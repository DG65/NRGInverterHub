<?php
// GoodWe-Flash-Register (47509/47510/45356, "einmal schreiben"): nur bei geaendertem Wert schreiben.
$src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
$fails = 0;
function check($ok, $t) { global $fails; echo ($ok ? 'OK   ' : 'FAIL ') . $t . "\n"; if (!$ok) $fails++; }

preg_match('/private function regAlready\(.*?\n    }\n/s', $src, $m);
check(!empty($m), 'regAlready gefunden');
class Mock { public $reg = []; public $writes = [];
    function readHolding($r, $n) { return isset($this->reg[$r]) ? [$this->reg[$r]] : null; }
    function u16($a, $i) { return $a[$i]; } }
eval('class T { ' . $m[0] . ' function t($mb,$r,$v){ return $this->regAlready($mb,$r,$v);} }');
$mb = new Mock; $t = new T;
$mb->reg[47510] = 3000;
check($t->t($mb, 47510, 3000) === true, 'gleicher Wert -> kein Schreiben');
check($t->t($mb, 47510, 2000) === false, 'anderer Wert -> schreiben');
check($t->t($mb, 45356, 10) === false, 'Lesefehler -> schreiben wie bisher');
foreach (['ctl_export_enable' => 'REG_FEED_POWER_ENABLE', 'ctl_export_limit' => 'REG_FEED_POWER_LIMIT', 'ctl_soc_min' => 'REG_SOC_MIN'] as $id => $c) {
    check(preg_match("/case '$id':[^}]*?regAlready\\(\\\$mb, self::$c, \\\$val\\)/s", $src) === 1, "$id prueft vor dem Schreiben");
}
exit($fails ? 1 : 0);
