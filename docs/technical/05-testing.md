# Tests

[← Datenmodell](04-data-model.md) · [Kompendium-Index](../README.md)

Bewusst **kein** Test-Framework — konsistent mit der dependency-freien
Philosophie. Zwei sich ergänzende Harnesses unter `tests/`, Node ≥ 20 +
`jsdom` genügen.

---

## 1. `frontend-api-shape.test.js`

*(bis v1.4.3 `backend-shape.test.js` — in v1.4.4 umbenannt, da der
Name die Frontend-seitige Perspektive widerspiegeln soll; der leere
`loadModule`-Stub wurde entfernt.)*

Prüft, dass die API exakt die Datenstrukturen liefert, die das Frontend
erwartet (Feldnamen, Typen, Hüllen). Hintergrund: mehrere historische
Bugs entstanden durch Backend↔Frontend-Feldnamen-Mismatch
(z. B. `AnomalyService` lieferte `value/z_score`, das Frontend las
`actual/z`). Backend-curl-Tests allein fangen das nicht — dieser Test
vergleicht die echten Antwortformen.

Benötigt einen laufenden Backend-Server.

---

## 2. `browser-render.test.mjs`

Lädt die **echten** View-ES-Module in JSDOM und ruft `render()` gegen
den laufenden Backend-Server auf. Chart.js wird über `esm-loader.mjs`
gestubbt; die übrige View-Logik (DOM-Aufbau, Events, Datenfluss) läuft
echt. Fängt: ReferenceErrors, kaputte DOM-Queries, Template-Fehler,
Event-Binding-Fehler.

### Modulgraph-Vorprüfung (seit v1.4.1)

Der erste Check crawlt **`app.js` samt aller transitiven Importe über
HTTP** gegen den Server. Genau diese Lücke verursachte Bug v1.4.1:
`sidebar.js` importierte `./state.js` statt `../state.js` → 404 → der
gesamte ES-Modulgraph brach → die App blieb bei „Lade…" stehen. Ein
reiner JSDOM-Direktimport sieht das **nicht** (er lädt per Dateipfad,
nicht über den Browser-Graphen). Der HTTP-Crawl fängt es.

---

## 3. Ausführen

```bash
# 1. Testdaten (Demo-Datensatz trägt schema_version 1.1.0 und wird beim
#    Start additiv aufs aktuelle Schema migriert)
cp -r demo-data /tmp/etdata

# 2. Server, der API UND statische Assets ausliefert. WICHTIG:
#    router.php (nicht api.php) als Router — er spiegelt das
#    nginx-Routing (statische Datei → direkt; /api → api.php; sonst
#    index.php). Mit api.php als Router liefe /public/js/app.js durch
#    api.php → 404, und der Modulgraph-Crawl des Browser-Render-Tests
#    scheitert. (Korrektur seit v1.5.1.)
ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8899 router.php &

# 3. Tests
node tests/frontend-api-shape.test.js
node --import='data:text/javascript,import{register}from"node:module";\
import{pathToFileURL}from"node:url";\
register("./tests/esm-loader.mjs",pathToFileURL("./"));' \
  tests/browser-render.test.mjs
```

Beide Harnesses geben Exit-Code 0 bei Erfolg. Stand v1.9.2:
**Frontend-API-Shape 20/20**, **Browser-Render 36/36** (inkl. Modulgraph-
Vorprüfung und Forecast-Modell-Check für alle fünf Modelle).

Hinzu kommt die **PHPUnit-Suite** für die Service-Schicht
(`tests/unit/…`, Basisklasse `ServiceTestCase`): real gegen echte
JSON-Dateien, ohne Mocks. Stand v1.9.2: **86 Tests / 274 Assertions**.
Ausführen mit `vendor/bin/phpunit --no-coverage`. Sie ist das
**Pflicht-Gate vor jedem Commit** (siehe
[Release-Prozess](06-release-process.md)).

Genau diese Sequenz läuft automatisiert in der **CI-Pipeline**
(`.github/workflows/ci.yml`) bei jedem Push und Pull Request gegen
`main`. Vier Jobs: **lint-php** (Syntax-Check aller `*.php`),
**phpunit** (Service-Suite), **test** (Migrations-Smoke +
Frontend-API-Shape + Browser-Render über `router.php`) und **docker**
(Image bauen + Container-Smoke gegen `/api/health`). Ein separater
Workflow `docker-publish.yml` veröffentlicht bei jedem Versions-Tag das
Multi-Arch-Image (amd64 + arm64) nach GHCR.

---

## 4. Bekannte Grenze

Ein echter **Headless-Chromium-Smoke** ist in der Build-Umgebung nicht
möglich (kein Browser-Binary). Chart.js ist im Test gestubbt — die
gesamte View-Logik, DOM-Erzeugung, Event-Bindung und der Backend-
Datenfluss laufen echt, **nicht** aber das tatsächliche Canvas-Chart-
Rendering. Empfehlung vor jedem Release: einmal manuell im Browser
durchklicken, besonders die Chart-haltigen Ansichten (Dashboard-
Kombichart, Verbrauchs-Monatschart, Analyse, Prognose).

---

[← Datenmodell](04-data-model.md) ·
[Release-Prozess →](06-release-process.md)
