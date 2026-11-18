<?php

require_once __DIR__ . '/KostalHttpClient.php';

// ---------------------------------------------------------------------------
// InverterHub — generisches Wechselrichter-Modul mit austauschbaren
// Hersteller-Treibern. Erster Treiber: GoodWe (portiert aus GoodweET).
// Die Treiber, die Modbus-Clients und die Hilfsklassen liegen je Klasse in
// libs/ (eine Klasse, eine Datei, Klassenname = Dateiname) und werden unten
// per require_once in dieser Reihenfolge geladen. Jede Klasse steht genau
// einmal in genau einer Datei: zwei Dateien mit derselben Klasse führen zu
// "Cannot redeclare class". Hier bleibt die Modulklasse InverterHub.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../libs/IHUB_ModbusGatewayClient.php';
require_once __DIR__ . '/../libs/IHUB_ModbusTcpClient.php';
require_once __DIR__ . '/../libs/IHUB_InverterDriverInterface.php';
require_once __DIR__ . '/../libs/IHUB_GoodweDriver.php';
require_once __DIR__ . '/../libs/IHUB_SungrowDriver.php';
require_once __DIR__ . '/../libs/IHUB_SolisDriver.php';
require_once __DIR__ . '/../libs/IHUB_GrowattDriver.php';
require_once __DIR__ . '/../libs/IHUB_SolaxDriver.php';
require_once __DIR__ . '/../libs/IHUB_SmaDriver.php';
require_once __DIR__ . '/../libs/IHUB_FroniusDriver.php';
require_once __DIR__ . '/../libs/IHUB_SolarEdgeDriver.php';
require_once __DIR__ . '/../libs/IHUB_DeyeDriver.php';
require_once __DIR__ . '/../libs/IHUB_SolplanetDriver.php';
require_once __DIR__ . '/../libs/IHUB_KostalDriver.php';
require_once __DIR__ . '/../libs/IHUB_HuaweiDriver.php';
require_once __DIR__ . '/../libs/IHUB_FoxEssDriver.php';
require_once __DIR__ . '/../libs/IHUB_FoxEssSmartDriver.php';
require_once __DIR__ . '/../libs/IHUB_VictronDriver.php';
require_once __DIR__ . '/../libs/IHUB_HoymilesMsa2Driver.php';
require_once __DIR__ . '/../libs/IHUB_RctClient.php';
require_once __DIR__ . '/../libs/IHUB_RctPowerDriver.php';
require_once __DIR__ . '/../libs/IHUB_WriteDiag.php';
require_once __DIR__ . '/../libs/IHUB_Text.php';

