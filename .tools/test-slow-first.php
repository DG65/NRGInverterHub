<?php
// Erster langsamer Zyklus kurz nach ApplyChanges (Pauls Befund 20.11.2026): rc_failsafe_decl_sec stand nach dem Update
// bis zu IntervalSlow auf 0 und das Energiemanagement sperrte die Fernsteuerung. Quelltextprüfung plus Ablauf mit Stub.
require_once __DIR__ . '/ih-source.inc.php';
$src = ih_source();
$fails = 0;
$ok = function ($c, $n) use (&$fails) { echo ($c ? "OK   " : "FAIL ") . $n . "\n"; if (!$c) $fails++; };

$ok(preg_match('/const SLOW_FIRST_MS = (\d+);/', $src, $m) === 1 && (int)$m[1] >= 3000 && (int)$m[1] <= 15000, 'kurzes erstes Intervall (3 bis 15 s) ist definiert');
$ok(preg_match("/function ApplyChanges\(\).*?SetBuffer\('SlowNormalMs', \(string\)\\\$slowMs\);\s*\\\$this->SetTimerInterval\('SlowTimer', self::SLOW_FIRST_MS\);/s", $src) === 1, 'ApplyChanges merkt das eingestellte Intervall und startet den langsamen Zyklus kurz');
$f = strpos($src, 'private function ReadSlowUnlocked()'); $body = $f === false ? '' : substr($src, $f, 1800);
$c = strpos($body, 'catch (Throwable $e)'); $g = strpos($body, "GetBuffer('SlowNormalMs')"); $r = strpos($body, 'SetTimerInterval(\'SlowTimer\', (int)$normal)');
$ok($c !== false && $g !== false && $r !== false && $c < $g && $g < $r, 'ReadSlowUnlocked stellt das eingestellte Intervall nach dem ersten Lauf (auch nach Fehler) wieder her');

// Ablauf mit Stub: dieselbe Logik wie in ApplyChanges und ReadSlowUnlocked
class T { public $buf = []; public $timer = null; const SLOW_FIRST_MS = 8000;
  function apply($slowMs) { if ($slowMs > self::SLOW_FIRST_MS) { $this->buf['n'] = (string)$slowMs; $this->timer = self::SLOW_FIRST_MS; } else { $this->buf['n'] = ''; $this->timer = $slowMs; } }
  function after() { $n = $this->buf['n'] ?? ''; if ($n !== '') { $this->buf['n'] = ''; $this->timer = (int)$n; } } }
$t = new T(); $t->apply(300000);
$ok($t->timer === 8000, 'nach dem Anwenden: erster Lauf in 8 s');
$t->after(); $ok($t->timer === 300000, 'nach dem ersten Lauf: wieder 300 s');
$t->after(); $ok($t->timer === 300000, 'weitere Läufe lassen das Intervall unverändert');
$t->apply(5000); $ok($t->timer === 5000 && ($t->buf['n'] === ''), 'Intervall kürzer als das erste: keine Umstellung');
echo $fails ? "\n$fails FEHLER\n" : "\nALLES OK\n";
exit($fails ? 1 : 0);
