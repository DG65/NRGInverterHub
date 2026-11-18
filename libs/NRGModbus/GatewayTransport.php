<?php
// NRGModbus 0.2.0 quelle:eb736be015ea | Kopie, nicht ändern (Quelle: NRGModbus)
namespace IHUB\NRGModbus;

/**
 * Modbus über die Symcon-Modbus-Gateway-Instanz (eingebauter RS485-Port der Symbox).
 *
 * Die Bibliothek hängt an keiner Modulklasse. Das Modul übergibt eine Weiterleitungsfunktion
 * `function (string $json): string|false` (z. B. ein Closure um $this->SendDataToParent(), das Methode ist protected,
 * oder der Aufruf der Brücke). Anfrage-JSON: {DataID, Function, Address, Quantity, Data}; Antwort roh, die ersten
 * zwei Byte (Funktionscode, Byte-Zahl) werden übersprungen, der Rest sind 16-Bit-Register big endian
 * (am Quellcode von SymconBC/EM24-DIN verifiziert). Bei Schreibzugriffen steht "Data" in Base64, rohe Registerbytes
 * sind meist kein gültiges UTF-8 und json_encode() schlüge sonst stumm fehl. Der Schreibweg (FC 6/16) ist eine
 * UNGETESTETE Ableitung aus dem Lesepfad; einmal je Prozess entsteht ein Hinweis (takeNotices()).
 *
 * Option requireFullReply (Standard false): bei weniger Registern als angefragt null liefern (ChargerHub) statt
 * der kürzeren Antwort (TCP-Verhalten, MeterHub, InverterHub).
 * Option trimReply (Standard true): eine längere Antwort auf die angefragte Zahl kürzen; false = alles liefern (bisher InverterHub).
 */
final class GatewayTransport implements ModbusTransport
{
    public const DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    public const GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';

    public $unitId;
    private $send;
    private $requireFullReply;
    private $trimReply;
    private $translate;
    private $notices = [];
    private $state;
    private static $writeWarned = false;

    public function __construct(int $unitId, callable $send, array $options = [])
    {
        $this->unitId = $unitId;
        $this->send = $send;
        $this->requireFullReply = (bool)($options['requireFullReply'] ?? false);
        $this->trimReply = (bool)($options['trimReply'] ?? true);
        $this->translate = $options['translate'] ?? null;
        $this->state = new TransportState();
    }

    public function state(): TransportState
    {
        return $this->state;
    }

    /** Hinweise für die Modulklasse (Bibliotheksklassen haben kein LogMessage): abholen leert die Liste. */
    public function takeNotices(): array
    {
        $n = $this->notices;
        $this->notices = [];
        return $n;
    }

    public function beginBatch(): void
    {
    }

    public function endBatch(): void
    {
    }

    public function close(): void
    {
    }

    private function request(int $function, int $address, int $quantity, string $data = ''): ?string
    {
        $s = $this->state;
        $s->error = '';
        $s->exceptionCode = 0;
        $json = json_encode(['DataID' => self::DATA_ID, 'Function' => $function, 'Address' => $address, 'Quantity' => $quantity, 'Data' => $data]);
        if ($json === false) {
            $s->error = 'frame';
            return null;
        }
        $s->requestHex = bin2hex($json);
        try {
            $resp = ($this->send)($json);
        } catch (\Throwable $e) {
            $s->error = 'no_parent';     // kein Parent verknüpft oder nicht bereit: kein Fatal, null wie überall
            return null;
        }
        if ($resp === false || $resp === null || $resp === '') {
            $s->error = 'no_response';
            return null;
        }
        $s->responseHex = bin2hex((string)$resp);
        return (string)$resp;
    }

    public function read(int $fc, int $address, int $count): ?array
    {
        $resp = $this->request($fc, $address, $count);
        if ($resp === null || strlen($resp) < 2) {
            if ($resp !== null) {
                $this->state->error = 'frame';
            }
            return null;
        }
        $words = @unpack('n*', substr($resp, 2));
        if ($words === false) {
            $this->state->error = 'frame';
            return null;
        }
        $regs = array_values($words);
        if ($this->requireFullReply && count($regs) < $count) {
            $this->state->error = 'frame';
            return null;
        }
        return $this->trimReply ? array_slice($regs, 0, $count) : $regs;
    }

    public function write(int $fc, int $address, array $words): bool
    {
        if (!self::$writeWarned) {
            self::$writeWarned = true;
            $this->notices[] = $this->t('Symbox gateway: write access (function 16) is an untested derivation from the verified read scheme. Check on real hardware before relying on it.');
        }
        $payload = '';
        foreach ($words as $w) {
            $payload .= pack('n', $w & 0xFFFF);
        }
        $fc = $fc === 6 ? 6 : 16;
        return $this->request($fc, $address, count($words), base64_encode($payload)) !== null;
    }

    private function t(string $text): string
    {
        return $this->translate !== null ? (string)($this->translate)($text) : $text;
    }

    /** Nur für Prüfstände: den Einmal-Hinweis zurücksetzen. */
    public static function resetNoticeState(): void
    {
        self::$writeWarned = false;
    }
}
