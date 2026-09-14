<?php

/**
 * InverterHubEnergy — Uebergangs-Huelle (EMS/Dietmar-Konsolidierung, 14.09.2026).
 * Siehe InverterHubTile/module.php fuer die volle Herleitung — identisches Muster.
 * NRGDashboard hat die Sankey-/Energiefluss-Darstellung uebernommen.
 *
 * TODO: exakten Namen der NRGDashboard-Nachfolgekachel eintragen, sobald
 * Dashboard ihn bestaetigt hat (angefragt 14.09.2026) — aktuell generischer
 * Hinweistext, kein konkreter Kachelname, um keine falsche Anleitung zu geben.
 */
class InverterHubEnergy extends IPSModule
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
                ['type' => 'Label', 'caption' => 'InverterHubEnergy (Sankey-Ansicht) wird nicht mehr weiterentwickelt — NRGDashboard hat die Energiefluss-Darstellung übernommen.'],
                ['type' => 'Label', 'caption' => 'Bitte installiere die passende Energiefluss-Kachel aus dem Modul NRGDashboard und richte sie dort neu ein.'],
                ['type' => 'Label', 'caption' => 'Diese Instanz hier kannst du danach löschen (Rechtsklick im Objektbaum → Löschen).'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Entfallen — durch NRGDashboard ersetzt.'],
            ],
        ]);
    }
}
