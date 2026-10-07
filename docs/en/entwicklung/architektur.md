# Architecture

**English** · [Deutsch](../../entwicklung/architektur.md)

[← Installation](../betrieb/installation.md) · [Compendium index](../README.md)

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
destroy one another. Schema level: **1.7.0**.

**Batches (since v3.1.0).** `JsonStore::batch(fn)` buffers every write inside
`fn` in memory — reads already see them — and at the end writes each file
exactly once. A batch ingest of 365 daily values thus writes `readings.json`
once instead of 365 times. If `fn` throws, nothing is written; when nested,
the outer batch applies.

---

## 2. Directory layout

```text
energietracker/
├── api.php                 # API entry point (~20 lines)
├── index.php               # SPA shell (HTML, favicon, theme anti-flash)
├── VERSION                 # the single source of the version number
├── public/
│   ├── css/                # tokens, app, components, print (v3.1.0)
│   ├── locales/            # language catalogues <lang>.json + languages.json (see Translating)
│   ├── img/                # app icon (light/dark), favicon
│   └── js/
│       ├── app.js          # frontend entry point
│       ├── router.js       # hash router (token, abort, area tabs)
│       ├── api.js          # fetch wrapper (BASE = 'api.php', timeout)
│       ├── state.js        # utilities/settings cache, saveSettings()
│       ├── lib/            # nav-model, sidebar, mobile-nav, theme, format, contrast, outbox, photo …
│       ├── components/     # chart, modal, toast
│       └── views/          # 15 views (see UI reference)
├── src/
│   ├── bootstrap.php       # DI container + route table
│   ├── Config/Utilities.php# utilities — single source of truth
│   ├── Config/Countries.php# country profiles (v2.7.0) — single source of truth
│   ├── Http/               # Router, Request, Response, ErrorHandler, CrossSiteGuard
│   ├── Storage/            # JsonStore, Migrator, WriteLock
│   ├── Support/            # Dates, Encoding, LocalizedException
│   ├── Services/           # domain logic (+ Pdf/PdfWriter)
│   └── Controllers/        # one file per class (PSR-1)
├── data/                   # runtime data (not in VCS)
├── demo-data/              # complete example dataset (8 utilities)
├── deploy/                 # templates for Unraid, CasaOS, Umbrel (v3.1.0)
├── docs/                   # this compendium
├── tests/                  # test harnesses
├── tools/build-demo.mjs    # build the public demo (v3.0.0, see § 8)
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

From this follow two calculation paths (see [data model](../referenz/datenmodell.md) and
[fundamentals](../verstehen/00-overview.md)):

- **cumulative** (gas, electricity, water, district heating): consumption =
  difference of successive meter readings, linearly interpolated over the days.
- **delivery-based** (heating oil, pellets): a **tank log** (since v2.10.0) —
  initial stock, deliveries "filled to full" and tank readings are anchor
  points; in between, consumption is distributed HDD-weighted, after the last
  one it is estimated with the calibrated rate. Consumption, cost and the tank
  stock curve come from the same calculation
  (`DeliveryConsumptionService::tankModel()`).

---

## 4. Services (`src/Services/` and `Pdf\PdfWriter`)

Each service is `final`, has a dependency-injected constructor and knows **no
HTTP**.

| Service | Responsibility |
|---|---|
| `SettingsService` | read/merge settings, type casts; defaults in `DEFAULTS`; cached per data state (v2.6.0) |
| `ConversionFactorService` | dated gas factors (F1012), day-exact |
| `I18nService` | catalogues, `t()`, `tp()` (plural rules `PLURAL_RULES`, v3.1.0), output typography, numbers and dates via `format.*`; language per request since v3.1.0 in the order `X-ET-Language` (device) → setting `language` → `Accept-Language` (set in `App::handle()`); `installationLocale()` for PDF and CSV; maps messages to their error code (v2.6.0) — [Translating](uebersetzen.md) |
| `MeterService` | CRUD meters/tanks, device swap, topology (submeters/groups, F1006) + `external_id` alias (F1009); `countsInTotals()`/`inService()`: a meter out of service counts in totals, not in capture and warnings (v2.9.0) |
| `ReadingService` | CRUD readings, auto-assignment to the active device; capture overview with the typical daily consumption; batch upsert for the CSV import (v2.6.0); since v3.1.0 `client_ref` (no duplicate when sending again) and `attachment_id` (photo) |
| `AttachmentService` | **(v3.1.0)** receipts: photos and PDFs under `data/attachments/`, index `attachments.json`; type from the first bytes, limits per file and in total, reference to the record, clean-up of orphaned receipts after 24 h |
| `OcrService` | **(v3.1.0)** text recognition through your own service in the home network (Ollama or OpenAI-compatible); checks on every call that every address is local and connects to exactly the checked one (`CURLOPT_RESOLVE`), without redirects |
| `ContractService` | CRUD contracts, strict validation, effective-date lookup; since v2.9.0 day-exact segments (`segmentsBetween`), renewed contract (`resolveForDate`), cancellation deadline (`switchTiming`) |
| `ConsumptionService` | monthly aggregation (cumulative **and** delivery-based), balance by calendar, heating model and weather adjustment (v2.8.0); contracts day-exact with `contract_parts` (v2.9.0); delegates the delivery daily distribution to `DeliveryConsumptionService`; since v2.6.0 plausibility (outliers, suspicion, rollover) with `warnings` |
| `DeliveryConsumptionService` | **(since v1.4.4)** heating oil/pellets — extracted from `ConsumptionService`; since v2.10.0 the tank log (`tankModel()`): anchors, one calculation for consumption, costs and stock, climate normal for missing days |
| `DeliveryService` | CRUD deliveries, tank stock curve |
| `TemperatureService` | CSV import, Open-Meteo sync with a source per day, daily auto-sync (v2.8.0) |
| `WeatherService` | Open-Meteo wrapper (archive, forecast, 30-year daily means, place search since v2.12.0) behind the `WeatherSource` interface |
| `ClimateNormalService` | **(v2.8.0)** climate normal at the location: HDD mean and spread per calendar month from 30 years |
| `RegressionService` | 5 models: linear, polynomial, robust, segmented (auto/fixed), sigmoid |
| `ForecastService` | R²-weighted mix of regression × seasonal profile, HDD from the climate normal, uncertainty band; contract-based cost forecast |
| `AnomalyService` | outliers against the expectation per month, robust spread (v2.8.0) |
| `BenchmarkService` | efficiency class **per heat source** + combined; since v2.10.0 coverage per source, classes only for full years, certificate-style figure (`certificate`), heat-pump electricity |
| `TariffComparisonService` | real + shadow contracts on actual consumption |
| `TariffSwitchService` | switching decision from the switch date (commitment chain, break-even) |
| `RecommendationService` | 7 statistical rule families, dismiss state |
| `ReminderService` | appointments/maintenance, recurrence roll-forward |
| `PdfReportService` + `Pdf\PdfWriter` | annual report, custom PDF generator |
| `BackupService` | export/import format 3.0 with a check before writing and a way back; snapshots (list, download, restore, rotation); since v3.1.0 with receipts (`attachment_files`, checked against `sha256`), export and snapshot streamed |
| `MigrationService` | v0.9.0 import (preview + apply) |
| `ReadingImportService` | CSV bulk import of readings; since v3.1.0 headers of every language and dates via `Support\Dates::parseUserDate()` |
| `CsvExportService` | tabular export (incl. deliveries); since v3.1.0 format 1 (frozen) and "local" in the default language — [CSV formats](../referenz/api.md#csv-formats-v310) |
| `DiagnosticsService` | system status, write permissions, data count |
| `HealthCheckService` | `/api/health`: `status` ok/degraded/error, checks (write permissions, schema, files, disk space, temp files), last ingest — N1003, v2.6.0 |
| `DemoService` | one-click demo import via the restore path — F1007 |
| `DemoDataAligner` | carries the demo data forward to today on import: readings from each meter's last reading with the consumption of the same period a year earlier, deliveries and temperatures as a year earlier, reminders relative to today — pure function (v3.0.0) |
| `DemoDataTranslator` | translates the descriptive texts of the demo data (names, notes, tariffs, reminders) from `demo-data/translations.json` into the interface language; company names, IDs and numbers stay — pure function (v3.1.0) |
| `PvSummaryService` / `StromSaldoService` | PV self-consumption/self-sufficiency resp. electricity balance — F1005; since v2.10.0 rates over the months covered by all meters, and self-consumption savings |
| `AuthService` | sign-in (password, proxy, sessions, lockout), API keys and the HA token — hashes only in `data/auth.json` (F1009, v2.6.0) |
| `IngestService` | idempotent push intake (`/api/ingest`, upsert-by-date) — F1009; since v3.1.0 batches (`ingestMany()`, up to 500 entries, sorted by utility, meter and date, one write per file via `JsonStore::batch()`) |
| `AgendaService` | **(v3.1.0)** deadlines and appointments from one source: reminders, readings due, cancellation deadline, end of term, end of the price guarantee, price increase, low stock. Per event `uid`, `kind`, `date`, `severity`, `due_now`; `due_now` fills “To do” on the dashboard |
| `CalendarService` | **(v3.1.0)** calendar subscription per RFC 5545 from the agenda (365 days): all-day, UID `<kind>-<id>@<instance_id>`, VALARM after `reminder_warn_days_before`, texts in the installation's default language |
| `SummaryService` | **(v3.1.0)** figures for Home Assistant and scripts (`/api/summary`, `summary_version` 1): per meter reading, consumption, contract, forecast, tank; plus PV and agenda. Every key is always there (value or null) |
| `InstanceService` | **(v3.1.0)** identifier of the installation (`et_` + 16 hex) in `data/instance.json`, created on first use; part of the calendar UIDs and of the `/api/summary` answer. Deliberately not in the backup — after a restore two installations would otherwise share it |
| `PeriodService` | **(v3.1.0)** consumption per period (`<utility>/periods.json`): create, change, CSV import and export; spreading over the months is done by `ConsumptionService` |
| `TenancyService` | **(v3.1.0)** tenancy (`tenancies.json`) and service charge statements (`tenancy_statements.json`) with receipts; carrying prices and prepayment over into the tenancy |
| `TenancyBudgetService` | **(v3.1.0)** auxiliary calculation: expected costs of the current billing period against the prepayment, month by month measured or estimated |
| `Co2CostService` | **(v3.1.0)** CO₂ price in fuel (BEHG) per utility and year — shown, not added; source bill, network factor or standard factor; stage under CO2KostAufG |
| `Co2SplitService` | **(v3.1.0)** CO₂ costs between tenant and landlord (CO2KostAufG): calculate yourself (own boiler) or check the heating cost statement (central heating); letter as PDF |
| `BillService` | **(v3.1.0)** supplier bills (`<utility>/bills.json`): record, compare with `billBreakdown()`, book the result as a special payment |
| `MarketPriceService` | **(v3.1.0)** wholesale electricity prices as monthly averages (`market_prices.json`): file import, SMARD download only on request, translation of a dynamic shadow contract into monthly prices (`expand()`) |
| `EvChargingReportService` | **(v3.1.0)** charging record of the wall box per month: contract price of the paying contract with a standing charge share, or the flat electricity rate (country profile); PDF via `Pdf\PdfWriter` |
| `HeatPumpService` | **(v3.1.0)** seasonal performance factor: heat of the meter with role `heat_pump_output` ÷ electricity of the linked meters, only months with both sides |
| `SeriesImportService` | **(v3.1.0)** condense time series from portals into daily values with a column mapping — as readings (via `ReadingImportService`) or periods (via `PeriodService`) |
| `ReferenceService` | **(v3.1.0)** benchmark against your own reference values: household electricity without heat pump and wall box, heating per m², links for checking yourself (Germany only) |

Since v3.1.0 `ConsumptionService` also knows the **group contract**
(`contractScope()`, `computeForGroup()`, `contractView()` for the blended
price), the reduced grid fee under § 14a EnWG, credit notes from the direct
marketer and the before/after comparison without a heating curve;
`MeterService::groupTarget()` makes a group the target of evaluations,
`PvSummaryService` calculates battery, payback and the plug-in solar
assumption.

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
`IngestController`, `SessionController`, `ManifestController` (v3.1.0: web app
manifest in the language of the device), `AgendaController` (v3.1.0:
`/api/agenda`, `/api/calendar.ics`, `/api/summary`), `AttachmentController`
(v3.1.0: receipts and text recognition), `PeriodController`,
`TenancyController`, `Co2Controller`, `BillController` (all v3.1.0),
`MarketPriceController` (`/api/market-prices`), `EvChargingController`
(`/api/reports/ev-charging…`), `HeatPumpController` (`/api/heat-pump`),
`SeriesImportController` (`…/import-series`, all v3.1.0). The benchmark lives
in `BenchmarkController`, the monthly price import in `ContractController`, the
evaluations of a meter group in the same controllers as those of a meter.

*(Note: group endpoints from F1006 live in the `MeterController`, auth/ingest from
F1009 in `AuthController`/`IngestController`, sign-in and API keys (v2.6.0) in
the `SessionController`.)*

**Sign-in gate (v2.6.0).** Before routing, `App` in `bootstrap.php` checks the
host name (`ET_ALLOWED_HOSTS`), foreign browser requests (`CrossSiteGuard`)
and — with sign-in switched on — the session, API key or proxy user. Ingest,
health (in minimal form) and sign-in itself stay public. The calendar
subscription (v3.1.0) also accepts a key of scope `calendar` as `?token=…` —
only there and only that way; a read or admin key in the link is refused.
Details: [Security](../betrieb/sicherheit.md).

The full route list is in the [API reference](../referenz/api.md).

---

## 6. Error handling

`Http/ErrorHandler` maps exceptions uniformly:

| Exception | HTTP | Meaning |
|---|---|---|
| `InvalidArgumentException` | 400 | invalid input |
| `Support\LocalizedException` | 400 | invalid input from code without an `I18nService` (static helpers such as `Utilities::get()`): carries catalogue key and parameters; the ErrorHandler translates into the language of the request and reports the key as `code` (v3.1.0) |
| `Http\NotFoundException` | 404 | resource missing (a type instead of a text pattern since v2.2.1; since v2.6.0 also for records addressed in the URL) |
| `Http\ConflictException` | 409 | conflict, e.g. the safety snapshot failed (v2.6.0) |
| `Storage\StorageCorruptedException` | 503 | data file corrupt (v2.5.3); since v3.1.0 the ErrorHandler translates the message (`errors.storage.<kind>`), `code` stays `errors.storage.corrupted` |
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
load the language → warm the utilities cache → `buildSidebar()` (dynamically from
the active utilities) → start the hash router.

**Navigation (since v2.11.0).** `lib/nav-model.js` is the single source for the
sidebar, the tab bar (iPhone, `lib/mobile-nav.js`) and the tabs of the areas. The
router announces every navigation as an `et:route` event (view, area, utility);
sidebar and tab bar set their highlight from it.

**Router (since v2.11.0).** Every navigation gets a token, an abort signal and a
container of its own inside `#view`:

