# Fundamentals & methodology

**English** · [Deutsch](../../verstehen/00-overview.md)

[← Compendium index](../README.md)

This chapter explains the calculation cores that apply to *all* utilities:
consumption distribution, heating degree days and temperatures, regression,
weather adjustment, forecast, efficiency class, anomalies and balance. All
formulas are checked against the source code (as of v2.8.0).

> **Note on presentation:** formulas are deliberately written as plain-text code
> blocks (no LaTeX maths) so that they are rendered **identically and correctly**
> on GitHub, in editors and in any Markdown viewer.

---

## 1. From the meter reading to the monthly consumption

Meters are read at irregular intervals. Between two readings `r1` (date `t1`,
value `c1`) and `r2` (`t2`, `c2`):

```text
Consumption[t1..t2] = c2 - c1
Days                = t2 - t1
```

This consumption is **linearly interpolated over the days** and then allocated to
the calendar months:

```text
Consumption per day = (c2 - c1) / (t2 - t1)
```

Across a **meter-swap boundary** (old device → new device):

```text
Consumption = (final_old - prev) + (curr - initial_new)
              \__ remaining path old device __/  \__ new device __/
```

Future readings (`is_future`) are ignored. For delivery-based utilities (heating
oil/pellets) the meter difference does not apply — there the consumption is
energetically balanced (see [Heating oil](05-heizoel.md)).

### Measured or interpolated *(since v3.7.0)*

When two readings are far apart, a monthly value is not a measurement but the
even spread of a longer period: with readings at the end of September and the
end of December, October, November and December share the same daily rate.
Every month therefore carries a marker:

```text
measured          = reading interval ≤ 45 days
measured_share      = share of the month's consumption from such intervals (0 … 1)
measured_day_share  = share of the month's days from such intervals (since v3.9.0)
interpolated        = measured_share < 0.5 or measured_day_share < 0.5
reading_gap_days    = longest interval the month draws from
```

**Since v3.9.0 the days count too.** If most days of a month come from a long
interval, the month is interpolated, even when the few measured days carry more
consumption: in the messy test household, December 2024 drew 18 of 31 days from
a 103-day interval and counted as measured up to v3.7, because the spread part
takes in the milder autumn months and so has a daily rate that is too low. It
therefore entered the anomalies.

What counts is the **length of the interval**, not whether there was a reading
in the month: readings on 31 January and 1 March measure February well. An
interval of up to 45 days reaches at most two half weeks beyond a full month;
the spreading then shifts so little that it stays below the level at which the
anomaly detection reports anything at all. An **estimated reading**
(`is_estimated`) measures nothing: the interval runs from the real reading
before it to the real one after it. For meters with periods, the period is the
interval. Heating oil and pellets carry no marker (`null`); their months are
modelled by degree days anyway.

**Interpolated months do not count where the app learns from months** —
anomalies (section 9), recommendations, the heating model and curve
(section 5), the forecast's seasonal profile (section 6) and trends against the
previous year. Their value says nothing about what was used in that very month;
treated as a measurement, it produced convincing-looking warnings and a skewed
heating model. **The monthly values themselves, costs, advance payments and the
balance stay as they are** — the total between two readings is known and
correct. Utility view, analysis and PV summary say when monthly values are
interpolated, with the number of readings in the range; tables show “≈”, charts
hatched bars.

**Limit (as of v3.9.0):** within an interval the app spreads evenly per day,
not by the weather. An interval from September to December therefore gives
October too much gas and December too little; for PV, in an interval from
February to July, March gets as much sun as June. It spreads by the weather only
across a reading marked “only for the
bill” (below). Spreading consumption by the weather in general is planned for a
later version. Until then shorter intervals help: every reading makes the
months next to it more precise.

### Check a reading *(since v3.9.0)*

A wrong reading between two correct ones silently moves consumption from one stretch
to the next. The total is right, but monthly values, billing year, heating model (and
with it the heating limit suggestion) and forecast are not. The app looks for such
readings and asks. It never corrects anything by itself.

**Expectation per interval.** For gas, district heating, heat and the electricity of a
heat pump: the heating model `a × HDD + c × days` with the heating limit of the meter
(or its group), fitted over the reading intervals. For electricity and water: the mean
daily amount of the intervals up to half a year before and after. For PV, garden water
and other meters with marked seasons (the daily rate of the summer half, April to
September, and that of the winter half are more than a factor of two apart, each half
taken as the median of its months): the same days in other years. The same for a milder season that recurs: if the summer half
is at least a quarter above or below the winters next to it in at least two years, the
same way round (counted from intervals that lie mostly in one half of the year), the
mean daily amount would say “too little” in winter and “too much” in summer although
everything is right. An EV charging point is not checked: charging happens when the car
was driven.

**A candidate** is a reading or a block of up to six consecutive readings (at most half
a year from the first to the last) where

```text
interval before  clearly below the expectation   (or above)
interval after   clearly above the expectation   (or below)
inside the block every interval normal
sum              |deviation before + deviation after| ≤ ½ × offset
neighbours       the interval before the first and the one after the last
                 do not deviate in the same direction
offset           (deviation after − deviation before) ÷ 2
```

**Clearly** means all three at once: measured below 3/4 or above 4/3 of the
expectation, at least a quarter of a typical month (consumption of the last twelve
months ÷ 12) and at least three spreads of the model (for the heating model its spread
per month, scaled to the length of the interval; for other years the spread between the
years, at least a fifth of the expectation). For the candidate the app computes the
expectations once more without the checked intervals.

Why: a quick check “below 0.5× or above 2×” flagged eleven intervals in a real
household, one of which was a wrong reading. Six were short summer intervals with a
large ratio and few kWh (hence the minimum amount), two were whole years at a different
level (hence the neighbours and the note on the baseline date below). The real case was
a block: three readings too low by the same amount, every interval inside the block
normal. A checker that only looks at neighbouring pairs does not find it. And a
transposed digit in a winter month (900 of 1,845 kWh) is at 1.49× and 0.43×; the quick
check would have missed it.

**Second stage: it does not add up at all.** If the interval before is at most half and
the one after at least twice the expectation (or the other way round), both above the
minimum amount and the spreads, the app asks even without a matching sum. Inside the
block the intervals may then keep deviating in the same direction, just not already back.
That is the case of readings getting more and more wrong: in a made-up follow-up check the
interval before was at 0.23×, the two inside the block at 0.78× and 0.69×, the one after
at 4.08×, and the surplus afterwards was about 2.4 times the shortfall before. No common offset
explains that, so the app suggests no reading; the entry says “Consumption before and
after … does not add up”, with the same buttons.

**In “To do”** it then says “Check the reading of …”, with measured against expected
for the interval before and after and the guess (“probably about 900 m³ too high”); in
the list the reading carries the mark “UNUSUAL”. Three buttons, all with undo:

- **Reading is correct:** the app never asks about this reading again (`is_verified`).
  Changing the reading later cancels that.
- **Only for the bill:** the reading stays for bill and balance (bill check, billing
  year, costs, advance payments), but monthly values, heating model and forecast skip it
  (`is_billing_only`). The consumption from the reading before to the next one is spread
  **by the weather**: per day `a × HDD + c` from the same heating model (evenly for types
  without a heating model). That fits when the supplier billed with an estimated or wrong
  reading: their next bill evens it out, and until then the balance follows theirs.
