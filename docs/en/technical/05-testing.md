# Tests

**English** · [Deutsch](../../technical/05-testing.md)

[← Data model](04-data-model.md) · [Compendium index](../README.md)

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
running backend server. Chart.js is stubbed via `esm-loader.mjs`; the remaining
view logic (DOM construction, events, data flow) runs for real. Catches:
ReferenceErrors, broken DOM queries, template errors, event-binding errors.

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

Both harnesses return exit code 0 on success. As of v2.13.0:
**frontend API shape 61/61**, **browser render 156/156** (incl. module-graph
pre-check and the forecast-model check for all five models). Since v2.11.0 the
module-graph crawl also follows dynamic imports — the router loads views on
demand. Since v2.12.0 the test renders every settings sub-page on its own,
passes a query to views (the capture view's direct link) and triggers saving
and field errors in dialogs. The shape test calls the import dry run and
re-showing a recommendation — against the demo copy, which the script
discards afterwards. Since v2.13.0 the render test opens the ⓘ explanations by
click and closes them with Escape, renders the help with a jump to a term and
checks the PV views for remuneration instead of cost and the balance for the
customer's side.

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

In addition there is the **PHPUnit suite** for the service layer (`tests/unit/…`,
base class `ServiceTestCase`): real against actual JSON files, without mocks. The
current number of test methods is in the README badge — `ReleaseConsistencyTest`
recounts it (v2.13.0: 448). Since v2.13.0 `LocaleCatalogTest` also checks keys
the code composes (`glossary.<id>.term`, `settings.field.<key>.label`) — the
check for literal keys cannot see them. Run it with
`vendor/bin/phpunit --no-coverage`. It
is the **mandatory gate before every commit** (see
[Release process](06-release-process.md)).

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

This exact sequence runs automated in the **CI pipeline**
(`.github/workflows/ci.yml`) on every push and pull request against `main`. Four
jobs: **lint-php** (syntax check of all `*.php`), **phpunit** (service suite),
**test** (migration smoke + frontend API shape + browser render via `router.php`)
and **docker** (build image + container smoke against `/api/health`). A separate
workflow `docker-publish.yml` publishes the multi-arch image (amd64 + arm64) to
GHCR on every version tag.

---

## 4. Known limit

A real **headless Chromium smoke** is not possible in the build environment (no
browser binary). Chart.js is stubbed in the test — the entire view logic, DOM
creation, event binding and backend data flow run for real, but **not** the actual
canvas chart rendering. Recommendation before every release: click through once
manually in the browser, especially the chart-bearing views (dashboard combo
chart, consumption monthly chart, analysis, forecast).

---

[← Data model](04-data-model.md) ·
[Release process →](06-release-process.md)
