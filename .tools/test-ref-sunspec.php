<?php
/**
 * Referenzlauf SMA und Fronius (SunSpec), 18.11.2026, Code-Bestandsaufnahme Schritt 1.
 * Haelt das heutige Verhalten von readFast/readSlow/readDeviceInfo fest. Am Treiber wurde nichts geaendert.
 *
 *   php .tools/test-ref-sunspec.php                 # Vergleich mit .tools/ref/sma.json und fronius.json
 *   UPDATE_REF=1 php .tools/test-ref-sunspec.php    # Referenz bewusst neu schreiben
 *
 * Bekannte Grenze (nur notiert, nichts geaendert): Der Fronius-Treiber erzeugt fuer Zaehler-Unit-ID,
 * Phasen und Zaehlerenergie je einen eigenen IHUB_ModbusTcpClient (echte TCP-Verbindung, nicht einspeisbar).
 * Im Test ist dort keine Antwort moeglich, beobachtbar ist nur der Rueckfall auf die eigene SunSpec-Kette.
 */
require_once __DIR__ . '/ref/harness.php';

/** SunSpec-Kette ab 40000: Kennung "SunS", Modelle hintereinander, Endemarke 0xFFFF. */
function ss_chain(RefMb $mb, ?int $unit, array $models): void
{
    $put = function (int $addr, array $vals) use ($mb, $unit) { $unit === null ? $mb->set(3, $addr, $vals) : $mb->setU($unit, 3, $addr, $vals); };
    $put(40000, [0x5375, 0x6E53]);
    $addr = 40002;
    foreach ($models as $m) {
        $put($addr, [$m['id'], $m['len']]);
        $data = $addr + 2;
        $vals = array_fill(0, $m['len'], null);
        foreach ($m['set'] ?? [] as $off => $v) { $vals[$off] = $v; }
        foreach ($vals as $i => $v) { if ($v === null) { $vals[$i] = 100 + ((($data + $i) * 37 + 13) % 3900); } }
        $put($data, $vals);
        $addr += 2 + $m['len'];
    }
    $put($addr, [0xFFFF, 0]);
}
function s16(int $v): int { return $v & 0xFFFF; }
function put32(array &$set, int $off, int $v): void { [$set[$off], $set[$off + 1]] = RefMb::w32($v); }
function putf(array &$set, int $off, float $f): void { [$set[$off], $set[$off + 1]] = RefMb::f32($f); }
function str16(string $s, int $regs): array
{
    $s = str_pad($s, $regs * 2, "\0"); $o = [];
    for ($i = 0; $i < $regs; $i++) { $o[] = (ord($s[$i * 2]) << 8) | ord($s[$i * 2 + 1]); }
    return $o;
}
function run(string $file, string $label, $driver, RefMb $mb, RefHub $hub, string $method = 'readFast'): array
{
    $ret = $driver->$method($mb, $hub);
    $res = ref_result($hub, $mb, ['return' => $ret, 'unitId' => $mb->unitId]);
    ref_check($file, $label, $res);
    return $res;
}

// ------------------------------------------------------------------ SMA
echo "SMA readFast\n";
$sma = new IHUB_SmaDriver;

/** Int+SF-Inverterkette (Model 103) plus Common (1) und Meter 203. */
function sma_int_models(array $over = []): array
{
    $inv = [0 => 57, 4 => s16(-2), 8 => 2390, 11 => s16(-1), 12 => 1370, 13 => 0, 14 => 5001, 15 => s16(-2), 24 => 0, 29 => 0x8000, 30 => 0, 31 => 355, 35 => s16(-1), 36 => 4];
    put32($inv, 22, 123456);
    foreach ($over as $k => $v) { $inv[$k] = $v; }
    $meter = [16 => s16(-820), 20 => 0];
    return [['id' => 1, 'len' => 66, 'set' => []], ['id' => 103, 'len' => 50, 'set' => $inv], ['id' => 203, 'len' => 105, 'set' => $meter]];
}
/** SMA-Eigenprofil (Input-Register) mit Batterie. */
function sma_profile(RefMb $mb, int $unit, array $o = []): void
{
    $in = function (int $addr, array $v) use ($mb, $unit) { $mb->setU($unit, 4, $addr, $v); };
    $in(30775, RefMb::w32($o['ac'] ?? 1370));
    $in(30225, RefMb::w32($o['riso'] ?? 2500000));
    if (!isset($o['noDc'])) { $in(30773, RefMb::w32($o['dcA'] ?? 900)); $in(30961, RefMb::w32($o['dcB'] ?? 480)); }
    if (!isset($o['noSoc'])) {
        $in(30845, RefMb::w32($o['soc'] ?? 55)); $in(30849, RefMb::w32($o['temp'] ?? 215)); $in(30851, RefMb::w32($o['volt'] ?? 5120));
    }
    if (!isset($o['noBatPwr'])) { $in(31393, array_merge(RefMb::w32($o['chg'] ?? 0), RefMb::w32($o['dis'] ?? 600))); }
}

