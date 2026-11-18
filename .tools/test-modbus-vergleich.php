<?php
/**
 * Vergleichslauf alter gegen neuer Modbus-Client (0.90.0, Umstellung auf NRGModbus).
 *
 *   php .tools/test-modbus-vergleich.php [Vergleichs-Commit]     # 0 = Verhalten gleich
 *
 * Holt IHUB_ModbusTcpClient und IHUB_ModbusGatewayClient aus dem Stand VOR der Umstellung (Standard: 69bbfcc, 0.89.1),
 * benennt sie in OLD_… um und fährt dieselben Abläufe gegen denselben Prüfserver (Modi wie in .tools/test-modbus-client.php,
 * dazu Schreiben und Gateway). Verglichen werden Rückgaben, lastError-Texte, Zahl der Verbindungen und die am Server
 * angekommenen Schreibframes. Jede Abweichung wird einzeln gemeldet; erwartete, begründete Abweichungen stehen in $accepted.
 */
require_once __DIR__ . '/ihub-text.inc.php';
require_once dirname(__DIR__) . '/libs/NRGModbus/autoload.php';
$ref = $argv[1] ?? '69bbfcc';
$root = dirname(__DIR__);
foreach (['IHUB_ModbusTcpClient', 'IHUB_ModbusGatewayClient'] as $cls) {
    $old = shell_exec('cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg("$ref:libs/$cls.php") . ' 2>/dev/null');
    if (!$old || !str_contains($old, "class $cls")) { fwrite(STDERR, "Altstand von $cls nicht lesbar ($ref)\n"); exit(2); }
    eval('?>' . str_replace("class $cls", "class OLD_$cls", $old));
}
require_once $root . '/libs/IHUB_ModbusGatewayClient.php';
require_once $root . '/libs/IHUB_ModbusTcpClient.php';

$fails = 0; $total = 0;
function check(string $l, $c, string $d = ''): void { global $fails, $total; $total++; if ($c) { echo "  ok    $l\n"; } else { $fails++; echo "  FEHLT $l" . ($d !== '' ? "  ($d)" : '') . "\n"; } }

function startServer(string $mode): array
{
    $cnt = tempnam(sys_get_temp_dir(), 'ihvc'); $wl = tempnam(sys_get_temp_dir(), 'ihvw');
    $proc = proc_open([PHP_BINARY, __DIR__ . '/ref/modbus-test-server.php', $mode, $cnt, $wl], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $port = (int)substr((string)fgets($pipes[1]), 5);
    return [$proc, $port, $cnt, $wl];
}
function stopServer(array $s): void { proc_terminate($s[0]); proc_close($s[0]); @unlink($s[2]); @unlink($s[3]); }
function conns(array $s): int { usleep(100000); return (int)@file_get_contents($s[2]); }
function wlog(array $s): array { usleep(60000); return array_values(array_filter(explode("\n", (string)file_get_contents($s[3])))); }

/** Fester Ablauf; liefert alles Beobachtbare als Liste. */
function scenario(string $class, int $port, array $srv, bool $batch): array
{
    $o = [];
    $mb = new $class('127.0.0.1', $port, 1);
    if ($batch) { $mb->beginBatch(); }
    $o['c0'] = conns($srv);
    $r = $mb->readHolding(100, 4);          $o['r1'] = $r;
    $r = $mb->readInput(5, 2);              $o['r2'] = $r;
    $o['c1'] = conns($srv);
    $o['w1'] = $mb->writeSingle(7, 0x1FFFF); $o['e1'] = $mb->lastError;
    $o['c2'] = conns($srv);
    $o['w2'] = $mb->writeMultiple(9, [1, 0xFFFF, 3]); $o['e2'] = $mb->lastError;
    $r = $mb->readHolding(300, 2);          $o['r3'] = $r;
    $o['c3'] = conns($srv);
    $mb->unitId = 9;
    $o['w3'] = $mb->writeSingle(1, 1);
    $r = $mb->readHolding(1, 1);            $o['r4'] = $r;
    if ($batch) { $mb->endBatch(); }
    $r = $mb->readHolding(400, 1);          $o['r5'] = $r;
    $o['c4'] = conns($srv);
    $o['dec'] = [$mb->u16([0xFFFF, 1], 0), $mb->s16([0xFFFF], 0), $mb->u32([1, 2], 0), $mb->s32([0xFFFF, 0xFFFE], 0), $mb->readStr([0x4142, 0x4300], 0, 2), $mb->readFloat32([0x42C8, 0], 0), $mb->readFloat32([0x42C8, 0], 5)];
    $mb->setFloatWordSwap(true);
    $o['dec2'] = $mb->readFloat32([0, 0x42C8], 0);
    return $o;
}

$accepted = ['split' => 'der alte Client las die Schreibantwort mit einem einzigen fread(64): kam sie in Stücken, galt sie als "keine Antwort" (Fehler des alten Clients, der neue liest exakt nach Frame-Länge)', 'split mit Batch' => 'dto.'];   // 'Modus/Abschnitt' => Begründung
foreach (['normal', 'dropafter1', 'exception', 'wexception', 'stray', 'wstray', 'badfc', 'shortdata', 'split'] as $mode) {
    foreach ([false, true] as $batch) {
        $label = "$mode" . ($batch ? ' mit Batch' : '');
        if ($mode === 'dropafter1' && $batch) { continue; }
        $sa = startServer($mode); $a = scenario('OLD_IHUB_ModbusTcpClient', $sa[1], $sa, $batch); $wa = wlog($sa); stopServer($sa);
        $sb = startServer($mode); $b = scenario('IHUB_ModbusTcpClient', $sb[1], $sb, $batch); $wb = wlog($sb); stopServer($sb);
        $diff = [];
        foreach ($a as $k => $v) { if ($v !== ($b[$k] ?? null)) { $diff[] = "$k: alt " . json_encode($v) . ' neu ' . json_encode($b[$k] ?? null); } }
        if ($wa !== $wb) { $diff[] = 'Schreibframes: alt ' . json_encode($wa) . ' neu ' . json_encode($wb); }
        check("TCP $label: Rückgaben, Fehlertexte, Verbindungszahl, Schreibframes gleich" . (isset($accepted[$label]) ? ' (erwartet abweichend: ' . $accepted[$label] . ')' : ''), $diff === [] || isset($accepted[$label]), implode(' | ', $diff));
    }
}

// Fehlerfälle ohne Server und stummer Server (Zeit mitprüfen)
$a = new OLD_IHUB_ModbusTcpClient('127.0.0.1', 1, 1); $b = new IHUB_ModbusTcpClient('127.0.0.1', 1, 1);
check('kein Server, Lesen: beide null', $a->readHolding(1, 1) === null && $b->readHolding(1, 1) === null);
$t0 = microtime(true); $wa = $a->writeSingle(1, 1); $da = microtime(true) - $t0; $ea = $a->lastError;
$t0 = microtime(true); $wb = $b->writeSingle(1, 1); $db = microtime(true) - $t0; $eb = $b->lastError;
check('kein Server, Schreiben: beide false, derselbe Text', $wa === false && $wb === false && $ea === $eb, "alt '$ea' neu '$eb'");
check('kein Server, Schreiben: beide wiederholen das Öffnen mit 1,5 s Pause', $da >= 1.4 && $db >= 1.4 && abs($da - $db) < 0.5, round($da, 2) . ' / ' . round($db, 2));
$s = startServer('silent');
$a = new OLD_IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1); $b = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$t0 = microtime(true); $ra = $a->readHolding(1, 1); $da = microtime(true) - $t0;
$t0 = microtime(true); $rb = $b->readHolding(1, 1); $db = microtime(true) - $t0;
check('stummer Server, Lesen: beide null nach rund 3 s', $ra === null && $rb === null && $da > 2.8 && $db > 2.8 && $da < 4 && $db < 4, round($da, 2) . ' / ' . round($db, 2));
$wa = $a->writeSingle(1, 1); $ea = $a->lastError; $wb = $b->writeSingle(1, 1); $eb = $b->lastError;
check('stummer Server, Schreiben: beide false, derselbe Text', $wa === false && $wb === false && $ea === $eb && $ea !== '', "alt '$ea' neu '$eb'");
stopServer($s);

// Schreiben auf eigener Verbindung bei laufendem Batch (Punkt 1 der offenen Punkte)
$s = startServer('normal'); $b = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$b->beginBatch(); $b->readHolding(1, 1); $b->writeSingle(2, 2); $b->readHolding(3, 1); $b->writeMultiple(4, [1]); $b->readHolding(5, 1);
check('Batch läuft weiter: zwei Schreibzugriffe auf je eigener Verbindung, Lesen auf der Batch-Verbindung (3 Verbindungen)', conns($s) === 3, (string)conns($s));
stopServer($s);

// Gateway
$mkModule = function () {
    return new class {
        public $calls = []; public $reply = null;
        public function ForwardToGateway(string $dataId, int $function, int $address, int $quantity, string $data) {
            $this->calls[] = [$dataId, $function, $address, $quantity, bin2hex($data)];
            if (is_callable($this->reply)) { return ($this->reply)($function, $address, $quantity); }
            return $this->reply;
        }
    };
};
$replies = [
    'normal'  => function ($f, $a, $q) { $d = ''; for ($i = 0; $i < $q; $i++) { $d .= pack('n', $a + $i); } return ($f === 3 || $f === 4) ? "\xFF\xFF" . $d : "\x10\x02"; },
    'kurz'    => fn($f, $a, $q) => "\x03\x02" . pack('n', 7),
    'lang'    => fn($f, $a, $q) => "\x03\x06" . pack('n3', 1, 2, 3),
    'false'   => fn($f, $a, $q) => false,
    'leer'    => fn($f, $a, $q) => '',
    'einbyte' => fn($f, $a, $q) => "\x03",
    'null'    => fn($f, $a, $q) => null,
];
foreach ($replies as $name => $reply) {
    $res = [];
    foreach (['OLD_IHUB_ModbusGatewayClient', 'IHUB_ModbusGatewayClient'] as $cls) {
        $m = $mkModule(); $m->reply = $reply; $logs = [];
        $g = new $cls($m, 502, 5, function ($t) use (&$logs) { $logs[] = $t; });
        $o = [$g->readHolding(100, 3), $g->readInput(200, 2), $g->writeSingle(300, 0xFFFF), $g->writeMultiple(301, [0xFFFF, 0x8001]), $g->readHolding(1, 1), $g->requests, $g->responses, $m->calls, $logs, $g->unitId];
        $res[] = json_encode($o);
    }
    check("Gateway Antwort '$name': Rückgaben, Aufrufe der Weiterleitung, Zähler, Hinweis, unitId gleich", $res[0] === $res[1], $res[0] . ' <> ' . $res[1]);
}
$r1 = (new OLD_IHUB_ModbusGatewayClient(new stdClass, 502, 1))->readHolding(1, 1); $g2 = new IHUB_ModbusGatewayClient(new stdClass, 502, 1);
check('Gateway ohne ForwardToGateway(): null, keine Anfrage gezählt', $r1 === null && $g2->readHolding(1, 1) === null && $g2->requests === 0);

echo "\n$total Prüfungen, " . ($fails === 0 ? "ALLE BESTANDEN\n" : "$fails FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
