# Contributing

**English** · [Deutsch](CONTRIBUTING.de.md)

Thanks for joining in! What helps most:

- **Bug reports** with the details from Settings → System → System diagnostics
  and the steps that lead to the error.
- **Translations** — a new language needs no code (see below).
- **Documentation**: mistakes, gaps, a question missing from the
  [FAQ](docs/en/einstieg/faq.md).
- **Code** — before larger changes to the data model or the API please open an
  issue first, so that migration and the stability promise are thought through.

Please do **not** report security issues as an issue, but as described in
[SECURITY.md](SECURITY.md).

## Setting up

PHP 8.4 is enough to run it, without Composer, without a build step:

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

npm install --no-save jsdom                        # for the browser tests
cp -R demo-data /tmp/etdata
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8899 router.php &
node tests/frontend-api-shape.test.js              # API formats
node --import='data:text/javascript,import{register}from"node:module";import{pathToFileURL}from"node:url";register("./tests/esm-loader.mjs",pathToFileURL("./"));' \
  tests/browser-render.test.mjs                    # views in JSDOM (port 8899 is fixed)
```

Without a server, `tests/format.test.mjs`, `tests/router.test.mjs`,
`tests/contrast.test.mjs`, `tests/chart.test.mjs`, `tests/plausibility.test.mjs`
and `tests/ha-snippet.test.mjs` run. CI runs everything on every pull request.
Details: [Tests](docs/en/entwicklung/tests.md).

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

1. Copy `public/locales/en.json` to `public/locales/<code>.json` (ISO 639-1,
   e.g. `pl`) and translate it. Placeholders like `{count}` stay; plural forms
   are written as `one`/`other` (further categories such as `few`/`many` are
   allowed, the choice follows `Intl.PluralRules`).
2. Register it in `public/locales/languages.json`: `"pl": "Polski"`.
3. `vendor/bin/phpunit --filter LocaleCatalogTest` checks that every key is
   there and the placeholders match.

Number and date formats come from the browser (`Intl`); no code is needed. The
docs exist in German and English — for further languages the help inside the
app is the way (glossary texts under `glossary.*`).

## Pull requests

- one topic per pull request, with a test;
- `vendor/bin/phpunit` and the browser tests green;
- docs and CHANGELOG (section for the next version) updated;
- contributions are under the project's [MIT licence](LICENSE).
