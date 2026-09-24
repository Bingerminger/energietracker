# Architektur

**Deutsch** · [English](../en/technical/02-architecture.md)

[← Installation](01-installation.md) · [Kompendium-Index](../README.md)

Energietracker folgt einer klaren Schichtentrennung. Kernprinzip:
**dependency-frei, flat-file, ein Einstiegspunkt pro Verantwortung.**

---

## 1. Gesamtbild

```text
                  Browser (SPA, ES-Module)
                          |  fetch /api/...
                          v
  index.php  - liefert SPA-Huelle (HTML, laedt public/js/app.js)
  api.php    - 20-Zeilen-Einstiegspunkt -> Router
                          |
                          v
  +-----------------------------------------------------------+
  |  Controllers (26)        |  Services (31)                  |
  |  HTTP rein / raus        |  Fachlogik, kein HTTP           |
  +-----------------------------------------------------------+
                          |
                          v
  Storage  - JsonStore (atomar, Schreibsperre) + Migrator
                          |
                          v
  data/    - flache JSON-Dateien je Verbrauchsart
```

Es gibt **keine** Datenbank. Persistenz ist eine Menge von JSON-Dateien
unter `data/`, geschrieben mit `LOCK_EX` (exklusiver Lock), damit
parallele Requests sich nicht zerstören. Schema-Stand: **1.5.0**.

---

## 2. Verzeichnislayout

```text
energietracker/
├── api.php                 # API-Einstiegspunkt (~20 Zeilen)
├── index.php               # SPA-Shell (HTML, Favicon, Theme-Anti-Flash)
├── VERSION                 # einzige Quelle der Versionsnummer
├── public/
│   ├── css/                # tokens, app, components
│   ├── img/                # App-Icon (hell/dunkel), Favicon
│   └── js/
│       ├── app.js          # Frontend-Einstiegspunkt
│       ├── router.js       # Hash-Router
│       ├── api.js          # fetch-Wrapper (BASE = 'api.php')
│       ├── state.js        # Utilities-/Settings-Cache
│       ├── lib/            # sidebar, theme, format
│       ├── components/     # chart, modal, toast
│       └── views/          # 12 Ansichten (s. UI-Referenz)
├── src/
│   ├── bootstrap.php       # DI-Container + Routen-Tabelle
│   ├── Config/Utilities.php# Verbrauchsarten — single source of truth
│   ├── Http/               # Router, Request, Response, ErrorHandler, CrossSiteGuard
│   ├── Storage/            # JsonStore, Migrator, WriteLock
│   ├── Support/            # Dates, Encoding
│   ├── Services/ (31)      # Fachlogik (+ Pdf/PdfWriter)
│   └── Controllers/ (26)   # je Klasse eine Datei (PSR-1)
├── data/                   # Laufzeitdaten (nicht im VCS)
├── demo-data/              # vollständiger Beispieldatensatz (8 Arten)
├── docs/                   # dieses Kompendium
├── tests/                  # Test-Harnesses
└── scripts/init_data.py    # optionaler Excel-Import
```

---

## 3. Konfiguration der Verbrauchsarten

`src/Config/Utilities.php` ist die **einzige Wahrheitsquelle** für alle
Verbrauchsarten. Jede Art definiert u. a.:

| Feld | Bedeutung |
|---|---|
| `key`, `label`, `icon`, `color` | Identität und Darstellung |
| `consumption_unit` | Abrechnungseinheit (`kWh` oder `m³`) |
| `reading_kind` | `cumulative` (Zählerstände) **oder** `delivery` (Lieferungen) |
| `volume_unit` | Eingabeeinheit für Lieferungen (`L` Heizöl, `kg` Pellets) |
| `conversion_setting` | Settings-Schlüssel für die kWh-Umrechnung |
| `hgt_relevant` | ob Heizgradtage in Regression/Prognose einfließen |

Daraus ergeben sich zwei Berechnungspfade (siehe
[Datenmodell](04-data-model.md) und
[Grundlagen](../functional/00-overview.md)):

- **kumulativ** (Gas, Strom, Wasser, Fernwärme): Verbrauch =
  Differenz aufeinanderfolgender Zählerstände, linear über die Tage
  interpoliert.
- **lieferbasiert** (Heizöl, Pellets): Verbrauch wird energetisch aus
  Anfangsbestand + Lieferungen bilanziert und HGT-gewichtet auf die
  Monate verteilt; eine separate, kalibrierte Methode liefert die
  Tank-Bestandskurve.

