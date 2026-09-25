# Architektur

**Deutsch** · [English](../en/entwicklung/architektur.md)

[← Installation](../betrieb/installation.md) · [Kompendium-Index](../README.md)

Energietracker folgt einer klaren Schichtentrennung. Kernprinzip:
**dependency-frei, flat-file, ein Einstiegspunkt pro Verantwortung.**

---

## 1. Gesamtbild

```text
                  Browser (SPA, ES-Module)
                          |  fetch /api/...
                          v
  index.php  - liefert SPA-Huelle (HTML, laedt public/js/app.js)
  api.php    - 20-Zeilen-Einstiegspunkt -> Router
                          |
                          v
  +-----------------------------------------------------------+
  |  Controllers             |  Services                       |
  |  HTTP rein / raus        |  Fachlogik, kein HTTP           |
  +-----------------------------------------------------------+
                          |
                          v
  Storage  - JsonStore (atomar, Schreibsperre) + Migrator
                          |
                          v
  data/    - flache JSON-Dateien je Verbrauchsart
```

Es gibt **keine** Datenbank. Persistenz ist eine Menge von JSON-Dateien
unter `data/`, geschrieben mit `LOCK_EX` (exklusiver Lock), damit
parallele Requests sich nicht zerstören. Schema-Stand: **1.6.0**.

---

## 2. Verzeichnislayout

```text
energietracker/
├── api.php                 # API-Einstiegspunkt (~20 Zeilen)
├── index.php               # SPA-Shell (HTML, Favicon, Theme-Anti-Flash)
├── VERSION                 # einzige Quelle der Versionsnummer
├── public/
│   ├── css/                # tokens, app, components
│   ├── img/                # App-Icon (hell/dunkel), Favicon
│   └── js/
│       ├── app.js          # Frontend-Einstiegspunkt
│       ├── router.js       # Hash-Router (Token, Abbruch, Bereichs-Tabs)
│       ├── api.js          # fetch-Wrapper (BASE = 'api.php', Zeitlimit)
│       ├── state.js        # Utilities-/Settings-Cache, saveSettings()
│       ├── lib/            # nav-model, sidebar, mobile-nav, theme, format, contrast …
│       ├── components/     # chart, modal, toast
│       └── views/          # 15 Ansichten (s. UI-Referenz)
├── src/
│   ├── bootstrap.php       # DI-Container + Routen-Tabelle
│   ├── Config/Utilities.php# Verbrauchsarten — single source of truth
│   ├── Config/Countries.php# Länderprofile (v2.7.0) — single source of truth
│   ├── Http/               # Router, Request, Response, ErrorHandler, CrossSiteGuard
│   ├── Storage/            # JsonStore, Migrator, WriteLock
│   ├── Support/            # Dates, Encoding
│   ├── Services/           # Fachlogik (+ Pdf/PdfWriter)
│   └── Controllers/        # je Klasse eine Datei (PSR-1)
├── data/                   # Laufzeitdaten (nicht im VCS)
├── demo-data/              # vollständiger Beispieldatensatz (8 Arten)
├── docs/                   # dieses Kompendium
├── tests/                  # Test-Harnesses
└── scripts/init_data.py    # optionaler Excel-Import
```

---

## 3. Konfiguration der Verbrauchsarten

`src/Config/Utilities.php` ist die **einzige Wahrheitsquelle** für alle
Verbrauchsarten. Jede Art definiert u. a.:

| Feld | Bedeutung |
|---|---|
| `key`, `label`, `icon`, `color` | Identität und Darstellung |
| `consumption_unit` | Abrechnungseinheit (`kWh` oder `m³`) |
| `reading_kind` | `cumulative` (Zählerstände) **oder** `delivery` (Lieferungen) |
| `volume_unit` | Eingabeeinheit für Lieferungen (`L` Heizöl, `kg` Pellets) |
| `conversion_setting` | Settings-Schlüssel für die kWh-Umrechnung |
| `hgt_relevant` | ob Heizgradtage in Regression/Prognose einfließen |

Daraus ergeben sich zwei Berechnungspfade (siehe
[Datenmodell](../referenz/datenmodell.md) und
[Grundlagen](../verstehen/00-overview.md)):

- **kumulativ** (Gas, Strom, Wasser, Fernwärme): Verbrauch =
  Differenz aufeinanderfolgender Zählerstände, linear über die Tage
  interpoliert.
- **lieferbasiert** (Heizöl, Pellets): ein **Tankbuch** (seit v2.10.0) —
  Anfangsbestand, Lieferungen „bis voll getankt" und Peilstände sind
  Stützstellen; dazwischen wird der Verbrauch HGT-gewichtet verteilt, danach
  mit der kalibrierten Rate geschätzt. Verbrauch, Kosten und
  Tank-Bestandskurve kommen aus derselben Rechnung
  (`DeliveryConsumptionService::tankModel()`).

