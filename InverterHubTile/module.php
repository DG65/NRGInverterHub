<?php

/**
 * InverterHubTile
 *
 * HTML-Kachel für InverterHub. Liest Variablen einer InverterHub-Instanz
 * (beliebiger Hersteller) und stellt sie als animierte Energiefluss-Kachel
 * dar (Solar / Netz / Last / Batterie). Da die Datenpunkte je nach
 * Hersteller-Treiber unterschiedlich heißen bzw. teils fehlen (nicht jeder
 * Treiber liefert Netzmessung oder Batteriewerte), wird pro Größe eine
 * Ident-Fallback-Kette probiert; fehlt eine Größe komplett, wird der
 * zugehörige Kreis ausgegraut statt mit falschen Werten befüllt.
 *
 * Pattern identisch zu GoodweETTile (DG65).
 */
class InverterHubTile extends IPSModule
{
    private const SOURCE_MODULE = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';

    // Ident-Fallback-Ketten je Größe (erster gefundener Ident gewinnt).
    // pv_real (berechnete PV-Erzeugung, z. B. SolarEdge StorEdge) hat Vorrang
    // vor pv_total, da Letzteres bei Batteriebetrieb die reine PV nicht abbildet.
    private const IDENT_PV     = ['pv_real', 'pv_total'];
    private const IDENT_AC     = ['ac_power'];
    private const IDENT_GRID   = ['meter_total'];
    private const IDENT_BATPWR = ['bat_total_pwr', 'bat_power'];
    private const IDENT_SOC    = ['soc', 'bat_soc'];
    private const IDENT_CONN   = ['connected'];

    private const DEF_BACKGROUND = -1;
    private const DEF_FONT       = 'system';
    private const DEF_TRANSITION = 800;
    private const DEF_TOLERANCE  = 300;
    private const DEF_FLOWREF    = 10000;

    // Auswählbare Verbraucher-Arten. Der Schlüssel steht in der Konfiguration,
    // 'label' dient als Vorgabe-Bezeichnung (wenn der Nutzer keine eigene
    // vergibt), 'icon' verweist auf den Icon-Zeichner in module.html und
    // 'color' ist die Vorgabefarbe der Art (je Zeile überschreibbar).
    // Farbwahl: Wärme in Feuertönen, Kühlung/Wasser in Türkis, Fahrzeuge in
    // Violett (bewusst abgesetzt von der blauen Hausbatterie).
    private const CONSUMER_TYPES = [
        'wallbox'  => ['label' => 'Wallbox',         'icon' => 'car',      'color' => 0x9575CD],
        'heatpump' => ['label' => 'Wärmepumpe',      'icon' => 'heatpump', 'color' => 0xFF7A18],
        'ac'       => ['label' => 'Klimaanlage',     'icon' => 'ac',       'color' => 0x26C6DA],
        'poolheat' => ['label' => 'Pool-Wärmepumpe', 'icon' => 'poolheat', 'color' => 0xFF8A50],
        'poolpump' => ['label' => 'Pool-Pumpe',      'icon' => 'poolpump', 'color' => 0x26A69A],
        'sauna'    => ['label' => 'Sauna',           'icon' => 'sauna',    'color' => 0xF4511E],
        'boiler'   => ['label' => 'Warmwasser',      'icon' => 'boiler',   'color' => 0xFFA726],
        'dryer'    => ['label' => 'Trockner',        'icon' => 'dryer',    'color' => 0x78909C],
        // Haushalt und weitere Bereiche — Vokabular deckungsgleich mit der
        // Funktionszuordnung des MeterHub-Moduls, damit dessen Zähler/Phasen
        // direkt als passender Verbraucher-Kreis übernommen werden können.
        'washer'     => ['label' => 'Waschmaschine',      'icon' => 'washer',     'color' => 0x4DD0E1],
        'dishwasher' => ['label' => 'Spülmaschine',       'icon' => 'dishwasher', 'color' => 0x4DB6AC],
        'oven'       => ['label' => 'Backofen',           'icon' => 'oven',       'color' => 0xEF6C00],
        'stove'      => ['label' => 'Herd',               'icon' => 'stove',      'color' => 0xE64A19],
        'fridge'     => ['label' => 'Kühl-/Gefriergerät', 'icon' => 'fridge',     'color' => 0x4FC3F7],
        'kitchen'    => ['label' => 'Küche',              'icon' => 'kitchen',    'color' => 0xFFB74D],
        'heater'     => ['label' => 'Heizung',            'icon' => 'heater',     'color' => 0xFF7043],
        'vent'       => ['label' => 'Lüftung',            'icon' => 'vent',       'color' => 0x80DEEA],
        'light'      => ['label' => 'Beleuchtung',        'icon' => 'light',      'color' => 0xFFD54F],
        'it'         => ['label' => 'Server / Netzwerk',  'icon' => 'it',         'color' => 0x7986CB],
        'workshop'   => ['label' => 'Werkstatt',          'icon' => 'workshop',   'color' => 0x8D6E63],
        'garage'     => ['label' => 'Garage',             'icon' => 'garage',     'color' => 0xB39DDB],
        'appliances'    => ['label' => 'Haushaltsgeräte (allgemein)', 'icon' => 'appliances',    'color' => 0x90A4AE],
        'entertainment' => ['label' => 'Unterhaltungsmedien',         'icon' => 'entertainment', 'color' => 0x7E57C2],
        'other'    => ['label' => 'Verbraucher',     'icon' => 'other',    'color' => 0x90A4AE],
    ];

    // Anbieter des MHUB-Vertrags: echte Zähler und virtuelle (berechnete).
    private const METERHUB_GUID         = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    // InverterHubMonitor - liefert den Diagnostik-Vertrag IHUBMON_GetDiagnostics
    // (Gesundheitsanzeige der Kachel: Warndreieck + Diagnoseleiste).
    private const MONITOR_GUID          = '{7B1F9A34-6C52-4E8D-9A1B-4F3E2D7C6A19}';
    private const METERHUB_VIRTUAL_GUID = '{ADF18291-2E60-4354-92F5-B96863C127C8}';

    // Übersetzung der MeterHub-Funktionen in Verbraucher-Arten dieser Kachel.
    // Nicht gelistete Funktionen (grid, house, pv, battery) sind Kernwerte und
    // werden nicht als eigener Verbraucher-Kreis dargestellt.
    private const MHUB_TYPE_MAP = [
        'heatpump'    => 'heatpump',
        'heater'      => 'heater',
        'hotwater'    => 'boiler',
        'aircon'      => 'ac',
        'ventilation' => 'vent',
        'wallbox1'    => 'wallbox',
        'wallbox2'    => 'wallbox',
        'wallbox3'    => 'wallbox',
        'wallbox4'    => 'wallbox',
        'wallbox5'    => 'wallbox',
        'garage'      => 'garage',
        'washer'      => 'washer',
        'dryer'       => 'dryer',
        'dishwasher'  => 'dishwasher',
        'oven'        => 'oven',
        'stove'       => 'stove',
        'fridge'      => 'fridge',
        'kitchen'     => 'kitchen',
        'pool'        => 'poolpump',
        'sauna'       => 'sauna',
        'light'       => 'light',
        'it'          => 'it',
        'workshop'    => 'workshop',
        'appliances'    => 'appliances',
        'entertainment' => 'entertainment',
        'other'       => 'other',
    ];

    // „Was ist neu"-Banner (siehe newsBanner()/AckNews()).
    private const NEWS_VERSION = '0.72';
    private const NEWS_ITEMS = [
        'Einheit wählt sich automatisch nach Größe (W/kW/MW) statt fest kW mit drei Nachkommastellen.',
        'Victron: Hauslast-Berechnung korrigiert (war zu hoch, wenn gleichzeitig Netzbezug bestand).',
        'Eingesteckte Wallbox wird nicht mehr fälschlich ausgegraut, wenn gerade nicht geladen wird.',
    ];
    // Symcon-Forum-Hinweis (Verbund-Konvention, Formularpunkt 4): dismissible,
    // aber EINMALIG statt versionsscharf - der Forum-Link ändert sich normalerweise nicht.
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-inverterhub-multi-wechselrichter-ein-modbus-tcp-modul-fuer-goodwe-sma-fronius-sungrow-solis-growatt-solax/144121';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeString('SeenNews', '');

