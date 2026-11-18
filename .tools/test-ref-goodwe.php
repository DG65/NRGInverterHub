<?php
/**
 * Referenzlauf GoodWe (18.11.2026, Code-Bestandsaufnahme Schritt 1).
 * Haelt das heutige Verhalten fest: readFast (readFastInner), readSlow, readDeviceInfo und alle Schreibwege
 * (writeControl, writeGridService, releaseGridService, regAlready). Am Treiber wurde nichts geaendert.
 *
 *   php .tools/test-ref-goodwe.php                 # Vergleich mit .tools/ref/goodwe.json, 0 = unveraendert
 *   UPDATE_REF=1 php .tools/test-ref-goodwe.php    # Referenz bewusst neu schreiben
 */
require_once __DIR__ . '/ref/harness.php';

function gw_str(string $s, int $regs): array
{
    $s = str_pad($s, $regs * 2, "\0");
    $o = [];
    for ($i = 0; $i < $regs; $i++) { $o[] = (ord($s[$i * 2]) << 8) | ord($s[$i * 2 + 1]); }
    return $o;
}
function gw_s16(int $v): int { return $v & 0xFFFF; }

/** Alle Lesebloecke des schnellen Zyklus; Ankerwerte an belegten Adressen, Rest Muster. */
function gw_fast_fixture(): RefMb
{
    $mb = new RefMb(247);
    // Wechselrichterblock 35103 (42): Netzmodus 35136 = 1, Wirkleistung s32 35139 = 4200 W, Gesamt s32 35137 = 4300 W
    $mb->set(3, 35103 + 33, [1]);
    $mb->set(3, 35103 + 34, RefMb::w32(4300));
    $mb->set(3, 35103 + 36, RefMb::w32(4200));
    $mb->set(3, 35103 + 21, RefMb::w32(-150));           // Phase 1 Leistung negativ (s32)
    // Batterie 1 (35174, 18): Strom s16 -12,5 A, Leistung s32 -3500 W, Modus 2
    $mb->set(3, 35174 + 7, [gw_s16(-125)]);
    $mb->set(3, 35174 + 8, RefMb::w32(-3500));
    $mb->set(3, 35174 + 10, [2]);
    $mb->set(3, 35174 + 0, [gw_s16(-45)]);                // Lufttemperatur negativ
    // Batterie 2 (35262, 7): Strom -12,0 A, Leistung -3400 W
    $mb->set(3, 35262 + 1, [gw_s16(-120)]);
    $mb->set(3, 35262 + 2, RefMb::w32(-3400));
    // PV extern (35301, 41): u32 PV gesamt 6840 W
    $mb->set(3, 35301, RefMb::w32(6840));
    // Zaehler (36019, 39): Gesamt s32 -2800 W (Bezug), Phasen
    $mb->set(3, 36019 + 6, RefMb::w32(-2800));
    $mb->set(3, 36019 + 0, RefMb::w32(-900));
    // BMS (47900, 34): SOC1 55, SOC2 57 (Mittel 56), Temperatur s16 -3,5 Grad
    $mb->set(3, 47900 + 8, [55]);
    $mb->set(3, 47900 + 26, [57]);
    $mb->set(3, 47900 + 10, [gw_s16(-35)]);
    // EMS-Register 47511 = 1 (Automatik), 47512 = 0
    $mb->set(3, 47511, [1, 0]);
    foreach ([[35103, 42], [35145, 26], [35174, 18], [35262, 7], [35301, 41], [36019, 39], [47900, 34], [35332, 16], [37003, 21], [39001, 21]] as [$a, $n]) { $mb->pattern(3, $a, $n); }
    $mb->set(3, 45263, [100]); $mb->set(3, 35365, [1500]); $mb->set(3, 47000, [0]); $mb->set(3, 36014, [5002]); $mb->set(3, 45252, [1]);
    return $mb;
}

$d = new IHUB_GoodweDriver;
echo "GoodWe readFast (readFastInner)\n";

// A) Vollbild: alle Gruppen an, Automatikmodus
$mb = gw_fast_fixture(); $hub = new RefHub(); $hub->seed['ctl_ems_mode'] = 1;
$ret = $d->readFast($mb, $hub);
ref_check('goodwe', 'readFast voll', ref_result($hub, $mb, ['return' => $ret]));
$blocks = array_map(function ($r) { return $r[1]; }, $mb->reads);
ref_ok(!array_diff([35103, 35174, 35262, 35301, 36019, 47900, 35332, 37003, 39001, 36014, 35145], $blocks), 'readFast voll: alle erwarteten Lesebloecke wurden angefragt');

