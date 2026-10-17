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
eval($m[1]);

class MockMb
{
    public $regs = [];
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
    public function GetPropBool($n) { return true; }
    public function SetVarBool($i, $x) { $this->v[$i] = (bool)$x; }
    public function SetVarInt($i, $x) { $this->v[$i] = (int)$x; }
    public function SetVarFloat($i, $x) { $this->v[$i] = (float)$x; }
    public function SetVarStr($i, $x) { $this->v[$i] = (string)$x; }
}
function put32(&$r, $a, $v) { $v &= 0xFFFFFFFF; $r[$a] = ($v >> 16) & 0xFFFF; $r[$a + 1] = $v & 0xFFFF; }

function run($regs, $slow = false)
{
    $d = new IHUB_SolisDriver(); $mb = new MockMb(); $mb->regs = $regs; $hub = new MockHub();
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

echo "Energiezaehler\n";
$r = [33049 => 0];
put32($r, 33029, 1940); $r[33035] = 126;                      // PV gesamt 1940 kWh, heute 12,6 kWh
put32($r, 33161, 833);  $r[33163] = 204;                      // Laden gesamt 833 kWh, heute 20,4 kWh
put32($r, 33165, 741);  $r[33167] = 85;                       // Entladen
put32($r, 33169, 120);  $r[33171] = 485;                      // Bezug
put32($r, 33173, 497);  $r[33175] = 16;                       // Einspeisung
$v = run($r, true);
ok($v['e_pv_total'] === 1940.0 && abs($v['e_pv_day'] - 12.6) < 0.001, 'PV: Gesamt 1940 kWh (Faktor 1), heute 12,6 kWh (Faktor 0,1)');
ok($v['e_charge_total'] === 833.0 && abs($v['e_charge_day'] - 20.4) < 0.001, 'Laden: Gesamt/heute');
ok($v['e_disch_total'] === 741.0 && abs($v['e_disch_day'] - 8.5) < 0.001, 'Entladen: Gesamt/heute');
ok($v['e_buy_total'] === 120.0 && abs($v['e_buy_day'] - 48.5) < 0.001, 'Bezug: Gesamt/heute');
ok($v['e_sell_total'] === 497.0 && abs($v['e_sell_day'] - 1.6) < 0.001, 'Einspeisung: Gesamt/heute');

echo $fail === 0 ? "\nALLES OK\n" : "\n$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
