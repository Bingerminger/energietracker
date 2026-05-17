# Tests

Zwei ergänzende Test-Harnesses (kein Test-Framework, bewusst
dependency-frei — Node ≥ 20 + `jsdom` genügt).

## frontend-api-shape.test.js
Prüft, dass die Backend-Endpoints exakt die Datenstrukturen liefern,
die das Frontend erwartet. Benötigt einen laufenden Backend-Server.
(Umbenannt von `backend-shape.test.js` in v1.4.4 — der Name spiegelt
jetzt die tatsächliche Perspektive wider: Frontend-seitige Erwartungen
an die API-Shapes.)

## browser-render.test.mjs
Lädt die echten View-ES-Module in JSDOM und ruft `render()` gegen den
laufenden Backend-Server auf — fängt ReferenceErrors, kaputte
DOM-Queries, Template- und Event-Binding-Fehler. Chart.js wird
gestubbt (`esm-loader.mjs`), die übrige View-Logik läuft echt.

### Ausführen
```sh
# 1. Backend-Testserver starten (Beispiel)
cp -r demo-data /tmp/etdata
php -S 127.0.0.1:8899 -t . api.php &

# 2. Tests
node tests/frontend-api-shape.test.js
node --import='data:text/javascript,import{register}from"node:module";import{pathToFileURL}from"node:url";register("./tests/esm-loader.mjs",pathToFileURL("./"));' tests/browser-render.test.mjs
```

Beide Harnesses geben Exit-Code 0 bei Erfolg, ≠0 bei Fehlern.

> Hinweis: Ein echter Headless-Browser-Smoke (Chromium) wird empfohlen,
> ist aber nicht Teil dieser Harnesses, da Chart.js hier gestubbt ist.
