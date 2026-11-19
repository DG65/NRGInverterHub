<?php
/**
 * Gemeinsame Fernsteuerung (IHUB_RemoteControl) und ihre Adapter Solis, FoxESS, Deye (0.92.0): derselbe Vertragstest für jeden Adapter
 * gegen ein nachgebildetes Gerät je Hersteller.
 *   php .tools/test-remote-control.php     # 0 = alle Prüfungen bestanden
 *
 * Vertragsfälle je Adapter: Fähigkeit fehlt -> nichts geschrieben; nicht angebotener Modus oder Merkmal -> abgelehnt statt still ignoriert;
 * Vorgaben nur gemerkt, erst der Modus sendet; Schreibreihenfolge; Vorzeichen in beide Richtungen; Totmann gesetzt und zurückgelesen;
 * Gerät übernimmt nichts -> zweiter Versuch, Warnung, Anzeige bleibt; Schreibfehler stoppt die Reihenfolge; Modus 0; nie 0xFFFF-Sentinel;
 * Kennzeichnung (tested/vendor/modes) und Vertragsfeld remoteControl. Die Nachbildungen folgen den Herstellerdokumenten, nicht Messungen
 * (nur Solis ist am Gerät belegt).
 */
require_once __DIR__ . '/ref/harness.php';
IHUB_RemoteControl::$noWait = true;
IHUB_SolisDispatch::$spacingUs = 0; IHUB_SolisDispatch::$readbackUs = 0; IHUB_SolisDispatch::$retryPauseUs = 0;
IHUB_RemoteFoxEss::$gapUs = 0; IHUB_RemoteFoxEss::$readbackUs = 0; IHUB_RemoteFoxEss::$retryPauseUs = 0;
IHUB_RemoteDeye::$gapUs = 0; IHUB_RemoteDeye::$readbackUs = 0; IHUB_RemoteDeye::$retryPauseUs = 0;

/** Gemeinsame Grundlage der nachgebildeten Geräte: Schreiben speichert, einstellbare Fehler. */
class SimBase extends RefMb
{
    public $ignoreWrites = 0;     // so viele Schreibbefehle quittieren, aber nicht übernehmen
    public $ignoreRegs = [];      // Startadressen, deren Schreibbefehle quittiert, aber nicht übernommen werden
    public $available = true;
    public $order = [];           // Adressen der Schreibbefehle in Reihenfolge
    public function __construct() { parent::__construct(1); }
    protected function store(int $start, array $values, int $fc): void { foreach (array_values($values) as $i => $v) { $this->regs[3][$start + $i] = $v & 0xFFFF; } }
    protected function after(): void { }
    public function writeSingle($reg, $value)
    {
        $this->lastError = '';
        if (in_array((int)$reg, $this->failWrite, true)) { $this->lastError = 'no connection (simuliert)'; $this->writes[] = ['single-failed', (int)$reg, (int)$value]; return false; }
        $this->writes[] = ['single', (int)$reg, (int)$value]; $this->order[] = (int)$reg;
        if ($this->ignoreWrites > 0) { $this->ignoreWrites--; return true; }
        if (in_array((int)$reg, $this->ignoreRegs, true)) { return true; }
        $this->store((int)$reg, [(int)$value], 6); $this->after(); return true;
    }
    public function writeMultiple($start, $values)
    {
        $this->lastError = '';
        if (in_array((int)$start, $this->failWrite, true)) { $this->lastError = 'no connection (simuliert)'; $this->writes[] = ['multi-failed', (int)$start, array_values($values)]; return false; }
        $this->writes[] = ['multi', (int)$start, array_map('intval', array_values($values))]; $this->order[] = (int)$start;
        if ($this->ignoreWrites > 0) { $this->ignoreWrites--; return true; }
        if (in_array((int)$start, $this->ignoreRegs, true)) { return true; }
        $this->store((int)$start, $values, 16); $this->after(); return true;
    }
    public function regs(int $a, int $n = 1): array { $o = []; for ($i = 0; $i < $n; $i++) { $o[] = $this->regs[3][$a + $i] ?? 0; } return $o; }
}

