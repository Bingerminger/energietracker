# Enter and check the annual bill

[Deutsch](../../anleitungen/jahresabrechnung.md) · **English**

[← Compendium index](../README.md)

Take a gas bill into the Energietracker, recalculate it and book the credit.
Afterwards the app shows the same balance as the bill, and the forecast carries
on with the new advance. Electricity and district heating work the same way
without step 2.

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
| Energy per period | Check a bill (step 4) |
| Credit or back-payment | the contract's balance (step 5), then booked as a special payment (step 6) |
| New advance | on the contract, with its date (step 6) |

## 1. Readings

**Consumption → Gas → "+ Reading"**: the readings at the start and at the end of
the billing period, with their dates. If the supplier estimated (on the bill
for instance "S" or "estimated" — the legend is there), tick **"Estimated /
corrected value"**. If there already is a reading on that date, the app asks
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

If the bill states the calorific value in MJ/m³, "Enter calorific value in"
switches the unit. Without entries of your own the app uses the default
11.5 kWh/m³ — then every kWh figure differs from the bill.

## 3. Contract

**Consumption → Gas → "⚙️ Manage contracts"** → **"+ New contract"**, or edit
the running contract:

- **Provider** and **tariff**, **start**, the end or **"Renews unless
  cancelled"**, plus the **notice period** — reminders and the switch date come
  from these.
- **Unit price** 9.20 ct/kWh, **standing charge** 11.90 €/month and **advance
  payment** 150 €, each from 01.01.2025. If the bill states the standing charge
  per year, divide by 12.
- Prices **gross**, i.e. including VAT, as on the bill — the app calculates with
  exactly the amounts you enter.
- If a price changed during the year, add another row with the date of the
  change; it applies from that day.

## 4. Recalculate

**Costs & contracts → Check a bill** (or on the gas page "Check … bill"): choose
meter and period 01.01.2025 to 31.12.2025. The page shows per section cubic
metres, volume correction factor, calorific value and kWh — the same lines as
the bill. Each reading says where it comes from: no suffix = read, **S** =
entered as estimated, **E** = a substitute value the app determines to the day
between two readings, exactly where the supplier estimates too.

If the cubic metres match but the kWh do not, a factor period is usually
missing (step 2). The split over the sections can differ by a few cubic metres
because the supplier estimates the intermediate reading differently; the total
stays the same.

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
in the contract (**"+ Special payment"**), always as a positive amount. Two
cases:

- **The contract continues** (the same contract across the turn of the year):
  kind **"Refund (affecting advances)"**, amount 145.00 €, plus the **new
  advance** 140 € from 01.03.2026. The balance settles the credit, and from
  March the app continues with 140 €.
- **The contract ends with the bill** and a new one starts: in the old contract
  **"Refund (not affecting advances)"** of 145.00 € — its balance is then at
  zero —, and in the new contract the advance of 140 € from 01.03.2026 as an
  ordinary advance row.

A **back-payment** is booked the same way, with the kind "Back-payment …". Do not
do both: if you have already entered the new advance as an advance row, use the
kind "not affecting advances" ([Special payments](../verstehen/10-sonderzahlungen.md)).

## 7. Afterwards

- The **balance card** now calculates up to the next billing date (Settings →
  Utilities & billing → Settlement date gas; default 1 January) and suggests an
  advance. If the suggestion is far from the supplier's new advance, the
  forecast is worth a look.
- With a notice period entered, the app reminds you in time before the last day
  to cancel; **Costs & contracts → Tariff switch** compares offers.
- Next year the same steps — usually only 1, 2 and 6.

---

[← Compendium index](../README.md)
