<?php
/**
 * Prüfstand der Anpassungsklassen IHUB_ModbusTcpClient und IHUB_ModbusGatewayClient (seit 0.90.0 über NRGModbus).
 *
 *   php .tools/test-modbus-adapter.php     # 0 = alle Prüfungen bestanden
 *   IHUB_ADAPTER_DIR=<Ordner mit den beiden Klassendateien> php .tools/test-modbus-adapter.php   # Mutationsproben
 *
 * Prüft, was die Treiber und das Hauptmodul an den Klassen sehen: Verhalten wie vor der Umstellung. Der gründliche
 * Gegenlauf alt gegen neu steht in .tools/test-modbus-vergleich.php (langsam, ca. 2 Minuten).
 */
require_once __DIR__ . '/ihub-text.inc.php';
require_once dirname(__DIR__) . '/libs/NRGModbus/autoload.php';
$dir = getenv('IHUB_ADAPTER_DIR') ?: dirname(__DIR__) . '/libs';
require_once $dir . '/IHUB_ModbusGatewayClient.php';
require_once $dir . '/IHUB_ModbusTcpClient.php';

$fails = 0; $total = 0;
function check(string $l, $c, string $d = ''): void { global $fails, $total; $total++; if ($c) { echo "  ok    $l\n"; } else { $fails++; echo "  FEHLT $l" . ($d !== '' ? "  ($d)" : '') . "\n"; } }
function startServer(string $mode): array
{
    $cnt = tempnam(sys_get_temp_dir(), 'ihac'); $wl = tempnam(sys_get_temp_dir(), 'ihaw');
    $proc = proc_open([PHP_BINARY, __DIR__ . '/ref/modbus-test-server.php', $mode, $cnt, $wl], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    return [$proc, (int)substr((string)fgets($pipes[1]), 5), $cnt, $wl];
}
function stopServer(array $s): void { proc_terminate($s[0]); proc_close($s[0]); @unlink($s[2]); @unlink($s[3]); }
function conns(array $s): int { usleep(100000); return (int)@file_get_contents($s[2]); }
function wlog(array $s): array { usleep(60000); return array_values(array_filter(explode("\n", (string)file_get_contents($s[3])))); }

echo "1) Lesen und Batch\n";
$s = startServer('normal'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$a = $mb->readHolding(100, 4); $b = $mb->readInput(5, 2);
check('Register = Adresse, Holding und Input', $a === [100, 101, 102, 103] && $b === [5, 6]);
check('Einzelverbindung: zwei Lesezugriffe, zwei Verbindungen', conns($s) === 2);
$mb->beginBatch(); $mb->readHolding(1, 1); $mb->readHolding(2, 1); $mb->readHolding(3, 1);
check('Batch: eine Verbindung für drei Lesezugriffe', conns($s) === 3);
$mb->endBatch(); $mb->readHolding(4, 1);
check('nach endBatch() wieder Einzelverbindung', conns($s) === 4);
stopServer($s);
$s = startServer('stray'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1); $mb->beginBatch();
$a = $mb->readHolding(41000, 1); $b = $mb->readHolding(41001, 1);
check('fremde Transaktions-ID beim Lesen verworfen (261,5-MW-Befund)', ($a[0] ?? null) === 41000 && ($b[0] ?? null) === 41001);
stopServer($s);
check('nicht erreichbar: Lesen null', (new IHUB_ModbusTcpClient('127.0.0.1', 1, 1))->readHolding(1, 1) === null);

echo "2) Schreiben auf eigener Verbindung, lastError als Klartext\n";
$s = startServer('normal'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
$mb->beginBatch(); $mb->readHolding(1, 1);
$ok1 = $mb->writeSingle(7, 0x1FFFF); $e1 = $mb->lastError; $mb->readHolding(2, 1);
$ok2 = $mb->writeMultiple(9, [1, 0xFFFF, 3]); $mb->readHolding(3, 1);
check('Schreiben bei laufendem Batch: true, lastError leer', $ok1 === true && $ok2 === true && $e1 === '');
check('zwei Schreibzugriffe auf je eigener Verbindung, Batch bleibt (3 Verbindungen)', conns($s) === 3, (string)conns($s));
check('Anfragen am Server wie gesendet (FC6 Wert maskiert, FC16 mit Byte-Zahl)', wlog($s) === ['u1 060007ffff', 'u1 1000090003060001ffff0003'], json_encode(wlog($s)));
$mb->unitId = 9; $mb->writeSingle(1, 1);
check('Unit-ID zur Laufzeit gewechselt (Victron, SMA): wirkt beim Schreiben', (wlog($s)[2] ?? '') === 'u9 0600010001', json_encode(wlog($s)));
$mb->unitId = 1; $mb->port = $s[1]; $mb->host = '127.0.0.1';
stopServer($s);
$s = startServer('wexception'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('Exception beim Schreiben: false, "exception code 3" (übersetzt)', $mb->writeSingle(1, 1) === false && $mb->lastError === IHUB_Text::t('exception code %s', 3), $mb->lastError);
stopServer($s);
$s = startServer('wstray'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('Schreiben ohne Prüfung der Transaktions-ID (erste Antwort gilt, wie bisher)', $mb->writeSingle(1, 1) === true && $mb->lastError === '');
stopServer($s);
$s = startServer('wbadtid'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('Schreibantwort mit fremder Transaktions-ID gilt (Prüfung beim Schreiben aus, wie bisher)', $mb->writeSingle(1, 1) === true && $mb->writeMultiple(2, [1]) === true);
$mb->lastError = 'alt';
check('lastError wird bei Erfolg geleert', $mb->writeSingle(1, 1) === true && $mb->lastError === '');
stopServer($s);
$s = startServer('silent'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', $s[1], 1);
check('keine Antwort: false, "no answer"', $mb->writeSingle(1, 1) === false && $mb->lastError === IHUB_Text::t('no answer'), $mb->lastError);
stopServer($s);
$mb = new IHUB_ModbusTcpClient('127.0.0.1', 1, 1);
$t0 = microtime(true); $r = $mb->writeSingle(1, 1); $dt = microtime(true) - $t0;
$prefix = strstr(IHUB_Text::t('no connection (%s)', 'ZZ'), 'ZZ', true);
check('nicht erreichbar: false, "no connection (…)" mit Fehlernummer', $r === false && str_starts_with($mb->lastError, $prefix) && preg_match('/\d/', $mb->lastError) === 1, $mb->lastError);
check('Schreiben: zwei Verbindungsversuche mit 1,5 s Pause', $dt >= 1.4 && $dt < 3.5, round($dt, 2) . ' s');
$t0 = microtime(true); $mb->readHolding(1, 1); $dt = microtime(true) - $t0;
check('Lesen wiederholt das Öffnen nicht', $dt < 1.0, round($dt, 2) . ' s');
check('lastError wird beim Lesen nicht angefasst', $mb->lastError !== '');

echo "3) Host und Port zur Laufzeit\n";
$s = startServer('normal'); $mb = new IHUB_ModbusTcpClient('127.0.0.1', 1, 1);
$mb->port = $s[1];
check('geänderter Port wird beim nächsten Zugriff benutzt (Lesen und Schreiben)', $mb->readHolding(8, 1) === [8] && $mb->writeSingle(1, 1) === true);
stopServer($s);

echo "4) Dekodieren\n";
$mb = new IHUB_ModbusTcpClient('x', 1, 1);
check('u16/s16/u32/s32/readStr', $mb->u16([0xFFFF], 0) === 65535 && $mb->s16([0xFFFF], 0) === -1 && $mb->u32([1, 2], 0) === 65538 && $mb->s32([0xFFFF, 0xFFFE], 0) === -2 && $mb->readStr([0x4142, 0x4300], 0, 2) === 'ABC' && $mb->u16([], 3) === 0);
check('readFloat32 ABCD', $mb->readFloat32([0x42C8, 0], 0) === 100.0);
$mb->setFloatWordSwap(true);
check('readFloat32 CDAB nach setFloatWordSwap (Kostal)', $mb->readFloat32([0, 0x42C8], 0) === 100.0);
$mb->floatWordSwap = false;
check('floatWordSwap als öffentliches Feld', $mb->readFloat32([0x42C8, 0], 0) === 100.0);
check('Ungültig-Marke bleibt aus: 0xFFFF bleibt 65535, NaN bleibt NaN', $mb->u16([0xFFFF], 0) === 65535 && is_nan($mb->readFloat32([0x7FC0, 0], 0)));

echo "5) Gateway-Anpassung\n";
$mod = new class {
    public $calls = []; public $reply = null;
    public function ForwardToGateway(string $dataId, int $function, int $address, int $quantity, string $data) { $this->calls[] = [$dataId, $function, $address, $quantity, bin2hex($data)]; return is_callable($this->reply) ? ($this->reply)($function, $address, $quantity) : $this->reply; }
};
$mod->reply = function ($f, $a, $q) { $d = ''; for ($i = 0; $i < $q; $i++) { $d .= pack('n', $a + $i); } return ($f === 3 || $f === 4) ? "\xFF\xFF" . $d : "\x10\x02"; };
$logs = [];
$g = new IHUB_ModbusGatewayClient($mod, 502, 5, function ($t) use (&$logs) { $logs[] = $t; });
check('lesen: Antwort ohne Kopf, 0-indiziert', $g->readHolding(100, 3) === [100, 101, 102] && $g->readInput(200, 2) === [200, 201]);
check('Weiterleitung bekommt DataID, Function, Adresse, Menge und leere Nutzdaten', $mod->calls[0] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}', 3, 100, 3, ''], json_encode($mod->calls[0]));
check('Zähler: zwei Anfragen, zwei Antworten', $g->requests === 2 && $g->responses === 2);
check('Schreiben: rohe Registerbytes (nicht Base64) kommen bei ForwardToGateway an', $g->writeMultiple(300, [0xFFFF, 0x8001]) === true && $mod->calls[2] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}', 16, 300, 2, 'ffff8001'], json_encode($mod->calls[2] ?? null));
check('Hinweis zum ungetesteten Schreibweg schon beim ersten writeMultiple', count($logs) === 1 && str_contains($logs[0], 'writeMultiple/FC16'), json_encode($logs));
check('Schreiben FC6', $g->writeSingle(301, 5) === true && $mod->calls[3][1] === 6 && $mod->calls[3][4] === '0005');
check('Hinweis zum ungetesteten Schreibweg genau einmal über den Rückruf', count($logs) === 1, json_encode($logs));
$mod->reply = fn($f, $a, $q) => "\x03\x06" . pack('n3', 1, 2, 3);
check('längere Antwort wird NICHT gekürzt (wie bisher)', $g->readHolding(0, 2) === [1, 2, 3]);
$mod->reply = false; $before = $g->responses;
check('false von der Weiterleitung: null, Antwortzähler bleibt', $g->readHolding(1, 1) === null && $g->responses === $before);
check('unitId bleibt öffentlich und beschreibbar', $g->unitId === 5 && ($g->unitId = 6) === 6);
check('Gateway-Client hat jede öffentliche Client-Methode des TCP-Clients', array_diff(get_class_methods('IHUB_ModbusTcpClient'), get_class_methods('IHUB_ModbusGatewayClient')) === [], implode(',', array_diff(get_class_methods('IHUB_ModbusTcpClient'), get_class_methods('IHUB_ModbusGatewayClient'))));

echo "\n$total Prüfungen, " . ($fails === 0 ? "ALLE BESTANDEN\n" : "$fails FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
