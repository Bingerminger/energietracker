# Gas

**English** · [Deutsch](../../functional/01-gas.md)

[← Fundamentals](00-overview.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **cumulative** (meter readings in m³) |
| Billing unit | kWh |
| Conversion | `gas_conversion_factors` — dated list (volume correction factor × calorific value per cut-off date), since v2.5.0 |
| HDD-relevant | **yes** — heating dominates the consumption |
| Colour | Orange |

## Background

The gas meter measures **volume** (m³), but **energy** (kWh) is billed. The
conversion is printed on every gas bill:

```text
kWh = m³ × volume correction factor × calorific value
          (≈ 0.95–1.0)              (≈ 10–11.7 kWh/m³)
```

The **volume correction factor** (German *Zustandszahl*) belongs to the
delivery point (altitude, pressure) and practically never changes. The
**calorific value** (*Brennwert*) is a period average published by the grid
operator and changes several times a year — a yearly bill typically lists
three or four different values, each with its own period, because the
supplier's gas sources vary.

## Conversion factors with cut-off dates (F1012, since v2.5.0)

Up to v2.4.2 the Energietracker knew a single factor. Since v2.5.0 it is a
**dated list** under *Settings → Gas conversion factors* — one entry per
calorific-value period, exactly as the bill states them:

| Valid from | Vol. corr. | Calorific value | → Factor |
|---|---|---|---|
| *(undated)* | — | — | 11.5000 |
| 2024-01-01 | 0.9600 | 11.400 | 10.9440 |
| 2025-01-01 | 0.9600 | 11.650 | 11.1840 |
| 2025-10-01 | 0.9600 | 11.520 | 11.0592 |

(These are the demo-data values — *Settings → Load demo data* shows the
list together with the bill verification right away.)

Worth knowing:

- **The latest entry whose date is not after the day takes effect.** The
  undated entry applies to everything before — it is the migrated legacy
  value, so the history calculates exactly as before v2.5.0. Nothing changes
  retroactively.
- **The factor is derived from volume correction factor × calorific value**
  and stored with five decimals. Without a breakdown, enter the factor
  directly. The volume correction factor is prefilled from the last entry.
- **Day-exact.** If a cut-off date falls inside a reading interval, the
  interval is split there — every day calculates with its own factor. The
  supplier does the same, but with *estimated* intermediate readings (reading
  type "S" on the bill); here no estimate is needed.
- **Plausibility check on save:** volume correction 0.8–1.1, calorific value
  8–13, factor 5–15 kWh/m³. A typo like 115 instead of 11.5 would otherwise
  multiply every consumption by ten — silently.
- Heating oil and pellets keep their scalar; nobody publishes a new calorific
  value for them every month.
- *(v2.7.0)* The input also takes the calorific value in **MJ/m³** (United
  Kingdom, Netherlands) or **GJ/Smc** (Italy) and converts it to kWh/m³ —
  storage and the plausibility check stay in kWh/m³. See
  [country profiles](14-laenderprofile.md#7-gas-calorific-value-unit-and-price-per-cubic-metre).

## Bill verification

In the gas consumption view the **Bill verification** block recalculates the
supplier bill: for the chosen range, sections arise at every reading and every
calorific-value change, each with

```text
Period | Reading (from) | Reading (to) | Days | Boundary | m³ | Vol. corr. | Calorific value | kWh/m³ | kWh
```

— exactly the lines the bill shows, including the meter reading at the
start and end of every section. Each reading carries its **reading type**
like the footnotes on the bill (since v2.5.2): no suffix for a real meter
reading, `S` for a reading recorded as estimated, `E` for a **substitute
value** — there is no meter reading on that day, it is interpolated
day-exact between the enclosing readings. Those are exactly the places
where the supplier estimates too (calorific-value and period boundaries);
the fewer `E` in the table, the less estimation is in the comparison.
Across a meter swap there is no continuous reading, so the substitute
value stays without a number. If a line differs, either a factor is
entered wrongly or the supplier estimated an intermediate reading
differently.
Sections without an enclosing reading (before the first, after the last) show
without consumption so the gap is visible.

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
settlement up to the billing date — since v2.8.0 by calendar, with estimated
consumption since the last reading
([Fundamentals §10](00-overview.md#10-balance-to-date)).

Refunds/surcharges and additional advance payments are recorded as
**[special payments](10-sonderzahlungen.md)** (F1003) and feed into the balance.

If the bill states the unit price **per m³** (Italy, Netherlands), the helper
"Convert a price per m³" below the unit prices converts it to ct/kWh — divided
by the calorific value valid on the chosen day *(v2.7.0)*.

## Typical pitfalls

- **Wrong calorific factor** → the costs don't match. Always take it from the
  real bill.
- **Long reading intervals** smear cold/warm phases. For a good HDD correlation,
  read more often (ideally monthly).

[← Fundamentals](00-overview.md) · [Electricity →](02-strom.md)
