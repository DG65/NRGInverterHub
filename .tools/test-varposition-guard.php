<?php
/**
 * Prüfstand: Store-Review-Fund 9m (HeishaMon-Sitzung, 28.09.2026) - IPS_SetPosition()
 * darf eine manuelle Umsortierung im Objektbaum nicht bei jedem ApplyChanges() rückgängig
 * machen. RegisterVar() ist wegen seiner vielen Abhängigkeiten (RegisterVariableXXX,
 * EnsureCategory, IPS_CreateVariable, ...) nicht sinnvoll isoliert lauffähig zu machen -
 * strukturelle Prüfung direkt am Quelltext, analog zu anderen "kein X in Y"-Tests hier.
 *
 *   php .tools/test-varposition-guard.php    # 0 = alle Prüfungen bestanden
 */
$src = file_get_contents(dirname(__DIR__) . '/InverterHub/module.php');
if (!preg_match('/private function RegisterVar\(array \$def, int \$pos\).*?\n    \}\n/s', $src, $m)) {
    fwrite(STDERR, "RegisterVar() nicht gefunden (umbenannt/umformatiert?)\n");
    exit(1);
}
$body = $m[0];
$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) { echo "  ok    $label\n"; } else { $fails++; echo "  FEHLT $label\n"; }
}

echo "1) IPS_SetPosition() steht nur innerhalb eines \"if (\\\$created)\"-Zweigs\n";
check(
    'Aufruf ist an $created gebunden, nicht unbedingt bei jedem ApplyChanges()',
    (bool)preg_match('/if\s*\(\s*\$created\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*IPS_SetPosition\(\$vid,\s*\$pos\);/', $body)
);
check(
    'kein unbedingter IPS_SetPosition($vid, ...) außerhalb dieses Zweigs',
    substr_count($body, 'IPS_SetPosition($vid') === 1
);

echo "2) IPS_SetParent()/IPS_SetName() bleiben unbedingt (Kategorie-/Namenswechsel, z. B. bei ControlAuthority, soll weiter greifen)\n";
check('IPS_SetParent($vid, ...) weiterhin bei jedem Durchlauf', (bool)preg_match('/IPS_SetParent\(\$vid,\s*\$catID\);/', $body));
check('IPS_SetName($vid, ...) weiterhin bei jedem Durchlauf', (bool)preg_match('/IPS_SetName\(\$vid,\s*\$caption\);/', $body));

echo "\n" . ($fails === 0 ? "ALLE PRUEFUNGEN BESTANDEN\n" : "$fails PRUEFUNG(EN) FEHLGESCHLAGEN\n");
exit($fails === 0 ? 0 : 1);
