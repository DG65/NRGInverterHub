<?php

// ---------------------------------------------------------------------------
// IHUB_SmaDriver — SMA-Wechselrichter über SunSpec (wie von OpenEMS für SMA
// Sunny Tripower verwendet — SMA-eigene native Register wurden zugunsten
// von SunSpec verworfen, siehe Erkenntnis vom 2026-07-16). Laufzeit-
// Discovery ab Basisregister 40000, keine festen Adressen — analog zu
// IHUB_FroniusDriver, Feldoffsets gegen OpenEMS-SunSpec-Referenz verifiziert.
// ---------------------------------------------------------------------------

class IHUB_SmaDriver implements IHUB_InverterDriverInterface
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

    // ---- SunSpec Int+SF ----------------------------------------------------
    // Bewusst als eigene Methoden DIESER Klasse (nicht aus IHUB_FroniusDriver
    // mitbenutzt): Die Treiber sind getrennte Klassen, ein klassenübergreifender
    // $this->-Aufruf wäre ein Fatal Error zur Laufzeit.

    // sunssf-Skalierungsfaktor auswerten (int16, 0x8000 = nicht implementiert).
    private function sfVal($raw)
    {
        $v = ($raw > 32767) ? $raw - 65536 : $raw;
        return ($v === -32768) ? 0 : $v;
    }

    // Ganzzahlregister mit zugehörigem SF-Register zu einem echten Messwert
    // verrechnen. Liefert null, wenn das Feld den Sentinel „nicht implementiert"
    // trägt - u16: 0xFFFF, s16: 0x8000. Bei s16 ist 0xFFFF der gültige Wert -1
    // und darf gerade NICHT als Sentinel gelten.
    private function scaled($mb, $blk, $off, $sfOff, bool $signed = false)
    {
        $raw = $mb->u16($blk, $off);
        if ($signed ? ($raw === 0x8000) : ($raw === 0xFFFF)) {
            return null;
        }
        $val = $signed ? $mb->s16($blk, $off) : $raw;
        return $val * pow(10, $this->sfVal($mb->u16($blk, $sfOff)));
    }

    // DC-Leistung eines MPP-Eingangs aus dem SMA-Eigenprofil (int32, W).
    // false = keine Antwort, null = Sentinel (0x80000000 bzw. 0xFFFFFFFF = "nicht verfuegbar"),
    // sonst die Leistung.
    private function readDcInputPower($mb, int $reg)
    {
        $blk = $mb->readInput($reg, 2);
        if ($blk === null) {
            return false;
        }
        $w = $mb->s32($blk, 0);
        if ($w === -2147483648 || $w === -1) {
            return null;
        }
        return (float)$w;
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Operating status',    'I', 'SMA.Status',      true,  'device', 'SunSpec St'],
            ['ac_power',  'AC active power',   'F', 'SMA.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
            ['pv_total',  'PV total power', 'F', 'SMA.Watt',        true,  'pv',     'RO 30773+30961 (SMA-Profil, DC MPP A+B), Fallback SunSpec DCW'],
            ['riso',      'Insulation resistance','F', 'SMA.KOhm',      true,  'pv',     'RO 30225 (SMA-Profil, Ohm ÷1000)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Grid (voltage, current, frequency)', 'vars' => [
                ['grid_volt', 'Grid voltage',  'F', 'SMA.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Grid current',     'F', 'SMA.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Grid frequency',   'F', 'SMA.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (total yield)', 'vars' => [
                ['e_total', 'Yield total', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp_cab', 'Case temperature', 'F', '~Temperature', false, 'device', 'SunSpec TmpCab (Model 101/103)'],
            ]],
            'GroupMppt' => ['caption' => 'PV inputs A and B (power per input, e.g. east/west)', 'vars' => [
                ['mppt1_power', 'PV input A power', 'F', 'SMA.Watt', true, 'pv', 'RO 30773 (SMA profile, DC power MPP A, W)'],
                ['mppt2_power', 'PV input B power', 'F', 'SMA.Watt', true, 'pv', 'RO 30961 (SMA profile, DC power MPP B, W)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (power)', 'vars' => [
                ['meter_total', 'Grid power (meter)', 'F', 'SMA.Watt', true, 'meter', 'SunSpec Meter Model 201/203'],
            ]],
            'GroupBat' => ['caption' => 'Battery (hybrid/storage devices)', 'vars' => [
                ['bat_soc',  'Battery SOC',        'I', '~Battery.100',  true,  'bat', 'RO 30845 (SMA-Profil, %)'],
                ['bat_pwr',  'Battery power',   'F', 'SMA.Watt',      true,  'bat', 'RO 31393-31395 (charging minus discharging, + = charging)'],
                ['bat_volt', 'Battery voltage',   'F', 'SMA.Volt',      false, 'bat', 'RO 30851 (SMA-Profil, V ÷100)'],
                ['bat_temp', 'Battery temperature', 'F', '~Temperature',  false, 'bat', 'RO 30849 (SMA-Profil, °C ÷10)'],
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
            'SMA.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SMA.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SMA.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'SMA.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'SMA.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ',      0.0, 65535.0, 1.0,  0],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['SMA.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        // Float-Inverter-Model bevorzugt (111/112/113), sonst Int+SF (101/102/103)
        // SMA-Besonderheit: Die SunSpec-Kette liegt NICHT auf der Unit-ID, die in
        // der SMA-Oberflaeche eingestellt ist, sondern auf dieser Zahl PLUS 123.
        // So dokumentiert es SMA selbst; OpenEMS setzt seine Vorgabe deshalb auf
        // 126 (= 3 + 123). Wer in der Oberflaeche 4 eingestellt hat, braucht 127.
        //
        // Weil das kaum jemand weiss, probiert der Treiber es selbst: Findet sich
        // unter der eingetragenen Unit-ID keine SunSpec-Kette, wird einmalig mit
        // +123 erneut gesucht. Das SMA-EIGENE Registerprofil (30000er, z. B. Riso)
        // liegt dagegen auf der unveraenderten Unit-ID.
        $native = $mb->unitId;
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }
        if ($inv === null && $native <= 124) {
            $mb->unitId = $native + 123;
            $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
            $isFloat = ($inv !== null);
            if ($inv === null) {
                $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
            }
            if ($inv === null) {
                $mb->unitId = $native;
            }
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

        // ---- SMA-Eigenprofil (30000er-Register) --------------------------
        // Es liegt NICHT auf der SunSpec-Kennung, sondern auf der Geraete-
        // Unit-ID. Welche das ist, haengt davon ab, was der Nutzer eingetragen
        // hat: die Geraete-ID (z. B. 3, dann SunSpec via +123-Retry) ODER
        // direkt die SunSpec-Kennung (z. B. 126, der OpenEMS-Standard) - dann
        // ist die Geraete-ID die eingetragene MINUS 123. Beim Tester fror so
        // der Isolationswiderstand ein und die Batterie blieb stumm, obwohl
        // sein Altmodul dieselben Register (auf Unit 3) problemlos las.
        // Deshalb wird die Geraete-ID sondiert: Kandidaten der Reihe nach
        // gegen Reg 30775 (AC-Wirkleistung, liefert auch nachts einen Wert
        // statt NaN) geprueft, der erste Treffer gewinnt.
        //
        // WICHTIG, an der Anlage belegt (Tester-Registerliste 22.07.2026): Das
        // SMA-Eigenprofil (30000er) wird als INPUT-Register (FC 0x04) gelesen,
        // NICHT als Holding (0x03). Deshalb kamen 30773/30961 (DC), 30845 ff.
        // (Batterie) und 31393/31395 (Batterieleistung) mit readHolding als NaN
        // zurueck und liessen das Geraet batterielos erscheinen. Die SunSpec-
        // Kette weiter oben bleibt Holding - das sind zwei verschiedene Welten.
        $sunspecUnit = $mb->unitId;
        $cands = [$native];
        if ($native > 123) {
            $cands[] = $native - 123;
        }
        if (!in_array(3, $cands, true)) {
            $cands[] = 3;   // SMA-Werksvorgabe
        }
        $devUnit = $native;
        foreach ($cands as $c) {
            $mb->unitId = $c;
            $probe = $mb->readInput(30775, 2);
            if ($probe !== null && $mb->u32($probe, 0) !== 0xFFFFFFFF) {
                $devUnit = $c;
                break;
            }
        }
        $mb->unitId = $devUnit;
        $risoBlk = $mb->readInput(30225, 2);
        if ($risoBlk !== null) {
            $r = $mb->u32($risoBlk, 0);
            if ($r !== 0xFFFFFFFF) {
                $hub->SetVarFloat('riso', $r / 1000.0);
            }
        }
        // PV-Gesamtleistung ebenfalls aus dem SMA-Eigenprofil: SMA belegt das
        // SunSpec-Feld DCW nicht (Sentinel 0x8000), die Variable bliebe leer
        // bzw. auf ihrem letzten Stand. Stattdessen die DC-Leistungen der MPP-
        // Eingaenge summieren: Reg 30773 (MPP A) und 30961 (MPP B), jeweils
        // int32 in W. Sentinel 0x80000000 = "nicht verfuegbar" (z. B. kein
        // zweiter Eingang oder nachts) zaehlt nicht mit.
        // SMA meldet "nicht verfuegbar" je nach Geraet als 0x80000000 (signed
        // NaN) ODER 0xFFFFFFFF (unsigned NaN, als s32 = -1). Der STP Smart
        // Energy z. B. belegt 30773/30961 gar nicht und lieferte so -1 je
        // Register - die Kachel zeigte -2 W bei laufender Produktion.
        // Je Eingang (A/B) zusätzlich einzeln ablegen (Forum thorsten1, 05.11.2026, Ost/West-Anlage):
        // Antwortet das Gerät mit dem Sentinel (nachts oder Eingang nicht belegt), gilt der Eingang als
        // 0 W; antwortet es gar nicht, bleibt der alte Wert stehen.
        $dcSum = null;
        $mppt  = [];
        foreach ([30773 => 'mppt1_power', 30961 => 'mppt2_power'] as $reg => $ident) {
            $w = $this->readDcInputPower($mb, $reg);
            if ($w === false) {
                continue;   // keine Antwort
            }
            if ($w === null) {
                $mppt[$ident] = 0.0;   // Sentinel: Register nicht belegt oder nachts
                continue;
            }
            $mppt[$ident] = $w;
            $dcSum = ($dcSum ?? 0.0) + $w;
        }
        if ($dcSum !== null) {
            $hub->SetVarFloat('pv_total', $dcSum);
        }
        if ($hub->GetPropBool('GroupMppt')) {
            foreach ($mppt as $ident => $w) {
                $hub->SetVarFloat($ident, $w);
            }
        }

        // Batterie (Hybrid-/Storage-Geraete) aus dem SMA-Eigenprofil. Alle
        // Register u32, NaN = 0xFFFFFFFF ("keine Batterie" bzw. gerade kein
        // Wert). Quellen: SOC 30845 (%), Temperatur 30849 (÷10 °C), Spannung
        // 30851 (÷100 V), Ladeleistung 31393 (W), Entladeleistung 31395 (W) -
        // lt. SMA Modbus-TI (EDMx) und CodeKing-Registerkarte. Vorzeichen:
        // + = laedt, - = entlaedt.
        // Batterie IMMER lesen, auch bei abgewählter Gruppe: Ob ueberhaupt eine
        // Batterie da ist, entscheidet unten darueber, ob die PV-Leistung aus
        // der AC-Leistung abgeleitet werden darf. Geschrieben werden die
        // Variablen weiterhin nur bei eingeschalteter Gruppe.
        //
        // Erkennungsmerkmal ist der SOC (30845) und NICHT die Lade-/Entlade-
        // leistung: 30845/30849/30851 stehen im GERAETE-Registerprofil, die
        // Leistungsregister 31393/31395 dagegen nur im Profil des SMA DATA
        // MANAGER. Auf dem Wechselrichter selbst antworten sie mit NaN - und
        // genau daran scheiterte die erste Fassung: Das Geraet galt als
        // batterielos, und die Entladeleistung erschien wieder als Solarertrag.
        $batNet     = null;    // + = laedt, - = entlaedt; null = unbekannt
        $hasBattery = false;
        $bs = $mb->readInput(30845, 8);   // 30845 SOC, 30849 Temp, 30851 Volt
        $soc = ($bs !== null) ? $mb->u32($bs, 0) : 0xFFFFFFFF;
        if ($soc !== 0xFFFFFFFF && $soc <= 100) {
            $hasBattery = true;
        }
        $bp = $mb->readInput(31393, 4);   // Laden (W), Entladen (W) - s. o.
        if ($bp !== null) {
            $chg = $mb->u32($bp, 0);
            $dis = $mb->u32($bp, 2);
            if ($chg !== 0xFFFFFFFF && $dis !== 0xFFFFFFFF) {
                $hasBattery = true;
                $batNet     = (float)$chg - (float)$dis;
            }
        }
        if ($hub->GetPropBool('GroupBat')) {
            if ($batNet !== null) {
                $hub->SetVarFloat('bat_pwr', $batNet);
            }
            if ($bs !== null) {
                if ($soc !== 0xFFFFFFFF) {
                    $hub->SetVarInt('bat_soc', $soc);
                }
                // "nicht verfuegbar" kommt je nach Geraet als 0xFFFFFFFF, 0x80000000 oder
                // 0x7FFFFFFF (SB 5000TL-21 / 8.0-AV3-40 ohne Batterie: 214748364,8 Grad).
                $t = $mb->u32($bs, 4);
                if ($t !== 0xFFFFFFFF && $t !== 0x80000000 && $t !== 0x7FFFFFFF) {
                    $hub->SetVarFloat('bat_temp', $t / 10.0);
                }
                $u = $mb->u32($bs, 6);
                if ($u !== 0xFFFFFFFF && $u !== 0x80000000 && $u !== 0x7FFFFFFF) {
                    $hub->SetVarFloat('bat_volt', $u / 100.0);
                }
            }
        }
        $mb->unitId = $sunspecUnit; // zurueck auf die SunSpec-Kennung

        // Offsets siehe IHUB_FroniusDriver (identische SunSpec-Modelle 101/103/111/113),
        // gegen OpenEMS-SunSpec-Referenz verifiziert. Zusätzlich hier genutzt:
        // DCW (aggregierte DC-Leistung) Float @36, Int+SF @29; TmpCab Float @38, Int+SF @31.
        if ($isFloat) {
            $acW = $mb->readFloat32($blk, 20);
            $st  = $mb->u16($blk, 46);
            $hub->SetVarFloat('ac_power', $acW);
            $hub->SetVarInt('status', $st);
            if ($dcSum === null) {
                $dcw = $mb->readFloat32($blk, 36);
                if (is_finite($dcw)) {
                    $hub->SetVarFloat('pv_total', $dcw);
                    $dcSum = $dcw;
                }
            }
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->readFloat32($blk, 38));
            }
        } else {
            // Int+SF-Variante (Model 101/103): Jedes Feld ist ein Ganzzahl-
            // Register MIT einem eigenen Skalierungsfaktor-Register (sunssf).
            // Ohne dessen Auswertung liegen die Werte um Zehnerpotenzen daneben.
            // SMA bietet ausschliesslich diese Modelle an (keine Float-Modelle
            // 111/113), deshalb traf das bis 0.65.3 jeden SMA-Nutzer:
            // Netzspannung 2390 statt 239,0 V (V_SF = -1), Netzstrom 57 statt
            // 0,57 A (A_SF = -2). Gegenprobe: 0,57 A * 239 V = 136 W, und die
            // AC-Wirkleistung meldete zeitgleich 137 W.
            //
            // Offsets ab Modellstart (Model 101/103, identische Feldreihenfolge):
            //   0 A(+SF@4) | 8 PhVphA(+SF@11) | 12 W(+SF@13) | 14 Hz(+SF@15)
            //   22 WH acc32(+SF@24) | 29 DCW(+SF@30) | 31 TmpCab(+SF@35) | 36 St
            $acW = $this->scaled($mb, $blk, 12, 13, true);
            if ($acW !== null) {
                $hub->SetVarFloat('ac_power', $acW);
            }
            $st = $mb->u16($blk, 36);
            $hub->SetVarInt('status', $st);
            if ($dcSum === null) {
                $v = $this->scaled($mb, $blk, 29, 30, true);
                if ($v !== null) {
                    $hub->SetVarFloat('pv_total', $v);
                    $dcSum = $v;
                }
            }
            if ($hub->GetPropBool('GroupGrid')) {
                $v = $this->scaled($mb, $blk, 0, 4);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_curr', $v);
                }
                $v = $this->scaled($mb, $blk, 8, 11);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_volt', $v);
                }
                $v = $this->scaled($mb, $blk, 14, 15);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_freq', $v);
                }
            }
            // Gesamtertrag fehlte in diesem Zweig komplett - die Variable blieb
            // bei SMA dauerhaft auf 0,00 kWh / "Nie aktualisiert".
            if ($hub->GetPropBool('GroupEnergy')) {
                $wh = $mb->u32($blk, 22);
                if ($wh !== 0xFFFFFFFF) {
                    $hub->SetVarFloat('e_total', $wh * pow(10, $this->sfVal($mb->u16($blk, 24))) / 1000.0);
                }
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $v = $this->scaled($mb, $blk, 31, 35, true);
                if ($v !== null) {
                    $hub->SetVarFloat('temp_cab', $v);
                }
            }
        }

        // Letzte Rueckfallebene fuer die PV-Gesamtleistung, wenn weder das
        // SMA-Eigenprofil (30773/30961) noch SunSpec-DCW einen DC-Wert liefert.
        //
        // ACHTUNG, teuer gelernt (Tester-Report 22.07.2026, 21:50 Uhr): Auf
        // einem HYBRIDGERAET sagt die AC-Leistung NICHTS darueber aus, woher
        // die Energie kommt. Nachts speiste die Batterie 1850 W ins Haus, der
        // Wechselrichter meldete weiter Status "Normal (MPPT)" - und die Kachel
        // zeigte diese 1850 W als Solarerzeugung an. Der Status taugt also NICHT
        // als Tageslicht-Ersatz.
        //
        // Deshalb wird nur noch abgeleitet, wenn die Herkunft geklaert ist:
        //   - Geraet OHNE Batterie: AC ~ PV ist zulaessig (nur Wandlerverluste).
        //   - Geraet MIT Batterie: nur wenn die Batterieleistung wirklich
        //     gelesen wurde, dann PV ~ AC + Ladeleistung.
        //   - Batterie vorhanden, aber Leistung unbekannt: 0 W. Lieber gar kein
        //     Wert als eine erfundene Erzeugung.
        if ($dcSum === null) {
            $pv = 0.0;
            if ($st === 4 && $acW !== null) {
                if (!$hasBattery) {
                    $pv = max(0.0, (float)$acW);
                } elseif ($batNet !== null) {
                    $pv = max(0.0, (float)$acW + $batNet);
                }
            }
            $hub->SetVarFloat('pv_total', $pv);
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $this->findModel($mb, 201) ?: $this->findModel($mb, 203) ?: $this->findModel($mb, 211) ?: $this->findModel($mb, 213);
            if ($meter !== null) {
                [$mtbase, $mtlen] = $meter;
                // Model 201/203: W@16 mit W_SF@20 - der Block muss bis 20 reichen.
                $mtblk = $mb->readHolding($mtbase, min($mtlen, 24));
                if ($mtblk !== null) {
                    $v = $this->scaled($mb, $mtblk, 16, 20, true);
                    if ($v !== null) {
                        $hub->SetVarFloat('meter_total', $v);
                    }
                }
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Die SunSpec-Kette liegt bei SMA auf Geraete-Unit-ID + 123 (siehe readFast).
        // readDeviceInfo laeuft EINMALIG VOR readFast und merkt sich den Erfolg -
        // ohne diesen Rueckfall blieben Modell und Seriennummer bei Unit-ID 3 (wie
        // in der SMA-Oberflaeche eingestellt) dauerhaft leer (Forum brdiver677, 28.10.2026).
        $native = $mb->unitId;
        $common = $this->findModel($mb, 1);
        if ($common === null && $native <= 124) {
            $mb->unitId = $native + 123;
            $common = $this->findModel($mb, 1);
            if ($common === null) {
                $mb->unitId = $native;
            }
        }
        if ($common !== null) {
            [$base, $len] = $common;
            $blk = $mb->readHolding($base, min($len, 66));
            if ($blk !== null) {
                $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
                $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
            }
        }
        $mb->unitId = $native; // readFast sucht selbst wieder ab der eingetragenen Unit-ID
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}
