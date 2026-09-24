# Data model

**English** · [Deutsch](../../technical/04-data-model.md)

[← API reference](03-api-reference.md) · [Compendium index](../README.md)

All data is stored as flat JSON files under `data/`. No database. Writes are
serialised by `LOCK_EX`. Schema level: **1.5.0** (in `data/meta.json` and in every
backup).

> **Schema history (short form):** 1.0.0 utility-oriented layout · 1.0.3 water
> three-component contracts · 1.1.0 district heating/heating oil/pellets +
> `reminders.json` · **1.2.0** meter topology (`parent_meter_id`, `meter_group_id`,
> `meter_groups.json` per utility — F1006) · **1.3.0** meter alias `external_id`
> for the Home Assistant integration (F1009) · **1.4.0** analysis baseline
> cut-offs `baseline_events` on the meter (F1011) · **1.5.0** dated gas
> conversion factors `gas_conversion_factors` in `settings.json` instead of the
> scalar `gas_conversion_factor` (F1012).

---

## 1. Directory and file layout

```text
data/
├── meta.json                 # { schema_version, migrated_at, log[] }
├── settings.json             # settings (defaults: SettingsService::DEFAULTS)
├── auth.json                 # sign-in, HA token, API keys — hashes only, never plaintext
├── temperatures.json         # { "YYYY-MM-DD": { avg, min, max }, … }
├── reminders.json            # appointments/maintenance
├── recommendations_dismissed.json
├── gas/        { meters.json, readings.json, contracts.json, meter_groups.json }
├── strom/      { meters.json, readings.json, contracts.json, meter_groups.json }
├── wasser/     { meters.json, readings.json, contracts.json, meter_groups.json }
├── fernwaerme/ { meters.json, readings.json, contracts.json, meter_groups.json }
├── heizoel/    { meters.json, deliveries.json, contracts.json, meter_groups.json }
├── pellets/    { meters.json, deliveries.json, contracts.json, meter_groups.json }
├── pv_einspeisung/ { meters.json, readings.json, contracts.json, meter_groups.json }
├── pv_erzeugung/   { meters.json, readings.json, contracts.json, meter_groups.json }
├── logs/       # JSON Lines log (N1010)
├── .write.lock # write lock (v2.5.3)
└── backups/    # snapshots: backup_… (own), pre-restore-/pre-migration-/pre-demo-/pre-v09-… (automatic)
```

**Snapshots (v2.6.0):** name `<prefix>YYYY-MM-DD_HHMMSS[-n].json`; the prefix
determines the occasion (`reason` in `GET /api/backup/snapshots`). Retention: of
your own the last ten, automatic ones 30 days, at least the three newest per
occasion. A snapshot is streamed into a temporary file and only renamed at the
end.

Cumulative utilities (gas, electricity, water, district heating, PV) have
`readings.json`; delivery-based utilities (heating oil, pellets) have
`deliveries.json` instead. `contracts.json` exists for all of them, but is
typically empty for heating oil/pellets — there the **tank invoice itself** is the
cost basis (see [Heating oil](../functional/05-heizoel.md)). `meter_groups.json`
(since 1.2.0) holds the group master data per utility; the group *membership*, by
contrast, sits on the meter (`meter_group_id`).

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
>   "session_secret": "…",              // HMAC key of the session cookies; new with every new password
>   "login_failures": { "count": 1, "first_at": 1758700000, "locked_until": 0 },
>   "api_keys": [ { "id": "k_…", "name": "backup script", "scope": "read",
>                   "hash": "…sha256…", "created_at": "…", "last_used_at": null } ]
> }
> ```
>
> Details: [Security](08-security.md), [API reference → sign-in](03-api-reference.md).

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

  // Analysis baseline cut-offs (F1011, since schema 1.4.0) — default []:
  "baseline_events": [
    { "date": "2021-09-01", "label": "Loft insulation" }
  ],

  "devices": [ Device, … ],

  // only for delivery-based utilities (heating oil/pellets):
  "capacity": 3000.0,
  "capacity_unit": "L",
  "initial_stock": 2400.0
}
```

**Meter topology (F1006).** A meter can be a **submeter** of another
(`parent_meter_id`, series connection — its consumption is subtracted from the
parent meter) and/or a **member of a group** (`meter_group_id`, combines several
meters for the dashboard). Rules: at most one submeter level (no chains/cycles); a
parent meter with submeters cannot be deleted without removing the assignment. See
[meter topology](../functional/13-meter-topologie.md).

**`external_id` (F1009).** A freely assignable, per-utility-unique alias
(`[A-Za-z0-9_.-]{1,64}`) for the Home Assistant integration. `POST /api/ingest`
accepts it in place of the internal ID. Default `null` = no alias.

### Meter group (`meter_groups.json`, F1006)

```json
{ "id": "g_strom_ab12cd34", "name": "Off-peak + peak electricity", "created_at": "2026-06-01" }
```

Pure master data (ID + name). Which meters belong to it is **not** stored here, but
as `meter_group_id` on the respective meter (single source of truth).

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

The consumption calculation also skips **sandwiched outliers** of the same
device and reports them as `warnings` (see
[Meter readings → plausibility](../functional/11-zaehlerstaende.md)).