---

## 4. Services (`src/Services/` und `Pdf\PdfWriter`)

Jeder Service ist `final`, hat einen dependency-injizierten Konstruktor
und kennt **kein HTTP**.

| Service | Verantwortung |
|---|---|
| `SettingsService` | Settings lesen/mergen, Typ-Casts; Defaults in `DEFAULTS`; je Datenstand zwischengespeichert (v2.6.0) |
| `ConversionFactorService` | datierte Gas-Faktoren (F1012), tagesgenau |
| `I18nService` | Kataloge, `t()`, Sprache aus Einstellung bzw. `Accept-Language`; ordnet Meldungen ihrem Fehlercode zu (v2.6.0) |
| `MeterService` | CRUD Zähler/Tanks, Gerätetausch, Topologie (Subzähler/Gruppen, F1006) + `external_id`-Alias (F1009); `countsInTotals()`/`inService()`: ein Zähler außer Betrieb zählt in Summen, nicht in Erfassung und Warnungen (v2.9.0) |
| `ReadingService` | CRUD Ablesungen, Auto-Zuordnung zum aktiven Device; Erfassungsübersicht mit typischem Tagesverbrauch; Sammel-Upsert für den CSV-Import (v2.6.0) |
| `ContractService` | CRUD Verträge, strikte Validierung, Stichtag-Lookup; seit v2.9.0 tagesgenaue Abschnitte (`segmentsBetween`), weiterlaufender Vertrag (`resolveForDate`), Kündigungsstichtag (`switchTiming`) |
| `ConsumptionService` | Monatsaggregation (kumulativ **und** lieferbasiert), Saldo nach Kalender, Heizmodell und Wetterbereinigung (v2.8.0); Verträge tagesgenau mit `contract_parts` (v2.9.0); delegiert die Liefer-Tagesverteilung an `DeliveryConsumptionService`; seit v2.6.0 Plausibilität (Ausreißer, Verdacht, Überlauf) mit `warnings` |
| `DeliveryConsumptionService` | **(seit v1.4.4)** Heizöl/Pellets — aus `ConsumptionService` extrahiert; seit v2.10.0 Tankbuch (`tankModel()`): Stützstellen, eine Rechnung für Verbrauch, Kosten und Bestand, Klimanormal für fehlende Tage |
| `DeliveryService` | CRUD Lieferungen, Tank-Bestandskurve |
| `TemperatureService` | CSV-Import, Open-Meteo-Abgleich mit Quelle je Tag, täglicher Auto-Sync (v2.8.0) |
| `WeatherService` | Open-Meteo-Wrapper (Archiv, Vorhersage, 30-Jahres-Tagesmittel, seit v2.12.0 Ortssuche) hinter dem Interface `WeatherSource` |
| `ClimateNormalService` | **(v2.8.0)** Klimanormal am Standort: HGT-Mittel und -Streuung je Kalendermonat aus 30 Jahren |
| `RegressionService` | 5 Modelle: linear, polynomial, robust, segmented (auto/fix), sigmoid |
| `ForecastService` | R²-gewichtete Mischung Regression × Saisonprofil, HGT aus dem Klimanormal, Unsicherheitsband; vertragsbasierte Kostenprognose |
| `AnomalyService` | Ausreißer gegen die Erwartung je Monat, robuste Streuung (v2.8.0) |
| `BenchmarkService` | Effizienzklasse **pro Heizquelle** + kombiniert; seit v2.10.0 Abdeckung je Quelle, Klassen nur für ganze Jahre, energieausweis-nahe Kennzahl (`certificate`), Wärmepumpen-Strom |
| `TariffComparisonService` | echte + Schattenverträge auf Ist-Verbrauch |
| `TariffSwitchService` | Wechselentscheidung ab Wechseltermin (Bindungskette, Break-even) |
| `RecommendationService` | 7 statistische Regelfamilien, Dismiss-State |
| `ReminderService` | Termine/Wartung, Recurrence-Fortschreibung |
| `PdfReportService` + `Pdf\PdfWriter` | Jahresbericht, eigener PDF-Generator |
| `BackupService` | Export/Import Format 3.0 mit Prüfung vor dem Schreiben und Rückweg; Snapshots (Liste, Download, Einspielen, Rotation) |
| `MigrationService` | v0.9.0-Import (Preview + Apply) |
| `ReadingImportService` | CSV-Bulk-Import von Ablesungen |
| `CsvExportService` | tabellarischer Export (inkl. Lieferungen) |
| `DiagnosticsService` | Systemstatus, Schreibrechte, Datenzählung |
| `HealthCheckService` | `/api/health`: `status` ok/degraded/error, Prüfungen (Schreibrechte, Schema, Dateien, Platz, Temp-Dateien), letzter Ingest — N1003, v2.6.0 |
| `DemoService` | Ein-Klick-Demo-Import über den Restore-Pfad — F1007 |
| `PvSummaryService` / `StromSaldoService` | PV-Eigenverbrauch/Autarkie bzw. Strom-Saldo — F1005; seit v2.10.0 Quoten über gemeinsam abgedeckte Monate und Ersparnis durch Eigenverbrauch |
| `AuthService` | Anmeldung (Passwort, Proxy, Sitzungen, Sperre), API-Schlüssel und HA-Token — nur Hashes in `data/auth.json` (F1009, v2.6.0) |
| `IngestService` | idempotenter Push-Eingang (`/api/ingest`, upsert-by-date) — F1009 |

