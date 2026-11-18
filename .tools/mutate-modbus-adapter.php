<?php
/**
 * Mutationsproben an den Anpassungsklassen: jede Mutation baut einen Fehler in eine Kopie von libs/IHUB_Modbus*Client.php
 * ein, .tools/test-modbus-adapter.php muss anschlagen.   php .tools/mutate-modbus-adapter.php   (0 = alle erkannt)
 */
$root = dirname(__DIR__);
$M = [
    ['IHUB_ModbusTcpClient.php', "'openAttempts'   => 2,", "'openAttempts'   => 1,", 'Schreiben: nur ein Verbindungsversuch'],
    ['IHUB_ModbusTcpClient.php', "'verifyWriteTid' => false,", "'verifyWriteTid' => true,", 'Schreiben prüft die Transaktions-ID'],
    ['IHUB_ModbusTcpClient.php', "'openPauseUs'    => 1500000,", "'openPauseUs'    => 0,", 'Schreiben: keine Pause zwischen den Versuchen'],
    ['IHUB_ModbusTcpClient.php', "\$t->unitId = (int)\$this->unitId;", "", 'Unit-ID-Wechsel zur Laufzeit wirkt nicht'],
    ['IHUB_ModbusTcpClient.php', "\$t->port   = (int)\$this->port;", "", 'Port-Wechsel zur Laufzeit wirkt nicht'],
    ['IHUB_ModbusTcpClient.php', "\$this->sync(\$this->writer)->writeSingle(", "\$this->sync(\$this->reader)->writeSingle(", 'writeSingle benutzt die Batch-Verbindung'],
    ['IHUB_ModbusTcpClient.php', "\$this->sync(\$this->writer)->writeMultiple(", "\$this->sync(\$this->reader)->writeMultiple(", 'writeMultiple benutzt die Batch-Verbindung'],
    ['IHUB_ModbusTcpClient.php', "\$this->reader->endBatch();", "\$this->writer->endBatch();", 'endBatch schließt die falsche Verbindung'],
    ['IHUB_ModbusTcpClient.php', "\$this->sync(\$this->reader)->beginBatch();", "\$this->sync(\$this->writer)->beginBatch();", 'beginBatch öffnet die falsche Verbindung'],
    ['IHUB_ModbusTcpClient.php', "} elseif (\$w->lastException > 0) {", "} elseif (false) {", 'Exception-Text fehlt'],
    ['IHUB_ModbusTcpClient.php', "in_array(\$w->lastError, ['timeout', 'eof', 'write'], true)", "in_array(\$w->lastError, ['eof', 'write'], true)", 'Zeitlimit wird nicht als "keine Antwort" gemeldet'],
    ['IHUB_ModbusTcpClient.php', "\$this->lastError = '';\n            return true;", "return true;", 'lastError wird bei Erfolg nicht geleert'],
    ['IHUB_ModbusTcpClient.php', "\$detail = trim(\$w->lastErrno . ' ' . \$w->lastErrstr);", "\$detail = '';", 'Verbindungsfehler ohne Fehlernummer'],
    ['IHUB_ModbusTcpClient.php', "ModbusDecode::float32(\$regs, \$offset, \$this->floatWordSwap)", "ModbusDecode::float32(\$regs, \$offset, false)", 'floatWordSwap wird ignoriert'],
    ['IHUB_ModbusTcpClient.php', "'mode'           => 'single',\n            'openAttempts'", "'mode'           => 'cycle',\n            'openAttempts'", 'Schreiben im Zyklusmodus (bleibt offen)'],
    ['IHUB_ModbusGatewayClient.php', "['trimReply' => false]", "['trimReply' => true]", 'Gateway kürzt lange Antworten'],
    ['IHUB_ModbusGatewayClient.php', "base64_decode((string)(\$req['Data'] ?? ''), true)", "(string)(\$req['Data'] ?? '')", 'Gateway reicht Base64 statt roher Bytes weiter'],
    ['IHUB_ModbusGatewayClient.php', "\$this->requests++;", "", 'Gateway zählt keine Anfragen'],
    ['IHUB_ModbusGatewayClient.php', "\$this->responses++;", "", 'Gateway zählt keine Antworten'],
    ['IHUB_ModbusGatewayClient.php', "(int)\$req['Quantity']", "(int)\$req['Address']", 'Gateway: falsche Menge'],
    ['IHUB_ModbusGatewayClient.php', "\$this->notImplemented('writeMultiple/FC16');", "", 'Gateway: kein Hinweis zum Schreibweg'],
];
$tmp = sys_get_temp_dir() . '/ihmuta' . getmypid(); mkdir($tmp);
$jobs = [];
foreach ($M as $i => [$file, $search, $replace]) {
    $d = "$tmp/m$i"; mkdir($d);
    foreach (['IHUB_ModbusTcpClient.php', 'IHUB_ModbusGatewayClient.php'] as $f) { copy("$root/libs/$f", "$d/$f"); }
    $t = file_get_contents("$d/$file"); $p = strpos($t, $search);
    if ($p === false) { $jobs[$i] = ['err' => 'Suchtext nicht gefunden']; continue; }
    file_put_contents("$d/$file", substr($t, 0, $p) . $replace . substr($t, $p + strlen($search)));
    $jobs[$i] = ['dir' => $d, 'out' => ''];
}
$queue = array_keys(array_filter($jobs, fn($j) => isset($j['dir']))); $run = [];
while ($queue || $run) {
    while ($queue && count($run) < 5) {
        $i = array_shift($queue);
        $pr = proc_open('IHUB_ADAPTER_DIR=' . escapeshellarg($jobs[$i]['dir']) . ' php ' . escapeshellarg(__DIR__ . '/test-modbus-adapter.php') . ' 2>&1', [1 => ['pipe', 'w']], $pp);
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
foreach ($M as $i => [$file, , , $desc]) {
    $j = $jobs[$i];
    if (isset($j['err'])) { $bad++; echo "  FEHLER     [$file] $desc: {$j['err']}\n"; continue; }
    $failed = [];
    foreach (explode("\n", $j['out']) as $l) { if (str_starts_with($l, '  FEHLT ')) { $failed[] = trim(substr($l, 8)); } }
    if ($j['rc'] !== 0) { echo sprintf("  erkannt    [%s] %s  -> %s\n", $file, $desc, $failed ? mb_substr($failed[0], 0, 70) : 'Abbruch im Lauf'); }
    else { $bad++; echo "  UNGEFANGEN [$file] $desc\n"; }
}
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . count($M) . " Mutationen, " . (count($M) - $bad) . " erkannt, $bad ungefangen\n";
exit($bad === 0 ? 0 : 1);
