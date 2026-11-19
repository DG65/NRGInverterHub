<?php
/**
 * Solis Remote Dispatch (0.91.0, Smart Control V1.0, EMS-Auftrag 19.11.2026): Register 44100-44110, Fähigkeit 34502-34504.
 *   php .tools/test-solis-dispatch.php          # 0 = alle Prüfungen bestanden
 *
 * Ein nachgebildetes Gerät (DispMb) nimmt Schreibzugriffe nach den Regeln, die Pauls Solis am 19.11.2026 gezeigt hat:
 * der Echtzeitblock gilt nur, wenn der Hauptschalter 44100 bereits an ist; 0xFFFF im Systemblock wird nicht übernommen;
 * 34504 meldet 0 aus, 1 Standby, 2 Echtzeit. Die Registerwerte des Prüfstands sind aus der Doku abgeleitet, nicht am Gerät gemessen.
 */
require_once __DIR__ . '/ref/harness.php';
IHUB_SolisDispatch::$spacingUs = 0; IHUB_SolisDispatch::$readbackUs = 0; IHUB_SolisDispatch::$retryPauseUs = 0;

class DispMb extends RefMb
{
    public $available = true;          // 34502 = 0xAA55
    public $ignoreWrites = 0;          // so viele Schreibbefehle werden quittiert, aber nicht übernommen
    public $statusZero = false;        // 34504 bleibt 0, obwohl die Register stehen
    public $times = [];                // Zeitpunkt jedes Schreibbefehls
    public $batches = 0;               // Zahl der beginBatch()-Aufrufe
    public function beginBatch() { $this->batches++; }
    public function __construct() { parent::__construct(1); for ($a = 44100; $a <= 44110; $a++) { $this->regs[3][$a] = 0; } $this->refresh(); }
    public function refresh()
    {
        $on = ($this->regs[3][44100] ?? 0) === 1;
        $this->regs[4][34502] = $this->available ? 0xAA55 : 0x0000;
        $this->regs[4][34503] = $this->available ? 1 : 0;
        $this->regs[4][34504] = ($on && !$this->statusZero) ? (($this->regs[3][44105] ?? 1) === 1 ? 1 : 2) : 0;
    }
    public function writeMultiple($start, $values)
    {
        $this->lastError = '';
        if (in_array((int)$start, $this->failWrite, true)) { $this->lastError = 'no connection (simuliert)'; $this->writes[] = ['multi-failed', (int)$start, $values]; return false; }
        $this->writes[] = ['multi', (int)$start, array_map('intval', $values)];
        $this->times[] = microtime(true);
        if ($this->ignoreWrites > 0) { $this->ignoreWrites--; return true; }
        $values = array_values($values);
        if ((int)$start === 44100 && count($values) === 1) {          // Hauptschalter aus
            $this->regs[3][44100] = $values[0] & 0xFFFF;
            if ($values[0] === 0) { $this->regs[3][44101] = 5; }      // Gerät setzt den Totmann zurück
        } elseif ((int)$start === 44100) {                            // Systemblock
            if (in_array(0xFFFF, $values, true)) { $this->refresh(); return true; }   // wurde am Gerät nicht übernommen
            foreach ($values as $i => $v) { $this->regs[3][44100 + $i] = $v & 0xFFFF; }
        } elseif ((int)$start === 44105) {                            // Echtzeitblock: gilt nur bei laufendem Hauptschalter
            if (($this->regs[3][44100] ?? 0) === 1) {
                foreach ($values as $i => $v) {
                    $a = 44105 + $i;
                    if ($a === 44108) { $old = $this->regs[3][44108] ?? 0; foreach ([0x03, 0x30] as $m) { if (($v & $m) === 0) { $v = ($v & ~$m) | ($old & $m); } } }
                    $this->regs[3][$a] = $v & 0xFFFF;
                }
            }
        }
        $this->refresh();
        return true;
    }
}

