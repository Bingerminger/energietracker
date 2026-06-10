# API reference (short form)

**English** · [Deutsch](../API.md)

> **Note:** the canonical API reference, maintained at every release, is the
> compendium under [`docs/en/technical/03-api-reference.md`](technical/03-api-reference.md).
> This top-level document contains the detailed request/response examples and is kept
> factually up to date (as of: v1.9.2).

A REST API over a single entry point: `api.php`. Paths have the prefix `/api/`,
which follows the script name:

```
http://localhost/api.php/api/<endpoint>
```

With Apache using `mod_rewrite` or similar routing, the `api.php` part can be hidden
— the examples below show the explicit path that works without URL rewriting.

---

## Response envelope

All responses have the same structure:

**Success:**

```json
{
  "success": true,
  "data": { ... }
}
```

**Error:**

```json
{
  "success": false,
  "error": "Readable error message in German",
  "detail": { "file": "...", "line": ..., "type": "..." }
}
```

`detail` is optional and contains diagnostic information that the ErrorHandler adds
on exceptions. The HTTP status code reflects the error class:

| Status | Class | When |
|---|---|---|
| 200 | OK | success |
| 400 | Bad Request | `InvalidArgumentException` — validation error, missing mandatory fields |
| 404 | Not Found | `RuntimeException` with "not found" in the text — route or entity missing |
| 500 | Internal Server Error | other errors |

