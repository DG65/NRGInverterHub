<?php
// SMA: DC-Leistung je Eingang A/B (Register 30773/30961), Forum thorsten1 05.11.2026.
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
$a = strpos($src, 'class IHUB_SmaDriver'); $b = strpos($src, 'class IHUB_FroniusDriver');
$sma = substr($src, $a, $b - $a);
if (!preg_match('/    private function readDcInputPower\(.*?\n    }\n/s', $sma, $m)) { echo "FAIL Hilfsfunktion nicht in der SMA-Klasse\n"; exit(1); }
eval('class SmaT {' . $m[0] . ' public function t($mb, $r) { return $this->readDcInputPower($mb, $r); } }');
class FakeMb {
    public $regs = [];
    function readInput($r, $n) { return $this->regs[$r] ?? null; }
    function s32($b, $i) { $v = ($b[$i] << 16) | $b[$i + 1]; return ($v >= 0x80000000) ? $v - 0x100000000 : $v; }
}
$mb = new FakeMb; $t = new SmaT;
$mb->regs[30773] = [0, 1234];            $mb->regs[30961] = [0x8000, 0]; $mb->regs[40000] = [0xFFFF, 0xFFFF];
ok($t->t($mb, 30773) === 1234.0, 'Leistung 1234 W');
ok($t->t($mb, 30961) === null, 'Sentinel 0x80000000 = nicht verfügbar (null)');
ok($t->t($mb, 40000) === null, 'Sentinel 0xFFFFFFFF (-1) = nicht verfügbar (null)');
ok($t->t($mb, 12345) === false, 'keine Antwort = false (alter Wert bleibt)');
ok(strpos($sma, "30773 => 'mppt1_power', 30961 => 'mppt2_power'") !== false, 'Eingang A = 30773, Eingang B = 30961');
ok(preg_match("/GroupMppt'\s*=>.*?mppt1_power.*?mppt2_power/s", $sma) === 1, 'Gruppe GroupMppt mit beiden Variablen');
ok(strpos($sma, "GetPropBool('GroupMppt')") !== false, 'Schreiben nur bei aktivierter Gruppe');
echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n"; exit($fail === 0 ? 0 : 1);
