# Glossary & formula collection

**English** · [Deutsch](../../verstehen/09-glossar.md)

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
| **Weather adjustment** | Consumption converted to a normal year: since v2.8.0 only the weather influence according to the heating model is converted; the base load and the month's own deviation stay (`heat_adjusted`). |
| **Heating model** | v2.8.0: `consumption = a × HDD + c × days` per meter — `a` consumption per degree day, `c` base load per day. Provides the expectation for every month (`expected_heat`), summer included. |
| **Climate normal** | v2.8.0: mean and spread of the heating degree days per calendar month from 30 years of daily means at the location (Open-Meteo archive). Basis for the forecast, the adjustment and the uncertainty band. |
| **Uncertainty band** | v2.8.0: the range within which consumption lies in 80 % of years (`confidence_band_sigma`) — derived from the spread of the winters and the noise of the model. |
| **R²** | Coefficient of determination: the share of explained scatter (0…1). |
| **Seasonal profile** | The monthly mean of consumption over the history. |
| **Blend** | The R²-weighted mix of regression × seasonal profile in the forecast. |
| **Balance** | Actual costs − advances paid (plus the net of special payments). **Positive = back-payment looming, negative = credit** — that is how the API and the CSV export calculate. Since v2.13.0 the interface shows the customer's side: "credit" or "additional payment" without a sign, in tables + = credit. |
| **Shadow contract** | A tariff you do not hold: either an offer from a comparison site (for the switching decision) or a hypothesis about the past ("what would that have cost?"). Takes effect **only** in the tariff comparison — never on the balance, forecast or contract status. |
| **Cancellation deadline** | The last day by which the notice must reach the supplier (`cancel_by`). Since v2.9.0 the reminder levels count down to this day, not to the contract end. |
| **Notice mode** | v2.9.0 (`notice_mode`): at the end of the term, any time at month end, or any time to any day (for example default supply, German *Grundversorgung*, with two weeks' notice). |
| **Renewed contract** | v2.9.0: expired, without a successor and not cancelled — runs on at its last prices (`renewed`, monthly rows `contract_assumed`) and can be cancelled any time with at most one month's notice. |
| **Switch date** | v2.3.0: the first day a new tariff could start supplying. Derived from the contract end and the notice period; distinct from the **cancellation deadline**, by which notice must be given. |
| **Break-even consumption** | v2.3.0: the annual volume above which an offer beats the running contract (column "Pays off from"). If it sits far from the expected consumption, the switching decision holds even with an imprecise forecast. |
| **Sign-up bonus** | v2.3.0: a one-off amount on the offer (`signup_bonus_eur`) that counts in the first year only. The ranking deliberately follows the cost **from** year two. |
| **Special payment** | F1003: a refund/back-payment or an additional advance payment. Balance = costs - advances + (Σ refund - Σ back-payment - Σ advance payment). "with effect" additionally sets the future advance. Gas/electricity/district heating only. |
| **Meter-reading capture** | F1004 (v1.6.0): the central view `#/zaehlerstaende` for quickly recording all cumulative meters on site in one pass. Gas/electricity/water/district heating only — heating oil/pellets use deliveries. |
| **Efficiency class** | The kWh/m²·a classification of heating energy (A+…H), per source since v1.4.0. |
| **Base load** | The weather-independent base (hot water, standby). |
| **Anomaly** | A month that deviates from the expectation for exactly this month by more than the threshold (heating model or the same calendar month in other years); robust spread with a floor, since v2.8.0. |
| **Tank stock curve** | The remaining stock of oil/pellets per day — since v2.10.0 from the same calculation as the consumption (tank log), calculated between anchors, estimated afterwards. |
| **Tank log** | v2.10.0: one calculation for consumption, costs and stock of oil/pellets, based on known stock levels (initial stock, delivery "filled to full", tank reading). |
| **Anchor (known stock level)** | A day with a known tank stock. Between two anchors the consumption is calculated, not estimated. |
| **Tank reading** | A tank stock read off (gauge, dipstick, sensor), stored on the tank under `tank_levels`. |
| **Certificate-style figure** | v2.10.0: the second efficiency figure — net calorific value, weather-adjusted, per m² of usable floor area, with a hot-water surcharge for decentralised hot water. Not a consumption certificate (that requires 36 months). |
| **Usable floor area (AN)** | The reference area of the energy performance certificate: 1.2 × living area, 1.35 × for a single/two-family or terraced house with a heated basement (§ 82 GEG). |
| **Gross / net calorific value** | Gas is billed by gross calorific value (kWh including the heat of condensation); the energy performance certificate and the BAFA CO₂ factors refer to the net calorific value: net kWh = gross kWh × 0.906. |
| **Self-consumption savings** | v2.10.0: self-used PV electricity × working price of the grid import — the electricity costs that self-consumption avoids. |
| **Recurrence** | The repeat rule of an appointment (annual, …). |
| **Baseline date** | F1011 (v2.4.0): a dated structural change on the meter (new heating, insulation, windows). From that day heating model, weather adjustment, anomalies and forecast start afresh; the months before stay visible but grey. The card "Effect of the measure" compares before and after per degree day. |
| **Volume correction factor** | Converts the measured gas volume to standard conditions (pressure, temperature at the installation site); on the gas bill, typically 0.93–0.97 (German: Zustandszahl). |
| **Gas conversion factor** | v2.5.0 (F1012): volume correction factor × calorific value per period, with the day from which it applies ("Valid from"). A reading interval across a change is split to the day. |
| **Billing date** | Day of the annual bill per utility (`billing_cycle_anchor_*`, default 1 January); the balance card projects the expected bill up to it. |
| **Reading type** | Where a meter reading comes from. In the bill check: no suffix = read, **S** = entered as estimated, **E** = substitute value, determined to the day between two readings where the supplier estimates too. Supplier bills use different abbreviations; their legend is on the bill. |
| **Bill check** | v2.5.0: recalculates a gas bill section by section — m³ × volume correction factor × calorific value = kWh, split at every reading and every change of calorific value ([guide](../anleitungen/jahresabrechnung.md)). |

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
Gas:          kWh = m³ × volume correction factor × calorific value   (per period from "valid from", since v2.5.0)
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

**Certificate-style figure (since v2.10.0):**

```text
AN     = living area × 1.2   (1.35 for a single/two-family or terraced house with heated basement)
metric = Σ adjusted kWh (gas × 0.906) / AN  (+ 20 with decentralised hot water)
```

**Tank log (oil/pellets, since v2.10.0):**

```text
consumption between anchors = stock_before + Σ deliveries − stock_after
share_day ∝ ρ + HDD_day           ρ = s · HDD_year / ((1 − s) · 365.25)
after the last anchor: rate × (ρ + HDD_day), estimated
price: moving average of the tank content
```

**Delivery costs (v1.4.2, total-amount precedence):**

```text
cost = total_eur                          if set
cost = quantity × unit_price_cents / 100  otherwise
```

**Balance:**

```text
balance = Σ costs - Σ advances + (Σ refund - Σ back-payment - Σ advance payment)

balance > 0  → back-payment looming
balance < 0  → credit
```

**Water saving index:**

```text
saving index = (litres per person per day) / reference × 100
```

**CO₂** *(default factors with a source since v2.10.0; electricity per year)*:

```text
CO2 = consumption × CO2 factor
```

**Z-score (anomaly, since v2.8.0):**

```text
r = actual - expected
z = (r - median(r)) / max( 1.4826 × MAD(r), 0.10 × max(expected, typical month) )
```

**Heating model and adjustment (since v2.8.0):**

```text
expected_heat = a × HDD + c × days
heat_adjusted = actual + a × (HDD_normal - HDD_actual)      (at least c × days)
```

---

## Default values (selection)

| Key | Default | Unit |
|---|---|---|
| `gas_conversion_factors` | `[{from:null, kwh_per_m3:11.5}]` | dated list (z × Hs per effective date) |
| `heizoel_kwh_per_l` | 10.0 | kWh/L |
| `pellets_kwh_per_kg` | 4.8 | kWh/kg |
| `hdd_base_temp` | 15.0 | °C |
| `blend_max` | 0.80 | — |
| `delivery_baseload_share` | 0.15 | share |
| `forecast_months` | 12 | months |
| `wohnflaeche_m2` | 100 | m² |
| `co2_gas` | 182 (BAFA, net calorific value × 0.906) | g/kWh |
| `co2_strom` / `co2_strom_years` | 380 until 2014, then German Environment Agency per year (2025: 344) | g/kWh |
| `co2_heizoel` / `co2_pellets` / `co2_fernwaerme` | 266 / 36 / 280 (BAFA) | g/kWh |
| `co2_wasser` | 350 *(no source)* | g/m³ |

---

[← Scenario: own home](08-szenario-eigenheim.md) ·
[Compendium index](../README.md)
