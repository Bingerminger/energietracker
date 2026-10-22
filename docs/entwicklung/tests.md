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

Beide Harnesses geben Exit-Code 0 bei Erfolg. Stand v3.0.0:
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
- **`tests/demo.test.mjs`** (v3.0.0) — die öffentliche Demo: baut sie mit
  `tools/build-demo.mjs` (startet selbst einen PHP-Server auf 8895), liefert sie
  statisch aus (8894) und startet die echte `app.js` aus dem Bauergebnis in
  JSDOM. Dann jede Seite des Navigationsmodells: keine Anfrage ohne
  vorberechnete Antwort, kein Fehler, Kennzeichen und Hinweisleiste mit
  Sprachwahl, Rechnungsprüfung mit Vorbelegung, Quellcode-Link auf den Tag,
  Schreiben abgelehnt, CSV und PDF als Dateien. Lokal:
  `node tests/demo.test.mjs` (braucht PHP und jsdom).
- **`tests/plural.test.mjs`** (v3.1.0) — `pluralCategory()` aus
  `lib/i18n.js` (Intl.PluralRules, Portugiesisch als pt-PT) gegen
  `tests/fixtures/plural-cases.json`, dieselbe Datei, die `PluralRulesTest` im
  Backend prüft; dazu keine selbstgebauten Plurale (`=== 1 ? t(…)`) in den
  Ansichten.
- **`tests/hardcoded-text.test.mjs`** (v3.1.0) — keine Texte im Code der
  Ansichten und Komponenten: Textknoten in HTML-Vorlagen und Literale mit
  Umlaut oder ß fallen auf. Erlaubt sind Einheiten, Formelzeichen und Namen
  (Liste im Test).
- **`tests/levels.test.mjs`** (v3.2.0) — Nutzungsstufen und Schaubilder ohne
  Server: woher die Stufe kommt (Person vor Installation, unbekannt = Experte),
  welche Seite welche Stufe braucht, Navigation und CSS folgen ihr; das
  Geld-Bild rechnet mit dem Abrechnungszeitraum (`balance_path`), das
  Wetter-Bild braucht sechs Monate mit bereinigtem Wert und Vorjahr. Der
  Demo-Klicktest geht jeden Beispielhaushalt in zwei Stufen durch. Seit v3.7.0
  verlangt der Test für jede Ansicht des Routers und jede Einstellungsseite
  einen Eintrag in `VIEW_LEVEL` und liest die Funktionstabelle
  ([Was kann ich auf welcher Stufe?](../referenz/stufen.md), DE und EN): Jede
  Zeile mit Sprungmarke `<a id="level-…">` gibt die Mindeststufe (erste Spalte
  ohne „–“), Seiten gegen `VIEW_LEVEL`, Teile von Seiten gegen die Marke im
  Quelltext (`data-min-level` am selben Tag, `level:` in derselben Zeile,
  `atLeast(…)`). Dazu die Anlässe des Vorschlags aus dem Haushalt
  (`suggestReasons`) und „höchstens einmal je Anlass“. Der Render-Test prüft
  die Navigation je Stufe über den echten Router und den Vorschlag „Erfahren“
  für den Haushalt mit PV.
- **`tests/explainer.test.mjs`** (v3.4.0) — Schaubilder, zweite Fassung, ohne
  Server: die Formel des Energieflusses gegen
  `tests/fixtures/energy-flows.json` (dieselbe Datei prüft
  `PvSummaryServiceTest` in PHP), Bilanz (Sonne raus = Erzeugung, Haus =
  Zuflüsse), Geometrie R1–R5 (kein Text auf Text, auf Linien oder fremden
  Knoten, keine Kreuzung außer der erklärten Brücke, alles im Bild) über zwölf
  Fälle in allen sieben Sprachen, keine Hex-Farben und keine Emoji im Bild,
  „Bewegung reduzieren“, Hervorhebung, Jahr/Monat an Ort und Stelle; der
  Zeitstrahl mit gebauten Verträgen ohne Überlappung; Geld und Wetter mit
  Extremwerten. Textbreiten sind geschätzt (0,56 em bzw. 0,6 em für Ziffern)
  mit 10 % Reserve. Seit v3.5.0 auch der Tagesfilm in kW (R1–R5 in allen
  Sprachen, Lücke als „–“).
