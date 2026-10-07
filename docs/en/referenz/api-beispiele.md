# API examples

**English** · [Deutsch](../../referenz/api-beispiele.md)

[← Compendium index](../README.md)

> **Note:** the [API reference](api.md) is authoritative for paths, fields,
> status codes and the stability promise — the complete route list, checked
> against the code by a test. This page is the **guide with detailed examples**
> for the most-used endpoints (last revised in substance with v2.6.0; up to
> v2.13 it was `docs/en/API.md`).
> Up to v2.5.3 it deviated from the code in several places (review API-18); the
> bodies for meters and meter swaps formerly described here are accepted as
> aliases since then.

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
  "error": "Readable message in the language of the request",
  "code": "errors.reading.dateInvalid"
}
```

`code` *(since v2.6.0)* is stable — scripts evaluate it, not the message text.
The message is in the language of the request (seven languages; order:
[API reference → language](api.md#language-of-the-responses-v310)). `detail`
with file and line is only returned with `ET_DEBUG=1`; a `500` carries
`error_id` (the server log holds the same ID). All status codes (400, 401, 403,
404, 405, 409, 421, 422, 429, 500, 502, 503) and their meaning:
[API reference → status codes](api.md#status-codes-of-all-endpoints).

---

## Route overview

The complete list of all routes is in the
[API reference](api.md#1-full-route-overview) — a test
checks it against the code at every release. Up to v2.5.3 this document had its
own table; in the end it knew 37 of 70 routes. The sections below show examples
by topic.

---

## Diagnostics

### `GET /api/diagnostics`

Delivers the system state and schema information.

**Response:**

```json
{
  "success": true,
  "data": {
    "app_version": "2.10.0",
    "schema_version": "1.6.0",
    "php_version": "8.4.12",
    "data_dir": "/var/www/energietracker/data",
    "data_dir_writable": true,
    "curl_available": true,
    "time_zone": "Europe/Berlin",
    "now": "2026-09-25T00:13:17+02:00",
    "migration_needed": false,
    "utilities": {
      "gas":     { "kind": "cumulative", "meters": 1, "readings": 41, "contracts": 6, "last_reading_date": "2026-03-15" },
      "heizoel": { "kind": "delivery",   "meters": 1, "deliveries": 3, "contracts": 0, "last_delivery_date": "2025-09-18" }
    },
    "temperatures": { "rows": 1277 },
    "settings_known_keys": ["gas_conversion_factors", "hdd_base_temp", "…"]
  }
}
```

For monitoring use `GET /api/health` (status, checks, HTTP 503 on error);
diagnostics is the detailed view for the settings page.

---

## Utilities

### `GET /api/utilities`

Delivers the static configuration of the nine utilities (`gas`, `strom`,
`wasser`, `fernwaerme`, `heizoel`, `pellets`, `pv_einspeisung`, `pv_erzeugung`,
since v3.1.0 `waerme`; the single source of truth from
`src/Config/Utilities.php`).

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
      "conversion_setting": null,
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

**Response:** all settings as a flat object; keys and defaults are defined in
`SettingsService::DEFAULTS`.

### `PATCH /api/settings`

A partial update — only the passed keys are written, all others stay unchanged.

**Body:**

```json
{ "hdd_base_temp": 17, "forecast_model": "robust" }
```

**Response:** the complete updated settings object. Unknown keys are not
stored; since v2.6.0 the response names them in `ignored_keys` and in the
`X-Ignored-Keys` header (up to v2.5.3 they were dropped silently — a typo in a
key went unnoticed).

Since v2.7.0 the endpoint checks the country-profile keys `country`, `currency`,
`timezone` and `gas_cv_unit` against their allowed values (400
`errors.settings.valueInvalid`):

```json
{ "country": "AT", "timezone": "Europe/Vienna" }
```

### `GET /api/countries` *(v2.7.0)*

The country profiles (currency, time zone, heating threshold, CO₂ factor for
electricity, location, efficiency scale, calorific-value unit). Writes nothing —
the caller decides what to adopt via `PATCH /api/settings`. Field list:
[API reference](api.md#country-profile-country-currency-timezone-gas_cv_unit-v270-additive).

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

**Body:** plain text (Content-Type `text/plain`), one line per day: date, mean,
minimum, maximum. The usual form is `DD.MM.YYYY;mean;min;max` with a decimal
comma (since v2.12.0); tab and the old format with `"` as separator are read
too. Since v3.1.0 also every export file of your own (format 1 and "local" in
every language): dates as `D/M/YYYY`, `D-M-YYYY` or ISO, a comma as field
separator with a decimal point, a header in any language.

```
Datum;Mittel (°C);Min (°C);Max (°C)
15.01.2024;4,2;-1,0;7,1
16.01.2024;3,8;0,5;6,9
```

```
Date,Mean (°C),Min (°C),Max (°C)
15/01/2024,4.2,-1,7.1
```

**Response:**

```json
{ "success": true, "data": { "imported": 365, "skipped": 0, "errors": [] } }
```

