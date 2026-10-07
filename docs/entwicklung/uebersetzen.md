# Übersetzen und Sprachen

**Deutsch** · [English](../en/entwicklung/uebersetzen.md)

[← Tests](tests.md) · [Kompendium-Index](../README.md)

Der Energietracker spricht sieben Sprachen: Deutsch, Englisch, Französisch,
Italienisch, Spanisch, Portugiesisch und Niederländisch. Deutsch ist die
Vorlage, aus der übersetzt wird. Diese Seite beschreibt, wie die Sprachen im
Code zusammenhängen, welche Stilregeln gelten und welcher Test sie prüft, und
was eine neue Sprache oder ein neuer Schlüssel braucht. Wie man mitmacht:
[CONTRIBUTING.de.md](../../CONTRIBUTING.de.md).

---

## 1. Aufbau

### Kataloge

| Datei | Inhalt |
|---|---|
| `public/locales/<lang>.json` | ein Katalog je Sprache, verschachtelte Schlüssel, angesprochen in Punkt-Notation (`settings.lang.deviceLabel`). Browser und Backend lesen dieselben Dateien. |
| `public/locales/languages.json` | die Liste der Sprachen: Kürzel → Name in der eigenen Sprache (`"fr": "Français"`). Was hier steht, ist eine unterstützte Sprache — für die Oberfläche (`getLanguages()`) wie für das Backend (`I18nService::supported()`). |

Platzhalter stehen in geschweiften Klammern (`{count}`, `{label}`). Drei setzt
die Übersetzung selbst ein, aus der Einstellung `currency`: `{cur}` (Symbol),
`{minor}` (Untereinheit), `{code}` (ISO-Code). Fehlt ein Schlüssel in einer
Sprache, gilt der deutsche Text; fehlt er auch dort, erscheint der Schlüssel
selbst. `LocaleCatalogTest` sorgt dafür, dass es dazu nicht kommt.

### Browser — `public/js/lib/i18n.js`

- `t(key, params)` übersetzt, `tp(key, n, params)` wählt die Pluralform über
  `Intl.PluralRules` (Portugiesisch als pt-PT) und setzt `{count}`.
- `typography()` setzt bei der Ausgabe geschützte Leerzeichen (s. u.).
- Zahlen, Beträge und Daten formatiert `lib/format.js` über `Intl`, mit der
  Region aus Sprache und Land (`REGION`, `setCountry()`): `fmt.num`,
  `fmt.money`, `fmt.date`, `fmt.pct` (Prozent nach der Sprache), `fmt.unit`
  (Zahl und Einheit mit geschütztem Leerzeichen).

### Backend — `src/Services/I18nService.php`

- `t()` und `tp()` wie im Browser; Pluralregeln aus der Tabelle
  `PLURAL_RULES` (ohne `ext-intl`).
- `typography()` mit denselben Regeln wie im Browser.
- `number()`, `money()`, `date()`, `month()` nach den Katalogwerten `format.*`
  und den Länderabweichungen in `Countries::FORMAT_OVERRIDES` — für
  PDF-Jahresbericht, CSV im Format „local“ und Texte der Empfehlungen.
- `errorCodeFor()` ordnet eine Meldung ihrem Katalogschlüssel zu; der
  Schlüssel ist der `code` der Fehlerantwort.
- Code ohne `I18nService` (statische Helfer wie `Utilities::get()`) wirft
  `Support\LocalizedException(key, params, logText)`; der ErrorHandler
  übersetzt in die Sprache der Anfrage. Der dritte Wert ist nur fürs Log.

### Sprache einer Anfrage

Seit v3.1.0 wählt jedes Gerät seine Sprache selbst. Im Browser liegt sie unter
`localStorage` `et-language` (leer = wie die Installation); `index.php` setzt
damit schon vor dem ersten Bild `<html lang>`. `api.js` schickt sie bei jeder
Anfrage als `X-ET-Language`. Das Backend nimmt in dieser Reihenfolge:

