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
IHUB_RemoteFronius::$gapUs = 0; IHUB_RemoteFronius::$readbackUs = 0; IHUB_RemoteFronius::$retryPauseUs = 0;
IHUB_RemoteKostal::$gapUs = 0; IHUB_RemoteKostal::$readbackUs = 0; IHUB_RemoteKostal::$retryPauseUs = 0;
IHUB_RemoteHuawei::$gapUs = 0; IHUB_RemoteHuawei::$dirPauseUs = 0; IHUB_RemoteHuawei::$readbackUs = 0; IHUB_RemoteHuawei::$retryPauseUs = 0;
IHUB_RemoteSungrow::$gapUs = 0; IHUB_RemoteSungrow::$readbackUs = 0; IHUB_RemoteSungrow::$retryPauseUs = 0;
IHUB_RemoteVictron::$readbackUs = 0; IHUB_RemoteVictron::$retryPauseUs = 0;
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

/** Fronius: SunSpec-Kette ab 40000 (SunS, Model 1, 101, 124 mit Länge 24, Ende); Model 124 ab 40014. Werte wie ein GEN24 mit 3300 W Speicherleistung. */
class SimFronius extends SimBase
{
    const BASE = 40014;
    public $modelPresent = true;
    public function __construct(int $wchamax = 3300, int $wsf = 0, int $rsf = -2)
    {
        parent::__construct();
        $r = &$this->regs[3];
        $r[40000] = 0x5375; $r[40001] = 0x6E53;
        $r[40002] = 1; $r[40003] = 4; for ($a = 40004; $a <= 40007; $a++) { $r[$a] = 0; }
        $r[40008] = 101; $r[40009] = 2; $r[40010] = 0; $r[40011] = 0;
        if ($this->modelPresent) {
            $r[40012] = 124; $r[40013] = 24;
            for ($i = 0; $i < 24; $i++) { $r[self::BASE + $i] = 0; }
            $r[self::BASE] = $wchamax; $r[self::BASE + 16] = $wsf & 0xFFFF; $r[self::BASE + 23] = $rsf & 0xFFFF;
            $r[self::BASE + 10] = 10000; $r[self::BASE + 11] = 10000;     // Werkzustand: 100 % beide Richtungen, keine Grenze aktiv
            $r[40038] = 0xFFFF; $r[40039] = 0;
        } else {
            $r[40012] = 0xFFFF; $r[40013] = 0;
        }
    }
    public function readHolding($start, $count) { if (!$this->available) { $this->reads[] = [3, (int)$start, (int)$count, 1]; return null; } return parent::readHolding($start, $count); }
    public function reg(int $idx): int { return $this->regs[3][self::BASE + $idx] ?? 0; }
}

/** Kostal: Register 1080 = 2 (externes Batteriemanagement über Modbus), Akkugrenzen 1076/1078 und Sollwert 1034 als Float32; Wortreihenfolge einstellbar. */
class SimKostal extends SimBase
{
    public $swap = false;
    public function __construct(int $mode = 2, float $maxCharge = 5000.0, float $maxDischarge = 5000.0, bool $swap = false)
    {
        parent::__construct();
        $this->swap = $swap;
        $this->regs[3][1080] = $mode;
        foreach ([1076 => $maxCharge, 1078 => $maxDischarge, 1034 => 0.0] as $a => $v) { $w = \IHUB\NRGModbus\ModbusDecode::packFloat32($v, $swap); $this->regs[3][$a] = $w[0]; $this->regs[3][$a + 1] = $w[1]; }
    }
    public function readHolding($start, $count) { if (!$this->available && (int)$start === 1080) { $this->reads[] = [3, 1080, (int)$count, 1]; return null; } return parent::readHolding($start, $count); }
    public function sp(): float { return \IHUB\NRGModbus\ModbusDecode::float32([$this->regs[3][1034], $this->regs[3][1035]], 0, $this->swap); }
}

/** Huawei: SUN2000 mit LUNA2000 (47000 = 2, Zustand 2, Systemmaximum 5000 W); 47100 schaltet den Arbeitsmodus 37006 (1 = Zwangsmodus läuft). */
class SimHuawei extends SimBase
{
    public function __construct(int $model = 2, int $maxC = 5000, int $maxD = 5000)
    {
        parent::__construct();
        $r = &$this->regs[3];
        $r[47000] = $model; $r[37000] = 2; $r[37006] = 4; $r[37025] = 0;
        foreach ([37046 => $maxC, 37048 => $maxD, 47075 => 3500, 47077 => 3500, 47242 => 0, 47244 => 2000, 47247 => 0, 47249 => 0] as $a => $v) { $r[$a] = ($v >> 16) & 0xFFFF; $r[$a + 1] = $v & 0xFFFF; }
        foreach ([47083, 47087, 47100, 47246] as $a) { $r[$a] = 0; }
    }
    public function readHolding($start, $count) { if (!$this->available && (int)$start === 47000) { $this->reads[] = [3, 47000, (int)$count, 1]; return null; } return parent::readHolding($start, $count); }
    protected function after(): void { $this->regs[3][37006] = (($this->regs[3][47100] ?? 0) !== 0) ? 1 : 4; }
    public function v32(int $a): int { return (($this->regs[3][$a] ?? 0) << 16) | ($this->regs[3][$a + 1] ?? 0); }
}

/** Sungrow: Gerätetypcode bei Dokumentnummer 5000 (Wire 5000 + Versatz), BDC-Nennleistung 5628 (100 = 10 kW), Laufstatus 13000 (0x4000 bei externem EMS), EMS-Register 13050 ff. */
class SimSungrow extends SimBase
{
    public $off;
    public function __construct(int $off = 0, int $type = 0xE03, int $rated = 100)
    {
        parent::__construct();
        $this->off = $off;
        $this->regs[4][4999] = 0; $this->regs[4][5000] = 100; $this->regs[4][5001] = 100;
        $this->regs[4][5000 + $off] = $type;
        $this->regs[4][5628 + $off] = $rated; $this->regs[4][13000 + $off] = 0;
        $this->regs[3][13050 + $off] = 0; $this->regs[3][13051 + $off] = 0xCC; $this->regs[3][13052 + $off] = 0; $this->regs[3][13080 + $off] = 20;
    }
    public function readInput($start, $count) { if (!$this->available && (int)$start === 4999) { $this->reads[] = [4, 4999, (int)$count, 1]; return null; } return parent::readInput($start, $count); }
    protected function after(): void { $this->regs[4][13000 + $this->off] = (($this->regs[3][13050 + $this->off] ?? 0) === 3) ? 0x4000 : 0; }
    public function h(int $doc): int { return $this->regs[3][$doc + $this->off] ?? 0; }
}

/** Victron GX: Unit 100, Override 2716/2717 (int32, 0 = keiner), Hub4-Modus 2902, Dynamic ESS 5400; Schreiben auf 2716 nur auf Unit 100. */
class SimVictron extends SimBase
{
    public function __construct(int $hub4 = 2, int $dess = 0)
    {
        parent::__construct();
        $this->unitId = 1;
        $this->regs[3][2716] = 0; $this->regs[3][2717] = 0; $this->regs[3][2902] = $hub4; $this->regs[3][5400] = $dess; $this->regs[3][2700] = 50;
        for ($a = 808; $a <= 826; $a++) { $this->regs[3][$a] = 0; }
        $this->flow(0, 1000, 1000, 0, 0);
    }
    public function readHolding($start, $count) { if (!$this->available && (int)$start === 2716) { $this->reads[] = [3, 2716, (int)$count, (int)$this->unitId]; return null; } return parent::readHolding($start, $count); }
    public function writeMultiple($start, $values) { $this->units[] = $this->unitId; return parent::writeMultiple($start, $values); }
    public $units = [];
    /** Messwerte: AC-PV (808), Verbrauch (817), Netz (820, + = Bezug), Akkuleistung (842, + = Laden), DC-PV (850). */
    public function flow(int $pvAc, int $load, int $grid, int $bat, int $pvDc): void { $this->regs[3][808] = $pvAc & 0xFFFF; $this->regs[3][817] = $load & 0xFFFF; $this->regs[3][820] = $grid & 0xFFFF; $this->regs[3][842] = $bat & 0xFFFF; $this->regs[3][850] = $pvDc & 0xFFFF; }
    public function ov(): int { $u = (($this->regs[3][2716] ?? 0) << 16) | ($this->regs[3][2717] ?? 0); return $u >= 0x80000000 ? $u - 0x100000000 : $u; }
}