- **`tests/day-film.test.mjs`** (v3.5.0) — Tagesfilm des Energieflusses ohne
  Server, die API mit gebauten Antworten: Leistung = kWh × 4, aufgelaufene
  Summen nur über vollständige Viertelstunden und aus den Mengen gerechnet
  (Netzladung bleibt Netzstrom), Knoten je Tag, Leiste mit Börsenpreis und
  Lücken; „Tag“ nur mit Viertelstunden, Abspielen von selbst (15 s je Tag),
  Schieberegler mit `aria-valuetext`, Leertaste, Pfeile, Bild auf/ab, Pos1/Ende,
  Lücke als „–“, Tag/Monat wählen, typischer Tag, Zeitumstellung (100
  Viertelstunden), „Bewegung reduzieren“ ohne Abspielen und ohne Punkte.
  Server-Seite: `EnergyFlowDayTest` (Subzähler, Lücken, Zeitumstellung März und
  Oktober, typischer Tag, ohne PV, Speicher, fehlender Preis, Vorbelegung).
- **`tests/docs.test.mjs`** (v3.4.0) — das Handbuch in der App: holt die
  Freigabeliste vom echten `DocsService` (PHP-Kommandozeile) und rendert jede
  Seite mit `lib/markdown.js` und den Linkregeln aus `lib/docs.js`. Jedes
  In-App-Ziel ist freigegeben, jede Sprungmarke existiert, jedes Bild kommt
  über den Endpunkt, die Ausgabe enthält nur erlaubte Tags und Attribute, keine
  Markdown-Reste; rund 40 Angriffs-Payloads werden entschärft — im Renderer
  allein und in den Linkregeln allein; der Service Worker hält die Doku offline
  vor.
- **`tests/cache-names.test.mjs`** (v3.4.0) — führt `sw.js` in einer Sandbox
  aus und gleicht die Cache-Namen, die der Worker wirklich anlegt, mit
  `lib/cache-names.js` ab: Abmelden leert die API-Antworten dieser
  Installation, nicht die einer zweiten auf demselben Ursprung; `data/` fängt
  der Worker nicht ab.
- **`tests/forms.test.mjs`** (v3.4.0) — die aufgeräumte Oberfläche ohne
  Server: ein Formularraster im CSS (sechs Spalten, schmal zwei, Unterraster,
  Feldgruppen, Zeilenlisten mit Beschriftung nur oben), `[hidden]` gilt immer,
  keine Einzel-Flicken in den Ansichten (Versalien, `flex-end`, `.form-grid`);
  Uhrzeiten der Zeitfenster als HH:MM; der Umschalter der Darstellung zeigt
  die wirksame Darstellung mit „A“ bei Automatik und meldet jeden Schritt; die
  Doku sagt dasselbe.
- **`tests/poisoned-render.test.mjs`** (v3.4.0) — startet den Server mit den
  Demo-Daten, in denen jeder Freitext (und der Pfad des Datenverzeichnisses)
  HTML und Anführungszeichen trägt, lädt die echte `app.js` in JSDOM und ruft
  jede Seite auf. Landet ein Wert ungeschützt im DOM, entsteht ein Element mit
  `data-xss` — rot. Dazu der Selbsttest „data/ gesperrt“ unter Zugriff.
  Wählt einen freien Port selbst.
- **`tests/lists.test.mjs`** (v3.4.0) — Listen ohne und mit Server: die
  Reihenfolge der Verträge (`lib/contract-order.js`), das Blättern
  (`components/pager.js`: Schwelle 50, Seitengröße je Liste gemerkt) und die
  Abrechnungsperiode nach dem Stichtag (`lib/billing-period.js`). Dann der
  Beispielhaushalt „eigenheim-modern“ mit einem Testzähler (beendet,
  weiterlaufend) und 400 Tageswerten in der echten `app.js`: beide
  Vertragslisten in derselben Reihenfolge und mit derselben Phase, Ablesungen
  mit „N in Jahr · M gesamt“, „Alle Jahre“, Blättern und „nur auffällige“,
  Rechnung prüfen ohne Zeitraum und beim Wechsel von Art und Zähler. Port aus
  `ET_LISTS_PORT`, sonst ein freier.
