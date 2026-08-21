# InverterHub

![Symcon](https://img.shields.io/badge/Symcon-PHP--Modul-blue)
![Modul Version](https://img.shields.io/badge/Version-0.74.0--beta.1-informational)
![Symcon Version](https://img.shields.io/badge/IP--Symcon-%E2%89%A5%209.0-orange)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

IP-Symcon-Modul, das Wechselrichter verschiedener Hersteller direkt per **Modbus TCP** ausliest
und steuert — ein generisches Treiber-Framework statt eines Moduls pro Hersteller.

**Status: Beta.** Die Register-Zuordnungen basieren auf den öffentlich verfügbaren
Modbus-Protokolldokumenten der Hersteller und wurden, soweit möglich, gegen reale Anlagen
geprüft (GoodWe live verifiziert; Fronius, SolarEdge, Kostal, Victron und Huawei durch
Beta-Tester an realen Anlagen erprobt) sowie gegen unabhängige Quellen gegengeprüft — die
Referenzimplementierung [OpenEMS](https://github.com/OpenEMS/openems) für Register-/
Feldoffsets (GoodWe, Fronius, SMA), die Register-Definitionen der `huawei-solar-lib` sowie
von echten Nutzern im
[IP-Symcon-Forum](https://community.symcon.de/c/symcon/vorlagen-modbus/86) geteilte
Modbus-Vorlagen (GoodWe, SolaX, SolarEdge, Deye, Solplanet, Kostal, Victron, Huawei). Rückmeldungen zu
falschen/fehlenden Werten sind willkommen — bitte mit Hersteller, Modell und betroffenem
Register melden.

## Unterstützte Hersteller

| Hersteller | Umfang | Anmerkung |
|---|---|---|
| **GoodWe** | PV (3 MPPT-Tracker), Netz, Batterie 1+2, Meter, Energie, Backup/Insel, EMS-Steuerung | Live verifiziert, produktiv im Einsatz |
| **Sungrow** | PV (4 MPPT), Netz, Batterie, Meter, Energie, Backup, Start/Stop | SH-Hybrid-Serie |
| **Solis** | PV (4 Strings), Netz, Batterie, Meter, Energie | Nur Hybrid-Serie (33000er-Register); reine String-Wechselrichter (3000er) noch nicht unterstützt |
| **Growatt** | PV (3 Strings), Netz, Energie, Temperatur, Fehlercodes | Deckt den über TL-X/TL3-X/MOD/MIX/SPH/WIT gemeinsamen Basisregisterbereich ab |
| **SolaX** | PV (6 Strings), Netz ein-/dreiphasig, Batterie-Systemwerte, Meter/CT | **Wichtig:** Der Wechselrichter spricht nur Modbus RTU. Modbus TCP läuft ausschließlich über ein zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als Gateway — dessen IP-Adresse eintragen, nicht die des Wechselrichters |
| **SolarEdge** | PV Gesamtleistung, Netz, Meter (inkl. Bezug/Einspeisung kWh), Energie, Temperatur, Status, StorEdge-Batterie (SOC/Leistung/Spannung/Strom/Temperatur/SOH/Zustand), berechnete PV-Erzeugung, Gerätename/Seriennummer | Reines SunSpec (Integer-Modell 103 mit Skalierungsfaktoren), dieselbe Laufzeit-Discovery wie Fronius/SMA. Der StorEdge-Batterieblock (ab 0xE100) nutzt eine abweichende Byte-Reihenfolge (CDAB), was das Modul automatisch berücksichtigt. Auf StorEdge-Anlagen spiegelt das DC-Register nicht die reine PV wider – dafür die optionale Gruppe „PV-Erzeugung berechnet" aktivieren. |
| **Deye** | PV (2 Strings), Netz, Batterie, Hausverbrauch, Energie, Start/Stop-Steuerung | SG04LP3-Serie, Vorlage von einem 8K-SG04LP3 getestet |
| **Solplanet / AISWEI** | PV (3 Strings), Batterie, Temperatur, Energie | ASW-Gen-Serie |
| **Kostal** | PV (3 DC-Eingänge), Netz, Batterie, Meter, Hausverbrauch nach Quelle, Energie | Nur PLENTICORE plus Generation 1 getestet — andere Generationen/Leistungsklassen ungeprüft. **Wichtig:** Kostal nutzt standardmäßig Port **1502**, nicht 502 — beim Anlegen der Instanz ggf. manuell eintragen. |
| **SMA** | PV Gesamtleistung, Netz, Meter, Energie, Temperatur, Status, Gerätename/Seriennummer | Reine SunSpec-Implementierung mit Laufzeit-Discovery, wie von OpenEMS für SMA Sunny Tripower verwendet |
| **Fronius** | PV (MPPT: Spannung/Strom/Leistung je String), Netz, Meter (Gesamt + optional je Phase U/I/P), Energie, Batterie (GEN24-Hybrid: SOC als Float, Leistung, Spannung), Status, Gerätename/Seriennummer | Reine SunSpec-Implementierung mit Laufzeit-Discovery (keine festen Registeradressen, siehe unten). Der Smart Meter ist ein eigenes Modbus-Gerät mit eigener Unit-ID („Smart-Meter-Adresse", Vorgabe 200, je nach Konfiguration z. B. 240 — im Datenpunkte-Panel einstellbar). Im Wechselrichter muss der Modbus-Server (TCP) aktiviert sein; „Steuerung erlauben" ist nicht nötig, das Modul liest nur. |
| **Victron GX** | PV (DC + AC-gekoppelt, optional je Solarladeregler/MPPT), Netz, Batterie (SOC/Leistung/Spannung/Strom/Zustand), Hausverbrauch, Netz-Quelle | Liest den aggregierten Systemdienst `com.victronenergy.system` (Cerbo GX / Venus OS). **Wichtig:** Unit-ID ist bei Victron ein Geräte-Selektor — der Systemdienst liegt fest auf **100**, das Modul spricht diese automatisch an (die im Formular gesetzte Unit-ID wird bei Victron ignoriert). Port **502**. Im GX unter Einstellungen → Services → Modbus TCP aktivieren. Noch nicht am realen Gerät verifiziert — Vorzeichen von Netz/Batterie ggf. per Invers-Schalter anpassen. |
| **Huawei SUN2000** | PV (DC-Eingang), Netz, Wirkleistung, Temperatur, Status, Energie, Smart Meter (DTSU666), Batterie (LUNA2000: SOC/Leistung/Spannung/Strom/Temperatur/Zustand) | Native Huawei-Registermap (kein SunSpec), Register/Gain aus `huawei-solar-lib`. Unit-ID des Wechselrichters ist meist **1** (je nach Konfiguration auch 0/16 — im Wechselrichter unter Modbus TCP einstellbar), Port **502**. Modbus TCP muss im Gerät aktiviert sein. Noch nicht am realen Gerät verifiziert — Vorzeichen von Netz/Batterie ggf. per Invers-Schalter anpassen. |

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch oder für eigene Skripte.

## Module in diesem Repository

### InverterHub

Die eigentliche Datenauslese-Instanz. Ein Modul, ein `Manufacturer`-Auswahlfeld — je nach
gewähltem Hersteller werden die passenden Datenpunkt-Gruppen (Checkboxen) und Register
freigeschaltet. Architektur:

- **`ModbusTcpClient`** — gemeinsame Modbus-TCP-Grundfunktionen (Read Holding/Input Register,
  Write Single/Multiple, Datentyp-Hilfsfunktionen), von allen Treibern genutzt.
- **`InverterDriverInterface`** — Vertrag, den jeder Hersteller-Treiber erfüllt (Basisvariablen,
  optionale Gruppen, Profile, `readFast`/`readSlow`/`readDeviceInfo`/`writeControl`).
- **Ein Treiber je Hersteller** (`GoodweDriver`, `SungrowDriver`, `SolisDriver`, `GrowattDriver`,
  `SolaxDriver`, `SmaDriver`, `FroniusDriver`, `SolarEdgeDriver`, `DeyeDriver`, `SolplanetDriver`,
  `KostalDriver`, `VictronDriver`, `HuaweiDriver`) — kapselt die herstellerspezifischen
  Registeradressen, Skalierungsfaktoren und Eigenheiten.

Einrichtung: Instanz anlegen, Hersteller wählen, IP-Adresse (und bei Bedarf Port/Unit-ID)
eintragen, gewünschte Datenpunkt-Gruppen aktivieren, übernehmen.

**Externer Hauslastzähler — Eingang (optional):** Unter „Externer Hauslastzähler — Eingang
(optional)" lässt sich eine bereits vorhandene Variable mit real gemessener Hauslast auswählen
(z. B. ein Shelly am Hausanschluss). Wichtig: hier einen echten Verbrauchszähler wählen (immer
positiv), keinen Netz-/Einspeisezähler.

**Energie-Einheit (kWh/Wh):** Standardmäßig werden Energiewerte in kWh ausgegeben. Der Schalter
„Energie in Wh statt kWh ausgeben" (im Datenpunkte-Panel) stellt sie auf die Basiseinheit Wh um
— konsistent zur Leistung (W); die neue IP-Symcon-Darstellung skaliert dann selbst auf
Wh/kWh/MWh. Bestehende Instanzen bleiben ohne Umschalten bei kWh (kein Sprung in der Historie).

**Invers-Schalter:** Je nach Verdrahtung/gewünschter Konvention lassen sich Netz-Leistung
(Meter) und Batterie-Leistung per Schalter invertieren. Der angezeigte Datenpunkt folgt dann
der gewählten Konvention.

### InverterHubDiscovery

Ein **Configurator**-Modul, das einen IP-Bereich im lokalen Netz nach Wechselrichtern auf
Modbus-TCP-Port 502 durchsucht:

1. Start- und End-IP eintragen (wird beim Anlegen anhand des eigenen Netzwerks vorbelegt,
   bleibt aber änderbar), optional eine Namens-Vorlage für neu anzulegende Instanzen.
2. „Netzwerk durchsuchen" klicken — nicht-blockierender Parallel-Scan auf Port 502.
3. Für jede offene IP wird der Hersteller anhand weniger dokumentierter Standard-Unit-IDs und
   eines charakteristischen Registers pro Hersteller erkannt (kein voller 1-247-Scan).
4. Treffer erscheinen in der Ergebnistabelle — Klick auf „Erstellen" legt eine
   `InverterHub`-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.

**IPs ignorieren:** Adressen in dieser Liste werden beim Scan komplett übersprungen — gedacht
für RTU/TCP-Konverter und andere Modbus-Geräte, die sonst fälschlich als Wechselrichter in
der Ergebnisliste erscheinen (solche Geräte leiten Modbus-Anfragen an den dahinterliegenden
Bus weiter und antworten daher mit plausiblen Werten; zuverlässig unterscheiden lässt sich
das nicht). Mehrere IPs Komma-getrennt.

**Namens-Vorlage:** leer lassen für den Standard „Hersteller + laufende Nummer" (z. B.
„GoodWe 1", „GoodWe 2"), oder ein eigenes Muster mit den Platzhaltern `{hersteller}` `{ip}`
`{unitid}` `{nr}` eintragen (z. B. `{hersteller} Dach ({ip})`).

**Bekannte Einschränkung:** Filter/Aktualisieren (oberhalb) und Erstellen/Alle erstellen/
Zielkategorie-Auswahl (unterhalb) der Ergebnistabelle sind fester Bestandteil der nativen
IP-Symcon-Konfigurator-Ansicht — ihre Position und ein „einzeln als gesehen markieren" lassen
sich modulseitig nicht beeinflussen bzw. ergänzen (IP-Symcon-API-Grenze, keine Dokumentation
dafür vorhanden).

### Kacheln (`InverterHubTile`, `InverterHubEnergy`, `InverterHubMonitor`) — entfernt

Auf diesem Zweig (`ems-integration`) entfernt (20.08.2026): Die Aufgabe der Visualisierung
(Stromfluss-Kachel, Sankey-Diagramm, Monitoring/Diagnostik) übernimmt jetzt
[NRGDashboard](https://github.com/DG65/NRGDashboard). Mit `InverterHubMonitor` ist auch der
Diagnostik-Vertrag `IHUBMON_GetDiagnostics` entfallen. Die volle Doku dieser drei Module bleibt
in der Git-Historie dieses Repos erhalten.

## Fronius und SMA: Hinweis zur SunSpec-Discovery

Beide Hersteller sprechen den offenen SunSpec-Standard statt eigener Register (bei Fronius
von Fronius selbst so dokumentiert — Registeradressen sind **nicht konstant** und hängen von
der jeweiligen Modellkette ab; bei SMA folgt dieses Modul dem Vorbild von OpenEMS, das den
SMA Sunny Tripower ebenfalls rein über SunSpec anspricht). `FroniusDriver` und `SmaDriver`
durchlaufen deshalb bei jedem Lesezyklus die Modellkette ab Basisregister 40000 (Common Block,
dann Model-ID + Länge je Block), statt feste Adressen zu verwenden. Das ist etwas langsamer als
bei den anderen Herstellern, aber der zuverlässigste Weg.

## Verwandte Projekte

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, listet
[SUITE.md](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).

### EnergiePrognose (PV- und Verbrauchsprognose)

**[Prognose](https://github.com/DG65/NRGPrognose)** liefert die Prognosen, gegen die dieses
Projekt die Messwerte stellt. Genutzt wird davon das Modul **PV-Prognose** (Präfix `PVF`):

- Der **InverterHubMonitor** (Genutzt auf `main`/`beta`, auf `ems-integration` entfernt —
  Aufgabe jetzt bei NRGDashboard) berechnete aus den dort gepflegten Generatorparametern (kWp
  je Generator, Performance-Ratio) zusammen mit einem Einstrahlungssensor **Erwartungswerte**
  und stellte sie dem gemessenen Ertrag gegenüber. Damit lassen sich Verschmutzung und Defekte
  erkennen (Soll/Ist-Vergleich).
- Ist das Prognose-Modul nicht installiert, entfallen die Erwartungswerte; alles andere
  funktioniert unverändert.

### MeterHub (Energiezähler)

**[MeterHub](https://github.com/DG65/NRGMeterHub)** ist das Schwester-Repository dieses Projekts:
dasselbe Framework-Prinzip, aber für **Energiezähler** statt Wechselrichter (Modbus TCP, z. B.
Siemens PAC2200, Janitza UMG604/UMG800). Beide Projekte sind eigenständig nutzbar und ergänzen
sich, wo beide installiert sind:

- **Gerätesuche:** Der `InverterHubDiscovery` findet auf Wunsch in einem Durchlauf sowohl
  Wechselrichter als auch Zähler und legt Zähler direkt als MeterHub-Instanzen an. Ist MeterHub
  nicht installiert, werden gefundene Zähler übersprungen.

Die Kopplung ist optional: Keines der Module setzt das andere voraus.

## Installation

Über die IP-Symcon Modulverwaltung „Hinzufügen" mit der URL dieses Repositories:

```
https://github.com/DG65/NRGInverterHub
```

Für den Beta-Kanal den Zweig `beta` auswählen.

### Zusammenspiel mit den anderen Modulen: wie sie sich finden

Alle Kopplungen sind **optional** — kein Modul setzt ein anderes voraus. Fehlt ein Partner,
entfallen nur dessen Zusatzfunktionen.

| Kopplung | Wie sie zustande kommt |
|---|---|
| Gerätesuche → **MeterHub** | **automatisch**: gefundene Zähler werden als MeterHub-Instanzen angelegt |

### Empfohlene Reihenfolge

Die Reihenfolge ist nicht zwingend (nichts geht kaputt), erspart aber Nacharbeit:

1. **InverterHub** installieren, Wechselrichter anlegen — oder von der **Gerätesuche** finden lassen
2. **MeterHub**, falls Energiezähler vorhanden
3. **NRGDashboard**, für die Visualisierung (Stromfluss, Sankey, Monitoring/Diagnostik)

### Wie viele Instanzen?

- **Wechselrichter und Zähler:** beliebig viele — je Gerät eine Instanz.
- **EMS:** genau **eine**. Es ist die einzige Stelle, die steuernd auf die Batterie zugreift;
  eine zweite Instanz würde gegen die erste arbeiten.

## Mitwirken / Fehler melden

Rückmeldungen zu falschen Registerwerten, fehlenden Datenpunkten oder neuen unterstützten
Modellen gerne als Issue auf GitHub. Besonders hilfreich: Hersteller, Modellbezeichnung,
betroffenes Register/Ident und beobachteter vs. erwarteter Wert.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