function newHub(array $seed = []) { $h = new RefHub(['GroupControl' => true]); $h->seed = $seed; return $h; }
$drv = new IHUB_SolisDriver();
$codes = function (DispMb $mb) { return array_map(function ($w) { return $w[1] . ':' . json_encode($w[2]); }, $mb->writes); };

echo "A) Register aufbauen\n";
ref_ok(IHUB_SolisDispatch::systemBlock(['mode' => 2]) === [1, 5, 0, 0, 0], 'Systemblock ohne Grenzen: an, Totmann 5 min (Vorgabe), keine Grenzschalter, Grenzen 0');
ref_ok(IHUB_SolisDispatch::systemBlock(['mode' => 2, 'failsafeMin' => 2, 'importLimitW' => 11000, 'exportLimitW' => 0]) === [1, 2, 1, 110, 0], 'Systemblock Bezugsgrenze 11000 W: Bit0, 110 (100 W)');
ref_ok(IHUB_SolisDispatch::systemBlock(['mode' => 2, 'importLimitW' => 0, 'exportLimitW' => 800]) === [1, 5, 2, 0, 8], 'Systemblock Einspeisegrenze 800 W: Bit1, 8');
ref_ok(IHUB_SolisDispatch::systemBlock(['mode' => 2, 'failsafeMin' => 99999])[1] === 1440, 'Totmann auf 1440 min begrenzt');
$all = [];
foreach ([[0, 0], [11000, 800], [40000, 40000], [-5, -5], [123456, 99999]] as [$i, $e]) { $all = array_merge($all, IHUB_SolisDispatch::systemBlock(['mode' => 2, 'importLimitW' => $i, 'exportLimitW' => $e])); }
ref_ok(!in_array(0xFFFF, $all, true), 'der Systemblock enthält nie 0xFFFF (am Gerät nicht übernommen)');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 2, 'watts' => 3000]) === [2, 0, 300, 0, 0, 100], 'Echtzeitblock Akku +3000 W (Laden): S32 300 (10 W), SOC 0 bis 100 (obere Grenze 0 gilt als 100)');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 2, 'watts' => -3000]) === [2, 0xFFFF, 0xFED4, 0, 0, 100], 'Akku −3000 W (Entladen): S32 −300 = 0xFFFFFED4');
ref_ok(IHUB_SolisDispatch::decodePower(0xFFFF, 0xFED4) === -3000 && IHUB_SolisDispatch::decodePower(0, 300) === 3000, 'Leistung hin und zurück (positiv und negativ)');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 3, 'watts' => -1234])[1] === 0xFFFF && IHUB_SolisDispatch::realtimeBlock(['mode' => 3, 'watts' => -1234])[2] === 0xFF85, 'Modus 3 −1234 W: auf 10 W gerundet (−123 = 0xFF85)');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 1, 'watts' => 5000]) === [1, 0, 0, 0, 0, 100], 'Standby: Leistung wird als 0 geschrieben, auch wenn eine andere gemerkt ist');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 2, 'watts' => 99999999])[2] === 4000, 'Leistung auf 40000 W begrenzt');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 2, 'watts' => 0, 'gridCharge' => 1, 'pvShutdown' => 2])[3] === 0x12, 'Funktionsschalter: Netzladen erlaubt (Bits 4-5 = 1) und PV abschalten (Bits 0-1 = 2) = 0x12');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 2, 'watts' => 0, 'gridCharge' => 2])[3] === 0x20, 'Netzladen nicht erlaubt (Bits 4-5 = 2) = 0x20');
ref_ok(IHUB_SolisDispatch::realtimeBlock(['mode' => 2, 'watts' => 0, 'socMin' => 20, 'socMax' => 90]) === [2, 0, 0, 0, 20, 90], 'SOC-Bereich 20 bis 90');
ref_ok(IHUB_SolisDispatch::validate(['mode' => 7]) !== '' && IHUB_SolisDispatch::validate(['mode' => 2, 'socMin' => 80, 'socMax' => 50]) !== '' && IHUB_SolisDispatch::validate(['mode' => 2, 'socMin' => 101]) !== '' && IHUB_SolisDispatch::validate(['mode' => 4, 'socMin' => 0, 'socMax' => 100]) === '', 'Prüfung: ungültiger Modus und SOC-Bereich abgelehnt, gültiges angenommen');
ref_ok(IHUB_SolisDispatch::$spacingUs >= 0, 'Abstandsgröße vorhanden');