        $this->RegisterPropertyInteger('SourceInstance', 0);
        // Manueller Modus (ohne InverterHub-Instanz): einzelne Variablen direkt
        // zuweisen. Wird verwendet, wenn keine InverterHub-Instanz gewählt ist.
        $this->RegisterPropertyInteger('ManualPvID', 0);
        $this->RegisterPropertyString('ManualPvUnit', 'auto');
        $this->RegisterPropertyInteger('ManualAcID', 0);
        $this->RegisterPropertyString('ManualAcUnit', 'auto');
        $this->RegisterPropertyInteger('ManualGridID', 0);
        $this->RegisterPropertyString('ManualGridUnit', 'auto');
        $this->RegisterPropertyBoolean('ManualGridInvert', false);
        $this->RegisterPropertyInteger('ManualBatID', 0);
        $this->RegisterPropertyString('ManualBatUnit', 'auto');
        $this->RegisterPropertyBoolean('ManualBatInvert', false);
        $this->RegisterPropertyInteger('ManualSocID', 0);
        $this->RegisterPropertyInteger('ManualHouseID', 0);
        $this->RegisterPropertyInteger('ColorBackground', self::DEF_BACKGROUND);
        $this->RegisterPropertyString('FontFamily',       self::DEF_FONT);
        $this->RegisterPropertyInteger('TransitionMs',    self::DEF_TRANSITION);
        // Referenzleistung fürs Fluss-Tempo: bei dieser Leistung laufen die
        // Dreiecke mit Höchsttempo. Kleinerer Wert = Unterschiede im
        // Alltagsbereich (1-10 kW) deutlicher sichtbar.
        $this->RegisterPropertyInteger('FlowRefW', self::DEF_FLOWREF);
        // Zusätzliche Verbraucher, die nicht aus dem Wechselrichter kommen,
        // sondern als vorhandene Leistungs-Variablen ausgewählt werden.
        // Frei erweiterbare Tabelle: je Zeile Art, Bezeichnung und Variable.
        $this->RegisterPropertyString('Consumers', '[]');
        // MeterHub-Instanzen, deren Funktionszuordnung übernommen wird.
        $this->RegisterPropertyString('MeterHubs', '[]');
        // HeishaMon-Instanzen (Wärmepumpe), Vertrag HEISHA_GetFunctions.
        $this->RegisterPropertyString('HeishaMons', '[]');
        // Fahrzeuge (für Wallboxen): Bezeichnung, Verbunden-Bedingung, SOC.
        $this->RegisterPropertyString('Vehicles', '[]');
        // Zeitfenster für die automatische Zuordnung Fahrzeug <-> Wallbox.
        $this->RegisterPropertyInteger('MatchToleranceSec', self::DEF_TOLERANCE);
        // Berechnete Hauslast zusätzlich in eine eigene Variable schreiben.
        $this->RegisterPropertyBoolean('WriteHouseLoad', false);
        // Echter, gemessener Hausverbrauch (Variable) - hat Vorrang vor der
        // rechnerischen Bilanz und wird dann in der Mitte angezeigt.
        $this->RegisterPropertyInteger('HouseLoadID', 0);

        // Einfuehrungs-Tour bei erster Benutzung (Verbund-Konvention
        // "Einfuehrungs-Tour fuer Kacheln", SUITE.md 29.08.2026, Muster
        // NRGDashboardTile): Bestaetigung kommt ueber den WebHook zurueck
        // (?dismissTour=1), da die sandboxed HTML-SDK-Kachel keinen anderen
        // Rueckkanal in die Instanz hat.
        $this->RegisterAttributeBoolean('TourSeen', false);
        // Geisterringe (gestriger Vergleichswert je Knoten): Archiv-Abfragen
        // je BuildPayload() waeren zu teuer - 5-Minuten-Cache je Variable
        // (Muster NRGDashboardTile YesterdayCache).
        $this->RegisterAttributeString('YesterdayCache', '{}');

        // Anzeige-Feinheiten "hinter dem Doppelpfeil" (Muster NRGDashboardTile/
        // Prognose-Energiebilanz): echte Instanz-Variablen mit EnableAction()
        // statt Formular-Properties - der WebFront-Doppelpfeil zeigt die
        // Instanz-Kinder, dort sind sie ohne Konsolenzugriff bedienbar.
        // Steuervariablen, nie archiviert - RegisterVariableXXX unkritisch.
        foreach ([
            'HideInactive'    => ['Inaktive Knotenpunkte ausblenden statt nur ausgrauen', 200, false],
            'CoupleBoltPower' => ['Blitzbögen an Leistung koppeln', 201, true],
            'CoupleGlowPower' => ['Leuchtschein an Leistung koppeln', 202, true],
        ] as $ident => [$caption, $pos, $default]) {
            $isNew = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->RegisterVariableBoolean($ident, $caption, '', $pos);
            $this->EnableAction($ident);
            if ($isNew) {
                $this->SetValue($ident, $default);
            }
        }
        $isNewIntensity = @IPS_GetObjectIDByIdent('EffectIntensity', $this->InstanceID) === false;
        $this->RegisterVariableInteger('EffectIntensity', 'Effekt-Intensität (Blitze/Leuchtschein)', '~Intensity.100', 203);
        $this->EnableAction('EffectIntensity');
        if ($isNewIntensity) {
            $this->SetValue('EffectIntensity', 100);
        }

