<?php

// ---------------------------------------------------------------------------
// IHUB_ModbusGatewayClient — Anbindung an Symcons eingebauten Symbox-RS485-
// Port über das native Gateway-Instanzmodell, zusätzlich zum direkten TCP-Weg, kein
// Ersatz. SUITE.md 9j, Verbund-Abstimmung mit MeterHub/ChargerHub (18.09.2026).
// Seit 0.90.0 Anpassungsklasse über der gemeinsamen Bibliothek NRGModbus (GatewayTransport):
// die Bibliothek baut die Anfrage als JSON, diese Klasse zerlegt sie und ruft ForwardToGateway()
// der Modulinstanz (SendDataToParent() ist in IPSModule protected). Verhalten wie vorher:
// $requests/$responses zählen, Antworten werden nicht auf die angefragte Zahl gekürzt, der
// Schreibweg (FC6/FC16) ist eine UNGETESTETE Ableitung aus dem verifizierten Lesepfad.
// Lesepfad von MeterHub am Rohcode des offiziellen SymconBC-Referenzmoduls verifiziert
// (github.com/symcon/SymconBC, EM24-DIN/module.php, 18.09.2026).
// ---------------------------------------------------------------------------
class IHUB_ModbusGatewayClient
{
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

    /** @var \IHUB\NRGModbus\ModbusClient */
    private $client;

    public function __construct($module, $port, $unitId, $logger = null)
    {
        $this->module = $module;
        $this->unitId = $unitId;
        $this->logger = $logger;
        $this->client = \IHUB\NRGModbus\ModbusClient::gateway((int)$unitId, function (string $json) {
            return $this->forward($json);
        }, ['trimReply' => false]);
    }

    // Zerlegt die Anfrage der Bibliothek (JSON, Data in Base64) und ruft die Weiterleitung der Modulinstanz auf.
    private function forward(string $json)
    {
        if (!method_exists($this->module, 'ForwardToGateway')) {
            return false;
        }
        $req = json_decode($json, true);
        if (!is_array($req)) {
            return false;
        }
        $data = base64_decode((string)($req['Data'] ?? ''), true);
        $this->requests++;
        $response = @$this->module->ForwardToGateway((string)$req['DataID'], (int)$req['Function'], (int)$req['Address'], (int)$req['Quantity'], $data === false ? '' : $data);
        if ($response === false || $response === null || $response === '') {
            return false;
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
    // readStr/readFloat32). .tools/test-gateway-client.php prueft, dass jede von Treibern
    // genutzte Client-Methode hier existiert. Fehlten sie, brach der erste
    // Lesezugriff im Gateway-Modus mit "Call to undefined method" ab (Forum-
    // Beta-Tester Mstaudi, 19.09.2026: "Aktualisieren tut es auch noch nicht").
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

    public function readFloat32($regs, $offset)
    {
        return \IHUB\NRGModbus\ModbusDecode::float32($regs, $offset, $this->floatWordSwap);
    }

    public function readHolding($startReg, $count)
    {
        return $this->client->readHolding($startReg, $count);
    }

    public function readInput($startReg, $count)
    {
        return $this->client->readInput($startReg, $count);
    }

    // Ungetestete Ableitung aus dem verifizierten Lesepfad - für den Schreibfall
    // (FC6/FC16) gibt es im SymconBC-Referenzmodul KEIN Beispiel (reiner
    // Lese-Zähler). Function-Code und Adressierung folgen demselben Schema,
    // Data enthält (Annahme) die gepackten 16-Bit-Registerwerte statt "".
    public function writeSingle($reg, $value)
    {
        $this->notImplemented('writeSingle/FC6');
        return $this->client->writeSingle($reg, $value);
    }

    public function writeMultiple($startReg, $values)
    {
        $this->notImplemented('writeMultiple/FC16');
        return $this->client->writeMultiple($startReg, $values);
    }
}
