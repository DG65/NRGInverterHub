<?php

// ---------------------------------------------------------------------------
// IHUB_FoxEssSmartDriver — FoxESS H3 Smart / H3 Pro / KH (Gen2-Registerwelt).
// Eigener Treiber, weil diese Geraete NICHT die Registerbelegung 10000/11000
// (FC04, "Fox Hybrid/AC Modbus Protocol" V1.01) des IHUB_FoxEssDriver sprechen,
// sondern HOLDING-Register (FC03) im Bereich 37xxx/38xxx/39xxx. Fund aus
// einer Forum-Meldung (hbraun, 05.10.2026: H3 Smart wird nicht gefunden) und
// anschliessender Gegenpruefung an der Home-Assistant-Integration
// nathanmarlor/foxess_modbus (inverter_profiles.py: H3_SMART nutzt
// RegisterType.HOLDING; entity_descriptions.py: Adressen und Skalierung).
//
// READ-ONLY, bewusst klein gehalten und NOCH NICHT an echter Hardware bestaetigt.
// Nicht eingebaut: Steuer-/Konfigurationsregister (46xxx, Arbeitsmodus, Lade-
// grenzen), EPS/Ersatzstrom, Phasen-Einzelwerte, Fehlercodes (39067-39069).
// Vorbild: dieselbe Vorsicht wie beim Kerntreiber (SMA-FC03/FC04-Lehre).
//
// Wortreihenfolge der 32-Bit-Werte: HA liest die Adressliste "niederwertiges
// Wort zuerst" (modbus_controller.read, value |= val << i*16) und listet z. B.
// "holding=[39238, 39237]" - also liegt das HOCHWERTIGE Wort auf der
// KLEINEREN Adresse (39237). Das entspricht dem u32()/s32()-Helfer hier.
// Skalierung/Einheit: Leistungen laut HA in 0,001 kW = W; Netzmesspunkt (Grid CT)
// 0,0001 kW = 0,1 W; Energien 0,01 kWh; Spannungen/Stroeme/Temperaturen 0,1.
// Vorzeichen (HA): Grid CT positiv = Einspeisung, Batterie positiv = Entladen -
// passend zur InverterHub-Konvention (meter_total + = Einspeisung, bat_power + =
// Entladen).
// ---------------------------------------------------------------------------

