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
    /** @var array<string,string> */
    private static $merkliste = [];

    // Translate() liest locale.json bei jedem Aufruf (SUITE.md Stolperstein 24): je Text einmal je Prozess.
    private function T(string $text, ...$args): string
    {
        if (!isset(self::$merkliste[$text])) {
            self::$merkliste[$text] = $this->Translate($text);
        }
        $out = self::$merkliste[$text];
        if ($args === []) {
            return $out;
        }
        $f = @vsprintf($out, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $f === false ? $out : $f;
    }

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
            $status = $this->T('⚠️ Not connected to a ModBus gateway. Select a gateway above via the connection of the instance.');
        } elseif (!$state['parentActive']) {
            $status = $this->T('⚠️ The ModBus gateway is not active (status %d). Check the gateway and the serial interface.', $state['parentStatus']);
        } elseif ($state['unitId'] !== null) {
            $status = $this->T('✅ Connected. Device ID at the gateway: %d', $state['unitId']);
        } else {
            $status = $this->T('✅ Connected. The device ID could not be read at the gateway.');
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'This bridge connects an InverterHub instance to a Symcon ModBus gateway, for example the built-in RS485 port of the Symbox.'],
                ['type' => 'Label', 'caption' => 'One bridge serves exactly one device: you set the device address (unit ID) at the gateway as "Device ID". A second device on the same bus needs a second gateway and a second bridge.'],
                ['type' => 'Label', 'caption' => 'In the InverterHub instance select "Symbox gateway" as the connection type and select this bridge here.'],
                ['type' => 'Label', 'caption' => $status],
            ],
            'actions' => [],
            'status'  => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Bridge ready.'],
            ],
        ]);
    }
}
