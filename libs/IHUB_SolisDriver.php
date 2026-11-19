<?php

// ---------------------------------------------------------------------------
// IHUB_SolisDriver — Solis Hybrid-Wechselrichter (33000er-Registerblock).
// Registeradressen direkt verwendet. Mess-/Statuswerte: Read Input Register
// (FC 0x04). Reine String-Wechselrichter (3000er-Block) werden derzeit
// nicht unterstützt.
// ---------------------------------------------------------------------------

class IHUB_SolisDriver implements IHUB_InverterDriverInterface
{
    // Richtung aus dem letzten Lesezyklus (readFast), fuer Batteriestrom und -leistung.
    private $batDischarging = false;

    public function getBaseVars()
    {
        return [
            ['connected',   'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',    'PV total power', 'F', 'SLS.Watt', true,  'pv',       'RO 33057-33058'],
            ['ac_power',    'AC active power',   'F', 'SLS.Watt', true,  'device',   'RO 33079-33080'],
            ['meter_total', 'Grid power (+ feed-in / − import)', 'F', 'SLS.Watt', true, 'grid', 'RO 33151-33152'],
            ['bat_power',   'Bat. total power (+ discharging / − charging)', 'F', 'SLS.Watt', true, 'batcommon', 'RO 33149-33150 + Richtung RO 33135 (+ Akku 2)'],
            ['bat_soc',     'SOC (avg.)',           'I', '~Battery.100', true, 'batcommon', 'RO 33139 (average with two batteries)'],
            ['bat_charge_max_w',    'Bat. max. charge power',    'F', 'SLS.Watt', false, 'batcommon', 'BMS calc'],
            ['bat_discharge_max_w', 'Bat. max. discharge power', 'F', 'SLS.Watt', false, 'batcommon', 'BMS calc'],
            ['grid_mode',   'Grid mode',         'I', 'SLS.GridMode', false, 'grid', 'calc'],
        ];
    }

