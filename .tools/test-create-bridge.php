<?php
// Prueft CreateBridge() (Knopf "Brücke anlegen und verbinden"), die beiden module.json
// und die Sichtbarkeit der Gateway-Felder. Nicht nachbildbar: Kernel-Verhalten beim
// Funktionsaufruf zwischen Instanzen und die Konsole selbst.
$root = __DIR__ . '/..';
$src  = file_get_contents($root . '/InverterHub/module.php');
$fails = 0;
$chk = function($ok, $n) use (&$fails) { echo ($ok ? "OK   " : "FAIL ") . $n . "\n"; if (!$ok) $fails++; };

// --- module.json: Gateway-Schnittstellen nur an der Bruecke, nie am Hauptmodul
$hub    = json_decode(file_get_contents($root . '/InverterHub/module.json'), true);
$bridge = json_decode(file_get_contents($root . '/InverterHubBridge/module.json'), true);
$chk($hub['parentRequirements'] === [] && $hub['implemented'] === [], 'Hauptmodul: parentRequirements und implemented leer');
$chk($bridge['parentRequirements'] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'] && $bridge['implemented'] === ['{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}'], 'Bruecke: parentRequirements und implemented gesetzt');
$chk($bridge['type'] === 3 && $bridge['prefix'] === 'IHUBB' && $bridge['id'] !== $hub['id'], 'Bruecke: type 3, Prefix IHUBB, eigene GUID');
preg_match("/BRIDGE_GUID = '([^']+)'/", $src, $g);
$chk(($g[1] ?? '') === $bridge['id'], 'Hub-Konstante BRIDGE_GUID = GUID der Bruecke');

// --- Sichtbarkeit: Gateway-Felder nur im Verbindungsweg "Symbox-Gateway"
foreach (['GatewayPick', 'CreateBridgeButton', 'BridgeInstanceID'] as $f) {
    $pos = strpos($src, "'name'         => '$f'") ?: strpos($src, "'name'    => '$f'") ?: strpos($src, "'name' => '$f'");
    $slice = $pos === false ? '' : substr($src, $pos, 500);
    $chk($pos !== false && strpos($slice, "ReadPropertyString('ConnectionType') === 'gateway'") !== false, "Feld $f: Anfangs-Sichtbarkeit nur im Gateway-Modus");
    $chk(strpos($src, "UpdateFormField('$f', 'visible'") !== false, "Feld $f: live umschaltbar (RequestAction)");
}
foreach (['Host', 'Port', 'UnitId'] as $f) {
    $chk(strpos($src, "UpdateFormField('$f', 'visible'") !== false, "Feld $f: live umschaltbar");
}

// --- CreateBridge()
if (!preg_match('/public function CreateBridge\(.*?\n    \}\n/s', $src, $m)) { echo "FAIL CreateBridge nicht gefunden\n"; exit(1); }
preg_match("/NATIVE_GATEWAY_GUID = '([^']+)'/", $src, $ng);
$G = ['inst' => [], 'byModule' => [], 'created' => [], 'calls' => [], 'failCreate' => false, 'nextId' => 900];
function IPS_InstanceExists($id) { return isset($GLOBALS['G']['inst'][$id]); }
function IPS_GetInstance($id) { return $GLOBALS['G']['inst'][$id]; }
function IPS_GetInstanceListByModuleID($guid) { return $GLOBALS['G']['byModule'][$guid] ?? []; }
function IPS_GetName($id) { return 'Name' . $id; }
function IPS_GetParent($id) { return 50; }
function IPS_CreateInstance($guid) { if ($GLOBALS['G']['failCreate']) { throw new Exception('Modul unbekannt'); } $id = $GLOBALS['G']['nextId']++; $GLOBALS['G']['inst'][$id] = ['ConnectionID' => 0, 'ModuleInfo' => ['ModuleID' => $guid]]; $GLOBALS['G']['byModule'][$guid][] = $id; $GLOBALS['G']['calls'][] = "create:$id"; return $id; }
function IPS_SetName($id, $n) { $GLOBALS['G']['calls'][] = "name:$id:$n"; }
function IPS_SetParent($id, $p) { $GLOBALS['G']['calls'][] = "parent:$id:$p"; }
function IPS_ConnectInstance($id, $gw) { $GLOBALS['G']['inst'][$id]['ConnectionID'] = $gw; $GLOBALS['G']['calls'][] = "connect:$id:$gw"; }
function IPS_ApplyChanges($id) { $GLOBALS['G']['calls'][] = "apply:$id"; }
eval('class H { const NATIVE_GATEWAY_GUID = "' . $ng[1] . '"; const BRIDGE_GUID = "' . $g[1] . '";
    public $InstanceID = 60; public $fields = [];
    function UpdateFormField($f, $p, $v) { $this->fields[] = [$f, $p, $v]; }
    ' . $m[0] . ' }');
$h = new H();
$GW = $ng[1];

$r = $h->CreateBridge(0);
$chk(strpos($r, '⚠️') === 0 && $G['calls'] === [], 'kein Gateway gewaehlt: Hinweis, nichts angelegt');
$G['inst'][10] = ['ConnectionID' => 0, 'ModuleInfo' => ['ModuleID' => '{ANDERE-INSTANZ}']];
$r = $h->CreateBridge(10);
$chk(strpos($r, 'kein ModBus-Gateway') !== false && $G['calls'] === [], 'falsches Modul: abgelehnt, nichts angelegt');

$G['inst'][11] = ['ConnectionID' => 0, 'ModuleInfo' => ['ModuleID' => $GW]];
$r = $h->CreateBridge(11);
$chk($G['calls'] === ['create:900', 'name:900:InverterHub Brücke (Name11)', 'parent:900:50', 'connect:900:11', 'apply:900'], 'anlegen: Reihenfolge create, name, parent(neben Hub), connect, apply');
$chk(strpos($r, 'angelegt') !== false && end($h->fields) === ['BridgeInstanceID', 'value', 900], 'anlegen: Meldung und Bruecke im Formular eingetragen');

$G['calls'] = [];
$r = $h->CreateBridge(11);
$chk($G['calls'] === [] && strpos($r, 'wiederverwendet') !== false && end($h->fields) === ['BridgeInstanceID', 'value', 900], 'gleiches Gateway: vorhandene Bruecke wiederverwendet, nichts neu angelegt');

$G['inst'][12] = ['ConnectionID' => 0, 'ModuleInfo' => ['ModuleID' => $GW]];
$r = $h->CreateBridge(12);
$chk(in_array('connect:901:12', $G['calls'], true), 'anderes Gateway: eigene neue Bruecke');

$G['failCreate'] = true; $G['inst'][13] = ['ConnectionID' => 0, 'ModuleInfo' => ['ModuleID' => $GW]];
$r = $h->CreateBridge(13);
$chk(strpos($r, 'nicht angelegt werden') !== false, 'Modul fehlt (IPS_CreateInstance wirft): verstaendliche Meldung, kein Fatal');

// --- nie automatisch anlegen
foreach (['ApplyChanges', 'GetConfigurationForm'] as $fn) {
    preg_match('/public function ' . $fn . '\(.*?\n    \}\n/s', $src, $mm);
    $chk(!empty($mm[0]) && strpos($mm[0], '$this->CreateBridge(') === false && strpos($mm[0], 'IPS_CreateInstance') === false, "$fn legt nie eine Bruecke an");
}
exit($fails ? 1 : 0);
