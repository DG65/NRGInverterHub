<?php
// Verbund-Konvention "Verbindungen im Formular sichtbar machen" (SUITE.md, 21.09.2026):
// jede Verbindung zeigt live ✅ / ⚠️ / ℹ️. Prueft die Zeilen je Zustand und, dass sie im
// ausgelieferten Formular stehen (Element direkt in den items, nicht ersetzt).
$root = __DIR__ . '/..';
$hub  = file_get_contents($root . '/InverterHub/module.php');
$disc = file_get_contents($root . '/InverterHubDiscovery/module.php');
$fails = 0;
$chk = function ($ok, $n) use (&$fails) { echo ($ok ? "OK   " : "FAIL ") . $n . "\n"; if (!$ok) $fails++; };

// ------------------------------------------------------------ Kernmodul: Bruecke
if (!preg_match('/private function BridgeStatusLine\(.*?\n    \}\n/s', $hub, $m)) { echo "FAIL BridgeStatusLine nicht gefunden\n"; exit(1); }
$G = ['inst' => [], 'state' => null, 'hasFn' => true];
function IPS_InstanceExists($id) { return isset($GLOBALS['G']['inst'][$id]); }
function IPS_GetName($id) { return 'Name' . $id; }
function IPS_GetInstance($id) { return $GLOBALS['G']['inst'][$id]; }
function IHUBB_GetState($id) { return $GLOBALS['G']['state']; }
eval('class HB { ' . str_replace('function_exists(\'IHUBB_GetState\')', '$GLOBALS["G"]["hasFn"]', $m[0]) . ' public function t($i) { return $this->BridgeStatusLine($i); } }');
$h = new HB();

$r = $h->t(0);
$chk(strpos($r, 'ℹ️') === 0 && strpos($r, 'inaktiv') !== false, 'keine Bruecke gewaehlt: ℹ️ und was dann gilt');
$G['inst'][20] = ['ConnectionID' => 0];
$G['hasFn'] = false;
$chk(strpos($h->t(20), '⚠️') === 0 && strpos($h->t(20), 'aktualisieren') !== false, 'Bruecken-Modul ohne Funktion: ⚠️ mit Handlungshinweis');
$G['hasFn'] = true; $G['state'] = 'kein json';
$chk(strpos($h->t(20), '⚠️') === 0, 'Muell statt Zustand: ⚠️');
$G['state'] = json_encode(['connected' => false, 'parentActive' => false, 'parentStatus' => 0, 'unitId' => null]);
$chk(strpos($h->t(20), '⚠️') === 0 && strpos($h->t(20), 'mit keinem ModBus Gateway') !== false && strpos($h->t(20), '#20') !== false, 'Bruecke ohne Gateway: ⚠️ mit ID und Hinweis');
$G['inst'][20] = ['ConnectionID' => 30];
$G['state'] = json_encode(['connected' => true, 'parentActive' => false, 'parentStatus' => 104, 'unitId' => 6]);
$chk(strpos($h->t(20), '⚠️') === 0 && strpos($h->t(20), 'nicht aktiv (Status 104)') !== false && strpos($h->t(20), '#30') !== false, 'Gateway inaktiv: ⚠️ mit Gateway-ID und Status');
$G['state'] = json_encode(['connected' => true, 'parentActive' => true, 'parentStatus' => 102, 'unitId' => 6]);
$r = $h->t(20);
$chk(strpos($r, '✅') === 0 && strpos($r, '#20') !== false && strpos($r, '#30') !== false && strpos($r, 'Geräte-ID am Gateway: 6') !== false, '✅ nennt Bruecke, Gateway und gelesene Geraete-ID');
$G['state'] = json_encode(['connected' => true, 'parentActive' => true, 'parentStatus' => 102, 'unitId' => null]);
$chk(strpos($h->t(20), 'nicht lesbar') !== false, '✅ ohne lesbare Geraete-ID sagt es das');

// Zeile steht im ausgelieferten Formular: als Element direkt in den items, sichtbar nur im Gateway-Modus,
// live nachgefuehrt bei Auswahlwechsel, Umschalten und nach dem Anlegen.
$chk(preg_match("/'name'\\s*=>\\s*'BridgeStatus',\\s*'caption'\\s*=>\\s*\\\$this->BridgeStatusLine\\(\\\$this->ReadPropertyInteger\\('BridgeInstanceID'\\)\\),\\s*'visible'\\s*=>\\s*\\\$this->ReadPropertyString\\('ConnectionType'\\) === 'gateway'/s", $hub) === 1, 'Formular: BridgeStatus mit berechneter Zeile, nur im Gateway-Modus sichtbar');
$chk(strpos($hub, "'onChange'     => 'IPS_RequestAction(\$id, \"BridgeChanged\", \$BridgeInstanceID);'") !== false && strpos($hub, "if (\$Ident === 'BridgeChanged')") !== false, 'Auswahlwechsel fuehrt die Zeile live nach');
$chk(strpos($hub, "UpdateFormField('BridgeStatus', 'visible', !\$direct)") !== false, 'Umschalten Direkt/Gateway blendet die Zeile mit');
$chk(preg_match("/UpdateFormField\\('BridgeInstanceID', 'value', \\\$bridge\\);\\s*\\\$this->UpdateFormField\\('BridgeStatus', 'caption'/", $hub) === 1, 'nach "Bruecke anlegen und verbinden" wird die Zeile aktualisiert');

