<?php

// ---------------------------------------------------------------------------
// IHUB_FoxEssDriver — FoxESS H1/H3 Single Phase Hybrid Inverter. Register aus der
// offiziellen "Fox Hybrid/AC Modbus Protocol"-Dokumentation (V1.01, 2021.09.02),
// vollständig gelesen (nicht nur auszugsweise).
//
// READ-ONLY-MVP, bewusst beschränkt auf den Echtzeit-/Zähler-Block 11000-11095
// (96 zusammenhängende Register, ein Leseblock). Dafür zeigt die Doku explizite
// Beispielrahmen mit Funktionscode 0x04 (Read Registers) - hier also FC04 wie
// dokumentiert (readInput), analog IHUB_SolplanetDriver.
//
// BEWUSST NICHT eingebaut, bis ein Beta-Tester es an echter Hardware verifiziert
// hat (Lehre aus dem SMA-FC03/FC04-Fehler vom selben Tag - siehe CLAUDE.md):
// - Config-/Steuerregister ab 40000 (Work Mode, Lade-Fenster, SOC-Grenzen usw.):
//   Die Doku belegt FC04 nur für die 10000er/11000er-Bloecke; ob 40000+ ebenso
//   ueber FC04 oder wie bei anderen Herstellern ueber FC03 (Holding) gelesen
//   wird, steht nicht explizit da. Erst nach Bestaetigung am echten Geraet
//   implementieren - sonst droht dieselbe Fehldeutung wie bei SMA.
// - "Remote"-Sollwertregister (44000+, Wirk-/Blindleistungsvorgabe fuer
//   Fernsteuerung): dieselbe Risikoklasse wie der GoodWe-47509-Beschriftungs-
//   fehler - erst nach Read-Only-Verifikation und mit Bedacht einbauen.
// - CleanMem-Act (45000-45002): "Clear events/energy", FACTORY RESET, alle
//   WriteOnly - nie als leicht ausloesbare Aktion anbieten.
//
// Word-Order bei den U32-Energiezaehlern (zwei Register je Wert, z. B.
// "11069----11070") ist in der Doku nicht explizit benannt; hier wie bei allen
// anderen Treibern High-Word-zuerst angenommen (Standard-Konvention, passt zum
// vorhandenen u32()-Helfer). Von einem Tester an einem plausiblen kWh-Wert zu
// bestaetigen.
// ---------------------------------------------------------------------------

