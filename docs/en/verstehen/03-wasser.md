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
   waste-water meter*; working price (ct/m³).
3. **Rainwater** — a flat rate per sealed area (€/m²·year) based on the
   maintained area.

This model was introduced with schema 1.0.3; an auto-migrator transfers old
simple water tariffs into the drinking-water component.

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

[← Electricity](02-strom.md) · [District heating →](04-fernwaerme.md)
