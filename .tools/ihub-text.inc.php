<?php
// Gemeinsamer Test-Baustein: laedt IHUB_Text aus module.php und uebersetzt wie Symcon ueber locale.json.
// Sprache: Umgebungsvariable IHUB_SPRACHE (de = Vorgabe, en = Quelltext).
if (!class_exists('IHUB_Text', false)) {
    $__src = file_get_contents(__DIR__ . '/../InverterHub/module.php');
    if (!preg_match('/^class IHUB_Text\n\{.*?^\}\n/ms', $__src, $__m)) {
        fwrite(STDERR, "IHUB_Text nicht gefunden\n");
        exit(1);
    }
    eval($__m[0]);
    $__lang = getenv('IHUB_SPRACHE') ?: 'de';
    $__de = json_decode(file_get_contents(__DIR__ . '/../InverterHub/locale.json'), true)['translations']['de'];
    IHUB_Text::setzen(function (string $t) use ($__lang, $__de): string {
        return $__lang === 'de' ? ($__de[$t] ?? $t) : $t;
    });
}
