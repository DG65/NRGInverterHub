<?php
// Prueft IHUB_SolisDriver: Vorzeichen von Batterieleistung/-strom (Rohwert vorzeichenbehaftet,
// positiv = Laden; Richtungsregister 33135) und die Skalierung der Energiezaehler
// (Gesamtstaende 1 kWh, Tageswerte 0,1 kWh). Anlass: Abgleich mit dem Solis-Portal, 17.10.2026.
// Aufruf: php .tools/test-solis.php

$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }

$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
if (!preg_match('/(class IHUB_SolisDriver implements IHUB_InverterDriverInterface\s*\{.*?\n\})\n\n\/\/ -{20,}\n\/\/ IHUB_GrowattDriver/s', $src, $m)) {
    echo "FAIL Treiberklasse nicht gefunden\n";
    exit(1);
}
define('VARIABLETYPE_FLOAT', 2);
interface IHUB_InverterDriverInterface {}
$GLOBALS['solisSrc'] = $m[1];
eval($m[1]);

class MockMb
{
    public $regs = [];
    public $writes = [];
    public $failWrites = false;
    public function writeSingle($reg, $val) { if ($this->failWrites) { return false; } $this->writes[] = [$reg, $val]; return true; }
    public function readHolding($start, $count)
    {
        return $this->readInput($start, $count);
    }
    public function readInput($start, $count)
    {
        $out = [];
        for ($a = $start; $a < $start + $count; $a++) { $out[] = $this->regs[$a] ?? 0; }
        return $out;
    }
    public function u16($r, $o) { return isset($r[$o]) ? ($r[$o] & 0xFFFF) : 0; }
    public function s16($r, $o) { $v = $this->u16($r, $o); return $v > 32767 ? $v - 65536 : $v; }
    public function u32($r, $o) { return (($this->u16($r, $o) << 16) | $this->u16($r, $o + 1)); }
    public function s32($r, $o) { $v = $this->u32($r, $o); return $v > 2147483647 ? $v - 4294967296 : $v; }
}
class MockHub
{
    public $v = [];
    public $warn = [];
    public function GetVarInt($i) { return $this->v[$i] ?? -1; }
    public function WarnUser($m) { $this->warn[] = $m; }
    public $props = [];
    public function GetPropBool($n) { return $this->props[$n] ?? true; }
    public function SetVarBool($i, $x) { $this->v[$i] = (bool)$x; }
    public function SetVarInt($i, $x) { $this->v[$i] = (int)$x; }
    public function SetVarFloat($i, $x) { $this->v[$i] = (float)$x; }
    public function SetVarStr($i, $x) { $this->v[$i] = (string)$x; }
}
function put32(&$r, $a, $v) { $v &= 0xFFFFFFFF; $r[$a] = ($v >> 16) & 0xFFFF; $r[$a + 1] = $v & 0xFFFF; }

function run($regs, $slow = false, $props = [])
{
    $d = new IHUB_SolisDriver(); $mb = new MockMb(); $mb->regs = $regs; $hub = new MockHub(); $hub->props = $props;
    $d->readFast($mb, $hub);
    if ($slow) { $d->readSlow($mb, $hub); }
    return $hub->v;
}

echo "Batterie-Vorzeichen\n";
$base = [33049 => 586, 33057 => 0, 33058 => 0, 33134 => 145, 33139 => 17, 33140 => 100, 33133 => 2101];
// Signierte Firmware, Entladen: Rohwert -2900 (so im Portal-Abgleich beobachtet), Richtung 1.
$r = $base; put32($r, 33149, -2900); $r[33135] = 1;
$v = run($r);
ok($v['bat_power'] === 2900.0, 'Rohwert -2900, Richtung 1 -> +2900 W (Entladen)');
ok(abs($v['bat_curr'] - 14.5) < 0.001, 'Batteriestrom bei Entladen positiv');
// Signierte Firmware, Entladen, Richtungsregister unzuverlaessig (0): negativer Rohwert gewinnt.
$r = $base; put32($r, 33149, -2900); $r[33135] = 0;
$v = run($r);
ok($v['bat_power'] === 2900.0, 'Rohwert negativ, Richtung 0 -> trotzdem Entladen (+2900 W)');
// Laden: Rohwert +3000, Richtung 0.
$r = $base; put32($r, 33149, 3000); $r[33135] = 0;
$v = run($r);
ok($v['bat_power'] === -3000.0, 'Rohwert +3000, Richtung 0 -> -3000 W (Laden)');
ok(abs($v['bat_curr'] - (-14.5)) < 0.001, 'Batteriestrom bei Laden negativ');
// Firmware mit vorzeichenlosem Betrag, Entladen: Rohwert +2900, Richtung 1.
$r = $base; put32($r, 33149, 2900); $r[33135] = 1;
$v = run($r);
ok($v['bat_power'] === 2900.0, 'vorzeichenloser Betrag 2900, Richtung 1 -> +2900 W (Entladen)');
// Ruhe.
$r = $base; put32($r, 33149, 0); $r[33135] = 0;
$v = run($r);
ok($v['bat_power'] == 0.0, 'Ruhe: 0 W');

