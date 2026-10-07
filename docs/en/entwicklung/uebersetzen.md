# Translating and languages

**English** · [Deutsch](../../entwicklung/uebersetzen.md)

[← Tests](tests.md) · [Compendium index](../README.md)

The Energietracker speaks seven languages: German, English, French, Italian,
Spanish, Portuguese and Dutch. German is the source that is translated from.
This page describes how the languages fit together in the code, which style
rules apply and which test checks them, and what a new language or a new key
needs. How to take part: [CONTRIBUTING.md](../../../CONTRIBUTING.md).

---

## 1. Structure

### Catalogues

| File | Contents |
|---|---|
| `public/locales/<lang>.json` | one catalogue per language, nested keys, addressed in dot notation (`settings.lang.deviceLabel`). Browser and backend read the same files. |
| `public/locales/languages.json` | the list of languages: code → name in its own language (`"fr": "Français"`). What is listed here is a supported language — for the interface (`getLanguages()`) as for the backend (`I18nService::supported()`). |

Placeholders sit in curly braces (`{count}`, `{label}`). Three are filled in by
the translation itself, from the setting `currency`: `{cur}` (symbol),
`{minor}` (minor unit), `{code}` (ISO code). If a key is missing in a language,
the German text applies; if it is missing there too, the key itself appears.
`LocaleCatalogTest` makes sure it does not come to that.

### Browser — `public/js/lib/i18n.js`

- `t(key, params)` translates, `tp(key, n, params)` picks the plural form via
  `Intl.PluralRules` (Portuguese as pt-PT) and fills in `{count}`.
- `typography()` sets non-breaking spaces on output (see below).
- `lib/format.js` formats numbers, amounts and dates via `Intl`, with the
  region from language and country (`REGION`, `setCountry()`): `fmt.num`,
  `fmt.money`, `fmt.date`, `fmt.pct` (percent by language), `fmt.unit`
  (number and unit with a non-breaking space).

### Backend — `src/Services/I18nService.php`

- `t()` and `tp()` as in the browser; plural rules from the table
  `PLURAL_RULES` (without `ext-intl`).
- `typography()` with the same rules as in the browser.
- `number()`, `money()`, `date()`, `month()` following the catalogue values
  `format.*` and the country deviations in `Countries::FORMAT_OVERRIDES` — for
  the PDF annual report, CSV in format "local" and the recommendation texts.
- `errorCodeFor()` maps a message to its catalogue key; the key is the `code`
  of the error response.
- Code without an `I18nService` (static helpers such as `Utilities::get()`)
  throws `Support\LocalizedException(key, params, logText)`; the ErrorHandler
  translates into the language of the request. The third value is for the log
  only.

### Language of a request

Since v3.1.0 every device picks its own language. In the browser it lives in
`localStorage` `et-language` (empty = same as the installation); `index.php`
uses it to set `<html lang>` before the first paint. `api.js` sends it with
every request as `X-ET-Language`. The backend takes, in this order:

1. `X-ET-Language` — the language of the device,
2. the setting `language` — the default language of the installation,
3. `Accept-Language` — only without a valid setting (first start).

