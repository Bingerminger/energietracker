# Energietracker — Compendium

**English** · [Deutsch](../README.md)

> Documentation for the Energietracker, ordered by what you want to do. German
> is the authoritative version and English its complete mirror; the other
> interface languages have the help inside the app.
>
> **New here?** → [Getting started](einstieg/erste-schritte.md) — one example
> all the way from installation to the first forecast.

The Energietracker is a self-hosted web app for a household's energy and water
consumption: eight utilities, contracts and billing, weather adjustment,
forecast and switch decision — without a database, everything as JSON files on
your own server. The only outside contact is Open-Meteo (weather data).

---

## 🚀 Getting started — *get going*

| Document | Contents |
|---|---|
| [Getting started](einstieg/erste-schritte.md) | Installation → first meter → first reading → first contract → first forecast |
| [What the Energietracker does](einstieg/funktionen.md) | All features, ordered by question |
| [Questions and answers](einstieg/faq.md) | The most common questions, answered briefly |
| [Use on your phone](einstieg/handy.md) | Open it on the home network, install it as an app, what works offline |

## 🧭 Guides — *get a task done*

| Document | Contents |
|---|---|
| [Enter and check the annual bill](anleitungen/jahresabrechnung.md) | Take readings, prices, gas factors and the credit from a bill and recalculate it |
| [Connect Home Assistant](anleitungen/home-assistant.md) | Push readings automatically: token, meter alias, REST command |
| [Use cases](anleitungen/anwendungsfaelle.md) | Shared flat with shared meters, smart home, PV household, landlord |
| [Moving from v0.9.0](anleitungen/migration-v090.md) | Take over an old backup |

## 📚 Concepts — *how things are calculated*

| Document | Contents |
|---|---|
| [Basics & methodology](verstehen/00-overview.md) | Heating degree days, heating model, regression, forecast, weather adjustment — the formulas |
| [Gas](verstehen/01-gas.md) · [Electricity](verstehen/02-strom.md) · [Water](verstehen/03-wasser.md) · [District heating](verstehen/04-fernwaerme.md) | The meter-based utilities |
| [Heating oil](verstehen/05-heizoel.md) · [Wood pellets](verstehen/06-pellets.md) | Delivery-based, with a tank book |
| [PV feed-in & generation](verstehen/12-pv.md) | Remuneration, self-consumption, self-sufficiency |
| [Special payments](verstehen/10-sonderzahlungen.md) | Refund and back-payment, voluntary advance, effect on the balance |
| [Meter-reading capture](verstehen/11-zaehlerstaende.md) | Capture in one pass, plausibility, direct link |
| [Meter topology](verstehen/13-meter-topologie.md) | Sub-meters and meter groups |
| [Country profiles](verstehen/14-laenderprofile.md) | Currency, formats, efficiency scale, CO₂ factors, gas units per country |
| [Scenario flat](verstehen/07-szenario-wohnung.md) · [Scenario house](verstehen/08-szenario-eigenheim.md) | Worked examples |
| [Glossary & formulas](verstehen/09-glossar.md) | All terms and formulas in brief |

## 📖 Reference — *look things up*

| Document | Contents |
|---|---|
| [Views](referenz/ansichten.md) | Every view explained, with real screenshots |
| [Settings](referenz/einstellungen.md) | Every key with default, place in the interface and effect |
| [API reference](referenz/api.md) | All routes with status codes and stability promise (checked against the code by a test) |
| [API examples](referenz/api-beispiele.md) | Detailed requests and responses of the most used endpoints |
| [Data model](referenz/datenmodell.md) | JSON schemas, storage, schema migration |

## 🛠️ Operation — *install and keep it running*

| Document | Contents |
|---|---|
| [Installation](betrieb/installation.md) | Requirements, setup, update, backup |
| [Docker](betrieb/docker.md) | Container quick start, `docker compose`, Synology, updates, data volume, logs |
| [Web server](betrieb/webserver.md) | Apache and nginx with the tested rules |
| [Security & network operation](betrieb/sicherheit.md) | Sign-in, API keys, proxy, HTTPS, checklist before going live |
| [Troubleshooting](betrieb/fehlersuche.md) | Symptom → cause → fix |

## 🧑‍💻 Development — *work on the code*

| Document | Contents |
|---|---|
| [Architecture](entwicklung/architektur.md) | Layers, services, controllers, frontend |
| [Data flow & algorithms](entwicklung/datenfluss.md) | From meter reading to balance, forecast algorithm |
| [Tests](entwicklung/tests.md) | PHPUnit, shape and render tests, counter-checks |
| [Release process](entwicklung/release-prozess.md) | Versioning, CHANGELOG, documentation upkeep, lessons learned |
| [Screenshots](entwicklung/screenshots.md) | How the images in these docs are made |

Contributing: [CONTRIBUTING.md](../../CONTRIBUTING.md) ·
Security issues: [SECURITY.md](../../SECURITY.md) ·
Version history: [CHANGELOG.md](../../CHANGELOG.md) (in German) ·
Planning: [roadmap.md](../../roadmap.md) (in German)

---

## Conventions in these docs

- Interface terms appear exactly as the app shows them; menu paths as
  "Settings → Weather data".
- Formulas are plain-text code blocks, checked against the source code, not
  written from memory.
- **[Unverified]** marks assumptions and defaults without a reliable primary
  source that should be adjusted in the settings.
- Paths are relative to the project directory. Since v2.14.0 the docs are
  ordered by audience; the old paths (`technical/`, `functional/`, `ui/` …)
  redirect to the new places. File names stay the same in both languages, so a
  page and its mirror always sit at the same relative path.
- A test checks every link and anchor, the mirror of every page and that this
  index lists every page.
