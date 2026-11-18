<?php

class IHUB_RctPowerDriver implements IHUB_InverterDriverInterface
{
    // Objekt-IDs (Beleg: python-rctclient registry.py; Namen dort in Klammern)
    const OID_SOC          = 0x959930BF;   // battery.soc, Anteil 0..1
    const OID_BAT_POWER    = 0x400F015B;   // g_sync.p_acc_lp, W
    const OID_GRID_POWER   = 0x91617C58;   // g_sync.p_ac_grid_sum_lp, W
    const OID_AC_POWER     = 0xDB2D69AE;   // g_sync.p_ac_sum_lp, W
    const OID_PV_A         = 0xDB11855B;   // dc_conv.dc_conv_struct[0].p_dc_lp, W
    const OID_PV_B         = 0x0CB5D21B;   // dc_conv.dc_conv_struct[1].p_dc_lp, W
    const OID_BAT_VOLT     = 0x65EED11B;   // battery.voltage, V
    const OID_BAT_CURR     = 0x21961B58;   // battery.current, A
    const OID_BAT_TEMP     = 0x902AFAFB;   // battery.temperature, °C
    const OID_BAT_STATUS   = 0x71765BD8;   // battery.status, Int32
    const OID_E_CHARGE     = 0x5570401B;   // battery.stored_energy, Wh
    const OID_E_DISCH      = 0xA9033880;   // battery.used_energy, Wh
    const OID_E_PV_A       = 0xFC724A9E;   // energy.e_dc_total[0], Wh
    const OID_E_PV_B       = 0x68EEFD3D;   // energy.e_dc_total[1], Wh
    const OID_SERIAL       = 0x7924ABD9;   // Seriennummer (HA-Integration rct_power, api.py)
    // Stufe 2a (nur Lesen). Quelle: Adresskommentare im Master von IPSCoyote/RCT-Power (Version 2.3);
    // Typen und Skalierung dort nur als Kommentar, am Gerät noch nicht geprüft.
    const OID_TOWER_COUNT  = 0x663F1452;   // Anzahl Batterietürme (battery stack count)
    const OID_BAT_VOLT_SUM = 0xA7FA5C5D;   // Batteriespannung gesamt, V
    const OID_BAT_CURR_SUM = 0xB408E40A;   // Batteriestrom gesamt, A (Tiefpass 1 s)
    const OID_T2_CURR      = 0x79D7D617;   // Turm 2 Strom, A
    const OID_T2_VOLT      = 0xFCA1CBB5;   // Turm 2 Spannung, V
    const OID_SOC2         = 0x8B4BE168;   // Turm 2 Ladestand, 0..1
    const OID_SOH1         = 0x381B8BF9;   // Turm 1 Gesundheitszustand, 0..1
    const OID_SOH2         = 0x1781CD31;   // Turm 2 Gesundheitszustand, 0..1
    const OID_PM_STATE     = 0xDC667958;   // power_mng.state, UInt8
    const OID_EMPTY_FULL   = 0x315D1490;   // power_mng.bat_empty_full, Bit 0 war leer, Bit 1 war voll
    const OID_STRATEGY     = 0xF168B748;   // power_mng.soc_strategy, nur Anzeige (0..5, 2 = extern, 4 = intern)
    const OID_POWER_EXTERN = 0xBD008E29;   // power_mng.battery_power_extern, W, nur Anzeige
    const OID_USE_GRID     = 0x36A9E9A6;   // power_mng.use_grid_power_enable, Bool, nur Anzeige
    const OID_SOC_MIN      = 0xCE266F0F;   // power_mng.soc_min, 0..1, nur Anzeige

