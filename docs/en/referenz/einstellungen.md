# Settings — every key

[Deutsch](../../referenz/einstellungen.md) · **English**

[← Compendium index](../README.md)

Every setting with key, default, place in the interface and effect. Through the
API, `GET`/`PATCH /api/settings` reads and writes the same keys
([API reference](api.md)); the app rejects invalid values with 400. Keys stay
stable: anything is removed only with a new major version and after notice — the
deprecated ones are at the end.

Since v3.2.0 a household can have several people with roles
([Users in the household](../anleitungen/benutzer.md)). Three keys can then
only be changed by admins, because they make the app talk to other addresses or
let it be embedded: `frame_ancestors`, `ocr_endpoint` and `evcc_endpoint`. A
member who sends one of them gets `403` `errors.auth.adminOnly`; members may
change every other key. Without sign-in the restriction does not apply.

The defaults apply to a new installation in Germany. Another country sets its
profile on the first start (location, currency, time zone, heating limit, CO₂
factors …) — see [Country profiles](../verstehen/14-laenderprofile.md). A test
checks that this page names every key.

## Settings → General

### Language & country

| Key | Default | In the app | Effect |
|---|---|---|---|
| `language` | `de` | Default language of the installation | de, en, fr, it, es, pt, nl. Applies to devices without a choice of their own, to the annual report and CSV files, and to messages for Home Assistant and scripts. Since v3.1.0 every device also picks its own language ("Language on this device", stored in the browser); the app sends it as `X-ET-Language`, and labels and messages of the API follow it. |
| `country` | `DE` | Country | Country (ISO code). Changing it offers to apply the country profile — see [Country profiles](../verstehen/14-laenderprofile.md). |
| `currency` | `EUR` | Currency | Currency (ISO code) for display; nothing is converted. |
| `timezone` | `Europe/Berlin` | Time zone | Time zone for "today", billing dates and reminders. |

### Experience level and setup *(v3.2.0)*

The level decides what the interface shows, not what the app calculates:
calculations, API and export are the same at every level. A page above the
level stays reachable and shows a note whose one click raises the level. More:
[Setup](../einstieg/einrichtung.md).

| Key | Default | In the app | Effect |
|---|---|---|---|
| `ui_level` | `expert` | Switch at the top next to day/night; card “Experience level and setup” | `beginner` (🌱 Beginner), `advanced` (🌿 Experienced) or `expert` (🌳 Expert). Beginner: overview with three answers, meter readings, simple contracts, annual report. Experienced: plus analyses, forecast, switching, bill check and meter structure. Expert: everything, with explainers, groups, calculation parameters and access. The default Expert means: an existing installation sees everything as before after the update; a new installation picks the level in the setup assistant. With sign-in every person has their own level (`PATCH /api/session/me`); this key then applies to people without a choice of their own. |
| `setup_pending` | `false` | — | `true` means: the setup assistant appears the next time the app opens. It is only set on the very first start of a new installation, never after an update; the assistant resets it when it is finished or skipped, and so does loading an example household. The assistant can be started again at any time with “Start the setup assistant” on the card “Experience level and setup” — that does not need this key. |
| `setup_persona` | empty (`null`) | Setup assistant, first question | The persona chosen last: `mieterin`, `etw-fernwaerme`, `eigenheim-klassisch`, `eigenheim-modern` or `showcase` (“Just look at everything first”); empty or `none` = none. The assistant suggests the answer from it; loading an example household sets the loaded persona. Calculates nothing. |

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
| `wohnflaeche_m2` | 100 | Living area | Heated area — denominator of the efficiency metric. Since v3.1.0 also the area for the CO₂ stage ([CO₂ price](../verstehen/16-co2-preis.md)); when you rent, the floor area from the tenancy takes precedence. |
| `gebaeudetyp` | `efh` | Building type | Multi-family means three or more flats. Values: `efh` detached/semi-detached, `rh` terraced house, `mfh` apartment building, `whg` flat. Determines the reference area of the certificate-style figure. |
| `beheizter_keller` | off | Heated basement | Residential building with up to two dwellings (detached/semi-detached, also terraced house) with heated basement: usable floor area = 1.35 × living area (otherwise 1.2; § 82(2) GModG, formerly GEG) — for the certificate-style figure. Only takes effect with `gebaeudetyp` `efh` or `rh`. |
| `warmwasser_dezentral` | off | Decentralised hot water | Hot water from an instantaneous heater or boiler, not from the heating: the certificate-style figure gets a 20 kWh/m²·yr surcharge. |

