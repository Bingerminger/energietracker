# Electricity

**English** · [Deutsch](../../functional/02-strom.md)

[← Gas](01-gas.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **cumulative** (meter readings in kWh) |
| Billing unit | kWh |
| Conversion | none (already kWh) |
| HDD-relevant | **no** |
| Colour | Mint green |

## Background

Electricity is measured directly in kWh — no conversion. Unlike gas, electricity
consumption does **not** depend systematically on the outdoor temperature
(exceptions: heat pump, air conditioning, electric supplementary heating — these
would create an HDD coupling, but it is deliberately not modelled here because it
is household-specific).

## What Energietracker does with it

- **Monthly consumption** via linear interpolation.
- **No HDD, no heating-signature regression.** The analysis instead shows the
  **seasonal profile** (monthly mean) and trends.
- **Forecast**: pure seasonal profile — the regression is omitted (see
  [Fundamentals §6](00-overview.md)).
- **Base load**: a constant base (fridge, standby, router) plus variable peaks.
  Noticeable base-load increases are detected by the anomaly/trend rule.

## Contracts

Like gas: working price (ct/kWh), base price (€/month), advances, bonuses. Shadow
contracts allow "what would tariff X have cost?" on the real consumption —
without distorting the balance/forecast.

Refunds/surcharges and additional advance payments are recorded as
**[special payments](10-sonderzahlungen.md)** (F1003) and feed into the balance.

## Typical pitfalls

- **Expecting a temperature correlation.** For pure household electricity, a
  weak/absent HDD coupling is normal — not an error.
- **Heat-pump electricity** mixes heating and household electricity. If you want
  to separate it, create a second meter.

[← Gas](01-gas.md) · [Water →](03-wasser.md)
