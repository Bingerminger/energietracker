# Fundamentals & methodology

**English** · [Deutsch](../../functional/00-overview.md)

[← Compendium index](../README.md)

This chapter explains the calculation cores that apply to *all* utilities:
consumption distribution, heating degree days, regression, weather adjustment,
forecast, efficiency class. All formulas are checked against the source code.

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
| Gas | `kWh = m³ × calorific value × state number` | `gas_conversion_factor` = 11.5 kWh/m³ |
| Heating oil | `kWh = litres × Hu` | `heizoel_kwh_per_l` = 10.0 kWh/L |
| Pellets | `kWh = kg × Hu` | `pellets_kwh_per_kg` = 4.8 kWh/kg |
| Electricity, district heating | already kWh | — |
| Water | stays m³ | — |

The gas factor is printed like this on the gas bill (calorific value × state
number) and should be read off there and maintained in the settings.

---

## 3. Heating degree days (HDD)

The central weather reference. Per day with mean outdoor temperature `T_avg` and
heating limit temperature `T_base` (`hdd_base_temp`, default **15 °C**):

```text
HDD_day = max(0, T_base - T_avg)
```

Monthly HDD = sum of the daily values. Intuitively: on a 5 °C day `15 - 5 = 10`
HDD accumulate, on a 20 °C day none. The colder, the more HDD, the more heating
energy. Only HDD-relevant utilities (gas, district heating, heating oil, pellets)
use this; electricity and water do not.

---

## 4. Regression models

Energietracker fits the relationship **HDD → consumption** with five models
(`RegressionService`). Each returns parameters, the coefficient of determination
`R²` and a `valid` flag (enough data points?).

| Model | Form | Use |
|---|---|---|
| **linear** | `y = a·x + b` | base case, robust from 3 points |
| **polynomial** | `y = a·x² + b·x + c` | slight curvature, from 4 points |
| **robust** | linear, Huber-weighted | dampens outliers |
| **segmented** | breakpoint: summer base + heating arm | heating/summer operation separated; breakpoint `auto` or fixed |
| **sigmoid** | S-curve (heating signature) | saturating heating curve, TU München/BDEW form |

The sigmoid form (exactly as in the backend `sigmoidPredict`):

```text
kWh = A / (1 + (B / (HDD - θ0))^C) + D     for HDD > θ0
kWh = D                                     otherwise
```

`R²` measures how well the model explains the scatter (`R² = 1`: perfect, `0`: no
better than the mean):

```text
R² = 1 - ( Σ (yi - ŷi)² ) / ( Σ (yi - ȳ)² )
```

The default model is selectable in the settings (`forecast_model`); in the
**forecast** and the **analysis correlation chart**, all five models are shown.

---

## 5. Weather adjustment — "consumed more, or just colder?"

For HDD-relevant utilities, the following is reported per month:

- **`expected_hgt`** — the consumption *expected* by the regression model for the
  month's HDD,
- **`weather_adjusted`** — the consumption normalised to the *long-term*
  calendar-month HDD (following VDI 3807 logic),
- **`delta_pct`** — the percentage deviation of actual vs. expected:

```text
delta_pct = (actual - expected) / expected × 100
```

This lets you separate a cold winter from real over-consumption. Low-load months
(barely any HDD) are hidden to avoid division-by-almost-zero artefacts.

---

## 6. Forecast (12 months)

`ForecastService` blends two estimators:

1. **Regression** on the expected future monthly HDD (from the long-term seasonal
   profile of the temperatures),
2. **pure seasonal profile** of consumption (monthly mean of the history).

The blend is weighted with the regression `R²`, capped by `blend_max` (default
**0.80**):

```text
w        = min(R², blend_max)
Forecast = w · regression value + (1 - w) · seasonal value
```

Intuitively: if the heating signature explains consumption well (high `R²`), the
regression counts more strongly — but never more than 80 %, so that a single good
year does not dominate the forecast. For **non**-HDD-relevant utilities
(electricity, water) the regression is omitted entirely: a pure seasonal forecast.

In addition, the forecast projects the **costs** of open contracts up to the next
settlement date (`billing_cycle_anchor_<utility>`, format `DD-MM` in the UI)
including the advance and the running balance.

---

## 7. Efficiency class

`BenchmarkService` computes the specific heating energy demand:

```text
metric = (Σ heating kWh of the year) / living_area_m²     [kWh / (m²·a)]
```

and classifies it using the band limits (`efficiency_class_thresholds`, default
A+ < 30, A < 50, B < 75, C < 100, D < 130, E < 160, F < 200, G < 250, otherwise H).

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

with a utility-specific factor (`co2_gas`, `co2_strom`, …). The defaults are
**[Unverified]** rough guide values and should be adjusted in the settings to
your own source (electricity tariff mix, heating-oil standard).

---

## 9. Anomalies

`AnomalyService` flags months whose consumption deviates by more than
`recommendation_anomaly_sigma` standard deviations from the expected value
(z-score):

```text
z = (actual - mean) / standard deviation
```

Anomalies are hints, not judgements — a move, a new heat pump or a faulty meter
produce them alike.

---

[← Compendium index](../README.md) ·
[Gas →](01-gas.md)
