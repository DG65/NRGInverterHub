<?php
/**
 * Prüfstand: automatische Archiv-Verdichtung (CompactionPlan()), Referenz
 * MeterHub (siehe dortige CLAUDE.md). Extrahiert nur die reine Rechenlogik
 * aus InverterHub/module.php und prüft sie ohne echtes IP-Symcon.
 *
 *   php .tools/test-archive-compaction.php    # 0 = alle Prüfungen bestanden
 */

class IPSModule
{
    private $props = [];
    public function setProp($k, $v) { $this->props[$k] = $v; }
    public function ReadPropertyBoolean($k) { return (bool)($this->props[$k] ?? false); }
    public function ReadPropertyInteger($k) { return (int)($this->props[$k] ?? 0); }
}

$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
preg_match('/private function CompactionPlan\(.*?\n    \}/s', $src, $mPlan);
if (empty($mPlan)) {
    fwrite(STDERR, "Extraktion fehlgeschlagen: CompactionPlan (umbenannt/umformatiert?)\n");
    exit(1);
}
$planCode = preg_replace('/^private function/', 'public function', $mPlan[0]);
eval("class InverterHub extends IPSModule {\n" . $planCode . "\n}\n");

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label\n"; }
}

$m = new InverterHub();
$m->setProp('AutoCompactionPower', true);
$m->setProp('CompactDirectPower', true);
$m->setProp('CompactStage2MonthsPower', 1);
$m->setProp('CompactStage2TypePower', 1);
$m->setProp('CompactStage3MonthsPower', 12);
$m->setProp('CompactStage3TypePower', 2);

echo "1) < 60s Rohintervall: alle drei Stufen aktiv\n";
$p = $m->CompactionPlan(5, 'Power');
check('direkt gesetzt', $p[0] === [-1, 0]);
check('Stufe 2 gesetzt', $p[1] === [1, 1]);
check('Stufe 3 gesetzt', $p[2] === [12, 2]);

echo "2) 60-299s Rohintervall: 'direkt' entfaellt (waere Leerlauf)\n";
$p = $m->CompactionPlan(200, 'Power');
check('direkt aus', $p[0] === [-1, -1]);
check('Stufe 2 weiterhin gesetzt', $p[1] === [1, 1]);
check('Stufe 3 weiterhin gesetzt', $p[2] === [12, 2]);

echo "3) 300-3599s Rohintervall: nur die 12-Monats-Stufe\n";
$p = $m->CompactionPlan(300, 'Power');
check('direkt aus', $p[0][1] === -1);
check('Stufe 2 aus (waere Leerlauf ggue. 5-Min-Rohintervall)', $p[1][1] === -1);
check('Stufe 3 weiterhin gesetzt', $p[2] === [12, 2]);

echo "4) >= 3600s Rohintervall: keine Stufe (Rohdaten schon groeber)\n";
$p = $m->CompactionPlan(3600, 'Power');
check('direkt aus', $p[0][1] === -1);
check('Stufe 2 aus', $p[1][1] === -1);
check('Stufe 3 aus', $p[2][1] === -1);

echo "5) Hauptschalter aus: alle drei Stufen aktiv auf -1 (loescht bestehende Regeln)\n";
$m->setProp('AutoCompactionPower', false);
$p = $m->CompactionPlan(5, 'Power');
check('direkt aktiv auf -1', $p[0] === [-1, -1]);
check('Stufe 2 aktiv auf -1 (Monat bleibt erhalten)', $p[1] === [1, -1]);
check('Stufe 3 aktiv auf -1 (Monat bleibt erhalten)', $p[2] === [12, -1]);

echo "6) 'Direkt' einzeln deaktiviert, Hauptschalter an\n";
$m->setProp('AutoCompactionPower', true);
$m->setProp('CompactDirectPower', false);
$p = $m->CompactionPlan(5, 'Power');
check('direkt aus trotz kleinem Intervall', $p[0] === [-1, -1]);
check('Stufe 2/3 weiterhin gesetzt', $p[1] === [1, 1] && $p[2] === [12, 2]);

echo "7) Power und Energy sind unabhaengige Property-Saetze\n";
$m->setProp('AutoCompactionEnergy', true);
$m->setProp('CompactDirectEnergy', true);
$m->setProp('CompactStage2MonthsEnergy', 2);
$m->setProp('CompactStage2TypeEnergy', 3);
$m->setProp('CompactStage3MonthsEnergy', 24);
$m->setProp('CompactStage3TypeEnergy', 5);
$p = $m->CompactionPlan(120, 'Energy');
check('eigene Stage2-Werte', $p[1] === [2, 3]);
check('eigene Stage3-Werte', $p[2] === [24, 5]);

echo "\n" . ($fails === 0 ? "ALLE PRUEFUNGEN BESTANDEN\n" : "$fails PRUEFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
