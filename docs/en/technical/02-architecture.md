# Architecture

**English** · [Deutsch](../../technical/02-architecture.md)

[← Installation](01-installation.md) · [Compendium index](../README.md)

Energietracker follows a clear separation of layers. The core principle:
**dependency-free, flat-file, one entry point per responsibility.**

---

## 1. The big picture

```text
                  Browser (SPA, ES modules)
                          |  fetch /api/...
                          v
  index.php  - delivers the SPA shell (HTML, loads public/js/app.js)
  api.php    - 20-line entry point -> Router
                          |
                          v
  +-----------------------------------------------------------+
  |  Controllers (20)        |  Services (24)                  |
  |  HTTP in / out           |  domain logic, no HTTP          |
  +-----------------------------------------------------------+
                          |
                          v
  Storage  - JsonStore (LOCK_EX) + Migrator
                          |
                          v
  data/    - flat JSON files per utility
```

There is **no** database. Persistence is a set of JSON files under `data/`,
written with `LOCK_EX` (an exclusive lock) so that parallel requests do not
destroy one another. Schema level: **1.3.0**.

---

## 2. Directory layout

```text
energietracker/
├── api.php                 # API entry point (~20 lines)
├── index.php               # SPA shell (HTML, favicon, theme anti-flash)
├── VERSION                 # the single source of the version number
├── public/
│   ├── css/                # tokens, app, components
│   ├── img/                # app icon (light/dark), favicon
│   └── js/
│       ├── app.js          # frontend entry point
│       ├── router.js       # hash router
│       ├── api.js          # fetch wrapper (BASE = 'api.php')
│       ├── state.js        # utilities/settings cache
│       ├── lib/            # sidebar, theme, format
│       ├── components/     # chart, modal, toast
│       └── views/          # 12 views (see UI reference)
├── src/
│   ├── bootstrap.php       # DI container + route table
│   ├── Config/Utilities.php# utilities — single source of truth
│   ├── Http/               # Router, Request, Response, ErrorHandler
│   ├── Storage/            # JsonStore, Migrator
│   ├── Services/ (24)      # domain logic (+ Pdf/PdfWriter)
│   └── Controllers/ (20)   # one file per class (PSR-1)
├── data/                   # runtime data (not in VCS)
├── demo-data/              # complete example dataset (8 utilities)
├── docs/                   # this compendium
├── tests/                  # test harnesses
└── scripts/init_data.py    # optional Excel import
```

---

## 3. Configuration of the utilities

`src/Config/Utilities.php` is the **single source of truth** for all utilities.
Each utility defines, among other things:

| Field | Meaning |
|---|---|
| `key`, `label`, `icon`, `color` | identity and presentation |
| `consumption_unit` | billing unit (`kWh` or `m³`) |
| `reading_kind` | `cumulative` (meter readings) **or** `delivery` (deliveries) |
| `volume_unit` | input unit for deliveries (`L` heating oil, `kg` pellets) |
| `conversion_setting` | settings key for the kWh conversion |
| `hgt_relevant` | whether heating degree days enter the regression/forecast |

From this follow two calculation paths (see [data model](04-data-model.md) and
[fundamentals](../functional/00-overview.md)):

- **cumulative** (gas, electricity, water, district heating): consumption =
  difference of successive meter readings, linearly interpolated over the days.
- **delivery-based** (heating oil, pellets): consumption is energetically
  balanced from the initial stock + deliveries and distributed HDD-weighted over
  the months; a separate, calibrated method provides the tank stock curve.

---

## 4. Services (`src/Services/`, 24 + `Pdf\PdfWriter`)

Each service is `final`, has a dependency-injected constructor and knows **no
HTTP**.

