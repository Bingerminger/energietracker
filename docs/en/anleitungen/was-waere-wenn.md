# What if? The forecast workshop

**English** · [Deutsch](../../anleitungen/was-waere-wenn.md)

[← Compendium index](../README.md)

What does a cold winter cost? What does insulation bring? Is switching to the
cheaper offer worth it? Since **v3.6.0** the **forecast workshop** answers
such questions with your own numbers: move a slider, see the result at once —
as one big number, as a curve and as a ranking “What makes the biggest
difference?”. It calculates on your server, with your heating model, your
contracts and your billing period. Nothing leaves your device until you pass
on an image or a PDF yourself.

> **In one minute:** open Insights → **Forecast** (also at the Beginner
> level), click **“Cold winter”** at the top. The card tells you what a winter
> like roughly one in ten costs extra. Then drag the **“Save”** slider to 10%
> and watch the number turn.

---

## 1. What you need

- At least **six months of consumption** of a meter with readings and a
  contract — gas, electricity, water or district heating. Delivery utilities
  (heating oil, pellets) and feed-in stay with the
  [detailed forecast](../referenz/ansichten.md#6-forecast).
- For **winter, room temperature and insulation** a heating model: gas or
  district heating with temperatures (weather sync). With a
  [climate normal](../verstehen/09-glossar.md) the heating degree days are
  those of 30 years at your location.
- For a **tariff switch** offers you entered (Tariff switch → add an offer) or
  your own price.

## 2. The levers

| Slider | What it moves | from level |
|---|---|---|
| **Winter** (± % heating degree days) | only the heating-dependent part, proportionally | Beginner |
| **Save** (%) | all consumption — behaviour | Beginner |
| **Tariff** | current contract, an offer, the cheapest offer, from Experienced your own unit price and standing charge | Beginner |
| **Room temperature** (± °C) | the heating threshold by the same amount (§ 6) | Experienced |
| **Base load** (± %) | hot water, cooking, appliances — the part without weather influence | Experienced |
| **Measures** | per measure % on the heating part, % on the base load, cost | Experienced |
| **Forecast model** | weather-adjusted (the forecast), same as last year, trend | Experienced |
| **Heating curve** | the regression model of the forecast (linear, robust …) | Expert |
| **Electric car** | electricity: km per year × kWh/100 km, evenly over the days | Expert |
| **Heat pump (SPF)** | electricity of a meter with the heat pump role: quantity × SPF today ÷ SPF in the scenario | Expert |
| **Budget** | finds the best combination of measures (§ 7) | Expert |

You enter the percentages of a measure yourself, ideally from your energy
assessment or the tradesperson’s quote. The workshop deliberately offers no
“typical” values — they depend on the house.

## 3. What you see

- **Result card:** one statement, one big number — “A cold winter costs you
  €214 more a year.” Plus consumption, CO₂ and the recommended advance payment.
- **Key figures:** cost per year (against the baseline), consumption per
  year, recommended advance payment; from Experienced the balance at the next
  bill and CO₂.
- **Cumulative cost:** baseline dashed, scenario in colour — where the lines
  part, the change takes effect.
- **Month by month** (from Experienced): consumption per month, for experts
  with the uncertainty range.
- **What makes the biggest difference?** (from Experienced): each lever on its
  own against the baseline (normal winter, current contract), saving in the
  first year and — where you entered a cost — the payback.
- **Forecast models compared** (Expert): the same question with three models;
  if they are far apart, the answer is uncertain.

Below the workshop the **detailed forecast** is still there, collapsible:
history with range, monthly table with cost, advance payment, balance and
method.

## 4. Example scenarios

| Example | What it sets | Note |
|---|---|---|
| **Cold winter** | heating degree days as high as in roughly one winter in ten (from the spread in the climate normal) | without a climate normal 10% as an assumption |
| **Roof insulated** | a measure “Insulate roof” with 15% less heating energy | an example, not a guide value — enter yours |
| **Cheapest offer** | the cheapest of your offers, calculated with your expected consumption | bonus not included |
| **Save 10%** | ten percent less consumption through behaviour | — |

An example only appears if it fits the meter (no heating model, no winter; no
offer, no switch). In the public demo the baseline and the examples are
precomputed — one click shows a result straight away.

## 5. Save, compare, share

- **Save:** enter a name, “Save”. Scenarios live **in this device’s browser**
  (at most 20); another device does not see them.
- **Compare:** recalculates all saved scenarios and puts cost, difference,
  consumption and CO₂ side by side.
- **Save result as image:** the card as a PNG (1200 × 630, the size of preview
  images) — drawn in the browser, without any outside service.
- **Print or save as PDF:** the print view with assumptions, baseline against
  scenario, curves, monthly table and ranking, neatly on A4. The browser’s
  print dialog turns it into a PDF.
- **Monthly values as CSV** (Expert): quantity, cost and cumulative cost per
  month for baseline and scenario.

## 6. How it calculates

**One source.** The baseline is the forecast for the next twelve months with
all its rules: climate normal, heating model, analysis baseline, contracts to
the day, advance payments by plan. The workshop changes only quantities — and
prices for the tariff. In the forecast the cost of a month is a linear
function of the quantity (unit price × quantity + standing charge − bonuses);
exactly this function also values the scenario quantities. Without a change
you therefore get the forecast to the cent.

**Heating part.** The heating model describes consumption as a × heating
degree days + c × days. The heating-dependent part of a month is a × HDD;
only it responds to winter, room temperature and insulation. Summer months
without heating degree days do not change.

**Room temperature.** One degree less in the room lowers the heating threshold
by one degree. The workshop takes the heating degree days at the shifted
threshold from the climate normal (otherwise from your temperature history)
and multiplies the difference by a — your own model, not a rule of thumb; the
same reasoning as the forecast’s temperature offset since v2.8.0.

**Measures act one after another.** Twice 30% less heating energy gives
0.7 × 0.7 = 49% less, not 60%. The ranking calculates each measure on its own,
the combination calculates them together.

**Tariff.** An offer is calculated with the same price rule as in the
[switching decision](../referenz/ansichten.md#switching-decision): unit price ×
quantity + standing charge per month, bonus not included. For water (three
price components) the current contract stays.

**Models.** *Weather-adjusted* is the forecast. *Same as last year* takes the
measured months one year earlier (daily rate × days); if a month is missing,
the forecast fills it and the workshop says how many. *Trend* continues last
year with the change from the year before, at most ± 25% — and only with two
full years of readings. The uncertainty range belongs to the forecast model.

**Advance payment.** With a running billing period (F1030) like the
suggestion in the contract status: today’s advance payment + expected final
balance ÷ remaining months. Otherwise a twelfth of the annual cost, rounded up.

**Balance at the next bill.** The balance measured so far in the running
billing period plus the forecast months up to its end (cost − advance
payment), the last month pro rata.

**CO₂.** Consumption × the factor the consumption view used over the last
twelve months.

## 7. The best combination within a budget

Enter a **budget** and the **cost** of the measures. The workshop then
calculates **every combination** that fits the budget with the real model —
interactions included — and names the one with the largest saving per year
(on a tie the cheaper one). With at most ten measures that is at most 1,024
combinations; the result is exact.

Alongside, a small **simplex solver** solves the linear relaxation: it may
take measures partially and uses the individual savings. That gives an
**upper bound** and the **shadow price of the budget** — what one more euro
of budget would bring per year there. “Apply selection” puts the measures
found into the scenario.

## 8. Limits

- A scenario is a **model calculation, not a measurement**. The workshop says
  up to when readings exist and how large the heating part is.
- The workshop calculates for gas, electricity, water and district heating
  with readings and a contract; not for delivery utilities or feed-in.
- It does not offer dynamic tariffs (wholesale price per month) as an offer —
  [Tariff switch](../referenz/ansichten.md#switching-decision) calculates those.
- It does not calculate load shifting against quarter-hour prices; what
  shifting achieved is shown by [Valuing flexibility](flexibilitaet.md).
- Measure percentages and costs are your inputs.

## 9. For developers

The workshop calls `POST /api/forecast/scenario` — a route that only
calculates and writes nothing (also allowed with a read-only key). Fields,
limits and response: [API reference](../referenz/api.md).
