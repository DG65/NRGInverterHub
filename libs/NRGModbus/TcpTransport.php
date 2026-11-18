<?php
// NRGModbus 0.2.0 quelle:eb736be015ea | Kopie, nicht ändern (Quelle: NRGModbus)
namespace IHUB\NRGModbus;

/**
 * Modbus TCP direkt über einen Socket.
 *
 * Verbindungsarten (Abstimmung 18.11.2026, Punkt 4), jedes Modul behält seinen heutigen Standard:
 *   MODE_SINGLE     eine Verbindung je Aufruf (InverterHub ohne Batch, ChargerHub ohne Dauerverbindung)
 *   MODE_CYCLE      eine Verbindung, beim ersten Aufruf geöffnet, bis close() gehalten (MeterHub); einmal neu
 *                   verbinden, wenn die Gegenstelle eine benutzte Verbindung geschlossen hat
 *   MODE_PERSISTENT ein Socket je Host:Port, über alle Objekte und Instanzen geteilt, Zugriffe über Semaphore
 *                   serialisiert (ChargerHub, DaheimLader Touch PRO)
 * Zusätzlich in jeder Art: beginBatch()/endBatch() öffnet eine Verbindung für viele Aufrufe (InverterHub, WiNet-S).
 * Der Batch übersteuert die Art, bei Fehler bleibt er stehen (ein Zeitlimit löscht ihn nicht).
 *
 * Optionen: timeout (Sekunden, Standard 3, nicht verkürzen), openAttempts (Verbindungsversuche beim Schreiben,
 * Standard 1 = keine Wiederholung), openPauseUs (Pause zwischen den Versuchen, Standard 1,5 s),
 * semaphoreEnter/semaphoreLeave (Callables für MODE_PERSISTENT, ohne sie keine Sperre),
 * verifyWriteTid (Standard true; false = die erste Antwort auf einen Schreibzugriff gilt ohne Prüfung der Transaktions-ID,
 * wie bisher im InverterHub; Lesen prüft immer).
 * Die Bibliothek wiederholt NUR das Öffnen. Schreibwiederholung, Sperren und Diagnose bleiben im Modul.
 */
final class TcpTransport implements ModbusTransport
{
    public const MODE_SINGLE = 'single';
    public const MODE_CYCLE = 'cycle';
    public const MODE_PERSISTENT = 'persistent';

    public $host;
    public $port;
    public $unitId;

    private $mode;
    private $timeout;
    private $openAttempts;
    private $openPauseUs;
    private $semEnter;
    private $semLeave;
    private $verifyWriteTid;
    private $verifyTid = true;

    private $batchSock = null;
    private $cycleSock = null;
    private $tid = 0;
    private $state;

    /** @var array<string,resource> geteilte Dauerverbindungen je Host:Port */
    private static $persistentSockets = [];

