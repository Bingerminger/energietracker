# Changelog (English)

All notable changes, in English from version 3.4.0 on. The German
[CHANGELOG.md](CHANGELOG.md) is the complete record, including every earlier
version. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/).

---

## [3.9.0] — 2026-10-27 — Every contract pays only its share. Metering concepts, Home Assistant and the contract from the bill

MINOR release without a schema step (stays 1.8.0). 3.9.0 contains what was
announced as 3.7.1 (GitHub [#38]) and 3.8.0 ([#36]); those numbers are dropped.
New are **metering concepts** ([#40]): if a sub-meter has its own contract, the
parent meter only bills the remainder, a cascade with heat pump, peak/off-peak
and solar can be set up, registers carry their OBIS code, and an assistant with
diagrams creates it all. Energietracker **reports to Home Assistant by
itself**: deadlines, reminders, warnings and new mail, plus a “To do” sensor. An
annual bill turns into the **contract with prices and advance payment plan**,
for water with payments every two months too. The **heating limit** shows where
it matters, a **mistyped meter reading** stands out, and balance card, annual
report and forecast workshop calculate from **one** projection. Plus: household
from documents on an existing household, people in the household without
sign-in, a setup assistant with drawings and a pass through the whole
interface. API, CSV format 1, backup format 3.0 and the Docker environment only
grow additively.

### ⚠️ For existing installations

- **Remainder billing on the parent meter** ([#40]): if a sub-meter has its own
  contract (or bills through its group’s group contract), costs, contract
  amount, balance, forecast, tariff comparison, bill check and annual report of
  the parent meter only bill the remainder from that contract’s start. Up to
  3.7 the sub-meter’s amount was paid through both contracts, and the monthly
  total of costs did not include the sub-meter: costs drop on the parent meter
  and rise in the total of the utility. Efficiency, seasonal performance factor
  and “electricity by role” use each meter’s own consumption. A sub-meter
  without its own contract stays a breakdown.
- **Balance over the running billing period:** balance card, Costs &
  contracts, annual report, “Where does my money go?” and forecast workshop
  calculate a running contract over the running billing period, even without a
  recorded bill. Up to 3.7 the card counted from the contract start without a
  bill and the workshop the billing year; both named different balances. The
  settlement date is the **last day** of the period everywhere (`settle_on`, for
  example 31 December instead of 1 January). If fewer than three advance
  payments are left before the bill, the card no longer suggests a catch-up
  amount (`suggested_advance` is then `null`, `suggestion_withheld: "short"`),
  but the advance that fits going forward.
- **Advance payments of the measured days:** the “Advances” tile and the monthly
  table count the advance pro rata by day in the first and last month of a
  reading series (`advance_measured_eur`), no longer in full.
- **Interpolated by days too:** a month also counts as interpolated if fewer
  than half of its days come from intervals of up to 45 days
  (`measured_day_share`). For meters with few readings, deviations,
  recommendations, heating model and forecast can change; monthly values and
  costs stay.
- **Forecast:** a calendar month without its own history comes from the
  heating model instead of the regression of monthly totals; if the model is
  not reliable, with the measured summer base load (`heat_model_summer`),
  otherwise from interpolated values, which for heating only count from
  intervals of up to 62 days. Where the segmented model or the sigmoid is flat,
  the measured seasonal value applies. Without a weather link (electricity,
  water) and without twelve measured calendar months the annual amount follows
  the consumption of the last 365 days (`level_last_year`). Forecasts of heating
  utilities, especially with the segmented model, and of electricity with few
  readings change. The display names the effective share of the heating curve
  (`blend_effective`).
- **Heating limit suggestion** ([#38]): smoothed over 1 °C, without limits where
  the fit drops many months or overestimates May to September, with a range;
  the suggestion is the limit that changes least. Suggestions may shift
  compared with 3.7, mostly 0.5 to 1 °C upwards. The base-load check measures
  the model’s own base load for the lower bound; at very high heating limits the
  quality gate now fails where it narrowly passed up to 3.7. Recurring months
  are a pattern and no longer a recommendation.
- **Solar energy flow:** the measured feed-in is no longer capped at the
  interpolated generation (`flows.pv_grid`). The “Solar power” block of the
  annual report takes all amounts from one period and compares whole years
  only.
- **Future contracts** show the unit price and advance of their start instead
  of “–” (API `null` before).
- **Inbox:** the browser joins a PDF’s text layer by the position of the
  characters; lines drawn backwards and pieces without a gap give a different
  text than before. The reading carries `schema_version` 5; a document read
  before keeps its reading, “Read again” asks with the new text layer. A reading
  code only applies to the reading right before it: of “old reading … I · new
  reading … S” only the new reading is created as estimated. If no contract at
  all applies at the meter, “Create contract … from this bill” is pre-selected.
- **Household from documents:** prices, advance payments and bills of a contract
  that does not exist yet only go with it. A selection without its contract is
  now refused by `POST /api/inbox/draft/apply` before anything is written
  (`400 errors.inboxDraft.needs.contract`); up to 3.7 the entries failed one by
  one, and a bill was created without a contract.
- **Watched folder:** the app now also reads it without anyone opening it, at
  most every five minutes after any request (in the Docker image the health
  check asks every 30 seconds).
- **Home Assistant app:** it now requests `homeassistant_api` (for notifications
  through the Supervisor) and the `/share` folder with write access. The watched
  folder lives there under `/share/energietracker/posteingang`. Notifications
  stay off until an admin switches them on.
- **People:** without sign-in, Settings → Access no longer shows a list of
  people; they are under Household & building. The water field “People in the
  household” is called “Number of people”.
- **Catalogues:** new keys in all seven languages; changed among others
  `assignee.noPeople`, `settings.users.noAccessHint`,
  `settings.field.wasser_personen_anzahl.label`, `inbox.empty` and the card
  titles of the settings (icon no longer in the text); removed
  `settings.users.hintOff`, `settings.users.deleteHintOff`,
  `contracts.water.apMonthly`.
- **No new outbound connection without your doing:** notifications only go to
  the address an admin enters; an address outside the home network only with
  `https://`.

### Added

#### Metering concepts ([#40])

- **Remainder billing on the parent meter:** a sub-meter with its own contract
  is deducted day by day, only on days on which its contract runs. The meter,
  its contract and “Check the bill” say how it was calculated (“300 kWh =
  500 kWh measured − 200 kWh heat pump”). If sub-meter readings are missing,
  the app carries its latest daily rate forward for up to 92 days and says so;
  if it measures more than the parent meter, no negative remainder is billed,
  and a note asks you to check the readings. When you create a contract on a
  sub-meter, a sentence says what changes on the parent meter. Not for water
  (there the garden meter deducts through the waste water).
- **Cascade with heat pump, peak/off-peak and solar:** described and calculated
  as use case H; roles follow each meter’s own consumption (the heat pump in
  front as the remainder, the household behind).
- **Registers with OBIS code** (1.8.0, 1.8.1, 1.8.2, 2.8.0) on the meter. Home
  Assistant (meter number plus code) and the inbox assign readings of the same
  meter number to the matching register, a feed-in register to the solar
  feed-in.
- **Assistant “Set up metering concept”** in five steps: what is connected to
  the electricity supply, how the meters are connected (diagrams for two meters
  side by side, a sub-meter behind, a cascade or one meter for everything),
  which meters and which contracts there are. It creates meters, registers,
  groups and contracts, assigns existing meters instead of creating them twice,
  leaves running contracts as they are and saves the current state first.
  Reached from the meters page, Settings → Utilities & billing and the end of
  the setup assistant.

#### Notifications to Home Assistant ([#36])

- **Energietracker speaks up by itself:** due appointments, deadlines and other
  “To do” items, warnings and new documents in the inbox, each message only
  once (a new stage such as “overdue” is a new message), at most five at a
  time, in the installation’s language. No schedule of its own: the check runs
  once a day from the time set, after the answer to the first request after it.
- **Three ways:** in the Home Assistant app through the Supervisor without
  setup, otherwise by webhook (with a ready-made automation) or with address
  and token. Settings → Integrations: events, time, “Send a test message”, last
  delivery. Webhook ID and token stay on the server, not in the settings and
  not in the backup.
- **“To do” sensor:** `GET /api/todo` with count, overdue items, the next item
  and up to 50 items, with a template; every new kind of item counts.
- **Watched folder** in the Home Assistant app under `/share` (reachable through
  the Samba share), new environment variable `ET_INBOX_DIR` for a place of your
  own.
- **Supplier mail by email** through Home Assistant’s IMAP integration
  described; a HACS integration is in preparation.

#### Contract from the bill

- If no contract applies at the meter in a bill’s period, the inbox creates it:
  supplier, contract account, start, **prices per section gross** (rate read per
  component, never guessed), basic and provision price as the standing charge,
  energy tax and CO₂ price within the unit price, with a visible breakdown; for
  water all three components. In the same pass the bill belongs to the new
  contract and is booked; without the contract booking is locked. A contract of
  the same supplier that ends earlier gets a follow-up contract as an offer.
- **Advance payments every two or three months or on fixed dates** for every
  utility: rhythm and dates from the bill, in the contract form as “Rhythm”.
  Calendar balance, advance suggestion, forecast, balance history, billing
  year, “Check the bill” and the key statement count per due date. Monthly
  stays as before.
- **Waste water** with a standing charge of its own.
- **Evidence check:** spaced digits (“3 1 5,5 9 €”) as one number; a reading
  code applies to its own reading; a reading from a document that does not fit
  between your own is not pre-selected and names the neighbour; a reading you
  reported, which the supplier dates to the end of the period, is “already
  there”, and “Check the bill” calculates up to your reading day. The cloud
  repeats a truncated answer for PDFs too.

#### Heating limit where it matters ([#38])

- **“Adjust the heating limit” under “To do”** when the heating model fits much
  better with a different limit: before and after, the effect on the expected
  balance and advance payment, “Apply (this meter only)” with undo and “Not now”
  until the next measured month. The same note on the balance card, forecast
  and workshop when their estimate is uncertain because of a poor fit. At every
  level; Home Assistant counts the entry.
- **Heating limit in the meter form**, also for heating oil, pellets and meter
  groups.
- **Summer reference** of the base-load check independent of the limit being
  checked (announced as 3.7.1); the suggestion must not overestimate the
  summer; `/forecast` takes `?hdd_base=` like `/consumption`.
- **Recurring months** (such as every April below the expectation) as one line
  in the analysis.

#### Check intermediate readings

- A wrong reading between two correct ones silently moves consumption from one
  month to the next. The app finds such readings, also several in a row with
  the same error, and asks under “To do”: “check the reading of …”, with
  measured against expected and its guess. “Reading is correct”, “Only for the
  bill” or “Correct it”, all with undo. If consumption before and after does
  not add up at all, it asks even without a common offset. Recurring seasons do
  not count as an error.
- **Only for the bill:** bill check, billing year, costs and balance use the
  reading, monthly values, heating model and forecast skip it; the consumption
  in between is spread by the weather.
- **Readings entered twice** with “Delete one”, and the **note on the baseline
  date** when consumption stayed on a different level for a year or more (“Set
  baseline date here” or “Not now”).
- In the list of readings the marks UNUSUAL, DUPLICATE, VERIFIED and BILL ONLY.
  No number changes until someone marks a reading.

#### Evaluations that say how certain they are

- **Energy flow** with “≈” on estimated amounts, the period covered and
  contradictory months; solar table with “!” and “*”.
- Monthly values interpolated by days too, estimates named in the number of
  readings (“8 readings and 1 estimate”), each meter with its own last reading
  date, “Cancellation confirmed?” instead of “Cancel soon” for a recorded switch,
  “Notice period not entered” instead of “Cancel at any time”, “about” without
  cents, years in the annual report from the first reading.

#### Household from documents on an existing household

- The draft takes over the one active meter of a utility and adds number and
  market location, recognises recorded contracts without a number too and never
  pre-selects what is already there (announced as 3.7.1).
- The same contract is recognised even if a later tariff confirmation was filed
  first or the start is only settled by the first bill. The brand’s
  confirmation and the municipal utility’s supply start letter a few days apart
  are one contract. With the same start and another supplier name the draft
  asks “the same supplier?”.
- **Reading behind a web server with a short time limit:** after a `504` the
  interface waits for the saved result instead of showing an error (announced
  as 3.7.1).

#### People and interface

- **People in the household without sign-in:** under Settings → Household &
  building you add, rename and delete people, at every level. They can be
  chosen under “Responsible”; a later account with the same name takes them
  over.
- **Setup assistant with drawings** for each step and each sample household,
  and the empty overview too; one shared basis for drawings and diagrams, light
  and dark.
- **Environment name as a badge** next to the logo (Settings → General), such as
  “TEST” for a test installation (announced as 3.7.1).

#### API and data (additive)

- **Routes** (186 → 195): `GET /api/todo`, `GET`/`PATCH /api/homeassistant/notify`,
  `POST /api/homeassistant/notify/test`, `POST /api/metering-concept/preview`,
  `POST /api/metering-concept`, `GET …/meters/{id}/heat-limit`,
  `GET …/meter-groups/{id}/heat-limit`, `GET …/meters/{id}/reading-checks`;
  `POST /api/users` with `has_account: false`.
- **Data:** on the meter `obis`, on tanks and meter groups `hdd_base_temp`, on
  readings `is_verified` and `is_billing_only`, in the advance payment plan
  `every_months` and `due_dates`, for water `schmutzwasser.base_prices`, on bills
  `end_reading_on`; settings `ha_notify_*` and `env_name`; environment variable
  `ET_INBOX_DIR`. No new store in the backup.
- **Responses:** `rest_of`, `billed_kwh`, `bridged`, `measured_day_share`,
  `advance_measured_eur`, `cost_measured`, `advance_dates`, `settle_on`,
  `billing_period`, `advance_ongoing`, `suggestion_withheld`,
  `successor_switch`, `blend_effective`, `current_advance_every_months`,
  `next_advance_dates`, `utility_label` in `/api/summary`, agenda kinds
  `heat_limit`, `reading_check`, `reading_duplicate`, `level_shift`; the inbox
  reading with `schema_version` 5.

### Changed

- **Interface:** no more horizontal scrolling under Access and in the header on
  the iPhone, all buttons 44 px on touch, the assistants’ footer centred,
  consistent empty states and card titles; “Where does my money go?” shows its
  bars without motion too, and the balance card’s range uses text size on the
  phone.
- **Handbook:** use case H (cascade), remainder billing in the meter topology,
  “Check a reading” in the basics and in “Ticking off notices”, notifications in
  the Home Assistant guide, contract from the bill in the inbox guide, new and
  renewed images in both languages, the limits of every new calculation named
  openly.

### Fixed

- **Sub-meters** ([#40]): since v3.1.0 the docs promised that the household
  meter’s contract bills only the remainder when the heat pump has its own
  contract behind it. The app never calculated that; now it does, and a test
  holds the example of the docs.
- **Balance card and workshop** named different balances and settlement dates
  without a recorded bill; dates lay one day after the period.
- **Shortly before the bill** the card suggested a catch-up amount that put the
  shortfall on one or two payments (in the test household €208 instead of €75).
- **Energy flow and annual report** capped the measured feed-in and compared
  part years (known limits of 3.7.0).
- **Forecast:** months without history all got the same level with the
  segmented model (May = July), summer months below the break point 80 % of it;
  the display named an 80 % share of the heating curve although every month used
  the seasonal profile.
- **Meter group:** its expectation came from the first member instead of the
  heating model of the sum; quality gate, deviations and suggestion of a group
  were wrong.
- **Household from documents:** the same contract showed as a new contract with
  an overlap when a later tariff confirmation was filed first; a bill without
  its contract booked its result on the contract that was running then.
- **Water:** the balance card said “(0 kWh)” instead of the m³.
- **Base-load check** not monotone in the heating limit (follow-up measurement
  for [#38]).
- **Reading from a document:** a reading that does not fit between your own
  readings is no longer pre-selected, and the note names the neighbouring
  reading. A reading you reported yourself, dated by the supplier to the end of
  the period, is recognised as already there; “Check bill” then counts up to the
  reading day. A rounded reading on the bill is not a conflict.
- **Advance in a rhythm:** balance card, workshop and report compared the
  suggestion with the monthly amount instead of the instalment. With advance
  payments every two months the suggestion would have come out at less than half.
- **Contract from the bill:** the advance payments already paid according to the
  bill did not reach the app, so the billing year and the report showed far too
  high an additional payment. The suggestion now adds them as a plan line.
- **One uncertainty everywhere:** overview, workshop and annual report name the
  same range as the balance card; the traffic light says “estimate uncertain”
  when it is. After applying a heating limit the card shows “≈” with the spread
  by weather instead of an amount to the cent.
- **Balance card with a poorly fitting heating model** uses reliable values like
  the forecast; summer months used to get the base price only.
- **Check readings:** after “Only for the bill” new findings came out of the
  bridged state; readings entered twice now share the decision.
- **Annual report:** partial years were compared like full years (CO₂, litres per
  person and day, kWh per m²), the cost breakdown did not add up, and “Adjust
  advance” came first for a contract about to end.
- **Change of supplier:** the billing period ends with the contract instead of the
  old reference date, without stub periods.
- **Rest billing visible:** tile and monthly table show the billed quantity. The
  assistant says from when the house contract only bills the rest and asks for
  the installation date.
- **Heating limit note** names the last day of the period and a range from the
  smaller to the larger amount; beginners see a short sentence without jargon.
- Plus wording from the sceptic run: reason for the baseline date per utility,
  the wallbox sentence only with a wallbox, assumed living area and number of
  people are named, water sections show their costs, “No contract” instead of
  “0.00 €”.

### Known limits

- Within a reading interval the app spreads evenly per day, not by the weather
  (only across a reading “only for the bill” by the weather).
- The ways through the Supervisor and the `/share` folder of the Home Assistant
  app and the email way through IMAP are tested against a mock Home Assistant,
  not yet in a running one; the HACS integration is not published.
- MiSpeL (storage with grid and solar power) is only a note in the docs and is
  not calculated yet.
- The check of intermediate readings is deliberately cautious and misses small
  offsets; with a poorly fitting heating model it only reports large ones.
- Bills of a municipal utility without a shared number or word with the brand’s
  confirmation still form a contract of their own in the draft (as a question,
  not pre-selected).

### Migration

None. The schema stays 1.8.0; new fields are optional, no new store in the
backup. Webhook ID and token of the notifications live in a file of their own
and are deliberately left out. Notifications are off until an admin switches
them on.

### Tests

- **Metering concepts:** `SubmeterRestBillingTest`, `SubmeterRestConsumersTest`,
  `SubmeterRestProjectionTest`, `SubmeterRestDocsTest`, `CascadeMeteringTest`,
  `InboxRegisterTest`, `MeteringConceptServiceTest`, Node suite
  `metering-concepts.test`.
- **Home Assistant:** `HomeAssistantNotifyTest` and `HomeAssistantEndpointTest`
  against a mock Home Assistant over HTTP, `InboxFolderTest`.
- **Contract from the bill:** `WaterBillContractTest` (end to end with a hand
  calculation), `AdvanceRhythmTest`, `InvoicePricesTest`,
  `HouseholdDraftAdvanceRhythmTest`, `WaterContractDocsTest`, `RhythmPaidSumTest`.
- **Evaluations:** `OneProjectionTest`, `SolarFlowHonestyTest`,
  `ForecastLevelTest`, `EvaluationDetailsTest`, `BillingWindowTest`.
- **Heating limit:** `HeatLimitFollowUpTest`, `ForecastMissingMonthTest`,
  `ForecastFlatRegressionTest`.
- **Check a reading:** `ReadingCheckTest`, `ReadingCheckDocsTest`.
- **Draft, people, interface:** `HouseholdDraftExistingContractsTest`,
  `PersonWithoutAccountTest`, Node suite `illustrations.test`.
- **Docs:** `DocumentedThresholdsTest` ties the figures of the new guides to the
  constants.
- 1749 test methods (+272). Every new rule has a counter-check that
  breaks it: 549 in 9 packages, all red.

### Lessons learned

- **A promise without a test stayed wrong for three releases.** Remainder
  billing on the parent meter had been in the docs since v3.1.0; the app never
  calculated it. Since then tests check the examples of the docs by calculating
  the same figures with the app.
- **One projection instead of two.** Balance card and workshop calculated the
  same figure over different periods and visibly contradicted each other. Now
  every view reads the same fields of the contract status.
- **Caution is a decision.** The check of intermediate readings reports nothing
  for any sample household and misses small errors in return. That was measured
  on made-up households with built-in errors, not on the app’s own model alone.

[#36]: https://github.com/Bingerminger/energietracker/issues/36
[#38]: https://github.com/Bingerminger/energietracker/issues/38
[#40]: https://github.com/Bingerminger/energietracker/issues/40

---

**Marketing sentence:** A heat pump on its own tariff, an annual bill in the
inbox or a deadline coming up: Energietracker bills every contract only for
what it really measures, creates the contract from the bill and speaks up in
Home Assistant by itself.

---

## [3.7.0] — 2026-10-22 — Mail from your supplier. Reading and managing documents automatically

MINOR release without a schema step (stays 1.8.0): an **inbox for supplier
mail**. File a PDF, have it read — by an AI in your own home network or,
experimental and only with an opt-in, by a cloud service —, check every detail
against its quote and take it over with one click into contracts, meters,
bills and readings. A stack of letters becomes the whole household; in
day-to-day use every letter says what it means and what to do now, mail also
arrives by shortcut, folder or email, and every contract and meter has a file.
Plus: monthly values say whether they are measured or spread between two
readings (GitHub [#37], [#39]), a quality gate for the heating model with a
suggested heating limit ([#38]), an advance payment suggestion that states its
uncertainty, experience levels that follow the household, responsible people
on meters and contracts, the follow-ups to [#33] and [#35], and dialogs for
keyboard and screen readers. API, CSV format 1, backup format 3.0 and the
Docker environment only grow additively.

### ⚠️ For existing installations

- **Costs without bonus** ([#33]): the “Costs” tile shows the costs without
  the bonus, and the bonus has its own line below; only the balance includes
  it. The API (`cost`) stays as it was.
- **Water with advance payments:** water contracts with an advance payment
  plan now have a calendar balance like gas and electricity. Balance and
  expected final balance may differ from 3.6.0.
- **Interpolated months** ([#37], [#39]): months that come from a reading
  interval of more than 45 days no longer count as measured. For meters with
  few readings, expectation, adjusted consumption, anomalies, recommendations,
  forecast and the balance estimate to date can change; monthly values, costs,
  advance payments and the balance up to the last reading stay the same.
  Recommendations only come from the last 24 months.
- **Summer forecast:** in summer the measured seasonal value applies instead
  of the heating curve. Forecast, quantity for comparison portals and the
  annual report’s forecast can change (bug fixed, see “Fixed”).
- **Advance payment as a range:** when the estimate is uncertain, the balance
  card gives a range with the reason instead of an advance payment, and
  `suggested_advance` is `null` — also in the Home Assistant sensor.
- **Superseded contracts:** if a follow-up contract begins while the old one
  is still running, the old one counts advance payments, standing charge and
  balance only up to the day before. Its balance can change considerably.
- **Booking without falling back:** “Book as special payment” no longer takes
  the newest contract when none applies at the end of the period, but the
  contract of the period, or refuses (bug fixed, see “Fixed”).
- **“Check bill”** knows the verdict “no prices” and takes a reading on the
  last day as a boundary reading instead of an estimate; the calculation is
  unchanged, the verdict says “check” less often.
- **Short remainders** ([#33]): a remainder under 14 days between settlement
  date and change of supplier joins the neighbouring period. Boundaries and
  names of billing periods can shift, the totals stay the same.
- **Experience levels:** eleven parts of pages moved one level down because
  they belong to the household rather than to expert knowledge; beginners and
  experienced users see more.
- **Dialogs** open with the focus in the first field instead of on “×”;
  confirmations start on “Cancel”.
- **Climate normal:** so that it covers heating limits from 6 °C, the app
  fetches a stored climate normal from Open-Meteo once more at the next
  weather sync — the same request as on first start.
- **Folder `posteingang/`:** when an admin starts the app (without sign-in,
  that is everyone), it creates the watched folder in the data directory.
  Nothing in it is ever deleted.
- **No new outside connection without your doing:** locally, the service
  entered for text recognition reads; the cloud path stays off until an admin
  switches it on.

### Added

#### Household from documents

- **One draft across all documents:** file your supplier mail and have it
  read — instead of one list per document you get a draft of the whole
  household: meters, contracts with their price chain and advance payments,
  readings, bills with booking, gas conversion and reminders. You only check
  what is doubtful; one click takes over the rest through the same services and
  checks as manual entry, and the app makes a backup first. The draft grows
  with every document read, and taking it over again changes nothing.
- **Meters and supply relationships:** meters come from meter number and
  market location; a new number at the same metering point becomes a meter
  swap, the empty default meter is reused, and a meter with nothing attached
  is not created. Documents belong together by contract or customer number or
  by the same supplier spelt differently (legal form, “Stadtwerke”, a brand of
  a supplier).
- **Contracts from every letter:** start from the contract confirmation, the
  supply start letter or the first bill, end from the cancellation, the final
  bill or the day before the following contract; the advance payment is not a
  required field but an open point. Prices from confirmation and price change
  form the chain, and a bill’s price confirms it (gross against gross) or shows
  up as a finding. A corrected bill replaces the earlier version.
- **Cross-checked across the documents:** closing equal to opening reading
  becomes one reading, gaps and overlaps between contracts stand out,
  documents with the same content count once. “Still missing” names what the
  mail does not provide — such as prices for a section or the final reading of
  the old meter —, with a link to the field.
- **Setup assistant:** three ways at the end — from documents (recommended,
  with the documents that help for your household), by hand, or with sample
  data. “Set up reading” opens the “Reading documents” card as a dialog, in
  the assistant as in the inbox.

#### Mail in day-to-day use

- **Inbox** at every level, in the navigation and as “File supplier mail” in
  the Add sheet: pick a PDF or drag it in, on the iPad from the Files app. The
  same file is recognised by its checksum, the same letter in another file by
  its content after reading (“Probably the same document as …”). Without an AI
  it stays a place to file; “Enter by hand” opens the matching form with the
  document next to it.
- **Preview next to the document:** assignment to utility, meter and contract
  prefilled and changeable, comparison with your data (new, already there,
  conflict, overlap). Only what you tick is taken over, through the same checks
  as manual entry: readings, bill and special payment, new advance payment, new
  contract with prices, bonus, notice periods and numbers, price change with a
  reminder for the special right of termination, cancellation, start of
  supply, meter swap, conversion factor and calorific value for the gas
  conversion ([#35]). Afterwards the document is attached to the record as a
  receipt, the history says “Taken over from a document”, and taking it over
  again changes nothing.
- **What does this mean for you?** One sentence, one figure, one button per
  document. For a price change the extra cost per year from your own forecast
  and the way to the tariff comparison when an offer saves money; a bill is
  checked as you take it over (“matches” or “is … higher than your own
  calculation”, with the reason); a new advance payment is set against the one
  that brings the bill to zero; a cancellation without a follow-up contract
  gets a reminder before the end; a meter reading request becomes a reminder
  for the person responsible and leads straight to entering the reading; a
  meter swap opens the swap dialog prefilled; a payment reminder gets a
  reminder of its own.
- **Many at once:** “Read all” works through the documents one by one,
  resumable and cancellable; afterwards the review list shows only the flagged
  fields.
- **Mail without a browser:** a shortcut “Share → Energietracker” for iPhone
  and iPad with an API key that can only file documents (new scope “inbox
  only”); a watched folder `posteingang/` in the data directory (NAS share,
  Docker volume) — PDF by content only, no symbolic links, files move to
  `übernommen/` or `fehler/`, nothing is ever deleted; email attachments
  through Home Assistant’s IMAP integration (guide). In the Home Assistant app
  the folder cannot be reached; shortcut and email work there.
- **A file per contract and meter:** all documents in one place — taken-over
  mail, bills with PDF, receipts, reading photos —, with key message, filter
  and search. Contract card and bill name their receipts by file name.
- **“Report meter reading” reminders** lead straight to entering that meter’s
  reading.

#### Reading locally or in the cloud (experimental)

- **Local by default:** an AI in your own home network reads, the text
  recognition service (Ollama or OpenAI-compatible), recommended `qwen3.8`
  with about 18 GB of memory. First the type, then that type’s fields, each
  with value, verbatim quote and page. Pages with terms and conditions are not
  sent, with Ollama the app fits the context window to the length of the
  document and asks once more with a larger answer budget if an answer is cut
  off at the window (the same with the OpenAI-compatible service), and the
  result is cached per document, model and schema. The
  home-network rule of text recognition applies, checked on every call; the
  browser reads the text layer (PDF.js, bundled), and it is not stored.
- **What is read:** bill, contract confirmation, price change, cancellation
  and start of supply; meter swap, meter reading request and advance payment
  change with every field checked; payment reminders, withdrawals and anything
  else are recognised and filed.
- **One “Reading documents” card** under Settings → Expert with the switch
  Local | Cloud; the choice applies to the whole inbox. “Local” shows address
  and interface (the same as for meter photos), text model, time limit and
  “Check connection”.
- **In the cloud as an opt-in:** Claude (Anthropic) gets the PDF and returns
  the quote verbatim with its page; OpenAI, Mistral, OpenRouter, Google Gemini
  or your own OpenAI-compatible `https://` address get the text. You pick the
  model from the service’s list. Off until an admin switches it on; before
  every reading the app names the service, its destination and what goes out,
  and the queue asks once per run. All cloud paths are marked experimental
  until there is feedback from real use.
- **What goes out and what does not:** every document you have read in the
  cloud leaves your home network; the chosen service sees its whole content and
  processes it under its own terms. Only the document, the reading instructions
  and the key for signing in go out — no meter readings, contracts, settings or
  other household data. The “Reading documents” card says so in its “Cloud”
  view and in its status, and so do the inbox and the guide.
- **Key and connection:** the API key stays on the server — never in the
  browser, the backup or the log — and belongs to its service. The cloud path
  rejects addresses in the home network and connects only to the checked
  address, without redirects and without a proxy. In the Docker image nginx
  waits up to 660 s while a document is read (cloud time limit up to 600 s),
  310 s otherwise.

#### Accuracy

- **The server checks the quote:** a field is to be checked when its quote is
  missing or not in the document, or the value is not in its own quote. It
  reads German numbers the German way (“12.345” is twelve thousand). Quotes
  from tables count when their parts stand close together in their order (same
  line or at most two below); umlauts that the text layer delivers as two
  characters or in Mac Roman no longer get in the way. A market location ID
  must have its form (11 digits, BDEW check digit).
- **Flags only for real doubt:** a field missing from the document flags only
  when a proposal needs it (core fields per proposal); “expected” is a hint; a
  price that matches the contract is “already there”; choice fields lean on
  other details (meter number at a known meter, units, tariff). Side details
  read with doubt are never taken over silently.
- **Cross-check of the bill:** the server adds up the advance payments paid
  itself, and the result must be the total minus the advance payments. If the
  bill does not add up, every amount involved is flagged, not only the result.
- **Net prices as on the bill:** if the bill shows net prices with a VAT rate,
  the app converts them to gross and compares gross with gross; the contract
  gets the gross price. It reads the rate from the quote, the same text line,
  a tax column (“USt %”) or the one VAT line — read, never guessed, and only
  from quotes that are in the document; with two rates it does not convert. The same net price with a different VAT rate is a
  new price line, not a conflict.
- **Never the wrong contract again:** prefilling by date only uses contracts
  of the same supplier, otherwise the inbox offers “Create the contract from
  this document”; a bill’s new advance payment only goes to a follow-up
  contract of the same supplier. If a new contract starts at the same meter,
  “previous contract ends the day before” is ticked in advance, and so is the
  bill’s old reading when it fits between the known readings.
- **Meter swap on the annual bill:** if two meters stand on the bill, the
  inbox proposes the swap with final and initial reading — ticked in advance
  when numbers, day and readings fit together. Several reading periods of one
  meter become one entry, the readings in between readings of their own; a
  final reading of the new meter never lands on the old device.
- **Estimated meter readings** are recognised by a word (“geschätzt”,
  “hochgerechnet”, “rechnerisch ermittelt”), by a footnote with its
  explanation or by a reading code the bill explains itself (“S = geschätzt”,
  also in a code group or a “reading type” column); the reading is created as estimated and does not count as
  a measurement.
- **Date and title of every document** are read as well; they put the mail in
  order and show a correction as one.

#### Measured or interpolated

- **Every monthly value says whether it is measured** ([#37]): when two
  readings lie far apart, the monthly value is an even spread of a longer
  period. What counts is the length of the interval (up to 45 days is
  measured), not whether a reading fell in the month; estimated readings do
  not measure. The utility view and the analysis say so with the number of
  readings, tables show “≈”, charts hatched bars.
- ⚠️ **Interpolated months teach nothing:** anomaly table, recommendations,
  heating model and heating curve, the forecast’s seasonal profile, trend
  against the previous year, balance estimate, the workshop’s “Same as last
  year” and the year-on-year comparisons in the annual report leave them out.
  With few readings, the heating model works with the intervals between
  readings. Recommendations only come from the last 24 months; the analysis
  still shows every deviation.
- **Annual report:** it still compares annual totals when readings were taken
  at both turns of the year, even with interpolated months in between;
  otherwise a note says why the comparison is missing.
- **Solar with few readings** ([#39]): the overview says how many monthly
  values are interpolated and from how many readings, that self-consumption,
  self-sufficiency, savings and payback are estimates for these months, and
  marks the months in the energy flow.

#### Quality gate and heating limit suggestion

- **Quality gate** ([#38]): if the heating model fits poorly — spread above
  15 % of an average measured month, or base load far from the measured
  summers —, the anomaly table says so instead of staying empty, names the
  limit below which it detects nothing and leads to the heating limit.
- **Heating limit suggestion per meter** ([#38]): the analysis recalculates a
  meter’s measured months with heating limits from 6 to 18 °C and suggests one
  that fits clearly better. It is applied only with a click (from Experienced)
  and only for this meter, and can be taken back; the setting stays the
  default for the others.
- **Climate normal from 6 °C** for low heating limits; outside the grid the
  forecast says so instead of silently calculating at the edge.
- **Annual band and heating share only with a fitting heating model**,
  otherwise the forecast says why it works without a band. Invalid heating
  curves give the reason (too few points, does not fit, cannot be calculated),
  and every adjusted month says what it is based on.

#### Experience levels

- **Levels rethought:** the level follows three sentences — beginners want to
  know what they use, what it costs and whether the advance payment is enough;
  experienced users compare and check; experts see and connect everything.
  Anyone with PV, a battery, a heat pump or a wallbox sees their pages at
  every level.
- **For everyone:** the contract’s notice period; for district heating the
  capacity charge, metering charge and connected load. **From Experienced** (up
  to 3.6.0 Expert): explainers on the overview, seasonal performance factor,
  PV battery and payback, charging record, electric car and SPF in the
  workshop, “Sharing CO₂ costs”, PV system and heat pump electricity meter in
  the meter dialog, the Photovoltaics setting.
- **Suggestion from the household:** PV, a battery, a heat pump or a wallbox
  suggest “Experienced” to beginners, quarter-hour values suggest “Expert” to
  experienced users — at most once per reason.
- **“What can I do at each level?”** in the handbook: every feature with its
  level, ordered by task, with three households to choose from; linked from
  the settings and the assistant, checked against the code by a test.

#### Follow-ups to 3.6.0

- **Flexibility with your own values:** heat-pump share, washing and
  dishwasher cycles and the car’s plug-in time without evcc can be set under
  Settings → Utilities (from Experienced); the page and the report then say
  “your value” instead of “sample value”. Plug-in times from evcc come first.
- **§ 14a Module 3 without VAT:** grid fees can be entered net (“Amounts
  without VAT”); the app grosses them up with its VAT rate.
- ⚠️ **Water with advance payments** ([#34]): water contracts with an advance
  payment plan have a calendar balance like gas and electricity, with an
  advance suggestion and balance path; the unit price is drinking plus waste
  water. The annual report shows balance and advance advice. Without advance
  payments everything stays as before.

#### Billing year and credits

- ⚠️ **Costs without bonus** ([#33]): the “Costs” tile (utility by calendar and
  billing year, overview, annual report) shows the costs without the bonus;
  the bonus has its own line below. The balance includes it, like the bill;
  the monthly average does not — a one-off credit is not a monthly price. The
  API (`cost`) stays after deduction, `bonus_items` is new; the billing-year
  CSV gains “Bonus” and “Costs without bonus” at the end.
- **Settlement date made clear** ([#33]): settings, handbook and API say that
  the settlement date is the first day of the billing period (01-09 means
  1 September to 31 August). Without a recorded bill, the app suggests it from
  the contract start dates.
- **Other credit** ([#35]): a new bonus type with a note, e.g. for a goodwill
  credit; type and note appear in the contract, the annual report and the
  tariff comparison.
- **Gas conversion in the contract** ([#35]): for gas the contract form shows
  today’s conversion (conversion factor × calorific value) with a link to the
  setting and says why it belongs to the grid and not to the contract.

#### Responsible for meters and contracts

- **Responsible:** in the meter and contract form you pick a person of the
  household. Deadlines then go to them: notice deadline, end of contract,
  price guarantee and “Bill expected” on the contract, readings and stock on
  the meter.
- **Deadlines inherit the responsibility:** a reminder without a responsible
  person of its own gets the contract’s, otherwise the meter’s. The chip says
  “via the contract” or “via the meter” — in the reminders, under “To do”, on
  Contracts & payments and in the calendar. A reminder’s own responsible
  person always comes first.
- **Reminder on a meter:** a calibration, meter swap or delivery can belong to
  a meter and so inherits its person.
- **“My tasks” everywhere:** the filter counts inherited responsibility and is
  now on Contracts & payments too. Signed-in people can subscribe to the
  calendar with only what is assigned to them.
- **Deleting a person** also hands meters and contracts to the person
  deleting; without sign-in they stay with “(deleted)”. The dialog gives the
  number beforehand.

#### Quality

- **Dialogs for keyboard and screen readers:** every dialog opens with the
  focus in its first field (on touch screens on the dialog, without popping up
  the keyboard); confirmations start on “Cancel” and are read out together
  with the question, and the title is a heading. The Tab key stays inside the
  dialog, Escape closes it, and the focus returns to the button, even when the
  list is rebuilt after saving. Error messages are tied to their field. An
  automatic check clicks through every page and every dialog, future ones
  included.
- **Faster with lots of data:** with ten years of daily readings the overview,
  billing periods, annual report and forecast workshop answer two to four
  times faster, and the time now only grows linearly with the amount of data —
  with the same numbers.
- **English screenshots with English dates** (dd/mm/yyyy).
- **Release checklist** with the bundled libraries (Chart.js, PDF.js) and the
  visual check in Safari/WebKit (`tools/webkit-check.mjs`).

#### API and data (additive)

- **Routes:** `GET`/`POST /api/inbox`, `GET /api/inbox/review`,
  `POST`/`DELETE /api/inbox/queue`, `GET`/`PATCH`/`DELETE /api/inbox/{id}`,
  `POST /api/inbox/{id}/classify|extract|apply|remind`,
  `GET /api/inbox/draft`, `POST /api/inbox/draft/apply`,
  `POST /api/inbox/folder`, `GET`/`PATCH /api/inbox/cloud`,
  `GET /api/inbox/cloud/models`, `GET /api/inbox/local/check`,
  `GET /api/utility/{u}/dossier`; `POST /api/inbox` also takes JSON with
  `pdf_base64`.
- **Data:** a new pot `inbox.json` (in the backup); on the contract
  `contract_number`, `customer_number`, `attachment_ids`, `assignee_id`; on
  the meter `assignee_id`, `hdd_base_temp`; on reminders `utility`,
  `meter_id`; on bills `factors`; readings with `source: inbox`; API key scope
  `inbox`; settings `inbox_*`, `inbox_cloud_*`, `flex_*`.
- **Responses:** per month `interpolated`, `measured_share`,
  `reading_gap_days`, plus `interpolation` and `anomaly_quality`;
  `bonus_items`; `projection_uncertain`, `price_missing`, `superseded_on` on
  the contract status; in “Check bill” `no_prices`, `boundaries`,
  `price_missing_at`, `factor_mismatches`; `suggested_anchor_source` on the
  billing periods; `period_last_day` in `GET /api/summary`;
  `assignee_source` in the agenda; `?person=` on the calendar; `example_keys`
  in `GET /api/settings`.
- **CSV:** billing years with “Bonus” and “Costs without bonus” at the end,
  the local monthly overview with “Bonus”; format 1 stays unchanged.

### Changed

- **Uncertain means uncertain everywhere:** balance card, summary (also for
  Home Assistant), annual report and workshop give the same reasons. Where the
  estimate is uncertain, only the range is shown — on the card, at “Expected on
  …” under costs & contracts and in the contracts table. If the heating model
  fits poorly, “Colder, or more used?” no longer says “really used less” but
  that it cannot be shown; the balance then estimates with one factor for the
  whole (⚠️ estimated costs and balance of such meters change).
- **Monthly year-on-year comparison:** a month in progress is marked with * and
  not compared with a full one (existed before 3.7.0). Interpolated months with
  an estimated reading as support are no longer called “spread evenly”.
- ⚠️ **Advance payment as a range when it is uncertain:** if the heating model
  fits poorly or its calibration is stuck at a limit, the balance card gives a
  range with the reason instead of an amount; the annual report turns it into
  a note instead of a task, and the forecast workshop shows the range.
  `suggested_advance` is then `null` (`projection_uncertain`), also in the Home
  Assistant sensor.
- ⚠️ **Superseded contracts:** if a follow-up contract begins while the old
  one is still running, its advance payments, standing charge and balance end
  the day before (`superseded_on`); the stored end stays as entered. Up to now
  its advance payments kept counting up to today. Chains without gaps
  calculate as before.
- ⚠️ **“Check bill” gives an answer:** a new verdict “no prices” with a link to
  the contract, also when the quantity differs at the same time; a reading on
  the last day of the period is a boundary reading, not an estimate; a change
  of calorific value that the gas conversion knows as the bill does is no
  longer a reason, otherwise both values are shown. The calculation itself is
  unchanged.
- **Contract without a unit price:** balance card and billing year say “price
  missing”.
- **Sample data:** the showcase household has a made-up annual bill in the
  inbox with a precomputed reading, a draft from documents and a quarterly
  read intermediate meter that shows interpolated months; every household has
  a contract and a meter with a responsible person, the classic house an other
  credit. The public demo shows the inbox without reading.
- **Handbook:** new guides [Inbox](docs/en/anleitungen/posteingang.md) and
  [Household from documents](docs/en/anleitungen/haushalt-aus-dokumenten.md),
  the feature table [What can I do at each level?](docs/en/referenz/stufen.md)
  and, for developers,
  [Measuring with large amounts of data](docs/en/entwicklung/messung-grosse-daten.md).

### Fixed

- **Interpolated months as anomalies** ([#37]): the anomaly table,
  recommendation r1 and the heating model took months that were only spread
  from a long reading interval as measured, and reported deviations that did
  not exist — the same ones again every month.
- **Heating model with a convex heating curve** ([#38]): when the model fitted
  poorly, the anomaly table stayed empty and did not say why.
- **Solar with two readings** ([#39]): the solar overview showed identical
  months and years without a hint that they are interpolated.
- **One-day periods** ([#33]): when the settlement date sat a few days away
  from the contract start (such as one day before it),
  every change left a tiny period behind, and the settings hint read like an
  end date. A remainder under 14 days now joins the neighbouring period; the
  totals stay the same.
- **Bonus note** ([#35]): the contract form silently dropped a bonus’s note on
  saving, and the water form saved every bonus as “new customer”.
- ⚠️ **Book as special payment** took the meter’s newest contract when none
  applied at the end of the period, even if it did not touch the period (since
  v3.1.0, also without the inbox). Now the app books to the contract that
  bills most of the period — e.g. a final bill one day past the contract end —,
  otherwise it refuses and names the period.
- ⚠️ **Summer forecast:** the heating curve also calculated below its
  smallest point; with a convex heating curve and a lot of hot water the
  annual forecast and the quantity for comparison portals were clearly too
  low. Now the measured seasonal value applies in summer, and with a poorly
  fitting heating model no heating curve is used.
- **“Bill expected”** missed a booked refund or additional payment in the
  period; it now counts as the received bill.
- **Level note:** beginners did not see the note “This page belongs to the …
  level” above “Tariff switch”, “Check a bill” and “Tenancy”.
- **Dialogs:** the focus started on “×”; fields in the calendar subscription
  and in the water contract tables had no label; green had too little
  contrast in light mode.
- **“To do” with “My tasks”:** the combined line of due readings disappeared
  completely.

### Known limits (coming in 3.7.x)

- **Solar with very few readings:** the energy flow caps the measured feed-in
  at the evenly spread generation, and the “Solar power” block in the annual
  report compares part years. The solar overview says that the monthly values
  are interpolated; self-consumption and self-sufficiency per month are only
  rough estimates in that case.
- **Balance card and forecast workshop** compute the expected balance over
  different periods and can name different billing dates; when the estimate is
  uncertain, the card shows only the range.
- **Inbox:** a meter swap whose number only stands in the header line above
  the readings is offered but not ticked in advance; reading codes in code
  groups or a “reading type” column are not yet recognised as estimated.

### Migration

None. Schema stays 1.8.0. New in the backup is the pot `inbox.json`; the key
of a cloud service lives in a file of its own and is deliberately left out.
Reading is set to “Local”, the cloud path to “off”.

### Tests

- **Inbox:** `InboxServiceTest`, `InboxEvidenceTest` (quote check),
  `InboxMarksTest` (flags, proposals, taking over), `InboxLocalProviderTest`
  (against a simulated Ollama over HTTP), `InboxGistTest`, `InboxFolderTest`,
  `DossierServiceTest`, `InboxOperationDocsTest`, `BillVerdictTest`; over HTTP
  `InboxEndpointTest`, `InboxChannelsEndpointTest`, `InboxDraftEndpointTest`,
  `LongRequestTimeLimitTest`, `NginxReadTimeoutTest`.
- **Household from documents:** `HouseholdDraftTest`,
  `InterpolationFromDraftTest`, Node suite `inbox-draft.test`.
- **Cloud:** `CloudExtractionTest` against a simulated service (request form,
  quotes, errors, address check, key in no message; no real call),
  `InboxCloudEndpointTest` (key in no response, no backup, no log).
- **Measured or interpolated, quality gate:** `InterpolationTest` (monthly
  values, costs, advance payments and balance bit-identical to recordings made
  with the 3.6.0 code), `MessyHouseholdTest` with an untidy test household
  built on its own physics (`tests/fixtures/messy-household/`),
  `RealDataFollowUpTest`.
- **Follow-ups, [#33], [#35]:** `ShortRestPeriodsTest`, `CostWithoutBonusTest`,
  `WaterCalendarBalanceTest`, `SummaryPeriodLastDayTest`.
- **Responsible, quality:** `ResponsibilityTest`, `ResponsibilityHttpTest`,
  `ReleaseChecklistTest`; new Node suites `inbox.test`, `a11y.test` (clicks
  through every reachable page and every dialog) and `large-data.test` (ten
  years of daily values, linear run time).
- Extended: `BackupSafetyTest`, `BaselineCutoffTest`, `BillExpectedTest`,
  `BillingPeriodServiceTest`, `DemoCoverageTest`, `FlexibilityInsightTest`,
  `FlexibilityTest`, `OutboundHostsTest`, `PersonaPeopleTest`,
  `ReportFlexPageTest`, `ReportModelServiceTest`, `ViewSumsConsistencyTest`,
  `WaterSpecialPaymentsTest`; `levels.test`, `assignee.test`, `chart.test`,
  render, shape and demo tests.
- 1477 test methods (+403), PHPUnit counts 1795 tests. Every new rule has a
  mutation check that breaks it: 937 in 14 packages, all red.

### Lessons learned

- **Sample data built from your own model confirms itself.** [#37], [#38] and
  [#39] were found by a user with real data, not by our tests: the sample
  households are generated from the app’s heating model and read regularly —
  a quality gate can never trip on them. New, therefore: an untidy test
  household built on its own physics (convex heating curve, gaps, meter swap,
  estimated reading), a comparison of the evaluations with real household
  data that runs only locally and puts nothing into the repository, and before
  every release a sceptic’s run that checks every figure shown for its origin
  and plausibility.
- **Hits per field are not yet a benefit.** In the usefulness test with real
  supplier mail the AI read the fields right almost every time, and still
  hardly a usable household came out: contracts were left behind because a
  required field was missing, most flags were false alarms, prefilling hit
  another supplier’s contract, and “Check bill” almost always said “check”.
  Since then the measure is the finished household — the same test with the
  same stored readings, repeated after every round of fixes.
- **An amount without its uncertainty is a claim.** The advance payment
  suggestion gave an exact amount even with a poorly fitting model; the
  comparison with real data showed how far off it can be. Now the app gives a
  range and says what it depends on.

[#33]: https://github.com/Bingerminger/energietracker/issues/33
[#34]: https://github.com/Bingerminger/energietracker/issues/34
[#35]: https://github.com/Bingerminger/energietracker/issues/35
[#37]: https://github.com/Bingerminger/energietracker/issues/37
[#38]: https://github.com/Bingerminger/energietracker/issues/38
[#39]: https://github.com/Bingerminger/energietracker/issues/39

---

## [3.6.0] — 2026-10-20 — What if, and as on the bill

MINOR release without a schema step (stays 1.8.0): the forecast workshop
“What if?”, evaluations by billing year as well as calendar year (GitHub
[#33]) right into the overview, meter groups and “Check bill”, a flexibility
page with a traffic light and euros per appliance, people with a fixed
number, a note about new versions and “What’s new” after an update, and the
image tag `:3`. API, CSV format 1, backup format 3.0 and the Docker
environment only grow additively.

### ⚠️ For existing installations

- **Changed figures** (bugs fixed, see “Fixed”): the balance of the running
  contract starts after the last booked bill instead of at the contract
  start; “Check bill” includes waste water for water; meter groups no longer
  count a submeter twice; the forecast workshop’s final balance includes the
  rest of the current month.
- **Deleting a person:** open reminders now go to the person deleting;
  completed items keep the name with “(deleted)”. Existing people get their
  number on first use.
- **Further changed figures:** the flexibility levers in euros at the
  wholesale price now include VAT; the car only shifts while it is plugged in
  (plug-in times from evcc); billing periods end at a change of supplier; the
  annual report counts heating oil and pellets in its costs; the benchmark
  only uses living area and people once they have been entered.
- **Without sign-in**, the “Users and roles” card now says that people with
  their own login only come with sign-in switched on, and jumps to “Switch
  sign-in on”.
- **Without sign-in** (login off), `GET /api/people` now returns the people
  from the register instead of an empty list. With sign-in, sign-in applies
  as before.
- **“What’s new”:** after the update every device shows “Updated to 3.6.0”
  once, with a link to the release notes — without any request going out.
- **Note about new versions** (`update_check`) stays off on existing
  installations until someone switches it on under Settings → General; setup
  of new installations suggests it.
- **Examples and templates on `:3`:** the Compose example, README, docs and the
  Unraid template now name the tag `:3` (always the newest 3.x, no jump to a
  new major version). Running containers do not change; CasaOS and Umbrel stay
  on a fixed version, as their stores require.

### Added

- **Forecast workshop — “What if?” (F1031):** What does a cold winter cost,
  what does insulation bring, is switching worth it? Sliders for winter,
  saving and tariff, plus room temperature, base load, measures with costs,
  forecast model, electric car, heat pump and budget. The result appears at
  once as a result card with one big number, curves, recommended advance
  payment, balance at the next bill, CO₂ and the ranking “What makes the
  biggest difference?”, plus the best combination within a budget. Example
  scenarios, save and compare, image, print/PDF, CSV; the demo has the
  examples precomputed.
- **Overview card “What if? Try it”**; the forecast is now available from the
  Beginner level.
- **Flexibility guides:** a traffic light for cheap and expensive hours with
  the sun, “Your lever” in euros per appliance, action sentences with time
  windows, best time windows, solar self-use, a result card as an image;
  load shifting against quarter-hour prices, calculated on the server
  (`insights`). The page is there for every level, with an invitation when
  data is missing. In the annual report the page “Flexibility” (`flex=1`),
  in the print view “Attach scenario”.
- **“Your lever” tile on the overview:** the same number as the flexibility
  result card, “up to …” with a dynamic tariff, one action sentence; only
  with measured quarter hours, also for beginners.
- **People with a fixed number:** every person in the household gets a
  running number that is never given out again (register of people
  `people.json`, part of the backup). Only admins delete people; open
  reminders go to the person deleting, and the dialog says beforehand how
  many and to whom. Completed items keep the name with “(deleted)”: in the
  history, on completed reminders, in the history CSV and in the calendar.
  People from a backup or sample household appear under Access marked
  “without sign-in” and can be deleted or turned into a login with the same
  number via “Create login”.
- **Benchmark as a traffic light:** the tile shows household electricity,
  heating and water per person with a traffic light and one clear sentence
  (water turns yellow only from 5 % above the benchmark);
  if a detail is missing, exactly one link leads straight to the field. The
  links to the Stromspiegel and Heizspiegel start pages are gone.
- **Billing year instead of calendar year** ([#33]): a switch “Calendar year ·
  Billing year” on the utility page, overview and analysis; the device
  remembers the choice. Periods come from the recorded supplier bills —
  exactly as on the bill, also irregular like 15 Apr–30 Mar — and before,
  between and after them from the billing anchor date per utility.
  Day-accurate with quantity, cost, advance payments (pro rata per day) and
  balance; part months at the start and end. If the anchor date does not match
  the last bill, the app suggests one. Utilities without a bill check (heating
  oil, pellets, solar) stay on the calendar year.
- **Annual report** with an appendix “Billing years” (option in the dialog; the
  other pages stay calendar year so that totals across utilities add up);
  **CSV export per billing period** (`monthly.csv?period=billing`, tile under
  Settings → Data).
- **Current year explained:** ⓘ next to “Advances up to …”: the calendar
  year counts the standing charge and advance payment of the broken month in
  full, the billing year by the day; whole years are the same.
- **Edit people without sign-in:** under Settings → Access, admins can
  change the name and role of people from a backup or sample household. Open
  reminders show the new name, completed items keep the old one. “Rename”
  also exists for password logins; names are unique across all people.
- **Assumed area made visible:** without an entered area, the efficiency
  class and the tenants’ CO₂ split keep calculating with 100 m² but now say
  so (“Assumed living area 100 m² – please enter it”) and lead straight to the
  field, also in the annual report (`area_assumed`).
- **Billing years all the way through:** the meter groups of a card show the
  same period as the card; “Check bill” suggests the latest completed
  billing period; the balance card says “Calculated from …”. A test keeps
  quantity, cost, advances and balance identical across every view, by
  calendar and by billing year.
- **API additive:** `GET /api/utility/{u}/meters/{id}/billing-periods`, plus
  for meter groups and the whole utility; annual report `billing=1`;
  `GET /api/update-check`; `POST /api/forecast/scenario` (only calculates,
  stores nothing, also with a read-only key); `GET /api/users/{id}/handover`;
  `insights` in `GET /api/flexibility`; annual report `flex=1`;
  `balance_from` on the contract balance; `rating`, `water`, `missing` on
  the benchmark; `no`, `has_account` on people; `ends_at_switch` on the
  billing periods; `assumed_keys` in the settings;
  `GET /api/flexibility?summary=1`, plus `summary`, `vat_pct`,
  `ev_availability` in `insights`; `PATCH /api/users/{id}` also for people
  without sign-in.
- **Note about new versions:** once a day the server checks on GitHub whether
  a newer version exists, and the app shows it with a link to the release
  notes until you close the note for that version. Only when switched on;
  GitHub only sees the server’s address.
- **“What’s new”** after an update, with a line about supporting the project.
- **Image tag `:3`** (and `:3-rootless`) — stays current without jumping to a
  new major version unasked.

### Changed

- ⚠️ **Billing year on a change of supplier:** if another supplier’s
  contract begins in the middle of a period and no final bill is recorded,
  the period ends on the last day with the old supplier and the next one
  starts with the new contract. A renewal or a new tariff with the same
  supplier does not split.
- ⚠️ **Wholesale-price levers include VAT** (rate of the dynamic offer,
  otherwise 19 %); page, report and tile say “incl. VAT”. What stays net says
  so: ct values in the traffic light and time windows and the check (profile
  factor, advantage, battery value) are marked “net, excl. VAT”.
- ⚠️ **The car charges while it is plugged in:** charging windows from the
  evcc plug-in times instead of “whole charging day”; page and report name
  the basis.
- **Benchmark: default values don’t count.** Living area and people only
  benchmark once you have entered them, even the same value as the default
  (100 m², 2 people). Until then the tile invites you to the field. Anyone
  who has already saved the values keeps their traffic light.
- **Docs: the weekly rebuild explained.** Tuesday’s “Docker Rebuild” is not a
  release: same code and tag, a new image with a current base and a new digest.
  Update watchers report it for that reason, and GHCR counts downloads per
  version anew.
- **Print view with a PDF button:** next to “Print / save as PDF”, “Download
  annual report” fetches the PDF file with the same selection. On the iPad,
  “Print” leads to the print dialog, not straight to a file.
- **CO₂ factors collapsed:** under Settings → Utilities the long list starts
  collapsed, the same disclosure as “Detailed forecast”; it opens on an error
  or after saving.
- **Calm forms on the iPad:** selects as tall as inputs, 16 px text also
  with keyboard or trackpad (no zoom on tap), consistent spacing, password
  fields inside forms, across all settings.
- **Sample data for every use case:** every responsibility belongs to a
  person that exists, and in the classic house “Lukas (deleted)” shows the
  history; plus a granny flat with peak/off-peak meters as a meter group
  with a group contract, a retired meter, a Home Assistant alias, an
  estimated reading and an extra item on the water bill. A test requires
  each of the 52 use cases in at least one household.
- **Sample households:** the classic house brings its own made-up reference
  values and shows the benchmark with a full traffic light; the modern house
  has plug-in times from evcc, and “Your lever” shows €91 there, up to €251
  with a dynamic tariff (before €139 and €224).
- **Sample data: billing anchor and bills in step.** Where the demo places the
  billing anchor relative to today, the annual bills move along (final bills
  stay) and the import recalculates their amounts: no in-between period and no
  anchor suggestion in the billing year, “Check bill” still matches. The
  owner-occupied flat now expects its bill for district heating.
- **The licence in plain words** in the README: free to use, change and pass
  on at home; obligations only for whoever offers a modified version to others
  as a web service.
- **Release notes** are now among the app’s allowed GitHub links.
- **Support link** also in the README of the Home Assistant app.
- **Sample data:** the sample household with gas, electricity and water shows a
  back-payment on the water contract ([#34]).

### Fixed

- **Advance payment in the month of a change:** “Check bill” and the billing
  years count the advance payment of a month with two contracts per contract
  by its days; before, a final bill to the 14th got part of the new advance
  payment.
- **Annual report, costs with heating oil and pellets:** the “Costs” tile, its
  trend and “Money” count the cost of the deliveries like the overview; their
  pages state it instead of “no prices are stored”.
- **Check bill, water:** waste water was missing when it runs through its
  own meter or as main meter minus garden meter, and so in every billing
  year too.
- **Balance of the running contract** counted every year since the contract
  started; now from the day after the last booked bill (`balance_from`).
- **Meter groups:** a submeter whose parent meter is in the same group was
  counted twice for consumption, bill check and billing year.
- **Forecast workshop:** the final balance was missing the rest of the
  current month.

### Migration

None. Schema stays 1.8.0; the new setting `update_check` is off.

### Tests

- `BillingPeriodServiceTest` (12), `UpdateCheckServiceTest` (5),
  `ScenarioServiceTest`, `SimplexTest`, `ScenarioEndpointTest`, new Node
  suites `release-notice.test` and `scenario.test`; extended
  `DemoDataAlignerTest`, `DemoServiceTest`, `PersonaUseCasesTest` (three
  reference dates), `setup-wizard.test`,
  `DeployTemplatesTest`, `ReleaseConsistencyTest`, render tests.
- Also new: `PeopleServiceTest`, `PersonDeletionTest`, `PersonaPeopleTest`,
  `ComparisonTrafficLightTest`, `FlexibilityPersonaTest` (recalculates the
  sample household’s figures independently), `ViewSumsConsistencyTest`
  (same totals in every view), `DemoCoverageTest`, `BillingWindowTest`,
  `SupplierSwitchPeriodsTest`, `PersonEditTest`, `ReportFlexPageTest`.
- 1074 test methods (+145, 8 of them edge cases: meter replacement, foreign and invalid bills, a 14-month bill, pre-release tag, broken answer). 345 mutation checks, all red.

### Lessons learned

- **A note inside the app only reaches those who already have it.**
  Installations on old versions learn about new ones only via GitHub, update
  tools or a tag like `:3` — so the note comes early and the tag with it.

[#33]: https://github.com/Bingerminger/energietracker/issues/33
[#34]: https://github.com/Bingerminger/energietracker/issues/34

---

## [3.5.1] — 2026-10-14 — On the iPad

PATCH release: bugs that showed up on the iPad (Safari), plus the first real
test of text recognition with Ollama. No schema step (stays 1.8.0); the API
only grows additively (`month` on fetching wholesale prices).

### Fixed

- **Setup assistant: cards looked selected several times.** After each
  choice the focus jumped to the first card, whose focus ring looked like a
  second selection; on top, Safari kept the hover border after a tap. Now the
  focus stays on the chosen card, hover applies only with a mouse, and the
  focus ring only when using the keyboard.
- **“Check bill”: overlapping fields.** Date and time fields bring their own
  minimum width in Safari and reached into the next column — they now follow
  the form grid throughout the app.
- **Setup assistant: country could not be chosen.** If fetching the country
  list failed when the assistant first opened, it offered only the current
  country; it now loads the list again. In the demo the country of the sample
  household appears as text with an explanation instead of a locked field.
- **Settings → Access:** notes in one font size; the password fields sit in a
  form so Safari recognises it as a password form.
- **Loading wholesale prices from SMARD without feedback:** the Flexibility
  page now loads the year month by month with a progress bar (“March 2025,
  3 of 12”); every button that is working shows a spinning wheel (still with
  “Reduce motion”). API additive:
  `POST /api/market-prices/sync-smard-intervals?year=&month=` (1–12),
  `errors.flexibility.monthInvalid`.

### Changed

- **Home Assistant app easier to find:** an “Add the repository to Home
  Assistant” button in the README (DE/EN) and the handbook, a “Home Assistant
  App” badge, and a note with a link on the Home Assistant card in the app.
- **Text recognition tried with Ollama:** Ollama 0.40.4 with the model
  `glm-ocr` read a test image of a meter reading correctly in about
  4 seconds; the model reports no confidence, and the value is only prefilled
  as always.

### Migration

None. Schema stays 1.8.0.

### Tests

- `IntervalServiceTest` (wholesale prices for one month), `setup-wizard.test`
  (focus on the chosen card, country list loaded again, demo country as
  text), `forms.test` (hover, focus ring, date fields, access, wheel,
  progress, Home Assistant app button).
- 929 test methods (+1). 14 mutation checks, all red.

### Lessons learned

- **A visual check in Chromium alone is not enough.** Sticky hover, date
  fields with their own width and password fields outside a form only show
  up in Safari. UI changes need Safari/WebKit in the check — or acceptance on
  the iPad.

---

## [3.5.0] — 2026-10-14 — Who, when, where to

MINOR release without a schema step (stays 1.8.0): household tasks get
someone responsible, missing bills stand out, and the energy flow can be
played over a day. Plus Home Assistant OS as an app, an image without root
and a setup that asks for language and country first. **F1027** day film,
**F1028** someone responsible for reminders, **F1029** bill expected,
**N1022** setup with language and country, **N1023** iPad, **N1024** image
without root, **N1025** Home Assistant app, **N1026** sample data; plus the
legal state for 2027 and GitHub [#34]. API, CSV format 1, backup format 3.0,
the Docker environment and the Home Assistant ingest only grow additively.

### ⚠️ For existing installations

- **Bill expected:** after the update “Bill expected” appears for billing
  periods that ended within the last 120 days and have no recorded supplier’s
  bill — tick it off or record the bill. The notices also show in
  `summary.agenda` and in the calendar subscription.
- **Certificate-style figure from 1 January 2027:** it follows the procedure
  in force on the day of calculation. From 2027 that is § 82 GModG as amended
  (BGBl. 2026 I no. 226): gas at gross instead of net calorific value (the
  figure rises by about 10 %), hot-water surcharge 16 instead of
  20 kWh/(m²·yr), data over two years instead of 36 months. Class limits
  stay. Until then the overview shows a preview in Germany.
- **Water bills “booked” as a special payment since v3.1.0** count as not
  booked again and can be booked — the booking never arrived in the
  contract. Special payments stored via the API on a heating-oil, pellet or
  feed-in contract no longer count in the balance (the interface never
  offered them there).
- **iPad in portrait:** the sidebar appears only from 900 px width, below
  that the tab bar with “More” as on the phone.
- **Data directory not writable:** the app answers 503 with
  `errors.storage.notWritable` instead of 500; `/api/health` reports
  `data_dir_writable: false`, and the log names UID, group and the `chown`
  needed.

### Added

- **Day film (F1027):** the energy flow plays a day in quarter hours, either a
  specific day or the typical day of a month (average per quarter hour).
  Power in the nodes, totals since midnight in the buttons, the wholesale
  price with a cursor below. Gaps are marked, never 0. Play, pause, a slider
  with the time of day and keyboard control; with “Reduce motion” only the
  slider. Appears only with quarter-hour values of grid import. New routes
  `GET /api/energy-flow/days`, `/api/energy-flow/day?date=`,
  `/api/energy-flow/typical-day?month=`.
- **Someone responsible for reminders (F1028):** a “Responsible” field in the
  reminder dialog (a signed-in person or any name), a name chip in the
  reminders and under “To do”, a line “Responsible: …” in the calendar;
  filter “All · My tasks · Unassigned · names”, without sign-in with “I am …”
  per device. No notifications. API: `assignee`, `assignee_id`,
  `assignee_former` on reminders and agenda events, `GET /api/people`.
- **Bill expected (F1029):** when a billing period ends (anchor date or
  contract end → final bill) without a recorded supplier’s bill, it appears
  under “To do” with a jump to record it; for electricity and gas after six
  weeks “overdue — statutory deadline passed, ask the supplier” (§ 40c(2)
  EnWG), district heating and water without a statutory deadline only
  “expected”. Clears with the recorded bill, can be ticked off; up to 120 days
  after the end of the period. Agenda kind `bill_expected`.
- **The history as CSV** for proof (for example for a landlord or property
  manager): an “As CSV” button under Reminders & tips → Done with the chosen
  filters; `GET /api/activity.csv` (date, action, title, by, reason, note;
  format 1 or “local”, protected against formulas).
- **Setup asks for language and country first (N1022):** step 1 picks
  language and country, preselected from the browser; the language applies at
  once, and below the country you see what the country profile sets. Only
  what changes is saved. In the demo too (language per device). New route
  `POST /api/settings/country`: first start, settings and setup store only
  deviations from the defaults when the country changes.
- **Annual report with a dialog:** year, utilities (partial report), language
  of the PDF and appendix; the device remembers the choice. The print view
  understands the partial report.
- **Image without root (N1024):** additional variant `X.Y.Z-rootless` /
  `latest-rootless` — runs as UID 10001 on port 8080, needs no capabilities,
  same platforms, rebuilt and scanned weekly, started in CI; the standard
  image is unchanged.
- **Home Assistant app (N1025):** with Home Assistant OS, Energietracker runs
  as an app: its own repository
  [energietracker-ha](https://github.com/Bingerminger/energietracker-ha),
  sidebar via Ingress, sign-in with the HA users, data in Home Assistant’s
  backups, direct port off by default. Same image as with Docker, marked
  experimental for now.
- **Sample data (N1026):** all sample households and the showcase show the
  “Done” history with made-up people, reminders with someone responsible,
  recorded supplier bills, a bill that is still pending and follow-up
  contracts without a gap; the house with heat pump and solar has quarter
  hours for generation, feed-in and battery and, when loaded, the last months
  up to yesterday for the day film.
- **Flat electricity rate 2027:** 32 ct/kWh in the German country profile
  (Destatis, first half of 2026: 32.14 ct, rounded down; BMF letter of
  11 Nov 2025, para. 30). If a year’s value is missing, the message says where
  it comes from and when it is fixed.
- **API additive:** `certificate.rules`, `fuel_basis`, `fuel_basis_approx`,
  `dhw_rule`, `months_required`, `months_in_window`, `upcoming` on the
  efficiency figure (`months_36` unchanged); `has_special_payments` in
  `/api/utilities`; `special_payments` on the water contract and in its
  `contract-status`.

### Changed

- **The “Done” history without gaps:** a new period on meters with
  consumption per period clears “Read the meter” like a reading; a changed
  service-charge statement appears as “changed” (without clearing twice).
- **Efficiency classes checked against the law:** A+ to H are the limits of
  Annex 10 and stay unchanged from 2027 (GModG Art. 2 no. 54; Bundestag
  printed paper 21/6278). From 2027 heating oil and pellets count as an
  approximation at net calorific value until an official factor is
  published.
- **CO₂ price docs:** state of 2027 with sources (BEHG draft 21/7869, ETS2
  from 2028 under Regulation (EU) 2026/667, value for the cost split under
  § 4(1) no. 3 CO2KostAufG) and what the app calculates for 2027 without an
  entered price.
- **Guides checked**, each marked with what was tried and what was checked
  against the vendor documentation: text recognition (LM Studio without
  “Require Authentication”), openHAB (return value of the HTTP action),
  Unraid template (new `ET_PROXY_USER_HEADER`, `ET_LOCK_WAIT_S`), evcc and
  text recognition (link-local has not counted as the home network since
  v3.4.0).
- **Actions updated** (Dependabot #29–#32): docker/login-action 4.6.0,
  docker/metadata-action 6.2.0, docker/setup-buildx-action 4.4.1,
  actions/setup-node 7; CI tests on Node 24.

### Fixed

- **Special payments for water contracts** ([#34]): the water contract now has
  the “Special payments” section like gas, electricity and district heating —
  refund and back-payment (with or without effect on the advance) and
  additional advance payment. They count in the balance of the overview and
  of the utility and in the projection to the end of the contract; “with
  effect” sets the advance from its effective date, in the forecast too.
  “Book as special payment” in the bill check now arrives in the water
  contract.
- **Home Assistant OS:** the Supervisor’s `options.json` (readable by root
  only) no longer counts as a damaged file — until now `/api/health` answered
  503 from the second start on, and the watchdog restarted the app.
- **Calendar subscription under Ingress:** gives the address inside Home
  Assistant’s network instead of the Ingress address, with a hint for phones
  and computers.
- **Loading a sample household** reset language and country to German.
- **An English installation** at the “Experienced” or “Beginner” level showed
  German utility names in the sidebar after starting.
- **“Start with my own data”** jumped to “Meter readings” and straight back.

### Migration

No schema step. New fields are optional; older reminders and water contracts
keep working unchanged.

### Tests

- New PHPUnit classes include `EnergyFlowDayTest`, `AssigneeTest`,
  `AssigneeAccessTest`, `BillExpectedTest`, `ActivityAdditionsTest`,
  `WaterSpecialPaymentsTest`, `RootlessImageTest`, `UnwritableDataDirTest`,
  `OtherSystemsGuideTest`, `HomeAssistantAppTest`, `HomeAssistantAppDocsTest`,
  `PersonaUseCasesTest`; extended include `EfficiencyCertificateTest`,
  `EvChargingReportTest`, `DemoDataAlignerTest`, `DeployTemplatesTest`.
- New Node suites: `day-film.test`, `assignee.test`, `setup-wizard.test`,
  `nav-threshold.test`, `report-dialog.test`.
- New CI steps in the Docker job: image without root (permissions, a foreign
  data folder, any UID with group 0) and “as a Home Assistant app”.
- 928 test methods (+115). 300 mutation checks, all red.

### Lessons learned

- **A predicate answers one question.** “Has advances” also decided where
  special payments exist — water dropped out of both, although it has
  advances and back-payments. Now `hasSpecialPayments()` asks only that.
- **A new notice needs a window for existing data.** A full year back would
  have flooded almost every installation with “overdue” after the update;
  120 days show what is still pending.
- **New values need their examples in every package.** A new history action
  and a test “every action in every household” were built in parallel —
  only the merged state showed the contradiction.
- **A test process is not a request.** Over 1000 tests PHPUnit accumulates
  memory; the limit for that now sits in the test configuration, while the
  request itself provably stays below 128 MB.

Details: [Release process §5](docs/en/entwicklung/release-prozess.md).

[#34]: https://github.com/Bingerminger/energietracker/issues/34

---

## [3.4.0] — 2026-10-13 — Clear and secure

MINOR release without a schema step (stays 1.8.0): the app becomes readable,
tidy and more secure for everyone in the household — also without internet.
**N1019** help on the server, **N1020** secure at home, **F1025** a yearly
report for everyone, **F1024** done with history, **F1026** explainers second
edition, **N1021** a tidier interface. API, CSV format 1, backup format 3.0 and
the Home Assistant ingest grow additively; only the import of old v0.9.0
backups is removed (stability class C).

### ⚠️ For existing installations

- **v0.9.0 backups:** the import is gone. If you still have one: install
  v3.3.0, migrate there, then update to 3.4.0.
- **Proxy sign-in:** under Home Assistant ingress only the headers set by the
  Supervisor count; behind another proxy `ET_PROXY_USER_HEADER` names the one
  header that counts. Without it the previous behaviour stays, and the new
  security check under Access recommends setting it.
- **Larger uploads:** requests are limited to 8 MB by default; only backup
  import, receipts and file imports may be larger (up to 256 MB). If you run
  Apache yourself, the docs show the equivalent.
- **Docker:** the image runs on PHP 8.5, PHP inside the container is only
  reachable internally, and the image now contains the docs (about 7 MB more).
- **Example household “Own house with heat pump and solar”:** the home battery
  no longer discharges into the car; grid import, feed-in, battery figures and
  advance payments of the example change.
- **Self-sufficiency with a battery corrected:** battery losses no longer
  count as self-used electricity, grid charging stays grid electricity.
  Without a battery nothing changes; with one the value usually drops by about
  one percentage point (also `summary.pv.autarky_pct_ytd`).
- **Contract status:** “PAST” is now “ENDED”; an expired contract that keeps
  running is called “RENEWED” in both lists.
- **Contract reminders:** a follow-on contract that starts without a gap
  settles the predecessor’s cancellation deadline, end of term, price
  guarantee and price increase by itself. Contracts that keep running or have
  no end no longer have a deadline that moves every day — calendar
  subscriptions and `/api/summary` in Home Assistant show fewer events.
- **Backup:** the new history of done hints travels with the backup,
  including the names of the people as a snapshot.

### Added

- **Help on the server (N1019):** the handbook (German and English) comes from
  your own server in the version you run and opens in the app
  (`#/help/doc/…`) — with table of contents, German/English switch and
  pictures; tables and code scroll on the iPhone, pages you have read work
  offline, also in the public demo. The other interface languages read the
  English version with a note. All seven use cases are short texts in seven
  languages in the help, each jumping to its section of the handbook. A small
  Markdown renderer without dependencies shows the pages; it escapes
  everything and allows only permitted links and pictures. New routes
  `GET /api/docs?path=…` and `GET /api/docs/asset` serve only files from an
  allowlist, with sign-in when it is switched on. Only bug reports, the project
  page and the source code of the version still lead to GitHub.
- **Yearly report for everyone (F1025):** the PDF is new for readers without a
  technical background — “The essentials” with big numbers, three-year lines,
  four traffic lights (always with symbol and word) and the three most
  important tasks; “What paid off”; “What to do”; deadlines and dates; money;
  one page per utility; topic pages for solar and battery, heat pump, electric
  car, cheap hours and rented flat (only with data); a small glossary. The
  monthly tables are available as an appendix “Figures in detail”
  (`?detail=1`).
  - **Recommended actions** with priority, deadline and amount — amounts only
    from your own readings and contract prices, marked as measured,
    extrapolated or worked example; anything outside the report year sits in
    a box “As of today”.
  - **Charts** as PDF vectors without a library: monthly bars with the
    previous year, balance line, efficiency scale, traffic lights, timeline,
    energy flow; colours from the utility colour table, darkened for print.
  - **Accessible:** body text from 10 pt, footer “Page x of y”, document
    title, language and bookmarks in the PDF; the print view shows the same
    as HTML.
  - **Language and partial report by link:** `?lang=` and `?utilities=`; the
    PDF link in the interface uses the device language.
  - New guide [Reading the yearly report](docs/en/anleitungen/jahresbericht-lesen.md)
    with a recipe for sending the report via Home Assistant.
- **Security check (N1020)** under Settings → Access and System: sign-in
  mode, proxy header, host list and a self-test whether the web server blocks
  `data/`. **“Sign out everywhere”** ends a person’s sessions on all devices
  (`POST /api/session/logout-everywhere`).
- **Host list in the interface:** administrators enter under Access which
  names the app is reached by (`allowed_hosts`, in addition to
  `ET_ALLOWED_HOSTS`); the current name must be included, so you cannot lock
  yourself out.
- **New energy flow (F1026):** no crossings — sun on top, grid left, battery
  right, house at the bottom, below it heat pump, wall box and the rest;
  values only inside the circles, rings for origin and destination, bands by
  amount, dots at the same speed with density by amount; year and month
  switchable with an in-place transition; tapping sun, grid, battery or house
  highlights its flows. “Reduce motion” shows the calm final picture; screen
  readers get the flows as a list.
- **Contract at a glance** now shows minimum term, price guarantee and the
  follow-on contract.
- **Paging long lists (N1021):** from 51 rows, 25/50/100 per page, remembered
  per device; filter “Only flagged”. Readings and periods show “N in year · M
  in total” and have the year choice including “All years” in the card.
- **API (additive):** `phase` (`future`, `current`, `renewed`, `past`,
  `shadow`) and `values_on` on the contract responses, `phase` also on
  `contract-status`; `contract-status` additionally `min_term_end`,
  `price_guarantee_until`, `auto_renews`; `pv-summary` per month and year with
  `battery`, `heat_pump_kwh`, `ev_charger_kwh` and `flow`.
- **Support (GitHub #28):** Settings → System leads to Buy Me a Coffee; plus a
  section in the README and the “Sponsor” button on GitHub.
- **Done with history (F1024):** hints can be ticked off — a tick at “To do”
  and at reminders, a small dialog with date (can be backdated), note and
  receipt, for a cancellation “cancelled, does not renew”, undo in the hint.
  New tab **Hints → Done** (from “Experienced”): who did what and when, by
  month, filtered by person, period and area; automatic entries name their
  reason (follow-on contract, reading, delivery, service charge statement;
  Home Assistant and evcc as integrations); undo strikes through instead of
  deleting, notes can be added later. Administrators set the retention
  (`activity_keep_months`, default 60 months) and clear the history.
  Objection and CO₂ refund deadlines as well as price increases can now be
  ticked off and appear under “To do”. When deleting a person, an
  administrator can replace their name in the history with “former person”.
  New guide [Settling hints](docs/en/anleitungen/hinweise-erledigen.md).
- **API (additive, class C):** `POST /api/agenda/done`, `GET`/`DELETE
  /api/activity`, `POST /api/activity/{id}/undo`, `PATCH /api/activity/{id}`;
  agenda events with `occurrence`, `can_mark_done`, `done`, `title_key`;
  `contract-status` with `successor`; `reminders/{id}/done` and `dismiss`
  additionally return `activity_id`.

### Changed

- **Doc links** in help, Home Assistant, setup, people, evcc, flexibility and
  the yearly report open the handbook in the app instead of GitHub.
- **Session** slides: 30 days from the last use instead of from sign-in.
- **Docker:** PHP 8.5 in the image and in the CI matrix (GitHub #27); the
  image contains `docs/` and `CHANGELOG.md`; a hardened Compose example is in
  the docs, and CI starts the image that way; a weekly rebuild of the latest
  release tag with an image scan keeps the base current.
- **PDF writer** measures text with the real widths of the standard fonts; the
  CO₂ letter and the charging record wrap cleanly too.
- **Contracts backwards through time (N1021):** Costs → Contracts and the
  utility’s “Contracts & advances” show one order — Upcoming · Current ·
  Ended (collapsed from three) · Quotes for comparison with a link to the
  tariff switch; the group contract is included, special payments are newest
  first. Cards show the values that apply today (unit price, standing charge,
  advance payment), the counts only from “Expert”. In the contract dialog the
  price rows list the newest first, and “+ Row” adds on top.
- **Check a bill:** one period “up to and including” for checking and
  entering; “Enter the supplier’s bill (optional)” is collapsed below the
  result.
- **One form grid for all dialogs (N1021):** six columns, width by content
  (dates and numbers narrow, text wide, notes full width), two columns on the
  iPhone; label, field and hint of a row line up. Fields that belong together
  sit in a box with a heading and their own surface, also in dark mode. Row
  lists (prices, bonuses, special payments, time windows, items) follow one
  pattern — date | amount | extra | ✕ — labelled only above the first row.
  Controls are 36 px high, 44 px with touch.
- **Contract dialog** in the order of a bill: contract → term and
  cancellation → prices → grid fee § 14a → bonuses → special payments →
  revenues → notes. The **meter dialog** starts with role and recording.
- **Settings:** all cards in a grid, top-aligned, narrow ones side by side,
  wide ones at the end of the page; “Active utilities” is narrow with boxes in
  columns; Home Assistant and “Sign-in & access” are each split into two
  cards.
- **Appearance (light/dark):** the icon shows what applies (☀️/🌙), an “A”
  marks “follow the system”, and every click briefly announces the new state —
  before, the step to “follow the system” looked like a click without effect.
- **Follow-on contract instead of a reminder:** if a follow-on contract starts
  without a gap, the cancellation recommendation, the analysis hints and
  “Cancel by” disappear; the overview shows “Follow-on contract … from …”,
  with a gap the reminder names it. Contracts that keep running or have no end
  read “Can be cancelled any time” in the overview.
- **Calendar:** done items keep their UID, get “✓ ” and no advance alarm;
  `/api/summary` no longer counts done items.
- **Hiding a cancellation recommendation** lasts at least until the deadline
  (“Hide until …”).

### Removed

- **Import of v0.9.0 backups** including the routes `POST /api/migration/v09/*`,
  the settings card and the guide.

### Security

Described by class, not by steps. Found in our own security review; please
keep reporting issues as described in [SECURITY.md](SECURITY.md).

- **Privilege escalation in proxy mode** via self-set user headers closed.
- **Missing role check** on the import of old backups — the feature is gone;
  only administrators may delete snapshots.
- **Docker:** PHP-FPM inside the container is only reachable via 127.0.0.1;
  CI checks this.
- **Lockout after failed attempts** per device and per name instead of for the
  whole household, plus an emergency brake; the sign-in response time no
  longer reveals whether a name exists.
- **Protection against DNS rebinding** via the host list in the interface.
- **Availability:** request bodies are read only after sign-in and only up to
  the size limit (413); calls to outside services no longer hold the write
  lock, and the lock has a timeout (503 with Retry-After).
- **Apache:** the protection of `data/` also works without mod_rewrite.
- **Signing out** clears the cached data completely again (regression from
  v3.1.0).
- **CSV export:** formula protection for every cell except numbers; IDs are
  checked when created and in the backup. Values in the Home Assistant
  template are quoted.
- **Outgoing connections** limited (protocol, redirects, response size);
  link-local addresses no longer count as the home network.
- The container’s host name only goes to the trusted proxy; storage paths are
  checked lexically; catalogue texts may only contain `strong`, `em` and
  `code`.
- **Supply chain:** third-party GitHub Actions pinned by commit hash, explicit
  permissions per workflow, Dependabot for Composer too.

### Fixed

- **Yearly report:** text ran past the right margin; “-0” in percentage
  columns; “annual consumption” above solar and battery; temperature and
  degree days for electricity, water and solar; “€0.00” for sub-meters, heat
  quantity and service charges (now explained, for renters the amount from the
  tenancy prices); recommendations from other years; “no open
  recommendations” despite due dates; colliding column headers in the
  charging record; “advance €0” as a suggestion.
- **Water** is no longer recommended for a tariff switch — also not in the
  app’s recommendations.
- **Check a bill** via Costs → Check a bill showed no consumption list: the
  page only calculated with a period in the address. Now it calculates right
  away on both paths, with the last completed billing period by the utility’s
  billing date; switching utility or meter keeps the period.
- **Readings:** “43 readings” in the header but 10 in the list — the number
  counted all years, the list the selected one. Now both are shown.
- **Balance card** showed the replaced older contract when contracts
  overlapped.
- **Energy flow:** lines crossed with a battery and numbers overlapped;
  sun→house delivered more than was generated; in incomplete years the
  sun→house flow was missing.
- **Contract at a glance:** renewed contracts showed a past end, open-ended
  ones a cancellation deadline of “today”, a missed deadline was still named
  as a deadline, labels overlapped, and “today” was a day off at night.
- **Money and weather explainers:** percentages ran past the edge, legend and
  labels overlapped in some languages.
- **Overlapping and floating fields** in the contract and meter dialogs,
  checkbox texts in capitals, floating ✕ buttons, “+ Row” across the full
  width and the field sitting lower in the meter replacement.
- **“Add an offer”** showed the dynamic tariff’s mark-up and VAT even without a
  dynamic tariff — `hidden` now works everywhere.
- **§ 14a module 3:** “from” and “until” are entered the same way; “6” or
  “1530” becomes 06:00 or 15:30.
- **Water contract** scrolled sideways on the iPhone.
- **Moving cancellation deadline:** contracts that keep running or have no end
  created a new “last day to cancel” every day in agenda, calendar and Home
  Assistant (`summary.contract.cancel_by` = today).
- **Hidden cancellation recommendations** came back before the deadline.
- **Adding a follow-on contract** left all of the predecessor’s reminders in
  place.

### Migration

No schema step. New settings (`allowed_hosts`, `activity_keep_months`) have
defaults; the new environment variables `ET_PROXY_USER_HEADER` and
`ET_LOCK_WAIT_S` are optional.

### Tests

- New PHPUnit classes for docs, security, PDF report, contract phase,
  timeline and history; new Node suites `docs.test`, `cache-names.test`,
  `poisoned-render.test`, `lists.test`, `explainer.test`, `forms.test`.
- The test tools run in parallel on their own ports (`ET_TEST_HOST`,
  `ET_DEMO_PORT`).
- 813 test methods (+118). 265 mutation checks, all red.

### Lessons learned

- **Building in parallel means merging carefully** — search the whole
  repository for conflict markers after every merge.
- **One formula in two places needs a shared check** — self-sufficiency is
  computed by the server and the explainer, both checked against one fixture.
- **A deadline of “today” is no deadline** — a reminder needs a fixed date or
  none.
- **Hiding has to work everywhere** — one global `[hidden]` rule instead of
  seven patches.
- **Trusting headers needs a filter in front.**

In detail: [Release process §5](docs/en/entwicklung/release-prozess.md).
