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
IHUB_RemoteSolax::$gapUs = 0; IHUB_RemoteSolax::$readbackUs = 0; IHUB_RemoteSolax::$retryPauseUs = 0;
IHUB_RemoteSma::$gapUs = 0; IHUB_RemoteSma::$readbackUs = 0; IHUB_RemoteSma::$retryPauseUs = 0;

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

class SimSolax extends SimBase
{
    public function __construct() { parent::__construct(); for ($a = 0x1A0; $a <= 0x1A7; $a++) { $this->regs[3][$a] = 0; } }
    public function readHolding($start, $count) { if (!$this->available && (int)$start === 0x1A0) { $this->reads[] = [3, 0x1A0, (int)$count, 1]; return null; } return parent::readHolding($start, $count); }
    protected function store(int $start, array $values, int $fc): void
    {
        $values = array_values($values);
        if ($start === 0xA0) { foreach ($values as $i => $v) { $this->regs[3][0x1A0 + $i] = $v & 0xFFFF; } return; }   // Schreiben 0xA0 ff. erscheint als Rücklesen 0x1A0 ff.
        parent::store($start, $values, $fc);   // 0x7C ff. (alte Schnittstelle) nur speichern
    }
}

/** SMA: Konfiguration (RW) liegt in den Eingangsregistern, die Sollwerte (WO) lesen sich als NaN; je Geräteklasse andere Vorgaben. */
class SimSma extends SimBase
{
    public $setpoints = [];
    public function __construct(string $family = 'se', bool $nominal = true)
    {
        parent::__construct();
        $ext = ($family === 'si') ? 1079 : 303;          // Sunny Island: vom Installateur vorbelegt (Grid Guard)
        foreach ([40210 => $ext, 41193 => 2506, 41195 => 600, 44029 => 308, 40075 => 1129] as $a => $v) { $this->regs[4][$a] = 0; $this->regs[4][$a + 1] = $v; }
        if ($nominal) { $this->regs[4][33019] = 0xFFFF; $this->regs[4][33020] = 0xD8F0; }   // −10000 W
    }
    public function readInput($start, $count) { if (!$this->available && (int)$start === 40210) { $this->reads[] = [4, 40210, (int)$count, (int)$this->unitId]; return null; } return parent::readInput($start, $count); }
    protected function store(int $start, array $values, int $fc): void
    {
        $values = array_values($values);
        if (in_array($start, [40149, 44039, 44449], true)) { $this->setpoints[$start] = $values; return; }
        foreach ($values as $i => $v) { $this->regs[4][$start + $i] = $v & 0xFFFF; }
    }
    public function cfg(int $a): int { return (($this->regs[4][$a] ?? 0) << 16) | ($this->regs[4][$a + 1] ?? 0); }
}

/** Treiber mit fest gewählter Geräteklasse (für die Vertragstests; SmaControlType wird gesondert geprüft). */
class SmaDrvFamily extends IHUB_SmaDriver
{
    public $fam = 'se';
    protected function remoteAdapter($hub): IHUB_RemoteSma { return new IHUB_RemoteSma($this->fam); }
}

function hubFor(array $seed = [], bool $control = true) { $h = new RefHub(['GroupControl' => $control]); $h->seed = $seed; return $h; }
function u32w(int $v): array { $u = $v < 0 ? $v + 4294967296 : $v; return [($u >> 16) & 0xFFFF, $u & 0xFFFF]; }