The browser opens downloads (PDF, CSV) as a page without this header; they are
therefore produced in the default language, as are responses to Home Assistant
and scripts. The print view of the annual report renders in the browser and
follows the language of the device. JSON responses carry
`Vary: X-ET-Language, Accept-Language`. The web app manifest comes via
`GET /api/manifest?lang=xx` in the language of the device. Details:
[API reference → language of the responses](../referenz/api.md#language-of-the-responses-v310).

### `format.*` — what every language says about itself

| Key | Meaning | Example (de) |
|---|---|---|
| `format.decimal` | decimal separator | `,` |
| `format.group` | thousands separator | `.` |
| `format.date` | date pattern (PHP `date()`) | `d.m.Y` |
| `format.monthsShort` | twelve month abbreviations, comma-separated | `Jan.,Feb.,März,…` |
| `format.money` | position of the currency sign | `{amount} {cur}` |
| `format.typography` | `fr` (French spaces) or `none` | `none` |
| `format.pdfCharset` | `cp1252` if the PDF fonts can set the language, otherwise `none` (print view only) | `cp1252` |

The country refines the language only where it is spoken there.
`src/Config/Countries.php` holds:

- `BY_LANGUAGE` — the country that matches the language on first start when
  the browser names no region (`en` → `GB`).
- `FORMAT_OVERRIDES` — notations in which country and language differ from
  the language alone (German in Austria and Switzerland, French and Italian in
  Switzerland).
- `bill_terms` and `comparison_portal` — the terms on the bill and the
  official tariff comparison per country
  ([country profiles §8](../verstehen/14-laenderprofile.md#8-what-your-bill-calls-it)).
  They belong to the country, not to the language, and are therefore not in
  the catalogue.

---

## 2. Style guide

Every rule here has been broken before. Where a test checks it, it is named
(`CatalogStyleTest` unless stated otherwise).

### Form of address

| Language | Address | Example |
|---|---|---|
| German | du | „Trag deine Zählerstände ein …“ |
| English | you | “Enter your meter readings …” |
| French | vous | « Saisissez vos relevés … » |
| Italian | tu | «Inserisci le letture dei contatori …» |
| Spanish | tú | «Introduce tus lecturas …» |
| Portuguese | third person without pronoun (formal) | «Introduza as leituras dos contadores …» |
| Dutch | je | “Voer je meterstanden in …” |

Test: `testAddressFormPerLanguage` looks for the respective other form.

### Terms — termbase

One thing has one name everywhere: in the contract form, in the table, in the
tariff comparison, in the help. The **glossary is the termbase** —
`glossary.<id>.term` is the canonical form per language.
`tests/fixtures/termbase.json` lists per term the canonical form (`use`) and
the variants that must no longer appear (`avoid`); `allowKeys` names deliberate
exceptions, `csv.*` is exempt. Test: `testTermbase`.

| German | English | French | Italian | Spanish | Portuguese | Dutch |
|---|---|---|---|---|---|---|
| Arbeitspreis | unit price | prix unitaire | prezzo unitario | precio unitario | preço unitário | eenheidsprijs |
| Grundpreis | standing charge | abonnement | quota fissa | término fijo | termo fixo | vastrecht |
| Abschlag | advance payment | acompte | acconto | anticipo | adiantamento | voorschot |
| Sonderzahlung | special payment | paiement exceptionnel | pagamento straordinario | pago extraordinario | pagamento extraordinário | bijzondere betaling |
| Stichtag | effective date | date d’effet | data di decorrenza | fecha de efecto | data de efeito | ingangsdatum |
| Zustandszahl | correction factor | coefficient de correction | coefficiente di correzione | coeficiente de corrección | coeficiente de correção | volumeherleidingsfactor |
| Verbrauchsart | utility | type de consommation | utenza | suministro | serviço | verbruikssoort |
| Subzähler | sub-meter | sous-compteur | sottocontatore | subcontador | subcontador | tussenmeter |

The complete list is in the file. Names of the utilities follow the catalogue
`utilityNames.*` (heating oil is “Huisbrandolie” in Dutch). What an item is
called on a country's **bill** is a different matter and lives in
`Countries.php` (`bill_terms`): the interface speaks a language, the bill a
country.

### Typography

| Language | Quotation marks | Percent |
|---|---|---|
| German | „…“ and ‚…‘ | `12 %` |
| English | “…” and ‘…’ | `12%` |
| French | « … » | `12 %` |
| Italian | «…» without inner spaces | `12%` |
| Spanish | «…» without inner spaces | `12 %` |
| Portuguese | «…» without inner spaces | `12%` |
| Dutch | “…” | `12%` |

- No straight ASCII quotation marks in text (except in `<code>` and in HTML
  attributes). French and Italian only with the typographic apostrophe ’.
- Percent as CLDR and `Intl` write it; in code via `fmt.pct`. The test checks
  that English, Italian, Portuguese and Dutch put no space before it.
- **Non-breaking spaces do not belong in the catalogue** — nobody types
  invisible characters reliably into JSON. The output sets them
  (`typography()` in the browser, `I18nService::typography()` in the backend):
  before `%` where there is a space, and with `format.typography = "fr"`
  additionally before `:` `;` `!` `?` and inside « ». CSV headers stay
  untouched.
- Portuguese after the 1990 Orthographic Agreement (“faturado”, “selecionado”,
  “ativo”).

Tests: `testTypographyPerLanguage`, `testFrenchTypographyAtOutput`.

### The German canon

The German catalogue is the source of every translation:

- no ASCII quotation marks;
- no anglicisms and class names (“Settings”, “Forecast”, “What-if”, “Sync”,
  “Utility”);
- dates as `JJJJ-MM-TT` or `TT.MM.JJJJ`, never `YYYY` or `DD`;
- ellipsis with a space: „Lädt …“;
- „Heizgradtag“, not „Gradtag“;
- field and class names in a message only at the end as „(API: feld)“;
- „{n} %“ with a space.

Test: `testGermanCanon` checks quotation marks, leftover English words, date
format and ellipsis; the other points are agreements.

### Sentence structure and placeholders

- Inserted labels (`{label}`, `{utility}`, `{sev}`, `{stage}`) are capitalised
  and carry no article. Except in German they therefore only stand at the start
  of a sentence, after “:”, “—”, “(” or an opening quotation mark — in
  mid-sentence they would give “Plan next Heating oil delivery”. Test:
  `testLabelsNotMidSentence`.
- Variants are whole sentences, not building blocks: rather three keys
  `recommendations.emptyFiltered.urgent`, `….warning`, `….info` than one
  sentence with an inserted word.
- Placeholders are the same in every language (`LocaleCatalogTest`).

### Plural

- A number in the text gets a **plural group**: `one` and `other`, plus `many`
  where the language has it. In code `tp(key, n)`, never `n === 1 ? … : …`.
- Rules per CLDR: `one` is exactly 1, in French 0 and 1 (also “1,5 jour”);
  `many` are round millions in French, Italian, Spanish and Portuguese
  (“1 million de …”). If a form is missing, `other` applies.
- Browser (`Intl.PluralRules`) and backend (`PLURAL_RULES`) pick the same form:
  `tests/plural.test.mjs` and `PluralRulesTest` check both against
  `tests/fixtures/plural-cases.json`.
- No bracket plurals („Problem(e)“). `{count}`, `{days}` and `{months}` appear
  only in plural groups, except in forms that fit every number („Alle (3)“,
  „Probleme: 3“, „14 von 31 Tagen“). Test:
  `testCountPlaceholdersLiveInPluralGroups`.
- `errors.*` stay single keys with a plural-neutral number (“problems: 3”) —
  their key is the error code.

### What does not change

- **`csv.*` is frozen.** These keys carry the header of the monthly overview in
  CSV format 1, which scripts rely on. Translations improve `csvLocal.*`, never
  `csv.*` ([CSV formats](../referenz/api.md#csv-formats-v310)).
- **Error codes are stable.** A key under `errors.*` is the `code` of the error
  response; Home Assistant and scripts evaluate it. The text may be improved,
  the key not renamed.
- **No texts in code.** What users read is in the catalogue. Checked by
  `HardcodedTextTest` (backend) and `tests/hardcoded-text.test.mjs` (views and
  components).
- **No dead keys.** `CatalogUsageTest` finds keys the code uses neither
  literally nor through a composed prefix.
- File names of the CSV exports (`csvLocal.file.*`) are ASCII lower-case
  letters, digits and hyphens (`testCsvFileSlugs`).

---

## 3. Checklist: new language

A copy of `en.json` under a new code is not enough: as long as required parts
are missing, `CatalogStyleTest` (`testEveryLanguageIsComplete`,
`testBillCheckMarksFollowLanguage`), `PluralRulesTest` and
`DemoDataTranslatorTest` fail. The full list:

1. **Catalogue** `public/locales/<code>.json` (ISO 639-1), translated from
   `en.json` or `de.json`. Placeholders stay, plural groups get the forms of
   the language.
2. **`languages.json`:** `"<code>": "<name in the language>"`.
3. **`format.*`** in the catalogue: `decimal`, `group`, `date`, `monthsShort`
   (twelve), `money`, `typography`, `pdfCharset`. Latin script without
   characters outside CP1252 → `cp1252`, otherwise `none`; `PdfCharsetTest`
   checks this against every report text.
4. **Plural rule** in `I18nService::PLURAL_RULES` and the cases of the language
   in `tests/fixtures/plural-cases.json`. If `Intl` needs a region for the right
   rules, an entry in `PLURAL_LOCALE` (`lib/i18n.js`).
5. **Region** for `Intl` in `REGION` (`public/js/lib/format.js`) if the
   language would be formatted wrongly without a region.
6. **Country** in `Countries::BY_LANGUAGE` for the first start; if the language
   is spoken in a country with its own notation, a line in
   `FORMAT_OVERRIDES`.
7. **Letters of the bill check** (`utility.billCheck.mark.estimated` and
   `.interpolated`, the way bills in that language mark estimates) and their
   entry in `CatalogStyleTest::testBillCheckMarksFollowLanguage`.
8. **Style rules** for the language: form of address (a pattern in
   `testAddressFormPerLanguage`), forbidden quotation marks and percent in
   `testTypographyPerLanguage`, the language in `testLabelsNotMidSentence`.
9. **Termbase:** in `tests/fixtures/termbase.json` the canonical form under
   `use` and known wrong forms under `avoid` per term; the glossary
   (`glossary.<id>.term`) must contain the `use` form.
10. **Demo data:** the translation of every demo text in
    `demo-data/translations.json` (`DemoDataTranslatorTest`).
11. **Tests:**
    `vendor/bin/phpunit --no-coverage --filter 'LocaleCatalogTest|CatalogStyleTest|CatalogUsageTest|PluralRulesTest|PdfCharsetTest|CsvLocalFormatTest|DemoDataTranslatorTest'`
    and `node tests/plural.test.mjs`.

The documentation exists in German and English; for further languages the help
inside the app is the way (`glossary.*`).

## 4. Checklist: new key

1. Add it to **all seven** catalogues, German first and following the German
   canon. The same placeholders everywhere (`LocaleCatalogTest`).
2. If it contains a number: a **plural group** and `tp()` — except for
   `errors.*` (plural-neutral, see above).
3. Use the **termbase terms**; when in doubt, look up the language's
   `glossary.<id>.term`.
4. **Form of address and typography** of the language, label placeholders only
   in the allowed places.
5. The key must be **used** (`CatalogUsageTest`). If the code composes it
   (`` `utilityNames.${key}` ``), the test recognises the prefix; otherwise it
   belongs in `CatalogUsageTest::EXTRA` with a reason. Whoever removes code
   removes its keys too.
6. A new message for the backend goes under `errors.*` and is thrown via `t()`
   or `LocalizedException` — never as text.
7. Check:
   `vendor/bin/phpunit --no-coverage --filter 'LocaleCatalogTest|CatalogStyleTest|CatalogUsageTest|HardcodedTextTest'`
   and `node tests/hardcoded-text.test.mjs`.

---

[← Tests](tests.md) ·
[Release process →](release-prozess.md)
