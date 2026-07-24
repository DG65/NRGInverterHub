<?php

// ---------------------------------------------------------------------------
// InverterHubMonitor — Monitoring-Kachel mit archivierten Werten aus einer
// InverterHub-Instanz (à la Meteocontrol VCOM). Die Werte werden NICHT mehr
// von Hand in einer Tabelle gepflegt, sondern:
//   1. Man wählt die InverterHub-Instanz als Quelle.
//   2. Man kreuzt die gewünschten Werte an (nur vorhandene/archivierte Idents
//      werden angeboten).
//   3. Farben, Achse und Einheit sind je Wert voreingestellt.
// Ansichten (in der Kachel umschaltbar): „Tag (Verlauf)" = Leistungs-Zeitreihe
// (~5-Min), „Monat/Jahr (Energie)" = Energie-Balken. Energie kommt aus dem
// Zuwachs des Archiv-Zählers (Max−Min je Tages-Bucket) — funktioniert für
// Lifetime- und Tagesreset-Zähler; für reine Leistungswerte wird integriert.
// Rendering wahlweise Highcharts oder ECharts.
//
// Architektur-Hinweis: Die Serien werden über einen zentralen Katalog
// (self::CATALOG) aufgelöst. Ein späterer Ausbau auf seitliche Reiter
// (verschiedene Diagramme) kann darauf aufsetzen, indem CATALOG-Einträge
// über ein Feld „group"/„tab" gebündelt und mehrere seriesMeta/types-Sätze
// (ein „diagrams"-Array) an die Kachel geliefert werden.
// ---------------------------------------------------------------------------

