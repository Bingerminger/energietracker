# API reference

**English** · [Deutsch](../../technical/03-api-reference.md)

[← Architecture](02-architecture.md) · [Compendium index](../README.md)

All endpoints under `/api/…`. A uniform response envelope:

```json
{ "success": true,  "data": … }
{ "success": false, "error": "message in the language of the request", "code": "errors.reading.dateInvalid" }
```

`{utility}` is one of: `gas`, `strom`, `wasser`, `fernwaerme`, `heizoel`,
`pellets`, `pv_einspeisung`, `pv_erzeugung`. As of: **83 routes**, v2.7.0 —
`ReleaseConsistencyTest` checks that every registered route appears in the
table below (German and English).

> Detailed request/response examples for the most-used endpoints are in
> [`docs/API.md`](../API.md). **This** document is authoritative for paths and
> fields.

### Status codes of all endpoints

| Code | When |
|------|------|
| `400` | Invalid input. Since v2.5.3 on every write path: a date that is not a calendar date (`2026-02-30`, text), or an amount/meter reading that is not a number. Up to v2.5.2 both were stored. Since v2.6.0 also query parameters outside their range (forecast, annual report, temperature sync). |
| `401` | `/api/ingest` with a token set but a missing or wrong bearer header. *(v2.6.0)* With sign-in switched on: any non-public route without a session or API key (`errors.auth.required`); wrong password. |
| `403` | *(v2.5.3)* Writing request (`POST`/`PUT`/`PATCH`/`DELETE`) from the browser of a **foreign** website — checked via `Sec-Fetch-Site`, falling back to `Origin` against `Host`. Requests without these headers (Home Assistant, curl, scripts) are not affected. *(v2.6.0)* Writing request with a read-only key (`errors.auth.readOnlyKey`). |
| `404` | Unknown route or unknown record. Since v2.6.0 consistently also for records addressed in the URL (up to v2.5.3 partly 400). |
| `405` | *(v2.6.0)* Known path, wrong method — with an `Allow` header. `HEAD` is answered like `GET`, `OPTIONS` with `204` and `Allow`. Up to v2.5.3: `404`. |
| `409` | *(v2.6.0)* The safety snapshot before an import/restore failed (`errors.backup.snapshotFailed`) — possible anyway with `?allow_without_snapshot=1`. Password or sign-in mode are fixed by the environment. |
| `421` | *(v2.6.0)* Host name not in `ET_ALLOWED_HOSTS` (only when set; IP addresses and `localhost` are always allowed). |
| `429` | *(v2.6.0)* Sign-in locked for 5 minutes after five failures within 15 minutes (`errors.auth.locked`). |
| `503` | *(v2.5.3)* A data file is corrupt (not valid JSON). The file stays untouched; a quarantine copy `<file>.corrupt-<checksum>` is placed next to it. Up to v2.5.2 it was read as empty and overwritten on the next write. *(v2.6.0)* The data comes from a **newer** version (e.g. after rolling back the image tag): all routes except `/api/health`, nothing is written (`errors.storage.dataTooNew`). `/api/health` itself answers `503` when `status` = `error`. |
| `500` | Unexpected error. Since v2.6.0 with `error_id`; the server log holds the details under the same ID. |

Since v2.5.3 writing requests run one after another (lock on
`data/.write.lock`): a Home Assistant push during an edit no longer loses a
change.

### Error codes *(v2.6.0)*

Every error response carries `code` — the catalogue key of the message
(`errors.reading.dateInvalid`, `errors.backup.invalid` …) or, for general HTTP
errors, `errors.http.*` (`badRequest`, `unauthorized`, `forbidden`,
`notFound`, `methodNotAllowed`, `unavailable`, `internal`). `error` is
translated into the language of the request (`Accept-Language`) and may change
between versions — **scripts evaluate `code`, never the message text.**

`detail` with file, line and exception type is only returned with
`ET_DEBUG=1` (up to v2.5.3 on every error, including absolute paths). Domain
details are still always returned, such as the findings of a faulty backup in
`detail.problems`.

### Sign-in *(v2.6.0, opt-in)*

Without sign-in (the default) the API stays open as before. When it is switched
on (Settings → "Sign-in & access", or `ET_AUTH`), **one** of these applies to
every route:

