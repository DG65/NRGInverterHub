<?php
/**
 * Prüfstand Kostal HTTP API (nur Lesen): Abbildung der processdata-Werte auf die Register des Kostal-Treibers,
 * Einheiten, fehlende Werte, PV3/Batterietemperatur, Sitzungswiederverwendung, Anmeldung (SCRAM) gegen eine
 * unabhängige Python-Fassung. Kein Netzwerkzugriff: der Client wird abgeleitet und request() ersetzt.
 *   php .tools/test-kostal-http.php
 */
foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3] as $k => $v) { define($k, $v); }
require_once dirname(__DIR__) . '/InverterHub/KostalHttpClient.php';
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
$a = strpos($src, 'interface IHUB_InverterDriverInterface');
$b = strpos($src, "\nclass InverterHub extends IPSModule");
eval(substr($src, $a, $b - $a));

$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }

class HubMock {
    public $v = []; public $groups = [];
    function __construct(bool $all = true) { foreach (['GroupPV', 'GroupGrid', 'GroupBat', 'GroupMeter', 'GroupHome', 'GroupEnergy'] as $g) { $this->groups[$g] = $all; } }
    // wie der Hub: Float32-Extremwert = "nicht belegt" wird verworfen
    function SetVarFloat($i, $x) { if (abs($x) >= 3.0e38) { return; } $this->v[$i] = $x; } function SetVarInt($i, $x) { $this->v[$i] = $x; }
    function SetVarBool($i, $x) { $this->v[$i] = $x; } function SetVarStr($i, $x) { $this->v[$i] = $x; }
    function GetPropBool($n) { return $this->groups[$n] ?? false; }
    function GetKostalWordSwap() { return true; }   // Modbus-Einstellung CDAB: im HTTP-Modus ohne Wirkung
}

/** Client ohne Netzwerk: request() beantwortet aus $handler. */
class StubKostalClient extends IHUB_KostalHttpClient
{
    public $log = [];
    public $handler;
    protected function request(string $method, string $path, $payload = null, string $session = ''): array
    {
        $this->log[] = [$method, $path, $session];
        $this->requests++;
        $this->responses++;
        return ($this->handler)($method, $path, $payload, $session);
    }
}

function datum($id, $value, $unit = '') { return ['id' => $id, 'unit' => $unit, 'value' => $value]; }
function fixture(array $skipModules = []): array
{
    $m = [
        'devices:local' => [datum('Dc_P', 2500.0, 'W'), datum('HomeBat_P', 300.0, 'W'), datum('HomeGrid_P', 100.0, 'W'), datum('HomePv_P', 1800.0, 'W'), datum('Iso_R', 5000000.0, 'Ohm')],
        'devices:local:ac' => [datum('Frequency', 50.01, 'Hz'), datum('L1_I', 3.5), datum('L1_P', 800.0), datum('L1_U', 231.0), datum('L2_I', 3.4), datum('L2_P', 790.0), datum('L2_U', 232.0), datum('L3_I', 3.3), datum('L3_P', 780.0), datum('L3_U', 233.0), datum('P', 2.37, 'kW')],
        'devices:local:battery' => [datum('I', -3.2), datum('SoC', 76), datum('U', 420.5), datum('P', -1300.0)],
        'devices:local:powermeter' => [datum('L1_P', 50.0), datum('L2_P', 60.0), datum('L3_P', 40.0), datum('P', 150.0)],
        'devices:local:pv1' => [datum('I', 4.0), datum('P', 1500.0), datum('U', 375.0)],
        'devices:local:pv2' => [datum('I', 2.8), datum('P', 1000.0), datum('U', 357.0)],
        'devices:local:pv3' => [datum('I', 1.0), datum('P', 10.0), datum('U', 10.0)],
        // Zähler ohne Einheit: die Statistik-Schlüssel gelten als Wh
        'scb:statistic:EnergyFlow' => [datum('Statistic:Yield:Total', 12345678.0), datum('Statistic:Yield:Day', 5400.0), datum('Statistic:Yield:Year', 4000000.0), datum('Statistic:Yield:Month', 300000.0), datum('Statistic:EnergyHome:Total', 9000000.0)],
    ];
    foreach ($skipModules as $s) { unset($m[$s]); }
    return $m;
}
function handler(array $modules, array $info = ['hostname' => 'scb'])
{
    return function ($method, $path, $payload, $session) use ($modules, $info) {
        if ($path === 'processdata') {
            $idx = [];
            foreach (array_keys($modules) as $id) { $idx[] = ['moduleid' => $id, 'processdataids' => []]; }
            return [200, $idx];
        }
        if (strpos($path, 'processdata/') === 0) {
            $id = substr($path, strlen('processdata/'));
            return isset($modules[$id]) ? [200, [['moduleid' => $id, 'processdata' => $modules[$id]]]] : [404, null];
        }
        if ($path === 'info/version') { return [200, $info]; }
        return [404, null];
    };
}
function client(array $modules, bool $pv3Battery = false): StubKostalClient
{
    $c = new StubKostalClient('wr.local', 'user', 'geheim', $pv3Battery, function () { return 'SESSION'; }, function ($s) {});
    $c->handler = handler($modules);
    return $c;
}

