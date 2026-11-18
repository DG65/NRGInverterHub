<?php
/**
 * Symcon verlangt Typangaben (int/float/bool/string) an den Parametern öffentlicher Funktionen, die als PREFIX_Funktion()
 * erzeugt werden (Meldung der PHPLibrary beim Laden: "Parameter X in der Funktion Y hat keinen Datentyp").
 *   php .tools/check-public-types.php     # 0 = alle Parameter getypt
 * Ausgenommen sind die Überschreibungen der Basisklasse IPSModule (Konstruktor, RequestAction, ReceiveData, ForwardData, MessageSink).
 */
$root = dirname(__DIR__);
$skip = ['__construct', 'RequestAction', 'ReceiveData', 'ForwardData', 'MessageSink', 'GetConfigurationForm', 'Create', 'ApplyChanges', 'Destroy'];
$bad = 0;
foreach (glob($root . '/*/module.php') as $f) {
    $t = file_get_contents($f);
    preg_match_all('/^    public function (\w+)\(([^)]*)\)/m', $t, $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        if (in_array($x[1], $skip, true)) { continue; }
        foreach (array_filter(array_map('trim', explode(',', $x[2]))) as $p) {
            if (!preg_match('/^\??\w+\s+&?\$/', $p) && !str_starts_with($p, '...')) { echo basename(dirname($f)) . ": $x[1]($p) ohne Datentyp\n"; $bad++; }
        }
    }
}
echo $bad === 0 ? "OK: alle Parameter öffentlicher Funktionen sind getypt\n" : "$bad Parameter ohne Datentyp\n";
exit($bad === 0 ? 0 : 1);
