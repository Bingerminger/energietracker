# Connecting Energietracker with Home Assistant

**English** · [Deutsch](../../anleitungen/home-assistant.md)

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
│                 │  ◀──────────────────  │                    │
│  sensors        │   GET /api/summary    │                    │
└─────────────────┘   hourly              └────────────────────┘
```

1. Generate an **API token** in Energietracker (once) → protects the push.
2. Give each meter an **alias** (e.g. `stromzaehler_haus`).
3. In HA, create a **REST command** + an **automation** that sends the meter
   readings in the evening.
4. Optional (since v3.1.0): **values back** — REST sensors show balance,
   forecast and days since the last reading in Home Assistant
   ([step 5](#step-5--values-back-to-home-assistant)).

All steps can be prepared directly in Energietracker under
**Settings → Integrations → 🏠 Home Assistant integration** (including
copy-and-paste YAML).

---

## Step 1 — Generate an API token

1. Open Energietracker → **Settings** → **Integrations** →
   **🏠 Home Assistant integration**.
2. Click **"Generate token"**. The token is shown **only once** — copy it
   immediately and store it safely (e.g. in the HA secrets).
3. The token protects **only** the push endpoint `/api/ingest`, not the rest of
   the app. As long as none is set, the push accepts values without a token
   (intended for the home network only). **Once a token exists, HA must send it
   along** — otherwise the endpoint responds with `401`.
4. **With sign-in switched on** (Settings → Access → "Sign-in & access", since
   v2.6.0) the token is **mandatory**: without a token the push then rejects
   every value. The app itself is protected by sign-in — see
   [Security & network operation](../betrieb/sicherheit.md).

> The token is stored server-side only as a **hash** (in `data/auth.json`), never
> in clear text and not in the normal settings. If you lose it, simply generate a
> new one (the old one then becomes invalid).
>
> Since v2.6.0 the card shows when a value last arrived with the token
> (accurate to the hour) — the first question when troubleshooting.

---

## Step 2 — Assign meter aliases

HA should not address the meters via cryptic internal IDs (`m_strom_main`).
Instead, give each meter an **alias**:

- In **Settings → Integrations → 🏠 Home Assistant integration → Meter
  aliases**, enter an alias per meter (e.g. `stromzaehler_haus`,
  `gaszaehler_wohnung`) and **save**.
- Allowed are 1–64 characters from letters, digits, `_`, `.`, `-`.
- The alias must be unique within a utility.

The ingest endpoint accepts both the alias and the internal ID — the alias is
just the more convenient, readable variant.

---

## Step 3 — REST command in Home Assistant

In the `configuration.yaml` (adjust the URL — the ready-made snippet with the
right address is also in the settings, ready to copy):

```yaml
rest_command:
  energietracker_push:
    url: "http://YOUR-ENERGIETRACKER-IP:8080/api.php/api/ingest"
    method: POST
    headers:
      Authorization: !secret energietracker_auth   # whole value from secrets.yaml, including “Bearer”
      Content-Type: "application/json"
    # float without a default: if the sensor is unavailable the push fails instead of recording 0.
    payload: >
      {
        "utility": "{{ utility }}",
        "meter": "{{ meter }}",
        "value": {{ states(sensor_entity) | float }},
        "date": "{{ now().strftime('%Y-%m-%d') }}"
      }
```

> **Path note:** `…/api.php/api/ingest` always works. If your web server has a
> rewrite rule (Apache `.htaccess` / nginx), `…/api/ingest` works too.

Token in `secrets.yaml` — the **whole** header value, including `Bearer`:

```yaml
energietracker_auth: "Bearer et_your_copied_token"
```

> **Why like this?** In Home Assistant, `!secret` only works as a complete YAML
> value. Inside `"Bearer !secret energietracker_token"` (as this guide said up to
> v2.5.2) it is plain text — Energietracker receives the string literally and
> answers `401`.

> ⚠️ **Did you copy the template before v2.5.3?** Then your config contains
> `| float(0)`. Please remove it (`| float`) and add the condition from step 4.
> `float(0)` turns an unavailable sensor into a meter reading of **0**; the next
> real reading then counts as the consumption of a single day and distorts
> costs, balance and forecast.

---

## Step 4 — Automation (daily push)

```yaml
alias: "Energy: send meter readings to Energietracker"
description: "Sends the daily meter readings in the evening for contract & cost upkeep"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  # Only send when the sensor has a value – right after a restart it is briefly “unavailable”.
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.stromzaehler_total_kwh') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "strom"
          meter: "stromzaehler_haus"
          sensor_entity: "sensor.stromzaehler_total_kwh"
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.gaszaehler_total_m3') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "gas"
          meter: "gaszaehler_haus"
          sensor_entity: "sensor.gaszaehler_total_m3"