- **`tests/inbox-draft.test.mjs`** (v3.7.0) — Haushalt aus Dokumenten: Texte
  und Anfrage des Entwurfs (`lib/inbox-draft.js`) ohne Server, dann ein
  erfundener Stapel (Auslesungen wie gespeichert über
  `tests/fixtures/draft-seed.php`, ohne Modell) in der echten `app.js`:
  Knopf im Posteingang, Zusammenfassung, ein Haken je Feldgruppe, nur
  Zweifelhaftes fragt, Belegstelle, „Fehlt noch“, Übernehmen mit „Rechnung
  prüfen“; „Auslesen einrichten“ als Dialog; der Assistent mit drei Wegen; die
  Anleitung DE/EN gegen den Katalog. Die Zusammenführung selbst prüfen
  `HouseholdDraftTest` und `InboxDraftEndpointTest` (PHPUnit). Port aus
  `ET_DRAFT_PORT`, sonst ein freier.
- **`tests/a11y.test.mjs`** (v3.7.0) — Barrierefreiheit der Dialoge ohne
  externe Pakete. Lädt die echte `app.js` mit einer Kopie der Beispieldaten in
  JSDOM, geht jede Seite durch, die die Navigation erreicht, und klickt jeden
  Knopf; im Dialog noch einmal jeden Knopf (Rückfragen, Fehlermeldungen beim
  Speichern ohne Eingaben). Schreibende Anfragen und Abrufe ins Netz
  beantwortet der Test selbst mit einem Fehler. Jeder Dialog, der über
  `components/modal.js` aufgeht, wird geprüft — eine Liste der Dialoge gibt es
  nicht, neue prüft er von selbst mit (rund 45 Arten, mindestens 25 verlangt):
  `role="dialog"`/`"alertdialog"` mit `aria-modal`, Hintergrund `inert`, Titel
  als Überschrift per `aria-labelledby`, Rückfrage mit Beschreibung, Fokus beim
  Öffnen im Dialog und nicht auf „×“, Fokusfalle, Escape schließt, Fokus zurück
  zum Auslöser, jedes Feld beschriftet (Platzhalter zählt nicht), jeder Knopf
  mit Namen, `aria-*`-Verweise und IDs, Fehlermeldungen als Live-Region und —
  sobald sichtbar — per `aria-describedby` am Feld mit `aria-invalid`, Kontrast
  nach WCAG AA in hell und dunkel. Den Kontrast rechnet eine kleine Kaskade aus
  den Stylesheets der App nach (Spezifität, `var()`, Vererbung, halbtransparente
  Flächen, `color-mix`), weil JSDOM weder `var()` noch Vererbung kennt. Dazu die
  Mechanik von `modal.js` an eigenen Dialogen (Startfokus, Touch, Optionsgruppen,
  `<summary>`, verschachtelte Dialoge, Auslöser nach Neuaufbau). Port aus
  `ET_A11Y_PORT`, sonst ein freier; `A11Y_DEBUG=1` listet alle Befunde, die
  Auslöser je Seite und alle Dialoge.
- **`tests/large-data.test.mjs`** (v3.7.0) — Grenzen mit großen Datenmengen:
  baut mit `tools/large-household.mjs` den Haushalt aus der
  [Messung](messung-grosse-daten.md) (zehn Jahre Tageswerte, zwei Jahre
  Viertelstunden) und ruft die Endpunkte der Hauptansichten ab. Jede Anfrage
  antwortet mit 200 und vollständigen Daten; die Antwortzeit liegt unter dem
  Zehnfachen der Messung (CI, fängt einen Rückfall in quadratische Laufzeit),
  mit `ET_PERF_STRICT=1` unter Messung + 50 % (lokal). Port aus
  `ET_LARGE_PORT`, sonst ein freier.

