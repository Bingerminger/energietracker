# Settings — every key

[Deutsch](../../referenz/einstellungen.md) · **English**

[← Compendium index](../README.md)

Every setting with key, default, place in the interface and effect. Through the
API, `GET`/`PATCH /api/settings` reads and writes the same keys
([API reference](api.md)); the app rejects invalid values with 400. Keys stay
stable: anything is removed only with a new major version and after notice — the
deprecated ones are at the end.

The defaults apply to a new installation in Germany. Another country sets its
profile on the first start (location, currency, time zone, heating limit, CO₂
factors …) — see [Country profiles](../verstehen/14-laenderprofile.md). A test
checks that this page names every key.

## Settings → General

### Language & country

| Key | Default | In the app | Effect |
|---|---|---|---|
| `language` | `de` | Language | Interface language: de, en, fr, it, es, pt, nl. Card "Language & country". |
| `country` | `DE` | Country | Country (ISO code). Changing it offers to apply the country profile — see [Country profiles](../verstehen/14-laenderprofile.md). |
| `currency` | `EUR` | Currency | Currency (ISO code) for display; nothing is converted. |
| `timezone` | `Europe/Berlin` | Time zone | Time zone for "today", billing dates and reminders. |

### Display

| Key | Default | In the app | Effect |
|---|---|---|---|
| `dashboard_months` | 12 | Months on dashboard | How many months the history on the overview shows (3–36). |
| `forecast_months` | 12 | Forecast horizon | How many months the forecast looks ahead (1–24). Default of the forecast view. |
| `alert_days_since_reading` | 45 | Warn after | If the last reading is older, the meter appears under “To do” and the consumption view reminds you. |

### Reminders

| Key | Default | In the app | Effect |
|---|---|---|---|
| `contract_remind_days_1` | 90 | Level 1 — early warning | Days before the last day to cancel (without a notice period: before the contract end) for the first reminder. |
| `contract_remind_days_2` | 30 | Level 2 — reminder | second stage |
| `contract_remind_days_3` | 1 | Level 3 — urgent | third stage |
| `reminder_warn_days_before` | 14 | Reminder “due soon” from | From this many days before the date, a reminder counts as “due soon”. |
| `reminder_overdue_days` | 0 | Grace until “overdue” | For this many days after the date a reminder still counts as due, then as overdue. 0 = from the next day. |

## Settings → Household & building

### Building & efficiency

| Key | Default | In the app | Effect |
|---|---|---|---|
| `wohnflaeche_m2` | 100 | Living area | Heated area — denominator of the efficiency metric. |
| `gebaeudetyp` | `efh` | Building type | Multi-family means three or more flats. Values: `efh` detached/semi-detached, `rh` terraced house, `mfh` apartment building, `whg` flat. Determines the reference area of the certificate-style figure. |
| `beheizter_keller` | off | Heated basement | Single-/two-family or terraced house with heated basement: usable floor area = 1.35 × living area (otherwise 1.2) — for the certificate-style figure. |
| `warmwasser_dezentral` | off | Decentralised hot water | Hot water from an instantaneous heater or boiler, not from the heating: the certificate-style figure gets a 20 kWh/m²·yr surcharge. |

### Water reference values

| Key | Default | In the app | Effect |
|---|---|---|---|
| `wasser_personen_anzahl` | 2 | People in household | For the water saving index: litres per person and day compared with the reference. |
| `wasser_personen_referenz` | 122 | Reference | BDEW 2024: 122 L per person and day (Germany). Existing installations keep the former default 127 until they take over the new values. |
| `wasser_sparindex_gut` | 100 | Saving index — good up to | Index ≤ this value is considered unremarkable. |
| `wasser_sparindex_warnung` | 150 | Saving index — warning from | Index ≥ this value indicates saving potential. |

## Settings → Utilities & billing

### Active utilities

| Key | Default | In the app | Effect |
|---|---|---|---|
| `active_utilities` | `gas, strom, wasser` | Active utilities | Which utilities the menu and evaluations show. Deselected ones keep their data. |

### Billing cycle

| Key | Default | In the app | Effect |
|---|---|---|---|
| `billing_cycle_anchor_gas` | `01-01` | Settlement date gas | Day of the annual bill as `MM-DD` (displayed `DD-MM`); the balance card projects the expected bill up to it. |
| `billing_cycle_anchor_strom` | `01-01` | Settlement date electricity | like gas |
| `billing_cycle_anchor_wasser` | `01-01` | Settlement date water | like gas |
| `billing_cycle_anchor_fernwaerme` | `01-01` | Settlement date district heating | like gas |
| `billing_cycle_anchor_pv_einspeisung` | `01-01` | Settlement date PV feed-in | Billing day of the feed-in remuneration (since v2.13.0; before, 1 January applied). |

### Physical constants

| Key | Default | In the app | Effect |
|---|---|---|---|
| `gas_conversion_factors` | 11.5 kWh/m³ (undated) | Gas conversion factors | One entry per calorific-value period, exactly as the gas bill states them: valid from, volume correction factor and calorific value — the kWh/m³ factor is derived. Without a breakdown, enter the factor directly. The undated entry applies to everything before the first effective date. Consumption splits every reading interval day-exactly at the effective dates. List with "valid from", volume correction factor and calorific value per period; one entry may be undated. Default: undated 11.5 kWh/m³. |
| `gas_cv_unit` | `kwh` | Enter calorific value in | Unit in which the calorific value is entered: `kwh` (kWh/m³), `mj` (MJ/m³) or `gj` (GJ/Smc). Always stored as kWh/m³. |
| `hdd_base_temp` | 15 | HDD base temperature | Heating limit — days below count as heating days. |

### Fuels (delivery)

