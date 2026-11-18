<?php

// ---------------------------------------------------------------------------
// IHUB_FroniusDriver — Fronius SunSpec-Modbus (Datamanager). Reine SunSpec-
// Implementierung mit DYNAMISCHEN Registeradressen: Modelle werden zur
// Laufzeit ab Basisregister 40000 durchlaufen (Model-ID + Länge), keine
// festen Adressen. Adressen werden pro Zyklus neu ermittelt und im Attribut
// gecacht, da sie laut Fronius-Doku über Firmware-Versionen hinweg nicht
// garantiert stabil sind.
// ---------------------------------------------------------------------------

class IHUB_FroniusDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        1 => 'Off', 2 => 'Auto shutdown', 3 => 'Starting', 4 => 'Normal (MPPT)',
        5 => 'Power reduction', 6 => 'Shutting down', 7 => 'Fault', 8 => 'Standby',
    ];

    // Sucht ab Basisregister 40000 (SunSpec "SunS"-Marker + Common Block) nach
    // dem gewünschten Model und gibt [Startadresse Nutzdaten, Länge] zurück.
    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002; // hinter dem 2-Register-Common-Marker "SunS"
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null; // End Block erreicht
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
            ['status',    'Operating status',    'I', 'FRO.Status',      true,  'device', 'SunSpec St'],
            ['pv_total',  'PV total power', 'F', 'FRO.Watt',        true,  'pv',     'SunSpec DCW (Model 160)'],
            ['ac_power',  'AC active power',   'F', 'FRO.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV details (MPPT according to Multiple MPPT Extension Model 160)', 'vars' => [
                ['mppt1_volt',  'MPPT1 voltage', 'F', 'FRO.Volt',   false, 'pv', 'SunSpec Model 160 DCV'],
                ['mppt1_curr',  'MPPT1 current',    'F', 'FRO.Ampere', false, 'pv', 'SunSpec Model 160 DCA'],
                ['mppt1_power','MPPT1 power',  'F', 'FRO.Watt',   true,  'pv', 'SunSpec Model 160 DCW'],
                ['mppt2_volt',  'MPPT2 voltage', 'F', 'FRO.Volt',   false, 'pv', 'SunSpec Model 160 DCV'],
                ['mppt2_curr',  'MPPT2 current',    'F', 'FRO.Ampere', false, 'pv', 'SunSpec Model 160 DCA'],
                ['mppt2_power','MPPT2 power',  'F', 'FRO.Watt',   true,  'pv', 'SunSpec Model 160 DCW'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, current, frequency)', 'vars' => [
                ['grid_volt', 'Grid voltage',  'F', 'FRO.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Grid current',     'F', 'FRO.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Grid frequency',   'F', 'FRO.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (total yield)', 'vars' => [
                ['e_total', 'Yield total', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (power)', 'vars' => [
                ['meter_total', 'Grid power (meter)', 'F', 'FRO.Watt', true, 'meter', 'SunSpec Meter Model 20x/21x (Unit-ID 200)'],
            ]],
            'GroupMeterEnergy' => ['caption' => 'Smart meter energy (import / feed-in total)', 'vars' => [
                ['meter_imp', 'Import total (from the grid)',   'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhImp (EnergyReal_WAC_Sum_Consumed)'],
                ['meter_exp', 'Feed-in total (to the grid)', 'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhExp (EnergyReal_WAC_Sum_Produced)'],
            ]],
            'GroupMeterPhases' => ['caption' => 'Smart meter per phase (voltage/current/power)', 'vars' => [
                ['meter_l1_volt', 'Meter L1 voltage', 'F', 'FRO.Volt',   false, 'meter', 'SunSpec Meter PhVphA'],
                ['meter_l1_curr', 'Meter L1 current',    'F', 'FRO.Ampere', false, 'meter', 'SunSpec Meter AphA'],
                ['meter_l1_pwr',  'Meter L1 power', 'F', 'FRO.Watt',   true,  'meter', 'SunSpec Meter WphA'],
                ['meter_l2_volt', 'Meter L2 voltage', 'F', 'FRO.Volt',   false, 'meter', 'SunSpec Meter PhVphB'],
                ['meter_l2_curr', 'Meter L2 current',    'F', 'FRO.Ampere', false, 'meter', 'SunSpec Meter AphB'],
                ['meter_l2_pwr',  'Meter L2 power', 'F', 'FRO.Watt',   true,  'meter', 'SunSpec Meter WphB'],
                ['meter_l3_volt', 'Meter L3 voltage', 'F', 'FRO.Volt',   false, 'meter', 'SunSpec Meter PhVphC'],
                ['meter_l3_curr', 'Meter L3 current',    'F', 'FRO.Ampere', false, 'meter', 'SunSpec Meter AphC'],
                ['meter_l3_pwr',  'Meter L3 power', 'F', 'FRO.Watt',   true,  'meter', 'SunSpec Meter WphC'],
            ]],
            'GroupBat' => ['caption' => 'Battery (GEN24 hybrid: SOC, power, voltage)', 'vars' => [
                ['bat_soc',   'Bat. SOC', 'F', 'FRO.Soc', true, 'bat', 'SunSpec Model 124 ChaState'],
                ['bat_power', 'Bat. power (discharging + / charging -)', 'F', 'FRO.Watt', true, 'bat', 'SunSpec Model 160 Module 3+4'],
                ['bat_volt',  'Bat. voltage', 'F', 'FRO.Volt', false, 'bat', 'SunSpec Model 160 Speicher-Modul DCV'],
            ]],
            'GroupDevice' => ['caption' => 'Device information', 'vars' => [
                ['dev_model', 'Model', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Serial number', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['riso',      'Insulation resistance', 'F', 'FRO.KOhm', true, 'device', 'SunSpec Ris (Model 122)'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    // sunssf-Skalierungsfaktor auswerten (int16, 0x8000 = nicht implementiert).
    private function sfVal($raw)
    {
        $v = ($raw > 32767) ? $raw - 65536 : $raw;
        return ($v === -32768) ? 0 : $v;
    }

    // Ganzzahlregister mit zugehoerigem SF-REGISTER (Offset, nicht Faktor)
    // verrechnen. null bei Sentinel „nicht implementiert" - u16: 0xFFFF,
    // s16: 0x8000. Bewusst anders benannt als scaledU16(), das einen bereits
    // ausgewerteten Faktor erwartet.
    private function scaledSf($mb, $blk, $off, $sfOff, bool $signed = false)
    {
        $raw = $mb->u16($blk, $off);
        if ($signed ? ($raw === 0x8000) : ($raw === 0xFFFF)) {
            return null;
        }
        $val = $signed ? $mb->s16($blk, $off) : $raw;
        return $val * pow(10, $this->sfVal($mb->u16($blk, $sfOff)));
    }

    // uint16-Registerwert mit Skalierungsfaktor; 0xFFFF = nicht implementiert.
    private function scaledU16($mb, $blk, $off, $sf)
    {
        $raw = $mb->u16($blk, $off);
        if ($raw === 0xFFFF) {
            return null;
        }
        return $raw * pow(10, $sf);
    }

    public function getProfiles()
    {
        return [
            'FRO.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'FRO.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'FRO.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'FRO.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ', 0.0, 100000.0, 1.0, 0],
            'FRO.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'FRO.Soc'    => [VARIABLETYPE_FLOAT, ' %',        0.0,   100.0, 0.1,  1],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['FRO.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        // Float-Inverter-Model bevorzugt (111/112/113), sonst Int+SF (101/102/103)
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
        if ($hub->BlockLooksUnset($blk)) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // Offsets ab Modellstart gemäß offizieller SunSpec-Modelldefinition
        // (Model 113/103 "Inverter Three Phase" — Feldreihenfolge, gegen die
        // OpenEMS-SunSpec-Implementierung verifiziert 2026-07-16):
        // Float (Model 113, je Feld 2 Register): 0 A, 14 PhVphA(Netzspannung),
        // 22 Hz(Frequenz), 20 W(Wirkleistung), 30 WH(Gesamtertrag), 46 St(Status)
        // Int+SF (Model 103, je Feld 1 Register + eigenes SF-Register):
        // 0 A, 8 PhVphA, 14 Hz(+SF@15), 12 W(+SF@13), 36 St
        $invStatus = 0;
        if ($isFloat) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($blk, 20));
            $invStatus = $mb->u16($blk, 46);
            $hub->SetVarInt('status', $invStatus);
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
        } else {
            // Int+SF-Variante (Model 101/103): Ganzzahlregister mit eigenem
            // Skalierungsfaktor-Register. Bis 0.65.3 wurde hier der Rohwert
            // uebernommen - bei Fronius faellt das kaum auf, weil der
            // Datamanager die Float-Modelle 111/113 anbietet und der Code die
            // bevorzugt. Der Zweig greift nur bei aelteren Geraeten, war dort
            // aber genauso falsch wie bei SMA.
            // Offsets: 0 A(+SF@4) | 8 PhVphA(+SF@11) | 12 W(+SF@13)
            //          14 Hz(+SF@15) | 22 WH acc32(+SF@24) | 36 St
            $v = $this->scaledSf($mb, $blk, 12, 13, true);
            if ($v !== null) {
                $hub->SetVarFloat('ac_power', $v);
            }
            $invStatus = $mb->u16($blk, 36);
            $hub->SetVarInt('status', $invStatus);
            if ($hub->GetPropBool('GroupGrid')) {
                $v = $this->scaledSf($mb, $blk, 0, 4);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_curr', $v);
                }
                $v = $this->scaledSf($mb, $blk, 8, 11);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_volt', $v);
                }
                $v = $this->scaledSf($mb, $blk, 14, 15);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_freq', $v);
                }
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $wh = $mb->u32($blk, 22);
                if ($wh !== 0xFFFFFFFF) {
                    $hub->SetVarFloat('e_total', $wh * pow(10, $this->sfVal($mb->u16($blk, 24))) / 1000.0);
                }
            }
        }

        if ($hub->GetPropBool('GroupPV') || $hub->GetPropBool('GroupBat')) {
            $mppt = $this->findModel($mb, 160);
            if ($mppt !== null) {
                [$mbase, $mlen] = $mppt;
                $readLen = min($mlen, 88);   // fester Kopf (8) + bis zu 4 Module à 20
                $mblk = $mb->readHolding($mbase, $readLen);
                if ($mblk !== null) {
                    // Multiple MPPT Extension (Model 160), offizielles Layout:
                    // fester Kopf: DCA_SF(0) DCV_SF(1) DCW_SF(2) DCWH_SF(3)
                    // Evt(4-5) N(6) TmsPer(7); je Modul (20 Register ab 8+n*20):
                    // ID(+0) IDStr(+1..+8, Text!) DCA(+9) DCV(+10) DCW(+11) ...
                    // Bugfix: die alten Offsets (10/12 bzw. 30/32) lasen mitten
                    // im IDStr-Textfeld - daher Fantasiewerte wie "2056,4 V".
                    $dcaSf = $this->sfVal($mb->u16($mblk, 0));
                    $dcvSf = $this->sfVal($mb->u16($mblk, 1));
                    $dcwSf = $this->sfVal($mb->u16($mblk, 2));
                    $modVal = function ($n, $inner, $sf) use ($mb, $mblk, $readLen) {
                        $base = 8 + $n * 20;
                        if ($base + 20 > $readLen) {
                            return null;   // Modul nicht vorhanden/gelesen
                        }
                        return $this->scaledU16($mb, $mblk, $base + $inner, $sf);
                    };

                    if ($hub->GetPropBool('GroupPV')) {
                        $p1 = $modVal(0, 11, $dcwSf);
                        $p2 = $modVal(1, 11, $dcwSf);
                        $hub->SetVarFloat('mppt1_volt',  (float)($modVal(0, 10, $dcvSf) ?? 0));
                        $hub->SetVarFloat('mppt1_curr',  (float)($modVal(0, 9, $dcaSf) ?? 0));
                        $hub->SetVarFloat('mppt1_power', (float)($p1 ?? 0));
                        $hub->SetVarFloat('mppt2_volt',  (float)($modVal(1, 10, $dcvSf) ?? 0));
                        $hub->SetVarFloat('mppt2_curr',  (float)($modVal(1, 9, $dcaSf) ?? 0));
                        $hub->SetVarFloat('mppt2_power', (float)($p2 ?? 0));
                        $hub->SetVarFloat('pv_total', (float)(($p1 ?? 0) + ($p2 ?? 0)));
                    }

                    // GEN24-Hybrid: Module 3 und 4 sind die Speicherkanäle
                    // (Laden bzw. Entladen). Konvention im Modul: positiv =
                    // Entladung (Leistung wird ans System abgegeben).
                    if ($hub->GetPropBool('GroupBat')) {
                        $charge    = $modVal(2, 11, $dcwSf);
                        $discharge = $modVal(3, 11, $dcwSf);
                        if ($charge !== null || $discharge !== null) {
                            $hub->SetVarFloat('bat_power', (float)(($discharge ?? 0) - ($charge ?? 0)));
                        }
                        // Batteriespannung aus dem DCV der Speichermodule (2/3);
                        // ist am aktiven Kanal belegt, daher den ersten gültigen
                        // Wert nehmen.
                        $bVolt = $modVal(2, 10, $dcvSf);
                        if ($bVolt === null || $bVolt <= 0) {
                            $bVolt = $modVal(3, 10, $dcvSf);
                        }
                        if ($bVolt !== null && $bVolt > 0) {
                            $hub->SetVarFloat('bat_volt', (float)$bVolt);
                        }
                    }
                }
            }
        }

        // Batterie-SOC aus dem Basic-Storage-Model 124: ChaState (Offset 6,
        // uint16) mit ChaState_SF (Offset 20, sunssf).
        if ($hub->GetPropBool('GroupBat')) {
            $stor = $this->findModel($mb, 124);
            if ($stor !== null) {
                [$sbase, $slen] = $stor;
                $sblk = $mb->readHolding($sbase, min($slen, 24));
                if ($sblk !== null && min($slen, 24) >= 21) {
                    $soc = $this->scaledU16($mb, $sblk, 6, $this->sfVal($mb->u16($sblk, 20)));
                    if ($soc !== null) {
                        // Fronius liefert den SOC als Float (ChaState mit
                        // Skalierungsfaktor) - eine Nachkommastelle zeigen.
                        $hub->SetVarFloat('bat_soc', round((float)$soc, 1));
                    }
                }
            }
        }

        // Isolationswiderstand: Solange der Wechselrichter NICHT einspeist
        // (Status 4 = Normal/MPPT, 5 = Leistungsreduktion), wird er in jedem
        // schnellen Zyklus gelesen. Der aussagekraeftige Wert entsteht beim
        // Selbsttest VOR dem Zuschalten - im langsamen Zyklus wuerde man ihn
        // verpassen. Im Betrieb genuegt die Auffrischung aus readSlow().
        if (!in_array($invStatus ?? 0, [4, 5], true)) {
            $this->readRiso($mb, $hub);
        }

        if ($hub->GetPropBool('GroupMeter')) {
            // Fronius meldet den Smart Meter NICHT in der SunSpec-Kette des
            // Wechselrichters, sondern unter einer eigenen Unit-ID (die
            // "Zähleradresse"). Deren Vorgabe ist 200, je nach Konfiguration
            // aber z. B. 240 - daher konfigurierbar. Erst dort suchen, zur
            // Sicherheit als Rückfall auch in der eigenen Kette.
            $meterVal = $this->readMeterTotal(new IHUB_ModbusTcpClient($mb->host, $mb->port, $hub->GetMeterUnitId()));
            if ($meterVal === null) {
                $meterVal = $this->readMeterTotal($mb);
            }
            if ($meterVal !== null) {
                // SunSpec-Meter: positiv = Bezug. Modul-Konvention: positiv =
                // Einspeisung - daher Vorzeichen drehen.
                $hub->SetVarFloat('meter_total', -$meterVal);
            }
        }

        if ($hub->GetPropBool('GroupMeterPhases')) {
            $mc = new IHUB_ModbusTcpClient($mb->host, $mb->port, $hub->GetMeterUnitId());
            if (!$this->readMeterPhases($mc, $hub)) {
                $this->readMeterPhases($mb, $hub);
            }
        }

        if ($hub->GetPropBool('GroupMeterEnergy')) {
            $mc = new IHUB_ModbusTcpClient($mb->host, $mb->port, $hub->GetMeterUnitId());
            $me = $this->readMeterEnergy($mc);
            if ($me === null) {
                $me = $this->readMeterEnergy($mb);
            }
            if ($me !== null) {
                // Werte in kWh; die optionale Wh-Ausgabe rechnet SetVarFloat um.
                $hub->SetVarFloat('meter_imp', $me['imp']); // Consumed / Bezug
                $hub->SetVarFloat('meter_exp', $me['exp']); // Produced / Einspeisung
            }
        }

        return true;
    }

    // Liest die kumulierten Energie-Zählerstände eines SunSpec-Meters
    // (Bezug/Einspeisung gesamt) - entspricht EnergyReal_WAC_Sum_Consumed/
    // Produced der Fronius-API. Rückgabe ['imp'=>kWh, 'exp'=>kWh] oder null.
    private function readMeterEnergy($client)
    {
        // Int-Modelle 20x: TotWhExp @36, TotWhImp @44, TotWh_SF @52 (uint32, Wh).
        $meter = $this->findModel($client, 201) ?: $this->findModel($client, 202) ?: $this->findModel($client, 203);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 54));
            if ($blk === null || min($len, 54) < 53) {
                return null;
            }
            $sf = pow(10, $this->sfVal($client->u16($blk, 52)));
            return [
                'imp' => $client->u32($blk, 44) * $sf / 1000.0,
                'exp' => $client->u32($blk, 36) * $sf / 1000.0,
            ];
        }
        // Float-Modelle 21x (je Messwert 2 Register): TotWhExp @58, TotWhImp @66.
        // WICHTIG: 58/66 sind die GESAMT-Zähler; 60/68 wären TotWhExpPhA/ImpPhA
        // (Per-Phase), die viele Meter nicht füllen -> lieferten fälschlich 0 Wh.
        $meter = $this->findModel($client, 211) ?: $this->findModel($client, 212) ?: $this->findModel($client, 213);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 70));
            if ($blk === null || min($len, 70) < 68) {
                return null;
            }
            return [
                'imp' => $client->readFloat32($blk, 66) / 1000.0,
                'exp' => $client->readFloat32($blk, 58) / 1000.0,
            ];
        }
        return null;
    }

    // Liest die Phasenwerte (U/I/P je Phase) eines SunSpec-Meters. Strom und
    // Leistung je Phase werden im Roh-Vorzeichen des Zählers geführt (P und I
    // damit zueinander konsistent, P = U·I); nur der Gesamtwert wird gedreht.
    // Rückgabe true, wenn ein Meter-Model gefunden wurde.
    private function readMeterPhases($client, $hub)
    {
        // Int-Modelle 20x: Messwerte int16/uint16 mit Skalierungsfaktor.
        $meter = $this->findModel($client, 201) ?: $this->findModel($client, 202) ?: $this->findModel($client, 203);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 24));
            if ($blk === null || min($len, 24) < 21) {
                return false;
            }
            $aSf = pow(10, $this->sfVal($client->u16($blk, 4)));   // A_SF
            $vSf = pow(10, $this->sfVal($client->u16($blk, 13)));  // V_SF
            $wSf = pow(10, $this->sfVal($client->u16($blk, 20)));  // W_SF
            // Offsets: AphA/B/C = 1/2/3, PhVphA/B/C = 6/7/8, WphA/B/C = 17/18/19.
            $ph = [['l1', 1, 6, 17], ['l2', 2, 7, 18], ['l3', 3, 8, 19]];
            foreach ($ph as [$p, $ai, $vi, $wi]) {
                if ($client->u16($blk, $vi) !== 0xFFFF) {
                    $hub->SetVarFloat('meter_' . $p . '_volt', $client->u16($blk, $vi) * $vSf);
                }
                if ($client->s16($blk, $ai) !== -32768) {
                    $hub->SetVarFloat('meter_' . $p . '_curr', $client->s16($blk, $ai) * $aSf);
                }
                if ($client->s16($blk, $wi) !== -32768) {
                    // Phasen-Leistung NICHT drehen: Der Zähler liefert sie mit
                    // demselben Vorzeichen wie den Phasenstrom (P = U·I). Der
                    // Gesamtwert (meter_total) wird separat gedreht - Phasenwert
                    // und Strom bleiben dagegen konsistent zueinander.
                    $hub->SetVarFloat('meter_' . $p . '_pwr', $client->s16($blk, $wi) * $wSf);
                }
            }
            return true;
        }

        // Float-Modelle 21x: Messwerte als IEEE-754-Float32 (je 2 Register).
        $meter = $this->findModel($client, 211) ?: $this->findModel($client, 212) ?: $this->findModel($client, 213);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 34));
            if ($blk === null || min($len, 34) < 34) {
                return false;
            }
            // Offsets (Float32): AphA/B/C = 2/4/6, PhVphA/B/C = 10/12/14,
            // WphA/B/C = 28/30/32.
            $ph = [['l1', 2, 10, 28], ['l2', 4, 12, 30], ['l3', 6, 14, 32]];
            foreach ($ph as [$p, $ai, $vi, $wi]) {
                $hub->SetVarFloat('meter_' . $p . '_volt', $client->readFloat32($blk, $vi));
                $hub->SetVarFloat('meter_' . $p . '_curr', $client->readFloat32($blk, $ai));
                $hub->SetVarFloat('meter_' . $p . '_pwr', $client->readFloat32($blk, $wi));
            }
            return true;
        }

        return false;
    }

    // Liest die Gesamtwirkleistung eines SunSpec-Meters (Int-Modelle 20x mit
    // Skalierungsfaktor, Float-Modelle 21x als IEEE-754) über den übergebenen
    // Client. Rückgabe null, wenn kein Meter-Model gefunden wird.
    private function readMeterTotal($client)
    {
        $meter = $this->findModel($client, 201) ?: $this->findModel($client, 202) ?: $this->findModel($client, 203);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 24));
            if ($blk === null || min($len, 24) < 21) {
                return null;
            }
            // Model 20x: W bei Offset 16 (int16), W_SF bei Offset 20.
            $raw = $client->s16($blk, 16);
            if ($raw === -32768) {
                return null;
            }
            return $raw * pow(10, $this->sfVal($client->u16($blk, 20)));
        }

        $meter = $this->findModel($client, 211) ?: $this->findModel($client, 212) ?: $this->findModel($client, 213);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 30));
            if ($blk === null || min($len, 30) < 28) {
                return null;
            }
            // Float-Model 21x: W ist der 14. Messwert -> Offset 26 (je 2 Reg.).
            return $client->readFloat32($blk, 26);
        }

        return null;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
        // Isolationswiderstand hier als Auffrischung waehrend des Betriebs;
        // steht der Wechselrichter, holt ihn readFast() in jedem Zyklus.
        $this->readRiso($mb, $hub);
    }

    /**
     * Isolationswiderstand aus SunSpec-Modell 122 ("Measurements Status").
     *
     * WANN das gelesen wird, ist fachlich entscheidend: Der Wechselrichter misst
     * die Isolation beim Selbsttest VOR dem Zuschalten. Genau dieser Wert zeigt,
     * ob eine Strecke Feuchtigkeit zieht ("absaufen"). Steht das Geraet erst im
     * Betrieb, ist der Wert weniger aussagekraeftig bzw. aendert sich kaum.
     *
     * Deshalb: Im Stillstand und beim Anlauf (Status != 4/5) wird er in JEDEM
     * schnellen Zyklus gelesen, damit die Messung des Selbsttests nicht
     * verpasst wird. Speist das Geraet ein, genuegt der langsame Zyklus.
     *
     * Das Modell fuehrt Ris und Ris_SF als die BEIDEN LETZTEN Register des 44
     * Register langen Blocks. Bewusst ueber die gemeldete Modelllaenge
     * adressiert statt ueber eine feste Adresse - die SunSpec-Kette liegt je
     * nach Geraet an unterschiedlicher Stelle.
     *
     * Nicht jedes Geraet fuehrt Modell 122; fehlt es, entfaellt der Wert
     * stillschweigend. Ueber die Fronius Solar API ist der Wert NICHT verfuegbar
     * (CommonInverterData kennt ihn nicht) - Modbus ist der einzige Weg.
     */
    private function readRiso($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupDevice')) {
            return;
        }
        $m122 = $this->findModel($mb, 122);
        if ($m122 === null) {
            return;
        }
        [$base, $len] = $m122;
        if ($len < 44) {
            return; // unerwartete Laenge - lieber nichts liefern als etwas Falsches
        }
        $blk = $mb->readHolding($base, 44);
        if ($blk === null) {
            return;
        }
        $ris   = $mb->u16($blk, 42);   // vorletztes Register
        $risSf = $this->sfVal($mb->u16($blk, 43));
        if ($ris === 0xFFFF || $risSf === null) {
            return; // SunSpec-Kennung fuer "nicht implementiert"
        }
        // Ris ist in Ohm * 10^Ris_SF; die anderen Treiber fuehren riso in kOhm.
        $hub->SetVarFloat('riso', ($ris * pow(10, $risSf)) / 1000.0);
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
        // Common Block: Mn(16 Zeichen ab Offset 0), Md(16 ab Offset 16),
        // Vr(8 ab Offset 32... Herstellerabhängig), SN(16 ab Offset 40)
        // SunSpec Common Block (Model 1): MN(0-15) MD(16-31) OPT(32-39)
        // VR(40-47) SN(48-63) — verifiziert gegen OpenEMS-SunSpec-Definition.
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}
