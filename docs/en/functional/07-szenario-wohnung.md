# Scenario: flat dweller (rented flat)

**English** · [Deutsch](../../functional/07-szenario-wohnung.md)

[← Wood pellets](06-pellets.md) · [Compendium index](../README.md)

Typical starting point: a rented flat, **electricity** on your own contract,
**heating/hot water** centrally via the service-charge statement (the building's
gas/district heating, often only annual and only visible pro rata), **water**
partly split cold/hot. No tank of your own.

---

## 1. What can realistically be tracked

| Quantity | Tracking | Note |
|---|---|---|
| Household electricity | **good** — own meter, own bill | core benefit |
| Hot water/heating | **limited** | only if your flat has its own meters |
| Cold water | **good**, if flat meter | otherwise only the building statement |
| Building gas/district heating | mostly **not** directly | only estimable via the annual statement |

**Recommendation:** focus on **electricity** and — if present — **water**. Only
track heating if the flat has its own meters.

---

## 2. Recommended setup

1. Reduce the **active utilities** in the settings to what you really measure
   (e.g. only `strom`, `wasser`). Inactive utilities disappear from the
   sidebar/dashboard — this keeps the interface clear.
2. Create the **electricity meter**, record the initial reading with a date.
3. Enter the **electricity contract** with working price, base price, advance —
   so the **balance** shows whether your advances are too high/low.
4. **Read monthly** (a photo of the meter is enough as a reminder). The more
   regular, the better the evaluation.

---

## 3. What the balance is good for

The running balance is especially valuable for tenants:

```text
balance = Σ advances paid - Σ actual costs
```

A strongly negative balance months before the annual statement warns early of a
back-payment — you can have the advance actively adjusted instead of being caught
out. A high positive balance means you are lending the supplier money
interest-free → lower the advance.

---

## 4. Shadow contracts: calculate a tariff switch

Create a **shadow contract** (`is_shadow`) with the terms of a desired tariff.
The tariff comparison applies it to your **real** historical consumption —
without changing the balance or forecast. This gives you a sound view of whether
a switch would have paid off, before you switch.

---

## 5. Understanding electricity consumption (without HDD)

Electricity is not heating-driven (see [Electricity](02-strom.md)). Useful
readings:

- **Seasonal profile**: a winter peak is often from lighting/standby; a summer
  peak points to an air conditioner/fan.
- **Base-load increase**: if the base rises over months, it is worth hunting for
  permanent consumers (an old fridge, a server, an aquarium). The trend/anomaly
  rule draws attention to it.
- **Saving check**: reducing the base load by 50 W saves over a year ≈
  `0.05 kW × 8760 h ≈ 438 kWh`.

---

## 6. What you should NOT force

- Do not invent "estimated" building heating values just to make an efficiency
  class appear — the class is a single-family-home metric and, for flat dwellers
  without their own heat metering, of little significance.
- Do not think of water in kWh — it stays m³.

---

## 7. Special case: balcony solar plant

Plug-in mini PV systems ("balcony power plant", up to 800 W) feed in via a normal
socket since the Solar Package I. There is **no feed-in meter** (a backward-running
import meter is automatically excluded by modern two-way meters, which only show
the net import). Consequences for the app:

- **Activating `pv_einspeisung` brings nothing** — you have no meter for it. With
  no data, the app computes empty.
- **`pv_erzeugung` is optionally useful** if your inverter has a kWh meter. You
  then record its reading monthly and see the generation as pure statistics. The
  electricity balance stays unchanged (no feed-in share), but you have a
  performance check of your balcony plant.
- The actual effect shows on the normal `strom` meter: *less* import. So you
  measure the economics of a balcony plant by comparing the import kWh
  before/after (ideally weather-adjusted, but electricity is mostly not strongly
  HDD-driven — an annual mean is enough).

---

## Further reading

- **Capture values automatically** instead of typing them monthly? If you use
  Home Assistant: [Home Assistant integration](../HOME-ASSISTANT.md).
- **More practical cases** (incl. a shared flat with shared meters):
  [Application examples & use cases](../USE-CASES.md).

---

[← Wood pellets](06-pellets.md) · [Scenario: own home →](08-szenario-eigenheim.md)
