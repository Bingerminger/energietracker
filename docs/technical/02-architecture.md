# Architektur

[← Installation](01-installation.md) · [Kompendium-Index](../README.md)

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
  |  Controllers (18)        |  Services (22)                  |
  |  HTTP rein / raus        |  Fachlogik, kein HTTP           |
  +-----------------------------------------------------------+
                          |
                          v
  Storage  - JsonStore (LOCK_EX) + Migrator
                          |
                          v
  data/    - flache JSON-Dateien je Verbrauchsart
```

Es gibt **keine** Datenbank. Persistenz ist eine Menge von JSON-Dateien
unter `data/`, geschrieben mit `LOCK_EX` (exklusiver Lock), damit
parallele Requests sich nicht zerstören. Schema-Stand: **1.1.0**.

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
│       ├── router.js       # Hash-Router
│       ├── api.js          # fetch-Wrapper (BASE = 'api.php')
│       ├── state.js        # Utilities-/Settings-Cache
│       ├── lib/            # sidebar, theme, format
│       ├── components/     # chart, modal, toast
│       └── views/          # 11 Ansichten (s. UI-Referenz)
├── src/
│   ├── bootstrap.php       # DI-Container + Routen-Tabelle
│   ├── Config/Utilities.php# Verbrauchsarten — single source of truth
│   ├── Http/               # Router, Request, Response, ErrorHandler
│   ├── Storage/            # JsonStore, Migrator
│   ├── Services/ (22)      # Fachlogik (+ Pdf/PdfWriter)
│   └── Controllers/ (18)   # je Klasse eine Datei (PSR-1)
├── data/                   # Laufzeitdaten (nicht im VCS)
├── demo-data/              # vollständiger Beispieldatensatz (6 Arten)
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
[Datenmodell](04-data-model.md) und
[Grundlagen](../functional/00-overview.md)):

- **kumulativ** (Gas, Strom, Wasser, Fernwärme): Verbrauch =
  Differenz aufeinanderfolgender Zählerstände, linear über die Tage
  interpoliert.
- **lieferbasiert** (Heizöl, Pellets): Verbrauch wird energetisch aus
  Anfangsbestand + Lieferungen bilanziert und HGT-gewichtet auf die
  Monate verteilt; eine separate, kalibrierte Methode liefert die
  Tank-Bestandskurve.

---

## 4. Services (`src/Services/`, 22 + `Pdf\PdfWriter`)

Jeder Service ist `final`, hat einen dependency-injizierten Konstruktor
und kennt **kein HTTP**.

| Service | Verantwortung |
|---|---|
| `SettingsService` | Settings lesen/mergen, Typ-Casts; 50 Schlüssel |
| `MeterService` | CRUD Zähler/Tanks, Gerätetausch (Devices) |
| `ReadingService` | CRUD Ablesungen, Auto-Zuordnung zum aktiven Device |
| `ContractService` | CRUD Verträge, strikte Validierung, Stichtag-Lookup |
| `ConsumptionService` | Monatsaggregation (kumulativ **und** lieferbasiert), Saldo, Wetterbereinigung; delegiert die Liefer-Tagesverteilung an `DeliveryConsumptionService` |
| `DeliveryConsumptionService` | **(seit v1.4.4)** Tages-Verbrauchsverteilung & Tank-Bestandsabzug für Heizöl/Pellets — aus `ConsumptionService` extrahiert (~350 Zeilen) |
| `DeliveryService` | CRUD Lieferungen, Tank-Bestandskurve |
| `TemperatureService` | CSV-Import, Tages-Map |
| `WeatherService` | Open-Meteo-Wrapper (Archiv + Vorhersage) |
| `RegressionService` | 5 Modelle: linear, polynomial, robust, segmented (auto/fix), sigmoid |
| `ForecastService` | R²-gewichtete Mischung Regression × Saisonprofil; vertragsbasierte Kostenprognose |
| `AnomalyService` | Z-Score-Ausreißer |
| `BenchmarkService` | Effizienzklasse **pro Heizquelle** + kombiniert |
| `TariffComparisonService` | echte + Schattenverträge auf Ist-Verbrauch |
| `RecommendationService` | 7 statistische Regelfamilien, Dismiss-State |
| `ReminderService` | Termine/Wartung, Recurrence-Fortschreibung |
| `PdfReportService` + `Pdf\PdfWriter` | Jahresbericht, eigener PDF-Generator |
| `BackupService` | Export/Import Format 3.0, Snapshots |
| `MigrationService` | v0.9.0-Import (Preview + Apply) |
| `ReadingImportService` | CSV-Bulk-Import von Ablesungen |
| `CsvExportService` | tabellarischer Export (inkl. Lieferungen) |
| `DiagnosticsService` | Systemstatus, Schreibrechte, Datenzählung |

---

## 5. Controllers (`src/Controllers/`, 18)

Jeder Controller ist `final`, eine Klasse pro Datei. Methoden geben
`never` zurück und antworten direkt über `Response::json()` /
`Response::csv()` / `Response::error()`.

`UtilitiesController`, `SettingsController`, `TemperatureController`,
`MeterController`, `ReadingController`, `ContractController`,
`ConsumptionController`, `ForecastController`, `DeliveryController`,
`BenchmarkController`, `TariffComparisonController`,
`RecommendationController`, `ReminderController`, `ReportController`,
`ExportController`, `BackupController`, `MigrationController`,
`DiagnosticsController`.

Die vollständige Routen-Liste steht in der
[API-Referenz](03-api-reference.md).

---

## 6. Fehlerbehandlung

`Http/ErrorHandler` mappt Ausnahmen einheitlich:

| Exception | HTTP | Bedeutung |
|---|---|---|
| `InvalidArgumentException` | 400 | ungültige Eingabe |
| `RuntimeException` mit „nicht gefunden" | 404 | Ressource fehlt |
| sonstige | 500 | unerwarteter Fehler |

Antwort-Hülle einheitlich:
`{ "success": true, "data": … }` oder
`{ "success": false, "error": "…", "detail": … }`.

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
Theme-Toggle binden → `buildSidebar()` (dynamisch aus aktiven
Verbrauchsarten) → Utilities-Cache wärmen → Hash-Router starten.

> ⚠️ **Architektur-kritisch:** Da alle Module über einen einzigen
> ES-Modulgraphen geladen werden, bricht **ein einziger fehlerhafter
> relativer Import** (404) die *gesamte* App — die Oberfläche bleibt bei
> „Lade…" stehen. Genau das war Bug v1.4.1 (`sidebar.js` importierte
> `./state.js` statt `../state.js`). Der Browser-Render-Test
> (`tests/browser-render.test.mjs`) crawlt seit v1.4.1 den kompletten
> Modulgraphen über HTTP und fängt solche Fehler. Siehe
> [Tests](05-testing.md).

---

[← Installation](01-installation.md) ·
[API-Referenz →](03-api-reference.md)