class SimSolis extends SimBase
{
    public function __construct() { parent::__construct(); for ($a = 44100; $a <= 44110; $a++) { $this->regs[3][$a] = 0; } $this->after(); }
    protected function after(): void
    {
        $on = ($this->regs[3][44100] ?? 0) === 1;
        $this->regs[4][34502] = $this->available ? 0xAA55 : 0; $this->regs[4][34503] = $this->available ? 1 : 0;
        $this->regs[4][34504] = $on ? (in_array($this->regs[3][44105] ?? 1, [1, 3], true) ? 1 : 2) : 0;
    }
    protected function store(int $start, array $values, int $fc): void
    {
        $values = array_values($values);
        if ($start === 44100 && count($values) === 1) { $this->regs[3][44100] = $values[0] & 0xFFFF; return; }
        if ($start === 44100) { if (in_array(0xFFFF, $values, true)) { return; } parent::store($start, $values, $fc); return; }
        if ($start === 44105 && ($this->regs[3][44100] ?? 0) === 1) { parent::store($start, $values, $fc); }
    }
}

class SimFox extends SimBase
{
    public $countdownZero = false;   // 46007 bleibt 0, obwohl die Register stehen
    public function __construct() { parent::__construct(); for ($a = 46001; $a <= 46007; $a++) { $this->regs[3][$a] = 0; } }
    public function readHolding($start, $count) { if (!$this->available) { $this->reads[] = [3, (int)$start, (int)$count, 1]; return null; } return parent::readHolding($start, $count); }
    protected function after(): void { $this->regs[3][46007] = ((($this->regs[3][46001] ?? 0) & 1) && !$this->countdownZero) ? ($this->regs[3][46002] ?? 0) : 0; }
}

class SimDeye extends SimBase
{
    public $notAvailable = false;   // 1100 liefert 0x0500
    public function __construct()
    {
        parent::__construct();
        for ($a = 1100; $a <= 1110; $a++) { $this->regs[3][$a] = 0; }
        $this->regs[3][1101] = 0xFFFF;                 // Werkseinstellung: Watchdog aus
        $this->regs[3][20] = 34464; $this->regs[3][21] = 1;   // 100000 (0,1 W) = 10 kW
    }
    public function readHolding($start, $count)
    {
        if ($this->notAvailable && (int)$start === 1100) { $this->reads[] = [3, 1100, (int)$count, 1]; return array_fill(0, (int)$count, 0x0500); }
        return parent::readHolding($start, $count);
    }
}

function hubFor(array $seed = [], bool $control = true) { $h = new RefHub(['GroupControl' => $control]); $h->seed = $seed; return $h; }
function u32w(int $v): array { $u = $v < 0 ? $v + 4294967296 : $v; return [($u >> 16) & 0xFFFF, $u & 0xFFFF]; }

