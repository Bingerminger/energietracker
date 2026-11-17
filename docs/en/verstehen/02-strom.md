# Electricity

**English** · [Deutsch](../../verstehen/02-strom.md)

[← Gas](01-gas.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **cumulative** (meter readings in kWh) |
| Billing unit | kWh |
| Conversion | none (already kWh) |
| HDD-relevant | **no** |
| Colour | Mint green |

## Background

Electricity is measured directly in kWh — no conversion. Unlike gas, electricity
consumption does **not** depend systematically on the outdoor temperature
(exceptions: heat pump, air conditioning, electric supplementary heating — these
would create an HDD coupling, but it is deliberately not modelled here because it
is household-specific).

## What Energietracker does with it

- **Monthly consumption** via linear interpolation.
- **No HDD, no heating-signature regression.** The analysis instead shows the
  **seasonal profile** (monthly mean) and trends.
- **Forecast**: pure seasonal profile — the regression is omitted (see
  [Fundamentals §6](00-overview.md)).
- **Base load**: a constant base (fridge, standby, router) plus variable peaks.
  Noticeable base-load increases are detected by the anomaly/trend rule.

## Contracts

Like gas: working price (ct/kWh), base price (€/month), advances, bonuses. Shadow
contracts allow "what would tariff X have cost?" on the real consumption —
without distorting the balance/forecast.

Refunds/surcharges and additional advance payments are recorded as
**[special payments](10-sonderzahlungen.md)** (F1003) and feed into the balance.

## Peak and off-peak: one contract for a meter group *(v3.1.0)*

A dual-rate meter has two registers — peak (HT) and off-peak (NT) — but is
billed under **one** contract with one standing charge and two unit prices.
Since v3.1.0 the app models it like this:

1. Create both registers as meters of their own and combine them into a group
   under Household → Meters with **“Group meters”**
   ([Meter topology](13-meter-topologie.md)).
2. In the contract dialog, choose the group under **“Meter”** — it is listed
   under “Meter groups (one contract for all)”.
3. Under **“Unit price per meter (e.g. peak/off-peak)”** enter each register’s
   unit price. An empty field takes the unit price above.

Each register calculates its consumption at its price; standing charge,
advance payments and bonuses count once.

```text
Example: peak 2,000 kWh × 30 ct + off-peak 1,000 kWh × 22 ct + 12 × €12 standing charge
       = €600 + €220 + €144 = €964 per year
```

The same works for gas and district heating, e.g. when one contract covers two
meters. Balance, forecast, switch decision and bill check exist for the whole
group; details under
[Meter topology → Group contract](13-meter-topologie.md#group-contract-v310).

## Controllable consumers *(v3.1.0)*

Heat pumps, wall boxes, air conditioners and battery storage above 4.2 kW that
go into operation from 1 Jan 2024 count as **controllable consumer devices**
under § 14a EnWG: the grid operator may throttle their power temporarily during
a bottleneck — to at least 4.2 kW, not to zero — and in return the grid fee
drops. Three modules:

| Module | What it is | In the app |
|---|---|---|
| **1** | a fixed amount per year by which the grid fee drops | in the electricity contract the list **“Reduced grid fee (§ 14a EnWG, module 1)”**: from when and how much per year. The app deducts the amount day-exact from the fixed costs — €120 a year is €10 per month — and shows it per month |
| **2** | a separate meter for the heat pump or wall box; its grid unit price drops to 40 % | an electricity meter of its own with the role “Heat pump (heating electricity)” or “Wallbox (EV charger)” and a contract of its own with the lower unit price. If it sits behind the household meter, as a sub-meter ([Meter topology](13-meter-topologie.md#module-2-a-separate-meter-with-its-own-contract-v310)) |
| **3** | time-variable grid fees on top of module 1, per quarter hour | *(v3.3.0)* in the electricity contract (level “Expert”) the time windows from the grid operator’s price sheet: standard grid fee and windows with their own price per quarter. With quarter-hour values, [Valuing flexibility](../anleitungen/flexibilitaet.md#6--14a-module-3-time-variable-grid-fee) works out the saving against the standard grid fee; costs, balance and bill check still calculate without it |

In the tariff comparison and the switch decision the reduction is left out: it
applies equally to every supplier.

## Dynamic tariffs *(v3.1.0)*

With a **dynamic tariff** the unit price follows the wholesale price
(§ 41a EnWG). Since 2025 every supplier in Germany has to offer one; it is
billed per quarter hour, which needs a smart metering system.

**Is it worth it? — the dynamic tariff check.** Under **Costs & contracts →
Tariff switch** (electricity) there is the card **“Wholesale electricity
prices”**. “Load from SMARD” fetches the monthly averages of the wholesale
prices for Germany/Luxembourg from SMARD, the platform of the Federal Network
Agency — only when you tap it; “Import file” reads a SMARD download or a list
of your own. Then add an offer with the tick **“Dynamic tariff (wholesale price
per month)”**, plus the **mark-up per kWh** (everything except the wholesale
price: grid fee, levies, taxes, margin — it is on the price sheet), the standing
charge and the VAT on the wholesale price (default 19 %).

```text
unit price per month = wholesale price (monthly average) × (1 + VAT) + mark-up
Example: 3,000 kWh, wholesale 10 ct, VAT 19 %, mark-up 15 ct, standing charge €10
         → 3,000 × 26.9 ct + 12 × €10 = €807 + €120 = €927 per year
```

For future months without a wholesale value the app assumes the same month of
the previous year and says for how many months. Without wholesale prices the
offer does not appear; a note does instead.

**What the check cannot do.** Without quarter-hour values it calculates with
the monthly average, as if your consumption were spread evenly across the day.
In the evening, when many people cook and heat, electricity is usually dearer
than average; whoever uses a lot then pays more with a dynamic tariff than the
check shows. Whoever can shift consumption into cheap hours — wall box, heat
pump, battery — pays less. The app deliberately uses no load profile (the BDEW
standard load profile H25): it is published without a licence. *(v3.3.0)* If at
least 90 % of a month’s quarter hours have your consumption and the exchange
price, the review uses your own load profile instead
([Dynamic tariff check with your own load profile](../anleitungen/flexibilitaet.md#7-dynamic-tariff-check-with-your-own-load-profile));
the ranking for switching still uses the monthly average.

**Keeping a dynamic contract.** If you have one, enter it as a real contract
with **prices per month**: on the contract card **“Import monthly prices”**
with a file `month;ct/kWh[;standing charge]` — e.g. `01.2026;31,42;9,90` per
line, from the bill or the customer portal. The preview names the months;
“Apply” enters a unit price per month from the first. Balance and bill check
then calculate with these prices. The tick “Dynamic tariff” exists only for
offers, not for real contracts.

## Wall box and company car *(v3.1.0)*

An electricity meter with the role **“Wallbox (EV charger)”** counts like any
other — as a sub-meter behind the household meter, the app subtracts it there.
In addition its consumption view shows the card **“Charging record (company
car)”**: the charged amount per month with price, as CSV and PDF for the
employer. Step by step:
[Record charging electricity for a company car](../anleitungen/ladestrom-nachweis.md).

## A new wall box in the history *(v3.13.0)*

**A new wall box does not know its first winters.** Up to v3.12, the forecast took
the average of all measured months for every calendar month without history, so after
a summer too little for the winter.

<!-- doc-claim: wallbox-arrival -->
**Since v3.13.0 the forecast of the wall box calculates such months by an assumption:**
an electric car needs about 1% more electricity per degree below 20 °C, and it is
driven the same distance. The degrees come from the climate normal (otherwise from
your own daily temperatures), the level from the measured months. Source: Lee, Song,
Lim, Park (2024), Energy Conversion and Management 306, 118289: at −15 °C 35.4% more
energy than at 24 °C, most efficient between 20 and 30 °C. If the meter only knows a
month distributed (rare readings) and there are charging sessions from evcc, the
month comes from the sessions. It needs three measured months and temperatures for
every calendar month (without them it stays with the average); the forecast names the
months calculated this way.

**The meter in front of the wall box still uses the months before it**, in which the
car is missing. Giving those months the grid consumption of the wall box in the same
calendar month (without the solar share of the evcc sessions) is built but did not
pass its check on the test household. A note says so and suggests a baseline date on
the day of the installation ([glossary: baseline date](09-glossar.md)). In a cascade
(heat pump in front, household behind), the note also applies to the household meter:
the wall box hangs on the front meter there but sits in the household. The notes stay
for two years after the installation, that is from the installation date on the wall
box meter.

## Heat pump by the weather *(v3.13.0, not yet switched on)*

**A heat pump’s electricity follows the weather; so far the forecast treats it like
household electricity:** with the seasonal profile of the measured years. After a mild
winter it is therefore too low, after a cold one too high.

**Which meters follow the weather** is decided in one place (`UtilityWeather`), only by
fields, never by the name:

| Meter | follows the weather |
|---|---|
| role **heat pump**, own meter (next to or behind the household) | yes |
| **cascade:** front meter with the heat pump role, household behind it | yes, calculated is the **rest** (the quantity its contract bills), not the whole meter |
| **main electricity meter**, when the heating runs on electricity (Household › Building) and there is no heat pump meter of its own | yes; with several meters without a parent meter none (the app does not guess) |
| **group** (e.g. peak/off-peak of a heat pump) | yes, if all members do |

**Built is the same path as for gas:** heating model from degree days and days (also
across reading intervals), climate normal instead of weather luck, quality gate, band.
In addition the calculation picks the heating limit itself when the meter has none of
its own, and uses the same calibration to the latest level for forecast and balance.

**Unlike gas**, the climate normal of the heat pump follows the **temperature trend**:
at ten DWD weather stations the mean of the thirty years put the degree days of the
following year **9 to 12 % too high**, with the trend 2 to 3 %
([measurement](../entwicklung/messung-temperaturtrend.md)). The **HT/NT group** of a
heat pump calculates **one** model and splits it by the shares of its registers per
calendar month; group = HT + NT holds.

**It is only shown once it passes its check.** It is measured on a test household with
its own physics, not derived from the app’s model, against thresholds fixed in advance
(tool `tools/rueckrechnung.php`, [tests](../entwicklung/tests.md)). In v3.13.0 the
calculation does not pass them yet; forecast, band and “Expected at billing” stay with
the seasonal profile, and **a note says so** in the forecast, on the balance card and in
the workshop.

**Minimum needed** once it is switched on: 8 measured months or 8 intervals between
readings of at least 20 days, including a winter and a summer month, plus a location for
the climate normal. Below that the seasonal profile stays and the note says what is
missing (show instead of guess).

**Known limit:** the model is linear. In a very cold winter the performance factor drops
and a heating rod kicks in; then it tends to be too low.

## Typical pitfalls

- **Expecting a temperature correlation.** For pure household electricity, a
  weak/absent HDD coupling is normal — not an error.
- **Heat-pump electricity** mixes heating and household electricity. If you want
  to separate it, create a second meter with the role “Heat pump (heating
  electricity)”, usually as a sub-meter. With a heat meter as well, the app
  calculates the seasonal performance factor
  ([Heat](15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310)).
- **Two contracts for a peak/off-peak pair.** A register with its own contract
  and at the same time a group contract in the same period is refused by the
  app — otherwise the standing charge and consumption would count twice. End
  the old contract first.

[← Gas](01-gas.md) · [Water →](03-wasser.md)