$solis = new IHUB_SolisDriver(); $fox = new IHUB_FoxEssSmartDriver(); $deye = new IHUB_DeyeDriver(); $solax = new IHUB_SolaxDriver();
$smaSe = new SmaDrvFamily(); $smaSi = new SmaDrvFamily(); $smaSi->fam = 'si'; $smaSx = new SmaDrvFamily(); $smaSx->fam = 'stpsx';
$adapters = [
    'Solis' => ['drv' => $solis, 'sim' => fn() => new SimSolis(), 'adapter' => new IHUB_RemoteSolis(), 'modes' => [0, 1, 2, 3], 'tested' => true, 'rated' => 0],
    'FoxESS' => ['drv' => $fox, 'sim' => fn() => new SimFox(), 'adapter' => new IHUB_RemoteFoxEss(), 'modes' => [0, 2, 3], 'tested' => false, 'rated' => 0],
    'Deye' => ['drv' => $deye, 'sim' => fn() => new SimDeye(), 'adapter' => new IHUB_RemoteDeye(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 10000],
    'SolaX' => ['drv' => $solax, 'sim' => fn() => new SimSolax(), 'adapter' => new IHUB_RemoteSolax(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 0],
    'SMA STP SE' => ['drv' => $smaSe, 'sim' => fn() => new SimSma('se'), 'adapter' => new IHUB_RemoteSma('se'), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 0],
    'SMA Sunny Island' => ['drv' => $smaSi, 'sim' => fn() => new SimSma('si'), 'adapter' => new IHUB_RemoteSma('si'), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 10000],
    'SMA STPS X' => ['drv' => $smaSx, 'sim' => fn() => new SimSma('stpsx'), 'adapter' => new IHUB_RemoteSma('stpsx'), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 10000],
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
foreach ([['Solis', new IHUB_RemoteSolis(), 7, 1], ['FoxESS', new IHUB_RemoteFoxEss(), 1, 3], ['Deye', new IHUB_RemoteDeye(), 3, 3], ['SolaX', new IHUB_RemoteSolax(), 3, 3], ['SMA', new IHUB_RemoteSma('se'), 3, 3], ['SMA Sunny Island', new IHUB_RemoteSma('si'), 3, 3], ['SMA STPS X', new IHUB_RemoteSma('stpsx'), 3, 3]] as [$n, $a, $badMode, $x]) {
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

echo "\n== SolaX: Register, Vorzeichen, Totmann ==\n";
$mb = new SimSolax(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000, 'ctl_rc_failsafe_sec' => 90]);
$solax->writeControl($mb, $hub, 'ctl_rc_mode', 2);
$bat = IHUB_RemoteSolax::encodeS32(-2000); $pv = IHUB_RemoteSolax::encodeS32(100000);
ref_ok($mb->writes === [['multi', 0xA0, [8, 1, $pv[0], $pv[1], $bat[0], $bat[1], 90, 0xA0]]], 'SolaX: ein FC16-Block 0xA0-0xA7: Modus 8, ExecMode 1, PV-Grenze 100000 W, Akku +2000 W Laden = −2000 (Entladen +), WaitTimeout 90 s (0xA6), NextMotion 0xA0 (0xA7)');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 90 && $hub->vars['rc_active'][1] === true && $hub->vars['rc_status'][1] === 8, 'SolaX: übernommen, Totmann im Gerät = WaitTimeout 90 s (0xA6), aktiv (Rohstatus 8)');
ref_ok($bat === [0xF830, 0xFFFF] && IHUB_RemoteSolax::decodeS32(0xF830, 0xFFFF) === -2000, 'SolaX: S32 niederwertiges Wort zuerst (−2000 = 0xFFFFF830)');
$mb = new SimSolax(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => -1500]); $solax->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[0][2][4] === 1500 && $mb->writes[0][2][5] === 0 && $mb->writes[0][2][6] === 60, 'SolaX: −1500 W (Entladen) = +1500 fürs Gerät; WaitTimeout 0 = Vorgabe 60 s');
$mb = new SimSolax(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 4000]); $solax->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[0][2][4] === 0 && $mb->writes[0][2][5] === 0 && $hub->warn === [], 'SolaX: Halten schreibt Akkuleistung 0, auch bei gemerkter Leistung');
$mb = new SimSolax(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_failsafe_sec' => 3]); $solax->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[0][2][6] === 10, 'SolaX: WaitTimeout 3 s auf 10 s angehoben');
$mb = new SimSolax(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_failsafe_sec' => 65535]); $solax->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[0][2][6] === 60000, 'SolaX: WaitTimeout auf 60000 s begrenzt');
$mb = new SimSolax(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $solax->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->order === [0xA0, 0x7C] && $mb->writes[0][2] === [0] && $mb->writes[1][2] === array_fill(0, 13, 0) && $hub->vars['ctl_rc_mode'][1] === 0 && $hub->vars['rc_failsafe_eff_sec'][1] === 0, 'SolaX: Modus 0 schreibt 0xA0 := 0 und 13 Nullen ab 0x7C');
$mb = new SimSolax(); $mb->ignoreRegs = [0xA0]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $solax->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']) && count($mb->writes) === 2, 'SolaX: Gerät übernimmt den Block nicht: zweiter Versuch, Warnung, Modus bleibt');
$mb = new SimSolax(); $hub = hubFor(['rc_version' => 1]); $solax->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(!in_array('single', array_column($mb->writes, 0), true), 'SolaX: nur FC16-Blöcke');
$mb = new SimSolax(); $mb->available = false; $hub = hubFor(); $solax->readSlow($mb, $hub);
ref_ok(!isset($hub->vars['rc_available']), 'SolaX: ohne Antwort auf 0x01A0 (Gen2/Gen3, ältere Firmware) bleiben die Variablen unverändert');
$sg = $solax->getOptionalGroups();
ref_ok(isset($sg['GroupControl']) && ($sg['GroupControl']['default'] ?? true) === false, 'SolaX: Gruppe Steuerung startet aus (ungetestet)');


echo "\n== SMA: Register, Vorzeichen, Totmann, Flash-Schutz ==\n";
$neg = fn(int $pct) => IHUB_RemoteSma::words($pct);
// STP SE: Konfiguration (Gerät steht auf 40210 = 303, 41193 = 2506) wird gesetzt, dann der Sollwert 40149 + 40151
$mb = new SimSma('se'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000, 'ctl_rc_failsafe_sec' => 90]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [40210, 41193, 40149], 'SMA STP SE: Reihenfolge Betriebsart 40210, Ausfallverhalten und Timeout 41193, Sollwert 40149 zuletzt');
ref_ok($mb->writes[0][2] === [0, 1079] && $mb->writes[1][2] === [0, 2507, 0, 90] && $mb->writes[2][2] === array_merge(IHUB_RemoteSma::words(-2000), [0, 802]), 'SMA STP SE: 40210 = 1079, 41193 = 2507 mit Timeout 41195 = 90 s in einem Block, Sollwert +2000 W (Laden) = −2000 und 40151 = 802');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 90 && $hub->vars['rc_active'][1] === true && $mb->cfg(41193) === 2507 && $mb->cfg(41195) === 90, 'SMA STP SE: übernommen, Totmann 90 s, Werksvorgabe 2506 (Werte halten) auf 2507 umgestellt und zurückgelesen');
// zweiter Aufruf: Konfiguration steht bereits, nur noch der Sollwert (kein zyklisches Schreiben ins Flash)
$n = count($mb->writes); $hub->SetVarInt('ctl_rc_w', 500); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($mb->writes) === $n + 1 && $mb->writes[$n][1] === 40149 && $mb->writes[$n][2] === array_merge(IHUB_RemoteSma::words(-500), [0, 802]), 'SMA STP SE: zweiter Zyklus schreibt nur den Sollwert, die Konfiguration (Flash) wird nicht erneut geschrieben');
$mb = new SimSma('se'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => -1500]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[2][2] === array_merge(IHUB_RemoteSma::words(1500), [0, 802]) && $mb->writes[1][2][3] === 60, 'SMA STP SE: −1500 W (Entladen) = +1500; Totmann 0 = Vorgabe 60 s');
$mb = new SimSma('se'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 3000]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[2][2] === [0, 0, 0, 802], 'SMA STP SE: Halten = 0 W mit 802, auch bei gemerkter Leistung');
$mb = new SimSma('se'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_failsafe_sec' => 2]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[1][2][3] === 5, 'SMA STP SE: Timeout 2 s auf 5 s angehoben');
$mb = new SimSma('se'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_failsafe_sec' => 60000]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[1][2][3] === 1800, 'SMA STP SE: Timeout auf 1800 s begrenzt');
$mb = new SimSma('se'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [['multi', 40151, [0, 803]]] && $hub->vars['ctl_rc_mode'][1] === 0 && $hub->vars['rc_failsafe_eff_sec'][1] === 0, 'SMA STP SE: Modus 0 schreibt nur 40151 := 803 (inaktiv), die Konfiguration bleibt unberührt');
$mb = new SimSma('se'); $mb->ignoreRegs = [41193]; $hub = hubFor(['rc_version' => 1]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '41193') !== false && !isset($hub->vars['ctl_rc_mode']), 'SMA STP SE: Gerät übernimmt 41193 nicht (Totmann bliebe aus): Warnung nennt Register 41193, Modus bleibt');
$mb = new SimSma('se'); $mb->ignoreRegs = [40149]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 100]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 2, 'SMA STP SE: der Sollwert selbst lässt sich nicht zurücklesen (WO, NaN): nur die Konfiguration wird geprüft');
$mb = new SimSma('se'); $mb->noRead = [41193]; $hub = hubFor(['rc_version' => 1]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->order[0] === 40210 && in_array(41193, $mb->order, true) && count($hub->warn) === 1, 'SMA STP SE: Konfiguration nicht lesbar: sie wird (erster Zyklus) vollständig geschrieben, die Prüfung scheitert ehrlich mit Warnung');
$mb = new SimSma('se'); $mb->noRead = [41193]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 1]); $smaSe->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(!in_array(40210, $mb->order, true) && !in_array(41193, $mb->order, true) && in_array(40149, $mb->order, true), 'SMA STP SE: Konfiguration nicht lesbar, Fernsteuerung läuft schon: nur der Sollwert (kein Flash-Schreiben auf Verdacht)');

