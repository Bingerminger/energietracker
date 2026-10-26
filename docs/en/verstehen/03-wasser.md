# Water

**English** · [Deutsch](../../verstehen/03-wasser.md)

[← Electricity](02-strom.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **cumulative** (meter readings in m³) |
| Billing unit | **m³** (not kWh) |
| HDD-relevant | **no** |
| Colour | Blue |

## Background

Water is the only utility that is **not** converted to kWh — billing is in m³.
The consumption is largely weather-independent (a slight seasonality from garden
watering in summer is possible).

## Three-component tariff

Water has its own contract model with three components:

1. **Drinking water** — working price (ct/m³) + base price (€/month).
2. **Waste water** — basis either the *drinking-water quantity* or a *separate
   waste-water meter*; working price (ct/m³) and *(v3.9.0)* a standing charge of
   its own (€/month) if the supplier shows one (field
   `schmutzwasser.base_prices`, optional).
3. **Rainwater** — a flat rate per sealed area (€/m²·year) based on the
   maintained area.

This model was introduced with schema 1.0.3; an auto-migrator transfers old
simple water tariffs into the drinking-water component.

Each component keeps its prices as **sections from a day on**: if the supplier
changes its prices on 1 October, there are two rows, and every day is charged at
the price valid on it. The app keeps **gross prices**. If the bill shows net
prices, each component has its own tax rate (often 7 % for drinking water and
19 % for waste water and rainwater). The app reads the rate from the bill; it
never guesses it. Basic price and provision price of the same component together
are its standing charge; a yearly price counts as one twelfth per month.

Example (made up): a basic price of €50.00 and a provision price of €100.00 a
year net, 7 % VAT, give gross (€53.50 + €107.00) ÷ 12 = €13.375 a month; a water
price of €2.10 per m³ net becomes €2.247 = 224.7 ct per m³.

*(v3.9.0)* From a **water annual bill** the inbox creates the contract with all
three components, the price sections and the advance payment plan
([Inbox → Contract from the bill](../anleitungen/posteingang.md#contract-from-the-bill-v390)).

## Advances, balance and special payments *(v3.5.0)*

Like gas and electricity, a water contract has monthly **advances** and, at the
end of the billing period, a **back-payment or refund**. Since v3.5.0 you enter
it as a **[special payment](10-sonderzahlungen.md)** in the contract (section
“Special payments” in the water contract, after the bonuses) or book the result
of the supplier bill under *Check a bill*
([#34](https://github.com/Bingerminger/energietracker/issues/34)). A
*with-effect* type sets the new advance from its effective date — in the months,
in the balance and in the forecast.

*(v3.7.0)* If the water contract has **advance payments**, the balance is
calculated **by calendar** as for gas, electricity and district heating
([Fundamentals §10](00-overview.md#10-balance-to-date)): measured up to the
last reading, estimated after that, standing charge by the day, advances by
plan up to today. Per m³ count drinking water and wastewater (with a meter of
its own or a garden deduction meter at the measured wastewater share), as fixed
costs the standing charge and the rainwater charge (area × €/m²·a ÷ 12). As
there, you get a **suggested advance payment** (`suggested_advance`), the
**balance history** (`balance_path`) and, in the annual report, the balance and
the advice on the advance. The API marks this with `projection_method: forecast`.

Without advance payments the balance stays over the months with readings
(`projection_method: flat_average`, as water always calculated up to v3.6):

```text
balance today     = costs of the read months − advances of those months + special payments net
expected at end   = balance today + (average cost per month − current advance) × remaining months
```

The overview, the page *Contracts & payments* and the water consumption view
show both values.

## Advance payments every two or three months *(v3.9.0)*

Many water suppliers do not collect the advance payment monthly but every two
months or on fixed dates. Each row of the advance payment plan therefore has a
**rhythm** (contract form → “Rhythm”): monthly, every 2 months or every 3
months. If the inbox took the plan over from a bill, the **due dates according
to the bill** are shown below the row; they are kept when you save and dropped
when you change the rhythm. This applies to every utility, not only to water.

- The amount applies **per due date**. The app never silently turns it into a
  monthly amount.
- Every N months: from the day of the row, always on the same day (at the end of
  a month on the month's last day).
- With due dates: on the days listed, then yearly on the same days. A bill often
  lists five dates because the sixth instalment falls into the next bill; without
  a new plan it continues at the same pace.
- A new row ends the rhythm of the old one.
- Monthly stays exactly as before; no new field is stored.

This is how the evaluations use it:

- **Balance by calendar**: paid are the instalments whose day has been reached,
  each in full in its month.
- **Suggested advance payment**: what is left until the next bill is spread over
  the instalments still to come; the suggestion applies per due date.
- **Forecast** and month rows: each instalment in its month, the months in
  between without an advance payment.
- The **balance card** and the contracts overview name the rhythm (“€165.00
  every 2 months”), the balance card also the next due date (API:
  `current_advance_every_months`, `next_advance_dates`, only with a rhythm).

Example (made up): €165.00 every two months from 15 May 2026. On 31 July 2026
two instalments are paid (15 May and 15 July), €330.00, not three times a
monthly amount. Until the next bill on 1 April 2027 three more instalments
follow (15 Sep, 15 Nov, 15 Jan), €495.00 in total.

## Roles: cold, hot, garden *(v3.1.0)*

Since v3.1.0 every water meter has a **role** (meter dialog → “Role”, API field
`role`):

| Role | Interface | Effect |
|---|---|---|
| `cold` (default) | Cold water | as before |
| `warm` | Hot water | additionally the heat for this hot water, see below |
| `garden` | Garden | water that does not go into the sewer — a label, the calculation stays the same |

Meters without a role are cold-water meters; nothing changes for them.

**How to arrange the meters** — so that the total of the utility is right:

- **Rented flat:** the cold- and hot-water meters sit side by side in the flat.
  Create both as independent meters (no parent meter). Their sum is the fresh
  water of the flat.
- **House:** the main meter at the house connection measures everything, a
  hot-water meter behind the storage tank only the warm part. Create the
  hot-water meter as a **sub-meter** of the main meter
  ([meter topology](13-meter-topologie.md)); then it is not counted twice and
  the total stays the main meter.

## Heat for the hot water *(v3.1.0)*

For a meter with the role **Hot water**, the app works out each month how much
heat this water needed — with the formula of the German Heating Costs
Ordinance (HeizkostenV § 9 (2)):

```text
Q [kWh] = 2.5 × V [m³] × (t_w − 10)
```

`t_w` is the hot-water temperature, setting `warmwasser_temp_c` (Settings →
Household & building → “Home and hot water”; default 60 °C, allowed 30–90).
Examples:

```text
1 m³ at 60 °C:  2.5 × 1 × 50 = 125 kWh
3 m³ at 55 °C:  2.5 × 3 × 45 = 337.5 kWh
```

This is a **calculated value, not a measurement** — the ordinance uses it when
the heat for hot water is not metered separately. The water consumption view
shows “Heat for this hot water in {year}: about … kWh at 60 °C” below the table,
with an ⓘ explanation; the API returns `dhw_kwh` and `dhw_temp_c` per month
(additive). The setting `warmwasser_energietraeger` records what heats the hot
water — for information only. More in [Heat](15-waerme.md).

## Water saving index

```text
saving index = (litres per person per day) / reference × 100
```

with `wasser_personen_anzahl` and `wasser_personen_referenz` (default reference
122 L per person and day, BDEW 2024; installations from before v2.10.0 keep 127
until they take over the new values). Values clearly below 100 = thrifty; the
band limits (`wasser_sparindex_gut/_warnung`) are adjustable.

## Typical pitfalls

- **Expecting kWh**: water evaluations show m³, not kWh — the efficiency class (a
  heating metric) does not apply to water.
- **Wrong waste-water basis chosen**: with a separate waste-water meter, it must
  also be maintained as a meter/component, otherwise the app computes on the
  drinking-water basis.
- **Hot-water meter counted twice** *(v3.1.0)*: in a house it belongs under the
  main meter as a sub-meter, in a rented flat next to the cold-water meter —
  not both.
- **An advance payment every two months entered as a monthly amount**
  *(v3.9.0)*: the balance then counts twice the advance payment. Enter the
  amount per due date and set the rhythm.

[← Electricity](02-strom.md) · [District heating →](04-fernwaerme.md)