// ------------------------------------------------------------ Gerätesuche: MeterHub, MigrationsHub
foreach (['LibraryVersion', 'MeterHubStatusLine', 'MigrationsHubStatusLine', 'ConnectionPanel'] as $fn) {
    if (!preg_match('/private function ' . $fn . '\(.*?\n    \}\n/s', $disc, $mm)) { echo "FAIL $fn nicht gefunden\n"; exit(1); }
    $methods[$fn] = $mm[0];
}
$D = ['mh' => false, 'mig' => false, 'fn' => false, 'lists' => [], 'mods' => [], 'libs' => []];
function IPS_ModuleExists($g) { return in_array($g, $GLOBALS['D']['mods'], true); }
function IPS_GetInstanceListByModuleID($g) { return $GLOBALS['D']['lists'][$g] ?? []; }
function IPS_GetModule($g) { return isset($GLOBALS['D']['libs'][$g]) ? ['LibraryID' => $g . 'L'] : false; }
function IPS_GetLibrary($id) { return ['Version' => $GLOBALS['D']['libs'][substr($id, 0, -1)] ?? '']; }
function MIGHUB_FindLegacyCandidates() {}
preg_match("/METERHUB_GUID = '([^']+)'/", $disc, $g1); preg_match("/MIGRATIONSHUB_GUID = '([^']+)'/", $disc, $g2);
$body = implode("\n", $methods);
$body = str_replace("function_exists('MIGHUB_FindLegacyCandidates')", '$GLOBALS["D"]["fn"]', $body);
eval('class HD { const METERHUB_GUID = "' . $g1[1] . '"; const MIGRATIONSHUB_GUID = "' . $g2[1] . '";
    private function meterHubInstalled() { return IPS_ModuleExists(self::METERHUB_GUID); }
    ' . $body . ' public function mh() { return $this->MeterHubStatusLine(); } public function mig() { return $this->MigrationsHubStatusLine(); } public function panel() { return $this->ConnectionPanel(); } }');
$x = new HD(); $MH = $g1[1]; $MIG = $g2[1];

$r = $x->mh();
$chk(strpos($r, 'ℹ️') === 0 && strpos($r, 'nicht installiert') !== false && strpos($r, 'nur Wechselrichter') !== false, 'MeterHub nicht installiert: ℹ️ und was dann gilt');
$D['mods'][] = $MH; $D['libs'][$MH] = '0.31.3-beta.1';
$r = $x->mh();
$chk(strpos($r, '✅') === 0 && strpos($r, '0.31.3-beta.1') !== false && strpos($r, 'noch keine Instanz') !== false && strpos($r, 'Energiezähler') !== false, 'MeterHub installiert ohne Instanz: ✅ mit Version und Wirkung');
$D['lists'][$MH] = [5, 6];
$chk(strpos($x->mh(), '2 Instanzen vorhanden') !== false, 'MeterHub: Anzahl der Instanzen genannt');

$r = $x->mig();
$chk(strpos($r, 'ℹ️') === 0 && strpos($r, 'nicht installiert') !== false && strpos($r, 'entfällt') !== false, 'MigrationsHub nicht installiert: ℹ️ und was dann gilt');
$D['fn'] = true; $D['mods'][] = $MIG; $D['libs'][$MIG] = '0.5.0';
$r = $x->mig();
$chk(strpos($r, '✅') === 0 && strpos($r, '0.5.0') !== false && strpos($r, 'noch keine Instanz') !== false, 'MigrationsHub ohne Instanz: ✅ mit Version, sagt dass sie beim Suchlauf angelegt wird');
$D['lists'][$MIG] = [77];
$r = $x->mig();
$chk(strpos($r, '✅') === 0 && strpos($r, 'Instanz #77') !== false && strpos($r, 'Altinstanzen') !== false, 'MigrationsHub mit Instanz: ✅ mit Instanz-ID und Wirkung');
$D['fn'] = false;
$chk(strpos($x->mig(), 'ℹ️') === 0, 'MigrationsHub-Funktion fehlt trotz Modul: ℹ️ (kein Fatal)');

// Im ausgelieferten Formular: Panel enthaelt beide Zeilen direkt als Elemente, und GetConfigurationForm setzt es ein.
$panel = $x->panel(); $names = array_column($panel['items'], 'name');
$chk($names === ['MeterHubStatus', 'MigrationsHubStatus'] && strpos($panel['items'][0]['caption'], 'MeterHub') !== false, 'Panel "Verbundene Module" enthaelt beide Zeilen als Elemente');
$chk(preg_match('/array_splice\(\$form\[\'elements\'\], 1, 0, \[\$this->ConnectionPanel\(\)\]\);/', $disc) === 1, 'GetConfigurationForm setzt das Panel in die ausgelieferten elements ein');
$chk(strpos($disc, 'IPS_CreateInstance') === false || strpos($methods['ConnectionPanel'] . $methods['MigrationsHubStatusLine'] . $methods['MeterHubStatusLine'], 'IPS_CreateInstance') === false, 'Statuszeilen legen nie eine Instanz an');
exit($fails ? 1 : 0);
