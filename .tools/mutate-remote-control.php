<?php
/**
 * Mutationsproben für die gemeinsame Fernsteuerung (Basis und Adapter Solis, FoxESS, Deye): jede Mutation baut einen Fehler in eine Kopie des Moduls (InverterHub/, libs/,
 * .tools/) ein; .tools/test-remote-control.php muss anschlagen.   php .tools/mutate-remote-control.php   (0 = alle erkannt)
 */
$root = dirname(__DIR__);
$B = 'libs/IHUB_RemoteControl.php'; $F = 'libs/IHUB_RemoteFoxEss.php'; $Y = 'libs/IHUB_RemoteDeye.php'; $X1 = 'libs/IHUB_RemoteSolax.php'; $S = 'libs/IHUB_RemoteSolis.php'; $M = 'InverterHub/module.php';
$X = [
    // Basis
    [$B, [["if (\$steps === []) {", 'if (false) {']], 'leere Schrittliste wird nicht als erfüllt behandelt'],
    [$B, [['$bad = $a->capabilityError($cap);', '$bad = "";']], 'Fähigkeit wird nicht geprüft'],
    [$B, [['$bad = $a->validate($req, $cap);', '$bad = "";']], 'Anfrage wird nicht geprüft'],
    [$B, [['for ($try = 0; $try < 2; $try++) {', 'for ($try = 0; $try < 1; $try++) {']], 'kein zweiter Gesamtversuch'],
    [$B, [['continue 2;', 'continue;']], 'nach einem Schreibfehler geht die Reihenfolge weiter'],
    [$B, [['$diff = $a->verify($back, $req, $cap);', '$diff = "";']], 'Rücklesen wird nicht verglichen'],
    [$B, [['max(-self::POWER_MAX_W, min(self::POWER_MAX_W, (float)$value))', '(float)$value']], 'Leistung nicht begrenzt'],
    [$B, [["(\$v !== 0 && !\$info['gridCharge'])", 'false']], 'Netzladen wird bei Geräten ohne Netzladen nicht abgelehnt'],
    [$B, [["if (\$window && !\$info['socWindow']) {", 'if (false) {']], 'SOC-Fenster wird bei Geräten ohne Fenster nicht abgelehnt'],
    [$B, [["\$hub->GetVarInt('ctl_rc_mode'), \$err, \$back", '0, $err, $back']], 'letzter Modus wird dem Adapter nicht übergeben'],
    [$B, [["\$hub->SetVarInt('ctl_rc_mode', \$mode);", '']], 'Modus wird nach Erfolg nicht gemerkt'],
    [$B, [['if ($ver <= 0) {', 'if (true) {']], 'Fähigkeit wird nie aus dem Zyklus übernommen'],
    [$B, [["\$hub->SetVarBool('rc_available', \$on);", "\$hub->SetVarBool('rc_available', true);"]], 'rc_available immer true'],
    [$B, [["\$hub->SetVarBool('rc_tested', (bool)\$info['tested']);", "\$hub->SetVarBool('rc_tested', true);"]], 'rc_tested immer true'],
    [$B, [["\$hub->SetVarStr('rc_modes', implode(',', \$info['modes']));", '']], 'rc_modes fehlt'],
    // FoxESS
    [$F, [['return 1 | (($mode === 3 ? 2 : 1) << 2);', 'return 1 | (($mode === 3 ? 1 : 2) << 2);']], 'FoxESS: Ziele Akku und Netz vertauscht'],
    [$F, [['return $mode === 2 ? -$w : $w;', 'return $w;']], 'FoxESS: Vorzeichen für Modus 2 nicht gedreht'],
    [$F, [["['fc' => 6, 'addr' => self::REG_TIMEOUT, 'words' => [\$this->timeout(\$req)], 'afterUs' => self::\$gapUs],", '']], 'FoxESS: Totmann wird nicht gesetzt'],
    [$F, [['const TIMEOUT_DEFAULT_S = 60;', 'const TIMEOUT_DEFAULT_S = 600;']], 'FoxESS: Totmann-Vorgabe geändert'],
    [$F, [['$last === 0 ? [] :', 'false ? [] :']], 'FoxESS: Fernsteuerung wird auch ausgeschaltet, wenn wir sie nicht eingeschaltet haben'],
    [$F, [["if (\$back['status'] <= 0) {", 'if (false) {']], 'FoxESS: Restzeit 0 gilt nicht als Fehlschlag'],
    [$F, [['return [($u >> 16) & 0xFFFF, $u & 0xFFFF];', 'return [$u & 0xFFFF, ($u >> 16) & 0xFFFF];']], 'FoxESS: I32 niederwertiges Wort zuerst'],
    [$F, [["if (!in_array(\$mode, [0, 2, 3], true)) {", 'if (false) {']], 'FoxESS: Modus 1 wird nicht abgelehnt'],
    [$F, [["['fc' => 6, 'addr' => self::REG_CONTROL, 'words' => [self::controlWord(\$mode)]", "['fc' => 16, 'addr' => self::REG_CONTROL, 'words' => [self::controlWord(\$mode)]"]], 'FoxESS: Enable mit FC16 statt FC06'],
    // Deye
    [$Y, [['$v = (int)round(-$watts / $ratedW * 1000);', '$v = (int)round(-$watts / $ratedW * 100);']], 'Deye: falsche Skalierung (nicht 0,1 %)'],
    [$Y, [['$v = (int)round(-$watts / $ratedW * 1000);', '$v = (int)round($watts / $ratedW * 1000);']], 'Deye: Vorzeichen nicht gedreht'],
    [$Y, [["['fc' => 16, 'addr' => self::REG_MODE, 'words' => [1], 'afterUs' => 0],", '']], 'Deye: Fernmodus wird nicht eingeschaltet'],
    [$Y, [['max(self::WATCHDOG_MIN_S, min(self::WATCHDOG_MAX_S, $s))', '$s']], 'Deye: Watchdog nicht begrenzt'],
    [$Y, [['const WATCHDOG_DEFAULT_S = 60;', 'const WATCHDOG_DEFAULT_S = 600;']], 'Deye: Watchdog-Vorgabe geändert'],
    [$Y, [['if ($mode !== 2 || $ratedW <= 0) {', 'if ($ratedW <= 0) {']], 'Deye: Halten schreibt die gemerkte Leistung'],
    [$Y, [["if (\$mode === 2 && (int)(\$cap['ratedW'] ?? 0) <= 0) {", 'if (false) {']], 'Deye: unbekannte Nennleistung wird nicht abgelehnt'],
    [$Y, [["['fc' => 16, 'addr' => self::REG_WATCHDOG,", "['fc' => 6, 'addr' => self::REG_WATCHDOG,"]], 'Deye: FC06 statt FC16'],
    [$Y, [['if ($got !== $want) {', 'if (false) {']], 'Deye: Rücklesen vergleicht nichts'],
    [$Y, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'Deye: Modus 3 wird nicht abgelehnt'],
    // SolaX
    [$X1, [['return -max(-self::POWER_MAX_W, min(self::POWER_MAX_W, $watts));', 'return max(-self::POWER_MAX_W, min(self::POWER_MAX_W, $watts));']], 'SolaX: Vorzeichen der Akkuleistung nicht gedreht'],
    [$X1, [['return [$u & 0xFFFF, ($u >> 16) & 0xFFFF];', 'return [($u >> 16) & 0xFFFF, $u & 0xFFFF];']], 'SolaX: S32 hochwertiges Wort zuerst'],
    [$X1, [["if (\$mode !== 2) {\n            return 0;", "if (false) {\n            return 0;"]], 'SolaX: Halten schreibt die gemerkte Leistung'],
    [$X1, [['max(self::DURATION_MIN_S, min(self::DURATION_MAX_S, $s))', '$s']], 'SolaX: Dauer nicht begrenzt'],
    [$X1, [['const NEXT_USER_MODE = 0xA0;', 'const NEXT_USER_MODE = 0xA1;']], 'SolaX: 0xA7 / Timeout geändert'],
    [$X1, [["['fc' => 16, 'addr' => self::REG_OLD, 'words' => array_fill(0, 13, 0), 'afterUs' => 0],", '']], 'SolaX: Modus 0 schreibt die alte Schnittstelle nicht'],
    [$X1, [['if ($got !== $want) {', 'if (false) {']], 'SolaX: Rücklesen vergleicht nichts'],
    [$X1, [["if (!in_array(\$mode, [0, 1, 2], true)) {", 'if (false) {']], 'SolaX: Modus 3 wird nicht abgelehnt'],
    [$X1, [['const PV_UNLIMITED_W = 100000;', 'const PV_UNLIMITED_W = 1000;']], 'SolaX: PV-Grenze zu klein (drosselt die PV)'],
    [$X1, [['return $this->duration($req) + self::NEXT_USER_MODE;', 'return $this->duration($req);']], 'SolaX: Totmann im Gerät falsch gemeldet'],
    // Solis-Adapter
    [$S, [["IHUB_SolisDispatch::failsafe(\$this->legacy(\$req)) * 60", "IHUB_SolisDispatch::failsafe(\$this->legacy(\$req))"]], 'Solis: Totmann in Minuten statt Sekunden gemeldet'],
    [$S, [["(int)ceil(\$sec / 60)", "(int)floor(\$sec / 60)"]], 'Solis: Totmann wird abgerundet'],
    [$M, [["'modeID' => \$find('ctl_rc_mode')", "'modeID' => 0"]], 'Vertrag: modeID fehlt'],
    [$M, [["if (\$find('ctl_rc_mode') > 0) {", 'if (true) {']], 'Vertrag: remoteControl auch ohne Variablen gefüllt'],
    [$M, [["\$group['default'] ?? true", 'true']], 'Gruppe mit default false startet an'],
];

