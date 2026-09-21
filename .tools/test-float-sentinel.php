<?php
/**
 * Prüfstand: Float32-"nicht belegt"-Marke (-3.4028235E+38) bei SolarEdge/Kostal
 * (Forum-Beta-Tester somm, 21.09.2026: "Warning: The float -3.40282346638528E+38 is not
 * representable as an int" im FastTimer). Prüft den zentralen Schutz in SetVarFloat() und
 * dass kein rohes (int)round(readFloat32(...)) mehr im Modul steht.
 *
 *   php .tools/test-float-sentinel.php    # 0 = alle Prüfungen bestanden
 */
if (!defined('KL_WARNING')) { define('KL_WARNING', 3); }
if (!defined('KL_MESSAGE')) { define('KL_MESSAGE', 10); }

$store = []; $props = []; $logs = [];
function GetValueFloat($vid) { global $store; return $store[$vid] ?? 0.0; }
function SetValueFloat($vid, $v) { global $store; $store[$vid] = $v; }

class IPSModule
{
    private $buf = [];
    public function ReadPropertyBoolean($k) { global $props; return (bool)($props[$k] ?? false); }
    public function LogMessage($m, $l) { global $logs; $logs[] = $m; }
    public function FindVarByIdent($ident) { return 'V_' . $ident; }
    public function GetBuffer($k) { return $this->buf[$k] ?? ''; }
    public function SetBuffer($k, $v) { $this->buf[$k] = $v; }
}

$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
preg_match('/public function SetVarFloat\(.*?\n    \}/s', $src, $mSet);
preg_match('/private function IsEnergyIdent\(.*?\n    \}/s', $src, $mIs);
foreach (['SetVarFloat' => $mSet, 'IsEnergyIdent' => $mIs] as $label => $m) {
    if (empty($m)) { fwrite(STDERR, "Extraktion fehlgeschlagen: $label\n"); exit(1); }
}
eval("class InverterHub extends IPSModule {\n" . $mSet[0] . "\n" . preg_replace('/^private function/', 'public function', $mIs[0]) . "\n}\n");

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label\n"; }
}
$FLT_MIN = -3.4028234663852886E+38;
$m = new InverterHub();

echo "1) Marke wird verworfen, der alte Stand bleibt stehen\n";
$store['V_bat_volt'] = 402.5;
$m->SetVarFloat('bat_volt', $FLT_MIN);
check('Spannung: alter Wert 402.5 bleibt', $store['V_bat_volt'] === 402.5);
$store['V_bat_temp'] = 24.0;
$m->SetVarFloat('bat_temp', $FLT_MIN);
check('Temperatur: alter Wert bleibt', $store['V_bat_temp'] === 24.0);

echo "2) Auch nach Vorzeichenumkehr im Treiber (+3.4E+38, z. B. -readFloat32) wird verworfen\n";
$store['V_bat_power'] = -1200.0;
$m->SetVarFloat('bat_power', -$FLT_MIN);
check('Leistung: alter Wert bleibt (kein +3.4E+38 im Archiv)', $store['V_bat_power'] === -1200.0);

echo "3) Nur einmal je Messgroesse ins Meldungsfenster\n";
$logs = [];
$m2 = new InverterHub();
$m2->SetVarFloat('bat_curr', $FLT_MIN);
$m2->SetVarFloat('bat_curr', $FLT_MIN);
$m2->SetVarFloat('bat_curr', $FLT_MIN);
check('drei Marken = eine Meldung', count($logs) === 1 && strpos($logs[0], 'bat_curr') !== false);
$m2->SetVarFloat('bat_volt', $FLT_MIN);
check('andere Messgroesse meldet eigenstaendig', count($logs) === 2);

echo "4) Echte Werte bleiben unberuehrt, auch grosse\n";
$m->SetVarFloat('bat_volt', 398.7);
check('normaler Wert wird geschrieben', $store['V_bat_volt'] === 398.7);
$m->SetVarFloat('bat_power', 29900.0);
check('29,9 kW wird geschrieben (kein Schwellwert bei Anlagengroesse)', $store['V_bat_power'] === 29900.0);
$m->SetVarFloat('bat_power', -1.0e9);
check('unrealistisch, aber weit unter der Marke: wird nicht angefasst', $store['V_bat_power'] === -1.0e9);

echo "5) Kein rohes (int)round(readFloat32(...)) mehr im Modul\n";
check('keine ungeschuetzte Umwandlung', preg_match('/\(int\)round\(\$mb->readFloat32\(/', $src) === 0);
check('SolarEdge SOC/SOH und Kostal SOC geschuetzt', substr_count($src, 'abs($socRaw) < 1.0e9') === 2 && substr_count($src, 'abs($sohRaw) < 1.0e9') === 1);

exit($fails ? 1 : 0);
