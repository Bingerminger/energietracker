# Contributing

**English** · [Deutsch](CONTRIBUTING.de.md)

Thanks for joining in! What helps most:

- **Bug reports** with the details from Settings → System → System diagnostics
  and the steps that lead to the error.
- **Translations** — a new language is mostly a catalogue, plus a few lines of
  configuration (see below).
- **Documentation**: mistakes, gaps, a question missing from the
  [FAQ](docs/en/einstieg/faq.md).
- **Code** — before larger changes to the data model or the API please open an
  issue first, so that migration and the stability promise are thought through.

Please do **not** report security issues as an issue, but as described in
[SECURITY.md](SECURITY.md). The [code of conduct](CODE_OF_CONDUCT.md) applies to
everyone: kind, on topic, no real data.

Working with an AI coding agent? [AGENTS.md](AGENTS.md) sums up what it needs
to know: running the tests, conventions, no user data, no third-party packages.

## Ideas, plans and status

**From a wish to a release**, the same path for every report:

1. **Report.** A bug goes into an issue (form “Report a bug”), a question under
   [Discussions → Q&A](https://github.com/Bingerminger/energietracker/discussions/categories/q-a), an idea under
   [Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas), where everyone
   can vote with 👍. Please never report a vulnerability in public, see [SECURITY.md](SECURITY.md).
2. **Assess.** New bugs carry `status: triage`. We reproduce them and close duplicates with a link to the issue that
   stays. If something is missing, the issue carries `status: needs info`.
3. **Accepted.** What we want to build is an issue on the
   [project board](https://github.com/users/Bingerminger/projects/1) under “Accepted”. An idea from Discussions only
   becomes an issue at this point, with a link back. A 👍 on the issue tells us what should come first.
4. **Planned and in progress.** When a release is planned, the issue gets a
   [milestone](https://github.com/Bingerminger/energietracker/milestones) and hangs under the release’s tracking issue.
   The board shows “Planned” and “In progress”, the [roadmap](roadmap.en.md) sums it up.
5. **Released.** With the release we close the issue and name the version. If it still does not work for you, please
   reopen it.

What we will not build is closed as “not planned”, with a reason. What cannot be fixed right away is listed as a
known limitation in the [changelog](CHANGELOG.en.md).

**Where the status lives:** only in the board’s “Status” field. Labels only say what an issue is (`bug`,
`enhancement`, `documentation`), which area it touches (`area: …`) and whether we are waiting for an answer
(`status: triage`, `status: needs info`). `found-by-automation` marks reports from an automated test or an agent.

## Setting up

PHP 8.4 or newer is enough to run it (the CI checks 8.4 and 8.5), without
Composer, without a build step:

```bash
git clone https://github.com/Bingerminger/energietracker.git
cd energietracker
cp -R demo-data /tmp/etdata                        # a copy of the sample data
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8080 router.php
```

Always with `router.php` — without it the PHP server also serves `data/`.

## Testing

```bash
composer install                                   # only for PHPUnit (dev)
vendor/bin/phpunit --no-coverage                   # service layer
composer test:parallel                             # the same, one process per core

npm install --no-save jsdom                        # for the browser tests
cp -R demo-data /tmp/etdata
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8899 router.php &
node tests/frontend-api-shape.test.js              # API formats
node --import='data:text/javascript,import{register}from"node:module";import{pathToFileURL}from"node:url";register("./tests/esm-loader.mjs",pathToFileURL("./"));' \
  tests/browser-render.test.mjs                    # views in JSDOM (port 8899 is fixed)
```

Without a server, `tests/format.test.mjs`, `tests/router.test.mjs`,
`tests/contrast.test.mjs`, `tests/chart.test.mjs`, `tests/plausibility.test.mjs`,
`tests/ha-snippet.test.mjs`, `tests/plural.test.mjs` and
`tests/hardcoded-text.test.mjs` run. CI runs everything on every pull request.
While developing, run only the tests your change touches; before a pull request,
the whole suite. Details: [Tests](docs/en/entwicklung/tests.md).

A change brings its test. The **counter-check** has proven itself: briefly turn
the new safeguard in the code back — the test must then fail.

## Principles

- **No runtime dependencies.** PHP without Composer in operation, the frontend
  as vanilla ES modules without a build. A new library is an architecture
  question — please raise it in an issue first.
- **One source of truth.** Properties of the utilities live in
  `src/Config/Utilities.php` (the interface reads them through
  `/api/utilities`), defaults in `SettingsService::DEFAULTS`. No lists like
  `['gas', 'strom']` in the code.
- **Stability promise.** API fields, CSV formats and the backup format (3.0)
  change only additively; anything is removed only with a new major version and
  after notice. Home Assistant and scripts depend on it.
- **Language.** Symbols (classes, methods, fields) in English; comments, docs
  and the CHANGELOG in German. Pull requests and issues are welcome in English or
  German.
- **No user data in the repository** — not in tests or examples either: no real
  meter readings, amounts, providers, contract or metering point numbers.

## Documentation

Every visible change updates the docs — German is the authoritative version,
the English mirror lives under `docs/en/` at the same relative path. A test
checks links, anchors and that every page has its mirror. New pages belong in
the index (`docs/README.md`, `docs/en/README.md`). The CHANGELOG follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) with the sections
Added, Changed, Deprecated, Fixed, Migration and Tests.

## Adding a language

The complete checklist, the style guide (form of address, terms, typography,
plurals) and the tests behind it are in
[Translating and languages](docs/en/entwicklung/uebersetzen.md). In short:

1. Copy `public/locales/en.json` to `public/locales/<code>.json` (ISO 639-1,
   e.g. `pl`) and translate it. Placeholders like `{count}` stay; plural forms
   are written as `one`/`other` (further categories such as `few`/`many` are
   allowed).
2. Register it in `public/locales/languages.json`: `"pl": "Polski"`.
3. Set the notation in the catalogue under `format.*` — decimal and thousands
   separator, date pattern, month abbreviations, amount, `typography` and
   `pdfCharset`.
4. Make the language known to the code: plural rule in
   `I18nService::PLURAL_RULES` (with cases in
   `tests/fixtures/plural-cases.json`), country for the first start in
   `Countries::BY_LANGUAGE`, letters of the bill check, termbase terms and the
   translation of the demo data.
5. `vendor/bin/phpunit --no-coverage --filter 'LocaleCatalogTest|CatalogStyleTest|PluralRulesTest'`
   checks keys, placeholders, style and completeness.

In the browser `Intl` formats numbers and dates; the backend formats the PDF
annual report, CSV spreadsheets and recommendation texts itself — following
`format.*` in the catalogue and the country deviations in
`src/Config/Countries.php`. Without them it would write German notation. The
docs exist in German and English — for further languages the help inside the
app is the way (glossary texts under `glossary.*`).

## Pull requests

- one topic per pull request, with a test;
- `vendor/bin/phpunit` and the browser tests green;
- docs and CHANGELOG (section for the next version) updated;
- contributions are published under the project's licence,
  [AGPL-3.0-or-later](LICENSE) — no separate agreement (CLA) needed.
