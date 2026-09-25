# Heating oil

**English** · [Deutsch](../../verstehen/05-heizoel.md)

[← District heating](04-fernwaerme.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **delivery-based** (tank invoices, no meter readings) |
| Input unit | **litres (L)** |
| Billing unit | kWh |
| Calorific value | `heizoel_kwh_per_l` (default **10.0 kWh/L**, Hu heating oil EL) |
| HDD-relevant | **yes** |
| Colour | Violet |

---

## 1. Why heating oil works differently

There is **no meter**. What really exists: a **tank** with a capacity and an
initial stock, and occasional **deliveries** with an invoice. From this the
running consumption must be *modelled*.

That is why heating oil (like pellets) has its own data model:

- **Tank/store** = a "meter" with `capacity`, `capacity_unit` (`L`),
  `initial_stock`.
- **Delivery** = `{date, quantity, unit_price_cents | total_eur, supplier, note,
  is_planned}`.

---

## 2. Contracts? — No, the tank invoice *is* the contract

Heating oil is bought at daily prices, not via a supply contract with a fixed
working price. **There is deliberately no contract entity for heating oil.** The
cost basis is the respective **tank invoice**:

```text
cost of delivery =
    total_eur                          if total amount recorded
    quantity × unit_price_cents / 100  otherwise
```

**Since v1.4.2** `total_eur` takes precedence: the invoice total is the amount
actually paid and includes a delivery fee, a small-quantity surcharge or rebates
that a plain *price × quantity* would not capture. The effective unit price is
derived from it (`ct/L = total_eur × 100 / quantity`).

**Since v2.10.0 a consumed litre costs what it costs in the tank:** a delivery
mixes into the stock at its price (moving average), and consumption is charged at
the average price. The **initial stock** costs the price maintained on the tank
(`initial_stock_price_ct`), otherwise that of the first delivery. Up to v2.9 it
was free, and every day carried the price of the last delivery — after an
expensive autumn delivery, even the cheap oil from spring was booked as
expensive. The monthly table shows the effective price per kWh.

> In practice: just enter the **invoice amount** and the **litres** of the tank
> invoice. You do not need to compute the ct/L price.

---

## 3. Tank log — one calculation for consumption and stock (since v2.10.0)

Up to v2.9 the app calculated twice: the costs distributed the initial stock plus
**all** deliveries over the time up to today, as if the tank were empty today;
the stock curve worked with a calibrated rate. Consequence: a delivery made today
increased the consumption of all previous years by around 20 %, and the balance
said "tank empty" while the curve showed 1,466 L. Now there is **one**
calculation from which consumption, costs and the stock curve come.

**Anchors** are days on which the stock is known:

- the start day with the **initial stock**,
- a delivery **"filled to full"** (`fill_to_full`) — afterwards the stock is the
  tank capacity,
- a **tank reading** (`tank_levels` on the tank) — read from the tank gauge, with
  a dipstick or from the level sensor.

Between two anchors the consumption is **known**:

```text
consumption = stock_before + Σ deliveries in between − stock_after
```

It is distributed over the days by base load and degree days:

```text
share_day ∝ ρ + HDD_day        ρ = s · HDD_year / ((1 − s) · 365.25)
```

ρ is the base load in degree-day units (`delivery_baseload_share` s, default
0.15 — hot water, stand-by). As a result, a summer interval gets mainly base
load, instead of 85 % falling on the few cool days. The degree days of a normal
year come from the climate normal, otherwise from your own temperature history
(if each of the twelve months has at least 15 days), otherwise from a rough Central European monthly
mean. Missing daily temperatures are filled from the climate normal.

**After the last anchor** the app continues with a calibrated rate and marks these
days as **estimated** (`estimated_from`; monthly rows `estimated_days`, "≈" in
the table). The rate comes from the intervals between anchors, otherwise from the
delivery cadence (what was delivered before the last delivery was consumed
between the first and the last delivery), and with exactly one delivery from the
assumption that the initial stock was used up by then. Each source needs at
least 14 days. If none suffices (for example only the initial stock, or the only
delivery on the first day), there is **no** consumption calculation and a notice
— up to v2.9 the entire initial stock then counted as consumed by today.

If two levels contradict each other (more in the tank than can be there), the app
books nothing in between and reports it; if the tank runs empty by calculation,
it asks for a missing delivery or a tank reading.

---

## 4. Tank stock curve

The curve is the same calculation as the consumption (§3): the stock at the end
of each day. The known levels are in `anchors` (start of the day, after a
delivery) and appear as points. The tank view draws the calculated part
as a solid line, the estimated part dashed and the known stocks as points, and it
states up to when the calculation is based on known stocks.

> **It gets more accurate with anchors:** mark a delivery as "filled to full" or
> record a tank reading every now and then (tank view, "Record tank reading").
> From then on the consumption up to that day is calculated, not estimated — and
> a later delivery no longer changes it.

*(Up to v2.9 there were two models here: the calibrated stock curve since v1.4.0
and, alongside it, the energy balance for costs and efficiency. The cadence rule
of the old curve lives on as the calibration without anchors.)*

---

## 5. In practice: size the tank realistically

So that the stock curve shows a plausible sawtooth, the tank size, initial stock
and delivery cadence should match the consumption scale (demo example: 3000 L
tank, start 2400 L, annual autumn delivery ~1150 L → min ~49 %, max ~93 %). A
4000 L tank with only small partial deliveries would never appear well filled —
that is not an error but reflects reality.

---

## 6. Typical pitfalls

- **Wrong calorific value**: 10.0 kWh/L applies to heating oil EL. For a different
  quality, adjust it in the settings, otherwise kWh and the efficiency class tip
  over.
- **No tank reading, no "filled to full" delivery**: then everything is
  estimated, and a new delivery shifts the rate. One tank reading a year is
  enough for the previous years to be settled (since v2.10.0).
- **A planned delivery** (`is_planned`) does not count in the balance/stock — by
  design, so that forward planning does not distort the actual state.

---

[← District heating](04-fernwaerme.md) · [Wood pellets →](06-pellets.md)