$solis = new IHUB_SolisDriver(); $fox = new IHUB_FoxEssSmartDriver(); $deye = new IHUB_DeyeDriver();
$adapters = [
    'Solis' => ['drv' => $solis, 'sim' => fn() => new SimSolis(), 'adapter' => new IHUB_RemoteSolis(), 'modes' => [0, 1, 2, 3], 'tested' => true, 'rated' => 0],
    'FoxESS' => ['drv' => $fox, 'sim' => fn() => new SimFox(), 'adapter' => new IHUB_RemoteFoxEss(), 'modes' => [0, 2, 3], 'tested' => false, 'rated' => 0],
    'Deye' => ['drv' => $deye, 'sim' => fn() => new SimDeye(), 'adapter' => new IHUB_RemoteDeye(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 10000],
];
$all = [0, 1, 2, 3];

foreach ($adapters as $name => $A) {
    echo "\n== $name: Vertragstests ==\n";
    $drv = $A['drv']; $mk = $A['sim']; $ad = $A['adapter']; $info = $ad->info();
    $seedOn = ['rc_version' => 1, 'rc_rated_w' => $A['rated']];

    // Kennzeichnung
    ref_ok($info['tested'] === $A['tested'] && $info['modes'] === $A['modes'] && $info['vendor'] === $name && $info['source'] !== '' && is_array($info['pitfalls']), "$name: Kennzeichnung (tested " . var_export($A['tested'], true) . ', Modi ' . implode(',', $A['modes']) . ', Dokumentquelle, Fallen)');
    $mb = $mk(); $hub = hubFor();
    $drv->readSlow($mb, $hub);
    ref_ok(($hub->vars['rc_available'][1] ?? null) === true && ($hub->vars['rc_version'][1] ?? 0) >= 1 && ($hub->vars['rc_tested'][1] ?? null) === $A['tested'] && ($hub->vars['rc_modes'][1] ?? '') === implode(',', $A['modes']) && strpos($hub->vars['rc_vendor'][1] ?? '', $name) === 0, "$name: langsamer Zyklus meldet Fähigkeit, Version, tested, Modi, Hersteller");
    if ($name === 'Deye') { ref_ok(($hub->vars['rc_rated_w'][1] ?? 0) === 10000, 'Deye: Nennleistung aus Register 20/21 (0,1 W) = 10000 W'); }

    // Fähigkeit fehlt
    $mb = $mk(); $mb->available = false; if ($name === 'Deye') { $mb->notAvailable = true; } if ($name === 'Solis') { $mb->regs[4][34502] = 0; }
    $hub = hubFor();
    $drv->writeControl($mb, $hub, 'ctl_rc_mode', 2);
    ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), "$name: ohne Fähigkeit nichts geschrieben, Warnung, Modus bleibt");
    $mb = $mk(); $mb->silent = true; $hub = hubFor();
    $drv->writeControl($mb, $hub, 'ctl_rc_mode', 2);
    ref_ok($mb->writes === [] && count($hub->warn) === 1, "$name: Fähigkeitsabfrage ohne Antwort: nichts geschrieben, Warnung");

    // nicht angebotene Modi
    foreach (array_diff($all, $A['modes']) as $m) {
        $mb = $mk(); $hub = hubFor($seedOn);
        $drv->writeControl($mb, $hub, 'ctl_rc_mode', $m);
        ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), "$name: Modus $m nicht angeboten: abgelehnt, nichts geschrieben");
    }
    $mb = $mk(); $hub = hubFor($seedOn); $drv->writeControl($mb, $hub, 'ctl_rc_mode', 9);
    ref_ok($mb->writes === [] && count($hub->warn) === 1, "$name: Modus 9 ungültig: abgelehnt");

    // nicht unterstützte Merkmale
    $hub = hubFor($seedOn); $mb = $mk();
    $drv->writeControl($mb, $hub, 'ctl_rc_grid_charge', 1); $drv->writeControl($mb, $hub, 'ctl_rc_soc_max', 80); $drv->writeControl($mb, $hub, 'ctl_rc_soc_min', 20);
    if ($info['gridCharge'] && $info['socWindow']) {
        ref_ok($hub->warn === [] && $hub->vars['ctl_rc_grid_charge'][1] === 1 && $hub->vars['ctl_rc_soc_max'][1] === 80 && $hub->vars['ctl_rc_soc_min'][1] === 20, "$name: Netzladen und SOC-Fenster werden gemerkt");
    } else {
        ref_ok(count($hub->warn) === 3 && !isset($hub->vars['ctl_rc_grid_charge']) && !isset($hub->vars['ctl_rc_soc_max']), "$name: Netzladen und SOC-Fenster nicht unterstützt: abgelehnt statt still ignoriert (3 Warnungen)");
        $hub = hubFor($seedOn); $drv->writeControl($mb, $hub, 'ctl_rc_soc_max', 100); $drv->writeControl($mb, $hub, 'ctl_rc_soc_min', 0); $drv->writeControl($mb, $hub, 'ctl_rc_grid_charge', 0);
        ref_ok($hub->warn === [], "$name: Vorgaben ohne Wirkung (0, 100, 0) werden angenommen");
    }

    // Vorgaben nur gemerkt
    $mb = $mk(); $hub = hubFor($seedOn);
    $drv->writeControl($mb, $hub, 'ctl_rc_w', 1234); $drv->writeControl($mb, $hub, 'ctl_rc_failsafe_sec', 120);
    ref_ok($mb->writes === [] && $hub->vars['ctl_rc_w'][1] === 1230 && $hub->vars['ctl_rc_failsafe_sec'][1] === 120, "$name: Leistung (auf 10 W gerundet) und Totmann werden nur gemerkt");
    $mb = $mk(); $hub = hubFor(); $drv->writeControl($mb, $hub, 'ctl_rc_w', 99999999); $drv->writeControl($mb, $hub, 'ctl_rc_w', -99999999);
    ref_ok($hub->vars['ctl_rc_w'][1] === -40000, "$name: Leistung auf ±40000 W begrenzt");

    // Fähigkeit wird vor dem ersten Zyklus selbst abgefragt
    $mb = $mk(); $hub = hubFor(['rc_rated_w' => $A['rated'], 'ctl_rc_w' => 0]);
    $drv->writeControl($mb, $hub, 'ctl_rc_mode', 2);
    ref_ok($hub->warn === [] && ($hub->vars['ctl_rc_mode'][1] ?? null) === 2, "$name: Fähigkeit noch unbekannt (Version 0): wird beim Schreiben einmal abgefragt, Modus 2 angenommen");
}

