<?php

// ---------------------------------------------------------------------------
// IHUB_SolarEdgeDriver — SolarEdge-Wechselrichter über SunSpec. Registeradressen
// sind bei SolarEdge in der Praxis stabil (Common Block ab 40000), es wird
// trotzdem dieselbe Laufzeit-Discovery wie bei Fronius/SMA verwendet — das
// funktioniert für sowohl feste als auch dynamische Layouts gleichermaßen.
// Registerauswahl/-offsets 2026-07-16 gegen eine community-getestete
// SolarEdge-Modbus-Vorlage aus dem IP-Symcon-Forum verifiziert.
// ---------------------------------------------------------------------------

class IHUB_SolarEdgeDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        1 => 'Off', 2 => 'Auto shutdown', 3 => 'Starting', 4 => 'Normal (MPPT)',
        5 => 'Power reduction', 6 => 'Shutting down', 7 => 'Fault', 8 => 'Standby',
    ];

    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002;
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null;
            }
            if ($modelId === $wantedModelId) {
                return [$addr + 2, $len];
            }
            $addr += 2 + $len;
        }
        return null;
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Operating status',    'I', 'SLE.Status',      true,  'device', 'SunSpec St'],
            ['ac_power',  'AC active power',   'F', 'SLE.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
            ['pv_total',  'PV total power', 'F', 'SLE.Watt',        true,  'pv',     'SunSpec DCW (Model 101/103)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Grid (voltage, current, frequency)', 'vars' => [
                ['grid_volt', 'Grid voltage',  'F', 'SLE.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Grid current',     'F', 'SLE.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Grid frequency',   'F', 'SLE.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (total yield)', 'vars' => [
                ['e_total', 'Yield total', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp_cab', 'Heat sink temperature', 'F', '~Temperature', false, 'device', 'SunSpec TmpSnk (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (power)', 'vars' => [
                ['meter_total', 'Grid power (meter)', 'F', 'SLE.Watt', true, 'meter', 'SunSpec Meter Model 201/203'],
            ]],
            'GroupMeterEnergy' => ['caption' => 'Meter energy (import / feed-in)', 'vars' => [
                ['meter_imp', 'Import total',       'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhImp (Offset 44)'],
                ['meter_exp', 'Feed-in total', 'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhExp (Offset 36)'],
            ]],
            'GroupBattery' => ['caption' => 'Battery (StorEdge)', 'vars' => [
                ['bat_soc',    'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'SE 0xE184 (Float32, LE)'],
                ['bat_power',  'Bat. power',   'F', 'SLE.Watt',     true,  'bat', 'SE 0xE174 (Float32, LE)'],
                ['bat_volt',   'Bat. voltage',   'F', 'SLE.Volt',     false, 'bat', 'SE 0xE170 (Float32, LE)'],
                ['bat_curr',   'Bat. current',      'F', 'SLE.Ampere',   false, 'bat', 'SE 0xE172 (Float32, LE)'],
                ['bat_temp',   'Bat. temperature', 'F', '~Temperature', false, 'bat', 'SE 0xE16C (Float32, LE)'],
                ['bat_soh',    'Bat. SOH',        'I', '~Battery.100', true,  'bat', 'SE 0xF582 (Float32, LE)'],
                ['bat_status', 'Bat. state',    'I', 'SLE.BatState', true,  'bat', 'SE 0xE186'],
            ]],
            'GroupPvReal' => ['caption' => 'PV generation calculated (StorEdge; requires battery group)', 'vars' => [
                ['pv_real', 'PV generation (calculated)', 'F', 'SLE.Watt', true, 'pv', 'PV total + battery power, ≥ 0'],
            ]],
            'GroupDevice' => ['caption' => 'Device information', 'vars' => [
                ['dev_model', 'Model', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Serial number', 'S', '', false, 'device', 'SunSpec Common Block'],
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
            'SLE.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLE.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLE.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'SLE.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        // SolarEdge StorEdge Speicherstatus (Reg 0xE186).
        $batState = [
            1 => ['Off',        0x7A8A99],
            3 => ['Charge',      0x3BA55D],
            4 => ['Discharge',   0xE08A2B],
            6 => ['Sleep mode',  0x7A8A99],
        ];
        return ['SLE.Status' => $status, 'SLE.BatState' => $batState];
    }

    public function readFast($mb, $hub)
    {
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }

        $ok = ($inv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        [$base, $len] = $inv;
        $blk = $mb->readHolding($base, min($len, 60));
        if ($blk === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // Für die berechnete PV-Erzeugung (StorEdge-Eigenart, s. u.) merken wir
        // uns PV-Gesamtleistung und Batterieleistung.
        $pvTotalVal = 0.0;
        $batPowerVal = 0.0;

        // Offsets siehe IHUB_FroniusDriver/IHUB_SmaDriver (identische SunSpec-Modelle),
        // zusätzlich gegen eine reale SolarEdge-Registertabelle verifiziert.
        if ($isFloat) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($blk, 20));
            $hub->SetVarInt('status', $mb->u16($blk, 46));
            $pvTotalVal = $mb->readFloat32($blk, 36);
            $hub->SetVarFloat('pv_total', $pvTotalVal);
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->readFloat32($blk, 40));
            }
        } else {
            // SunSpec-Integer-Modell 103: Jeder Messwert ist ein int16/uint16
            // mit separatem Skalierungsfaktor-Register (sunssf, 10^SF). SolarEdge
            // liefert z. B. Spannung/Strom mit SF -1 - ohne Anwenden erscheinen
            // sie um Faktor 10 zu groß (225 V -> 2250 V, 28,9 A -> 289 A).
            $hub->SetVarFloat('ac_power', $mb->s16($blk, 12) * $this->sf($mb, $blk, 13));
            $hub->SetVarInt('status', $mb->u16($blk, 36));
            $pvTotalVal = $mb->s16($blk, 29) * $this->sf($mb, $blk, 30);
            $hub->SetVarFloat('pv_total', $pvTotalVal);
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->u16($blk, 0) * $this->sf($mb, $blk, 4));
                $hub->SetVarFloat('grid_volt', $mb->u16($blk, 8) * $this->sf($mb, $blk, 11));
                $hub->SetVarFloat('grid_freq', $mb->u16($blk, 14) * $this->sf($mb, $blk, 15));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->u32($blk, 22) * $this->sf($mb, $blk, 24) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->s16($blk, 32) * $this->sf($mb, $blk, 35));
            }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $this->findModel($mb, 201) ?: $this->findModel($mb, 203) ?: $this->findModel($mb, 211) ?: $this->findModel($mb, 213);
            if ($meter !== null) {
                [$mtbase, $mtlen] = $meter;
                $mtblk = $mb->readHolding($mtbase, min($mtlen, 54));
                if ($mtblk !== null) {
                    // Zähler-Modell 20x: W (gesamt) bei Offset 16, W_SF bei 20.
                    $hub->SetVarFloat('meter_total', $mb->s16($mtblk, 16) * $this->sf($mb, $mtblk, 20));
                    // Energie-Zähler: TotWhExp bei Offset 36, TotWhImp bei 44,
                    // gemeinsamer TotWh_SF bei 52 (uint32, in Wh -> kWh /1000).
                    if ($hub->GetPropBool('GroupMeterEnergy')) {
                        $whsf = $this->sf($mb, $mtblk, 52);
                        $hub->SetVarFloat('meter_exp', $mb->u32($mtblk, 36) * $whsf / 1000.0);
                        $hub->SetVarFloat('meter_imp', $mb->u32($mtblk, 44) * $whsf / 1000.0);
                    }
                }
            }
        }

        if ($hub->GetPropBool('GroupBattery')) {
            // SolarEdge StorEdge: Batterie-1-Block ab 0xE100 (57600). Die
            // Float32-Momentanwerte liegen im Bereich 0xE16C..0xE184 und sind -
            // anders als der SunSpec-Inverter-Block (ABCD) - little-endian
            // (CDAB, Wort-Swap). Daher hier gezielt umschalten.
            $mb->setFloatWordSwap(true);
            $bat = $mb->readHolding(57708, 28); // 0xE16C .. 0xE187 (inkl. Status)
            if ($bat !== null) {
                $hub->SetVarFloat('bat_temp', $mb->readFloat32($bat, 0));   // 0xE16C
                $hub->SetVarFloat('bat_volt', $mb->readFloat32($bat, 4));   // 0xE170
                // Vorzeichen: SolarEdge meldet Batterie-Strom/-Leistung mit
                // + = Laden (0xE172/0xE174). Modul-Konvention ist + = Entladen /
                // − = Laden (damit die Kachel-Flussrichtung stimmt) -> negieren.
                $hub->SetVarFloat('bat_curr', -$mb->readFloat32($bat, 6));  // 0xE172
                $batChargeW  = $mb->readFloat32($bat, 8);                   // 0xE174 (+ = Laden)
                $batPowerVal = -$batChargeW;                               // + = Entladen
                $hub->SetVarFloat('bat_power', $batPowerVal);
                // -3.4028235E+38 (kleinster Float32) heisst bei SolarEdge "Register nicht belegt"
                // (Forum-Beta-Tester somm, 21.09.2026): (int) davon erzeugt eine PHP-Warnung und
                // einen Zufallswert - dann gar nicht schreiben, der alte Stand bleibt stehen.
                $socRaw = $mb->readFloat32($bat, 24); // 0xE184
                if (abs($socRaw) < 1.0e9) {
                    $hub->SetVarInt('bat_soc', (int)round($socRaw));
                }
                // Speicherstatus (0xE186, uint32): Wert 1-6 liegt im niederw.
                // Wort, das bei CDAB zuerst kommt -> u16 am Offset 26.
                $hub->SetVarInt('bat_status', $mb->u16($bat, 26));          // 0xE186

                // Berechnete PV-Erzeugung (optional). StorEdge-Eigenart: Das
                // DC-Leistungsregister spiegelt bei Batteriebetrieb nicht die
                // reine PV-Erzeugung wider. Formel des Testers (am realen Gerät
                // bewährt): PV-Erzeugung = PV-Gesamtleistung + Batterie-
                // Ladeleistung, nie negativ. Benötigt die aktive Batteriegruppe.
                if ($hub->GetPropBool('GroupPvReal')) {
                    $hub->SetVarFloat('pv_real', max(0.0, $pvTotalVal + $batChargeW));
                }
            }
            // SOH liegt in einem separaten Block (0xF582 = 62850), Float32 CDAB.
            $soh = $mb->readHolding(62850, 2);
            if ($soh !== null) {
                $sohRaw = $mb->readFloat32($soh, 0);
                if (abs($sohRaw) < 1.0e9) {
                    $hub->SetVarInt('bat_soh', (int)round($sohRaw));
                }
            }
            $mb->setFloatWordSwap(false);
        }

        return true;
    }

    // 10^SF aus einem SunSpec-Skalierungsfaktor-Register. Nicht implementierte
    // SF liefern den Sentinel 0x8000 (-32768) bzw. unplausible Werte - dann 1.0.
    private function sf($mb, $blk, $off)
    {
        $e = $mb->s16($blk, $off);
        if ($e < -10 || $e > 10) {
            return 1.0;
        }
        return pow(10, $e);
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $common = $this->findModel($mb, 1);
        if ($common === null) {
            return;
        }
        [$base, $len] = $common;
        $blk = $mb->readHolding($base, min($len, 66));
        if ($blk === null) {
            return;
        }
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}