// STPS X: Fenster 44449/44451, beide zusammen, Prozent der Nennleistung 33019, Entladen positiv
$mb = new SimSma('stpsx'); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 2000, 'ctl_rc_failsafe_sec' => 10]); $smaSx->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [40210, 41193, 44029, 44449], 'SMA STPS X: Reihenfolge 40210, 41193/41195, Rampen 44029, Fenster 44449 zuletzt');
ref_ok($mb->writes[2][2] === [0, 303] && $mb->writes[3][2] === array_merge($neg(-2000), $neg(-2000)) && $mb->writes[1][2] === [0, 2507, 0, 10], 'SMA STPS X: 44029 = 303, +2000 W an 10 kW = 20 % Laden = −2000 (FIX2) in 44449 UND 44451 in einem Telegramm, Timeout 10 s');
$mb = new SimSma('stpsx'); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => -3500]); $smaSx->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[3][2] === array_merge($neg(3500), $neg(3500)), 'SMA STPS X: −3500 W (Entladen) = +35 % = 3500 (Beispiel der SMA-Doku)');
$mb = new SimSma('stpsx'); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 40000]); $smaSx->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[3][2] === array_merge($neg(-10000), $neg(-10000)), 'SMA STPS X: Leistung über der Nennleistung auf −100 % begrenzt');
$mb = new SimSma('stpsx'); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 4000]); $smaSx->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[3][2] === [0, 0, 0, 0] && $hub->warn === [], 'SMA STPS X: Halten = beide Grenzen 0 %');
$mb = new SimSma('stpsx'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $smaSx->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [['multi', 44449, array_merge($neg(10000), $neg(-10000))]] && $hub->vars['ctl_rc_mode'][1] === 0, 'SMA STPS X: Modus 0 öffnet das Fenster auf +100 % / −100 %');
$mb = new SimSma('stpsx', false); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $smaSx->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && strpos($hub->warn[0], '33019') !== false, 'SMA STPS X: Nennleistung 33019 unbekannt: Modus 2 nicht geschrieben, Warnung nennt das Register');
$mb = new SimSma('stpsx', false); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $smaSx->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 1, 'SMA STPS X: Halten braucht keine Nennleistung');

