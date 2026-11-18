<?php

// ---------------------------------------------------------------------------
// IHUB_GoodweDriver — Treiber für GoodWe GW-ET/EH/BT/BH-Hybridwechselrichter
// Portiert 1:1 aus GoodweET/module.php (inkl. dokumentierter Firmware-Quirks)
// ---------------------------------------------------------------------------

class IHUB_GoodweDriver implements IHUB_InverterDriverInterface
{
    const REG_WORK_MODE         = 47000;
    const REG_EMS_ENABLE        = 47505;
    const REG_FEED_POWER_ENABLE = 47509;
    const REG_FEED_POWER_LIMIT  = 47510;
    const REG_EMS_POWER_MODE    = 47511;
    const REG_EMS_POWER_SET     = 47512;
    const REG_SOC_MIN           = 45356;
    const REG_SOC_MAX           = 45559;
    const REG_FAST_CHG_ENABLE   = 47545;   // Schnellladung aus dem Netz: 0 aus, 1 an
    const REG_FAST_CHG_SOC      = 47546;   // Ziel-SOC der Schnellladung, 1-100 %
    const REG_FAST_CHG_PCT      = 47603;   // erlaubte Netzladeleistung in % (Standard 100)
    const REG_INTERNET_MODE     = 47017;
    const REG_RESTART           = 45220;

    const EMS_POWER_MAX = 34500;

    const WORK_MODES = [
        0 => 'Self-consumption', 1 => 'Island mode', 2 => 'Backup',
        3 => 'Economic',  4 => 'Peak shaving',  5 => 'Extended self-consumption',
    ];

    // 255 (0xFF) ist der offizielle STOPPED-Modus der GoodWe-Firmware (OpenEMS
    // EmsPowerMode.java: "System shutdown. Stop working and turn to wait mode").
    // Die Firmware setzt ihn SELBST, wenn bei ctl_ems_enable=true der erwartete
    // Heartbeat des externen EMS ausbleibt (~70-120s) - Totmann-Vollzug. Ohne
    // eigenen Profileintrag zeigte IPS dafuer irrefuehrend die letzte
    // Assoziation ("Batterie - Entladen") an - real passiert (29.08.2026,
    // Dietmar auf falscher Faehrte). Deshalb eigener, unmissverstaendlicher
    // Eintrag mit Warnfarbe. Kein gueltiger SETZ-Wert: writeControl() erlaubt
    // weiterhin nur 0-12.
    const EMS_MODES = [
        0 => 'Stopped', 1 => 'Automatic', 2 => 'Charging - solar', 3 => 'Discharging + solar',
        4 => 'AC - import', 5 => 'AC - export', 6 => 'Energy saving', 7 => 'Island mode',
        8 => 'Battery - standby', 9 => 'Power purchase', 10 => 'Power sale',
        11 => 'Battery - charging', 12 => 'Battery - discharging',
        255 => '⚠️ Dead-man: control lost',
    ];

    const BAT_MODES = [0 => 'No Battery', 1 => 'Standby', 2 => 'discharging', 3 => 'charging'];

    const GRID_MODES = [
        0 => 'Waiting', 1 => 'Feed-in', 2 => 'Feed-in: limit', 3 => 'Feed-in: desaturation',
        4 => 'Feed-in: PV limit', 5 => 'Feed-in: reactive', 6 => 'Feed-in: reactive power',
        7 => 'Feed-in: shutdown', 8 => 'Feed-in: PV optimization', 9 => 'Feed-in: ECO',
        10 => 'Fault: HW protection', 11 => 'Fault', 17 => 'Bypass', 18 => 'Island mode',
    ];

    public function getBaseVars(){
        return [
            ['soc',           'SOC',                'I', '~Battery.100',      true,  'batcommon', 'BMS Ø'],
            ['work_mode',     'Operating mode',       'I', 'GWH.WorkMode',      true,  'device',    'RW 47000'],
            ['grid_mode',     'Grid mode',           'I', 'GWH.GridMode',      false, 'grid',      'DSP 35136'],
            ['island',        'Off-grid (island)', 'B', '~Alert',           true,  'grid',      'calc'],
            ['pv_total',      'PV total power',   'F', 'GWH.Watt',          true,  'pv',        'DSP 35301'],
            ['ac_power',      'AC active power',     'F', 'GWH.Watt',          true,  'device',    'DSP 35139'],
            ['bat_total_pwr', 'Bat. total power',  'F', 'GWH.Watt',          true,  'batcommon', 'Σ Bat1+Bat2'],
            ['meter_total',   'Grid power',       'F', 'GWH.Watt',          true,  'grid',      'SM 36025'],
            ['bat_charge_max_w',    'Bat. max. charge power',   'F', 'GWH.Watt', false, 'batcommon', 'BMS calc'],
            ['bat_discharge_max_w', 'Bat. max. discharge power','F', 'GWH.Watt', false, 'batcommon', 'BMS calc'],
            ['connected',     'Connection',          'B', '~Alert.Reversed',   false, 'errors',    ''],
            ['riso',          'Insulation resistance','F', 'GWH.KOhm',          true,  'device',    'DSP 35365'],
            // Live gefunden (28.07.2026, EMS-Sitzung): deutsche 70%-Regel-
            // Abregelung. Laut GoodWe-Doku nur 70 oder 100 sinnvoll ("Only
            // can set 70, only for German"). Bisher rein lesend, keine
            // Steuerung - Abregelung wird ueber SEMS+/den Netzbetreiber
            // gesetzt, nicht ueber InverterHub.
            ['derate_pct',    '70% rule curtailment','I', 'GWH.Percent',       true,  'grid',      'RW 45263'],
        ];
    }

