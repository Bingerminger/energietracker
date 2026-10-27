# API reference

**English** · [Deutsch](../../referenz/api.md)

[← Architecture](../entwicklung/architektur.md) · [Compendium index](../README.md)

All endpoints under `/api/…`. A uniform response envelope:

```json
{ "success": true,  "data": … }
{ "success": false, "error": "message in the language of the request", "code": "errors.reading.dateInvalid" }
```

`{utility}` is one of: `gas`, `strom`, `wasser`, `fernwaerme`, `heizoel`,
`pellets`, `pv_einspeisung`, `pv_erzeugung` and, since v3.1.0, `waerme`
(heat). As of: **195 routes**, v3.9.0 —
`ReleaseConsistencyTest` checks that every registered route appears in the
table below (German and English).

> Detailed request/response examples for the most-used endpoints are in
> [`docs/API.md`](api-beispiele.md). **This** document is authoritative for paths and
> fields.

### Status codes of all endpoints

| Code | When |
|------|------|
| `400` | Invalid input. Since v2.5.3 on every write path: a date that is not a calendar date (`2026-02-30`, text), or an amount/meter reading that is not a number. Up to v2.5.2 both were stored. Since v2.6.0 also query parameters outside their range (forecast, annual report, temperature sync). *(v3.1.0)* A receipt with the wrong content, too large, or storage full (`errors.attachment.*`) — also an upload above PHP's `post_max_size`, then with that limit in the message (`errors.attachment.size`); text recognition not set up or not in the home network (`errors.ocr.off`, `errors.ocr.notLocal`); a meter reading on a meter with consumption per period (`errors.reading.periodMeter`), overlapping periods (`errors.period.overlap`), errors in the tenancy (`errors.tenancy.*`); an invalid year for the CO₂ price (`errors.co2.yearInvalid`), errors in a supplier bill (`errors.bill.periodInvalid`, `…amountInvalid`, `…noResult`, `…noContract`), a bill check for a utility without one (`errors.billCheck.unsupportedUtility`), an invalid market or metering location ID (`errors.meter.maloInvalid`, `…meloInvalid`), a capacity charge without a connected load (`errors.contract.capacityMissing`), deleting a meter that still has periods or bills (`errors.meter.hasPeriods`, `…hasBills`); a group contract on an unsuitable group or with prices for non-members (`errors.contract.targetInvalid`), a member with its own contract in the same period (`errors.contract.groupMemberOverlap`), deleting a group with contracts (`errors.meter.groupHasContracts`), a dynamic tariff as a real contract or outside electricity (`errors.contract.dynamicShadowOnly`), an unknown price model (`errors.contract.priceModelInvalid`), monthly prices without a readable line or for water and feed-in (`errors.contract.priceImportEmpty`, `…priceImportUnsupported`), wholesale prices without values (`errors.marketPrices.noRows`), a charging record without a paying contract, without a flat rate or with invalid parameters (`errors.evReport.*`), an invalid heat pump year (`errors.heatPump.yearInvalid`), a link to an electricity meter without the heat pump role (`errors.meter.heatPumpLinkInvalid`), an invalid PV value on the meter (`errors.meter.valueInvalid`), a time series with an unsuitable mapping or without a starting reading (`errors.import.mappingInvalid`, `…anchorMissing`) — all v3.1.0. *(v3.2.0)* An unknown example household (`errors.demo.personaUnknown`); a person’s name empty or too long, an unknown role, deleting your own person (`errors.users.nameInvalid`, `…roleInvalid`, `…notSelf`), own settings without a signed-in person, the password of a person from the proxy (`errors.users.noUser`, `…proxyNoPassword`); evcc not set up or not in the home network, no finished charging sessions, a CSV without the required columns, an unknown choice for the meter readings, meter readings of several charging points (`errors.evcc.off`, `…notLocal`, `…noSessions`, `…columns`, `…countersInvalid`, `…loadpointNeeded`). *(v3.3.0)* Invalid time windows for § 14a module 3 on an electricity contract (`errors.contract.gridTouInvalid`), an ingest timestamp without a time zone or in a form other than ISO 8601 (`errors.ingest.timestampFormat`), a year outside 2018–2100 for flexibility and for fetching quarter-hour prices (`errors.flexibility.yearInvalid`). *(v3.4.0)* A host list that does not contain the name of the request (`errors.settings.hostNotListed`); a self-chosen device ID outside letters, digits, `_` and `-` (`errors.meter.idInvalid`); a backup with identifiers made of control characters, quotes, angle brackets or backslash (problem `unsafe_id:<field>` in `detail.problems`). *(v3.4.0, F1024)* A done date that is not a calendar day or lies in the future (`errors.activity.dateInvalid`), a note over 500 characters (`errors.activity.noteInvalid`), an unknown receipt (`errors.attachment.notFound`). *(v3.5.0, F1027)* Day film: a date that is not a calendar day or lies outside 2000–2100 (`errors.energyFlow.dateInvalid`), an invalid month (`errors.energyFlow.monthInvalid`). |
| `401` | `/api/ingest` with a token set but a missing or wrong bearer header. *(v2.6.0)* With sign-in switched on: any non-public route without a session or API key (`errors.auth.required`); wrong password, since v3.2.0 also an unknown name (`errors.auth.wrongPassword`). *(v3.1.0)* A calendar key on any route other than `/api/calendar.ics`, a `read` or `admin` key as `?token=` in the calendar link. |
| `403` | *(v2.5.3)* Writing request (`POST`/`PUT`/`PATCH`/`DELETE`) from the browser of a **foreign** website — checked via `Sec-Fetch-Site`, falling back to `Origin` against `Host`. Requests without these headers (Home Assistant, curl, scripts) are not affected. *(v2.6.0)* Writing request with a read-only key (`errors.auth.readOnlyKey`). *(v3.2.0)* A member on a route for admins only (`errors.auth.adminOnly`) — [People in the household](#people-in-the-household-v320). |
| `404` | Unknown route or unknown record. Since v2.6.0 consistently also for records addressed in the URL (up to v2.5.3 partly 400). *(v3.1.0)* Also an unknown period (`errors.period.notFound`), tenancy (`errors.tenancy.notFound`), statement (`errors.tenancy.statementNotFound`) or supplier bill (`errors.bill.notFound`). *(v3.2.0)* An unknown person (`errors.users.notFound`). *(v3.3.0)* An unknown quarter-hour series (`errors.intervals.notFound`). *(v3.4.0)* A page or image of the documentation outside the allowlist (`errors.docs.notFound`; for an old path with `detail.moved_to`); a history entry (`errors.activity.notFound`) or an agenda event (`errors.activity.eventNotFound`) that does not exist (any more). |
| `405` | *(v2.6.0)* Known path, wrong method — with an `Allow` header. `HEAD` is answered like `GET`, `OPTIONS` with `204` and `Allow`. Up to v2.5.3: `404`. |
| `409` | *(v2.6.0)* The safety snapshot before an import/restore failed (`errors.backup.snapshotFailed`) — possible anyway with `?allow_without_snapshot=1`. Password or sign-in mode are fixed by the environment. *(v3.2.0)* A person’s name is taken (`errors.users.nameTaken`), the last admin would become a member or be deleted (`errors.users.lastAdmin`), the first admin’s password is fixed by the environment (`errors.auth.passwordFixed`). *(v3.4.0)* Ticking off with an `occurrence` that no longer applies — for example after a changed contract end (`errors.activity.occurrenceChanged`). |
| `413` | *(v3.4.0)* Body larger than allowed (`errors.http.tooLarge`) — checked against `Content-Length` before reading: routes reachable without sign-in (`POST /api/ingest`, `POST /api/session`) 1 MB, backup import, receipts and the imports (`…/import-csv`, `…/import-series`, `…/import-evcc`) 256 MB, all others 8 MB. With sign-in switched on, a request without a session comes back as `401` before that. |
| `421` | *(v2.6.0)* Host name not in `ET_ALLOWED_HOSTS` (only when set; IP addresses and `localhost` are always allowed). *(v3.4.0)* Likewise for the setting `allowed_hosts`; both lists apply. |
| `422` | *(v3.1.0)* PDF annual report, CO₂ letter or charging record in a language the built-in PDF fonts cannot set (`errors.report.pdfUnsupportedLanguage`) — the print view can. *(v3.4.0)* `POST /api/agenda/done` for a kind that only the data clears — “read the meter”, “service-charge statement due”, end of the price guarantee (`errors.activity.notManual`). |
| `429` | *(v2.6.0)* Sign-in locked for 5 minutes after five failures within 15 minutes (`errors.auth.locked`). *(v3.4.0)* The lock applies per device (client address; behind a proxy from `ET_TRUSTED_PROXIES` the one from `X-Forwarded-For`), after ten failures per name, after a hundred for everyone. |
| `502` | A service outside the app does not answer or answers with nothing usable — a fault on the other side, not invalid input: the place search (`errors.temperature.geocodeFailed`); *(v3.1.0)* `POST /api/ocr/reading` when your own text recognition service does not answer in time, cannot be reached or returns nothing usable (`errors.ocr.timeout`, `errors.ocr.unreachable`, `errors.ocr.badAnswer`); *(v3.1.0)* `POST /api/market-prices/sync-smard` when SMARD cannot be reached or returns no values (`errors.marketPrices.syncFailed`); *(v3.2.0)* `POST …/sync-evcc` when evcc cannot be reached or returns no list of charging sessions (`errors.evcc.unreachable`, `errors.evcc.badAnswer`); *(v3.3.0)* `POST /api/market-prices/sync-smard-intervals` when SMARD cannot be reached, a weekly package fails or there is no value for the year (`errors.marketPrices.syncQuarterHoursFailed`). |
| `503` | *(v2.5.3)* A data file is corrupt (not valid JSON). The file stays untouched; a quarantine copy `<file>.corrupt-<checksum>` is placed next to it. Up to v2.5.2 it was read as empty and overwritten on the next write. Since v3.1.0 the message is in the language of the request (up to v3.0 always German); `code` stays `errors.storage.corrupted`. *(v2.6.0)* The data comes from a **newer** version (e.g. after rolling back the image tag): all routes except `/api/health`, nothing is written (`errors.storage.dataTooNew`). `/api/health` itself answers `503` when `status` = `error`. *(v3.4.0)* The write lock has been held for more than 15 seconds (`errors.http.busy`, header `Retry-After: 5`; `ET_LOCK_WAIT_S` changes the deadline). *(v3.5.0)* The data directory is not writable and the app would have to create or migrate on start: all routes except `/api/health` (`errors.storage.notWritable`; up to v3.4 it was `500`). `/api/health` then reports `data_dir_writable: false`. |
| `500` | Unexpected error. Since v2.6.0 with `error_id`; the server log holds the details under the same ID. |

Since v2.5.3 writing requests run one after another (lock on
`data/.write.lock`): a Home Assistant push during an edit no longer loses a
change. Since v3.4.0 syncs with outside services lock only after fetching
(`POST /api/temperatures/sync-open-meteo`, `…/market-prices/sync-smard`,
`…/sync-smard-intervals`, `…/sync-evcc`), text recognition
(`POST /api/ocr/reading`) not at all; whoever waits longer than the deadline
gets `503`.

### Error codes *(v2.6.0)*

Every error response carries `code` — the catalogue key of the message
(`errors.reading.dateInvalid`, `errors.backup.invalid` …) or, for general HTTP
errors, `errors.http.*` (`badRequest`, `unauthorized`, `forbidden`,
`notFound`, `methodNotAllowed`, `unavailable`, `internal`). `error` is
translated into the language of the request (see below) and may change between
versions — **scripts evaluate `code`, never the message text.**

**Deprecated *(v3.1.0)*:** `errors.billCheck.gasOnly` is no longer sent — the
bill check exists for every utility with meter readings and a contract; a
utility without one reports `errors.billCheck.unsupportedUtility`. The key stays
in the catalogue until the next major version.

`detail` with file, line and exception type is only returned with
`ET_DEBUG=1` (up to v2.5.3 on every error, including absolute paths). Domain
details are still always returned, such as the findings of a faulty backup in
`detail.problems`.

### Language of the responses *(v3.1.0)*

Messages and labels of the API (utility names, recommendation texts, `error`)
come in **one** language per request. It is chosen in this order:

1. Header `X-ET-Language` — the interface sends the language of the device
   with every request (Settings → General → "Language on this device").
2. Setting `language` — the default language of the installation.
3. `Accept-Language` — only while there is no valid setting (in practice on
   first start).

An unknown value in `X-ET-Language` does not count; the default language
applies. The browser opens downloads (PDF annual report, CSV) as a page without
this header — so they are produced in the default language, as is everything
Home Assistant and scripts fetch. A script that wants another language sends
`X-ET-Language: fr`. Every JSON response carries
`Vary: X-ET-Language, Accept-Language` so that a cache does not mix the
languages. Up to v3.0 the setting applied to every device.

### Sign-in *(v2.6.0, opt-in)*

Without sign-in (the default) the API stays open as before. When it is switched
on (Settings → Access → "Sign-in & access", or `ET_AUTH`), **one** of these applies to
every route:

| Way | For | Passed as |
|---|---|---|
| Session | the browser | cookie `et_session` (HttpOnly, SameSite=Strict, 30 days; since v3.4.0 sliding: a session older than one day gets a new cookie with the full duration when used) after `POST /api/session` |
| API key | scripts, other programs, Home Assistant | `Authorization: Bearer etk_…`; scope `read` (`GET` only) or `admin` |
| Calendar key *(v3.1.0)* | calendar apps | API key with scope `calendar`, **only** as `?token=etk_…` and **only** for `GET /api/calendar.ics` |
| Inbox key *(v3.7.0)* | shortcut “Share → Energietracker”, Home Assistant (email) | API key with scope `inbox`, as `Authorization: Bearer etk_…`, **only** for `POST /api/inbox` (any other route `401`) |
| Proxy | behind Authelia, Authentik, Home Assistant Ingress or similar | `ET_AUTH=proxy`, only from addresses in `ET_TRUSTED_PROXIES`. Since v3.4.0: with `ET_PROXY_USER_HEADER` exactly that header; under Home Assistant Ingress only `X-Remote-User-Id`/`X-Remote-User-Name` (person by ID); otherwise as before the first of `Remote-User`/`X-Forwarded-User`/`X-Remote-User`/`X-Remote-User-Name`/`X-Remote-User-Id` (with a warning in `GET /api/diagnostics`) |

Reachable without sign-in: `POST /api/ingest` (own token, **mandatory** once
sign-in is on), `GET|HEAD /api/health` (then only `{status, version}`),
`GET|POST|DELETE /api/session`, `GET|HEAD /api/manifest` (since v3.1.0;
contains no data) and `OPTIONS`. Details:
[Security](../betrieb/sicherheit.md).

**Calendar subscription *(v3.1.0)*.** Calendar apps cannot send a header, so the
key sits in the link. So that a link in a calendar sync or a log does not open
the whole API, only a key with scope `calendar` counts there — a `read` or
`admin` key in the link gives `401`. Conversely, a calendar key opens no other
route, not even as an `Authorization` header (`401`). With a session (the
browser) or a `read`/`admin` key in the header, `/api/calendar.ics` can be
fetched like any other route.

**People and roles *(v3.2.0)*.** With sign-in, every person in the household
can have an account of their own (mode `password`: name and password; mode
`proxy`: the reported name). Admins (`admin`) may do everything; members
(`member`) record and see everything but do not change access, do not restore
data and do not change network addresses — there the app answers `403`
`errors.auth.adminOnly`. Without sign-in and with an API key there is no
person; a request may then do what it could before. Which routes only admins
reach: [People in the household](#people-in-the-household-v320).

### Stability promise *(v2.6.0)*

Whoever builds on the API — Home Assistant, scripts, own evaluations — needs a
promise about what may change. Three classes:

| Class | Scope | Promise |
|---|---|---|
| **A — interfaces for other systems** | `POST /api/ingest` (also as a batch), `GET /api/health`, `GET /api/summary` (`summary_version: 1`, v3.1.0), `GET /api/todo` (`todo_version: 1`, v3.9.0), `GET /api/calendar.ics` (shape and UIDs, v3.1.0), backup format 3.0 (`/api/backup/export`, `/api/backup/import`), CSV exports in format 1 and the CSV imports ([CSV formats](#csv-formats-v310)), master data (`meters`, `readings`, `contracts`, `deliveries`, `reminders`, `settings`, `temperatures`; since v3.1.0 `periods` including `periods.csv` and the period import, `tenancies` and `statements`), uploading and fetching receipts (`POST /api/attachments`, `GET /api/attachments/{id}`, v3.1.0), error envelope with `code` | Additive changes only. Renaming or removing only with a **major version**, announced at least one minor version earlier in the CHANGELOG under "Deprecated". Old field names stay valid as aliases. |
| **B — evaluations** | consumption, balance, forecast, tariff comparison/switch, bill check, efficiency, recommendations, PV/balance, `readings-overview`, `agenda` (v3.1.0), the tenancy budget (`tenancies/{id}/budget`, v3.1.0), CO₂ price and sharing (`co2-costs`, `co2-split`, `reports/co2-split.pdf`, v3.1.0), supplier bills (`bills` including `check` and `book`, v3.1.0), the evaluations of a meter group (`meter-groups/{id}/…`, v3.1.0), the charging record (`reports/ev-charging` as JSON, CSV and PDF, v3.1.0), the seasonal performance factor (`heat-pump`, v3.1.0), the benchmark (`benchmarks/comparison`, v3.1.0), flexibility (`flexibility`, v3.3.0), the day film (`energy-flow/day`, `energy-flow/typical-day`, `energy-flow/days`, v3.5.0) | Documented fields keep their name and meaning; new ones are added. **Values** may change when a calculation is corrected — the CHANGELOG says so. |
| **C — user interface** | `session` (since v3.4.0 including `session/logout-everywhere`), `auth/token`, `auth/keys`, `backup/snapshots`, `diagnostics`, `demo`, `countries`, the receipt list and deleting (`GET /api/attachments`, `DELETE /api/attachments/{id}`) and `ocr/reading` (v3.1.0), wholesale electricity prices (`market-prices` including import and SMARD download), monthly prices from a file (`contracts/{id}/prices/import-csv`) and time series with a column mapping (`meters/{id}/import-series`, all v3.1.0), people (`users`, `session/me`, v3.2.0), charging sessions from evcc (`import-evcc`, `sync-evcc`, `ev-sessions`, v3.2.0), quarter-hour series (`intervals`) and wholesale prices per quarter hour (`market-prices/sync-smard-intervals`, both v3.3.0), docs in the app (`docs`, `docs/asset`, v3.4.0), history of done notices (`activity`, `agenda/done`, v3.4.0) | Built for the app's own interface; changes are possible but listed in the CHANGELOG. |

Reason: v2.0.0 silently switched `verdict` from "Nachzahlung/Erstattung" to keys
(`surcharge`/`refund`/`balanced`) — without notice. That must not happen again.

---

## 1. Full route overview

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/health` | health check: `status` ok/degraded/error, checks, last ingest (HTTP 503 on `error`); also `HEAD` |
| GET | `/api/update-check` | *(v3.6.0)* is there a newer version? `{enabled, current, latest, newer, url, checked_at}` — only with the setting `update_check`, otherwise `{enabled: false, current}` without any outgoing request; the server asks GitHub at most once a day |
| GET | `/api/session` | sign-in mode, signed in?, fixed by the environment? *(v2.6.0)*; since v3.2.0 `named_login`, `user`, `role` — [People](#people-in-the-household-v320) |
| POST | `/api/session` | sign in `{name?, password}` → session cookie *(v2.6.0; `name` since v3.2.0)* |
| DELETE | `/api/session` | sign out *(v2.6.0)* |
| POST | `/api/session/logout-everywhere` | *(v3.4.0)* sign out everywhere: all sessions of the signed-in person end, on every device (including this one); API keys stay — [People](#people-in-the-household-v320) |
| POST | `/api/session/password` | set/change the password `{password, current?}` — switches sign-in on *(v2.6.0)*; since v3.2.0 admins only |
| DELETE | `/api/session/password` | switch sign-in off `{current}` *(v2.6.0)*; since v3.2.0 admins only |
| PATCH | `/api/session/me` | *(v3.2.0)* own settings of the signed-in person `{ui_level?, language?}` — [People](#people-in-the-household-v320) |
| POST | `/api/session/me/password` | *(v3.2.0)* change your own password `{current, password}` |
| GET | `/api/users` | *(v3.2.0)* people in the household, without password hash — admins only; *(v3.6.0)* with running number `no` and `has_account`, followed by the people without sign-in from the register (`has_account: false`, `source: "none"`) |
| GET | `/api/people` | *(v3.5.0)* people for the “Responsible” choice: only `[{id, name}]`, sorted by name, for every signed-in role (read keys too); *(v3.6.0)* from the [register of people](#register-of-people-v360), without deleted ones, also without sign-in switched on (up to v3.5 `[]` then) — [Responsible](#responsible-for-reminders-v350). Class C |
| POST | `/api/users` | *(v3.2.0)* add a person `{name, password, role?}`; `201` — admins only; *(v3.6.0)* response with `no`; *(v3.9.0)* with `has_account: false` a person without sign-in `{name, role?}` (no password, response as in the list with `source: "none"`); without sign-in like every setting — card “People in the household” |
| PATCH | `/api/users/{id}` | *(v3.2.0)* change name, role or password `{name?, role?, password?}` — admins only; *(v3.6.0)* also for people without sign-in (name and role; a password gives `400` `errors.users.noAccountPassword`) — [Register of people](#register-of-people-v360) |
| GET | `/api/users/{id}/handover` | *(v3.6.0)* preview for “Delete person”: `{person: {id, name, no}, to: {id, name}\|null, open, done}` — open reminders and to whom, completed history entries — admins only; *(v3.7.0)* plus `meters`, `contracts` (responsibilities that pass over). Class C |
| DELETE | `/api/users/{id}` | *(v3.2.0)* delete a person; the last admin stays — admins only; since v3.4.0 `?anonymize_activity=1` replaces their name in the history with “former member” (response `activity_anonymized`); *(v3.6.0)* open reminders pass to the person deleting, completed items keep the name with “(deleted)” (response additionally `handed_over`, `handed_over_to`, `kept`) — [Register of people](#register-of-people-v360); *(v3.7.0)* meters and contracts too (`records_handed_over`, `records_kept`) — [Responsible](#responsible-for-meters-and-contracts-v370) |
| GET | `/api/auth/keys` | API keys (without plaintext) *(v2.6.0)*; since v3.2.0 admins only |
| POST | `/api/auth/keys` | create a key `{name, scope: read\|admin\|calendar\|inbox}`; plaintext once *(v2.6.0; `calendar` since v3.1.0, `inbox` since v3.7.0)*; since v3.2.0 admins only |
| DELETE | `/api/auth/keys/{id}` | revoke a key *(v2.6.0)*; since v3.2.0 admins only |
| GET | `/api/diagnostics` | system status, write permissions, schema; since v3.4.0 `security` (sign-in mode, proxy header, host lists, `notices`: `proxy_header`, `open_without_hosts` with a suggested `host`) |
| GET | `/api/utilities` | list of utilities + configuration; since v2.13.0 each with `has_contracts`, `has_advance_payment_contracts` and `accounting_kind` (`consumption`, `feed_in`, `generation`); since v3.1.0 `meter_roles` for utilities with meter roles and `supports_bill_check` (bill check available: gas, electricity, water, district heating); since v3.5.0 `has_special_payments` (special payments on the contract: gas, electricity, water, district heating — [#34](https://github.com/Bingerminger/energietracker/issues/34)); `has_advance_payment_contracts` stays without water (balance by calendar, group contract) |
| GET | `/api/manifest` | *(v3.1.0)* web app manifest in one language (`?lang=`, otherwise the device or installation language); reachable without sign-in, contains no data. Class C |
| GET | `/api/docs` | *(v3.4.0)* one page of the documentation of this release: `?path=` (`README.md`, `en/anleitungen/kalender.md`, `root/CHANGELOG.md`) → `{path, lang, mirror, root_files, markdown}`; only from the allowlist — [Docs in the app](#docs-in-the-app-get-apidocs-v340). Class C |
| GET | `/api/docs/asset` | *(v3.4.0)* one image of the documentation (`?path=ui/screenshots/…png&v=<version>`), `image/png`; cached as immutable with the version of this release. Class C |
| GET | `/api/settings` | settings; *(v3.6.0)* plus `assumed_keys` — [Assumed values](#assumed-values-assumed_keys-v360) |
| PATCH | `/api/settings` | change settings; `frame_ancestors`, `ocr_endpoint` and `evcc_endpoint` since v3.2.0 admins only, as are `allowed_hosts` and `activity_keep_months` (v3.4.0) |
| GET | `/api/countries` | country profiles: defaults per country *(v2.7.0)*; since v3.1.0 with bill terms (`bill_terms`) and the official tariff comparison (`comparison_portal`) — see below |
| POST | `/api/settings/country` | change the country: `{"country": "AT", "profile": true}` applies the country profile, `"profile": false` only the country; only values that differ from the defaults are stored *(v3.5.0)* — see below |
| GET | `/api/settings/default-updates` | corrected defaults this installation does not use yet (CO₂, water reference) *(v2.10.0)* — see below |
| GET | `/api/temperatures` | daily temperatures (map) |
| POST | `/api/temperatures` | upsert a day |
| POST | `/api/temperatures/import-csv` | CSV import; `DD.MM.YYYY;avg;min;max`, also tab and the old double-quote format — one separator per line (v2.12.0); since v3.1.0 every export format and dates as `D/M/YYYY`, `D-M-YYYY` or ISO — [Import](#import-v310) |
| GET | `/api/geocode` | place search for the location; `?q=` (2–80 characters) — v2.12.0, see below |
| POST | `/api/temperatures/sync-open-meteo` | Open-Meteo sync; `?start=&end=&reload=1&auto=1` (v2.8.0) — see below |
| DELETE | `/api/temperatures/{date}` | delete a day |
| GET | `/api/utility/{u}/meters` | meters/tanks; *(v3.7.0)* per meter `assignee`, `assignee_id`, `assignee_former`, `assignee_deleted` — [Responsible](#responsible-for-meters-and-contracts-v370) |
| POST | `/api/utility/{u}/meters` | create; *(v3.7.0)* with `assignee_id`; since v3.1.0 with `role` and `capture` — [see below](#meters-role-capture-v310-additive) — and `malo_id`, `melo_id` ([see below](#meters-malo_id-melo_id-v310-additive)); for PV generation `plug_in`, `investment_eur`, `commissioned_on`, `battery_capacity_kwh` ([PV](#pv-battery-plug-in-solar-payback-v310-additive)), for heat `heat_pump_meter_ids` ([heat pump](#seasonal-performance-factor-of-the-heat-pump-v310)) |
| GET | `/api/utility/{u}/meters/{id}` | single |
| PATCH | `/api/utility/{u}/meters/{id}` | change; `capture` only while the meter has no data of the previous kind (v3.1.0) |
| DELETE | `/api/utility/{u}/meters/{id}` | delete; `400` while readings, deliveries, contracts or sub-meters depend on it, since v3.1.0 also periods (`errors.meter.hasPeriods`) or supplier bills (`errors.meter.hasBills`) |
| POST | `/api/utility/{u}/meters/{id}/replace-device` | meter swap |
| GET | `/api/utility/{u}/meter-groups` | meter groups (F1006) |
| POST | `/api/utility/{u}/meter-groups` | create a group |
| POST | `/api/utility/{u}/meter-groups/merge` | "Group meters": bundle several meters |
| PATCH | `/api/utility/{u}/meter-groups/{groupId}` | rename a group |
| DELETE | `/api/utility/{u}/meter-groups/{groupId}` | dissolve a group (members remain); since v3.1.0 `400` `errors.meter.groupHasContracts` while contracts are attached to the group |
| GET | `/api/utility/{u}/meter-groups/{id}/consumption` | *(v3.1.0)* monthly series of the group as the sum of its members, fields as for a meter — [Group contract](#group-contract-v310) |
| GET | `/api/utility/{u}/meter-groups/{id}/contract-status` | *(v3.1.0)* balance of the group contract, as for a meter |
| GET | `/api/utility/{u}/meter-groups/{id}/forecast` | *(v3.1.0)* forecast of the group; with unit prices per member using a blended price |
| GET | `/api/utility/{u}/meter-groups/{id}/tariff-switch` | *(v3.1.0)* switch decision for the group |
| GET | `/api/utility/{u}/meter-groups/{id}/bill-check` | *(v3.1.0)* bill check of the group: sections per member, fixed costs once |
| POST | `/api/metering-concept/preview` | *(v3.9.0)* “metering concept” assistant: preview from the layout (`concept`, `device`, `pv`, `battery`, `tou`, `slots`, `contracts`) → `{concept, slots, actions, problems}`; writes nothing, also with a read-only key — [see below](#set-up-a-metering-concept-v390-f1032) |
| POST | `/api/metering-concept` | *(v3.9.0)* create the same: meters, registers, groups, sub-meters and contracts through the existing services, with a snapshot first (`concept_…`); `201` `{concept, actions, created: {meters, groups, contracts}, snapshot}` |
| GET | `/api/utility/{u}/meter-groups/{id}/billing-periods` | *(v3.6.0)* billing periods of the group (members together) — [Billing periods](#billing-periods-v360) |
| GET | `/api/utility/{u}/meter-groups/{id}/heat-limit` | *(v3.9.0)* heating limit suggestion of the group, as for a meter |
| GET | `/api/utility/{u}/readings` | readings |
| POST | `/api/utility/{u}/readings` | create; since v3.1.0 with `client_ref` (same identifier on the same meter → `200` with `duplicate: true` instead of a second reading) and `attachment_id` (photo) — [see below](#readings-client_ref-attachment_id-v310-additive) |
| PATCH | `/api/utility/{u}/readings/{id}` | change; since v3.1.0 also `attachment_id` (`null` detaches the photo); moving it to a meter with consumption per period → `400` `errors.reading.periodMeter`; since v3.9.0 `is_verified` and `is_billing_only` — [see below](#readings-is_verified-is_billing_only-v390-additive) |
| DELETE | `/api/utility/{u}/readings/{id}` | delete (a photo is detached and cleaned up after 24 hours) |
| POST | `/api/utility/{u}/meters/{id}/readings/import-csv` | CSV bulk import; `?dry_run=1` only reads (preview, v2.12.0); since v3.1.0 headers and date formats of every language — see below |
| POST | `/api/utility/{u}/meters/{id}/import-series` | *(v3.1.0)* time series from a portal with a column mapping `{csv, mapping}`, condensed into daily values; `?dry_run=1` only reads — [Import time series](#import-time-series-v310); since v3.3.0 values at 15, 30 or 60 minute intervals additionally per quarter hour (`intervals`) |
| POST | `/api/utility/strom/meters/{id}/import-evcc` | *(v3.2.0)* charging sessions from evcc’s CSV export `{csv, counters?, loadpoint?}`, plus the wall box meter readings; `?dry_run=1` only reads — [Charging sessions from evcc](#charging-sessions-from-evcc-v320) |
| POST | `/api/utility/strom/meters/{id}/sync-evcc` | *(v3.2.0)* the same straight from evcc in the home network (`evcc_endpoint`) `{counters?, loadpoint?}`; `?dry_run=1` only reads, `502` on errors of evcc |
| GET | `/api/utility/{u}/periods` | *(v3.1.0)* consumption per period, sorted by start; `?meter_id=` only this meter — [Consumption per period](#consumption-per-period-v310) |
| POST | `/api/utility/{u}/periods` | *(v3.1.0)* create a period `{meter_id, from, to, value}` or `{meter_id, month, value}`; `client_ref` as for readings (`200` with `duplicate: true`) |
| PATCH | `/api/utility/{u}/periods/{id}` | *(v3.1.0)* change a period |
| DELETE | `/api/utility/{u}/periods/{id}` | *(v3.1.0)* delete a period |
| POST | `/api/utility/{u}/meters/{id}/periods/import-csv` | *(v3.1.0)* periods from CSV (`month;value[;note]` or `from;to;value[;note]`); `?dry_run=1` only reads |
| **GET** | **`/api/readings-overview`** | **all active cumulative meters + last reading (F1004, v1.6.0); since v3.1.0 with `capture`, `last_period` and `role`** |
| GET | `/api/utility/{u}/meters/{id}/reading-checks` | *(v3.9.0)* check intermediate readings: readings with a common offset (`checks`), readings entered twice (`duplicates`), note on the baseline date (`level_shift`) — [Check intermediate readings](#check-intermediate-readings-v390) |
| GET | `/api/utility/{u}/deliveries` | deliveries (heating oil/pellets) |
| POST | `/api/utility/{u}/deliveries` | create |
| PATCH | `/api/utility/{u}/deliveries/{id}` | change |
| DELETE | `/api/utility/{u}/deliveries/{id}` | delete |
| GET | `/api/utility/{u}/meters/{id}/stock-history` | tank stock curve; since v2.10.0 the tank log with anchors and the start of the estimate — see below |
| GET | `/api/utility/{u}/contracts` | contracts; since v3.4.0 with `phase` and `values_on` per contract ([see below](#contracts-phase-values_on-v340-additive)); *(v3.7.0)* responsibility as on the meter |
| POST | `/api/utility/{u}/contracts` | create; *(v3.7.0)* with `assignee_id`; since v3.1.0 district heating with capacity and metering charges, response possibly with `warnings` — [see below](#contracts-district-heating-fixed-costs-warnings-v310-additive); for a meter group with `meter_group_id` ([Group contract](#group-contract-v310)); electricity with `grid_reduction`, price model `price_model`/`dynamic`, feed-in with `revenue_statements` ([see below](#contracts-reduced-grid-fee-price-model-credit-notes-v310-additive)); since v3.3.0 electricity with `grid_tou` ([time-variable grid fee](#contracts-time-variable-grid-fee-module-3-v330-additive)) |
| GET | `/api/utility/{u}/contracts/{id}` | single; since v3.4.0 with `phase` and `values_on` |
| PATCH | `/api/utility/{u}/contracts/{id}` | change; as when creating |
| DELETE | `/api/utility/{u}/contracts/{id}` | delete |
| POST | `/api/utility/{u}/contracts/{id}/prices/import-csv` | *(v3.1.0)* monthly prices from a file (`month;ct_kwh[;standing_charge]`), sets `price_model: "monthly"`; `?dry_run=1` only reads |
| GET | `/api/market-prices` | *(v3.1.0)* wholesale electricity prices (day-ahead DE/LU) as monthly averages, with attribution — [Wholesale prices](#wholesale-electricity-prices-and-dynamic-tariff-check-v310) |
| POST | `/api/market-prices/import-csv` | *(v3.1.0)* wholesale prices from a file (SMARD download or `YYYY-MM;€/MWh`); `?dry_run=1` only reads |
| POST | `/api/market-prices/sync-smard` | *(v3.1.0)* fetch the monthly values from SMARD — only on request |
| POST | `/api/market-prices/sync-smard-intervals` | *(v3.3.0)* fetch a year's wholesale prices per quarter hour from SMARD, `?year=` (default previous year) — only on request, `502` on SMARD errors — [Flexibility](#valuing-flexibility-v330) |
| GET | `/api/utility/{u}/consumption` | monthly consumption (utility-wide) |
| GET | `/api/utility/{u}/meters/{id}/consumption` | consumption + anomalies + regressions |
| GET | `/api/utility/{u}/meters/{id}/contract-status` | balance per contract; since v2.5.1 with `special_payments[]` (items, gas/electricity/district heating, since v3.5.0 water too); since v2.8.0 by calendar up to today, since v2.9.0 day-exact with the cancellation deadline, since v3.4.0 with `phase` — see below — and `min_term_end`, `price_guarantee_until`, `auto_renews` ([Explainers](#explainers-energy-flow-and-timeline-v340-additive)) and `successor` ([follow-on contract](#done-with-history-v340)) |
| GET | `/api/utility/{u}/meters/{id}/forecast` | forecast with uncertainty band, climate normal and warnings (v2.8.0); since v3.1.0 CO₂ price scenario `?co2_scenario_eur_t=&co2_scenario_from=` — see below |
| POST | `/api/forecast/scenario` | *(v3.6.0)* forecast workshop: calculate a scenario against the baseline (winter, room temperature, measures, tariff, model, budget); writes nothing — [see below](#post-apiforecastscenario--forecast-workshop-v360-f1031-additive) |
| GET | `/api/utility/{u}/meters/{id}/tariff-comparison` | tariff comparison real vs. shadow (retrospective); since v3.3.0 a dynamic shadow contract uses your own load profile where quarter hours exist (`dynamic_profile_months`) — [dynamic tariff check](#wholesale-electricity-prices-and-dynamic-tariff-check-v310) |
| GET | `/api/utility/{u}/meters/{id}/tariff-switch` | switching decision from the switch date; optional `?switch_date=YYYY-MM-DD` |
| GET | `/api/utility/{u}/meters/{id}/bill-check` | bill verification: sections per reading and calorific-value change or price date, with costs, `?from=&to=` (F1012; up to v3.0 gas only, since v3.1.0 gas, electricity, water, district heating, otherwise 400) — [see below](#get-apiutilityumetersidbill-checkfromto-f1012-v250-all-utilities-since-v310) |
| GET | `/api/utility/{u}/meters/{id}/billing-periods` | *(v3.6.0)* billing periods of the meter from bills and settlement date, with quantity, cost, advance payments, balance; `?months=1` with a monthly series — [Billing periods](#billing-periods-v360) |
| GET | `/api/utility/{u}/billing-periods` | *(v3.6.0)* the same for the whole utility (meters without a parent meter, without consumption per period) |
| GET | `/api/utility/{u}/meters/{id}/heat-limit` | *(v3.9.0)* heating limit suggestion of the meter `{heat_limit}` as with `…/consumption?heat_limit=1`, with a suggestion also `effect` (balance and advance payment of the current contract before and after) — [Heating limit suggestion](#heating-limit-suggestion-v390) |
| GET | `/api/utility/{u}/bills` | *(v3.1.0)* supplier bills, the newest first; `?meter_id=` only this meter — [Supplier bills](#supplier-bills-v310) |
| POST | `/api/utility/{u}/bills` | *(v3.1.0)* record a bill; `201` |
| PATCH | `/api/utility/{u}/bills/{id}` | *(v3.1.0)* change a bill |
| DELETE | `/api/utility/{u}/bills/{id}` | *(v3.1.0)* delete a bill; receipts are released, a booked special payment stays in the contract |
| GET | `/api/utility/{u}/bills/{id}/check` | *(v3.1.0)* own calculation against the supplier’s: difference, verdict, reasons |
| POST | `/api/utility/{u}/bills/{id}/book` | *(v3.1.0)* book the result as a special payment in the contract (once per bill) |
| GET | `/api/benchmarks/efficiency` | efficiency class per heat source; since v2.10.0 with coverage and the certificate-style figure — see below |
| GET | `/api/benchmarks/comparison` | *(v3.1.0)* benchmark of the annual consumption against your own reference values (household electricity, heating per m²; since v3.6.0 with traffic light `rating`, water per person and missing details `missing`), `?year=` (default previous year) — [Benchmark](#benchmark-get-apibenchmarkscomparison-v310) |
| GET | `/api/heat-pump` | *(v3.1.0)* seasonal performance factor per heat pump: heat ÷ electricity per month and year, `?year=` (default previous year) — [Heat pump](#seasonal-performance-factor-of-the-heat-pump-v310) |
| GET | `/api/flexibility` | *(v3.3.0)* flexibility of a year from consumption and wholesale price per quarter hour: profile factor and advantage per electricity meter, battery, § 14a module 3; *(v3.6.0)* plus `insights` — traffic light, levers, time windows, solar power; `?year=` (default previous year), *(v3.6.0)* `?summary=1` only the result card’s number — [Flexibility](#valuing-flexibility-v330) |
| GET | `/api/intervals` | *(v3.3.0)* index of the quarter-hour series (consumption per meter, wholesale prices) |
| DELETE | `/api/intervals/{id}` | *(v3.3.0)* delete one quarter-hour series; meter readings and daily values stay |
| GET | `/api/energy-flow/days` | *(v3.5.0)* days and months with quarter-hour values of grid import, defaults for the day film — [Day film](#day-film-energy-flow-per-quarter-hour-v350) |
| GET | `/api/energy-flow/day` | *(v3.5.0)* energy flow of one day per quarter hour, `?date=YYYY-MM-DD` — [Day film](#day-film-energy-flow-per-quarter-hour-v350) |
| GET | `/api/energy-flow/typical-day` | *(v3.5.0)* typical day of a month (average per quarter hour), `?month=YYYY-MM` — [Day film](#day-film-energy-flow-per-quarter-hour-v350) |
| GET | `/api/recommendations` | statistical recommendations |
| POST | `/api/recommendations/{id}/dismiss` | hide a recommendation; `{until?}`, otherwise 30 days — since v3.4.0 with a deadline (`evidence.deadline`) at least until then; the response also has `until` and `activity_id` |
| DELETE | `/api/recommendations/{id}/dismiss` | undo hiding (v2.12.0); an ID that is not hidden is not an error |
| GET | `/api/reminders` | appointments + due status; since v3.4.0 `last_done_by` (who did it last, from the history); since v3.5.0 per reminder `assignee`, `assignee_id`, `assignee_former` — [Responsible](#responsible-for-reminders-v350); *(v3.7.0)* `assignee_inherited` (the meter’s person when the reminder has none of its own) |
| POST | `/api/reminders` | create; since v3.5.0 optional `assignee` (name) and `assignee_id` (person); *(v3.7.0)* `utility` and `meter_id` (reminder on a meter) |
| PATCH | `/api/reminders/{id}` | change; since v2.12.0 also `last_done` (date or `null`) — for "Undo" after "Done"; since v3.5.0 `assignee`/`assignee_id` |
| DELETE | `/api/reminders/{id}` | delete |
| POST | `/api/reminders/{id}/done` | done, roll the recurrence forward; `{done_date?, note?, attachment_id?}` — since v3.4.0 with a history entry, the response also has `activity_id` |
| GET | `/api/tenancies` | *(v3.1.0)* tenancies, the most recent first — [Tenancy](#tenancy-v310) |
| POST | `/api/tenancies` | *(v3.1.0)* create a tenancy |
| PATCH | `/api/tenancies/{id}` | *(v3.1.0)* change; lists (`prepayments`, `prices`, `fixed_costs`) always whole |
| DELETE | `/api/tenancies/{id}` | *(v3.1.0)* delete together with its statements; their receipts are released |
| GET | `/api/tenancies/{id}/budget` | *(v3.1.0)* estimate for the current billing period: prepayment against expected costs; `?as_of=YYYY-MM-DD` |
| GET | `/api/tenancies/{id}/statements` | *(v3.1.0)* service charge statements, the newest first |
| POST | `/api/tenancies/{id}/statements` | *(v3.1.0)* record a statement; `apply_prices`, `apply_prepayment` take over prices and the new prepayment |
| PATCH | `/api/tenancies/{id}/statements/{sid}` | *(v3.1.0)* change a statement |
| DELETE | `/api/tenancies/{id}/statements/{sid}` | *(v3.1.0)* delete a statement; its receipts are released |
| GET | `/api/co2-costs` | *(v3.1.0)* CO₂ price in fuel per utility, `?year=` (default: previous year) — [CO₂ price and sharing](#co₂-price-and-sharing-v310) |
| GET | `/api/co2-split` | *(v3.1.0)* CO₂ costs between tenant and landlord (CO2KostAufG), `?year=` |
| GET | `/api/reports/yearly.pdf` | PDF annual report (file download; `?inline=1` shows it in the browser, v2.11.0) |
| GET | `/api/reports/yearly` | Annual report as data for the print view (v3.1.0) |
| GET | `/api/reports/co2-split.pdf` | *(v3.1.0)* letter “Refund of the landlord’s share” or check result as a PDF, `?year=`, `?inline=1` |
| GET | `/api/reports/ev-charging` | *(v3.1.0)* charging record for a company car per month, `?meter_id=&year=&method=contract\|flat[&flat_ct=]` — [Charging record](#charging-record-v310) |
| GET | `/api/reports/ev-charging.csv` | *(v3.1.0)* the same as CSV; `format`, `lang` as for the CSV exports |
| GET | `/api/reports/ev-charging.pdf` | *(v3.1.0)* the same as PDF with meter readings and a signature line; `?inline=1` |
| GET | `/api/ev-sessions` | *(v3.2.0)* stored charging sessions from evcc, newest first, `?meter_id=&year=`; with both parameters also monthly totals `monthly` — [Charging sessions from evcc](#charging-sessions-from-evcc-v320) |
| GET | `/api/agenda` | *(v3.1.0)* deadlines and dates of the next `?days=` days (default 90) plus anything overdue; source of “To do” on the dashboard — [Agenda](#get-apiagendadays90-v310); since v3.4.0 per event `occurrence`, `can_mark_done`, `done`, `title_key` |
| POST | `/api/agenda/done` | *(v3.4.0)* tick off a notice `{uid, occurrence?, done_on?, note?, attachment_id?, cancelled?}`; `201` with `{activity_id, entry, event}`, already done `200` with `duplicate: true` — [Done with history](#done-with-history-v340). Class C |
| GET | `/api/activity` | *(v3.4.0)* history of done notices, newest first; `?from=&to=` (day it was done), `?by=` (person ID or `person`, `key`, `integration`, `auto`, `none`), `?kind=` (comma-separated), `?ref=` (an identifier, for example a contract ID), `?limit=` (default 500, at most 5000). Class C |
| DELETE | `/api/activity` | *(v3.4.0)* clear the history: entries done before `?before=YYYY-MM-DD`, without a date all of them → `{deleted}` — admins only |
| GET | `/api/activity.csv` | *(v3.5.0)* the history as CSV for proof: filters as `GET /api/activity`, `?format=1\|local&lang=`; columns date, action, title, by, reason, note; without struck-through entries — [History as CSV](#history-as-csv-v350). Class C |
| POST | `/api/activity/{id}/undo` | *(v3.4.0)* undo: strikes through (`undone {at, by}`) and restores what the entry changed → the entry with `reverted` |
| PATCH | `/api/activity/{id}` | *(v3.4.0)* change the note `{note}` (up to 500 characters) |
| GET | `/api/calendar.ics` | *(v3.1.0)* the same events as a calendar subscription (iCalendar, 365 days) — [Calendar](#get-apicalendarics-v310); *(v3.7.0)* `?person=<id>` only what is assigned to this person |
| GET | `/api/summary` | *(v3.1.0)* key figures per meter for Home Assistant and scripts, `?utility=&meter=` — [Summary](#get-apisummary-v310) |
| GET | `/api/todo` | *(v3.9.0)* the “To do” list for a sensor: `count`, `overdue`, `next`, `items` — [To do](#get-apitodo-v390) |
| GET | `/api/homeassistant/notify` | *(v3.9.0)* state of the notifications to Home Assistant, without webhook ID and token — [Notifications](#notifications-to-home-assistant-v390). Class C |
| PATCH | `/api/homeassistant/notify` | *(v3.9.0)* save and switch on the notifications (admins only) |
| POST | `/api/homeassistant/notify/test` | *(v3.9.0)* test message to the destination set, `{ok, status, error, target}` (admins only) |
| GET | `/api/attachments` | *(v3.1.0)* receipts: index and storage use `usage` — [Receipts](#receipts-and-text-recognition-v310) |
| POST | `/api/attachments` | *(v3.1.0)* upload a receipt: the file as raw body, `?kind=reading_photo` (or `bill_pdf`, `statement_pdf`, `other`), optional `&name=`; `201` with the index entry |
| GET | `/api/attachments/{id}` | *(v3.1.0)* the file of a receipt (photo, PDF) |
| DELETE | `/api/attachments/{id}` | *(v3.1.0)* delete a receipt; the reference on the record goes with it |
| POST | `/api/ocr/reading` | *(v3.1.0)* read the meter value from a reading photo `{attachment_id}` — through your own text recognition service in the home network, `502` on errors of the service |
| GET | `/api/inbox` | *(v3.7.0)* inbox: documents, queue, provider — [Inbox](#inbox-v370) |
| POST | `/api/inbox` | *(v3.7.0)* file a PDF (raw body, `?name=`, or JSON `{name?, pdf_base64}`); `409 errors.inbox.duplicate` for the same checksum; also with an “inbox” key |
| GET | `/api/inbox/review` | *(v3.7.0)* check list: only the flagged fields |
| GET | `/api/inbox/draft` | *(v3.7.0)* household from documents: one draft across all read documents (`?documents=` selection) — [Draft](#household-from-documents-v370) |
| POST | `/api/inbox/draft/apply` | *(v3.7.0)* take over a selection from the draft `{items, confirm?, choose?, documents?}` |
| POST | `/api/inbox/folder` | *(v3.7.0)* read the watched folder `posteingang/` (admins only) — [folder](#folder--post-apiinboxfolder) |
| POST | `/api/inbox/queue` | *(v3.7.0)* queue documents for reading `{ids?}` |
| DELETE | `/api/inbox/queue` | *(v3.7.0)* cancel the queue |
| GET | `/api/inbox/cloud` | *(v3.7.0)* reading in the cloud: state and providers (name, destination host, notice), never the key — only `has_key` — [Reading in the cloud](#get-apiinboxcloud--patch-apiinboxcloud-v370) |
| PATCH | `/api/inbox/cloud` | *(v3.7.0)* save and switch on reading in the cloud (admins only) |
| GET | `/api/inbox/cloud/models` | *(v3.7.0)* models of the cloud service that is set up, the key stays on the server (admins only) — [Model list](#get-apiinboxcloudmodels--get-apiinboxlocalcheck-v370-reading-documents-card) |
| GET | `/api/inbox/local/check` | *(v3.7.0)* “Check connection” for your own service in the home network (admins only) |
| GET | `/api/inbox/{id}` | *(v3.7.0)* preview: reading with quotes, assignment, proposals compared with your data |
| PATCH | `/api/inbox/{id}` | *(v3.7.0)* assignment, corrections (`edits`), type, status, note |
| DELETE | `/api/inbox/{id}` | *(v3.7.0)* delete a document |
| POST | `/api/inbox/{id}/classify` | *(v3.7.0)* recognise the type `{pages, consent?, fresh?}` |
| POST | `/api/inbox/{id}/extract` | *(v3.7.0)* read and check the fields `{pages, consent?, fresh?}` |
| POST | `/api/inbox/{id}/apply` | *(v3.7.0)* take over the ticked proposals `{proposals: [id]}`; afterwards the key message (`gist`), for a bill with its check |
| POST | `/api/inbox/{id}/remind` | *(v3.7.0)* reminder for a filed document (payment reminder) `{date}` |
| GET | `/api/utility/{u}/dossier` | *(v3.7.0)* file of a meter (`?meter_id=`) or contract (`?contract_id=`) — [file](#file--get-apiutilityudossier) |
| GET | `/api/export/{u}/monthly.csv` | monthly aggregates as CSV; `?format=1` (default) or `?format=local&lang=` *(v3.1.0)* — [CSV formats](#csv-formats-v310); *(v3.6.0)* `?period=billing`: one row per billing period ([Billing periods](#billing-periods-v360)) |
| GET | `/api/export/{u}/readings.csv` | readings as CSV (cumulative); `format`, `lang` as above |
| GET | `/api/export/{u}/deliveries.csv` | **v1.4.2** deliveries as CSV (heating oil/pellets); `format`, `lang` as above |
| GET | `/api/export/{u}/periods.csv` | *(v3.1.0)* consumption per period as CSV, all meters of the utility; `format`, `lang` as above |
| GET | `/api/export/temperatures.csv` | temperature series as CSV; `format`, `lang` as above |
| GET | `/api/backup/export` | full backup JSON; since v3.1.0 with the receipts (`attachment_files`), `?attachments=0` without the files — [see below](#snapshots-and-import-v260); since v3.3.0 always with the quarter-hour series (`interval_files`) |
| POST | `/api/backup/import` | restore a backup; `?dry_run=1` only checks, `?allow_without_snapshot=1` see 409; since v3.2.0 admins only |
| POST | `/api/backup/snapshot` | place a snapshot |
| GET | `/api/backup/snapshots` | snapshots: name, size, time, occasion *(v2.6.0)* |
| GET | `/api/backup/snapshots/{name}` | download a snapshot (file) *(v2.6.0)* |
| POST | `/api/backup/snapshots/{name}/restore` | restore a snapshot (saving the current state first) *(v2.6.0)*; since v3.2.0 admins only |
| DELETE | `/api/backup/snapshots/{name}` | delete a snapshot *(v2.6.0)*; since v3.4.0 admins only |
| GET | `/api/strom-saldo` | electricity balance (import − PV feed-in), F1005 |
| GET | `/api/pv-summary` | PV self-consumption + self-sufficiency rate, F1005; since v2.10.0 over jointly covered months, with savings — see below; since v3.1.0 battery, payback, the assumption for plug-in solar and the § 51 EEG note ([PV](#pv-battery-plug-in-solar-payback-v310-additive)); since v3.4.0 the energy flow per month and year (`flow`) ([Explainers](#explainers-energy-flow-and-timeline-v340-additive)) |
| GET | `/api/demo/status` | demo data available/store empty? (F1007); since v3.2.0 with `personas` — [Example households](#example-households-v320) |
| POST | `/api/demo/import` | load the demo dataset (F1007); since v3.0.0 carried forward to today (readings, deliveries, temperatures as in the same period a year earlier, reminders relative to today); since v3.2.0 `{persona}` for an example household, admins only |
| GET | `/api/auth/token` | API token status (never the token itself), F1009; since v3.2.0 admins only |
| POST | `/api/auth/token` | generate a token (one-time plaintext), F1009; since v3.2.0 admins only |
| DELETE | `/api/auth/token` | revoke the token → API open again, F1009; since v3.2.0 admins only |
| **POST** | **`/api/ingest`** | **idempotent meter-reading push for Home Assistant (F1009); since v3.1.0 also as a batch of up to 500 readings; since v3.3.0 with `timestamp` for consumption per quarter hour** |

---

## 2. Selected endpoints in detail

### `GET /api/readings-overview` *(F1004, v1.6.0)*

The aggregate endpoint for the central meter-reading capture
(`#/zaehlerstaende`). Delivers in one round trip all active meters of the
cumulative utilities (gas/electricity/water/district heating) plus each one's last
real (non-planned) reading as a validation baseline. Delivery utilities (heating
oil/pellets) are excluded — there are no meter readings there, but deliveries.

```json
{
  "success": true,
  "data": {
    "rows": [
      {
        "utility": "gas",
        "utility_label": "Gas",
        "utility_icon": "🔥",
        "unit": "m³",              // unit of the METER READING (since v2.4.2)
        "consumption_unit": "kWh", // unit of the CONSUMPTION
        "color": "#f59e0b",
        "meter_id": "m_gas_main",
        "meter_name": "Main meter gas",
        "meter_icon": "🔥",
        "meter_notes": "Cellar",
        "active_device_id": "d_gas_1",
        "last_reading": {
          "date": "2026-04-15",
          "counter": 12345.67,
          "is_estimated": false,
          "id": "20260415-3f2a9c1b",   // since v2.6.0: "already a reading today → replace"
          "device_id": "d_gas_1"       // since v2.6.0: other device → no decrease
        },
        "expected_next_min": 12345.67,
        "typical_per_day": 4.2,        // since v2.6.0: median of the last ≤ 10 intervals, null with < 2
        "suspect_count": 0,            // since v2.6.0: unconfirmed suspect readings (Home Assistant)
        "reading_count": 41,           // since v2.13.0: number of real readings (no planned or suspect ones)
        "capture": "counter",          // since v3.1.0: "counter" (meter readings) or "period"
        "last_period": null,           // since v3.1.0: last period of a period meter
        "role": null                   // since v3.1.0: role of the meter, null for utilities without roles
      },
      {
        "utility": "waerme",
        "utility_label": "Heat",
        "unit": "kWh",
        "consumption_unit": "kWh",
        "meter_id": "m_waerme_1",
        "meter_name": "Heat meter",
        "last_reading": null,
        "reading_count": 0,
        "capture": "period",
        "last_period": { "id": "p_3c9a…", "from": "2026-09-01", "to": "2026-09-30",
                         "value": 412.0, "value_unit": "consumption" },
        "role": "consumption",
        "…": "…"
      }
    ]
  }
}
```

**Since v3.1.0**, additively: `capture` (the meter's way of recording,
`counter` or `period`), `last_period` (`{id, from, to, value, value_unit}` of the
period with the latest end, otherwise `null`) and `role` (the meter's role;
`null` for utilities without roles — gas, district heating, PV feed-in). A meter
with `capture: "period"` has no readings: the capture view shows a card with
month and consumption for it and saves via `POST /api/utility/{u}/periods`
([Consumption per period](#consumption-per-period-v310)).

**Since v2.6.0** suspect readings (`is_suspect`) do not count as
`last_reading` — a Home Assistant push of 0 would otherwise become the baseline
of the next capture. `typical_per_day` backs the question "That would be
400 kWh a day, usually it is 8". `reading_count` (since v2.13.0) counts the
readings a consumption can come from: the setup checklist and the empty states
ask whether there are two yet.

**`unit` versus `consumption_unit` (since v2.4.2, GitHub #21).** A gas meter
counts cubic metres; kWh only comes into being through the conversion factor.
`unit` is the unit of `counter` and `expected_next_min`, `consumption_unit` that
of the consumption derived from them. Up to v2.4.1 `unit` was missing from the
response, and the reading-capture view labelled the gas meter reading "kWh" —
while it was always stored and calculated in m³. Among the cumulative utilities
gas is the **only** one where the two units differ — electricity, district
heating and PV count in kWh, water in m³, each identical to its consumption
unit. That is why the defect only ever showed for gas.

`expected_next_min` is the value against which the frontend validation warns about
a backward meter reading (not hard-blocked — a meter swap is legitimate). Saving is
**not** done via this endpoint, but per row via the existing route
`POST /api/utility/{u}/readings`.

### `GET /api/utility/{u}/meters/{id}/consumption`

Monthly aggregates of a meter including regressions and anomalies. Fields per month
include: `ym`, `days`, `kwh` *or* `m3`, `cost`, `avg_temp`, `hdd`, `temp_days`,
`co2_kg`, `advance_eur`, `monthly_balance`, `cumulative_balance` as well as
smoothings (`ma3`/`ma6`/`ma12`).

**Since v3.1.0:**

- Water meters with the role `warm` additionally carry, per month, `dhw_kwh` —
  the heat for this hot water as a calculated value under HeizkostenV § 9 (2),
  `2.5 × m³ × (dhw_temp_c − 10)`, 1 m³ at 60 °C = 125 kWh — and `dhw_temp_c`
  (setting `warmwasser_temp_c`, default 60). Not a measurement; meters without
  this role stay unchanged.
- A meter with `capture: "period"` calculates from its periods instead of
  readings: value ÷ days of the period, spread over the months to the day; gaps
  stay gaps (`days` only counts covered days). The fields are the same, and so
  are contracts, weather adjustment and forecast.
- Heat (`waerme`) is HDD-relevant like gas and district heating; `co2_kg` uses
  the factor of the energy source in `waerme_energietraeger` (0 if not set).
- Electricity with a reduced grid fee (§ 14a EnWG, module 1) carries
  `grid_reduction_eur`; `base_price_eur` is then already reduced by it
  ([Contracts](#contracts-reduced-grid-fee-price-model-credit-notes-v310-additive)).
- Feed-in with credit notes from the direct marketer carries `revenue_source`
  (`statement` or `mixed`).
- A meter that is a member of a group with a group contract calculates its
  share of it; the ID of a group returns the sum of the members
  ([Group contract](#group-contract-v310)).
- PV generation and feed-in calculate `co2_kg` with your own avoidance factor
  `co2_pv_avoided` when it is set.

**Since v3.7.0 (GitHub #37, #38, #39), additive** — measured or interpolated
([Basics §1](../verstehen/00-overview.md#measured-or-interpolated-since-v370)):

| Field per month | Meaning |
|---|---|
| `measured_share` | share of the month's consumption from reading intervals of up to 45 days (0 … 1); estimated readings do not measure |
| `interpolated` | `true` when `measured_share` is below 0.5: the value is spread evenly from a longer interval. Heating oil and pellets: `null` |
| `reading_gap_days` | longest reading interval the month draws from (`null` when a real reading is missing on one side) |
| `reading_gap_estimated` | *(v3.7.0)* the month is interpolated with an estimated reading as a support point (each side has its own daily rate) |

Interpolated months feed no anomaly, no recommendation R1/R3, no heating curve
(`regression_point: false`) and no heating model; the monthly values, costs,
advance payments and the balance stay unchanged. The utility's `monthly_total`
carries the same fields, weighted by consumption. In addition at the top level:

```json
"interpolation": {                 // null for heating oil and pellets
  "max_gap_days": 45,
  "months": 36, "interpolated_months": 36, "interpolated": ["2023-06", "…"],
  "readings": 12, "readings_by_year": { "2023": 3, "2024": 4, "2025": 4, "2026": 1 },
  "from": "2023-06", "to": "2026-05",
  "heat_basis": "intervals",        // months | intervals | null (no heating model)
  "heat_points": 11
},
"anomaly_quality": {               // null without a heating model or below 5 checked months
  "model_quality": "poor",          // good | poor (one of the checks in reasons)
  "reasons": ["scatter", "base_load"],
  "ratio": 0.31, "poor_above": 0.15, // resid_scale / mean_month
  "resid_scale": 210.4, "mean_month": 678.7, "typical": 680.0,
  "base_load": {                    // null below 2 measured summer months
    "model_per_day": 0.4, "measured_per_day": 6.1, "ratio": 0.066,
    "allowed": [0.5, 1.5], "months": 4
  },
  "threshold_sigma": 2.0,
  "detect_floor": 420.8, "unit": "kWh",
  "months": 29
}
```

`readings` counts real readings that enter the calculation (without estimated
and skipped ones), for meters with periods the real periods. `heat_basis:
"intervals"`: below 8 measured months, the heating model works with the
intervals between real readings ([Basics §5](../verstehen/00-overview.md#heating-model)).
`detect_floor` is the deviation a typical month needs at least to be reported
([Basics §9](../verstehen/00-overview.md#9-anomalies)).

**Since v3.7.0 (check against real data), all additive:**

| Where | Field | Meaning |
|---|---|---|
| `…/meters/{id}/consumption?heat_limit=1` | `heat_limit` | heating limit suggestion of the meter: `{current, current_ratio, current_ok, best, best_ratio, suggest, default, own, months, grid: [{base, ratio, base_load_ok, summer_ratio, summer_ok}]}`, `null` without a heating model, below 12 measured months or without the parameter ([Basics §5](../verstehen/00-overview.md#heating-limit-suggestion-since-v370)). *(v3.7.1)* `summer_ratio`: expectation ÷ measured summer (the same months for every limit), `summer_ok`: at most 1.10 — a condition for a suggestion |
| `anomaly_quality.base_load` | `reference`, `reference_months` | *(v3.7.1)* where the summer months of the base-load check come from: `no_hdd_top` (no degree days at 18 °C) or `window` (June to August), and which they are |
| `monthly[]` | `hdd_top` | *(v3.7.1)* degree days of the month at 18 °C, independent of the heating limit (summer reference) |
| `GET …/meters/{id}/forecast` | `?hdd_base=` | *(v3.7.1)* heating limit for this forecast (6 to 22 °C) as with `…/consumption`, without storing it on the meter; outside: 400 |
| `PATCH …/meters/{id}`, meter | `hdd_base_temp` | this meter's own heating limit in °C (6 to 22, only heating types; up to v3.7.1 only with meter readings, since v3.9.0 also tanks and groups); `null` or empty removes it, then the setting applies |
| `monthly[]` | `heat_adjusted_basis` | why `heat_adjusted` is what it is: `model`, `summer`, `base_load`, `normal_is_actual` (or `null`) |
| `regressions.*`, `forecast.regression` | `reason` | with `valid: false`: `too_few_points`, `poor_fit`, `degenerate` |
| `…/contract-status` | `projection_uncertain` | `{reasons: [factor_clamped \| few_measured \| poor_fit], factor, factor_raw, factor_part, factor_limits, fit_ratio, base_load_per_day, projected_range, suggested_range}` or `null`; then `suggested_advance` is `null`. `factor_part`: which factor is clamped (`base`, `heat`, `both`); `base_load_per_day`: `{model, measured}` when the range uses the measured summer base load; `suggested_range` is `null` if both ends would be equal |
| `…/contract-status` | `projection_factor_base`, `projection_factor_heat` | calibration separately for base load and heating share (0.5–1.5; `projection_factor_heat` `null` without a heating model); since v3.7.0 `projection_factor` is the effective factor |
| `…/contract-status` | `superseded_on` | last day before the follow-up contract that supersedes this contract (otherwise `null`); `end` stays the stored end |
| `…/contract-status`, `…/billing-periods` | `price_missing` | contract without a unit price: costs and balance without consumption costs |
| `…/forecast` | `fit_quality` | `{model_quality, ratio, reasons}` of the quality gate (or `null`); with `poor` `annual.low/high = null`, `annual.withheld = "poor_fit"`, months without a band |
| `…/forecast` | `annual.withheld` | why the year has no band: `poor_fit`, `few_measured` (heating model only over the reading intervals, or unchecked), `few_years` (without a weather reference fewer than 3 degrees of freedom of the spread) |
| `…/forecast` | `warnings[]` | new `poor_fit {ratio, reasons}`, `few_measured {heat_basis}`, `regression_invalid {model, reason, n, r2}`, `climate_normal_grid {base, min, max, hdd_source}` |
| `POST /api/forecast/scenario` | `advance.uncertain` | with a failed quality gate or too little measured `{reasons: [few_measured \| poor_fit], fit_ratio, spread, base_range, scen_range}`, then `base_/scen_recommended: null`; `warnings[]` new `poor_fit`, `few_measured`, `basis.heat_share_year: null` |

### Heating limit suggestion *(v3.9.0)*

`GET /api/utility/{u}/meters/{id}/heat-limit` (also `…/meter-groups/{id}/heat-limit`)
answers `{heat_limit}` in the form of `…/consumption?heat_limit=1`, without the rest
of the consumption response. New and additive ([Basics §5](../verstehen/00-overview.md#heating-limit-suggestion-since-v370)):

| Field | Meaning |
|---|---|
| `best_smoothed` | smoothed spread of the suggested limit (mean of all limits up to 1 °C either side); the choice uses it, `best_ratio` is the spread the quality gate shows with that limit |
| `range` | `[from, to]`: adjoining limits that fit equally well (at most 15 % above the best smoothed spread); the suggestion is the one closest to today's limit |
| `before`, `after` | `{ratio, model_quality, base_per_day, model_per_day, measured_per_day}` today and with the suggested limit |
| `grid[]` | plus `fit_n`, `fit_dropped` (dropped months more than 10 % of a typical month off), `fit_ok` (at most one in ten, at least one), `warm_ratio`, `warm_ok` (expectation ÷ consumption May to September, at most 1.10), `smoothed` |
| `effect` | only with a suggestion: `{contract_id, until, current_advance, before, after}`, each `{balance, balance_range, advance, advance_range, uncertain}` of the current contract today and with the suggested limit; `null` without a current contract |

More fields since v3.9.0: `monthly[].expected_base` (the base load part `c × days`),
`anomaly_quality.base_load.base_per_day` and `base_ratio` (the base load `c` alone
against the measured summer: the lower bound 0.5 of the check), `anomaly_quality.warm`
(`{ratio, months}` for May to September), `anomalies[].pattern` and `pattern_years`
(the same calendar month reported in the same direction in at least two years and on
the same side in every checked year: a pattern, not an event), `hdd_base_temp` also on
tanks (heating oil, pellets), when creating a meter and on meter groups
(`PATCH …/meter-groups/{id}`). The agenda knows the kind `heat_limit` (recommendation of
category `modell`, `ref` with `best`, `current`, `range`, `before`, `after`, `effect`).

### Check intermediate readings *(v3.9.0)*

`GET /api/utility/{u}/meters/{id}/reading-checks` — the finding for a meter with
meter readings (method and thresholds: [Basics §1](../verstehen/00-overview.md#check-a-reading-since-v390)).
Empty for periods, groups and delivery types.

```jsonc
{ "checks": [ {
    "uid": "reading_check-gas-m_gas_main-2025-01-01", "kind": "offset",
    "date": "2025-01-01", "until": "2025-03-01", "count": 3,
    "reading_ids": ["r1", "r2", "r3"], "direction": "low", "offset": 250.0, "unit": "m³",
    "basis": "heat_model",
    "before": { "from": "2024-10-15", "to": "2025-01-01", "days": 78, "measured": 108.0, "expected": 358.0, "ratio": 0.3 },
    "after":  { "from": "2025-03-01", "to": "2025-05-25", "days": 85, "measured": 489.0, "expected": 239.0, "ratio": 2.04 },
    "suggested": [ { "id": "r1", "date": "2025-01-01", "counter": 4210.0, "suggested": 4460.0 } ] } ],
  "duplicates": [ { "uid": "reading_duplicate-gas-m_gas_main-2025-03-01", "date": "2025-03-01",
    "counter": 4890.0, "unit": "m³", "keep_id": "r7", "delete_ids": ["r8"] } ],
  "level_shift": null, "basis": "heat_model", "unit": "m³", "billing_only": [] }
```

| Field | Meaning |
|---|---|
| `checks[]` | a reading or a block of consecutive readings (`count`, `reading_ids`) where the interval before (`before`) and the one after (`after`) deviate clearly from the expectation in opposite directions and one common offset explains both. `direction`: `low` (the readings are probably too low) or `high`; `offset` in the meter unit; `measured`, `expected` per interval in the meter unit, `ratio` = measured ÷ expected; `suggested[]` the reading that would fit |
| `basis` | where the expectation comes from: `heat_model` (heating model over the reading intervals), `mean_rate` (mean daily amount half a year before and after), `other_years` (the same days in other years); `null`: no check (too few intervals, charging point) |
| `duplicates[]` | the same reading on the same day more than once: `keep_id` (with a photo, otherwise with a note, otherwise the first), `delete_ids` |
| `level_shift` | note on the baseline date or `null`: `{uid, date, from, direction, factor, days, months, basis}`. `direction: "higher"` means: from `from` to `date` consumption was consistently `factor` times today’s |
| `billing_only` | IDs of the readings “only for the bill” (`is_billing_only`) |

The agenda reports each finding as an event with `due_now` (and so also in
[`/api/todo`](#get-apitodo-v390) and to Home Assistant): `reading_check`,
`reading_duplicate` and `level_shift`, with `uid` as above and the fields of the
finding in `ref`. They are settled by the data: “Reading is correct”
(`PATCH …/readings/{id}` with `is_verified: true` per reading), “only for the bill”
(`is_billing_only: true`), a corrected reading or deleting a duplicate make the
finding go away. Only `level_shift` can be ticked off (`POST /api/agenda/done`), or a
baseline date on the day `date` settles it.

**Since v3.9.0, additive, in `…/consumption` (`monthly[]`):** `bridged: true` on
months spread by the weather across a reading “only for the bill”; there `billed_kwh`
carries the quantity the contracts use (by the reading, as with remainder billing),
`kwh` the monthly value.

**Since v3.9.0 (evaluation), additive** — every number is right or says why it
is uncertain ([Basics §1](../verstehen/00-overview.md#measured-or-interpolated-since-v370),
[§10](../verstehen/00-overview.md#one-projection-for-balance-and-workshop-since-v390)):

| Where | Field | Meaning |
|---|---|---|
| `…/consumption`, `monthly[]` | `measured_day_share` | share of the month's **days** from reading intervals of up to 45 days (0 … 1). Since v3.9.0 `interpolated` is `true` when `measured_share` **or** `measured_day_share` is below 0.5 |
| `…/consumption`, `monthly[]` | `advance_measured_eur`, `cost_measured` | advance payment and cost of the **measured days**: pro rata in the first and last month of a reading series, otherwise equal to `advance_eur` and `cost`. Basis of the “Balance” tile and the monthly table |
| `…/consumption`, `monthly[]` | `advance_dates` | only with an advance rhythm (every 2 or 3 months, due dates from the bill): the month's installments `[{date, amount_eur, contract_id}]`. Partial periods (billing year, “Check bill”, measured days, rest of the month in the workshop) count each on its day like the card's balance, not pro rata over the month's days |
| `…/consumption`, `interpolation` | `estimated_by_year` | estimated readings per year, next to `readings_by_year` (they measure nothing but appear in the list) |
| `…/contract-status` | `settle_on` | **last day** of the billing period (inclusive): one rule for balance card, Costs & contracts, annual report, “Where does my money go?” and workshop |
| `…/contract-status` | `billing_period` | `{from, until, open_from, source, bill_id}`: the running one of the [billing periods](#billing-periods-v360) the balance is calculated over; `open_from` is the day after the last bill when the cut-off date does not match it. `null` for contracts outside the running period and for feed-in |
| `…/contract-status` | `months_left`, `advance_ongoing`, `suggestion_withheld` | advance payments left until the bill; advance that fits going forward (estimated cost of the twelve months after it ÷ 12, whole euros, `null` when the estimate is uncertain); `"short"` when with fewer than 3 payments left no catch-up amount is suggested (`suggested_advance` is then `null`) |
| `…/contract-status` | `successor_switch` | the recorded successor contract is with another supplier: instead of “Cancel or switch by …” the app asks “Cancellation confirmed?” |
| `…/forecast` | `blend_effective` | effective share of the heating curve in the first twelve forecast months (0 … 1); `blend_weight` stays the weight by model quality |
| `…/forecast` | `warnings[]` | new `level_last_year {value, from, to, factor}`: without a weather link and without twelve measured calendar months the annual amount follows the consumption of the last 365 days |
| `GET /api/pv-summary` | `monthly[].partial_series`, `monthly[].feed_above_generation` | series that cover the month only in part (below 90 % of the days of the longest series; the month then does not count as covered); measured feed-in above generation (contradictory) |
| `GET /api/pv-summary` | `yearly[].flow_period` | period of the energy flow: `{from, to, months, interpolated_months, interpolated_series, feed_above_generation, partial, generation_kwh}` or `null` |
| `GET /api/readings-overview` | `first_date` | first real reading of the meter (year choice of the report) |
| `GET /api/reports/yearly` | `model.topics.pv.notes`, `.estimated` | notes on the solar block (period, interpolated, contradictory, no comparison); `estimated`: the block rests on interpolated months (≈, “estimated” instead of “measured”) |
| `POST /api/forecast/scenario` | `advance.base_ongoing`, `.scen_ongoing`, `.withheld` | like the card's `advance_ongoing` and `suggestion_withheld`; the scenario shifts the ongoing advance by its monthly costs |

**Changed values (v3.9.0):**

- **Balance over the running billing period.** A contract that falls into the
  running billing period calculates balance, costs, advance payments and
  suggestion over exactly that period, also without a captured bill. Up to v3.8
  the card counted from the contract start without a bill (several years), the
  workshop the running billing year. `effective_end` is then the day after the
  end of the period (or the maintained contract end when it comes first); in a
  period covered only in part, only additional advance payments count among the
  special payments. The workshop (`billing`, `advance`) takes balance, date and
  suggestion from the contract status.
- **Energy flow without capping.** `flows.pv_grid` is the measured feed-in, also
  when it is above the (interpolated) generation; up to v3.8 it was
  `min(feed-in, generation)`. `eigenverbrauchsquote` refers to the sun in the
  figure.
- **Future contracts** show the working price and advance payment of their start (up to v3.8 `null`).
- **Forecast:** without a weather link and without twelve measured calendar
  months the annual total is that of the last 365 days (warning `level_last_year`).

**Since v2.8.0** `hdd` only counts the days with consumption; `temp_days` says for
how many of them temperatures are available. For HDD-relevant utilities with meter
readings (gas, district heating, since v3.1.0 heat), the fields of the heating model are added
(formulas in [Fundamentals §5](../verstehen/00-overview.md#5-weather-adjustment--consumed-more-or-just-colder)):

| Field | Meaning |
|---|---|
| `regression_point` | `true` if the month feeds the heating curve (one rule for analysis, adjustment and forecast) |
| `expected_heat` | expectation from `a × HDD + c × days` for exactly this month |
| `weather_delta_pct` | over- or under-consumption in % for the given weather; `null` for partial months |
| `hdd_normal` | heating degree days of a normal year for the same days (climate normal, otherwise your own history) |
| `heat_adjusted` | weather-adjusted consumption: `actual + a × (hdd_normal − hdd)`, at least the base load — only the weather influence according to the model is converted |

**Deprecated (since v2.8.0, removed with v3.0.0):** `expected_hgt`,
`weather_adjusted`, `delta_pct`. They continue to be delivered unchanged.
`weather_adjusted` also scaled the base load with the HDD ratio, `delta_pct`
compared with the mean of all months and thus measured the season — the
successors are `heat_adjusted` and `weather_delta_pct`.

`regressions` contains all five models with `r2`/`n`/`valid`, since v2.8.0 also
`curve` (points `{x, y}` of the curve up to the largest HDD value, computed by the
backend) and, for the linear model, `se_a` (standard error of the slope). The
breakpoint model is continuous: `split` is the breakpoint, `base.b` the base,
`heat.a` the slope above it. For heating oil and pellets `regressions` stays empty
and `regressions_note` is `"delivery_modelled"`: their monthly values are
distributed by degree days, so a curve on top would be circular reasoning.

**Since v2.4.0 (F1011)** every month additionally carries `pre_baseline`
(`true` = the month lies before the meter's analysis baseline date). Such
months stay in the response but feed **no** evaluation: `expected_heat`,
`weather_delta_pct` and `heat_adjusted` are `null` for them (the heating model
describes the building after the measure), and the regressions leave them out.
The before/after comparison is carried by `baseline_comparison`.

Two new top-level fields go with it:

```json
"baseline": {
  "active_from": "2021-09-01",     // null = no baseline date in effect
  "active_label": "Loft insulation",
  "first_month": "2021-10",        // first full month after it
  "events": [ { "date": "2021-09-01", "label": "Loft insulation" } ],
  "months_total": 144, "months_after": 58, "points_after": 41,
  "limits": [                       // what cannot be computed right now
    { "key": "weather_adjustment", "need": 12, "have": 144, "ok": true },
    { "key": "regression",         "need": 8,  "have": 41,  "ok": true },
    { "key": "anomalies",          "need": 5,  "have": 58,  "ok": true }
  ]
},
"baseline_comparison": {            // null when one epoch is too thin
  "before": { "slope": 0.42, "base": 11.8, "r2": 0.97, "points": 63, "se": 0.011 },
  "after":  { "slope": 0.28, "base": 12.1, "r2": 0.98, "points": 41, "se": 0.009 },
  "delta_pct": -33.3, "unit": "kWh",
  "significant": true,               // since v2.8.0
  "delta_pct_ci95": [-38.5, -28.1]   // since v2.8.0
}
```

`slope` is consumption **per degree day** and therefore already
weather-corrected; `delta_pct` is the effect of the measure. Since v2.8.0
`significant` says whether the difference is supported (test of the slope
difference, |z| ≥ 1.96), and `delta_pct_ci95` gives the 95 % range of the change;
`se` is the standard error of the respective slope. `limits` is filled
in **without** a baseline date too — a history that is simply too short gets explained
instead of an evaluation silently disappearing.

**Without a heating curve *(v3.1.0, additive)*.** Up to v3.0
`baseline_comparison` existed only for utilities with heating degree days.
Since v3.1.0 the app also compares electricity, water and PV before and after
the baseline date — via the **daily average per calendar month**, only over
calendar months that exist in both phases (at least three, months with at least
`min_days_period` days). The heating-curve variant has since carried
`method: "hdd_slope"` in addition.

```json
"baseline_comparison": {
  "method": "seasonal_mean",
  "before": { "per_year": 2810.4, "months": 9 },
  "after":  { "per_year": 2395.0, "months": 9 },
  "delta_pct": -14.8, "delta_per_year": -415.4,
  "delta_pct_ci95": [-19.6, -10.0], "significant": true,
  "months_compared": 9, "unit": "kWh"
}
```

`per_year` scales the compared months up to a year; `delta_pct_ci95` comes from
the spread of the monthly ratios after ÷ before, and `significant` means the
range does not include 0. Example: baseline date “Plug-in solar in operation”
on the electricity meter ([Flat scenario](../verstehen/07-szenario-wohnung.md#7-special-case-plug-in-solar)).

**Since v2.6.0** additionally `warnings` — readings that do not enter the
calculation, or only with reservations:

```json
"warnings": [
  { "type": "suspect",  "reading_id": "20260920-5c425cf2", "date": "2026-09-20", "counter": 0 },
  { "type": "outlier",  "reading_id": "20260220-dabada46", "date": "2026-02-20", "counter": 13300, "kind": "spike" },
  { "type": "decrease", "reading_id": "…", "date": "…", "counter": 5.0,
    "previous": { "date": "…", "counter": 18432.5 } }
]
```

| `type` | Meaning | in the calculation? |
|---|---|---|
| `suspect` | Home Assistant delivered a reading lower than the previous one; awaiting confirmation (`PATCH …/readings/{id}` with `is_suspect: false`) | no |
| `outlier` | sandwiched outlier of the same device: `kind` = `spike` (upwards) or `dip` (downwards) | no |
| `decrease` | the reading drops without an identifiable outlier — meter swap or rollover not recorded? | the negative interval is not |

Up to v2.5.3 the calculation only discarded the negative interval and counted
the next one in full from the wrong reading: a single value of 0 turned 190 kWh
in a month into 50,270 kWh. A **rollover** (99,998 → 12) is calculated
correctly when `digits` (register digits) is maintained on the device.

### `GET /api/utility/{u}/meters/{id}/contract-status` — balance by calendar *(v2.8.0)*

For contracts with advances (gas, electricity, district heating), the balance is
computed up to **today**, like the annual statement: advances according to the
payment plan, the base price day-exact, the consumption since the last reading
estimated. New fields per contract (additive):

| Field | Meaning |
|---|---|
| `balance_as_of` | reference date of the calculation (today, limited to the contract term) |
| `projection_method` | `forecast` (heating model or seasonal profile) or `flat_average` |
| `measured_until` | date of the last valid reading |
| `cost_to_date` | cost up to the reference date = `energy_cost_to_date + base_to_date - bonus_to_date` |
| `energy_cost_to_date` | working price × consumption, measured plus estimated |
| `base_to_date` | base price, day-exact, up to the reference date |
| `bonus_to_date` | bonuses credited up to and including the current month |
| `estimated_cost_to_date` | the estimated part of it: working price for the period since `measured_until` |
| `estimated_cost_remaining` | estimated cost from the reference date to the end of the contract |
| `advance_remaining` | advances still due until the end of the contract |
| `suggested_advance` | advance that evens out the expected balance by the end of the contract (≥ 0), otherwise `null` |
| `projection_factor` | calibration of the estimate to the most recent level (0.5–1.5); since v3.7.0 the effective factor from `projection_factor_base` and `projection_factor_heat` |
| `balance_path` | *(v2.16.0)* only on the current contract, otherwise `null`: the same calculation as a monthly series from the contract start (since v3.6.0 from `balance_from`) to its end or the next bill — per month `ym`, cumulative `cost` (measured + estimated + standing charge − bonuses) and `paid` (advances by plan − special payments net), `balance = cost − paid`, `estimated` (contains days after the last reading), `future` (month after today). The last point is `projected_end_balance` |

**Changed values:** since v2.8.0 `advance_paid` counts the advances by calendar up
to and including the current month (previously only months with a reading);
`current_balance = cost_to_date − advance_paid + special_payment_net`,
`projected_end_balance = current_balance + estimated_cost_remaining −
advance_remaining`. `actual_cost`, `actual_kwh_cost` and `actual_base_total` still
describe only the measured months.

**Balance after the last booked bill *(v3.6.0)*:** if a current contract runs on
beyond a recorded supplier bill whose result is booked in the contract as a
refund or additional payment (gas, electricity, water, district heating),
balance, costs, advances and `balance_path` start on the day after that bill.
The booked results of the bills no longer count, they settle the bill’s period;
special payments before that day neither. `special_payments` and the `special_*`
totals list only the payments that count. New field (additive):

| Field | Meaning |
|---|---|
| `balance_from` | from when balance, costs and advances count: contract start or the day after the meter’s last booked bill (for a group: its members’) |

Without a booked bill after the contract start nothing changes. Up to v3.5 the
balance always started at the contract start; on a contract over several years
every year without a booked settlement stayed in the balance.

**Advance payment with a rhythm *(v3.9.0)*:** a row of `advance_payments`
(every utility, water too) optionally carries `every_months` (`2` or `3`; `1`
means monthly and is not stored) and `due_dates` (dates, ascending, none before
`from`; regular dates give `every_months`). Anything else → 400
`errors.contract.advanceEveryInvalid` or `errors.contract.advanceDueBeforeStart`.
The amount applies per due date: every N months from `from` (day clamped at the
end of a month) or on the dates and then yearly on the same days, until the
next row. `advance_paid`, `advance_remaining`, `balance_path` and the forecast
count each instalment in full on its day; `suggested_advance` then applies per
due date (rest ÷ instalments still due). New fields per contract, only with a
rhythm (additive):

| Field | Meaning |
|---|---|
| `current_advance_every_months` | rhythm of the current advance payment in months |
| `next_advance_dates` | the next due dates from today (at most six, one year) |

Without a rhythm all values stay as before. Water: `schmutzwasser.base_prices`
*(v3.9.0, optional)* is the standing charge of the waste water
(`eur_per_month`); it counts in costs, balance and forecast like the one of the
drinking water.

### Contracts day-exact, cancellation deadline *(v2.9.0)*

**Contract fields (additive, optional):** `notice_period_days` (0–730, takes
precedence over `notice_period_months`), `notice_mode` (`term_end` |
`month_end` | `any_day`, `null` = automatic) and `auto_renews` (`false` =
cancelled). Invalid values → 400 `errors.contract.noticeDaysOutOfRange` or
`errors.contract.noticeModeInvalid`. Meaning: [data model](datenmodell.md).

**Monthly rows** (`…/consumption`, gas/electricity/district heating/heating
oil/pellets/PV): contract and prices apply from their own day. `contract_id` is
the contract with the most days in the month; new are `contract_assumed`
(`true` = the contract has expired and runs on without a successor) and — only
with more than one contract in the month — `contract_parts[]`, each entry with
`contract_id`, `days`, `kwh`, `kwh_cost`, `base_price_eur`, `advance_eur`,
`bonus_eur`, `cost`, `assumed`. The totals of the monthly row are the sums of the
parts. Water stays with the contract of the first of the month. *(v3.7.0,
additive)* Months with a bonus carry `bonus_items[]` — per bonus
`credit_date`, `amount_eur`, `type` (`sofort`, `wechsel`, `neukunde`,
`sonstige`) and `label` (note); their sum is `bonus_eur`. `monthly_total` of
the whole utility then also has `bonus_eur` and `bonus_items`. `cost` stays
net of the bonus; the “Costs” tile shows `cost + bonus_eur` and the bonus as a
line of its own.

**`contract-status`, new fields per contract:**

| Field | Meaning |
|---|---|
| `renewed` | expired, without a successor, not cancelled: runs on (`is_current: true`); the balance runs up to the next billing date |
| `cancel_by` | last day to give notice; today for contracts that can be cancelled any time and for renewed ones |
| `days_to_cancel` | days until `cancel_by` (negative = passed) |
| `switch_date` | earliest day with the new supplier |
| `notice_basis` | `fixed_end`, `open_ended`, `min_term`, `renewed` or `unknown` (no notice period maintained) |
| `remind_basis` | `cancel_by` or `end` — what `remind_stage` and `should_remind` refer to; `null` for open-ended and renewed contracts (no staged reminder) |
| `cancel_missed` | deadline passed, the contract runs on beyond its end |
| `price_increase` | next entered increase: `{from, working_price_ct: [old, new] \| null, base_price_eur: [old, new] \| null}`, otherwise `null` |

A renewed contract can be cancelled with at most one month's notice
(§ 309 Nr. 9 BGB); `switch_date` uses the shorter of the contract's own notice
period and one month.

**Changed values:** months with a contract switch or a price change in
mid-month, advances in the first and last contract month (pro rata) and
consumption after a contract end without a successor (previously €0).
`days_until_end` still counts down to the contract end; `remind_stage` and
`should_remind` refer to `cancel_by` when a notice period is maintained
(`remind_basis: "cancel_by"`).

**`tariff-switch`:** the `current` block additionally carries
`notice_period_days`, `notice_mode` and `renewed`; a renewed contract forms the
commitment chain instead of reporting "no running contract".

**`tariff-comparison` (retrospective):** real contracts show what the bill
booked (with two contracts in a month, only their part). Shadow contracts count
as a price sheet for **all** months of the period, before their first price
entry with its price — up to v2.8 only for their own term, which made a summer
offer without a winter look cheaper. Since v2.12.0 the response carries
`years`: the years with consumption data, newest first — even when the chosen
year is empty, so the year picker stays usable.

**`PATCH /api/settings`:** `billing_cycle_anchor_*` must be a calendar day
`MM-DD`, otherwise 400 `errors.settings.valueInvalid`.
`min_temp_days_forecast` and `baujahr` are **deprecated** (without effect,
dropped with v3.0.0); they are still delivered and accepted. Since v2.13.0 the
same applies to `billing_cycle_anchor_heizoel` and `…_pellets`; new is
`billing_cycle_anchor_pv_einspeisung` (default `01-01`).

### Contracts: district heating fixed costs, `warnings` *(v3.1.0, additive)*

**District heating** (CALC-31) — five optional contract fields, `fernwaerme`
only:

| Field | Meaning | Limits |
|---|---|---|
| `capacity_kw` | connected load in kW | 0–100,000 |
| `capacity_prices` | capacity charge, dated list `[{from, eur_per_kw_year}]` | like the other price lists |
| `metering_prices` | metering charge, dated list `[{from, eur_per_year}]` | like the other price lists |
| `co2_g_per_kwh` | emission factor of the heat network in g CO₂ per kWh | 0–2000 |
| `primary_energy_factor` | primary energy factor, only stored and shown | 0–5 |

`null` or empty removes a value; a decimal comma is accepted. An invalid value →
`400` `errors.contract.valueInvalid`, a capacity charge without a connected load
→ `400` `errors.contract.capacityMissing`.

```text
fixed costs per month = base price + capacity_kw × capacity charge / 12 + metering charge / 12
Example without a base price: 10 kW × 60 / 12 + 120 / 12 = 60 € per month
```

The fixed costs count everywhere the base price counts — monthly rows
(`…/consumption`), `contract-status` including the balance, forecast and bill
check —, by calendar days of the month; effective dates in either list split
sections like a price change. `co2_g_per_kwh` replaces the `co2_fernwaerme` value
in `co2_kg` for the months of the contract and is the basis of the CO₂ price of
district heating ([CO₂ price and sharing](#co₂-price-and-sharing-v310)). Shadow
contracts do not count for it.

**`warnings`** — `POST` and `PATCH` of a contract (any utility) return the
saved contract, since v3.1.0 additively with `warnings` when there is something
to note. Today one value: `term_over_24_months` — the minimum term
(`min_term_end`) ends more than 24 months after the contract start; an initial
term that long is invalid for consumers in Germany (§ 309 no. 9 BGB). The note is
not stored, the contract is; `GET` does not return it either. The interface
shows it after saving.

```json
{ "success": true, "data": { "id": "c_strom_004", "start": "2026-11-01",
  "min_term_end": "2029-10-31", "…": "…", "warnings": ["term_over_24_months"] } }
```

### Group contract *(v3.1.0)*

Since v3.1.0 a contract can belong to a **meter group** instead of a meter
([#17](https://github.com/Bingerminger/energietracker/issues/17)) — e.g. a
dual-rate meter with peak and off-peak (HT/NT) whose two registers sit as two
meters in one group. Only for utilities with advance-payment contracts: gas,
electricity, district heating.

| Field | Meaning |
|---|---|
| `meter_group_id` | target of the contract; `meter_id` is then `null`. The group must belong to the utility and have members, otherwise `400` `errors.contract.targetInvalid` |
| `working_prices_by_meter` | optional `{meter_id: [{from, ct_per_kwh}]}` — a unit price of its own per member (peak/off-peak). If a member is missing, `working_prices` applies. Prices for a meter outside the group → `errors.contract.targetInvalid` |

`PATCH` with `meter_group_id` moves a contract onto a group, `PATCH` with
`meter_id` back onto a meter (then `meter_group_id` and
`working_prices_by_meter` are dropped). `GET …/contracts?meter_id=<group-id>`
returns the group’s contracts.

**How the app calculates.** Each member calculates its consumption at its unit
price. Standing charge, advance payments and bonuses are carried only by the
**first member** in the order of the meter list — so they count exactly once,
and every sum across meters (utility, overview, PDF, CSV, efficiency) is right
without a special path.

```text
Example (specification): peak 2,000 kWh × 30 ct + off-peak 1,000 kWh × 22 ct + 12 × €12 standing charge
                         = €600 + €220 + €144 = €964 per year
```

**No double counting.** A member may not have a real contract of its own in the
period of a group contract, and vice versa: `400`
`errors.contract.groupMemberOverlap` — end the old contract first. Shadow
contracts on the group are allowed. A group with contracts attached cannot be
deleted (`errors.meter.groupHasContracts`).

**Evaluations of the group** (sum of the members) come from the routes
`GET /api/utility/{u}/meter-groups/{id}/consumption`, `…/contract-status`,
`…/forecast`, `…/tariff-switch` and `…/bill-check` (class B), in the same form
as for a meter; the meter routes `…/meters/{id}/…` accept the group ID as well.
Differences:

- **Blended price.** The projection in the balance, the forecast and the switch
  decision calculate with a total amount. With prices per member they use a
  blended price, weighted with the members’ consumption over their last twelve
  months; the contract there carries `working_prices_blended: true`.
- **Bill check.** The members’ sections follow one another, each row with
  `meter_id` and `meter_name`; fixed costs count once (with the first member).
  The response carries `group_id`.
- **Members.** `contract-status` of a member additionally carries
  `group_contract: {group_id, contract_id}` — the balance sits with the group
  contract.

Agenda, calendar and recommendations (cancellation deadline, contract end,
price increase) also run for group contracts. Background:
[Meter topology](../verstehen/13-meter-topologie.md#group-contract-v310).

### Remainder billing at the parent meter *(v3.9.0, additive)*

If a submeter (`parent_meter_id`) has a contract of its own or is billed through
the group contract of its group, the parent meter’s contracts only bill the
remainder: measured minus submeter, on the days with its own contract
([meter topology](../verstehen/13-meter-topologie.md#remainder-billing-v390)).
Gas, electricity, district heating and feed-in; not water. New fields, only
where the rule applies:

| Where | Field | Meaning |
|---|---|---|
| monthly row of the parent meter (`…/consumption`, also of a group) | `billed_kwh` | amount of its contracts (remainder, never below zero); `kwh` stays the measurement |
| there, `…/contract-status` (per contract), `…/bill-check` (`totals`) | `rest_of` | `{measured, submeters: [{meter_id, name, kwh}], rest}`, plus only where present `estimated_kwh` (the submeter’s latest daily rate carried forward, at most 92 days), `open_days` (days without a submeter reading), `short_kwh` (remainder below zero, not billed) |
| `…/bill-check`, rows and `totals` | `measured_kwh`, `submeter_kwh` | measurement and deduction per section; there `kwh` is the contract’s amount |
| `…/forecast`, forecast months | `billed_kwh`, `deducted_kwh`, `rest_of` | costs and advance use `billed_kwh`; `kwh` stays the forecast of the measurement |

Totals across meters (`…/consumption` of the utility `monthly_total`, billing
year of the utility, annual report, PDF, CSV) keep counting the **amount** only
from meters without a parent and add the **money** of submeters with their own
contract (costs, advances, balance, bonuses). `contract-status`, bill check,
tariff comparison (`actual_kwh`, `consumption`, `expected_consumption`) and the
forecast workshop calculate with the remainder.

### Contracts: reduced grid fee, price model, credit notes *(v3.1.0, additive)*

**§ 14a EnWG, module 1** — electricity only: `grid_reduction: [{from,
eur_per_year, module: 1}]`, the annual reduction of the grid fee for a
controllable consumer (heat pump, wall box). It applies day-exact as a
deduction from the fixed costs:

```text
fixed costs per month = standing charge − reduction / 12      example: €120/yr → €10 per month
```

Monthly rows (`…/consumption`) then additionally carry `grid_reduction_eur`;
`base_price_eur` is the standing charge minus the reduction. The switch
decision leaves it out — it applies equally to every supplier. Module 2 (a
meter of its own) needs no field: a sub-meter with its own contract
([Electricity](../verstehen/02-strom.md#controllable-consumers-v310)).

**Price model** — `price_model` on every contract except water:

| Value | Meaning |
|---|---|
| missing, `fixed` | prices with effective dates as before; `fixed` is not stored |
| `monthly` | a real contract with prices per month (e.g. a dynamic tariff as it was billed); set by the monthly price import |
| `dynamic` | **only an electricity shadow contract** for the dynamic tariff check, with `dynamic: {markup_ct_per_kwh, base_eur_month, vat_pct, weighting}` — otherwise `400` `errors.contract.dynamicShadowOnly` |

An unknown value → `400` `errors.contract.priceModelInvalid`. Within `dynamic`
the mark-up is 0–100 ct/kWh, the standing charge 0–1000 € per month, VAT
0–30 % (default 19); `weighting` is always `flat`. How monthly prices come out
of it: [Wholesale prices and dynamic tariff check](#wholesale-electricity-prices-and-dynamic-tariff-check-v310).

**Monthly prices from a file** — `POST
/api/utility/{u}/contracts/{id}/prices/import-csv[?dry_run=1]`, body
`text/plain`, one line `month;ct_kwh[;standing_charge]` each (month as
`MM.YYYY`, `MM/YYYY` or `YYYY-MM`; a header line is allowed). Each line becomes
a unit price (and a standing charge in € per month) from the first of the
month; entries with the same effective date are replaced, the price model
becomes `monthly`. Response `{months, from, to, base_prices, errors[{line,
text}]}`, in a dry run additionally `would_import`. No readable line → `400`
`errors.contract.priceImportEmpty`; water and feed-in →
`errors.contract.priceImportUnsupported`.

```text
monat;ct_kwh;grundpreis
01.2026;31,42;9,90
02.2026;29,87;9,90
```

**Credit notes from the direct marketer** — feed-in only:
`revenue_statements: [{from, to, amount_eur, kwh?, attachment_id?}]`, `to`
inclusive. A credit note replaces kWh × feed-in tariff for its period, spread
over the months day by day. A month then carries `revenue_source: "statement"`
(fully covered) or `"mixed"`. Example: a credit note for June 2026 of €23.40
appears in June exactly like that.

### Contracts: time-variable grid fee (module 3) *(v3.3.0, additive)*

**§ 14a EnWG, module 3** — electricity only: `grid_tou` describes the
time-variable grid fee from the grid operator’s price sheet, a standard tariff
and time windows with a different price per kWh. Example with made-up prices,
as on the heat pump contract of the example household “Own house with heat
pump and solar” (`eigenheim-modern`):

```json
"grid_tou": {
  "standard_ct": 8.1,
  "windows": [
    { "quarters": [1, 2, 3, 4], "from": "00:00", "to": "06:00", "ct": 2.4 },
    { "quarters": [1, 4],       "from": "17:00", "to": "20:00", "ct": 11.9 }
  ]
}
```

| Field | Meaning |
|---|---|
| `standard_ct` | grid fee per kWh outside the windows, ct/kWh (0–100); required as soon as there are windows |
| `windows[].quarters` | quarters in which the window applies: 1 = January–March … 4 = October–December; stored sorted and without duplicates |
| `windows[].from`, `windows[].to` | local time `HH:MM`, `to` also `24:00`; `from` lies before `to` — a window across midnight is two (`22:00`–`24:00` and `00:00`–`06:00`) |
| `windows[].ct` | grid fee per kWh in the window (0–100); below `standard_ct` low tariff, above it high tariff |
| `net` | *(v3.7.0, additive)* `true`: the amounts are on the contract **without VAT**; the flexibility analysis grosses them up with the app’s rate (that of the dynamic offer, otherwise 19 %, like the wholesale levers; `module3[].prices_net`, `vat_pct`). Missing or `false`: as entered (gross, as on the bill) — the field is only stored with `true` |

At most 24 windows. Numbers may also come as text with a decimal comma
(`"2,4"`). If two windows overlap, the first in the list applies. Invalid →
`400` `errors.contract.gridTouInvalid`; the message names the place
(`standard_ct`, `windows[1]`, `windows[1].ct` or `windows`). `null`, `{}` or an
object without a standard tariff and without windows removes the field; a
window that is not an object is dropped. For utilities other than electricity
`grid_tou` is not stored.

Balance, forecast and tariff comparison keep calculating with the contract’s
prices. `grid_tou` is only evaluated under
[flexibility](#valuing-flexibility-v330): per month with the meter’s real
contract (not a shadow contract) that applies on the first of the month.
Module 3 only exists on top of module 1 (`grid_reduction`) and with a smart
metering system — determination BK8-22/010-A of the Federal Network Agency; as
of and sources: [Use cases](../anleitungen/anwendungsfaelle.md), section G.

### Contracts: `phase`, `values_on` *(v3.4.0, additive)*

Every contract in `GET /api/utility/{u}/contracts` (also with `?meter_id=`)
and `GET …/contracts/{id}` carries two fields that exist in the answer only —
they are not stored, and a `PATCH` with them changes nothing:

| Field | Meaning |
|---|---|
| `phase` | `future` (starts after today), `current` (applies today), `renewed` (ended, runs on without cancellation), `past` (ended or replaced by a contract that started later), `shadow` (quote for comparison, counts nowhere) |
| `values_on` | `{date, working_price_ct, base_price_eur, advance_eur}`: unit price (minor unit per consumption unit, for water drinking water per m³), standing charge (€/month, for water drinking water) and advance payment from the effective plan (with special payments that change it) on the day `date` — today, for an ended contract its last day, for an upcoming one its first; a missing value is `null` |

The phase follows `ContractService::resolveForDate()` per target (meter or
meter group; quotes do not count): whoever applies today is `current` or
`renewed`; with overlapping contracts the later start wins and the older one is
`past`. `GET …/meters/{id}/contract-status` and
`…/meter-groups/{id}/contract-status` carry the same `phase` per contract
(there without `shadow`, quotes are not part of the balance). The interface
reads the status of both contract lists from it only and orders by phase:
upcoming, current (with renewed), ended, quotes. Up to v3.3 each list derived
it itself — the same contract was called “PAST” and “RENEWED”.

### Wholesale electricity prices and dynamic tariff check *(v3.1.0)*

What would a dynamic electricity tariff have cost? For this the app keeps the
wholesale prices (day-ahead, bidding zone Germany/Luxembourg) as **monthly
averages** in the pot `market_prices.json` (in the backup):

```json
{ "source": "smard", "area": "DE-LU", "unit": "ct/kWh",
  "months": { "2025-01": { "avg_ct": 11.414 }, "2025-02": { "avg_ct": 12.82 } },
  "imported_at": "2026-02-03T19:12:00+01:00",
  "attribution": "Bundesnetzagentur | SMARD.de (CC BY 4.0)" }
```

| Route | Purpose |
|---|---|
| `GET /api/market-prices` | the pot including `attribution` |
| `POST /api/market-prices/import-csv[?dry_run=1]` | file as body: a SMARD download with hourly or quarter-hour values (header with “Datum von” and the column “Deutschland/Luxemburg [€/MWh]”, decimal comma, “-” for missing) or simply `YYYY-MM;€/MWh`. Averages per month and replaces those months. Response `{months, from, to, rows, skipped}`, in a dry run additionally `would_import` and `preview` |
| `POST /api/market-prices/sync-smard` | fetches the monthly values of recent years from SMARD (Federal Network Agency). **Only on request**, never in the background — the only download of its kind. Response `{months, from, to}` |

Errors: no values in the file → `400` `errors.marketPrices.noRows`; SMARD
unreachable or empty → `502` `errors.marketPrices.syncFailed`. Attribution:
“Bundesnetzagentur | SMARD.de (CC BY 4.0)”.

**Dynamic tariff check.** An electricity shadow contract with
`price_model: "dynamic"` is translated into monthly prices for the look-back
(`tariff-comparison`) and the switch (`tariff-switch`):

```text
unit price (month) = spot average × (1 + VAT) + mark-up      standing charge = base_eur_month
Example: 3,000 kWh, spot 10 ct, VAT 19 %, mark-up 15 ct, standing charge €10
         → 3,000 × 26.9 ct + 12 × €10 = €807 + €120 = €927 per year
```

A month without a market value — in the future — takes the same month of the
previous year (up to three years back) as an assumption. The candidate’s row
carries `price_model: "dynamic"` and `dynamic_assumed_months` (number of
assumed months). If market data is missing entirely, the candidate is dropped
and the response carries `dynamic_missing_market: true`.

Without quarter hours the check works **without a load profile** with the
monthly average, as if consumption were spread evenly across the day. Evening
use usually costs more; a real dynamic tariff is billed per quarter hour via a
smart metering system (§ 41a EnWG). Background:
[Electricity → Dynamic tariffs](../verstehen/02-strom.md#dynamic-tariffs-v310).

**With your own load profile *(v3.3.0)*.** If consumption and wholesale price
per quarter hour exist for the meter ([flexibility](#valuing-flexibility-v330)),
the look-back (`tariff-comparison`) uses the consumption-weighted wholesale
price instead of the monthly average in every month in which at least 90 % of
the quarter hours have both:

```text
spot (month)  = Σ (kWh × ct/kWh) ÷ Σ kWh      over the quarter hours with consumption and price
unit price    = spot × (1 + VAT) + mark-up
```

The candidate’s row then also carries `dynamic_profile_months` — the number of
these months, otherwise 0. The switching decision (`tariff-switch`) looks ahead
and stays with the monthly average.

### Valuing flexibility *(v3.3.0)*

Did shifting pay off? Control happens elsewhere — in evcc, Home Assistant or
the battery itself; Energietracker recalculates with consumption and wholesale
price **per quarter hour**. Only quarter hours that have both count, and only
the wholesale share — without taxes, levies and grid fee (the levers in
`insights` include VAT since v3.6.0, see below). Step by step:
[Valuing flexibility](../anleitungen/flexibilitaet.md); how the series are
stored: [Data model](datenmodell.md#quarter-hour-series-intervalsjson-v330).

| Route | Purpose | Class |
|---|---|---|
| `GET /api/flexibility?year=` | evaluation of a calendar year; *(v3.6.0)* `&summary=1` only the result card’s number | B |
| `GET /api/intervals` | index of the quarter-hour series | C |
| `DELETE /api/intervals/{id}` | delete one series | C |
| `POST /api/market-prices/sync-smard-intervals?year=` | fetch wholesale prices per quarter hour from SMARD | C |

`year` is a calendar year from 2018 to 2100, without it the previous year. The
year runs in the installation’s time zone (`timezone`) from 1 January 0:00 to
1 January 0:00.

**Where the quarter hours come from.**

| Source | Route | `source` |
|---|---|---|
| file from a portal (grid operator, metering operator, inverter) | `POST …/import-series` with values at 15, 30 or 60 minute intervals — [Import time series](#import-time-series-v310) | `import` |
| Home Assistant, Node-RED, scripts | `POST /api/ingest` with `timestamp` — [Timestamps and quarter hours](#timestamps-and-quarter-hours-v330) | `ingest` |
| wholesale prices day-ahead DE-LU | `POST /api/market-prices/sync-smard-intervals` — see below | `smard` |

A new value replaces the old one in the same quarter hour. Consumption can exist
for any meter of a utility with meter readings; evaluated are electricity and
the battery meters of PV generation.

**Evaluation — `GET /api/flexibility`.** Example: the example household
`eigenheim-modern` in 2025 (consumption made up, wholesale prices from SMARD),
lists shortened:

```json
{ "year": 2025,
  "prices": { "available": true, "coverage": 1, "area": "DE-LU" },
  "meters": [
    { "meter_id": "m_strom_waermepumpe", "name": "Wärmepumpe", "role": "heat_pump",
      "controllable": true, "coverage": 1,
      "months": [ { "month": 1, "kwh": 768.82, "coverage": 1, "priced_kwh": 768.82,
                    "cheap_kwh": 430.98, "negative_kwh": 12.49, "own_ct": 10.634,
                    "market_ct": 11.414, "profile_factor": 0.932, "advantage_eur": 6 }, "…" ],
      "total": { "priced_kwh": 3890.98, "cheap_kwh": 2125.88, "cheap_share": 0.5464,
                 "negative_kwh": 111.98, "own_ct": 9.596, "market_ct": 10.079,
                 "profile_factor": 0.952, "advantage_eur": 18.78 } },
    "…" ],
  "battery": {
    "months": [ { "charged_kwh": 103.05, "discharged_kwh": 94.8, "charge_ct": 10.373,
                  "discharge_ct": 12.119, "value_eur": 0.8, "month": 1 }, "…" ],
    "total": { "charged_kwh": 1694.7, "discharged_kwh": 1555.91, "charge_ct": 8.114,
               "discharge_ct": 11.612, "value_eur": 43.15 } },
  "module3": [
    { "meter_id": "m_strom_waermepumpe", "name": "Wärmepumpe",
      "months": [ { "month": 1, "low_kwh": 298.74, "high_kwh": 30.75,
                    "standard_kwh": 439.33, "saving_eur": 15.86 }, "…" ],
      "total": { "low_kwh": 1511.79, "high_kwh": 129.41, "standard_kwh": 2249.78,
                 "saving_eur": 81.25 } } ] }
```

| Field | Meaning |
|---|---|
| `prices` | `available`: price series of the year exists; `coverage`: share of the year’s quarter hours with a price (0–1); `area`: bidding zone `DE-LU` |
| `meters[]` | per electricity meter with a consumption series for the year: `meter_id`, `name`, `role`, `controllable` (role `heat_pump` or `ev_charger` — consumption that can be shifted), `coverage` (share of quarter hours with consumption). Without prices `months: []` and `total: null` |
| `months[]` | per month with consumption: `month` (1–12), `kwh` (the month’s consumption), `priced_kwh` (of that in quarter hours with a price), `coverage` (share of the month’s quarter hours with consumption and price), `cheap_kwh`, `negative_kwh`, `own_ct`, `market_ct`, `profile_factor`, `advantage_eur` — `own_ct`, `profile_factor` and `advantage_eur` are `null` without a quarter hour with consumption and price, `market_ct` without a price in the month |
| `total` | over the months with an own price and a market average: the same figures without `month`, `kwh` and `coverage`, plus `cheap_share` (share of `cheap_kwh` in `priced_kwh`); `null` if no quarter hour has consumption and price |
| `battery` | battery: per month and in total `charged_kwh`, `discharged_kwh`, `charge_ct` and `discharge_ct` (wholesale price, weighted by quantity; `null` without quantity) and `value_eur`. Needs one PV generation consumption series each with the role `battery_charge` (“Battery – charging”) and `battery_discharge` (“Battery – discharging”) and prices, otherwise `null` |
| `module3[]` | per electricity meter with a consumption series whose real contract carries `grid_tou` on at least one first of a month in the year ([module 3](#contracts-time-variable-grid-fee-module-3-v330-additive)): per month and in total `low_kwh`, `high_kwh`, `standard_kwh` (consumption in the low, high and standard tariff) and `saving_eur`. Needs no wholesale prices |

```text
cheap           = quarter hour with a price below the simple market average of its month
own_ct          = Σ (kWh × ct/kWh) ÷ Σ kWh             own wholesale price, weighted by consumption
market_ct       = simple market average of the month; for the year weighted by kWh per month
profile_factor  = own_ct ÷ market_ct                   below 1: more consumption in cheap quarter hours
advantage_eur   = (market_ct − own_ct) × kWh ÷ 100     positive: saved compared with even consumption
value_eur       = (Σ kWh discharged × ct/kWh − Σ kWh charged × ct/kWh) ÷ 100
saving_eur      = Σ kWh × (standard_ct − ct/kWh of the window) ÷ 100

Example heat pump 2025: (10.079 − 9.596) ct/kWh × 3,890.98 kWh ÷ 100 ≈ €18.8
                        profile factor 9.596 ÷ 10.079 = 0.952
```

`profile_factor` is `null` when the market average is around 0 (magnitude up
to 0.01 ct). For module 3, quarter and time of day of every quarter hour count
in local time; months without a contract with `grid_tou` are missing from
`months`.

**What you can do — `insights`** *(v3.6.0, additive)*. The same response
carries traffic light, levers and time windows (`FlexibilityInsightService`);
the interface only formats ([Flexibility, § 4](../anleitungen/flexibilitaet.md#4-the-page-card-by-card)):

| Field | Meaning |
|---|---|
| `signal` | price signal of the traffic light: `spot` (wholesale price per quarter hour), `tou` (without wholesale prices: module 3 time windows on the contract) or `null` |
| `season_now` | `summer` (April–September) or `winter` — the season of the action sentences, by today’s date |
| `pattern` | per `all`, `summer`, `winter`: `week[7][24]` (Monday first; per field `ct`, `level` `cheap`/`mid`/`expensive`), `hours[24]` (`ct`, `level`, `sun` 0–1 as a share of the strongest hour, `sun_level` `strong`/`some`/`none`), `windows[]` (the three cheapest windows of the week: `weekday` 1–7, `from`, `to`, `ct`, `below_ct`, `below_pct`, `sun`), `day_cheap`, `day_peak` (cheapest or most expensive three hours of the typical day), `days`, `mean_ct`, `min_ct`, `max_ct`, `cheap_ct`, `expensive_ct` (third limits; `null` for module 3, where the grid fee stages apply); `null` without prices and without generation |
| `levers[]` | per appliance and signal, biggest first: `kind` (`ev`, `heat_pump`, `battery`, `washer`, `dishwasher`), `meter_id`, `name`, `signal` (`spot`, `tou`, `solar`), `real` (`true` = on the contract as it is), `basis` (`measured` or `assumed` = sample value), `share`, `kwh`, `shift_kwh`, `eur` (€ a year, ≥ 1; *v3.6.0* for `spot` including VAT `vat_pct`), `window` per `summer`/`winter` (`weekday` or `null`, `from`, `to`, `ct`, `what` `cheap`/`peak`/`sun`/`low`; *v3.6.0* `plugged: true` when the window comes from the plug-in times — with plug-in times and solar power `null` in a season in which the car is never plugged in while the sun shines), for sample values `assumption`; *(v3.6.0)* `availability` (`ev`: `sessions` = charging windows from the evcc charging sessions, `day` = assumption “whole charging day”; otherwise `null`), `sessions` (number of charging sessions in the year) and `session_share` (share of the charging inside a charging window, 0–1), both `null` without charging sessions |
| `total_eur`, `total_real_eur` | sum of the biggest lever per appliance; of it only levers with `real` |
| `vat_pct` | *(v3.6.0)* VAT on the wholesale price in percent: `dynamic.vat_pct` of the dynamic offer, without an offer 19 |
| `ev_availability` | *(v3.6.0)* the car’s charging windows across all wall boxes: `basis` (`sessions` or `day`; *v3.7.0* `window` = your own plug-in time from the settings, without evcc), `sessions`, `share` (as above), *(v3.7.0)* `window` (`{from, until}` in hours or `null`); `null` without a wall box with quarter hours. In `levers[]`, `availability` is then `window` too |
| `summary` | *(v3.6.0)* the result card’s number, one rule for page, report and overview: `year`, `eur` (`total_real_eur` if ≥ 1 — otherwise `total_eur`), `real` (which of the two), `dynamic_eur` (`total_eur` if `real` and more than €1 larger, otherwise `null`), `vat_pct`, `measured` (measured quarter hours: electricity, or generation and feed-in — only then does the overview show the tile), `action` (first sentence with a time window, with `real` first one with `real`; fields as in `actions[]`); `null` without a lever |
| `actions[]` | one sentence per appliance: the biggest lever with `real`, otherwise the biggest; *(v3.6.0)* if the one with `real` has no time window in the current season, the biggest if it has one; `kind`, `meter_id`, `name`, `signal`, `real`, `basis`, `eur`, `season`, `window` (current season), `other` (the other) |
| `solar` | with generation and feed-in per quarter hour: `generation_kwh`, `feed_kwh`, `charged_kwh`, `self_kwh` (generation − feed-in), `direct_kwh`, `self_share`, `more_kwh`, `more_eur`, `self_share_after`, `more_share`, `working_ct`, `feed_ct`; otherwise `null` |
| `achieved` | from the check: `profile_eur` (advantage of the controllable meters), `battery_eur`, `module3_eur` — each `null` without data |
| `missing[]` | what is missing: `prices`, `intervals`, `devices` (no wall box or heat pump with quarter hours), `pv` (PV meters without quarter hours), `tariff` (neither a dynamic offer nor module 3 time windows) |
| `assumptions` | the assumptions: `heat_pump_share` (sample 0.3), `ev_share` (1), `appliances` (`washer`, `dishwasher` with `kwh_per_cycle`, `cycles_per_week`, `hours`), `window_hours` (3); *(v3.7.0, additive)* `ev_window` and `own` (each of `heat_pump_share`, `washer`, `dishwasher`, `ev_window`: `true` = your own value from the [settings](einstellungen.md#flexibility-v370), otherwise the sample value). An appliance with your own value has the basis `basis: own` in `levers[]`; 0 cycles a week = no lever |

```text
lever (spot, tou)  = Σ days (cost today − cost if the day’s free part (share) fills the day’s cheapest
                     quarter hours, at most at the highest measured power) ÷ 100
                     — for spot × (1 + vat_pct ÷ 100)
lever (battery)    = Σ days (discharging in the most expensive quarter hours without charging, at most the
                     house’s grid import + discharge − value today) ÷ 100 × (1 + vat_pct ÷ 100)
lever (solar)      = shifted kWh × (unit price − feed-in tariff) ÷ 100; only house connection and sub-meters
lever (appliances) = Σ days kWh per day × (average of all start times − best window) ÷ 100 (spot with VAT)
car (v3.6.0)       days → charging windows: per evcc charging session (`ev_sessions`) the quarter hours from
                     `created` (plugging in) to `finished` (unplugging), also across midnight; on days with a
                     charging window charging outside it stays where it is; days without a session as above
```

**Only the card’s number — `GET /api/flexibility?summary=1`** *(v3.6.0,
additive)*. Response `{year, summary}` with `summary` as above (or `null`),
without `prices`, `meters`, `battery`, `module3` and without the other fields
of `insights` — this is how the overview fetches its “Your lever” tile, without
the check per meter. `year` as above, without it the previous year.

```json
{ "year": 2025,
  "summary": { "year": 2025, "eur": 90.63, "real": true, "dynamic_eur": 250.75, "vat_pct": 19,
               "measured": true,
               "action": { "kind": "heat_pump", "meter_id": "m_strom_waermepumpe", "name": "Wärmepumpe",
                           "signal": "tou", "real": true, "basis": "measured", "eur": 39.68, "season": "winter",
                           "window": { "weekday": null, "from": 0, "to": 6, "ct": 2.4, "what": "low" },
                           "other": { "weekday": null, "from": 0, "to": 6, "ct": 2.4, "what": "low" } } } }
```

**Series — `GET /api/intervals`.**

```json
{ "series": [
  { "id": "e-strom-m_strom_waermepumpe-2025", "kind": "energy", "utility": "strom",
    "meter_id": "m_strom_waermepumpe", "unit": "kWh", "year": 2025, "step": 900,
    "slots": 35040, "filled": 35040, "from": "2025-01-01T00:00:00+01:00",
    "to": "2026-01-01T00:00:00+01:00", "source": "import", "updated_at": "…" },
  { "id": "p-de-lu-2025", "kind": "price", "area": "DE-LU", "unit": "ct/kWh",
    "year": 2025, "step": 900, "slots": 35040, "filled": 35040,
    "from": "2025-01-01T00:00:00+01:00", "to": "2026-01-01T00:00:00+01:00",
    "source": "smard", "updated_at": "…" } ] }
```

Sorted by `kind`, meter and year. `slots` are the year’s places, `filled` those
with a value; `from` and `to` the start of the first and the end of the last
quarter hour with a value (local time, `null` without a value); `source` the
source of the last write. The fields in detail:
[Data model](datenmodell.md#quarter-hour-series-intervalsjson-v330).

**Deleting — `DELETE /api/intervals/{id}`** removes the index entry and the file
and answers `{"deleted": true}`. Meter readings, daily values and the monthly
market averages stay.

**Wholesale prices — `POST /api/market-prices/sync-smard-intervals?year=`**,
without a body. Fetches the day-ahead wholesale price DE-LU per quarter hour from
SMARD (Federal Network Agency): first the list of weekly packages, then every
package of the year — about 53 requests. Conversion €/MWh ÷ 10 = ct/kWh. Before
1 October 2025 hours were traded; SMARD then carries the hourly value in all
four quarter hours. SMARD has quarter-hour values from October 2018. Future
quarter hours stay empty, existing values are replaced. **Only on request**,
never in the background; only the request to SMARD goes out, none of your data.

```json
{ "year": 2025, "slots": 35040, "from": "2025-01-01", "to": "2025-12-31" }
```

`slots` counts the quarter hours taken over, `from` and `to` are the first and
last day with a value. Attribution: “Bundesnetzagentur | SMARD.de (CC BY 4.0)”.

| Status | `code` | When |
|---|---|---|
| `400` | `errors.flexibility.yearInvalid` | `year` is not a year from 2018 to 2100 (`flexibility`, `sync-smard-intervals`) |
| `400` | `errors.flexibility.monthInvalid` | *(v3.5.1)* `month` is not a number from 1 to 12 (`sync-smard-intervals`) |
| `404` | `errors.intervals.notFound` | `DELETE`: no series with this ID in the index |
| `502` | `errors.marketPrices.syncQuarterHoursFailed` | `sync-smard-intervals`: SMARD unreachable, a package with an HTTP error or no value for the year — nothing is stored then |

### `GET /api/utility/{u}/meters/{id}/forecast` *(extended in v2.8.0)*

Additionally per forecast month:

| Field | Meaning |
|---|---|
| `band_low`, `band_high` | uncertainty band (width `confidence_band_sigma`, default 1.28 σ ≈ 80 %) |
| `hdd_estimated` | normal heating degree days of the month (climate normal, otherwise your own history) |
| `contract_assumed` | `true` if the last contract continues as an assumption after the end of the contract |
| `method` | `blend(reg=…, seasonal=…)`, `seasonal_only`, `heat_model` (calendar month without own history, since v3.9.0 before the regression), `heat_model_summer` (likewise, heating model not reliable: base load from the measured summer months), `seasonal_interpolated` (interpolated values only, or the heating model does not know months this warm, fails the quality gate or has a base load below half the measured summer; for heating only from gaps of up to 62 days as long as there is a heating model), `regression_only` (without a heating model), `filled` |

At the top level:

```json
"hdd_source": "climate_normal",       // or "temperature_history", "consumption_months", null
"annual": { "value": 9387.3, "low": 8619.3, "high": 10155.4, "sigma": 1.28, "level_pct": 80 },
"warnings": [
  { "code": "history_short", "months": 8, "missing": [1, 2, 3, 4] },
  { "code": "no_climate_normal", "hdd_source": "temperature_history" }
],
"climate_normal": { "period": { "from": "1996-01-01", "to": "2025-12-31" },
                    "latitude": 51.34, "longitude": 12.37, "fetched_at": "…" }
```

`annual` is absent (`null`) when there are no twelve months with a band. The
forecast's advances come from the effective payment plan (special payments "with
effect" change it).

**Since v3.7.0 (#37, additive):** interpolated months do not form the seasonal
profile. A calendar month without a measured value and without a model gets
`method: "seasonal_interpolated"` (the interpolated values). `warnings` then
carries `{ "code": "interpolated", "months": 12, "readings": 2, "heat_basis": null }`,
and `heat_model` names its basis (`basis`: `months` or `intervals`).

**CO₂ price scenario *(v3.1.0, MKT-26, additive)*.** What a higher CO₂ price
would cost — shown next to the forecast only; `cost_estimated` and all other
values stay unchanged.

| Parameter | Meaning |
|---|---|
| `co2_scenario_eur_t` | scenario price in €/t (0–1000). Without the parameter the setting `co2_price_scenario_eur_t` applies; empty (`co2_scenario_eur_t=`) switches the scenario off for this request |
| `co2_scenario_from` | first year of the scenario (2021–2100); without it `co2_price_scenario_from` applies (default 2028) |

Invalid → `400` `errors.forecast.paramInvalid` like the other parameters. With
a scenario, every forecast month from `co2_scenario_from` on additionally
carries `co2_delta_eur` (the month’s extra cost including VAT), and at the top
level:

```json
"co2_scenario": { "eur_t": 150.0, "from": 2028,
                  "delta_ct_per_kwh": 1.943, "delta_cost_12m_eur": 0.0 }
```

```text
delta_ct_per_kwh = (eur_t − CO₂ price of the year) × factor [kg/kWh] / 10 × (1 + 0.19)
```

`delta_ct_per_kwh` is the value of the first scenario month, `null` if the
forecast does not reach the year `from`. `delta_cost_12m_eur` sums
`co2_delta_eur` over the **first twelve** forecast months — if they lie before
`from`, the sum is 0. Factor and price as for `/api/co2-costs` (gas, heating
oil, heat via its energy source); without a factor (electricity, water, district
heating) `co2_scenario` is `null`, as it is without a scenario. In a country
without a CO₂ scheme `delta_ct_per_kwh` stays `null`.

### `POST /api/forecast/scenario` — forecast workshop *(v3.6.0, F1031, additive)*

Calculates a scenario for a meter or meter group against the baseline (the
forecast for the next twelve months). **Writes nothing:** no write lock, also
allowed with a read-only key. POST only because of the nested inputs. Guide
and method: [What if?](../anleitungen/was-waere-wenn.md)

| Field | Meaning (default) |
|---|---|
| `utility`, `meter_id` | required; meter or group. Unknown meter → `404` |
| `model` | `standard` (forecast), `last_year`, `trend` (`standard`) |
| `regression` | regression model of the forecast: `linear`, `polynomial`, `robust`, `segmented`, `sigmoid` (setting) |
| `winter_pct` | heating degree days ± % (−50…50; 0) — only the heating-dependent part |
| `room_temp_delta` | room temperature ± K (−5…5; 0) — heating threshold shifted, heating degree days from the climate normal or history |
| `saving_pct` | consumption − % (0…80; 0) |
| `baseload_pct` | base load ± % (−80…100; 0) |
| `ev_km`, `ev_kwh_per_100km` | electric car (electricity only): km per year (0…100,000) and consumption (5…60, required when km > 0) |
| `jaz_from`, `jaz_to` | SPF today and in the scenario (1…8, both or none; electricity with the heat pump role only) |
| `measures[]` | at most 10: `id` (A–Z, a–z, 0–9, `_`, `-`), `kind`, `label`, `heat_pct` (0…90), `base_pct` (0…90), `cost_eur` (0…10,000,000 or `null`), `active` (`true`) |
| `tariff` | `{"kind":"current"}`, `{"kind":"contract","contract_id":…}` (contract or offer of the meter), `{"kind":"custom","working_price_ct":…,"base_eur_month":…}`, `{"kind":"cheapest"}` |
| `budget_eur` | with a value: best combination of measures within this budget (0…10,000,000) |

Invalid → `400` `errors.forecast.paramInvalid` with the field and the allowed
range. Utilities without the workshop (deliveries, feed-in, utilities without
contracts) answer with `supported: false`, a short history with
`valid: false`, `reason_code: "too_few_months"`.

The response has the same shape as in the German reference (shortened example
there): `months[]` (`ym`, `base_qty`, `scen_qty`, `base_cost`, `scen_cost`,
`base_cum_cost`, `scen_cum_cost`, `advance`, `heat_share`, band fields),
`totals` (`base`, `scenario`, `delta` with `qty_pct`, `cost_pct`), `band`,
`models` (`standard`, `last_year`, `trend` with `growth_pct`), `advance`,
`billing`, `tariff`, `offers[]`, `ranking[]`, `optimization`,
`winter_presets`, `capabilities`, `basis`, `warnings[]`, `inputs`.

- `ranking`: each lever on its own against the baseline (normal winter,
  current contract), sorted by `saving_eur` (positive = saves); `kind` =
  `room_temp`, `saving`, `baseload`, `ev`, `jaz`, `tariff`, `measure`.
- `optimization` (only with `budget_eur`): exact by enumerating every
  combination within the budget with the model; `lp` = linear relaxation with
  the simplex solver (`bound_eur` upper bound, `budget_value_per_eur` shadow
  price of the budget in € per € per year).
- `advance.method`: `billing_period` (advance payment + final balance ÷
  remaining months of the running billing period) or `year_twelfth`.
- `billing`: balance at the end of the running billing period (positive =
  additional payment); `null` without a period. `balance_so_far` is the period’s
  balance as in `…/billing-periods` (as of `as_of`). *(v3.6.0)* `rest_days`
  (additive): days after `as_of` up to the end of the month that have neither a
  reading nor a forecast month — estimated with the daily rate of the measured
  part of that month, standing charge and advance pro rata; before, they were
  missing from the end balance.
- `warnings[].code`: `room_temp_unavailable`, `last_year_unavailable`,
  `trend_unavailable`, `model_filled` (with `months`), `ev_unsupported`,
  `jaz_unsupported`, `no_heat_model`, `tariff_unsupported` (water),
  `tariff_no_price`, `no_offers`.

In addition, since v3.6.0 `GET …/forecast` carries per month `heat_share`
(heating share from the heating model or `null`), `fixed_eur` and `unit_eur`
(cost of the month = `fixed_eur` + `unit_eur` × quantity) and at the top level
`heat_model` (`a`, `c`, `n`) and `hdd_base` — additive.

### `POST /api/temperatures/sync-open-meteo` *(v2.8.0)*

Fetches daily temperatures for the location from the settings — measured values
from the archive, the forecast for the last few days and the coming week — and,
the first time, the climate normal (30 years).

| Parameter | Meaning |
|---|---|
| `start`, `end` | period (ISO date). Without `start`: from the first reading or delivery, otherwise the last 30 days |
| `reload=1` | replace existing values with archive values — except your own (`csv`, `manual`) |
| `auto=1` | automatic fetch by the interface: at most once a day; with `weather_auto_fill = false` `{"skipped": true, "reason": "auto_fill_off"}`, otherwise possibly `{"skipped": true, "reason": "already_today"}` |

Response: `imported`, `archive_rows`, `forecast_rows`, `archive_range`,
`archive_error`, `forecast_error`, `measured_until`, `forecast_until` and
`climate_normal` (`status`: `present` | `fetched` | `failed`, plus period and
coordinates).

**Since v3.1.0** `archive_error` and `forecast_error` are in the language of the
request (catalogue `errors.weather.<code>`; up to v3.0 raw English or German
text). Next to them, additively, the stable reason as `archive_error_code` or
`forecast_error_code` — `null` without an error:

| Code | Meaning |
|---|---|
| `network` | Open-Meteo cannot be reached (DNS, connection, timeout) |
| `http` | Open-Meteo answered with an HTTP error |
| `badFormat` | response in an unexpected format |
| `noDays` | response without usable days |
| `noTransport` | PHP cannot fetch web addresses (neither cURL nor `allow_url_fopen`) |

If the climate normal fails, `climate_normal` carries `error_code` as well,
next to `status: "failed"` and `error`. Scripts evaluate the codes, not the
text.

Since v2.8.0 every entry in `GET /api/temperatures` carries `source`:
`archive`, `forecast`, `csv` or `manual`. Forecasts are replaced by archive values
as soon as these are available; your own values never are. Only the location,
rounded to two decimal places, is sent to Open-Meteo.

### `GET /api/geocode?q=…` *(v2.12.0)*

Place search for the weather data page via Open-Meteo geocoding. `q` must be
2–80 characters long, otherwise 400 `errors.temperature.geocodeQuery`; if
Open-Meteo cannot be reached, 502 `errors.temperature.geocodeFailed`. Names
follow the interface language. Only the search text is sent, and only when
someone searches.

```json
[ { "name": "Leipzig", "latitude": 51.3396, "longitude": 12.3713,
    "country": "Germany", "admin1": "Saxony", "postcode": "04303" } ]
```

At most five hits; `country`, `admin1` and `postcode` may be `null`.

### `POST /api/utility/{u}/meters/{id}/readings/import-csv?dry_run=1` *(v2.12.0)*

Reads the CSV like the import but writes nothing. The response has the same
fields (`imported` and `overwritten` are 0, `skipped`, `errors`, possibly
`encoding_converted_from` and `other_meter_rows`) plus:

```json
{ "dry_run": true,
  "rows": [ { "line": 2, "date": "2024-02-01", "counter": 1395.2,
              "note": "", "is_estimated": false } ] }
```

The interface compares `rows` with the existing readings and shows each row's
effect (new, replaces, unchanged) and the checks of manual entry. Numbers may
use point, comma, space or apostrophe as thousands separator (`1.395,2`,
`1 395,2`, `1'395.2`). Without `dry_run` the import stays as it was. Which
headers and date formats the import understands since v3.1.0:
[Import](#import-v310).

### Import time series *(v3.1.0)*

`POST /api/utility/{u}/meters/{id}/import-series[?dry_run=1]` reads a file
from a portal (grid operator, metering operator, inverter, heat pump) with a
**column mapping** and condenses it into daily values. For every utility with
meter readings, not for heating oil and pellets. JSON body:

```json
{ "csv": "Datum;Uhrzeit;Wert (kWh)\n01.01.2025;00:15;0,061\n…",
  "mapping": { "skip_rows": 1, "date_col": 0, "time_col": 1, "value_col": 2,
               "value_kind": "consumption", "unit_factor": 1,
               "interval_stamp": "end", "start_counter": 40211.0 } }
```

| `mapping` | Meaning |
|---|---|
| `delimiter` | `;`, `,` or tab; empty = detect per line |
| `skip_rows` | lines before the data (default 0) |
| `date_col` | column with the date or date and time, **from 0** (default 0) |
| `time_col` | column with the time, if separate; otherwise omit |
| `tz` | time zone for stamps without one; default that of the installation |
| `value_col` | column with the value — required |
| `value_kind` | `counter` (meter reading: the last value per day) or `consumption` (consumption per interval: sum per day, default) |
| `unit_factor` | factor on the values, e.g. `0.001` for Wh → kWh (default 1, greater than 0) |
| `interval_stamp` | `start` (default) or `end` — with `end`, an interval ending at 0:00 belongs to the previous day |
| `start_counter` | reading at the start of the first day, only for `consumption` on a meter with readings |

Stamps: `DD.MM.YYYY[ HH:MM[:SS]]`, `YYYY-MM-DD[ HH:MM]` and ISO 8601 with a
time zone (`2025-03-30T01:00:00+01:00`, `…Z`); the days of the clock change
(23 or 25 hours) count correctly. Numbers with decimal comma or point; UTF-8 or
Windows-1252.

| `value_kind` | Meter | Result |
|---|---|---|
| `counter` | meter readings | one reading per day with the last value; existing readings on the same day are replaced |
| `consumption` | consumption per period (`capture: "period"`) | one period per day via the period import; overlaps are skipped |
| `consumption` | meter readings | readings from an anchor — the reading on the first day or `start_counter`; the reading at the end of a day sits on the next day. Without an anchor `400` `errors.import.anchorMissing` |
| `counter` | consumption per period | `400` `errors.import.mappingInvalid` |

Response:

```json
{ "rows_read": 35040, "skipped": 0, "errors": [], "days": 365,
  "from": "2025-01-01", "to": "2025-12-31", "total": 3412.618,
  "value_kind": "consumption",
  "intervals": { "step": 900, "slots": 35040 },
  "preview": [ { "date": "2025-01-01", "value": 9.412 }, "…" ],
  "target": "readings", "readings": 366,
  "result": { "imported": 366, "overwritten": 0, "skipped": 0, "errors": [] } }
```

`total` only for `consumption`; `preview` is the first ten days; `errors` names
up to twenty unreadable lines (`errors.import.seriesRow`). In a dry run `result`
is omitted; for readings `dry_run: true` is set. With `target: "periods"`,
`result` is the response of the period import. A mapping with invalid values or
a file without a single day → `400` `errors.import.mappingInvalid`. Step by
step: [Time series from portals](../anleitungen/daten-aus-portalen.md).

**Quarter hours *(v3.3.0, additive)*.** If the values come at 15, 30 or 60
minute intervals — the most frequent gap between the stamps decides —, the
import also keeps them per quarter hour for
[flexibility](#valuing-flexibility-v330). `intervals` names the interval in
seconds (`step`) and the number of quarter hours (`slots`); for coarser values
(daily values) or fewer than two lines it is `null`. A consumption per interval
is spread evenly over its quarter hours (with `interval_stamp: "end"` the
interval ends at the stamp). For meter readings the difference between two
readings exactly one interval apart is spread; nothing arises across a gap or a
decrease. A dry run only counts; the series is stored when taking over (source
`import`), for meters with readings and with periods alike. Existing values in
the same quarter hours are replaced.

### `GET /api/utility/{u}/meters/{id}/tariff-switch` — forecast quality *(extended in v2.8.0)*

The `forecast` block (quality of the consumption assumption) additionally carries
`warnings` and `hdd_source` like the forecast, and `annual_band` (= `annual` of
the forecast): the switching decision shows how far the annual consumption can
vary depending on the winter.

### Readings: `is_suspect`, `source` *(v2.6.0, additive)*

Two optional fields on a reading — only present when set:

- `source`: `ingest` (Home Assistant) or `csv` (CSV import). Basis of
  `last_ingest` in `/api/health`.
- `is_suspect: true`: the ingest received a falling reading on the same device.
  The reading is stored anyway (201) but only counts after confirmation.
  `PATCH` with `is_suspect: false` confirms it; a corrected `counter` resolves
  the suspicion as well.

### Readings: `client_ref`, `attachment_id` *(v3.1.0, additive)*

Two more optional fields, likewise only present when set:

- `client_ref` — an identifier of the entry, chosen by the client: 8–64
  characters from `A–Z`, `a–z`, `0–9` and `-` (the interface uses a UUID). If
  the same meter already has a reading with the same `client_ref`,
  `POST …/readings` does not create a second one but answers **`200`** with
  the existing reading and `duplicate: true`; otherwise `201` as before. So a
  client may safely send again after a timeout — the interface's offline queue
  does exactly that. Wrong format → `400` `errors.reading.clientRefInvalid`.
- `attachment_id` — photo of the meter reading
  ([Receipts](#receipts-and-text-recognition-v310)). The receipt must exist and
  have `kind: reading_photo`, otherwise `400` `errors.attachment.notFound` or
  `errors.attachment.wrongKind`. `PATCH` with another ID replaces the photo,
  with `null` detaches it. A detached photo — including that of a deleted
  reading — stays for 24 hours and is then cleaned up.

```jsonc
// POST /api/utility/strom/readings
{ "meter_id": "m_strom_main", "date": "2026-10-07", "counter": 48212.3,
  "client_ref": "7d0f6c1e-2b8a-4c55-9e3d-0a1b2c3d4e5f",
  "attachment_id": "att_5f0c2a9e81d34b67" }
```

### Readings: `is_verified`, `is_billing_only` *(v3.9.0, additive)*

Two flags of the [check of intermediate readings](#check-intermediate-readings-v390),
stored only when set (`PATCH` with `false` removes them):

- `is_verified: true` — “Reading is correct”: the check no longer asks about any block
  with this reading. Changing `counter` or `date` cancels the confirmation (so does a
  CSV import with a different reading on the same day).
- `is_billing_only: true` — “only for the bill”: bill check, billing year, costs and
  balance keep using the reading; monthly values, heating model and forecast skip it.
  The consumption from the reading before to the next one without the flag is spread
  by the weather (`bridged`). Without a reading after it (or across a meter
  replacement) the reading stays in the monthly values. Also when creating
  (`POST …/readings`).

### Meters: `role`, `capture` *(v3.1.0, additive)*

Two optional fields on a meter, on creation and via `PATCH …/meters/{id}`.

**`role`** — what the meter stands for. The first role per utility is the
default and is **not stored** (missing = default); `null` or empty resets to
the default. The list per utility is in `GET /api/utilities` under
`meter_roles` (absent for utilities without roles).

| Utility | Roles (default first) | Effect |
|---|---|---|
| `strom` | `household`, `heat_pump`, `ev_charger` | `heat_pump` counts as heating energy in the efficiency figure |
| `wasser` | `cold`, `warm`, `garden` | `warm` gets `dhw_kwh` per month (hot-water heat) |
| `pv_erzeugung` | `generation`, `battery_charge`, `battery_discharge` | battery charging and discharging as meters of their own |
| `waerme` | `consumption`, `heat_pump_output` | only `consumption` counts in the efficiency figure; a heat pump's heat output would otherwise be counted twice next to its electricity |

Other utilities have no roles. An unknown role — or a role on a utility
without roles — gives `400` `errors.meter.roleInvalid`. For electricity the
older field `heat_source` (v2.10.0) stays in step: `role: "heat_pump"` sets
`heat_source: true`, any other role removes it, and `heat_source: true` without
`role` sets the role `heat_pump`. Older versions thus still recognise the heat
pump.

Meters with the roles `battery_charge`, `battery_discharge` and
`heat_pump_output` do **not count in the totals of their utility** — like
sub-meters: not in the utility’s monthly overview, the dashboard, the CSV
monthly overview, the PDF, efficiency, PV balance and CO₂. They measure energy
that is not consumption or generation of the utility and serve figures of their
own.

**`capture`** — the way of recording: `counter` (meter readings, default, not
stored) or `period` (consumption per period, [see below](#consumption-per-period-v310)).
`period` exists only for utilities with meter readings, not for heating oil and
pellets; any other value → `400` `errors.meter.captureInvalid`. It can only be
switched while the meter has no data of the previous kind (readings or
periods), otherwise `400` `errors.meter.captureLocked`.

### Meters: `malo_id`, `melo_id` *(v3.1.0, additive)*

Two optional fields for switching supplier, on creation and via
`PATCH …/meters/{id}`; only present when set. Spaces are removed, an empty
value deletes the field.

| Field | Form | Error (`400`) |
|---|---|---|
| `malo_id` | market location ID: 11 digits, the first not 0, the last a check digit per BDEW — add the digits in odd positions (1st, 3rd, … 9th) once and those in even positions (2nd, … 10th) twice; the check digit tops the sum up to the next ten. Example (synthetic): `51234567895` | `errors.meter.maloInvalid` |
| `melo_id` | metering location ID: 33 characters, `DE` and 31 digits or capital letters; lower case is converted to upper case | `errors.meter.meloInvalid` |

The interface offers both fields in the meter dialog (not for heating oil and
pellets); the switching decision shows the market location ID under “Have ready
for switching”.

### Meters: `digits` *(v2.6.0, additive)*

Register digits before the decimal point (3–12) per device. On creation as the
single field `digits` or inside the device; `PATCH …/meters/{id}` with `digits`
sets it on the installed device (`null`/empty removes it); `replace-device`
carries it over to the new device unless specified otherwise. Invalid → 400
`errors.meter.digitsInvalid`.

Since v2.6.0 the bodies described in `docs/API.md` up to v2.5.3 are accepted as
well: on creation an object `device {serial, installed_on, initial_counter}`,
on a meter swap `removed_on`, `final_counter` and
`new_device {serial, installed_on, initial_counter}`. The current names take
precedence.

### Meters: `obis` *(v3.9.0, additive)*

The meter’s register as an OBIS code, such as `1.8.0` (import), `1.8.1`
(import HT), `1.8.2` (import NT), `2.8.0` (feed-in), also with prefix
(`1-0:1.8.1`) and suffix (`*255`). Optional, on creation and with
`PATCH …/meters/{id}`; empty removes the field. The input is stored without
spaces; comparison uses the form `C.D.E` (`1-0:1.8.1*255` is `1.8.1`).
Invalid → 400 `errors.meter.obisInvalid`. A meter with several registers is
one meter per register with the same device number (`serial`).

- **Home Assistant** (`POST /api/ingest`): if `meter` matches neither alias nor
  ID, it counts together with `obis` as a device number: exactly the meter of
  the utility whose installed device carries this number and whose register
  matches.
- **Inbox:** entries of `meter_readings` also carry `obis` and `register`
  (label such as “HT”, “Einspeisung”; helper fields, `schema_version` 5). If the
  same meter number appears with several registers on the bill, each reading
  goes to the meter with that register (code on the meter, otherwise its name);
  a feed-in register on the electricity bill goes to solar feed-in (proposal
  with `utility`). Without a unique assignment these entries make no proposal.

### Set up a metering concept *(v3.9.0, F1032)*

The “metering concept” assistant ([#40](https://github.com/Bingerminger/energietracker/issues/40))
asks about the layout, not the name of the metering concept (names differ
between grid operators). Body of both routes:

| Field | Values |
|---|---|
| `concept` | `single` (one meter, bidirectional with solar), `side_by_side` (device with its own meter next to the main meter), `behind` (device as a sub-meter behind the main meter), `cascade` (the front meter measures everything, the household behind it, the device is the remainder) |
| `device` | `heat_pump` or `ev_charger`; required except for `single` |
| `pv`, `battery` | feed-in (`pv_einspeisung`, register `2.8.0`) and generation (`pv_erzeugung`); battery with charging and discharging (roles `battery_charge`, `battery_discharge`) |
| `tou` | `none`, `all` (HT/NT on every import meter), `device` (only on the device’s meter; not in a cascade: `400` `errors.concept.touCascade`) |
| `slots.<slot>` | `name`, `serial`, `obis.<register>` (prefilled `1.8.0` or `1.8.1`/`1.8.2`, validated like [`obis`](#meters-obis-v390-additive)), `meter_id` or `meter_ids.<register>` for an existing meter |
| `contracts.<slot>` | `provider`, `start`, `ct`, `ct_nt` (HT/NT), `base` and `advance` per month; left empty the slot gets no contract |

Slots: `main` (front or main meter), `house` (cascade: household), `dev`
(device), `feed`, `gen`, `bat_in`, `bat_out`. With HT/NT a slot becomes one
meter per register in a group with a group contract and a price per register; a
sub-meter hangs register by register behind the register of the same name of
its parent. The cascade sets roles by own consumption: the front meter gets
`heat_pump` or `ev_charger` (the remainder), the one behind it `household`.

Existing meters are assigned (role, register, parent, group), not created
again; an existing register is kept. A contract is only created where the
target has no running or agreed contract today (`skip_contract`). The preview
lists each step as `op` (`activate`, `create_meter`, `update_meter`,
`create_group`, `reuse_group`, `create_contract`, `skip_contract`) and under
`problems` obstacles such as `chain` (a meter with sub-meters is meant to hang
behind another one); `POST /api/metering-concept` then refuses with
`errors.concept.<code>`. Other errors: `errors.concept.field`,
`errors.concept.meterUnknown`, `errors.concept.meterTwice`.

### Consumption per period *(v3.1.0)*

The third way of recording next to meter readings and deliveries: each period
holds the consumption itself — for example from the monthly consumption
information of the metering service (HeizkostenV § 6a), from interval values of
a portal, or from a heat meter of which only monthly values are known. It
applies to meters with `capture: "period"` ([see above](#meters-role-capture-v310-additive))
of every utility with meter readings, not to heating oil and pellets (there
`400` `errors.common.unknownUtility`). Stability class A (master data).
Background: [Heat and consumption per period](../verstehen/15-waerme.md).

A period in the pot `<utility>/periods.json` (example values):

```json
{ "id": "p_8f2c41d0a9b3", "meter_id": "m_waerme_1",
  "from": "2026-09-01", "to": "2026-09-30",
  "value": 410.0, "value_unit": "consumption",
  "is_estimated": false, "source": "manual",
  "reference": { "prev_month": 180.0, "prev_year_month": 450.0, "average_user": 390.0 },
  "note": "Consumption information September",
  "attachment_id": "att_5f0c2a9e81d34b67",
  "client_ref": "7d0f6c1e-2b8a-4c55-9e3d-0a1b2c3d4e5f" }
```

| Field | Meaning |
|---|---|
| `from`, `to` | first and last day, **both inclusive** (`YYYY-MM-DD`). Not a calendar date → `400` `errors.period.dateInvalid`; start after end → `400` `errors.period.order` |
| `month` | only when writing: `YYYY-MM` as a shorthand for a whole month instead of `from`/`to` (invalid → `errors.period.dateInvalid`) |
| `value` | consumption in the period, a number ≥ 0 (as text also with a decimal comma), rounded to three decimals; otherwise `400` `errors.period.valueInvalid` |
| `value_unit` | `consumption` (default) = the utility's consumption unit (kWh, m³ for water) or `meter` = the meter unit. They only differ for gas: `meter` means m³, converted to kWh with the dated factors; with `consumption` the app works the m³ back out (bill check, CSV). For every other utility `consumption` is always stored |
| `is_estimated` | estimated (default `false`) |
| `source` | `manual` (default), `csv` (CSV import) or `import` |
| `reference` | optional, the comparison values of the consumption information: `prev_month` (previous month), `prev_year_month` (same month last year), `average_user` (average user), each a number ≥ 0; without values `null`. For display only, they do not enter the calculation |
| `note` | at most 500 characters |
| `attachment_id` | optional: a receipt for the period, such as the consumption information as a PDF ([Receipts](#receipts-and-text-recognition-v310)); `PATCH` with another ID replaces it, with `null` detaches it |
| `client_ref` | as for readings ([see above](#readings-client_ref-attachment_id-v310-additive)): if the same meter already has a period with this identifier, `POST` answers **`200`** with the existing period and `duplicate: true`; otherwise `201`. Wrong format → `400` `errors.reading.clientRefInvalid` |

**Rules.**

- `POST` needs `meter_id` (without it, the utility's default meter applies).
  Unknown meter → `404` `errors.common.meterNotFound`; a meter with meter
  readings → `400` `errors.period.meterNotPeriod`.
- Periods of the same meter must not overlap, not even on one day → `400`
  `errors.period.overlap`, the message names the existing period. Gaps in
  between are allowed.
- `PATCH` changes only the fields sent; the meter stays. Unknown ID → `404`
  `errors.period.notFound`, likewise for `DELETE` (response `{deleted: true}`;
  a receipt is detached and cleaned up after 24 hours).
- A meter with consumption per period accepts **no readings**: `POST
  …/readings` and the readings import answer `400` `errors.reading.periodMeter`,
  the ingest `400` `errors.ingest.periodMeter` (in a batch as a result row with
  this `code`).

**Calculation.** Per period a daily rate = `value` ÷ days (from `from` to `to`
inclusive), spread over the months to the day. Gaps stay gaps: a month only
counts its covered days (`days`), as with readings. After that everything runs
as with meter readings — monthly rows, contracts and balance, CSV monthly
overview, PDF, forecast, weather adjustment. In the agenda the next entry is due
`alert_days_since_reading` days after the end of the last period.

**CSV import — `POST /api/utility/{u}/meters/{id}/periods/import-csv`.** The
CSV as the raw body, as for the readings import. Per row:

```text
month;value[;note]          month as MM.YYYY, MM/YYYY or YYYY-MM (whole month)
from;to;value[;note]        dates as in the readings import (ISO or D.M.YYYY …)
```

Separator `;`, tab or `,`; header optional. The app's own export
(`periods.csv`, format 1 and "local") can be read back in: if the header has a
meter ID column, only the rows of this meter count. Imported periods have
`source: "csv"` and `value_unit: "consumption"`. A row that overlaps an existing
period or an earlier one in the file is **skipped and reported**, not
overwritten. Empty file → `400` `errors.import.emptyCsv`.

```json
{ "imported": 11, "skipped": 1,
  "errors": [ "Line 5: The period overlaps an existing one (2026-03-01 – 2026-03-31)." ] }
```

Row errors in `errors` (language of the request): `errors.period.csvDate`
(period not recognised), `errors.period.csvValue` (value not a number or
negative), `errors.period.csvLine` (otherwise, with the message — such as the
overlap). With **`?dry_run=1`** nothing is written; the response carries
`imported: 0`, `skipped`, `errors`, `dry_run: true`, `would_import` (how many
could be imported) and `rows` (`[{line, from, to, value, note}]`).

**Export:** `GET /api/export/{u}/periods.csv` — all periods of the utility,
[CSV formats](#csv-formats-v310).

### Tenancy *(v3.1.0)*

For tenants who pay for heating and water through the service charges (F1008).
The app records what the landlord asks for and bills, and calculates an
**estimate** from it — not a service charge statement and not a billing program
for landlords. The interface shows the page (Costs & contracts → Tenancy) only
with the setting `wohnverhaeltnis: "miete"`; the routes answer regardless.
Master data (tenancy, statements) stability class A, the budget class B. Step
by step: [As a tenant](../anleitungen/mieter.md).

Amounts (`*_eur`) are in the major unit of the configured currency, not
converted. Dated lists (`prepayments`, `prices`, `fixed_costs`) apply per entry
from `from`; `PATCH` always takes a list **whole** and sorts it by `from`.

**Tenancy** (`tenancies.json`, example values):

```json
{ "id": "t_1a2b3c4d5e6f", "label": "Flat, 2nd floor", "landlord": "Example Property Management",
  "start": "2024-04-01", "end": null, "wohnflaeche_m2": 68,
  "co2_own_appliances": false, "co2_restriction": "none",
  "billing_anchor": "01-01",
  "prepayments": [ { "from": "2024-04-01", "heating_eur_month": 70, "operating_eur_month": 50 } ],
  "prices": [ { "from": "2026-01-01", "heat_eur_per_kwh": 0.15, "warm_water_eur_per_m3": 12,
                "cold_water_eur_per_m3": 5, "source": "statement", "statement_id": "s_9e8d7c6b5a40" } ],
  "fixed_costs": [ { "from": "2026-01-01", "label": "Waste collection", "eur_per_year": 180 } ],
  "meter_ids": { "heat": ["m_waerme_1"], "warm_water": ["m_wasser_warm"], "cold_water": ["m_wasser_kalt"] },
  "notes": "" }
```

| Field | Meaning |
|---|---|
| `start`, `end` | start (required) and end (`null` or empty = ongoing); end before start → `400` `errors.tenancy.endBeforeStart` |
| `label`, `landlord`, `notes` | text, at most 120 or 2000 characters |
| `wohnflaeche_m2` | optional, 1–10,000 — floor area per the tenancy agreement; empty means the setting `wohnflaeche_m2` applies. Basis of the CO₂ stage ([CO₂ price and sharing](#co₂-price-and-sharing-v310)) |
| `co2_own_appliances` | *(v3.1.0, H4)* `true` if the gas also supplies your own appliances such as a gas cooker — the refund with self-contained heating drops to 95 % (default `false`) |
| `co2_restriction` | *(v3.1.0, H4)* public-law restrictions under § 9 CO2KostAufG: `none` (default), `one` (against renovation **or** replacing the heating — share halved), `both` (against both — no sharing); an unknown value becomes `none` |
| `billing_anchor` | start of the billing period as `MM-DD`, default `01-01`; not a calendar day → `400` `errors.tenancy.anchorInvalid` |
| `prepayments` | `{from, heating_eur_month, operating_eur_month}` — monthly prepayment for heating and operating costs |
| `prices` | `{from, heat_eur_per_kwh?, warm_water_eur_per_m3?, cold_water_eur_per_m3?, source, statement_id?}` — prices per kWh of heat, per m³ of hot water and per m³ of cold water (sewage included), each 0–1000; `source` `statement` (from a statement) or `estimate` (default). For each price the most recent entry carrying it applies |
| `fixed_costs` | `{from, label, eur_per_year}` — flat charges (waste, cleaning, insurance …); per label the most recent entry applies |
| `meter_ids` | assigned meters: `heat` (heat), `warm_water` and `cold_water` (water); unknown ID → `400` `errors.tenancy.meterNotFound` |

A list entry without a valid `from` → `400` `errors.tenancy.dateInvalid`;
anything other than a list of objects → `errors.tenancy.listInvalid`; an amount
that is not a number or outside its range → `errors.tenancy.amountInvalid`.
`DELETE` deletes the tenancy **together with its statements**; their receipts
are detached and cleaned up after 24 hours. Response `{deleted: true}`.

**Service charge statement** (`tenancy_statements.json`, example values):

```json
{ "id": "s_9e8d7c6b5a40", "tenancy_id": "t_1a2b3c4d5e6f",
  "period_from": "2025-01-01", "period_to": "2025-12-31", "received_on": "2026-06-15",
  "total_cost_eur": 1740, "prepaid_eur": 1440, "result_eur": 300,
  "positions": [
    { "label": "Heating",     "category": "heating",    "amount_eur": 900 },
    { "label": "Hot water",   "category": "warm_water", "amount_eur": 240, "consumption": 20, "unit": "m³" },
    { "label": "Cold water",  "category": "cold_water", "amount_eur": 150, "consumption": 60, "unit": "m³" },
    { "label": "Sewage",      "category": "sewage",     "amount_eur": 150 },
    { "label": "Operating costs", "category": "operating", "amount_eur": 300 } ],
  "heat": { "consumption": 6000, "unit": "kWh", "cost_eur": 900 },
  "co2": null,
  "new_prepayment": { "from": "2026-07-01", "heating_eur_month": 80, "operating_eur_month": 55 },
  "attachment_ids": [ "att_0b1c2d3e4f5a6b7c" ], "booked": false, "note": "",
  "created_at": "2026-06-16T19:02:11+02:00" }
```

| Field | Meaning |
|---|---|
| `period_from`, `period_to` | the billed period (required); start after end → `400` `errors.tenancy.periodInvalid` |
| `received_on` | the day the statement arrived (optional) — start of the objection deadline in the agenda |
| `total_cost_eur`, `prepaid_eur` | total costs and the prepayments credited |
| `result_eur` | the result, **positive = additional payment**, negative = credit (as balance and budget). If not given, `total_cost_eur − prepaid_eur`, also after a change of either |
| `positions` | items `{label, category, amount_eur, consumption?, unit?}`; `category` ∈ `heating`, `warm_water`, `cold_water`, `sewage`, `operating`, `other` (unknown → `other`) |
| `heat` | optional: heat consumption on the statement `{consumption, unit: kWh \| MWh, cost_eur?}` |
| `co2` | optional: the CO₂ information on the heating cost statement (§ 7 CO2KostAufG) — `{emissions_kg?, cost_eur?, stage?, landlord_share_pct?, landlord_amount_eur?}`; emissions 0–10,000,000 kg, amounts 0–1,000,000, `stage` 1–10, `landlord_share_pct` 0–100, otherwise `400` `errors.tenancy.amountInvalid`; without values `null`. Since v3.1.0 (H4) validated instead of stored as it comes. When it is set, `GET /api/co2-split` calculates the central-heating case for the year in which the period ends |
| `new_prepayment` | optional: new prepayment as per the statement `{from, heating_eur_month, operating_eur_month}` |
| `attachment_ids` | receipts (PDF or photo, [Receipts](#receipts-and-text-recognition-v310)); a receipt removed from the list is detached |
| `booked` | booked yes/no (default `false`) |
| `note`, `created_at` | note (at most 2000 characters), time of creation |

**Taking over on save.** `POST` and `PATCH` of a statement accept two switches
that are not stored themselves:

- `apply_prices: true` — prices from the statement into the tenancy: the costs
  of the category (fixed charges included) divided by its consumption. Heat =
  `heat.cost_eur` (if not given, the sum of the `heating` items) ÷
  `heat.consumption` (MWh × 1000); hot water = `warm_water` items ÷ their
  consumption; cold water = `cold_water` **and** `sewage` items ÷ the
  consumption of the `cold_water` items (sewage per m³ of fresh water). Without
  a consumption there is no price. The new entry applies from the day after
  `period_to`, with `source: "statement"` and `statement_id`; an entry taken
  over earlier from the same statement is replaced. In the example: €900 ÷
  6,000 kWh = €0.15/kWh, €240 ÷ 20 m³ = €12/m³, (€150 + €150) ÷ 60 m³ = €5/m³.
- `apply_prepayment: true` — take `new_prepayment` into `prepayments` (an entry
  with the same `from` is replaced).

Unknown tenancy → `404` `errors.tenancy.notFound`, unknown statement → `404`
`errors.tenancy.statementNotFound`.

**Budget — `GET /api/tenancies/{id}/budget[?as_of=YYYY-MM-DD]`.** The
prepayment against the costs the current billing period is likely to bring — an
estimate from your own meters and the prices of the last statement; what the
landlord bills may differ. `as_of` is the reference date (missing or not a
date: today).

- **Period:** one year from the `billing_anchor` that `as_of` falls into,
  limited to the start and end of the tenancy — the first period starts on
  moving in. Months at the edges count pro rata (`share`).
- **Per month** (prices, prepayment and flat charges as of the 15th):

```text
expected = heat kWh × price per kWh + hot-water m³ × price + cold-water m³ × price
           + flat charges per year / 12                              (all × share)
paid     = prepayment heating + prepayment operating costs           (× share)
```

- **Consumption:** measured from the assigned meters (readings or periods) when
  they cover the whole month; otherwise estimated — heat from the meter's
  heating model with the climate normal, else from the same month last year,
  else from the daily mean of all known months. A month is `measured` when it
  has ended by `as_of` and all meters cover it fully.

```json
{ "success": true, "data": {
  "tenancy_id": "t_1a2b3c4d5e6f",
  "period_from": "2026-01-01", "period_to": "2026-12-31", "as_of": "2026-10-07",
  "months": [
    { "ym": "2026-01", "share": 1, "heat_kwh": 1200.0, "warm_water_m3": 1.7, "cold_water_m3": 2.0,
      "expected_eur": 225.4, "prepaid_eur": 120, "balance_eur": 105.4, "measured": true },
    { "…": "…" } ],
  "expected_eur": 1500.0, "prepaid_eur": 1440, "projected_result_eur": 60.0,
  "to_date": { "expected_eur": 1150.0, "prepaid_eur": 1080, "result_eur": 70.0 },
  "risk": "medium", "suggested_prepayment_eur": 125,
  "components": { "heat_eur": 960.0, "warm_water_eur": 240.0, "cold_water_eur": 120.0, "fixed_eur": 180.0,
                  "heat_kwh": 6400.0, "warm_water_m3": 20.0, "cold_water_m3": 24.0 },
  "assumptions": [ "months_estimated" ], "months_estimated": 3 } }
```

In the example: 6,400 kWh × €0.15 + 20 m³ × €12 + 24 m³ × €5 + €180 flat
charges = €1,500 expected, against 12 × €120 = €1,440 prepayment → €60 likely
additional payment, just over 4 % of the prepayment, hence `medium`; suitable
prepayment €1,500 ÷ 12 = €125. October to December are estimated.

| Field | Meaning |
|---|---|
| `tenancy_id`, `period_from`, `period_to`, `as_of` | tenancy, billing period, reference date |
| `months[]` | per month `ym`, `share` (share of the period, 0–1), `heat_kwh`, `warm_water_m3`, `cold_water_m3`, `expected_eur`, `prepaid_eur`, `balance_eur` (running: expected − paid), `measured` |
| `expected_eur`, `prepaid_eur` | totals over the whole period, measured and estimated |
| `to_date` | `{expected_eur, prepaid_eur, result_eur}` — over the measured months only |
| `projected_result_eur` | `expected_eur − prepaid_eur`: positive = likely additional payment, negative = credit |
| `risk` | `low` (result ≤ 0), `medium` (additional payment up to 10 % of the prepayment), `high` (more); `null` without a prepayment |
| `suggested_prepayment_eur` | expected costs of the period ÷ number of its months, rounded up to a whole unit — for a full year, the annual costs / 12 |
| `components` | totals of the period: `heat_eur`, `warm_water_eur`, `cold_water_eur`, `fixed_eur`, `heat_kwh`, `warm_water_m3`, `cold_water_m3` |
| `assumptions` | what the calculation assumes: `price_missing_heat`, `price_missing_warm_water`, `price_missing_cold_water` (meter assigned but no price — the costs are missing), `prepayment_missing` (no prepayment for a month), `months_estimated`, `no_meters` (flat charges only) |
| `months_estimated` | number of estimated months |

The deadlines of the service charge statement appear in the
[agenda](#get-apiagendadays90-v310) and the calendar once `wohnverhaeltnis` is
set to `miete`. Sharing the CO₂ costs between tenant and landlord:
[CO₂ price and sharing](#co₂-price-and-sharing-v310).

### `GET|PATCH /api/settings` — `gas_conversion_factors` *(F1012, v2.5.0)*

Since schema 1.5.0 the scalar `gas_conversion_factor` is a **dated list**;
the migration turns the legacy value into the undated entry:

```json
"gas_conversion_factors": [
  { "from": null,         "zustandszahl": null, "brennwert": null,  "kwh_per_m3": 11.5 },
  { "from": "2024-01-01", "zustandszahl": 0.96, "brennwert": 11.4,  "kwh_per_m3": 10.944 },
  { "from": "2025-01-01", "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184 }
]
```

- `PATCH` takes the whole list (no single-entry edit); a decimal comma is
  accepted. When `zustandszahl` (volume correction factor) **and**
  `brennwert` (calorific value) are set, `kwh_per_m3` is derived from them
  (5 decimals) and overrides any supplied value. Only one of the two → 400.
- Plausibility (400 with `errors.settings.*`): volume correction 0.8–1.1,
  calorific value 8–13, factor 5–15; at most one undated entry; no duplicate
  dates. The response is sorted by `from`, undated first.
- Empty list → default (undated 11.5).
- Effect: `kwh` in every consumption response is calculated **day-exact**
  with the factor valid on that day; an effective date inside a reading
  interval splits the interval.

### Assumed values: `assumed_keys` *(v3.6.0)*

`GET /api/settings`, `PATCH /api/settings` and `POST /api/settings/country`
return the list `assumed_keys` next to the settings (additive): values that are
only the default because nobody has entered them — currently `wohnflaeche_m2`
and `wasser_personen_anzahl`.

```json
{ "wohnflaeche_m2": 100, "wasser_personen_anzahl": 3, …,
  "assumed_keys": ["wohnflaeche_m2"] }
```

- The value itself stays the default; efficiency class, CO₂ stage, water
  saving index and annual report keep calculating with it. Only the benchmark
  (`GET /api/benchmarks/comparison`) waits for the entry. Where the assumed
  area flows in, the response says so with `area_assumed: true`:
  `GET /api/benchmarks/efficiency`, `GET /api/co2-split` and `per_m2` in
  `GET /api/co2-costs` (where the tenancy’s area comes first).
- Entered means: `settings.json` holds the key — saved via `PATCH`, from a
  backup or a sample household, even with a value equal to the default.
  `PATCH` with `null` or `""` withdraws it (the key disappears from
  `settings.json`).
- `assumed_keys` is not a setting: sent in a `PATCH`, it is listed under
  `ignored_keys`.
- *(v3.7.0, additive)* `example_keys`: the same form for the sample values of
  the flexibility analysis (`flex_*`, [settings](einstellungen.md#flexibility-v370))
  that nobody has entered. The default is used; entered (even equal to the
  default) means “your value”, `null` or `""` withdraws it. `example_keys` is
  not a setting either.

### Country profile: `country`, `currency`, `timezone`, `gas_cv_unit` *(v2.7.0, additive)*

Four new settings; the defaults keep the previous behaviour (`DE`, `EUR`,
`Europe/Berlin`, `kwh`). `PATCH` rejects unknown values with 400
(`errors.settings.valueInvalid`):

| Key | Allowed | Effect |
|---|---|---|
| `country` | `DE AT CH FR IT ES PT NL GB` | how numbers and dates are written (together with the language), efficiency scale |
| `currency` | `EUR CHF GBP` | symbol and minor unit in every text. Amounts are **not** converted; `*_eur`/`ct_per_kwh` mean the major/minor unit of the chosen currency |
| `timezone` | IANA name (`Europe/Vienna`) | PHP time zone (today, due dates) and day boundaries of the weather data |
| `gas_cv_unit` | `kwh mj gj` | unit in which the interface takes the calorific value; storage is always kWh/m³ |

`GET /api/countries` returns the profiles the interface proposes values from
when the country changes (class C):

```json
{ "code": "AT", "languages": ["de"], "currency": "EUR", "timezone": "Europe/Vienna",
  "hdd_base_temp": 15.0, "co2_strom": 103.0, "co2_strom_source": "ember-2024",
  "efficiency_scale": null, "gas_cv_unit": "kwh",
  "location_name": "Wien", "latitude": 48.2082, "longitude": 16.3738 }
```

The German profile additionally carries `co2_strom_years` (yearly values of
the German Environment Agency, since v2.10.0) and `"co2_strom_source": "uba"`.

**Since v3.1.0**, additively, per profile:

```json
"bill_terms": { "workingPrice": "Energie-Verbrauchspreis", "basePrice": "Energie-Grundpreis",
                "advance": "Teilbetrag", "calorificValue": "Verrechnungsbrennwert",
                "balance": "Nachzahlung / Guthaben" },
"comparison_portal": "https://www.e-control.at/tarifkalkulator"
```

- `bill_terms`: glossary ID (`glossary.<id>` in the catalogue) → the wording on
  the country's bill, in the language of the bill. Only documented terms — a
  missing ID means the bill has no item with the same meaning. Switzerland
  carries one more level, per language: `{"de": {…}, "fr": {…}, "it": {…}}`.
- `comparison_portal`: address of the official or regulatory tariff
  comparison, or `null` (Germany, Switzerland, Netherlands, United Kingdom).

Terms, portals and sources:
[country profiles §8](../verstehen/14-laenderprofile.md#8-what-your-bill-calls-it).

On **first start** (empty data directory) the app picks language and country
from `Accept-Language` and writes only the values that differ from the
defaults. After that the header changes nothing; since v3.1.0 a device picks
its language via `X-ET-Language`
([language of the responses](#language-of-the-responses-v310)). Details:
[country profiles](../verstehen/14-laenderprofile.md).

**Changing the country *(v3.5.0)*:** `POST /api/settings/country` with
`{"country": "AT", "profile": true}` sets the country and every value of its
profile (`currency`, `timezone`, `hdd_base_temp`, `co2_strom`,
`co2_strom_years`, location, `gas_cv_unit`); with `"profile": false` only the
country. The rule is the same as on first start: a value that equals the
default is not stored, and an earlier stored value is dropped. Whoever picks
Germany after an English first start (profile GB) has no profile value left in
`settings.json`. Response: all settings, as with `PATCH`; an unknown country
ends with 400 `errors.settings.valueInvalid`. The setup assistant and the
settings change the country this way; `PATCH /api/settings` with `country`
remains valid.

### CO₂ factors per year, corrected defaults *(v2.10.0)*

- `co2_strom_years`: object `{"2024": 353, "2025": 344}` (year → g/kWh).
  `PATCH` also accepts it as a list `[{year, g_per_kwh}]`; years 1990–2100,
  values 0–2000, otherwise 400 `errors.settings.valueInvalid`. A year uses the
  value of the last listed year up to it; before the first listed year,
  `co2_strom` applies. Monthly rows (`co2_kg`) use the factor of their year.
- New defaults with a source: `co2_gas` 182 (BAFA 201 on net calorific value ×
  0.906), `co2_pellets` 36, `co2_fernwaerme` 280, `co2_strom_years` German
  Environment Agency (UBA) 2015–2025, `wasser_personen_referenz` 122 (BDEW
  2024).
- **Existing installations:** the 1.6.0 migration pins the previous default for
  each of these keys that the installation never saved (`co2_strom_years: []`).
  `GET /api/settings/default-updates` returns what could be adopted:
  `[{ "key": "co2_gas", "current": 201, "recommended": 182 }, …]` — only keys
  that still carry exactly the old default. Adopting them is done via
  `PATCH /api/settings`.
- `beheizter_keller`, `warmwasser_dezentral` (bool, default `false`) for the
  certificate-style figure.

### PV: rates, savings, tariff rank *(v2.10.0)*

`GET /api/pv-summary`: monthly rows carry `covered` (all three meters have
data), `bezug_price_ct`, `savings_eur` (self-consumption × working price of the
import) and `feed_in_revenue_eur`; in months that are not covered,
`eigenverbrauch_kwh` and the rates are `null`. Annual rows carry
`months_with_data`, `months_covered`, `savings_eur`, `feed_in_revenue_eur`,
`pv_benefit_eur`; self-consumption and rates are calculated over covered months
only (up to v2.9 over all months — with "0" for uneven coverage).

`tariff-switch` and `tariff-comparison` return `higher_is_better`: `true` for the
feed-in; the offers are then sorted by **higher** revenue.

### PV: battery, plug-in solar, payback *(v3.1.0, additive)*

**Fields on the meter** (`pv_erzeugung`, all optional; empty or `false`
removes them, invalid → `400` `errors.meter.valueInvalid`):

| Field | Meaning |
|---|---|
| `plug_in` | `true` = plug-in solar device (balcony system) without a feed-in meter |
| `investment_eur` | investment incl. VAT, 0–10,000,000 |
| `commissioned_on` | date of commissioning (ISO) |
| `battery_capacity_kwh` | storage capacity, 0–10,000 — on the meter with the role `battery_charge` |

Meters with the roles `battery_charge` and `battery_discharge` do not count
towards generation (nor towards sums). Self-consumption keeps its definition;
since v3.4.0 self-sufficiency uses the energy flow
([below](#explainers-energy-flow-and-timeline-v340-additive)).

**`GET /api/pv-summary`** additively:

- per year row `battery: {charged_kwh, discharged_kwh, losses_kwh,
  efficiency_pct, full_cycles}` or `null` (without battery meters);
  `full_cycles` = charged ÷ capacity, only with `battery_capacity_kwh`.
- `payback: {investment_eur, commissioned_on, benefit_to_date_eur,
  avg_benefit_12m_eur, years_to_break_even, break_even_ym,
  break_even_projected}` — `null` without an investment. Benefit = avoided
  purchase (self-consumption × unit price) + feed-in revenue since
  commissioning; if the investment has not come back yet, `break_even_ym`
  projects with the benefit of the last twelve months
  (`break_even_projected: true`). Example: €800 at €160 a year = 5 years.
- `self_consumption_assumed_pct` — set when a generation meter carries
  `plug_in`, there is no feed-in meter and the setting
  `pv_assumed_self_consumption_pct` has a value. Self-consumption is then
  generation × share, and the months carry `self_consumption_assumed: true`.
- `hints: ["negative_prices"]` — country Germany and commissioning on or after
  25 Feb 2025: there is no feed-in tariff for periods with a negative wholesale
  price — for systems below 100 kW from the calendar year after a smart
  metering system is installed (§ 51 EEG). A longer funding period partly
  offsets this (§ 51a EEG).
- *(since v3.7.0, #39)* `hints` contains `"interpolated"` when a month rests on
  an interpolated series; in addition `interpolation: {utility, readings, from,
  to, months, interpolated_months}` — the series with the most interpolated
  months and its real readings, otherwise `null`. Each monthly row carries
  `interpolated` (bool) and `interpolated_series` (which fields:
  `erzeugung_kwh`, `einspeisung_kwh`, `bezug_kwh`), each yearly row
  `months_interpolated`. Self-consumption, self-sufficiency, savings and payback
  of these months are estimates; their values stay unchanged.

The setting `co2_pv_avoided` (g/kWh) replaces the electricity mix in the
avoided CO₂ of PV generation and feed-in ([Settings](einstellungen.md)).
Credit notes from the direct marketer on the feed-in:
[Contracts](#contracts-reduced-grid-fee-price-model-credit-notes-v310-additive).

### Explainers: energy flow and timeline *(v3.4.0, additive)*

**`GET /api/pv-summary`**, additionally per monthly row:

| Field | Meaning |
|---|---|
| `battery` | `{charged_kwh, discharged_kwh}` of the month; `null` without battery meters |
| `heat_pump_kwh` | consumption of the electricity meters with the role `heat_pump` in the month (sub-meters too); `null` without such a meter |
| `ev_charger_kwh` | the same for the role `ev_charger` (wallbox) |
| `flow` | energy flow of the month (see below); `null` if the month is not covered (`covered: false`) |

Additionally per yearly row `heat_pump_kwh` and `ev_charger_kwh` (sum of all
months, like `bezug_kwh`) and `flow`: the sum of the monthly flows over the
**covered** months, with `months` (= `months_covered`); `null` without a
covered month. This way the key figure and the explainer use the same months.

`flow` — all amounts in kWh:

| Field | Meaning |
|---|---|
| `pv_house`, `pv_battery`, `pv_grid` | solar → house, → battery, → grid (sum = generation) |
| `grid_house`, `grid_battery` | grid → house, grid → battery (sum = import) |
| `battery_house` | battery → house (= discharged) |
| `house` | house consumption = `pv_house + battery_house + grid_house` |
| `grid_in_house` | grid power in the house, also via the battery |
| `heat_pump`, `ev_charger` | share of heat pump and wallbox in the house consumption; `null` without a meter |
| `autarky` | `1 − grid_in_house / house` (0–1), `null` below 0.1 kWh |
| `months` | yearly rows only: number of months |

`pv_battery` and `grid_battery` are calculated from monthly totals: solar
charges the battery first, the grid supplied the rest. Formula:
[PV §4](../verstehen/12-pv.md#4-self-consumption--self-sufficiency-rate).

**Changed values:** since v3.4.0 `autarkiequote` (month and year) is
`flow.autarky`. Without a battery it is the same figure as before; with a
battery its losses no longer count as used in the house, so the rate drops
slightly. `eigenverbrauch_kwh` and `eigenverbrauchsquote` are unchanged;
`summary.pv.autarky_pct_ytd` follows the key figure.

**`contract-status`**, additionally per contract:

| Field | Meaning |
|---|---|
| `min_term_end` | end of the minimum term from the contract or `null` |
| `price_guarantee_until` | end of the price guarantee from the contract or `null` |
| `auto_renews` | renews without cancellation (bool, effective value: missing in the contract = `true`) |

The meaning of `cancel_by`, `switch_date` and `notice_basis` is unchanged; the
explainer “Contract at a glance” shows “cancel any time” for renewed and
open-ended contracts and `switch_date − 1` as the earliest end.

### Day film: energy flow per quarter hour *(v3.5.0)*

The energy flow of a day from the quarter-hour series
([Flexibility](#valuing-flexibility-v330), storage in the
[data model](datenmodell.md#quarter-hour-series-intervalsjson-v330)) — for the
explainer “Energy flow in the house”, level **“Day”**
([Views](ansichten.md#1-overview-dashboard)). Class B, read only.

| Route | Purpose |
|---|---|
| `GET /api/energy-flow/days` | days and months that can be shown; defaults |
| `GET /api/energy-flow/day?date=YYYY-MM-DD` | one day per quarter hour |
| `GET /api/energy-flow/typical-day?month=YYYY-MM` | typical day of a month |

**Which meters.** As in the energy flow of month and year: grid import is the
electricity meters that count in totals — **sub-meters do not count** (F1006);
feed-in and generation are the PV meters that count in totals; the battery is
the meters with the roles `battery_charge` and `battery_discharge`. A kind
without meters is 0 (no PV: generation and feed-in 0, the house is the import).

**Complete or gap.** A quarter hour is complete when every meter in service on
that day (active, with a device installed) has a value. Otherwise it is a
**gap**: the missing kind `null`, `house` and `flow` `null` — never a silent 0.
With generation but no feed-in meter (or the other way round), the other half
is always missing: without it, generation cannot be split. Without quarter-hour
values of grid import there is no day film.

**Local time.** A day runs from midnight to midnight in the installation's time
zone (`timezone`): 96 quarter hours, on the day the clocks change 92 in March
(2:00–2:45 does not exist) and 100 in October (2:00–2:45 twice, the second time
with `"repeat": true`). The typical day averages by time of day: 96 quarter
hours, `n` = number of quarter hours in the average.

**`GET /api/energy-flow/day?date=2025-06-14`** (made-up values, shortened):

```json
{ "mode": "day", "date": "2025-06-14", "month": "2025-06",
  "timezone": "Europe/Berlin", "step_minutes": 15,
  "slots": [
    { "t": "00:00", "grid": 0.082, "gen": 0, "feed": 0, "charged": 0, "discharged": 0.05,
      "house": 0.132, "price": 9.84,
      "flow": { "pv_house": 0, "pv_battery": 0, "pv_grid": 0, "grid_house": 0.082, "grid_battery": 0,
                "battery_house": 0.05, "house": 0.132, "grid_in_house": 0.082, "autarky": 0.3788 } },
    "…",
    { "t": "15:00", "grid": null, "gen": 0.41, "feed": 0.12, "charged": 0, "discharged": 0,
      "house": null, "price": 7.9, "flow": null },
    "…" ],
  "totals": { "grid": 6.412, "gen": 24.87, "feed": 11.903, "charged": 4.2, "discharged": 3.75,
              "flow": { "house": 18.929, "grid_in_house": 6.412, "autarky": 0.6613, "…": "…" },
              "house": 18.929, "autarky": 0.6613 },
  "completeness": { "slots": 96, "complete": 94, "price": 96 },
  "kinds": { "gen": true, "feed": true, "battery": true, "price": true },
  "missing": [] }
```

| Field | Meaning |
|---|---|
| `slots[].t` | start of the quarter hour, local time `HH:MM`; `repeat` only for the second hour in October |
| `slots[].grid`, `gen`, `feed`, `charged`, `discharged` | kWh in the quarter hour (sum of the meters of the kind); `null` = missing |
| `slots[].house` | house consumption from the flow; `null` in a gap |
| `slots[].price` | wholesale price of the quarter hour in ct/kWh (SMARD, DE-LU); `null` without a price |
| `slots[].flow` | flow of the quarter hour as in [Explainers](#explainers-energy-flow-and-timeline-v340-additive), amounts in kWh with 4 decimals; `null` in a gap |
| `slots[].n` | typical day only: number of quarter hours in the average |
| `totals` | amounts over the complete quarter hours and the flow **from these amounts** — calculated like a month, so grid charging stays grid power even if the battery only discharges in the evening; `house`, `autarky` from it |
| `completeness` | number of quarter hours, complete ones and ones with a price |
| `kinds` | which kinds the household has (for the nodes of the picture) |
| `missing[]` | `{kind, utility, meter_id, name}`: meters that should deliver values on that day but have no quarter-hour series; `meter_id: null` = missing half of the PV |
| `days` | typical day only: days of the month with at least one complete quarter hour |

Power in kW is `kWh × 60 / step_minutes` (× 4 at 15 minutes).

**`GET /api/energy-flow/days`**: `available` (bool), `days[]` (days with at
least one quarter hour of grid import, ascending, without today and later),
`complete_days[]` (those without a gap), `months[]`, `default_day` (last
complete day, otherwise the last with data) and `default_month` (latest month)
— `null` without data.

Errors: `400` with `errors.energyFlow.dateInvalid` or
`errors.energyFlow.monthInvalid` if `date` is not a calendar day or `month` not
a month, or the year lies outside 2000–2100.

### `GET /api/utility/{u}/meters/{id}/bill-check?from=…&to=…` *(F1012, v2.5.0; all utilities since v3.1.0)*

Recalculates the supplier bill: one section per boundary within the range.
`to` is exclusive, `to_inclusive` the last calendar day of the section;
`from < to` in ISO form, otherwise 400 `errors.billCheck.invalidRange`.

**Since v3.1.0 (package H5)** for **every utility with meter readings and a
contract**: gas, electricity, water, district heating (`supports_bill_check` in
`GET /api/utilities`). Not heating oil, pellets, PV feed-in, PV generation and
heat — there `400` with the new code `errors.billCheck.unsupportedUtility`. Up
to v3.0 the error was `errors.billCheck.gasOnly`; the key stays in the catalogue
but is no longer sent (**deprecated**). Unknown meter → `404`.

Section boundaries:

- **Gas:** start, end, every reading, every change of calorific value or
  volume correction factor — as before. If the price changes inside a section,
  the cost calculation splits it internally by days; no row of its own is
  created.
- **Electricity, water, district heating:** start, end, every reading and every
  effective date of the contracts (start, day after the end, working, base,
  capacity and metering prices, advances) — `reason` then `price`, with a
  reading on the same day `reading+price`. Consumption per section in `kwh`
  (water: `m3`).

The gas response (cost fields since v3.1.0, see below):

```json
{
  "from": "2025-01-01", "to": "2026-01-01",
  "rows": [
    { "from": "2025-01-01", "to": "2025-03-10", "to_inclusive": "2025-03-09",
      "days": 68, "reason": "start",
      "m3": 412.3, "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184,
      "kwh": 4611.2 },
    { "from": "2025-03-10", "to": "2025-10-01", "to_inclusive": "2025-09-30",
      "days": 205, "reason": "reading", "m3": 301.0, "…": "…" },
    { "from": "2025-10-01", "to": "2025-11-04", "to_inclusive": "2025-11-03",
      "days": 34, "reason": "factor", "…": "…" },
    { "from": "2025-11-04", "to": "2026-01-01", "to_inclusive": "2025-12-31",
      "days": 58, "reason": "reading", "m3": null, "kwh": null, "…": "…" }
  ],
  "totals": { "days": 365, "m3": 812.4, "kwh": 9034.7, "gaps": 1 }
}
```

`reason` ∈ `start`, `end`, `reading`, `reading_estimated`, `factor`, since
v3.1.0 `price` — combinations joined with `+` (`reading+factor`). **Since v2.5.2** every row
carries `counter_from`/`counter_to` (meter reading at the start/end of the
section) with `counter_from_kind`/`counter_to_kind` ∈ `reading` (real
reading), `reading_estimated` (recorded as estimated), `interpolated`
(substitute value: no reading on that day, interpolated day-exact) or
`null` (no enclosing interval). Across a meter swap the substitute value is
`null` with `kind = interpolated`. `m3`/`kwh` are `null` when
no reading interval encloses the section (before the first, after the last
reading); `totals.gaps` counts those sections, they are missing from the
sums. `m3` per section is the linear interpolation of the enclosing
interval — the supplier estimates at the very same places.

**Costs *(v3.1.0, additive)*.** Every row also carries the section’s costs,
calculated with the same building blocks as the monthly rows and the balance
(contract sections, price on the day, fixed costs per month by calendar days;
shadow contracts do not count):

| Field | Meaning |
|---|---|
| `ct_per_kwh` | working price of the section (ct per kWh; not for water) |
| `energy_cost` | consumption cost: quantity × working price, split by days when the price changes inside the section; `null` if a price is missing |
| `fixed_cost` | fixed costs: base price, for district heating plus capacity and metering charges ([Contracts](#contracts-district-heating-fixed-costs-warnings-v310-additive)) |
| `fixed_days` | days over which the fixed costs run |
| `contract_id` | the section’s contract |
| `tw_m3`, `tw_cost`, `sw_m3`, `sw_cost`, `nw_cost` | water only: drinking water (working and base price), wastewater and rainwater. The wastewater volume follows the contract’s basis: drinking water, a meter of its own (`separater_zaehler`) or drinking water minus the deduction meters (`trinkwasser_minus_abzug`) — their volume in the same section, day-exact *(since v3.6.0; up to v3.5 only with the drinking-water basis, otherwise `null`)*. `energy_cost` = drinking water + wastewater per m³, `fixed_cost` = base price + rainwater |

`totals` additively: `energy_cost`, `fixed_cost`, `bonus` (bonuses credited in
the period), `total` (= `energy_cost + fixed_cost − bonus`) and `price_missing`
(`true` if a price is missing for part of it). Plus `utility` at the top level.

```json
{ "from": "2025-01-01", "to": "2026-01-01", "utility": "strom",
  "rows": [
    { "from": "2025-01-01", "to": "2025-04-01", "to_inclusive": "2025-03-31",
      "days": 90, "reason": "start", "kwh": 812.4,
      "counter_from": 40211.0, "counter_from_kind": "reading",
      "counter_to": 41023.4, "counter_to_kind": "interpolated",
      "ct_per_kwh": 29.8, "energy_cost": 242.1, "fixed_days": 90,
      "fixed_cost": 37.5, "contract_id": "c_strom_003" },
    { "from": "2025-04-01", "to": "2026-01-01", "to_inclusive": "2025-12-31",
      "days": 275, "reason": "price", "kwh": 2190.6, "…": "…" } ],
  "totals": { "days": 365, "kwh": 3003.0, "gaps": 0,
              "energy_cost": 901.47, "fixed_cost": 150.0, "bonus": 0.0,
              "total": 1051.47, "price_missing": false },
  "warnings": [] }
```

The supplier bill’s own figures are kept in the pot `<utility>/bills.json`;
`GET …/bills/{id}/check` provides the comparison
([Supplier bills](#supplier-bills-v310)).

### Billing periods *(v3.6.0)*

`GET /api/utility/{u}/meters/{id}/billing-periods` (also
`…/meter-groups/{id}/…` and `/api/utility/{u}/billing-periods` for the whole
utility) — F1030, GitHub #33. Only utilities with bill check, otherwise `400`
`errors.billCheck.unsupportedUtility`. The single source for the utility page,
the overview and the analysis in billing years, the appendix “Billing years”
and `monthly.csv?period=billing` (`BillingPeriodService`).

Periods, ascending: first the recorded **supplier bills** (`period_from` to
`period_to`, also shorter or longer than a year; if a bill overlaps an earlier
one, the earlier one wins). Before, between and after them the **settlement
date** of the utility (`billing_cycle_anchor_<utility>`, 29 Feb acts as
28 Feb): a gap is split at the settlement dates, the current period starts the
day after the last bill and ends the day before the next settlement date. The
first period starts with the first reading or an effective analysis break;
there is none before that. *(v3.6.0, addendum)* A **change of supplier**
without a recorded bill splits a settlement-date period: it ends on the last
day with the old supplier, and the next one starts with the follow-up
contract. A change means: a follow-up contract of another supplier (provider,
otherwise tariff name, ignoring case) or of the same one only after a gap,
when the old one does not renew (`auto_renews: false`) — the same rule by
which “Bill expected” expects a final bill. A gap without a contract is a
partial period of its own; a contract that renews runs until the day before
the follow-up contract. With several meters (whole utility, group) the changes
of all meters cut, like their bills. Each period (and `…/bills/{id}/check`)
counts the advance payment of a month with two contracts per contract by its
days. *(v3.7.0)* The settlement date is the **first day** of a period. **No
short remainders:** a piece under 14 days between the settlement date and a
change of supplier (settlement date 31-08, contracts from 1 September) joins
the neighbouring period across the settlement date, never across a change or a
bill; if today lies in the remainder, the current period runs to the
settlement date after next. The totals stay the same.

| Field | Meaning |
|---|---|
| `anchor` | settlement date of the utility (MM-DD) |
| `suggested_anchor` | the day after the end of the last bill (MM-DD) if it differs from the settlement date; without a bill *(v3.7.0)* the start of the follow-up contract at the latest change of supplier, otherwise the day on which at least two contracts begin; otherwise `null` |
| `suggested_anchor_source` | *(v3.7.0, additive)* `bill`, `contracts` or `null` — where the suggestion comes from |
| `data_from`, `measured_until` | first and last reading |
| `unit_key` | `kwh` or `m3` (water) |
| `periods[].from`, `until` | period, `until` inclusive |
| `periods[].source`, `bill_id` | `bill` (with the bill’s ID) or `anchor` |
| `periods[].complete`, `running` | whole, closed period with data from its start, or still running; a period up to a change of supplier or from a follow-up contract counts as whole |
| `periods[].ends_at_switch` | *(v3.6.0, additive)* `true` if the period ends on the last day with the old supplier (change of supplier) |
| `periods[].label` | “2025” (calendar year) or “2025/26” for a whole year, otherwise `null` (the app then shows the dates) |
| `periods[].as_of`, `days` | calculated up to the last reading in the period; days up to then |
| `periods[].kwh` or `m3` (gas both), `energy_cost`, `fixed_cost`, `bonus`, `cost` | as `bill-check` (`cost` = `total`, net of the bonus) |
| `periods[].bonus_items` | *(v3.7.0, additive)* the period’s bonuses one by one (`credit_date`, `amount_eur`, `type`, `label`; like `totals.bonus_items` of `bill-check`) |
| `periods[].advances` | advance payments pro rata per day from the monthly series (as `…/bills/{id}/check`) |
| `periods[].special` | special payments dated within the period: `extra_advances`, `refunds`, `surcharges` |
| `periods[].balance` | `cost − advances − extra_advances`; positive = additional payment. Refunds and additional payments settle an earlier bill and do not count |
| `periods[].invoice` | only for `bill`: `amount_eur`, `advances_paid_eur`, `result_eur`, `energy_kwh` or `volume_m3` of the bill |
| `periods[].months[]` | only with `?months=1`: calendar months, the first and last as partial months (`ym`, `from`, `until`, `days`, quantity, `cost`, `kwh_cost`, `base_price_eur`, `bonus_eur`, `advance_eur`, `co2_kg`) |

Sub-meters do not count for the whole utility (they are inside the parent
meter); a group is the sum of its members; fixed costs and advance payments of
a group contract are carried by the first member.

`monthly.csv?period=billing` (also with `format=local`): columns from, to,
basis, days, consumption, cost, advance payments, extra advance payments,
balance, complete; *(v3.7.0, added at the end)* bonus and cost without bonus
(the number of the “Costs” tile and of the annual report) — “cost” stays net
of the bonus; file name `…-abrechnungsjahre-…` (format 1) or
`…-billing-years-…` (local, English). The monthly overview in the “local”
format has *(v3.7.0)* the month’s bonus as its last column; format 1 stays
byte for byte as it is. A value of `period` other than
`calendar` or `billing` is `400` `errors.export.periodInvalid`.

### Supplier bills *(v3.1.0)*

The supplier’s bill with its own figures — basis for comparing with your own
calculation, for booking the result and for the CO₂ details (package H5,
B3/UI-35). For the same utilities as the bill check, otherwise `400`
`errors.billCheck.unsupportedUtility`. Stability class B. Step by step:
[Annual bill](../anleitungen/jahresabrechnung.md#7-on-the-bill-enter-compare-book).

Pot `<utility>/bills.json` (schema 1.7.0, part of the backup), one entry
(example values):

```json
{ "id": "b_3f9c0a1d2e4b", "meter_id": "m_gas_main", "contract_id": null,
  "kind": "annual", "period_from": "2025-01-01", "period_to": "2025-12-31",
  "issued_on": "2026-02-10",
  "invoice": { "energy_kwh": 16437, "amount_eur": 1667.5, "advances_paid_eur": 1800,
               "result_eur": -132.5 },
  "items": [ { "label": "Metering", "amount_eur": 12.5, "kind": "other" } ],
  "co2": { "emissions_kg": 2981.5, "cost_eur": 163.98 },
  "attachment_ids": [ "att_0b1c2d3e4f5a6b7c" ],
  "special_payment_id": null, "note": "",
  "created_at": "2026-02-14T18:20:05+01:00" }
```

| Field | Meaning |
|---|---|
| `meter_id` | meter; without it, the utility’s default meter. Unknown → `404` `errors.common.meterNotFound` |
| `contract_id` | optional: the contract `book` books into; without it, the contract that applied at the end of the period |
| `kind` | `annual` (annual bill, default), `final` (final bill), `interim` (interim bill) |
| `period_from`, `period_to` | billed period, **both inclusive**; not a date or start after end → `400` `errors.bill.periodInvalid` |
| `issued_on` | bill date (optional) — date of the booking and start of the CO₂ deadline (the day the supplier billed) |
| `invoice` | `energy_kwh` (for water `volume_m3`), `amount_eur` (bill amount incl. VAT), `advances_paid_eur`, `result_eur` (**positive = additional payment**, negative = credit; without it `amount_eur − advances_paid_eur`), `net` (optional, only stored). Every value optional; not a number or out of range → `400` `errors.bill.amountInvalid` |
| `items` | other items `{label, amount_eur, kind}` with `kind` ∈ `levy`, `fee`, `credit`, `other` (default); description at most 120 characters. The app does not recalculate them |
| `co2` | optional, meaningful for gas: `{emissions_kg, cost_eur?, stated_factor?}` — emissions and CO₂ amount as on the bill, the amount **including VAT**, as the bill states it (CO2KostAufG § 3(3); read as net up to v3.1), stated factor in kg/kWh. Take precedence over the standard factor for the [CO₂ price](#co₂-price-and-sharing-v310) |
| `factors` | *(v3.7.0, optional, gas)* correction factor and calorific value as on the bill per section `[{from, to?, zustandszahl, brennwert}]` (correction factor 0.5–1.5, calorific value 5–20 kWh/m³), e.g. from the inbox; the comparison holds them against the [gas conversion](einstellungen.md). Without them the field is missing |
| `attachment_ids` | receipts (`kind: bill_pdf`, otherwise `other`); a receipt removed from the list is released, the receipt’s `ref.type` is `bill` |
| `special_payment_id` | set once the result is booked |
| `note`, `created_at` | note (at most 2000 characters), time of creation |

`GET …/bills` returns the bills by `period_to` descending, `?meter_id=` only
those of one meter. `POST` answers `201` with the bill, `PATCH` changes only
the fields sent, `DELETE` answers `{deleted: true}`. Unknown ID → `404`
`errors.bill.notFound`. A meter with bills cannot be deleted
(`errors.meter.hasBills`).

**Comparison — `GET …/bills/{id}/check`.** Recalculates the bill’s period with
the bill check (`period_to` inclusive) and sets both side by side:

```json
{ "bill_id": "b_3f9c0a1d2e4b", "utility": "gas", "meter_id": "m_gas_main", "group_id": null,
  "period_from": "2025-01-01", "period_to": "2025-12-31",
  "ours": { "kwh": 16437.2, "energy_cost": 1512.22, "fixed_cost": 142.8, "bonus": 0.0,
            "items_total": 12.5, "total": 1667.52, "advances": 1800.0 },
  "invoice": { "energy_kwh": 16437, "amount_eur": 1667.5, "advances_paid_eur": 1800, "result_eur": -132.5 },
  "delta": { "kwh": -0.2, "kwh_pct": 0.0, "eur": -0.02, "eur_pct": 0.0 },
  "verdict": "ok",
  "reasons": [ "factor_change_inside", "items_not_modelled" ],
  "rows": [ "… as bill-check …" ] }
```

| Field | Meaning |
|---|---|
| `group_id` | ID of the meter group when a group contract touches the period: then the app recalculates the whole group (peak and off-peak on one bill), even if the bill is recorded on one member; otherwise `null` |
| `ours` | recalculated: quantity (`kwh` or `m3`), `energy_cost`, `fixed_cost`, `bonus`, `items_total` (sum of the other items), `total` = consumption cost + fixed costs − bonus + other items, `advances` (advances in the period per payment plan, pro rata by days; `null` without advances) |
| `delta` | bill − recalculated: quantity absolute and in % (`kwh_pct` or `m3_pct`), amount in € and % |
| `verdict` | `ok` if the quantity differs by at most 1 % and the amount by at most 1 % **or** 2 €; *(v3.7.0)* `no_prices` if the quantity matches (or is missing) but the contract lacks a price (or there is no contract) for part of the period, so the amount cannot be recalculated; otherwise `check` (a differing quantity stays `check`). `null` if the bill states neither quantity nor amount |
| `reasons` | possible reasons: `estimated_reading_at_boundary` (estimated or interpolated reading at an edge of the period; *since v3.7.0* only there, and a reading on the last day or on the day before the first counts as a boundary reading, as on the bill), `price_change_inside`, `factor_change_inside` (gas: the gas conversion changes within the period, the bill does not state its values), *(v3.7.0)* `factor_mismatch` (calorific value or correction factor on the bill differ from the gas conversion), `items_not_modelled` (other items taken over, not recalculated), `price_missing`, `gaps` (sections without readings; not the single day after a boundary reading on the last day) |
| `boundaries` | *(v3.7.0)* kind of reading at the edges `{start, end}`: `reading` (reading on the first day or on the day after the last), `reading_adjacent` (on the day before or on the last day), `reading_estimated`, `interpolated` or `null` (no reading before or after); for a group the weakest member |
| `price_missing_at` | *(v3.7.0)* where the price is missing: `[{from, to, contract_id, provider?, meter_id?}]`, `to` inclusive, `contract_id` `null` without a contract — with every verdict, so also with `check` when the quantity differs (the page then shows the same notice with the jump to the contracts) |
| `factor_mismatches` | *(v3.7.0, gas)* per day with differing values `{from, invoice: {zustandszahl, brennwert, kwh_per_m3}, list: {…}}`; empty when the gas conversion knows the change as the bill does |
| `rows` | the sections of the bill check — the classification above does not change them |

**Book — `POST …/bills/{id}/book`.** Adds the result (`invoice.result_eur`) as
a special payment (F1003) in the contract: positive as `nachzahlung_ohne`,
negative as `rueckzahlung_ohne` — **not affecting the advance** —, amount
without sign, date = `issued_on`, otherwise the day after `period_to`, note
“Annual bill {from} – {to}” in the language of the request. Contract:
`contract_id`, otherwise *(since v3.7.0)* the contract that applies on
`period_to` (a renewing one too) if it bills more than half of the days;
otherwise the one that bills more than half (e.g. the old contract of a final
bill that runs one day past its end); otherwise the one on `period_to` — in
each case including a group contract of the group the meter belongs to
(without shadow contracts). Up to v3.6 the app took the meter’s newest contract
when none applied on `period_to`, even if it did not touch the period at all.
Response: the bill with
`special_payment_id` and `contract_id`. **Once per bill** — if it is already
booked, another call changes nothing and returns the bill unchanged. Without a
result (missing or 0) → `400` `errors.bill.noResult`, without a contract →
`400` `errors.bill.noContract` (the message names the period). Deleting the bill keeps the booked special
payment in the contract.

*(v3.5.0, [#34](https://github.com/Bingerminger/energietracker/issues/34))*
For the water contract too: up to v3.4 the call answered there with
`special_payment_id`, but the contract silently dropped the special payment.
Such a “booked” bill is now returned with `special_payment_id: null` as long as
its contract has no `special_payments` field at all — it can then be booked. If
a contract does not take the special payment, `book` answers `500` instead of
“booked”.

### CO₂ price and sharing *(v3.1.0)*

Package H4. Both routes calculate only in countries with a CO₂ scheme
(`Countries::co2Scheme`, today only Germany: `behg`) and otherwise answer with
`supported: false` and a note in `note`. `?year=` is the year (2021–2100,
default: previous year); anything else → `400` `errors.co2.yearInvalid`.
Stability class B. Background: [CO₂ price in fuel](../verstehen/16-co2-preis.md),
[Share CO₂ costs with the landlord](../anleitungen/co2-aufteilung.md).

**`GET /api/co2-costs?year=2025`** — the CO₂ price contained in the unit price
of gas, heating oil and district heating (CALC-27). **Shown, not added**:

```json
{ "supported": true, "scheme": "behg", "year": 2025,
  "rows": [
    { "utility": "gas", "kwh": 10000.0, "factor_kg_per_kwh": 0.18139,
      "emissions_kg": 1813.9, "price_eur_t": 55.0,
      "cost_eur_net": 99.76, "cost_eur_gross": 118.72, "ct_per_kwh": 1.187,
      "source": "computed", "vat": 0.19, "approx": false, "coverage_days": 365 } ],
  "total": { "emissions_kg": 1813.9, "cost_eur_net": 99.76, "cost_eur_gross": 118.72 },
  "per_m2": { "kg": 18.1, "area_m2": 100.0, "stage": 3, "landlord_share_pct": 20 },
  "price": { "eur_t": 55.0, "assumed": false },
  "vat": 0.19, "note": null }
```

| Field | Meaning |
|---|---|
| `rows[]` | one row per utility (`gas`, `heizoel`, `fernwaerme`, `waerme`) if there are emissions and a price. Meters that count in the totals are included (no sub-meters; for heat only the role `consumption`) |
| `kwh` | consumption of the calendar year |
| `factor_kg_per_kwh` | BEHG standard factor — gas 0.18139 (per kWh gross calorific value), heating oil 0.2664 (per kWh net calorific value) —, for district heating the contract’s `co2_g_per_kwh` / 1000, for heat the factor of the energy source (`waerme_energietraeger`, gas or heating oil only); with figures from the bill emissions ÷ consumption |
| `source` | `bill` (supplier bills whose period ends in the year and carry `co2.emissions_kg` — summed), `contract` (district heating with network factor) or `computed` (consumption × standard factor). In this order; district heating without a network factor, pellets and electricity never appear |
| `price_eur_t` | applicable price of the year: setting `co2_price_eur_t_years`, otherwise the country profile (DE 2021: 25, 2022: 30, 2023: 30, 2024: 45, 2025: 55, 2026: 60), otherwise the last known value (`price.assumed: true`). For 2027, CO2KostAufG § 4(1) no. 3 sets the average of the auctions from 1 July to 30 November 2026; until it is entered, the API uses the last known value (60) as an assumption |
| `cost_eur_net` | emissions / 1000 × price; with bills their `co2.cost_eur` ÷ (1 + `vat`) if every one of them carries it — the bill amount includes VAT (CO2KostAufG § 3(3)) |
| `cost_eur_gross` | net × (1 + `vat`); with bills their `co2.cost_eur` (since v3.2.0; up to v3.1 net × (1 + `vat`), i.e. the tax twice) |
| `ct_per_kwh` | gross per kWh in ct |
| `vat` | *(v3.2.0)* VAT rate applied to the row: per month 19 %, for `gas` and `fernwaerme` (and heat from gas or district heating) 7 % from 10/2022 to 3/2024 (§ 28(5) and (6) UStG), weighted by consumption for the year, without consumption the average of the twelve months. Gas 2023: `0.07`; heating oil always `0.19`; heat by its energy source (`waerme_energietraeger`) |
| `approx` | `true` for heat with a calculated value — the heat is counted, not the fuel |
| `coverage_days` | days of the year with consumption data |
| `total` | totals of all rows, `null` without rows |
| `per_m2` | emissions per m² of floor area (one decimal place) with `stage` (1–10) and `landlord_share_pct` per the annex to the CO2KostAufG; area from the year’s tenancy (with `wohnverhaeltnis: miete`), otherwise `wohnflaeche_m2`. `null` without rows or area. *(v3.6.0)* `area_assumed`: the area is only the default (entered nowhere) |
| `price` | `{eur_t, assumed}` |
| `vat` | standard VAT rate on the CO₂ price (`0.19`); the rate applied per row is in `rows[].vat` |

Stages (kg CO₂ per m² and year, upper limit exclusive → landlord’s share):
< 12 → 0 %, < 17 → 10 %, < 22 → 20 %, < 27 → 30 %, < 32 → 40 %, < 37 → 50 %,
< 42 → 60 %, < 47 → 70 %, < 52 → 80 %, above 95 %. For a period shorter than a
year the limits are shortened pro rata.

**`GET /api/co2-split?year=2025`** — the landlord’s share of the CO₂ costs
(MKT-15). Only with `wohnverhaeltnis: miete` and a CO₂ scheme, otherwise
`{supported: false, year, note}`. An estimate, not legal advice. The app
determines the case:

- **`central`** (central heating) — a service charge statement of the tenancy
  whose period ends in the year carries `co2` ([Tenancy](#tenancy-v310)). The
  app recalculates stage, share and amount (`co2.cost_eur` × share) from
  emissions and area and compares them with the statement’s figures.
- **`self_supplied`** (self-contained heating) — otherwise, from the `gas` and
  `heizoel` rows of `/api/co2-costs`: costs **gross**, reductions from the
  tenancy (`co2_own_appliances` × 0.95; `co2_restriction` `one` × 0.5, `both`
  → 0).

```json
{ "supported": true, "year": 2025, "tenancy_id": "t_1a2b3c4d5e6f", "area_m2": 80.0,
  "case": "self_supplied", "source": "computed",
  "emissions_kg": 2176.7, "kg_per_m2": 27.2, "stage": 5, "landlord_share_pct": 40,
  "co2_cost_eur": 142.46, "landlord_amount_eur": 56.98,
  "checks": [], "reductions": [], "deadline": "2027-02-10",
  "price": { "eur_t": 55.0, "assumed": false }, "utilities": [ "gas" ] }
```

| Field | Meaning |
|---|---|
| `case` | `self_supplied`, `central` or `null` (no gas or heating-oil data and no CO₂ details; then `note`) |
| `tenancy_id`, `area_m2` | the year’s tenancy (the most recent) and its floor area (`wohnflaeche_m2`, otherwise the setting; `null` without) |
| `area_assumed` | *(v3.6.0)* `true` when the tenancy’s area is empty and the living area was never entered — the calculation still uses the default (100 m²), the interface asks you to enter it ([Assumed values](#assumed-values-assumed_keys-v360)) |
| `emissions_kg`, `kg_per_m2`, `stage`, `landlord_share_pct` | emissions, per m² (one decimal place), stage and landlord’s share |
| `co2_cost_eur`, `landlord_amount_eur` | the year’s CO₂ costs and the landlord’s amount |
| `source` | `self_supplied`: the sources of the rows from `/api/co2-costs`, joined with a comma (for instance `bill` or `bill,computed`); `central`: `statement` |
| `checks[]` | `central`: `stage`, `share`, `amount` (difference above 0.50 €), `missing_values` (emissions or area missing); `self_supplied`: `area_missing` (no stage without an area) |
| `reductions[]` | `own_appliances`, `restriction_one`, `restriction_both` |
| `deadline` | `self_supplied` only: deadline for the refund (§ 6(2): twelve months from the date the supplier billed) = bill date of the gas bill whose period ends in the year (`issued_on`, otherwise the day after the period) + 12 months; `null` without a recorded gas bill |
| `statement_id`, `days`, `stated` | `central` only: the statement, the days of its period and its figures `{stage, landlord_share_pct, landlord_amount_eur}` |
| `price`, `utilities` | `self_supplied` only: price as above and the utilities included (`gas`, `heizoel`) |

**`GET /api/reports/co2-split.pdf?year=2025`** — the result as a PDF: for
`self_supplied` a letter “Refund of the landlord’s share of CO₂ costs
(CO2KostAufG)”, for `central` the check result with the differences, always
with the note “An estimate … not legal advice”. File
`energietracker-co2-<year>.pdf`, `?inline=1` shows it in the browser instead of
downloading it. Language as for the PDF annual report (without `X-ET-Language`
the default language); a language without a PDF character set → `422`
`errors.report.pdfUnsupportedLanguage`.

**Settings:** `co2_price_eur_t_years`, `co2_price_scenario_eur_t`,
`co2_price_scenario_from` ([Settings](einstellungen.md#co₂-price-v310)); the
scenario is part of the [forecast](#get-apiutilityumetersidforecast-extended-in-v280).

### `GET /api/utility/{u}/meters/{id}/stock-history` *(heating oil/pellets only)*

```json
{ "success": true, "data": {
  "capacity": 3000, "capacity_unit": "L", "initial_stock": 2400,
  "days": [ { "date": "2023-01-01", "stock": 2389.4,
              "delivery": 0, "consumption": 10.6, "estimated": false }, … ],
  "anchors": [ { "date": "2023-01-01", "kind": "start", "stock": 2400 },
               { "date": "2025-09-17", "kind": "level", "stock": 1650 } ],
  "estimated_from": "2025-09-17",
  "calibration": "anchors",
  "warnings": []
}}
```

**Since v2.10.0 (tank log)** stock and consumption come from **one**
calculation — the same one the monthly rows, the costs and the efficiency figure
come from. `stock` is the stock at the end of the day. `anchors` lists the known
stock levels (`start` = initial stock, `full` = delivery "filled to full",
`level` = tank reading); between them the consumption is calculated, from
`estimated_from` onwards it is estimated (`estimated` per day). `calibration`
names the origin of the rate: `anchors`, `deliveries` (delivery cadence),
`first_delivery` or `none`. `warnings`: `inconsistent_level` (`from`, `to`,
`excess` — the levels do not add up, nothing is booked in between),
`stock_exhausted` (`date` — empty by calculation, checked on estimated days
only), `no_calibration` (no rate: fewer than 14 days between known levels, no
two deliveries and no single delivery after an initial stock above 0),
`flat_no_temperatures`. Up to v2.9 the curve was a
second model alongside the cost calculation. Details:
[Heating oil](../verstehen/05-heizoel.md).

**Monthly rows** of heating oil and pellets (`…/consumption`) carry, since
v2.10.0, `estimated_days` (days after the last anchor) and `working_price_ct`
(effective price per kWh from the moving average price of the tank content; up
to v2.9 `null`). `cost` is calculated at the average price of the tank content,
the initial stock at `initial_stock_price_ct` or at the price of the first
delivery (up to v2.9: €0).

### Tank: `tank_levels`, `initial_stock_price_ct`; delivery: `fill_to_full` *(v2.10.0, additive)*

- `PATCH …/meters/{id}` (heating oil/pellets only) with `tank_levels`: the
  **whole** list `[{date, level, note}]`. Strict: a real date, not in the future,
  one level per day, 0 ≤ `level` ≤ capacity (+2 %), decimal comma allowed.
  Errors → 400 `errors.meter.invalidTankLevels`, `…invalidTankLevelDate`,
  `…tankLevelFuture`, `…duplicateTankLevel`, `…tankLevelInvalid`,
  `…tankLevelAboveCapacity`.
- `initial_stock_price_ct` (ct per L or kg) on creation or via `PATCH`;
  empty/`null` removes it (the price of the first delivery then applies).
  Invalid → 400 `errors.meter.initialPriceInvalid`.
- `POST|PATCH …/deliveries` with `fill_to_full: true` (also `"true"`, `1`;
  `"false"` is false): after the delivery the tank is full. A quantity above the
  capacity (+2 %) → 400 `errors.delivery.fullAboveCapacity`.
- Electricity: `heat_source: true` on the meter marks a heat pump — it then
  counts in the efficiency figure. Since v3.1.0 equivalent to the role
  `heat_pump` ([meter roles](#meters-role-capture-v310-additive)).

All fields travel in the backup (whole records); the CSV export of the
deliveries stays unchanged.

### `GET /api/benchmarks/efficiency?year=YYYY`

Since **v1.4.0** per heat source:

```json
{ "success": true, "data": {
  "year": 2024, "wohnflaeche_m2": 100,
  "per_source": [
    { "utility": "gas", "label": "Gas", "kwh": 10685.8,
      "kwh_per_m2": 106.9, "class": "D" }
  ],
  "primary":  { "utility": "gas", "label": "Gas", "kwh": 10685.8,
                "kwh_per_m2": 106.9, "class": "D" },
  "combined": { "kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D" },
  "certificate": { "area_m2": 120, "area_factor": 1.2, "kwh": 9681,
                   "kwh_per_m2": 80.7, "dhw_surcharge": 0,
                   "weather_adjusted": true, "complete": true,
                   "class": "C", "months_36": 36,
                   "rules": "geg2024", "fuel_basis": "hi", "fuel_basis_approx": [],
                   "dhw_rule": 20, "months_required": 36, "months_in_window": 36,
                   "upcoming": { "from": "2027-01-01", "rules": "gmodg2027",
                                 "kwh": 10685, "kwh_per_m2": 89, "dhw_surcharge": 0, "class": "C",
                                 "fuel_basis": "hs", "fuel_basis_approx": [], "dhw_rule": 16,
                                 "months_required": 24, "months_in_window": 24 } },
  "thresholds": { "A+": 30, "A": 50, "…": 0 },
  "scale": "geg", "scale_note": null,
  "note": null,
  "total_kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D",
  "breakdown": { "gas": 10685.8 }
}}
```

`per_source` lists each heating-energy utility (gas, district heating, heating oil,
pellets, since v3.1.0 heat) **individually** — a house really mostly heats with one source; summing
several would yield a nonsensical class. `primary` = the highest-consuming source,
`combined` = the sum (only meaningful with deliberately combined heating operation,
`note` points to it). The top-level fields are backward-compatible aliases and have
reflected the **primary** source since v1.4.0.

*(v3.6.0)* `area_assumed`: `true` when `wohnflaeche_m2` is only the default
(never entered). Metric and class keep calculating with it; the overview and
the annual report say “assumed living area” and ask you to enter it.

*(v2.7.0)* `scale` names the efficiency scale of the configured country —
today only `geg` (Germany). For other countries `scale` is `null`, every
`class` field is `null`, and `scale_note` explains why; the figure
`kwh_per_m2` stays. A class under German law would mislead in France (DPE)
or Austria (HWB).

*(v2.10.0)* Each source carries `coverage_days` and `complete` (≥ 360 days);
**no class without a full year** (`class: null`, `note` explains why). Limits
are inclusive ("up to 100" = C). Electricity meters with `heat_source: true`
(since v3.1.0: role `heat_pump`) appear as source `strom`. *(v3.1.0)* Heat
(`waerme`) only counts with meters of the role `consumption`; the role
`heat_pump_output` (a heat pump's heat output) stays out, otherwise the same
heat would be counted twice next to the heating electricity. `certificate` is the certificate-style figure:
weather-adjusted (`weather_adjusted`), relative to `area_m2` = living area ×
`area_factor` (1.2; 1.35 for `gebaeudetyp` efh/rh with `beheizter_keller`),
plus `dhw_surcharge` with `warmwasser_dezentral`. `months_36` counts the months
with heating data in the three years up to the reference year (meaning
unchanged).

*(v3.5.0, additive)* The figure follows the consumption-certificate procedure
in force **on the day of calculation** (§ 82 GModG; "as if the certificate
were issued today"), for every reference year:

| Field | up to 31 Dec 2026 | from 1 Jan 2027 |
|---|---|---|
| `rules` | `geg2024` | `gmodg2027` |
| `fuel_basis` | `hi` — net calorific value, gas × 0.906 | `hs` — gross calorific value, gas × 1 |
| `dhw_rule` | 20 (surcharge per m² with decentralised hot water) | 16 |
| `months_required` | 36 | 24 (two years) |

`months_in_window` counts the months with heating data in the rule's period up
to the reference year. `fuel_basis_approx` names utilities that are not
converted to `fuel_basis` (from 2027 `heizoel` and `pellets`: the app counts
them at net calorific value, and there is no freely available factor to the
gross calorific value). `upcoming` is present until the next rule takes effect
(`from`, the same fields under the new rule), then `null`. Class limits and
`thresholds` are the same under both rules (Annex 10).
Formulas: [Fundamentals §7](../verstehen/00-overview.md#7-efficiency-class).

### Benchmark: `GET /api/benchmarks/comparison` *(v3.1.0)*

Places the annual consumption against **your own reference values** (class B,
`?year=`, default previous year). The app deliberately ships no tables from the
German electricity or heating benchmarks (Strom-/Heizspiegel) — using them
requires written permission (§ 87b UrhG); the household enters the values
itself ([Settings](einstellungen.md#own-reference-values-v310)).

```json
{ "year": 2025, "supported": true, "source": "Stromspiegel 2025",
  "strom": { "kwh": 2950, "complete": true, "own_value": 2800, "delta_pct": 5.4,
             "rating": "near",
             "persons": 2, "building": "house", "dhw_electric": false,
             "special_household": true },
  "heating": [ { "utility": "gas", "kwh_per_m2": 118.4, "weather_adjusted": true,
                 "complete": true, "own_value": 130, "delta_pct": -8.9, "rating": "good" } ],
  "water": { "m3": 88.0, "complete": true, "persons": 2, "reference_l": 122,
             "liters_per_person_day": 121, "index": 99, "delta_pct": -1.0, "rating": "good" },
  "missing": [],
  "area_m2": 120,
  "links": { "stromspiegel": "https://www.stromspiegel.de/",
             "heizspiegel": "https://www.heizspiegel.de/" } }
```

| Field | Meaning |
|---|---|
| `strom` | household electricity: all electricity meters in sums **without** the roles `heat_pump` and `ev_charger`; `null` without values in the year |
| `strom.special_household` | `true` with PV, a heat pump or a wall box — then the general electricity benchmark does not fit |
| `strom.persons`, `building`, `dhw_electric` | the characteristics the electricity benchmark distinguishes by: persons in the household, `house` (detached, semi-detached or terraced) or `flat`, hot water by electricity (`warmwasser_elektrisch`) |
| `heating[]` | per heating type (gas, heating oil, pellets, district heating, heat) kWh per m² living area, weather-adjusted where the heating model exists (`weather_adjusted`) |
| `complete` | all twelve months with values; only then is there a `delta_pct` |
| `own_value`, `delta_pct` | your reference value (`reference_strom_kwh` or `reference_heat_kwh_m2`) and the deviation in % |
| `rating` | *(v3.6.0)* traffic light: `good` at or below the reference value, `near` up to 10 % above, `high` more; `null` without `delta_pct` |
| `water` | *(v3.6.0)* water of the main meters in the year against the benchmark: `liters_per_person_day` = m³ × 1000 / days / `persons` (`wasser_personen_anzahl`), `index` = litres / `reference_l` (`wasser_personen_referenz`) × 100, `delta_pct` = `index` − 100; `rating` by the bands of the water saving index (`good` up to `wasser_sparindex_gut`, `high` from `wasser_sparindex_warnung`), only in a full year; `null` without values in the year or without the utility water active |
| `missing` | *(v3.6.0)* settings that make a benchmark possible in the first place, in the order the overview invites you: `reference_strom_kwh`, `wohnflaeche_m2` (before `reference_heat_kwh_m2`), `wasser_personen_anzahl` (before `wasser_personen_referenz`). Living area and people are missing until someone has entered them — the default (100 m², 2 people) does not count; then there is no `heating[]` line, `water.persons` is `0` and `area_m2` is `null` ([Assumed values](#assumed-values-assumed_keys-v360)) |
| `source` | `reference_source`, otherwise `null` |
| `links` | the pages for checking yourself — only for the country Germany, otherwise empty; the overview no longer shows them since v3.6.0 |

### Seasonal performance factor of the heat pump *(v3.1.0)*

**Link on the meter.** A heat meter with the role `heat_pump_output` (heat
pump output) names the heat pump’s electricity meters in `heat_pump_meter_ids`
— electricity meters with the role `heat_pump`, otherwise `400`
`errors.meter.heatPumpLinkInvalid`. An empty list removes the field.

**`GET /api/heat-pump?year=`** (class B, default previous year, year 1990–2100,
otherwise `400` `errors.heatPump.yearInvalid`):

```json
{ "year": 2025,
  "pumps": [ { "heat_meter_id": "m_wp_waerme", "name": "Heat pump output",
    "elec_meter_ids": ["m_wp_strom"], "linked": true,
    "months": [ { "ym": "2025-01", "month": 1, "heat_kwh": 1620.0, "elec_kwh": 520.0,
                  "cop": 3.12, "hdd": 512.3 }, "…" ],
    "months_covered": 12, "heat_kwh": 9000.0, "elec_kwh": 2500.0,
    "jaz": 3.6, "jaz_heating_season": 3.3,
    "by_hdd": [ { "ym": "2025-01", "hdd": 512.3, "cop": 3.12 }, "…" ] } ],
  "reference": { "air_water": 3.4, "ground_water": 4.3 } }
```

```text
performance factor (month) = heat / electricity      SPF (jaz) = Σ heat / Σ electricity
Example: 9,000 kWh heat / 2,500 kWh electricity = SPF 3.6
```

Only months in which **both** sides have values count (with several electricity
meters, all of them). `jaz_heating_season` covers October to April.
`reference` holds the averages of the Fraunhofer field test “WP-QS im Bestand”
(2025) for context. A performance factor is **not** weather-adjusted; `by_hdd`
shows how it varies with the heating degree days. Which energy counts (backup
heater, hot water, pumps) depends on where the meters sit; depending on this
balance boundary, performance factors of the same system differ by around 15 %.

The heat meter with `heat_pump_output` counts neither in the sums of heat nor
in the efficiency figure: the heat pump is already there with its electricity.

### `GET /api/export/{u}/deliveries.csv` *(v1.4.2, heating oil/pellets)*

CSV with one row per delivery (columns below). Heating oil and pellets only;
for cumulative utilities the route answers 400 (`errors.csv.notDeliveryBased`)
— use `readings.csv` there.

### CSV formats *(v3.1.0)*

The exports (`/api/export/{u}/monthly.csv`, `…/readings.csv`,
`…/deliveries.csv`, since v3.1.0 `…/periods.csv`, and
`/api/export/temperatures.csv`) return a file
(`text/csv`, `Content-Disposition: attachment`) in one of two formats:

| Parameter | Format | Meant for |
|---|---|---|
| `format=1` or none | **Format 1** — frozen, stability class A | scripts, own evaluations |
| `format=local`, optionally `lang=xx` | **Spreadsheet in one language** | Excel, LibreOffice, Numbers |

Any other value for `format` → 400 with `code` `errors.export.formatInvalid`.
The interface offers both under Settings → Data → Data export ("Spreadsheet in
the default language — recommended" and "CSV format 1 — stable, for scripts")
and remembers the choice in the browser.

#### Format 1

As it has been written since v1.1.0; since v3.1.0 `CsvFormatV1Test` pins it
byte for byte against the files under `tests/fixtures/csv-format-1/`. A change
to it would be a new format, no longer format 1.

- Field separator `;`, line ending CRLF, UTF-8 with BOM.
- Numbers with a decimal comma, without thousands separators, at most four
  decimals, trailing zeros removed (`1480,5`, `80`, `-0,1`). Empty cell = no
  value.
- Dates ISO `YYYY-MM-DD`, months `YYYY-MM`.
- Booleans `ja` and `nein`.
- A cell containing `;`, `"` or a line break is quoted, with `"` doubled.
- **Formula guard:** free text (meter name, note, supplier) that starts with
  `=`, `+`, `-`, `@`, tab or carriage return gets a leading `'` — spreadsheets
  then do not evaluate it as a formula. Numbers stay untouched, negative ones
  too.

The headers, verbatim:

| File | Header |
|---|---|
| `readings.csv` | `Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand;Preis (ct);Notiz;Geschaetzt;Zukunft` |
| `deliveries.csv` | `Tank/Lager-ID;Tank/Lager;Datum;Menge (L);Preis (ct/L);Gesamt (EUR);Lieferant;Notiz;Geplant` — for pellets `kg` instead of `L` |
| `temperatures.csv` | `Datum;oe Temp (C);Min (C);Max (C)` |
| `periods.csv` *(v3.1.0)* | `Von;Bis;Wert;Notiz;Zaehler-ID;Zaehler;Einheit;Geschaetzt;Quelle` |
| `monthly.csv` | the frozen keys `csv.*` in the language of the request (see below) |

`periods.csv` has one row per period across all meters of the utility: `Bis`
is the last day (inclusive), `Einheit` the unit of `Wert` (for gas `m³` with
`value_unit: meter`, otherwise the consumption unit), `Quelle` is `manual`,
`csv` or `import`.

The monthly overview carries its header in the language of the request —
without `X-ET-Language`, that is the default language of the installation.
English:

```text
Month;Days;Consumption (kWh);Cost (EUR);Advance payment (EUR);Monthly balance (EUR);Cumulative balance (EUR);Avg temp (°C);HDD;CO2 (kg)
```

German: `Monat;Tage;Verbrauch (kWh);Kosten (EUR);Abschlag (EUR);…`; the headers
of all seven languages are reference files in the test directory. The `csv.*`
keys are frozen: translations do not change them, and the typography of the
output leaves them out. The consumption unit is the utility's (`kWh` or `m³`);
the balance has the sign of the API (positive = additional payment).

In format 1, `EUR` and `ct` are fixed labels for the major and minor unit of
the configured currency — amounts are not converted. Only the monthly overview
fills in the currency code (`EUR`, `CHF` or `GBP`).

File names (date of the export):
`energietracker-<utility>-monatsuebersicht-YYYY-MM-DD.csv`,
`energietracker-<utility>-ablesungen-YYYY-MM-DD.csv`,
`energietracker-<utility>-lieferungen-YYYY-MM-DD.csv`,
`energietracker-<utility>-zeitraeume-YYYY-MM-DD.csv` (v3.1.0),
`energietracker-temperaturen-YYYY-MM-DD.csv`.

#### Format "local"

The spreadsheet in one language, so that Excel or LibreOffice open it correctly
with a double click. The language is `lang` (one of the seven); without `lang`,
or with an unknown one, the **default language of the installation** — not the
language of the device.

- Headers from the catalogue keys `csvLocal.*` of the language, with currency
  and minor unit (`Reading`, `Price (ct)`; French `Index`, `Prix (ct)`; with
  Swiss francs as currency `Price (Rp.)`). Periods (v3.1.0) from
  `csvLocal.periods.*`.
- Decimal separator of language and country (German in Switzerland `.`), no
  thousands separators, at most four decimals.
- Field separator `;` with a decimal comma, otherwise `,`.
- Dates in the pattern of the language: de `15.01.2026`, en, fr, it, es and pt
  `15/01/2026`, nl `15-01-2026` (country variants such as French in
  Switzerland `15.01.2026`). Months `YYYY-MM`.
- Yes and no in the language (`Yes`/`No`, `Oui`/`Non`).
- UTF-8 with BOM, CRLF, quoting and formula guard as in format 1.
- File name from `csvLocal.file.*`, e.g.
  `energietracker-strom-releves-2026-01-15.csv` (French). The utility key
  stays.

Column names and words come from the catalogues and may change with a better
translation. Scripts read format 1.

#### Import *(v3.1.0)*

Readings (`…/readings/import-csv`) and temperatures
(`/api/temperatures/import-csv`) read their exports (`readings.csv`,
`temperatures.csv`) back in — format 1 and "local" in every language
(`CsvLocalFormatTest`):

- **Dates** ISO `YYYY-MM-DD` or day before month with a four-digit year:
  `D.M.YYYY`, `D/M/YYYY`, `D-M-YYYY`, day and month with one or two digits.
  Checked against the calendar (`31.02.2026` is not a date).
- **Month before day** (US style) is never reinterpreted: if the second
  position holds a value above 12 (`01/15/2026`), the row is an error — the
  readings import reports it with its own message
  (`errors.import.dateMonthFirst`) in `errors`, the temperature import counts
  it under `skipped`.
- The **header** is the first row if it contains letters and does not start
  with a date or a number — "Date;Moyenne;Min;Max" is recognised, a data row
  is not swallowed.
- **Columns** of the readings are found by name: names from
  `csvLocal.readings.*` of every language, from format 1 and the earlier ones
  (`datum`, `zaehlerstand`, `counter`, `wert`, `notiz` …). Case, accents,
  umlauts and a unit in brackets do not matter. Without a header: date,
  reading, note, estimated.
- **"Estimated"** holds for `1`, `true`, `x`, `ja` and the yes of every
  language (`oui`, `sì`, `sí`, `sim` …).
- Messages in `errors` are in the language of the request.

**Periods** (`…/meters/{id}/periods/import-csv`, v3.1.0) read per row either
`month;value[;note]` with the month as `MM.YYYY`, `MM/YYYY` or `YYYY-MM` (whole
month) or `from;to;value[;note]` with dates as above; separator `;`, tab or
`,`, header optional. The import reads the app's own `periods.csv` export
(format 1 and "local") back in: if the header has a meter ID column, only the
rows of this meter count. Details:
[Consumption per period](#consumption-per-period-v310).

Since v3.1.0 the file behind "Download example CSV" in the interface is in the
interface language, with its date format and decimal separator.

### `POST /api/utility/{u}/deliveries`

Required: `meter_id`, `date`, `quantity` (> 0). Optional `unit_price_cents`
**or** `total_eur`, `supplier`, `note`, `is_planned`, `fill_to_full` (v2.10.0).
**Since v1.4.2** `total_eur` takes precedence over `unit_price_cents` — the
invoice amount is the figure actually paid (incl. the delivery fee/rebate); the
effective unit price is derived from it (`total_eur · 100 / quantity`).

### `GET /api/reports/yearly.pdf?year=YYYY`

Delivers **no JSON**, but directly a PDF (`Content-Type: application/pdf`). Since
**v1.4.2** without the former axis-less mini chart — instead a figures bar (annual
consumption, avg/month, total costs, strongest/weakest month) plus the monthly
table. Generated by the built-in, dependency-free PDF writer.

Since **v2.11.0** with `inline=1`: `Content-Disposition: inline` instead of
`attachment` — the browser shows the PDF instead of downloading it. The interface
opens it that way in a new tab ("Open in browser"); in the home-screen app on the
iPhone the download often never arrived. Without the option nothing changes.

Since **v3.1.0** the writer maps characters instead of dropping them ("CO₂" →
"CO2", the middle dot in "kWh/m²·a" stays) and measures widths in characters.
The built-in fonts only know CP1252; a language with `format.pdfCharset = none`
gets `422` with `code` `errors.report.pdfUnsupportedLanguage` and a pointer to
the print view. Today all seven languages are `cp1252`. The PDF is in the
language of the request; loaded from the interface (without `X-ET-Language`)
that is the default language of the installation — the print view, in
contrast, uses the language of the device.

Since **v3.4.0** (F1025, “Annual report for everyone”) the PDF is laid out anew
for readers without a technical background: at a glance with four status lights
and the three most important tasks, what paid off, what needs doing (priority,
amount, deadline), deadlines and appointments, money, one page per utility,
topic pages only with data (solar power, heat pump, electric car, cheap hours,
rented home), a short glossary — with charts as vector graphics, a footer
“Page x of y”, bookmarks, `/Lang` and document title. The monthly tables are now
only in the appendix “Figures in detail”. Reading guide:
[Reading the annual report](../anleitungen/jahresbericht-lesen.md). Additional
parameters (for both routes):

| Parameter | Meaning |
|---|---|
| `lang` | language of the report (`de`, `en`, `fr`, `it`, `es`, `pt`, `nl`), as for `/api/manifest`; an unknown one silently falls back to the language of the request. The interface appends the device language to the PDF link |
| `utilities` | partial report: comma-separated utilities (`gas,wasser`); an unknown one → `400` with `code` `errors.common.unknownUtility`. The file name gets the utilities appended (`energietracker-2025-gas-wasser.pdf`) |
| `detail` | `1` = with appendix “Figures in detail” (one monthly table per utility; temperature and degree days only for heating utilities) |
| `billing` | *(v3.6.0)* `1` = with appendix “Billing years”: for each utility with bill check, the periods ending in the report year ([Billing periods](#billing-periods-v360)); the other pages stay with the calendar year |
| `flex` | *(v3.6.0)* `1` = with page “Flexibility”: result, action sentences, typical day per half-year, best time windows, “Your lever”, solar power ([Flexibility](#valuing-flexibility-v330)); left out without traffic light and levers |

### `GET /api/reports/yearly?year=YYYY` *(v3.1.0)*

The same report as data, unformatted — the basis of the print view
(`#/report/print`), which formats numbers, dates and amounts in the browser and
prints any script. Stability class B. Fields: `year`, `created_on`,
`location_name`, `version`, `efficiency` (as `/api/benchmarks/efficiency`),
`utilities[]` (`utility`, `label`, `accounting_kind`, `unit`, `consumption`,
`cost`, `co2_kg`), `has_generation`, `meters[]` (`utility`, `label`,
`meter_id`, `meter_name`, `accounting_kind`, `unit`, `months[]` with `ym`,
`value`, `cost`, `avg_temp`, `hdd`, `heat_adjusted`, `weather_delta_pct`;
`kpis` with `sum`, `avg_month`, `cost`, `peak`, `low`; `has_weather_adjusted`,
`has_temperature`) and `recommendations[]` (the first 12, as
`/api/recommendations`). Selection: active utilities, one entry per meter with
consumption in the year.

Since **v3.4.0** (additive) also **`model`** — the data model the PDF is built
from too (`ReportModelService`), with finished sentences in the language of the
request and the figures for the charts. Parameters `lang`, `utilities` and
`detail` as for the PDF; they apply to `model`, `utilities` also to the fields
up to v3.3. Fields:

| Field | Content |
|---|---|
| `version`, `year`, `today`, `today_text`, `lang`, `created_on`, `location_name`, `options` | frame; `today` = “as of today” |
| `texts` | headings and fixed sentences of both outputs |
| `utilities[]` | utilities of the report with `color` (SSOT) and `print_color` (darkened for print, ≥ 3:1 on white) |
| `headline` | `tiles[]` (`key`, `label`, `value`, `value_text`, `pct`, `sub`, `spark` over three years), `lights[]` (`key` cost/consumption/billing/deadlines, `level` ok/warn/alert/none, `word`, `detail`, `as_of_today`), `top_tasks[]` |
| `tasks[]`, `successes[]`, `infos[]` | per entry `id`, `kind`, `rule`, `priority` (1–3, tasks only), `utility`, `title`, `reason`, `euro` (`value`, `per` year/once/per100, `basis` measured/projected/example, `effect`, `text`, `basis_text`) or `null`, `deadline`, `deadline_text`, `steps[]`, `as_of_today` |
| `agenda` | `events[]` (`date`, `date_text`, `title`, `kind`, `severity`, `days`, `state`), `timelines[]` per current contract (`points[]`, `sentence`, `explainer`) |
| `money` | `total` (`cost`, `prev`, `pct`, `price_effect`, `volume_effect`, `sentence` — like the “Costs” tile: utilities with a contract, since v3.6.0 also heating oil and pellets from the deliveries), `by_utility[]`, `revenue[]`, `monthly[]` (with previous year), `bonus_notes[]`, `balances[]` per contract (`projected_end_balance`, `current_advance`, `suggested_advance` — `null` if the suggestion would be ≤ 0 —, `text`, `advance_text`, `path[]`, `explainer`), `forecast`; `null` without costs |
| `pages[]` | per utility: `value`, `prev_value`, `pct`, `months[]` (with `prev`, `estimated`), `sentence`, `cost` (`basis` contract/deliveries *(v3.6.0: heating oil, pellets)*/tenancy/none, `value`, `text`), `weather`, `efficiency`, `roles`, `water`, `tank`, `sub_meters[]`, `notes[]`, `weather_rows[]` |
| `topics` | `pv`, `heat_pump`, `ev`, `flexibility`, `tenancy` — each `null` when there is no data |
| `glossary[]`, `sources[]` | glossary (only terms that appear) and sources |
| `appendix[]` | only with `detail=1`: per utility `head[]`, `rows[]`, `total[]`, `notes[]` |
| `billing[]` | *(v3.6.0)* with `detail=1` or `billing=1`: appendix “Billing years” per utility with bill check, same shape; periods ending in the report year. The PDF shows it only with `billing=1` (`options.billing`) |
| `flex_page` | *(v3.6.0)* with `detail=1` or `flex=1`: page “Flexibility” as finished sentences — `big`, `sentence`, `facts[]` (`label`, `value`), `footer`, `actions[]` (`text`, `tag`, `other`, `signal`), `actions_lead`, `bands[]` per half-year (`season`, `label`, `hours[]` with `level`, `sun`, `ct`, `summary`), `band_lead`, `band_lead_pdf`, `windows` (`lead`, `head[]`, `rows[]`), `levers` (`head[]`, `rows[]` with `label`, `signal`, `real`, `real_text`, `kwh`, `eur`, `total`, `dynamic_note`), `solar_text`, `notes[]`, `legend[]`; `null` without traffic light and levers. The PDF shows it only with `flex=1` (`options.flex`), the print view with `flex=1` in its address |
| `errors[]` | sections that could not be read — the rest of the report stands |

### Charging record *(v3.1.0)*

The statement for reimbursing the charging electricity of a company car under
the German Federal Ministry of Finance (BMF) letter of 11 Nov 2025 — a
statement, not tax advice ([Guide](../anleitungen/ladestrom-nachweis.md)).
Class B.

| Route | Response |
|---|---|
| `GET /api/reports/ev-charging` | JSON (see below) |
| `GET /api/reports/ev-charging.csv` | CSV `energietracker-ladestrom-YYYY.csv`; `format=1` (default) or `local` with `lang` like the [CSV formats](#csv-formats-v310) |
| `GET /api/reports/ev-charging.pdf` | PDF `energietracker-ladestrom-YYYY.pdf` in the default language, `?inline=1` shows it in the browser; a language without PDF fonts → `422` `errors.report.pdfUnsupportedLanguage` |

| Parameter | Meaning |
|---|---|
| `meter_id` | electricity meter of the wall box (role `ev_charger`, usually a sub-meter of the household meter); unknown → `404` |
| `year` | 2017–2100, default previous year; otherwise `400` `errors.evReport.yearInvalid` |
| `method` | `contract` (default) or `flat`; otherwise `400` `errors.evReport.methodInvalid` |
| `flat_ct` | your own flat rate in ct/kWh (0–200) for `flat`; otherwise `400` `errors.evReport.flatInvalid` |

**`contract`** — price of the paying contract: the wall box’s own contract
(§ 14a EnWG, module 2) or that of the parent meter. Per month the unit price =
energy cost ÷ kWh of the paying meter (day-exact), plus the standing charge pro
rata by kWh (wall box ÷ parent meter; in full with a contract of its own).
Splitting by kWh is an assumption — the letter says “pro rata”. Without a
paying contract `400` `errors.evReport.noContract`.

**`flat`** — flat electricity rate × kWh. The rate comes from `flat_ct` or from
the country profile (`ev_flat_rate_ct_years`, Germany 2026: 34 ct/kWh,
2027: 32 ct/kWh; a year is missing until Destatis has published the first half
of the previous year); if it
is missing, `400` `errors.evReport.flatMissing`. Example from the letter:
3,000 kWh × €0.34 = €1,020. The choice applies uniformly per calendar year.

```json
{ "meter_id": "m_wallbox", "meter_name": "Wall box", "serial": "WB-0001",
  "role": "ev_charger", "year": 2026, "method": "contract", "flat_ct": null,
  "payer_meter_id": "m_strom_haus",
  "rows": [ { "ym": "2026-03", "kwh": 100.0, "price_ct": 30.0, "base_share_eur": 3.0,
              "amount_eur": 33.0, "estimated": false,
              "first_reading": { "date": "2026-03-01", "counter": 1520.4 },
              "last_reading":  { "date": "2026-03-31", "counter": 1617.9 } }, "…" ],
  "total": { "kwh": 1180.5, "amount_eur": 389.62 },
  "price_missing": false }
```

`estimated` = the month contains estimated days; `first_reading`/`last_reading`
are the first and last reading in the month (`null` without a reading).
`price_ct` and `amount_eur` are `null` where a price is missing; then
`price_missing` is `true`.

CSV in format 1 with a fixed header:

```text
Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode
```

*(v3.2.0)* The wall box meter readings can come from evcc — as readings on the
meter they enter the record like recorded ones:
[Charging sessions from evcc](#charging-sessions-from-evcc-v320).

### Charging sessions from evcc *(v3.2.0)*

evcc controls the wall box — with solar power or in cheap hours — and keeps,
per charging session, start, end, energy, solar share, price and, where the
wall box measures it, the meter reading. The Energietracker takes over these
sessions and does the maths; it controls nothing. Two ways with the same
processing: the CSV export from evcc (charging sessions → download) or the
fetch through the evcc API in your own network. Step by step:
[Charging sessions from evcc](../anleitungen/evcc.md). Class C.

| Route | Body | Purpose |
|---|---|---|
| `POST /api/utility/strom/meters/{id}/import-evcc[?dry_run=1]` | `{csv, counters?, loadpoint?}` | take over the CSV export |
| `POST /api/utility/strom/meters/{id}/sync-evcc[?dry_run=1]` | `{counters?, loadpoint?}` | fetch the sessions from `GET <evcc_endpoint>/api/sessions` |
| `GET /api/ev-sessions?meter_id=&year=` | — | stored sessions and monthly totals |

`{id}` is an electricity meter with meter readings. The interface offers both
in the view of a meter with the role `ev_charger` (wall box).

| Field | Meaning |
|---|---|
| `csv` | content of the file as text. Header in one of the languages de, en, fr, it, es, pt, nl or with the English field names (`created`, `chargedEnergy` …); a BOM is removed; separator comma or semicolon (decided from the header); numbers with a point or a comma. The columns for start and energy are required |
| `counters` | where the meter readings come from: `auto` (default) — the wall box meter readings, if they are missing the summed-up energy; `energy` — always sum up, from the last reading before the first session, without one from the initial reading of the installed device (say when the meter in the Energietracker is not the one in the wall box); `none` — only the sessions, no readings |
| `loadpoint` | only sessions of this charging point (name as in evcc); if missing, all |

Processing:

- A session needs start and energy (not negative), otherwise it counts under
  `skipped`. If it is still running (end empty or `0001-01-01…`), it counts
  under `running` and comes complete with the next import.
- Times without a time zone are taken in the installation’s time zone
  (`timezone`); `date` is the day of the end.
- Every session gets an identifier from start and charging point. Importing
  the same session again replaces it instead of counting it twice.
- Meter readings (a reading on day D counts as the reading at the start of D):
  with wall box meter readings (`counter_source: "meter"`) the start reading on
  the day of the start and the end reading on the day after the end (the
  highest per day); otherwise (`"energy"`) the reading up to the day of the
  first session plus the charged energy, on the day after each session.
  Readings after today and a drop are left out. They go to the meter as
  readings with the note `evcc`; an existing reading on the same day is
  replaced.
- Meter readings belong to one wall box: if the sessions come from several
  charging points and `counters` is not `none`, the route answers `400`
  `errors.evcc.loadpointNeeded` — then give `loadpoint`.

Response, here the preview with `?dry_run=1`:

```json
{ "sessions": 12, "skipped": 0, "running": 1, "loadpoints": ["Garage"],
  "from": "2026-09-02", "to": "2026-09-30",
  "charged_kwh": 214.37, "solar_kwh": 131.902,
  "readings": 12, "replaces": 0, "counter_source": "meter", "dry_run": true }
```

| Field | Meaning |
|---|---|
| `sessions` | sessions taken over (after the `loadpoint` filter) |
| `skipped`, `running` | unreadable or still running sessions |
| `loadpoints` | every charging point in the file or the answer — also those `loadpoint` filters out |
| `from`, `to` | first and last day |
| `charged_kwh`, `solar_kwh` | charged energy and the part from the sun (energy × solar share per session) |
| `readings`, `replaces` | derived meter readings; `replaces` of them replace an existing reading on the same day |
| `counter_source` | `meter`, `energy` or `none` |
| `import` | only without `dry_run`: result of the reading import as for the CSV import (`imported`, `overwritten`, `skipped`, `errors`); `null` without meter readings |

**Fetch — `sync-evcc`.** The server fetches `GET <evcc_endpoint>/api/sessions`
(at most 30 seconds) and expects a list with the fields `created`, `finished`,
`loadpoint`, `vehicle`, `meterStart`, `meterStop`, `chargedEnergy`,
`solarPercentage`, `price`, `pricePerKWh`; the envelope `{result: […]}` of
older evcc versions is unwrapped. As with
[text recognition](#post-apiocrreading), home network only: the name is
resolved, every address must be local, the connection goes to exactly the
checked address, redirects are not followed.

**Stored sessions — `GET /api/ev-sessions`.**

```json
{ "sessions": [ { "id": "evs_1a2b3c4d5e6f", "meter_id": "m_wallbox",
      "created": "2026-09-30T17:02:11+02:00", "finished": "2026-09-30T21:40:03+02:00",
      "date": "2026-09-30", "loadpoint": "Garage", "vehicle": "Small car",
      "charged_kwh": 18.402, "solar_pct": 0, "price_eur": 5.15, "price_per_kwh": 0.28,
      "meter_start": 4120.551, "meter_stop": 4138.953, "source": "csv" } ],
  "monthly": { "2026-09": { "kwh": 214.37, "solar_kwh": 131.902, "solar_pct": 61.5,
                            "price_eur": 38.4, "sessions": 12 } } }
```

`meter_id` and `year` filter, both are optional; an unknown meter gives an
empty list. `monthly` only exists with both, otherwise `null`: per month
`kwh`, `solar_kwh`, `solar_pct` (weighted by energy), `price_eur` (sum of the
prices according to evcc, `null` without prices) and `sessions`. A `year`
outside 2017–2100 → `400` `errors.evReport.yearInvalid`. The fields per
session: [Data model](datenmodell.md#charging-sessions-ev_sessionsjson-v320).

| Status | `code` | When |
|---|---|---|
| `404` | `errors.common.meterNotFound` | no electricity meter with this ID |
| `400` | `errors.reading.periodMeter` | a meter with consumption per period — it takes no readings |
| `400` | `errors.evcc.countersInvalid` | `counters` is not `auto`, `energy` or `none` |
| `400` | `errors.evcc.loadpointNeeded` | sessions of several charging points with meter readings — give `loadpoint` or `counters: "none"` |
| `400` | `errors.evcc.columns` | a CSV without the columns for start or energy |
| `400` | `errors.evcc.noSessions` | no finished session — also for an empty file or a `loadpoint` without a match |
| `400` | `errors.evcc.off` | `sync-evcc`: `evcc_endpoint` is empty |
| `400` | `errors.evcc.notLocal` | `sync-evcc`: an address of evcc is not in your own network |
| `502` | `errors.evcc.unreachable` | `sync-evcc`: name does not resolve, no connection or an HTTP error |
| `502` | `errors.evcc.badAnswer` | `sync-evcc`: the answer is not a list of charging sessions |

### `GET /api/agenda?days=90` *(v3.1.0)*

Deadlines and dates from **one** source: the dashboard (“To do”), the calendar
and Home Assistant read the same events (`AgendaService`). Up to v3.0 the
dashboard assembled “To do” in the browser. Stability class B.

`days` is the window from today (default 90, limited to 0–730). Anything
already due or overdue is added. Sorted by date, then kind.

```json
{ "success": true, "data": { "events": [
  { "uid": "reading-strom-m_strom_main", "kind": "reading_due", "date": "2026-10-07",
    "title": "Electricity: take a meter reading (Main electricity meter)",
    "params": { "label": "Electricity", "meter": "Main electricity meter" },
    "severity": "due", "due_now": true,
    "href": "#/zaehlerstaende?meter=m_strom_main",
    "ref": { "utility": "strom", "meter_id": "m_strom_main", "last_reading": "2026-08-19",
             "days_since_reading": 49, "icon": "⚡" } },
  { "uid": "cancel_by-c_strom_003", "kind": "cancel_by", "date": "2026-11-30",
    "title": "Electricity: last day to cancel (Musterstadt Utilities)",
    "params": { "label": "Electricity", "provider": "Musterstadt Utilities" },
    "severity": "upcoming", "due_now": false,
    "href": "#/utility/strom/contracts",
    "ref": { "utility": "strom", "meter_id": "m_strom_main", "contract_id": "c_strom_003", "days": 54 } }
] } }
```

| `kind` | Event | `date` | `due_now` (listed under “To do”) |
|---|---|---|---|
| `reminder` | date (maintenance, calibration deadline …) | next due date | date due or overdue |
| `reading_due` | reading due (cumulative meters in service) | last reading + `alert_days_since_reading`; if that has passed or there is no reading: today | last reading older than `alert_days_since_reading` |
| `cancel_by` | last day to cancel | cancellation deadline of a current or future contract | contract reminder (recommendation R6) active and not dismissed |
| `term_end` | fixed contract end (not for contracts that keep running) | contract end | like `cancel_by` when the reminder refers to the end |
| `price_guarantee_end` | end of the price guarantee | `price_guarantee_until` | never |
| `price_increase` | recorded price increase — check special termination | day of the increase | *(since v3.1.0)* while the recommendation `r_price_increase` (country DE only) stands and is not hidden — then `severity` `due` and `ref.recommendation_id`; hidden, `upcoming` again. Up to v3.0: never |
| `tank_reorder` | heating oil/pellets: stock low (recommendation R5) | today | always, as long as the recommendation is not dismissed |
| `tenancy_statement_due` *(v3.1.0)* | service charge statement for the last completed billing period due (§ 556 (3) BGB) — only as long as no statement for that period has been recorded | end of the period + 12 months | never |
| `objection_deadline` *(v3.1.0)* | last day to object to a statement | receipt (`received_on`) + 12 months | from 30 days before (`severity` then `due`) |
| `co2_claim_deadline` *(v3.1.0)* | self-contained heating in a rented home: claim the landlord’s share of the CO₂ costs (CO2KostAufG § 6(2)) — only with `case: self_supplied` and an amount above 0 | `deadline` from `/api/co2-split`: bill date of the gas bill + 12 months | from 30 days before (`severity` then `due`) |
| `bill_expected` *(v3.5.0)* | a billing period of a contract has ended, no supplier’s bill recorded — [Bill expected](#bill-expected-v350) | electricity/gas in DE: end + 42 days (§ 40c(2) EnWG); otherwise the day after the end | always; `severity` `due`, after the statutory deadline `overdue` |
| `reading_check` *(v3.9.0)* | check a reading (or a block) — [Check intermediate readings](#check-intermediate-readings-v390) | today | always, until “Reading is correct”, “only for the bill” or a corrected reading settles it; cannot be ticked off |
| `reading_duplicate` *(v3.9.0)* | the same reading entered twice on the same day | today | always, until one is deleted; cannot be ticked off |
| `level_shift` *(v3.9.0)* | consumption consistently different from today for at least a year: note on the baseline date | today | until ticked off (“Not now”) or a baseline date near `ref.date` is set |

**Period meters *(v3.1.0)*.** For a meter with consumption per period,
`reading_due` counts from the end of the last period instead of the last
reading; `ref.last_reading` then carries that date.

**Tenancy *(v3.1.0)*.** The two deadlines of the service charge statement only
exist with `wohnverhaeltnis: "miete"` and per tenancy
([Tenancy](#tenancy-v310)). The billing period follows from `billing_anchor`;
`tenancy_statement_due` appears when this period lies within the tenancy and no
statement ending 31 days or less before its end has been recorded — `ref`:
`{tenancy_id, period_to}`, `params.period` = end of the period, `severity`
`upcoming` (after it has passed `overdue`). `objection_deadline` exists per
statement with `received_on`, until the deadline has passed — `ref`:
`{tenancy_id, statement_id, days}`. Both point to `#/tenancy`. The deadlines
are a pointer, not legal advice.

**CO₂ costs *(v3.1.0, H5)*.** `co2_claim_deadline` exists with
`wohnverhaeltnis: "miete"` for billing years up to two years back when
`GET /api/co2-split` returns `case: self_supplied`, a `landlord_amount_eur`
above 0 and a `deadline` that has not passed yet. Title “CO₂ cost {year}: last
day to claim the landlord’s share of {amount}”, `params` `{year, amount}`
(amount formatted), `href` `#/tenancy`, `ref` `{year, landlord_amount_eur, days}`.

`severity`: `overdue` (date overdue), `urgent` (deadline within 14 days, stock
urgent), `due` (due), `upcoming` (still to come). `title` is in the language of
the request, `params` holds its parts. `href` is the matching view of the
interface, `ref` points to the data (meter, contract, date, recommendation).
`uid` stays the same for the same object — a date marked done keeps it and
moves to the new date.

**Done *(v3.4.0, F1024)*.** Every event also carries `title_key` (catalogue
key of the title), `occurrence` (`uid@anchor`), `can_mark_done` and `done`
(`null` or a note of it); something done is never `due_now` and has
`severity` `upcoming`. A follow-on contract without a gap clears `cancel_by`,
`term_end`, `price_guarantee_end` and `price_increase` of the predecessor by
itself; contracts that renew or have no end no longer have a `cancel_by` (it
was today every day). Details: [Done with history](#done-with-history-v340).

**Responsible *(v3.5.0, F1028)*.** Every event additionally carries
`assignee`, `assignee_id` and `assignee_former` — for reminders the
responsibility ([Responsible for reminders](#responsible-for-reminders-v350)),
otherwise `null`, `null`, `false`; *(v3.6.0)* plus `assignee_deleted` (person
deleted, the name stays).

**Inherited *(v3.7.0)*.** Without a responsible person of their own, the four
fields carry the contract’s, otherwise the meter’s
([Responsible for meters and contracts](#responsible-for-meters-and-contracts-v370)),
plus additionally `assignee_source`: `own` (on the reminder), `contract`,
`meter` or `null` (nobody). A reminder on a meter carries `ref.utility` and
`ref.meter_id`.

| Kind | inherits from |
|---|---|
| `reminder` | the reminder’s meter (`utility`, `meter_id`) |
| `cancel_by`, `term_end`, `price_guarantee_end`, `price_increase`, `bill_expected` | contract, then meter (a group has no responsible person) |
| `reading_due`, `tank_reorder` | meter |
| `tenancy_statement_due`, `objection_deadline`, `co2_claim_deadline` | – |

When ticked off (`POST /api/agenda/done`) the history entry carries the
responsibility as the snapshot `assignee`.

#### Bill expected *(v3.5.0)*

`bill_expected` (F1029): when a billing period of a real contract (not a
shadow contract) of a utility with bill checking (`supports_bill_check`: gas,
electricity, water, district heating — never heating oil, pellets, feed-in)
has ended and no recorded supplier’s bill covers it, the event is in the
agenda. Only the latest ended period per contract:

- **running** (also renewed): the last completed period after
  `billing_cycle_anchor_<utility>`, from the contract start; shorter than 60
  days → no event;
- **ended**: final bill from the last anchor date to the end (a renewed
  contract ends the day before the follow-on contract); if a follow-on contract
  with the same supplier continues without a gap, none.

Not for periods that ended more than 120 days ago
(`BillExpectedService::WINDOW_DAYS`: deadline 42 days plus a good eleven weeks;
after that the event disappears without a history entry), before the first
reading (or first period) or before an effective baseline date. A **matching** bill is
one on the meter, on a member of the group or on the group, without or with
this `contract_id`, whose `period_to` differs by at most 31 days from the end
of the period — then there is no event.

**Deadline** (`Countries::BILL_DEADLINE_DAYS`): only electricity and gas in
Germany, 42 days after the end (§ 40c(2) EnWG: six weeks after the billing
period or after supply ends); after that `overdue` with the title “… —
statutory deadline passed, ask the supplier”. District heating (§ 4(3) FFVAV)
and water (§ 24(1) AVBWasserV) have no deadline: `deadline: null`, never
`overdue`. Monthly billing (three weeks) and § 193 BGB are not modelled.

| Field | Value |
|---|---|
| `uid` | `bill_expected-<contract>-<period end>`; `occurrence` = `uid@<period end>` |
| `title_key` | `calendar.event.billExpected`, `…billOverdue`, `…finalBillExpected`, `…finalBillOverdue`; `params` `{label, provider, from, until}` (days as YYYY-MM-DD, in `title` in the language’s date format) |
| `date` | `deadline`, without a deadline the day after the end of the period |
| `ref` | `{utility, meter_id (meter or group), contract_id, period_from, period_until, final, deadline, overdue}` |
| `href` | `#/bill-check?utility=…&meter=…&from=…&until=…&capture=1` — bill check with this period and the recording section open |
| `can_mark_done` | yes — ticked off means “arrived without being recorded” (`reason` `activity.reason.billWithout`) |

When a bill is recorded (`POST /api/utility/{u}/bills`) or changed so that it
covers the period, a history entry `auto_done` with `reason`
`activity.reason.bill` and `ref.bill_id` is created; deleting or moving the
bill strikes it through and the event is open again.

### `GET /api/calendar.ics` *(v3.1.0)*

The same events as a subscribable calendar according to RFC 5545, without a
third-party service. Stability class A for the **shape** (all-day events, fields
below) and the **UIDs**; the texts may change with a translation. Setting it up
in Apple Calendar, Thunderbird and Google Calendar:
[Subscribe in your calendar](../anleitungen/kalender.md).

- Events of the next **365 days** (`/api/agenda` with `days=365`), all-day
  (`DTSTART;VALUE=DATE`, `DTEND` = next day), `TRANSP:TRANSPARENT` (blocks no
  time), `CATEGORIES` = kind in capitals.
- `UID:<kind>-<id>@<instance_id>`, e.g. `cancel_by-c_strom_003@et_3f9a1c0b7d2e4a61`.
  Forms: `reminder-<id>`, `reading-<utility>-<meter>`, `cancel_by-<contract>`,
  `term_end-<contract>`, `price_guarantee_end-<contract>`,
  `price_increase-<contract>-<date>`, `tank_reorder-<utility>-<meter>`, since
  v3.1.0 `tenancy_statement_due-<tenancy>-<period end>`,
  `objection_deadline-<statement>` and `co2_claim_deadline-<year>`, since
  v3.5.0 `bill_expected-<contract>-<period end>`. When a
  date is marked done, the next fetch shows the same event on the new date.
- *(v3.5.0)* A reminder with someone responsible carries
  `DESCRIPTION:Responsible: <name>` (text `calendar.assignee`; after the
  person was deleted possibly “former member”) — just the line, no
  notification. *(v3.7.0)* Inherited responsibility with an addition:
  `Responsible: <name> (via the contract)` or `(via the meter)`
  (`calendar.assigneeContract`, `…Meter`).
- *(v3.7.0)* **`?person=<id>`** (a person id like `u_3f9a1c2e`, otherwise 400
  `errors.assignee.unknown`): only events assigned to this person — own or
  inherited responsibility by id, a free name on a reminder if it matches
  theirs; deleted people and “former member” do not count. `X-WR-CALNAME`
  names the person (`calendar.nameFor`). Together with the calendar link:
  `…/calendar.ics?token=etk_…&person=u_…`.
- Advance reminder (`VALARM`, `TRIGGER:-P<n>D`) for dates, cancellation
  deadlines, contract ends, price guarantees and price increases; `<n>` is
  `reminder_warn_days_before` (setting, default 14, `0` = none). The deadlines
  of the service charge statement and of the CO₂ refund (v3.1.0) carry no
  advance reminder.
- *(v3.4.0)* Something **done** (`done` in the agenda) stays in the calendar
  with the same UID, `SUMMARY` starts with “✓ ”, a `VALARM` is dropped.
- `REFRESH-INTERVAL;VALUE=DURATION:PT12H` and `X-PUBLISHED-TTL:PT12H`: calendars
  ask again every 12 hours. `X-WR-CALNAME` is the calendar’s name.
- Texts in the **default language of the installation** — calendars send no
  language.
- Response headers `Content-Type: text/calendar; charset=utf-8`,
  `Cache-Control: private, max-age=900`; lines over 75 bytes are folded, CRLF.

```text
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Energietracker//Kalender//EN
CALSCALE:GREGORIAN
METHOD:PUBLISH
X-WR-CALNAME:Energietracker — deadlines and dates
REFRESH-INTERVAL;VALUE=DURATION:PT12H
X-PUBLISHED-TTL:PT12H
BEGIN:VEVENT
UID:cancel_by-c_strom_003@et_3f9a1c0b7d2e4a61
DTSTAMP:20261007T071502Z
DTSTART;VALUE=DATE:20261130
DTEND;VALUE=DATE:20261201
SUMMARY:Electricity: last day to cancel (Musterstadt Utilities)
CATEGORIES:CANCEL_BY
TRANSP:TRANSPARENT
BEGIN:VALARM
ACTION:DISPLAY
DESCRIPTION:Electricity: last day to cancel (Musterstadt Utilities)
TRIGGER:-P14D
END:VALARM
END:VEVENT
END:VCALENDAR
```

**Access.** Without sign-in open like every route. With sign-in: a session or an
API key in the header; for calendar apps a key with scope `calendar` in the
link — `…/api.php/api/calendar.ics?token=etk_…`. Only this scope counts in the
link (see [Sign-in](#sign-in-v260-opt-in)). The interface creates it under
Reminders & tips → Reminders & maintenance → “Subscribe in your calendar”; it can be revoked under
Settings → Access.

### `GET /api/summary` *(v3.1.0)*

Key figures for Home Assistant and scripts in one flat response: one request
for all sensors instead of `consumption`, `contract-status` and `forecast` per
meter. Stability class A with its own version number `summary_version` (today
`1`): **every key is always present** — with a value or `null` —, new ones are
only added, and the number only rises with a breaking change.

| Parameter | Meaning |
|---|---|
| `utility` | only this utility |
| `meter` | only this meter — internal ID or alias (`external_id`) |

```json
{ "success": true, "data": {
  "summary_version": 1,
  "instance_id": "et_3f9a1c0b7d2e4a61",
  "generated_at": "2026-10-07T09:15:02+02:00",
  "app_version": "3.1.0",
  "currency": "EUR",
  "meters": [
    { "key": "strom.m_strom_main", "utility": "strom", "meter_id": "m_strom_main",
      "external_id": "stromzaehler_haus", "name": "Main electricity meter",
      "unit": "kWh", "consumption_unit": "kWh", "role": "household", "is_sub_meter": false,
      "last_reading_date": "2026-09-30", "last_counter": 48211.4,
      "days_since_reading": 7, "reading_due": false,
      "month_to_date": { "value": 61.2, "estimated": true },
      "year_to_date":  { "value": 2318.7, "estimated": true },
      "contract": { "contract_id": "c_strom_003", "provider": "Musterstadt Utilities",
        "tariff_name": "Basic electricity", "working_price_ct": 29.8, "base_price_month": 12.5,
        "advance_month": 78, "suggested_advance": 74, "balance": -41.3,
        "projected_end_balance": -52.9, "verdict": "refund", "balance_as_of": "2026-10-07",
        "period_end": "2027-03-31", "period_last_day": "2027-03-31",
        "cancel_by": "2027-02-28", "days_to_cancel": 144,
        "price_increase_from": null },
      "forecast_12m": { "value": 3120.4, "cost": 1080.55, "low": 2890.1, "high": 3350.7 },
      "tank": null,
      "capture": "counter",
      "utility_label": "Electricity" }
  ],
  "pv": { "autarky_pct_ytd": null, "self_consumption_pct_ytd": null, "strom_saldo_ytd": null },
  "agenda": { "due": 1, "overdue": 0,
              "next": { "kind": "reminder", "date": "2026-10-15", "title": "Test smoke alarms" } },
  "warnings": 0
}}
```

| Field | Meaning |
|---|---|
| `instance_id` | identifier of this installation (`et_` + 16 hex digits), created on first use in `data/instance.json`. The calendar builds its UIDs from it; scripts use it to tell several installations apart. Deliberately **not** in the backup — otherwise two installations would share it after a restore |
| `currency` | currency of the amounts (setting, not converted) |
| `meters[]` | one entry per meter in service of the active utilities |
| `pv` | self-sufficiency and self-consumption rate (%) and electricity balance of the current year, otherwise `null` |
| `agenda` | `due` = entries under “To do”, `overdue` = of these overdue or urgent, `next` = next date or deadline (`kind`, `date`, `title`) or `null`; since v3.4.0 each without what is done; since v3.5.0 `bill_expected` counts too (overdue after the statutory deadline, `next` with its date) |
| `warnings` | number of unconfirmed suspect readings (Home Assistant) |

Per meter:

| Field | Meaning |
|---|---|
| `key` | `<utility>.<meter_id>` — unique, basis of the Home Assistant template |
| `external_id` | alias for Home Assistant or `null` |
| `unit`, `consumption_unit` | unit of the meter reading and of the consumption (m³ and kWh for gas) |
| `role`, `is_sub_meter` | the **effective** role of the meter — without a stored role the utility’s default role (for instance `household`), `null` only for utilities without roles —, sub-meter yes/no |
| `last_reading_date`, `last_counter`, `days_since_reading` | last valid reading (without suspect and planned ones). For a meter with consumption per period: `last_reading_date` = end of the last period, `last_counter` `null`, the days counted from that end |
| `reading_due` | reading or next period older than `alert_days_since_reading` (cumulative meters only) |
| `capture` | *(v3.1.0)* way of recording: `counter` (meter readings) or `period` (consumption per period) |
| `utility_label` | *(v3.9.0, last key of the entry)* name of the utility in the language of the request (without `X-ET-Language` that of the installation), for example “Electricity” |
| `month_to_date`, `year_to_date` | consumption of the current month and year in `consumption_unit`, `estimated: true` = contains estimated days; `null` without data in the year |
| `contract` | current contract (utilities with contracts), otherwise `null`: prices and advance payment, `balance` and `projected_end_balance` with the sign of `contract-status` (positive = additional payment, for feed-in = payout), `verdict`, `period_end`, *(v3.7.0, additive)* `period_last_day`, `cancel_by`, `days_to_cancel`, `price_increase_from`. For a contract with a maintained end date `period_end` is that end (inclusive), otherwise the settlement date of the next bill — the **first day of the next period**; it stays that way for existing sensors. `period_last_day` is always the **last day** of the period: the end itself or the day before the settlement date (settlement date 01-09: `period_end` 2027-09-01, `period_last_day` 2027-08-31). Since v3.4.0 `cancel_by` and `days_to_cancel` are `null` when the contract has no dated deadline (renewing, open-ended, cancellable at any time — up to v3.3 it said “today” every day) or a follow-on contract continues without a gap |
| `forecast_12m` | forecast of the next twelve months: `value` in `consumption_unit`, `cost` (`null` for generation only), `low`/`high` = annual band; `null` for heating oil/pellets or without a valid forecast |
| `tank` | heating oil/pellets: `stock`, `capacity`, `unit`, `percent`, `estimated_from`; otherwise `null` |

**Access.** Open without sign-in. With sign-in an API key with scope `read` as
`Authorization: Bearer etk_…` (the ingest token `et_…` does not work here).
Response header `Cache-Control: private, max-age=300`: Home Assistant polls,
five minutes are enough. `agenda.next.title` is in the default language of the
installation unless the request sends `X-ET-Language`. Template for Home
Assistant:
[Home Assistant, step 5](../anleitungen/home-assistant.md#step-5--values-back-to-home-assistant).

### `GET /api/todo` *(v3.9.0)*

The “To do” list for a sensor in Home Assistant and for scripts
([#36](https://github.com/Bingerminger/energietracker/issues/36)). The source
is the [agenda](#get-apiagendadays90-v310) events with `due_now`, without what
is done. Every due event counts, whatever its kind: a new kind counts without
any change to sensors. Three due readings are three items (the overview
combines them in one line). Stability class A with its own version number
`todo_version` (today `1`).

```json
{ "success": true, "data": {
  "todo_version": 1,
  "instance_id": "et_3f9a1c0b7d2e4a61",
  "generated_at": "2026-11-03T08:00:04+01:00",
  "count": 2,
  "overdue": 1,
  "next": { "uid": "reminder-r_1a2b", "kind": "reminder", "title": "Check smoke detectors",
            "date": "2026-11-01", "severity": "overdue", "href": "#/reminders" },
  "items": [
    { "uid": "reminder-r_1a2b", "kind": "reminder", "title": "Check smoke detectors",
      "date": "2026-11-01", "severity": "overdue", "href": "#/reminders" },
    { "uid": "reading-strom-m_strom_main", "kind": "reading_due", "title": "Electricity: take a meter reading (Main meter)",
      "date": "2026-11-03", "severity": "due", "href": "#/zaehlerstaende?meter=m_strom_main" }
  ]
}}
```

| Field | Meaning |
|---|---|
| `count` | number of due items |
| `overdue` | of which overdue or urgent (as `agenda.overdue` in `/api/summary`) |
| `next` | the first item of the order, otherwise `null` |
| `items[]` | the items, at most 50: overdue, then urgent, then due; within that by date, then by kind and `uid`. Per item `uid`, `kind` (kind as in the agenda), `title` (in the language of the request, without `X-ET-Language` that of the installation), `date`, `severity`, `href` (jump into the app) |
| `instance_id` | ID of this installation, as in `/api/summary` |

**Access** as for `/api/summary`: open without sign-in, with sign-in an API key
with scope `read`. Template:
[Home Assistant → “To do” sensor](../anleitungen/home-assistant.md#to-do-sensor-v390).

### Notifications to Home Assistant *(v3.9.0)*

`GET /api/homeassistant/notify` returns the state, `PATCH` saves (admins only),
`POST /api/homeassistant/notify/test` sends a test message (admins only).
Class C. Webhook ID and token never come back, only `has_webhook` and
`has_token`; they are in `data/ha_notify_secret.json` (mode 0600), not in the
settings and not in the backup.

```json
{ "success": true, "data": {
  "enabled": true, "target": "webhook", "url": "http://192.168.178.10:8123",
  "service": "persistent_notification.create",
  "events": ["reminder", "deadline", "warning", "document"], "hour": 8,
  "has_webhook": true, "has_token": false, "supervisor_available": false,
  "complete": true, "ready": true,
  "targets": ["supervisor", "webhook", "token"], "all_events": ["reminder", "deadline", "warning", "document"],
  "last": { "at": "2026-11-03T08:00:05+01:00", "ok": true, "status": 200, "error": null, "category": "deadline" },
  "daily_on": "2026-11-03", "sent_count": 12
}}
```

| `PATCH` field | Meaning |
|---|---|
| `enabled` | switch; switching on only works with a complete destination (otherwise `400` `errors.haNotify.incomplete`) |
| `target` | `supervisor` (app in Home Assistant, `SUPERVISOR_TOKEN`), `webhook` or `token` |
| `url` | Home Assistant address without a path; in the home network `http://` or `https://`, outside only `https://` |
| `webhook_id` | 8 to 200 characters from letters, digits, `-`, `_`; a whole address `…/api/webhook/<id>` is shortened; empty = keep |
| `token` | long-lived token from Home Assistant (a copied `Bearer ` is dropped); empty = keep |
| `clear_webhook`, `clear_token` | `true` deletes the secret |
| `service` | `persistent_notification.create` or `notify.<name>` (not `notify.send_message`) |
| `events` | subset of `reminder`, `deadline`, `warning`, `document` |
| `hour` | hour (0 to 23) from which the daily check runs |

The test message answers `200` with `{ok, status, error, target}`; `error` is
`incomplete`, `url`, `dns`, `address` (link-local, blocked), `insecure`
(public address without `https://`), `timeout`, `network`, `http` (then
`status` from Home Assistant) or `noTransport`. When what is sent:
[Home Assistant, step 6](../anleitungen/home-assistant.md#step-6--notifications-to-home-assistant-v390).

### Done with history *(v3.4.0)*

Notices can be marked as done — by hand or by themselves from the data — and a
history records what was done when and by whom
([guide](../anleitungen/hinweise-erledigen.md), pot
[`activity`](datenmodell.md#history-of-done-notices-activityjson-v340)).
`/api/activity` and `POST /api/agenda/done` are stability class C; the new
fields in the agenda and `contract-status` belong to their class B.

**Follow-on contract (`contract-status`, additive).** Per contract
`successor`: `null` or `{contract_id, provider, start, gap_days}` — the next
real contract of the same meter or group (no shadow contract) that starts later
and does not end before this one. `provider` falls back to the tariff name.
`gap_days` = days without a contract between end + 1 and the start, `0` =
without a gap (also when they overlap); a contract that renews or has no end
runs until the follow-on contract, so there it is always `0`. With
`gap_days: 0`, `should_remind` is `false`, `remind_stage` `0` and
`cancel_missed` `false` — recommendation R6, the notices in the analysis and
“Cancel by” go away. With a gap the reminder stays; the R6 text names the gap.
*Changed value:* `remind_basis` is `cancel_by` only for a dated deadline; a
fixed-term contract that can be cancelled at any time (`notice_mode`
`month_end`/`any_day`) reminds of its end (`end`) instead of “cancel by today”
every day.

**Agenda (additive).**

| Field | Meaning |
|---|---|
| `title_key` | catalogue key of the title (with `params` it can be rendered in any language) |
| `occurrence` | `uid@anchor`: a contract its end or `renewed`/`open`, a tank the last delivery (`none` without), a reminder `next_due`, a reading the last reading, tenancy deadlines their date. A tick applies to this occurrence; if the anchor changes, the notice is open again |
| `can_mark_done` | can be ticked off by hand: `reminder`, `cancel_by`, `term_end`, `price_increase`, `tank_reorder`, `objection_deadline`, `co2_claim_deadline`, since v3.5.0 `bill_expected` — and not done yet |
| `done` | `null` or `{auto, on, by, activity_id, note, reason, successor_id}` (fields without a value are left out). `auto: true` with `reason {key: "activity.reason.successor", params: {provider, date}}` = follow-on contract without a gap |

Contracts that renew or have no end, and fixed-term contracts that can be
cancelled at any time, no longer have a `cancel_by` event (`switchTiming` still
returns “today” there for switch planning). R6 and `r_price_increase` and R5
carry the additive `evidence.contract_id`, `evidence.deadline` (deadline,
contract end or day of the increase) and `evidence.anchor` (last delivery).
Ticked off by hand they no longer appear for that occurrence.

**`POST /api/agenda/done`** `{uid, occurrence?, done_on?, note?, attachment_id?, cancelled?}`

- `uid` from the agenda (window 730 days); `occurrence` optional as a check —
  if it differs, `409` `errors.activity.occurrenceChanged`.
- `done_on` a calendar day, not in the future (default today); `note` up to
  500 characters; `attachment_id` an existing receipt (upload with
  `kind=other`, reference type `activity`).
- `cancelled: true` on `cancel_by`: sets `auto_renews: false` on the contract
  (undo restores it).
- A reminder goes through `ReminderService::markDone` — like
  `POST /api/reminders/{id}/done` (next due date, `last_done`).
- Response `201` `{activity_id, entry, event}`; already done `200` with
  `duplicate: true` and the existing entry. `404`
  `errors.activity.eventNotFound`, `422` `errors.activity.notManual`
  (`reading_due`, `tenancy_statement_due`, `price_guarantee_end`).

**Automatic entries** (`action: "auto_done"`, `reason`): a follow-on contract
without a gap created or changed (the predecessor still runs or renews;
`subject.kind: "contract"`, `ref.successor_id`; deleting or changing the
follow-on contract strikes it through), a reading recorded — **only if
`reading_due` was due** and the reading is newer than the last one, otherwise
the daily Home Assistant ingest would fill the history —, a delivery with low
stock, a service-charge statement for the open period. `by` is the acting
person or key, for the ingest `{type: "integration", name: "home_assistant"}`,
for evcc `name: "evcc"`, without sign-in `{type: "auto", reason}`. Since
v3.5.0 also: a new period on meters with consumption per period (like a
reading, only when due; `reason` `activity.reason.period` with `from`, `to`), a
recorded supplier’s bill for an expected bill (`activity.reason.bill`,
`ref.bill_id`).

**Changed *(v3.5.0)*** (`action: "updated"`): a changed service-charge
statement (`PATCH /api/tenancies/{id}/statements/{sid}`) appears as
`subject.kind: "tenancy_statement"`, `title_key` `activity.changed.statement`
in the history — as proof only, it clears nothing and cannot be undone. If only
the change closes the open period, the entry is the `auto_done` for
`tenancy_statement_due` instead (never both); if the change reopens it, that
clearing is struck through. Unchanged statements create no entry.

**Responsible *(v3.5.0)*.** When a reminder is done the entry additionally
carries `assignee`: `{name, id?}` as a snapshot or `{former: true}` after
“former member”.

**`GET /api/activity`** — list of entries (fields in the
[data model](datenmodell.md#history-of-done-notices-activityjson-v340)),
newest first by `done_on`, then `recorded_at`. Filters `from`, `to` (`400`
`errors.activity.dateInvalid` for an invalid day), `by`, `kind`, `ref`,
`limit`. Read keys may read.

**`POST /api/activity/{id}/undo`** — sets `undone {at, by}` and restores what
`revert` names (reminder: `next_due`, `active`, `last_done`; cancellation:
`auto_renews`; hiding: show the recommendation again), as long as the state is
still the one after it was done. Response: the entry with `reverted`
(`true`/`false`). A second undo changes nothing. `404`
`errors.activity.notFound`.

**`PATCH /api/activity/{id}`** `{note}` — change the note; without `note`
`400` `errors.activity.noteInvalid`.

**`DELETE /api/activity?before=YYYY-MM-DD`** — removes entries done before that
day, without `before` all of them; response `{deleted}`. Admins only. Receipts
of removed entries are released.

**Rights.** Admins and members may tick off, undo and edit notes; only admins
clear the history and change `activity_keep_months` (`403`
`errors.auth.adminOnly`); a read key writes nothing (`403`
`errors.auth.readOnlyKey`). Without sign-in everything is allowed, `by` is then
`null`. **Hiding** (`POST /api/recommendations/{id}/dismiss`) adds a
`dismissed` entry (`subject.kind: "recommendation"`); `DELETE …/dismiss`
strikes it through. **Retention:** `activity_keep_months` (default 60, `0` =
unlimited), cleaned up when writing.

### History as CSV *(v3.5.0)*

`GET /api/activity.csv` — the same history as a file for proof (for example for
a landlord or property manager). Filters as `GET /api/activity` (`from`, `to`,
`by`, `kind`, `ref`, `limit`; without `limit` up to 5000 entries), newest
first; struck-through (undone) entries are left out. Format as the other CSV
exports ([CSV formats](#csv-formats-v310)): UTF-8 with BOM, CRLF, protection
against formulas (apostrophe before `=`, `+`, `-`, `@`).

| `format` | header | action | language of title, by, reason |
|---|---|---|---|
| `1` (default) | `Datum;Aktion;Titel;Wer;Grund;Notiz` | code: `done`, `auto_done`, `dismissed`, `updated` | of the request (without `X-ET-Language` the default language) |
| `local` (`&lang=`) | `csvLocal.activity.*` of the language, separator `;` with a decimal comma, otherwise `,` | `activity.actionName.*` | `lang`, otherwise the default language |

File name `energietracker-verlauf-YYYY-MM-DD.csv` (format 1) or after
`csvLocal.file.activity`. The interface downloads “As CSV” with
`format=local` and the language of the device. Unknown format `400`
`errors.export.formatInvalid`, invalid day `400` `errors.activity.dateInvalid`.
Class C.

### Responsible for reminders *(v3.5.0)*

A reminder (`reminders.json`) optionally has a responsible person (F1028). No
schema step; missing fields = nobody. Since v3.7.0 meters and contracts too
([below](#responsible-for-meters-and-contracts-v370)). Nobody is notified.

| Field | Meaning |
|---|---|
| `assignee` | display name, trimmed, up to 60 characters, no control characters; empty or missing = nobody |
| `assignee_id` | person id ([People](#people-in-the-household-v320), [register](#register-of-people-v360)), only when picked from the list of people; then the person’s current name applies — on a completed one-off reminder the name at the time it was done |
| `assignee_former` | `true` after the person was deleted and their name replaced (“former member”) |
| `assignee_deleted` | *(v3.6.0)* `true`: the person has been deleted (note on the reminder or according to the register); name and id stay, the interface shows “Name (deleted)”, so does the calendar |

`GET /api/reminders` always returns all four (`null`, `null`, `false`, `false`
without anyone responsible). `POST`/`PATCH /api/reminders…` take `assignee` and
`assignee_id`: a known `assignee_id` sets the person’s name, `assignee` without
`assignee_id` is a free name, both empty = nobody; a `PATCH` without these
fields leaves the responsibility unchanged. Errors `400`
`errors.reminder.assigneeInvalid` (too long, control characters, not text) or
`errors.reminder.assigneeUnknown` (unknown or, since v3.6.0, deleted person).
Ticking off (`…/done`) keeps the responsibility; the history entry names it.

**Person deleted** (`DELETE /api/users/{id}`), *v3.6.0* — up to v3.5.0 only
the `assignee_id` disappeared and the name stayed as a free name: **open**
reminders (active; by id or with the same free name without an id) pass to
the person deleting. **Completed** ones keep name and id with
`assignee_deleted: true`, with `?anonymize_activity=1` `assignee_former: true`
instead. If an API key deletes (no person), open reminders also stay with
`assignee_deleted: true`. In the history `by`, `undone.by` and `assignee` of
the person get `deleted: true` (or, with `anonymize_activity`, `former: true`
as before).

**List of people:** `GET /api/people` → `[{id, name}]` for every signed-in role
(the full list `GET /api/users` stays with admins). Since v3.6.0 from the
register of people, without deleted ones, and also without sign-in. The admin
from before v3.2 only counts once they are stored.

### Responsible for meters and contracts *(v3.7.0)*

Meters and contracts optionally accept `assignee_id` — a person from the
[register](#register-of-people-v360), no free names. Additive, no schema step;
a `PATCH` without the field changes nothing.

| Route | What |
|---|---|
| `POST`/`PATCH /api/utility/{u}/meters…`, `…/contracts…` | `assignee_id`: person id; `null` or empty = nobody. Unknown, deleted or not an id → `400` `errors.assignee.unknown`. The current person may stay unchanged even if deleted in the meantime |
| `GET` (list, single) and the responses of `POST`/`PATCH` | plus `assignee` (name from the register), `assignee_former`, `assignee_deleted` — always all four; an unknown id shows nobody |
| `POST`/`PATCH /api/reminders…` | `utility` and `meter_id`: the reminder’s meter (must exist, otherwise `400` `errors.reminder.meterUnknown`); `meter_id: null` removes it |
| `GET /api/reminders` | `assignee_inherited`: `{assignee, assignee_id, assignee_former, assignee_deleted, assignee_source: "meter"}` or `null` — only when the reminder has no responsible person of its own |
| `GET /api/agenda` | per event the inherited responsibility and `assignee_source` ([Agenda](#get-apiagendadays90-v310)) |
| `GET /api/calendar.ics` | inherited with an addition in `DESCRIPTION`, `?person=<id>` ([Calendar](#get-apicalendarics-v310)) |
| `GET /api/users/{id}/handover` | plus `meters`, `contracts`: how many pass over |
| `DELETE /api/users/{id}` | meters and contracts to the person deleting (`records_handed_over`); without one the id stays (`records_kept`, “Name (deleted)”), with `?anonymize_activity=1` `assignee_former: true` replaces the id |

Home Assistant: `POST /api/ingest` and `GET /api/summary` stay unchanged.

### Register of people *(v3.6.0)*

Every person gets a **running number** `no` that is never given out again;
deleted people stay noted in the register (`data/people.json`, pot `people` in
the backup, without passwords —
[data model](datenmodell.md#register-of-people-peoplejson-v360)). The id stays
the account’s (`u_…`). Existing accounts get their number on the first read. A
new account with the name of a person without an account (e.g. from a backup)
takes over their id and number.

**People without sign-in** (from a backup or a sample household) are listed by
`GET /api/users` after the accounts, with `has_account: false` and
`source: "none"`. `GET …/handover` and `DELETE /api/users/{id}` work for them
too (register only, `auth.json` stays untouched), and so does
`PATCH /api/users/{id}` with `{name?, role?}` — the same rules as for an
account (name 1–40 characters, `400` `errors.users.nameInvalid`; unique among
all people and accounts, case-insensitive, otherwise `409`
`errors.users.nameTaken`, or `errors.users.nameTakenPerson` when a person
without sign-in has the name; role `admin` or `member`, otherwise `400`
`errors.users.roleInvalid`); a password gives `400`
`errors.users.noAccountPassword` (create an account for that). The response
looks like an entry of `GET /api/users` (`has_account: false`). Open reminders
then show the new name (they read it via `assignee_id`); completed reminders
and the history keep the name from when the item was done. A deleted person
can no longer be changed (`404`).

**Names are unique across all people** (accounts and people without sign-in;
deleted ones do not count). If `PATCH /api/users/{id}` renames an account to
the name of a person without sign-in, the app answers `409`
`errors.users.nameTakenPerson`. `POST /api/users` with that name is no
conflict: the new account takes over the person’s id and number (as before).
Without sign-in every `/api/users` route is reachable like any setting; `to`
is then `null`, and open reminders stay with `assignee_deleted: true`.

`GET /api/users/{id}/handover` (admins only) for the dialog:

```json
{ "person": { "id": "u_8b0d4f17", "name": "Kim", "no": 2, "has_account": true },
  "to": { "id": "u_3f9a1c2e", "name": "Alex" },
  "open": 1, "done": 3 }
```

`open` = open reminders that pass over; `done` = history entries the person
appears in; `to` = the requesting person (`null` with an API key).
`DELETE /api/users/{id}` additionally answers with `handed_over` (reminders
passed over), `handed_over_to` (`{id, name}` or `null`) and `kept` (reminders
that stay with the note). Unknown person `404` `errors.users.notFound`; a
member `403` `errors.auth.adminOnly`.

### Receipts and text recognition *(v3.1.0)*

Receipts are files belonging to a record: the photo of a meter reading, since
v3.1.0 also the consumption information for a period (`attachment_id`) and the
PDF or photo of a service charge statement (`attachment_ids`) and of a supplier
bill (`attachment_ids`, v3.1.0). The files live under
`data/attachments/<id>.<ext>`, the index in the pot `attachments.json`
([Data model](datenmodell.md#receipts-v310)). They are only served through the
API — the data directory is blocked for the web server.

**Upload — `POST /api/attachments?kind=reading_photo`.** The file comes as the
**raw body**, not as `multipart/form-data`. `kind` ∈ `reading_photo`,
`bill_pdf`, `statement_pdf`, `other` (an unknown value becomes `other`);
`&name=` keeps the original file name (`original_name`, at most 120
characters).

| Check | Rule | Error (`400`) |
|---|---|---|
| Content | JPEG, PNG, WebP or PDF — recognised by the first bytes, not by the extension or `Content-Type`. SVG, HTML and everything else is rejected | `errors.attachment.type` |
| Size | photo at most 3 MB, PDF at most 10 MB. If the upload is larger than PHP's `post_max_size`, the body arrives empty; then also `errors.attachment.size`, with that limit in the message | `errors.attachment.size` |
| Storage | all receipts together at most `attachments_max_mb` (default 500 MB) | `errors.attachment.quota` |

Response `201` with the index entry:

```json
{ "success": true, "data": {
  "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "mime": "image/jpeg",
  "size": 284113, "sha256": "9c1e…", "created_at": "2026-10-07T08:12:40+02:00",
  "ref": null } }
```

Through the API the server takes a photo as it comes. Scaling it down and
removing the EXIF data (GPS location included) is only done by the interface
in the browser before it uploads.

`ref` stays `null` until a record links the receipt — for a reading via
`attachment_id` ([see above](#readings-client_ref-attachment_id-v310-additive)),
`ref.type` then `reading`; for a period `period`, for a service charge statement
`tenancy_statement`, for a supplier bill `bill` (v3.1.0).
A receipt without a reference is orphaned: uploaded and never saved, or its
reading was deleted or the photo detached. After **24 hours** the app cleans it
up, on the next upload and when the snapshots rotate; until then it can be
linked again. Files in `data/attachments/` without an index entry also go after
24 hours.

**Fetch — `GET /api/attachments/{id}`.** Returns the file itself, no JSON
envelope: `Content-Type` from the index, `Content-Disposition: inline`,
`Cache-Control: private, max-age=31536000, immutable` (a receipt never changes —
new file, new ID), `X-Content-Type-Options: nosniff`, for PDFs also
`Content-Security-Policy: sandbox`: a PDF runs without scripts and without
access to the app. Unknown ID → `404`.

**List — `GET /api/attachments`.**

```json
{ "success": true, "data": {
  "attachments": [ { "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "mime": "image/jpeg",
      "size": 284113, "sha256": "9c1e…", "created_at": "2026-10-07T08:12:40+02:00",
      "ref": { "type": "reading", "utility": "strom", "id": "20261007-1a2b3c4d" } } ],
  "usage": { "count": 1, "bytes": 284113, "max_bytes": 524288000 } } }
```

**Delete — `DELETE /api/attachments/{id}`.** Removes the file and the index
entry right away and takes the reference on the record with it
(`attachment_id` on the reading); response `{deleted: true}`.

#### `POST /api/ocr/reading`

```json
{ "attachment_id": "att_5f0c2a9e81d34b67" }
```

Reads the meter value from a reading photo through **your own text recognition
service in the home network** — Ollama or an OpenAI-compatible server such as
LM Studio (settings `ocr_*`, [guide](../anleitungen/texterkennung.md)). The
request to the service is made by the server, never by the browser. Response
`200`:

```json
{ "success": true, "data": {
  "value": 12345.6, "confidence": 0.92,
  "raw": "{\"value\": 12345.6, \"confidence\": 0.92}",
  "model": "qwen2.5vl", "duration_ms": 23810 } }
```

| Field | Meaning |
|---|---|
| `value` | the recognised reading, or `null` if the model could not read anything. **Nothing is saved** — the interface offers the value to take over |
| `confidence` | the model's own estimate, 0–1, or `null` |
| `raw` | the model's answer, at most 500 characters — for troubleshooting |
| `model`, `duration_ms` | the model from `ocr_model` and the duration of the request |

The prompt is fixed and in English: only the digits of the register, decimal
places as on the roller register, answer as `{"value": …, "confidence": …}`.
The answer is read tolerantly — JSON also inside code fences or in the middle of
text, otherwise the first number; “12.345,6” and “12345,6” become `12345.6`.

**Interface (`ocr_api`).** `ollama`: `POST <ocr_endpoint>/api/chat`, the image
base64-encoded in `images[]`. `openai`: `POST <ocr_endpoint>/v1/chat/completions`
(if the address ends in `/v1`, only `/chat/completions` is appended), the image
as a data URI. An address that is already complete stays as it is.

**Home network only.** The server resolves the name in `ocr_endpoint`, and
**every** address must be local: loopback (`127.0.0.0/8`, `::1`),
`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10` (Tailscale,
CGNAT) and IPv6 ULA (`fc00::/7`). Link-local (`169.254.0.0/16`, `fe80::/10`)
no longer counts since v3.4.0 (on rented servers that is where the providers'
metadata services answer), nor does `fd00:ec2::254`. The response may be at
most 20 MB.
Checked on every call; the connection goes to exactly the checked address
(protection against DNS rebinding), redirects are not followed. There is no
switch to lift this — a cloud service is deliberately not provided for.

| Status | `code` | When |
|---|---|---|
| `400` | `errors.ocr.off` | `ocr_endpoint` is empty |
| `400` | `errors.ocr.notLocal` | an address of the service is not in your own network |
| `400` | `errors.attachment.notFound`, `errors.attachment.wrongKind` | the receipt is missing or is not a reading photo |
| `502` | `errors.ocr.timeout` | no answer within `ocr_timeout_s` |
| `502` | `errors.ocr.unreachable` | name does not resolve, no connection, or an HTTP error of the service (an unknown model, say) |
| `502` | `errors.ocr.badAnswer` | an answer without usable content |

### Inbox *(v3.7.0)*

File supplier mail as a PDF, have it read, check it and take it over
([guide](../anleitungen/posteingang.md), pot `inbox.json` in the
[data model](datenmodell.md#inbox-v370)).

**File — `POST /api/inbox?name=…`.** The PDF comes as a raw body like a
receipt (up to 10 MB, recognised by its first bytes; anything else →
`400 errors.inbox.pdfOnly`). It is stored in the receipt store (kind
`inbox_pdf`, reference `{type: "inbox", id}`). If the same file (same SHA-256)
is already in the inbox: `409 errors.inbox.duplicate`, the message names the
date and `detail.existing` is the existing entry. Answer `201` with the entry.
*(Package betrieb)* Without `name` the document is called “Document YYYY-MM-DD
HH.MM.pdf” (in the language of the installation). Instead of the raw body, JSON
`{"name": "…", "pdf_base64": "…"}` works too — that is how Home Assistant passes
on attachments from the IMAP integration; line breaks in the Base64 are allowed,
invalid Base64 → `400 errors.inbox.base64Invalid`. With sign-in switched on, an
API key with the scope **`inbox`** (“inbox only”) is enough: it may use exactly
this route and no other (`401`); a read key gets `403 errors.auth.readOnlyKey`.
Example:

```sh
curl -X POST -H "Authorization: Bearer etk_…" -H "Content-Type: application/pdf" \
  --data-binary @Bill.pdf "http://YOUR-ENERGIETRACKER-IP:8080/api.php/api/inbox?name=Bill.pdf"
```

**List — `GET /api/inbox`.** `entries` (newest first: `id`, `attachment_id`,
`name`, `status`, `type`, `flags`, `queued_at`, `created_at`, `applied`,
`error`, *(package betrieb)* `gist`, `meter_id`, `contract_id`, *(v3.7.0)*
 `duplicate_of` — the older document with the same content or
`null`), `folder` (`dir`, `present`, `pending` — the watched folder), `queue`
(`counts` per status, `queued`, `next`) and `provider`
(`id`, `label`, `local`, `available`, `model`, `destination`, `notice`, `pdf`,
`recommended_model`). `available` means the provider really sends — locally
with an address set up, in the cloud switched on and with a key. `destination`
(host) and `notice` (what leaves, in the language of the request) come from the
cloud connections; the interface shows both in the dialog “This document goes
to …”, for the local provider they are `null`.

**Preview — `GET /api/inbox/{id}`.** `entry` (without the cache),
`assignment` (saved or pre-filled: `utility`, `meter_id`, `contract_id`),
`assignment_saved`, `suggested` (`by`: `meter_number`, `market_location`,
`contract_number`, `utility`, `single_meter`, `date`), `proposals`, `schema`
(kind per field), `review_all`, `recognize_only`, *(package betrieb)* `gist`,
*(v3.7.0)* `duplicates` (`[{id, name, created_at, status}]`:
documents of the same type with the same period or effective date and the same
numbers and amounts — in another file; nothing is deleted).

`by: date` means *since v3.7.0*: the contract **of the same supplier** (name
without case, punctuation and legal form) on the reference date, for a bill the
one that bills most of its period. The preview never pre-fills another
supplier’s contract, and none without a supplier in the document.

**Key message — `gist`** (computed fresh in the preview; in the list the one
saved after the last write, field `gist` of the entry in `inbox.json`):

```json
{ "type": "price_change", "key": "inbox.gist.priceChange.more",
  "params": { "date": "2026-04-01", "provider": "Stadtwerke Musterstadt", "amount": 52.4 },
  "figure": { "kind": "money_year", "value": 52.4, "tone": "bad", "signed": true },
  "action": { "kind": "apply", "key": "inbox.gist.action.applyPrices" },
  "details": [{ "key": "inbox.gist.detail.termination", "params": { "date": "2026-03-31" } }],
  "computed_at": "2026-10-21T10:00:00+02:00" }
```

`figure.kind`: `money_year`, `money_month`, `money`, `result` (positive =
additional payment), `date`, `price_ct`; `tone`: `good`, `warn`, `bad`,
`neutral`. `action.kind`: `apply` (take over the ticked proposals), `link`
(`href` in the app), `remind` (create a reminder with `due`, `POST …/remind`),
`file` (file away). Texts are catalogue keys with raw values (dates ISO,
amounts as numbers). After taking over, a bill carries `check` (`verdict`,
`delta_eur`, `reasons`, `bill_id`) from “Check bill”; with `verdict: no_prices`
the sentence is `inbox.gist.invoice.noPrices`, the details name
the sections without a price, and the button leads to the contracts
(`inbox.gist.action.addPrices`). If the quantity differs and prices are
missing as well (`verdict: check`, `price_missing_at` not empty), it is
`inbox.gist.invoice.offQuantityNoPrices` with `pct` (deviation of the
quantity); the details name the sections without a price as well, and the
button opens “Check bill”. Open points of the proposals
appear as details `inbox.note.missing` or `inbox.note.unsure` with `fields`;
`duplicate_of` names a document with the same content (if nothing is left to
take over, `action` is “file”). Without type or reading `null`.

A field of the reading (`entry.extraction.fields.<name>`):

```json
{ "value": 1235.6, "raw": "1.235,6", "quote": "Zählerstand neu am 31.12.2025: 1.235,6 m³",
  "page": 1, "status": "ok", "reasons": [] }
```

`status` is `ok`, `check` or `empty`. Reasons in `reasons`: `empty` (empty, but
a proposal needs the field — *since v3.7.0* per proposal, not per document
type), `no_quote`, `quote_not_found` (after normalising white space,
hyphenation and case the quote is not in the text), `value_not_in_quote`,
`unreadable`, `estimated` (“voraussichtlich”), `mandatory` (meter swap, meter
reading request, advance payment change — *(v3.7.0)* not `document_date` and
`document_title` unless a proposal needs them), `price_from_invoice`, `sum_mismatch`
(total of the advance payments), `inconsistent` (result ≠ total − advance
payments; *(v3.7.0)* then every field involved is flagged: result, total and
the sum of the advance payments, without a sum each advance payment),
*(v3.7.0)* `invalid_format` (market location ID not in its form:
11 digits, the first 1–9, check digit as in the BDEW application guide
“Identifikatoren in der Marktkommunikation”, chapters 3 and 8.1; a meter number
with an asterisk in the value or the quote, such as an abbreviation “*EMH” in
front of it — what belongs to the number would stay open; matching against the
devices ignores an asterisk). List fields (`advances_paid`, `gas_factors`, `working_prices`) carry
`items`, each with its own quote; the server adds up the advance payments
(`sum`).

*(v3.7.0)* These reasons do not flag, they explain — the `status`
stays `ok` or `empty`:

| Reason | Meaning |
|---|---|
| `missing` | not in the document, no proposal needs it (`status: empty`) |
| `estimated` | “voraussichtlich” (expected): taken over as a hint, the proposal stays ticked (a flag up to v3.6) |
| `supported` | a choice field without a valid quote that other details back: meter number, market location or contract number at the assigned meter or contract, units and keywords of the other quotes, for the bill type the new advance, for the result amount against advances |
| `price_matches` | price from the bill = price line of the assigned contract on the same day (to rounding); the proposal is `exists` — a net price counts gross |
| `net_price` | the price is printed net and its VAT rate is read: converted to gross (the app keeps gross prices); the entry carries `net: {vat_pct, gross}`, the proposal the gross price |
| `vat_changed` | same net price as in the contract, only a different VAT rate: no flag, a new price line (gross) from the day the bill shows the price for |

*(v3.7.0)* **Net and VAT rate.** A price on the bill counts as net when the
rate stands in the quote (read, never guessed — not by utility or date
either; only from a quote that stands in the document — for a quote with “…”
only from the parts that stand there) and net is stated: by the model (`basis: net` on the unit price,
`base_price_basis` on the standing charge) or in the quote (“netto”,
“zzgl.”), without “brutto”/“inkl.” next to it. If the model names a rate
(`vat_pct`, `base_price_vat_pct`), exactly that rate must stand in the quote.
If the quote carries no rate, a rate the server reads in the text layer while
reading counts — on the entry or field as `vat_read: {vat_pct, from}`:
`from: row` when the model names net/gross **and** a rate and exactly that rate
with “%” is the only one in the text line that holds the whole quote (one line
below does not count); `from: column` when the row carries exactly one number without “%” that is a
usual rate (0, 5, 7, 16 or 19 — § 12 (1)–(3) and § 28 (1), (2) and (5) UStG),
not the price itself, and the header of a tax column stands above it on the
same page (“USt %”, “MwSt.-Satz in %”); `from: line` when the bill shows
exactly one rate in a line of its own (“Umsatzsteuer 19 % auf …”, “zzgl. 19 %
MwSt.”). If two rates stand there (say 7 % and 19 %), neither counts — which
price line carries which would be a guess. Otherwise the price stays as printed
and the flag as before. “Different VAT
rate” needs the rate of the contract price: from a document on the contract
(contract confirmation or price change from the inbox, rate in the quote of
the price or in the field `vat_pct`) or from the same bill. Balance and
forecast keep calculating with the contract’s gross prices. Helper fields
(`basis`, `vat_pct`, `base_price_basis`, `base_price_vat_pct`, `vat_pct` of
the confirmation) never flag. A reading carries `schema_version` **5**; the
cache depends on this version. A document read with an older version keeps its
reading until you read it again: the app recognises net there from the quote,
only reading again asks the model for the new fields. Reading again without
`fresh` with the same version hits the cache: the model is not asked, the check
runs again with the text layer sent along.

*(v3.7.0, `schema_version` 3)* **Date, title, readings per meter.** Every type
that is read carries `document_date` (date of the letter or bill) and
`document_title` (heading, such as “Rechnungskorrektur”, also from the page
header) — checked like every field (the date stands in the quote), without a
flag when missing (no proposal needs them). The bill also carries
`meter_readings` (`items` per meter: `meter_number`, `reading_old`,
`reading_old_date`, `reading_new`, `reading_new_date`, each entry with its own
quote); the single fields `meter_number`, `reading_old` … stay and are still
filled. `meter_readings` yields readings at the assigned meter (by meter
number, without duplicating the single fields). If exactly one entry with a
foreign number stands next to exactly one of the meter, the app proposes a meter
swap (`meter_change`, `label_key` `inbox.proposal.meterSwapInvoice`, final
reading of the old and initial reading of the new meter, swap day = date of the
initial reading). It is ticked in advance only when everything fits: the
meter’s entry carries the number of the device that ran until then; removal on
the same day or the day before; the readings rise; no known reading of the old
device lies above the final reading, none from the swap day on; nothing
flagged. The readings on the swap day are carried by the swap, not by a
reading. The same number twice with two readings on the same day (two
registers) or a foreign meter without the old one’s final reading yield no
proposal.

*(v3.7.0, `schema_version` 4)* **Periods per meter, number in the header
line.** The server merges entries with the same number (one per reading
period) into one: earliest initial reading, latest final reading, the entry
keeps its form. The readings in between stand on the entry as `between:
[{date, counter, estimated}]`, together with `merged` (number of periods) and
`estimated_ends` (how the initial and final reading were taken); every reading
in between becomes a reading like the final one. If a reading falls with the
date, the entry is flagged (`inconsistent`). If the number is not in the quote
but in the header line directly above the reading lines, it counts as backed
(`number_from: header`); the header line of another meter backs nothing. The
readings of the new meter are ticked in advance only when the swap is —
otherwise they would land on the old device. If the bill shows several
numbers, a reading of the single fields is ticked in advance only when an
entry that may be ticked carries it at this device.

*(v3.7.0, `schema_version` 3)* **Estimated readings.** Every reading on the bill
carries how it was taken: `reading_new_estimated`, `reading_old_estimated`
(bool, helper fields without a flag) and `estimated` per entry of
`meter_readings` (applies to both readings of the entry). A reading counts as
estimated only when its quote says so — with a word (“geschätzt”, “Schätzung”,
“rechnerisch ermittelt”, *(version 4)* “hochgerechnet”) or a reading code the document itself explains as an
estimate (“S = geschätzt”, “R: rechnerisch ermittelt”); a code without that
explanation does not count, nor does the explanation alone. *(Version 4)* The
same goes for a footnote mark (“5)”, “*”) the document explains as an estimate
(“5) hochgerechnet”), a code in a code group (“10/12/26” with “12 =
geschätzt”) and a number below a column “Ableseart” or “Ablesegrund” when the
document explains it; what stands in the line right after the quote counts
too. `estimated_by` (`word`/`code`/`mark`) tells why. The reading proposal then carries
`is_estimated: true` in `data` (`label_key` `inbox.proposal.readingEstimated`)
and creates the reading as estimated — the monthly marker does not count it as
a measurement.

*(v3.7.0)* **Quote from several places.** A quote with “…” (or “...”) counts
as found when its parts stand in this order on the same page, each in the same
text line as the previous one or at most 2 text lines below the previous one —
label and value stand in the same line or in the line below, also in a wide
table row (counted in lines, not characters); the value must be in one part.
Without “…” a quote may split into two parts (column header and row) if both
stand on the same page, the first before the second and at most 2 text lines
above it, each at least four characters long, and the value is in the second,
not the first. Umlauts as two characters in the text layer (“Ã¤” or “√§”
instead of “ä”, letter + diaeresis) and Mac Roman bytes read as Windows-1252
(“Š” for “ä”, “š” for “ö”, “Ÿ” for “ü”) are evened out by the comparison. The server checks quotes when reading, against the text layer,
which it does not keep — an older reading benefits only when read again.

The server determines the flags from today’s records: preview, list (`flags`)
and review list recalculate on every call, also for documents read by an older
version. They are stored when reading, changing and taking over — a
`PATCH /api/inbox/{id}` with an empty body writes them to `inbox.json`. `flags`
counts what is still to check (without what was confirmed).

A proposal:

```json
{ "id": "p_3f2a9c1e0b7d", "kind": "reading", "label_key": "inbox.proposal.reading",
  "params": { "date": "2025-12-31", "counter": 1235.6, "meter": "Main gas meter" },
  "data": { "meter_id": "m_gas_main", "date": "2025-12-31", "counter": 1235.6 },
  "sources": ["reading_new", "reading_new_date"], "status": "new", "detail": null,
  "check": false, "checked": true }
```

`kind`: `reading`, `bill`, `bill_book` (result as a special payment),
`contract_create`, `contract_end`, `contract_start`, `contract_price`,
`contract_base_price`, `contract_advance`, `contract_ids`, `meter_ids`
(market location), `meter_change`, `gas_factor` (effective date of the gas
conversion), `reminder`. `status`: `new`, `exists` (skipped), `conflict`
(overwrites only when ticked), `overlap`. `checked` is the default: new and not
flagged. The `id` depends on kind and content.

*(v3.7.0, additive)* `core` — the fields whose doubt flags the
proposal (if absent, `sources` apply); `reasons` — `[{field, reasons}]`, why
`check` holds; `hints` — `[{field, reason}]` without a flag (`estimated`);
`open` — `[{field, reason}]` with `missing` (not in the document) or `unsure`
(flagged and therefore **not** taken over, e.g. the advance of a contract
confirmation: the contract is created without it). New as an offer (not
ticked): `contract_create` with `label_key`
`inbox.proposal.contractFromInvoice` (a bill with period and supplier but no
contract of that supplier; a final bill ends with its period) and
`inbox.proposal.contractFromSupplyStart` (supply start without a contract).
Without a contract of that supplier `bill_book` is only an offer (`open` names
`contract`); taken over it books only to a contract of the supplier that bills
the period, otherwise `errors.bill.noContract`. The new advance of a bill only
goes to a follow-up contract of the same supplier.

*(v3.9.0, `schema_version` 5)* **Contract from the bill.** If no contract at all
applies at the meter during the period, `contract_create`
(`inbox.proposal.contractFromInvoice`) is prefilled and carries the prices per
section (gross), for water all three components, and the new advance payment
plan; `data.for_bill: true` means the bill of this run belongs to it (`bill` and
`bill_book` then carry `data.contract_new: true`). `params.lines` breaks it
down: `{section, kind: working|base|rate|advance, from, value, parts: [{label,
price, unit, net, vat_pct, gross}], area?, every?, dates?}`. If a contract
applies in the period, it stays an offer; if one of the same supplier ends
earlier, a follow-up or preceding contract is offered (`label_key`
`inbox.proposal.contractSpanFromInvoice` when it ends). `bill_book` at the new
contract carries `requires` (the `id` of the contract proposal) and
`checked_with` (its prefill once the contract is ticked); without the contract
in the selection, taking over refuses it (`failed`, `code`
`errors.inboxDraft.needs.contract`). New kind `contract_water_price`
(`inbox.proposal.waterWorking`, `waterBase`, `waterRate`): a price row of the
bill at an existing water contract, `data.list` like
`trinkwasser.working_prices`, `params.section`, never prefilled; “already
there” when the contract has it on that day.

New fields of the bill: `water_prices` (list: `section`
`trinkwasser`/`schmutzwasser`/`niederschlagswasser`, `label`, `from`, `to`,
`price`, `unit` `m3`/`year`/`month`/`m2_year`, `area_m2`, `vat_pct`, `basis`;
the component also counts from the heading above the line, `section_from:
heading`; a component other than the heading's is flagged), `advance_new_dates`
(list of `date`) and `advance_new_every` (`monthly`/`bimonthly`/`quarterly`);
`working_prices` carries `part` (`energy`/`energy_tax`/`co2`/`other`): rows of
the same day with exactly one `energy` give one working price. The new advance
payment (`contract_advance`, in the plan of `contract_create`) carries
`every_months`/`due_dates` in `data.row`, `params.every` and `params.dates`; an
advance payment change keeps the rhythm of the current row. Digits with spaces
(“3 1 5,5 9 €”) are read as one number when every digit stands alone. A reading
code applies to the reading right before it: `estimated_ends` per entry of
`meter_readings` is `[old, new]` and says which reading is estimated
(`estimated` still means: at least one); only that one is created as estimated.

**Change — `PATCH /api/inbox/{id}`** with `assignment`, `edits`
(`{field: value}` confirmed or corrected; `null` takes it back), `type`
(another type: the reading is discarded), `status` (`filed` file away, `new`
back) or `note`. Answer like the preview.

**Read — `POST /api/inbox/{id}/classify`, then `…/extract`** with
`{pages: [text per page], consent?, fresh?}`. The browser reads the text layer
(pdf.js), *(v3.9.0)* with the position of every text piece: pieces of one line
(same baseline, within one line of the document) stand from left to right, even
if the PDF draws a right-aligned number character by character from the right,
and a space stands only where the document has a gap. Lines the document writes
from the left stay as before. `classify` recognises the type, `extract` reads the fields of that
type and checks them; without a type `extract` does both. Cached per provider,
model and schema version (`fresh: true` asks again). The write lock is taken
only after the service answers. A provider other than `local` requires
`consent: true`, otherwise `409 errors.inbox.consentRequired` with the name of
the service.

| Status | `code` | When |
|---|---|---|
| `400` | `errors.inbox.off` | no service set up (`ocr_endpoint` empty) |
| `400` | `errors.ocr.notLocal` | an address of the service is not in the home network |
| `400` | `errors.inbox.noText` | no text layer (a scan) and a provider that takes text only |
| `400` | `errors.inbox.pagesInvalid`, `errors.inbox.providerUnknown` | unreadable text layer, provider not registered |
| `409` | `errors.inbox.consentRequired` | provider outside the home network without consent |
| `502` | `errors.inbox.truncated` *(v3.7.0)* | the answer broke off at the context window (`done_reason` or `finish_reason`: `length`), also on the second attempt with a larger window and double the answer budget; names budget and window |
| `502` | `errors.ocr.timeout`, `errors.ocr.unreachable`, `errors.inbox.badAnswer`, `errors.inbox.modelMissing` | the service does not answer, gives no usable answer, or the model is missing |

When a cloud service reads, its messages come on top
([below](#get-apiinboxcloud--patch-apiinboxcloud-v370), “When reading”).

**Take over — `POST /api/inbox/{id}/apply`** with `{proposals: [id, …]}`. Only
those, through the same services as manual entry. An unknown ID →
`400 errors.inbox.proposalStale` (nothing happens). Answer: `applied`,
`skipped` (already there), `failed` (error per proposal), `entry`, `proposals`.

**Queue — `POST /api/inbox/queue`** (`{ids?}`; without, all new and failed
documents) and **`DELETE /api/inbox/queue`** (queued ones become new again).
The open page works through them one document at a time.

**Check list — `GET /api/inbox/review`.** Only the flagged fields of all
documents that have been read and not yet taken over: `entry_id`, `name`,
`type`, `field`, `item`, `value`, `quote`, `page`, `reasons`.

#### Household from documents *(v3.7.0)*

One draft across all documents that have been read and not yet taken over
([guide](../anleitungen/haushalt-aus-dokumenten.md)). It works only with the
stored readings (`extraction`, plus `edits`) and asks no service. Whoever may
take over a single document may write.

**Draft — `GET /api/inbox/draft`** (`?documents=inb_…,inb_…` takes only these).
Answer: `summary` (`documents`, `used`, `duplicates`, `without`, `meters`,
`meter_changes`, `contracts`, `prices`, `advances`, `readings`, `bills`,
`reminders`, `questions`), `utilities` (order of the sections), `items`,
`documents` (per document `state`: `used`, `duplicate` with `duplicate_of` —
the same older document as `entries[].duplicate_of` in `GET /api/inbox`, even
if it has already been taken over —, `no_utility`, `no_meter`, `no_start`,
`meter_choice` *(3.7.1)* — meter open, see `missing` —, `nothing`; plus
`items`), `findings` (`start_near` *(3.7.1)* — recorded contract with a start
up to 7 days off, `{provider, from, date}`: start according to the document
and on record —, `gap`, `overlap`, `falling`,
`chain_mismatch`, `duplicate`, `no_meter`, `same_supplier` — bills in another
spelling at the meter and in the time of a contract with a shared name —,
`invoice_wp`/`invoice_bp` — a bill price differs from the confirmation or price
change, `{name, date, invoice, chain}` —, `start_from_invoice` — start moved
forward to the first bill of the same contract —, `bill_corrected` — corrected
bill, the later version applies — with `params` and `docs`), `missing`
(`advance`, `prices`, `working_price`, `base_price`, `prices_unconfirmed` —
prices only on bills, still to be confirmed; each with `which`: `wp`, `bp`,
what is missing after taking over what is pre-selected —,
`working_price_gap`/`base_price_gap` — a period without a price, `params`
`{provider, from, to}` —, `meter_choice` *(3.7.1)* — a number or market
location without a recorded meter, and several meters with data are possible,
the one already has another measuring point or fits only half; `params`
`{meter, count, meters}`, `docs`, `href` to the first document (choose the
meter there; a saved assignment applies in the next draft) —, `water_prices`, `contract_start`, `old_final` (meter
swap without the closing reading of the old meter: it is only inferred from a
reading at most 7 days before), `open` — the open points of the proposals,
`open: [{field, reason: missing|unsure}]`, one line per contract or supply
relationship with its documents in `docs`, unless the draft has them from
another document — with `href`), `targets` and `queue` (as in
`GET /api/inbox`).

An entry is a group of fields with one tick:

```json
{ "id": "d_5c1e0a9b3f27", "kind": "contract", "utility": "strom", "status": "new",
  "check": false, "checked": true, "actionable": true, "optional": false,
  "label_key": "inboxDraft.item.contract.new",
  "params": { "provider": "Nordlicht Energie GmbH", "from": "2025-04-01", "to": null },
  "fields": [
    { "name": "start", "kind": "date", "value": "2025-04-01", "core": true, "basis": "supply_start",
      "check": false, "reasons": [], "status": "new",
      "sources": [{ "doc": "inb_…", "name": "lieferbeginn.pdf", "field": "supply_start", "quote": "…", "page": 1 }] },
    { "name": "tariff_name", "kind": "text", "value": "Basis 12", "check": true, "reasons": ["differs"],
      "options": [{ "value": "Basis 12", "sources": [] }, { "value": "Basis 24", "sources": [] }] }
  ],
  "docs": ["inb_…"] }
```

`kind` in the order of the take-over: `meter` (`action`: `create`, `adopt` —
the empty default meter —, `existing`; *(3.7.1)* also the one active meter
with data without number and market location if nothing contradicts; the
field `serial` then carries `device_id`, the device that ran at the time of the
number), `meter_change`, `contract`,
`contract_end` (previous contract, only as an offer), `prices` (fields
`wp:<date>`, `bp:<date>` per month), `advances` (`ap:<date>`), `readings`
(`r:<date>`), `bill` (with booking of the result), `gas_factor` (`gf:<date>`),
`reminder`. `status` of the entry: `new`, `update` (existing, gets added to),
`exists`, `conflict`, `overlap`; `update` and `overlap` are *(3.7.1)* always
`check` with the reason `existing_update` or `existing_overlap` and never
`checked` (pre-selected is only what does not touch the recorded data); a
contract at an existing meter counts as recorded with the same start or — same
supplier or same contract number — with a start up to 7 days off
(`start_near`, the recorded start stays); contracts of the meter's group count
too. *(3.9.0)* The final start is compared, also after moving it forward to the
first bill (`invoice_earlier`) and for a predecessor contract made from bills.
Confirmations with the same contract number give the earliest start, whatever
order they were filed in. Within the same supply relationship, a start up to
7 days off at the same meter is the same contract (for example the brand's
confirmation and the supply start letter of the billing municipal utility);
different contract numbers stay two. Same start with another supplier name,
and the recorded contract carries no other contract number: the recorded
contract as a question “the same supplier?” (`label_key`
`inboxDraft.item.contract.sameSupplier`, `params.existing` = the recorded
name, reason `same_supplier`, `detail` `{provider, start, end, document}`, field
`provider` with `status: conflict`, `existing` and `check: false`; taking over
never writes the recorded name). `requires` *(3.9.0)*: on `prices`, `advances`
and `bill` the `id` of the contract entry without which the entry is not taken
over (the contract does not exist yet, or its supplier is a question),
otherwise `null`; such an entry is only `checked` when its contract is, and
`checked_with` is its default once the contract is ticked. Meter readings
belong to the meter, not to the contract. Per field `new`, `exists`, `conflict` (with
`existing`; a bill price with `price_matches` is `exists`). `check` on a field:
doubtful (flagged quote — the marks by today’s data as in the list and the
preview, `Proposals::marks` — and no second document that confirms it; bill
prices do not confirm each other), reasons
from the quote check plus `differs`, `conflict_existing`, `chain_mismatch`,
`inferred`, `falling_reading`, `assumed_date`, `swap_open` (reading after a
swap without a closing reading), `swap_readings` (a swap from the readings
does not quite fit: another running device, a reading above the closing
reading, a falling new meter or a reading from the swap day on in the existing
data — then not pre-selected, as in the single document). `hint` `newest`:
different identifiers, the one from the most recent document is pre-selected,
the others in `options`. Read where the extraction has them: `document_date`,
`document_title` (corrected bill) and `meter_readings` (readings per meter of
a bill; the same number with consecutive periods → readings of that meter,
with overlapping ones → `missing` `two_registers`). No meter reading
is created on the swap day (the swap carries the readings). A reading field
marked as estimated (`is_estimated`, according to the bill:
`reading_*_estimated`, `meter_readings[].estimated`) creates an estimated
meter reading. `basis` of the start:
`confirmation`, `supply_start`, `invoice`, `invoice_earlier` (moved forward to
the first bill), `existing`; of the end `document`, `successor`; on a price
row `vat_changed` (same net price, different VAT rate according to the bill).
Bill prices count gross (net with a VAT rate that was read, converted as in the
inbox, also when confirmed or corrected — entered in the same basis as
printed); a price row only from net prices carries `net: {vat_pct}`. `core`:
core field — if one is doubtful, the entry is `check` and not pre-selected.

**Take over — `POST /api/inbox/draft/apply`** with
`{items: [id, …], confirm: {id: [field, …]}, choose: {id: {field: option}}, documents?}`.
A selected entry writes its fields that are new and not doubtful, plus the core
fields (ticking confirms them), the ones named in `confirm` and the option
picked in `choose`. An unknown ID, an unknown field or an option outside the
list → `400 errors.inbox.proposalStale` before anything is written; nothing
selected → `400 errors.inboxDraft.nothingSelected`; *(3.9.0)* an entry without
the contract entry named in its `requires` → `400 errors.inboxDraft.needs.contract`,
also before anything is written. If a bill's contract is missing while taking
over (it failed), the bill is listed in `failed` with this code and is not
created; up to 3.8 it was created without a contract and booked its result on
the contract that ran at the time. First the server makes a
backup `pre-inbox-…` (Settings → Data, reason “before household from
documents”). Answer: `applied` (per entry `ref`), `failed` (per entry or field
with `error`, `code`), `created` (`meters`, `meter_changes`, `contracts`,
`rows`, `readings`, `bills`, `booked`, `gas_factors`, `reminders`), `bills`
(for “Check the bill”: `utility`, `id`, `meter_id`, `period_from`,
`period_to`, `booked`), `missing`, `documents` (`done`, `filed`, `open`) and
`snapshot`. Documents the draft covers completely are `done` afterwards (what
was taken over in `applied`, assignment set, marks and key message `gist`
renewed as after `POST /api/inbox/{id}/apply`), probable duplicates `filed`
when their original is `done`; the list keeps working out `duplicate_of` from
the content, it is not stored.

**Delete — `DELETE /api/inbox/{id}`.** The entry; the PDF only while it hangs
at the inbox alone.

**Reminder — `POST /api/inbox/{id}/remind`** with `{date: "YYYY-MM-DD"}`
*(package betrieb)*: a reminder for the document (payment reminder: “sort out
…”) at the assigned meter, with the person responsible for the contract; the
document is filed afterwards. Answer like the preview. Without a valid date
`400 errors.reminder.dueRequired`.

#### Folder — `POST /api/inbox/folder`

*(v3.7.0, package betrieb)* Reads `<data directory>/posteingang/` (admins only,
otherwise `403 errors.auth.adminOnly`; a missing folder is created). Only files
directly in the folder, no hidden ones, no symbolic links; PDF by content, up
to 10 MB; at most 20 per call, a file younger than 10 seconds waits. Afterwards
it is in `posteingang/übernommen/` (a duplicate too), a refused one in
`posteingang/fehler/`. Answer:

```json
{ "dir": "posteingang", "imported": [{ "name": "Bill.pdf", "id": "inb_…", "moved": "übernommen/Bill.pdf" }],
  "duplicates": [], "failed": [{ "name": "photo.pdf", "reason": "type", "moved": "fehler/photo.pdf" }],
  "skipped": [{ "name": "link.pdf", "reason": "symlink" }], "waiting": 0, "more": false }
```

`reason`: `type` (not a PDF), `size`, `rejected` (with `code`), `quota` (the
receipt store is full — the file stays), for `skipped` `symlink`,
`not_a_file`, `outside`, `unreadable`. `moved` is `null` when moving failed.

#### File — `GET /api/utility/{u}/dossier`

*(v3.7.0, package betrieb)* `?meter_id=` or `?contract_id=` (otherwise `400
errors.dossier.scope`; unknown → `404`). `items`, newest first:

| `kind` | What | Fields |
|---|---|---|
| `inbox` | a done or filed inbox document assigned to the meter or contract | `type` (document type), `inbox_id`, `status`, `gist`, `bill_id` (when it became a bill) |
| `bill` | supplier bill | `type` (`annual`, `final`, `interim`), `bill_id`, `period_from`, `period_to`, `result_eur`, `amount_eur`, `attachment_ids` |
| `receipt` | receipt on the contract | `contract_id`, `provider` |
| `photo` | photo of a reading (meter only) | `counter` |

Every item has `date` (the document’s effective date, otherwise when it
arrived), `title` and `attachment_id` (or `null`). A PDF appears only once.

#### `GET /api/inbox/cloud` · `PATCH /api/inbox/cloud` *(v3.7.0)*

Reading in the cloud for the inbox: an opt-in, off until someone who manages
the installation switches it on
([guide](../anleitungen/posteingang.md#reading-in-the-cloud)). Text recognition
stays in the home network; this is a separate path. `GET` returns the
state, **never the key**:

```json
{ "success": true, "data": {
  "enabled": false, "provider": "anthropic", "endpoint": "", "model": "",
  "timeout_s": 120, "has_key": false, "keys": { "anthropic": false, "openai": false }, "ready": false,
  "active": false,
  "preset": "anthropic", "experimental": true,
  "presets": [
    { "id": "anthropic", "provider": "anthropic", "endpoint": null, "label": "Claude (Anthropic)", "host": "api.anthropic.com", "has_key": false },
    { "id": "mistral", "provider": "openai", "endpoint": "https://api.mistral.ai/v1", "label": "Mistral", "host": "api.mistral.ai", "has_key": false },
    { "id": "custom", "provider": "openai", "endpoint": null, "label": null, "host": "", "has_key": false } ],
  "providers": [
    { "id": "anthropic", "label": "Claude (Anthropic)", "destination": "api.anthropic.com",
      "notice": "The document goes as a PDF to …", "sends": "pdf", "default_model": "claude-opus-5-5" },
    { "id": "openai", "label": "OpenAI-compatible service", "destination": "",
      "notice": "The document’s text goes to …", "sends": "text", "default_model": "" } ] } }
```

(`presets` shortened; in full: `anthropic`, `openai`, `mistral`, `openrouter`,
`gemini`, `custom`.) `notice` is the note the inbox shows before every reading
(in the language of the request), `destination` the host the document goes to,
`sends` what leaves (`pdf` or `text`). `ready` means the service could send —
switched on **and** with a key. After restoring a backup on another server
`enabled` is true, `ready` is not. `active` means ready **and** chosen with the
switch of the “Reading documents” card (`inbox_provider`) — then something
really leaves. *(“Reading documents” card, additive)* `preset` is the preset that
matches provider and address (otherwise `custom`), `presets[].has_key` whether a
key is stored for that destination — on the OpenAI-compatible path it belongs to
the host. `experimental`: no cloud path has been checked against real-world
feedback yet; `GET /api/inbox` reports the same (`provider.experimental`).

`PATCH` takes the same fields (`enabled`, `provider`, `endpoint`, `model`,
`timeout_s`), all optional, plus `api_key` (new key; empty = keep) and
`clear_key` (`true` = the provider's key, otherwise the id of a provider; the
selection stays). *(“Reading documents” card, additive)* `preset` sets provider
and address from the preset; `select: true` lets the service read as soon as it
is ready, `select: false` leaves `inbox_provider` as it is. Response as for
`GET`. Only a complete connection is switched on. If that makes it ready, the
server without `select` sets `inbox_provider` to the provider, and back to
`local` when switched off; whoever chose “Local” while the cloud was ready keeps
that. Via `PATCH /api/settings`, `inbox_provider` only accepts a
cloud service while it is ready, otherwise `400` (text of
`errors.inboxCloud.off`). Admins only, otherwise `403` `errors.auth.adminOnly` —
the same for the `inbox_*` keys via `PATCH /api/settings`.

| Status | `code` | When |
|---|---|---|
| `400` | `errors.inboxCloud.noKey` | switching on without a key |
| `400` | `errors.inboxCloud.noEndpoint`, `errors.inboxCloud.noModel` | OpenAI-compatible without an address or model |
| `400` | `errors.inboxCloud.httpsOnly` | address not `https://` or with credentials |
| `400` | `errors.inboxCloud.notPublic` | address or name in the home network (`192.168.…`, `nas.local`, `fritz.box`, a name without a dot) |
| `400` | `errors.inboxCloud.keyFormat` | key not 8–500 printable characters without spaces |

**When reading** (through the inbox) the connections report: `400`
`errors.inboxCloud.off` or `noKey` (not switched on), `notPublic` (a resolved
address is not public), `notPdf`, `emptyDocument`; `413` `tooLarge` or
`tooLargeLimits`; `422` `refusal` (the model declines); `429` `rateLimited` with
`Retry-After`; `502` `unauthorized` (the service rejects the key — deliberately
not `401`, which in the interface means “sign in again”), `redirect`,
`unreachable`, `badAnswer`, `truncated`, `rejected`; `503` `overloaded` with
`Retry-After`; `504` `timeout`. What each provider sees and how the connection
is checked: [Security](../betrieb/sicherheit.md#reading-in-the-cloud-v370).

#### `GET /api/inbox/cloud/models` · `GET /api/inbox/local/check` *(v3.7.0, “Reading documents” card)*

Both admins only (otherwise `403` `errors.auth.adminOnly`), both without a
document.

**`GET /api/inbox/cloud/models`** — the models of the cloud service that is set
up (stored provider, address and key; the key stays on the server).
OpenAI-compatible `GET <base>/models` with `Authorization: Bearer`, Claude
`GET https://api.anthropic.com/v1/models?limit=1000` with `x-api-key` and
`anthropic-version`. The same rules as for reading (HTTPS only, never the home
network, the checked address, no redirect) and the same errors (`noKey`,
`noEndpoint`, `notPublic`, `unauthorized`, `redirect` …). Only ids that may be
stored as `inbox_cloud_model`, at most 1000, in the service’s order:

```json
{ "success": true, "data": { "provider": "openai", "preset": "mistral",
  "models": [ { "id": "test-model-a", "label": "" }, { "id": "vendor/test-model-b", "label": "Test Model B" } ] } }
```

**`GET /api/inbox/local/check?endpoint=…&api=ollama|openai`** — “Check
connection” for your own service (without parameters the stored
`ocr_endpoint`/`ocr_api`): Ollama `GET /api/tags`, OpenAI-compatible
`GET /v1/models`, home network only like text recognition. Response
`{ host, api, models: ["qwen3.8:latest", …], model, model_found }` — `model` is
`inbox_model`, `model_found` says whether it is among them (also with
`:latest`). Errors as for text recognition: `400` `errors.ocr.off`,
`errors.ocr.notLocal`, `errors.ocr.unreachable`, `errors.ocr.timeout`,
`errors.ocr.badAnswer`.

### `POST /api/ingest` *(F1009, v1.9.0 — Home Assistant)*

An idempotent push intake for external data suppliers. **Upsert per (meter,
date):** a renewed push on the same day updates the value instead of creating a
second reading.

```jsonc
// Header (only if a token is set): Authorization: Bearer <token>
{
  "utility": "strom",
  "meter":   "stromzaehler_haus",  // external_id alias OR internal meter ID
  "value":   12345.6,              // alias: "counter"
  "date":    "2026-06-02"          // optional, default today; an ISO stamp is truncated
}
```

Response `201` (new) resp. `200` (updated) with
`{ status: "created"|"updated", utility, meter_id, date, counter, reading_id,
suspect }` — `suspect` since v2.6.0: if the value is lower than the previous
reading of the same device, it is stored but marked as suspect
(`suspect: true`, plus `previous: {date, counter}`) and only counts after
confirmation in the interface. A rollover with maintained `digits` is not
suspect. A push is therefore never rejected where it was
accepted before.
Errors: `401` (token needed/wrong; since v2.6.0 with sign-in switched on also
without a token set: `errors.ingest.tokenRequiredWithLogin`), `400` (unknown
utility/meter, no numeric value, no valid calendar date, delivery utility
heating oil/pellets; since v3.1.0 a meter with consumption per period:
`errors.ingest.periodMeter` — it accepts no readings).

If the date lies before the installation of the **first** device, that device is
backdated since v2.5.3 instead of rejecting the reading (typical when back-filling
older readings after a new installation). A date in a gap between two devices
remains a `400`.

#### Timestamps and quarter hours *(v3.3.0)*

Optional `timestamp` — the moment the reading applied to, as ISO 8601 **with a
time zone**: `YYYY-MM-DDTHH:MM[:SS[.ffffff]]` followed by `Z`, `±HH:MM` or
`±HHMM`, just as Home Assistant writes `now().isoformat()`. Anything else →
`400` `errors.ingest.timestampFormat`. Without `date` the day of the timestamp
in the installation’s time zone applies. The day’s reading stays as it was: one
reading per meter and day, the last push wins.

```json
{ "utility": "strom", "meter": "stromzaehler_haus", "value": 12345.6,
  "timestamp": "2026-10-12T14:37:00+02:00" }
```

Two readings with a timestamp give consumption per quarter hour: the difference
is spread over the quarter hours in between in proportion to time.

```text
reading 12,345.0 at 14:07, reading 12,345.6 at 14:37  →  0.6 kWh in 30 minutes
14:00–14:15   8 minutes  → 0.16 kWh   added to the share before 14:07
14:15–14:30  15 minutes  → 0.30 kWh
14:30–14:45   7 minutes  → 0.14 kWh   the next reading brings the rest
```

Nothing is spread for the first reading with a timestamp, for a moment equal to
or before the previous one (the previous one stays the reference), for a
decrease and for a gap of more than six hours — in the last two cases the new
reading is the new start. A suspect reading (`suspect: true`) does not count at all. With
`timestamp` the response also carries `intervals`, the number of quarter hours
touched (`0` if nothing was spread); for a suspect reading the field is
missing.

```json
{ "status": "updated", "utility": "strom", "meter_id": "m_strom_main",
  "date": "2026-10-12", "counter": 12345.6, "reading_id": "20261012-1a2b3c4d",
  "suspect": false, "intervals": 3 }
```

For a profile per quarter hour the reading must arrive at least every 15
minutes — in Home Assistant an automation with `time_pattern` and
`minutes: "/15"`. Since v3.3.0 the template’s REST command sends `timestamp`
along; its automation, however, sends once a day (23:55), and nothing is spread
over more than six hours ([Home Assistant](../anleitungen/home-assistant.md)).
Readings up to six hours apart are spread evenly over their gap.

#### Batch *(v3.1.0)*

Several readings in one request — for backfilling after a Home Assistant
outage or from Node-RED, ioBroker and openHAB
([Other systems](../anleitungen/andere-systeme.md)). The body is a **list** of
single objects or an object `{"readings": [...]}`; token as for the single push.

```json
{ "readings": [
  { "utility": "strom", "meter": "stromzaehler_haus", "value": 48190.2, "date": "2026-10-05" },
  { "utility": "strom", "meter": "stromzaehler_haus", "value": 48201.7, "date": "2026-10-06" },
  { "utility": "gas",   "meter": "gaszaehler_garten", "value": 9876.5,  "date": "2026-10-06" }
] }
```

- At most **500** entries, otherwise `400` with `code` `errors.ingest.tooMany`.
  An empty list under `readings` or anything other than a list → `400`
  `errors.ingest.bodyInvalid`. In both cases nothing is stored.
- Every entry is handled like a single push: same checks, upsert per meter and
  day, same response fields. A faulty entry does not stop the others.
- Processing runs by utility, meter and date, since v3.3.0 on the same day by
  `timestamp` (for the order, an entry without `date` counts with the date at
  the start of its timestamp). That way the suspect check sees earlier readings of the same
  batch, and the quarter hours arise in the right order. Every data file is
  written once.
- Response **`200` even with partial errors**, results in input order:

```json
{ "success": true, "data": {
  "results": [
    { "index": 0, "status": "created", "utility": "strom", "meter_id": "m_strom_main",
      "date": "2026-10-05", "counter": 48190.2, "reading_id": "20261005-1a2b3c4d", "suspect": false },
    { "index": 1, "status": "created", "utility": "strom", "meter_id": "m_strom_main",
      "date": "2026-10-06", "counter": 48201.7, "reading_id": "20261006-5e6f7a8b", "suspect": false },
    { "index": 2, "status": "error", "code": "errors.ingest.meterNotFound",
      "error": "gas: no meter found for “gaszaehler_garten” (neither as alias nor as ID)" }
  ],
  "created": 2, "updated": 0, "failed": 1 } }
```

`status` is `created`, `updated` or `error`; an error carries `code` (stable)
and `error` (text in the language of the request) — such as
`errors.ingest.periodMeter` for an entry to a meter with consumption per period
(v3.1.0). **Whoever backfills checks
`failed`** — the HTTP status alone only says that the batch arrived.

Home Assistant: `rest_command` with `response_variable` (the response is then
under `content`):

```yaml
rest_command:
  energietracker_batch:
    url: "http://192.168.178.10:8080/api.php/api/ingest"
    method: POST
    headers:
      Authorization: !secret energietracker_auth
    content_type: "application/json"
    payload: "{{ readings | tojson }}"

# in an automation or a script:
actions:
  - action: rest_command.energietracker_batch
    data:
      readings: "{{ batch }}"         # list of {utility, meter, value, date}
    response_variable: answer
  - if:
      - condition: template
        value_template: "{{ answer.status != 200 or answer.content.data.failed > 0 }}"
    then:
      - action: persistent_notification.create
        data:
          title: "Energietracker"
          message: >-
            {% if answer.status != 200 %}HTTP {{ answer.status }}: {{ answer.content.code | default('') }}
            {% else %}Rejected: {{ answer.content.data.results | selectattr('status', 'eq', 'error')
            | map(attribute='code') | join(', ') }}{% endif %}
```

Node-RED: a function node after the `http request` node (return “a parsed JSON
object”):

```js
const d = msg.payload && msg.payload.data;
if (msg.statusCode !== 200 || !d) {
  node.error(`Energietracker: HTTP ${msg.statusCode} ${(msg.payload && msg.payload.code) || ''}`, msg);
  return null;
}
if (d.failed > 0) {
  const bad = d.results.filter(r => r.status === 'error').map(r => `#${r.index} ${r.code}`);
  node.warn(`Energietracker: ${d.failed} rejected – ${bad.join(', ')}`);
}
return msg;
```

A single object in the body behaves as before (`201`/`200`).

> **Template for Home Assistant:** `| float` **without** a default and check
> `has_value(…)` before the push — see [`docs/HOME-ASSISTANT.md`](../anleitungen/home-assistant.md).
> `float(0)` from templates up to v2.5.2 recorded a meter reading of 0 when the
> sensor was unavailable.

### `GET|POST|DELETE /api/auth/token` *(F1009)*

Management of the **opt-in** token for the push endpoint. It protects
`/api/ingest` **only**: without a token the ingest accepts values without a
header; as soon as a token exists, it requires `Authorization: Bearer <token>`.
The rest of the API is protected by sign-in (see above), not by this token. The
token is stored only as a SHA-256 hash in `data/auth.json` and returned in
plaintext **once** on creation. Since v2.6.0 `GET` also reports `last_used_at`
(accurate to the hour) — useful for the question "is anything arriving at all?".

### `/api/session`, `/api/session/password`, `/api/auth/keys` *(v2.6.0)*

```jsonc
// GET /api/session
{ "mode": "off", "authenticated": true, "mode_fixed": false,
  "password_fixed": false, "has_password": false,
  "named_login": false, "user": null, "role": "admin" }   // the last three since v3.2.0

// POST /api/session/password   (switch on: password only; change: + current)
{ "password": "at-least-8-characters", "current": "previous" }
// → { "mode": "password" } + session cookie; a new password ends all other sessions

// POST /api/auth/keys
{ "name": "backup script", "scope": "read" }
// → 201 { "id": "k_…", "key": "etk_…", "hint": "…" }   plaintext only here
```

- `mode`: `off` · `password` · `proxy`. `mode_fixed`/`password_fixed`: fixed by
  `ET_AUTH` or `ET_ADMIN_PASSWORD_HASH` — the changing routes then answer `409`.
- Failed attempts: after five within 15 minutes sign-in is locked for
  5 minutes (`429`). This also applies to `current` when changing or switching
  off. Since v3.4.0 per device (client address, behind a proxy from
  `ET_TRUSTED_PROXIES` the one from `X-Forwarded-For`); ten failures for one
  name lock that name, a hundred in total lock everyone. An unknown name takes
  as long as a known one.
- *(v3.4.0)* `POST /api/session/logout-everywhere` — no body; raises the
  session epoch of the signed-in person, all their sessions end (including the
  one of this request, the cookie is deleted). Response
  `{authenticated: false, logged_out_everywhere: true}`; without a signed-in
  person (open operation, API key) `400` `errors.users.noUser`.
- *(v3.4.0)* Sliding session: a valid session older than one day gets a new
  cookie with the full duration (30 days) on the next request. A cookie nobody
  uses expires 30 days after its last use.
- `GET /api/auth/keys` returns `id`, `name`, `scope`, `created_at`,
  `last_used_at` — never the key or its hash.
- *(v3.2.0)* `/api/session/password`, `/api/auth/keys` and `/api/auth/token`
  are reached by admins only. `POST /api/session/password` sets the
  installation password (that of the first admin `u_admin`); `current` is the
  password of the signed-in person. Every person changes their own password via
  `POST /api/session/me/password`.

### People in the household *(v3.2.0)*

With sign-in switched on, every person in the household can have an account of
their own: their own sign-in, their own experience level and language. Everyone
shares the household’s data. People are stored in `data/auth.json`, not in the
backup, without a schema step
([Data model](datenmodell.md#people-in-authjson-v320)); since v3.6.0 also in
the [register of people](#register-of-people-v360), which is part of the
backup. Guide:
[Users in the household](../anleitungen/benutzer.md).

| Role | `role` | may |
|---|---|---|
| Admin | `admin` | everything |
| Member | `member` | record and see everything; change their own level, language and password |

**Admins only** — a member gets `403` `errors.auth.adminOnly`:

- `/api/users…`, `/api/auth/keys…`, `/api/auth/token` and
  `/api/session/password` — every method, `GET` included;
- `POST /api/backup/import`, `POST /api/backup/snapshots/{name}/restore` and
  `POST /api/demo/import` — all three replace the data;
- *(v3.4.0)* `DELETE /api/backup/snapshots/{name}` — among them are the
  safety copies before an import, the way back;
- `PATCH /api/settings` with `frame_ancestors`, `ocr_endpoint`,
  `evcc_endpoint` or (since v3.4.0) `allowed_hosts` in the body, when the
  value changes — settings that make the app talk to other addresses, let it
  be embedded or block names; since v3.4.0 also `activity_keep_months`, which
  shortens the history. Without these keys, members may change settings too;
- *(v3.4.0)* `DELETE /api/activity` — clearing the history
  ([Done with history](#done-with-history-v340)).

The role applies to signed-in people. Without sign-in and with an API key there
is no person; a request may then do what it could before (a `read` key still
only reads).

**Transition from v3.1.** Up to v3.1 there was one password. It now belongs to
the first admin: identifier `u_admin`, name `admin`. As long as nobody adds a
person, it only appears in the list (`GET /api/users`); with the first change
it is written into `auth.json`. It still signs in **without a name**, with the
installation password or `ET_ADMIN_PASSWORD_HASH`. Sessions from v3.1 stay
valid for it. If `u_admin` is deleted, the installation password goes, and with
it sign-in without a name.

**Proxy.** In mode `proxy` the app recognises the person by the reported name
([Sign-in](#sign-in-v260-opt-in)) and creates them on the first visit
(`source: "proxy"`, name cut to 40 characters): as an admin while there is no
admin from the proxy, otherwise as a member. They have no password in the app.

**Person.** Every response with a person has these fields, never a password
hash:

```json
{ "id": "u_3f9a1c2e", "name": "Alex", "role": "member", "source": "password",
  "prefs": { "ui_level": "beginner", "language": "en" },
  "created_at": "2026-10-09T18:20:11+02:00" }
```

| Field | Meaning |
|---|---|
| `id` | `u_` and eight hex digits; the first admin `u_admin` |
| `name` | 1–40 characters without control characters, repeated white space becomes one; unique regardless of upper and lower case |
| `role` | `admin` or `member` |
| `source` | `password` (sign-in in the app) or `proxy` (from the upstream service) |
| `prefs` | own settings, only those set: `ui_level` (`beginner`, `advanced`, `expert`) and `language`. Without an own level the installation’s applies, without an own language that of the device or the installation |
| `created_at` | created (ISO 8601) |
| `no` | *(v3.6.0, only `GET`/`POST /api/users`)* running number from the [register of people](#register-of-people-v360) |

**`GET /api/session`** additionally reports since v3.2.0:

```jsonc
{ "mode": "password", "authenticated": true, "mode_fixed": false,
  "password_fixed": false, "has_password": true,
  "named_login": true,
  "user": { "id": "u_3f9a1c2e", "name": "Alex", "role": "member", "source": "password",
            "prefs": { "ui_level": "beginner" }, "created_at": "…" },
  "role": "member" }
```

- `named_login`: mode `password`, and there is at least one added person with a
  password other than `u_admin` — sign-in then asks for the name.
- `user`: the person of this session; `null` without sign-in, with an API key
  and when not signed in.
- `role`: `admin` or `member`; `null` when not signed in, `admin` without a
  person.

**`POST /api/session`** — `{name?, password}`. Without a name (empty or
missing) the first admin with the installation password applies. The name
counts regardless of upper and lower case and only for people with
`source: "password"`. An unknown name and a wrong password answer the same:
`401` `errors.auth.wrongPassword`. The lock after five failures (`429`) applies
to the whole installation, not per person. Outside mode `password` → `400`
`errors.auth.notPasswordMode`.

| Route | Body | Response | Errors |
|---|---|---|---|
| `PATCH /api/session/me` | `{ui_level?, language?}`; `null` or `""` removes your own value | your own person | `400` `errors.users.noUser` (no person signed in: without sign-in or with an API key); `400` `errors.settings.valueInvalid` (unknown level or language) |
| `POST /api/session/me/password` | `{current, password}` | `{changed: true}` and a new session cookie | `400` `errors.users.noUser`; `400` `errors.users.proxyNoPassword` (person from the proxy); `401` `errors.auth.wrongPassword` or `429` `errors.auth.locked` (previous password); `400` `errors.auth.passwordTooShort` (fewer than 8 characters); `409` `errors.auth.passwordFixed` (`u_admin` with `ET_ADMIN_PASSWORD_HASH`) |
| `GET /api/users` | — | list of people, including `u_admin` while it exists | — |
| `POST /api/users` | `{name, password, role?}`; `role` default `member` | `201` with the person (`source: "password"`) | `400` `errors.users.nameInvalid`, `errors.users.roleInvalid`, `errors.auth.passwordTooShort`; `409` `errors.users.nameTaken` |
| `PATCH /api/users/{id}` | `{name?, role?, password?}`, each field on its own; the password without the previous one (“Reset password”) | the changed person | `404` `errors.users.notFound`; `400` as when adding; `409` `errors.users.nameTaken`, `errors.users.lastAdmin` (the last admin would become a member), `errors.auth.passwordFixed` |
| `GET /api/users/{id}/handover` | — *(v3.6.0)* | `{person, to, open, done}` ([Register of people](#register-of-people-v360)); *(v3.7.0)* plus `meters`, `contracts` | `404` `errors.users.notFound` |
| `DELETE /api/users/{id}` | — ; *(v3.4.0)* `?anonymize_activity=1` replaces the name in the history with “former member” | `{deleted: true, activity_anonymized}`; *(v3.6.0)* plus `handed_over`, `handed_over_to`, `kept`; *(v3.7.0)* `records_handed_over`, `records_kept` | `400` `errors.users.notSelf` (your own person); `404` `errors.users.notFound`; `409` `errors.users.lastAdmin`, `errors.auth.passwordFixed` (`u_admin` with `ET_ADMIN_PASSWORD_HASH`) |

A deleted person is signed out at once: their session cookie no longer counts.
A new password — changed by the person or reset by an admin — signs that person
out on every device; whoever changes their own gets a new cookie for the
current device right away. The other people stay signed in.

### Snapshots and import *(v2.6.0)*

`GET /api/backup/snapshots` lists `data/backups/` (newest first):

```json
[ { "name": "pre-restore-2026-09-25_000438.json", "size": 215512,
    "created_at": "2026-09-25T00:04:38+02:00", "reason": "restore" } ]
```

`reason` ∈ `manual` (own snapshot), `restore` (before import/restore),
`migration`, `demo`, `v09`. **Retention:** of your own the last ten; automatic
ones 30 days, keeping at least the three newest per occasion.

Since v2.6.0 `POST /api/backup/import` **checks everything first**: every pot
must be a list of objects with mandatory fields and valid dates. A faulty
backup changes nothing and answers `400` with `detail.problems` (at most 50):

```json
{ "success": false, "code": "errors.backup.invalid",
  "error": "The backup is incomplete or damaged (problems: 3). Nothing was restored.",
  "detail": { "problems": [ { "pot": "gas/meters", "index": 0, "problem": "missing:devices" } ] } }
```

`problem` ∈ `not_an_object`, `not_a_list`, `unknown_utility`,
`missing:<field>`, `date:<field>`, `counter`, `devices`, `entry:<date>`; since
v3.1.0 for receipts also `id` and `mime` (index entry in the pot
`attachments`) as well as `unknown:<id>`, `sha256:<id>` and `mime:<id>` (file
in the pot `attachment_files`, see below). With `?dry_run=1` the import stops
after the check and returns the report (counts per pot, `untouched` = pots not
contained in the backup and therefore unchanged). Further changes: the envelope
of `GET /api/backup/export` (`{success, data}`) is unwrapped; if a file fails
mid-write, the files already written are rolled back;
`recommendations_dismissed` is part of the backup since v2.6.0.

**Receipts in the backup *(v3.1.0)*.** The format stays `3.0`. New are the pot
`attachments` (the index) and the key `attachment_files` with the content of
every file, base64-encoded: `{"att_…": "<base64>"}`. Older versions skip the
unknown key.

- `GET /api/backup/export?attachments=0` leaves out the files, the index
  stays. Meant for a quick backup of the figures: whoever restores such a
  backup has references to photos without a file (`404` when fetching).
- Export and snapshot are streamed file by file; the export keeps the envelope
  `{success, data}`.
- The import checks every file **before the first write**: it needs an index
  entry (`unknown:<id>`), its SHA-256 checksum must match (`sha256:<id>`) and
  its content must fit the type in the index (`mime:<id>`). The receipts are
  written first, then the index; an existing file with the same ID stays
  untouched. The report counts the files under `attachment_files`.
- Snapshots contain the receipts too — so `data/backups/` grows with every
  photo, once per snapshot. A backup with receipts is larger than the files
  themselves (base64: about a third more); upload limits for restoring:
  [Web server](../betrieb/webserver.md).

**Charging sessions in the backup *(v3.2.0)*.** New pot `ev_sessions` (the
format stays `3.0`); every entry needs `id`, `meter_id`, `date` and
`charged_kwh`, and `date` must be a calendar date. An older backup without the
pot leaves the stored charging sessions unchanged (`untouched`). People and
passwords (`auth.json`) are still not part of the backup.

**History in the backup *(v3.4.0)*.** New pot `activity` (the format stays
`3.0`); every entry needs `id`, `action` (`done`, `auto_done`, `dismissed`),
`subject` with `kind` and `occurrence`, and `done_on` as a calendar date. The
names of people are in it as a snapshot. An older backup without the pot
leaves the history unchanged (`untouched`).

**Quarter-hour series in the backup *(v3.3.0)*.** The format stays `3.0`. New
are the pot `intervals` (the index `intervals.json` including `samples`) and the
key `interval_files` with every series of the index:
`{"<id>": {id, kind, year, step, start, values}}`. It is always in the backup,
also with `?attachments=0` and in every snapshot — a series is data, not a
receipt, and takes up to about 250 KB per year as a file.

- On restore every series needs a valid ID, an entry in the backup’s index and
  a list `values`; otherwise the check reports `invalid:<id>` (pot
  `interval_files`, not an object: `not_an_object`), and nothing is restored.
  The report counts the series under `interval_files`.
- If the backup brings the pot `intervals`, series without an entry in the new
  index are deleted afterwards — after switching households no series of the
  previous one lie around. Without the pot the existing series stay unchanged
  (`untouched`).

### Example households *(v3.2.0)*

Next to the previous demo household with every utility (now the “showcase”,
`showcase`) there are four example households, one for each persona of the
setup assistant ([Setup](../einstieg/einrichtung.md)). Class C.

`GET /api/demo/status`:

```json
{ "available": true, "is_empty": true,
  "personas": ["mieterin", "etw-fernwaerme", "eigenheim-klassisch", "eigenheim-modern", "showcase"] }
```

- `available`: the showcase is included.
- `is_empty`: nothing recorded yet — no reading, delivery, period or contract
  in any utility. Since v3.2.0 the default meters of a new installation no
  longer count as data.
- `personas`: the example households whose file is present; `showcase` is
  always in it.

`POST /api/demo/import` — body `{force?, persona?}`, admins only:

| `persona` | Household |
|---|---|
| `mieterin` | rented flat: electricity, heat from the monthly consumption information, hot and cold water, tenancy with service charge statements |
| `etw-fernwaerme` | owner-occupied flat with district heating (own supply contract) and electricity |
| `eigenheim-klassisch` | detached house with gas, electricity and water including a garden meter |
| `eigenheim-modern` | heat pump, PV with battery, wall box with charging sessions from evcc |
| `showcase` or empty | the showcase with every utility |

- An unknown persona → `400` `errors.demo.personaUnknown`; if there is data
  already and `force: true` is missing → `400` `errors.demo.dataExists`.
- An example household replaces the **whole** household: every pot of every
  utility, also those it does not use. The app takes a snapshot of the current
  state first. The experience level (`ui_level`) stays; `setup_pending` becomes
  `false`, `setup_persona` the loaded persona.
- Response: the report of the backup import, plus `demo_import: true` and
  `persona`.

### Docs in the app: `GET /api/docs` *(v3.4.0)*

Help reads the documentation from your own server — the same Markdown files as
on GitHub, as of this release — and renders it itself
(`public/js/lib/markdown.js`, view `#/help/doc/<path>`).

`GET /api/docs?path=anleitungen/kalender.md`

```json
{ "path": "anleitungen/kalender.md", "lang": "de", "mirror": "en/anleitungen/kalender.md",
  "root_files": ["CHANGELOG.md", "CONTRIBUTING.de.md", "CONTRIBUTING.md", "CREDITS.md",
                 "LICENSE", "SECURITY.md", "TRADEMARKS.md"],
  "markdown": "# Kalender …" }
```

- `path` is a key of the **allowlist**: pages under `docs/` (English under
  `en/`) without the redirects of old paths, plus a fixed list of files in the
  root directory as `root/<name>` — `CHANGELOG.md` (and `CHANGELOG.en.md` once
  it exists), `CONTRIBUTING.md`, `CONTRIBUTING.de.md`, `SECURITY.md`,
  `CREDITS.md`, `TRADEMARKS.md`, `LICENSE`.
- `lang` is `de`, `en` or `mul` (both languages in one file), `mirror` the same
  page in the other language or `null`. `root_files` names the served files of
  the root directory: links from the docs to them stay in the app, every other
  file of the repository (such as `roadmap.md` or the templates under
  `deploy/`) opens GitHub at the tag of this version.
- Everything else — `..`, `%2e%2e`, absolute paths, `docs/…`, unknown pages —
  returns `404` `errors.docs.notFound`. The path is never joined with a
  directory, symbolic links are not on the list. A redirect (old path since
  v2.14.0) names its target in `detail.moved_to`; the app follows it.
- `GET /api/docs/asset?path=ui/screenshots/dashboard.png&v=<version>` serves
  only PNG under `docs/ui/screenshots/`, with `Content-Type: image/png` and
  `X-Content-Type-Options: nosniff`. With the version of this release in `v`,
  `Cache-Control: private, max-age=31536000, immutable` applies, otherwise
  `private, no-cache`.
- Sign-in as for every other route — the docs are not public. The service
  worker keeps pages you have read and their images for offline use.
- Directly under `/docs/…` the docs stay blocked (Apache, nginx,
  `router.php`). Since v3.4.0 they are in the Docker image, without
  `docs/assets/promo/`.

### `GET|HEAD /api/health` *(N1003; extended in v2.6.0)*

```json
{ "status": "ok", "version": "2.10.0", "schema_version": "1.6.0",
  "data_dir_writable": true, "migrations_pending": 0,
  "data_initialized_at": "2026-09-24T23:58:03+02:00",
  "php_version": "8.4.12", "timezone": "Europe/Berlin",
  "last_ingest": { "m_strom_main": "2026-09-20" },
  "checks": {
    "data_dir_writable": { "ok": true, "level": "ok" },
    "schema":            { "ok": true, "level": "ok" },
    "files":             { "ok": true, "level": "ok", "corrupt": [] },
    "disk":              { "ok": true, "level": "ok", "free_mb": 736175 },
    "temp_files":        { "ok": true, "level": "ok", "removed": 0 } } }
```

`status` ∈ `ok` · `degraded` (pending migration, less than 50 MB free) ·
`error` (not writable, corrupt file, data newer than the app, less than 5 MB
free) — with HTTP `503` on `error`, so Docker HEALTHCHECK and monitors detect
the fault. Since v2.6.0 `migrations_pending` counts the pending migration steps
(up to v2.5.3 only `0` or `1`; `0` still means "nothing to do"). With sign-in
switched on and the caller not signed in, only `{status, version}` is returned.

### Parameter limits *(v2.6.0)*

Previously accepted silently, now `400` with `code` and — for the forecast —
`detail {param, value, range}`:

| Endpoint | Parameter | allowed |
|---|---|---|
| `…/forecast` | `forecast_months` | 1–60 |
| | `temp_offset` | −30 to +30 °C |
| | `price_factor` | 0–10 |
| | `model` | `linear`, `polynomial`, `robust`, `segmented`, `sigmoid` |
| | `co2_scenario_eur_t` | 0–1000 €/t, empty = off (v3.1.0) |
| | `co2_scenario_from` | 2021–2100 (v3.1.0) |
| `/api/co2-costs`, `/api/co2-split`, `/api/reports/co2-split.pdf` | `year` | 2021–2100, default previous year, otherwise `errors.co2.yearInvalid` (v3.1.0) |
| `…/bill-check` | `from`, `to` | ISO date, `from` < `to`, otherwise `errors.billCheck.invalidRange` |
| `/api/reports/yearly.pdf` | `year` | 2000–2100 |
| | `inline` | `1` = show instead of download (v2.11.0) |
| `/api/reports/yearly` | `year` | 2000–2100 (v3.1.0) |
| `/api/reports/yearly…` | `utilities` | comma-separated utilities, otherwise `errors.common.unknownUtility` (v3.4.0) |
| | `lang` | language code; unknown = language of the request (v3.4.0) |
| | `detail` | `1` = with appendix (v3.4.0) |
| `/api/reports/ev-charging…` | `year` | 2017–2100, default previous year, otherwise `errors.evReport.yearInvalid` (v3.1.0) |
| | `method` | `contract`, `flat`, otherwise `errors.evReport.methodInvalid` |
| | `flat_ct` | 0–200 ct/kWh, otherwise `errors.evReport.flatInvalid` |
| `/api/ev-sessions` | `year` | 2017–2100 or empty (all years), otherwise `errors.evReport.yearInvalid` (v3.2.0) |
| `…/import-evcc`, `…/sync-evcc` | `counters` | `auto` (default), `energy`, `none`, otherwise `errors.evcc.countersInvalid` (v3.2.0) |
| `/api/heat-pump` | `year` | 1990–2100, default previous year, otherwise `errors.heatPump.yearInvalid` (v3.1.0) |
| `/api/flexibility`, `…/sync-smard-intervals` | `year` | 2018–2100, default previous year, otherwise `errors.flexibility.yearInvalid` (v3.3.0) |
| `…/sync-smard-intervals` | `month` | optional 1–12: only this month (the interface loads month by month with progress), otherwise `errors.flexibility.monthInvalid` (v3.5.1) |
| `POST /api/ingest` | `timestamp` | ISO 8601 with a time zone or empty, otherwise `errors.ingest.timestampFormat` (v3.3.0) |
| `/api/energy-flow/day` | `date` | calendar day YYYY-MM-DD, year 2000–2100, required, otherwise `errors.energyFlow.dateInvalid` (v3.5.0) |
| `/api/energy-flow/typical-day` | `month` | YYYY-MM, year 2000–2100, required, otherwise `errors.energyFlow.monthInvalid` (v3.5.0) |
| `/api/benchmarks/comparison` | `year` | a year, default previous year (v3.1.0) |
| `/api/export/…` | `format` | `1` (default) or `local`, otherwise `errors.export.formatInvalid` (v3.1.0) |
| | `lang` | language code for `format=local`; unknown = default language |
| `POST /api/temperatures` | `avg`, `min`, `max` | numbers, mandatory |
| `…/sync-open-meteo` | `start`, `end` | ISO date, `start` ≤ `end` |

> Full examples for auth + ingest and the step-by-step setup in Home Assistant:
> [`docs/HOME-ASSISTANT.md`](../anleitungen/home-assistant.md) and [`docs/API.md`](api-beispiele.md).

### Meter groups *(F1006, v1.8.0)*

`GET/POST /api/utility/{u}/meter-groups`, `PATCH/DELETE …/{groupId}` as well as
`POST …/meter-groups/merge` ("Group meters"). Membership is set via `meter_group_id`
on the meter, not in the group. Since v3.1.0 a group can carry a contract, and
the evaluations exist for the whole group (`…/meter-groups/{id}/consumption`
etc., [Group contract](#group-contract-v310)); it can then only be deleted once
no contract is attached to it. Submeters are linked via `parent_meter_id` on the
meter (see [data model](datenmodell.md) and
[meter topology](../verstehen/13-meter-topologie.md)).

---

[← Architecture](../entwicklung/architektur.md) ·
[Data model →](datenmodell.md)
