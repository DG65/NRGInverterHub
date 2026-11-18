<?php

// ---------------------------------------------------------------------------
// IHUB_HoymilesMsa2Driver — Hoymiles MS-A2 (Speicher mit MQTT-Schnittstelle).
//
// ANDERS als alle anderen Treiber: kein Modbus, sondern MQTT. Das Gerät sendet an den
// MQTT-Server von Symcon; die Nachrichten kommen über die "NRG-Stack InverterHub Brücke
// (MQTT)" in IHUB_MqttReceive() -> onMqtt(). readFast() prüft nur, ob noch Nachrichten
// eintreffen. Geräte-ID, z. B. "MSA-280520260806" (= client_prefix-SN).
//
// Quellen (nicht am Gerät erprobt, VORABVERSION): Quellcode Lin071/ha (Hoymiles-Integration
// für Home Assistant, Felder und Vorzeichen), Mitschnitt von AKL (Forum, Firmware V01.07.02).
//   quick/state  (1 s):   soc, bat_p, bat_sts, heat, pv_p, grid_on_p, grid_off_p,
//                         sys_pv_p, sys_pv2_p, sys_bat_p, sys_grid_p, sys_load_p, sys_plug_p,
//                         sys_sp_p, sys_eps_p, sys_soc, sys_heat
//   device/state (5 min): bat_v, bat_i, bat_temp, rssi, pack_num, packs[], pvs[], grid[]
//   system/state (5 min): pv_e, pv2_e, chg_e, dchg_e, plug_*_e, eps_*_e, ems_mode
// Vorzeichen: Batterie (sys_bat_p/bat_p) < 0 = Laden, > 0 = Entladen (= InverterHub-Konvention);
// sys_grid_p > 0 = Netzbezug -> meter_total = -sys_grid_p (InverterHub: + = Einspeisung).
// sys_*-Felder gibt es nur bei Master/Einzelgerät. Der am MS-A2 angehängte Wechselrichter
// (z. B. HMS-2000-4WB) liefert seine Erzeugung über sys_pv2_p.
//
// Steuerung (nur Befehle, kein Rücklesen): EMS-Modus über .../select/<id>/ems_mode/command
// (general | mqtt_ctrl | tou_plan, belegt) und Leistungsvorgabe .../number/<id>/power_ctrl/set
// (Einheit und Vorzeichen NICHT belegt, deshalb nur durchgereicht).
// ---------------------------------------------------------------------------

class IHUB_HoymilesMsa2Driver implements IHUB_InverterDriverInterface
{
    const EMS_MODES = [0 => 'general', 1 => 'mqtt_ctrl', 2 => 'tou_plan'];
    const BAT_STATUS = ['standby' => 0, 'charge' => 1, 'discharge' => 2, 'lock' => 3];
    const STALE_SECONDS = 120;   // quick/state kommt im Sekundentakt

