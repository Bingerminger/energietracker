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

Both harnesses return exit code 0 on success. As of v1.9.2:
**frontend API shape 20/20**, **browser render 36/36** (incl. module-graph
pre-check and the forecast-model check for all five models).

In addition there is the **PHPUnit suite** for the service layer (`tests/unit/…`,
base class `ServiceTestCase`): real against actual JSON files, without mocks. As of
v1.9.2: **86 tests / 274 assertions**. Run with `vendor/bin/phpunit
--no-coverage`. It is the **mandatory gate before every commit** (see
[Release process](06-release-process.md)).

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