    public function getOptionalGroups()
    {
        $groups = [
            'GroupPV' => ['caption' => 'PV details (up to 4 strings)', 'vars' => [
                ['pv1_volt', 'PV1 voltage', 'F', 'SLS.Volt',   false, 'pv', 'RO 33049'],
                ['pv1_curr', 'PV1 current',    'F', 'SLS.Ampere', false, 'pv', 'RO 33050'],
                ['pv2_volt', 'PV2 voltage', 'F', 'SLS.Volt',   false, 'pv', 'RO 33051'],
                ['pv2_curr', 'PV2 current',    'F', 'SLS.Ampere', false, 'pv', 'RO 33052'],
                ['pv3_volt', 'PV3 voltage', 'F', 'SLS.Volt',   false, 'pv', 'RO 33053'],
                ['pv3_curr', 'PV3 current',    'F', 'SLS.Ampere', false, 'pv', 'RO 33054'],
                ['pv4_volt', 'PV4 voltage', 'F', 'SLS.Volt',   false, 'pv', 'RO 33055'],
                ['pv4_curr', 'PV4 current',    'F', 'SLS.Ampere', false, 'pv', 'RO 33056'],
                ['mppt1_power', 'MPPT1 power', 'F', 'SLS.Watt', true, 'pv', 'voltage × current (calculated)'],
                ['mppt2_power', 'MPPT2 power', 'F', 'SLS.Watt', true, 'pv', 'voltage × current (calculated)'],
                ['mppt3_power', 'MPPT3 power', 'F', 'SLS.Watt', true, 'pv', 'voltage × current (calculated)'],
                ['mppt4_power', 'MPPT4 power', 'F', 'SLS.Watt', true, 'pv', 'voltage × current (calculated)'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, current, reactive/apparent power per phase)', 'vars' => [
                ['grid_l1_volt', 'Grid L1 voltage', 'F', 'SLS.Volt',   false, 'grid', 'RO 33073'],
                ['grid_l2_volt', 'Grid L2 voltage', 'F', 'SLS.Volt',   false, 'grid', 'RO 33074'],
                ['grid_l3_volt', 'Grid L3 voltage', 'F', 'SLS.Volt',   false, 'grid', 'RO 33075'],
                ['grid_l1_curr', 'Grid L1 current',    'F', 'SLS.Ampere', false, 'grid', 'RO 33076'],
                ['grid_l2_curr', 'Grid L2 current',    'F', 'SLS.Ampere', false, 'grid', 'RO 33077'],
                ['grid_l3_curr', 'Grid L3 current',    'F', 'SLS.Ampere', false, 'grid', 'RO 33078'],
                ['grid_freq',    'Grid frequency',      'F', 'SLS.Hertz', false, 'grid', 'RO 33094'],
            ]],
            'GroupBat' => ['caption' => 'Battery 1 (voltage, current, SOC, power, mode, BMS) and loads', 'vars' => [
                ['bat_volt', 'Bat.1 voltage', 'F', 'SLS.Volt',   false, 'bat1', 'RO 33133'],
                ['bat_curr', 'Bat.1 current (+ discharging / − charging)', 'F', 'SLS.Ampere', false, 'bat1', 'RO 33134 + Richtung RO 33135'],
                ['bat_soh',  'Bat.1 SOH',      'I', '~Intensity.100', true, 'bat1', 'RO 33140'],
                ['bat1_soc',  'Bat.1 SOC',     'I', '~Battery.100', true, 'bat1', 'RO 33139'],
                ['bat1_pwr',  'Bat.1 power (+ discharging / − charging)', 'F', 'SLS.Watt', true, 'bat1', 'RO 33149-33150 + Richtung RO 33135'],
                ['bat1_mode', 'Bat.1 mode',   'I', 'SLS.BatMode', true, 'bat1', 'calc'],
                ['bat1_temp', 'Bat.1 temperature', 'F', '~Temperature', false, 'bat1', 'RO 33043 (÷10)'],
                ['bat1_bms_volt', 'Bat.1 voltage (BMS)', 'F', 'SLS.Volt', false, 'bat1', 'RO 33141 (÷100)'],
                ['bat1_bms_curr', 'Bat.1 current (BMS)', 'F', 'SLS.Ampere', false, 'bat1', 'RO 33142 (÷10)'],
                ['bat1_chg_max_a', 'Bat.1 max. charge current',   'F', 'SLS.Ampere', false, 'bat1', 'RO 33143 (÷10)'],
                ['bat1_dis_max_a', 'Bat.1 max. discharge current', 'F', 'SLS.Ampere', false, 'bat1', 'RO 33144 (÷10)'],
                ['household_load', 'Load at grid connection', 'F', 'SLS.Watt', true, 'grid', 'RO 33147'],
                ['backup_load',    'Load at backup output', 'F', 'SLS.Watt', true, 'backup', 'RO 33148'],
                ['house_load_total', 'Total load (grid + backup)', 'F', 'SLS.Watt', true, 'grid', 'calc 33147 + 33148'],
            ]],
            'GroupBat2' => ['caption' => 'Battery 2 (only with two independent batteries, preview)', 'vars' => [
                ['bat2_volt', 'Bat.2 voltage', 'F', 'SLS.Volt',   false, 'bat2', 'RO 34289 (÷10)'],
                ['bat2_curr', 'Bat.2 current (+ discharging / − charging)', 'F', 'SLS.Ampere', false, 'bat2', 'RO 34290 + Richtung RO 34291'],
                ['bat2_pwr',  'Bat.2 power (+ discharging / − charging, calculated)', 'F', 'SLS.Watt', true, 'bat2', 'voltage × current'],
                ['bat2_mode', 'Bat.2 mode',   'I', 'SLS.BatMode', true, 'bat2', 'calc'],
                ['bat2_soc',  'Bat.2 SOC',     'I', '~Battery.100', true, 'bat2', 'RO 34278'],
                ['bat2_soh',  'Bat.2 SOH',     'I', '~Intensity.100', true, 'bat2', 'RO 34279'],
                ['bat2_temp', 'Bat.2 temperature', 'F', '~Temperature', false, 'bat2', 'RO 34277 (÷10)'],
                ['bat2_bms_volt', 'Bat.2 voltage (BMS)', 'F', 'SLS.Volt', false, 'bat2', 'RO 34275 (÷100)'],
                ['bat2_bms_curr', 'Bat.2 current (BMS)', 'F', 'SLS.Ampere', false, 'bat2', 'RO 34276 (÷10)'],
                ['bat2_chg_max_a', 'Bat.2 max. charge current',   'F', 'SLS.Ampere', false, 'bat2', 'RO 34281 (÷10)'],
                ['bat2_dis_max_a', 'Bat.2 max. discharge current', 'F', 'SLS.Ampere', false, 'bat2', 'RO 34282 (÷10)'],
            ]],
            'GroupControl' => ['caption' => 'Control (forced charging/discharging, storage mode, preview)', 'vars' => [
                ['ctl_force_mode',   'Forced charge/discharge', 'I', 'SLS.ForceMode',   false, 'control', 'RW 43135'],
                ['ctl_force_w',      'Forced power (W)',    'I', 'SLS.WattInt',     false, 'control', 'RW 43136 (charging) / 43129 (discharging), x10 W'],
                ['ctl_storage_mode', 'Storage mode',       'I', 'SLS.StorageMode', false, 'control', 'RW 43110 (selection only)'],
                ['svc_grid_charge_w', 'Grid-supportive: grid charging (W)', 'I', 'SLS.WattInt', false, 'control', 'Forced charging 43136/43135 = 1; 0 = release; caller rewrites every <= 60 s (time limit approx. 182 s)'],
                ['svc_discharge_to_grid_w', 'Grid-supportive: discharge to setpoint (W)', 'I', 'SLS.WattInt', false, 'control', 'Zwangsentladen 43129/43135 = 2; 0 = Freigabe; speist ein, wenn Vorgabe > Hausbedarf'],
                ['svc_release', 'Grid-supportive: release (automatic)', 'B', '~Switch', false, 'control', 'Forced mode 43135 = 0 and "hold battery" back'],
                ['svc_discharge_inhibit', 'Grid-supportive: block discharging (hold battery)', 'B', '~Switch', false, 'control', 'writes 43110 = 17 (reserve without grid charging), remembers the previous value; off = back'],
                ['svc_prev_storage', 'Remembered storage mode (before "hold battery")', 'I', '', false, 'control', 'display only'],
                ['svc_prev_grid_storage', 'Remembered storage mode (before grid charging)', 'I', '', false, 'control', 'display only; svc_grid_charge_w writes 43110 with bit 5 (grid charging allowed), svc_release restores'],
                ['ctl_max_charge_a', 'Max. charge current (A)',    'F', 'SLS.AmpereSet',   false, 'control', 'RW 43012 (÷10), fest gespeichert'],
                ['ctl_max_disch_a',  'Max. discharge current (A)', 'F', 'SLS.AmpereSet',   false, 'control', 'RW 43013 (÷10), fest gespeichert'],
                // Remote Dispatch (Solis Smart Control V1.0, Register 44100-44110, Fähigkeit 34502). Die Vorgaben werden nur
                // gemerkt; erst das Schreiben von ctl_dispatch_mode sendet alles ans Gerät (und muss vor Ablauf des
                // Totmanns wiederholt werden). Siehe IHUB_SolisDispatch.
                ['ctl_dispatch_mode', 'Dispatch: mode (writing sends everything)', 'I', 'SLS.DispatchMode', false, 'control', 'RW 44100/44105: 0 off, 1 standby, 2 battery, 3 grid connection point, 4 AC grid port. Set the other dispatch values first, the mode last; write again before the failsafe time runs out'],
                ['ctl_dispatch_w', 'Dispatch: power (W)', 'I', 'SLS.WattSigned', false, 'control', 'RW 44106-44107 (S32, 10 W). Mode 2: + charging / − discharging; modes 3 and 4: − import / + feed-in. Only stored until the mode is written'],
                ['ctl_dispatch_grid_charge', 'Dispatch: grid charging', 'I', 'SLS.GridChargeSwitch', false, 'control', 'RW 44108 bits 4-5: 0 unchanged, 1 allowed, 2 not allowed'],
                ['ctl_dispatch_pv_shutdown', 'Dispatch: PV shutdown', 'I', 'SLS.PvShutdown', false, 'control', 'RW 44108 bits 0-1: 0 unchanged, 1 off, 2 on (for negative prices)'],
                ['ctl_dispatch_soc_min', 'Dispatch: SOC lower limit (%)', 'I', '~Intensity.100', false, 'control', 'RW 44109'],
                ['ctl_dispatch_soc_max', 'Dispatch: SOC upper limit (%, 0 = 100)', 'I', '~Intensity.100', false, 'control', 'RW 44110'],
                ['ctl_dispatch_failsafe_min', 'Dispatch: failsafe time (min, 0 = 5)', 'I', 'SLS.Minutes', false, 'control', 'RW 44101 (1-1440): without a new command the device leaves the dispatch after this time'],
                ['ctl_dispatch_import_limit_w', 'Dispatch: import limit (W, 0 = none)', 'I', 'SLS.WattInt', false, 'control', 'RW 44102 bit 0 + 44103 (100 W)'],
                ['ctl_dispatch_export_limit_w', 'Dispatch: feed-in limit (W, 0 = none)', 'I', 'SLS.WattInt', false, 'control', 'RW 44102 bit 1 + 44104 (100 W)'],
                ['dispatch_available', 'Dispatch: device offers remote dispatch', 'B', '~Switch', false, 'device', 'RO 34502 = 0xAA55'],
                ['dispatch_version', 'Dispatch: register version', 'I', '', false, 'device', 'RO 34503 (1 = V01), 0 without dispatch'],
                ['dispatch_status', 'Dispatch: status', 'I', 'SLS.DispatchStatus', false, 'device', 'RO 34504'],
            ]],
            'GroupSettings' => ['caption' => 'Inverter settings (read only, preview)', 'vars' => [
                ['cfg_storage_mode',     'Storage mode (setting)',        'I', 'SLS.StorageMode', false, 'settings', 'HR 43110'],
                ['cfg_force_mode',       'Forced charge/discharge (setting)',  'I', 'SLS.ForceMode',   false, 'settings', 'HR 43135'],
                ['cfg_force_charge_w',   'Forced charge power',                  'F', 'SLS.Watt',        false, 'settings', 'HR 43136 (x10 W)'],
                ['cfg_force_disch_w',    'Forced discharge power',               'F', 'SLS.Watt',        false, 'settings', 'HR 43129 (x10 W)'],
                ['cfg_charge_limit_w',   'Charge power limit',              'F', 'SLS.Watt',        false, 'settings', 'HR 43130 (x10 W)'],
                ['cfg_disch_limit_w',    'Discharge power limit',           'F', 'SLS.Watt',        false, 'settings', 'HR 43131 (x10 W)'],
                ['cfg_grid_adjust',      'Grid balancing (0 off, 1 system, 2 inverter)', 'I', '',                false, 'settings', 'HR 43132'],
                ['cfg_soc_max',          'SOC charge limit (overcharge)',          'I', '~Intensity.100',  false, 'settings', 'HR 43010'],
                ['cfg_soc_min',          'SOC discharge limit (overdischarge)',    'I', '~Intensity.100',  false, 'settings', 'HR 43011'],
                ['cfg_soc_forcecharge',  'SOC forced charge',                     'I', '~Intensity.100',  false, 'settings', 'HR 43018'],
                ['cfg_soc_backup',       'SOC backup reserve',                   'I', '~Intensity.100',  false, 'settings', 'HR 43024'],
                ['cfg_max_charge_a',     'Max. charge current',                       'F', 'SLS.Ampere',      false, 'settings', 'HR 43012 (÷10)'],
                ['cfg_max_disch_a',      'Max. discharge current',                    'F', 'SLS.Ampere',      false, 'settings', 'HR 43013 (÷10)'],
                ['cfg_rc_timeout_min',   'Remote control timeout (minutes)',       'I', '',                false, 'settings', 'HR 43282'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (voltage, current per phase)', 'vars' => [
                ['mt_l1_volt', 'Meter L1 voltage', 'F', 'SLS.Volt',   false, 'meter', 'RO 33251'],
                ['mt_l2_volt', 'Meter L2 voltage', 'F', 'SLS.Volt',   false, 'meter', 'RO 33253'],
                ['mt_l3_volt', 'Meter L3 voltage', 'F', 'SLS.Volt',   false, 'meter', 'RO 33255'],
                ['mt_l1_curr', 'Meter L1 current', 'F', 'SLS.Ampere', false, 'meter', 'RO 33252 (÷100)'],
                ['mt_l2_curr', 'Meter L2 current', 'F', 'SLS.Ampere', false, 'meter', 'RO 33254 (÷100)'],
                ['mt_l3_curr', 'Meter L3 current', 'F', 'SLS.Ampere', false, 'meter', 'RO 33256 (÷100)'],
                ['mt_l1_pwr', 'Meter L1 power (+ feed-in / − import)', 'F', 'SLS.Watt', true, 'meter', 'RO 33257-33258'],
                ['mt_l2_pwr', 'Meter L2 power (+ feed-in / − import)', 'F', 'SLS.Watt', true, 'meter', 'RO 33259-33260'],
                ['mt_l3_pwr', 'Meter L3 power (+ feed-in / − import)', 'F', 'SLS.Watt', true, 'meter', 'RO 33261-33262'],
                ['mt_total_pwr', 'Meter total power (+ feed-in / − import)', 'F', 'SLS.Watt', true, 'meter', 'RO 33263-33264'],
            ]],
            'GroupBackup' => ['caption' => 'Backup / island (voltage, current per phase)', 'vars' => [
                ['backup_l1_volt', 'Backup voltage L1', 'F', 'SLS.Volt',   false, 'backup', 'RO 33137'],
                ['backup_l1_curr', 'Backup current L1',    'F', 'SLS.Ampere', false, 'backup', 'RO 33138'],
                ['backup_l2_volt', 'Backup voltage L2', 'F', 'SLS.Volt',   false, 'backup', 'RO 33153'],
                ['backup_l2_curr', 'Backup current L2',    'F', 'SLS.Ampere', false, 'backup', 'RO 33154'],
                ['backup_l3_volt', 'Backup voltage L3', 'F', 'SLS.Volt',   false, 'backup', 'RO 33155'],
                ['backup_l3_curr', 'Backup current L3',    'F', 'SLS.Ampere', false, 'backup', 'RO 33156'],
            ]],
            'GroupStatus' => ['caption' => 'Status and errors (operating status, BMS, error codes)', 'vars' => [
                ['op_status',   'Operating status',        'I', 'SLS.OpStatus',  true,  'device', 'RO 33287'],
                ['bms_status',  'BMS status',            'I', 'SLS.BmsStatus', true,  'errors', 'RO 33111'],
                ['fault_code',  'Error code',            'I', '',              true,  'errors', 'RO 33116-33120, 33124-33125 (erster)'],
                ['fault_text',  'Error detail',         'S', '',              false, 'errors', 'RO 33116-33120, 33124-33125 (alle)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp_inv', 'Inverter temperature', 'F', '~Temperature', false, 'device', 'RO 33093'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (PV, battery, grid, consumption)', 'vars' => [
                ['e_pv_day',       'PV today',            'F', '~Electricity', true, 'energy', 'RO 33035'],
                ['e_pv_total',     'PV total',           'F', '~Electricity', true, 'energy', 'RO 33029-33030 (U32, kWh)'],
                ['e_charge_day',   'Bat. charge today',    'F', '~Electricity', true, 'energy', 'RO 33163'],
                ['e_charge_total', 'Bat. charge total',   'F', '~Electricity', true, 'energy', 'RO 33161-33162 (U32, kWh)'],
                ['e_disch_day',    'Bat. discharge today',    'F', '~Electricity', true, 'energy', 'RO 33167'],
                ['e_disch_total',  'Bat. discharge total',   'F', '~Electricity', true, 'energy', 'RO 33165-33166 (U32, kWh)'],
                ['e_buy_day',      'Import today',         'F', '~Electricity', true, 'energy', 'RO 33171'],
                ['e_buy_total',    'Import total',        'F', '~Electricity', true, 'energy', 'RO 33169-33170 (U32, kWh)'],
                ['e_sell_day',     'Feed-in today',   'F', '~Electricity', true, 'energy', 'RO 33175'],
                ['e_sell_total',   'Feed-in total',  'F', '~Electricity', true, 'energy', 'RO 33173-33174 (U32, kWh)'],
                ['e_load_day',     'Load today (grid + backup)', 'F', '~Electricity', true, 'energy', 'RO 33179'],
                ['e_load_total',   'Load total (grid + backup)', 'F', '~Electricity', true, 'energy', 'RO 33177-33178 (U32, kWh)'],
            ]],
            'GroupDevice' => ['caption' => 'Device information', 'vars' => [
                ['dev_model', 'Model no.', 'I', '', false, 'device', 'RO 33000'],
                ['dev_sn',    'Serial number', 'S', '', false, 'device', 'RO 33004-33019'],
                ['dev_fw_dsp', 'Firmware DSP', 'I', '', false, 'device', 'RO 33001'],
                ['dev_fw_hmi', 'Firmware HMI', 'I', '', false, 'device', 'RO 33002'],
            ]],
        ];
        // Gemeinsame Fernsteuerung (ctl_rc_*, rc_*) neben dem alten Weg ctl_dispatch_* (seit 0.92.0)
        $groups['GroupControl']['vars'] = array_merge($groups['GroupControl']['vars'], IHUB_RemoteControl::vars());
        return $groups;
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLS.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLS.WattInt' => [VARIABLETYPE_INTEGER, ' W',        0,   30000,  10,  0],
            'SLS.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLS.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'SLS.AmpereSet' => [VARIABLETYPE_FLOAT, ' A',     0.0,   200.0, 0.1,  1],
            'SLS.WattSigned' => [VARIABLETYPE_INTEGER, ' W', -40000, 40000, 10, 0],
            'SLS.Minutes' => [VARIABLETYPE_INTEGER, ' min',      0,   1440,  1,  0],
            'SLS.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ] + IHUB_RemoteControl::profiles();
    }

    // Speicherbetrieb laut Register 43110 (Bitfeld, Werte aus dem wills106-Plugin und der Solis-Doku).
    const STORAGE_MODES = [
        1 => 'Self-consumption, no grid charging', 3 => 'Time charging, no grid charging', 17 => 'Backup/reserve, no grid charging',
        33 => 'Self-consumption, without time charging', 35 => 'Own consumption', 37 => 'Island mode', 41 => 'Wake battery',
        43 => 'Wake battery + time charging', 49 => 'Backup/reserve, without time charging', 51 => 'Backup/reserve',
        64 => 'Feed-in priority, no grid charging', 96 => 'Feed-in priority, without time charging', 98 => 'Feed-in priority',
    ];

    public function getEnumProfiles()
    {
        $modes = [];
        foreach (self::STORAGE_MODES as $k => $label) {
            $modes[$k] = [$label, 0x7A8A99];
        }
        return [
            'SLS.StorageMode' => $modes,
            'SLS.ForceMode'   => [0 => ['Off', 0x7A8A99], 1 => ['Charge', 0x3BA55D], 2 => ['Discharge', 0xE08A2B]],
            'SLS.DispatchMode'   => [0 => ['Off', 0x7A8A99], 1 => ['Standby', 0xE08A2B], 2 => ['Battery', 0x3BA55D], 3 => ['Grid connection point', 0x3BA55D], 4 => ['AC grid port', 0x3BA55D]],
            'SLS.DispatchStatus' => [0 => ['Not running', 0x7A8A99], 1 => ['Standby', 0xE08A2B], 2 => ['Real-time control', 0x3BA55D], 3 => ['Time-of-use control', 0x3BA55D]],
            'SLS.GridChargeSwitch' => [0 => ['Unchanged', 0x7A8A99], 1 => ['Allowed', 0x3BA55D], 2 => ['Not allowed', 0xE08A2B]],
            'SLS.PvShutdown'  => [0 => ['Unchanged', 0x7A8A99], 1 => ['PV on', 0x3BA55D], 2 => ['PV shut down', 0xE08A2B]],
            'SLS.BatMode'     => [0 => ['Standby', 0x7A8A99], 1 => ['Charge', 0x3BA55D], 2 => ['Discharge', 0xE08A2B]],
            'SLS.GridMode'    => [0 => ['Balanced', 0x7A8A99], 1 => ['Feed-in', 0x3BA55D], 2 => ['Import', 0xE08A2B]],
            'SLS.OpStatus'    => [0 => ['Stop', 0x7A8A99], 1 => ['Idle', 0x7A8A99], 2 => ['Soft start', 0xE08A2B], 3 => ['Grid operation', 0x3BA55D],
                                  4 => ['Island mode', 0xE08A2B], 5 => ['Transition island → grid', 0xE08A2B], 6 => ['Backup bypass', 0xE08A2B], 7 => ['Generator operation', 0xE08A2B]],
            'SLS.BmsStatus'   => [0 => ['Normal', 0x3BA55D], 1 => ['Communication disturbed', 0xC0392B], 2 => ['Warning', 0xE08A2B]],
        ] + IHUB_RemoteControl::enumProfiles();
    }

    // Eine wiederverwendete Verbindung fuer den ganzen Lesezyklus statt vier bis fuenf frisch geoeffneter
    // (15.11.2026, Pauls Solis: der Datenlogger lehnt gleichzeitige und schnell aufeinanderfolgende
    // Verbindungen ab).
    public function readFast($mb, $hub)
    {
        $mb->beginBatch();
        try {
            return $this->readFastInner($mb, $hub);
        } finally {
            $mb->endBatch();
        }
    }

    private function readFastInner($mb, $hub)
    {
        $pv     = $mb->readInput(33049, 12);   // 33049-33060 (PV1-4 V/I + Total DC-Bereich)
        $ac     = $mb->readInput(33079, 6);    // 33079-33084 Wirk/Blind/Scheinleistung
        $bat    = $mb->readInput(33132, 20);   // 33132-33151 Batterie + Household/Backup Load
        $meter  = $mb->readInput(33151, 3);    // 33151-33152 (+33153 Reserve) Grid Port Power

        $ok = ($pv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', (float)$mb->u32($pv, 8));  // 33057-33058
        if ($ac !== null) {
            $hub->SetVarFloat('ac_power', (float)$mb->s32($ac, 0));
        }
        $bat2 = null;
        $power1 = 0.0;
        $soc1 = 0;
        if ($bat !== null) {
            $soc1 = $mb->u16($bat, 7);                                     // 33139
            // Vorzeichen (Abgleich mit Solis-Portal, 17.10.2026): Der Rohwert in 33149/33150
            // ist bei dieser Firmware vorzeichenbehaftet, POSITIV = Laden (evcc liest ihn mit
            // Faktor -1 als "positiv = Entladen"). Bei Firmware mit vorzeichenlosem Betrag
            // (Home-Assistant-Integration Pho3niX90/solis_modbus) gibt das Richtungsregister
            // 33135 die Richtung vor (0 = Laden, 1 = Entladen). Beides zusammen:
            // negativer Rohwert = Entladen, positiver Rohwert = Entladen nur bei 33135 = 1.
            // InverterHub-Konvention: + = Entladen, - = Laden.
            $rawBat = $mb->s32($bat, 17);
            $this->batDischarging = ($rawBat < 0) || ($mb->u16($bat, 3) === 1);   // 33135
            $power1 = (float)($this->batDischarging ? abs($rawBat) : -abs($rawBat));
            // Zwei unabhaengige Akkus: Gesamtleistung = Akku 1 + Akku 2, SOC = Mittelwert
            // (nur wenn die Gruppe "Batterie 2" aktiv und das Funktionsbit gesetzt ist).
            $bat2 = $hub->GetPropBool('GroupBat2') ? $this->readBattery2($mb) : null;
            $hub->SetVarFloat('bat_power', $power1 + ($bat2 !== null ? $bat2['power'] : 0.0));
            $hub->SetVarInt('bat_soc', $bat2 !== null ? (int)round(($soc1 + $bat2['soc']) / 2) : $soc1);
            // Maximale Lade-/Entladeleistung aus dem BMS-Grenzstrom (wie bei GoodWe "BMS calc").
            // Je Batterieeingang gilt zusaetzlich die Spannungskennlinie des Wechselrichters
            // (Handbuch Kap. 3.13): das Minimum aus BMS-Grenze und Kennlinie.
            $v1   = $mb->u16($bat, 1) / 10.0;                              // 33133
            $lim1 = self::wrCurrentLimit($v1);
            $chgW = min($mb->u16($bat, 11) / 10.0, $lim1) * $v1;           // 33143
            $disW = min($mb->u16($bat, 12) / 10.0, $lim1) * $v1;           // 33144
            if ($bat2 !== null) {
                $lim2 = self::wrCurrentLimit($bat2['volt']);
                $chgW += min($bat2['chg_max'], $lim2) * $bat2['volt'];
                $disW += min($bat2['dis_max'], $lim2) * $bat2['volt'];
            }
            $hub->SetVarFloat('bat_charge_max_w', round($chgW));
            $hub->SetVarFloat('bat_discharge_max_w', round($disW));
        }
        if ($meter !== null) {
            $gridW = (float)$mb->s32($meter, 0);
            $hub->SetVarFloat('meter_total', $gridW);
            // Netzmodus aus dem Vorzeichen: + Einspeisung, - Bezug (Toleranz 50 W).
            $hub->SetVarInt('grid_mode', $gridW > 50 ? 1 : ($gridW < -50 ? 2 : 0));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt', $mb->u16($pv, 0) / 10.0);
            $hub->SetVarFloat('pv1_curr', $mb->u16($pv, 1) / 10.0);
            $hub->SetVarFloat('pv2_volt', $mb->u16($pv, 2) / 10.0);
            $hub->SetVarFloat('pv2_curr', $mb->u16($pv, 3) / 10.0);
            $hub->SetVarFloat('pv3_volt', $mb->u16($pv, 4) / 10.0);
            $hub->SetVarFloat('pv3_curr', $mb->u16($pv, 5) / 10.0);
            $hub->SetVarFloat('pv4_volt', $mb->u16($pv, 6) / 10.0);
            $hub->SetVarFloat('pv4_curr', $mb->u16($pv, 7) / 10.0);
            // Leistung je MPPT: das Geraet liefert nur Spannung und Strom je Eingang, keine Leistung.
            for ($i = 0; $i < 4; $i++) {
                $hub->SetVarFloat('mppt' . ($i + 1) . '_power', (float)round(($mb->u16($pv, $i * 2) / 10.0) * ($mb->u16($pv, $i * 2 + 1) / 10.0)));
            }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $grid = $mb->readInput(33073, 24); // 33073-33096
            if ($grid !== null) {
                $hub->SetVarFloat('grid_l1_volt', $mb->u16($grid, 0) / 10.0);
                $hub->SetVarFloat('grid_l2_volt', $mb->u16($grid, 1) / 10.0);
                $hub->SetVarFloat('grid_l3_volt', $mb->u16($grid, 2) / 10.0);
                $hub->SetVarFloat('grid_l1_curr', $mb->u16($grid, 3) / 10.0);
                $hub->SetVarFloat('grid_l2_curr', $mb->u16($grid, 4) / 10.0);
                $hub->SetVarFloat('grid_l3_curr', $mb->u16($grid, 5) / 10.0);
                $hub->SetVarFloat('grid_freq', $mb->u16($grid, 21) / 100.0);
                if ($hub->GetPropBool('GroupTemp')) {
                    $hub->SetVarFloat('temp_inv', $mb->s16($grid, 20) / 10.0);
                }
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $signed = function ($v) { return $this->batDischarging ? abs($v) : -abs($v); };
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 1) / 10.0);       // 33133
            $hub->SetVarFloat('bat_curr', $signed($mb->s16($bat, 2) / 10.0)); // 33134
            $hub->SetVarInt('bat_soh',    $mb->u16($bat, 8));              // 33140
            $hub->SetVarInt('bat1_soc',   $soc1);
            $hub->SetVarFloat('bat1_pwr', $power1);
            $hub->SetVarInt('bat1_mode',  self::batMode($power1));
            $hub->SetVarFloat('bat1_bms_volt', $mb->u16($bat, 9) / 100.0);          // 33141
            $hub->SetVarFloat('bat1_bms_curr', $signed($mb->s16($bat, 10) / 10.0)); // 33142
            $hub->SetVarFloat('bat1_chg_max_a', $mb->u16($bat, 11) / 10.0);         // 33143
            $hub->SetVarFloat('bat1_dis_max_a', $mb->u16($bat, 12) / 10.0);         // 33144
            $hub->SetVarFloat('household_load', (float)$mb->u16($bat, 15)); // 33147
            $hub->SetVarFloat('backup_load',    (float)$mb->u16($bat, 16)); // 33148
            // Gesamtverbrauch: Solis versorgt das Haus teils ueber den Netzanschluss, teils ueber den Backup-Ausgang.
            $hub->SetVarFloat('house_load_total', (float)($mb->u16($bat, 15) + $mb->u16($bat, 16)));
        }

        // Zweiter Akku (siehe readBattery2()): Einzelwerte; Summe und Mittelwert stecken in bat_power/bat_soc.
        if ($bat2 !== null) {
            $hub->SetVarFloat('bat2_volt', $bat2['volt']);
            $hub->SetVarFloat('bat2_curr', $bat2['curr']);
            $hub->SetVarFloat('bat2_pwr', $bat2['power']);
            $hub->SetVarInt('bat2_mode', self::batMode($bat2['power']));
            $hub->SetVarInt('bat2_soc', $bat2['soc']);
            $hub->SetVarInt('bat2_soh', $bat2['soh']);
            $hub->SetVarFloat('bat2_temp', $bat2['temp']);
            $hub->SetVarFloat('bat2_bms_volt', $bat2['bms_volt']);
            $hub->SetVarFloat('bat2_bms_curr', $bat2['bms_curr']);
            $hub->SetVarFloat('bat2_chg_max_a', $bat2['chg_max']);
            $hub->SetVarFloat('bat2_dis_max_a', $bat2['dis_max']);
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $mtV = $mb->readInput(33251, 14); // 33251-33264
            if ($mtV !== null) {
                $hub->SetVarFloat('mt_l1_volt', $mb->u16($mtV, 0) / 10.0);
                $hub->SetVarFloat('mt_l2_volt', $mb->u16($mtV, 2) / 10.0);
                $hub->SetVarFloat('mt_l3_volt', $mb->u16($mtV, 4) / 10.0);
                $hub->SetVarFloat('mt_l1_curr', $mb->u16($mtV, 1) / 100.0);   // 33252
                $hub->SetVarFloat('mt_l2_curr', $mb->u16($mtV, 3) / 100.0);   // 33254
                $hub->SetVarFloat('mt_l3_curr', $mb->u16($mtV, 5) / 100.0);   // 33256
                $hub->SetVarFloat('mt_l1_pwr', (float)$mb->s32($mtV, 6));     // 33257-33258
                $hub->SetVarFloat('mt_l2_pwr', (float)$mb->s32($mtV, 8));     // 33259-33260
                $hub->SetVarFloat('mt_l3_pwr', (float)$mb->s32($mtV, 10));    // 33261-33262
                $hub->SetVarFloat('mt_total_pwr', (float)$mb->s32($mtV, 12));
            }
        }

        // Backup / Insel: Spannung und Strom je Phase (33137/33138, 33153-33156).
        if ($hub->GetPropBool('GroupBackup')) {
            $bk = $mb->readInput(33137, 20);   // 33137-33156
            if ($bk !== null) {
                $hub->SetVarFloat('backup_l1_volt', $mb->u16($bk, 0) / 10.0);    // 33137
                $hub->SetVarFloat('backup_l1_curr', $mb->u16($bk, 1) / 10.0);    // 33138
                $hub->SetVarFloat('backup_l2_volt', $mb->u16($bk, 16) / 10.0);   // 33153
                $hub->SetVarFloat('backup_l2_curr', $mb->u16($bk, 17) / 10.0);   // 33154
                $hub->SetVarFloat('backup_l3_volt', $mb->u16($bk, 18) / 10.0);   // 33155
                $hub->SetVarFloat('backup_l3_curr', $mb->u16($bk, 19) / 10.0);   // 33156
            }
        }

        // Status und Fehler: Betriebsstatus 33287 (laut Solis-Doku, Tabelle "Operating Status"), BMS-Status 33111,
        // Fehlercodes 01-05 (33116-33120) und 06-07 (33124-33125).
        if ($hub->GetPropBool('GroupStatus')) {
            $op = $mb->readInput(33287, 1);
            if ($op !== null) {
                $hub->SetVarInt('op_status', $mb->u16($op, 0));
            }
            $st = $mb->readInput(33111, 15);   // 33111-33125
            if ($st !== null) {
                $hub->SetVarInt('bms_status', $mb->u16($st, 0));
                $codes = [];
                foreach ([5, 6, 7, 8, 9, 13, 14] as $i) {   // 33116-33120, 33124-33125
                    $c = $mb->u16($st, $i);
                    if ($c !== 0) {
                        $codes[] = $c;
                    }
                }
                $hub->SetVarInt('fault_code', count($codes) > 0 ? $codes[0] : 0);
                $hub->SetVarStr('fault_text', count($codes) > 0
                    ? implode(', ', array_map(function ($c) { return sprintf('%04X', $c); }, $codes))
                    : 'OK');
            }
        }

        return true;
    }

    // Zweiter Akku (Registerabzug 18.10.2026, bestaetigt durch glenmo/microgrid_remote_monitor):
    // Funktionsregister 33097 Bit 6 = "Batterien unabhaengig" (0 = parallel). Dann liegen die Werte
    // von Akku 2 in 34278 (SOC) und 34289-34291 (Spannung 0,1 V, Strom 0,1 A, Richtung 1 = Entladen).
    // Ein eigenes Leistungsregister gibt es nicht - die Leistung ist Spannung x Strom.
    private function readBattery2($mb)
    {
        $func = $mb->readInput(33097, 1);
        if ($func === null || ($mb->u16($func, 0) & 0x40) === 0) {
            return null;
        }
        $b2 = $mb->readInput(34275, 17);   // 34275-34291
        if ($b2 === null) {
            return null;
        }
        $volt  = $mb->u16($b2, 14) / 10.0;                // 34289
        $curr  = abs($mb->s16($b2, 15)) / 10.0;           // 34290 (Betrag)
        $disch = ($mb->u16($b2, 16) === 1);               // 34291
        $signed = $disch ? $curr : -$curr;
        $bmsCurr = abs($mb->s16($b2, 1)) / 10.0;          // 34276 (Betrag)
        return [
            'volt'     => $volt,
            'curr'     => $signed,
            'soc'      => $mb->u16($b2, 3),               // 34278
            'soh'      => $mb->u16($b2, 4),               // 34279
            'temp'     => $mb->s16($b2, 2) / 10.0,        // 34277
            'bms_volt' => $mb->u16($b2, 0) / 100.0,       // 34275
            'bms_curr' => $disch ? $bmsCurr : -$bmsCurr,
            'chg_max'  => $mb->u16($b2, 6) / 10.0,        // 34281
            'dis_max'  => $mb->u16($b2, 7) / 10.0,        // 34282
            'power'    => (float)round($volt * $signed),
        ];
    }

    // Maximaler Batteriestrom je Eingang in Abhaengigkeit der Batteriespannung (S6-EH3P(29.9-50)K-H,
    // Handbuch Kap. 3.13, Tabelle "Batteriespannung / -strom / -leistung"), linear dazwischen.
    const WR_CURRENT_CURVE = [[150.0, 30.0], [310.0, 56.0], [320.0, 70.0], [500.0, 70.0], [800.0, 44.0]];

    private static function wrCurrentLimit($volt)
    {
        $c = self::WR_CURRENT_CURVE;
        if ($volt <= $c[0][0]) {
            return $c[0][1];
        }
        for ($i = 1; $i < count($c); $i++) {
            if ($volt <= $c[$i][0]) {
                return $c[$i - 1][1] + ($volt - $c[$i - 1][0]) * ($c[$i][1] - $c[$i - 1][1]) / ($c[$i][0] - $c[$i - 1][0]);
            }
        }
        return $c[count($c) - 1][1];
    }

    // Modus eines Akkus aus der (vorzeichenbehafteten) Leistung: + Entladen, - Laden.
    private static function batMode($power)
    {
        if (abs($power) < 10) {
            return 0;
        }
        return $power > 0 ? 2 : 1;
    }

    public function readSlow($mb, $hub)
    {
        $mb->beginBatch();
        try {
            $this->readSlowInner($mb, $hub);
        } finally {
            $mb->endBatch();
        }
    }

    private function readSlowInner($mb, $hub)
    {
        if ($hub->GetPropBool('GroupBat')) {
            $t = $mb->readInput(33043, 1);   // BMS-Batterietemperatur, 0,1 Grad
            if ($t !== null) {
                $hub->SetVarFloat('bat1_temp', $mb->s16($t, 0) / 10.0);
            }
        }
        // Einstellungs-Register (nur lesen, Holding/FC03). Jeder Block wird einzeln und
        // fehlertolerant gelesen - nicht unterstuetzte Register bleiben einfach leer.
        if ($hub->GetPropBool('GroupSettings')) {
            $r = $mb->readHolding(43010, 4);   // 43010-43013
            if ($r !== null) {
                $hub->SetVarInt('cfg_soc_max', $mb->u16($r, 0));
                $hub->SetVarInt('cfg_soc_min', $mb->u16($r, 1));
                $hub->SetVarFloat('cfg_max_charge_a', $mb->u16($r, 2) / 10.0);
                $hub->SetVarFloat('cfg_max_disch_a', $mb->u16($r, 3) / 10.0);
            }
            $r = $mb->readHolding(43018, 1);
            if ($r !== null) {
                $hub->SetVarInt('cfg_soc_forcecharge', $mb->u16($r, 0));
            }
            $r = $mb->readHolding(43024, 1);
            if ($r !== null) {
                $hub->SetVarInt('cfg_soc_backup', $mb->u16($r, 0));
            }
            $r = $mb->readHolding(43110, 1);
            if ($r !== null) {
                $hub->SetVarInt('cfg_storage_mode', $mb->u16($r, 0));
            }
            $r = $mb->readHolding(43129, 8);   // 43129-43136
            if ($r !== null) {
                $hub->SetVarFloat('cfg_force_disch_w', $mb->u16($r, 0) * 10.0);   // 43129
                $hub->SetVarFloat('cfg_charge_limit_w', $mb->u16($r, 1) * 10.0);  // 43130
                $hub->SetVarFloat('cfg_disch_limit_w', $mb->u16($r, 2) * 10.0);   // 43131
                $hub->SetVarInt('cfg_grid_adjust', $mb->u16($r, 3));              // 43132
                $hub->SetVarInt('cfg_force_mode', $mb->u16($r, 6));               // 43135
                $hub->SetVarFloat('cfg_force_charge_w', $mb->u16($r, 7) * 10.0);  // 43136
            }
            $r = $mb->readHolding(43282, 1);
            if ($r !== null) {
                $hub->SetVarInt('cfg_rc_timeout_min', $mb->u16($r, 0));
            }
        }
        if ($hub->GetPropBool('GroupControl')) {
            $d = IHUB_SolisDispatch::probe($mb);   // 34502-34504: Faehigkeit, Version, Status
            if ($d !== null) {
                $hub->SetVarBool('dispatch_available', $d['available']);
                $hub->SetVarInt('dispatch_version', $d['available'] ? $d['version'] : 0);
                $hub->SetVarInt('dispatch_status', $d['available'] ? $d['status'] : 0);
                IHUB_RemoteControl::refresh(new IHUB_RemoteSolis(), $mb, $hub, $d);   // gemeinsame Fernsteuerung, dieselbe Fähigkeit
            }
        }
        if ($hub->GetPropBool('GroupEnergy')) {
            // Gesamtstaende (U32) haben 1 kWh Aufloesung, nur die Tageswerte (U16) 0,1 kWh
            // (evcc: "register has 1 kWh resolution"; openHAB/HA: Faktor 1). Bis 0.77.0-beta.27 fehlerhaft durch 10 geteilt.
            $e1 = $mb->readInput(33029, 12);  // 33029-33040
            if ($e1 !== null) {
                $hub->SetVarFloat('e_pv_total', (float)$mb->u32($e1, 0));
                $hub->SetVarFloat('e_pv_day',   $mb->u16($e1, 6) / 10.0);
            }
            $e2 = $mb->readInput(33161, 20);  // 33161-33180
            if ($e2 !== null) {
                $hub->SetVarFloat('e_charge_total', (float)$mb->u32($e2, 0));
                $hub->SetVarFloat('e_charge_day',   $mb->u16($e2, 2)  / 10.0);
                $hub->SetVarFloat('e_disch_total',  (float)$mb->u32($e2, 4));
                $hub->SetVarFloat('e_disch_day',    $mb->u16($e2, 6)  / 10.0);
                $hub->SetVarFloat('e_buy_total',    (float)$mb->u32($e2, 8));
                $hub->SetVarFloat('e_buy_day',      $mb->u16($e2, 10) / 10.0);
                $hub->SetVarFloat('e_sell_total',   (float)$mb->u32($e2, 12));
                $hub->SetVarFloat('e_sell_day',     $mb->u16($e2, 14) / 10.0);
                $hub->SetVarFloat('e_load_total',   (float)$mb->u32($e2, 16));          // 33177-33178
                $hub->SetVarFloat('e_load_day',     $mb->u16($e2, 18) / 10.0);          // 33179
            }
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $model = $mb->readInput(33000, 1);
        if ($model !== null) {
            $hub->SetVarInt('dev_model', $mb->u16($model, 0));
        }
        $sn = $mb->readInput(33004, 16);
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 16));
        }
        $fw = $mb->readInput(33001, 2);   // 33001 DSP, 33002 HMI
        if ($fw !== null) {
            $hub->SetVarInt('dev_fw_dsp', $mb->u16($fw, 0));
            $hub->SetVarInt('dev_fw_hmi', $mb->u16($fw, 1));
        }
    }