echo "Zweiter Akku (Vorabversion)\n";
$r = $base; put32($r, 33149, -400); $r[33135] = 1;
$r[33097] = 588;                                              // Bit 6 gesetzt = unabhaengig
$r[34278] = 26; $r[34289] = 2126; $r[34290] = 24; $r[34291] = 1;
$v = run($r);
ok(abs($v['bat2_volt'] - 212.6) < 0.001 && abs($v['bat2_curr'] - 2.4) < 0.001 && $v['bat2_soc'] === 26, 'Akku 2: Spannung, Strom (Entladen +), SOC');
ok($v['bat2_pwr'] === 510.0, 'Akku 2: berechnete Leistung 510 W (+ = Entladen)');
ok($v['bat_power'] === 910.0, 'Bat. Leistung ist bei zwei unabhaengigen Akkus die Summe (400 + 510 W)');
$r[34291] = 0;
$v = run($r);
ok($v['bat2_curr'] < 0 && $v['bat2_pwr'] < 0, 'Akku 2 Richtung 0 = Laden (negativ)');
$r[33097] = 588 & ~0x40;                                      // parallel
$v = run($r);
ok(!isset($v['bat2_volt']) && !isset($v['bat2_pwr']) && $v['bat_power'] === 400.0, 'Parallelbetrieb (Bit 6 aus): keine Akku-2-Werte, Leistung nur Akku 1');
$r[33097] = 588;
$v = run($r, false, ['GroupBat2' => false]);
ok(!isset($v['bat2_volt']) && $v['bat_power'] === 400.0, 'Gruppe Akku 2 abgeschaltet: keine Akku-2-Werte, Leistung nur Akku 1');

echo "Einstellungen (nur lesen)\n";
$r = [33049 => 0];
$r[43010] = 100; $r[43011] = 10; $r[43012] = 1000; $r[43013] = 1000; $r[43018] = 8; $r[43024] = 80;
$r[43110] = 35; $r[43129] = 150; $r[43130] = 600; $r[43131] = 500; $r[43132] = 1; $r[43135] = 2; $r[43136] = 200; $r[43282] = 5;
$v = run($r, true);
ok($v['cfg_storage_mode'] === 35 && $v['cfg_force_mode'] === 2, 'Speicherbetrieb 35, Zwangsmodus 2 (Entladen)');
ok($v['cfg_force_charge_w'] === 2000.0 && $v['cfg_force_disch_w'] === 1500.0, 'Zwangsleistungen in W (x10)');
ok($v['cfg_charge_limit_w'] === 6000.0 && $v['cfg_disch_limit_w'] === 5000.0 && $v['cfg_grid_adjust'] === 1, 'Leistungsgrenzen und Netzabgleich');
ok($v['cfg_soc_max'] === 100 && $v['cfg_soc_min'] === 10 && $v['cfg_soc_forcecharge'] === 8 && $v['cfg_soc_backup'] === 80, 'SOC-Grenzen');
ok($v['cfg_max_charge_a'] === 100.0 && $v['cfg_max_disch_a'] === 100.0 && $v['cfg_rc_timeout_min'] === 5, 'Stromgrenzen (0,1 A) und Zeitlimit');
$v = run($r, true, ['GroupSettings' => false]);
ok(!isset($v['cfg_storage_mode']), 'Gruppe Einstellungen abgeschaltet: nichts gelesen');
// Der Treiber darf nirgends schreiben.
ok(strpos($GLOBALS['solisSrc'], 'writeMultiple') === false, 'Solis-Treiber nutzt nur Einzelschreiben (FC06)');

