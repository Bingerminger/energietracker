# Heat, consumption per period and meter roles

**English** · [Deutsch](../../verstehen/15-waerme.md)

[← Country profiles](14-laenderprofile.md) · [Compendium index](../README.md)

Since **v3.1.0** the Energietracker knows a ninth utility, **Heat**, a third way
of recording, **consumption per period**, and **roles** for meters. All three
came with the tenant package but are not limited to tenants. How to set them up
step by step as a tenant is in [As a tenant](../anleitungen/mieter.md).

| Property | Value |
|---|---|
| Key | `waerme` |
| Recording | **cumulative** (heat meter in kWh) or **consumption per period** |
| Unit | kWh (meter reading and consumption) |
| Conversion | none |
| HDD-relevant | **yes** — heating model, weather adjustment and forecast as for gas |
| Contracts | **none** — paid through the tenancy |
| Active by default | **no** |
| Colour | Orange |

---

## 1. What heat is

Heat is the warmth that **arrives in your home** — counted by the flat’s heat
meter or reported in the monthly consumption information from the metering
service. It is not the fuel the heat is made from.

| | Heat | Gas, heating oil, pellets | District heating |
|---|---|---|---|
| What is counted | heat in the home | fuel before the boiler | heat at the building connection |
| Who is paid | the landlord (service charges) | the supplier | the supplier |
| Contracts, advances, balance | none | yes | yes |

Typical cases:

- **Tenant in a building with central heating.** The heating belongs to the
  landlord; you only see what your flat uses. That is heat. The costs run
  through the service charges ([tenancy](../anleitungen/mieter.md)).
