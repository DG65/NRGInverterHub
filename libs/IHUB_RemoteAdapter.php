<?php

// ---------------------------------------------------------------------------
// IHUB_RemoteAdapter — was ein Hersteller für die gemeinsame Fernsteuerung (IHUB_RemoteControl) liefern muss.
// Ein Adapter bildet NUR Register, Skalierung, Vorzeichen, Blockgrenzen und das Setzen des Totmanns ab. Reihenfolge der
// Schreibzugriffe, Abstände, Rücklesen, zweiter Versuch, Warnungen und Fehlerzähler liegen einmal in der Basis.
//
// Anfrage (normalisiert, für alle Hersteller gleich):
//   mode        0 aus (Gerät kehrt zur eigenen Logik zurück), 1 halten (Akku weder laden noch entladen),
//               2 Akku ±W, 3 Netzanschlusspunkt ±W
//   watts       Leistung in W mit EINHEITLICHEM Vorzeichen: Modus 2 + Akku laden / − Akku entladen,
//               Modus 3 + Einspeisung / − Bezug (die Adapter rechnen auf die Konvention des Geräts um)
//   gridCharge  0 unverändert, 1 Netzladen erlaubt, 2 nicht erlaubt
//   socMin/socMax  SOC-Fenster in % (socMax 0 = 100)
//   failsafeSec Totmann in Sekunden, 0 = Vorgabe des Adapters
//   ext         herstellerspezifische Zusätze (z. B. Solis: pvShutdown, importLimitW, exportLimitW, Altmodus 4)
// ---------------------------------------------------------------------------
interface IHUB_RemoteAdapter
{
    /**
     * Beschreibung: ['vendor' => string, 'source' => string (Dokument und Version), 'tested' => bool (am Gerät belegt),
     *   'modes' => int[] (unterstützte Modi), 'gridCharge' => bool, 'socWindow' => bool,
     *   'failsafeMinSec' => int, 'failsafeMaxSec' => int, 'failsafeDefaultSec' => int, 'pitfalls' => string[] (bekannte Fallen)]
     */
    public function info(): array;

    /**
     * Fähigkeit am Gerät lesen. null = keine Antwort. Sonst ['available' => bool, 'version' => int, 'status' => int (Rohstatus),
     * optional 'ratedW' => int (Nennleistung in W, wenn der Adapter sie braucht)].
     */
    public function capability($mb): ?array;

    /** Fehlertext, wenn die Fähigkeit eine Steuerung ausschließt (keine Antwort, nicht verfügbar), sonst ''. */
    public function capabilityError(?array $cap): string;

    /** Prüft die Anfrage gegen den Adapter und die Fähigkeit; Fehlertext oder ''. */
    public function validate(array $req, ?array $cap): string;

    /**
     * Schreibplan: ['steps' => [['fc' => 6|16, 'addr' => int, 'words' => int[], 'afterUs' => int (Wartezeit nach dem Schreibbefehl)]]].
     * Leere Liste = nichts zu schreiben (Anfrage ist bereits erfüllt). $last = zuletzt angewendeter Modus der Instanz (0 = keine eigene Fernsteuerung aktiv).
     */
    public function plan(array $req, array $cap, int $last): array;

    /** Rücklesen nach dem Schreiben: ['regs' => int[] 0-indiziert, 'status' => int] oder null ohne Antwort. */
    public function readback($mb, array $req, array $cap): ?array;

    /** Vergleicht das Rückgelesene mit der Anfrage; Fehlertext oder ''. */
    public function verify(array $back, array $req, array $cap): string;

    /** Zeit in Mikrosekunden, die der Aufrufer vor dem Rücklesen wartet. */
    public function readbackWaitUs(): int;

    /** Pause vor dem zweiten Gesamtversuch. */
    public function retryPauseUs(): int;

    /** Läuft die Fernsteuerung laut Rohstatus? */
    public function isActive(int $status): bool;

    /** Totmann in Sekunden, wie er ans Gerät geht (nach Begrenzung und Rundung). */
    public function effectiveFailsafeSec(array $req): int;
}

/**
 * Optional (seit 0.94.0): Adapter, die vor dem Planen Konfigurationsregister des Geräts lesen müssen, weil diese im Flash liegen und nur bei
 * Abweichung geschrieben werden dürfen (SMA). prepare() gibt die Fähigkeit um herstellerspezifische Angaben ergänzt zurück (z. B. 'cfg').
 * Die Basis ruft es nach capabilityError() und vor validate()/plan() auf.
 */
interface IHUB_RemotePreparing
{
    public function prepare($mb, array $cap): array;
}
