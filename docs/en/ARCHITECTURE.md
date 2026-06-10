# Architecture (short form)

**English** · [Deutsch](../ARCHITECTURE.md)

> **Note:** the canonical architecture documentation, maintained at every release,
> is the compendium under [`docs/en/technical/02-architecture.md`](technical/02-architecture.md).
> This older top-level document is only kept factually up to date (as of: v1.4.4)
> and offers a compact overview.

The structure, data flow and core algorithms of Energietracker.

---

## 1. Module map

```
                          ┌─────────────────────────────────┐
                          │  api.php  (20-line entry)       │
                          └─────────────┬───────────────────┘
                                        │
                          ┌─────────────▼───────────────────┐
                          │  src/bootstrap.php — app        │
                          │  container, service wiring,     │
                          │  routing                        │
                          └──┬───────────────┬──────────────┘
                             │               │
              ┌──────────────▼──────┐  ┌─────▼──────────────────┐
              │  Controllers (18)   │  │   Services (22)        │
              │  - 1 class / file   │  │   - pure domain        │
              │  - thin adapter     │  │     logic              │
              │  - no logic         │  │   - no HTTP knowledge  │
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
              │   public/js/  (vanilla-JS SPA)             │
              │   ┌─────────────────────────────────────┐   │
              │   │  app.js → router.js → views/*.js    │   │
              │   │              ↑                      │   │
              │   │    components/  ←  lib/format.js    │   │
              │   └─────────────────────────────────────┘   │
              └─────────────────────────────────────────────┘
```

---

## 2. Backend layers

### 2.1 HTTP layer (`src/Http/`)

- **`Router.php`** — pattern-based routes (`{utility}` / `{id}`). Strips
  `SCRIPT_NAME` and collapses multiple slashes into a single one before the matching
  begins.
- **`Request.php`** — path params, query params, JSON body decoding.
- **`Response.php`** — JSON encoding, a uniform response envelope (`{success, data}`
  or `{success, error, detail}`).
- **`ErrorHandler.php`** — `set_exception_handler`, maps:
  - `InvalidArgumentException` → HTTP 400
  - `RuntimeException` with "not found" → HTTP 404
  - other → HTTP 500

### 2.2 Storage layer (`src/Storage/`)

