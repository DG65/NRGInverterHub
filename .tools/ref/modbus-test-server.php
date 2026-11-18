<?php
/**
 * Kleiner Modbus-TCP-Server für den Prüfstand (Vorlage: InverterHub und MeterHub test-modbus-client.php).
 *
 *   php tests/server.php <Modus> <Zählerdatei> <Schreibprotokoll>
 *
 * Meldet "PORT <n>". Register = eigene Adresse; Schreibzugriffe (FC 6/16) werden quittiert und als Hex ins
 * Schreibprotokoll geschrieben. Die Zählerdatei enthält die Zahl der angenommenen Verbindungen.
 * Modi: normal | dropafter1 (schließt nach der ersten Antwort) | exception (Antwort Exception 2 auf alles)
 *       | silent | stray (vor jeder Antwort ein fremder Frame mit falscher Transaktions-ID und 0xDEAD)
 *       | wstray (nur bei Schreibzugriffen ein fremder Frame vorweg) | wexception (Schreiben: Exception 3)
 *       | badfc (Antwort mit falschem Funktionscode) | badlen (Längenfeld 0) | split (Antwort byteweise mit Pause)
 *       | shortdata (Antwort mit nur der Hälfte der Register)
 *       | wbadtid (Schreibantworten tragen eine falsche Transaktions-ID, sonst unverändert)
 *       | silentafter1 (beantwortet je Verbindung nur die erste Anfrage, danach stumm)
 *       | strayflood (nie eine passende Antwort, alle 100 ms ein fremder Frame bis 2 s nach der Anfrage)
 */
[$mode, $cntFile, $wlog] = [$argv[1] ?? 'normal', $argv[2] ?? '/dev/null', $argv[3] ?? '/dev/null'];
$srv = stream_socket_server('tcp://127.0.0.1:0', $e, $es);
if (!$srv) { fwrite(STDERR, "kein Server: $es\n"); exit(1); }
$port = (int)substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
file_put_contents($cntFile, '0');
fwrite(STDOUT, "PORT $port\n"); fflush(STDOUT);
$conns = 0;
$clients = []; $buf = []; $served = [];
while (true) {
    $read = array_merge([$srv], $clients);
    $w = $x = null;
    if (@stream_select($read, $w, $x, 1) === false) { break; }
    foreach ($read as $c) {
        if ($c === $srv) {
            $n = @stream_socket_accept($srv, 0);
            if ($n) { $conns++; file_put_contents($cntFile, (string)$conns); stream_set_blocking($n, false); $clients[(int)$n] = $n; $buf[(int)$n] = ''; $served[(int)$n] = 0; }
            continue;
        }
        $id = (int)$c;
        $d = @fread($c, 4096);
        if ($d === false || ($d === '' && feof($c))) { fclose($c); unset($clients[$id], $buf[$id], $served[$id]); continue; }
        $buf[$id] .= $d;
        while (strlen($buf[$id]) >= 7) {
            $h = unpack('ntid/npid/nlen/Cunit', substr($buf[$id], 0, 7));
            if (strlen($buf[$id]) < 6 + $h['len']) { break; }
            $pdu = substr($buf[$id], 7, $h['len'] - 1);
            $buf[$id] = substr($buf[$id], 6 + $h['len']);
            $served[$id]++;
            $fc = ord($pdu[0]);
            $isWrite = ($fc === 6 || $fc === 16);
            if ($isWrite) { file_put_contents($wlog, 'u' . $h['unit'] . ' ' . bin2hex($pdu) . "\n", FILE_APPEND); }
            if ($mode === 'silent') { continue; }
            if ($mode === 'silentafter1' && $served[$id] > 1) { continue; }
            if ($mode === 'strayflood') {
                for ($k = 0; $k < 20; $k++) {
                    $bad = chr($fc) . chr(2) . pack('n', 0xDEAD);
                    @fwrite($c, pack('nnn', ($h['tid'] + 1000 + $k) & 0xFFFF, 0, strlen($bad) + 1) . chr($h['unit']) . $bad);
                    usleep(100000);
                }
                continue;
            }
            if ($mode === 'exception' || ($mode === 'wexception' && $isWrite)) {
                $resp = chr($fc | 0x80) . chr($mode === 'wexception' ? 3 : 2);
            } elseif ($fc === 3 || $fc === 4) {
                $a = unpack('nstart/ncount', substr($pdu, 1, 4));
                $n = $mode === 'shortdata' ? intdiv($a['count'], 2) : $a['count'];
                $data = '';
                for ($i = 0; $i < $n; $i++) { $data .= pack('n', ($a['start'] + $i) & 0xFFFF); }
                $resp = chr($mode === 'badfc' ? ($fc === 3 ? 4 : 3) : $fc) . chr(strlen($data)) . $data;
            } else {
                $resp = substr($pdu, 0, 5);
            }
            if ($mode === 'stray' || ($mode === 'wstray' && $isWrite)) {
                $bad = chr($fc) . chr(2) . pack('n', 0xDEAD);
                @fwrite($c, pack('nnn', ($h['tid'] + 1000) & 0xFFFF, 0, strlen($bad) + 1) . chr($h['unit']) . $bad);
            }
            $len = $mode === 'badlen' ? 0 : strlen($resp) + 1;
            $rt = ($mode === 'wbadtid' && $isWrite) ? ($h['tid'] + 1000) & 0xFFFF : $h['tid'];
            $out = pack('nnn', $rt, 0, $len) . chr($h['unit']) . $resp;
            if ($mode === 'split') {
                foreach (str_split($out, 3) as $part) { @fwrite($c, $part); usleep(20000); }
            } else {
                @fwrite($c, $out);
            }
            if ($mode === 'dropafter1') { fclose($c); unset($clients[$id], $buf[$id], $served[$id]); break; }
        }
    }
}