        $this->SetVisualizationType(1);
    }

    // WebFront-Bedienung der Doppelpfeil-Variablen (s. Create()).
    public function RequestAction($Ident, $Value)
    {
        if (in_array($Ident, ['HideInactive', 'CoupleBoltPower', 'CoupleGlowPower'], true)) {
            $this->SetValue($Ident, (bool)$Value);
            $this->UpdateVisualizationValue($this->BuildPayload());
            return;
        }
        if ($Ident === 'EffectIntensity') {
            $this->SetValue($Ident, max(50, min(150, (int)$Value)));
            $this->UpdateVisualizationValue($this->BuildPayload());
        }
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        // Eigener WebHook: Tour-Bestaetigung (?dismissTour=1) + Standalone-
        // Ausgabe der Kachel fuer IPSView/Browser (Muster NRGDashboardTile).
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook('/hook/ihubtile' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $msg) {
                if ($msg === VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        $src = $this->ResolveSource();
        if ($src > 0 && IPS_InstanceExists($src)) {
            $allIdents = array_merge(
                self::IDENT_PV, self::IDENT_AC, self::IDENT_GRID,
                self::IDENT_BATPWR, self::IDENT_SOC, self::IDENT_CONN
            );
            foreach (array_unique($allIdents) as $ident) {
                $vid = $this->FindIdentRecursive($src, $ident);
                if ($vid && $vid > 0) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                }
            }
            $this->SetStatus(102);
        } else {
            // Manueller Modus: die direkt zugewiesenen Variablen abonnieren.
            $manualIDs = [
                $this->ReadPropertyInteger('ManualPvID'),
                $this->ReadPropertyInteger('ManualAcID'),
                $this->ReadPropertyInteger('ManualGridID'),
                $this->ReadPropertyInteger('ManualBatID'),
                $this->ReadPropertyInteger('ManualSocID'),
                $this->ReadPropertyInteger('ManualHouseID'),
            ];
            // Kernwerte aus MeterHub ebenfalls abonnieren.
            foreach ($this->MeterHubCoreIDs() as $mhID) {
                $manualIDs[] = $mhID;
            }
            $any = false;
            foreach (array_unique($manualIDs) as $vid) {
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $this->RegisterReference($vid);
                    $this->RegisterMessage($vid, VM_UPDATE);
                    $any = true;
                }
            }
            $this->SetStatus($any ? 102 : 201);
        }

        // Zusätzliche Verbraucher (Wärmepumpe/Wallboxen) liegen außerhalb der
        // Quell-Instanz und müssen separat abonniert werden.
        foreach ($this->CollectConsumerVarIDs() as $vid) {
            $this->RegisterReference($vid);
            $this->RegisterMessage($vid, VM_UPDATE);
        }

        // Optionale Ausgabe der berechneten Hauslast als eigene Variable.
        $this->MaintainVariable(
            'house_load',
            'Hauslast (berechnet)',
            VARIABLETYPE_FLOAT,
            '~Watt',
            10,
            $this->ReadPropertyBoolean('WriteHouseLoad')
        );

        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    // Liefert die IDs aller konfigurierten Verbraucher-Variablen, gefiltert auf
    // tatsächlich existierende Variablen.
    private function CollectConsumerVarIDs()
    {
        $ids = [];
        foreach ($this->ReadConsumerRows() as $row) {
            $ids[] = $row['id'];
            if ($row['plugID'] > 0 && IPS_VariableExists($row['plugID'])) {
                $ids[] = $row['plugID'];
            }
        }
        foreach ($this->ReadVehicleRows() as $v) {
            $ids[] = $v['socID'];
            if ($v['plugID'] > 0 && IPS_VariableExists($v['plugID'])) {
                $ids[] = $v['plugID'];
            }
        }
        return array_unique($ids);
    }

    // Verbraucher-Tabelle aus der Konfiguration lesen und auf gültige Zeilen
    // reduzieren (existierende Variable). Unbekannte Arten fallen auf
    // 'other' zurück, eine leere Bezeichnung auf die Vorgabe der Art.
    private function ReadConsumerRows()
    {
        $rows = json_decode($this->ReadPropertyString('Consumers'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $vid = (int)($row['VariableID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $type = (string)($row['Type'] ?? 'other');
            if (!isset(self::CONSUMER_TYPES[$type])) {
                $type = 'other';
            }
            $name = trim((string)($row['Name'] ?? ''));
            // Eigene Farbe je Zeile; -1 (bzw. nicht gesetzt) = Vorgabe der Art.
            $color = array_key_exists('Color', $row) ? (int)$row['Color'] : -1;
            if ($color < 0) {
                $color = self::CONSUMER_TYPES[$type]['color'];
            }
            $out[] = [
                'id'      => $vid,
                'type'    => $type,
                'name'    => ($name !== '' ? $name : self::CONSUMER_TYPES[$type]['label']),
                'icon'    => self::CONSUMER_TYPES[$type]['icon'],
                'color'   => sprintf('#%06x', $color),
                'unit'    => (string)($row['Unit'] ?? 'auto'),
                // Nur für Wallboxen relevant, sonst unbenutzt.
                'plugID'  => (int)($row['PlugID'] ?? 0),
                'plugOp'  => (string)($row['PlugOp'] ?? 'truthy'),
                'plugVal' => (string)($row['PlugVal'] ?? ''),
            ];
        }

        // Zusätzlich: Verbraucher aus den Funktionszuordnungen konfigurierter
        // MeterHub-Instanzen — dadurch entfällt das Pflegen der Liste von Hand.
        foreach ($this->MeterHubAssignments() as $a) {
            $fn = (string)($a['function'] ?? '');
            if (!isset(self::MHUB_TYPE_MAP[$fn])) {
                continue; // Kernwerte (Netz/Haus/PV/Batterie) sind keine Kreise
            }
            $vid = (int)($a['powerID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $type = self::MHUB_TYPE_MAP[$fn];
            $name = trim((string)($a['label'] ?? ''));
            $out[] = [
                'id'      => $vid,
                'type'    => $type,
                'name'    => ($name !== '' ? $name : self::CONSUMER_TYPES[$type]['label']),
                'icon'    => self::CONSUMER_TYPES[$type]['icon'],
                'color'   => sprintf('#%06x', self::CONSUMER_TYPES[$type]['color']),
                'unit'    => 'w', // MeterHub liefert Leistung immer in Watt
                // MeterHub ist ein echter Zähler — der Wert ist gemessen.
                'measured' => true,
                'plugID'  => 0,
                'plugOp'  => 'truthy',
                'plugVal' => '',
            ];
        }

        // Wärmepumpen aus HeishaMon (Vertrag HEISHA_GetFunctions ab v1.1.1).
        foreach ($this->HeishaAssignments() as $a) {
            $vid = (int)($a['PowerID'] ?? 0);
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $type = 'heatpump'; // Vertrag liefert derzeit ausschließlich diesen Typ
            $name = trim((string)($a['Caption'] ?? ''));
            $out[] = [
                'id'      => $vid,
                'type'    => $type,
                'name'    => ($name !== '' ? $name : self::CONSUMER_TYPES[$type]['label']),
                'icon'    => self::CONSUMER_TYPES[$type]['icon'],
                'color'   => sprintf('#%06x', self::CONSUMER_TYPES[$type]['color']),
                'unit'    => 'w', // „Elektrische Leistung (gesamt)" ist in W
                // false = HeishaMon-Schätzung (grob in ~200-W-Stufen), nicht
                // gemessen. Die Kachel stellt solche Werte gröber dar, statt
                // Scheingenauigkeit vorzutäuschen.
                'measured' => (bool)($a['Measured'] ?? false),
                'plugID'  => 0,
                'plugOp'  => 'truthy',
                'plugVal' => '',
            ];
        }
        return $out;
    }

    /**
     * Wärmepumpen-Zuordnungen der konfigurierten HeishaMon-Instanzen.
     * Vertrag (HeishaMon ab v1.1.1): HEISHA_GetFunctions($id) liefert eine
     * Liste aus ['Type','Caption','PowerID','EnergyID','Measured'].
     * HeishaMon ist optional — fehlt das Modul, bleibt die Liste leer.
     */
    private function HeishaAssignments(): array
    {
        $rows = json_decode($this->ReadPropertyString('HeishaMons'), true);
        if (!is_array($rows) || !function_exists('HEISHA_GetFunctions')) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $iid = (int)($row['InstanceID'] ?? 0);
            if ($iid <= 0 || !IPS_InstanceExists($iid)) {
                continue;
            }
            $list = @HEISHA_GetFunctions($iid);
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $a) {
                if (is_array($a)) {
                    $out[] = $a;
                }
            }
        }
        return $out;
    }

    /**
     * Funktionszuordnungen der konfigurierten MeterHub-Instanzen einlesen.
     * Das MeterHub-Modul ist optional — ist es nicht installiert, bleibt die
     * Liste leer und die Kachel verhält sich exakt wie bisher.
     */
    private function MeterHubAssignments(): array
    {
        $rows = json_decode($this->ReadPropertyString('MeterHubs'), true);
        if (!is_array($rows)) {
            return [];
        }
        // Es gibt zwei Anbieter desselben Vertrags: echte Zaehler (MeterHub)
        // und virtuelle (MeterHubVirtual). Welcher gemeint ist, entscheidet die
        // Modul-GUID der Instanz - so wird nicht auf gut Glueck der falsche
        // Praefix gerufen. Beide Module sind optional.
        $out = [];
        foreach ($rows as $row) {
            $iid = (int)($row['InstanceID'] ?? 0);
            if ($iid <= 0 || !IPS_InstanceExists($iid)) {
                continue;
            }
            $guid = @IPS_GetInstance($iid)['ModuleInfo']['ModuleID'] ?? '';
            $json = '';
            if ($guid === self::METERHUB_GUID && function_exists('MHUB_GetFunctions')) {
                $json = (string)@MHUB_GetFunctions($iid);
            } elseif ($guid === self::METERHUB_VIRTUAL_GUID && function_exists('MHUBV_GetFunctions')) {
                $json = (string)@MHUBV_GetFunctions($iid);
            }
            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['assignments'])) {
                continue;
            }
            foreach ($data['assignments'] as $a) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * Kernwerte aus MeterHub: der Zähler mit Funktion „Netzanschluss" liefert
     * die Netz-Leistung, der mit „Hausverbrauch" die real gemessene Hauslast.
     * Rückgabe: ['grid' => VariablenID, 'house' => VariablenID] (0 = keiner).
     */
    private function MeterHubCoreIDs(): array
    {
        $core = ['grid' => 0, 'house' => 0];
        foreach ($this->MeterHubAssignments() as $a) {
            $fn  = (string)($a['function'] ?? '');
            $vid = (int)($a['powerID'] ?? 0);
            if (isset($core[$fn]) && $core[$fn] === 0 && $vid > 0 && IPS_VariableExists($vid)) {
                $core[$fn] = $vid;
            }
        }
        return $core;
    }

    // Fahrzeug-Tabelle lesen.
    private function ReadVehicleRows()
    {
        $rows = json_decode($this->ReadPropertyString('Vehicles'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $socID = (int)($row['SocID'] ?? 0);
            if ($socID <= 0 || !IPS_VariableExists($socID)) {
                continue;
            }
            $name = trim((string)($row['Name'] ?? ''));
            $out[] = [
                'name'    => ($name !== '' ? $name : 'Fahrzeug'),
                'socID'   => $socID,
                'plugID'  => (int)($row['PlugID'] ?? 0),
                'plugOp'  => (string)($row['PlugOp'] ?? 'truthy'),
                'plugVal' => (string)($row['PlugVal'] ?? ''),
            ];
        }
        return $out;
    }

    // Ordnet Fahrzeuge den Wallboxen zu - ohne dass irgendwo ein Datenpunkt
    // "welches Auto steht hier" existieren müsste.
    //
    // Idee: Wallbox und Fahrzeug melden das Verbinden BEIDE, nur eben jedes für
    // sich. Wird ein Auto eingesteckt, wechseln daher beide Zustände praktisch
    // gleichzeitig. Als Zeitpunkt dient der von IP-Symcon ohnehin geführte
    // Zeitstempel der letzten Wertänderung ('VariableChanged'). Die Paare
    // werden nach zeitlicher Nähe sortiert und eindeutig (1:1) vergeben, sodass
    // bei zwei Autos an zwei Wallboxen jedes dort landet, wo es eingesteckt
    // wurde.
    //
    // Rückgabe: [ Index der Verbraucher-Zeile => Index des Fahrzeugs ]
    private function AssignVehicles($rows, $vehicles)
    {
        $tol = max(0, (int)$this->ReadPropertyInteger('MatchToleranceSec'));

        $wbConnected  = [];   // Zeilen-Index => Zeitpunkt des Verbindens
        $wbAllIdx     = [];
        foreach ($rows as $i => $row) {
            if ($row['type'] !== 'wallbox') {
                continue;
            }
            $wbAllIdx[] = $i;
            if ($this->CondMet($row['plugID'], $row['plugOp'], $row['plugVal']) === true) {
                $wbConnected[$i] = $this->ChangedAt($row['plugID']);
            }
        }

        $vConnected = [];
        foreach ($vehicles as $j => $v) {
            if ($this->CondMet($v['plugID'], $v['plugOp'], $v['plugVal']) === true) {
                $vConnected[$j] = $this->ChangedAt($v['plugID']);
            }
        }

        // Alle möglichen Paare innerhalb des Zeitfensters bilden und nach
        // zeitlicher Nähe aufsteigend eindeutig vergeben.
        $pairs = [];
        foreach ($wbConnected as $i => $tw) {
            foreach ($vConnected as $j => $tv) {
                $d = abs($tw - $tv);
                if ($tol > 0 && $d > $tol) {
                    continue;
                }
                $pairs[] = ['d' => $d, 'w' => $i, 'v' => $j];
            }
        }
        usort($pairs, function ($a, $b) {
            return $a['d'] <=> $b['d'];
        });

        $map   = [];
        $usedV = [];
        foreach ($pairs as $p) {
            if (isset($map[$p['w']]) || isset($usedV[$p['v']])) {
                continue;
            }
            $map[$p['w']]   = $p['v'];
            $usedV[$p['v']] = true;
        }

        // Sonderfall genau eine Wallbox / genau ein Fahrzeug: Da ist die Lage
        // auch ohne Zeitkorrelation eindeutig - hier darf die
        // Verbunden-Bedingung des Fahrzeugs also auch fehlen.
        if (count($map) === 0 && count($wbAllIdx) === 1 && count($vehicles) === 1) {
            $i = $wbAllIdx[0];
            $wbState = $this->CondMet($rows[$i]['plugID'], $rows[$i]['plugOp'], $rows[$i]['plugVal']);
            $vState  = $this->CondMet($vehicles[0]['plugID'], $vehicles[0]['plugOp'], $vehicles[0]['plugVal']);
            if ($wbState !== false && $vState !== false) {
                $map[$i] = 0;
            }
        }

        return $map;
    }

    // Wertet eine "verbunden"-Bedingung aus: Variable + Operator + Vergleichswert.
    // Nötig, weil jede Quelle das anders meldet - z. B. Boolean (Ladeklappe),
    // String (Ladekabeltyp, leer = kein Kabel) oder Integer (go-e
    // "Kabel-Leistungsfähigkeit", 0 = kein Kabel). Rückgabe null = nicht
    // konfiguriert (unbekannt), sonst true/false.
    private function CondMet($varID, $op, $val)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return null;
        }
        $v = GetValue($varID);

        switch ($op) {
            case 'eq': return $this->Equals($v, $val);
            case 'ne': return !$this->Equals($v, $val);
            case 'gt': return $this->Num($v) >  (float)$val;
            case 'ge': return $this->Num($v) >= (float)$val;
            case 'lt': return $this->Num($v) <  (float)$val;
            case 'le': return $this->Num($v) <= (float)$val;
            default:   return $this->Truthy($v);   // 'truthy'
        }
    }

    // Gleichheit: numerisch vergleichen, wenn beide Seiten Zahlen sind
    // (sonst wäre 0 != "0.0"), ansonsten als getrimmter Text ohne
    // Beachtung der Groß-/Kleinschreibung.
    private function Equals($v, $val)
    {
        if (is_bool($v)) {
            return $v === $this->Truthy($val);
        }
        if (is_numeric($v) && is_numeric($val)) {
            return ((float)$v) == ((float)$val);
        }
        return strcasecmp(trim((string)$v), trim((string)$val)) === 0;
    }

    private function Num($v)
    {
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        return is_numeric($v) ? (float)$v : 0.0;
    }

    // "Belegt/verbunden" ohne expliziten Vergleichswert: true, ungleich 0
    // bzw. nicht-leerer Text.
    private function Truthy($v)
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((float)$v) != 0.0;
        }
        $s = strtolower(trim((string)$v));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'nein');
    }

    // Zeitpunkt der letzten WERT-Änderung einer Variable. IP-Symcon führt das
    // von Haus aus mit ('VariableChanged' ändert sich nur bei echtem
    // Wertwechsel, nicht bei jeder Aktualisierung) - genau das brauchen wir
    // als "verbunden seit", ganz ohne eigenen Datenpunkt.
    private function ChangedAt($varID)
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return 0;
        }
        $info = @IPS_GetVariable($varID);
        return $info ? (int)$info['VariableChanged'] : 0;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && is_array($Data) && ($Data[0] ?? 0) === KR_READY) {
            $this->RegisterHook('/hook/ihubtile' . $this->InstanceID);
            return;
        }
        if ($Message === VM_UPDATE) {
            $this->UpdateVisualizationValue($this->BuildPayload());
        }
    }

    /**
     * Liefert die Kachel als eigenstaendige Webseite (IPSView-WebView/Browser)
     * und nimmt die Tour-Bestaetigung entgegen. Muster: NRGDashboardTile.
     */
    public function ProcessHookData()
    {
        if (isset($_GET['dismissTour'])) {
            $this->WriteAttributeBoolean('TourSeen', true);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            return;
        }
        if (isset($_GET['detail'])) {
            // Klick-Detailseite (Muster NRGDashboardTile, schlanke Fassung:
            // Leistung-Tagesverlauf + 14-Tage-Energiebalken + Variablenwerte;
            // Highlights/Unterzaehler bleiben leer, die Seite blendet die
            // Bereiche dann selbst aus).
            $key = (string)$_GET['detail'];
            $day = isset($_GET['day']) ? (string)$_GET['day'] : '';
            if (isset($_GET['json'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($this->BuildDetailPayload($key, $day));
                return;
            }
            header('Content-Type: text/html; charset=utf-8');
            $html = file_get_contents(__DIR__ . '/detail.html');
            echo str_replace('/*%%PAYLOAD%%*/', 'handleDetail(' . json_encode($this->BuildDetailPayload($key, $day)) . ');', $html);
            return;
        }
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->BuildPayload();
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->BuildPayload()) . ');'
               . 'setInterval(function(){fetch(window.location.pathname+"?json=1")'
               . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);})'
               . '.catch(function(){});},30000);</script>';
        echo $html;
    }

    /** Konsolen-Gegenstueck zur Tour-Bestaetigung (Doku-Panel-Button). */
    public function ResetTour()
    {
        $this->WriteAttributeBoolean('TourSeen', false);
        return '✅ Die Einführungs-Tour wird beim nächsten Öffnen der Kachel wieder angezeigt.';
    }

    private const YESTERDAY_CACHE_TTL_SEC = 300;

    /**
     * Wert der Variable vor genau 24h aus dem Archiv (+-15min-Fenster,
     * naechstliegender Datenpunkt) - treibt die Geisterringe der Kachel.
     * Gecacht (5min TTL), null wenn kein Archiv/keine Daten.
     */
    private function GetYesterdayValue(int $id)
    {
        if ($id <= 0 || !IPS_VariableExists($id) || !function_exists('AC_GetLoggedValues')) {
            return null;
        }
        $now = time();
        $cache = json_decode($this->ReadAttributeString('YesterdayCache'), true);
        if (!is_array($cache)) { $cache = []; }
        $entry = $cache[(string)$id] ?? null;
        if (is_array($entry) && ($now - ($entry['fetchedAt'] ?? 0)) < self::YESTERDAY_CACHE_TTL_SEC) {
            return $entry['value'] ?? null;
        }
        $arch = $this->DetailArchiveID();
        if ($arch <= 0) { return null; }
        $target = strtotime('-1 day', $now);
        $rows = @AC_GetLoggedValues($arch, $id, $target - 900, $target + 900, 0);
        $value = null;
        if (is_array($rows) && count($rows) > 0) {
            $best = null; $bestDiff = PHP_INT_MAX;
            foreach ($rows as $row) {
                $diff = abs(($row['TimeStamp'] ?? 0) - $target);
                if ($diff < $bestDiff) { $bestDiff = $diff; $best = $row; }
            }
            if ($best !== null) {
                $value = (float)($best['Avg'] ?? $best['Value'] ?? 0);
            }
        }
        $cache[(string)$id] = ['value' => $value, 'fetchedAt' => $now];
        $this->WriteAttributeString('YesterdayCache', json_encode($cache));
        return $value;
    }

    /**
     * Gesundheits-/Diagnoseanzeige der Kachel: Eintraege des Diagnostik-
     * Vertrags IHUBMON_GetDiagnostics (InverterHubMonitor), sofern eine
     * Monitor-Instanz existiert, die auf DIESELBE Quell-Instanz zeigt wie
     * diese Kachel (kein Raten bei mehreren Monitoren). Optionale Kopplung:
     * ohne Monitor bleibt die Liste leer, die Kachel blendet die
     * Diagnoseleiste dann selbst aus.
     */
    private function CollectDiagnostics()
    {
        if (!function_exists('IHUBMON_GetDiagnostics')) {
            return [];
        }
        $src = $this->ResolveSource();
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return [];
        }
        $out = [];
        foreach (IPS_GetInstanceListByModuleID(self::MONITOR_GUID) as $monID) {
            if ((int)@IPS_GetProperty($monID, 'SourceInstance') !== $src) {
                continue;
            }
            $entries = @IHUBMON_GetDiagnostics($monID);
            if (is_array($entries)) {
                foreach ($entries as $e) {
                    if (is_array($e)) { $out[] = $e; }
                }
            }
        }
        return $out;
    }

    /**
     * Aufloesung eines Kachel-detailKey zu Variablen/Metadaten - Grundlage
     * der Klick-Detailseite. Deckt beide Betriebsarten (Quell-Instanz und
     * manuelle Datenpunkte) ab; 'loss' hat keine eigene Variable.
     */
    private function DetailDevice(string $key)
    {
        $src = $this->ResolveSource();
        $useInstance = ($src > 0 && IPS_InstanceExists($src));
        $findIdent = function (array $idents) use ($src, $useInstance) {
            if (!$useInstance) { return 0; }
            foreach ($idents as $ident) {
                $vid = $this->FindIdentRecursive($src, $ident);
                if ($vid && $vid > 0) { return $vid; }
            }
            return 0;
        };
        $manual = function (string $prop) {
            $id = $this->ReadPropertyInteger($prop);
            return ($id > 0 && IPS_VariableExists($id)) ? $id : 0;
        };
        switch ($key) {
            case 'pv':
                return ['label' => 'Solar', 'function' => 'pv', 'instanceID' => $useInstance ? $src : 0,
                    'powerID' => $useInstance ? $findIdent(self::IDENT_PV) : $manual('ManualPvID')];
            case 'battery':
                return ['label' => 'Batterie', 'function' => 'battery', 'instanceID' => $useInstance ? $src : 0,
                    'powerID' => $useInstance ? $findIdent(self::IDENT_BATPWR) : $manual('ManualBatID'),
                    'socID'   => $useInstance ? $findIdent(self::IDENT_SOC) : $manual('ManualSocID')];
            case 'grid':
                return ['label' => 'Netz', 'function' => 'grid', 'instanceID' => $useInstance ? $src : 0,
                    'powerID' => $useInstance ? $findIdent(self::IDENT_GRID) : $manual('ManualGridID')];
            case 'house':
                $houseID = $this->ReadPropertyInteger('HouseLoadID');
                if ($houseID <= 0 || !IPS_VariableExists($houseID)) {
                    $houseID = $useInstance ? (int)@IPS_GetProperty($src, 'HouseLoadMeterID') : $this->ReadPropertyInteger('ManualHouseID');
                }
                return ['label' => 'Hauslast', 'function' => 'house', 'instanceID' => $useInstance ? $src : 0,
                    'powerID' => ($houseID > 0 && IPS_VariableExists($houseID)) ? $houseID : 0];
            case 'loss':
                return ['label' => 'Verluste', 'function' => 'loss', 'instanceID' => $useInstance ? $src : 0, 'powerID' => 0];
        }
        if (preg_match('/^c(\d+)$/', $key, $m)) {
            $rows = $this->ReadConsumerRows();
            $i = (int)$m[1];
            if (isset($rows[$i])) {
                return ['label' => $rows[$i]['name'], 'function' => $rows[$i]['type'],
                    'instanceID' => 0, 'powerID' => (int)$rows[$i]['id']];
            }
        }
        return null;
    }

    /** Datenpaket der Klick-Detailseite (schlankes NRGDashboardTile-Muster). */
    private function BuildDetailPayload(string $key, string $dayStr)
    {
        $d = $this->DetailDevice($key);
        if ($d === null) {
            return ['ok' => false, 'error' => 'Gerät nicht gefunden - bitte die Kachel neu öffnen.'];
        }
        // DST-sicher: Kalendertag-Grenzen ueber strtotime, nie 86400er-Arithmetik.
        $dayStart = strtotime('today');
        if ($dayStr !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStr)) {
            $parsed = strtotime($dayStr . ' 00:00:00');
            if ($parsed !== false) { $dayStart = $parsed; }
        }
        $dayEnd = min(time(), strtotime('+1 day', $dayStart));

        $source = '';
        $iid = (int)($d['instanceID'] ?? 0);
        if ($iid > 0 && IPS_InstanceExists($iid)) {
            $inst = IPS_GetInstance($iid);
            $source = IPS_GetName($iid) . ' (' . ($inst['ModuleInfo']['ModuleName'] ?? '') . ')';
        }

        $powerID = (int)($d['powerID'] ?? 0);
        $values = [];
        foreach (['powerID', 'socID'] as $f) {
            $vid = (int)($d[$f] ?? 0);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $v = IPS_GetVariable($vid);
                $values[] = ['field' => $f, 'name' => IPS_GetName($vid),
                    'value' => GetValueFormatted($vid), 'ts' => (int)$v['VariableUpdated']];
            }
        }

        return [
            'ok'         => true,
            'key'        => $key,
            'label'      => $d['label'],
            'function'   => $d['function'],
            'source'     => $source,
            'powerNow'   => ($powerID > 0) ? round($this->VarWatts($powerID, 'auto')) : null,
            'values'     => $values,
            'day'        => date('Y-m-d', $dayStart),
            'dayLabel'   => date('d.m.Y', $dayStart),
            'isToday'    => date('Y-m-d', $dayStart) === date('Y-m-d'),
            'power'      => $this->DetailDaySeries($powerID, $dayStart, $dayEnd),
            'energy'     => $this->DetailEnergyBars($powerID, $dayStart),
            'highlights' => [],
            'subMeters'  => [],
            'renderedAt' => time(),
            'bg'         => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'font'       => $this->FontStack($this->ReadPropertyString('FontFamily')),
        ];
    }

    /** 5-Minuten-Tagesverlauf aus dem Archiv ([[ms, W], ...]). */
    private function DetailDaySeries(int $vid, int $from, int $to)
    {
        $arch = $this->DetailArchiveID();
        if ($arch <= 0 || $vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($arch, $vid)) {
            return [];
        }
        $agg = @AC_GetAggregatedValues($arch, $vid, 5, $from, $to, 0);
        if (!is_array($agg)) { return []; }
        $out = [];
        foreach ($agg as $row) {
            $out[] = [((int)$row['TimeStamp']) * 1000, round((float)$row['Avg'], 1)];
        }
        usort($out, function ($a, $b) { return $a[0] <=> $b[0]; });
        return $out;
    }

    /**
     * 14-Tage-Energiebalken: aus der Leistung integriert (Tagesmittel x 24h,
     * als Naeherung gekennzeichnet) - die Kachel kennt nur Leistungsvariablen,
     * keine Zaehler. Kein rohes Zaehler-Differenzieren (IPS-Counter-Falle).
     */
    private function DetailEnergyBars(int $powerID, int $dayStart)
    {
        $arch = $this->DetailArchiveID();
        if ($arch <= 0 || $powerID <= 0 || !IPS_VariableExists($powerID) || !@AC_GetLoggingStatus($arch, $powerID)) {
            return ['bars' => [], 'unit' => '', 'approx' => false];
        }
        $from = strtotime('-13 day', $dayStart);
        $to = min(time(), strtotime('+1 day', $dayStart));
        $agg = @AC_GetAggregatedValues($arch, $powerID, 1, $from, $to, 0);
        if (!is_array($agg)) { return ['bars' => [], 'unit' => '', 'approx' => false]; }
        $bars = [];
        foreach ($agg as $row) {
            $wAvg = (float)$row['Avg'] * $this->UnitFactorFromProfile($powerID);
            $bars[] = [date('Y-m-d', (int)$row['TimeStamp']), round(($wAvg * 24.0) / 1000.0, 2)];
        }
        usort($bars, function ($a, $b) { return strcmp($a[0], $b[0]); });
        return ['bars' => $bars, 'unit' => 'kWh', 'approx' => true, 'name' => IPS_GetName($powerID)];
    }

    private function DetailArchiveID()
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 ? $ids[0] : 0;
    }

    /** WebHook beim WebHook-Control registrieren (Standard-Muster, 1:1 NRGDashboardTile). */
    private function RegisterHook(string $WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $index => $hook) {
            if ($hook['Hook'] === $WebHook) {
                if ((int)$hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        // Die Auswahlliste der Verbraucher-Arten wird aus CONSUMER_TYPES
        // erzeugt, damit es nur EINE Quelle gibt (sonst laufen form.json und
        // die Konstante bei neuen Arten auseinander).
        $this->injectConsumerTypeOptions($form);
        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        }

        // Formular-Reihenfolge (Verbund-Konvention, EMS/Dietmar 24.07.2026):
        // 1. „Was ist neu" (aufgeklappt, versionsscharf dismissible, OHNE Version)
        // 2. „Dokumentation & Hilfe" (eingeklappt, MIT Versionsnummer) - existiert
        //    schon in form.json, Versionszeile wird dort NUR eingefuegt (kein
        //    zweites Panel - form.json hatte bereits ein eigenes Doku-Panel).
        // 3. Fachpanels (form.json)
        // 4. Symcon-Forum-Hinweis (dismissible, einmalig)
        $this->InjectVersionIntoDocPanel($form);
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 InverterHubTile ist Beta — Rückmeldungen und Testberichte sind im Symcon-Forum-Thread willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::FORUM_THREAD_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'IHUBTILE_DismissReviewHint($id);'],
                ],
            ];
        }
        return json_encode($form);
    }

    // Versionszeile IMMER sichtbar, nicht nur im dismissible News-Banner
    // (Verbund-Konvention, EMS 24.07.2026). Fuegt sie als ERSTE Zeile ins
    // bereits in form.json vorhandene Doku-Panel ein, statt ein zweites,
    // doppeltes Panel zu erzeugen.
    private function InjectVersionIntoDocPanel(array &$form): void
    {
        $lib = @IPS_GetLibrary('{7EFE4BD7-DC14-460E-B0ED-88071197D35B}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ InverterHubTile Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ InverterHubTile';
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && str_contains($el['caption'] ?? '', 'Dokumentation')) {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                // Verbund-Konvention "Einfuehrungs-Tour fuer Kacheln"
                // (SUITE.md 29.08.2026): Reset-Button gehoert IMMER ins
                // Doku-Panel, mit sichtbarer Rueckmeldung (echo).
                $el['items'][] = ['type' => 'Button',
                    'caption' => 'Einführungs-Tour erneut anzeigen',
                    'onClick' => 'echo IHUBTILE_ResetTour($id);'];
                return;
            }
        }
        unset($el);
    }

    public function DismissReviewHint()
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    // Setzt die Optionen der Spalte „Art" in der Verbraucher-Liste aus
    // CONSUMER_TYPES (rekursiv, da die Liste in einem ExpansionPanel steckt).
    private function injectConsumerTypeOptions(array &$form)
    {
        $options = [];
        foreach (self::CONSUMER_TYPES as $key => $def) {
            $options[] = ['caption' => $def['label'], 'value' => $key];
        }
        $walk = function (&$items) use (&$walk, $options) {
            foreach ($items as &$it) {
                if (($it['type'] ?? '') === 'List' && ($it['name'] ?? '') === 'Consumers') {
                    foreach ($it['columns'] as &$col) {
                        if (($col['name'] ?? '') === 'Type') {
                            $col['edit'] = ['type' => 'Select', 'options' => $options];
                        }
                    }
                    unset($col);
                }
                if (isset($it['items']) && is_array($it['items'])) {
                    $walk($it['items']);
                }
            }
            unset($it);
        };
        if (isset($form['elements']) && is_array($form['elements'])) {
            $walk($form['elements']);
        }
    }

    // „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet leer),
    // bis der Nutzer „Verstanden" klickt. Neuinstallation sieht es einmalig.
    private function newsBanner()
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'IHUBTILE_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    // Idents der Quell-Instanz liegen ggf. in Unterkategorien, daher
    // rekursive Suche statt IPS_GetObjectIDByIdent (nur direkte Kinder).
    private function FindIdentRecursive(int $parentID, string $ident): int
    {
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectIdent'] === $ident) {
                return $childID;
            }
            if ($obj['ObjectType'] === 0) {
                $found = $this->FindIdentRecursive($childID, $ident);
                if ($found) {
                    return $found;
                }
            }
        }
        return 0;
    }

    public function ResetStyle()
    {
        // Vorgabe der Module-Store-Review: Eine Schaltfläche im Konfigurations-
        // formular darf nicht selbst persistieren (kein IPS_SetProperty +
        // IPS_ApplyChanges), sondern setzt nur die Felder der geöffneten Maske.
        // Bestätigt wird vom Nutzer mit „Übernehmen" — so bleibt ein Fehlklick
        // folgenlos. Aus demselben Grund hier kein ReloadForm(): das würde die
        // Maske aus den gespeicherten Eigenschaften neu aufbauen und die eben
        // gesetzten Werte wieder verwerfen.
        $this->UpdateFormField('ColorBackground', 'value', self::DEF_BACKGROUND);
        $this->UpdateFormField('FontFamily',      'value', self::DEF_FONT);
        $this->UpdateFormField('TransitionMs',    'value', self::DEF_TRANSITION);
        $this->UpdateFormField('FlowRefW',        'value', self::DEF_FLOWREF);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->BuildPayload()) . ');</script>';
        return $html;
    }

    // -----------------------------------------------------------------------
    // Payload-Aufbau
    // -----------------------------------------------------------------------

    private function BuildPayload()
    {
        $style = [
            'bg'      => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'font'    => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'transMs'  => $this->TransitionValue(),
            'flowRefW' => $this->FlowRefValue(),
        ];

        $src = $this->ResolveSource();
        $useInstance = ($src > 0 && IPS_InstanceExists($src));

        $connected    = true;
        $gridInvert   = false;
        $batInvert    = false;
        $houseMeterID = 0;
        $acIsHouseLoad = false;   // s. u. bei der Instanz-Auswertung (Victron)

        if ($useInstance) {
            $find = function (array $idents) use ($src) {
                foreach ($idents as $ident) {
                    $vid = $this->FindIdentRecursive($src, $ident);
                    if ($vid && $vid > 0) {
                        return GetValue($vid);
                    }
                }
                return null;
            };
            $conn = $find(self::IDENT_CONN);
            $connected = ($conn === null) ? true : (bool)$conn; // Treiber ohne 'connected' gelten als verbunden
            $pv   = $find(self::IDENT_PV);
            $ac   = $find(self::IDENT_AC);
            $grid = $find(self::IDENT_GRID);
            $bat  = $find(self::IDENT_BATPWR);
            $soc  = $find(self::IDENT_SOC);
            $gridInvert   = (bool)@IPS_GetProperty($src, 'MeterInvert');
            $batInvert    = (bool)@IPS_GetProperty($src, 'BatInvert');
            $houseMeterID = (int)@IPS_GetProperty($src, 'HouseLoadMeterID');
            // Semantik von ac_power ist NICHT bei allen Treibern gleich: Bei
            // String-Wechselrichtern (SMA/GoodWe/Fronius …) ist es der
            // Wechselrichter-AUSGANG, aus dem die Hauslast per Bilanz
            // (ac − Netzeinspeisung) folgt. Victron dagegen liefert im
            // System-Dienst direkt die AC-LAST des Hauses ("AC Verbrauch (Haus)")
            // - dort IST ac_power schon die Hauslast, und die Bilanz würde den
            // Netzbezug faelschlich obendrauf addieren (real gesehen: 385 W Last
            // + 639 W Netz = 1033 W statt 385 W). Deshalb den Treiber abfragen.
            $acIsHouseLoad = in_array((string)@IPS_GetProperty($src, 'Manufacturer'), ['victron'], true);
        } else {
            // Manueller Modus: einzelne Variablen direkt zuweisen (Leistungen in
            // Watt umgerechnet, SOC als Rohwert). So funktioniert die Kachel auch
            // ohne InverterHub-Instanz, z. B. mit Werten anderer Module/Zähler.
            $man = function (string $idProp, string $unitProp) {
                $id = $this->ReadPropertyInteger($idProp);
                if ($id > 0 && IPS_VariableExists($id)) {
                    return $this->VarWatts($id, $this->ReadPropertyString($unitProp));
                }
                return null;
            };
            $pv   = $man('ManualPvID',   'ManualPvUnit');
            $ac   = $man('ManualAcID',   'ManualAcUnit');
            $grid = $man('ManualGridID', 'ManualGridUnit');
            $bat  = $man('ManualBatID',  'ManualBatUnit');
            $socID = $this->ReadPropertyInteger('ManualSocID');
            $soc  = ($socID > 0 && IPS_VariableExists($socID)) ? GetValue($socID) : null;
            $gridInvert   = $this->ReadPropertyBoolean('ManualGridInvert');
            $batInvert    = $this->ReadPropertyBoolean('ManualBatInvert');
            $houseMeterID = $this->ReadPropertyInteger('ManualHouseID');

            // Kernwerte aus MeterHub ergänzen, wo hier nichts zugewiesen ist:
            // ein Zähler mit Funktion „Netzanschluss" liefert die Netzleistung,
            // einer mit „Hausverbrauch" die gemessene Hauslast. Vorzeichen:
            // MeterHub zählt + = Bezug, die Kachel + = Einspeisung — daher
            // negieren (der Invers-Schalter oben bleibt als Notausgang nutzbar).
            $mhCore = $this->MeterHubCoreIDs();
            if ($grid === null && $mhCore['grid'] > 0) {
                $grid = -$this->VarWatts($mhCore['grid'], 'w');
            }
            if ($houseMeterID <= 0 && $mhCore['house'] > 0) {
                $houseMeterID = $mhCore['house'];
            }

            if ($pv === null && $ac === null && $grid === null && $bat === null && $soc === null) {
                return json_encode(array_merge($style, [
                    'ok'          => false,
                    'devices'     => [],
                    'updatedAt'   => time(),
                    'renderedAt'  => time(),
                    'hideInactive' => (bool)$this->GetValue('HideInactive'),
                    'coupleBolt'  => (bool)$this->GetValue('CoupleBoltPower'),
                    'coupleGlow'  => (bool)$this->GetValue('CoupleGlowPower'),
                    'effectIntensity' => (int)$this->GetValue('EffectIntensity'),
                    'hookPath'    => '/hook/ihubtile' . $this->InstanceID,
                    'diagnostics' => [],
                    'gridAmpel'   => null,
                    'showTour'    => !$this->ReadAttributeBoolean('TourSeen'),
                ]));
            }
        }

        // Direkt an der Kachel gewählter, echter Hausverbrauchs-Zähler hat
        // Vorrang - unabhängig von Quell-/Manuell-Modus. So lässt sich statt der
        // rechnerischen Bilanz der gemessene Wert in der Mitte anzeigen.
        $tileHouseID = $this->ReadPropertyInteger('HouseLoadID');
        if ($tileHouseID > 0 && IPS_VariableExists($tileHouseID)) {
            $houseMeterID = $tileHouseID;
        }

        $pvHave   = ($pv !== null);
        $gridHave = ($grid !== null);
        $batHave  = ($bat !== null);
        $socHave  = ($soc !== null);

        $pvW   = $pvHave ? (float)$pv : 0.0;
        $gridW = $gridHave ? (float)$grid : 0.0;
        $batW  = $batHave ? (float)$bat : 0.0;
        // Ist die Batterie-Leistung in der gewählten Konvention invertiert,
        // rechnet die Kachel intern wieder auf ihre kanonische Konvention
        // zurück (+ = Entladen), damit die Flussrichtung stimmt.
        if ($batHave && $batInvert) {
            $batW = -$batW;
        }
        // Analog fürs Netz (kanonisch + = Einspeisung).
        if ($gridHave && $gridInvert) {
            $gridW = -$gridW;
        }

        // Last (Hausverbrauch) per Bilanz. Bevorzugt über die AC-Wirkleistung:
        //   Hauslast = AC-Leistung − Netzeinspeisung   (gridW: + = Einspeisung)
        // Die AC-Wirkleistung ist bereits das, was der Wechselrichter NACH der
        // Batterie ans Hausnetz abgibt - dadurch braucht diese Bilanz keine
        // Batteriedaten und ist auch dann korrekt, wenn die Batterie gerade
        // lädt (z. B. PV 8 kW, Ladung 7 kW -> AC 1 kW -> Hauslast 1 kW). Die
        // frühere PV-basierte Formel überschätzte die Last um die Ladeleistung,
        // sobald die Batteriedaten fehlten oder die Batterie-Gruppe aus war.
        //
        // Rückfall auf die DC-Bilanz (PV + Batterie-Entladung − Einspeisung)
        // nur, wenn keine AC-Wirkleistung vorliegt. Vorzeichen Batterie:
        // positiv = Entladung, negativ = Ladung.
        $houseHave = false;
        $houseBalanceW = 0.0;
        if ($ac !== null && $acIsHouseLoad) {
            // Victron: ac_power IST bereits die Hauslast, keine Bilanz noetig.
            $houseHave     = true;
            $houseBalanceW = max(0.0, (float)$ac);
        } elseif ($ac !== null && $gridHave) {
            $houseHave     = true;
            $houseBalanceW = max(0.0, (float)$ac - $gridW);
        } elseif ($pvHave && $gridHave) {
            $houseHave     = true;
            $houseBalanceW = max(0.0, $pvW - $gridW + $batW);
        } elseif ($ac !== null) {
            $houseHave     = true;
            $houseBalanceW = max(0.0, (float)$ac);
        }
        $houseW = $houseBalanceW;

        // Optionaler externer Hauslastzähler (z. B. Shelly am Hausanschluss):
        // liefert die tatsächlich gemessene Last (genauer als die Bilanz) und
        // erlaubt, die Differenz als "Wandlungsverluste" auszuweisen
        // (Wechselrichter-Eigenverbrauch, Leitungsverluste, Messtoleranzen).
        $lossHave = false;
        $lossW    = 0.0;
        $meterID  = $houseMeterID;
        if ($meterID > 0 && IPS_VariableExists($meterID)) {
            $realHouseW = $this->VarWatts($meterID, 'auto');
            // Ein Hausverbrauch ist nie negativ. Liefert die gewählte Variable
            // einen negativen Wert, ist es kein Hausverbrauchszähler, sondern
            // z. B. ein Netz-/Einspeisezähler (negativ = Einspeisung) - dann
            // ignorieren wir sie und bleiben bei der berechneten Bilanz-Hauslast
            // (sonst erschiene eine negative Hauslast und absurde "Verluste").
            if ($realHouseW >= 0.0) {
                $houseHave  = true;
                $houseW     = $realHouseW;
                if ($pvHave && $gridHave) {
                    $lossHave = true;
                    $lossW    = max(0.0, $houseBalanceW - $realHouseW);
                }
            }
        }

        // Berechnete Hauslast optional in die eigene Variable schreiben, damit
        // sie außerhalb der Kachel (Automationen, Charts) nutzbar ist.
        if ($houseHave && $this->ReadPropertyBoolean('WriteHouseLoad')) {
            $vid = @$this->GetIDForIdent('house_load');
            if ($vid) {
                $this->SetValue('house_load', round($houseW));
            }
        }

        // -------------------------------------------------------------------
        // Adapter auf das Geraete-Schema der neuen Darstellungsschicht
        // (module.html von NRGDashboardTile uebernommen, 29.08.2026, Schema
        // laut Dashboard-Uebergabe): devices[] mit function/label/value/...
        // statt des alten flachen pvHave/pvW-Payloads. Alle "NEU, optional"
        // markierten Felder liefern wir bewusst als null/weg - module.html
        // blendet die zugehoerigen Zusatzfeatures dann aus (1:1-Paritaet,
        // Zusatzfeatures spaeter schrittweise). Konfigurationsmodell,
        // Properties und alle Berechnungen oben bleiben unveraendert.
        // -------------------------------------------------------------------
        $srcID = $useInstance ? $src : 0;
        $devices = [];
        if ($pvHave) {
            $devices[] = ['function' => 'pv', 'label' => 'Solar',
                'value' => round($pvW), 'soc' => null, 'measured' => true,
                'detailKey' => 'pv', 'instanceID' => $srcID, 'sub' => '', 'plugged' => null];
        }
        if ($batHave || $socHave) {
            $devices[] = ['function' => 'battery', 'label' => 'Batterie',
                'value' => $batHave ? round($batW) : 0,
                'soc' => $socHave ? round((float)$soc) : null, 'measured' => true,
                'detailKey' => 'battery', 'instanceID' => $srcID, 'sub' => '', 'plugged' => null];
        }
        if ($gridHave) {
            $devices[] = ['function' => 'grid', 'label' => 'Netz',
                'value' => round($gridW), 'soc' => null, 'measured' => true,
                'detailKey' => 'grid', 'instanceID' => $srcID, 'sub' => '', 'plugged' => null];
        }
        if ($houseHave) {
            $devices[] = ['function' => 'house', 'label' => 'Hauslast',
                'value' => round($houseW), 'soc' => null,
                'measured' => ($meterID > 0 && IPS_VariableExists($meterID)),
                'detailKey' => 'house', 'instanceID' => $srcID, 'sub' => '', 'plugged' => null];
        }
        if ($lossHave && round($lossW) > 0) {
            // 'loss' kennt FUNCTION_STYLE nicht -> DEFAULT_STYLE (grau,
            // "other"-Icon) - bewusst akzeptiert, der alte Verluste-Kreis war
            // ebenfalls grau.
            $devices[] = ['function' => 'loss', 'label' => 'Verluste',
                'value' => round($lossW), 'soc' => null, 'measured' => false,
                'detailKey' => 'loss', 'instanceID' => $srcID, 'sub' => '', 'plugged' => null];
        }
        foreach ($this->BuildConsumers() as $c) {
            $devices[] = [
                'function'   => $c['type'],
                'label'      => $c['label'],
                'value'      => $c['w'],
                'soc'        => $c['soc'] ?? null,
                'measured'   => $c['measured'],
                'detailKey'  => $c['key'],
                'instanceID' => $srcID,
                'sub'        => $c['sub'] ?? '',
                'plugged'    => $c['plugged'] ?? null,
            ];
        }

        // Geisterringe: gestriger Vergleichswert je Knoten (gleiche
        // Variablen wie die Live-Werte, ueber DetailDevice() aufgeloest;
        // Einheiten-/Vorzeichen-Kanonisierung wie beim Live-Wert). null =
        // kein Archiv/keine Daten - die Kachel laesst den Ring dann weg.
        foreach ($devices as &$dev) {
            $dd = $this->DetailDevice($dev['detailKey']);
            $vid = (int)($dd['powerID'] ?? 0);
            if ($vid > 0) {
                $yv = $this->GetYesterdayValue($vid);
                if ($yv !== null) {
                    $w = $yv * $this->UnitFactorFromProfile($vid);
                    if (!$useInstance) {
                        if ($dev['function'] === 'grid' && $this->ReadPropertyBoolean('ManualGridInvert')) { $w = -$w; }
                        if ($dev['function'] === 'battery' && $this->ReadPropertyBoolean('ManualBatInvert')) { $w = -$w; }
                    }
                    $dev['yesterdayValue'] = round($w);
                }
            }
        }
        unset($dev);

        $payload = array_merge($style, [
            'ok'              => $connected,
            'devices'         => $devices,
            'updatedAt'       => time(),
            'renderedAt'      => time(),
            'hideInactive'    => (bool)$this->GetValue('HideInactive'),
            'coupleBolt'      => (bool)$this->GetValue('CoupleBoltPower'),
            'coupleGlow'      => (bool)$this->GetValue('CoupleGlowPower'),
            'effectIntensity' => (int)$this->GetValue('EffectIntensity'),
            'hookPath'        => '/hook/ihubtile' . $this->InstanceID,
            'diagnostics'     => $this->CollectDiagnostics(),
            'gridAmpel'       => null,
            'showTour'        => !$this->ReadAttributeBoolean('TourSeen'),
        ]);

        return json_encode($payload);
    }

    // Zusätzliche Verbraucher als Liste für die Kachel. Die Kachel verteilt
    // alle vorhandenen Knoten selbst radial - die Anzahl ist daher frei.
    private function BuildConsumers()
    {
        $rows     = $this->ReadConsumerRows();
        $vehicles = $this->ReadVehicleRows();
        $assign   = $this->AssignVehicles($rows, $vehicles);

        $out = [];
        foreach ($rows as $i => $row) {
            $entry = [
                'key'   => 'c' . $i,
                'type'  => $row['type'],
                'label' => $row['name'],
                'icon'  => $row['icon'],
                'color' => $row['color'],
                'w'     => round($this->VarWatts($row['id'], $row['unit'])),
                // false = der Wert ist geschätzt, nicht gemessen (derzeit nur
                // HeishaMon ohne externen Zähler, Raster ~200 W). Die Anzeige
                // soll ihn dann gröber darstellen statt Scheingenauigkeit zu
                // suggerieren. Manuell zugewiesene Variablen gelten als gemessen.
                'measured' => (bool)($row['measured'] ?? true),
            ];

            // Wallboxen: Auto-Symbol mit dem Ladestand des Fahrzeugs, das
            // gerade an DIESER Wallbox steht (automatisch ermittelt). Der Name
            // des erkannten Fahrzeugs wird als Zusatzzeile angezeigt, damit die
            // Zuordnung nachvollziehbar bleibt.
            if ($row['type'] === 'wallbox') {
                if (isset($assign[$i])) {
                    $v = $vehicles[$assign[$i]];
                    $entry['socHave'] = true;
                    $entry['soc']     = round((float)GetValue($v['socID']));
                    $entry['sub']     = $v['name'];
                } else {
                    $entry['socHave'] = false;
                    $entry['soc']     = null;
                }
                // Ist die Verbunden-Bedingung der Wallbox erfüllt, gilt sie als
                // "eingesteckt" und wird auch bei 0 W farbig (nicht ausgegraut)
                // dargestellt - "angesteckt, lädt gerade nicht" ist ein echter
                // Zustand, kein inaktiver Knoten. Ohne konfigurierte Bedingung
                // (null) bleibt es beim rein leistungsabhängigen Verhalten.
                $entry['plugged'] = ($this->CondMet($row['plugID'], $row['plugOp'], $row['plugVal']) === true);
            }

            $out[] = $entry;
        }
        return $out;
    }

    // Liest eine Leistungs-Variable und rechnet sie einheitlich in Watt um.
    // $unit: 'w' | 'kw' | 'mw' erzwingt die Einheit, 'auto' (Vorgabe) errät sie
    // aus dem Profil-Suffix der Variable. So werden Fremdquellen, die z. B.
    // Kilowatt liefern (viele Wallboxen), korrekt behandelt - intern rechnet
    // die Kachel durchgängig in Watt.
    private function VarWatts($vid, $unit = 'auto')
    {
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return 0.0;
        }
        $v = (float)GetValue($vid);
        switch (strtolower(trim((string)$unit))) {
            case 'kw': return $v * 1000.0;
            case 'mw': return $v * 1000000.0;
            case 'w':  return $v;
            default:   return $v * $this->UnitFactorFromProfile($vid);
        }
    }

    // Einheiten-Faktor (auf Watt) aus dem Profil-Suffix einer Variable: " kW"
    // -> 1000, " MW" -> 1e6, sonst 1 (W oder unbekannt). Berücksichtigt ein
    // etwaiges eigenes Profil (VariableCustomProfile) vorrangig.
    private function UnitFactorFromProfile($vid)
    {
        $var = @IPS_GetVariable($vid);
        if (!is_array($var)) {
            return 1.0;
        }
        $prof = ($var['VariableCustomProfile'] ?? '') !== ''
            ? $var['VariableCustomProfile']
            : ($var['VariableProfile'] ?? '');
        if ($prof === '') {
            return 1.0;
        }
        $p = @IPS_GetVariableProfile($prof);
        if (!is_array($p)) {
            return 1.0;
        }
        $suffix = strtolower(trim((string)($p['Suffix'] ?? '')));
        if (strpos($suffix, 'kw') !== false) {
            return 1000.0;
        }
        if (strpos($suffix, 'mw') !== false) {
            return 1000000.0;
        }
        return 1.0;
    }

    // -----------------------------------------------------------------------
    // Hilfsfunktionen (identisch mit GoodweETTile)
    // -----------------------------------------------------------------------

    private function ResolveSource()
    {
        return (int)$this->ReadPropertyInteger('SourceInstance');
    }

    private function ColorOrEmpty(int $color)
    {
        return $color < 0 ? '' : sprintf('#%06x', $color);
    }

    private function FontStack(string $family)
    {
        if ($family === 'system' || $family === '') {
            return '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        }
        return $family;
    }

    private function FlowRefValue()
    {
        $v = (int)$this->ReadPropertyInteger('FlowRefW');
        return ($v >= 500 && $v <= 100000) ? $v : self::DEF_FLOWREF;
    }

    private function TransitionValue()
    {
        $v = (int)$this->ReadPropertyInteger('TransitionMs');
        return ($v >= 0 && $v <= 5000) ? $v : self::DEF_TRANSITION;
    }
}
