# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/) und
[Semantic Versioning](https://semver.org/lang/de/).

---

## [1.1.0] — 2026-05-14 — Forecast-Finanzen, Datenexport, mehr Komfort

Erstes MINOR-Release nach der v1.0.x-Bugfix-Serie. Sieben neue Funktionen
und ein Datenmodell-Fix — alle additiv und abwärtskompatibel. **Das
Speicherschema bleibt unverändert bei 1.0.3**; die neuen Einstellungen
werden über die Defaults gemerged, eine `settings.json` aus einer älteren
Version funktioniert ohne Migrationsschritt weiter.

### Added

- **[F-02] Vertragsbasierte Kostenprognose.** Die Prognose rechnete bisher
  mit dem letzten bekannten Arbeitspreis. Jetzt löst der `ForecastService`
  pro Prognosemonat den dann aktiven Vertrag auf und verwendet den **für
  diesen Monat gültigen** Arbeits- und Grundpreis aus der Preishistorie —
  ein im Vertrag für die Zukunft gepflegter Preiswechsel schlägt damit
  korrekt in `cost_estimated` durch. Die Monatsdetail-Tabelle hat zwei
  neue Spalten: *projizierter Abschlag* und *laufender Saldo* (kumuliert
  Kosten − Abschlag). Künftige Boni werden nicht fortgeschrieben — nur im
  Vertrag mit Gutschriftdatum gepflegte Boni fließen ein.
- **[F-03] Abrechnungszyklus je Verbrauchsart.** Drei neue Settings-Keys
  `billing_cycle_anchor_gas|strom|wasser` (Format `MM-TT`, Default
  `01-01`). Der Saldo offener Verträge (`end = null`) wird nun bis zum
  nächsten Abrechnungsstichtag projiziert statt bis „heute + 12 Monate".
  Verträge mit gepflegtem Ende verwenden weiterhin dieses Ende.
- **[F-04] Jahresvergleich mit Monatsdeltas.** Die Korrelations-Ansicht
  bekommt ein eigenes Widget mit der Monat-für-Monat-Differenz der beiden
  jüngsten Jahre mit Daten — absolut und prozentual, inklusive
  Summenzeile über die gemeinsamen Monate. Das bestehende
  „Jahresvergleich"-Liniendiagramm bleibt unverändert daneben bestehen.
- **[F-05] Erinnerung an Vertragsende.** Die `contract-status`-Antwort
  enthält pro Vertrag `days_until_end`, `should_remind` und `remind_stage`
  (0–3). Drei Schwellen sind als Settings-Keys `contract_remind_days_1|2|3`
  konfigurierbar (Default 90 / 30 / 1 Tage). Die Korrelations-Ansicht
  zeigt fällige Verträge als gestuften Hinweis-Banner.
- **[F-06] CSV-Import von Ablesungen.** Neuer zähler-gebundener Endpoint
  `POST /api/utility/{utility}/meters/{id}/readings/import-csv` (Body:
  CSV als `text/plain`). Bereits vorhandene Ablesungen am selben Datum
  werden **überschrieben und im Ergebnis gemeldet** (`imported`,
  `overwritten`, `skipped`, `errors`). Akzeptiert `;`/`,` als Trenner,
  `TT.MM.JJJJ` oder ISO-Datum, deutsches Dezimalkomma. Die Import-Logik
  steckt im quell-agnostischen `ReadingImportService` — eine künftige
  Smart-Meter-Anbindung (siehe Roadmap) kann denselben Kern ohne
  CSV-Parsing wiederverwenden. UI: „CSV-Import"-Knopf je Zähler in der
  Zählerverwaltung.
- **[F-07] CSV-Export.** Drei neue Endpoints liefern tabellarische
  Exporte als Datei-Download: `GET /api/export/{utility}/monthly.csv`
  (Monatsaggregate), `GET /api/export/{utility}/readings.csv`
  (Rohablesungen), `GET /api/export/temperatures.csv` (Temperaturreihe).
  Semikolon-getrennt, UTF-8 mit BOM, deutsches Dezimalkomma — direkt in
  Excel/LibreOffice/Google Sheets nutzbar. UI: Export-Kacheln in den
  Einstellungen. Ergänzt das vollständige JSON-Backup, ersetzt es nicht.
- **[F-10] Wasser-Spar-Index.** Die Korrelations-Ansicht zeigt für Wasser
  einen Index `(Liter/Person/Tag) / Referenz × 100` über die jüngsten bis
  zu 12 Monate, mit Einordnung auf einem Band. Die Bandgrenzen sind als
  Settings-Keys `wasser_sparindex_gut` / `wasser_sparindex_warnung`
  konfigurierbar (Default 100 / 150).
- Einstellungs-Ansicht überarbeitet: gruppierte Settings-Karten in einem
  responsiven Raster, Feld-Erklärungen, Einheitenkennzeichnung. Das
  Settings-Inventar wächst von 20 auf 28 Schlüssel.

### Fixed

- **[#2 — Datenmodell] Schmutzwasser-Basis `separater_zaehler` war ohne
  Wirkung.** Im Wasser-Vertragsmodell (v1.0.3) konnte für die
  Schmutzwasser-Komponente die Basis `separater_zaehler` gewählt werden —
  die Verbrauchsberechnung nutzte aber in jedem Fall das Trinkwasser-m³.
  Jetzt löst der `ConsumptionService` die Basis `separater_zaehler` über
  das Feld `separater_zaehler_meter_id` auf und rechnet mit dem
  monatlichen m³ des referenzierten Zählers. Eine Rekursionssperre
  verhindert Endlosschleifen bei (fehlerhaft) gegenseitig
  referenzierenden Zählern. Kein Schema-Eingriff — das Feld existierte
  bereits, wurde nur nicht ausgewertet.

### Notes

- Schema-Version unverändert **1.0.3**. Keine Migration nötig: fehlende
  Settings-Keys werden beim Lesen aus den Defaults ergänzt.
- Bekannte Einschränkung F-02: bei Wasser mit Schmutzwasser-Basis
  `separater_zaehler` nutzt die *Vorausschau* das Trinkwasser-Volumen als
  Schmutzwasser-Basis (der separate Zähler wird nicht selbst prognostiziert).
  Die *historische* Auswertung rechnet das separate Volumen korrekt.

[1.1.0]: https://github.com/Bingerminger/energietracker/releases/tag/v1.1.0

---

Drei via GitHub gemeldete Bugs in der Vertragsverwaltung und in der
Korrelations-Ansicht.

### Fixed

- **[#1] Vertrags-Button irreführend benannt.** Auf den Utility-Seiten
  (Gas/Strom/Wasser) zeigte der Action-Button in der „Verträge &
  Abschläge"-Karte den Text *„+ Neuer Vertrag"* — er führte aber zur
  Vertragsverwaltungs-Seite, nicht zum Vertrag-Anlegen-Dialog. Neu:
  *„⚙️ Verträge verwalten"* mit passendem Icon. Das eigentliche
  Anlegen ist weiterhin in der Vertragsverwaltung selbst per
  „+ Neuer Vertrag"-Button erreichbar.

- **[#2] Speichern/Abbrechen-Buttons im Vertrag-Edit-Modal funktionierten
  nicht bei manchen Verträgen.** Wenn beim Aufbau des Modals ein
  Wiring-Schritt (Boni-Sektion oder Zeilen-Buttons einer Stichtag-
  Gruppe) eine Exception warf — etwa weil ein migriertes v0.9.0-Format
  unerwartete Strukturen mitbrachte — wurden die Fußleisten-Buttons nie
  verbunden, weil sie nach den anderen Handlern gebunden wurden.
  Fixes:
  - Cancel/Save werden jetzt **als allererstes** gebunden, bevor
    irgendein anderer Sub-Handler läuft. Selbst wenn ein nachfolgender
    Schritt fehlschlägt, bleibt das Modal benutzbar.
  - Alle DOM-Lookups in `bindRowHandlers`, `bindBonusHandlers`,
    `bindBonusRow` defensiv mit Optional-Chaining (`?.`). Fehlt ein
    Element, gibt's eine `console.warn`, aber kein TypeError mehr.
  - Die globale ID `#bonus-section` wurde durch `[data-section="bonus"]`
    ersetzt, damit gestapelte Modale nicht über IDs kollidieren können.

- **[#3] Anomalien-Tabelle in der Korrelations-Ansicht zeigte leere
  Werte.** Feldnamen-Mismatch zwischen Backend und Frontend:
  `AnomalyService` liefert `value` / `z_score` / `deviation` / `percent`
  / `kind` / `hdd` / `avg_temp` — das Frontend las `a.actual` und `a.z`
  (existieren nicht), wodurch *Verbrauch* und *Abweichung (σ)* immer
  NaN bzw. leer waren. Das schuf den Eindruck, dass die Anomalien nicht
  zu den eigentlichen Zählerdaten passten.
  Die Anomalien-Tabelle wurde dabei gleich angereichert: Absolute
  Abweichung, Prozent-Abweichung, σ-Wert, plus HGT und ø-Temperatur bei
  HGT-relevanten Utilities. Empty-State-Text erklärt jetzt, gegen welches
  Modell die Schwelle gerechnet wird.

### Notes

- Das geänderte Anomalien-Markup ist nicht-breaking; alle Backend-Felder
  waren bereits vorhanden, nur das Frontend hat sie schlicht nicht
  gelesen.
- Schema-Version (`data/meta.json`) bleibt auf `1.0.3` — keine
  Datenmodell-Änderung in v1.0.4.

[1.0.4]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.4

---

## [1.0.3] — 2026-05-11 — Wasser-Vertragsmodell

Fachlicher Bugfix am Wassermodul. In v1.0.2 wurden Wasserverträge mit
demselben Schema wie Gas und Strom modelliert (ein `working_prices`-Array
mit `ct_per_kwh`), was bei Wasser strukturell falsch ist: eine deutsche
Wasserrechnung enthält drei separate Positionen — Trinkwasser, Schmutzwasser
und Niederschlagswasser — mit jeweils eigener Berechnungslogik.

### Changed — Wasser-Vertragsmodell (Schema 1.0.3)

Wasserverträge haben jetzt drei Komponenten-Blöcke. Gas- und Strom-
verträge bleiben strukturell unverändert.

```json
{
  "id": "c_wasser_...",
  "meter_id": "m_wasser_haupt",
  "provider": "Kommunale Wasserwerke Leipzig",
  "tariff_name": "Trink-, Schmutz- und Niederschlagswasser 2025",
  "start": "2025-01-01",
  "end":   "2026-12-31",

  "trinkwasser": {
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 255.0}],
    "base_prices":    [{"from": "2025-01-01", "eur_per_month": 8.50}]
  },

  "schmutzwasser": {
    "basis": "trinkwasser",
    "separater_zaehler_meter_id": null,
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 305.0}]
  },

  "niederschlagswasser": {
    "rates": [
      {"from": "2025-01-01", "eur_per_m2_year": 1.50, "versiegelte_flaeche_m2": 120}
    ]
  },

  "advance_payments": [{"from": "2025-01-01", "amount_eur": 72.00}],
  "bonuses": []
}
```

**Berechnungen pro Monat:**

- *Trinkwasser*: `m³ × ct_per_m3 / 100 + eur_per_month`
- *Schmutzwasser*: `m³ × ct_per_m3 / 100`. `m³` ist standardmäßig der
  Trinkwasser-Verbrauch (Basis `trinkwasser`, Standard in 95% der DE-Haushalte).
  Bei Basis `separater_zaehler` wird in v1.0.3 vorerst der gleiche Trinkwasser-
  Wert verwendet — eine echte Auswertung des separaten Zählers folgt in
  einer späteren Version.
- *Niederschlagswasser*: `(versiegelte_flaeche_m2 × eur_per_m2_year) / 12`.
  Stichtag-Historie für Tarif **und** Fläche, falls sich beides ändert.

### Added — Auto-Migration

Beim ersten Start auf v1.0.3 prüft `Storage\Migrator::needsWaterContractsUpgrade()`
ob noch Wasser-Verträge in der alten v1.0.2-Form (`working_prices` + `base_prices` direkt
am Vertrag) vorliegen. Falls ja:

- Alte `working_prices` wandern nach `trinkwasser.working_prices`, wobei das
  Feld `ct_per_kwh` (das bei Wasser semantisch schon ct/m³ war) zu `ct_per_m3`
  umbenannt wird.
- Alte `base_prices` wandern nach `trinkwasser.base_prices`.
- `schmutzwasser` und `niederschlagswasser` werden mit leeren Arrays
  initialisiert. Eine Notiz im `notes`-Feld weist die User darauf hin,
  beide Komponenten manuell nachzupflegen, da die alten Daten sie
  nicht enthalten haben.
- `advance_payments` und `bonuses` bleiben unverändert.

Schema-Marker in `data/meta.json` springt von `1.0.0` auf `1.0.3`.

### Added — UI

- **Wasser-Saldo-Karte** zeigt unterhalb der 4 Hauptspalten eine
  Komponenten-Zeile mit drei Kacheln (Trinkwasser, Schmutzwasser,
  Niederschlagswasser), jeweils mit aktuellem Tarif, kumuliertem
  Verbrauchsanteil und Grundpreis-Aufschlüsselung.
- **Wasser-Vertragsdialog** (`openWaterContractModal`) mit drei
  ein-/ausklappbaren Komponenten-Sektionen plus Abschläge und Boni.
  Schmutzwasser-Basis ist umschaltbar (Trinkwasser-Verbrauch /
  separater Zähler).
- **Vertragstabelle** für Wasser zeigt die Anzahl der Stichtage pro
  Komponente statt der Standard-Spalten.

### Backend-Änderungen

- `ContractService::normalizeWater()` — strikte F4-Validierung der
  drei Komponenten-Blöcke. Halb-ausgefüllte Niederschlagswasser-Zeilen
  (z.B. Fläche fehlt, Tarif vorhanden) werden mit HTTP 400 abgelehnt.
- `ConsumptionService::applyWaterContracts()` — neue Pro-Monat-Berechnung
  mit drei Komponenten und Aufschlüsselung in `monthly[].trinkwasser`,
  `.schmutzwasser`, `.niederschlagswasser`.
- `ConsumptionService::contractStatus()` für Wasser ergänzt um
  `components.{trinkwasser, schmutzwasser, niederschlagswasser}` mit
  Pro-Komponenten-Summen und aktuellen Tarifwerten.

### Migration

Update von v1.0.2 → v1.0.3 ist beim ersten App-Start automatisch.
Bestehende Backups (Format `backup_version: "3.0"`) bleiben kompatibel,
weil sie unter `utilities.wasser.contracts` die alte Struktur
enthielten — beim Import wird sie wie laufende v1.0.2-Daten erkannt und
in die neue Struktur überführt.

[1.0.3]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.3

---

## [1.0.2] — 2026-05-11 — Initial public release

Erste öffentliche Version des Energietrackers. Selbst-gehostete Web-App
zum Erfassen und Analysieren des privaten Energie- und Wasserverbrauchs.
Single-File-PHP-Backend, Vanilla-JS-SPA-Frontend, flat-file JSON-Persistenz.

Eine private Vorgängerversion existierte unter dem Namen *Energietracker
v0.9.0* (Backup-Format `version: "2.1"`). Backups aus dieser Version
können mit dem eingebauten Migrator importiert werden — siehe
[`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md). Außerhalb
dieses Migrationspfads ist v0.9.0 für die öffentliche Codebase irrelevant.

### Funktionen

- **Drei Verbrauchsarten parallel**: Gas, Strom, Wasser. Pro Utility
  eigene Zähler, Verträge, Tarifhistorie und Berechnungslogik.
- **Mehrere Zähler pro Utility**, jeder mit unabhängiger Vertragsserie
  (z.B. Hauptzähler + Gartenwasser-Zwischenzähler).
- **Zählertausch** als erstklassiges Datenmodell: ein Zähler bündelt
  beliebig viele Geräte (Devices) mit Seriennummer, Einbaudatum,
  Anfangs-/Endzähler, Begründung. Verbrauch wird über Tausch-Grenzen
  hinweg korrekt überbrückt.
- **Vertragshistorie** mit stichtag-genauen Arbeits- und Grundpreisen,
  monatlichen Abschlägen und Boni. Mehrere Stichtage pro Vertrag werden
  als forward-fill auf die Monatszeilen angewandt.
- **Saldo-Berechnung pro Vertrag**: aktueller Stand (Kosten −
  Abschläge) und erwarteter End-Saldo (extrapoliert über die
  verbleibenden Monate). Verdict: Erstattung, Nachzahlung, Ausgeglichen.
- **Heizgradtage** (HGT) je Monat gegen die Basistemperatur, mit
  Open-Meteo-Sync und CSV-Import für historische Tagestemperaturen
  (Format `DD.MM.YYYY"avg"min"max`, double-quote-getrennt).
- **Vier Regressionsmodelle** für HGT vs. Verbrauch: linear,
  polynomial Grad 2, robust (Huber), segmentiert
  (Heiz-/Sommerlast bei HGT = 50 als Default-Schwelle).
- **12-Monats-Forecast** als R²-gewichtete Mischung aus Regressions-
  modell und Saisonprofil.
- **Anomalie-Erkennung**: Monate mit > 2σ Abweichung vom
  Modell-Erwartungswert.
- **Backup & Restore** über die UI (Format `backup_version: "3.0"`),
  inklusive automatischer Sicherheits-Snapshots im Datenverzeichnis.
- **Migration aus v0.9.0**: ein altes Backup-Format (`version: "2.1"`)
  kann zweistufig importiert werden — erst Preview mit Übersicht, dann
  *Ersetzen* oder *Zusammenführen* (Konflikt-Resolution per ID).

### Technische Architektur

- **Backend (PHP ≥ 8.4)**: 13 Services + 11 Controllers, organisiert
  in einem kleinen App-Container (`src/bootstrap.php`). Speicher-Layer
  via `JsonStore` mit `LOCK_EX`-Schreibverbindung. Routing über einen
  kompakten Hand-rolled Router (`src/Http/Router.php`).
- **Frontend (Vanilla JS, ES Modules)**: 8 Views + 3 Komponenten + 1
  Hash-Router, 1 API-Fetch-Wrapper, 1 Lightweight-State-Cache. Kein
  Build-Step, keine Node-Toolchain nötig zur Auslieferung.
- **Daten**: flache JSON-Dateien unter `data/`. Schema-Version
  in `data/meta.json`. Atomic-write-Pattern überall.
- **Visualisierung**: Chart.js 4 per CDN-Import in `index.php`.
- **Typografie**: DM Mono (Zahlen, Datumsangaben) und DM Sans
  (Prosa) per Google Fonts.

### Datenmodell (siehe README → Datenmodell für Details)

- **Reading**: `{id, meter_id, device_id, date, counter, price_cents?,
  note, is_estimated, is_future}`
- **Meter**: `{id, name, icon, created_at, active, notes, devices: [...]}`
- **Device**: `{id, serial, installed_on, initial_counter, removed_on,
  final_counter, reason}`
- **Contract**: `{id, meter_id, provider, tariff_name, start, end, notes,
  working_prices: [{from, ct_per_kwh}], base_prices, advance_payments, bonuses}`

### Settings-Inventar (20 Schlüssel)

```
gas_conversion_factor (kWh/m³)     hdd_base_temp (°C)
co2_gas (g/kWh)                    co2_strom (g/kWh)
co2_wasser (g/m³)                  min_days_period
min_hdd_regression                 blend_max
forecast_months                    min_temp_days_forecast
forecast_model                     dashboard_months
alert_days_since_reading           anomaly_threshold
location_name                      latitude
longitude                          weather_auto_fill
wasser_personen_anzahl             wasser_personen_referenz
```

### Bekannte Einschränkungen

- HGT-Anwendung nur für Gas und Strom relevant; bei Wasser fällt der
  Forecast auf 100 % Saisonprofil zurück.
- Open-Meteo-Sync benötigt `curl` als PHP-Extension.
- Keine Mehrbenutzer- oder Auth-Schicht — Single-Tenant per Design.

[1.0.2]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.2

---

## Roadmap

Nicht terminiert — Reihenfolge und Umfang können sich ändern.

### Smart-Meter-Anbindung (Aufbau auf F-06)

F-06 hat den Ablesungs-Import in zwei Schichten getrennt: die
CSV-Parsing-Schicht und den quell-agnostischen Kern
`ReadingImportService::importRows()`. Damit ist **Variante 1** (gekapselte,
wiederverwendbare Importlogik) bereits umgesetzt.

Offen ist **Variante 2** — ein unbeaufsichtigter Endpoint, über den ein
Smart-Meter-Gateway oder ein Heimautomatisierungs-System Ablesungen ohne
UI-Interaktion einliefert. Dafür nötig:

- Ein Token-geschützter Endpoint (z.B.
  `POST /api/ingest/{utility}/{meter}` mit `Authorization: Bearer …`),
  da die übrige App bewusst auth-frei und Single-Tenant ist.
- Token-Verwaltung (Erzeugen/Widerrufen) in den Einstellungen.
- `importRows()` ist bereits der passende Aufsetzpunkt — der neue
  Endpoint muss nur Auth + Payload-Parsing ergänzen, die Schreib- und
  Überschreiblogik bleibt unverändert.

### Weitere Verbrauchsarten

- Heizöl und Pellets als zusätzliche Utilities (`Config/Utilities.php`
  ist die Single Source of Truth — additiv erweiterbar, ähnlich wie
  Wasser in v1.0.0).

### Internationalisierung

- Englische UI-Sprache. Aktuell sind Labels und Meldungen
  durchgängig deutsch verdrahtet.