> Note: the human-readable `error` messages in API responses are emitted in German
> (the application's source locale), regardless of the documentation language.

---

## Route overview

| Method | Path | Purpose |
|---|---|---|
| GET    | `/api/diagnostics`                                                | system status (PHP version, write permissions, schema, etc.) |
| GET    | `/api/utilities`                                                  | list of utilities + configuration |
| GET    | `/api/settings`                                                   | current settings |
| PATCH  | `/api/settings`                                                   | update settings |
| GET    | `/api/temperatures`                                               | daily temperatures as a map |
| POST   | `/api/temperatures`                                               | upsert a single day |
| POST   | `/api/temperatures/import-csv`                                    | CSV import |
| POST   | `/api/temperatures/sync-open-meteo`                               | sync via Open-Meteo |
| DELETE | `/api/temperatures/{date}`                                        | delete a day |
| GET    | `/api/utility/{utility}/meters`                                   | meter list |
| POST   | `/api/utility/{utility}/meters`                                   | create a meter |
| GET    | `/api/utility/{utility}/meters/{id}`                              | single meter |
| PATCH  | `/api/utility/{utility}/meters/{id}`                              | update a meter |
| DELETE | `/api/utility/{utility}/meters/{id}`                              | delete a meter |
| POST   | `/api/utility/{utility}/meters/{id}/replace-device`               | meter swap (F2) |
| GET    | `/api/utility/{utility}/readings`                                 | readings list |
| POST   | `/api/utility/{utility}/readings`                                 | create a reading |
| PATCH  | `/api/utility/{utility}/readings/{id}`                            | update a reading |
| DELETE | `/api/utility/{utility}/readings/{id}`                            | delete a reading |
| POST   | `/api/utility/{utility}/meters/{id}/readings/import-csv`          | CSV bulk import of readings (F-06) |
| GET    | `/api/utility/{utility}/contracts`                                | contracts list |
| POST   | `/api/utility/{utility}/contracts`                                | create a contract |
| GET    | `/api/utility/{utility}/contracts/{id}`                           | single contract |
| PATCH  | `/api/utility/{utility}/contracts/{id}`                           | update a contract |
| DELETE | `/api/utility/{utility}/contracts/{id}`                           | delete a contract |
| GET    | `/api/utility/{utility}/consumption`                              | monthly consumption (utility-wide) |
| GET    | `/api/utility/{utility}/meters/{id}/consumption`                  | monthly consumption of a meter + anomalies + regressions |
| GET    | `/api/utility/{utility}/meters/{id}/contract-status`              | balance aggregation per contract |
| GET    | `/api/utility/{utility}/meters/{id}/forecast`                     | 12-month forecast |
| GET    | `/api/backup/export`                                              | full backup as JSON |
| POST   | `/api/backup/import`                                              | restore a backup (format 3.0+) |
| POST   | `/api/backup/snapshot`                                            | place a snapshot in the data directory |
| GET    | `/api/export/{utility}/monthly.csv`                               | monthly overview as CSV (F-07) |
| GET    | `/api/export/{utility}/readings.csv`                              | meter readings as CSV (F-07) |
| GET    | `/api/export/temperatures.csv`                                    | temperature series as CSV (F-07) |
| POST   | `/api/migration/v09/preview`                                      | analyse a v0.9.0 backup |
| POST   | `/api/migration/v09/import`                                       | adopt a v0.9.0 backup |

---

## Diagnostics

### `GET /api/diagnostics`

Delivers the system state and schema information.

**Response:**

```json
{
  "success": true,
  "data": {
    "app_version": "1.1.0",
    "schema_version": "1.0.0",
    "php_version": "8.4.0",
    "data_dir": "/var/www/energietracker/data",
    "data_dir_writable": true,
    "curl_available": true,
    "time_zone": "Europe/Berlin",
    "totals": {
      "gas":    { "meters": 1, "readings": 52, "contracts": 4 },
      "strom":  { "meters": 1, "readings": 22, "contracts": 4 },
      "wasser": { "meters": 1, "readings": 12, "contracts": 1 },
      "temperatures": 1131
    }
  }
}
```

---

## Utilities

### `GET /api/utilities`

Delivers the static configuration of the three utilities (the single source of truth
from `src/Config/Utilities.php`).

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "key": "gas",
      "label": "Gas",
      "icon": "🔥",
      "unit": "m³",
      "consumption_unit": "kWh",
      "unit_to_kwh": true,
      "conversion_setting": "gas_conversion_factor",
      "hgt_relevant": true,
      "color": "#ff7b2e",
      "co2_setting": "co2_gas",
      "default_meter_name": "Hauptzähler",
      "allow_multiple_meters": true
    },
    { "key": "strom", ... },
    { "key": "wasser", ... }
  ]
}
```

---

## Settings

### `GET /api/settings`

**Response:** all 20 settings keys as a flat object (see README → data model →
settings inventory).

### `PATCH /api/settings`

A partial update — only the passed keys are written, all others stay unchanged.

**Body:**

```json
{ "hdd_base_temp": 17, "forecast_model": "robust" }
```

**Response:** the complete updated settings object.

---

## Temperatures

### `GET /api/temperatures`

**Response:** a map of `YYYY-MM-DD` to `{avg, min, max}` (efficient for an O(1)
lookup, NOT as an array).

```json
{
  "success": true,
  "data": {
    "2023-04-03": {"avg": 3.4, "min": 2.4, "max": 18.1},
    "2023-04-04": {"avg": 3.4, "min": -0.4, "max": 8.9}
  }
}
```

### `POST /api/temperatures`

**Body:**

```json
{ "date": "2026-05-11", "avg": 18.5, "min": 12.0, "max": 24.3 }
```

### `POST /api/temperatures/import-csv`

**Body:** plain text (Content-Type `text/plain`), format `DD.MM.YYYY"avg"min"max`:

```
15.01.2024"4.2"-1.0"7.1
16.01.2024"3.8"0.5"6.9
```

**Response:**

```json
{ "success": true, "data": { "imported": 365, "skipped": 0 } }
```

### `POST /api/temperatures/sync-open-meteo`

Loads temperatures for the coordinates stored in the settings (2023–today from the
archive, plus 14 days of forecast).

**Body:** optional `{}`. The location data is taken from the settings.

**Response:**

```json
{
  "success": true,
  "data": {
    "imported": 1145,
    "archive_rows": 1131,
    "forecast_rows": 14,
    "archive_error": null,
    "forecast_error": null
  }
}
```

### `DELETE /api/temperatures/{date}`

Deletes a single day (`date` as `YYYY-MM-DD`).

---

## Meters & devices

### `GET /api/utility/{utility}/meters`

`{utility}` ∈ `gas | strom | wasser`.

**Response:** an array of meter objects (schema see README → data model → meter and
device).

### `POST /api/utility/{utility}/meters`

**Body:**

```json
{
  "name": "Garden intermediate meter",
  "icon": "💧",
  "notes": "Optional",
  "device": {
    "serial": "WZ-2021-AB123",
    "installed_on": "2021-04-15",
    "initial_counter": 0.0
  }
}
```

