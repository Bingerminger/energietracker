# Meter topology — submeters & meter groups (F1006)

**English** · [Deutsch](../../functional/13-meter-topologie.md)

[← PV](12-pv.md) · [Compendium index](../README.md)

Since **v1.8.0** (schema 1.2.0), meters can be related to one another. This cleanly
solves two very common everyday situations: **"one meter sits behind another"** and
**"several meters actually belong together"**.

| Relationship | Field on the meter | Effect |
|---|---|---|
| **Submeter** (series connection) | `parent_meter_id` | consumption is **subtracted** from the parent meter |
| **Group** | `meter_group_id` | consumptions are **combined** in the dashboard |

Both fields are optional (default `null`) and additive — existing data stays
unchanged.

---

## 1. Submeters (series connection)

**Situation:** a consumer hangs *behind* the main meter and is measured by it. The
classics:

- a **heat pump** behind the household electricity meter,
- a **wallbox** behind the house connection,
- **garden water** behind the main water meter.

Here the main meter measures **gross** — i.e. including the submeter. If you simply
added both, the submeter share would be double-counted.

**Solution:** on the submeter, set the parent meter (`parent_meter_id`). Then:

```
own consumption of the parent meter (net) = gross reading − Σ submeter consumptions
utility total                             = only meters WITHOUT parent_meter_id
```

In the dashboard the submeter appears indented under its parent meter; in the
utility total it does **not** additionally appear.

> **Example.** Household electricity measures 300 kWh in January (gross). According
> to the heat-pump submeter, 120 kWh of it is for the heat pump. The electricity
> total for January stays **300 kWh** (not 420) — the 120 kWh are only the
> breakdown of how much of it was the heat pump.

### Rules

- **At most one level.** A submeter may not itself be the parent meter of a further
  submeter (no multi-level chains, no cycles).
- **Deletion protection.** A parent meter with assigned submeters cannot be deleted
  without first removing the assignment.

---

## 2. Meter groups

**Situation:** several meters logically belong together and should appear in the
dashboard as *one* item:

- **off-peak + peak electricity** (two registers / two meters for the same
  connection),
- **several wallboxes** at one location,
- **several units** of the same house (see
  [Use cases → Landlord](../USE-CASES.md)).

**Solution:** create a group and assign the meters (`meter_group_id`). The group
sums the consumptions for the dashboard and is expandable, so the individual
meters remain visible.

### Merge wizard

The fastest way to bundle is via **Settings → Meters / Contracts → "Merge
meters"**: select several existing meters, give a group name, done. In the
background the wizard sets `meter_group_id` on all selected meters.

### What groups do (not yet) do

In v1.8.0, groups combine exclusively the **consumption for the dashboard**.
**Contracts stay per meter** — there is (not yet) a group contract with a shared
balance. This extension is deliberately deferred to a later release, to avoid
double-counting in the balance logic.

---

## 3. Interaction & data model

A meter may **simultaneously** be a submeter *and* a group member. The aggregation
always computes the submeter net values first, then the group sum — so nothing can
flow in twice.

The technical view (fields, `meter_groups.json`, validation, API endpoints) is in
the [data model](../technical/04-data-model.md) and the
[API reference](../technical/03-api-reference.md). The aliases for the Home
Assistant integration (`external_id`) are independent of it and described in
[Home Assistant](../HOME-ASSISTANT.md).

---

[← PV](12-pv.md) · [Compendium index](../README.md)
