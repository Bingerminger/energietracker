# Changelog (English)

All notable changes, in English from version 3.4.0 on. The German
[CHANGELOG.md](CHANGELOG.md) is the complete record, including every earlier
version. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/).

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