Is supplied automatically with a default device if none is given.

### `PATCH /api/utility/{utility}/meters/{id}`

**Body:** any subset of
`{ name, icon, active, notes, parent_meter_id, meter_group_id, external_id }`.

- `parent_meter_id` / `meter_group_id` — meter topology (F1006, from schema 1.2.0).
- `external_id` — a freely assignable alias for the Home Assistant integration
  (F1009, from schema 1.3.0). Unique per utility; allowed are 1–64 characters from
  `[A-Za-z0-9_.-]`. An empty value/`null` removes the alias.

### `DELETE /api/utility/{utility}/meters/{id}`

Deletes the meter **and all associated readings and contracts** (cascade).

### `POST /api/utility/{utility}/meters/{id}/replace-device`

F2 meter swap: closes the current device and creates a new one.

**Body:**

```json
{
  "removed_on": "2024-08-22",
  "final_counter": 18432.5,
  "reason": "Calibration deadline expired",
  "new_device": {
    "serial": "GAS-2024-CD8945",
    "installed_on": "2024-08-22",
    "initial_counter": 0.0
  }
}
```

---

## Readings

### `GET /api/utility/{utility}/readings`

The query parameter `meter_id` filters to one meter.

**Response:** an array of readings (schema see README).

### `POST /api/utility/{utility}/readings`

**Body:**

```json
{
  "meter_id": "m_gas_main",
  "date": "2026-05-11",
  "counter": 24890.5,
  "note": "",
  "is_estimated": false,
  "is_future": false
}
```

`device_id` is derived automatically from the current (= most recently installed,
not removed) device of the meter.

### `PATCH /api/utility/{utility}/readings/{id}`

**Body:** any subset of the reading fields.

### `DELETE /api/utility/{utility}/readings/{id}`

### `POST /api/utility/{utility}/meters/{id}/readings/import-csv`

CSV bulk import of readings into a specific meter (F-06, since v1.1.0). An already
existing reading on the same date is **overwritten**, not duplicated.

**Content-Type:** `text/plain` — the request body is the raw CSV text, **not** JSON.

**CSV format** (a header line is detected and skipped automatically):

```
datum;zaehlerstand;notiz;geschaetzt
01.02.2026;12345,6;Year start;false
2026-03-01;12567.8;;ja
```

- **Separator:** `;` preferred, `,` as a fallback.
- **Date:** `DD.MM.YYYY` or ISO `YYYY-MM-DD`.
- **Counter:** a German decimal comma and a thousands dot are recognised.
- **notiz** and **geschaetzt** are optional; `geschaetzt` accepts
  `true/false/1/0/ja/nein/x` (empty = `false`).

> Note: the CSV column headers (`datum`, `zaehlerstand`, `notiz`, `geschaetzt`) and
> the boolean keywords (`ja`/`nein`) are German, matching the app's source locale.

**Response:**

```json
{
  "success": true,
  "data": {
    "imported":    2,
    "overwritten": 0,
    "skipped":     0,
    "errors":      []
  }
}
```

`errors` contains, per unprocessable row, a German-language message with the line
number. The import logic sits in the source-agnostic `ReadingImportService` —
external data sources such as the [Home Assistant integration](HOME-ASSISTANT.md)
(`POST /api/ingest`) reuse the same core without CSV parsing.

---

## Contracts

### `GET /api/utility/{utility}/contracts`

The query parameter `meter_id` filters to one meter.

### `POST /api/utility/{utility}/contracts`

**Body for gas / electricity:**

```json
{
  "meter_id": "m_gas_main",
  "provider": "Vattenfall",
  "tariff_name": "Easy Gas 12",
  "start": "2025-01-01",
  "end":   "2025-12-31",
  "notes": "",
  "working_prices":   [{"from": "2025-01-01", "ct_per_kwh": 9.2}],
  "base_prices":      [{"from": "2025-01-01", "eur_per_month": 10.50}],
  "advance_payments": [{"from": "2025-01-01", "amount_eur": 145.00}],
  "bonuses":          [{"credit_date": "2025-06-30", "amount_eur": 100, "type": "neukunde", "label": "New-customer bonus"}]
}
```

