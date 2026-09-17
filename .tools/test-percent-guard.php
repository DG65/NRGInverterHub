<?php
/**
 * Prüfstand: Plausibilitätsschutz für SOC/SOH-Prozentwerte in SetVarInt()
 * (Folgefund Stefan/somm, SolarEdge, 17.09.2026 - siehe CLAUDE.md).
 *
 *   php .tools/test-percent-guard.php    # 0 = alle Prüfungen bestanden
 */

if (!defined('KL_WARNING')) { define('KL_WARNING', 3); }

$store = [];
$logs = [];

function SetValueInteger($vid, $v) { global $store; $store[$vid] = $v; }

class IPSModule
{
    public function LogMessage($m, $l) { global $logs; $logs[] = $m; }
    public function FindVarByIdent($ident) { return 'V_' . $ident; }
}

$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
preg_match('/public function SetVarInt\(.*?\n    \}/s', $src, $m);
if (empty($m)) {
    fwrite(STDERR, "Extraktion fehlgeschlagen: SetVarInt (umbenannt/umformatiert?)\n");
    exit(1);
}
eval("class InverterHub extends IPSModule {\n" . $m[0] . "\n}\n");

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label\n"; }
}

$inv = new InverterHub();
global $store, $logs;

echo "1) Ausserhalb 0-100% wird fuer SOC-Idents verworfen\n";
$store['V_bat1_soc'] = 62;
$logs = [];
$inv->SetVarInt('bat1_soc', 255);
check('alter Stand bleibt stehen', $store['V_bat1_soc'] === 62);
check('Warnung geloggt', count($logs) === 1);

echo "2) Negativer Wert wird ebenfalls verworfen (bat2_soh)\n";
$store['V_bat2_soh'] = 98;
$logs = [];
$inv->SetVarInt('bat2_soh', -5);
check('alter Stand bleibt stehen', $store['V_bat2_soh'] === 98);

echo "3) Randwerte 0 und 100 bleiben gueltig\n";
$logs = [];
$inv->SetVarInt('soc', 0);
check('0 wird geschrieben', $store['V_soc'] === 0);
$inv->SetVarInt('soc', 100);
check('100 wird geschrieben', $store['V_soc'] === 100);
check('keine Warnung', count($logs) === 0);

echo "4) Andere Idents (z. B. Steuer-Sollwerte) sind vom Schutz unberuehrt\n";
$store['V_ctl_soc_min'] = 20;
$logs = [];
$inv->SetVarInt('ctl_soc_min', 150);
check('kein Prozent-Ident (endet nicht auf soc/soh) - Wert wird normal geschrieben', $store['V_ctl_soc_min'] === 150);
check('keine Warnung', count($logs) === 0);

echo "\n" . ($fails === 0 ? "ALLE PRUEFUNGEN BESTANDEN\n" : "$fails PRUEFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
