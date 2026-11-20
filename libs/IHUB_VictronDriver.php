<?php

// ---------------------------------------------------------------------------
// IHUB_VictronDriver — Victron GX (Cerbo/Venus OS) über Modbus TCP. Anders als bei
// Einzel-Wechselrichtern ist die Unit-ID hier ein Geräte-Selektor: Der Dienst
// com.victronenergy.system liegt IMMER auf Unit-ID 100 und aggregiert die
// Anlagenwerte (PV, Netz, Batterie, Verbrauch). Wir sprechen daher fest Unit
// 100 an, unabhängig von der im Formular gesetzten Unit-ID. Register verbatim
// aus Victrons offizieller attributes.csv (dbus_modbustcp), Big-Endian.
// ---------------------------------------------------------------------------

class IHUB_VictronDriver implements IHUB_InverterDriverInterface
{
    const UNIT_SYSTEM = 100;

    const BAT_STATE = [
        0 => 'Sleep', 1 => 'Charge', 2 => 'Discharge',
    ];
    const GRID_SOURCE = [
        0 => 'Not available', 1 => 'Grid', 2 => 'Generator',
        3 => 'Shore power', 240 => 'Not connected',
    ];

    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV total power', 'F', 'VIC.Watt',        true,  'pv',     'DC-PV 850 + AC-PV 808..816'],
            ['ac_power',  'AC consumption (house)', 'F', 'VIC.Watt',      true,  'device', 'Σ AC Consumption 817..819'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Grid (power, source)', 'vars' => [
                ['meter_total', 'Grid power (+ feed-in / − import)', 'F', 'VIC.Watt', true, 'grid', 'Σ Grid 820..822 (int16)'],
                ['grid_source', 'Active grid source', 'I', 'VIC.GridSrc', true, 'grid', 'Reg 826'],
            ]],
            'GroupBattery' => ['caption' => 'Battery', 'vars' => [
                ['bat_soc',   'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'Reg 843'],
                ['bat_power', 'Bat. power (+ discharging / − charging)', 'F', 'VIC.Watt', true, 'bat', 'Reg 842 (int16)'],
                ['bat_volt',  'Bat. voltage',   'F', 'VIC.Volt',    false, 'bat', 'Reg 840 (÷10)'],
                ['bat_curr',  'Bat. current',      'F', 'VIC.Ampere',  false, 'bat', 'Reg 841 (int16, ÷10)'],
                ['bat_state', 'Bat. state',    'I', 'VIC.BatState', true,  'bat', 'Reg 844'],
            ]],
            'GroupPvDetail' => ['caption' => 'PV details (DC / AC-coupled)', 'vars' => [
                ['pv_dc',  'PV DC-coupled (MPPT)',  'F', 'VIC.Watt', true, 'pv', 'Reg 850'],
                ['pv_ac',  'PV AC-coupled (Σ)',     'F', 'VIC.Watt', true, 'pv', 'Σ 808..816'],
            ]],
            // Zählerstände. WICHTIG: Der Systemdienst (Unit 100) führt KEINE
            // Energiezähler, nur Momentanleistungen. Netzbezug/-einspeisung
            // liegen auf dem eigenen Dienst com.victronenergy.grid mit eigener
            // Unit-ID (unten einzutragen); der Solarertrag kommt von den
            // Solarladereglern und nutzt deren MPPT-Unit-IDs mit.
            'GroupEnergy' => ['caption' => 'Energy meters (grid import/export, solar yield) — enter the unit ID of the grid meter below', 'vars' => [
                ['meter_imp',  'Grid import total',   'F', '~Electricity', true, 'energy', 'Σ Reg 2622/2624/2626 (u32, ÷100)'],
                ['meter_exp',  'Feed-in total', 'F', '~Electricity', true, 'energy', 'Σ Reg 2628/2630/2632 (u32, ÷100)'],
                ['e_pv_total', 'Solar yield total', 'F', '~Electricity', true, 'energy', 'Σ Laderegler Reg 790 (÷10)'],
                ['e_pv_day',   'Solar yield today',  'F', '~Electricity', true, 'energy', 'Σ Laderegler Reg 784 (÷10)'],
                // Einzelwerte je Solarladeregler. Auf Anregung von Beta-Tester
                // loerdy: Sie sind für sich interessant und machen zugleich einen
                // Zählerüberlauf sichtbar, statt ihn in der Summe zu verstecken.
                ['mppt1_e_day',   'MPPT 1 yield today',  'F', '~Electricity', true, 'energy', 'Laderegler 1, Reg 784 (÷10)'],
                ['mppt1_e_total', 'MPPT 1 yield total', 'F', '~Electricity', true, 'energy', 'Laderegler 1, Reg 790 (÷10)'],
                ['mppt2_e_day',   'MPPT 2 yield today',  'F', '~Electricity', true, 'energy', 'Laderegler 2, Reg 784 (÷10)'],
                ['mppt2_e_total', 'MPPT 2 yield total', 'F', '~Electricity', true, 'energy', 'Laderegler 2, Reg 790 (÷10)'],
                ['mppt3_e_day',   'MPPT 3 yield today',  'F', '~Electricity', true, 'energy', 'Laderegler 3, Reg 784 (÷10)'],
                ['mppt3_e_total', 'MPPT 3 yield total', 'F', '~Electricity', true, 'energy', 'Laderegler 3, Reg 790 (÷10)'],
                ['mppt4_e_day',   'MPPT 4 yield today',  'F', '~Electricity', true, 'energy', 'Laderegler 4, Reg 784 (÷10)'],
                ['mppt4_e_total', 'MPPT 4 yield total', 'F', '~Electricity', true, 'energy', 'Laderegler 4, Reg 790 (÷10)'],
            ]],
            'GroupMppt' => ['caption' => 'PV per solar charge controller / MPPT (enter unit IDs below)', 'vars' => [
                ['mppt1_power', 'MPPT 1 power', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt1_volt',  'MPPT 1 voltage', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt1_state', 'MPPT 1 state',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
                ['mppt2_power', 'MPPT 2 power', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt2_volt',  'MPPT 2 voltage', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt2_state', 'MPPT 2 state',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
                ['mppt3_power', 'MPPT 3 power', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt3_volt',  'MPPT 3 voltage', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt3_state', 'MPPT 3 state',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
                ['mppt4_power', 'MPPT 4 power', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt4_volt',  'MPPT 4 voltage', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt4_state', 'MPPT 4 state',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
            ]],
        ] + [
            // Fernsteuerung (Netzsollwert-Override 2716, nur Modus 3 und 0), UNGETESTET: Vorgabe aus, gemeinsame Schicht IHUB_RemoteControl
            'GroupRemote' => ['caption' => 'Remote control of the grid setpoint (untested: built from the Victron register list and community reports, off by default)', 'default' => false, 'vars' => IHUB_RemoteControl::vars()],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'VIC.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -100000.0, 100000.0, 1.0, 0],
            'VIC.Volt'   => [VARIABLETYPE_FLOAT, ' V',        0.0,   1000.0, 0.1, 1],
            'VIC.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -1000.0,   1000.0, 0.1, 1],
        ] + IHUB_RemoteControl::profiles();
    }

    public function getEnumProfiles()
    {
        $bat = [];
        foreach (self::BAT_STATE as $k => $label) {
            $bat[$k] = [$label, 0x7A8A99];
        }
        $src = [];
        foreach (self::GRID_SOURCE as $k => $label) {
            $src[$k] = [$label, 0x7A8A99];
        }
        $chg = [
            0 => ['Off', 0x7A8A99], 2 => ['Fault', 0xC0392B], 3 => ['Bulk', 0x3BA55D],
            4 => ['Absorption', 0x3BA55D], 5 => ['Float', 0x3BA55D], 6 => ['Storage', 0x7A8A99],
            7 => ['Equalization', 0xE08A2B], 11 => ['Hub-1', 0x7A8A99], 252 => ['Hub-1', 0x7A8A99],
        ];
        return ['VIC.BatState' => $bat, 'VIC.GridSrc' => $src, 'VIC.ChgState' => $chg] + IHUB_RemoteControl::enumProfiles();
    }

    public function readFast($mb, $hub)
    {
        // Victron: Systemdienst ist immer Unit-ID 100 (Geräte-Selektor).
        $mb->unitId = self::UNIT_SYSTEM;

        // AC-Block 808..826 (19 Register) in einem Zug.
        $ac = $mb->readHolding(808, 19);
        $ok = ($ac !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        // AC-gekoppelte PV (Output/Grid/Genset L1..L3), alle uint16 W.
        $pvAc = 0.0;
        for ($o = 0; $o <= 8; $o++) {
            $pvAc += $mb->u16($ac, $o);
        }
        // Hausverbrauch = Σ AC Consumption L1..L3 (Offsets 9..11).
        $cons = $mb->u16($ac, 9) + $mb->u16($ac, 10) + $mb->u16($ac, 11);
        // Netzleistung = Σ Grid L1..L3 (Offsets 12..14, int16). Victron: + =
        // Bezug. Modul-Konvention Meter: + = Einspeisung -> negieren.
        $grid = $mb->s16($ac, 12) + $mb->s16($ac, 13) + $mb->s16($ac, 14);

        $hub->SetVarFloat('ac_power', (float)$cons);

        // DC-gekoppelte PV (Reg 850) und den Batterieblock (840..844) GETRENNT
        // lesen: Zwischen 844 und 850 liegen reservierte Register (845..849),
        // und manche GX-Firmwares quittieren einen Block, der reservierte oder
        // nicht belegte Register überspannt, mit einer Modbus-Exception - dann
        // scheiterte bisher der gesamte Batterie-/PV-Block (real gemeldet:
        // nur Netz kam an, PV/Batterie blieben leer).
        $pvDcBlk = $mb->readHolding(850, 1);
        $pvDc = ($pvDcBlk !== null) ? (float)$mb->u16($pvDcBlk, 0) : 0.0;
        $hub->SetVarFloat('pv_total', $pvAc + $pvDc);

        // Batterieblock 840..844 (V, A, W, SOC, State) - ohne reservierte Register.
        $bat = $mb->readHolding(840, 5);

        if ($hub->GetPropBool('GroupPvDetail')) {
            $hub->SetVarFloat('pv_dc', $pvDc);
            $hub->SetVarFloat('pv_ac', $pvAc);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('meter_total', (float)(-$grid));
            $hub->SetVarInt('grid_source', $mb->s16($ac, 18)); // Reg 826
        }

        if ($hub->GetPropBool('GroupBattery') && $bat !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 0) / 10.0);  // 840
            // Strom wie Leistung auf Modul-Konvention (+ = Entladen) negieren.
            $hub->SetVarFloat('bat_curr', -$mb->s16($bat, 1) / 10.0); // 841
            // Reg 842: Victron + = Laden. Modul-Konvention + = Entladen -> negieren.
            $hub->SetVarFloat('bat_power', (float)(-$mb->s16($bat, 2))); // 842
            $hub->SetVarInt('bat_soc', $mb->u16($bat, 3));            // 843
            $hub->SetVarInt('bat_state', $mb->u16($bat, 4));         // 844
        }

        // Einzelne Solarladeregler (MPPT): jeder ist ein eigenes Modbus-Gerät
        // mit eigener Unit-ID (im GX unter Modbus-Diensten ablesbar). Je Regler
        // Leistung (789 ÷10), Spannung (776 ÷100) und Zustand (775).
        if ($hub->GetPropBool('GroupMppt')) {
            $ids = $hub->GetVictronMpptUnitIds();
            for ($i = 0; $i < 4; $i++) {
                $n = $i + 1;
                if (!isset($ids[$i]) || $ids[$i] <= 0) {
                    continue;
                }
                $mb->unitId = $ids[$i];
                $p = $mb->readHolding(789, 1);
                $v = $mb->readHolding(776, 1);
                $s = $mb->readHolding(775, 1);
                if ($p !== null) { $hub->SetVarFloat('mppt' . $n . '_power', $mb->u16($p, 0) / 10.0); }
                if ($v !== null) { $hub->SetVarFloat('mppt' . $n . '_volt',  $mb->u16($v, 0) / 100.0); }
                if ($s !== null) { $hub->SetVarInt('mppt' . $n . '_state',   $mb->u16($s, 0)); }
            }
            $mb->unitId = self::UNIT_SYSTEM;
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupRemote')) {
            $keep = $mb->unitId;
            $mb->unitId = self::UNIT_SYSTEM;
            IHUB_RemoteControl::refresh($this->remoteAdapter($hub), $mb, $hub);   // Fernsteuerung: Fähigkeit (Register 2716) und Kennzeichnung
            $mb->unitId = $keep;
        }
        // Zählerstände. Der Systemdienst (Unit 100) führt KEINE Energiezähler -
        // nur Momentanleistungen. Die Zähler liegen auf eigenen Diensten:
        //   com.victronenergy.grid          -> eigene Unit-ID (Netzzähler)
        //   com.victronenergy.solarcharger  -> die MPPT-Unit-IDs
        // Deshalb wird hier die Unit-ID gewechselt und am Ende zurückgesetzt.
        if (!$hub->GroupEnabled('GroupEnergy')) {
            return;
        }
        $restore = $mb->unitId;

        // --- Netzbezug / Einspeisung -------------------------------------
        // Bewusst die 32-Bit-Register 2622..2632 statt der 16-Bit-Variante
        // 2603..2608: Letztere laufen bei ÷100 schon nach 655,35 kWh über und
        // sind als Lebenszähler unbrauchbar.
        $gridUnit = $hub->GetVictronGridUnitId();
        if ($gridUnit > 0) {
            $mb->unitId = $gridUnit;
            $imp = 0.0; $exp = 0.0; $ok = false;
            foreach ([2622, 2624, 2626] as $reg) {
                $r = $mb->readHolding($reg, 2);
                if ($r !== null) { $imp += $mb->u32($r, 0) / 100.0; $ok = true; }
            }
            foreach ([2628, 2630, 2632] as $reg) {
                $r = $mb->readHolding($reg, 2);
                if ($r !== null) { $exp += $mb->u32($r, 0) / 100.0; $ok = true; }
            }
            if ($ok) {
                $hub->SetVarFloat('meter_imp', $imp);
                $hub->SetVarFloat('meter_exp', $exp);
            }
        }

        // --- Solarertrag je Laderegler ------------------------------------
        // ACHTUNG: Beide Register sind nur 16 Bit; für die Solarladeregler gibt
        // es KEINE 32-Bit-Variante und kein /Yield/System. Yield/User (790)
        // läuft daher bei ÷10 nach 6553,5 kWh über und zählt wieder bei null an.
        // Bei einem Beta-Tester bestätigt: zwei Regler mit 10616,55 und 9628,06
        // kWh lieferten 4063,0 + 3074,5 = 7137,5 statt 20244,6 kWh.
        //
        // Optional korrigiert das Modul das (siehe YieldCorrected()): Die Zahl
        // der bisherigen Überläufe wird einmalig aus einem vom Nutzer
        // eingetragenen Ist-Wert geeicht und danach laufend fortgezählt.
        $ids = $hub->GetVictronMpptUnitIds();
        if (count($ids) > 0) {
            $total = 0.0; $day = 0.0; $any = false;
            foreach ($ids as $i => $id) {
                $mb->unitId = $id;
                // Gesamtertrag bevorzugt aus dem 32-BIT-Register 3728 lesen.
                // Von Beta-Tester loerdy am Geraet nachgewiesen: Es liefert den
                // echten Lebensdauer-Ertrag (bei ihm 10617 kWh), waehrend das
                // 16-Bit-Register 790 bei /10 nach 6553,5 kWh ueberlaeuft und
                // denselben Zaehler nur verstuemmelt zeigt (40631 -> 4063,1).
                // Manche Geraete/Firmwares bieten 3728 nur ueber FC04 an, daher
                // erst Holding, dann Input. Erst wenn beides fehlt, greift der
                // Rueckfall auf 790 samt Ueberlaufkorrektur.
                $t32 = $mb->readHolding(3728, 2);
                if ($t32 === null) { $t32 = $mb->readInput(3728, 2); }
                $t = ($t32 === null) ? $mb->readHolding(790, 1) : null;
                $d = $mb->readHolding(784, 1);
                $n = $i + 1;
                if ($t32 !== null) {
                    // ACHTUNG, anderer Massstab als Reg 790: 3728 zaehlt in
                    // GANZEN kWh (loerdys Geraet: Rohwert 10617 = 10617 kWh),
                    // waehrend 790 in 0,1 kWh zaehlt. Hier also NICHT teilen.
                    $kwh = (float) $mb->u32($t32, 0);
                    $total += $kwh; $any = true;
                    $hub->SetVarFloat('mppt' . $n . '_e_total', $kwh);
                } elseif ($t !== null) {
                    $kwh = $hub->YieldCorrected($id, $i, (int) $t[0]);
                    $total += $kwh; $any = true;
                    $hub->SetVarFloat('mppt' . $n . '_e_total', $kwh);
                }
                if ($d !== null) {
                    $dayKwh = $d[0] / 10.0;
                    $day += $dayKwh; $any = true;
                    $hub->SetVarFloat('mppt' . $n . '_e_day', $dayKwh);
                }
            }
            if ($any) {
                $hub->SetVarFloat('e_pv_total', $total);
                $hub->SetVarFloat('e_pv_day',   $day);
            }
            $hub->FlushYieldState();
        }

        $mb->unitId = $restore;
    }

    /** Adapter der Fernsteuerung; mit Brücke und Portal-ID an der Instanz laufen die Overrides über MQTT (seit 0.97.0). */
    protected function remoteAdapter($hub): IHUB_RemoteVictron
    {
        $a = new IHUB_RemoteVictron();
        $portal = trim((string)$hub->GetPropStr('VictronPortalID'));
        if ($portal !== '' && (int)$hub->GetPropInt('VictronMqttBridgeID') > 0) {
            $a->portal = $portal;
            $a->publisher = function (string $topic, string $payload) use ($hub): bool { return (bool)$hub->VictronMqttPublish($topic, $payload); };
        }
        return $a;
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Seriennummer/Modell optional - Systemdienst hat keinen Klarnamen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Nur die gemeinsame Fernsteuerung (Netzsollwert-Override 2716 auf dem Systemdienst, Unit 100), UNGETESTET; keine weiteren Schreibwege.
        if (strpos($ident, 'ctl_rc_') === 0) {
            $keep = $mb->unitId;
            $mb->unitId = self::UNIT_SYSTEM;
            try {
                IHUB_RemoteControl::control($this->remoteAdapter($hub), $mb, $hub, $ident, $value);
            } finally {
                $mb->unitId = $keep;
            }
        }
    }
}
