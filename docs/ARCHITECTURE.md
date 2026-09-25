# Architektur (Kurzfassung)

**Deutsch** · [English](en/ARCHITECTURE.md)

> **Hinweis:** Die kanonische, bei jedem Release gepflegte
> Architektur-Dokumentation ist das Kompendium unter
> [`docs/technical/02-architecture.md`](technical/02-architecture.md).
> Dieses ältere Top-Level-Dokument wird nur noch faktisch
> nachgeführt (Stand: v1.4.4) und bietet eine kompakte Übersicht.

Aufbau, Datenfluss und Kernalgorithmen des Energietrackers.

---

## 1. Module-Karte

```
                          ┌─────────────────────────────────┐
                          │  api.php  (20 Zeilen Entry)     │
                          └─────────────┬───────────────────┘
                                        │
                          ┌─────────────▼───────────────────┐
                          │  src/bootstrap.php — App-       │
                          │  Container, Service-Wiring,     │
                          │  Routing                        │
                          └──┬───────────────┬──────────────┘
                             │               │
              ┌──────────────▼──────┐  ┌─────▼──────────────────┐
              │  Controllers        │  │   Services             │
              │  - 1 Klasse / Datei │  │   - Reine Domain-      │
              │  - dünner Adapter   │  │     Logik              │
              │  - keine Logik      │  │   - kein HTTP-Wissen   │
              └─────────────┬───────┘  └──────────┬─────────────┘
                            │                     │
                            │       ┌─────────────▼─────────┐
                            │       │  Storage / JsonStore  │
                            │       │  LOCK_EX writes       │
                            │       └────────┬──────────────┘
                            │                │
                            │       ┌────────▼──────────────┐
                            │       │   data/*.json         │
                            │       │   (flat files)        │
                            │       └───────────────────────┘
                            │
              ┌─────────────▼───────────────────────────────┐
              │   public/js/  (Vanilla-JS SPA)              │
              │   ┌─────────────────────────────────────┐   │
              │   │  app.js → router.js → views/*.js    │   │
              │   │              ↑                      │   │
              │   │    components/  ←  lib/format.js    │   │
              │   └─────────────────────────────────────┘   │
              └─────────────────────────────────────────────┘
```

---

## 2. Backend-Schichten

### 2.1 HTTP-Layer (`src/Http/`)

- **`Router.php`** — pattern-basierte Routes (`{utility}` / `{id}`).
  Strippt `SCRIPT_NAME` und kollabiert mehrfache Slashes auf einen
  einzelnen, bevor das Matching anfängt.
- **`Request.php`** — Path-Param, Query-Param, JSON-Body-Decoding.
- **`Response.php`** — JSON-Encoding, einheitliche Antwort-Hülle
  (`{success, data}` oder `{success, error, detail}`).
- **`ErrorHandler.php`** — `set_exception_handler`, mappt:
  - `InvalidArgumentException` → HTTP 400
  - `RuntimeException` mit „nicht gefunden" → HTTP 404
  - Sonstiges → HTTP 500

### 2.2 Storage-Layer (`src/Storage/`)

- **`JsonStore.php`** — Lese- und Schreibwrapper für `data/`. Schreibt
  mit `LOCK_EX` und `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
  JSON_UNESCAPED_SLASHES`. Lese-Methoden geben `[]`-Default zurück,
  wenn die Datei fehlt.
- **`Migrator.php`** — Bootstrap-Logik für ein frisches
  Datenverzeichnis (`initFresh()`) und automatische Schema-Migration
  bei künftigen v1.0.x-Updates (Hook-Punkt für Datenmodell-Sprünge).

### 2.3 Config (`src/Config/`)

**`Utilities.php`** — Single source of truth für die drei
Verbrauchsarten. Liefert pro Key (`gas`, `strom`, `wasser`):

