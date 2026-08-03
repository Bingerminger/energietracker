<p align="center"><img src="public/img/icon-light-180.png" alt="Energietracker" width="96"></p>
<p align="center"><b>English</b> · <a href="README.de.md">Deutsch</a></p>

# Energietracker

[![Version](https://img.shields.io/badge/version-2.3.4-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.4-777BB4.svg)](#requirements)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Self-hosted web application for recording and analysing your own energy and
water consumption. Single-file PHP backend, vanilla-JS SPA frontend, flat-file
JSON persistence — no database, no server setup, no external runtime
dependencies (other than Chart.js via CDN).

Up to eight utilities in parallel: **gas**, **electricity**, **water**,
**district heating**, **heating oil** and **wood pellets** (heating oil/pellets
are delivery-based rather than meter-based), plus, since v1.7.0, **PV feed-in**
and **PV generation** for photovoltaic owners (combined electricity balance,
self-sufficiency rate). Per utility: multiple meters with fully modelled meter
swaps, an arbitrary contract history with tariff changes, base-price history,
advance payments and bonuses. From this the app computes monthly consumption
(linearly interpolated resp. energetically balanced), heating degree days
against the local climate, five regression models (linear, polynomial, robust,
segmented with a data-driven breakpoint, sigmoid), weather adjustment, an
efficiency class (kWh/m²·a) and a balance per contract — both the current state
and the expected year-end settlement. On top of that: a statistical
recommendation engine, reminder/maintenance management, a tariff comparison with
shadow contracts and a PDF annual report.

> **Status:** v2.3.4 is the current public version (initial release was v1.0.2).
> If you want to migrate from a privately run v0.9.0 backup, see
> [Migration from v0.9.0](docs/MIGRATION-FROM-V090.md) — the v0.9.0 backup
> format is supported by the migrator.

> 📚 **Full documentation:** the separate technical and functional compendium
> (installation, API, data model, each utility with formulas, user scenarios, UI
> reference) lives under **[`docs/en/`](docs/en/README.md)**.

> 🌐 **Languages:** the app interface ships in **German, English, French,
> Italian, Spanish, Portuguese and Dutch**, switchable under *Settings →
> Language*. The documentation is maintained bilingually (English + German); the
> compendium is fully available in English as well — German remains
> canonical and is kept in sync at every release.

---

## Contents

- [Features](#features)
- [Documentation](#documentation)
- [Quick start](#quick-start)
- [Data model](#data-model)
- [Directory layout](#directory-layout)
- [Migration from v0.9.0](#migration-from-v090)
- [Further documentation](#further-documentation)
- [Contributing & licence](#contributing--licence)

---

## Features

### Data entry

- **Meter-reading table** per utility with inline edit, delete, optional
  per-reading notes and an "estimated" flag for corrections.
- **Future readings** to pre-record planned billing dates (ignored in the
  consumption calculation but kept visible).
- **Meter swap** as a first-class data model: a meter bundles one or more
  devices, including serial number, installation date, initial/final counter and
  reason. Consumption is computed correctly across swap boundaries
  (`(old_final − previous_reading) + (current_reading − new_initial)`).
- **Multiple meters per utility** with independent contracts (e.g. main meter +
  garden-water sub-meter).
- **Temperature import** as CSV (format `DD.MM.YYYY"avg"min"max`,
  double-quote-separated) or via Open-Meteo sync using the location coordinates
  in the settings.
- **CSV import of readings** per meter: read a file of
  `date;reading;note;estimated` — existing readings on the same date are
  overwritten and reported in the result.

### Contracts and balance

- **Contract history** per meter: provider, tariff name, start/end, free-text
  note.
- **Date-accurate price history** for working price (ct/consumption unit), base
  price (€/month) and the monthly advance (€). Several effective dates per
  contract are applied to the months as a forward fill.
- **Bonuses** with credit date, amount and label (new-customer, loyalty bonus,
  etc.).
- **Balance calculation** per contract:
  - *Current balance* = costs incurred so far − advances paid so far
  - *Expected year-end balance* = current balance + (remaining months × avg
    monthly cost − monthly advance). Open contracts are projected to the next
    settlement date (configurable per utility, default 1 January).
  - *Verdict*: refund / surcharge / balanced with a ±5 € threshold
- **Contract-end reminder**: contracts whose end falls within a configurable
  window (three levels, default 90 / 30 / 1 days) are shown as a staged hint in
  the correlation view.

### Analysis

- **Heating degree days** (HDD) per month against the configured base
  temperature (default 15 °C, adjustable in the settings).
- **Five regression models** on HDD vs. consumption:
  - *Linear*: classic OLS fit, fast and robust for evenly heated households
  - *Polynomial degree 2*: captures non-linear effects (e.g. summer base load
    from hot water)
  - *Robust (Huber)*: iteratively reweighted OLS, ignores outliers
  - *Segmented*: two separate linear fits above/below a threshold (typically
    HDD = 50), distinguishing the heating season from summer load
  - *Sigmoid*: S-curve for households with pronounced saturation at high HDD
- **Anomalies**: months in which consumption deviates more than 2σ (adjustable)
  from the model's expected value.
- **Forecast** over 12 months as an R²-weighted blend of the regression model
  and the seasonal profile. The cost forecast is contract-based: per month the
  then-valid working and base price from the contract history is used, plus the
  projected advance and running balance.
- **Year-on-year comparison with monthly deltas**: month-by-month difference of
  the two most recent years, absolute and as a percentage.
- **Water saving index** `(litres/person/day) / reference × 100` with
  configurable band limits.

### Delivery-based fuels (v1.3.0)

- **Heating oil & pellets** are recorded via **deliveries** instead of meter
  readings (date, quantity, price/unit or total amount, supplier, note,
  "planned" flag). The monthly consumption is energetically balanced and
  distributed via a base-load share plus HDD weighting.
- **Tank stock curve**: modelled remaining stock (initial stock + deliveries −
  HDD-weighted consumption) with a warning threshold. An estimate, not a tank
  gauge.
- **District heating** as an additional cumulative, HDD-relevant utility.

### Evaluation & insights (v1.3.0)

- **Weather adjustment**: "consumed more, or just colder?" — consumption
  normalised to the long-term calendar-month HDD, plus the regression
  expectation and the deviation in per cent.
- **Efficiency class** A+…H from the heating energy demand in kWh/m²·a,
  configurable band limits, living area/year built/building type maintainable.
- **Recommendation engine**: seven statistical rule families from your own data,
  with severity and individually dismissible.
- **Sigmoid and auto-segmented regression model** in addition to
  linear/polynomial/robust.

### Management (v1.3.0)

- **Reminders & maintenance**: recurring reminders (heating service, chimney
  sweep, meter calibration deadlines …) with a due status.
- **Tariff comparison** with **shadow contracts**: compute hypothetical tariffs
  on your real consumption without affecting the balance/forecast.
- **PDF annual report** as a file download (dependency-free PDF writer, no
  composer/mPDF needed).
- **Toggleable utilities**: hide unused utilities without losing data.

### Meter topology & automation (v1.8.0 / v1.9.0)

- **Sub-meters / series connection (F1006):** a meter can sit behind another
  (e.g. a heat pump behind household electricity). Its consumption is subtracted
  from the parent meter — no double counting in the total.
- **Meter groups (F1006):** several meters (off-peak + peak electricity, several
  wallboxes) can be bundled into a group for the dashboard; a **merge wizard**
  combines existing meters. See
  [Meter topology](docs/en/README.md).
- **Home Assistant integration (F1009):** Home Assistant pushes meter readings
  automatically via `POST /api/ingest` (idempotent, upsert per day). Optional
  API token (opt-in) and freely assignable meter aliases. Full guide with use
  cases: [Home Assistant](docs/en/README.md).

### Operations

- **Backup & restore** via the UI: a full JSON backup in the new format
  (`backup_version: "3.0"`), for restoring or moving.
- **Migration from v0.9.0**: an old backup format (`version: "2.1"`) can be
  imported directly, either replacing or merging with existing data. See
  [Migration from v0.9.0](docs/MIGRATION-FROM-V090.md).
- **CSV export** for the monthly overview, readings and the temperature series —
  semicolon-separated, UTF-8 with BOM, directly usable in Excel/LibreOffice.
  Complements the full JSON backup.
- **Light/dark toggle** on the right in the top bar. Respects
  `prefers-color-scheme` on the first visit, then persists the choice in
  `localStorage`.
- **System diagnostics** under Settings: PHP version, data directory, write
  permissions, schema version, number of meters/readings per utility.
- **CI pipeline** (GitHub Actions): four jobs on every push/PR to `main` — PHP
  syntax lint, the PHPUnit service suite, frontend-API-shape + browser-render
  against a real backend server, and a Docker image smoke test. Version tags
  automatically publish the multi-arch image (amd64 + arm64) to GHCR.

---

## Documentation

The full documentation lives as a **compendium** under
[`docs/en/`](docs/en/README.md) — split into a technical and a functional part
plus a UI reference with **real screenshots of all 12 views**:

- 🚀 **New here?** → [Getting started](docs/en/README.md) (a guided example from
  installation to the first forecast) ·
  [Use cases](docs/en/README.md) (shared flat, smart home, PV, landlord)
- 🔧 **Technical:** installation · architecture · API · data model · tests ·
  release process → see the [compendium index](docs/en/README.md)
- 🏠 **Home Assistant integration** — push meter readings automatically from Home
  Assistant (token, meter alias, REST command, scenarios) → see the
  [compendium index](docs/en/README.md)
- 📚 **Functional:** fundamentals & formulas · each utility (gas … pellets) ·
  flat scenario · house scenario · glossary → see the
  [compendium index](docs/en/README.md)
- 🖥️ **UI reference:** all views → see the [compendium index](docs/en/README.md)

> The English compendium is complete — see
> [`docs/en/README.md`](docs/en/README.md). The German documentation under
> [`docs/`](docs/README.md) remains canonical and is kept in sync at every release.

---

## Quick start

### Requirements

- **PHP ≥ 8.4** (the CLI plus any web server: Apache, nginx, Caddy, or the
  built-in PHP server for local work)
- A web browser with ES modules (anything from 2020 onwards)

### Installation

```bash
git clone https://github.com/Bingerminger/energietracker.git
cd energietracker

# Local test server (document root = project root)
php -S 127.0.0.1:8080
```

Open <http://127.0.0.1:8080> in the browser → the dashboard appears with an
empty state.

On first start the app automatically initialises `data/meta.json`,
`data/settings.json`, an empty `temperatures.json` and the utility subfolders
(`data/gas/`, `data/strom/`, `data/wasser/`) including `meters.json`,
`readings.json`, `contracts.json`. The `data/` directory must be writable by the
web server.

### 🐳 Docker (since v1.7.3)

Reproducible operation as a single container (nginx + php-fpm). Data lives in
the mounted volume `./data`.

```bash
docker compose up -d        # → http://localhost:8080
```

Or without Compose, directly with the published image:

```bash
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:2.3.4
```

> Without `--name energietracker` Docker assigns a random name (e.g.
> `thirsty_archimedes`). With `--name` the container is always called
> `energietracker` — exactly as when starting via `docker compose`.

Logs (JSON Lines) appear via `docker logs`; configuration via `ET_LOG_LEVEL` /
`ET_LOG_DEST` / `ET_DATA_DIR`. Details:
[INSTALL.md → Production: Docker](INSTALL.md#production-docker-since-v173).

### First-time setup

1. Check **Settings → System constants**: gas conversion factor (default 11.5
   kWh/m³), HDD base temperature (default 15 °C), CO₂ factors, your own location
   (lat/lon, default Leipzig).
2. Open **Consumption → Gas/Electricity/Water → ⚙️ Meters** and create the first
   meter (a default device is created automatically). For existing meters, enter
   a serial number + approximate installation date.
3. **Consumption → Gas → 📑 Contracts** → create the first contract with
   provider/tariff/start/end, and enter the working price, base price and monthly
   advance.
4. **First reading** via the `+ Reading` button. As soon as at least two readings
   exist, the monthly consumption calculation begins.
5. **Temperatures → sync Open-Meteo** for local climate data (or upload a CSV
   file).

### Demo data

> 💡 **Fastest way (no file system needed):** the demo data is also available as
> a JSON backup under
> [`demo-data/energietracker-demo-backup.json`](demo-data/energietracker-demo-backup.json).
> In an empty Energietracker, upload it via *Settings → Backup & restore →
> Import backup* (since v1.7.4 also via the "Load demo data" button). A snapshot
> is created automatically beforehand.

The repository ships demo data under `demo-data/` (Leipzig detached-house
scenario 2023–2026, three utilities, several contracts with tariff changes). To
try it out, copy it into `data/`:

```bash
rm -rf data/gas data/strom data/wasser data/meta.json data/settings.json data/temperatures.json
cp -r demo-data/gas demo-data/strom demo-data/wasser data/
cp demo-data/meta.json demo-data/settings.json demo-data/temperatures.json data/
```

> Before copying, back up your own data if needed (Settings → Backup & restore →
> *Download JSON backup*).

---

## Data model

Everything is stored as JSON under `data/`. The schema version (`1.1.0` — since
v1.3.0; v1.0.3 introduced the water contract model, v1.1.0 added the
delivery-based utilities and `reminders.json`) is in `data/meta.json` and in
every exported backup under `backup_version`.

```
data/
├── meta.json                ← {schema_version, created_at, …}
├── settings.json            ← {gas_conversion_factor, hdd_base_temp, …}
├── temperatures.json        ← {"YYYY-MM-DD": {avg, min, max}}
├── gas/
│   ├── meters.json          ← [{id, name, icon, devices: [...], …}]
│   ├── readings.json        ← [{id, meter_id, device_id, date, counter, …}]
│   └── contracts.json       ← [{id, meter_id, provider, working_prices: [...], …}]
├── strom/
│   ├── meters.json
│   ├── readings.json
│   └── contracts.json
├── wasser/
│   ├── meters.json
│   ├── readings.json
│   └── contracts.json
└── backups/                 ← [optional UI snapshots]
```

### Reading

```json
{
  "id": "r_abc12345",
  "meter_id": "m_gas_main",
  "device_id": "d_gas_001",
  "date": "2025-04-15",
  "counter": 23545.0,
  "price_cents": null,
  "note": "After heating service",
  "is_estimated": false,
  "is_future": false
}
```

`price_cents` is optional and (when set) applied as a forward fill to later
readings — as a fallback when no matching contract exists. In practice you leave
`price_cents` empty and maintain contracts instead.

### Meter and device (meter swap)

```json
{
  "id": "m_gas_main",
  "name": "Main meter",
  "icon": "🔥",
  "created_at": "2023-01-01T00:00:00+01:00",
  "active": true,
  "notes": "",
  "devices": [
    {
      "id": "d_gas_001",
      "serial": "GAS-2019-AB7321",
      "installed_on": "2019-04-12",
      "initial_counter": 0.0,
      "removed_on": "2024-08-22",
      "final_counter": 18432.5,
      "reason": "Calibration period expired"
    },
    {
      "id": "d_gas_002",
      "serial": "GAS-2024-CD8945",
      "installed_on": "2024-08-22",
      "initial_counter": 0.0,
      "removed_on": null,
      "final_counter": null,
      "reason": ""
    }
  ]
}
```

The devices list is chronological. The active one (= most recently installed and
not removed) is the one with `removed_on === null`. Consumption between two
readings on different devices is bridged correctly via
`ConsumptionService::consumptionBetween()`.

### Contract

```json
{
  "id": "c_gas_004",
  "meter_id": "m_gas_main",
  "provider": "Vattenfall",
  "tariff_name": "Easy Gas 12 — 2026",
  "start": "2026-01-01",
  "end": "2026-12-31",
  "notes": "Price adjustment at the start of 2026",
  "working_prices":   [{"from": "2026-01-01", "ct_per_kwh": 8.2}],
  "base_prices":      [{"from": "2026-01-01", "eur_per_month": 10.50}],
  "advance_payments": [{"from": "2026-01-01", "amount_eur": 130.00}],
  "bonuses":          [{"credit_date": "2026-06-30", "amount_eur": 75, "type": "neukunde", "label": "New-customer bonus"}]
}
```

For water the field `ct_per_kwh` semantically means *ct/m³* — the unit is derived
from the utility config (`consumption_unit`), not from the field name.

`end: null` corresponds to an open contract. For the balance projection the
effective end is then set to the next settlement date of the respective utility
(settings `billing_cycle_anchor_*`, default 1 January).

### Settings (28 keys)

For the full list of configurable values see
[`docs/technical/04-data-model.md`](docs/technical/04-data-model.md) →
*Settings* (English mirror: [`docs/en/technical/04-data-model.md`](docs/en/technical/04-data-model.md)).

---

## Directory layout

```
energietracker/
├── api.php                  ← 20-line entry point, delegates to src/bootstrap.php
├── index.php                ← SPA shell (sidebar + top bar, loads /public/js/app.js)
├── VERSION                  ← "2.3.4"
├── README.md                ← this file (English)
├── README.de.md             ← German version
├── CHANGELOG.md
├── LICENSE
├── docs/                    ← German compendium (canonical)
│   ├── en/                  ← English mirror (complete)
│   ├── API.md               ← REST endpoint reference with examples
│   ├── ARCHITECTURE.md      ← service map, data-model details, calculations
│   ├── MIGRATION-FROM-V090.md
│   └── screenshots/         ← real PNG screenshots of all views
├── src/                     ← PHP backend
│   ├── bootstrap.php        ← app container, routing
│   ├── Config/
│   │   └── Utilities.php    ← single source of truth for all 6 utilities
│   ├── Http/                ← Router, Request, Response, ErrorHandler
│   ├── Storage/
│   │   ├── JsonStore.php    ← LOCK_EX writes, atomic reads
│   │   └── Migrator.php     ← bootstrap logic for an empty `data/`
│   ├── Services/            ← 24 services (Consumption, DeliveryConsumption, Forecast, Auth, Ingest, …)
│   └── Controllers/         ← 20 controllers, 1 class per file
├── public/
│   ├── css/                 ← tokens.css + app.css + components.css
│   └── js/                  ← vanilla-JS SPA
│       ├── app.js           ← entry
│       ├── api.js           ← fetch wrapper
│       ├── router.js        ← hash router
│       ├── state.js         ← lightweight cache
│       ├── views/           ← dashboard, utility, meters, contracts, …
│       ├── components/      ← chart, modal, toast
│       └── lib/             ← format, i18n
├── public/locales/          ← language catalogs (de, en, fr, it, es, pt, nl)
├── data/                    ← runtime data (gitignored, .gitkeep stubs)
├── demo-data/               ← optional copyable demo dataset
└── scripts/
    └── init_data.py         ← Python helper for bulk import from Excel
```

---

## Migration from v0.9.0

If you want to migrate from a v0.9.0 backup:

1. In v0.9.0, export a full JSON backup (format version `2.1` with the top-level
   keys `gas`, `strom`, `temperatures`, `settings`, `contracts`).
2. Install v1.2.0 fresh (see [Quick start](#quick-start)) or delete the demo
   data.
3. Open **Settings → Backup & restore → 📦 Migration from v0.9.0** and upload the
   JSON file.
4. The migration dialog shows what would be imported (readings per utility,
   contracts, temperatures, settings, warnings, detected meter-swap candidates).
5. Choose **Replace** or **Merge** → import. Before writing, a safety snapshot of
   the current data is created automatically under `data/backups/`.

A complete step-by-step guide including schema mapping and error handling is in
[`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md) (English:
[`docs/en/MIGRATION-FROM-V090.md`](docs/en/MIGRATION-FROM-V090.md)).

---

## Further documentation

- [`docs/en/README.md`](docs/en/README.md) — **compendium index** (technical ·
  functional · UI), with English/German availability
- [`docs/technical/03-api-reference.md`](docs/technical/03-api-reference.md) —
  full API reference (German)
- [`docs/technical/04-data-model.md`](docs/technical/04-data-model.md) — data
  model & schemas (German)
- [`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md) — migration from
  v0.9.0 (German)
- [`CHANGELOG.md`](CHANGELOG.md) — version history

---

## Contributing & licence

Pull requests welcome. Before larger changes to the data model, please open an
issue so that a migration can be planned cleanly.

MIT License — see [`LICENSE`](LICENSE).
