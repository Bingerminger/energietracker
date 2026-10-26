# Meter topology — submeters & meter groups (F1006)

**English** · [Deutsch](../../verstehen/13-meter-topologie.md)

[← PV](12-pv.md) · [Compendium index](../README.md)

Since **v1.8.0** (schema 1.2.0), meters can be related to one another. This cleanly
solves two very common everyday situations: **"one meter sits behind another"** and
**"several meters actually belong together"**.

| Relationship | Field on the meter | Effect |
|---|---|---|
| **Submeter** (series connection) | `parent_meter_id` | does **not** count again in the total; with its own contract the parent contract bills only the **remainder** (since v3.9.0) |
| **Group** | `meter_group_id` | consumptions are **combined** in the dashboard; since v3.1.0 also a **shared contract** |

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

**Solution:** on the submeter, set the parent meter (`parent_meter_id`). The
amount of the utility then only counts meters without a parent:

```
utility total (amount) = only meters WITHOUT parent_meter_id
```

In the dashboard the submeter appears indented under its parent meter; in the
utility total it does **not** additionally appear. What the parent meter’s
contract bills depends on whether the submeter has a contract of its own:

| Submeter | The parent meter’s contract bills | Example |
|---|---|---|
| **without its own contract** | everything it measures (gross); the submeter is a **breakdown** | private measurement at the wall box |
| **with its own contract** *(since v3.9.0)* | only the **remainder**: measured minus submeter | heat pump with its own tariff (module 2), cascade |

> **Example without its own contract.** Household electricity measures 300 kWh
> in January (gross). According to the heat-pump submeter, 120 kWh of it is for
> the heat pump. The electricity total for January stays **300 kWh** (not 420),
> and the household contract bills the 300 kWh. The 120 kWh are only the
> breakdown of how much of it was the heat pump.

### Remainder billing *(v3.9.0)*

If the submeter has a **contract of its own** (also a group contract of its
group, not an offer), the parent meter’s contract only bills the remainder.
This applies day by day: the amount is subtracted only on days on which the
submeter’s contract runs. If it starts on 16 March, the parent contract bills
everything up to 15 March.

<!-- doc-claim: rest-billing-example (SubmeterRestDocsTest) -->
```text
Parent meter 500 kWh a month, contract 20 ct/kWh
Submeter     200 kWh a month, own contract 40 ct/kWh

Parent contract   300 kWh = 500 kWh measured − 200 kWh submeter   × 20 ct =  60 €
Submeter contract                        200 kWh                  × 40 ct =  80 €
Utility total                        500 kWh                                140 €
```

- **Amount:** the utility total stays the parent meter’s measurement (500 kWh,
  no double counting). The parent meter’s monthly row keeps `kwh` =
  measurement and adds `billed_kwh` (the amount of its contracts).
- **Costs:** remainder × parent price + submeter × its own price. Balance,
  costs & contracts, bill check, billing year, forecast and forecast workshop,
  suggested advance, annual report (“Where does my money go?”), tariff
  comparison (review and switch: expected annual consumption = remainder), CSV
  and PDF calculate this way.
- **Note:** the parent meter, its contract and the bill check say how it is
  calculated, for example “300 kWh = 500 kWh measured − 200 kWh Heat pump”. The
  API adds `rest_of` (`measured`, `submeters`, `rest`). When you create a
  contract for a submeter, one sentence says that from its start the parent
  contract only bills the remainder.
- **Different reading days:** both meters are aligned through the daily
  distribution (evenly per day between two readings). If the parent meter has
  been read more recently than the submeter, the app carries the submeter’s
  latest daily rate forward for up to 92 days and says so (`estimated_kwh`). If
  a reading is missing altogether, nothing is subtracted (`open_days`).
- **Remainder below zero:** if the submeter measures more than the parent meter
  in a month, a reading is usually wrong. The parent contract does not bill that
  month negatively (0 kWh, 0 € unit price); the note gives the amount
  (`short_kwh`) and asks you to check the readings.
- **Not for water:** there the garden meter has been deducted through the waste
  water since v3.1.0 (basis “drinking water minus deduction meters”).

### Rules

- **At most one level.** A submeter may not itself be the parent meter of a further
  submeter (no multi-level chains, no cycles).
- **Deletion protection.** A parent meter with assigned submeters cannot be deleted
  without first removing the assignment.

### Module 2: a separate meter with its own contract *(v3.1.0)*

