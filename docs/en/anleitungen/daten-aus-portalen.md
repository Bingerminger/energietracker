# Import time series from portals

**English** · [Deutsch](../../anleitungen/daten-aus-portalen.md)

[← Compendium index](../README.md)

Grid operators, metering operators, inverters and heat pumps often hand out
their measurements as a file: quarter-hour values (load profile), hourly or
daily values. Since **v3.1.0** the Energietracker reads such files with a
**column mapping** and condenses them into daily values — as meter readings or
as consumption per day. The app does not connect to the portals: you download
the file and import it.

---

## 1. Where the data comes from

| Source | What you usually get there |
|---|---|
| **Grid operator or metering operator** (customer portal) | With a smart metering system quarter-hour values for import and feed-in; with a modern metering device often only meter readings or daily values |
| **Inverter** (manufacturer’s app or portal) | Generation per day or hour, sometimes also feed-in, import and battery, partly in Wh |
| **Heat pump** (manufacturer’s portal) | Heat output and electricity input per day, partly as a meter reading |
| **Wall box** (app or portal) | Energy charged per session or day, often with a meter reading |

The file is almost always a CSV file, sometimes called “export” or “download”.
Save an Excel file as CSV first.

**A right to your own data.** Since 12 September 2025 the EU Data Act
(Regulation (EU) 2023/2854) applies: whoever uses a connected device — such as
an inverter, a heat pump or a wall box — can ask the data holder, usually the
manufacturer or the provider of the companion app, for the device’s readily
available data: free of charge, in a common, machine-readable format, and on
request also to a third party (Art. 4 and 5). Devices from micro and small
enterprises are exempt (Art. 7). In this picture the Energietracker is the recipient,
with one particularity: it runs on your own server, so the data stays with you.
It has no interface to manufacturers; it reads the file you receive. This is a
note, not legal advice.

## 2. Check first

Open the file once in a text editor and answer four questions:

1. **How many lines come before the data?** Header, title, meter number,
   column names — everything before the first value.
2. **Which column holds date, time and value?** Date and time may share one
   column or sit in two.
3. **Is the value a meter reading or consumption per interval?** A meter
   reading keeps growing (12,345.6 → 12,346.1); consumption per interval is
   small and fluctuates (0.061 kWh in a quarter hour).
4. **Does the time stamp mark the start or the end of the interval?** Many
   grid operators stamp the end: the quarter hour from 23:45 to 0:00 then
   carries “0:00” of the next day.

Plus the unit: kWh, Wh or MWh. Water and gas meters count in m³.

## 3. Step by step

**Consumption → *utility* → ⚙️ Meters**, on the meter you want, **“Import time
series”** (for every utility with meter readings, not for heating oil and
pellets). Create the meter beforehand, with the right role and recording.

1. **“Choose file”** — the first lines of the file appear in the dialog so you
   can see the columns.
2. **“Header rows”** — the number of lines before the data (question 1).
3. **“Date (and time) column”**, **“Time column (if separate)”** and
   **“Value column”** — the list counts the columns from 1 and shows the column
   name from the header line.
4. **“The values are”**: “Consumption per interval” or “Meter readings”
   (question 3).
5. **“Unit of the values”**: kWh, Wh or MWh for utilities in kWh; for water and
   gas the unit of the meter.
6. **“Time stamp marks”**: “the start of the interval” or “the end of the
   interval” (question 4).
7. **“Start value (meter reading)”** — only for consumption values on a meter
   with readings: the reading at the start of the first day. Empty means the
   reading on that day.
8. **“Preview”** — the app reads the file without saving anything and shows
   e.g. “365 days from 01/01/2025 to 31/12/2025, 3,412.6 kWh in total” (for meter
   readings “the last reading of each day”) and how many lines without a date
   or value it skipped.
9. **“Import”** writes.

The app detects the separator (semicolon, tab, comma), decimal comma or point
and the character encoding (UTF-8 or Windows-1252) itself. The browser
remembers the mapping per meter; next month, file, preview and import are
enough.

## 4. What the app makes of it