echo "B) Fähigkeit\n";
$mb = new DispMb(); $mb->available = false; $mb->refresh();
ref_ok(IHUB_SolisDispatch::probe($mb) === ['available' => false, 'version' => 0, 'status' => 0], 'Gerät ohne 0xAA55: nicht verfügbar');
$mb = new DispMb(); $mb->available = true; $mb->refresh();
ref_ok(IHUB_SolisDispatch::probe($mb) === ['available' => true, 'version' => 1, 'status' => 0], 'Gerät mit 0xAA55: verfügbar, Version 1, Status 0');
$mb = new DispMb(); $mb->silent = true;
ref_ok(IHUB_SolisDispatch::probe($mb) === null, 'keine Antwort auf 34502: null');
$mb = new DispMb(); $mb->available = false; $mb->refresh(); $hub = newHub();
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_dispatch_mode']), 'ohne Dispatch-Fähigkeit: nichts geschrieben, Warnung, Variable bleibt (Rückfall auf die bisherigen Wege)');
$mb = new DispMb(); $mb->silent = true; $hub = newHub();
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1, 'Fähigkeitsabfrage ohne Antwort: nichts geschrieben, Warnung');

echo "C) Schreiben und Rücklesen\n";
$mb = new DispMb(); $hub = newHub();
$drv->writeControl($mb, $hub, 'ctl_dispatch_w', -2500);
$drv->writeControl($mb, $hub, 'ctl_dispatch_failsafe_min', 2);
$drv->writeControl($mb, $hub, 'ctl_dispatch_grid_charge', 2);
ref_ok($mb->writes === [] && $hub->vars['ctl_dispatch_w'][1] === -2500 && $hub->vars['ctl_dispatch_failsafe_min'][1] === 2, 'Vorgaben werden nur gemerkt, kein Schreibzugriff');
$hub->seed = ['ctl_dispatch_w' => -2500, 'ctl_dispatch_failsafe_min' => 2, 'ctl_dispatch_grid_charge' => 2];
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok($codes($mb) === ['44100:[1,2,0,0,0]', '44105:[2,65535,65286,32,0,100]'], 'Schreibreihenfolge: erst System (44100-44104), dann Echtzeit (44105-44110): ' . implode(' ', $codes($mb)));
ref_ok($hub->warn === [] && $hub->vars['ctl_dispatch_mode'][1] === 2 && $hub->vars['dispatch_status'][1] === 2 && $hub->vars['dispatch_available'][1] === true, 'Gerät hat die Werte: Modus 2 gemerkt, Status 2 (Echtzeit), verfügbar');
ref_ok(count(array_filter($mb->reads, function ($r) { return $r[1] === 34502; })) === 1, 'Fähigkeit unbekannt: genau eine Abfrage von 34502 vor dem Schreiben');
$mb->reads = []; $mb->writes = [];
$hub->seed['dispatch_version'] = 1;
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok(count(array_filter($mb->reads, function ($r) { return $r[1] === 34502; })) === 0 && count($mb->writes) === 2, 'Fähigkeit bekannt (Version aus dem langsamen Zyklus): keine Abfrage von 34502');
ref_ok(count($mb->reads) === 2 && $mb->reads[0][1] === 44100 && $mb->reads[0][2] === 11 && $mb->reads[1][1] === 34504, 'Rücklesen: 44100-44110 und 34504');
$mb = new DispMb(); $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 1);
ref_ok($hub->vars['dispatch_status'][1] === 1 && $hub->vars['ctl_dispatch_mode'][1] === 1 && $hub->warn === [], 'Standby: Status 1');
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 0);
ref_ok(array_slice($codes($mb), -1) === ['44100:[0]'] && $hub->vars['ctl_dispatch_mode'][1] === 0 && $hub->vars['dispatch_status'][1] === 0 && $hub->warn === [], 'Aus: nur 44100 := 0, Status 0');
$mb = new DispMb(); $hub = newHub(['dispatch_version' => 1, 'ctl_dispatch_w' => -4000]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 1);
ref_ok(in_array('44105:[1,0,0,0,0,100]', $codes($mb), true), 'Standby schreibt 0 W, auch wenn −4000 W gemerkt sind');

