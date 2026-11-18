<?php
// GoodWe: Zaehlerwert 0xFFFF/0xFFFFFFFF ("ungueltig") wird nicht uebernommen (Fund 01.11.2026, Tageszaehler 6553,5 kWh).
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }
require_once __DIR__ . '/ih-source.inc.php';
$src = ih_source();
if (!preg_match('/(class IHUB_GoodweDriver implements IHUB_InverterDriverInterface\s*\{.*?\n\})\n\n\/\/ -{20,}\n\/\/ IHUB_SungrowDriver/s', $src, $m)) { echo "FAIL Treiberklasse nicht gefunden\n"; exit(1); }
define('VARIABLETYPE_FLOAT', 2); define('VARIABLETYPE_INTEGER', 1);
interface IHUB_InverterDriverInterface {}
eval($m[1]);
class FMb {
    public $blk; public $bms = null;
    function readHolding($a, $n) { return ($a === 35191) ? $this->blk : (($a === 37056) ? $this->bms : null); }
    function u16($b, $i) { return $b[$i]; }
    function u32($b, $i) { return ($b[$i] << 16) | $b[$i + 1]; }
}
class FHub { public $v = []; function GetPropBool($n) { return $n === 'GroupEnergy'; } function SetVarFloat($i, $x) { $this->v[$i] = $x; } function SetVarInt($i, $x) { $this->v[$i] = $x; } }
function block($over = []) { $b = array_fill(0, 22, 0); $b[15] = 1; $b[16] = 39142; $b[17] = 62; $b[18] = 2; $b[19] = 37522; $b[20] = 52; foreach ($over as $i => $x) { $b[$i] = $x; } return $b; }
$d = new IHUB_GoodweDriver;
$mb = new FMb; $mb->blk = block(); $h = new FHub; $d->readSlow($mb, $h);
ok(abs($h->v['e_charge_day'] - 6.2) < 1e-9 && abs($h->v['e_disch_day'] - 5.2) < 1e-9, 'gueltige Tageszaehler werden uebernommen');
ok(abs($h->v['e_charge_total'] - 10467.8) < 1e-6, 'gueltiger Gesamtzaehler (U32) wird uebernommen');
$mb->blk = block([17 => 0xFFFF, 20 => 0xFFFF]); $h = new FHub; $d->readSlow($mb, $h);
ok(!array_key_exists('e_charge_day', $h->v) && !array_key_exists('e_disch_day', $h->v), '0xFFFF (Tageszaehler) wird nicht geschrieben');
ok(array_key_exists('e_charge_total', $h->v), 'andere Zaehler im selben Lauf werden weiter geschrieben');
$mb->blk = block([15 => 0xFFFF, 16 => 0xFFFF, 18 => 0xFFFF, 19 => 0xFFFF]); $h = new FHub; $d->readSlow($mb, $h);
ok(!array_key_exists('e_charge_total', $h->v) && !array_key_exists('e_disch_total', $h->v), '0xFFFFFFFF (Gesamtzaehler) wird nicht geschrieben');
$mb->blk = block([17 => 0]); $h = new FHub; $d->readSlow($mb, $h);
ok(array_key_exists('e_charge_day', $h->v) && $h->v['e_charge_day'] === 0.0, 'echte 0 (Tagesbeginn) wird weiter geschrieben');
// BMS-Gesamtzaehler 37056/37058 (U32, 0,1 kWh): Messung 07.11.2026 8669,7 / 8429,7 kWh
$mb = new FMb; $mb->blk = block(); $mb->bms = [1, 21161, 1, 18761]; $h = new FHub; $d->readSlow($mb, $h);
ok(abs($h->v['bms_e_charge_total'] - 8669.7) < 1e-6 && abs($h->v['bms_e_disch_total'] - 8429.7) < 1e-6, 'BMS-Gesamtzaehler Laden/Entladen werden uebernommen');
$mb->bms = [0xFFFF, 0xFFFF, 1, 18761]; $h = new FHub; $d->readSlow($mb, $h);
ok(!array_key_exists('bms_e_charge_total', $h->v) && array_key_exists('bms_e_disch_total', $h->v), '0xFFFFFFFF beim BMS-Zaehler wird verworfen, der andere bleibt');
$mb->bms = null; $h = new FHub; $d->readSlow($mb, $h);
ok(!array_key_exists('bms_e_charge_total', $h->v), 'keine Antwort: nichts geschrieben');
echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n"; exit($fail === 0 ? 0 : 1);
