<?php

// ---------------------------------------------------------------------------
// IHUB_SungrowDriver — Sungrow SH-Hybrid-Serie (SH5.0RT...SH10RT, SH5.0RS...SH10RS)
// Eigene, weit verstreute Einzelregister. Mess-/Statuswerte: Read Input
// Register (FC 0x04). Steuerung: Holding Register (FC 0x03/0x06).
// Registeradressen werden direkt verwendet (kein Offset -1 dokumentiert).
// ---------------------------------------------------------------------------

class IHUB_SungrowDriver implements IHUB_InverterDriverInterface
{
    const REG_START_STOP = 13000; // Holding: 0xCF=Start, 0xCE=Stop

    const RUN_STATES = [
        0 => 'Off', 1 => 'Running', 2 => 'Fault', 3 => 'Standby',
    ];

    public function getBaseVars()
    {
        return [
            ['connected',   'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['riso',        'Insulation resistance','F', 'SGW.KOhm',       true,  'pv',     'RO 5071 (kΩ)'],
            ['running_state','Operating status',   'I', 'SGW.RunState',     true,  'device', 'RO 13000'],
            ['power_flow_status', 'Power flow status', 'I', '',       true,  'device', 'RO 13001'],
            ['pv_total',    'PV total power', 'F', 'SGW.Watt',         true,  'pv',     'RO 5017-5018'],
            ['ac_power',    'AC active power',   'F', 'SGW.Watt',         true,  'device', 'RO 13034-13035'],
            ['meter_total', 'Grid power',     'F', 'SGW.Watt',         true,  'grid',   'RO 5601-5602'],
            ['bat_power',   'Bat. power',     'F', 'SGW.Watt',         true,  'bat',    'RO 5214-5215'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV details (MPPT voltage/current; string models SG-CX up to MPPT 12)', 'vars' => [
                ['mppt1_volt', 'MPPT1 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5011'],
                ['mppt1_curr', 'MPPT1 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5012'],
                ['mppt2_volt', 'MPPT2 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5013'],
                ['mppt2_curr', 'MPPT2 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5014'],
                ['mppt3_volt', 'MPPT3 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5015'],
                ['mppt3_curr', 'MPPT3 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5016'],
                ['mppt4_volt', 'MPPT4 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5115'],
                ['mppt4_curr', 'MPPT4 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5116'],
                ['mppt5_volt', 'MPPT5 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5117'],
                ['mppt5_curr', 'MPPT5 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5118'],
                ['mppt6_volt', 'MPPT6 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5119'],
                ['mppt6_curr', 'MPPT6 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5120'],
                ['mppt7_volt', 'MPPT7 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5121'],
                ['mppt7_curr', 'MPPT7 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5122'],
                ['mppt8_volt', 'MPPT8 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5123'],
                ['mppt8_curr', 'MPPT8 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5124'],
                ['mppt9_volt', 'MPPT9 voltage', 'F', 'SGW.Volt',   false, 'pv', 'RO 5130'],
                ['mppt9_curr', 'MPPT9 current',    'F', 'SGW.Ampere', false, 'pv', 'RO 5131'],
                ['mppt10_volt','MPPT10 voltage','F', 'SGW.Volt',   false, 'pv', 'RO 5132'],
                ['mppt10_curr','MPPT10 current',   'F', 'SGW.Ampere', false, 'pv', 'RO 5133'],
                ['mppt11_volt','MPPT11 voltage','F', 'SGW.Volt',   false, 'pv', 'RO 5134'],
                ['mppt11_curr','MPPT11 current',   'F', 'SGW.Ampere', false, 'pv', 'RO 5135'],
                ['mppt12_volt','MPPT12 voltage','F', 'SGW.Volt',   false, 'pv', 'RO 5136'],
                ['mppt12_curr','MPPT12 current',   'F', 'SGW.Ampere', false, 'pv', 'RO 5137'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, current, reactive power, power factor, frequency)', 'vars' => [
                ['grid_v1',      'Grid voltage 1', 'F', 'SGW.Volt',   false, 'grid', 'RO 5019'],
                ['grid_v2',      'Grid voltage 2', 'F', 'SGW.Volt',   false, 'grid', 'RO 5020'],
                ['grid_v3',      'Grid voltage 3', 'F', 'SGW.Volt',   false, 'grid', 'RO 5021'],
                ['grid_c1',      'Grid current 1',    'F', 'SGW.Ampere', false, 'grid', 'RO 5022 (String)'],
                ['grid_c2',      'Grid current 2',    'F', 'SGW.Ampere', false, 'grid', 'RO 5023 (String)'],
                ['grid_c3',      'Grid current 3',    'F', 'SGW.Ampere', false, 'grid', 'RO 5024 (String)'],
                ['grid_reactive','Reactive power',    'F', 'SGW.WattReactive', false, 'grid', 'RO 5033-5034'],
                ['power_factor', 'Power factor',     'F', 'SGW.PowerFactor',  false, 'grid', 'RO 5035'],
                ['grid_freq',    'Grid frequency',     'F', 'SGW.Hertz',        false, 'grid', 'RO 5242'],
            ]],
            'GroupBat' => ['caption' => 'Battery (voltage, current, SOC, SOH, temperature)', 'vars' => [
                ['bat_volt', 'Bat. voltage',    'F', 'SGW.Volt',     false, 'bat', 'RO 13020'],
                ['bat_curr', 'Bat. current',       'F', 'SGW.Ampere',   false, 'bat', 'RO 13021'],
                ['bat_soc',  'Bat. SOC',         'I', '~Battery.100', true,  'bat', 'RO 13023'],
                ['bat_soh',  'Bat. SOH',         'I', '~Intensity.100', true, 'bat', 'RO 13024'],
                ['bat_temp', 'Bat. temperature',  'F', '~Temperature', true,  'bat', 'RO 13025'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (power per phase)', 'vars' => [
                ['mt_l1_pwr', 'Meter L1 power', 'F', 'SGW.Watt', true, 'meter', 'RO 5603'],
                ['mt_l2_pwr', 'Meter L2 power', 'F', 'SGW.Watt', true, 'meter', 'RO 5605'],
                ['mt_l3_pwr', 'Meter L3 power', 'F', 'SGW.Watt', true, 'meter', 'RO 5607'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (PV, load, export)', 'vars' => [
                ['e_pv_day',   'PV today',   'F', '~Electricity', true, 'energy', 'RO 13002'],
                ['e_pv_total', 'PV total',  'F', '~Electricity', true, 'energy', 'RO 13003-13004'],
                ['load_power', 'Load power', 'F', 'SGW.Watt',   true, 'energy', 'RO 13008-13009'],
                ['export_power','Feed-in power', 'F', 'SGW.Watt', true, 'energy', 'RO 13010-13011'],
            ]],
            'GroupBackup' => ['caption' => 'Backup / emergency power (voltage, current, power per phase)', 'vars' => [
                ['backup_total', 'Backup total power', 'F', 'SGW.Watt', true, 'backup', 'RO 5726-5727'],
                ['backup_freq',  'Backup frequency',       'F', 'SGW.Hertz', false, 'backup', 'RO 5734'],
            ]],
            'GroupDevice' => ['caption' => 'Device information (type, rated power, serial number)', 'vars' => [
                ['dev_type',    'Device type code', 'I', '', false, 'device', 'RO 5000'],
                ['dev_rated_w', 'Rated power',    'I', '', false, 'device', 'RO 5001'],
                ['dev_sn',      'Serial number',    'S', '', false, 'device', 'RO 4990-4999'],
            ]],
            'GroupControl' => ['caption' => 'Control (start/stop)', 'vars' => [
                ['ctl_run', 'Inverter on/off', 'B', '~Switch', false, 'control', 'RW 13000'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SGW.Watt'          => [VARIABLETYPE_FLOAT,   ' W',   -40000.0, 40000.0, 1.0,  0],
            'SGW.Volt'          => [VARIABLETYPE_FLOAT,   ' V',        0.0,  1000.0, 0.1,  1],
            'SGW.Ampere'        => [VARIABLETYPE_FLOAT,   ' A',     -200.0,   200.0, 0.1,  1],
            'SGW.Hertz'         => [VARIABLETYPE_FLOAT,   ' Hz',      45.0,    65.0, 0.01, 2],
            'SGW.WattReactive'  => [VARIABLETYPE_FLOAT,   ' var', -40000.0, 40000.0, 1.0,  0],
            'SGW.PowerFactor'   => [VARIABLETYPE_FLOAT,   '',        -1.0,     1.0, 0.001, 3],
            'SGW.KOhm'          => [VARIABLETYPE_FLOAT,   ' kΩ',       0.0, 65535.0, 1.0,  0],
        ];
    }

    public function getEnumProfiles()
    {
        $runState = [];
        foreach (self::RUN_STATES as $k => $label) {
            $runState[$k] = [$label, 0x7A8A99];
        }
        return ['SGW.RunState' => $runState];
    }

    public function readFast($mb, $hub)
    {
        // Alle Reads eines Zyklus über EINE Verbindung (Batch): der Sungrow
        // WiNet-S erlaubt nur eine Modbus-Verbindung und lehnt schnelle
        // Reconnects ab - sonst fielen spätere Reads (z. B. der MPPT-Block) aus.
        $mb->beginBatch();
        try {
            return $this->readFastInner($mb, $hub);
        } finally {
            $mb->endBatch();
        }
    }

    private function readFastInner($mb, $hub)
    {
        // String-Wechselrichter (SG-CX/„P2") haben den 13000er-Hybrid-Block NICHT
        // (Modbus-Exception) und legen ihre Daten ausschließlich im 5000er-Block
        // ab - mit gegenüber den Hybrid-Modellen um 1 nach unten verschobenen
        // Adressen (Protokoll-Adresse = Sungrow-Doku − 1) und 32-Bit-Werten mit
        // niederwertigem Wort zuerst. Dafür ein eigener Lesepfad.
        $probe13000 = $mb->readInput(13000, 2);
        if ($probe13000 === null) {
            return $this->readFastString($mb, $hub);
        }

        $dc      = $mb->readInput(5011, 12);   // 5011-5022 (MPPT1-3 + DC total + Grid Volt)
        $mppt4   = $mb->readInput(5115, 2);    // 5115-5116
        $reactive= $mb->readInput(5033, 4);    // 5033-5036 (reactive/PF/freq)
        $battery = $mb->readInput(5214, 2);    // 5214-5215 Bat power wide range
        $meterTotal = $mb->readInput(5601, 2); // 5601-5602 Meter Active Power (Gesamt, real bestätigt)
        $meter   = $mb->readInput(5603, 6);    // 5603,5605,5607 (+ Reserve dazwischen) — unbestätigt
        $running = $probe13000;                // 13000 running state + 13001 power flow
        $freqhi  = $mb->readInput(5242, 1);    // 5242 high precision freq
        $sum     = $mb->readInput(13034, 2);   // 13034-13035 total active power

        $ok = ($dc !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        if ($running !== null) {
            $hub->SetVarInt('running_state', $mb->u16($running, 0));
            $hub->SetVarInt('power_flow_status', $mb->u16($running, 1));
        }
        $hub->SetVarFloat('pv_total', (float)$mb->u32($dc, 6));
        $riso = $mb->readInput(5071, 1); // Array-Isolationswiderstand (kΩ)
        if ($riso !== null) {
            $hub->SetVarFloat('riso', (float)$mb->u16($riso, 0));
        }
        if ($sum !== null) {
            $hub->SetVarFloat('ac_power', (float)$mb->s32($sum, 0));
        }
        if ($battery !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s32($battery, 0));
        }
        if ($meterTotal !== null) {
            $hub->SetVarFloat('meter_total', (float)$mb->s32($meterTotal, 0));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('mppt1_volt', $mb->u16($dc, 0) / 10.0);
            $hub->SetVarFloat('mppt1_curr', $mb->u16($dc, 1) / 10.0);
            $hub->SetVarFloat('mppt2_volt', $mb->u16($dc, 2) / 10.0);
            $hub->SetVarFloat('mppt2_curr', $mb->u16($dc, 3) / 10.0);
            $hub->SetVarFloat('mppt3_volt', $mb->u16($dc, 4) / 10.0);
            $hub->SetVarFloat('mppt3_curr', $mb->u16($dc, 5) / 10.0);
            if ($mppt4 !== null) {
                $hub->SetVarFloat('mppt4_volt', $mb->u16($mppt4, 0) / 10.0);
                $hub->SetVarFloat('mppt4_curr', $mb->u16($mppt4, 1) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_v1', $mb->u16($dc, 9)  / 10.0);
            $hub->SetVarFloat('grid_v2', $mb->u16($dc, 10) / 10.0);
            $hub->SetVarFloat('grid_v3', $mb->u16($dc, 11) / 10.0);
            if ($reactive !== null) {
                $hub->SetVarFloat('grid_reactive', (float)$mb->s32($reactive, 0));
                $hub->SetVarFloat('power_factor',  $mb->s16($reactive, 2) / 1000.0);
            }
            if ($freqhi !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freqhi, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupMeter') && $meter !== null) {
            $hub->SetVarFloat('mt_l1_pwr', (float)$mb->s32($meter, 0));
            $hub->SetVarFloat('mt_l2_pwr', (float)$mb->s32($meter, 2));
            $hub->SetVarFloat('mt_l3_pwr', (float)$mb->s32($meter, 4));
        }

        if ($hub->GetPropBool('GroupBat')) {
            $batBlk = $mb->readInput(13020, 7); // 13020-13026
            if ($batBlk !== null) {
                $hub->SetVarFloat('bat_volt', $mb->u16($batBlk, 0) / 10.0);
                $hub->SetVarFloat('bat_curr', $mb->u16($batBlk, 1) / 10.0);
                $hub->SetVarInt('bat_soc', (int)round($mb->u16($batBlk, 3) / 10.0));
                $hub->SetVarInt('bat_soh', (int)round($mb->u16($batBlk, 4) / 10.0));
                $hub->SetVarFloat('bat_temp', $mb->s16($batBlk, 5) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupBackup')) {
            $bk = $mb->readInput(5726, 10); // 5726-5735
            if ($bk !== null) {
                $hub->SetVarFloat('backup_total', (float)$mb->s32($bk, 0));
                $hub->SetVarFloat('backup_freq',  $mb->u16($bk, 8) / 100.0);
            }
        }

        return true;
    }

    // String-Wechselrichter (SG-CX/„P2"): alle Werte aus dem 5000er-Block.
    // Protokoll-Adresse = Sungrow-Doku − 1; 32-Bit „sw" (niederwertiges Wort
    // zuerst). Adressen live an einem SG125CX-P2 verifiziert.
    private function readFastString($mb, $hub)
    {
        $b = $mb->readInput(5000, 40); // 5000..5039
        $hub->SetVarBool('connected', $b !== null);
        if ($b === null) {
            return false;
        }
        // 32-Bit, niederwertiges Wort zuerst.
        $u32 = function ($i) use ($b) { return ($b[$i] ?? 0) + (($b[$i + 1] ?? 0) << 16); };
        $s32 = function ($i) use ($b) { $v = ($b[$i] ?? 0) + (($b[$i + 1] ?? 0) << 16); return ($v >= 0x80000000) ? $v - 0x100000000 : $v; };
        $s16 = function ($i) use ($b) { $v = $b[$i] ?? 0; return ($v >= 0x8000) ? $v - 0x10000 : $v; };

        $hub->SetVarFloat('pv_total', (float)$u32(16));   // 5016-5017 DC-Gesamtleistung (W)
        $hub->SetVarFloat('ac_power', (float)$u32(30));   // 5030-5031 Wirkleistung (W)

        // Isolationsimpedanz gegen Masse (kΩ) - String-Modelle bei 5070.
        $riso = $mb->readInput(5070, 1);
        if ($riso !== null) {
            $hub->SetVarFloat('riso', (float)$mb->u16($riso, 0));
        }

        if ($hub->GetPropBool('GroupPV')) {
            // MPPT 1-3 im 5000er-Block (5010-5015).
            $hub->SetVarFloat('mppt1_volt', ($b[10] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt1_curr', ($b[11] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt2_volt', ($b[12] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt2_curr', ($b[13] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt3_volt', ($b[14] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt3_curr', ($b[15] ?? 0) / 10.0);
            // MPPT 4-12 im erweiterten Block. AB 5100 lesen (dokumentierter
            // Blockanfang) - ein Read direkt ab 5114 wird vom WR abgelehnt.
            // V-Register: MPPT4 5114 … relative Offsets zu 5100 (Lücke 5124-5128).
            $ext = $mb->readInput(5100, 50); // 5100..5149
            if ($ext !== null) {
                $map = [4 => 14, 5 => 16, 6 => 18, 7 => 20, 8 => 22, 9 => 29, 10 => 31, 11 => 33, 12 => 35];
                foreach ($map as $n => $vi) {
                    $hub->SetVarFloat("mppt{$n}_volt", ($ext[$vi] ?? 0) / 10.0);
                    $hub->SetVarFloat("mppt{$n}_curr", ($ext[$vi + 1] ?? 0) / 10.0);
                }
            }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_v1', ($b[18] ?? 0) / 10.0);  // 5018-5020 Phasen U
            $hub->SetVarFloat('grid_v2', ($b[19] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_v3', ($b[20] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_c1', ($b[21] ?? 0) / 10.0);  // 5021-5023 Phasen I
            $hub->SetVarFloat('grid_c2', ($b[22] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_c3', ($b[23] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_reactive', (float)$s32(32));  // 5032-5033 Blindleistung
            $hub->SetVarFloat('power_factor',  $s16(34) / 1000.0);// 5034 Power Factor
            $hub->SetVarFloat('grid_freq',     ($b[35] ?? 0) / 10.0); // 5035 Frequenz (×0,1)
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            $hub->SetVarFloat('e_pv_day',   ($b[2] ?? 0) / 10.0); // 5002 Tagesertrag (×0,1 kWh)
            $hub->SetVarFloat('e_pv_total', (float)$u32(3));       // 5003-5004 Gesamtertrag (kWh)
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($mb->readInput(13000, 1) === null) {
            return; // String-WR: Energie kommt aus readFastString (5000er-Block)
        }
        if ($hub->GetPropBool('GroupEnergy')) {
            $e = $mb->readInput(13002, 11); // 13002-13012
            if ($e !== null) {
                $hub->SetVarFloat('e_pv_day',   $mb->u16($e, 0) / 10.0);
                $hub->SetVarFloat('e_pv_total', $mb->u32($e, 1) / 10.0);
                $hub->SetVarFloat('load_power',   (float)$mb->s32($e, 6));
                $hub->SetVarFloat('export_power', (float)$mb->s32($e, 8));
            }
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $mb->beginBatch();
        try {
            $this->readDeviceInfoInner($mb, $hub);
        } finally {
            $mb->endBatch();
        }
    }

    private function readDeviceInfoInner($mb, $hub)
    {
        // String-WR (SG-CX/„P2"): Gerätetyp 4999, Nennleistung 5000 (×0,1 kW).
        // Hybrid-Modelle: Gerätetyp 5000, Nennleistung 5001.
        if ($mb->readInput(13000, 1) === null) {
            $dev = $mb->readInput(4999, 2); // 4999 Typ, 5000 Nennleistung
        } else {
            $dev = $mb->readInput(5000, 2); // 5000 Typ, 5001 Nennleistung
        }
        if ($dev !== null) {
            $hub->SetVarInt('dev_type',    $mb->u16($dev, 0));
            $hub->SetVarInt('dev_rated_w', $mb->u16($dev, 1) * 100);
        }
        $sn = $mb->readInput(4989, 10); // Seriennummer (UTF-8)
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 10));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        if ($ident === 'ctl_run') {
            $val = (bool)$value ? 0xCF : 0xCE;
            if ($mb->writeSingle(IHUB_SungrowDriver::REG_START_STOP, $val)) {
                $hub->SetVarBool('ctl_run', (bool)$value);
            }
        }
    }
}
