# Gas

**English** · [Deutsch](../../functional/01-gas.md)

[← Fundamentals](00-overview.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **cumulative** (meter readings in m³) |
| Billing unit | kWh |
| Conversion | `gas_conversion_factor` (default 11.5 kWh/m³) |
| HDD-relevant | **yes** — heating dominates the consumption |
| Colour | Orange |

## Background

The gas meter measures **volume** (m³), but **energy** (kWh) is billed. The
conversion is printed on every gas bill:

```text
kWh = m³ × calorific value × state number
          (≈ 10–11.5 kWh/m³)  (≈ 0.95–1.0)
```

Enter the product (often 11.4–11.6) as `gas_conversion_factor` — otherwise the
costs will deviate from the bill.

## What Energietracker does with it

- **Monthly consumption** via linear interpolation between readings.
- **Heating signature**: since gas mostly heats, consumption correlates strongly
  with heating degree days. The analysis shows the regression (often a high
  `R²`); the `sigmoid` curve captures the saturation on very cold days well.
- **Weather adjustment**: separates "cold winter" from "really consumed more"
  (see [Fundamentals §5](00-overview.md)).
- **Forecast**: R²-weighted blend of the heating-signature regression and the
  seasonal profile.
- **Efficiency class**: gas counts as a heat source in kWh/m²·a.

## Contracts

Gas has classic supply contracts: working price (ct/kWh), base price (€/month),
advances, bonuses. Several contracts with a change are correctly chained across
their terms; the balance shows the state today and the expected year-end
settlement up to the billing date.

Refunds/surcharges and additional advance payments are recorded as
**[special payments](10-sonderzahlungen.md)** (F1003) and feed into the balance.

## Typical pitfalls

- **Wrong calorific factor** → the costs don't match. Always take it from the
  real bill.
- **Long reading intervals** smear cold/warm phases. For a good HDD correlation,
  read more often (ideally monthly).

[← Fundamentals](00-overview.md) · [Electricity →](02-strom.md)