    public function __construct(string $host, int $port, int $unitId, array $options = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->unitId = $unitId;
        $this->mode = $options['mode'] ?? self::MODE_SINGLE;
        $this->timeout = (float)($options['timeout'] ?? 3.0);
        $this->openAttempts = max(1, (int)($options['openAttempts'] ?? 1));
        $this->openPauseUs = (int)($options['openPauseUs'] ?? 1500000);
        $this->semEnter = $options['semaphoreEnter'] ?? null;
        $this->semLeave = $options['semaphoreLeave'] ?? null;
        $this->verifyWriteTid = (bool)($options['verifyWriteTid'] ?? true);
        $this->state = new TransportState();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function state(): TransportState
    {
        return $this->state;
    }

    public function beginBatch(): void
    {
        $this->endBatch();
        $s = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($s !== false) {
            stream_set_timeout($s, (int)ceil($this->timeout));
            $this->batchSock = $s;
            $this->state->connects++;
        }
    }

    public function endBatch(): void
    {
        if ($this->batchSock !== null) {
            @fclose($this->batchSock);
            $this->batchSock = null;
        }
    }

    public function close(): void
    {
        $this->endBatch();
        if ($this->cycleSock !== null) {
            @fclose($this->cycleSock);
            $this->cycleSock = null;
        }
    }

    public function read(int $fc, int $address, int $count): ?array
    {
        $this->resetOperation();
        $this->verifyTid = true;
        $pdu = $this->transact(pack('Cnn', $fc, $address, $count), false);
        if ($pdu === null || strlen($pdu) < 2) {
            return null;
        }
        $rfc = ord($pdu[0]);
        if ($rfc & 0x80) {
            $this->state->exceptionCode = ord($pdu[1]);
            return null;
        }
        if ($rfc !== $fc) {
            return null;
        }
        $data = substr($pdu, 2, ord($pdu[1]));
        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    public function write(int $fc, int $address, array $words): bool
    {
        $this->resetOperation();
        if ($fc === 6) {
            $pdu = pack('Cnn', 0x06, $address, ($words[0] ?? 0) & 0xFFFF);
        } else {
            $fc = 16;
            $data = '';
            foreach ($words as $w) {
                $data .= pack('n', $w & 0xFFFF);
            }
            $pdu = pack('CnnC', 0x10, $address, count($words), count($words) * 2) . $data;
        }
        $this->verifyTid = $this->verifyWriteTid;
        $resp = $this->transact($pdu, true);
        if ($resp === null || $resp === '') {
            return false;
        }
        $rfc = ord($resp[0]);
        if ($rfc === $fc) {
            return true;
        }
        if ($rfc === ($fc | 0x80)) {
            $this->state->exceptionCode = strlen($resp) >= 2 ? ord($resp[1]) : 0;
        } else {
            $this->state->error = 'frame';
        }
        return false;
    }

    private function resetOperation(): void
    {
        $s = $this->state;
        $s->error = '';
        $s->errno = 0;
        $s->errstr = '';
        $s->exceptionCode = 0;
        $s->requestHex = '';
        $s->responseHex = '';
    }

    // -----------------------------------------------------------------------

    private function persistentKey(): string
    {
        return $this->host . ':' . $this->port;
    }

    /** Eine Anfrage senden und die zugehörige Antwort-PDU (ab Funktionscode) liefern, sonst null. */
    private function transact(string $pdu, bool $isWrite): ?string
    {
        if ($this->batchSock !== null) {
            $resp = $this->exchange($this->batchSock, $pdu);   // der Batch bleibt auch nach einem Fehler stehen
            return $resp;
        }
        if ($this->mode === self::MODE_PERSISTENT) {
            return $this->transactPersistent($pdu, $isWrite);
        }
        if ($this->mode === self::MODE_CYCLE) {
            return $this->transactCycle($pdu, $isWrite);
        }
        $sock = $this->openFresh($isWrite ? $this->openAttempts : 1);
        if ($sock === false) {
            return null;
        }
        $resp = $this->exchange($sock, $pdu);
        @fclose($sock);
        return $resp;
    }

    private function transactCycle(string $pdu, bool $isWrite): ?string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reused = $this->cycleSock !== null;
            if (!$reused) {
                $sock = $this->openFresh($isWrite ? $this->openAttempts : 1);
                if ($sock === false) {
                    return null;
                }
                $this->cycleSock = $sock;
            }
            $resp = $this->exchange($this->cycleSock, $pdu);
            if ($resp !== null) {
                return $resp;
            }
            @fclose($this->cycleSock);
            $this->cycleSock = null;
            // Neu verbinden NUR, wenn eine schon benutzte Verbindung weggebrochen ist: nicht bei Zeitlimit (ein
            // stummes Gerät würde doppelt so lange blockieren) und nicht bei einer Exception (gültige Antwort).
            if (!$reused || !in_array($this->state->error, ['write', 'eof'], true)) {
                return null;
            }
        }
        return null;
    }

