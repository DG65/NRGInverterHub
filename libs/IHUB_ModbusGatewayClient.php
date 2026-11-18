<?php

// ---------------------------------------------------------------------------
// IHUB_ModbusGatewayClient — Anbindung an Symcons eingebauten Symbox-RS485-
// Port über das native Gateway-Instanzmodell (SendDataToParent()/ForwardData()
// statt eigenem fsockopen-Client), zusätzlich zum direkten TCP-Weg oben, kein
// Ersatz. SUITE.md 9j, Verbund-Abstimmung mit MeterHub/ChargerHub (18.09.2026):
// gleiche vier Methoden wie IHUB_ModbusTcpClient (readHolding/readInput/
// writeSingle/writeMultiple), damit Treiber beim Umschalten unverändert
// bleiben. Das exakte ForwardData()-Payload-Schema (Function Code/Adresse/
// Quantity/Unit-ID-Kodierung im Puffer) ist nirgends öffentlich dokumentiert —
// weder SDK-Doku noch Modulreferenz noch Community-Threads zeigen ein
// vollständiges Beispiel, die nativen Module sind kompiliert. Deshalb bewusst
// nur als Stub: liefert kontrolliert null/false statt Fatal Error, bis das
// Schema an echter Symbox-Hardware oder über eine Forum-Antwort geklärt ist.
// -----------------------------------------------------------------------
class IHUB_ModbusGatewayClient
{
    // Von MeterHub direkt am Rohcode des offiziellen SymconBC-Referenzmoduls
    // verifiziert (github.com/symcon/SymconBC, EM24-DIN/module.php, 18.09.2026):
    // Lesepfad ist damit ein bestätigter Fund, kein Community-Reverse-Engineering.
    private const GATEWAY_DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';

    private $module;
    // Wird im Gateway-Modus NIE gesendet (die Nutzlast hat keine Unit-ID, die Adresse
    // steckt in der Geraete-ID der Gateway-Instanz). Public nur, damit Treiber, die
    // $mb->unitId umsetzen (SunSpec, Victron), nicht an einem privaten Feld scheitern.
    public $unitId;
    private static $warned = false;
    // Protokoll-Rueckruf der Modulinstanz (LogMessage() ist in IPSModule geschuetzt und
    // hier nicht erreichbar; die globale Protokollfunktion ist laut Store-Review nicht erwuenscht).
    private $logger;
    public $requests = 0;
    public $responses = 0;

    // Wie IHUB_ModbusTcpClient: Float32-Wortreihenfolge (Kostal = CDAB).
    public $floatWordSwap = false;

    // SendDataToParent() ist eine Methode der IPSModule-Instanz, keine globale
    // Funktion - die Klasse braucht daher (anders als IHUB_ModbusTcpClient, der
    // rein per fsockopen arbeitet) einen Verweis auf die aufrufende Modulinstanz.
    public function __construct($module, $port, $unitId, $logger = null)
    {
        $this->module = $module;
        $this->unitId = $unitId;
        $this->logger = $logger;
    }

    private function request($function, $address, $quantity, $data = '')
    {
        if (!method_exists($this->module, 'ForwardToGateway')) {
            return null;
        }
        $this->requests++;
        $response = @$this->module->ForwardToGateway(self::GATEWAY_DATA_ID, $function, $address, $quantity, $data);
        if ($response === false || $response === null || $response === '') {
            return null;
        }
        $this->responses++;
        return $response;
    }

    private function notImplemented($what)
    {
        if (!self::$warned) {
            self::$warned = true;
            if (is_callable($this->logger)) {
                call_user_func($this->logger, IHUB_Text::t('Symbox gateway write path (%s) is an untested derivation from the verified read path - not yet confirmed on real hardware.', $what));
            }
        }
    }

    public function beginBatch()
    {
    }

    public function endBatch()
    {
    }

    public function setFloatWordSwap(bool $swap)
    {
        $this->floatWordSwap = $swap;
    }

    // Dekodier-Hilfen, die JEDER Treiber am Client aufruft (u16/s16/u32/s32/
    // readStr/readFloat32). Muessen zeichengleich zu IHUB_ModbusTcpClient
    // bleiben - .tools/test-gateway-client.php prueft, dass jede von Treibern
    // genutzte Client-Methode hier existiert. Fehlten sie, brach der erste
    // Lesezugriff im Gateway-Modus mit "Call to undefined method" ab (Forum-
    // Beta-Tester Mstaudi, 19.09.2026: "Aktualisieren tut es auch noch nicht").
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

    public function readHolding($startReg, $count)
    {
        return $this->readRegisters(3, $startReg, $count);
    }

    public function readInput($startReg, $count)
    {
        return $this->readRegisters(4, $startReg, $count);
    }

    // Verifiziertes Antwortformat (MeterHub, 18.09.2026): erste 2 Byte sind
    // Function-Code + Byte-Count der Modbus-PDU (vom Gateway bereits abgetrennt
    // vom MBAP/RTU-Rahmen zurückgegeben), danach 16-Bit-Register big-endian.
    private function readRegisters($function, $startReg, $count)
    {
        $response = $this->request($function, $startReg, $count);
        if ($response === null || strlen($response) < 2) {
            return null;
        }
        $unpacked = @unpack('n*', substr($response, 2));
        if ($unpacked === false) {
            return null;
        }
        return array_values($unpacked);
    }

    // Ungetestete Ableitung aus dem verifizierten Lesepfad - für den Schreibfall
    // (FC6/FC16) gibt es im SymconBC-Referenzmodul KEIN Beispiel (reiner
    // Lese-Zähler). Function-Code und Adressierung folgen demselben Schema,
    // Data enthält (Annahme) die gepackten 16-Bit-Registerwerte statt "".
    public function writeSingle($reg, $value)
    {
        $this->notImplemented('writeSingle/FC6');
        $response = $this->request(6, $reg, 1, pack('n', $value & 0xFFFF));
        return $response !== null;
    }

    public function writeMultiple($startReg, $values)
    {
        $this->notImplemented('writeMultiple/FC16');
        $data = '';
        foreach ($values as $v) {
            $data .= pack('n', $v & 0xFFFF);
        }
        $response = $this->request(16, $startReg, count($values), $data);
        return $response !== null;
    }
}
