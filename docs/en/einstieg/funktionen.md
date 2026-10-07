# What the Energietracker does

[Deutsch](../../einstieg/funktionen.md) · **English**

[← Compendium index](../README.md)

The detailed feature overview. What the views look like is shown in the
[view reference](../referenz/ansichten.md); how things are calculated is in
[Basics & methodology](../verstehen/00-overview.md).

---

## Capture

- **Meter readings** per meter, with a note and an "estimated" flag; planned
  readings (such as the next billing date) only count once the day has come.
- **Central capture** of all meters in one pass — on the iPhone with a "Next"
  key, Live Text to photograph the register and "Undo"; a bookmark
  `#/zaehlerstaende?meter=<id>` jumps straight to one meter.
- **Without a network in the cellar** (v3.1.0): a reading that does not reach
  the server waits in the browser and is sent later — without duplicates, and
  with a question if there is already a different reading on the same day
  ([Use on your phone](handy.md#the-not-saved-yet-queue)).
- **Photo as a receipt** (v3.1.0) per reading, scaled down in the browser and
  without GPS data; with your own text recognition service in the home network
  the app suggests the reading from the photo
  ([Text recognition in the home network](../anleitungen/texterkennung.md)).
- **Plausibility check** before saving: a multiple of the usual daily
  consumption ("forgot a comma?"), a smaller reading without a meter swap, a
  date in the future, a second reading on the same day. A register rollover
  (99,999 → 0) is calculated correctly.
- **Meter swap** as a data model of its own: a meter bundles its devices with
  serial number, installation and removal, initial and final reading;
  consumption across the swap is right.
- **Several meters** per utility, **sub-meters** (subtracted from the parent,
  no double counting) and **groups** (sum on the overview) — see
  [Meter topology](../verstehen/13-meter-topologie.md).
- **Consumption per period** (v3.1.0) instead of meter readings, where the
  consumption already comes ready-made — such as the monthly consumption
  information for heating and hot water. The app spreads each period over the
  months to the day; after that everything works as with readings. One by one,
  month by month in the capture view or as CSV — see
  [Heat](../verstehen/15-waerme.md).
- **Roles** for meters (v3.1.0): heat pump or wallbox for electricity, cold,
  hot or garden water, battery for PV, heat pump output for heat.
- **Heating oil and pellets** through deliveries instead of readings, with a
  **tank book**: initial stock, "filled to full" and dipstick readings are
  anchor points; in between consumption is calculated, afterwards estimated
  and marked as such.
- **CSV import** of readings with a preview: every row shows its effect (new,
  replaced, unchanged) and the questions the capture view would ask, before
  anything is written. Spreadsheets in every language of the app and with
  `DD.MM.YYYY`, `DD/MM/YYYY` or `DD-MM-YYYY` are read, as is every export file
  of your own.
- **Home Assistant** pushes readings automatically (`POST /api/ingest`,
  idempotent, with token and meter alias, since v3.1.0 also as a batch for
  backfilling) and reads balance, forecast and “days since reading” back as
  sensors (`GET /api/summary`) — see
  [Connect Home Assistant](../anleitungen/home-assistant.md). ioBroker,
  Node-RED and openHAB send readings the same way — see
  [Other systems](../anleitungen/andere-systeme.md).
- **Time series from portals** (v3.1.0): files from the grid operator,
  metering operator, inverter or heat pump — quarter-hour, hourly or daily
  values — read with a column mapping, condensed into daily values, daylight
  saving time included — see
  [Time series from portals](../anleitungen/daten-aus-portalen.md).
- **Temperatures** automatically from Open-Meteo for your own location (place
  search, daily sync, 30-year climate normal) or as CSV.

## Contracts, advances, billing

- **Contracts** per meter with unit price, standing charge and advance
  payment as a history — to the day: a switch or a price change in mid-month
  applies from its own day. A contract without a successor continues at its
  last prices until it is cancelled. For district heating (v3.1.0) also the
  **capacity and metering charges** by connected load — see
  [District heating](../verstehen/04-fernwaerme.md).
- **One contract for a meter group** (v3.1.0): e.g. peak and off-peak with one
  standing charge and a unit price of their own per register; balance,
  forecast, switch and bill check for the whole group — see
  [Meter topology](../verstehen/13-meter-topologie.md#group-contract-v310).
- **Controllable consumers** (v3.1.0): the reduced grid fee under § 14a EnWG
  (module 1) in the electricity contract, module 2 as a meter of its own with
  its own contract; **monthly prices** from a file for a dynamic contract;
  **credit notes from the direct marketer** for the feed-in — see
  [Electricity](../verstehen/02-strom.md).
- **Charging record** (v3.1.0) for a company car: per month the amount at the
  wall box with the contract price or the flat electricity rate, as CSV and PDF
  with meter readings — a statement, not tax advice — see
  [Charging record](../anleitungen/ladestrom-nachweis.md).
- **Bonuses** (sign-up, loyalty) and **special payments** (refund,
  back-payment, voluntary advance) — see
  [Special payments](../verstehen/10-sonderzahlungen.md).
- **Balance** per contract, in the words of the bill: "credit" or "additional
  payment", by calendar up to today, estimated from the last reading on, plus
  the **expected bill** on the billing date and a **suggested advance**.
- **Notice periods** in months, weeks or days: reminders in three stages up to
  the last day to cancel, notes on missed deadlines and announced price
  increases (with the special right to cancel in Germany).
- **Bill check** for gas, electricity, water and district heating (since
  v3.1.0, gas only before): recalculates the supplier's bill section by section
  — quantity, price, consumption and fixed costs, for gas m³ × volume correction
  factor × calorific value = kWh. The bill's figures can be recorded
  (“According to the bill”, with a PDF), compared (“matches” or “check”, with
  reasons) and booked as a special payment. How to enter a bill:
  [Enter and check the annual bill](../anleitungen/jahresabrechnung.md).
- **Contracts & payments** on one page: all running contracts with notice
  deadline, advance and the bill to expect.
- **Water** with three components: drinking water, wastewater (by drinking
  water or its own meter) and rainwater by sealed area.
- **Tenancy** (v3.1.0) for everyone who pays for heating and water through the
  service charges: prepayment against the expected costs of the current
  billing period, with an assessment and a suitable prepayment per month (an
  estimate, not a service charge statement); service charge statements with a
  PDF, from which the app takes over prices and the new prepayment; the
  deadlines for the statement and for objections in the calendar — see
  [As a tenant](../anleitungen/mieter.md).
- **Sharing CO₂ costs** (v3.1.0, Germany, rented homes): the landlord’s share
  under the CO2KostAufG — worked out with your own gas boiler, with a letter as
  a PDF and the deadline in the calendar; with central heating the heating cost
  statement is recalculated — see
  [Share CO₂ costs with the landlord](../anleitungen/co2-aufteilung.md).

## Switching

- **Switch decision**: the expected annual consumption to take to a comparison
  portal, the switch date from contract end and notice period, offers you found
  ranked by the ongoing cost from year two, with the **break-even** ("pays off
  from …") and the cost per month.
- The **chain of commitments** counts: if the follow-up contract is already
  signed, the date follows its end.
- **Look back**: the same offers laid over the consumption actually measured —
  "what would tariff X have cost?".
- For the PV feed-in the other way round: the higher remuneration comes first.
- **Dynamic tariff check** (v3.1.0, electricity): what would a dynamic tariff
  have cost? With the monthly wholesale averages from SMARD (only on request)
  or from a file, mark-up and VAT — an approximation without a load profile —
  see [Electricity → Dynamic tariffs](../verstehen/02-strom.md#dynamic-tariffs-v310).
- The country's **official tariff comparison** is linked where there is one
  (Austria, France, Italy, Spain, Portugal).
- **Have ready for switching** (v3.1.0): market location ID, meter number and
  last reading to copy — what the new supplier asks for.
- **Price increase** in the running contract (v3.1.0, Germany): a
  recommendation with the special right to terminate and an entry under “To
  do”; when saving a contract, a note if the minimum term runs beyond 24 months.

## Understand and look ahead

- **Heating degree days** from your own location against the heating limit
  (default 15 °C), plus a **heating model** with base load and **weather
  adjustment**: "used more, or was it just colder?" — every month against its
  expectation for that weather.
- **Five models** for degree days against consumption: linear, polynomial,
  robust, segmented (with a break point from the data) and sigmoid, with an R²
  comparison.
- **Baseline date**: date a renovation on the meter — from there every
  evaluation starts afresh; the effect shows as a before/after figure per
  degree day, with a statement whether it is statistically supported. Since
  v3.1.0 also for electricity, water and PV over the same calendar months —
  e.g. “Plug-in solar in operation”.
- **Forecast** over up to 24 months with an **uncertainty band** (80 % of
  years), cost per month from the tariff then valid, running balance and
  what-if (temperature offset, price factor, since v3.1.0 a higher CO₂ price).
- **Anomalies** (months far from their expectation), **year-on-year**
  comparison month by month and the **water saving index** per person.
- **Efficiency** in kWh/m²·yr — classes A+ to H per the German GEG, only for
  whole years — and next to it a **certificate-style figure** (net calorific
  value, weather-adjusted, building floor area).
- **Heat** (v3.1.0): the warmth that arrives in the home, as a utility of its
  own with heating model, weather adjustment and forecast; CO₂ as an
  approximation via the energy source of the heating. For hot-water meters the
  app calculates the **heat for the hot water** under HeizkostenV § 9 — see
  [Heat](../verstehen/15-waerme.md).
- **CO₂** with a source (BAFA, electricity mix per year from the German
  Environment Agency); for PV as avoided CO₂.
- **CO₂ price in fuel** (v3.1.0, Germany): how much BEHG price is contained in
  gas, heating oil and district heating — shown, not added; prices per year
  adjustable — see [CO₂ price in fuel](../verstehen/16-co2-preis.md).
- **PV**: feed-in as remuneration, generation, self-consumption,
  self-sufficiency and the savings from self-consumption; since v3.1.0
  **battery** (losses, efficiency, full cycles), **payback**, **plug-in solar**
  without a feed-in meter with assumed self-consumption, the § 51 EEG note and
  your own CO₂ avoidance factor — see [PV](../verstehen/12-pv.md).
- **Heat pump** (v3.1.0): seasonal performance factor from heat and
  electricity meters, per month and for the heating season, with field-test
  values for context — see [Heat](../verstehen/15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310).
- **Benchmark** (v3.1.0): household electricity and heating per m² against your
  own reference values, e.g. from the German electricity or heating
  benchmarks, with links for checking yourself in Germany.
- **Recommendations** from your own data (seven rule families, each hideable)
  and **reminders** for servicing, chimney sweep and calibration deadlines.
- **Calendar subscription** (since v3.1.0): reminders, cancellation deadlines,
  contract ends, end of the price guarantee, due readings and — when renting —
  the deadlines of the service charge statement and of the CO₂ refund in your
  own calendar, with
  advance warning for reminders and contracts — see
  [Subscribing to the calendar](../anleitungen/kalender.md).
- **Annual report** as a print view in the language of the device — print it
  or save it as a PDF, on the iPhone via Share → Print — and as a ready-made
  PDF file in the browser or as a download.

## Use

- **Mac and iPhone**: navigation in seven areas along the users' questions; on
  the iPhone a tab bar with "＋ Add", dialogs as sheets, 44 px touch targets,
  contrast per WCAG AA, installable as an app — see
  [Use on your phone](handy.md).
- **Help in the app**: first steps with ticks from your data, a glossary with
  43 terms and an ⓘ next to every key figure.
- **Seven languages** (German, English, French, Italian, Spanish, Portuguese,
  Dutch), chosen per device, and **country profiles** for nine countries:
  currency, formats, time zone, weather location, heating limit, CO₂ factor,
  gas units and the terms on the country's bill — see
  [Country profiles](../verstehen/14-laenderprofile.md).
- **Light, dark or like the system**.
- **Sample data** to try it out without losing your own (the app saves the
  current state first).

## Run

- **No database, no runtime dependencies**: PHP 8.2 or newer and plain JSON
  files; Chart.js and the fonts ship with the repository.
- **Docker** (amd64 and arm64, with PHP 8.4) or any web server with PHP — see
  [Installation](../betrieb/installation.md); templates for Synology, Unraid,
  CasaOS and Umbrel under [Docker operation](../betrieb/docker.md).
- **Backup** as one JSON file (format 3.0), checked and previewed before it is
  applied; automatic snapshots before every import. Since v3.1.0 including the
  photos.
- **CSV export** for the monthly overview, readings, deliveries, periods
  (v3.1.0) and temperatures — as a spreadsheet in the default language for Excel and
  LibreOffice or in the frozen format 1 for scripts.
- **Optional sign-in** with a password or through a reverse proxy, API keys
  with read or manage rights and separate keys for the calendar subscription
  only — see
  [Security & network operation](../betrieb/sicherheit.md).
- **Diagnostics** under Settings → System and `GET /api/health` for the Docker
  health check and uptime monitors.
- **Open REST API** with a stability promise — see the
  [API reference](../referenz/api.md).

---

[← Compendium index](../README.md)