echo "\n== Adapter: Prüfung der Anfragen ==\n";
foreach ([['Solis', new IHUB_RemoteSolis(), 7, 1], ['FoxESS', new IHUB_RemoteFoxEss(), 1, 3], ['Deye', new IHUB_RemoteDeye(), 3, 3]] as [$n, $a, $badMode, $x]) {
    $cap = ['available' => true, 'version' => 1, 'status' => 0, 'ratedW' => 10000];
    ref_ok($a->validate(['mode' => $badMode, 'watts' => 0, 'socMin' => 0, 'socMax' => 0, 'failsafeSec' => 0, 'ext' => []], $cap) !== '', "$n: Adapter lehnt Modus $badMode selbst ab");
    ref_ok($a->validate(['mode' => 2, 'watts' => 0, 'socMin' => 0, 'socMax' => 0, 'failsafeSec' => 0, 'ext' => []], $cap) === '', "$n: Modus 2 wird angenommen");
}

echo "\n== Solis: Schreibfolge und Vorzeichen (gemeinsame Schicht = alter Weg) ==\n";
$mb = new SimSolis(); $hub = hubFor($seedOn = ['rc_version' => 1, 'ctl_rc_w' => -2500, 'ctl_rc_failsafe_sec' => 120]);
$solis->writeControl($mb, $hub, 'ctl_rc_mode', 2);
$hubF = hubFor(['rc_version' => 1, 'ctl_rc_w' => 100, 'ctl_rc_failsafe_sec' => 90]); $mbF = new SimSolis(); $solis->writeControl($mbF, $hubF, 'ctl_rc_mode', 2);
ref_ok($mbF->writes[0][2][1] === 2 && $hubF->vars['rc_failsafe_eff_sec'][1] === 120, 'Solis: Totmann 90 s wird auf volle Minuten aufgerundet (2 min), im Gerät und in rc_failsafe_eff_sec 120 s');
ref_ok($mb->order === [44100, 44105] && $mb->writes[0][2] === [1, 2, 0, 0, 0] && $mb->writes[1][2] === [2, 0xFFFF, 0xFF06, 0, 0, 100], 'Solis: System 44100-44104 vor Echtzeit 44105-44110, −2500 W = S32 −250, Totmann 120 s = 2 min');
ref_ok($hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 2 && $hub->vars['rc_failsafe_eff_sec'][1] === 120 && $hub->vars['rc_active'][1] === true, 'Solis: Modus gemerkt, Totmann im Gerät 120 s gemeldet, aktiv');
$mb = new SimSolis(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => -2100]); $solis->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($hub->warn === [] && in_array([3, 0xFFFF, 0xFF2E, 0, 0, 100], [array_slice($mb->writes[1][2], 0, 6)], true), 'Solis: Modus 3 −2100 W = S32 −210 (Netz −Bezug), Status 1 ist kein Fehlschlag');
$mb = new SimSolis(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $solis->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [['multi', 44100, [0]]] && $hub->vars['ctl_rc_mode'][1] === 0 && $hub->vars['rc_failsafe_eff_sec'][1] === 0, 'Solis: Modus 0 schreibt nur 44100 := 0');
$mb = new SimSolis(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 3000, 'ctl_rc_grid_charge' => 1, 'ctl_rc_soc_min' => 10, 'ctl_rc_soc_max' => 90]); $solis->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[1][2] === [2, 0, 300, 0x10, 10, 90], 'Solis: Netzladen erlaubt (0x10) und SOC-Fenster 10..90 gehen mit');
$mb = new SimSolis(); $mb->ignoreWrites = 99; $hub = hubFor(['rc_version' => 1]); $solis->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']) && count($mb->writes) === 4, 'Solis: Gerät übernimmt nichts: zweiter Gesamtversuch (4 Schreibbefehle), Warnung, Anzeige bleibt');
$mb = new SimSolis(); $mb->failWrite = [44105]; $hub = hubFor(['rc_version' => 1]); $solis->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), 'Solis: Echtzeitblock scheitert: Warnung, Modus bleibt');

