<?php
// SMA: Modell/Seriennummer liegen auf Unit-ID + 123 (Forum brdiver677, 28.10.2026); Batterie-Sentinel 0x7FFFFFFF.
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
$a = strpos($src, 'class IHUB_SmaDriver'); $b = strpos($src, 'class IHUB_FroniusDriver');
$sma = substr($src, $a, $b - $a);
ok(strpos($sma, '0x7FFFFFFF') !== false, 'SMA-Treiber kennt den Sentinel 0x7FFFFFFF fuer Batterietemperatur');
$code = '';
foreach (['findModel', 'readDeviceInfo'] as $m) {
    if (!preg_match('/    (private|public) function ' . $m . '\(.*?\n    }\n/s', $sma, $x)) { echo "FAIL $m nicht gefunden\n"; exit(1); }
    $code .= $x[0] . "\n";
}
eval('class SmaT {' . $code . '}');
class FakeMb {
    public $unitId = 3; public $regs = [];
    function readHolding($addr, $n) { $o = []; for ($i = 0; $i < $n; $i++) { if (!isset($this->regs[$this->unitId][$addr + $i])) { return null; } $o[] = $this->regs[$this->unitId][$addr + $i]; } return $o; }
    function u16($b, $i) { return $b[$i]; }
    function readStr($b, $o, $n) { $s = ''; for ($i = 0; $i < $n; $i++) { $w = $b[$o + $i]; $s .= chr($w >> 8) . chr($w & 255); } return rtrim($s, "\0"); }
}
class FakeHub { public $v = []; function SetVarStr($k, $s) { $this->v[$k] = $s; } }
function chain() { $r = [40002 => 1, 40003 => 66]; $put = function ($s, $off) use (&$r) { $s = str_pad($s, 32, "\0"); for ($i = 0; $i < 16; $i++) { $r[40004 + $off + $i] = (ord($s[2 * $i]) << 8) | ord($s[2 * $i + 1]); } };
    $put('SMA', 0); $put('Sunny Boy 5000TL-21', 16); $put('3012345678', 48); for ($i = 0; $i < 66; $i++) { $r[40004 + $i] = $r[40004 + $i] ?? 0; } return $r; }

$mb = new FakeMb; $mb->regs[126] = chain(); $hub = new FakeHub; $d = new SmaT;
$d->readDeviceInfo($mb, $hub);
ok(($hub->v['dev_model'] ?? '') === 'Sunny Boy 5000TL-21', 'Unit-ID 3: Modell wird ueber 126 gefunden');
ok(($hub->v['dev_sn'] ?? '') === '3012345678', 'Unit-ID 3: Seriennummer wird ueber 126 gefunden');
ok($mb->unitId === 3, 'Unit-ID danach wieder die eingetragene');
$mb = new FakeMb; $mb->unitId = 126; $mb->regs[126] = chain(); $hub = new FakeHub;
$d->readDeviceInfo($mb, $hub);
ok(($hub->v['dev_sn'] ?? '') === '3012345678' && $mb->unitId === 126, 'Unit-ID 126 direkt: wie bisher');
$mb = new FakeMb; $hub = new FakeHub; $d->readDeviceInfo($mb, $hub);
ok($hub->v === [] && $mb->unitId === 3, 'Keine SunSpec-Kette: nichts geschrieben, Unit-ID unveraendert');
echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n"; exit($fail === 0 ? 0 : 1);
