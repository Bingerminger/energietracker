# API reference

**English** · [Deutsch](../../technical/03-api-reference.md)

[← Architecture](02-architecture.md) · [Compendium index](../README.md)

All endpoints under `/api/…`. A uniform response envelope:

```json
{ "success": true,  "data": … }
{ "success": false, "error": "message", "detail": … }
```

`{utility}` is one of: `gas`, `strom`, `wasser`, `fernwaerme`, `heizoel`,
`pellets`, `pv_einspeisung`, `pv_erzeugung`. As of: **68 routes**, v1.9.2.

> A detailed variant of this reference (with request/response examples for **all**
> endpoints) is additionally available at [`docs/API.md`](../API.md). This document
> is the compact overview in the compendium.

---

## 1. Full route overview

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/health` | health check (version, schema, write permissions, migrations) |
| GET | `/api/diagnostics` | system status, write permissions, schema |
| GET | `/api/utilities` | list of utilities + configuration |
| GET | `/api/settings` | settings |
| PATCH | `/api/settings` | change settings |
| GET | `/api/temperatures` | daily temperatures (map) |
| POST | `/api/temperatures` | upsert a day |
| POST | `/api/temperatures/import-csv` | CSV import |
| POST | `/api/temperatures/sync-open-meteo` | Open-Meteo sync |
| DELETE | `/api/temperatures/{date}` | delete a day |
| GET | `/api/utility/{u}/meters` | meters/tanks |
| POST | `/api/utility/{u}/meters` | create |
| GET | `/api/utility/{u}/meters/{id}` | single |
| PATCH | `/api/utility/{u}/meters/{id}` | change |
| DELETE | `/api/utility/{u}/meters/{id}` | delete |
| POST | `/api/utility/{u}/meters/{id}/replace-device` | meter swap |
| GET | `/api/utility/{u}/meter-groups` | meter groups (F1006) |
| POST | `/api/utility/{u}/meter-groups` | create a group |
| POST | `/api/utility/{u}/meter-groups/merge` | merge wizard: bundle several meters |
| PATCH | `/api/utility/{u}/meter-groups/{groupId}` | rename a group |
| DELETE | `/api/utility/{u}/meter-groups/{groupId}` | dissolve a group (members remain) |
| GET | `/api/utility/{u}/readings` | readings |
| POST | `/api/utility/{u}/readings` | create |
| PATCH | `/api/utility/{u}/readings/{id}` | change |
| DELETE | `/api/utility/{u}/readings/{id}` | delete |
| POST | `/api/utility/{u}/meters/{id}/readings/import-csv` | CSV bulk import |
| **GET** | **`/api/readings-overview`** | **all active cumulative meters + last reading (F1004, v1.6.0)** |
| GET | `/api/utility/{u}/deliveries` | deliveries (heating oil/pellets) |
| POST | `/api/utility/{u}/deliveries` | create |
| PATCH | `/api/utility/{u}/deliveries/{id}` | change |
| DELETE | `/api/utility/{u}/deliveries/{id}` | delete |
| GET | `/api/utility/{u}/meters/{id}/stock-history` | tank stock curve |
| GET | `/api/utility/{u}/contracts` | contracts |
| POST | `/api/utility/{u}/contracts` | create |
| GET | `/api/utility/{u}/contracts/{id}` | single |
| PATCH | `/api/utility/{u}/contracts/{id}` | change |
| DELETE | `/api/utility/{u}/contracts/{id}` | delete |
| GET | `/api/utility/{u}/consumption` | monthly consumption (utility-wide) |
| GET | `/api/utility/{u}/meters/{id}/consumption` | consumption + anomalies + regressions |
| GET | `/api/utility/{u}/meters/{id}/contract-status` | balance per contract |
| GET | `/api/utility/{u}/meters/{id}/forecast` | 12-month forecast |
| GET | `/api/utility/{u}/meters/{id}/tariff-comparison` | tariff comparison real vs. shadow (retrospective) |
| GET | `/api/utility/{u}/meters/{id}/tariff-switch` | switching decision from the switch date; optional `?switch_date=YYYY-MM-DD` |
| GET | `/api/benchmarks/efficiency` | efficiency class per heat source |
| GET | `/api/recommendations` | statistical recommendations |
| POST | `/api/recommendations/{id}/dismiss` | hide a recommendation |
| GET | `/api/reminders` | appointments + due status |
| POST | `/api/reminders` | create |
| PATCH | `/api/reminders/{id}` | change |
| DELETE | `/api/reminders/{id}` | delete |
| POST | `/api/reminders/{id}/done` | done, roll the recurrence forward |
| GET | `/api/reports/yearly.pdf` | PDF annual report (file download) |
| GET | `/api/export/{u}/monthly.csv` | monthly aggregates as CSV |
| GET | `/api/export/{u}/readings.csv` | readings as CSV (cumulative) |
| GET | `/api/export/{u}/deliveries.csv` | **v1.4.2** deliveries as CSV (heating oil/pellets) |
| GET | `/api/export/temperatures.csv` | temperature series as CSV |
| GET | `/api/backup/export` | full backup JSON |
| POST | `/api/backup/import` | restore a backup |
| POST | `/api/backup/snapshot` | place a snapshot |
| POST | `/api/migration/v09/preview` | analyse a v0.9.0 backup |
| POST | `/api/migration/v09/import` | adopt a v0.9.0 backup |
| GET | `/api/strom-saldo` | electricity balance (import − PV feed-in), F1005 |
| GET | `/api/pv-summary` | PV self-consumption + self-sufficiency rate, F1005 |
| GET | `/api/demo/status` | demo data available/store empty? (F1007) |
| POST | `/api/demo/import` | load the demo dataset (F1007) |
| GET | `/api/auth/token` | API token status (never the token itself), F1009 |
| POST | `/api/auth/token` | generate a token (one-time plaintext), F1009 |
| DELETE | `/api/auth/token` | revoke the token → API open again, F1009 |
| **POST** | **`/api/ingest`** | **idempotent meter-reading push for Home Assistant (F1009)** |

---

## 2. Selected endpoints in detail

### `GET /api/readings-overview` *(F1004, v1.6.0)*

The aggregate endpoint for the central meter-reading capture
(`#/zaehlerstaende`). Delivers in one round trip all active meters of the
cumulative utilities (gas/electricity/water/district heating) plus each one's last
real (non-planned) reading as a validation baseline. Delivery utilities (heating
oil/pellets) are excluded — there are no meter readings there, but deliveries.

