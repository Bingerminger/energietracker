# Tests

**English** · [Deutsch](../../entwicklung/tests.md)

[← Data model](../referenz/datenmodell.md) · [Compendium index](../README.md)

Deliberately **no** test framework — consistent with the dependency-free
philosophy. Two complementary harnesses under `tests/`; Node ≥ 20 + `jsdom` are
enough.

---

## 1. `frontend-api-shape.test.js`

*(until v1.4.3 `backend-shape.test.js` — renamed in v1.4.4, as the name should
reflect the frontend-side perspective; the empty `loadModule` stub was removed.)*

Checks that the API delivers exactly the data structures the frontend expects
(field names, types, envelopes). Background: several historical bugs arose from a
backend↔frontend field-name mismatch (e.g. `AnomalyService` delivered
`value/z_score`, the frontend read `actual/z`). Backend curl tests alone do not
catch this — this test compares the real response shapes.

Requires a running backend server.

---

## 2. `browser-render.test.mjs`

Loads the **real** view ES modules in JSDOM and calls `render()` against the
running backend server. Chart.js itself is replaced by a recording stub on
`window.Chart` (JSDOM has no canvas); the chart layer `components/chart.js` and
the remaining view logic (DOM construction, events, data flow) run for real. Up
to v2.14 `esm-loader.mjs` replaced the chart layer with a stub as well — it never
ran in the test; today the loader only points the API address at the test
server. Catches: ReferenceErrors, broken DOM queries, template errors,
event-binding errors.

### Module-graph pre-check (since v1.4.1)

The first check crawls **`app.js` together with all transitive imports over HTTP**
against the server. This exact gap caused bug v1.4.1: `sidebar.js` imported
`./state.js` instead of `../state.js` → 404 → the entire ES module graph broke →
the app stayed at "Loading…". A pure JSDOM direct import does **not** see this (it
loads via the file path, not over the browser graph). The HTTP crawl catches it.

---

## 3. Running

```bash
# 1. Test data (the demo dataset carries schema_version 1.1.0 and is migrated
#    additively to the current schema on start)
cp -r demo-data /tmp/etdata

# 2. A server that serves the API AND the static assets. IMPORTANT:
#    router.php (not api.php) as the router — it mirrors the nginx routing
#    (static file → direct; /api → api.php; otherwise index.php). With api.php as
#    the router, /public/js/app.js would run through api.php → 404, and the
#    module-graph crawl of the browser render test would fail. (Fixed since v1.5.1.)
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8899 router.php &

# 3. Tests
node tests/frontend-api-shape.test.js
node --import='data:text/javascript,import{register}from"node:module";\
import{pathToFileURL}from"node:url";\
register("./tests/esm-loader.mjs",pathToFileURL("./"));' \
  tests/browser-render.test.mjs
```

