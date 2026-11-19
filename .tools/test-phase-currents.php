<?php
/**
 * Vertragsfeld phaseCurrentIDs (IHUB_GetFunctions 1.7, 18.11.2026, EMS-Wunsch Phasenwaechter):
 * [L1, L2, L3] = Phasenstroeme des ZAEHLERS am Netzanschluss in A, nie der AC-Strom des Wechselrichters.
 *   php .tools/test-phase-currents.php    # 0 = alle Pruefungen bestanden
 */
require_once __DIR__ . '/ref/harness.php';

// Nachgebildeter Objektbaum: eine Instanz (ID 1) mit Variablen direkt darunter
$GLOBALS['TREE'] = [];
function IPS_GetChildrenIDs($p) { return $p === 1 ? array_values(array_map(function ($v) { return $v[0]; }, $GLOBALS['TREE'])) : []; }
function IPS_GetObject($id) { foreach ($GLOBALS['TREE'] as $ident => $v) { if ($v[0] === $id) { return ['ObjectIdent' => $ident, 'ObjectType' => 2]; } } return ['ObjectIdent' => '', 'ObjectType' => 2]; }

class GfHub extends InverterHub
{
    public $maker = 'solis';
    public function __construct() { $this->InstanceID = 1; }
    public function ReadPropertyString($n) { return $n === 'Manufacturer' ? $this->maker : 'ems'; }
    public function ReadPropertyBoolean($n) { return false; }
    public function ReadPropertyInteger($n) { return 0; }
}
function idents(array $names): void { $GLOBALS['TREE'] = []; $i = 100; foreach ($names as $n) { $GLOBALS['TREE'][$n] = [$i++]; } }
function fn_ids(string $maker, array $names): array
{
    idents($names);
    $h = new GfHub(); $h->maker = $maker;
    return $h->GetFunctions();
}
function idOf(string $ident): int { return $GLOBALS['TREE'][$ident][0]; }

echo "GetFunctions: phaseCurrentIDs\n";
$r = fn_ids('solis', ['mt_l1_curr', 'mt_l2_curr', 'mt_l3_curr', 'grid_l1_curr', 'grid_l2_curr', 'grid_l3_curr']);
ref_ok($r['contractVersion'] === '1.8', 'Vertragsversion 1.8');
ref_ok($r['phaseCurrentIDs'] === [idOf('mt_l1_curr'), idOf('mt_l2_curr'), idOf('mt_l3_curr')], 'Solis: Zaehlerstroeme mt_l1..3_curr, nicht die AC-Stroeme grid_l1..3_curr des Wechselrichters');
$r = fn_ids('solis', ['grid_l1_curr', 'grid_l2_curr', 'grid_l3_curr']);
ref_ok($r['phaseCurrentIDs'] === [], 'Solis ohne Zaehlergruppe: leer (AC-Stroeme des Wechselrichters zaehlen nicht)');
$r = fn_ids('goodwe', ['mt_l1_curr', 'mt_l2_curr', 'mt_l3_curr', 'grid_l1_curr', 'grid_l2_curr', 'grid_l3_curr']);
ref_ok($r['phaseCurrentIDs'] === [idOf('mt_l1_curr'), idOf('mt_l2_curr'), idOf('mt_l3_curr')], 'GoodWe: mt_l1..3_curr (Smart Meter 36055-36057)');
$r = fn_ids('fronius', ['meter_l1_curr', 'meter_l2_curr', 'meter_l3_curr']);
ref_ok($r['phaseCurrentIDs'] === [idOf('meter_l1_curr'), idOf('meter_l2_curr'), idOf('meter_l3_curr')], 'Fronius: meter_l1..3_curr (SunSpec-Zaehler)');
$r = fn_ids('solis', ['mt_l1_curr', 'mt_l2_curr']);
ref_ok($r['phaseCurrentIDs'] === [], 'nur zwei von drei Phasen: leer (das EMS erwartet genau drei)');
$r = fn_ids('kostal', ['grid_l1_curr', 'grid_l2_curr', 'grid_l3_curr']);
ref_ok($r['phaseCurrentIDs'] === [], 'Kostal: nur WR-Phasenstroeme, kein Zaehler: leer');
$r = fn_ids('solax', ['grid_r_curr', 'grid_s_curr', 'grid_t_curr']);
ref_ok($r['phaseCurrentIDs'] === [], 'Solax: R/S/T-Strom des Wechselrichters, kein Zaehler: leer');
$r = fn_ids('huawei', ['meter_curr']);
ref_ok($r['phaseCurrentIDs'] === [], 'Huawei: nur ein Zaehlerstrom (L1): leer');
$r = fn_ids('goodwe', ['mt_l1_curr', 'mt_l2_curr', 'mt_l3_curr', 'meter_l1_curr', 'meter_l2_curr', 'meter_l3_curr']);
ref_ok($r['phaseCurrentIDs'][0] === idOf('mt_l1_curr'), 'beide Muster vorhanden: mt_l*_curr hat Vorrang');

echo "Werte je Lesezyklus\n";
// Solis: Zaehlerblock 33251-33264, Spannung/Strom abwechselnd; Strom in 0,01 A
$d = new IHUB_SolisDriver; $mb = new RefMb(1); $mb->auto = 'pos'; $mb->set(4, 33251, [2300, 5770, 2301, 1234, 2302, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
$hub = new RefHub(); $d->readFast($mb, $hub);
ref_ok(abs($hub->vars['mt_l1_curr'][1] - 57.70) < 1e-9 && abs($hub->vars['mt_l2_curr'][1] - 12.34) < 1e-9 && $hub->vars['mt_l3_curr'][1] === 0.0, 'Solis: 33252/33254/33256 durch 100 = 57,70 / 12,34 / 0,00 A, in jedem schnellen Zyklus gesetzt');
$hub = new RefHub(['GroupMeter' => false]); $d->readFast($mb, new RefHub(['GroupMeter' => false]));
$h2 = new RefHub(['GroupMeter' => false]); $d->readFast($mb, $h2);
ref_ok(!isset($h2->vars['mt_l1_curr']), 'Solis ohne Gruppe Zaehler: keine Zaehlerstroeme (dann bleibt phaseCurrentIDs leer)');
// GoodWe: Zaehlerblock 36019 (39): Strom bei Offset 36-38 (36055-36057), 0,1 A
$g = new IHUB_GoodweDriver; $mb = new RefMb(247); $mb->auto = 'pos'; $mb->set(3, 36055, [577, 123, 0]);
$hub = new RefHub(); $g->readFast($mb, $hub);
ref_ok(abs($hub->vars['mt_l1_curr'][1] - 57.7) < 1e-9 && abs($hub->vars['mt_l2_curr'][1] - 12.3) < 1e-9 && $hub->vars['mt_l3_curr'][1] === 0.0, 'GoodWe: 36055-36057 durch 10 = 57,7 / 12,3 / 0,0 A');

ref_finish('phaseCurrentIDs');
