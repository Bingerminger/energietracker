# Tests

[← Datenmodell](04-data-model.md) · [Kompendium-Index](../README.md)

Bewusst **kein** Test-Framework — konsistent mit der dependency-freien
Philosophie. Zwei sich ergänzende Harnesses unter `tests/`, Node ≥ 20 +
`jsdom` genügen.

---

## 1. `backend-shape.test.js`

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
# 1. Testdaten + Migration
cp -r demo-data /tmp/etdata
php -r 'require "src/bootstrap.php";
  (new \Energietracker\Storage\Migrator(
     new \Energietracker\Storage\JsonStore("/tmp/etdata")))->migrate();'

# 2. Server, der API UND statische JS ausliefert (router.php-Muster,
#    SCRIPT_NAME auf /api.php gesetzt — Details im tests/README.md)
php -S 127.0.0.1:8899 router.php &

# 3. Tests
node tests/backend-shape.test.js
node --import='data:text/javascript,import{register}from"node:module";\
import{pathToFileURL}from"node:url";\
register("./tests/esm-loader.mjs",pathToFileURL("./"));' \
  tests/browser-render.test.mjs
```

Beide Harnesses geben Exit-Code 0 bei Erfolg. Stand v1.4.2:
**Backend-Shape 9/9**, **Browser-Render 24/24** (inkl. Modulgraph-
Vorprüfung und Forecast-Modell-Check für alle fünf Modelle).

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