// Sunny Island: 40210 ist durch den Grid-Guard-Code geschützt und wird nie geschrieben
$mb = new SimSma('si'); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => -2500]); $smaSi->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [41193, 44029, 40075, 44039] && !in_array(40210, $mb->order, true), 'SMA Sunny Island: 40210 wird nie geschrieben (Grid Guard); Reihenfolge 41193/41195, 44029, Eigenverbrauch 40075, Fenster 44039');
ref_ok($mb->writes[2][2] === [0, 1130] && $mb->writes[3][2] === array_merge($neg(2500), $neg(2500)) && $hub->warn === [], 'SMA Sunny Island: 40075 = 1130 (Eigenverbrauchserhöhung aus), −2500 W (Entladen) = +25 % in 44039 und 44041');
$mb = new SimSma('si'); $mb->regs[4][40211] = 303; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000]); $smaSi->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && strpos($hub->warn[0], '1079') !== false && strpos($hub->warn[0], 'Grid Guard') !== false, 'SMA Sunny Island: 40210 steht nicht auf 1079: nichts geschrieben, Warnung nennt Grid Guard');
$mb = new SimSma('si'); $mb->regs[4][40076] = 1130; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_mode' => 2]); $smaSi->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->order === [44039, 40075] && $mb->writes[0][2] === array_merge($neg(10000), $neg(-10000)) && $mb->writes[1][2] === [0, 1129], 'SMA Sunny Island: Modus 0 öffnet das Fenster und schaltet die Eigenverbrauchserhöhung wieder ein (sonst lädt er nach dem Timeout voll)');
$mb = new SimSma('si'); $mb->regs[4][40211] = 303; $mb->regs[4][40076] = 1129; $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $smaSi->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($hub->warn === [] && $mb->order === [44039], 'SMA Sunny Island: Modus 0 mit Eigenverbrauch schon an: 40075 wird nicht erneut geschrieben');