mode: single
```

> **The condition is part of it.** If a sensor is unavailable right now (HA
> restart, radio dropout at 23:55), the automation skips that meter for today —
> the others keep running. A skipped day costs nothing: Energietracker spreads
> consumption linearly between two readings anyway. A wrong reading, on the other
> hand, distorts everything until someone finds it. The settings generate this
> automation ready-made from your aliases.

> **Idempotent:** A repeated push on the same day (e.g. a manual test + the
> automation) creates **no** duplicate — Energietracker updates the existing daily
> value (upsert per meter & date).

---

## Step 5 — Values back to Home Assistant

Since v3.1.0 it also works the other way round: Energietracker calculates
balance, forecast and due dates, Home Assistant shows them and reacts to them.
The source is `GET /api/summary` — one request for all sensors; the fields are
in the [API reference](../referenz/api.md#get-apisummary-v310).

The settings generate the block ready-made from your meters (card
**“Step 5 · Values back into Home Assistant”**): per meter the forecast of the
next twelve months and the days since the last reading, plus the balance for
meters with a contract. For an electricity meter it looks like this — into
`configuration.yaml`:

```yaml
rest:
  - resource: "http://YOUR-ENERGIETRACKER-IP:8080/api.php/api/summary"
    scan_interval: 3600   # once an hour is enough
    headers:
      Authorization: !secret energietracker_read   # only needed if sign-in is enabled
    sensor:
      - name: "Electricity – Balance"
        unique_id: energietracker_strom_m_strom_default_balance
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='contract.balance', default=none) | first }}"
        device_class: monetary
        unit_of_measurement: "EUR"
      - name: "Electricity – Forecast 12 months"
        unique_id: energietracker_strom_m_strom_default_forecast_12m
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='forecast_12m.value', default=none) | first }}"
        device_class: energy
        unit_of_measurement: "kWh"
      - name: "Electricity – Days since reading"
        unique_id: energietracker_strom_m_strom_default_days_since_reading
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='days_since_reading', default=none) | first }}"
        device_class: duration
        unit_of_measurement: "d"
```

- **`key`** is `<utility>.<internal meter ID>` (not the alias) — the template
  from the settings fills it in correctly. The `unique_id` is derived from it
  and stays stable as long as the meter does.
- **`scan_interval: 3600`**: the values change at most with a new reading; once
  an hour is enough. The response may be cached for five minutes.
- **Balance** has the sign of the API: positive = additional payment, negative =
  credit (for PV feed-in: positive = payout). The unit `EUR` stands for the
  currency of your settings.
- A sensor shows “unknown” when the value does not exist — for example without a
  current contract or before the forecast has enough data.

**With sign-in switched on**, Home Assistant needs an API key with scope
**“Read”** to read (Settings → Access → “API keys for scripts”). The ingest
token from step 1 does not work for this. Into `secrets.yaml`, again the whole
header value:

```yaml
energietracker_read: "Bearer etk_…"
```

Without sign-in, leave out the two lines `headers:` and `Authorization: …` —
otherwise Home Assistant complains about the missing secret.

**Dashboard card** (card “Entities”, in the card editor “Code editor”):

```yaml
type: entities
title: Energietracker
entities:
  - entity: sensor.electricity_balance
  - entity: sensor.electricity_forecast_12_months
  - entity: sensor.electricity_days_since_reading
```

Home Assistant builds the entity IDs from the name; check them under
Settings → Devices & services → Entities.

**Automation “Cancellation deadline in 30 days”.** It needs a fourth sensor,
appended under `sensor:` in the block above:

```yaml
      - name: "Electricity – Days to deadline"
        unique_id: energietracker_strom_m_strom_default_days_to_cancel
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='contract.days_to_cancel', default=none) | first }}"
        unit_of_measurement: "d"
```

And the automation:

```yaml
alias: "Energietracker: cancellation deadline in 30 days"
triggers:
  - trigger: numeric_state
    entity_id: sensor.electricity_days_to_deadline
    below: 31
    above: 0
actions:
  - action: persistent_notification.create
    data:
      title: "Electricity: cancellation deadline"
      message: >-
        {{ states('sensor.electricity_days_to_deadline') }} days left until the
        last day to cancel. Time to compare offers.