function hubFor(array $seed = [], bool $control = true) { $h = new RefHub(['GroupControl' => $control]); $h->seed = $seed; return $h; }
function u32w(int $v): array { $u = $v < 0 ? $v + 4294967296 : $v; return [($u >> 16) & 0xFFFF, $u & 0xFFFF]; }

$solis = new IHUB_SolisDriver(); $fox = new IHUB_FoxEssSmartDriver(); $deye = new IHUB_DeyeDriver(); $solax = new IHUB_SolaxDriver();
$fronius = new IHUB_FroniusDriver(); $kostal = new IHUB_KostalDriver(); $huawei = new IHUB_HuaweiDriver(); $sungrow = new IHUB_SungrowDriver(); $victron = new IHUB_VictronDriver(); $smaSe = new SmaDrvFamily(); $smaSi = new SmaDrvFamily(); $smaSi->fam = 'si'; $smaSx = new SmaDrvFamily(); $smaSx->fam = 'stpsx';
$adapters = [
    'Solis' => ['drv' => $solis, 'sim' => fn() => new SimSolis(), 'adapter' => new IHUB_RemoteSolis(), 'modes' => [0, 1, 2, 3], 'tested' => true, 'rated' => 0],
    'FoxESS' => ['drv' => $fox, 'sim' => fn() => new SimFox(), 'adapter' => new IHUB_RemoteFoxEss(), 'modes' => [0, 2, 3], 'tested' => false, 'rated' => 0],
    'Deye' => ['drv' => $deye, 'sim' => fn() => new SimDeye(), 'adapter' => new IHUB_RemoteDeye(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 10000],
    'SolaX' => ['drv' => $solax, 'sim' => fn() => new SimSolax(), 'adapter' => new IHUB_RemoteSolax(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 0],
    'Victron' => ['drv' => $victron, 'sim' => fn() => new SimVictron(), 'adapter' => new IHUB_RemoteVictron(), 'modes' => [0, 1, 2, 3], 'tested' => false, 'rated' => 0],
    'Sungrow' => ['drv' => $sungrow, 'sim' => fn() => new SimSungrow(), 'adapter' => new IHUB_RemoteSungrow(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 10000],
    'Huawei' => ['drv' => $huawei, 'sim' => fn() => new SimHuawei(), 'adapter' => new IHUB_RemoteHuawei(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 5000],
    'Kostal' => ['drv' => $kostal, 'sim' => fn() => new SimKostal(), 'adapter' => new IHUB_RemoteKostal(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 5000],
    'Fronius' => ['drv' => $fronius, 'sim' => fn() => new SimFronius(), 'adapter' => new IHUB_RemoteFronius(), 'modes' => [0, 1, 2], 'tested' => false, 'rated' => 3300],
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
    $declMode = array_values(array_filter($A['modes'], fn($m) => $m > 0))[0];
    $declWant = max(0, $ad->effectiveFailsafeSec(IHUB_RemoteControl::requestFromHub($hub, $declMode)));
    ref_ok(array_key_exists('rc_failsafe_decl_sec', $hub->vars) && $hub->vars['rc_failsafe_decl_sec'][1] === $declWant, "$name: langsamer Zyklus meldet das Vertragsfeld failsafeSec (rc_failsafe_decl_sec = $declWant s, aus dem Adapter)");

    // Fähigkeit fehlt
    $mb = $mk(); $mb->available = false; if ($name === 'Deye') { $mb->notAvailable = true; } if ($name === 'Solis') { $mb->regs[4][34502] = 0; }
    $hub = hubFor();
    $drv->writeControl($mb, $hub, 'ctl_rc_mode', in_array(2, $A['modes'], true) ? 2 : 3);
    ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), "$name: ohne Fähigkeit nichts geschrieben, Warnung, Modus bleibt");
    $mb = $mk(); $mb->silent = true; $hub = hubFor();
    $drv->writeControl($mb, $hub, 'ctl_rc_mode', in_array(2, $A['modes'], true) ? 2 : 3);
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
    } elseif ($info['gridCharge'] && !$info['socWindow']) {
        ref_ok(count($hub->warn) === 2 && $hub->vars['ctl_rc_grid_charge'][1] === 1 && !isset($hub->vars['ctl_rc_soc_max']), "$name: Netzladen wird gemerkt, SOC-Fenster abgelehnt statt still ignoriert (2 Warnungen)");
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
    $firstMode = in_array(2, $A['modes'], true) ? 2 : 3;      // Victron bietet nur 0 und 3
    $drv->writeControl($mb, $hub, 'ctl_rc_mode', $firstMode);
    ref_ok($hub->warn === [] && ($hub->vars['ctl_rc_mode'][1] ?? null) === $firstMode, "$name: Fähigkeit noch unbekannt (Version 0): wird beim Schreiben einmal abgefragt, Modus $firstMode angenommen");
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








echo "\n== Victron: Netzsollwert-Override 2716, nur Modus 0 und 3 ==\n";
$mb = new SimVictron(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2500]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->writes === [['multi', 2716, IHUB_RemoteVictron::words(-2500)]] && $mb->ov() === -2500, 'Victron: Modus 3 +2500 W (Einspeisung) = Override −2500 W (int32, hohes Wort zuerst) in 2716');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 180 && $hub->vars['rc_active'][1] === true && $mb->units === [100] && $mb->unitId === 1, 'Victron: übernommen, Totmann 180 s (Zeitgeber von hub4control, die kleinere Messung), aktiv; geschrieben auf Unit 100, die Unit-ID der Instanz wird zurückgesetzt');
$mb = new SimVictron(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => -1800]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->ov() === 1800, 'Victron: −1800 W (Bezug) = Override +1800 W');
$mb = new SimVictron(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 0]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->writes === [['multi', 2716, [0, 0]]] && $hub->warn === [], 'Victron: 0 W ist ein echter Override (Netzbezug 0 W) und wird als 0 gesendet, kein Freigeben');
$mb = new SimVictron(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 40000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->ov() === -40000, 'Victron: Leistung auf ±40000 W begrenzt');
$mb = new SimVictron(); $mb->regs[3][2716] = 0xFFFF; $mb->regs[3][2717] = 0xF63C; $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 3]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [['multi', 2716, [0, 50]]] && $mb->ov() === 50 && $hub->vars['ctl_rc_mode'][1] === 0 && $hub->vars['rc_failsafe_eff_sec'][1] === 0, 'Victron: Modus 0 hat per Modbus keine Freigabe: der Netzsollwert 2700 (50 W) wird einmal gespiegelt, 0 wird NIE als Freigabe geschrieben');
$n = count($mb->writes); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok(count($mb->writes) === $n && $hub->warn === [], 'Victron: Modus 0 erneut: der Override steht schon auf dem Netzsollwert, es wird geschwiegen (hub4control nimmt ihn nach seinem Zeitgeber zurück)');
$mb = new SimVictron(); $mb->noRead = [2700]; $mb->regs[3][2717] = 700; $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 3]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [] && $hub->warn === [], 'Victron: Modus 0 ohne lesbaren Netzsollwert 2700: nichts geschrieben (nie 0 raten)');
$mb = new SimVictron(2, 1); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && strpos($hub->warn[0], '5400') !== false && !isset($hub->vars['ctl_rc_mode']), 'Victron: Dynamic ESS aktiv (5400 = 1): nichts geschrieben, Warnung nennt Register 5400');
$mb = new SimVictron(3, 0); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && strpos($hub->warn[0], '2902') !== false, 'Victron: ESS-Modus 3 (externe Steuerung, 2902 = 3): der Override gilt nicht, nichts geschrieben, Warnung nennt Register 2902');
$mb = new SimVictron(); $mb->noRead = [2902, 5400]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($hub->warn === [] && $mb->ov() === -1000, 'Victron: fehlen 2902 und 5400 (ältere Version, kein Settings-Dienst), wird trotzdem geschrieben');
$mb = new SimVictron(); $mb->ignoreRegs = [2716]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '2716') !== false && !isset($hub->vars['ctl_rc_mode']) && count($mb->writes) === 2, 'Victron: Override wird nicht übernommen: zweiter Versuch, Warnung nennt Register 2716');
$mb = new SimVictron(); $mb->failWrite = [2716]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok(count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']) && $mb->unitId === 1, 'Victron: Schreibfehler: Warnung, Modus bleibt, Unit-ID zurückgesetzt');
$mb = new SimVictron(); $mb->available = false; $hub = hubFor(); $victron->readSlow($mb, $hub);
ref_ok(!isset($hub->vars['rc_available']) && $mb->unitId === 1, 'Victron: ohne Antwort auf 2716 (Venus OS älter als 3.50) bleiben die Variablen unverändert');
$mb = new SimVictron(); $hub = hubFor(); $victron->readSlow($mb, $hub);
ref_ok($hub->vars['rc_available'][1] === true && $hub->vars['rc_modes'][1] === '0,1,2,3' && $hub->vars['rc_tested'][1] === false, 'Victron: langsamer Zyklus meldet Fähigkeit und die Modi 0,1,2,3');
$mb = new SimVictron(); $mb->flow(0, 1000, 1000, 0, 0); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 4000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [['multi', 2716, [0, 1000]]] && $hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 180, 'Victron: Halten (Last 1000 W, keine PV): Netzsollwert = Last = 1000 W Bezug (Akku 0 W), auch bei gemerkter Leistung; Totmann 180 s');
$mb = new SimVictron(); $mb->flow(0, 1000, 400, 0, 600); $hub = hubFor(['rc_version' => 1]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->ov() === 400, 'Victron: Halten mit DC-gekoppelter PV (600 W, Register 850) und Last 1000 W: Sollwert 400 W Bezug');
$mb = new SimVictron(); $mb->flow(0, 1000, -500, 0, 1500); $hub = hubFor(['rc_version' => 1]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->ov() === -500, 'Victron: Halten mit DC-PV 1500 W über der Last: der Überschuss geht ins Netz, Sollwert −500 W (die DC-PV zählt in die Bilanz)');
$mb = new SimVictron(); $mb->flow(0, 1000, 1300, 300, 0); $hub = hubFor(['rc_version' => 1]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->ov() === 1000, 'Victron: Halten, der Akku lädt ungewollt mit 300 W: die Rückführung bleibt auf 1000 W Bezug (Vorsteuerung und Rückführung stimmen überein)');
$mb = new SimVictron(); $mb->flow(0, 1000, 1000, 0, 0); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->ov() === 3000 && $hub->warn === [], 'Victron: Akku +2000 W Laden bei Last 1000 W: Netzsollwert +3000 W Bezug');
$mb = new SimVictron(); $mb->flow(0, 1000, 1000, 0, 0); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => -1500]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->ov() === -500, 'Victron: Akku −1500 W Entladen bei Last 1000 W: Netzsollwert −500 W (Einspeisung)');
$mb = new SimVictron(); $mb->flow(0, 1000, 3500, 0, 0); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->ov() === 3800, 'Victron: der Akku folgt nicht (Netz 3500 W, Akku 0 W): der Sollwert wird auf die Vorsteuerung ± 800 W begrenzt (3800 W), kein Aufschaukeln');
$mb = new SimVictron(); $mb->flow(0, 1000, -2000, 0, 0); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 2000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->ov() === 2200, 'Victron: Rückführung nach unten ebenfalls begrenzt (Vorsteuerung 3000 W − 800 W)');
$mb = new SimVictron(); $mb->noRead = [850]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 500]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && strpos($hub->warn[0], '842') !== false, 'Victron: Messwerte (850) nicht lesbar: Halten und Akku ±W werden nicht geschrieben, Warnung nennt die Register');
$mb = new SimVictron(); $mb->noRead = [850]; $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 500]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($hub->warn === [] && $mb->ov() === -500, 'Victron: Modus 3 braucht die Messwerte nicht');
$mb = new SimVictron(2, 1); $hub = hubFor(['rc_version' => 1]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && strpos($hub->warn[0], '5400') !== false, 'Victron: Dynamic ESS aktiv: auch Halten wird nicht geschrieben');
ref_ok(IHUB_RemoteVictron::loopSetpoint(0, ['pv' => 0, 'load' => 1000, 'grid' => 1000, 'bat' => 0]) === 1000 && IHUB_RemoteVictron::loopSetpoint(2000, ['pv' => 500, 'load' => 1000, 'grid' => 500, 'bat' => 0]) === 2500 && IHUB_RemoteVictron::loopSetpoint(-1000, ['pv' => 0, 'load' => 1000, 'grid' => 1000, 'bat' => 0]) === 0, 'Victron: loopSetpoint() rechnet Vorsteuerung und Rückführung');
$mb = new SimVictron(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 500]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok(array_unique($mb->order) === [2716], 'Victron: es wird nur Register 2716 geschrieben, nie 2700, 2704, 2705, 2720, 2901 oder 2902 (auch Halten und Akku ±W nicht)');
ref_ok(IHUB_RemoteVictron::devicePower(3, 2500) === -2500 && IHUB_RemoteVictron::devicePower(3, -1230) === 1230 && IHUB_RemoteVictron::devicePower(3, 0) === 0 && IHUB_RemoteVictron::devicePower(0, 2500) === 0 && IHUB_RemoteVictron::devicePower(3, 1234) === -1230, 'Victron: devicePower je Modus, Vorzeichen, 0 W als echter Override und 10-W-Rundung');
ref_ok(IHUB_RemoteVictron::words(-2500) === [0xFFFF, 0xF63C] && IHUB_RemoteVictron::words(70000) === [1, 4464], 'Victron: int32 hohes Wort zuerst (Zweierkomplement)');
$a = new IHUB_RemoteVictron();
ref_ok($a->validate(['mode' => 4, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['flow' => []]) !== '' && $a->validate(['mode' => 1, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], []) !== '' && $a->validate(['mode' => 1, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['flow' => ['pv' => 0, 'load' => 0, 'grid' => 0, 'bat' => 0]]) === '' && $a->validate(['mode' => 3, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], []) === '', 'Victron: Modus 4 und Halten ohne Messwerte werden vom Adapter selbst abgelehnt, Modus 3 braucht keine Messwerte');
ref_ok($a->isActive(-500) && $a->isActive(1) && !$a->isActive(0), 'Victron: aktiv, solange ein Override gesetzt ist');
echo "\n== Victron: Overrides über MQTT (Brücke und Portal-ID an der Instanz) ==\n";
function mqttHub(array $seed) { $h = hubFor($seed); $h->props['VictronPortalID'] = 'c0619ab12345'; $h->props['VictronMqttBridgeID'] = 7; return $h; }
function mqttVals(RefHub $h): array { $o = []; foreach ($h->published as [$t, $p]) { $o[substr($t, strlen('W/c0619ab12345/hub4/0/Overrides/'))] = json_decode($p, true)['value']; } return $o; }
$mb = new SimVictron(); $mb->regs[3][2716] = 0xFFFF; $mb->regs[3][2717] = 0xF63C;   // das GX hat den Override übernommen (−2500)
$hub = mqttHub(['rc_version' => 1, 'ctl_rc_w' => 2500]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($mb->writes === [] && array_column($hub->published, 0) === array_map(fn($n) => 'W/c0619ab12345/hub4/0/Overrides/' . $n, ['ForceCharge', 'MaxChargePower', 'MaxDischargePower', 'FeedInExcess', 'Setpoint']) && mqttVals($hub) === ['ForceCharge' => 0, 'MaxChargePower' => -1, 'MaxDischargePower' => -1, 'FeedInExcess' => 0, 'Setpoint' => -2500] && $hub->warn === [], 'Victron MQTT: Modus 3 +2500 W = Setpoint −2500, Setpoint zuletzt, die anderen Overrides frei, kein Modbus-Schreiben');
$mb = new SimVictron(); $hub = mqttHub(['rc_version' => 1, 'ctl_rc_mode' => 3]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [] && mqttVals($hub) === ['ForceCharge' => 0, 'MaxChargePower' => -1, 'MaxDischargePower' => -1, 'FeedInExcess' => 0, 'Setpoint' => null] && strpos($hub->published[4][1], 'null') !== false && $hub->warn === [], 'Victron MQTT: Modus 0 löscht den Setpoint mit JSON null und gibt alle Overrides frei (echte Freigabe, kein Spiegeln von 2700)');
$mb = new SimVictron(); $mb->flow(0, 1000, 1000, 0, 600); $hub = mqttHub(['rc_version' => 1, 'ctl_rc_w' => 2000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(mqttVals($hub) === ['ForceCharge' => 1, 'MaxChargePower' => (int)round(2000 / 0.95 - 600 * 0.98), 'MaxDischargePower' => -1, 'Setpoint' => null] && $hub->warn === [], 'Victron MQTT: Akku +2000 W (Laden) = ForceCharge 1, MaxChargePower = P/0,95 − 0,98·PV_DC, Setpoint frei');
$mb = new SimVictron(); $mb->flow(0, 1000, 1000, 0, 600); $mb->regs[3][2716] = 0xFFFF; $mb->regs[3][2717] = (65536 - 40000 / 10 * 10) & 0xFFFF;
$mb->regs[3][2716] = (int)((-40000 >> 16) & 0xFFFF); $mb->regs[3][2717] = (int)(-40000 & 0xFFFF);
$hub = mqttHub(['rc_version' => 1, 'ctl_rc_w' => -1500]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(mqttVals($hub) === ['ForceCharge' => 0, 'MaxChargePower' => -1, 'MaxDischargePower' => (int)round((1500 + 600) * 0.95), 'Setpoint' => -40000] && $hub->warn === [], 'Victron MQTT: Akku −1500 W (Entladen) = MaxDischargePower (D + PV_DC)·0,95 und Setpoint −40000 (das Netz darf aufnehmen, die Akkugrenze regelt)');
$mb = new SimVictron(); $mb->flow(0, 1000, 1000, 0, 600); $mb->regs[3][2716] = (int)((-40000 >> 16) & 0xFFFF); $mb->regs[3][2717] = (int)(-40000 & 0xFFFF);
$hub = mqttHub(['rc_version' => 1]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(mqttVals($hub) === ['ForceCharge' => 0, 'MaxChargePower' => -1, 'MaxDischargePower' => 600, 'Setpoint' => -40000] && $hub->warn === [], 'Victron MQTT: Halten = MaxDischargePower auf die DC-PV begrenzt (kein Entladen ins Haus über die PV hinaus), kein Regelkreis');
$mb = new SimVictron(); $mb->flow(0, 1000, 1000, 0, 0); $hub = mqttHub(['rc_version' => 1]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(mqttVals($hub)['MaxDischargePower'] === 1, 'Victron MQTT: Halten ohne PV: MaxDischargePower mindestens 1 W (0 würde als "aus" gelesen)');
$mb = new SimVictron(); $hub = mqttHub(['rc_version' => 1, 'ctl_rc_w' => 1000]); $hub->publishOk = false; $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($hub->warn !== [] && $mb->writes === [] && count($hub->published) === 2, 'Victron MQTT: Veröffentlichen schlägt fehl (Brücke weg): Warnung, nichts über Modbus nachgeschoben, je Versuch nur der erste Schritt (zwei Versuche), kein Weiterschreiben');
$mb = new SimVictron(2, 1); $mb->flow(0, 1000, 1000, 0, 0); $hub = mqttHub(['rc_version' => 1, 'ctl_rc_w' => 1000]); $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($hub->published === [] && $hub->warn !== [], 'Victron MQTT: Dynamic ESS aktiv wird auch über MQTT abgelehnt (der Override würde verworfen)');
$mb = new SimVictron(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_w' => 1000]); $hub->props['VictronPortalID'] = 'c0619ab12345'; $victron->writeControl($mb, $hub, 'ctl_rc_mode', 3);
ref_ok($hub->published === [] && $mb->ov() === -1000, 'Victron: Portal-ID ohne Brücke bleibt beim Modbus-Weg 2716');

$vg = $victron->getOptionalGroups();
ref_ok(isset($vg['GroupRemote']) && ($vg['GroupRemote']['default'] ?? true) === false && ($vg['GroupPV']['default'] ?? true) === true, 'Victron: Gruppe Fernsteuerung startet aus, die übrigen Gruppen bleiben an');
$i = $a->info();
ref_ok($i['tested'] === false && $i['modes'] === [0, 1, 2, 3] && count($i['pitfalls']) >= 10 && array_filter($i['pitfalls'], fn($s) => strpos($s, 'does NOT release') !== false) && array_filter($i['pitfalls'], fn($s) => strpos($s, 'deliberate timer') !== false), 'Victron: Kennzeichnung, Modi 0 bis 3, Fallen nennen den absichtlichen Zeitgeber und dass 0 nicht freigibt');

echo "\n== Sungrow: externer EMS-Modus, Heartbeat, Adressversatz ==\n";
$mb = new SimSungrow(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 2000, 'ctl_rc_failsafe_sec' => 90]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [13050, 13051, 13080], 'Sungrow: Reihenfolge EMS-Modus 13050, Befehl und Leistung 13051/13052, Heartbeat 13080 zuletzt');
ref_ok($mb->writes[0][2] === [3] && $mb->writes[1][2] === [0xAA, 2000] && $mb->writes[2][2] === [90], 'Sungrow: +2000 W Laden: 13050 = 3 (externes EMS), 0xAA mit 2000 W, Heartbeat 90 s');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 90 && $hub->vars['rc_active'][1] === true && $hub->vars['rc_status'][1] === 0x4000, 'Sungrow: übernommen, Totmann 90 s, aktiv (Laufstatus 0x4000)');
$n = count($mb->writes); $hub->SetVarInt('ctl_rc_w', 500); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(array_slice($mb->order, $n) === [13051, 13080] && $mb->h(13052) === 500, 'Sungrow: zweiter Zyklus schreibt nur Befehl/Leistung und Heartbeat, 13050 (dauerhaft gespeichert) nicht erneut');
$mb = new SimSungrow(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => -1500]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[1][2] === [0xBB, 1500] && $mb->writes[2][2] === [60], 'Sungrow: −1500 W Entladen: 0xBB mit 1500 W; Heartbeat 0 = Vorgabe 60 s');
$mb = new SimSungrow(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 3000]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[1][2] === [0xCC, 0] && $mb->writes[2][2] === [60], 'Sungrow: Halten = 0xCC mit 0 W im externen Modus, der Heartbeat läuft (Totmann auch beim Halten)');
$mb = new SimSungrow(0, 0xE03, 50); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 9000]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->h(13052) === 5000, 'Sungrow: Leistung auf die BDC-Nennleistung 5628 (50 = 5 kW) begrenzt');
foreach ([[5, 10], [99999, 1000]] as [$in, $outv]) {
    $mb = new SimSungrow(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_failsafe_sec' => $in]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 1);
    ref_ok($mb->h(13080) === $outv, "Sungrow: Heartbeat $in s wird auf $outv s begrenzt");
}
$mb = new SimSungrow(-1); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 2000]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [13049, 13050, 13079] && $hub->warn === [] && $mb->h(13051) === 0xAA, 'Sungrow: Versatz −1 (Dokument: Nummer minus eins) wird am Gerätetypcode bei 4999 erkannt, alle Adressen um eins kleiner');
$mb = new SimSungrow(0, 0x1234); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), 'Sungrow: unbekannter Gerätetypcode: nichts geschrieben, Warnung');
$mb = new SimSungrow(0); $mb->noRead = [13050]; $hub = hubFor(['rc_version' => 1]); $sungrow->readSlow($mb, $hub);
ref_ok($hub->vars['rc_available'][1] === false, 'Sungrow: ohne EMS-Register 13050 (String-Wechselrichter): nicht verfügbar');
$mb = new SimSungrow(0); $mb->regs[3][13050] = 3; $mb->regs[3][13051] = 0xAA; $mb->regs[3][13052] = 700; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_mode' => 2]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [['multi', 13050, [0, 0xCC, 0]]] && $hub->vars['ctl_rc_mode'][1] === 0, 'Sungrow: Modus 0 schreibt 13050 := 0, Befehl 0xCC, Leistung 0 in einem Block');
$mb = new SimSungrow(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_mode' => 0]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [] && $hub->warn === [], 'Sungrow: Modus 0 bei schon hergestelltem Eigenverbrauch: nichts geschrieben');
$mb = new SimSungrow(0); $mb->ignoreRegs = [13080]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 100]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '13080') !== false && !isset($hub->vars['ctl_rc_mode']), 'Sungrow: Heartbeat nicht übernommen (Totmann bliebe bei 20 s): Warnung nennt Register 13080');
$mb = new SimSungrow(0); $mb->ignoreRegs = [13051]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 100]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '13051') !== false, 'Sungrow: Befehl nicht übernommen: Warnung nennt Register 13051');
$mb = new SimSungrow(0); $mb->failWrite = [13050]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 100]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && !in_array(13080, $mb->order, true), 'Sungrow: Schreiben des EMS-Modus scheitert: Befehl und Heartbeat werden nicht mehr geschrieben');
$mb = new SimSungrow(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 10000, 'ctl_rc_w' => 100]); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 2); $sungrow->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(array_diff($mb->order, [13050, 13051, 13080]) === [] && !in_array(33047, $mb->order, true) && !in_array(13058, $mb->order, true) && !in_array(13074, $mb->order, true) && !in_array(13100, $mb->order, true), 'Sungrow: nie 33047/33048, SOC-Grenzen 13058/13059, Einspeisegrenze 13074 oder 13100');
$a = new IHUB_RemoteSungrow();
ref_ok($a->validate(['mode' => 3, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['off' => 0, 'ratedW' => 10000]) !== '' && $a->validate(['mode' => 2, 'watts' => 500, 'failsafeSec' => 0, 'ext' => []], ['off' => 0]) !== '' && $a->validate(['mode' => 2, 'watts' => 500, 'failsafeSec' => 0, 'ext' => []], ['ratedW' => 10000]) !== '' && $a->validate(['mode' => 2, 'watts' => 500, 'failsafeSec' => 0, 'ext' => []], ['off' => 0, 'ratedW' => 10000]) === '', 'Sungrow: Modus 3, unbekannte Nennleistung und fehlender Versatz werden vom Adapter abgelehnt');
ref_ok(IHUB_RemoteSungrow::command(2, 2000, 10000) === [0xAA, 2000] && IHUB_RemoteSungrow::command(2, -2000, 10000) === [0xBB, 2000] && IHUB_RemoteSungrow::command(2, 0, 10000) === [0xCC, 0] && IHUB_RemoteSungrow::command(1, 2000, 10000) === [0xCC, 0], 'Sungrow: command() je Modus und Vorzeichen');
ref_ok(IHUB_RemoteSungrow::isType(0xE03) && IHUB_RemoteSungrow::isType(0xE20) && IHUB_RemoteSungrow::isType(0xD0D) && !IHUB_RemoteSungrow::isType(100) && !IHUB_RemoteSungrow::isType(0), 'Sungrow: Gerätetypcodes aus dem Anhang erkannt, Leistungswerte nicht');
ref_ok($a->isActive(0x4000) && $a->isActive(0x0800) && !$a->isActive(0), 'Sungrow: aktiv bei externem EMS (0x4000) oder Zwangsbetrieb (0x0800)');
$sg = $sungrow->getOptionalGroups();
ref_ok(isset($sg['GroupRemote']) && ($sg['GroupRemote']['default'] ?? true) === false && isset($sg['GroupControl']) && ($sg['GroupControl']['default'] ?? true) === true && count(array_column($sg['GroupControl']['vars'], 0)) === 1, 'Sungrow: eigene Gruppe Fernsteuerung startet aus, die Gruppe Start/Stopp bleibt unverändert und an');
$i = $a->info();
ref_ok($i['tested'] === false && count($i['pitfalls']) >= 10 && array_filter($i['pitfalls'], fn($s) => strpos($s, '33047') !== false) && array_filter($i['pitfalls'], fn($s) => strpos($s, 'offset') !== false), 'Sungrow: Kennzeichnung ungetestet, Fallen nennen 33047 und den Adressversatz');

echo "\n== Huawei: Zwangsladen/-entladen, Minuten, Hold ohne Totmann ==\n";
$u = fn(int $v) => [($v >> 16) & 0xFFFF, $v & 0xFFFF];
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 2000, 'ctl_rc_failsafe_sec' => 90]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [47247, 47083, 47100], 'Huawei: Reihenfolge Leistung 47247, Dauer 47083, Zwangsmodus 47100 zuletzt (47075 reicht schon, Rest steht)');
ref_ok($mb->writes[0] === ['multi', 47247, $u(2000)] && $mb->writes[1] === ['multi', 47083, [2]] && $mb->writes[2] === ['multi', 47100, [1]], 'Huawei: +2000 W Laden: 47247 = 2000 (U32, FC 16), Dauer 90 s = 2 Minuten (aufgerundet), 47100 = 1');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 120 && $hub->vars['rc_active'][1] === true && $mb->regs[3][37006] === 1, 'Huawei: übernommen, Totmann 120 s (2 min), aktiv (37006 = 1)');
$n = count($mb->writes); $hub->SetVarInt('ctl_rc_w', 1500); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(array_slice($mb->order, $n) === [47247, 47083, 47100] && $mb->v32(47247) === 1500, 'Huawei: neuer Sollwert 1500 W: Leistung, dann Dauer und Zwangsmodus');
$n = count($mb->writes); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(array_slice($mb->order, $n) === [47083, 47100], 'Huawei: unveränderter Zyklus schreibt nur Dauer 47083 und Zwangsmodus 47100');
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => -1200]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[0] === ['multi', 47249, $u(1200)] && $mb->writes[2] === ['multi', 47100, [2]] && $mb->writes[1][2] === [2], 'Huawei: −1200 W Entladen: 47249 = 1200, 47100 = 2; Dauer 0 = Vorgabe 120 s = 2 Minuten');
$mb = new SimHuawei(); $mb->regs[3][47075] = 0; $mb->regs[3][47076] = 0; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 3000]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order[0] === 47075 && $mb->writes[0][2] === $u(5000), 'Huawei: Ladegrenze 47075 steht auf 0 (Hold-Rest): sie wird zuerst auf das Systemmaximum 37046 angehoben');
$mb = new SimHuawei(2, 3000, 3000); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3000, 'ctl_rc_w' => 9000]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->v32(47247) === 3000, 'Huawei: Leistung über dem Systemmaximum 37046 auf 3000 W begrenzt');
$mb = new SimHuawei(); $mb->regs[3][47100] = 1; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => -800]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order[0] === 47100 && $mb->writes[0][2] === [0] && $mb->order[count($mb->order) - 1] === 47100 && end($mb->writes)[2] === [2], 'Huawei: Richtungswechsel Laden → Entladen: erst 47100 = 0, danach die neue Richtung');
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 2000, 'ctl_rc_grid_charge' => 1]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(in_array(47087, $mb->order, true) && $mb->regs[3][47087] === 1 && $mb->v32(47242) === 2000 && $mb->v32(47244) === 2000 && array_search(47087, $mb->order) < array_search(47247, $mb->order), 'Huawei: Netzladen erlaubt: 47087 = 1, Netzladeleistung 47242 = 2000 und Maximum 47244 vor der Zwangsleistung');
$mb = new SimHuawei(); $mb->regs[3][47087] = 1; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 2000, 'ctl_rc_grid_charge' => 2]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->regs[3][47087] === 0, 'Huawei: Netzladen nicht erlaubt: 47087 = 0');
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 2000]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [['multi', 47075, [0, 0]], ['multi', 47077, [0, 0]]] && $hub->vars['rc_failsafe_eff_sec'][1] === 0 && $hub->warn === [], 'Huawei: Halten sperrt 47075 und 47077 auf 0, ohne Totmann (rc_failsafe_eff_sec = 0)');
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 0]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->v32(47075) === 0 && $mb->v32(47077) === 0 && $hub->vars['rc_failsafe_eff_sec'][1] === 0, 'Huawei: Modus 2 mit 0 W ist Halten');
$mb = new SimHuawei(); $mb->regs[3][47100] = 2; $mb->regs[3][47083] = 5; $mb->regs[3][47250] = 900; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_mode' => 2]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->regs[3][47100] === 0 && $mb->v32(47249) === 0 && $mb->regs[3][47083] === 0 && $mb->v32(47075) === 3500 && $hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 0, 'Huawei: Modus 0 stoppt, löscht Leistung und Dauer, eigene Grenzwerte bleiben');
$mb = new SimHuawei(); $mb->regs[3][47076] = 0; $mb->regs[3][47078] = 0; $mb->regs[3][47075] = 0; $mb->regs[3][47077] = 0; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_mode' => 1]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->v32(47075) === 5000 && $mb->v32(47077) === 5000, 'Huawei: Modus 0 hebt die Hold-Sperre (0) auf das Systemmaximum an');
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_mode' => 2]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [] && $mb->v32(47075) === 3500, 'Huawei: Modus 0 ohne Rest: nichts geschrieben, eigene Grenzwerte (3500) bleiben');
$mb = new SimHuawei(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1, 'Huawei: Speichermodell 47000 = 0 (keine Batterie): nichts geschrieben, Warnung');
$mb = new SimHuawei(); $mb->ignoreRegs = [47100]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 500]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '47100') !== false && !isset($hub->vars['ctl_rc_mode']), 'Huawei: Zwangsmodus wird nicht übernommen: Warnung nennt Register 47100');
$mb = new SimHuawei(); $mb->ignoreRegs = [47083]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 500]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '47083') !== false, 'Huawei: Dauer (Totmann) nicht übernommen: Warnung nennt Register 47083');
$mb = new SimHuawei(); $mb->failWrite = [47247]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 500]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && !in_array(47100, $mb->order, true), 'Huawei: Schreiben der Leistung scheitert: der Zwangsmodus wird nicht gestartet');
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 500, 'ctl_rc_failsafe_sec' => 999999]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->regs[3][47083] === 1440, 'Huawei: Dauer auf 1440 Minuten begrenzt');
$mb = new SimHuawei(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 500]); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 2); $huawei->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(array_diff($mb->order, [47100, 47247, 47083, 47075, 47077, 47246]) === [] && !in_array(47081, $mb->order, true) && !in_array(47082, $mb->order, true) && !in_array(47321, $mb->order, true) && !in_array(47589, $mb->order, true), 'Huawei: nie SOC-Grenzen 47081/47082 und nie die Drittanbieter-Register 47321/47589');
$a = new IHUB_RemoteHuawei();
ref_ok($a->validate(['mode' => 3, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['ratedW' => 5000]) !== '' && $a->validate(['mode' => 2, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['ratedW' => 5000]) === '' && $a->validate(['mode' => 2, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], []) !== '', 'Huawei: Modus 3 und unbekanntes Systemmaximum werden vom Adapter abgelehnt');
ref_ok(IHUB_RemoteHuawei::power(2000, 5000, 4000) === 2000 && IHUB_RemoteHuawei::power(9000, 5000, 4000) === 5000 && IHUB_RemoteHuawei::power(-9000, 5000, 4000) === 4000, 'Huawei: power() begrenzt je Richtung auf das Systemmaximum');
$sg = $huawei->getOptionalGroups();
ref_ok(isset($sg['GroupControl']) && ($sg['GroupControl']['default'] ?? true) === false && ($sg['GroupGrid']['default'] ?? true) === true, 'Huawei: Gruppe Steuerung startet aus, die übrigen Gruppen bleiben an');
$i = $a->info();
ref_ok($i['tested'] === false && count($i['pitfalls']) >= 10 && array_filter($i['pitfalls'], fn($s) => strpos($s, 'NO failsafe') !== false) && array_filter($i['pitfalls'], fn($s) => strpos($s, 'MINUTES') !== false), 'Huawei: Kennzeichnung ungetestet, Fallen nennen Hold ohne Totmann und die Minuten');

echo "\n== Kostal: Sollwert 1034, Vorzeichen, Grenzen, Freischaltung ==\n";
$pk = fn(float $v, bool $s = false) => \IHUB\NRGModbus\ModbusDecode::packFloat32($v, $s);
$mb = new SimKostal(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 2000]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [['multi', 1034, $pk(-2000.0)]], 'Kostal: +2000 W (Laden) = −2000 W in Register 1034 (Float32), genau ein Schreibbefehl');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 0 && $hub->vars['rc_active'][1] === true && $hub->vars['rc_status'][1] === 2, 'Kostal: übernommen, Totmann im Gerät unbekannt (0, er steht in der Weboberfläche), aktiv (1080 = 2)');
$mb = new SimKostal(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => -1500]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [['multi', 1034, $pk(1500.0)]], 'Kostal: −1500 W (Entladen) = +1500 W');
$mb = new SimKostal(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 3000]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [['multi', 1034, $pk(0.0)]] && $hub->warn === [], 'Kostal: Halten schreibt 0 W, auch bei gemerkter Leistung');
$mb = new SimKostal(2, 3000.0, 2000.0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3000, 'ctl_rc_w' => 9000]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [['multi', 1034, $pk(-3000.0)]], 'Kostal: Laden über der Akkugrenze 1076 auf −3000 W begrenzt');
$mb = new SimKostal(2, 3000.0, 2000.0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3000, 'ctl_rc_w' => -9000]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [['multi', 1034, $pk(2000.0)]], 'Kostal: Entladen über der Akkugrenze 1078 auf +2000 W begrenzt');
$mb = new SimKostal(2, 5000.0, 5000.0, true); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 1234, '_kostalSwap' => true]); $hub->props['_kostalSwap'] = true; $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [['multi', 1034, $pk(-1234.0, true)]] && $hub->warn === [], 'Kostal: Byte-Reihenfolge CDAB (Wort-Swap) der Instanz wird beim Schreiben und Lesen beachtet');
$mb = new SimKostal(); $hub = hubFor(['rc_version' => 1, 'ctl_rc_mode' => 2]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [] && $hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 0 && $hub->vars['rc_failsafe_eff_sec'][1] === 0, 'Kostal: Modus 0 schreibt nichts (das Gerät übernimmt nach dem Zeitgeber der Weboberfläche)');
foreach ([0, 1] as $m) {
    $mb = new SimKostal($m); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2);
    ref_ok($mb->writes === [] && count($hub->warn) === 1 && strpos($hub->warn[0], '1080') !== false && !isset($hub->vars['ctl_rc_mode']), "Kostal: 1080 = $m (externes Batteriemanagement nicht über Modbus): nichts geschrieben, Warnung nennt Register 1080");
}
$mb = new SimKostal(0); $hub = hubFor(); $kostal->readSlow($mb, $hub);
ref_ok($hub->vars['rc_available'][1] === false && $hub->vars['rc_version'][1] === 0, 'Kostal: langsamer Zyklus meldet bei 1080 = 0 „nicht verfügbar“');
$mb = new SimKostal(); $mb->ignoreRegs = [1034]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 1000]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], '1034') !== false && !isset($hub->vars['ctl_rc_mode']) && count($mb->writes) === 2, 'Kostal: Gerät übernimmt den Sollwert nicht: zweiter Versuch, Warnung nennt Register 1034');
$mb = new SimKostal(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 5000, 'ctl_rc_w' => 100]); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 2); $kostal->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(array_unique($mb->order) === [1034] && !in_array(1042, $mb->order, true) && !in_array(1280, $mb->order, true) && !in_array(1038, $mb->order, true) && !in_array(1040, $mb->order, true), 'Kostal: es wird nur Register 1034 geschrieben, nie Minimum-SOC 1042, Grenzen 1038/1040 oder die Batteriebegrenzung 1280 ff.');
$a = new IHUB_RemoteKostal();
ref_ok($a->verify(['regs' => [0, 0], 'status' => 0, 'sp' => -1000.0], ['mode' => 2, 'watts' => 1000], ['maxCharge' => 5000.0]) !== '' && $a->verify(['regs' => [2, -10000], 'status' => 2, 'sp' => -1000.0], ['mode' => 2, 'watts' => 1000], ['maxCharge' => 5000.0]) === '' && $a->verify(['regs' => [2, 0], 'status' => 2, 'sp' => 0.0], ['mode' => 2, 'watts' => 1000], []) !== '', 'Kostal: verify erkennt verlorenes externes Management (1080) und falschen Sollwert');
ref_ok($a->isActive(2) === true && $a->isActive(0) === false && $a->isActive(1) === false, 'Kostal: aktiv nur bei 1080 = 2');
ref_ok($a->validate(['mode' => 3, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['available' => true, 'mode' => 2]) !== '' && $a->validate(['mode' => 2, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['available' => true, 'mode' => 2]) === '', 'Kostal: Modus 3 wird vom Adapter selbst abgelehnt, Modus 2 angenommen');
ref_ok(IHUB_RemoteKostal::devicePower(2, 2000, null, null) === -2000.0 && IHUB_RemoteKostal::devicePower(2, -2000, null, null) === 2000.0 && IHUB_RemoteKostal::devicePower(1, 2000, 5000.0, 5000.0) === 0.0 && IHUB_RemoteKostal::devicePower(0, 2000, 5000.0, 5000.0) === 0.0, 'Kostal: devicePower je Modus (Vorzeichen gedreht, Halten und Aus 0)');
$sg = $kostal->getOptionalGroups();
ref_ok(isset($sg['GroupControl']) && ($sg['GroupControl']['default'] ?? true) === false && ($sg['GroupPV']['default'] ?? true) === true, 'Kostal: Gruppe Steuerung startet aus, die übrigen Gruppen bleiben an');
$i = $a->info();
ref_ok($i['tested'] === false && count($i['pitfalls']) >= 10 && array_filter($i['pitfalls'], fn($s) => strpos($s, '1042') !== false) && array_filter($i['pitfalls'], fn($s) => strpos($s, '1280') !== false), 'Kostal: Kennzeichnung ungetestet, Fallen nennen Minimum-SOC 1042 und Batteriebegrenzung 1280');

echo "\n== Fronius: Model 124, Fenster, Totmann ==\n";
$B = SimFronius::BASE;
$mb = new SimFronius(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => 1650, 'ctl_rc_failsafe_sec' => 90]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->order === [$B + 13, $B + 10, $B + 3], 'Fronius: Reihenfolge Rückfallzeit (RvrtTms), beide Grenzen in einem Block (OutWRte/InWRte), StorCtl_Mod zuletzt');
ref_ok($mb->writes[0] === ['multi', $B + 13, [90]] && $mb->writes[1] === ['multi', $B + 10, [(-5000) & 0xFFFF, 5000]] && $mb->writes[2] === ['multi', $B + 3, [3]], 'Fronius: +1650 W an 3300 W = 50 % Laden: OutWRte −5000, InWRte +5000 (SF −2), Mod = 3, Rückfallzeit 90 s');
ref_ok($hub->warn === [] && $hub->vars['rc_failsafe_eff_sec'][1] === 90 && $hub->vars['rc_active'][1] === true && $mb->reg(13) === 90, 'Fronius: übernommen, Rückfallzeit im Gerät 90 s, aktiv');
$n = count($mb->writes); $hub->SetVarInt('ctl_rc_w', 330); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($mb->writes) === $n + 1 && $mb->writes[$n] === ['multi', $B + 10, [(-1000) & 0xFFFF, 1000]], 'Fronius: zweiter Zyklus schreibt nur den Grenzenblock (hält den Zeitgeber wach), Rückfallzeit und Mod stehen schon');
$mb = new SimFronius(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => -3300]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[1][2] === [10000, (-10000) & 0xFFFF] && $mb->writes[0][2] === [120], 'Fronius: −3300 W (Entladen, 100 %): OutWRte +10000, InWRte −10000; Rückfallzeit 0 = Vorgabe 120 s');
$mb = new SimFronius(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => 2000]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes[1][2] === [0, 0] && $mb->writes[2][2] === [3] && $hub->warn === [], 'Fronius: Halten = beide Grenzen 0 % mit Mod 3, auch bei gemerkter Leistung');
$mb = new SimFronius(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => 40000]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[1][2] === [(-10000) & 0xFFFF, 10000], 'Fronius: Leistung über WChaMax auf 100 % begrenzt');
$mb = new SimFronius(3300, 0, -1); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => 1650]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes[1][2] === [(-500) & 0xFFFF, 500], 'Fronius: Skalierung InOutWRte_SF −1 wird gelesen: 50 % = 500');
$mb = new SimFronius(330, 1, -2); $hub = hubFor(['ctl_rc_w' => 1650]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($hub->warn === [] && $mb->writes[1][2] === [(-5000) & 0xFFFF, 5000], 'Fronius: WChaMax 330 mit SF 1 = 3300 W (Skalierung gelesen), 50 % Laden');
foreach ([[5, 10], [99999, 28800]] as [$in, $outv]) {
    $mb = new SimFronius(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_failsafe_sec' => $in]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
    ref_ok($mb->writes[0] === ['multi', $B + 13, [$outv]], "Fronius: Rückfallzeit $in s wird auf $outv s begrenzt");
}
$mb = new SimFronius(); $mb->regs[3][$B + 3] = 3; $mb->regs[3][$B + 13] = 120; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_mode' => 1]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->order === [$B + 10, $B + 3] && $mb->writes[0][2] === [10000, 10000] && $mb->writes[1][2] === [0] && $hub->vars['ctl_rc_mode'][1] === 0 && $hub->vars['rc_active'][1] === false, 'Fronius: Modus 0 stellt beide Grenzen auf 100 % und Mod auf 0 (Automatik), nicht mehr aktiv');
$mb = new SimFronius(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 0);
ref_ok($mb->writes === [] && $hub->warn === [] && $hub->vars['ctl_rc_mode'][1] === 0, 'Fronius: Modus 0 bei schon hergestellter Automatik: nichts geschrieben');
$mb = new SimFronius(0); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), 'Fronius: WChaMax 0 (kein Speicher): nichts geschrieben, Warnung');
$mb = new SimFronius(); $mb->modelPresent = false; $mb = new SimFronius(); $mb->regs[3][40012] = 0xFFFF; $mb->regs[3][40013] = 0; $hub = hubFor(['rc_version' => 1]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [] && count($hub->warn) === 1, 'Fronius: Model 124 nicht in der Kette: nichts geschrieben, Warnung');
$mb = new SimFronius(); $mb->ignoreRegs = [$B + 10]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => 1000]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], (string)($B + 10)) !== false && !isset($hub->vars['ctl_rc_mode']), 'Fronius: Gerät übernimmt die Grenzen nicht (Empfehlung, Priorität): Warnung nennt das Register');
$mb = new SimFronius(); $mb->ignoreRegs = [$B + 13]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(count($hub->warn) === 1 && strpos($hub->warn[0], (string)($B + 13)) !== false, 'Fronius: Rückfallzeit nicht übernommen (Totmann bliebe aus): Warnung nennt das Register');
$mb = new SimFronius(); $mb->failWrite = [$B + 10]; $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(count($hub->warn) === 1 && !in_array($B + 3, $mb->order, true), 'Fronius: Schreiben der Grenzen scheitert (z. B. Steuerung über Modbus aus): Mod wird nicht mehr geschrieben, Warnung');
$mb = new SimFronius(); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => 500]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok(array_diff($mb->order, [$B + 3, $B + 10, $B + 13]) === [] && !in_array($B + 15, $mb->order, true) && !in_array($B + 7, $mb->order, true), 'Fronius: nur RvrtTms, OutWRte/InWRte und StorCtl_Mod werden geschrieben, nie ChaGriSet oder MinRsvPct');
$mb = new SimFronius(3300, -32768, -2); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 1);
ref_ok($mb->writes === [] && count($hub->warn) === 1 && !isset($hub->vars['ctl_rc_mode']), 'Fronius: unplausibler Skalierungsfaktor (0x8000): Modell nicht benutzt, nichts geschrieben, Warnung');
$mb = new SimFronius(3300, 0, -32768); $hub = hubFor(['rc_version' => 1, 'rc_rated_w' => 3300, 'ctl_rc_w' => 100]); $fronius->writeControl($mb, $hub, 'ctl_rc_mode', 2);
ref_ok($mb->writes === [] && count($hub->warn) === 1, 'Fronius: unplausibler Skalierungsfaktor der Grenzen (0x8000): nichts geschrieben, Warnung');
$a = new IHUB_RemoteFronius();
ref_ok($a->validate(['mode' => 2, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['available' => true, 'ratedW' => 3300]) !== '' && $a->validate(['mode' => 3, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['available' => true, 'base' => 1, 'sf' => -2, 'ratedW' => 3300]) !== '' && $a->validate(['mode' => 2, 'watts' => 0, 'failsafeSec' => 0, 'ext' => []], ['available' => true, 'base' => 1, 'sf' => -2, 'ratedW' => 3300]) === '', 'Fronius: ohne gelesene Adresse oder mit Modus 3 abgelehnt, sonst angenommen');
ref_ok(IHUB_RemoteFronius::limits(2, 825, 3300, -2) === [-2500, 2500] && IHUB_RemoteFronius::limits(2, -825, 3300, -2) === [2500, -2500] && IHUB_RemoteFronius::limits(1, 5000, 3300, -2) === [0, 0] && IHUB_RemoteFronius::limits(0, 5000, 3300, -2) === [10000, 10000], 'Fronius: limits() je Modus und Vorzeichen (Laden Out negativ/In positiv, Entladen umgekehrt, Halten 0, Aus 100 %)');
$sg = $fronius->getOptionalGroups();
ref_ok(isset($sg['GroupControl']) && ($sg['GroupControl']['default'] ?? true) === false && ($sg['GroupPV']['default'] ?? true) === true, 'Fronius: Gruppe Steuerung startet aus, die übrigen Gruppen bleiben an');
$i = $a->info();
ref_ok($i['tested'] === false && count($i['pitfalls']) >= 8 && array_filter($i['pitfalls'], fn($s) => strpos($s, 'RvrtTms') !== false), 'Fronius: Kennzeichnung ungetestet, Fallen nennen die Rückfallzeit');

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

echo "\n== Vertrag IHUB_GetFunctions 1.10: remoteControl ==\n";
$GLOBALS['TREE'] = [];
function IPS_GetChildrenIDs($p) { return $p === 1 ? array_values(array_map(function ($v) { return $v[0]; }, $GLOBALS['TREE'])) : []; }
function IPS_GetObject($id) { foreach ($GLOBALS['TREE'] as $ident => $v) { if ($v[0] === $id) { return ['ObjectIdent' => $ident, 'ObjectType' => 2]; } } return ['ObjectIdent' => '', 'ObjectType' => 2]; }
function IPS_ObjectExists($id) { return true; }
function GetValueInteger($id) { return $GLOBALS['TESTVAL'][$id] ?? 0; }
class RcHub extends InverterHub
{
    public $maker = 'solis';
    public function __construct() { $this->InstanceID = 1; }
    public function ReadPropertyString($n) { return $n === 'Manufacturer' ? $this->maker : 'ems'; }
    public function ReadPropertyBoolean($n) { return false; }
    public function ReadPropertyInteger($n) { return 0; }
}
foreach (['solis' => $solis, 'foxess_smart' => $fox, 'deye' => $deye, 'solax' => $solax, 'sma' => $real, 'fronius' => $fronius, 'kostal' => $kostal, 'huawei' => $huawei, 'sungrow' => $sungrow, 'victron' => $victron] as $maker => $drv) {
    $gg = $drv->getOptionalGroups(); $idents = array_column(array_merge($gg['GroupControl']['vars'] ?? [], $gg['GroupRemote']['vars'] ?? []), 0);
    $GLOBALS['TREE'] = []; $i = 100; foreach (IHUB_RemoteControl::vars() as $v) { if (in_array($v[0], $idents, true)) { $GLOBALS['TREE'][$v[0]] = [$i++]; } }
    $GLOBALS['TESTVAL'] = [$GLOBALS['TREE']['rc_failsafe_decl_sec'][0] => 90];
    $h = new RcHub(); $h->maker = $maker; $f = $h->GetFunctions();
    $rc = $f['remoteControl'];
    ref_ok($f['contractType'] === 'inverter' && $rc['failsafeSec'] === 90 && $f['failsafeSec'] === 90 && $f['failsafeMode'] === 'deadman' && $rc['failsafeMode'] === 'deadman', "$maker: contractType inverter, failsafeSec 90 (Eintrag und remoteControl) und failsafeMode deadman");
    ref_ok($f['contractVersion'] === '1.10' && $rc['modeID'] === $GLOBALS['TREE']['ctl_rc_mode'][0] && $rc['powerID'] === $GLOBALS['TREE']['ctl_rc_w'][0] && $rc['availableID'] === $GLOBALS['TREE']['rc_available'][0] && $rc['testedID'] === $GLOBALS['TREE']['rc_tested'][0] && $rc['vendorID'] === $GLOBALS['TREE']['rc_vendor'][0] && $rc['modesID'] === $GLOBALS['TREE']['rc_modes'][0] && $rc['failsafeSecID'] === $GLOBALS['TREE']['ctl_rc_failsafe_sec'][0] && $f['controllable'] === true, "$maker: remoteControl mit allen IDs, Vertrag 1.10, controllable");
}
$GLOBALS['TREE'] = ['pv_total' => [101]]; $h = new RcHub(); $f = $h->GetFunctions();
ref_ok($f['remoteControl'] === [], 'Treiber ohne Fernsteuerung: leeres Feld');
$missing = [];
foreach (['solis' => $solis, 'foxess_smart' => $fox, 'deye' => $deye, 'solax' => $solax, 'sma' => $real, 'fronius' => $fronius, 'kostal' => $kostal, 'huawei' => $huawei, 'sungrow' => $sungrow, 'victron' => $victron] as $maker => $drv) {
    $prof = array_merge(array_keys($drv->getProfiles()), array_keys($drv->getEnumProfiles()));
    foreach (array_merge($drv->getOptionalGroups()['GroupControl']['vars'] ?? [], $drv->getOptionalGroups()['GroupRemote']['vars'] ?? []) as $v) { if ($v[3] !== '' && $v[3][0] !== '~' && !in_array($v[3], $prof, true)) { $missing[] = "$maker:" . $v[0] . '=' . $v[3]; } }
}
ref_ok($missing === [], 'jedes benutzte Profil der Fernsteuerung ist je Treiber definiert' . ($missing ? ': ' . implode(',', $missing) : ''));
$fox2 = (new IHUB_FoxEssSmartDriver())->getOptionalGroups();
ref_ok(isset($fox2['GroupControl']) && count(array_column($fox2['GroupControl']['vars'], 0)) === count(IHUB_RemoteControl::vars()), 'FoxESS: eigene Gruppe Steuerung mit genau den gemeinsamen Variablen (keine weiteren Schreibwege)');
ref_ok(($fox2['GroupControl']['default'] ?? true) === false && strpos(file_get_contents(dirname(__DIR__) . '/InverterHub/module.php'), "\$group['default'] ?? true") !== false, 'FoxESS: Gruppe Steuerung startet aus (ungetestet), das Hauptmodul wertet default aus; alle anderen Gruppen weiter an');

ref_finish('gemeinsame Fernsteuerung');
