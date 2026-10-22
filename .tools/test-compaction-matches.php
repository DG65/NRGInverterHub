<?php
// CompactionMatches(): Verdichtung nur schreiben, wenn das Archiv vom Plan abweicht.
$src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
$fails = 0;
function check($ok, $t) { global $fails; echo ($ok ? 'OK   ' : 'FAIL ') . $t . "\n"; if (!$ok) $fails++; }
preg_match('/private function CompactionMatches\(.*?\n    }\n/s', $src, $m);
check(!empty($m), 'CompactionMatches gefunden');
$GLOBALS['cur'] = null;
function AC_GetCompaction($a, $v) { return $GLOBALS['cur']; }
eval('class T { ' . str_replace('private function', 'public function', $m[0]) . ' }');
$t = new T;
$plan = [[-1, 0], [1, 1], [12, 2]];
$GLOBALS['cur'] = [['MonthOffset' => 12, 'CompactionType' => 2], ['MonthOffset' => 1, 'CompactionType' => 1], ['MonthOffset' => -1, 'CompactionType' => 0]];
check($t->CompactionMatches(1, 2, $plan) === true, 'identisch -> kein Schreiben (Reihenfolge egal)');
$GLOBALS['cur'][1]['CompactionType'] = 3;
check($t->CompactionMatches(1, 2, $plan) === false, 'anderer Typ -> schreiben');
$GLOBALS['cur'] = [['MonthOffset' => 12, 'CompactionType' => 2]];
check($t->CompactionMatches(1, 2, $plan) === false, 'Regel fehlt -> schreiben');
$GLOBALS['cur'] = [['MonthOffset' => 12, 'CompactionType' => 2], ['MonthOffset' => 1, 'CompactionType' => 1]];
check($t->CompactionMatches(1, 2, [[-1, -1], [1, 1], [12, 2]]) === true, 'Typ -1 und keine Regel -> passt');
check($t->CompactionMatches(1, 2, [[-1, -1], [1, -1], [12, 2]]) === false, 'Typ -1 aber Regel vorhanden -> loeschen');
$GLOBALS['cur'] = false;
check($t->CompactionMatches(1, 2, $plan) === false, 'Fehler/kein Array -> schreiben');
$GLOBALS['cur'] = [['x' => 1]];
check($t->CompactionMatches(1, 2, $plan) === false, 'unerwartetes Format -> schreiben');
exit($fails ? 1 : 0);
