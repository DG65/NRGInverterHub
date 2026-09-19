<?php
// Extrahiert IHUB_ModbusGatewayClient aus module.php und testet gegen ein
// simuliertes ForwardToGateway()-Ergebnis (verifiziertes SymconBC-Antwortformat).
$src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
if (!preg_match('/class IHUB_ModbusGatewayClient\b.*?\n}\n/s', $src, $m)) {
    fwrite(STDERR, "Klasse nicht gefunden\n");
    exit(1);
}
function IPS_LogMessage($a,$b){}
$GLOBALS['connId'] = 1;
function IPS_GetInstance($id){ return ['ConnectionID' => $GLOBALS['connId']]; }
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
    $stub = 'class ForwardStub { public $InstanceID = 1; public $sent; function SendDataToParent($j) { $this->sent = $j; return "ok"; } ' . $fm[0] . ' }';
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

// Test 6: kein Gateway verbunden (ConnectionID 0) -> nichts senden, false
if (isset($fm)) {
    $GLOBALS['connId'] = 0;
    $s2 = new ForwardStub();
    $r2 = $s2->ForwardToGateway('{DATAID}', 3, 0, 2, '');
    if ($r2 !== false || $s2->sent !== null) { echo "FAIL ohne-Gateway: es wurde gesendet\n"; $fails++; } else { echo "OK ohne-Gateway (kein SendDataToParent)\n"; }
    $GLOBALS['connId'] = 1;
}

// Test 7: JEDE Methode, die Treiber am Client ($mb->...) aufrufen, muss in der
// Gateway-Klasse existieren - sonst Fatal Error im Gateway-Modus (Mstaudi 19.09.2026).
preg_match_all('/\$mb->(\w+)\(/', $src, $um);
$missing = [];
foreach (array_unique($um[1]) as $meth) {
    if (!method_exists('IHUB_ModbusGatewayClient', $meth)) { $missing[] = $meth; }
}
if ($missing) { echo "FAIL Treiber nutzen Methoden, die dem Gateway-Client fehlen: " . implode(', ', $missing) . "\n"; $fails++; }
else { echo "OK alle von Treibern genutzten Client-Methoden existieren im Gateway-Client\n"; }

// Test 8: Dekodier-Hilfen liefern dasselbe wie beim Direkt-Client
$g = new IHUB_ModbusGatewayClient(new FakeModule(), 502, 1);
$regs = [0xFFFE, 0x0001, 0x4048, 0xF5C3, 0x4142];
$ok = $g->s16($regs, 0) === -2 && $g->u32($regs, 0) === 0xFFFE0001 && $g->s32($regs, 0) === -131071
   && $g->readStr($regs, 4, 1) === 'AB' && abs($g->readFloat32($regs, 2) - 3.14) < 0.001;
$g->setFloatWordSwap(true);
$ok = $ok && abs($g->readFloat32([0xF5C3, 0x4048], 0) - 3.14) < 0.001;
if (!$ok) { echo "FAIL Dekodier-Hilfen\n"; $fails++; } else { echo "OK Dekodier-Hilfen (u16/s16/u32/s32/readStr/readFloat32, Wortvertauschung)\n"; }

// Test 9: Antwortzaehler und beschreibbares unitId (Treiber setzen $mb->unitId)
$m9 = new FakeModule();
$c9 = new IHUB_ModbusGatewayClient($m9, 502, 1);
$m9->nextResponse = false;
$c9->readHolding(0, 1);
$noAnswer = ($c9->requests === 1 && $c9->responses === 0);
$m9->nextResponse = "\x03\x02" . pack('n', 5);
$c9->readHolding(0, 1);
$answered = ($c9->requests === 2 && $c9->responses === 1);
$c9->unitId = 42;
if (!($noAnswer && $answered && $c9->unitId === 42)) { echo "FAIL Antwortzaehler/unitId\n"; $fails++; } else { echo "OK Antwortzaehler und beschreibbares unitId\n"; }

exit($fails > 0 ? 1 : 0);
