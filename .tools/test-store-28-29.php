<?php
require_once __DIR__ . '/ihub-text.inc.php';
// Store-Punkte 28 und 29 (SUITE.md): Profil/Darstellung nie nachtraeglich setzen, Archiv des Nutzers nie von selbst anfassen.
// Aufruf: php .tools/test-store-28-29.php
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
preg_match('/private function RegisterVar\(.*?\n    }\n/s', $src, $rv);
$rv = $rv[0] ?? '';
ok($rv !== '', 'RegisterVar gefunden');
// 28
ok(strpos($src, 'IPS_CreateVariable(') === false || !preg_match('/IPS_CreateVariable\(\$vtype\)/', $src), 'Punkt 28: Variablen werden nur ueber RegisterVariable* angelegt');
ok(preg_match('/RegisterVariableFloat\(\$ident, \$name, \$useProfile, \$pos\)/', $rv) === 1, 'Punkt 28: Profil beim Anlegen als Parameter von RegisterVariable*');
ok(substr_count($src, 'IPS_SetVariableCustomProfile(') === 1 && preg_match('/!\$created && \$useProfile !== \'\' && \$this->reprofileEnergy && \$isEnergy/', $rv) === 1, 'Punkt 28: CustomProfile nur bei der Wh-Umstellung bestehender Energie-Variablen');
// 29
ok(substr_count($src, 'AC_SetCompaction(') === 2 && substr_count($src, 'private function ApplyCompactionNow') === 1, 'Punkt 29: AC_SetCompaction nur in ApplyCompactionNow');
preg_match('/private function ApplyCompactionNow\(\).*?\n    }\n/s', $src, $ac);
ok(substr_count($ac[0] ?? '', 'AC_SetCompaction(') === 2, 'Punkt 29: beide AC_SetCompaction-Aufrufe liegen in ApplyCompactionNow');
ok(substr_count($src, 'AC_SetAggregationType(') === 1, 'Punkt 29: Aggregationstyp nur in SetArchive (beim Anlegen/Knopf)');
ok(strpos($rv, 'AC_Set') === false, 'Punkt 29: RegisterVar fasst das Archiv bestehender Variablen nicht an');
ok(preg_match("/RegisterPropertyBoolean\('AutoCompaction' \. \\\$kind, false\)/", $src) === 1, 'Punkt 29: Verdichtung Standard AUS');
ok(strpos($src, "if (\$Ident === 'CompactionNow')") !== false && strpos($src, '"CompactionNow"') !== false, 'Punkt 29: Verdichtung nur ueber den Knopf im Formular');
// Funktionspruefung ApplyCompactionNow
$GLOBALS['sets'] = []; $GLOBALS['logged'] = [10 => true, 11 => true]; $GLOBALS['props'] = ['AutoCompactionPower' => true, 'AutoCompactionEnergy' => false];
function IPS_GetInstanceListByModuleID($g) { return [99]; }
function AC_GetLoggingStatus($a, $v) { return !empty($GLOBALS['logged'][$v]); }
function AC_SetCompaction($a, $v, $m, $t) { $GLOBALS['sets'][] = [$v, $m, $t]; }
class FD { public function getBaseVars() { return [['pv_total', 'x', 'F', 'W', true, 'pv'], ['e_pv_day', 'y', 'F', '~Electricity', true, 'energy'], ['soc', 'z', 'I', '', true, 'bat']]; } public function getOptionalGroups() { return []; } }
eval('class T { ' . $ac[0] . '
    private function ActiveDriverVars() { return (new FD())->getBaseVars(); }
    private function ReadPropertyBoolean($n) { return $GLOBALS["props"][$n] ?? false; }
    private function ReadPropertyInteger($n) { return 5; }
    private function FindVarByIdent($i) { return ["pv_total" => 10, "e_pv_day" => 11, "soc" => 12][$i] ?? 0; }
    private function CompactionPlan($i, $k) { return [[-1, 0], [1, 1], [12, 2]]; }
    private function CompactionMatches($a, $v, $p) { return false; }
    public function run() { return $this->ApplyCompactionNow(); }
}');
$t = new T(); $r = $t->run();
$vids = array_unique(array_column($GLOBALS['sets'], 0));
ok($vids === [10] && count($GLOBALS['sets']) === 3, 'Knopf: nur geloggte Variable der angehakten Art (Leistung), Energie und nicht geloggte bleiben unberuehrt: ' . $r);
$GLOBALS['props'] = ['AutoCompactionPower' => false, 'AutoCompactionEnergy' => false]; $GLOBALS['sets'] = [];
$r = $t->run();
ok($GLOBALS['sets'] === [] && strpos($r, 'ℹ️') === 0, 'Knopf ohne angehakte Art: nichts geschrieben, Hinweis: ' . $r);
echo $fail ? "\n$fail FEHLER\n" : "\nALLE PRUEFUNGEN BESTANDEN\n";
exit($fail ? 1 : 0);
