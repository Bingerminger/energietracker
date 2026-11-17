# Measurement: which normal predicts next year's heating degree days? *(v3.13.0)*

**Question:** the forecast of the heat pump by the weather works with the expected heating degree days per month. Is
the flat mean of the last 30 years good enough for that, or is the next year systematically off?

**Answer:** **The flat 30-year mean is on average 9 to 12 % too high** (too cold), because Germany has been getting
warmer for decades. **A linear trend of the annual mean temperature over the same 30 years, carried forward to the
next year, does best** (bias + 1.6 to + 2.6 % depending on the heating limit): a year warmer by ΔT has the degree days
of a heating limit lower by ΔT. Since v3.13.0 the app stores the trend in the climate normal (`temp_trend`,
`temp_trend_slope`, `temp_trend_center`, [data model](../referenz/datenmodell.md)); **only the heat pump uses it**, gas
and all other utilities keep the flat mean.

## Data and method

- **Source:** Deutscher Wetterdienst (DWD), Climate Data Center, daily values (KL), daily mean air temperature, up to
  the end of 2025. Data basis: Deutscher Wetterdienst, used under the
  [CDC terms of use](https://opendata.dwd.de/climate_environment/CDC/Nutzungsbedingungen_German.pdf) (German)
  ([CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)). Raw data is not in
  the repository.
- **Places:** ten stations: Berlin-Tempelhof, Dresden-Klotzsche, Frankfurt am Main, Hamburg-Fuhlsbüttel, Hannover,
  Köln/Bonn, Leipzig/Halle, München-Stadt, Nürnberg, Stuttgart-Echterdingen.
- **Rolling:** for every target year 2001 to 2025 only with the 30 years before; per place and year the twelve monthly
  sums of heating degree days (sum of max(0, limit − daily mean)). 249 cases (one station year incomplete).
- **Error measures:** annual error (sum of the estimate against the annual sum, absolute), bias (signed, positive = too
  many degree days), WAPE of the months.

## Result (target years 2001 to 2025)

| Estimator | Limit 15 °C: annual error | Bias | WAPE months | Limit 12 °C: annual error | Bias |
|---|---|---|---|---|---|
| flat mean of the last 30 years (up to v3.12) | 10.8 % | + 9.0 % | 19.3 % | 14.1 % | + 11.6 % |
| mean of the last 15 years | 8.7 % | + 5.4 % | 18.6 % | 11.8 % | + 7.2 % |
| mean of the last 10 years | 7.9 % | + 4.1 % | 18.4 % | 10.8 % | + 5.6 % |
| offset = mean of the last 10 years − mean of the 30 | 7.9 % | + 4.2 % | 18.1 % | 10.8 % | + 5.7 % |
| trend of the degree days themselves, as a factor | 7.5 % | + 1.4 % | 18.1 % | 10.5 % | + 2.4 % |
| **trend of the temperature, carried forward** | **7.4 %** | **+ 1.6 %** | **17.8 %** | **10.2 %** | **+ 2.6 %** |
| trend of the temperature, damped by half | 8.3 % | + 5.2 % | 18.3 % | 11.3 % | + 7.0 % |

**In the last ten target years (2016 to 2025)** the gap is larger: at the limit 15 °C flat 12.1 % (bias + 11.6 %),
trend 7.5 % (+ 4.0 %). The carried-forward trend was between about 0.4 and 1.1 K in every case; the app caps it at
± 2 K. At the limit 13 °C: flat 12.7 % (+ 10.5 %), trend 9.1 % (+ 2.1 %).

## What the app does with it

- **Normal with trend, per year:** degree days of the heating limit − slope × (year − middle of the 30 years), for every
  forecast month and every estimated day of the balance card. Below ten complete years, no trend, nor on a normal whose
  period is shorter than ten years. The offset is measured for the year after the period; for the year after that the
  app carries the line one more year forward.
- **Not beyond the grid:** the heating limit never moves below 6 °C, where the normal ends.
- **Blending with the seasonal profile stays** as for gas: by the coefficient of determination of the heating model, at
  most 0.8.
- **Limit:** the trend is a straight line over 30 years. Even with it the following year was recently still too cold
  (2016 to 2025 bias + 4 %); the weather of a single year remains (median annual error about 6 %).