- **`JsonStore.php`** — a read and write wrapper for `data/`. Writes with `LOCK_EX`
  and `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. Read
  methods return a `[]` default if the file is missing.
- **`Migrator.php`** — bootstrap logic for a fresh data directory (`initFresh()`)
  and automatic schema migration on future v1.0.x updates (the hook point for
  data-model jumps).

### 2.3 Config (`src/Config/`)

**`Utilities.php`** — the single source of truth for the three utilities. Delivers
per key (`gas`, `strom`, `wasser`):

| Field | Meaning |
|---|---|
| `label` | display name (e.g. "Gas") |
| `icon` | emoji (🔥/⚡/💧) |
| `unit` | meter-reading unit (m³ for gas/water, kWh for electricity) |
| `consumption_unit` | consumption unit for the calculation (kWh for gas/electricity, m³ for water) |
| `unit_to_kwh` | bool — the meter reading must be converted to the consumption unit |
| `conversion_setting` | settings key for the conversion factor (`gas_conversion_factor`) |
| `hgt_relevant` | bool — consumption reacts to heating degree days |
| `color` | hex colour for the UI |
| `co2_setting` | settings key for the CO₂ factor |
| `default_meter_name` | default name when creating a meter |
| `allow_multiple_meters` | bool — F3: allow several meters |

### 2.4 Services (`src/Services/`)

Each service is `final`, has a dependency-injected constructor and knows no HTTP. The
external call view goes through the controllers.

| Service | Responsibility |
|---|---|
| `SettingsService` | read/merge the settings file, type-cast for numeric values |
| `MeterService` | CRUD of meters + F2 (device replace) |
| `ReadingService` | CRUD of readings, auto-assignment of `device_id` to the active device |
| `ContractService` | CRUD of contracts, F4-strict validation, `valueValidOn(...)` for the effective-date lookup, `bonusForMonth(...)` |
| `ConsumptionService` | monthly aggregation, F2 device bridging, F3 multi-meter aggregation, **`contractStatus()`** for the balance card; F-03 billing-cycle projection of open contracts, F-05 contract-end reminder, waste-water `separater_zaehler` resolution with a recursion lock |
| `DeliveryConsumptionService` | **(v1.4.4)** daily consumption distribution & tank stock draw for heating oil/pellets — extracted from `ConsumptionService` |
| `TemperatureService` | CSV import, day-map update |
| `WeatherService` | Open-Meteo API wrapper (archive + forecast) |
| `RegressionService` | five models (linear, polynomial, robust, segmented, sigmoid) + `fit()` dispatcher + `predict()` |
| `ForecastService` | R²-weighted mix of regression and seasonal profile over 12 months; F-02 contract-based cost forecast (`projectMonthFinances()`) with a projected advance and running balance |
| `AnomalyService` | z-score-based outlier detection |
| `BackupService` | export/import in the 3.0 format, snapshot creation |
| `MigrationService` | v0.9.0 → current schema, preview + apply with replace/merge mode |
| `ReadingImportService` | CSV bulk import of readings (F-06); source-agnostic core `importRows()`, overwrites existing readings on the same date |
| `CsvExportService` | tabular CSV export (F-07) for the monthly overview, meter readings, temperature series |
| `DiagnosticsService` | system status, totals per utility |

### 2.5 Controllers (`src/Controllers/`)

Each controller is `final`, one class per file. Methods return `never`, because they
respond directly via `Response::json()`/`Response::error()` (terminating with
`exit`).

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

## 3. Frontend structure

```
public/
├── css/
│   ├── tokens.css       ← colours, spacing, font stack as CSS variables
│   ├── app.css          ← layout grid (sidebar + topbar + view)
│   └── components.css   ← reusable patterns
└── js/
    ├── app.js           ← entry: starts the router, tooltips, toast stack
    ├── router.js        ← hash router; matches against `data-route`
    ├── api.js           ← fetch wrapper with response-envelope unpacking
    ├── state.js         ← lightweight cache (`getUtilities()`,
    │                      `getSettings()`, `invalidateSettings()`)
    ├── lib/
    │   └── format.js    ← de-DE formatter (number, EUR, date, month)
    ├── components/
    │   ├── chart.js     ← Chart.js wrapper with dark-theme defaults
    │   ├── modal.js     ← generic modal + confirm dialog
    │   └── toast.js     ← stack bottom-right, OK/warning/error
    └── views/
        ├── dashboard.js     ← 12-month KPIs + charts
        ├── utility.js       ← gas/electricity/water (balance, contracts, monthly table, …)
        ├── meters.js        ← meters + devices CRUD incl. F2 swap UI
        ├── contracts.js     ← contract editor with F4 validation
        ├── temperatures.js  ← CSV import + Open-Meteo sync + monthly chart
        ├── analysis.js      ← HDD scatter with 4 regression models + year comparison
        ├── forecast.js      ← 12-month forecast per utility
        └── settings.js      ← settings form, backup/restore, migration from v0.9.0
