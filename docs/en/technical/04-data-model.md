# Data model

**English** · [Deutsch](../../technical/04-data-model.md)

[← API reference](03-api-reference.md) · [Compendium index](../README.md)

All data is stored as flat JSON files under `data/`. No database. Writes are
serialised by `LOCK_EX`. Schema level: **1.3.0** (in `data/meta.json` and in every
backup).

> **Schema history (short form):** 1.0.0 utility-oriented layout · 1.0.3 water
> three-component contracts · 1.1.0 district heating/heating oil/pellets +
> `reminders.json` · **1.2.0** meter topology (`parent_meter_id`, `meter_group_id`,
> `meter_groups.json` per utility — F1006) · **1.3.0** meter alias `external_id`
> for the Home Assistant integration (F1009).

---

## 1. Directory and file layout

```text
data/
├── meta.json                 # { schema_version, migrated_at, log[] }
├── settings.json             # 40 keys (see below)
├── auth.json                 # API token HASH for HA ingest (F1009) — never plaintext
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
└── backups/    # snapshots
```

Cumulative utilities (gas, electricity, water, district heating, PV) have
`readings.json`; delivery-based utilities (heating oil, pellets) have
`deliveries.json` instead. `contracts.json` exists for all of them, but is
typically empty for heating oil/pellets — there the **tank invoice itself** is the
cost basis (see [Heating oil](../functional/05-heizoel.md)). `meter_groups.json`
(since 1.2.0) holds the group master data per utility; the group *membership*, by
contrast, sits on the meter (`meter_group_id`).

> **`auth.json`** (F1009) contains exclusively the **SHA-256 hash** of the API
> token, never the plaintext, and is **excluded** from the backup. As long as the
> file is missing/empty, the API is in open mode (no token required). Details:
> [API reference → Auth](03-api-reference.md).

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
  "reason": "Calibration swap"
}
```

Consumption across a swap boundary:
`(old_final − previous_reading) + (current_reading − new_initial)`.

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

## 3. Settings (`settings.json`, 40 keys)

Groups (a selection of the default values):

| Key | Default | Meaning |
|---|---|---|
| `gas_conversion_factor` | 11.5 | kWh per m³ gas (calorific value × state number) |
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
  `meter_group_id` in 1.2.0, `external_id` in 1.3.0),
- raises the version step by step to the current state (**1.3.0**).

Each step has its own `needsVXXXUpgrade()` + `upgradeToVXXX()` pair and is
idempotent in itself (a repeated run is a no-op).

The bundled demo data carries `schema_version: 1.1.0` and is migrated additively to
the current state (1.3.0) on first start — adding `meter_groups.json` per utility
(1.2.0) and the meter field `external_id` (1.3.0) without touching existing values.
The migration path (1.0.0 → current schema) is additionally checked in the CI via a
separate migration smoke test. A downgrade is not supported.

---

[← API reference](03-api-reference.md) ·
[Tests →](05-testing.md)
