<?php

/**
 * InverterHubMonitor — Uebergangs-Huelle (EMS/Dietmar-Konsolidierung, 14.09.2026).
 * Siehe InverterHubTile/module.php fuer die volle Herleitung — identisches Muster.
 * NRGDashboard hat die Diagramm-/Diagnostik-Darstellung uebernommen.
 */
class InverterHubMonitor extends IPSModule
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
                ['type' => 'Label', 'caption' => 'InverterHubMonitor wird nicht mehr weiterentwickelt — NRGDashboard hat die Diagramm-/Diagnostik-Darstellung übernommen.'],
                ['type' => 'Label', 'caption' => 'Bitte installiere die Kachel „NRGDashboardPVMonitor" aus dem Modul NRGDashboard und richte sie dort neu ein.'],
                ['type' => 'Label', 'caption' => 'Diese Instanz hier kannst du danach löschen (Rechtsklick im Objektbaum → Löschen).'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Entfallen — durch NRGDashboardPVMonitor ersetzt.'],
            ],
        ]);
    }
}
