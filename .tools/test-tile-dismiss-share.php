<?php
/**
 * Prüfstand: geteiltes Ausblenden über mehrere InverterHubTile-Instanzen
 * (SUITE.md "Ausblenden über mehrere Instanzen desselben Moduls teilen",
 * Dietmar 14.09.2026).
 *
 *   php .tools/test-tile-dismiss-share.php    # 0 = alle Prüfungen bestanden
 *
 * Extrahiert nur die betroffenen Methoden aus InverterHubTile/module.php
 * (nicht die komplette Kachel-Infrastruktur) und simuliert mehrere
 * Instanzen in einem Prozess, um die Cross-Instanz-Propagation inkl.
 * Ping-Pong-Terminierung zu prüfen.
 */

$registry = [];

if (!defined('KL_WARNING')) { define('KL_WARNING', 3); }
if (!defined('VM_UPDATE')) { define('VM_UPDATE', 0); }

class IPSModule
{
    public $InstanceID = 0;
    private $attrs = [];
    public function __construct($id = 0) { $this->InstanceID = $id; }
    public function ReadAttributeBoolean($n) { return $this->attrs[$n] ?? false; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = (bool)$v; }
    public function ReadAttributeString($n) { return $this->attrs[$n] ?? ''; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = (string)$v; }
    public function RegisterAttributeBoolean($n, $d) { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function RegisterAttributeString($n, $d) { if (!array_key_exists($n, $this->attrs)) { $this->attrs[$n] = $d; } }
    public function UpdateFormField($a, $b, $c) {}
    public function LogMessage($m, $l) {}
}

function IPS_GetInstanceListByModuleID($guid) { global $registry; return array_keys($registry); }
function IHUBTILE_DismissReviewHint($id) { global $registry; return $registry[$id]->DismissReviewHint(); }
function IHUBTILE_AckNews($id) { global $registry; return $registry[$id]->AckNews(); }
function IHUBTILE_GetDismissState($id) { global $registry; return $registry[$id]->GetDismissState(); }

$src = file_get_contents(dirname(__DIR__) . '/InverterHubTile/module.php');
preg_match('/private const ATTR_REVIEW_HINT_GONE = .*?;/', $src, $m1);
preg_match('/private const NEWS_VERSION\s*=\s*.*?;/', $src, $m2);
preg_match('/private const SELF_MODULE\s*=\s*.*?;/', $src, $m3);
preg_match('/public function GetDismissState\(\).*?\n    \}/s', $src, $mGet);
preg_match('/public function DismissReviewHint\(\).*?\n    \}/s', $src, $mDismiss);
preg_match('/private function PropagateDismissToSiblings.*?\n    \}/s', $src, $mProp);
preg_match('/public function AckNews\(\).*?\n    \}/s', $src, $mAck);

foreach (['ATTR_REVIEW_HINT_GONE' => $m1, 'NEWS_VERSION' => $m2, 'SELF_MODULE' => $m3, 'GetDismissState' => $mGet, 'DismissReviewHint' => $mDismiss, 'PropagateDismissToSiblings' => $mProp, 'AckNews' => $mAck] as $label => $m) {
    if (empty($m)) { fwrite(STDERR, "Extraktion fehlgeschlagen: $label (Methode/Konstante umbenannt oder umformatiert?)\n"); exit(1); }
}

eval("class InverterHubTile extends IPSModule {\n"
    . $m1[0] . "\n" . $m2[0] . "\n" . $m3[0] . "\n"
    . $mGet[0] . "\n" . $mDismiss[0] . "\n" . $mProp[0] . "\n" . $mAck[0] . "\n"
    . "}\n");

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label\n"; }
}

echo "1) DismissReviewHint propagiert auf Geschwister-Instanz, terminiert sauber\n";
$registry = [101 => new InverterHubTile(101), 102 => new InverterHubTile(102)];
$registry[101]->DismissReviewHint();
check('A selbst ausgeblendet', $registry[101]->ReadAttributeBoolean('ReviewHintDismissed') === true);
check('B per Propagation ausgeblendet', $registry[102]->ReadAttributeBoolean('ReviewHintDismissed') === true);
$registry[102]->WriteAttributeBoolean('ReviewHintDismissed', false);
$registry[101]->DismissReviewHint();
check('bereits ausgeblendete Instanz propagiert nicht erneut (Ping-Pong-Schutz)', $registry[102]->ReadAttributeBoolean('ReviewHintDismissed') === false);

echo "2) AckNews propagiert auf mehrere Geschwister-Instanzen, kein Endlosloop bei 3 Instanzen\n";
$registry = [201 => new InverterHubTile(201), 202 => new InverterHubTile(202), 203 => new InverterHubTile(203)];
$registry[201]->AckNews();
check('alle drei quittiert', $registry[201]->ReadAttributeString('SeenNews') !== '' && $registry[202]->ReadAttributeString('SeenNews') !== '' && $registry[203]->ReadAttributeString('SeenNews') !== '');

echo "3) Neue Instanz uebernimmt Ausblenden-Stand einer vorhandenen Geschwister-Instanz\n";
$registry = [301 => new InverterHubTile(301)];
$registry[301]->DismissReviewHint();
$registry[301]->AckNews();
$registry[302] = new InverterHubTile(302);
foreach (IPS_GetInstanceListByModuleID('x') as $sib) {
    if ($sib === 302) { continue; }
    $state = IHUBTILE_GetDismissState($sib);
    if (!empty($state['reviewGone'])) { $registry[302]->WriteAttributeBoolean('ReviewHintDismissed', true); }
    if (!empty($state['seenNews'])) { $registry[302]->WriteAttributeString('SeenNews', $state['seenNews']); }
    break;
}
check('neue Instanz uebernimmt ReviewHint-Stand', $registry[302]->ReadAttributeBoolean('ReviewHintDismissed') === true);
check('neue Instanz uebernimmt SeenNews-Stand', $registry[302]->ReadAttributeString('SeenNews') === $registry[301]->ReadAttributeString('SeenNews'));

echo "\n" . ($fails === 0 ? "ALLE PRUEFUNGEN BESTANDEN\n" : "$fails PRUEFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