Hinzu kommt die **PHPUnit-Suite** für die Service-Schicht
(`tests/unit/…`, Basisklasse `ServiceTestCase`, für Tests über HTTP seit
v3.1.0 `HttpServerTestCase`): real gegen echte JSON-Dateien, ohne Mocks. Die aktuelle Zahl der Testmethoden steht im
README-Abzeichen — `ReleaseConsistencyTest` zählt sie nach (v3.0.0: 472).
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
auf eine echte Seite. Seit v3.4.0 hat jeder Anwendungsfall „## X — …“ einen
Kurztext im Katalog und jeder Kurztext seinen Anker `case-…` in DE und EN, und
die Doku reist im Docker-Image mit. `ReleaseConsistencyTest` prüft daneben
weiter die API-Referenz gegen die Routen und seit v3.4.0, dass das Frontend
GitHub nur für Fehlermeldung, Projektseite und Quellcode der Version verlinkt.
`DocsEndpointTest` (über HTTP) und `DocsServiceTest` sichern die
Freigabeliste von `/api/docs`.
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

**Ein unordentlicher Haushalt (v3.7.0).** Die Fehler aus #37, #38 und #39 fand
ein Nutzer mit echten Daten, nicht unsere Tests: Die Beispielhaushalte rechnen
den Heizverbrauch nach dem Modell der App (Konstante × Heizgradtage zur
Heizgrenze 15 °C) und werden regelmäßig abgelesen — ein Zirkelschluss, auf den
das Modell immer passt. Unter `tests/fixtures/messy-household/` liegt deshalb
ein Testhaushalt mit eigener Physik, erzeugt von `generate.mjs` (fester Seed,
`node tests/fixtures/messy-household/generate.mjs` schreibt `household.json`,
`--check` vergleicht nur): Heizen erst unter 12,5 °C, in der Übergangszeit nur
einzelne Räume, Sonnengewinne, Speichermasse mit einem Tag Verzögerung, ein
Kessel, der bei Kälte Wirkungsgrad verliert — eine konvexe Heizkurve wie in
#38. Abgelesen wird wie im echten Leben: unregelmäßig, manchmal zweimal im
Monat, über drei Monate nichts im Herbst, eine Sommerpause, eine geschätzte
Ablesung, ein Zählertausch mitten im Monat, PV-Erzeugung nur zweimal in mehr
als zwei Jahren, Strom einmal im Jahr. `MessyHouseholdTest` verlangt, dass die
App Unsicherheit zeigt statt sicher wirkender Zahlen: verteilte Monate
gekennzeichnet, keiner davon in der Abweichungstabelle, die Gütesperre greift,
der PV-Hinweis erscheint, Prognose und Erwartung verschwinden nicht still — und
Saldo und Kosten bleiben die Summe zwischen den Ablesungen.

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

**Sprachen (v3.1.0).** Eigene Tests prüfen, was Übersetzer und Entwickler
falsch machen können; die Regeln dahinter stehen unter
[Übersetzen](uebersetzen.md):

- `CatalogStyleTest` — Inhalt der Kataloge: Termbase
  (`tests/fixtures/termbase.json`, Glossar = kanonische Form), Anrede je
  Sprache, deutscher Kanon, Typografie je Sprache, Bezeichnungen nicht mitten
  im Satz, Zähl-Platzhalter nur in Pluralgruppen, Vollständigkeit einer
  Sprache (`format.*`, Pluralregel, Land), ASCII-Dateinamen der CSV-Exporte,
  Kürzel der Rechnungsprüfung. `LocaleCatalogTest` prüft daneben wie bisher die
  Struktur (gleiche Schlüssel und Platzhalter).
- `CatalogUsageTest` — jeder Katalogschlüssel wird benutzt, wörtlich oder
  über ein Präfix, das der Code zusammensetzt; ein Gegentest zeigt, dass die
  Erkennung einen toten Schlüssel findet.
- `HardcodedTextTest` — keine Meldungstexte im Backend an den Stellen, an
  denen Text beim Client ankommt (`throw`, `Response::error`, `'error' =>`,
  `$errors[] =`).
- `PluralRulesTest` — `I18nService::tp()` und `PLURAL_RULES` gegen
  `tests/fixtures/plural-cases.json`; jede Sprache aus `languages.json` hat
  eine Regel und Fälle.
- `CsvFormatV1Test` — Format 1 der CSV-Exporte Byte für Byte gegen
  `tests/fixtures/csv-format-1/` (Kopfzeile der Monatsübersicht in allen
  sieben Sprachen, Dateinamen). Neu schreiben nur mit `ET_WRITE_GOLDEN=1` —
  und dann ist es kein Format 1 mehr.