**Body for water** (three component blocks, since v1.0.3):

```json
{
  "meter_id": "m_wasser_haupt",
  "provider": "Municipal waterworks Leipzig",
  "tariff_name": "Drinking, waste and rainwater 2025",
  "start": "2025-01-01",
  "end":   "2026-12-31",
  "notes": "",
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
    "rates": [{"from": "2025-01-01", "eur_per_m2_year": 1.50, "versiegelte_flaeche_m2": 120}]
  },
  "advance_payments": [{"from": "2025-01-01", "amount_eur": 72.00}],
  "bonuses": []
}
```

The water component keys (`trinkwasser` = drinking water, `schmutzwasser` = waste
water, `niederschlagswasser` = rainwater, `versiegelte_flaeche_m2` = sealed area)
are part of the data model and kept literally. `schmutzwasser.basis` is
`"trinkwasser"` (default, waste-water quantity = drinking-water consumption) or
`"separater_zaehler"` (with `separater_zaehler_meter_id` as a reference to a second
meter — since v1.1.0 the monthly m³ of the referenced meter is used in this case; the
historical evaluation calculates with it correctly, the forecast look-ahead uses the
drinking-water volume in a simplified way).

Strict F4 validation: half-filled sub-rows (e.g. `{from: "2025-01-01"}` without
`ct_per_kwh`) lead to HTTP 400 with a precise error message like
`"working_prices-Eintrag #2: ct_per_kwh fehlt"`.

### `PATCH /api/utility/{utility}/contracts/{id}`

### `DELETE /api/utility/{utility}/contracts/{id}`

---

## Consumption aggregation

### `GET /api/utility/{utility}/consumption`

Monthly consumption **utility-wide** (aggregated over all meters).

**Query parameters:**
- `hdd_base` (optional, float) — overrides the HDD base temperature for this one
  response

**Response:**

```json
{
  "success": true,
  "data": {
    "meters": [
      { "meter": {...}, "monthly": [ ... ] }
    ],
    "monthly_total": [
      {
        "ym": "2025-04",
        "year": 2025, "month": 4,
        "kwh": 1840.5, "m3": 160.0, "cost": 169.33,
        "days": 30,
        "avg_temp": 9.2, "min_temp": 2.1, "max_temp": 18.4, "hdd": 174,
        "ma3": 2210.0, "ma6": 2540.0, "ma12": 2620.0
      }
    ]
  }
}
```

### `GET /api/utility/{utility}/meters/{id}/consumption`

Monthly consumption of a **single meter** plus anomalies and regression models.

**Response:**

```json
{
  "success": true,
  "data": {
    "meter": { ... },
    "monthly": [
      {
        "ym": "2025-04", "year": 2025, "month": 4,
        "days": 30, "kwh": 1840.5, "m3": 160.0,
        "kwh_per_day": 61.4, "avg_temp": 9.2, "hdd": 174,
        "cost": 169.33,
        "contract_id": "c_gas_003",
        "working_price_ct": 9.2,
        "base_price_eur": 10.5,
        "advance_eur": 145.0,
        "bonus_eur": 0.0,
        "kwh_cost": 158.83,
        "monthly_balance": 24.33,
        "cumulative_balance": -98.50,
        "co2_kg": 369.9,
        "ma3": 2210.0, "ma6": 2540.0
      }
    ],
    "anomalies": [
      { "ym": "2025-02", "actual": 3192, "expected": 3653, "z": -2.02 }
    ],
    "regressions": {
      "linear":     { "model": "linear",     "valid": true, "r2": 0.9668, "a": 11.86, "b": 1023.0, "n": 30 },
      "polynomial": { "model": "polynomial", "valid": true, "r2": 0.9682, "a": 0.012, "b": 8.3, "c": 1180.0, "n": 30 },
      "robust":     { "model": "robust",     "valid": true, "r2": 0.9667, "a": 11.84, "b": 1025.0, "n": 30 },
      "segmented":  { "model": "segmented",  "valid": true, "r2": 0.9669, "split": 50, "heat": {...}, "base": {...} }
    }
  }
}
```

With `hgt_relevant: false` (water), `regressions` is an empty object.

