<?php
/**
 * Prüfstand Schreibbefehle (15.11.2026, Pauls Solis: "keine Verbindung" beim Schreiben):
 *  1) Client: Wiederholung mit Pause 1,5 s, Fehlernummer und Text im lastError
 *  2) Sperre je Instanz (LockEnter/LockLeave/LockIsStale): Wiedereintritt, Zeitlimit, verwaiste Sperre
 *  3) Fehlerverfolgung (RecordWriteResult, WarnUser): Zähler, Zeitpunkte, Text
 *  4) Fehlervariablen nur bei Treibern mit Steuerpunkten
 *
 *   php .tools/test-write-lock.php    # 0 = alle Prüfungen bestanden
 */

if (!class_exists('IPSModule')) {
    class IPSModule
    {
        public $InstanceID = 0;
        public $buf = [];
        public $log = [];
        public $debug = [];
        public function __construct($id = 0) { $this->InstanceID = $id; }
        public function GetBuffer($k) { return $this->buf[$k] ?? ''; }
        public function SetBuffer($k, $v) { $this->buf[$k] = $v; }
        public function LogMessage($m, $l) { $this->log[] = [$m, $l]; }
        public function SendDebug($a, $b, $c) { $this->debug[] = "$a: $b"; }
    }
}
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3, 'KR_READY' => 10103, 'KL_WARNING' => 2] as $c => $v) {
    if (!defined($c)) { define($c, $v); }
}

// Nachgebildetes IPS: Semaphoren und Variablen
$GLOBALS['SEM'] = [];
function IPS_SemaphoreEnter($name, $ms) { if (!empty($GLOBALS['SEM'][$name])) { return false; } $GLOBALS['SEM'][$name] = true; return true; }
function IPS_SemaphoreLeave($name) { unset($GLOBALS['SEM'][$name]); return true; }
$GLOBALS['VARS'] = [];   // id => [ident, value]
function IPS_GetChildrenIDs($p) { return array_keys($GLOBALS['VARS']); }
function IPS_GetObject($id) { return ['ObjectIdent' => $GLOBALS['VARS'][$id][0], 'ObjectType' => 2]; }
function GetValueInteger($id) { return $GLOBALS['VARS'][$id][1]; }
function SetValueInteger($id, $v) { $GLOBALS['VARS'][$id][1] = $v; }
function SetValueString($id, $v) { $GLOBALS['VARS'][$id][1] = $v; }