### Home and hot water *(v3.1.0)*

If you rent, the Costs area gets the “Tenancy” page; the energy source sets the
CO₂ value of the heat. Step by step for tenants:
[As a tenant](../anleitungen/mieter.md); the calculation:
[Heat](../verstehen/15-waerme.md).

| Key | Default | In the app | Effect |
|---|---|---|---|
| `wohnverhaeltnis` | `eigentum` | I live | “in my own home” (`eigentum`) or “in a rented home” (`miete`). Rented: the “Tenancy” page appears under Costs & contracts (prepayment, estimate, service charge statements), and agenda and calendar know the two deadlines of the service charge statement. In Germany the app also splits the CO₂ costs between tenant and landlord ([Share CO₂ costs](../anleitungen/co2-aufteilung.md)). In your own home nothing changes. |
| `waerme_energietraeger` | empty | Heat comes from | For the CO₂ value of the Heat utility – an approximation, since the heat is counted, not the fuel. Values: `gas`, `heizoel`, `pellets`, `fernwaerme`, `strom` (heat pump, instantaneous water heater) or empty (“not specified”) — then the CO₂ value is 0. The CO₂ factor of that energy source is used (electricity per year). |
| `warmwasser_energietraeger` | empty | Hot water is heated by | What heats the hot water: the same values plus `waerme` (“Heat (central)”). For information only; the calculation does not change. |
| `warmwasser_temp_c` | 60 | Hot water temperature | For the heat of the hot-water meters under HeizkostenV § 9 (2): 2.5 kWh per m³ and degree above 10 °C (30–90 °C). Unknown: 60 °C — then 1 m³ of hot water is 125 kWh. Only affects water meters with the role “Hot water”. |

### Own reference values *(v3.1.0)*

