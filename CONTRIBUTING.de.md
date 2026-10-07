# Mitwirken

[English](CONTRIBUTING.md) · **Deutsch**

Danke fürs Mitmachen! Am meisten helfen:

- **Fehlermeldungen** mit den Angaben aus Einstellungen → System →
  System-Diagnose und den Schritten, die zum Fehler führen.
- **Übersetzungen** — eine neue Sprache ist vor allem ein Katalog, dazu ein
  paar Zeilen Konfiguration (siehe unten).
- **Doku**: Fehler, Lücken, eine fehlende Frage in der
  [FAQ](docs/einstieg/faq.md).
- **Code** — vor größeren Änderungen am Datenmodell oder an der API bitte erst
  ein Issue, damit Migration und Stabilitätszusage durchdacht sind.

Sicherheitslücken bitte **nicht** als Issue, sondern wie in
[SECURITY.md](SECURITY.md) beschrieben melden.

## Einrichten

PHP ab 8.2 genügt zum Laufen (die CI prüft 8.2, 8.3 und 8.4), ohne Composer,
ohne Build-Schritt:

```bash
git clone https://github.com/Bingerminger/energietracker.git
cd energietracker
cp -R demo-data /tmp/etdata                        # eine Kopie der Beispieldaten
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8080 router.php
```

Immer mit `router.php` — ohne ihn liefert der PHP-Server auch `data/` aus.

## Testen

```bash
composer install                                   # nur für PHPUnit (dev)
vendor/bin/phpunit --no-coverage                   # Service-Schicht

npm install --no-save jsdom                        # für die Browser-Tests
cp -R demo-data /tmp/etdata
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8899 router.php &
node tests/frontend-api-shape.test.js              # API-Formate
node --import='data:text/javascript,import{register}from"node:module";import{pathToFileURL}from"node:url";register("./tests/esm-loader.mjs",pathToFileURL("./"));' \
  tests/browser-render.test.mjs                    # Ansichten in JSDOM (Port 8899 fest)
```

Ohne Server laufen `tests/format.test.mjs`, `tests/router.test.mjs`,
`tests/contrast.test.mjs`, `tests/chart.test.mjs`, `tests/plausibility.test.mjs`,
`tests/ha-snippet.test.mjs`, `tests/plural.test.mjs` und
`tests/hardcoded-text.test.mjs`. Die CI führt alles bei jedem Pull Request aus.
Details: [Tests](docs/entwicklung/tests.md).

Eine Änderung bringt ihren Test mit. Bewährt hat sich die **Gegenprobe**: den
neuen Schutz im Code kurz zurückdrehen — der Test muss dann rot werden.

## Grundsätze

- **Keine Laufzeit-Abhängigkeiten.** PHP ohne Composer im Betrieb, Frontend als
  Vanilla-ES-Module ohne Build. Eine neue Bibliothek ist eine
  Architekturfrage — bitte vorher im Issue.
- **Eine Quelle der Wahrheit.** Eigenschaften der Verbrauchsarten stehen in
  `src/Config/Utilities.php` (die Oberfläche liest sie über `/api/utilities`),
  Standardwerte in `SettingsService::DEFAULTS`. Keine Listen wie
  `['gas', 'strom']` im Code.
- **Stabilitätszusage.** API-Felder, CSV-Formate und das Backup-Format (3.0)
  ändern sich nur additiv; entfernt wird erst mit einer neuen Hauptversion und
  nach Ankündigung. Home Assistant und Skripte hängen daran.
- **Sprache.** Symbole (Klassen, Methoden, Felder) englisch; Kommentare,
  Doku und CHANGELOG deutsch. Pull Requests und Issues gern auf Deutsch oder
  Englisch.
- **Keine Nutzerdaten im Repository** — auch nicht in Tests oder
  Beispielen: keine echten Zählerstände, Beträge, Anbieter, Vertrags- oder
  Zählpunktnummern.

## Doku

Jede sichtbare Änderung zieht die Doku mit — Deutsch ist die maßgebliche
Fassung, der englische Spiegel liegt unter `docs/en/` am selben relativen Pfad.
Ein Test prüft Links, Anker und dass jede Seite ihren Spiegel hat. Neue Seiten
gehören in den Index (`docs/README.md`, `docs/en/README.md`). Der CHANGELOG
folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/) mit den
Abschnitten Added, Changed, Deprecated, Fixed, Migration und Tests.

## Eine Sprache hinzufügen

Die vollständige Checkliste, den Stilguide (Anrede, Begriffe, Typografie,
Plural) und die Tests dazu beschreibt
[Übersetzen und Sprachen](docs/entwicklung/uebersetzen.md). In Kürze:

1. `public/locales/en.json` nach `public/locales/<code>.json` kopieren
   (ISO-639-1, z. B. `pl`) und übersetzen. Platzhalter wie `{count}` bleiben
   stehen; Pluralformen stehen als `one`/`other` (weitere Kategorien wie
   `few`/`many` sind erlaubt).
2. In `public/locales/languages.json` eintragen: `"pl": "Polski"`.
3. Die Schreibweise im Katalog unter `format.*` festlegen — Dezimal- und
   Tausendertrenner, Datumsmuster, Monatskürzel, Betrag, `typography` und
   `pdfCharset`.
4. Die Sprache im Code bekannt machen: Pluralregel in
   `I18nService::PLURAL_RULES` (mit Fällen in
   `tests/fixtures/plural-cases.json`), Land für den Erststart in
   `Countries::BY_LANGUAGE`, Kürzel der Rechnungsprüfung, Begriffe der
   Termbase und die Übersetzung der Demo-Daten.
5. `vendor/bin/phpunit --no-coverage --filter 'LocaleCatalogTest|CatalogStyleTest|PluralRulesTest'`
   prüft Schlüssel, Platzhalter, Stil und Vollständigkeit.

Im Browser formatiert `Intl` Zahlen und Daten; das Backend formatiert für
PDF-Jahresbericht, CSV-Tabellen und Empfehlungstexte selbst — nach `format.*`
im Katalog und den Länderabweichungen in `src/Config/Countries.php`. Ohne diese
Angaben schriebe es deutsch. Die Doku gibt es auf Deutsch und Englisch — für
weitere Sprachen ist die Hilfe in der App der Weg (Glossar-Texte unter
`glossary.*`).

## Pull Requests

- ein Thema je Pull Request, mit Test;
- `vendor/bin/phpunit` und die Browser-Tests grün;
- Doku und CHANGELOG (Abschnitt für die nächste Version) nachgezogen;
- Beiträge erscheinen unter der Lizenz des Projekts,
  [AGPL-3.0-or-later](LICENSE) — eine eigene Vereinbarung (CLA) ist nicht nötig.
