# Connecting Energietracker with Home Assistant

**English** · [Deutsch](../HOME-ASSISTANT.md)

> **Goal:** Home Assistant (HA) reads your smart meters automatically and sends
> the meter readings to Energietracker. You no longer maintain values by hand —
> Energietracker handles contracts, cost calculation and forecasts, while HA
> quietly delivers the data in the background.

This guide is the **official** integration (since Energietracker **v1.9.0**,
feature F1009).

> ⚠️ **Beware of circulating forum guides.** There is a popular but
> **technically wrong** guide (AI-generated) that describes `POST /api.php` with
> a `{"action":"add_reading", "value":…, "timestamp":…}` and a token from
> `settings.json`. **None of that exists in Energietracker.** Use only the
> interface described here (`POST /api/ingest`).

---

## Overview: how the connection works

```
┌─────────────────┐   daily push          ┌────────────────────┐
│  Home Assistant │  ──────────────────▶  │   Energietracker   │
│  (smart meter)  │   POST /api/ingest    │  contracts · costs │
│                 │   Bearer token        │  forecasts · UI    │
└─────────────────┘                       └────────────────────┘
```

1. Generate an **API token** in Energietracker (once) → protects the push.
2. Give each meter an **alias** (e.g. `stromzaehler_haus`).
3. In HA, create a **REST command** + an **automation** that sends the meter
   readings in the evening.

All three steps can be prepared directly in Energietracker under
**Settings → 🏠 Home Assistant integration** (including copy-and-paste YAML).

---

## Step 1 — Generate an API token

1. Open Energietracker → **Settings** → **🏠 Home Assistant integration**.
2. Click **"Generate token"**. The token is shown **only once** — copy it
   immediately and store it safely (e.g. in the HA secrets).
3. As long as no token is set, the push endpoint is openly reachable (intended
   for the local network only). **Once a token exists, HA must send it along** —
   otherwise the endpoint responds with `401`.

> The token is stored server-side only as a **hash** (in `data/auth.json`), never
> in clear text and not in the normal settings. If you lose it, simply generate a
> new one (the old one then becomes invalid).

---

## Step 2 — Assign meter aliases

HA should not address the meters via cryptic internal IDs (`m_strom_main`).
Instead, give each meter an **alias**:

- In **Settings → 🏠 Home Assistant integration → Meter aliases**, enter an alias
  per meter (e.g. `stromzaehler_haus`, `gaszaehler_wohnung`) and **save**.
- Allowed are 1–64 characters from letters, digits, `_`, `.`, `-`.
- The alias must be unique within a utility.

The ingest endpoint accepts both the alias and the internal ID — the alias is
just the more convenient, readable variant.

---

## Step 3 — REST command in Home Assistant

In the `configuration.yaml` (adjust URL/token — the ready-made snippet is also in
the settings, ready to copy):

```yaml
rest_command:
  energietracker_push:
    url: "http://YOUR-ENERGIETRACKER-IP:8080/api.php/api/ingest"
    method: POST
    headers:
      Authorization: "Bearer !secret energietracker_token"
      Content-Type: "application/json"
    payload: >
      {
        "utility": "{{ utility }}",
        "meter": "{{ meter }}",
        "value": {{ states(sensor_entity) | float(0) }},
        "date": "{{ now().strftime('%Y-%m-%d') }}"
      }
```

> **Path note:** `…/api.php/api/ingest` always works. If your web server has a
> rewrite rule (Apache `.htaccess` / nginx), `…/api/ingest` works too.

Token in `secrets.yaml`:

```yaml
energietracker_token: "et_your_copied_token"
```

---

## Step 4 — Automation (daily push)

```yaml
alias: "Energy: send meter readings to Energietracker"
description: "Sends the daily meter readings in the evening for contract & cost upkeep"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - action: rest_command.energietracker_push
    data:
      utility: "strom"
      meter: "stromzaehler_haus"
      sensor_entity: "sensor.stromzaehler_total_kwh"
  - action: rest_command.energietracker_push
    data:
      utility: "gas"
      meter: "gaszaehler_haus"
      sensor_entity: "sensor.gaszaehler_total_m3"
mode: single
```

