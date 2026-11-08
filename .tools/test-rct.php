<?php
require_once __DIR__ . '/ihub-text.inc.php';
/**
 * Prüfstand RCT Power (Stufe 1, nur Lesen): Rahmen, CRC, Maskierung und Treiber gegen einen
 * simulierten RCT-Server (eigener Prozess, unabhängige Frame-Bauweise mit Tabellen-CRC).
 *   php .tools/test-rct.php               # 0 = alles bestanden
 *   php .tools/test-rct.php --mutations   # jede absichtliche Verfälschung des Clients MUSS auffallen
 */
$self = __FILE__;
$mode = $argv[1] ?? '';

// ---- Simulierter Server (Kindprozess) --------------------------------------------------------
if ($mode === '--server') {
    $behavior = $argv[2];
    $vals = [
        0x959930BF => "\x3e\x97\xb1\x91",                 // SOC 0,296 (Beispiel der rctclient-Doku)
        0x400F015B => pack('G', 1500.0),                   // Batterie
        0x91617C58 => pack('G', 400.0),                    // Netz (Rohwert + = Bezug)
        0xDB2D69AE => pack('G', 1000.0),                   // AC
        0xDB11855B => pack('G', 2000.0),                   // PV A
        0x0CB5D21B => pack('G', 500.0),                    // PV B
        0x2D2B0102 => "\x2b\x2d\x00\x00",                  // OID und Nutzdaten mit '+' und '-'
        0x7924ABD9 => 'RCT12',                             // Zeichenkette, ungerade Länge (7 Byte Daten)
        0x71765BD8 => pack('N', 0xFFFFFFFE),               // Int32 -2
        0x22222222 => '',                                  // leere Nutzdaten (Länge nur die OID)
    ];
    if ($behavior === 'full') {
        $vals += [
            0x65EED11B => pack('G', 380.0),                // Turm 1 Spannung
            0x21961B58 => pack('G', -10.0),                // Turm 1 Strom
            0x663F1452 => "\x02",                          // Anzahl Türme (1 Byte)
            0xA7FA5C5D => pack('G', 750.0),                // Gesamtspannung
            0xB408E40A => pack('G', -20.0),                // Gesamtstrom
            0x79D7D617 => pack('G', -10.0),                // Turm 2 Strom
            0xFCA1CBB5 => pack('G', 372.0),                // Turm 2 Spannung
            0x8B4BE168 => pack('G', 0.5),                  // Turm 2 SOC
            0x381B8BF9 => pack('G', 0.97),                 // Turm 1 SOH
            0x1781CD31 => pack('G', 7.5),                  // Turm 2 SOH ausserhalb 0..1 (muss verworfen werden)
            0xDC667958 => "\x03",                          // power_mng.state
            0x315D1490 => "\x02",                          // bat_empty_full: Bit 1 = war voll
            0xF168B748 => "\x02",                          // soc_strategy = extern
            0xBD008E29 => pack('G', -3000.0),              // battery_power_extern
            0x36A9E9A6 => "\x01",                          // use_grid_power_enable
            0xCE266F0F => pack('G', 0.07),                 // soc_min
        ];
    }
    $tab = [];
    for ($i = 0; $i < 256; $i++) { $c = $i << 8; for ($b = 0; $b < 8; $b++) { $c = ($c & 0x8000) ? (($c << 1) ^ 0x1021) & 0xFFFF : ($c << 1) & 0xFFFF; } $tab[$i] = $c; }
    $crc = function (string $d) use ($tab) { if (strlen($d) & 1) { $d .= "\0"; } $c = 0xFFFF; foreach (str_split($d) as $ch) { $c = (($c << 8) & 0xFFFF) ^ $tab[(($c >> 8) ^ ord($ch)) & 0xFF]; } return $c; };
    $esc = function (string $d) { $o = ''; foreach (str_split($d) as $ch) { if ($ch === '+' || $ch === '-') { $o .= '-'; } $o .= $ch; } return $o; };
    $frame = function (int $oid, string $payload, bool $badCrc = false) use ($crc, $esc) {
        $body = "\x05" . chr(4 + strlen($payload)) . pack('N', $oid) . $payload;
        $c = $crc($body) ^ ($badCrc ? 0x0101 : 0);
        return "\x00+" . $esc($body . pack('n', $c));
    };
    $srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    echo stream_socket_get_name($srv, false) . "\n"; fflush(STDOUT);
    while ($c = @stream_socket_accept($srv, 20)) {
        $buf = '';
        while (true) {
            $r = fread($c, 256);
            if ($r === '' || $r === false) { break; }
            $buf .= $r;
            // Anfrage entmaskieren: '+', dann 8 Byte (Befehl, Länge, OID, CRC)
            $p = strpos($buf, '+'); if ($p === false) { continue; }
            $q = $p + 1; $raw = '';
            while (strlen($raw) < 8 && $q < strlen($buf)) { if ($buf[$q] === '-') { if ($q + 1 >= strlen($buf)) { break; } $q++; } $raw .= $buf[$q]; $q++; }
            if (strlen($raw) < 8) { continue; }
            $buf = substr($buf, $q);
            if ($crc(substr($raw, 0, 6)) !== unpack('n', substr($raw, 6, 2))[1]) { continue; }
            $oid = unpack('N', substr($raw, 2, 4))[1];
            if ($behavior === 'silent' || !isset($vals[$oid])) { continue; }
            $resp = $frame($oid, $vals[$oid], $behavior === 'badcrc');
            if ($behavior === 'stray') { fwrite($c, $frame(0x11111111, "\x00\x00\x00\x01")); }
            if ($behavior === 'split') { fwrite($c, substr($resp, 0, 5)); fflush($c); usleep(150000); fwrite($c, substr($resp, 5)); }
            else { fwrite($c, $resp); }
        }
        fclose($c);
    }
    exit(0);
}