echo "1) Abbildung auf die Register des Kostal-Treibers\n";
$drv = new IHUB_KostalDriver(); $h = new HubMock(); $c = client(fixture());
$res = $drv->readFast($c, $h);
ok($res === true && $h->v['connected'] === true, 'readFast liefert true, verbunden');
ok($h->v['pv_total'] === 2500.0 && abs($h->v['ac_power'] - 2370.0) < 0.01, 'PV gesamt 2500 W, AC 2,37 kW -> 2370 W');
ok($h->v['riso'] === 5000.0, 'Isolationswiderstand 5 MOhm -> 5000 kOhm');
ok($h->v['pv1_power'] === 1500.0 && $h->v['pv2_volt'] === 357.0 && $h->v['pv3_curr'] === 1.0, 'PV-Eingänge 1 bis 3');
ok(abs($h->v['grid_freq'] - 50.01) < 0.001 && $h->v['grid_l2_volt'] === 232.0 && $h->v['grid_l3_pwr'] === 780.0, 'Netzgruppe (Block 152-171)');
ok($h->v['bat_soc'] === 76 && $h->v['bat_volt'] === 420.5 && abs($h->v['bat_curr'] + 3.2) < 0.001, 'Batterie SOC, Spannung, Strom (Float32-Genauigkeit)');
ok($h->v['bat_power'] === -1300.0 && $h->v['bat_status'] === 1, 'Batterieleistung (SInt16) -1300 W, Zustand 1 (Laden)');
ok($h->v['meter_total'] === 150.0 && $h->v['meter_l1_pwr'] === 50.0, 'Zähler');
ok($h->v['home_from_bat'] === 300.0 && $h->v['home_from_grid'] === 100.0 && $h->v['home_from_pv'] === 1800.0 && $h->v['home_total'] === 9000.0, 'Hausverbrauch (Block 106-119), Energie Wh -> kWh');
ok(!array_key_exists('bat_temp', $h->v), 'keine Batterietemperatur im HTTP-Modus');
$drv->readSlow($c, $h);
ok($h->v['e_total'] === 12345.678 && $h->v['e_day'] === 5.4, 'Energiezähler ohne Einheit gelten als Wh (-> kWh)');
$dev = new HubMock(); $drv->readDeviceInfo($c, $dev);
ok(($dev->v['dev_name'] ?? '') === 'scb', 'Gerätename aus info/version');

echo "2) Fehlende Werte werden nie zu Null\n";
$h = new HubMock(); $c = client(fixture(['devices:local:battery']));
$drv->readFast($c, $h);
ok(!array_key_exists('bat_soc', $h->v) && !array_key_exists('bat_power', $h->v) && !array_key_exists('bat_volt', $h->v), 'Anlage ohne Batterie: keine Batteriewerte, nichts auf 0 gesetzt');
ok($h->v['pv_total'] === 2500.0 && $h->v['meter_total'] === 150.0, 'Rest der Anlage unberührt');
$miss = $c->getMissingFor($drv, $h);
ok(isset($miss['bat_soc']) && isset($miss['bat_power']) && !isset($miss['bat_temp']) && !isset($miss['pv_total']), 'getMissingFor nennt die Batteriepunkte, nicht die Temperatur');
$h = new HubMock(); $c = client(fixture(['devices:local']));
ok($drv->readFast($c, $h) === false && $h->v['connected'] === false, 'ohne devices:local (Kerndaten) meldet der Treiber keine Verbindung');
$c = client(fixture()); $c->handler = function ($m, $p, $pl, $s) { return $p === 'processdata' ? [200, [['moduleid' => 'devices:local']]] : [200, [['moduleid' => 'devices:local', 'processdata' => [datum('Dc_P', 'abc', 'W')]]]]; };
$h = new HubMock(); $drv->readFast($c, $h);
ok(!array_key_exists('pv_total', $h->v), 'kein Zahlenwert: nicht schreiben');