// 1) Typischer Aufbau: Geraete-Unit 3, SunSpec-Kette auf 126 (= 3 + 123), Eigenprofil auf 3, Batterie
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3);
$res = run('sma', 'Int+SF, Unit 3 mit Retry +123, Batterie', $sma, $mb, new RefHub());
ref_ok($res['unitId'] === 126, 'SMA Unit-ID: nach readFast steht die SunSpec-Kennung 126 (3+123) an');
// 2) Kennung direkt als 126 eingetragen: Geraete-ID wird sondiert (126 - 123 = 3)
$mb = new RefMb(126); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3);
run('sma', 'Int+SF, Unit 126 direkt, Geraete-ID per Sondierung', $sma, $mb, new RefHub());
// 3) Ohne Batterie, ohne DC-Register: PV wird aus der AC-Leistung abgeleitet (Status 4)
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3, ['noDc' => 1, 'noSoc' => 1, 'noBatPwr' => 1]);
run('sma', 'Int+SF ohne Batterie, PV aus AC abgeleitet', $sma, $mb, new RefHub());
// 4) Batterie vorhanden (SOC), Leistung unbekannt: PV 0
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3, ['noDc' => 1, 'noBatPwr' => 1]);
run('sma', 'Int+SF Batterie ohne Leistung, PV 0', $sma, $mb, new RefHub());
// 5) Batterie mit Leistung, PV = AC + Ladeleistung (Entladen negativ)
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3, ['noDc' => 1, 'chg' => 300, 'dis' => 0]);
run('sma', 'Int+SF Batterie laedt, PV = AC + Laden', $sma, $mb, new RefHub());
// 6) Sentinel 0xFFFFFFFF und 0x80000000 bei den DC-Eingaengen
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3, ['dcA' => 0xFFFFFFFF, 'dcB' => 0x80000000, 'noSoc' => 1, 'noBatPwr' => 1]);
run('sma', 'DC-Sentinel -> 0 W je Eingang, PV abgeleitet', $sma, $mb, new RefHub());
// 7) Batterietemperatur-Sentinel 0x7FFFFFFF
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3, ['temp' => 0x7FFFFFFF, 'volt' => 0x80000000]);
run('sma', 'Batterie-Temperatur/Spannung Sentinel', $sma, $mb, new RefHub());
// 8) Float-Modell 111
$f = []; putf($f, 0, 12.5); putf($f, 14, 231.4); putf($f, 20, 2450.0); putf($f, 22, 50.01); putf($f, 30, 4321000.0); putf($f, 36, 2600.0); putf($f, 38, 41.5); $f[46] = 4;
$mb = new RefMb(3); ss_chain($mb, 126, [['id' => 1, 'len' => 66], ['id' => 111, 'len' => 60, 'set' => $f]]); sma_profile($mb, 3, ['noDc' => 1]);
run('sma', 'Float-Modell 111, DCW aus SunSpec', $sma, $mb, new RefHub());
// 9) Kein SunSpec auf keiner Unit
$mb = new RefMb(3); sma_profile($mb, 3);
$res = run('sma', 'keine SunSpec-Kette', $sma, $mb, new RefHub());
ref_ok($res['unitId'] === 3, 'SMA ohne SunSpec: Unit-ID bleibt auf dem eingetragenen Wert');
// 10) Block lauter 0xFFFF
$mb = new RefMb(3); ss_chain($mb, 126, [['id' => 1, 'len' => 66], ['id' => 103, 'len' => 50, 'set' => array_fill(0, 50, 0xFFFF)]]); sma_profile($mb, 3);
run('sma', 'Inverterblock lauter 0xFFFF', $sma, $mb, new RefHub());
// 11) Gruppen aus
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models()); sma_profile($mb, 3);
run('sma', 'Int+SF alle Gruppen aus', $sma, $mb, new RefHub(['GroupGrid' => false, 'GroupEnergy' => false, 'GroupTemp' => false, 'GroupMppt' => false, 'GroupMeter' => false, 'GroupBat' => false, 'GroupDevice' => false]));
// 12) Nicht implementierte Int+SF-Felder (0xFFFF bzw. 0x8000)
$mb = new RefMb(3); ss_chain($mb, 126, sma_int_models([12 => 0x8000, 0 => 0xFFFF, 8 => 0xFFFF, 14 => 0xFFFF, 24 => 0x8000, 31 => 0x8000])); sma_profile($mb, 3, ['noDc' => 1]);
run('sma', 'Int+SF nicht implementierte Felder', $sma, $mb, new RefHub());
// 13) Geraeteinformation
$com = []; foreach (str16('SMA', 16) as $i => $v) { $com[$i] = $v; } foreach (str16('Sunny Tripower 6.0', 16) as $i => $v) { $com[16 + $i] = $v; } foreach (str16('3019999999', 16) as $i => $v) { $com[48 + $i] = $v; }
$mb = new RefMb(3); ss_chain($mb, 126, [['id' => 1, 'len' => 66, 'set' => $com]]);
run('sma', 'readDeviceInfo mit Retry +123', $sma, $mb, new RefHub(), 'readDeviceInfo');
$mb = new RefMb(3); ss_chain($mb, 126, [['id' => 1, 'len' => 66, 'set' => $com]]); $mb->regsByUnit[126][3] = [];
run('sma', 'readDeviceInfo ohne Common-Block', $sma, $mb, new RefHub(), 'readDeviceInfo');
// 14) writeControl und readSlow tun nichts
$mb = new RefMb(3); $hub = new RefHub(); $sma->writeControl($mb, $hub, 'ctl_x', 1); $sma->readSlow($mb, $hub);
ref_ok($mb->writes === [] && $hub->vars === [], 'SMA writeControl/readSlow: keine Schreibzugriffe, keine Variablen');

