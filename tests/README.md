# Tests

Zwei ergänzende Test-Harnesses (kein Test-Framework, bewusst
dependency-frei — Node ≥ 20 + `jsdom` genügt).

## backend-shape.test.js
Prüft, dass die neuen v1.3.0-Endpoints exakt die Datenstrukturen
liefern, die das Frontend erwartet. Benötigt einen laufenden
Backend-Server.

## browser-render.test.mjs
Lädt die echten View-ES-Module in JSDOM und ruft `render()` gegen den
laufenden Backend-Server auf — fängt ReferenceErrors, kaputte
DOM-Queries, Template- und Event-Binding-Fehler. Chart.js wird
gestubbt (`esm-loader.mjs`), die übrige View-Logik läuft echt.

### Ausführen
```sh
# 1. Backend-Testserver starten (Beispiel)
cp -r demo-data /tmp/etdata
php -r 'require "src/bootstrap.php"; (new \Energietracker\Storage\Migrator(new \Energietracker\Storage\JsonStore("/tmp/etdata")))->migrate();'
# router.php mit $_SERVER['SCRIPT_NAME']='/api.php' davor; siehe CI-Hinweise
php -S 127.0.0.1:8899 router.php &

# 2. Tests
node tests/backend-shape.test.js
node --import='data:text/javascript,import{register}from"node:module";import{pathToFileURL}from"node:url";register("./tests/esm-loader.mjs",pathToFileURL("./"));' tests/browser-render.test.mjs
```

Beide Harnesses geben Exit-Code 0 bei Erfolg, ≠0 bei Fehlern.

> Hinweis: Ein echter Headless-Browser-Smoke (Chromium) wird empfohlen,
> ist aber nicht Teil dieser Harnesses, da Chart.js hier gestubbt ist.
