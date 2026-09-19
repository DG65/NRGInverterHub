<?php
// Prueft das Brueckenmodul (InverterHubBridge): Forward()/GetState() gegen simulierte
// Instanz-/Gateway-Zustaende. Vertrag identisch zu MeterHub/ChargerHub.
$src = file_get_contents(__DIR__ . '/../InverterHubBridge/module.php');
if (!preg_match('/public function Forward\(.*?\n    \}\n\n    public function GetState\(.*?\n    \}\n\n    private function ParentState\(.*?\n    \}\n/s', $src, $m)) {
    fwrite(STDERR, "FAIL Methoden nicht gefunden\n"); exit(1);
}
$GLOBALS['inst'] = []; $GLOBALS['props'] = [];
function IPS_GetInstance($id) { return $GLOBALS['inst'][$id] ?? ['ConnectionID' => 0, 'InstanceStatus' => 0]; }
function IPS_InstanceExists($id) { return isset($GLOBALS['inst'][$id]); }
function IPS_GetProperty($id, $n) { if (!isset($GLOBALS['props'][$id][$n])) { throw new Exception('kein Property'); } return $GLOBALS['props'][$id][$n]; }
eval('class B { public $InstanceID = 1; public $reply = false; public $sent = null;
    function SendDataToParent($j) { $this->sent = $j; return $this->reply; }
    ' . $m[0] . ' }');
$fails = 0; $chk = function($ok, $n) use (&$fails) { echo ($ok ? "OK   " : "FAIL ") . $n . "\n"; if (!$ok) $fails++; };
$b = new B();

$GLOBALS['inst'] = [1 => ['ConnectionID' => 0, 'InstanceStatus' => 102]];
$r = json_decode($b->Forward('{"x":1}'), true);
$chk($r === ['ok' => false, 'error' => 'not_connected'] && $b->sent === null, 'ohne Gateway: not_connected, nichts gesendet');
$chk(json_decode($b->GetState(), true) === ['connected' => false, 'parentActive' => false, 'parentStatus' => 0, 'unitId' => null], 'GetState ohne Gateway');

$GLOBALS['inst'] = [1 => ['ConnectionID' => 5, 'InstanceStatus' => 102], 5 => ['ConnectionID' => 0, 'InstanceStatus' => 104]];
$r = json_decode($b->Forward('{"x":1}'), true);
$chk($r === ['ok' => false, 'error' => 'parent_inactive'] && $b->sent === null, 'Gateway inaktiv: parent_inactive, nichts gesendet');

$GLOBALS['inst'][5]['InstanceStatus'] = 102; $GLOBALS['props'][5]['DeviceID'] = 6;
$b->reply = false;
$r = json_decode($b->Forward('{"x":1}'), true);
$chk($r === ['ok' => false, 'error' => 'no_response'] && $b->sent === '{"x":1}', 'keine Antwort: no_response, Anfrage unveraendert durchgereicht');

$raw = "\x03\x04" . pack('n', 0xFFFF) . "\x80\xFE";
$b->reply = $raw;
$r = json_decode($b->Forward('{"x":1}'), true);
$chk(($r['ok'] ?? false) === true && base64_decode($r['data'], true) === $raw, 'Antwort mit rohen Bytes (0xFFFF, 0x80, 0xFE) heil ueber Base64');
$chk(json_decode($b->GetState(), true) === ['connected' => true, 'parentActive' => true, 'parentStatus' => 102, 'unitId' => 6], 'GetState mit Gateway und gelesener Geraete-ID');

unset($GLOBALS['props'][5]['DeviceID']);
$st = json_decode($b->GetState(), true);
$chk(array_key_exists('unitId', $st) && $st['unitId'] === null && $st['connected'] === true, 'Geraete-ID nicht lesbar: unitId null, kein Fatal');
exit($fails ? 1 : 0);
