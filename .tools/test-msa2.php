<?php
// Prueft IHUB_HoymilesMsa2Driver (MQTT, kein Modbus) und die MQTT-Bruecke: Zuordnung der Felder aus
// quick/state, device/state und system/state, Vorzeichen, Befehle, Frische ("verbunden").
// Die Nachrichten stammen aus dem Mitschnitt von AKL (Forum, Firmware V01.07.02) und dem Quellcode Lin071/ha.
// Aufruf: php .tools/test-msa2.php

$fail = 0;
function ok($c, $m) { global $fail; if ($c) { echo "  ok   $m\n"; } else { echo "  FAIL $m\n"; $fail++; } }

$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
if (!preg_match('/(class IHUB_HoymilesMsa2Driver implements IHUB_InverterDriverInterface\s*\{.*?\n\})\n\n\/\/ -{20,}\n\/\/ InverterHub — Hauptmodul/s', $src, $m)) {
    echo "FAIL Treiberklasse nicht gefunden\n";
    exit(1);
}
define('VARIABLETYPE_FLOAT', 2);
define('VARIABLETYPE_INTEGER', 1);
interface IHUB_InverterDriverInterface {}
eval($m[1]);

class MockHub
{
    public $v = [];
    public $warn = [];
    public $published = [];
    public $age = 5;
    public $publishOk = true;
    public $dev = 'MSA-280520260806';
    public function SetVarInt($i, $x) { $this->v[$i] = (int)$x; }
    public function SetVarFloat($i, $x) { $this->v[$i] = (float)$x; }
    public function SetVarBool($i, $x) { $this->v[$i] = (bool)$x; }
    public function SetVarStr($i, $x) { $this->v[$i] = (string)$x; }
    public function WarnUser($m) { $this->warn[] = $m; }
    public function MqttAge() { return $this->age; }
    public function MqttDeviceId() { return $this->dev; }
    public function MqttPublish($t, $p) { if (!$this->publishOk) { return false; } $this->published[] = [$t, $p]; return true; }
}

$d = new IHUB_HoymilesMsa2Driver();
$T = 'homeassistant/sensor/MSA-280520260806/';

echo "Variablen und Profile\n";
$idents = [];
foreach ($d->getBaseVars() as $r) { $idents[] = $r[0]; }
foreach ($d->getOptionalGroups() as $g) { foreach ($g['vars'] as $r) { $idents[] = $r[0]; } }
ok(count($idents) === count(array_unique($idents)), 'Idents sind eindeutig (' . count($idents) . ')');
$profiles = array_keys($d->getProfiles()) + [];
$enums = array_keys($d->getEnumProfiles());
$missing = [];
foreach (array_merge($d->getBaseVars(), ...array_map(function ($g) { return $g['vars']; }, array_values($d->getOptionalGroups()))) as $r) {
    $p = $r[3];
    if ($p !== '' && $p[0] !== '~' && !in_array($p, array_keys($d->getProfiles()), true) && !in_array($p, $enums, true)) { $missing[] = $p; }
}
ok($missing === [], 'alle benutzten MSA.*-Profile sind definiert' . ($missing ? ' (fehlt: ' . implode(', ', $missing) . ')' : ''));

echo "quick/state (AKL-Mitschnitt, Standby)\n";
$h = new MockHub();
$d->onMqtt($h, $T . 'quick/state', '{"grid_on_p":0.0,"grid_off_p":0.0,"bat_sts":"standby","bat_p":0.0,"soc":10.00,"heat":false,"sys_pv_p":0.0,"sys_plug_p":0.0,"sys_bat_p":0.0,"sys_grid_p":0.0,"sys_load_p":160.0,"sys_sp_p":0.0,"sys_soc":10.00,"sys_heat":false,"sys_pv2_p":160.0,"sys_eps_p":0.0}');
ok($h->v['bat_soc'] === 10 && $h->v['bat_power'] === 0.0 && $h->v['bat_mode'] === 0, 'SOC 10 %, Batterie 0 W, Status Bereitschaft');
ok($h->v['pv_total'] === 160.0 && $h->v['pv1_power'] === 0.0 && $h->v['pv2_power'] === 160.0, 'PV gesamt 160 W = PV1 (0) + PV2 (160, angehängter Wechselrichter)');
ok($h->v['load_power'] === 160.0 && $h->v['meter_total'] === 0.0 && $h->v['bat_soc_sys'] === 10, 'Last 160 W, Netz 0 W, System-SOC 10 %');