class IHUB_FoxEssSmartDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        0 => 'Unknown', 1 => 'Standby', 2 => 'Grid operation', 3 => 'Island mode', 4 => 'Fault',
    ];

    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Operating status',    'I', 'FOXS.Status',     true,  'device', 'HR 39063 + 39065 (Bits)'],
            ['pv_total',  'PV total power', 'F', 'FOXS.Watt',       true,  'pv',     'Σ HR 39279/81/83/85 (I32)'],
            ['ac_power',  'AC active power',   'F', 'FOXS.Watt',       true,  'device', 'HR 39134 (I32)'],
            ['bat_power', 'Bat. power (+ discharging / − charging)', 'F', 'FOXS.Watt', true, 'bat', 'HR 39237 (I32)'],
            ['bat_soc',   'Bat. SOC',          'I', '~Battery.100',    true,  'bat',    'HR 37612'],
            ['meter_total', 'Grid power (+ feed-in / − import)', 'F', 'FOXS.Watt', true, 'grid', 'HR 38814 (I32, ÷10)'],
            ['house_load_total', 'House load power', 'F', 'FOXS.Watt', true, 'grid', 'HR 39225+39226 (I32, W)'],
        ];
    }

    public function getOptionalGroups()
    {
        $groups = [
            'GroupPV' => ['caption' => 'PV details (string 1-4)', 'vars' => [
                ['pv1_volt', 'PV1 voltage', 'F', 'FOXS.Volt',   false, 'pv', 'HR 39070 (÷10)'],
                ['pv1_curr', 'PV1 current',    'F', 'FOXS.Ampere', false, 'pv', 'HR 39071 (÷10)'],
                ['pv2_volt', 'PV2 voltage', 'F', 'FOXS.Volt',   false, 'pv', 'HR 39072 (÷10)'],
                ['pv2_curr', 'PV2 current',    'F', 'FOXS.Ampere', false, 'pv', 'HR 39073 (÷10)'],
                ['pv3_volt', 'PV3 voltage', 'F', 'FOXS.Volt',   false, 'pv', 'HR 39074 (÷10)'],
                ['pv3_curr', 'PV3 current',    'F', 'FOXS.Ampere', false, 'pv', 'HR 39075 (÷10)'],
                ['pv4_volt', 'PV4 voltage', 'F', 'FOXS.Volt',   false, 'pv', 'HR 39076 (÷10)'],
                ['pv4_curr', 'PV4 current',    'F', 'FOXS.Ampere', false, 'pv', 'HR 39077 (÷10)'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, frequency)', 'vars' => [
                ['grid_volt_r', 'Grid voltage L1', 'F', 'FOXS.Volt', false, 'grid', 'HR 39123 (÷10)'],
                ['grid_volt_s', 'Grid voltage L2', 'F', 'FOXS.Volt', false, 'grid', 'HR 39124 (÷10)'],
                ['grid_volt_t', 'Grid voltage L3', 'F', 'FOXS.Volt', false, 'grid', 'HR 39125 (÷10)'],
                ['grid_freq',   'Grid frequency',     'F', 'FOXS.Hertz', false, 'grid', 'HR 39139 (÷100)'],
            ]],
            'GroupBat' => ['caption' => 'Battery (voltage, current, temperature, health)', 'vars' => [
                ['bat_volt',   'Bat. voltage',    'F', 'FOXS.Volt',   false, 'bat', 'HR 37609 (÷10, BMS)'],
                ['bat_curr',   'Bat. current',       'F', 'FOXS.Ampere', false, 'bat', 'HR 37610 (I16, ÷10, BMS)'],
                ['bat_temp',   'Bat. temperature',  'F', '~Temperature', false, 'bat', 'HR 37611 (I16, ÷10, BMS)'],
                ['bat_soh',    'Bat. health (SOH)', 'I', '~Battery.100', false, 'bat', 'HR 37624'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp_inv', 'Inverter temperature', 'F', '~Temperature', false, 'device', 'HR 39141 (I16, ÷10)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (day/total)', 'vars' => [
                ['e_pv_total',     'PV total',             'F', '~Electricity', true, 'energy', 'HR 39601+39602 (U32, ÷100)'],
                ['e_pv_day',       'PV today',              'F', '~Electricity', true, 'energy', 'HR 39603+39604 (U32, ÷100)'],
                ['e_charge_total', 'Bat. charge total',     'F', '~Electricity', true, 'energy', 'HR 39605+39606 (U32, ÷100)'],
                ['e_charge_day',   'Bat. charge today',      'F', '~Electricity', true, 'energy', 'HR 39607+39608 (U32, ÷100)'],
                ['e_disch_total',  'Bat. discharge total',  'F', '~Electricity', true, 'energy', 'HR 39609+39610 (U32, ÷100)'],
                ['e_disch_day',    'Bat. discharge today',   'F', '~Electricity', true, 'energy', 'HR 39611+39612 (U32, ÷100)'],
                ['e_sell_total',   'Feed-in total',    'F', '~Electricity', true, 'energy', 'HR 39613+39614 (U32, ÷100)'],
                ['e_sell_day',     'Feed-in today',     'F', '~Electricity', true, 'energy', 'HR 39615+39616 (U32, ÷100)'],
                ['e_buy_total',    'Grid import total',      'F', '~Electricity', true, 'energy', 'HR 39617+39618 (U32, ÷100)'],
                ['e_buy_day',      'Grid import today',       'F', '~Electricity', true, 'energy', 'HR 39619+39620 (U32, ÷100)'],
                ['e_load_total',   'House consumption total',  'F', '~Electricity', true, 'energy', 'HR 39629+39630 (U32, ÷100)'],
                ['e_load_day',     'House consumption today',   'F', '~Electricity', true, 'energy', 'HR 39631+39632 (U32, ÷100)'],
            ]],
        ];
        // Fernsteuerung 46001 ff. (H3-Pro, H3-Smart), UNGETESTET: nur in dieser Gruppe, Vorgabe aus, gemeinsame Schicht IHUB_RemoteControl
        $groups['GroupControl'] = ['caption' => 'Remote control (untested: built from the manufacturer document only, off by default)', 'default' => false, 'vars' => IHUB_RemoteControl::vars()];
        return $groups;
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'FOXS.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -100000.0, 100000.0, 1.0,  0],
            'FOXS.Volt'   => [VARIABLETYPE_FLOAT, ' V',        0.0,   1500.0, 0.1,  1],
            'FOXS.Ampere' => [VARIABLETYPE_FLOAT, ' A',     -500.0,    500.0, 0.1,  1],
            'FOXS.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',      45.0,     65.0, 0.01, 2],
        ] + IHUB_RemoteControl::profiles();
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, $k === 4 ? 0xC0392B : 0x7A8A99];
        }
        return ['FOXS.Status' => $status] + IHUB_RemoteControl::enumProfiles();
    }

    // Liest einen zusammenhaengenden Holding-Block. Lehnt das Geraet den
    // breiten Block ab (manche Firmwares quittieren Bereiche mit reservierten
    // Registern mit einer Exception), wird derselbe Bereich in Stuecken zu je
    // 10 Registern wiederholt - alles-oder-nichts, damit keine Luecken als 0
    // missverstanden werden. Scheitert schon das erste Stueck, wird sofort
    // aufgegeben (kein Timeout-Stau bei einem Geraet, das gar nicht antwortet).
    private function readBlock($mb, $start, $count)
    {
        $regs = $mb->readHolding($start, $count);
        if ($regs !== null || $count <= 10) {
            return $regs;
        }
        $out = [];
        for ($off = 0; $off < $count; $off += 10) {
            $n     = min(10, $count - $off);
            $chunk = $mb->readHolding($start + $off, $n);
            if ($chunk === null) {
                return null;
            }
            foreach (array_values($chunk) as $i => $v) {
                $out[$off + $i] = $v;
            }
        }
        return $out;
    }

    public function readFast($mb, $hub)
    {
        // Betriebsstatus + PV-Spannungen/-Stroeme: 39063-39077 (15 Register).
        // Dieser Block entscheidet ueber "verbunden" - fehlt er, antwortet das
        // Geraet entweder nicht oder spricht diese Registerwelt nicht.
        $a  = $this->readBlock($mb, 39063, 15);
        $ok = ($a !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        // Status laut HA (ModbusG2InverterStateSensor): 39063 Bit 6 = Fehler,
        // Bit 2 = Betrieb, Bit 0 = Standby; 39065 Bit 0 = Inselbetrieb.
        $s1 = $mb->u16($a, 0);
        $s3 = $mb->u16($a, 2);
        if (($s1 & 0x40) !== 0) {
            $status = 4;
        } elseif (($s3 & 0x01) !== 0) {
            $status = 3;
        } elseif (($s1 & 0x04) !== 0) {
            $status = 2;
        } elseif (($s1 & 0x01) !== 0) {
            $status = 1;
        } else {
            $status = 0;
        }
        $hub->SetVarInt('status', $status);

        if ($hub->GetPropBool('GroupPV')) {
            for ($i = 1; $i <= 4; $i++) {
                $off = 7 + ($i - 1) * 2;   // 39070 + (i-1)*2
                $hub->SetVarFloat('pv' . $i . '_volt', $mb->u16($a, $off) / 10.0);
                $hub->SetVarFloat('pv' . $i . '_curr', $mb->s16($a, $off + 1) / 10.0);
            }
        }

        // Wechselrichter-/Netzblock 39123-39141 (19 Register).
        $b = $this->readBlock($mb, 39123, 19);
        if ($b !== null) {
            $hub->SetVarFloat('ac_power', (float)$mb->s32($b, 11));   // 39134/39135
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_volt_r', $mb->u16($b, 0) / 10.0);   // 39123
                $hub->SetVarFloat('grid_volt_s', $mb->u16($b, 1) / 10.0);   // 39124
                $hub->SetVarFloat('grid_volt_t', $mb->u16($b, 2) / 10.0);   // 39125
                $hub->SetVarFloat('grid_freq',   $mb->u16($b, 16) / 100.0); // 39139
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_inv', $mb->s16($b, 18) / 10.0);     // 39141
            }
        }

        // Leistungsblock 39201-39286 (86 Register): Hausleistung (39225/26), Batterieleistung (39237/38)
        // und die vier PV-String-Leistungen (39279/80, 81/82, 83/84, 85/86).
        $c = $this->readBlock($mb, 39201, 86);
        if ($c !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s32($c, 36));      // 39237/39238
            // Hausleistung (load_power der Home-Assistant-Integration, H3 Pro/Smart): 39225/39226, I32, W.
            // Aus dem Open-Source-Code uebernommen, am Geraet noch nicht bestaetigt (16.11.2026).
            $hub->SetVarFloat('house_load_total', (float)$mb->s32($c, 24));
            // Je String kann der Wert ohne angeschlossene Module negativ werden
            // (HA: max(x, 0)) - sonst ergaebe die Summe Unsinn.
            $pv = 0.0;
            foreach ([78, 80, 82, 84] as $off) {
                $pv += max(0.0, (float)$mb->s32($c, $off));
            }
            $hub->SetVarFloat('pv_total', $pv);
        }

        // Netzmesspunkt (Grid CT) 38814/38815, I32, Einheit 0,1 W.
        $m = $mb->readHolding(38814, 2);
        if ($m !== null) {
            $hub->SetVarFloat('meter_total', $mb->s32($m, 0) / 10.0);
        }

        // BMS Batterie 1: 37609-37620 (12 Register) - HA liest diesen Bereich
        // ausdruecklich einzeln (individual_read_register_ranges), nicht
        // zusammen mit Nachbarbereichen.
        $d = $mb->readHolding(37609, 12);
        if ($d !== null) {
            $hub->SetVarInt('bat_soc', $mb->u16($d, 3));                  // 37612
            if ($hub->GetPropBool('GroupBat')) {
                $hub->SetVarFloat('bat_volt', $mb->u16($d, 0) / 10.0);    // 37609
                $hub->SetVarFloat('bat_curr', $mb->s16($d, 1) / 10.0);    // 37610
                $hub->SetVarFloat('bat_temp', $mb->s16($d, 2) / 10.0);    // 37611
            }
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            // 39601-39632 (32 Register), U32 hochwertiges Wort zuerst, Einheit 0,01 kWh.
            $e = $this->readBlock($mb, 39601, 32);
            if ($e !== null) {
                $map = [
                    'e_pv_total' => 0,     'e_pv_day' => 2,
                    'e_charge_total' => 4, 'e_charge_day' => 6,
                    'e_disch_total' => 8,  'e_disch_day' => 10,
                    'e_sell_total' => 12,  'e_sell_day' => 14,
                    'e_buy_total' => 16,   'e_buy_day' => 18,
                    'e_load_total' => 28,  'e_load_day' => 30,
                ];
                foreach ($map as $ident => $off) {
                    $hub->SetVarFloat($ident, $mb->u32($e, $off) / 100.0);
                }
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupControl')) {
            IHUB_RemoteControl::refresh(new IHUB_RemoteFoxEss(), $mb, $hub);   // Fernsteuerung 46001 ff.: Fähigkeit und Kennzeichnung
        }
        // Batterie-Gesundheit (SOH) aendert sich langsam; HA liest 37624 einzeln.
        if ($hub->GetPropBool('GroupBat')) {
            $h = $mb->readHolding(37624, 1);
            if ($h !== null) {
                $hub->SetVarInt('bat_soh', $mb->u16($h, 0));
            }
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Keine Geraeteinformationsregister in dieser Ausbaustufe (HA-Quelle
        // liefert fuer das Smart-Profil keinen belegten Textbereich).
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Nur die gemeinsame Fernsteuerung (46001 ff.), UNGETESTET (rc_tested = false); keine weiteren Schreibwege.
        if (strpos($ident, 'ctl_rc_') === 0) {
            IHUB_RemoteControl::control(new IHUB_RemoteFoxEss(), $mb, $hub, $ident, $value);
        }
    }
}