    private function transactPersistent(string $pdu, bool $isWrite): ?string
    {
        $name = 'NRGModbus_' . $this->persistentKey();
        $locked = false;
        if ($this->semEnter !== null) {
            if (!($this->semEnter)($name, 5000)) {
                $this->state->error = 'busy';    // Gerät gerade anderweitig belegt, kein Grund, ewig zu warten
                return null;
            }
            $locked = true;
        }
        try {
            $sock = $this->persistentSocket($isWrite ? $this->openAttempts : 1);
            if ($sock === false) {
                return null;
            }
            $resp = $this->exchange($sock, $pdu);
            if ($resp === null) {
                $this->dropPersistent();
            }
            return $resp;
        } finally {
            if ($locked && $this->semLeave !== null) {
                ($this->semLeave)($name);
            }
        }
    }

    private function persistentSocket(int $attempts)
    {
        $key = $this->persistentKey();
        $s = self::$persistentSockets[$key] ?? null;
        if (is_resource($s)) {
            $meta = @stream_get_meta_data($s);
            if ($meta !== false && empty($meta['eof']) && empty($meta['timed_out'])) {
                return $s;
            }
            @fclose($s);
            unset(self::$persistentSockets[$key]);
        }
        $s = $this->openFresh($attempts);
        if ($s !== false) {
            self::$persistentSockets[$key] = $s;
        }
        return $s;
    }

    private function dropPersistent(): void
    {
        $key = $this->persistentKey();
        if (isset(self::$persistentSockets[$key])) {
            @fclose(self::$persistentSockets[$key]);
            unset(self::$persistentSockets[$key]);
        }
    }

    /** Neue Verbindung; $attempts Versuche mit Pause (nur beim Schreiben > 1). Bei Misserfolg Grund in state(). */
    private function openFresh(int $attempts)
    {
        $errno = 0;
        $errstr = '';
        for ($try = 0; $try < $attempts; $try++) {
            $s = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
            if ($s !== false) {
                stream_set_timeout($s, (int)ceil($this->timeout));
                $this->state->connects++;
                return $s;
            }
            if ($try < $attempts - 1) {
                usleep($this->openPauseUs);
            }
        }
        $this->state->error = 'connect';
        $this->state->errno = (int)$errno;
        $this->state->errstr = (string)$errstr;
        return false;
    }

    /**
     * Eine Anfrage senden, die Antwort mit passender Transaktions-ID einsammeln. Fremde Frames (verspätete Antworten
     * früherer Anfragen) werden verworfen. Ohne diese Prüfung wurde einem Lesezugriff ein Rest-Frame untergeschoben
     * (261,5-MW-Ausreißer, 02.09.2026): auf einer weiterbenutzten Verbindung darf nie etwas von der nächsten Antwort
     * verschluckt werden, deshalb wird jeder Frame exakt nach MBAP-Länge gelesen.
     */
    private function exchange($sock, string $pdu): ?string
    {
        $this->tid = ($this->tid % 65535) + 1;
        $tid = $this->tid;
        $frame = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId) . $pdu;
        $this->state->requestHex = bin2hex($frame);
        if (@fwrite($sock, $frame) !== strlen($frame)) {
            $this->state->error = 'write';
            return null;
        }
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $head = $this->readExact($sock, 7, $deadline);
            if ($head === null) {
                return null;
            }
            $h = unpack('ntid/npid/nlen', $head);
            if ($h['len'] < 2 || $h['len'] > 260) {
                $this->state->error = 'frame';
                return null;
            }
            $body = $this->readExact($sock, $h['len'] - 1, $deadline);
            if ($body === null) {
                return null;
            }
            if (!$this->verifyTid || $h['tid'] === $tid) {
                $this->state->responseHex = bin2hex($head . $body);
                return $body;
            }
            // verspätete Antwort einer früheren Anfrage: verwerfen, weiterlesen
        }
    }

    private function readExact($sock, int $n, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (microtime(true) >= $deadline) {
                $this->state->error = 'timeout';
                return null;
            }
            $chunk = @fread($sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($sock);
                $this->state->error = !empty($meta['timed_out']) ? 'timeout' : 'eof';
                return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