    public function getBaseVars()
    {
        return [
            ['connected',   'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['bat_soc',     'Bat. SOC',          'I', '~Battery.100',    true,  'bat',    'OID 0x959930BF battery.soc (Anteil 0..1, hier in %)'],
            ['bat_power',   'Bat. power (+ discharging / − charging)', 'F', 'RCT.Watt', true, 'bat', 'OID 0x400F015B g_sync.p_acc_lp'],
            ['meter_total', 'Grid power (+ feed-in / − import)', 'F', 'RCT.Watt', true, 'grid', 'OID 0x91617C58 g_sync.p_ac_grid_sum_lp (Rohwert + = Bezug, hier umgekehrt)'],
            ['ac_power',    'AC active power',   'F', 'RCT.Watt',        true,  'device', 'OID 0xDB2D69AE g_sync.p_ac_sum_lp'],
            ['pv_total',    'PV total power', 'F', 'RCT.Watt',        true,  'pv',     'OID 0xDB11855B + 0x0CB5D21B (Generator A + B)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV generators (A, B)', 'vars' => [
                ['mppt1_power', 'PV generator A power', 'F', 'RCT.Watt', true, 'pv', 'OID 0xDB11855B'],
                ['mppt2_power', 'PV generator B power', 'F', 'RCT.Watt', true, 'pv', 'OID 0x0CB5D21B'],
            ]],
            'GroupBat' => ['caption' => 'Battery (voltage, current, temperature, status)', 'vars' => [
                ['bat_volt',   'Bat. voltage',    'F', 'RCT.Volt',   false, 'bat', 'OID 0x65EED11B'],
                ['bat_curr',   'Bat. current',       'F', 'RCT.Ampere', false, 'bat', 'OID 0x21961B58'],
                ['bat_temp',   'Bat. temperature',  'F', '~Temperature', false, 'bat', 'OID 0x902AFAFB'],
                ['bat_status', 'Bat. status (raw value)', 'I', '',      false, 'bat', 'OID 0x71765BD8 battery.status'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (total)', 'vars' => [
                ['e_pv_total',     'PV total',            'F', '~Electricity', true, 'energy', 'OID 0xFC724A9E + 0x68EEFD3D (Wh)'],
                ['e_charge_total', 'Bat. charge total',    'F', '~Electricity', true, 'energy', 'OID 0x5570401B battery.stored_energy (Wh)'],
                ['e_disch_total',  'Bat. discharge total', 'F', '~Electricity', true, 'energy', 'OID 0xA9033880 battery.used_energy (Wh)'],
            ]],
            'GroupBatTowers' => ['caption' => 'Battery towers (tower 2, total values, state of health, number of towers, read only, preview)', 'vars' => [
                ['bat_tower_count', 'Battery towers (number)', 'I', '', false, 'bat', 'OID 0x663F1452'],
                ['bat_volt_total',  'Bat. voltage (total)',    'F', 'RCT.Volt',   false, 'bat', 'OID 0xA7FA5C5D'],
                ['bat_curr_total',  'Bat. current (total)',    'F', 'RCT.Ampere', false, 'bat', 'OID 0xB408E40A'],
                ['bat1_power',      'Tower 1 power (voltage x current)', 'F', 'RCT.Watt', false, 'bat', 'calculated'],
                ['bat2_volt',       'Tower 2 voltage',         'F', 'RCT.Volt',   false, 'bat', 'OID 0xFCA1CBB5'],
                ['bat2_curr',       'Tower 2 current',         'F', 'RCT.Ampere', false, 'bat', 'OID 0x79D7D617'],
                ['bat2_power',      'Tower 2 power (voltage x current)', 'F', 'RCT.Watt', false, 'bat', 'calculated'],
                ['bat2_soc',        'Tower 2 SOC',             'I', '~Battery.100',   false, 'bat', 'OID 0x8B4BE168'],
                ['bat_soh',         'Tower 1 SOH',             'I', '~Intensity.100', false, 'bat', 'OID 0x381B8BF9'],
                ['bat2_soh',        'Tower 2 SOH',             'I', '~Intensity.100', false, 'bat', 'OID 0x1781CD31'],
            ]],
            'GroupBatState' => ['caption' => 'Battery state (operating state, was empty, was full, read only, preview)', 'vars' => [
                ['bat_mode_state', 'Operating state (raw value)', 'I', '', false, 'bat', 'OID 0xDC667958 power_mng.state'],
                ['bat_was_empty',  'Battery was empty',  'B', '', false, 'bat', 'OID 0x315D1490 bit 0'],
                ['bat_was_full',   'Battery was full',   'B', '', false, 'bat', 'OID 0x315D1490 bit 1'],
            ]],
            'GroupControlState' => ['caption' => 'Control state (strategy, external target power, grid power allowed; display only, nothing is written, preview)', 'vars' => [
                ['rct_strategy',        'SOC strategy',                 'I', 'RCT.Strategy', false, 'bat', 'OID 0xF168B748 power_mng.soc_strategy'],
                ['rct_external_active', 'External control active (strategy 2)', 'B', '~Alert', false, 'bat', 'OID 0xF168B748'],
                ['rct_power_extern',    'External target power (+ discharging / − charging)', 'F', 'RCT.Watt', false, 'bat', 'OID 0xBD008E29 power_mng.battery_power_extern'],
                ['rct_use_grid',        'Grid power allowed',           'B', '', false, 'bat', 'OID 0x36A9E9A6 power_mng.use_grid_power_enable'],
                ['rct_soc_min',         'Minimum SOC',                  'I', '~Intensity.100', false, 'bat', 'OID 0xCE266F0F power_mng.soc_min'],
            ]],
            'GroupDevice' => ['caption' => 'Device information (serial number)', 'vars' => [
                ['dev_sn', 'Serial number', 'S', '', false, 'device', 'OID 0x7924ABD9'],
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
            'RCT.Watt'   => [VARIABLETYPE_FLOAT, ' W', -100000.0, 100000.0, 1.0, 0],
            'RCT.Volt'   => [VARIABLETYPE_FLOAT, ' V',        0.0,   1500.0, 0.1, 1],
            'RCT.Ampere' => [VARIABLETYPE_FLOAT, ' A',     -500.0,    500.0, 0.1, 1],
        ];
    }

    public function getEnumProfiles()
    {
        return ['RCT.Strategy' => [
            0 => ['SOC target = SOC', 0x7A8A99],
            1 => ['Constant', 0x7A8A99],
            2 => ['External', 0xE67E22],
            3 => ['Average battery voltage', 0x7A8A99],
            4 => ['Internal', 0x2ECC71],
            5 => ['Schedule', 0x7A8A99],
        ]];
    }

    // Anteil 0..1 -> ganze Prozent; alles ausserhalb gilt als unbrauchbare Antwort (nicht schreiben).
    private static function pct(?float $x): ?int
    {
        return ($x === null || $x < 0.0 || $x > 1.0) ? null : (int)round($x * 100.0);
    }

    // Ganzzahl ohne Vorzeichen; Länge der Antwort (1, 2 oder 4 Byte) entscheidet, weil der Typ je Objekt
    // nur als Kommentar im Fremdcode steht.
    private function readUInt($mb, int $oid): ?int
    {
        $p = $mb->read($oid);
        if ($p === null) {
            return null;
        }
        switch (strlen($p)) {
            case 1:
                return ord($p);
            case 2:
                return unpack('n', $p)[1];
            case 4:
                return unpack('N', $p)[1];
        }
        return null;
    }

    // Schreibt nur Werte, die das Gerät tatsächlich beantwortet hat. Fehlt eine Antwort,
    // bleibt der alte Wert stehen (nicht auf 0 setzen). Antwortet gar nichts, wird die
    // Verbindung als gestört gemeldet.
    public function readFast($mb, $hub)
    {
        $soc  = $mb->readFloat(self::OID_SOC);
        $bat  = $mb->readFloat(self::OID_BAT_POWER);
        $grid = $mb->readFloat(self::OID_GRID_POWER);
        $ac   = $mb->readFloat(self::OID_AC_POWER);
        $pa   = $mb->readFloat(self::OID_PV_A);
        $pb   = $mb->readFloat(self::OID_PV_B);

        $answered = ($soc !== null) || ($bat !== null) || ($grid !== null) || ($ac !== null) || ($pa !== null) || ($pb !== null);
        $hub->SetVarBool('connected', $answered);
        if (!$answered) {
            $why = ($mb->requests > 0 && $mb->crcErrors > 0)
                ? IHUB_Text::t('only faulty answers (checksum)')
                : IHUB_Text::t('no answer within the time limit');
            throw new RuntimeException(IHUB_Text::t('RCT Power: %s. Check address and port (usually 8899); is the RCT app or Home Assistant running at the same time?', $why));
        }

        if ($soc !== null) {
            $hub->SetVarInt('bat_soc', (int)round($soc * 100.0));
        }
        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', $bat);
        }
        if ($grid !== null) {
            $hub->SetVarFloat('meter_total', -$grid);
        }
        if ($ac !== null) {
            $hub->SetVarFloat('ac_power', $ac);
        }
        if ($pa !== null || $pb !== null) {
            $hub->SetVarFloat('pv_total', max(0.0, (float)$pa) + max(0.0, (float)$pb));
        }
        if ($hub->GetPropBool('GroupPV')) {
            if ($pa !== null) {
                $hub->SetVarFloat('mppt1_power', max(0.0, $pa));
            }
            if ($pb !== null) {
                $hub->SetVarFloat('mppt2_power', max(0.0, $pb));
            }
        }
        $wantBat    = $hub->GetPropBool('GroupBat');
        $wantTowers = $hub->GetPropBool('GroupBatTowers');
        $v = null;
        $a = null;
        if ($wantBat || $wantTowers) {
            $v = $mb->readFloat(self::OID_BAT_VOLT);
            $a = $mb->readFloat(self::OID_BAT_CURR);
        }
        if ($wantBat) {
            if ($v !== null) {
                $hub->SetVarFloat('bat_volt', $v);
            }
            if ($a !== null) {
                $hub->SetVarFloat('bat_curr', $a);
            }
            $t = $mb->readFloat(self::OID_BAT_TEMP);
            if ($t !== null) {
                $hub->SetVarFloat('bat_temp', $t);
            }
            $s = $mb->readInt32(self::OID_BAT_STATUS);
            if ($s !== null) {
                $hub->SetVarInt('bat_status', $s);
            }
        }
        if ($wantTowers) {
            $vs = $mb->readFloat(self::OID_BAT_VOLT_SUM);
            if ($vs !== null) {
                $hub->SetVarFloat('bat_volt_total', $vs);
            }
            $as = $mb->readFloat(self::OID_BAT_CURR_SUM);
            if ($as !== null) {
                $hub->SetVarFloat('bat_curr_total', $as);
            }
            // Leistung je Turm nur, wenn beide Messwerte geantwortet haben (Teilwert wäre falsch).
            if ($v !== null && $a !== null) {
                $hub->SetVarFloat('bat1_power', $v * $a);
            }
            $v2 = $mb->readFloat(self::OID_T2_VOLT);
            $a2 = $mb->readFloat(self::OID_T2_CURR);
            if ($v2 !== null) {
                $hub->SetVarFloat('bat2_volt', $v2);
            }
            if ($a2 !== null) {
                $hub->SetVarFloat('bat2_curr', $a2);
            }
            if ($v2 !== null && $a2 !== null) {
                $hub->SetVarFloat('bat2_power', $v2 * $a2);
            }
            $s2 = self::pct($mb->readFloat(self::OID_SOC2));
            if ($s2 !== null) {
                $hub->SetVarInt('bat2_soc', $s2);
            }
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupEnergy')) {
            $c  = $mb->readFloat(self::OID_E_CHARGE);
            $d  = $mb->readFloat(self::OID_E_DISCH);
            $pa = $mb->readFloat(self::OID_E_PV_A);
            $pb = $mb->readFloat(self::OID_E_PV_B);
            if ($c !== null) {
                $hub->SetVarFloat('e_charge_total', $c / 1000.0);
            }
            if ($d !== null) {
                $hub->SetVarFloat('e_disch_total', $d / 1000.0);
            }
            // Teilsumme wäre falsch: nur schreiben, wenn beide Generatoren geantwortet haben.
            if ($pa !== null && $pb !== null) {
                $hub->SetVarFloat('e_pv_total', ($pa + $pb) / 1000.0);
            }
        }
        if ($hub->GetPropBool('GroupBatTowers')) {
            $n = $this->readUInt($mb, self::OID_TOWER_COUNT);
            if ($n !== null && $n <= 16) {
                $hub->SetVarInt('bat_tower_count', $n);
            }
            $h1 = self::pct($mb->readFloat(self::OID_SOH1));
            if ($h1 !== null) {
                $hub->SetVarInt('bat_soh', $h1);
            }
            $h2 = self::pct($mb->readFloat(self::OID_SOH2));
            if ($h2 !== null) {
                $hub->SetVarInt('bat2_soh', $h2);
            }
        }
        if ($hub->GetPropBool('GroupBatState')) {
            $st = $this->readUInt($mb, self::OID_PM_STATE);
            if ($st !== null) {
                $hub->SetVarInt('bat_mode_state', $st);
            }
            $ef = $this->readUInt($mb, self::OID_EMPTY_FULL);
            if ($ef !== null) {
                $hub->SetVarBool('bat_was_empty', ($ef & 1) !== 0);
                $hub->SetVarBool('bat_was_full', ($ef & 2) !== 0);
            }
        }
        // Nur Anzeige: Dieser Treiber schreibt nichts an den Wechselrichter.
        if ($hub->GetPropBool('GroupControlState')) {
            $str = $this->readUInt($mb, self::OID_STRATEGY);
            if ($str !== null && $str <= 5) {
                $hub->SetVarInt('rct_strategy', $str);
                $hub->SetVarBool('rct_external_active', $str === 2);
            }
            $pe = $mb->readFloat(self::OID_POWER_EXTERN);
            if ($pe !== null) {
                $hub->SetVarFloat('rct_power_extern', $pe);
            }
            $ug = $this->readUInt($mb, self::OID_USE_GRID);
            if ($ug !== null) {
                $hub->SetVarBool('rct_use_grid', $ug !== 0);
            }
            $sm = self::pct($mb->readFloat(self::OID_SOC_MIN));
            if ($sm !== null) {
                $hub->SetVarInt('rct_soc_min', $sm);
            }
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupDevice')) {
            return;
        }
        $sn = $mb->readString(self::OID_SERIAL);
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $sn);
        }
    }

    // Stufe 1: nur Lesen. Dieser Treiber besitzt keine Steuervariablen.
    public function writeControl($mb, $hub, string $ident, $value)
    {
    }
}
