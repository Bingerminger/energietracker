# Valuing flexibility

**English** · [Deutsch](../../anleitungen/flexibilitaet.md)

[← Compendium index](../README.md)

The heat pump runs at night, the car charges at noon, the battery discharges in
the evening: whoever shifts consumption wants to know whether it paid off.
Since **v3.3.0** the Energietracker checks this per quarter hour — with your
consumption and the wholesale electricity price of the same quarter hour. The
page **Insights → Flexibility** shows per electricity meter how much ran in
cheap quarter hours and what wholesale price you paid on average, plus what the
battery and the time-variable grid fee under § 14a module 3 brought.

> **The Energietracker controls nothing — it checks the numbers.** Control
> happens elsewhere: evcc charges the car, Home Assistant switches the heat
> pump, the battery manages itself. How this fits together is shown in the
> [use cases](anwendungsfaelle.md#g--control-with-evcc-or-home-assistant--check-the-numbers-in-energietracker).

Only the **wholesale share** of the electricity price is calculated — without
taxes, levies, grid fee and the supplier’s mark-up. That is the part that
depends on when you consume (exception: the time-variable grid fee, § 6). It
only turns into real money with a dynamic tariff billed per quarter hour; with
a fixed unit price the page shows what such a tariff would have brought with
your consumption pattern.

---

## 1. What you need

- The level **“Expert”** — only then does “Flexibility” appear under Insights
  ([experience levels](../einstieg/einrichtung.md#3-experience-levels)).
- **Consumption per quarter hour** for at least one electricity meter (§ 2). It
  tells the most for a meter of its own for the heat pump or the wall box.
- **Wholesale prices per quarter hour** for the same year, from SMARD (§ 3).
- For the **battery**, two meters under PV generation with the roles
  “Battery – charging” and “Battery – discharging”, both with quarter hours
  (§ 5).
- For **§ 14a module 3**, the time windows on the electricity contract (§ 6).
  Module 3 does not need wholesale prices.

The calculation runs per calendar year in the time zone of the installation
(Settings → General → Time zone). A year has 35,040 quarter hours, a leap year
35,136; the days of the clock change have 92 or 100.

---

## 2. Consumption per quarter hour

Two ways, also side by side. A new value replaces an existing one in the same
quarter hour.

### From a portal file

Import the load profile from the grid or metering operator, the inverter or the
heat pump with **“Import time series”**
([Import time series from portals](daten-aus-portalen.md)). If the file holds
values in 15-, 30- or 60-minute steps, the Energietracker keeps them per
quarter hour in addition to the daily values:

- The step is the most frequent gap between two time stamps.
- **Consumption per interval** is spread evenly over its quarter hours — an
  hourly value over four, a half-hourly value over two.
- **Meter readings** give the difference between two consecutive readings. If a
  stamp is missing in between or the reading drops, the gap stays empty.
- **Daily values** give no quarter hours.

The preview does not show the quarter hours separately. After “Import” the
series appears in the list “Quarter-hour series” (§ 8).

### From Home Assistant, every 15 minutes

Home Assistant sends the meter reading **with a timestamp**: since v3.3.0 the
REST command template contains the line
`"timestamp": "{{ now().isoformat() }}"`
([Home Assistant, step 3](home-assistant.md#step-3--rest-command-in-home-assistant)).
The Energietracker spreads the difference to the previous reading over the
quarter hours in between, by share of time. To get quarter hours out of it, a
second automation sends every 15 minutes; the daily one from step 4 stays as it
is. For a heat pump and a wall box:

```yaml
alias: "Energy: heat pump and wall box per quarter hour"
description: "Sends the meter readings with a timestamp every 15 minutes"
triggers:
  - trigger: time_pattern
    minutes: "/15"
actions:
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.waermepumpe_strom_total_kwh') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "strom"
          meter: "strom_waermepumpe"
          sensor_entity: "sensor.waermepumpe_strom_total_kwh"
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.wallbox_total_kwh') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "strom"
          meter: "strom_wallbox"
          sensor_entity: "sensor.wallbox_total_kwh"
mode: single
```

`minutes: "/15"` fires at every full quarter hour (:00, :15, :30, :45). Each
push still updates the day’s reading; the readings file keeps growing by one
entry per day. The response states in `intervals` how many quarter hours the
reading touched.

**Nothing is spread** for

- the first reading with a timestamp — it is only the start;
- a reading that is not newer than the previous one (sent twice or backfilled);
- a reading lower than the previous one
  ([falling values](home-assistant.md#falling-values-since-v260));
- a gap of more than six hours, for example after an outage. The new reading
  then counts as a new start; the gap stays empty. A portal file can fill it.

That is why the daily push alone creates no quarter hours: there are more than
six hours between two evenings.

---

## 3. Wholesale prices per quarter hour from SMARD

At the top of the page: **“Load wholesale prices {year} from SMARD”**. The
server fetches the day-ahead prices for Germany/Luxembourg (DE-LU) of the
selected year from SMARD, the electricity market platform of the Federal
Network Agency (Bundesnetzagentur), week by week, and converts €/MWh into
ct/kWh. That takes a moment; then the app reports e.g. “Wholesale prices 2025
loaded: 35,040 quarter hours.”

- **Only when you tap it**, per year. Only the request to SMARD goes out, none
  of your data.
- **Current year:** what SMARD has published so far is loaded. Loading again
  later adds the rest; existing values are replaced.
- **Before 1 October 2025** SMARD provides hourly prices: then all four quarter
  hours of an hour carry the same price.
- The monthly averages of the card “Wholesale electricity prices” under Tariff
  switch are a list of their own; this button does not fill them.
- Source: Bundesnetzagentur | SMARD.de, licence CC BY 4.0 — the page names it
  below the figures.

---

## 4. The page card by card

**Insights → Flexibility**, at the top the year: the current one and the three
before it, the previous year is preselected. If something is missing, a notice
says what:

- “The wholesale prices per quarter hour for {year} are missing — load them
  from SMARD above.” Without prices the page shows no meter cards.
- “There is no consumption per quarter hour for {year} yet. …” — § 2.

### Per electricity meter

One card per electricity meter with quarter hours in the selected year. Meters
with the role “Heat pump (heating electricity)” or “Wallbox (EV charger)” carry
the tag **“controllable”** and come first; the others — such as the house
connection — follow for comparison.

| Figure | Meaning |
|---|---|
| **Consumption with price** | kWh in quarter hours that have both consumption and a wholesale price — the calculation runs only over these |
| **In cheap quarter hours** | the share of it in quarter hours whose price is **below the average of their month** |
| **At negative prices** | kWh in quarter hours with a wholesale price below zero |
| **Own avg. / market avg.** | your wholesale price per kWh, weighted by consumption, against the market average |
| **Profile factor** ⓘ | own avg. divided by market avg. |
| **Advantage (wholesale share)** | what your consumption profile saved (positive) or cost (negative) against the market average |

```text
cheap            price of the quarter hour < average of all quarter hours of its month
own avg.         = Σ (kWh × price) ÷ Σ kWh
market avg.      = average of all quarter hours of the month
                   for the year: Σ (market avg. of the month × kWh of the month) ÷ Σ kWh
profile factor   = own avg. ÷ market avg.
advantage in €   = (market avg. − own avg.) × kWh ÷ 100
```

All sums run over the quarter hours with consumption and price.

- **Profile factor below 1:** you tend to consume when electricity is cheap.
  **Above 1:** when it is expensive. If the market average is close to zero
  (below 0.01 ct), the profile factor stays empty.
- **Only within the month.** The market average of the year is weighted by your
  consumption per month. So what counts is when you consume within a month —
  that a heat pump runs more in winter, when electricity is usually more
  expensive, does not enter.

Below it a sentence: “Cheaper than average: … saved on the wholesale share” or
“More expensive than average: … more on the wholesale share”, with the profile
factor.

**“Per month”** opens a table: month, kWh (of all quarter hours with
consumption), cheap, negative, own avg., market avg., advantage and
**coverage** — the share of the month’s quarter hours with consumption and
price.

### Battery and module 3

The cards “Battery by wholesale price” (§ 5) and “§ 14a module 3 — {meter}”
(§ 6) appear when the data for them exists.

### Quarter-hour series

At the bottom the list of all series, of all years (§ 8).

---

## 5. Battery

The card **“Battery by wholesale price”** needs two meters under **PV
generation** with the roles “Battery – charging” and “Battery – discharging”
([PV §7](../verstehen/12-pv.md#7-battery-v310)), both with quarter hours, and
the wholesale prices of the year.

| Figure | Meaning |
|---|---|
| **Charged · avg. price** | kWh charged and the wholesale price while charging, weighted by quantity |
| **Discharged · avg. price** | kWh discharged and the wholesale price while discharging |
| **Value of shifting** | what shifting across the day was worth at the wholesale price |

```text
avg. charging price     = Σ (charged × price) ÷ Σ charged
avg. discharging price  = Σ (discharged × price) ÷ Σ discharged
value in €              = (Σ discharged × price − Σ charged × price) ÷ 100
```

Every kWh counts at the wholesale price of its quarter hour: when charging,
what the electricity was worth on the exchange at that moment; when
discharging, what it was worth then. The battery loss is included — less comes
out than goes in. Not included are the benefit of self-consumption against the
purchase price and the grid fee. Losses, efficiency and full cycles are shown
by the card “Battery {year}” under PV generation.

---

## 6. § 14a module 3: time-variable grid fee

### The legal situation in brief (as of 9 October 2026)

Not legal advice — what counts are the law, the regulator’s determination and
your grid operator’s price sheets. The rules are German.

**Module 3** is a time-variable grid fee for controllable consumer devices
under § 14a EnWG, in three stages (high load, standard, low load) — only on top
of module 1 and with a smart metering system. It rests on two determinations
of the Federal Network Agency (Bundesnetzagentur), on control (ruling
chamber 6, BK6-22-300) and on grid fees (BK8-22/010-A). Grid operators have had
to offer and bill module 3 since 1 April 2025; in May 2026 the Federal Network
Agency found that in many places this happens not at all or only inadequately.
Sources:
[Bundesnetzagentur, 27.11.2023](https://www.bundesnetzagentur.de/SharedDocs/Pressemitteilungen/DE/2023/20231127_14a.html),
[Bundesnetzagentur, grid fee reduction](https://www.bundesnetzagentur.de/DE/Vportal/Energie/SteuerbareVBE/Netzentgelt_table.html),
[Bundesnetzagentur, 28.05.2026](https://www.bundesnetzagentur.de/1108084)
(all in German). More, also on modules 1 and 2, in the
[use cases](anwendungsfaelle.md#the-legal-situation-in-brief-as-of-9-october-2026).

### Enter the time windows on the contract

**Consumption → Electricity → “⚙️ Manage contracts”**, edit the contract of
the meter whose quarter hours you have — for example the heat pump’s. In the
field group **“§ 14a module 3: time-variable grid fee”** (level “Expert”):

1. **“Standard tariff (ct/kWh)”** — the grid fee per kWh outside the time
   windows.
2. **“+ Time window”** per line in the price sheet: the **quarters** (Q1 =
   January to March … Q4 = October to December), **“From”**, **“Until (HH:MM,
   also 24:00)”** and **“ct/kWh in the window”**.
3. Save.

A window across midnight is two windows: 22:00 to 24:00 and 00:00 to 06:00. At
most 24 windows; if two overlap, the first one applies. The app calculates with
the amounts you enter — net from the price sheet or including VAT, your choice.
If the standard tariff and all windows are empty, module 3 is removed from this
contract again.

Example, as in the sample household (§ 9; made-up prices):

| Quarters | From | Until | ct/kWh |
|---|---|---|---|
| Standard tariff | | | 8.1 |
| Q1, Q2, Q3, Q4 | 00:00 | 06:00 | 2.4 |
| Q1, Q4 | 17:00 | 20:00 | 11.9 |

### What the card shows

The card **“§ 14a module 3 — {meter}”** appears for every electricity meter
with quarter hours whose contract has time windows:

```text
grid fee of a quarter hour = ct of the first window matching quarter and time of day,
                             otherwise the standard tariff
in the low / high / standard tariff = kWh with a grid fee below / above / equal to standard
saving vs. standard in €   = Σ kWh × (standard tariff − grid fee of the quarter hour) ÷ 100
```

Each month uses the contract running on the first of the month. Consumption in
the high tariff lowers the saving; it can also turn negative.

**Only here.** The time windows do not change the contract’s costs: balance,
forecast and bill check keep calculating with the unit price you enter. The
saving appears on this card alone.

---

## 7. Dynamic tariff check with your own load profile

Under **Costs & contracts → Tariff switch** (electricity), **“Looking back at
real months”** used to calculate an offer with the tick “Dynamic tariff
(wholesale price per month)” with the monthly wholesale average
([Electricity → Dynamic tariffs](../verstehen/02-strom.md#dynamic-tariffs-v310)).
Since v3.3.0 it takes your own load profile for months with enough quarter
hours:

```text
wholesale price of the month = Σ (kWh × price) ÷ Σ kWh   if at least 90 % of the month’s
                                                          quarter hours have consumption and price
                             = monthly average            otherwise
unit price                   = wholesale price × (1 + VAT) + mark-up
```

- The load profile must come from the **meter** you are comparing — usually the
  house connection. Quarter hours of a sub-meter do not count there.
- Below the offer it then says “Wholesale price with your quarter-hour load
  profile in {N} months, otherwise the monthly average.”
- For the remaining months the monthly averages of the card “Wholesale
  electricity prices” are still needed.
- The ranking for switching ahead keeps using monthly averages: there is no
  load profile for future months.

---

## 8. Managing quarter-hour series

The card **“Quarter-hour series”** at the bottom of the page lists every
series: utility · meter ID or “Wholesale prices DE-LU”, year, coverage (share
of quarter hours with a value) and source — “File”, “Home Assistant” or
“SMARD”. 🗑️ **“Delete series”** with the question “Delete this series? Meter
readings and daily values stay.” removes only the quarter hours. Importing or
loading again creates the series anew.

**Storage.** One file per series and year:

```text
data/intervals.json                                  index of all series
data/intervals/e-strom-m_strom_waermepumpe-2025.json consumption: e-<utility>-<meter ID>-<year>
data/intervals/p-de-lu-2025.json                     wholesale prices DE-LU: p-de-lu-<year>
```

Each series has one slot per quarter hour of the year; slot i starts
i × 15 minutes after 1 January, 00:00 local time. Empty slots are `null`.
Consumption is in kWh per quarter hour, prices in ct/kWh. Only what is in the
index counts.

**Data volume.** A full series for a whole year takes about 250 KB (the file is
written compactly). The sample household has eight series for 2025, about
1.5 MB together; loading it adds the last two to three months up to yesterday
(§ 9).

**Backup.** The backup contains all series, also a backup without receipts. If
it brings an index of the series, restoring drops series that are not in it —
after switching the sample household, no series of the previous one stay
behind.

---

## 9. To look at: the sample household

The sample household **“Own house with heat pump and solar”** has quarter
hours for 2025: the household (grid import), the controlled heat pump (it
prefers to run between 0 and 6 o’clock and avoids 17 to 20 o’clock), the wall
box from its charging sessions, since v3.5.0 also the solar generation and
feed-in, and the battery — so that every quarter hour adds up on its own
(generation = used directly + fed in + charged; the battery charges from the
surplus and discharges into the house in the evening). Plus the real wholesale
prices 2025 per quarter hour from SMARD, and on the heat pump’s contract the
module 3 windows from § 6. When loading, the app carries the last two to three
months up to yesterday forward — day by day from the same calendar day a year
earlier, the wholesale prices too; the day film shows them. The consumption is
made up.

To load it: Settings → General → **“Start the setup assistant”**, “Own house
with heat pump and solar”, choose the level **“Expert”**, at the end “Look at
it with sample data”
([sample households](anwendungsfaelle.md#sample-households-to-look-at-v320)).
This replaces all data of the installation; the app takes a snapshot first.
Then **Insights → Flexibility**, year 2025:

```text
Meter                       with price  cheap  negative  own avg.  market avg.  profile factor  advantage
Heat pump (controllable)     3,891 kWh   55 %   112 kWh   9.60 ct    10.08 ct        0.95          €18.78
Wallbox (controllable)       2,618 kWh   48 %   469 kWh   9.41 ct     9.07 ct        1.04          −€9.01
Household (grid import)      2,862 kWh   40 %   303 kWh  10.79 ct     9.41 ct        1.15         −€39.46

Battery      1,695 kWh charged at avg. 8.11 ct, 1,556 kWh discharged at avg. 11.61 ct
             value = (1,555.91 × 11.612 − 1,694.70 × 8.114) ÷ 100 ≈ €43.15
Module 3 (heat pump)
             1,512 kWh in the low tariff, 129 kWh in the high tariff, 2,250 kWh in the standard tariff
             saving = (1,511.79 × (8.1 − 2.4) + 129.41 × (8.1 − 11.9)) ÷ 100 ≈ €81.25
```

Thanks to its control the heat pump consumes at about 5 % below the market
average — €18.78 on the wholesale share. The wall box charges a lot at negative
prices and still above the average; the household draws from the grid, as
usual, in the evening, when electricity is more expensive — at midday the sun
covers it. The battery charges at midday, when wholesale power is cheap, and
discharges in the evening.

Looking back under Tariff switch, the offer “Electricity Dynamic” for the meter
“Household (grid import)” calculates with its own load profile in twelve months
of 2025.

---

## 10. When something goes wrong

| Message | Cause and fix |
|---|---|
| “The wholesale prices per quarter hour for {year} are missing — load them from SMARD above.” | No prices are loaded for the year (§ 3). |
| “There is no consumption per quarter hour for {year} yet. …” | No electricity meter has quarter hours in this year (§ 2). |
| “No quarter hour with both consumption and wholesale price.” | Consumption and prices do not overlap — for example prices only up to a day, consumption only after it. Load the prices again. |
| “SMARD could not be reached or returned no values — …” | SMARD does not answer or has nothing for the year (response `502`). Try again later. The app does not accept a file with quarter-hour prices. |
| “Module 3 time windows invalid (…): …” | When saving the contract: no quarter ticked, a time not in the format HH:MM, “From” not before “Until” (across midnight: two windows), a price outside 0 to 100 ct, no standard tariff or more than 24 windows. The brackets name the field, e.g. `windows[0]` for the first window. |
| “Timestamp “…” not readable — expected ISO 8601 with time zone, …” | A push from Home Assistant with a `timestamp` without a time zone or in another format (response `400`). The template `{{ now().isoformat() }}` fits. |
| Quarter hours from Home Assistant stay empty | The line `"timestamp"` is missing in the REST command (template before v3.3.0), only the daily push runs, or one of the cases from § 2 “Nothing is spread”. |
| “Series “…” not found.” | The series has already been deleted (response `404`). |
| “Invalid year: …” | Via the API, a year outside 2018 to 2100 (response `400`). |
| “Flexibility” is missing under Insights | The level is not “Expert”. |
| The card “Battery by wholesale price” is missing | One of the two battery meters under PV generation, its quarter hours or the wholesale prices are missing. |
| The card “§ 14a module 3” is missing | The contract running on the first of the month has no time windows, or they sit on another meter than the one with the quarter hours. |

---

## 11. Via the API

```text
GET    /api/flexibility?year=2025                         → {year, prices, meters, battery, module3}
GET    /api/intervals                                     → {series: [...]}
DELETE /api/intervals/{id}                                → {deleted: true}
POST   /api/market-prices/sync-smard-intervals?year=2025  → {year, slots, from, to}
POST   /api/market-prices/sync-smard-intervals?year=2025&month=3  → March only (v3.5.1)
```

Without `year` the previous year applies. Quarter hours also arrive via
`POST /api/utility/{u}/meters/{id}/import-series` (new in the response:
`intervals: {step, slots}`, `null` for daily values) and `POST /api/ingest`
with `timestamp` (new in the response: `intervals`, the number of quarter hours
touched). A batch processes entries with a timestamp per meter in time order. On
the electricity contract the field is called `grid_tou`:

```json
{ "grid_tou": { "standard_ct": 8.1,
                "windows": [ { "quarters": [1, 2, 3, 4], "from": "00:00", "to": "06:00", "ct": 2.4 },
                             { "quarters": [1, 4], "from": "17:00", "to": "20:00", "ct": 11.9 } ] } }
```

All fields and error codes: [API reference](../referenz/api.md); the storage in
the [data model](../referenz/datenmodell.md).

---

[← Compendium index](../README.md)