For the card “Benchmark {year}” on the overview. The app ships no tables from
the German electricity or heating benchmarks (Strom-/Heizspiegel) — using them
requires permission —; you enter the values that, for example, the electricity
benchmark gives for your household. Empty = no benchmark
([API](api.md#benchmark-get-apibenchmarkscomparison-v310)).

| Key | Default | In the app | Effect |
|---|---|---|---|
| `reference_strom_kwh` | empty (`null`) | Reference household electricity | kWh per year (0–100000), without heat pump and wall box. The app sets the household electricity of a full year next to it — all electricity meters without the roles heat pump and wall box — and states the deviation in %. |
| `reference_heat_kwh_m2` | empty (`null`) | Reference heating | kWh per m² living area and year (0–1000). Compared per heating type, weather-adjusted where the heating model exists. Heating benchmarks use the whole building; for a flat it is only a guide. |
| `reference_source` | empty | Source | Text of up to 120 characters, e.g. title and year; shown below the benchmark. |
| `warmwasser_elektrisch` | off | Hot water by electricity | Instantaneous electric heater or electric boiler. Electricity benchmarks distinguish by it; the app returns the setting with the benchmark but does not calculate with it. |

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
| `active_utilities` | `gas, strom, wasser` | Active utilities | Which utilities the menu and evaluations show. Deselected ones keep their data. Since v3.1.0 there are nine; the new Heat utility (`waerme`) is not active by default — choose it here if you need it. |

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
| `co2_pv_avoided` | empty (`null`) | CO₂ avoided by PV | *(v3.1.0)* Your own avoidance factor in g/kWh (0–2000) for PV generation and feed-in. Empty = electricity mix as for “CO₂ electricity” (previous behaviour). The app suggests no value; the German Environment Agency’s emissions balance of renewable energy gives a guide. |

### Photovoltaics *(v3.1.0)*

| Key | Default | In the app | Effect |
|---|---|---|---|
| `pv_assumed_self_consumption_pct` | empty (`null`) | Assumed self-consumption | Share of generation in % (0–100) that the household uses itself — for a plug-in solar device without a feed-in meter. Only takes effect when a generation meter is marked as plug-in solar and there is no feed-in meter: self-consumption is then generation × share. Empty = no assumption, no ratio ([PV](../verstehen/12-pv.md#8-plug-in-solar-v310)). |

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
| `frame_ancestors` | empty | Allowed embedding addresses | Address with scheme and port, e.g. http://homeassistant.local:8123; separate several with spaces. Empty = only this installation itself. Addresses allowed to embed the app (Content Security Policy), such as a Home Assistant dashboard. Since v3.2.0 admins only. |

## Settings → Expert

Since v3.1.0 the groups “Receipts” and “Text recognition in the home network”
come first, since v3.2.0 also “evcc in your home network”; the calculation
parameters (regression & forecast, recommendations & distribution, since
v3.1.0 CO₂ price) sit below, collapsed behind “Show calculation parameters”.
The page belongs to the Expert level.

### Receipts *(v3.1.0)*

| Key | Default | In the app | Effect |
|---|---|---|---|
| `attachments_max_mb` | 500 | Storage for receipts, at most | Upper limit for all receipts together (photos, PDFs) in MB, 10–100000. Once it is reached, the app refuses new receipts. From 80 % the backup card under Settings → Data shows a warning. A single photo may have at most 3 MB, a PDF 10 MB. |

### Text recognition in the home network *(v3.1.0)*

Suggests the meter reading from a photo using your own service such as Ollama
or LM Studio — only in your own network. Setup:
[Text recognition in the home network](../anleitungen/texterkennung.md).

| Key | Default | In the app | Effect |
|---|---|---|---|
| `ocr_endpoint` | empty | Service address | Base address with `http://` or `https://`, for example `http://192.168.178.20:11434` (Ollama) or `http://192.168.178.20:1234/v1` (LM Studio); the app appends the path. Empty = off, the app opens no connection. Every address the name points to must be in your own network, otherwise text recognition refuses. Since v3.2.0 admins only. |
| `ocr_api` | `ollama` | Interface | `ollama` (`/api/chat`) or `openai` — OpenAI-compatible (`/v1/chat/completions`), such as LM Studio or LocalAI. |
| `ocr_model` | empty | Model | Name of a vision model as the service knows it, for example `qwen2.5vl`, `llama3.2-vision` or `minicpm-v` (at most 200 characters). |
| `ocr_timeout_s` | 30 | Time limit | Seconds the server waits for the answer (5–300). On a NAS without a graphics card, vision models often need 20 to 60 seconds. Since v3.1.0 nginx in the Docker image waits up to 310 seconds, so the whole time limit applies; behind your own web server or reverse proxy, its limit counts. |

### evcc in your home network *(v3.2.0)*

Fetches the wall box charging sessions straight from evcc when you tap “Fetch
from evcc” in the wall box view. The CSV file from evcc always works, also
without this key. Setup:
[Charging sessions from evcc](../anleitungen/evcc.md).

| Key | Default | In the app | Effect |
|---|---|---|---|
| `evcc_endpoint` | empty | evcc address | Base address with `http://` or `https://` at which you open evcc in the browser, for example `http://192.168.178.30:7070` or `http://evcc.local:7070`; the app appends `/api/sessions`. Empty = off, the app opens no connection. Every address the name points to must be in your own network, otherwise the fetch refuses (as with text recognition). Admins only. |

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

### CO₂ price *(v3.1.0)*

The CO₂ price in fuel (BEHG) — background and calculation under
[CO₂ price in fuel](../verstehen/16-co2-preis.md). Effective only in countries
with a CO₂ price, today Germany.

| Key | Default | In the app | Effect |
|---|---|---|---|
| `co2_price_eur_t_years` | empty (`{}`) = country profile | CO₂ price per year | Your own yearly values in € per tonne as a table year → €/t (years 1990–2100, values 0–1000); they take precedence over the country profile (Germany: 2021 25, 2022 30, 2023 30, 2024 45, 2025 55, 2026 60). For a year without a value the last known one applies as an assumption — for 2027 therefore 60, although § 4(1) no. 3 CO2KostAufG makes the average of the auctions from 1 July to 30 November 2026 the applicable price; the German Environment Agency publishes it no later than ten working days before the year begins, enter it here then. Via the API an object `{"2027": 65}` or a list `[{year, eur_t}]`. |
| `co2_price_scenario_eur_t` | empty (`null`) = off | Scenario: CO₂ price | Default for the forecast field “CO₂ price from 2028 (€/t)” (0–1000 €/t): the forecast then shows what this price would cost extra. Empty = off. |
| `co2_price_scenario_from` | 2028 | Scenario from year | First year the scenario applies to (2021–2100). In 2028 the European emissions trading (ETS2) is to replace the fixed prices. |

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