- **Correct it:** opens the edit dialog with the reading that would fit.

Why a flag of its own rather than “estimated”: `is_estimated` only changes the marker
(an estimated reading measures nothing, the months next to it count as interpolated);
the spreading still goes through it. Estimated readings often come from the supplier
and are usually fine; bridging all of them would have changed monthly values nobody
touched.

**Entered twice:** the same reading on the same day twice gives “Reading of … entered
twice” with “Delete one” (the one with a photo is kept, otherwise the one with a note).

**A different level, not a reading error:** if consumption was consistently far above
or below today for at least a year in a row (every sizeable interval at least a quarter
above or below, together at least one and a half times), with no counter-movement
afterwards (at most a tenth of the days afterwards back at the old level; an interval
only counts if it, or one next to it in a row, also differs from today by at least the
minimum amount, so a short summer interval with a few kWh more is no return), the app
suggests a [baseline date](#effect-of-a-measure-baseline-date) on the day of the change:
“Set baseline date here” (a dialog with the end of the unusual period as the day, to
confirm or change) or “Not now”. Today’s level comes from at least half a year
after the change (for the heating model from four intervals on, even if only a few
readings exist after it). A gradual transition is allowed: right before the time after,
a single interval may lie between the old and today’s level; the day of the change is
then the end of the last interval at the old level. With an effective baseline date only
the time after it counts; if it is at the change, the note is not shown. The time before
a detected change is no yardstick for single readings. With a recurring season the note
uses the mean daily amount of the time after (over a whole year the season evens out).

**Limits.** The check is deliberately cautious: it reports nothing for any
sample household nor for the messy test household as long as all readings are
right. In return it misses small errors. If the heating model fits poorly
(large spread), it asks only about large offsets; a suitable heating limit
makes it more sensitive. For electricity with a strong season (winter below
four fifths of the mean), every winter after moving in counts as a return to
the old, lower level, and then no baseline note comes. In a month that a
reading “only for the bill” bridges, amount and cost do not match: the amount
is spread by the weather, the cost uses the reading (`billed_kwh` says which).

All three entries count in `/api/todo` and in the notifications to Home Assistant, at
every level.

---

## 2. Energy conversion

So that utilities become comparable, Energietracker computes internally in
**kWh** (water stays in m³). The conversion sits in the settings:

| Utility | Formula | Default |
|---|---|---|
| Gas | `kWh = m³ × volume correction factor × calorific value` | `gas_conversion_factors` — dated list, z × Hs per effective date (since v2.5.0; default 11.5 kWh/m³) |
| Heating oil | `kWh = litres × Hu` | `heizoel_kwh_per_l` = 10.0 kWh/L |
| Pellets | `kWh = kg × Hu` | `pellets_kwh_per_kg` = 4.8 kWh/kg |
| Electricity, district heating | already kWh | — |
| Water | stays m³ | — |

The gas factor is printed like this on the gas bill (calorific value × state
number) and should be read off there and maintained in the settings.

---

## 3. Heating degree days (HDD) and temperatures

The central weather reference. Per day with mean outdoor temperature `T_avg` and
heating limit temperature `T_base` (`hdd_base_temp`, default **15 °C**):

```text
HDD_day = max(0, T_base - T_avg)
```

Monthly HDD = sum of the daily values **over the days for which consumption is
available** (since v2.8.0). If the last reading ends on the 15th, the month gets
the degree days of 15 days — up to v2.7 it got those of the whole month, and the
partial month looked like a frugal winter month. `temp_days` counts for how many
of these days a temperature is available. If more than 10 % are missing, the
month is incomplete and does not feed any model.

Intuitively: on a 5 °C day `15 - 5 = 10` HDD accumulate, on a 20 °C day none.
The colder, the more HDD, the more heating energy. Only HDD-relevant utilities
(gas, district heating, heating oil, pellets) use this; electricity and water do
not.

### Where the temperatures come from

Every day carries its source (`source`):

| Source | Meaning | Overwritten by |
|---|---|---|
| `archive` | measured value from the Open-Meteo archive (about six days' delay) | nothing |
| `forecast` | forecast for the last few days and the coming week | the archive value, as soon as it is available |
| `csv`, `manual` | your own import or your own entry | nothing — not even by the fetch |

With `weather_auto_fill` (default on), the app fetches the missing days itself
**once a day when it is opened**, starting from the first reading. Only the
location is transmitted, rounded to two decimal places (about 1 km). Entries
without a source come from versions before v2.8.0. If they are older than the
archive delay, they count as measured. Anyone who wants to replace them anyway —
earlier versions stored forecasts like measured values — ticks "Also replace
existing older values with archive values" (`reload=1`) for the fetch.

### Climate normal

On the first fetch, the app additionally loads the daily means of the last
**30 full calendar years** at the location and stores only the key figures
derived from them (`data/climate_normal.json`): the mean heating degree days per
calendar month, their spread across the years and the spread of the annual
total — for heating limits from 6 to 22 °C in half-degree steps, because degree
days cannot be derived from monthly means. The data is reloaded when the location
moves by more than about 5 km or another year has been completed.

**From 6 °C since v3.7.0** (up to v3.6 from 10 °C): with real data the heating
model fit best far below 15 °C, and for lower heating limits the normal silently
used 10 °C. A normal without the new grid points counts as outdated and is
fetched again once at the next weather sync — one archive request, as the first
time. Until then, and for a heating limit outside the grid, there is no normal
for that limit: the app uses its own temperature history and says so
(`climate_normal_grid`) instead of calculating with the nearest grid limit.

What for: the forecast needs the degree days of a **normal** January even if your
own history starts in May, and the adjustment compares with the long-term mean
instead of with your own twelve months. Without a climate normal, the app
computes with the mean of your own temperature history and says so.

---

## 4. Regression models

Energietracker fits the relationship **HDD → consumption** with five models
(`RegressionService`). Each returns parameters, the coefficient of determination
`R²` and a `valid` flag (enough data points?).

| Model | Form | Minimum points |
|---|---|---|
| **linear** | `y = a·x + b` | 3 |
| **polynomial** | `y = a·x² + b·x + c` | 4 |
| **robust** | linear, Huber-weighted — dampens outliers | 4 |
| **segmented** | `y = b + a · max(0, x - k)` — summer base `b`, heating arm from breakpoint `k` | 8, 4 on each side of the breakpoint |
| **sigmoid** | S-curve (heating signature), TU München/BDEW form | 8, valid from `R² ≥ 0.5` |

Since v2.8.0 the breakpoint model is **continuous**: the base and the heating arm
meet at the breakpoint. Up to v2.7 both arms were fitted separately and jumped
apart there. The breakpoint `k` is searched for (`segmented_split_mode = auto`)
or set to a fixed value.

The sigmoid form (exactly as in the backend `sigmoidPredict`):

```text
kWh = A / (1 + (B / (HDD - θ0))^C) + D     for HDD > θ0
kWh = D                                     otherwise
```

`R²` measures how well the model fits the months shown (`R² = 1`: perfect, `0`:
no better than the mean):

```text
R² = 1 - ( Σ (yi - ŷi)² ) / ( Σ (yi - ȳ)² )
```

This is **goodness of fit**, not predictive quality: a model with more
parameters always fits the same points at least as well.

**Why a model does not hold (since v3.7.0):** `reason` says so —
`too_few_points` (below the minimum points), `poor_fit` (the sigmoid with
`R² < 0.5`) or `degenerate` (no variation in the values). Up to v3.6 every
invalid fit was called "too few data points", even with 50 points. If the model
chosen for the forecast does not hold, the forecast continues without a heating
curve and says so (`regression_invalid`).

**Which months are points** — since v2.8.0 one rule for analysis, adjustment and
forecast (up to v2.7 each place chose slightly differently; the same meter showed
R² 0.42 in the analysis and 0.56 in the forecast):

```text
point  ⇔  days ≥ min_days_period (20)
          and temperatures for ≥ 90 % of the days
          and HDD > min_hdd_regression (5)
          and consumption > 0
          and not before the baseline date
```

Every month carries `regression_point` for this. The analysis scatter plot draws
months outside the fit as hollow points and months before the baseline date in grey.

The default model is selectable in the settings (`forecast_model`); in the
**forecast** and the **analysis correlation chart**, all five models are shown.

---

## 5. Weather adjustment — "consumed more, or just colder?"

### Heating model

For HDD-relevant utilities with meter readings (gas, district heating), the app
computes with a heating model per meter since v2.8.0:

```text
consumption = a × HDD + c × days
```

`a` is the consumption per degree day (heating share), `c` the base load per day
(hot water, cooking). It is fitted without an intercept over all months from the
baseline date onwards that have at least `min_days_period` days, temperatures and
consumption — **summer included**, as it helps determine the base load. At least
8 months. A robustness step takes out months that lie more than 3.5 robust
spreads off (such as a summer with a broken boiler) once and refits. `a` and `c`
are never negative.

**Interpolated months are left out (since v3.7.0, section 1).** If fewer than 8
measured months remain, the model works with the **reading intervals
themselves**: the model is a sum over days and holds for a quarter as well as
for a month. Every interval between two real readings is one point
(consumption, degree days and days of the interval), weighted by `30.4 / days`
— the spread grows with the length, so a quarter counts like three months.
Again at least 8 points; otherwise there is no heating model, and the app says
so instead of silently computing over interpolated months. The API names the
basis (`heat_basis`: `months` or `intervals`).

Heating oil and pellets get no heating model: their monthly values are
**distributed by degree days** from the deliveries. A fit would merely reproduce
the distribution.

### Fields per month

| Field | Formula | Meaning |
|---|---|---|
| `expected_heat` | `a × HDD + c × days` | expectation for exactly this weather and these days |
| `weather_delta_pct` | `(actual - expected_heat) / expected_heat × 100` | over- or under-consumption **for the given weather** (only months with enough days) |
| `hdd_normal` | `HDD_normal[month] × days / days_in_month` | degree days of a normal year for the same days |
| `heat_adjusted` | `actual + a × (hdd_normal - HDD)`, at least `c × days` | weather-adjusted consumption |

`heat_adjusted` converts only the **weather influence according to the model** to
a normal year; whatever else was different about the month (visitors, holiday, a
sticking valve) stays as it is. Below `min_hdd_regression` degree days or below
the base load (holiday, vacancy), the month stays unchanged, and the base load is
the lower limit. The scaling of the entire heating share by
`HDD_normal / HDD_actual` known from VDI 3807 suits annual values; in a
transitional month with 11 instead of 30 degree days it would multiply every
random deviation by 2.7.

This lets you separate a cold winter from real over-consumption: a January with
`weather_delta_pct = +2 %` was normal, even if it consumed three times as much as
October.

**Why "adjusted" equals the raw value (since v3.7.0):** `heat_adjusted_basis`
gives the reason — `summer` (at most `min_hdd_regression` degree days),
`base_load` (consumption below the base load), `normal_is_actual` (no climate
normal and only one year of own temperatures for this month: the "normal" is the
month itself) — otherwise `model`. The last case is a candidate for "adjusted =
raw in many measured months" with real data.

### Heating limit suggestion *(since v3.7.0)*

The heating limit (`hdd_base_temp`, default 15 °C) decides from which daily
temperature degree days count. The analysis recalculates the measured months with
every heating limit from 6 to 18 °C in half degrees and measures the spread like
the quality gate (section 9). It suggests the limit with the smallest spread whose
model also passes the base-load check — but only if it is **clearly** better:

```text
suggestion  ⇔  spread_new ≤ 2/3 × spread_today   (at least 33 % less)
               and spread_today − spread_new ≥ 3 percentage points
               and at least 12 measured, checked months
```

With two years of monthly values the robust spread varies by about a fifth of its
value; a third less is clearly above that, and the percentage points prevent
suggestions between two well-fitting models.

*Since v3.9.0 (GitHub #38, announced as 3.7.1)* the suggested limit must also match the measured
summer: its model may overestimate the summer months of the quality gate (the same
ones for every limit, section 9) by at most 10 % (`summer_ok` in the grid). A single
summer month varies by 10 to 20 % in everyday life, the mean over the summers of
several years much less. For the reporter, the model of the low limit suggested until
then was about a quarter above the measurements in July and August; such a limit is
no longer offered.

The limit applies **per meter** (`hdd_base_temp` on the meter, 6 to 22 °C like
the climate normal grid); the setting stays the default for all meters without
their own — the best limit differs from house to house. Degree days, heating
model, adjustment, balance estimate and forecast of this meter use it. It is never
applied automatically: from the Experienced level with a button in the analysis,
where it can also be undone; below that the sentence with the level note. With real
data the optimum lay far below 15 °C; an invented household of the same shape (base
load 6.5 kWh a day, heating only below 12 °C) shows a base load of 1.25 instead of
6.5 kWh a day at 15 °C and suggests 12 °C (`RealDataFollowUpTest`).

**A calmer grid (since v3.9.0, GitHub #38).** With two years of monthly values the
spread jumps by a quarter and more from one grid point to the next. For the
maintainer the minimum sat at a single limit in a narrow valley, and right there
the robustness step of the fit dropped a quarter of the months: the model fitted
the rest very well, and the look back at May to September was a third above the
measurement with this limit. Since then:

```text
smoothed(g)    = mean spread of all limits up to 1 °C either side of g   (five grid points)
eligible       ⇔ base load check passed, summer overestimated by at most 10 %,
                 May to September together overestimated by at most 10 %,
                 and the fit drops at most one month in ten (at least one) as a real
                 outlier (more than 10 % of a typical month off)
plateau        = adjoining eligible limits, smoothed at most 15 % above the best
suggestion     = the limit of the plateau closest to today's
much better    : smoothed ≤ 2/3 × spread today and at least 3 percentage points less
```

Nearly noise-free months (±2 %) also drop out of the fit when the spread is tiny,
but they lie within everyday noise and do not count as outliers. The plateau's
range is part of the suggestion (`range`): “Between 10.5 and 11.5 °C the model fits
about equally well” (`HeatLimitFollowUpTest`, also on a noisy series and on the
messy test household).

**Where the suggestion appears (since v3.9.0).** Under “To do” on the overview
(agenda `heat_limit`, recommendation of category `modell`) with before and after
and the effect on the expected balance and the advance payment suggestion of the
current contract, with “Apply (this meter only)” and “Not now”. “Not now” hides
exactly this state of the data (the suggested limit and the number of checked
months are part of the recommendation's ID); when a measured month is added, it
comes back. The same note appears on the balance card, in the forecast and in the
workshop when their estimate is uncertain because of a poor fit (`poor_fit`).
Applying works there at every level and can be undone; the analysis with the grid
and the reset stays from Experienced. **Heating oil and pellets** have their own
limit too (they spread their deliveries by the degree days of that limit), and so
do **meter groups** (`hdd_base_temp` on the group: the members use it for the sum,
the expectation of the sum comes from the heating model of the sum). There is a
suggestion for meters with readings and for groups, not for tanks (their months
are spread by degree days).

> **Replaced (deprecated since v2.8.0, still delivered):**
> `weather_adjusted` scaled the **entire** consumption with the HDD ratio — hot
> water included; a September was thus "adjusted" by 54 %. `delta_pct` compared
> with the mean of all months and therefore measured the season (January
> "+58 %"). Both fields remain in the API until the next major version; the
> interface no longer uses them.

### Effect of a measure (baseline date)

If a baseline date is set (F1011), the analysis compares the consumption per degree day
(slope `a` of the linear heating curve) before and after it. Since v2.8.0 with
statistical evidence:

```text
significant  ⇔  |a_after - a_before| / √(se_before² + se_after²) ≥ 1.96
95 % range of the change (delta method):
  Δ% ± 196 × √(se_after² + (a_after/a_before)² × se_before²) / a_before
```

`se` is the standard error of the slope. The interface states whether the
difference is supported or may lie within the noise — with few winter months that
is often the case.

**Without a heating curve (since v3.1.0).** Electricity, water and PV have no
slope per degree day. For them the app compares the daily average per calendar
month before and after the baseline date — only months that exist in both
phases (at least three):

```text
before/after per year = Σ (daily average of the month × days of the month) × 365 / Σ days
Δ%                    = (after − before) / before × 100
95 % range            = Δ% ± 1.96 × spread of the monthly ratios (after/before) / √n × 100
```

The difference is supported when the range does not include 0. The weather is
not adjusted here (`method: "seasonal_mean"`; the heating-curve variant is
called `hdd_slope`).

---

## 6. Forecast

`ForecastService` computes each forecast month with two estimators:

1. **Regression** of the selected model on the **normal** heating degree days of
   the month — from the climate normal, otherwise from your own temperature
   history,
2. **Seasonal profile**: the mean **daily rate** of the same calendar month
   (months with at least `min_days_period` days), times the days of the forecast
   month.

The blend is weighted with the regression `R²`, capped by `blend_max` (default
**0.80**):

```text
w        = min(R², blend_max)
Forecast = w · regression value + (1 - w) · seasonal value
```

Intuitively: if the heating curve explains consumption well (high `R²`), it
counts more strongly — but never more than 80 %. For **non**-HDD-relevant
utilities (electricity, water) the regression is omitted: a pure seasonal
forecast.

**Since v3.7.0 only where the heating curve knows something:** if there is a
measured seasonal value, the regression does not count in months with fewer
degree days than its smallest point (summer; the curve is fitted over heating
months only) and not if the heating model fails the quality gate (section 9) —
then `seasonal_only` applies. With a convex heating curve the line lay at zero in
summer; with weight 0.8 almost all the hot water was missing, about 23 % of the
year with real data, also in the quantity for comparison portals.

Since v3.9.0 the regression also does not count where it is flat: for the
change-point model below the knee (where it computes the fixed level b), for
the sigmoid up to θ₀ (where it computes the base load D). b is fitted to all
months below the knee, the transition months; June, August and September thus
got 80 % of the same level instead of their measured seasonal value.

**If a calendar month is missing from your own history** (anyone who starts in
May has no January yet), it comes from the heating model since v3.9.0
(`heat_model`): a × degree days of the climate normal at the meter's heating
limit + c × days. That holds where the model knows months this warm itself (at
least two of its points with at most 0.5 degree days per day more than the
month's normal). Otherwise the base load c is guessed there, fitted only to
heating and transition months, and the forecast uses the interpolated values of
the same calendar month (`seasonal_interpolated`) if there are any. The same
applies if the heating model fails the quality gate or its base load c lies
below half the mean daily rate of measured summer months (June to August); the
quality gate checks the base load only from two summer months on, this check
already with one. Interpolated values only count from gaps of up to 62 days:
within a gap the consumption is spread by days, not by weather, and a gap over
the winter carries heating consumption into July. If there are none, the month
comes from the heating model; if that is not reliable, with the measured summer
rate (June to August) as the base load if it is above c (`heat_model_summer`).
Without a
heating model it comes from the regression alone (`regression_only`), otherwise
from the daily mean of all months (`filled`).

Up to v3.7 the regression of the monthly totals came first. It is fitted only
over months with degree days and knows no days; the change-point model gave
every month below the knee the same level, that of the transition months (May =
July), and the straight line put summer months at its intercept. Up to v2.7 a
missing month got 0 degree days and the seasonal value 0 — January came out at
less than a tenth of the correct value.
`warnings` reports a short history (`history_short`) and a missing climate
normal (`no_climate_normal`).

**Interpolated months (since v3.7.0)** do not form the seasonal profile. If a
calendar month was never measured and no model helps (or the heating model does
not know months this warm, see above), the forecast uses the
interpolated values for it (`seasonal_interpolated`) — for a garden meter that is
not read in winter, that is better than the mean of the whole year. The warning
`interpolated` names the number of interpolated months and of readings; the daily
mean of all months (`filled`) holds for interpolated ones too. `history_short`
counts only **measured** calendar months: someone who reads twice a year for two
years has 24 monthly values but no measured month. The forecast workshop passes
the warning `interpolated` on; “Same as last year” takes interpolated months of
the previous year from the forecast, like missing ones.

**Annual amount of the last 365 days (since v3.9.0).** Without a weather link
(electricity, water) and without twelve measured calendar months the profile is
a patchwork: measured months from one year, interpolated ones as the mean of all
years. With real electricity data of almost only interpolated months the
forecast was consistently too low in retrospect; in the messy test household four
summer readings lowered the annual amount by over 5 % although consumption stayed
the same, and a year with 25 % more consumption came out as the mean of three
years (−13 %). Then:

```text
annual amount = consumption of the last 365 days (monthly values, the oldest pro rata by days)
month         = profile value × annual amount ÷ Σ profile values of the twelve forecast months
```

Between two readings the total of interpolated months is correct too. The
warning `level_last_year` names amount and period. Heating utilities keep
calculating with weather and heating model; with twelve measured calendar months
the own profile stays. If consumption changes in the middle of the year (new heat
pump), this amount also lags behind by up to a year.

**Share of the heating curve (since v3.9.0).** `blend_weight` is the weight by
model quality, `blend_effective` the part of the annual amount that really comes
from the heating curve. Months below the heating curve's points or with a failed
quality gate use the seasonal profile; up to v3.7 “heating curve share 80 %”
then stood next to “seasonal profile” in every month. A month from the heating
model (`heat_model`) counts fully towards the heating curve.

**Temperature offset** (what-if): a year that is δ warmer has exactly the heating
degree days of the heating limit `T_base - δ`. With a climate normal or your own
temperature history this is exact; only without both is the shift applied
linearly across all days of the month, as before.

### Uncertainty band

Per month and for the year:

```text
σ_month = √( (a × σ_HDD[month])² + σ_residual² )
band    = forecast ± z × σ_month

σ_year  = √( (a × σ_HDD,year)² + Σ σ_residual² )
```

`a` is the consumption per degree day, `σ_HDD` the spread of this month's degree
days over 30 years (climate normal), `σ_HDD,year` that of the annual total — a
cold winter affects all months at once, hence not the sum of the monthly spreads.
`σ_residual` is the noise of the heating model; for utilities without a weather
reference, the spread of the daily rate around the seasonal profile.
`z = confidence_band_sigma`, default **1.28 ≈ 80 %** of years (up to v2.7 the
setting had no effect).

**Since v3.7.0 only with a passed quality gate.** If the heating model fits
poorly (section 9), there is no band (`annual.low/high = null`,
`annual.withheld = "poor_fit"`) and the warning `poor_fit`: with real data the
80 % band hit in none of five look-backs, because the model itself was off. The
forecast workshop then shows neither band nor heating share and a range instead of
the advance payment — insulation and room temperature act on a heating share the
model does not know.

**Too little measured (since v3.7.0).** If the heating model only rests on the
reading intervals, or the quality gate cannot check it (fewer than 5 measured
months), there is no band either (`annual.withheld = "few_measured"`, warning
`few_measured`); balance and workshop give a range (section 10).

**Without a weather reference** (electricity, water) `σ_residual` is the pooled
spread of the daily rate per calendar month across the years, with Σ(n − 1)
degrees of freedom. Below 3 degrees of freedom there is no band
(`annual.withheld = "few_years"`): with one value per calendar month every
deviation is zero — up to v3.6 the band thus had zero width for water and ±1 to 2 %
for electricity with two years. Per month at least the calendar month's own spread
and 10 % of its value apply, the same floor as in anomaly detection (section 9).
The seasonal profile without a weather reference takes the **median** from three
values per calendar month: a holiday month at half its value then does not shift
the forecast; with two values the band of that month carries the disagreement of
the years. For heating utilities it stays the mean — there the value of a calendar
month is mostly the weather of its winter.

### Costs

In addition, the forecast projects the **costs** per month with the working and
base price valid at that time and the advance from the **effective** payment plan
(special payments "with effect" change it). After the end of the last contract,
that contract continues as an assumption — contracts usually renew — and these
months are marked (`contract_assumed`).

---

## 7. Efficiency class

`BenchmarkService` computes the specific heating energy demand:

```text
metric = (Σ heating kWh of the year) / living_area_m²     [kWh / (m²·a)]
```

and classifies it using the band limits (`efficiency_class_thresholds`, default
A+ up to 30, A up to 50, B up to 75, C up to 100, D up to 130, E up to 160, F up to
200, G up to 250, otherwise H). Since v2.10.0 a limit is **inclusive** ("up to
100" is C), as in Annex 10 of the GModG (Building Modernisation Act, formerly
GEG); previously a value exactly on the limit landed one class worse. The
limits stay the same from 1 Jan 2027: Art. 2 no. 54 of the Act of 23 Jul 2026
(BGBl. 2026 I no. 226) only changes the heading of Annex 10 ("to § 86(1)") and
its column header, "to leave the efficiency classes unchanged" (explanatory
memorandum, Bundestag printed paper 21/6278, p. 122).

**Classes only for full years (since v2.10.0).** If the reference year covers
fewer than 360 days, there is no class, and a note says that the figure only
applies to the measured period. Anyone who started in June used to get a dream
class for their half year without a winter.

**Two figures (since v2.10.0).** The house figure above stays as it is:
consumption per m² of living area, as measured. Alongside it there is a
**certificate-style** figure (`certificate`):

```text
AN      = living area × 1.2      (× 1.35 for residential buildings with up to two dwellings and a heated basement,
                                 § 82(2) GModG, formerly GEG; in the app a detached or terraced house)
E       = Σ heat sources: weather-adjusted annual consumption, fuels depending on the procedure (below)
metric  = E / AN  (+ hot water surcharge with decentralised hot water, depending on the procedure)
```

**Which procedure (since v3.5.0).** On 1 Jan 2027 the way a consumption
certificate is calculated changes (§ 82 GModG as amended by Art. 2 no. 32 of
the Act of 23 Jul 2026, BGBl. 2026 I no. 226; in force under Art. 9(2)). The
app calculates the figure **as if the certificate were issued today**: up to
31 Dec 2026 with the previous procedure, from 1 Jan 2027 with the new one —
for every reference year. What counts is the date of issue, not the period of
the data; the Act itself distinguishes old and new certificates that way
(§ 112(3) GModG as amended: certificates "issued before 1 January 2027"). The
API names the procedure in the field `rules`.

```text
                      up to 31 Dec 2026 (geg2024)          from 1 Jan 2027 (gmodg2027)
fuels                 net calorific value: gas × 0.906     gross calorific value: gas as counted (× 1)
                      (announcement of 29 Mar 2021,        ("fuels are to be taken into account at
                      BAnz AT 16.04.2021 B1, section 2)    their gross calorific value", § 82(2) sentence 2)
decentral hot water   + 20 kWh/m²·a (§ 82(2))              + 16 kWh/m²·a (§ 82(2) sentence 3)
data period           36 months (§ 82(4))                  "annually over a period of two years"
                                                           (§ 82(1) sentence 2) = 24 months
```

District heating, heat and heat-pump electricity are not fuels and are never
converted. The app counts **heating oil and pellets** at their net calorific
value (kWh per litre or kg). The announcement gives the factor to the gross
calorific value only by reference to a standard that is not freely available
(DIN V 18599-1, table B.1); from 2027 they therefore stay at the net
calorific value, and the API lists them in `fuel_basis_approx` as an
approximation — the figure is somewhat too low for them. A gas-heated house
with the same consumption gets a figure about 10 % higher from 2027
(1 / 0.906) with the same class limits, so the same home can land in a
different class.

Before 1 Jan 2027 the overview shows under the figure what the new procedure
changes and which figure it would give (API: `upcoming`).

The adjustment runs month by month with the heating model (`heat_adjusted`)
when every month of the year has such a value — the normal case for gas and
district heating with a heating model. Otherwise (heating oil, pellets, heat
pump, months without a model or before a baseline cut) it is adjusted over the
year with the climate normal (heating share only, VDI 3807). Without a climate normal the value
stands as measured and is marked as such. This is **not a consumption
certificate**: that covers the whole period (36 months, from 2027 two years)
with the climate factors of the DWD (German Meteorological Service), from 2027
also normalised to the usage conditions of DIN/TS 18599-10 (§ 82(3) as
amended); the app calculates one reference year and does not reproduce the
normalisation. The settings *Heated basement* and
*Decentralised hot water* and the role *Heat pump (heating electricity)* on an
electricity meter (up to v3.0 the flag *Heating electricity (heat pump)*) feed
into it — with this role, a heat-pump house gets a figure too. Since v3.1.0
**heat** counts as well, but only meters with the role *Home consumption*
([Heat](15-waerme.md#4-efficiency-figure)).

**Since v1.4.0, separated per heat source.** A house usually heats with one
source in reality; summing all heating types would yield a nonsensical class. The
report shows `per_source` (class per source), `primary` (the largest) and
`combined` (the sum — only meaningful with deliberately combined heating
operation such as a pellet base load + a gas peak load).

---

## 8. CO₂

```text
CO2 = consumption × CO2 factor
```

with a utility-specific factor (`co2_gas`, `co2_strom`, …), relative to the unit
in which the app counts. Since v2.10.0 every default has a source:

| Factor | Default | Source |
|---|---|---|
| Gas | 182 g/kWh | BAFA information sheet on CO₂ factors (2026): 201 g/kWh relative to the **net calorific value**; the app counts gas by gross calorific value, hence × 0.906 |
| Electricity | per year, 2015–2025 (2025: 344 g/kWh) | German Environment Agency (UBA), emission factor of the electricity mix (`co2_strom_years`); after the last year its value applies, before that `co2_strom` (380) |
| Heating oil | 266 g/kWh | BAFA, relative to the net calorific value — which is how the app calculates heating oil |
| Pellets | 36 g/kWh | BAFA, CO₂ equivalents including the upstream chain (up to v2.9: 26) |
| District heating | 280 g/kWh | BAFA flat rate; the value of your own network is available from the supplier (up to v2.9: 180) |
| Water | 350 g/m³ | rough guide value without a documented source |

Up to v2.9 a single value applied to electricity for all years, and the gas
factor referred to the net calorific value while the app counts gross-calorific
kWh (+10 %).

**Existing installations keep their figures.** The migration to schema 1.6.0
pins the previous default in every installation that never saved one of these
values (Lesson 36: an update does not change a figure the user has not touched
themselves). The settings then show "Newer default values available" with the
old and the new value — they are only applied at the push of a button. The same
applies to the water reference (127 → 122 L per person and day, BDEW 2024). New
installations start with the new values.

**PV** avoids CO₂ instead of emitting it: generation and feed-in are shown as
"avoided", and only once in the annual report
([PV](12-pv.md#5-co₂-as-avoided)). Since v3.1.0 your own avoidance factor can
apply instead of the electricity mix (`co2_pv_avoided`).

---

## 9. Anomalies

`AnomalyService` flags months whose consumption deviates clearly from the
**expectation for exactly this month**. Since v2.8.0:

| Utility | Expectation |
|---|---|
| Gas, district heating | `expected_heat` from the heating model (section 5) — in summer that is the base load |
| Electricity, water, PV | daily rate of the same calendar month in **other** years (median), in the first year that of the two neighbouring months — times the days of the month |
| Heating oil, pellets | no anomalies: the monthly values are distributed by degree days, so any "deviation" would be an artefact of the distribution |

The month being checked is never part of its own expectation (leave-one-out). Up
to v2.7, a +60 % March with one year of history was compared with a mean that
contained it, and heating utilities got an expectation of 0 in summer — every
summer was an "outlier".

The spread is estimated **robustly** (median and MAD of the residuals instead of
mean and standard deviation — otherwise a single outlier inflates the standard
deviation and hides in it), with a floor of 10 % of the expectation or of a
typical month:

```text
r       = actual - expected
σ       = max( 1.4826 × MAD(r),  0.10 × max(expected, typical month) )
z       = (r - median(r)) / σ
anomaly ⇔ |z| ≥ anomaly_threshold      (default 2.0)
```

The floor prevents a mere +5 % from being reported as "6σ" when the data is very
even: at 2σ, only what goes beyond about 20 % of a typical month is reported. Not
checked are months with fewer than `min_days_period` days, months with a meter
swap, months before the baseline date and, since v3.7.0, **interpolated months**
(section 1) — their value is the daily rate of a longer reading interval, a
"deviation" from it would be an artefact of the spreading; with fewer than 5
usable months, nothing is checked at all.

**Quality gate (since v3.7.0).** The linear heating model fits a curved heating
curve poorly; the detection then mostly measures the model's own error, and an
empty table would not mean "all is well". The app therefore checks two things,
both over measured months only:

```text
ratio            = σ_robust / average measured month   (mean of the checked months)
base load        = expectation / consumption of measured summer months
                   (no degree days at 18 °C, otherwise June to August; at least 2 months;
                   measured as the median of the daily rates)
base load c      = c × days / consumption of the same months   (since v3.9.0, the lower bound)
model_quality    = poor if ratio > 15 % or base load outside 0.5 … 1.5
                   (since v3.9.0 the lower bound uses base load c, the upper bound the base load)
detect_floor     = anomaly_threshold × max(σ_robust, 10 % of the typical month)
```

**Lower bound with the base load itself (since v3.9.0, GitHub #38).** Up to v3.7.0
the lower bound also measured the whole expectation of the summer months. At high
heating limits `c` drops to zero, but the cool summer days get degree days, and
`a × HDD` lifted the expectation back above half of the measurement. For the
reporter the check failed in v3.7.0 between 14 and 16.5 °C and passed again at 17
to 18 °C. The fixed summer reference alone (below, announced as 3.7.1) did not
change that, because the expectation itself is not monotone in the limit: in the
messy test household it then failed from 12.5 to 17.5 °C and passed at 18 °C. Now the lower bound
checks `c` (`base_load.base_ratio`, `base_per_day`), the upper bound still the
whole expectation; the limits that pass form one contiguous range.

**Recurring months (since v3.9.0).** A calendar month reported in the same
direction in at least two years and on the same side of the expectation in every
checked year is a **pattern**, not an event (`anomalies[].pattern`,
`pattern_years`): with a curved heating curve, April and October lie below or
above the straight line every year. The analysis combines them into one line per
month and points to the heating limit; R1 and R4 no longer turn them into
recommendations.

**The same summer for every heating limit (since v3.9.0, GitHub #38, announced as 3.7.1).** Up to v3.7.0
the summer months were those with HDD ≤ `min_hdd_regression` — calculated with the
heating limit of the model under test. With a low limit, shoulder months with real
heating demand thus counted as summer; the reference rose, and a model with too high
a base load passed. Now they are measured months without a single degree day at the
top of the grid (18 °C, `hdd_top` of the monthly row); if there are fewer than two —
the rule in central Europe, almost every July has cool days —, the measured months
June to August, the three warmest in all country profiles. Every heating limit is
checked against the same months (`base_load.reference`, `reference_months`).

With `poor`, the anomaly table says so, names the limit `detect_floor` below
which it detects nothing, for the base load also model and measurement per day,
and points to the heating limit (Settings › Utilities & billing › Physical
constants, from the Experienced level). A well-fitting model detects at 2σ from
20 % of a typical month; above 15 % only from 30 %, one and a half times as
late. 15 % is also the bound ASHRAE Guideline 14 names for calibrated models on
monthly data. The spread is measured against the **mean** of the measured
months, not against the detection's typical month: its median of the
expectations drops the summers of a model with base load 0, and the model would
look about a quarter better than it is. A convex heating curve pushes the base
load `c` almost to zero; the second check catches that even when the spread
stays just below 15 %. Summer consumption varies by 10 to 20 % in everyday
life, well inside the band. The model itself stays unchanged in v3.7.0.

What follows from `poor`: the forecast uses the measured months and shows no band
(section 6), the balance gives a range instead of an advance suggestion
(section 10), the forecast workshop shows neither band nor heating share, and the
analysis — if it finds a clearly better one — suggests another heating limit
(section 5). Since v3.7.0 **R1** also reports nothing, and the annual report makes
no weather-adjusted comparison (no adjusted tile, no “colder or more consumed?”,
“–” instead of the adjusted values in the appendix); a note stands in their place.
If the anomaly table still has rows, the sentence above it says that they may just
as well show the model's error — up to v3.6 it spoke of an empty table even then.

The recommendations build on this: **R1** (over-consumption for the given
weather) measures gas and district heating against the heating model with the
same spread and floor (threshold `recommendation_anomaly_sigma`); **R4** reports
the "high" anomalies of the utilities without a weather reference — PV excluded,
since a high-yield month is no warning sign. **R2** (trend) compares the
weather-adjusted values (`heat_adjusted`) of the last twelve months with the same
calendar months of the previous year as soon as at least nine such pairs exist.
R1 and R3 (summer consumption of the heating) leave interpolated months out; R2
does not — twelve months together are not changed by the spreading, and the
adjustment is additive per month. **Since v3.7.0, R1, R3 and R4 only report
months of the last 24 months**; the anomaly table of the analysis still shows
all of them. An outlier from three years ago is no longer something to act on,
and “Hide for 30 days” should not bring it back every month.

Anomalies are hints, not judgements — a move, a new heat pump or a faulty meter
produce them alike.

---

## 10. Balance to date

For contracts with advances (gas, electricity, district heating), the balance
card computes **by calendar up to today** since v2.8.0, like the annual
statement:

```text
Cost to date       = working price × measured consumption     (up to the last reading)
                   + working price × estimated consumption    (last reading → today)
                   + base price, day-exact, up to today
                   - bonuses up to today
Advances paid      = advances per payment plan, due at the start of the month,
                     up to and including the current month
Balance today      = cost to date - advances paid + special payments (net)
Expected at end    = balance today + estimated remaining cost - remaining advances
```

Up to v2.7, costs **and** advances only counted for months with a reading. Anyone
who had last read the meter in March saw a balance made up of three months in
September, while the bank had long since debited nine advances.

**The estimate** for the gap and for the remainder runs per day: for gas and
district heating with the heating model (`a × HDD + c`, with the measured daily
temperature, for days without a value with the climate normal), otherwise with
the daily rate of the same calendar month. If the most recent measured months (up
to six full ones from the last twelve) lie clearly above or below the model — a
new heating system without a baseline date, changed behaviour — the estimate follows
this level (`projection_factor`, limited to 0.5 to 1.5).
Since v3.7.0 only **measured** months count for this (section 1), and the daily
rate of a calendar month comes from measured months as long as there are any;
only otherwise from interpolated ones. The heating model is the same as in
section 5, so with few readings also the one over the reading intervals.

**The same trust rule as the forecast (since v3.9.0).** The card only estimates
with the heating model when it passes the quality gate and its base load is not
below half the measured summer rate (like the forecast, section 6). Otherwise,
like the forecast, it takes the calendar month: measured, otherwise interpolated
from short gaps; only where there is none, the heating model with the measured
summer rate as base load. In the messy test household June to August used to be
0 kWh (base price only); the measured values were 157 to 187 kWh.

**A width even for a certain estimate (since v3.9.0).** When the heating model
passes the quality gate, the estimate is not “uncertain”, but it is not exact
either: the card names the balance to the euro (“≈”) and a width next to it
(`projection_band`, 80 %):

```text
half width = 1.28 × √( Σ (a × heating factor × σ_HDD,month × share of future days)²
                      + Σ (σ_model × share of estimated days)² ) × price per kWh
```

The weather counts for the days from today, the model's spread for every
estimated day since the last reading. Up to v3.8 every range disappeared once the
quality gate was passed (for example after the heating limit), even with nine
estimated months. The advance suggestion stays.

**Two factors (since v3.7.0).** The factor only acts on the part it comes from:
months whose expectation is less than 30 % heating calibrate the base load
(`projection_factor_base`), the others the heating share
(`projection_factor_heat`) — each with at least two months, otherwise 1, each
limited to 0.5 to 1.5:

```text
factor_base    = Σ consumption ÷ Σ expectation            (months with hardly any heating)
factor_heating = (Σ consumption − factor_base × Σ c × days) ÷ Σ a × HDD   (heating months)
day            = a × HDD × factor_heating + c × factor_base
```

With real data, the single factor up to v3.6 came from summer and shoulder months
and scaled the whole winter estimate along with them. `projection_factor` is now the
effective factor (estimate ÷ estimate without calibration). **If the heating model
fails the quality gate, it stays one factor for the whole:** the split assumes a
model that separates base load and heating correctly — with real data the model's
base load was about a tenth of the measured summer, the base-load factor stuck at
1.5 and the heating share from shoulder months pushed the winter down. The estimate
is uncertain anyway then (`poor_fit`); a clamped partial factor counts as
`factor_clamped` (`factor_part`: `base`, `heat` or `both`). Without a heating model
it stays one factor; the daily rate of a calendar month there is the median from
three values (as in the forecast, section 6).

**Advance suggestion:** if the expected balance deviates noticeably, the card
suggests an advance that evens it out by the bill (since v3.9.0 the last day of
the running billing period, below):

```text
suggestion = max(0, rounded( current advance + expected balance / remaining months ))
```

The breakdown on the card (`energy_cost_to_date`, `base_to_date`,
`bonus_to_date`) adds up to the total; the estimated part is shown below it with
the date of the last reading.

**Uncertain instead of falsely certain (since v3.7.0).** If a factor sticks at
a limit (`factor_clamped`: the recent level lies below 0.5 or above 1.5 of the
model), too little is measured to check the heating model (`few_measured`: it only
rests on the reading intervals, or the quality gate cannot check it), or the
heating model fails the quality gate (`poor_fit`), the app does not know whether
its estimate holds. There is then no single suggestion (`suggested_advance = null`)
but `projection_uncertain` with reason and range. The same estimated energy is
calculated with other assumptions:

```text
factor clamped:     with the unclamped factors and with 1 (the recent level fully or not at all)
too little measured: × 0.5 and × 1.5 (the whole calibration range)
poor model:         every assumption additionally × (1 ± ratio of the quality gate);
                    if the base-load check fails, also with the measured summer base load
                    (`base_load_per_day`), since otherwise the model puts summer days near zero
range               = expected balance + (energy_assumption − estimated energy), smallest to largest
advance             = current advance + range / remaining months
```

Without a heating model (electricity, water) there is neither a quality gate nor a
heating share carried forward over the year; a calendar month never measured takes
the daily rate of its reading interval. If nothing is left to estimate (billed,
ended: no estimated quantity), the estimate counts as certain — decided by quantity,
not by euros, so that a contract without a unit price does not silently count as
certain; an advance range with equal ends (“between 0 € and 0 €”) is dropped.
Contract status, summary (also for Home Assistant), balance card, annual report and
workshop show the same flag; where it is set, only the range appears instead of an
amount (balance card, “Expected on …” under costs & contracts, contracts table).

With real data the suggestion with a clamped factor was about a third too low;
following it would have meant paying about a quarter of the annual costs on top,
and the annual report made it task no. 1. Card, annual report (a note instead of
a task; the status light takes the unfavourable end of the range and, while an
estimate is uncertain, shows no “Advance payments fit”) and forecast workshop now
show the range with the reason; the explainer “Where does my money go?” gives cost
and result as a range.

Up to v3.7 workshop and card calculated the balance differently. Since v3.9.0
there is one projection for both ([below](#one-projection-for-balance-and-workshop-since-v390)).

**Superseded (since v3.7.0).** If a follow-up contract begins while a contract
is still running (open or with an end after that), the follow-up contract
applies from its start (latest start wins). Advance payments, standing charge and
balance of the old contract end the day before (`superseded_on`); its stored end
stays as it is. Up to v3.6 its advance payments kept counting in the balance up to
today — the balance was about one annual amount too favourable. This also holds
for a follow-up contract lying **entirely inside the old one**: the old one does not
resume afterwards; after the end of the follow-up contract, that one continues as an
assumption (§ 309 No. 9 BGB). Only a contract of the same meter or group can
supersede — two meters with their own contracts (dual supply) and shadow contracts
supersede nothing. Contracts without a successor and gapless chains stay unchanged.

**Price missing (since v3.7.0).** If a contract has no unit price, costs and
balance contain only standing charge and advance payments. Contract status and
billing year both say so (`price_missing`) instead of showing two different,
equally wrong balances.

### One projection for balance and workshop *(since v3.9.0)*

Balance card, Costs & contracts, annual report, “Where does my money go?” and the
forecast workshop calculate from the same projection with the same billing date.
The period is the running one of the billing periods (cut-off date
`billing_cycle_anchor_<utility>`, bills, supplier switches; without a cut-off
date the calendar year), also without a captured bill:

```text
balance from  = max(contract start, period start), after a bill the day after it
balance to    = min(contract end, period end)
billing date  = last day of the period (settle_on); if the contract ends earlier without
                a successor contract from the same supplier, its last day (final bill)
workshop      = sum of the card balances; the scenario only changes the days from today to the bill
```

One date for every view: the **last day** of the period (31.12.2026), not the
day after. A superseded contract ends on the day before its successor. In a
period that is only partly covered, only additional advance payments count among
the special payments; refunds and back payments settle an earlier bill. Up to
v3.8 the card counted from the contract start without a captured bill: in the
messy test household it showed about €1,163 credit as of 01.01.2027 next to €357
as of 31.12.2026 in the workshop, outside the card's range. Contracts outside the
running period (billed, past) still calculate over their own term.

**No catch-up amount shortly before the bill.** With fewer than three advance
payments left until the bill (`months_left`), the card suggests no catch-up
amount (`suggestion_withheld: "short"`): putting the shortfall on one or two
payments multiplies them (test household electricity: €208 instead of €75), and
the bill settles the rest anyway. Instead it names the advance that fits going
forward (`advance_ongoing`):

```text
ongoing = rounded( estimated cost of the 12 months after the bill / installments per year )
```

Installments per year: 12 for a monthly advance, 6 every two months, 4 every
three months. *(since v3.9.0)* With a rhythm, suggestion and ongoing advance apply
per installment, and the texts name the rhythm (“about €152 instead of €165 every
2 months”); up to v3.8 a monthly amount stood next to the amount per due date.

### Day-exact like the bill (since v2.9.0)

Contract and prices apply **from their own day**, not from the first of the
month. A month is split at every contract start and end and at every effective
date of the working prices, base prices and advances:

```text
Working price  per segment: consumption on the segment's days × price of that day
Base price     monthly amount × days of the segment / days of the month
Advance        monthly amount on the first contract day of the month,
               pro rata to the contract days in the month
```

Up to v2.8 the contract valid on the first of the month applied to the whole
month: a switch on the 15th charged all of June at the old price, and a price
increase on 15 March only took effect in April. If two contracts fall within one
month, the monthly row belongs to the one with the most days; both parts are
listed in `contract_parts`, and each contract's balance counts only its own
part.

**Without cancellation a contract runs on.** If a contract ends without a
successor starting, the app keeps calculating with its last prices and advances
(`contract_assumed`) — just as the supplier does. Up to v2.8, consumption after
a forgotten contract end cost €0. Anyone who has cancelled clears the "Renews
unless cancelled" checkbox on the contract (`auto_renews: false`). Since March
2022 a contract that runs on can be cancelled any time with at most one month's
notice (§ 309 Nr. 9 BGB); the balance card says so, and the balance runs up to
the next billing date.

### Cancellation deadline and reminder (since v2.9.0)

```text
Cancel by (fixed term)   = contract end − notice period
Switch date (any time)   = today + notice period, to month end or to any day
Switch date (renewed)    = today + min(notice period, 1 month)
```

Notice periods can be given in months, weeks or days (`notice_period_months` or
`notice_period_days`), plus the notice mode (`notice_mode`: at the end of the
term, any time at month end, any time to any day — for example default supply,
German *Grundversorgung*, with two weeks' notice). The three reminder levels
(`contract_remind_days_1/2/3`, default 90/30/1 days) count down to the
**cancellation deadline**, no longer to the contract end: with one month's
notice, two of the three reminders used to arrive after the deadline. If the
deadline has been missed, the card says so (`cancel_missed`). A price increase
entered for the future appears as a note — in Germany together with the special
right to cancel (*Sonderkündigungsrecht*) effective on the day of the increase
(§ 41 Abs. 5 EnWG).

---

## 11. Further calculations *(v3.1.0)*

In brief, with the place of the full explanation:

```text
group contract       member cost = consumption × its own unit price;
                     standing charge, advance, bonuses only with the first member
                     blended price (forecast, switch) = Σ price × share of consumption over the last 12 months
§ 14a module 1       fixed costs per month = standing charge − reduction per year / 12   (day-exact)
dynamic check        unit price per month = wholesale monthly average × (1 + VAT) + mark-up
charging record      contract price: kWh × (energy cost / kWh of the paying meter)
                                     + standing charge × kWh wall box / kWh parent meter
                     flat rate:      kWh × flat rate
seasonal perf.       SPF = Σ heat / Σ electricity, only months with both values
battery              efficiency = discharged / charged;  full cycles = charged / capacity
plug-in solar        self-consumption = generation × assumed share   (without a feed-in meter)
payback              benefit = self-consumption × unit price + feed-in revenue since commissioning
                     years   = investment / benefit of the last 12 months
credit notes         replace kWh × feed-in tariff in their period, spread day by day
benchmark            Δ% = (annual value − own reference value) / reference value × 100   (full years only)
                     traffic light (v3.6.0): good Δ ≤ 0 · above 0 < Δ ≤ 10 · well above Δ > 10
                     water: l/person·day = m³ × 1000 / days / people;
                            index = l / benchmark × 100, traffic light by the saving index bands
time series          per day: last meter reading or sum of the intervals
```

More: [Electricity](02-strom.md) (group contract, § 14a, dynamic tariffs),
[Charging record](../anleitungen/ladestrom-nachweis.md),
[Heat §7](15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310),
[PV §7–11](12-pv.md#7-battery-v310),
[Time series from portals](../anleitungen/daten-aus-portalen.md) and the
benchmark in the [API reference](../referenz/api.md#benchmark-get-apibenchmarkscomparison-v310).

---

[← Compendium index](../README.md) ·
[Gas →](01-gas.md)