echo "Vorzeichen (Mitschnitt AKL: Entladen 519 W, Netzbezug 1420 W, Last 1939 W)\n";
$h = new MockHub();
$d->onMqtt($h, $T . 'quick/state', '{"bat_sts":"discharge","bat_p":519.0,"sys_bat_p":519.0,"sys_grid_p":1420.0,"sys_load_p":1939.0,"soc":55,"sys_pv_p":0.0,"sys_pv2_p":0.0}');
ok($h->v['bat_power'] === 519.0 && $h->v['bat_mode'] === 2, 'Entladen: + (InverterHub-Konvention), Status Entladen');
ok($h->v['meter_total'] === -1420.0, 'Netzbezug 1420 W (sys_grid_p > 0) wird zu meter_total = -1420 (+ = Einspeisung)');
$h = new MockHub();
$d->onMqtt($h, $T . 'quick/state', '{"bat_sts":"charge","bat_p":-390.0,"sys_bat_p":-390.0,"sys_grid_p":6.0,"sys_load_p":-384.0}');
ok($h->v['bat_power'] === -390.0 && $h->v['bat_mode'] === 1 && $h->v['meter_total'] === -6.0, 'Laden: - ; Netz +6 W Bezug -> -6');
$h = new MockHub();
$d->onMqtt($h, $T . 'quick/state', '{"bat_sts":"standby","bat_p":-100.0,"pv_p":800.0,"soc":50}');
ok($h->v['bat_power'] === -100.0 && $h->v['pv_total'] === 800.0 && !isset($h->v['meter_total']), 'ohne sys_*: bat_p und pv_p als Rückfall, kein Netzwert erfunden');

echo "device/state und system/state\n";
$h = new MockHub();
$d->onMqtt($h, $T . 'device/state', json_encode([
    'bat_v' => 51.2, 'bat_i' => -7.5, 'bat_temp' => 21.5, 'rssi' => -61, 'pack_num' => 2,
    'packs' => [['id' => 1, 'soc' => 48, 'temp' => 20.5], ['id' => 2, 'soc' => 52, 'temp' => 22.0]],
    'grid' => [['type' => 'grid_on', 'v' => 231.4, 'f' => 50.01, 'p' => 10], ['type' => 'grid_off', 'v' => 0], ['type' => 'inv', 'p' => 160]],
]));
ok($h->v['bat_volt'] === 51.2 && $h->v['bat_curr'] === -7.5 && $h->v['bat_temp'] === 21.5 && $h->v['dev_rssi'] === -61.0, 'Batterie-Spannung/-Strom/-Temperatur, WLAN');
ok($h->v['pack1_soc'] === 48 && $h->v['pack2_soc'] === 52 && $h->v['pack2_temp'] === 22.0 && !isset($h->v['pack3_soc']), 'Packs nach id zugeordnet');
ok($h->v['grid_volt'] === 231.4 && $h->v['grid_freq'] === 50.01 && $h->v['inv_power'] === 160.0, 'Netzspannung/-frequenz aus grid[type=grid_on], Wechselrichterleistung aus grid[type=inv]');
$h = new MockHub();
$d->onMqtt($h, $T . 'system/state', '{"pv_e":1250,"pv2_e":3400,"chg_e":2100,"dchg_e":1800,"ems_mode":"mqtt_ctrl"}');
ok(abs($h->v['e_pv_day'] - 1.25) < 1e-9 && abs($h->v['e_pv2_day'] - 3.4) < 1e-9 && abs($h->v['e_charge_day'] - 2.1) < 1e-9 && abs($h->v['e_discharge_day'] - 1.8) < 1e-9, 'Tagesenergie Wh -> kWh');
ok($h->v['dev_ems_mode'] === 'mqtt_ctrl' && $h->v['ctl_ems_mode'] === 1, 'EMS-Modus aus system/state: Text und Auswahl');

echo "Robustheit\n";
$h = new MockHub();
$d->onMqtt($h, $T . 'quick/state', 'kein json');
$d->onMqtt($h, $T . 'quick/state', '{"soc":"abc","bat_p":null,"sys_grid_p":"x"}');
$d->onMqtt($h, 'homeassistant/switch/MSA-280520260806/config', '{"soc":99}');
ok($h->v === [], 'Müll, nicht-numerische Werte und fremde Topics werden ignoriert');

echo "Verbindung (Frische)\n";
$h = new MockHub(); $h->age = 3;
ok($d->readFast(null, $h) === true && $h->v['connected'] === true, 'Nachricht vor 3 s: verbunden');
$h = new MockHub(); $h->age = 300;
ok($d->readFast(null, $h) === false && $h->v['connected'] === false, 'letzte Nachricht vor 300 s: nicht verbunden');
$h = new MockHub(); $h->age = -1;
ok($d->readFast(null, $h) === false, 'noch nie eine Nachricht: nicht verbunden');