echo "\n== FoxESS: Register, Vorzeichen, Totmann ==\n";
$mb = new SimFox(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000, 'ctl_rc_failsafe_sec' => 90]);
$fox->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [46002, 46001, 46003], 'FoxESS: Reihenfolge Timeout (46002), Enable (46001), Leistung (46003)');
ref_ok($mb->writes[0] === ['single', 46002, 90] && $mb->writes[1] === ['single', 46001, 5] && $mb->writes[2] === ['multi', 46003, u32w(-2000)], 'FoxESS: Modus 2 +2000 W (Laden): 46002 = 90 s, 46001 = 0b0101 (Enable, Ziel Akku, Erzeugersystem), Leistung I32 −2000 W; Timeout und Enable mit FC06, Leistung mit FC16');
ref_ok($hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 2 && $hub->vars['rc_failsafe_eff_sec'][1] === 90 && $hub->vars['rc_status'][1] === 90 && $hub->vars['rc_active'][1] === true, 'FoxESS: übernommen, Totmann 90 s, Restzeit 46007 = 90, aktiv');
$mb = new SimFox(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => -3000]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[2] === ['multi', 46003, u32w(3000)] && $mb->writes[0][2] === 60, 'FoxESS: Modus 2 −3000 W (Entladen) = +3000 W; Totmann 0 = Vorgabe 60 s');
$mb = new SimFox(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1500]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->writes[1] === ['single', 46001, 9] && $mb->writes[2] === ['multi', 46003, u32w(1500)], 'FoxESS: Modus 3 +1500 W (Einspeisung): 46001 = 0b1001 (Ziel Netz), Leistung +1500 W');
$mb = new SimFox(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => -1500]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->writes[2] === ['multi', 46003, u32w(-1500)], 'FoxESS: Modus 3 −1500 W (Bezug) = −1500 W');
$mb = new SimFox(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 0]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [] && $mb->reads === [] && $hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 0, 'FoxESS: Modus 0 ohne eigene Fernsteuerung: nichts geschrieben, nichts gelesen (die App-Strategien nutzen dasselbe Register), kein Fehler');
$mb = new SimFox(); $mb->regs[3][46001] = 5; $mb->regs[3][46002] = 60; $mb->regs[3][46007] = 40; $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [['single', 46001, 0]] && $hub->vars['ctl_rc_mode'][1] === 0, 'FoxESS: Modus 0 nach eigener Fernsteuerung: 46001 := 0');
$mb = new SimFox(); $mb->ignoreWrites = 99; $hub = hubFor(['rc_version' => 1]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']) && count($mb->writes) === 6, 'FoxESS: Gerät übernimmt nichts: zwei Gesamtversuche, Warnung, Anzeige bleibt');
$mb = new SimFox(); $mb->failWrite = [46001]; $hub = hubFor(['rc_version' => 1]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && !in_array(46003, $mb->order, true), 'FoxESS: Enable scheitert: die Leistung wird nicht mehr geschrieben');
$mb = new SimFox(); $mb->countdownZero = true; $hub = hubFor(['rc_version' => 1]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '46007') !== false && !isset($hub->vars['ctl_rc_mode']), 'FoxESS: Register stehen, Restzeit 46007 bleibt 0: Warnung, Modus bleibt');
$mb = new SimFox(); $mb->ignoreRegs = [46003]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000]); $fox->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '46003') !== false && !isset($hub->vars['ctl_rc_mode']), 'FoxESS: nur die Leistung wird nicht übernommen: Warnung nennt Register 46003');
$mb = new SimFox(); $mb->available = false; $hub = hubFor(); $fox->readSlow($mb, $hub);
ref_ok(!isset($hub->vars['rc_available']), 'FoxESS: ohne Antwort auf 46001 bleiben die Variablen unverändert');

