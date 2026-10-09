# Setup and experience levels

**English** · [Deutsch](../../einstieg/einrichtung.md)

[← Compendium index](../README.md)

Since **v3.2.0** a new installation starts with three questions instead of an
empty interface. The setup assistant then sets the utilities and the
experience level and, if you like, shows a sample household that suits you.
The experience level decides how much the interface shows — every level
calculates everything. Explainers illustrate the key figures with your own
data.

---

## 1. The setup assistant

### When it appears

- **On the very first start** of a new installation, i.e. with an empty data
  directory — such as a fresh Docker container. It comes back every time you
  open the app until you finish it or choose “Skip”.
- **Not after an update.** An existing installation keeps its interface and is
  set to the “Expert” level afterwards.
- **In the public demo**, as long as this browser has not picked a sample
  household yet ([§ 5](#5-the-public-demo)).

### The steps

The “Set up” dialog has four steps (“Step 1 of 4”):

1. **“Who are you?”** — one of five answers (table below). The answer
   suggests utilities and a level.
2. **“Which energy sources do you use?”** — the suggested utilities are
   ticked; combine freely, at least one has to stay. A note explains the
   difference between district heating and heat
   ([§ 2](#district-heating-or-heat)).
3. **“How much experience do you have?”** — Beginner, Experienced or Expert
   ([§ 3](#3-experience-levels)).
4. **“What comes next”** — the summary and a choice:
   - **“Look at it with sample data”** loads the sample household for your
     answer in step 1 ([§ 2](#2-the-sample-households)). The app saves the
     current state as a snapshot first.
   - **“Start with my own data”** takes you to “Meter readings”: check the
     meters, add the first reading — continue with
     [Getting started](erste-schritte.md).

Nothing is saved until **“Let’s go”**: the active utilities, the tenure
(Settings → Household & building → “I live”: “in a rented home” for “I rent my
home”, otherwise “in my own home”), your answer in step 1 and the level. “Back” goes back a step, “Skip” leaves everything as it is.

| Answer in step 1 | Suggested utilities | Suggested level |
|---|---|---|
| “I rent my home” | Electricity, Heat, Water | Beginner |
| “Owner-occupied flat with district heating” | Electricity, District heating | Beginner |
| “Own house with gas, oil or pellets” | Gas, Electricity, Water | Experienced |
| “Own house with heat pump and solar” | Electricity, Heat, Water, PV generation, PV feed-in | Experienced |
| “Just look at everything first” | all | Expert |

If you heat with oil or pellets, change the ticks in step 2: Heating oil or
Wood pellets on, Gas off.

### Start it again

Settings → General → card “Experience level and setup” → **“Start the setup
assistant”**. When you start it again, your active utilities and your level
are preselected. An answer in step 1 resets the ticks in step 2 to its
suggestion and leaves the level alone. This is also how you load a different
sample household later.

---

## 2. The sample households

There is a sample household for every answer in step 1 — four new ones and
the **showcase**, the previous demo household with almost every utility. All
of them are invented: names, suppliers and meter numbers are examples. The
location is Leipzig, the data cover three years and are carried forward to
today when you load them — readings, monthly values and temperatures with the
values of the same period one year earlier, reminders relative to today.

| Sample household | Utilities | What’s in it | Good for seeing |
|---|---|---|---|
| **Rented flat** (“I rent my home”) | Electricity, Heat, Water | 62 m², one person, central gas heating in the basement. Electricity on its own supply contract, with a switch of supplier and a renewal; heat as monthly values from the metering service’s consumption information (with previous month, same month last year and average user); cold and hot water meters; a tenancy with prepayments for heating and operating costs and two service charge statements including the landlord’s share of the CO₂ costs | the overview for beginners with “Does the prepayment cover it?”, heat without a contract of its own, [As a tenant](../anleitungen/mieter.md) |
| **Owner-occupied flat with district heating** | Electricity, District heating | 85 m², two people; district heating provides heating and hot water. Heat meter with monthly readings from the customer portal; its own district heating contract with unit, standing, capacity (6.5 kW) and metering charges and several price changes; the 2025 annual bill is recorded; electricity with a switch of supplier | district heating as a supply contract with a bill, [Check the annual bill](../anleitungen/jahresabrechnung.md), calibration deadline as a reminder |
| **Classic house** (“Own house with gas, oil or pellets”) | Gas, Electricity, Water | Detached house, 140 m², four people, gas condensing boiler. Gas contract switched after a price comparison, with a price guarantee; final bill with a credit and annual bill with an additional payment and a new advance as special payments; baseline date “Hydraulic balancing” in September 2024; water with a main meter and a garden meter as a sub-meter, drinking water, wastewater and rainwater; reminders for servicing, chimney sweep and calibration | weather adjustment and baseline date, tariff switch, sub-meters |
| **Modern house** (“Own house with heat pump and solar”) | Electricity, PV feed-in, PV generation, Heat | Detached house, 150 m², four people. Household meter; heat pump with its own meter and a heat pump tariff with the reduced grid fee under § 14a EnWG (module 1); wallbox as a sub-meter of the household meter, with charging sessions from evcc; a dynamic tariff as an offer for comparison; 9.8 kWp PV on the east and west roof with feed-in remuneration; 10 kWh battery; heat meter of the heat pump | seasonal performance factor, energy flow and self-sufficiency, [Charging sessions from evcc](../anleitungen/evcc.md), [Charging record](../anleitungen/ladestrom-nachweis.md), dynamic tariff check |
| **Showcase** (“Just look at everything first”) | Gas, Electricity, Water, District heating, Heating oil, Wood pellets, PV feed-in, PV generation | the previous demo household: a detached house with eight utilities — all except heat. Meter swap, baseline date, offers for comparison, tank book; details in [demo-data/README.md](../../../demo-data/README.md) | everything at once, for the “Expert” level |

### District heating or heat?

The sample households show the difference that the note in step 2 explains:

- **District heating** has its own supply contract with a bill from the
  supplier — as in the owner-occupied flat
  ([District heating](../verstehen/04-fernwaerme.md)).
- **Heat** is only measured. It is paid through the rent (the rented flat), or
  it is the output of a heat pump (the modern house)
  ([Heat](../verstehen/15-waerme.md)).

### Loading and going back

- **In the assistant:** choose the answer in step 1, then “Look at it with
  sample data” at the end.
- **Settings → Data → “Load demo data”** and **“Try with sample data”** on the
  empty overview load the showcase.

A sample household **replaces the whole household**: meters, readings,
contracts, tenancy, reminders and the household settings. Utilities it does
not use are empty afterwards. **Your experience level stays.** The app saves a
snapshot first; if there is data already, it asks before. To go back: Settings
→ Data → “Stored snapshots” → “Restore…”. With
[users](../anleitungen/benutzer.md), only someone who manages the installation
can load one.

Via the API: `GET /api/demo/status` lists the available sample households
under `personas`, `POST /api/demo/import` with `{"persona": "mieterin",
"force": true}` loads one of them (`mieterin`, `etw-fernwaerme`,
`eigenheim-klassisch`, `eigenheim-modern`, `showcase`). An unknown name ends
with “There is no sample household “…”.”
([API reference](../referenz/api.md)).

---

## 3. Experience levels

Three levels: **🌱 Beginner**, **🌿 Experienced** and **🌳 Expert**. They decide
what you see — not what the app calculates.

| Area | 🌱 Beginner | 🌿 Experienced | 🌳 Expert |
|---|---|---|---|
| Overview | three answers: Will I get money back? More or less than last year? What needs doing? — with one explainer | all key figures | plus the “Explainers” section |
| Meter readings | record, photo as a receipt | plus CSV import | plus time series from portals and text recognition |
| Consumption per utility | monthly trend, balance, explainers | plus previous year, weather-adjusted, table | everything, e.g. anomalies, CO₂ price, seasonal performance factor, charging record |
| Contracts | unit price, standing charge, advance | plus price changes, bonuses, notice, special payments | plus groups and peak/off-peak, § 14a, shadow contracts, monthly prices, capacity charge for district heating |
| Tariff switch, check a bill | hidden — the recommendations point to them | yes | plus dynamic tariff check and wholesale prices |
| Tenancy (when renting) | “Does the prepayment cover it?” on the overview | the whole page | plus CO₂ split and check |
| Insights | annual report | plus analysis, forecast, weather data | plus baseline date, models, scenarios |
| Meter structure | hidden | sub-meters, roles | plus groups, alias, devices |
| Settings | General, Household & building, Utilities & billing, Data | plus Weather data, Integrations | everything, including Access, Expert and System |

### Hiding is not switching off

- The app **calculates everything at every level**. API, CSV export, backup,
  Home Assistant and the calendar subscription deliver the same, whatever the
  level.
- **Hidden fields stay in the form.** A contract you save as a beginner keeps
  its price changes and bonuses.
- **Hidden pages stay reachable.** A link to one — from the recommendations,
  from “To do” or a bookmark — opens the page with the note “This page belongs
  to the “…” level. It is open anyway.” and the button “Switch to “…””.
- **After an update** every existing installation is set to “Expert” —
  nothing disappears.

### Switching

- **Top bar**, next to light/dark: the “Experience level” selector. The level
  applies at once; navigation and view rebuild themselves.
- **Settings → General → “Experience level and setup”**: the same selector,
  plus one line per level on what it shows.

**Who it applies to:** without sign-in the level applies to the whole
installation, on all devices. With [users in the
household](../anleitungen/benutzer.md) every person has their own, on all
their devices; anyone who has not chosen one sees the installation’s level. In
the public demo the browser remembers it. It is stored as the setting
`ui_level` or as a setting of the person
([Settings](../referenz/einstellungen.md)).

### The overview for beginners

Once there is consumption data, the overview at the “Beginner” level shows
three answers instead of all key figures:

- **“Will I get money back?”** — per contract with advance payments “about …
  back”, “about … additional payment” or “about even”, with the date of the
  bill. Without a contract: “No contract with advance payments yet.” and the
  link “Add contract”.
- **“More or less than last year?”** — per utility the last twelve months
  against the same months a year earlier, once at least six months can be
  compared; within 2 % “about the same as last year”.
- **“What needs doing?”** — up to four items from “To do”, such as due
  readings, reminders and notice deadlines.
- When renting, also **“Does the prepayment cover it?”**, with a suitable
  prepayment per month when it gets tight.

Below, the explainer “Where does my money go?” explains the contract with the
highest advance; at the top is the button “Add reading”. The figures are the
same as at the other levels.

### Suggestions to move up

If your data has more to offer than the level shows, the overview suggests the
next level: “Your data holds more than this level shows — “…” shows it.”

| Level | Reason in the data | Suggestion |
|---|---|---|
| Beginner | sub-meters, values from Home Assistant (a meter with an alias), several meters for one utility | Experienced |
| Experienced | a meter with the role heat pump, battery or wallbox | Expert |

“Switch to “…”” moves up with one click. **“Don’t ask again”** is remembered
by this device, per level and reason. The suggestion never blocks anything.

---

## 4. Explainers

Four pictures, each answering one question — with your figures:

| Explainer | What it shows | Appears when … |
|---|---|---|
| **“Where does my money go?”** | advance payments against cost up to the bill, the cost split into consumption, standing charge and “still estimated”; the result as a credit or an additional payment | there is a running contract with advance payments (not for the feed-in) |
| **“Energy flow in the house”** | solar, house, grid and battery over one year: produced, used in the house, fed in, drawn from the grid, self-sufficiency | PV generation is recorded; the battery shows with battery meters |
| **“Colder, or used more?”** | consumption and heating degree days against the same months a year earlier, plus the weather-adjusted result: “you really used less”, “you really used more — not just the weather” or “about the same — the difference is the weather” (threshold ±2 %) | a heating energy (gas, district heating, heating oil, wood pellets, heat) has weather-adjusted months — only whole months with an adjusted value from the last twelve count, at least six with a previous year |
| **“Contract at a glance”** | start, today, “Cancel by”, price increase and end on a timeline | there is a running contract |

**Where they appear:**

- **Consumption view of a utility**, at every level: the card “Explainers”
  with all that fit this utility.
- **Overview, Expert level:** the “Explainers” section — money and timeline for
  the contract with the highest advance, the energy flow of the previous year
  and “Colder, or used more?” for the heating.
- **Overview, Beginner level:** one explainer, “Where does my money go?”.

**Motion:** bars grow and flows run as soon as a picture comes into view. If
“Reduce motion” is on for the device, the finished picture shows straight
away; the same when printing. Each picture’s statement is written below it as
text — the graphic itself is hidden from screen readers, the text is not.
Colours follow light/dark and the colour of the utility. The pictures are SVG
with CSS animation, without a library.

---

## 5. The public demo

Without installing anything: [bingerminger.github.io/energietracker](https://bingerminger.github.io/energietracker/).
On the first visit the assistant opens with three steps — “Who are you?”,
“How much experience do you have?” and “What comes next”. The demo then shows
the chosen sample household; “Skip” shows the showcase.

- **The browser remembers** the sample household and the level. Nothing else
  is saved in the demo.
- **Change it:** Settings → General → “Start the setup assistant”.
- **Link directly:** `?persona=` with the name of the sample household, for
  example
  [`…/energietracker/?persona=eigenheim-modern`](https://bingerminger.github.io/energietracker/?persona=eigenheim-modern).

---

[← Compendium index](../README.md)