// Teilweise fehlende Blöcke: ein fehlender Punkt zieht die übrigen desselben Blocks nicht mit
$m = fixture(); $m['devices:local'] = array_values(array_filter($m['devices:local'], fn($d) => $d['id'] !== 'HomeBat_P'));
$h = new HubMock(); $c = client($m); $drv->readFast($c, $h);
ok(!array_key_exists('home_from_bat', $h->v) && $h->v['home_from_grid'] === 100.0 && $h->v['home_from_pv'] === 1800.0 && $h->v['home_total'] === 9000.0, 'fehlt nur HomeBat_P: Netz-/PV-Anteil und Energie des Blocks bleiben erhalten, der fehlende Wert wird nicht 0');
$m = fixture(); $m['scb:statistic:EnergyFlow'] = array_values(array_filter($m['scb:statistic:EnergyFlow'], fn($d) => $d['id'] !== 'Statistic:Yield:Month'));
$h = new HubMock(); $c = client($m); $drv->readSlow($c, $h);
ok(!array_key_exists('e_month', $h->v) && $h->v['e_total'] === 12345.678 && $h->v['e_year'] === 4000.0, 'fehlt nur der Monatszähler: Gesamt-, Tages- und Jahreszähler werden trotzdem gelesen, Monat nicht auf 0 gesetzt');

echo "3) PV3 als Batterieanschluss und Einheiten\n";
$c = client(fixture(), true); $h = new HubMock(); $drv->readFast($c, $h);
$pvLoads = array_filter($c->log, fn($e) => strpos($e[1], 'pv3') !== false);
ok(!array_key_exists('pv3_curr', $h->v) && !array_key_exists('pv3_power', $h->v) && !array_key_exists('pv3_volt', $h->v), 'PV3 als Batterie: keine PV3-Werte');
ok($pvLoads === [], 'PV3 wird gar nicht erst angefragt');
ok($h->v['pv1_power'] === 1500.0 && $h->v['bat_soc'] === 76, 'PV1, PV2 und Batterie bleiben aktiv');
$c = client(fixture()); $c->handler = function ($m, $p, $pl, $s) { if ($p === 'processdata') { return [200, [['moduleid' => 'scb:statistic:EnergyFlow']]]; } return [200, [['moduleid' => 'scb:statistic:EnergyFlow', 'processdata' => [datum('Statistic:Yield:Total', 12.0, 'kWh'), datum('Statistic:Yield:Day', 5.0, 'MWh'), datum('Statistic:Yield:Year', 7.0, 'W'), datum('Statistic:Yield:Month', 3.0, 'foo')]]]]; };
$h = new HubMock(); $drv->readSlow($c, $h);
ok($h->v['e_total'] === 12.0 && $h->v['e_day'] === 5000.0, 'kWh und MWh werden in Wh umgerechnet');
ok(!array_key_exists('e_year', $h->v) && !array_key_exists('e_month', $h->v), 'falsche Einheit bei einem Zähler: nicht schreiben');

echo "4) Nur Lesen\n";
$c = client(fixture()); $threw = 0;
foreach ([fn() => $c->writeSingle(1, 2), fn() => $c->writeMultiple(1, [2])] as $f) { try { $f(); } catch (LogicException $e) { $threw++; } }
ok($threw === 2, 'Schreibzugriffe lösen eine Ausnahme aus');
$c = client(fixture()); $drv->readFast($c, new HubMock());
ok(count(array_filter($c->log, fn($e) => $e[0] !== 'GET')) === 0, 'mit gültiger Sitzung nur GET-Anfragen');
$src2 = file_get_contents(dirname(__DIR__) . '/InverterHub/KostalHttpClient.php');
ok(substr_count($src2, "'PUT'") === 0 && substr_count($src2, "'DELETE'") === 0 && substr_count($src2, "authorized('POST'") === 0, 'kein PUT/DELETE/POST über die Datenzugriffe (POST nur bei der Anmeldung)');