// B) Totmann: 47511 faellt auf 255 - Warnung nur beim Uebergang
$mb = gw_fast_fixture(); $mb->set(3, 47511, [255, 0]); $hub = new RefHub(); $hub->seed['ctl_ems_mode'] = 1;
$ret = $d->readFast($mb, $hub);
ref_check('goodwe', 'readFast Totmann 255 beim Uebergang', ref_result($hub, $mb, ['return' => $ret]));
ref_ok(count($hub->warn) === 1, 'Totmann: genau eine Warnung beim Uebergang auf 255');
$mb = gw_fast_fixture(); $mb->set(3, 47511, [255, 0]); $hub = new RefHub(); $hub->seed['ctl_ems_mode'] = 255;
$d->readFast($mb, $hub);
ref_ok(count($hub->warn) === 0, 'Totmann: keine zweite Warnung, solange das Register auf 255 bleibt');
$mb = gw_fast_fixture(); $mb->set(3, 47511, [255, 0]); $hub = new RefHub(); // Start ohne Vorwert (0)
$d->readFast($mb, $hub);
ref_ok(count($hub->warn) === 1, 'Totmann: Vorwert 0 (nach Neustart) zaehlt als Uebergang');

// C) Batterie 2 aus, BMS-Block ohne Antwort
$mb = gw_fast_fixture(); $mb->noRead = [47900, 35262]; $hub = new RefHub(['GroupBat2' => false]); $hub->seed['ctl_ems_mode'] = 1;
$ret = $d->readFast($mb, $hub);
ref_check('goodwe', 'readFast ohne BMS und Batterie 2', ref_result($hub, $mb, ['return' => $ret]));

// D) Gruppen aus: nur Kernwerte
$mb = gw_fast_fixture(); $hub = new RefHub(['GroupBat1' => false, 'GroupBat2' => false, 'GroupGrid' => false, 'GroupMeter' => false, 'GroupTemp' => false, 'GroupBackup' => false, 'EnableTracker1' => false, 'EnableTracker2' => false, 'EnableTracker3' => false]);
$ret = $d->readFast($mb, $hub);
ref_check('goodwe', 'readFast alle Gruppen aus', ref_result($hub, $mb, ['return' => $ret]));

// E) Fremdes Geraet: Wechselrichterblock lauter 0xFFFF
$mb = gw_fast_fixture(); for ($i = 0; $i < 42; $i++) { $mb->regs[3][35103 + $i] = 0xFFFF; }
$hub = new RefHub(); $ret = $d->readFast($mb, $hub);
ref_check('goodwe', 'readFast Fremdgeraet 0xFFFF', ref_result($hub, $mb, ['return' => $ret]));
ref_ok($ret === false && $hub->vars['connected'][1] === false, 'Fremdgeraet: nicht verbunden, keine weiteren Werte');

// F) Keine Antwort auf den Hauptblock
$mb = gw_fast_fixture(); $mb->noRead = [35103]; $hub = new RefHub(); $ret = $d->readFast($mb, $hub);
ref_check('goodwe', 'readFast ohne Antwort', ref_result($hub, $mb, ['return' => $ret]));

echo "GoodWe readSlow und readDeviceInfo\n";
function gw_slow_fixture(): RefMb
{
    $mb = new RefMb(247);
    $mb->set(3, 47545, [1, 80]);                                    // Schnellladung an, Ziel-SOC 80
    $mb->set(3, 47603, [10]);
    $mb->pattern(3, 35191, 22);
    $mb->set(3, 35191 + 15, RefMb::w32(104678));                   // Bat. geladen gesamt 10467,8 kWh (Messung 01.11.)
    $mb->set(3, 35191 + 17, [62]);                                  // Tag 6,2 kWh
    $mb->set(3, 37056, array_merge(RefMb::w32(86697), RefMb::w32(84297))); // BMS-Zaehler 8669,7/8429,7 kWh (Messung 07.11.)
    $mb->set(3, 32000, [0x05, 0, 0x01]);                            // Netzfehler (Bits 0,2), Systemfehler Bit 0
    $mb->pattern(3, 32000, 17);
    $mb->set(3, 37006, [0x0102]); $mb->set(3, 37010, [0x0304]); $mb->set(3, 37012, [0x0001]); $mb->set(3, 37013, [0x0002]);
    $mb->set(3, 35220, RefMb::w32((1 << 18) | (1 << 3)));         // DiagStatusL: Bits 18 und 3
    return $mb;
}
$mb = gw_slow_fixture(); $hub = new RefHub(); $d->readSlow($mb, $hub);
ref_check('goodwe', 'readSlow voll', ref_result($hub, $mb));
$mb = gw_slow_fixture(); $mb->set(3, 35191 + 15, RefMb::w32(0xFFFFFFFF)); $mb->set(3, 35191 + 17, [0xFFFF]); $mb->set(3, 37056, RefMb::w32(0xFFFFFFFF)); $hub = new RefHub(); $d->readSlow($mb, $hub);
ref_check('goodwe', 'readSlow Ungueltig-Marken 0xFFFF', ref_result($hub, $mb));
ref_ok(!isset($hub->vars['e_charge_day']) && !isset($hub->vars['e_charge_total']) && !isset($hub->vars['bms_e_charge_total']), 'readSlow: ungueltige Zaehler werden nicht geschrieben');
$mb = gw_slow_fixture(); $hub = new RefHub(['GroupErrors' => false, 'GroupEnergy' => false, 'GroupControl' => false]); $d->readSlow($mb, $hub);
ref_check('goodwe', 'readSlow alle Gruppen aus', ref_result($hub, $mb));