- The old view's cleanup runs before the new view starts.
- A late view writes into its detached container. Its cleanup runs as soon as
  it finishes.
- `api.js` aborts the read requests (GET) of the view that was left; their
  promise stays pending, so the view does not continue and reports no error.
  Writes always complete.
- App-wide fetches (`state.js`, the sidebar counts) run through `appScope()`
  without a signal.
- Views load via `import()`; the import map versions these paths too. After
  the first paint the router preloads the rest when idle, so they sit in the
  service worker's cache for offline use.
- `#/path?key=value` hands the query to the view as `ctx.query`:
  `render(container, params, ctx)`.

**Language.** `lib/i18n.js` loads the catalogue of this device's language
(`localStorage` `et-language`, otherwise the setting `language`) and translates
with `t()` and `tp()`; `api.js` sends the language as `X-ET-Language` with
every request so that the API's texts match (v3.1.0). `lib/format.js` formats
numbers, amounts and dates via `Intl` by language and country. Structure, style
rules and checklists: [Translating](uebersetzen.md).

**Settings** are written by the views through `state.saveSettings(patch)`:
PATCH, update the cache, fire `et:settingschange`. The sidebar rebuilds when
`active_utilities` changes. After demo data, an import or a restore the app
reloads completely.

**“To do” (since v3.1.0)** is no longer assembled by the dashboard itself; it
shows the events from `GET /api/agenda` with `due_now`. The calendar
subscription and Home Assistant read the same agenda — one rule for all three.