- `CsvLocalFormatTest` — Format „local“ je Sprache (Trenner, Dezimalzeichen,
  Datum, Ja/Nein, Dateiname), unbekanntes Format mit Fehlercode, und jede
  Export-Datei lässt sich wieder importieren — auch Tabellen aus anderen
  Sprachen und unmögliche oder US-Daten.
- `PdfCharsetTest` — jeder Berichtstext einer Sprache mit
  `format.pdfCharset = cp1252` kommt verlustfrei im PDF an („CO₂“ → „CO2“,
  Breiten in Zeichen); eine Sprache mit `none` gilt als nicht setzbar
  (`pdfSupported()`, die Route antwortet dann mit 422); jeder Katalog nennt
  seinen Zeichensatz.
- `DeviceLanguageTest` — gegen einen echten PHP-Server: `X-ET-Language` vor
  der Standardsprache, `Vary`, ohne Kopfzeile die Standardsprache, das
  Manifest in der gewünschten Sprache.
- `DemoDataTranslatorTest` — jeder Text der Demo-Daten ist in jede Sprache
  übersetzt (`demo-data/translations.json`), IDs, Zahlen und Firmennamen
  bleiben; seit v3.5.0 auch Notizen und Bausteine im Verlauf „Erledigt“
  (Namen von Personen bleiben).
- `PersonaUseCasesTest` (v3.5.0, N1026) — jeder Beispielhaushalt und das
  Schaufenster zeigen die neuen Anwendungsfälle: Verlauf mit allen
  Aktionsarten, Termine mit Zuständigkeit (nie mit `assignee_id`),
  Versorgerrechnungen mit dem Urteil „passt“, eine kürzlich beendete
  Periode ohne Rechnung bei Strom oder Gas (mit Stichtag relativ zu heute zu
  jedem Zeitpunkt), Folgeverträge; Viertelstunden, die je Viertelstunde
  aufgehen und zwischen zwei Ablesungen die Differenz der Stände ergeben;
  Backup-Rundlauf und die Tabelle in `docs/einstieg/einrichtung.md`.
  `DemoDataAlignerTest` prüft dazu das Fenster der Viertelstunden (bis
  gestern, nach dem Export, Zeitumstellung) und den Verlauf, der mit den
  Terminen wandert.

**Über HTTP (v3.1.0).** Kopfzeilen, Statuscodes und Zugriffsregeln sieht ein
Service-Test nicht. Dafür gibt es die Basisklasse `HttpServerTestCase`
(`tests/unit/Support/`): Sie startet `php -S … router.php` auf einem freien
Port mit eigenem Datenverzeichnis, eigener `settings.json` und eigenen
Umgebungsvariablen (`ET_AUTH` …) und räumt beides danach ab. Auf ihr bauen
`AgendaAccessTest` und `IngressTest` auf.

**Home Assistant, Kalender, Betrieb (v3.1.0):**

- `AgendaServiceTest` — die Agenda aus Terminen, Ablesungen und Verträgen
  (Art, Datum, `severity`, `due_now`); eine ausgeblendete
  Vertragserinnerung ist nicht mehr „jetzt“ fällig; der Kalender ist gültiges
  iCalendar mit stabilen UIDs, Texte werden maskiert und lange Zeilen gefaltet,
  ohne ein UTF-8-Zeichen zu teilen; der Schlüsselsatz von `/api/summary` ist
  festgeschrieben und jeder Schlüssel immer vorhanden.
- `AgendaAccessTest` — gegen einen echten PHP-Server mit eingeschalteter
  Anmeldung: `/api/summary` nur mit Schlüssel, mit `Cache-Control: private,
  max-age=300`; der Kalender nur mit einem Schlüssel des Bereichs `calendar`
  im Link; ein Lese-Schlüssel im Link wird abgelehnt, der Kalender-Schlüssel
  gilt auf keiner anderen Route.
- `BulkIngestTest` — Stapel-Ingest: ein Jahr Tageswerte schreibt jede Datei
  einmal (`JsonStore::batch()`), fehlerhafte Einträge halten die übrigen nicht
  auf und behalten ihren `index`, ein fallender Wert innerhalb des Stapels ist
  verdächtig, die Obergrenze von 500 Einträgen, das Einzelobjekt antwortet
  wie bisher.
