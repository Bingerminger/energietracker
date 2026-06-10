# Glossary & formula collection

**English** · [Deutsch](../../functional/09-glossar.md)

[← Scenario: own home](08-szenario-eigenheim.md) · [Compendium index](../README.md)

A compact reference of all terms and formulas. The detailed derivation is in
[Fundamentals & methodology](00-overview.md).

> Formulas are written as plain-text code blocks so that they are displayed
> identically and correctly everywhere (GitHub, editor, viewer).

---

## Terms

| Term | Meaning |
|---|---|
| **Cumulative** | Recording via continuous meter readings (gas, electricity, water, district heating). |
| **Delivery-based** | Recording via fuel deliveries instead of a meter (heating oil, pellets). |
| **HDD (heating degree days)** | A measure of "heating demand due to cold" per day/month. |
| **Heating limit temperature** | The outdoor temperature above which heating starts (`hdd_base_temp`, default 15 °C). |
| **Heating signature** | The regression relationship HDD → consumption. |
| **Weather adjustment** | Consumption normalised to the long-term monthly HDD (VDI 3807 logic). |
| **R²** | Coefficient of determination: the share of explained scatter (0…1). |
| **Seasonal profile** | The monthly mean of consumption over the history. |
| **Blend** | The R²-weighted mix of regression × seasonal profile in the forecast. |
| **Balance** | Advances paid − actual costs. |
| **Shadow contract** | A hypothetical tariff for "what would that have cost?" — without affecting the balance/forecast. |
| **Special payment** | F1003: a refund/back-payment or an additional advance payment. Balance = costs - advances + (Σ refund - Σ back-payment - Σ advance payment). "with effect" additionally sets the future advance. Gas/electricity/district heating only. |
| **Meter-reading capture** | F1004 (v1.6.0): the central view `#/zaehlerstaende` for quickly recording all cumulative meters on site in one pass. Gas/electricity/water/district heating only — heating oil/pellets use deliveries. |
| **Efficiency class** | The kWh/m²·a classification of heating energy (A+…H), per source since v1.4.0. |
| **Base load** | The weather-independent base (hot water, standby). |
| **Anomaly** | A month with a z-score deviation above the threshold. |
| **Tank stock curve** | The modelled (not measured) remaining stock for oil/pellets. |
| **Recurrence** | The repeat rule of an appointment (annual, …). |

---

## Formulas (checked against the code)

**Daily consumption (cumulative):**

```text
kWh_day = (c2 - c1) / (t2 - t1)
```

**Meter swap:**

```text
consumption = (final_old - prev) + (curr - initial_new)
```

**Energy conversion:**

```text
Gas:          kWh = m³ × calorific factor
Heating oil:  kWh = litres × Hu
Pellets:      kWh = kg × Hu
```

**Heating degree days:**

```text
HDD_day = max(0, T_base - T_avg)
```

**Coefficient of determination:**

```text
R² = 1 - ( Σ (yi - ŷi)² ) / ( Σ (yi - ȳ)² )
```

**Sigmoid heating signature** (backend `sigmoidPredict`):

```text
kWh = A / (1 + (B / (HDD - θ0))^C) + D     for HDD > θ0
kWh = D                                     otherwise
```

**Forecast blend:**

```text
w        = min(R², blend_max)
forecast = w · regression value + (1 - w) · seasonal value
```

**Efficiency metric (per heat source):**

```text
metric = (Σ heating kWh of the year) / living_area_m²     [kWh / (m²·a)]
```

**Delivery energy balance (oil/pellets):**

```text
total kWh = (initial_stock + Σ deliveries) × Hu
```

**Daily draw of the stock curve (v1.4.0):**

```text
rate      = (Σ deliveries without the last) · (1 - s)
            / Σ HDD in the window [first .. last delivery]

stock_day = max(0, stock_prevday + delivery_day
                   - (base_load_L + rate · HDD_day))
```

**Delivery costs (v1.4.2, total-amount precedence):**

```text
cost = total_eur                          if set
cost = quantity × unit_price_cents / 100  otherwise
```

**Balance:**

```text
balance = Σ advances - Σ costs
```

**Water saving index:**

```text
saving index = (litres per person per day) / reference × 100
```

**CO₂** *(default factors [Unverified])*:

```text
CO2 = consumption × CO2 factor
```

**Z-score (anomaly):**

```text
z = (actual - mean) / standard deviation
```

---

## Default values (selection)

| Key | Default | Unit |
|---|---|---|
| `gas_conversion_factor` | 11.5 | kWh/m³ |
| `heizoel_kwh_per_l` | 10.0 | kWh/L |
| `pellets_kwh_per_kg` | 4.8 | kWh/kg |
| `hdd_base_temp` | 15.0 | °C |
| `blend_max` | 0.80 | — |
| `delivery_baseload_share` | 0.15 | share |
| `forecast_months` | 12 | months |
| `wohnflaeche_m2` | 100 | m² |
| `co2_gas / _strom / _wasser` | 201 / 380 / 350 | g/kWh or g/m³ *(Unverified)* |

---

[← Scenario: own home](08-szenario-eigenheim.md) ·
[Compendium index](../README.md)
