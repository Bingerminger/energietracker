# Scenario: flat dweller (rented flat)

**English** · [Deutsch](../../verstehen/07-szenario-wohnung.md)

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
| Heating (heat) | **good** with the monthly consumption information *(since v3.1.0)* | consumption per period; before that only with a meter of your own in the flat |
| Hot water | **good**, if flat meter or consumption information | role “Hot water”, the heat for it as a calculated value |
| Cold water | **good**, if flat meter | otherwise only the building statement |
| Building gas/district heating | mostly **not** directly | paid by the landlord; you see the heat of your flat |

**Recommendation:** focus on **electricity**, **heat** from the consumption
information and — if present — **water**. With “I live: in a rented home” the
page **Tenancy** is added (section 3a).

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
balance = Σ actual costs - Σ advances paid
```

A positive balance months before the annual statement warns early of a
back-payment — you can have the advance actively adjusted instead of being caught
out; the balance card suggests an amount for this. A strongly negative balance
means you are lending the supplier money interest-free → lower the advance.

Since v2.8.0 the balance is computed by calendar up to today, even if the last
reading was months ago: the advances count as they were debited, and the
consumption since the last reading is estimated and shown as an estimate
([Fundamentals §10](00-overview.md#10-balance-to-date)).

---

## 3a. Heating and service charges *(since v3.1.0)*

You do not pay for heating and hot water to a supplier but through the service
charges to the landlord. So there is no balance in the sense of a supplier
contract, but a counterpart — the page **Tenancy** under Costs & contracts:

1. **Settings → Household & building → “I live”: “in a rented home”.**
2. Switch on the utility **Heat** and create a meter with the recording
   **“Consumption per period”**. Each month enter the value from the
   consumption information (German HeizkostenV § 6a, since 2022 for remotely
   read devices).
3. Create water meters with the roles **Cold water** and **Hot water** — side by
   side, not as sub-meters.
4. Create the **tenancy**: prepayment, billing date, prices from the last
   statement, flat charges, assignment of the meters.

The card “Prepayment and costs” then works like the balance: expected costs of
the current billing period against the prepayment, with a suitable prepayment
per month.

```text
outcome = Σ (heat kWh × price + water m³ × price + flat charges / 12) − Σ prepayment
```

An **estimate, not a service charge statement** — what the landlord bills may
differ. The deadlines for the statement and for objections appear in the
calendar. Step by step: [As a tenant](../anleitungen/mieter.md); background:
[Heat](15-waerme.md).

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
  class appear — the class is a building metric and, for flat dwellers without
  their own heat metering, of little significance. With heat from the
  consumption information (since v3.1.0) a figure for the flat appears — a
  rough guide, because the position in the building and the neighbouring flats
  play a part.
- Do not think of water in kWh — it stays m³.

---

## 7. Special case: plug-in solar

Plug-in solar devices (“balcony power plants”) feed into the home’s wiring via
a socket. In Germany, since the Solarpaket I (2024):

- **Size:** up to **2,000 Wp** of modules and **800 VA** of inverter power.
- **Registration:** only in the **market master data register**
  (Marktstammdatenregister), within a month of commissioning; no longer with
  the grid operator.
- **Surplus:** what goes into the grid is taken by the grid operator **free of
  charge** — there is no feed-in tariff.
- **Meter:** an old Ferraris meter without a backstop may run backwards for a
  transition period, until the metering operator replaces it with a modern
  meter. A modern meter counts import and feed-in separately or only the
  import — it never runs backwards.

Up to v3.0 this page said a backward-running meter was “automatically
excluded” by modern meters — for old Ferraris meters that was not true.

**Setting up (since v3.1.0):**

1. Switch on the utility **PV generation** (Settings → Utilities & billing →
   Active utilities) and create the inverter’s meter if it shows a kWh reading
   (on the device or in the manufacturer’s app; a file from there is read by
   [Time series from portals](../anleitungen/daten-aus-portalen.md)). In the
   meter dialog tick **“Plug-in solar device (balcony system, no feed-in
   meter)”**; under “PV system”, optionally investment and commissioning date
   for the payback.
2. Under Settings → Utilities & billing → **Photovoltaics** enter the
   **assumed self-consumption**, for example 70 %. Without a feed-in meter the
   app calculates self-consumption with it and shows savings, self-sufficiency
   and payback — marked as an assumption
   ([PV §8](12-pv.md#8-plug-in-solar-v310)).
3. You do not need **PV feed-in**: there is no contract and usually no meter
   for it. If you can read the feed-in register of a modern meter, create it
   there — then the app calculates with the measured feed-in instead of the
   assumption.

**Measuring the effect — the baseline date.** The actual effect shows on the
electricity meter: less import. On the electricity meter, under *Baseline
dates*, enter the commissioning date with the label “Plug-in solar in
operation”. Since v3.1.0 the analysis also compares before and after for
electricity: the daily average per calendar month in both phases, over the
months that exist in both (at least three), scaled up to a year — in the card
“Effect of the measure”, stating whether the difference is statistically
supported. The weather is not adjusted; a sunny summer counts. The comparison
becomes reliable after a year with the device.

---

## Further reading

- **Capture values automatically** instead of typing them monthly? If you use
  Home Assistant: [Home Assistant integration](../anleitungen/home-assistant.md).
- **More practical cases** (incl. a shared flat with shared meters):
  [Application examples & use cases](../anleitungen/anwendungsfaelle.md).

---

[← Wood pellets](06-pellets.md) · [Scenario: own home →](08-szenario-eigenheim.md)
