# Use cases

**English** · [Deutsch](../USE-CASES.md)

[← Compendium index](README.md)

Four worked-through practical cases that show how Energietracker is set up and
used in concrete living situations. Each case names the **meters**, the
**settings** and a **typical workflow**. For the basic setup, see
[Getting started](getting-started.md) first.

| Use case | Focus | Features |
|---|---|---|
| [A — Shared flat with shared meters](#a--shared-flat-with-shared-meters) | Sub-meters, cost splitting | F1006 |
| [B — Smart home / Home Assistant](#b--smart-home--home-assistant-full-build-out) | Automatic push | F1009 |
| [C — PV household with a heat pump](#c--pv-household-with-a-heat-pump) | PV + sub-meters | F1005, F1006 |
| [D — Landlord with several units](#d--landlord-with-several-units) | Groups per unit | F1006 |

---

## A — Shared flat with shared meters

**Situation.** Four-person shared flat, one common main electricity meter. One
person runs a power-hungry server/gaming PC with its own plug-in meter and wants
to keep their share cleanly separate.

**Setup.**

1. Create a `strom` main meter: *"shared-flat house connection"*.
2. Create a second `strom` meter: *"study (server)"*.
3. On the second meter, under **Meters / contracts**, set the **parent meter** to
   *"shared-flat house connection"* → it becomes a **sub-meter**.

**What happens.** The server sub-meter is subtracted from the house connection.
On the dashboard you see:

```
⚡ Shared-flat house connection .. 612 kWh   (net, without server)
   ↳ Study (server) .............. 188 kWh   (breakdown)
Electricity total ............... 612 kWh
```

This lets you quantify the server share (188 kWh) exactly for the internal
shared-flat settlement, while the flat's total cost (612 kWh × tariff) stays
correct without double counting. Details:
[Meter topology](functional/13-meter-topologie.md).

> **Tip.** Maintain the shared-flat contract on the main meter. The sub-meter
> needs no contract of its own — its kWh figure is enough for the internal
> apportionment.

---

## B — Smart home / Home Assistant (full build-out)

**Situation.** A tech-savvy household with Home Assistant (HA) that already reads
all meters digitally (electricity + gas via a reading head, water via a pulse
meter). Nobody wants to type values anymore.

**Setup.**

1. In **Settings → 🏠 Home Assistant integration**, generate an **API token**
   (copy it once).
2. Give each meter an **alias**: `strom_haus`, `gas_haus`, `wasser_haus`.
3. In HA, insert the ready-made `rest_command` YAML (copyable from the settings)
   and build an automation that pushes all meters at 23:55 in the evening.

**HA automation (abbreviated):**

```yaml
alias: "Energy → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - action: rest_command.energietracker_push
    data: { utility: "strom",  meter: "strom_haus",  sensor_entity: "sensor.strom_total_kwh" }
  - action: rest_command.energietracker_push
    data: { utility: "gas",    meter: "gas_haus",    sensor_entity: "sensor.gas_total_m3" }
  - action: rest_command.energietracker_push
    data: { utility: "wasser", meter: "wasser_haus", sensor_entity: "sensor.wasser_total_m3" }
```

**What happens.** Every evening a daily meter reading per meter arrives in
Energietracker — **idempotent**: a second push on the same day (e.g. a manual
test) overwrites the value instead of creating a duplicate. The complete
step-by-step guide including troubleshooting is in
[Home Assistant](HOME-ASSISTANT.md).

> **Security.** As long as no token is set, the ingest is open (intended for the
> LAN only). Once a token exists, `/api/ingest` requires it as
> `Authorization: Bearer …`. The token is stored server-side only as a hash.

---

## C — PV household with a heat pump

**Situation.** A detached house with a PV system and a heat pump. Wanted:
self-sufficiency rate, feed-in compensation **and** the separate electricity
consumption of the heat pump.

**Setup.**

1. In **Settings → Active utilities**, activate `pv_einspeisung` and
   `pv_erzeugung`.
2. Create meters:
   - `strom` *"house connection"* (grid draw),
   - `strom` *"heat pump"* → **sub-meter** of *"house connection"*,
   - `pv_einspeisung` *"feed-in meter"* (feed-in compensation),
   - `pv_erzeugung` *"inverter"* (total generation).
3. On the feed-in meter, store the simplified PV contract (only ct/kWh).

**What the app shows.**

- **Electricity balance** (`/api/strom-saldo`): grid draw − feed-in, i.e. the
  real direction of electricity over the year.
- **Self-sufficiency rate & self-consumption** (`/api/pv-summary`) from
  generation vs. draw.
- The **heat-pump sub-meter** shows how much of the house electricity goes into
  heating — ideal for keeping an eye on the heat-pump efficiency (SCOP) — without
  doubling the electricity total.

Background: [PV](functional/12-pv.md) and
[Meter topology](functional/13-meter-topologie.md). If you also pull the heat-pump
values from HA, combine this with use case B (alias `strom_waermepumpe`).

---

## D — Landlord with several units

**Situation.** A two-family house, rented out. Each residential unit has its own
meters for electricity and water; the landlord wants to evaluate each unit
separately and prepare the later utility bill.

**Setup.**

1. Create one meter per unit and type:
   `strom` *"unit 1 electricity"*, `strom` *"unit 2 electricity"*,
   `wasser` *"unit 1 water"*, `wasser` *"unit 2 water"*,
   plus `wasser` *"common/garden"*.
2. Form one **group** per unit (merge wizard): *"flat 1"* combines the unit-1
   meters, *"flat 2"* those of the second unit.
3. Maintain contracts per meter (each flat has its own supply contract).

**What happens.** The dashboard shows an expandable aggregate item per group —
*"flat 1: 2,940 kWh / 78 m³"* — and the individual meters below it. This way the
landlord sees unit by unit without having to add up the meters manually.

> **Outlook NKA.** The structured utility billing for tenants (relevant meter
> readings, flat-rate apportionments, the annual final bill with a PDF upload) is
> planned as feature **F1008** and builds exactly on this group/topology
> structure. Until then, groups + the
> [CSV export](technical/03-api-reference.md) provide a solid basis per unit.

---

## Which use case fits me?

- **I type values myself but want order with main/sub-meters.** → A
- **I have Home Assistant and never want to type again.** → B
- **I have PV (+ a heat pump).** → C
- **I manage several residential units.** → D

All four can be combined — e.g. a landlord (D) with an HA push (B) per unit. To
get started: [Getting started](getting-started.md).

---

[← Compendium index](README.md)
