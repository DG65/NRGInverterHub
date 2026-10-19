<?php
// Prueft die Schaltflaeche "Archivierung jetzt einrichten" (strukturell + eval der Methode):
// - Schaltflaeche und Ergebnis-Label sind im Formular, der Hinweis nennt die Folgen der ausgeschalteten
//   Archivierung (nicht mehr "nur fuer Migration"),
// - RequestAction('ArchiveNow') ruft ArchiveAllNow() und setzt das Haekchen im offenen Formular,
// - ArchiveAllNow() archiviert nur Variablen, die der Treiber empfiehlt und die noch nicht geloggt werden.
// Aufruf: php .tools/test-archive-now.php
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');

ok(strpos($src, "'name'    => 'ArchiveNowButton'") !== false && strpos($src, 'IPS_RequestAction($id, "ArchiveNow", true);') !== false, 'Schaltflaeche im Formular');
ok(strpos($src, "'name'    => 'ArchiveNowResult'") !== false, 'Ergebnis-Label im Formular');
ok(strpos($src, 'Das ist nur für eine Migration aus einem Altmodul') === false && strpos($src, 'zeigen Dashboard, Tages- und Verlaufswerte nichts an') !== false, 'Hinweistext nennt die Folgen, nicht mehr "nur fuer Migration"');
ok(preg_match("/if \(\\\$Ident === 'ArchiveNow'\) \{\s*\\\$this->UpdateFormField\('ArchiveNowResult', 'caption', \\\$this->ArchiveAllNow\(\)\);\s*\\\$this->UpdateFormField\('AutoArchive', 'value', true\);/", $src) === 1, 'RequestAction: Ergebnis anzeigen und Haekchen im offenen Formular setzen');
ok(strpos($src, "RegisterPropertyBoolean('AutoArchive', false)") !== false, 'Vorgabe von AutoArchive bleibt AUS (Store-Vorgabe)');

// Methode ausfuehren
if (!preg_match('/private function ArchiveAllNow\(\): string\n    \{.*?\n    \}\n/s', $src, $m)) { echo "FAIL ArchiveAllNow nicht gefunden\n"; exit(1); }
$GLOBALS['logged'] = [10 => true];          // Variable 10 ist schon archiviert
$GLOBALS['set'] = [];
$GLOBALS['archives'] = [99];
function IPS_GetInstanceListByModuleID($g) { return $GLOBALS['archives']; }
function AC_GetLoggingStatus($a, $v) { return !empty($GLOBALS['logged'][$v]); }
function IPS_ApplyChanges($id) {}
class FakeDriver {
    public function getBaseVars() { return [['pv_total', 'PV', 'F', 'W', true, 'pv', ''], ['connected', 'V', 'B', '', false, 'errors', '']]; }
    public function getOptionalGroups() { return ['GroupEnergy' => ['vars' => [['e_pv_day', 'PV Heute', 'F', '~Electricity', true, 'energy', ''], ['bat_soh', 'SOH', 'I', '', true, 'bat1', '']]], 'GroupOff' => ['vars' => [['x_off', 'X', 'F', '', true, 'pv', '']]]]; }
}
eval('class T { ' . $m[0] . '
    public function GetDriver() { return new FakeDriver(); }
    public function ReadPropertyBoolean($n) { return $n !== "GroupOff"; }
    private function MpptFiltered($v) { return $v; }
    private function FindVarByIdent($i) { return ["pv_total" => 10, "e_pv_day" => 11, "bat_soh" => 12, "connected" => 13, "x_off" => 14][$i] ?? 0; }
    private function SetArchive($vid, $e) { $GLOBALS["set"][$vid] = $e; }
    public function run() { return $this->ArchiveAllNow(); }
}');
$t = new T();
$msg = $t->run();
ok($GLOBALS['set'] === [11 => true, 12 => false], 'archiviert nur empfohlene, noch nicht geloggte Variablen (Energie als Zaehler)');
ok(!isset($GLOBALS['set'][10]) && !isset($GLOBALS['set'][13]) && !isset($GLOBALS['set'][14]), 'bereits archiviert, nicht empfohlen und abgeschaltete Gruppe bleiben unberuehrt');
ok(strpos($msg, '2 Variablen') !== false && strpos($msg, '1 waren schon archiviert') !== false, 'Ergebnistext nennt die Anzahl: ' . $msg);
$GLOBALS['archives'] = [];
ok(strpos($t->run(), 'Keine Archiv-Instanz') !== false, 'ohne Archiv-Instanz: klare Meldung, nichts eingerichtet');

echo $fail === 0 ? "\nALLES OK\n" : "\n$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
