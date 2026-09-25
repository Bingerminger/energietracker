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
  |  Controllers             |  Services                       |
  |  HTTP in / out           |  domain logic, no HTTP          |
  +-----------------------------------------------------------+
                          |
                          v
  Storage  - JsonStore (atomic, write lock) + Migrator
                          |
                          v
  data/    - flat JSON files per utility
```

There is **no** database. Persistence is a set of JSON files under `data/`,
written with `LOCK_EX` (an exclusive lock) so that parallel requests do not
destroy one another. Schema level: **1.5.0**.

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
│       ├── lib/            # sidebar, theme, format, gas-factor
│       ├── components/     # chart, modal, toast
│       └── views/          # 12 views (see UI reference)
├── src/
│   ├── bootstrap.php       # DI container + route table
│   ├── Config/Utilities.php# utilities — single source of truth
│   ├── Config/Countries.php# country profiles (v2.7.0) — single source of truth
│   ├── Http/               # Router, Request, Response, ErrorHandler, CrossSiteGuard
│   ├── Storage/            # JsonStore, Migrator, WriteLock
│   ├── Support/            # Dates, Encoding
│   ├── Services/           # domain logic (+ Pdf/PdfWriter)
│   └── Controllers/        # one file per class (PSR-1)
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

## 4. Services (`src/Services/` and `Pdf\PdfWriter`)

Each service is `final`, has a dependency-injected constructor and knows **no
HTTP**.

| Service | Responsibility |
|---|---|
| `SettingsService` | read/merge settings, type casts; defaults in `DEFAULTS`; cached per data state (v2.6.0) |
| `ConversionFactorService` | dated gas factors (F1012), day-exact |
| `I18nService` | catalogues, `t()`, language from the setting or `Accept-Language`; maps messages to their error code (v2.6.0) |
| `MeterService` | CRUD meters/tanks, device swap, topology (submeters/groups, F1006) + `external_id` alias (F1009) |
| `ReadingService` | CRUD readings, auto-assignment to the active device; capture overview with the typical daily consumption; batch upsert for the CSV import (v2.6.0) |
| `ContractService` | CRUD contracts, strict validation, effective-date lookup |
| `ConsumptionService` | monthly aggregation (cumulative **and** delivery-based), balance by calendar, heating model and weather adjustment (v2.8.0); delegates the delivery daily distribution to `DeliveryConsumptionService`; since v2.6.0 plausibility (outliers, suspicion, rollover) with `warnings` |
| `DeliveryConsumptionService` | **(since v1.4.4)** daily consumption distribution & tank stock draw for heating oil/pellets — extracted from `ConsumptionService` (~350 lines) |
| `DeliveryService` | CRUD deliveries, tank stock curve |
| `TemperatureService` | CSV import, Open-Meteo sync with a source per day, daily auto-sync (v2.8.0) |
| `WeatherService` | Open-Meteo wrapper (archive, forecast, 30-year daily means) behind the `WeatherSource` interface |
| `ClimateNormalService` | **(v2.8.0)** climate normal at the location: HDD mean and spread per calendar month from 30 years |
| `RegressionService` | 5 models: linear, polynomial, robust, segmented (auto/fixed), sigmoid |
| `ForecastService` | R²-weighted mix of regression × seasonal profile, HDD from the climate normal, uncertainty band; contract-based cost forecast |
| `AnomalyService` | outliers against the expectation per month, robust spread (v2.8.0) |
| `BenchmarkService` | efficiency class **per heat source** + combined |
| `TariffComparisonService` | real + shadow contracts on actual consumption |
| `TariffSwitchService` | switching decision from the switch date (commitment chain, break-even) |
| `RecommendationService` | 7 statistical rule families, dismiss state |
| `ReminderService` | appointments/maintenance, recurrence roll-forward |
| `PdfReportService` + `Pdf\PdfWriter` | annual report, custom PDF generator |
| `BackupService` | export/import format 3.0 with a check before writing and a way back; snapshots (list, download, restore, rotation) |
| `MigrationService` | v0.9.0 import (preview + apply) |
| `ReadingImportService` | CSV bulk import of readings |
| `CsvExportService` | tabular export (incl. deliveries) |
| `DiagnosticsService` | system status, write permissions, data count |
| `HealthCheckService` | `/api/health`: `status` ok/degraded/error, checks (write permissions, schema, files, disk space, temp files), last ingest — N1003, v2.6.0 |
| `DemoService` | one-click demo import via the restore path — F1007 |
| `PvSummaryService` / `StromSaldoService` | PV self-consumption/self-sufficiency resp. electricity balance — F1005 |
| `AuthService` | sign-in (password, proxy, sessions, lockout), API keys and the HA token — hashes only in `data/auth.json` (F1009, v2.6.0) |
| `IngestService` | idempotent push intake (`/api/ingest`, upsert-by-date) — F1009 |

---

## 5. Controllers (`src/Controllers/`)

Each controller is `final`, one class per file. Methods return `never` and respond
directly via `Response::json()` / `Response::csv()` / `Response::error()`.

`UtilitiesController`, `SettingsController`, `TemperatureController`,
`MeterController`, `ReadingController`, `ContractController`,
`ConsumptionController`, `ForecastController`, `DeliveryController`,
`BenchmarkController`, `TariffComparisonController`, `TariffSwitchController`,
`RecommendationController`,
`ReminderController`, `ReportController`, `ExportController`, `BackupController`,
`MigrationController`, `DiagnosticsController`, `HealthController`,
`DemoController`, `PvSummaryController`, `StromSaldoController`, `AuthController`,
`IngestController`, `SessionController`.

*(Note: group endpoints from F1006 live in the `MeterController`, auth/ingest from
F1009 in `AuthController`/`IngestController`, sign-in and API keys (v2.6.0) in
the `SessionController`.)*

**Sign-in gate (v2.6.0).** Before routing, `App` in `bootstrap.php` checks the
host name (`ET_ALLOWED_HOSTS`), foreign browser requests (`CrossSiteGuard`)
and — with sign-in switched on — the session, API key or proxy user. Ingest,
health (in minimal form) and sign-in itself stay public. Details:
[Security](08-security.md).

The full route list is in the [API reference](03-api-reference.md).

---

## 6. Error handling

`Http/ErrorHandler` maps exceptions uniformly:

| Exception | HTTP | Meaning |
|---|---|---|
| `InvalidArgumentException` | 400 | invalid input |
| `Http\NotFoundException` | 404 | resource missing (a type instead of a text pattern since v2.2.1; since v2.6.0 also for records addressed in the URL) |
| `Http\ConflictException` | 409 | conflict, e.g. the safety snapshot failed (v2.6.0) |
| `Storage\StorageCorruptedException` | 503 | data file corrupt (v2.5.3) |
| other | 500 | unexpected error — generic message with `error_id`, details in the log (v2.6.0) |

A uniform response envelope: `{ "success": true, "data": … }` or
`{ "success": false, "error": "…", "code": "errors.…" }`. `code` is the
catalogue key of the message (`I18nService::errorCodeFor()`), otherwise
`errors.http.*` by status code; `detail` with file/line only with
`ET_DEBUG=1`. The router answers `HEAD` like `GET`, a wrong method with `405`
and `Allow`, `OPTIONS` with `204`.

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
