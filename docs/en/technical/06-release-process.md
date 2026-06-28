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
7. **A fresh smoke from the packed ZIP**: unpack, migration, server, check the core
   endpoints + the changed paths.
8. **Build the ZIP** (exclude: `.git`, `*.pyc`, `__pycache__`, `.DS_Store`, runtime
   `data/*.json`, `data/backups/*`).

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

The user takes the ZIP contents into the local repo and publishes:

```bash
git add -A
git commit -m "vX.Y.Z — <short description>"
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin main --tags
```

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

---

[← Tests](05-testing.md) ·
[Compendium index](../README.md)