    // Steuerregister (Holding, FC06). Quellen: Home-Assistant-Integration Pho3niX90/solis_modbus,
    // Plugin wills106/homeassistant-solax-modbus, Abschrift der Solis-Doku. NOCH AN KEINER ANLAGE ERPROBT.
    const REG_STORAGE_MODE = 43110;   // Speicherbetrieb (Bitfeld)
    const REG_FORCE_DISCH_W = 43129;  // Zwangs-Entladeleistung, 10 W
    const REG_FORCE_MODE   = 43135;   // 0 aus, 1 Laden, 2 Entladen
    const REG_FORCE_CHG_W  = 43136;   // Zwangs-Ladeleistung, 10 W
    const REG_MAX_CHARGE_A = 43012;   // max. Ladestrom, 0,1 A (fest gespeichert)
    const REG_MAX_DISCH_A  = 43013;   // max. Entladestrom, 0,1 A (fest gespeichert)
    const CURRENT_MAX_A    = 200.0;
    const STORAGE_BIT_GRID = 32;      // Bit 5 von 43110: Netzladen erlaubt (ohne dieses Bit setzt der WR Zwangsladen nur aus PV um, gemessen 25.10.2026)
    const STORAGE_HOLD     = 17;      // Reserve ohne Netzladen: Akku wird nicht entladen, kein Netzladen (gemessen 20.10.2026)
    const FORCE_MAX_W      = 30000;   // Obergrenze der Vorgabe; der WR begrenzt selbst (z. B. 2 x 70 A beim S6-EH3P29.9K-H)
    // Erlaubte Speicherbetriebsarten: bewusst OHNE Inselbetrieb (37) und Batterie-Wecken (41/43).
    const STORAGE_MODES_ALLOWED = [1, 3, 17, 33, 35, 49, 51, 64, 96, 98];

