<?php
// NRGModbus 0.2.0 quelle:eb736be015ea | Kopie, nicht ändern (Quelle: NRGModbus)
namespace IHUB\NRGModbus;

/**
 * Ein Client für alle Übertragungswege. Die Module binden ihn über eine dünne Anpassungsklasse ein, die ihre alten
 * Methoden- und Feldnamen behält (z. B. writeSingle/writeHolding, readFloat32/floatLE, lastError als Code oder Text).
 *
 * Festlegungen (Abstimmung 18.11.2026, Abschnitt 9):
 *  - Lesen: bei jedem Fehler null. Nie Exception, nie false, nie [].
 *  - Schreiben: bool, Grund in lastWriteError (Klartext) und lastError (Code); nie Exception.
 *  - Dekodieren: fehlendes Register 0; Ungültig-Marke nur je Aufruf (nullOnInvalid), Standard aus.
 *  - Wiederholt wird nur das Öffnen der Verbindung (Option openAttempts am TcpTransport), Standard aus.
 *  - Übersetzungshaken `translate` (Callable `function (string $text, ...$args): string`) statt Modul-Textklasse;
 *    ohne ihn: englischer Text mit sprintf().
 *
 * Felder (Abschnitt 9, Punkt 5): lastError, lastErrno, lastErrstr, failureText(), connects, lastWriteError,
 * writeTrace, connectError, writeRetryable, takeNotices(), close().
 */
final class ModbusClient
{
    /** Grund des letzten Fehlschlags als Code (siehe TransportState::$error); '' bei Erfolg und bei einer Modbus-Exception. */
    public $lastError = '';
    public $lastErrno = 0;
    public $lastErrstr = '';
    /** Exception-Code der letzten Antwort, sonst 0. */
    public $lastException = 0;
    public $connects = 0;

    /** Klartext-Grund des letzten fehlgeschlagenen Schreibzugriffs ('' = Erfolg). */
    public $lastWriteError = '';
    /** Roh-Protokoll der Schreibzugriffe (nur mit Option traceWrites, sonst leer). */
    public $writeTrace = [];
    /** Grund des letzten fehlgeschlagenen Verbindungsaufbaus als "text (nummer)". */
    public $connectError = '';
    /** Der letzte Schreibfehler war ein Verbindungs-/Zeitlimitfehler: ein Wiederholversuch ist sinnvoll (eine Exception ist es nicht). */
    public $writeRetryable = false;

    /** Wortreihenfolge für Fließkommawerte: true = CDAB. Je Aufruf übersteuerbar. */
    public $wordSwap = false;

    private $transport;
    private $translate;
    private $traceWrites;

    public function __construct(ModbusTransport $transport, array $options = [])
    {
        $this->transport = $transport;
        $this->translate = $options['translate'] ?? null;
        $this->traceWrites = (bool)($options['traceWrites'] ?? false);
        $this->wordSwap = (bool)($options['wordSwap'] ?? false);
    }

    /** Direkt per Socket. Optionen siehe TcpTransport, dazu translate, traceWrites, wordSwap. */
    public static function tcp(string $host, int $port, int $unitId, array $options = []): self
    {
        return new self(new TcpTransport($host, $port, $unitId, $options), $options);
    }

    /** Über die Symcon-Modbus-Gateway-Instanz, $send = Weiterleitungsfunktion. */
    public static function gateway(int $unitId, callable $send, array $options = []): self
    {
        return new self(new GatewayTransport($unitId, $send, $options), $options);
    }

    /** Unit-ID zur Laufzeit wechseln (Victron: Regler- und Netz-Unit-IDs auf demselben Client). */
    public function setUnitId(int $unitId)
    {
        $this->transport->unitId = $unitId;
    }

    public function unitId(): int
    {
        return (int)$this->transport->unitId;
    }

    public function transport(): ModbusTransport
    {
        return $this->transport;
    }

    // ---- Lesen ----

    public function readHolding($startReg, $count)
    {
        return $this->doRead(3, (int)$startReg, (int)$count);
    }

    public function readInput($startReg, $count)
    {
        return $this->doRead(4, (int)$startReg, (int)$count);
    }

    private function doRead(int $fc, int $address, int $count): ?array
    {
        $r = $this->transport->read($fc, $address, $count);
        $this->syncState();
        return $r;
    }

    // ---- Schreiben ----

    public function writeSingle($reg, $value): bool
    {
        $word = (int)$value & 0xFFFF;
        return $this->doWrite(6, (int)$reg, [$word], sprintf('FC06 reg %d value %d', $reg, $word));
    }

    public function writeMultiple($startReg, $values): bool
    {
        $words = array_map(function ($v) { return (int)$v & 0xFFFF; }, array_values($values));
        return $this->doWrite(16, (int)$startReg, $words, sprintf('FC16 reg %d values [%s]', $startReg, implode(',', $words)));
    }

    public function writeFloat32($startReg, float $value, ?bool $swap = null): bool
    {
        return $this->writeMultiple($startReg, ModbusDecode::packFloat32($value, $swap ?? $this->wordSwap));
    }

