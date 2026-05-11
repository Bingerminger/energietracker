# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/) und
[Semantic Versioning](https://semver.org/lang/de/).

---

## [1.0.2] — 2026-05-11 — Initial public release

Erste öffentliche Version des Energietrackers. Selbst-gehostete Web-App
zum Erfassen und Analysieren des privaten Energie- und Wasserverbrauchs.
Single-File-PHP-Backend, Vanilla-JS-SPA-Frontend, flat-file JSON-Persistenz.

Eine private Vorgängerversion existierte unter dem Namen *Energietracker
v0.9.0* (Backup-Format `version: "2.1"`). Backups aus dieser Version
können mit dem eingebauten Migrator importiert werden — siehe
[`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md). Außerhalb
dieses Migrationspfads ist v0.9.0 für die öffentliche Codebase irrelevant.

### Funktionen

- **Drei Verbrauchsarten parallel**: Gas, Strom, Wasser. Pro Utility
  eigene Zähler, Verträge, Tarifhistorie und Berechnungslogik.
- **Mehrere Zähler pro Utility**, jeder mit unabhängiger Vertragsserie
  (z.B. Hauptzähler + Gartenwasser-Zwischenzähler).
- **Zählertausch** als erstklassiges Datenmodell: ein Zähler bündelt
  beliebig viele Geräte (Devices) mit Seriennummer, Einbaudatum,
  Anfangs-/Endzähler, Begründung. Verbrauch wird über Tausch-Grenzen
  hinweg korrekt überbrückt.
- **Vertragshistorie** mit stichtag-genauen Arbeits- und Grundpreisen,
  monatlichen Abschlägen und Boni. Mehrere Stichtage pro Vertrag werden
  als forward-fill auf die Monatszeilen angewandt.
- **Saldo-Berechnung pro Vertrag**: aktueller Stand (Kosten −
  Abschläge) und erwarteter End-Saldo (extrapoliert über die
  verbleibenden Monate). Verdict: Erstattung, Nachzahlung, Ausgeglichen.
- **Heizgradtage** (HGT) je Monat gegen die Basistemperatur, mit
  Open-Meteo-Sync und CSV-Import für historische Tagestemperaturen
  (Format `DD.MM.YYYY"avg"min"max`, double-quote-getrennt).
- **Vier Regressionsmodelle** für HGT vs. Verbrauch: linear,
  polynomial Grad 2, robust (Huber), segmentiert
  (Heiz-/Sommerlast bei HGT = 50 als Default-Schwelle).
- **12-Monats-Forecast** als R²-gewichtete Mischung aus Regressions-
  modell und Saisonprofil.
- **Anomalie-Erkennung**: Monate mit > 2σ Abweichung vom
  Modell-Erwartungswert.
- **Backup & Restore** über die UI (Format `backup_version: "3.0"`),
  inklusive automatischer Sicherheits-Snapshots im Datenverzeichnis.
- **Migration aus v0.9.0**: ein altes Backup-Format (`version: "2.1"`)
  kann zweistufig importiert werden — erst Preview mit Übersicht, dann
  *Ersetzen* oder *Zusammenführen* (Konflikt-Resolution per ID).

### Technische Architektur

- **Backend (PHP ≥ 8.4)**: 13 Services + 11 Controllers, organisiert
  in einem kleinen App-Container (`src/bootstrap.php`). Speicher-Layer
  via `JsonStore` mit `LOCK_EX`-Schreibverbindung. Routing über einen
  kompakten Hand-rolled Router (`src/Http/Router.php`).
- **Frontend (Vanilla JS, ES Modules)**: 8 Views + 3 Komponenten + 1
  Hash-Router, 1 API-Fetch-Wrapper, 1 Lightweight-State-Cache. Kein
  Build-Step, keine Node-Toolchain nötig zur Auslieferung.
- **Daten**: flache JSON-Dateien unter `data/`. Schema-Version
  in `data/meta.json`. Atomic-write-Pattern überall.
- **Visualisierung**: Chart.js 4 per CDN-Import in `index.php`.
- **Typografie**: DM Mono (Zahlen, Datumsangaben) und DM Sans
  (Prosa) per Google Fonts.

### Datenmodell (siehe README → Datenmodell für Details)

- **Reading**: `{id, meter_id, device_id, date, counter, price_cents?,
  note, is_estimated, is_future}`
- **Meter**: `{id, name, icon, created_at, active, notes, devices: [...]}`
- **Device**: `{id, serial, installed_on, initial_counter, removed_on,
  final_counter, reason}`
- **Contract**: `{id, meter_id, provider, tariff_name, start, end, notes,
  working_prices: [{from, ct_per_kwh}], base_prices, advance_payments, bonuses}`

### Settings-Inventar (20 Schlüssel)

```
gas_conversion_factor (kWh/m³)     hdd_base_temp (°C)
co2_gas (g/kWh)                    co2_strom (g/kWh)
co2_wasser (g/m³)                  min_days_period
min_hdd_regression                 blend_max
forecast_months                    min_temp_days_forecast
forecast_model                     dashboard_months
alert_days_since_reading           anomaly_threshold
location_name                      latitude
longitude                          weather_auto_fill
wasser_personen_anzahl             wasser_personen_referenz
```

### Bekannte Einschränkungen

- HGT-Anwendung nur für Gas und Strom relevant; bei Wasser fällt der
  Forecast auf 100 % Saisonprofil zurück.
- Open-Meteo-Sync benötigt `curl` als PHP-Extension.
- Keine Mehrbenutzer- oder Auth-Schicht — Single-Tenant per Design.

[1.0.2]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.2
