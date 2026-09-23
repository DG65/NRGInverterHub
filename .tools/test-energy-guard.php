<?php
/**
 * Prüfstand: Zählerschutz gegen einzelne Ausreißer-Nullwerte in SetVarFloat()
 * (Fund Stefan/somm, SolarEdge, 17.09.2026 - siehe CLAUDE.md). Extrahiert nur
 * SetVarFloat()/IsEnergyIdent() aus InverterHub/module.php und simuliert eine
 * Variable ohne echtes IP-Symcon.
 *
 *   php .tools/test-energy-guard.php    # 0 = alle Prüfungen bestanden
 */

if (!defined('KL_WARNING')) { define('KL_WARNING', 3); }

$store = [];    // vid => float
$props = [];    // property => value
$logs = [];

function GetValueFloat($vid) { global $store; return $store[$vid] ?? 0.0; }
function SetValueFloat($vid, $v) { global $store; $store[$vid] = $v; }

class IPSModule
{
    public function ReadPropertyBoolean($k) { global $props; return (bool)($props[$k] ?? false); }
    public function LogMessage($m, $l) { global $logs; $logs[] = $m; }
    public function FindVarByIdent($ident) { return 'V_' . $ident; }
}

$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
preg_match('/public function SetVarFloat\(.*?\n    \}/s', $src, $mSet);
preg_match('/private function IsEnergyIdent\(.*?\n    \}/s', $src, $mIs);
foreach (['SetVarFloat' => $mSet, 'IsEnergyIdent' => $mIs] as $label => $m) {
    if (empty($m)) { fwrite(STDERR, "Extraktion fehlgeschlagen: $label (umbenannt/umformatiert?)\n"); exit(1); }
}
$setCode = preg_replace('/^public function/', 'public function', $mSet[0]);
$isCode  = preg_replace('/^private function/', 'public function', $mIs[0]);
eval("class InverterHub extends IPSModule {\n" . $setCode . "\n" . $isCode . "\n}\n");

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label\n"; }
}

$m = new InverterHub();

echo "1) Echter Ausreisser (0 zwischen zwei plausiblen Staenden) wird verworfen\n";
global $store, $logs;
$store['V_e_total'] = 13210.27;
$logs = [];
$m->SetVarFloat('e_total', 0.0);
check('alter Stand bleibt stehen', $store['V_e_total'] === 13210.27);
check('Warnung geloggt', count($logs) === 1);

echo "2) Erste Ablesung ueberhaupt (alter Stand 0) wird NICHT blockiert\n";
$store['V_e_total'] = 0.0;
$logs = [];
$m->SetVarFloat('e_total', 0.0);
check('0 -> 0 bleibt zulaessig (kein falscher Alarm bei Neuanlage)', $store['V_e_total'] === 0.0);
check('keine Warnung', count($logs) === 0);

echo "3) Normaler, steigender Zaehlerstand wird ganz normal geschrieben\n";
$store['V_e_total'] = 13210.27;
$logs = [];
$m->SetVarFloat('e_total', 13210.30);
check('neuer Stand uebernommen', $store['V_e_total'] === 13210.30);
check('keine Warnung', count($logs) === 0);

echo "4) Ein echter, dauerhafter Zaehlertausch (deutlich niedrigerer, aber NICHT 0-Wert) bleibt moeglich\n";
$store['V_e_total'] = 13210.27;
$logs = [];
$m->SetVarFloat('e_total', 50.0);
check('kein blindes Blockieren jedes Ruecksprungs', $store['V_e_total'] === 50.0);

echo "5) Nicht-Energie-Idents (z. B. Leistung) sind vom Schutz unberuehrt\n";
$store['V_ac_power'] = 5000.0;
$logs = [];
$m->SetVarFloat('ac_power', 0.0);
check('Leistungswert 0 wird normal geschrieben (0 W ist real moeglich)', $store['V_ac_power'] === 0.0);

echo "6) Tageszaehler (Fund Dietmar, 23.09.2026): legitimer Reset auf 0 wird NICHT blockiert\n";
foreach (['e_pv_day', 'e_charge_day', 'e_sell_day', 'e_buy_day', 'e_load_day', 'e_disch_day', 'e_day'] as $ident) {
    $store['V_' . $ident] = 36.8;
    $logs = [];
    $m->SetVarFloat($ident, 0.0);
    check("$ident: Reset auf 0 wird uebernommen (kein Einfrieren auf Vortageswert)", $store['V_' . $ident] === 0.0);
    check("$ident: keine Warnung", count($logs) === 0);
}

echo "7) Tageszaehler laeuft danach ganz normal weiter hoch\n";
$store['V_e_pv_day'] = 0.0;
$logs = [];
$m->SetVarFloat('e_pv_day', 0.4);
check('Tageszaehler steigt normal, sobald wieder Ertrag da ist', $store['V_e_pv_day'] === 0.4);

echo "\n" . ($fails === 0 ? "ALLE PRUEFUNGEN BESTANDEN\n" : "$fails PRUEFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
