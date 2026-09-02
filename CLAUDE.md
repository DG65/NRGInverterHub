# Hinweise für die Arbeit an diesem Repository

## `IHUB_ModbusTcpClient` verwertete Antworten ohne Transaktions-ID-Prüfung (02.09.2026)

Real gemeldet (Dashboard-Sitzung): Auf Dietmars Anlage (#52838) wurde nachts gegen 03:00 Uhr ein
einzelner archivierter PV-Leistungswert von 261.554.185 W (261,5 MW) geloggt — physikalisch bei
einer 9,18-kWp-Anlage unmöglich, hat Dashboards Tagesmittel-Näherung verzerrt. Zerlegt
(`261554185 = 0x0F970009`): High-Wort 3991, Low-Wort 9 — zwei plausibel aussehende, aber
zueinander unpassende Registerhälften, keine zufällige Bitkippung.

**Root Cause:** `readHolding()`/`readInput()` in `IHUB_ModbusTcpClient` prüften die Modbus-TCP-
Transaktions-ID (MBAP-Header) der Antwort **nie** gegen die der eigenen Anfrage. Im Batch-Modus
(eine wiederverwendete Verbindung für alle Reads eines Zyklus) kann ein einzelner Read über sein
3s-Zeitlimit laufen und als fehlgeschlagen gelten, während seine Antwort kurz danach doch noch
auf derselben Verbindung eintrifft. Ohne TID-Prüfung wurde dieser verspätete Rest-Frame beim
NÄCHSTEN Read desselben Zyklus als dessen eigene Antwort fehlinterpretiert. Betraf potenziell
alle 15 Treiber (geteilte Basisklasse). Details/Fix identisch zur `ems-integration`-Notiz (nicht
dupliziert, dort ausführlicher dokumentiert).

**Fix (0.76.1-beta.1):** `readRegisters()`/`readMbapFrame()` sammeln jeden Frame vollständig über
das MBAP-Längenfeld ein und prüfen die Transaktions-ID; Nichttreffer werden verworfen. Mit einem
Socket-Pair-Testskript gegen die exakt gemeldeten Werte (3991/9) verifiziert.

## Der Modul-Verbund

Dieses Repo gehört zu einer Gruppe eigenständiger IP-Symcon-Module, die zusammenwirken. An
ihnen wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet, die sich auf
gemeinsame Regeln und dokumentierte Schnittstellen geeinigt haben.

| Modul | Rolle | Repo / lokale Kopie | Vertrag zu uns |
|---|---|---|---|
| **InverterHub** (dieses Repo) | Wechselrichter messen, darstellen, steuern | `DG65/InverterHub` | — |
| **MeterHub** | Energiezähler (Modbus TCP) | `DG65/MeterHub` · `../MeterHub` | `MHUB_GetFunctions($id)` |
| **Prognose** (EnergiePrognose) | PV- und Verbrauchsprognose | `DG65/Prognose` · `../Prognose` | `PVF_GetGenerators`, `PVF_GetModuleArea(s)`, `PVF_GetForecast` |
| **HeishaMon** | Panasonic-Wärmepumpe | `DG65/HeishaMon` | `HEISHA_GetFunctions($id)` (ab v1.1.1) |
| **StromGedacht** | Netzampel (TransnetBW) | `DG65/StromGedachtWidget` | noch keiner; `SGW_GetState()` auf Zuruf zugesagt |
| **Tibber Grid Rewards** | Erlös-/Vermarktungssignale **und Preisquelle** | `DG65/TibberGridRewards` | `TIBBERGR_GetPriceCurve($id)` (ab v2.1.1); Signale weiter über Statusvariablen `Delivering`, `GridRewardMode`, `GridRewardWallboxRequest` |
| **Tessie** | Tesla-Fahrzeuge (Wallbox-SOC) | `DG65/Tessie` | bewusst keiner — rein konfigurativ |
| **EMS** | Entscheidungslogik / Batteriefahrweise | EMS-Repo · `../EMS` | noch keiner (`EMS_GetStatus`, `EMS_SetECOWindow`, `EMS_PlanNightCharge`) |
| **ChargerHub** | Wallboxen (Modbus TCP) | `DG65/ChargerHub` | noch keiner — Gerüst (v0.1.0) |
| **MigrationsHub** | Übernahme von Bestandsgeräten und Archivwerten | `DG65/MigrationsHub` | noch keiner — Gerüst (v0.1.0) |

### `beta` ist Produktion, nicht Vorbereitung

**Der Beta-Zweig wird NICHT reviewt und sofort ausgeliefert.** Wer im Module Store auf den
Beta-Kanal gestellt hat, bekommt jeden Push binnen Minuten auf die laufende Anlage — es gibt
keinen Puffer, keine Freigabe, keinen Zwischenschritt.

Daraus folgt: **Vor jedem Push beide Prüfskripte ausführen** (siehe unten) und Änderungen an
Treibern nur pushen, wenn sie zumindest syntaktisch und strukturell geprüft sind. Ein Fehler
hier ist kein „defekter Build", sondern eine abstürzende Instanz bei realen Nutzern.

Real passiert: Die Builds 145 und 146 riefen wegen eines Textersatzes in der falschen Klasse
Methoden auf, die dort nicht existierten — SMA- und Fronius-Instanzen liefen dadurch bis zur
Korrektur in einen Fatal Error, und zwar sofort nach dem Push.

### Grundregel: jedes Modul bleibt eigenständig — und das wird geprüft

Kein Modul darf ein anderes voraussetzen. Kopplungen liegen hinter `function_exists(...)`
bzw. `IPS_ModuleExists(...)`; fehlt der Partner, entfallen nur Zusatzfunktionen — es darf
nichts brechen.

**Das ist kein Stilthema.** Der Aufruf einer nicht vorhandenen Funktion ist in PHP ein
**Fatal Error**. Das oft vorangestellte `@` unterdrückt ihn **nicht** — es unterdrückt nur
Warnungen. Fehlt der Wächter und ist das Partnermodul nicht installiert, bricht die Instanz
hart ab, statt die Zusatzfunktion wegzulassen.

Damit die Zusage jederzeit belegbar ist statt nur behauptet:

```
php .tools/check-standalone.php
```

Der Prüfer durchsucht alle PHP-Dateien nach Aufrufen fremder Modulpräfixe (`MHUB_`, `PVF_`,
`HEISHA_`, `SGW_`, `TIBBERGR_`, `TESSIE_`, `EMS_`, `GWET_`) und meldet jeden, der **in seiner
aufrufenden Funktion** keinen passenden `function_exists()`-Wächter hat. Kommentare und
Zeichenketten werden vorher entfernt, damit dokumentierte Beispielaufrufe keinen Fehlalarm
auslösen. Rückgabewert 0 = sauber, 1 = mindestens eine ungesicherte Stelle (für CI geeignet).

**Vor jedem Release ausführen**, und bei jeder neuen Kopplung. Kommt ein Partnermodul dazu,
dessen Präfix in `FOREIGN_PREFIXES` ergänzen — sonst prüft der Prüfer daran vorbei.

### Klassengrenzen prüfen — `InverterHub/module.php` hat 15 Treiberklassen

```
php .tools/check-class-scope.php
```

Meldet jeden `$this->foo()`-Aufruf, dessen Methode **in einer anderen Klasse derselben Datei**
definiert ist. Zur Laufzeit wäre das ein Fatal Error, sobald der Zweig ausgeführt wird.

**Warum das hier real passiert ist:** `SmaDriver`, `FroniusDriver` und `SolarEdgeDriver` sprechen
alle SunSpec und enthalten deshalb **wortgleiche Codeblöcke** (etwa `'GroupDevice' => [...
dev_model ... dev_sn ...]`). Ein Textersatz trifft dann die erstbeste Fundstelle statt der
gemeinten. Genau so landete der Fronius-Isolationswiderstand im SMA-Treiber und riss die Builds
145 und 146 auf.

**Daher vor jedem Textersatz in dieser Datei prüfen, in welcher Klasse die Fundstelle liegt** —
und den Prüfer vor dem Release laufen lassen. Er ist von der MeterHub-Seite beigesteuert.

### Keine sichtbaren Hilfsordner im Repo-Wurzelverzeichnis

Der Ordner heißt `.tools` mit **führendem Punkt**, und das muss so bleiben: Die Prüfung des
Symcon Module Store behandelt **jeden sichtbaren Top-Level-Ordner als Modul** und verlangt dort
eine `module.json`. Ein sichtbarer Ordner `tools` lässt die Einreichung mit „Das Modul tools hat
keine module.json" scheitern — real passiert bei der Tibber-Einreichung. Ordner mit führendem
Punkt überspringt der Scanner.

Gilt für jedes künftige Hilfsverzeichnis (Skripte, Testdaten, CI): entweder mit Punkt beginnen
oder unterhalb eines bestehenden Modulordners ablegen.

### Steuerhoheit: nur das EMS regelt die Batterie

Wichtigste Absprache im Verbund, weil sie sonst schwer auffindbare Fehler erzeugt:

1. **Das EMS ist die einzige Steuerhoheit auf der Batterie.** Es entscheidet.
2. **InverterHub ist reine Ausführungsschicht** — wir setzen um, wir entscheiden nicht.
3. **Signalmodule steuern nicht direkt durch.**

Hintergrund: StromGedacht und Tibber Grid Rewards besitzen beide generische
„Wenn→Dann"-Regel-Engines, mit denen sich **ohne eine Zeile Code** Regeln auf InverterHub- oder
GoodweET-Variablen legen ließen. Dann plant das EMS ein ECO-Fenster, während parallel eine
Regel eine Ladevorgabe schreibt — zwei Regler auf derselben Batterie, beide „korrekt". Beide
Signal-Sitzungen haben dieser Rollenverteilung zugestimmt.

**EMS-Prioritätshierarchie, zwei Situationen (EMS-Sitzung, 24.07.2026), relevant für
`controlAuthority`:**
- **Situation A** — das EMS besitzt den Schreibkanal (eigene Optimierung, §14a, Komfort,
  Direktvermarktung) → echte interne Prioritätsordnung, das EMS entscheidet und schreibt über
  uns. Das ist der `controlAuthority == 'ems'`-Fall.
- **Situation B** — ein externer Akteur besitzt den Schreibkanal komplett außerhalb des EMS
  (Tibber Grid Rewards → go-e-Cloud/Tesla-API, go-e Controller eigenständig). Das EMS hat dort
  KEINE Override-Möglichkeit, teils keine Benachrichtigung — nur nachträgliche Erkennung und
  Reaktion. Das ist der `controlAuthority == 'external'`-Fall: Wir setzen dort keine EMS-Vorgabe
  um, unabhängig davon, ob das EMS den Eingriff überhaupt bemerkt.
- **Schutz-Ebene 0** (Enteisung/Sterilisation/Mindest-SoC) bleibt unverändert ÜBER jeder
  Prioritätsordnung — auch über `controlAuthority`, falls wir je selbst solche Schutzgrenzen
  durchsetzen müssten.
- **Verbund-Prinzip:** kein Abfangen/Nachahmen von Hersteller-Protokollen (MITM/Impersonation)
  — verworfen, weil nicht auf andere NRG-Stack-Nutzer verallgemeinerbar. Nur offizielle,
  dokumentierte APIs. Gilt auch für uns, falls wir je einen externen Regler „erkennen" wollten.

### Preiskurve fürs EMS (betrifft InverterHub nicht direkt)

Für ein preisgetriebenes EMS wurde ein zweiter Verbund-Vertrag vereinbart. Hier nur als
Überblick festgehalten — InverterHub konsumiert ihn **nicht**:

```php
<PREFIX>_GetPriceCurve(int $id): array
// [[ 'start'=>int (inkl.), 'end'=>int (EXKLUSIV), 'price'=>float ct/kWh brutto,
//    'basis'=>'endkunde'|'spot', 'netzentgelt'=>'enthalten'|'fehlt',
//    'level'=>null ], …]   // Liste aufsteigend, Lücken zulässig
```

Zwei Festlegungen, die aus teuren Fehlannahmen entstanden sind:

- **`level` ist immer `null`.** Die Einstufung („günstig/teuer") trifft das **EMS**, nicht die
  Quelle. Grund: Tibber führt ein eigenes Schema aus seinem gleitenden Mittel, eine Spotquelle
  hätte keins und müsste es nachbilden — dasselbe Feld mit zwei Herleitungen, und das EMS
  entschiede bei gleicher Preislage je nach Kundentyp anders. Quellenspezifische Einstufungen
  gehören in ein eigenes Feld (`level_tibber`).
- **`basis` und `netzentgelt` sind nicht redundant.** `basis` sagt, *wessen* Preis es ist,
  `netzentgelt`, *was* darin steckt. Ohne beides rechnet das EMS ein Netzentgelt-Overlay
  doppelt oder gar nicht.

Hintergrund: Bei **§14a EnWG Modul 3** (zeitvariable Netzentgelte) weicht der kundenspezifische
Preis vom allgemeinen Regionalpreis tageszeitabhängig ab — an Dietmars Anlage gemessen um
−7,50 ct (00–06 Uhr) bis +4,00 ct (16:30–20:30). Wer auf Spot optimiert, optimiert das Falsche.
Werte und Zeitfenster sind **netzbetreiberspezifisch** und dürfen nirgends fest im Code stehen.

### Konvention für neue `*_GetFunctions`-Verträge

Kommt ein neues Partnermodul dazu, ist `MHUB_GetFunctions` die **Referenz**. Die ausführliche
Fassung steht in der `CLAUDE.md` des MeterHub-Repos (Abschnitt „Konvention für
`*_GetFunctions`-Verträge"); in Kurzform:

- **Liste statt Einzelobjekt**, auch bei nur einem Eintrag — spätere Aufteilungen brechen die
  Signatur dann nicht.
- Empfohlene Felder: `function`, `label`, `powerID` (W), `energyImportID`/`energyExportID`
  (kumulative kWh), `measured` (bool).
- **Veröffentlichte Verträge werden nicht umbenannt.** Abweichende Feldnamen übersetzt die
  konsumierende Seite; Änderungen nur additiv und nach Ankündigung.
- **Genauigkeit braucht ein eigenes Flag** — nicht aus `energyID == 0` ableiten (siehe
  HeishaMon-Fall unten).
- **Energie nur aus kumulativen Zählern.** Fehlt einer, wird die Größe weggelassen statt aus
  der Leistung hochgerechnet.

**Mehrere Module dürfen denselben Vertrag erfüllen.** `MHUB_GetFunctions` (echte Zähler),
`MHUBV_GetFunctions` (virtuelle Zähler aus MeterHubVirtual) und `HEISHA_GetFunctions`
(Wärmepumpe) liefern dieselbe Struktur. Die konsumierende Seite unterscheidet sie über die
**Modul-GUID der gewählten Instanz**, nicht durch Rateversuche am Präfix:

```php
private const METERHUB_GUID         = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
private const METERHUB_VIRTUAL_GUID = '{ADF18291-2E60-4354-92F5-B96863C127C8}';
```

Der Reihe nach Präfixe durchzuprobieren würde zwar dank `function_exists` nicht abstürzen,
verschleiert aber, welche Quelle gemeint war, und bricht, sobald zwei Anbieter gleichzeitig
installiert sind. Für den Nutzer bleibt es eine Liste: Die vorhandenen `MeterHubs`-Listen
nehmen echte **und** virtuelle Zähler auf.

### Zusammenarbeit der Sitzungen

Die Sitzungen **teilen kein Gedächtnis**. Was einer gesagt wird, wissen die anderen nicht — der
Abgleich funktioniert ausschließlich über ausdrückliche Nachrichten. Es gibt **keine Hierarchie**
zwischen ihnen; die Zuständigkeiten unten sind Absprache, nicht Rangordnung. Auftraggeber ist
der Repo-Eigentümer.

**Übergreifende Koordination läuft über den Repo-Eigentümer.** Er ist der zentrale
Ansprechpartner für den gesamten Verbund. Einzelne Modul-Sitzungen werden **direkt** nur bei
**modulspezifischen** Aufgaben angesprochen — etwa einer konkreten Rückfrage zu einem Vertrag,
den man gerade konsumiert. Alles Übergreifende (neue Konventionen, Verbund-Regeln, neue Partner,
Werkzeuge für alle) geht über ihn, statt dass eine Sitzung es eigenmächtig in die Runde trägt.

In fremden Repos wird ohnehin nicht gearbeitet.

## Kopplung an die PV-Prognose (Prognose-Repo, Präfix `PVF`)

Der `InverterHubMonitor` berechnet Erwartungswerte (Einstrahlung × Generatorparameter) und
stellt sie dem gemessenen Ertrag gegenüber. Er konsumiert dafür:

| Verwendet | Zweck |
|---|---|
| `PVF_GetGenerators($id)` | liefert `pr` (Performance-Ratio) **und** je Generator kWp + Faktor |
| `PVF_GetModuleArea($id)` | Gesamt-Modulfläche (m²) für spez. Leistung / PR |
| Statusvariable `PVF_ModuleArea` | Fallback, wenn der Getter fehlt |
| Konfigurationsschlüssel `PVF_PR`, `PVGenerators` | Fallback für Prognose-Versionen **vor Build 41** (ohne Getter) |
| Modul-GUID `{257DD4E8-9705-462E-89FC-56D0A1038353}` | Instanz der PV-Prognose finden |

Verfügbar, aktuell ungenutzt: `PVF_GetModuleAreas($id)` (seit Build 39) liefert die Fläche
**je Generator** mit `name`, `modules`, `lengthMM`, `widthMM`, `areaPerModule`, `area` — die
Basis, falls spez. Leistung / PR einmal pro Generator statt gesamt ausgewertet werden soll.

**Diagnose = GEMESSENE Einstrahlung × Generatorparameter, NIE `PVF_GetForecast`** (Prognose-
Sitzung, 23.07.2026): Die Wetter-Prognose-Abweichung stammt überwiegend vom Wetterfehler, nicht
von Verschmutzung — GetForecast als Diagnose-Referenz würde also Wetterfehler als Anlagenfehler
ausweisen. Für die Soll/Ist-Diagnose immer den Weg über `PVF_GetGenerators` × gemessene
Einstrahlung nehmen (macht `PvfModel()` bereits). **`PVF_GetForecast` NICHT pollen:** Es kann
einen Wetter-API-Abruf auslösen (Forecast.Solar ratenbegrenzt → häufiges Pollen sperrt aus); für
reine Anzeige die Statusvariablen `PVF_Today`/`Tomorrow`/`DayAfter` lesen (aktualisiert der
Prognose-Timer selbst). Unsere jetzige Nutzung (`GetGenerators`/`GetModuleArea`, nur Konfig) ist
kostenlos.

**Instanzwahl (behoben 2026-07-23):** `PvfInstanceID()` — explizite Wahl (`PvfInstance`) gewinnt,
sonst Automatik NUR bei genau einer Instanz; bei mehreren wird NICHT geraten (Formularhinweis).
Eine ungültige explizite Wahl fällt still auf die Automatik zurück. Früher nahm `PvfModel`/
`PvfArea` still `$ids[0]` — von der Prognose-Sitzung als derselbe Fehler bestätigt, den sie in
ihrem Build 43 behoben haben.

`contractVersion`: `PVF_GetGenerators` trägt die Version der Generatoren-Familie (Start 1.0),
`PVF_GetForecast` die der Prognose-Familie — getrennt. Wir konsumieren `GetGenerators`, also nur
DEREN Major prüfen (wenn die Meldepflicht gebaut wird). Fehlt das Feld = 1.0.

**Vertrag (mit der Prognose-Sitzung abgestimmt):** Die `PVF_Get*`-Funktionen sind die
öffentliche Schnittstelle — Signatur- oder Strukturänderungen dort werden angekündigt und in
`InverterHubMonitor/module.php` nachgezogen. Interne Umbauten der Prognose sind frei, solange
die Rückgabestruktur stabil bleibt (so blieb der Vertrag z. B. unberührt, als die Modulfläche
ab Build 40 aus Länge × Breite berechnet statt als m² eingegeben wurde).

Der Zugriff auf `PVF_PR`/`PVGenerators` per `IPS_GetConfiguration` ist **ausschließlich
Fallback** und läuft nur, wenn der Getter fehlt oder nichts liefert (`PvfModel()`, Zweig ab
`count($rows) === 0`).

**Diesen Fallback nicht entfernen** — er ist derzeit für die *Mehrheit* der Installationen der
einzige Pfad (Stand von der Prognose-Sitzung bestätigt):

| Prognose-Kanal | Version / Build | Getter `PVF_GetGenerators` / `GetModuleArea(s)` | Property `PVF_PR` | Modul-Spalten in `PVGenerators` |
|---|---|---|---|---|
| Stable (`main`) | 0.19 / Build 32 | **nein** (nur `Rebuild`, `GetForecast`, `GetStatusText`, `GetSnapshot`) | **ja** (Default 0.85) | **nein** |
| Beta | 0.20-beta / Build 44 | ja | ja | ja |

Build-Zuordnung (von der Prognose-Seite aus der Historie belegt): Modul-Spalten + `GetModuleArea`
ab **38**, `GetModuleAreas` ab **39**, Eingabe auf Länge × Breite (mm) umgestellt ab **40**,
`GetGenerators` ab **41**. Da wir Fläche *und* Getter brauchen, ist **41** die maßgebliche
Schwelle.

**Was auf Stable geht und was nicht — wichtig für Hinweistexte:**

- **Erwartungswerte: funktionieren auf Stable.** Sie brauchen nur kWp und das *konfigurierte*
  Performance-Ratio; `PVF_PR` ist dort als Property vorhanden und über den Fallback lesbar.
- **Spezifische Leistung (W/m²): geht auf Stable nicht** — nicht wegen eines fehlenden Getters,
  sondern weil `PVGenerators` dort **überhaupt keine Modul-Spalten** hat (weder Anzahl noch
  Fläche noch Länge/Breite). Ein Stable-Nutzer kann die Werte auch nicht eintragen.

Nicht behaupten, das *Performance-Ratio* brauche ein Update — das gilt nur für die aus
Einstrahlung × Fläche **gemessene** Größe, nicht für den konfigurierten Parameter. Diese
Mehrdeutigkeit hat schon einmal zu einem irreführenden Hinweistext geführt.

Aufgeräumt (Fallback entfernen) wird erst, wenn 0.20 im Stable-Kanal ist und die
Prognose-Sitzung sich meldet — dann legen beide Seiten gemeinsam eine Mindestversion fest.

**Nichts eigenmächtig im Prognose-Repo ändern.** Wird ein neuer Getter gebraucht, in der
Prognose-Sitzung anfragen — sie bauen ihn dort. (Der Getter `PVF_GetGenerators` wurde
seinerzeit von hier aus unabgestimmt dort angelegt; das soll sich nicht wiederholen.)

Fehlt das Prognose-Modul, entfallen nur die Erwartungswerte (die Konfigurationsmaske weist
darauf hin) — es darf nichts brechen.

## Fahrzeug-/Wallbox-Kopplung (z. B. Tessie) — bewusst nur konfigurativ

Die Stromflusskachel zeigt an Wallboxen den Ladestand des angesteckten Fahrzeugs. Die
Fahrzeug-Tabelle (`Vehicles`: Bezeichnung, Verbunden-Bedingung, `SocID`) ist
**herstellerneutral**; `AssignVehicles()` ordnet Fahrzeug und Wallbox über die zeitliche
Korrelation der beiden Verbinden-Meldungen zu, ohne dass eine Seite die andere kennen muss.

**Es gibt keine Code-Abhängigkeit zu [Tessie](https://github.com/DG65/Tessie)** — kein
`TESSIE_`-Aufruf, keine GUID. Tessie ist lediglich eine mögliche Quelle für die eingetragenen
Variablen (dort u. a. eine `Soc`-Variable). Das ist Absicht: Jede andere Wallbox-/Fahrzeug-
Quelle funktioniert genauso.

**Wer hier etwas ändert:** Diese Neutralität bitte erhalten. Eine direkte Anbindung an ein
bestimmtes Fahrzeugmodul wäre nur dann sinnvoll, wenn sie — wie bei MeterHub — rein additiv
ist und hinter einem `function_exists`-Guard liegt, sodass die manuelle Konfiguration
unverändert weiterfunktioniert.

## Schwester-Repository MeterHub

Beide sind eigenständig lauffähig und koppeln nur optional aneinander. Die Berührungspunkte:

| Berührungspunkt | Wo im Code |
|---|---|
| Kombinierte Gerätesuche (findet WR **und** Zähler, legt Zähler als MeterHub-Instanz an) | `InverterHubDiscovery/module.php` (`METERHUB_GUID`) |
| Verbraucher-Kreise der Stromflusskachel aus MeterHub-Funktionszuordnung | `InverterHubTile/module.php`: `CONSUMER_TYPES`, `MHUB_TYPE_MAP` |
| Icon-Zeichner der Verbraucher-Arten | `InverterHubTile/module.html`: `ICONS` |

`CONSUMER_TYPES` und `MHUB_TYPE_MAP` liegen **in `module.php`**, nicht in `module.html`. In
`module.html` liegen ausschließlich die Icon-Zeichner im `ICONS`-Objekt.

**`MHUB_GetFunctions($id)` ist der Vertrag zwischen den Repos.** Ändern sich dort Feldnamen
oder Struktur, muss `InverterHubTile/module.php` mitgezogen werden. Neue Einträge im
MeterHub-Vokabular `FUNCTIONS` brauchen einen Eintrag in `MHUB_TYPE_MAP`, sonst fallen sie in
der Kachel still heraus. Die Kernwerte (`grid`, `house`, `pv`, `battery`, `none`) sind dort
bewusst **nicht** gemappt.

**Grundregel:** Keines der Module darf das andere voraussetzen. Fehlt das jeweils andere
(`IPS_ModuleExists` prüfen), entfallen nur die Zusatzfunktionen — es darf nichts brechen.

### Invarianten der MeterHub-Kopplung

1. **Verbraucher-Arten nur in `CONSUMER_TYPES` pflegen.** Die Auswahlliste der Spalte „Art"
   wird zur Laufzeit von `injectConsumerTypeOptions()` in `GetConfigurationForm` erzeugt und
   überschreibt die statischen `options` der `form.json`. Wer eine Art nur in der `form.json`
   einträgt, erzeugt ein stilles Auseinanderlaufen.
2. **Vorzeichen des Netz-Kernwerts wird negiert.** MeterHub zählt `+` = Bezug, die Kachel
   `+` = Einspeisung. Ohne installiertes MeterHub greift ein `function_exists`-Guard und die
   Kachel verhält sich exakt wie zuvor.
3. **`form.json` nicht maschinell umformatieren.** Ein `json.dump`-Durchlauf hat dort schon
   einmal 929 Zeilen für eine 13-zeilige Ergänzung geändert und den Diff unlesbar gemacht.
   Die kompakte Originalformatierung (2 Leerzeichen, einzeilige Objekte) bitte beibehalten
   und rein additiv arbeiten.

## Kopplung an HeishaMon (Wärmepumpe, Präfix `HEISHA`)

Folgt demselben Muster wie MeterHub. Vertrag ab HeishaMon v1.1.1:

```php
HEISHA_GetFunctions(int $id): array
// [[ 'Type' => 'heatpump', 'Caption' => string,
//    'PowerID' => int,   // W, "Elektrische Leistung (gesamt)"
//    'EnergyID' => int,  // kumulative kWh des externen Zählers, 0 = keiner
//    'Measured' => bool  // Genauigkeit von PowerID
// ]]
```

Die abweichenden Feldnamen (`Type`/`Caption`/… statt `function`/`label`/…) werden **bei uns**
übersetzt — der Vertrag war bereits im Store veröffentlicht, als die Konvention entstand, und
veröffentlichte Verträge werden nicht umbenannt.

**Der HeishaMon-Fall — warum Genauigkeit ein eigenes Flag braucht:**
Ursprünglich war vorgeschlagen, „gemessen vs. geschätzt" aus `EnergyID == 0` abzuleiten. Das
trägt nicht: Im Panel „Externer Stromzähler" lassen sich Leistungs- **und/oder**
Energievariable zuweisen. Bei „nur Leistung" ist der Wert **gemessen**, `EnergyID` aber
trotzdem 0 — er wäre fälschlich als Schätzung eingestuft worden. Deshalb gibt es `Measured`.
Diese Ableitung also nirgends wieder einführen.

**Auswirkung auf die Darstellung:** Ohne externen Zähler schätzt HeishaMon die Leistung nur im
**~200-W-Raster**. `fmtKw()` in `module.html` rendert deshalb bei `measured === false` eine
Nachkommastelle mit vorangestelltem `≈` statt drei Stellen — „0,034 kW" wäre dort
Scheingenauigkeit. Das Modul setzt `measured` im Payload **immer** — bei MeterHub-Zählern
explizit `true`, bei manuell zugewiesenen Verbrauchern per **Vorgabe** `true` (die Zeile trägt
das Feld nicht, der Payload ergänzt es über `?? true`). Eine Prüfung auf „Feld fehlt" ist
daher nicht nötig; ein fehlendes Feld gilt trotzdem als gemessen, weil `fmtKw()` nur auf
`=== false` reagiert.

Der Unterschied ist beim Umbau wichtig: Wer die Zeilenstruktur der manuellen Verbraucher
ändert und annimmt, das Feld sei dort bereits gesetzt, verliert die Vorgabe. Aus demselben
Grund prüft die Anzeige strikt auf `=== false` und nicht auf `!measured` — der Mittelknoten
(Hauslast) ruft `setValueText()` ohne dritten Parameter auf, `measured` ist dort `undefined`
und muss weiterhin dreistellig bleiben.

**Sankey:** Die Wärmepumpe wird nur berücksichtigt, wenn `EnergyID` gesetzt ist. Ist sie 0,
entfällt der Strang bewusst — aus der Leistung wird **keine** Energie hochgerechnet. HeishaMons
Variable „Stromverbrauch heute" ist als Quelle ungeeignet: Sie wird um Mitternacht auf 0
zurückgesetzt, was innerhalb einer Periode plausibel aussehende falsche Werte liefert und über
den Rücksetzpunkt hinweg den Knoten kommentarlos entfallen lässt.

## Parallele Sitzungen: Zuständigkeiten

An beiden Repos wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet. Beide
committen auf denselben Branch `beta`. Vereinbarte Aufteilung:

- **MeterHub-Seite:** das MeterHub-Repo vollständig, plus die Integrationslogik in
  InverterHub — `InverterHubTile/module.php`, `form.json`, `CONSUMER_TYPES`, `MHUB_TYPE_MAP`
  und die Verbraucher-Icons; ebenso die Anbindung von `InverterHubEnergy` (Sankey) an die
  MeterHub-Zähler und die Anbindung weiterer Funktionsquellen (z. B. HeishaMon). Also alles zu
  Daten und Konfiguration.
- **Darstellungs-Seite:** die Darstellungsschicht in `InverterHubTile/module.html` —
  SVG-Geometrie, CSS, Farben, Filter/Verläufe, Browser-Kompatibilität. Dazu die
  Versionspflege in `library.json` **samt Changelog** (beides gehört zusammen; wer eine
  Erhöhung braucht, nennt Nummer und Text, statt die Dateien selbst zu bearbeiten).

### Wegweiser: welches Anliegen gehört wohin

| Anliegen | Zuständig |
|---|---|
| Wechselrichter-Treiber, Register, neue Hersteller, Gerätesuche | InverterHub (Kernmodule) |
| Aussehen der Kacheln — SVG, CSS, Farben, Browser-Probleme | InverterHub (Darstellung) |
| Versionsnummern und Changelog in diesem Repo | InverterHub (Darstellung) |
| Energiezähler, MeterHub-Repo | MeterHub |
| Verbraucher/Zähler in Kachel und Sankey (`module.php`, `form.json`) | MeterHub |
| PV- und Verbrauchsprognose, Erwartungswerte | Prognose |
| Wärmepumpe (Daten, Steuerbefehle) | HeishaMon |
| Netzampel | StromGedacht |
| Grid Rewards / Vermarktungssignale | Tibber Grid Rewards |
| Fahrzeuge, Wallbox-SOC | Tessie |
| Batteriesteuerung, Entscheidungslogik | EMS |

Bei Tester-Rückmeldungen nach **Symptom** zuordnen, nicht nach Modulname: „Werte falsch" →
das Modul, das sie liest; „sieht falsch aus" → Darstellung; „Verbraucher fehlt in der Kachel" →
MeterHub.

**Die Grenze in `module.html` verläuft exakt am `ICONS`-Objekt.** Die MeterHub-Seite arbeitet
ausschließlich dort: je Verbraucher-Art eine Funktion `name(g)`, die im 32×32-Raster zentriert
auf (0,0) Kindelemente anhängt (`data-hollow` für offene Konturen). Alles außerhalb von `ICONS`
— Filter, Verläufe, Layout, viewBox — gehört der Darstellungs-Seite. Strichstärke, Farbgebung
und Relief lassen sich daher frei ändern, ohne die Icons anzufassen; sie erben das ohnehin.

Wer die **Struktur** von `ICONS` ändern will (Signatur, Rasterkonvention), stimmt das vorher
ab, damit die andere Seite ihre Icons nachziehen kann.

**Versionsnummern:** `library.json` pflegt die Darstellungs-Seite (sie bumpt häufiger). Wer
eine Erhöhung braucht, nennt die gewünschte Nummer, statt die Datei selbst zu bearbeiten —
so bleiben `library.json` und Changelog synchron.

## Globale Klassennamen brauchen einen Modul-Präfix (Verbund-Konvention, 24.07.2026)

Real passiert: MeterHub, ChargerHub und wir hatten alle drei unabhängig eine interne Hilfsklasse
schlicht `ModbusTcpClient` genannt — sobald ein Konsument (EMS) mehr als eines dieser Module im
selben PHP-Prozess lädt, „Cannot redeclare class ModbusTcpClient" (Fatal Error), aufgetreten beim
ersten echten EMS-Discovery-Test. PHP-Klassen sind global, nicht pro Modul isoliert.

Bei uns behoben: `ModbusTcpClient` → **`IHUB_ModbusTcpClient`** (0.73.1-beta.1, Build 189) —
rein interne Umbenennung, kein öffentlicher Vertrag betroffen.

**Nachgezogen (0.73.2-beta.1, Build 190):** Derselbe Kollisionstyp betraf auch die 15
Treiberklassen (`GoodweDriver`, `SmaDriver`, `VictronDriver` usw.) und `InverterDriverInterface`
— generische, markennahe Namen, die ein anderes Verbund-Modul ebenso vergeben könnte (MeterHub
plant z. B. selbst einen SMA-Zähler-Treiber). Alle jetzt `IHUB_<Name>`. **Echte Umbenennung,
kein `class_exists()`-Guard** — ein Guard ließe bei einer Kollision nur zufällig die zuerst
geladene Implementierung gewinnen (stille statt klarer Fehlerquelle), das wollte das EMS
ausdrücklich nicht. Gleiches Muster bei MeterHub (`MHUB_`) und ChargerHub abgestimmt.

**Regel für jede künftige globale Hilfsklasse** (nicht nur Modbus-Client, jede `class X` in
`module.php`, die keinen generischen/eindeutigen Namen hat): Modul-Präfix voranstellen, wie bei
Idents und Profilen (`IHUB_…`). Vor dem Anlegen einer neuen Klasse kurz `grep -rn "^class "`
über die eigenen Dateien prüfen, ob der Name schon vergeben ist — und im Hinterkopf behalten,
dass andere Verbund-Module denselben naheliegenden Namen (`ModbusTcpClient`, `HttpClient` o. ä.)
ebenso naheliegend finden könnten.

## Regeln fürs Committen

Diese Regeln entstanden aus einem konkreten Vorfall: Ein `git add -A` hat die in Arbeit
befindlichen Änderungen der jeweils anderen Sitzung mit in einen fremden Commit gezogen, dessen
Botschaft sie nicht beschrieb.

- **Kein `git add -A`.** Nur die Dateien stagen, die man selbst geändert hat.
- **Vor dem Commit `git pull --rebase origin beta`.**
- **Vor dem Committen prüfen**, ob im Arbeitsbaum fremde Änderungen liegen (`git status`,
  `git diff`) — wenn ja, nicht mitcommitten.
- **Versionsbumps** in `library.json` und der Changelog-Eintrag gehören zusammen; wer bumpt,
  hält beide synchron (es ist schon vorgekommen, dass das Changelog eine Version nannte, die
  `library.json` noch nicht hatte).

## Zugangsdaten (Verbund-Konvention, 23.07.2026) — derzeit ohne InverterHub-Bezug

Alle unsere Treiber sind bislang reines lokales Modbus TCP — keine Zugangsdaten, kein akuter
Umbaubedarf. Gilt erst, falls je eine Cloud-Anbindung entsteht (z. B. ein Herstellerportal):

1. Handshake-/Token-Verfahren bevorzugen (OAuth o. ä.). Passwort nur beim einmaligen Handshake,
   danach NICHT speichern — nur Token/Secret bleibt liegen.
2. Passwörter nur dauerhaft speichern, wenn wirklich wiederholt gebraucht (kein Token-Weg
   existiert). Handshake hat Vorrang.
3. Speicherort für dauerhaft benötigte Tokens/Passwörter: `RegisterAttributeString` (NICHT
   `RegisterPropertyString`) — Attribute erscheinen nicht im Konfigurationsformular.
4. **IPS verschlüsselt nicht at rest** (weder Properties noch Attribute). „Sicher" heißt hier
   „nicht im Formular/Log/Anzeigetext sichtbar", nicht „verschlüsselt" — so kommunizieren.
5. Formulareingabe für ein einmaliges Passwort: `PasswordTextBox` (maskiert), Wert nach
   Verwendung sofort leeren bzw. gar nicht als Property führen.

Referenz: MeterHub/Inexogy-Treiber (OAuth1, Passwort nur beim Handshake).

## Gemeinsame Profile NRG.* (Verbund-Konvention, 24.07.2026) — geplant, NICHT umgesetzt

Physikalische Grundgrößen bekommen einen gemeinsamen `NRG.*`-Präfix statt je Modul ein eigenes
Profil (`NRG.Watt` statt `IHUB.Watt`). Bewusst klein: nur **sechs** Profile — `NRG.Watt`,
`NRG.kWh`, `NRG.Ampere`, `NRG.Volt`, `NRG.Percent`, `NRG.Celsius`. Modulspezifische Status-/
Enum-Profile (Betriebsmodus, Netzquelle, Warncodes) bleiben beim eigenen Modul-Präfix. Anlage
idempotent, kein Eigentümer-Modul: `IPS_VariableProfileExists('NRG.Watt')` prüfen, nur bei
Fehlen anlegen (Muster GleitenderMittelwert). Details: EMS/SUITE.md, Abschnitt „Gemeinsame
Variablenprofile".

**Bei uns ist das kein Rename, sondern ein Design-Problem — deshalb noch nicht angefasst.**
15 Treiber, **39 profildefinierende Stellen**, uneinheitliche Wertebereiche für dieselbe
physikalische Größe: `*.Ampere` ist bei den meisten Treibern ±200 A, bei Victron und Huawei
(Großanlagen) ±1000 A. Ein gemeinsames `NRG.Ampere` braucht EINEN Bereich für alle (also ±1000),
das ist eine bewusste Entscheidung, keine reine Umbenennung — bei den kleineren Wechselrichtern
kostet es etwas Anzeigefeinheit (Nachkommastellen/Skalenauflösung).

**Zusätzliches Risiko: Live-Instanzen.** Ein Profilwechsel ändert `IPS_SetVariableCustomProfile`
auf jeder bestehenden Instanz — anders als ein Rename im Code betrifft das laufende
Installationen der Beta-Tester direkt (WebFront-Darstellung, ggf. Archiv-Skalierung). Das
gehört nicht beiläufig in eine bereits sehr lange Sitzung, sondern in einen eigenen,
gegengeprüften Umstellungs-Build mit Vorher/Nachher-Test an mindestens einer Live-Instanz.

**Plan, wenn angegangen wird:**
1. `NRG.*`-Profile mit vereinheitlichten Bereichen entwerfen (Ampere ±1000, Watt je nach größtem
   Treiber, etc.) — Bereiche VOR dem Bau mit Dietmar abstimmen, nicht selbst festlegen.
2. In allen 15 Treibern `getProfiles()` die generischen Einträge durch `NRG.*`-Referenzen
   ersetzen; modulspezifische Profile (Status/Enum) bleiben unangetastet.
3. Idempotente Anlage zentral im Hauptmodul (nicht je Treiber), analog GleitenderMittelwert.
4. Vor dem Push: eine Instanz jedes betroffenen Herstellers im Browser/an echtem IPS gegentesten.

## Vertragsversionierung (Verbund-Konvention, 23.07.2026)

Manifest: https://github.com/DG65/EMS/blob/main/SUITE.md. Betrifft uns bei jeder angebotenen und
konsumierten Schnittstelle. **Bestehendes muss nicht umgebaut werden** — anwenden, sobald neue
Verträge entstehen.

**`IHUB_GetFunctions($id)` ist gebaut** (0.73.0-beta.1, Build 188) — nicht mehr nur geplant.
Liefert je physischer Instanz: `contractVersion` ('1.0'), `instanceID`, `manufacturer`,
`virtual` (immer `false` bei einer physischen Instanz — reserviert fürs künftige
InverterHubVirtual), `measured`, `controllable` (hat der Treiber überhaupt Steuerregister?,
generisch über die `GroupControl`-Gruppe erkannt), `controlAuthority` (`ems`/`external`/`none`,
Nutzereinstellung), sowie `pvPowerID`/`acPowerID`/`batPowerID`/`gridPowerID`/`socID`/
`connectedID`. Aktuell `controllable === true` nur bei GoodWe (9 Steuerpunkte), Deye (Ein/Aus),
Sungrow (Start/Stop) — die übrigen 13 Treiber sind reine Lesepfade.

**`controlAuthority` wird durchgesetzt, nicht nur gemeldet.** `RequestAction` verweigert jeden
Schreibzugriff, wenn die Instanz nicht auf `ems` steht (Verteidigung in der Tiefe — das EMS soll
den Wert selbst prüfen, aber ein Fehler dort darf hier nicht zu einer ungewollten Schreibaktion
führen). Formularfeld „Steuerhoheit dieser Instanz" erscheint nur bei Treibern mit
`GroupControl`.

- **Modul-Version:** unser SemVer bleibt, eigener Takt (Store-Pflicht) — davon unberührt.
- **`contractVersion` je Vertrag:** additives Feld `'Major.Minor'` (String, Start `'1.0'`).
  Major NUR bei Bruch (Feld entfernt/umbenannt/umgedeutet); volle Kompatibilität nur innerhalb
  derselben Major. Minor bei additiven Erweiterungen. **Fehlendes Feld = konservativ `'1.0'`.**
- **Meldepflicht (Dietmars Kernanforderung):** Ein Konsument kennt seine Mindest-Major je Partner.
  Ist die Partner-Major zu alt (oder umgekehrt der Konsument), bleibt das Modul **standalone voll
  funktionsfähig**, nur die Kopplung wird deaktiviert — und der Zustand wird **sichtbar** gemeldet
  (Instanzstatus/Formular, nicht nur Log): „⚠️ Partnermodul X benötigt eine Aktualisierung
  (Vertrag 2.x benötigt, 1.4 vorhanden)".
- Konkret bei uns: Kachel/Monitor konsumieren `MHUB_/MHUBV_GetFunctions`, `TIBBERGR_GetPriceCurve`,
  `PVF_Get*`. Beim nächsten Anfassen dieser Konsumstellen die Mindest-Major prüfen und melden;
  `IHUB_GetFunctions` (InverterHubVirtual) trägt `contractVersion` von Anfang an.
- Baseline-Stände (Stand 23.07.2026): `MHUB_/MHUBV_GetFunctions` ab MeterHub 0.17.0-beta.1 auf
  **`contractVersion '1.1'`** (1.0 = Ur-Vertrag, 1.1 = latency/authority/energyKind/sourceCount —
  die billing-Balken brauchen 1.1). Major bleibt 1, solange kein Bruch. Unser billing-Konsum
  (`BillingGridImportVid`) degradiert bereits weich: fehlt `authority`, gilt `auxiliary`, der
  Zähler wird nicht als billing gewählt und der Integrations-Rückfall läuft — eine sichtbare
  Versionswarnung ist dort also nicht nötig (optionale Anzeige, keine kritische Kopplung).

## Emojis sind erwünscht (Verbund-Regel, Dietmar 23.07.2026)

Ersetzt jede frühere „keine Emojis"-Vorsichtsregel. Emojis sind erwünscht, wo sie Nutzen stiften:
1. als **Panel-Icon** — ein Zeichen am Anfang einer ExpansionPanel-Überschrift (📖🔌📊), Ersatz
   fürs fehlende `icon`-Feld;
2. als **Status-/Aufmerksamkeitssymbol** (✅ ❌ ⚠️ 💡 ℹ️) dort, wo etwas beim Lesen Aufmerksamkeit
   erfordert oder herausgestellt werden soll (Status, Warnungen, wichtige Hinweise) — für Fokus
   und Auflockerung.

Faktenlage: Kein Symcon-Store-Review hat Emojis je beanstandet; die frühere Regel war präventiv.
**Beobachtungsklausel:** Bemängelt ein Stable-Review Emojis doch je, entscheidet der Verbund neu
(Rückfall: gemeinsam emoji-frei). Die bestehenden Emoji-Captions (Formular-Hinweise, Discovery-
🔎, Preis-💶) sind damit regelkonform.

## Sprachregel: alles Nutzersichtbare auf Deutsch

Verbund-Regel seit 22.07.2026, gilt für alle zehn Mitglieder. Anweisung des Repo-Eigentümers:
„wenn möglich keine Anglizismen bzw. komplette Ausdrücke oder Sätze in englischer Sprache".

**Deutsch ist alles, was der Nutzer sieht:** Formularbeschriftungen, Hinweis- und Warntexte,
Bestätigungsdialoge, Fehler- und Statusmeldungen, Rückgabe-Texte (z. B. ein `reason`-Feld),
Log-Meldungen, Variablen- und Profilnamen, README und Changelog.

Vermeidbare Anglizismen ersetzen: Dry-Run → Probelauf, Link → Verknüpfung, Event → Ereignis,
Button → Schaltfläche, Checkliste → Prüfliste, Scan/scannen → Suche/suchen.

**Wort-für-Wort-Ersetzen reicht nicht.** Zwei Fehlerarten sind im Verbund real aufgetreten und
überstehen jede maschinelle Ersetzung:

1. **Genus-Bruch.** „einen langsameren, aber zuverlässigen Port*check*" wird mit „Port-*Prüfung*"
   (feminin) grammatisch falsch. Nach jeder Ersetzung den **Diff lesen**, nicht nur die
   Trefferliste. (Hier passiert, beim Durchsehen bemerkt.)
2. **Objekt-Verwechslung.** „scannen" heißt zweierlei, was im Englischen dasselbe Wort ist:
   Wird ein **Adressbereich abgesucht** → „durchsuchen/absuchen"; sollen **Geräte gefunden**
   werden → „finden". „Zähler lassen sich nicht durchsuchen" ist grammatisch tadellos und
   trotzdem falsch — man durchsucht nicht die Zähler, die Suche findet sie nicht.
   (Bei MeterHub passiert, dort korrigiert.)

**Ausgenommen — bleibt englisch, weil Umbenennen Verträge bricht:**

- Bezeichner im Code: Klassen-, Methoden-, Variablen-, Property- und vor allem **Ident-Namen**.
  **Idents sind API und werden nie umbenannt** (Verbund-Konvention). Die Sprachregel gilt
  ausdrücklich nur für Anzeigetexte, nicht für Idents wie `pv_total` oder `CurrentPrice`.
- Feststehende IP-Symcon- und Technikbegriffe: `SelectVariable`, WebFront, Modbus TCP, SunSpec,
  `AC_ChangeVariableID`, MPPT, SOC usw.

## SMA mit Sunny Home Manager: zweiter Regler außerhalb von IPS

Betrifft SMA-Hybridgeräte (z. B. STP Smart Energy) an Anlagen mit **Sunny Home Manager 2.0
(SHM)**. Der SHM ist kein reiner Zähler, sondern ein **aktiver Regler**: Er schreibt selbst
Leistungsvorgaben an den Wechselrichter. Schreibt zusätzlich unser EMS über InverterHub
Vorgaben, stehen zwei Regler auf derselben Batterie — dieselbe Situation, die die
Steuerhoheits-Regel oben verhindert, nur dass der zweite Regler diesmal ein SMA-Gerät ist
und kein IPS-Modul. **Das Einlesen des SHM macht ihn nicht passiv.**

Vereinbarte Betriebsarten für solche Anlagen (beim Einbau der SMA-Steuerregister in der
Konfigmaske abbilden und davor warnen):

1. **SHM regelt, wir beobachten nur** — sicher; EMS-Steuerung auf diesem Gerät deaktiviert.
2. **SHM nur als Zähler** (keine Verbraucher-/Batteriesteuerung im Sunny Portal aktiv) —
   dann darf das EMS steuern.
3. **Mischbetrieb — aktiv ausschließen**, mindestens deutlich warnen.

Zuordnung im Verbund (über den Repo-Eigentümer angestoßen, Prüfung bei MeterHub angefragt):

- **SHM 2.0 und SMA Energy Meter gehören als Zähler in den MeterHub.** Achtung: Beide
  sprechen **kein Modbus TCP**, sondern senden per **Speedwire-Multicast** (UDP an
  239.12.255.254:9522, „EMETER-Protokoll") mehrmals pro Sekunde von selbst. MeterHub bräuchte
  dafür einen zweiten Empfangsweg (UDP-Listener statt Polling) — Architekturentscheidung der
  MeterHub-Sitzung. Die Messwertstruktur beider Geräte ist identisch.
- **InverterHub-Seite** (hier): SMA-Steuerregister für den Smart Energy erst zusammen mit der
  SHM-Betriebsarten-Warnung einbauen — nicht vorher.

## Abrechnungsgenauer Netzzähler (Inexogy) — optional, nie fest verdrahtet

Dietmar baut einen **Inexogy mMSD/iMSys** am Netzübergabepunkt ein — den Zähler, dessen
Zählerstand auch auf der Rechnung steht, mit abrechnungsgenauen 15-Minuten-Werten (per API auch
an Tibber angebunden). Das ist die genaue Quelle für alles, was mit Netzbezug/Kosten zu tun hat.

**Harte Regel (Anweisung Dietmar, 2026-07-23): NICHT fest verdrahten. Nicht jeder ist bei
Inexogy.** Der Zähler ist eine mögliche, bevorzugte Quelle — nie eine Voraussetzung. Jede
Nutzung liegt hinter `function_exists`/`IPS_ModuleExists`, mit Rückfall auf das bisherige
Verhalten.

Zuständigkeit: Zähler gehören zu **MeterHub**. Entschieden (MeterHub, 2026-07-23): Inexogy wird
eine zweite Transportklasse INNERHALB MeterHub (Pull/HTTPS-Timer, `InexogyHttpClient` neben
`ModbusTcpClient`, OAuth 1.0a) — kein eigenes Modul (die Trennlinie ist Push vs. Pull, nicht
Modbus vs. Cloud; Speedwire war Push → eigenes Modul). Die Rechnungsprüfung selbst
(Bezugsenergie je Slot × Preis) liegt beim **EMS**.

**Vertragskennzeichen — NICHT selbst erfinden.** MeterHub erweitert `MHUB_GetFunctions`/
`MHUBV_GetFunctions` additiv um ein Zwei-Achsen-Modell (Format wird im EMS-Strang abgesegnet):

```
latency:   'realtime' | 'delayed'    — darf ein Echtzeit-Regler darauf regeln?
authority: 'billing'  | 'auxiliary'  — steht der Wert auf der Rechnung?
```

Die beiden Achsen sind orthogonal: Dietmar hat bald ZWEI `grid`-Zähler am selben Anschluss —
Inexogy (`billing` + `delayed`) und einen lokalen Modbus-Zähler (`auxiliary` + `realtime`). Ein
einzelnes „billingGrade"-Flag könnte das nicht trennen — deshalb **kein eigenes Feld**, sondern
`authority` konsumieren.

Wo die Felder sitzen (MeterHub 0.15.1-beta.1, fixiert): **Zähler-Eigenschaften**
(`latency`/`authority`/`pollInterval`) stehen an beiden Orten — auf Instanz-Ebene UND in jede
Zuordnung gespiegelt (aus derselben Property, können nicht auseinanderlaufen).
**Zuordnungs-Eigenschaften** (`energyKind`/`sourceCount`) nur je Zuordnung. `BillingGridImportVid()`
liest `authority` deshalb bewusst je Zuordnung (`assignments[]`), dort wo auch `function`/
`energyImportID` stehen — das ist garantiert vorhanden.

**Berührungspunkt bei uns:** Die Netzbezug-Balken im Strompreis-Reiter des `InverterHubMonitor`
(`SlotEnergyBars`) integrieren sie derzeit aus der Wechselrichter-Netzleistung (`meter_total`) —
das bleibt der **Rückfallweg**. Sobald das Format steht, den Balken auf die Quelle mit
**`function == 'grid'` UND `authority == 'billing'`** umstellen. Erst umsetzen, wenn MeterHub
sich meldet (Format vom EMS abgesegnet). Nicht pollen — MeterHub gibt Bescheid.

**Integrationslogik nach `energyKind` (MeterHub-Vertrag), NICHT selbst roh differenzieren:**
- `energyKind: 'counter'` (Inexogy: kumulativer Zählerstand): Intervall-Bezug über
  `AC_GetAggregatedValues` mit **Counter-Aggregationstyp** holen — das Archiv behandelt
  Zählerwechsel, Überläufe und Lücken sauber. **Kein** rohes `wert[t2] − wert[t1]` (still falsch
  bei Sprüngen — die Falle aus der Zeitreihen-Diskussion).
- `energyKind: 'interval'` (fertige Periodenwerte): je Slot **summieren** statt differenzieren.
- Nur wenn gar kein billing-Zähler da ist: aus `meter_total`-Leistung integrieren (heutiger
  Weg). Einheit (Wh/kWh) und Vorzeichen liefert MeterHub bereits normiert — nicht selbst raten.

## IHUBMON_GetDiagnostics — Diagnostik-Vertrag für NRGDashboard (gebaut 25.07.2026)

Zielarchitektur (Dietmar): **Diagnose-Logik bleibt bei uns** (InverterHubMonitor kennt die
Wechselrichter-Details), **Darstellung wandert zu NRGDashboard**. `InverterHubMonitor` bleibt
vorerst voll nutzbar — kein Sofort-Umbau, der Vertrag ist zusätzlich, kein Ersatz. Details/
Feldliste im README, Abschnitt „Diagnostik-Vertrag". Kurzfassung:

- Drei Einträge: `yield_vs_forecast` (Ertrag vs. PVF-Erwartung), `mppt_string_compare`
  (schwacher Einzelstrang), `riso` (Isolationswiderstand gegen Nutzer-Schwelle `RisoWarnKOhm`,
  Default 0 = aus — **kein Herstellervorgabewert ohne Bestätigung**, damit ist der offene
  Tester-Wunsch kea/Dietmar zur Riso-Schwelle erledigt).
- Konvention, mit NRGDashboard abgestimmt und als Muster für andere Hub-Module gedacht:
  gemessene Rohgröße = Variablen-**Referenz** (Konsument zeichnet Archiv-Zeitreihen selbst),
  berechneter Vergleichswert = **Wert**, Bewertung (`level`/`threshold`/`reason`) = **Metadaten**
  vom Anbieter (analog zum bewusst fehlenden `level` bei `TIBBERGR_GetPriceCurve`).
- `contractVersion` '1.0' von Anfang an. `level` ist `null`, wenn keine Bewertung möglich ist
  (zu wenig Erzeugung, keine Schwelle konfiguriert) — Rohwert kommt trotzdem.

Falls NRGDashboard-Feedback zum Format kommt: additiv erweitern, nicht umbenennen (Vertrag ist
schon veröffentlicht, sobald ein Konsument darauf aufbaut).

## InverterHubVirtual — Anlagen-Summe mehrerer Wechselrichter (Designstand)

Mehr-WR-Anlagen (z. B. sirkentucky: zwei getrennte SMA → zwei InverterHub-Instanzen, EINE
Anlage) brauchen einen virtuellen Gesamt-WR: pro Gerät UND als Anlagen-Summe. Design mit EMS und
MeterHub abgestimmt (2026-07-23), Umsetzung noch offen.

**Abgrenzung zu ChristianLs Fall:** Der ist NICHT dies — ein Victron-System mit mehreren internen
PV-Wechselrichtern (Unit 20/41) aggregiert der Victron-Treiber selbst (Erträge summieren). Der
virtuelle WR ist der instanzübergreifende Fall.

- **Eigene Instanz + eigene GUID** (Prefix z. B. IHUBV), analog MeterHubVirtual. Aggregation an
  EINEM Ort; Monitor, Kachel und EMS konsumieren dieselbe Summe → kein Drift. NICHT ein Modus
  einer bestehenden Instanz.
- **Flache Summe, KEIN Verdrahtungsbaum.** MeterHubVirtual ist ein Baum (mit `_rest`,
  Zyklenprüfung), weil bei Zählern SUBTRAHIERT wird. Bei WR wird nur ADDIERT (WR1+WR2=Anlage) —
  Baum nur, wo subtrahiert wird. Die ganze `_rest`/Elternauflösungs-Komplexität entfällt.
- **Aggregations-Klasse je Größe** (datengetrieben, an der Größen-Definition; jeder Treiber
  deklariert mit). Physik extensiv vs. intensiv:
  - `sum` — extensiv, wächst mit der Anlage: Ertrag, Leistung, MPPT-Strang.
  - `mean` — intensiv: SOC (**kapazitätsgewichtet**, nicht arithmetisch — der Virtuelle braucht
    dafür die nutzbare kWh-Kapazität je Batterie: Formularfeld je Instanz, oder ableiten wo der
    WR sie liefert), ggf. Spannung/Frequenz.
  - `plant` — EINMAL auf Anlagenebene, NIE pro WR summiert: **Netz, Hauslast**. Gefährlichste
    Falle (N-fache Hauslast); kommt aus dem Inexogy-billing-Zähler, nicht aus WR-Netzwerten.
  - `device` — nur pro Gerät, gar nicht aggregieren: Riso, Temperatur, Status, Seriennummer.
    Bleibt in der WR-Sicht, taucht in der Anlagen-Sicht nicht auf.
- **Dynamisches Abdeckungskennzeichen.** `sourceCount` (statisch, konfiguriert) reicht NICHT —
  fällt ein WR aus, wird die Summe still zu klein. Zusätzlich **`activeSourceCount`** (in den
  letzten X s aktualisiert), damit ein Konsument „2 von 3 WR liefern" sieht und die Summe als
  unvollständig markieren kann. MeterHub übernimmt denselben Feldnamen.
- **Nur-lesend + „virtuell"-Kennzeichen.** Der Virtuelle führt NIE Steuerung. Er bietet dieselbe
  Schnittstelle wie ein physischer (`IHUB_GetFunctions` o. ä.) MIT einem Kennzeichen
  `virtual`/`aggregated` (analog `authority`/`measured`), damit das EMS ihn als Aggregat erkennt
  und nicht als weiteres Einzelgerät in die Steuerung nimmt.
- **Steuerung bleibt physisch.** EMS verteilt Anlagen-Sollwerte auf die echten WR einzeln (je
  eigene Register + `controlAuthority`), respektiert dabei `controlAuthority != 'ems'` (extern/
  SHM-geregelte WR ausgenommen, wie externallyManaged bei Ladepunkten). Kein Sollwert am
  Virtuellen, keine Sammel-Schreibstelle.
- **Stabile Geräteidentität** (Seriennummer als Anker), damit die Zuordnung in der Anlagen-Sicht
  nicht springt, wenn ein WR mal offline ist.
- **Testgerüst, das wirklich rechnet** (nachgebildetes IPS, echte Summen) — Aggregationslogik ist
  Code, wo `php -l` nichts beweist. MeterHubs `.tools/test-virtual.php` als Muster.

Feldnamen (von Dietmar bestätigt 2026-07-23; mit MeterHub kompatibel, MeterHub übernimmt sie):
`activeSourceCount`, `aggregation` (sum|mean|plant|device), `virtual`.

## Verbund-Konvention: Kacheln mit Datumssteuerung bedienen sich identisch

Gilt für **alle** Kacheln mit Zeitraum-/Datumsauswahl — derzeit `InverterHubMonitor` und
`InverterHubEnergy` (Sankey), künftig jede weitere im Verbund. Wer eine Kachel mit
Datumssteuerung baut oder ändert, zieht **alle** anderen im selben Zug nach; die Bedienung
darf nie auseinanderlaufen, auch nicht vorübergehend.

Der vereinbarte Aufbau (Referenz: `InverterHubMonitor/module.html`):

1. **Position:** Steuerleiste `#bar` horizontal **zentriert**, direkt unter dem Kacheltitel
   (nicht ins Titelband — dort fängt die IPS-Kopfzeile die Klicks ab).
2. **Reihenfolge:** Ansichts-Auswahl (`Tag/Woche/Monat/…`) · ◀ · Datumsfeld · ▶ ·
   Schnellwahl.
3. **Schnellwahl „Vorgestern / Gestern / Heute":** nur in der **Tagesansicht** sichtbar;
   der angezeigte Tag ist hervorgehoben und wandert bei jeder Navigationsart mit; Tage ohne
   Archivdaten sind ausgegraut (Buttons `.qday`, Container `#quick`).
4. **Optik:** gleiche Klassen/Stile (`.sel`, `.nav`, `.pick`, `.qday`), gleiche Radien und
   Grautöne, Theme-Färbung über `applyTheme`.

Änderungen an dieser Konvention werden über den Repo-Eigentümer in den Verbund getragen,
damit auch Kacheln anderer Module (z. B. GoodweET, StromGedachtTile) nachziehen können.

## Browser-Eigenheiten der Kacheln (teuer erkauftes Wissen)

Gilt für `InverterHubTile/module.html` und sinngemäß für andere Kachel-HTML:

- **Safari rastert `filter: blur()` beim Skalieren grob.** Weiche Verläufe (Corona,
  Glanzlichter, Schatten) daher als radiale SVG-Verläufe umsetzen, nicht per Blur.
- **Safari rendert einen `objectBoundingBox`-Radialverlauf auf einer *elliptischen* Box hart
  statt weich.** Deshalb weiche Ellipsen als **Kreis** mit Verlauf zeichnen und per
  `transform: scale(1, …)` stauchen.
- **Verlaufs-Fill per Inline-Style setzen**, nicht als `fill`-Attribut: Eine Stylesheet-Regel
  wie `fill: none` schlägt sonst das Attribut, und Safari zeigt den Verlauf gar nicht.
- **`feSpecularLighting` wird in Safari körnig.** Höhenkarte großzügig glätten und den Glanz
  breit halten (kleiner `specularExponent`).
- **Die viewBox bleibt fest und quadratisch**; das Einpassen macht `preserveAspectRatio`
  („xMidYMid meet"). Die viewBox früher per JS ans Seitenverhältnis nachzuziehen führte beim
  laufenden Vergrößern zu einem Frame mit falschem Seitenverhältnis — der Inhalt sprang dabei
  klein in eine Ecke.
- **Das Maximieren-Symbol vergrößert die Kachel nicht.** Es öffnet die Objekt-Detailansicht
  des Hosts (Variablenliste der Instanz). Das gilt für alle HTML-SDK-Kacheln, auch für
  Symcons eigene. Wirkt die Ansicht leer, liegt das an fehlenden Variablen der Instanz — nicht
  am Kachel-Layout. Bitte nicht erneut „reparieren".
