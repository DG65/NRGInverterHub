<?php

// ---------------------------------------------------------------------------
// IHUB_InverterDriverInterface — Vertrag, den jeder Hersteller-Treiber erfüllt
// ---------------------------------------------------------------------------

interface IHUB_InverterDriverInterface
{
    /**
     * Immer aktive Basisvariablen.
     * [ident, caption, type(F/I/B/S), profile, archive, group, reg]
     */
    public function getBaseVars();

    /**
     * Optionale Variablengruppen, je Property-Name (Checkbox in der Instanz).
     * ['GroupXYZ' => ['caption' => '...', 'vars' => [...]]]
     */
    public function getOptionalGroups();

    /** Zusätzliche boolesche Properties, die dieser Treiber braucht (Default-Werte). */
    public function getExtraBooleanProperties();

    /** Custom-Profile, die dieser Treiber anlegt: [name => [type, suffix, min, max, step, digits]] */
    public function getProfiles();

    /** Enum-Profile (Assoziationen): [name => [wert => [label, farbe]]] */
    public function getEnumProfiles();

    /** Liest die schnellen (Leistungs-)Werte. Rückgabe: Verbindung erfolgreich? */
    public function readFast($mb, $hub);

    /** Liest die langsamen Werte (Zählerstände, Fehler). */
    public function readSlow($mb, $hub);

    /** Liest einmalig Geräteinformationen (Seriennummer, Modell, Firmware). */
    public function readDeviceInfo($mb, $hub);

    /** Verarbeitet einen Schreibzugriff (RequestAction) auf ein Steuer-Ident. */
    public function writeControl($mb, $hub, string $ident, $value);
}
