# Valuing flexibility

**English** · [Deutsch](../../anleitungen/flexibilitaet.md)

[← Compendium index](../README.md)

The heat pump runs at night, the car charges at noon, the battery discharges in
the evening: whoever shifts consumption wants to know whether it paid off — and
when it pays off. Since **v3.3.0** the Energietracker checks this per quarter
hour, with your consumption and the wholesale electricity price of the same
quarter hour. Since **v3.6.0** the page **Insights → Flexibility** also guides
you: a traffic light shows when power is cheap or expensive and when the sun
shines, “Your lever” calculates in euros what shifting can bring per appliance
in a year, and clear sentences say when to run what. The big number comes as an
image to pass on, as a page in the annual report and as the “Your lever” tile
on the overview.

> **The Energietracker controls nothing — it checks the numbers.** Control
> happens elsewhere: evcc charges the car, Home Assistant switches the heat
> pump, the battery manages itself. How this fits together is shown in the
> [use cases](anwendungsfaelle.md#g--control-with-evcc-or-home-assistant--check-the-numbers-in-energietracker).

**Three price signals, two kinds of money.** The wholesale price per quarter
hour counts only the **wholesale share** of the electricity price — without
levies, grid fee and the supplier’s mark-up. It only turns into real money with
a dynamic tariff billed per quarter hour; the page therefore says “with a
dynamic tariff”. Since v3.6.0 the euro amounts based on the wholesale price
include **VAT**, the way a dynamic tariff bills it: the rate of your dynamic
offer under Check a switch, without an offer the standard German rate of 19 %
(§ 12(1) UStG). On the contract as it is, two other signals work: the
**time-variable grid fee** under § 14a module 3 (§ 6) and **solar power** —
every kWh that comes from your own system instead of the grid saves the unit
price (as on the bill, including VAT) and only costs the feed-in tariff that
would otherwise have been paid. These amounts say “on your tariff”. Page,
report and tile therefore say “incl. VAT”.

---

## 1. What you need

- **Any level.** Since v3.6.0 “Flexibility” is under Insights for everyone (up
  to v3.5.0 only for “Expert”). **Beginners** see the result card, the action
  sentences and the typical day of the traffic light, from **“Experienced”**
  also the week, the best time windows, “Using solar power at home” and “Your
  lever”, from **“Expert”** the check per meter, the battery, module 3 and the
  quarter-hour series
  ([experience levels](../einstieg/einrichtung.md#3-experience-levels)).
- For the **traffic light** the **wholesale prices per quarter hour** of the
  year from SMARD (§ 3) are enough — or, without them, the module 3 time
  windows on the electricity contract (§ 6).
- **Consumption per quarter hour** for at least one electricity meter (§ 2):
  with it the page checks what your shifting achieved, and “Your lever” works
  with measured values. It tells the most for a meter of its own for the heat
  pump or the wall box (role on the meter).
- For **“Using solar power at home”** generation and feed-in per quarter hour
  (PV generation, PV feed-in).
- For the **battery**, two meters under PV generation with the roles
  “Battery – charging” and “Battery – discharging”, both with quarter hours
  (§ 5).
- For **§ 14a module 3**, the time windows on the electricity contract (§ 6).
  Module 3 does not need wholesale prices.
- For the **car’s charging windows**, the charging sessions from evcc on the
  wall box meter ([evcc](evcc.md)): a session runs from plugging in to
  unplugging. Without them the page assumes the car is available all day on
  charging days and marks this as a sample value (§ 4, “Your lever”).

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

At the top of the page: **“Load wholesale prices {year} from SMARD”** (if they
are missing, the same button also sits in the invitation, § 4). The server
fetches the day-ahead prices for Germany/Luxembourg (DE-LU) of the selected
year from SMARD, the electricity market platform of the Federal Network Agency
(Bundesnetzagentur), week by week, and converts €/MWh into ct/kWh. That takes a
moment; then the app reports e.g. “Wholesale prices 2025 loaded: 35,040 quarter
hours.”

- **Only when you tap it**, per year. Only the request to SMARD goes out, none
  of your data.
- **Current year:** what SMARD has published so far is loaded. Loading again
  later adds the rest; existing values are replaced.
- **Before 1 October 2025** SMARD provides hourly prices: then all four quarter
  hours of an hour carry the same price.
- The monthly averages of the card “Wholesale electricity prices” under Tariff
  switch are a list of their own; this button does not fill them.
- Source: Bundesnetzagentur | SMARD.de, licence CC BY 4.0 — the page names it
  below the figures and in the footer of the result card.

---

## 4. The page card by card

**Insights → Flexibility**, at the top the year: the current one and the three
before it, the previous year is preselected. The server calculates every
number; the page only formats and chooses which season the traffic light
shows.

### Something missing: the invitation

Instead of error messages, the top says **“What this page can show as well”** —
one sentence per missing building block, saying what it brings:

| Missing | What it brings |
|---|---|
| **Wholesale prices per quarter hour** | traffic light with cheap and expensive hours, “Your lever” in euros — for the washing machine and dishwasher too. With the button “Load wholesale prices {year} from SMARD” |
| **Consumption per quarter hour** | when you use power, and the check of what your shifting achieved (§ 2) |
| **Wallbox or heat pump with its own meter** | “Your lever” with measured values instead of sample values only |
| **Generation and feed-in per quarter hour** | how much more solar power you could use at home (appears only with PV meters) |
| **Dynamic tariff or module 3** | when shifting brings real money: a dynamic offer under Tariff switch or time windows on the contract (§ 6) |

With the wholesale prices alone the page already shows the traffic light, the
best time windows and the sample values for washing machine and dishwasher.

### Result card “Your lever”

One big number: what you can save **on your tariff** in a year by shifting use
into cheap and sunny hours. If there is no lever on the contract as it is, it
shows what a dynamic tariff would bring, and says so. Below it up to three
facts: the biggest lever (on your tariff when the big number is), “With a
dynamic tariff: up to …”, “Solar power used at home” or “Module 3 already
saved”. The footer names source and date and says “amounts incl. VAT”. The
same number appears on the **“Your lever” tile of the overview**
([views](../referenz/ansichten.md#1-overview-dashboard)).

**“Save as image”** draws the card in the browser as a PNG (1200 × 630 pixels,
the format of preview images) — without any outside service, like the result
card of the forecast workshop ([What if?](was-waere-wenn.md)).

### What you can do

One sentence per appliance with time window and amount, biggest first, for
example “Charge the car on solar power, 11:00–14:00: up to €65 a year.” Next to
it, whether it applies **on your tariff** or **with a dynamic tariff** and
whether a **sample value** is behind it. The time window is for the current
half-year (April to September summer, otherwise winter); below it, small, the
window of the other.

Per appliance the biggest lever on the contract as it is wins; only where there
is none — or where it has no time window in the current half-year — the one
with a dynamic tariff. The windows:

| Appliance, signal | Time window |
|---|---|
| Car with plug-in times from evcc | only hours in which the car is usually plugged in during the half-year (in at least half of the charging sessions): with the wholesale price the cheapest three, with solar power the sunniest — if it is never there at noon, there is no solar sentence |
| Car without plug-in times, washing machine and dishwasher with wholesale price | the cheapest window of the week (weekday and time, 3 or 2 hours) |
| Heat pump with wholesale price | the cheapest three hours of the typical day, every day |
| Battery | the most expensive three hours of the typical day — discharge then |
| everything with module 3 | the low-tariff window according to the contract |
| everything else with solar power | the sunniest hours of the typical day |

Next to the car’s sentence it says which basis applies: **“plug-in times from
evcc”** or **“whole charging day (sample value)”**.

### When power is cheap (traffic light)

At the top the choice **Winter half-year · Summer half-year · Whole year**
(the current half-year is preselected).

- **A typical day** (all levels): 24 hours as a band. Per hour the average
  wholesale price; **green ✔** the cheapest third, **yellow ▲** the middle,
  **red ✖** the most expensive — the level always as colour **and** symbol, for
  screen readers as a sentence (“13:00: cheap, 8.3 ct, plenty of sun”). Above
  it **☀**, when the system generates strongly (at least half of the strongest
  hour; faint from 15 %). Below it a sentence: “Cheapest: on Sundays
  12:00–15:00. Most expensive: 17:00–20:00.” and the price range.
- **The week** (from “Experienced”): the same levels per weekday and hour,
  7 × 24 fields, as a table with text per field.

The traffic light’s ct values are the **wholesale share, net, excl. VAT** — it
says so above the band and at the range (“… (wholesale share, net, excl. VAT
and grid fee)”). The euro amounts of the levers next to it include VAT.

Without wholesale prices but with module 3 time windows, the traffic light
shows the grid fee: green the low tariff, yellow the standard tariff, red the
high tariff. Without both, only the sun.

```text
price of an hour   = average of all quarter hours of this hour (and this weekday) in the half-year
cheap / expensive  = lower / upper third of the 24 hours (day) or the 168 fields (week)
                     if both limits fall on the same price, it is “medium”
sun of an hour     = average generation per quarter hour ÷ that of the strongest hour
```

### Best time windows of the week

From “Experienced”: the three cheapest windows of three hours at a time in the
selected half-year that do not overlap on one day — with avg. wholesale price,
how much cheaper than the average and how much sun. The card carries the tag
**“net, excl. VAT”**, the column is called “Avg. wholesale price (net)”.

### Using solar power at home

From “Experienced”, with generation and feed-in per quarter hour: how much you
used at home (generation minus feed-in), how much went to the grid and how much
more the solar-power levers (below) would let you use — with the
self-consumption afterwards and the amount from unit price minus feed-in
tariff. The bar shows both. The figures can differ by less than 1 % from the PV
page: there the meter readings count (consumption between two readings spread
evenly over the days), here the quarter hours of the calendar year.

### Your lever

From “Experienced” the table: per appliance and price signal, how many kWh move
and what that brings in a year, biggest first. Below it the sum — per
appliance only its biggest lever counts, otherwise the same kWh would be
shifted twice — and the part of it on your tariff, both incl. VAT.

**How the server calculates:** shifting happens only **within one day** (local
time) and at most at the appliance’s **highest measured power** per quarter
hour. The free part of the day’s consumption fills the cheapest quarter hours
of the day first. Today’s pattern is one of the possible distributions — so a
lever is **never negative**. It is an **upper limit**: the cheapest quarter
hours are known the day before (day-ahead), and the appliance must then be
ready. Levers below €1 a year do not appear.

**The car only charges while it is plugged in.** With charging sessions from
evcc on the wall box meter, each session from plugging in to unplugging is a
**charging window** — plugged in in the evening, also across midnight until the
next morning. The charge only moves within its window; charging outside a
window stays where it is. On days without any session (evcc was not running
yet, a session is missing) the assumption “available all day on charging days”
applies. Below the table it says which basis applies, with the number of
charging sessions and — if not all charging lies in a window — their share.

```text
wholesale price        lever = Σ days (cost today − cost if the free part ran in the day’s cheapest
                                quarter hours) ÷ 100 × (1 + VAT)
module 3               lever = the same with the quarter hour’s grid fee, without mark-up
car with plug-in times days  → charging windows (plugging in to unplugging), otherwise as above
battery                lever = Σ days (value if discharging were in the most expensive quarter hours in
                                which the house needs power and the battery does not charge − value today)
                                ÷ 100 × (1 + VAT)
solar power            kWh   = consumption in quarter hours without feed-in (at most the house’s grid import
                                there) that fits into quarter hours with feed-in of the same day
                                (for the car: of the same charging window)
                       lever = kWh × (unit price − feed-in tariff) ÷ 100
washer / dishwasher    lever = Σ days kWh per day × (average of all start times − best window) ÷ 100
                                × (1 + VAT) with the wholesale price
VAT                    = rate of the dynamic offer (Check a switch), without an offer 19 %
```

- **Measured:** the wall box and the heat pump (roles of the electricity
  meters) and the battery. **Solar power** only reaches the house connection
  and its sub-meters — not a heat pump with its own meter and tariff. A day’s
  feed-in is used once: first by the car, then by washing machine and
  dishwasher.
- **Unit price** is the house connection’s at mid-month, **feed-in tariff**
  that of the feed-in meter.

**Sample values** (marked as such on the page and in the report):

| Assumption | Value |
|---|---|
| Heat pump: shiftable part of daily use (building and buffer) | 30 % |
| Car without charging sessions from evcc: available all day on charging days | the whole charge of the day |
| Washing machine | 0.9 kWh per cycle, 4 cycles a week, 2 hours |
| Dishwasher | 1.0 kWh per cycle, 5 cycles a week, 2 hours |

Washing machine and dishwasher have no meter of their own: the calculation is
against a **start at any time** (the average of all start times of the day).

### Checked: what shifting achieved

From “Expert” the previous cards. They use the pure wholesale share, **without
VAT** — a measure of the consumption profile, not a bill. They say so: the
sentence under the heading, the tag **“net, excl. VAT”** on every meter card
and on the battery, “(net)” on “Own avg. / market avg.”, “Advantage” and
“Value of shifting”. The module 3 card does not carry it: it uses the amounts
on the contract. **One card per
electricity meter** with quarter hours in the selected year. Meters with the role “Heat pump (heating
electricity)” or “Wallbox (EV charger)” carry the tag **“controllable”** and
come first; the others — such as the house connection — follow for comparison.

| Figure | Meaning |
|---|---|
| **Consumption with price** | kWh in quarter hours that have both consumption and a wholesale price — the calculation runs only over these |
| **In cheap quarter hours** | the share of it in quarter hours whose price is **below the average of their month** |
| **At negative prices** | kWh in quarter hours with a wholesale price below zero |
| **Own avg. / market avg. (net)** | your wholesale price per kWh, weighted by consumption, against the market average |
| **Profile factor** ⓘ | own avg. divided by market avg. |
| **Advantage (wholesale share, net)** | what your consumption profile saved (positive) or cost (negative) against the market average |

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
- **House connection and wall box.** If the wall box is a sub-meter of the
  house connection, its grid import is also in that card. The wall box’s card
  counts everything it charges, including solar power at midday — as the
  appliance’s consumption profile, not as a bill.

Below it a sentence: “Cheaper than average: … saved on the wholesale share” or
“More expensive than average: … more on the wholesale share”, with the profile
factor. **“Per month”** opens a table: month, kWh (of all quarter hours with
consumption), cheap, negative, own avg., market avg., advantage and
**coverage** — the share of the month’s quarter hours with consumption and
price.

Then the cards “Battery by wholesale price” (§ 5) and “§ 14a module 3 —
{meter}” (§ 6), when the data for them exists, and at the bottom the list of
all quarter-hour series (§ 8).

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
| **Value of shifting (net)** | what shifting across the day was worth at the wholesale price — wholesale share, net, excl. VAT (tag on the card) |

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
by the card “Battery {year}” under PV generation; its quantities come from the
meter readings and can therefore differ by less than 1 % from the quarter hours
here. What discharging in the most expensive hours would add is under “Your
lever” (§ 4).

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
For module 3 to include VAT like the page’s other levers, enter the amounts
including VAT, as they reach your bill; the sample household has them that
way. If the standard tariff and all windows are empty, module 3 is removed from
this contract again.

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
the high tariff lowers the saving; it can also turn negative. What more use in
the low tariff would add is under “Your lever” (§ 4); without wholesale prices
the time windows colour the traffic light.

**Only here.** The time windows do not change the contract’s costs: balance,
forecast and bill check keep calculating with the unit price you enter. The
saving appears on this page alone.

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

The card **“Quarter-hour series”** at the bottom of the page (level “Expert”)
lists every series: utility · meter ID or “Wholesale prices DE-LU”, year,
coverage (share of quarter hours with a value) and source — “File”, “Home
Assistant” or “SMARD”. 🗑️ **“Delete series”** with the question “Delete this
series? Meter readings and daily values stay.” removes only the quarter hours.
Importing or loading again creates the series anew.

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
box from its charging sessions (since v3.6.0 with plug-in times: in the winter
half-year plugged in in the evening until the next morning, in the summer
half-year at noon until late afternoon; charging starts right after plugging
in), since v3.5.0 also the solar generation and
feed-in, and the battery — so that every quarter hour adds up on its own
(generation = used directly + fed in + charged; the battery charges from the
surplus and discharges into the house in the evening). Plus the real wholesale
prices 2025 per quarter hour from SMARD, and on the heat pump’s contract the
module 3 windows from § 6. When loading, the app carries the last two to three
months up to yesterday forward — day by day from the same calendar day a year
earlier, the wholesale prices too; the day film shows them. The consumption is
made up.

To load it: Settings → General → **“Start the setup assistant”**, “Own house
with heat pump and solar”, choose a level, at the end “Look at it with sample
data”
([sample households](anwendungsfaelle.md#sample-households-to-look-at-v320)).
This replaces all data of the installation; the app takes a snapshot first.
Then **Insights → Flexibility**, year 2025.

**Your lever.** The result card — and the tile on the overview — shows **€91**
a year on the contract as it is, with a dynamic tariff up to **€251**, both
incl. VAT (wholesale price with the 19 % of the offer “Strom Dynamisch”):

```text
Appliance                       Price signal                              shifted   per year
Electric car (Wallbox)          Wholesale price, with a dynamic tariff   2,316 kWh     €140
Heat pump                       Wholesale price, with a dynamic tariff     944 kWh      €52
Heat pump                       Grid fee (module 3), on your tariff        878 kWh      €40
Dishwasher (sample value)       Solar power, on your tariff                105 kWh      €23
Battery                         Wholesale price, with a dynamic tariff     327 kWh      €19
Washing machine (sample value)  Solar power, on your tariff                 78 kWh      €17
Dishwasher (sample value)       Wholesale price, with a dynamic tariff     261 kWh      €16
Washing machine (sample value)  Wholesale price, with a dynamic tariff     188 kWh      €12
Electric car (Wallbox)          Solar power, on your tariff                 53 kWh      €11

on your tariff                €40 (heat pump, module 3) + €23 + €17 (sun) + €11 (car, sun)  ≈ €91
biggest lever per appliance   €140 (car) + €52 (heat pump) + €23 + €19 (battery) + €17      ≈ €251
```

The car uses its **plug-in times from evcc** (153 charging sessions in 2025,
all charging inside a charging window). With a dynamic tariff it would bring
the most, charged at night in the winter half-year instead of right after
plugging in in the evening, and in the cheapest midday hours in the summer
half-year. With solar power it brings only €11: in summer it charges at noon
anyway, in winter it is not there at noon. Under the assumption “whole
charging day” — the way the page calculated before plug-in times — it would
have been €65 with solar power and €154 with a dynamic tariff: the assumption
overestimates what a company car that is away on weekdays can do.

The biggest lever on the contract as it is is therefore the heat pump in the
low grid tariff. In the winter half-year the sentences read, for example,
“Charge the car 02:00–05:00: up to €140 a year.” (with a dynamic tariff,
plug-in times from evcc) and “Run the heat pump more in the low grid tariff,
00:00–06:00: up to €40 a year.” In 2025 the traffic light shows Sundays 12 to
15 o’clock as the cheapest window of the week, in the summer half-year even
with prices below zero; the evening is expensive.

**Solar power.** 3,570 of 9,198 kWh used at home (39 %); with the solar-power
levers about 236 kWh more (41 %), €51 a year.

**Checked** (level “Expert”):

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
discharges in the evening. A test recalculates each of these figures
independently from the quarter hours of the file.

Looking back under Tariff switch, the offer “Electricity Dynamic” for the meter
“Household (grid import)” calculates with its own load profile in twelve months
of 2025.

---

## 10. In the annual report

In the annual report dialog, **“With page “Flexibility” (traffic light, levers,
time windows)”** adds a page of its own — in the PDF and in the print view
(`flex=1`), with the same figures as here: the big number, the action
sentences, the typical day for the winter and summer half-year, the best time
windows, “Your lever” and solar power. The amounts are incl. VAT as here
(footer of the card, sum and note under “Your lever”); the ct values of the
band and time windows appear as “wholesale share, net, excl. VAT” (sentence
above the band, column “Avg. wholesale price (net)”, a note of their own), as do
the battery value and the amounts of the check in the report’s “Flexibility”
topic. The notes also name the
basis of the car’s charging windows (plug-in times from evcc or sample value),
and the car’s sentence carries it as a tag. In the PDF the sun is a dot above the
hour (the PDF has no ☀). Without wholesale prices, module 3 time windows and
generation per quarter hour the page is left out
([Reading the annual report](jahresbericht-lesen.md)).

---

## 11. When something goes wrong

| Message | Cause and fix |
|---|---|
| Invitation “What this page can show as well” | Building blocks are missing (§ 4); each sentence says which one and what it brings. |
| “No quarter hour with both consumption and wholesale price.” | Consumption and prices do not overlap — for example prices only up to a day, consumption only after it. Load the prices again. |
| “SMARD could not be reached or returned no values — …” | SMARD does not answer or has nothing for the year (response `502`). Try again later. The app does not accept a file with quarter-hour prices. |
| “Module 3 time windows invalid (…): …” | When saving the contract: no quarter ticked, a time not in the format HH:MM, “From” not before “Until” (across midnight: two windows), a price outside 0 to 100 ct, no standard tariff or more than 24 windows. The brackets name the field, e.g. `windows[0]` for the first window. |
| “Timestamp “…” not readable — expected ISO 8601 with time zone, …” | A push from Home Assistant with a `timestamp` without a time zone or in another format (response `400`). The template `{{ now().isoformat() }}` fits. |
| Quarter hours from Home Assistant stay empty | The line `"timestamp"` is missing in the REST command (template before v3.3.0), only the daily push runs, or one of the cases from § 2 “Nothing is spread”. |
| “Series “…” not found.” | The series has already been deleted (response `404`). |
| “Invalid year: …” | Via the API, a year outside 2018 to 2100 (response `400`). |
| Week, time windows and “Your lever” are missing | The level is “Beginner” (§ 1). |
| The check per meter is missing | The level is not “Expert”. |
| An appliance is missing under “Your lever” | Its lever is below €1 a year, or it lacks quarter hours or the role on the meter. For solar power: it is not behind the house connection (own meter) — or, according to its plug-in times, the car is never plugged in while the sun shines. |
| No solar sentence for the car in winter | With plug-in times from evcc: in the winter half-year the car is usually not plugged in at noon. The sentence then names the lever with a dynamic tariff. |
| The “Your lever” tile is missing on the overview | For the previous year there are no measured quarter hours (electricity, or generation and feed-in) or no lever of €1 or more. The Flexibility page says what is missing. |
| The card “Battery by wholesale price” is missing | One of the two battery meters under PV generation, its quarter hours or the wholesale prices are missing. |
| The card “§ 14a module 3” is missing | The contract running on the first of the month has no time windows, or they sit on another meter than the one with the quarter hours. |

---

## 12. Via the API

```text
GET    /api/flexibility?year=2025                         → {year, prices, meters, battery, module3, insights}
GET    /api/flexibility?year=2025&summary=1               → {year, summary}  (only the card’s number, v3.6.0)
GET    /api/intervals                                     → {series: [...]}
DELETE /api/intervals/{id}                                → {deleted: true}
POST   /api/market-prices/sync-smard-intervals?year=2025  → {year, slots, from, to}
POST   /api/market-prices/sync-smard-intervals?year=2025&month=3  → March only (v3.5.1)
```

Without `year` the previous year applies. Since v3.6.0 the response also
carries `insights` — traffic light (`pattern`), levers (`levers`, `total_eur`,
`total_real_eur`, incl. VAT with `vat_pct`), the card’s number (`summary`), the
car’s charging windows (`ev_availability`, per lever `availability`), action
sentences (`actions`), solar power (`solar`), what is missing (`missing`) and
the sample values (`assumptions`); all fields in the
[API reference](../referenz/api.md#valuing-flexibility-v330). With
`summary=1` only `summary` comes back — that is how the overview fetches its
tile, without the check per meter. The annual report adds the page with
`flex=1`.

Quarter hours also arrive via
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
