# Eigenes Wechselrichter-Modul an den NRG-Stack anbinden

Diese Anleitung richtet sich an Entwickler, die ein **eigenes Symcon-Modul für einen Wechselrichter oder Hybrid-Speicher**
haben und es für das EMS und die Anzeigemodule des NRG-Stacks sichtbar machen wollen. Das eigene Modul bleibt ein
eigenständiges Modul. Es braucht den InverterHub nicht, und der InverterHub braucht das Fremdmodul nicht.

Gemeint ist der **Vertrag `inverter`, Version 1.10**, wie ihn der InverterHub mit `IHUB_GetFunctions` anbietet.

## Das Prinzip

1. Dein Modul bietet eine Funktion `<PRÄFIX>_GetFunctions($id)` an (Präfix aus der `module.json`). Sie liefert **ein Array
   mit den Feldern unten**, je Wechselrichter eine Instanz. Sie muss schnell sein, ohne Nebenwirkung und ohne Geräteabfrage.
2. Gelesen wird über **Variablen-IDs** (`...ID`-Felder). `0` heißt „gibt es nicht“, nicht „Wert 0“.
3. Gesteuert wird über `IPS_RequestAction()` auf die Instanz mit festen Idents, nie über eine eigene Set-Funktion.

## Felder

| Feld | Bedeutung |
|---|---|
| `contractVersion` | `'1.10'`. Konsumenten prüfen die Hauptversion. |
| `contractType` | immer `'inverter'` (ohne das Feld wird die Funktion als `function` gelesen) |
| `manufacturer`, `instanceID`, `virtual` (immer `false` bei einem echten Gerät), `measured` (`true` nur bei echten Messwerten) | Kennzeichnung |
| `pvPowerID`, `acPowerID`, `batPowerID`, `gridPowerID`, `socID`, `houseLoadID` | Variablen-IDs in W bzw. %, `0` wenn nicht vorhanden. Die Vorzeichen sind schon die **kanonischen** (Eigenverbrauch/Tabellen siehe SUITE.md), nicht die Gerätewerte. |
| `controllable`, `controlAuthority` | steuerbar ja/nein und wer schreiben darf: `ems`, `external` oder `none` |
| `failsafeSec` | Sekunden, nach denen das Gerät oder dein Modul selbst in einen sicheren Zustand zurückfällt, wenn die Befehle ausbleiben. **`0` oder fehlend = kein Rückfall: dann gibt das EMS das Schreiben nicht frei.** |
| `failsafeMode` | `'deadman'`: das EMS erneuert den Befehl innerhalb von `failsafeSec`/2 |
| `remoteControl` | Steuerpunkte (siehe unten), leeres Array ohne Fernsteuerung |

Weitere optionale Felder (MPPT, Batterie je Pack, Phasenströme) stehen in `CLAUDE.md` des InverterHub-Repos.

## Steuern: `remoteControl`

IDs der Variablen, die über `IPS_RequestAction()` beschrieben werden: `modeID`, `powerID`, `gridChargeID`, `socMinID`,
`socMaxID`, `failsafeSecID`; zurückgemeldet werden `availableID`, `activeID`, `statusID`, `testedID`.

- **Reihenfolge:** zuerst Leistung, SOC-Grenzen und Netzladen setzen, **zuletzt den Modus**. Nur das Schreiben des Modus
  sendet.
- **Modi:** `0` aus (Gerät läuft wieder selbst), `1` Akku halten, `2` Akku ±W, `3` Netzanschlusspunkt ±W. Biete nur an, was
  dein Gerät kann, und nenne es in `modesID`.
- **Vorzeichen:** Modus 2: **+ Laden, − Entladen**. Modus 3: **+ Einspeisung, − Bezug**.
- **Rückfall (Pflicht zum Steuern):** Der Totmann liegt beim Gerät oder in deinem Modul, nicht beim EMS. `failsafeSec`
  muss der Wert sein, den du wirklich im Gerät einstellst (nach Rundung und Begrenzung). Das EMS schreibt zyklisch nach.
  Dasselbe Schreiben darf nur die Frist verlängern, nicht neu starten.
- **`testedID`:** `false`, wenn die Anbindung nur nach Herstellerdokument gebaut und noch nicht an einem Gerät bestätigt
  ist. Das EMS steuert solche Geräte nur nach ausdrücklicher Zustimmung des Nutzers.

## Was das EMS tut

Es sucht Fremdmodule über die Modulliste und den Präfix, liest sofort und **steuert erst nach Freigabe je Instanz** durch
den Nutzer. Eigene Module haben Vorrang, Doppel werden über `deviceSerial` erkannt. Werte außerhalb der Vertragsgrenzen
werden verworfen.