mode: single
```

It fires once when the value drops below 31. If you prefer to see deadlines in
your calendar: [Subscribe in your calendar](kalender.md).

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
| PV feed-in    | `pv_einspeisung` | kWh |
| PV generation | `pv_erzeugung` | kWh |
| Heat *(v3.1.0)* | `waerme` | kWh |

> **Not supported:** heating oil and pellets (`heizoel`/`pellets`) — they work
> with **deliveries** instead of meter readings. An ingest on them is rejected
> with `400`. So is a meter with the recording “consumption per period”
> (`errors.ingest.periodMeter`, since v3.1.0).

What matters is the **absolute meter reading** (the value on the meter), not the
daily consumption — Energietracker forms the differences itself and accounts for
meter swaps without loss.

**Data volume:** one push per day and meter is enough. Further ones on the same
day overwrite that day's reading (`"status":"updated"`) — the file grows by one
entry per day, about 90 KB a year per meter.

> **Data quality:** only send real, absolute meter readings — never a substitute
> value. When a meter is replaced, record it in Energietracker (meter view →
> **Meter swap**), not in the push: the new meter starts again at a small value,
> and without a swap entry that would look like the meter running backwards.

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
  - if: [{ condition: template, value_template: "{{ has_value('sensor.netz_bezug_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "strom",          meter: "strom_haus",          sensor_entity: "sensor.netz_bezug_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.waermemenge_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "fernwaerme",     meter: "fernwaerme_haus",     sensor_entity: "sensor.waermemenge_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.einspeisung_total_kwh') }}" }]
    then:
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
  - if: [{ condition: template, value_template: "{{ has_value('sensor.strom_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "strom",  meter: "strom_wohnung",  sensor_entity: "sensor.strom_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.gas_total_m3') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "gas",    meter: "gas_wohnung",    sensor_entity: "sensor.gas_total_m3" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.wasser_total_m3') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "wasser", meter: "wasser_wohnung", sensor_entity: "sensor.wasser_total_m3" }
mode: single
```

Energietracker takes over advance-payment monitoring, the surcharge forecast and
(for water) the saving index — ideal for preparing the annual utility bill.

---

## Use case C — heat pump with a heat meter

**Situation:** a detached house with a heat pump. HA reads the heat pump’s
electricity meter and its heat meter (via the manufacturer’s integration or an
M-Bus adapter). Since v3.1.0 Energietracker calculates the seasonal performance
factor from them ([Heat §7](../verstehen/15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310)).

**In Energietracker:**

| Meter | Utility, role | Alias |
|--------|---------------|-------|
| Heat pump electricity | `strom`, role “Heat pump (heating electricity)”, sub-meter of the house connection | `strom_waermepumpe` |
| Heat pump heat output | `waerme`, role “Heat pump output”, linked to `strom_waermepumpe` under “Heat pump electricity meter” | `waerme_wp` |

The utility **Heat** must be switched on (Settings → Utilities & billing). Both
sensors deliver cumulative meter readings in kWh.

**HA automation:**

```yaml
alias: "Energy: heat pump → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - if: [{ condition: template, value_template: "{{ has_value('sensor.waermepumpe_strom_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "strom",  meter: "strom_waermepumpe", sensor_entity: "sensor.waermepumpe_strom_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.waermepumpe_waerme_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "waerme", meter: "waerme_wp",         sensor_entity: "sensor.waermepumpe_waerme_total_kwh" }
mode: single
```

Both readings arrive on the same evening; the card “Heat pump {year}” only
counts months in which both meters have values.

---

## Backfilling after an outage

If Home Assistant was down for a few days or the reading head delivered
nothing, those days are missing. That costs nothing: Energietracker spreads
consumption linearly between two readings. If you still have the readings —
from the HA statistics, an InfluxDB or the reading head’s log —, since v3.1.0
you can send them in **one** batch: a list of up to 500 entries, each like a
single push.

```bash
curl -X POST "http://YOUR-IP:8080/api.php/api/ingest" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"readings":[
        {"utility":"strom","meter":"strom_haus","value":12301.4,"date":"2026-10-03"},
        {"utility":"strom","meter":"strom_haus","value":12312.9,"date":"2026-10-04"},
        {"utility":"strom","meter":"strom_haus","value":12324.0,"date":"2026-10-05"}
      ]}'
```

The response is `200` even if individual entries fail. **Check `failed`**: the
response names `status` per entry (`created`, `updated` or `error`) and, for
errors, the `code`, plus the totals `created`, `updated` and `failed`. With
`jq`:

```bash
… | jq '.data.failed, [.data.results[] | select(.status == "error") | {index, code}]'
```

With Node-RED a function node builds the batch, for example from a query of
the last few days, and an `http request` node sends it:

```js
// msg.payload: [{ day: '2026-10-03', kwh: 12301.4 }, …] from the previous query
const readings = msg.payload
  .filter(r => Number.isFinite(Number(r.kwh)))          // never send 0 or empty values
  .map(r => ({ utility: 'strom', meter: 'strom_haus', value: Number(r.kwh), date: r.day }));
if (readings.length === 0) return null;
msg.payload = { readings };
msg.headers = { Authorization: 'Bearer ' + env.get('ET_TOKEN') };
return msg;
```

