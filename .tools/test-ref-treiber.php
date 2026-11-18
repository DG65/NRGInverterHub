<?php
/**
 * Referenzlauf der acht Treiber ohne eigenen Prüfstand (18.11.2026, Code-Bestandsaufnahme Schritt 1):
 * Sungrow, Growatt, Solax, Deye, Solplanet, Huawei, FoxESS (alt, Register 10000/11000) und Victron.
 *
 * Haelt das heutige Verhalten fest, ohne den Treiber zu aendern. Eingespeist werden Registermuster (je
 * Adresse ein eigener Wert) in vier Spielarten, damit jede Verschiebung von Offset, Skalierung, Vorzeichen
 * oder Wortreihenfolge eine Abweichung erzeugt:
 *   pos   = kleine positive Werte,
 *   neg   = Werte mit gesetztem hohem Bit (s16/s32 negativ, u32 gross),
 *   ffff  = "nicht implementiert" (0xFFFF in allen Registern),
 *   zero  = alles 0.
 * Dazu: Gruppen aus, keine Antwort, Sungrow-Stringwechselrichter (Register 13000 fehlt), Victron mit Reglern.
 * Die Registerwerte sind Muster, KEINE am Geraet gemessenen Werte; belegt wird hier nur "was der Treiber heute daraus macht".
 *
 *   php .tools/test-ref-treiber.php                 # Vergleich mit .tools/ref/treiber.json
 *   UPDATE_REF=1 php .tools/test-ref-treiber.php    # Referenz bewusst neu schreiben
 */
require_once __DIR__ . '/ref/harness.php';

$drivers = [
    'sungrow'   => 'IHUB_SungrowDriver',
    'growatt'   => 'IHUB_GrowattDriver',
    'solax'     => 'IHUB_SolaxDriver',
    'deye'      => 'IHUB_DeyeDriver',
    'solplanet' => 'IHUB_SolplanetDriver',
    'huawei'    => 'IHUB_HuaweiDriver',
    'foxess'    => 'IHUB_FoxEssDriver',
    'victron'   => 'IHUB_VictronDriver',
];
$counts = [];

/** readFast, readSlow und readDeviceInfo nacheinander auf demselben Hub (wie der Hauptbaustein im Betrieb). */
function cycle(string $key, string $cls, string $label, ?string $auto, array $opt = []): array
{
    global $counts;
    $d = new $cls;
    $mb = new RefMb((int)($opt['unit'] ?? 1));
    $mb->auto = $auto;
    $mb->silent = $opt['silent'] ?? false;
    $mb->noRead = $opt['noRead'] ?? [];
    $props = $opt['props'] ?? [];
    $hub = new RefHub($props, $opt['defaultProp'] ?? true);
    $rets = [];
    foreach (['readDeviceInfo', 'readFast', 'readSlow'] as $m) { $rets[$m] = $d->$m($mb, $hub); }
    $res = ref_result($hub, $mb, ['returns' => $rets, 'reads' => $mb->reads, 'unitId' => $mb->unitId]);
    ref_check('treiber', "$key $label", $res);
    $counts[$key] = ($counts[$key] ?? 0) + 1;
    return [$mb, $hub, $res];
}

foreach ($drivers as $key => $cls) {
    echo "$key\n";
    $o = $key === 'victron' ? ['props' => ['_victronMppt' => [223, 224], '_victronGrid' => 30]] : [];
    foreach (['pos' => 'Muster positiv', 'neg' => 'Muster negativ (hohes Bit)', 'ffff' => 'alles 0xFFFF (nicht implementiert)', 'zero' => 'alles 0'] as $mode => $text) {
        [$mb, $hub, $res] = cycle($key, $cls, $text, $mode, $o);
        if ($mode === 'pos') { ref_ok(count($res['vars']) > 0, "$key: liefert im Normalfall Variablen (" . count($res['vars']) . ')'); }
    }
    cycle($key, $cls, 'Gruppen aus', 'pos', ['defaultProp' => false] + $o);
    [$mb, $hub, $res] = cycle($key, $cls, 'keine Antwort', null, ['silent' => true] + $o);
    ref_ok(($res['vars']['connected'][1] ?? null) !== true, "$key: ohne Antwort nie als verbunden gemeldet");

    // Steuerwege: ohne Steuerregister darf nie geschrieben werden
    $d = new $cls; $mb = new RefMb(1); $hub = new RefHub();
    if ($key !== 'sungrow' && $key !== 'deye') {
        $d->writeControl($mb, $hub, 'ctl_run', true); $d->writeControl($mb, $hub, 'ctl_onoff', true);
        ref_ok($mb->writes === [] && $hub->vars === [], "$key: writeControl schreibt nichts (kein Steuerregister)");
    }
}

echo "Sungrow Stringwechselrichter und Steuerung\n";
cycle('sungrow', 'IHUB_SungrowDriver', 'String-WR (13000 fehlt) positiv', 'pos', ['noRead' => [13000]]);
cycle('sungrow', 'IHUB_SungrowDriver', 'String-WR (13000 fehlt) negativ', 'neg', ['noRead' => [13000]]);
foreach ([['an', true, []], ['aus', false, []], ['an scheitert', true, [13000]]] as [$t, $v, $fail]) {
    $d = new IHUB_SungrowDriver; $mb = new RefMb(1); $mb->failWrite = $fail; $hub = new RefHub();
    $d->writeControl($mb, $hub, 'ctl_run', $v);
    ref_check('treiber', "sungrow write ctl_run $t", ref_result($hub, $mb));
}
$d = new IHUB_SungrowDriver; $mb = new RefMb(1); $hub = new RefHub(); $d->writeControl($mb, $hub, 'ctl_gibt_es_nicht', 1);
ref_ok($mb->writes === [], 'sungrow: unbekannter Ident schreibt nichts');

echo "Deye Steuerung\n";
foreach ([['an', true, []], ['aus', false, []], ['an scheitert', true, [80]]] as [$t, $v, $fail]) {
    $d = new IHUB_DeyeDriver; $mb = new RefMb(1); $mb->failWrite = $fail; $hub = new RefHub();
    $d->writeControl($mb, $hub, 'ctl_onoff', $v);
    ref_check('treiber', "deye write ctl_onoff $t", ref_result($hub, $mb));
}

echo "Victron Regler und Netzzaehler\n";
cycle('victron', 'IHUB_VictronDriver', 'ohne MPPT-Regler und ohne Netz-Unit', 'pos', ['props' => ['_victronMppt' => [], '_victronGrid' => 0]]);
[$mb, $hub, $res] = cycle('victron', 'IHUB_VictronDriver', 'Reglerzugriffe wechseln die Unit-ID', 'pos', ['props' => ['_victronMppt' => [223, 224], '_victronGrid' => 30]]);
$units = array_values(array_unique(array_map(function ($r) { return $r[3]; }, $res['reads'])));
ref_ok(in_array(223, $units, true) && in_array(224, $units, true) && in_array(30, $units, true), 'Victron: Regler- und Netz-Unit-IDs werden angesprochen (' . implode(',', $units) . ')');
ref_ok($res['unitId'] === 100, 'Victron (Beobachtung): nach dem Lauf steht die Unit-ID auf dem Systemwert 100, nicht auf dem eingetragenen Wert; unkritisch, weil der Client je Zyklus neu erzeugt wird');

echo "\nPruefungen je Treiber: ";
foreach ($counts as $k => $n) { echo "$k $n, "; }
echo "\n";
ref_finish('acht Treiber');
