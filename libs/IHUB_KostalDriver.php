<?php

// ---------------------------------------------------------------------------
// IHUB_KostalDriver — Kostal PLENTICORE plus (Generation 1). Direkte Adressierung,
// Float32-Register (FC 0x03), Wert bereits in physikalischer SI-Einheit
// (kein separates Skalierungsfaktor-Register wie bei SunSpec Int+SF).
// Register 2026-07-16 gegen eine community-getestete Kostal-Modbus-Vorlage
// aus dem IP-Symcon-Forum übernommen.
// ---------------------------------------------------------------------------

class IHUB_KostalDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV total power (DC)', 'F', 'KST.Watt',   true,  'pv',     'RO 100 (Float32)'],
            ['ac_power',  'AC active power total',  'F', 'KST.Watt',  true,  'device', 'RO 172 (Float32)'],
            ['riso',      'Insulation resistance', 'F', 'KST.KOhm',    true,  'pv',     'RO 120 (Float32, Ohm ÷1000)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV details (DC input 1-3)', 'vars' => [
                ['pv1_curr',  'PV1 current',    'F', 'KST.Ampere', false, 'pv', 'RO 258 (Float32)'],
                ['pv1_power', 'PV1 power', 'F', 'KST.Watt',   true,  'pv', 'RO 260 (Float32)'],
                ['pv1_volt',  'PV1 voltage', 'F', 'KST.Volt',   false, 'pv', 'RO 266 (Float32)'],
                ['pv2_curr',  'PV2 current',    'F', 'KST.Ampere', false, 'pv', 'RO 268 (Float32)'],
                ['pv2_power', 'PV2 power', 'F', 'KST.Watt',   true,  'pv', 'RO 270 (Float32)'],
                ['pv2_volt',  'PV2 voltage', 'F', 'KST.Volt',   false, 'pv', 'RO 276 (Float32)'],
                ['pv3_curr',  'PV3 current',    'F', 'KST.Ampere', false, 'pv', 'RO 278 (Float32)'],
                ['pv3_power', 'PV3 power', 'F', 'KST.Watt',   true,  'pv', 'RO 280 (Float32)'],
                ['pv3_volt',  'PV3 voltage', 'F', 'KST.Volt',   false, 'pv', 'RO 286 (Float32)'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, current, power per phase, frequency)', 'vars' => [
                ['grid_freq',    'Grid frequency',      'F', 'KST.Hertz',  false, 'grid', 'RO 152 (Float32)'],
                ['grid_l1_curr', 'Grid L1 current',     'F', 'KST.Ampere', false, 'grid', 'RO 154 (Float32)'],
                ['grid_l1_pwr',  'Grid L1 power',  'F', 'KST.Watt',   true,  'grid', 'RO 156 (Float32)'],
                ['grid_l1_volt', 'Grid L1 voltage',  'F', 'KST.Volt',   false, 'grid', 'RO 158 (Float32)'],
                ['grid_l2_curr', 'Grid L2 current',     'F', 'KST.Ampere', false, 'grid', 'RO 160 (Float32)'],
                ['grid_l2_pwr',  'Grid L2 power',  'F', 'KST.Watt',   true,  'grid', 'RO 162 (Float32)'],
                ['grid_l2_volt', 'Grid L2 voltage',  'F', 'KST.Volt',   false, 'grid', 'RO 164 (Float32)'],
                ['grid_l3_curr', 'Grid L3 current',     'F', 'KST.Ampere', false, 'grid', 'RO 166 (Float32)'],
                ['grid_l3_pwr',  'Grid L3 power',  'F', 'KST.Watt',   true,  'grid', 'RO 168 (Float32)'],
                ['grid_l3_volt', 'Grid L3 voltage',  'F', 'KST.Volt',   false, 'grid', 'RO 170 (Float32)'],
            ]],
            'GroupBat' => ['caption' => 'Battery (power, voltage, current, SOC, temperature, state)', 'vars' => [
                ['bat_soc',    'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 210 (Float32)'],
                ['bat_power',  'Bat. power (discharging + / charging -)', 'F', 'KST.Watt', true, 'bat', 'RO 582 (SInt16)'],
                ['bat_status', 'Bat. state',    'I', 'KST.BatState', true,  'bat', 'abgeleitet aus RO 582'],
                ['bat_temp', 'Bat. temperature', 'F', '~Temperature', true,  'bat', 'RO 214 (Float32)'],
                ['bat_volt', 'Bat. voltage',   'F', 'KST.Volt',     false, 'bat', 'RO 216 (Float32)'],
                ['bat_curr', 'Bat. current (charging -/discharging +)', 'F', 'KST.Ampere', false, 'bat', 'RO 200 (Float32)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (power per phase)', 'vars' => [
                ['meter_l1_pwr',  'Meter L1 power', 'F', 'KST.Watt', true, 'meter', 'RO 224 (Float32)'],
                ['meter_l2_pwr',  'Meter L2 power', 'F', 'KST.Watt', true, 'meter', 'RO 234 (Float32)'],
                ['meter_l3_pwr',  'Meter L3 power', 'F', 'KST.Watt', true, 'meter', 'RO 244 (Float32)'],
                ['meter_total',   'Meter total power', 'F', 'KST.Watt', true, 'meter', 'RO 252 (Float32)'],
            ]],
            'GroupHome' => ['caption' => 'House consumption (split by source)', 'vars' => [
                ['home_total',    'House consumption total',       'F', '~Electricity', true, 'device', 'RO 118 (Float32, Wh)'],
                ['home_from_pv',  'House consumption from PV',       'F', 'KST.Watt', true, 'device', 'RO 116 (Float32)'],
                ['home_from_bat', 'House consumption from battery', 'F', 'KST.Watt', true, 'device', 'RO 106 (Float32)'],
                ['home_from_grid','House consumption from grid',     'F', 'KST.Watt', true, 'device', 'RO 108 (Float32)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (day/month/year/total)', 'vars' => [
                ['e_total',   'Yield total', 'F', '~Electricity', true, 'energy', 'RO 320 (Float32, Wh)'],
                ['e_day',     'Yield today',  'F', '~Electricity', true, 'energy', 'RO 322 (Float32, Wh)'],
                ['e_year',    'Yield year',   'F', '~Electricity', true, 'energy', 'RO 324 (Float32, Wh)'],
                ['e_month',   'Yield month',  'F', '~Electricity', true, 'energy', 'RO 326 (Float32, Wh)'],
            ]],
            'GroupDevice' => ['caption' => 'Device information', 'vars' => [
                ['dev_name', 'Product name', 'S', '', false, 'device', 'RO 768 (String)'],
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
            'KST.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'KST.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'KST.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'KST.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'KST.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ',      0.0, 100000.0, 1.0, 0],
        ];
    }

    public function getEnumProfiles()
    {
        return [
            'KST.BatState' => [
                0 => ['Sleep',     0x7A8A99],
                1 => ['Charge',    0x3BA55D],
                2 => ['Discharge', 0xE08A2B],
            ],
        ];
    }

    public function readFast($mb, $hub)
    {
        $mb->setFloatWordSwap($hub->GetKostalWordSwap());
        $pv  = $mb->readHolding(100, 2);
        $ac  = $mb->readHolding(172, 2);

        $ok = ($pv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', $mb->readFloat32($pv, 0));
        if ($ac !== null) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($ac, 0));
        }
        // Isolationswiderstand (Reg 120, Float32 in Ohm -> kΩ). Wort-Swap greift
        // über die bereits gesetzte Byte-Reihenfolge.
        $risoBlk = $mb->readHolding(120, 2);
        if ($risoBlk !== null) {
            $hub->SetVarFloat('riso', $mb->readFloat32($risoBlk, 0) / 1000.0);
        }

        if ($hub->GetPropBool('GroupPV')) {
            $dc1 = $mb->readHolding(258, 2);
            $dc1p = $mb->readHolding(260, 2);
            $dc1v = $mb->readHolding(266, 2);
            $dc2 = $mb->readHolding(268, 2);
            $dc2p = $mb->readHolding(270, 2);
            $dc2v = $mb->readHolding(276, 2);
            $dc3 = $mb->readHolding(278, 2);
            $dc3p = $mb->readHolding(280, 2);
            $dc3v = $mb->readHolding(286, 2);
            if ($dc1 !== null)  { $hub->SetVarFloat('pv1_curr',  $mb->readFloat32($dc1, 0)); }
            if ($dc1p !== null) { $hub->SetVarFloat('pv1_power', $mb->readFloat32($dc1p, 0)); }
            if ($dc1v !== null) { $hub->SetVarFloat('pv1_volt',  $mb->readFloat32($dc1v, 0)); }
            if ($dc2 !== null)  { $hub->SetVarFloat('pv2_curr',  $mb->readFloat32($dc2, 0)); }
            if ($dc2p !== null) { $hub->SetVarFloat('pv2_power', $mb->readFloat32($dc2p, 0)); }
            if ($dc2v !== null) { $hub->SetVarFloat('pv2_volt',  $mb->readFloat32($dc2v, 0)); }
            if ($dc3 !== null)  { $hub->SetVarFloat('pv3_curr',  $mb->readFloat32($dc3, 0)); }
            if ($dc3p !== null) { $hub->SetVarFloat('pv3_power', $mb->readFloat32($dc3p, 0)); }
            if ($dc3v !== null) { $hub->SetVarFloat('pv3_volt',  $mb->readFloat32($dc3v, 0)); }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $grid = $mb->readHolding(152, 20); // 152-171
            if ($grid !== null) {
                $hub->SetVarFloat('grid_freq',    $mb->readFloat32($grid, 0));
                $hub->SetVarFloat('grid_l1_curr', $mb->readFloat32($grid, 2));
                $hub->SetVarFloat('grid_l1_pwr',  $mb->readFloat32($grid, 4));
                $hub->SetVarFloat('grid_l1_volt', $mb->readFloat32($grid, 6));
                $hub->SetVarFloat('grid_l2_curr', $mb->readFloat32($grid, 8));
                $hub->SetVarFloat('grid_l2_pwr',  $mb->readFloat32($grid, 10));
                $hub->SetVarFloat('grid_l2_volt', $mb->readFloat32($grid, 12));
                $hub->SetVarFloat('grid_l3_curr', $mb->readFloat32($grid, 14));
                $hub->SetVarFloat('grid_l3_pwr',  $mb->readFloat32($grid, 16));
                $hub->SetVarFloat('grid_l3_volt', $mb->readFloat32($grid, 18));
            }
        }

        if ($hub->GetPropBool('GroupBat')) {
            $soc  = $mb->readHolding(210, 2);
            $temp = $mb->readHolding(214, 2);
            $volt = $mb->readHolding(216, 2);
            $curr = $mb->readHolding(200, 2);
            if ($soc !== null) {
                $socRaw = $mb->readFloat32($soc, 0);
                if (abs($socRaw) < 1.0e9) { // Float32-Extremwert = "nicht belegt", siehe SolarEdge
                    $hub->SetVarInt('bat_soc', (int)round($socRaw));
                }
            }
            if ($temp !== null) { $hub->SetVarFloat('bat_temp', $mb->readFloat32($temp, 0)); }
            if ($volt !== null) { $hub->SetVarFloat('bat_volt', $mb->readFloat32($volt, 0)); }
            if ($curr !== null) { $hub->SetVarFloat('bat_curr', $mb->readFloat32($curr, 0)); }
            // Bat.-Leistung: Reg 582 ist ein einzelnes SInt16 (W) - kein Float32,
            // daher direkt lesen (Wort-Swap irrelevant). Vorzeichen wie der Strom
            // (Reg 200): + = Entladen, − = Laden. Der Zustand wird daraus abgeleitet.
            $pwr = $mb->readHolding(582, 1);
            if ($pwr !== null) {
                $p = $mb->s16($pwr, 0);
                $hub->SetVarFloat('bat_power', (float)$p);
                $hub->SetVarInt('bat_status', ($p > 10) ? 2 : (($p < -10) ? 1 : 0));
            }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $m1 = $mb->readHolding(224, 2);
            $m2 = $mb->readHolding(234, 2);
            $m3 = $mb->readHolding(244, 2);
            $mt = $mb->readHolding(252, 2);
            if ($m1 !== null) { $hub->SetVarFloat('meter_l1_pwr', $mb->readFloat32($m1, 0)); }
            if ($m2 !== null) { $hub->SetVarFloat('meter_l2_pwr', $mb->readFloat32($m2, 0)); }
            if ($m3 !== null) { $hub->SetVarFloat('meter_l3_pwr', $mb->readFloat32($m3, 0)); }
            if ($mt !== null) { $hub->SetVarFloat('meter_total',  $mb->readFloat32($mt, 0)); }
        }

        if ($hub->GetPropBool('GroupHome')) {
            $home = $mb->readHolding(106, 14); // 106-118 (mit Lücke bei 120)
            if ($home !== null) {
                $hub->SetVarFloat('home_from_bat',  $mb->readFloat32($home, 0));
                $hub->SetVarFloat('home_from_grid', $mb->readFloat32($home, 2));
                $hub->SetVarFloat('home_from_pv',   $mb->readFloat32($home, 10));
                // Register 118 ist It. offizieller KOSTAL-Doku eine kumulierte
                // Energie in Wh (nicht W wie ursprünglich fälschlich
                // angenommen) - daher Umrechnung auf kWh wie bei allen
                // anderen ~Electricity-Datenpunkten im Modul.
                $hub->SetVarFloat('home_total',     $this->whToKwh($mb->readFloat32($home, 12)));
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        $mb->setFloatWordSwap($hub->GetKostalWordSwap());
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        // Register 320-327 sind It. offizieller KOSTAL-Doku Wh, nicht kWh -
        // Umrechnung fehlte bisher komplett (Bugfix: Werte erschienen um
        // Faktor 1000 zu groß, z.B. "Milliarden Watt" bei älteren Anlagen
        // mit hohem Gesamtertrag).
        $e = $mb->readHolding(320, 8); // 320-327
        if ($e !== null) {
            $hub->SetVarFloat('e_total', $this->whToKwh($mb->readFloat32($e, 0)));
            $hub->SetVarFloat('e_day',   $this->whToKwh($mb->readFloat32($e, 2)));
            $hub->SetVarFloat('e_year',  $this->whToKwh($mb->readFloat32($e, 4)));
            $hub->SetVarFloat('e_month', $this->whToKwh($mb->readFloat32($e, 6)));
        }
    }

    // Wh -> kWh. Die Marke "nicht belegt" (Float32-Extremwert) bleibt unverändert, damit SetVarFloat sie verwirft
    // und nicht erst durch die Division zu einer scheinbar gültigen Zahl wird.
    private function whToKwh(float $wh): float
    {
        return (abs($wh) >= 3.0e38) ? $wh : $wh / 1000.0;
    }

    public function readDeviceInfo($mb, $hub)
    {
        $mb->setFloatWordSwap($hub->GetKostalWordSwap());
        $name = $mb->readHolding(768, 16);
        if ($name !== null) {
            $hub->SetVarStr('dev_name', $mb->readStr($name, 0, 16));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}
