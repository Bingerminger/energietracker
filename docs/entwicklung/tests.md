# Tests

**Deutsch** · [English](../en/entwicklung/tests.md)

[← Datenmodell](../referenz/datenmodell.md) · [Kompendium-Index](../README.md)

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
den laufenden Backend-Server auf. Chart.js selbst ersetzt ein
aufzeichnendes Stub auf `window.Chart` (JSDOM hat kein Canvas); die
Chart-Schicht `components/chart.js` und die übrige View-Logik (DOM-Aufbau,
Events, Datenfluss) laufen echt. Bis v2.14 ersetzte `esm-loader.mjs` auch die
Chart-Schicht durch einen Stub — sie lief im Test nie; heute biegt der Loader
nur noch die API-Adresse auf den Testserver. Fängt: ReferenceErrors, kaputte
DOM-Queries, Template-Fehler, Event-Binding-Fehler.

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

Beide Harnesses geben Exit-Code 0 bei Erfolg. Stand v2.16.0:
**Frontend-API-Shape 62/62**, **Browser-Render 189/189** (inkl. Modulgraph-
Vorprüfung und Forecast-Modell-Check für alle fünf Modelle). Der
Modulgraph-Crawl folgt seit v2.11.0 auch dynamischen Importen — der Router
lädt die Ansichten erst bei Bedarf. Seit v2.12.0 rendert der Test jede
Einstellungs-Unterseite einzeln, übergibt Ansichten eine Query (Direktsprung
der Erfassung) und löst Speichern und Feldfehler in Dialogen aus. Der
Shape-Test ruft den Import-Trockenlauf und das Wieder-Einblenden einer
Empfehlung auf — gegen die Demo-Kopie, die das Skript danach verwirft.
Seit v2.13.0 öffnet der Render-Test die ⓘ-Erklärungen per Klick und schließt
sie mit Escape, rendert die Hilfe mit Sprung zu einem Begriff und prüft die
PV-Ansichten auf Vergütung statt Kosten und den Saldo auf die Kundensicht.
Seit v2.15.0 prüft er an den aufgezeichneten Diagrammen: Farben als
Funktionen, der Teilmonat blass mit „14 von 31 Tagen“ im Tooltip,
Kurzbeschreibungen, Datentabellen, Jahr und Zähler aus der Adresse, zwei
schnelle Prognoseläufe mit einem Chart.

Ohne Server laufen `tests/format.test.mjs`, `tests/ha-snippet.test.mjs`,
`tests/plausibility.test.mjs` und seit v2.11.0:

- **`tests/router.test.mjs`** — JSDOM, erfundene Ansichten, ein gestelltes
  `fetch` mit Verzögerung. Geprüft wird: Eine langsame Ansicht überschreibt
  die schnellere nicht, der Cleanup verlassener Ansichten läuft, Leseanfragen
  werden abgebrochen, die Query kommt an, Fehler bieten „Erneut versuchen",
  und die Zurück-Taste schließt einen Dialog, ohne die Seite zu verlassen.
- **`tests/contrast.test.mjs`** — liest die Farb-Token aus `tokens.css` und
  die Farben der Verbrauchsarten aus `Utilities.php` und prüft alle Paare
  aus Schrift und Fläche in beiden Themes gegen WCAG AA (4,5:1).
- **`tests/chart.test.mjs`** (v2.15.0) — die Chart-Schicht mit einem
  aufzeichnenden Stub: ein Chart je Canvas, Aufräumen beim Seitenwechsel,
  Neuzeichnen beim Theme-Wechsel samt Achsenfarben, kein Wurf bei einem
  Chart.js-Fehler, jede Chartfarbe (Verbrauchsarten und Paletten) mit 3:1 auf
  der Karte in beiden Themes; dazu die Monatsregeln aus `lib/chart-data.js`
  (Teilmonat, Trend gegen dieselben Monate des Vorjahres, witterungsbereinigt
  nur, wenn alle Monate einen Wert tragen).

Hinzu kommt die **PHPUnit-Suite** für die Service-Schicht
(`tests/unit/…`, Basisklasse `ServiceTestCase`): real gegen echte
JSON-Dateien, ohne Mocks. Die aktuelle Zahl der Testmethoden steht im
README-Abzeichen — `ReleaseConsistencyTest` zählt sie nach (v2.16.0: 459).
`LocaleCatalogTest` prüft seit v2.13.0 auch Schlüssel, die der Code
zusammensetzt (`glossary.<id>.term`, `settings.field.<key>.label`) — die
Prüfung auf literale Schlüssel sieht sie nicht. Seit v2.14.0 kennt er
Pluralformen (`one`/`other` und die Zusatzkategorien einzelner Sprachen) und
prüft, dass jeder Schlüssel, den der Code an `tp()` übergibt, sie in jeder
Sprache hat.