    // Schreibt 43110, liest zurueck und schreibt bei Abweichung einmal neu (ein Schreibbefehl kam an der Anlage
    // nicht immer an). true = Geraet hat den Wert.
    // Schreibt ein Register, liest es nach 0,4 s zurueck und schreibt bei Abweichung einmal neu (das Geraet
    // bestaetigte Schreibbefehle gelegentlich, ohne den Wert zu uebernehmen). true = Geraet hat den Wert.
    // $err bekommt die Antwort des Geraets (Ausnahmecode usw.) fuer die Warnung.
    private function writeVerified($mb, $reg, $value, &$err)
    {
        $err = '';
        for ($try = 0; $try < 2; $try++) {
            if ($try > 0) {
                usleep(1500000);   // Pause vor dem zweiten Versuch (Datenlogger mit nur einer Verbindung)
            }
            if (!$mb->writeSingle($reg, $value)) {
                $err = isset($mb->lastError) && $mb->lastError !== '' ? $mb->lastError : IHUB_Text::t('write failed');
                continue;
            }
            usleep(400000);
            $r = $mb->readHolding($reg, 1);
            if ($r !== null && $mb->u16($r, 0) === ($value & 0xFFFF)) {
                $err = '';
                return true;
            }
            $err = ($r === null) ? IHUB_Text::t('read-back without answer') : IHUB_Text::t('device acknowledged, but stored %s instead of %s', $mb->u16($r, 0), $value);
        }
        return false;
    }

