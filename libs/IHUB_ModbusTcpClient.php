<?php

// ---------------------------------------------------------------------------
// IHUB_ModbusTcpClient — Anpassungsklasse über der gemeinsamen Bibliothek NRGModbus (libs/NRGModbus/, Namensraum
// IHUB\NRGModbus), seit 0.90.0. Die Treiber sehen weiter dieselben Methoden, Felder und dasselbe Verhalten wie vorher:
//   - Lesen liefert bei jedem Fehler null, Batch über beginBatch()/endBatch().
//   - $unitId, $host, $port, $floatWordSwap sind öffentliche Felder, die Treiber auch zur Laufzeit umsetzen
//     (SMA, Victron); sie werden vor jedem Zugriff an die Bibliothek durchgereicht.
//   - Geschrieben wird auf einer EIGENEN Verbindung (zweiter Bibliotheksclient), ein laufender Batch bleibt unberührt:
//     2 Verbindungsversuche mit 1,5 s Pause, Antwort ohne Prüfung der Transaktions-ID (verifyWriteTid = aus).
//   - $lastError ist der Klartext des letzten SCHREIBzugriffs ('' = bestätigt), nicht der Fehlercode der Bibliothek.
// Die Schreibwiederholung, die Sperre je Instanz und die Schreibdiagnose liegen weiter im Hauptmodul.
// ---------------------------------------------------------------------------

class IHUB_ModbusTcpClient
{
    public $host;
    public $port;
    public $unitId;

    // Float32-Wortreihenfolge: false = ABCD (big-endian, SunSpec-Konvention),
    // true = CDAB (Wort-Swap, „Standard Modbus little-endian"). Kostal-Geräte
    // sind ab Werk auf CDAB gestellt und liefern sonst Datenmüll.
    public $floatWordSwap = false;

    // Letzte Antwort eines Schreibzugriffs (writeSingle): '' = bestaetigt, sonst "keine Verbindung",
    // "keine Antwort", "Ausnahmecode N". Treiber nehmen das in ihre Warnungen auf.
    public $lastError = '';

    /** @var \IHUB\NRGModbus\ModbusClient Lesen, Batch */
    private $reader;
    /** @var \IHUB\NRGModbus\ModbusClient Schreiben, immer eigene Verbindung */
    private $writer;

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
        $this->reader = \IHUB\NRGModbus\ModbusClient::tcp((string)$host, (int)$port, (int)$unitId, ['mode' => 'single']);
        $this->writer = \IHUB\NRGModbus\ModbusClient::tcp((string)$host, (int)$port, (int)$unitId, [
            'mode'           => 'single',
            'openAttempts'   => 2,
            'openPauseUs'    => 1500000,
            'verifyWriteTid' => false,
        ]);
    }

    /** Aktuelle Werte der öffentlichen Felder an den Bibliotheksclient geben (Treiber setzen $unitId zur Laufzeit um). */
    private function sync(\IHUB\NRGModbus\ModbusClient $c): \IHUB\NRGModbus\ModbusClient
    {
        $t = $c->transport();
        $t->host   = (string)$this->host;
        $t->port   = (int)$this->port;
        $t->unitId = (int)$this->unitId;
        return $c;
    }

    // Öffnet eine wiederverwendbare Verbindung. Schlägt sie fehl, bleibt der
    // Per-Read-Modus aktiv (kein Fehler).
    public function beginBatch()
    {
        $this->sync($this->reader)->beginBatch();
    }

    public function endBatch()
    {
        $this->reader->endBatch();
    }

    public function setFloatWordSwap(bool $swap)
    {
        $this->floatWordSwap = $swap;
    }

    public function readHolding($startReg, $count)
    {
        return $this->sync($this->reader)->readHolding($startReg, $count);
    }

    // Read Input Registers (FC 0x04) — Sungrow, Solis, Growatt, Solax trennen
    // Mess-/Input-Register (0x04) von Holding-/Steuerregistern (0x03).
    public function readInput($startReg, $count)
    {
        return $this->sync($this->reader)->readInput($startReg, $count);
    }

    public function writeSingle($reg, $value)
    {
        return $this->finishWrite($this->sync($this->writer)->writeSingle($reg, $value));
    }

    public function writeMultiple($startReg, $values)
    {
        return $this->finishWrite($this->sync($this->writer)->writeMultiple($startReg, $values));
    }

    // Setzt den Klartext des Schreibergebnisses wie vor der Umstellung: "no connection (<errno> <text>)", "no answer",
    // "exception code N", "unexpected answer" (übersetzt über IHUB_Text).
    private function finishWrite(bool $ok): bool
    {
        if ($ok) {
            $this->lastError = '';
            return true;
        }
        $w = $this->writer;
        if ($w->lastError === 'connect') {
            $detail = trim($w->lastErrno . ' ' . $w->lastErrstr);
            $this->lastError = ($detail !== '' && $detail !== '0')
                ? IHUB_Text::t('no connection (%s)', $detail)
                : IHUB_Text::t('no connection');
        } elseif ($w->lastException > 0) {
            $this->lastError = IHUB_Text::t('exception code %s', $w->lastException);
        } elseif (in_array($w->lastError, ['timeout', 'eof', 'write'], true)) {
            $this->lastError = IHUB_Text::t('no answer');
        } else {
            $this->lastError = IHUB_Text::t('unexpected answer');
        }
        return false;
    }

    public function u16($regs, $offset)
    {
        return \IHUB\NRGModbus\ModbusDecode::u16($regs, $offset);
    }

    public function s16($regs, $offset)
    {
        return \IHUB\NRGModbus\ModbusDecode::s16($regs, $offset);
    }

    public function u32($regs, $offset)
    {
        return \IHUB\NRGModbus\ModbusDecode::u32($regs, $offset);
    }

    public function s32($regs, $offset)
    {
        return \IHUB\NRGModbus\ModbusDecode::s32($regs, $offset);
    }

    public function readStr($regs, $offset, int $regCount)
    {
        return \IHUB\NRGModbus\ModbusDecode::str($regs, $offset, $regCount);
    }

    // IEEE-754 Float32 über 2 Register. Standard Big-Endian (SunSpec, ABCD);
    // bei $floatWordSwap = true wird die Wortreihenfolge getauscht (CDAB), wie
    // sie z. B. Kostal im „Standard Modbus (little-endian)"-Modus liefert.
    public function readFloat32($regs, $offset)
    {
        return \IHUB\NRGModbus\ModbusDecode::float32($regs, $offset, $this->floatWordSwap);
    }
}