Rows without a valid date or with missing values count under `skipped` — so
does a date with the month before the day (`01/15/2024`); it is never
reinterpreted. Details: [API reference → Import](api.md#import-v310).

### `POST /api/temperatures/sync-open-meteo`

Loads temperatures for the coordinates stored in the settings: measured values
from the Open-Meteo archive, the forecast for the last few days and the coming
week, and the first time additionally the climate normal (30 years). Only the
location is transmitted, rounded to two decimal places.

**Query (all optional, since v2.8.0):** `start`, `end` (ISO date; without
`start` from the first reading or delivery), `reload=1` (replace existing values
with archive values — except your own from CSV or manual entry), `auto=1` (at
most once a day; this is how the interface calls it when opened).
**Body:** optional `{}`.

**Response:**

```json
{
  "success": true,
  "data": {
    "imported": 1145,
    "archive_rows": 1131,
    "forecast_rows": 14,
    "archive_range": "2023-01-01..2026-09-19",
    "archive_error": null,
    "archive_error_code": null,
    "forecast_error": null,
    "forecast_error_code": null,
    "measured_until": "2026-09-19",
    "forecast_until": "2026-10-08",
    "climate_normal": { "status": "fetched", "period": { "from": "1996-01-01", "to": "2025-12-31" },
                        "latitude": 51.34, "longitude": 12.37, "fetched_at": "…" }
  }
}
```

If Open-Meteo cannot be reached, the reason is in `archive_error` or
`forecast_error` in the language of the request, and as a stable code (since
v3.1.0) in `archive_error_code` or `forecast_error_code` — such as `network`,
`http` or `noDays`:

```json
"archive_error": "Open-Meteo replied with HTTP 503. Try again later.",
"archive_error_code": "http"
```

Since v2.8.0 every day in `GET /api/temperatures` carries `source`
(`archive`, `forecast`, `csv`, `manual`); forecasts are replaced by archive
values as soon as these are available. Details:
[API reference](api.md).

### `DELETE /api/temperatures/{date}`

Deletes a single day (`date` as `YYYY-MM-DD`).

---

## Meters & devices

### `GET /api/utility/{utility}/meters`

`{utility}` ∈ `gas | strom | wasser | fernwaerme | heizoel | pellets |
pv_einspeisung | pv_erzeugung | waerme` (heat, since v3.1.0).

**Response:** an array of meter objects (schema:
[data model](datenmodell.md)).

### `POST /api/utility/{utility}/meters`

**Body:**

```json
{
  "name": "Garden intermediate meter",
  "icon": "💧",
  "notes": "Optional",
  "device_serial": "WZ-2021-AB123",
  "installed_on": "2021-04-15",
  "initial_counter": 0.0,
  "digits": 5
}
```

All device fields are optional (installed today, initial reading 0). `digits`
*(v2.6.0)* = register digits before the decimal point (3–12), so that a
rollover is calculated correctly. Heating oil/pellets require `capacity` (> 0)
and `initial_stock` instead.

The body described here up to v2.5.3, with an object
`"device": {"serial", "installed_on", "initial_counter"}`, was never read by the
code — the values were silently lost. Since v2.6.0 it is accepted as an alias;
the single fields above take precedence.

**Role and way of recording (v3.1.0).** `role` says what the meter stands for
(such as `warm` for a hot-water meter), `capture: "period"` makes it a meter
with consumption per period. A hot-water meter and a heat meter for the monthly
consumption information:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"name":"Hot water bathroom","role":"warm"}' \
  'http://nas.local:8080/api.php/api/utility/wasser/meters'

curl -X POST -H 'Content-Type: application/json' \
  -d '{"name":"Heat meter","capture":"period"}' \
  'http://nas.local:8080/api.php/api/utility/waerme/meters'
```

The default role (`cold`, `household` …) and `capture: "counter"` are not
stored. Roles per utility and error codes:
[API reference → Meters](api.md#meters-role-capture-v310-additive).

### `PATCH /api/utility/{utility}/meters/{id}`

**Body:** any subset of
`{ name, icon, active, notes, parent_meter_id, meter_group_id, external_id }`;
since v3.1.0 also `role` and `capture` (the way of recording only while the
meter has no data of the previous kind, otherwise `400`
`errors.meter.captureLocked`).

- `parent_meter_id` / `meter_group_id` — meter topology (F1006, from schema 1.2.0).
- `external_id` — a freely assignable alias for the Home Assistant integration
  (F1009, from schema 1.3.0). Unique per utility; allowed are 1–64 characters from
  `[A-Za-z0-9_.-]`. An empty value/`null` removes the alias.

### `DELETE /api/utility/{utility}/meters/{id}`

Deletes the meter. If it has readings, deliveries, contracts or sub-meters, the
route refuses with `400` (`errors.meter.hasReadings`,
`errors.meter.hasDeliveries`, `errors.meter.hasContracts`,
`errors.meter.isParent`) — none of them is deleted along with it. The route
does not check the periods of a meter with consumption per period (v3.1.0);
delete them first.

### `POST /api/utility/{utility}/meters/{id}/replace-device`

F2 meter swap: closes the current device and creates a new one.

**Body:**

```json
{
  "date": "2024-08-22",
  "old_final_counter": 18432.5,
  "new_initial_counter": 0.0,
  "serial": "GAS-2024-CD8945",
  "reason": "Calibration deadline expired"
}
```

`old_final_counter` is mandatory (missing → 400; a silent final reading of 0
produced a 200-fold spike in issue #13). The swap day belongs to the **new**
device. The body described here up to v2.5.3 (`removed_on`, `final_counter`,
`new_device {serial, installed_on, initial_counter}`) ended in
"old_final_counter missing"; since v2.6.0 it is accepted as an alias.

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

**Sending again without duplicates (`client_ref`, v3.1.0).** Whoever does not
know after a timeout whether the reading arrived sends it again with the same
identifier. The client chooses the identifier: 8–64 characters from letters,
digits and `-`.

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_gas_main","date":"2026-10-07","counter":24990.5,"client_ref":"keller-2026-10-07-gas"}' \
  'http://nas.local:8080/api.php/api/utility/gas/readings'
```

The first attempt answers `201` with the new reading. Every further one with the
same `client_ref` on the same meter creates nothing and answers `200`:

```json
{
  "success": true,
  "data": {
    "id": "20261007-9f8e7d6c", "meter_id": "m_gas_main", "device_id": "d_gas_001",
    "date": "2026-10-07", "counter": 24990.5, "price_cents": null, "note": "",
    "is_estimated": false, "is_future": false,
    "client_ref": "keller-2026-10-07-gas",
    "duplicate": true
  }
}
```

An identifier in the wrong format → `400` `errors.reading.clientRefInvalid`.
The interface sends a `client_ref` with every entry in “Meter readings”
(offline queue).

