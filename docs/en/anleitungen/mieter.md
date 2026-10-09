# As a tenant: heating, water and service charges at a glance

[Deutsch](../../anleitungen/mieter.md) · **English**

[← Compendium index](../README.md)

Since v3.1.0 the Energietracker also helps if you rent and pay for heating and
water through the service charges to your landlord. You see your consumption
month by month, the app estimates whether the prepayment covers the current
billing period, keeps the service charge statements including the PDF and puts
the deadlines into your calendar.

> **An estimate, not a service charge statement.** The app calculates with your
> meters and the prices of the last statement. What the landlord bills may
> differ. It gives no legal advice: this page names laws for orientation, without
> guarantee. The deadlines and rules described are those of German law.

**What you need:**

- the **monthly consumption information** from the metering service (heating,
  often hot water too) — via portal, app or letter,
- the **last service charge statement** — for the billing period, prices and
  prepayment,
- if you read them yourself: the readings of the water meters in the flat.

---

## 1. Set how you live

**Settings → Household & building → “Home and hot water” → “I live”: “in a
rented home”.**

After that the **Costs & contracts** area has the page **“Tenancy”**, and the
calendar and “To do” know two new deadlines
([section 8](#8-deadlines-in-the-calendar)). If you live “in my own home” (the
default), nothing changes.

In the same group:

| Field | What for |
|---|---|
| “Heat comes from” | Energy source of the heating in the building (gas, heating oil, pellets, district heating, electricity) — for the CO₂ value of the heat. An approximation, see [Heat](../verstehen/15-waerme.md#5-co₂--an-approximation). |
| “Hot water is heated by” | For information only. |
| “Hot water temperature” | For the heat of the hot-water meters, default 60 °C. Unknown: leave it. |

---

## 2. Switch on Heat

Tick **Settings → Utilities & billing → Active utilities → Heat**.

Heat is the warmth that arrives in your flat — not the fuel in the basement. It
has no supplier contracts; it is paid through the tenancy. Background:
[Heat](../verstehen/15-waerme.md).

---

## 3. Create a meter for the consumption information

**Consumption → Heat → Meters → “+ New meter”:**

- **Name:** free, for example “Flat heating”.
- **Role:** “Home consumption” (default).
- **Recording:** **“Consumption per period”**. The consumption information
  states the month’s consumption, not a meter reading — exactly what this way
  of recording is for.

The way of recording can only be changed while the meter has no data. If you
have a heat meter of your own in the flat whose reading you can take, choose
“Meter readings” instead.

**Every month:** **Meter readings** (or “Add” on the phone). The meter has
a card of its own there:

1. **Month** — pre-filled with the month after the last entry.
2. **Consumption (kWh)** — the value from the consumption information.
3. Expandable **“Comparison values from the consumption information”**:
   previous month, same month last year, average user. Optional; the app keeps
   them and shows them in the table, but does not calculate with them.

Without a network the entry goes into the queue and is sent later.

**Older months at once:** Consumption → Heat → Meters → **CSV import**, one
line each with month and consumption:

```text
Month;Consumption
01.2026;1180
02.2026;960
03.2026;720
```

(Example values.) Instead of `01.2026`, `01/2026` or `2026-01` work too; instead
of a month, `from;to;consumption` with two dates. The preview first shows what
would be read; months that already exist are skipped and reported.

You edit or delete single periods under Consumption → Heat in the table
**“Periods”**.

---

## 4. Hot and cold water

In most rented flats a cold-water and a hot-water meter sit side by side. Under
**Consumption → Water → Meters** create one meter for each:

| Meter | Role | Parent meter |
|---|---|---|
| Cold water | “Cold water” | none |
| Hot water | “Hot water” | none |

Both stand **side by side**, not as sub-meters — their sum is the fresh water of
the flat.

**Recording:** “Meter readings” if you read the meters yourself; “Consumption
per period” (in m³) if the consumption information states the hot water
monthly.

For the hot-water meter the app works out how much heat the water needed —
“Heat for this hot water in {year}: about … kWh” below the table in the water
consumption view. A calculated value under HeizkostenV § 9 (2.5 kWh per m³ and
degree above 10 °C), not a measurement;
[Water](../verstehen/03-wasser.md#heat-for-the-hot-water-v310).

As a tenant you do not need a water contract — you pay for water through the
service charges.

---

## 5. Create the tenancy

**Costs & contracts → Tenancy → “+ Tenancy”.**

| Field | What to enter |
|---|---|
| Name | free, for example “Flat” — appears in the calendar deadlines |
| Landlord or managing agent | optional |
| Floor area per tenancy agreement (m²) | *(v3.1.0)* the area from the tenancy agreement; empty means the living area from Settings → Household & building applies. Basis of the CO₂ stage |
| Start / End | moving-in date; leave the end empty while it runs |
| Billing period starts on (DD.MM.) | first day of the billing period as on the statement, for example `01.01.` for the calendar year or `01.07.` |
| Prepayments | per row: **From**, “Heating € per month”, “Operating costs € per month”. If the prepayment changes, add a new row with the new date — the old one stays for the past. |
| Prices | per row: **From**, “Heat € per kWh”, “Hot water € per m³”, “Cold water € per m³”. From the last statement; the app can take them over itself ([section 7](#7-record-a-statement-and-take-over-prices)). |
| Flat charges | per row: **From**, name, “€ per year” — what is not billed by consumption: waste, cleaning, insurance, cable … |
| Assigned meters | Heating: the Heat meter; hot water and cold water: the water meters |
| CO₂ costs (CO2KostAufG) | *(v3.1.0)* “Gas also for own appliances (e.g. gas cooker)” and “Public-law restrictions (§ 9)” — reductions of the landlord’s share of the CO₂ costs ([Share CO₂ costs](co2-aufteilung.md#5-self-contained-heating-work-it-out-and-claim-it)) |
| Note | free |

A meter without a tick does not go into the calculation. The app calculates the
cold-water price **including sewage** (both per m³ of fresh water).

In Germany the page also shows, since v3.1.0, the card **“Sharing CO₂ costs”**:
which part of the CO₂ costs of heating the landlord bears — to claim with your
own gas boiler, to check the heating cost statement with central heating. How:
[Share CO₂ costs with the landlord](co2-aufteilung.md).

> **Tip on flat charges.** If you enter a prepayment for operating costs, the
> operating costs belong in the flat charges too — otherwise there is no
> expense against that prepayment and the calculation shows too large a credit.
> If you only want to watch the heating, leave both out.

---

## 6. Read the estimate

The first card, **“Prepayment and costs”**, calculates the **current billing
period** — from the billing date to one year later; the first period starts on
moving in.

| Display | Meaning |
|---|---|
| Expected costs | what the whole period is likely to cost |
| Prepaid | total of the prepayments in the period |
| Likely outcome | “… additional payment”, “… credit” or “about even”, plus an assessment |
| Suitable prepayment per month | expected costs divided by the months of the period, rounded up to whole euros |

The **assessment**:

| Display | When |
|---|---|
| “Little additional payment expected” | outcome is a credit or even |
| “Small additional payment possible” | additional payment up to 10 % of the prepayment |
| “Additional payment likely” | more than 10 % |

Without a prepayment there is no assessment.

**How the app calculates, per month:**

```text
expected = heat kWh × heat price
         + hot water m³ × hot-water price
         + cold water m³ × cold-water price
         + flat charges / 12
paid     = prepayment heating + operating costs
```

- **Measured months** come from the assigned meters.
- **The other months are estimated:** heat from the heating model with the
  long-term average weather (climate normal), otherwise from the same month last
  year, otherwise from the daily average of the known months. In the table
  “Month by month” they carry the note “estimated”.
- Months at the edge of the period count in proportion.
- If something is missing, it is listed below the card: no price for heat, hot
  water or cold water, no prepayment for part of the period, no meters
  assigned, number of estimated months.

> **Example** (round example figures, heating only): heat price €0.15 per kWh,
> expected heat for the year 9,000 kWh, prepayment for heating €120 a month.
>
> ```text
> expected  = 9,000 kWh × €0.15  = €1,350
> paid      = 12 × €120          = €1,440
> outcome   = €1,350 − €1,440    =   −€90   → €90 credit
> suitable prepayment = €1,350 / 12 = €112.50 → €113
> ```
>
> The card shows “€90 credit” and “Little additional payment expected”.

---

## 7. Record a statement and take over prices

When the service charge statement arrives, enter it under **“Service charge
statements” → “+ Statement”**:

| Field | What to enter |
|---|---|
| Period from / to | billing period as on the statement |
| Received on | the day the statement arrived — the app calculates the objection deadline from it |
| Total costs / Prepaid | the totals of the statement; the app calculates the outcome (positive = additional payment) |
| Heat consumption on the statement (kWh) / Heating costs | from the heating-costs section |
| Items | per row name, type (heating, hot water, cold water, sewage, operating costs, other), amount and — for water — the consumption in m³ |
| New prepayment as per the statement | from, heating and operating costs per month |
| Attach PDF or photo | the statement itself, as a receipt |

Two tick boxes, both preselected:

- **“Take over prices from it (from the day after the period)”** — the app
  calculates the prices and adds them to the tenancy with the source
  “statement”:

  ```text
  heat        = heating costs / heat consumption   (no heating costs: sum of the “heating” items)
  hot water   = “hot water” items / their m³
  cold water  = (“cold water” + “sewage” items) / m³ of the “cold water” items
  ```

  If a consumption is missing, that price is missing. If you save the same
  statement again, the app replaces its price row instead of adding a second.
- **“Take over the new prepayment”** — the new prepayment goes into the list of
  prepayments.

> **Example** (example figures): heating costs €1,200 for 8,000 kWh → €0.15 per
> kWh. Hot water €300 for 25 m³ → €12 per m³. Cold water €150 and sewage €100 for
> 50 m³ → €5 per m³.

> **What does the water price refer to?** Many statements distribute water and
> sewage by the **total** fresh water (cold and hot) and the heating of the hot
> water separately. If yours does, enter the total fresh water as the
> consumption of the cold-water item and assign **both** water meters under
> “Cold water” in the tenancy, and only the hot-water meter under “Hot water”.

---

## 8. Deadlines in the calendar

With “in a rented home” the app calculates two deadlines. They appear in the
[calendar subscription](kalender.md) and in the dates overview:

| Entry | Date | When it appears |
|---|---|---|
| “Service charge statement up to … due: …” | end of the last completed billing period + 12 months (German Civil Code, BGB § 556 (3)) | as long as no statement is recorded for that period; not under “To do” |
| “Last day to object to the service charge statement: …” | “Received on” + 12 months | only with a receipt date; under “To do” from 30 days before |

With your own gas boiler a third one is added since v3.1.0: “CO₂ cost {year}:
last day to claim the landlord’s share of …” — twelve months after the date of
the gas bill ([Share CO₂ costs](co2-aufteilung.md#7-deadline-in-the-calendar)).

None of them has an advance reminder of its own in the calendar. The app only
calculates the dates; whether and how a deadline applies in a particular case it
does not judge. Tenants’ associations or legal advice can help with questions.

---

## 9. What the Heating Costs Ordinance provides

For orientation, without guarantee — the text of the ordinance (HeizkostenV)
is what counts:

- **§ 6a — monthly consumption information.** Since 2022, tenants with remotely
  read meters or heat cost allocators must be told their heating and hot-water
  consumption every month, with the previous month, the same month last year
  and an average user.
- **§ 5 — retrofitting.** Devices that cannot be read remotely must be
  retrofitted or replaced by 31 December 2026 — unless this is technically
  impossible or unreasonable in the individual case.
- **§ 12 — right to reduce.** If remotely readable devices are missing although
  they are required (installed since 1 December 2021, all others from 2027), or
  the consumption information is missing or incomplete, you may reduce your
  cost share by 3 %; if the costs are not billed by consumption, by 15 %.

If no consumption information arrives although the meters can be read
remotely, ask the landlord or the managing agent.

---

## 10. Limits

- **An estimate.** The landlord’s statement follows its own rules: it splits the
  heating costs into basic and consumption costs, calculates with the fuel costs
  of the whole building and knows items you have not entered. The app
  approximates this with the prices of the last statement.
- **Prices change.** If prices rise during the year, the app only knows once
  you add a new row under “Prices” with a later “From”.
- **Estimated months** are estimates — the more measured months, the better the
  calculation.
- **Not a billing program for landlords.** The app records what you are charged;
  it cannot distribute service charges
  ([use cases](anwendungsfaelle.md)).
- **Moving:** create a second tenancy for the new flat and enter an end date for
  the old one. At the top of the page you choose which one is shown.
- **Deleting:** deleting a tenancy also removes its statements; the attached
  receipts are released and cleaned up after 24 hours.

Technical details:
[API reference](../referenz/api.md#tenancy-v310) ·
[data model](../referenz/datenmodell.md) ·
[views](../referenz/ansichten.md#tenancy-v310).

---

[← Compendium index](../README.md) · [Deadlines and dates in your calendar](kalender.md)
