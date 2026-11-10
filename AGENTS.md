# AGENTS.md

Notes for contributors who work with an AI coding agent, and for the agent
itself. They sum up [CONTRIBUTING.md](CONTRIBUTING.md); where the two differ,
CONTRIBUTING wins. You stay responsible for the change: say in the pull
request that an agent helped and what you checked yourself.

## The project in brief

- **Backend:** PHP 8.3 or newer, no framework, no Composer packages at runtime
  (PHPUnit is the only dev dependency). Data lives as JSON files in `data/`.
- **Frontend:** vanilla ES modules in `public/js/`, no build step, no
  `package.json`. Seven interface languages in `public/locales/*.json`.
- **Layout:** `src/Services/` (domain logic), `src/Controllers/`, `src/Http/`,
  `src/Storage/`, `src/Config/` (single sources of truth), `docs/` (German) and
  `docs/en/` (English mirror), `tests/unit/` (PHPUnit), `tests/*.test.mjs`
  (Node with jsdom), `docker/` (image).
- Architecture and data model:
  [docs/en/entwicklung/architektur.md](docs/en/entwicklung/architektur.md).

## Run the tests locally

```bash
composer install                                   # PHPUnit only
vendor/bin/phpunit --no-coverage                   # service layer

npm install --no-save jsdom                        # for the browser tests
cp -R demo-data /tmp/etdata
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8899 router.php &
node tests/frontend-api-shape.test.js
```

The full list of Node suites, with the exact commands, is in
`.github/workflows/ci.yml` (job `test`); the CI runs all of them on every pull
request. Details: [docs/en/entwicklung/tests.md](docs/en/entwicklung/tests.md).
If you change the example households, `node tools/build-personas.mjs --check`
must pass too.

Every change brings its test. Do the **counter-check**: turn the new safeguard
in the code back for a moment, and the test must fail. Tests never depend on
today's date: use fixed dates and pass them on (`$today`, `atDate()`,
`['today' => …]`). A service that receives a reference date passes it down to
every calculation below it; a reference date means "the state on that day".
Moving a test's data forward is not a fix. To check, run the suite with the
clock moved forward (libfaketime,
`faketime -f "+<seconds>" php vendor/phpunit/phpunit/phpunit`).

## Rules that matter most

- **No user data.** The repository is public. Never real meter readings,
  amounts, supplier or tariff names, meter, metering point or contract numbers,
  addresses or logs from a real installation, in code, tests, fixtures, commit
  messages or pull requests. Use invented values; example addresses only
  `192.168.178.x` or `nas.local`.
- **No third-party packages.** No Composer runtime dependencies, no npm
  packages, no CDN. A new library is an architecture question: open an issue
  first.
- **One source of truth.** Utilities come from `src/Config/Utilities.php`,
  defaults from `SettingsService::DEFAULTS`. No hard-coded lists like
  `['gas', 'strom']`. A new setting also goes into
  `docs/referenz/einstellungen.md` and its English mirror.
- **Interfaces change only additively.** API fields, CSV formats and the backup
  format stay compatible. New stored fields also go into the field list of
  `BackupService`, with a round-trip test.
- **Texts for people come from the catalogues**, in all seven languages
  (`LocaleCatalogTest`, `CatalogStyleTest`). Never branch on the wording of a
  message; use a field or a code.
- **Secrets** (keys, tokens) never reach the browser and never sit in a backup
  as plain text.
- **Docs in German and English.** German is authoritative; the English page
  lives under `docs/en/` at the same path. `DocsIntegrityTest` checks links,
  anchors and mirrors. A claim in the docs needs a test that holds it.
- **Naming.** Symbols in English, code comments in German, like the existing
  code. One topic per pull request, no unrelated refactoring.

## Reporting what an automated test or an agent found

Use the issue form **“Report a bug”** and choose **“With an automated test or
agent”** under *Found*; in triage the issue gets the label
`found-by-automation`.
Give the exact version, the steps or the test that shows the error, and what
you expected according to the docs. Leave out personal data. Security issues
never go into an issue: see [SECURITY.md](SECURITY.md). Ideas go to
[Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas).
