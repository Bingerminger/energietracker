# Data model

**English** · [Deutsch](../../referenz/datenmodell.md)

[← API reference](api.md) · [Compendium index](../README.md)

All data is stored as flat JSON files under `data/`. No database. Writes are
serialised by `LOCK_EX`. Schema level: **1.7.0** (in `data/meta.json` and in every
backup).

> **Schema history (short form):** 1.0.0 utility-oriented layout · 1.0.3 water
> three-component contracts · 1.1.0 district heating/heating oil/pellets +
> `reminders.json` · **1.2.0** meter topology (`parent_meter_id`, `meter_group_id`,
> `meter_groups.json` per utility — F1006) · **1.3.0** meter alias `external_id`
> for the Home Assistant integration (F1009) · **1.4.0** analysis baseline
> dates `baseline_events` on the meter (F1011) · **1.5.0** dated gas
> conversion factors `gas_conversion_factors` in `settings.json` instead of the
> scalar `gas_conversion_factor` (F1012) · **1.6.0** previous CO₂ and water
> defaults pinned in existing installations before the corrected ones apply
> (v2.10.0, Lesson 36) · **1.7.0** pots `attachments.json` (receipts),
> `tenancies.json` and `tenancy_statements.json` (tenancy), `market_prices.json`
> (wholesale electricity prices), `periods.json`
> (consumption per period) and `bills.json` (supplier bills) per utility and the
> new utility `waerme/` created empty, as well as missing basic pots (v3.1.0) ·
> v3.2.0 **without** a schema step: `ev_sessions.json` (charging sessions from
> evcc) is created by the first import, people are stored in `auth.json`.

---

## 1. Directory and file layout

```text
data/
├── meta.json                 # { schema_version, migrated_at, log[] }
├── settings.json             # settings (defaults: SettingsService::DEFAULTS)
├── auth.json                 # sign-in, people (v3.2.0), HA token, API keys — passwords and keys only as hashes; not in the backup
├── temperatures.json         # { "YYYY-MM-DD": { avg, min, max, source }, … } — source since v2.8.0
├── climate_normal.json       # climate normal at the location (v2.8.0) — key figures only, no raw data
├── weather_sync.json         # state of the last Open-Meteo sync (v2.8.0)
├── reminders.json            # appointments/maintenance
├── recommendations_dismissed.json
├── attachments.json          # index of the receipts (v3.1.0, schema 1.7.0)
├── attachments/              # receipt files <id>.<jpg|png|webp|pdf> (v3.1.0)
├── tenancies.json            # tenancies (v3.1.0, schema 1.7.0)
├── tenancy_statements.json   # service charge statements (v3.1.0, schema 1.7.0)
├── market_prices.json        # wholesale electricity prices as monthly averages (v3.1.0, schema 1.7.0)
├── ev_sessions.json          # charging sessions from evcc (v3.2.0) — created by the first import
├── instance.json             # identifier of the installation (v3.1.0) — not in the backup
├── gas/        { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── strom/      { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── wasser/     { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── fernwaerme/ { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── heizoel/    { meters.json, deliveries.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── pellets/    { meters.json, deliveries.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── pv_einspeisung/ { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── pv_erzeugung/   { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── waerme/     { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }   # heat (v3.1.0)
├── logs/       # JSON Lines log (N1010)
├── .write.lock # write lock (v2.5.3)
└── backups/    # snapshots: backup_… (own), pre-restore-/pre-migration-/pre-demo-/pre-v09-… (automatic)
```

**Snapshots (v2.6.0):** name `<prefix>YYYY-MM-DD_HHMMSS[-n].json`; the prefix
determines the occasion (`reason` in `GET /api/backup/snapshots`). Retention: of
your own the last ten, automatic ones 30 days, at least the three newest per
occasion. A snapshot is streamed into a temporary file and only renamed at the
end. Since v3.1.0 it contains the receipts (base64) — so `backups/` grows with
every photo, once per snapshot.

