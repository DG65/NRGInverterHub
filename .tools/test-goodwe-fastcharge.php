<?php
// GoodWe-Schnellladung (47545/47546/47603): Register, Wertebereiche, Rueckleser.
$src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
$fails = 0;
function check($ok, $t) { global $fails; echo ($ok ? 'OK   ' : 'FAIL ') . $t . "\n"; if (!$ok) $fails++; }
$gw = substr($src, strpos($src, 'class IHUB_GoodweDriver'), strpos($src, 'class IHUB_SungrowDriver') - strpos($src, 'class IHUB_GoodweDriver'));
check(preg_match('/REG_FAST_CHG_ENABLE\s*=\s*47545/', $gw) && preg_match('/REG_FAST_CHG_SOC\s*=\s*47546/', $gw) && preg_match('/REG_FAST_CHG_PCT\s*=\s*47603/', $gw), 'Registeradressen 47545/47546/47603');
foreach (['ctl_fastcharge_enable', 'ctl_fastcharge_soc', 'ctl_fastcharge_pct'] as $id) {
    check(strpos($gw, "['$id'") !== false, "$id als Steuervariable angelegt");
    check(strpos($gw, "case '$id':") !== false, "$id in writeControl");
}
check(preg_match("/case 'ctl_fastcharge_soc':\s*\\\$val = max\(1, min\(100, \(int\)\\\$value\)\);/", $gw) === 1, 'SOC auf 1-100 begrenzt');
check(preg_match("/case 'ctl_fastcharge_pct':\s*\\\$val = max\(0, min\(100, \(int\)\\\$value\)\);/", $gw) === 1, 'Netzleistung auf 0-100 % begrenzt');
check(strpos($gw, 'readHolding(self::REG_FAST_CHG_ENABLE, 2)') !== false && strpos($gw, 'readHolding(self::REG_FAST_CHG_PCT, 1)') !== false, 'Rueckleser in readSlow');
exit($fails ? 1 : 0);