How a function node then evaluates `failed`, and how Home Assistant does it with
`response_variable`, is in the [API reference](../referenz/api.md#batch-v310);
the complete recipes for Node-RED, ioBroker and openHAB are under
[Other systems](andere-systeme.md).

---

## Running under Home Assistant Ingress

If Energietracker itself runs behind Home Assistant Ingress (opened from the Home
Assistant sidebar), the following applies since v3.1.0:

- **Not offline.** Under Ingress the app shares its origin with Home Assistant
  and registers no service worker. Offline entry only works when it is opened
  directly via its own port.
- **Address in the templates.** The Ingress address needs an HA session and
  changes. The templates in the settings therefore name
  `http://<container hostname>` — Home Assistant reaches the app that way on the
  internal network.
- **Sign-in via Home Assistant.** Ingress only lets signed-in HA users through.
  If the app should take over the user, set `ET_AUTH=proxy` and
  `ET_TRUSTED_PROXIES=172.30.32.2` (the address Ingress requests come from).
  Home Assistant sends `X-Remote-User-Name` and `X-Remote-User-Id`;
  Energietracker recognises them since v3.1.0. Push and sensors do not go
  through Ingress and then need the token or read key as above. More:
  [Security & network operation](../betrieb/sicherheit.md).
- **Uploads up to 16 MiB.** Without `ingress_stream: true` in the app’s
  configuration, Home Assistant limits uploads through Ingress to 16 MiB. Restore
  a larger backup via the direct port.

---

## Troubleshooting

| Symptom (HA log) | Cause & fix |
|------------------|-------------|
| `401` | Token set, but the header is missing/wrong. `!secret` only works as the **whole** value: `Authorization: !secret energietracker_auth`, with `"Bearer et_…"` in `secrets.yaml`. A `"Bearer !secret …"` sends the text literally. Otherwise regenerate the token. Since v2.6.0 also: sign-in switched on but no token generated yet. |
| The value arrives but does not count | It is lower than the previous reading and therefore **marked as suspect** (since v2.6.0; response `"suspect": true`). The view of the utility shows a notice and the reading carries "CHECK" — confirm it (✅), correct it or record a meter swap. |
| `400 No meter found for "…"` | The alias/ID does not match the meter. Check the alias in the settings. |
| `400 … works with deliveries` | Heating oil/pellets are not supported via ingest. |
| `400 Reading … is not a number` | The HA sensor delivers `unknown`/`unavailable`. **Skip** the push then, never replace it with 0: condition `has_value(…)` as in step 4. `| float(0)` trades the visible error for a silent wrong booking. |
| Template error “float got invalid input” in the HA log | Same cause, reported by `| float` without a default — intended: nothing is booked. Add the `has_value(…)` condition and the log stays quiet. |
| Response `created`/`updated`, but the value does not appear | The utility is deselected under Settings → Utilities & billing → Active utilities (then it is missing from the menu, but the value is stored anyway), or the view shows another meter or another year. A meter set to "inactive" also accepts values and counts. |
| Sensors from step 5: `401` or “unknown” | `401`: sign-in switched on, but no read key (`energietracker_read`) — the ingest token does not work for this. “unknown”: `key` does not match (it names the internal meter ID, not the alias) or the value is missing (no current contract, no forecast yet). The template in the settings fills in the keys correctly. |
| Batch: response `200`, but readings are missing | Individual entries failed — `failed` and the entries with `"status": "error"` name the reason (`code`) and position (`index`). |

**Quick test** (from the HA machine, open mode or with a token):

```bash
curl -X POST "http://YOUR-IP:8080/api.php/api/ingest" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"utility":"strom","meter":"strom_haus","value":12345.6}'
```

A successful response contains `"status":"created"` (or `"updated"` on the second
call on the same day).

### Falling values (since v2.6.0)

A meter reading cannot go down — except on a meter swap or a rollover. If Home
Assistant still delivers a value lower than the previous one of the same device
(typically a reading-head dropout arriving as 0), Energietracker stores it but
**marks it as suspect**: it counts in no evaluation until you confirm it. The
response names `"suspect": true` and the previous reading. Up to v2.5.3 a single
such value turned a normal month into consumption the size of the whole meter
reading.

Maintain the **register digits** on the meter (edit meter → "Register digits")
if it can start again at 0 after 99,999 — then a rollover is not flagged and
the evaluation calculates it correctly.

---

← [Docs index](../README.md) · [API reference](../referenz/api-beispiele.md) ·
[Other systems](andere-systeme.md) · [Subscribe in your calendar](kalender.md)
