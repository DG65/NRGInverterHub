<?php
// Zweisprachigkeit Stufe 1 (SUITE.md Punkt 9, Store-Checkliste 25-27): Quelltexte englisch, Deutsch in locale.json.
// Geprueft je Modul: jeder Text im Code steht in locale.json, Platzhalter stimmen, keine deutschen Reste im Code,
// kein IPS_DeleteVariable, keine Geschwister-Abfrage in ApplyChanges.
// Treiber-Variablenbeschriftungen (Zeilen vor der Klasse InverterHub) folgen in Stufe 2 und sind hier ausgenommen.
$root = dirname(__DIR__);
$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'OK   ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }
function lits(string $src): array {
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $q = $t[1][0]; $v = substr($t[1], 1, -1);
            $out[] = [$t[2], $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $v) : stripcslashes($v)];
        }
    }
    return $out;
}
$german = '/[äöüÄÖÜß]|\b(der|die|das|und|nicht|oder|wird|ist|bitte|mit|von|bei|auf|zum|zur|noch|nur|keine|kein|konnte|für|prüfen|Gerät|Wert|Instanz|Verbindung)\b/u';
foreach (['InverterHub', 'InverterHubDiscovery', 'InverterHubBridge', 'InverterHubMqttBridge'] as $mod) {
    $src = file_get_contents("$root/$mod/module.php");
    $de = json_decode(file_get_contents("$root/$mod/locale.json"), true)['translations']['de'];
    $from = 0;
    if ($mod === 'InverterHub') {
        preg_match('/^class InverterHub extends IPSModule/m', $src, $m, PREG_OFFSET_CAPTURE);
        $from = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
    }
    $L = array_filter(lits($src), function ($x) use ($from) { return $x[0] >= $from; });
    // 1) Texte aus t()/T()/Translate()-Aufrufen und Formular-Beschriftungen
    $need = [];
    foreach ($L as [$line, $s]) {
        $need[$s] = $line;
    }
    $missing = [];
    foreach ($need as $s => $line) {
        if (preg_match($german, $s)) {
            // deutscher Text im Code, der kein Schluessel ist
            if (!isset($de[$s]) && !in_array($s, array_values($de), true)) { $missing[] = "$line: $s"; }
        }
    }
    ok($missing === [], "$mod: keine deutschen Texte im Code" . ($missing ? ': ' . implode(' | ', array_slice($missing, 0, 5)) : ''));
    $tcalls = [];
    preg_match_all("/(?:IHUB_Text::t|\\\$this->T|\\\$this->Translate)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $mm);
    foreach ($mm[1] as $raw) { $tcalls[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw); }
    $nokey = array_values(array_filter(array_unique($tcalls), function ($s) use ($de) { return !isset($de[$s]); }));
    ok($nokey === [], "$mod: jeder Text eines Übersetzungsaufrufs steht in locale.json (" . count(array_unique($tcalls)) . ')' . ($nokey ? ': ' . implode(' | ', array_slice($nokey, 0, 4)) : ''));
    if (preg_match("/NEWS_ITEMS = \\[(.*?)\\n    \\];/s", $src, $n)) {
        $items = array_map(function ($x) { return $x[1]; }, lits('<?php ' . $n[1] . ';'));
        $nn = array_values(array_filter($items, function ($s) use ($de) { return !isset($de[$s]); }));
        ok($nn === [], "$mod: jede Neuerung (NEWS_ITEMS) steht in locale.json (" . count($items) . ')' . ($nn ? ': ' . $nn[0] : ''));
    }
    // 1b) Ein Text mit Uebersetzung muss entweder im Uebersetzungsaufruf, als Formular-Beschriftung oder in NEWS_ITEMS stehen -
    // sonst bleibt er in der deutschen Oberflaeche englisch (Meldung, Rueckgabetext, Protokoll).
    $toks = array_values(array_filter(token_get_all($src), function ($t) { return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); }));
    $naked = [];
    $inNews = false;
    foreach ($toks as $i => $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'NEWS_ITEMS') { $inNews = true; }
        if ($inNews && $t === ';') { $inNews = false; }
        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING || $inNews) { continue; }
        $q = $t[1][0]; $v = substr($t[1], 1, -1);
        $v = $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $v) : stripcslashes($v);
        if (!isset($de[$v]) || $t[2] < $from || strpos($v, ' ') === false) { continue; }
        $p1 = $toks[$i - 1] ?? null; $p2 = $toks[$i - 2] ?? null; $p3 = $toks[$i - 3] ?? null;
        $inCall = $p1 === '(' && is_array($p2) && in_array($p2[1], ['t', 'T', 'Translate'], true);
        $isForm = is_array($p1) && $p1[0] === T_DOUBLE_ARROW && is_array($p2) && in_array(trim($p2[1], "'\""), ['caption', 'label', 'suffix', 'title'], true);
        $isTernary = $p1 === '?' || $p1 === ':';
        if (!$inCall && !$isForm && !$isTernary && $v !== 'Janitza UMG (classic map)' && !in_array($v, ['⚡ Power', '🔋 Energy'], true)) { $naked[] = $t[2] . ': ' . $v; }
    }
    ok($naked === [], "$mod: übersetzte Texte stehen nur in Übersetzungsaufrufen oder Formular-Beschriftungen" . ($naked ? ': ' . implode(' | ', array_slice($naked, 0, 40)) : ''));
    // 1c) Formular-Beschriftungen: jede ausser Eigennamen braucht eine deutsche Uebersetzung (die Konsole uebersetzt sie).
    $names = ['GoodWe', 'Sungrow', 'Solis', 'Growatt', 'SolaX', 'SMA', 'Fronius', 'SolarEdge', 'Deye', 'Solplanet / AISWEI', 'Kostal', 'Victron GX', 'TCP-Port', 'Unit ID', '⏱️  Polling', 'Host', 'Port', 'News', 'Version', 'Build', 'Meter', 'Topic', 'Payload', 'Retain', 'Function', 'Address', 'Quantity', 'Data'];
    preg_match_all("/'(?:caption|label|suffix)'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $cm, PREG_OFFSET_CAPTURE);
    $nocap = [];
    foreach ($cm[1] as [$cs, $co]) {
        $cl = substr_count(substr($src, 0, $co), "\n") + 1;
        $cv = str_replace("\\'", "'", $cs);
        if ($cl >= $from && preg_match('/[A-Za-z]{3,}/', $cv) && !isset($de[$cv]) && !in_array($cv, $names, true) && strpos($cv, 'IHUB_') === false && !preg_match('/^[\\w .\\/()+-]*$/', $cv) ) { $nocap[] = "$cl: $cv"; }
        elseif ($cl >= $from && preg_match('/[A-Za-z]{3,}/', $cv) && !isset($de[$cv]) && !in_array($cv, $names, true) && preg_match('/\\s/', $cv)) { $nocap[] = "$cl: $cv"; }
    }
    ok($nocap === [], "$mod: jede Formular-Beschriftung hat eine deutsche Übersetzung" . ($nocap ? ': ' . implode(' | ', array_slice(array_unique($nocap), 0, 10)) : ''));
    // 2) Platzhalter
    $bad = [];
    foreach ($de as $en => $g) {
        preg_match_all('/%(?:\d+\$)?[sd]/', $en, $a); preg_match_all('/%(?:\d+\$)?[sd]/', $g, $b);
        if ($a[0] !== $b[0]) { $bad[] = $en; }
        if ($g === '') { $bad[] = "leer: $en"; }
    }
    ok($bad === [], "$mod: Platzhalter in Englisch und Deutsch gleich" . ($bad ? ': ' . $bad[0] : ''));
    // 3) englische Schluessel duerfen kein Deutsch sein
    $ger = array_values(array_filter(array_keys($de), function ($s) use ($german) { return preg_match($german, $s); }));
    ok($ger === [], "$mod: Quelltexte (Schlüssel) sind englisch" . ($ger ? ': ' . $ger[0] : ''));
    if ($mod === 'InverterHub') {
        ok(strpos($src, 'IPS_DeleteVariable') === false, 'Punkt 26: kein IPS_DeleteVariable, UnregisterVariable statt dessen');
        preg_match('/public function ApplyChanges\(\).*?\n    }\n/s', $src, $ap);
        ok(strpos($ap[0], 'AdoptDismissFromSibling') === false, 'Punkt 27: keine Geschwister-Abfrage in ApplyChanges');
    }
}
echo $fail ? "\n$fail FEHLER\n" : "\nALLE PRUEFUNGEN BESTANDEN\n";
exit($fail ? 1 : 0);
