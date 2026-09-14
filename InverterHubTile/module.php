<?php

/**
 * InverterHubTile — Uebergangs-Huelle (EMS/Dietmar-Konsolidierung, 14.09.2026).
 *
 * Die echte Stromfluss-Kachel ist auf diesem Zweig (ems-integration -> beta)
 * entfallen; NRGDashboard hat die Funktion uebernommen. Diese Datei behaelt
 * bewusst dieselbe Modul-GUID/Klasse/Ident-Praefix bei, damit bereits
 * bestehende Instanzen (viele der ~240 Store-Installationen) beim naechsten
 * Update NICHT mit einem Fatal Error ("require_once ... Failed opening
 * required") abbrechen, sondern eine klare Handlungsanweisung sehen. Keine
 * Messwerte, keine Timer, keine Variablen mehr - reine Hinweis-Anzeige.
 *
 * Nicht eigenmaechtig weiter ausbauen: sobald Dietmar entscheidet, dass die
 * Alt-Instanzen ausreichend migriert sind, kann diese Huelle (und das ganze
 * Modul) komplett entfallen.
 */
class InverterHubTile extends IPSModule
{
    public function Create()
    {
        parent::Create();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(104);
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => '⚠️  Diese Kachel ist entfallen'],
                ['type' => 'Label', 'caption' => 'InverterHubTile wird nicht mehr weiterentwickelt — NRGDashboard hat die Stromfluss-Anzeige übernommen.'],
                ['type' => 'Label', 'caption' => 'Bitte installiere die Kachel „NRGDashboardTile" aus dem Modul NRGDashboard und richte sie dort neu ein.'],
                ['type' => 'Label', 'caption' => 'Diese Instanz hier kannst du danach löschen (Rechtsklick im Objektbaum → Löschen).'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Entfallen — durch NRGDashboardTile ersetzt.'],
            ],
        ]);
    }
}
