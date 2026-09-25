# Release process

**English** · [Deutsch](../../technical/06-release-process.md)

[← Tests](05-testing.md) · [Compendium index](../README.md)

Every functional change produces a complete, internally consistent release. Typos
or minimal doc fixes do **not** trigger a release.

---

## 1. Semantic versioning

| Bump | When |
|---|---|
| **PATCH** (x.y.**Z**) | a bugfix without a behaviour/data-model/API change |
| **MINOR** (x.**Y**.0) | a new feature *or* an additive, backward-compatible model/API change |
| **MAJOR** (**X**.0.0) | a breaking change to the data model/API |

Examples from the history:

- v1.4.0 — tank stock model + efficiency per heat source (an additive model/API
  change) → MINOR
- v1.4.1 — sigmoid selectable in the forecast (a pure UI bugfix) → PATCH
- v1.4.2 — export of new utilities, date format, PDF figures, total-amount
  precedence, logo, compendium → MINOR (additive exports + API extension)
- v1.4.3 — sigmoid in the analysis, contract logic per utility, doc markdown, app
  name → PATCH (pure fixes, no new feature)
- v1.4.4 — audit hardening: service extraction (`DeliveryConsumptionService`,
  internal, API unchanged), CI pipeline, `JsonStore` traversal protection, demo
  data schema, test rename → PATCH (no new user feature, no API/data-model break;
  pure code quality and operations)
- v1.4.5 — CI actions to the Node 24 runtime (`checkout`/`setup-node` `@v4`→`@v5`)
  → PATCH (pure build-infrastructure maintenance, no code/behaviour change; fixes a
  GitHub deprecation warning)
- v1.5.0 — F1003 special payments (refund/back-payment, advance payment) → MINOR (a
  new, backward-compatible feature; additive data structure, no migration step,
  schema unchanged at 1.1.0)
- v1.5.1 — CI fix: test server via `router.php` instead of `api.php` (static assets
  + `/api` routing), server+tests in one CI step → PATCH (pure test/CI
  infrastructure, no application code)
- v1.6.0 — F1004 central meter-reading capture (new menu item `#/zaehlerstaende`,
  aggregate endpoint `/api/readings-overview`, mobile-first view) → MINOR (a new
  backward-compatible feature; additive endpoint, no schema field, no migration
  step)
- v1.6.1 — bugfix Issue #14 (the water sub-dashboard showed 0 m³; utility.js summed
  `m.kwh` instead of the utility-specific `consKey`) + Issue #13 (a huge spike on a
  meter swap; four-layered: (a) `replaceDevice` requires `old_final_counter`
  explicitly, (b) an off-by-one in `deviceOnDate` on the swap day fixed, (c) a
  plausibility check on the value range of the old device in `consumptionBetween`,
  (d) a `device_swap` flag for swap months, the AnomalyService respects it) → PATCH
  (pure bugfixes, no API or schema changes)

---

## 2. Release checklist

1. **Check the code against real effect.** Never document docs/schemas from memory
   — always against the source code (grep/`php -l`/smoke). *(Lesson learned: in the
   v1.0.0 refactor, schemas had to be corrected afterwards because field names were
   documented from memory.)*
2. **`VERSION`** updated (the single source of the version number).
3. **`CHANGELOG.md`**: a new section per "Keep a Changelog" (`Added` / `Changed` /
   `Fixed` / `Migration` / `Notes`).
4. **Version stamps** pulled in sync: `README.md` (badge + status), the `INSTALL.md`
   reference, the compendium header (`docs/README.md` and the affected chapters).
5. **Maintain the compendium** — *mandatory at every release from v1.4.2*: changed
   endpoints → `technical/03-api-reference.md`; changed behaviour/model → the
   affected `functional/*`; a new/changed view → `ui/01-views.md` + a new screenshot
   in `ui/screenshots/`; migration notes on a data-model change in
   `technical/04-data-model.md`.
6. **Tests** green: `frontend-api-shape` + `browser-render` (incl. the module-graph
   pre-check). Since v1.4.4 both plus a PHP syntax lint run automatically in the CI
   (`.github/workflows/ci.yml`) — the green CI run is a prerequisite for tagging. On
   a data-model change, pull the demo data and schemas along.