```json
{
  "success": true,
  "data": {
    "rows": [
      {
        "utility": "gas",
        "utility_label": "Gas",
        "utility_icon": "🔥",
        "consumption_unit": "kWh",
        "color": "#f59e0b",
        "meter_id": "m_gas_main",
        "meter_name": "Main meter gas",
        "meter_icon": "🔥",
        "meter_notes": "Cellar",
        "active_device_id": "d_gas_1",
        "last_reading": {
          "date": "2026-04-15",
          "counter": 12345.67,
          "is_estimated": false
        },
        "expected_next_min": 12345.67
      }
    ]
  }
}
```

`expected_next_min` is the value against which the frontend validation warns about
a backward meter reading (not hard-blocked — a meter swap is legitimate). Saving is
**not** done via this endpoint, but per row via the existing route
`POST /api/utility/{u}/readings`.

### `GET /api/utility/{u}/meters/{id}/consumption`

Monthly aggregates of a meter including regressions and anomalies. Fields per month
include: `ym`, `days`, `kwh` *or* `m3`, `cost`, `avg_temp`, `hdd`, `co2_kg`,
`advance_eur`, `monthly_balance`, `cumulative_balance`; for HDD-relevant utilities
additionally `expected_hgt`, `weather_adjusted`, `delta_pct` as well as smoothings
(MA-3/MA-6). `regressions` contains all five models with `r2`/`valid`.