$p = (new IHUB_RemoteSma('si'))->plan(['mode' => 1, 'watts' => 0, 'failsafeSec' => 60], ['available' => true, 'ratedW' => 10000, 'cfg' => ['mode' => 303, 'fallback' => 2506, 'timeout' => 600, 'ramp' => 308, 'self' => 1129]], 0);
ref_ok(!in_array(40210, array_column($p['steps'], 'addr'), true) && in_array(41193, array_column($p['steps'], 'addr'), true), 'SMA Sunny Island: auch im Plan wird 40210 nie geschrieben (Grid Guard), selbst wenn es abweicht');
$mb = new SimSma('si'); $mb->ignoreRegs = [40075]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000]); $smaSi->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '40075') !== false && !isset($hub->vars['ctl_rc_mode']), 'SMA Sunny Island: Gerät übernimmt 40075 (Eigenverbrauch aus) nicht: Warnung nennt Register 40075');

// Geräteklasse aus der Instanzeigenschaft, Unit-ID des Geräts, Kennzeichnung
ref_ok(IHUB_RemoteSma::familyOf(0) === 'se' && IHUB_RemoteSma::familyOf(1) === 'si' && IHUB_RemoteSma::familyOf(2) === 'stpsx' && IHUB_RemoteSma::familyOf(9) === 'se', 'SMA: SmaControlType 0/1/2 = STP SE / Sunny Island / STPS X, unbekannt = STP SE');
$real = new IHUB_SmaDriver(); $mb = new SimSma('se'); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 100]); $hub->props['SmaControlType'] = 2; $real->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(in_array(44449, $mb->order, true) && !in_array(40149, $mb->order, true), 'SMA: der Treiber wählt die Geräteklasse aus SmaControlType (2 = STPS X: Fenster 44449)');
$mb = new SimSma('se'); $mb->unitId = 126; $mb->regsByUnit = [3 => [4 => $mb->regs[4]], 126 => [4 => []]]; $hub = hubFor(); $real->readSlow($mb, $hub);
ref_ok($mb->unitId === 126 && ($hub->vars['rc_available'][1] ?? null) === true && in_array(3, array_column($mb->reads, 3), true), 'SMA: Steuerregister liegen auf der Geräte-Unit-ID 3, nicht auf der SunSpec-Kennung 126; die Einstellung wird zurückgesetzt');
$sg = $real->getOptionalGroups();
ref_ok(isset($sg['GroupControl']) && ($sg['GroupControl']['default'] ?? true) === false && count($sg) > 1 && ($sg['GroupBat']['default'] ?? true) === true, 'SMA: Gruppe Steuerung startet aus, die übrigen Gruppen bleiben an');
foreach (['se', 'si', 'stpsx'] as $f) {
    $i = (new IHUB_RemoteSma($f))->info();
    ref_ok($i['tested'] === false && count($i['pitfalls']) >= 6 && array_filter($i['pitfalls'], fn($s) => strpos($s, '41193') !== false), "SMA $f: Kennzeichnung ungetestet, Fallen nennen den Totmann (41193)");
}

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
foreach (['solis' => $solis, 'foxess_smart' => $fox, 'deye' => $deye, 'solax' => $solax, 'sma' => $real] as $maker => $drv) {
    $idents = array_column($drv->getOptionalGroups()['GroupControl']['vars'], 0);
    $GLOBALS['TREE'] = []; $i = 100; foreach (IHUB_RemoteControl::vars() as $v) { if (in_array($v[0], $idents, true)) { $GLOBALS['TREE'][$v[0]] = [$i++]; } }
    $h = new RcHub(); $h->maker = $maker; $f = $h->GetFunctions();
    $rc = $f['remoteControl'];
    ref_ok($f['contractVersion'] === '1.9' && $rc['modeID'] === $GLOBALS['TREE']['ctl_rc_mode'][0] && $rc['powerID'] === $GLOBALS['TREE']['ctl_rc_w'][0] && $rc['availableID'] === $GLOBALS['TREE']['rc_available'][0] && $rc['testedID'] === $GLOBALS['TREE']['rc_tested'][0] && $rc['vendorID'] === $GLOBALS['TREE']['rc_vendor'][0] && $rc['modesID'] === $GLOBALS['TREE']['rc_modes'][0] && $rc['failsafeSecID'] === $GLOBALS['TREE']['ctl_rc_failsafe_sec'][0] && $f['controllable'] === true, "$maker: remoteControl mit allen IDs, Vertrag 1.9, controllable");
}
$GLOBALS['TREE'] = ['pv_total' => [101]]; $h = new RcHub(); $f = $h->GetFunctions();
ref_ok($f['remoteControl'] === [], 'Treiber ohne Fernsteuerung: leeres Feld');
$missing = [];
foreach (['solis' => $solis, 'foxess_smart' => $fox, 'deye' => $deye, 'solax' => $solax, 'sma' => $real] as $maker => $drv) {
    $prof = array_merge(array_keys($drv->getProfiles()), array_keys($drv->getEnumProfiles()));
    foreach ($drv->getOptionalGroups()['GroupControl']['vars'] as $v) { if ($v[3] !== '' && $v[3][0] !== '~' && !in_array($v[3], $prof, true)) { $missing[] = "$maker:" . $v[0] . '=' . $v[3]; } }
}
ref_ok($missing === [], 'jedes benutzte Profil der Fernsteuerung ist je Treiber definiert' . ($missing ? ': ' . implode(',', $missing) : ''));
$fox2 = (new IHUB_FoxEssSmartDriver())->getOptionalGroups();
ref_ok(isset($fox2['GroupControl']) && count(array_column($fox2['GroupControl']['vars'], 0)) === count(IHUB_RemoteControl::vars()), 'FoxESS: eigene Gruppe Steuerung mit genau den gemeinsamen Variablen (keine weiteren Schreibwege)');
ref_ok(($fox2['GroupControl']['default'] ?? true) === false && strpos(file_get_contents(dirname(__DIR__) . '/InverterHub/module.php'), "\$group['default'] ?? true") !== false, 'FoxESS: Gruppe Steuerung startet aus (ungetestet), das Hauptmodul wertet default aus; alle anderen Gruppen weiter an');

ref_finish('gemeinsame Fernsteuerung');