echo "Aufbau wie GoodWe (Stufe 1)\n";
$r = $base; put32($r, 33149, -400); $r[33135] = 1; $r[33097] = 588;
$r[33139] = 27; $r[33141] = 21060; $r[33142] = 21; $r[33143] = 1400; $r[33144] = 2016; $r[33043] = 228;
$r[34275] = 21050; $r[34276] = 24; $r[34277] = 227; $r[34278] = 26; $r[34279] = 100; $r[34281] = 1400; $r[34282] = 2016;
$r[34289] = 2126; $r[34290] = 24; $r[34291] = 1;
$v = run($r, true);
ok($v['bat1_pwr'] === 400.0 && $v['bat1_mode'] === 2 && $v['bat1_soc'] === 27, 'Akku 1: Leistung +400 W, Modus Entladen, SOC 27');
ok($v['bat2_mode'] === 2 && $v['bat2_soc'] === 26 && $v['bat2_soh'] === 100 && abs($v['bat2_temp'] - 22.7) < 0.001, 'Akku 2: Modus Entladen, SOC 26, SOH 100, Temperatur 22,7');
ok($v['bat_soc'] === 27 || $v['bat_soc'] === 26, 'Gemeinsamer SOC ist der Mittelwert (27 und 26 -> ' . $v['bat_soc'] . ')');
ok(abs($v['bat1_bms_volt'] - 210.6) < 0.001 && abs($v['bat1_bms_curr'] - 2.1) < 0.001, 'Akku 1: BMS-Spannung/-Strom (Entladen +)');
ok(abs($v['bat1_temp'] - 22.8) < 0.001, 'Akku 1: Temperatur aus 33043 (langsamer Zyklus)');
// 140 A * 213,0 V (Akku 1: 33133) + 140 A * 212,6 V (Akku 2) = 59564 W; Entladen 201,6 A analog
$expCharge = round(140.0 * 210.1 + 140.0 * 212.6);
$r2 = $r; $r2[33133] = 2101;
$v2 = run($r2, true);
ok($v2['bat_charge_max_w'] === $expCharge, 'max. Ladeleistung = Grenzstrom x Spannung beider Akkus (' . $expCharge . ' W)');
$r3 = $r; $r3[33097] = 588 & ~0x40;
$v3 = run($r3, true);
ok($v3['bat_soc'] === 27 && !isset($v3['bat2_soc']) && $v3['bat_charge_max_w'] === round(140.0 * 210.1), 'ein Akku: SOC unveraendert, max. Ladeleistung nur Akku 1');
$r4 = $base; put32($r4, 33151, 3000);
$v4 = run($r4);
ok($v4['grid_mode'] === 1, 'Netzmodus Einspeisung bei +3000 W');
put32($r4, 33151, -3000);
$v4 = run($r4);
ok($v4['grid_mode'] === 2, 'Netzmodus Bezug bei -3000 W');
put32($r4, 33151, 20);
$v4 = run($r4);
ok($v4['grid_mode'] === 0, 'Netzmodus ausgeglichen bei +20 W');

echo "Stufe 2: Meter-Phasen, Backup, Status, Fehler\n";
$r = [33049 => 0];
$r[33252] = 360; $r[33254] = 370; $r[33256] = 190;
put32($r, 33257, -1000); put32($r, 33259, -2000); put32($r, 33261, 500); put32($r, 33263, -2500);
$r[33137] = 2363; $r[33138] = 13; $r[33153] = 2362; $r[33154] = 14; $r[33155] = 2360; $r[33156] = 12;
$r[33287] = 3; $r[33111] = 1; $r[33116] = 0x1053; $r[33120] = 0x2012; $r[33125] = 0xF010;
$v = run($r);
ok($v['mt_l1_pwr'] === -1000.0 && $v['mt_l2_pwr'] === -2000.0 && $v['mt_l3_pwr'] === 500.0 && $v['mt_total_pwr'] === -2500.0, 'Meter: Leistung je Phase und gesamt (− Bezug)');
ok(abs($v['mt_l1_curr'] - 3.6) < 0.001 && abs($v['mt_l3_curr'] - 1.9) < 0.001, 'Meter: Strom je Phase (÷100)');
ok(abs($v['backup_l1_volt'] - 236.3) < 0.001 && abs($v['backup_l2_curr'] - 1.4) < 0.001 && abs($v['backup_l3_volt'] - 236.0) < 0.001, 'Backup: Spannung/Strom je Phase');
ok($v['op_status'] === 3 && $v['bms_status'] === 1, 'Betriebsstatus Netzbetrieb (3), BMS-Status 1');
ok($v['fault_code'] === 0x1053 && $v['fault_text'] === '1053, 2012, F010', 'Fehlercodes: erster Code und Textliste');
$r[33116] = 0; $r[33120] = 0; $r[33125] = 0;
$v = run($r);
ok($v['fault_code'] === 0 && $v['fault_text'] === 'OK', 'keine Fehler: Code 0, Text OK');
$v = run($r, false, ['GroupStatus' => false, 'GroupBackup' => false]);
ok(!isset($v['op_status']) && !isset($v['backup_l1_volt']), 'abgeschaltete Gruppen: nichts gelesen');

