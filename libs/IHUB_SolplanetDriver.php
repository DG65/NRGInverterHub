<?php

// ---------------------------------------------------------------------------
// IHUB_SolplanetDriver — Solplanet/AISWEI ASW-Gen-Serie. Direkte Adressierung,
// Read Input Register (FC 0x04). Register 2026-07-16 gegen eine community-
// getestete Solplanet-Modbus-Vorlage aus dem IP-Symcon-Forum übernommen.
// ---------------------------------------------------------------------------

class IHUB_SolplanetDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV total power', 'F', 'SPL.Watt',        true,  'pv',     'RO 1600'],
            ['ac_power',  'AC active power',   'F', 'SPL.Watt',        true,  'device', 'RO 1370'],
            ['bat_power', 'Bat. power',     'F', 'SPL.Watt',        true,  'bat',    'RO 1618'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV details (string 1-3)', 'vars' => [
                ['pv1_volt', 'PV1 voltage', 'F', 'SPL.Volt',   false, 'pv', 'RO 1318'],
                ['pv1_curr', 'PV1 current',    'F', 'SPL.Ampere', false, 'pv', 'RO 1319'],
                ['pv2_volt', 'PV2 voltage', 'F', 'SPL.Volt',   false, 'pv', 'RO 1320'],
                ['pv2_curr', 'PV2 current',    'F', 'SPL.Ampere', false, 'pv', 'RO 1321'],
                ['pv3_volt', 'PV3 voltage', 'F', 'SPL.Volt',   false, 'pv', 'RO 1322'],
                ['pv3_curr', 'PV3 current',    'F', 'SPL.Ampere', false, 'pv', 'RO 1323'],
            ]],
            'GroupBat' => ['caption' => 'Battery (voltage, current, SOC, SOH, temperature)', 'vars' => [
                ['bat_volt', 'Bat. voltage',   'F', 'SPL.Volt',       false, 'bat', 'RO 1616'],
                ['bat_curr', 'Bat. current',      'F', 'SPL.Ampere',     false, 'bat', 'RO 1617'],
                ['bat_soc',  'Bat. SOC',        'I', '~Battery.100',   true,  'bat', 'RO 1621'],
                ['bat_soh',  'Bat. SOH',        'I', '~Intensity.100', true,  'bat', 'RO 1622'],
                ['bat_temp', 'Bat. temperature', 'F', '~Temperature',   true,  'bat', 'RO 1620'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp_internal', 'Indoor temperature', 'F', '~Temperature', false, 'device', 'RO 1310'],
                ['temp_env',      'Ambient temperature', 'F', '~Temperature', false, 'device', 'RO 1680'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (day/total)', 'vars' => [
                ['e_pv_day',      'PV today',            'F', '~Electricity', true, 'energy', 'RO 1602'],
                ['e_pv_total',    'PV total',           'F', '~Electricity', true, 'energy', 'RO 1604'],
                ['e_inv_day',     'Inverter today','F', '~Electricity', true, 'energy', 'RO 1302'],
                ['e_inv_total',   'Inverter total','F', '~Electricity', true, 'energy', 'RO 1304'],
                ['e_charge_day',  'Bat. charge today',    'F', '~Electricity', true, 'energy', 'RO 1625'],
                ['e_disch_day',   'Bat. discharge today', 'F', '~Electricity', true, 'energy', 'RO 1627'],
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
            'SPL.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0, 0],
            'SPL.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1, 1],
            'SPL.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1, 1],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $sys = $mb->readInput(1368, 4);   // 1368-1371: Apparent(2)+Active(2)
        $pv  = $mb->readInput(1600, 2);   // 1600-1601: PV total power (U32)
        $bat = $mb->readInput(1616, 7);   // 1616-1622

        $ok = ($sys !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('ac_power', (float)$mb->s32($sys, 2));
        if ($pv !== null) {
            $hub->SetVarFloat('pv_total', (float)$mb->u32($pv, 0));
        }
        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s32($bat, 2));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $pvd = $mb->readInput(1318, 6);
            if ($pvd !== null) {
                $hub->SetVarFloat('pv1_volt', $mb->u16($pvd, 0) / 10.0);
                $hub->SetVarFloat('pv1_curr', $mb->u16($pvd, 1) / 100.0);
                $hub->SetVarFloat('pv2_volt', $mb->u16($pvd, 2) / 10.0);
                $hub->SetVarFloat('pv2_curr', $mb->u16($pvd, 3) / 100.0);
                $hub->SetVarFloat('pv3_volt', $mb->u16($pvd, 4) / 10.0);
                $hub->SetVarFloat('pv3_curr', $mb->u16($pvd, 5) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 0) / 100.0);
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 1) / 10.0);
            $hub->SetVarInt('bat_soc',    $mb->u16($bat, 5));
            $hub->SetVarInt('bat_soh',    $mb->u16($bat, 6));
        }
        if ($hub->GetPropBool('GroupBat')) {
            $batTemp = $mb->readInput(1620, 1);
            if ($batTemp !== null) {
                $hub->SetVarFloat('bat_temp', $mb->s16($batTemp, 0) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $t1 = $mb->readInput(1310, 1);
            if ($t1 !== null) {
                $hub->SetVarFloat('temp_internal', $mb->s16($t1, 0) / 10.0);
            }
            $t2 = $mb->readInput(1680, 1);
            if ($t2 !== null) {
                $hub->SetVarFloat('temp_env', $mb->u16($t2, 0) / 10.0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e1 = $mb->readInput(1302, 4); // 1302-1305
        if ($e1 !== null) {
            $hub->SetVarFloat('e_inv_day',   $mb->u32($e1, 0) / 10.0);
            $hub->SetVarFloat('e_inv_total', $mb->u32($e1, 2) / 10.0);
        }
        $e2 = $mb->readInput(1602, 4); // 1602-1605
        if ($e2 !== null) {
            $hub->SetVarFloat('e_pv_day',   $mb->u32($e2, 0) / 10.0);
            $hub->SetVarFloat('e_pv_total', $mb->u32($e2, 2) / 10.0);
        }
        $e3 = $mb->readInput(1625, 4); // 1625-1628
        if ($e3 !== null) {
            $hub->SetVarFloat('e_charge_day', $mb->u32($e3, 0) / 10.0);
            $hub->SetVarFloat('e_disch_day',  $mb->u32($e3, 2) / 10.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}
