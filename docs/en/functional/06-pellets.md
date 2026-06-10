# Wood pellets

**English** · [Deutsch](../../functional/06-pellets.md)

[← Heating oil](05-heizoel.md) · [Compendium index](../README.md)

| Property | Value |
|---|---|
| Recording | **delivery-based** (delivery invoices) |
| Input unit | **kilograms (kg)** |
| Billing unit | kWh |
| Calorific value | `pellets_kwh_per_kg` (default **4.8 kWh/kg**, DIN EN ISO 17225-2 A1) |
| HDD-relevant | **yes** |
| Colour | Amber/brown |

---

## 1. Like heating oil — with one important unit difference

Pellets work mechanically **identically to heating oil**: no meter, but a store
(`capacity`, `initial_stock`) plus deliveries. The entire tank model, the energy
balance, the calibrated stock curve (v1.4.0) and the total-amount precedence
(v1.4.2) apply unchanged — see **[Heating oil §3–§5](05-heizoel.md)**.

**The one difference: the unit.** Pellets are delivered and stored in **kg**, not
litres. Accordingly:

- `volume_unit = "kg"`, `capacity_unit = "kg"`
- delivery price is **ct/kg**, total amount €
- calorific value `pellets_kwh_per_kg` instead of `heizoel_kwh_per_l`

```text
kWh = kg × Hu     (Hu ≈ 4.8 kWh/kg)
```

All exports, tables and tooltips show "kg" instead of "L" accordingly (since
v1.4.2 also the delivery CSV export, with the correct unit in the column header).

---

## 2. Contracts? — No, the delivery invoice *is* the contract

As with heating oil: no contract entity. Enter the quantity (kg) and invoice
amount (€) of the pellet delivery; `total_eur` takes precedence over a ct/kg unit
price (it includes delivery/blow-in/rebate).

---

## 3. Calorific value note

4.8 kWh/kg corresponds to certified quality pellets (DIN EN ISO 17225-2 class A1,
moisture content ≤ 10 %). Wetter or inferior goods lie below this — if needed,
adjust `pellets_kwh_per_kg` in the settings, otherwise kWh and the efficiency
class are too optimistic.

---

## 4. Demo example

5000 kg store, start 4000 kg, annual summer delivery ~2400 kg → a realistic
sawtooth (min ~44 %, max ~98 %), efficiency class in the D–E range (a
well-insulated pellet house).

---

[← Heating oil](05-heizoel.md) · [Scenario: flat →](07-szenario-wohnung.md)
