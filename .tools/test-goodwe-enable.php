<?php
// Prueft, welcher Wert bei ctl_ems_enable in Register 47505 geschrieben wird.
// 2 (frueher) erzeugt an der echten Anlage eine langsame Leistungsrampe, 1 nicht.
$src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
if (!preg_match("/case 'ctl_ems_enable':\s*(?:\/\/[^\n]*\n\s*)*\\\$val = ([^;]+);/", $src, $m)) {
    fwrite(STDERR, "FAIL ctl_ems_enable-Zweig nicht gefunden\n");
    exit(1);
}
$expr = $m[1];
$fails = 0;
foreach ([[true, 1], [false, 0]] as [$in, $want]) {
    $value = $in;
    $got = eval('return ' . $expr . ';');
    if ($got !== $want) { echo "FAIL enable=" . var_export($in, true) . " schreibt $got, erwartet $want\n"; $fails++; }
    else { echo "OK enable=" . var_export($in, true) . " -> $got\n"; }
}
exit($fails ? 1 : 0);