echo "Steuerung (nur durchreichen)\n";
$d = new IHUB_SolisDriver();
function ctl($d, $hub, $mb, $ident, $val) { $d->writeControl($mb, $hub, $ident, $val); }
$mb = new MockMb(); $hub = new MockHub();
ctl($d, $hub, $mb, 'ctl_force_w', 3004);
ok($hub->v['ctl_force_w'] === 3000 && $mb->writes === [], 'Leistung 3004 W -> 3000 W gemerkt, ohne aktiven Zwangsmodus nichts geschrieben');
ctl($d, $hub, $mb, 'ctl_force_mode', 1);
ok($mb->writes === [[43136, 300], [43135, 1]] && $hub->v['ctl_force_mode'] === 1, 'Laden: erst Leistung 43136=300, dann Modus 43135=1');
$mb->writes = [];
ctl($d, $hub, $mb, 'ctl_force_w', 2000);
ok($mb->writes === [[43136, 200]], 'Leistungsaenderung bei aktivem Laden: nur 43136=200');
$mb->writes = [];
ctl($d, $hub, $mb, 'ctl_force_mode', 2);
ok($mb->writes === [[43129, 200], [43135, 2]], 'Entladen: erst 43129=200, dann 43135=2');
$mb->writes = [];
ctl($d, $hub, $mb, 'ctl_force_mode', 0);
ok($mb->writes === [[43135, 0]] && $hub->v['ctl_force_mode'] === 0, 'Aus: nur 43135=0');
$mb->writes = [];
ctl($d, $hub, $mb, 'ctl_force_w', 20000);
ok($hub->v['ctl_force_w'] === 9900, 'Leistung wird auf 9900 W begrenzt');
ctl($d, $hub, $mb, 'ctl_force_w', -50);
ok($hub->v['ctl_force_w'] === 0, 'negative Leistung wird 0');
$mb->writes = []; $hub->warn = [];
ctl($d, $hub, $mb, 'ctl_force_mode', 5);
ok($mb->writes === [] && count($hub->warn) === 1, 'ungueltiger Zwangsmodus 5: nichts geschrieben, Warnung');
$mb2 = new MockMb(); $mb2->failWrites = true; $h2 = new MockHub(); $h2->v['ctl_force_w'] = 1000;
ctl($d, $h2, $mb2, 'ctl_force_mode', 1);
ok(!isset($h2->v['ctl_force_mode']) && count($h2->warn) === 1, 'Schreibfehler: Variable bleibt unveraendert, Warnung');
$mb3 = new MockMb(); $h3 = new MockHub();
ctl($d, $h3, $mb3, 'ctl_storage_mode', 37);
ok($mb3->writes === [] && count($h3->warn) === 1, 'Speicherbetrieb 37 (Inselbetrieb) wird nicht geschrieben');
ctl($d, $h3, $mb3, 'ctl_storage_mode', 35);
ok($mb3->writes === [[43110, 35]] && $h3->v['ctl_storage_mode'] === 35, 'Speicherbetrieb 35 wird geschrieben');
$mb3->writes = []; $h3->v['cfg_storage_mode'] = 35;
ctl($d, $h3, $mb3, 'ctl_storage_mode', 35);
ok($mb3->writes === [], 'unveraendert (gelesen = 35): nicht erneut geschrieben');
ok(strpos($GLOBALS['solisSrc'], 'ReassertEmsControl') === false && strpos($GLOBALS['solisSrc'], 'sleep') === false, 'keine eigene Wiederholung im Treiber');

echo "Energiezaehler\n";
$r = [33049 => 0];
put32($r, 33029, 1940); $r[33035] = 126;                      // PV gesamt 1940 kWh, heute 12,6 kWh
put32($r, 33161, 833);  $r[33163] = 204;                      // Laden gesamt 833 kWh, heute 20,4 kWh
put32($r, 33165, 741);  $r[33167] = 85;                       // Entladen
put32($r, 33169, 120);  $r[33171] = 485;                      // Bezug
put32($r, 33173, 497);  $r[33175] = 16;                       // Einspeisung
put32($r, 33177, 1318); $r[33179] = 302;                      // Haus (Restgroesse)
$v = run($r, true);
ok($v['e_pv_total'] === 1940.0 && abs($v['e_pv_day'] - 12.6) < 0.001, 'PV: Gesamt 1940 kWh (Faktor 1), heute 12,6 kWh (Faktor 0,1)');
ok($v['e_charge_total'] === 833.0 && abs($v['e_charge_day'] - 20.4) < 0.001, 'Laden: Gesamt/heute');
ok($v['e_disch_total'] === 741.0 && abs($v['e_disch_day'] - 8.5) < 0.001, 'Entladen: Gesamt/heute');
ok($v['e_buy_total'] === 120.0 && abs($v['e_buy_day'] - 48.5) < 0.001, 'Bezug: Gesamt/heute');
ok($v['e_sell_total'] === 497.0 && abs($v['e_sell_day'] - 1.6) < 0.001, 'Einspeisung: Gesamt/heute');
ok($v['e_load_total'] === 1318.0 && abs($v['e_load_day'] - 30.2) < 0.001, 'Haus: Gesamt 1318 kWh (Faktor 1), heute 30,2 kWh (Faktor 0,1)');

echo $fail === 0 ? "\nALLES OK\n" : "\n$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