---

## 5. Controllers (`src/Controllers/`)

Jeder Controller ist `final`, eine Klasse pro Datei. Methoden geben
`never` zurück und antworten direkt über `Response::json()` /
`Response::csv()` / `Response::error()`.

`UtilitiesController`, `SettingsController`, `TemperatureController`,
`MeterController`, `ReadingController`, `ContractController`,
`ConsumptionController`, `ForecastController`, `DeliveryController`,
`BenchmarkController`, `TariffComparisonController`,
`TariffSwitchController`, `RecommendationController`, `ReminderController`, `ReportController`,
`ExportController`, `BackupController`, `MigrationController`,
`DiagnosticsController`, `HealthController`, `DemoController`,
`PvSummaryController`, `StromSaldoController`, `AuthController`,
`IngestController`, `SessionController`.

*(Hinweis: Gruppen-Endpoints aus F1006 liegen im `MeterController`,
Auth/Ingest aus F1009 in `AuthController`/`IngestController`, Anmeldung und
API-Schlüssel (v2.6.0) im `SessionController`.)*

**Anmelde-Schranke (v2.6.0).** Vor dem Routing prüft `App` in
`bootstrap.php` Hostnamen (`ET_ALLOWED_HOSTS`), fremde Browser-Anfragen
(`CrossSiteGuard`) und — bei eingeschalteter Anmeldung — Sitzung,
API-Schlüssel oder Proxy-Benutzer. Öffentlich bleiben Ingest, Health (in
Minimalform) und die Anmeldung selbst. Details:
[Sicherheit](../betrieb/sicherheit.md).

Die vollständige Routen-Liste steht in der
[API-Referenz](../referenz/api.md).

---

## 6. Fehlerbehandlung

`Http/ErrorHandler` mappt Ausnahmen einheitlich:

| Exception | HTTP | Bedeutung |
|---|---|---|
| `InvalidArgumentException` | 400 | ungültige Eingabe |
| `Http\NotFoundException` | 404 | Ressource fehlt (seit v2.2.1 als Typ statt Textmuster; seit v2.6.0 auch für Datensätze in der URL) |
| `Http\ConflictException` | 409 | Konflikt, z. B. Sicherungs-Snapshot gescheitert (v2.6.0) |
| `Storage\StorageCorruptedException` | 503 | Datendatei beschädigt (v2.5.3) |
| sonstige | 500 | unerwarteter Fehler — generische Meldung mit `error_id`, Einzelheiten im Log (v2.6.0) |

Antwort-Hülle einheitlich:
`{ "success": true, "data": … }` oder
`{ "success": false, "error": "…", "code": "errors.…" }`. `code` ist der
Katalogschlüssel der Meldung (`I18nService::errorCodeFor()`), sonst
`errors.http.*` nach Statuscode; `detail` mit Datei/Zeile nur bei
`ET_DEBUG=1`. Der Router beantwortet `HEAD` wie `GET`, eine falsche Methode
mit `405` und `Allow`, `OPTIONS` mit `204`.

### 6.1 Speicher-Pfad-Sicherheit (seit v1.4.4)

`JsonStore::path()` baut den Dateipfad aus `rootDir` plus relativem
Schlüssel. Zusätzlich zur Whitelist-Prüfung im Service-Layer
(`Utilities::exists()` lässt nur bekannte Energieart-Schlüssel zu) prüft
`path()` per `realpath` + Präfix-Vergleich, dass der aufgelöste Pfad
**innerhalb** von `rootDir` liegt. Jeder Versuch, über `../` aus dem
Datenverzeichnis auszubrechen, wirft eine `InvalidArgumentException`
(→ HTTP 400). Defense-in-Depth: der Schutz greift auch dann, wenn ein
künftiger Endpunkt die Service-Layer-Validierung umgehen sollte.

---

## 7. Frontend