---

## 4. Services (`src/Services/`, 31 + `Pdf\PdfWriter`)

Jeder Service ist `final`, hat einen dependency-injizierten Konstruktor
und kennt **kein HTTP**.

| Service | Verantwortung |
|---|---|
| `SettingsService` | Settings lesen/mergen, Typ-Casts; Defaults in `DEFAULTS`; je Datenstand zwischengespeichert (v2.6.0) |
| `ConversionFactorService` | datierte Gas-Faktoren (F1012), tagesgenau |
| `I18nService` | Kataloge, `t()`, Sprache aus Einstellung bzw. `Accept-Language`; ordnet Meldungen ihrem Fehlercode zu (v2.6.0) |
| `MeterService` | CRUD Zähler/Tanks, Gerätetausch, Topologie (Subzähler/Gruppen, F1006) + `external_id`-Alias (F1009) |
| `ReadingService` | CRUD Ablesungen, Auto-Zuordnung zum aktiven Device; Erfassungsübersicht mit typischem Tagesverbrauch; Sammel-Upsert für den CSV-Import (v2.6.0) |
| `ContractService` | CRUD Verträge, strikte Validierung, Stichtag-Lookup |
| `ConsumptionService` | Monatsaggregation (kumulativ **und** lieferbasiert), Saldo, Wetterbereinigung; delegiert die Liefer-Tagesverteilung an `DeliveryConsumptionService`; seit v2.6.0 Plausibilität (Ausreißer, Verdacht, Überlauf) mit `warnings` |
| `DeliveryConsumptionService` | **(seit v1.4.4)** Tages-Verbrauchsverteilung & Tank-Bestandsabzug für Heizöl/Pellets — aus `ConsumptionService` extrahiert (~350 Zeilen) |
| `DeliveryService` | CRUD Lieferungen, Tank-Bestandskurve |
| `TemperatureService` | CSV-Import, Tages-Map |
| `WeatherService` | Open-Meteo-Wrapper (Archiv + Vorhersage) |
| `RegressionService` | 5 Modelle: linear, polynomial, robust, segmented (auto/fix), sigmoid |
| `ForecastService` | R²-gewichtete Mischung Regression × Saisonprofil; vertragsbasierte Kostenprognose |
| `AnomalyService` | Z-Score-Ausreißer |
| `BenchmarkService` | Effizienzklasse **pro Heizquelle** + kombiniert |
| `TariffComparisonService` | echte + Schattenverträge auf Ist-Verbrauch |
| `TariffSwitchService` | Wechselentscheidung ab Wechseltermin (Bindungskette, Break-even) |
| `RecommendationService` | 7 statistische Regelfamilien, Dismiss-State |
| `ReminderService` | Termine/Wartung, Recurrence-Fortschreibung |
| `PdfReportService` + `Pdf\PdfWriter` | Jahresbericht, eigener PDF-Generator |
| `BackupService` | Export/Import Format 3.0 mit Prüfung vor dem Schreiben und Rückweg; Snapshots (Liste, Download, Einspielen, Rotation) |
| `MigrationService` | v0.9.0-Import (Preview + Apply) |
| `ReadingImportService` | CSV-Bulk-Import von Ablesungen |
| `CsvExportService` | tabellarischer Export (inkl. Lieferungen) |
| `DiagnosticsService` | Systemstatus, Schreibrechte, Datenzählung |
| `HealthCheckService` | `/api/health`: `status` ok/degraded/error, Prüfungen (Schreibrechte, Schema, Dateien, Platz, Temp-Dateien), letzter Ingest — N1003, v2.6.0 |
| `DemoService` | Ein-Klick-Demo-Import über den Restore-Pfad — F1007 |
| `PvSummaryService` / `StromSaldoService` | PV-Eigenverbrauch/Autarkie bzw. Strom-Saldo — F1005 |
| `AuthService` | Anmeldung (Passwort, Proxy, Sitzungen, Sperre), API-Schlüssel und HA-Token — nur Hashes in `data/auth.json` (F1009, v2.6.0) |
| `IngestService` | idempotenter Push-Eingang (`/api/ingest`, upsert-by-date) — F1009 |

---

## 5. Controllers (`src/Controllers/`, 26)

Jeder Controller ist `final`, eine Klasse pro Datei. Methoden geben
`never` zurück und antworten direkt über `Response::json()` /
`Response::csv()` / `Response::error()`.