7. **A fresh smoke against a clean data set**: start the server with
   `ET_DATA_DIR` pointing at a copy of `demo-data/`, check the migration, then
   query the core endpoints and the changed paths. Never against the local
   `data/` — that holds real user data.

---

## 3. Doc-maintenance rule (from v1.4.2)

The compendium is part of the release, **not** a downstream extra. Rule of thumb
per change type:

| Change | Doc to maintain |
|---|---|
| a new/changed endpoint | `technical/03-api-reference.md` |
| a changed calculation/data model | the matching `functional/0X-*.md` + possibly `technical/04-data-model.md` |
| a new utility | a new `functional/0X-*.md`, the index, architecture |
| a new/changed view | `ui/01-views.md` + a screenshot under `ui/screenshots/` (a real capture with demo data) |
| a new lesson learned | here in this document |

The product's contents (code/docs) are only changed when the user explicitly
prompts it — no unsolicited "best-practice refactorings".

---

## 4. Git publication

**The CI gate sits between push and tag** — not both in one step:

```bash
# 1. Commit and push, still WITHOUT the tag
git add -A
git commit -m "vX.Y.Z — <short description>"
git push origin main

# 2. Wait for CI. Only proceed on green.
#    (jobs: test, lint-php, phpunit, docker)

# 3. Set and push the tag → triggers the GHCR publish
git tag -a vX.Y.Z -m "vX.Y.Z — …"
git push origin vX.Y.Z
```

A tag is the basis for the container image and the GitHub release. It must not
exist before the pipeline is green. `git push origin main --tags` pushes it out
before CI has confirmed it.

Then create the **GitHub release** (`gh release create vX.Y.Z --verify-tag
--latest`). It is the approval marker: what gets published is what has been
accepted.

> Up to v2.3.3 a ZIP-based procedure was described here. Since v2.0.0 no
> release carries attachments — installation runs via the container image from
> GHCR, `git clone`, or `git checkout` of a tag. The section was dropped in
> v2.3.4.

---

## 5. Lessons learned (cumulative)

- **Test the frontend browser-realistically.** Backend curl + JSDOM direct import
  are not enough — the module graph must be crawled over HTTP (catches 404 imports
  like v1.4.1).
- **Check the docs against the real code**, never from memory.
- **Check backend↔frontend field names on both sides** (several mismatch bugs in
  the history).
- **`str_replace` on large methods cautiously** — it can cut off adjacent docblocks;
  afterwards `php -l`.
- **Recognise model dual use.** `dailyDeliveryConsumption` served costs *and* the
  stock curve; the final-stock-0 assumption was correct for costs but wrong for the
  stock curve → decoupled in v1.4.0.
- **Service extraction without a breaking change (v1.4.4).** When extracting
  `DeliveryConsumptionService`, the public signature of `ConsumptionService` was
  preserved: the new constructor parameter is `?DeliveryConsumptionService = null`,
  a lazy getter creates the service itself if needed. This way existing callers
  (tests, `DeliveryService::stockHistory()`) do not break. Rule of thumb: internal
  refactors must not force the outer API to change.
- **Keep an eye on the CI action runtime (v1.4.5).** GitHub periodically deprecates
  the Node runtime on which the actions *themselves* run (Node 20 → 24). This is
  independent of the Node version you set up in the workflow for your own tests.
  Pinning to major tags (`@v5` instead of a SHA) lets GitHub pull patch updates
  automatically; on a major bump, check whether behaviour changes (e.g.
  `checkout@v6` moved the credential storage → irrelevant for simple CI, but decide
  consciously, do not blindly take the newest tag).
- **An additive feature without a migration (v1.5.0, F1003).** `special_payments`
  was modelled like `bonuses` as an optional array that defaults to `[]` on
  normalisation. This way existing contracts without the field work unchanged — no
  migration step, the schema stays 1.1.0. Rule of thumb: keep a new contract
  subfield additive and default-`[]`, then it is backward-compatible by
  construction. Scope gating belongs in `Utilities` (single source of truth:
  `hasAdvancePaymentContracts()`), not in hardcoded utility lists in the
  service/frontend.