| Service | Responsibility |
|---|---|
| `SettingsService` | read/merge settings, type casts; 40 keys |
| `MeterService` | CRUD meters/tanks, device swap, topology (submeters/groups, F1006) + `external_id` alias (F1009) |
| `ReadingService` | CRUD readings, auto-assignment to the active device |
| `ContractService` | CRUD contracts, strict validation, effective-date lookup |
| `ConsumptionService` | monthly aggregation (cumulative **and** delivery-based), balance, weather adjustment; delegates the delivery daily distribution to `DeliveryConsumptionService` |
| `DeliveryConsumptionService` | **(since v1.4.4)** daily consumption distribution & tank stock draw for heating oil/pellets — extracted from `ConsumptionService` (~350 lines) |
| `DeliveryService` | CRUD deliveries, tank stock curve |
| `TemperatureService` | CSV import, daily map |
| `WeatherService` | Open-Meteo wrapper (archive + forecast) |
| `RegressionService` | 5 models: linear, polynomial, robust, segmented (auto/fixed), sigmoid |
| `ForecastService` | R²-weighted mix of regression × seasonal profile; contract-based cost forecast |
| `AnomalyService` | z-score outliers |
| `BenchmarkService` | efficiency class **per heat source** + combined |
| `TariffComparisonService` | real + shadow contracts on actual consumption |
| `RecommendationService` | 7 statistical rule families, dismiss state |
| `ReminderService` | appointments/maintenance, recurrence roll-forward |
| `PdfReportService` + `Pdf\PdfWriter` | annual report, custom PDF generator |
| `BackupService` | export/import format 3.0, snapshots |
| `MigrationService` | v0.9.0 import (preview + apply) |
| `ReadingImportService` | CSV bulk import of readings |
| `CsvExportService` | tabular export (incl. deliveries) |
| `DiagnosticsService` | system status, write permissions, data count |
| `HealthCheckService` | `/api/health` (version, schema, write permissions, migrations) — N1003 |
| `DemoService` | one-click demo import via the restore path — F1007 |
| `PvSummaryService` / `StromSaldoService` | PV self-consumption/self-sufficiency resp. electricity balance — F1005 |
| `AuthService` | opt-in API token (hash in `data/auth.json`, `hash_equals`) — F1009 |
| `IngestService` | idempotent push intake (`/api/ingest`, upsert-by-date) — F1009 |

---

## 5. Controllers (`src/Controllers/`, 20)

Each controller is `final`, one class per file. Methods return `never` and respond
directly via `Response::json()` / `Response::csv()` / `Response::error()`.

`UtilitiesController`, `SettingsController`, `TemperatureController`,
`MeterController`, `ReadingController`, `ContractController`,
`ConsumptionController`, `ForecastController`, `DeliveryController`,
`BenchmarkController`, `TariffComparisonController`, `RecommendationController`,
`ReminderController`, `ReportController`, `ExportController`, `BackupController`,
`MigrationController`, `DiagnosticsController`, `HealthController`,
`DemoController`, `PvSummaryController`, `StromSaldoController`, `AuthController`,
`IngestController`.

*(Note: group endpoints from F1006 live in the `MeterController`, auth/ingest from
F1009 in `AuthController`/`IngestController`.)*

The full route list is in the [API reference](03-api-reference.md).

---

## 6. Error handling

`Http/ErrorHandler` maps exceptions uniformly:

| Exception | HTTP | Meaning |
|---|---|---|
| `InvalidArgumentException` | 400 | invalid input |
| `RuntimeException` with "not found" | 404 | resource missing |
| other | 500 | unexpected error |

A uniform response envelope: `{ "success": true, "data": … }` or
`{ "success": false, "error": "…", "detail": … }`.

### 6.1 Storage path safety (since v1.4.4)

`JsonStore::path()` builds the file path from `rootDir` plus the relative key. In
addition to the whitelist check in the service layer (`Utilities::exists()` admits
only known utility keys), `path()` checks via `realpath` + prefix comparison that
the resolved path lies **inside** `rootDir`. Any attempt to break out of the data
directory via `../` throws an `InvalidArgumentException` (→ HTTP 400).
Defence-in-depth: the protection applies even if a future endpoint were to bypass
the service-layer validation.

---

## 7. Frontend

Pure ES modules, **no build step**. `app.js` is the entry: bind the theme toggle →
`buildSidebar()` (dynamically from the active utilities) → warm the utilities cache
→ start the hash router.

> ⚠️ **Architecture-critical:** since all modules are loaded via a single ES module
> graph, **a single faulty relative import** (404) breaks the *entire* app — the
> interface stays at "Loading…". That was exactly bug v1.4.1 (`sidebar.js` imported
> `./state.js` instead of `../state.js`). The browser render test
> (`tests/browser-render.test.mjs`) has crawled the complete module graph over HTTP
> since v1.4.1 and catches such errors. See [Tests](05-testing.md).

---

[← Installation](01-installation.md) ·
[API reference →](03-api-reference.md)
