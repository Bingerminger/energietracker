# Use cases

**English** · [Deutsch](../../anleitungen/anwendungsfaelle.md)

[← Compendium index](../README.md)

Five worked-through practical cases that show how Energietracker is set up and
used in concrete living situations. Each case names the **meters**, the
**settings** and a **typical workflow**. For the basic setup, see
[Getting started](../einstieg/erste-schritte.md) first.

| Use case | Focus | Features |
|---|---|---|
| [A — Shared flat with shared meters](#a--shared-flat-with-shared-meters) | Sub-meters, cost splitting | F1006 |
| [B — Smart home / Home Assistant](#b--smart-home--home-assistant-full-build-out) | Automatic push | F1009 |
| [C — PV household with a heat pump](#c--pv-household-with-a-heat-pump) | PV + sub-meters | F1005, F1006 |
| [D — Landlord with several units](#d--landlord-with-several-units) | Meters per unit | F1006 |
| [E — Wall box and company car](#e--wall-box-and-company-car) | Charging record, § 14a EnWG | v3.1.0 |

---

## A — Shared flat with shared meters

**Situation.** Four-person shared flat, one common main electricity meter. One
person runs a power-hungry server/gaming PC with its own plug-in meter and wants
to keep their share cleanly separate.

**Setup.**

1. Create a `strom` main meter: *"shared-flat house connection"*.
2. Create a second `strom` meter: *"study (server)"*.
3. On the second meter, under **⚙️ Meters → Edit**, set the **parent meter** to
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
[Meter topology](../verstehen/13-meter-topologie.md).

> **Tip.** Maintain the shared-flat contract on the main meter. The sub-meter
> needs no contract of its own — its kWh figure is enough for the internal
> apportionment.

---

## B — Smart home / Home Assistant (full build-out)

**Situation.** A tech-savvy household with Home Assistant (HA) that already reads
all meters digitally (electricity + gas via a reading head, water via a pulse
meter). Nobody wants to type values anymore.

**Setup.**

1. In **Settings → Integrations → 🏠 Home Assistant integration**, generate an
   **API token** (copy it once).
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
[Home Assistant](home-assistant.md).

> **Security.** The token protects only the push endpoint `/api/ingest`, not
> the app: without a token the ingest accepts values without a header (intended
> for the home network only), with a token it requires
> `Authorization: Bearer …`. The app itself is protected by the optional
> sign-in (since v2.6.0); once it is switched on, the token is mandatory. See
> [Security & network operation](../betrieb/sicherheit.md). The token is stored
> server-side only as a hash.

---

## C — PV household with a heat pump

**Situation.** A detached house with a PV system and a heat pump. Wanted:
self-sufficiency rate, feed-in compensation **and** the separate electricity
consumption of the heat pump.

**Setup.**

1. In **Settings → Utilities & billing → Active utilities**, activate
   `pv_einspeisung` and `pv_erzeugung`.
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
  heating without doubling the electricity total; with the role “Heat pump
  (heating electricity)” it counts in the efficiency figure.

**Since v3.1.0 also:**

- **Seasonal performance factor.** With a heat meter — Heat, role “Heat pump
  output”, linked to the heat pump’s electricity meter — both meters show the
  card “Heat pump {year}”: e.g. 9,000 kWh of heat from 2,500 kWh of
  electricity, SPF 3.6 (made-up example;
  [Heat §7](../verstehen/15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310)).
- **Battery.** Charging and discharging meters under PV generation (roles
  “Battery – charging” and “Battery – discharging”) give losses, efficiency and
  full cycles ([PV §7](../verstehen/12-pv.md#7-battery-v310)).
- **Payback.** Investment and commissioning date on the inverter meter show
  when the system has paid for itself
  ([PV §9](../verstehen/12-pv.md#9-payback-v310)).
- **Grid fee under § 14a EnWG.** If the heat pump is controllable, the
  reduction (module 1) goes into the electricity contract; with a meter of its
  own from the grid operator (module 2) the heat pump meter gets a contract of
  its own ([Electricity](../verstehen/02-strom.md#controllable-consumers-v310)).
- **Data from the portals** of inverter and heat pump can be read as a file
  ([Time series from portals](daten-aus-portalen.md)).

Background: [PV](../verstehen/12-pv.md),
[Detached house scenario §6a](../verstehen/08-szenario-eigenheim.md#6a-heat-pump-v310)
and [Meter topology](../verstehen/13-meter-topologie.md). If you also pull the
heat-pump values from HA, combine this with use case B (alias
`strom_waermepumpe`, for the heat output e.g. `waerme_wp` —
[Home Assistant](home-assistant.md#use-case-c--heat-pump-with-a-heat-meter)).

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
2. Maintain contracts per meter (each flat has its own supply contract).
3. If a unit has several meters **of the same type** (such as peak/off-peak or a
   wallbox), a **group** combines them (⚙️ Meters → "Group meters").
   Groups apply per utility — electricity and water of one flat cannot be
   bundled into one group. Since v3.1.0 a contract can belong to the whole
   group, e.g. a peak/off-peak contract with one standing charge and two unit
   prices ([Group contract](../verstehen/13-meter-topologie.md#group-contract-v310)).

**What happens.** Under **Consumption → Electricity** or **Water**, the
selection at the top shows every meter with consumption, cost and the balance of
its contract; the overview shows the total per utility. The CSV export of the
readings (Settings → Data) carries the meter on every row — the unit can be
filtered from it. The monthly overview exports the total per utility, not per
unit.

> **Service charges.** Since v3.1.0 there is the **tenant’s** view: prepayment
> against expected costs and the service charge statement with its deadlines
> (F1008, [#15](https://github.com/Bingerminger/energietracker/issues/15),
> [As a tenant](mieter.md)). The app does not produce a statement for the
> landlord across several units. Contracts per meter group
> ([#17](https://github.com/Bingerminger/energietracker/issues/17)) exist since
> v3.1.0.

---

## E — Wall box and company car

**Situation.** A detached house with a wall box behind the household meter. The
company car is charged at home; the employer reimburses the electricity and
wants a monthly statement.

**Setup.**

1. `strom` *"house connection"* with the electricity contract.
2. `strom` *"wall box"* with the role **“Wallbox (EV charger)”** and the parent
   meter *"house connection"* → sub-meter.
3. Read the wall box monthly, ideally on the first of the month — by hand, via
   Home Assistant or from the wall box portal.
4. Optional: if the wall box is controllable under § 14a EnWG, fill the list
   “Reduced grid fee (§ 14a EnWG, module 1)” in the electricity contract.

**What happens.** The wall box’s consumption view shows the card **“Charging
record (company car)”**: choose the year, choose the price — contract price
with a pro-rata standing charge or the flat electricity rate (2026:
34 ct/kWh) —, download CSV or PDF. The electricity total stays right because
the wall box is contained in the house connection as a sub-meter.

```
Example (made up), contract price, March:
  house connection 400 kWh, energy cost €120.00 → 30 ct/kWh, standing charge €12.00
  wall box 100 kWh → €30.00 + €12.00 × 100/400 = €33.00
```

A statement, not tax advice — step by step:
[Record charging electricity for a company car](ladestrom-nachweis.md).

---

## Which use case fits me?

- **I type values myself but want order with main/sub-meters.** → A
- **I have Home Assistant and never want to type again.** → B
- **I have PV (+ a heat pump).** → C
- **I manage several residential units.** → D
- **I charge a company car at home.** → E

All five can be combined — e.g. a landlord (D) with an HA push (B) per unit. To
get started: [Getting started](../einstieg/erste-schritte.md).

---

[← Compendium index](../README.md)