| Values in the file | Meter with | Result |
|---|---|---|
| Meter readings | meter readings | the **last reading** of each day as a reading; an existing reading on the same day is replaced |
| Consumption per interval | consumption per period | one **period** per day with the day’s total — like the [period import](../verstehen/15-waerme.md#2-consumption-per-period); days that overlap existing periods are skipped |
| Consumption per interval | meter readings | **readings**, summed up from a starting reading — the reading on the first day or the start value. The reading at the end of a day sits on the next day (0:00) |
| Meter readings | consumption per period | refused: “The column mapping does not fit the file …” |

Without a starting reading the app asks for one: “Consumption values need a
starting reading: a reading on … or a start value”.

After that everything calculates as with readings you type in: monthly
consumption, costs, balance, forecast, plausibility. Daily values are stored,
not quarter hours — the app’s evaluations work in days and months.

## 5. Time stamps and daylight saving time

- **Formats:** `DD.MM.YYYY HH:MM`, `YYYY-MM-DD HH:MM` (seconds allowed) and
  ISO 8601 with time zone, e.g. `2025-03-30T01:00:00+01:00` or `…Z`. A date
  alone works too (daily values) — then choose “the start of the interval”,
  otherwise every value lands on the previous day.
- **Time zone:** stamps without one count in the time zone of the installation
  (Settings → General → Time zone). Stamps with one are converted into that
  time zone before the app determines the day.
- **Daylight saving time:** the day of the change has 23 or 25 hours, i.e. 92
  or 100 quarter hours. The app counts them all towards their day; nothing is
  missing, nothing doubled.
- **End stamp at 0:00:** with “the end of the interval”, an interval that ends
  at 0:00 belongs to the previous day.

The app reads a year of quarter-hour values (35,040 lines) into 365 days in a
few seconds.

## 6. Examples

All files are made up; in real portals the columns are named differently.

**Load profile from the grid operator** — quarter hours in kWh, end stamp,
date and time separate:

```text
Zählpunkt;DE0000000000000000000000000000000
Datum;Uhrzeit;Wert (kWh);Status
01.01.2025;00:15;0,061;W
01.01.2025;00:30;0,058;W
…
02.01.2025;00:00;0,071;W
```

Header rows 2 · date column 1 · time column 2 · value column 3 · “Consumption
per interval” · kWh · “the end of the interval”. On an electricity meter with
readings, the reading of 1 January is needed — as a reading or as the start
value.

**Inverter** — daily values in Wh, one date per line:

```text
Date,Energy [Wh]
2025-06-01,18230
2025-06-02,21045
```

Header rows 1 · date column 1 · value column 2 · “Consumption per interval” ·
Wh · “the start of the interval”. If the portal offers the inverter’s total
counter, “Meter readings” is simpler: then no start value is needed.

**Heat pump** — meter reading of the heat output per day, for a Heat meter with
the role “Heat pump output” (for the
[seasonal performance factor](../verstehen/15-waerme.md#7-seasonal-performance-factor-of-the-heat-pump-v310)):

```text
Zeit;Wärmemenge gesamt [kWh]
2025-10-01T00:00:00+02:00;18412,3
2025-10-02T00:00:00+02:00;18431,9
```

Header rows 1 · date column 1 · value column 2 · “Meter readings” · kWh ·
“the start of the interval”.

## 7. Limits

- **No fetching.** The app retrieves nothing from portals; for ongoing values
  without a file, [Home Assistant](home-assistant.md) is the way.
- **Daily values, not quarter hours.** Billing per quarter hour, as a dynamic
  tariff needs it, is not reproduced by the app
  ([Electricity → Dynamic tariffs](../verstehen/02-strom.md#dynamic-tariffs-v310)).
- **Faulty lines** without a recognisable date or value are skipped, and the
  first twenty are named (“Line 12: no date or value recognised …”). If the
  mapping does not fit at all, the app says so instead of writing anything.
- **Replace rather than add** for meter readings: a reading from the file
  replaces one of your own on the same day. If you want to keep your readings,
  check the period in the preview and shorten the file if necessary.

## 8. Via the API

`POST /api/utility/{u}/meters/{id}/import-series` with `?dry_run=1` for the
preview; in the body the file as text and the mapping with columns from 0:

```json
{ "csv": "Datum;Uhrzeit;Wert (kWh)\n01.01.2025;00:15;0,061\n…",
  "mapping": { "skip_rows": 1, "date_col": 0, "time_col": 1, "value_col": 2,
               "value_kind": "consumption", "unit_factor": 1,
               "interval_stamp": "end", "start_counter": 40211.0 } }
```

All fields and error codes:
[API reference → Import time series](../referenz/api.md#import-time-series-v310).

---

[← Compendium index](../README.md)