> **Idempotent:** A repeated push on the same day (e.g. a manual test + the
> automation) creates **no** duplicate — Energietracker updates the existing daily
> value (upsert per meter & date).

---

## Important: the units must match

Energietracker works with the units of the respective utility. The HA sensor must
deliver **the same cumulative meter reading** in that unit:

| Utility | `utility` | Expected unit |
|---------|-----------|---------------|
| Electricity   | `strom`     | kWh |
| Gas           | `gas`       | m³  |
| Water         | `wasser`    | m³  |
| District heat | `fernwaerme`| kWh |

> **Not supported:** heating oil and pellets (`heizoel`/`pellets`) — they work
> with **deliveries** instead of meter readings. An ingest on them is rejected
> with `400`.

What matters is the **absolute meter reading** (the value on the meter), not the
daily consumption — Energietracker forms the differences itself and accounts for
meter swaps without loss.

---

## Use case A — detached house with PV and district heating

**Situation:** detached house, smart meter for grid draw, heat meter for district
heating, PV system with feed-in meter. HA already has all these sensors.

**Aliases in Energietracker:**

| Meter | Utility | Alias |
|-------|---------|-------|
| House connection electricity | `strom` | `strom_haus` |
| District heating | `fernwaerme` | `fernwaerme_haus` |
| PV feed-in | `pv_einspeisung` | `pv_einspeisung_haus` |

**HA automation:**

```yaml
alias: "Energy: detached house → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - action: rest_command.energietracker_push
    data: { utility: "strom",          meter: "strom_haus",          sensor_entity: "sensor.netz_bezug_total_kwh" }
  - action: rest_command.energietracker_push
    data: { utility: "fernwaerme",     meter: "fernwaerme_haus",     sensor_entity: "sensor.waermemenge_total_kwh" }
  - action: rest_command.energietracker_push
    data: { utility: "pv_einspeisung", meter: "pv_einspeisung_haus", sensor_entity: "sensor.einspeisung_total_kwh" }
mode: single
```

In Energietracker you then see grid-draw costs, the district-heating bill and the
PV feed-in compensation — without ever typing a value manually.

---

## Use case B — rented flat (electricity, gas, water)

**Situation:** rented flat with electricity, gas and a (readable) water meter. HA
reads electricity/gas via a smart-meter reading head, water e.g. via a pulse
sensor.

**Aliases in Energietracker:**

| Meter | Utility | Alias |
|-------|---------|-------|
| Flat electricity meter | `strom` | `strom_wohnung` |
| Flat gas meter | `gas` | `gas_wohnung` |
| Water meter | `wasser` | `wasser_wohnung` |

**HA automation:**

```yaml
alias: "Energy: flat → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - action: rest_command.energietracker_push
    data: { utility: "strom",  meter: "strom_wohnung",  sensor_entity: "sensor.strom_total_kwh" }
  - action: rest_command.energietracker_push
    data: { utility: "gas",    meter: "gas_wohnung",    sensor_entity: "sensor.gas_total_m3" }
  - action: rest_command.energietracker_push
    data: { utility: "wasser", meter: "wasser_wohnung", sensor_entity: "sensor.wasser_total_m3" }
mode: single
```

Energietracker takes over advance-payment monitoring, the surcharge forecast and
(for water) the saving index — ideal for preparing the annual utility bill.

---

## Troubleshooting

| Symptom (HA log) | Cause & fix |
|------------------|-------------|
| `401` | Token set, but the header is missing/wrong. Check `Authorization: Bearer <token>`; regenerate the token if needed. |
| `400 No meter found for "…"` | The alias/ID does not match the meter. Check the alias in the settings. |
| `400 … works with deliveries` | Heating oil/pellets are not supported via ingest. |
| `400 Reading … is not a number` | The HA sensor delivers `unknown`/`unavailable`. Guard with `| float(0)` or tie the trigger to sensor availability. |
| The value does not appear | Wrong `utility`, or the meter is inactive in Energietracker. |

**Quick test** (from the HA machine, open mode or with a token):

```bash
curl -X POST "http://YOUR-IP:8080/api.php/api/ingest" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"utility":"strom","meter":"strom_haus","value":12345.6}'
```

A successful response contains `"status":"created"` (or `"updated"` on the second
call on the same day).

---

← [Docs index](README.md) · [API reference](API.md)