| Feld | Bedeutung |
|---|---|
| `label` | Anzeigename (z.B. „Gas") |
| `icon` | Emoji (🔥/⚡/💧) |
| `unit` | Zählerstand-Einheit (m³ für Gas/Wasser, kWh für Strom) |
| `consumption_unit` | Konsumeinheit zur Berechnung (kWh für Gas/Strom, m³ für Wasser) |
| `unit_to_kwh` | bool — Zählerstand muss zur Konsumeinheit umgerechnet werden |
| `conversion_setting` | Settings-Key für den skalaren Umrechnungsfaktor (Heizöl/Pellets); Gas: `null`, seit v2.5.0 datierte Liste `gas_conversion_factors` |
| `hgt_relevant` | bool — Verbrauch reagiert auf Heizgradtage |
| `color` | Hex-Farbe für die UI |
| `co2_setting` | Settings-Key für den CO₂-Faktor |
| `default_meter_name` | Default-Name beim Anlegen eines Zählers |
| `allow_multiple_meters` | bool — F3: mehrere Zähler erlauben |

### 2.4 Services (`src/Services/`)

Jeder Service ist `final`, hat einen Dependency-injizierten Konstruktor
und kennt kein HTTP. Aufruf-Sicht von außen geht über die Controller.

| Service | Verantwortung |
|---|---|
| `SettingsService` | Read/merge der Settings-Datei, type-cast für numerische Werte |
| `MeterService` | CRUD von Metern + F2 (Device-Replace); `countsInTotals()`/`inService()` legen fest, was „inaktiv" heißt (v2.9.0) |
| `ReadingService` | CRUD von Readings, Auto-Zuweisung von `device_id` zum aktiven Device |
| `ContractService` | CRUD von Verträgen, F4-strikte Validierung, `valueValidOn(...)`/`valueOnDate(...)` für Stichtag-Lookup, `bonusForMonth(...)`; seit v2.9.0 `segmentsBetween(...)` (tagesgenaue Abschnitte), `resolveForDate(...)` (weiterlaufender Vertrag) und `switchTiming(...)` (Kündigungsstichtag) |
| `ConsumptionService` | Monatsaggregation, F2-Device-Bridging, F3-Multi-Meter-Aggregation, **`contractStatus()`** für Saldo-Karte; F-03 Abrechnungszyklus-Projektion offener und weiterlaufender Verträge, F-05 Erinnerung am Kündigungsstichtag (v2.9.0), Schmutzwasser-`separater_zaehler`-Auflösung mit Rekursionssperre |
| `DeliveryConsumptionService` | **(v1.4.4)** Heizöl/Pellets — aus `ConsumptionService` extrahiert; seit v2.10.0 `tankModel()` (Tankbuch): eine Rechnung für Verbrauch, Kosten (gleitender Durchschnittspreis) und Bestand, Stützstellen `initial_stock`/`fill_to_full`/`tank_levels` |
| `TemperatureService` | CSV-Import, Open-Meteo-Abgleich mit Quelle je Tag (`archive`/`forecast`/`csv`/`manual`), täglicher Auto-Sync (v2.8.0) |
| `WeatherService` | Open-Meteo-API-Wrapper (Archiv, Vorhersage, 30-Jahres-Tagesmittel) hinter dem Interface `WeatherSource` |
| `ClimateNormalService` | **(v2.8.0)** Klimanormal am Standort: HGT-Mittel und -Streuung je Kalendermonat aus 30 Jahren, gespeichert in `climate_normal.json` |
| `RegressionService` | Fünf Modelle (linear, polynomial, robust, segmented, sigmoid) + `fit()` Dispatcher + `predict()` |
| `ForecastService` | R²-gewichtete Mischung aus Regression und Saisonprofil über 12 Monate, HGT aus dem Klimanormal, Unsicherheitsband (v2.8.0); F-02 vertragsbasierte Kostenprognose (`projectMonthFinances()`) mit projiziertem Abschlag und laufendem Saldo |
| `AnomalyService` | Ausreißer gegen die Erwartung je Monat (Heizmodell bzw. derselbe Kalendermonat anderer Jahre), robuste Streuung mit Untergrenze (v2.8.0) |
| `BackupService` | Export/Import im 3.0-Format, Snapshot-Erzeugung |
| `MigrationService` | v0.9.0 → aktuelles Schema, Preview + Apply mit Replace/Merge-Modus |
| `ReadingImportService` | CSV-Bulk-Import von Ablesungen (F-06); quell-agnostischer Kern `importRows()`, überschreibt vorhandene Ablesungen am selben Datum |
| `CsvExportService` | Tabellarischer CSV-Export (F-07) für Monatsübersicht, Zählerstände, Temperaturreihe |
| `DiagnosticsService` | System-Status, totals pro Utility |

### 2.5 Controllers (`src/Controllers/`)

Jeder Controller ist `final`, eine Klasse pro Datei. Methoden geben
`never` zurück, weil sie via `Response::json()`/`Response::error()`
direkt antworten (terminieren mit `exit`).

| Controller | Routes |
|---|---|
| `UtilitiesController`      | `GET /api/utilities` |
| `SettingsController`       | `GET/PATCH /api/settings` |
| `TemperatureController`    | `GET/POST/DELETE /api/temperatures*` |
| `MeterController`          | `GET/POST/PATCH/DELETE /api/utility/{u}/meters*` + replace-device |
| `ReadingController`        | `GET/POST/PATCH/DELETE /api/utility/{u}/readings*`, `POST .../meters/{id}/readings/import-csv` (F-06) |
| `ContractController`       | `GET/POST/PATCH/DELETE /api/utility/{u}/contracts*` |
| `ConsumptionController`    | `GET /api/utility/{u}/consumption`, `GET .../meters/{id}/consumption`, `GET .../meters/{id}/contract-status` |
| `ForecastController`       | `GET /api/utility/{u}/meters/{id}/forecast` |
| `BackupController`         | `GET /api/backup/export`, `POST /api/backup/import`, `POST /api/backup/snapshot` |
| `ExportController`         | `GET /api/export/{u}/monthly.csv`, `GET /api/export/{u}/readings.csv`, `GET /api/export/temperatures.csv` (F-07) |
| `MigrationController`      | `POST /api/migration/v09/preview`, `POST /api/migration/v09/import` |
| `DiagnosticsController`    | `GET /api/diagnostics` |

---

## 3. Frontend-Struktur

```
public/
├── css/
│   ├── tokens.css       ← Farben, Spacing, Font-Stack als CSS-Variablen
│   ├── app.css          ← Layout-Grid (Sidebar + Topbar + View)
│   └── components.css   ← Wiederverwendbare Patterns
└── js/
    ├── app.js           ← Entry: startet Router, Tooltips, Toast-Stack
    ├── router.js        ← Hash-Router; matched gegen `data-route`
    ├── api.js           ← Fetch-Wrapper mit Antwort-Hüllen-Entpackung
    ├── state.js         ← Lightweight-Cache (`getUtilities()`,
    │                      `getSettings()`, `invalidateSettings()`)
    ├── lib/
    │   └── format.js    ← de-DE-Formatter (Zahl, EUR, Datum, Monat)
    ├── components/
    │   ├── chart.js     ← Chart.js-Wrapper mit Dark-Theme-Defaults
    │   ├── modal.js     ← Generic Modal + Confirm-Dialog
    │   └── toast.js     ← Stack rechts unten, OK/Warning/Error
    └── views/
        ├── dashboard.js     ← 12-Monats-KPIs + Charts
        ├── utility.js       ← Gas/Strom/Wasser (Saldo, Verträge, Monatstabelle, …)
        ├── meters.js        ← Zähler + Devices CRUD inkl. F2 Tausch-UI
        ├── contracts.js     ← Vertrags-Editor mit F4-Validierung
        ├── temperatures.js  ← CSV-Import + Open-Meteo-Sync + Monatschart
        ├── analysis.js      ← HGT-Scatter mit 4 Regressionsmodellen + Jahresvergleich
        ├── forecast.js      ← 12-Monats-Forecast pro Utility
        └── settings.js      ← Settings-Form, Backup/Restore, Migration aus v0.9.0
```

---

## 4. Datenfluss: Reading → Monatsverbrauch → Saldo

### Schritt 1 — Reading-Sammlung

`ConsumptionService::forMeter($utility, $meter)` startet mit allen
Readings des Meters, filtert solche mit `is_future: true` oder Datum
in der Zukunft heraus, und sortiert chronologisch. Mindestens zwei
Readings müssen vorliegen — sonst leeres Ergebnis.

### Schritt 2 — Intervall-zu-Monats-Verteilung

Für jedes Paar konsekutiver Readings `(prev, curr)`:

1. Tage = `(curr.date − prev.date).days`
2. Counter-Delta wird via `consumptionBetween()` berechnet — bei
   Device-Wechsel zwischen `prev` und `curr` wird das so überbrückt:
   ```
   consumption = (old_device.final_counter − prev.counter)
               + (curr.counter − new_device.initial_counter)
   ```
3. Counter-Delta × `unit_to_kwh`-Faktor (für Gas: 11.5 kWh/m³ als Default).
4. **Lineare Verteilung auf die Monate**: täglich werden die kWh und
   die anteiligen Tage in den jeweiligen `YYYY-MM`-Bucket geschoben.

### Schritt 3 — Wetter-Anreicherung

Jeder Monat bekommt aus `temperatures.json` zugeordnet:

- `avg_temp` = Mittelwert aller Tagestemperaturen im Monat
- `min_temp` = Minimum
- `max_temp` = Maximum
- **`hdd`** (Heizgradtage) = Σ max(0, base − avg_day) über alle Tage,
  base = `settings.hdd_base_temp` (Default 15 °C)

### Schritt 4 — Utility-Felder

- `kwh_per_day = kwh / days`
- `m3 = kwh / unit_to_kwh_factor` (nur Gas)
- `co2_kg = kwh × Faktor / 1000` — Faktor über `SettingsService::co2Factor(Schlüssel, Jahr)`; Strom je Jahr aus `co2_strom_years` (seit v2.10.0)

### Schritt 5 — Contract-Application (seit v2.9.0 tagesgenau)

`ContractService::segmentsBetween(...)` teilt jeden Monat an jedem
Vertragsbeginn und -ende und an jedem Stichtag der Arbeitspreise,
Grundpreise und Abschläge. Welcher Vertrag an einem Tag gilt, entscheidet
`resolveForDate(...)`: bei Überlappung der spätere Beginn; nach einem
Vertragsende ohne Nachfolger der zuletzt beendete Vertrag als Annahme
(`assumed`), solange er nicht als gekündigt markiert ist
(`auto_renews: false`). Je Abschnitt:

```
Arbeitspreis = Verbrauch der Abschnittstage (aus Schritt 2)
               × Preis am Abschnittstag (valueOnDate; nach dem Ende
                 der Preis am letzten Vertragstag, priceDate)
Grundpreis   = Monatsbetrag × Abschnittstage / Monatstage
Abschlag     = Monatsbetrag am ersten Vertragstag des Monats
               × Vertragstage / Monatstage
Bonus        = je Vertrag; nicht für angenommene Abschnitte nach dem Ende
```

Die Monatszeile:

- `contract_id` = Vertrag mit den meisten Tagen, `contract_assumed`
- `working_price_ct` = mengengewichteter Arbeitspreis des Monats
- `kwh_cost`, `base_price_eur`, `advance_eur`, `bonus_eur` = Summen der
  Abschnitte
- `cost = kwh_cost + base_price_eur − bonus_eur`
- `monthly_balance = cost − advance_eur` (positiv = Unterzahlt)
- `cumulative_balance` = laufender Saldo pro Vertrag-ID
- `contract_parts[]` — nur bei mehr als einem Vertrag im Monat: je Vertrag
  `days`, `kwh`, `kwh_cost`, `base_price_eur`, `advance_eur`,
  `bonus_eur`, `cost`, `assumed`

Bis v2.8 galt der Vertrag vom Monatsersten für den ganzen Monat
(`findActiveForDate(Monatserster)`). **Wasser** rechnet weiterhin so: Das
Drei-Komponenten-Modell kennt keine Abschläge, und seine Tarife ändern sich
zum Jahreswechsel.

### Schritt 6 — Moving Averages

- `ma3` = 3-Monats-Mittel über `kwh` (für Wasser über `m3`)
- `ma6` = 6-Monats-Mittel
- `ma12` = 12-Monats-Mittel

### Schritt 7 — Vertragsaggregation (`contractStatus`)

Nach den Monatszeilen liefert `ConsumptionService::contractStatus()` pro
Contract-ID die Aggregation:

```
actual_kwh       = Σ kwh           (gemessene Monate; bei zwei Verträgen
actual_cost      = Σ cost           im Monat nur der Teil dieses Vertrags)
actual_kwh_cost  = Σ kwh_cost
actual_base_total  = Σ base_price_eur
actual_bonus_total = Σ bonus_eur
months_actual    = count(Monate)
```

Der **Saldo** rechnet seit v2.8.0 nach Kalender bis heute
(`balanceProjection`), wie die Jahresabrechnung:

```
cost_to_date          = energy_cost_to_date + base_to_date − bonus_to_date
advance_paid          = Abschläge nach Zahlungsplan bis einschließlich
                        des laufenden Monats
current_balance       = cost_to_date − advance_paid + special_payment_net
projected_end_balance = current_balance + estimated_cost_remaining
                        − advance_remaining
```

Der Verbrauch seit der letzten Ablesung wird geschätzt (Heizmodell bzw.
Saisonprofil). Offene und weiterlaufende Verträge (`renewed`, seit v2.9.0)
rechnen bis zum nächsten Abrechnungsstichtag. Dazu kommen seit v2.9.0 der
Kündigungsstichtag (`ContractService::switchTiming`: `cancel_by`,
`switch_date`, `notice_basis`), die Erinnerungsstufe relativ zu diesem Tag
(`remind_basis`), `cancel_missed` und die nächste eingetragene
Preiserhöhung (`price_increase`).

`verdict`-Schwelle:
- `Nachzahlung` wenn `projected > +5 €`
- `Erstattung` wenn `projected < −5 €`
- `Ausgeglichen` dazwischen

---

## 5. Forecast-Algorithmus

`ForecastService::forMeter($utility, $meter)` mischt zwei Quellen:

1. **Regressions-Komponente** — auf den vergangenen `(hdd, kwh)`-
   Paaren fittet das in den Settings konfigurierte Modell. Predict pro
   Forecast-Monat verwendet die HGT-Vorhersage aus dem Saisonprofil der
   letzten Jahre (Tages-Mittelwerte über den Kalendertag, aggregiert
   auf den Monat).
2. **Saison-Komponente** — Mittelwert des Verbrauchs in jedem
   Kalendermonat über alle vergangenen Jahre.

Die finale Vorhersage ist:

```
weight = min(blend_max, r2)   // settings.blend_max = 0.8 default
forecast_kwh = weight × regression_pred + (1 − weight) × seasonal_avg
```

Bei `hgt_relevant: false` (Wasser) wird `weight = 0` gesetzt — die
Vorhersage besteht ausschließlich aus dem Saisonprofil.

**Kostenprognose (F-02, seit v1.1.0).** Pro Prognosemonat entstehen
`cost_estimated` (Arbeitspreis × Menge + Grundpreis − bekannte Boni),
`advance_estimated` (der gültige Abschlag) und `balance_running`
(kumuliert Kosten − Abschlag). Seit v2.9.0 teilt `projectStandardMonth()`
den Monat wie die Ist-Rechnung an Vertrags- und Preisstichtagen
(`segmentsBetween`); die Menge verteilt sich nach Tagen auf die Abschnitte,
Grundpreis und Abschlag anteilig. Nach einem Vertragsende ohne Nachfolger
läuft der letzte Vertrag als Annahme weiter (`contract_assumed`). Wasser
löst den Vertrag weiterhin am Monatsersten auf (`resolveForDate`). Künftige
Boni werden nicht fortgeschrieben. Fehlt jeder Vertrag, greift
`last_price_ct` als Fallback-Arbeitspreis.

---

## 6. Settings-Inventar (Auswahl)

| Schlüssel | Typ | Default | Bedeutung |
|---|---|---|---|
| `gas_conversion_factors` | list | `[{from:null, kwh_per_m3:11.5}]` | kWh pro m³ Gas, datiert je Stichtag (seit v2.5.0; vorher Skalar `gas_conversion_factor`) |
| `hdd_base_temp` | float | 15 | HGT-Basistemperatur in °C |
| `co2_gas` | int | 182 | g CO₂ pro kWh Gas (Brennwert; BAFA 201 auf Heizwert × 0,906 — v2.10.0, vorher 201) |
| `co2_strom` | int | 380 | g CO₂ pro kWh Strom für Jahre vor dem ersten Jahreswert |
| `co2_strom_years` | map | UBA 2015–2025 | Strom je Jahr (v2.10.0) |
| `co2_wasser` | int | 350 | g CO₂ pro m³ Wasser |
| `min_days_period` | int | 20 | Mindest-Tage pro Ableseintervall (Sanity) |
| `min_hdd_regression` | float | 5 | Mindest-HGT pro Monat zur Berücksichtigung in der Regression |
| `blend_max` | float | 0.8 | Maximales Gewicht der Regressionskomponente im Forecast |
| `forecast_months` | int | 12 | Forecast-Horizont in Monaten |
| `min_temp_days_forecast` | int | 20 | **Veraltet (v2.9.0), ohne Wirkung**, entfällt mit v3.0.0 — ein Monat zählt, wenn für 90 % seiner Verbrauchstage Temperaturen vorliegen (seit v2.8.0) |
| `forecast_model` | string | `linear` | Default-Modell für den Forecast (`linear`/`polynomial`/`robust`/`segmented`/`sigmoid`) |
| `confidence_band_sigma` | float | 1.28 | Breite des Prognosebands in Standardabweichungen (1,28 ≈ 80 % der Jahre; bis v2.7 ohne Wirkung) |
| `dashboard_months` | int | 12 | Monate im Verbrauchsverlauf des Dashboards (3–36; seit v2.9.0 wirksam, vorher fest 12) |
| `alert_days_since_reading` | int | 45 | Status-Banner „Ablesung überfällig": Warnung ab ⅔ der Tage, Alarm danach (seit v2.9.0 wirksam, vorher fest 30/60) |
| `anomaly_threshold` | float | 2 | Schwelle (robuster z-Wert) für die Anomalie-Erkennung |
| `location_name` | string | "Leipzig Zentrum" | Anzeigename des Standorts |
| `latitude` | float | 51.3397 | Geo-Lat für Open-Meteo |
| `longitude` | float | 12.3731 | Geo-Lon für Open-Meteo |
| `weather_auto_fill` | bool | true | Temperaturen einmal am Tag beim Öffnen der App von Open-Meteo holen (seit v2.8.0 wirksam; übermittelt den gerundeten Standort) |
| `wasser_personen_anzahl` | int | 2 | Personen im Haushalt für Wasser-Referenz |
| `wasser_personen_referenz` | int | 127 | Referenz-Liter pro Person pro Tag |
| `billing_cycle_anchor_gas` | string | `01-01` | Abrechnungsstichtag Gas (`MM-TT`, seit v2.9.0 als echter Kalendertag geprüft, sonst 400); Saldo offener und weiterlaufender Verträge wird bis dorthin projiziert (F-03) |
| `billing_cycle_anchor_strom` | string | `01-01` | Abrechnungsstichtag Strom (F-03) |
| `billing_cycle_anchor_wasser` | string | `01-01` | Abrechnungsstichtag Wasser (F-03) |
| `billing_cycle_anchor_fernwaerme` | string | `01-01` | Abrechnungsstichtag Fernwärme (v1.3.0) |
| `billing_cycle_anchor_pv_einspeisung` | string | `01-01` | Abrechnungsstichtag der Einspeisevergütung (v2.13.0; bis dahin galt der Jahresbeginn fest) |
| `billing_cycle_anchor_heizoel`, `…_pellets` | string | `01-01` | **Veraltet (v2.13.0), ohne Wirkung** — keine Abschläge, kein Saldo; entfallen mit v3.0.0 |
| `contract_remind_days_1` | int | 90 | Erinnerung Stufe 1 — Tage vor dem Kündigungsstichtag, ohne Frist vor dem Vertragsende (F-05; Stichtag seit v2.9.0) |
| `contract_remind_days_2` | int | 30 | Erinnerung Stufe 2 (F-05) |
| `contract_remind_days_3` | int | 1 | Erinnerung Stufe 3 — dringend (F-05) |
| `wasser_sparindex_gut` | int | 100 | Wasser-Spar-Index: Werte ≤ gelten als unauffällig (F-10) |
| `wasser_sparindex_warnung` | int | 150 | Wasser-Spar-Index: Werte ≥ zeigen Sparpotenzial (F-10) |

Alle acht v1.1.0-Schlüssel sind additiv: der `SettingsService` merged sie
beim Lesen aus den Defaults, eine `settings.json` aus einer früheren
Version funktioniert ohne Migrationsschritt weiter. Das Speicherschema
(`Migrator::SCHEMA_VERSION`) bleibt unverändert bei `1.0.3`.

---

## 7. Daten-Initialisierung

Bei einem leeren `data/`-Verzeichnis (nur `.gitkeep` vorhanden) legt
`Storage\Migrator::initFresh()` beim ersten Request an:

- `meta.json` mit `schema_version`, `created_at`
- `settings.json` mit allen 20 Defaults
- `temperatures.json` als `{}`
- Pro Utility: `gas/`, `strom/`, `wasser/` mit leerem
  `meters.json`, `readings.json`, `contracts.json`

Es wird **kein** Default-Meter automatisch angelegt — der Anwender muss
über die UI explizit den ersten Zähler erzeugen.

---

## 8. Test- und Debug-Hinweise

### PHP-Built-in-Server für lokales Testen

```bash
php -S 127.0.0.1:8765
```

### Endpoint per curl

```bash
curl -s http://127.0.0.1:8765/api.php/api/diagnostics | jq
```

### Debug-Logs

`Response::error(...)` schreibt mit `error_log()` ins Standard-Error-
Log des PHP-Servers. Der ErrorHandler hängt bei `WP_DEBUG`-ähnlichem
Mode (siehe `bootstrap.php`) Stack-Traces an die JSON-Antwort.

### Demo-Daten zurücksetzen

```bash
find data/ -mindepth 1 -not -name '.gitkeep' -delete
mkdir -p data/gas data/strom data/wasser data/backups
cp -r demo-data/gas demo-data/strom demo-data/wasser data/
cp demo-data/meta.json demo-data/settings.json demo-data/temperatures.json data/
```