```

---

## 4. Data flow: reading → monthly consumption → balance

### Step 1 — reading collection

`ConsumptionService::forMeter($utility, $meter)` starts with all readings of the
meter, filters out those with `is_future: true` or a date in the future, and sorts
them chronologically. At least two readings must be present — otherwise an empty
result.

### Step 2 — interval-to-month distribution

For each pair of consecutive readings `(prev, curr)`:

1. Days = `(curr.date − prev.date).days`
2. The counter delta is computed via `consumptionBetween()` — on a device swap
   between `prev` and `curr` it is bridged like this:
   ```
   consumption = (old_device.final_counter − prev.counter)
               + (curr.counter − new_device.initial_counter)
   ```
3. Counter delta × `unit_to_kwh` factor (for gas: 11.5 kWh/m³ as default).
4. **Linear distribution over the months**: daily, the kWh and the proportional days
   are pushed into the respective `YYYY-MM` bucket.

### Step 3 — weather enrichment

Each month is assigned, from `temperatures.json`:

- `avg_temp` = the mean of all daily temperatures in the month
- `min_temp` = the minimum
- `max_temp` = the maximum
- **`hdd`** (heating degree days) = Σ max(0, base − avg_day) over all days, base =
  `settings.hdd_base_temp` (default 15 °C)

### Step 4 — utility fields

- `kwh_per_day = kwh / days`
- `m3 = kwh / unit_to_kwh_factor` (gas only)
- `co2_kg = kwh × co2_setting / 1000`

### Step 5 — contract application

For each month the contract active for the month-start date is determined
(`ContractService::findActiveForDate(...)`). From it:

- `working_price_ct` = the to-the-date working price (`valueValidOn`)
- `base_price_eur` = the to-the-date base price
- `advance_eur` = the to-the-date monthly advance
- `bonus_eur` = possibly the bonus for this month (proportional to the bonus credit
  date)
- `kwh_cost = kwh × working_price_ct / 100`
- `cost = kwh_cost + base_price_eur − bonus_eur`
- `monthly_balance = cost − advance_eur` (positive = underpaid)
- `cumulative_balance` = the running balance per contract ID (reset on every
  contract change)

### Step 6 — moving averages

- `ma3` = the 3-month mean over `kwh` (for water over `m3`)
- `ma6` = the 6-month mean
- `ma12` = the 12-month mean

### Step 7 — contract aggregation (`contractStatus`)

After the month rows, `ConsumptionService::contractStatus()` delivers the
aggregation per contract ID:

```
actual_kwh       = Σ kwh
actual_cost      = Σ cost
actual_kwh_cost  = Σ kwh_cost
actual_base_total  = Σ base_price_eur
actual_bonus_total = Σ bonus_eur
advance_paid     = Σ advance_eur
months_actual    = count(months)
current_balance  = actual_cost − advance_paid
```

For the **expected end balance**, an extrapolation over the remaining months:

```
months_to_end   = monthsBetween(today, effective_end)
avg_monthly_cost = actual_cost / months_actual
monthly_advance  = current_advance_amount
delta_remaining  = (avg_monthly_cost − monthly_advance) × months_to_end
projected_end_balance = current_balance + delta_remaining
```

`verdict` threshold:
- `back-payment` if `projected > +5 €`
- `refund` if `projected < −5 €`
- `balanced` in between

---

## 5. Forecast algorithm

`ForecastService::forMeter($utility, $meter)` mixes two sources:

1. **Regression component** — on the past `(hdd, kwh)` pairs, the model configured in
   the settings fits. The predict per forecast month uses the HDD forecast from the
   seasonal profile of the last years (daily means over the calendar day, aggregated
   to the month).
2. **Seasonal component** — the mean of the consumption in each calendar month over
   all past years.

The final forecast is:

```
weight = min(blend_max, r2)   // settings.blend_max = 0.8 default
forecast_kwh = weight × regression_pred + (1 − weight) × seasonal_avg
```

With `hgt_relevant: false` (water), `weight = 0` is set — the forecast consists
exclusively of the seasonal profile.

**Cost forecast (F-02, since v1.1.0).** Per forecast month, `projectMonthFinances()`
resolves the then-active contract and uses the working and base price valid for that
month from the price history (`ContractService::valueValidOn(...)`). From this arise
`cost_estimated` (working price × quantity + base price − known bonuses),
`advance_estimated` (the valid advance) and `balance_running` (cumulative costs −
advance). Future bonuses are not carried forward. If an active contract is missing,
`last_price_ct` applies as a fallback working price.

---

## 6. Settings inventory (28 keys)

| Key | Type | Default | Meaning |
|---|---|---|---|
| `gas_conversion_factor` | float | 11.5 | kWh per m³ gas |
| `hdd_base_temp` | float | 15 | HDD base temperature in °C |
| `co2_gas` | int | 201 | g CO₂ per kWh gas |
| `co2_strom` | int | 380 | g CO₂ per kWh electricity |
| `co2_wasser` | int | 350 | g CO₂ per m³ water |
| `min_days_period` | int | 20 | minimum days per reading interval (sanity) |
| `min_hdd_regression` | float | 5 | minimum HDD per month to be considered in the regression |
| `blend_max` | float | 0.8 | maximum weight of the regression component in the forecast |
| `forecast_months` | int | 12 | forecast horizon in months |
| `min_temp_days_forecast` | int | 20 | minimum days of temperature data per month for use |
| `forecast_model` | string | `linear` | default model for the forecast (`linear`/`polynomial`/`robust`/`segmented`) |
| `dashboard_months` | int | 12 | how many months to show on the dashboard |
| `alert_days_since_reading` | int | 45 | status-banner threshold "reading overdue" |
| `anomaly_threshold` | float | 2 | z-score threshold for anomaly detection |
| `location_name` | string | "Leipzig Zentrum" | display name of the location |
| `latitude` | float | 51.3397 | geo-lat for Open-Meteo |
| `longitude` | float | 12.3731 | geo-lon for Open-Meteo |
| `weather_auto_fill` | bool | true | run the sync automatically on start |
| `wasser_personen_anzahl` | int | 2 | persons in the household for the water reference |
| `wasser_personen_referenz` | int | 127 | reference litres per person per day |
| `billing_cycle_anchor_gas` | string | `01-01` | billing date gas (`MM-DD`); the balance of open contracts is projected up to there (F-03) |
| `billing_cycle_anchor_strom` | string | `01-01` | billing date electricity (F-03) |
| `billing_cycle_anchor_wasser` | string | `01-01` | billing date water (F-03) |
| `contract_remind_days_1` | int | 90 | contract-end reminder level 1 — days before (F-05) |
| `contract_remind_days_2` | int | 30 | contract-end reminder level 2 (F-05) |
| `contract_remind_days_3` | int | 1 | contract-end reminder level 3 — urgent (F-05) |
| `wasser_sparindex_gut` | int | 100 | water saving index: values ≤ count as unremarkable (F-10) |
| `wasser_sparindex_warnung` | int | 150 | water saving index: values ≥ show saving potential (F-10) |

All eight v1.1.0 keys are additive: the `SettingsService` merges them from the
defaults on read, a `settings.json` from an earlier version continues to work without
a migration step. The storage schema (`Migrator::SCHEMA_VERSION`) stays unchanged at
`1.0.3`.

---

## 7. Data initialisation

With an empty `data/` directory (only `.gitkeep` present),
`Storage\Migrator::initFresh()` creates on the first request:

- `meta.json` with `schema_version`, `created_at`
- `settings.json` with all 20 defaults
- `temperatures.json` as `{}`
- per utility: `gas/`, `strom/`, `wasser/` with empty `meters.json`, `readings.json`,
  `contracts.json`

**No** default meter is created automatically — the user must explicitly create the
first meter via the UI.

---

## 8. Test and debug notes

### PHP built-in server for local testing

```bash
php -S 127.0.0.1:8765
```

### Endpoint via curl

```bash
curl -s http://127.0.0.1:8765/api.php/api/diagnostics | jq
```

### Debug logs

`Response::error(...)` writes via `error_log()` into the standard error log of the
PHP server. The ErrorHandler appends stack traces to the JSON response in a
`WP_DEBUG`-like mode (see `bootstrap.php`).

### Reset demo data

```bash
find data/ -mindepth 1 -not -name '.gitkeep' -delete
mkdir -p data/gas data/strom data/wasser data/backups
cp -r demo-data/gas demo-data/strom demo-data/wasser data/
cp demo-data/meta.json demo-data/settings.json demo-data/temperatures.json data/
```