Under § 14a EnWG, module 2, a heat pump or wall box gets a meter of its own
from the grid operator, and its grid unit price drops to 40 %
([Electricity → Controllable consumers](02-strom.md#controllable-consumers-v310)).
Two amounts are then billed at two prices. This is how you model it:

1. Create the heat pump’s or wall box’s meter as an electricity meter of its
   own, with the role “Heat pump (heating electricity)” or “Wallbox (EV
   charger)”.
2. **If it sits behind the household meter** (the household meter measures
   everything), set the household meter as its parent. **If the two meters sit
   side by side**, leave the field empty.
3. Give the meter a **contract of its own** with the lower unit price. If it
   sits behind the household meter, the household contract only bills the
   remainder from that contract’s start ([remainder billing](#remainder-billing-v390)).

Balance and costs then appear per meter; the sum of the utility is right in
both cases.

> **Correction v3.9.0.** Since v3.1.0 this section said that the app subtracts
> the submeter’s amount at the household meter and that the household contract
> only bills the rest. The app did not calculate it: the household contract
> billed everything its meter measures, the submeter’s amount was paid through
> both contracts, and the monthly cost total did not contain the submeter.
> Since v3.9.0 the app calculates as described here; a test pins down the
> numbers of the example above. If you already have a contract on a submeter,
> you will therefore see lower costs at the household meter and higher ones in
> the utility total.

Several meters one behind the other with HT/NT and solar (cascade) are
described in
[use case H](../anleitungen/anwendungsfaelle.md#h--cascade-with-heat-pump-htnt-and-solar-v390).
There the front meter measures everything and the remainder is the heat pump:
the rule works both ways, the front meter’s contract may also be the heat
pump’s. Since v3.9.0 the seasonal performance factor, efficiency and
“electricity by role” take each meter’s own consumption (measured minus
submeters); a heat-pump submeter behind the household meter therefore also
counts in the efficiency, as use case C describes.

---

## 2. Meter groups

**Situation:** several meters logically belong together and should appear in the
dashboard as *one* item:

- **off-peak + peak electricity** (two registers / two meters for the same
  connection),
- **several wallboxes** at one location,
- **several units** of the same house (see
  [Use cases → Landlord](../anleitungen/anwendungsfaelle.md)).

**Solution:** create a group and assign the meters (`meter_group_id`). The group
sums the consumptions for the dashboard and is expandable, so the individual
meters remain visible.

### Group meters

The fastest way to bundle is via **Consumption → *utility* → ⚙️ Meters → "Group
meters"**: select several existing meters, give a group name, done. In the
background the dialog sets `meter_group_id` on all selected meters
(`POST …/meter-groups/merge`).

### Group contract *(v3.1.0)*

Up to v3.0, groups only combined the **consumption for the dashboard**;
contracts always belonged to a meter. Since v3.1.0 a contract can belong to **a
group** ([#17](https://github.com/Bingerminger/energietracker/issues/17)) — for
gas, electricity and district heating, the utilities with advance-payment
contracts. The typical case is a dual-rate meter: two registers (peak and
off-peak), one contract with one standing charge and two unit prices
([Electricity](02-strom.md#peak-and-off-peak-one-contract-for-a-meter-group-v310)).

**Creating it.** In the contract dialog choose the group under **“Meter”** — it
is listed under “Meter groups (one contract for all)” once it has members.
Then **“Unit price per meter (e.g. peak/off-peak)”** appears: one price list
per member; empty means the unit price above. The contract card names the
group instead of a meter.

**Calculating.** Each member calculates its consumption at its unit price.
Standing charge, advance payments and bonuses are carried by the **first
member** only (order of the meter list) — so they count exactly once, and every
sum is right: the utility’s, the overview, the annual report, the CSV export and
the efficiency figure.

```text
Example: peak 2,000 kWh × 30 ct + off-peak 1,000 kWh × 22 ct + 12 × €12 standing charge
       = €600 + €220 + €144 = €964 per year
```

**Where you see it.**

- **Consumption view of a member:** the balance card shows the balance of the
  group contract with the note “This meter is billed through the group contract
  “…”. Balance and advance payments apply to the whole group.”
- **Tariff switch:** the meter choice lists “Group: …” for every group with a
  group contract. Forecast and switch decision calculate with the consumption of
  the whole group; with two unit prices using a blended price, weighted with the
  registers’ consumption over the last twelve months. You add an offer (shadow
  contract) for the group there.
- **To do, calendar, recommendations:** cancellation deadline, contract end and
  price increase also apply to group contracts.
- **Check a bill:** in the interface per meter — the first member with the
  fixed costs, the others with their consumption. The whole group at once is
  recalculated by `GET …/meter-groups/{id}/bill-check`
  ([API](../referenz/api.md#group-contract-v310)).

**Rules.**

- A member may have **no real contract of its own** in the period of a group
  contract, and vice versa — otherwise the standing charge and consumption
  would count twice. The app refuses it (“A meter of the group has its own
  contract in this period …”); end the old contract first. Offers (shadow
  contracts) are not affected.
- A group with contracts cannot be dissolved; first delete the group contracts
  or assign them to a meter.
- Water has no group contracts.

---

## 3. Interaction & data model

A meter may **simultaneously** be a submeter *and* a group member. The group
total does not count submeters whose parent meter is itself a member, so
nothing can flow in twice. If such a submeter is billed through the group
contract, its money still counts, and the parent meter only bills the
remainder. In the cascade (registers HT and NT each as a meter of its own) each
register of the rear meter hangs on the matching register of the front one: HT
behind HT, NT behind NT.

The **Set up metering concept** assistant *(v3.9.0)* creates this by these
rules: it asks about the layout (one meter for everything, two meters side by
side, sub-meter behind, cascade) and creates sub-meters, groups for HT and NT,
registers and contracts; existing meters are assigned
([use case H](../anleitungen/anwendungsfaelle.md#case-cascade),
[API](../referenz/api.md#set-up-a-metering-concept-v390-f1032)).

The technical view (fields, `meter_groups.json`, validation, API endpoints) is in
the [data model](../referenz/datenmodell.md) and the
[API reference](../referenz/api.md). The aliases for the Home
Assistant integration (`external_id`) are independent of it and described in
[Home Assistant](../anleitungen/home-assistant.md).

---

[← PV](12-pv.md) · [Compendium index](../README.md)
