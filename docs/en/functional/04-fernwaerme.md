# District heating

**English** · [Deutsch](../../functional/04-fernwaerme.md)

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
kWh directly). Typically there is a high **base price** (a demand charge based on
the connected load) plus a working price.

## What Energietracker does with it

- Monthly consumption via linear interpolation of the kWh meter readings.
- Full **heating-signature analysis** (HDD regression, all five models).
- **Weather adjustment** and an R²-weighted **forecast** as for gas.
- Counts as a **heat source** in the efficiency class.

## Contracts

Working price (ct/kWh) + base price (€/month, often as a demand charge). Price
changes are correctly assigned over time via the `working_prices`/`base_prices`
history with a forward fill.

Refunds/surcharges and additional advance payments are recorded as
**[special payments](10-sonderzahlungen.md)** (F1003) and feed into the balance.

## Typical pitfalls

- **Base price underestimated**: with district heating the fixed share is often
  high — be sure to maintain the demand charge as `base_prices`, otherwise the
  balance is too optimistic.

[← Water](03-wasser.md) · [Heating oil →](05-heizoel.md)
