# Water

**English** · [Deutsch](../../functional/03-wasser.md)

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
   waste-water meter*; working price (ct/m³).
3. **Rainwater** — a flat rate per sealed area (€/m²·year) based on the
   maintained area.

This model was introduced with schema 1.0.3; an auto-migrator transfers old
simple water tariffs into the drinking-water component.

## Water saving index

```text
saving index = (litres per person per day) / reference × 100
```

with `wasser_personen_anzahl` and `wasser_personen_referenz` (default reference
~127 L/person/day, the German average). Values clearly below 100 = thrifty; the
band limits (`wasser_sparindex_gut/_warnung`) are adjustable.

## Typical pitfalls

- **Expecting kWh**: water evaluations show m³, not kWh — the efficiency class (a
  heating metric) does not apply to water.
- **Wrong waste-water basis chosen**: with a separate waste-water meter, it must
  also be maintained as a meter/component, otherwise the app computes on the
  drinking-water basis.

[← Electricity](02-strom.md) · [District heating →](04-fernwaerme.md)