require_once dirname(__DIR__) . '/InverterHub/module.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; }
    else { $fails++; echo "  FEHLT $label" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

// ---------------------------------------------------------------- 1) Client
echo "1) Client\n";
// Kein Server: Verbindung abgelehnt -> eine Wiederholung nach Pause, Fehlernummer im Text
$mb = new IHUB_ModbusTcpClient('127.0.0.1', 1, 1);
$t0 = microtime(true);
$ok = $mb->writeSingle(43135, 1);
$dt = microtime(true) - $t0;
check('abgelehnte Verbindung: false', $ok === false);
check('Pause von etwa 1,5 s vor dem zweiten Versuch', $dt >= 1.4 && $dt < 4.0, sprintf('%.2f s', $dt));
check('lastError nennt "no connection" mit Fehlernummer', strpos($mb->lastError, 'no connection (') === 0, $mb->lastError);
$t0 = microtime(true);
$ok = $mb->writeMultiple(43135, [1, 2]);
check('writeMultiple: false und lastError gesetzt', $ok === false && strpos($mb->lastError, 'no connection') === 0, $mb->lastError);

// Server, der erst nach 0,8 s lauscht: erster Versuch abgelehnt, zweiter nach der Pause erfolgreich
$port = random_int(20000, 60000);
$code = <<<'PHP'
$port = (int)$argv[1];
usleep(800000);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) { exit(1); }
$c = @stream_socket_accept($srv, 10);
if ($c) {
    $h = fread($c, 12);
    $x = unpack('ntid', $h);
    fwrite($c, pack('nnn', $x['tid'], 0, 6) . chr(1) . substr($h, 7, 5));
    fclose($c);
}
PHP;
$proc = proc_open([PHP_BINARY, '-r', $code, '--', (string)$port], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
$mb = new IHUB_ModbusTcpClient('127.0.0.1', $port, 1);
$ok = $mb->writeSingle(43135, 1);
check('späte Verbindung: zweiter Versuch nach der Pause kommt durch', $ok === true && $mb->lastError === '', $mb->lastError);
foreach ($pipes as $p) { @fclose($p); }
@proc_terminate($proc);

// ---------------------------------------------------------------- 2) Sperre
echo "2) Sperre je Instanz\n";
$rc = new ReflectionClass('InverterHub');
$mk = function (int $id) use ($rc) {
    $o = $rc->newInstanceWithoutConstructor();
    $o->InstanceID = $id;
    return $o;
};
$call = function ($o, string $m, ...$args) use ($rc) {
    $r = $rc->getMethod($m);
    return $r->invoke($o, ...$args);
};
$a = $mk(4711);
$b = $mk(4711);   // zweiter Lauf (anderer Thread) derselben Instanz
$other = $mk(4712);
check('Lauf A bekommt die Sperre', $call($a, 'LockEnter', 100) === true);
check('Lauf B bekommt sie nicht (Zeitlimit)', $call($b, 'LockEnter', 100) === false);
check('eine andere Instanz ist nicht betroffen', $call($other, 'LockEnter', 100) === true);
check('Wiedereintritt im selben Lauf geht (Tiefe)', $call($a, 'LockEnter', 100) === true);
$call($a, 'LockLeave');
check('nach einem Leave von zwei Eintritten bleibt sie gehalten', $call($b, 'LockEnter', 100) === false);
$call($a, 'LockLeave');
check('nach dem letzten Leave ist sie frei', $call($b, 'LockEnter', 100) === true);
$call($b, 'LockLeave');
$call($other, 'LockLeave');
check('Sperre leer, keine Reste', $GLOBALS['SEM'] === []);
check('frische Sperre gilt nicht als verwaist', $call($a, 'LockIsStale') === false);
$a->buf['IoLockSince'] = (string)(time() - InverterHub::LOCK_STALE_SEC - 5);
check('Sperre älter als ' . InverterHub::LOCK_STALE_SEC . ' s gilt als verwaist', $call($a, 'LockIsStale') === true);

// ---------------------------------------------------------------- 3) Fehlerverfolgung
echo "3) Fehlerverfolgung\n";
$GLOBALS['VARS'] = [101 => ['ctl_write_fail_count', 0], 102 => ['ctl_write_fail_time', 0], 103 => ['ctl_write_fail_text', ''], 104 => ['ctl_write_ok_time', 0]];
$h = $mk(4713);
$call($h, 'RecordWriteResult', 'ctl_force_mode', '');
check('Erfolg setzt nur die Zeit des letzten Erfolgs', $GLOBALS['VARS'][104][1] > 0 && $GLOBALS['VARS'][101][1] === 0);
$call($h, 'RecordWriteResult', 'ctl_force_mode', 'Solis: forced mode could not be written (register 43135): no connection (111 Connection refused)');
check('Fehlschlag zählt', $GLOBALS['VARS'][101][1] === 1);
check('Fehlschlag setzt die Zeit', $GLOBALS['VARS'][102][1] > 0);
check('Fehlertext enthält Ident und Grund', strpos($GLOBALS['VARS'][103][1], 'ctl_force_mode: Solis') === 0 && strpos($GLOBALS['VARS'][103][1], '111') !== false, $GLOBALS['VARS'][103][1]);
$call($h, 'RecordWriteResult', 'ctl_force_mode', str_repeat('x', 600));
check('Fehlertext wird auf 250 Zeichen begrenzt', mb_strlen($GLOBALS['VARS'][103][1]) === 250);
// WarnUser meldet nur während eines Schreibbefehls
$h2 = $mk(4714);
$h2->WarnUser('Warnung beim Lesen');
$wf = new ReflectionProperty('InverterHub', 'writeFailure');
$iw = new ReflectionProperty('InverterHub', 'inWriteCall');
check('WarnUser außerhalb eines Schreibbefehls merkt sich nichts', $wf->getValue($h2) === '');
$iw->setValue($h2, true);
$h2->WarnUser('erste'); $h2->WarnUser('zweite');
check('WarnUser im Schreibbefehl merkt sich die erste Meldung', $wf->getValue($h2) === 'erste');
check('beide Meldungen gehen weiter ins Protokoll', count($h2->log) === 3);
// ohne Fehlervariablen (Treiber ohne Steuerung): nichts passiert
$GLOBALS['VARS'] = [];
$call($mk(4715), 'RecordWriteResult', 'x', 'Fehler');
check('ohne Fehlervariablen kein Fehler und nichts angelegt', $GLOBALS['VARS'] === []);

// ---------------------------------------------------------------- 4) Variablen
echo "4) Fehlervariablen\n";
$vars = IHUB_WriteDiag::vars();
$idents = array_column($vars, 0);
check('vier Fehlervariablen mit festen Idents', $idents === ['ctl_write_fail_count', 'ctl_write_fail_time', 'ctl_write_fail_text', 'ctl_write_ok_time'], implode(',', $idents));
check('keine ist ein Steuerpunkt (Gruppe diag, nie control)', count(array_filter($vars, function ($v) { return $v[5] !== 'diag'; })) === 0);
check('keine wird archiviert', count(array_filter($vars, function ($v) { return $v[4] !== false; })) === 0);

echo $fails ? "\n$fails FEHLER\n" : "\nALLE PRUEFUNGEN BESTANDEN\n";
exit($fails ? 1 : 0);