| Way | For | Passed as |
|---|---|---|
| Session | the browser | cookie `et_session` (HttpOnly, SameSite=Strict, 30 days) after `POST /api/session` |
| API key | scripts, other programs | `Authorization: Bearer etk_…`; scope `read` (`GET` only) or `admin` |
| Proxy | behind Authelia, Authentik or similar | `ET_AUTH=proxy`; user from `Remote-User`/`X-Forwarded-User`, only from addresses in `ET_TRUSTED_PROXIES` |

Reachable without sign-in: `POST /api/ingest` (own token, **mandatory** once
sign-in is on), `GET|HEAD /api/health` (then only `{status, version}`),
`GET|POST|DELETE /api/session` and `OPTIONS`. Details:
[Security](08-security.md).

### Stability promise *(v2.6.0)*

Whoever builds on the API — Home Assistant, scripts, own evaluations — needs a
promise about what may change. Three classes:

| Class | Scope | Promise |
|---|---|---|
| **A — interfaces for other systems** | `POST /api/ingest`, `GET /api/health`, backup format 3.0 (`/api/backup/export`, `/api/backup/import`), CSV exports and imports, master data (`meters`, `readings`, `contracts`, `deliveries`, `reminders`, `settings`, `temperatures`), error envelope with `code` | Additive changes only. Renaming or removing only with a **major version**, announced at least one minor version earlier in the CHANGELOG under "Deprecated". Old field names stay valid as aliases. |
| **B — evaluations** | consumption, balance, forecast, tariff comparison/switch, bill check, efficiency, recommendations, PV/balance, `readings-overview` | Documented fields keep their name and meaning; new ones are added. **Values** may change when a calculation is corrected — the CHANGELOG says so. |
| **C — user interface** | `session`, `auth/token`, `auth/keys`, `backup/snapshots`, `diagnostics`, `demo`, `migration/v09`, `countries` | Built for the app's own interface; changes are possible but listed in the CHANGELOG. |

Reason: v2.0.0 silently switched `verdict` from "Nachzahlung/Erstattung" to keys
(`surcharge`/`refund`/`balanced`) — without notice. That must not happen again.

---

## 1. Full route overview

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/health` | health check: `status` ok/degraded/error, checks, last ingest (HTTP 503 on `error`); also `HEAD` |
| GET | `/api/session` | sign-in mode, signed in?, fixed by the environment? *(v2.6.0)* |
| POST | `/api/session` | sign in `{password}` → session cookie *(v2.6.0)* |
| DELETE | `/api/session` | sign out *(v2.6.0)* |
| POST | `/api/session/password` | set/change the password `{password, current?}` — switches sign-in on *(v2.6.0)* |
| DELETE | `/api/session/password` | switch sign-in off `{current}` *(v2.6.0)* |
| GET | `/api/auth/keys` | API keys (without plaintext) *(v2.6.0)* |
| POST | `/api/auth/keys` | create a key `{name, scope: read\|admin}`; plaintext once *(v2.6.0)* |
| DELETE | `/api/auth/keys/{id}` | revoke a key *(v2.6.0)* |
| GET | `/api/diagnostics` | system status, write permissions, schema |
| GET | `/api/utilities` | list of utilities + configuration |
| GET | `/api/settings` | settings |
| PATCH | `/api/settings` | change settings |
| GET | `/api/countries` | country profiles: defaults per country *(v2.7.0)* |
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
| GET | `/api/utility/{u}/meters/{id}/contract-status` | balance per contract; since v2.5.1 with `special_payments[]` (items, gas/electricity/district heating only) |
| GET | `/api/utility/{u}/meters/{id}/forecast` | 12-month forecast |
| GET | `/api/utility/{u}/meters/{id}/tariff-comparison` | tariff comparison real vs. shadow (retrospective) |
| GET | `/api/utility/{u}/meters/{id}/tariff-switch` | switching decision from the switch date; optional `?switch_date=YYYY-MM-DD` |
| GET | `/api/utility/{u}/meters/{id}/bill-check` | bill verification: sections per reading and calorific-value change, `?from=&to=` (F1012, **gas only**, otherwise 400) |
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
| POST | `/api/backup/import` | restore a backup; `?dry_run=1` only checks, `?allow_without_snapshot=1` see 409 |
| POST | `/api/backup/snapshot` | place a snapshot |
| GET | `/api/backup/snapshots` | snapshots: name, size, time, occasion *(v2.6.0)* |
| GET | `/api/backup/snapshots/{name}` | download a snapshot (file) *(v2.6.0)* |
| POST | `/api/backup/snapshots/{name}/restore` | restore a snapshot (saving the current state first) *(v2.6.0)* |
| DELETE | `/api/backup/snapshots/{name}` | delete a snapshot *(v2.6.0)* |
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
        "unit": "m³",              // unit of the METER READING (since v2.4.2)
        "consumption_unit": "kWh", // unit of the CONSUMPTION
        "color": "#f59e0b",
        "meter_id": "m_gas_main",
        "meter_name": "Main meter gas",
        "meter_icon": "🔥",
        "meter_notes": "Cellar",
        "active_device_id": "d_gas_1",
        "last_reading": {
          "date": "2026-04-15",
          "counter": 12345.67,
          "is_estimated": false,
          "id": "20260415-3f2a9c1b",   // since v2.6.0: "already a reading today → replace"
          "device_id": "d_gas_1"       // since v2.6.0: other device → no decrease
        },
        "expected_next_min": 12345.67,
        "typical_per_day": 4.2,        // since v2.6.0: median of the last ≤ 10 intervals, null with < 2
        "suspect_count": 0             // since v2.6.0: unconfirmed suspect readings (Home Assistant)
      }
    ]
  }
}
```