// ------------------------------------------------------------------ Fronius
echo "Fronius readFast\n";
$fro = new IHUB_FroniusDriver;

function fro_models(string $kind = 'float', array $mo = []): array
{
    $inv = [];
    if ($kind === 'float') {
        putf($inv, 0, 8.2); putf($inv, 14, 230.2); putf($inv, 20, 3650.0); putf($inv, 22, 49.98); putf($inv, 30, 8765000.0); $inv[46] = 4;
        $invm = ['id' => 113, 'len' => 60, 'set' => $inv];
    } else {
        $inv = [0 => 82, 4 => s16(-1), 8 => 2302, 11 => s16(-1), 12 => 3650, 13 => 0, 14 => 4998, 15 => s16(-2), 24 => 0, 36 => 4];
        put32($inv, 22, 8765000);
        foreach ($mo['inv'] ?? [] as $k => $v) { $inv[$k] = $v; }
        $invm = ['id' => 103, 'len' => 50, 'set' => $inv];
    }
    $m160 = [0 => s16(-2), 1 => s16(-1), 2 => 0, 3 => 0, 6 => 4];
    $mod = [[9 => 520, 10 => 3921, 11 => 2041], [9 => 410, 10 => 3800, 11 => 1558], [9 => 0, 10 => 4100, 11 => 0xFFFF], [9 => 300, 10 => 4100, 11 => 1200]];
    foreach ($mod as $n => $vals) { foreach ($vals as $o => $v) { $m160[8 + $n * 20 + $o] = $v; } }
    $m124 = [6 => 5500, 20 => s16(-2)];
    $m122 = [42 => 8000, 43 => 3];
    $m203 = [1 => 31, 2 => 32, 3 => 33, 4 => s16(-1), 6 => 2301, 7 => 2302, 8 => 2303, 13 => s16(-1), 16 => 450, 17 => 150, 18 => 151, 19 => 149, 20 => 0];
    put32($m203, 36, 120000); put32($m203, 44, 340000); $m203[52] = s16(0);
    $models = [['id' => 1, 'len' => 66], $invm];
    if (!isset($mo['no160'])) { $models[] = ['id' => 160, 'len' => 88, 'set' => $m160]; }
    $models[] = ['id' => 122, 'len' => 44, 'set' => $m122];
    $models[] = ['id' => 124, 'len' => 24, 'set' => $m124];
    if (!isset($mo['noMeter'])) { $models[] = ['id' => 203, 'len' => 105, 'set' => $m203]; }
    return $models;
}
$all = ['GroupGrid' => true, 'GroupEnergy' => true, 'GroupPV' => true, 'GroupBat' => true, 'GroupDevice' => true, 'GroupMeter' => true, 'GroupMeterPhases' => true, 'GroupMeterEnergy' => true];