**Since v2.4.0 (F1011)** every month additionally carries `pre_baseline`
(`true` = the month lies before the meter's analysis baseline cut-off). Such
months stay in the response but feed **no** evaluation: `expected_hgt` and
`delta_pct` are `null` for them and the regressions leave them out.
`weather_adjusted` is still computed for them — the value is independent of the
building and carries the before/after comparison.

Two new top-level fields go with it:

```json
"baseline": {
  "active_from": "2021-09-01",     // null = no cut-off in effect
  "active_label": "Loft insulation",
  "first_month": "2021-10",        // first full month after it
  "events": [ { "date": "2021-09-01", "label": "Loft insulation" } ],
  "months_total": 144, "months_after": 58, "points_after": 41,
  "limits": [                       // what cannot be computed right now
    { "key": "weather_adjustment", "need": 12, "have": 144, "ok": true },
    { "key": "regression",         "need": 8,  "have": 41,  "ok": true },
    { "key": "anomalies",          "need": 5,  "have": 58,  "ok": true }
  ]
},
"baseline_comparison": {            // null when one epoch is too thin
  "before": { "slope": 0.42, "base": 11.8, "r2": 0.97, "points": 63 },
  "after":  { "slope": 0.28, "base": 12.1, "r2": 0.98, "points": 41 },
  "delta_pct": -33.3, "unit": "kWh"
}
```

`slope` is consumption **per degree day** and therefore already
weather-corrected; `delta_pct` is the effect of the measure. `limits` is filled
in **without** a cut-off too — a history that is simply too short gets explained
instead of an evaluation silently disappearing.

### `GET /api/utility/{u}/meters/{id}/stock-history` *(heating oil/pellets only)*

```json
{ "success": true, "data": {
  "capacity": 3000, "capacity_unit": "L", "initial_stock": 2400,
  "days": [ { "date": "2023-01-01", "stock": 2389.4,
              "delivery": 0, "consumption": 10.6 }, … ]
}}
```

The stock is a **calibrated model estimate** (initial stock + deliveries −
HDD-weighted consumption, rate from the closed delivery intervals), **not** a tank
gauging. Since v1.4.0 the model no longer forces a final stock of 0. Details:
[Heating oil](../functional/05-heizoel.md).

### `GET /api/benchmarks/efficiency?year=YYYY`

Since **v1.4.0** per heat source:

```json
{ "success": true, "data": {
  "year": 2024, "wohnflaeche_m2": 100,
  "per_source": [
    { "utility": "gas", "label": "Gas", "kwh": 10685.8,
      "kwh_per_m2": 106.9, "class": "D" }
  ],
  "primary":  { "utility": "gas", "label": "Gas", "kwh": 10685.8,
                "kwh_per_m2": 106.9, "class": "D" },
  "combined": { "kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D" },
  "thresholds": { "A+": 30, "A": 50, "…": 0 },
  "note": null,
  "total_kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D",
  "breakdown": { "gas": 10685.8 }
}}
```

`per_source` lists each heating-energy utility (gas, district heating, heating oil,
pellets) **individually** — a house really mostly heats with one source; summing
several would yield a nonsensical class. `primary` = the highest-consuming source,
`combined` = the sum (only meaningful with deliberately combined heating operation,
`note` points to it). The top-level fields are backward-compatible aliases and have
reflected the **primary** source since v1.4.0.

### `GET /api/export/{u}/deliveries.csv` *(v1.4.2, heating oil/pellets)*

CSV with one row per delivery: `tank/store ID`, `tank/store`, `date`,
`quantity (L|kg)`, `price (ct/L|kg)`, `total (EUR)`, `supplier`, `note`, `planned`.
Semicolon-separated, UTF-8 BOM, German decimal comma. For cumulative utilities use
`readings.csv` instead.

### `POST /api/utility/{u}/deliveries`

Required: `meter_id`, `date`, `quantity` (> 0). Optional `unit_price_cents`
**or** `total_eur`, `supplier`, `note`, `is_planned`. **Since v1.4.2** `total_eur`
takes precedence over `unit_price_cents` — the invoice amount is the figure
actually paid (incl. the delivery fee/rebate); the effective unit price is derived
from it (`total_eur · 100 / quantity`).

### `GET /api/reports/yearly.pdf?year=YYYY`

Delivers **no JSON**, but directly a PDF (`Content-Type: application/pdf`). Since
**v1.4.2** without the former axis-less mini chart — instead a figures bar (annual
consumption, avg/month, total costs, strongest/weakest month) plus the monthly
table. Generated by the built-in, dependency-free PDF writer.

### `POST /api/ingest` *(F1009, v1.9.0 — Home Assistant)*

An idempotent push intake for external data suppliers. **Upsert per (meter,
date):** a renewed push on the same day updates the value instead of creating a
second reading.

```jsonc
// Header (only if a token is set): Authorization: Bearer <token>
{
  "utility": "strom",
  "meter":   "stromzaehler_haus",  // external_id alias OR internal meter ID
  "value":   12345.6,              // alias: "counter"
  "date":    "2026-06-02"          // optional, default today; an ISO stamp is truncated
}
```

Response `201` (new) resp. `200` (updated) with
`{ status: "created"|"updated", utility, meter_id, date, counter, reading_id }`.
Errors: `401` (token needed/wrong), `400` (unknown utility/meter, no numeric value,
delivery utility heating oil/pellets).

### `GET|POST|DELETE /api/auth/token` *(F1009)*

Management of the **opt-in** API token. Without a token the API is open (LAN mode);
as soon as a token exists, `/api/ingest` requires a bearer header. The token is
stored only as a SHA-256 hash in `data/auth.json` and returned in plaintext
**once** on creation.

> Full examples for auth + ingest and the step-by-step setup in Home Assistant:
> [`docs/HOME-ASSISTANT.md`](../HOME-ASSISTANT.md) and [`docs/API.md`](../API.md).

### Meter groups *(F1006, v1.8.0)*

`GET/POST /api/utility/{u}/meter-groups`, `PATCH/DELETE …/{groupId}` as well as
`POST …/meter-groups/merge` (merge wizard). Membership is set via `meter_group_id`
on the meter, not in the group. Submeters are linked via `parent_meter_id` on the
meter (see [data model](04-data-model.md) and
[meter topology](../functional/13-meter-topologie.md)).

---

[← Architecture](02-architecture.md) ·
[Data model →](04-data-model.md)
