# Heating oil

**English** · [Deutsch](../../functional/05-heizoel.md)

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
derived from it (`ct/L = total_eur × 100 / quantity`) and assigned to the
consumption days via a forward fill.

> In practice: just enter the **invoice amount** and the **litres** of the tank
> invoice. You do not need to compute the ct/L price.

---

## 3. Consumption distribution (energy balance)

Over the usage period the balance holds: what was in the tank plus what was
delivered is consumed. In energy:

```text
total kWh = (initial_stock + Σ deliveries) × Hu
```

This energy is distributed over the days: a weather-independent **base-load
share** (`delivery_baseload_share`, default 0.15 — e.g. hot water) flat, the
**rest HDD-weighted**:

```text
kWh_day =  total kWh · s / days                      (base load, s = 0.15)
         + (1 - s) · total kWh · HDD_day / Σ HDD      (heating share)
```

From this follow monthly consumption, costs (via the delivery price per day) and
the heating-signature analysis — analogous to gas.

---

## 4. Tank stock curve (the v1.4.0 model)

> **Important:** the displayed stock is a **calibrated model estimate**, *not* a
> tank gauging.

Up to v1.3.0 the model distributed the *entire* energy HDD-weighted and thereby
forced final stock ≈ 0 — the tank curve was practically useless (always ~0 %).
The old calculation also mixed litres with kWh (a latent unit error, masked by
the 0-normalisation).

**Since v1.4.0** the stock curve is decoupled (`dailyDeliveryStockDraw`). The
daily draw is in **litres** and uses an **HDD rate calibrated from the closed
delivery intervals**:

```text
rate = (Σ deliveries without the last) · (1 - s)
       ----------------------------------------------------   [ L / HDD ]
       Σ HDD in the window [first delivery .. last delivery]
```

Reasoning: in steady-state operation a household refills, per cycle, roughly what
it has consumed since the last delivery — so "all deliveries except the last"
corresponds to consumption in the closed time window. This rate is extrapolated
onto the head (before the first delivery) and the open tail (after the last
delivery):

```text
stock_day = max(0, stock_prevday + delivery_day
                   - (base_load_L + rate · HDD_day))
```

A final stock of 0 is **no longer** forced — the remaining stock follows
physically. Fallback with < 2 deliveries (no cadence derivable): rate from
(initial stock + Σ deliveries) over the window HDD; without temperatures: a flat
draw.

The **cost/efficiency calculation** still uses the energy balance from §3 — there
"bought ≈ consumed over the term" is correct; only the *stock curve* needs the
calibrated rate.

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
- **Expecting a tank gauging**: the stock is modelled, not measured. A real
  dip-stick input is (still) not provided.
- **A planned delivery** (`is_planned`) does not count in the balance/stock — by
  design, so that forward planning does not distort the actual state.

---

[← District heating](04-fernwaerme.md) · [Wood pellets →](06-pellets.md)