echo "Steuerung\n";
$h = new MockHub();
$d->writeControl(null, $h, 'ctl_ems_mode', 1);
ok($h->published === [['homeassistant/select/MSA-280520260806/ems_mode/command', 'mqtt_ctrl']] && $h->v['ctl_ems_mode'] === 1, 'EMS-Modus 1 -> mqtt_ctrl auf .../select/<id>/ems_mode/command');
$h = new MockHub();
$d->writeControl(null, $h, 'ctl_ems_mode', 7);
ok($h->published === [] && count($h->warn) === 1, 'ungültiger Modus: nichts gesendet, Warnung');
$h = new MockHub();
$d->writeControl(null, $h, 'ctl_power_ctrl', -500);
ok($h->published === [['homeassistant/number/MSA-280520260806/power_ctrl/set', '-500']] && $h->v['ctl_power_ctrl'] === -500, 'Leistungsvorgabe wird unverändert (ganzzahlig) durchgereicht');
$h = new MockHub(); $h->publishOk = false;
$d->writeControl(null, $h, 'ctl_ems_mode', 0);
ok(!isset($h->v['ctl_ems_mode']) && count($h->warn) === 1, 'Senden scheitert: Anzeige unverändert, Warnung');
$h = new MockHub(); $h->dev = '';
$d->writeControl(null, $h, 'ctl_ems_mode', 0);
ok($h->published === [] && count($h->warn) === 1, 'ohne Geräte-ID wird nichts gesendet');

echo "Bruecke und Hauptmodul (Quelltext)\n";
$bridge = file_get_contents(dirname(__DIR__) . '/InverterHubMqttBridge/module.php');
ok(strpos($bridge, "'DataID'           => self::TX_DATA_ID") !== false && strpos($bridge, "'PacketType'       => 3") !== false && strpos($bridge, "utf8_encode(\$payload)") !== false, 'Brücke sendet PUBLISH (Paket 3) mit utf8_encode');
ok(strpos($bridge, "utf8_decode(\$payload)") !== false && strpos($bridge, '1670886000') !== false, 'Brücke dekodiert die Payload wie das Referenzmodul');
$mj = json_decode(file_get_contents(dirname(__DIR__) . '/InverterHubMqttBridge/module.json'), true);
$hubmj = json_decode(file_get_contents(dirname(__DIR__) . '/InverterHub/module.json'), true);
ok($mj['prefix'] === 'IHUBM' && $mj['parentRequirements'] === ['{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'] && $mj['implemented'] === ['{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}'], 'module.json der Brücke: Eltern- und Empfangsschnittstelle des MQTT-Servers');
ok($hubmj['parentRequirements'] === [] && $hubmj['implemented'] === [], 'Hauptmodul bleibt ohne Pflicht-Elterninstanz (bestehende Instanzen unberührt)');
ok(preg_match('/MQTT_BRIDGE_GUID = \'(\{[0-9A-F-]+\})\'/', $src, $g) && strtoupper($g[1]) === strtoupper($mj['id']), 'GUID der Brücke im Hauptmodul stimmt mit module.json überein');
ok(strpos($src, "'hoymiles_msa2' => 'IHUB_HoymilesMsa2Driver'") !== false && strpos($src, "'label' => 'MQTT (Symcon-MQTT-Server") !== false, 'Hersteller und Verbindungsweg sind im Formular eingetragen');

echo "Erweiterungspacks und Notstrom/Steckdose (Forum AKL, 26.10.2026)\n";
$groups = $d->getOptionalGroups();
$idents = function ($g) { return array_map(function ($v) { return $v[0]; }, $g['vars']); };
ok(isset($groups['GroupPacks']) && $idents($groups['GroupPacks']) === ['pack1_soc', 'pack1_temp', 'pack2_soc', 'pack2_temp', 'pack3_soc', 'pack3_temp', 'pack4_soc', 'pack4_temp'], 'Pack-Variablen liegen in der eigenen Gruppe GroupPacks');
ok(count(array_filter($idents($groups['GroupBat']), function ($i) { return strpos($i, 'pack') === 0; })) === 0, 'Batterie-Gruppe enthaelt keine Pack-Variablen mehr');
ok($d->getExtraBooleanProperties() === ['GroupPacks' => false], 'GroupPacks ist standardmaessig aus (kein Dauer-"Nie" ohne Erweiterung)');
ok(preg_match('/foreach \(\$drv->getExtraBooleanProperties\(\).*?foreach \(\$drv->getOptionalGroups\(\)/s', $src) === 1, 'Hauptmodul registriert die Extra-Vorgabe vor der Gruppen-Vorgabe (Vorgabe false bleibt erhalten)');
$h = new MockHub();
$d->onMqtt($h, $T . 'system/state', '{"pv_e":0,"pv2_e":2030,"dchg_e":244,"chg_e":1962,"plug_out_e":245,"plug_in_e":1982,"eps_out_e":7,"eps_in_e":0,"ems_mode":"general"}');
ok(abs($h->v['e_plug_in_day'] - 1.982) < 1e-9 && abs($h->v['e_plug_out_day'] - 0.245) < 1e-9 && abs($h->v['e_eps_out_day'] - 0.007) < 1e-9 && $h->v['e_eps_in_day'] === 0.0, 'Steckdose/Notstrom Tagesenergie Wh -> kWh (Werte aus dem Mitschnitt vom 26.10.2026)');
echo $fail === 0 ? "ALLES OK\n" : "$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
