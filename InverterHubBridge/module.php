<?php

// ---------------------------------------------------------------------------
// InverterHubBridge - Brücke zwischen einer InverterHub-Instanz und dem nativen
// ModBus-Gateway von Symcon (z. B. eingebauter RS485-Port der Symbox).
//
// Nur diese Instanz trägt die Gateway-Schnittstellen in module.json. Läge das
// am Hauptmodul, bekäme jede Direkt-Instanz den Hinweis "benötigt eine
// übergeordnete Instanz" (Forum-Beta-Tester Mstaudi, 19.09.2026).
//
// Vertrag (mit MeterHub/ChargerHub abgestimmt, gleich in allen drei Brücken):
//   Forward(json)  -> immer JSON: {"ok":true,"data":"<base64 der rohen Antwort>"}
//                     oder {"ok":false,"error":"not_connected|parent_inactive|no_response"}
//   GetState()     -> JSON {"connected","parentActive","parentStatus","unitId"}
// Die Antwort ist IMMER Base64: rohe Registerbytes sind meist kein gültiges
// UTF-8 und würden die Instanzgrenze nicht heil überstehen. Die Brücke kennt
// keine Function Codes und reicht die Anfrage unverändert durch.
// Eine Brücke = genau ein Gerät (die Geräte-ID steht am Gateway).
// ---------------------------------------------------------------------------

class InverterHubBridge extends IPSModule
{
    public function Create()
    {
        parent::Create();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function Forward(string $json): string
    {
        $state = $this->ParentState();
        if (!$state['connected']) {
            return json_encode(['ok' => false, 'error' => 'not_connected']);
        }
        if (!$state['parentActive']) {
            return json_encode(['ok' => false, 'error' => 'parent_inactive']);
        }
        $response = @$this->SendDataToParent($json);
        if ($response === false || $response === null || $response === '') {
            return json_encode(['ok' => false, 'error' => 'no_response']);
        }
        return json_encode(['ok' => true, 'data' => base64_encode($response)]);
    }

    public function GetState(): string
    {
        return json_encode($this->ParentState());
    }

    private function ParentState(): array
    {
        $connectionId = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $state = [
            'connected'    => $connectionId > 0,
            'parentActive' => false,
            'parentStatus' => 0,
            'unitId'       => null,
        ];
        if ($connectionId <= 0 || !IPS_InstanceExists($connectionId)) {
            $state['connected'] = false;
            return $state;
        }
        $state['parentStatus'] = (int)IPS_GetInstance($connectionId)['InstanceStatus'];
        $state['parentActive'] = ($state['parentStatus'] === 102);
        try {
            $unit = @IPS_GetProperty($connectionId, 'DeviceID');
            if (is_numeric($unit)) {
                $state['unitId'] = (int)$unit;
            }
        } catch (Throwable $e) {
            // Property nicht lesbar: unitId bleibt null.
        }
        return $state;
    }

    public function GetConfigurationForm()
    {
        $state = $this->ParentState();
        if (!$state['connected']) {
            $status = '⚠️ Mit keinem ModBus-Gateway verbunden. Oben über die Verbindung der Instanz ein Gateway wählen.';
        } elseif (!$state['parentActive']) {
            $status = '⚠️ Das ModBus-Gateway ist nicht aktiv (Status ' . $state['parentStatus'] . '). Gateway und serielle Schnittstelle prüfen.';
        } elseif ($state['unitId'] !== null) {
            $status = '✅ Verbunden. Geräte-ID am Gateway: ' . $state['unitId'];
        } else {
            $status = '✅ Verbunden. Die Geräte-ID ließ sich am Gateway nicht auslesen.';
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Diese Brücke verbindet eine InverterHub-Instanz mit einem ModBus-Gateway von Symcon, zum Beispiel dem eingebauten RS485-Port der Symbox.'],
                ['type' => 'Label', 'caption' => 'Eine Brücke bedient genau ein Gerät: Die Geräteadresse (Unit ID) stellst du am Gateway als „Geräte-ID“ ein. Für ein zweites Gerät am selben Bus braucht es ein zweites Gateway und eine zweite Brücke.'],
                ['type' => 'Label', 'caption' => 'In der InverterHub-Instanz „Symbox-Gateway“ als Verbindungsweg wählen und hier diese Brücke auswählen.'],
                ['type' => 'Label', 'caption' => $status],
            ],
            'actions' => [],
            'status'  => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Brücke bereit.'],
            ],
        ]);
    }
}
