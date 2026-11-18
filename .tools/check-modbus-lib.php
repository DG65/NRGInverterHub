<?php
/**
 * Prüft, dass libs/NRGModbus/ eine unveränderte Kopie der Quelle ist (Kopierwerkzeug der Bibliothek, Schritt `check`).
 *
 *   php .tools/check-modbus-lib.php        # 0 = gleich der Quelle (oder Quelle nicht vorhanden: nur Hinweis), 1 = Abweichung
 *
 * Die Quelle liegt lokal unter ../NRGModbus (eigenes Repo ohne Remote). Ohne die Quelle (z. B. auf einem anderen Rechner) wird nur
 * geprüft, dass libs/NRGModbus/ vorhanden ist und jede Datei Kopfzeile und Namensraum IHUB\NRGModbus trägt.
 */
$root = dirname(__DIR__);
$lib = $root . '/libs/NRGModbus';
$tool = dirname($root) . '/NRGModbus/.tools/modbus-sync.php';
$bad = 0;
foreach (glob($lib . '/*.php') ?: [] as $f) {
    $t = (string)file_get_contents($f);
    if (basename($f) === 'autoload.php') { continue; }
    if (!str_contains($t, "\n// NRGModbus ") || !preg_match('/^namespace IHUB\\\\NRGModbus;\s*$/m', $t)) { echo "FEHLT Kopfzeile oder Namensraum: " . basename($f) . "\n"; $bad++; }
}
if (!is_file($tool)) {
    echo $bad === 0 ? "Quelle ../NRGModbus nicht vorhanden: nur Kopfzeilen und Namensraum geprüft, in Ordnung\n" : '';
    exit($bad === 0 ? 0 : 1);
}
$out = (string)shell_exec('php ' . escapeshellarg($tool) . ' check 2>&1');
foreach (explode("\n", $out) as $l) {
    if (str_starts_with($l, 'IHUB ')) { echo $l . "\n"; if (!str_contains($l, 'gleich der Quelle')) { $bad++; } }
}
if (!str_contains($out, 'IHUB ')) { echo "Modul IHUB steht nicht in NRGModbus/.tools/modules.json\n"; $bad++; }
exit($bad === 0 ? 0 : 1);
