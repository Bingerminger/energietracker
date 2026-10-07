# Glossary & formula collection

**English** · [Deutsch](../../verstehen/09-glossar.md)

[← Scenario: own home](08-szenario-eigenheim.md) · [Compendium index](../README.md)

A compact reference of all terms and formulas. The detailed derivation is in
[Fundamentals & methodology](00-overview.md). What unit price, standing charge,
advance payment, correction factor, calorific value and balance are called on
the bills of other countries is in
[country profiles §8](14-laenderprofile.md#8-what-your-bill-calls-it); since
v3.1.0 the app shows it in the help and at every ⓘ.

> Formulas are written as plain-text code blocks so that they are displayed
> identically and correctly everywhere (GitHub, editor, viewer).

---

## Terms

| Term | Meaning |
|---|---|
| **Cumulative** | Recording via continuous meter readings (gas, electricity, water, district heating, PV, since v3.1.0 heat). |
| **Delivery-based** | Recording via fuel deliveries instead of a meter (heating oil, pellets). |
| **Consumption per period** | v3.1.0: A way of recording besides meter readings: each period holds the consumption itself, for example from the monthly consumption information. The app spreads it over the months to the day; after that everything works as with meter readings. Chosen on the meter as “Recording” ([Heat](15-waerme.md)). |
| **Monthly consumption information** | v3.1.0: Since 2022, tenants with remotely read meters in Germany must be told their heating and hot-water consumption every month, with the previous month, the same month last year and an average user (HeizkostenV § 6a). The values can be entered as consumption per period. German abbreviation: UVI. |
| **Heat** | v3.1.0: the utility for the heat that arrives in the home (heat meter or consumption information), not for the fuel. In kWh, without supplier contracts ([Heat](15-waerme.md)). |
| **Meter role** | v3.1.0: what a meter measures within its utility — for example hot water instead of cold water, heat pump instead of household (`role`). If missing, the first role of the utility applies. |
| **Prepayment (service charges)** | v3.1.0: What a tenant pays the landlord every month for heating and operating costs. The service charge statement compares it with the actual costs. |
| **Service charge statement** | v3.1.0: The landlord’s annual statement. In Germany it must arrive within twelve months of the end of the billing period; objections are possible up to twelve months after receipt (BGB § 556). Both deadlines appear in the calendar ([As a tenant](../anleitungen/mieter.md)). |
| **Hot-water heat** | v3.1.0: The heat used by a hot-water meter, calculated under HeizkostenV § 9: 2.5 kWh per m³ and degree above 10 °C. 1 m³ at 60 °C is 125 kWh. A calculated value, not a measurement. |
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
| **Meter-reading capture** | F1004 (v1.6.0): the central view `#/zaehlerstaende` for quickly recording all cumulative meters on site in one pass. Heating oil/pellets use deliveries; since v3.1.0, meters with consumption per period get a card with month and consumption. |
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
| **Reading type** | Where a meter reading comes from. In the bill check: no suffix = read, **E** = entered as estimated, **I** = interpolated substitute value, determined to the day between two readings where the supplier estimates too. Since v3.1.0 the letters follow the interface language (German S/E, Italian S/I …, [Gas](01-gas.md)). Supplier bills use different abbreviations; their legend is on the bill. |
| **Bill check** | v2.5.0: recalculates a gas bill section by section — m³ × volume correction factor × calorific value = kWh, split at every reading and every change of calorific value. Since v3.1.0 also for electricity, water and district heating (split at readings and price dates) and with the costs per section ([guide](../anleitungen/jahresabrechnung.md)). |
| **Supplier bill (“According to the bill”)** | v3.1.0: the figures of the supplier’s annual bill — period, quantity, amount, advances, result, other items, for gas the CO₂ details. The app sets them against its own calculation (“matches” within 1 % or 2 €, otherwise “check”) and, if you want, books the result as a special payment ([guide](../anleitungen/jahresabrechnung.md#7-on-the-bill-enter-compare-book)). |
| **CO₂ price (BEHG)** | v3.1.0: Since 2021 suppliers of gas and heating oil in Germany pay a price for every tonne of CO₂ and pass it on in the unit price: 55 euros in 2025, 60 euros per tonne in 2026. The app shows it – it is not a surcharge. It is calculated with the standard BEHG factors; wood, pellets and electricity carry none ([CO₂ price in fuel](16-co2-preis.md)). |
| **Sharing CO₂ costs** | v3.1.0: In German rented homes, tenant and landlord share the CO₂ costs in ten stages (CO2KostAufG): below 12 kg CO₂ per m² and year the tenant bears everything, from 52 kg the landlord 95%. The value is rounded to one decimal place ([Share CO₂ costs](../anleitungen/co2-aufteilung.md)). |
| **Capacity charge, metering charge** | v3.1.0, district heating: fixed costs next to the base price — capacity charge in € per kW of connected load and year, metering charge in € per year. Both feed into costs, balance and bill check per month ([District heating](04-fernwaerme.md)). |
| **Market location ID (MaLo)** | v3.1.0: the eleven-digit number of the point where energy is supplied; the last digit is a check digit. The new supplier asks for it when you switch. Next to it the **metering location ID (MeLo)** with 33 characters starting with “DE”. Both are on the bill. |
| **Special right to terminate** | If the supplier raises prices, in Germany you may give notice as of that day (§ 41(5) EnWG). Since v3.1.0 the app reports an entered price increase as a recommendation and under “To do”. |
| **Group contract** | v3.1.0: a contract for a meter group instead of a meter — e.g. peak and off-peak of a dual-rate meter with a unit price of their own per register. Standing charge, advance payments and bonuses count once ([Meter topology](13-meter-topologie.md#group-contract-v310)). |
| **§ 14a EnWG (controllable consumers)** | v3.1.0: The grid operator may throttle heat pumps, wall boxes and storage above 4.2 kW during bottlenecks; in return the grid fee drops. Module 1: a fixed amount per year. Module 2: a separate meter with 40% of the grid unit price. Module 3: time-variable grid fees on top of module 1 ([Electricity](02-strom.md#controllable-consumers-v310)). |
| **Dynamic tariff** | v3.1.0: An electricity tariff whose unit price follows the wholesale price (§ 41a EnWG). It is billed per quarter hour with a smart metering system. The app estimates it with the monthly wholesale average plus a mark-up — an approximation, not a substitute for the bill ([Electricity](02-strom.md#dynamic-tariffs-v310)). |
| **Seasonal performance factor (SPF)** | v3.1.0: How much heat a heat pump makes from one kWh of electricity, measured over a year: heat ÷ electricity. Needs a heat meter on the heat pump. In a field test, air/water units reached about 3.4, brine/water 4.3 ([Heat](15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310)). |
| **Flat electricity rate** | v3.1.0: a flat price per kWh with which an employer in Germany can reimburse the electricity used to charge a company car at home (BMF letter of 11 Nov 2025; 2026: 34 ct/kWh) — an alternative to the contract price ([Charging record](../anleitungen/ladestrom-nachweis.md)). |

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

**Consumption per period (since v3.1.0):**

```text
daily rate    = value / days of the period     (from and to inclusive)
month's share = daily rate × days of the period in that month
```

**Hot-water heat (since v3.1.0, HeizkostenV § 9 (2)):**

```text
Q [kWh] = 2.5 × V [m³] × (t_w − 10)      t_w = warmwasser_temp_c, default 60 °C
1 m³ at 60 °C = 125 kWh;  3 m³ at 55 °C = 337.5 kWh
```

**Tenancy — estimate per month (since v3.1.0):**

```text
expected = heat kWh × price + hot water m³ × price + cold water m³ × price + flat charges / 12
paid     = prepayment heating + operating costs
outcome  = Σ expected − Σ paid        > 0 additional payment, < 0 credit
```

**CO₂ price in fuel (since v3.1.0, BEHG):**

```text
emissions [kg]  = consumption [kWh] × factor   (gas 0.18139 per kWh gross, heating oil 0.2664 per kWh net)
net [€]         = emissions / 1000 × price [€/t]
gross [€]       = net × 1.19
scenario [ct/kWh] = (scenario price − price of the year) × factor / 10 × 1.19
```

**Sharing CO₂ costs (since v3.1.0, CO2KostAufG):**

```text
kg per m²  = emissions / floor area           (one decimal place)
stage      < 12 → 0 % · < 17 → 10 % · < 22 → 20 % · < 27 → 30 % · < 32 → 40 %
           < 37 → 50 % · < 42 → 60 % · < 47 → 70 % · < 52 → 80 % · otherwise 95 %   (landlord's share)
refund     = CO₂ costs × share × reduction   (own appliances × 0.95; one restriction × 0.5; both 0)
```

**District heating — fixed costs per month (since v3.1.0):**

```text
fixed = base price + connected load [kW] × capacity charge [€/(kW·a)] / 12 + metering charge [€/a] / 12
```

**§ 14a EnWG, module 1 (since v3.1.0):**

```text
fixed = standing charge − reduction [€/a] / 12          €120/a → €10 less per month
```

**Dynamic tariff check (since v3.1.0):**

```text
unit price [ct/kWh] = wholesale monthly average [ct/kWh] × (1 + VAT) + mark-up [ct/kWh]
3,000 kWh, 10 ct, 19 %, 15 ct, €10/month → 3,000 × 26.9 ct + €120 = €927
```

**Charging record (since v3.1.0):**

```text
contract price: amount = kWh × energy cost/kWh of the paying meter + standing charge × kWh wall box / kWh parent meter
flat rate:      amount = kWh × flat rate          3,000 kWh × €0.34 = €1,020
```

**Seasonal performance factor (since v3.1.0):**

```text
SPF = Σ heat [kWh] / Σ electricity [kWh]        only months with values on both sides
9,000 kWh / 2,500 kWh = 3.6
```

**PV payback (since v3.1.0):**

```text
benefit = self-consumption × unit price + feed-in revenue     (since commissioning)
years   = investment / benefit of the last 12 months          €800 / €160/a = 5 years
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
| `warmwasser_temp_c` | 60 (v3.1.0) | °C |
| `co2_price_eur_t_years` | `{}` = country profile (DE 2025: 55, 2026: 60) (v3.1.0) | €/t |
| `co2_price_scenario_eur_t` / `co2_price_scenario_from` | empty / 2028 (v3.1.0) | €/t / year |

---

[← Scenario: own home](08-szenario-eigenheim.md) ·
[Compendium index](../README.md)
