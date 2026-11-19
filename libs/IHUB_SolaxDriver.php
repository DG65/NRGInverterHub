<?php

// ---------------------------------------------------------------------------
// IHUB_SolaxDriver — SolaX Hybrid X1/X3-Serie. WICHTIG: Der Wechselrichter selbst
// spricht nur Modbus RTU — Modbus TCP läuft ausschließlich über ein
// zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als RTU/TCP-Gateway.
// Registeradressen direkt verwendet, Read Input Register (FC 0x04).
// ---------------------------------------------------------------------------

class IHUB_SolaxDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected',   'Connection',         'B', '~Alert.Reversed', false, 'errors', ''],
            ['grid_status', 'Grid status',         'I', 'SLX.GridStatus',  false, 'grid',   'RO 0x001A'],
            ['pv_total',    'PV total power',  'F', 'SLX.Watt',        true,  'pv',     'RO 0x0032-0x0033'],
            ['ongrid_total','On-grid total power', 'F', 'SLX.Watt',    true,  'device', 'RO 0x0034-0x0035'],
            ['bat_power',   'Bat. power',      'F', 'SLX.Watt',        true,  'bat',    'RO 0x0038-0x0039'],
            ['bat_soc',     'Bat. SOC',           'I', '~Battery.100',    true,  'bat',    'RO 0x003C'],
        ];
    }

    public function getOptionalGroups()
    {
        $groups = [
            'GroupPV' => ['caption' => 'PV details (up to 6 strings)', 'vars' => [
                ['pv1_volt', 'PV1 voltage', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0003'],
                ['pv1_curr', 'PV1 current',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0005'],
                ['pv1_power','PV1 power', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x000A'],
                ['pv2_volt', 'PV2 voltage', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0004'],
                ['pv2_curr', 'PV2 current',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0006'],
                ['pv2_power','PV2 power', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x000B'],
                ['pv3_volt', 'PV3 voltage', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0122'],
                ['pv3_curr', 'PV3 current',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0123'],
                ['pv3_power','PV3 power', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x0124'],
                ['pv4_volt', 'PV4 voltage', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0028'],
                ['pv5_volt', 'PV5 voltage', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0029'],
                ['pv6_volt', 'PV6 voltage', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x002A'],
                ['pv4_curr', 'PV4 current',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002B'],
                ['pv5_curr', 'PV5 current',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002C'],
                ['pv6_curr', 'PV6 current',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002D'],
                ['pv4_power','PV4 power', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x002E'],
                ['pv5_power','PV5 power', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x002F'],
                ['pv6_power','PV6 power', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x0030'],
            ]],
            'GroupGridX1' => ['caption' => 'Grid single-phase (X1 models)', 'vars' => [
                ['grid_volt', 'Grid voltage',  'F', 'SLX.Volt',   false, 'grid', 'RO 0x0000'],
                ['grid_curr', 'Grid current',     'F', 'SLX.Ampere', false, 'grid', 'RO 0x0001'],
                ['grid_pwr',  'Grid power',  'F', 'SLX.Watt',   true,  'grid', 'RO 0x0002'],
                ['grid_freq', 'Grid frequency',   'F', 'SLX.Hertz',  false, 'grid', 'RO 0x0007'],
            ]],
            'GroupGridX3' => ['caption' => 'Grid three-phase (X3 models)', 'vars' => [
                ['grid_r_volt', 'Grid R voltage', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x006A'],
                ['grid_r_curr', 'Grid R current',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x006B'],
                ['grid_r_pwr',  'Grid R power', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x006C'],
                ['grid_s_volt', 'Grid S voltage', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x006E'],
                ['grid_s_curr', 'Grid S current',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x006F'],
                ['grid_s_pwr',  'Grid S power', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x0070'],
                ['grid_t_volt', 'Grid T voltage', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x0072'],
                ['grid_t_curr', 'Grid T current',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x0073'],
                ['grid_t_pwr',  'Grid T power', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x0074'],
            ]],
            'GroupBat' => ['caption' => 'Battery system values (total)', 'vars' => [
                ['bat_capacity', 'Bat. installed capacity', 'F', '~Electricity', false, 'bat', 'RO 0x003A-0x003B'],
                ['bat_soh',      'Bat. SOH', 'I', '~Intensity.100', true, 'bat', 'RO 0x003D'],
            ]],
            'GroupMeter' => ['caption' => 'Meter/CT (feed-in power)', 'vars' => [
                ['feedin_total', 'Feed-in power total', 'F', 'SLX.Watt', true, 'meter', 'RO 0x0046-0x0047'],
                ['feedin_r',     'Feed-in power R',      'F', 'SLX.Watt', true, 'meter', 'RO 0x0082-0x0083'],
                ['feedin_s',     'Feed-in power S',      'F', 'SLX.Watt', true, 'meter', 'RO 0x0084-0x0085'],
            ]],
            'GroupOffgrid' => ['caption' => 'Off-grid / EPS (emergency power)', 'vars' => [
                ['offgrid_total', 'Off-grid total power', 'F', 'SLX.Watt', true, 'backup', 'RO 0x0036-0x0037'],
            ]],
            'GroupDevice' => ['caption' => 'Device information', 'vars' => [
                ['dev_type', 'Inverter type', 'I', '', false, 'device', 'RO 0x0015'],
                ['dev_sn',   'Module serial number', 'S', '', false, 'device', 'RO 0x00AA-0x00AE'],
            ]],
        ];
        // Fernsteuerung (VPP-Modus 8, neue Schnittstelle 0xA0 ff.), UNGETESTET: Vorgabe aus, gemeinsame Schicht IHUB_RemoteControl
        $groups['GroupControl'] = ['caption' => 'Remote control (untested: built from the manufacturer document and community code, off by default)', 'default' => false, 'vars' => IHUB_RemoteControl::vars()];
        return $groups;
    }


    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLX.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLX.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLX.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'SLX.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ] + IHUB_RemoteControl::profiles();
    }

    public function getEnumProfiles()
    {
        return [
            'SLX.GridStatus' => [
                0 => ['On-grid', 0x27D07F],
                1 => ['Off-grid', 0xE74C3C],
            ],
        ] + IHUB_RemoteControl::enumProfiles();
    }

    public function readFast($mb, $hub)
    {
        $pv1  = $mb->readInput(0x0000, 12);      // 0x0000-0x000B (Grid X1 + PV1/2 V/I/P)
        $sys  = $mb->readInput(0x001A, 36);       // 0x001A-0x003D (Status, PV total, Bat total)
        $ext3 = $mb->readInput(0x0028, 9);        // 0x0028-0x0030 (PV4-6)
        $pv3  = $mb->readInput(0x0122, 3);        // 0x0122-0x0124 (PV3)

        $ok = ($sys !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('grid_status', $mb->u16($sys, 0));                  // 0x001A
        $hub->SetVarFloat('pv_total',     (float)$mb->u32($sys, 24));       // 0x0032-33
        $hub->SetVarFloat('ongrid_total', (float)$mb->s32($sys, 26));       // 0x0034-35
        $hub->SetVarFloat('bat_power',    (float)$mb->s32($sys, 30));       // 0x0038-39
        $hub->SetVarInt('bat_soc', $mb->u16($sys, 34));                     // 0x003C

        if ($hub->GetPropBool('GroupPV') && $pv1 !== null) {
            $hub->SetVarFloat('pv1_volt',  $mb->u16($pv1, 3)  / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($pv1, 5)  / 10.0);
            $hub->SetVarFloat('pv1_power', (float)$mb->u16($pv1, 10));
            $hub->SetVarFloat('pv2_volt',  $mb->u16($pv1, 4)  / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($pv1, 6)  / 10.0);
            $hub->SetVarFloat('pv2_power', (float)$mb->u16($pv1, 11));
            if ($pv3 !== null) {
                $hub->SetVarFloat('pv3_volt',  $mb->u16($pv3, 0) / 10.0);
                $hub->SetVarFloat('pv3_curr',  $mb->u16($pv3, 1) / 10.0);
                $hub->SetVarFloat('pv3_power', (float)$mb->u16($pv3, 2));
            }
            if ($ext3 !== null) {
                $hub->SetVarFloat('pv4_volt',  $mb->u16($ext3, 0) / 10.0);
                $hub->SetVarFloat('pv5_volt',  $mb->u16($ext3, 1) / 10.0);
                $hub->SetVarFloat('pv6_volt',  $mb->u16($ext3, 2) / 10.0);
                $hub->SetVarFloat('pv4_curr',  $mb->u16($ext3, 3) / 10.0);
                $hub->SetVarFloat('pv5_curr',  $mb->u16($ext3, 4) / 10.0);
                $hub->SetVarFloat('pv6_curr',  $mb->u16($ext3, 5) / 10.0);
                $hub->SetVarFloat('pv4_power', (float)$mb->u16($ext3, 6));
                $hub->SetVarFloat('pv5_power', (float)$mb->u16($ext3, 7));
                $hub->SetVarFloat('pv6_power', (float)$mb->u16($ext3, 8));
            }
        }

        if ($hub->GetPropBool('GroupGridX1') && $pv1 !== null) {
            $hub->SetVarFloat('grid_volt', $mb->u16($pv1, 0) / 10.0);
            $hub->SetVarFloat('grid_curr', $mb->s16($pv1, 1) / 10.0);
            $hub->SetVarFloat('grid_pwr',  (float)$mb->s16($pv1, 2));
            $hub->SetVarFloat('grid_freq', $mb->u16($pv1, 7) / 100.0);
        }

        if ($hub->GetPropBool('GroupGridX3')) {
            $g3 = $mb->readInput(0x006A, 12); // 0x006A-0x0075
            if ($g3 !== null) {
                $hub->SetVarFloat('grid_r_volt', $mb->u16($g3, 0) / 10.0);
                $hub->SetVarFloat('grid_r_curr', $mb->s16($g3, 1) / 10.0);
                $hub->SetVarFloat('grid_r_pwr',  (float)$mb->s16($g3, 2));
                $hub->SetVarFloat('grid_s_volt', $mb->u16($g3, 4) / 10.0);
                $hub->SetVarFloat('grid_s_curr', $mb->s16($g3, 5) / 10.0);
                $hub->SetVarFloat('grid_s_pwr',  (float)$mb->s16($g3, 6));
                $hub->SetVarFloat('grid_t_volt', $mb->u16($g3, 8) / 10.0);
                $hub->SetVarFloat('grid_t_curr', $mb->s16($g3, 9) / 10.0);
                $hub->SetVarFloat('grid_t_pwr',  (float)$mb->s16($g3, 10));
            }
        }

        if ($hub->GetPropBool('GroupBat')) {
            $hub->SetVarFloat('bat_capacity', $mb->u32($sys, 32) / 1000.0); // 0x003A-3B, Wh -> kWh
            $hub->SetVarInt('bat_soh', $mb->u16($sys, 35));                 // 0x003D
        }

        if ($hub->GetPropBool('GroupOffgrid')) {
            $hub->SetVarFloat('offgrid_total', (float)$mb->s32($sys, 28));  // 0x0036-37
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $mb->readInput(0x0046, 64); // 0x0046-0x0085
            if ($meter !== null) {
                $hub->SetVarFloat('feedin_total', (float)$mb->s32($meter, 0));
                $hub->SetVarFloat('feedin_r', (float)$mb->s32($meter, 0x3C)); // 0x0082-83
                $hub->SetVarFloat('feedin_s', (float)$mb->s32($meter, 0x3E)); // 0x0084-85
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupControl')) {
            IHUB_RemoteControl::refresh(new IHUB_RemoteSolax(), $mb, $hub);   // Fernsteuerung (VPP 0xA0 ff.): Fähigkeit und Kennzeichnung
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $type = $mb->readHolding(0x0015, 1);
        if ($type !== null) {
            $hub->SetVarInt('dev_type', $mb->u16($type, 0));
        }
        $sn = $mb->readHolding(0x00AA, 5);
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 5));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Nur die gemeinsame Fernsteuerung (VPP-Modus 8), UNGETESTET (rc_tested = false); keine weiteren Schreibwege.
        if (strpos($ident, 'ctl_rc_') === 0) {
            IHUB_RemoteControl::control(new IHUB_RemoteSolax(), $mb, $hub, $ident, $value);
        }
    }
}