$mb = new RefMb(247);
$mb->set(3, 35001, [10000]); $mb->set(3, 35001 + 2, gw_str('9010KETU22AR0018', 8)); $mb->set(3, 35001 + 10, gw_str('GW10K-ET', 5)); $mb->set(3, 35001 + 15, [12]); $mb->set(3, 35001 + 18, [34]); $mb->pattern(3, 35001, 27);
$hub = new RefHub(); $d->readDeviceInfo($mb, $hub);
ref_check('goodwe', 'readDeviceInfo', ref_result($hub, $mb));

echo "GoodWe Schreibwege\n";
function gw_write(string $label, string $ident, $value, array $opt = [])
{
    global $d;
    $mb = new RefMb(247);
    $mb->failWrite = $opt['fail'] ?? [];
    foreach ($opt['regs'] ?? [] as $a => $v) { $mb->set(3, $a, [$v]); }
    $hub = new RefHub();
    foreach ($opt['seed'] ?? [] as $k => $v) { $hub->seed[$k] = $v; }
    $d->writeControl($mb, $hub, $ident, $value);
    ref_check('goodwe', "write $label", ref_result($hub, $mb, ['lastError' => $mb->lastError]));
    return [$mb, $hub];
}
gw_write('ctl_work_mode 3', 'ctl_work_mode', 3);
gw_write('ctl_work_mode 6 (ausserhalb)', 'ctl_work_mode', 6);
gw_write('ctl_work_mode -1 (ausserhalb)', 'ctl_work_mode', -1);
gw_write('ctl_work_mode scheitert', 'ctl_work_mode', 2, ['fail' => [47000]]);
gw_write('ctl_ems_enable an', 'ctl_ems_enable', true);
gw_write('ctl_ems_enable aus', 'ctl_ems_enable', false);
gw_write('ctl_ems_mode 9', 'ctl_ems_mode', 9);
gw_write('ctl_ems_mode 13 (ausserhalb)', 'ctl_ems_mode', 13);
gw_write('ctl_ems_power 5000', 'ctl_ems_power', 5000);
gw_write('ctl_ems_power 99999 (Klemme)', 'ctl_ems_power', 99999);
gw_write('ctl_ems_power -5 (Klemme)', 'ctl_ems_power', -5);
gw_write('ctl_export_enable bereits gesetzt', 'ctl_export_enable', true, ['regs' => [47509 => 1]]);
gw_write('ctl_export_enable an, Grenze 0 (Warnung)', 'ctl_export_enable', true, ['regs' => [47509 => 0, 47510 => 0]]);
gw_write('ctl_export_enable an, Grenze 5000', 'ctl_export_enable', true, ['regs' => [47509 => 0, 47510 => 5000]]);
gw_write('ctl_export_enable aus', 'ctl_export_enable', false, ['regs' => [47509 => 1]]);
gw_write('ctl_export_limit bereits gesetzt', 'ctl_export_limit', 4000, ['regs' => [47510 => 4000]]);
gw_write('ctl_export_limit neu', 'ctl_export_limit', 4000, ['regs' => [47510 => 100]]);
gw_write('ctl_export_limit Klemme', 'ctl_export_limit', 50000, ['regs' => [47510 => 100]]);
gw_write('ctl_fastcharge_enable', 'ctl_fastcharge_enable', true);
gw_write('ctl_fastcharge_soc 0 (Klemme 1)', 'ctl_fastcharge_soc', 0);
gw_write('ctl_fastcharge_soc 80', 'ctl_fastcharge_soc', 80);
gw_write('ctl_fastcharge_pct 150 (Klemme 100)', 'ctl_fastcharge_pct', 150);
gw_write('ctl_soc_min bereits gesetzt', 'ctl_soc_min', 10, ['regs' => [45356 => 10]]);
gw_write('ctl_soc_min neu', 'ctl_soc_min', 15, ['regs' => [45356 => 10]]);
gw_write('ctl_soc_max 97', 'ctl_soc_max', 97);
gw_write('ctl_internet an', 'ctl_internet', true);
gw_write('ctl_internet aus', 'ctl_internet', false);
gw_write('ctl_restart', 'ctl_restart', true);
gw_write('ctl_restart false', 'ctl_restart', false);
gw_write('unbekannter Ident', 'ctl_gibt_es_nicht', 1);

