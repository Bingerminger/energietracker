# What can I do at each level?

**English** · [Deutsch](../../referenz/stufen.md)

[← Compendium index](../README.md)

The experience level decides what the interface shows: **🌱 Beginner**,
**🌿 Experienced** or **🌳 Expert**. This page says what you can do at each
level and helps you choose. How to switch and who has which level is in
[Setup and experience levels](../einstieg/einrichtung.md#3-experience-levels).

Since **v3.7.0** the assignment has been thought through again. Three
sentences decide:

| Level | The sentence behind it |
|---|---|
| 🌱 Beginner | “I want to know what I use, what it costs and whether the advance payment is enough. The app should read the supplier’s mail for me.” |
| 🌿 Experienced | “I want to compare and check.” |
| 🌳 Expert | “I want to see and connect everything.” |

The **household** decides which pages you need, not the level. Anyone with PV,
a battery, a heat pump or a wallbox sees their pages as a beginner too, just
with simple content. A house with gas, PV and a wallbox needs more pages than a
rented flat with electricity, but not more expertise.

**What the level does not change:**

- The app **calculates everything at every level**. API, CSV export, backup,
  Home Assistant and the calendar feed deliver the same.
- **Hidden things stay reachable.** A link or a bookmark opens the page with
  the note “This page belongs to the “…” level. It is open anyway.”
- **Hidden fields stay in the form.** A contract you save as a beginner keeps
  its bonuses and price changes.
- **After an update** an existing installation is set to “Expert”.
- With [users in the household](../anleitungen/benutzer.md) **every person has
  their own level**.

---

## 1. Which level suits me?

**Tenant, electricity only.** 🌱 Beginner is enough. The overview answers
“Will I get money back?”, “More or less than last year?” and “What needs
doing?”. She enters the reading under Meter readings and drops the annual bill
and a price change into the inbox. With the forecast workshop she plays
through what a cold winter or another tariff costs. To recalculate the annual
bill or check a switch, she moves to 🌿 Experienced.

**Flat with district heating.** 🌱 Beginner is enough for everyday use. Since
v3.7.0 the contract shows the capacity charge, the metering charge and the
connected load there already; they are on every district heating bill. The
consumption view explains with “Colder, or used more?” whether the winter
or your own heating makes the difference. 🌿 Experienced is worth it for
“Check a bill” and the monthly table with weather-adjusted values.

**House with gas, PV and a wallbox.** 🌱 Beginner shows all utilities, the PV
with the explainer “Energy flow in the house”, the wallbox as a meter of its
own and, on the Flexibility page, the result with “Your lever”. Because the
household has PV and a wallbox, the app suggests 🌿 Experienced once (§ 3).
Experienced adds the energy flow per month, battery and payback, the charging
record, charging sessions from evcc, “Tariff switch” for gas and electricity
and the meter structure. This household needs 🌳 Expert only with quarter-hour
values, for instance from Home Assistant or the grid operator’s portal: then
Flexibility recalculates per meter.

---

## 2. Features by task

✓ means: the level shows it. – means: hidden, but reachable by link. The
columns run from left to right: what beginners see, every higher level sees
too. A test checks every row with an anchor against the code.

### Enter

| Feature | 🌱 | 🌿 | 🌳 | What for |
|---|---|---|---|---|
| <a id="level-readings-entry"></a>**Meter readings** — all meters in one go, photo as a receipt, queued when offline | ✓ | ✓ | ✓ | enter the reading; with text recognition set up, the photo suggests it |
| <a id="level-contracts-overview"></a><a id="level-contracts"></a>**Contracts & payments** — supplier, term, unit price and standing charge, advance payment | ✓ | ✓ | ✓ | what it costs, whether the advance payment is enough |
| <a id="level-el-notice"></a>Contract: **notice period** | ✓ | ✓ | ✓ | *for everyone since v3.7.0:* it creates “Cancel by …” in the to-dos |
| <a id="level-el-fw-prices"></a>District heating: **capacity charge, metering charge, connected load** | ✓ | ✓ | ✓ | *for everyone since v3.7.0:* they are on every district heating bill |
| <a id="level-el-contract-terms"></a>Contract: price guaranteed until, “Cancellation takes effect”, minimum term, “Renews unless cancelled” | – | ✓ | ✓ | plan a switch |
| <a id="level-el-price-changes"></a>Contract: more price rows (“+ Row”), bonuses, special payments | – | ✓ | ✓ | reflect price changes and refunds exactly |
| <a id="level-el-contract-expert"></a>Contract: district heating key figures (CO₂, primary energy), prices per meter of a group, § 14a module 1 and module 3, credit notes from the direct marketer, “Import monthly prices”, counts per contract | – | – | ✓ | special cases of tariff and grid fee |
| Reading: **“Only for the bill”** and **“Reading is correct”** in the edit dialog *(v3.9.0)* | ✓ | ✓ | ✓ | a reading the supplier billed with that skews the months |
| Contract: **advance payments with a rhythm** (every 2 or 3 months, due dates from the bill) *(v3.9.0)* | ✓ | ✓ | ✓ | as the supplier collects them; balance and suggestion count per due date |
| <a id="level-meters"></a>**Meter structure** — sub-meters, roles, devices and meter swaps, CSV import of readings; since v3.9.0 register (OBIS) and heating limit per meter | – | ✓ | ✓ | manage the meters of a utility (link “⚙️ Meters” in the consumption view) |
| Assistant **“Set up metering concept”** — meters, registers, sub-meters and contracts from the layout *(v3.9.0)* | ✓ | ✓ | ✓ | through Settings → Utilities & billing and the setup assistant; the button on the meters page from Experienced |
| <a id="level-el-meter-plant"></a>Meter: **PV system** (investment, commissioning, battery, plug-in solar), **heat pump electricity meter** | – | ✓ | ✓ | *from Experienced since v3.7.0:* the basis for payback and seasonal performance factor |
| <a id="level-el-meter-expert"></a>Meter: meter group, baseline dates, “Import time series” | – | – | ✓ | group contract, calculating from a renovation, quarter hours from portals |

### Understand

| Feature | 🌱 | 🌿 | 🌳 | What for |
|---|---|---|---|---|
| <a id="level-dashboard"></a>**Overview** | three answers | all key figures | all key figures | Money back? More than last year? What needs doing? When renting also “Does the prepayment cover it?” |
| <a id="level-el-dash-explainers"></a>Overview: the **“Explainers”** section | – | ✓ | ✓ | *from Experienced since v3.7.0:* money, timeline, energy flow, weather at a glance (beginners have “Where does my money go?”) |
| <a id="level-utility"></a>**Consumption per utility** — key figures, monthly trend, balance, explainers; tank and deliveries for heating oil and pellets | ✓ | ✓ | ✓ | PV, wallbox and heat pump have their page at every level too |
| <a id="level-el-utility-advanced"></a>Consumption: monthly table, “Measured”/“Weather-adjusted”, CO₂ tile, PV energy flow per month | – | ✓ | ✓ | compare months, take the weather out |
| <a id="level-el-pv-extras"></a>PV generation: **battery** and **payback** | – | ✓ | ✓ | *from Experienced since v3.7.0:* has the system paid off, what does the battery lose? |
| <a id="level-el-heat-pump"></a>The **“Heat pump”** card with seasonal performance factor | – | ✓ | ✓ | *from Experienced since v3.7.0:* how efficiently the heat pump works |
| <a id="level-el-utility-expert"></a>Consumption: hot water heat, CO₂ price in the fuel | – | – | ✓ | figures behind the cost |
| <a id="level-recommendations"></a>**Recommendations** | ✓ | ✓ | ✓ | tips from your data |
| “To do”: **adjust the heating limit** with “Apply (this meter only)” and “Not now”, also on the balance card, forecast and workshop *(v3.9.0)* | ✓ | ✓ | ✓ | whether the advance is enough depends on the heating limit |
| “To do”: **check a reading**, duplicate readings, note on the baseline date *(v3.9.0)* | ✓ | ✓ | ✓ | otherwise a mistyped reading silently skews the months |
| <a id="level-report"></a><a id="level-report-print"></a>**Annual report** — also as a PDF and for printing | ✓ | ✓ | ✓ | the year on one page |
| <a id="level-analysis"></a>**Analysis** — heating signature, years compared | – | ✓ | ✓ | consumption and temperature side by side |
| <a id="level-el-baseline"></a>Analysis: baseline and “Effect of the measure” | – | – | ✓ | before and after a renovation |
| <a id="level-temperatures"></a>**Weather data** — location, sync, CSV | – | ✓ | ✓ | the basis of the weather adjustment |
| <a id="level-help"></a><a id="level-help-doc"></a>**Help**, glossary and handbook | ✓ | ✓ | ✓ | terms and guides |

### Check and compare

| Feature | 🌱 | 🌿 | 🌳 | What for |
|---|---|---|---|---|
| <a id="level-tariffs"></a>**Tariff switch** — offers, switch date, break-even | – | ✓ | ✓ | is another tariff worth it? The recommendations point beginners there too |
| <a id="level-el-market"></a>Tariff switch: dynamic tariff and “Wholesale electricity prices” | – | – | ✓ | wholesale prices per quarter hour |
| <a id="level-bill-check"></a>**Check a bill** | – | ✓ | ✓ | recalculate and book the annual bill |
| <a id="level-el-bill-check-link"></a>Consumption: the “Check a bill” card | – | ✓ | ✓ | the way there from the consumption view |
| <a id="level-tenancy"></a>**Tenancy** — prepayment, service charge statements, deadlines | – | ✓ | ✓ | beginners see “Does the prepayment cover it?” on the overview |
| <a id="level-el-co2-split"></a>Tenancy: **“Sharing CO₂ costs”** | – | ✓ | ✓ | *with the page since v3.7.0:* check the landlord’s share |
| <a id="level-el-ev-report"></a>Wallbox: **“Charging record (company car)”** | – | ✓ | ✓ | *from Experienced since v3.7.0:* document the charging power |

### Plan

| Feature | 🌱 | 🌿 | 🌳 | What for |
|---|---|---|---|---|
| <a id="level-forecast"></a><a id="level-scenario-print"></a>**Forecast workshop** — winter, saving, tariff; print the result or save it as an image | ✓ | ✓ | ✓ | what if … |
| <a id="level-el-ws-advanced"></a>Workshop: “More levers” — room temperature, base load, forecast model, measures, electric car, seasonal performance factor | – | ✓ | ✓ | electric car and heat pump *from Experienced since v3.7.0*, when the household has them |
| <a id="level-el-ws-results"></a>Workshop: “Month by month”, ranking of the measures | – | ✓ | ✓ | compare measures |
| <a id="level-el-ws-expert"></a>Workshop: “For experts” — regression model, budget with the best combination, model comparison, CSV | – | – | ✓ | the method and the raw data |
| <a id="level-el-fc-classic"></a>Forecast (expanded): model, CO₂ price scenario | – | – | ✓ | calculation parameters |
| <a id="level-flexibility"></a>**Flexibility** — result, action sentences, typical day; “Your lever” on the overview | ✓ | ✓ | ✓ | when electricity is cheap |
| <a id="level-el-flex-advanced"></a>Flexibility: the week, best time windows, “Using solar power at home”, “Your lever” per device | – | ✓ | ✓ | plan shifting |
| <a id="level-el-flex-expert"></a>Flexibility: recalculation per meter, battery, § 14a module 3, quarter-hour series | – | – | ✓ | recalculate with quarter-hour values |

### Mail from the supplier

| Feature | 🌱 | 🌿 | 🌳 | What for |
|---|---|---|---|---|
| <a id="level-inbox"></a>**Inbox** — drop a PDF, have it read, check every value against its source passage, apply it; enter by hand next to the document | ✓ | ✓ | ✓ | the app reads the supplier’s mail |
| <a id="level-inbox-draft"></a>Draft from documents — set up the household from many documents at once *(v3.7.0)* | ✓ | ✓ | ✓ | first set-up without typing |
| File per contract and meter — all documents in date order *(v3.7.0)* | ✓ | ✓ | ✓ | just a list of the documents |
| Setting up reading — the “Reading” card (local or cloud) under Settings → Expert | – | – | ✓ | set up once, usually by the person who manages the installation; the inbox and the set-up assistant open the same card as a dialog, at every level |
| Shortcut “Share → Energietracker”, watched folder *(v3.7.0)* | – | ✓ | ✓ | input channels, like the integrations |

### Household and people

| Feature | 🌱 | 🌿 | 🌳 | What for |
|---|---|---|---|---|
| <a id="level-reminders"></a>**Reminders & maintenance** and the to-dos — with the person responsible, as a calendar feed | ✓ | ✓ | ✓ | miss nothing |
| <a id="level-activity"></a>**Done** — who did what and when | – | ✓ | ✓ | look it up and undo |
| <a id="level-settings-general"></a>Settings → **General** — language and country, level, your own account, note about new versions | ✓ | ✓ | ✓ | |
| <a id="level-el-settings-general"></a>General: display, reminders | – | ✓ | ✓ | periods and lead time of the reminders |
| <a id="level-settings-household"></a>Settings → **Household & building** — living area, building, tenure, heating and hot water | ✓ | ✓ | ✓ | |
| <a id="level-el-settings-household"></a>Household: own reference values, water reference values | – | ✓ | ✓ | placing your figures with your own numbers |
| <a id="level-settings-utilities"></a>Settings → **Utilities & billing** — active utilities, billing dates | ✓ | ✓ | ✓ | |
| <a id="level-el-settings-utilities"></a>Utilities: physical constants, energy sources (delivery), own flexibility values, photovoltaics | – | ✓ | ✓ | photovoltaics *from Experienced since v3.7.0* |
| <a id="level-el-settings-co2"></a>Utilities: CO₂ emission factors | – | – | ✓ | calculation parameters |
| **People in the household**: add, rename, delete (Settings → Household & building), also without sign-in *(v3.9.0)* | ✓ | ✓ | ✓ | names for “Responsible” on reminders, meters and contracts |
| Accounts and roles (Settings → Access → “Users and roles”) | – | – | ✓ | who signs in and which role applies; depends on sign-in. Up to v3.7 people could only be added here |

### Connect

| Feature | 🌱 | 🌿 | 🌳 | What for |
|---|---|---|---|---|
| <a id="level-settings-data"></a>Settings → **Data** — export, backup, restore, sample data | ✓ | ✓ | ✓ | your data is yours |
| <a id="level-settings-integrations"></a>Settings → **Integrations** — Home Assistant: token, alias, templates; since v3.9.0 notifications to Home Assistant | – | ✓ | ✓ | meter readings from outside, deadlines and new mail to Home Assistant |
| <a id="level-el-evcc"></a>Wallbox: **“Charging sessions from evcc”** | – | ✓ | ✓ | upload or fetch charging sessions |
| <a id="level-settings-access"></a>Settings → **Access** — sign-in, users and roles, API keys, embedding, host names | – | – | ✓ | who gets in |
| <a id="level-settings-expert"></a>Settings → **Expert** — reading, receipts, text recognition, evcc address; collapsed, the calculation parameters | – | – | ✓ | services in the home network, fine-tuning |
| <a id="level-settings-system"></a>Settings → **System** — version, diagnostics, security check | – | – | ✓ | operation |

---

## 3. The app suggests

If your household needs more than the level shows, the overview suggests the
next level: “Your household has more than this level shows — “…” shows it.”
The reason is shown below it.

| Level | Reason | Suggestion | What it adds |
|---|---|---|---|
| 🌱 Beginner | PV system, battery, heat pump or wallbox | 🌿 Experienced | energy flow per month, battery and payback, seasonal performance factor, charging record, charging sessions from evcc |
| 🌱 Beginner | sub-meters, several meters for one utility, values from Home Assistant | 🌿 Experienced | meter structure, integrations |
| 🌿 Experienced | quarter-hour values | 🌳 Expert | recalculation per meter, battery and § 14a module 3 in Flexibility, quarter-hour series |

- **At most once per reason.** Once you have seen a reason, it does not come
  back on this device at this level — whether you switch, choose “Don’t ask
  again” or just move on. If a new reason appears (a wallbox, say), only that
  one shows.
- **“Switch to “…””** moves up with one click. The suggestion blocks nothing.
- The **setup assistant** suggests the level from the answer to “Who are
  you?” ([Setup](../einstieg/einrichtung.md#the-steps)).

---

[← Compendium index](../README.md)
