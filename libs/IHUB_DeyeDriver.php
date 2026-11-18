<?php

// ---------------------------------------------------------------------------
// IHUB_DeyeDriver — Deye Hybrid-Wechselrichter (SG04LP3-Serie). Direkte
// Registeradressierung, ausschließlich Einzelregister (FC 0x03).
// Register 2026-07-16 gegen eine community-getestete Deye-Modbus-Vorlage
// aus dem IP-Symcon-Forum übernommen (getestet an einem Deye 8K-SG04LP3).
// ---------------------------------------------------------------------------

class IHUB_DeyeDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Operating status',    'I', '',                true,  'device', 'RO 500'],
            ['pv_total',  'PV total power', 'F', 'DYE.Watt',        true,  'pv',     'Σ RO 672+673'],
            ['bat_power', 'Bat. power',     'F', 'DYE.Watt',        true,  'bat',    'RO 590'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV details (string 1+2)', 'vars' => [
                ['pv1_power', 'PV1 power', 'F', 'DYE.Watt',   true,  'pv', 'RO 672'],
                ['pv1_volt',  'PV1 voltage', 'F', 'DYE.Volt',   false, 'pv', 'RO 676'],
                ['pv1_curr',  'PV1 current',    'F', 'DYE.Ampere', false, 'pv', 'RO 677'],
                ['pv2_power', 'PV2 power', 'F', 'DYE.Watt',   true,  'pv', 'RO 673'],
                ['pv2_volt',  'PV2 voltage', 'F', 'DYE.Volt',   false, 'pv', 'RO 678'],
                ['pv2_curr',  'PV2 current',    'F', 'DYE.Ampere', false, 'pv', 'RO 679'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, current per phase, frequency)', 'vars' => [
                ['grid_l1_volt', 'Grid L1 voltage', 'F', 'DYE.Volt',   false, 'grid', 'RO 598'],
                ['grid_l2_volt', 'Grid L2 voltage', 'F', 'DYE.Volt',   false, 'grid', 'RO 599'],
                ['grid_l3_volt', 'Grid L3 voltage', 'F', 'DYE.Volt',   false, 'grid', 'RO 600'],
                ['grid_l1_curr', 'Grid L1 current',    'F', 'DYE.Ampere', false, 'grid', 'RO 616'],
                ['grid_l2_curr', 'Grid L2 current',    'F', 'DYE.Ampere', false, 'grid', 'RO 617'],
                ['grid_l3_curr', 'Grid L3 current',    'F', 'DYE.Ampere', false, 'grid', 'RO 618'],
                ['grid_total',   'Grid import total',  'F', 'DYE.Watt',   true,  'grid', 'RO 619'],
                ['grid_freq',    'Grid frequency',      'F', 'DYE.Hertz',  false, 'grid', 'RO 638'],
            ]],
            'GroupBat' => ['caption' => 'Battery (voltage, current, SOC, temperature)', 'vars' => [
                ['bat_volt', 'Bat. voltage',   'F', 'DYE.Volt',     false, 'bat', 'RO 587'],
                ['bat_soc',  'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 588'],
                ['bat_curr', 'Bat. current',      'F', 'DYE.Ampere',   false, 'bat', 'RO 591'],
                ['bat_temp', 'Bat. temperature', 'F', '~Temperature', true,  'bat', 'RO 586'],
            ]],
            'GroupLoad' => ['caption' => 'House consumption (load)', 'vars' => [
                ['load_l1',    'Load L1',      'F', 'DYE.Watt', true, 'device', 'RO 650'],
                ['load_l2',    'Load L2',      'F', 'DYE.Watt', true, 'device', 'RO 651'],
                ['load_l3',    'Load L3',      'F', 'DYE.Watt', true, 'device', 'RO 652'],
                ['load_total', 'Load total',  'F', 'DYE.Watt', true, 'device', 'RO 653'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (day/total)', 'vars' => [
                ['e_charge_day',    'Bat. charge today',    'F', '~Electricity', true, 'energy', 'RO 514'],
                ['e_disch_day',     'Bat. discharge today', 'F', '~Electricity', true, 'energy', 'RO 515'],
                ['e_charge_total',  'Bat. charge total',   'F', '~Electricity', true, 'energy', 'RO 516'],
                ['e_disch_total',   'Bat. discharge total','F', '~Electricity', true, 'energy', 'RO 518'],
                ['e_buy_day',       'Grid import today',     'F', '~Electricity', true, 'energy', 'RO 520'],
                ['e_sell_day',      'Feed-in today',   'F', '~Electricity', true, 'energy', 'RO 521'],
                ['e_buy_total',     'Grid import total',    'F', '~Electricity', true, 'energy', 'RO 522'],
                ['e_sell_total',    'Feed-in total',  'F', '~Electricity', true, 'energy', 'RO 524'],
                ['e_load_day',      'House consumption today', 'F', '~Electricity', true, 'energy', 'RO 526'],
                ['e_load_total',    'House consumption total','F', '~Electricity', true, 'energy', 'RO 527'],
                ['e_pv_day',        'PV today',            'F', '~Electricity', true, 'energy', 'RO 529'],
                ['e_pv_total',      'PV total',           'F', '~Electricity', true, 'energy', 'RO 534'],
            ]],
            'GroupControl' => ['caption' => 'Control', 'vars' => [
                ['ctl_onoff', 'Inverter on/off', 'B', '~Switch', false, 'control', 'RW 80'],
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
            'DYE.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'DYE.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'DYE.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'DYE.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $status = $mb->readHolding(500, 1);
        $pv     = $mb->readHolding(672, 8);   // 672-679: PV1/2 power,volt,curr interleaved
        $bat    = $mb->readHolding(586, 6);   // 586-591: temp,volt,soc,-,power,current
        $grid   = $mb->readHolding(598, 3);   // 598-600: L1-L3 Spannung
        $gcurr  = $mb->readHolding(616, 4);   // 616-619: L1-L3 Strom + Gesamt
        $freq   = $mb->readHolding(638, 1);
        $load   = $mb->readHolding(650, 4);   // 650-653

        $ok = ($status !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($status, 0));

        $pv1w = ($pv !== null) ? $mb->s16($pv, 0) : 0;
        $pv2w = ($pv !== null) ? $mb->s16($pv, 1) : 0;
        $hub->SetVarFloat('pv_total', (float)($pv1w + $pv2w));

        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s16($bat, 4));
        }

        if ($hub->GetPropBool('GroupPV') && $pv !== null) {
            $hub->SetVarFloat('pv1_power', (float)$pv1w);
            $hub->SetVarFloat('pv1_volt',  $mb->u16($pv, 4) / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($pv, 5) / 10.0);
            $hub->SetVarFloat('pv2_power', (float)$pv2w);
            $hub->SetVarFloat('pv2_volt',  $mb->u16($pv, 6) / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($pv, 7) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            if ($grid !== null) {
                $hub->SetVarFloat('grid_l1_volt', $mb->u16($grid, 0) / 10.0);
                $hub->SetVarFloat('grid_l2_volt', $mb->u16($grid, 1) / 10.0);
                $hub->SetVarFloat('grid_l3_volt', $mb->u16($grid, 2) / 10.0);
            }
            if ($gcurr !== null) {
                $hub->SetVarFloat('grid_l1_curr', (float)$mb->s16($gcurr, 0));
                $hub->SetVarFloat('grid_l2_curr', (float)$mb->s16($gcurr, 1));
                $hub->SetVarFloat('grid_l3_curr', (float)$mb->s16($gcurr, 2));
                $hub->SetVarFloat('grid_total',   (float)$mb->s16($gcurr, 3));
            }
            if ($freq !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freq, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_temp', $mb->s16($bat, 0) / 10.0);
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 1) / 100.0);
            $hub->SetVarInt('bat_soc',    $mb->u16($bat, 2));
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 5) / 100.0);
        }

        if ($hub->GetPropBool('GroupLoad') && $load !== null) {
            $hub->SetVarFloat('load_l1',    (float)$mb->s16($load, 0));
            $hub->SetVarFloat('load_l2',    (float)$mb->s16($load, 1));
            $hub->SetVarFloat('load_l3',    (float)$mb->s16($load, 2));
            $hub->SetVarFloat('load_total', (float)$mb->s16($load, 3));
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e1 = $mb->readHolding(514, 11); // 514-524 (mit Lücken)
        if ($e1 !== null) {
            $hub->SetVarFloat('e_charge_day',   $mb->u16($e1, 0) / 10.0);
            $hub->SetVarFloat('e_disch_day',    $mb->u16($e1, 1) / 10.0);
            $hub->SetVarFloat('e_charge_total', $mb->u16($e1, 2) / 10.0);
            $hub->SetVarFloat('e_disch_total',  $mb->u16($e1, 4) / 10.0);
            $hub->SetVarFloat('e_buy_day',      $mb->u16($e1, 6) / 10.0);
            $hub->SetVarFloat('e_sell_day',     $mb->u16($e1, 7) / 10.0);
            $hub->SetVarFloat('e_buy_total',    $mb->u16($e1, 8) / 10.0);
            $hub->SetVarFloat('e_sell_total',   $mb->u16($e1, 10) / 10.0);
        }
        $e2 = $mb->readHolding(526, 9); // 526-534 (mit Lücken)
        if ($e2 !== null) {
            $hub->SetVarFloat('e_load_day',   $mb->u16($e2, 0) / 10.0);
            $hub->SetVarFloat('e_load_total', $mb->u16($e2, 1) / 10.0);
            $hub->SetVarFloat('e_pv_day',     $mb->u16($e2, 3) / 10.0);
            $hub->SetVarFloat('e_pv_total',   $mb->u16($e2, 8) / 10.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        if ($ident === 'ctl_onoff') {
            $val = (bool)$value ? 1 : 0;
            if ($mb->writeSingle(80, $val)) {
                $hub->SetVarBool('ctl_onoff', (bool)$value);
            }
        }
    }
}
