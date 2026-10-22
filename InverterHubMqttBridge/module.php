<?php

// ---------------------------------------------------------------------------
// InverterHubMqttBridge - Brücke zwischen InverterHub-Instanzen und dem MQTT-Server
// von Symcon (Geräte, die ihre Daten per MQTT senden, z. B. Hoymiles MS-A2).
//
// Nur diese Instanz trägt die MQTT-Schnittstellen in module.json. Läge das am
// Hauptmodul, bekäme jede Direkt-Instanz den Hinweis "benötigt eine
// übergeordnete Instanz" (gleiche Überlegung wie bei der ModBus-Gateway-Brücke).
//
// Datenfluss (am Referenzmodul Schnittcher/IPS-Zigbee2MQTT gelesen, nicht am
// Gerät erprobt):
//   Empfang: ReceiveData($JSONString) mit {"Topic":..., "Payload":...}; ab Kernel-
//            Datum 1670886000 liegt die Payload UTF-8-kodiert vor (utf8_decode).
//   Senden:  SendDataToParent mit DataID {043EA491-0325-4ADD-8FC2-A30C8EEB4D3F},
//            PacketType 3 (PUBLISH), QualityOfService 0, Retain false,
//            Topic, Payload (utf8_encode).
// Empfangene Nachrichten werden an alle InverterHub-Instanzen mit Verbindungsweg
// "MQTT" weitergereicht, die auf diese Brücke zeigen (IHUB_MqttReceive). Die
// Geräte-ID (z. B. MSA-280520260806) steht an der InverterHub-Instanz; die Brücke
// baut daraus ihren Empfangsfilter.
// ---------------------------------------------------------------------------

class InverterHubMqttBridge extends IPSModule
{
    private const HUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const TX_DATA_ID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    public function Create()
    {
        parent::Create();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->Refresh();
        $this->SetStatus(102);
    }

    // Baut den Empfangsfilter neu (aus den Geräte-IDs der verbundenen InverterHub-Instanzen).
    // Wird bei ApplyChanges und von der InverterHub-Instanz nach jeder Änderung aufgerufen.
    public function Refresh(): bool
    {
        $ids = [];
        foreach ($this->LinkedHubs() as $hubId) {
            $dev = trim((string)@IPS_GetProperty($hubId, 'MqttDeviceID'));
            if ($dev !== '') {
                $ids[$dev] = true;
            }
        }
        if (!$ids) {
            $this->SetReceiveDataFilter('NOTHING_TO_RECEIVE');
            return false;
        }
        $parts = [];
        foreach (array_keys($ids) as $d) {
            $parts[] = preg_quote($d, '/');
        }
        $this->SetReceiveDataFilter('.*"Topic":"homeassistant/[^/]+/(' . implode('|', $parts) . ')/.*');
        return true;
    }

    public function ReceiveData($JSONString)
    {
        $buffer = json_decode($JSONString, true);
        if (!is_array($buffer) || !isset($buffer['Topic'])) {
            return '';
        }
        $topic = (string)$buffer['Topic'];
        $payload = (string)($buffer['Payload'] ?? '');
        if (IPS_GetKernelDate() > 1670886000) {
            $payload = utf8_decode($payload);
        }
        $this->SetBuffer('LastTopic', $topic);
        $this->SetBuffer('LastTime', (string)time());
        if (!function_exists('IHUB_MqttReceive')) {
            return '';
        }
        foreach ($this->LinkedHubs() as $hubId) {
            @IHUB_MqttReceive($hubId, $topic, $payload);
        }
        return '';
    }

    // Veröffentlicht eine Nachricht über den MQTT-Server.
    public function Publish(string $topic, string $payload): bool
    {
        $connectionId = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($connectionId <= 0 || !IPS_InstanceExists($connectionId) || (int)IPS_GetInstance($connectionId)['InstanceStatus'] !== 102) {
            return false;
        }
        $data = [
            'DataID'           => self::TX_DATA_ID,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => utf8_encode($payload),
        ];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $this->SendDebug('Publish ' . $topic, $payload, 0);
        @$this->SendDataToParent($json);
        return true;
    }

    public function GetState(): string
    {
        $connectionId = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $state = [
            'connected'    => $connectionId > 0 && IPS_InstanceExists($connectionId),
            'parentActive' => false,
            'parentStatus' => 0,
            'lastTopic'    => $this->GetBuffer('LastTopic'),
            'lastAge'      => $this->GetBuffer('LastTime') === '' ? -1 : (time() - (int)$this->GetBuffer('LastTime')),
            'hubs'         => count($this->LinkedHubs()),
        ];
        if ($state['connected']) {
            $state['parentStatus'] = (int)IPS_GetInstance($connectionId)['InstanceStatus'];
            $state['parentActive'] = ($state['parentStatus'] === 102);
        }
        return json_encode($state);
    }

    // InverterHub-Instanzen mit Verbindungsweg "MQTT", die auf diese Brücke zeigen.
    private function LinkedHubs(): array
    {
        $found = [];
        foreach (IPS_GetInstanceListByModuleID(self::HUB_GUID) as $hubId) {
            if (@IPS_GetProperty($hubId, 'ConnectionType') === 'mqtt'
                && (int)@IPS_GetProperty($hubId, 'BridgeInstanceID') === $this->InstanceID) {
                $found[] = (int)$hubId;
            }
        }
        return $found;
    }

    public function GetConfigurationForm()
    {
        $s = json_decode($this->GetState(), true);
        if (!$s['connected']) {
            $status = '⚠️ Mit keinem MQTT-Server verbunden. Oben über die Verbindung der Instanz den MQTT-Server von Symcon wählen.';
        } elseif (!$s['parentActive']) {
            $status = '⚠️ Der MQTT-Server ist nicht aktiv (Status ' . $s['parentStatus'] . '). Server und Server-Socket prüfen.';
        } elseif ($s['hubs'] === 0) {
            $status = '✅ Verbunden. Noch keine InverterHub-Instanz mit Verbindungsweg „MQTT“ ausgewählt (dort diese Brücke eintragen).';
        } elseif ($s['lastAge'] < 0) {
            $status = '✅ Verbunden, ' . $s['hubs'] . ' Instanz(en) zugeordnet. Bisher keine Nachricht des Geräts empfangen (Geräte-ID und MQTT-Zugang am Gerät prüfen).';
        } else {
            $status = '✅ Verbunden, ' . $s['hubs'] . ' Instanz(en) zugeordnet. Letzte Nachricht vor ' . $s['lastAge'] . ' s (' . $s['lastTopic'] . ').';
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Diese Brücke verbindet InverterHub-Instanzen mit dem MQTT-Server von Symcon, an den ein Gerät (z. B. Hoymiles MS-A2) seine Daten sendet.'],
                ['type' => 'Label', 'caption' => 'Die Geräte-ID (z. B. MSA-280520260806) trägst du in der InverterHub-Instanz ein, nicht hier. Mehrere Geräte können sich eine Brücke teilen.'],
                ['type' => 'Label', 'caption' => 'In der InverterHub-Instanz „MQTT“ als Verbindungsweg wählen und hier diese Brücke auswählen.'],
                ['type' => 'Label', 'caption' => $status],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Filter neu aufbauen', 'onClick' => 'echo IHUBM_Refresh($id) ? "✅ Filter gesetzt." : "ℹ️ Keine Instanz mit Geräte-ID zugeordnet.";'],
            ],
            'status'  => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Brücke bereit.'],
            ],
        ]);
    }
}