**Doku (v2.14.0).** `DocsIntegrityTest` liest jede Markdown-Datei unter
`docs/` und die Dateien im Wurzelverzeichnis und prüft: Jeder relative Link
und jedes Bild zeigt auf eine vorhandene Datei, in exakter Schreibweise (GitHub
unterscheidet Groß und klein, macOS nicht); jeder Anker existiert im Ziel;
jede deutsche Seite hat ihren englischen Spiegel unter demselben Pfad;
niemand verlinkt eine Weiterleitung; der Index führt jede Seite; die
[Einstellungsreferenz](../referenz/einstellungen.md) nennt jeden Schlüssel aus
`SettingsService::DEFAULTS`; und jeder Doku-Link der App (`lib/docs.js`) zeigt
auf eine echte Seite. `ReleaseConsistencyTest` prüft daneben weiter die
API-Referenz gegen die Routen.
Ausführen mit `vendor/bin/phpunit --no-coverage`. Sie ist das
**Pflicht-Gate vor jedem Commit** (siehe
[Release-Prozess](release-prozess.md)).

**Rechenkerne mit synthetischen Daten (v2.8.0).** `WeatherModelTest` erzeugt
Temperaturen und Verbrauch nach einer bekannten Formel
(`Verbrauch = a × HGT + c × Tage`) und prüft, dass Heizmodell, Bereinigung,
Anomalien, Trend, Prognose und Saldo sie wiederfinden — und nichts melden, wo
nichts ist. `TemperatureSyncTest` ersetzt Open-Meteo durch eine Attrappe
(Interface `WeatherSource`) und prüft, welche Werte ein Abgleich überschreiben
darf. Jede neue Regel bekam eine **Gegenprobe**: Code gezielt zurückdrehen,
der Test muss rot werden. Mehrere Tests waren zunächst auch ohne ihre Regel grün
(zu glatte Testdaten) und wurden erst dadurch aussagekräftig.

**Verträge tagesgenau (v2.9.0).** `ContractDayAccurateTest` prüft Monate mit
Preisänderung, Vertragswechsel und Lücke, den weiterlaufenden und den
gekündigten Vertrag, Kündigungsstichtag und Erinnerung, verpasste Fristen,
Preiserhöhungen, anteilige Abschläge und die Prognose. `TotalsAndSettingsRulesTest`
hält fest, dass ein Zähler außer Betrieb in jeder Summe zählt, und prüft die
Abrechnungsstichtage.

**Energieträger (v2.10.0).** `TankModelTest` baut ein Klima mit Winter und
Sommer und prüft das Tankbuch: Eine Lieferung von heute ändert die
Vorjahre nicht, zwischen Anfangsbestand und Peilstand ist der Verbrauch
bekannt, Kurve und Verbrauch sind eine Reihe, der Tank mischt Preise, ein
Sommerintervall trägt Grundlast, widersprüchliche Stände werden gemeldet,
fehlende Temperaturen kommen aus dem Klimanormal. `Co2FactorsTest` prüft
Strom je Jahr, die Festschreibung alter Defaults bei Bestandsinstallationen
und das Angebot der neuen, `PvSemanticsTest` Quoten, Ersparnis, Tarifrang und
Jahresbericht, `EfficiencyCertificateTest` Teiljahre, Grenzen und die
energieausweis-nahe Kennzahl. 36 Gegenproben, alle rot.

**Saldo-Verlauf (v2.16.0).** `BalancePathTest` legt einen Vertrag über zwölf
Monate an, der vor acht begann, und prüft die Monatsreihe `balance_path`: der
letzte Punkt ist der erwartete Endsaldo, Abschläge wachsen nach Plan, ein
gemessener Monat kostet Arbeitspreis plus Grundpreis, eine Rückzahlung
vermindert das Bezahlte in ihrem Monat, und nur der laufende Vertrag trägt eine
Reihe. Der Render-Test prüft dazu Vorjahr und Umschalter im Monatschart (die
bereinigten Werte müssen `heat_adjusted` sein), den PV-Energiefluss, die
kleinen Vielfachen der Übersicht und das Temperaturband.

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
möglich (kein Browser-Binary). Chart.js ist im Test ein Stub — die
gesamte View-Logik samt Chart-Schicht, DOM-Erzeugung, Event-Bindung und der
Backend-Datenfluss laufen echt, **nicht** aber das tatsächliche
Canvas-Chart-Rendering. Empfehlung vor jedem Release: einmal manuell im Browser
durchklicken, besonders die Chart-haltigen Ansichten (Dashboard-
Kombichart, Verbrauchs-Monatschart, Analyse, Prognose).

---

[← Datenmodell](../referenz/datenmodell.md) ·
[Release-Prozess →](release-prozess.md)
