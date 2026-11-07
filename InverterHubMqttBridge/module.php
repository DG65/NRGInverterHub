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
            $status = $this->T('⚠️ Not connected to an MQTT server. Select the Symcon MQTT server above via the connection of the instance.');
        } elseif (!$s['parentActive']) {
            $status = $this->T('⚠️ The MQTT server is not active (status %d). Check the server and the server socket.', $s['parentStatus']);
        } elseif ($s['hubs'] === 0) {
            $status = $this->T('✅ Connected. No InverterHub instance with connection type "MQTT" selected yet (enter this bridge there).');
        } elseif ($s['lastAge'] < 0) {
            $status = $this->T('✅ Connected, %d instance(s) assigned. No message from the device received so far (check the device ID and the MQTT access on the device).', $s['hubs']);
        } else {
            $status = $this->T('✅ Connected, %d instance(s) assigned. Last message %d s ago (%s).', $s['hubs'], $s['lastAge'], $s['lastTopic']);
        }
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'This bridge connects InverterHub instances to the Symcon MQTT server to which a device (e.g. Hoymiles MS-A2) sends its data.'],
                ['type' => 'Label', 'caption' => 'You enter the device ID (e.g. MSA-280520260806) in the InverterHub instance, not here. Several devices can share one bridge.'],
                ['type' => 'Label', 'caption' => 'In the InverterHub instance select "MQTT" as the connection type and select this bridge here.'],
                ['type' => 'Label', 'caption' => $status],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Rebuild filter', 'onClick' => 'echo IHUBM_Refresh($id) ? ' . var_export($this->T('✅ Filter set.'), true) . ' : ' . var_export($this->T('ℹ️ No instance with a device ID assigned.'), true) . ';'],
            ],
            'status'  => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Bridge ready.'],
            ],
        ]);
    }
}