### `GET /api/utility/{utility}/meters/{id}/contract-status`

Balance aggregation per contract — the data source for the *current contract balance*
card and the *contracts & advances* table in the UI.

**Response:**

```json
{
  "success": true,
  "data": {
    "contracts": [
      {
        "contract_id": "c_gas_003",
        "provider": "Vattenfall",
        "tariff_name": "Easy Gas 12",
        "start": "2025-01-01",
        "end":   "2025-12-31",
        "effective_end": "2025-12-31",
        "is_current": false,
        "is_past":    true,
        "is_future":  false,
        "is_open_ended": false,
        "current_working_price_ct": 9.2,
        "current_base_price_eur":   10.5,
        "current_advance_amount":   145.0,
        "months_actual":      12,
        "actual_kwh":         18432.5,
        "actual_kwh_cost":    1695.79,
        "actual_base_total":  126.0,
        "actual_bonus_total": 100.0,
        "actual_cost":        1721.79,
        "advance_paid":       1740.0,
        "current_balance":    -18.21,
        "projected_end_balance": -18.21,
        "verdict": "Erstattung",
        "days_until_end": 231,
        "should_remind":  false,
        "remind_stage":   0
      }
    ]
  }
}
```

`verdict` is `Nachzahlung` (back-payment) when `projected_end_balance > 5`,
`Erstattung` (refund) when `< -5`, otherwise `Ausgeglichen` (balanced). The values
are emitted in German.

`effective_end` is, for contracts with a maintained end, identical to `end`; for
open contracts (`end: null`, `is_open_ended: true`) it is the next billing date of
the utility (settings `billing_cycle_anchor_<utility>`, default `01-01`) — the
`projected_end_balance` is projected up to there (F-03, since v1.1.0).

**Contract-end reminder** (F-05, since v1.1.0) — three additional fields per
contract:

- `days_until_end` — days until the contract end, signed (negative = the end is in
  the past); `null` for open contracts.
- `remind_stage` — `0` (no reminder) to `3` (urgent). The thresholds are
  configurable as settings keys `contract_remind_days_1|2|3` (default 90 / 30 / 1
  days).
- `should_remind` — `true` as soon as `remind_stage > 0`.

**Water-specific response** (since v1.0.3): each contract object additionally
contains `actual_m3` and `components` with the breakdown of the three components:

```json
{
  "contract_id": "c_wasser_...",
  "actual_m3": 187.5,
  "current_balance": +52.28,
  "projected_end_balance": +85.40,
  "verdict": "Nachzahlung",
  "components": {
    "trinkwasser": {
      "working_cost": 482.69,
      "base_cost": 102.00,
      "total": 584.69,
      "current_ct_per_m3": 265.0,
      "current_eur_per_month": 8.50
    },
    "schmutzwasser": {
      "total": 424.59,
      "current_ct_per_m3": 315.0,
      "basis": "trinkwasser"
    },
    "niederschlagswasser": {
      "total": 225.00,
      "current_eur_per_m2_year": 1.50,
      "current_versiegelte_m2": 120,
      "current_monthly": 15.00
    }
  }
}
```

Per month, `meterConsumption` for water delivers each component separately under
`monthly[].trinkwasser`, `.schmutzwasser`, `.niederschlagswasser`.

### `GET /api/utility/{utility}/meters/{id}/forecast`

A 12-month forecast per meter. For HDD-relevant utilities (gas) an R²-weighted mix of
the regression model and the seasonal profile; for non-HDD-relevant ones
(electricity, water) a pure seasonal profile.

The **cost forecast is contract-based** (F-02, since v1.1.0): for each forecast month
the then-active contract is resolved and the working and base price valid for that
month is used from the price history.

**Query parameters** (all optional):
- `model` — `linear | polynomial | robust | segmented` (default = setting
  `forecast_model`)
- `temp_offset` — °C shift of the HDD assumption (what-if)
- `price_factor` — multiplier on the working prices (what-if)
- `forecast_months` — horizon in months (default = setting `forecast_months`)

**Response:**