- `IngressTest` — Betrieb unter Home-Assistant-Ingress: kein Service Worker
  und eine interne Adresse für die HA-Vorlage, wenn `X-Ingress-Path` ankommt;
  Caches und Worker gehören der eigenen Installation (Präfix `et:<scope>:`,
  die Selbstheilung meldet nur den eigenen Worker ab); `X-Remote-User-Name` und
  `X-Remote-User-Id` gelten nur vom vertrauenswürdigen Proxy.
- `DeployTemplatesTest` — die Vorlagen für Unraid, CasaOS und Umbrel
  (`deploy/`) nennen dasselbe Image, denselben Port und denselben Datenpfad
  wie `docker-compose.yml` und das Dockerfile; die Unraid-Vorlage ist gültiges
  XML und kennt jede Umgebungsvariable.
- `tests/ha-snippet.test.mjs` vergleicht seit v3.1.0 auch den Sensor-Block
  aus [Schritt 5 der Home-Assistant-Anleitung](../anleitungen/home-assistant.md)
  zeilenweise mit der Vorlage der App (`haRestSensorYaml`), ohne Kommentare,
  Adresse und Namen.

**Home-Assistant-App (v3.5.0):** Die App liegt im eigenen Repo
`energietracker-ha` (dort prüft der Linter `frenck/action-app-linter` die
`config.yaml`) und nutzt das Standard-Image unverändert. Hier wird geprüft,
was das Image dafür können muss:

- `HomeAssistantAppTest` — gegen einen echten PHP-Server mit der Umgebung der
  App (`ET_AUTH=proxy`, `ET_TRUSTED_PROXIES`): die `options.json` des
  Supervisors (Rechte 0600) lässt `/api/health` gesund; unter Ingress keine
  absoluten Pfade in der Shell, kein Service Worker, die HA-Person angemeldet
  (die erste verwaltet), keine Warnung in der Diagnose; von anderen Adressen
  zählen die Supervisor-Kopfzeilen nicht, der Push braucht seinen Token.
- `HealthCheckServiceTest` — `options.json` gilt nicht als beschädigt, eine
  eigene unlesbare Datei weiter.
- `HomeAssistantAppDocsTest` — der Auszug der `config.yaml` in der Anleitung
  ist in DE und EN gleich und passt zum Image: Port (`EXPOSE`, nginx),
  Datenpfad, Image-Name und Plattformen aus `docker-publish.yml`,
  `init: false` zu supervisord, `HEALTHCHECK` auf `/api/health`; jede
  Variable liest die App, `ET_AUTH` ist ein gültiger Modus.
- `tests/ha-snippet.test.mjs` — `haBaseUrl()` und `haUrl()`: unter Ingress
  der Hostname der App statt `/api/hassio_ingress/<token>/`, sonst die Adresse
  der Seite (auch im Unterverzeichnis); `tests/browser-render.test.mjs` prüft
  damit das Kalender-Abo unter Ingress.
- In der CI startet der Docker-Job das Image wie der Supervisor (Umgebung der
  App, Volume `/data`, `options.json` als root vor dem zweiten Start) und
  wartet auf „healthy“.

**Viertelstunden und Flexibilität (v3.3.0):**

- `IntervalServiceTest` — Migration auf 1.8.0; Plätze je Jahr mit
  Zeitumstellung (35 040, Schaltjahr 35 136, März vier Viertelstunden weniger,
  Oktober vier mehr) und Ortszeit je Platz; Werte landen in ihrer
  Viertelstunde, auch über den Jahreswechsel; Zählerstände aus Home Assistant
  (nach Minuten verteilt, nichts bei doppeltem oder fallendem Stand und über
  eine Pause von mehr als sechs Stunden); nur Reihen im Index zählen; Backup
  hin und zurück, Reihen ohne Eintrag fallen weg, unbekannte Reihen lehnt die
  Prüfung ab; Portal-Import mit Viertelstunden, auch ohne Zeitzone am Tag der
  Umstellung auf Winterzeit (100 Viertelstunden, keine überschrieben); Ingest
  mit `timestamp`; SMARD-Abruf gegen eine Attrappe (€/MWh → ct/kWh, Werte des
  Vorjahres im ersten Wochenpaket bleiben draußen; scheitert er, kommt ein
  eigener Fehlercode und nichts wird gespeichert).