// die letzte Probe in $S mit Platzhaltertext 'x' entfällt, wenn der Suchtext nicht existiert
$tmp = sys_get_temp_dir() . '/ihmutd' . getmypid(); mkdir($tmp);
$jobs = [];
foreach ($X as $i => [$file, $pairs, $desc]) {
    $d = "$tmp/m$i"; mkdir($d);
    foreach (['InverterHub', 'InverterHubDiscovery', 'libs', '.tools'] as $dir) { if (is_dir("$root/$dir")) { exec('cp -R ' . escapeshellarg("$root/$dir") . ' ' . escapeshellarg("$d/$dir")); } }
    $t = file_get_contents("$d/$file"); $ok = true;
    foreach ($pairs as [$a, $b]) {
        $p = strpos($t, $a);
        if ($p === false) { $ok = false; break; }
        $t = substr($t, 0, $p) . $b . substr($t, $p + strlen($a));
    }
    if (!$ok) { $jobs[$i] = ['err' => 'Suchtext nicht gefunden']; continue; }
    file_put_contents("$d/$file", $t);
    $jobs[$i] = ['dir' => $d, 'out' => ''];
}
$queue = array_keys(array_filter($jobs, fn($j) => isset($j['dir']))); $run = [];
while ($queue || $run) {
    while ($queue && count($run) < 6) {
        $i = array_shift($queue);
        $pr = proc_open('php ' . escapeshellarg($jobs[$i]['dir'] . '/.tools/test-remote-control.php') . ' 2>&1', [1 => ['pipe', 'w']], $pp);
        stream_set_blocking($pp[1], false); $run[$i] = [$pr, $pp[1]];
    }
    foreach ($run as $i => [$pr, $pipe]) {
        $jobs[$i]['out'] .= (string)stream_get_contents($pipe);
        $st = proc_get_status($pr);
        if (!$st['running']) { $jobs[$i]['out'] .= (string)stream_get_contents($pipe); $jobs[$i]['rc'] = $st['exitcode']; fclose($pipe); proc_close($pr); unset($run[$i]); }
    }
    usleep(100000);
}
$bad = 0;
foreach ($X as $i => [$file, , $desc]) {
    $j = $jobs[$i];
    if (isset($j['err'])) { $bad++; echo "  FEHLER     [$file] $desc: {$j['err']}\n"; continue; }
    $failed = [];
    foreach (explode("\n", $j['out']) as $l) { if (str_starts_with($l, '  FAIL ')) { $failed[] = trim(substr($l, 7)); } }
    if ($j['rc'] !== 0) { echo sprintf("  erkannt    [%s] %s  -> %s\n", basename($file), $desc, $failed ? mb_substr($failed[0], 0, 70) : 'Abbruch im Lauf'); }
    else { $bad++; echo "  UNGEFANGEN [$file] $desc\n"; }
}
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . count($X) . " Mutationen, " . (count($X) - $bad) . " erkannt, $bad ungefangen\n";
exit($bad === 0 ? 0 : 1);
