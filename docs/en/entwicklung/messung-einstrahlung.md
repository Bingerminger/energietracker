# Measurement: which normal predicts next year's solar radiation? *(v3.13.0)*

**Question:** the PV forecast works with the expected solar radiation per month. Is the flat mean of the last 30
years good enough for that, or is the next year systematically off?

**Answer:** **The flat 30-year mean is on average 3.4 % too low**, because solar radiation in Central Europe has been
rising since the 1980s (brightening). **A linear trend of the annual sums over the same 30 years, carried forward to
the next year, does best** (annual error 3.2 % instead of 4.1 %, hardly too low any more). Since v3.13.0 the app
stores it in the climate normal (`rad_month_trend`, `rad_trend_factor`, [data model](../referenz/datenmodell.md)).

## Data and method

- **Source:** Open-Meteo archive (ERA5), daily sum `shortwave_radiation_sum` 1971 to 2025, data under
  [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) from [Open-Meteo](https://open-meteo.com/). The raw data is
  not in the repository.
- **Places:** eight large cities, coordinates of the city centre: Berlin, Hamburg, Munich, Cologne, Frankfurt am Main,
  Stuttgart, Vienna, Zurich.
- **Rolling:** for each target year 2001 to 2025 only with the years before it; per place and year the twelve monthly
  sums. 200 cases.
- **Error measures:** annual error (sum of the estimate against the annual sum, absolute), bias (signed), WAPE of the
  months.

## Result (target years 2001 to 2025)

| Estimator | Annual error | Bias | WAPE months | largest annual error |
|---|---|---|---|---|
| flat mean of the last 30 years (until v3.12) | 4.1 % | −3.4 % | 8.6 % | 14.6 % |
| mean of the last 15 years | 3.3 % | −2.0 % | 8.5 % | 13.0 % |
| mean of the last 10 years | 3.4 % | −1.6 % | 8.6 % | 13.5 % |
| **trend over 30 years, carried forward** | **3.2 %** | **−0.5 %** | **8.4 %** | **12.6 %** |
| trend over 30 years, damped by half | 3.3 % | −1.9 % | 8.4 % | 13.6 % |
| trend over 15 years | 3.4 % | −0.6 % | 8.6 % | 14.6 % |
| previous year (like the seasonal profile after one year) | 4.3 % | −0.3 % | 11.7 % | 16.0 % |
| mean of the two previous years | 3.7 % | −0.4 % | 10.1 % | 14.4 % |
| ½ flat mean + ½ two previous years | 3.3 % | −1.9 % | 8.9 % | 14.5 % |
| ½ trend + ½ two previous years | 3.3 % | −0.5 % | 8.9 % | 13.5 % |

**In the last ten target years (2016 to 2025)** the gap is larger: flat 4.7 % (bias −4.2 %), trend 3.5 % (−1.5 %). The
trend of the annual sum 1996 to 2025 lies between +0.1 % (Zurich) and +0.5 % (Berlin) per year; the last ten years
were 0.8 to 4.6 % above the 30-year mean. The trend factor was between 1.006 and 1.061 in every case; the app caps it
at ± 10 %.

## What the app does with it

- **Normal with trend:** monthly sums of the normal × the factor of the annual-sum trend, carried forward to the year
  after the reference period. Below ten complete years, no trend.
- **No blending with the measured years when the trend is there:** blending with the previous years did not improve
  the trend (annual 3.3 instead of 3.2 %) and made the months worse (8.9 instead of 8.4 %). Without a trend in the
  normal it does help (3.3 instead of 4.1 %), so the PV forecast then blends the measured months by half.
- **Limit:** the trend is a straight line over 30 years. If the development reverses (like the “dimming” until the
  1980s), it is too high for a few years.