**Service worker (per installation since v3.1.0).** The caches carry the
installation's scope in their name: `et:<scope>:static-<version>` and
`et:<scope>:runtime-<version>`. On activation the worker deletes only caches
with its own prefix (and, once, the old names without a scope); the
self-repair in `index.php` (cache version ≠ shell version → clear caches,
unregister the worker, reload) sees only its own caches and unregisters only
the worker whose scope is this installation's path. Up to v3.0 every
installation cleared everything that did not carry its name — with two
installations on one origin (two instances on the same NAS under different
paths) each other's, and under Home Assistant ingress Home Assistant's caches
and worker too.

**Home Assistant ingress (v3.1.0).** When the page arrives with the
`X-Ingress-Path` header, `index.php` registers no service worker (scope and
caches belong to Home Assistant there; there is no offline use under ingress)
and names the container's host name in `<meta name="et-ingress-host">`. The
Home Assistant template in the settings then uses `http://<hostname>` as the
address instead of the ingress address from the address bar.

**Dialogs** push a history entry when they open: the back button closes the
topmost dialog instead of the page. If it closes otherwise, it takes the entry
back with `history.back()` — except on a navigation, which that would undo.

**Charts (since v2.15.0).** Chart.js 4.5.1 lives under `public/vendor/`
(unchanged from the npm package, integrity checked against the registry) and
comes as a global `Chart`. Every view draws through `components/chart.js`:

- `makeChart(canvas, config, {label})` enters each chart in a registry. A
  canvas carries exactly one; on the `et:route` event the layer clears up what
  the old view left behind. A Chart.js error goes to the console, not into a
  toast.
- Colours are functions in the configurations: `utilColor(u, alpha)` (the
  utility's colour, tinted per theme via `themedColor` from
  `lib/utility-theme.js`) and `tokenColor(name)` (theme token). On the
  `et:themechange` event the layer resets the defaults, releases the axis
  colours Chart.js copied when the chart was created, and redraws every open
  chart.
- `chartTableHtml()` returns the numbers of a chart as an expandable table.

`lib/chart-data.js` holds the rules for monthly series, without DOM:
`isPartial()` (partial month, `days` less than the month), `yoyTrend()` (the
same full months a year earlier, weather-adjusted when all carry a value),
`lastMonths()` and `seriesSummary()` for short descriptions.

> ⚠️ **Architecture-critical:** since all modules are loaded via a single ES module
> graph, **a single faulty relative import** (404) breaks the *entire* app — the
> interface stays at "Loading…". That was exactly bug v1.4.1 (`sidebar.js` imported
> `./state.js` instead of `../state.js`). The browser render test
> (`tests/browser-render.test.mjs`) has crawled the complete module graph over HTTP
> since v1.4.1 and catches such errors. See [Tests](tests.md).