echo "\n== Deye: Register, Skalierung, Watchdog ==\n";
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 2000, 'ctl_rc_failsafe_sec' => 90]);
$deye->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [1101, 1104, 1109, 1100], 'Deye: Reihenfolge Watchdog (1101), Regelziel/-art (1104/1105), Leistung (1109), Fernmodus (1100) zuletzt');
ref_ok($mb->writes[0][2] === [90] && $mb->writes[1][2] === [1, 2] && $mb->writes[2][2] === [(-200) & 0xFFFF] && $mb->writes[3][2] === [1], 'Deye: Watchdog 90 s, 1104 = 1 (akkuseitig), 1105 = 2 (Leistung), 2000 W an 10 kW = 200 in 0,1 % (Laden negativ), 1100 = 1');
ref_ok(!in_array('single', array_column($mb->writes, 0), true), 'Deye: nur FC16 (FC06 ist nicht freigegeben)');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 90 && $hub->vars['rc_status'][1] === 1 && $hub->vars['rc_active'][1] === true, 'Deye: übernommen, Watchdog 90 s, Fernmodus aktiv');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => -3000]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[2][2] === [300] && $mb->writes[0][2] === [60], 'Deye: −3000 W (Entladen) = +300 (0,1 %); Watchdog 0 = Vorgabe 60 s');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 40000, 'ctl_rc_w' => 40000]);
$deye->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[2][2] === [(-1000) & 0xFFFF], 'Deye: 40000 W an einem 40-kW-Gerät = 100 % = −1000 in 0,1 %');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 1000, 'ctl_rc_w' => 40000]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[2][2] === [(-1200) & 0xFFFF], 'Deye: Leistung über 120 % der Nennleistung auf −1200 begrenzt');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000]);
$mb->regs[3][20] = 0; $mb->regs[3][21] = 0;
$deye->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), 'Deye: Nennleistung unbekannt: Modus 2 nicht geschrieben (keine Umrechnung möglich), Warnung');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 5000]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[2][2] === [0] && $hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 1, 'Deye: Halten schreibt 0 in 1109, auch bei gemerkter Leistung (Nennleistung bekannt)');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_failsafe_sec' => 5]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[0][2] === [10], 'Deye: Watchdog 5 s auf 10 s (Untergrenze) angehoben, nie 0xFFFF');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_failsafe_sec' => 99999]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[0][2] === [18000], 'Deye: Watchdog auf 18000 s begrenzt');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [['multi', 1100, [0]]] && $hub->vars['ctl_rc_mode'][1] === 0, 'Deye: Modus 0 schreibt nur 1100 := 0');
$mb = new SimDeye(); $mb->notAvailable = true; $hub = hubFor(); $deye->readSlow($mb, $hub);
ref_ok($hub->vars['rc_available'][1] === false && $hub->vars['rc_version'][1] === 0, 'Deye: 1100 liest 0x0500: Fernmodus nicht verfügbar');
$mb = new SimDeye(); $mb->ignoreWrites = 99; $hub = hubFor(['rc_version' => 1]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']) && count($mb->writes) === 8, 'Deye: Gerät übernimmt nichts: zwei Gesamtversuche (8 Schreibbefehle), Warnung, Anzeige bleibt');
$mb = new SimDeye(); $mb->ignoreRegs = [1109]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 2000]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '1109') !== false && !isset($hub->vars['ctl_rc_mode']), 'Deye: nur die Leistung wird nicht übernommen: Warnung nennt Register 1109');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1]); $r = $deye->readSlow($mb, $hub);
ref_ok(IHUB_RemoteDeye::devicePower(1, 5000, 10000) === 0 && IHUB_RemoteDeye::devicePower(2, 0, 10000) === 0 && IHUB_RemoteDeye::devicePower(2, 1000, 10000) === ((-100) & 0xFFFF), 'Deye: devicePower je Modus (Halten 0, Laden negativ)');
$mb = new SimDeye(); $mb->failWrite = [1104]; $hub = hubFor(['rc_version' => 1]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(count($hub->warn) === 1 && !in_array(1100, $mb->order, true), 'Deye: Regelziel scheitert: der Fernmodus wird nicht mehr eingeschaltet');
$mb = new SimDeye(); $hub = hubFor(['rc_version' => 1]); $deye->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(($mb->regs[3][1101] ?? 0xFFFF) === 60, 'Deye: der Watchdog 1101 steht im Gerät (nicht mehr 0xFFFF = aus) und wird zurückgelesen');

echo "\n== Vertrag IHUB_GetFunctions 1.9: remoteControl ==\n";
$GLOBALS['TREE'] = [];
function IPS_GetChildrenIDs($p) { return $p === 1 ? array_values(array_map(function ($v) { return $v[0]; }, $GLOBALS['TREE'])) : []; }
function IPS_GetObject($id) { foreach ($GLOBALS['TREE'] as $ident => $v) { if ($v[0] === $id) { return ['ObjectIdent' => $ident, 'ObjectType' => 2]; } } return ['ObjectIdent' => '', 'ObjectType' => 2]; }
function IPS_ObjectExists($id) { return true; }
class RcHub extends InverterHub
{
    public $maker = 'solis';
    public function __construct() { $this->InstanceID = 1; }
    public function ReadPropertyString($n) { return $n === 'Manufacturer' ? $this->maker : 'ems'; }
    public function ReadPropertyBoolean($n) { return false; }
    public function ReadPropertyInteger($n) { return 0; }
}
foreach (['solis' => $solis, 'foxess_smart' => $fox, 'deye' => $deye] as $maker => $drv) {
    $idents = array_column($drv->getOptionalGroups()['GroupControl']['vars'], 0);
    $GLOBALS['TREE'] = []; $i = 100; foreach (IHUB_RemoteControl::vars() as $v) { if (in_array($v[0], $idents, true)) { $GLOBALS['TREE'][$v[0]] = [$i++]; } }
    $h = new RcHub(); $h->maker = $maker; $f = $h->GetFunctions();
    $rc = $f['remoteControl'];
    ref_ok($f['contractVersion'] === '1.9' && $rc['modeID'] === $GLOBALS['TREE']['ctl_rc_mode'][0] && $rc['powerID'] === $GLOBALS['TREE']['ctl_rc_w'][0] && $rc['availableID'] === $GLOBALS['TREE']['rc_available'][0] && $rc['testedID'] === $GLOBALS['TREE']['rc_tested'][0] && $rc['vendorID'] === $GLOBALS['TREE']['rc_vendor'][0] && $rc['modesID'] === $GLOBALS['TREE']['rc_modes'][0] && $rc['failsafeSecID'] === $GLOBALS['TREE']['ctl_rc_failsafe_sec'][0] && $f['controllable'] === true, "$maker: remoteControl mit allen IDs, Vertrag 1.9, controllable");
}
$GLOBALS['TREE'] = ['pv_total' => [101]]; $h = new RcHub(); $f = $h->GetFunctions();
ref_ok($f['remoteControl'] === [], 'Treiber ohne Fernsteuerung: leeres Feld');
$missing = [];
foreach (['solis' => $solis, 'foxess_smart' => $fox, 'deye' => $deye] as $maker => $drv) {
    $prof = array_merge(array_keys($drv->getProfiles()), array_keys($drv->getEnumProfiles()));
    foreach ($drv->getOptionalGroups()['GroupControl']['vars'] as $v) { if ($v[3] !== '' && $v[3][0] !== '~' && !in_array($v[3], $prof, true)) { $missing[] = "$maker:" . $v[0] . '=' . $v[3]; } }
}
ref_ok($missing === [], 'jedes benutzte Profil der Fernsteuerung ist je Treiber definiert' . ($missing ? ': ' . implode(',', $missing) : ''));
$fox2 = (new IHUB_FoxEssSmartDriver())->getOptionalGroups();
ref_ok(isset($fox2['GroupControl']) && count(array_column($fox2['GroupControl']['vars'], 0)) === count(IHUB_RemoteControl::vars()), 'FoxESS: eigene Gruppe Steuerung mit genau den gemeinsamen Variablen (keine weiteren Schreibwege)');
ref_ok(($fox2['GroupControl']['default'] ?? true) === false && strpos(file_get_contents(dirname(__DIR__) . '/InverterHub/module.php'), "\$group['default'] ?? true") !== false, 'FoxESS: Gruppe Steuerung startet aus (ungetestet), das Hauptmodul wertet default aus; alle anderen Gruppen weiter an');

ref_finish('gemeinsame Fernsteuerung');