- `FlexibilityTest` — Kennzahlen der Verschiebung (günstig, negativ,
  Profilfaktor, Vorteil), der Speicher mit bekanntem Ergebnis in Euro, Modul 3
  mit bekannter Ersparnis in Cent, Prüfung der Zeitfenster am Vertrag und der
  gewichtete Börsenpreis im Dynamik-Check — ein Monat unter 90 % Abdeckung
  fällt auf das Monatsmittel zurück.
- Der Render-Test baut die Seite „Flexibilität“ ohne Daten (zwei Hinweise,
  Knopf für die Börsenpreise des Jahres); der Demo-Klicktest öffnet sie mit den
  Beispielhaushalten, der Stufentest kennt ihre Mindeststufe (Experte).

**v3.4.0 — Hilfe, Sicherheit, Bericht, Verlauf, Oberfläche:**

- `DocsServiceTest`, `DocsEndpointTest` — Freigabeliste der Doku, kein Pfad
  hinaus, Weiterleitungen, Anmeldung.
- `HouseholdSecurityTest`, `HouseholdHardeningTest`, `ContainerHardeningTest`
  — Proxy-Kopfzeilen, Rollen, Sperre je Gerät, Größengrenzen, Hostliste,
  Container- und Webserver-Regeln.
- `PdfWriterTest`, `ReportModelServiceTest`, `ReportAdviceServiceTest` —
  PDF-Struktur, Grafik und Box-Protokoll (nichts über den Rand, keine
  Überlappung), Seiten je Beispielhaushalt in sieben Sprachen, Regeln der
  Handlungsempfehlungen mit Betrag und Priorität.
- `ContractPhaseTest`, `ContractPhaseEndpointTest`,
  `ContractStatusTimelineTest` — Phase eines Vertrags, Felder des Zeitstrahls.
- `ActivityServiceTest`, `ActivityAccessTest` — Folgevertrag erledigt
  Hinweise, Abhaken und Rückgängig, wer was erledigt hat, Backup-Rundreise,
  Aufbewahrung, Rollen.

Genau diese Sequenz läuft automatisiert in der **CI-Pipeline**
(`.github/workflows/ci.yml`) bei jedem Push und Pull Request gegen
`main`. Vier Jobs: **lint-php** (Syntax-Check aller `*.php`),
**phpunit** (Service-Suite), **test** (Migrations-Smoke +
Frontend-API-Shape + Browser-Render über `router.php`) und **docker**
(Image bauen + Container-Smoke gegen `/api/health`). Seit v3.1.0 laufen
**lint-php** und **phpunit** je unter PHP 8.2, 8.3 und 8.4 — der
Mindestversion, der Version von Ubuntu 24.04 und der im Docker-Image; seit
v3.4.0 auch unter 8.5 (dem Docker-Image). Der Job **docker** prüft seit v3.4.0
zusätzlich, dass php-fpm nur im Container erreichbar ist, und startet das Image
gehärtet (Beispiel aus [Docker-Betrieb](../betrieb/docker.md#gehärteter-betrieb-v340)),
seit v3.5.0 auch die Variante ohne root (`--target rootless`: ohne
Capabilities, keine UID 0, Schreiben ins Volume, klare Meldung bei fremdem
Datenordner — [Ohne root](../betrieb/docker.md#ohne-root-v350));
der Job **test** schickt die App mit präparierten Daten durch alle Seiten
(`tests/poisoned-render.test.mjs`). Alle Workflows laufen mit Leserechten,
Actions anderer Anbieter sind per Commit-SHA gepinnt. Ein
separater Workflow `docker-publish.yml` veröffentlicht bei jedem Versions-Tag
das Multi-Arch-Image (amd64 + arm64) nach GHCR, seit v3.5.0 dazu die
Variante ohne root (Tags `-rootless`); `docker-rebuild.yml`
(v3.4.0) baut den neuesten Release-Tag jede Woche neu und prüft die Images auf
bekannte Lücken (nicht blockierend, beide Varianten). `docker-armv7-probe.yml`
(v3.1.0, nur von Hand gestartet) baut das Image für `linux/arm/v7` unter QEMU
und startet es, ohne zu veröffentlichen; erst wenn er grün ist, kommt arm/v7 in
die Plattformliste.

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
