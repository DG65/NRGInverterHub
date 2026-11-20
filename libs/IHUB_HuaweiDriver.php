<?php

// ---------------------------------------------------------------------------
// IHUB_HuaweiDriver — Huawei SUN2000 (L1/M1) + DTSU666-Zähler + LUNA2000-Batterie.
// Native Huawei-Registermap (FC 0x03, Big-Endian, int16/int32 mit Gain, kein
// SunSpec). Register/Gain verbatim aus der Bibliothek wlcrs/huawei-solar-lib
// (huawei_solar/registers.py) übernommen. Standard-Unit-ID des Wechselrichters
// ist 1 (je nach Konfiguration auch 0/16), Port 502.
// ---------------------------------------------------------------------------

class IHUB_HuaweiDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        0x0000 => 'Standby: initialization', 0x0001 => 'Standby: insulation resistance',
        0x0002 => 'Standby: irradiation',    0x0003 => 'Standby: grid detection',
        0x0100 => 'Starting',                  0x0200 => 'Grid operation',
        0x0201 => 'Grid operation: power limited', 0x0202 => 'Grid operation: self-derating',
        0x0300 => 'Shutdown: fault',      0x0301 => 'Shutdown: command',
        0x0304 => 'Shutdown: power limited', 0x0306 => 'Shutdown: DC switch open',
        0x0308 => 'Shutdown: input underpowered', 0xA000 => 'Standby: no irradiation',
    ];
    const BAT_STATUS = [
        0 => 'Offline', 1 => 'Standby', 2 => 'Running', 3 => 'Fault', 4 => 'Sleep mode',
    ];

    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Operating status',    'I', 'HUA.Status',      true,  'device', 'RO 32089'],
            ['pv_total',  'PV total power (DC input)', 'F', 'HUA.Watt', true, 'pv',   'RO 32064 (I32)'],
            ['ac_power',  'AC active power',   'F', 'HUA.Watt',        true,  'device', 'RO 32080 (I32)'],
            ['riso',      'Insulation resistance', 'F', 'HUA.MOhm',     true,  'pv',     'RO 32088 (÷1000, MΩ)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Grid (voltage, current, frequency)', 'vars' => [
                ['grid_volt', 'Grid voltage',  'F', 'HUA.Volt',   false, 'grid', 'RO 32069 (÷10)'],
                ['grid_curr', 'Grid current',     'F', 'HUA.Ampere', false, 'grid', 'RO 32072 (I32, ÷1000)'],
                ['grid_freq', 'Grid frequency',   'F', 'HUA.Hertz',  false, 'grid', 'RO 32085 (÷100)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp_cab', 'Indoor temperature', 'F', '~Temperature', false, 'device', 'RO 32087 (÷10)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (yield total/today)', 'vars' => [
                ['e_total', 'Yield total', 'F', '~Electricity', true, 'energy', 'RO 32106 (U32, ÷100)'],
                ['e_day',   'Yield today',  'F', '~Electricity', true, 'energy', 'RO 32114 (U32, ÷100)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (DTSU666)', 'vars' => [
                ['meter_total', 'Grid power (meter, + feed-in / − import)', 'F', 'HUA.Watt', true, 'meter', 'RO 37113 (I32)'],
                ['meter_volt',  'Meter voltage L1', 'F', 'HUA.Volt',   false, 'meter', 'RO 37101 (I32, ÷10)'],
                ['meter_curr',  'Meter current L1',    'F', 'HUA.Ampere', false, 'meter', 'RO 37107 (I32, ÷100)'],
                ['meter_freq',  'Meter frequency',    'F', 'HUA.Hertz',  false, 'meter', 'RO 37118 (I16, ÷100)'],
            ]],
            'GroupBattery' => ['caption' => 'Battery (LUNA2000)', 'vars' => [
                ['bat_soc',    'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 37004 (÷10)'],
                ['bat_power',  'Bat. power (+ discharging / − charging)', 'F', 'HUA.Watt', true, 'bat', 'RO 37001 (I32)'],
                ['bat_volt',   'Bat. voltage',   'F', 'HUA.Volt',    false, 'bat', 'RO 37003 (÷10)'],
                ['bat_curr',   'Bat. current',      'F', 'HUA.Ampere',  false, 'bat', 'RO 37021 (I16, ÷10)'],
                ['bat_temp',   'Bat. temperature', 'F', '~Temperature', false, 'bat', 'RO 37022 (I16, ÷10)'],
                ['bat_status', 'Bat. state',    'I', 'HUA.BatStatus', true, 'bat', 'RO 37000'],
            ]],
            'GroupDevice' => ['caption' => 'Device information', 'vars' => [
                ['dev_model', 'Model', 'S', '', false, 'device', 'RO 30000 (String)'],
            ]],
        ] + [
            // Fernsteuerung des Speichers (Zwangsladen/-entladen, Register 47100 ff.), UNGETESTET: Vorgabe aus, gemeinsame Schicht IHUB_RemoteControl
            'GroupControl' => ['caption' => 'Remote control of the battery (untested: built from the Huawei document and other projects, off by default)', 'default' => false, 'vars' => IHUB_RemoteControl::vars()],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'HUA.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -100000.0, 100000.0, 1.0,  0],
            'HUA.Volt'   => [VARIABLETYPE_FLOAT, ' V',        0.0,   1000.0, 0.1,  1],
            'HUA.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -1000.0,   1000.0, 0.1,  1],
            'HUA.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',      45.0,     65.0, 0.01, 2],
            'HUA.MOhm'   => [VARIABLETYPE_FLOAT, ' MΩ',       0.0,    100.0, 0.001, 3],
        ] + IHUB_RemoteControl::profiles();
    }

    public function getEnumProfiles()
    {
        $st = [];
        foreach (self::STATUS as $k => $label) {
            $st[$k] = [$label, 0x7A8A99];
        }
        $bs = [];
        foreach (self::BAT_STATUS as $k => $label) {
            $bs[$k] = [$label, 0x7A8A99];
        }
        return ['HUA.Status' => $st, 'HUA.BatStatus' => $bs] + IHUB_RemoteControl::enumProfiles();
    }

    public function readFast($mb, $hub)
    {
        // Block 32064..32089 (26 Register): Eingangs-/Wirkleistung, Netz, Temp, Status.
        $a = $mb->readHolding(32064, 26);
        $ok = ($a !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', (float)$mb->s32($a, 0));   // 32064 Input power (DC)
        $hub->SetVarFloat('ac_power', (float)$mb->s32($a, 16));  // 32080 Active power
        $hub->SetVarInt('status', $mb->u16($a, 25));             // 32089 Device status
        $hub->SetVarFloat('riso', $mb->u16($a, 24) / 1000.0);    // 32088 Isolationswiderstand (MΩ)

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_volt', $mb->u16($a, 5) / 10.0);    // 32069
            $hub->SetVarFloat('grid_curr', $mb->s32($a, 8) / 1000.0);  // 32072
            $hub->SetVarFloat('grid_freq', $mb->u16($a, 21) / 100.0);  // 32085
        }
        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp_cab', $mb->s16($a, 23) / 10.0);    // 32087
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $m = $mb->readHolding(37100, 19); // 37100..37118
            if ($m !== null) {
                // Huawei-Zähler: + = Einspeisung, − = Bezug (Modul-Konvention).
                $hub->SetVarFloat('meter_total', (float)$mb->s32($m, 13)); // 37113
                $hub->SetVarFloat('meter_volt',  $mb->s32($m, 1) / 10.0);  // 37101
                $hub->SetVarFloat('meter_curr',  $mb->s32($m, 7) / 100.0); // 37107
                $hub->SetVarFloat('meter_freq',  $mb->s16($m, 18) / 100.0); // 37118
            }
        }

        if ($hub->GetPropBool('GroupBattery')) {
            $b = $mb->readHolding(37000, 23); // 37000..37022
            if ($b !== null) {
                $hub->SetVarInt('bat_status', $mb->u16($b, 0));            // 37000
                // Huawei: + = Laden. Modul-Konvention + = Entladen -> negieren.
                $hub->SetVarFloat('bat_power', (float)(-$mb->s32($b, 1))); // 37001
                $hub->SetVarFloat('bat_volt', $mb->u16($b, 3) / 10.0);     // 37003
                $hub->SetVarInt('bat_soc', (int)round($mb->u16($b, 4) / 10.0)); // 37004
                $hub->SetVarFloat('bat_curr', -$mb->s16($b, 21) / 10.0);   // 37021
                $hub->SetVarFloat('bat_temp', $mb->s16($b, 22) / 10.0);    // 37022
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupControl')) {
            IHUB_RemoteControl::refresh(new IHUB_RemoteHuawei(), $mb, $hub);   // Fernsteuerung: Fähigkeit (Register 47000) und Kennzeichnung
        }
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e = $mb->readHolding(32106, 10); // 32106..32115
        if ($e !== null) {
            $hub->SetVarFloat('e_total', $mb->u32($e, 0) / 100.0);  // 32106
            $hub->SetVarFloat('e_day',   $mb->u32($e, 8) / 100.0);  // 32114
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $name = $mb->readHolding(30000, 15); // Model name (STR, 30 Zeichen)
        if ($name !== null) {
            $hub->SetVarStr('dev_model', $mb->readStr($name, 0, 15));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Nur die gemeinsame Fernsteuerung (Zwangsladen/-entladen), UNGETESTET (rc_tested = false); keine weiteren Schreibwege.
        if (strpos($ident, 'ctl_rc_') === 0) {
            IHUB_RemoteControl::control(new IHUB_RemoteHuawei(), $mb, $hub, $ident, $value);
        }
    }
}