- **Heat pump with a heat meter.** If you want to see how much heat the heat
  pump delivers, create a Heat meter with the role “Heat pump output”
  ([roles](#3-meter-roles)); linked to its electricity meter, it gives the
  seasonal performance factor ([§7](#7-seasonal-performance-factor-of-the-heat-pump-v310)).

If the building is on a district heating network but you have no contract of
your own with the supplier, it is heat for you as well; “Heat comes from” is
then set to district heating ([§5](#5-co₂--an-approximation)). The utility
[District heating](04-fernwaerme.md) is for a supply contract of your own. All
three cases as sample households: [use case F](../anleitungen/anwendungsfaelle.md#f--district-heating-or-heat).

**Switching it on:** Settings → Utilities & billing → Active utilities →
**Heat**. Schema step 1.7.0 creates the data pots (`data/waerme/`) empty, also
in existing installations. There is no default meter: create it under
Consumption → Heat → Meters.

The app keeps heat in kWh and does not convert other units.

---

## 2. Consumption per period

Until now the app knew two ways of recording: **meter readings** (gas,
electricity, water, district heating, PV) and **deliveries** (heating oil,
pellets). Since v3.1.0 there is a third: **consumption per period**. Instead of
a meter reading you enter the consumption itself, together with the period it
covers.

Meant for values that already come as consumption:

- the **monthly consumption information** from the metering service (German
  Heating Costs Ordinance, HeizkostenV § 6a: since 2022 every month for
  remotely read devices, with the previous month, the same month last year and
  an average user),
- monthly or interval values from a customer portal,
- a heat meter of which only monthly values are known.

### Setting it up

The meter dialog (Consumption → *utility* → Meters → Edit) has the choice
**“Recording”**: “Meter readings” or “Consumption per period”. This works for
every utility with meter readings, not for heating oil and pellets. Switching
is only possible while the meter has **no data of the current kind** — if you
want to move from readings to periods, a second meter is usually the better
way.

A meter with consumption per period takes **no meter readings**, neither in the
interface nor through the API or Home Assistant. There is no meter swap for it.

### Entering values

- **Meter readings (capture):** for such meters the capture view shows a card of
  its own with **Month** and **Consumption**. The month after the last period is
  pre-filled. Expandable below: **“Comparison values from the consumption
  information”** — previous month, same month last year, average user. The
  comparison values are only stored and shown, not used in calculations.
  Without a network the entry goes into the queue like a meter reading
  ([meter-reading capture](11-zaehlerstaende.md)).
- **Consumption view:** instead of the readings it shows the table
  **“Periods {year}”** with “+ Period”, edit and delete. Here you can enter any
  period (from, to inclusive), not only whole months.
- **CSV:** Consumption → *utility* → Meters → **CSV import** reads periods —
  one line each `month;consumption[;note]` (month as `01.2026`, `01/2026` or
  `2026-01`) or `from;to;consumption[;note]`. The header line is optional; a
  preview first shows what would be read. Overlapping lines are skipped and
  reported, not overwritten. The app’s own export (`periods.csv`) can be read
  back in.

### How the app calculates

Each period is spread over the months **to the day**:

```text
daily rate    = consumption / days of the period     (from and to inclusive)
month's share = daily rate × days of the period in that month
```

> **Example.** A period from 15 January to 14 February with 900 kWh has 31 days,
> so about 29 kWh a day. January gets 17 days (about 494 kWh), February 14 days
> (about 406 kWh). A monthly value from the consumption information — 1 to
> 31 January — lands entirely in January.

After that **everything works as with meter readings**: monthly chart, heating
model, weather adjustment, forecast, contracts and balance (for utilities with
contracts), CSV monthly overview, annual report.

- **Gaps stay gaps.** A missing month has no coverage. A partly covered month is
  a partial month and — as with readings — only goes into the heating model if
  it has enough days (`min_days_period`).
- **No overlap.** Two periods of the same meter must not overlap; the second is
  rejected.
- **Gas:** a period can be given in kWh (consumption) or in m³ (meter unit). The
  app converts m³ to kWh with the dated gas factors and converts kWh back to m³
  for the bill check and the CSV. All other utilities have only one unit.
- **Reading due:** the reminder “take a meter reading” (To do, calendar) follows
  the **end of the last period**, with the same limit as for readings
  (Settings → General → “Warn after”, default 45 days).

---

## 3. Meter roles

Within its utility a meter can measure different things: household electricity
or a heat pump, cold or hot water. For this, since v3.1.0 the meter dialog has
the choice **“Role”** (API field `role`). For electricity it replaces the former
checkbox “Heating electricity (heat pump)”.

| Utility | Roles (first = default) | Effect |
|---|---|---|
| Electricity | Household · **Heat pump (heating electricity)** · **Wallbox (EV charger)** | Heat pump counts as heating energy in the efficiency figure; the wall box gets the [charging record](../anleitungen/ladestrom-nachweis.md); neither is part of household electricity in the benchmark |
| Water | Cold water · **Hot water** · Garden | Hot water: the heat for it as a calculated value ([Water](03-wasser.md#heat-for-the-hot-water-v310)) |
| PV generation | Generation · **Battery – charging** · **Battery – discharging** | batteries count neither as generation nor in sums, only in the card “Battery” ([PV §7](12-pv.md#7-battery-v310)) |
| Heat | **Home consumption** · **Heat pump output** | home consumption counts as heating energy; heat pump output counts neither in sums nor in the efficiency figure, but gives the seasonal performance factor together with the electricity ([§7](#7-seasonal-performance-factor-of-the-heat-pump-v310)) |

- Without a role the **first role** of the utility applies; it is not stored
  separately. Existing meters thus keep their behaviour.
- For electricity, the role “Heat pump” is the same as the older field
  `heat_source`; the app keeps both in step so that older versions and scripts
  still recognise the heat pump.
- **Sums:** the battery roles and the heat pump output measure energy that is
  already counted elsewhere; they therefore do not count in the sums of their
  utility. All other roles count like any meter — a wall box with its own meter
  appears in electricity. If such a meter sits behind another one, it belongs
  underneath as a sub-meter ([meter topology](13-meter-topologie.md)).

The meter list shows the role as a tag, unless it is the default role.

---

## 4. Efficiency figure

Heat counts as a heat source of its own in the efficiency figure (kWh per m²
and year) — but only meters with the role **“Home consumption”**.

The **heat pump output** does not count: the heat pump is already in the figure
with its electricity (role “Heat pump” on the electricity meter). If its heat
counted as well, the same heating would appear twice.

The area comes from Settings → Household & building → Living area. The
efficiency classes are meant for buildings; for a single flat in a larger
building the figure is a rough guide — position in the building and neighbours
play a part.

---

## 5. CO₂ — an approximation

Heat has no CO₂ factor of its own. The value comes from the **energy source of
the heating**: setting `waerme_energietraeger` (Settings → Household & building
→ “Home and hot water” → “Heat comes from”) — gas, heating oil, wood pellets,
district heating or electricity. The app takes that source’s factor, for
electricity the one of the respective year.

```text
CO2 [kg] = heat [kWh] × factor of the energy source [g/kWh] / 1000
not specified: 0
```

This is an **approximation**, because the heat is counted, not the fuel:

- For **gas, heating oil and pellets** the losses of the system are missing —
  the value tends to be too low.
- For **district heating** the basis fits best: its factor also applies per kWh
  of heat delivered.
- For **electricity from a heat pump** the value is clearly too high, because
  1 kWh of electricity becomes about 3 kWh of heat, depending on the system. If
  you record the heat pump with its electricity meter, that is where the right
  value is.

The card “CO₂ price in the fuel” (v3.1.0) uses the same approximation when the
heat comes from gas or heating oil — there with the BEHG standard factors
([CO₂ price in fuel](16-co2-preis.md)).

---

## 6. Hot water

With many central heating systems the heat for hot water is not part of the
flat’s heat; it is billed through the **hot-water meter**. If a water meter has
the role **Hot water**, the app calculates the heat for it under HeizkostenV § 9
(2):

```text
Q [kWh] = 2.5 × V [m³] × (t_w − 10)      t_w = warmwasser_temp_c, default 60 °C
1 m³ at 60 °C = 125 kWh
```

A calculated value, not a measurement — details under
[Water](03-wasser.md#heat-for-the-hot-water-v310). The setting
`warmwasser_energietraeger` (“Hot water is heated by”) records what heats the
hot water, including “Heat (central)”. It is for information only and changes
no calculation.

---

## 7. Seasonal performance factor of the heat pump *(v3.1.0)*

How much heat does the heat pump make from one kWh of electricity? Measured
over a year, that is the **seasonal performance factor (SPF)**:

```text
performance factor (month) = heat / electricity      SPF = Σ heat / Σ electricity
Example (made up): 9,000 kWh heat / 2,500 kWh electricity = SPF 3.6
```

**Setting up.**

1. Create the heat pump’s electricity meter with the role **“Heat pump
   (heating electricity)”** — usually as a sub-meter of the house connection.
2. Create the heat pump’s heat meter under **Heat**, role **“Heat pump
   output”**. The meter dialog then shows **“Heat pump electricity meter”**:
   choose the meter from step 1 there (with two electricity meters, e.g.
   compressor and backup heater, both).
3. Read both meters — by hand, via Home Assistant or from a file of the
   manufacturer’s portal ([Time series from portals](../anleitungen/daten-aus-portalen.md)).

**The card “Heat pump {year}”** appears in the consumption view of both
meters: the seasonal performance factor with the number of months it comes
from, the factor of the **heating season** (October to April), heat and
electricity of the year, and per month heat, electricity and performance
factor. If the heat meter or the link is missing, the card says what to do.

**For context** it names the Fraunhofer ISE field test “WP-QS im Bestand”
(2025): air/water heat pumps averaged 3.4, brine/water 4.3.

**Limits.**

- Only months in which **both** sides have values count. A summer without a
  reading on the heat meter is therefore missing from both sums, not just one.
- A performance factor is **not weather-adjusted**. Cold months usually have
  lower values; the API returns the heating degree days per month for this
  (`by_hdd`).
- **Where the meters sit** decides what is in the figure: backup heater, hot
  water, circulation pumps. Depending on this balance boundary, performance
  factors of the same system differ by around 15 % — values from different
  sources are therefore only partly comparable.
- The heat output counts neither in the efficiency figure (§4) nor in the sums
  of heat; the heat pump is there with its electricity.

API: `GET /api/heat-pump?year=` ([API reference](../referenz/api.md#seasonal-performance-factor-of-the-heat-pump-v310)).

---

## 8. Technical view

- Meters: fields `role` and `capture` (`counter` | `period`; missing = meter
  readings).
- Periods: pot `<utility>/periods.json`, routes `…/periods`, CSV import and
  export — [API reference](../referenz/api.md#consumption-per-period-v310),
  [data model](../referenz/datenmodell.md).
- Monthly values of hot-water meters carry `dhw_kwh` and `dhw_temp_c`
  (additive).
- Heat meter of the heat pump: field `heat_pump_meter_ids` (v3.1.0), the linked
  electricity meters with the role `heat_pump`.

---

[← Country profiles](14-laenderprofile.md) · [Compendium index](../README.md) ·
[CO₂ price in fuel →](16-co2-preis.md)