| Key | Default | In the app | Effect |
|---|---|---|---|
| `heizoel_kwh_per_l` | 10 | Heating oil energy content | Energy per litre of heating oil (net calorific value, usually 10 kWh/L). Converts litres to kWh. |
| `pellets_kwh_per_kg` | 4.8 | Pellets energy content | Energy per kg of pellets (usually 4.8 kWh/kg). Converts kg to kWh. |
| `tank_warn_pct` | 15 | Tank warning from | Below this level the tank turns yellow, below half of it red, and “To do” suggests a delivery. |

### CO₂ emission factors

| Key | Default | In the app | Effect |
|---|---|---|---|
| `co2_gas` | 182 | CO₂ gas | BAFA: 201 g/kWh, based on the net calorific value. The app counts gas by gross calorific value (z-number × calorific value), hence × 0.906 = 182. Existing installations keep the former default 201 (net calorific value) until they take over the new values. |
| `co2_strom` | 380 | CO₂ electricity | Applies to years before the first yearly value — without yearly values, to all years. |
| `co2_strom_years` | 2015–2025 (344–530) | CO₂ electricity per year | Electricity mix per year (German Environment Agency, UBA). After the last year its value applies, before that “CO₂ electricity”. Electricity mix per year, 2015–2025 from the German Environment Agency (g/kWh). |
| `co2_wasser` | 350 | CO₂ water | For treatment and pumping per m³ of water — an estimate without an official source. |
| `co2_fernwaerme` | 280 | CO₂ district heating | BAFA flat rate. Your heat network’s own value is more accurate — ask your supplier. Former default 180. |
| `co2_heizoel` | 266 | CO₂ heating oil | BAFA, based on the net calorific value — as the app calculates heating oil. |
| `co2_pellets` | 36 | CO₂ pellets | BAFA, CO₂ equivalents including the upstream chain. Former default 26. |

## Settings → Weather data

### Location and sync

| Key | Default | In the app | Effect |
|---|---|---|---|
| `location_name` | `Leipzig Zentrum` | Place name | Name of the location, for display. Set under Settings → Weather data by place search. |
| `latitude` | 51.3397 | Latitude | Latitude for temperatures and climate normal (sent to Open-Meteo rounded to two decimals, about 1 km). |
| `longitude` | 12.3731 | Longitude | Longitude, like latitude. |
| `weather_auto_fill` | on | Fill weather automatically | Fetches temperatures from Open-Meteo at most once a day when the app opens (location rounded to about 1 km). |

## Settings → Access

### Embedding

| Key | Default | In the app | Effect |
|---|---|---|---|
| `frame_ancestors` | empty | Allowed embedding addresses | Address with scheme and port, e.g. http://homeassistant.local:8123; separate several with spaces. Empty = only this installation itself. Addresses allowed to embed the app (Content Security Policy), such as a Home Assistant dashboard. |

## Settings → Expert

### Regression & forecast

| Key | Default | In the app | Effect |
|---|---|---|---|
| `min_days_period` | 20 | Min. days per period | Months with fewer recorded days (partial months) stay out of the heating signature and anomalies. |
| `min_hdd_regression` | 5 | Min. HDD for regression | Months with fewer degree days stay out of the heating signature — in summer the base load drives consumption, not the weather. |
| `blend_max` | 0.8 | Max. blend weight | Highest weight of the weather model in the forecast; the rest comes from the seasonal profile. 0.8 means at least 20 % seasonal profile. |
| `forecast_model` | `linear` | Default model | Model for forecast and tariff comparison. Linear suits most homes; the analysis shows which one explains your data best. Values: `linear`, `polynomial`, `robust`, `segmented`, `sigmoid`. |
| `segmented_split_mode` | `auto` | Segment breakpoint | auto = fitted from data, fixed = fixed HDD value below. Values: `auto`, `fixed`. |
| `segmented_fixed_split` | 50 | Fixed breakpoint | Only effective in “fixed” mode. |
| `confidence_band_sigma` | 1.28 | Forecast band width | In units of spread σ: 1.28 means consumption falls inside the band in 80 % of years; 1.64 would be 90 %. |
| `anomaly_threshold` | 2 | Anomaly threshold | From what deviation (in units of spread σ) a month counts as unusual in the analysis. Smaller means more sensitive. |

### Recommendations & distribution

| Key | Default | In the app | Effect |
|---|---|---|---|
| `recommendation_anomaly_sigma` | 2 | Recommendation anomaly threshold | The same kind of threshold for recommendations: only from this deviation on does a tip appear. |
| `recommendation_trend_pct_year` | 3 | Recommendation trend threshold | From what increase per year the recommendations report rising consumption. |
| `delivery_baseload_share` | 0.15 | Base-load share distribution | Share of consumption as weather-independent base load (rest HDD-weighted). |

## Only via the API

| Key | Default | In the app | Effect |
|---|---|---|---|
| `efficiency_class_thresholds` | A+ … G | — | Upper limits of the efficiency classes in kWh/m²·yr (each inclusive): A+ 30, A 50, B 75, C 100, D 130, E 160, F 200, G 250, above that H. Only changeable via the API. |

## Deprecated — removed in v3.0.0

| Key | Default | In the app | Effect |
|---|---|---|---|
| `min_temp_days_forecast` | 20 | — | Deprecated (v2.9.0), without effect — replaced by the rule that temperatures must exist for 90 % of a month's consumption days. |
| `baujahr` | empty | — | Deprecated (v2.9.0), without effect. |
| `billing_cycle_anchor_heizoel` | `01-01` | — | Deprecated (v2.13.0), without effect: heating oil has no advances and so no balance up to a billing date. |
| `billing_cycle_anchor_pellets` | `01-01` | — | Deprecated (v2.13.0), without effect, like heating oil. |

---

[← Compendium index](../README.md)