- **The dev/CI server needs a router (v1.5.1).** `php -S host:port api.php` makes
  `api.php` the router for EVERYTHING — static assets (`/public/js/*`) then land in
  `api.php` → 404, the module-graph crawl of the browser render test breaks.
  Solution: a `router.php` that mirrors the nginx behaviour (file → direct, `/api` →
  api.php, otherwise index.php). Secondly: in GitHub Actions every `run:` is its own
  shell — a `php -S … &` backgrounded in step A is gone in step B. Server start,
  readiness probe, tests and teardown must be in ONE step. Lesson: always mirror
  the local test infrastructure once for real against the CI setup, do not only
  check backend endpoints.
- **Aggregate endpoint over a bulk POST (v1.6.0, F1004).** When building the central
  meter-reading capture, the question "one API call to save all meters or one per
  meter?" was a real fork. Decided against a new batch endpoint, **for** the
  existing POST per meter — reasoning: partial failures stay precisely localisable,
  no new data format, no additional validation path. Instead aggregation **on the
  read side** via `GET /api/readings-overview` (all meters + last reading in one
  round trip) — this addresses "minimise API calls" where it makes domain sense (the
  initial data load) and leaves writing granular. Rule of thumb: aggregates are
  often the right answer for read performance; they are rarely the right answer for
  write robustness.
