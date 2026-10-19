# Changelog

## 0.77.0-beta.38 (2026-10-19)

- **Solis: Gesamtverbrauch als Leistung.** Neue berechnete Variable „Last gesamt (Netz + Backup)"
  (`house_load_total`, Summe aus „Last am Netzanschluss" und „Last am Notstromausgang", archiviert). Solis versorgt
  das Haus teils oder ganz über den Backup-Ausgang; ein einzelner Messwert für den Gesamtverbrauch fehlte bisher.
  **Verbundvertrag `IHUB_GetFunctions` 1.3 → 1.4 (additiv):** neues Feld `houseLoadID` (ID der Variable
  `house_load_total`, 0 bei Treibern ohne diesen Wert); bisher nur Solis. Nichts entfernt oder umgedeutet, Major
  bleibt 1. Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.37 (2026-10-19)

- **Archivierung nachträglich einrichten (Schaltfläche).** Seit der Store-Review-Änderung (AutoArchive-Vorgabe
  „aus", 0.77.0-beta.23) archivieren neu angelegte Instanzen keine Messwerte mehr. Dashboard und EMS brauchen aber
  Archivwerte (z. B. Tages-/Verlaufsanzeigen: bei einer neuen Solis-Instanz blieb „heute" leer). Der Hinweis im
  Formular behauptete zudem, die Archivierung sei nur für Migrationen ausgeschaltet. Jetzt: ehrlicher Hinweistext
  und eine Schaltfläche **„Archivierung jetzt für alle empfohlenen Messwerte einrichten"**: Sie schaltet das
  Archiv für alle Variablen ein, die der Treiber als archivierungswürdig kennzeichnet und die noch nicht
  archiviert werden (inkl. Verdichtung wie sonst), und setzt das Häkchen „Messwerte automatisch archivieren" im offenen
  Formular (danach „Übernehmen"). Die Vorgabe bleibt aus (Store-Vorgabe); es geschieht nur auf ausdrücklichen Klick.
  Prüfstand `.tools/test-archive-now.php`.

## 0.77.0-beta.36 (2026-10-18)

- **Solis: Leistung je MPPT.** Der Solis meldet je Eingang nur Spannung und Strom (33049-33056) und
  gesamt die DC-Leistung (33057), aber keine Leistung je MPPT. Neu: „MPPT1-4 Leistung" als berechneter
  Wert (Spannung × Strom, in der Gruppe „PV-Details"). Damit findet auch der Verbundvertrag
  `IHUB_GetFunctions` (Felder `mpptPowerIDs`/`mpptCurrentIDs`/`mpptVoltageIDs`, z. B. für die MPPT-Tabelle im
  Dashboard) bei Solis jetzt die Stränge: Strom und Spannung nimmt er dafür aus den vorhandenen
  Variablen „PV1-4 Strom/Spannung" — nur wenn zu dem Strang eine MPPT-Leistung existiert, damit andere
  Treiber unberührt bleiben. Bestehende Variablen unverändert. Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.35 (2026-10-18)

- **Solis: Steuerung (Vorabversion, nur durchreichen).** Neue Gruppe „Steuerung" mit drei Schreibpunkten:
  „Zwangsladen/-entladen" (Register 43135: Aus, Laden, Entladen), „Zwangsleistung (W)" (43136 beim Laden,
  43129 beim Entladen, in Schritten zu 10 W, 0 bis 9900 W) und „Speicherbetrieb" (43110, nur ausgewählte
  Betriebsarten: Eigenverbrauch, Zeitladen, Backup/Reserve, Einspeisevorrang — **nicht** Inselbetrieb und
  Batterie-Wecken). Geschrieben wird nur, wenn die Steuerhoheit der Instanz auf „EMS" steht, wie bei den anderen
  Treibern. Gilt wie überall bei InverterHub: **keine eigene Wiederholung und kein Totmann** — wer
  Zwangsladen/-entladen dauerhaft halten will, schreibt regelmäßig neu (Aufgabe des EMS). Der Solis hat
  nach bisheriger Einschätzung (Dietmar) wie GoodWe einen Totmann: Der Zwangsmodus fällt vermutlich nach einiger Zeit ohne
  erneutes Schreiben zurück, die Dauer ist unbestätigt (Hinweis: Zeitlimit-Register 43282, in der Gruppe „Einstellungen"
  lesbar). Ohne Zeitlimit bliebe der Modus stehen, bis die SOC-Grenzen des Wechselrichters greifen. **Noch an keiner Solis-Anlage erprobt.** Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.34 (2026-10-18)

- **Solis: Aufbau wie bei GoodWe (Stufe 2).** Neu: Leistung und Strom je Phase am Smart Meter
  (Register 33252/33254/33256 und 33257-33262), Spannung und Strom je Phase am Notstromausgang (Gruppe
  „Backup / Insel", Register 33137/33138 und 33153-33156), Betriebsstatus (Stopp, Leerlauf, Sanftanlauf,
  Netzbetrieb, Inselbetrieb, Übergang, Backup-Bypass, Generatorbetrieb; Register 33287 laut Solis-Doku),
  BMS-Status (33111), Fehlercodes (33116-33120, 33124-33125, als erster Code und als Text) sowie
  Firmware-Stände DSP und HMI (33001/33002). Neue Gruppen „Backup / Insel" und „Status und Fehler"
  (abschaltbar). Bestehende Variablen unverändert. Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.33 (2026-10-18)

- **Solis: Aufbau wie bei GoodWe (Stufe 1).** Die Ordner entsprechen jetzt dem GoodWe-Treiber:
  „Batterie 1", „Batterie 2", „Batterie (gemeinsam)", „Netz", „Smart Meter", „Backup / Insel",
  „Energiezähler", „Wechselrichter / Gerät" und neu „Einstellungen". **Idents und Variablen-IDs der
  bisherigen Variablen bleiben unverändert** (Archive, Dashboard und EMS bleiben gültig), nur Ordner und
  Namen ändern sich. Neu je Akku (Gruppen „Batterie" und „Akku 2"): SOC, Leistung, Modus
  (Standby/Laden/Entladen), Temperatur, BMS-Spannung und -Strom, maximale Lade-/Entladeströme.
  „Bat. Leistung" und „Bat. SOC" liegen in „Batterie (gemeinsam)": Leistung als Summe, SOC als Mittelwert
  beider Akkus (bei nur einem Akku unverändert). Neu gerechnet wie bei GoodWe: maximale Lade- und
  Entladeleistung (BMS-Grenzstrom × Spannung) und Netzmodus (Einspeisung/Bezug aus dem Vorzeichen der
  Netzleistung). Die erst gestern eingeführten „Akku 2"-Variablen (`bat_b_*`) heißen jetzt `bat2_*`; die
  alten werden automatisch entfernt. Verbrauchszähler heißen „Last Heute/Gesamt (Netz + Backup)". Prüfstand
  `.tools/test-solis.php`.

## 0.77.0-beta.32 (2026-10-18)

- **Solis: Gruppe „Einstellungen" (nur lesen).** Vorbereitung für eine spätere Steuerung: Die
  Einstellungs-Register des Wechselrichters werden als Anzeige-Variablen gelesen (Speicherbetrieb 43110,
  erzwungenes Laden/Entladen 43135 mit Leistungen 43136/43129, Leistungsgrenzen 43130/43131, Netzabgleich
  43132, SOC-Grenzen 43010/43011/43018/43024, Lade-/Entladestrom 43012/43013, Fernsteuer-Zeitlimit 43282).
  Es wird **nichts geschrieben**. Die Register stammen aus der Home-Assistant-Integration
  `Pho3niX90/solis_modbus`, dem Plugin von wills106 und einer Abschrift der Solis-Dokumentation; die
  Gruppe wird einmal je langsamem Lesezyklus gelesen und lässt sich im Formular abschalten. Ob die Werte
  zum Portal passen, ist noch nicht bestätigt. Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.31 (2026-10-18)

- **Solis: zweiter Akku in der Gesamtleistung, Ordner und Namen aufgeräumt.** Die Register des
  zweiten Akkus (34275-34291, Funktionsbit 33097 Bit 6 „unabhängig") sind inzwischen auch aus einer
  weiteren, unabhängigen Quelle bestätigt (Projekt `glenmo/microgrid_remote_monitor`, abgeleitet vom
  Solis-Protokolldokument, dort „nur Modelle mit zwei Akkuanschlüssen"). „Bat. Leistung" ist deshalb
  jetzt bei zwei unabhängigen Akkus die **Summe beider Akkus** (die Leistung von Akku 2 ist
  Spannung × Strom, ein eigenes Register gibt es nicht); bei einem Akku ändert sich nichts.
  **Ordner und Namen (nur Solis, Variablen-IDs, Idents und Archive bleiben):** Akku 2 liegt im Ordner
  „Batterie 2"; „Hausverbrauch" (Register 33147) heißt jetzt „Last am Netzanschluss" und liegt unter
  Netz, „Backup-Last" heißt „Last am Notstromausgang (Backup)" und liegt unter „Backup / Insel";
  die Verbrauchszähler heißen „Verbrauch Heute/Gesamt (Netz + Backup)"; Netz- und Meterleistung
  tragen die Vorzeichenregel in der Bezeichnung. Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.30 (2026-10-18)

- **Solis: zweiter Akku als Vorabversion (nur Anzeige).** Bei Anlagen mit zwei unabhängig
  arbeitenden Akkus (Funktionsregister 33097, Bit 6) las InverterHub bisher nur Akku 1 und zeigte
  deshalb etwa die Hälfte der Akkuleistung. Neue, getrennte Variablen „Akku 2" (Spannung, Strom,
  SOC, berechnete Leistung) aus den Registern 34278 und 34289 bis 34291, abgeleitet aus einem
  Registerabzug und der Energiebilanz — **noch nicht am Portal bestätigt**. Die bisherige
  „Bat. Leistung" bleibt bewusst unverändert (nur Akku 1), bis der Abgleich mit dem Portal stimmt.
  Die neue Gruppe „Akku 2" lässt sich im Formular abschalten. Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.29 (2026-10-18)

- **Solis: Hausverbrauch als Energiezähler ergänzt** („Hausverbrauch Gesamt/Heute", Register
  33177-33180, wie Bezug/Einspeisung aufgebaut). Neue Variablen, bestehende bleiben unberührt.
  Hinweis: Der Wechselrichter bildet diesen Zähler als Restgröße (alles, was die übrigen Zähler nicht
  erklären) — er ist deshalb kein eigener Messwert. Prüfstand `.tools/test-solis.php` erweitert.

## 0.77.0-beta.28 (2026-10-17)

- **Solis: Batterieleistung hatte das falsche Vorzeichen, die Gesamtzähler waren um den Faktor 10
  zu klein.** Abgleich einer Solis-Anlage mit dem Solis-Portal: InverterHub zeigte beim Entladen
  −2900 W, bei Home-Assistant-Integration, evcc und openHAB ist der Rohwert in 33149/33150
  vorzeichenbehaftet mit positiv = Laden. Jetzt gilt wie überall bei InverterHub **+ = Entladen,
  − = Laden**; bei Firmware mit vorzeichenlosem Betrag entscheidet das Richtungsregister 33135.
  Der Batteriestrom folgt demselben Vorzeichen. **Gesamtzähler** (PV, Laden, Entladen, Bezug,
  Einspeisung) haben bei Solis 1 kWh Auflösung und wurden fälschlich durch 10 geteilt — sie
  springen damit einmalig auf das Zehnfache; im Archiv erscheint für diesen Zeitraum ein
  entsprechender Sprung. Die Tageswerte (0,1 kWh) waren richtig. **Hinweis:** Wer den
  Batterie-Invers-Schalter nur zum Ausgleich dieses Fehlers eingeschaltet hatte, muss ihn jetzt
  wieder ausschalten. Prüfstand `.tools/test-solis.php`.

## 0.77.0-beta.27 (2026-10-13)

- **Gerätetausch (vormals MigrationsHub/Vormund): Funktionspräfix `MIGHUB_` → `GTAUSCH_`.** Die
  Gerätesuche ruft beim Prüfen auf ältere Instanzen und beim Vorbereiten der Migration jetzt
  zuerst `GTAUSCH_FindLegacyCandidates()` bzw. `GTAUSCH_PrefillMigration()` auf, der alte Präfix
  bleibt als Rückfall (Parameter unverändert). Die Anzeigetexte nennen das Modul „Gerätetausch".
  Ohne das Partnermodul bleibt alles unverändert lauffähig. Der Eigenständigkeitsprüfer kennt
  beide Präfixe.

## 0.77.0-beta.26 (2026-10-12)

- **Store-Review-Fund (Checkliste Punkt 15):** Die Schreibpfad-Warnung des Symbox-Gateway-Clients
  nutzte `IPS_LogMessage()`. Sie läuft jetzt über `$this->LogMessage()` der Modulinstanz
  (Rückruf an die Hilfsklasse). Im Modul steht kein `IPS_LogMessage(` mehr; Prüfstand
  `.tools/test-gateway-client.php` um zwei Tests ergänzt. Keine Funktionsänderung für Nutzer.

## 0.77.0-beta.25 (2026-10-05)

- **Neuer Treiber: FoxESS H3 Smart / H3 Pro / KH (Read-Only-Vorabversion, Beta).** Forum-Meldung
  (hbraun, 05.10.2026): Ein H3 Smart wurde weder gefunden noch ausgelesen. Ursache: Diese Geräte
  sprechen nicht die Registerbelegung 10000/11000 (FC04) des bisherigen FoxESS-Treibers, sondern
  Holding-Register (FC03) im Bereich 37xxx/38xxx/39xxx. Registerbelegung, Skalierung und
  Wortreihenfolge an der Home-Assistant-Integration `nathanmarlor/foxess_modbus` abgeglichen
  (Profil H3_SMART). Neuer Hersteller „FoxESS H3 Smart / H3 Pro / KH"; der bisherige Eintrag
  „FoxESS H1/H3" bleibt unverändert. Geliefert werden PV-Leistung und -Strings, Netzleistung
  (Netzmesspunkt), Batterieleistung/SOC/BMS-Werte, Netzspannung/-frequenz, Temperatur und
  Energiezähler (Tag/Gesamt). Keine Steuerung. **Noch nicht an echter Hardware bestätigt** —
  Rückmeldungen im Forum willkommen.
- **Gerätesuche:** erkennt den H3 Smart jetzt über Netzspannung (39123) und Netzfrequenz (39139).
  Prüfstand `.tools/test-foxess-smart.php`.

## 0.77.0-beta.24 (2026-09-28)

- **Store-Review-Fund 9m (HeishaMon-Sitzung):** `IPS_SetPosition()` setzte bei jeder Variable
  bei **jedem** `ApplyChanges()` die feste, treiberdefinierte Reihenfolge durch — zog ein Nutzer
  eine Variable im Objektbaum manuell an eine andere Stelle, warf ihn der nächste
  „Übernehmen"-Klick kommentarlos wieder in die Ursprungsreihenfolge zurück. Die Position wird
  jetzt nur noch beim erstmaligen Anlegen einer Variable gesetzt, danach bleibt eine manuelle
  Umsortierung erhalten. Kategorie/Name werden weiterhin bei jedem Durchlauf durchgesetzt (z. B.
  nötig bei einem Wechsel der Steuerhoheit). Prüfstand `.tools/test-varposition-guard.php`.

## 0.77.0-beta.23 (2026-09-28)

- **Store-Review-Fund (HeishaMon-Sitzung, Symcon lehnte deren v1.33.0 aus demselben Grund ab):**
  „Messwerte automatisch archivieren" stand standardmäßig auf AN. Ein Nutzer soll die
  automatische Archivierung bewusst einschalten, nicht nachträglich abwählen müssen — der
  Standard ist jetzt AUS. Ändert nur den Vorschlagswert für **neu angelegte** Instanzen,
  bestehende Instanzen behalten ihren gespeicherten Wert unverändert.

## 0.77.0-beta.22 (2026-09-23)

- **Fix: Tageszähler blieben nach dem morgendlichen Aufwecken stundenlang auf dem Vortageswert
  stehen** (Fund Dietmar, eigene Anlage #52838, 23.09.2026): Der Zählerschutz vom 17.09.2026
  (0.77.0-beta.4) sollte nur echte Ausreißer bei kumulativen Summenzählern verwerfen, erkannte
  aber jeden `e_*`-Ident pauschal als schützenswert — auch Tageszähler wie `e_pv_day` oder
  `e_charge_day`, die täglich legitim auf 0 zurückspringen. Dadurch wurde der morgendliche
  Reset selbst als Ausreißer gewertet und dauerhaft verworfen, bis der Wechselrichter wieder
  einen Wert über 0,001 kWh lieferte — bei `e_charge_day` (erst ab der ersten Ladung des Tages)
  teils den ganzen Vormittag. Tageszähler (Endung `_day`, herstellerunabhängig) sind jetzt vom
  Zählerschutz ausgenommen, Summenzähler bleiben wie zuvor geschützt. Regressionstest
  `.tools/test-energy-guard.php` um sieben Tageszähler-Fälle erweitert.

## 0.77.0-beta.21 (2026-09-21)

- **Fix: „The float -3.4028234663852886E+38 is not representable as an int“ im FastTimer**
  (Forum-Beta-Tester somm, SolarEdge, Screenshot vom 21.09.2026): SolarEdge meldet nicht belegte
  Float32-Register als −3,4028235E+38 (kleinster Float32-Wert, 0xFF7FFFFF). Der StorEdge-
  Batterieblock wandelte SOC und SOH mit `(int)round(...)` um, das erzeugt eine PHP-Warnung und
  einen Zufallswert; dieselbe Umwandlung gab es beim Kostal-SOC. Sie ist jetzt abgesichert, ein
  unbrauchbarer Wert wird nicht geschrieben, der alte Stand bleibt stehen. Zusätzlich verwirft
  `SetVarFloat()` zentral jeden Wert ab 3,0E+38 im Betrag (auch nach einer Vorzeichenumkehr im
  Treiber, dann +3,4E+38): Temperatur, Spannung, Strom und Leistung der Batterie gingen bisher
  ungefiltert in Variable und Archiv, das erklärt vermutlich auch die früher gemeldeten
  unmöglichen Einzelwerte. Die Marke wird einmal je Messgröße im Meldungsfenster genannt, nicht bei
  jedem Lesezyklus. Echte Werte, auch sehr große (29,9 kW), sind nicht betroffen. Prüfstand
  `.tools/test-float-sentinel.php`.

## 0.77.0-beta.20 (2026-09-21)

- **Verbindungen im Formular sichtbar machen** (neue Verbund-Konvention, SUITE.md 21.09.2026: „woher
  soll ich wissen, ob die Verbindung zustande gekommen ist?“): Jede Verbindung zeigt jetzt eine live
  berechnete Statuszeile, nicht nur einen statischen Doku-Satz.
  - **Gerätesuche:** neues Panel „Verbundene Module“ mit je einer Zeile für MeterHub und MigrationsHub:
    ✅ installiert (Version, Anzahl bzw. ID der Instanzen, was die Verbindung bewirkt) oder ℹ️ nicht
    installiert (und was dann gilt: nur Wechselrichter bzw. keine Prüfung auf ältere Instanzen). Die
    Zeilen legen nie eine Instanz an, auch nicht die von MigrationsHub.
  - **Symbox-Gateway (InverterHub):** unter der Brückenauswahl steht eine Zeile zur gewählten
    Brücke: ✅ Brücke und Gateway mit gelesener Geräte-ID, ⚠️ Brücke ohne Gateway, Gateway inaktiv oder
    Brücken-Modul veraltet, ℹ️ keine Brücke gewählt. Sie wird beim Öffnen berechnet, beim
    Auswahlwechsel und nach „Brücke anlegen und verbinden“ sofort nachgeführt und mit dem
    Verbindungsweg ein- und ausgeblendet.
  - Der Kern von InverterHub hat sonst keine automatische Verbindung zu anderen Modulen (Kopplungen
    laufen über die Verträge der Partner, nicht über Auswahlfelder hier).
  - Prüfstand `.tools/test-link-status.php`: alle Zustände je Zeile und dass jede Zeile als Element in
    den ausgelieferten Formularen steht.

## 0.77.0-beta.19 (2026-09-20)

- **Anzeigenamen vereinheitlicht: genau EIN Alias je Modul** (Forum-Feedback Mstaudi, Abgleich mit
  MeterHub): Jeder Alias in `module.json` erscheint in „Instanz hinzufügen“ als eigener Eintrag und
  ist der vorgeschlagene Instanzname, mehrere Aliase wirkten wie Duplikate. Jetzt: „NRG-Stack
  InverterHub“, „NRG-Stack InverterHub Suche“, „NRG-Stack InverterHub Brücke (ModBus-Gateway)“; die
  entfallenen Kachel-Module heißen „NRG-Stack InverterHub Kachel/Monitoring/Energiefluss
  (entfallen)“. Modulname, GUID und Präfix sind unverändert, bestehende Instanzen behalten ihren
  Namen. Nachteil: Die Suchbegriffe der entfernten Aliase (z. B. „Wechselrichter“) finden die Module
  in der Schnellsuche nicht mehr.
- **Statuszeile 104 in jedem Verbindungsweg neutral:** „Bitte Verbindung einstellen.“ Die Zeile folgt
  dem gespeicherten Stand und lässt sich im offenen Formular nicht live umschalten (Mstaudi wechselte
  im offenen Formular von Symbox auf Direkt und sah weiter den Brücken-Text). Status 201 bleibt je
  Verbindungsweg (Gateway: „keine Antwort über die Brücke …“, Direkt: „Wechselrichter nicht
  erreichbar“), er tritt nur bei laufender Instanz auf. Test `.tools/test-create-bridge.php` prüft
  Alias und Statuszeilen.

## 0.77.0-beta.18 (2026-09-19)

- **Gateway-Modus: einheitliche Wörter und passende Statuszeile** (Feedback Forum-Tester Mstaudi,
  Abgleich mit MeterHub und ChargerHub): Die Statuszeile ganz oben nannte im Gateway-Modus
  „Wechselrichter nicht erreichbar“ bzw. eine IP-Adresse. Sie zeigt jetzt Status 104 „Bitte die
  Brücke zum ModBus Gateway eintragen (Gateway wählen, „Brücke anlegen und verbinden“,
  übernehmen)“ und 201 „Verbindungsfehler – keine Antwort über die Brücke: Brücke und ModBus
  Gateway prüfen (Unit ID = Geräte-ID am Gateway)“. Im Direktmodus bleibt alles unverändert.
  Ohne gewählte Brücke steht die Instanz im Gateway-Modus auf 104 mit gestoppten Timern (statt
  auf 201 mit laufendem Lesezyklus). Feldnamen vereinfacht: „ModBus Gateway zum Gerät“ und
  „NRG-Stack Brücke zum ModBus Gateway“ (der längere Name wurde abgeschnitten), der Knopf heißt
  „Brücke anlegen und verbinden“. Die Texte in Doku-Panel, News, README und Formularhinweis sind
  angeglichen. Prüfstand `.tools/test-create-bridge.php` um diese Wörter und die Bereitschaft
  erweitert.

## 0.77.0-beta.17 (2026-09-19)

- **Symbox-Gateway läuft jetzt über eine eigene Brücke: neues Modul „NRG-Stack InverterHub
  Brücke (ModBus-Gateway)“ (`InverterHubBridge`, Prefix `IHUBB`)** (Entscheidung Dietmar,
  Abstimmung mit MeterHub/ChargerHub, gleicher Vertrag in allen drei Brücken). Ursache:
  `parentRequirements`/`implemented` in `InverterHub/module.json` ließen bei JEDER
  Direkt-Instanz den Hinweis „benötigt eine übergeordnete Instanz“ erscheinen. Diese
  Schnittstellen trägt jetzt nur noch die Brücke, die `module.json` des Hauptmoduls ist wieder
  leer — im selben Release, damit bestehende Gateway-Instanzen nicht ohne Alternative
  wegbrechen. Wer den Gateway-Modus nutzt: Brücke anlegen, in ihr das ModBus-Gateway wählen,
  in der InverterHub-Instanz die Brücke auswählen (Eigenschaft `BridgeInstanceID`). Eine
  Brücke bedient genau ein Gerät (Geräte-ID am Gateway). Vertrag der Brücke: `Forward(json)`
  liefert immer JSON mit `ok` und `data` (Base64) oder `ok=false` und `error` (`not_connected`,
  `parent_inactive`, `no_response`), `GetState()` liefert Verbindungs- und Gateway-Status. Die
  Statustexte des Hubs (201) nennen jetzt den Grund: keine Brücke gewählt, Brücke ohne Gateway,
  Gateway inaktiv, keine Antwort. Tests `.tools/test-bridge.php` und erweiterter
  `.tools/test-gateway-client.php` (rohe Bytes wie 0xFFFF hin und zurück über die Brücke).
  Direktverbindungen sind nicht betroffen. Noch nicht am echten Gateway geprüft; der
  Schreibpfad (FC6/FC16) bleibt eine ungetestete Ableitung.

  Zusätzlich (Abgleich mit MeterHub): Im Formular wählt man das ModBus-Gateway und klickt „… und
  Brücke anlegen und verbinden“ (`CreateBridge()`): legt die Brücke neben der Instanz an, verbindet
  sie, nutzt eine vorhandene Brücke am selben Gateway wieder und trägt sie ins offene Formular ein
  (danach „Übernehmen“). Es wird nie automatisch in `ApplyChanges()` angelegt. Gateway-Auswahl, Knopf
  und Brückenauswahl sind nur im Verbindungsweg „Symbox-Gateway“ sichtbar. Dokumentation:
  Doku-Panel, Formularhinweise mit den Schritten, README-Abschnitt und News-Eintrag inklusive Hinweis
  für alle, die den Gateway-Weg mit früheren Beta-Ständen direkt an der Instanz eingerichtet hatten
  (Instanz und Historie bleiben, Gateway wählen, Knopf klicken, übernehmen). Test
  `.tools/test-create-bridge.php` (Anlegen, Verbinden, Wiederverwenden, Fehlerfälle, beide
  `module.json`, Sichtbarkeit der Felder).

## 0.77.0-beta.16 (2026-09-19)

- **Fehler im Timer-Lauf landen jetzt im Meldungsfenster** (Forum-Beta-Tester Mstaudi: „meldet
  alles ok, aktualisiert aber nicht“): `ReadFast()` fing Fehler ab und gab den Text nur als
  Rückgabewert zurück, den der Timer verwirft — ein scheiternder Lesezyklus (z. B. „Call to
  undefined method“) blieb unsichtbar, nur der Knopf „Daten sofort lesen“ zeigte ihn. Fehler
  sowie die Gateway-Warnungen „Kein Gateway verbunden“ und „Gateway antwortet nicht“ werden
  jetzt als Fehler protokolliert, einmalig statt alle paar Sekunden (erneut erst bei geändertem
  Text), und beim Wiederanlaufen kommt genau eine Meldung „Lesezyklus läuft wieder“. Test
  `.tools/test-read-problem-log.php`. Gilt für alle Verbindungswege, am Datenpfad ändert sich
  nichts.

## 0.77.0-beta.15 (2026-09-19)

- **Symbox-Gateway-Modus: Unit ID ausgeblendet, Status bei ausbleibender Antwort**
  (MeterHub-Hinweis nach Forum-Rückmeldung Mstaudi): Die Nutzlast an das native Gateway enthält
  keine Unit-ID, unser Feld wurde dort nie gesendet und täuschte eine Wirkung vor. Im Gateway-
  Modus ist „Unit ID“ jetzt ausgeblendet, der Formulartext verweist auf die Geräte-ID der
  Gateway-Instanz (Herleitung aus dem Referenzprotokoll von Symcons Modul EM24-DIN, nicht am
  echten Gateway geprüft). „Verbindung aktiv“ sagte bisher nichts über echte Antworten: Der
  Lesezyklus setzt im Gateway-Modus jetzt Status 201, wenn kein Gateway verbunden ist oder das
  Gateway nicht antwortet, und wieder 102 bei Antworten; der Knopf „Daten sofort lesen“ nennt
  die Ursache. `unitId` im Gateway-Client ist schreibbar, damit Treiber, die `$mb->unitId`
  umsetzen (SunSpec, Victron), nicht an einem privaten Feld mit Fatal Error scheitern. Test
  erweitert. Der Direktweg ist unverändert.

## 0.77.0-beta.14 (2026-09-19)

- **Fix Symbox-Gateway-Modus: Lesezugriff brach mit „Call to undefined method" ab** (Forum-Beta-
  Tester Mstaudi, Growatt: „Aktualisieren tut es auch noch nicht"): Alle Treiber rufen am Client
  `u16`, `s16`, `u32`, `s32`, `readStr` und `readFloat32` auf. `IHUB_ModbusGatewayClient` hatte
  sie nicht, der erste Lesezugriff endete deshalb in einem Fatal Error, obwohl das Formular
  „Verbindung aktiv" zeigte. Die Dekodier-Hilfen sind jetzt auch dort vorhanden,
  `setFloatWordSwap()` (Kostal) wirkt statt ein No-Op zu sein. Neuer Regressionstest in
  `.tools/test-gateway-client.php`: jede Methode, die Treiber am Client aufrufen, muss im
  Gateway-Client existieren, und die Hilfen dekodieren wie beim Direktweg. Der Direktweg ist
  unverändert.
- **Bekannte Einschränkung, nicht behoben:** Die Nutzlast an das native Gateway enthält keine
  Unit-ID (Schema von Symcons Referenzmodul); ob das Feld „Unit ID" im Gateway-Modus wirkt oder
  im Gateway selbst eingestellt wird, ist ungeklärt. Treiber, die für Zusatzgeräte einen eigenen
  Direkt-Client mit `$mb->host` anlegen (Zähler bei Fronius/SMA/SolarEdge, Victron), sind im
  Gateway-Modus nicht nutzbar.

## 0.77.0-beta.13 (2026-09-19)

- **Fix GoodWe: `ctl_ems_enable=true` schreibt jetzt 1 statt 2 in Register 47505** (EMS-Live-Test
  19.09.2026 an der Anlage, Register per FC6 direkt geschrieben): Der bisherige Wert 2 (Erbe des
  GoodweET-Ports, ohne dokumentierten Grund) erzeugte eine langsame Rampe — ~15 s Anlauf, dann
  +250 W je 6 s, bei Modus 9 lud nur ein Turm. Mit 1 lag die volle Leistung (23,9 kW, beide Türme
  je ~11,8 kW) schon ~1 s nach dem Schreiben an und hielt ohne Rückfall. `false` schreibt
  weiterhin 0. Das Register wird nicht zurückgelesen, die Anzeige bleibt unverändert.
  Regressionstest `.tools/test-goodwe-enable.php`. Betrifft nur GoodWe-Instanzen, die
  EMS-Steuerung mit `enable=true` nutzen.

## 0.77.0-beta.12 (2026-09-19)

- **Fix Symbox-Gateway-Modus: Instanz blieb auf Status 104** (MeterHub-Fund, Forum-Beta-Tester):
  Im Gateway-Modus ist `Host` leer, `ApplyChanges()` verlangte aber immer einen Host, setzte
  Status 104 und stellte alle Timer auf 0 — die Instanz las nie, egal ob ein Gateway verbunden
  war. Die Host-Pflicht gilt jetzt nur noch im Direktmodus. `ForwardToGateway()` sendet ohne
  verbundenes Gateway (`ConnectionID <= 0`) nichts mehr, statt bei jedem Takt Symcons Warnung
  „Keine übergeordnete Instanz ist konfiguriert" zu erzeugen. Im Formular sind Host und Port im
  Gateway-Modus ausgeblendet (Umschalten ohne Übernehmen über `RequestAction`), der veraltete
  Platzhaltertext „liefert aktuell keine Werte" ist durch eine zutreffende Beschreibung
  ersetzt: Lesen ist umgesetzt, Schreiben ungetestet. Regressionstest um den Fall ohne Gateway
  erweitert. Direktverbindungen sind nicht betroffen.

## 0.77.0-beta.11 (2026-09-19)

- **Symbox-Gateway: `implemented` in `module.json` ergänzt** (MeterHub-Fund, live am nativen
  „ModBus Gateway" per `IPS_GetModule()` gelesen): Das Gateway akzeptiert nur Kinder, die die
  Schnittstelle `{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}` in `implemented` führen — `parentRequirements`
  allein (0.77.0-beta.9) reichte nicht, die Konsole zeigte keine Auswahl. Symcons Referenzmodul
  (SymconBC EM24-DIN) trägt beides. Rein deklarativ, keine Laufzeitänderung. Ob der
  Anlege-Ablauf bestehender Direktverbindungs-Instanzen dadurch berührt wird, ist unverifiziert;
  die Entscheidung Schwestermodul ja/nein (wie WPModbusHubGateway) liegt bei Dietmar.

## 0.77.0-beta.10 (2026-09-18)

- **Log-Rauschen behoben: „Der zu löschende Verdichtungseintrag wurde nicht gefunden"**
  (Screenshot Dietmar, Statusdialog): Die automatische Archiv-Verdichtung setzt eine
  deaktivierte Stufe aktiv auf Typ -1 (löschen), damit alte Regeln verschwinden. Existiert
  keine Regel, warnt Symcon bei jedem Übernehmen für jede Variable — harmlos, aber
  irreführend im Protokoll. Die Warnung wird jetzt nur für diesen Lösch-Fall unterdrückt;
  echte Fehler beim Setzen einer Regel bleiben sichtbar.

## 0.77.0-beta.9 (2026-09-18)

- **Fix: `parentRequirements` fehlte für den Symbox-Gateway-Modus** (MeterHub-Fund, live gegen
  den Solarpark verifiziert): Symcons natives „ModBus Gateway"-Modul implementiert die
  Splitter-Schnittstelle `{E310B701-4AE7-458E-B618-EC13A1A6F6A8}` (dieselbe GUID, die wir
  bereits als `DataID` in `SendDataToParent()` verwenden). Ohne diesen Eintrag in
  `InverterHub/module.json` zeigt die Symcon-Konsole gar keine Verbindungsmöglichkeit zu einem
  passenden Gateway an, unabhängig davon, ob eines existiert — genau das von einem
  Beta-Tester bei MeterHub gemeldete „will keine Verbindung aufbauen". Jetzt ergänzt:
  `"parentRequirements": ["{E310B701-4AE7-458E-B618-EC13A1A6F6A8}"]`. Rein deklarativ, keine
  Laufzeitänderung, keine Auswirkung auf bestehende Direktverbindungs-Instanzen — eine Instanz
  wird künftig über das 🔌-Symbol am Kopf der Instanzkonfiguration manuell an ein natives
  Gateway angebunden, keine Sonderfunktion nötig.

## 0.77.0-beta.8 (2026-09-18)

- **Fix: `ForwardToGateway()` konnte gepackte Registerbytes lautlos verschlucken**
  (MeterHub-Fund 18.09.2026): rohe Registerbytes (z. B. `0xFFFF`) sind meist kein gültiges
  UTF-8, `json_encode()` scheitert dabei **still** (liefert `false` statt Fehler/Warnung) —
  ohne Gegenmaßnahme wäre ein solcher Schreibwert unbemerkt gar nicht verschickt worden.
  `Data` wird jetzt vor dem `json_encode()` base64-kodiert, zusätzlich bricht
  `ForwardToGateway()` bei einem `json_encode()`-Fehlschlag sauber mit `false` ab statt einen
  kaputten String zu senden. Regressionstest `.tools/test-gateway-client.php` um einen
  Testfall mit `0xFFFF` (statt eines zufällig UTF-8-verträglichen Werts) erweitert, der den
  Fehler zuverlässig fängt. Betrifft ausschließlich den ohnehin als ungetestet markierten
  Symbox-Gateway-Schreibpfad.

## 0.77.0-beta.7 (2026-09-18)

- **Symbox-Gateway-Lesepfad implementiert (SUITE.md 9j):** `IHUB_ModbusGatewayClient` liest
  jetzt echt über `SendDataToParent()`/`ForwardData()`, nach dem von MeterHub direkt am Rohcode
  des offiziellen SymconBC-Referenzmoduls (`EM24-DIN`) verifizierten Payload-Schema
  (`Function`/`Address`/`Quantity`/`Data` als JSON, Antwort roh mit FC+ByteCount-Präfix,
  danach 16-Bit-Register big-endian). Neue öffentliche `ForwardToGateway()`-Methode am
  Hauptmodul, da `SendDataToParent()` in der IPSModule-Basisklasse `protected` ist und von der
  Client-Hilfsklasse nicht direkt aufgerufen werden kann. Regressionstest
  `.tools/test-gateway-client.php`. Der Schreibpfad (`writeSingle`/`writeMultiple`, FC6/FC16)
  ist weiterhin eine **ungetestete Ableitung** aus demselben Schema — im SymconBC-
  Referenzmodul gibt es dafür kein Beispiel, es ist ein reiner Lese-Zähler. Formular und Log
  weisen weiterhin klar darauf hin. Bewusst KEIN `ConnectParent()` in `Create()` ergänzt — das
  würde jede der ~240 bestehenden Instanzen zwingen, einen nativen Gateway-Parent im
  Objektbaum zu haben, und damit den bisherigen Direktverbindungs-Betrieb brechen.

## 0.77.0-beta.6 (2026-09-18)

- **Fassade für Symbox-Gateway-Anbindung ergänzt (SUITE.md 9j, noch nicht funktionsfähig):**
  neue Property „Verbindungsweg" (Direkt/Symbox-Gateway) im Panel „Verbindung".
  `GetModbusClient()` wählt danach `IHUB_ModbusTcpClient` (bisheriger Weg) oder die neue
  `IHUB_ModbusGatewayClient` — dieselben vier Methoden (`readHolding`/`readInput`/
  `writeSingle`/`writeMultiple`), damit Treiber unverändert bleiben. Mit MeterHub/ChargerHub
  abgestimmtes gemeinsames Interface für die künftige `SendDataToParent()`/`ForwardData()`-
  Anbindung an den eingebauten RS485-Port. Der Gateway-Client ist bewusst nur ein Stub (liefert
  `null`/`false` + einmaligen Log-Hinweis) — das native `ForwardData()`-Payload-Schema ist
  nirgends öffentlich dokumentiert, wird erst mit echter Symbox-Hardware oder einer
  Forum-Antwort geklärt. Formular warnt deutlich, dass „Symbox-Gateway" aktuell keine Werte
  liefert; externe RTU-zu-TCP-Gateways funktionieren unverändert über „Direkt".

## 0.77.0-beta.5 (2026-09-17)

- **Plausibilitätsschutz für SOC/SOH ergänzt** (Folgefund Stefan/somm, SolarEdge): Batterie-
  Ladezustand und -Gesundheit sind immer 0–100 % — ein einzelner Lesezyklus außerhalb dieser
  Grenze wird jetzt verworfen (alter Stand bleibt stehen), statt als Ausreißer ins Archiv zu
  gelangen. Betrifft nur Idents, die auf `_soc`/`_soh` enden (Steuer-Sollwerte wie
  `ctl_soc_min`/`ctl_soc_max` sind nicht betroffen). Regressionstest
  `.tools/test-percent-guard.php`. Für die von Stefan zusätzlich gemeldeten PV-Leistungs-
  Abweichungen fehlt noch eine belastbare Grenze (Leistung skaliert mit der Anlagengröße,
  ein fester Wert würde bei anderen Nutzern echte Werte verwerfen) — hier warten wir auf
  weitere Details.

Ältere Versionen: [CHANGELOG-Archiv.md](CHANGELOG-Archiv.md)