echo "D) Fehlerfälle\n";
$mb = new DispMb(); $mb->ignoreWrites = 2; $hub = newHub(['dispatch_version' => 1]);   // beide Schreibbefehle des ersten Versuchs werden quittiert und nicht übernommen
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok($hub->warn === [] && $hub->vars['ctl_dispatch_mode'][1] === 2 && count($mb->writes) === 4, 'erster Versuch nicht übernommen, zweiter Gesamtversuch gelingt (4 Schreibbefehle)');
$mb = new DispMb(); $mb->ignoreWrites = 99; $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_dispatch_mode']) && count($mb->writes) === 4 && strpos($hub->warn[0], 'stored 0 instead of 1') !== false, 'Gerät übernimmt nichts: nach zwei Versuchen eine Warnung mit Grund, Anzeige bleibt: ' . ($hub->warn[0] ?? ''));
$mb = new DispMb(); $mb->failWrite = [44105]; $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_dispatch_mode']), 'Echtzeitblock scheitert: Warnung, Modus bleibt');
$mb = new DispMb(); $mb->failWrite = [44100]; $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok(count($hub->warn) === 1 && !in_array(44105, array_map(function ($w) { return $w[1]; }, $mb->writes), true), 'Systemblock scheitert: der Echtzeitblock wird nicht geschrieben');
$mb = new DispMb(); $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 9);
ref_ok($mb->writes === [] && count($hub->warn) === 1, 'ungültiger Modus 9: nichts geschrieben, Warnung');
$mb = new DispMb(); $hub = newHub(['dispatch_version' => 1, 'ctl_dispatch_soc_min' => 80, 'ctl_dispatch_soc_max' => 50]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1, 'SOC-Bereich 80 bis 50: nichts geschrieben, Warnung');

$mb = new DispMb(); $mb->statusZero = true; $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_dispatch_mode']) && strpos($hub->warn[0], '34504') !== false, 'Register stehen, Gerät meldet aber keinen laufenden Dispatch (34504 = 0): Warnung, Modus bleibt');
$mb = new DispMb(); $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
ref_ok($mb->batches === 1, 'Rücklesen über eine gemeinsame Verbindung (ein Batch)');
IHUB_SolisDispatch::$spacingUs = 80000;
$mb = new DispMb(); $hub = newHub(['dispatch_version' => 1]);
$drv->writeControl($mb, $hub, 'ctl_dispatch_mode', 2);
IHUB_SolisDispatch::$spacingUs = 0;
ref_ok(count($mb->times) === 2 && ($mb->times[1] - $mb->times[0]) >= 0.075, 'Abstand zwischen System- und Echtzeitblock wird eingehalten (' . (count($mb->times) === 2 ? round(($mb->times[1] - $mb->times[0]) * 1000) : '?') . ' ms bei 80 ms eingestellt)');