```json
{
  "success": true,
  "data": {
    "valid": true,
    "utility": "gas",
    "meter_id": "m_gas_main",
    "blend_weight": 0.75,
    "last_price_ct": 8.2,
    "regression": { "model": "linear", "valid": true, "r2": 0.967, "a": 11.86, "b": 1023.0, "n": 30 },
    "historical": [ ... month series as in meterConsumption ... ],
    "forecast": [
      {
        "ym": "2026-06", "year": 2026, "month": 6,
        "kwh": 540,
        "hdd_estimated": 12.4,
        "cost_estimated": 47.79,
        "advance_estimated": 130.0,
        "balance_running": -216.71,
        "working_price_ct": 8.2,
        "contract_id": "c_gas_004",
        "method": "blend(reg=0.75, seasonal=0.25)"
      }
    ],
    "options": { "temp_offset": 0, "price_factor": 1, "forecast_months": 12 }
  }
}
```

Per forecast month:
- `kwh` or `m3` — the forecast consumption (the field name depends on the utility's
  `consumption_unit`).
- `cost_estimated` — working price × quantity + base price − known bonuses.
- `advance_estimated` — the advance valid for the month, or `null` if no
  contract/advance is maintained.
- `balance_running` — cumulative `cost_estimated − advance_estimated`. Negative =
  credit, positive = back-payment; the value of the last month is the projected
  balance at the end of the horizon.
- `working_price_ct` — the applied working price (the headline tariff).
- `contract_id` — the active contract, or `null` (then fallback to `last_price_ct`).
- `method` — `seasonal_only` or `blend(reg=…, seasonal=…)`.

Future bonuses are **not** carried forward — only bonuses maintained in the contract
with a credit date in the forecast period are included. With too little history
(< 6 months) the response is `{ "valid": false, "reason": "…" }`.

---

## Backup & restore

### `GET /api/backup/export`

A complete backup in the current format. The frontend can save the response directly
as a JSON file.

**Response (shortened):**

```json
{
  "success": true,
  "data": {
    "backup_version": "3.0",
    "app_version": "1.1.0",
    "exported_at": "2026-05-11T14:00:00+02:00",
    "meta": { ... },
    "temperatures": { ... },
    "settings": { ... },
    "utilities": {
      "gas":    { "meters": [...], "readings": [...], "contracts": [...] },
      "strom":  { ... },
      "wasser": { ... }
    }
  }
}
```

### `POST /api/backup/import`

Restores a backup. Only formats `backup_version: "3.0"` or higher are accepted — for
older formats the migrator (see below) is responsible.

**Body:** the `data` object from the export, i.e. top-level with `backup_version`,
`temperatures`, `settings`, `utilities`, …

**Response:**

```json
{
  "success": true,
  "data": {
    "temperatures": 1131,
    "settings": 20,
    "utilities": {
      "gas":    { "meters": 1, "readings": 52, "contracts": 4 },
      "strom":  { ... },
      "wasser": { ... }
    }
  }
}
```

### `POST /api/backup/snapshot`

Places a snapshot under `data/backups/backup_YYYY-MM-DD_HHMMSS.json`.

**Response:** `{ "success": true, "data": { "path": "backup_2026-05-11_140000.json" } }`

---

## CSV export

A tabular export for spreadsheets (F-07, since v1.1.0). Three datasets, each as a
**file download** — the response is **not** JSON but `text/csv` with
`Content-Disposition: attachment`. Format: semicolon-separated, UTF-8 with BOM (Excel
recognises the encoding), CRLF line endings, German decimal comma, ISO dates.

Supplements the complete JSON backup — for a re-importable backup still use
`GET /api/backup/export`.

### `GET /api/export/{utility}/monthly.csv`

Monthly aggregates of a utility over all meters: month, days, consumption, costs,
advance, monthly balance, cumulative balance, avg temperature, HDD, CO₂.

### `GET /api/export/{utility}/readings.csv`

All raw readings of a utility, one row per reading: meter ID, meter name, device ID,
date, counter, price, note, estimated flag, future flag.

### `GET /api/export/temperatures.csv`

The temperature series as daily values: date, avg, min, max.

---

## Migration from v0.9.0

A two-stage flow — preview (no write) followed by import with a mode choice. For a
detailed guide see [`MIGRATION-FROM-V090.md`](MIGRATION-FROM-V090.md).

### `POST /api/migration/v09/preview`

**Body:**

