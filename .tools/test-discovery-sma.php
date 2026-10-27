<?php
// Gerätesuche, SMA: SMA-eigenes Statusregister ODER SunSpec-Rückfall (Sunny Boy SB2.0-1VL-40, Forum richimaint 27.10.2026).
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }
$src = file_get_contents(dirname(__DIR__) . '/InverterHubDiscovery/module.php');
$need = ['probeVendor', 'probeSunSpecManufacturer', 'decodeAsciiText'];
$code = '';
foreach ($need as $m) {
    if (!preg_match('/    private function ' . $m . '\(.*?\n    }\n/s', $src, $x)) { echo "FAIL $m nicht gefunden\n"; exit(1); }
    $code .= $x[0] . "\n";
}
eval('class DiscT { public $regs = []; public $reads = [];
    private function readHolding($h, $p, $u, $s, $c, $t) { $this->reads[] = "H$s"; return $this->take($u, $s, $c); }
    private function readInput($h, $p, $u, $s, $c, $t) { $this->reads[] = "I$s"; return $this->take($u, $s, $c); }
    private function take($u, $s, $c) { if (!isset($this->regs[$u])) { return null; } $o = []; for ($a = $s; $a < $s + $c; $a++) { if (!isset($this->regs[$u][$a])) { return null; } $o[] = $this->regs[$u][$a]; } return $o; }
    public function probe($v, $u) { return $this->probeVendor($v, "1.2.3.4", 502, $u); }
' . $code . '}');
function text($s, $n = 16) { $s = str_pad($s, $n * 2, "\0"); $o = []; for ($i = 0; $i < $n; $i++) { $o[] = (ord($s[2 * $i]) << 8) | ord($s[2 * $i + 1]); } return $o; }
function sunspec($name) { $r = [40000 => 0x5375, 40001 => 0x6e53]; foreach (text($name) as $i => $w) { $r[40004 + $i] = $w; } return $r; }

$t = new DiscT; $t->regs[3] = [30200 => 0, 30201 => 307];
ok($t->probe('sma', 3) === true, 'SMA-eigenes Statusregister (307 = OK) wird weiter erkannt');
$t = new DiscT; $t->regs[126] = sunspec('SMA');
ok($t->probe('sma', 126) === true, 'Sunny Boy nur ueber SunSpec (Hersteller "SMA"): erkannt');
$t = new DiscT; $t->regs[126] = sunspec('SMA Solar Technology AG');
ok($t->probe('sma', 126) === true, 'Herstellername "SMA Solar Technology AG": erkannt');
$t = new DiscT; $t->regs[126] = sunspec('Fronius');
ok($t->probe('sma', 126) === false, 'Fronius ueber SunSpec: kein SMA');
$t = new DiscT; $t->regs[126] = sunspec('Smartgrid GmbH');
ok($t->probe('sma', 126) === false, 'Fremder Name, der nur mit "sma" anfaengt ("Smartgrid") wird nicht als SMA erkannt');
$t = new DiscT; $t->regs[1] = sunspec('XYZ SMA Dummy');
ok($t->probe('sma', 1) === false, 'Name, der "sma" nur enthaelt: kein SMA');
$t = new DiscT;
ok($t->probe('sma', 3) === false, 'Gerät antwortet gar nicht: kein SMA');
$t = new DiscT; $t->regs[126] = sunspec('Fronius');
ok($t->probe('fronius', 126) === true, 'Fronius-Erkennung unveraendert (enthaelt-Pruefung)');
echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n"; exit($fail === 0 ? 0 : 1);
