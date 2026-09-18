<?php
// Extrahiert IHUB_ModbusGatewayClient aus module.php und testet gegen ein
// simuliertes ForwardToGateway()-Ergebnis (verifiziertes SymconBC-Antwortformat).
$src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
if (!preg_match('/class IHUB_ModbusGatewayClient\b.*?\n}\n/s', $src, $m)) {
    fwrite(STDERR, "Klasse nicht gefunden\n");
    exit(1);
}
function IPS_LogMessage($a,$b){}
eval($m[0]);

class FakeModule {
    public $nextResponse;
    public function ForwardToGateway(string $dataId, int $function, int $address, int $quantity, string $data) {
        return $this->nextResponse;
    }
}

$fails = 0;

// Test 1: readHolding, 2 Register, simulierte Antwort FC+ByteCount+Register
$mod = new FakeModule();
$mod->nextResponse = "\x03\x04" . pack('n', 100) . pack('n', 200);
$client = new IHUB_ModbusGatewayClient($mod, 502, 1);
$regs = $client->readHolding(0, 2);
if ($regs !== [100, 200]) { echo "FAIL readHolding: " . var_export($regs, true) . "\n"; $fails++; } else { echo "OK readHolding\n"; }

// Test 2: readInput
$mod->nextResponse = "\x04\x02" . pack('n', 42);
$regs = $client->readInput(5, 1);
if ($regs !== [42]) { echo "FAIL readInput\n"; $fails++; } else { echo "OK readInput\n"; }

// Test 3: kein Parent verbunden (ForwardToGateway liefert false) -> null
class FakeModuleNoParent {
    public function ForwardToGateway(string $dataId, int $function, int $address, int $quantity, string $data) {
        return false;
    }
}
$client2 = new IHUB_ModbusGatewayClient(new FakeModuleNoParent(), 502, 1);
if ($client2->readHolding(0, 2) !== null) { echo "FAIL kein-Parent-Fall\n"; $fails++; } else { echo "OK kein-Parent-Fall\n"; }

// Test 4: writeSingle liefert bool
$mod->nextResponse = "\x06\x00";
if ($client->writeSingle(10, 5) !== true) { echo "FAIL writeSingle\n"; $fails++; } else { echo "OK writeSingle\n"; }

// Test 5: "hässlicher" Registerwert 0xFFFF darf ForwardToGateway() nicht per
// stillem json_encode()-Fehlschlag verschlucken (MeterHub-Fund 18.09.2026 -
// rohe gepackte Bytes sind meist kein gültiges UTF-8, json_encode() liefert
// dann `false` statt eines Fehlers, was unbemerkt NICHTS verschickt hätte).
// Testet direkt die reale ForwardToGateway()-Logik aus module.php, nicht nur
// die Client-Klasse (der eigentliche Fehler saß dort, nicht im Client).
if (!preg_match('/public function ForwardToGateway\(.*?\n    \}\n/s', $src, $fm)) {
    echo "FAIL ForwardToGateway() nicht gefunden\n";
    $fails++;
} else {
    $stub = 'class ForwardStub { public $sent; function SendDataToParent($j) { $this->sent = $j; return "ok"; } ' . $fm[0] . ' }';
    eval($stub);
    $s = new ForwardStub();
    $result = $s->ForwardToGateway('{DATAID}', 6, 10, 1, pack('n', 0xFFFF));
    if ($result === false || $s->sent === null) {
        echo "FAIL ugly-value: ForwardToGateway lieferte false / sendete nichts\n";
        $fails++;
    } else {
        $decoded = json_decode($s->sent, true);
        if ($decoded === null || base64_decode($decoded['Data']) !== pack('n', 0xFFFF)) {
            echo "FAIL ugly-value: Data kam nicht unversehrt an\n";
            $fails++;
        } else {
            echo "OK ugly-value (0xFFFF via ForwardToGateway)\n";
        }
    }
}

exit($fails > 0 ? 1 : 0);
