# Share CO₂ costs with the landlord

**English** · [Deutsch](../../anleitungen/co2-aufteilung.md)

[← Compendium index](../README.md)

In rented homes in Germany, tenant and landlord share the CO₂ costs of heating —
under the **Carbon Dioxide Cost Allocation Act (CO2KostAufG)**, for billing
periods from 2023. The more CO₂ the building emits per square metre, the larger
the landlord’s share. Since **v3.1.0** the Energietracker works out this share:
with your own gas boiler it claims it for you, with central heating it checks
the heating cost statement.

> **An estimate, not legal advice.** The app calculates with your data and the
> rules of the act, but does not judge whether and how they apply in your case.
> Tenants’ associations or legal advice can help with questions.

What the CO₂ price itself is and how the app calculates it:
[CO₂ price in fuel](../verstehen/16-co2-preis.md).

---

## 1. What you need

- **Settings → Household & building → “I live”: “in a rented home”** and Germany as the
  country. Otherwise the card is missing; the API answers with
  `supported: false`.
- A **tenancy** under Costs & contracts → Tenancy
  ([As a tenant](mieter.md#5-create-the-tenancy)) that applies in the billing
  year, with the **floor area**.
- For your own gas boiler: the year’s **gas consumption** (readings and
  contract) or the **heating oil deliveries** — ideally also the **gas bill**
  with its CO₂ details.
- For central heating: the landlord’s **heating cost statement** with its CO₂
  details.

## 2. Which case are you?

| Your heating | Who calculates | What the app does |
|---|---|---|
| **Self-contained heating** — a gas boiler in the flat with your own gas contract, or heating oil you buy yourself | You pay the CO₂ price through your supplier and claim the landlord’s share yourself (§ 6) | works out the share, writes a letter as a PDF and reminds you of the deadline |
| **Central heating** or district heating via the landlord | The landlord splits the costs in the heating cost statement (§ 7) | recalculates the landlord’s figures and reports differences |

The app picks the case itself: if a service charge statement whose period ends
in the year carries **CO₂ details**, it is central heating. Otherwise it
calculates with your **gas and heating oil data** as self-contained heating.

## 3. The floor area

The **“Edit tenancy”** dialog has the field **“Floor area per tenancy agreement
(m²)”**. Left empty, the living area from Settings → Household & building
applies. Without an area there is no stage; the card then says: “No stage
without the floor area – please enter it in the tenancy or under Household.” If
several tenancies apply in the year, the most recent one counts.

## 4. The ten stages

The yardstick is CO₂ emissions per square metre of floor area and year, rounded
to one decimal place (annex to the CO2KostAufG):

| Stage | kg CO₂ per m² and year | Landlord’s share | Tenant’s share |
|---|---|---|---|
| 1 | below 12 | 0 % | 100 % |
| 2 | 12 to below 17 | 10 % | 90 % |
| 3 | 17 to below 22 | 20 % | 80 % |
| 4 | 22 to below 27 | 30 % | 70 % |
| 5 | 27 to below 32 | 40 % | 60 % |
| 6 | 32 to below 37 | 50 % | 50 % |
| 7 | 37 to below 42 | 60 % | 40 % |
| 8 | 42 to below 47 | 70 % | 30 % |
| 9 | 47 to below 52 | 80 % | 20 % |
| 10 | 52 and above | 95 % | 5 % |

If a heating cost statement covers less than a year, the app shortens the
limits in the same proportion (§ 5(1)).

## 5. Self-contained heating: work it out and claim it

**1. Record consumption.** Gas as usual with readings and a contract, heating
oil via deliveries. From these the app works out the calendar year’s emissions
with the BEHG standard factor and the year’s CO₂ price.

**2. Better: record the gas bill.** Under **Costs & contracts → Check a bill**,
card **“According to the bill”**
([Annual bill](jahresabrechnung.md#7-on-the-bill-enter-compare-book)): there
**“Emissions (kg CO₂)”** and **“CO₂ cost on the bill”** as they are on the bill
— the supplier states them net — and the **bill date**. The app then uses the
supplier’s figures, and the deadline follows from the bill date.

**3. Enter reductions.** In the “Edit tenancy” dialog, field group **“CO₂ costs
(CO2KostAufG)”**:

| Field | Effect |
|---|---|
| “Gas also for own appliances (e.g. gas cooker) – refund −5%” | The landlord’s share drops to 95 % (§ 6(3)). |
| “Public-law restrictions (§ 9)”: none | no reduction |
| … “against renovation or replacing the heating (share halved)” | A restriction such as a listed building prevents one of them: share halved. |
| … “against both (no sharing)” | The landlord bears nothing. |

**4. Read it.** The card **“Sharing CO₂ costs {year}”** on the Tenancy page
shows the previous year: the case in one sentence, **CO₂ per m² and year**,
**Stage** (… / 10), **Landlord’s share** and **Refund**, below it the
reductions and “An estimate under the CO2KostAufG, not legal advice.”

```text
kg per m²     = the year's emissions / floor area      (one decimal place)
stage, share  = table in section 4
refund        = CO₂ costs including VAT × share × reduction
reduction     = × 0.95 with own appliances; × 0.5 with one restriction; 0 with both
```

**Example** (invented figures, CO₂ price 60 €/t): 12,000 kWh of gas, 80 m².

```text
Emissions     12,000 kWh × 0.18139 kg/kWh     = 2,176.7 kg
per m²        2,176.7 kg / 80 m²              =    27.2 kg   → stage 5, 40 %
CO₂ costs     2.1767 t × 60 €/t = 130.60 € net, × 1.19 = 155.41 €
Refund        155.41 € × 40 %                 =    62.16 €
with cooker   62.16 € × 0.95                  =    59.06 €
```

**5. Letter.** **“Letter (PDF)”** opens a letter “Refund of the landlord’s share
of CO₂ costs (CO2KostAufG)” with the billing year, the name of the home,
emissions, floor area, CO₂ per m², stage, share, the year’s CO₂ costs including
VAT, the reductions and the refund amount, plus the sentence “Under § 6(2)
CO2KostAufG the landlord bears the share stated. Please offset the amount in the
next service charge statement or pay it out. The supplier’s bill is enclosed.”
You add your name, address, date and signature yourself and enclose the
supplier’s bill. The PDF is in the installation’s default language.

**6. Mind the deadline.** The claim must reach the landlord in text form
**within twelve months of receiving the supplier’s bill** (§ 6(2)) — an email
counts as text form. The app takes the gas bill’s **bill date** as the date of
receipt, without a date the day after the billing period, and puts the deadline
in the agenda and calendar ([section 7](#7-deadline-in-the-calendar)).

## 6. Central heating: check the statement

With central heating the landlord has already deducted his share. The app
checks whether it is right.

1. **Record the service charge statement** ([As a tenant](mieter.md#7-record-a-statement-and-take-over-prices))
   and enter in the field group **“CO₂ information on the heating cost
   statement”** what it says there: **Emissions (kg CO₂)**, **CO₂ costs**,
   **Stage on the statement**, **Landlord’s share on the statement (%)** and
   **Landlord’s amount on the statement**. The calculation needs the first two;
   the others are for comparison.
2. The app recalculates the stage from emissions and floor area, the share from
   the table and the amount as CO₂ costs × share. The card shows the result and
   below it what differs:

| Note | When |
|---|---|
| “The stage on the statement differs from the recalculated one.” | different stage |
| “The landlord’s share on the statement differs from the table.” | different percentage |
| “The landlord’s amount on the statement differs from the recalculated one.” | more than 0.50 € apart |
| “Emissions or floor area are missing from the statement – nothing can be checked.” | emissions or floor area missing |

**Example:** 2,000 kg of CO₂ at 70 m² are 28.6 kg per m² — stage 5, 40 %. With
300 € of CO₂ costs the landlord bears 120 €. If the statement says stage 4 and
90 €, the notes on stage, share and amount appear.

Here “Letter (PDF)” produces a check result: the same table, the sentence
“Recalculation of the CO₂ information on the heating cost statement under §§ 5
and 7 CO2KostAufG.” and the differences. You can use it to ask the landlord.

## 7. Deadline in the calendar

With your own gas boiler, “in a rented home” and a refund above 0 €, the agenda knows one
more deadline:

| Entry | Date | When it appears |
|---|---|---|
| “CO₂ cost {year}: last day to claim the landlord’s share of {amount}” | bill date of the year’s gas bill + 12 months | in the [calendar subscription](kalender.md) up to 365 days ahead, under “To do” from 30 days before |

Without a recorded gas bill for the year there is no deadline — the app does not
know the date of receipt then. A click leads to the Tenancy page.

## 8. Limits

- **The case is inferred.** If you cook with gas but heat through central
  heating, and the statement has no CO₂ details, the app takes you for
  self-contained heating and calculates with your gas consumption. Enter the
  CO₂ details of the heating cost statement in that case.
- **Standard factor and calendar year.** Without a gas bill the app uses the
  standard factor and the calendar year’s consumption. What counts is the
  supplier’s bill — record it when money is at stake.
- **Heating oil** is included in the calculation, but a deadline only arises
  from a gas bill.
- **District heating and heat** are not calculated in the self-contained case;
  there the landlord calculates.
- **Germany only, rented homes only.** Owners and other countries do not see
  the card.
- **Not legal advice.** Whether reductions apply, whether a deadline runs and
  how the landlord offsets — the app does not judge.

Technical details:
[API reference](../referenz/api.md#co₂-price-and-sharing-v310) ·
[Data model](../referenz/datenmodell.md#tenancy-and-service-charge-statement-v310) ·
[Views](../referenz/ansichten.md#tenancy-v310).

---

[← Compendium index](../README.md) · [As a tenant](mieter.md)
