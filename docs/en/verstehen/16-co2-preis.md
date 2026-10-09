# CO₂ price in fuel

**English** · [Deutsch](../../verstehen/16-co2-preis.md)

[← Heat](15-waerme.md) · [Compendium index](../README.md)

Since **v3.1.0** the Energietracker shows how much CO₂ price is contained in
your costs for gas and heating oil — and for district heating, if you know the
emission factor of your heat network. It is **shown, not added**: the amount is
already part of your unit price. The app makes it visible so you can see what a
rising CO₂ price means for you. If you rent, you may share the CO₂ costs with
your landlord: [Share CO₂ costs with the landlord](../anleitungen/co2-aufteilung.md).

> An estimate, not legal advice. This page names the laws for orientation,
> without guarantee.

---

## 1. What the CO₂ price is

Since 2021, suppliers of natural gas and heating oil in Germany pay a price for
every tonne of CO₂ produced when their fuels are burned — under the **Fuel
Emissions Trading Act (BEHG)**. They pass it on to their customers through the
unit price. Up to 2025 the price was fixed per year; for 2026 a corridor of
55 to 65 € per tonne applies. A bill (Bundestag printed paper 21/7869) would
extend the corridor to 2027; it has not been passed yet (as of 9 Oct 2026).

After that, the **European emissions trading system for buildings and transport
(ETS2)** is to replace the national price — as things stand, from 2028. How
high the price will be is not known. For years without a fixed price the app
uses the last known value and says so ([§ 4](#4-price-per-year)).

The app knows the CO₂ price for **Germany** only (country profile DE). In other
countries the card is missing; the API answers with `supported: false` and a
note ([Country profiles](14-laenderprofile.md)).

## 2. Shown, not added

The app does **not** add the CO₂ price to your costs — it is part of the unit
price you entered on the contract. It only works out which share of it is CO₂
price:

- **including VAT**, like the unit price on your bill — that is also how bills
  state the CO₂ share (CO2KostAufG § 3(3): emissions × CO₂ price “plus any VAT
  payable on this amount”); this is the large figure on the card;
- **excluding VAT** (net) in the line below.

**Which VAT rate.** Usually 19 %. For gas through the natural gas grid and for
district heating, 7 % applied from 1 Oct 2022 to 31 Mar 2024 (§ 28(5) and (6)
UStG); heating oil stayed at 19 %. The app sets the rate per month and weights
it by consumption for the year: gas in 2023 is calculated entirely at 7 %, gas
in 2024 only for January to March. Without consumption in the year it takes
the average of the twelve months. The API gives the rate applied per row in the
field `vat`.

The figure helps to put things in context: which share of the price is set by
policy, and what changes if the CO₂ price goes up?

## 3. Emissions: the BEHG standard factors

The app uses the **standard emission factors of the BEHG**, not the BAFA
factors of the CO₂ balance. The BAFA factors include the upstream chain
(extraction, transport); the CO₂ price is levied on combustion only. That is
why the app shows two slightly different CO₂ figures: the CO₂ balance in the
monthly table and the emissions on the “CO₂ price” card.

| Utility | Factor | Basis |
|---|---|---|
| Gas | 0.18139 kg CO₂ per kWh | per kWh **gross calorific value**, as the gas bill counts (correction factor × calorific value) |
| Heating oil | 0.2664 kg CO₂ per kWh | per kWh **net calorific value**, as the app calculates heating oil (litres × kWh per litre) |
| District heating | emission factor of the heat network | only if it is on the contract (field “Network CO₂ factor (g/kWh)”, [District heating](04-fernwaerme.md)); otherwise no row |
| Heat | factor of the energy source | only if “Heat comes from” is set to gas or heating oil — an **approximation** ([§ 7](#7-limits)) |
| Pellets, wood, electricity | — | not covered by the BEHG, never a row |

**Where a row’s emissions come from**, in this order:

1. **Supplier bill** (`bill`) — a recorded bill whose period ends in this year
   and carries CO₂ details
   ([Annual bill](../anleitungen/jahresabrechnung.md#7-on-the-bill-enter-compare-book)).
   If the CO₂ amount is on it too, the app takes it as the amount **including
   VAT**, as it is on the bill, and works out the net amount (amount ÷
   (1 + VAT)). Up to v3.1 it read it as net and added the tax a second time.
2. **Contract** (`contract`) — for district heating the network’s emission
   factor.
3. **Calculated** (`computed`) — the year’s consumption × standard factor.

Only meters that also count in the utility’s totals are included: no
sub-meters, and for heat only meters with the role “Home consumption”.

## 4. Price per year

The values of the German country profile in € per tonne:

| Year | 2021 | 2022 | 2023 | 2024 | 2025 | 2026 |
|---|---|---|---|---|---|---|
| Price | 25 | 30 | 30 | 45 | 55 | 60 |

2021 to 2025 are the fixed prices, 2026 the midpoint of the 55 to 65 € corridor
(§ 4(1) no. 2 CO2KostAufG). For **2027** the average of the auctions from
1 July to 30 November 2026 applies (§ 4(1) no. 3); the German Environment
Agency publishes it no later than ten working days before the year begins
(§ 4(2)). As long as it is not in the country profile, the app uses the **last
known value as an assumption** for 2027 and later years — i.e. 60 € — and says
below: “This year’s price is not fixed yet; the last known one is used.” The
60 € are only a placeholder, not the 2027 price.

You enter your own values under **Settings → Expert → calculation parameters →
“CO₂ price” → “CO₂ price per year”** (year and € per tonne). They take
precedence over the country profile and replace the assumption — for instance
once the German Environment Agency has published the 2027 price or if your
supplier states a different value ([Settings](../referenz/einstellungen.md#co₂-price-v310)).

## 5. How it is calculated

```text
Emissions [kg]     = consumption [kWh] × factor [kg/kWh]
Amount net [€]     = emissions [kg] / 1000 × price [€/t]
Amount gross [€]   = amount net × (1 + VAT)      VAT 19 %, gas and district heating 10/2022–3/2024 7 %
per kWh [ct]       = amount gross / consumption [kWh] × 100

with the CO₂ amount from the bill:
Amount gross [€]   = CO₂ amount on the bill
Amount net [€]     = amount gross / (1 + VAT)
```

**Example** (invented figures): 10,000 kWh of gas in 2025.

```text
Emissions     10,000 kWh × 0.18139 kg/kWh  = 1,813.9 kg
net           1,813.9 kg / 1000 × 55 €/t   =    99.76 €
gross         99.76 € × 1.19               =   118.72 €
per kWh       118.72 € / 10,000 kWh        =     1.19 ct
```

So about 1.2 ct of every kilowatt-hour of gas was CO₂ price in 2025. The same
amount in 2023 (30 €/t, VAT 7 %): 54.42 € net × 1.07 = 58.23 €.

**Per m² of floor area.** The API also works out the emissions of all rows per
m² of floor area and the **stage under the CO2KostAufG** (ten stages, rounded
to one decimal place). The area comes from the tenancy when you rent, otherwise
from Settings → Household & building. What the stage is for:
[Share CO₂ costs with the landlord](../anleitungen/co2-aufteilung.md).

## 6. Where you see it

**Card “CO₂ price in the fuel {year}”** on the consumption view of gas,
heating oil, district heating and heat, for the year selected there:

- **Included** — the amount including VAT,
- **Per kWh** — the same amount per kWh,
- **Emissions (BEHG)** in kg,
- **CO₂ price** in € per tonne.

Below it a line: “Already part of the unit price – not a surcharge. Net …,
shown above including VAT.”, plus the source (standard factor, supplier bill or
the heat network’s emission factor), for heat the note on the approximation and,
for an assumed price, a note on that. Without consumption in the year or without
a factor, the card does not appear. The ⓘ explains the term.

**Forecast — CO₂ price scenario.** What a higher price would cost: the forecast
page has the field **“CO₂ price from 2028 (€/t)”** — empty = off. With a value,
say 150, the page writes below the forecast:

> “With 150 €/t CO₂ from 2028: +1.94 ct/kWh, … more over the next 12 months
> (incl. VAT).”

```text
Extra cost per kWh [ct] = (scenario − price of the year) × factor / 10 × 1.19
Example gas:              (150 − 60) × 0.18139 / 10 × 1.19 = 1.94 ct/kWh
                          at 12,000 kWh a year about 233 €
```

- The scenario is **shown next to the forecast only**; it does not change the
  forecast’s costs.
- It applies from the year under **Settings → Expert → calculation parameters
  → “CO₂ price” → “Scenario from year”** (default 2028), to every forecast month
  from that year on. The app counts the amount “over the next 12 months” only
  over months from that year: if the forecast does not reach it, no line
  appears, and if the next twelve months lie before it, the amount is 0 €. To
  see the effect on the coming year, set “Scenario from year” to the current
  year.
- The field is pre-filled with “Scenario: CO₂ price” from the same settings.
- A scenario exists for gas, heating oil and — as an approximation — heat with
  gas or heating oil as the energy source, not for district heating.

## 7. Limits

- **Standard factors.** The app uses the BEHG factors, not your supplier’s. A
  tariff with a biogas share carries less CO₂ price; the app does not know the
  share. Enter the details from your bill in that case — they take precedence.
- **Billing year is not calendar year.** A supplier bill counts with its whole
  period towards the year in which that period ends. Without a bill the app
  calculates the calendar year.
- **Heat is an approximation.** It counts the heat arriving in the flat, not the
  fuel used for it. The heating system’s losses are missing, so the figure tends
  to be too low. The VAT follows the energy source set under “Heat comes from”
  — for gas and district heating that includes the 7 % from 10/2022 to 3/2024.
- **District heating** only with your heat network’s factor. The supplier
  publishes it, often on the price sheet or the bill.
- **Future prices** are assumptions until they are fixed.
- **Germany only.** Other countries have their own rules, which the app does not
  model.

Technical details:
[API reference](../referenz/api.md#co₂-price-and-sharing-v310) ·
[Settings](../referenz/einstellungen.md#co₂-price-v310) ·
[Glossary](09-glossar.md).

---

[← Heat](15-waerme.md) · [Compendium index](../README.md)
