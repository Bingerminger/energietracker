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
- **Heating oil and pellets** through deliveries instead of readings, with a
  **tank book**: initial stock, "filled to full" and dipstick readings are
  anchor points; in between consumption is calculated, afterwards estimated
  and marked as such.
- **CSV import** of readings with a preview: every row shows its effect (new,
  replaced, unchanged) and the questions the capture view would ask, before
  anything is written.
- **Home Assistant** pushes readings automatically (`POST /api/ingest`,
  idempotent, with token and meter alias) — see
  [Connect Home Assistant](../anleitungen/home-assistant.md).
- **Temperatures** automatically from Open-Meteo for your own location (place
  search, daily sync, 30-year climate normal) or as CSV.

## Contracts, advances, billing

- **Contracts** per meter with unit price, standing charge and advance
  payment as a history — to the day: a switch or a price change in mid-month
  applies from its own day. A contract without a successor continues at its
  last prices until it is cancelled.
- **Bonuses** (sign-up, loyalty) and **special payments** (refund,
  back-payment, voluntary advance) — see
  [Special payments](../verstehen/10-sonderzahlungen.md).
- **Balance** per contract, in the words of the bill: "credit" or "additional
  payment", by calendar up to today, estimated from the last reading on, plus
  the **expected bill** on the billing date and a **suggested advance**.
- **Notice periods** in months, weeks or days: reminders in three stages up to
  the last day to cancel, notes on missed deadlines and announced price
  increases (with the special right to cancel in Germany).
- **Gas bill check**: recalculates the supplier's bill section by section —
  m³ × volume correction factor × calorific value = kWh, split at every reading
  and every change of calorific value. How to enter a bill:
  [Enter and check the annual bill](../anleitungen/jahresabrechnung.md).
- **Contracts & payments** on one page: all running contracts with notice
  deadline, advance and the bill to expect.
- **Water** with three components: drinking water, wastewater (by drinking
  water or its own meter) and rainwater by sealed area.

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
  degree day, with a statement whether it is statistically supported.
- **Forecast** over up to 24 months with an **uncertainty band** (80 % of
  years), cost per month from the tariff then valid, running balance and
  what-if (temperature offset, price factor).
- **Anomalies** (months far from their expectation), **year-on-year**
  comparison month by month and the **water saving index** per person.
- **Efficiency** in kWh/m²·yr — classes A+ to H per the German GEG, only for
  whole years — and next to it a **certificate-style figure** (net calorific
  value, weather-adjusted, building floor area).
- **CO₂** with a source (BAFA, electricity mix per year from the German
  Environment Agency); for PV as avoided CO₂.
- **PV**: feed-in as remuneration, generation, self-consumption,
  self-sufficiency and the savings from self-consumption — see
  [PV](../verstehen/12-pv.md).
- **Recommendations** from your own data (seven rule families, each hideable)
  and **reminders** for servicing, chimney sweep and calibration deadlines.
- **PDF annual report** in the browser or as a download.

## Use

- **Mac and iPhone**: navigation in seven areas along the users' questions; on
  the iPhone a tab bar with "＋ Add", dialogs as sheets, 44 px touch targets,
  contrast per WCAG AA, installable as an app — see
  [Use on your phone](handy.md).
- **Help in the app**: first steps with ticks from your data, a glossary with
  33 terms and an ⓘ next to every key figure.
- **Seven languages** (German, English, French, Italian, Spanish, Portuguese,
  Dutch) and **country profiles** for nine countries: currency, formats, time
  zone, weather location, heating limit, CO₂ factor, gas units — see
  [Country profiles](../verstehen/14-laenderprofile.md).
- **Light, dark or like the system**.
- **Sample data** to try it out without losing your own (the app saves the
  current state first).

## Run

- **No database, no runtime dependencies**: PHP 8.4 and plain JSON files;
  Chart.js and the fonts ship with the repository.
- **Docker** (amd64 and arm64) or any web server with PHP — see
  [Installation](../betrieb/installation.md).
- **Backup** as one JSON file (format 3.0), checked and previewed before it is
  applied; automatic snapshots before every import.
- **CSV export** for the monthly overview, readings and temperatures.
- **Optional sign-in** with a password or through a reverse proxy, API keys
  with read or manage rights — see
  [Security & network operation](../betrieb/sicherheit.md).
- **Diagnostics** under Settings → System and `GET /api/health` for the Docker
  health check and uptime monitors.
- **Open REST API** with a stability promise — see the
  [API reference](../referenz/api.md).

---

[← Compendium index](../README.md)
