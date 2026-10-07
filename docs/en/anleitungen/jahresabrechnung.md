# Enter and check the annual bill

[Deutsch](../../anleitungen/jahresabrechnung.md) · **English**

[← Compendium index](../README.md)

Take a gas bill into the Energietracker, recalculate it and book the credit.
Afterwards the app shows the same balance as the bill, and the forecast carries
on with the new advance. Electricity, water and district heating work the same
way without step 2 — since v3.1.0 including the bill check, and the bill’s
figures can be recorded, compared and booked as a special payment (step 7).

---

## The bill — an example

All figures are invented, but calculated through; the part of a German gas bill
that matters looks roughly like this (German terms in brackets):

```text
Stadtwerke Musterstadt · Erdgas Klassik          Billing period 01.01.2025 – 31.12.2025

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
are several. Then **From** 01.01.2025 and **To (exclusive)** 01.01.2026 and
**“Recalculate”**. The link from the utility page fills in utility, meter and
year already.

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
its own calculation. On **Check a bill**, below the table, is the card
**“According to the bill”** for the selected meter.

**Enter.** From the example:

| Field | Entry |
|---|---|
| From / To (inclusive) | 01.01.2025 / 31.12.2025 — here the last day counts, as on the bill |
| Bill date | the bill’s date, say 10.02.2026 |
| Consumption on the bill (kWh) | 16,437 — for water in m³ |
| Bill amount (incl. VAT) | 1,655.00 |
| Advance payments made | 1,800.00 |
| Additional payment / credit | leave empty: the app calculates amount − advances = −145.00. **Positive = additional payment**, negative = credit |
| Other items | levies, fees, credits with description and amount (credit negative) — the app does not recalculate them but adds them to its own total |
| Emissions (kg CO₂), CO₂ cost on the bill | gas only: the bill’s CO₂ details, the amount net, as stated there. They take precedence over the standard factor ([CO₂ price](../verstehen/16-co2-preis.md)) and, for tenants, set the deadline for the refund ([Share CO₂ costs](co2-aufteilung.md)) |
| Attach bill (PDF or photo) | the receipt; the list shows it as 📄 |

**“Save bill”** creates it and shows the comparison straight away.

**Compare.** The list below shows every bill with period, quantity, bill amount
and additional payment/credit. **“Compare”** shows a table **Recalculated · On
the bill · Difference** for quantity, amount and advances paid, plus a verdict:

- **“matches”** — quantity within 1 % and amount within 1 % or 2 €;
- **“check”** — otherwise; without quantity and amount there is no verdict.

Below it the possible reasons for a difference, for example: an estimated or
interpolated reading at a boundary of the period, a price change or a change of
calorific value within the period, other items the app only takes over, a
missing price in the contract or missing readings. Recalculated means:
consumption cost + fixed costs − bonus + other items.

**Book.** **“Book as special payment”** adds the result as a special payment in
the contract — a back-payment or refund **“not affecting advances”**, amount
without sign, date = bill date (without a date the day after the period), note
“Annual bill … – …”. The contract is the one that applied at the end of the
period. Afterwards the bill shows “booked”; booking again changes nothing. Enter
the new advance as an advance row on the contract (step 6). Without a result or
without a contract the app refuses and says why.

**Delete** (🗑️) asks first. A special payment already booked stays in the
contract; delete it there if you want it gone.

**With a group contract** you record “According to the bill” on one of the
registers. The comparison still recalculates the whole group — both registers,
the fixed costs and the advance once — because the supplier sends one bill for
both. “Book as special payment” books into the group contract.

## 8. Afterwards

- The **balance card** now calculates up to the next billing date (Settings →
  Utilities & billing → Settlement date gas; default 1 January) and suggests an
  advance. If the suggestion is far from the supplier's new advance, the
  forecast is worth a look.
- With a notice period entered, the app reminds you in time before the last day
  to cancel; **Costs & contracts → Tariff switch** compares offers.
- Next year the same steps — usually only 1, 2 and 7.

---

[← Compendium index](../README.md)
