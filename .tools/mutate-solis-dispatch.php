<?php
/**
 * Mutationsproben für Solis Remote Dispatch: jede Mutation baut einen Fehler in eine Kopie des Moduls (InverterHub/, libs/,
 * .tools/) ein; .tools/test-solis-dispatch.php muss anschlagen.   php .tools/mutate-solis-dispatch.php   (0 = alle erkannt)
 */
$root = dirname(__DIR__);
$D = 'libs/IHUB_SolisDispatch.php'; $R = 'libs/IHUB_RemoteSolis.php'; $S = 'libs/IHUB_SolisDriver.php'; $M = 'InverterHub/module.php';
$X = [
    [$D, [['public static $spacingUs = 750000;', 'public static $spacingUs = 100000;']], 'Abstand zwischen Schreibbefehlen unter 700 ms'],
    [$R, [["['fc' => 16, 'addr' => IHUB_SolisDispatch::REG_SYSTEM, 'words' => IHUB_SolisDispatch::systemBlock(\$p), 'afterUs' => IHUB_SolisDispatch::\$spacingUs],", "['fc' => 16, 'addr' => IHUB_SolisDispatch::REG_REALTIME, 'words' => IHUB_SolisDispatch::realtimeBlock(\$p), 'afterUs' => IHUB_SolisDispatch::\$spacingUs],"], ["['fc' => 16, 'addr' => IHUB_SolisDispatch::REG_REALTIME, 'words' => IHUB_SolisDispatch::realtimeBlock(\$p), 'afterUs' => 0],", "['fc' => 16, 'addr' => IHUB_SolisDispatch::REG_SYSTEM, 'words' => IHUB_SolisDispatch::systemBlock(\$p), 'afterUs' => 0],"]], 'Echtzeitblock vor dem Systemblock'],
    [$D, [['return [1, self::failsafe($p), $bits, (int)round($imp / 100), (int)round($exp / 100)];', 'return [1, self::failsafe($p), $bits, $imp > 0 ? (int)round($imp / 100) : 0xFFFF, $exp > 0 ? (int)round($exp / 100) : 0xFFFF];']], 'Grenzen aus als 0xFFFF geschrieben'],
    [$D, [['$u = $v < 0 ? $v + 4294967296 : $v;', '$u = abs($v);']], 'Vorzeichen der Leistung geht verloren'],
    [$D, [['return $max === 0 ? 100 : $max;', 'return $max;']], 'obere SOC-Grenze 0 bleibt 0'],
    [$D, [["\$w = (\$mode === self::MODE_STANDBY) ? 0 : (int)(\$p['watts'] ?? 0);", "\$w = (int)(\$p['watts'] ?? 0);"]], 'Standby schreibt die gemerkte Leistung'],
    [$D, [['$pv | ($gc << 4)', '$pv | ($gc << 2)']], 'Netzladen-Bits an der falschen Stelle'],
    [$R, [["if (!\$cap['available']) {", 'if (false) {']], 'Fähigkeit wird nicht geprüft'],
    [$R, [["\$diff = IHUB_SolisDispatch::compare(\$back['regs'], IHUB_SolisDispatch::systemBlock(\$p), IHUB_SolisDispatch::realtimeBlock(\$p));", "\$diff = '';"]], 'Rücklesen vergleicht nichts'],
    [$R, [["if (\$back['status'] === 0) {", 'if (false) {']], 'Status 34504 = 0 wird ignoriert'],
    [$R, [["'words' => [0], 'afterUs' => 0]", "'words' => [1], 'afterUs' => 0]"]], 'Aus schreibt 1 statt 0'],
    [$D, [['const FAILSAFE_DEFAULT_MIN = 5;', 'const FAILSAFE_DEFAULT_MIN = 10;']], 'Totmann-Vorgabe geändert'],
    [$D, [['min(self::FAILSAFE_MAX_MIN, $f)', '$f']], 'Totmann nicht begrenzt'],
    [$D, [['|| $min > $max) {', ') {']], 'SOC-Bereich mit Untergrenze über Obergrenze akzeptiert'],
    [$R, [["'afterUs' => IHUB_SolisDispatch::\$spacingUs],", "'afterUs' => 0],"]], 'kein Abstand zwischen den Blöcken'],
    [$D, [['$mb->beginBatch();', '']], 'Rücklesen ohne gemeinsame Verbindung (Batch)'],
    [$S, [["\$hub->SetVarInt('ctl_dispatch_mode', (int)\$value);\n                return;", "return;"]], 'Modus wird nach Erfolg nicht gemerkt'],
    [$S, [['$known = $ver > 0 ?', '$known = $ver > 99 ?']], 'Fähigkeit wird nie aus dem Zyklus übernommen'],
    [$S, [['$d = IHUB_SolisDispatch::probe($mb);', '$d = null;']], 'langsamer Zyklus liest die Fähigkeit nicht'],
    [$S, [["if (\$hub->GetPropBool('GroupControl')) {\n            \$d =", "if (true) {\n            \$d ="]], 'Fähigkeit auch ohne Gruppe Steuerung gelesen'],
    [$S, [["\$d['available'] ? \$d['version'] : 0", "\$d['version']"]], 'Version auch ohne Dispatch gemeldet'],
    [$S, [['max(-IHUB_SolisDispatch::POWER_MAX_W, min(IHUB_SolisDispatch::POWER_MAX_W, (float)$value))', '(float)$value']], 'Leistungsvorgabe nicht begrenzt'],
    [$S, [['(int)(round(max(0, min(IHUB_SolisDispatch::POWER_MAX_W, (float)$value)) / 100) * 100)', '(int)(round(max(0, min(IHUB_SolisDispatch::POWER_MAX_W, (float)$value)) / 10) * 10)']], 'Grenze nicht auf 100 W gerundet'],
    [$M, [["'modeID' => \$find('ctl_dispatch_mode')", "'modeID' => 0"]], 'Vertrag: modeID fehlt'],
    [$M, [["'contractVersion'  => '1.10'", "'contractVersion'  => '1.9'"]], 'Vertragsversion nicht erhöht'],
    [$M, [["if (\$find('ctl_dispatch_mode') > 0) {", 'if (true) {']], 'Vertrag: dispatch auch ohne Variablen gefüllt'],
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
        $pr = proc_open('php ' . escapeshellarg($jobs[$i]['dir'] . '/.tools/test-solis-dispatch.php') . ' 2>&1', [1 => ['pipe', 'w']], $pp);
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