    public function writeDouble64($startReg, float $value, ?bool $swap = null): bool
    {
        return $this->writeMultiple($startReg, ModbusDecode::packFloat64($value, $swap ?? $this->wordSwap));
    }

    private function doWrite(int $fc, int $address, array $words, string $label): bool
    {
        $this->lastWriteError = '';
        $this->writeRetryable = false;
        $this->connectError = '';
        $ok = $this->transport->write($fc, $address, $words);
        $this->syncState();
        if (!$ok) {
            $this->explainWriteFailure();
        }
        if ($this->traceWrites) {
            $st = $this->transport->state();
            $this->writeTrace[] = $label . ' | unit ' . ($this->transport->unitId ?? '?') . ' | request ' . $st->requestHex . ' | response ' . $st->responseHex;
        }
        return $ok;
    }

    private function explainWriteFailure(): void
    {
        $err = $this->lastError;
        if ($err === 'connect') {
            $this->lastWriteError = $this->connectError !== '' ? $this->t('Connection failed: %s', $this->connectError) : $this->t('Connection failed');
            $this->writeRetryable = true;
        } elseif ($err === 'timeout' || $err === 'eof' || $err === 'write' || $err === 'no_response' || $err === 'busy') {
            $this->lastWriteError = $this->t('No response from the device (timeout)');
            $this->writeRetryable = true;
        } elseif ($this->lastException > 0) {
            $labels = [
                1 => $this->t('Illegal Function (function code not supported)'),
                2 => $this->t('Illegal Data Address (register does not exist)'),
                3 => $this->t('Illegal Data Value (value outside the permitted range)'),
                4 => $this->t('Slave Device Failure'),
                6 => $this->t('Slave Device Busy'),
            ];
            $this->lastWriteError = $this->t('Modbus exception code %d (%s)', $this->lastException, $labels[$this->lastException] ?? $this->t('unknown'));
        } elseif ($err === 'no_parent') {
            $this->lastWriteError = $this->t('No gateway connection');
        } else {
            $this->lastWriteError = $this->t('Unexpected response from the device');
        }
    }

    // ---- Verbindung ----

    public function beginBatch()
    {
        $this->transport->beginBatch();
        $this->syncState();
    }

    public function endBatch()
    {
        $this->transport->endBatch();
    }

    public function close()
    {
        $this->transport->close();
    }

    /** Hinweise des Gateway-Wegs (sonst leer). */
    public function takeNotices(): array
    {
        return $this->transport instanceof GatewayTransport ? $this->transport->takeNotices() : [];
    }

    /** Grund des letzten Fehlschlags als Text, bei Verbindungsfehlern mit Fehlernummer ('' = kein Fehler). */
    public function failureText(): string
    {
        if ($this->lastError === '') {
            return '';
        }
        $t = $this->lastError;
        if ($this->lastError === 'connect' && ($this->lastErrno !== 0 || $this->lastErrstr !== '')) {
            $t .= ' (' . $this->lastErrno . ': ' . $this->lastErrstr . ')';
        }
        return $t;
    }

    private function syncState(): void
    {
        $s = $this->transport->state();
        $this->lastError = $s->error;
        $this->lastErrno = $s->errno;
        $this->lastErrstr = $s->errstr;
        $this->lastException = $s->exceptionCode;
        $this->connects = $s->connects;
        if ($s->error === 'connect') {
            $this->connectError = sprintf('%s (%d)', $s->errstr, $s->errno);
        }
    }

    private function t(string $text, ...$args): string
    {
        if ($this->translate !== null) {
            return (string)($this->translate)($text, ...$args);
        }
        return $args ? sprintf($text, ...$args) : $text;
    }

    // ---- Dekodieren (dünne Hüllen um ModbusDecode, damit Treiber $client->u16() weiter schreiben können) ----

    public function u16($regs, $offset, bool $nullOnInvalid = false) { return ModbusDecode::u16($regs, $offset, $nullOnInvalid); }
    public function s16($regs, $offset, bool $nullOnInvalid = false) { return ModbusDecode::s16($regs, $offset, $nullOnInvalid); }
    public function u32($regs, $offset, bool $swap = false, bool $nullOnInvalid = false) { return ModbusDecode::u32($regs, $offset, $swap, $nullOnInvalid); }
    public function s32($regs, $offset, bool $swap = false, bool $nullOnInvalid = false) { return ModbusDecode::s32($regs, $offset, $swap, $nullOnInvalid); }
    public function float32($regs, $offset, ?bool $swap = null, bool $nullOnInvalid = false) { return ModbusDecode::float32($regs, $offset, $swap ?? $this->wordSwap, $nullOnInvalid); }
    public function float64($regs, $offset, ?bool $swap = null, bool $nullOnInvalid = false) { return ModbusDecode::float64($regs, $offset, $swap ?? $this->wordSwap, $nullOnInvalid); }
    public function str($regs, $offset, int $regCount): string { return ModbusDecode::str($regs, $offset, $regCount); }
    public function setWordSwap(bool $swap) { $this->wordSwap = $swap; }
}