1. `X-ET-Language` — die Sprache des Geräts,
2. die Einstellung `language` — die Standardsprache der Installation,
3. `Accept-Language` — nur ohne gültige Einstellung (Erststart).

Downloads (PDF, CSV) öffnet der Browser als Seite ohne diese Kopfzeile; sie
entstehen deshalb in der Standardsprache, ebenso Antworten an Home Assistant
und Skripte. Die Druckansicht des Jahresberichts rechnet im Browser und folgt
der Sprache des Geräts. JSON-Antworten tragen
`Vary: X-ET-Language, Accept-Language`. Das Web-App-Manifest kommt über
`GET /api/manifest?lang=xx` in der Sprache des Geräts. Details:
[API-Referenz → Sprache der Antworten](../referenz/api.md#sprache-der-antworten-v310).

### `format.*` — was jede Sprache über sich sagt

| Schlüssel | Bedeutung | Beispiel (de) |
|---|---|---|
| `format.decimal` | Dezimalzeichen | `,` |
| `format.group` | Tausendertrenner | `.` |
| `format.date` | Datumsmuster (PHP-`date()`) | `d.m.Y` |
| `format.monthsShort` | zwölf Monatskürzel, durch Komma getrennt | `Jan.,Feb.,März,…` |
| `format.money` | Stellung des Währungszeichens | `{amount} {cur}` |
| `format.typography` | `fr` (französische Leerzeichen) oder `none` | `none` |
| `format.pdfCharset` | `cp1252`, wenn die PDF-Schriften die Sprache setzen können, sonst `none` (dann nur Druckansicht) | `cp1252` |

Das Land ergänzt die Sprache nur, wo sie dort gesprochen wird.
`src/Config/Countries.php` hält dazu:

- `BY_LANGUAGE` — das Land, das beim Erststart zur Sprache passt, wenn der
  Browser keine Region nennt (`en` → `GB`).
- `FORMAT_OVERRIDES` — Schreibweisen, in denen Land und Sprache von der
  Sprache allein abweichen (Deutsch in Österreich und der Schweiz,
  Französisch und Italienisch in der Schweiz).
- `bill_terms` und `comparison_portal` — die Begriffe der Rechnung und der
  amtliche Tarifvergleich je Land
  ([Länderprofile §8](../verstehen/14-laenderprofile.md#8-so-heißt-das-auf-deiner-rechnung)).
  Sie gehören dem Land, nicht der Sprache, und stehen deshalb nicht im
  Katalog.

---

## 2. Stilguide

Jede Regel hier war schon einmal gebrochen. Wo ein Test sie prüft, steht er
dabei (`CatalogStyleTest`, wenn nicht anders genannt).

### Anrede

| Sprache | Anrede | Beispiel |
|---|---|---|
| Deutsch | du | „Trag deine Zählerstände ein …“ |
| Englisch | you | “Enter your meter readings …” |
| Französisch | vous | « Saisissez vos relevés … » |
| Italienisch | tu | «Inserisci le letture dei contatori …» |
| Spanisch | tú | «Introduce tus lecturas …» |
| Portugiesisch | 3. Person ohne Pronomen (Sie-Form) | «Introduza as leituras dos contadores …» |
| Niederländisch | je | “Voer je meterstanden in …” |

Test: `testAddressFormPerLanguage` sucht die jeweils andere Form.

### Begriffe — Termbase

Eine Sache heißt überall gleich: in der Vertragsmaske, in der Tabelle, im
Tarifvergleich, in der Hilfe. Das **Glossar ist die Termbase** —
`glossary.<id>.term` ist die kanonische Form je Sprache.
`tests/fixtures/termbase.json` führt je Begriff die kanonische Form (`use`)
und die Varianten, die nicht mehr vorkommen dürfen (`avoid`); `allowKeys`
nennt bewusste Ausnahmen, `csv.*` ist ausgenommen. Test: `testTermbase`.

| Deutsch | Englisch | Französisch | Italienisch | Spanisch | Portugiesisch | Niederländisch |
|---|---|---|---|---|---|---|
| Arbeitspreis | unit price | prix unitaire | prezzo unitario | precio unitario | preço unitário | eenheidsprijs |
| Grundpreis | standing charge | abonnement | quota fissa | término fijo | termo fixo | vastrecht |
| Abschlag | advance payment | acompte | acconto | anticipo | adiantamento | voorschot |
| Sonderzahlung | special payment | paiement exceptionnel | pagamento straordinario | pago extraordinario | pagamento extraordinário | bijzondere betaling |
| Stichtag | effective date | date d’effet | data di decorrenza | fecha de efecto | data de efeito | ingangsdatum |
| Zustandszahl | correction factor | coefficient de correction | coefficiente di correzione | coeficiente de corrección | coeficiente de correção | volumeherleidingsfactor |
| Verbrauchsart | utility | type de consommation | utenza | suministro | serviço | verbruikssoort |
| Subzähler | sub-meter | sous-compteur | sottocontatore | subcontador | subcontador | tussenmeter |

Die vollständige Liste steht in der Datei. Eigennamen der Verbrauchsarten
folgen dem Katalog `utilityNames.*` (Heizöl heißt niederländisch
„Huisbrandolie“). Wie ein Posten auf der **Rechnung** eines Landes heißt, ist
etwas anderes und steht in `Countries.php` (`bill_terms`): Die Oberfläche
spricht eine Sprache, die Rechnung ein Land.

### Typografie

| Sprache | Anführungszeichen | Prozent |
|---|---|---|
| Deutsch | „…“ und ‚…‘ | `12 %` |
| Englisch | “…” und ‘…’ | `12%` |
| Französisch | « … » | `12 %` |
| Italienisch | «…» ohne Innenabstand | `12%` |
| Spanisch | «…» ohne Innenabstand | `12 %` |
| Portugiesisch | «…» ohne Innenabstand | `12%` |
| Niederländisch | “…” | `12%` |

- Keine geraden ASCII-Anführungszeichen im Text (außer in `<code>` und in
  HTML-Attributen). Französisch und Italienisch nur mit typografischem
  Apostroph ’.
- Prozent wie CLDR und `Intl` es schreiben; im Code über `fmt.pct`.
  Geprüft wird, dass Englisch, Italienisch, Portugiesisch und Niederländisch
  kein Leerzeichen davor setzen.
- **Geschützte Leerzeichen gehören nicht in den Katalog** — unsichtbare
  Zeichen tippt niemand zuverlässig ins JSON. Die Ausgabe setzt sie
  (`typography()` im Browser, `I18nService::typography()` im Backend): vor
  `%`, wo ein Leerzeichen steht, und mit `format.typography = "fr"` zusätzlich
  vor `:` `;` `!` `?` und innerhalb von « ». CSV-Kopfzeilen bleiben
  unberührt.
- Portugiesisch nach dem Orthographieabkommen 1990 („faturado“,
  „selecionado“, „ativo“).

Tests: `testTypographyPerLanguage`, `testFrenchTypographyAtOutput`.

### Deutscher Kanon

Der deutsche Katalog ist die Vorlage aller Übersetzungen:

- keine ASCII-Anführungszeichen;
- keine Anglizismen und Klassennamen („Settings“, „Forecast“, „What-if“,
  „Sync“, „Utility“);
- Datum als `JJJJ-MM-TT` bzw. `TT.MM.JJJJ`, nie `YYYY` oder `DD`;
- Auslassungspunkte mit Leerzeichen: „Lädt …“;
- „Heizgradtag“, nicht „Gradtag“;
- Feld- und Klassennamen in einer Meldung nur am Ende als „(API: feld)“;
- „{n} %“ mit Leerzeichen.

Test: `testGermanCanon` prüft Anführungszeichen, englische Restwörter,
Datumsformat und Auslassungspunkte; die übrigen Punkte sind Absprache.

### Satzbau und Platzhalter

- Eingesetzte Bezeichnungen (`{label}`, `{utility}`, `{sev}`, `{stage}`) sind
  groß geschrieben und tragen keinen Artikel. Außer im Deutschen stehen sie
  deshalb nur am Satzanfang, nach „:“, „—“, „(“ oder einem öffnenden
  Anführungszeichen — mitten im Satz ergäben sie „Plan next Heating oil
  delivery“. Test: `testLabelsNotMidSentence`.
- Varianten sind ganze Sätze, keine Wortbausteine: lieber drei Schlüssel
  `recommendations.emptyFiltered.urgent`, `….warning`, `….info` als ein Satz
  mit eingesetztem Wort.
- Platzhalter sind in jeder Sprache dieselben (`LocaleCatalogTest`).

### Plural

- Eine Zahl im Text bekommt eine **Pluralgruppe**: `one` und `other`, dazu
  `many`, wo die Sprache sie kennt. Im Code `tp(key, n)`, nie
  `n === 1 ? … : …`.
- Regeln nach CLDR: `one` ist genau 1, im Französischen 0 und 1 (auch
  „1,5 jour“); `many` sind runde Millionen in Französisch, Italienisch,
  Spanisch und Portugiesisch („1 million de …“). Fehlt eine Form, gilt
  `other`.
- Browser (`Intl.PluralRules`) und Backend (`PLURAL_RULES`) wählen dieselbe
  Form: `tests/plural.test.mjs` und `PluralRulesTest` prüfen beide gegen
  `tests/fixtures/plural-cases.json`.
- Keine Klammer-Plurale („Problem(e)“). `{count}`, `{days}` und `{months}`
  stehen nur in Pluralgruppen, außer in Formen, die für jede Zahl passen
  („Alle (3)“, „Probleme: 3“, „14 von 31 Tagen“). Test:
  `testCountPlaceholdersLiveInPluralGroups`.
- `errors.*` bleiben Einzelschlüssel mit einer pluralneutralen Zahl
  („Probleme: 3“) — ihr Schlüssel ist der Fehlercode.

### Was sich nicht ändert

- **`csv.*` ist eingefroren.** Die Schlüssel tragen die Kopfzeile der
  Monatsübersicht im CSV-Format 1, auf das Skripte bauen. Übersetzungen
  verbessern `csvLocal.*`, nie `csv.*`
  ([CSV-Formate](../referenz/api.md#csv-formate-v310)).
- **Fehlercodes sind stabil.** Ein Schlüssel unter `errors.*` ist der `code`
  der Fehlerantwort; Home Assistant und Skripte werten ihn aus. Den Text darf
  man verbessern, den Schlüssel nicht umbenennen.
- **Keine Texte im Code.** Was Nutzer lesen, steht im Katalog. Geprüft von
  `HardcodedTextTest` (Backend) und `tests/hardcoded-text.test.mjs`
  (Ansichten und Komponenten).
- **Keine toten Schlüssel.** `CatalogUsageTest` findet Schlüssel, die der
  Code weder wörtlich noch über ein zusammengesetztes Präfix benutzt.
- Dateinamen der CSV-Exporte (`csvLocal.file.*`) sind ASCII-Kleinbuchstaben,
  Ziffern und Bindestriche (`testCsvFileSlugs`).

---

## 3. Checkliste: neue Sprache

Eine Kopie von `en.json` unter neuem Kürzel reicht nicht: Solange
Pflichtteile fehlen, schlagen `CatalogStyleTest`
(`testEveryLanguageIsComplete`, `testBillCheckMarksFollowLanguage`),
`PluralRulesTest` und `DemoDataTranslatorTest` an. Die ganze Liste:

1. **Katalog** `public/locales/<code>.json` (ISO 639-1) aus `en.json` oder
   `de.json` übersetzen. Platzhalter bleiben, Pluralgruppen bekommen die
   Formen der Sprache.
2. **`languages.json`:** `"<code>": "<Name in der Sprache>"`.
3. **`format.*`** im Katalog: `decimal`, `group`, `date`, `monthsShort`
   (zwölf), `money`, `typography`, `pdfCharset`. Lateinische Schrift ohne
   Zeichen außerhalb von CP1252 → `cp1252`, sonst `none`; `PdfCharsetTest`
   prüft das an allen Berichtstexten.
4. **Pluralregel** in `I18nService::PLURAL_RULES` und die Fälle der Sprache in
   `tests/fixtures/plural-cases.json`. Braucht `Intl` eine Region für die
   richtigen Regeln, einen Eintrag in `PLURAL_LOCALE` (`lib/i18n.js`).
5. **Region** für `Intl` in `REGION` (`public/js/lib/format.js`), wenn die
   Sprache ohne Region falsch formatiert würde.
6. **Land** in `Countries::BY_LANGUAGE` für den Erststart; spricht man die
   Sprache in einem Land mit eigener Schreibweise, eine Zeile in
   `FORMAT_OVERRIDES`.
7. **Kürzel der Rechnungsprüfung** (`utility.billCheck.mark.estimated` und
   `.interpolated`, so wie Rechnungen der Sprache Schätzungen kennzeichnen)
   und den Eintrag dafür in `CatalogStyleTest::testBillCheckMarksFollowLanguage`.
8. **Stilregeln** für die Sprache festlegen: Anrede (ein Muster in
   `testAddressFormPerLanguage`), verbotene Anführungszeichen und Prozent in
   `testTypographyPerLanguage`, die Sprache in `testLabelsNotMidSentence`.
9. **Termbase:** in `tests/fixtures/termbase.json` je Begriff die
   kanonische Form unter `use` und bekannte Fehlformen unter `avoid`; das
   Glossar (`glossary.<id>.term`) muss die `use`-Form enthalten.
10. **Demo-Daten:** die Übersetzung jedes Demo-Textes in
    `demo-data/translations.json` (`DemoDataTranslatorTest`).
11. **Tests:**
    `vendor/bin/phpunit --no-coverage --filter 'LocaleCatalogTest|CatalogStyleTest|CatalogUsageTest|PluralRulesTest|PdfCharsetTest|CsvLocalFormatTest|DemoDataTranslatorTest'`
    und `node tests/plural.test.mjs`.

Die Doku gibt es auf Deutsch und Englisch; für weitere Sprachen ist die Hilfe
in der App der Weg (`glossary.*`).

## 4. Checkliste: neuer Schlüssel

1. In **alle sieben** Kataloge eintragen, Deutsch zuerst und nach dem
   deutschen Kanon. Gleiche Platzhalter überall (`LocaleCatalogTest`).
2. Steht eine Zahl darin: **Pluralgruppe** und `tp()` — außer bei `errors.*`
   (pluralneutral, s. o.).
3. **Begriffe der Termbase** verwenden; im Zweifel `glossary.<id>.term` der
   Sprache nachschlagen.
4. **Anrede und Typografie** der Sprache, Bezeichnungs-Platzhalter nur an den
   erlaubten Stellen.
5. Der Schlüssel muss **benutzt** werden (`CatalogUsageTest`). Setzt der Code
   ihn zusammen (`` `utilityNames.${key}` ``), erkennt der Test das Präfix;
   sonst gehört es mit Begründung in `CatalogUsageTest::EXTRA`. Wer Code
   entfernt, entfernt seine Schlüssel mit.
6. Eine neue Meldung fürs Backend kommt unter `errors.*` und wird über
   `t()` oder `LocalizedException` geworfen — nie als Text.
7. Prüfen:
   `vendor/bin/phpunit --no-coverage --filter 'LocaleCatalogTest|CatalogStyleTest|CatalogUsageTest|HardcodedTextTest'`
   und `node tests/hardcoded-text.test.mjs`.

---

[← Tests](tests.md) ·
[Release-Prozess →](release-prozess.md)