    // Zielwert des Speicherbetriebs mit "Netzladen erlaubt" (Bit 5). Die Mischformen 49/51 (Reserve MIT Netzladen)
    // laden von selbst mit voller Leistung aus dem Netz und werden deshalb nie gewaehlt; dafuer gilt 33.
    private function gridStorageTarget($cur)
    {
        $t = $cur | self::STORAGE_BIT_GRID;
        return (in_array($t, self::STORAGE_MODES_ALLOWED, true) && $t !== 49 && $t !== 51) ? $t : 33;
    }

    // Vor dem Netz-Zwangsladen: Ist Netzladen im Speicherbetrieb erlaubt? Sonst setzt der Solis das Zwangsladen
    // nur aus PV um und der Akku entlaedt sogar fuer den Hausbedarf (Paul, 25.10.2026: 17 kW erst nach 43110 = 33).
    // true = Zwangsladen darf geschrieben werden. Der bisherige Wert wird in svc_prev_grid_storage gemerkt
    // (nur wenn dort noch keiner steht) und von svc_release zurueckgeschrieben.
    private function ensureGridChargeAllowed($mb, $hub)
    {
        $r = $mb->readHolding(self::REG_STORAGE_MODE, 1);
        if ($r === null) {
            $hub->WarnUser(IHUB_Text::t('Solis: storage mode could not be read (register 43110), grid charging is attempted without checking.'));
            return true;
        }
        $cur = $mb->u16($r, 0);
        if (($cur & self::STORAGE_BIT_GRID) !== 0) {
            return true;
        }
        if (!in_array($cur, self::STORAGE_MODES_ALLOWED, true)) {
            $hub->WarnUser(IHUB_Text::t('Solis: storage mode %s does not allow grid charging and is not in the allowed selection; forced charging is attempted, but may only take effect from PV.', $cur));
            return true;
        }
        $target = $this->gridStorageTarget($cur);
        if ($hub->GetVarInt('svc_prev_grid_storage') <= 0) {
            $hub->SetVarInt('svc_prev_grid_storage', $cur);
        }
        if (!$this->writeVerified($mb, self::REG_STORAGE_MODE, $target, $err)) {
            $hub->WarnUser(IHUB_Text::t('Solis: grid charging could not be allowed (storage mode %s, register 43110): %s. Forced charging is not written.', $target, $err));
            return false;
        }
        return true;
    }

