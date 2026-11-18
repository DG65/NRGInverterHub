<?php

// ---------------------------------------------------------------------------
// IHUB_GrowattDriver — Growatt Wechselrichter (TL-X/TL3-X/MOD/MIX/SPH/WIT-Serie).
// Durchnummerierte Input-Register (FC 0x04), 32-Bit-Werte als H/L-Paar.
// Modellfamilien unterscheiden sich in Registerbereichen (siehe Growatt-
// Protokoll Kapitel 1.2) — abgedeckt ist der gemeinsame Basisbereich
// (Register-Index 0-107), der bei praktisch allen Modellfamilien gilt.
// ---------------------------------------------------------------------------

class IHUB_GrowattDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [0 => 'Wait', 1 => 'Normal', 3 => 'Fault'];

    public function getBaseVars()
    {
        return [
            ['connected',  'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',     'Operating status',    'I', 'GRW.Status',       true,  'device', 'Input 0'],
            ['pv_total',   'PV total power', 'F', 'GRW.Watt',         true,  'pv',     'Input 1-2'],
            ['ac_power',   'AC active power',   'F', 'GRW.Watt',         true,  'device', 'Input 35-36'],
            ['grid_freq',  'Grid frequency',      'F', 'GRW.Hertz',        false, 'grid',   'Input 37'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupRiso' => ['caption' => 'Insulation resistance (model-dependent — only populated on some models)', 'vars' => [
                ['riso', 'Insulation resistance', 'F', 'GRW.KOhm', true, 'pv', 'Input 200 (kΩ, model-dependent)'],
            ]],
            'GroupPV' => ['caption' => 'PV details (string 1-3, voltage/current/power)', 'vars' => [
                ['pv1_volt', 'PV1 voltage', 'F', 'GRW.Volt',   false, 'pv', 'Input 3'],
                ['pv1_curr', 'PV1 current',    'F', 'GRW.Ampere', false, 'pv', 'Input 4'],
                ['pv1_power','PV1 power', 'F', 'GRW.Watt',   true,  'pv', 'Input 5-6'],
                ['pv2_volt', 'PV2 voltage', 'F', 'GRW.Volt',   false, 'pv', 'Input 7'],
                ['pv2_curr', 'PV2 current',    'F', 'GRW.Ampere', false, 'pv', 'Input 8'],
                ['pv2_power','PV2 power', 'F', 'GRW.Watt',   true,  'pv', 'Input 9-10'],
                ['pv3_volt', 'PV3 voltage', 'F', 'GRW.Volt',   false, 'pv', 'Input 11'],
                ['pv3_curr', 'PV3 current',    'F', 'GRW.Ampere', false, 'pv', 'Input 12'],
                ['pv3_power','PV3 power', 'F', 'GRW.Watt',   true,  'pv', 'Input 13-14'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, current, power per phase)', 'vars' => [
                ['vac1', 'Grid L1 voltage', 'F', 'GRW.Volt',   false, 'grid', 'Input 38'],
                ['iac1', 'Grid L1 current',    'F', 'GRW.Ampere', false, 'grid', 'Input 39'],
                ['pac1', 'Grid L1 power', 'F', 'GRW.Watt',   true,  'grid', 'Input 40-41'],
                ['vac2', 'Grid L2 voltage', 'F', 'GRW.Volt',   false, 'grid', 'Input 42'],
                ['iac2', 'Grid L2 current',    'F', 'GRW.Ampere', false, 'grid', 'Input 43'],
                ['pac2', 'Grid L2 power', 'F', 'GRW.Watt',   true,  'grid', 'Input 44-45'],
                ['vac3', 'Grid L3 voltage', 'F', 'GRW.Volt',   false, 'grid', 'Input 46'],
                ['iac3', 'Grid L3 current',    'F', 'GRW.Ampere', false, 'grid', 'Input 47'],
                ['pac3', 'Grid L3 power', 'F', 'GRW.Watt',   true,  'grid', 'Input 48-49'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (day/total)', 'vars' => [
                ['e_day',   'Yield today',  'F', '~Electricity', true, 'energy', 'Input 53-54'],
                ['e_total', 'Yield total', 'F', '~Electricity', true, 'energy', 'Input 55-56'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp1', 'Inverter temperature', 'F', '~Temperature', false, 'device', 'Input 93'],
            ]],
            'GroupErrors' => ['caption' => 'Error codes', 'vars' => [
                ['fault_main', 'Main error code', 'I', '', true, 'errors', 'Input 105'],
                ['fault_sub',  'Error subcode',   'I', '', true, 'errors', 'Input 107'],
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
            'GRW.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'GRW.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'GRW.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'GRW.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'GRW.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ',      0.0, 65535.0, 1.0,  0],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['GRW.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        $blk = $mb->readInput(0, 108); // Index 0-107

        $ok = ($blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($blk, 0));
        $hub->SetVarFloat('pv_total', $mb->u32($blk, 1) / 10.0);
        $hub->SetVarFloat('ac_power', $mb->u32($blk, 35) / 10.0);
        $hub->SetVarFloat('grid_freq', $mb->u16($blk, 37) / 100.0);

        // Isolationswiderstand liegt außerhalb des 0-107-Blocks (Input 200) und
        // ist nur bei manchen Growatt-Modellen belegt — daher optional/separat.
        if ($hub->GetPropBool('GroupRiso')) {
            $r = $mb->readInput(200, 1);
            if ($r !== null) {
                $hub->SetVarFloat('riso', (float)$mb->u16($r, 0));
            }
        }

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt',  $mb->u16($blk, 3) / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($blk, 4) / 10.0);
            $hub->SetVarFloat('pv1_power', $mb->u32($blk, 5) / 10.0);
            $hub->SetVarFloat('pv2_volt',  $mb->u16($blk, 7) / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($blk, 8) / 10.0);
            $hub->SetVarFloat('pv2_power', $mb->u32($blk, 9) / 10.0);
            $hub->SetVarFloat('pv3_volt',  $mb->u16($blk, 11) / 10.0);
            $hub->SetVarFloat('pv3_curr',  $mb->u16($blk, 12) / 10.0);
            $hub->SetVarFloat('pv3_power', $mb->u32($blk, 13) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('vac1', $mb->u16($blk, 38) / 10.0);
            $hub->SetVarFloat('iac1', $mb->u16($blk, 39) / 10.0);
            $hub->SetVarFloat('pac1', $mb->u32($blk, 40) / 10.0);
            $hub->SetVarFloat('vac2', $mb->u16($blk, 42) / 10.0);
            $hub->SetVarFloat('iac2', $mb->u16($blk, 43) / 10.0);
            $hub->SetVarFloat('pac2', $mb->u32($blk, 44) / 10.0);
            $hub->SetVarFloat('vac3', $mb->u16($blk, 46) / 10.0);
            $hub->SetVarFloat('iac3', $mb->u16($blk, 47) / 10.0);
            $hub->SetVarFloat('pac3', $mb->u32($blk, 48) / 10.0);
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            $hub->SetVarFloat('e_day',   $mb->u32($blk, 53) / 10.0);
            $hub->SetVarFloat('e_total', $mb->u32($blk, 55) / 10.0);
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp1', $mb->s16($blk, 93) / 10.0);
        }

        if ($hub->GetPropBool('GroupErrors')) {
            $hub->SetVarInt('fault_main', $mb->u16($blk, 105));
            $hub->SetVarInt('fault_sub',  $mb->u16($blk, 107));
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Alle Werte werden bereits in readFast() in einem Block gelesen.
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
