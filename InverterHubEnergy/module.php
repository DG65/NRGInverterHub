<?php

/**
 * InverterHubEnergy — Uebergangs-Huelle (EMS/Dietmar-Konsolidierung, 14.09.2026).
 * Siehe InverterHubTile/module.php fuer die volle Herleitung — identisches Muster.
 * NRGDashboardPVMonitor (Reiter "Energiebilanz") hat die Sankey-Darstellung uebernommen
 * (Dashboard-Bestaetigung 14.09.2026 — eigene Berechnung, kein reiner Sankey-Nachbau,
 * aber bewusst als Ersatz benannt, Dietmars Entscheidung "Sankey UND neues Balkendiagramm
 * nebeneinander").
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
                ['type' => 'Label', 'caption' => 'InverterHubEnergy (Sankey-Ansicht) wird nicht mehr weiterentwickelt — NRGDashboardPVMonitor (Bibliothek „NRG-Stack Dashboard"), Reiter „Energiebilanz", übernimmt die Energiefluss-Darstellung.'],
                ['type' => 'Label', 'caption' => 'Bitte die Bibliothek „NRG-Stack Dashboard" installieren, dort die Kachel „NRGDashboardPVMonitor" anlegen und den Reiter „Energiebilanz" nutzen.'],
                ['type' => 'Label', 'caption' => 'Diese Instanz hier kannst du danach löschen (Rechtsklick im Objektbaum → Löschen).'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Entfallen — durch NRGDashboardPVMonitor (Reiter „Energiebilanz") ersetzt.'],
            ],
        ]);
    }
}
