# Tests

Ausführlich: [Tests](../docs/entwicklung/tests.md) im Kompendium. Hier die
Kurzfassung.

## PHPUnit — Service-Schicht (`tests/unit/`)

Die Pflicht vor jedem Commit. PHPUnit ist die einzige Abhängigkeit, nur für
die Entwicklung (`composer install`); der Betrieb braucht kein Composer.
Basisklasse `Support/ServiceTestCase` — echte JSON-Dateien in einem
Temp-Verzeichnis, keine Mocks.

```sh
vendor/bin/phpunit --no-coverage
```

## Browser-nahe Tests gegen einen laufenden Server

- **`frontend-api-shape.test.js`** — liefern die Endpunkte genau die Formate,
  die die Oberfläche erwartet?
- **`browser-render.test.mjs`** — lädt die echten Ansichten in JSDOM, crawlt den
  Modulgraphen über HTTP und klickt sich durch Dialoge und Popover. Chart.js ist
  gestubbt (`esm-loader.mjs`).

```sh
# Server mit router.php (NICHT api.php — sonst landen /public/js/* in api.php),
# gegen eine Kopie der Beispieldaten; Port 8899 ist im Render-Test fest
cp -R demo-data /tmp/etdata
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8899 router.php &

node tests/frontend-api-shape.test.js
node --import='data:text/javascript,import{register}from"node:module";import{pathToFileURL}from"node:url";register("./tests/esm-loader.mjs",pathToFileURL("./"));' tests/browser-render.test.mjs
```

Braucht Node ≥ 20 und `jsdom` (`npm install --no-save jsdom`).

## Ohne Server

`format.test.mjs`, `router.test.mjs`, `contrast.test.mjs`,
`plausibility.test.mjs`, `ha-snippet.test.mjs` — jeweils mit `node` starten.

Alle Harnesses enden mit Exit-Code 0 bei Erfolg.
