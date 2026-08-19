# Hinweise für die Arbeit an diesem Repository

## Der Modul-Verbund

Dieses Repo gehört zu einer Gruppe eigenständiger IP-Symcon-Module, die zusammenwirken. An
ihnen wird teilweise **gleichzeitig in getrennten Sitzungen** gearbeitet, die sich auf
gemeinsame Regeln und dokumentierte Schnittstellen geeinigt haben.

| Modul | Rolle | Repo / lokale Kopie | Vertrag zu uns |
|---|---|---|---|
| **InverterHub** (dieses Repo) | Wechselrichter messen, darstellen, steuern | `DG65/NRGInverterHub` | — |
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

**Zuständigkeit ab 28.07.2026 zusätzlich bei Dashboard:** Die Dashboard-Sitzung hat
`AssignVehicles()`/`Vehicles`-Property/`CondMet`-Helfer 1:1 nach `NRGDashboardTile` portiert
(deren Commit 849363b), damit ihre eigene Stromflusskachel dieselbe Fahrzeug-Zuordnung zeigt.
Es gibt dafür **keinen Verbund-Vertrag/SUITE.md-Eintrag** — reine Musterübernahme, keine
Abhängigkeit zwischen den Modulen. Der Code hier in `InverterHubTile` bleibt unverändert
bestehen (Dietmars eigene Kachel nutzt ihn weiterhin) — bei künftigen Änderungen an diesem
Mechanismus **beide Stellen im Blick behalten**, sie können sonst unbemerkt auseinanderlaufen,
genau wie bei `CONSUMER_TYPES`/`MHUB_TYPE_MAP` (s. Schwester-Repository-Abschnitt).

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

## MigrationsHub-Integration in InverterHubDiscovery (29.07.2026, verdrahtet/ungetestet)

Verbund-Absprache: Migration von Altinstanzen ist jetzt Teil des normalen Discovery-Scans statt
separates Werkzeug (`InverterHubDiscovery/module.php`, Commit 36f9180). Ablauf: Scan findet ein
Gerät → ruft additiv `MIGHUB_FindLegacyCandidates($mighubId, $host, $port, $unitId)` auf (hinter
`function_exists`, MigrationsHub-Instanz wird bei Bedarf einmalig je Scanlauf angelegt) → bei
Treffern erscheint ein Panel „Migration von Altinstanzen" → Klick auf „Migration vorbereiten"
(`StartMigration()`) legt die neue InverterHub-Instanz an, ruft `MIGHUB_PrefillMigration()` auf,
zeigt einen `OpenObjectButton` zu MigrationsHub, wo der bestehende Simulieren/Übernehmen-Ablauf
läuft. Matching ausschließlich über Host+Port+UnitId, nie über Namen (MigrationsHub-Konvention).

**Status: verdrahtet, aber mangels echtem Testfall NICHT end-to-end getestet** (nur `php -l` +
Code-Review). Grund: GoodweET — das einzige bisherige Alt-Modul, das InverterHub abgelöst hat —
ist bei Dietmar inzwischen komplett deinstalliert (Adoption war bereits vollständig
abgeschlossen), es gibt also keinen echten Alt-Instanz-Kandidaten mehr zum Durchklicken. Auf
Dietmars ausdrücklichen Wunsch (29.07.2026) **kein künstlicher Wegwerf-Test erzwungen** — echter
Test folgt bei der nächsten realen Alt-Modul-Ablösung im Verbund, dann diesen Hinweis entfernen.

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