    // Gegenstueck: den vor dem Netzladen gemerkten Speicherbetrieb zurueckschreiben, aber nur, wenn das Geraet noch
    // genau auf dem von uns gesetzten Wert steht (hat jemand anders ihn geaendert, bleibt es dabei).
    private function restoreGridChargeStorage($mb, $hub)
    {
        $prev = $hub->GetVarInt('svc_prev_grid_storage');
        if ($prev <= 0) {
            return;
        }
        $r = $mb->readHolding(self::REG_STORAGE_MODE, 1);
        if ($r === null) {
            $hub->WarnUser(IHUB_Text::t('Solis: storage mode could not be read (register 43110), the value %s remembered before grid charging is not written back.', $prev));
            return;
        }
        $cur = $mb->u16($r, 0);
        if ($cur === $this->gridStorageTarget($prev) && $cur !== $prev) {
            if (!$this->writeVerified($mb, self::REG_STORAGE_MODE, $prev, $err)) {
                $hub->WarnUser(IHUB_Text::t('Solis: storage mode %s was not reverted by the device (register 43110): %s', $prev, $err));
                return;
            }
        }
        $hub->SetVarInt('svc_prev_grid_storage', 0);
    }

    // Reine Weitergabe (Architekturentscheidung 29.08.2026): keine eigene Wiederholung, kein Totmann -
    // Dauerhalten und Rueckfall liegen beim Aufrufer (EMS).
    public function writeControl($mb, $hub, $ident, $value)
    {
        if (strpos($ident, 'ctl_rc_') === 0 && IHUB_RemoteControl::control(new IHUB_RemoteSolis(), $mb, $hub, $ident, $value)) {
            return;
        }
        switch ($ident) {
            case 'ctl_force_w':
                $w = (int)(round(max(0, min(self::FORCE_MAX_W, (float)$value)) / 10) * 10);
                $mode = $hub->GetVarInt('ctl_force_mode');
                if ($mode === 1 || $mode === 2) {
                    // Zwangsmodus laeuft: neue Leistung sofort ins passende Register schreiben.
                    $reg = ($mode === 1) ? self::REG_FORCE_CHG_W : self::REG_FORCE_DISCH_W;
                    if (!$this->writeVerified($mb, $reg, intdiv($w, 10), $err)) {
                        $hub->WarnUser(IHUB_Text::t('Solis: forced power could not be written (register %s): %s', $reg, $err));
                        return;
                    }
                }
                $hub->SetVarInt('ctl_force_w', $w);
                return;

            case 'ctl_force_mode':
                $mode = (int)$value;
                if ($mode < 0 || $mode > 2) {
                    $hub->WarnUser(IHUB_Text::t('Solis: forced mode %s is invalid (0 off, 1 charge, 2 discharge).', $mode));
                    return;
                }
                if ($mode !== 0) {
                    // Erst die Leistung, dann den Modus - sonst gilt kurz der neue Modus mit alter Leistung.
                    $w   = max(0, $hub->GetVarInt('ctl_force_w'));
                    $reg = ($mode === 1) ? self::REG_FORCE_CHG_W : self::REG_FORCE_DISCH_W;
                    if (!$this->writeVerified($mb, $reg, intdiv($w, 10), $err)) {
                        $hub->WarnUser(IHUB_Text::t('Solis: forced power could not be written (register %s), mode remains unchanged: %s', $reg, $err));
                        return;
                    }
                }
                if (!$this->writeVerified($mb, self::REG_FORCE_MODE, $mode, $err)) {
                    $hub->WarnUser(IHUB_Text::t('Solis: forced mode could not be written (register 43135): %s', $err));
                    return;
                }
                $hub->SetVarInt('ctl_force_mode', $mode);
                return;

            case 'svc_grid_charge_w':
            case 'svc_discharge_to_grid_w':
                $mode = ($ident === 'svc_grid_charge_w') ? 1 : 2;
                $other = ($mode === 1) ? 'svc_discharge_to_grid_w' : 'svc_grid_charge_w';
                $w = (int)(round(max(0, min(self::FORCE_MAX_W, (float)$value)) / 10) * 10);
                if ($w === 0) {
                    $this->writeControl($mb, $hub, 'svc_release', true);
                    return;
                }
                if ($mode === 1 && !$this->ensureGridChargeAllowed($mb, $hub)) {
                    return;
                }
                $cur = $hub->GetVarInt('ctl_force_mode');
                if ($cur > 0 && $cur !== $mode) {
                    // Anderer Zwangsmodus laeuft: erst auf Aus, sonst ginge die neue Leistung ins falsche Register.
                    $this->writeControl($mb, $hub, 'ctl_force_mode', 0);
                    if ($hub->GetVarInt('ctl_force_mode') !== 0) { return; }
                }
                $this->writeControl($mb, $hub, 'ctl_force_w', $w);
                $this->writeControl($mb, $hub, 'ctl_force_mode', $mode);
                if ($hub->GetVarInt('ctl_force_mode') === $mode && $hub->GetVarInt('ctl_force_w') === $w) {
                    $hub->SetVarInt($ident, $w);
                    $hub->SetVarInt($other, 0);
                }
                return;

            case 'svc_release':
                if (!(bool)$value) { return; }
                $this->writeControl($mb, $hub, 'ctl_force_mode', 0);
                if ($hub->GetVarInt('ctl_force_mode') === 0) {
                    $this->restoreGridChargeStorage($mb, $hub);
                }
                $this->writeControl($mb, $hub, 'svc_discharge_inhibit', false);
                if ($hub->GetVarInt('ctl_force_mode') === 0) {
                    $hub->SetVarInt('svc_grid_charge_w', 0);
                    $hub->SetVarInt('svc_discharge_to_grid_w', 0);
                }
                return;

            case 'svc_discharge_inhibit':
                $on = (bool)$value;
                $cur = $mb->readHolding(self::REG_STORAGE_MODE, 1);
                if ($cur === null) {
                    $hub->WarnUser(IHUB_Text::t('Solis: storage mode could not be read (register 43110), "hold battery" not changed.'));
                    return;
                }
                $cur = $mb->u16($cur, 0);
                if ($on) {
                    if ($cur === self::STORAGE_HOLD) {
                        $hub->SetVarBool('svc_discharge_inhibit', true);   // bereits gesetzt, nichts schreiben
                        return;
                    }
                    if (!in_array($cur, self::STORAGE_MODES_ALLOWED, true)) {
                        $hub->WarnUser(IHUB_Text::t('Solis: storage mode %s is not in the allowed selection, "hold battery" is not set.', $cur));
                        return;
                    }
                    $hub->SetVarInt('svc_prev_storage', $cur);
                    if (!$this->writeVerified($mb, self::REG_STORAGE_MODE, self::STORAGE_HOLD, $err)) {
                        $hub->WarnUser(IHUB_Text::t('Solis: "hold battery" (storage mode 17) was not accepted by the device (register 43110): %s', $err));
                        return;
                    }
                    $hub->SetVarBool('svc_discharge_inhibit', true);
                    return;
                }
                $prev = $hub->GetVarInt('svc_prev_storage');
                if ($cur !== self::STORAGE_HOLD) {   // nicht von uns gehalten: nur die Anzeige nachziehen
                    $hub->SetVarBool('svc_discharge_inhibit', false);
                    return;
                }
                if (!in_array($prev, self::STORAGE_MODES_ALLOWED, true) || $prev === self::STORAGE_HOLD) {
                    $hub->WarnUser(IHUB_Text::t('Solis: no remembered storage mode available, please set "ctl_storage_mode" to the desired value.'));
                    return;
                }
                if (!$this->writeVerified($mb, self::REG_STORAGE_MODE, $prev, $err)) {
                    $hub->WarnUser(IHUB_Text::t('Solis: storage mode %s was not accepted by the device (register 43110): %s', $prev, $err));
                    return;
                }
                $hub->SetVarBool('svc_discharge_inhibit', false);
                return;

            case 'ctl_max_charge_a':
            case 'ctl_max_disch_a':
                $chg  = ($ident === 'ctl_max_charge_a');
                $reg  = $chg ? self::REG_MAX_CHARGE_A : self::REG_MAX_DISCH_A;
                $cfg  = $chg ? 'cfg_max_charge_a' : 'cfg_max_disch_a';
                $amps = round(max(0.0, min(self::CURRENT_MAX_A, (float)$value)), 1);
                // Fest gespeichert (EEPROM): nur schreiben, wenn der zuletzt gelesene Wert abweicht.
                if (abs($hub->GetVarFloat($cfg) - $amps) >= 0.05) {
                    if (!$mb->writeSingle($reg, (int)round($amps * 10))) {
                        $hub->WarnUser(IHUB_Text::t('Solis: current limit could not be written (register %s): %s', $reg, (isset($mb->lastError) ? $mb->lastError : '')));
                        return;
                    }
                }
                $hub->SetVarFloat($ident, $amps);
                return;

            case 'ctl_dispatch_w':
                $hub->SetVarInt('ctl_dispatch_w', (int)(round(max(-IHUB_SolisDispatch::POWER_MAX_W, min(IHUB_SolisDispatch::POWER_MAX_W, (float)$value)) / 10) * 10));
                return;

            case 'ctl_dispatch_grid_charge':
            case 'ctl_dispatch_pv_shutdown':
                if ((int)$value < 0 || (int)$value > 2) {
                    $hub->WarnUser(IHUB_Text::t('Solis dispatch: %s is invalid for %s (0 unchanged, 1, 2).', (int)$value, $ident));
                    return;
                }
                $hub->SetVarInt($ident, (int)$value);
                return;

            case 'ctl_dispatch_soc_min':
            case 'ctl_dispatch_soc_max':
                $hub->SetVarInt($ident, max(0, min(100, (int)$value)));
                return;

            case 'ctl_dispatch_failsafe_min':
                $hub->SetVarInt($ident, max(0, min(IHUB_SolisDispatch::FAILSAFE_MAX_MIN, (int)$value)));
                return;

            case 'ctl_dispatch_import_limit_w':
            case 'ctl_dispatch_export_limit_w':
                $hub->SetVarInt($ident, (int)(round(max(0, min(IHUB_SolisDispatch::POWER_MAX_W, (float)$value)) / 100) * 100));
                return;

            case 'ctl_dispatch_mode':
                $p = [
                    'mode'         => (int)$value,
                    'watts'        => $hub->GetVarInt('ctl_dispatch_w'),
                    'gridCharge'   => $hub->GetVarInt('ctl_dispatch_grid_charge'),
                    'pvShutdown'   => $hub->GetVarInt('ctl_dispatch_pv_shutdown'),
                    'socMin'       => $hub->GetVarInt('ctl_dispatch_soc_min'),
                    'socMax'       => $hub->GetVarInt('ctl_dispatch_soc_max'),
                    'failsafeMin'  => $hub->GetVarInt('ctl_dispatch_failsafe_min'),
                    'importLimitW' => $hub->GetVarInt('ctl_dispatch_import_limit_w'),
                    'exportLimitW' => $hub->GetVarInt('ctl_dispatch_export_limit_w'),
                ];
                // Faehigkeit aus dem letzten langsamen Zyklus (Version > 0 nur bei 0xAA55), sonst einmal nachfragen.
                $ver = $hub->GetVarInt('dispatch_version');
                $known = $ver > 0 ? ['available' => true, 'version' => $ver, 'status' => $hub->GetVarInt('dispatch_status')] : null;
                $status = null;
                if (!IHUB_SolisDispatch::apply($mb, $p, $known, $err, $status)) {
                    $hub->WarnUser(IHUB_Text::t('Solis dispatch could not be applied: %s', $err));
                    return;
                }
                $hub->SetVarBool('dispatch_available', true);
                if ($status !== null) {
                    $hub->SetVarInt('dispatch_status', (int)$status);
                }
                $hub->SetVarInt('ctl_dispatch_mode', (int)$value);
                return;

            case 'ctl_storage_mode':
                $mode = (int)$value;
                if (!in_array($mode, self::STORAGE_MODES_ALLOWED, true)) {
                    $hub->WarnUser(IHUB_Text::t('Solis: storage mode %s is not written (not in the allowed selection, e.g. island mode/battery wake-up).', $mode));
                    return;
                }
                // Das Register ist fest gespeichert - nur schreiben, wenn der zuletzt gelesene Wert abweicht.
                if ($hub->GetVarInt('cfg_storage_mode') === $mode) {
                    $hub->SetVarInt('ctl_storage_mode', $mode);
                    return;
                }
                if (!$this->writeVerified($mb, self::REG_STORAGE_MODE, $mode, $err)) {
                    $hub->WarnUser(IHUB_Text::t('Solis: storage mode could not be written (register 43110): %s', $err));
                    return;
                }
                $hub->SetVarInt('ctl_storage_mode', $mode);
                return;
        }
    }
}