Cumulative utilities (gas, electricity, water, district heating, PV, since
v3.1.0 heat) have `readings.json`; delivery-based utilities (heating oil,
pellets) have `deliveries.json` instead. `contracts.json` exists for all of
them, but is typically empty for heating oil/pellets — there the **tank invoice
itself** is the cost basis (see [Heating oil](../verstehen/05-heizoel.md)). For
heat and PV generation it stays empty: neither has contracts.
`meter_groups.json` (since 1.2.0) holds the group master data per utility; the
group *membership*, by contrast, sits on the meter (`meter_group_id`).
`periods.json` (v3.1.0) holds the periods of meters recording “consumption per
period” ([Period](#period-v310)); it is created for every utility but used only
by those with meter readings. `bills.json` (v3.1.0) holds the supplier bills
([Supplier bill](#supplier-bill-v310)); likewise created for every utility,
used for gas, electricity, water and district heating.

**Heat (`waerme/`, v3.1.0).** The ninth utility: heat arriving in the home, in
kWh. Its folder is created empty with schema 1.7.0, in existing installations
too; there are no default meters, and it only becomes active once chosen under
Settings → Utilities & billing (`active_utilities`). More in
[Heat](../verstehen/15-waerme.md).

**`instance.json` (v3.1.0).** `{instance_id, created_at}` — the identifier of
the installation, `et_` and 16 hex digits, created at random when first needed.
The calendar builds the UIDs of its events from it, `GET /api/summary` returns
it as `instance_id`. The file deliberately stays **out** of the backup:
otherwise, after a restore onto a second installation, both would share the
same identifier.

**Temperatures and weather (v2.8.0, additive — no schema bump):**

- `temperatures.json`: every day carries `source` — `archive` (measured value from
  the Open-Meteo archive), `forecast` (forecast, replaced by the archive value),
  `csv` or `manual` (your own values, never overwritten). Entries without `source`
  come from older versions; if they are older than the archive delay (six days),
  they count as measured.
- `climate_normal.json`: `version`, `latitude`/`longitude` (rounded), `period`
  `{from, to}` (the last 30 full calendar years), `fetched_at`, `days`, `doy_avg`
  (366 mean daily means, day 60 = 29 February) and `hdd` — per heating limit from
  `"10.0"` to `"22.0"` in half-degree steps `{mean[12], sd[12], year_sd, years}`.
  Fetched again when the location moves by more than 0.05° or the period no longer
  reaches up to the previous year. Not part of the backup: the file can be
  regenerated at any time.
- `weather_sync.json`: `last_sync_at`, `last_sync_date` (for "once a day"),
  `measured_until`, `forecast_until`, `archive_error`, `forecast_error`,
  `climate_normal` (status of the last sync).

> **`auth.json`** (F1009, extended in v2.6.0) contains **hashes** only, never
> plaintext, and is **excluded** from the backup. If the file is missing,
> sign-in is off and the ingest is reachable without a token. An **unreadable**
> file does not count as empty: access fails (503) instead of silently opening.
>
> ```jsonc
> {
>   "token_hash": "…sha256…", "created_at": "…", "token_last_used_at": "…",  // HA ingest (F1009)
>   "mode": "password",                 // off | password | proxy (ET_AUTH takes precedence)
>   "password_hash": "$2y$10$…",        // password_hash(); ET_ADMIN_PASSWORD_HASH takes precedence
>   "session_secret": "…",              // HMAC key of the session cookies; new with every new installation password
>   "login_failures": { "count": 1, "first_at": 1758700000, "locked_until": 0 },
>   "api_keys": [ { "id": "k_…", "name": "backup script", "scope": "read",
>                   "hash": "…sha256…", "created_at": "…", "last_used_at": null } ],
>   "users": [ … ]                      // people in the household (v3.2.0)
> }
> ```
>
> The people: [People in `auth.json`](#people-in-authjson-v320).
> Details: [Security](../betrieb/sicherheit.md), [API reference → sign-in](api.md).

---

## 2. Core schemas

### Meter (a meter or a tank/store)

```json
{
  "id": "m_gas_main",
  "name": "Main meter gas",
  "icon": "🔥",
  "created_at": "2023-01-01",
  "active": true,
  "notes": "Cellar, left",

  // Meter topology (F1006, since schema 1.2.0) — default null:
  "parent_meter_id": null,   // set = submeter of this parent meter
  "meter_group_id": null,    // set = member of this meter group

  // HA integration (F1009, since schema 1.3.0) — default null:
  "external_id": "gaszaehler_haus",  // alias for POST /api/ingest

  // Analysis baseline dates (F1011, since schema 1.4.0) — default []:
  "baseline_events": [
    { "date": "2021-09-01", "label": "Loft insulation" }
  ],

  "devices": [ Device, … ],

  // only for delivery-based utilities (heating oil/pellets):
  "capacity": 3000.0,
  "capacity_unit": "L",
  "initial_stock": 2400.0,
  "initial_stock_price_ct": 98.5,       // v2.10.0, optional: price of the initial stock
  "tank_levels": [                       // v2.10.0, optional: tank readings (anchors)
    { "date": "2025-09-17", "level": 1650, "note": "Dipstick" }
  ],

  // electricity only, optional (v2.10.0):
  "heat_source": true,                   // heat pump: counts in the efficiency figure

  // v3.1.0, optional — missing = default:
  "role": "heat_pump",                   // role of the meter, per utility (see below)
  "capture": "period",                   // way of recording: missing = meter readings

  // v3.1.0, optional — for switching supplier (not for heating oil/pellets):
  "malo_id": "51234567895",              // market location ID (synthetic example)
  "melo_id": "DE…",                      // metering location ID, 33 characters

  // v3.1.0, PV generation only, optional:
  "plug_in": true,                       // plug-in solar device without a feed-in meter
  "investment_eur": 800,                 // investment incl. VAT (payback)
  "commissioned_on": "2025-04-12",       // commissioning (payback, § 51 EEG)
  "battery_capacity_kwh": 5.0,           // storage capacity, on the meter with role battery_charge

  // v3.1.0, heat with role heat_pump_output only, optional:
  "heat_pump_meter_ids": ["m_wp_strom"]  // electricity meters of the heat pump (role heat_pump)
}
```

**PV and heat pump fields (v3.1.0).** `plug_in` (`true` or missing),
`investment_eur` (0–10,000,000), `commissioned_on` (ISO date) and
`battery_capacity_kwh` (0–10,000) exist only for `pv_erzeugung`; invalid values →
`errors.meter.valueInvalid`, empty removes the field. They feed payback, battery
figures and the plug-in solar assumption in `GET /api/pv-summary`
([API](api.md#pv-battery-plug-in-solar-payback-v310-additive)).
`heat_pump_meter_ids` (only `waerme`) names electricity meters with the role
`heat_pump`, otherwise `errors.meter.heatPumpLinkInvalid`; `GET /api/heat-pump`
calculates the seasonal performance factor from it
([API](api.md#seasonal-performance-factor-of-the-heat-pump-v310)).

**Market and metering location (`malo_id`, `melo_id`, v3.1.0).** The market
location ID has 11 digits, the first not 0, the last a check digit per BDEW;
the metering location ID has 33 characters, “DE” and 31 digits or capital
letters. The app removes spaces, an empty value deletes the field; it rejects
anything else (`errors.meter.maloInvalid`, `…meloInvalid`). The switching
decision shows the market location ID under “Have ready for switching”.

**Role (`role`, v3.1.0).** What a meter measures, for the utilities that know
roles. The first is the default and is **not** stored — a meter without `role`
has it.

| Utility | Roles (first = default) |
|---|---|
| `strom` | `household`, `heat_pump` (heating electricity, counts in the efficiency figure), `ev_charger` (wallbox: charging record; not part of household electricity in the benchmark, nor is `heat_pump`) |
| `wasser` | `cold`, `warm` (hot water: the heat needed for it is shown as a calculated value), `garden` |
| `pv_erzeugung` | `generation`, `battery_charge`, `battery_discharge` (battery: not counted as generation nor in sums, only in the battery figures) |
| `waerme` | `consumption` (heat of the home, counts in the efficiency figure), `heat_pump_output` (output of a heat pump, counts neither in sums nor in the efficiency figure — it would otherwise be counted twice next to the heating electricity; basis of the seasonal performance factor) |

For electricity, `role: heat_pump` and the older field `heat_source: true` stay
in step: setting one sets the other, so older versions keep recognising the
heat pump. A role the utility does not know (or any role for a utility without
roles) is rejected by the API with 400 (`errors.meter.roleInvalid`).
`GET /api/readings-overview` names the role of every meter (`null` for
utilities without roles).

**Way of recording (`capture`, v3.1.0).** `counter` (meter readings, default,
not stored) or `period` (consumption per period) — for all utilities with meter
readings, not for heating oil and pellets. A meter with `period` has periods in
`periods.json` instead of readings; it rejects readings and Home Assistant
pushes (`errors.reading.periodMeter`, `errors.ingest.periodMeter`). The way of
recording can only be switched while the meter has no data of the current kind
(400 `errors.meter.captureLocked`); an unknown value gives
`errors.meter.captureInvalid`.

**Tank log (v2.10.0).** `tank_levels` and deliveries with `fill_to_full` are
anchors with a known stock; between them the consumption is calculated,
afterwards estimated ([Heating oil §3](../verstehen/05-heizoel.md)). If
`initial_stock_price_ct` is missing, the initial stock costs the price of the
first delivery. No schema step: the fields are optional and travel in the
backup.

**Meter topology (F1006).** A meter can be a **submeter** of another
(`parent_meter_id`, series connection — its consumption is subtracted from the
parent meter) and/or a **member of a group** (`meter_group_id`, combines several
meters for the dashboard; since v3.1.0 also for a shared contract). Rules: at most
one submeter level (no chains/cycles); a
parent meter with submeters cannot be deleted without removing the assignment. See
[meter topology](../verstehen/13-meter-topologie.md).

**`external_id` (F1009).** A freely assignable, per-utility-unique alias
(`[A-Za-z0-9_.-]{1,64}`) for the Home Assistant integration. `POST /api/ingest`
accepts it in place of the internal ID. Default `null` = no alias.

### Meter group (`meter_groups.json`, F1006)

```json
{ "id": "g_strom_ab12cd34", "name": "Off-peak + peak electricity", "created_at": "2026-06-01" }
```

Pure master data (ID + name). Which meters belong to it is **not** stored here, but
as `meter_group_id` on the respective meter (single source of truth). Since
v3.1.0 a group can be the target of a contract (`meter_group_id` on the
contract, see below); the order of the members is that of the meter list, and
the first one carries the fixed costs of the group contract.

### Device (a device within a meter — meter swap)

```json
{
  "id": "d_gas_001",
  "serial": "G-2018-447",
  "installed_on": "2018-03-01",
  "initial_counter": 0.0,
  "removed_on": "2024-10-01",
  "final_counter": 1562.0,
  "reason": "Calibration swap",
  "digits": 5                  // optional (v2.6.0): register digits, 3–12
}
```

Consumption across a swap boundary:
`(old_final − previous_reading) + (current_reading − new_initial)`.

**`digits`** (v2.6.0, additive, missing = unknown): if the reading on the same
device "falls back" by at most a tenth of the counting range, it is a rollover:
consumption = `new + 10^digits − old` (99,998 → 12 with five digits = 14).

### Reading (a meter reading — cumulative utilities)

```json
{
  "id": "r_gas_0001", "meter_id": "m_gas_main", "device_id": "d_gas_001",
  "date": "2023-02-02", "counter": 148.7, "price_cents": null,
  "note": "", "is_estimated": false, "is_future": false
}
```

`is_future: true` marks pre-noted entries — they stay visible but are **not**
included in the consumption calculation.

Two optional fields since v2.6.0 (additive, only present when set):

- `source`: `ingest` (Home Assistant) or `csv` (CSV import).
- `is_suspect: true`: a falling reading from the ingest; it does not count until
  confirmed (`PATCH` with `is_suspect: false`) or corrected.

Two more since v3.1.0, likewise optional:

- `client_ref`: an identifier of the entry (8–64 characters `[A-Za-z0-9-]`),
  chosen by the client. Creating a second reading with the same identifier on
  the same meter does not add a new one (offline queue,
  [API](api.md#readings-client_ref-attachment_id-v310-additive)).
- `attachment_id`: photo of the meter reading — ID of a receipt with
  `kind: reading_photo` ([Receipts](#receipts-v310)).

The consumption calculation also skips **sandwiched outliers** of the same
device and reports them as `warnings` (see
[Meter readings → plausibility](../verstehen/11-zaehlerstaende.md)).

### Receipts *(v3.1.0)*

Files belonging to a record — photos of meter readings, receipts for periods,
PDFs or photos of service charge statements and, since v3.1.0, of supplier bills
(`ref.type` `reading`, `period`, `tenancy_statement`, `bill`). The file lives
under `data/attachments/<id>.<ext>`,
its entry in the index `attachments.json` (a list):

```jsonc
{
  "id": "att_5f0c2a9e81d34b67",        // att_ + 16 hex digits
  "kind": "reading_photo",             // reading_photo | bill_pdf | statement_pdf | other
  "mime": "image/jpeg",                // image/jpeg | image/png | image/webp | application/pdf — determined from the content
  "size": 284113,                      // bytes
  "sha256": "9c1e…",
  "created_at": "2026-10-07T08:12:40+02:00",
  "ref": { "type": "reading", "utility": "strom", "id": "20261007-1a2b3c4d" },   // or null
  "original_name": "meter.jpg"         // optional, if given on upload
}
```

- **Reference in both directions.** `ref` points to the record, the record
  carries `attachment_id`. Both are set and removed together through the API
  ([Receipts](api.md#receipts-and-text-recognition-v310)).
- **Orphaned** is a receipt with `ref: null` — uploaded and never saved, or
  the reading was deleted or the photo detached; then `unlinked_at` holds the
  time. It is cleaned up after **24 hours** (from `unlinked_at`, otherwise from
  `created_at`), on the next upload and when the snapshots rotate. Files in
  `attachments/` without an index entry likewise.
- **Limits.** Photo 3 MB, PDF 10 MB, all together `attachments_max_mb`
  (default 500 MB).
- **Backup.** `attachments` is a pot like any other; the files are stored
  base64-encoded under `attachment_files` (`{id: base64}`). The backup format
  stays `3.0`; older versions skip the key. The import checks every file
  against `sha256` and content type before writing
  ([Snapshots and import](api.md#snapshots-and-import-v260)).

### Period *(v3.1.0)*

Consumption per period — for values that already come as consumption, such as
the monthly consumption information from the metering service. Only on meters
with `capture: "period"`, per utility in `<utility>/periods.json` (a list):

```jsonc
{
  "id": "p_3a9f1c20b7e4",            // p_ + 12 hex digits
  "meter_id": "m_waerme_1",
  "from": "2026-01-01",
  "to": "2026-01-31",                // inclusive
  "value": 820,                      // ≥ 0, rounded to three decimals
  "value_unit": "consumption",       // consumption | meter — differ only for gas
  "is_estimated": false,
  "source": "manual",                // manual | csv | import
  "reference": {                     // optional, comparison values of the consumption information
    "prev_month": 760, "prev_year_month": 900, "average_user": 850
  },
  "note": "",                        // up to 500 characters
  "attachment_id": null,             // optional: receipt (photo, PDF)
  "client_ref": "…"                  // optional, as for readings
}
```

- **Unit.** `consumption` is the utility's consumption unit (kWh, for water
  m³), `meter` the meter unit. The two differ only for gas: `meter` = m³,
  converted to kWh with the dated conversion factors; `consumption` = kWh, the
  app works the m³ back out for the bill check and the CSV. For every other
  utility `value_unit` is always `consumption`.
- **Rules.** `from ≤ to` (otherwise `errors.period.order`); two periods of the
  same meter must not touch (`errors.period.overlap`). `client_ref` works as
  for readings: the same identifier on the same meter does not create a second
  period.
- **Calculation.** Daily rate = value / days of the period, spread over the
  months to the day; gaps stay gaps (coverage as with meter readings). After
  that everything runs as with readings. More in
  [Heat](../verstehen/15-waerme.md).

### Tenancy and service charge statement *(v3.1.0)*

For tenants who pay heating and water through the service charges
([guide](../anleitungen/mieter.md)). Two lists at the top level.

`tenancies.json`:

```jsonc
{
  "id": "t_8c21e4f09a3b",
  "start": "2024-04-01",
  "end": null,                       // optional; empty = ongoing
  "label": "Flat 2nd floor",
  "landlord": "",                    // optional
  "wohnflaeche_m2": 68,              // optional, per tenancy agreement; empty = setting
  "co2_own_appliances": false,       // v3.1.0 (H4): gas also for own appliances → refund × 0.95
  "co2_restriction": "none",         // v3.1.0 (H4): none | one | both (§ 9 CO2KostAufG)
  "billing_anchor": "01-01",         // MM-DD, start of the billing period (default 01-01)
  "prepayments": [                   // dated, sorted by from
    { "from": "2024-04-01", "heating_eur_month": 70, "operating_eur_month": 50 }
  ],
  "prices": [
    { "from": "2025-01-01", "heat_eur_per_kwh": 0.15, "warm_water_eur_per_m3": 9.5,
      "cold_water_eur_per_m3": 4.5, "source": "statement", "statement_id": "s_…" }
  ],
  "fixed_costs": [                   // flat charges per year
    { "from": "2025-01-01", "label": "Waste", "eur_per_year": 180 }
  ],
  "meter_ids": { "heat": ["m_waerme_1"], "warm_water": ["m_ww_1"], "cold_water": ["m_kw_1"] },
  "notes": ""
}
```

- `prepayments`, `prices`, `fixed_costs` are dated lists: the latest entry with
  `from ≤ day` applies; for prices per field, for flat charges per label.
  Prices are optional (`source`: `statement` taken over from a statement,
  `estimate` entered yourself).
- `meter_ids`: `heat` takes meters of the Heat utility, `warm_water` and
  `cold_water` water meters. An unknown meter gives
  `errors.tenancy.meterNotFound`.

`tenancy_statements.json`:

```jsonc
{
  "id": "s_51d0a7c3e2f6",
  "tenancy_id": "t_8c21e4f09a3b",
  "period_from": "2025-01-01", "period_to": "2025-12-31",
  "received_on": "2026-06-15",       // optional: receipt, starts the objection period
  "total_cost_eur": 1500, "prepaid_eur": 1440,
  "result_eur": 60,                  // positive = additional payment, negative = credit
  "positions": [
    { "label": "Hot water", "category": "warm_water", "amount_eur": 285, "consumption": 30, "unit": "m³" }
  ],
  "heat": { "consumption": 9000, "unit": "kWh", "cost_eur": 1350 },   // optional; unit kWh | MWh
  "co2": null,                       // optional (v3.1.0, H4): {emissions_kg, cost_eur, stage,
                                     //   landlord_share_pct, landlord_amount_eur} per heating cost statement
  "new_prepayment": { "from": "2026-07-01", "heating_eur_month": 75, "operating_eur_month": 50 },
  "attachment_ids": [],              // receipts (PDF, photo)
  "booked": false,
  "note": "",
  "created_at": "2026-06-20T18:02:11+02:00"
}
```

- `category` ∈ {`heating`, `warm_water`, `cold_water`, `sewage`, `operating`,
  `other`}; an unknown value becomes `other`.
- `result_eur` missing in the request → costs − prepayment; positive means an
  additional payment (as with the balance).
- Saving a statement with `apply_prices` adds a price entry `source: statement`
  to the tenancy, valid from the day after `period_to`; `apply_prepayment`
  takes `new_prepayment` over into `prepayments`. Both are switches of the
  request, not stored fields ([API](api.md)).
- Deleting a tenancy takes its statements with it; their receipts become free
  and are cleaned up after 24 hours.
- **CO₂ costs (v3.1.0, H4).** `co2_own_appliances` and `co2_restriction` on the
  tenancy reduce the landlord’s share with your own gas boiler; `co2` on the
  statement carries the CO₂ details of the heating cost statement (emissions
  0–10,000,000 kg, amounts up to 1,000,000, stage 1–10, share 0–100 %). If `co2`
  is set, the central-heating case applies for the year in which the period ends
  ([Share CO₂ costs](../anleitungen/co2-aufteilung.md)).

All amounts in the main currency (`*_eur` means the configured currency, see
`currency`). The values above are examples.

**Backup.** `tenancies`, `tenancy_statements` and, per utility, `periods` and
`bills` are pots like the others and travel in the backup; the format stays
`3.0`. `instance.json` stays out (see above).

### Supplier bill *(v3.1.0)*

The supplier’s bill with its own figures, per utility in `<utility>/bills.json`
(a list) — for comparing with your own calculation, booking the result and the
CO₂ details
([Annual bill](../anleitungen/jahresabrechnung.md#7-on-the-bill-enter-compare-book)):

```jsonc
{
  "id": "b_3f9c0a1d2e4b",            // b_ + 12 hex digits
  "meter_id": "m_gas_main",
  "contract_id": null,               // optional; otherwise the contract at the end of the period
  "kind": "annual",                  // annual | final | interim
  "period_from": "2025-01-01",
  "period_to": "2025-12-31",         // inclusive
  "issued_on": "2026-02-10",         // optional: bill date
  "invoice": {                       // every value optional
    "energy_kwh": 16437,             // for water volume_m3
    "amount_eur": 1655,              // bill amount incl. VAT
    "advances_paid_eur": 1800,
    "result_eur": -145               // positive = additional payment; without it amount − advances
  },
  "items": [                         // other items, not recalculated
    { "label": "Metering", "amount_eur": 12.5, "kind": "fee" }   // levy | fee | credit | other
  ],
  "co2": { "emissions_kg": 2981.5, "cost_eur": 163.98 },   // optional; amount incl. VAT as on the bill (CO2KostAufG § 3(3)), plus stated_factor?
  "attachment_ids": [],              // receipts (PDF, photo)
  "special_payment_id": null,        // set once the result is booked
  "note": "",
  "created_at": "2026-02-14T18:20:05+01:00"
}
```

- **Utilities.** Only gas, electricity, water and district heating accept bills
  (`errors.billCheck.unsupportedUtility` otherwise); the pot exists for all.
- **Rules.** `period_from ≤ period_to` (`errors.bill.periodInvalid`), numbers
  within the allowed range (`errors.bill.amountInvalid`). A meter with bills
  cannot be deleted (`errors.meter.hasBills`).
- **Booking** adds a special payment “not affecting advances” in the contract
  and keeps its ID in `special_payment_id` — once per bill. Deleting the bill
  keeps the special payment; the receipts are released.
- **CO₂.** Emissions and amount take precedence over the standard factor for
  the CO₂ price; the `issued_on` of a gas bill sets, for tenants, the deadline
  for the refund ([CO₂ price in fuel](../verstehen/16-co2-preis.md)).

### Delivery (a fuel delivery — heating oil/pellets)

```json
{
  "id": "del_heizoel_a1", "meter_id": "m_heizoel_tank",
  "date": "2023-09-12", "quantity": 1150.0,
  "unit_price_cents": 104.5, "total_eur": 1201.75,
  "supplier": "Oil Müller GmbH", "note": "Autumn refill",
  "is_planned": false,
  "fill_to_full": false          // v2.10.0: true = full afterwards (anchor)
}
```

`quantity` in the utility's `volume_unit` (litres for heating oil, kg for pellets).
Cost basis since v1.4.2: **`total_eur` takes precedence**; only if no total amount
is set is `unit_price_cents` used.

### Contract (a contract — primarily for gas/electricity/water/district heating)

```json
{
  "id": "c_gas_001", "meter_id": "m_gas_main",
  "provider": "City works", "tariff_name": "Basic 2023",
  "start": "2023-01-01", "end": "2023-12-31", "notes": "",
  "working_prices":   [ { "from": "2023-01-01", "ct_per_kwh": 11.8 } ],
  "base_prices":      [ { "from": "2023-01-01", "eur_per_month": 11.9 } ],
  "advance_payments": [ { "from": "2023-01-01", "amount_eur": 90 } ],
  "bonuses":          [ { "credit_date": "2024-01-15",
                          "amount_eur": 60, "type": "wechselbonus",
                          "label": "New-customer bonus" } ],
  "special_payments": [ { "id": "sp_…", "date": "2024-03-15",
                          "kind": "rueckzahlung_mit",
                          "amount_eur": 142.5, "note": "Statement 2023",
                          "new_advance_eur": 95,
                          "advance_from": "2024-04-01" } ],
  "is_shadow": false, "shadow_label": null,
  "notice_period_months": 1, "notice_period_days": null,
  "notice_mode": null, "auto_renews": null,
  "min_term_end": null, "price_guarantee_until": null,
  "signup_bonus_eur": null
}
```

`is_shadow: true` = a hypothetical tariff for the comparison; affects **neither**
the balance **nor** the forecast.

**Cancellation (v2.3.0, extended in v2.9.0)** — all fields optional, `null` = not
maintained:

| Field | Meaning |
|---|---|
| `notice_period_months` | notice period in months; `0` = any time |
| `notice_period_days` | *(v2.9.0)* notice period in days (0–730), takes precedence over the months; the interface stores weeks as days |
| `notice_mode` | *(v2.9.0)* `term_end` (at the end of the term), `month_end` (any time, at month end), `any_day` (any time, to any day); `null` = at the end of the term if there is an end, otherwise at month end |
| `auto_renews` | *(v2.9.0)* `false` = cancelled, the contract really ends; otherwise it runs on without a successor at its last prices |
| `min_term_end` | end of the minimum term (open-ended contracts) |
| `price_guarantee_until` | end of the price guarantee |
| `signup_bonus_eur` | sign-up bonus as an amount (offers in the tariff comparison) |

The API rejects invalid values with 400 (`errors.contract.noticeDaysOutOfRange`,
`errors.contract.noticeModeInvalid`). If `min_term_end` ends more than 24
months after `start`, the API since v3.1.0 also answers on saving with
`warnings: ["term_over_24_months"]` (§ 309 no. 9 BGB); the note is not stored.

**District heating (v3.1.0, CALC-31)** — `fernwaerme` only, all optional:

```jsonc
{
  "capacity_kw": 10,                                          // connected load (kW)
  "capacity_prices": [ { "from": "2025-01-01", "eur_per_kw_year": 60 } ],   // capacity charge
  "metering_prices": [ { "from": "2025-01-01", "eur_per_year": 120 } ],     // metering charge
  "co2_g_per_kwh": 180,                                       // emission factor of the heat network
  "primary_energy_factor": 0.6                                // for information only
}
```

Fixed costs per month = base price + `capacity_kw` × capacity charge / 12 +
metering charge / 12 (in the example, without a base price, 60 €). A capacity
charge needs `capacity_kw` (`errors.contract.capacityMissing`); invalid values →
`errors.contract.valueInvalid`. `co2_g_per_kwh` replaces `co2_fernwaerme` for
the months of the contract ([District heating](../verstehen/04-fernwaerme.md)).
No schema step: missing fields mean “not maintained”.

**Group contract, grid fee, price model, credit notes (v3.1.0)** — all
optional, missing = as before:

```jsonc
{
  "meter_id": null,                                   // for a group contract
  "meter_group_id": "g_strom_ab12cd34",               // gas, electricity, district heating
  "working_prices_by_meter": {                        // unit price per member (peak/off-peak)
    "m_strom_ht": [ { "from": "2026-01-01", "ct_per_kwh": 30.0 } ],
    "m_strom_nt": [ { "from": "2026-01-01", "ct_per_kwh": 22.0 } ]
  },
  "grid_reduction": [ { "from": "2026-01-01", "eur_per_year": 120, "module": 1 } ],   // electricity only, § 14a EnWG
  "price_model": "dynamic",                           // missing = fixed; "monthly"; "dynamic" only electricity shadow contract
  "dynamic": { "markup_ct_per_kwh": 15, "base_eur_month": 10, "vat_pct": 19, "weighting": "flat" },
  "revenue_statements": [                             // feed-in only
    { "from": "2026-06-01", "to": "2026-06-30", "amount_eur": 23.40, "kwh": 290.5 }
  ]
}
```

| Field | Meaning |
|---|---|
| `meter_group_id`, `working_prices_by_meter` | contract for a meter group. Each member calculates at its unit price (if missing, `working_prices`); standing charge, advance payments and bonuses are carried by the first member only. No member may have a real contract of its own in the same period (`errors.contract.groupMemberOverlap`) — [API](api.md#group-contract-v310) |
| `grid_reduction` | reduced grid fee under § 14a EnWG, module 1, in € per year; day-exact as a deduction from the fixed costs |
| `price_model`, `dynamic` | price model: fixed (missing), `monthly` (monthly prices, e.g. from the import) or `dynamic` (dynamic tariff check, only as an electricity shadow contract; mark-up 0–100 ct/kWh, standing charge 0–1000 €/month, VAT 0–30 %, default 19) |
| `revenue_statements` | credit notes from the direct marketer, `to` inclusive; replace kWh × feed-in tariff for their period, day-exact |

No schema step: the fields travel with the contract in the backup.

Water additionally uses a three-component model (drinking/waste/rainwater), see
[Water](../verstehen/03-wasser.md).

### Wholesale electricity prices (`market_prices.json`) *(v3.1.0)*

```json
{ "source": "smard", "area": "DE-LU", "unit": "ct/kWh",
  "months": { "2025-01": { "avg_ct": 11.414 } },
  "imported_at": "2026-02-03T19:12:00+01:00" }
```

Wholesale electricity prices (day-ahead, Germany/Luxembourg) as monthly
averages in ct/kWh, net — filled from a file (`source: "csv"`) or on request
from SMARD (`"smard"`); an import replaces only the months it contains. Basis
of the dynamic tariff check ([API](api.md#wholesale-electricity-prices-and-dynamic-tariff-check-v310)).
Part of the backup; schema 1.7.0 creates the pot empty.

**`special_payments` (F1003, from v1.5.0)** — only for gas/electricity/district
heating (single source of truth: `Utilities::hasAdvancePaymentContracts()`).
`kind` ∈ {`rueckzahlung_mit`, `rueckzahlung_ohne`, `nachzahlung_mit`,
`nachzahlung_ohne`, `abschlagszahlung`}. `amount_eur` is always positive; the sign
in the balance follows from `kind` (a refund raises the balance, a
back-/advance-payment lowers it). Only the `*_mit` types carry `new_advance_eur` +
`advance_from`; these points are mixed into the effective advance plan. Additive &
backward-compatible — if the field is missing, it becomes `[]` on normalisation (no
migration step).

### Charging sessions (`ev_sessions.json`) *(v3.2.0)*

Wall box charging sessions from evcc, one list across all meters — filled from
the CSV export or the fetch in the home network
([API](api.md#charging-sessions-from-evcc-v320)). The file is created by the
first import; no schema step.

```json
{ "id": "evs_1a2b3c4d5e6f", "meter_id": "m_wallbox",
  "created": "2026-09-30T17:02:11+02:00", "finished": "2026-09-30T21:40:03+02:00",
  "date": "2026-09-30", "loadpoint": "Garage", "vehicle": "Small car",
  "charged_kwh": 18.402, "solar_pct": 0, "price_eur": 5.15, "price_per_kwh": 0.28,
  "meter_start": 4120.551, "meter_stop": 4138.953, "source": "csv" }
```

| Field | Meaning |
|---|---|
| `id` | `evs_` and 12 hex digits from start and charging point — the same session gives the same identifier, a second import replaces it |
| `meter_id` | electricity meter the session was taken over to (wall box) |
| `created`, `finished` | start and end, ISO 8601 with time zone; times without a zone from evcc are taken in the installation’s time zone |
| `date` | day of the end (`YYYY-MM-DD`) — year and month count by it |
| `loadpoint`, `vehicle` | charging point and vehicle as in evcc, at most 60 characters each, may be empty |
| `charged_kwh` | charged energy in kWh (3 decimals) |
| `solar_pct` | solar share in % (0–100), if evcc reports it |
| `price_eur`, `price_per_kwh` | price of the session and per kWh according to evcc, if reported — figures from evcc, not a calculation of the Energietracker |
| `meter_start`, `meter_stop` | wall box meter reading at start and end in kWh, if the wall box measures it |
| `source` | `csv` (CSV export) or `evcc` (fetch) |

Fields without a value are left out. The meter readings an import derives are
not stored here but as ordinary readings in `strom/readings.json` (note
`evcc`). Part of the backup as the pot `ev_sessions`; the import checks `id`,
`meter_id`, `date` (calendar date) and `charged_kwh`.

### People in `auth.json` *(v3.2.0)*

With sign-in switched on, a household can have several people
([API](api.md#people-in-the-household-v320)). They are stored under `users` in
`data/auth.json` — like password and keys **not in the backup**: a backup
restored on another installation brings no access with it. No schema step.

```jsonc
"users": [
  { "id": "u_admin", "name": "admin", "role": "admin", "source": "password",
    "prefs": {}, "created_at": "…" },                       // first admin: password = password_hash above
  { "id": "u_3f9a1c2e", "name": "Alex", "role": "member", "source": "password",
    "password_hash": "$2y$10$…", "prefs": { "ui_level": "beginner" }, "created_at": "…" },
  { "id": "u_8b0d4f17", "name": "kim", "role": "admin", "source": "proxy",
    "prefs": {}, "created_at": "…" }
]
```

| Field | Meaning |
|---|---|
| `id` | `u_` and eight hex digits; `u_admin` is the first admin from before v3.2 |
| `name` | 1–40 characters, unique regardless of upper and lower case; with `proxy` the reported name |
| `role` | `admin` or `member`; at least one person stays admin |
| `source` | `password` (sign-in in the app) or `proxy` (upstream service, created on the first visit) |
| `password_hash` | only with `password`: hash from `password_hash()` (bcrypt), **never the password itself**. `u_admin` has none of its own and uses the installation password (`password_hash` at the top level or `ET_ADMIN_PASSWORD_HASH`) |
| `prefs` | own settings: `ui_level`, `language`. Without a level the installation’s applies; without a language that of the device or the installation |
| `created_at` | created (ISO 8601) |
| `session_epoch` | counts every new password of this person; missing until the first change. A session cookie only counts with the current epoch — a new password signs the person out on every device |

As long as nobody adds a person, `users` is missing; `u_admin` then only exists
in the API responses, as long as a password is set. The lock after failed
attempts (`login_failures`) applies to all people together. Session cookies
carry the person’s identifier (`<expiry>.<id>.<hmac>`); the v3.1 form without
an identifier stands for `u_admin` until that password is set anew for the
first time. A deleted person no longer has a valid session.

---

## 3. Settings (`settings.json`)

Only deviations from the default are stored. Every key with its place in the
interface and its effect is listed in the [settings reference](einstellungen.md);
here a selection with background:

| Key | Default | Meaning |
|---|---|---|
| `gas_conversion_factors` | `[{from:null, kwh_per_m3:11.5}]` | **Dated list** (F1012): per entry `from` (ISO date or `null` for "before"), `zustandszahl` (volume correction factor), `brennwert` (calorific value), `kwh_per_m3` (derived = z × Hs, 5 decimals). The latest entry with `from ≤ day` takes effect. |
| `heizoel_kwh_per_l` | 10.0 | calorific value of heating oil EL |
| `pellets_kwh_per_kg` | 4.8 | calorific value of wood pellets (DIN EN ISO 17225-2 A1) |
| `hdd_base_temp` | 15.0 | heating limit temperature (°C) for HDD |
| `co2_gas` | 182 | g CO₂ per kWh (gross calorific value): BAFA 201 on net calorific value × 0.906 — *v2.10.0, previously 201* |
| `co2_strom` | 380 | g/kWh for years before the first entry in `co2_strom_years` |
| `co2_strom_years` | UBA 2015–2025 | *(v2.10.0)* year → g/kWh, German Environment Agency (emission factor of the electricity mix); after the last year its value applies |
| `co2_heizoel / _pellets / _fernwaerme` | 266 / 36 / 280 | g/kWh, BAFA (pellets CO₂ eq. incl. the upstream chain; district heating flat rate) — *v2.10.0, previously 266 / 26 / 180* |
| `co2_wasser` | 350 | g/m³ — rough guide value without a documented source |
| `blend_max` | 0.80 | upper bound of the regression weight in the forecast |
| `confidence_band_sigma` | 1.28 | width of the forecast band in σ (1.28 ≈ 80 % of years); without effect up to v2.7 |
| `anomaly_threshold` | 2.0 | threshold of the anomaly detection (robust z-value) |
| `min_days_period`, `min_hdd_regression` | 20, 5 | minimum days and minimum degree days, respectively, from which a month feeds the models |
| `forecast_months` | 12 | forecast horizon |
| `forecast_model` | linear | default regression model |
| `segmented_split_mode` | auto | breakpoint of the segmented regression |
| `wohnflaeche_m2` | 100 | for the efficiency class |
| `gebaeudetyp`, `beheizter_keller`, `warmwasser_dezentral` | efh, false, false | *(effective since v2.10.0)* usable floor area 1.2 or 1.35 × living area, hot-water surcharge 20 kWh/m²·a for the certificate-style figure |
| `wasser_personen_referenz` | 122 | L per person and day, BDEW 2024 (*v2.10.0, previously 127*) |
| `dashboard_months` | 12 | *(effective since v2.9.0)* months in the dashboard's consumption history (3–36) |
| `alert_days_since_reading` | 45 | *(effective since v2.9.0)* "reading overdue": warning from ⅔, alert from the value itself |
| `contract_remind_days_1/2/3` | 90 / 30 / 1 | reminder levels; since v2.9.0 days before the cancellation deadline, without a notice period before the contract end |
| `min_temp_days_forecast`, `baujahr` | 20, null | **deprecated (v2.9.0)**, without effect and no longer in the interface; dropped with v3.0.0 |
| `efficiency_class_thresholds` | A+…G | band limits kWh/m²·a |
| `billing_cycle_anchor_*` | 01-01 | billing date — stored `MM-DD`, **displayed `DD-MM`** (v1.4.2); since v2.9.0 it must be a calendar day, otherwise 400. Since v2.13.0 also `…_pv_einspeisung` (the remuneration is calculated up to the billing date) |
| `billing_cycle_anchor_heizoel`, `…_pellets` | 01-01 | **deprecated (v2.13.0)**, without effect (no advances, no balance) and no longer in the interface; removed in v3.0.0 |
| `delivery_baseload_share` | 0.15 | weather-independent base-load share for delivery utilities |
| `tank_warn_pct` | 15 | warning threshold for the tank level in %; alert from half of it (since v2.13.0 also in the interface) |
| `active_utilities` | gas, strom, wasser | which utilities menu and evaluations show; deselected ones keep their data. Heat (`waerme`, v3.1.0) is not active by default |
| `wohnverhaeltnis` | eigentum | *(v3.1.0)* `eigentum` (own home) or `miete` (rented); with `miete` the “Tenancy” page appears and the agenda knows the deadlines of the service charge statement |
| `waerme_energietraeger` | null | *(v3.1.0)* what produces the heat (`gas`, `heizoel`, `pellets`, `fernwaerme`, `strom`) — its CO₂ value comes with that factor, without an answer 0 |
| `warmwasser_temp_c` | 60 | *(v3.1.0)* hot-water temperature (30–90 °C) for the heat of the hot-water meters under HeizkostenV § 9 (2) |
| `warmwasser_energietraeger` | null | *(v3.1.0)* what heats the hot water (as above, plus `waerme`) — for information only |
| `location_name`, `latitude`, `longitude` | Leipzig | for Open-Meteo (transmitted rounded to two decimal places) |
| `weather_auto_fill` | true | *(effective since v2.8.0)* sync the temperatures once a day when the app is opened |
| `language` | de | default language of the installation (devices without a choice of their own, PDF, CSV, Home Assistant); a device can differ via `X-ET-Language` (v3.1.0) |
| `country` | DE | *(v2.7.0)* country: formats (together with the language), efficiency scale — [country profiles](../verstehen/14-laenderprofile.md) |
| `currency` | EUR | *(v2.7.0)* `EUR`, `CHF`, `GBP` — symbol and minor unit; amounts are not converted, `*_eur`/`ct_*` mean major/minor unit |
| `timezone` | Europe/Berlin | *(v2.7.0)* IANA time zone: "today", due dates, day boundaries of the weather data |
| `gas_cv_unit` | kwh | *(v2.7.0)* input unit of the calorific value (`kwh`, `mj`, `gj`); storage is always kWh/m³ |
| `frame_ancestors` | *(empty)* | *(v2.6.0)* origins allowed to embed the app (CSP `frame-ancestors`), e.g. `http://homeassistant.local:8123`; since v3.2.0 admins only |
| `attachments_max_mb` | 500 | *(v3.1.0)* storage for all receipts together in MB (10–100000) |
| `ocr_endpoint`, `ocr_api`, `ocr_model`, `ocr_timeout_s` | *(empty)*, ollama, *(empty)*, 30 | *(v3.1.0)* text recognition in the home network; empty = off, no connection — [settings](einstellungen.md), [guide](../anleitungen/texterkennung.md). Since v3.2.0 only admins change `ocr_endpoint` |
| `evcc_endpoint` | *(empty)* | *(v3.2.0)* address of evcc in the home network for fetching the charging sessions; empty = off, no connection, local addresses only; admins only — [Charging sessions from evcc](../anleitungen/evcc.md) |
| `ui_level` | expert | *(v3.2.0)* experience level of the installation: `beginner`, `advanced`, `expert` — what the interface shows, not what the app calculates. People have their own in `auth.json` (`prefs.ui_level`); existing installations after the update: Expert |
| `setup_pending` | false | *(v3.2.0)* `true` only after the first start of a new installation, until the setup assistant is finished or skipped |
| `setup_persona` | null | *(v3.2.0)* persona chosen last in the assistant or example household loaded (`mieterin`, `etw-fernwaerme`, `eigenheim-klassisch`, `eigenheim-modern`, `showcase`) |
| `co2_price_eur_t_years` | `{}` | *(v3.1.0)* your own CO₂ prices per year in €/t (year → value, 0–1000); empty = country profile — [CO₂ price](../verstehen/16-co2-preis.md) |
| `co2_price_scenario_eur_t`, `co2_price_scenario_from` | null, 2028 | *(v3.1.0)* CO₂ price scenario of the forecast: price in €/t (empty = off) and first year |
| `co2_pv_avoided` | null | *(v3.1.0)* your own PV avoidance factor in g/kWh (0–2000); empty = electricity mix |
| `pv_assumed_self_consumption_pct` | null | *(v3.1.0)* assumed self-consumption of a plug-in solar device without a feed-in meter in % (0–100); empty = no assumption |
| `reference_strom_kwh`, `reference_heat_kwh_m2`, `reference_source`, `warmwasser_elektrisch` | null, null, empty, false | *(v3.1.0)* your own reference values for the benchmark: household electricity in kWh/yr (0–100000), heating in kWh/m²·yr (0–1000), source (up to 120 characters), hot water by electricity |

The complete list is in `SettingsService::DEFAULTS`. `PATCH /api/settings`
does not store unknown keys and names them in `ignored_keys` since v2.6.0.

> The `billing_cycle_anchor_*` values are stored **canonically as `MM-DD`** (so the
> backend can build a valid `YYYY-MM-DD`), but displayed and entered in the UI in
> the German format **`DD-MM`** since v1.4.2. The conversion happens exclusively at
> the UI boundary.

---

## 4. Schema migration

`Storage/Migrator` runs on the first app start and is **idempotent**:

- recognises the `schema_version` in `meta.json`,
- recognises a completely empty directory (`isPristine()`, since v1.9.1) and then
  creates fresh default meters (`initFresh()`) instead of migrating blindly,
- adds missing directories/files (new utilities, `reminders.json`,
  `meter_groups.json`) and new meter fields additively (`parent_meter_id`/
  `meter_group_id` in 1.2.0, `external_id` in 1.3.0, `baseline_events` in 1.4.0)
  and in 1.5.0 turns the scalar `gas_conversion_factor` into the list
  `gas_conversion_factors`,
- in 1.6.0 pins the previous value for the corrected defaults (`co2_gas`,
  `co2_strom_years`, `co2_pellets`, `co2_fernwaerme`,
  `wasser_personen_referenz`) where the installation never saved it — only for
  data older than 1.6.0,
- in 1.7.0 (v3.1.0) creates the pots `attachments.json`, `tenancies.json`,
  `tenancy_statements.json` and `market_prices.json` as well as missing basic pots per utility
  (`meters`, `contracts`, `meter_groups`, `readings` or `deliveries`),
  `periods.json` and `bills.json` empty — so an existing installation also gets the folder of
  the new utility `waerme/`, without default meters. No existing record is
  changed; a first start creates the same pots,
- raises the version step by step to the current state (**1.7.0**).

Each step has its own `needsVXXXUpgrade()` + `upgradeToVXXX()` pair and is
idempotent in itself (a repeated run is a no-op).

The bundled demo data carries `schema_version: 1.1.0` and is migrated additively to
the current state (1.7.0) on first start — adding `meter_groups.json` per utility
(1.2.0) and the meter fields `external_id` (1.3.0) and `baseline_events` (1.4.0)
without touching existing values.
The migration path (1.0.0 → current schema) is additionally checked in the CI via a
separate migration smoke test. Before every migration the migrator creates a
snapshot `pre-migration-…` (since v2.5.3).

**Example households *(v3.2.0)*.** Next to this demo household (the
“showcase” with every utility) there are four example households under
`demo-data/personas/<persona>.json`: `mieterin`, `etw-fernwaerme`,
`eigenheim-klassisch`, `eigenheim-modern`. They use backup format 3.0 with
schema 1.7.0 and contain every pot of every utility, empty ones included — so
an example household replaces the whole household. `tools/build-personas.mjs`
generates them from a daily model with a fixed seed: every run writes the same
files, `--check` only compares. Names are made up, market location IDs
synthetic. When loaded, `DemoDataAligner` carries them forward to today like
the showcase. The Docker image contains them
([API](api.md#example-households-v320)).

**Downgrade protection (v2.6.0).** A downgrade is not supported — but it is now
detected: if the `schema_version` of the data is **newer** than the app, it
writes nothing, all routes except `/api/health` answer `503`
(`errors.storage.dataTooNew`), and `/api/health` reports `error`. Up to v2.5.3 an
older version silently stamped the newer data back to its own schema.

---

[← API reference](api.md) ·
[Tests →](../entwicklung/tests.md)
