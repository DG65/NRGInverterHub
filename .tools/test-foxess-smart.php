<?php
// Prueft IHUB_FoxEssSmartDriver (FoxESS H3 Smart / H3 Pro / KH, Holding 39xxx):
// Dekodierung (Skalierung, Wortreihenfolge, Vorzeichen), Statusbits, Block-
// Fallback in Stuecken, Ausfallverhalten, plus Registrierung in Kernmodul und
// Gerätesuche. Aufruf: php .tools/test-foxess-smart.php

$fail = 0;
function ok($cond, $msg)
{
    global $fail;
    if ($cond) {
        echo "  ok   $msg\n";
    } else {
        echo "  FAIL $msg\n";
        $fail++;
    }
}

$root = dirname(__DIR__);
$core = file_get_contents($root . '/InverterHub/module.php');
$disc = file_get_contents($root . '/InverterHubDiscovery/module.php');

// Treiberklasse extrahieren und mit minimalem Umfeld auswerten.
if (!preg_match('/(class IHUB_FoxEssSmartDriver implements IHUB_InverterDriverInterface\s*\{.*?\n\})\n\n\/\/ -{20,}\n\/\/ IHUB_VictronDriver/s', $core, $m)) {
    echo "FAIL Treiberklasse nicht gefunden\n";
    exit(1);
}
define('VARIABLETYPE_FLOAT', 2);
interface IHUB_InverterDriverInterface {}
eval($m[1]);

class MockMb
{
    public $regs = [];       // adresse => wert
    public $maxCount = 125;  // groesster erlaubter Block
    public $missing = [];    // adressen, die eine Exception ausloesen
    public $calls = [];
    public function readHolding($start, $count)
    {
        $this->calls[] = [$start, $count];
        if ($count > $this->maxCount) {
            return null;
        }
        $out = [];
        for ($a = $start; $a < $start + $count; $a++) {
            if (in_array($a, $this->missing, true)) {
                return null;
            }
            $out[] = $this->regs[$a] ?? 0;
        }
        return $out;
    }
    public function u16($r, $o) { return isset($r[$o]) ? ($r[$o] & 0xFFFF) : 0; }
    public function s16($r, $o) { $v = $this->u16($r, $o); return $v > 32767 ? $v - 65536 : $v; }
    public function u32($r, $o) { return (($this->u16($r, $o) << 16) | $this->u16($r, $o + 1)); }
    public function s32($r, $o) { $v = $this->u32($r, $o); return $v > 2147483647 ? $v - 4294967296 : $v; }
}

class MockHub
{
    public $v = [];
    public $groups = true;
    public function GetPropBool($n) { return $this->groups; }
    public function SetVarBool($i, $x) { $this->v[$i] = (bool)$x; }
    public function SetVarInt($i, $x) { $this->v[$i] = (int)$x; }
    public function SetVarFloat($i, $x) { $this->v[$i] = (float)$x; }
    public function SetVarStr($i, $x) { $this->v[$i] = (string)$x; }
}

function put32(&$regs, $addr, $val)
{
    $val &= 0xFFFFFFFF;
    $regs[$addr]     = ($val >> 16) & 0xFFFF;   // hochwertiges Wort auf der kleineren Adresse
    $regs[$addr + 1] = $val & 0xFFFF;
}
function put16(&$regs, $addr, $val) { $regs[$addr] = $val & 0xFFFF; }

function baseRegs()
{
    $r = [];
    put16($r, 39063, 0x0004);              // Betrieb, am Netz
    put16($r, 39065, 0x0000);
    put16($r, 39070, 6502); put16($r, 39071, 52);     // PV1 650,2 V / 5,2 A
    put16($r, 39072, 6100); put16($r, 39073, 48);     // PV2
    put16($r, 39123, 2305); put16($r, 39124, 2311); put16($r, 39125, 2298);
    put16($r, 39139, 5001);                // 50,01 Hz
    put16($r, 39141, 412);                 // 41,2 °C
    put32($r, 39134, 4321);                // AC-Leistung 4321 W
    put32($r, 39237, -1800);               // Batterie laedt mit 1800 W
    put32($r, 39279, 3000); put32($r, 39281, 2500); put32($r, 39283, -5); put32($r, 39285, 0);
    put32($r, 38814, -12345);              // Netz: 1234,5 W Bezug (negativ), Einheit 0,1 W
    put16($r, 37609, 4023); put16($r, 37610, -150); put16($r, 37611, 255); put16($r, 37612, 67);
    put32($r, 39601, 123456);              // 1234,56 kWh
    put32($r, 39603, 1875);                // 18,75 kWh
    put32($r, 39605, 50000); put32($r, 39607, 900);
    put32($r, 39609, 40000); put32($r, 39611, 700);
    put32($r, 39613, 30000); put32($r, 39615, 1100);
    put32($r, 39617, 20000); put32($r, 39619, 300);
    put32($r, 39629, 99999); put32($r, 39631, 1500);
    return $r;
}