## 8. Public demo (since v3.0.0)

The demo at <https://bingerminger.github.io/energietracker/> is the real
interface without PHP. GitHub Pages only serves files, so the API answers are
produced in advance:

1. `tools/build-demo.mjs` starts `php -S … router.php` with an empty data
   directory and loads the demo data via `POST /api/demo/import` — on the way
   `DemoDataAligner` carries them forward to the build day.
2. The script requests every read the interface makes (per utility, meter and
   contract; the forecast per model with the view's defaults; bill check and
   tariff comparison per year) and stores the answers content-addressed under
   `demo-api/r/`, with one index per language (`demo-api/index-<lang>.json`).
   What is the same in all languages is stored once. CSV and PDF downloads lie
   next to them as files.
3. `index.php` is rendered with `ET_DEMO_BUILD=1`: `<html data-demo>`, no
   service worker.

In the browser `data-demo` switches on `lib/demo-mode.js`. `api.js` hands every
request to it: GET from the snapshot, writes with "Nothing is saved in the
demo", a request that is not precomputed (your own what-if values, a freely
chosen period) with "not precomputed". The build script and the browser compute
a request's key (path + sorted query) with the same function
(`lib/demo-key.js`). In the demo the language follows the browser; a notice at
the bottom offers a language choice and the way to your own installation.

Publishing runs through `.github/workflows/pages.yml` — on a GitHub release (the
demo shows what is released) and on Mondays, so the data reach up to today
again. `tests/demo.test.mjs` builds the demo in CI and clicks the real app
through every page; red as soon as a page needs an answer that is not
precomputed.

---

[← Installation](../betrieb/installation.md) ·
[API reference →](../referenz/api.md)