// ---- Mutationen ------------------------------------------------------------------------------
$mutants = [
    'CRC ohne Nullbyte bei ungerader Länge' => ['if (strlen($data) % 2 === 1) {', 'if (false) {'],
    'CRC-Startwert 0x0000'                  => ['$crc = 0xFFFF;', '$crc = 0x0000;'],
    'Anfrage ohne Maskierung'               => ["if (\$c === self::START || \$c === self::ESC) {\n                \$out .= chr(self::ESC);\n            }", ''],
    'Antwort ohne Entmaskierung'            => ['if ($c === self::ESC) {', 'if (false) {'],
    'Prüfsumme der Antwort nicht geprüft'   => ['if (self::crc16($raw . $data) !== $crcIn) {', 'if (false) {'],
    'OID der Antwort nicht verglichen'      => ['if ($r[\'oid\'] === $oid) {', 'if (true) {'],
    'Float little endian'                   => ["unpack('G', \$p)", "unpack('g', \$p)"],
    'Länge ohne OID gezählt'                => ['if ($len < 4 || $len > 4096) {', 'if ($len < 5 || $len > 4096) {'],
];
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
$a = strpos($src, 'class IHUB_RctClient');
$b = strpos($src, 'class IHUB_RctPowerDriver');
$e = strpos($src, "\n}\n", $b);
if ($a === false || $b === false || $e === false) { fwrite(STDERR, "RCT-Klassen nicht gefunden\n"); exit(2); }
$code = substr($src, $a, $e + 3 - $a);
if (strpos($mode, '--mutant=') === 0) {
    $keys = array_keys($mutants); $m = $mutants[$keys[(int)substr($mode, 9)]];
    if (substr_count($code, $m[0]) !== 1) { fwrite(STDERR, "Mutation passt nicht (" . $keys[(int)substr($mode, 9)] . ")\n"); exit(2); }
    $code = str_replace($m[0], $m[1], $code);
}
if ($mode === '--mutations') {
    $bad = 0;
    foreach (array_keys($mutants) as $i => $name) {
        exec('php ' . escapeshellarg($self) . ' --mutant=' . $i . ' 2>&1', $o, $rc);
        $caught = ($rc === 1 || $rc === 255);
        echo ($caught ? "  ok   " : "  FAIL ") . "Mutation erkannt: $name" . ($rc === 2 ? ' (Mutation ungültig)' : '') . "\n";
        if (!$caught) { $bad++; }
        $o = [];
    }
    echo $bad === 0 ? "ALLE MUTATIONEN ERKANNT\n" : "$bad MUTATION(EN) NICHT ERKANNT\n";
    exit($bad === 0 ? 0 : 1);
}