class InverterHubMonitor extends IPSModule
{
    private const ARCHIVE_GUID   = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const INVERTERHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const PVF_GUID        = '{257DD4E8-9705-462E-89FC-56D0A1038353}'; // PV-Prognose
    private const TIBBER_GUID     = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}'; // Tibber Grid Reward (Preisquelle)
    private const METERHUB_GUID   = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}'; // MeterHub (echte Zähler)
    private const METERHUBV_GUID  = '{ADF18291-2E60-4354-92F5-B96863C127C8}'; // MeterHub Virtuelle Zähler
    private const PRICE_COLOR     = '#ffb300';
    private const WINDOW_DAYS   = 8;    // navigierbares Tages-Fenster (Verlauf)
    private const WINDOW_WEEKS  = 26;   // Wochen-Fenster (Energie-Balken)
    private const WINDOW_MONTHS = 12;   // Monats-Fenster (Energie-Balken)
    private const WINDOW_YEARS  = 5;    // Jahres-Fenster (Energie-Balken)
    private const SPAN_YEARS    = 5;    // max. Tiefe für „Gesamt"/Benutzerdefiniert
    private const AGG_5MIN = 5;         // IP-Symcon-Aggregationsstufe 5-Minuten
    private const AGG_DAY  = 1;         // täglich

    // Wert-Katalog: key => Definition. „power"/„energy" sind Kandidaten-Idents
    // (erster in der Quelle vorhandener gewinnt). „power" speist die
    // Verlaufs-Linie (Tag), „energy" die Energie-Balken (Monat/Jahr, Max−Min).
    // Fehlt „energy", wird für die Energie-Balken aus der Leistung integriert.
    // „noEnergy" => keine sinnvolle Energie (SOC/Temperatur) → nur Tages-Linie.
    // „groups" => in welchen seitlichen Reitern der Wert erscheint (ein Wert
    //   kann in mehreren Reitern auftauchen, z. B. PV in „Leistung" und „PV").
    private const CATALOG = [
        'pv'      => ['label' => 'PV-Erzeugung',       'power' => ['pv_total'],                 'energy' => ['e_pv_total'],                       'color' => '#e53935', 'axis' => 'left',  'unit' => 'W',    'default' => true,  'groups' => ['energy', 'solar']],
        'load'    => ['label' => 'Verbrauch',          'power' => [],                            'energy' => ['e_load_total', 'e_load_day'],        'color' => '#f0883e', 'axis' => 'left',  'unit' => 'W',    'default' => true,  'groups' => ['energy']],
        'gridbuy' => ['label' => 'Netzbezug',          'power' => [],                            'energy' => ['e_buy_total', 'e_buy_day'],          'color' => '#4aa3e0', 'axis' => 'left',  'unit' => 'W',    'default' => true,  'groups' => ['energy']],
        'gridsell'=> ['label' => 'Einspeisung',        'power' => [],                            'energy' => ['e_sell_total', 'e_sell_day'],        'color' => '#26a69a', 'axis' => 'left',  'unit' => 'W',    'default' => true,  'groups' => ['energy']],
        'grid'    => ['label' => 'Netzleistung',       'power' => ['meter_total'],               'energy' => [],                                    'color' => '#7e9fb5', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['energy']],
        'bcharge' => ['label' => 'Batterie laden',     'power' => [],                            'energy' => ['e_charge_total', 'e_charge_day'],    'color' => '#5fcb6b', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['energy', 'battery']],
        'bdisch'  => ['label' => 'Batterie entladen',  'power' => [],                            'energy' => ['e_disch_total', 'e_disch_day'],      'color' => '#2e7d32', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['energy', 'battery']],
        'bat'     => ['label' => 'Batterie-Leistung',  'power' => ['bat_total_pwr', 'bat_power'], 'energy' => [],                                   'color' => '#43a047', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['battery']],
        'ac'      => ['label' => 'AC-Wirkleistung',    'power' => ['ac_power'],                  'energy' => [],                                    'color' => '#ab47bc', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['energy']],
        'inv'     => ['label' => 'Inverter gesamt',    'power' => ['inv_total'],                 'energy' => [],                                    'color' => '#c2185b', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['energy']],
        'mppt1'   => ['label' => 'MPPT 1',             'power' => ['mppt1_power'],               'energy' => [],                                    'color' => '#e53935', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['mpp']],
        'mppt2'   => ['label' => 'MPPT 2',             'power' => ['mppt2_power'],               'energy' => [],                                    'color' => '#43a047', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['mpp']],
        'mppt3'   => ['label' => 'MPPT 3',             'power' => ['mppt3_power'],               'energy' => [],                                    'color' => '#1e88e5', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['mpp']],
        'mppt4'   => ['label' => 'MPPT 4',             'power' => ['mppt4_power'],               'energy' => [],                                    'color' => '#fb8c00', 'axis' => 'left',  'unit' => 'W',    'default' => false, 'groups' => ['mpp']],
        'soc'     => ['label' => 'Batterie-SOC',       'power' => ['soc', 'bat_soc'],            'energy' => [],                'noEnergy' => true, 'color' => '#9575cd', 'axis' => 'right', 'unit' => '%',    'default' => false, 'groups' => ['battery']],
        'temp'    => ['label' => 'Modultemperatur',    'power' => ['temp_module', 'temp_cab'],   'energy' => [],                'noEnergy' => true, 'color' => '#78909c', 'axis' => 'left',  'unit' => '°C',   'default' => false, 'groups' => ['diag']],
        'riso'    => ['label' => 'Isolationswiderstand','power' => ['riso'],                     'energy' => [],                'noEnergy' => true, 'color' => '#8d6e63', 'axis' => 'right', 'unit' => 'kΩ',   'default' => false, 'groups' => ['diag']],
    ];

    // Seitliche Reiter (Reihenfolge = Anzeige). Ein Reiter erscheint nur, wenn
    // mindestens einer seiner Werte angekreuzt/vorhanden ist.
    private const TABS = [
        'solar'   => 'PV & Einstrahlung',
        'mpp'     => 'MPP-Tracker',
        'battery' => 'Batterie',
    ];

    // Der Reiter „Strompreis" ist ein FOKUS-Reiter wie „PV & Einstrahlung": Er
    // erscheint nur, wenn es auch eine Preisquelle gibt - sonst stünde dort ein
    // Reiter ohne den Wert, um den es geht. Deshalb ist die Reiterliste eine
    // Methode und keine reine Konstante.
    // (Die Reiter „Leistung & Energie" und „Diagnose" wurden in 0.45.1 bewusst
    // entfernt; Werte ohne Reiter werden in Formular UND ResolveSeries
    // gefiltert. Diese Entscheidung wird hier NICHT rückgängig gemacht.)
    private function TabList(): array
    {
        $tabs = self::TABS;
        if ($this->ReadPropertyBoolean('show_price') && $this->PriceVarID() > 0) {
            $tabs['price'] = 'Strompreis';
        }
        return $tabs;
    }

    private const IRR_COLOR = '#ffc21a'; // sonnengelb

    // „Was ist neu"-Banner: NEWS_VERSION hochzählen, wenn nach einem Update ein
    // Hinweis erscheinen soll. Das Attribut startet leer, damit das Banner auch
    // bei bestehenden Instanzen nach dem Update erscheint; nach „Verstanden"
    // (oder solange die Version passt) bleibt es aus.
    private const NEWS_VERSION = '0.72';
    private const NEWS_ITEMS = [
        'Neuer Reiter „Strompreis": Netzbezug als Balken neben der Preiskurve (bei installiertem Preismodul) — mit Knopf zum Einschalten der Archivierung.',
        'Schnellwahl „Vorgestern/Gestern/Heute" in der Tagesansicht.',
        'PV-Prognose-Instanz wird bei mehreren Installationen nicht mehr stillschweigend geraten — explizite Auswahl statt automatisch der ersten.',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterPropertyInteger('SourceInstance', 0);
        foreach (self::CATALOG as $key => $def) {
            $this->RegisterPropertyBoolean('show_' . $key, !empty($def['default']));
        }
        // Externer Einstrahlungssensor (W/m²), nicht Teil der InverterHub-Instanz.
        $this->RegisterPropertyInteger('IrradianceID', 0);
        $this->RegisterPropertyBoolean('show_irr', true);
        // Batterie-Leistung invertieren (Vorzeichen laden/entladen je nach Anlage).
        $this->RegisterPropertyBoolean('BatInvert', false);
        // Strompreis-Kurve (optionale Kopplung an ein Preisquellen-Modul,
        // derzeit Tibber Grid Rewards). 0 = aus.
        $this->RegisterPropertyInteger('PriceInstance', 0);
        $this->RegisterPropertyBoolean('show_price', true);
        // PV-Prognose-Instanz für die Erwartungswerte. 0 = automatisch (nur bei
        // genau einer). Bei mehreren wird NICHT geraten (Prognose-Empfehlung).
        $this->RegisterPropertyInteger('PvfInstance', 0);

        $this->RegisterPropertyString('Engine', 'echarts');
        $this->RegisterPropertyInteger('ColorBackground', -1);
        $this->RegisterPropertyString('FontFamily', '');

        $this->RegisterTimer('Refresh', 0, 'IHUBMON_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        $src = $this->ReadPropertyInteger('SourceInstance');
        if ($src > 0 && IPS_InstanceExists($src)) {
            $this->RegisterReference($src);
        }
        $irr = $this->ReadPropertyInteger('IrradianceID');
        if ($irr > 0 && IPS_VariableExists($irr)) {
            $this->RegisterReference($irr);
        }

        $series = $this->ResolveSeries();
        foreach ($series as $s) {
            foreach (['powerVid', 'energyVid'] as $k) {
                if ($s[$k] > 0) {
                    $this->RegisterReference($s[$k]);
                }
            }
        }
        $ok = count($series) > 0;
        $this->SetStatus($src <= 0 ? 202 : ($ok ? 102 : 201));
        $this->SetTimerInterval('Refresh', $ok ? 120000 : 0);

        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    public function Refresh()
    {
        $this->UpdateVisualizationValue($this->BuildPayload());
    }

    // Button „Verstanden" im „Was ist neu"-Banner: merkt die gesehene Version
    // und blendet das Banner sofort aus.
    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    // Schaltet die Archivierung der Preisvariable ein. Sie gehört einem FREMDEN
    // Modul - deshalb passiert das ausdrücklich NUR auf Knopfdruck des Nutzers
    // und niemals automatisch: Ungefragt eine Variable in die Datenbank zu
    // schreiben, ist die Entscheidung des Anlagenbetreibers, nicht unsere.
    // Das Häkchen sitzt in IP-Symcon an der Variable selbst (nicht im Formular
    // des Preismoduls), weshalb es dort erfahrungsgemäß niemand findet.
    public function EnablePriceLogging()
    {
        $pvid = $this->PriceVarID();
        $aid  = $this->ArchiveID();
        if ($pvid <= 0 || $aid <= 0) {
            $this->UpdateFormField('PriceLogHint', 'caption', '⚠️ Preisvariable oder Archiv nicht gefunden.');
            return;
        }
        if (@AC_GetLoggingStatus($aid, $pvid)) {
            $this->UpdateFormField('PriceLogHint', 'caption', '✅ Rückblick bereit: Die Preisvariable wird bereits archiviert.');
            return;
        }
        // Aggregationstyp ausdrücklich auf 0 (Standard/Mittelwert) setzen, NICHT
        // Zähler: Ein Preis ist ein Momentanwert, kein aufsummierter Verbrauch.
        // Ohne diese Zeile bliebe eine Variable, die früher einmal als Zähler
        // archiviert wurde, auch weiterhin Zähler - und die Preiskurve wäre
        // Unsinn. Der Wert ist mit der Preisquelle abgestimmt, damit es egal
        // bleibt, über welchen Weg die Archivierung eingeschaltet wurde.
        @AC_SetAggregationType($aid, $pvid, 0);
        @AC_SetLoggingStatus($aid, $pvid, true);
        @IPS_ApplyChanges($aid);
        $ok = @AC_GetLoggingStatus($aid, $pvid);
        $this->UpdateFormField('PriceLogHint', 'caption', $ok
            ? '✅ Archivierung eingeschaltet. Ab jetzt wächst der Rückblick mit — rückwirkend gibt es keine Daten, die Kurve füllt sich also erst nach und nach.'
            : '⚠️ Einschalten fehlgeschlagen. Bitte an der Variable „Aktueller Preis" von Hand aktivieren (Rechtsklick → Archivierung).');
        $this->UpdateFormField('PriceLogButton', 'visible', !$ok);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $html .= '<script>handleMessage(' . json_encode($this->BuildPayload()) . ');</script>';
        return $html;
    }

    // -----------------------------------------------------------------------
    // Dynamisches Formular: Instanz wählen → nur vorhandene Werte ankreuzen.
    // -----------------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $src = $this->ReadPropertyInteger('SourceInstance');
        $elements = [];

        // „Was ist neu"-Banner nach einem Update (nicht bei Neuinstallation).
        if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION) {
            $newsItems = [
                ['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und die Einstellungen unten prüfen:'],
            ];
            foreach (self::NEWS_ITEMS as $line) {
                $newsItems[] = ['type' => 'Label', 'caption' => '• ' . $line];
            }
            $newsItems[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'IHUBMON_AckNews($id);'];
            $elements[] = ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $newsItems];
        }

        $elements[] = [
            'type' => 'ExpansionPanel', 'caption' => '📖 Dokumentation & Hilfe', 'expanded' => false,
            'items' => [
                $this->VersionLabel(),
                ['type' => 'Label', 'caption' => '1. InverterHub-Instanz als Quelle wählen und „Änderungen übernehmen". 2. Danach erscheinen unten die vorhandenen, archivierten Werte zum Ankreuzen. Farben, Achse und Einheit sind je Wert voreingestellt.'],
                ['type' => 'Label', 'caption' => 'Ansichten in der Kachel: „Tag (Verlauf)" zeigt die Leistungs-Zeitreihe (~5-Min). „Monat/Jahr (Energie)" zeigen Energie-Balken aus dem Zähler-Zuwachs (Energiewerte wie „PV Gesamt", „Bezug", „Einspeisung") bzw. — bei reinen Leistungswerten — integriert.'],
                ['type' => 'Label', 'caption' => 'Die Werte sind in der Kachel auf seitliche Reiter gruppiert (PV & Einstrahlung / MPP-Tracker / Batterie), damit unterschiedliche Einheiten (kW, %, W/m²) nicht auf einer Achse kollidieren.'],
                ['type' => 'Label', 'caption' => 'Verschmutzung/Defekt erkennen: PV-Erzeugung (links) + Einstrahlungssensor W/m² (rechts) ankreuzen. An sauberen Tagen laufen beide proportional; fällt die Leistung relativ ab → Reinigung/Defekt prüfen.'],
            ],
        ];

        $elements[] = [
            'type' => 'ExpansionPanel', 'caption' => 'Quelle', 'expanded' => true,
            'items' => [
                ['type' => 'SelectInstance', 'name' => 'SourceInstance', 'caption' => 'InverterHub-Instanz'],
            ],
        ];

        // Werte-Panel: nur Idents, die in der Quelle existieren und geloggt sind.
        $valueItems = [];
        if ($src > 0 && IPS_InstanceExists($src)) {
            $aid = $this->ArchiveID();
            $valueItems[] = ['type' => 'Label', 'caption' => 'Gewünschte Werte ankreuzen (Farben sind voreingestellt):'];
            $anyOffered = false;
            foreach (self::CATALOG as $key => $def) {
                // Nur Werte anbieten, deren Gruppe noch einen aktiven Reiter hat.
                if (count(array_intersect($def['groups'], array_keys($this->TabList()))) === 0) {
                    continue;
                }
                $vid = $this->FirstIdent($src, array_merge($def['power'], $def['energy']));
                if ($vid <= 0) {
                    continue;
                }
                $logged = ($aid > 0 && @AC_GetLoggingStatus($aid, $vid));
                $note = $logged ? '' : '  (⚠ nicht archiviert)';
                $axis = ($def['axis'] === 'right') ? 'rechts' : 'links';
                $valueItems[] = [
                    'type' => 'CheckBox', 'name' => 'show_' . $key,
                    'caption' => $def['label'] . '  —  ' . $axis . ', ' . $def['unit'] . $note,
                ];
                $anyOffered = true;
            }
            if (!$anyOffered) {
                $valueItems[] = ['type' => 'Label', 'caption' => '⚠ In dieser Instanz wurden keine bekannten Werte gefunden. Ist es wirklich eine InverterHub-Instanz?'];
            }
            $valueItems[] = ['type' => 'CheckBox', 'name' => 'BatInvert', 'caption' => 'Batterie-Leistung invertieren (falls Laden/Entladen mit falschem Vorzeichen erscheint)'];
            $valueItems[] = ['type' => 'Label', 'caption' => '— Einstrahlungssensor (optional, externe W/m²-Variable) —'];
            $valueItems[] = ['type' => 'CheckBox', 'name' => 'show_irr', 'caption' => 'Einstrahlung anzeigen (rechte Achse)'];
            $valueItems[] = ['type' => 'SelectVariable', 'name' => 'IrradianceID', 'caption' => 'Einstrahlungs-Variable (W/m²)'];

            // Auswahl der PV-Prognose-Instanz nur anbieten, wenn es MEHRERE gibt
            // — bei genau einer wird sie automatisch genommen (kein Feld nötig).
            $pvfList = @IPS_GetInstanceListByModuleID(self::PVF_GUID) ?: [];
            if (count($pvfList) > 1) {
                $valueItems[] = ['type' => 'SelectInstance', 'name' => 'PvfInstance', 'caption' => 'PV-Prognose-Instanz (bei mehreren bitte wählen)'];
                if ($this->PvfInstanceID() <= 0) {
                    $valueItems[] = ['type' => 'Label', 'caption' => '⚠️ Es sind mehrere PV-Prognose-Instanzen vorhanden. Bitte oben eine auswählen — sonst werden keine Erwartungswerte berechnet (es wird bewusst nicht geraten).'];
                }
            }

            // Hinweis aufs Prognose-Modul, sobald ein Einstrahlungssensor gewählt
            // ist: dessen Modulfläche macht aus der reinen Kurven-Ansicht später
            // eine quantitative Auswertung (spez. Leistung / Performance-Ratio).
            if ($this->ReadPropertyInteger('IrradianceID') > 0) {
                $pvf = $this->PvfArea();
                if (!$pvf['installed']) {
                    $valueItems[] = ['type' => 'Label', 'caption' => 'ℹ Tipp: Mit dem Prognose-Modul „PV-Prognose" (Suite EnergiePrognose, DG65/Prognose) lässt sich der Einstrahlungssensor voll nutzen. Ist es installiert, berechnet der Monitor aus dessen Generatorparametern (kWp, Performance-Ratio) Erwartungswerte und stellt sie dem gemessenen Ertrag gegenüber (gestrichelt) — für Verschmutzungs- und Defekterkennung. Dafür genügt bereits die Stable-Version. Trägst du dort zusätzlich je Generator Modulanzahl sowie Modullänge/-breite ein (Felder ab Version 0.20 / Build 41), kommt die spezifische Leistung (W/m²) hinzu. Das Modul ist derzeit nicht installiert.'];
                } elseif ($pvf['area'] <= 0.0) {
                    $valueItems[] = ['type' => 'Label', 'caption' => 'ℹ Erwartungswerte und Performance-Ratio funktionieren bereits mit der Stable-Version der „PV-Prognose" — dafür ist nichts zu tun. Nur die spezifische Leistung (W/m²) braucht zusätzlich die Modulangaben: Modulanzahl sowie Modullänge und -breite (mm) je Generator. Diese Felder gibt es erst ab Version 0.20 (Build 41), also im Beta-Zweig des Repositories DG65/Prognose; im Stable-Kanal (0.19) existieren sie noch nicht und lassen sich dort auch nicht eintragen.'];
                } else {
                    $valueItems[] = ['type' => 'Label', 'caption' => '✅ Modulfläche aus der PV-Prognose erkannt: ' . rtrim(rtrim(number_format($pvf['area'], 2, ',', '.'), '0'), ',') . ' m² — bereit für die spätere spez.-Leistungs-/PR-Auswertung.'];
                }
            }
        } else {
            $valueItems[] = ['type' => 'Label', 'caption' => '➜ Zuerst oben eine InverterHub-Instanz wählen und „Änderungen übernehmen".'];
        }
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Werte', 'expanded' => true, 'items' => $valueItems];

        // --- Strompreis (optionale Kopplung an eine Preisquelle) -------------
        $priceItems = [];
        $tibberAll = @IPS_GetInstanceListByModuleID(self::TIBBER_GUID);
        $tibberAll = is_array($tibberAll) ? $tibberAll : [];
        if (count($tibberAll) === 0) {
            $priceItems[] = ['type' => 'Label', 'caption' => 'ℹ️ Kein Preisquellen-Modul gefunden. Mit installiertem „Tibber Grid Reward" lässt sich der Strompreis als Stufenkurve in den Tagesverlauf legen (Reiter „Leistung"), inklusive Vorschau auf die kommenden Stunden.'];
        } else {
            $priceItems[] = ['type' => 'CheckBox', 'name' => 'show_price', 'caption' => 'Strompreis im Tagesverlauf anzeigen'];
            $priceItems[] = ['type' => 'SelectInstance', 'name' => 'PriceInstance', 'caption' => 'Preisquelle (leer = automatisch, wenn nur eine vorhanden)'];
            // Die Kurve besteht aus zwei Quellen; wir sagen für JEDE einzeln, ob
            // sie bereitsteht, statt einen allgemeinen Tipp zu geben.
            $pvid = $this->PriceVarID();
            $aid  = $this->ArchiveID();
            if ($pvid <= 0) {
                $priceItems[] = ['type' => 'Label', 'caption' => '⚠️ Preisvariable nicht gefunden — ist die Preisquelle konfiguriert (Zugangsschlüssel/Zuhause)?'];
            } else {
                $logged = ($aid > 0 && @AC_GetLoggingStatus($aid, $pvid));
                $priceItems[] = ['type' => 'Label', 'caption' => '✅ Vorschau bereit: Die kommenden Stunden kommen direkt aus dem Preismodul.'];
                $priceItems[] = ['type' => 'Label', 'name' => 'PriceLogHint', 'caption' => $logged
                    ? '✅ Rückblick bereit: Die Preisvariable wird archiviert.'
                    : '➜ Rückblick fehlt: Die Variable „Aktueller Preis" wird nicht archiviert — ohne Archiv zeigt die Kachel nur die Vorschau ab jetzt, denn das Preismodul liefert keine Historie. Aufgezeichnet wird erst ab dem Einschalten, rückwirkend gibt es nichts.'];
                $priceItems[] = ['type' => 'Button', 'name' => 'PriceLogButton', 'caption' => 'Archivierung der Preisvariable einschalten',
                    'visible' => !$logged, 'onClick' => 'IHUBMON_EnablePriceLogging($id);'];
            }
        }
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Strompreis', 'expanded' => false, 'items' => $priceItems];

        $elements[] = [
            'type' => 'ExpansionPanel', 'caption' => 'Darstellung', 'expanded' => false,
            'items' => [
                ['type' => 'Select', 'name' => 'Engine', 'caption' => 'Diagramm-Engine', 'options' => [
                    ['caption' => 'Apache ECharts', 'value' => 'echarts'],
                    ['caption' => 'Highcharts', 'value' => 'highcharts'],
                ]],
                ['type' => 'SelectColor', 'name' => 'ColorBackground', 'caption' => 'Hintergrundfarbe (-1 = Standard)'],
                ['type' => 'ValidationTextBox', 'name' => 'FontFamily', 'caption' => 'Schriftart (leer = Standard)'],
            ],
        ];

        return json_encode([
            'elements' => $elements,
            'actions'  => [],
            'status'   => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Monitoring aktiv'],
                ['code' => 201, 'icon' => 'inactive', 'caption' => 'Keine Werte angekreuzt'],
                ['code' => 202, 'icon' => 'inactive', 'caption' => 'Keine InverterHub-Instanz gewählt'],
            ],
        ]);
    }

    // -----------------------------------------------------------------------

    // Löst die angekreuzten Katalog-Einträge + Einstrahlung zu Serien auf.
    // Reihenfolge = Katalog-Reihenfolge, danach Einstrahlung.
    private function ResolveSeries(): array
    {
        $src = $this->ReadPropertyInteger('SourceInstance');
        $out = [];
        if ($src > 0 && IPS_InstanceExists($src)) {
            foreach (self::CATALOG as $key => $def) {
                if (!$this->ReadPropertyBoolean('show_' . $key)) {
                    continue;
                }
                // Nur Werte, deren Gruppe noch einen aktiven Reiter hat.
                if (count(array_intersect($def['groups'], array_keys($this->TabList()))) === 0) {
                    continue;
                }
                $powerVid  = $this->FirstIdent($src, $def['power']);
                $energyVid = $this->FirstIdent($src, $def['energy']);
                if ($powerVid <= 0 && $energyVid <= 0) {
                    continue;
                }
                // Batterie-Leistung optional invertieren (Vorzeichen-Konvention
                // laden/entladen ist je Anlage verschieden). Nutzt den scale-Weg.
                $scale = ($key === 'bat' && $this->ReadPropertyBoolean('BatInvert')) ? -1.0 : 1.0;
                $out[] = [
                    'key'       => $key,
                    'label'     => $def['label'],
                    'color'     => $def['color'],
                    'axis'      => $def['axis'],
                    'unit'      => $def['unit'],
                    'noEnergy'  => !empty($def['noEnergy']),
                    'groups'    => $def['groups'],
                    'isIrr'     => false,
                    'dash'      => false,
                    'scale'     => $scale,
                    'powerVid'  => $powerVid,
                    'energyVid' => $energyVid,
                ];
            }
        }
        $irr = $this->ReadPropertyInteger('IrradianceID');
        $irrOn = ($this->ReadPropertyBoolean('show_irr') && $irr > 0 && IPS_VariableExists($irr));
        if ($irrOn) {
            $out[] = [
                'key'       => 'irr',
                'label'     => 'Einstrahlung',
                'color'     => self::IRR_COLOR,
                'axis'      => 'right',
                'unit'      => 'W/m²',
                'noEnergy'  => false,
                'groups'    => ['solar'],
                'isIrr'     => true,
                'dash'      => false,
                'scale'     => 1.0,
                'powerVid'  => $irr,   // wird zu kWh/m² integriert
                'energyVid' => 0,
            ];
        }

        // Strompreis: Die Quelle ist ein FREMDES Modul, deshalb kein Katalog-
        // Eintrag (der sucht Idents in der InverterHub-Instanz). Vergangenheit
        // kommt aus der archivierten Variable „CurrentPrice" (€/kWh, daher
        // scale 100 → ct/kWh), die Zukunft in BuildPayload() aus
        // TIBBERGR_GetPriceCurve(). „noEnergy" => nur Tagesverlauf, denn eine
        // Wochen-/Monatssumme eines Preises ergibt keinen Sinn.
        $priceVid = $this->PriceVarID();
        if ($this->ReadPropertyBoolean('show_price') && $priceVid > 0) {
            $out[] = [
                'key'       => 'price',
                'label'     => 'Strompreis',
                'color'     => self::PRICE_COLOR,
                'axis'      => 'right',
                'unit'      => 'ct/kWh',
                'noEnergy'  => true,
                'groups'    => ['price'],
                'isIrr'     => false,
                'dash'      => false,
                'step'      => true,   // Preise gelten slotweise, keine Rampe
                'scale'     => 100.0,  // €/kWh → ct/kWh
                'powerVid'  => $priceVid,
                'energyVid' => 0,
            ];

            // NUR der Netz-BEZUG je Viertelstunde als Balken, im Preis-Reiter
            // neben der Preiskurve. Bewusst NICHT die Einspeisung und nicht die
            // Netto-Netzenergie: Eingespeiste Energie wird zur festen
            // Einspeiseverguetung verkauft (Bestandsanlage 18,36 ct), nicht zum
            // dynamischen Preis - sie neben die Preiskurve zu legen, wuerde den
            // Fokus verwaessern. Relevant ist allein die Energie, deren Kosten
            // = Bezug × dynamischer Preis. Reine ANZEIGE, keine Kostenrechnung
            // (Rechnungspruefung liegt beim EMS). Aus der Netz-LEISTUNG
            // integriert (meter_total), nicht aus einem kumulativen Zaehler -
            // das vermeidet die Zaehlertausch-/Einheitenwechsel-Fallen.
            // Quelle für den Bezug: bevorzugt der abrechnungsgenaue MeterHub-
            // Netzzähler (kumulativer kWh-Counter, exakt), sonst die integrierte
            // Wechselrichter-Netzleistung (Näherung, aber immer verfügbar).
            $billVid = $this->BillingGridImportVid();
            $gridVid = $billVid > 0
                ? $billVid
                : $this->FirstIdent($this->ReadPropertyInteger('SourceInstance'), self::CATALOG['grid']['power']);
            if ($gridVid > 0) {
                $out[] = [
                    'key'         => 'gridnrg',
                    'label'       => $billVid > 0 ? 'Netzbezug (abrechnungsgenau)' : 'Netzbezug',
                    'color'       => '#4aa3e0',
                    'axis'        => 'left',
                    'unit'        => 'kWh',
                    'noEnergy'    => true,
                    'groups'      => ['price'],
                    'isIrr'       => false,
                    'dash'        => false,
                    'bars'        => true,   // als Balken je Slot zeichnen
                    'slotEnergy'  => true,   // Slot-Energie (Balken)
                    'counterMode' => ($billVid > 0),  // true: Zähler-Differenz; false: aus Leistung integrieren
                    'scale'       => 1.0,
                    'powerVid'    => $gridVid,
                    'energyVid'   => 0,
                ];
            }
        }

        // Berechnete Serien aus Generatorparametern (PV-Prognose) × Einstrahlung:
        // erwartete Leistung = kWp × E(W/m²) × PR × Faktor. Nur wenn ein
        // Einstrahlungssensor gewählt ist und die PVF-Instanz Generatoren liefert.
        if ($irrOn) {
            $pvf = $this->PvfModel();
            if ($pvf !== null) {
                // Gesamt-Erwartung fürs Diagramm „PV & Einstrahlung".
                $out[] = [
                    'key'       => 'exp_total',
                    'label'     => 'PV erwartet',
                    'color'     => '#ff8a80',
                    'axis'      => 'left',
                    'unit'      => 'W',
                    'noEnergy'  => false,
                    'groups'    => ['solar'],
                    'isIrr'     => false,
                    'dash'      => true,
                    'scale'     => $pvf['totalKwp'] * $pvf['pr'],
                    'powerVid'  => $irr,
                    'energyVid' => 0,
                ];
                // Erwartung je Generator fürs Diagramm „MPP-Tracker" — hellere
                // Tönungen der kräftigen MPPT-Farben (gestrichelt = Soll).
                $palette = ['#ef9a9a', '#a5d6a7', '#90caf9', '#ffcc80', '#ce93d8', '#bcaaa4'];
                foreach ($pvf['gens'] as $i => $g) {
                    $out[] = [
                        'key'       => 'exp_g' . $i,
                        'label'     => 'Erwartet: ' . $g['name'],
                        'color'     => $palette[$i % count($palette)],
                        'axis'      => 'left',
                        'unit'      => 'W',
                        'noEnergy'  => false,
                        'groups'    => ['mpp'],
                        'isIrr'     => false,
                        'dash'      => true,
                        'scale'     => $g['kwp'] * $pvf['pr'] * $g['factor'],
                        'powerVid'  => $irr,
                        'energyVid' => 0,
                    ];
                }
            }
        }
        return $out;
    }

    // Liest aus der PV-Prognose (PVF) die Parameter fürs Erwartungs-Modell:
    // Performance-Ratio (PVF_PR) und je Generator kWp + manueller Faktor.
    // Rückgabe null, wenn keine PVF-Instanz / keine Generatoren mit kWp da sind.
    // PV-Prognose-Instanz: konfigurierte, sonst automatisch NUR bei genau einer.
    // Bei mehreren ohne explizite Wahl wird NICHT geraten (0) — sonst nähme man
    // still die erste (früheres Verhalten, von der Prognose-Sitzung als eigener
    // Fehler bestätigt). Eine ungültig gewählte Instanz fällt still auf die
    // Automatik zurück, damit ein Update keine laufende Installation lahmlegt.
    private function PvfInstanceID(): int
    {
        $cfg = $this->ReadPropertyInteger('PvfInstance');
        if ($cfg > 0 && IPS_InstanceExists($cfg)
            && IPS_GetInstance($cfg)['ModuleInfo']['ModuleID'] === self::PVF_GUID) {
            return $cfg;
        }
        $ids = @IPS_GetInstanceListByModuleID(self::PVF_GUID);
        return (is_array($ids) && count($ids) === 1) ? (int)$ids[0] : 0;
    }

    private function PvfModel(): ?array
    {
        $id = $this->PvfInstanceID();
        if ($id <= 0) {
            return null;
        }

        // Bevorzugt der stabile öffentliche Getter (versionsunabhängiger
        // Vertrag); Fallback auf die internen Properties per IPS_GetConfiguration
        // für ältere Prognose-Modul-Versionen ohne Getter.
        $rows = []; $pr = 0.0;
        if (function_exists('PVF_GetGenerators')) {
            $r = @PVF_GetGenerators($id);
            if (is_array($r) && isset($r['generators']) && is_array($r['generators'])) {
                $pr = (float)($r['pr'] ?? 0);
                foreach ($r['generators'] as $g) {
                    $rows[] = ['name' => (string)($g['name'] ?? ''), 'kwp' => (float)($g['kwp'] ?? 0), 'factor' => (float)($g['factor'] ?? 1.0)];
                }
            }
        }
        if (count($rows) === 0) {
            $cfg = @IPS_GetConfiguration($id);
            $cfg = is_string($cfg) ? json_decode($cfg, true) : null;
            if (is_array($cfg)) {
                $pr = (float)($cfg['PVF_PR'] ?? 0);
                $list = json_decode($cfg['PVGenerators'] ?? '[]', true);
                if (is_array($list)) {
                    foreach ($list as $row) {
                        $rows[] = ['name' => (string)($row['Name'] ?? ''), 'kwp' => (float)($row['kWp'] ?? 0), 'factor' => (float)($row['Factor'] ?? 1.0)];
                    }
                }
            }
        }

        if ($pr <= 0.0) { $pr = 0.85; }
        $gens = []; $total = 0.0;
        foreach ($rows as $row) {
            $kwp = $row['kwp'];
            if ($kwp <= 0.0) { continue; }
            $factor = ($row['factor'] > 0.0) ? $row['factor'] : 1.0;
            $name = trim($row['name']);
            $gens[] = ['name' => ($name !== '' ? $name : 'Generator ' . (count($gens) + 1)), 'kwp' => $kwp, 'factor' => $factor];
            $total += $kwp;
        }
        if (count($gens) === 0) {
            return null;
        }
        return ['id' => $id, 'pr' => $pr, 'gens' => $gens, 'totalKwp' => $total];
    }

    private function BuildPayload()
    {
        $engine = ($this->ReadPropertyString('Engine') === 'highcharts') ? 'highcharts' : 'echarts';
        $style = [
            'engine' => $engine,
            'bg'     => $this->ColorOrEmpty($this->ReadPropertyInteger('ColorBackground')),
            'font'   => $this->FontStack($this->ReadPropertyString('FontFamily')),
            'uid'    => (string)$this->InstanceID,
        ];

        $src = $this->ReadPropertyInteger('SourceInstance');
        if ($src <= 0 || !IPS_InstanceExists($src)) {
            return json_encode(array_merge($style, ['ok' => false, 'stateLabel' => 'Keine InverterHub-Instanz gewählt']));
        }
        $series = $this->ResolveSeries();
        if (count($series) === 0) {
            return json_encode(array_merge($style, ['ok' => false, 'stateLabel' => 'Keine Werte angekreuzt']));
        }
        $aid = $this->ArchiveID();
        if ($aid <= 0) {
            return json_encode(array_merge($style, ['ok' => false, 'stateLabel' => 'Kein Archiv gefunden']));
        }

        $meta = [];
        foreach ($series as $s) {
            $meta[] = ['label' => $s['label'], 'color' => $s['color'], 'axis' => $s['axis'], 'unit' => $s['unit'], 'dash' => !empty($s['dash']), 'step' => !empty($s['step']), 'bar' => !empty($s['bars'])];
        }

        // Seitliche Reiter: je Gruppe die Serienindizes + eigene Achsen-Einheiten.
        $tabs = [];
        foreach ($this->TabList() as $gkey => $glabel) {
            $idx = []; $hasEnergy = false;
            $dayLeft = ''; $dayRight = ''; $eRight = 'kWh';
            foreach ($series as $i => $s) {
                if (!in_array($gkey, $s['groups'], true)) {
                    continue;
                }
                $idx[] = $i;
                if (empty($s['noEnergy'])) { $hasEnergy = true; }
                if ($s['axis'] === 'right') {
                    if ($dayRight === '') { $dayRight = $s['unit']; }
                    if ($s['isIrr']) { $eRight = 'kWh/m²'; }
                } else {
                    // Leistung wird im Tagesverlauf in kW dargestellt (s. u.).
                    if ($dayLeft === '') { $dayLeft = ($s['unit'] === 'W') ? 'kW' : $s['unit']; }
                }
            }
            if (count($idx) === 0) {
                continue;
            }
            if ($dayLeft === '') { $dayLeft = $dayRight !== '' ? $dayRight : 'W'; }
            // Der Strompreis-Reiter bleibt bewusst auf die Tagesansicht
            // beschraenkt: Die Preisquelle liefert nur heute/morgen, eine
            // Wochen-/Monatssumme eines Preises ergibt keinen Sinn.
            $types = ($gkey === 'price') ? ['day'] : ($hasEnergy ? ['day', 'week', 'month', 'year', 'all', 'custom'] : ['day']);
            $tabs[] = [
                'key'   => $gkey,
                'label' => $glabel,
                'idx'   => $idx,
                'types' => $types,
                'units' => [
                    'day'    => ['left' => $dayLeft, 'right' => $dayRight],
                    'week'   => ['left' => 'kWh',    'right' => $eRight],
                    'month'  => ['left' => 'kWh',    'right' => $eRight],
                    'year'   => ['left' => 'kWh',    'right' => $eRight],
                    'all'    => ['left' => 'kWh',    'right' => $eRight],
                    'custom' => ['left' => 'kWh',    'right' => $eRight],
                ],
            ];
        }

        // --- Verlauf je Tag (5-Minuten-Linie, Leistung) ---
        $dayPeriods = [];
        for ($k = 0; $k < self::WINDOW_DAYS; $k++) {
            $start = strtotime("today -{$k} days 00:00:00");
            $end   = min(time(), $start + 86400);
            $rows = [];
            foreach ($series as $s) {
                if ($s['powerVid'] <= 0) { $rows[] = []; continue; }
                // Netzenergie-Balken: Leistung (W) je 15-Minuten-Fenster zu kWh
                // integrieren, Vorzeichen umdrehen (+ = Bezug). Aus denselben
                // 5-Minuten-Archivwerten wie die Leistungslinie.
                if (!empty($s['slotEnergy'])) {
                    $rows[] = $this->SlotEnergyBars($aid, $s['powerVid'], $start, $end, !empty($s['counterMode']));
                    continue;
                }
                $pts = $this->DaySeries($aid, $s['powerVid'], $start, $end);
                $scale = (float)($s['scale'] ?? 1.0);
                // Berechnete Serien: Einstrahlung × kWp × PR × Faktor = erwartete W.
                if ($scale != 1.0) {
                    foreach ($pts as &$pt) { $pt[1] = $pt[1] * $scale; } unset($pt);
                }
                // Leistung (linke Achse, W) → kW für lesbarere Skala.
                if ($s['axis'] !== 'right' && $s['unit'] === 'W') {
                    foreach ($pts as &$pt) { $pt[1] = round($pt[1] / 1000.0, 3); }
                    unset($pt);
                } elseif ($s['unit'] === '%') {
                    // SOC o. Ä.: BMS-Rauschen glätten (gleitender Mittelwert) →
                    // ruhige Kurve statt Zackenmuster.
                    $pts = $this->SmoothPoints($pts, 15);
                }
                // Strompreis am HEUTIGEN Tag um die Zukunft ergänzen: Der Archiv-
                // teil endet „jetzt", der Vertrag kennt die Slots bis morgen
                // Abend. Nur Punkte ab dem Archivende anhängen, damit sich
                // Vergangenheit und Vorschau nicht überlappen.
                if (!empty($s['step']) && $k === 0) {
                    $cut = count($pts) ? $pts[count($pts) - 1][0] : ($start * 1000);
                    foreach ($this->FuturePricePoints() as $fp) {
                        if ($fp[0] > $cut) { $pts[] = $fp; }
                    }
                }
                $rows[] = $pts;
            }
            $dayPeriods[] = ['id' => date('Y-m-d', $start), 'range' => date('d.m.Y', $start), 'series' => $rows];
        }

        // --- Energie-Basis: Tages-kWh je Serie über die gesamte Spanne ---
        // Ein Archivdurchlauf je Serie; daraus werden Woche/Monat/Jahr/Gesamt/
        // Benutzerdefiniert abgeleitet.
        $spanStart = strtotime('-' . self::SPAN_YEARS . ' years', strtotime(date('Y-01-01 00:00:00')));
        $now = time();
        $daily = [];      // [seriesIdx]['Y-m-d'] => kWh
        $monthSum = [];   // [seriesIdx]['Y-m']   => kWh
        $yearSum = [];    // [seriesIdx]['Y']     => kWh
        $allDates = [];   // 'Y-m-d' => 1 (Vereinigung)
        $minYear = (int)date('Y');
        foreach ($series as $i => $s) {
            $map = $this->ComputeDailyMap($aid, $s, $spanStart, $now);
            $daily[$i] = $map;
            $monthSum[$i] = []; $yearSum[$i] = [];
            foreach ($map as $d => $v) {
                $ym = substr($d, 0, 7); $y = substr($d, 0, 4);
                $monthSum[$i][$ym] = ($monthSum[$i][$ym] ?? 0.0) + $v;
                $yearSum[$i][$y]   = ($yearSum[$i][$y] ?? 0.0) + $v;
                $allDates[$d] = 1;
                if ((int)$y < $minYear) { $minYear = (int)$y; }
            }
        }
        $mNames = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
        $wNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

        // --- Energie je Woche (Tages-Balken, Mo–So) ---
        $weekPeriods = [];
        $wBase = strtotime('monday this week 00:00:00');
        for ($k = 0; $k < self::WINDOW_WEEKS; $k++) {
            $wStart = strtotime("-{$k} weeks", $wBase);
            $dates = []; for ($d = 0; $d < 7; $d++) { $dates[] = date('Y-m-d', $wStart + $d * 86400); }
            $rows = [];
            foreach ($series as $i => $s) { $rows[] = array_map(function ($dd) use ($daily, $i) { return $daily[$i][$dd] ?? null; }, $dates); }
            $we = $wStart + 6 * 86400;
            $weekPeriods[] = ['id' => date('o-\WW', $wStart), 'range' => 'KW ' . date('W', $wStart) . ' · ' . date('d.m.', $wStart) . '–' . date('d.m.Y', $we), 'cats' => $wNames, 'series' => $rows];
        }

        // --- Energie je Monat (Tages-Balken) ---
        $monthPeriods = [];
        $mBase = strtotime(date('Y-m-01 00:00:00'));
        for ($k = 0; $k < self::WINDOW_MONTHS; $k++) {
            $mStart = strtotime("-{$k} months", $mBase);
            $days   = (int)date('t', $mStart);
            $cats = []; $dates = [];
            for ($d = 1; $d <= $days; $d++) { $cats[] = (string)$d; $dates[] = date('Y-m-d', strtotime("+" . ($d - 1) . " days", $mStart)); }
            $rows = [];
            foreach ($series as $i => $s) { $rows[] = array_map(function ($dd) use ($daily, $i) { return $daily[$i][$dd] ?? null; }, $dates); }
            $monthPeriods[] = ['id' => date('Y-m', $mStart), 'range' => $this->MonthName((int)date('n', $mStart)) . ' ' . date('Y', $mStart), 'cats' => $cats, 'series' => $rows];
        }

        // --- Energie je Jahr (Monats-Balken) ---
        $yearPeriods = [];
        for ($k = 0; $k < self::WINDOW_YEARS; $k++) {
            $y = (int)date('Y') - $k;
            $rows = [];
            foreach ($series as $i => $s) {
                $arr = []; for ($m = 1; $m <= 12; $m++) { $arr[] = $monthSum[$i][sprintf('%04d-%02d', $y, $m)] ?? null; }
                $rows[] = $arr;
            }
            $yearPeriods[] = ['id' => (string)$y, 'range' => (string)$y, 'cats' => $mNames, 'series' => $rows];
        }

        // --- Gesamt (seit Anbeginn): ein Balken je Jahr ---
        $allCats = []; $allRows = [];
        for ($y = $minYear; $y <= (int)date('Y'); $y++) { $allCats[] = (string)$y; }
        foreach ($series as $i => $s) {
            $arr = []; foreach ($allCats as $yy) { $arr[] = $yearSum[$i][$yy] ?? null; }
            $allRows[] = $arr;
        }
        $allPeriods = [['id' => 'all', 'range' => (count($allCats) ? $allCats[0] . '–' . end($allCats) : 'Gesamt'), 'cats' => $allCats, 'series' => $allRows]];

        // --- Benutzerdefiniert: Tageswerte zum freien Von–Bis-Filtern im Webfront ---
        ksort($allDates);
        $customDaily = [];
        foreach (array_keys($allDates) as $d) {
            $v = []; foreach ($series as $i => $s) { $v[] = $daily[$i][$d] ?? null; }
            $customDaily[] = ['d' => $d, 'v' => $v];
        }

        return json_encode(array_merge($style, [
            'ok'          => true,
            'defaultType' => 'day',
            'defaultTab'  => $tabs[0]['key'] ?? 'energy',
            'seriesMeta'  => $meta,
            'tabs'        => $tabs,
            'types'       => [
                'day'    => ['mode' => 'line', 'periods' => $dayPeriods],
                'week'   => ['mode' => 'bar',  'periods' => $weekPeriods],
                'month'  => ['mode' => 'bar',  'periods' => $monthPeriods],
                'year'   => ['mode' => 'bar',  'periods' => $yearPeriods],
                'all'    => ['mode' => 'bar',  'periods' => $allPeriods],
                'custom' => ['mode' => 'bar',  'daily' => $customDaily, 'from' => date('Y-m-01'), 'to' => date('Y-m-d')],
            ],
        ]));
    }

    // Tages-Energie (kWh) je Kalendertag als ['Y-m-d' => kWh].
    // Zähler (energyVid): Zählertyp automatisch erkannt
    //   • Lifetime-Zähler (Min≈Max, läuft hoch): Zuwachs = Max[i]−Max[i-1]
    //     (der erste Bucket wird verworfen → kein „Geburtswert"-Spike).
    //   • Tagesreset-Zähler (Min≈0 je Tag): Zuwachs = Max−Min.
    // Reine Leistung (powerVid): Ø-Leistung × 24 h / 1000. „noEnergy" → leer.
    private function ComputeDailyMap(int $aid, array $s, int $start, int $end): array
    {
        if (!empty($s['noEnergy'])) {
            return [];
        }
        $counter = ($s['energyVid'] > 0);
        $vid = $counter ? $s['energyVid'] : $s['powerVid'];
        if ($vid <= 0 || !IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_DAY, $start, $end, 0);
        if (!is_array($data) || count($data) === 0) {
            return [];
        }
        usort($data, function ($a, $b) { return (int)$a['TimeStamp'] <=> (int)$b['TimeStamp']; });

        // Zählertyp bestimmen: Median von Min/Max. Nah 1 → Lifetime, nah 0 → Reset.
        $lifetime = false;
        if ($counter) {
            $ratios = [];
            foreach ($data as $row) {
                $mx = (float)$row['Max'];
                if ($mx > 0) { $ratios[] = ((float)$row['Min']) / $mx; }
            }
            if (count($ratios) > 0) {
                sort($ratios);
                $lifetime = ($ratios[intdiv(count($ratios), 2)] > 0.5);
            }
        }

        $scale = (float)($s['scale'] ?? 1.0);
        $out = []; $prevMax = null;
        foreach ($data as $row) {
            $ts = (int)$row['TimeStamp'];
            $mx = (float)$row['Max'];
            if (!$counter) {
                $val = (float)$row['Avg'] * 24.0 / 1000.0;   // Leistung → kWh
            } elseif ($lifetime) {
                $val = ($prevMax === null) ? null : ($mx - $prevMax); // Tag-zu-Tag
                $prevMax = $mx;
            } else {
                $val = $mx - (float)$row['Min'];              // Tagesreset
            }
            if ($val === null) { continue; }
            $val *= $scale; // berechnete Serien: × kWp·PR·Faktor
            if (!is_finite($val) || $val < 0) { $val = 0.0; }
            $out[date('Y-m-d', $ts)] = round($val, 2);
        }
        return $out;
    }

    // Zentrierter gleitender Mittelwert über $win Punkte (glättet Rauschen,
    // z. B. beim Batterie-SOC). Zeitstempel bleiben erhalten.
    private function SmoothPoints(array $pts, int $win): array
    {
        $n = count($pts);
        if ($n < 3 || $win < 2) {
            return $pts;
        }
        $half = intdiv($win, 2);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $lo = max(0, $i - $half);
            $hi = min($n - 1, $i + $half);
            $sum = 0.0; $c = 0;
            for ($j = $lo; $j <= $hi; $j++) { $sum += $pts[$j][1]; $c++; }
            $out[] = [$pts[$i][0], round($sum / $c, 1)];
        }
        return $out;
    }

    // 5-Minuten-Zeitreihe (Mittelwert je Bucket) eines Tages als [[tsMs,val],…].
    private function DaySeries(int $aid, int $vid, int $start, int $end): array
    {
        if (!IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_5MIN, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $pts = [];
        foreach ($data as $row) {
            $pts[] = [((int)$row['TimeStamp']) * 1000, round((float)$row['Avg'], 2)];
        }
        usort($pts, function ($a, $b) { return $a[0] <=> $b[0]; });
        return $pts;
    }

    // Netz-BEZUG je 15-Minuten-Fenster (kWh). Feste Viertelstunden-Raster, an
    // der vollen Stunde ausgerichtet - passt zur 15-Minuten-Abrechnung
    // (§14a/Tibber). Jeder Balken sitzt am FENSTERANFANG, damit die Balkenbreite
    // (= Slotdauer) korrekt nach rechts laeuft und sich mit der Preis-Stufenkurve
    // deckt.
    //
    // Zwei Quellen, je nach $counter:
    //  - $counter=false: aus der Netz-LEISTUNG (meter_total) integriert.
    //    meter_total ist + = Einspeisung, Bezug also die NEGATIVE Leistung; nur
    //    dieser Anteil zaehlt (max(0, −P)), Einspeisung bleibt aussen vor. Aus
    //    5-Minuten-Mitteln (Wh = W × 5/60 h).
    //  - $counter=true: aus einem kumulativen ENERGIE-Zaehler (MeterHub billing).
    //    AC_GetAggregatedValues liefert bei Counter-Variablen im Feld Avg den
    //    VERBRAUCH je Periode (nicht den Rohstand) - an Dietmars Anlage
    //    verifiziert; Ueberlauf/Zaehlerwechsel behandelt das Archiv selbst.
    //    Deshalb NICHT roh differenzieren, sondern die 5-Minuten-Verbraeuche zu
    //    15 min SUMMIEREN (Verbrauchswerte darf man summieren). Ein Import-Zaehler
    //    steigt nur, der Wert ist bereits reiner Bezug (≥ 0), keine Vorzeichen-
    //    behandlung noetig.
    private function SlotEnergyBars(int $aid, int $vid, int $start, int $end, bool $counter = false): array
    {
        if (!IPS_VariableExists($vid) || !@AC_GetLoggingStatus($aid, $vid)) {
            return [];
        }
        $data = @AC_GetAggregatedValues($aid, $vid, self::AGG_5MIN, $start, $end, 0);
        if (!is_array($data)) {
            return [];
        }
        $slot = 900;                  // 15 min
        $buckets = [];                // slotStartSec => kWh Bezug (immer ≥ 0)
        foreach ($data as $row) {
            $ts = (int)$row['TimeStamp'];
            $bucket = $start + intdiv($ts - $start, $slot) * $slot;
            if ($counter) {
                // Avg = Verbrauch (kWh) in diesem 5-min-Abschnitt.
                $buckets[$bucket] = ($buckets[$bucket] ?? 0.0) + max(0.0, (float)$row['Avg']);
            } else {
                // Bezugsanteil der Leistung (W) → kWh.
                $drawW = max(0.0, -(float)$row['Avg']);
                $buckets[$bucket] = ($buckets[$bucket] ?? 0.0) + $drawW * (5.0 / 60.0) / 1000.0;
            }
        }
        ksort($buckets);
        $bars = [];
        foreach ($buckets as $bStart => $kwh) {
            $bars[] = [$bStart * 1000, round($kwh, 3)];
        }
        return $bars;
    }

    // Preisquellen-Instanz: konfigurierte, sonst die einzige vorhandene.
    // Bewusst nur automatisch, wenn es GENAU EINE gibt - bei mehreren muss der
    // Nutzer wählen, statt dass wir die erste raten.
    // Versionszeile im Doku-Panel (Verbund-Konvention, EMS 24.07.2026): das
    // „Was ist neu"-Banner ist dismissible, die Version braucht daher eine
    // dauerhafte Stelle.
    private function VersionLabel(): array
    {
        $lib = @IPS_GetLibrary('{7EFE4BD7-DC14-460E-B0ED-88071197D35B}');
        $txt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ InverterHubMonitor Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ InverterHubMonitor';
        return ['type' => 'Label', 'caption' => $txt];
    }

    private function PriceInstanceID(): int
    {
        $cfg = $this->ReadPropertyInteger('PriceInstance');
        if ($cfg > 0) {
            return IPS_InstanceExists($cfg) ? $cfg : 0;
        }
        $all = @IPS_GetInstanceListByModuleID(self::TIBBER_GUID);
        return (is_array($all) && count($all) === 1) ? (int)$all[0] : 0;
    }

    // Abrechnungsgenauer Netzzähler aus MeterHub: die energyImportID (kumulativer
    // kWh-Zähler) der EINEN Zuordnung mit function=='grid' UND authority=='billing'
    // (Inexogy am Netzübergabepunkt). Rein OPTIONAL - fehlt MeterHub oder ein
    // solcher Zähler, liefert dies 0 und die Balken bleiben auf der Integration
    // aus der Wechselrichter-Netzleistung. Guard zwingend (function_exists),
    // sonst Fatal Error ohne MeterHub. Fehlende Vertragsfelder gelten konservativ
    // als authority='auxiliary' (also NICHT billing) - so wird eine ältere
    // MeterHub-Version ohne die Felder nie fälschlich als billing-Quelle gewählt.
    private function BillingGridImportVid(): int
    {
        foreach ([['MHUB_GetFunctions', self::METERHUB_GUID], ['MHUBV_GetFunctions', self::METERHUBV_GUID]] as $src) {
            [$fn, $guid] = $src;
            if (!function_exists($fn)) {
                continue;
            }
            foreach (@IPS_GetInstanceListByModuleID($guid) ?: [] as $iid) {
                $info = @$fn($iid);
                if (!is_array($info) || empty($info['assignments'])) {
                    continue;
                }
                foreach ($info['assignments'] as $a) {
                    $function  = $a['function']  ?? '';
                    $authority = $a['authority'] ?? 'auxiliary';
                    $vid       = (int)($a['energyImportID'] ?? 0);
                    if ($function === 'grid' && $authority === 'billing' && $vid > 0 && IPS_VariableExists($vid)) {
                        return $vid;
                    }
                }
            }
        }
        return 0;
    }

    // Archivierte Preisvariable (€/kWh) der Preisquelle.
    private function PriceVarID(): int
    {
        $iid = $this->PriceInstanceID();
        return ($iid > 0) ? $this->FindIdentRecursive($iid, 'CurrentPrice') : 0;
    }

    // Zukünftige Preis-Slots als Diagrammpunkte [ms, ct/kWh].
    // Der Vertrag liefert [start, end) je Slot; für eine Stufenkurve genügt der
    // Startpunkt je Slot plus ein Abschlusspunkt am Ende des letzten Slots.
    // Guard zwingend: Ohne installiertes Preismodul wäre der Aufruf ein Fatal
    // Error (siehe Eigenständigkeitsregel in CLAUDE.md).
    private function FuturePricePoints(): array
    {
        $iid = $this->PriceInstanceID();
        if ($iid <= 0 || !function_exists('TIBBERGR_GetPriceCurve')) {
            return [];
        }
        $curve = @TIBBERGR_GetPriceCurve($iid);
        if (!is_array($curve) || count($curve) === 0) {
            return [];
        }
        $pts = []; $lastEnd = 0; $lastPrice = null;
        foreach ($curve as $slot) {
            if (!isset($slot['start'], $slot['end'], $slot['price'])) {
                continue;
            }
            $pts[] = [((int)$slot['start']) * 1000, round((float)$slot['price'], 2)];
            $lastEnd   = (int)$slot['end'];
            $lastPrice = round((float)$slot['price'], 2);
        }
        if ($lastEnd > 0 && $lastPrice !== null) {
            $pts[] = [$lastEnd * 1000, $lastPrice];   // Stufe bis Slot-Ende ausziehen
        }
        return $pts;
    }

    // Erste vorhandene Variable zu einer Ident-Kandidatenliste in der Quelle.
    private function FirstIdent(int $iid, array $idents): int
    {
        foreach ($idents as $ident) {
            $vid = $this->FindIdentRecursive($iid, $ident);
            if ($vid > 0) {
                return $vid;
            }
        }
        return 0;
    }

    private function FindIdentRecursive(int $parent, string $ident): int
    {
        foreach (IPS_GetChildrenIDs($parent) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] === 2 && $obj['ObjectIdent'] === $ident) {
                return $cid;
            }
            $sub = $this->FindIdentRecursive($cid, $ident);
            if ($sub > 0) {
                return $sub;
            }
        }
        return 0;
    }

    private function MonthName(int $m): string
    {
        $n = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        return $n[$m] ?? (string)$m;
    }

    private function ArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $ids[0] ?? 0;
    }

    // Status des Prognose-Moduls (PV-Prognose): installiert? Gesamt-Modulfläche (m²)?
    // Bezieht die Fläche über die öffentliche PVF_GetModuleArea($id), Fallback
    // Statusvariable PVF_ModuleArea.
    private function PvfArea(): array
    {
        $iid = $this->PvfInstanceID();
        if ($iid <= 0) {
            return ['installed' => false, 'area' => 0.0, 'id' => 0];
        }
        $area = 0.0;
        if (function_exists('PVF_GetModuleArea')) {
            $area = (float)@PVF_GetModuleArea($iid);
        }
        if ($area <= 0.0) {
            $vid = @IPS_GetObjectIDByIdent('PVF_ModuleArea', $iid);
            if ($vid && IPS_VariableExists($vid)) { $area = (float)GetValue($vid); }
        }
        return ['installed' => true, 'area' => $area, 'id' => $iid];
    }

    private function ColorOrEmpty(int $color): string
    {
        return ($color >= 0) ? sprintf('#%06x', $color) : '';
    }

    private function FontStack(string $font): string
    {
        return trim($font);
    }
}