`UtilitiesController`, `SettingsController`, `TemperatureController`,
`MeterController`, `ReadingController`, `ContractController`,
`ConsumptionController`, `ForecastController`, `DeliveryController`,
`BenchmarkController`, `TariffComparisonController`,
`TariffSwitchController`, `RecommendationController`, `ReminderController`, `ReportController`,
`ExportController`, `BackupController`, `MigrationController`,
`DiagnosticsController`, `HealthController`, `DemoController`,
`PvSummaryController`, `StromSaldoController`, `AuthController`,
`IngestController`, `SessionController`.

*(Hinweis: Gruppen-Endpoints aus F1006 liegen im `MeterController`,
Auth/Ingest aus F1009 in `AuthController`/`IngestController`, Anmeldung und
API-Schlüssel (v2.6.0) im `SessionController`.)*

**Anmelde-Schranke (v2.6.0).** Vor dem Routing prüft `App` in
`bootstrap.php` Hostnamen (`ET_ALLOWED_HOSTS`), fremde Browser-Anfragen
(`CrossSiteGuard`) und — bei eingeschalteter Anmeldung — Sitzung,
API-Schlüssel oder Proxy-Benutzer. Öffentlich bleiben Ingest, Health (in
Minimalform) und die Anmeldung selbst. Details:
[Sicherheit](08-security.md).

Die vollständige Routen-Liste steht in der
[API-Referenz](03-api-reference.md).

---

## 6. Fehlerbehandlung

`Http/ErrorHandler` mappt Ausnahmen einheitlich:

| Exception | HTTP | Bedeutung |
|---|---|---|
| `InvalidArgumentException` | 400 | ungültige Eingabe |
| `Http\NotFoundException` | 404 | Ressource fehlt (seit v2.2.1 als Typ statt Textmuster; seit v2.6.0 auch für Datensätze in der URL) |
| `Http\ConflictException` | 409 | Konflikt, z. B. Sicherungs-Snapshot gescheitert (v2.6.0) |
| `Storage\StorageCorruptedException` | 503 | Datendatei beschädigt (v2.5.3) |
| sonstige | 500 | unerwarteter Fehler — generische Meldung mit `error_id`, Einzelheiten im Log (v2.6.0) |

Antwort-Hülle einheitlich:
`{ "success": true, "data": … }` oder
`{ "success": false, "error": "…", "code": "errors.…" }`. `code` ist der
Katalogschlüssel der Meldung (`I18nService::errorCodeFor()`), sonst
`errors.http.*` nach Statuscode; `detail` mit Datei/Zeile nur bei
`ET_DEBUG=1`. Der Router beantwortet `HEAD` wie `GET`, eine falsche Methode
mit `405` und `Allow`, `OPTIONS` mit `204`.

### 6.1 Speicher-Pfad-Sicherheit (seit v1.4.4)

`JsonStore::path()` baut den Dateipfad aus `rootDir` plus relativem
Schlüssel. Zusätzlich zur Whitelist-Prüfung im Service-Layer
(`Utilities::exists()` lässt nur bekannte Energieart-Schlüssel zu) prüft
`path()` per `realpath` + Präfix-Vergleich, dass der aufgelöste Pfad
**innerhalb** von `rootDir` liegt. Jeder Versuch, über `../` aus dem
Datenverzeichnis auszubrechen, wirft eine `InvalidArgumentException`
(→ HTTP 400). Defense-in-Depth: der Schutz greift auch dann, wenn ein
künftiger Endpunkt die Service-Layer-Validierung umgehen sollte.

---

## 7. Frontend

Reine ES-Module, **kein Build-Schritt**. `app.js` ist der Einstieg:
Theme-Toggle binden → `buildSidebar()` (dynamisch aus aktiven
Verbrauchsarten) → Utilities-Cache wärmen → Hash-Router starten.

> ⚠️ **Architektur-kritisch:** Da alle Module über einen einzigen
> ES-Modulgraphen geladen werden, bricht **ein einziger fehlerhafter
> relativer Import** (404) die *gesamte* App — die Oberfläche bleibt bei
> „Lade…" stehen. Genau das war Bug v1.4.1 (`sidebar.js` importierte
> `./state.js` statt `../state.js`). Der Browser-Render-Test
> (`tests/browser-render.test.mjs`) crawlt seit v1.4.1 den kompletten
> Modulgraphen über HTTP und fängt solche Fehler. Siehe
> [Tests](05-testing.md).

---

[← Installation](01-installation.md) ·
[API-Referenz →](03-api-reference.md)
