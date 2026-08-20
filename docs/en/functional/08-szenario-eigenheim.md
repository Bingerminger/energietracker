# Scenario: home owner

**English** · [Deutsch](../../functional/08-szenario-eigenheim.md)

[← Scenario: flat](07-szenario-wohnung.md) · [Compendium index](../README.md)

Typical starting point: a single-family home, **one** heat source
(gas / district heating / heat-pump electricity / heating oil / pellets), your own
**electricity**, your own **water** meter, possibly a **tank** for oil/pellets,
and increasingly **photovoltaics** on the roof. This is where Energietracker
unfolds its full functionality.

---

## 1. Recommended setup

1. Maintain **living area, year of construction, building type** in the settings —
   the basis of the **efficiency class**.
2. Run **one** heat source as active (see §3). Plus electricity + water.
3. Activate **temperatures**: set the location coordinates (default Leipzig) and
   use the Open-Meteo sync or import CSVs — without a temperature series there is
   no HDD analysis/weather adjustment.
4. **Record regularly**: read cumulative meters monthly; enter oil/pellet
   deliveries right after receipt (quantity + invoice amount).

---

## 2. Use the full analysis cycle

```text
readings/deliveries --> monthly consumption --> HDD regression
        |                                            |
        v                                            v
   weather adjustment <----------------------- seasonal profile
        |                                            |
        +--------------> forecast (R2 blend) <-------+
                                |
                                v
                  efficiency class / recommendations
```

Concretely:

- **Heating signature** (analysis): how strongly does your consumption depend on
  the cold? A high `R²` = strongly heating-driven. The `sigmoid` curve shows the
  saturation on very cold days.
