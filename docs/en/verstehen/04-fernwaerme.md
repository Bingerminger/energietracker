# District heating

**English** · [Deutsch](../../verstehen/04-fernwaerme.md)

[← Water](03-wasser.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **cumulative** (meter readings in kWh) |
| Billing unit | kWh |
| Conversion | none (already kWh) |
| HDD-relevant | **yes** |
| Colour | Red-rosé |

## Background

For evaluation, district heating behaves like gas — a cumulative kWh meter,
strongly heating-driven — but **without** a volume conversion (the meter delivers
kWh directly). Typically the fixed share is high: next to the working price
there is a **capacity charge** based on the connected load and often a
**metering charge** for the meter and billing.

**District heating or heat?** Create district heating if you have a supply
contract with the heat supplier yourself and receive its bill. If the heat is
only measured and paid through the service charges of your rent — even when the
building is on a district heating network —, it is
[Heat](15-waerme.md#1-what-heat-is). Both cases with sample households:
[use case F](../anleitungen/anwendungsfaelle.md#f--district-heating-or-heat).

## What Energietracker does with it

- Monthly consumption via linear interpolation of the kWh meter readings.
- Full **heating-signature analysis** (HDD regression, all five models).
- **Weather adjustment** and an R²-weighted **forecast** as for gas.
- Counts as a **heat source** in the efficiency class.
- **Bill check** (since v3.1.0) section by section, with working price and fixed
  costs ([Annual bill](../anleitungen/jahresabrechnung.md#4-recalculate)).

## Contracts

Working price (ct/kWh) + base price (€/month). Price changes are assigned to the
day via the `working_prices`/`base_prices` history (since v2.9.0; previously
from the following month).

Refunds/surcharges and additional advance payments are recorded as
**[special payments](10-sonderzahlungen.md)** (F1003) and feed into the balance.

## Fixed costs: capacity and metering charge *(v3.1.0)*

Since v3.1.0 the district heating contract dialog has two more price lists,
each with an effective date, and the field group **“District heating:
connection and key figures”**:

| Field | Unit | Meaning |
|---|---|---|
| **Connected load (kW)** | kW | the agreed capacity of the connection (`capacity_kw`) |
| **Capacity charge** | € per kW and year | dated list (`capacity_prices`); needs the connected load |
| **Metering charge** | € per year | dated list (`metering_prices`), also called billing or meter charge |
| **Network CO₂ factor (g/kWh)** | g CO₂ per kWh | emission factor of your heat network (`co2_g_per_kwh`) |
| **Primary energy factor** | — | for information only, stored and shown (`primary_energy_factor`) |

The values are on the bill or the heat supplier’s price sheet.

```text
fixed costs per month = base price
                      + connected load × capacity charge / 12
                      + metering charge / 12
Example without a base price:
          10 kW × 60 €/(kW·a) / 12 + 120 €/a / 12 = 50 € + 10 € = 60 € per month
```

The fixed costs count everywhere the base price counts: in the monthly costs,
the balance, the forecast and the bill check — by calendar days of the month.
An effective date in one of the lists splits the month like a price change. The
app refuses a capacity charge without a connected load (“A capacity charge
needs the connected load in kW”), and invalid values as well.

**The network CO₂ factor** replaces the general “CO₂ district heating” value
(Settings → Utilities & billing) in the CO₂ balance for the months of the
contract. It is also the only basis for the **CO₂ price** of district heating —
there is no standard factor for it ([CO₂ price in fuel](16-co2-preis.md)).

## Typical pitfalls

- **Fixed costs underestimated**: with district heating the fixed share is
  often high — enter the capacity and metering charges (since v3.1.0 as lists of
  their own; previously as part of the base price), otherwise the balance is too
  optimistic. If you have kept everything in the base price so far, leave it
  there; entering it twice counts twice.

[← Water](03-wasser.md) · [Heating oil →](05-heizoel.md)