```json
{ "backup": <v0.9.0 backup object> }
```

**Response:**

```json
{
  "success": true,
  "data": {
    "ok": true,
    "legacy_version": "2.1",
    "translated": { ... fully translated content ... },
    "report": {
      "readings":     { "gas": 52, "strom": 22, "wasser": 0 },
      "contracts":    { "gas": 8,  "strom": 4,  "wasser": 0 },
      "temperatures": 1131,
      "settings":     20,
      "warnings":     [ "v0.9.0 has no water — ..." ],
      "device_replacement_candidates": [
        { "utility": "strom", "reading_id": "...", "date": "2020-07-22", "counter": 6, "comment": "Zählerwechsel", "reason": "..." }
      ]
    }
  }
}
```

> Note: `device_replacement_candidates` are detected by scanning the old reading
> comments for German keywords such as `Zählerwechsel` (meter change) — the
> migrator matches the literal source text, so these strings are kept as-is.

### `POST /api/migration/v09/import`

**Body:**

```json
{ "translated": <preview.data.translated>, "mode": "replace" }
```

`mode` ∈ `"replace" | "merge"`.

**Response:**

```json
{
  "success": true,
  "data": {
    "mode": "replace",
    "snapshot": "backup_2026-05-11_113000.json",
    "written": {
      "gas":    { "meters": 1, "readings": 52, "contracts": 8 },
      "strom":  { "meters": 1, "readings": 22, "contracts": 4 },
      "wasser": { "meters": 1, "readings": 0,  "contracts": 0 }
    }
  }
}
```

In `merge` mode, each utility additionally contains a `skipped` field with the counts
of entries skipped due to an ID collision.

---

## Home Assistant integration (F1009, from v1.9.0)

> **Note for Home Assistant users:** a faulty guide circulates in forums with
> `POST /api.php` and fields `action`/`value`/`timestamp`. That is **wrong**. The
> correct, official interface is described below; the detailed step-by-step guide is
> in [`docs/HOME-ASSISTANT.md`](HOME-ASSISTANT.md).

### Authentication model (opt-in)

By default the API is reachable **without a token** (local network). As soon as a
token has been created, the ingest endpoint requires an `Authorization: Bearer
<token>` header. All other routes stay unchanged.

### `GET /api/auth/token`

Status (never the token itself):

```json
{ "success": true, "data": { "enabled": true, "created_at": "2026-06-01T12:00:00+02:00" } }
```

### `POST /api/auth/token`

Creates a new token (replaces an existing one). The plaintext token is returned
**only in this response** — afterwards only its SHA-256 hash is stored (in
`data/auth.json`, not in `settings.json`; excluded from the backup).

```json
{ "success": true, "data": { "token": "et_…48hex…", "created_at": "…", "hint": "…" } }
```

### `DELETE /api/auth/token`

Revokes the token → the API is in open mode again.

### `POST /api/ingest`

An idempotent push endpoint for external data suppliers (Home Assistant).
**Upsert-by-date:** if a reading already exists for the meter on the same date, it is
updated; otherwise created. So a repeated push on the same day produces **no**
duplicates.

**Header:** `Authorization: Bearer <token>` (only if a token is set).

**Body:**

```json
{
  "utility": "strom",
  "meter": "stromzaehler_haus",
  "value": 12345.6,
  "date": "2026-06-01"
}
```

- `utility` — the utility (`gas|strom|wasser|fernwaerme`; delivery utilities heating
  oil/pellets are rejected — they use deliveries instead of readings).
- `meter` — the **alias** (`external_id`) **or** the internal meter ID. Alias first.
- `value` — the counter (a number). The alias `counter` is also accepted.
- `date` — optional, default today. Accepts `YYYY-MM-DD`; a full ISO timestamp (e.g.
  HA `now().isoformat()`) is truncated to the date.

**Response** (`201` on new, `200` on update):

```json
{
  "success": true,
  "data": {
    "status": "created",
    "utility": "strom",
    "meter_id": "m_strom_main",
    "date": "2026-06-01",
    "counter": 12345.6,
    "reading_id": "20260601-ab12cd34"
  }
}
```

**Errors:** `401` (token needed/wrong), `400` (unknown utility, meter not found,
no/invalid value, delivery utility).