**Since v2.6.0** suspect readings (`is_suspect`) do not count as
`last_reading` — a Home Assistant push of 0 would otherwise become the baseline
of the next capture. `typical_per_day` backs the question "That would be
400 kWh a day, usually it is 8".

**`unit` versus `consumption_unit` (since v2.4.2, GitHub #21).** A gas meter
counts cubic metres; kWh only comes into being through the conversion factor.
`unit` is the unit of `counter` and `expected_next_min`, `consumption_unit` that
of the consumption derived from them. Up to v2.4.1 `unit` was missing from the
response, and the reading-capture view labelled the gas meter reading "kWh" —
while it was always stored and calculated in m³. Among the cumulative utilities
gas is the **only** one where the two units differ — electricity, district
heating and PV count in kWh, water in m³, each identical to its consumption
unit. That is why the defect only ever showed for gas.

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

**Since v2.6.0** additionally `warnings` — readings that do not enter the
calculation, or only with reservations:

```json
"warnings": [
  { "type": "suspect",  "reading_id": "20260920-5c425cf2", "date": "2026-09-20", "counter": 0 },
  { "type": "outlier",  "reading_id": "20260220-dabada46", "date": "2026-02-20", "counter": 13300, "kind": "spike" },
  { "type": "decrease", "reading_id": "…", "date": "…", "counter": 5.0,
    "previous": { "date": "…", "counter": 18432.5 } }
]
```

| `type` | Meaning | in the calculation? |
|---|---|---|
| `suspect` | Home Assistant delivered a reading lower than the previous one; awaiting confirmation (`PATCH …/readings/{id}` with `is_suspect: false`) | no |
| `outlier` | sandwiched outlier of the same device: `kind` = `spike` (upwards) or `dip` (downwards) | no |
| `decrease` | the reading drops without an identifiable outlier — meter swap or rollover not recorded? | the negative interval is not |

Up to v2.5.3 the calculation only discarded the negative interval and counted
the next one in full from the wrong reading: a single value of 0 turned 190 kWh
in a month into 50,270 kWh. A **rollover** (99,998 → 12) is calculated
correctly when `digits` (register digits) is maintained on the device.

### Readings: `is_suspect`, `source` *(v2.6.0, additive)*

Two optional fields on a reading — only present when set:

- `source`: `ingest` (Home Assistant) or `csv` (CSV import). Basis of
  `last_ingest` in `/api/health`.
- `is_suspect: true`: the ingest received a falling reading on the same device.
  The reading is stored anyway (201) but only counts after confirmation.
  `PATCH` with `is_suspect: false` confirms it; a corrected `counter` resolves
  the suspicion as well.

### Meters: `digits` *(v2.6.0, additive)*

Register digits before the decimal point (3–12) per device. On creation as the
single field `digits` or inside the device; `PATCH …/meters/{id}` with `digits`
sets it on the installed device (`null`/empty removes it); `replace-device`
carries it over to the new device unless specified otherwise. Invalid → 400
`errors.meter.digitsInvalid`.

Since v2.6.0 the bodies described in `docs/API.md` up to v2.5.3 are accepted as
well: on creation an object `device {serial, installed_on, initial_counter}`,
on a meter swap `removed_on`, `final_counter` and
`new_device {serial, installed_on, initial_counter}`. The current names take
precedence.

### `GET|PATCH /api/settings` — `gas_conversion_factors` *(F1012, v2.5.0)*

Since schema 1.5.0 the scalar `gas_conversion_factor` is a **dated list**;
the migration turns the legacy value into the undated entry:

```json
"gas_conversion_factors": [
  { "from": null,         "zustandszahl": null, "brennwert": null,  "kwh_per_m3": 11.5 },
  { "from": "2024-01-01", "zustandszahl": 0.96, "brennwert": 11.4,  "kwh_per_m3": 10.944 },
  { "from": "2025-01-01", "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184 }
]
```

- `PATCH` takes the whole list (no single-entry edit); a decimal comma is
  accepted. When `zustandszahl` (volume correction factor) **and**
  `brennwert` (calorific value) are set, `kwh_per_m3` is derived from them
  (5 decimals) and overrides any supplied value. Only one of the two → 400.
- Plausibility (400 with `errors.settings.*`): volume correction 0.8–1.1,
  calorific value 8–13, factor 5–15; at most one undated entry; no duplicate
  dates. The response is sorted by `from`, undated first.
- Empty list → default (undated 11.5).
- Effect: `kwh` in every consumption response is calculated **day-exact**
  with the factor valid on that day; a cut-off date inside a reading
  interval splits the interval.

### Country profile: `country`, `currency`, `timezone`, `gas_cv_unit` *(v2.7.0, additive)*

Four new settings; the defaults keep the previous behaviour (`DE`, `EUR`,
`Europe/Berlin`, `kwh`). `PATCH` rejects unknown values with 400
(`errors.settings.valueInvalid`):

| Key | Allowed | Effect |
|---|---|---|
| `country` | `DE AT CH FR IT ES PT NL GB` | how numbers and dates are written (together with the language), efficiency scale |
| `currency` | `EUR CHF GBP` | symbol and minor unit in every text. Amounts are **not** converted; `*_eur`/`ct_per_kwh` mean the major/minor unit of the chosen currency |
| `timezone` | IANA name (`Europe/Vienna`) | PHP time zone (today, due dates) and day boundaries of the weather data |
| `gas_cv_unit` | `kwh mj gj` | unit in which the interface takes the calorific value; storage is always kWh/m³ |

`GET /api/countries` returns the profiles the interface proposes values from
when the country changes (class C):

```json
{ "code": "AT", "languages": ["de"], "currency": "EUR", "timezone": "Europe/Vienna",
  "hdd_base_temp": 15.0, "co2_strom": 103.0, "co2_strom_source": "ember-2024",
  "efficiency_scale": null, "gas_cv_unit": "kwh",
  "location_name": "Wien", "latitude": 48.2082, "longitude": 16.3738 }
```

On **first start** (empty data directory) the app picks language and country
from `Accept-Language` and writes only the values that differ from the
defaults. After that the header changes nothing. Details:
[country profiles](../functional/14-laenderprofile.md).

### `GET /api/utility/gas/meters/{id}/bill-check?from=YYYY-MM-DD&to=YYYY-MM-DD` *(F1012, v2.5.0)*

Recalculates the supplier bill: one section per boundary within the range
— start, end, every reading, every calorific-value change. `to` is
exclusive, `to_inclusive` the last calendar day of the section. Gas only
(otherwise 400 `errors.billCheck.gasOnly`); `from < to` in ISO form,
otherwise 400.

```json
{
  "from": "2025-01-01", "to": "2026-01-01",
  "rows": [
    { "from": "2025-01-01", "to": "2025-03-10", "to_inclusive": "2025-03-09",
      "days": 68, "reason": "start",
      "m3": 412.3, "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184,
      "kwh": 4611.2 },
    { "from": "2025-03-10", "to": "2025-10-01", "to_inclusive": "2025-09-30",
      "days": 205, "reason": "reading", "m3": 301.0, "…": "…" },
    { "from": "2025-10-01", "to": "2025-11-04", "to_inclusive": "2025-11-03",
      "days": 34, "reason": "factor", "…": "…" },
    { "from": "2025-11-04", "to": "2026-01-01", "to_inclusive": "2025-12-31",
      "days": 58, "reason": "reading", "m3": null, "kwh": null, "…": "…" }
  ],
  "totals": { "days": 365, "m3": 812.4, "kwh": 9034.7, "gaps": 1 }
}
```

`reason` ∈ `start`, `end`, `reading`, `reading_estimated`, `factor` —
combinations joined with `+` (`reading+factor`). **Since v2.5.2** every row
carries `counter_from`/`counter_to` (meter reading at the start/end of the
section) with `counter_from_kind`/`counter_to_kind` ∈ `reading` (real
reading), `reading_estimated` (recorded as estimated), `interpolated`
(substitute value: no reading on that day, interpolated day-exact) or
`null` (no enclosing interval). Across a meter swap the substitute value is
`null` with `kind = interpolated`. `m3`/`kwh` are `null` when
no reading interval encloses the section (before the first, after the last
reading); `totals.gaps` counts those sections, they are missing from the
sums. `m3` per section is the linear interpolation of the enclosing
interval — the supplier estimates at the very same places.

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
  "scale": "geg", "scale_note": null,
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

*(v2.7.0)* `scale` names the efficiency scale of the configured country —
today only `geg` (Germany). For other countries `scale` is `null`, every
`class` field is `null`, and `scale_note` explains why; the figure
`kwh_per_m2` stays. A class under German law would mislead in France (DPE)
or Austria (HWB).

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
`{ status: "created"|"updated", utility, meter_id, date, counter, reading_id,
suspect }` — `suspect` since v2.6.0: if the value is lower than the previous
reading of the same device, it is stored but marked as suspect
(`suspect: true`, plus `previous: {date, counter}`) and only counts after
confirmation in the interface. A rollover with maintained `digits` is not
suspect. A push is therefore never rejected where it was
accepted before.
Errors: `401` (token needed/wrong; since v2.6.0 with sign-in switched on also
without a token set: `errors.ingest.tokenRequiredWithLogin`), `400` (unknown
utility/meter, no numeric value, no valid calendar date, delivery utility
heating oil/pellets).

If the date lies before the installation of the **first** device, that device is
backdated since v2.5.3 instead of rejecting the reading (typical when back-filling
older readings after a new installation). A date in a gap between two devices
remains a `400`.

> **Template for Home Assistant:** `| float` **without** a default and check
> `has_value(…)` before the push — see [`docs/HOME-ASSISTANT.md`](../HOME-ASSISTANT.md).
> `float(0)` from templates up to v2.5.2 recorded a meter reading of 0 when the
> sensor was unavailable.

### `GET|POST|DELETE /api/auth/token` *(F1009)*

Management of the **opt-in** token for the push endpoint. It protects
`/api/ingest` **only**: without a token the ingest accepts values without a
header; as soon as a token exists, it requires `Authorization: Bearer <token>`.
The rest of the API is protected by sign-in (see above), not by this token. The
token is stored only as a SHA-256 hash in `data/auth.json` and returned in
plaintext **once** on creation. Since v2.6.0 `GET` also reports `last_used_at`
(accurate to the hour) — useful for the question "is anything arriving at all?".

### `/api/session`, `/api/session/password`, `/api/auth/keys` *(v2.6.0)*

```jsonc
// GET /api/session
{ "mode": "off", "authenticated": true, "mode_fixed": false,
  "password_fixed": false, "has_password": false }

// POST /api/session/password   (switch on: password only; change: + current)
{ "password": "at-least-8-characters", "current": "previous" }
// → { "mode": "password" } + session cookie; a new password ends all other sessions

// POST /api/auth/keys
{ "name": "backup script", "scope": "read" }
// → 201 { "id": "k_…", "key": "etk_…", "hint": "…" }   plaintext only here
```

- `mode`: `off` · `password` · `proxy`. `mode_fixed`/`password_fixed`: fixed by
  `ET_AUTH` or `ET_ADMIN_PASSWORD_HASH` — the changing routes then answer `409`.
- Failed attempts: after five within 15 minutes sign-in is locked for
  5 minutes (`429`). This also applies to `current` when changing or switching
  off.
- `GET /api/auth/keys` returns `id`, `name`, `scope`, `created_at`,
  `last_used_at` — never the key or its hash.

### Snapshots and import *(v2.6.0)*

`GET /api/backup/snapshots` lists `data/backups/` (newest first):

```json
[ { "name": "pre-restore-2026-09-25_000438.json", "size": 215512,
    "created_at": "2026-09-25T00:04:38+02:00", "reason": "restore" } ]
```

`reason` ∈ `manual` (own snapshot), `restore` (before import/restore),
`migration`, `demo`, `v09`. **Retention:** of your own the last ten; automatic
ones 30 days, keeping at least the three newest per occasion.

Since v2.6.0 `POST /api/backup/import` **checks everything first**: every pot
must be a list of objects with mandatory fields and valid dates. A faulty
backup changes nothing and answers `400` with `detail.problems` (at most 50):

```json
{ "success": false, "code": "errors.backup.invalid",
  "error": "The backup is incomplete or damaged (3 problem(s)). Nothing was restored.",
  "detail": { "problems": [ { "pot": "gas/meters", "index": 0, "problem": "missing:devices" } ] } }
```

`problem` ∈ `not_an_object`, `not_a_list`, `unknown_utility`,
`missing:<field>`, `date:<field>`, `counter`, `devices`, `entry:<date>`. With
`?dry_run=1` the import stops after the check and returns the report (counts per
pot, `untouched` = pots not contained in the backup and therefore unchanged).
Further changes: the envelope of `GET /api/backup/export` (`{success, data}`)
is unwrapped; if a file fails mid-write, the files already written are rolled
back; `recommendations_dismissed` is part of the backup since v2.6.0.

### `GET|HEAD /api/health` *(N1003; extended in v2.6.0)*

```json
{ "status": "ok", "version": "2.6.0", "schema_version": "1.5.0",
  "data_dir_writable": true, "migrations_pending": 0,
  "data_initialized_at": "2026-09-24T23:58:03+02:00",
  "php_version": "8.4.12", "timezone": "Europe/Berlin",
  "last_ingest": { "m_strom_main": "2026-09-20" },
  "checks": {
    "data_dir_writable": { "ok": true, "level": "ok" },
    "schema":            { "ok": true, "level": "ok" },
    "files":             { "ok": true, "level": "ok", "corrupt": [] },
    "disk":              { "ok": true, "level": "ok", "free_mb": 736175 },
    "temp_files":        { "ok": true, "level": "ok", "removed": 0 } } }
```

`status` ∈ `ok` · `degraded` (pending migration, less than 50 MB free) ·
`error` (not writable, corrupt file, data newer than the app, less than 5 MB
free) — with HTTP `503` on `error`, so Docker HEALTHCHECK and monitors detect
the fault. Since v2.6.0 `migrations_pending` counts the pending migration steps
(up to v2.5.3 only `0` or `1`; `0` still means "nothing to do"). With sign-in
switched on and the caller not signed in, only `{status, version}` is returned.

### Parameter limits *(v2.6.0)*

Previously accepted silently, now `400` with `code` and — for the forecast —
`detail {param, value, range}`:

| Endpoint | Parameter | allowed |
|---|---|---|
| `…/forecast` | `forecast_months` | 1–60 |
| | `temp_offset` | −30 to +30 °C |
| | `price_factor` | 0–10 |
| | `model` | `linear`, `polynomial`, `robust`, `segmented`, `sigmoid` |
| `/api/reports/yearly.pdf` | `year` | 2000–2100 |
| `POST /api/temperatures` | `avg`, `min`, `max` | numbers, mandatory |
| `…/sync-open-meteo` | `start`, `end` | ISO date, `start` ≤ `end` |

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