// ---- Prüfungen -------------------------------------------------------------------------------
define('VARIABLETYPE_FLOAT', 2);
interface IHUB_InverterDriverInterface {}
eval($code);
$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }

echo "1) Rahmen und CRC gegen die Beispiele der rctclient-Doku\n";
ok(bin2hex(IHUB_RctClient::buildReadFrame(0x959930BF)) === '2b0104959930bf0d65', 'Leseanfrage battery.soc = 2b 01 04 959930bf 0d65');
ok(IHUB_RctClient::crc16(hex2bin('0508959930bf3e97b191')) === 0x9c86, 'CRC der Antwort = 9c86 (gerade Länge)');
$p = IHUB_RctClient::parseFrame(hex2bin('002b0508959930bf3e97b1919c86'));
ok($p['status'] === 'ok' && $p['oid'] === 0x959930BF && bin2hex($p['payload']) === '3e97b191', 'Antwort mit führendem 0x00 wird zerlegt');
ok(abs(unpack('G', $p['payload'])[1] - 0.296) < 0.001, 'Nutzdaten sind Float 0,296');
$q = IHUB_RctClient::parseFrame(hex2bin('002b0508959930bf3e97b191'));
ok($q['status'] === 'incomplete', 'abgeschnittener Rahmen = unvollständig');
$bad = hex2bin('002b0508959930bf3e97b1919c87');
ok(IHUB_RctClient::parseFrame($bad)['status'] === 'crc', 'falsche Prüfsumme wird erkannt');
$f = IHUB_RctClient::buildReadFrame(0x2D2B0102);
ok(substr_count($f, '-') >= 1 && bin2hex(substr($f, 0, 1)) === '2b', 'Anfrage mit \'-\' und \'+\' in der OID wird maskiert');