echo "E) Merken der Vorgaben\n";
$mb = new DispMb(); $hub = newHub();
$drv->writeControl($mb, $hub, 'ctl_dispatch_w', 123456);
$drv->writeControl($mb, $hub, 'ctl_dispatch_w', -123456);
$a = $hub->vars['ctl_dispatch_w'][1];
$drv->writeControl($mb, $hub, 'ctl_dispatch_w', 1234);
ref_ok($a === -40000 && $hub->vars['ctl_dispatch_w'][1] === 1230, 'Leistung auf ±40000 W begrenzt, auf 10 W gerundet');
$drv->writeControl($mb, $hub, 'ctl_dispatch_import_limit_w', 11049);
ref_ok($hub->vars['ctl_dispatch_import_limit_w'][1] === 11000, 'Bezugsgrenze auf 100 W gerundet');
$drv->writeControl($mb, $hub, 'ctl_dispatch_grid_charge', 5); $w1 = count($hub->warn);
$drv->writeControl($mb, $hub, 'ctl_dispatch_grid_charge', 1);
ref_ok($w1 === 1 && $hub->vars['ctl_dispatch_grid_charge'][1] === 1, 'Netzladen-Schalter: 5 abgelehnt (Warnung), 1 angenommen');
$drv->writeControl($mb, $hub, 'ctl_dispatch_soc_min', -5); $drv->writeControl($mb, $hub, 'ctl_dispatch_soc_max', 400); $drv->writeControl($mb, $hub, 'ctl_dispatch_failsafe_min', 99999);
ref_ok($hub->vars['ctl_dispatch_soc_min'][1] === 0 && $hub->vars['ctl_dispatch_soc_max'][1] === 100 && $hub->vars['ctl_dispatch_failsafe_min'][1] === 1440, 'SOC und Totmann auf den Bereich begrenzt');
ref_ok($mb->writes === [], 'keine dieser Vorgaben löst einen Schreibzugriff aus');

echo "F) Lesezyklus\n";
$mb = new DispMb(); $hub = newHub();
$drv->readSlow($mb, $hub);
ref_ok($hub->vars['dispatch_available'][1] === true && $hub->vars['dispatch_version'][1] === 1 && $hub->vars['dispatch_status'][1] === 0, 'readSlow: Fähigkeit, Version, Status gelesen');
$mb = new DispMb(); $mb->available = false; $mb->refresh(); $mb->regs[4][34503] = 7; $mb->regs[4][34504] = 2; $hub = newHub();
$drv->readSlow($mb, $hub);
ref_ok($hub->vars['dispatch_available'][1] === false && $hub->vars['dispatch_version'][1] === 0 && $hub->vars['dispatch_status'][1] === 0, 'Gerät ohne Dispatch: nicht verfügbar, Version 0');
$mb = new DispMb(); $hub = new RefHub(['GroupControl' => false]);
$drv->readSlow($mb, $hub);
ref_ok(count(array_filter($mb->reads, function ($r) { return $r[1] === 34502; })) === 0 && !isset($hub->vars['dispatch_available']), 'Gruppe Steuerung aus: 34502 wird nicht gelesen');
$mb = new DispMb(); $mb->silent = true; $hub = newHub(['dispatch_version' => 1]);
$drv->readSlow($mb, $hub);
ref_ok(!isset($hub->vars['dispatch_available']), 'keine Antwort: die Variablen bleiben unverändert');