echo "GoodWe Netzdienste (writeGridService, releaseGridService)\n";
gw_write('svc_grid_charge_w 5000 aus Automatik', 'svc_grid_charge_w', 5000, ['seed' => ['ctl_ems_mode' => 1]]);
gw_write('svc_grid_charge_w 4000 im gleichen Modus', 'svc_grid_charge_w', 4000, ['seed' => ['ctl_ems_mode' => 9]]);
gw_write('svc_grid_charge_w 99999 (Klemme)', 'svc_grid_charge_w', 99999, ['seed' => ['ctl_ems_mode' => 9]]);
gw_write('svc_grid_charge_w 0 = Freigabe', 'svc_grid_charge_w', 0, ['seed' => ['ctl_ems_mode' => 9]]);
gw_write('svc_discharge_to_grid_w 800 aus Modus 9', 'svc_discharge_to_grid_w', 800, ['seed' => ['ctl_ems_mode' => 9]]);
gw_write('svc_discharge_to_grid_w 900 im gleichen Modus', 'svc_discharge_to_grid_w', 900, ['seed' => ['ctl_ems_mode' => 3]]);
gw_write('svc_discharge_to_grid_w 0 = Freigabe', 'svc_discharge_to_grid_w', 0, ['seed' => ['ctl_ems_mode' => 3]]);
gw_write('svc_charge_inhibit an aus Automatik', 'svc_charge_inhibit', true, ['seed' => ['ctl_ems_mode' => 1]]);
gw_write('svc_charge_inhibit an im gleichen Modus', 'svc_charge_inhibit', true, ['seed' => ['ctl_ems_mode' => 3]]);
gw_write('svc_charge_inhibit aus = Freigabe', 'svc_charge_inhibit', false, ['seed' => ['ctl_ems_mode' => 3]]);
gw_write('svc_release aus Modus 3', 'svc_release', true, ['seed' => ['ctl_ems_mode' => 3]]);
gw_write('svc_release false', 'svc_release', false, ['seed' => ['ctl_ems_mode' => 3]]);
[$mb, $hub] = gw_write('Null-Schritt scheitert (Abbruch vor dem Modus)', 'svc_grid_charge_w', 5000, ['seed' => ['ctl_ems_mode' => 1], 'fail' => [47512]]);
ref_ok(!in_array(['single', 47511, 9], $mb->writes, true) && count($hub->warn) >= 2, 'Null-Schritt scheitert: Modus 47511 wird nicht geschrieben, Warnungen erscheinen');
[$mb, $hub] = gw_write('Modusschreiben scheitert', 'svc_grid_charge_w', 5000, ['seed' => ['ctl_ems_mode' => 1], 'fail' => [47511]]);
ref_ok(!isset($hub->vars['svc_grid_charge_w']), 'Modusschreiben scheitert: svc_grid_charge_w bleibt unveraendert (Teilerfolg wird nicht als aktiv gemeldet)');
[$mb, $hub] = gw_write('Enable-Schreiben scheitert', 'svc_discharge_to_grid_w', 800, ['seed' => ['ctl_ems_mode' => 1], 'fail' => [47505]]);
ref_ok(!isset($hub->vars['svc_discharge_to_grid_w']), 'Enable-Schreiben scheitert: Teilerfolg wird nicht als aktiv gemeldet');
gw_write('Freigabe scheitert', 'svc_release', true, ['seed' => ['ctl_ems_mode' => 3], 'fail' => [47511]]);

ref_finish('GoodWe');
