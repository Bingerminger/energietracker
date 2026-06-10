# Energietracker — Compendium

[English](README.md) · [Deutsch](../README.md)

> Full documentation for Energietracker.
> Split into a **technical** and a **functional** part, plus a **UI reference**
> with real screenshots of all views.
>
> **New here?** → straight to [Getting started](getting-started.md) — a
> continuous example from installation to the first forecast.

Energietracker is a locally run, dependency-free web app for recording and
analysing household energy consumption across up to eight utilities — **gas,
electricity, water, district heating, heating oil, wood pellets, PV feed-in, PV
generation**. No external service, no database: everything is stored as a flat
JSON file on your own machine.

> 🌐 **Translation status.** This compendium is fully mirrored to English. The
> **German documentation under [`docs/`](../README.md) remains canonical** and is
> kept in sync with every release; the English pages follow at each release. If a
> page ever diverges, the German original prevails.

---

## Map

### 🔧 Technical part — *for operation & development*

| Document | Contents |
|---|---|
| [Installation & operation](technical/01-installation.md) | Requirements, setup, web server, update, backup |
| [Architecture](technical/02-architecture.md) | Layered model, services, controllers, data flow |
| [API reference](technical/03-api-reference.md) | All 68 endpoints with examples |
| [Data model](technical/04-data-model.md) | JSON schemas, storage, schema migration |
| [Tests](technical/05-testing.md) | Backend-shape and browser-render harness |
| [Release process](technical/06-release-process.md) | Versioning, CHANGELOG, doc maintenance |
| [Docker operation](technical/07-docker.md) | Container quick start, `docker compose`, updates, data volume, logs |
| [Home Assistant integration (F1009)](HOME-ASSISTANT.md) | Push meter readings automatically from HA: token, meter alias, REST command, scenarios |

### 📚 Functional part — *for understanding & use*

| Document | Contents |
|---|---|
| [Fundamentals & methodology](functional/00-overview.md) | HDD, regression, forecast, weather adjustment — the formulas |
| [Gas](functional/01-gas.md) | Calorific value, m³→kWh, heating signature |
| [Electricity](functional/02-strom.md) | Base load, seasonal profile, no HDD |
| [Water](functional/03-wasser.md) | m³, three-component tariff, saving index |
| [District heating](functional/04-fernwaerme.md) | Cumulative, HDD-relevant, base price |
| [Heating oil](functional/05-heizoel.md) | Delivery-based, tank model, calorific value |
| [Wood pellets](functional/06-pellets.md) | Delivery-based, kg instead of litres |
| [Scenario: flat](functional/07-szenario-wohnung.md) | Best practices for a rented flat |
| [Scenario: detached house](functional/08-szenario-eigenheim.md) | Best practices for a detached house |
| [Glossary & formulas](functional/09-glossar.md) | All terms and formulas, compact |
| [Special payments (F1003)](functional/10-sonderzahlungen.md) | Refund/surcharge, advance payment, balance effect |
| [Meter-reading entry (F1004)](functional/11-zaehlerstaende.md) | Central mobile reading view (gas/electricity/water/district heating) |
| [PV feed-in & generation (F1005)](functional/12-pv.md) | Feed-in meter, generation meter, electricity balance, self-sufficiency rate |
| [Meter topology (F1006)](functional/13-meter-topologie.md) | Sub-meters (series) & meter groups — difference consumption and dashboard bundling |

### 🚀 Getting started & practice — *for new users*

| Document | Contents |
|---|---|
| [Getting started](getting-started.md) | Continuous example: installation → first meter → first reading → first contract → first forecast |
| [Home Assistant integration (F1009)](HOME-ASSISTANT.md) | Push meter readings automatically from HA: token, meter alias, REST command, use cases |
| [Use cases](USE-CASES.md) | Four worked-through cases: shared flat with shared meters, full smart-home build-out, PV household, landlord with several units |

### 🖥️ UI reference

| Document | Contents |
|---|---|
| [All views](ui/01-views.md) | Each of the 12 views explained, with a real screenshot |

---

## Quick entry by role

- **"I'm new and just want to get going."**
  → [Getting started](getting-started.md) (guided example from A to Z)

- **"I just want to install and use it."**
  → [Installation](technical/01-installation.md) →
  [Flat scenario](functional/07-szenario-wohnung.md) *or*
  [House scenario](functional/08-szenario-eigenheim.md)

- **"My specific case is unusual (shared flat, PV, landlord, smart home)."**
  → [Use cases](USE-CASES.md)

- **"I use Home Assistant and want to feed the meters automatically."**
  → [Home Assistant integration](HOME-ASSISTANT.md)

- **"I have main and sub-meters, or want to bundle meters."**
  → [Meter topology](functional/13-meter-topologie.md)

- **"I want to understand how the forecast is computed."**
  → [Fundamentals & methodology](functional/00-overview.md)

- **"I heat with oil/pellets."**
  → [Heating oil](functional/05-heizoel.md) or
  [Wood pellets](functional/06-pellets.md)

- **"I want to work on the software."**
  → [Architecture](technical/02-architecture.md) →
  [Data model](technical/04-data-model.md) →
  [Tests](technical/05-testing.md)

---

## Conventions in this documentation

- **[Unverified]** marks assumptions or default values that do not come from a
  reliable primary source and should be adjusted in the settings (e.g. CO₂
  factors).
- Formulas are written as plain-text code blocks; all formulas documented here are
  **checked against the real source code**, not noted from memory.
- Paths are relative to the project root.

The German compendium under [`docs/`](../README.md) is the canonical, complete
reference and is kept in sync with **every** release — see the
[release process](technical/06-release-process.md).
