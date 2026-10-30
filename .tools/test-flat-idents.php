<?php
// Seit 0.78.0 liegen alle Variablen direkt unter der Instanz (Symcon-Store-Review 30.10.2026):
// Idents muessen je Treiber ueber ALLE Gruppen eindeutig sein, sonst wuerde die eine die andere ersetzen.
// Aufruf: php .tools/test-flat-idents.php
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
$a = strpos($src, 'interface IHUB_InverterDriverInterface');
$b = strpos($src, "\nclass InverterHub extends IPSModule");
if ($a === false || $b === false) { echo "FAIL Quelltext-Grenzen nicht gefunden\n"; exit(1); }
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3] as $k => $v) { define($k, $v); }
eval(substr($src, $a, $b - $a));
preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'(IHUB_[A-Za-z0-9]+Driver)'/", $src, $m, PREG_SET_ORDER);
ok(count($m) >= 15, count($m) . ' Treiber gefunden');
foreach ($m as [, $key, $class]) {
    $d = new $class();
    $seen = [];
    $dups = [];
    foreach ($d->getBaseVars() as $v) { if (isset($seen[$v[0]])) { $dups[] = $v[0]; } $seen[$v[0]] = true; }
    foreach ($d->getOptionalGroups() as $g => $grp) {
        foreach ($grp['vars'] as $v) { if (isset($seen[$v[0]])) { $dups[] = $v[0] . ' (' . $g . ')'; } $seen[$v[0]] = true; }
    }
    ok($dups === [], "$key: " . count($seen) . ' Idents eindeutig' . ($dups ? ' - doppelt: ' . implode(', ', $dups) : ''));
}
echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n"; exit($fail === 0 ? 0 : 1);
