# Enter and check the annual bill

[Deutsch](../../anleitungen/jahresabrechnung.md) · **English**

[← Compendium index](../README.md)

Take a gas bill into the Energietracker, recalculate it and book the credit.
Afterwards the app shows the same balance as the bill, and the forecast carries
on with the new advance. Electricity, water and district heating work the same
way without step 2 — since v3.1.0 including the bill check, and the bill’s
figures can be recorded, compared and booked as a special payment (step 7). For
water the special payments of steps 6 and 7 exist only since v3.5.0
([#34](https://github.com/Bingerminger/energietracker/issues/34)); before, a
booking there never arrived in the contract.

---

## The bill — an example

All figures are invented, but calculated through; the part of a German gas bill
that matters looks roughly like this (German terms in brackets):

```text
Stadtwerke Musterstadt · Gas Basis 24            Billing period 01.01.2025 – 31.12.2025

Readings (Zählerstände)   01.01.2025   12,480 m³   type K (customer reading)
                          31.12.2025   14,010 m³   type S (estimate)
Consumption                             1,530 m³

Period                Volume   Correction    Calorific      Energy
                               (Zustandszahl) (Brennwert)
01.01.–30.06.2025     910 m³   0.9520        11.280 kWh/m³   9,772 kWh
01.07.–31.12.2025     620 m³   0.9505        11.310 kWh/m³   6,665 kWh
                                                            16,437 kWh

Unit price (Arbeitspreis)      16,437 kWh × 9.20 ct/kWh     1,512.20 €
Standing charge (Grundpreis)   12 months × 11.90 €            142.80 €
Invoice total (gross)                                       1,655.00 €
Advances paid (Abschläge)      12 × 150.00 €                1,800.00 €
Credit (Guthaben)                                             145.00 €

Your new advance from 01.03.2026: 140.00 €
```

| On the bill | In the app |
|---|---|
| Readings with date and reading type | two readings on the gas meter (step 1) |
| Volume correction factor and calorific value per period | gas conversion factors (step 2) |
| Unit price, standing charge, advance | the contract (step 3) |
| Energy and costs per period | Check a bill (step 4) |
| Credit or back-payment | the contract's balance (step 5), then booked as a special payment (step 6 or 7) |
| Invoice total, advances, result, levies, CO₂ details | “According to the bill” (step 7) |
| New advance | on the contract, with its date (step 6) |

## 1. Readings

**Consumption → Gas → “+ Reading”**: the readings at the start and at the end of
the billing period, with their dates. If the supplier estimated (on the bill
for instance “S” or “estimated” — the legend is there), tick **“Estimated /
corrected value”**. If there already is a reading on that date, the app asks
whether to replace it.

> Two readings are enough. If you have your own readings in between, the app
> calculates the months more precisely — the total for the year does not change.

## 2. Conversion factors (gas only)

The gas meter counts cubic metres, the bill charges kWh. **Settings → Utilities
& billing → Gas conversion factors**: one row per period of the bill with
**Valid from**, **Volume correction factor** and **Calorific value** — in the
example

| Valid from | Volume correction factor | Calorific value (kWh/m³) |
|---|---|---|
| 01.01.2025 | 0.9520 | 11.280 |
| 01.07.2025 | 0.9505 | 11.310 |

If the bill states the calorific value in MJ/m³, “Enter calorific value in”
switches the unit. Without entries of your own the app uses the default
11.5 kWh/m³ — then every kWh figure differs from the bill.

## 3. Contract

**Consumption → Gas → “⚙️ Manage contracts”** → **“+ New contract”**, or edit
the running contract:

- **Provider** and **tariff**, **start**, the end or **“Renews unless
  cancelled”**, plus the **notice period** — reminders and the switch date come
  from these.
- **Unit price** 9.20 ct/kWh, **standing charge** 11.90 €/month and **advance
  payment** 150 €, each from 01.01.2025. If the bill states the standing charge
  per year, divide by 12.
- Prices **gross**, i.e. including VAT, as on the bill — the app calculates with
  exactly the amounts you enter.
- If a price changed during the year, add another row with the date of the
  change; it applies from that day.

**District heating** has its own fixed costs since v3.1.0: in the contract
dialog the lists **“Capacity charge”** (€ per kW and year) and **“Metering
charge”** (€ per year), each with an effective date, and in the field group
“District heating: connection and key figures” the **connected load (kW)**. The
app turns these into fixed costs per month
([District heating](../verstehen/04-fernwaerme.md#fixed-costs-capacity-and-metering-charge-v310)).
**Water** has drinking water, wastewater and rainwater
([Water](../verstehen/03-wasser.md)).

**Peak and off-peak (since v3.1.0).** If the electricity bill charges two
registers with one standing charge, create both as meters, combine them into a
**group** (⚙️ Meters → "Group meters") and create the contract for the group:
under “Meter” the group, under **“Unit price per meter (e.g. peak/off-peak)”**
each register’s price. Standing charge and advance payment count once
([Electricity](../verstehen/02-strom.md#peak-and-off-peak-one-contract-for-a-meter-group-v310)).

## 4. Recalculate

**Costs & contracts → Check a bill** (or on the utility’s page “Check the …
bill”). Since v3.1.0 the check exists for **gas, electricity, water and district
heating** — at the top you choose the utility, next to it the meter if there
are several. Since v3.4.0 the page calculates at once, on both routes, with the
last completed billing period: settlement date under Settings → Utilities &
billing, default 1 January — in the example **From** 01.01.2025 and **To
(inclusive)** 31.12.2025, as on the bill. If the bill’s period differs, change
both dates and **“Recalculate”**. Switching utility or meter keeps the period
and recalculates.

The table cuts the period into sections — like the lines of the bill:

- **Gas:** at every reading and every change of calorific value; per section
  cubic metres, volume correction factor, calorific value and kWh.
- **Electricity, water, district heating:** at every reading and every
  effective date of the contract — start, end, price change, change of advance
  (boundary “Price change”).

Each section also shows **Price** (ct per kWh), **Consumption cost** and
**Fixed costs** — calculated with the same building blocks as the monthly table
and the balance: the unit price on the day, the standing charge by calendar days
of the month, for district heating also the capacity and metering charges. For
water the consumption cost is drinking water and wastewater per m³, the fixed
costs are the standing charge and rainwater. Below it the total:
“Recalculated: …”, less any bonus credited within the period.

Each reading says where it comes from: no suffix = read, **S** = entered as
estimated, **E** = a substitute value the app determines to the day between two
readings, exactly where the supplier estimates too.

**Group contract (peak/off-peak).** The page calculates per meter: the first
register of the group carries the fixed costs, the second only its consumption
— the sum of both is the bill. The API recalculates both at once, with the
sections one after another and the fixed costs once
(`GET /api/utility/strom/meter-groups/{id}/bill-check`,
[API reference](../referenz/api.md#group-contract-v310)).

If the cubic metres match but the kWh do not, a factor period is usually
missing (step 2). The split over the sections can differ by a few cubic metres
because the supplier estimates the intermediate reading differently; the total
stays the same. If the contract has no price for part of the period, the page
says so — costs are missing there too.

## 5. Compare the balance

**Consumption → Gas**, table **Contracts & advances**: for the 2025 contract it
shows *Consumed* 1,655.00 €, *Paid* 1,800.00 € and a balance of **+145.00 €** —
a credit, as on the bill. The sign follows the customer's side: + is credit,
− additional payment.

If the amount differs, it is almost always one of these: a missing factor, a
price with a different date, an advance that was not paid every month, or a
refund or back-payment from the previous year that has not been booked yet
(step 6 of the last bill).

## 6. Book the credit or back-payment

The credit is only paid after the bill — it is booked as a **special payment**
in the contract (**“+ Special payment”**), always as a positive amount. Two
cases:

- **The contract continues** (the same contract across the turn of the year):
  kind **“Refund (affecting advances)”**, amount 145.00 €, plus the **new
  advance** 140 € from 01.03.2026. The balance settles the credit, and from
  March the app continues with 140 €.
- **The contract ends with the bill** and a new one starts: in the old contract
  **“Refund (not affecting advances)”** of 145.00 € — its balance is then at
  zero —, and in the new contract the advance of 140 € from 01.03.2026 as an
  ordinary advance row.

A **back-payment** is booked the same way, with the kind “Back-payment …”. Do not
do both: if you have already entered the new advance as an advance row, use the
kind “not affecting advances” ([Special payments](../verstehen/10-sonderzahlungen.md)).

Since v3.1.0 this also works with one button from the recorded bill (step 7) —
then always as the kind “not affecting advances”.

## 7. On the bill: enter, compare, book

Since **v3.1.0** the app keeps the supplier’s bill itself and sets it against
its own calculation. On **Check a bill**, below the table, is the collapsed
section **“Record the supplier’s bill (optional)”** for the selected meter,
with the card **“According to the bill”** and the list of recorded bills.
Since v3.4.0 it uses the period from above (step 4) — one period for checking
and recording.

**Enter.** From the example:

| Field | Entry |
|---|---|
| Period | at the top under “From” / “To (inclusive)”: 01.01.2025 / 31.12.2025 — the last day counts, as on the bill |
| Bill date | the bill’s date, say 10.02.2026 |
| Consumption on the bill (kWh) | 16,437 — for water in m³ |
| Bill amount (incl. VAT) | 1,655.00 |
| Advance payments made | 1,800.00 |
| Additional payment / credit | leave empty: the app calculates amount − advances = −145.00. **Positive = additional payment**, negative = credit |
| Other items | levies, fees, credits with description and amount (credit negative) — the app does not recalculate them but adds them to its own total |
| Emissions (kg CO₂), CO₂ cost on the bill | gas only: the bill’s CO₂ details, the amount as stated there (including VAT, CO2KostAufG § 3(3)). They take precedence over the standard factor ([CO₂ price](../verstehen/16-co2-preis.md)) and, for tenants, set the deadline for the refund ([Share CO₂ costs](co2-aufteilung.md)) |
| Attach bill (PDF or photo) | the receipt; the list shows it as 📄 |

**“Save bill”** creates it and shows the comparison straight away.

**Compare.** The list below shows every bill with period, quantity, bill amount
and additional payment/credit. **“Compare”** shows a table **Recalculated · On
the bill · Difference** for quantity, amount and advances paid, plus a verdict:

- **“matches”** — quantity within 1 % and amount within 1 % or 2 €;
- **“no prices”** *(v3.7.0)* — the quantity matches (or is missing), but the
  contract lacks a price for part of the period: the amount cannot be
  recalculated. The page names the sections and the contract and links to the
  contracts;
- **“check”** — otherwise; without quantity and amount there is no verdict. If
  the quantity differs and prices are missing as well, it stays “check”, and
  the notice about the missing prices still appears below, with the sections
  and the jump to the contracts *(v3.7.0)*.

Below it the possible reasons for a difference, for example: an estimated or
interpolated reading at an edge of the period, a price change within the
period, a calorific value on the bill that differs from the gas conversion
factors in the settings (both values shown, with a link to the setting), other
items the app only takes over, a missing price in the contract or missing
readings. Since v3.7.0 a reading on the last day of the period (or on the day
before the first) counts as a boundary reading, as on the bill, not as an
estimate, and a change of calorific value that the gas conversion knows just
as the bill splits it is no reason. Recalculated means: consumption cost +
fixed costs − bonus + other items; the classification does not change it.

**Book.** **“Book as special payment”** adds the result as a special payment in
the contract — a back-payment or refund **“not affecting advances”**, amount
without sign, date = bill date (without a date the day after the period), note
“Annual bill … – …”. The contract is the one that applies at the end of the
period if it bills most of it; otherwise the one that does (e.g. the old
contract of a final bill that runs one day past its end) *(v3.7.0 — up to
v3.6 the app took the meter’s newest contract, even if it did not touch the
period)*. Afterwards the bill shows “booked”; booking again changes nothing. Enter
the new advance as an advance row on the contract (step 6). Without a result or
without a contract for the period the app refuses and says why.

**Delete** (🗑️) asks first. A special payment already booked stays in the
contract; delete it there if you want it gone.

**With a group contract** you record “According to the bill” on one of the
registers. The comparison still recalculates the whole group — both registers,
the fixed costs and the advance once — because the supplier sends one bill for
both. “Book as special payment” books into the group contract.

## 8. Afterwards

- The **balance card** now calculates up to the end of the current billing
  period, the day before the next settlement date, and suggests an advance.
  The **settlement date** (Settings → Utilities & billing; default 01-01) is
  the **first day** of the billing period: 01-09 means 1 September to
  31 August. If the suggestion is far from the supplier's new advance, the
  forecast is worth a look.
- Once you have booked the result (“Book as special payment”), the balance of a
  contract that runs on counts from the day after the bill since v3.6.0 — the
  billed time and the refund or additional payment no longer count. Without
  booking the result stays in the balance.
- Next time, **Check a bill** suggests the latest completed period in the
  rhythm of your recorded bills.
- With a notice period entered, the app reminds you in time before the last day
  to cancel; **Costs & contracts → Tariff switch** compares offers.
- **Billing year (v3.6.0):** switch the utility page to *Billing year*, and
  figures, chart and monthly table show exactly the period of the recorded
  bill, for example 15 Apr to 30 Mar, with advance payments and balance;
  between and after the bills the settlement date cuts. The current period
  starts the day after the bill. If the bill does not end the day before your
  settlement date, the view suggests the matching day; without a recorded bill
  *(v3.7.0)* the start of the contract after the latest change of supplier or
  the day on which several contracts begin. On this device the
  choice also applies to the overview and the analysis; the annual report has
  the appendix “Billing years” for it, the CSV export a table per period
  (Settings → Data).
- **Changed supplier:** if another supplier’s contract begins in the middle
  of a period and no final bill is recorded yet, the period ends on the last
  day with the old supplier (“ends with the change of supplier”) and the next
  one starts with the new contract — just as the old supplier issues its final
  bill. A renewal or a new tariff with the same supplier does not split. A gap
  between a cancelled old contract and the new one is a partial period of its
  own; if the old one renews, it runs until the day before the new one. A
  recorded bill always comes first. *(v3.7.0)* If the change lies only a few
  days next to the settlement date — settlement date 31-08, new contract from
  1 September —, there is no one-day period: remainders under 14 days join the
  neighbouring period. The totals stay the same. *(since v3.9.0)* If a supply
  begins and ends at a change of supplier and lasts at most 400 days (a one-year
  contract), it is one period up to the end of the contract: the supplier then
  bills with the final bill, and the settlement date does not apply within it.
  If the settlement date does not match the last bill or the contracts, the hint
  also appears on the balance card and under “Costs & contracts”.
- **Current year in both views:** the calendar year counts the standing
  charge and advance payment of the month of the last reading in full, the
  billing year only the days up to the reading; the ⓘ next to “Advances up to
  …” says so. Whole years are the same in both views.
- Next year the same steps — usually only 1, 2 and 7.

---

[← Compendium index](../README.md)