**Extern validiert (27.07.2026, Recherche auf Dietmars Wunsch nach mehr Eigeninitiative statt nur
Ticket-Reaktion):** Das Muster „PV summieren, Netz/Hauslast NUR EINMAL auf Anlagenebene" deckt
sich mit etablierten Open-Source-EMS-Projekten, kein Sonderweg:
- **OpenEMS** trennt exakt so: eine `Sum`-Komponente aggregiert anlagenweit, `GridActivePower`
  kommt von GENAU EINEM Meter mit Rolle `GRID`. Verbrauch wird dort **nicht** aus
  Einzelgeräte-Hauslastwerten summiert, sondern zentral **abgeleitet**:
  `Consumption = Production + ESS-Entladung − Netzeinspeisung`. Mehrere Batterie-WR fasst ein
  explizites ESS-Cluster zusammen — Analogie zu unserem `IHUBV`.
  (https://community.openems.io/t/ess-with-multiple-inverters/2333,
  https://openems.github.io/openems.io/openems/latest/coreconcepts.html)
- **evcc** erlaubt mehrere PV-Meter (automatisch summiert, `site.meters.pvs: [...]`), aber
  **nur einen** Netz-Meter je Anlage — bei mehreren physischen Zählpunkten muss der Nutzer sie
  VOR der Site-Konfiguration selbst zu einem virtuellen Meter zusammenfassen, die Anlage sieht
  danach wieder nur einen. (https://docs.evcc.io/en/meters/)
- **Home-Assistant-Energiedashboard** hat dagegen KEINEN strukturellen Schutz — es summiert
  blind, was zugewiesen wird; Nutzer laufen dort real in genau unsere „gefährlichste Falle"
  (mehrfach gezählte Hauslast bei mehreren WR), weil nichts das verhindert. Negativbeispiel,
  keine Blaupause.

**Verdikt:** unser Design ist bestätigt, keine Änderung am Grundmuster nötig. Eine Verfeinerung
von OpenEMS aber als Fallback vormerken, WENN kein Inexogy-Zähler vorhanden ist: Hauslast dann
nicht von irgendeinem WR erfragen (kein Treiber soll das je müssen), sondern zentral in
`IHUBV` ableiten: `Hauslast = Σ(PV) + Σ(Batterie-Entladung) − Netzbezug`. Das entfernt die Falle
strukturell statt nur per Dokumentation zu warnen — passt zu `plant`, ändert aber nichts an der
Umsetzung (weiterhin offen, s. o.).

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

## Steuervariablen brauchen `RegisterVariableXXX()`, nicht rohes `IPS_CreateVariable()`

Realer Vorfall (25./26.07.2026, Dietmars WR1-Instanz): WebFront-Klicks auf Steuer-Schalter
(z. B. „EMS Leistungsmodus") scheiterten mit **„Action is invalid" (Code -32603)**. Ursache lag
in zwei Ebenen übereinander, beide hier festgehalten, weil beide bei jedem künftigen Umbau der
Variablenanlage wieder zuschlagen können:

**Ebene 1 — falsche API zum Verdrahten der Aktion.** `IPS_SetVariableCustomAction($vid, $X)`
erwartet als zweiten Parameter eine **Skript-ID**, nicht — wie naheliegend vermutet — eine
Instanz-ID. Ein Aufruf mit der eigenen `$this->InstanceID` schlägt daher **immer** fehl (`false`,
kein Fehler/Exception), live bestätigt per Fehlermeldung „Skript #<InstanzID> existiert nicht".
Die korrekte, offiziell dokumentierte SDK-Methode für eine **modul-eigene** Statusvariable ist
`$this->EnableAction($Ident)` (siehe [Symcon-Doku](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/enableaction/)).

**Ebene 2 — der eigentliche Kern des Fehlers, schwerer zu finden:** `$this->EnableAction($Ident)`
selbst meldete `true` (kein Fehlersignal!), band aber trotzdem nichts — `VariableAction` blieb bei
`0`. Grund: Unsere Variablen wurden bislang per rohem `IPS_CreateVariable()`+`IPS_SetIdent()`
angelegt (nicht über `RegisterVariableInteger()`/`RegisterVariableBoolean()`/etc.). Eine so
erzeugte Variable ist beim Kernel **nie** als „eigene Variable dieser Instanz" registriert — nur
`RegisterVariableXXX()` trägt sie in die interne Buchführung ein, auf der `EnableAction()`
aufsetzt. Bloße Objektbaum-Zugehörigkeit (richtiger Parent, richtiger Ident) reicht nicht.
**Exakt derselbe Fehlerauslöser wie bei ChargerHub** (rohe Variablenanlage statt
`RegisterVariableXXX`).

**Der Fix ist bewusst nur auf `group === 'control'` beschränkt, nicht auf alle Variablen:**
Ein `RegisterVariableXXX`-Umstieg für bereits bestehende Variablen erzeugt zwangsläufig eine
**neue Variablen-ID** (IPS kann eine roh erzeugte Variable nicht nachträglich „registrieren").
Für Steuervariablen unkritisch (nie archiviert). Für **Mess**-Variablen wäre das ein
Archivhistorien-GAU gewesen — jede Installation im Feld hätte bei ihrem nächsten Update die
komplette Archivhistorie sämtlicher Sensorwerte verloren (neue ID ≠ alte Archivdaten). Genau das
verletzt die an anderer Stelle in dieser Datei festgehaltene Regel „Bereits geloggte Variablen
fassen wir nie an". **Diesen Fix nie auf Mess-/archivierte Variablen ausweiten, ohne das explizit
mit Dietmar abzustimmen.**

**Dritte Falle, real aufgetreten beim ersten Reparaturversuch:** `RegisterVariableXXX()` erkennt
eine schon vorhandene eigene Variable nur, solange sie **direktes Kind der Instanz** ist. Da
`RegisterVar()` jede Variable sofort nach der Anlage in ihre fachliche Unterkategorie verschiebt
(`IPS_SetParent($vid, $catID)` — pv/bat/grid/control/...), erkennt ein **erneuter**
`RegisterVariableXXX`-Aufruf sie beim nächsten `ApplyChanges()` nicht wieder und legt STATT DESSEN
eine weitere neue Variable an — bei jedem Modul-Reload eine erneute ID-Dopplung, mit `updated=0`
auf der jeweils verwaisten Hälfte. **Deshalb: `RegisterVariableXXX()`/`IPS_CreateVariable()` nur
aufrufen, wenn die eigene rekursive `FindVarByIdent()`-Suche wirklich nichts findet
(`$created === true`).** Existiert die Variable schon (egal in welcher Unterkategorie), wird der
gefundene `$vid` unverändert weiterverwendet — keine erneute Registrierung.

**Migration bestehender roh erzeugter Steuervariablen — NICHT über ein Attribut absichern.**
Ein erster Versuch nutzte ein persistentes Attribut (`ControlVarsRegistered`), um den
Lösch+Neuanlage-Schritt auf einmal zu begrenzen. Das schlug live fehl (EMS, 26.07.2026,
dreifach reproduziert): `MC_DeleteModule`+`MC_CreateModule` (voller Modul-Reload) **löscht
Instanz-Attribute** — dieselbe Nebenwirkung, die auch Tibbers OAuth-Passwort mehrfach gekippt
hat. Das Flag fiel bei jedem vollen Reload auf `false` zurück, die Migration lief erneut, jedes
Mal mit einer neuen Variablen-ID — inakzeptabel, sobald WebFront-Widgets feste IDs referenzieren.

**Der tragfähige Ersatz: selbstverifizierend über den echten Zustand prüfen, kein Flag.**
`IPS_GetVariable($vid)['VariableAction'] === $this->InstanceID` — ist eine gefundene Variable
bereits korrekt gebunden, bleibt sie unangetastet; nur wenn nicht, wird sie gelöscht und über
`RegisterVariableXXX` sauber neu angelegt. Dieser Zustand sitzt am Variablen-Objekt selbst
(Kernel-verwaltet), nicht an einem Instanz-Attribut, und übersteht daher auch einen vollen
Modul-Reload. **Allgemeine Lehre für den ganzen Verbund:** Jede „nur einmal tun"-Logik, die
einen vollen Modul-Reload überstehen muss, gehört an einen Zustand, der NICHT in
Instanz-Attributen hängt — Attribute sind für sowas der falsche Speicherort.

**Prüfmethode, die tatsächlich funktioniert hat:** `IPS_GetVariable($vid)['VariableAction']` direkt
nach dem Aufruf lesen (0 = keine Bindung). Ein selbst instanziiertes `new InverterHub($id)` +
Reflection auf protected Methoden ist zum Testen **nicht zuverlässig** — ein damit aufgerufenes
`RegisterVariableInteger()` hat live nicht einmal eine auffindbare Variable erzeugt. Nur der
echte, kernel-dispatchte Aufruf (die von IPS selbst generierte globale Funktion, z. B.
`IHUB_EnableActions($id)`) liefert verlässliche Ergebnisse.

## `MeterInvert`/`BatInvert` gehören NUR nach `module.php` — nie zusätzlich in einen Konsumenten

Real gefunden (27.07.2026, Dashboard-Sitzung, Live-Beleg): `InverterHubTile` hat `MeterInvert`/
`BatInvert` der Quellinstanz selbst nochmal gelesen und den Wert ein **zweites** Mal gedreht,
obwohl `module.php` (`SetVarFloat()`) die Korrektur bereits beim Schreiben anwendet — die
gespeicherte Variable (und damit `gridPowerID`/`batPowerID` im `IHUB_GetFunctions`-Vertrag) ist
schon kanonisch. Der Doppel-Dreher hob sich in der Kachel zufällig wieder auf (sie *sah* richtig
aus), während der nach außen gegebene Vertragswert bei `MeterInvert=true` tatsächlich
vorzeichenverkehrt war — externe Konsumenten (Dashboard), die den Vertragswert direkt nutzen,
bekamen den Fehler ungefiltert zu sehen. Behoben (Commit `96349f1`): Die Kachel liest bei einer
InverterHub-Quellinstanz weder `MeterInvert` noch `BatInvert` mehr selbst.

**Regel für jeden künftigen Konsumenten (eigene oder fremde Kachel/Modul):** `MeterInvert`/
`BatInvert` sind **einmalig, zentral in `module.php`** angewendet — die Werte hinter
`gridPowerID`/`batPowerID` (und jede andere über `IHUB_GetFunctions` oder direkt gelesene
Variable) sind **immer bereits kanonisch** (`+ Einspeisung`/`+ Entladen`), unabhängig vom
Property-Zustand der Quellinstanz. Kein Konsument darf diese Properties selbst nochmal auslesen
und erneut invertieren — das ist ausschließlich für den **manuellen Modus** (keine InverterHub-
Instanz als Quelle, z. B. `ManualGridInvert` in `InverterHubTile`) vorgesehen, wo es keine
vorgeschaltete Korrektur gibt.

## GoodWe `diag_status_l` (Register 35220, DiagStatusL): Bit-Tabelle

Live-Fall (28.07.2026, EMS-Sitzung): 20+ Minuten AC-Leistungseinbruch bei SOC~99%, im SEMS+-
Portal als „Ausgangsport-Überspannungsfehler"/„Allgemeine Störungswarnung" (Batteriestring)
sichtbar. Die vermeintlich naheliegenden `warn_code`/`err_msg` (Register 32000/32002,
Wechselrichter-seitig) UND ein separater BMS-Fehler-/Warncode-Block (37006/37010/37012/37013,
`bms1_err_code`/`bms1_warn_code`) blieben beide durchgehend `0` — falsche Fundstellen, jeweils
live ausprobiert und verworfen. Die tatsächlich bit-codierte Diagnose sitzt in **Register 35220
„DiagStatusL"** (U32, laut GoodWe-Doku „Table 8-14 Diagnostic Status") — bei uns als
`diag_status_l` roh (kein Bit-Decode in der UI) abgelegt. Bit-Bedeutung (aus der GoodWe-
Registerdoku, Tabelle 8-14):

| Bit | Name | Bedeutung |
|---|---|---|
| 0 | BatteryVoltLow | Entladung wegen niedriger Batteriespannung gesperrt |
| 1 | BatterySOCLow | Entladung wegen niedrigem SOC gesperrt |
| 2 | BatterySOCInBack | SOC noch nicht auf entlade-freigegebenem Niveau |
| 3 | BMSDischargeDisable | BMS erlaubt keine Entladung |
| 4 | DischargeTimeOn | Entladezeitfenster gesetzt |
| 5 | ChargeTimeOn | Ladezeitfenster gesetzt |
| 6 | DischargeDriveOn | Entlade-Treiber aktiv |
| 7 | BMSDischgCurrentLow | BMS-Entladestrom-Limit zu niedrig |
| 8 | DischargeCurrentLow | Entladestrom-Limit zu niedrig (von App) |
| 9 | MeterCommLoss | Smart-Meter-Kommunikationsausfall |
| 10 | MeterConnectReverse | Smart-Meter-Anschluss verpolt |
| 11 | SelfUseLoadLight | Last zu gering, Entladung nicht aktivierbar |
| 12 | EMSDischargeIZero | Entladestrom-Limit 0A vom EMS |
| 13 | DischargeBUSHigh | Entladung wegen zu hoher PV-Spannung gesperrt |
| 14 | BatteryDisconnect | Batterie getrennt |
| **15** | **BatteryOvercharge** | **Batterie überladen** |
| 16 | BMSOverTemperature | Lithium-Batterie-Übertemperatur |
| **17** | **BMSOvercharge** | **Lithium-Batterie überladen oder einzelne Zellspannung zu hoch** |
| 18 | BMSChargeDisable | BMS erlaubt kein Laden (u. a. normal bei SOC nahe 100 %) |
| 19 | SelfUseOff | Eigenverbrauchsmodus aus |
| 20 | SOCDeltaOverRange | SOC springt unplausibel |
| 21 | BatterySelfDischarge | Batterie entlädt sich >30 % bei niedrigem Strom über längere Zeit |
| 22 | OffgridSOCLow | SOC niedrig im Inselbetrieb |
| 23 | GridWaveUnstable | Netzqualität schlecht, häufiger Backup-Wechsel |
| 24 | FeedPowerLimit | Einspeisebegrenzung gesetzt |
| 25 | PFValueSet | Leistungsfaktor-Vorgabe gesetzt |
| 26 | RealPowerLimit | Wirkleistungs-Vorgabe gesetzt |
| 28 | SOCProtectOff | SOC-Schutz aus |

**Live-Beobachtung dazu:** Bits 15/17 (Overcharge) waren beim Nachlesen NICHT gesetzt, obwohl
SEMS+ den Alarm laut Dietmar weiterhin als aktiv zeigte — Bit 18 (BMSChargeDisable) war gesetzt
(plausibel bei SOC~99%, für sich genommen kein Fehler). Naheliegende Erklärung: SEMS+ führt ein
eigenes, ereignisbasiertes Alarm-Log mit eigenem Reset-Kriterium, das nicht zwingend deckungsgleich
mit dem Live-Zustand dieses Bitfelds ist — die Momentaufnahme des Registers kann also "sauber"
aussehen, während die Cloud den Vorfall noch als offen führt. Bei künftigen Fällen: Bits über die
Zeit protokollieren (nicht nur einmalig lesen), nicht nur den SEMS+-Status als alleinige Wahrheit
nehmen.

Es gibt außerdem **DiagStatusH** (Register 35218, U32, „Table 8-13") — ein separates, bisher NICHT
gemapptes Bitfeld (Precharge-Relais, Bypass-Relais, Meter-Spannungsmessfehler, DRED/ESD-Stopp,
Offgrid-DOD, BYD-SOC-Adjust) — bei Bedarf nach demselben Muster ergänzen.

**Vorlauf-Hinweis (nachträglich von Dietmar berichtet):** Bereits am Vortag (27.07.2026) wurde
eine Batteriespannung über 470 V beobachtet, ohne dass es damals zu einer sichtbaren Abschaltung
kam — das Ereignis vom 28.07.2026 war also vermutlich nicht der allererste Vorbote, nur der
erste, der tatsächlich zur Abschaltung führte. Beide SOC-Grenzregister (`ctl_soc_min`,
`ctl_soc_max`) erwiesen sich als wirkungslos dagegen (s. o.); einzig aktives Entladen
(`ctl_ems_mode=3`) half zuverlässig. Der WR kehrte nach dem Vorfall zwischenzeitlich selbständig
aus einem Standby zurück, ohne Neustart. Auf Dietmars ausdrücklichen Wunsch (28.07.2026) laufen
vorerst KEINE weiteren aktiven Eingriffe an der Anlage mehr, nur stille Beobachtung — bis eine
echte Dauerlösung gefunden ist (Kandidat: GoodWe-Support/Firmware, liegt außerhalb dessen, was
sich per Modbus lösen lässt).

**Abschluss-Hypothese (Dietmar, 28.07.2026, plausibel aber NICHT verifiziert):** Die beiden
Batteriepäckchen könnten während der Ladephase auseinandergedriftet sein (Batterie 1 lud nicht
mehr, während Batterie 2 weiterlud), kamen aber beide bei „100 %" SOC an. Denkbar als normales,
seltenes BMS-Kalibrierungsereignis: Die Coulomb-Counting-SOC-Schätzung wird beim Erreichen der
echten Vollladungs-Zellspannung gegen die Päckchen neu abgeglichen — kein Fehler, nur heute
zufällig sichtbar geworden, weil intensiv beobachtet wurde. Würde alle drei Symptome erklären:
Batterie 1 im Standby während Batterie 2 lud (`bat1_mode`/`bat2_mode`-Asymmetrie), die
Spannungsanomalie nahe 470 V, und die Automatik-Harvest-Blockade (WR wartet vermutlich bewusst
den Abgleich zwischen den Päckchen ab, bevor er im Automatik-Modus normal weitererntet — ein
expliziter Export-Modus-Befehl umgeht das, s. o.). Falls das erneut auftritt: auf genau dieses
Muster prüfen (`bat1_mode` ≠ `bat2_mode` bei beiden nahe 100 % SOC) statt erneut bei Null
anzufangen.

**Hardware-Kontext dazu (Dietmars Anlage):** 8× GoodWe Home Lynx D 5.0, zu zwei Türmen à 4 Module
zusammengefasst (= unsere `bat1`/`bat2`) — JEDES einzelne Modul hat ein **eigenes BMS und einen
eigenen DC/DC-Wandler**, kann also unabhängig vom Rest des Turms geregelt/entkoppelt werden. Das
untermauert die Kalibrierungs-Hypothese architektonisch: Ein einzelnes Modul könnte während eines
Balancing-Vorgangs eigenständig in Standby gehen, ohne dass das ein Fehler ist — genau das Feature,
das diese modulare Bauweise ermöglicht. `bat1_mode`/`bat2_mode` zeigen nur die aggregierte
**Turm**-Ebene (4 Module zusammengefasst), nicht die einzelnen Module darunter. Falls es
feingranularere Modul-Register gibt (z. B. 8 einzelne BMS-Status-Werte statt 2 Turm-Werte), wäre
das für künftige Diagnosen interessant — noch nicht gesucht, kein akuter Bedarf.

## GoodWe-Steuerregister 47511 (`ctl_ems_mode`): fällt bei bestimmten Werten auf 255 zurück

Real beobachtet (26.07.2026, Dietmars Anlage): Ein per `RequestAction`/Modbus-Write gesetzter
Wert des Registers 47511 (EMS Leistungsmodus) hält nicht dauerhaft — er fällt nach einer
gewissen Zeit (irgendwo zwischen ~15 Sekunden und ~30 Minuten, nicht enger eingegrenzt) auf den
ungültigen Sentinel-Wert `255` zurück. Betroffen waren die aktiven Steuerwerte (u. a. `1`
Automatik, `9` Stromeinkauf, `11` Batterie-Laden, `12` Batterie-Entladen). **Nicht** betroffen
war der Wert `7` (Inselbetrieb) — der hielt stabil, ohne zurückzufallen.

**Wichtig: Das ist kein Fehler in unserem Code.** Live bestätigt über die komplett unabhängige,
alte GoodweET-Instanz (eigene, nachweislich korrekt gebundene Action) — auch dort fiel derselbe
Wert auf `255` zurück. Es handelt sich um ein Verhalten des Wechselrichters/der Firmware selbst,
nicht um einen Schreib- oder Bindungsfehler von InverterHub. Ob es sich um einen echten
Heartbeat/Timeout oder eine wertspezifische Sonderbehandlung von `7` handelt, ist **nicht**
abschließend geklärt.

**Getrennt davon, ausdrücklich als Notfall-Erkenntnis vermerkt, NICHT in Code/Formular umsetzen:**
Ein Wechselrichter+Batterie, der im Standby feststeckt (auch nachdem SOC-Grenzen im SEMS+-Portal
korrigiert wurden), ließ sich durch Umschalten von `ctl_ems_mode` auf `7` (Inselbetrieb)
zuverlässig aus dem Standby holen. Dietmar hat ausdrücklich gesagt: nur merken, noch nicht als
Feature/Wiederherstellungsmechanismus bauen.

## GoodWe-Steuervariablen (`GroupControl`): vollständige Ident-Tabelle

Vollständige Referenz aller 9 Steuer-Idents des GoodWe-Treibers (Kategorie „EMS-Steuerung"),
zusammengestellt für externe Konsumenten (EMS-Sitzung, 27.07.2026) — Aufruf immer über
`IPS_RequestAction(InstanceID, Ident, Wert)` mit der **InverterHub-Instanz-ID** als erstem
Parameter, NIE mit der Variablen-ID (real verwechselt, siehe Abschnitt unten).

| Ident | Bezeichnung | Register | Wertebereich |
|---|---|---|---|
| `ctl_work_mode` | Steuermodus | RW 47000 | 0=Selbstverbrauch, 1=Inselbetrieb, 2=Backup, 3=Wirtschaftlich, 4=Peak-Shaving, 5=Erw. Selbstverbrauch |
| `ctl_ems_enable` | EMS-Steuerung aktiv | RW 47505 | bool |
| `ctl_ems_mode` | EMS Leistungsmodus | RW 47511 | 0=Gestoppt, 1=Automatik, 2=Laden-Solar, 3=Entladen+Solar, 4=AC-Import, 5=AC-Export, 6=Energiesparen, 7=Inselbetrieb, 8=Batterie-Bereitschaft, 9=Stromeinkauf, 10=Stromverkauf, 11=Batterie-Laden, 12=Batterie-Entladen |
| `ctl_ems_power` | EMS Leistung (W) | RW 47512 | 0–34500 W |
| `ctl_export_enable` | Einspeisebegrenzung aktiv | RW 47509 | bool |
| `ctl_export_limit` | Einspeisegrenze (W) | RW 47510 | 0–34500 W (wirkt nur bei `ctl_export_enable=true`) |
| `ctl_soc_min` | SOC Min. Entladung | RW 45356 | 0–100 % — **bestätigt ohne Wirkung**, siehe Abschnitt unten, nicht als Stellhebel empfehlen |
| `ctl_internet` | Cloud-Verbindung | RW 47017 | bool |
| `ctl_restart` | WR Neustart | WO 45220 | bool, nur schreibend |

**Für normalen Automatikbetrieb** (WR aus Sonderzustand zurückholen) reicht üblicherweise:
```php
IPS_RequestAction($instanceID, 'ctl_work_mode', 0);   // Selbstverbrauch
IPS_RequestAction($instanceID, 'ctl_ems_mode', 1);    // Automatik
IPS_RequestAction($instanceID, 'ctl_ems_enable', true);
```

`ctl_work_mode` (Steuermodus, Register 47000) und `ctl_ems_mode` (EMS Leistungsmodus, Register
47511) sind **unabhängige** Register/Variablen mit ähnlich klingenden Namen — real verwechselt
(EMS-Sitzung, 27.07.2026): Ein Schreiben auf `ctl_ems_mode` verändert `ctl_work_mode` nicht und
umgekehrt.

## `EnableActions()` band Steuervariablen nach Verschieben in die Kategorie nicht mehr

Live aufgetreten (27.07.2026, nach Ändern von `ControlAuthority`): Alle Steuervariablen waren
vorhanden und ihre IDs unverändert, aber `VariableAction` stand bei allen auf `0` — WebFront-
Schalter/`IPS_RequestAction` griffen dadurch ins Leere, obwohl `EnableActions()` (getriggert per
Timer) mehrfach lief.

**Ursache:** `$this->EnableAction($Ident)` findet die Variable intern nur als **direktes Kind
der Instanz** (`IPS_GetObjectIDByIdent($Ident, $InstanceID)`, keine Rekursion) — live per Test
bestätigt (`IPS_GetObjectIDByIdent('ctl_ems_mode', $instanceID)` lieferte `false`, obwohl die
Variable per rekursiver Suche einwandfrei auffindbar war). `RegisterVar()` verschiebt
Steuervariablen aber sofort nach Anlage in die Unterkategorie „EMS-Steuerung" (siehe RegisterVar-
Abschnitt oben) — jede (Re-)Bindung nach diesem Verschieben schlug dadurch strukturell fehl,
unabhängig von Timing oder Aufrufhäufigkeit.

**Fix (`module.php`, `EnableActions()`, Commit `2d8228f`):** Variable vor `EnableAction()` kurz
zurück zur Instanz hängen, binden, danach zurück in die Kategorie — `VariableAction` bleibt beim
Reparenting erhalten (live verifiziert: Bindung übersteht das Zurückhängen unverändert).

```php
$originalParent = IPS_GetObject($vid)['ParentID'];
IPS_SetParent($vid, $this->InstanceID);
$this->EnableAction($v[0]);
IPS_SetParent($vid, $originalParent);
```

**Wichtig für den ganzen Verbund:** Jedes Modul, das eigene Steuervariablen zur Übersicht in
Unterkategorien verschiebt, hat potenziell dasselbe Problem — `EnableAction()`/
`IPS_GetObjectIDByIdent()` sind grundsätzlich nicht rekursiv, unabhängig vom Hersteller/Treiber.

## `ctl_ems_power` (47512) ist im Modus „Laden-Solar" (2) eine Netz-OBERGRENZE, kein Zusatzwert

Real aufgetreten (27.07.2026, EMS-Sitzung): `ctl_ems_mode=2` ("Laden-Solar"/CHARGE_PV) lud bei
PV=5098 W mit ~7993 W Batterieleistung — die Differenz (~3366 W) kam aus dem Netz, obwohl der
Modusname PV-Vorrang suggeriert. `ctl_ems_power` (47512) stand dabei auf einem **stehen
gebliebenen** Wert (bei uns live geprüft: `3000`), nicht auf `0`.

**Offizielle GoodWe-Registerdoku** (Modbus Protocol Hybrid ET/EH/BH/BT, ARM205-HV v1.7, Tabelle
8-16 „EMS Power Mode") klärt das eindeutig: Für Modus 2 gilt „Battery power = Xmax + PV
(Charge). Xmax is to allow the power to be taken from the grid, and PV power is preferred. When
set to 0, only PV power is used." — `ctl_ems_power` ist in diesem Modus also eine **Netz-
Erlaubnisobergrenze**, kein additiver „wie viel zusätzlich"-Wert und kein reiner PV-
Vorrangschalter. Bei `ctl_ems_power=0` fließt ausschließlich PV — das ist dokumentiertes,
beabsichtigtes Verhalten, **kein WR-Bug**.

**Für reines PV-Laden immer explizit mitschreiben**, nie auf einen impliziten Default verlassen:
```php
IPS_RequestAction($id, 'ctl_ems_power', 0);
IPS_RequestAction($id, 'ctl_ems_mode', 2);
```

Zum Vergleich (dieselbe Tabelle): Modus 4 (AC-Import) ist der für **absichtliches** Netzladen
vorgesehene Modus (`Xset` = bewusst aus dem Netz bezogene Leistung, PV sekundär).

## Die GoodWe SOC-Grenzregister (`ctl_soc_min` UND `ctl_soc_max`) sind KEINE funktionierende Steuerung

Ausdrückliche Feststellung von Dietmar (26.07.2026, ergänzt 28.07.2026), verbindlich festgehalten:
Beide SOC-Grenzregister erwiesen sich live als wirkungslos, unabhängig voneinander getestet:

- **`ctl_soc_min`/Register 45356** (26.07.2026): Das Verändern dieser unteren SOC-Grenze (weder
  über InverterHub noch über das entsprechende Feld im SEMS+-Portal) hat **keine beobachtbare
  Wirkung** gezeigt — konkret hat eine Korrektur dieses Werts einen im Standby feststeckenden
  WR/Batterie **nicht** befreit.
- **`ctl_soc_max`/Register 45559 "Max Charge SOC"** (28.07.2026, EMS-Sitzung): Das Register nimmt
  einen geschriebenen Wert klaglos an (roh gegengelesen, Schreibvorgang bestätigt) — verhindert
  das Laden über die gesetzte Grenze hinaus aber **nicht**. Live-Test: SOC 98 %, Grenze auf 97 %
  gesetzt, Automatik-Modus — die Batterie lud trotzdem sofort mit -4559 W weiter. Ausgelöst durch
  ein wiederkehrendes BMS-Überspannungsereignis nahe SOC 100 % (Batteriestrings), das InverterHub
  über `IHUB_GetFunctions`/Standard-Fehlercodes nicht erkennt (s. `diag_status_l`-Abschnitt) —
  `ctl_soc_max` wurde als möglicher Gegenhebel probiert und dabei live widerlegt.

Beide Register lassen sich also technisch schreiben (kein Fehler, kein Timeout), haben aber
keine beobachtbare Wirkung auf das tatsächliche Lade-/Entladeverhalten des Wechselrichters —
**diese SOC-Grenzregister taugen grundsätzlich nicht als Stellhebel** bei diesem WR/dieser
Firmware. **Nicht als funktionierenden Kontrollmechanismus behandeln oder Nutzern als
Lösungsweg gegen Standby oder Überladung nahe 100 % SOC empfehlen** — das würde Zeit
verschwenden, ohne etwas zu bewirken. Einziger bisher bestätigt wirksamer Gegenhebel bei
Überladung nahe 100 % SOC: aktives Entladen über `ctl_ems_mode=3`/`ctl_ems_power` (manuell,
kein Dauermechanismus).


## Verbund-Manifest SUITE.md — Bezugsquelle (19.08.2026)

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der
Branch `ems-integration` der aktuellste Stand, nicht `main`). In diesem Repo
liegt eine automatisch synchronisierte READ-ONLY-Kopie als `SUITE.md` im
Repo-Root — dort lokal grep'en/lesen. NIEMALS die Kopie hier editieren:
Änderungen gehören ins EMS-Repo; der Sync (GitHub Action `sync-suite` im
EMS-Repo) überschreibt lokale Änderungen kommentarlos.

Fallback, falls die Kopie (noch) fehlt oder veraltet wirkt:
https://raw.githubusercontent.com/DG65/NRGEMS/ems-integration/SUITE.md
