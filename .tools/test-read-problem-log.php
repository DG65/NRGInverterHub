<?php
// LogReadProblem/ClearReadProblem: einmal loggen, bei gleichem Text nicht wiederholen,
// bei geaendertem Text erneut, bei Erholung genau eine Meldung.
$src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
if (!preg_match('/private function LogReadProblem\(.*?\n    \}\n\n    private function ClearReadProblem\(.*?\n    \}\n/s', $src, $m)) {
    fwrite(STDERR, "FAIL Methoden nicht gefunden\n"); exit(1);
}
define('KL_ERROR', 3); define('KL_MESSAGE', 10);
eval('class T { public $buf = []; public $log = [];
  function GetBuffer($k){ return $this->buf[$k] ?? ""; }
  function SetBuffer($k,$v){ $this->buf[$k] = $v; }
  function LogMessage($m,$l){ $this->log[] = [$m,$l]; }
  ' . str_replace(['private function LogReadProblem','private function ClearReadProblem'], ['public function LogReadProblem','public function ClearReadProblem'], $m[0]) . ' }');
$t = new T(); $fails = 0;
$chk = function($ok, $name) use (&$fails) { echo ($ok ? "OK   " : "FAIL ") . $name . "\n"; if (!$ok) $fails++; };
$t->ClearReadProblem();                       $chk(count($t->log) === 0, 'ohne Fehler keine Erholungsmeldung');
$t->LogReadProblem('A'); $t->LogReadProblem('A'); $t->LogReadProblem('A');
$chk(count($t->log) === 1 && $t->log[0][1] === KL_ERROR, 'gleicher Fehler nur einmal geloggt');
$t->LogReadProblem('B');                      $chk(count($t->log) === 2, 'geaenderter Text wird erneut geloggt');
$t->ClearReadProblem(); $t->ClearReadProblem();
$chk(count($t->log) === 3 && $t->log[2][1] === KL_MESSAGE, 'Erholung genau einmal gemeldet');
$t->LogReadProblem('B');                      $chk(count($t->log) === 4, 'nach Erholung wird derselbe Fehler wieder geloggt');
exit($fails ? 1 : 0);