- **Weather adjustment**: separates "cold winter" from real over-consumption — the
  core question after a renovation ("does the new insulation really help,
  weather-adjusted?").
- **Efficiency class per source** (since v1.4.0): an honest kWh/m²·a classification
  of your main heating.
- **Recommendations**: seven statistical rules (over-consumption trend, summer
  base, anomaly, tank level, contract end, efficiency …) — purely from your own
  data, no advertising.

---

## 3. Run exactly ONE heat source as active

A house really heats with one source. If several heating types are active at the
same time, the *combined* efficiency class would be nonsensical (the sum of
several full heating systems on the same area). Therefore:

- Run your real heat source as active (e.g. only `gas`).
- Leave the other heating types **inactive** (the data is preserved, only hidden
  from the sidebar/dashboard).
- **Exception** — deliberately combined operation (e.g. a pellet base load + a gas
  peak load): leave several active and use the `combined` efficiency view;
  `per_source` additionally shows each source individually.

---

## 4. Oil/pellet households: keep an eye on the tank

- Enter **every delivery** with quantity and **invoice amount** (`total_eur` has
  taken precedence since v1.4.2 — it includes the delivery fee/rebate).
- The **stock curve** (calibrated since v1.4.0) shows a realistic sawtooth and,
  via `tank_warn_pct`, warns in good time before it runs empty → reorder in time
  (ideally in summer, when oil/pellets are cheaper).
- Plan the next delivery in advance as `is_planned` — it appears but does not
  distort the balance/stock.

Details and formulas: [Heating oil](05-heizoel.md) / [Pellets](06-pellets.md).

---

## 5. Measuring before/after a renovation

The most valuable application — and since **v2.4.0 (F1011)** the Energietracker
works it out itself instead of merely describing it.

**The problem.** After insulating, the house is thermally a different building: it
permanently needs less per degree of cold. A regression across both states
describes neither of them — it returns a weighted average, and keeps doing so
until the new months outnumber the old ones. With twelve years of history that
takes years.

**Approach:**

1. Cleanly record at least **one full heating period before** the measure (for a
   sound heating curve).
2. Carry out the measure (insulation, windows, heating replacement, hydraulic
   balancing).
3. Enter the date under *Baseline cut-offs* on the meter, with a description such
   as "loft insulation". A future date may be recorded in advance — it only takes
   effect once reached.

From then on **every** evaluation starts at that point: the regressions in the
analysis chart, the expected value per month, the forecast (heating curve and
seasonal averages), anomaly detection, the recommendations — and through the
forecast, the tariff comparison as well. The earlier months stay visible in the
chart, just greyed out: they are excluded from the **model**, not from the display.

**What comes out of it.** The analysis area reports the heating curve of both
epochs:

```text
before    0.42 m³ per degree day   (63 data points)
after     0.28 m³ per degree day   (41 data points)
change     −33 %                   weather-corrected
```

Consumption **per degree day** *is* the weather correction: it says how much the
building needs per degree of cold, regardless of how cold the winter was. A plain
kWh difference year/year would be misleading as soon as the winters differ.

> **If too little history has accumulated since the cut-off**, the interface says
> so in plain words ("The regression needs at least 8 data points after the
> cut-off — 5 available") instead of letting an evaluation disappear silently. The
> comparison only appears once **both** epochs are sufficiently populated.

Several measures over time are possible: whichever cut-off is the latest one
already reached takes effect.

---

## 6. Photovoltaics — setup and reading

Since v1.7.0, two PV utilities belong to the standard home setup:
`pv_einspeisung` (the distribution grid operator's meter) and `pv_erzeugung` (the
inverter's total yield). They are NOT active in the default settings — anyone
without a system sees nothing of them. Anyone with a system activates both in
*Settings → Active utilities*.

### 6.1 Which meters do I need?

| You have | Recommended setup |
|---|---|
| Only a feed-in meter (standard commissioning up to ~2022) | Activate only `pv_einspeisung`. The electricity balance is computed, self-consumption/self-sufficiency rate stay zero (`has_generation_meter: false`). |
| Feed-in meter + generation meter at the inverter | Activate both. Full view: electricity balance, self-consumption rate, self-sufficiency rate. |
| System with a battery | Activate both as above; the app shows the *effective* self-sufficiency rate (the battery automatically raises the self-consumption in the numbers). |
| Several PV strings (e.g. south and east roof with separate inverters) | Create one `pv_erzeugung` meter per string — the app sums them for the dashboard automatically. |

### 6.2 Contract = feed-in tariff

A PV contract in the app carries **only** the EEG feed-in tariff in ct/kWh, with a
validity date from commissioning. No base price, no advance plan, no special
payments — this is deliberate, because the grid operator pays by actual generation,
not by a plan.

Typical rates (German EEG, 20 years fixed from commissioning):

| Commissioning | Systems ≤ 10 kWp | ≤ 40 kWp |
|---|---|---|
| 2022 | 6.2 ct/kWh | 5.2 ct/kWh |
| 2023 | 8.2 ct/kWh | 7.1 ct/kWh |
| 2024 | 8.1 ct/kWh | 7.0 ct/kWh |
| 2025 | 7.9 ct/kWh | 6.9 ct/kWh |

[Unverified] — source: § 48 EEG. Check at commissioning, as statutory adjustments
occur half-yearly.

### 6.3 Electricity balance — the most important figure

In the main dashboard the tile **"⚡ Electricity balance {year}"** appears:

```
import (grid)          − feed-in (PV)           = net electricity balance
€1,838 (4,700 kWh)     −   €545 (6,650 kWh)     =   €1,293 cost
```

Sign convention:

- **balance > 0** → on balance you still pay (import costs higher than the PV
  remuneration). The normal case for a household without a battery.
- **balance < 0** → the PV brings in more than the import costs. This happens with
  large systems, a small import (e.g. long travel, a small household with an
  oversized PV) or with old EEG rates.

### 6.4 Self-sufficiency and self-consumption rate

With a generation meter, the following are additionally shown:

```
self_consumption_kwh   = pv_generation_kwh − pv_feedin_kwh
self_consumption_rate  = self_consumption / pv_generation
self_sufficiency_rate  = self_consumption / (self_consumption + import)
```

| System | SC rate | Self-sufficiency rate |
|---|---|---|
| 10 kWp, no battery | ~30 % | ~35–45 % |
| 10 kWp, 8 kWh battery | ~55 % | ~65–75 % |
| 10 kWp + battery + wallbox + heat pump | ~70 % | ~50–60 % (more demand!) |

The **self-sufficiency rate** is the more honest figure for the pride effect ("how
independent am I"), the **self-consumption rate** is for the economics ("what stays
of my generation in the house").

### 6.5 CO₂ as "avoided"

For `pv_einspeisung`, the app shows the CO₂ value as a negative value with the
label "avoided" and a tooltip with a method note. The calculation is
`feedin_kWh × co2_strom` factor (default 380 g/kWh = German electricity mix). It
does **not** account for the PV life cycle (manufacture, transport, recycling), but
it sits on the same methodological level as the CO₂ factor for the import — so the
numbers are directly comparable.

### 6.6 Recording discipline

- Take readings on the **same date** as the import electricity meter — otherwise
  the monthly aggregates drift apart and the balance fluctuates artificially.
- For systems with a battery: the battery state (charge SoC) is not recorded by
  the app. Only generation and feed-in count.
- For reduced direct marketing after the 20 EEG years: create a new contract with
  the current other-direct-marketing rate and set the contract end of the old EEG
  contract — the app calculates the transition to the exact date.

Full technical reference: [PV detailed concept](12-pv.md).

---

## 7. Appointments & maintenance

Create recurring appointments (heating service, chimney sweep, meter calibration
deadline, PV system inspection every 4 years). Due/overdue appointments appear on
the dashboard; on completion, the next appointment is rolled forward according to
the recurrence.

---

## Further reading

- **Record a heat pump or wallbox separately?** Create it as a **submeter** behind
  the house connection: [Meter topology](13-meter-topologie.md).
- **Feed meters automatically from Home Assistant:**
  [Home Assistant integration](../HOME-ASSISTANT.md).
- **Fully worked examples** (PV + heat pump, a landlord with several units):
  [Application examples & use cases](../USE-CASES.md).

---

[← Scenario: flat](07-szenario-wohnung.md) · [Glossary →](09-glossar.md)