echo "5) Sitzung\n";
$saved = [];
$c = new StubKostalClient('wr.local', 'user', 'geheim', false, function () { return 'ALT'; }, function ($s) use (&$saved) { $saved[] = $s; });
$c->handler = function ($m, $p, $pl, $s) { return $p === 'auth/start' ? [500, null] : [401, null]; };
$threw = '';
try { $c->beginBatch(); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
ok($threw === 'Kostal auth/start failed' && $saved === [''], 'abgelaufene Sitzung (401): wird verworfen und neu angemeldet');
$c = new StubKostalClient('wr.local', 'user', 'geheim', false, function () { return 'GUT'; }, function ($s) {});
$c->handler = handler(fixture()); $c->beginBatch();
ok(count(array_filter($c->log, fn($e) => $e[1] === 'auth/start')) === 0 && $c->log[0][2] === 'GUT', 'gültige Sitzung wird wiederverwendet, keine neue Anmeldung');
foreach (['', 'a b', 'x/../y', "wr\r\nHost: x"] as $bad) {
    $t = false; try { new IHUB_KostalHttpClient($bad, 'user', 'pw'); } catch (InvalidArgumentException $e) { $t = true; }
    ok($t, 'ungültiger Host abgelehnt: ' . json_encode($bad));
}
$t = false; try { new IHUB_KostalHttpClient('wr', 'user', ''); } catch (InvalidArgumentException $e) { $t = true; }
ok($t, 'leeres Passwort abgelehnt');

echo "6) Anmeldung (SCRAM) gegen eine unabhängige Python-Fassung\n";
$py = trim((string)@shell_exec('command -v python3'));
if ($py === '') {
    echo "  --   python3 nicht gefunden, Prüfung übersprungen\n";
} else {
    $salt = base64_encode('0123456789abcdef'); $rounds = 4096; $snonce = 'SERVERNONCE12345'; $pw = 'Pässwort-ünd-Zeichen';
    $seen = [];
    $c = new StubKostalClient('wr.local', 'user', $pw, false, null, null);
    $c->handler = function ($m, $p, $pl, $s) use (&$seen, $salt, $rounds, $snonce) {
        if ($p === 'auth/start') { $seen['cnonce'] = $pl['nonce']; return [200, ['nonce' => $pl['nonce'] . $snonce, 'salt' => $salt, 'rounds' => $rounds, 'transactionId' => 'T1']]; }
        if ($p === 'auth/finish') { $seen['proof'] = $pl['proof']; return [500, null]; }
        return [500, null];
    };
    try { $c->beginBatch(); } catch (Throwable $e) { }
    $code = 'import base64,hashlib,hmac,sys\n'
        . 'pw,salt,rounds,cn,sn=sys.argv[1],sys.argv[2],int(sys.argv[3]),sys.argv[4],sys.argv[5]\n'
        . 'user="user"; fullnonce=cn+sn\n'
        . 'salted=hashlib.pbkdf2_hmac("sha256",pw.encode(),base64.b64decode(salt),rounds,32)\n'
        . 'ck=hmac.new(salted,b"Client Key",hashlib.sha256).digest(); stored=hashlib.sha256(ck).digest()\n'
        . 'msg=("n=%s,r=%s,r=%s,s=%s,i=%d,c=biws,r=%s"%(user,cn,fullnonce,salt,rounds,fullnonce)).encode()\n'
        . 'sig=hmac.new(stored,msg,hashlib.sha256).digest(); proof=bytes(a^b for a,b in zip(ck,sig))\n'
        . 'print(base64.b64encode(proof).decode())\n';
    // Hinweis: in der Nachricht steht die Server-Nonce (= Client-Nonce + Serverteil) zweimal, wie in KostalHttpClient::login()
    $code = str_replace('r=%s,r=%s,s=', 'r=%s,r=%s,s=', $code);
    $expect = trim((string)shell_exec(escapeshellarg($py) . ' -c ' . escapeshellarg(str_replace('\n', "\n", $code)) . ' ' . escapeshellarg($pw) . ' ' . escapeshellarg($salt) . ' ' . $rounds . ' ' . escapeshellarg($seen['cnonce'] ?? 'x') . ' ' . escapeshellarg($snonce)));
    ok(isset($seen['proof']) && $expect !== '' && $seen['proof'] === $expect, 'Client-Beweis stimmt mit der Python-Berechnung überein');
}

echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