- **Default values are hidden data corruption (v1.6.1, Issue #13).**
  `(float)($input['old_final_counter'] ?? 0)` in `replaceDevice` made an
  incompletely configured meter swap look like a cleanly closed one — the bridging
  logic then found a plausible-looking `final_counter=0`, computed `partA = 0 −
  prev.counter`, which discarded as negative one sub-case, but another case (a
  swap-day reading with `device_id=old`) produced a huge jump. Lesson: a missing
  mandatory value must be an explicit 400 error, not a silent numeric default —
  especially when the field later feeds into plausibility checks.
- **An off-by-one at the cut-off date must be identical everywhere (v1.6.1, Issue
  #13).** `deviceOnDate` (creating a reading) and `deviceIdOnDate` (evaluation) both
  used `$date > removed_on`, i.e. on the swap day itself the reading still belonged
  to the OLD device. In the bridging path this leads to a fatal jump. Convention
  clarified: an interval at `removed_on` ends before that day, the swap day itself
  belongs to the NEW device (`$date >= removed_on` everywhere). Lesson: with date
  inclusivities at cut-off dates, decide by convention before the first commit and
  nail it down with a comment at the day-1 check — not "this feels right" per spot.
- **A content plausibility check instead of magic numbers (v1.6.1, Issue #13).** The
  first line of defence was a cap `total > 100 × finalOld`. It did NOT catch
  Viktor's real data, because 17572 < 100 × 17549. Only the **content** check "does
  `prev.counter` even lie in the value range `[initial_counter_old,
  final_counter_old]` of the supposedly old device?" caught it cleanly, because it
  checks the CAUSE (the device_id assignment) instead of the symptom (a large
  value). Lesson: before tuning thresholds, check whether the underlying assumption
  about the data even holds — that is usually exactly where the lever is.
- **Frontend-backend field names across several stages (v1.6.1, Issue #14).**
  `kwh_per_day` was computed in `enrichWithWeather` from the raw `kwh` field, BEFORE
  `applyUtilityFields` shifted `kwh → m3` for water and nulled `kwh`. Result: in the
  same month row there was `kwh = 0` (which the m³ column showed) and
  `kwh_per_day = 0.3` (which the m³/day column showed) — a self-contradictory
  record. Lesson: utility-specific shifts either at the very end or consistently
  across all derived fields. Secondly: the frontend must resolve field names
  utility-aware (`consKey = consumption_unit==='kWh' ? 'kwh' : 'm3'`), not hardcode
  onto one field.
- **A backend mandatory field without a frontend input = a guaranteed failure
  path (v2.1.1, Issue #18).** `MeterService::create()` requires `capacity > 0` +
  `initial_stock` for delivery-based utilities, yet the "New meter" form never
  rendered or sent these fields → every oil/pellet tank failed on creation with no
  field to fill in. Lesson: treat `reading_kind`-dependent mandatory fields as a
  pair — validation in `Utilities`/`MeterService` AND the matching input field in
  the same change; a mandatory field the UI cannot capture is a 400 generator by
  construction.
- **A serializer with a hardcoded field list goes silently incomplete (v2.1.2).**
  `BackupService::export()` saved only `meters/readings/contracts`; `deliveries`
  (v1.3.0) and `meter_groups` (v1.8.0) were added as new per-utility data stores,
  but the list was never updated → backup/restore lost the data silently, as did
  the top-level `reminders`. Aggravatingly, the roadmap assumed "BackupService
  pulls all JSON files per utility anyway", and no test checked a round-trip.
  Lesson: a backup/export needs an export→import round-trip test that is
  mandatorily extended for every new data store; completeness assumptions about
  the serializer belong in tests, not in documentation.
- **A domain rule must be propagated to EVERY aggregator (v2.1.3).** The F1006
  rule "submeters do not count in utility totals" lived only in
  `ConsumptionService::forUtility`. Three other places that sum over meters
  themselves — `PdfReportService::yearAggregate`, `BenchmarkService` and the
  dashboard `groupBreakdown` — never got it at v1.8.0 → submeter double-counting in
  the annual PDF report and the efficiency class. Lesson: an exclusion/filter rule
  that applies to one aggregation applies to ALL; it belongs in a shared source
  (e.g. a "root meter" helper), not copied/forgotten per consumer.
- **Save-path validation does not protect the compute path (v2.1.4).**
  `applyWaterContracts` silently billed waste water on the drinking-water volume
  when `basis='separater_zaehler'` had no meter reference — contrary to its own
  code comment (which says 0). `ContractService` does prevent that state on save,
  but un-validated data (backup import, legacy) still reaches the computation.
  Lesson: the compute layer must be defensively correct (follow its documented
  behaviour), not rely on write-path validation — especially since restore writes
  data straight into the store.
- **No second colour/value source beside the SSOT (v2.1.5).** The monthly chart
  had its own hardcoded 2-colour palette (`utilityColor`: only gas/strom, the rest
  blue) instead of the utility `color` from the SSOT — 6 of 8 utilities got the
  wrong chart colour, while the rest of the UI used `u.color`. Plus: PHP
  `modify('+N months')` overflows at month-end (31 Aug + 6 months → 3 Mar instead
  of 28 Feb) — clamp the day to the target month's length. Lesson: pull any display
  property that already lives in the SSOT (Utilities) from there; and guard date
  arithmetic against month-end overflow.
- **An exclusion filter applies to every consumer (v2.2.0).** `is_shadow` was
  filtered in `ConsumptionService` at both call sites, but not in
  `ForecastService` — even though `ContractService::create()` promised in a
  comment that shadow contracts never feed into the forecast. As soon as the last
  real contract ended before the forecast horizon, the forecast projected prices
  and advance payments from the hypothesis. Same class as the sub-meter double
  counting in v2.1.3: an exclusion that only sits at some call sites is not an
  exclusion. Lesson: raw getters (`contracts->list()`) put the burden on the
  caller — a getter that enforces the filter is better; and a promise in a comment
  is not a mechanism, a test is.
- **Retro-fitted languages need a completeness check (v2.2.0).**
  `lib/format.js` mapped number, date and month formatting via a hand-maintained
  `{de, en}` table; the languages added in 2026 silently fell back to `de-DE`, so
  five of seven locales showed German number grouping and "Mär/Mai/Dez". Lesson: a
  new language (or utility) is only done once every place holding an enumeration
  has been carried along — catalogue, formatting, CSS tokens, default names. Where
  the platform can do it (`Intl`), no hand-written table belongs in the code.
- **A default instead of an error is data loss (v2.2.0).** `collectWaterForm()`
  turned a forgotten price into a valid tariff of 0 ct/m³ via
  `parseFloat(x || 0)`. The backend guard could not catch it because both fields
  arrived filled. Lesson: `|| 0` on user input destroys exactly the information
  the validation path needs — "not filled in". Pass empty through and let the
  guard decide.
- **A figure without a frame of reference lies (v2.2.0).** The tariff comparison
  reported the total consumption of the period on every row, but computed cost
  only over the months the respective contract covered. Both numbers were
  correct on their own — side by side they produced an invented saving (49 %
  instead of the real 15 %). Lesson: when a table puts values next to each other
  for comparison, every column must share the same frame of reference; where
  terms differ in length, a normalised figure is needed (here: total cost per
  unit). And: don't mix costs with cash flows (advance payments, one-off
  settlements) — they answer different questions.
- **`vendor` in .gitignore matches at every level (v2.2.0).** When self-hosting
  the fonts and Chart.js under `public/vendor/`, the unanchored `vendor/`
  pattern would have kept those files out of both the repository and the Docker
  image — the app would have shipped without fonts and without charts, and no
  test would have noticed. Lesson: gitignore patterns without a leading slash
  apply at every level; check newly added asset directories with
  `git check-ignore` and pin their delivery in the Docker smoke test.
- **Whatever is updated by hand on release day eventually gets forgotten
  (v2.2.0).** `docker-compose.yml` still pinned `1.9.3` across seven releases;
  anyone starting from the repository got a version predating the entire v2.x
  bundle. Lesson: every version stated outside the `VERSION` file belongs in a
  test (see `ReleaseConsistencyTest`) — service worker cache, compose pin,
  changelog section, README and INSTALL stamps.
- **Behaviour must not depend on the wording of a message (v2.2.1).**
  `ErrorHandler::statusFor()` detected "nicht gefunden" via `str_contains` and
  derived 404 from it. That worked while every message was German; once
  localisation landed in v2.0.0 it only covered German and English — a Spanish
  interface ("no encontrado") got **500 instead of 404**. Lesson: the moment
  texts get translated, every string check in the code becomes a time bomb.
  Meaning belongs in the type (here `Http\NotFoundException`), text only in the
  display. When introducing i18n, deliberately search for `str_contains`,
  `match` and `switch` over message texts.
- **A cache buster that only versions the entry point versions nothing
  (v2.2.3).** `index.php` appends `?v=<version>` to `app.js` — but the modules
  import each other with bare paths (`./lib/sidebar.js`). Under
  `stale-while-revalidate` the service worker kept serving them from the old
  cache after an update: a fresh `app.js` met a stale `sidebar.js` without the
  expected export, the module graph aborted with a SyntaxError, and the UI got
  stuck on "Loading…". Every running installation was affected. **Lesson:** with
  ES modules it is not the entry point that decides freshness, but the weakest
  building block. Application code therefore belongs in the network-first branch
  of the service worker — the speed gain of stale-while-revalidate does not
  outweigh a total outage. And: adding an export changes the contract between two
  files; across version boundaries that is a breaking change no same-revision
  test can see. A self-healing step in the shell (cache version ≠ shell version →
  purge and reload once) catches exactly this case.
  A second, unrelated lesson: this hole **had been named in the review** and was
  left open because it was filed under "C12, cache buster" as polish. A known
  defect in the delivery path is not polish.
- **Demo data is part of the feature (v2.2.2).** Neither
  `demo-data/reminders.json` nor the demo backup carried any appointments —
  anyone loading the demo saw an empty module and might well have thought it
  broken. Exactly the same class as the missing heating oil and pellet
  deliveries in v2.1.2: the backup carries a fixed field list, and a new data
  bucket has to be carried along there. Lesson: a new data module is only done
  once the demo data shows it — via **both** routes (copying the directory and
  "load demo") with the same content. The test
  `DemoServiceTest::testDemoDirectoryAndBackupCarryTheSameReminders` keeps the
  two in step from now on.
- **A PDF is testable (v2.2.2).** `PdfReportService` stayed untested for a long
  time because "you can't really check a PDF". But `PdfWriter` writes
  uncompressed: `preg_match_all('/\((.*?)\) Tj/s', $pdf)` yields the printed
  text fragments, so the figures can be compared directly. Comparing file size,
  by contrast, is useless — an extra meter adds its own page and shifts the
  length without any total being wrong.
- **Screenshots go stale silently (v2.2.1).** The UI reference still showed
  images from v1.9.2 — including the tariff comparison with exactly the
  miscalculation v2.2.0 fixed. No test fires when a picture is old. Lesson: a
  visible change to a view means its screenshot belongs in the same release.
  Capture with demo data, light theme and a tall viewport (1440 × 1500) rather
  than `fullPage` — full-page captures cut off the fixed sidebar. Then run
  `pngquant`.
- **"A default instead of an error" lived on in the frontend (v2.5.3).**
  `type="number"` returns an empty string for "12,5" in some browsers, and
  `Number("")` is 0 — ten forms silently booked a 0 this way, including the
  final reading of a meter swap. Numbers are now entered as text with a decimal
  keyboard and read by **one** parser (`parseDecimal`) that tells "empty" from
  "0". Any new number field takes this route.
- **A template users copy is code (v2.5.3).** The Home Assistant snippet lived
  in the app and in the guide — two versions, both wrong: `float(0)` recorded a
  meter reading of 0 when the sensor was unavailable, `"Bearer !secret …"` sent
  the text literally. The template now lives in one module
  (`public/js/lib/ha-snippet.js`), and `tests/ha-snippet.test.mjs` holds both
  language versions of the guide against it.
- **A scroll container only clips what is positioned against it (v2.5.3).** The
  invisible `.sr-only` label "Actions" in the table header is absolutely
  positioned; its containing block lay outside the `overflow-x: auto` — the whole
  page became wider on the iPhone. `.table-wrap` therefore carries
  `position: relative`. Measure overflow via `document.documentElement.scrollWidth`,
  not via the position of single elements: clipped elements still report their
  full width.
- **Discarding the negative interval is no protection (v2.6.0).** After a wrong
  reading (a 0 from Home Assistant) the consumption calculation only discarded
  the decrease and counted the next interval in full from the wrong reading — a
  month received the whole meter reading as consumption. The error sits in the
  **reading**, not in the interval: sandwiched outliers are now recognised as
  such and left out (`plausibleReadings`). Lesson 6 in new clothes — check the
  cause, not the symptom.
- **A protection that guards one door suggests a lock (v2.6.0).** The Home
  Assistant token protected only the push; the text in the settings read as if
  it locked the API. And a sign-in without web server rules could have been
  bypassed via `data/backups/`. Hence the order: first the delivery rules
  (Apache, nginx, development server), then sign-in — and every hint text says
  **what** is protected.
- **A route list without a test is a claim (v2.6.0).** `docs/API.md` knew 37 of
  70 routes, the reference claimed "68, v1.9.2", documented bodies did not work,
  and `verdict` was silently broken in v2.0.0. `ReleaseConsistencyTest` now
  compares the routes from `bootstrap.php` with the reference in both
  languages; formerly documented field names stay valid as aliases. A stability
  promise defines what may change.
- **Listeners on a long-lived container must be removed (v2.6.0).** The settings
  view re-renders itself after an import or a language switch; every round
  attached `input` and `beforeunload` listeners to the same container and to
  `window`. Result: a false "unsaved" warning on close. Listeners attached to
  objects outside the view are collected in a list and removed on the next
  render. And: a message must not cover the button it refers to — with a dialog
  open, toasts appear at the top.
- **"Language-country" is not always a region anyone wants (v2.7.0).** The
  first draft of the country profiles built the `Intl` region as
  `${language}-${country}` — for English with the default country DE that is
  "en-DE", and `Intl` writes German numbers there. Every existing English
  installation would have got them overnight. An older test
  (`format.test.mjs`) that expected English numbers caught it. The rule since:
  the country refines only languages spoken there; the backend follows the
  same rule. And `Intl` sometimes separates amounts differently from numbers
  (de-AT "€ 1.234,56" next to "1 234,5") — the backend mirrors that so PDF
  and interface write the same.
- **A default profile is a promise to every existing installation (v2.7.0).**
  `CountriesTest` checks that the German profile equals the previous defaults
  exactly — otherwise a small deviation (location name, CO₂ value) would
  silently change every installation that picks "Apply all". On first start
  the app writes only values that differ from the defaults; writing every
  profile value would freeze the defaults, and a later correction would no
  longer reach new installations.
- **An expectation that contains the value being checked checks nothing
  (v2.8.0).** The seasonal mean of the anomaly detection contained the checked
  month itself, the trend mainly measured in which month the data ended, and
  heating utilities got an expectation of 0 in summer — every summer was an
  "outlier", and a genuine +35 % February went unnoticed. Expectations belong to
  the month (heating model, the same calendar month in other years), the checked
  value never goes into its own expectation, and the spread is estimated
  robustly.
- **Synthetic test data must be able to refute the old calculation
  (v2.8.0).** Several new rules were green on the first test data even
  **without** the rule: no noise, the same climate every year. Only the
  counter-check — deliberately revert the rule, the test must turn red — revealed
  this. Since then every calculation rule gets a counter-check, and the test data
  is built so that the old way is visibly wrong.
- **A balance follows the calendar, not the readings (v2.8.0).** The advances
  only counted for months with a reading: anyone who last read the meter in March
  saw the balance of three months in September, while nine advances had been
  debited. And a breakdown must add up to its total — the first version of the
  new card showed parts that lacked the base price of the estimated months. The
  same applied to the annual tiles next to it; they now state their as-of date.
- **A setting without effect is false information (v2.8.0).**
  `confidence_band_sigma` and `weather_auto_fill` sat in the settings and did
  nothing. Both take effect now — and the daily fetch from Open-Meteo made the
  README sentence "makes no external requests" false. Whoever brings a setting to
  life checks which statements in the docs depended on it doing nothing.
- **One rule, one place (v2.8.0).** Which months feed the heating curve was
  decided slightly differently by the analysis, adjustment, forecast and
  anomalies; the same meter showed R² 0.42 in the analysis and 0.56 in the
  forecast. Now `ConsumptionService::isRegressionCandidate()` decides, and the
  chart draws exactly the points that are in the fit. Lesson 26 (month
  arithmetic) nearly bit again twice along the way — in a test
  (`strtotime('-8 months')`) and in the calibration window (`-12 months` on
  29 February); both now compute from the first of the month.
- **A formula for annual values does not carry over to months unchecked
  (v2.8.0).** The first version of `heat_adjusted` scaled the heating share
  (`actual − base load`) by `HDD_normal / HDD_actual`, as VDI 3807 provides for
  annual values. In a warm September with 11 instead of 30 degree days, however,
  this "heating share" is noise — ×2.7 produced +32 %, enough to push the annual
  trend over its 3 % threshold. Now only the weather influence according to the
  model is converted (`actual + a × (HDD_normal − HDD_actual)`). The tests were
  green because their synthetic climate was the same every year, so the ratio was
  always 1; it only came to light when reading the finished PDF report. Test data
  needs a year that deviates from the normal.
- **Every projection needs the "nothing to project" case (v2.8.1).** The
  balance projection introduced in v2.8.0 called its estimator even when there
  was none — a contract on a meter with fewer than two readings, the usual
  first step of a new user. `contract-status` answered with 500, and the
  consumption view showed nothing but the error message. The browser and shape
  tests run against the complete demo data and never see that state. It came
  to light while writing a test for the next package. Since then every new
  calculation gets a test with an empty data set and with one that is
  excluded entirely (for example everything before a cut-off).
- **A test can also cement a bug (v2.9.0).** For years
  `ContractEdgeCasesTest` explicitly guaranteed that a contract switch on the
  15th assigns the whole month to the old contract
  (`…AttributesEntireMonthToContractActiveOnFirst`). The test described the
  behaviour, not the supplier's bill — and so stood in the way of the fix. Such
  a test is not adjusted silently: it is renamed (`…SplitsTheMonthByDay`), the
  CHANGELOG names the changed values, and the counter-check shows that the new
  test rejects the old way.
- **No record does not mean zero (v2.9.0).** Consumption after a forgotten
  contract end cost €0 — the app assumed that the bill ends with the end date.
  The supplier keeps billing. Related to Lesson 24: where data is missing, what
  belongs there is the correct domain assumption (the contract runs on) plus a
  note that it is an assumption — not a silent zero.
- **A reminder counts down to the action, not to the event (v2.9.0).** The
  three levels counted down to the contract end. With one month's notice, two
  of three reminders came after the last day to cancel — punctual and useless.
  Whoever reminds counts back from the last day on which something can still be
  done.
- **A comparison needs the same period (v2.9.0).** A shadow contract applied
  only to its own term. An offer entered from April to September therefore
  looked cheaper than the same offer for the whole year: it was missing the
  winter. An offer is a price sheet and applies to the whole comparison period.
- **What "inactive" means was defined differently in four places (v2.9.0).**
  The consumption view and the CSV counted a deselected meter, the PDF report
  and the efficiency class did not — the same meter, two annual totals. The
  same class as Lessons 19 and 22. Now the meaning lives in one place
  (`MeterService::countsInTotals()` for totals, `inService()` for capture and
  warnings): a meter out of service still has a past.
- **A setting without effect is not removed silently (v2.9.0).**
  `min_temp_days_forecast` and `baujahr` did nothing. They are gone from the
  interface but not from the API: anyone who sets them by script would
  otherwise get a different state back without notice. They are marked as
  deprecated and are only dropped with a major version.

---

[← Tests](05-testing.md) ·
[Compendium index](../README.md)