echo "G) Variablen, Profile, Vertrag\n";
$vars = $drv->getOptionalGroups()['GroupControl']['vars'];
$idents = array_column($vars, 0);
foreach (['ctl_dispatch_mode', 'ctl_dispatch_w', 'ctl_dispatch_grid_charge', 'ctl_dispatch_pv_shutdown', 'ctl_dispatch_soc_min', 'ctl_dispatch_soc_max', 'ctl_dispatch_failsafe_min', 'ctl_dispatch_import_limit_w', 'ctl_dispatch_export_limit_w', 'dispatch_available', 'dispatch_version', 'dispatch_status'] as $i) {
    ref_ok(in_array($i, $idents, true), "Variable $i angelegt");
}
$byIdent = []; foreach ($vars as $v) { $byIdent[$v[0]] = $v; }
ref_ok($byIdent['ctl_dispatch_mode'][5] === 'control' && $byIdent['dispatch_status'][5] === 'device' && $byIdent['dispatch_available'][5] === 'device' && $byIdent['dispatch_version'][5] === 'device', 'Steuerpunkte im Bereich control (Aktion), Status nur lesend (kein Schalter)');
$missing = [];
$prof = array_merge(array_keys($drv->getProfiles()), array_keys($drv->getEnumProfiles()));
foreach ($vars as $v) { if ($v[3] !== '' && $v[3][0] !== '~' && !in_array($v[3], $prof, true)) { $missing[] = $v[0] . '=' . $v[3]; } }
ref_ok($missing === [], 'jedes benutzte Profil ist definiert' . ($missing ? ': ' . implode(',', $missing) : ''));
// Vertragsfeld
require_once __DIR__ . '/ref/harness.php';
$GLOBALS['TREE'] = [];
function IPS_GetChildrenIDs($p) { return $p === 1 ? array_values(array_map(function ($v) { return $v[0]; }, $GLOBALS['TREE'])) : []; }
function IPS_GetObject($id) { foreach ($GLOBALS['TREE'] as $ident => $v) { if ($v[0] === $id) { return ['ObjectIdent' => $ident, 'ObjectType' => 2]; } } return ['ObjectIdent' => '', 'ObjectType' => 2]; }
function IPS_ObjectExists($id) { return true; }
class DspHub extends InverterHub
{
    public function __construct() { $this->InstanceID = 1; }
    public function ReadPropertyString($n) { return $n === 'Manufacturer' ? 'solis' : 'ems'; }
    public function ReadPropertyBoolean($n) { return false; }
    public function ReadPropertyInteger($n) { return 0; }
}
$GLOBALS['TREE'] = ['ctl_dispatch_mode' => [101], 'ctl_dispatch_w' => [102], 'dispatch_available' => [103], 'dispatch_version' => [104], 'dispatch_status' => [105], 'ctl_dispatch_grid_charge' => [106], 'ctl_dispatch_soc_max' => [107]];
$f = (new DspHub())->GetFunctions();
ref_ok($f['contractVersion'] === '1.8', 'Vertrag 1.8');
ref_ok(is_array($f['dispatch']) && $f['dispatch']['modeID'] === 101 && $f['dispatch']['powerID'] === 102 && $f['dispatch']['availableID'] === 103 && $f['dispatch']['versionID'] === 104 && $f['dispatch']['statusID'] === 105 && $f['dispatch']['gridChargeID'] === 106 && $f['dispatch']['socMaxID'] === 107, 'dispatch: IDs von Modus, Leistung, Fähigkeit, Version, Status, Netzladen, SOC-Obergrenze');
ref_ok($f['dispatch']['socMinID'] === 0 && $f['dispatch']['failsafeMinID'] === 0 && $f['dispatch']['importLimitID'] === 0, 'fehlende Variablen: ID 0');
$GLOBALS['TREE'] = ['pv_total' => [101]];
$f = (new DspHub())->GetFunctions();
ref_ok($f['dispatch'] === [], 'ohne Dispatch-Variablen: leeres Feld');

echo "H) Schreibabstand\n";
$src = file_get_contents(dirname(__DIR__) . '/libs/IHUB_SolisDispatch.php');
ref_ok(preg_match('/public static \$spacingUs = (\d+);/', $src, $m) && (int)$m[1] >= 700000, 'Abstand zwischen zwei Schreibbefehlen im Code mindestens 700 ms (Doku), jetzt ' . ($m[1] ?? '?') . ' µs');

ref_finish('Solis Remote Dispatch');
