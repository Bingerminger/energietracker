# Fundamentals & methodology

**English** · [Deutsch](../../functional/00-overview.md)

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

---

## 2. Energy conversion

So that utilities become comparable, Energietracker computes internally in
**kWh** (water stays in m³). The conversion sits in the settings:

| Utility | Formula | Default |
|---|---|---|
| Gas | `kWh = m³ × volume correction factor × calorific value` | `gas_conversion_factors` — dated list, z × Hs per cut-off date (since v2.5.0; default 11.5 kWh/m³) |
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
total — for heating limits from 10 to 22 °C in half-degree steps, because degree
days cannot be derived from monthly means. The data is reloaded when the location
moves by more than about 5 km or another year has been completed.

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

**Which months are points** — since v2.8.0 one rule for analysis, adjustment and
forecast (up to v2.7 each place chose slightly differently; the same meter showed
R² 0.42 in the analysis and 0.56 in the forecast):

```text
point  ⇔  days ≥ min_days_period (20)
          and temperatures for ≥ 90 % of the days
          and HDD > min_hdd_regression (5)
          and consumption > 0
          and not before the cut-off
```

Every month carries `regression_point` for this. The analysis scatter plot draws
months outside the fit as hollow points and months before the cut-off in grey.

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
cut-off onwards that have at least `min_days_period` days, temperatures and
consumption — **summer included**, as it helps determine the base load. At least
8 months. A robustness step takes out months that lie more than 3.5 robust
spreads off (such as a summer with a broken boiler) once and refits. `a` and `c`
are never negative.

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

> **Replaced (deprecated since v2.8.0, still delivered):**
> `weather_adjusted` scaled the **entire** consumption with the HDD ratio — hot
> water included; a September was thus "adjusted" by 54 %. `delta_pct` compared
> with the mean of all months and therefore measured the season (January
> "+58 %"). Both fields remain in the API until the next major version; the
> interface no longer uses them.

### Effect of a measure (cut-off)

If a cut-off is set (F1011), the analysis compares the consumption per degree day
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

**If a calendar month is missing from your own history** (anyone who starts in
May has no January yet), it comes from the model alone (`regression_only`),
otherwise from the heating model (`heat_model`), otherwise from the daily mean of
all months (`filled`). Up to v2.7 it got 0 degree days and the seasonal value 0 —
January came out at less than a tenth of the correct value. `warnings` reports a
short history (`history_short`) and a missing climate normal
(`no_climate_normal`).

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
100" is C), as in GEG Annex 10; previously a value exactly on the limit landed
one class worse.

**Classes only for full years (since v2.10.0).** If the reference year covers
fewer than 360 days, there is no class, and a note says that the figure only
applies to the measured period. Anyone who started in June used to get a dream
class for their half year without a winter.

**Two figures (since v2.10.0).** The house figure above stays as it is:
consumption per m² of living area, as measured. Alongside it there is a
**certificate-style** figure (`certificate`):

```text
AN      = living area × 1.2      (× 1.35 for a single/two-family or terraced house with heated basement, § 82 GEG)
E       = Σ heat sources: weather-adjusted annual consumption,
          gas × 0.906 (gross → net calorific value)
metric  = E / AN  (+ 20 kWh/m²·a with decentralised hot water)
```

The adjustment runs month by month with the heating model (`heat_adjusted`)
when every month of the year has such a value — the normal case for gas and
district heating with a heating model. Otherwise (heating oil, pellets, heat
pump, months without a model or before a baseline cut) it is adjusted over the
year with the climate normal (heating share only, VDI 3807). Without a climate normal the value
stands as measured and is marked as such. This is **not a consumption
certificate**: that requires 36 months and the climate factors of the DWD
(German Meteorological Service). The settings *Heated basement* and
*Decentralised hot water* and the flag *Heating electricity (heat pump)* on an
electricity meter feed into it — with the flag, a heat-pump house gets a figure
too.

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
([PV](12-pv.md#5-co₂-as-avoided)).

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
swap and months before the cut-off; with fewer than 5 usable months, nothing is
checked at all.

The recommendations build on this: **R1** (over-consumption for the given
weather) measures gas and district heating against the heating model with the
same spread and floor (threshold `recommendation_anomaly_sigma`); **R4** reports
the "high" anomalies of the utilities without a weather reference — PV excluded,
since a high-yield month is no warning sign. **R2** (trend) compares the
weather-adjusted values (`heat_adjusted`) of the last twelve months with the same
calendar months of the previous year as soon as at least nine such pairs exist.

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
new heating system without a cut-off, changed behaviour — the estimate follows
this level (`projection_factor`, limited to 0.5 to 1.5).

**Advance suggestion:** if the expected balance deviates noticeably, the card
suggests an advance that evens it out by the end of the contract:

```text
suggestion = max(0, rounded( current advance + expected balance / remaining months ))
```

The breakdown on the card (`energy_cost_to_date`, `base_to_date`,
`bonus_to_date`) adds up to the total; the estimated part is shown below it with
the date of the last reading.

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

[← Compendium index](../README.md) ·
[Gas →](01-gas.md)
