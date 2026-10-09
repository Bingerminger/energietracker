# Record charging electricity for a company car

**English** · [Deutsch](../../anleitungen/ladestrom-nachweis.md)

[← Compendium index](../README.md)

If you charge a company car at home, your employer can reimburse the
electricity. In Germany the letter of the Federal Ministry of Finance (BMF) of
11 Nov 2025 sets out how: the employer reimburses the charged amount either at
the price of the electricity contract or with a flat electricity rate per kWh.
Since **v3.1.0** the Energietracker compiles a monthly statement for this — as
a card in the app, as CSV and as a PDF with meter readings and a signature
line.

> **A statement, not tax advice.** The app calculates with your meter readings
> and contracts. Whether and how the reimbursement is tax-free in your case is
> for payroll or a tax adviser to decide.

---

## 1. What you need

- A **separate meter for the wall box** — usually an intermediate meter behind
  the household meter, sometimes a meter of the grid operator of its own
  (§ 14a EnWG, module 2, see
  [Electricity](../verstehen/02-strom.md#controllable-consumers-v310)). Without
  a meter of its own, the charged amount cannot be shown. The BMF letter also
  accepts a mobile meter or one built into the wall box or the car (para. 27)
  — what matters is that the charged amount is measured.
- **Meter readings** of the wall box, ideally at the turn of the month. A
  month’s amount is the difference of the readings, spread over the months day
  by day; readings on the first of the month make it exact.
- A **paying contract**: a contract of the wall box itself or the contract of
  the household meter it sits behind. The flat rate needs none.

## 2. Set up the wall box as a meter

**Consumption → Electricity → ⚙️ Meters → “+ New meter”**:

1. **Name**, e.g. “Wall box”.
2. **Role: “Wallbox (EV charger)”.** Only with this role does the card
   “Charging record (company car)” appear.
3. **Parent meter:** the household meter, if the wall box sits behind it. It
   is then a sub-meter; its consumption is contained in the parent meter and
   subtracted there, not counted twice
   ([Meter topology](../verstehen/13-meter-topologie.md)). If the wall box has
   a meter of the grid operator with a contract of its own (module 2), leave the
   field empty.
4. Enter the first reading with its date, then read monthly — by hand, via
   [Home Assistant](home-assistant.md) or from a file of the wall box portal
   ([Time series from portals](daten-aus-portalen.md)).

## 3. The card “Charging record (company car)”

It sits in the consumption view of the wall box meter below the tables:

| Field | Meaning |
|---|---|
| **Year** | the current year and the three before; the previous year is preselected |
| **Price** | “Contract price with pro-rata standing charge” or “Flat electricity rate” |
| **Flat rate per kWh (cents)** | flat rate only; prefilled with the value from the country profile, can be overwritten |

Below that it says “Charged: … kWh · to reimburse: …”, with the buttons
**“Download CSV”** and **“Download PDF”**. If some months have no contract
price, the card says so; those months count without an amount.

## 4. The two methods

**Contract price with pro-rata standing charge.** The calculation uses the
contract that pays for the electricity: the wall box’s own contract, otherwise
the parent meter’s.

```text
price per kWh (month)  = energy cost of the paying meter / its kWh   (day-exact)
standing charge share  = standing charge of the month × kWh wall box / kWh parent meter
amount                 = kWh wall box × price per kWh + standing charge share
```

If the price changes mid-month, the monthly price is the average by days and
consumption — the way the bill forms it. If the wall box has a contract of its
own, it carries that contract’s full standing charge.

> **Example (made up).** In March the household meter counts 400 kWh with
> €120.00 energy cost, i.e. 30 ct/kWh; the standing charge is €12.00. The wall
> box charged 100 kWh: 100 × €0.30 = €30.00 plus €12.00 × 100 / 400 = €3.00
> standing charge share — €33.00 in total.

Splitting the standing charge by kWh is an assumption: the letter only
requires it to be taken into account “pro rata”. Without a paying contract the
app refuses (“There is no paying contract for the wall box …”).

**Flat electricity rate.** Charged kWh × flat rate. For Germany the country
profile knows the flat rate for **2026: 34 ct/kWh**. Example from the BMF
letter: 3,000 kWh × €0.34 = €1,020.00. For a year without a stored flat rate,
enter it yourself in the field “Flat rate per kWh (cents)” (0 to 200 cents);
without a value the app reports “No flat electricity rate is known for {year} —
enter it yourself”.

**One method per year.** Choose one of the two methods for a calendar year and
stick with it; switching within the year is not intended. The app calculates
each year on its own and does not remember the choice.

## 5. CSV and PDF

**CSV** (`energietracker-ladestrom-YYYY.csv`): one row per month. In format 1
the header is fixed:

```text
Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode
2026-03;m_wallbox;100;30;3;33;contract
```

Numbers and separators follow the rules of format 1
([CSV formats](../referenz/api.md#csv-formats-v310)). With `format=local`,
header, decimal separator and date are in the language of the installation,
for a spreadsheet.

**PDF** (`energietracker-ladestrom-YYYY.pdf`): the statement for printing —
meter with serial number, method (for the flat rate with the rate), per month
the first and the last meter reading in the month, kWh, cents per kWh,
standing charge share and amount, the total, a paragraph on the basis of the
calculation, the note “not tax advice” and a line for date and signature. The
PDF is produced in the default language of the installation; for a language the
built-in PDF fonts cannot set, the app answers `422`.

## 6. Limits

- **A self-made record alone is not enough.** Attach the electricity contract
  and the bill to the statement; the note on card and PDF says so too.
- **Estimated months.** If a month does not have two readings, the app spreads
  the difference of the surrounding readings by days. The API marks such
  months with `estimated: true`.
- **No contract price, no amount.** Months in which the paying contract has no
  unit price count with their amount of energy but without an amount of money
  (`price_missing`).
- **Monthly prices.** If the contract has prices per month (e.g. a dynamic
  tariff, entered with “Import monthly prices”), the contract price follows
  them ([Electricity](../verstehen/02-strom.md#dynamic-tariffs-v310)).
- **Several wall boxes:** one record per meter.
- **Self-employed.** For the self-employed with a business EV the same applies
  under the BMF letter of 21 Jul 2026; there the choice applies per financial
  year (as of 9 Oct 2026).

## 7. Via the API

```text
GET /api/reports/ev-charging?meter_id=m_wallbox&year=2026&method=contract
GET /api/reports/ev-charging.csv?meter_id=m_wallbox&year=2026&method=flat&flat_ct=34
GET /api/reports/ev-charging.pdf?meter_id=m_wallbox&year=2026&method=contract&inline=1
```

Fields, error codes and limits:
[API reference → Charging record](../referenz/api.md#charging-record-v310).

---

[← Compendium index](../README.md)
