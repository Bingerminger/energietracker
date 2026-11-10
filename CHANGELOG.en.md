# Changelog (English)

All notable changes, in English from version 3.4.0 on. The German
[CHANGELOG.md](CHANGELOG.md) is the complete record, including every earlier
version. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/).

---

## [3.12.0] — 2026-11-10 — Energietracker for your AI

MINOR release without a schema step (stays 1.8.0). The main feature is the **AI interface** (F1037):
Energietracker speaks the Model Context Protocol (MCP), and an AI assistant in the home network asks about
balance, consumption, contracts and deadlines. Alongside come the **HACS integration 1.0.0** (F1038), a **new
interface** ([#59]) and the fix of the **balance of meter groups** ([#58]). API, CSV format 1, backup format 3.0
and the Docker environment only grow additively; where a status code or an address changes, it is listed under
“For existing installations”.

### Summary

- **Your AI asks Energietracker.** Checked with Open WebUI and a local model via Ollama; Claude Code, VS Code,
  Cursor, LM Studio and cloud models are experimental. Every figure comes from the same calculation as the app and
  names its basis. **Off by default, a key of its own, changes only after you confirm them in the app.**
- **Home Assistant without a single line of YAML:** the HACS integration 1.0.0 with sensors of its own.
- **New interface, nothing is lost:** the menu in Everyday and Setup, “To do” gathers everything open,
  “Connections” and “Household” bundle the setup, settings have a search, the overview answers first. **Old
  addresses and bookmarks lead on.**
- **Letters where you look for them:** on the contract, prefilled with sender, addresses and numbers from
  household and contract. Plus a **setup guide** with six ways to work with Energietracker, and the **forecast
  workshop for every utility**, now also for heating oil, pellets and PV (tank, replacing the heating, battery).
- **Calculated more correctly and hardened, some figures change:** balance of meter groups ([#58]), water by the
  day and to the cent, PV feed-in per billing year, a bonus after the contract end; **every input checked by one
  rule**, in the backup import too. Examples old → new under “Changed”. Files holding access secrets are readable
  by the web server only.

### For existing installations

- **PHP 8.3 is now the minimum version** (was 8.2). This only affects running on your own web server; the
  Docker image and the Home Assistant app bring their own PHP (8.5). PHP 8.2 only receives security updates
  until the end of 2026. If you still run PHP 8.2, switch to 8.3 or newer before updating (Synology Web
  Station: a profile with PHP 8.3 or 8.4).
- **Reload open tabs once after the update.** A tab that stays open across the update does not know the new
  addresses. From the next update on, the app reloads itself once in that case.
- **New interface, old ways lead on** ([#59]): “Reminders & tips” is now “To do”; Integrations, Weather data and
  parts of Expert and Access live under “Connections”; meters, people, building and utilities under “Household”;
  settings are ordered by topic. Bookmarks, calendar and Home Assistant lead on through redirects. **`href`** in
  the agenda, `/api/todo`, notes on rights and the inbox names the new address; if an automation compares `href`,
  adjust the comparison. On the iPhone, Costs & contracts is under “More”.
- **AI interface off by default:** nothing changes after the update until an admin switches it on under
  Connections › AI assistants. If you never want it on a server, set `ET_MCP=off`; then it stays closed even with
  the switch.
- **Keys of their own for the AI:** the scopes `mcp` (AI read) and `mcp_write` (AI read and propose) only count
  at `/api/mcp`, `mcp_write` also at `POST /api/inbox`. Existing keys do not count at the AI interface, and an AI
  key counts nowhere else. Even with `ET_AUTH=off` or in proxy mode, `/api/mcp` only accepts an AI key.
- **Confirm in the app only (by default):** `apply_proposal` writes nothing and answers
  `status: awaiting_confirmation`; you confirm under To do › “AI proposals”. If you switch off “Confirm changes in
  the app only”, `apply_proposal` applies by itself, protected only by your program’s prompt. **Without sign-in
  this only protects against the AI interface:** any program in the home network can operate the app and present
  itself as a browser, including an AI with network or shell access of its own. Only sign-in helps against that;
  the page says so when it is off.
- **App in Home Assistant:** an AI program reaches the interface only through an opened port (Configuration →
  Network), not through Ingress. **HACS integration and YAML templates:** if you already use the templates from
  step 5, keep them or remove them so nothing appears twice.
- **File permissions `0600`:** `auth.json`, the key for reading in the cloud and the secret of the Home Assistant
  notifications now belong to the web server user only; the app converts them on the next request. If you back
  up the data directory from the host as another user, you can no longer read these three files (they are not
  part of the app’s backup anyway). **If the container’s UID changes** (for instance rootless on an existing
  volume), the app answers `503` until the folder belongs to the new UID (`chown -R`); see
  [Docker operation](docs/en/betrieb/docker.md) and [Security](docs/en/betrieb/sicherheit.md) § 10.
- **Stricter input, new error codes:** `400` instead of `500` for a wrong type, `400` for broken JSON and for a
  date outside 1900 to 2100, `404` instead of `200` when deleting an unknown meter or temperature day (list under
  “Changed”). A reading already stored outside the range stays out of the calculation with a notice. **True/false
  values follow one rule everywhere** (yes/no, true/false, 1/0, on/off; `null` and empty mean no, nonsense is
  `400`), **numbers have sensible limits**. **A backup with values that the write routes reject is no longer
  imported**; the message names pot, row and field.
- **Only admins** now change (with sign-in) the default language, country, currency and time zone, the note
  about new versions, the location and the weather sync. Members get `403` and still choose their own language.
- **Some figures change** (examples under “Changed”): balance of meter groups, water, PV feed-in, the started
  month, a bonus after the contract end, CO₂ costs in the year of moving in or out and “Year 2 on” in the switch
  decision. **A balance under €5 is now called “additional payment” or “credit”** instead of “balanced”;
  `verdict` in the API stays as it is.
- **Details for letters** are stored in `settings.json` or on the contract, and so in the backup, as soon as you
  enter them or take them over from the letter dialog; no AI gets them. “Remember the sender on this device” is
  gone.
- **Logs:** the image’s access log only writes the path (`?...` instead of the query), the app log masks keys. If
  you evaluate logs, adjust your pattern.

### Added

#### AI interface (F1037)

- **`POST /api/mcp`** (Apache and Synology: `…/api.php/api/mcp`): MCP over Streamable HTTP, JSON only, without a
  session. Version 2026-07-28 with `server/discover` and the required headers `MCP-Protocol-Version`,
  `Mcp-Method` and `Mcp-Name`, plus the older versions with `initialize` (2025-11-25, 2025-06-18, 2025-03-26).
- **20 tools**, 15 reading (`get_overview`, `list_meters`, `get_deadlines`, `get_contracts`, `get_consumption`,
  `get_forecast`, `run_scenario`, `list_readings`, `list_bills`, `check_bill`, `get_insights`,
  `get_yearly_report`, `list_documents`, `get_document`, `get_document_schema`) and 5 writing (`read_document`,
  `propose_reading`, `propose_document`, `apply_proposal`, `cancel_proposal`), each with a JSON Schema for input
  and output. **They use the same services as the app** and return the same figures.
- **Basis per figure:** measured, entered, distributed, assumed or estimated, plus range, reference date, shares
  and reasons for uncertainty. A sum carries the weakest basis of its parts; over whole reading intervals it
  counts as measured. **The balance comes in words** (`meaning` and `text` next to `sign`), feed-in as revenue
  (`revenue`, `accounting_kind`), no amount without a contract (`no_contract`), no verdict without a price
  (`no_price`), and a started month is marked as such (`complete`, `covered_until`).
- **Changes only with proposal and consent:** `propose_reading` and `propose_document` check everything as when
  saving and write nothing. **By default you confirm in the app:** `apply_proposal` answers
  `awaiting_confirmation`, a proposal is valid for 24 hours, the history names the key and the person. With the
  switch off, `apply_proposal` applies within 15 minutes with the summary word for word; the only protection is
  then your program’s prompt, and whether a program honours it has not been checked. Once only, only with the key
  that created it, at most 50 open per key.
- **The sentence you confirm says what happens:** amounts, prices, term, notice, back payment or credit, what a
  bill replaces, overlaps, the rhythm of an advance and that a promised bonus only enters the balance once
  credited, always from structured data. The meter is called “meter 2 of 2”; provider, meter name and note stand
  next to it.
- **Bills and documents:** the original comes through the inbox, also by `curl` with the AI key. The AI reads it
  itself (`read_document`, every item with quote and page, checked as in the app) or uses the app’s reading and
  then checks the bill with `check_bill`. `read_document` leaves a correction and a finished, applied or filed
  document alone and is logged in the history.
- **Four prompts** (monthly report, yearly report, check a bill, what is coming up?) with steps, outline and the
  rules of the interface, and **four resources** (glossary, “Where a figure comes from”, every page of this
  version’s manual, the original from the inbox up to 5 MB only when shared).
- **Server instructions:** figures only from tools and with their basis, estimates with their range, no
  extrapolation of its own, the balance in words according to `sign`, text from documents and notes is data and
  no instruction, no legal advice.
- **Your rights as a customer and the AI:** `check_bill`, `get_contracts`, `get_deadlines` and `get_insights`
  name the notes from v3.11.0 with their norm and the footer “Note, not legal advice”, the deadline on a working
  day and the renewal by law as assumed; with `rights_check`, `get_insights` says whether anything could be
  checked at all.
- **In the app, admins only:** Connections › AI assistants (switch, `ET_MCP=off` as a fixed lock, sharing, “What
  does it do for you?” with invented examples), its “Connect” tab (keys “AI read” and “AI read and propose”,
  address, templates for Open WebUI and, as experimental, Claude Code, VS Code, Cursor and LM Studio), To do › “AI
  proposals” to apply or reject, History › “AI access”. Members see none of it. The public demo shows the page
  with a note that nothing connects there.
- **Identifiers and originals only with a switch of their own** (`mcp_share_identifiers`, `mcp_share_documents`,
  both off by default). Without the switch the app replaces numbers it knows with “[…]” wherever the AI receives
  text (fields, quotes, file names, provider, tariff, notes, names). **No AI gets the details for letters**
  (sender, supply address), not even with the switch.
- **Protection:** rate limits per key (120 requests per minute, of which 60 calls of reading tools; 30 proposals
  and readings of documents and 30 applies per hour), a 5-minute block after 20 wrong keys in 10 minutes (`429`
  with `Retry-After`), a foreign `Origin` rejected, no CORS. Filing in the inbox with an AI key: 30 per hour, PDFs
  up to 10 MB (up to 14 MB per request as Base64), with a line in the access log and `source: mcp`. Names, notes and quotes only appear cleaned in the
  field `untrusted`. No tools for settings, people, keys, backups or deleting.
- **Docs:** new guide [Connect an AI assistant (MCP)](docs/en/anleitungen/ki-assistent.md) (Open WebUI with
  Ollama step by step, other programs as experimental, “Who confirms a change?”, access from the cloud only
  through a reverse proxy of your own with HTTPS), new explanation page
  [Where a figure comes from](docs/en/verstehen/18-herkunft.md), tools, prompts and resources in the API
  reference, sections in Security, Docker operation and the glossary.

#### HACS integration 1.0.0 (F1038)

- **Repository of its own** `Bingerminger/energietracker-hacs`, installable in HACS as a custom repository; the
  request for the HACS default list has been filed.
- **Sensors without YAML:** “To do” with the next item and the next appointment, per meter the balance with the
  expected and the measured balance, consumption this year, forecast 12 months and days since reading; one device
  per group contract, so a sum counts every contract once.
- **Repair notice** in Home Assistant when Energietracker is older than 3.9.0; a new key after revocation through
  re-authentication; texts in seven languages. Needs Home Assistant 2026.3 or later.
- **Manual:** [Connect Home Assistant](docs/en/anleitungen/home-assistant.md#hacs-integration-v3120) describes
  installation and sensors; step 5 and the “To do” sensor point to it.

#### Setup guide (F1039)

- **Help → Setup guide** shows six equal ways as diagrams: by hand, with supplier mail, with Home Assistant, with
  local AI, with cloud AI (experimental) and all together. Every diagram shows the boundary of your home and
  everything that leaves it; plus who it is for, what you get and need, effort, steps, where your data stays,
  what it cannot do and “In detail” with ports and addresses. Three questions lead to the matching way, none is
  required. Phones get their own narrow version.
- **Setup assistant:** a new step “How do you want to work?” with the same three questions, nothing required and
  nothing saved; at the end, a link to the matching way. Also in the public demo.
- **Manual:** new page [Setup guide](docs/en/einstieg/wegweiser.md) with the same diagrams and texts, generated
  from the same source as the app.

#### Forecast workshop per utility (F1040)

- **Each utility gets its own examples, measures and words:** gas with winter, one degree less and insulation,
  district heating adds a rising price, household electricity base load and an electric car, the heat pump its
  performance factor, the wallbox the electric car, water a low-flow shower head, a cistern and leaks.
- **Suggestions only where they have an effect:** no heating model, no winter and no insulation; no offer, no
  switch; for water a sentence explains why there is no tariff to choose. **Every figure in an example is shown
  as an assumed example,** not a guide value and not a promise of savings.
- **The calculation stays the same, to the cent.** Saved scenarios keep calculating as before; a measure that no
  longer fits the utility stays visible and is marked. `POST /api/forecast/scenario` and `run_scenario` add the
  field `profile`.
- **Heating oil and pellets in the workshop:** winter, one degree less and insulation calculate as for gas, with
  the heating part from the tank log. Plus the **fuel price ± %**, when the **tank runs empty** and from when to
  **refill** (without future deliveries). The base case is the forecast on the page; deliveries have no advance
  payment and no bill.
- **Replacing the heating as a second calculation:** the same heat from a heat pump or a pellet boiler, optionally
  with the payback. **Stated assumptions, not guide values:** efficiency of the old boiler 0.9, seasonal
  performance factor 3 all year round, electricity 30 ct/kWh, pellets 35 ct/kg, efficiency of the pellet boiler
  0.9; each can be changed.
- **Solar in the workshop:** a larger system, a battery, more self-consumption, another feed-in tariff, for the
  whole household. Result: grid import, feed-in, cost, revenue and self-sufficiency, each as a **model value** from
  the measured year. With a year of quarter-hours one value, otherwise a **range** between two stated bounds
  (additional sun entirely into the grid, or each day like the monthly average).
- **Forecast and balance stay unchanged;** a comparison old against new across nine utilities is byte-identical.
  `POST /api/forecast/scenario` and `run_scenario` additionally accept `price_pct`, `replace`, `pv_size_pct`,
  `pv_kwp_now`, `battery_kwh`, `self_use_pct`, `feed_in_ct` and return `calc`, `delivery`, `tank`,
  `replacement`, `pv` and `capabilities.regression`.

#### Letters easier to find, filled from your data

- **“Write a letter” on the contract** (electricity, gas, district heating; water only the request): a request to
  adjust the advance payment and a new template **“Termination at the next possible date”**, without a note too.
  The termination names no legal provision; if the date is only assumed (district heating under § 32
  AVBFernwärmeV, basic supply), it stays a gap and the dialog says why. Special termination, objection and
  complaint still only come from a note; if there is one, the choice leads to it.
- **Letters fill in from your data:** enter the sender and the supply address once under Household › Building ›
  “Details for letters” (optional), the supplier’s address and the customer and contract numbers on the contract.
  The dialog says for each detail where it comes from. **What you change there only applies to this letter;**
  “Use in household” or “Save on contract” stores it on purpose. If something is missing, it says where to enter
  it.
- **Notes on rights with a filter of their own,** “Rights” under To do, and a jump mark per note; without a note,
  the page names the way to the letter on the contract.
- **Showcase and demo:** invented sender, addresses and numbers; the termination letter shows no gaps.

#### API and data (additive)

- **Endpoints:** `POST /api/mcp`; for admins `GET /api/mcp/status`, `GET /api/mcp/journal` (access log without
  arguments and content, 90 days), `GET /api/mcp/proposals`, `DELETE /api/mcp/proposals/{id}` (a rejected
  proposal is never applied) and `POST /api/mcp/proposals/{id}/apply`.
- **Settings:** `mcp_enabled`, `mcp_share_identifiers`, `mcp_share_documents`, `mcp_apply_in_app` (admins only),
  `letter_sender_name`, `letter_sender_address`, `letter_supply_address`; environment variable `ET_MCP` (`off`,
  `0`, `false`, `no` lock it for good); API key scopes `mcp` and `mcp_write`.
- **Fields:** contract `provider_address`; reading `source: mcp`; history `by.via`, `by.confirmed_by`, `changes`;
  contract list `letters`; `POST /api/letters/render` with `utility` and `contract_id` instead of the finding,
  template `termination`, model with `place`, `sources` and `defaults`; `POST /api/inbox/{id}/apply` with
  `activity_id`; `reading-checks` with `skipped`; `/api/utilities` with `glyph` (`icon` stays in API, CSV and Home
  Assistant); `rest_of.billed`; in “Check bill” `gap_reason` and `totals.decreases`; findings on rights with
  `maybe_expired`, `soft_until` and `known_basis`; CO₂ split with `days`; switch decision with `monthly_year2`;
  scenario with `profile`; the notification webhook with `href` for notes on rights.

### Changed

#### Figures that change (examples old → new, invented households)

- **Balance of a meter group ([#58]), wrong in v3.11.0 and earlier too:** if the meters were read at different
  times, days counted twice and a month without one member looked like lower consumption. The app now takes the
  rows up to the earliest reading and then estimates from the months in which all meters are read. Two meters, one
  read a month later: estimated until today back payment €491.45 → €407.60, expected on 31 Dec €655.87 → €586.50,
  appropriate advance €141 → €149. If the readings are more than 62 days apart, a range replaces the single
  suggestion. **Reading all meters of a group on the same day is the most accurate.**
- **Water is calculated by the day like electricity** (so far the contract and price of the first of the month
  applied): July 12.4 m³, €2.10/m³ up to the 14th, €2.60/m³ from the 15th: drinking water €26.04 → €29.44. A
  contract switch on 15 Sep splits quantity, standing charge and advance by days: advance of the old contract in
  September €40.00 → €18.67 (14 of 30 days), the new one counts from the 15th. “Check bill” starts a new row on
  the price date.
- **Water prices the quantity the readings give,** not the month row rounded to 0.1 m³; only the display is
  rounded. Month rows, balance card, contract, billing year, “Check bill” and annual report give **the same cent**
  for the same readings, also with a garden meter. 11.333 m³ at €1.50 + €2.50: €44.20 → €44.22.
- **The forecast prices water by the day like electricity,** and so do the forecast workshop and the AI: a price
  from the 15th of a future month applies from the 15th, a contract switch on the 15th splits quantity, standing
  charge and advance by days. March with 9.3 m³, drinking water €2.10 → €2.80 from the 15th: €43.20 → €46.77.
- **Switch decision by the day:** a price step on the 15th applies from the 15th, in year 1 and year 2; a standing
  charge from the 15th counts pro rata, also for “Switch to offer” in the workshop. Until now the step only took
  effect the following month.
- **PV feed-in per billing year:** the balance card counts from the feed-in cut-off date, no longer since
  commissioning. 150 kWh a month at 8 ct since 04/2024: €360.00 → €108.00 (2026).
- **Balance card in a started month** (the billing year starts mid-month, the last reading is in the same month):
  the quantity counts by the measured days, as in the billing year. Cut-off 20 Oct, reading 26 Oct: 116 → 72 kWh,
  measured balance €17.81 → €4.57.
- **A bonus after the entered end of a contract that runs on:** end 30 June, the contract runs on, bonus €120 on
  15 July: the balance card now counts the €120 like the billing year (so far without it), the June row no longer
  carries the bonus. Forecast and tariff comparison count it on its day as well. A bonus after a contract that
  really ended counts on the contract’s last day, also in the billing year. **If a bonus actually comes with the
  annual bill before it, put its day into the period of that bill.**
- **A contract that ran on without notice until its successor:** its row now bills up to there. End 30 June,
  successor from 1 Sep: back payment €105.00 → credit €91.00, the sum of its billing years.
- **Estimated reading:** “Balance on …” only counts up to the last real reading; an estimated reading belongs to
  “Estimated up to today”. Real reading 1 Oct, estimated 4 Nov: “Balance on 4 Nov” → “Balance on 1 Oct”. The tiles
  still name the day of the last reading. Card and billing year give the same cent for the same state (so far for
  instance −€142.68 against −€142.67).
- **The balance in words follows the sign:** €3.50: “balanced” → “additional payment €3.50” (balance card,
  Contracts & advances); beginner overview under €1: “balanced” → the amount to the cent; exactly 0: “€0.00” →
  “balanced”. **Balances are green or red everywhere,** ranges with each end in its colour. The calculation and
  `verdict` in the API do not change.
- **Tenancy:** a month with a clock change counts all its days: March at a €120 prepayment €116.13 → €120.00, for
  the year €1,436.13 → €1,440.00. The result names an amount from 1 cent (so far from 50 cents); the monthly table
  shows the customer’s view (+ credit, − back payment), so far + stood for back payment.
- **CO₂ cost split with your own flat heating:** in the year of moving in or out the stage limits are shortened
  pro rata (§ 5 (3) sentence 4 CO2KostAufG), as for central heating. Moving in on 1 July, 11.1 kg CO₂/m²: stage 1
  (0 %) → stage 4 (30 %), refund €0.00 → €10.90.
- **Switch decision “Year 2 on”** means months 13 to 24 at the prices that apply then (without bonus). Offer
  20 ct, 34 ct from year two, 6,000 kWh: “Year 2 on” ≈ €1,320 → ≈ €2,160, rank 1 → behind the fixed price, “Pays
  off” – → “up to 600 kWh”. **“Pays off” names the direction:** “from 900 kWh” means cheaper above, “up to
  600 kWh” cheaper only below; until now every value stood under “Pays off from”, even for an offer that is dearer
  at the expected consumption (API, additive: `break_even_direction` `above` | `below`).
- **“Check bill” for gas:** a price change starts its own row as for electricity, and in every row price ×
  quantity = cost: one row 1,220 kWh · 8.0 ct · €103.60 → 620 kWh · 8.0 ct · €49.60 and 600 kWh · 9.0 ct ·
  €54.00. The total stays the same.
- **Remainder billing:** the note names the billed quantity: “238 kWh = 882 − 644” → “488 kWh = 882 − 644 + 250”
  (field `rest_of.billed`). If the period ends one day after the shared reading day, a false “remainder below
  zero” is gone: wallbox 128 → 124 kWh.
- **Metering charge:** exactly 6,000 kWh a year belong to the group up to 6,000 kWh (so far, through a rounding
  remainder, to “over 6,000 kWh”); every limit belongs to the lower group.
- **Fee assessment:** an entered “Received on” after the fourth day moves the notification (§ 41(2) sentence 3
  VwVfG): assessment of 3 Feb, received on 12 Feb: notification 7 Feb → 12 Feb, and the deadline ends later
  accordingly. Counted from the date of the assessment, the app says “possibly expired” for seven days after the
  earliest end (so far “expired” at once); letter and “To do” stay that long. **With “Received on”, the day of
  receipt is the earliest end:** the assessment was posted on that day at the latest, so the notification may lie up
  to four days later; up to the latest end it says “possibly expired” (so far “expired” from the next day, up to
  three days early). A receipt before the fourth day changes nothing.
- **Annual report:** figure, percentage and line of the “Consumption” tile show the same quantity: last point of
  the line 45,648.4 → 45,709.2 kWh, equal to the tile’s figure; a year without an adjusted quantity stays empty
  instead of raw.
- **Price per reading with a comma:** “12,5” ct counted as 12 ct: 100 kWh €12.00 → €12.50. “abc” and negative
  prices are rejected.
- **Living area and people:** “120,5” m² counted as 120 m² and as not entered → 120.5 m² (limits 0 to
  100,000 m² and 0 to 1,000 people; 0 still means “not stated”).
- **With francs or pounds:** “ct/kWh” → “Rp./kWh” or “p/kWh” in the annual report, notes, the inbox and the
  forecast workshop (also in the heat pump note and in `run_scenario`).

#### New interface ([#59])

- **A new design language across the app:** graphics of its own instead of emoji (on meters and in what you type
  yourself, emoji stay possible), one typeface, three radii, one shape for notices in five tones, tabs, filters,
  years and periods as the same buttons, tiles in a fixed grid without a ragged edge, amounts in tables with
  digits of equal width. Annual report, print and PDF without symbol characters.
- **A menu in two areas,** Everyday and Setup, the same at every level. On the iPhone a bar with Overview, To do,
  ＋, Consumption and More.
- **To do gathers everything open:** deadlines and dates, recommendations, rights, mail and AI proposals in one
  list with filters, plus the tabs Dates and History. History is there from Beginner.
- **Connections:** reading mail and photos, Home Assistant (map meters, notifications, templates), weather, evcc,
  calendar, AI assistants, keys and home network security, with the status in the row. Connections above your
  level open with “Show more connections”, without changing the level.
- **Household:** meters (all in one place, from Beginner, sub-meters indented below the main meter, a filter per
  utility), people, building with the details for letters, utilities with the metering concept as the second box.
- **Settings by topic, with search:** For you, Region and language, Display and reminders, Data and backup,
  Access and security, Calculation (so far “Expert”) and System. Search finds every setting, also above your
  level, and jumps to the field without changing your level. If the field sits in a part that is hidden right
  now, for example under “Cloud”, a note on the card says where it is; **“Show” opens that part and saves
  nothing.** “Region and language” says what applies right now
  and what overrides what; the appearance (light, dark, like the system) can also be chosen under “For you”. The
  security check appears once, under Access and security.
- **The overview answers first:** what needs doing, will I get money back, more or less than last year, at every
  level. “To do” appears there as an excerpt of four rows (overdue items, then deadlines from “Your rights”, then
  the rest) with “n more entries under ‘To do’”; the “Enter reading” button is gone, adding goes through ＋.
- **Tiles show more in less space:** the trend as a graphic of its own with sign, value and a visible basis
  (“+4 % vs last year” instead of “▲ 4 %”), new “about the same” below 0.5 % and “no comparison” with its reason
  (so far nothing stood there); in the footer the last reading or the fill level and the number of meters. The
  sparkline stays, 44 instead of 64 px high. **No detail of the old tile is gone.**
- **Insights › Key figures:** efficiency, benchmark, tanks, electricity balance and explainers, so far cards on
  the overview.
- **Consumption per utility in views:** overview with a short balance, balance and contract, months, readings. One
  button per task: “+ Reading” at the top, the second button in the notice is gone.
- **Costs & contracts as rows:** one button per row, “Edit”, “Write a letter”, “History” and “Manage contracts”
  under “More”; the page is about a fifth shorter on the Mac.
- **Past contracts** (new): ended and replaced contracts in one place, with term, prices, advance payment,
  consumed, paid, balance at the end and bill, filtered by utility, meter and year. The amount is the balance at
  the end of the contract, not a price comparison with the successor.
- **Tables stack below 900 px,** nothing scrolls sideways any more; settings on the iPhone with full field width
  and the label above the field. The buttons in the data export carry the colour of their utility again.
- **Old addresses and bookmarks lead on,** also with `?field=` and `?rec=`.
- **Footer `Version · Help · Star · Heart`:** the star leads to the project on GitHub, the heart to supporting it,
  both in a new tab; on the iPhone under “More”. **Logo and name at the top left lead to the overview.** A new logo
  in the hand of our own graphics: a house with a meter ring in the colours of the utilities.
- **Help** starts with the link row Demo, Handbook, Roadmap, Discussions; the setup assistant ends by saying where
  Household and Connections are.

#### Texts, manual and presentation

- **Every image in the manual shows the new interface**, each in German and English: workshop, annual report,
  contracts, dialogs, setup and sign-in. The workshop guide shows heating oil and PV.
- **Texts in all seven languages reworked:** key statement first, shorter sentences, no more dashes as sentence
  glue; key statements in subtitles and in the workshop are bold. French, Italian, Spanish, Portuguese and Dutch
  are proofread, terms aligned with the glossary and the menu. Long words in narrow tiles and fields are
  hyphenated instead of sticking out.
- **Manual on the new navigation:** every path names Household, Connections and the settings topics, and the
  settings reference is ordered that way. Notes appear as info boxes, the pictures show the new interface.
- **README** with a link row, five badges and the table “At a glance”; every number in it is tied to a test. The
  “Tests” badge is gone.
- **Code of conduct, a pull request template and a form for ideas** under Discussions. Reports go only through
  GitHub: “Report content” on the post, anything confidential through the private route from SECURITY.md.

#### Rights, letters and inbox

- **Notes on rights come first within their urgency,** in the API too; “To do” leads a rights entry straight to
  the note, the notification names the page. The guide [Write a letter](docs/en/anleitungen/brief-schreiben.md)
  starts with the three ways to a letter.
- **“Remember the sender on this device” is gone.** A sender remembered that way still prefills until the
  household has one; “Save in household” takes it over.
- **Questions on a new meter reading** (lower, jump, decimal point forgotten, higher than the next one) follow the
  same rule in the form and for the AI; “unusually large increase” instead of “consumption”, so it fits feed-in
  too.
- **CO₂ price 2027 (docs):** status of the BEHG amendment after the Bundestag vote of 15 Oct 2026, not yet
  promulgated; the app changes nothing.
- **README:** “ⓘ next to technical terms” instead of “next to every key figure”.

#### Interfaces and operation

- **Only admins change** (with sign-in) the default language, country, currency and time zone as well as the note
  about new versions, the location and the weather sync. Members see the values locked and still choose their own
  language, also during setup. `PATCH /api/settings` with one of these keys and `POST /api/settings/country`
  answer a member with `403`.
- **`href`** in the agenda, `/api/todo`, notes on rights and the inbox names the new address, for instance
  `#/todo/dates` instead of `#/reminders`, `#/todo?art=rec&rec=…` instead of `#/recommendations?rec=…` and
  `#/household/meters?art=gas` instead of `#/utility/gas/meters`.
- **Error codes:** wrong type (list or object instead of text) → `400` `errors.common.textExpected` instead of
  `500`; broken JSON → `400` `errors.http.bodyInvalid`; ingest batch without a list → `errors.ingest.bodyInvalid`
  instead of `errors.ingest.unknownUtility`; date outside 1900 to 2100 → `400` `errors.common.dateOutOfRange`;
  deleting an unknown meter or temperature day → `404` instead of `200`. A list of entries given as text, a number
  or an object → `400` `errors.common.listExpected`; contract amounts out of range →
  `errors.contract.amountOutOfRange`; a reminder with an unknown recurrence → `errors.common.valueInvalid`; a
  reading import without a recognised line → `errors.import.noRows` instead of `200`; an ingest value that is not a
  number (“31002,5”) → `errors.reading.counterInvalid` instead of `errors.ingest.valueMissing`. See the
  [API reference](docs/en/referenz/api.md).
- **`/api/health`** reports under `checks.restore` an import that ended halfway, with the snapshot from before
  (`degraded`). The CSV import of periods names rows of another meter under `other_meter_rows`.
- **Undo in the history** refuses AI entries without a revert (`409`) instead of crossing them out; the entry
  names what was changed.
- **Setup assistant:** one more step (six, seven with electricity and solar, five in the demo).
- **Backups:** new reasons `concept` (metering concept) and `auto`, `manual` only for your own (`backup_…`).
- **First start:** a health check without a language (the image’s `HEALTHCHECK`) does not set up an empty
  installation; only the first real request decides on language and country.
- **Receipts** come with `private, no-cache` and `ETag` instead of a year from the browser cache; unchanged, the
  server answers `304`.
- `GET /api/docs/asset` also serves the setup guide’s diagrams (SVG); the manual understands `<picture>`.
- The phase per contract in `…/contract-status` is computed by `ContractService::withPhases` (one place for the
  app and the AI); the response is unchanged. TRADEMARKS.md names the programs of the AI interface and HACS.

### Fixed

- **“Prices valid today” on the day:** when a price changed on the 15th, the contract card showed the old energy
  and base price until the end of the month (all utilities, for water also waste water and rainwater). The
  calculation was already day-accurate; only the display took the price of the first of the month.
- **A reading in the year 0001** (or a typo such as 0202) was accepted and brought down the overview, “To do”,
  the agenda and the annual report. Dates now lie between 1900 and 2100 (readings, meters, contracts, reminders,
  deliveries, periods, bills, tenancies), also for a bill’s issue and receipt date, a reminder’s “done on” and in
  the backup import; a reading already stored outside stays out of the calculation with a notice, and so does a
  stored meter reading above 10¹² (so far silently trillions of kWh).
- **Values that brought the app down:** a contract end `[]` or `{}` gave `500` but was stored; `forecast_model: []`,
  a building type as an object or a heating limit of 10³⁰⁸ were stored. Afterwards the overview, “To do”, the agenda
  and the annual report answered `500`. Now `400`, nothing stored: **settings only take the type of their default
  and numbers within sensible limits** (latitude ±90, longitude ±180, heating limit 6 to 22 °C, months and days as
  whole numbers), **prices, standing charges and advances are never negative**, contract amounts up to 10 million,
  deliveries, periods and temperatures with an upper limit.
- **True/false values as text:** “false”, “nein” and “off” became yes in eight places. A running contract with
  `is_shadow: "false"` dropped out of balance and forecast as a shadow contract, a reading became suspect or
  “billing only” (and was then missing from the monthly values), a meter, reminder or tenancy statement with
  “nein” stayed active or booked; the same for switches in the settings (such as the weather sync) and
  “estimated” on a period. **Now one rule applies on every write route,** nonsense is `400`.
- **Whole numbers and silent assumptions:** 10³⁰⁸ as months, days, digits, notice period or hour gave `500` under
  PHP 8.5; a notice period “1.5” silently became 1, “12,5” months stayed 12.5. A bill with “abc” as invoice data,
  items or CO₂ was stored empty, with an unknown contract ID as well; a reminder with an unknown recurrence silently
  became “no repetition”, a given “done on” was dropped on creation; a customer number as a list was silently
  dropped; a reading import with JSON instead of CSV said “0 imported”. Now `400`.
- **Backup import:** it took what the write routes reject (a contract end `[]`, `forecast_model: []`, a reading of
  10³⁰⁸, a date in the year 0001) with `problems: []` and then brought the app down. **Now it reports it as a
  problem and imports nothing.** If the process ends in the middle of an import, the health check reports it with
  the snapshot from before (so far a household of old and new files without a hint).
- **A reading on a foreign meter:** through the API a reading, and a contract too, could be attached to an
  unknown meter or one of another utility and then dropped out of every evaluation.
- **Server error instead of a message:** a list or an object instead of text caused `500` on more than a dozen
  write paths, and so did people, API keys and price lists on a contract (also as text or a number); broken JSON
  counted as an empty request. A response that could not be encoded came as `200` with an empty body, at
  `/api/summary` even cached for five minutes; now as `500` with an error ID.
- **CSV round trip of readings:** multi-line notes were cut off, “Estimated” flipped to no, the guard apostrophe
  before formulas stayed, spaces at the edge of a note were lost. Export and import now give the same readings,
  including the price. **Periods keep “Estimated”** (so far every one became measured), and an import into another
  meter names the skipped rows instead of silently importing nothing.
- **Ingest:** “31002,5” said “value missing”; now the code names the cause (`errors.reading.counterInvalid`).
- **Double booking:** saving in the contract dialog gave special payments new identifiers; a bill correction then
  booked twice.
- **Own backup pushed out:** ten runs of the metering concept assistant deleted a backup you had made yourself;
  several backups within one second pushed out the newest.
- **First start in a container:** a health check before the first browser visit (Docker, Home Assistant) fixed
  the installation to German, Germany and Leipzig, also with `Accept-Language: *` (which every program using
  Node’s `fetch` sends).
- **“Check bill”:** a falling meter reading is called “reading falls” with its own note instead of “without an
  enclosing reading”; the warning “meter swap or rollover not recorded?” appears here too.
- **Inbox:** a credit equal to the result is flagged and no longer goes into the bill unchecked. **A number only
  counts as evidence at number boundaries** (“0 m³” used to sit inside “13.580 m³”, “80,00 €” inside “180,00 €”),
  digits of an identifier are no evidence for a value, locally, in the cloud and through the AI; documents
  already read keep their marks until they are read again. A damaged PDF says “The file is damaged or not a
  readable PDF” and is “Failed” instead of staying “New” with a raw English message. The previous standing charge
  is shown in the same unit as the new one; after applying, the assignment knows new contracts without reloading;
  “net/gross” without a quote carries no tick. **A number right after “customer number”, “contract account” and
  similar labels no longer counts as evidence.**
- **Readings:** an older reading entered afterwards is no longer compared with the later one. A reading above the
  next one asks back (so far it did not); the consumption calculation still passes over it, and `reading-checks`
  now names it under `skipped`. **The “Meter readings” page checks a back-filled reading with the same rule**
  against the previous and the next reading for its date (so far not at all); the question in the “+ Reading”
  dialog names the date in its title instead of “{date}”.
- **Overview:** “n more” counts like “To do” and the sidebar (also recommendations, mail, AI proposals). The answer
  “More or less than last year?” uses the same threshold as the tile (about the same below 0.5 %). If an answer
  from the server is missing, an error line with the error ID appears instead of “Nothing right now” or “No
  contract with advance payments yet”, and “To do” then does not say “Nothing is waiting for you right now”.
  “3 meters are waiting for a reading” names utility and meter (“Gas · Main meter”). Beginner: below €1 the amount
  without “about”, exactly 0 is “balanced”.
- **Forecast › Months in detail:** the balance reads as on the balance card, with word and amount to the cent, in
  green or red; so far €0.38 to pay was “(balanced)” there.
- **Letters:** if the termination date stays open, the dialog says why (deadline missed, contract runs on, no
  notice period entered) and where to enter it; in the showcase the district heating has a notice period. Place
  and source follow the sender that is in the letter.
- The old bookmark `#/settings/household?field=person_name` leads to Household › People (so far Building).
- **Meter replacement from the inbox:** Cancel reopened the dialog straight away, and after × or Escape a reload
  reopened it.
- **Language:** a new default language also switched the display for someone with a language of their own; with
  sign-in but without a choice of one’s own, the selector showed “like the installation” although the device’s
  choice applied.
- **The public demo hid new features:** whoever had visited it before stayed with the remembered household, level
  and language after a release and did not see the new examples. Now the demo starts from zero for everyone after
  every release, and every file carries the build’s mark. New in the demo’s notice bar: “New in vX.Y”.
- **On iPhone and iPad:** settings fields were 52 px wide, buttons on the meter page ran out of the card, the gas
  conversion fields were too narrow on the iPad; buttons in a row had different heights, notices in one box
  different widths; “New date” from the capture sheet lost the dialog’s focus to the page behind it.
- **Accessibility:** coloured table columns reach 4.5:1; after “Cancel” in a confirmation the focus returns to the
  button.
- **A reference date was not passed on in several calculations.** Contract status, billing year, rights checks,
  tenancy budget (`as_of`), forecast, the workshop, the overview and the annual report always built their monthly
  rows with today’s date, even when asked for another day. Now they use **what was known on that day**: readings
  after it do not count. Day to day (reference date today) nothing changes. An annual report for an earlier year
  now finds the unusual months after 24 months too, and the agenda takes “Reorder” from the tank stock of its day.
- **The test suite depended on today’s date:** several tests were set relative to “today” and turned red as the
  calendar moved on (tank empty before the first delivery, new year, 29 February, end of month, clock change). They
  now use fixed dates.
- **A tab left open across an update reloads itself once** when it meets an address it does not know, instead of
  showing “Unknown route” (works from the next update on).
- **“Your lever” tile:** if last year has no quarter-hour data, the overview shows the year before, named in the
  title, instead of no tile; the link opens Flexibility for that year.
- **Forecast workshop:** the “Heating curve (regression model)” field also appeared for water and electricity; it
  now only appears where a heating model applies. Switching the utility after an example resets the example
  instead of carrying its values along (such as the low-flow shower head to heating oil).
- **French** showed “1 …” for 0 meters, groups or days.

### Security

- Hardening: files holding access secrets (`auth.json`, the key for reading in the cloud, the secret of the Home
  Assistant notifications) are now readable only by the web server user (`0600`); existing installations are
  converted on the next request.
- Hardening: receipts no longer stay in the browser cache after signing out; signing out clears it
  (`Clear-Site-Data`), and every receipt is re-checked before it is shown.
- Hardening: text recognition, evcc, Home Assistant notifications and reading in the cloud check entered addresses
  with one shared rule, in every notation.
- Hardening: **the metadata service `100.100.100.200` is blocked on all of these paths,** in every notation, like
  `169.254.169.254` and `fd00:ec2::254` already were. It lies in `100.64.0.0/10`; the rest of that network
  (CGNAT, Tailscale) stays home network.
- Hardening: the app log and the image’s access log contain no access keys.
- Hardening: the development server (`php -S` with `router.php`) answers invalid characters in the address with
  `404`.
- Hardening: with sign-in, only admins change the settings that cause a connection to the outside (note about new
  versions, location, weather sync), with any value, `null` included; `null` on a switch means off.
- Hardening: the AI also gets the title and text of hints and rights findings and the meter name in a proposal with
  the mask for identifiers and letter details.
- Hardening: notifications to Home Assistant carry foreign text (document name, titles of reminders and hints) as
  plain text without links, images, HTML or line breaks, with a maximum length.
- Hardening: only a key with an AI scope clears the failed attempts before the lock of the AI interface.
- Hardening: an API key’s “last used” only counts accepted requests.
- Hardening: the image also sets `X-Content-Type-Options: nosniff` for static files.

### Known limits

- **Checked only with Open WebUI and Ollama:** the templates for Claude Code, VS Code, Cursor and LM Studio follow
  their descriptions and have not been tried with every program; whether a program asks before applying has not
  been checked. That is why you confirm in the app by default.
- **No OAuth, no SSE:** AI services that connect from their provider’s cloud only work if they allow a header of
  their own with a key, and only through a reverse proxy of your own with HTTPS (a guide, no code). Home
  Assistant’s “Model Context Protocol” integration (SSE and OAuth only) does not connect.
- **Texts for the AI** (descriptions, prompts, messages) exist in German and English, in English for other
  languages of the installation. `read_document` checks quotes against the text the AI supplies itself; the
  protection against that is your confirmation.
- **Identifiers:** provider and tariff do not count as identifiers and go to the AI; the app does not recognise a
  short number without a label that appears nowhere in the household, and what the AI reads from a shared
  original itself, the app cannot hold back. The mask does not recognise details for letters written differently
  (“Str.”).
- **Evidence check:** an ordinary space between digits can separate thousands or two table columns; both readings
  apply there (“580 m³” in “35 580 m³”). Non-breaking spaces count as thousands separators. A meter number
  standing on its own can still be read as a reading.
- **Inbox:** list fields cannot yet be confirmed or corrected one by one (planned for v3.13). “Correct” on a price
  without a tax rate takes it as a gross price; the calculation behind it is unchanged.
- **Meter group:** what a member read later has measured after the earliest reading only enters the range; with
  uneven consumption the point value is an estimate. If the earliest reading and a contract switch fall into the
  same month, the app splits the monthly row by the share of the quantity.
- **Contract switch within a month:** the last advance counts by days; if the balance is close to zero, its sign
  can flip because of this. If the supplier debited the full month, enter the advance with dates or book the
  special payment. **To be clarified with v3.13.**
- **A weather-based forecast for heat pump, PV and battery** comes with v3.13; in the workshop the heat pump’s
  electricity therefore has no examples for winter and room temperature yet, and the seasonal performance factor
  for replacing the heating is the same all year round.
- **Workshop:** solar calculates with the last measured year, not with the weather; without a year of quarter-hours
  only as a range. Deliveries in a meter group have neither a heating part nor a tank. The workshop uses the
  weather forecast of the daily sync; if you open it before the sync, the figure can be slightly different
  afterwards.
- **Reference date:** meters with consumption per period do not know a reference date yet; a running period counts.
- **Input:** free text (notes, names) has no length limit yet (planned for v3.13). A backup of an older version with values that are
  rejected today imports only after correcting them. An import that ended halfway is not rolled back by the app
  itself; the health check names the snapshot from before.
- **Interface:** there is no one-time “What moved where?” hint for existing users; finding the new places is
  measured in paths and clicks, not checked with people. A real iPhone and iPad have not been checked (WebKit in
  the test browser at 390, 820 and 1180 px). Members still see fields under Connections that only admins may
  change; the server rejects the change. “Check bill” is there from Experienced; beginners find it through search.
  In French, Italian, Spanish, Portuguese and Dutch the heating limit still has different names in different
  places (one name planned for v3.13).
- **Operation:** `Clear-Site-Data` only works over HTTPS or on `localhost`. nginx’s error log can name the query
  when the upstream fails. For a fee assessment the app still does not know regional public holidays.
- **HACS:** according to HACS, inclusion in the default list takes months; until then the custom repository is
  the way. The public demo has no AI interface (it runs without a server).
- **Check of the public demo** (`tests/demo.test.mjs`): it turns red from mid-January, because the showcase has no
  measured part yet at the start of its billing year, and green again from early February. The check is affected,
  not the demo. **Known bug, fixed with v3.13.**
- **Interface tests against the sample data** (browser render, views): they use the real clock and tip over from
  the end of December 2026 as the sample data ages. The tests are affected, not the app. **Fixed with v3.13**
  through sample data that keeps up.

**v3.13.0 is planned for Tuesday, 17 November 2026.** Focus: the forecast for heat pump, PV, battery and electric
car, consistent terms in the translations and length limits for free text.

### Migration

None. Schema stays 1.8.0, the inbox reading schema stays 7. The new settings and the contract field
`provider_address` are optional and travel in the backup, no new bucket. New files only appear on use under
`data/mcp/` (proposals, access log, rate-limit counters) and are not part of the backup. The app sets the `0600`
permissions on the first request after the update; for rootless Docker with a changed UID see above.

### Tests

- **AI interface:** `McpServerTest`, `McpEndpointTest`, `McpSchemaTest`, `McpProtocolTest`, `McpRateLimiterTest`,
  `McpJournalTest`, `McpSecurityTest` (secrets in no answer, across every tool), `McpReadToolsTest`,
  `McpProvenanceTest`, `McpConsistencyTest` (the same figure as the app in an invented, a messy and the sample
  household), `McpRightsFieldsTest`, `McpWriteTest`, `McpDocumentToolsTest`, `McpApplyInAppTest`,
  `McpEvidenceBoundaryTest`, `McpLetterDataTest`, `McpPromptsTest`, `McpResourcesTest`, `McpDocsTest`,
  `McpValueDocsTest`, `McpIntegrationTest`; Node suites `mcp-protocol` against `php -S` and `mcp-snippet`
  (templates against the formats of the programs and the guide).
- **Calculation, recalculated by hand:** `GroupReadingsDifferTest` ([#58]), `WaterByTheDayTest`,
  `FeedInBillingYearTest`, `BalanceStartMidMonthTest`, `BonusAfterTheEnteredEndTest`, `BonusAfterEndTwoViewsTest`,
  `SwitchBalanceByHandTest`, `TenancyBudgetCalendarDaysTest`, `Co2PartialYearTest`, `TariffSwitchYearTwoTest`,
  `GasBillPriceChangeTest`, `SubmeterRestNoteTest`, `ReportConsumptionLineTest`.
- **Input and round trips:** `ReadingInputTest`, `InputErrorsEndpointTest` (year 0001 in every view),
  `InputHelpersTest`, `HouseholdNumbersTest`, `CsvReadingsRoundtripTest`, `SpecialPaymentIdsTest`,
  `SnapshotRotationTest`, `FirstStartHealthCheckTest`, `InputDocsTest`.
- **Hardening:** `SecretFilesTest`, `SecretFilesOnStartTest`, `AttachmentCacheTest`, `IpAddressTest`,
  `MetadataAddressTest` (every path, entered directly and behind a name), `NullBytePathTest`,
  `NginxLogAndHeadersTest`, `OutboundSettingsAccessTest`, `ApiKeyLastUsedTest`, Node suite `outbound-admin`;
  `CodeOfConductTest` (reporting only through GitHub).
- **Strict input everywhere:** `SettingsTypesTest` (every number setting with a limit), `ContractInputStrictTest`,
  `RemainingInputsStrictTest`, `WriteRoutesStrictInputTest`, `BoolSettingsAndPeriodsTest`,
  `BackupImportChecksTest` (19 poisoned backups, our own export stays clean).
- **Water and switching by the day:** `WaterExactQuantityTest` (card = billing year to the cent),
  `WaterForecastByDayTest`, `TariffSwitchByDayTest`, `TariffSwitchBreakEvenDirectionTest`.
- **Workshop:** `ScenarioProfileTest`, `ScenarioDeliveryTest` (tank empty, replacing the heating, price, calculated
  by hand), `ScenarioPvTest` (range and quarter-hours), `BillingWindowTest`; a comparison old against new keeps
  forecast and balance byte-identical.
- **Reference date:** `StichtagReadingsTest`; the whole round runs locally with the clock moved (+3 to +365 days,
  29 February, New Year’s Eve, clock change) with libfaketime.
- **Rights, letters, inbox:** `LetterContractTest`, `LetterDataTest`, `LetterShowcaseTest`,
  `McpLetterDocumentsTest`, `McpFindingMaskTest`, `HomeAssistantNotifyForeignTextTest`, `RightsBillChecksTest`
  (receipt, soft zone, limits of the metering charge), `InboxEvidenceBoundaryTest`, `InboxBillRightsTest`,
  `BillServiceTest`.
- **Interface and texts:** new Node suites `box-raster` and `sichtung-layout` (grid and sizes in a real browser),
  `design-rules`, `icons`, `rich`, `redirects` (every old address, in the docs too), `saldo-farbe`, `zaehlerbaum`,
  `sprache-wahl`, `zutun-verbindungen`, `haushalt-einstellungen`, `settings-search`, `sichten`, `catalog-keys`
  (every fixed key in the code is in all seven catalogues), `laengen` (long languages on the main routes),
  `setup-guide` with `build-setup-guide --check`, `demo-start`, `router` (reload after an update);
  `CatalogStyleTest::testNoDashAsConnector`, `ShellFooterTest`, `ReadmeClaimsTest` (every number in the README tied
  to a test).
- 2,665 test methods (+577). Every new rule has a counter-probe that breaks it: 1,416 in 33 files, all red; the 419
  from v3.11 stay red.

### Lessons learned

- **An AI does not see a greyed-out row.** What the interface says with colour, “≈” and the customer’s view needs
  a field for an AI: basis per figure, range and the sign as a word.
- **A request is not a confirmation.** The server cannot know whether an AI program asks before writing; checking
  the summary only proves that the AI can copy it. That is why by default a person confirms in the app, and
  without sign-in the app says that even this only protects against the AI interface.
- **Evidence in a text has to sit at number boundaries.** “Contains” is no check for numbers: an invented zero
  sits inside almost every meter reading.
- **The same quantity in two views needs a test that compares both.** Card and billing year, tile and annual
  report, monthly row and balance each calculated correctly on their own and still disagreed. For the balance of
  a meter group two errors partly cancelled each other out; only the calculation by hand with meters read at
  different times showed both.
- **What the user interface shapes, the API does not check by itself.** A list instead of text, the year 0001,
  “12,5”: the form never lets it through, the API accepted it. Now one place checks every write path, and the
  backup import only takes what the write paths would take.
- **A reference date is a state, and a test suite has to pass on every day.** A test turned red without a code
  change because it was set relative to today; with the clock moved forward some twenty more turned up, and one
  cause in the code: the monthly calculation knew no reference date although its callers had one. Pushing a date
  further out is no fix.
- **A demo that remembers choices hides new features** from returning visitors; after every release it starts
  from zero. And a feature that only hangs on a finding does not exist for anyone without one: it needs a way
  without an occasion.

[#58]: https://github.com/Bingerminger/energietracker/issues/58
[#59]: https://github.com/Bingerminger/energietracker/issues/59

---

**Marketing line:** Ask your AI how your power and gas are doing: it looks it up in Energietracker, tells you where
every figure comes from, and changes nothing without you. In Home Assistant it now works through HACS, without a
single line of YAML, and the new interface shows first what needs doing.

---

## [3.11.0] — 2026-11-04 — Your rights as a customer

MINOR release without a schema step (stays 1.8.0). The main feature is **Your
rights as a customer**: the Energietracker checks bills, advance payments and
price changes for what you are entitled to in Germany. A bill came too late, an
advance payment is far above your consumption, a price increase lets you
terminate, a bonus is missing, the bill estimates despite your own reading, the
metering charge is above the statutory cap, a fee assessment has a deadline.
Every note names the provision with source and date, is a **note, not legal
advice**, and offers, where it fits, a **letter** from a fixed template as a PDF
for a window envelope or as text. All legal statements sit in one place in the
code and in the [legal basis](docs/en/referenz/rechtsgrundlagen.md); a test
keeps both equal. The app also detects **contradicting contracts** ([#54]),
corrects the **legal references of existing notes** ([#55]) and knows a
**responsible person on the tenancy**. API, CSV format 1, backup format 3.0 and
the Docker environment only grow additively.

### ⚠️ For existing installations

- **Germany only, notes only:** the checks run only with the country Germany in
  the settings. They say what the law provides; they never say that something
  is void or that you should withhold payments. Letters are only created at the
  push of a button; the app sends nothing.
- **New notes from old data:** some checks also see bills and contracts entered
  in the past. Only notes whose occasion is at most 120 days ago or whose
  deadline is still running go to “To do”; “Bill check” shows all.
- **Special termination replaces the price increase** ([#55]): instead of the
  recommendation “Price increase, check special termination” on the day of the
  increase there is a “Rights” note with the date on the day before (the
  termination must arrive by then) and the letter. The calendar entry on the day
  of the increase stays, without “check special termination”. If you already
  ticked off an upcoming price increase, it appears once more.
- **District heating without a special right of termination** ([#55]): § 41
  Abs. 5 EnWG applies to electricity and gas, not to district heating. For
  district heating the app now names the price adjustment clause (§ 24 Abs. 4
  AVBFernwärmeV). Basic supply is justified with § 5 Abs. 3 StromGVV/GasGVV, a
  pure change in VAT gives no right to terminate (§ 41 Abs. 6 EnWG), and an
  agreed price step without the date of a letter only gets a note that asks.
- **Term warning by utility** ([#55]): “Minimum term over 24 months” only for
  electricity and gas; for district heating only above ten years (§ 32
  AVBFernwärmeV), none for water.
- **Contracts contradict each other** ([#54]): if a contract runs more than 31
  days into a later one according to its entry, both cards, “To do” and the
  billing year show a note. The calculation stays as before (the later start
  wins); “Set the end of … to …” makes the entry fit without changing any
  figures.
- **Inbox extraction schema 6 → 7:** a document read before keeps its
  extraction; basic supply, credits, metering charge, assessment, invoice number
  and the advance payment in a price letter arrive only with the next re-read.
- **New optional fields** on bill, contract, price, meter and reading (see “API
  and data”). Without them the notes are worded more cautiously or stay silent.

### Added

#### Your rights as a customer (F1035)

- **Seven checks:** bill came late (electricity, gas: six weeks after the end of
  the period, § 40c Abs. 2 EnWG), advance payment clearly too high (electricity,
  gas, district heating: more than 20 % and 10 € above the assessment from the
  last billed consumption, the forecast agrees), price change with special
  termination and a date (electricity, gas), bonus not credited (electricity,
  gas), estimate despite your own reading (all metered utilities with bills),
  metering charge above the cap of the Metering Point Operation Act
  (electricity, from 2026-01-01), deadline for a fee assessment (water, sewage).
  A contract that runs on after its end without cancellation is checked like a
  running one. The estimate counts from 2 % of the bill's quantity and 10 € effect;
  after the objection deadline the app says so and no longer offers a letter.
  If the deadline ends on a weekend or a nationwide public holiday, the next
  working day counts (§ 57 Abs. 2 VwGO with § 222 Abs. 2 ZPO, § 31 Abs. 3
  VwVfG); date, “To do”, calendar and letter name this day.
- **One note, many places:** “Rights” recommendation with the footer “Note, not
  legal advice. Basis: …” (every norm as a link, date checked), “Draft a letter”
  and “What the app checks”; “To do” (can be ticked off; with a deadline as a date
  14 days before), calendar with an advance reminder, Home Assistant as an item
  of “To do”, annual report (dates), activity. “Bill check” shows the notes on
  the bill and counts them in the list. Without a recorded bill, “Bill check” and
  the recommendations page say that the checks start with the first bill.
- **New handbook pages:** [Your rights as a customer](docs/en/verstehen/17-rechte.md)
  (what the app checks, how, limits, thresholds, examples) and
  [Legal basis](docs/en/referenz/rechtsgrundlagen.md) (the review report: every
  statement with norm, source, date and rating W/S/G/U; what is “to be checked”
  or “uncertain” never appears as a fact, and uncertain rules never in a note).

#### Letters (F1036)

- **Five templates:** special termination, request to adjust the advance
  payment, question about an estimate, complaint about a bill, objection to a
  fee assessment. Always in German, prefilled from note, contract and meter;
  missing details stay as a visible gap `[…]`.
- **PDF following DIN 5008** (address in the window, fold marks) or **copy as
  text**; “Mark as sent” ticks the note off with date and note. The objection is
  offered only after you confirm that the instructions on legal remedies name it.
  The app does not store sender or recipient (the browser can remember the
  sender if you wish). Guide: [Writing a letter](docs/en/anleitungen/brief-schreiben.md).

#### Price change letters in the inbox

- Adopted prices carry the date of the letter, the VAT rate and a “cancel by …”;
  there is only one special termination date. The key statement checks the
  notice period, recognises a pure tax change, shows extra costs with a range
  and the advance payment afterwards, and points to the rule for advance payments
  if the new advance rises clearly more than the costs. Dual tariff: the price
  goes to the matching register.
- Bills take over bill date, invoice number, estimated readings (the estimated
  final reading is stored on the bill, not as a reading, and does not change
  consumption; a reading that would make the meter run backwards is reported),
  credits,
  metering charge and assessment; if the bill names basic supply or a special
  contract, the app proposes the type of supply on the contract.

#### Contracts contradict each other ([#54])

- A note for an overlap, a nested contract, the tariff of another utility (also
  with a changed price) and advance payments of at least twice the costs, one
  entry for the same contract; buttons “Set the end of … to …”
  and “Both are right”. Contract list, contract status and billing year read one
  shared rule; the billing year labels assumed sections.

#### Responsible on the tenancy

- The deadlines of the service-charge statement and the CO₂ refund go to the
  person on the tenancy, otherwise to the first heat meter (“via the tenancy” in
  reminders, “To do” and calendar). “Delete person” counts tenancies too.

#### Showcase and demo

- The sample household always shows a special termination with a running
  deadline, a fee assessment with objection and a contradicting water contract;
  the Pages demo precomputes the first draft of each letter.

#### API and data (additive)

- `GET /api/rights` (findings with norms, footer, letter, window),
  `POST /api/letters/render` (text or PDF, stores nothing); agenda kinds `rights`
  and `contract_conflict`, recommendation category `recht`, recommendation
  `r_contract_conflict`, inbox proposal `contract_supply_kind`.
- Fields: bill `received_on`, `invoice_number`, `reading_estimated`, water
  `notice`, items `kind: metering` with `basis` and `vat_pct`; contract
  `supply_kind`; every price entry `announced_on`, `vat_pct`,
  `special_termination_until`; electricity meter `metering_device`,
  `msb_basic`, feed-in meter `pv_kw`; reading `reported_on`; tenancy
  `assignee_id`; bill `estimated_end_reading`. Contract status `price_increase`
  and `coverage`, billing year `periods[].assumed`, `advance_vs_cost` (with
  `contract_id`), `successor.gap_covered`, warnings `term_over_10_years`,
  `contract_conflict`, `estimated_reading_backwards`; `GET /api/rights` names
  `bills_recorded`; an objection after the deadline ends with
  `errors.letter.deadlinePassed` (422).

### Changed

- “According to the bill” shows kind, net or gross and VAT rate per item and the
  new fields; the electricity/gas contract dialog the type of supply and, from
  “Experienced”, “Letter dated” and “VAT %” per price; the electricity meter
  dialog the metering section; capture the switch “reported to the supplier”.
- Help → Documentation links “Your rights as a customer”; the glossary explains
  basic supply and special contract, metering operator and price cap, fee
  assessment and the energy arbitration board.
- A future follow-on contract of a later billing period carries
  `balance_measured: null` in the contract status.
- Docs: Docker Compose example for your own root certificate `ET_CA_FILE`
  ([#36]).
- The contract card names an announced price step with its date (“from …:
  energy price …”) instead of only the number of prices.

### Fixed

- District heating, basic supply, price steps and pure tax changes got a wrong
  note on the special right of termination under § 41 Abs. 5 EnWG; the term
  warning came for every utility ([#55]).
- Bills from the inbox had no bill date; booking used the day after the period.
- “According to the bill”: editing and saving turned every other item into kind
  “other”.
- Reminders from the inbox (special termination, report a reading) carried
  “{date}” instead of the day in the title since v3.7.0.
- The glossary named the special right of termination without distinguishing
  type of supply and kind of energy.
- **Bonus counted twice** ([#56]): when the billing year started in the middle of
  a month, a bonus from the last day of the old period was counted again in the
  new period's balance up to the last reading, its expected balance and so in the
  suggested advance. Now the day of the credit counts; the bonus column in
  “Contracts & advance payments” shows the same bonus as the balance instead of a
  share of the month.
- A tacitly renewed contract was called “can be cancelled at any time with at
  most one month’s notice”, with a calculated earliest end and “at the last
  prices”. Electricity and gas: the notice period is in the contract (the review
  report proves no general one-month rule); basic supply two weeks (§ 20
  StromGVV and GasGVV); district heating under § 32 AVBFernwärmeV (renewal by
  five years, notice nine months before).
- District heating without an entered notice period: shortly before the end the
  app suggested a switch although the notice period under § 32 AVBFernwärmeV had
  already passed. Now it reminds you of the day nine months before and afterwards
  says that the contract is extended, until when and when the next cancellation
  is possible; an entered follow-up contract takes precedence. “Check switch” no
  longer shows a past “runs until …” for an extended contract.
- Price entries after the end of a renewed contract did not apply; balance,
  forecast and contract card used the last price before.
- A replaced contract was labelled “Ended” next to a future end (now
  “Replaced”); a gap filled by the automatic renewal was called “without a
  contract”.

### Known limits

- **No person has reviewed the report yet.** The sources were read on
  2026-11-03; rows marked “To be checked” (among them the wording of the GasGVV,
  § 309 Nr. 9 BGB, exclusion of the objection procedure) and “Uncertain” (new
  version of the AVBFernwärmeV, EnWG amendment 2026, a determination by the
  Federal Network Agency on the price caps) are open, plus the one-year period
  without instructions on legal remedies counted from notification (§ 58 Abs. 2
  VwGO), the “nine months before expiry” calculation for district heating and the
  list of nationwide public holidays.
- German federal law only, no state laws. The app moves a date only for the end
  of the fee assessment deadline, and only for weekends and nationwide public
  holidays; it does not know state holidays.
- Advance payment: not for water and group contracts. Estimate: needs the
  estimated final reading from the bill. District heating during a renewal:
  the app names the next notice day, but it is not yet under “To do” or in the
  calendar. Metering charge: only from 2026-01-01, not for
  net amounts without a VAT rate. Bonus: no instant bonus.
- The app knows neither the supplier’s address nor the supply address (field in
  the dialog); the letter PDF has not yet been checked in a real window
  envelope. The Pages demo only precomputes the first draft of a letter.
- Household from documents still takes over prices from price changes without
  the date of the letter and does not take over the type of supply.

### Migration

None. The schema stays 1.8.0; all new fields are optional and travel in the
backup, no new pot. The inbox extraction schema rises from 6 to 7 (see above).

### Tests

- **Rights:** `RightsTest`, `RightsServiceTest`, `RightsFieldsTest`,
  `RightsEndpointTest`, `RightsBillChecksTest`, `RightsAdvanceTest`,
  `RightsPriceChangeTest`, `RightsLettersTest`, `InboxPriceChangeTest`,
  `InboxBillRightsTest`, Node suite `rights`.
- **Letters:** `LetterServiceTest`, `LetterLayoutTest` (box protocol in the
  window envelope), `LetterEndpointTest`, `PdfCharsetTest`, Node suite `letters`.
- **Order:** `ContractConflictTest` (M6 rebuilt, calculated by hand, coverage day
  by day), `TenancyResponsibilityTest`, Node suite `assignee`.
- **Docs:** `RightsSourcesDocTest` keeps the review report tied to the code row by
  row (norm, source, date, status), plus deadlines, price caps, thresholds and
  examples, allows only norms from the report on the explanation page and none
  that is uncertain as a fact; `DocumentedThresholdsTest` for the thresholds in
  the guides. Demo: `DemoDataAlignerTest` (deadlines relative to today),
  `demo.test` (note and letter in the Pages demo).
- **After the skeptic run:** `RightsRenewedContractTest` (renewed contracts),
  `BonusPeriodBoundaryTest` (#56, period boundary in the middle of a month),
  `ShowcaseAdvanceHighTest`; after the follow-up check `ContractNoticeFollowUpTest`
  (district heating before the end, follow-up contract, basic supply) and the
  deadline on a working day (Saturday, Sunday, Easter Monday, 3 October).
- 2088 test methods (+192). Every new rule has a counter-check that breaks it:
  430 in 10 packages, all red.

### Lessons learned

- **A legal statement needs a row before it becomes text.** The review report
  stood first in the concept, then as rules in the code, then as a table in the
  docs; a test keeps all three equal. Four notes from earlier releases named a
  provision that does not apply to the case.
- **Two packages, one date.** Inbox and check both created the special
  termination date; now the inbox writes the date onto the price, and only the
  check creates the date.
- **An example in the concept is not a finding in the code.** The rebuilt case M6
  is a nested contract, not an overlap; only the household calculated by hand
  showed this.
- **The renewed contract is the normal case.** Every check looked for the
  contract that is “active on the day”; a contract after its end without
  cancellation was invisible to them, and in real data every contract has an end.
  The skeptic run found this, not the tests: the test data had no renewed
  contracts.
- **An anchor has to suit the app’s renderer.** `<a id>` with an underscore shows
  up as text in the handbook; the anchors are now called `rights-bill-late` etc.

[#54]: https://github.com/Bingerminger/energietracker/issues/54
[#55]: https://github.com/Bingerminger/energietracker/issues/55
[#56]: https://github.com/Bingerminger/energietracker/issues/56
[#36]: https://github.com/Bingerminger/energietracker/issues/36

---

## [3.10.1] — 2026-11-03 — Security fixes in the image

PATCH release without a change to the app. The Docker image of 3.10.0 contained curl/libcurl 8.22.0 and
brotli 1.2.0 although Alpine already offered fixed versions; the scan after the release reported critical
and high findings with an available fix. According to [SECURITY.md](SECURITY.md) such a finding gets a new
image within 7 days and the Home Assistant app a new patch version.

### ⚠️ For existing installations

- **Docker:** pull the new image (`:3`, `:3.10`, `:latest` or `:3.10.1`); Home Assistant shows the app update.
- **Web server without Docker** (Apache, nginx, Synology Web Station): not affected, nothing to do.

### Fixed

- **Image:** the build now takes all Alpine security fixes (`apk upgrade`); this brings curl/libcurl 8.23.0
  and brotli 1.2.1. The PHP base image lagged behind these fixes.
- **Code scanning:** two `js/incomplete-sanitization` findings in a test helper.

### Changed

- **CI:** the Docker job checks that no Alpine package can be upgraded in the finished image.
- **Scan after the release:** results are reported for `main`, so Security shows the state of the current
  release and no longer that of the last weekly rebuild.

### Migration

None. Schema stays 1.8.0.

### Known limits

- busybox and tar have medium findings without an available fix; they no longer appear in the scan (only
  findings with a fix) and are taken over once Alpine fixes them.

### Tests

- 1896 test methods (+1): `ImageSupplyChainTest` holds `apk upgrade`, the check in CI and reporting the scan
  for `main`.

---

## [3.10.0] — 2026-11-03 — Promised and secured

MINOR release without a schema step (stays 1.8.0). 3.10.0 delivers what was
promised in [#36], [#38], [#40], [#41] and [#49] (from discussion [#42]). The
main feature is the **balance in one piece**: the balance card keeps apart what
is measured up to the last reading, what is estimated up to today and what to
expect at settlement, with an honest range for electricity and water too; if one
bill settles two contracts of the same supplier, every view shows the sum.
Energietracker now also reaches Home Assistant over **HTTPS with self-issued
certificates**, and the “To do” sensor names the next appointment. In **billing
years** the overview shows trend arrows, and switching no longer jumps to the
top. In the **cascade** ([#40]) the app bills the remainder per register with
the matching blended price and shows the solar power per branch. The **Docker
image** no longer needs supervisord or Python, is signed and is scanned after
every release. API, CSV format 1, backup format 3.0 and the Docker environment
only grow additively.

### ⚠️ For existing installations

- **Image without supervisord:** a small start script runs nginx and php-fpm.
  If one of the two services fails, the container ends with an error code
  instead of restarting the service inside. It comes back through the restart
  policy: `--restart unless-stopped` with `docker run` (all examples and the
  Unraid template now carry it), `restart: unless-stopped` in Compose (the
  bundled templates already had it), and in the Home Assistant app through the
  **watchdog**, which you should keep switched on. `docker stop` ends at once
  with exit code 0.
- **“Balance on ‹date›” instead of “Current balance”** ([#49]): the balance card
  now only counts up to the last reading and names its day; the figure up to
  today is a line below it and works out what came in since the reading. With
  rare readings the measured balance can have the opposite sign to the figure up
  to today. The “Balance” sensor in Home Assistant stays the figure up to today.
- **One bill across two contracts:** if the contract changes within the running
  billing period at the same supplier, card, Costs & contracts, overview,
  workshop and annual report expect the sum of both contracts, and the advance
  suggestion uses it; if one part is uncertain, there is only a range.
  `projected_end_balance` stays per contract, the sum is in `settle_balance` and
  for Home Assistant in `projected_end_balance_total`.
- **Wider estimate band with rare readings:** the width now comes only from
  measured months and is at least 10 % of the estimated quantity per month.
  Whoever reads once a year sees a clearly wider range than before, and
  electricity and water now have one too.
- **Successor contract from the next cut-off date:** it belongs to no upcoming
  bill. The contract list shows “–” instead of “≈”, `projected_end_balance` and
  `verdict` are `null` there, and its settlement day is the end of its own first
  period.
- **Cascade with HT/NT** ([#40]): the blended HT/NT price (forecast, balance
  estimate, advance suggestion, tariff comparison, workshop) follows the
  remainder per register instead of the measurement; the amounts change
  slightly. With a feed-in register on Z2, energy flow, seasonal performance
  factor and “electricity by role” include the heat pump’s own solar power.
- **Trend arrows:** in billing years they compare the same span of the previous
  period or the same calendar days a year earlier. More than double or less than
  half of the comparison period shows “Check the reading” instead of a
  percentage, in calendar and billing years alike.
- **Analysis baseline date:** the app only suggests “permanently more or less
  consumption” once at least two readings follow the change; a single reading
  with a wrong digit is no longer enough.
- **“Statistical anomaly” recommendation:** a parent meter is checked with its
  own consumption without its sub-meters. A wallbox spike only shows at the
  wallbox, no longer at the house meter.
- **Forecast workshop:** the baseline estimates each month like the balance card
  (with the calibration factor) and is called “Like the balance card
  (calibrated)”; `months[].base_qty` and `base_cost` therefore differ from the
  forecast where the factor is not 1.
- **Errors from third-party services:** if a cloud service refuses or does not
  answer while reading a document, only its reason is shown, without an error
  ID and without “Internal error”; it is logged as `warning` instead of `error`.
  If you filter the log for `error`, you no longer see these cases there.
- **Home Assistant:** the template’s balance sensor carries new attributes
  (`projected_end_balance`, `projected_end_balance_total`,
  `projected_end_range_total`, `measured_balance`, `measured_until`); its value
  stays the same. A template copied earlier gets them with the lines
  `json_attributes_path` and `json_attributes`. The test message reports a
  certificate error with its kind (`tlsIssuer`, `tlsName`, …) instead of
  `network`.
- **Removed:** `scripts/init_data.py` (the old Excel import from v1.0.0, marked
  deprecated since v2.14.0) and `requirements.txt`. The CSV import in the app
  does everything the script did.
- **Signature:** images from 3.10.0 on are signed with cosign; older tags stay
  unsigned. Verifying needs cosign 3 or newer.

### Added

#### Balance in one piece ([#49], discussion [#42])

- **Measured and estimated kept apart:** above the balance card it says
  “Measured from ‹start› up to the last reading on ‹date›”, the columns read
  “Consumed up to ‹date›”, “Advances up to ‹date›” and “Balance on ‹date›”. The
  line “Estimated up to today: …” works out what came in since the reading
  (estimated consumption, standing charge, against advances and special
  payments). Without a measured day the column reads “Balance today
  (estimated)”.
- **Tile and card end on the same day:** the tile reads “Advances ‹start› to
  ‹date›”; with a contract starting on 1 January and no special payments both
  show the same balance. If the card’s balance only starts within the calendar
  year (cut-off date, contract change), the tile says “Balance: see the balance
  card, it counts from …” instead of a second balance.
- **One bill, several contracts:** if the same bill also settles an earlier
  contract of the same supplier, the card names it with its period and balance
  (with “≈” if it is mostly estimated, as a range if it is uncertain), and counts
  measured and up to today across both. Costs & contracts, the beginner
  overview, “Where does my money go?”, the sentence above “Forecast in detail”,
  the heat-limit hint, the workshop, the annual report and Home Assistant show
  the same sum.
- **A range everywhere:** electricity, water and heating types without a
  trustworthy heating model have an estimate width too (“Depending on the
  estimate …”, with too few measured months “Too few measured months, …”). It
  appears on the balance card, the beginner overview, “Where does my money go?”,
  Costs & contracts, in the annual report and its “At a glance”.
- **Workshop like the card:** the forecast workshop’s baseline has the same
  monthly costs as the card’s balance path. Below “Forecast in detail” a
  sentence says by how much the calibration factor shifts the card’s quantities
  (from 2 %).
- **In the showcase and the demo** gas is read twelve days before today, so the
  measured balance and the estimate up to today are both visible; the last step
  of the first-run setup explains the three balances in one sentence.

#### Home Assistant and home network ([#36])

- **HTTPS with your own root certificate:** Home Assistant, text recognition,
  reading in the home network and evcc accept certificates you issued yourself:
  as a file in the container (`ET_CA_FILE`) or under Settings → Expert →
  “Connections in the home network”. The root certificate applies in addition
  to the usual ones, checking stays on. As a fallback, “Do not check
  certificates in the home network”: off by default, with a warning, only for
  addresses in the home network. The system diagnostics name your own root
  certificates.
- **One message per certificate error:** issuer unknown, certificate does not
  match the address, expired, or root certificates not readable, each with the
  matching advice; decided by the error code, not the wording.
- **“To do” sensor shows what is coming:** `/api/todo` also returns `soon` (due
  soon, with the app’s lead time) and `next_upcoming` (the next appointment with
  its date, even if it is not due yet). The state stays the number of due items;
  the template has two new sensors.
- **Expected at settlement in Home Assistant:** the balance sensor carries the
  sum of the bill (`projected_end_balance_total`), the range when the card only
  shows that (`projected_end_range_total`), and the measured balance with its
  day (`measured_balance`, `measured_until`).

#### Billing years on the overview ([#36])

- **Trend arrows in billing years:** the tiles “Consumption” and “Cost (billing
  year so far)” compare from the start of the period up to the last reading
  with the same days of the previous period. If the previous period gives no
  fair comparison (for example after a change of supplier in the middle of the
  year), the arrow compares the same calendar days a year earlier. The tooltip
  names both periods; below 14 days there is no arrow.
- **“Check the reading” instead of ▲ 1,382 %:** if it is more than double or
  less than half of the comparison period, the tile shows a link to the utility
  instead of a percentage, in calendar and billing years and in the beginner
  overview.

#### Cascade ([#40])

- **Feed-in in the cascade:** if the meters behind the grid connection also
  count backwards (2.8.0), the feed-in register of Z2 gets “Feed-in in the
  cascade → feed-in register of” under solar feed-in. It does not count as
  feed-in to the grid. From the differences the app calculates how much solar
  power the household (Z3 − Z2) and the heat pump (Z2 − Z1) use themselves; the
  card “Solar power per branch” under solar generation shows it, with “≈” for
  months from spread-out readings. The heat pump table shows “≈” on electricity
  and no performance factor for such months.
- **“Metering concept” assistant:** in a cascade with solar it creates the
  feed-in register of Z2, gives solar generation (Z3) the code 2.8.0 and draws
  the system behind Z2.

#### Inbox

- **Corrected bill recognised:** extraction reads the invoice number
  (`invoice_number`) and a reference to an earlier bill (`corrects_invoice`);
  extraction schema 6.

#### Image, security and contributing

- **Signed images:** every published image (release and weekly rebuild,
  standard and rootless) is signed with keyless cosign; the SBOM and provenance
  stay attached. How to verify: “Docker operation” and SECURITY.md.
- **Vulnerability scan right after every release.** SECURITY.md names
  deadlines: acknowledgement of a report within 7 days; a critical or high
  vulnerability with a fix gets a new image within 7 days, and the Home
  Assistant app a new patch version so that it fetches it.
- **`AGENTS.md`** for contributors working with AI agents; CONTRIBUTING explains
  ideas (Discussions → Ideas), board, milestones and status labels.
- **Saying thanks:** when the app has just helped (“Check a bill” finds a
  deviation, an appointment is done before its due date, the annual report is
  printed or downloaded), a quiet line with a link to support the project
  appears at most once a quarter per device. No dialog, never in the demo, can
  be switched off (`support_hint`). CREDITS.md has a “Supporters” section.

#### API and data (additive)

- **No new route.**
- **Data:** on solar feed-in meters `cascade_meter_id`; settings `home_ca_pem`,
  `home_tls_insecure` (both admins only) and `support_hint`; environment
  variable `ET_CA_FILE`. No new store in the backup.
- **Responses:** in the contract status `balance_measured`, `settle_balance`,
  `settle_verdict`, `settle_range`, `settle_parts`, `settle_measured`,
  `settle_today`, `settle_uncertain`, `projection_band` also without a heating
  model (with `few_measured`); `/api/summary` per contract
  `projected_end_balance_total`, `projected_end_range_total`,
  `measured_balance`, `measured_until`, `projection_band`, `balance_measured`,
  `settle_balance`, `settle_range`; `periods[].prev_same_span` (with `basis`) in
  the billing periods; `/api/todo` `soon`, `next_upcoming`; `/api/diagnostics`
  `home_tls`; `rest_of.registers` on groups; `/api/pv-summary` `cascade`; heat
  pump months with `estimated`; efficiency `strom.grid_only`; recommendation R4
  `evidence.without_submeters`; `monthly_total[].cost_measured` in the
  utility’s total; in the inbox `replaces`, `correction`,
  `bill_book.data.rebook`, `detail.same_on` and the extraction with
  `schema_version` 6.

### Changed

- **Remainder per register** ([#40]): the app has billed per register since
  v3.9.0 (HT minus HT, NT minus NT, a register below zero does not count against
  the other). New is that the remainder note names each register (“450 kWh, per
  register HT 250 kWh = 400 kWh − 150 kWh · NT 200 kWh = 300 kWh − 100 kWh”,
  `rest_of.registers`) and that the group’s blended price is weighted with the
  remainder per register.
- **Efficiency in the cascade:** with a feed-in register on Z2 the efficiency
  figure still counts the heat pump only with electricity from the grid and
  says so on the tile; seasonal performance factor and energy flow include the
  solar power used directly.
- **Advance suggestion shortly before the bill** ([#38]): the rule stays and is
  now described for amounts due and credits, with “exactly three” payments and
  the installments per year according to the payment plan, including a worked
  example.
- **Switching without a jump** ([#36]): calendar and billing year and the level
  redraw the page in place, without “Loading …”; reading position, focus and
  charts stay, the analysis keeps utility and meter.
- **Image:** without supervisord, Python and setuptools; the scan only reports
  findings that have a fix; `docker/login-action` 4.7.0 ([#50]); PHPUnit stays
  on 11 (PHPUnit 12 needs PHP 8.3).
- **Manual:** balance in one piece in the basics, feed-in in the cascade in use
  case H, HTTPS with your own certificate in the Home Assistant guide, corrected
  bills and opening readings in the inbox, signature and start script under
  Docker operation; new and renewed pictures in both languages.

### Fixed

- **Empty response after a timeout under PHP 8.5:** if PHP set the status line
  itself after a fatal error, the error handler turned a warning into an
  exception while answering, and the response stayed empty. The call dates from
  v2.6.0 and became visible with PHP 8.5, that is in the Docker image. Now an
  error message with an error ID comes back in that case too.
- **A service’s refusal shown as “Internal error”** ([#41]): if a cloud service
  refuses or does not answer, only its reason is shown.
- **Newer OpenAI models when reading documents** ([#41]): if the service refuses
  `temperature` or `max_tokens` and names the parameter, the app asks once more
  without the temperature or with `max_completion_tokens`. Services that take
  both get the same request as before.
- **Expected at settlement with two contracts:** if the contract changed within
  the running billing period at the same supplier, “Expected at settlement” only
  counted the current contract; a bill across two contracts now shows their sum.
- **Heat pump energy flow in the cascade** ([#40], since v3.9.0): it showed the
  whole measurement of the front meter including the household, now its own
  consumption.
- **Balance card with a credit** ([#38]): shortly before the bill it advised
  “no need to raise” the advance even with a credit; now “no need to lower”.
- **“After that … would be right” from an ending contract:** if the contract
  ends at the bill and a successor is entered, the card no longer names an
  ongoing advance from the old prices.
- **Successor contract from the next cut-off date:** it settled on the day
  before it started, with an expected balance of about one day of cost.
- **Opening reading of a bill on the day before:** if the same reading is yours
  up to three days before the bill’s date, the inbox treats it as “already
  there”; until now a second reading with zero consumption was created.
- **Corrected bill in the running inbox:** it was only recognised by its title
  and did not replace a bill that was already booked. Now it replaces the
  recorded bill of the same meter and period including its booking, ticked in
  advance and marked “replaces”; recognised by reference, invoice number, issue
  date or title; a version that is demonstrably older replaces nothing. The
  summary says what changes in the result.
- **Anomaly at the parent meter:** a sub-meter’s spike also showed at the parent
  meter that measures it too. Months in which a sub-meter is not fully measured
  do not count there, and the text names the deducted sub-meters.
- **Labels in billing years** ([#36]): the overview tiles said “last 12
  months” but counted from the start of the billing year.
- **Analysis:** switching between calendar and billing year started again with
  the first utility.
- **One day apart:** the tile in billing years names the day of the reading like
  the card and the overview.
- **A billing year without a name** was labelled twice (“… 31/08/2026–29/07/2027:
  31/08/2026 to 29/07/2027”).
- **Monthly average of the “Cost” tile** counts a started month by its days and
  is left out below four weeks (until now for example “avg €37.40/month” from four
  days).
- **Electricity balance overview** charged the full standing charge in the
  started month.
- **“Created on”** of the result cards in the workshop, scenario print and
  flexibility showed the day before shortly after midnight (UTC instead of local
  time).
- **Code scanning:** three findings (incomplete escaping) in test helpers.
- **TRADEMARKS.md** said Energietracker does not connect to OpenAI; since cloud
  reading (v3.7.0, opt-in) that was wrong, and the providers were missing.

### Known limits

- The balance sensor’s attributes have been checked against the JSONPath
  evaluation Home Assistant uses, but not yet in a running Home Assistant.
  Without a current contract Home Assistant logs a warning on every update; the
  state is correct.
- `ET_CA_FILE` is tested against a real TLS server, in the finished image only
  with the acceptance of this release. The Home Assistant app does not offer `ET_CA_FILE` as an option;
  the setting in the app is enough there.
- With a TLS library other than OpenSSL the app reports a name mismatch as a
  general certificate error.
- In the cascade the efficiency figure counts only grid electricity for the heat
  pump; seasonal performance factor and energy flow include the solar power. The
  app calculates one cascade point (Z2); several meters behind Z1 with their own
  feed-in and the inverter’s own consumption at Z3 are not calculated
  separately. Across a gap in the readings the seasonal performance factor is
  exact; months without measured solar power show no performance factor of their
  own.
- “Forecast in detail” shows the forecast without the calibration factor, the
  workshop’s baseline uses it; a sentence names the difference from 2 %.
- If two contracts overlap according to their entries (an old one runs past the
  start of a new one), the app does not warn yet; contract list and billing year
  treat the old one’s end differently.
- The thank-you hint remembers its day per device in the browser; in a household
  with several devices it can appear once a quarter on each.

### Migration

None. The schema stays 1.8.0; new fields are optional, no new store in the
backup (the new settings live in `settings.json`; a root certificate is not a
secret and is part of the backup). The **inbox extraction schema** goes from 5
to 6: a document read before 3.10.0 keeps its extraction; on the next re-read
the cache misses once, and only then do invoice number and reference come in.
For Docker: set the restart policy (see above).

### Tests

- **Balance:** `BalanceMeasuredTest`, `PeriodEndEstimateTest`, `SettleSumTest`,
  `SummarySettleTotalTest`, `ProjectionBandRatesTest`, `OneProjectionTest`,
  `ScenarioServiceTest`, Node suites `explainer`, `chart`, `ha-snippet`.
- **Billing years and interface:** `SameSpanTrendTest`, `ReadingCheckTest`,
  `SupportHintSettingTest`, Node suites `router`, `lists`, `release-notice`,
  `scenario`.
- **Home network and errors:** `HomeTlsTest` and `HomeTlsEndpointTest` against a
  real TLS test server with a CA generated in the test, `OpenAiParamRetryTest`
  with recorded invented answers, `ErrorHandlerTest`, `ErrorHandlerFatalTest`
  against a real `php -S`.
- **Cascade:** `CascadeFeedTest`, `CascadeFeedEstimatedTest`,
  `MeteringConceptServiceTest`, `FlexibilityInsightTest`, Node suite
  `metering-concepts`.
- **Corrections:** `InboxStartReadingTest`, `InboxBillCorrectionTest`,
  `AnomalyParentRestTest`, `EvaluationDetailsTest`.
- **Image:** `ContainerStartTest` (start script with stand-in services under
  `/bin/sh`), `ImageSupplyChainTest`, `TrademarksTest`; in CI a step that stops
  both image variants and ends a service.
- **Documentation:** `DocumentedThresholdsTest` binds the numbers of the new
  sentences to the code (lead time, horizon, trend arrow limit, calibration
  sentence, monthly average, home network ranges, kinds of certificate error);
  `ContainerStartTest` looks for the restart policy in every example of the
  documentation. Tests recalculate the worked examples of the basics with the
  app.
- 1895 test methods (+133). Every new rule has a counter-check that breaks it:
  300 in 8 packages, all red.

### Lessons learned

- **A balance needs its date.** With rare readings “Current balance” was a
  figure months old, and the estimate up to today had no word of its own. Now
  every figure names its day, and the line up to today works it out.
- **The width followed the reading density, not the uncertainty.** Evenly
  spread months hardly scatter, so whoever read once a year got the most
  certain statement. This was found with an invented household with rare
  readings, not with the sample data, which is read monthly.
- **A sum has to reach every view.** The sum of two contracts first appeared in
  five places and not in several others, one of them with the sentence “like
  the balance card”. Since then all views read the same function.

[#36]: https://github.com/Bingerminger/energietracker/issues/36
[#38]: https://github.com/Bingerminger/energietracker/issues/38
[#40]: https://github.com/Bingerminger/energietracker/issues/40
[#41]: https://github.com/Bingerminger/energietracker/issues/41
[#42]: https://github.com/Bingerminger/energietracker/discussions/42
[#49]: https://github.com/Bingerminger/energietracker/issues/49
[#50]: https://github.com/Bingerminger/energietracker/pull/50

---

**Marketing sentence:** One balance, one truth: Energietracker shows what is
measured, what is estimated and what to expect at settlement, with an honest
range, reaches Home Assistant over HTTPS with your own certificate and comes as
a slim, signed image.

---

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
  leaves running contracts as they are and saves the current state first. A new
  meter gets its installation day and the reading at installation as its first
  reading. Reached from the meters page, Settings → Utilities & billing and the
  setup assistant.
- **Right at the start:** whoever picks electricity and solar in the setup
  assistant gets the step “How are your meters connected?” with a diagram: “One
  meter per utility” is pre-selected, “Another layout” opens the assistant at the
  end.
- **In the showcase and the demo:** the sample household “Just look around
  first” has a heat pump with its own two-rate meter (peak/off-peak) and a group
  contract behind the main meter; the main meter visibly bills only the rest. In
  the public demo the assistant leads up to the summary with pre-computed
  previews; creating is locked there.

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

- **Recommendation “statistical anomaly”:** only appears when the same month of
  an earlier year is measured (a heating month compared with summer is no
  anomaly), once per meter group and with the meter name when the utility has
  several meters. For new meters it therefore appears only after a year.
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
- **Annual report, traffic light:** it took the balance of a recorded follow-up
  contract and said “advance payments fit” while the running contract expected
  clearly more; now only running contracts count, and a large credit reads
  “advance payments higher than needed”. Ranges in the report also run from the
  smaller to the larger amount.
- **Average unit price** counted months without a contract and came out too low.
- **Advance plan from the bill:** up to the first new due date a note says that
  no advance is entered, instead of a silent 0 €. A truncated reading on the
  bill is not a conflict.
- Smaller points: beginners also see the short heating limit sentence at the
  balance card, the overview shows the new values right after “Apply”, the
  efficiency tile names the row without a class, and a sentence below the
  monthly table explains why monthly values and the billed quantity differ.
- **Group contract at its members** (since v3.1.0): “Costs & contracts” showed
  “No running contract” with “Add contract” at the meters of a group, and “Do I
  get money back?” did not know the group contract. Now the members share one
  row with the group contract, its balance counts once; `/api/summary` returns
  `group_contract` per member.

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
- 1761 test methods (+284). Every new rule has a counter-check that
  breaks it: 599 in 9 packages, all red.

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