    public function getBaseVars()
    {
        return [
            ['connected',   'Connection',                       'B', '~Alert.Reversed', false, 'errors',    'MQTT'],
            ['pv_total',    'PV total power',                'F', 'MSA.Watt', true,  'pv',        'quick/state sys_pv_p + sys_pv2_p'],
            ['bat_power',   'Bat. power (+ discharging / − charging)', 'F', 'MSA.Watt', true,  'batcommon', 'quick/state sys_bat_p (fallback bat_p)'],
            ['bat_soc',     'SOC (own packs)',               'I', '~Intensity.100', true, 'batcommon', 'quick/state soc'],
            ['meter_total', 'Grid power (+ feed-in / − import)', 'F', 'MSA.Watt', true, 'grid',    'quick/state -sys_grid_p (only with meter)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV (individual values)', 'vars' => [
                ['pv1_power', 'PV1 power', 'F', 'MSA.Watt', true, 'pv', 'quick/state sys_pv_p'],
                ['pv2_power', 'PV2 power (attached inverter)', 'F', 'MSA.Watt', true, 'pv', 'quick/state sys_pv2_p'],
                ['pv_device', 'PV power (this device only)', 'F', 'MSA.Watt', false, 'pv', 'quick/state pv_p'],
            ]],
            'GroupBat' => ['caption' => 'Battery', 'vars' => [
                ['bat_mode',    'Battery status',        'I', 'MSA.BatStatus', false, 'bat', 'quick/state bat_sts'],
                ['bat_soc_sys', 'SOC (whole system, capacity-weighted)', 'I', '~Intensity.100', false, 'bat', 'quick/state sys_soc'],
                ['bat_heat',    'Battery heating',       'B', '~Switch', false, 'bat', 'quick/state heat'],
                ['bat_volt',    'Battery voltage',      'F', 'MSA.Volt', false, 'bat', 'device/state bat_v'],
                ['bat_curr',    'Battery current',         'F', 'MSA.Ampere', false, 'bat', 'device/state bat_i'],
                ['bat_temp',    'Battery temperature',    'F', 'MSA.Celsius', false, 'bat', 'device/state bat_temp'],
            ]],
            // Nur bei angebauten Erweiterungspacks. Vorgabe AUS (getExtraBooleanProperties), damit Geraete ohne
            // Erweiterung keine dauerhaft leeren Variablen bekommen (Forum AKL, 26.10.2026).
            'GroupPacks' => ['caption' => 'Expansion packs (only if installed)', 'vars' => [
                ['pack1_soc',   'Pack 1 SOC',            'I', '~Intensity.100', false, 'bat', 'device/state packs[1].soc'],
                ['pack1_temp',  'Pack 1 temperature',     'F', 'MSA.Celsius', false, 'bat', 'device/state packs[1].temp'],
                ['pack2_soc',   'Pack 2 SOC',            'I', '~Intensity.100', false, 'bat', 'device/state packs[2].soc'],
                ['pack2_temp',  'Pack 2 temperature',     'F', 'MSA.Celsius', false, 'bat', 'device/state packs[2].temp'],
                ['pack3_soc',   'Pack 3 SOC',            'I', '~Intensity.100', false, 'bat', 'device/state packs[3].soc'],
                ['pack3_temp',  'Pack 3 temperature',     'F', 'MSA.Celsius', false, 'bat', 'device/state packs[3].temp'],
                ['pack4_soc',   'Pack 4 SOC',            'I', '~Intensity.100', false, 'bat', 'device/state packs[4].soc'],
                ['pack4_temp',  'Pack 4 temperature',     'F', 'MSA.Celsius', false, 'bat', 'device/state packs[4].temp'],
            ]],
            'GroupSystem' => ['caption' => 'System (load, grid port, socket)', 'vars' => [
                ['load_power',  'Load (system value)',     'F', 'MSA.Watt', false, 'meter', 'quick/state sys_load_p (negative while charging, includes the charging power)'],
                ['plug_power',  'Socket power',    'F', 'MSA.Watt', false, 'meter', 'quick/state sys_plug_p'],
                ['sp_power',    'Smart plug power',   'F', 'MSA.Watt', false, 'meter', 'quick/state sys_sp_p'],
                ['eps_power',   'EPS power',          'F', 'MSA.Watt', false, 'meter', 'quick/state sys_eps_p'],
                ['grid_on_power',  'Grid port device (negative = import)', 'F', 'MSA.Watt', false, 'meter', 'quick/state grid_on_p (Vorzeichen umgekehrt zu sys_grid_p)'],
                ['grid_off_power', 'Island port device',    'F', 'MSA.Watt', false, 'meter', 'quick/state grid_off_p'],
            ]],
            'GroupGrid' => ['caption' => 'Grid and inverter (every 5 minutes)', 'vars' => [
                ['grid_volt', 'Grid voltage',   'F', 'MSA.Volt', false, 'grid', 'device/state grid[grid_on].v'],
                ['grid_freq', 'Grid frequency',   'F', 'MSA.Hertz', false, 'grid', 'device/state grid[grid_on].f'],
                ['inv_power', 'Inverter power (5 min)', 'F', 'MSA.Watt', false, 'grid', 'device/state grid[inv].p'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy today (every 5 minutes)', 'vars' => [
                ['e_pv_day',        'PV1 yield today',      'F', '~Electricity', true, 'energy', 'system/state pv_e (Wh)'],
                ['e_pv2_day',       'PV2 yield today',      'F', '~Electricity', true, 'energy', 'system/state pv2_e (Wh)'],
                ['e_charge_day',    'Battery charge today',  'F', '~Electricity', true, 'energy', 'system/state chg_e (Wh)'],
                ['e_discharge_day', 'Battery discharge today', 'F', '~Electricity', true, 'energy', 'system/state dchg_e (Wh)'],
                ['e_plug_in_day',   'Socket import today',        'F', '~Electricity', true, 'energy', 'system/state plug_in_e (Wh), energy from the socket into the device'],
                ['e_plug_out_day',  'Socket output today',       'F', '~Electricity', true, 'energy', 'system/state plug_out_e (Wh), energy from the device into the socket'],
                ['e_eps_in_day',    'Emergency power input today',       'F', '~Electricity', true, 'energy', 'system/state eps_in_e (Wh)'],
                ['e_eps_out_day',   'Emergency power output today',       'F', '~Electricity', true, 'energy', 'system/state eps_out_e (Wh)'],
            ]],
            'GroupDevice' => ['caption' => 'Device', 'vars' => [
                ['dev_rssi',      'WiFi signal (dBm)',   'F', 'MSA.Dbm', false, 'device', 'device/state rssi'],
                ['dev_ems_mode',  'EMS mode (device reports)', 'S', '', false, 'device', 'system/state ems_mode'],
            ]],
            'GroupControlMsa' => ['caption' => 'Control (preview, not tested on the device)', 'vars' => [
                ['ctl_ems_mode',   'EMS mode',          'I', 'MSA.EmsMode', false, 'control', 'Befehl select/<id>/ems_mode/command'],
                ['ctl_power_ctrl', 'Power setpoint MQTT control (W, sign unconfirmed)', 'I', 'MSA.WattInt', false, 'control', 'Command number/<id>/power_ctrl/set, only in EMS mode mqtt_ctrl'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return ['GroupPacks' => false];
    }

    public function getProfiles()
    {
        return [
            'MSA.Watt'    => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0, 0],
            'MSA.WattInt' => [VARIABLETYPE_INTEGER, ' W', -10000, 10000, 10, 0],
            'MSA.Volt'    => [VARIABLETYPE_FLOAT, ' V',  0.0, 1000.0, 0.1, 1],
            'MSA.Ampere'  => [VARIABLETYPE_FLOAT, ' A', -200.0, 200.0, 0.1, 1],
            'MSA.Hertz'   => [VARIABLETYPE_FLOAT, ' Hz', 45.0, 65.0, 0.01, 2],
            'MSA.Celsius' => [VARIABLETYPE_FLOAT, ' °C', -40.0, 100.0, 0.1, 1],
            'MSA.Dbm'     => [VARIABLETYPE_FLOAT, ' dBm', -120.0, 0.0, 1.0, 0],
        ];
    }

    public function getEnumProfiles()
    {
        return [
            'MSA.BatStatus' => [0 => ['Ready', -1], 1 => ['Charge', 0x2E9E5B], 2 => ['Discharge', 0xE8A33D], 3 => ['Locked', 0xC0392B]],
            'MSA.EmsMode'   => [0 => ['Self-consumption (general)', -1], 1 => ['MQTT power control (mqtt_ctrl)', 0x3D7BE8], 2 => ['Schedule (tou_plan)', 0x8E44AD]],
        ];
    }

    // --- Empfang (statt Abfrage) -------------------------------------------------------

    private static function num($a, $key)
    {
        return (is_array($a) && isset($a[$key]) && is_numeric($a[$key])) ? (float)$a[$key] : null;
    }

    private static function pick($list, $type, $index)
    {
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $item) {
            if (is_array($item) && ($item['type'] ?? null) === $type) {
                return $item;
            }
        }
        return (isset($list[$index]) && is_array($list[$index])) ? $list[$index] : null;
    }

    private static function byId($list, $id)
    {
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $item) {
            if (is_array($item) && (int)($item['id'] ?? 0) === $id) {
                return $item;
            }
        }
        return (isset($list[$id - 1]) && is_array($list[$id - 1])) ? $list[$id - 1] : null;
    }

    /**
     * Verarbeitet eine MQTT-Nachricht des Geräts. $topic endet auf /quick/state, /device/state
     * oder /system/state; alles andere wird ignoriert.
     */
    public function onMqtt($hub, $topic, $payload)
    {
        $a = json_decode($payload, true);
        if (!is_array($a)) {
            return;
        }
        if (substr($topic, -12) === '/quick/state') {
            $this->quick($hub, $a);
        } elseif (substr($topic, -13) === '/device/state') {
            $this->device($hub, $a);
        } elseif (substr($topic, -13) === '/system/state') {
            $this->system($hub, $a);
        }
    }

    private function quick($hub, $a)
    {
        $soc = self::num($a, 'soc');
        if ($soc !== null) {
            $hub->SetVarInt('bat_soc', (int)round($soc));
        }
        $bat = self::num($a, 'sys_bat_p');
        if ($bat === null) {
            $bat = self::num($a, 'bat_p');
        }
        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', $bat);
        }
        $pv1 = self::num($a, 'sys_pv_p');
        $pv2 = self::num($a, 'sys_pv2_p');
        if ($pv1 !== null || $pv2 !== null) {
            $hub->SetVarFloat('pv_total', (float)($pv1 ?? 0.0) + (float)($pv2 ?? 0.0));
        } elseif (self::num($a, 'pv_p') !== null) {
            $hub->SetVarFloat('pv_total', self::num($a, 'pv_p'));
        }
        if ($pv1 !== null) { $hub->SetVarFloat('pv1_power', $pv1); }
        if ($pv2 !== null) { $hub->SetVarFloat('pv2_power', $pv2); }
        $map = ['pv_p' => 'pv_device', 'sys_load_p' => 'load_power', 'sys_plug_p' => 'plug_power', 'sys_sp_p' => 'sp_power',
                'sys_eps_p' => 'eps_power', 'grid_on_p' => 'grid_on_power', 'grid_off_p' => 'grid_off_power'];
        foreach ($map as $key => $ident) {
            $v = self::num($a, $key);
            if ($v !== null) {
                $hub->SetVarFloat($ident, $v);
            }
        }
        // sys_grid_p: + = Bezug (Hoymiles), InverterHub: + = Einspeisung.
        $grid = self::num($a, 'sys_grid_p');
        if ($grid !== null) {
            $hub->SetVarFloat('meter_total', -$grid);
        }
        $sys = self::num($a, 'sys_soc');
        if ($sys !== null) {
            $hub->SetVarInt('bat_soc_sys', (int)round($sys));
        }
        if (isset($a['bat_sts']) && is_string($a['bat_sts']) && isset(self::BAT_STATUS[$a['bat_sts']])) {
            $hub->SetVarInt('bat_mode', self::BAT_STATUS[$a['bat_sts']]);
        }
        if (isset($a['heat'])) {
            $hub->SetVarBool('bat_heat', (bool)$a['heat']);
        }
    }

    private function device($hub, $a)
    {
        $map = ['bat_v' => 'bat_volt', 'bat_i' => 'bat_curr', 'bat_temp' => 'bat_temp', 'rssi' => 'dev_rssi'];
        foreach ($map as $key => $ident) {
            $v = self::num($a, $key);
            if ($v !== null) {
                $hub->SetVarFloat($ident, $v);
            }
        }
        for ($i = 1; $i <= 4; $i++) {
            $p = self::byId($a['packs'] ?? null, $i);
            if ($p !== null) {
                $soc = self::num($p, 'soc');
                $temp = self::num($p, 'temp');
                if ($soc !== null) { $hub->SetVarInt('pack' . $i . '_soc', (int)round($soc)); }
                if ($temp !== null) { $hub->SetVarFloat('pack' . $i . '_temp', $temp); }
            }
        }
        $on = self::pick($a['grid'] ?? null, 'grid_on', 0);
        if ($on !== null) {
            if (self::num($on, 'v') !== null) { $hub->SetVarFloat('grid_volt', self::num($on, 'v')); }
            if (self::num($on, 'f') !== null) { $hub->SetVarFloat('grid_freq', self::num($on, 'f')); }
        }
        $inv = self::pick($a['grid'] ?? null, 'inv', 2);
        if ($inv !== null && self::num($inv, 'p') !== null) {
            $hub->SetVarFloat('inv_power', self::num($inv, 'p'));
        }
    }

    private function system($hub, $a)
    {
        // Tagesenergie in Wh -> kWh (die Treiber liefern kWh, der Wh-Schalter rechnet ggf. hoch).
        $map = ['pv_e' => 'e_pv_day', 'pv2_e' => 'e_pv2_day', 'chg_e' => 'e_charge_day', 'dchg_e' => 'e_discharge_day',
                'plug_in_e' => 'e_plug_in_day', 'plug_out_e' => 'e_plug_out_day', 'eps_in_e' => 'e_eps_in_day', 'eps_out_e' => 'e_eps_out_day'];
        foreach ($map as $key => $ident) {
            $v = self::num($a, $key);
            if ($v !== null) {
                $hub->SetVarFloat($ident, $v / 1000.0);
            }
        }
        if (isset($a['ems_mode']) && is_string($a['ems_mode'])) {
            $hub->SetVarStr('dev_ems_mode', $a['ems_mode']);
            $idx = array_search($a['ems_mode'], self::EMS_MODES, true);
            if ($idx !== false) {
                $hub->SetVarInt('ctl_ems_mode', (int)$idx);
            }
        }
    }

    // --- Takt und Steuerung ------------------------------------------------------------

    /** Wird vom Timer gerufen: Verbindung = es kommen noch Nachrichten. */
    public function readFast($mb, $hub)
    {
        $age = $hub->MqttAge();
        $ok = ($age >= 0 && $age <= self::STALE_SECONDS);
        $hub->SetVarBool('connected', $ok);
        return $ok;
    }

    public function readSlow($mb, $hub)
    {
        // Nichts zu lesen: alle Werte kommen per MQTT.
        return true;
    }

    public function readDeviceInfo($mb, $hub)
    {
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        $dev = $hub->MqttDeviceId();
        if ($dev === '') {
            $hub->WarnUser(IHUB_Text::t('MS-A2: device ID missing, command not sent.'));
            return;
        }
        switch ($ident) {
            case 'ctl_ems_mode':
                $mode = (int)$value;
                if (!isset(self::EMS_MODES[$mode])) {
                    $hub->WarnUser(IHUB_Text::t('MS-A2: unknown EMS mode %s (0 general, 1 mqtt_ctrl, 2 tou_plan).', $mode));
                    return;
                }
                if (!$hub->MqttPublish('homeassistant/select/' . $dev . '/ems_mode/command', self::EMS_MODES[$mode])) {
                    $hub->WarnUser(IHUB_Text::t('MS-A2: EMS mode command could not be sent (check MQTT bridge/server).'));
                    return;
                }
                $hub->SetVarInt('ctl_ems_mode', $mode);
                return;

            case 'ctl_power_ctrl':
                $w = (int)round((float)$value);
                if (!$hub->MqttPublish('homeassistant/number/' . $dev . '/power_ctrl/set', (string)$w)) {
                    $hub->WarnUser(IHUB_Text::t('MS-A2: power setpoint could not be sent (check MQTT bridge/server).'));
                    return;
                }
                $hub->SetVarInt('ctl_power_ctrl', $w);
                return;
        }
    }
}