    public function getOptionalGroups(){
        return [
            'EnableTracker1' => ['caption' => 'MPPT tracker 1 (PV1+PV2)', 'vars' => [
                ['pv1_voltage', 'PV1 voltage (tracker 1 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35103'],
                ['pv1_current', 'PV1 current (tracker 1 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35104'],
                ['pv2_voltage', 'PV2 voltage (tracker 1 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35107'],
                ['pv2_current', 'PV2 current (tracker 1 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35108'],
                ['mppt1_power',   'MPPT1 power', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35337'],
                ['mppt1_current', 'MPPT1 current',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35345'],
            ]],
            'EnableTracker2' => ['caption' => 'MPPT tracker 2 (PV3+PV4)', 'vars' => [
                ['pv3_voltage', 'PV3 voltage (tracker 2 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35111'],
                ['pv3_current', 'PV3 current (tracker 2 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35112'],
                ['pv4_voltage', 'PV4 voltage (tracker 2 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35115'],
                ['pv4_current', 'PV4 current (tracker 2 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35116'],
                ['mppt2_power',   'MPPT2 power', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35338'],
                ['mppt2_current', 'MPPT2 current',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35346'],
            ]],
            'EnableTracker3' => ['caption' => 'MPPT tracker 3 (PV5+PV6)', 'vars' => [
                ['pv5_voltage', 'PV5 voltage (tracker 3 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35304'],
                ['pv5_current', 'PV5 current (tracker 3 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35305'],
                ['pv6_voltage', 'PV6 voltage (tracker 3 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35306'],
                ['pv6_current', 'PV6 current (tracker 3 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35307'],
                ['mppt3_power',   'MPPT3 power', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35339'],
                ['mppt3_current', 'MPPT3 current',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35347'],
            ]],
            'GroupGrid' => ['caption' => 'Grid L1/L2/L3 (voltage, current, frequency, power)', 'vars' => [
                ['grid_l1_volt', 'Grid L1 voltage', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35121'],
                ['grid_l1_curr', 'Grid L1 current',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35122'],
                ['grid_l1_freq', 'Grid L1 frequency', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35123'],
                ['grid_l1_pwr',  'Grid L1 power', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35124'],
                ['grid_l2_volt', 'Grid L2 voltage', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35126'],
                ['grid_l2_curr', 'Grid L2 current',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35127'],
                ['grid_l2_freq', 'Grid L2 frequency', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35128'],
                ['grid_l2_pwr',  'Grid L2 power', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35129'],
                ['grid_l3_volt', 'Grid L3 voltage', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35131'],
                ['grid_l3_curr', 'Grid L3 current',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35132'],
                ['grid_l3_freq', 'Grid L3 frequency', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35133'],
                ['grid_l3_pwr',  'Grid L3 power', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35134'],
                ['inv_total',    'Inverter total',   'F', 'GWH.Watt',   true,  'grid', 'DSP 35137'],
                ['grid_freq',    'Grid frequency',      'F', 'GWH.Hertz',  false, 'grid', 'SM 36014'],
            ]],
            'GroupBat1' => ['caption' => 'Battery 1 (voltage, current, power, mode, SOC)', 'vars' => [
                ['bat1_volt', 'Bat.1 voltage', 'F', 'GWH.Volt',   false, 'bat1', 'DSP 35180'],
                ['bat1_curr', 'Bat.1 current',    'F', 'GWH.Ampere', true,  'bat1', 'DSP 35181'],
                ['bat1_pwr',  'Bat.1 power', 'F', 'GWH.Watt',   true,  'bat1', 'DSP 35182'],
                ['bat1_mode', 'Bat.1 mode',    'I', 'GWH.BatMode', true,  'bat1', 'DSP 35184'],
                ['bat1_soc',  'Bat.1 SOC',      'I', '~Battery.100', true, 'bat1', 'BMS 47908'],
                ['bat1_soh',  'Bat.1 SOH',      'I', '~Intensity.100', true, 'bat1', 'BMS 47909'],
                ['bat1_temp', 'Bat.1 temperature', 'F', '~Temperature', true, 'bat1', 'BMS 47910'],
                ['bat1_bms_volt', 'Bat.1 voltage (BMS)', 'F', 'GWH.Volt',   false, 'bat1', 'BMS 47906'],
                ['bat1_bms_curr', 'Bat.1 current (BMS)',    'F', 'GWH.Ampere', true,  'bat1', 'BMS 47907'],
                ['bat1_chg_max_a','Bat.1 max. charge current',   'F', 'GWH.Ampere', false, 'bat1', 'BMS 47903'],
                ['bat1_dis_max_a','Bat.1 max. discharge current', 'F', 'GWH.Ampere', false, 'bat1', 'BMS 47905'],
                ['bat1_bms_warn', 'Bat.1 BMS warning',       'I', '', true,  'bat1', 'BMS 47911'],
                ['bat1_bms_alarm','Bat.1 BMS alarm',         'I', '', true,  'bat1', 'BMS 47913'],
                // BMS-Zelldiagnostik (37003er-Block). Zellspannungsspreizung
                // (vmax−vmin) ist ein Frühindikator für Batteriealterung.
                ['bat1_pack_temp','Bat.1 pack temperature',  'F', '~Temperature',  false, 'bat1', 'BMS 37003 (÷10)'],
                ['bat1_cell_vmax','Bat.1 cell voltage max',      'I', 'GWH.MilliVolt', false, 'bat1', 'BMS 37022'],
                ['bat1_cell_vmin','Bat.1 cell voltage min',      'I', 'GWH.MilliVolt', false, 'bat1', 'BMS 37023'],
                ['bat1_cell_tmax','Bat.1 cell temp. max',     'F', '~Temperature',  false, 'bat1', 'BMS 37020 (÷10)'],
                ['bat1_cell_tmin','Bat.1 cell temp. min',     'F', '~Temperature',  false, 'bat1', 'BMS 37021 (÷10)'],
                ['bat1_idx_vmax', 'Bat.1 cell no. voltage max','I', 'GWH.CellIdx',   false, 'bat1', 'BMS 37018'],
                ['bat1_idx_vmin', 'Bat.1 cell no. voltage min','I', 'GWH.CellIdx',   false, 'bat1', 'BMS 37019'],
                ['bat1_idx_tmax', 'Bat.1 cell no. temp. max','I', 'GWH.CellIdx',  false, 'bat1', 'BMS 37016'],
                ['bat1_idx_tmin', 'Bat.1 cell no. temp. min','I', 'GWH.CellIdx',  false, 'bat1', 'BMS 37017'],
            ]],
            'GroupBat2' => ['caption' => 'Battery 2 (voltage, current, power, mode)', 'vars' => [
                ['bat2_volt', 'Bat.2 voltage', 'F', 'GWH.Volt',   false, 'bat2', 'DSP 35262'],
                ['bat2_curr', 'Bat.2 current',    'F', 'GWH.Ampere', true,  'bat2', 'DSP 35263'],
                ['bat2_pwr',  'Bat.2 power', 'F', 'GWH.Watt',   true,  'bat2', 'DSP 35264'],
                ['bat2_mode', 'Bat.2 mode',    'I', 'GWH.BatMode', true,  'bat2', 'DSP 35266'],
                ['bat2_soc',  'Bat.2 SOC',      'I', '~Battery.100', true, 'bat2', 'BMS 47926'],
                ['bat2_soh',  'Bat.2 SOH',      'I', '~Intensity.100', true, 'bat2', 'BMS 47927'],
                ['bat2_temp', 'Bat.2 temperature', 'F', '~Temperature', true, 'bat2', 'BMS 47928'],
                ['bat2_bms_volt', 'Bat.2 voltage (BMS)', 'F', 'GWH.Volt',   false, 'bat2', 'BMS 47924'],
                ['bat2_bms_curr', 'Bat.2 current (BMS)',    'F', 'GWH.Ampere', true,  'bat2', 'BMS 47925'],
                // BMS-Zelldiagnostik Batterie 2 (39001er-Block), analog Bat.1.
                ['bat2_pack_temp','Bat.2 pack temperature',  'F', '~Temperature',  false, 'bat2', 'BMS 39001 (÷10)'],
                ['bat2_cell_vmax','Bat.2 cell voltage max',      'I', 'GWH.MilliVolt', false, 'bat2', 'BMS 39020'],
                ['bat2_cell_vmin','Bat.2 cell voltage min',      'I', 'GWH.MilliVolt', false, 'bat2', 'BMS 39021'],
                ['bat2_cell_tmax','Bat.2 cell temp. max',     'F', '~Temperature',  false, 'bat2', 'BMS 39018 (÷10)'],
                ['bat2_cell_tmin','Bat.2 cell temp. min',     'F', '~Temperature',  false, 'bat2', 'BMS 39019 (÷10)'],
                ['bat2_idx_vmax', 'Bat.2 cell no. voltage max','I', 'GWH.CellIdx',   false, 'bat2', 'BMS 39016'],
                ['bat2_idx_vmin', 'Bat.2 cell no. voltage min','I', 'GWH.CellIdx',   false, 'bat2', 'BMS 39017'],
                ['bat2_idx_tmax', 'Bat.2 cell no. temp. max','I', 'GWH.CellIdx',  false, 'bat2', 'BMS 39014'],
                ['bat2_idx_tmin', 'Bat.2 cell no. temp. min','I', 'GWH.CellIdx',  false, 'bat2', 'BMS 39015'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (kWh today/total: PV, grid, load, battery)', 'vars' => [
                ['e_pv_day',       'PV today',           'F', '~Electricity', true, 'energy', 'DSP 35193'],
                ['e_pv_total',     'PV total',           'F', '~Electricity', true, 'energy', 'DSP 35191'],
                ['e_sell_day',     'Feed-in today',   'F', '~Electricity', true, 'energy', 'DSP 35199'],
                ['e_buy_day',      'Import today',         'F', '~Electricity', true, 'energy', 'DSP 35202'],
                ['e_load_day',     'Load today',          'F', '~Electricity', true, 'energy', 'DSP 35205'],
                ['e_load_total',   'Load total',         'F', '~Electricity', true, 'energy', 'DSP 35203'],
                ['e_charge_day',   'Bat. charge today',    'F', '~Electricity', true, 'energy', 'DSP 35208'],
                ['e_charge_total', 'Bat. charge total',   'F', '~Electricity', true, 'energy', 'DSP 35206'],
                ['e_disch_day',    'Bat. discharge today',    'F', '~Electricity', true, 'energy', 'DSP 35211'],
                ['e_disch_total',  'Bat. discharge total',   'F', '~Electricity', true, 'energy', 'DSP 35209'],
                ['bms_e_charge_total', 'Bat. charge total (BMS)',    'F', '~Electricity', true, 'energy', 'DSP 37056-37057 (U32, 0.1 kWh)'],
                ['bms_e_disch_total',  'Bat. discharge total (BMS)', 'F', '~Electricity', true, 'energy', 'DSP 37058-37059 (U32, 0.1 kWh)'],
                ['work_hours',     'Operating hours',     'F', '', false, 'energy', 'DSP 35197'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (power, voltage, current per phase)', 'vars' => [
                ['mt_l1_volt', 'Grid L1 voltage', 'F', 'GWH.Volt',   false, 'meter', 'SM 36052'],
                ['mt_l2_volt', 'Grid L2 voltage', 'F', 'GWH.Volt',   false, 'meter', 'SM 36053'],
                ['mt_l3_volt', 'Grid L3 voltage', 'F', 'GWH.Volt',   false, 'meter', 'SM 36054'],
                ['mt_l1_curr', 'Grid L1 current',    'F', 'GWH.Ampere', false, 'meter', 'SM 36055'],
                ['mt_l2_curr', 'Grid L2 current',    'F', 'GWH.Ampere', false, 'meter', 'SM 36056'],
                ['mt_l3_curr', 'Grid L3 current',    'F', 'GWH.Ampere', false, 'meter', 'SM 36057'],
                ['mt_l1_pwr',  'Grid L1 power', 'F', 'GWH.Watt',   true,  'meter', 'SM 36019'],
                ['mt_l2_pwr',  'Grid L2 power', 'F', 'GWH.Watt',   true,  'meter', 'SM 36021'],
                ['mt_l3_pwr',  'Grid L3 power', 'F', 'GWH.Watt',   true,  'meter', 'SM 36023'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatures (air, module, heat sink)', 'vars' => [
                ['temp_air',      'Air temperature',  'F', '~Temperature', false, 'device', 'DSP 35174'],
                ['temp_module',   'Module temperature', 'F', '~Temperature', true,  'device', 'DSP 35175'],
                ['temp_heatsink', 'Heat sink',    'F', '~Temperature', true,  'device', 'DSP 35176'],
            ]],
            'GroupBackup' => ['caption' => 'Backup / island mode (power, voltage per phase)', 'vars' => [
                ['backup_total', 'Backup power',  'F', 'GWH.Watt', true,  'backup', 'DSP 35169'],
                ['backup_active','Backup active',     'B', '~Switch',   false, 'backup', 'RW 45252'],
                ['backup_l1_volt','Backup voltage L1', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35145'],
                ['backup_l1_curr','Backup current L1',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35146'],
                ['backup_l1_freq','Backup frequency L1', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35147'],
                ['backup_l1_pwr', 'Backup power L1',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35149'],
                ['backup_l2_volt','Backup voltage L2', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35151'],
                ['backup_l2_curr','Backup current L2',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35152'],
                ['backup_l2_freq','Backup frequency L2', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35153'],
                ['backup_l2_pwr', 'Backup power L2',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35155'],
                ['backup_l3_volt','Backup voltage L3', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35157'],
                ['backup_l3_curr','Backup current L3',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35158'],
                ['backup_l3_freq','Backup frequency L3', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35159'],
                ['backup_l3_pwr', 'Backup power L3',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35161'],
            ]],
            'GroupErrors' => ['caption' => 'Error and warning codes', 'vars' => [
                ['warn_code',  'Warning code',      'I', '', true, 'errors', 'DSP 32000'],
                ['err_msg',    'Error code',    'I', '', true, 'errors', 'DSP 32002'],
                ['err_detail', 'Error detail', 'S', '', true, 'errors', ''],
                // Live gefunden (28.07.2026, EMS-Sitzung): 'warn_code'/'err_msg'
                // oben sind WECHSELRICHTER-seitige Codes und erfassen BMS-
                // Schutzereignisse (z.B. Ausgangsport-Ueberspannung bei fast
                // vollem SOC) NICHT - die stehen nur im SEMS+-Portal. Eigener,
                // separater Registerblock fuer die Batterie-BMS.
                ['bms1_err_code',  'BMS1 error code',  'I', '', true, 'errors', 'DSP 37006/37012'],
                ['bms1_warn_code', 'BMS1 warning code',    'I', '', true, 'errors', 'DSP 37010/37013'],
                // Live gefunden (28.07.2026): bms1_err_code/warn_code oben
                // blieben WAEHREND eines aktiven SEMS+-Alarms (Batteriestring-
                // Ueberspannung) durchgehend 0 - die falsche Fundstelle. Die
                // tatsaechlich bit-codierte Diagnose steht in DiagStatusL
                // (Register 35220, U32, "Table 8-14 Diagnostic Status"), u.a.
                // Bit 15 BatteryOvercharge, Bit 17 BMSOvercharge. Roh als
                // Ganzzahl abgelegt (kein Bit-Decode in der UI) - Bits bei
                // Bedarf gegen die Tabelle in CLAUDE.md pruefen.
                ['diag_status_l', 'Diagnostic status (bit field)', 'I', '', true, 'errors', 'DSP 35220 (U32), Table 8-14'],
            ]],
            'GroupDevice' => ['caption' => 'Device information (serial number, model, firmware)', 'vars' => [
                ['dev_sn',      'Serial number', 'S', '', false, 'device', 'DSP 35003'],
                ['dev_model',   'Model',       'S', '', false, 'device', 'DSP 35011'],
                ['dev_rated_w', 'Rated power', 'I', '', false, 'device', 'DSP 35001'],
                ['dev_fw_arm',  'Firmware ARM', 'I', '', false, 'device', 'DSP 35019'],
                ['dev_fw_dsp',  'Firmware DSP', 'I', '', false, 'device', 'DSP 35016'],
            ]],
            'GroupControl' => ['caption' => 'EMS control (operating mode, feed-in limit, SOC limits)', 'vars' => [
                ['ctl_work_mode',     'Control mode',          'I', 'GWH.WorkMode', false, 'control', 'RW 47000'],
                ['ctl_ems_enable',    'EMS control active',  'B', '~Switch',      false, 'control', 'RW 47505'],
                ['ctl_ems_mode',      'EMS power mode',   'I', 'GWH.EMSMode',  false, 'control', 'RW 47511'],
                ['ctl_ems_power',     'EMS power (W)',     'I', 'GWH.WattEMS',  false, 'control', 'RW 47512'],
                // ACHTUNG, Semantik von 47509 ist umgekehrt zur frueheren
                // Beschriftung: Das Register heisst bei GoodWe „Feed_Power_Enable"
                // und schaltet nicht die EINSPEISUNG, sondern deren BEGRENZUNG.
                // „Aus" = keine Begrenzung = unbegrenzt einspeisen.
                // „Ein" = Begrenzung aktiv, und zwar auf den Wert aus 47510.
                // Die alte Beschriftung „Einspeisung Ja/Nein" verleitete dazu,
                // den Schalter einzuschalten, um Einspeisung zu ERLAUBEN - bei
                // einer hinterlegten Grenze von 0 W ist das Ergebnis
                // Nulleinspeisung, also das Gegenteil der Absicht.
                // Belegt an einer realen Anlage: Schalter „Aus", Grenze 0 W,
                // und trotzdem 42,7 kWh Einspeisung am selben Tag.
                // Idents bleiben unveraendert (Idents sind API).
                ['ctl_export_enable', 'Feed-in limit active', 'B', '~Switch',      false, 'control', 'RW 47509 (Feed_Power_Enable: EIN = Begrenzung aus 47510 gilt)'],
                ['ctl_export_limit',  'Feed-in limit (W)',       'I', 'GWH.WattEMS',  false, 'control', 'RW 47510 (only effective while the limit is active)'],
                ['ctl_soc_min',       'SOC min. discharge',   'I', 'GWH.Percent',  false, 'control', 'RW 45356'],
                // Live getestet und WIDERLEGT (28.07.2026, EMS-Sitzung): Register
                // 45559 "Max Charge SOC" nimmt den geschriebenen Wert an (roh
                // gegengelesen, 97 wurde uebernommen), verhindert das Laden
                // ueber die Grenze hinaus aber NICHT - bei SOC 98%, Grenze 97,
                // Automatik-Modus lud die Batterie trotzdem sofort mit -4559W
                // weiter. Genau dasselbe Muster wie ctl_soc_min (45356, s. o.):
                // Register laesst sich schreiben, hat aber keine beobachtbare
                // Wirkung auf das tatsaechliche Ladeverhalten. NICHT als
                // funktionierenden Kontrollmechanismus behandeln oder Nutzern
                // als Loesung gegen Ueberladung nahe 100% SOC empfehlen.
                ['ctl_soc_max',       'SOC max. charge (confirmed ineffective)', 'I', 'GWH.Percent', false, 'control', 'RW 45559'],
                // Schnellladung (GoodWe Third-Party-EMS-Protokoll V1.1.1, Tabelle 9, Nr. 29-31). Gemessen
                // 23.10.2026 an Dietmars Anlage (SOC 71 %, Ziel 80 %): laedt aus dem Netz mit ca. 300 W je
                // Prozent Netzleistung (10 % = 3,0 kW, 30 % = 9,0 kW), auch bei Modus 8; aus -> Ruhelast
                // binnen 15 s. Kein Flash-Register.
                ['ctl_fastcharge_enable', 'Fast charging active',          'B', '~Switch',      false, 'control', 'RW 47545 (gemessen)'],
                ['ctl_fastcharge_soc',    'Fast charging up to SOC (%)',    'I', 'GWH.Percent',  false, 'control', 'RW 47546 (gemessen)'],
                ['ctl_fastcharge_pct',    'Fast charging grid power (%)', 'I', 'GWH.Percent', false, 'control', 'RW 47603 (gemessen, ca. 300 W je %)'],
                ['ctl_internet',      'Cloud connection',     'B', '~Switch',      false, 'control', 'RW 47017'],
                ['ctl_restart',       'Inverter restart',          'B', '~Switch',      false, 'control', 'WO 45220'],
                // Generische Netzdienlichkeits-Operationen (Verbund-Vertrag mit
                // EMS, IHUB_GetFunctions 1.2 -> 1.3, "gridServiceCapabilities" -
                // 12.09.2026). Schreiben dieselben Register wie die ctl_*-Idents
                // oben - EMS nutzt fuer eine Instanz entweder ctl_* (heutige
                // Automatik-/Grid-Rewards-/Tagesplan-Steuerung) ODER svc_*
                // (neue netzdienliche Bausteine), nie beide gleichzeitig auf
                // derselben Instanz (sonst zwei Regler auf einer Batterie -
                // das durchzusetzen liegt bei EMS, nicht hier). Alle drei
                // Sollwert-Operationen fahren bewusst mit enable=false (47505)
                // statt true: Der 255/STOPPED-Ruecklauf tritt laut unserem
                // A/B-Test (29.08.2026) nur bei enable=true ohne Heartbeat
                // auf - mit enable=false haelt der gesetzte Modus dauerhaft,
                // ohne dass EMS selbst einen Totmann-Heartbeat bauen muesste.
                ['svc_charge_inhibit',      'Grid-supportive: block charging',            'B', '~Switch',     false, 'control', 'writes 47511=3 (discharging+solar), 47512=0, 47505=false'],
                ['svc_grid_charge_w',       'Grid-supportive: grid charging (W)',             'I', 'GWH.WattEMS', false, 'control', 'writes 47511=9 (power purchase, NAP-controlled), 47512=W, 47505=false; 0 = release'],
                ['svc_discharge_to_grid_w', 'Grid-supportive: feed in from battery (W)', 'I', 'GWH.WattEMS', false, 'control', 'writes 47511=3 (discharging+solar, Xmax = W), 47512=W, 47505=false; 0 = release'],
                ['svc_release',             'Grid-supportive: release (automatic)',     'B', '~Switch',     false, 'control', 'writes 47511=1 (automatic), 47512=0, 47505=false'],
            ]],
        ];
    }

    public function getExtraBooleanProperties(){
        return [
            'EnableTracker1' => true,
            'EnableTracker2' => true,
            'EnableTracker3' => true,
        ];
    }

    public function getProfiles(){
        return [
            'GWH.Watt'      => [VARIABLETYPE_FLOAT,   ' W',  -40000.0, 40000.0, 1.0,  0],
            'GWH.Volt'      => [VARIABLETYPE_FLOAT,   ' V',       0.0,  1000.0, 0.1,  1],
            'GWH.Ampere'    => [VARIABLETYPE_FLOAT,   ' A',    -200.0,   200.0, 0.1,  1],
            'GWH.Hertz'     => [VARIABLETYPE_FLOAT,   ' Hz',     45.0,    65.0, 0.01, 2],
            'GWH.Percent'   => [VARIABLETYPE_INTEGER, ' %',          0,     100, 1,    0],
            'GWH.WattEMS'   => [VARIABLETYPE_INTEGER, ' W',          0,   34500, 1,    0],
            'GWH.KOhm'      => [VARIABLETYPE_FLOAT,   ' kΩ',       0.0,  5000.0, 0.1,  1],
            'GWH.MilliVolt' => [VARIABLETYPE_INTEGER, ' mV',         0,    5000, 1,    0],
            'GWH.CellIdx'   => [VARIABLETYPE_INTEGER, '',            0,     512, 1,    0],
        ];
    }

    public function getEnumProfiles(){
        $wmColors = [0xF5A623, 0x7A8A99, 0x2BB3C0, 0x27D07F, 0xE74C3C, 0xF39C12];
        $workMode = [];
        foreach (self::WORK_MODES as $k => $label) {
            $workMode[$k] = [$label, $wmColors[$k] ?? 0x7A8A99];
        }
        $emsMode = [];
        foreach (self::EMS_MODES as $k => $label) {
            // 255 = Totmann-Zustand in Warnrot, alle regulaeren Modi neutral.
            $emsMode[$k] = [$label, $k === 255 ? 0xE74C3C : 0x7A8A99];
        }
        $batMode = [];
        foreach (self::BAT_MODES as $k => $label) {
            $batMode[$k] = [$label, 0x7A8A99];
        }
        $gridMode = [];
        foreach (self::GRID_MODES as $k => $label) {
            $gridMode[$k] = [$label, 0x7A8A99];
        }
        return [
            'GWH.WorkMode' => $workMode,
            'GWH.EMSMode'  => $emsMode,
            'GWH.BatMode'  => $batMode,
            'GWH.GridMode' => $gridMode,
        ];
    }

    // Verbindungs-Konkurrenz mit RequestAction()/writeControl() (24.08.2026,
    // live an Dietmars Anlage + unabhaengig von EMS bestaetigt, siehe CLAUDE.md
    // "GoodWe reagiert schleppend auf Schaltbefehle"): readFast() las bisher
    // JEDEN der ~15-20 Register-Bloecke ueber eine EIGENE, frisch geoeffnete
    // Verbindung (kein Batch-Modus, anders als beim Sungrow-Treiber). Faellt
    // ein RequestAction()-Schreibbefehl (eigene, ebenfalls frische Verbindung
    // ueber GetModbusClient()) in dieses mehrere Sekunden lange Lesefenster,
    // konkurrieren beide um die GoodWe-Firmware - beobachtetes Symptom: der
    // Schaltbefehl wird laut IPS-Variable sofort uebernommen, die reale
    // Batterieleistung bleibt aber 20-50+ Sekunden bei ~0W/Rauschen. Analog zum
    // Sungrow-WiNet-S-Muster oben: EINE wiederverwendete Verbindung fuer den
    // gesamten Lesezyklus verkuerzt das Konfliktfenster von mehreren Sekunden
    // auf einen Bruchteil.
    public function readFast($mb, $hub)
    {
        $mb->beginBatch();
        try {
            return $this->readFastInner($mb, $hub);
        } finally {
            $mb->endBatch();
        }
    }

    private function readFastInner($mb, $hub){
        $inv     = $mb->readHolding(35103, 42);
        $bat1blk = $mb->readHolding(35174, 18);
        $bat2blk = $mb->readHolding(35262, 7);
        $pvext   = $mb->readHolding(35301, 41);
        $meter   = $mb->readHolding(36019, 39);
        // WBMS Bat1+Bat2 in einem Block ab 47900 (count 34). Ab 47900 lesen,
        // sonst liefern 47908/47909 (SOC/SOH) 0xFFFF!
        $bms     = $mb->readHolding(47900, 34);

        $ok = ($inv !== null && $bat1blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }
        // Ein fremdes Geraet (z. B. ein SMA-Wechselrichter, bei dem versehentlich
        // GoodWe eingestellt wurde) antwortet auf diese Adressen mit lauter
        // 0xFFFF. Ohne diese Pruefung entstuenden daraus 65535 %, 6553,5 V und
        // Seriennummern aus lauter „ÿ".
        if ($hub->BlockLooksUnset($inv)) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        $pvTotal = ($pvext !== null) ? (float)$mb->u32($pvext, 0) : 0.0;
        $hub->SetVarFloat('pv_total', $pvTotal);

        $derateBlk = $mb->readHolding(45263, 1);
        if ($derateBlk !== null) {
            $hub->SetVarInt('derate_pct', $mb->u16($derateBlk, 0));
        }

        $risoBlk = $mb->readHolding(35365, 1);
        if ($risoBlk !== null) {
            $hub->SetVarFloat('riso', $mb->u16($risoBlk, 0) / 10.0);
        }

        $wm = $mb->readHolding(47000, 1);
        if ($wm !== null) {
            $hub->SetVarInt('work_mode', $mb->u16($wm, 0));
        }

        // Rueckgelesener IST-Zustand von ctl_ems_mode/ctl_ems_power - reines
        // Reporting (Architektur-Entscheidung Dietmar, 29.08.2026: InverterHub
        // ist NUR Kommunikationsschicht; jede Steuerungs-POLITIK - Heartbeat,
        // Totmann-Fallback, Reassert - liegt beim EMS). Die 255 (offizieller
        // STOPPED-Modus, Firmware-Totmann bei ausbleibendem EMS-Heartbeat,
        // per OpenEMS-Quelle bestaetigt) wird hier nur sichtbar gemacht:
        // eigener Profileintrag (s. EMS_MODES) + einmalige Log-Warnung beim
        // Uebergang. KEIN automatischer Eingriff - das entscheidet das EMS.
        $prevEmsMode = $hub->GetVarInt('ctl_ems_mode');
        $emsCtl = $mb->readHolding(self::REG_EMS_POWER_MODE, 2);
        if ($emsCtl !== null) {
            $emsModeLive = $mb->u16($emsCtl, 0);
            $hub->SetVarInt('ctl_ems_mode', $emsModeLive);
            $hub->SetVarInt('ctl_ems_power', $mb->u16($emsCtl, 1));
            if ($emsModeLive === 255 && $prevEmsMode !== 255) {
                $hub->WarnUser(IHUB_Text::t('GoodWe dead-man switch triggered: register 47511 is at 255 (control lost, inverter in safety stop/standby mode). InverterHub does not intervene - the reaction (command again, ctl_ems_enable off, heartbeat) is up to the EMS or the user.'));
            }
        }

        $bat2Active = $hub->GetPropBool('GroupBat2') && ($bat2blk !== null);
        $soc1 = ($bms !== null) ? (float)$mb->u16($bms, 8)  : 0.0;
        $soc2 = ($bms !== null) ? (float)$mb->u16($bms, 26) : 0.0;
        $hub->SetVarInt('soc', (int)round($bat2Active ? (($soc1 + $soc2) / 2.0) : $soc1));

        if ($hub->GetPropBool('EnableTracker1')) {
            $hub->SetVarFloat('pv1_voltage', $mb->u16($inv, 0) / 10.0);
            $hub->SetVarFloat('pv1_current', $mb->u16($inv, 1) / 10.0);
            $hub->SetVarFloat('pv2_voltage', $mb->u16($inv, 4) / 10.0);
            $hub->SetVarFloat('pv2_current', $mb->u16($inv, 5) / 10.0);
        }
        if ($hub->GetPropBool('EnableTracker2')) {
            $hub->SetVarFloat('pv3_voltage', $mb->u16($inv, 8)  / 10.0);
            $hub->SetVarFloat('pv3_current', $mb->u16($inv, 9)  / 10.0);
            $hub->SetVarFloat('pv4_voltage', $mb->u16($inv, 12) / 10.0);
            $hub->SetVarFloat('pv4_current', $mb->u16($inv, 13) / 10.0);
        }
        if ($hub->GetPropBool('EnableTracker3') && $pvext !== null) {
            $hub->SetVarFloat('pv5_voltage', $mb->u16($pvext, 3) / 10.0);
            $hub->SetVarFloat('pv5_current', $mb->u16($pvext, 4) / 10.0);
            $hub->SetVarFloat('pv6_voltage', $mb->u16($pvext, 6) / 10.0);
            $hub->SetVarFloat('pv6_current', $mb->u16($pvext, 7) / 10.0);
        }

        // Firmware-Quirk (live verifiziert): Requests ab Register 35333+
        // liefern für 35338/35339 nur 0xFFFF. Ab 35332 oder früher stabil.
        $mpptBlk = $mb->readHolding(35332, 16);
        if ($mpptBlk !== null) {
            if ($hub->GetPropBool('EnableTracker1')) {
                $hub->SetVarFloat('mppt1_power',   (float)$mb->u16($mpptBlk, 5));
                $hub->SetVarFloat('mppt1_current', (float)$mb->u16($mpptBlk, 13));
            }
            if ($hub->GetPropBool('EnableTracker2')) {
                $hub->SetVarFloat('mppt2_power',   (float)$mb->u16($mpptBlk, 6));
                $hub->SetVarFloat('mppt2_current', (float)$mb->u16($mpptBlk, 14));
            }
            if ($hub->GetPropBool('EnableTracker3')) {
                $hub->SetVarFloat('mppt3_power',   (float)$mb->u16($mpptBlk, 7));
                $hub->SetVarFloat('mppt3_current', (float)$mb->u16($mpptBlk, 15));
            }
        }

        $gridMode = $mb->u16($inv, 33);
        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_l1_volt', $mb->u16($inv, 18) / 10.0);
            $hub->SetVarFloat('grid_l1_curr', $mb->u16($inv, 19) / 10.0);
            $hub->SetVarFloat('grid_l1_freq', $mb->u16($inv, 20) / 100.0);
            $hub->SetVarFloat('grid_l1_pwr',  (float)$mb->s32($inv, 21));
            $hub->SetVarFloat('grid_l2_volt', $mb->u16($inv, 23) / 10.0);
            $hub->SetVarFloat('grid_l2_curr', $mb->u16($inv, 24) / 10.0);
            $hub->SetVarFloat('grid_l2_freq', $mb->u16($inv, 25) / 100.0);
            $hub->SetVarFloat('grid_l2_pwr',  (float)$mb->s32($inv, 26));
            $hub->SetVarFloat('grid_l3_volt', $mb->u16($inv, 28) / 10.0);
            $hub->SetVarFloat('grid_l3_curr', $mb->u16($inv, 29) / 10.0);
            $hub->SetVarFloat('grid_l3_freq', $mb->u16($inv, 30) / 100.0);
            $hub->SetVarFloat('grid_l3_pwr',  (float)$mb->s32($inv, 31));
            $hub->SetVarFloat('inv_total',    (float)$mb->s32($inv, 34));
        }
        $hub->SetVarInt('grid_mode',  $gridMode);
        $hub->SetVarFloat('ac_power', (float)$mb->s32($inv, 36));
        $hub->SetVarBool('island', ($gridMode === 17 || $gridMode === 18));

        if ($meter !== null) {
            $hub->SetVarFloat('meter_total', (float)$mb->s32($meter, 6));
        }

        if ($hub->GetPropBool('GroupBat1')) {
            $hub->SetVarFloat('bat1_volt', $mb->u16($bat1blk, 6)  / 10.0);
            $hub->SetVarFloat('bat1_curr', $mb->s16($bat1blk, 7)  / 10.0);
            $hub->SetVarFloat('bat1_pwr',  (float)$mb->s32($bat1blk, 8));
            $hub->SetVarInt('bat1_mode',   $mb->u16($bat1blk, 10));
            $hub->SetVarInt('bat1_soc',  (int)round($soc1));
            if ($bms !== null) {
                $hub->SetVarInt('bat1_soh',  $mb->u16($bms, 9));
                $hub->SetVarFloat('bat1_temp', $mb->s16($bms, 10) / 10.0);
                $hub->SetVarFloat('bat1_bms_volt', $mb->u16($bms, 6) / 10.0);
                $hub->SetVarFloat('bat1_bms_curr', $mb->s16($bms, 7) / 10.0);
                $hub->SetVarFloat('bat1_chg_max_a', $mb->u16($bms, 3) / 10.0);
                $hub->SetVarFloat('bat1_dis_max_a', $mb->u16($bms, 5) / 10.0);
                $hub->SetVarInt('bat1_bms_warn',  $mb->u32($bms, 11));
                $hub->SetVarInt('bat1_bms_alarm', $mb->u32($bms, 13));
            }
            // BMS-Zelldiagnostik: eigener Block 37003 (count 21). Getrennt vom
            // BMS-Block gelesen; Offsets an der Anlage verifiziert (aus dem
            // GoodweET-Vorgängermodul übernommen).
            $cells1 = $mb->readHolding(37003, 21);
            if ($cells1 !== null) {
                $hub->SetVarFloat('bat1_pack_temp', $mb->s16($cells1, 0) / 10.0);  // 37003
                $hub->SetVarInt('bat1_idx_tmax',    $mb->u16($cells1, 13));         // 37016
                $hub->SetVarInt('bat1_idx_tmin',    $mb->u16($cells1, 14));         // 37017
                $hub->SetVarInt('bat1_idx_vmax',    $mb->u16($cells1, 15));         // 37018
                $hub->SetVarInt('bat1_idx_vmin',    $mb->u16($cells1, 16));         // 37019
                $hub->SetVarFloat('bat1_cell_tmax', $mb->s16($cells1, 17) / 10.0);  // 37020
                $hub->SetVarFloat('bat1_cell_tmin', $mb->s16($cells1, 18) / 10.0);  // 37021
                $hub->SetVarInt('bat1_cell_vmax',   $mb->u16($cells1, 19));         // 37022
                $hub->SetVarInt('bat1_cell_vmin',   $mb->u16($cells1, 20));         // 37023
            }
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp_air',      $mb->s16($bat1blk, 0) / 10.0);
            $hub->SetVarFloat('temp_module',   $mb->s16($bat1blk, 1) / 10.0);
            $hub->SetVarFloat('temp_heatsink', $mb->s16($bat1blk, 2) / 10.0);
        }

        if ($bat2Active) {
            $hub->SetVarFloat('bat2_volt', $mb->u16($bat2blk, 0)  / 10.0);
            $hub->SetVarFloat('bat2_curr', $mb->s16($bat2blk, 1)  / 10.0);
            $hub->SetVarFloat('bat2_pwr',  (float)$mb->s32($bat2blk, 2));
            $hub->SetVarInt('bat2_mode',   $mb->u16($bat2blk, 4));
            $hub->SetVarInt('bat2_soc',  (int)round($soc2));
            if ($bms !== null) {
                $hub->SetVarInt('bat2_soh',  $mb->u16($bms, 27));
                $hub->SetVarFloat('bat2_temp', $mb->s16($bms, 28) / 10.0);
                $hub->SetVarFloat('bat2_bms_volt', $mb->u16($bms, 24) / 10.0);
                $hub->SetVarFloat('bat2_bms_curr', $mb->s16($bms, 25) / 10.0);
            }
            // BMS-Zelldiagnostik Batterie 2: Block 39001 (count 21), analog Bat.1.
            $cells2 = $mb->readHolding(39001, 21);
            if ($cells2 !== null) {
                $hub->SetVarFloat('bat2_pack_temp', $mb->s16($cells2, 0) / 10.0);  // 39001
                $hub->SetVarInt('bat2_idx_tmax',    $mb->u16($cells2, 13));         // 39014
                $hub->SetVarInt('bat2_idx_tmin',    $mb->u16($cells2, 14));         // 39015
                $hub->SetVarInt('bat2_idx_vmax',    $mb->u16($cells2, 15));         // 39016
                $hub->SetVarInt('bat2_idx_vmin',    $mb->u16($cells2, 16));         // 39017
                $hub->SetVarFloat('bat2_cell_tmax', $mb->s16($cells2, 17) / 10.0);  // 39018
                $hub->SetVarFloat('bat2_cell_tmin', $mb->s16($cells2, 18) / 10.0);  // 39019
                $hub->SetVarInt('bat2_cell_vmax',   $mb->u16($cells2, 19));         // 39020
                $hub->SetVarInt('bat2_cell_vmin',   $mb->u16($cells2, 20));         // 39021
            }
        }

        $b1p = $mb->s32($bat1blk, 8);
        $b2p = ($bat2blk !== null) ? $mb->s32($bat2blk, 2) : 0;
        $hub->SetVarFloat('bat_total_pwr', (float)($b1p + $b2p));

        if ($bms !== null) {
            $v1      = $mb->u16($bms, 6) / 10.0;
            $chgMaxW = ($mb->u16($bms, 3) / 10.0) * $v1;
            $disMaxW = ($mb->u16($bms, 5) / 10.0) * $v1;
            if ($bat2Active) {
                $v2 = $mb->u16($bms, 24) / 10.0;
                $chgMaxW += ($mb->u16($bms, 21) / 10.0) * $v2;
                $disMaxW += ($mb->u16($bms, 23) / 10.0) * $v2;
            }
            $hub->SetVarFloat('bat_charge_max_w',    $chgMaxW);
            $hub->SetVarFloat('bat_discharge_max_w', $disMaxW);
        }

        if ($hub->GetPropBool('GroupMeter') && $meter !== null) {
            $hub->SetVarFloat('mt_l1_pwr',  (float)$mb->s32($meter, 0));
            $hub->SetVarFloat('mt_l2_pwr',  (float)$mb->s32($meter, 2));
            $hub->SetVarFloat('mt_l3_pwr',  (float)$mb->s32($meter, 4));
            $hub->SetVarFloat('mt_l1_volt', $mb->u16($meter, 33) / 10.0);
            $hub->SetVarFloat('mt_l2_volt', $mb->u16($meter, 34) / 10.0);
            $hub->SetVarFloat('mt_l3_volt', $mb->u16($meter, 35) / 10.0);
            $hub->SetVarFloat('mt_l1_curr', $mb->u16($meter, 36) / 10.0);
            $hub->SetVarFloat('mt_l2_curr', $mb->u16($meter, 37) / 10.0);
            $hub->SetVarFloat('mt_l3_curr', $mb->u16($meter, 38) / 10.0);
            $freqBlk = $mb->readHolding(36014, 1);
            if ($freqBlk !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freqBlk, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBackup')) {
            $bkPhase = $mb->readHolding(35145, 26);
            if ($bkPhase !== null) {
                $hub->SetVarFloat('backup_l1_volt', $mb->u16($bkPhase, 0)  / 10.0);
                $hub->SetVarFloat('backup_l1_curr', $mb->u16($bkPhase, 1)  / 10.0);
                $hub->SetVarFloat('backup_l1_freq', $mb->u16($bkPhase, 2)  / 100.0);
                $hub->SetVarFloat('backup_l1_pwr',  (float)$mb->s32($bkPhase, 4));
                $hub->SetVarFloat('backup_l2_volt', $mb->u16($bkPhase, 6)  / 10.0);
                $hub->SetVarFloat('backup_l2_curr', $mb->u16($bkPhase, 7)  / 10.0);
                $hub->SetVarFloat('backup_l2_freq', $mb->u16($bkPhase, 8)  / 100.0);
                $hub->SetVarFloat('backup_l2_pwr',  (float)$mb->s32($bkPhase, 10));
                $hub->SetVarFloat('backup_l3_volt', $mb->u16($bkPhase, 12) / 10.0);
                $hub->SetVarFloat('backup_l3_curr', $mb->u16($bkPhase, 13) / 10.0);
                $hub->SetVarFloat('backup_l3_freq', $mb->u16($bkPhase, 14) / 100.0);
                $hub->SetVarFloat('backup_l3_pwr',  (float)$mb->s32($bkPhase, 16));
                $hub->SetVarFloat('backup_total',   (float)$mb->s32($bkPhase, 24));
            }
            $bkSt = $mb->readHolding(45252, 1);
            if ($bkSt !== null) {
                $hub->SetVarBool('backup_active', $mb->u16($bkSt, 0) > 0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub){
        // Schnellladung zuruecklesen (nur wenn die Steuerung eingeschaltet ist; Fehler still)
        if ($hub->GetPropBool('GroupControl')) {
            $fc = $mb->readHolding(self::REG_FAST_CHG_ENABLE, 2);
            if ($fc !== null) {
                $hub->SetVarBool('ctl_fastcharge_enable', $mb->u16($fc, 0) === 1);
                $hub->SetVarInt('ctl_fastcharge_soc', $mb->u16($fc, 1));
            }
            $fp = $mb->readHolding(self::REG_FAST_CHG_PCT, 1);
            if ($fp !== null) {
                $hub->SetVarInt('ctl_fastcharge_pct', $mb->u16($fp, 0));
            }
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            $e = $mb->readHolding(35191, 22);
            if ($e !== null) {
                // Liefert das Geraet fuer einen Zaehler 0xFFFF (16 Bit) bzw. 0xFFFFFFFF (32 Bit),
                // ist das "ungueltig" (Beobachtet 01.11.2026: Tageszaehler 6553,5 kWh im Archiv,
                // Dietmars Anlage). Solche Werte nicht uebernehmen, der alte Stand bleibt stehen.
                $put = function (string $ident, int $raw, int $sentinel) use ($hub) {
                    if ($raw !== $sentinel) {
                        $hub->SetVarFloat($ident, $raw / 10.0);
                    }
                };
                $put('e_pv_total',     $mb->u32($e, 0),  0xFFFFFFFF);
                $put('e_pv_day',       $mb->u32($e, 2),  0xFFFFFFFF);
                $hub->SetVarFloat('work_hours',     (float)$mb->u32($e, 6));
                $put('e_sell_day',     $mb->u16($e, 8),  0xFFFF);
                $put('e_buy_day',      $mb->u16($e, 11), 0xFFFF);
                $put('e_load_total',   $mb->u32($e, 12), 0xFFFFFFFF);
                $put('e_load_day',     $mb->u16($e, 14), 0xFFFF);
                $put('e_charge_total', $mb->u32($e, 15), 0xFFFFFFFF);
                $put('e_charge_day',   $mb->u16($e, 17), 0xFFFF);
                $put('e_disch_total',  $mb->u32($e, 18), 0xFFFFFFFF);
                $put('e_disch_day',    $mb->u16($e, 20), 0xFFFF);
            }
            // BMS-Gesamtzaehler (37056 Laden, 37058 Entladen, U32, 0,1 kWh): laut Messung 07.11.2026
            // belastbar, die Wechselrichter-Gesamtzaehler 35206/35209 sind exakt doppelt (siehe CLAUDE.md).
            // 0xFFFFFFFF = ungueltig, dann bleibt der alte Stand stehen.
            $bmsE = $mb->readHolding(37056, 4);
            if ($bmsE !== null) {
                foreach ([['bms_e_charge_total', 0], ['bms_e_disch_total', 2]] as $pair) {
                    $raw = $mb->u32($bmsE, $pair[1]);
                    if ($raw !== 0xFFFFFFFF) {
                        $hub->SetVarFloat($pair[0], $raw / 10.0);
                    }
                }
            }
        }

        if ($hub->GetPropBool('GroupErrors')) {
            $err = $mb->readHolding(32000, 17);
            if ($err !== null) {
                $hub->SetVarInt('warn_code', $mb->u16($err, 0));
                $hub->SetVarInt('err_msg',   $mb->u16($err, 2));
                $utility = $mb->u16($err, 0);
                $detail  = [];
                if ($utility & 0x01) { $detail[] = IHUB_Text::t('Grid overvoltage'); }
                if ($utility & 0x02) { $detail[] = IHUB_Text::t('Grid undervoltage'); }
                if ($utility & 0x04) { $detail[] = IHUB_Text::t('Grid overfrequency'); }
                if ($utility & 0x08) { $detail[] = IHUB_Text::t('Grid underfrequency'); }
                if ($utility & 0x10) { $detail[] = IHUB_Text::t('Grid overcurrent'); }
                $sys = $mb->u16($err, 2);
                if ($sys & 0x01) { $detail[] = IHUB_Text::t('System error 1'); }
                $hub->SetVarStr('err_detail', empty($detail) ? 'OK' : implode(', ', $detail));
            }
            // Separater BMS-Fehler-/Warncode-Block (37006/37010/37012/37013) -
            // NICHT identisch mit den WR-seitigen Codes oben, s. Kommentar bei
            // der Variablendefinition.
            $bmsErrL  = $mb->readHolding(37006, 1);
            $bmsWarnL = $mb->readHolding(37010, 1);
            $bmsErrH  = $mb->readHolding(37012, 1);
            $bmsWarnH = $mb->readHolding(37013, 1);
            if ($bmsErrL !== null && $bmsErrH !== null) {
                $hub->SetVarInt('bms1_err_code', ($mb->u16($bmsErrH, 0) << 16) | $mb->u16($bmsErrL, 0));
            }
            if ($bmsWarnL !== null && $bmsWarnH !== null) {
                $hub->SetVarInt('bms1_warn_code', ($mb->u16($bmsWarnH, 0) << 16) | $mb->u16($bmsWarnL, 0));
            }
            $diagL = $mb->readHolding(35220, 2);
            if ($diagL !== null) {
                $hub->SetVarInt('diag_status_l', ($mb->u16($diagL, 0) << 16) | $mb->u16($diagL, 1));
            }
        }
    }

    public function readDeviceInfo($mb, $hub){
        $dev = $mb->readHolding(35001, 27);
        if ($dev === null) {
            return;
        }
        $hub->SetVarInt('dev_rated_w', $mb->u16($dev, 0));
        $hub->SetVarStr('dev_sn',      $mb->readStr($dev, 2, 8));
        $hub->SetVarStr('dev_model',   $mb->readStr($dev, 10, 5));
        $hub->SetVarInt('dev_fw_dsp',  $mb->u16($dev, 15));
        $hub->SetVarInt('dev_fw_arm',  $mb->u16($dev, 18));
    }

    /**
     * Steht der Wert schon im Register? Lesen kostet kein Flash; die Register
     * 47509/47510/45356 sind laut GoodWe-Protokoll "einmal schreiben". Bei
     * Lesefehler false -> es wird geschrieben wie bisher.
     */
    private function regAlready($mb, int $reg, int $val): bool
    {
        $r = $mb->readHolding($reg, 1);
        return $r !== null && $mb->u16($r, 0) === $val;
    }

    public function writeControl($mb, $hub, string $ident, $value){
        switch ($ident) {
            case 'ctl_work_mode':
                $val = (int)$value;
                if ($val < 0 || $val > 5) { return; }
                if ($mb->writeSingle(self::REG_WORK_MODE, $val)) {
                    $hub->SetVarInt('ctl_work_mode', $val);
                    $hub->SetVarInt('work_mode', $val);
                }
                break;

            case 'ctl_ems_enable':
                // 1 = ein. Der frueher geschriebene Wert 2 (Erbe aus dem GoodweET-Port,
                // ohne dokumentierten Grund) erzeugt live eine langsame Rampe: ~15 s
                // Anlauf, dann +250 W je 6 s, bei Modus 9 nur ein Turm. Mit 1 volle
                // Leistung nach ~1 s (EMS-Live-Test 19.09.2026, Register 47505 per FC6).
                $val = (bool)$value ? 1 : 0;
                if ($mb->writeSingle(self::REG_EMS_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_ems_enable', (bool)$value);
                }
                break;

            case 'ctl_ems_mode':
                $val = (int)$value;
                if ($val < 0 || $val > 12) { return; }
                if ($mb->writeSingle(self::REG_EMS_POWER_MODE, $val)) {
                    $hub->SetVarInt('ctl_ems_mode', $val);
                }
                break;

            case 'ctl_ems_power':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($mb->writeSingle(self::REG_EMS_POWER_SET, $val)) {
                    $hub->SetVarInt('ctl_ems_power', $val);
                }
                break;

            case 'ctl_export_enable':
                $val = (bool)$value ? 1 : 0;
                // Flash-Register (GoodWe: einmal schreiben): nur bei geaendertem Wert
                if ($this->regAlready($mb, self::REG_FEED_POWER_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_export_enable', (bool)$value);
                    break;
                }
                // Einschalten heisst: Die Begrenzung aus 47510 gilt ab jetzt.
                // Steht dort 0 W, ist das Ergebnis NULLEINSPEISUNG. Das kann
                // gewollt sein (Direktvermarktung, Netzbetreiberauflage), ist
                // aber meist ein Missverstaendnis - deshalb ein deutlicher
                // Hinweis ins Meldungslog, statt es stillschweigend zu tun.
                if ($val === 1) {
                    $lim = $mb->readHolding(self::REG_FEED_POWER_LIMIT, 1);
                    if ($lim !== null && $mb->u16($lim, 0) === 0) {
                        $hub->WarnUser(IHUB_Text::t('Feed-in limit was switched on, but the stored limit is 0 W. The inverter therefore feeds NOTHING into the grid any more. If this is not intended: set the limit to the desired value or switch the limit off again.'));
                    }
                }
                if ($mb->writeSingle(self::REG_FEED_POWER_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_export_enable', (bool)$value);
                }
                break;

            case 'ctl_export_limit':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($this->regAlready($mb, self::REG_FEED_POWER_LIMIT, $val)) {
                    $hub->SetVarInt('ctl_export_limit', $val);
                    break;
                }
                if ($mb->writeSingle(self::REG_FEED_POWER_LIMIT, $val)) {
                    $hub->SetVarInt('ctl_export_limit', $val);
                }
                break;

            case 'ctl_fastcharge_enable':
                $val = (bool)$value ? 1 : 0;
                if ($mb->writeSingle(self::REG_FAST_CHG_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_fastcharge_enable', (bool)$value);
                }
                break;

            case 'ctl_fastcharge_soc':
                $val = max(1, min(100, (int)$value));
                if ($mb->writeSingle(self::REG_FAST_CHG_SOC, $val)) {
                    $hub->SetVarInt('ctl_fastcharge_soc', $val);
                }
                break;

            case 'ctl_fastcharge_pct':
                $val = max(0, min(100, (int)$value));
                if ($mb->writeSingle(self::REG_FAST_CHG_PCT, $val)) {
                    $hub->SetVarInt('ctl_fastcharge_pct', $val);
                }
                break;

            case 'ctl_soc_min':
                $val = max(0, min(100, (int)$value));
                if ($this->regAlready($mb, self::REG_SOC_MIN, $val)) {
                    $hub->SetVarInt('ctl_soc_min', $val);
                    break;
                }
                if ($mb->writeSingle(self::REG_SOC_MIN, $val)) {
                    $hub->SetVarInt('ctl_soc_min', $val);
                }
                break;

            case 'ctl_soc_max':
                $val = max(0, min(100, (int)$value));
                if ($mb->writeSingle(self::REG_SOC_MAX, $val)) {
                    $hub->SetVarInt('ctl_soc_max', $val);
                }
                break;

            case 'ctl_internet':
                $val = (bool)$value ? 0 : 1;
                if ($mb->writeSingle(self::REG_INTERNET_MODE, $val)) {
                    $hub->SetVarBool('ctl_internet', (bool)$value);
                }
                break;

            case 'ctl_restart':
                if ((bool)$value) {
                    $mb->writeSingle(self::REG_RESTART, 1);
                    IPS_Sleep(500);
                    $hub->SetVarBool('ctl_restart', false);
                }
                break;

            // Generische Netzdienlichkeits-Operationen (EMS-Vertrag, s. o. bei
            // GroupControl). Die jeweils ANDEREN svc_*-Statusvariablen werden
            // nur bei vollstaendigem Erfolg auf ihren Neutralzustand
            // zurueckgesetzt, damit nie mehrere gleichzeitig "aktiv" anzeigen
            // (immer nur eine Operation kann real gelten) - misslingt ein
            // Schreibvorgang, bleibt der bisherige Anzeigezustand stehen,
            // statt einen ungewissen WR-Zustand als "aktiv" zu behaupten.
            case 'svc_charge_inhibit':
                if ((bool)$value) {
                    if ($this->writeGridService($mb, $hub, self::EMS_MODE_DISCHARGE_PV, 0)) {
                        $hub->SetVarBool('svc_charge_inhibit', true);
                        $hub->SetVarInt('svc_grid_charge_w', 0);
                        $hub->SetVarInt('svc_discharge_to_grid_w', 0);
                    } else {
                        $hub->WarnUser(IHUB_Text::t('Grid-supportive "block charging" only partially written - inverter state uncertain, read the registers back.'));
                    }
                } else {
                    $this->releaseGridService($mb, $hub);
                }
                break;

            case 'svc_grid_charge_w':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($val === 0) {
                    $this->releaseGridService($mb, $hub);
                    break;
                }
                if ($this->writeGridService($mb, $hub, self::EMS_MODE_GRID_BUY, $val)) {
                    $hub->SetVarInt('svc_grid_charge_w', $val);
                    $hub->SetVarBool('svc_charge_inhibit', false);
                    $hub->SetVarInt('svc_discharge_to_grid_w', 0);
                } else {
                    $hub->WarnUser(IHUB_Text::t('Grid-supportive "grid charging" only partially written - inverter state uncertain, read the registers back.'));
                }
                break;

            case 'svc_discharge_to_grid_w':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($val === 0) {
                    $this->releaseGridService($mb, $hub);
                    break;
                }
                if ($this->writeGridService($mb, $hub, self::EMS_MODE_DISCHARGE_PV, $val)) {
                    $hub->SetVarInt('svc_discharge_to_grid_w', $val);
                    $hub->SetVarBool('svc_charge_inhibit', false);
                    $hub->SetVarInt('svc_grid_charge_w', 0);
                } else {
                    $hub->WarnUser(IHUB_Text::t('Grid-supportive "feed in from battery" only partially written - inverter state uncertain, read the registers back.'));
                }
                break;

            case 'svc_release':
                if ((bool)$value) {
                    $this->releaseGridService($mb, $hub);
                }
                break;
        }
    }

    // EMS-Leistungsmodi, die die generischen svc_*-Operationen ansteuern
    // (Auszug aus EMS_MODES, hier benannt statt als Magic Number).
    const EMS_MODE_DISCHARGE_PV = 3; // "Entladen + Solar" - Xset ist Sollwert, keine Obergrenze (EMS-Fund 12.09.2026)
    const EMS_MODE_GRID_BUY     = 9; // "Stromeinkauf" - Netzbezug am Netzanschlusspunkt geregelt, sicherer als 11 (EMS-Test 24.08.2026)
    const EMS_MODE_AUTO         = 1; // "Automatik" - WR-Eigenregelung, Ziel von svc_release

    // Uebergang ueber Null, aber NUR bei echtem Moduswechsel (EMS-Fund
    // 13.09.2026, zweite Korrektur - jede feste Zwei-Schritt-Reihenfolge
    // (Modus->Leistung oder Leistung->Modus) hat fuer irgendeinen Uebergang
    // ein Loch: Modus 3 ist ein erzwungener Sollwert (Xset), keine Obergrenze,
    // also gilt zwischen den beiden Schreibvorgaengen kurz entweder der ALTE
    // Modus mit der NEUEN Leistung oder der NEUE Modus mit der ALTEN Leistung
    // - je nach Reihenfolge ein Entladestoss in die eine oder andere Richtung.
    // Sicher ist nur: Leistung erst auf 0 (harmlos unter JEDEM Modus - Modus 3
    // mit 0 sperrt das Laden, Modus 9 mit 0 hat keinen Netzbezug-Sollwert,
    // Modus 1 ignoriert den Wert ohnehin), dann Modus wechseln, dann die
    // Zielleistung setzen. Bleibt der Modus gleich (nur die Leistung aendert
    // sich, z. B. Grid-Rewards-Nachfuehren), entfaellt der Null-Schritt - er
    // wuerde nur unnoetig auf 0 flackern. enable=false zuletzt, unabhaengig
    // vom Zweig (idempotent, kein Modus-/Leistungswechsel dadurch). EMS
    // uebernimmt dieselbe Logik in setGoodweMode().
    private function writeGridService($mb, $hub, int $mode, int $powerW): bool
    {
        $modeChanges = $hub->GetVarInt('ctl_ems_mode') !== $mode;
        $ok = true;

        if ($modeChanges) {
            if ($mb->writeSingle(self::REG_EMS_POWER_SET, 0)) {
                $hub->SetVarInt('ctl_ems_power', 0);
            } else {
                // Null-Schritt fehlgeschlagen (EMS-Fund 13.09.2026): den
                // Moduswechsel HIER abbrechen statt trotzdem fortzufahren -
                // sonst liefe genau der Zwischenzustand, den der Null-Schritt
                // verhindern soll (neuer Modus mit der alten Leistung). WR
                // bleibt im alten, bekannten Zustand; EMS versucht es im
                // naechsten Zyklus erneut.
                $hub->WarnUser(IHUB_Text::t('Grid-supportive: mode change aborted - power could not be set to 0 beforehand, the inverter stays in its previous mode.'));
                return false;
            }
            if ($mb->writeSingle(self::REG_EMS_POWER_MODE, $mode)) {
                $hub->SetVarInt('ctl_ems_mode', $mode);
            } else {
                $ok = false;
            }
        }

        if (!$modeChanges || $powerW > 0) {
            if ($mb->writeSingle(self::REG_EMS_POWER_SET, $powerW)) {
                $hub->SetVarInt('ctl_ems_power', $powerW);
            } else {
                $ok = false;
            }
        }

        if ($mb->writeSingle(self::REG_EMS_ENABLE, 0)) {
            $hub->SetVarBool('ctl_ems_enable', false);
        } else {
            $ok = false;
        }

        return $ok;
    }

    private function releaseGridService($mb, $hub): void
    {
        if ($this->writeGridService($mb, $hub, self::EMS_MODE_AUTO, 0)) {
            $hub->SetVarBool('svc_charge_inhibit', false);
            $hub->SetVarInt('svc_grid_charge_w', 0);
            $hub->SetVarInt('svc_discharge_to_grid_w', 0);
        } else {
            $hub->WarnUser(IHUB_Text::t('Grid-supportive "release" only partially written - inverter state uncertain, read the registers back.'));
        }
    }
}
