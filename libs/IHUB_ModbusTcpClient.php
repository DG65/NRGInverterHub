<?php

// ---------------------------------------------------------------------------
// IHUB_ModbusTcpClient — gemeinsame Modbus-TCP-Grundfunktionen für alle Treiber
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

    // Batch-Modus: eine offene Verbindung für viele Reads. Manche Geräte
    // (z. B. Sungrow WiNet-S) erlauben nur EINE Modbus-Verbindung und lehnen
    // schnelle Reconnects ab - dann fielen spätere Reads eines Zyklus aus.
    private $batchSock = null;

    // Rest-Bytes eines bereits eingesammelten, aber noch nicht abgeholten
    // MBAP-Frames auf der Batch-Verbindung (siehe readMbapFrame()) - muss dem
    // naechsten Read auf derselben Verbindung vorangestellt werden, sonst
    // gingen echte, nur etwas verspaetet eingetroffene Antworten verloren.
    private $batchLeftover = '';

    // Öffnet eine wiederverwendbare Verbindung. Schlägt sie fehl, bleibt der
    // Per-Read-Modus aktiv (kein Fehler).
    public function beginBatch()
    {
        $this->endBatch();
        $s = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($s !== false) {
            stream_set_timeout($s, 3);
            $this->batchSock = $s;
        }
    }

    public function endBatch()
    {
        $this->batchLeftover = '';
        if ($this->batchSock !== null) {
            @fclose($this->batchSock);
            $this->batchSock = null;
        }
    }

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
    }

    public function setFloatWordSwap(bool $swap)
    {
        $this->floatWordSwap = $swap;
    }

    public function readHolding($startReg, $count)
    {
        return $this->readRegisters(0x03, $startReg, $count);
    }

    // Liest eine vollstaendige MBAP-Antwort mit passender Transaktions-ID vom
    // Socket. Wird im Batch-Modus dieselbe Verbindung fuer viele Reads
    // wiederverwendet, kann ein einzelner Read ueber dem 3s-Zeitlimit haengen
    // bleiben, waehrend seine Antwort kurz danach doch noch eintrifft - ohne
    // TID-Pruefung wurde dieser "verspaetete" Rest-Frame dann still als
    // Antwort des NAECHSTEN Reads im selben Zyklus fehlinterpretiert (real
    // beobachtet: eine PV-Leistung von 261,5 MW nachts, siehe CLAUDE.md/
    // Dashboard-Meldung 02.09.2026 - zwei fremde Registerhaelften wurden als
    // High-/Low-Wort eines 32-Bit-Werts zusammengesetzt). Fix: jeden Frame per
    // MBAP-Laengenfeld vollstaendig einsammeln, bei TID-Mismatch verwerfen und
    // auf den naechsten warten statt ihn zu verwerten.
    private function readMbapFrame($sock, $expectedTid, &$buffer)
    {
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            while (strlen($buffer) >= 6) {
                $tid    = (ord($buffer[0]) << 8) | ord($buffer[1]);
                $length = (ord($buffer[4]) << 8) | ord($buffer[5]);
                $frameLen = 6 + $length;
                if (strlen($buffer) < $frameLen) {
                    break; // Frame noch nicht vollstaendig - weiter lesen
                }
                $frame  = substr($buffer, 0, $frameLen);
                $buffer = substr($buffer, $frameLen);
                if ($tid === $expectedTid) {
                    return substr($frame, 7); // PDU ab Function Code
                }
                // Fremder/veralteter Frame (falsche TID) - verwerfen, weiter warten.
            }
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }
        return null;
    }

    private function readRegisters($fc, $startReg, $count)
    {
        $sock = $this->batchSock ?: @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return null;
        }
        if ($this->batchSock === null) {
            stream_set_timeout($sock, 3);
        }

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', $fc, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);

        $buffer = ($this->batchSock !== null) ? $this->batchLeftover : '';
        $this->batchLeftover = '';
        $pdu = $this->readMbapFrame($sock, $tid, $buffer);

        if ($this->batchSock === null) {
            fclose($sock);
        } elseif ($buffer !== '') {
            // Ueberschuss (Rest eines noch nicht abgeholten spaeteren Frames)
            // bleibt sonst unsichtbar zwischen den Reads liegen - fuer den
            // naechsten Read auf dieser Verbindung merken.
            $this->batchLeftover = $buffer;
        }

        if ($pdu === null || strlen($pdu) < 2) {
            return null;
        }

        $respFc = ord($pdu[0]);
        if ($respFc & 0x80 || $respFc !== $fc) {
            return null;
        }

        $byteCount = ord($pdu[1]);
        $data      = substr($pdu, 2, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    // Read Input Registers (FC 0x04) — Sungrow, Solis, Growatt, Solax trennen
    // Mess-/Input-Register (0x04) von Holding-/Steuerregistern (0x03).
    public function readInput($startReg, $count)
    {
        return $this->readRegisters(0x04, $startReg, $count);
    }

    // Oeffnet die Verbindung fuer einen Schreibbefehl. Scheitert das Oeffnen (z. B. weil ein Datenlogger nur
    // eine Verbindung zulaesst und gerade eine andere offen ist), wird nach einer Pause von 1,5 s einmal
    // wiederholt. Bleibt es dabei, steht Fehlernummer und Text des Systems in lastError (abgelehnt oder
    // Zeitlimit, 15.11.2026, Pauls Solis).
    private function openForWrite()
    {
        $errno = 0;
        $errstr = '';
        for ($try = 0; $try < 2; $try++) {
            $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
            if ($sock !== false) {
                return $sock;
            }
            if ($try === 0) {
                usleep(1500000);
            }
        }
        $detail = trim($errno . ' ' . $errstr);
        $this->lastError = ($detail !== '' && $detail !== '0')
            ? IHUB_Text::t('no connection (%s)', $detail)
            : IHUB_Text::t('no connection');
        return false;
    }

    public function writeSingle($reg, $value)
    {
        $sock = $this->openForWrite();
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x06, $reg, $value & 0xFFFF);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        if ($resp === false || strlen($resp) < 8) {
            $this->lastError = IHUB_Text::t('no answer');
            return false;
        }
        if (ord($resp[7]) === 0x06) {
            $this->lastError = '';
            return true;
        }
        $this->lastError = (ord($resp[7]) & 0x80) ? IHUB_Text::t('exception code %s', strlen($resp) >= 9 ? ord($resp[8]) : '?') : IHUB_Text::t('unexpected answer');
        return false;
    }

    public function writeMultiple($startReg, $values)
    {
        $sock = $this->openForWrite();
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, 3);

        $count     = count($values);
        $byteCount = $count * 2;
        $dataPart  = '';
        foreach ($values as $v) {
            $dataPart .= pack('n', $v & 0xFFFF);
        }
        $tid  = mt_rand(1, 65535);
        $pdu  = pack('CnnC', 0x10, $startReg, $count, $byteCount) . $dataPart;
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        if ($resp === false || strlen($resp) < 8) {
            $this->lastError = IHUB_Text::t('no answer');
            return false;
        }
        if (ord($resp[7]) === 0x10) {
            $this->lastError = '';
            return true;
        }
        $this->lastError = (ord($resp[7]) & 0x80) ? IHUB_Text::t('exception code %s', strlen($resp) >= 9 ? ord($resp[8]) : '?') : IHUB_Text::t('unexpected answer');
        return false;
    }

    public function u16($regs, $offset)
    {
        return isset($regs[$offset]) ? ($regs[$offset] & 0xFFFF) : 0;
    }

    public function s16($regs, $offset)
    {
        $v = $this->u16($regs, $offset);
        return $v > 32767 ? $v - 65536 : $v;
    }

    public function u32($regs, $offset)
    {
        return (($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1));
    }

    public function s32($regs, $offset)
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    public function readStr($regs, $offset, int $regCount)
    {
        $s = '';
        for ($i = 0; $i < $regCount; $i++) {
            $r  = $this->u16($regs, $offset + $i);
            $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
        }
        return rtrim($s, "\x00 ");
    }

    // IEEE-754 Float32 über 2 Register. Standard Big-Endian (SunSpec, ABCD);
    // bei $floatWordSwap = true wird die Wortreihenfolge getauscht (CDAB), wie
    // sie z. B. Kostal im „Standard Modbus (little-endian)"-Modus liefert.
    public function readFloat32($regs, $offset)
    {
        $hi  = $this->u16($regs, $offset);
        $lo  = $this->u16($regs, $offset + 1);
        if ($this->floatWordSwap) {
            $tmp = $hi; $hi = $lo; $lo = $tmp;
        }
        $raw = pack('nn', $hi, $lo);
        $val = unpack('G', $raw);
        return (float)($val[1] ?? 0.0);
    }
}