Both harnesses return exit code 0 on success. As of v3.0.0:
**frontend API shape 62/62**, **browser render 189/189** (incl. module-graph
pre-check and the forecast-model check for all five models). Since v2.11.0 the
module-graph crawl also follows dynamic imports — the router loads views on
demand. Since v2.12.0 the test renders every settings sub-page on its own,
passes a query to views (the capture view's direct link) and triggers saving
and field errors in dialogs. The shape test calls the import dry run and
re-showing a recommendation — against the demo copy, which the script
discards afterwards. Since v2.13.0 the render test opens the ⓘ explanations by
click and closes them with Escape, renders the help with a jump to a term and
checks the PV views for remuneration instead of cost and the balance for the
customer's side. Since v2.15.0 it checks the recorded charts: colours as
functions, the partial month pale with "14 of 31 days" in the tooltip, short
descriptions, data tables, year and meter from the address, two quick forecast
runs with one chart.

Without a server run `tests/format.test.mjs`, `tests/ha-snippet.test.mjs`,
`tests/plausibility.test.mjs` and, since v2.11.0:

- **`tests/router.test.mjs`** — JSDOM, made-up views and a staged, delayed
  `fetch`. It checks that a slow view does not overwrite the faster one, that
  the cleanup of views left behind runs, that read requests are aborted, that
  the query arrives, that errors offer "Try again", and that the back button
  closes a dialog without leaving the page.
- **`tests/contrast.test.mjs`** — reads the colour tokens from `tokens.css` and
  the utility colours from `Utilities.php` and checks every text/surface pair in
  both themes against WCAG AA (4.5:1).
- **`tests/chart.test.mjs`** (v2.15.0) — the chart layer with a recording stub:
  one chart per canvas, clean-up on navigation, redrawing on a theme change
  including the axis colours, no throw on a Chart.js error, every chart colour
  (utilities and palettes) at 3:1 on the card in both themes; plus the monthly
  rules from `lib/chart-data.js` (partial month, trend against the same months
  a year earlier, weather-adjusted only when every month carries a value).
- **`tests/demo.test.mjs`** (v3.0.0) — the public demo: builds it with
  `tools/build-demo.mjs` (starts its own PHP server on 8895), serves it
  statically (8894) and starts the real `app.js` from the build in JSDOM. Then
  every page of the navigation model: no request without a precomputed answer,
  no error, badge and notice with language choice, bill check with its
  defaults, source link to the tag, writes refused, CSV and PDF as files.
  Locally: `node tests/demo.test.mjs` (needs PHP and jsdom).
- **`tests/plural.test.mjs`** (v3.1.0) — `pluralCategory()` from `lib/i18n.js`
  (Intl.PluralRules, Portuguese as pt-PT) against
  `tests/fixtures/plural-cases.json`, the same file `PluralRulesTest` checks in
  the backend; plus no home-made plurals (`=== 1 ? t(…)`) in the views.
- **`tests/hardcoded-text.test.mjs`** (v3.1.0) — no texts in the code of views
  and components: text nodes in HTML templates and literals with an umlaut or ß
  stand out. Units, formula symbols and names are allowed (list in the test).
- **`tests/levels.test.mjs`** (v3.2.0) — experience levels and explainers
  without a server: where a level comes from (person before installation,
  unknown = expert), which page needs which level, navigation and CSS follow
  the level; the money explainer uses the billing period (`balance_path`), the
  weather explainer needs six months with an adjusted value and last year. The
  demo click test walks through every example household at two levels.
- **`tests/explainer.test.mjs`** (v3.4.0) — explainers, second edition,
  without a server: the energy-flow formula against
  `tests/fixtures/energy-flows.json` (the same file is checked in PHP by
  `PvSummaryServiceTest`), balance (solar out = generation, house = inflows),
  geometry R1–R5 (no text on text, on lines or on other nodes, no crossing
  except the explained bridge, everything inside the picture) over twelve
  cases in all seven languages, no hex colours and no emoji in the picture,
  “reduce motion”, highlighting, year/month in place; the timeline with built
  contracts without overlaps; money and weather with extreme values. Text
  widths are estimated (0.56 em, 0.6 em for digits) with a 10 % reserve.
- **`tests/docs.test.mjs`** (v3.4.0) — the handbook in the app: fetches the
  allowlist from the real `DocsService` (PHP command line) and renders every
  page with `lib/markdown.js` and the link rules from `lib/docs.js`. Every
  in-app target is allowed, every jump target exists, every image comes through
  the endpoint, the output holds only allowed tags and attributes and no
  Markdown leftovers; around 40 attack payloads are defused — in the renderer
  alone and in the link rules alone; the service worker keeps the docs for
  offline use.
- **`tests/cache-names.test.mjs`** (v3.4.0) — runs `sw.js` in a sandbox and
  matches the cache names the worker really creates against
  `lib/cache-names.js`: signing out clears the API responses of this
  installation, not those of a second one on the same origin; the worker does
  not intercept `data/`.
- **`tests/forms.test.mjs`** (v3.4.0) — the tidied-up interface without a
  server: one form grid in the CSS (six columns, two when narrow, subgrid,
  field groups, row lists with labels only on top), `[hidden]` always applies,
  no one-off patches in the views (upper case, `flex-end`, `.form-grid`); time
  windows as HH:MM; the appearance switch shows the effective appearance with an
  “A” for automatic and announces each step; the docs say the same.
- **`tests/poisoned-render.test.mjs`** (v3.4.0) — starts the server with the
  demo data in which every free text (and the path of the data directory)
  carries HTML and quotes, loads the real `app.js` in JSDOM and opens every
  page. If a value lands in the DOM unprotected, an element with `data-xss`
  appears — red. Plus the self-test “data/ blocked” under Access. Picks a free
  port itself.
- **`tests/lists.test.mjs`** (v3.4.0) — lists without and with a server: the
  order of contracts (`lib/contract-order.js`), paging (`components/pager.js`:
  threshold 50, page size remembered per list) and the billing period after the
  settlement date (`lib/billing-period.js`). Then the example household
  “eigenheim-modern” with a test meter (ended, renewing) and 400 daily values
  in the real `app.js`: both contract lists in the same order and with the
  same phase, readings with “N in year · M in total”, “All years”, paging and
  “only flagged”, checking a bill without a period and when switching utility
  and meter. Port from `ET_LISTS_PORT`, otherwise a free one.

In addition there is the **PHPUnit suite** for the service layer (`tests/unit/…`,
base class `ServiceTestCase`, since v3.1.0 `HttpServerTestCase` for tests over
HTTP): real against actual JSON files, without mocks. The
current number of test methods is in the README badge — `ReleaseConsistencyTest`
recounts it (v3.0.0: 472). Since v2.13.0 `LocaleCatalogTest` also checks keys
the code composes (`glossary.<id>.term`, `settings.field.<key>.label`) — the
check for literal keys cannot see them. Since v2.14.0 it knows plural forms
(`one`/`other` and the extra categories some languages need) and checks that
every key the code passes to `tp()` has them in every language. Run it with
`vendor/bin/phpunit --no-coverage`. It
is the **mandatory gate before every commit** (see
[Release process](release-prozess.md)).

**Documentation (v2.14.0).** `DocsIntegrityTest` reads every Markdown file under
`docs/` and the files in the root directory and checks: every relative link and
image points to an existing file, in exact spelling (GitHub distinguishes upper
and lower case, macOS does not); every anchor exists in the target; every German
page has its English mirror under the same path; nobody links a redirect; the
index lists every page; the [settings reference](../referenz/einstellungen.md)
names every key from `SettingsService::DEFAULTS`; and every documentation link
of the app (`lib/docs.js`) points to a real page. Since v3.4.0 every use case
“## X — …” has a short text in the catalogue and every short text its anchor
`case-…` in German and English, and the docs travel in the Docker image. Next
to it, `ReleaseConsistencyTest` keeps checking the API reference against the
routes and, since v3.4.0, that the frontend links to GitHub only for bug
reports, the project page and the source code of the version.
`DocsEndpointTest` (over HTTP) and `DocsServiceTest` secure the allowlist of
`/api/docs`.

**Calculation cores with synthetic data (v2.8.0).** `WeatherModelTest` generates
temperatures and consumption according to a known formula
(`consumption = a × HDD + c × days`) and checks that the heating model, the
adjustment, anomalies, trend, forecast and balance find it again — and report
nothing where there is nothing. `TemperatureSyncTest` replaces Open-Meteo with a
stand-in (interface `WeatherSource`) and checks which values a sync may
overwrite. Every new rule got a **counter-check**: deliberately revert the code,
and the test must turn red. Several tests were initially green even without their
rule (test data too smooth) and only became meaningful through this.

**Contracts to the day (v2.9.0).** `ContractDayAccurateTest` checks months with a
price change, a contract switch and a gap, the renewed and the cancelled
contract, cancellation deadline and reminder, missed deadlines, price increases,
pro-rata advances and the forecast. `TotalsAndSettingsRulesTest` pins down that a
meter out of service counts in every total, and checks the billing dates.

**Energy sources (v2.10.0).** `TankModelTest` builds a climate with winter and
summer and checks the tank log: a delivery made today does not change the
previous years, between the initial stock and a tank reading the consumption is
known, curve and consumption are one series, the tank mixes prices, a summer
interval carries base load, contradictory levels are reported, missing
temperatures come from the climate normal. `Co2FactorsTest` checks electricity
per year, the pinning of old defaults in existing installations and the offer of
the new ones, `PvSemanticsTest` rates, savings, tariff rank and the annual
report, `EfficiencyCertificateTest` partial years, limits and the
certificate-style figure. 36 counter-checks, all red.

**Balance over time (v2.16.0).** `BalancePathTest` creates a twelve-month contract
that started eight months ago and checks the monthly series `balance_path`: the
last point is the expected final balance, advances grow by plan, a measured month
costs unit price plus standing charge, a refund lowers what was paid in its month,
and only the current contract carries a series. The render test adds the previous
year and the switch in the monthly chart (the adjusted values must be
`heat_adjusted`), the PV energy flow, the small multiples of the overview and the
temperature band.

**Languages (v3.1.0).** Dedicated tests check what translators and developers
can get wrong; the rules behind them are in
[Translating](uebersetzen.md):

- `CatalogStyleTest` — the content of the catalogues: termbase
  (`tests/fixtures/termbase.json`, glossary = canonical form), form of address
  per language, the German canon, typography per language, labels not in
  mid-sentence, count placeholders only in plural groups, completeness of a
  language (`format.*`, plural rule, country), ASCII file names of the CSV
  exports, letters of the bill check. `LocaleCatalogTest` keeps checking the
  structure (same keys and placeholders).
- `CatalogUsageTest` — every catalogue key is used, literally or through a
  prefix the code composes; a counter-test shows that the detection finds a
  dead key.
- `HardcodedTextTest` — no message texts in the backend where text reaches the
  client (`throw`, `Response::error`, `'error' =>`, `$errors[] =`).
- `PluralRulesTest` — `I18nService::tp()` and `PLURAL_RULES` against
  `tests/fixtures/plural-cases.json`; every language from `languages.json` has a
  rule and cases.
- `CsvFormatV1Test` — format 1 of the CSV exports byte for byte against
  `tests/fixtures/csv-format-1/` (header of the monthly overview in all seven
  languages, file names). Rewrite only with `ET_WRITE_GOLDEN=1` — and then it is
  no longer format 1.
- `CsvLocalFormatTest` — format "local" per language (separator, decimal
  separator, dates, yes/no, file name), an unknown format with its error code,
  and every export file can be imported again — spreadsheets from other
  languages and impossible or US dates too.
- `PdfCharsetTest` — every report text of a language with
  `format.pdfCharset = cp1252` reaches the PDF without loss ("CO₂" → "CO2",
  widths in characters); a language with `none` counts as not settable
  (`pdfSupported()`, the route then answers 422); every catalogue names its
  character set.
- `DeviceLanguageTest` — against a real PHP server: `X-ET-Language` before the
  default language, `Vary`, the default language without the header, the
  manifest in the requested language.
- `DemoDataTranslatorTest` — every text of the demo data is translated into
  every language (`demo-data/translations.json`); IDs, numbers and company
  names stay.

**Over HTTP (v3.1.0).** A service test cannot see headers, status codes and
access rules. For those there is the base class `HttpServerTestCase`
(`tests/unit/Support/`): it starts `php -S … router.php` on a free port with
its own data directory, its own `settings.json` and its own environment
variables (`ET_AUTH` …), and removes both afterwards. `AgendaAccessTest` and
`IngressTest` build on it.

**Home Assistant, calendar, operation (v3.1.0):**

- `AgendaServiceTest` — the agenda from reminders, readings and contracts
  (kind, date, `severity`, `due_now`); a dismissed contract reminder is no
  longer due “now”; the calendar is valid iCalendar with stable UIDs, texts are
  escaped and long lines folded without splitting a UTF-8 character; the key
  set of `/api/summary` is frozen and every key is always present.
- `AgendaAccessTest` — against a real PHP server with sign-in switched on:
  `/api/summary` only with a key, with `Cache-Control: private, max-age=300`;
  the calendar only with a key of scope `calendar` in the link; a read key in
  the link is refused, and the calendar key works on no other route.
- `BulkIngestTest` — batch ingest: a year of daily values writes each file once
  (`JsonStore::batch()`), faulty entries do not hold up the others and keep
  their `index`, a falling value inside the batch is suspect, the limit of 500
  entries, the single object answers as before.
- `IngressTest` — running under Home Assistant ingress: no service worker and
  an internal address for the HA template when `X-Ingress-Path` arrives; caches
  and worker belong to this installation (prefix `et:<scope>:`, the self-repair
  unregisters only its own worker); `X-Remote-User-Name` and `X-Remote-User-Id`
  count only from the trusted proxy.
- `DeployTemplatesTest` — the templates for Unraid, CasaOS and Umbrel
  (`deploy/`) name the same image, port and data path as `docker-compose.yml`
  and the Dockerfile; the Unraid template is valid XML and knows every
  environment variable.
- Since v3.1.0 `tests/ha-snippet.test.mjs` also compares the sensor block from
  [step 5 of the Home Assistant guide](../anleitungen/home-assistant.md) line by
  line with the app's template (`haRestSensorYaml`), without comments, address
  and names.

**Quarter hours and flexibility (v3.3.0):**

- `IntervalServiceTest` — migration to 1.8.0; slots per year with clock
  changes (35,040, leap year 35,136, March four quarter hours fewer, October
  four more) and local time per slot; values land in their quarter hour, also
  across the turn of the year; meter readings from Home Assistant (spread by
  minutes, nothing for a repeated or falling reading and across a gap of more
  than six hours); only series in the index count; backup round trip, series
  without an entry are removed, the check rejects unknown series; portal import
  with quarter hours, also without a time zone on the day the clocks go back
  (100 quarter hours, none overwritten); ingest with `timestamp`; SMARD fetch
  against a stub (€/MWh → ct/kWh, values of the previous year in the first
  weekly package stay out; if it fails, an error code of its own and nothing is
  stored).
- `FlexibilityTest` — shifting metrics (cheap, negative, profile factor,
  advantage), the battery with a known result in euros, module 3 with a known
  saving in cents, validation of the time windows on the contract and the
  weighted exchange price in the dynamic check — a month below 90 % coverage
  falls back to the monthly mean.
- The render test builds the “Flexibility” page without data (two hints, the
  button for the year's exchange prices); the demo click test opens it with the
  example households, the levels test knows its minimum level (expert).

**v3.4.0 — help, security, report, history, interface:**

- `DocsServiceTest`, `DocsEndpointTest` — the docs allowlist, no path out,
  redirects, sign-in.
- `HouseholdSecurityTest`, `HouseholdHardeningTest`, `ContainerHardeningTest`
  — proxy headers, roles, lockout per device, size limits, host list,
  container and web server rules.
- `PdfWriterTest`, `ReportModelServiceTest`, `ReportAdviceServiceTest` — PDF
  structure, graphics and box log (nothing past the margin, no overlap), pages
  per example household in seven languages, rules of the recommendations with
  amount and priority.
- `ContractPhaseTest`, `ContractPhaseEndpointTest`,
  `ContractStatusTimelineTest` — a contract's phase, timeline fields.
- `ActivityServiceTest`, `ActivityAccessTest` — a follow-on contract settles
  hints, ticking off and undo, who did what, backup round trip, retention,
  roles.

This exact sequence runs automated in the **CI pipeline**
(`.github/workflows/ci.yml`) on every push and pull request against `main`. Four
jobs: **lint-php** (syntax check of all `*.php`), **phpunit** (service suite),
**test** (migration smoke + frontend API shape + browser render via `router.php`)
and **docker** (build image + container smoke against `/api/health`). Since
v3.1.0 **lint-php** and **phpunit** each run under PHP 8.2, 8.3 and 8.4 — the
minimum version, the version in Ubuntu 24.04 and the one in the Docker image;
since v3.4.0 also under 8.5 (the Docker image). Since v3.4.0 the **docker** job
also checks that php-fpm is only reachable inside the container and starts the
image hardened (example from [Docker operation](../betrieb/docker.md#hardened-operation-v340));
the **test** job sends the app with prepared data through every page
(`tests/poisoned-render.test.mjs`). All workflows run with read permissions,
actions of other vendors are pinned by commit SHA. A
separate workflow `docker-publish.yml` publishes the multi-arch image (amd64 +
arm64) to GHCR on every version tag; `docker-rebuild.yml` (v3.4.0) rebuilds the
latest release tag every week and scans the image for known vulnerabilities
(non-blocking). `docker-armv7-probe.yml` (v3.1.0, started
by hand only) builds the image for `linux/arm/v7` under QEMU and starts it
without publishing; only once it is green does arm/v7 join the platform list.

---

## 4. Known limit

A real **headless Chromium smoke** is not possible in the build environment (no
browser binary). Chart.js is a stub in the test — the entire view logic including
the chart layer, DOM creation, event binding and backend data flow run for real,
but **not** the actual canvas chart rendering. Recommendation before every release: click through once
manually in the browser, especially the chart-bearing views (dashboard combo
chart, consumption monthly chart, analysis, forecast).

---

[← Data model](../referenz/datenmodell.md) ·
[Release process →](release-prozess.md)
