<?php
/**
 * Gesamtquelltext des Moduls InverterHub fuer Prüfstände, die Textmuster im Code suchen oder Klassen per Regex herausschneiden.
 *
 * Seit 0.88.0 liegt jede Klasse in libs/<Klasse>.php, InverterHub/module.php bindet sie per require_once ein. Diese Funktion
 * setzt die Dateien an der Stelle der require_once-Zeilen wieder ein und liefert den frueheren Gesamttext. So bleiben alle
 * alten Prüfstände unverändert aussagekräftig (gleicher Text wie vor dem Aufteilen, bis auf den neu formulierten Kopfkommentar).
 * Neue Prüfstände laden die Klassen besser direkt per require_once (siehe test-ref-*.php).
 */
function ih_source(): string
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $root = dirname(__DIR__);
    $text = file_get_contents($root . '/InverterHub/module.php');
    $cache = preg_replace_callback("/^require_once __DIR__ \\. '\\/\\.\\.\\/libs\\/(\\w+)\\.php';\\n/m", function ($m) use ($root) {
        $t = file_get_contents($root . '/libs/' . $m[1] . '.php');
        return substr($t, strlen("<?php\n"));
    }, $text);
    return $cache;
}