### Delivery (a fuel delivery — heating oil/pellets)

```json
{
  "id": "del_heizoel_a1", "meter_id": "m_heizoel_tank",
  "date": "2023-09-12", "quantity": 1150.0,
  "unit_price_cents": 104.5, "total_eur": 1201.75,
  "supplier": "Oil Müller GmbH", "note": "Autumn refill",
  "is_planned": false
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
  "is_shadow": false, "shadow_label": null
}
```

`is_shadow: true` = a hypothetical tariff for the comparison; affects **neither**
the balance **nor** the forecast. Water additionally uses a three-component model
(drinking/waste/rainwater), see [Water](../functional/03-wasser.md).

**`special_payments` (F1003, from v1.5.0)** — only for gas/electricity/district
heating (single source of truth: `Utilities::hasAdvancePaymentContracts()`).
`kind` ∈ {`rueckzahlung_mit`, `rueckzahlung_ohne`, `nachzahlung_mit`,
`nachzahlung_ohne`, `abschlagszahlung`}. `amount_eur` is always positive; the sign
in the balance follows from `kind` (a refund raises the balance, a
back-/advance-payment lowers it). Only the `*_mit` types carry `new_advance_eur` +
`advance_from`; these points are mixed into the effective advance plan. Additive &
backward-compatible — if the field is missing, it becomes `[]` on normalisation (no
migration step).

---

## 3. Settings (`settings.json`)

Groups (a selection of the default values):

| Key | Default | Meaning |
|---|---|---|
| `gas_conversion_factors` | `[{from:null, kwh_per_m3:11.5}]` | **Dated list** (F1012): per entry `from` (ISO date or `null` for "before"), `zustandszahl` (volume correction factor), `brennwert` (calorific value), `kwh_per_m3` (derived = z × Hs, 5 decimals). The latest entry with `from ≤ day` takes effect. |
| `heizoel_kwh_per_l` | 10.0 | calorific value of heating oil EL |
| `pellets_kwh_per_kg` | 4.8 | calorific value of wood pellets (DIN EN ISO 17225-2 A1) |
| `hdd_base_temp` | 15.0 | heating limit temperature (°C) for HDD |
| `co2_gas / _strom / _wasser` | 201 / 380 / 350 | g CO₂ per kWh or m³ — *[Unverified]* adjustable |
| `co2_heizoel / _pellets / _fernwaerme` | 266 / … | ditto |
| `blend_max` | 0.80 | upper bound of the regression weight in the forecast |
| `forecast_months` | 12 | forecast horizon |
| `forecast_model` | linear | default regression model |
| `segmented_split_mode` | auto | breakpoint of the segmented regression |
| `wohnflaeche_m2` | 100 | for the efficiency class |
| `efficiency_class_thresholds` | A+…G | band limits kWh/m²·a |
| `billing_cycle_anchor_*` | 01-01 | billing date — stored `MM-DD`, **displayed `DD-MM`** (v1.4.2) |
| `delivery_baseload_share` | 0.15 | weather-independent base-load share for delivery utilities |
| `tank_warn_pct` | — | warning threshold for the tank level |
| `active_utilities` | all | which utilities are visible in the sidebar/dashboard |
| `location_name`, `latitude`, `longitude` | Leipzig | for Open-Meteo |
| `language` | de | language of the interface and of API messages |
| `country` | DE | *(v2.7.0)* country: formats (together with the language), efficiency scale — [country profiles](../functional/14-laenderprofile.md) |
| `currency` | EUR | *(v2.7.0)* `EUR`, `CHF`, `GBP` — symbol and minor unit; amounts are not converted, `*_eur`/`ct_*` mean major/minor unit |
| `timezone` | Europe/Berlin | *(v2.7.0)* IANA time zone: "today", due dates, day boundaries of the weather data |
| `gas_cv_unit` | kwh | *(v2.7.0)* input unit of the calorific value (`kwh`, `mj`, `gj`); storage is always kWh/m³ |
| `frame_ancestors` | *(empty)* | *(v2.6.0)* origins allowed to embed the app (CSP `frame-ancestors`), e.g. `http://homeassistant.local:8123` |

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
- raises the version step by step to the current state (**1.5.0**).

Each step has its own `needsVXXXUpgrade()` + `upgradeToVXXX()` pair and is
idempotent in itself (a repeated run is a no-op).

The bundled demo data carries `schema_version: 1.1.0` and is migrated additively to
the current state (1.5.0) on first start — adding `meter_groups.json` per utility
(1.2.0) and the meter fields `external_id` (1.3.0) and `baseline_events` (1.4.0)
without touching existing values.
The migration path (1.0.0 → current schema) is additionally checked in the CI via a
separate migration smoke test. Before every migration the migrator creates a
snapshot `pre-migration-…` (since v2.5.3).

**Downgrade protection (v2.6.0).** A downgrade is not supported — but it is now
detected: if the `schema_version` of the data is **newer** than the app, it
writes nothing, all routes except `/api/health` answer `503`
(`errors.storage.dataTooNew`), and `/api/health` reports `error`. Up to v2.5.3 an
older version silently stamped the newer data back to its own schema.

---

[← API reference](03-api-reference.md) ·
[Tests →](05-testing.md)