A meter with consumption per period (v3.1.0) has no readings: `POST` and the
CSV import answer `400` `errors.reading.periodMeter` there — the value belongs
in a period ([see below](#consumption-per-period-v310)).

### `PATCH /api/utility/{utility}/readings/{id}`

**Body:** any subset of the reading fields. Since v3.1.0 also `attachment_id`:
another ID replaces the photo, `null` detaches it
([Receipts](#receipts-and-photos-v310)).

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

Since v3.1.0 the import reads every export file of your own back in — format 1
and the spreadsheet in every language —, for example a French one:

```
ID compteur;Compteur;ID appareil;Date;Index;Prix (ct);Note;Estimé;Futur
m_gas_main;Compteur principal;d_gas_1;01/02/2026;12345,6;;;Non;Non
```

- **Separator:** `;` preferred, `,` as a fallback (decided on the first line).
- **Date:** ISO `YYYY-MM-DD` or day before month with a four-digit year —
  `D.M.YYYY`, `D/M/YYYY`, `D-M-YYYY` (since v3.1.0; up to v3.0 only
  `DD.MM.YYYY`). A date with the month before the day (`01/15/2026`) is not
  reinterpreted but reported as an error.
- **Header:** columns are found by their name, in every language of the app;
  without a header the order is date, reading, note, estimated. If the file has
  a column with the meter ID (your own export of several meters), the import
  takes only the rows of this meter.
- **Counter:** decimal comma or point; point, space or apostrophe as thousands
  separator are recognised.
- **note** and **estimated** are optional; "estimated" holds for `true`, `1`,
  `x`, `ja` and the yes of every language (`yes`, `oui`, `sì`, `sí` …),
  otherwise and when empty `false`.

> Up to v3.0 the column names (`datum`, `zaehlerstand`, `notiz`,
> `geschaetzt`) and the keywords `ja`/`nein` had to be German; they still work.

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

`errors` contains, per unprocessable row, a message with the line number —
since v3.1.0 in the language of the request (up to v3.0 German):

```json
"errors": [ "Line 4: month above 12 (01/15/2026) — please write the day before the month (DD/MM/YYYY) or YYYY-MM-DD" ]
```

The import logic sits in the source-agnostic `ReadingImportService` —
external data sources such as the [Home Assistant integration](../anleitungen/home-assistant.md)
(`POST /api/ingest`) reuse the same core without CSV parsing.

---

## Consumption per period *(v3.1.0)*

For values that already come as consumption — such as the monthly consumption
information of the metering service. The meter needs `capture: "period"`
([create a meter](#post-apiutilityutilitymeters)); it then accepts no more
readings. Fields, rules and error codes:
[API reference → Consumption per period](api.md#consumption-per-period-v310).
The values below are examples.

### Enter one month — `POST /api/utility/{utility}/periods`

`month` is the shorthand for a whole month; the comparison values of the
consumption information (`reference`) are optional and for display only:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_waerme_1a2b3c4d","month":"2026-09","value":410,
       "reference":{"prev_month":180,"prev_year_month":450,"average_user":390},
       "client_ref":"uvi-2026-09-waerme"}' \
  'http://nas.local:8080/api.php/api/utility/waerme/periods'
```

**Response** (`201`):

```json
{
  "success": true,
  "data": {
    "id": "p_8f2c41d0a9b3", "meter_id": "m_waerme_1a2b3c4d",
    "from": "2026-09-01", "to": "2026-09-30",
    "value": 410, "value_unit": "consumption", "is_estimated": false,
    "source": "manual", "note": "",
    "reference": { "prev_month": 180, "prev_year_month": 450, "average_user": 390 },
    "client_ref": "uvi-2026-09-waerme"
  }
}
```

The same call again (same `client_ref`) creates nothing and answers `200` with
`duplicate: true`. Any period works with `from` and `to` (both inclusive)
instead of `month`. If it overlaps an existing one → `400`
`errors.period.overlap`. For gas the value can also be entered in m³
(`"value_unit": "meter"`); the app then calculates with the dated conversion
factors.

Read, change, delete:

```bash
curl 'http://nas.local:8080/api.php/api/utility/waerme/periods?meter_id=m_waerme_1a2b3c4d'
curl -X PATCH -H 'Content-Type: application/json' -d '{"value":415,"note":"corrected"}' \
  'http://nas.local:8080/api.php/api/utility/waerme/periods/p_8f2c41d0a9b3'
curl -X DELETE 'http://nas.local:8080/api.php/api/utility/waerme/periods/p_8f2c41d0a9b3'
```

### Many months from CSV — `POST /api/utility/{utility}/meters/{id}/periods/import-csv`

The body is the raw CSV text. One month and its consumption per row, or
`from;to;value`; a header is optional:

```
month;value;note
01.2026;1480;consumption information
02.2026;1210
2026-03;980
01.04.2026;30.04.2026;520
```

First as a dry run — nothing is written:

```bash
curl -X POST -H 'Content-Type: text/plain' --data-binary @consumption-info.csv \
  'http://nas.local:8080/api.php/api/utility/waerme/meters/m_waerme_1a2b3c4d/periods/import-csv?dry_run=1'
```

```json
{
  "success": true,
  "data": {
    "imported": 0, "skipped": 0, "errors": [],
    "dry_run": true, "would_import": 4,
    "rows": [
      { "line": 2, "from": "2026-01-01", "to": "2026-01-31", "value": 1480, "note": "consumption information" },
      { "line": 3, "from": "2026-02-01", "to": "2026-02-28", "value": 1210, "note": "" },
      { "line": 4, "from": "2026-03-01", "to": "2026-03-31", "value": 980, "note": "" },
      { "line": 5, "from": "2026-04-01", "to": "2026-04-30", "value": 520, "note": "" }
    ]
  }
}
```

Then without `?dry_run=1`: the response gives `imported`, `skipped` and
`errors`. A row that overlaps an existing period is skipped and reported in
`errors` (“Line 3: The period overlaps an existing one (…)”), not overwritten.
Your own file from `GET /api/export/waerme/periods.csv` can be read back in the
same way.

---

## Receipts and photos *(v3.1.0)*

A photo with a meter reading in two steps: first upload the file, then create
the reading with the receipt's ID. Fields, limits and error codes:
[API reference → Receipts](api.md#receipts-and-text-recognition-v310).

### Upload a photo — `POST /api/attachments`

The file goes as the **raw body**, not as a form (`multipart`). The server
determines the type from the content; the `Content-Type` is mere courtesy.

```bash
curl -X POST --data-binary @zaehler.jpg -H 'Content-Type: image/jpeg' \
  'http://nas.local:8080/api.php/api/attachments?kind=reading_photo'
```

**Response** (`201`):

```json
{
  "success": true,
  "data": {
    "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "mime": "image/jpeg",
    "size": 284113, "sha256": "9c1e…", "created_at": "2026-10-07T08:12:40+02:00",
    "ref": null
  }
}
```

Through the API the server takes the photo as it comes. Scaling it down and
removing the EXIF data (GPS location included) is only done by the interface in
the browser — a script should do that itself before uploading. At most 3 MB
per photo.

### Create a reading with a photo

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_gas_main","date":"2026-10-07","counter":24990.5,"attachment_id":"att_5f0c2a9e81d34b67"}' \
  'http://nas.local:8080/api.php/api/utility/gas/readings'
```

The response is the new reading with `attachment_id`; the receipt then carries
`ref: {"type": "reading", "utility": "gas", "id": "…"}`. A receipt that is not
attached to any reading after 24 hours is cleaned up.

With sign-in switched on, every call needs an API key with the “Manage”
permission: `-H 'Authorization: Bearer etk_…'`.

### Fetch and delete a photo

```bash
curl -o photo.jpg 'http://nas.local:8080/api.php/api/attachments/att_5f0c2a9e81d34b67'
curl -X DELETE 'http://nas.local:8080/api.php/api/attachments/att_5f0c2a9e81d34b67'
```

`DELETE` removes the file and the reference right away; the reading stays, just
without a photo. `GET /api/attachments` lists all receipts with the storage use
(`usage`).

### Read the meter value from the photo — `POST /api/ocr/reading`

Only with a text recognition service set up in the home network
([guide](../anleitungen/texterkennung.md)):

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"attachment_id":"att_5f0c2a9e81d34b67"}' \
  'http://nas.local:8080/api.php/api/ocr/reading'
```

```json
{
  "success": true,
  "data": { "value": 24990.5, "confidence": 0.9, "raw": "{\"value\": 24990.5, \"confidence\": 0.9}",
            "model": "qwen2.5vl", "duration_ms": 31250 }
}
```

Nothing is saved here — whoever wants to use the value creates the reading as
above. If the service does not answer, answers too late or with nothing usable,
the response is `502` (`errors.ocr.timeout`, `errors.ocr.unreachable`,
`errors.ocr.badAnswer`); without a service, or with an address outside the home
network, `400` (`errors.ocr.off`, `errors.ocr.notLocal`).

---

## Contracts

### `GET /api/utility/{utility}/contracts`

The query parameter `meter_id` filters to one meter.

### `POST /api/utility/{utility}/contracts`

**Body for gas / electricity:**

```json
{
  "meter_id": "m_gas_main",
  "provider": "Nordwind Energie",
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
        "provider": "Nordwind Energie",
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
        "verdict": "refund",
        "days_until_end": 231,
        "should_remind":  false,
        "remind_stage":   0
      }
    ]
  }
}
```

**Since v2.5.1** every contract additionally carries `special_payments`: the
individual items as `[{date, kind, amount_eur, note}]` (sorted by date,
amounts positive — the direction is in `kind`), the data source for the
tooltip of the *Special payments* column. `special_payment_net` is the net
from the customer's perspective (Σ refund − Σ back-payment − Σ advance
payment). The field is absent for water and PV feed-in.

**Since v2.8.0** the balance for gas, electricity and district heating is
computed **by calendar up to today**: `advance_paid` counts the advances according
to the payment plan up to and including the current month (previously only
months with a reading), `cost_to_date` covers consumption up to today — measured
up to `measured_until`, estimated afterwards (`estimated_cost_to_date`). In
addition `energy_cost_to_date`, `base_to_date`, `bonus_to_date` (the parts of the
total), `estimated_cost_remaining`, `advance_remaining`, `suggested_advance`,
`balance_as_of`, `projection_method` and `projection_factor` — table in the
[API reference](api.md). `actual_*` still describes only
the measured months.

`verdict` is a key: `surcharge` (back-payment) when
`projected_end_balance > 5`, `refund` when `< -5`, otherwise `balanced`. For PV
feed-in the axis is inverted: `payout`, `reclaim`, `balanced`. The interface
translates the key (`utility.verdict.*`). Up to v1.9.x German words were
emitted here — v2.0.0 changed that **without notice**; that is exactly why the
[stability promise](api.md#stability-promise-v260) exists
since v2.6.0.

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

**Since v2.9.0** the levels count down to the **cancellation deadline**
(`remind_basis: "cancel_by"`) as soon as a notice period is maintained —
otherwise, as before, to the end (`"end"`). In addition `cancel_by`,
`days_to_cancel`, `switch_date`, `notice_basis` (`fixed_end`, `open_ended`,
`min_term`, `renewed`, `unknown`), `cancel_missed`, `renewed` (expired without a
successor, runs on) and `price_increase` (next entered increase). Details:
[API reference](api.md).

**Water-specific response** (since v1.0.3): each contract object additionally
contains `actual_m3` and `components` with the breakdown of the three components:

```json
{
  "contract_id": "c_wasser_...",
  "actual_m3": 187.5,
  "current_balance": +52.28,
  "projected_end_balance": +85.40,
  "verdict": "surcharge",
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
        "contract_assumed": false,
        "band_low": 470.2,
        "band_high": 609.8,
        "method": "blend(reg=0.75, seasonal=0.25)"
      }
    ],
    "hdd_source": "climate_normal",
    "annual": { "value": 9387.3, "low": 8619.3, "high": 10155.4, "sigma": 1.28, "level_pct": 80 },
    "warnings": [],
    "climate_normal": { "period": { "from": "1996-01-01", "to": "2025-12-31" }, "latitude": 51.34, "longitude": 12.37, "fetched_at": "…" },
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
  credit, positive = back-payment (since v2.13.0 the interface shows the
  customer's side in words); the value of the last month is the projected
  balance at the end of the horizon.
- `working_price_ct` — the applied working price (the headline tariff).
- `contract_id` — the active contract, or `null` (then fallback to `last_price_ct`).
- `method` — `blend(reg=…, seasonal=…)`, `seasonal_only`, since v2.8.0 also
  `regression_only` (calendar month without own history), `heat_model` and
  `filled`.
- `band_low`/`band_high` *(v2.8.0)* — uncertainty band, width
  `confidence_band_sigma` (default 1.28 σ ≈ 80 % of years).
- `hdd_estimated` — since v2.8.0 the normal heating degree days from the
  climate normal (`hdd_source`), otherwise from your own history.
- `contract_assumed` *(v2.8.0)* — `true` if the last contract continues as an
  assumption after the end of the contract.

At the top level since v2.8.0: `annual` (annual total with band), `warnings`
(`history_short`, `no_climate_normal`), `hdd_source` and `climate_normal`.

Future bonuses are **not** carried forward — only bonuses maintained in the contract
with a credit date in the forecast period are included. With too little history
(< 6 months) the response is `{ "valid": false, "reason": "…" }`.

---

## Tenancy *(v3.1.0)*

For tenants who pay for heating and water through the service charges:
prepayment, prices from the last statement and the assigned meters — from these
an **estimate** for the current billing period. It does not replace a service
charge statement; what the landlord bills may differ. Fields and rules:
[API reference → Tenancy](api.md#tenancy-v310), step by step in the interface:
[As a tenant](../anleitungen/mieter.md). All values below are examples.

### Create a tenancy — `POST /api/tenancies`

Prepayment €100 for heating and €20 for operating costs per month, billing by
calendar year, one heat meter with consumption per period
([see above](#consumption-per-period-v310)):

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"label":"Flat","start":"2024-01-01","billing_anchor":"01-01",
       "prepayments":[{"from":"2024-01-01","heating_eur_month":100,"operating_eur_month":20}],
       "meter_ids":{"heat":["m_waerme_1a2b3c4d"]}}' \
  'http://nas.local:8080/api.php/api/tenancies'
```

**Response** (`201`):

```json
{
  "success": true,
  "data": {
    "id": "t_1a2b3c4d5e6f", "start": "2024-01-01", "label": "Flat", "landlord": "",
    "notes": "", "wohnflaeche_m2": null, "billing_anchor": "01-01",
    "prepayments": [ { "from": "2024-01-01", "heating_eur_month": 100, "operating_eur_month": 20 } ],
    "prices": [], "fixed_costs": [],
    "meter_ids": { "heat": ["m_waerme_1a2b3c4d"], "warm_water": [], "cold_water": [] }
  }
}
```

`PATCH /api/tenancies/{id}` changes single fields; a list (`prepayments`,
`prices`, `fixed_costs`) is always sent whole.

### Record a statement and take over prices — `POST /api/tenancies/{id}/statements`

The statement for 2025: 8,600 kWh of heat for €1,290 heating costs, €180
operating costs, €1,440 prepaid. `apply_prices` derives the heat price from it
and enters it into the tenancy from the day after the period:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"period_from":"2025-01-01","period_to":"2025-12-31","received_on":"2026-03-20",
       "total_cost_eur":1470,"prepaid_eur":1440,
       "positions":[{"label":"Heating","category":"heating","amount_eur":1290},
                    {"label":"Operating costs","category":"operating","amount_eur":180}],
       "heat":{"consumption":8600,"unit":"kWh","cost_eur":1290},
       "apply_prices":true}' \
  'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements'
```

The response (`201`) is the statement with `result_eur: 30` (costs −
prepayment; positive = additional payment). The tenancy then holds:

```json
"prices": [ { "from": "2026-01-01", "heat_eur_per_kwh": 0.15,
              "source": "statement", "statement_id": "s_9e8d7c6b5a40" } ]
```

€1,290 ÷ 8,600 kWh = €0.15/kWh. With `new_prepayment` and
`"apply_prepayment": true` the same call also takes over a new prepayment. A
PDF of the statement is uploaded beforehand as a receipt
(`POST /api/attachments?kind=statement_pdf`, [Receipts](#receipts-and-photos-v310))
and its ID passed in `attachment_ids`. `received_on` sets the objection deadline
(twelve months) in the agenda and the calendar, provided `wohnverhaeltnis` is
set to `miete`.

### What is coming? — `GET /api/tenancies/{id}/budget`

```bash
curl 'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/budget'
```

Worked by hand for 2026: about 9,000 kWh of heat in the year (measured up to
September, October to December estimated from the heating model) × €0.15 =
€1,350 expected, against 12 × €120 = €1,440 prepayment → **−€90, so about €90
credit**:

```json
{
  "success": true,
  "data": {
    "tenancy_id": "t_1a2b3c4d5e6f",
    "period_from": "2026-01-01", "period_to": "2026-12-31", "as_of": "2026-10-07",
    "months": [
      { "ym": "2026-01", "share": 1, "heat_kwh": 1650.0, "warm_water_m3": 0, "cold_water_m3": 0,
        "expected_eur": 247.5, "prepaid_eur": 120, "balance_eur": 127.5, "measured": true },
      { "…": "…" }
    ],
    "expected_eur": 1350.0, "prepaid_eur": 1440, "projected_result_eur": -90.0,
    "to_date": { "expected_eur": 1035.0, "prepaid_eur": 1080, "result_eur": -45.0 },
    "risk": "low", "suggested_prepayment_eur": 113,
    "components": { "heat_eur": 1350.0, "warm_water_eur": 0, "cold_water_eur": 0, "fixed_eur": 0,
                    "heat_kwh": 9000.0, "warm_water_m3": 0, "cold_water_m3": 0 },
    "assumptions": [ "months_estimated" ], "months_estimated": 3
  }
}
```

`risk: "low"` because the result is not above 0; the suitable prepayment is
€1,350 ÷ 12 = €112.50, rounded up to €113. `to_date` only counts the measured
months (January to September). `?as_of=2026-06-30` calculates for another
reference date. If a price is missing for an assigned meter, `assumptions`
holds e.g. `price_missing_heat` — those costs are then missing from the
calculation.

Read, change, delete statements:

```bash
curl 'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements'
curl -X PATCH -H 'Content-Type: application/json' -d '{"booked":true}' \
  'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements/s_9e8d7c6b5a40'
curl -X DELETE 'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements/s_9e8d7c6b5a40'
```

`DELETE /api/tenancies/{id}` deletes the tenancy together with its statements.

---

## Record, check and book a supplier bill *(v3.1.0)*

Keep the supplier’s annual bill, set it against your own calculation and book
the result as a special payment — for gas, electricity, water and district
heating. Fields and rules:
[API reference → Supplier bills](api.md#supplier-bills-v310), in the interface:
[Annual bill](../anleitungen/jahresabrechnung.md#7-on-the-bill-enter-compare-book).
All values are examples.

### Create a bill — `POST /api/utility/{utility}/bills`

An electricity bill for 2025: 3,010 kWh, 1,066.08 € gross, including 12.50 € for
metering, 12 × 90 € advances:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_strom_main","period_from":"2025-01-01","period_to":"2025-12-31",
       "issued_on":"2026-01-20",
       "invoice":{"energy_kwh":3010,"amount_eur":1066.08,"advances_paid_eur":1080},
       "items":[{"label":"Metering","amount_eur":12.5,"kind":"fee"}]}' \
  'http://nas.local:8080/api.php/api/utility/strom/bills'
```

**Response** (`201`) — the app worked out `result_eur` (amount − advances;
negative = credit):

```json
{
  "success": true,
  "data": {
    "id": "b_7c2e19a0f4d3", "meter_id": "m_strom_main",
    "period_from": "2025-01-01", "period_to": "2025-12-31", "issued_on": "2026-01-20",
    "kind": "annual",
    "invoice": { "energy_kwh": 3010, "amount_eur": 1066.08, "advances_paid_eur": 1080,
                 "result_eur": -13.92 },
    "items": [ { "label": "Metering", "amount_eur": 12.5, "kind": "fee" } ],
    "co2": null, "note": "", "attachment_ids": [],
    "created_at": "2026-01-24T19:05:12+01:00"
  }
}
```

Upload a PDF of the bill as a receipt first
(`POST /api/attachments?kind=bill_pdf`, [Receipts](#receipts-and-photos-v310))
and pass its ID in `attachment_ids`.

### Compare — `GET /api/utility/{utility}/bills/{id}/check`

```bash
curl 'http://nas.local:8080/api.php/api/utility/strom/bills/b_7c2e19a0f4d3/check'
```

```json
{
  "success": true,
  "data": {
    "bill_id": "b_7c2e19a0f4d3", "utility": "strom", "meter_id": "m_strom_main",
    "period_from": "2025-01-01", "period_to": "2025-12-31",
    "ours": { "kwh": 3003.0, "energy_cost": 901.47, "fixed_cost": 150.0, "bonus": 0.0,
              "items_total": 12.5, "total": 1063.97, "advances": 1080.0 },
    "invoice": { "energy_kwh": 3010, "amount_eur": 1066.08, "advances_paid_eur": 1080,
                 "result_eur": -13.92 },
    "delta": { "kwh": 7.0, "kwh_pct": 0.23, "eur": 2.11, "eur_pct": 0.2 },
    "verdict": "ok",
    "reasons": [ "estimated_reading_at_boundary", "price_change_inside", "items_not_modelled" ],
    "rows": [ "…" ]
  }
}
```

Recalculated: 901.47 € working price + 150.00 € base price + 12.50 € item =
1,063.97 €. The bill is 7 kWh (0.23 %) and 2.11 € (0.2 %) higher — both below
1 %, hence `ok`. The reasons name what a difference may come from: an
interpolated reading on 1 April, a price change within the period and the item
taken over.

### Book — `POST /api/utility/{utility}/bills/{id}/book`

```bash
curl -X POST 'http://nas.local:8080/api.php/api/utility/strom/bills/b_7c2e19a0f4d3/book'
```

The response is the bill with `special_payment_id` and `contract_id`. The
contract then holds a special payment
`{"date": "2026-01-20", "kind": "rueckzahlung_ohne", "amount_eur": 13.92,
"note": "Annual bill 01/01/2025 – 31/12/2025"}`. A second call changes nothing.
Without a result the route answers `400` with `code` `errors.bill.noResult`,
without a contract `errors.bill.noContract`.

For gas the bill also takes its CO₂ details; they then take precedence over the
standard factor for the CO₂ price:

```bash
curl -X PATCH -H 'Content-Type: application/json' \
  -d '{"co2":{"emissions_kg":2981.5,"cost_eur":163.98}}' \
  'http://nas.local:8080/api.php/api/utility/gas/bills/b_3f9c0a1d2e4b'
```

List, delete:

```bash
curl 'http://nas.local:8080/api.php/api/utility/strom/bills?meter_id=m_strom_main'
curl -X DELETE 'http://nas.local:8080/api.php/api/utility/strom/bills/b_7c2e19a0f4d3'
```

A special payment already booked stays in the contract after deleting.

---

## CO₂ price and sharing *(v3.1.0)*

Germany only; otherwise `supported: false`. Background:
[CO₂ price in fuel](../verstehen/16-co2-preis.md), fields:
[API reference → CO₂ price and sharing](api.md#co₂-price-and-sharing-v310).

### Fetch the CO₂ price — `GET /api/co2-costs`

```bash
curl 'http://nas.local:8080/api.php/api/co2-costs?year=2025'
```

```json
{
  "success": true,
  "data": {
    "supported": true, "scheme": "behg", "year": 2025,
    "rows": [
      { "utility": "gas", "kwh": 10000.0, "factor_kg_per_kwh": 0.18139,
        "emissions_kg": 1813.9, "price_eur_t": 55.0,
        "cost_eur_net": 99.76, "cost_eur_gross": 118.72, "ct_per_kwh": 1.187,
        "source": "computed", "approx": false, "coverage_days": 365 }
    ],
    "total": { "emissions_kg": 1813.9, "cost_eur_net": 99.76, "cost_eur_gross": 118.72 },
    "per_m2": { "kg": 18.1, "area_m2": 100.0, "stage": 3, "landlord_share_pct": 20 },
    "price": { "eur_t": 55.0, "assumed": false }, "vat": 0.19, "note": null
  }
}
```

10,000 kWh × 0.18139 kg/kWh = 1,813.9 kg; × 55 €/t = 99.76 € net, with 19 %
VAT 118.72 € — about 1.19 ct per kWh. The amount is already part of the unit
price. Without `year` the previous year applies; `year=2020` gives `400`
`errors.co2.yearInvalid`.

### Landlord’s share — `GET /api/co2-split`

Only with `wohnverhaeltnis: "miete"`:

```bash
curl 'http://nas.local:8080/api.php/api/co2-split?year=2025'
curl -o co2-2025.pdf 'http://nas.local:8080/api.php/api/reports/co2-split.pdf?year=2025'
```

With your own gas boiler (`case: self_supplied`) the response names stage,
share, `landlord_amount_eur` and `deadline`, the deadline for the refund; the PDF
is the letter to the landlord. With central heating (`case: central`) it checks
the CO₂ details of the service charge statement and reports differences in
`checks`. Examples of both responses:
[API reference](api.md#co₂-price-and-sharing-v310).

### Scenario in the forecast

```bash
curl 'http://nas.local:8080/api.php/api/utility/gas/meters/m_gas_main/forecast?co2_scenario_eur_t=150&co2_scenario_from=2027'
```

The response additionally carries `co2_scenario` (`eur_t`, `from`,
`delta_ct_per_kwh`, `delta_cost_12m_eur`) and, per forecast month from 2027,
`co2_delta_eur`. At 150 instead of 60 €/t that is, for gas,
(150 − 60) × 0.18139 / 10 × 1.19 = 1.94 ct per kWh more. The forecast itself does
not change.

---

## Group contract for peak/off-peak *(v3.1.0)*

An electricity contract for the meter group `g_strom_ab12cd34` with the
registers `m_strom_ht` and `m_strom_nt`: one standing charge, two unit prices.
Rules: [API reference → Group contract](api.md#group-contract-v310). All values
are examples.

### Create — `POST /api/utility/strom/contracts`

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_group_id":"g_strom_ab12cd34","provider":"Example Energy","tariff_name":"Dual rate",
       "start":"2026-01-01",
       "working_prices":[{"from":"2026-01-01","ct_per_kwh":30}],
       "working_prices_by_meter":{"m_strom_nt":[{"from":"2026-01-01","ct_per_kwh":22}]},
       "base_prices":[{"from":"2026-01-01","eur_per_month":12}],
       "advance_payments":[{"from":"2026-01-01","amount_eur":80}]}' \
  'http://nas.local:8080/api.php/api/utility/strom/contracts'
```

`m_strom_ht` calculates with the general unit price (30 ct), `m_strom_nt` with
its own (22 ct). **Response** (shortened):

```json
{ "success": true, "data": {
  "id": "c_9f31d2a7b0e4", "meter_id": null, "meter_group_id": "g_strom_ab12cd34",
  "working_prices": [ { "from": "2026-01-01", "ct_per_kwh": 30 } ],
  "working_prices_by_meter": { "m_strom_nt": [ { "from": "2026-01-01", "ct_per_kwh": 22 } ] },
  "base_prices": [ { "from": "2026-01-01", "eur_per_month": 12 } ], "…": "…" } }
```

If `m_strom_ht` still has a contract of its own in the same period, the API
answers `400` with `"code": "errors.contract.groupMemberOverlap"`.

### Evaluate — `GET /api/utility/strom/meter-groups/{id}/contract-status`

```bash
curl 'http://nas.local:8080/api.php/api/utility/strom/meter-groups/g_strom_ab12cd34/contract-status'
curl 'http://nas.local:8080/api.php/api/utility/strom/meter-groups/g_strom_ab12cd34/forecast'
```

The same form as for a meter; in the forecast the contract carries
`working_prices_blended: true` (blended price of peak and off-peak). The
`contract-status` of a member points to the group contract with
`"group_contract": {"group_id": "g_strom_ab12cd34", "contract_id": "c_9f31d2a7b0e4"}`.

---

## Wholesale electricity prices and dynamic tariff check *(v3.1.0)*

Rules and formula: [API reference → Wholesale prices](api.md#wholesale-electricity-prices-and-dynamic-tariff-check-v310).

### Load from SMARD — `POST /api/market-prices/sync-smard`

The only download of its kind, only on request:

```bash
curl -X POST 'http://nas.local:8080/api.php/api/market-prices/sync-smard'
```

```json
{ "success": true, "data": { "months": 48, "from": "2022-11", "to": "2026-10" } }
```

### Or from a file — `POST /api/market-prices/import-csv?dry_run=1`

```bash
curl -X POST -H 'Content-Type: text/plain' --data-binary $'2025-01;114,14\n2025-02;128,20\n' \
  'http://nas.local:8080/api.php/api/market-prices/import-csv?dry_run=1'
```

```json
{ "success": true, "data": { "months": 2, "from": "2025-01", "to": "2025-02",
  "rows": 2, "skipped": 0, "would_import": 2,
  "preview": { "2025-01": { "avg_ct": 11.414 }, "2025-02": { "avg_ct": 12.82 } } } }
```

Values in €/MWh become ct/kWh (÷ 10). Without `dry_run` they are stored;
`GET /api/market-prices` returns them with the attribution.

### Create a dynamic offer

An electricity shadow contract with the price model `dynamic`:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_strom_main","is_shadow":true,"shadow_label":"Dynamic (example)",
       "start":"2026-11-01","price_model":"dynamic",
       "dynamic":{"markup_ct_per_kwh":15,"base_eur_month":10,"vat_pct":19}}' \
  'http://nas.local:8080/api.php/api/utility/strom/contracts'
```

`GET …/meters/m_strom_main/tariff-switch` then lists the offer with
`"price_model": "dynamic"` and `dynamic_assumed_months` (months for which the
previous year’s month was assumed). If wholesale prices are missing, the offer
is missing and the response carries `"dynamic_missing_market": true`.

---

## Charging record *(v3.1.0)*

For the wall box `m_wallbox` (role `ev_charger`, sub-meter of the household
meter). Fields: [API reference → Charging record](api.md#charging-record-v310).

```bash
# contract price, as JSON
curl 'http://nas.local:8080/api.php/api/reports/ev-charging?meter_id=m_wallbox&year=2026&method=contract'

# flat electricity rate, as CSV in format 1
curl -o ladestrom.csv \
  'http://nas.local:8080/api.php/api/reports/ev-charging.csv?meter_id=m_wallbox&year=2026&method=flat'

# statement as PDF
curl -o ladestrom.pdf \
  'http://nas.local:8080/api.php/api/reports/ev-charging.pdf?meter_id=m_wallbox&year=2026&method=flat'
```

The CSV (flat rate 34 ct/kWh, made-up amounts):

```text
Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode
2026-01;m_wallbox;180,5;34;0;61,37;flat
2026-02;m_wallbox;162;34;0;55,08;flat
```

For a year without a flat rate in the country profile, pass it with
`&flat_ct=…`; otherwise the API answers `400` with
`"code": "errors.evReport.flatMissing"`.

---

## Time series from a portal *(v3.1.0)*

A load profile from the grid operator (quarter hours in kWh, end stamp) as a
dry run on the electricity meter `m_strom_main`; the reading on 1 January is
`40211`. Fields: [API reference → Import time series](api.md#import-time-series-v310).

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"csv":"Datum;Uhrzeit;Wert (kWh)\n01.01.2025;00:15;0,061\n01.01.2025;00:30;0,058\n02.01.2025;00:00;0,071",
       "mapping":{"skip_rows":1,"date_col":0,"time_col":1,"value_col":2,
                  "value_kind":"consumption","unit_factor":1,"interval_stamp":"end",
                  "start_counter":40211}}' \
  'http://nas.local:8080/api.php/api/utility/strom/meters/m_strom_main/import-series?dry_run=1'
```

```json
{ "success": true, "data": {
  "rows_read": 3, "skipped": 0, "errors": [], "days": 1,
  "from": "2025-01-01", "to": "2025-01-01", "total": 0.19,
  "value_kind": "consumption",
  "preview": [ { "date": "2025-01-01", "value": 0.19 } ],
  "target": "readings", "readings": 2, "dry_run": true } }
```

All three quarter hours belong to 1 January — the last one ends at 0:00 on
2 January. Without `dry_run` two readings are created: 40211 on 1 January (the
start value) and 40211.19 on 2 January.

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
    },
    "attachments": [ { "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "…": "…" } ],
    "attachment_files": { "att_5f0c2a9e81d34b67": "/9j/4AAQSkZJRgABAQ…" }
  }
}
```

Since v3.1.0 the receipts are part of the backup: the index under
`attachments`, the files base64-encoded under `attachment_files`. The format
stays `3.0`. `?attachments=0` leaves out the files (the index stays) — for a
quick backup of the figures:

```bash
curl -o backup-without-photos.json 'http://nas.local:8080/api.php/api/backup/export?attachments=0'
```

### `POST /api/backup/import`

Restores a backup. Only formats `backup_version: "3.0"` or higher are accepted — for
older formats the migrator (see below) is responsible.

**Body:** the `data` object from the export, i.e. top-level with `backup_version`,
`temperatures`, `settings`, `utilities`, … Since v2.6.0 it may also be the whole
export response (`{success, data}`) — a file saved with
`curl …/backup/export > backup.json` can be restored directly.

**Flow since v2.6.0:** check first, then write. If a pot is not a list of
objects, mandatory fields are missing or a date is invalid, the import changes
nothing and answers `400` with the findings in `detail.problems`. Before writing
it creates a safety snapshot `pre-restore-…`; if that fails, it answers `409` —
with `?allow_without_snapshot=1` it proceeds anyway. `?dry_run=1` stops after the
check.

**Response:**

```json
{
  "success": true,
  "data": {
    "utilities": {
      "gas":   { "meters": 1, "readings": 41, "contracts": 6, "deliveries": 0, "meter_groups": 0 },
      "strom": { "…": "…" }
    },
    "untouched": [],
    "problems": [],
    "temperatures": 1277,
    "settings": 21,
    "reminders": 6,
    "recommendations_dismissed": 0,
    "attachments": 12,
    "attachment_files": 12,
    "auto_snapshot_before_restore": "pre-restore-2026-09-25_000438.json"
  }
}
```

`untouched` names pots that are missing from the backup and therefore stay
unchanged (partial restore). With `dry_run`, `"dry_run": true` appears instead
of the snapshot. Since v3.1.0 `attachment_files` counts the receipt files of
the backup; each one is first checked against the `sha256` and content type of
its index entry (problems `sha256:<id>`, `mime:<id>`, `unknown:<id>`). An
existing file with the same ID stays untouched.

### `POST /api/backup/snapshot`

Places a snapshot under `data/backups/backup_YYYY-MM-DD_HHMMSS.json`.

**Response:** `{ "success": true, "data": { "file": "backup_2026-09-25_001317.json" } }`

List, download, restore and delete: `GET /api/backup/snapshots`,
`GET|DELETE /api/backup/snapshots/{name}`,
`POST /api/backup/snapshots/{name}/restore` *(v2.6.0)* — see the
[API reference](api.md#snapshots-and-import-v260).

---

## CSV export

A tabular export for spreadsheets (F-07, since v1.1.0). Five datasets (since
v3.1.0 with the periods), each as a **file download** — the response is **not** JSON but `text/csv` with
`Content-Disposition: attachment`.

Since v3.1.0 in two formats:

- **Format 1** (default, `?format=1` or no parameter): frozen for scripts —
  semicolon, UTF-8 with BOM, CRLF, decimal comma without thousands separators,
  ISO dates, `ja`/`nein`, fixed headers.
- **Format "local"** (`?format=local`, optionally `&lang=xx`): the spreadsheet
  in one language — headers, decimal separator, dates and yes/no of the
  language, field separator `;` or `,` to match the decimal separator. Without
  `lang` the default language of the installation.

All rules, headers and file names:
[API reference → CSV formats](api.md#csv-formats-v310). An unknown format → 400
with `"code": "errors.export.formatInvalid"`.

Supplements the complete JSON backup — for a re-importable backup still use
`GET /api/backup/export`.

### `GET /api/export/{utility}/monthly.csv`

Monthly aggregates of a utility over all meters: month, days, consumption, costs,
advance, monthly balance, cumulative balance, avg temperature, HDD, CO₂.

```
Month;Days;Consumption (kWh);Cost (EUR);Advance payment (EUR);Monthly balance (EUR);Cumulative balance (EUR);Avg temp (°C);HDD;CO2 (kg)
2025-01;31;310,5;93,15;80;13,15;13,15;0,6;28,9;106,8
```

Format 1 of an installation whose default language is English: English header,
yet a semicolon and a decimal comma — the format is frozen.

### `GET /api/export/{utility}/readings.csv`

All raw readings of a utility, one row per reading: meter ID, meter name, device ID,
date, counter, price, note, estimated flag, future flag.

Format 1:

```
Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand;Preis (ct);Notiz;Geschaetzt;Zukunft
m_strom_main;Hauptzähler;d_strom_1;2025-02-01;310,5;;;nein;nein
```

`?format=local&lang=en` — a comma as separator because English writes a decimal
point; names and notes are data and stay as they were entered:

```
Meter ID,Meter,Device ID,Date,Reading,Price (ct),Note,Estimated,Future
m_strom_main,Hauptzähler,d_strom_1,01/02/2025,310.5,,,No,No
```

### `GET /api/export/{utility}/deliveries.csv`

Deliveries of heating oil or pellets, one row per delivery: tank ID, tank name,
date, quantity, unit price, total, supplier, note, planned flag. 400 for the
other utilities.

### `GET /api/export/{utility}/periods.csv`

*(v3.1.0)* Consumption per period, one row per period across all meters of the
utility: from, to (inclusive), value, note, meter ID, meter name, unit,
estimated flag, source. Format 1:

```
Von;Bis;Wert;Notiz;Zaehler-ID;Zaehler;Einheit;Geschaetzt;Quelle
2026-09-01;2026-09-30;410;Verbrauchsinfo;m_waerme_1a2b3c4d;Wärmezähler;kWh;nein;manual
```

The period import reads the file back in (only the rows of the chosen meter).

### `GET /api/export/temperatures.csv`

The temperature series as daily values: date, avg, min, max.

---

## Migration from v0.9.0

A two-stage flow — preview (no write) followed by import with a mode choice. For a
detailed guide see [`MIGRATION-FROM-V090.md`](../anleitungen/migration-v090.md).

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
> in [`docs/HOME-ASSISTANT.md`](../anleitungen/home-assistant.md).

### Authentication model (opt-in)

The token protects the ingest endpoint **only**: without a token it accepts
values without a header; as soon as a token has been created, it requires
`Authorization: Bearer <token>`. Since v2.6.0 the other routes are protected by
**sign-in** (opt-in, Settings → Access → "Sign-in & access"); once that is switched on,
the token is **mandatory** for the ingest. Details:
[Security](../betrieb/sicherheit.md).

### `GET /api/auth/token`

Status (never the token itself):

```json
{ "success": true, "data": { "enabled": true, "created_at": "2026-06-01T12:00:00+02:00",
                             "last_used_at": "2026-09-24T18:00:00+02:00" } }
```

`last_used_at` *(v2.6.0)*: the last push with this token, accurate to the hour
— `null` as long as nothing has arrived.

### `POST /api/auth/token`

Creates a new token (replaces an existing one). The plaintext token is returned
**only in this response** — afterwards only its SHA-256 hash is stored (in
`data/auth.json`, not in `settings.json`; excluded from the backup).

```json
{ "success": true, "data": { "token": "et_…48hex…", "created_at": "…", "hint": "…" } }
```

### `DELETE /api/auth/token`

Revokes the token → the ingest is reachable without a token again (only without
sign-in; with sign-in it then rejects every push with `401`).

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

- `utility` — a utility with meter readings (`gas|strom|wasser|fernwaerme|
  pv_einspeisung|pv_erzeugung|waerme`; heating oil/pellets are rejected — they use
  deliveries instead of readings). A meter with consumption per period
  (v3.1.0) accepts no readings either: `400` `errors.ingest.periodMeter`.
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
    "reading_id": "20260601-ab12cd34",
    "suspect": false
  }
}
```

`suspect` *(v2.6.0)*: if the value is lower than the previous reading of the
same device, it is stored but marked as suspect (`"suspect": true`, plus
`"previous": {"date", "counter"}`) and only counts after confirmation in the
interface (view of the utility). A sensor dropout with 0 can therefore no longer
create phantom consumption. A register rollover (99,998 → 12) is not suspect when
the number of digits (`digits`) is maintained on the meter.

**Errors:** `401` (token needed/wrong; with sign-in switched on also without a
token set), `400` (unknown utility, meter not found, no/invalid value, delivery
utility, meter with consumption per period).
