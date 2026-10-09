# Use cases

**English** · [Deutsch](../../anleitungen/anwendungsfaelle.md)

[← Compendium index](../README.md)

Seven worked-through practical cases that show how Energietracker is set up and
used in concrete living situations. Each case names the **meters**, the
**settings** and a **typical workflow**. For the basic setup, see
[Getting started](../einstieg/erste-schritte.md) first.

| Use case | Focus | Features | Sample household |
|---|---|---|---|
| [A — Shared flat with shared meters](#a--shared-flat-with-shared-meters) | Sub-meters, cost splitting | F1006 | — |
| [B — Smart home / Home Assistant](#b--smart-home--home-assistant-full-build-out) | Automatic push | F1009 | — |
| [C — PV household with a heat pump](#c--pv-household-with-a-heat-pump) | PV + sub-meters | F1005, F1006 | Own house with heat pump and solar |
| [D — Landlord with several units](#d--landlord-with-several-units) | Meters per unit | F1006 | — |
| [E — Wall box and company car](#e--wall-box-and-company-car) | Charging record, § 14a EnWG | v3.1.0 | Own house with heat pump and solar |
| [F — District heating or heat?](#f--district-heating-or-heat) | the right utility | v3.1.0, F1018 | I rent my home · Owner-occupied flat with district heating · Own house with heat pump and solar |
| [G — Control with evcc or Home Assistant](#g--control-with-evcc-or-home-assistant--check-the-numbers-in-energietracker) | check the numbers, not control | F1009, F1022 | Own house with heat pump and solar |

## Sample households to look at *(v3.2.0)*

Four households come ready-made, with made-up data. To load one:

1. Settings → General → **“Start the setup assistant”**.
2. At “Who are you?” pick the household: “I rent my home”, “Owner-occupied flat
   with district heating”, “Own house with gas, oil or pellets” or “Own house
   with heat pump and solar”. “Just look at everything first” loads the
   household with all nine utilities.
3. At the end choose **“Look at it with sample data”**.

This replaces **all** data of this installation. The app takes a snapshot
first; you restore it under Settings → Data. Once users are set up, only an
administrator may do this.

In the [public demo](https://bingerminger.github.io/energietracker/) the
assistant asks on your first visit which household you want to see. You pick
another one there the same way, under Settings → General → Setup assistant.
More: [Setup](../einstieg/einrichtung.md).

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

**To look at:** sample household “Own house with heat pump and solar” — a PV
system with 9.8 kWp, a 10 kWh battery, a heat pump with its own meter and a heat
meter, a wall box ([sample households](#sample-households-to-look-at-v320)).

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
   Home Assistant or from the wall box portal. Since v3.2.0 the readings also
   come with the charging sessions from evcc
   ([Charging sessions from evcc](evcc.md)).
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

**To look at:** sample household “Own house with heat pump and solar” — the
wall box is a sub-meter of the household meter, its charging sessions come from
evcc ([use case G](#g--control-with-evcc-or-home-assistant--check-the-numbers-in-energietracker)).

---

## F — District heating or heat?

**Situation.** Two utilities sound almost the same and both count heat in kWh:
**District heating** and **Heat**. The difference is not the meter but the
contract — who sends you the bill?

| | District heating | Heat |
|---|---|---|
| Supply contract | **your own** with the heat supplier | none in your name |
| Paid | to the supplier: unit price, standing charge, capacity and metering charge | through the service charge statement of your rent — or not separately at all, because it is the output of your heat pump |
| In the app | contracts, advances, balance, bill check | no contracts; costs through the [tenancy](mieter.md), for the heat pump the seasonal performance factor |

**Rule of thumb:** if you have a contract with the heat supplier yourself,
create **District heating**. Otherwise it is **Heat**. That holds even if your
rented building is on a district heating network: the landlord has the
contract, for you it is heat. Under Settings → Household & building → “Heat
comes from” you then choose “District heating” — it only counts for the CO₂
approximation ([Heat §5](../verstehen/15-waerme.md#5-co₂--an-approximation)).
The same goes for an owner-occupied flat where the owners’ association has the
contract and bills the heat through the service charge.

Three sample households show the cases
([load one](#sample-households-to-look-at-v320)):

**1. “I rent my home” — heat from the consumption information.** A rented flat
of 62 m²; the central heating (gas) in the basement belongs to the landlord.

- `strom` *“Flat electricity meter”* with its own electricity contract — the
  only supply contract of its own.
- `waerme` *“Heating per monthly consumption info”* with the recording
  **“Consumption per period”**: per month the value from the monthly
  consumption information of the metering service, plus the comparison values.
  It has to come monthly once the devices can be read remotely (German Heating
  Costs Ordinance, HeizkostenV § 6a, since 2022).
- `wasser` *“Cold water meter”* and *“Hot water meter”* (role “Hot water”).
- A **tenancy** with prepayments for heating and operating costs and the
  statements for 2024 and 2025.

```text
Consumption information April 2026 (made up):
  heat 297 kWh · previous month 679 kWh · same month last year 316 kWh · average user 341 kWh
Service charge statement 2025: heat 4,668 kWh, heating costs €649.79
```

Step by step: [As a tenant](mieter.md#3-create-a-meter-for-the-consumption-information).

**2. “Owner-occupied flat with district heating” — a supply contract of your
own.** 85 m², a flat station in the hallway; the readings come from the
supplier’s customer portal.

- `fernwaerme` *“Heat meter”* with the contract *“District Heating Home”*: unit
  price, standing charge, connected load, capacity and metering charge, network
  CO₂ factor, advance payment.
- `strom` *“Flat electricity meter”* with its own electricity contract.

```text
Fixed costs per month from April 2025 (made up):
  standing charge €4.20 + 6.5 kW × €57.60/(kW·a) / 12 + metering charge €96/a / 12
  = €4.20 + €31.20 + €8.00 = €43.40
Unit price from 2026: 12.5 ct/kWh · network CO₂ factor: 198 g/kWh
```

The contract dialog shows the fields for connected load, capacity and metering
charge at the level “Expert”; the app calculates with them at every level
([District heating](../verstehen/04-fernwaerme.md#fixed-costs-capacity-and-metering-charge-v310)).

**3. “Own house with heat pump and solar” — heat as the heat pump’s output.**
There is no heat bill at all here; what you pay for is the heat pump’s
electricity. You create the heat meter so that the app can calculate the
seasonal performance factor.

- `strom` *“Heat pump”*: a meter of its own with the role “Heat pump (heating
  electricity)” and a contract of its own, *“Heat Pump Electricity”*, holding
  the reduced grid fee under § 14a EnWG (module 1).
- `waerme` *“Heat pump heat meter”* with the role “Heat pump output”, linked to
  the electricity meter *“Heat pump”*.

```text
Card “Heat pump 2025” (made up):
  12,953 kWh heat / 3,896 kWh electricity = seasonal performance factor 3.3 · heating season 3.4
```

The card appears at the level “Expert”
([Heat §7](../verstehen/15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310)).

> **Not twice.** The heat pump output counts neither in the sums of heat nor in
> the efficiency figure — the heat pump is already there with its electricity
> ([Heat §4](../verstehen/15-waerme.md#4-efficiency-figure)).

---

## G — Control with evcc or Home Assistant — check the numbers in Energietracker

**Situation.** An energy consultant asks for a “controller”: it should charge
the home battery and the car when electricity on the exchange is cheap or even
negative, and use the car bidirectionally as a battery for the house.

**The stance.** Energietracker controls nothing. It switches no wall box, no
battery and no heat pump. Tools built for that do it:

- **evcc** charges the car with solar surplus or, with a dynamic tariff, in the
  hours below a price limit
  ([evcc: Dynamic tariffs](https://docs.evcc.io/en/features/dynamic-prices/));
  if the inverter supports it, evcc also charges the home battery from the grid
  in cheap hours ([evcc: Home battery](https://docs.evcc.io/en/features/battery/)).
- **Home Assistant** switches whatever it can reach with automations — for
  example by the wholesale price of the coming hours.

Energietracker **checks the numbers** of what came out of it:

| Question | In Energietracker | Where the values come from |
|---|---|---|
| How much did the car charge, how much of it from the sun, at what price? | wall box → card “Charging sessions from evcc” (from level “Experienced”) | CSV export from evcc or “Fetch from evcc” ([Charging sessions from evcc](evcc.md)) |
| What does the company car’s charging electricity cost? | wall box → card “Charging record (company car)” | readings from evcc, Home Assistant or by hand ([use case E](#e--wall-box-and-company-car)) |
| Would a dynamic tariff have paid off? | Costs & contracts → Tariff switch → “Wholesale electricity prices” and an offer “Dynamic tariff” | wholesale prices from SMARD, only when you tap it ([Electricity → Dynamic tariffs](../verstehen/02-strom.md#dynamic-tariffs-v310)) |
| How well does the heat pump work, what does § 14a module 1 bring? | card “Heat pump {year}”; in the contract “Reduced grid fee (§ 14a EnWG, module 1)” | a meter of its own and a heat meter ([use case C](#c--pv-household-with-a-heat-pump)) |
| What does the battery lose? | PV generation → card “Battery {year}” | charging and discharging meters ([PV §7](../verstehen/12-pv.md#7-battery-v310)) |
| All readings without typing | every utility | Home Assistant sends them in the evening via `POST /api/ingest` ([Home Assistant](home-assistant.md)) |

The app shows the charging record, the heat pump card and the wholesale prices
at the level “Expert”.

**In the sample household “Own house with heat pump and solar”:**

```text
Charging sessions from evcc 2025 (made up): 152 sessions, 2,618 kWh,
  about 27 % of it from the sun (April to September almost 50 %, October to March 9 to 14 %),
  price according to evcc €629 in total
Offer “Electricity Dynamic” for comparison: mark-up 19.4 ct/kWh, standing charge €11.90/month
```

The sample household brings no wholesale prices; in your installation you fetch
them with “Load from SMARD”.

**What Energietracker cannot do (as of v3.2.0).**

- It calculates in days and months, not in quarter hours. Whether the car
  charged in the cheap hours shows only in the price according to evcc.
- The dynamic tariff check uses the monthly wholesale average. Whoever shifts
  consumption into cheap hours really pays less than the check shows.
- It does not calculate § 14a module 3, the time-variable grid fees.
- It does not record electricity that the car gives back (V2H, V2G); the
  charging sessions from evcc count only what was charged.
- It does not count hours with a negative wholesale price. For new PV systems it
  only points out the rule
  ([PV §10](../verstehen/12-pv.md#10-negative-wholesale-prices-v310)).

**Planned: “Assess flexibility” (v3.3.0, no date yet).** Energietracker is to
store values per quarter hour — from portal files, from Home Assistant (ingest
with a timestamp) and wholesale prices from SMARD, only when you tap it — and
show with them:

- **Success of shifting:** kWh in cheap and negative hours, your own average
  price against the wholesale average, per month and per controllable meter
  (wall box, heat pump, battery).
- **Dynamic tariff check with your real load profile** instead of the monthly
  average.
- **Battery by the hour:** what charging at low and discharging at high prices
  brings.
- **§ 14a module 3:** the saving from shifting into the grid operator’s
  low-tariff time windows.

Control stays outside even then.

### The legal situation in brief (as of 9 October 2026)

Not legal advice — what counts are the law, the regulator’s determination and
your grid operator’s price sheets. The rules are German.

- **§ 14a EnWG — controllable consumer devices.** Since 1 January 2024 two
  determinations of the Federal Network Agency (Bundesnetzagentur) apply — on
  control (ruling chamber 6, BK6-22-300) and on grid fees (BK8-22/010-A) —, for
  example to heat pumps and private wall boxes: the grid operator
  may throttle them during bottlenecks, and in return the grid fee drops.
  **Module 1** is a flat reduction per year. **Module 2** lowers the grid-fee
  unit price to 40 % with a meter of its own. **Module 3** is a time-variable
  grid fee in three stages (high load, standard, low load) — only on top of
  module 1 and with a smart metering system. Grid operators have had to offer
  and bill module 3 since 1 April 2025; in May 2026 the Federal Network Agency
  found that in many places this happens not at all or only inadequately, and
  threatened two grid operators with coercive fines (deadline 30.09.2026).
  Sources:
  [Bundesnetzagentur, 27.11.2023](https://www.bundesnetzagentur.de/SharedDocs/Pressemitteilungen/DE/2023/20231127_14a.html),
  [Bundesnetzagentur, grid fee reduction](https://www.bundesnetzagentur.de/DE/Vportal/Energie/SteuerbareVBE/Netzentgelt_table.html),
  [Bundesnetzagentur, 28.05.2026](https://www.bundesnetzagentur.de/1108084)
  (all in German).
- **Negative prices — the “solar peak act”.** The act to avoid temporary
  generation surpluses (BGBl. 2025 I Nr. 51, promulgated on 24.02.2025) applies
  to PV systems commissioned **from 25.02.2025**. In periods with a negative
  wholesale price their remuneration drops to zero (§ 51 EEG) — for systems
  below 100 kW from the calendar year after a smart metering system is
  installed. In return the support period gets longer (§ 51a EEG). Until a
  smart metering system is installed and control has been tested, new systems
  below 100 kW with a feed-in tariff may feed in at most 60 % of their installed
  capacity (§ 9 (2) EEG; plug-in solar devices up to 2 kW excepted). Older
  systems stay under the old rules (§ 100 (3b) and (46) EEG) — including the 2023
  system in the sample household. Sources:
  [BGBl. 2025 I Nr. 51](https://www.recht.bund.de/bgbl/1/2025/51/VO.html),
  [§ 51 EEG](https://www.gesetze-im-internet.de/eeg_2014/__51.html),
  [§ 51a EEG](https://www.gesetze-im-internet.de/eeg_2014/__51a.html),
  [§ 9 EEG](https://www.gesetze-im-internet.de/eeg_2014/__9.html),
  [§ 100 EEG](https://www.gesetze-im-internet.de/eeg_2014/__100.html)
  (all in German).
- **MiSpeL — batteries with grid and solar power.** On 1 October 2026 the
  Federal Network Agency adopted its determination on the market integration of
  storage and charging points (file no. 618-25-02). Batteries and bidirectional
  charging points may now store grid and solar power mixed and feed it back
  without losing the EEG support. Two routes: the **demarcation option** (a
  meter of its own, recording per quarter hour, open to all) and the **flat-rate
  option** for solar systems up to 30 kWp (one quarter-hour meter at the house
  connection is enough). In both, the only support is the market premium, i.e.
  direct marketing; with a fixed feed-in tariff — as in the sample household —
  the “exclusivity option” remains, and the battery may then store only
  electricity from your own system to keep the support. Until 30.09.2027 the
  determination applies only with the consent of grid operator and metering
  operator; the flat-rate option also awaits state-aid approval from the
  European Commission. Sources:
  [Bundesnetzagentur, 01.10.2026](https://www.bundesnetzagentur.de/SharedDocs/Pressemitteilungen/DE/2026/20261001_Mispel.html),
  [MiSpeL procedure page](https://www.bundesnetzagentur.de/DE/Fachthemen/ElektrizitaetundGas/ErneuerbareEnergien/EEG_Aufsicht/MiSpeL/start.html),
  [background paper](https://www.bundesnetzagentur.de/DE/Fachthemen/ElektrizitaetundGas/ErneuerbareEnergien/EEG_Aufsicht/MiSpeL/DL/Hintergrundpapier.pdf?__blob=publicationFile&v=3),
  [determination](https://www.bundesnetzagentur.de/DE/Fachthemen/ElektrizitaetundGas/ErneuerbareEnergien/EEG_Aufsicht/MiSpeL/DL/MiSpeL_TenorMitBegruendung.pdf?__blob=publicationFile&v=2)
  (operative points 5 and 9; all in German).
- **Bidirectional charging.** Settled: for the grid fee exemption of storage,
  § 118 (6) sentence 3 EnWG refers to § 21 EnFG, and that section puts charging
  points for electric cars on a par with storage. For grid power the car later
  gives back to the grid, levies and grid fees therefore do not apply; for the
  driving power they still do. The quantities must be recorded in line with
  metering and calibration law; MiSpeL sets out how — according to the Federal
  Network Agency, V2H and V2G then work “even without a second meter”. Open: the
  transition period until 30.09.2027 and the approval of the flat-rate option
  (see MiSpeL). What grid fees for storage and bidirectional charging points
  will look like in future is being settled by the Federal Network Agency in
  its ongoing procedure on the general grid fee system (AgNes). The exemption
  under § 118 (6) EnWG only covers storage commissioned by 04.08.2029, for 20
  years and only for the grid access charges. **Electricity tax:** whoever
  feeds power from the car back during bidirectional charging is not treated as
  a supplier for that; if it is used at the charging point without the public
  grid (V2H), no electricity tax arises (§ 5a (3) StromStG). For V2G into the
  grid the provision does not say so. Sources:
  [§ 118 EnWG](https://www.gesetze-im-internet.de/enwg_2005/__118.html),
  [§ 21 EnFG](https://www.gesetze-im-internet.de/enfg/__21.html),
  [§ 5a StromStG](https://www.gesetze-im-internet.de/stromstg/__5a.html), background
  paper and determination (reasons, section 3.2.2.2) as above.

---

## Which use case fits me?

| If you say … | Use case | To look at ([load](#sample-households-to-look-at-v320)) |
|---|---|---|
| “I type values myself but want order with main/sub-meters.” | A | “Own house with gas, oil or pellets” (garden meter as a sub-meter) |
| “I have Home Assistant and never want to type again.” | B | — |
| “I have PV (+ a heat pump).” | C | “Own house with heat pump and solar” |
| “I manage several residential units.” | D | — |
| “I charge a company car at home.” | E | “Own house with heat pump and solar” |
| “I rent; my heating comes with the consumption information.” | F | “I rent my home” |
| “I have a district heating contract.” | F | “Owner-occupied flat with district heating” |
| “I heat with gas, oil or pellets.” | [Getting started](../einstieg/erste-schritte.md) | “Own house with gas, oil or pellets” |
| “evcc or Home Assistant control my car, battery or heat pump.” | G (+ B) | “Own house with heat pump and solar” |
| “I just want to see everything first.” | — | “Just look at everything first” (all nine utilities) |

All cases can be combined — e.g. a landlord (D) with an HA push (B) per unit.
To get started: [Getting started](../einstieg/erste-schritte.md).

---

[← Compendium index](../README.md)