Reine ES-Module, **kein Build-Schritt**. `app.js` ist der Einstieg:
Theme-Toggle binden → Sprache laden → Utilities-Cache wärmen →
`buildSidebar()` (dynamisch aus aktiven Verbrauchsarten) → Hash-Router
starten.

**Navigation (seit v2.11.0).** `lib/nav-model.js` ist die eine Quelle für
Seitenleiste, Tab-Leiste (iPhone, `lib/mobile-nav.js`) und die Tabs der
Bereiche. Der Router meldet jede Navigation als Ereignis `et:route`
(Ansicht, Bereich, Verbrauchsart); Seitenleiste und Tab-Leiste setzen daraus
ihre Markierung.

**Router (seit v2.11.0).** Jede Navigation bekommt ein Token, ein
Abbruchsignal und einen eigenen Container im `#view`:

- Der Cleanup der alten Ansicht läuft, bevor die neue startet.
- Eine verspätete Ansicht schreibt in ihren ausgehängten Container. Ihr
  Cleanup läuft, sobald sie fertig ist.
- Leseanfragen (GET) der verlassenen Ansicht bricht `api.js` ab; ihr Promise
  bleibt offen, die Ansicht läuft nicht weiter und meldet keinen Fehler.
  Schreibzugriffe laufen immer zu Ende.
- App-weite Abrufe (`state.js`, Zähler der Seitenleiste) laufen über
  `appScope()` ohne Signal.
- Ansichten werden per `import()` geladen; die Import-Map versioniert auch
  diese Pfade. Nach dem ersten Bild lädt der Router die übrigen im Leerlauf
  vor, damit sie offline im Cache des Service Workers liegen.
- `#/pfad?schlüssel=wert` reicht die Query als `ctx.query` an die Ansicht:
  `render(container, params, ctx)`.

**Einstellungen** schreiben die Ansichten über `state.saveSettings(patch)`:
PATCH, Zwischenspeicher aktualisieren, Ereignis `et:settingschange`. Die
Seitenleiste baut sich bei geänderten `active_utilities` neu auf. Nach
Demo-Daten, Import oder Wiederherstellung lädt die App komplett neu.

**Dialoge** legen beim Öffnen einen History-Eintrag an: Die Zurück-Taste
schließt den obersten Dialog statt die Seite. Schließt er anders, nimmt er
den Eintrag mit `history.back()` zurück — außer bei einer Navigation, die
sonst rückgängig gemacht würde.

**Diagramme (seit v2.15.0).** Chart.js 4.5.1 liegt unter `public/vendor/`
(unverändert aus dem npm-Paket, Integrität gegen die Registry geprüft) und
kommt als globales `Chart`. Alle Ansichten zeichnen über
`components/chart.js`:

- `makeChart(canvas, config, {label})` trägt jedes Chart in eine Registry
  ein. Ein Canvas trägt genau eins; beim Ereignis `et:route` räumt die Schicht
  ab, was die alte Ansicht liegen ließ. Ein Fehler von Chart.js landet in der
  Konsole, nicht in einem Toast.
- Farben stehen in den Konfigurationen als Funktionen: `utilColor(u, alpha)`
  (Farbe der Verbrauchsart, je Theme getönt über `themedColor` aus
  `lib/utility-theme.js`) und `tokenColor(name)` (Theme-Token). Beim
  Ereignis `et:themechange` setzt die Schicht die Vorgaben neu, löst die
  Achsenfarben, die Chart.js beim Anlegen kopiert hat, und zeichnet jedes
  offene Chart neu.
- `chartTableHtml()` liefert die Zahlen eines Diagramms als aufklappbare
  Tabelle.

`lib/chart-data.js` hält die Regeln für Monatsreihen, ohne DOM:
`isPartial()` (Teilmonat, `days` kleiner als der Monat), `yoyTrend()`
(dieselben vollen Monate ein Jahr zuvor, witterungsbereinigt, wenn alle einen
Wert tragen), `lastMonths()` und `seriesSummary()` für Kurzbeschreibungen.

> ⚠️ **Architektur-kritisch:** Da alle Module über einen einzigen
> ES-Modulgraphen geladen werden, bricht **ein einziger fehlerhafter
> relativer Import** (404) die *gesamte* App — die Oberfläche bleibt bei
> „Lade…" stehen. Genau das war Bug v1.4.1 (`sidebar.js` importierte
> `./state.js` statt `../state.js`). Der Browser-Render-Test
> (`tests/browser-render.test.mjs`) crawlt seit v1.4.1 den kompletten
> Modulgraphen über HTTP und fängt solche Fehler. Siehe
> [Tests](tests.md).

---

[← Installation](../betrieb/installation.md) ·
[API-Referenz →](../referenz/api.md)