class IHUB_FoxEssDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        0 => 'Waiting', 1 => 'Check', 2 => 'Grid operation', 3 => 'Island mode',
        4 => 'Recoverable fault', 5 => 'Non-recoverable fault',
    ];
    const BAT_STATUS = [0 => 'Idle', 1 => 'Normal', 2 => 'Offline'];

    public function getBaseVars()
    {
        return [
            ['connected', 'Connection',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Operating status',    'I', 'FOX.Status',      true,  'device', 'RO 11056'],
            ['pv_total',  'PV total power', 'F', 'FOX.Watt',        true,  'pv',     'Σ RO 11002+11005'],
            ['ac_power',  'AC active power',   'F', 'FOX.Watt',        true,  'device', 'RO 11011 (Inv Power_P)'],
            ['bat_power', 'Bat. power',     'F', 'FOX.Watt',        true,  'bat',    'RO 11008 (+ = Entladen)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV details (string 1+2)', 'vars' => [
                ['pv1_volt', 'PV1 voltage', 'F', 'FOX.Volt',   false, 'pv', 'RO 11000'],
                ['pv1_curr', 'PV1 current',    'F', 'FOX.Ampere', false, 'pv', 'RO 11001'],
                ['pv2_volt', 'PV2 voltage', 'F', 'FOX.Volt',   false, 'pv', 'RO 11003'],
                ['pv2_curr', 'PV2 current',    'F', 'FOX.Ampere', false, 'pv', 'RO 11004'],
            ]],
            'GroupGrid' => ['caption' => 'Grid (voltage, frequency)', 'vars' => [
                ['grid_volt', 'Grid voltage', 'F', 'FOX.Volt',  false, 'grid', 'RO 11009'],
                ['grid_freq', 'Grid frequency',  'F', 'FOX.Hertz', false, 'grid', 'RO 11014'],
            ]],
            'GroupBat' => ['caption' => 'Battery (voltage, current, SOC, temperature)', 'vars' => [
                ['bat_volt',    'Bat. voltage',    'F', 'FOX.Volt',     false, 'bat', 'RO 11034 (BMS)'],
                ['bat_curr',    'Bat. current',       'F', 'FOX.Ampere',   false, 'bat', 'RO 11035 (BMS)'],
                ['bat_soc',     'Bat. SOC',         'I', '~Battery.100', true,  'bat', 'RO 11036'],
                ['bat_temp',    'Bat. temperature',  'F', '~Temperature', true,  'bat', 'RO 11038'],
                ['bat_status',  'Bat. status',      'I', 'FOX.BatStat',  false, 'bat', 'RO 11057'],
            ]],
            'GroupEps' => ['caption' => 'Emergency power (EPS/island mode)', 'vars' => [
                ['eps_volt', 'EPS voltage',  'F', 'FOX.Volt',  false, 'backup', 'RO 11015'],
                ['eps_pwr',  'EPS power',  'F', 'FOX.Watt',  true,  'backup', 'RO 11017'],
                ['eps_freq', 'EPS frequency',  'F', 'FOX.Hertz', false, 'backup', 'RO 11020'],
            ]],
            'GroupMeter' => ['caption' => 'Smart meter (power)', 'vars' => [
                ['meter1_pwr', 'Meter 1 power', 'F', 'FOX.Watt', true, 'meter', 'RO 11021'],
                ['meter2_pwr', 'Meter 2 power', 'F', 'FOX.Watt', true, 'meter', 'RO 11022'],
            ]],
            'GroupTemp' => ['caption' => 'Temperature', 'vars' => [
                ['temp_inv', 'Inverter temperature', 'F', '~Temperature', false, 'device', 'RO 11024'],
                ['temp_env', 'Ambient temperature',        'F', '~Temperature', false, 'device', 'RO 11025'],
            ]],
            'GroupEnergy' => ['caption' => 'Energy meters (day/total)', 'vars' => [
                ['e_pv_day',       'PV today',              'F', '~Electricity', true, 'energy', 'RO 11071 (÷10)'],
                ['e_pv_total',     'PV total',             'F', '~Electricity', true, 'energy', 'RO 11069+11070 (U32, ÷10)'],
                ['e_charge_day',   'Bat. charge today',      'F', '~Electricity', true, 'energy', 'RO 11074 (÷10)'],
                ['e_charge_total', 'Bat. charge total',     'F', '~Electricity', true, 'energy', 'RO 11072+11073 (U32, ÷10)'],
                ['e_disch_day',    'Bat. discharge today',   'F', '~Electricity', true, 'energy', 'RO 11077 (÷10)'],
                ['e_disch_total',  'Bat. discharge total',  'F', '~Electricity', true, 'energy', 'RO 11075+11076 (U32, ÷10)'],
                ['e_sell_day',     'Feed-in today',     'F', '~Electricity', true, 'energy', 'RO 11080 (÷10)'],
                ['e_sell_total',   'Feed-in total',    'F', '~Electricity', true, 'energy', 'RO 11078+11079 (U32, ÷10)'],
                ['e_load_day',     'House consumption today',   'F', '~Electricity', true, 'energy', 'RO 11092 (÷10)'],
                ['e_load_total',   'House consumption total',  'F', '~Electricity', true, 'energy', 'RO 11090+11091 (U32, ÷10)'],
            ]],
            'GroupDevice' => ['caption' => 'Device information', 'vars' => [
                ['dev_model', 'Model',       'S', '', false, 'device', 'RO 10000-10007 (STR)'],
                ['dev_sn',    'Serial number', 'S', '', false, 'device', 'RO 10008-10015 (STR)'],
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
            'FOX.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'FOX.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'FOX.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'FOX.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        $batStatus = [];
        foreach (self::BAT_STATUS as $k => $label) {
            $batStatus[$k] = [$label, 0x7A8A99];
        }
        return ['FOX.Status' => $status, 'FOX.BatStat' => $batStatus];
    }

    public function readFast($mb, $hub)
    {
        // Ein Block ueber den gesamten Echtzeit-/Zaehler-Bereich (96 Register,
        // 11000-11095) - laut Doku per FC04 (Read Registers) lesbar, siehe
        // IHUB_SolplanetDriver fuer dasselbe Muster mit readInput().
        $blk = $mb->readInput(11000, 96);
        $ok  = ($blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($blk, 56));   // 11056 Inverter state

        $pv1w = (float)$mb->s16($blk, 2);   // 11002 PV1 power
        $pv2w = (float)$mb->s16($blk, 5);   // 11005 PV2 power
        $hub->SetVarFloat('pv_total', $pv1w + $pv2w);
        $hub->SetVarFloat('ac_power', (float)$mb->s16($blk, 11));   // 11011 Inv Power_P
        $hub->SetVarFloat('bat_power', (float)$mb->s16($blk, 8));   // 11008 Battery power

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt', $mb->s16($blk, 0) / 10.0);   // 11000
            $hub->SetVarFloat('pv1_curr', $mb->s16($blk, 1) / 10.0);   // 11001
            $hub->SetVarFloat('pv2_volt', $mb->s16($blk, 3) / 10.0);   // 11003
            $hub->SetVarFloat('pv2_curr', $mb->s16($blk, 4) / 10.0);   // 11004
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_volt', $mb->u16($blk, 9) / 10.0);   // 11009 (U16 lt. Doku)
            $hub->SetVarFloat('grid_freq', $mb->u16($blk, 14) / 100.0); // 11014
        }

        if ($hub->GetPropBool('GroupBat')) {
            $hub->SetVarFloat('bat_volt', $mb->s16($blk, 34) / 10.0);  // 11034 BMS
            $hub->SetVarFloat('bat_curr', $mb->s16($blk, 35) / 10.0);  // 11035 BMS
            $hub->SetVarInt('bat_soc',    $mb->u16($blk, 36));         // 11036
            $hub->SetVarFloat('bat_temp', $mb->s16($blk, 38) / 10.0);  // 11038
            $hub->SetVarInt('bat_status', $mb->u16($blk, 57));         // 11057
        }

        if ($hub->GetPropBool('GroupEps')) {
            $hub->SetVarFloat('eps_volt', $mb->u16($blk, 15) / 10.0);  // 11015
            $hub->SetVarFloat('eps_pwr',  (float)$mb->s16($blk, 17));  // 11017 Epspower_P
            $hub->SetVarFloat('eps_freq', $mb->u16($blk, 20) / 100.0); // 11020
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $hub->SetVarFloat('meter1_pwr', (float)$mb->s16($blk, 21));   // 11021
            $hub->SetVarFloat('meter2_pwr', (float)$mb->s16($blk, 22));   // 11022
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp_inv', $mb->s16($blk, 24) / 10.0);   // 11024
            $hub->SetVarFloat('temp_env', $mb->s16($blk, 25) / 10.0);   // 11025
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            // U32-Zaehler, Word-Order High-zuerst angenommen (s.o., unbestaetigt).
            $hub->SetVarFloat('e_pv_total',     $mb->u32($blk, 69) / 10.0);   // 11069+11070
            $hub->SetVarFloat('e_pv_day',       $mb->u16($blk, 71) / 10.0);   // 11071
            $hub->SetVarFloat('e_charge_total', $mb->u32($blk, 72) / 10.0);   // 11072+11073
            $hub->SetVarFloat('e_charge_day',   $mb->u16($blk, 74) / 10.0);   // 11074
            $hub->SetVarFloat('e_disch_total',  $mb->u32($blk, 75) / 10.0);   // 11075+11076
            $hub->SetVarFloat('e_disch_day',    $mb->u16($blk, 77) / 10.0);   // 11077
            $hub->SetVarFloat('e_sell_total',   $mb->u32($blk, 78) / 10.0);   // 11078+11079
            $hub->SetVarFloat('e_sell_day',     $mb->u16($blk, 80) / 10.0);   // 11080
            $hub->SetVarFloat('e_load_total',   $mb->u32($blk, 90) / 10.0);   // 11090+11091
            $hub->SetVarFloat('e_load_day',     $mb->u16($blk, 92) / 10.0);   // 11092
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Echtzeit-Block in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Modell (10000-10007, 16 Zeichen) und SN (10008-10015, 15 Zeichen).
        // Read-Only-MVP: nur einmalig lesen, kein Steuerregister in dieser
        // Ausbaustufe implementiert.
        $blk = $mb->readInput(10000, 16);
        if ($blk === null) {
            return;
        }
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 0, 8));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 8, 8));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert - siehe
        // Kommentar am Klassenkopf (44000er-Sollwertregister erst nach
        // Read-Only-Verifikation und mit Bedacht).
    }
}