echo "2) Client gegen simulierten Server\n";
function server(string $behavior, &$proc, &$port) {
    global $self;
    $proc = proc_open(['php', $self, '--server', $behavior], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $line = trim(fgets($pipes[1]));
    $port = (int)substr($line, strrpos($line, ':') + 1);
    return $pipes;
}
foreach (['normal', 'split', 'stray'] as $beh) {
    $pipes = server($beh, $proc, $port);
    $c = new IHUB_RctClient('127.0.0.1', $port, 2.0);
    $v = $c->readFloat(0x959930BF);
    ok($v !== null && abs($v - 0.296) < 0.001, "[$beh] SOC gelesen");
    ok($c->readFloat(0x2D2B0102) !== null && $c->crcErrors === 0, "[$beh] OID und Nutzdaten mit '+' und '-' (Maskierung beidseitig)");
    ok($c->readString(0x7924ABD9) === 'RCT12', "[$beh] Zeichenkette ungerader Länge (CRC mit Auffüllbyte)");
    ok($c->readInt32(0x71765BD8) === -2, "[$beh] Int32 mit Vorzeichen");
    ok($c->read(0x22222222) === '' && $c->readFloat(0x22222222) === null, "[$beh] leere Nutzdaten (Länge 4) = kein Wert");
    $c->close(); proc_terminate($proc); proc_close($proc);
}
$pipes = server('badcrc', $proc, $port);
$c = new IHUB_RctClient('127.0.0.1', $port, 1.0);
ok($c->readFloat(0x959930BF) === null && $c->crcErrors >= 1, '[badcrc] fehlerhafte Prüfsumme liefert null, nicht einen Wert');
$c->close(); proc_terminate($proc); proc_close($proc);
$pipes = server('silent', $proc, $port);
$c = new IHUB_RctClient('127.0.0.1', $port, 0.5);
$t = microtime(true);
$r = [$c->readFloat(0x959930BF), $c->readFloat(0x400F015B), $c->readFloat(0x91617C58), $c->readFloat(0xDB2D69AE)];
ok($r === [null, null, null, null], '[silent] keine Antwort = null');
ok(microtime(true) - $t < 2.5, '[silent] gibt nach zwei stummen Anfragen auf (kein Warten auf jedes Objekt)');
$c->close(); proc_terminate($proc); proc_close($proc);

echo "3) Treiber\n";
class HubMock {
    public $v = []; public $groups = ['GroupPV' => true, 'GroupBat' => true, 'GroupEnergy' => true, 'GroupDevice' => true, 'GroupBatTowers' => false, 'GroupBatState' => false, 'GroupControlState' => false];
    function SetVarFloat($i, $x) { $this->v[$i] = $x; } function SetVarInt($i, $x) { $this->v[$i] = $x; }
    function SetVarBool($i, $x) { $this->v[$i] = $x; } function SetVarStr($i, $x) { $this->v[$i] = $x; }
    function GetPropBool($n) { return $this->groups[$n] ?? false; }
}
$pipes = server('normal', $proc, $port);
$d = new IHUB_RctPowerDriver(); $h = new HubMock();
$c = new IHUB_RctClient('127.0.0.1', $port, 2.0);
try { $d->readFast($c, $h); } catch (Throwable $e) { echo '  FAIL readFast warf: ' . $e->getMessage() . "\n"; $fail++; }
ok(($h->v['connected'] ?? null) === true, 'verbunden');
ok($h->v['bat_soc'] === 30, 'SOC 0,296 -> 30 %');
ok($h->v['bat_power'] === 1500.0, 'Batterieleistung unverändert');
ok($h->v['meter_total'] === -400.0, 'Netz: Rohwert + = Bezug wird zu − (Einspeisung positiv)');
ok($h->v['pv_total'] === 2500.0 && $h->v['mppt1_power'] === 2000.0 && $h->v['mppt2_power'] === 500.0, 'PV Gesamt und Generator A/B');
ok(!array_key_exists('bat_volt', $h->v), 'nicht beantwortete Objekte überschreiben nichts');
$d->readDeviceInfo($c, $h);
ok(($h->v['dev_sn'] ?? '') === 'RCT12', 'Seriennummer');
$c->close(); proc_terminate($proc); proc_close($proc);
$pipes = server('silent', $proc, $port);
$h = new HubMock(); $c = new IHUB_RctClient('127.0.0.1', $port, 0.5);
$thrown = false; try { $d->readFast($c, $h); } catch (RuntimeException $e) { $thrown = true; }
ok($thrown && $h->v['connected'] === false && count($h->v) === 1, 'keine Antwort: verbunden = false, Meldung, sonst nichts geschrieben');
$c->close(); proc_terminate($proc); proc_close($proc);

echo "3b) Stufe 2a: Türme, Batteriezustand, Steuerungszustand (nur Anzeige)\n";
$pipes = server('full', $proc, $port);
$d = new IHUB_RctPowerDriver(); $h = new HubMock();
$h->groups['GroupBatTowers'] = $h->groups['GroupBatState'] = $h->groups['GroupControlState'] = true;
$c = new IHUB_RctClient('127.0.0.1', $port, 2.0);
try { $d->readFast($c, $h); $d->readSlow($c, $h); } catch (Throwable $e) { echo '  FAIL Lesen warf: ' . $e->getMessage() . "\n"; $fail++; }
ok($h->v['bat_volt_total'] === 750.0 && $h->v['bat_curr_total'] === -20.0, 'Gesamtspannung und Gesamtstrom');
ok($h->v['bat1_power'] === 380.0 * -10.0, 'Turm 1 Leistung = Spannung x Strom');
ok($h->v['bat2_volt'] === 372.0 && $h->v['bat2_curr'] === -10.0 && $h->v['bat2_power'] === 372.0 * -10.0, 'Turm 2 Spannung, Strom, Leistung');
ok($h->v['bat2_soc'] === 50, 'Turm 2 SOC 0,5 -> 50 %');
ok($h->v['bat_tower_count'] === 2, 'Anzahl der Türme (Antwort mit 1 Byte)');
ok($h->v['bat_soh'] === 97, 'Turm 1 SOH 0,97 -> 97 %');
ok(!array_key_exists('bat2_soh', $h->v), 'SOH ausserhalb 0..1 wird verworfen, nichts geschrieben');
ok($h->v['bat_mode_state'] === 3, 'power_mng.state');
ok($h->v['bat_was_empty'] === false && $h->v['bat_was_full'] === true, 'bat_empty_full: Bit 0 / Bit 1');
ok($h->v['rct_strategy'] === 2 && $h->v['rct_external_active'] === true, 'Strategie 2 = externe Steuerung aktiv');
ok($h->v['rct_power_extern'] === -3000.0 && $h->v['rct_use_grid'] === true && $h->v['rct_soc_min'] === 7, 'externe Sollleistung, Netzbezug erlaubt, Mindest-SOC');
$c->close(); proc_terminate($proc); proc_close($proc);
// Gruppen aus: keine der neuen Variablen wird angefasst
$pipes = server('full', $proc, $port);
$h = new HubMock(); $c = new IHUB_RctClient('127.0.0.1', $port, 2.0);
$d->readFast($c, $h); $d->readSlow($c, $h);
$neu = ['bat_volt_total', 'bat2_volt', 'bat2_soc', 'bat_tower_count', 'bat_soh', 'bat_mode_state', 'rct_strategy', 'rct_external_active', 'rct_power_extern'];
ok(array_intersect($neu, array_keys($h->v)) === [], 'Gruppen aus: keine neue Variable wird beschrieben');
$c->close(); proc_terminate($proc); proc_close($proc);
// Strategie 4 (intern): Warnvariable aus; Antwort mit 4 Byte wird ebenfalls als Zahl gelesen
$d2 = new IHUB_RctPowerDriver();
$stub = new class { function read($oid) { return $oid === 0xF168B748 ? pack('N', 4) : null; } function readFloat($oid) { return null; } };
$h = new HubMock(); $h->groups['GroupControlState'] = true;
$d2->readSlow($stub, $h);
ok(($h->v['rct_strategy'] ?? null) === 4 && $h->v['rct_external_active'] === false, 'Strategie 4 (4-Byte-Antwort) = intern, Warnung aus');
$stub6 = new class { function read($oid) { return $oid === 0xF168B748 ? "\x09" : null; } function readFloat($oid) { return null; } };
$h = new HubMock(); $h->groups['GroupControlState'] = true;
$d2->readSlow($stub6, $h);
ok(!array_key_exists('rct_strategy', $h->v), 'unbekannte Strategie (9) wird nicht geschrieben');

echo "4) Nur Lesen\n";
ok(strpos($code, 'CMD_WRITE') === false && !preg_match('/chr\(0x0[23]\)/', $code), 'kein Schreibbefehl im Client');
// Steuer-Objekte dürfen nur gelesen werden: jede Verwendung muss ein Lesezugriff oder die Konstante selbst sein.
$rest = preg_replace('/(readUInt\(\$mb, |readFloat\()self::OID_(STRATEGY|POWER_EXTERN|USE_GRID|SOC_MIN)\)/', '', $code);
$rest = preg_replace('/const OID_(STRATEGY|POWER_EXTERN|USE_GRID|SOC_MIN)\b/', '', $rest);
ok(!preg_match('/OID_(STRATEGY|POWER_EXTERN|USE_GRID|SOC_MIN)\b/', $rest), 'Steuer-Objekte werden nur gelesen (readUInt/readFloat), nirgends sonst verwendet');
ok(!preg_match('/0x97997C93/', $code) && substr_count($code, 'buildReadFrame') === 2, 'kein weiterer Rahmenbauer: nur buildReadFrame (Definition und Aufruf)');
ok(preg_match('/function writeControl\(\$mb, \$hub, string \$ident, \$value\)\s*\{\s*\}/', $code) === 1, 'writeControl ist leer');

echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