echo "Dekodierung\n";
$d  = new IHUB_FoxEssSmartDriver();
$mb = new MockMb(); $mb->regs = baseRegs();
$hub = new MockHub();
$res = $d->readFast($mb, $hub);
ok($res === true, 'readFast liefert true');
ok($hub->v['connected'] === true, 'connected = true');
ok($hub->v['status'] === 2, 'Status Netzbetrieb (Bit 2)');
ok(abs($hub->v['pv1_volt'] - 650.2) < 0.01 && abs($hub->v['pv1_curr'] - 5.2) < 0.01, 'PV1 Spannung/Strom (÷10)');
ok(abs($hub->v['pv2_volt'] - 610.0) < 0.01, 'PV2 Spannung');
ok(abs($hub->v['grid_volt_r'] - 230.5) < 0.01, 'Netzspannung L1');
ok(abs($hub->v['grid_freq'] - 50.01) < 0.001, 'Netzfrequenz (÷100)');
ok(abs($hub->v['temp_inv'] - 41.2) < 0.01, 'Wechselrichter-Temperatur');
ok($hub->v['ac_power'] === 4321.0, 'AC-Leistung (I32, Wort-Reihenfolge hoch zuerst)');
ok($hub->v['bat_power'] === -1800.0, 'Batterieleistung negativ = Laden (I32)');
ok($hub->v['pv_total'] === 5500.0, 'PV-Summe ohne negative String-Werte');
ok(abs($hub->v['meter_total'] - (-1234.5)) < 0.01, 'Netz: -1234,5 W (Bezug), Einheit 0,1 W');
ok(abs($hub->v['bat_volt'] - 402.3) < 0.01 && abs($hub->v['bat_curr'] - (-15.0)) < 0.01 && abs($hub->v['bat_temp'] - 25.5) < 0.01, 'BMS Spannung/Strom/Temperatur');
ok($hub->v['bat_soc'] === 67, 'Batterie-SOC');
ok(abs($hub->v['e_pv_total'] - 1234.56) < 0.001, 'PV gesamt 1234,56 kWh (U32, ÷100)');
ok(abs($hub->v['e_pv_day'] - 18.75) < 0.001, 'PV heute 18,75 kWh');
ok(abs($hub->v['e_charge_total'] - 500.0) < 0.001 && abs($hub->v['e_charge_day'] - 9.0) < 0.001, 'Laden gesamt/heute');
ok(abs($hub->v['e_disch_total'] - 400.0) < 0.001 && abs($hub->v['e_disch_day'] - 7.0) < 0.001, 'Entladen gesamt/heute');
ok(abs($hub->v['e_sell_total'] - 300.0) < 0.001 && abs($hub->v['e_sell_day'] - 11.0) < 0.001, 'Einspeisung gesamt/heute');
ok(abs($hub->v['e_buy_total'] - 200.0) < 0.001 && abs($hub->v['e_buy_day'] - 3.0) < 0.001, 'Netzbezug gesamt/heute');
ok(abs($hub->v['e_load_total'] - 999.99) < 0.001 && abs($hub->v['e_load_day'] - 15.0) < 0.001, 'Hausverbrauch gesamt/heute');

echo "Statusbits\n";
foreach ([[0x0040, 0, 4, 'Fehler'], [0x0004, 1, 3, 'Inselbetrieb'], [0x0001, 0, 1, 'Standby'], [0x0000, 0, 0, 'Unbekannt'], [0x0005, 0, 2, 'Betrieb vor Standby']] as [$s1, $s3, $exp, $name]) {
    $mb = new MockMb(); $mb->regs = baseRegs(); $mb->regs[39063] = $s1; $mb->regs[39065] = $s3;
    $hub = new MockHub(); $d->readFast($mb, $hub);
    ok($hub->v['status'] === $exp, "Status $name -> $exp");
}

echo "Ausfall und Fallback\n";
$mb = new MockMb(); $mb->regs = baseRegs(); $mb->missing = [39063];
$hub = new MockHub();
ok($d->readFast($mb, $hub) === false && $hub->v['connected'] === false, 'Ohne 39063: connected=false, readFast=false');

$mb = new MockMb(); $mb->regs = baseRegs(); $mb->maxCount = 10;   // Geraet lehnt breite Bloecke ab
$hub = new MockHub();
ok($d->readFast($mb, $hub) === true && $hub->v['pv_total'] === 5500.0 && abs($hub->v['e_pv_day'] - 18.75) < 0.001, 'Breite Bloecke abgelehnt: Stueckweise Wiederholung liefert dieselben Werte');

$mb = new MockMb(); $mb->regs = baseRegs(); $mb->missing = [39201 + 40];  // Luecke mitten im Leistungsblock
$hub = new MockHub();
$d->readFast($mb, $hub);
ok(!isset($hub->v['pv_total']) && !isset($hub->v['bat_power']), 'Block mit unlesbarem Register: keine Teilwerte statt Nullen');
ok(isset($hub->v['ac_power']) && isset($hub->v['e_pv_day']), 'Uebrige Bloecke werden trotzdem gelesen');

echo "Registrierung\n";
ok(strpos($core, "'foxess_smart' => 'IHUB_FoxEssSmartDriver'") !== false, 'DRIVERS enthaelt foxess_smart');
ok(strpos($core, "'value' => 'foxess_smart'") !== false, 'Hersteller-Auswahl enthaelt foxess_smart');
ok(strpos($disc, "'foxess_smart' => [247, 1]") !== false, 'Gerätesuche: Unit-ID-Kandidaten');
ok(strpos($disc, "case 'foxess_smart':") !== false, 'Gerätesuche: Erkennung vorhanden');
// Der alte FoxESS-Treiber bleibt unveraendert (FC04, 11000er-Block).
ok(strpos($core, '$mb->readInput(11000, 96)') !== false, 'Bisheriger FoxESS-Treiber unveraendert');

echo $fail === 0 ? "\nALLES OK\n" : "\n$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