$mb = new RefMb(1); ss_chain($mb, null, fro_models('float'));
run('fronius', 'Float-Modell 113, MPPT/Speicher/SOC/Riso/Zaehler (eigene Kette)', $fro, $mb, new RefHub($all));
$mb = new RefMb(1); ss_chain($mb, null, fro_models('int'));
run('fronius', 'Int+SF Modell 103', $fro, $mb, new RefHub($all));
$mb = new RefMb(1); ss_chain($mb, null, fro_models('int', ['inv' => [12 => 0x8000, 0 => 0xFFFF, 8 => 0xFFFF, 14 => 0xFFFF, 24 => 0x8000]]));
run('fronius', 'Int+SF nicht implementierte Felder', $fro, $mb, new RefHub($all));
$mb = new RefMb(1); ss_chain($mb, null, fro_models('float', ['no160' => 1, 'noMeter' => 1]));
run('fronius', 'Float ohne MPPT-Modell und ohne Zaehler', $fro, $mb, new RefHub($all));
$mb = new RefMb(1); ss_chain($mb, null, fro_models('float'));
run('fronius', 'Float nur Kerngruppen (alle optionalen aus)', $fro, $mb, new RefHub(['GroupGrid' => false, 'GroupEnergy' => false, 'GroupPV' => false, 'GroupBat' => false, 'GroupDevice' => false, 'GroupMeter' => false, 'GroupMeterPhases' => false, 'GroupMeterEnergy' => false]));
// Float-Zaehler 213 statt Int-Zaehler
$m213 = []; putf($m213, 26, -310.0); putf($m213, 10, 230.5); putf($m213, 12, 231.5); putf($m213, 14, 232.5); putf($m213, 2, 1.1); putf($m213, 4, 1.2); putf($m213, 6, 1.3); putf($m213, 28, 100.0); putf($m213, 30, 110.0); putf($m213, 32, 120.0); putf($m213, 58, 5000000.0); putf($m213, 66, 7000000.0);
$models = array_filter(fro_models('float'), function ($m) { return $m['id'] !== 203; }); $models[] = ['id' => 213, 'len' => 124, 'set' => $m213];
$mb = new RefMb(1); ss_chain($mb, null, array_values($models));
run('fronius', 'Float-Zaehler Modell 213', $fro, $mb, new RefHub($all));
// Keine SunSpec-Kette / Block lauter 0xFFFF
$mb = new RefMb(1);
run('fronius', 'keine SunSpec-Kette', $fro, $mb, new RefHub($all));
$mb = new RefMb(1); ss_chain($mb, null, [['id' => 1, 'len' => 66], ['id' => 113, 'len' => 60, 'set' => array_fill(0, 60, 0xFFFF)]]);
run('fronius', 'Inverterblock lauter 0xFFFF', $fro, $mb, new RefHub($all));
// readSlow (Riso) und readDeviceInfo
$mb = new RefMb(1); ss_chain($mb, null, fro_models('float'));
run('fronius', 'readSlow Isolationswiderstand', $fro, $mb, new RefHub($all), 'readSlow');
$mb = new RefMb(1); ss_chain($mb, null, fro_models('float'));
run('fronius', 'readSlow ohne GroupDevice', $fro, $mb, new RefHub(['GroupDevice' => false]), 'readSlow');
$mb = new RefMb(1); ss_chain($mb, null, [['id' => 1, 'len' => 66, 'set' => $com], ['id' => 122, 'len' => 40]]);
run('fronius', 'readSlow Modell 122 zu kurz', $fro, $mb, new RefHub($all), 'readSlow');
$mb = new RefMb(1); ss_chain($mb, null, [['id' => 1, 'len' => 66, 'set' => $com]]);
run('fronius', 'readDeviceInfo', $fro, $mb, new RefHub(), 'readDeviceInfo');
$mb = new RefMb(1); $hub = new RefHub(); $fro->writeControl($mb, $hub, 'ctl_x', 1);
ref_ok($mb->writes === [] && $hub->vars === [], 'Fronius writeControl: keine Schreibzugriffe');

// Beobachtung festhalten: Isolationswiderstand wird in jedem schnellen Zyklus gelesen, weil $invStatus nie gesetzt wird.
$mb = new RefMb(1); ss_chain($mb, null, fro_models('float')); $hub = new RefHub($all); $fro->readFast($mb, $hub);
ref_ok(isset($hub->vars['riso']), 'Fronius (Beobachtung): riso wird im schnellen Zyklus gelesen, obwohl der Wechselrichter einspeist (Status 4); die Variable $invStatus ist im Quelltext nirgends zugewiesen');

ref_finish('SMA und Fronius');