class InverterHub extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        IHUB_Text::setzen(function (string $text): string {
            return $this->Translate($text);
        });
    }

    private const DRIVERS = [
        'goodwe'    => 'IHUB_GoodweDriver',
        'sungrow'   => 'IHUB_SungrowDriver',
        'solis'     => 'IHUB_SolisDriver',
        'growatt'   => 'IHUB_GrowattDriver',
        'solax'     => 'IHUB_SolaxDriver',
        'sma'       => 'IHUB_SmaDriver',
        'fronius'   => 'IHUB_FroniusDriver',
        'solaredge' => 'IHUB_SolarEdgeDriver',
        'deye'      => 'IHUB_DeyeDriver',
        'solplanet' => 'IHUB_SolplanetDriver',
        'kostal'    => 'IHUB_KostalDriver',
        'victron'   => 'IHUB_VictronDriver',
        'huawei'    => 'IHUB_HuaweiDriver',
        'foxess'    => 'IHUB_FoxEssDriver',
        'foxess_smart' => 'IHUB_FoxEssSmartDriver',
        'hoymiles_msa2' => 'IHUB_HoymilesMsa2Driver',
        'rct_power' => 'IHUB_RctPowerDriver',
    ];

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-inverterhub-multi-wechselrichter-ein-modbus-tcp-modul-fuer-goodwe-sma-fronius-sungrow-solis-growatt-solax/144121';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    // Eigene Modul-GUID (module.json 'id') - fuer die Geschwister-Suche beim
    // geteilten Ausblenden (SUITE.md "Ausblenden ueber mehrere Instanzen
    // desselben Moduls teilen", Referenzimplementierung MeterHub, EMS 14.09.2026).
    private const SELF_MODULE = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const LICENSE_URL = 'https://github.com/DG65/NRGInverterHub/blob/beta/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // „Was ist neu"-Banner (siehe newsBanner()/AckNews()). Vergleich laeuft
    // gegen den STRING NEWS_VERSION - jede Erhoehung zeigt den Banner erneut,
    // bis der Nutzer ihn (wieder) bestaetigt. Pro-Version-Dismiss ist damit
    // bereits erfuellt (Verbund-Konvention, EMS 24.07.2026); diese Konstante
    // bitte bei jeder nutzerrelevanten Aenderungsrunde mit hochziehen - sie
    // stand seit ihrer Einfuehrung bei 0.45 unveraendert, waehrend das Modul
    // laengst bei 0.72 war.
    private const NEWS_VERSION = '0.80.0';
    private const NATIVE_GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const BRIDGE_GUID = '{901E0B93-83EC-4819-B111-BDEF465BB166}';
    private const MQTT_BRIDGE_GUID = '{B8E699DE-22E4-4DF3-926B-8F4C118852B7}';
    private const MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const NEWS_ITEMS = [
        'GoodWe: two new battery meters from the BMS (total charged / total discharged, kWh). The inverter totals are exactly doubled, the BMS values are reliable, for example to determine the round-trip efficiency.',
        'FoxESS (H3 Smart and related series): new variable "House load power" in W (register 39225/39226). Not yet confirmed on a device, please compare with "Total home" in the Fox app.',
        'Writing to the device is more reliable: read cycles and commands no longer run at the same time on an instance, a failed connection is retried once after a short pause and the error code appears in the warning. Solis now reads over one shared connection. Instances with controls get four read-only variables (ctl_write_*) that show commands which did not arrive.',
        'Kostal PLENTICORE plus Gen2: new connection type "Kostal HTTP API" (read only, preview). It needs no Modbus connection, only IP address, user and password. Contributed by a community member.',
        'RCT Power: new optional groups for battery tower 2, state of health, battery state and the current control state (strategy, external target power). Read only, preview: nothing is written to the inverter.',
        'SMA: new group "PV inputs A and B" with the DC power per input (e.g. for east/west arrays). At night and for an unused input it shows 0 W.',
        'New manufacturer: RCT Power Storage DC (own protocol on port 8899, read only, preview). Values and signs of grid and battery have been confirmed against the RCT app on an RCT Power Storage DC 10.0 (firmware 2.3.5689).',
        'All variables now sit directly under the instance, the subfolders (PV, battery, grid …) are gone (Symcon store requirement). Existing variables are moved up automatically when you apply; their IDs, archive data and links are kept.',
        'New manufacturer: Hoymiles MS-A2 (storage, via MQTT instead of Modbus, preview without device test). Connection type "MQTT" with the new "NRG-Stack InverterHub Bridge (MQTT)" on the Symcon MQTT server; enter the device ID.',
        'Symbox gateway (built-in RS485 port): now runs via the new "NRG-Stack InverterHub Bridge (ModBus gateway)". Under connection type "Symbox gateway" select the ModBus gateway, click "Create bridge and connect", then apply. If you had set up the gateway route directly on the instance with earlier beta versions: instance and history are kept, just select the gateway, click the button and apply. Direct connections are not affected, the note "requires a parent instance" disappears.',
        'New inverter: FoxESS H1/H3 (read-only preview, beta).',
        'FoxESS H3 Smart / H3 Pro / KH: own driver for the newer register layout (holding 39xxx, as in Home Assistant) — read-only preview, please give feedback in the forum.',
        'SMA: several corrections to scaling, battery/PV detection and register access — values are now much more accurate.',
        'Victron: house load calculation corrected (it was too high when grid import was present at the same time).',
        'GoodWe: feed-in limit switch labelled correctly, plus a warning when limiting to 0 W.',
        'Energy meters: wrong archive aggregation type fixed — existing variables are corrected automatically and without data loss.',
        'Power flow tile: unit is chosen automatically (W/kW/MW), a plugged-in wallbox is no longer wrongly greyed out.',
        'Monitoring: new tab "Electricity price" with grid import — if the price module is installed.',
    ];

    private $driver = null;
    // true, wenn der „Energie in Wh"-Schalter seit dem letzten ApplyChanges
    // umgelegt wurde → dann (und nur dann) Energie-Profile neu setzen.
    private $reprofileEnergy = false;

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('SeenNews', '');
        // Merkt den zuletzt angewandten Zustand des „Energie in Wh"-Schalters,
        // um Energie-Profile nur bei echter Umstellung neu zu setzen.
        $this->RegisterAttributeBoolean('LastEnergyUnitWh', false);
        // Zustand der Ueberlaufkorrektur: {unitId: {w: Ueberlaeufe, r: letzter Rohwert}}
        $this->RegisterAttributeString('VictronYieldState', '{}');
        // Kostal HTTP API: Sitzungskennung und Passwort liegen als Attribut (nicht im Formular, nicht als Eigenschaft).
        $this->RegisterAttributeString('KostalHttpSession', '');
        $this->RegisterAttributeString('KostalHttpPassword', '');

        $this->RegisterPropertyBoolean('Active', true);
        // Store-Checkliste Punkt 12 (keine eigene Anlage als Norm): kein Hersteller
        // vorausgewaehlt - eine neue Instanz bleibt inaktiv, bis der Nutzer bewusst
        // waehlt, statt stillschweigend gegen ein GoodWe-Geraet zu sprechen.
        $this->RegisterPropertyString('Manufacturer', '');
        $this->RegisterPropertyBoolean('MeterInvert', false);
        $this->RegisterPropertyBoolean('BatInvert', false);
        // Steuerhoheit dieser Instanz (Verbund-Vertrag IHUB_GetFunctions,
        // EMS-Prioritaetshierarchie Situation A/B in CLAUDE.md). 'ems' = Normalfall,
        // das EMS darf hierher schreiben. 'external' = ein anderer Akteur besitzt
        // den Schreibkanal (z. B. Sunny Home Manager, Tibber->Herstellercloud) -
        // das EMS soll NICHT hierher schreiben. 'none' = niemand soll steuern.
        // Nur relevant, wenn der Treiber ueberhaupt Steuerregister hat
        // (GroupControl); bei reinen Lesetreibern wirkungslos, aber schadet nicht.
        $this->RegisterPropertyString('ControlAuthority', 'ems');
        // Architektur-Entscheidung (Dietmar, 29.08.2026): InverterHub ist REINE
        // Kommunikationsschicht. Jede Steuerungs-POLITIK - Heartbeat/Reassert
        // gegen den GoodWe-255-Totmann, automatischer Fallback auf enable=false,
        // Pendel-Erkennung - liegt beim EMS (dorthin uebergeben), NICHT hier.
        // Frueher hier vorhandene Politik-Properties (EmsReassertEnabled,
        // EmsWriteMode, DeadmanBehavior) wurden ersatzlos entfernt.
        // Anzahl tatsächlich vorhandener MPPT-Eingänge / Solarladeregler.
        // 0 = alle anlegen, die der Treiber kennt (bisheriges Verhalten und
        // Vorgabe, damit bestehende Instanzen unverändert bleiben).
        $this->RegisterPropertyInteger('MpptCount', 0);
        // Energie-Ausgabe in Wh statt kWh (Basiseinheit, konsistent zu W;
        // die neue IPS-Darstellung skaliert dann selbst auf Wh/kWh/MWh).
        $this->RegisterPropertyBoolean('EnergyUnitWh', false);
        // Messwerte automatisch archivieren (Standard an). Ausschalten NUR, wenn
        // diese Instanz Ziel einer Migration aus einem Altmodul (z. B. GoodweET)
        // werden soll: MigrationsHub haengt die Alt-Historie per
        // AC_ChangeVariableID um, und das gelingt nur an einer Variable OHNE
        // eigene Archivhistorie. Wuerde InverterHub sofort mitloggen, waere das
        // Ziel binnen Sekunden "nicht mehr jungfraeulich" und die Migration
        // wuerde blockiert. Nach der Migration schaltet MigrationsHub das Logging
        // am Ziel selbst ein - oder man setzt diesen Schalter dann wieder auf an.
        // Store-Review-Fund (HeishaMon-Sitzung, 28.09.2026, Symcon lehnte v1.33.0 deswegen ab):
        // Standard muss AUS sein - ein Nutzer soll die automatische Archivierung bewusst
        // einschalten, nicht nachtraeglich abwaehlen muessen. Aendert nur den Vorschlagswert
        // fuer NEU angelegte Instanzen, bestehende behalten ihren gespeicherten Wert.
        $this->RegisterPropertyBoolean('AutoArchive', false);
        // Automatische Archiv-Verdichtung (Referenz MeterHub, Store-Reife-Fund
        // Stefan/somm 17.09.2026 - Wechselrichter-Werte laufen ungebremst in
        // voller Aufloesung ins Archiv). Getrennt fuer Momentanwerte (Leistung,
        // schnelles Intervall) und kumulative Zaehler (Energie, langsames
        // Intervall), da beide typischerweise unterschiedliche Update-Takte
        // haben. Vorbelegung bewusst generisch (keine eigene Anlage als Norm):
        // direkt 1x/Min, nach 1 Monat 1x/5 Min, nach 12 Monaten 1x/Std.
        foreach (['Power', 'Energy'] as $kind) {
            $this->RegisterPropertyBoolean('AutoCompaction' . $kind, false);
            $this->RegisterPropertyBoolean('CompactDirect' . $kind, true);
            $this->RegisterPropertyInteger('CompactStage2Months' . $kind, 1);
            $this->RegisterPropertyInteger('CompactStage2Type' . $kind, 1);
            $this->RegisterPropertyInteger('CompactStage3Months' . $kind, 12);
            $this->RegisterPropertyInteger('CompactStage3Type' . $kind, 2);
        }
        // Modbus-Unit-ID des Smart Meters (SunSpec-Hersteller wie Fronius/SMA/
        // SolarEdge: der Zähler ist ein eigenes Modbus-Gerät, Adresse ab 200,
        // je nach Konfiguration z. B. auch 240).
        $this->RegisterPropertyInteger('MeterUnitId', 200);
        // Float32-Wortreihenfolge bei Kostal. 0 = CDAB (little-endian „Standard
        // Modbus", Werkseinstellung Plenticore), 1 = ABCD (big-endian/SunSpec).
        // Falsche Wahl liefert unbrauchbare Werte (riesige/negative Zahlen).
        $this->RegisterPropertyInteger('KostalByteOrder', 0);
        $this->RegisterPropertyString('KostalHttpUser', 'user');
        $this->RegisterPropertyBoolean('KostalPv3Battery', false);
        // Victron: Unit-IDs der einzelnen Solarladeregler (MPPT), kommagetrennt.
        $this->RegisterPropertyString('VictronMpptUnitIds', '');
        // Unit-ID des Victron-Netzzaehlers (com.victronenergy.grid).
        // 0 = nicht konfiguriert; Netz-Zaehlerstaende entfallen dann.
        $this->RegisterPropertyInteger('VictronGridUnitId', 0);
        // Ueberlaufkorrektur des Solarertrags (Reg 790 ist nur 16 Bit).
        $this->RegisterPropertyBoolean('VictronYieldFix', false);
        // Ist-Werte je Laderegler in kWh, Reihenfolge wie die MPPT-Unit-IDs.
        $this->RegisterPropertyString('VictronYieldCalib', '');
        $this->RegisterPropertyInteger('HouseLoadMeterID', 0);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 247);
        // SUITE.md 9j: Verbindungsweg zusätzlich zum direkten TCP-Weg, kein Ersatz.
        // 'gateway' ist aktuell ein Stub (siehe IHUB_ModbusGatewayClient) - liefert
        // bewusst keine echten Werte, bis das native ForwardData()-Payload-Schema
        // geklärt ist.
        $this->RegisterPropertyString('ConnectionType', 'direct');
        // Bruecke zum nativen ModBus-Gateway (Modul InverterHubBridge). Nur im
        // Gateway-Modus relevant; 0 = keine gewaehlt.
        $this->RegisterPropertyInteger('BridgeInstanceID', 0);
        // Nur Auswahlfeld fuer den Knopf "Brücke anlegen und verbinden": muss eine
        // registrierte Property sein, damit $GatewayPick im onClick ankommt.
        $this->RegisterPropertyInteger('GatewayPick', 0);
        // MQTT-Verbindungsweg (Hoymiles MS-A2): Geräte-ID und Auswahlfeld fuer den Knopf "MQTT-Brücke anlegen".
        $this->RegisterPropertyString('MqttDeviceID', '');
        $this->RegisterPropertyInteger('MqttServerPick', 0);
        $this->RegisterPropertyInteger('IntervalFast', 5);
        $this->RegisterPropertyInteger('IntervalSlow', 300);
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);

        // Treiber-spezifische Properties werden hier für ALLE Treiber (nicht nur
        // den aktuell gewählten) registriert. Grund: Create() legt die Properties
        // einmalig zum Erstellungszeitpunkt an, aber der tatsächlich gewünschte
        // Manufacturer-Wert (z.B. vom Discovery-Modul mitgegeben) wird oft erst
        // NACH Create() über die Konfiguration gesetzt. Würden nur die Properties
        // des zu diesem Zeitpunkt aktiven (Default-)Treibers registriert, fehlten
        // bei jedem anderen Hersteller sämtliche Datenpunkt-Gruppen-Checkboxen
        // ("Eigenschaft GroupPV nicht gefunden" etc.) — ungenutzte Properties
        // anderer Treiber bleiben einfach unbenutzt, das ist unschädlich.
        $allProps = [];
        foreach (self::DRIVERS as $driverClass) {
            $drv = new $driverClass();
            foreach ($drv->getExtraBooleanProperties() as $name => $default) {
                if (!array_key_exists($name, $allProps)) {
                    $allProps[$name] = $default;
                }
            }
            foreach ($drv->getOptionalGroups() as $propName => $group) {
                if (!array_key_exists($propName, $allProps)) {
                    $allProps[$propName] = true;
                }
            }
        }
        foreach ($allProps as $name => $default) {
            $this->RegisterPropertyBoolean($name, $default);
        }

        $this->RegisterTimer('FastTimer', 0, 'IHUB_ReadFast($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlowTimer', 0, 'IHUB_ReadSlow($_IPS[\'TARGET\']);');
        // IPS_SetVariableCustomAction() schlägt fehl, wenn die Instanz noch
        // innerhalb derselben Erstellungs-/Konfigurations-Transaktion nicht
        // als gültiges Ziel sichtbar ist (z.B. wenn ein Configurator-Modul
        // Instanz+Konfiguration in einem einzigen synchronen Ablauf anlegt —
        // da hilft auch "erst beim zweiten ApplyChanges" nichts, weil beide
        // Aufrufe noch zur selben Transaktion gehören). Custom Actions
        // werden deshalb einmalig per Kurz-Timer NACH der Transaktion gesetzt.
        $this->RegisterTimer('EnableActionsTimer', 0, 'IHUB_EnableActions($_IPS[\'TARGET\']);');

        $this->RegisterAttributeBoolean('DeviceInfoRead', false);
        $this->RegisterAttributeBoolean('ImplausibleLogged', false);
    }

    // Ein Gerät, das auf Adressen antwortet, die es gar nicht belegt, liefert
    // reihenweise 0xFFFF - den Modbus-/SunSpec-Sentinel „nicht implementiert".
    // Häufigste Ursache: In der Instanz ist der falsche Hersteller eingestellt,
    // dann werden fremde Registeradressen abgefragt und das Gerät quittiert sie
    // höflich mit lauter Einsen. Ohne diese Prüfung landen daraus Geisterwerte
    // in den Variablen (65535 %, 6553,5 V, 4294967295 W, Strings aus „ÿ").
    // Gemeldet wird einmal pro Instanzstart, damit das Log nicht vollläuft.
    // Warnung ins Meldungslog, aus einem Treiber heraus aufrufbar.
    public function WarnUser(string $message)
    {
        $this->LogMessage($message, KL_WARNING);
        if ($this->inWriteCall && $this->writeFailure === '') {
            $this->writeFailure = $message;
        }
    }

    public function BlockLooksUnset(array $regs): bool
    {
        $n = count($regs);
        if ($n < 8) {
            return false;   // zu kurz für eine belastbare Aussage
        }
        $unset = 0;
        foreach ($regs as $r) {
            if (($r & 0xFFFF) === 0xFFFF) {
                $unset++;
            }
        }
        if ($unset * 5 < $n * 4) {
            // Unter 80 % - normaler Betrieb, ggf. gemeldeten Zustand zurücknehmen.
            if ($this->ReadAttributeBoolean('ImplausibleLogged')) {
                $this->WriteAttributeBoolean('ImplausibleLogged', false);
            }
            return false;
        }
        if (!$this->ReadAttributeBoolean('ImplausibleLogged')) {
            $this->WriteAttributeBoolean('ImplausibleLogged', true);
            $this->LogMessage(
                IHUB_Text::t('The device answers the requested register range only with 0xFFFF ("register not in use"). It is reachable but delivers no measured values. Please check the manufacturer and the unit ID in the instance settings - usually a different manufacturer is set than the device actually is.'),
                KL_WARNING
            );
        }
        return true;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Store-Checkliste Punkt 12: kein Hersteller gewaehlt -> Instanz bleibt
        // inaktiv statt mit einem falschen Default-Treiber (z. B. GoodWe) gegen
        // ein fremdes Geraet zu sprechen. Muss VOR RegisterVariables() greifen,
        // da GetDriver() sonst mangels Auswahl auf GoodWe zurueckfallen wuerde.
        if ($this->ReadPropertyString('Manufacturer') === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }

        $this->CreateProfiles();
        // Energie-Profile nur neu setzen, wenn der Wh-Schalter umgelegt wurde -
        // sonst würde jedes „Übernehmen" eine vom Nutzer gewählte Presentation
        // (IPS 7 „Wertanzeige") auf das Legacy-Profil zurücksetzen.
        $wh = $this->ReadPropertyBoolean('EnergyUnitWh');
        $this->reprofileEnergy = ($this->ReadAttributeBoolean('LastEnergyUnitWh') !== $wh);
        $this->RegisterVariables();
        $this->WriteAttributeBoolean('LastEnergyUnitWh', $wh);
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }

        // Im Gateway-Modus ist Host ausgeblendet und leer - die Verbindung laeuft
        // ueber den Parent, nicht ueber einen eigenen Socket (MeterHub-Fund,
        // Forum-Beta-Tester 19.09.2026: Instanz blieb sonst dauerhaft auf 104).
        $gatewayMode = ($this->ReadPropertyString('ConnectionType') === 'gateway');
        if ($gatewayMode && $this->ReadPropertyInteger('BridgeInstanceID') <= 0) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }
        $httpMode = ($this->ReadPropertyString('ConnectionType') === 'kostal_http');
        if ($httpMode && ($this->ReadPropertyString('Manufacturer') !== 'kostal'
            || trim($this->ReadAttributeString('KostalHttpPassword')) === ''
            || trim($this->ReadPropertyString('KostalHttpUser')) === '')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }
        $mqttMode = ($this->ReadPropertyString('ConnectionType') === 'mqtt');
        if ($mqttMode && ($this->ReadPropertyString('Manufacturer') !== 'hoymiles_msa2'
            || $this->ReadPropertyInteger('BridgeInstanceID') <= 0
            || trim($this->ReadPropertyString('MqttDeviceID')) === '')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }
        $host = $this->ReadPropertyString('Host');
        if ($host === '' && !$gatewayMode && !$mqttMode) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }

        // RCT: Takt mindestens 30 s, damit die RCT-App oder Home Assistant parallel nicht verdrängt werden.
        $fast = $this->ReadPropertyInteger('IntervalFast');
        if ($httpMode) { $fast = max(10, $fast); }
        if ($this->ReadPropertyString('Manufacturer') === 'rct_power') {
            $fast = max($fast, 30);
        }
        $this->SetTimerInterval('FastTimer', $fast * 1000);
        $this->SetTimerInterval('SlowTimer', $this->ReadPropertyInteger('IntervalSlow') * 1000);
        $this->SetTimerInterval('EnableActionsTimer', 200);
        $this->WriteAttributeBoolean('DeviceInfoRead', false);
        if ($mqttMode) {
            // Die Bruecke baut ihren Empfangsfilter aus der Geraete-ID dieser Instanz.
            $bridgeId = $this->ReadPropertyInteger('BridgeInstanceID');
            if (IPS_InstanceExists($bridgeId) && function_exists('IHUBM_Refresh')) {
                @IHUBM_Refresh($bridgeId);
            }
        }
        $this->SetStatus(102);
    }

    // Wird kurz nach ApplyChanges einmalig vom EnableActionsTimer aufgerufen,
    // sobald die Instanz die Erstellungstransaktion sicher verlassen hat.
    //
    // Live-Fehler (25./26.07.2026): IPS_SetVariableCustomAction() ist die
    // falsche API fuer Variablen, die die eigene Instanz selbst per
    // RegisterVariableXXX angelegt hat - sie schlaegt fuer diese immer fehl
    // (auch ausserhalb jeder Transaktion, auch bei druck-frisch angelegten
    // Variablen), weil eine modul-eigene Variable ihren nativen Action-Slot
    // ueber $this->EnableAction() bekommt, nicht ueber die globale Funktion.
    // IPS_SetVariableCustomAction() ist fuer FREMDE/nicht modul-eigene
    // Variablen gedacht. Ohne diesen Fix blieb "VariableAction" dauerhaft 0,
    // WebFront-Klicks auf die Steuer-Schalter scheiterten mit "Action is
    // invalid" - waehrend IPS_RequestAction() aus einem Skript (Kernel-Aufruf
    // direkt an die Instanz) unbeeinflusst funktionierte.
    //
    // Live-Fehler (26.07.2026, EMS/Dietmar): Der einmalige Kurz-Timer-Aufruf
    // wirkte "fragil" (Bindung nach GroupControl/ControlAuthority-Aenderung
    // wieder weg) - ein periodischer ReadFast()-Aufruf wurde testweise
    // eingefuehrt (228a6b4) und wieder entfernt, weil er selbst spontane
    // ID-Vergabe-Probleme reproduzierte (2e5d0aa). Tatsaechliche Ursache erst
    // spaeter gefunden: EnableAction() findet die Variable nur als DIREKTES
    // Kind der Instanz, aber Steuervariablen werden in die Unterkategorie
    // "EMS-Steuerung" verschoben - jede (Re-)Bindung nach Reparenting schlug
    // dadurch fehl. Fix s. u. (kurz zurueckhaengen, binden, zurueck).
    public function EnableActions()
    {
        $this->SetTimerInterval('EnableActionsTimer', 0);

        $driver = $this->GetDriver();
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                if ($v[5] === 'control') {
                    $vid = $this->FindVarByIdent($v[0]);
                    if ($vid && @IPS_GetVariable($vid)['VariableAction'] !== $this->InstanceID) {
                        // EnableAction() findet die Variable nur als DIREKTES Kind der
                        // Instanz - seit 0.78.0 liegen alle Variablen dort. Variablen aus
                        // einer Altinstallation werden vorher heraufgehaengt.
                        if ((int)IPS_GetObject($vid)['ParentID'] !== $this->InstanceID) {
                            IPS_SetParent($vid, $this->InstanceID);
                        }
                        $this->EnableAction($v[0]);
                    }
                }
            }
        }
    }

    // Hinweis (Architektur-Entscheidung Dietmar, 29.08.2026): Der fruehere
    // Reassert-/Heartbeat-Mechanismus gegen den GoodWe-255-Totmann
    // (EMS_REASSERT_IDENTS, ReassertEmsControl(), LastCommanded_/
    // LastCommandTime-Attribute) wurde ersatzlos ENTFERNT - InverterHub ist
    // reine Kommunikationsschicht, jede Steuerungs-Politik liegt beim EMS.
    // Historie/Begruendung: CLAUDE.md, Abschnitt "255 entschluesselt".

    // Verbund-Konvention "Sichtbare Rueckmeldung bei jeder Aktion" (20.08.2026,
    // SUITE.md Punkt 13, Referenz EMS BuildDayPlan): der "Verbindung testen /
    // Daten sofort lesen"-Button ruft dieselbe Methode wie der periodische
    // FastTimer auf - der Rueckgabewert wird dabei stumm verworfen, das
    // Button-onClick nutzt ihn per echo als sichtbaren Erfolgs-/Fehlertext.
    // Sperre je Instanz fuer alle Zugriffe auf das Geraet (Lesezyklen und Schreibbefehle), fuer jeden
    // Hersteller und jede Verbindungsart (15.11.2026, Pauls Solis: Datenlogger mit nur einer Verbindung,
    // Schreibbefehle scheiterten mit "keine Verbindung", weil der Lesezyklus gleichzeitig verbunden war).
    // Nicht wiedereintrittsfaehig in IPS, daher die Tiefe. Die Sperre hat ein Zeitlimit: Wer sie nicht
    // bekommt, liest in diesem Zyklus nicht (Lesen) bzw. schreibt trotzdem (Schreiben), damit ein
    // haengender Lesezyklus nie dauerhaft blockiert. Haelt ein Lauf die Sperre laenger als
    // LOCK_STALE_SEC, gilt sie als verwaist und wird ignoriert.
    const LOCK_WAIT_READ_MS  = 8000;
    const LOCK_WAIT_WRITE_MS = 20000;
    const LOCK_STALE_SEC     = 45;
    private $ihubLockDepth = 0;

    private function LockName(): string
    {
        return 'IHUB_IO_' . $this->InstanceID;
    }

    private function LockEnter(int $waitMs): bool
    {
        if ($this->ihubLockDepth > 0) {
            $this->ihubLockDepth++;
            return true;
        }
        if (!function_exists('IPS_SemaphoreEnter') || !IPS_SemaphoreEnter($this->LockName(), $waitMs)) {
            return false;
        }
        $this->ihubLockDepth = 1;
        $this->SetBuffer('IoLockSince', (string)time());
        return true;
    }

    private function LockLeave(): void
    {
        if ($this->ihubLockDepth <= 0) {
            return;
        }
        $this->ihubLockDepth--;
        if ($this->ihubLockDepth === 0) {
            $this->SetBuffer('IoLockSince', '');
            IPS_SemaphoreLeave($this->LockName());
        }
    }

    private function LockIsStale(): bool
    {
        $since = (int)$this->GetBuffer('IoLockSince');
        return $since > 0 && (time() - $since) > self::LOCK_STALE_SEC;
    }

    public function ReadFast(): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return IHUB_Text::t('ℹ️ Instance is disabled ("Active" switch).');
        }
        $held = $this->LockEnter(self::LOCK_WAIT_READ_MS);
        if (!$held && function_exists('IPS_SemaphoreEnter') && !$this->LockIsStale()) {
            $this->SendDebug('Lock', 'Read cycle skipped, a command is being written', 0);
            return IHUB_Text::t('ℹ️ Read cycle skipped: a command is being written to the device.');
        }
        try {
            return $this->ReadFastUnlocked();
        } finally {
            if ($held) {
                $this->LockLeave();
            }
        }
    }

    private function ReadFastUnlocked(): string
    {
        try {
            $driver = $this->GetDriver();
            $mb = $this->GetModbusClient();
            if (!$this->ReadAttributeBoolean('DeviceInfoRead')) {
                $driver->readDeviceInfo($mb, $this);
                $this->WriteAttributeBoolean('DeviceInfoRead', true);
            }
            $ok = $driver->readFast($mb, $this);
            if ($mb instanceof IHUB_KostalHttpClient) {
                $http = $mb;
                $missing = $http->getMissingFor($driver, $this);
                // Real HTTP communication is not an error merely because an
                // optional hardware-dependent API point is absent.
                if ($ok !== true || isset($missing['pv_total']) || isset($missing['ac_power'])) {
                    $this->SetStatus(201);
                    throw new RuntimeException('Kostal HTTP API core data missing: ' . json_encode($missing, JSON_UNESCAPED_SLASHES));
                }
                $this->SetStatus(102);
                if ($missing !== []) {
                    $details = json_encode($missing, JSON_UNESCAPED_SLASHES);
                    $this->SendDebug('Kostal HTTP missing points', $details, 0);
                    return IHUB_Text::t('⚠️ Kostal HTTP reachable, %d enabled data points not available: %s', count($missing), $details);
                }
            }
        } catch (Throwable $e) {
            if ($this->ReadPropertyString('ConnectionType') === 'kostal_http') { $this->SetStatus(201); }
            $msg = IHUB_Text::t('⚠️ Connection failed: %s', $e->getMessage());
            $this->LogReadProblem($msg);
            return $msg;
        }
        // MQTT-Modus (Hoymiles MS-A2): nichts wird abgefragt; "verbunden" heisst, dass noch
        // Nachrichten des Geraets eintreffen.
        if ($this->ReadPropertyString('ConnectionType') === 'mqtt') {
            $this->EnableActions();
            $age = $this->MqttAge();
            if ($age < 0) {
                $this->SetStatus(201);
                return IHUB_Text::t('⚠️ No MQTT message from the device received yet. Check the device ID, the MQTT access on the device and the bridge.');
            }
            if ($age > IHUB_HoymilesMsa2Driver::STALE_SECONDS) {
                $this->SetStatus(201);
                return IHUB_Text::t('⚠️ Last MQTT message %d s ago - the device is no longer sending.', $age);
            }
            $this->SetStatus(102);
            return IHUB_Text::t('✅ MQTT: last message %d s ago.', $age);
        }
        // Gateway-Modus: "Verbindung aktiv" haengt sonst nur an ApplyChanges und sagt
        // nichts ueber echte Antworten. Ohne verbundenes Gateway oder bei falscher
        // Geraete-ID am Gateway kommt nichts zurueck (MeterHub-Hinweis, 19.09.2026).
        if ($mb instanceof IHUB_ModbusGatewayClient) {
            if ($mb->requests > 0 && $mb->responses === 0) {
                $this->SetStatus(201);
                $msg = $this->GatewayErrorText($this->GetBuffer('GatewayError'));
                $this->LogReadProblem($msg);
                return $msg;
            }
            if ($mb->responses > 0) {
                $this->SetStatus(102);
            }
        }
        // Periodische Selbstheilung der Steuer-Bindung (wieder eingefuehrt
        // 27.07.2026, mit Dietmars/EMS' ausdruecklicher Freigabe, nach
        // mehrstuendiger Beobachtung vor Entfernung des SUITE.md-Warnhinweises
        // geplant). Vorgeschichte: Ein erster Versuch (228a6b4, 26.07.2026)
        // wurde wieder entfernt (2e5d0aa), weil damals der Verdacht bestand,
        // wiederholte EnableAction()-Aufrufe koennten selbst Neuanlagen/
        // ID-Churn ausloesen - Ursache damals nicht verstanden.
        // Inzwischen IST die Ursache bekannt und behoben (2d8228f):
        // EnableAction() bindet eine Variable nur, wenn sie DIREKTES Kind der
        // Instanz ist; RegisterVar() verschiebt Steuervariablen aber sofort
        // in die Unterkategorie "EMS-Steuerung" - jede Bindung nach diesem
        // Verschieben schlug deshalb fehl, unabhaengig von der Aufrufhaeufigkeit.
        // EnableActions() selbst legt NIE eine Variable an (nur FindVarByIdent
        // + bedingtes EnableAction), ist bei bereits korrekt gebundenen
        // Variablen ein reiner, billiger No-Op (VariableAction-Pruefung) und
        // kann daher sicher jeden Zyklus laufen - zieht eine verlorene Bindung
        // (z. B. nach einer Property-Aenderung wie ControlAuthority) spaetestens
        // beim naechsten ReadFast (typ. 5 s) automatisch nach, ohne dass dafuer
        // eine Diagnose-Sitzung noetig ist (Verbund-Ziel 3, SUITE.md).
        $this->EnableActions();
        $this->ClearReadProblem();
        return IHUB_Text::t('✅ Connection ok, data read (%s).', date('H:i:s'));
    }

    // Der Timer verwirft den Rueckgabetext von ReadFast() - nur der Knopf zeigt ihn. Ein
    // Fehler im Timer-Lauf blieb deshalb unsichtbar (Forum-Beta-Tester Mstaudi,
    // 19.09.2026: "meldet alles ok, aktualisiert aber nicht"). Jetzt einmalig ins
    // Meldungsfenster, nicht alle paar Sekunden: erneut erst bei geaendertem Text.
    private function LogReadProblem(string $msg)
    {
        if ($this->GetBuffer('LastReadProblem') === $msg) {
            return;
        }
        $this->SetBuffer('LastReadProblem', $msg);
        $this->LogMessage($msg, KL_ERROR);
    }

    private function ClearReadProblem()
    {
        if ($this->GetBuffer('LastReadProblem') !== '') {
            $this->SetBuffer('LastReadProblem', '');
            $this->LogMessage(IHUB_Text::t('✅ Read cycle is running again.'), KL_MESSAGE);
        }
    }

    public function ReadSlow()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $held = $this->LockEnter(self::LOCK_WAIT_READ_MS);
        if (!$held && function_exists('IPS_SemaphoreEnter') && !$this->LockIsStale()) {
            $this->SendDebug('Lock', 'Slow read skipped, a command is being written', 0);
            return;
        }
        try {
            $this->ReadSlowUnlocked();
        } finally {
            if ($held) {
                $this->LockLeave();
            }
        }
    }

    private function ReadSlowUnlocked()
    {
        try {
            $mb = $this->GetModbusClient();
            $this->GetDriver()->readSlow($mb, $this);
        } catch (Throwable $e) {
            $this->LogReadProblem(IHUB_Text::t('⚠️ Slow read failed: %s', $e->getMessage()));
            if ($this->ReadPropertyString('ConnectionType') === 'kostal_http') { $this->SetStatus(201); }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'ConnectionTypeChanged') {
            $direct = ($Value === 'direct');
            $http = ($Value === 'kostal_http');
            $gateway = ($Value === 'gateway');
            $mqtt = ($Value === 'mqtt');
            $this->UpdateFormField('KostalHttpUser', 'visible', $http);
            $this->UpdateFormField('KostalHttpPasswordInput', 'visible', $http);
            $this->UpdateFormField('KostalHttpPasswordSave', 'visible', $http);
            $this->UpdateFormField('KostalHttpPasswordState', 'visible', $http);
            $this->UpdateFormField('KostalPv3Battery', 'visible', $http);
            $this->UpdateFormField('KostalHttpModeHelp', 'visible', $http);
            $this->UpdateFormField('GatewayModeHelp', 'visible', $gateway);
            $this->UpdateFormField('KostalModbusDocHelp', 'visible', !$http);
            $this->UpdateFormField('KostalHttpDocHelp', 'visible', $http);
            $this->UpdateFormField('Host', 'visible', $direct || $http);
            $this->UpdateFormField('Port', 'visible', $direct);
            $this->UpdateFormField('UnitId', 'visible', $direct);
            $this->UpdateFormField('BridgeInstanceID', 'visible', $gateway || $mqtt);
            $this->UpdateFormField('GatewayPick', 'visible', $gateway);
            $this->UpdateFormField('CreateBridgeButton', 'visible', $gateway);
            $this->UpdateFormField('BridgeStatus', 'visible', $gateway || $mqtt);
            $this->UpdateFormField('MqttDeviceID', 'visible', $mqtt);
            $this->UpdateFormField('MqttServerPick', 'visible', $mqtt);
            $this->UpdateFormField('CreateMqttBridgeButton', 'visible', $mqtt);
            return;
        }
        if ($Ident === 'BridgeChanged') {
            $this->UpdateFormField('BridgeStatus', 'caption', $this->BridgeStatusLine((int)$Value));
            return;
        }
        if ($Ident === 'CompactionNow') {
            $this->UpdateFormField('CompactNowResult', 'caption', $this->ApplyCompactionNow());
            return;
        }
        if ($Ident === 'ArchiveNow') {
            $this->UpdateFormField('ArchiveNowResult', 'caption', $this->ArchiveAllNow());
            $this->UpdateFormField('AutoArchive', 'value', true);
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        // Steuerhoheits-Schutz: Nur bei 'ems' schreibt dieses Modul ueberhaupt
        // an den Wechselrichter. Ist die Instanz als 'external'/'none' markiert
        // (ein anderer Akteur besitzt den Schreibkanal, z. B. Sunny Home
        // Manager), wird der Schreibzugriff verweigert - auch wenn irgendein
        // Aufrufer (Skript, fehlerhafte EMS-Logik) es versucht. Verteidigung in
        // der Tiefe: Das EMS soll controlAuthority selbst pruefen, aber ein
        // Bug dort darf hier trotzdem nicht zu einer ungewollten Schreibaktion
        // fuehren.
        if ($this->ReadPropertyString('ControlAuthority') !== 'ems') {
            $this->LogMessage(
                IHUB_Text::t('Write access to "%s" denied: this instance is marked as "%s" (control authority is not with the EMS). If the EMS is to control here, set "Control authority" to "EMS" in the instance settings.', $Ident, $this->ReadPropertyString('ControlAuthority')),
                KL_WARNING
            );
            return;
        }
        $held = $this->LockEnter(self::LOCK_WAIT_WRITE_MS);
        if (!$held && function_exists('IPS_SemaphoreEnter')) {
            // Haengender Lesezyklus: Der Befehl wird trotzdem geschrieben, damit er nicht verloren geht.
            $this->LogMessage(IHUB_Text::t('The read cycle did not release the device within %s s, command "%s" is written anyway.', (int)(self::LOCK_WAIT_WRITE_MS / 1000), $Ident), KL_WARNING);
        }
        $this->writeFailure = '';
        $this->inWriteCall = true;
        try {
            $this->GetDriver()->writeControl($this->GetModbusClient(), $this, $Ident, $Value);
        } catch (Throwable $e) {
            $this->writeFailure = $e->getMessage();
            $this->RecordWriteResult($Ident, $this->writeFailure);
            throw $e;
        } finally {
            $this->inWriteCall = false;
            if ($held) {
                $this->LockLeave();
            }
        }
        $this->RecordWriteResult($Ident, $this->writeFailure);
    }

    // Verfolgung endgueltig nicht angekommener Befehle (15.11.2026): Meldet ein Treiber waehrend eines
    // Schreibbefehls eine Warnung (WarnUser), gilt der Befehl als nicht ausgefuehrt. Nur Lesen fuer das EMS:
    // Zaehler, Zeitpunkte und letzter Text stehen in den Variablen ctl_write_* (nur bei Treibern mit Steuerung).
    private $inWriteCall = false;
    private $writeFailure = '';

    private function RecordWriteResult(string $ident, string $failure): void
    {
        if ($this->FindVarByIdent('ctl_write_fail_count') === 0) {
            return;
        }
        if ($failure === '') {
            $this->SetVarInt('ctl_write_ok_time', time());
            return;
        }
        $this->SetVarInt('ctl_write_fail_count', $this->GetVarInt('ctl_write_fail_count') + 1);
        $this->SetVarInt('ctl_write_fail_time', time());
        $vid = $this->FindVarByIdent('ctl_write_fail_text');
        if ($vid) {
            SetValueString($vid, mb_substr($ident . ': ' . $failure, 0, 250));
        }
    }

    // Verbund-Vertrag mit MigrationsHub (29.07.2026): liefert je Fremdmodul-GUID
    // eine Alt-Ident -> Neu-Ident/-Typ-Zuordnung, damit MigrationsHub Alt-
    // Variablen VOR unserem eigenen ApplyChanges()-Zyklus per IPS_SetParent/
    // IPS_SetIdent korrekt umbenennen kann - unsere eigene RegisterVariables()
    // (Reihenfolge: $valid berechnen -> PruneForeignObjects($valid) ->
    // RegisterVar() je Variable) erkennt bereits korrekt benannte Variablen
    // dann selbst als "existiert schon", ohne dass wir selbst reparenten
    // muessen (s. Absprache im Verbund-Chat). Rein informativ, keine
    // Objektaenderung hier.
    //
    // GoodweET (unser einziger bisheriger Migrationsfall, unser GoodWe-Treiber
    // wurde daraus 1:1 portiert): Zuordnung hier BEWUSST NOCH LEER - das
    // GoodweET-Repository ist inzwischen entfernt/nicht mehr erreichbar
    // (Adoption war laut SUITE.md bereits vollstaendig abgeschlossen), wir
    // haben also keine verifizierbare Quelle mehr fuer die exakte GUID und
    // die tatsaechlichen Alt-Idents. Lieber eine ehrliche Leermeldung als
    // eine geratene, unverifizierte Zuordnung - MigrationsHubs Preflight-
    // Sonde bleibt bis dahin der Sicherheitsweg. Sollte die GoodweET-GUID/
    // ihre Ident-Namen irgendwann verifiziert vorliegen (z. B. von Dietmar
    // oder aus einer alten Sicherung), hier ergaenzen.
    public function GetIdentMapping(string $foreignModuleGUID, array $foreignIdents): array
    {
        // Noch keine verifizierten Zuordnungen hinterlegt (s. Kommentar oben).
        return [];
    }

    // Verbund-Vertrag fuer das EMS und andere Konsumenten (analog MHUB_GetFunctions).
    // Liefert Identitaet, Steuerfaehigkeit und die wichtigsten Variablen-IDs dieser
    // InverterHub-Instanz, damit ein Konsument nicht selbst nach Idents suchen muss.
    public function GetFunctions(): array
    {
        $driver = $this->GetDriver();
        $hasControl = array_key_exists('GroupControl', $driver->getOptionalGroups());
        $find = function (string $ident): int {
            $vid = $this->FindVarByIdent($ident);
            return $vid ?: 0;
        };
        // Batterie-Block-Details (Dietmar, 28.08.2026, ueber NRGDashboard
        // angefragt: bei Mehrblock-Batterien - z.B. Dietmars eigene Anlage
        // mit 2 Tuermen - interessieren Temperatur/SOC/SOH je Block, nicht
        // nur der aggregierte Gesamtwert). bat1_*/bat2_*-Idents existieren
        // bislang nur beim GoodWe-Treiber; FindVarByIdent() sucht generisch
        // im gesamten Objektbaum der Instanz, liefert bei anderen Treibern
        // schlicht 0 zurueck - kein Treiber-Sonderfall noetig. Bis zu 4
        // Bloecke vorgesehen (aktuell keine bat3_/bat4_-Idents vorhanden,
        // defensiv fuer kuenftige Treiber mit mehr Bloecken). Nur belegte
        // IDs (> 0) landen im Ergebnis - additiv, kein Konsument muss auf
        // fehlende Bloecke pruefen, ein leeres Array ist der Normalfall bei
        // Einzelblock-/Nicht-GoodWe-Anlagen.
        $batteryTempIDs = [];
        $batterySocIDs = [];
        $batterySohIDs = [];
        for ($i = 1; $i <= 4; $i++) {
            $t = $find('bat' . $i . '_temp');
            $s = $find('bat' . $i . '_soc');
            $h = $find('bat' . $i . '_soh');
            if ($t > 0) {
                $batteryTempIDs[] = $t;
            }
            if ($s > 0) {
                $batterySocIDs[] = $s;
            }
            if ($h > 0) {
                $batterySohIDs[] = $h;
            }
        }
        // MPPT-Strang-Details (Dietmar, 28.08.2026, ueber NRGDashboard
        // angefragt: "eine Tabelle mit allen relevanten Stromwerten ...
        // z.B. auch fuer die MPPTs"). Ident-Schreibweise unterscheidet sich
        // je Treiber (GoodWe: mppt1_current, Sungrow/Victron: mppt1_curr/
        // mppt1_volt) - beide Varianten je Index probieren, $find() liefert
        // bei Nichttreffer ohnehin 0. Bis zu 4 Straenge (aktuell hoechste
        // beobachtete Anzahl, z.B. Sungrow).
        $mpptPowerIDs = [];
        $mpptCurrentIDs = [];
        $mpptVoltageIDs = [];
        for ($i = 1; $i <= 4; $i++) {
            $p = $find('mppt' . $i . '_power');
            $c = $find('mppt' . $i . '_current') ?: $find('mppt' . $i . '_curr');
            $v = $find('mppt' . $i . '_volt');
            // Treiber ohne eigene mpptN_curr/-volt, aber mit berechneter mpptN_power (Solis): Strom und Spannung
            // des Eingangs stehen unter pvN_curr/pvN_volt. Nur mit vorhandener MPPT-Leistung, damit andere
            // Treiber (z. B. GoodWe, zwei Strings je MPPT) nicht falsch zugeordnet werden.
            if ($p > 0) {
                $c = $c ?: $find('pv' . $i . '_curr');
                $v = $v ?: $find('pv' . $i . '_volt');
            }
            if ($p > 0) {
                $mpptPowerIDs[] = $p;
            }
            if ($c > 0) {
                $mpptCurrentIDs[] = $c;
            }
            if ($v > 0) {
                $mpptVoltageIDs[] = $v;
            }
        }
        // Netzdienlichkeits-Faehigkeiten (EMS-Vertrag, 12.09.2026): generisch
        // ueber die Existenz der svc_*-Idents erkannt, kein Treiber-Sonderfall
        // noetig - ein neuer Treiber, der diese Idents registriert (siehe
        // GoodWe GroupControl), taucht automatisch mit auf. Aktuell nur beim
        // GoodWe-Treiber vorhanden; leeres Array bei allen anderen 14.
        $gridServiceCapabilities = [];
        if ($find('svc_charge_inhibit') > 0) {
            $gridServiceCapabilities[] = 'chargeInhibit';
        }
        if ($find('svc_grid_charge_w') > 0) {
            $gridServiceCapabilities[] = 'gridCharge';
        }
        if ($find('svc_discharge_to_grid_w') > 0) {
            $gridServiceCapabilities[] = 'dischargeToGrid';
        }
        if ($find('svc_discharge_inhibit') > 0) {
            $gridServiceCapabilities[] = 'dischargeInhibit';
        }
        if ($find('svc_release') > 0) {
            $gridServiceCapabilities[] = 'release';
        }
        return [
            // 1.0 -> 1.1: batteryTempIDs/batterySocIDs/batterySohIDs/
            // batteryCapacityID. 1.1 -> 1.2: mpptPowerIDs/mpptCurrentIDs/
            // mpptVoltageIDs. 1.2 -> 1.3: gridServiceCapabilities (EMS-Vertrag,
            // 12.09.2026, Netzdienlich-Konzept). Alles additiv, kein Feld
            // entfernt/umbenannt/umgedeutet, Major bleibt unveraendert (siehe
            // CLAUDE.md "Vertragsversionierung"). 1.3 -> 1.4: houseLoadID (Dashboard-Wunsch
            // 19.10.2026, gemessener Gesamtverbrauch; bisher nur Solis: house_load_total).
            // 1.5 -> 1.6: batteryChargeEnergyID/batteryDischargeEnergyID (EMS-Wunsch 17.11.2026,
            // belastbare Batterie-Gesamtzaehler kWh, bisher nur GoodWe: bms_e_*_total; sonst 0).
            'contractVersion'  => '1.6',
            'houseLoadID'      => $find('house_load_total'),
            'gridServiceCapabilities' => $gridServiceCapabilities,
            'instanceID'       => $this->InstanceID,
            'manufacturer'     => $this->ReadPropertyString('Manufacturer'),
            // Immer false bei einer PHYSISCHEN Instanz (dieses Modul). Reserviert
            // fuer InverterHubVirtual (Aggregat mehrerer Instanzen), damit ein
            // Konsument einen Aggregatwert nicht versehentlich als weiteres
            // Einzelgeraet in die Steuerung nimmt (siehe CLAUDE.md).
            'virtual'          => false,
            'measured'         => true,
            // Kann dieser Treiber ueberhaupt Steuerregister schreiben? (aktuell
            // GoodWe/Deye/Sungrow - generisch ueber die GroupControl-Gruppe
            // erkannt, damit sich das automatisch mitzieht, wenn weitere
            // Treiber Steuerregister bekommen.)
            'controllable'     => $hasControl,
            // Wer darf tatsaechlich schreiben (Nutzereinstellung, s. o.).
            // Fuer einen Konsumenten ohne 'controllable' ist dieser Wert irrelevant.
            'controlAuthority' => $this->ReadPropertyString('ControlAuthority'),
            'pvPowerID'        => $find('pv_total'),
            'acPowerID'        => $find('ac_power'),
            'batPowerID'       => $find('bat_power') ?: $find('bat_total_pwr'),
            'gridPowerID'      => $find('meter_total'),
            'socID'            => $find('bat_soc') ?: $find('soc'),
            'connectedID'      => $find('connected'),
            'batteryTempIDs'   => $batteryTempIDs,
            'batterySocIDs'    => $batterySocIDs,
            'batterySohIDs'    => $batterySohIDs,
            'batteryCapacityID' => $find('bat_capacity'),
            'batteryChargeEnergyID'    => $find('bms_e_charge_total'),
            'batteryDischargeEnergyID' => $find('bms_e_disch_total'),
            'mpptPowerIDs'     => $mpptPowerIDs,
            'mpptCurrentIDs'   => $mpptCurrentIDs,
            'mpptVoltageIDs'   => $mpptVoltageIDs,
        ];
    }

    public function GetConfigurationForm()
    {
        // Geteiltes Ausblenden: nicht in ApplyChanges() (Store-Punkt 27, keine Geschwister-
        // Abfragen dort), sondern erst, wenn jemand das Formular oeffnet - nur dort wird es gebraucht.
        $this->AdoptDismissFromSibling();
        $driver = $this->GetDriver();

        $groupItems = [];
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            $groupItems[] = [
                'type'    => 'CheckBox',
                'name'    => $propName,
                'caption' => $group['caption'],
            ];
            // Steuerhoheit direkt bei der Steuerungs-Gruppe: nur sichtbar, wenn
            // dieser Treiber ueberhaupt Steuerregister hat (GroupControl-Gruppe
            // existiert). Reine Lesetreiber bekommen das Feld nicht - es waere
            // dort wirkungslos und nur Ballast im Formular.
            if ($propName === 'GroupControl') {
                // Erklaerungsbeduerftiges Feld - Symcon kennt kein Hover-Tooltip
                // (Verbund-Konvention, EMS 27.07.2026), deshalb PopupButton statt
                // Mouseover fuer den Hintergrund (Situation A/B, Prioritaetsregel).
                $groupItems[] = [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type'    => 'Select',
                            'name'    => 'ControlAuthority',
                            'caption' => 'Control authority of this instance',
                            'options' => [
                                ['label' => 'EMS (normal case — the EMS may write here)', 'value' => 'ems'],
                                ['label' => 'External (another actor writes, e.g. Sunny Home Manager) — the EMS must NOT write', 'value' => 'external'],
                                ['label' => 'None (nobody is to control here)', 'value' => 'none'],
                            ],
                        ],
                        [
                            'type'    => 'PopupButton',
                            'caption' => 'Control authority — what does it mean?',
                            'width'   => '480px',
                            'popup'   => [
                                'caption' => 'Control authority — what does it mean?',
                                'items'   => [
                                    ['type' => 'Label', 'caption' => '"EMS": normal case, the EMS owns the write channel to this instance (own optimization, §14a, direct marketing) and may write here.'],
                                    ['type' => 'Label', 'caption' => '"External": another actor owns the write channel completely outside the EMS (e.g. Sunny Home Manager). InverterHub then implements NO EMS setpoint, regardless of whether the EMS notices the intervention.'],
                                    ['type' => 'Label', 'caption' => '"None": nobody is to control here, RequestAction refuses every write access.'],
                                    ['type' => 'Label', 'caption' => 'Set wrongly, this leads either to two controllers acting on the same battery at the same time, or to the EMS not being able to write at all, without this being noticed immediately.'],
                                ],
                            ],
                        ],
                    ],
                ];
                // Live bestaetigt (26.07.2026): Register ctl_ems_mode/ctl_ems_power
                // fallen ohne periodische Neubestaetigung auf einen ungueltigen
                // Wert zurueck (GoodWes interner SMART-Automatikmodus uebernimmt
                // sonst wieder). Default AUS, damit einmaliges manuelles Schalten
                // (Steuerungs-POLITIK - Schreibstrategie/Totmann-Verhalten -
                // liegt seit 29.08.2026 komplett beim EMS, s. Create()-Kommentar.)
            }
        }
        // Invers-Schalter: die Vorzeichen von Netz- und Batterieleistung hängen
        // von Einbauort/Verdrahtung bzw. der gewünschten Konvention ab und sind
        // je Anlage verschieden - der Nutzer legt die Richtung selbst fest.
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'MeterInvert',
            'caption' => 'Invert grid power (meter) — if feed-in/import are shown swapped',
        ];
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'BatInvert',
            'caption' => 'Invert battery power — default is + discharging / − charging',
        ];
        // Store-Checkliste/Dashboard-Fund (16.09.2026, sunnyww-Vorfall): Beide
        // Schalter aendern die tatsaechlich gespeicherte Variable, nicht nur
        // eine interne Anzeige - IHUB_GetFunctions() (gridPowerID/batPowerID)
        // gibt exakt diese Variable an Konsumenten wie NRGDashboard weiter,
        // OHNE eigene Korrektur. Reine Geschmacksumschaltung ("ich haette es
        // lieber andersherum") bricht dadurch die Konvention fuer jeden
        // Konsumenten, der sich auf "+ = Einspeisung/Entladen" verlaesst -
        // die Schalter sind nur fuer eine tatsaechlich falsche native
        // Vorzeichen-Meldung des Geraets gedacht, nicht fuer Praeferenz.
        $groupItems[] = [
            'type' => 'Label',
            'caption' => '⚠️ Acts on the stored variable itself, not just on a display — other modules (e.g. NRG-Stack Dashboard) that read this variable adopt the changed direction unchecked. Only enable if your device really reports the direction wrongly, not as a matter of taste.',
        ];
        // Anzahl MPPT-Eingänge: Die Treiber kennen so viele, wie die Baureihe
        // maximal haben kann. Wer weniger Strings betreibt, bekommt sonst leere
        // Variablen. Die Höchstzahl stammt aus den Idents des gewählten Treibers.
        $maxMppt = 0;
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                if (preg_match('/^mppt(\d+)_/', (string) $v[0], $m)) {
                    $maxMppt = max($maxMppt, (int) $m[1]);
                }
            }
        }
        if ($maxMppt > 1) {
            $groupItems[] = [
                'type'    => 'NumberSpinner',
                'name'    => 'MpptCount',
                'caption' => IHUB_Text::t('Number of MPPT inputs (0 = create all %d)', $maxMppt),
                'minimum' => 0,
                'maximum' => $maxMppt,
            ];
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => IHUB_Text::t('ℹ This inverter supports up to %d MPPT inputs. Enter how many are actually in use — the remaining variables are then not created or are removed again on apply. 0 still creates all of them.', $maxMppt),
            ];
        }
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'EnergyUnitWh',
            'caption' => 'Output energy in Wh instead of kWh (base unit; the new Symcon presentation then scales itself to Wh/kWh/MWh)',
        ];
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'AutoArchive',
            'caption' => 'Archive measured values automatically',
        ];
        if (!$this->ReadPropertyBoolean('AutoArchive')) {
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => '⚠️ Automatic archiving is off (default). Without an archive the dashboard, daily and history values show nothing. Recommended: click "Set up archiving now" below and tick the checkbox. Only when migrating from an old module (e.g. GoodweET) leave it off until the old history has been taken over — the target variable must remain without a history of its own.',
            ];
        }
        $groupItems[] = [
            'type'    => 'Button',
            'name'    => 'ArchiveNowButton',
            'caption' => '🗄️ Set up archiving now for all recommended measured values',
            'onClick' => 'IPS_RequestAction($id, "ArchiveNow", true);',
        ];
        $groupItems[] = [
            'type'    => 'Label',
            'name'    => 'ArchiveNowResult',
            'caption' => '',
        ];
        $groupItems[] = [
            'type' => 'ExpansionPanel', 'caption' => '🗄️  Archive compaction', 'expanded' => false,
            'items' => array_merge(
                [['type' => 'Label', 'caption' => 'Compacts finely resolved archive values into coarser aggregates after a while, instead of keeping them in full resolution indefinitely. Only effective if "Archive measured values automatically" is active above.']],
                $this->CompactionFields('Power', '⚡ Power'),
                $this->CompactionFields('Energy', '🔋 Energy'),
                [
                    ['type' => 'Button', 'name' => 'CompactNowButton', 'caption' => '🗜️ Apply compaction now', 'onClick' => 'IPS_RequestAction($id, "CompactionNow", true);'],
                    ['type' => 'Label', 'name' => 'CompactNowResult', 'caption' => ''],
                ]
            ),
        ];

        // Meter-Adresse nur bei Fronius relevant: Dort ist der Smart Meter ein
        // eigenständiges Modbus-Gerät auf derselben IP mit eigener Unit-ID
        // (SMA/SolarEdge liefern den Zähler in der eigenen SunSpec-Kette).
        if ($this->ReadPropertyString('Manufacturer') === 'fronius') {
            $groupItems[] = [
                'type'    => 'NumberSpinner',
                'name'    => 'MeterUnitId',
                'caption' => 'Smart meter address (Modbus unit ID, default 200; e.g. 240 depending on the Fronius configuration)',
                'minimum' => 1,
                'maximum' => 247,
            ];
        }

        // Byte-/Wortreihenfolge nur bei Kostal: Der Plenticore bietet im
        // Webinterface (Einstellungen → Modbus/Sunspec) die Wahl zwischen
        // „little-endian (CDAB) Standard Modbus" (Werkseinstellung) und
        // „big-endian (ABCD) Sunspec". Muss hier passend gewählt werden, sonst
        // liefert das Gerät unbrauchbare Float-Werte (riesige/negative Zahlen).
        if ($this->ReadPropertyString('Manufacturer') === 'kostal'
            && $this->ReadPropertyString('ConnectionType') !== 'kostal_http') {
            $groupItems[] = [
                'type'    => 'Select',
                'name'    => 'KostalByteOrder',
                'caption' => 'Byte order (must match the setting in the Plenticore)',
                'options' => [
                    ['caption' => 'little-endian (CDAB) — standard Modbus (factory setting)', 'value' => 0],
                    ['caption' => 'big-endian (ABCD) — SunSpec', 'value' => 1],
                ],
            ];
        }

        // SMA: Die SunSpec-Kette liegt auf einer um 123 versetzten Unit-ID.
        if ($this->ReadPropertyString('Manufacturer') === 'sma') {
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => 'ℹ SMA peculiarity of the unit ID: the SunSpec data is NOT on the unit ID set in the SMA interface, but on this number PLUS 123 — with the SMA default 3 that is 126, with 4 it is 127. The module tries the offset automatically if nothing is found under the entered unit ID. If there is still no connection: in the SMA interface under "External communication" enable the Modbus TCP server and check which unit ID is set there.',
            ];
        }

        // Victron: Unit-IDs der einzelnen Solarladeregler (MPPT). Für die
        // optionale Gruppe „PV je Solarladeregler / MPPT".
        if ($this->ReadPropertyString('Manufacturer') === 'victron') {
            $groupItems[] = [
                'type'    => 'ValidationTextBox',
                'name'    => 'VictronMpptUnitIds',
                'caption' => 'Solar charge controller unit IDs (MPPT, comma-separated, max. 4 — readable in the GX under Settings → Services → Modbus TCP → available services). Enables the group "PV per solar charge controller / MPPT".',
            ];
            $groupItems[] = [
                'type'    => 'NumberSpinner',
                'name'    => 'VictronGridUnitId',
                'caption' => 'Unit ID of the grid meter (0 = none) — for the group "Energy meters"',
                'minimum' => 0,
                'maximum' => 247,
            ];
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => 'ℹ Victron does NOT keep the meter readings in the system service, but in separate services: grid import/export in the grid meter (com.victronenergy.grid, enter the unit ID above), the solar yield in the solar charge controllers (uses the MPPT unit IDs as well). Note: the total solar yield counter is only 16 bit on the device side and overflows after 6,553.5 kWh; grid import and export, on the other hand, are read from 32-bit registers and are not affected.',
            ];
            $groupItems[] = [
                'type'    => 'CheckBox',
                'name'    => 'VictronYieldFix',
                'caption' => 'Solar yield: correct counter overflow (needed from 6,553.5 kWh per charge controller)',
            ];
            $groupItems[] = [
                'type'    => 'ValidationTextBox',
                'name'    => 'VictronYieldCalib',
                'caption' => 'Calibration: actual total yield per charge controller in kWh, comma-separated in the same order as the unit IDs above',
            ];
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => 'ℹ For calibration, read the value "Total lifetime" per charge controller from the VictronConnect app or the GX and enter it here (example for two controllers: 10617,9628). From it the module determines once how often the counter has already overflowed; after that it counts further overflows itself. If the yield counter on the device is reset, the module detects this and suspends the correction — then please calibrate again. The individual values per charge controller are created in addition, so that it stays visible what is measured and what is calculated.',
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📖  Documentation & help',
                    'expanded' => false,
                    'items' => array_merge([
                        $this->VersionLabel(),
                    ], [
                        ['type' => 'Label', 'caption' => 'InverterHub reads supported inverters via Modbus TCP, MQTT or (for KOSTAL PLENTICORE plus Gen2) the local HTTP API. Select the manufacturer, connection type and applicable data point groups. For the KOSTAL HTTP mode no Modbus connection or port is needed.'],
                        ['type' => 'Label', 'caption' => 'Supported manufacturers: GoodWe (GW-ET/EH/BT/BH), Sungrow (SH hybrid), Solis (hybrid, 33000 registers), Growatt (TL-X/TL3-X/MOD/MIX/SPH/WIT), SolaX, SMA (STP/STPS/SI, incl. grid measurement), Fronius (SunSpec, GEN24 hybrid incl. battery/smart meter), SolarEdge (incl. StorEdge battery), Deye (SG04LP3), Solplanet/AISWEI, Kostal (Modbus Gen1 / HTTP API Gen2), Victron GX (Cerbo/Venus OS), Huawei SUN2000 (incl. DTSU666 meter + LUNA2000 battery, confirmed live on a real system) FoxESS H1/H3 as well as FoxESS H3 Smart / H3 Pro / KH (each read-only preview, beta).'],
                        ['type' => 'Label', 'caption' => '⚙️ Connection peculiarities per manufacturer:'],
                        ['type' => 'Label', 'name' => 'KostalModbusDocHelp', 'caption' => '• Kostal via Modbus TCP: the default port is 1502 (not 502). Select the byte order matching the inverter (factory setting CDAB).', 'visible' => $this->ReadPropertyString('ConnectionType') !== 'kostal_http'],
                        ['type' => 'Label', 'name' => 'KostalHttpDocHelp', 'caption' => '• Kostal via HTTP API (PLENTICORE plus Gen2): only IP/host, local username and password are required. If PV3 is the battery input, enable the PV3 battery checkbox. Battery temperature is not exposed by this API and is disabled. No Modbus settings are required.', 'visible' => $this->ReadPropertyString('ConnectionType') === 'kostal_http'],
                        ['type' => 'Label', 'caption' => '• Victron GX: port 502; with Victron the unit ID is a device selector — the system service is fixed at 100 and addressed automatically (the unit ID in the form is ignored). In the GX enable Settings → Services → Modbus TCP.'],
                        ['type' => 'Label', 'caption' => '• Huawei SUN2000: port 502, unit ID usually 1 (0/16 depending on the configuration). Enable Modbus TCP in the inverter.'],
                        ['type' => 'Label', 'caption' => '• Fronius: the smart meter is a Modbus device of its own with its own unit ID — adjustable in the field "Smart meter address" (default 200, e.g. 240 depending on the configuration).'],
                        ['type' => 'Label', 'caption' => '• SolaX: the inverter itself only speaks Modbus RTU. Modbus TCP only works via an additional SolaX monitoring module (Pocket WiFi/LAN) as a gateway — enter its IP address, not that of the inverter.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Sign convention (module-wide): battery + = discharging / − = charging; grid meter + = feed-in / − = import. If a direction is wrong on your own system, the respective invert switch below helps — the InverterHubTile tile stays correct automatically.'],
                        ['type' => 'Label', 'caption' => '🔄 If a migration to another hub module is planned: leave communication (Modbus/EMS control) disabled for now until the migration is complete — see MigrationsHub for details.'],
                        ['type' => 'Label', 'caption' => '🔌 Symbox gateway (built-in RS485 port): select connection type "Symbox gateway", select the native ModBus gateway, click "Create bridge and connect", apply. The bridge is a small add-on module of this library; one bridge serves exactly one device, whose address (unit ID) is set at the gateway as "Device ID". If the instance shows status 201, the connection test names the reason (no bridge, no gateway at the bridge, gateway inactive, no answer). External RTU-to-TCP gateways (e.g. Waveshare/USR) continue to work via "Direct".', 'visible' => $this->ReadPropertyString('ConnectionType') === 'gateway'],
                        ['type' => 'Label', 'caption' => '🛡️ Insulation resistance (Riso): available for GoodWe, Huawei, Sungrow, SMA and Kostal; optional for Growatt and Fronius (model-dependent, depending on the SunSpec register). Solis, SolaX, SolarEdge, Deye, Solplanet, Victron GX and FoxESS do not provide it.'],
                        ['type' => 'Label', 'caption' => 'Register addresses are in the description field of each variable (object manager, column "Description").'],
                    ]),
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Communication active',
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Manufacturer',
                    'caption' => 'Manufacturer',
                    'options' => [
                        ['label' => '— please select —', 'value' => ''],
                        ['label' => 'GoodWe',  'value' => 'goodwe'],
                        ['label' => 'Sungrow', 'value' => 'sungrow'],
                        ['label' => 'Solis',   'value' => 'solis'],
                        ['label' => 'Growatt', 'value' => 'growatt'],
                        ['label' => 'SolaX (only via SolaX monitoring dongle)', 'value' => 'solax'],
                        ['label' => 'SMA',     'value' => 'sma'],
                        ['label' => 'Fronius', 'value' => 'fronius'],
                        ['label' => 'SolarEdge', 'value' => 'solaredge'],
                        ['label' => 'Deye', 'value' => 'deye'],
                        ['label' => 'Solplanet / AISWEI', 'value' => 'solplanet'],
                        ['label' => 'Kostal (PLENTICORE plus, Modbus gen. 1 / HTTP API gen. 2)', 'value' => 'kostal'],
                        ['label' => 'Victron GX (Cerbo/Venus OS, unit ID 100)', 'value' => 'victron'],
                        ['label' => 'Huawei SUN2000 (+ DTSU666 / LUNA2000, unit ID usually 1)', 'value' => 'huawei'],
                        ['label' => 'FoxESS H1/H3 (read-only preview, beta)', 'value' => 'foxess'],
                        ['label' => 'FoxESS H3 Smart / H3 Pro / KH (read-only preview, beta)', 'value' => 'foxess_smart'],
                        ['label' => 'Hoymiles MS-A2 (via MQTT, preview without device test)', 'value' => 'hoymiles_msa2'],
                        ['label' => 'RCT Power Storage DC (own protocol, port 8899, read only, preview without device test)', 'value' => 'rct_power'],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'ℹ️ RCT Power Storage DC: enter port 8899 (502 is replaced by 8899 for this). Read only, polling at most every 30 s so that the RCT app or Home Assistant is not displaced when running in parallel. The signs of grid (+ feed-in) and battery (+ discharging) have been confirmed against the RCT app on an RCT Power Storage DC 10.0 (firmware 2.3.5689). If your device shows the opposite, use the switches "Invert grid power" or "Invert battery power" and report it in the forum.',
                    'visible' => $this->ReadPropertyString('Manufacturer') === 'rct_power',
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔌  Connection',
                    'expanded' => true,
                    'items' => [
                        [
                            'type' => 'Select',
                            'name' => 'ConnectionType',
                            'caption' => 'Connection type',
                            'options' => $this->ConnectionTypeOptions(),
                            'onChange' => 'IPS_RequestAction($id, "ConnectionTypeChanged", $ConnectionType);',
                        ],
                        [
                            'type'         => 'SelectInstance',
                            'name'         => 'GatewayPick',
                            'caption'      => 'ModBus gateway to the device',
                            'validModules' => [self::NATIVE_GATEWAY_GUID],
                            'visible'      => $this->ReadPropertyString('ConnectionType') === 'gateway',
                        ],
                        [
                            'type'    => 'Button',
                            'name'    => 'CreateBridgeButton',
                            'caption' => 'Create bridge and connect',
                            'onClick' => 'echo IHUB_CreateBridge($id, $GatewayPick);',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'gateway',
                        ],
                        [
                            'type'         => 'SelectInstance',
                            'name'         => 'BridgeInstanceID',
                            'caption'      => 'NRG-Stack bridge (ModBus gateway or MQTT)',
                            'validModules' => [self::BRIDGE_GUID, self::MQTT_BRIDGE_GUID],
                            'visible'      => in_array($this->ReadPropertyString('ConnectionType'), ['gateway', 'mqtt'], true),
                            'onChange'     => 'IPS_RequestAction($id, "BridgeChanged", $BridgeInstanceID);',
                        ],
                        // Verbund-Konvention (SUITE.md, 21.09.2026): jede Verbindung zeigt live, ob sie
                        // zustande kam. Element steht direkt in den items des Panels (kein Ersetzen
                        // in einer Verschachtelung, siehe "rekursiv suchen"-Falle).
                        [
                            'type'    => 'Label',
                            'name'    => 'BridgeStatus',
                            'caption' => $this->BridgeStatusLine($this->ReadPropertyInteger('BridgeInstanceID')),
                            'visible' => in_array($this->ReadPropertyString('ConnectionType'), ['gateway', 'mqtt'], true),
                        ],
                        [
                            'type'    => 'ValidationTextBox',
                            'name'    => 'MqttDeviceID',
                            'caption' => 'Device ID of the MS-A2 (e.g. MSA-280520260806)',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'mqtt',
                        ],
                        [
                            'type'         => 'SelectInstance',
                            'name'         => 'MqttServerPick',
                            'caption'      => 'Symcon MQTT server (the MS-A2 sends to it)',
                            'validModules' => [self::MQTT_SERVER_GUID],
                            'visible'      => $this->ReadPropertyString('ConnectionType') === 'mqtt',
                        ],
                        [
                            'type'    => 'Button',
                            'name'    => 'CreateMqttBridgeButton',
                            'caption' => 'Create MQTT bridge and connect',
                            'onClick' => 'echo IHUB_CreateMqttBridge($id, $MqttServerPick);',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'mqtt',
                        ],
                        [
                            'type' => 'CheckBox',
                            'name' => 'KostalPv3Battery',
                            'caption' => 'PV3 is the battery connection (PV3 data points are not read)',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'kostal_http',
                        ],
                        [
                            'type' => 'Label',
                            'name' => 'KostalHttpModeHelp',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'kostal_http',
                            'caption' => 'ℹ️ KOSTAL HTTP API v1 (PLENTICORE plus Gen2): enter the IP address or host name and the local user password (user name: user) and click "Save password". The connection uses HTTP only; Modbus port, unit ID and byte order have no function here.
If DC3/PV3 is used as the battery connection, enable the switch above: current, voltage and power of PV3 are then not read. PV1/PV2 and the battery measurements stay active.
This KOSTAL HTTP API does not provide a battery temperature, so it is not read in HTTP mode. Variables that already exist stay untouched, they are simply no longer updated.',
                        ],
                        ['type' => 'Label', 'caption' => 'ℹ️ "Symbox gateway" addresses the built-in RS485 port of the Symcon hardware via a native ModBus gateway. Procedure: select the ModBus gateway below, click "Create bridge and connect" (creates the bridge, connects it and enters it) and then click "Apply". IP address, port and unit ID then no longer apply — the device address (unit ID) is set at the gateway as "Device ID", one bridge serves exactly one device. If no values arrive, check this device ID first. Reading follows the scheme of Symcon\'s reference module, writing (control commands) is still untested. For an external RTU-to-TCP gateway (e.g. Waveshare/USR) please use "Direct" with its IP/port — that works today.', 'name' => 'GatewayModeHelp', 'visible' => $this->ReadPropertyString('ConnectionType') === 'gateway'],
                        // IP-Adresse ODER Hostname erlaubt (fsockopen löst den
                        // Namen per DNS auf) - so überlebt die Instanz einen
                        // IP-Wechsel des Wechselrichters, wenn ein fester Name
                        // (DHCP-Reservierung/mDNS, z. B. „wr-fronius.local") genutzt wird.
                        [
                            'type' => 'ValidationTextBox', 'name' => 'KostalHttpUser',
                            'caption' => 'Kostal API username (typically user)',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'kostal_http',
                        ],
                        // Das Passwort ist bewusst KEINE Eigenschaft: Das Feld hat keinen Property-Namen, der Knopf legt den Wert
                        // als Attribut der Instanz ab (NRG-Stack-Konvention, siehe SUITE.md Zugangsdaten).
                        [
                            'type' => 'PasswordTextBox', 'name' => 'KostalHttpPasswordInput',
                            'caption' => 'Kostal API user password (enter it, then click "Save password")',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'kostal_http',
                        ],
                        [
                            'type' => 'Button', 'name' => 'KostalHttpPasswordSave',
                            'caption' => 'Save password',
                            'onClick' => 'echo IHUB_SetKostalHttpPassword($id, $KostalHttpPasswordInput);',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'kostal_http',
                        ],
                        [
                            'type' => 'Label', 'name' => 'KostalHttpPasswordState',
                            'caption' => $this->ReadAttributeString('KostalHttpPassword') !== '' ? 'ℹ️ A password is saved. It is not shown again.' : '⚠️ No password saved yet.',
                            'visible' => $this->ReadPropertyString('ConnectionType') === 'kostal_http',
                        ],
                        ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP address or host name', 'visible' => in_array($this->ReadPropertyString('ConnectionType'), ['direct', 'kostal_http'], true), 'validate' => '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'visible' => $this->ReadPropertyString('ConnectionType') === 'direct', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'visible' => $this->ReadPropertyString('ConnectionType') === 'direct', 'minimum' => 1, 'maximum' => 247],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🏠  External house load meter — input (optional)',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Input, not output: here you can optionally select an existing variable with the really MEASURED house load (e.g. a separate energy meter/Shelly at the house connection). Please select a real consumption meter (always positive) — not a grid/feed-in meter, which can become negative. If a meter is selected, the InverterHubTile tile uses it to show a more accurate load and the difference to the PV/grid/battery balance as "conversion losses" (inverter self-consumption, line losses). Without a selection it stays with the pure balance estimate.'],
                        ['type' => 'Label', 'caption' => 'Note: you can OUTPUT the house load CALCULATED by the module as a variable of its own in the tile instance (InverterHubTile) → panel "Data source" → "Write calculated house load … to a variable".'],
                        ['type' => 'Label', 'caption' => 'Priority if both fields are set: an "Actual house consumption" entered on the InverterHubTile instance itself always takes precedence over this field.'],
                        ['type' => 'SelectVariable', 'name' => 'HouseLoadMeterID', 'caption' => 'External house load measurement variable (input)'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⏱️  Polling',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Fast interval (seconds)', 'minimum' => 5, 'maximum' => 60, 'suffix' => 's'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalSlow', 'caption' => 'Slow interval (seconds)', 'minimum' => 60, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📊  Data points',
                    'expanded' => true,
                    'items' => $groupItems,
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Test connection / read data now', 'onClick' => 'echo IHUB_ReadFast($id);'],
                ['type' => 'Button', 'caption' => '🔄 Force apply (without a form change)', 'onClick' => 'IPS_ApplyChanges($id); echo ' . var_export(IHUB_Text::t('✅ ApplyChanges() executed.'), true) . ';'],
            ],
            // 104 folgt dem GESPEICHERTEN Stand und laesst sich im offenen Formular nicht live
            // umschalten - deshalb in jedem Verbindungsweg neutral (Mstaudi, 20.09.2026).
            // 201 gibt es nur bei laufender Instanz, dort stimmen Speicherstand und Anzeige ueberein.
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Please set up the connection.'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Connection active.'],
                ['code' => 201, 'icon' => 'error',    'caption' => ($this->ReadPropertyString('ConnectionType') === 'gateway')
                    ? 'Connection error – no answer via the bridge: check the bridge and the ModBus gateway (unit ID = device ID at the gateway).'
                    : (($this->ReadPropertyString('ConnectionType') === 'mqtt')
                        ? 'No MQTT messages from the device: check the device ID, the MQTT access on the device and the bridge.'
                        : 'Connection error – inverter not reachable.')],
            ],
        ];

        // Symcon-Forum-Hinweis, einmalig dismissible (Verbund-Konvention Formularpunkt 4,
        // Referenz MeterHub::ForumHint(), EMS 14.09.2026 - eigenes ExpansionPanel statt
        // Label mitten im Formular, mit echtem "Zum Forums-Thread"-Knopf).
        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }

        // "Über dieses Modul" (Lizenz/Spenden, Verbund-Konvention Formularpunkt 5,
        // "Variante A", ganz unten, NICHT dismissible).
        $form['elements'][] = $this->LicenseHint();

        // „Was ist neu"-Banner nach einem Update ganz oben.
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        // „Wozu dieses Modul?" ganz vorn, noch vor dem News-Banner (Store-
        // Checkliste Punkt 0, EMS 14.09.2026, Referenz MeterHub::PurposeIntro()).
        $purpose = $this->PurposeIntro();
        if ($purpose !== null) {
            array_unshift($form['elements'], $purpose);
        }

        return json_encode($form);
    }

    /** Siehe MeterHub::PurposeIntro() fuer die volle Herleitung - steht ganz vorn, noch vor dem News-Panel. */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'InverterHub reads inverter measurements via supported connection types (including Modbus TCP and the read-only KOSTAL HTTP API) and publishes them as Symcon variables. Write access for an EMS is available only for drivers and connection types that explicitly support it.'],
                ['type' => 'Label', 'caption' => 'The benefit: real measured values instead of estimates — as a basis for dashboards, load management or an EMS, and, for controllable inverters, the possibility to actually implement charge/discharge setpoints.'],
                ['type' => 'Label', 'caption' => 'Several inverters, or want to set up several devices at once? InverterHubDiscovery searches the local network for them.'],
                ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'IHUB_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /**
     * Ausblenden von "Wozu dieses Modul?"/"Was ist Neu?"/Forum-Hinweis ueber
     * alle Geschwister-Instanzen dieses Moduls teilen (SUITE.md "Ausblenden
     * ueber mehrere Instanzen desselben Moduls teilen", Referenz MeterHub,
     * EMS 14.09.2026). Ruft bei jeder Geschwister-Instanz NUR den reinen
     * Uebernahme-Schritt auf (AdoptDismissState), nicht erneut die volle
     * Ack-Methode - dadurch kein Ping-Pong moeglich, ganz ohne Prozessmerker.
     */
    private function PropagateDismiss(string $what, string $value = ''): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::SELF_MODULE) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                IHUB_AdoptDismissState($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Geschwister-Instanz mitten im Reload/Loeschen darf das
                // Ausblenden der aufrufenden Instanz nicht mitreissen - @ haelt
                // Fatals bekanntlich nicht auf.
            }
        }
    }

    /** Reiner Uebernahme-Schritt fuer eine Geschwister-Instanz - siehe PropagateDismiss(). */
    public function AdoptDismissState(string $what, string $value)
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ReviewHint':
                $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
                $this->UpdateFormField('ReviewHint', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString('SeenNews', $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Fuer Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand uebernehmen wollen. */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean('PurposeIntroGone'),
            'reviewHintGone'   => $this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE),
            'seenNews'         => $this->ReadAttributeString('SeenNews'),
        ];
    }

    /**
     * Gegenrichtung zu PropagateDismiss(): eine Instanz sieht bei jedem
     * ApplyChanges() bei einer beliebigen Geschwister-Instanz nach und
     * uebernimmt deren Stand, statt "Wozu dieses Modul?"/"Was ist Neu?"/
     * Forum-Hinweis erneut zu zeigen, obwohl der Nutzer sie an anderer Stelle
     * schon bestaetigt hat. Zieht nur vor (false->true, aeltere->neuere
     * News-Version), ueberschreibt nie einen schon weiter fortgeschrittenen
     * eigenen Stand.
     */
    private function AdoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone') && $this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)
            && $this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(self::SELF_MODULE) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = IHUB_GetDismissState($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean('PurposeIntroGone') && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE) && !empty($state['reviewHintGone'])) {
                $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
            }
            if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION && ($state['seenNews'] ?? '') === self::NEWS_VERSION) {
                $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
            }
            break;
        }
    }

    // Versionszeile im Doku-Panel (Verbund-Konvention, Teil 2 der einheitlichen
    // Formular-Optik, EMS 24.07.2026): Das „Was ist neu"-Banner ist dismissible
    // und verschwindet damit irgendwann - die Versionsnummer muss deshalb an
    // einer IMMER sichtbaren Stelle stehen, nicht nur im Banner.
    // Bibliotheks-GUID = "id" aus library.json (nicht die Modul-GUID).
    private function VersionLabel(): array
    {
        $lib = @IPS_GetLibrary('{7EFE4BD7-DC14-460E-B0ED-88071197D35B}');
        $txt = (is_array($lib) && isset($lib['Version']))
            ? IHUB_Text::t('ℹ️ InverterHub version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ InverterHub';
        return ['type' => 'Label', 'caption' => $txt];
    }

    // „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet leer),
    // bis der Nutzer „Verstanden" klickt. Neuinstallation sieht es einmalig.
    private function newsBanner()
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 New in this module — please take a quick look and check the settings if necessary:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . IHUB_Text::t($line)];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'IHUB_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => IHUB_Text::t('🆕 New in version %s', self::NEWS_VERSION), 'expanded' => true, 'items' => $items];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::NEWS_VERSION);
    }

    public function DismissReviewHint()
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
        $this->PropagateDismiss('ReviewHint');
    }

    /** Siehe MeterHub::ForumHint() fuer die volle Herleitung. */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'InverterHub is beta — feedback and test reports are welcome in the Symcon forum thread.'],
                ['type' => 'Button', 'caption' => 'To the forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'IHUB_DismissReviewHint($id);'],
            ],
        ];
    }

    /** Siehe MeterHub::LicenseHint() fuer die volle Herleitung - Verbund-Konvention "Variante A". */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born from real enthusiasm for my own system — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work has gone into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use, commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Like the module and want to leave something anyway? I would be happy about a small donation — entirely voluntary, nothing expected in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Treiber-Auswahl
    // -----------------------------------------------------------------------

    private function GetDriver(){
        if ($this->driver !== null) {
            return $this->driver;
        }
        $key   = $this->ReadPropertyString('Manufacturer');
        $class = self::DRIVERS[$key] ?? self::DRIVERS['goodwe'];
        $this->driver = new $class();
        return $this->driver;
    }

    // Modbus-Unit-ID des Smart Meters (für SunSpec-Hersteller, deren Zähler
    // ein eigenständiges Modbus-Gerät auf derselben IP/Port ist).
    public function GetMeterUnitId(): int
    {
        $v = (int)$this->ReadPropertyInteger('MeterUnitId');
        return ($v >= 1 && $v <= 247) ? $v : 200;
    }

    // Float32-Wortreihenfolge bei Kostal: true = CDAB (Wort-Swap), false = ABCD.
    public function GetKostalWordSwap(): bool
    {
        if ($this->ReadPropertyString('ConnectionType') === 'kostal_http') { return false; }
        return (int)$this->ReadPropertyInteger('KostalByteOrder') === 0;
    }

    /**
     * Legt das lokale Benutzerpasswort der Kostal-HTTP-Schnittstelle als Attribut ab (nicht als Eigenschaft) und
     * verwirft die gespeicherte Sitzung. Wird vom Knopf "Passwort speichern" im Formular aufgerufen.
     */
    public function SetKostalHttpPassword(string $password): string
    {
        if (trim($password) === '') {
            return IHUB_Text::t('⚠️ Please enter a password first.');
        }
        $this->WriteAttributeString('KostalHttpPassword', $password);
        $this->WriteAttributeString('KostalHttpSession', '');
        $this->UpdateFormField('KostalHttpPasswordState', 'caption', IHUB_Text::t('ℹ️ A password is saved. It is not shown again.'));
        // Das Passwort ist keine Eigenschaft, "Übernehmen" bleibt im Formular deshalb ausgegraut (Rückmeldung Dirk
        // Allard, 10.11.2026). Es wird nichts an den Eigenschaften geändert: die Instanz wird nur neu angewendet, damit
        // Status und Takt das neue Passwort sofort nutzen.
        $this->ApplyChanges();
        return IHUB_Text::t('✅ Password saved and applied.');
    }

    // Verbindungsarten im Formular. "Kostal HTTP" gibt es nur beim Hersteller Kostal; steht sie schon in der
    // gespeicherten Konfiguration, bleibt sie sichtbar, damit das Feld nicht leer erscheint.
    private function ConnectionTypeOptions(): array
    {
        $options = [['label' => 'Direct (own TCP connection)', 'value' => 'direct']];
        if ($this->ReadPropertyString('Manufacturer') === 'kostal' || $this->ReadPropertyString('ConnectionType') === 'kostal_http') {
            $options[] = ['label' => 'Kostal HTTP API v1 (read only, PLENTICORE plus gen. 2)', 'value' => 'kostal_http'];
        }
        $options[] = ['label' => 'Symbox gateway (built-in RS485 port, beta)', 'value' => 'gateway'];
        $options[] = ['label' => 'MQTT (Symcon MQTT server, Hoymiles MS-A2 only, preview)', 'value' => 'mqtt'];
        return $options;
    }

    // Victron: Unit-IDs der Solarladeregler (MPPT) als Integer-Array (max. 4).
    /**
     * Ist eine optionale Gruppe aktiviert? Treiber nutzen das, um teure Lesungen
     * zu überspringen, deren Variablen ohnehin nicht existieren.
     */
    public function GroupEnabled(string $propName): bool
    {
        try {
            return $this->ReadPropertyBoolean($propName);
        } catch (Throwable $e) {
            return false; // Gruppe kennt der aktuelle Treiber nicht
        }
    }

    // Zustand der Überlaufkorrektur, innerhalb eines Lesezyklus gepuffert und
    // am Ende gesammelt geschrieben (FlushYieldState) - nicht je Regler einzeln.
    private $yieldState = null;
    private $yieldDirty = false;

    /**
     * Rechnet den 16-Bit-Rohwert des Solarertrags (Reg 790, ÷10) auf den
     * tatsächlichen Gesamtertrag hoch.
     *
     * Hintergrund: Victron stellt diesen Zähler über Modbus nur als UINT16
     * bereit - bei ÷10 ist bei 6553,5 kWh Schluss, danach zählt er wieder bei
     * null an. Eine 32-Bit-Variante existiert nicht.
     *
     * Verfahren:
     *  - EICHUNG: Beim ersten Lauf je Regler wird die Zahl der bisherigen
     *    Überläufe aus dem vom Nutzer eingetragenen Ist-Wert bestimmt (aus der
     *    Victron-App ablesbar). Ohne Eintrag wird 0 angenommen.
     *  - FORTZÄHLEN: Sinkt der Rohwert, war es entweder ein Überlauf oder ein
     *    Reset des Zählers durch den Nutzer. Unterschieden wird über die Höhe:
     *    Vor einem Überlauf MUSS der Zähler nahe am Maximum stehen. Nur dann
     *    wird hochgezählt; sonst gilt es als Reset und die Korrektur beginnt
     *    von vorn (der Nutzer muss dann neu eichen).
     *
     * Ist die Korrektur abgeschaltet, wird der Rohwert unverändert geliefert.
     */
    public function YieldCorrected(int $unitId, int $index, int $raw): float
    {
        if (!$this->ReadPropertyBoolean('VictronYieldFix')) {
            return $raw / 10.0;
        }
        if ($this->yieldState === null) {
            $s = json_decode((string)$this->ReadAttributeString('VictronYieldState'), true);
            $this->yieldState = is_array($s) ? $s : [];
        }
        $key = (string) $unitId;

        if (!isset($this->yieldState[$key])) {
            // Eichung aus dem eingetragenen Ist-Wert des Geräts.
            $calib = $this->GetVictronYieldCalib();
            $true  = (float) ($calib[$index] ?? 0.0);
            $wraps = ($true > 0.0) ? intdiv((int) round($true * 10), 65536) : 0;
        } else {
            $wraps = (int) $this->yieldState[$key]['w'];
            $last  = (int) $this->yieldState[$key]['r'];
            if ($raw < $last) {
                // Überlauf nur annehmen, wenn der Zähler vorher nahe am Maximum
                // stand. Ein Nutzer-Reset kommt von einem beliebigen Wert und
                // darf NICHT als Überlauf gezählt werden - das addierte sonst
                // fälschlich 6553,6 kWh.
                if ($last >= 60000 && $raw <= 5000) {
                    $wraps++;
                } else {
                    $wraps = 0; // Reset erkannt: Korrektur ist ungültig geworden
                }
            }
        }

        $this->yieldState[$key] = ['w' => $wraps, 'r' => $raw];
        $this->yieldDirty = true;
        return ($wraps * 65536 + $raw) / 10.0;
    }

    /** Gepufferten Korrekturzustand schreiben (einmal je Lesezyklus). */
    public function FlushYieldState(): void
    {
        if ($this->yieldDirty && $this->yieldState !== null) {
            $this->WriteAttributeString('VictronYieldState', json_encode($this->yieldState));
            $this->yieldDirty = false;
        }
    }

    /** Eichwerte (tatsächlicher Gesamtertrag je Laderegler, kWh), Reihenfolge wie die Unit-IDs. */
    public function GetVictronYieldCalib(): array
    {
        $out = [];
        foreach (explode(',', $this->ReadPropertyString('VictronYieldCalib')) as $part) {
            $out[] = (float) str_replace(',', '.', trim($part));
        }
        return $out;
    }

    /**
     * Unit-ID des Victron-Netzzählers (Dienst com.victronenergy.grid).
     * 0 = nicht konfiguriert; die Netz-Zählerstände entfallen dann.
     */
    public function GetVictronGridUnitId(): int
    {
        $id = $this->ReadPropertyInteger('VictronGridUnitId');
        return ($id > 0 && $id <= 247) ? $id : 0;
    }

    public function GetVictronMpptUnitIds(): array
    {
        $out = [];
        foreach (explode(',', $this->ReadPropertyString('VictronMpptUnitIds')) as $part) {
            $id = (int)trim($part);
            if ($id > 0 && $id <= 247) {
                $out[] = $id;
            }
            if (count($out) >= 4) {
                break;
            }
        }
        return $out;
    }

    private function GetModbusClient()
    {
        if ($this->ReadPropertyString('ConnectionType') === 'kostal_http') {
            return new IHUB_KostalHttpClient(
                $this->ReadPropertyString('Host'),
                $this->ReadPropertyString('KostalHttpUser'),
                $this->ReadAttributeString('KostalHttpPassword'),
                $this->ReadPropertyBoolean('KostalPv3Battery'),
                function (): string { return $this->ReadAttributeString('KostalHttpSession'); },
                function (string $session): void { $this->WriteAttributeString('KostalHttpSession', $session); }
            );
        }
        // RCT Power: eigenes TCP-Protokoll (Port 8899), kein Modbus. Der Modbus-Standardport 502
        // ist dort nie richtig und gilt als "nicht eingestellt".
        if ($this->ReadPropertyString('Manufacturer') === 'rct_power') {
            $port = $this->ReadPropertyInteger('Port');
            return new IHUB_RctClient($this->ReadPropertyString('Host'), ($port === 502) ? 8899 : $port);
        }
        // MQTT-Modus (Hoymiles MS-A2): kein Modbus, die Treibermethoden bekommen null.
        if ($this->ReadPropertyString('ConnectionType') === 'mqtt') {
            return null;
        }
        if ($this->ReadPropertyString('ConnectionType') === 'gateway') {
            return new IHUB_ModbusGatewayClient(
                $this,
                $this->ReadPropertyInteger('Port'),
                $this->ReadPropertyInteger('UnitId'),
                function (string $text): void {
                    $this->LogMessage($text, KL_WARNING);
                }
            );
        }
        return new IHUB_ModbusTcpClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('UnitId')
        );
    }

    // Öffentliche Methode für IHUB_ModbusGatewayClient (die Hilfsklasse hat keinen Zugriff
    // auf die Modulinstanz). Seit der Umstellung auf die Brücke (19.09.2026) hängt diese
    // Instanz NICHT mehr selbst am ModBus-Gateway, sondern reicht die Anfrage an eine
    // InverterHubBridge-Instanz weiter, die als Kind des Gateways die Schnittstellen trägt.
    // Schema der Anfrage: von MeterHub am Referenzmodul SymconBC/EM24-DIN verifiziert.
    public function ForwardToGateway(string $dataId, int $function, int $address, int $quantity, string $data)
    {
        // 'Data' kann rohe gepackte Registerbytes enthalten (z. B. beim
        // Schreibpfad) - die sind meist kein gültiges UTF-8 (z. B. 0xFFFF),
        // json_encode() scheitert dabei STUMM (liefert false statt Warnung/
        // Exception). Ohne Base64 würde so ein Wert lautlos NICHT verschickt.
        $json = json_encode([
            'DataID'   => $dataId,
            'Function' => $function,
            'Address'  => $address,
            'Quantity' => $quantity,
            'Data'     => base64_encode($data),
        ]);
        if ($json === false) {
            $this->SetBuffer('GatewayError', 'encode_failed');
            return false;
        }
        $bridge = $this->ReadPropertyInteger('BridgeInstanceID');
        if ($bridge <= 0 || !IPS_InstanceExists($bridge) || !function_exists('IHUBB_Forward')) {
            $this->SetBuffer('GatewayError', 'no_bridge');
            return false;
        }
        $reply = json_decode((string)@IHUBB_Forward($bridge, $json), true);
        if (!is_array($reply) || empty($reply['ok'])) {
            $this->SetBuffer('GatewayError', is_array($reply) ? (string)($reply['error'] ?? 'no_response') : 'no_response');
            return false;
        }
        $raw = base64_decode((string)($reply['data'] ?? ''), true);
        if ($raw === false || $raw === '') {
            $this->SetBuffer('GatewayError', 'no_response');
            return false;
        }
        $this->SetBuffer('GatewayError', '');
        return $raw;
    }

    // --- MQTT (Hoymiles MS-A2) ----------------------------------------------------
    // Von der MQTT-Bruecke aufgerufen (IHUB_MqttReceive): eine Nachricht des Geraets.
    public function MqttReceive(string $topic, string $payload)
    {
        if ($this->ReadPropertyString('ConnectionType') !== 'mqtt' || !$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $dev = trim($this->ReadPropertyString('MqttDeviceID'));
        if ($dev === '' || strpos($topic, '/' . $dev . '/') === false) {
            return;   // Nachricht eines anderen Geraets
        }
        $driver = $this->GetDriver();
        if (!method_exists($driver, 'onMqtt')) {
            return;
        }
        $this->SetBuffer('MqttLast', (string)time());
        try {
            $driver->onMqtt($this, $topic, $payload);
            $this->SetVarBool('connected', true);
        } catch (Throwable $e) {
            $this->LogReadProblem(IHUB_Text::t('⚠️ MQTT message not processed: %s', $e->getMessage()));
        }
    }

    // Sekunden seit der letzten Nachricht, -1 = noch keine.
    public function MqttAge(): int
    {
        $t = $this->GetBuffer('MqttLast');
        return $t === '' ? -1 : max(0, time() - (int)$t);
    }

    public function MqttDeviceId(): string
    {
        return trim($this->ReadPropertyString('MqttDeviceID'));
    }

    // Befehl an das Geraet (ueber die Bruecke). true = an den MQTT-Server uebergeben.
    public function MqttPublish(string $topic, string $payload): bool
    {
        $bridge = $this->ReadPropertyInteger('BridgeInstanceID');
        if ($bridge <= 0 || !IPS_InstanceExists($bridge) || !function_exists('IHUBM_Publish')) {
            return false;
        }
        return (bool)@IHUBM_Publish($bridge, $topic, $payload);
    }

    // Knopf "MQTT-Brücke anlegen und verbinden": legt eine InverterHubMqttBridge neben dieser Instanz an
    // (oder nutzt eine vorhandene am selben MQTT-Server) und traegt sie ins offene Formular ein.
    public function CreateMqttBridge(int $serverId): string
    {
        if ($serverId <= 0 || !IPS_InstanceExists($serverId)) {
            return IHUB_Text::t('⚠️ Please select the Symcon MQTT server first.');
        }
        if ((IPS_GetInstance($serverId)['ModuleInfo']['ModuleID'] ?? '') !== self::MQTT_SERVER_GUID) {
            return IHUB_Text::t('⚠️ The selected instance is not an MQTT server.');
        }
        $bridge = 0;
        foreach (IPS_GetInstanceListByModuleID(self::MQTT_BRIDGE_GUID) as $candidate) {
            if ((int)(IPS_GetInstance($candidate)['ConnectionID'] ?? 0) === $serverId) {
                $bridge = (int)$candidate;
                break;
            }
        }
        $created = false;
        if ($bridge === 0) {
            try {
                $bridge = IPS_CreateInstance(self::MQTT_BRIDGE_GUID);
                IPS_SetName($bridge, IHUB_Text::t('InverterHub Bridge MQTT (%s)', IPS_GetName($serverId)));
                IPS_SetParent($bridge, IPS_GetParent($this->InstanceID));
                IPS_ConnectInstance($bridge, $serverId);
                IPS_ApplyChanges($bridge);
                $created = true;
            } catch (Throwable $e) {
                return IHUB_Text::t('⚠️ Bridge could not be created: %s Is the library up to date (module "NRG-Stack InverterHub Bridge (MQTT)" present)?', $e->getMessage());
            }
        }
        $this->UpdateFormField('BridgeInstanceID', 'value', $bridge);
        $this->UpdateFormField('BridgeStatus', 'caption', $this->MqttBridgeStatusLine($bridge));
        return $created
            ? IHUB_Text::t('✅ MQTT bridge created and connected to the MQTT server. It is entered above - now enter the device ID and click "Apply".')
            : IHUB_Text::t('ℹ️ Existing MQTT bridge on this server reused. It is entered above - now enter the device ID and click "Apply".');
    }

    private function MqttBridgeStatusLine(int $bridgeId): string
    {
        if ($bridgeId <= 0 || !IPS_InstanceExists($bridgeId)) {
            return IHUB_Text::t('ℹ️ No MQTT bridge selected: the instance stays inactive (status 104). Select the MQTT server and click "Create bridge and connect".');
        }
        $head = IHUB_Text::t('Bridge #%d "%s"', $bridgeId, IPS_GetName($bridgeId));
        if (!function_exists('IHUBM_GetState')) {
            return IHUB_Text::t('⚠️ %s is selected, but the bridge module does not answer. Update the library in the module control.', $head);
        }
        $s = json_decode((string)@IHUBM_GetState($bridgeId), true);
        if (!is_array($s)) {
            return IHUB_Text::t('⚠️ %s returns no state. Update the library in the module control.', $head);
        }
        if (empty($s['connected'])) {
            return IHUB_Text::t('⚠️ %s is not connected to an MQTT server.', $head);
        }
        if (empty($s['parentActive'])) {
            return IHUB_Text::t('⚠️ %s: the MQTT server is not active (status %d).', $head, (int)($s['parentStatus'] ?? 0));
        }
        if ((int)($s['lastAge'] ?? -1) >= 0) {
            return IHUB_Text::t('✅ %s connected to the MQTT server. Last message %d s ago.', $head, (int)$s['lastAge']);
        }
        return IHUB_Text::t('✅ %s connected to the MQTT server. No message received yet.', $head);
    }

    // Knopf "Brücke anlegen und verbinden": legt eine InverterHubBridge neben dieser
    // Instanz an (oder nutzt eine vorhandene am selben Gateway), verbindet sie mit dem
    // gewaehlten ModBus-Gateway und traegt sie ins offene Formular ein. Nie aus
    // ApplyChanges()/GetConfigurationForm() aufrufen - nur auf ausdruecklichen Klick.
    public function CreateBridge(int $gatewayId): string
    {
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            return IHUB_Text::t('⚠️ Please select a ModBus gateway first.');
        }
        if ((IPS_GetInstance($gatewayId)['ModuleInfo']['ModuleID'] ?? '') !== self::NATIVE_GATEWAY_GUID) {
            return IHUB_Text::t('⚠️ The selected instance is not a ModBus gateway.');
        }
        $bridge = 0;
        foreach (IPS_GetInstanceListByModuleID(self::BRIDGE_GUID) as $candidate) {
            if ((int)(IPS_GetInstance($candidate)['ConnectionID'] ?? 0) === $gatewayId) {
                $bridge = (int)$candidate;
                break;
            }
        }
        $created = false;
        if ($bridge === 0) {
            try {
                $bridge = IPS_CreateInstance(self::BRIDGE_GUID);
                IPS_SetName($bridge, IHUB_Text::t('InverterHub Bridge (%s)', IPS_GetName($gatewayId)));
                IPS_SetParent($bridge, IPS_GetParent($this->InstanceID));
                IPS_ConnectInstance($bridge, $gatewayId);
                IPS_ApplyChanges($bridge);
                $created = true;
            } catch (Throwable $e) {
                return IHUB_Text::t('⚠️ Bridge could not be created: %s Is the library up to date (module "NRG-Stack InverterHub Bridge" present)?', $e->getMessage());
            }
        }
        $this->UpdateFormField('BridgeInstanceID', 'value', $bridge);
        $this->UpdateFormField('BridgeStatus', 'caption', $this->BridgeStatusLine($bridge));
        return $created
            ? IHUB_Text::t('✅ Bridge created and connected to the gateway. It is entered above - now click "Apply".')
            : IHUB_Text::t('ℹ️ Existing bridge on this gateway reused. It is entered above - now click "Apply".');
    }

    // Statuszeile der gewaehlten Bruecke, live beim Oeffnen des Formulars, bei Auswahlwechsel
    // und nach "Bruecke anlegen und verbinden" berechnet. Vier Zustaende nach SUITE.md:
    // ✅ verbunden (mit Gateway und gelesener Geraete-ID), ⚠️ verbunden, aber unbrauchbar,
    // ℹ️ nichts gewaehlt (und was dann gilt).
    private function BridgeStatusLine(int $bridgeId): string
    {
        if ($this->ReadPropertyString('ConnectionType') === 'mqtt') {
            return $this->MqttBridgeStatusLine($bridgeId);
        }
        if ($bridgeId <= 0 || !IPS_InstanceExists($bridgeId)) {
            return IHUB_Text::t('ℹ️ No bridge selected: the instance stays inactive (status 104). Select a ModBus gateway and click "Create bridge and connect".');
        }
        $name = IPS_GetName($bridgeId);
        $head = IHUB_Text::t('Bridge #%d "%s"', $bridgeId, $name);
        if (!function_exists('IHUBB_GetState')) {
            return IHUB_Text::t('⚠️ %s is selected, but the bridge module does not answer. Update the library in the module control.', $head);
        }
        $state = json_decode((string)@IHUBB_GetState($bridgeId), true);
        if (!is_array($state)) {
            return IHUB_Text::t('⚠️ %s returns no state. Update the library in the module control.', $head);
        }
        if (empty($state['connected'])) {
            return IHUB_Text::t('⚠️ %s is not connected to a ModBus gateway. Select a gateway in the bridge or click "Create bridge and connect" here.', $head);
        }
        $gatewayId = (int)(IPS_GetInstance($bridgeId)['ConnectionID'] ?? 0);
        $gw = IHUB_Text::t('ModBus gateway #%d "%s"', $gatewayId, $gatewayId > 0 ? IPS_GetName($gatewayId) : '?');
        if (empty($state['parentActive'])) {
            return IHUB_Text::t('⚠️ %s is connected to %s, but the gateway is not active (status %d). Check the gateway and the serial interface.', $head, $gw, (int)($state['parentStatus'] ?? 0));
        }
        $unit = ($state['unitId'] ?? null) !== null
            ? IHUB_Text::t('Device ID at the gateway: %d', (int)$state['unitId'])
            : IHUB_Text::t('Device ID at the gateway not readable');
        return IHUB_Text::t('✅ %s connected via %s. %s. Whether the device answers is shown by "Test connection / read data now".', $head, $gw, $unit);
    }

    private function GatewayErrorText(string $error): string
    {
        switch ($error) {
            case 'no_bridge':
                return IHUB_Text::t('⚠️ No bridge selected. Create a "NRG-Stack InverterHub Bridge (ModBus gateway)" and select it here.');
            case 'not_connected':
                return IHUB_Text::t('⚠️ The bridge is not connected to a ModBus gateway. Select a gateway in the bridge instance.');
            case 'parent_inactive':
                return IHUB_Text::t('⚠️ The ModBus gateway is not active. Check the gateway instance and the serial interface.');
            default:
                return IHUB_Text::t('⚠️ The gateway does not answer. Check the device ID at the gateway and the connection.');
        }
    }

    // -----------------------------------------------------------------------
    // Variablen-Registrierung (generisch, treiberunabhängig)
    // -----------------------------------------------------------------------

    private function KostalHttpSuppressedIdents(): array
    {
        if ($this->ReadPropertyString('Manufacturer') !== 'kostal'
            || $this->ReadPropertyString('ConnectionType') !== 'kostal_http') {
            return [];
        }
        // Die Kostal HTTP API liefert keine Batterie-Temperatur.
        $skip = ['bat_temp' => true];
        if ($this->ReadPropertyBoolean('KostalPv3Battery')) {
            foreach (['pv3_curr', 'pv3_power', 'pv3_volt'] as $ident) $skip[$ident] = true;
        }
        return $skip;
    }

    private function RegisterVariables()
    {
        $driver = $this->GetDriver();
        $suppressed = $this->KostalHttpSuppressedIdents();

        // Menge der gültigen Idents des AKTUELL gewählten Treibers (Basis +
        // aktivierte optionale Gruppen). Alles andere unter der Instanz ist
        // ein Überbleibsel eines anderen Herstellers oder einer deaktivierten
        // Gruppe und wird entfernt - sonst blieben beim Herstellerwechsel
        // (z. B. GoodWe -> Fronius) die Ordner/Variablen des vorigen Treibers
        // stehen (real gemeldet: neu angelegte Fronius-Instanz zeigte die
        // GoodWe-Datenpunkte).
        $valid = [];
        $hasControl = false;
        foreach ($driver->getBaseVars() as $v) {
            $valid[$v[0]] = true;
            if ($v[5] === 'control') {
                $hasControl = true;
            }
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($this->MpptFiltered($group['vars']) as $v) {
                    $valid[$v[0]] = true;
                    if ($v[5] === 'control') {
                        $hasControl = true;
                    }
                }
            }
        }
        // Fehlervariablen fuer Schreibbefehle: nur bei Instanzen, die ueberhaupt Steuerpunkte haben.
        $writeDiag = $hasControl ? $this->WriteDiagVars() : [];
        foreach ($writeDiag as $v) {
            $valid[$v[0]] = true;
        }
        $this->PruneForeignObjects($valid);

        $pos = 0;
        foreach ($driver->getBaseVars() as $v) {
            $this->RegisterVar($v, $pos++);
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($this->MpptFiltered($group['vars']) as $v) {
                    if (!isset($suppressed[$v[0]])) {
                        $this->RegisterVar($v, $pos++);
                    }
                }
            }
        }
        foreach ($writeDiag as $v) {
            $this->RegisterVar($v, $pos++);
        }
        // Frueher angelegte Kategorien (cat_*) sind jetzt leer und werden entfernt.
        $this->PruneForeignObjects($valid);
    }

    // Vom Kern fuer jede Instanz mit Steuerpunkten gefuehrte Meldung, ob Schreibbefehle angekommen sind
    // (15.11.2026, Wunsch EMS fuer die Statuszeile). Gruppe 'diag', kein Steuerpunkt, keine Aktion.
    private function WriteDiagVars(): array
    {
        return IHUB_WriteDiag::vars();
    }

    /**
     * Blendet Variablen nicht vorhandener MPPT-Eingänge aus.
     *
     * Die Treiber definieren so viele MPPT-Idents (mppt1_*, mppt2_* …), wie die
     * jeweilige Baureihe maximal haben kann — beim Sungrow SG-CX etwa zwölf.
     * Wer nur zwei Strings betreibt, bekäme sonst zehn leere Variablen, die den
     * Objektbaum zumüllen und im Archiv Platz kosten.
     *
     * MpptCount = 0 bedeutet „alle anlegen" (Vorgabe, bisheriges Verhalten).
     * Idents ohne mppt-Nummer bleiben immer erhalten.
     *
     * Die Filterung greift bewusst auch in der Liste gültiger Idents, damit
     * PruneForeignObjects() überzählige Variablen einer früheren Einstellung
     * wieder abräumt, statt sie verwaist stehen zu lassen.
     */
    private function MpptFiltered(array $vars): array
    {
        $max = $this->ReadPropertyInteger('MpptCount');
        if ($max <= 0) {
            return $vars;
        }
        $out = [];
        foreach ($vars as $v) {
            if (preg_match('/^mppt(\d+)_/', (string) $v[0], $m) && (int) $m[1] > $max) {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }

    // Entfernt unter der Instanz alle Variablen mit einem Ident, das nicht in
    // $validIdents steht (Reste eines anderen Treibers / deaktivierter Gruppe),
    // und räumt danach leere Modul-Kategorien (cat_*) ab.
    private function PruneForeignObjects(array $validIdents)
    {
        $all = [];
        $collect = function ($pid) use (&$collect, &$all) {
            foreach (IPS_GetChildrenIDs($pid) as $cid) {
                $all[] = $cid;
                if (IPS_GetObject($cid)['ObjectType'] === 0) {
                    $collect($cid);
                }
            }
        };
        $collect($this->InstanceID);

        // 1) Variablen (ObjectType 2) mit ungültigem Ident löschen.
        foreach ($all as $cid) {
            if (!IPS_ObjectExists($cid)) {
                continue;
            }
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2 || $obj['ObjectIdent'] === '') {
                continue;
            }
            if (!isset($validIdents[$obj['ObjectIdent']])) {
                $this->DropVariable($cid);
            }
        }

        // 2) Leere Modul-Kategorien (cat_*) entfernen.
        foreach ($all as $cid) {
            if (!IPS_ObjectExists($cid)) {
                continue;
            }
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] === 0
                && strpos($obj['ObjectIdent'], 'cat_') === 0
                && count(IPS_GetChildrenIDs($cid)) === 0) {
                @IPS_DeleteCategory($cid);
            }
        }
    }

    // Custom Actions werden hier bewusst NICHT gesetzt — das übernimmt
    // EnableActions() per Kurz-Timer nach Abschluss der Transaktion
    // (siehe Kommentar in Create()).
    private function RegisterVar(array $def, int $pos)
    {
        [$ident, $caption, $type, $profile, $archive, $group] = $def;
        $reg = isset($def[6]) ? $def[6] : '';

        $vtype = [
            'F' => VARIABLETYPE_FLOAT,
            'I' => VARIABLETYPE_INTEGER,
            'B' => VARIABLETYPE_BOOLEAN,
            'S' => VARIABLETYPE_STRING,
        ][$type];

        $vid = $this->FindVarByIdent($ident);
        // Typ-Migration: Ändert sich der gewünschte Variablentyp (z. B. Fronius-
        // SOC von Integer auf Float), muss die bestehende Variable neu angelegt
        // werden - IPS kann den Typ einer Variable nicht nachträglich ändern.
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== $vtype) {
            $this->DropVariable($vid);
            $vid = 0;
        }

        // Live-Fehler (26.07.2026): Steuervariablen wurden bisher per rohem
        // IPS_CreateVariable()+IPS_SetIdent() angelegt. Solche Variablen sind
        // fuer den Kernel NIE ueber diese Instanz "registriert" (das passiert
        // nur ueber RegisterVariableXXX) - EnableAction() findet dafuer keinen
        // Action-Slot und bleibt wirkungslos (meldet trotzdem `true`, ohne
        // etwas zu bewirken). Klick in WebFront/App scheiterte mit "Action is
        // invalid". Beschraenkt auf group==='control': Fuer alle uebrigen
        // (Mess-)Variablen bliebe ein Umstieg auf RegisterVariableXXX ein
        // Delete+Neuanlage-Vorgang mit NEUER Variablen-ID - das wuerde die
        // Archivhistorie jeder geloggten Messvariable in jeder Installation
        // kappen. Steuervariablen werden nicht archiviert, das Risiko
        // existiert dort nicht.
        //
        // Zweiter Live-Fehler, real aufgetreten: RegisterVariableXXX() erkennt
        // eine schon existierende eigene Variable NUR, wenn sie aktuell noch
        // DIREKTES Kind der Instanz ist. Da wir jede Variable sofort danach in
        // eine Unterkategorie verschieben (pv/bat/grid/control/...), fand
        // RegisterVariableXXX sie beim naechsten ApplyChanges() nicht wieder
        // und legte STATT DESSEN eine weitere neue Variable an - bei jedem
        // Reload eine neue ID, Action erneut auf 0. Deshalb: RegisterVariableXXX
        // nur beim EINMALIGEN Erzeugen aufrufen (per eigener, rekursiver
        // FindVarByIdent-Suche erkannt, die auch in Unterkategorien findet).
        // Existiert die Variable schon, wird der gefundene $vid unveraendert
        // weiterverwendet - keine erneute Registrierung, kein ID-Churn.
        //
        // Dritter Live-Fehler, real aufgetreten (EMS, 26.07.2026): Die
        // Einmaligkeit wurde zunaechst ueber ein persistentes Attribut
        // (ControlVarsRegistered) abgesichert - das ueberlebt aber KEINEN
        // vollen Modul-Reload (MC_DeleteModule+MC_CreateModule loescht
        // Instanz-Attribute, dieselbe Nebenwirkung, die auch Tibbers
        // OAuth-Passwort gekippt hat). Ergebnis: Bei jedem vollen Reload lief
        // die Migration erneut, neue Variablen-ID bei jedem Mal - fuer
        // Nutzer inakzeptabel (feste WebFront-Widget-Referenzen brechen).
        // Deshalb KEIN Attribut/Flag mehr: Die Pruefung ist selbstverifizierend
        // ueber den ECHTEN, kernel-verwalteten Zustand der Variable selbst
        // (VariableAction), der - anders als ein Attribut - einen vollen
        // Modul-Reload uebersteht, solange die Variable selbst nicht geloescht
        // wird.
        if ($group === 'control' && $vid) {
            $alreadyBound = (@IPS_GetVariable($vid)['VariableAction'] === $this->InstanceID);
            if (!$alreadyBound) {
                // Alte, roh erzeugte oder noch nicht registrierte Variable -
                // erst loeschen, dann sauber ueber RegisterVariableXXX neu
                // anlegen (sonst "Ident muss fuer jede Ebene eindeutig sein").
                $this->DropVariable($vid);
                $vid = 0;
            }
        }
        $created = !$vid;
        // Der Name ist nur eine Vorbelegung (Nutzer-Eigenschaft): beim Anlegen in der Sprache der Konsole gesetzt, danach nie
        // mehr ueberschrieben. Uebersetzt wird nur dann (Translate() liest locale.json bei jedem Aufruf).
        $name = $created ? IHUB_Text::t($caption) : $caption;

        // Energie in Wh: Statt des kWh-Standardprofils ~Electricity das Wh-Profil. Die Skalierung
        // des Werts (x1000) uebernimmt SetVarFloat.
        $isEnergy = ($profile === '~Electricity');
        if ($isEnergy && $this->ReadPropertyBoolean('EnergyUnitWh')) {
            $profile = 'IHB.Wh';
        }
        // Existiert unter dem Namen schon ein Profil ANDEREN Typs (z. B. ein altes "SMA.Watt" als
        // Ganzzahl aus einem Fremdmodul), wirft IPS "Variablentyp und Profiltyp stimmen nicht
        // ueberein" (Forum brdiver677, 28.10.2026). Dann Profil weglassen und warnen.
        $useProfile = $profile;
        if ($useProfile !== '' && ($created || ($this->reprofileEnergy && $isEnergy))) {
            $existing = @IPS_GetVariableProfile($useProfile);
            if (is_array($existing) && ($existing['ProfileType'] ?? $vtype) !== $vtype) {
                $this->LogMessage(IHUB_Text::t('Profile "%s" has a different type than the variable "%s" and is not assigned. Please check or delete the profile under Kernel > Variable profiles.', $useProfile, IHUB_Text::t($caption)), KL_WARNING);
                $useProfile = '';
            }
        }

        if ($created) {
            // Store-Punkt 28: Das Profil (Standarddarstellung) kommt beim Anlegen ueber RegisterVariable*,
            // nie ueber IPS_SetVariableCustomProfile. Alle neuen Variablen werden registriert.
            switch ($type) {
                case 'F':
                    $vid = $this->RegisterVariableFloat($ident, $name, $useProfile, $pos);
                    break;
                case 'I':
                    $vid = $this->RegisterVariableInteger($ident, $name, $useProfile, $pos);
                    break;
                case 'B':
                    $vid = $this->RegisterVariableBoolean($ident, $name, $useProfile, $pos);
                    break;
                case 'S':
                    $vid = $this->RegisterVariableString($ident, $name, $useProfile, $pos);
                    break;
            }
        }

        // Symcon-Store-Review (30.10.2026): Kategorien unter Instanzen sind nicht
        // vorgesehen. Alle Variablen liegen direkt unter der Instanz. Bestehende
        // Variablen werden einmalig aus den frueheren Unterkategorien heraufgehaengt
        // (IPS_SetParent aendert die Variablen-ID nicht - Archiv, Verknuepfungen
        // und WebFront-Verweise bleiben erhalten), die leeren Kategorien raeumt
        // RegisterVariables() danach ab.
        $moved = ((int)IPS_GetObject($vid)['ParentID'] !== $this->InstanceID);
        if ($moved) {
            IPS_SetParent($vid, $this->InstanceID);
        }
        // Store-Review-Fund 9m (HeishaMon-Sitzung, 28.09.2026): Position nur beim
        // erstmaligen Anlegen setzen, nicht bei jedem ApplyChanges() - sonst wirft
        // ein Nutzer, der eine Variable im Objektbaum manuell umsortiert hat, beim
        // naechsten "Uebernehmen" automatisch wieder auf die feste Treiber-Reihenfolge
        // zurueckgesetzt, ohne dass er das will oder merkt.
        if ($created || $moved) {
            IPS_SetPosition($vid, $pos);
        }
        if ($created) {
            IPS_SetName($vid, $name);
        }

        // Profil nur bei einer echten Wh-Umstellung (Schalter im Formular, ausdrueckliche Handlung des Nutzers)
        // bei bestehenden Energie-Variablen neu setzen. Sonst nie: Eine vom Nutzer gewaehlte Darstellung bleibt.
        if (!$created && $useProfile !== '' && $this->reprofileEnergy && $isEnergy
            && @IPS_GetVariable($vid)['VariableCustomProfile'] !== $useProfile) {
            IPS_SetVariableCustomProfile($vid, $useProfile);
        }
        if ($reg !== '') {
            // Nur Texte uebersetzen, reine Registerangaben ("DSP 35103") nicht - spart Translate()-Aufrufe.
            IPS_SetInfo($vid, preg_match('/[a-z]{4,}/', $reg) ? IHUB_Text::t($reg) : $reg);
        }
        // Auto-Archivierung nur bei NEU angelegter Variable und nur, wenn der
        // Nutzer sie nicht abgeschaltet hat (Migrations-Vorbedingung, s. o.).
        // Bereits geloggte Variablen fassen wir nie an - ein Nutzer, der die
        // Archivierung von Hand ausgeschaltet hat, soll das behalten.
        if ($archive && $created && $this->ReadPropertyBoolean('AutoArchive')) {
            $this->SetArchive($vid, $isEnergy);
        }
        // Store-Punkt 29: Bestehende Variablen werden im Archiv nie angefasst (weder Verdichtung noch
        // Aggregationstyp). Verdichtung nur auf Knopfdruck (ApplyCompactionNow), Logging nur auf Knopfdruck
        // oder ueber das ausdruecklich angehakte Auto-Archivieren beim Anlegen.
    }

    private function UnregVarIfExists($ident)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            $this->DropVariable($vid);
        }
    }

    // Entfernt eine Variable dieser Instanz ueber UnregisterVariable() (Store-Punkt 26). Das geht nur
    // fuer direkte Kinder; Reste aus den frueheren Unterordnern werden dazu erst eine Ebene hochgehaengt.
    private function DropVariable(int $vid): void
    {
        if (!IPS_VariableExists($vid)) {
            return;
        }
        $obj = IPS_GetObject($vid);
        if ($obj['ParentID'] !== $this->InstanceID) {
            @IPS_SetParent($vid, $this->InstanceID);
            $obj = IPS_GetObject($vid);
            if ($obj['ParentID'] !== $this->InstanceID) {
                return;
            }
        }
        $this->UnregisterVariable($obj['ObjectIdent']);
    }

    private function FindVarByIdent($ident){
        return $this->FindIdentRecursive($this->InstanceID, $ident);
    }

    private function FindIdentRecursive(int $parentID, $ident){
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

    // Aggregationstyp beim ERSTMALIGEN Archivieren setzen: 1 (Zähler) für
    // kumulative Energie-Idents (Profil ~Electricity/IHB.Wh - Ertrag, Bezug,
    // Einspeisung usw.), sonst 0 (Standard/Mittelwert) für Momentanwerte.
    //
    // ACHTUNG, real gemeldeter Fehler (Tester sirkentucky, 24.07.2026): Hier
    // stand zuvor UNBEDINGT AggregationType 0, auch für Energie-Idents. Für
    // einen kWh-Zähler ist das falsch - AC_GetAggregatedValues liefert dann
    // bei Zeitreihen-Auswertungen den ROHSTAND statt des Periodenverbrauchs
    // (siehe die Counter-Aggregations-Falle, an anderer Stelle bereits
    // dokumentiert). Der Nutzer hatte es von Hand auf „Zähler" umgestellt,
    // aber jede Neuanlage der Variable (z. B. nach Loeschen) setzte es wieder
    // auf Standard zurueck.
    // Nur bei ERSTANLAGE gesetzt (Aufrufer prueft $created) - eine vom Nutzer
    // manuell geaenderte Aggregation an einer bestehenden Variable wird nie
    // wieder ueberschrieben.
    // Formularfelder je Kategorie (Power/Energy) fuer das Verdichtungs-Panel.
    private function CompactionFields(string $kind, string $label): array
    {
        return [
            ['type' => 'Label', 'caption' => $label],
            ['type' => 'CheckBox', 'name' => 'AutoCompaction' . $kind, 'caption' => 'Compaction active (applied only with "Apply compaction now")'],
            ['type' => 'Label', 'caption' => '⚠️ Compaction is never applied automatically. "Apply compaction now" writes the rules to the archive of the variables already archived and replaces rules set manually in the console.'],
            ['type' => 'CheckBox', 'name' => 'CompactDirect' . $kind, 'caption' => 'Immediately to the highest compaction level (1x/minute)'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Label', 'caption' => 'After'],
                ['type' => 'NumberSpinner', 'name' => 'CompactStage2Months' . $kind, 'suffix' => 'months to'],
                ['type' => 'Select', 'name' => 'CompactStage2Type' . $kind, 'options' => self::COMPACTION_TYPES],
            ]],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Label', 'caption' => 'After'],
                ['type' => 'NumberSpinner', 'name' => 'CompactStage3Months' . $kind, 'suffix' => 'months to'],
                ['type' => 'Select', 'name' => 'CompactStage3Type' . $kind, 'options' => self::COMPACTION_TYPES],
            ]],
        ];
    }

    private const COMPACTION_TYPES = [
        ['caption' => '1x per minute', 'value' => 0],
        ['caption' => '1x per 5 minutes', 'value' => 1],
        ['caption' => '1x per hour', 'value' => 2],
        ['caption' => '1x per day', 'value' => 3],
        ['caption' => '1x per week', 'value' => 4],
        ['caption' => '1x per month', 'value' => 5],
        ['caption' => '1x per year', 'value' => 6],
    ];

    // Variablen des gewaehlten Treibers (Basis + angehakte Gruppen, MPPT gefiltert).
    private function ActiveDriverVars(): array
    {
        $driver = $this->GetDriver();
        $vars = $driver->getBaseVars();
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($this->MpptFiltered($group['vars']) as $v) {
                    $vars[] = $v;
                }
            }
        }
        $suppressed = $this->KostalHttpSuppressedIdents();
        return array_values(array_filter($vars, static fn($v) => !isset($suppressed[$v[0]])));
    }

    // Vom Nutzer ausgeloest (Schaltflaeche, Store-Punkt 29): schreibt die Verdichtungsregeln ins Archiv der
    // bereits archivierten Variablen, getrennt fuer Leistung und Energie und nur fuer die angehakten Arten.
    // Nie automatisch beim Uebernehmen. Gibt einen Ergebnistext fuer das Formular zurueck.
    private function ApplyCompactionNow(): string
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) === 0) {
            return IHUB_Text::t('⚠️ No archive instance found - nothing was set up.');
        }
        if (!$this->ReadPropertyBoolean('AutoCompactionPower') && !$this->ReadPropertyBoolean('AutoCompactionEnergy')) {
            return IHUB_Text::t('ℹ️ Tick "Compaction active" for power and/or energy, save with "Apply", then click again.');
        }
        $changed = 0;
        $same = 0;
        foreach ($this->ActiveDriverVars() as $v) {
            if (empty($v[4])) {
                continue;
            }
            $vid = $this->FindVarByIdent($v[0]);
            if (!$vid || !@AC_GetLoggingStatus($archiveIDs[0], $vid)) {
                continue;
            }
            $isEnergy = ($v[3] === '~Electricity');
            $kind = $isEnergy ? 'Energy' : 'Power';
            if (!$this->ReadPropertyBoolean('AutoCompaction' . $kind)) {
                continue;
            }
            $interval = $isEnergy ? $this->ReadPropertyInteger('IntervalSlow') : $this->ReadPropertyInteger('IntervalFast');
            $plan = $this->CompactionPlan($interval, $kind);
            if ($this->CompactionMatches($archiveIDs[0], $vid, $plan)) {
                $same++;
                continue;
            }
            foreach ($plan as [$months, $type]) {
                if ($type === -1) {
                    @AC_SetCompaction($archiveIDs[0], $vid, $months, $type);
                } else {
                    AC_SetCompaction($archiveIDs[0], $vid, $months, $type);
                }
            }
            $changed++;
        }
        return IHUB_Text::t('✅ Compaction written for %d variable(s), %d already matched.', $changed, $same);
    }

    // Vom Nutzer ausgeloest (Schaltflaeche): schaltet das Archiv fuer alle Variablen ein, die der
    // Treiber als archivierungswuerdig kennzeichnet und die noch NICHT geloggt werden. Bereits
    // archivierte Variablen bleiben unberuehrt. Gibt einen Ergebnistext fuer das Formular zurueck.
    private function ArchiveAllNow(): string
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) === 0) {
            return IHUB_Text::t('⚠️ No archive instance found - nothing was set up.');
        }
        $vars = $this->ActiveDriverVars();
        $done = 0;
        $already = 0;
        foreach ($vars as $v) {
            if (empty($v[4])) {
                continue;   // Treiber empfiehlt dafuer kein Archiv
            }
            $vid = $this->FindVarByIdent($v[0]);
            if (!$vid) {
                continue;
            }
            if (@AC_GetLoggingStatus($archiveIDs[0], $vid)) {
                $already++;
                continue;
            }
            $this->SetArchive($vid, $v[3] === '~Electricity');
            $done++;
        }
        IPS_ApplyChanges($archiveIDs[0]);
        $head = $already > 0
            ? IHUB_Text::t('✅ Archiving set up for %d variable(s) (%d were already archived).', $done, $already)
            : IHUB_Text::t('✅ Archiving set up for %d variable(s).', $done);
        return $head . ' ' . IHUB_Text::t('Please save the checkmark above with "Apply" so that new measured values are archived in future as well.');
    }

    /**
     * Stimmen die im Archiv gespeicherten Verdichtungsregeln mit dem Plan ueberein?
     * AC_GetCompaction liefert [['MonthOffset'=>..,'CompactionType'=>..], ...].
     * Typ -1 im Plan heisst "keine Regel an diesem Versatz". Im Zweifel (Fehler,
     * unerwartetes Format) false -> es wird wie bisher geschrieben.
     */
    private function CompactionMatches(int $archiveID, int $vid, array $plan): bool
    {
        $cur = @AC_GetCompaction($archiveID, $vid);
        if (!is_array($cur)) {
            return false;
        }
        $have = [];
        foreach ($cur as $r) {
            if (!is_array($r) || !isset($r['MonthOffset'], $r['CompactionType'])) {
                return false;
            }
            $have[(int)$r['MonthOffset']] = (int)$r['CompactionType'];
        }
        foreach ($plan as [$months, $type]) {
            if ($type === -1) {
                if (isset($have[$months])) {
                    return false;
                }
            } elseif (!isset($have[$months]) || $have[$months] !== $type) {
                return false;
            }
        }
        return true;
    }

    private function SetArchive($vid, bool $isEnergy = false)
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            AC_SetLoggingStatus($archiveIDs[0], $vid, true);
            AC_SetAggregationType($archiveIDs[0], $vid, $isEnergy ? 1 : 0);
        }
    }

    // Archiv-Verdichtung aus dem Update-Intervall abgeleitet, nur auf Knopfdruck angewendet (ApplyCompactionNow)
    // (Referenz MeterHub, siehe dortige CLAUDE.md fuer die volle Herleitung).
    // Jede Stufe nur, wenn ihre Ziel-Aufloesung GROEBER ist als das Roh-
    // Intervall (sonst Leerlauf). Liefert IMMER drei [Monatsversatz, Typ]-
    // Paare - eine deaktivierte/nicht sinnvolle Stufe bekommt Typ -1, damit
    // AC_SetCompaction eine zuvor gesetzte Regel aktiv loescht statt sie
    // stehen zu lassen (MeterHub-Lehre: "nichts aufrufen" != "aktiv aus").
    private function CompactionPlan(int $intervalSeconds, string $kind): array
    {
        if (!$this->ReadPropertyBoolean('AutoCompaction' . $kind)) {
            return [[-1, -1], [$this->ReadPropertyInteger('CompactStage2Months' . $kind), -1], [$this->ReadPropertyInteger('CompactStage3Months' . $kind), -1]];
        }
        $stage2Months = $this->ReadPropertyInteger('CompactStage2Months' . $kind);
        $stage3Months = $this->ReadPropertyInteger('CompactStage3Months' . $kind);
        $direct = ($intervalSeconds < 60 && $this->ReadPropertyBoolean('CompactDirect' . $kind)) ? 0 : -1;
        $stage2 = ($intervalSeconds < 300) ? $this->ReadPropertyInteger('CompactStage2Type' . $kind) : -1;
        $stage3 = ($intervalSeconds < 3600) ? $this->ReadPropertyInteger('CompactStage3Type' . $kind) : -1;
        return [[-1, $direct], [$stage2Months, $stage2], [$stage3Months, $stage3]];
    }

    // -----------------------------------------------------------------------
    // Variable setzen (public, damit Treiber sie via $hub->... aufrufen können)
    // -----------------------------------------------------------------------

    public function SetVarFloat(string $ident, float $value)
    {
        // Robustheit: Liefert ein Register keinen gültigen Float32 (z. B. weil
        // ein Gerät an dieser Adresse gar keinen Float ablegt oder eine andere
        // Byte-/Wort-Reihenfolge nutzt), entstehen NaN/INF. IP-Symcon lehnt die
        // beim Setzen mit einer Warnung ab ("NaN/INF Werte werden nicht
        // unterstützt") - wir fangen das zentral ab und schreiben 0.0.
        if (!is_finite($value)) {
            $value = 0.0;
        }
        // Float32-"nicht belegt"-Marke: SolarEdge meldet nicht vorhandene Float-Register als
        // -3.4028235E+38 (0xFF7FFFFF), nach einer Vorzeichenumkehr im Treiber als +3.4E+38.
        // Das ist kein Messwert in irgendeiner Einheit und gehoerte bisher ungefiltert ins
        // Archiv (Forum-Beta-Tester somm, 21.09.2026). Verwerfen, der alte Stand bleibt stehen;
        // einmal je Messgroesse ins Meldungsfenster, damit es nicht alle paar Sekunden meldet.
        if (abs($value) >= 3.0e38) {
            if ($this->GetBuffer('Sentinel_' . $ident) === '') {
                $this->SetBuffer('Sentinel_' . $ident, '1');
                $this->LogMessage(IHUB_Text::t('Register for "%s" reports "not in use" (float32 extreme value) - value is not accepted.', $ident), KL_MESSAGE);
            }
            return;
        }
        // Zentraler Invers-Schalter für die Meter-Leistung: Je nach Einbauort/
        // Verdrahtung des Zählers melden Anlagen die Richtung genau umgekehrt -
        // der Nutzer entscheidet selbst, statt dass wir je Hersteller raten.
        if ($ident === 'meter_total' && $this->ReadPropertyBoolean('MeterInvert')) {
            $value = -$value;
        }
        // Analog für die Batterieleistung (Vorzeichen je nach gewünschter
        // Konvention). Modul-Standard ist + = Entladen, − = Laden.
        if (in_array($ident, ['bat_total_pwr', 'bat_power', 'bat1_pwr', 'bat2_pwr'], true)
            && $this->ReadPropertyBoolean('BatInvert')) {
            $value = -$value;
        }
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            // Zaehlerschutz (Fund Stefan/somm, SolarEdge, 17.09.2026): manche
            // Geraete liefern waehrend eines Standby-Wechsels (typischerweise
            // morgens/abends) sporadisch einen einzelnen Lesezyklus mit einem
            // Wert nahe 0, obwohl der kumulative Zaehler real nie zurueckspringt
            // (bestaetigt: 13210,27 kWh -> 0,00 kWh -> 13210,27 kWh). Ein solcher
            // Ausreisser wird verworfen (alter Stand bleibt stehen), statt als
            // Scheinsprung ins Archiv zu gelangen. Vergleich immer in kWh (Roh-
            // einheit der Treiber), unabhaengig vom Wh-Anzeigeschalter. Bewusst
            // NUR gegen "auf quasi 0 gefallen" geprueft, nicht gegen jeden
            // Rueckgang - ein echter Zaehlertausch (dauerhaft niedrigerer
            // Folgewert) bleibt dadurch moeglich, nur der Einzelausreisser wird
            // abgefangen. Analog zu MeterHubs CounterGuardStep(), hier bewusst
            // einfacher gehalten (kein Reset-Bestaetigungsfenster ueber mehrere
            // Lesungen - ein WR-Zaehlertausch ohne Neuanlage der Instanz ist
            // praktisch nie der Fall).
            // Tageszaehler (e_pv_day, e_charge_day, e_day, ...) sind vom Schutz oben
            // ausgenommen: bei denen ist ein Ruecksprung auf 0 kein Ausreisser, sondern
            // taeglich erwartetes Verhalten (Fund Dietmar, 23.09.2026 - eigene Anlage,
            // e_pv_day/e_charge_day blieben nach dem morgendlichen Aufwecken des WR
            // stundenlang auf dem Vortageswert stehen, weil der Schutz den legitimen
            // Reset als Ausreisser wertete). Erkennung generisch ueber die Endung
            // "_day", herstellerunabhaengig, kein Treiber-Sonderfall.
            $isDailyCounter = (substr($ident, -4) === '_day');
            if ($this->IsEnergyIdent($ident) && !$isDailyCounter) {
                $prevKwh = (float)@GetValueFloat($vid);
                if ($this->ReadPropertyBoolean('EnergyUnitWh')) {
                    $prevKwh /= 1000.0;
                }
                if ($value < 0.001 && $prevKwh > 0.5) {
                    $this->LogMessage(IHUB_Text::t('Implausible meter reading for "%s" discarded (0 instead of previously %s kWh) - probably a single Modbus outlier.', $ident, round($prevKwh, 2)), KL_WARNING);
                    return;
                }
            }
            // Energie in Wh: Die Treiber liefern kWh. Ist der Schalter aktiv und
            // handelt es sich um eine Energie-Variable, auf Wh hochrechnen. Die
            // Erkennung erfolgt am Ident (nicht am Profil), damit eine vom Nutzer
            // geänderte Presentation die Skalierung nicht aushebelt.
            if ($this->ReadPropertyBoolean('EnergyUnitWh') && $this->IsEnergyIdent($ident)) {
                $value *= 1000.0;
            }
            SetValueFloat($vid, $value);
        }
    }

    // Energie-Zähler-Variablen (kWh), die der Wh-Schalter auf Wh hochrechnet.
    // Deckt alle ~Electricity-Idents ab: e_* plus die wenigen Ausnahmen.
    private function IsEnergyIdent(string $ident): bool
    {
        return strncmp($ident, 'e_', 2) === 0
            || in_array($ident, ['meter_imp', 'meter_exp', 'home_total', 'bat_capacity'], true);
    }

    public function SetVarInt(string $ident, int $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            // Plausibilitaetsschutz fuer Prozent-Idents (SOC/SOH, Fund Stefan/
            // somm, SolarEdge, 17.09.2026 - Folgefund zum Zaehlerschutz oben).
            // Anders als bei Leistung/Energie ist hier eine feste, generische
            // Grenze zulaessig: SOC/SOH sind IMMER 0-100 %, unabhaengig von
            // Hersteller oder Anlagengroesse - kein "keine eigene Anlage als
            // Norm"-Konflikt. Ein einzelner ausserhalb liegender Lesezyklus
            // wird verworfen (alter Stand bleibt stehen) statt als
            // Ausreisser-Punkt ins Archiv zu gelangen.
            if (preg_match('/(?:^|_)so[ch]$/', $ident) && ($value < 0 || $value > 100)) {
                $this->LogMessage(IHUB_Text::t('Implausible value for "%s" discarded (%s instead of 0-100 %%) - probably a single Modbus outlier.', $ident, $value), KL_WARNING);
                return;
            }
            SetValueInteger($vid, $value);
        }
    }

    public function SetVarBool(string $ident, bool $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueBoolean($vid, $value);
        }
    }

    public function SetVarStr(string $ident, string $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueString($vid, $value);
        }
    }

    // Öffentlicher Wrapper: ReadPropertyBoolean() ist bei IPSModule protected
    // und daher von externen Treiber-Klassen (IHUB_GoodweDriver etc.) nicht direkt
    // aufrufbar.
    public function GetPropBool(string $name)
    {
        return $this->ReadPropertyBoolean($name);
    }

    // Aktueller Wert einer eigenen Integer-Variable (rekursive Ident-Suche) -
    // Treiber nutzen das z. B. fuer Flanken-Erkennung (nur beim UEBERGANG auf
    // 255 warnen, nicht in jedem 5s-Zyklus erneut). -1, wenn nicht vorhanden.
    public function GetVarInt(string $ident)
    {
        $vid = $this->FindVarByIdent($ident);
        return $vid ? GetValueInteger($vid) : -1;
    }

    // Wie GetVarInt, fuer Float-Variablen. -1.0, wenn nicht vorhanden.
    public function GetVarFloat(string $ident)
    {
        $vid = $this->FindVarByIdent($ident);
        return $vid ? GetValueFloat($vid) : -1.0;
    }

    // -----------------------------------------------------------------------
    // Profile (treiberdefiniert)
    // -----------------------------------------------------------------------

    private function CreateProfiles()
    {
        $driver = $this->GetDriver();

        // Energie-Profil in Wh (Basiseinheit) für die optionale Wh-Ausgabe.
        if (!IPS_VariableProfileExists('IHB.Wh')) {
            IPS_CreateVariableProfile('IHB.Wh', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileDigits('IHB.Wh', 0);
        IPS_SetVariableProfileText('IHB.Wh', '', ' Wh');

        foreach ($driver->getProfiles() as $name => $def) {
            [$type, $suffix, $min, $max, $step, $digits] = $def;
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
            }
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }

        foreach ($driver->getEnumProfiles() as $name => $assocs) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            }
            foreach ($assocs as $value => $def) {
                [$label, $color] = $def;
                IPS_SetVariableProfileAssociation($name, $value, IHUB_Text::t($label), '', $color);
            }
        }
    }
}
