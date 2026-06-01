<p align="center"><img src="public/img/icon-light-180.png" alt="Energietracker" width="96"></p>

# Energietracker

[![Version](https://img.shields.io/badge/version-1.8.0-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.4-777BB4.svg)](#requirements)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Selbst-gehostete Web-Anwendung zum Erfassen und Analysieren des eigenen
Energie- und Wasserverbrauchs. Single-File PHP-Backend, Vanilla-JS SPA-Frontend,
flat-file JSON-Persistenz — keine Datenbank, kein Server-Setup, keine externen
Abhängigkeiten zur Laufzeit (außer Chart.js per CDN).

Bis zu acht Verbrauchsarten parallel: **Gas**, **Strom**, **Wasser**,
**Fernwärme**, **Heizöl** und **Pellets** (Heizöl/Pellets lieferbasiert
statt zählerbasiert), plus seit v1.7.0 **PV-Einspeisung** und
**PV-Erzeugung** für Photovoltaik-Eigentümer (kombinierter Strom-Saldo,
Autarkiequote). Pro Verbrauchsart: mehrere Zähler mit voll
modelliertem Zählertausch, beliebige Vertragshistorie mit
Tarifänderungen, Grundpreis-Verlauf, Abschlägen und Boni. Daraus
berechnet die App Monatsverbräuche (linear interpoliert bzw. energetisch
bilanziert), Heizgradtage gegen das lokale Klima, fünf Regressionsmodelle
(linear, polynomial, robust, segmentiert mit datenbasiertem Knickpunkt,
Sigmoid), Wetterbereinigung, eine Effizienzklasse (kWh/m²·a) und einen
Saldo pro Vertrag — sowohl aktueller Stand als auch erwartete
End-Saldierung. Dazu eine statistische Empfehlungs-Engine,
Termin-/Wartungsverwaltung, Tarifvergleich mit Schattenverträgen und ein
PDF-Jahresbericht.

> **Status:** v1.8.0 ist die aktuelle öffentliche Version (initial release war v1.0.2). Wer aus einem privat
> betriebenen v0.9.0-Backup migrieren möchte, findet die Anleitung unter
> [Migration aus v0.9.0](docs/MIGRATION-FROM-V090.md) — das Backup-Format
> v0.9.0 wird vom Migrator unterstützt.

> 📚 **Vollständige Dokumentation:** Das getrennte technische und
> fachliche Kompendium (Installation, API, Datenmodell, je Energieart
> mit Formeln, Anwender-Szenarien, UI-Referenz) liegt unter
> **[`docs/`](docs/README.md)**.

---

## Inhalt

- [Funktionen](#funktionen)
- [Dokumentation](#dokumentation)
- [Schnellstart](#schnellstart)
- [Datenmodell](#datenmodell)
- [Verzeichnisstruktur](#verzeichnisstruktur)
- [Migration aus v0.9.0](#migration-aus-v090)
- [Weiterführende Dokumentation](#weiterführende-dokumentation)
- [Mitwirken & Lizenz](#mitwirken--lizenz)

---

## Funktionen

### Dateneingabe

- **Zählerstand-Tabelle** pro Utility mit Inline-Edit, Löschen, optionalen
  Anmerkungen pro Ablesung und einem „geschätzt"-Flag für Korrekturen.
- **Zukunfts-Readings** zum Vormerken geplanter Abrechnungstermine
  (werden bei der Verbrauchsberechnung ignoriert, bleiben aber sichtbar).
- **Zählertausch** als erstklassiges Datenmodell: ein Zähler bündelt
  einen oder mehrere Geräte (Devices), inkl. Seriennummer, Einbaudatum,
  Anfangs-/Endzähler und Begründung. Verbrauch wird über Tausch-Grenzen
  hinweg korrekt berechnet (`(altes_final − vorheriges_reading) +
  (aktuelles_reading − neues_initial)`).
- **Mehrere Zähler pro Utility** mit unabhängigen Verträgen
  (z.B. Hauptzähler + Gartenwasser-Zwischenzähler).
- **Temperatur-Import** als CSV (Format
  `DD.MM.YYYY"avg"min"max`, double-quote-getrennt) oder per Open-Meteo-Sync
  über Standort-Koordinaten in den Einstellungen.
- **CSV-Import von Ablesungen** je Zähler: eine Datei mit
  `datum;zählerstand;notiz;geschätzt` einlesen — vorhandene Ablesungen am
  selben Datum werden überschrieben und im Ergebnis gemeldet.

### Verträge und Saldo

- **Vertragshistorie** pro Zähler: Anbieter, Tarifname, Start/Ende,
  freier Notiztext.
- **Stichtag-genaue Preishistorie** für Arbeitspreis (ct/Verbrauchseinheit),
  Grundpreis (€/Monat) und monatlichen Abschlag (€). Mehrere Stichtage
  pro Vertrag werden als forward-fill auf die Monate angewandt.
- **Boni** mit Gutschriftdatum, Betrag und Label
  (Neukunden-, Treuebonus, etc.).
- **Saldo-Berechnung** pro Vertrag:
  - *Aktueller Saldo* = bereits angefallene Kosten − bisher bezahlte Abschläge
  - *Erwarteter End-Saldo* = aktueller Saldo + (verbleibende Monate × ø
    Monatskosten − Monatsabschlag). Offene Verträge werden bis zum
    nächsten Abrechnungsstichtag projiziert (je Utility konfigurierbar,
    Default 1. Januar).
  - *Verdict*: Erstattung / Nachzahlung / Ausgeglichen mit Schwellwert ±5 €
- **Erinnerung an Vertragsende**: Verträge, deren Ende innerhalb einer
  konfigurierbaren Frist liegt (drei Stufen, Default 90 / 30 / 1 Tage),
  werden in der Korrelations-Ansicht als gestufter Hinweis angezeigt.

### Analyse

- **Heizgradtage** (HGT) je Monat gegen die hinterlegte Basistemperatur
  (Default 15 °C, in Einstellungen anpassbar).
- **Vier Regressionsmodelle** auf HGT vs. Verbrauch:
  - *Linear*: klassische OLS-Anpassung, schnell und robust für gleichmäßig
    geheizte Haushalte
  - *Polynomial Grad 2*: erfasst nicht-lineare Effekte (z.B. Sommer-Grundlast
    durch Warmwasser)
  - *Robust (Huber)*: iterativ neu-gewichtete OLS, ignoriert Ausreißer
  - *Segmentiert*: zwei separate lineare Anpassungen oberhalb/unterhalb eines
    Schwellwerts (typisch HGT = 50), unterscheidet Heizperiode von Sommerlast
- **Anomalien**: Monate, in denen der Verbrauch mehr als 2σ (anpassbar) vom
  Modell-Erwartungswert abweicht.
- **Forecast** über 12 Monate als R²-gewichtete Mischung aus
  Regressionsmodell und Saisonprofil. Die Kostenprognose ist
  vertragsbasiert: pro Monat wird der dann gültige Arbeits- und
  Grundpreis aus der Vertragshistorie verwendet, plus projizierter
  Abschlag und laufender Saldo.
- **Jahresvergleich mit Monatsdeltas**: Monat-für-Monat-Differenz der
  beiden jüngsten Jahre, absolut und prozentual.
- **Wasser-Spar-Index** `(Liter/Person/Tag) / Referenz × 100` mit
  konfigurierbaren Bandgrenzen.

### Lieferbasierte Energieträger (v1.3.0)

- **Heizöl & Pellets** werden über **Lieferungen** statt Zählerständen
  erfasst (Datum, Menge, Preis/Einheit oder Gesamtbetrag, Lieferant,
  Notiz, „geplant"-Flag). Der Monatsverbrauch wird energetisch
  bilanziert und über einen Sockelanteil plus HGT-Gewichtung verteilt.
- **Tank-Bestandskurve**: modellierter Restbestand
  (Anfangsbestand + Lieferungen − HGT-gewichteter Verbrauch) mit
  Warnschwelle. Eine Schätzung, keine Tankpeilung.
- **Fernwärme** als zusätzliche kumulative, HGT-relevante Verbrauchsart.

### Auswertung & Insights (v1.3.0)

- **Wetterbereinigung**: „mehr verbraucht oder nur kälter?" — Verbrauch
  normiert auf das langjährige Kalendermonats-HGT, plus
  Regressionserwartung und Abweichung in Prozent.
- **Effizienzklasse** A+…H aus dem Heizenergiebedarf in kWh/m²·a,
  konfigurierbare Bandgrenzen, Wohnfläche/Baujahr/Gebäudetyp pflegbar.
- **Empfehlungs-Engine**: sieben statistische Regelfamilien aus den
  Eigendaten, mit Schweregrad und einzeln ausblendbar.
- **Sigmoid- und auto-segmentiertes Regressionsmodell** zusätzlich zu
  linear/polynomial/robust.

### Verwaltung (v1.3.0)

- **Termine & Wartung**: wiederkehrende Erinnerungen (Heizungswartung,
  Schornsteinfeger, Zähler-Eichfristen …) mit Fälligkeitsstatus.
- **Tarifvergleich** mit **Schattenverträgen**: hypothetische Tarife auf
  die echten Verbräuche rechnen, ohne Saldo/Prognose zu beeinflussen.
- **PDF-Jahresbericht** als Datei-Download (abhängigkeitsfreier
  PDF-Writer, kein composer/mPDF nötig).
- **Aktivierbare Verbrauchsarten**: nicht genutzte Arten ausblenden,
  ohne Daten zu verlieren.

### Operativ

- **Backup & Restore** über die UI: vollständiges JSON-Backup im neuen
  Format (`backup_version: "3.0"`), zur Wiederherstellung oder zum Umzug.
- **Migration aus v0.9.0**: ein altes Backup-Format (`version: "2.1"`) kann
  direkt importiert werden, entweder ersetzend oder zusammenführend mit
  bestehenden Daten. Siehe [Migration aus v0.9.0](docs/MIGRATION-FROM-V090.md).
- **CSV-Export** für Monatsübersicht, Zählerstände und Temperaturreihe —
  semikolon-getrennt, UTF-8 mit BOM, direkt in Excel/LibreOffice nutzbar.
  Ergänzt das vollständige JSON-Backup.
- **Tag/Nacht-Umschaltung** rechts in der Topbar. Respektiert beim
  ersten Besuch `prefers-color-scheme`, persistiert die Wahl danach
  in `localStorage`.
- **System-Diagnose** unter Einstellungen: PHP-Version, Datenverzeichnis,
  Schreibrechte, Schema-Version, Anzahl Zähler/Ablesungen pro Utility.
- **CI-Pipeline** (GitHub Actions, seit v1.4.4): PHP-Syntax-Lint aller
  Dateien plus Frontend-API-Shape- und Browser-Render-Tests gegen einen
  echten Backend-Server bei jedem Push/PR auf `main`.

---

## Dokumentation

Die vollständige Doku liegt als **Kompendium** unter
[`docs/`](docs/README.md) — getrennt in einen technischen und einen
fachlichen Teil plus eine UI-Referenz mit schematischen Mockups
**aller 11 Ansichten**:

- 🔧 **Technisch:** [Installation](docs/technical/01-installation.md) ·
  [Architektur](docs/technical/02-architecture.md) ·
  [API](docs/technical/03-api-reference.md) ·
  [Datenmodell](docs/technical/04-data-model.md) ·
  [Tests](docs/technical/05-testing.md) ·
  [Release-Prozess](docs/technical/06-release-process.md)
- 📚 **Fachlich:** [Grundlagen & Formeln](docs/functional/00-overview.md) ·
  je Energieart ([Gas](docs/functional/01-gas.md) …
  [Pellets](docs/functional/06-pellets.md)) ·
  [Szenario Wohnung](docs/functional/07-szenario-wohnung.md) ·
  [Szenario Eigenheim](docs/functional/08-szenario-eigenheim.md) ·
  [Glossar](docs/functional/09-glossar.md)
- 🖥️ **UI-Referenz:** [Alle Ansichten](docs/ui/01-views.md)

> Die Bilder im Kompendium sind **schematische SVG-Mockups**, keine
> echten Pixel-Screenshots — sie bilden Layout und Farblogik korrekt
> ab. Echte Screenshots lassen sich lokal nach Anleitung in der
> [Installation](docs/technical/01-installation.md) erstellen.

---

## Schnellstart

### Voraussetzungen

- **PHP ≥ 8.4** (CLI und ein beliebiger Webserver: Apache, nginx, Caddy,
  oder der eingebaute PHP-Server für lokales Arbeiten)
- Web-Browser mit ES Modules (alles ab 2020)

### Installation

```bash
git clone https://github.com/Bingerminger/energietracker.git
cd energietracker

# Lokaler Test-Server (Document Root = Projektwurzel)
php -S 127.0.0.1:8080
```

Browser auf <http://127.0.0.1:8080> → Dashboard erscheint mit leerem Zustand.

Die App initialisiert beim ersten Start automatisch `data/meta.json`,
`data/settings.json`, leere `temperatures.json` und die Utility-Unterordner
(`data/gas/`, `data/strom/`, `data/wasser/`) inkl. `meters.json`,
`readings.json`, `contracts.json`. Das Verzeichnis `data/` muss durch
den Webserver schreibbar sein.

### 🐳 Docker (seit v1.7.3)

Reproduzierbarer Betrieb als Single-Container (nginx + php-fpm). Daten
liegen im gemounteten Volume `./data`.

```bash
docker compose up -d        # → http://localhost:8080
```

Oder ohne Compose, direkt mit dem veröffentlichten Image:

```bash
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:1.7.3
```

> Ohne `--name energietracker` vergibt Docker einen zufälligen Namen
> (z. B. `thirsty_archimedes`). Mit `--name` heißt der Container immer
> `energietracker` — genau wie beim Start über `docker compose`.

Logs (JSON Lines) landen via `docker logs`; Konfiguration über
`ET_LOG_LEVEL` / `ET_LOG_DEST` / `ET_DATA_DIR`. Details:
[INSTALL.md → Produktiv: Docker](INSTALL.md#produktiv-docker-seit-v173).

### Erstinbetriebnahme

1. **Einstellungen → System-Konstanten** prüfen: Gas-Umrechnungsfaktor
   (Default 11.5 kWh/m³), HGT-Basistemperatur (Default 15 °C),
   CO₂-Faktoren, eigener Standort (Lat/Lon, Default Leipzig).
2. **Verbrauch → Gas/Strom/Wasser → ⚙️ Zähler** öffnen und den ersten
   Zähler anlegen (ein Default-Gerät wird automatisch erzeugt). Für
   bestehende Zähler eine Seriennummer + ungefähres Einbaudatum eintragen.
3. **Verbrauch → Gas → 📑 Verträge** → ersten Vertrag mit
   Anbieter/Tarif/Start/Ende anlegen, Arbeits- und Grundpreis sowie
   monatlichen Abschlag eintragen.
4. **Erste Ablesung** über den `+ Ablesung`-Button. Sobald mindestens zwei
   Ablesungen vorliegen, beginnt die Monatsverbrauchsberechnung.
5. **Temperaturen → Open-Meteo synchronisieren** für lokale Klimadaten
   (oder eine CSV-Datei hochladen).

### Demo-Daten

> 💡 **Schnellster Weg (kein Dateisystem nötig):** die Demo-Daten liegen auch
> als JSON-Backup unter
> [`demo-data/energietracker-demo-backup.json`](demo-data/energietracker-demo-backup.json).
> In einem leeren Energietracker über *Einstellungen → Backup & Restore →
> Backup importieren* hochladen (ab v1.7.4 zusätzlich per „Demo-Daten laden"-
> Button). Es wird vorab automatisch ein Snapshot angelegt.

Im Repository liegen Demo-Daten unter `demo-data/` (Leipziger EFH-Szenario
2023–2026, drei Utilities, mehrere Verträge mit Tarifwechseln). Zum
Ausprobieren in `data/` kopieren:

```bash
rm -rf data/gas data/strom data/wasser data/meta.json data/settings.json data/temperatures.json
cp -r demo-data/gas demo-data/strom demo-data/wasser data/
cp demo-data/meta.json demo-data/settings.json demo-data/temperatures.json data/
```

> Vor dem Kopieren ggf. eigene Daten sichern (Einstellungen → Backup &
> Restore → *JSON-Backup herunterladen*).

---

## Datenmodell

Alles liegt als JSON unter `data/`. Schema-Version (`1.1.0` — seit
v1.3.0; v1.0.3 führte das Wasser-Vertragsmodell ein, v1.1.0 ergänzte die
lieferbasierten Verbrauchsarten und `reminders.json`) steht in `data/meta.json` und in jedem exportierten Backup unter
`backup_version`.

```
data/
├── meta.json                ← {schema_version, created_at, …}
├── settings.json            ← {gas_conversion_factor, hdd_base_temp, …}
├── temperatures.json        ← {"YYYY-MM-DD": {avg, min, max}}
├── gas/
│   ├── meters.json          ← [{id, name, icon, devices: [...], …}]
│   ├── readings.json        ← [{id, meter_id, device_id, date, counter, …}]
│   └── contracts.json       ← [{id, meter_id, provider, working_prices: [...], …}]
├── strom/
│   ├── meters.json
│   ├── readings.json
│   └── contracts.json
├── wasser/
│   ├── meters.json
│   ├── readings.json
│   └── contracts.json
└── backups/                 ← [optionale Snapshots der UI]
```

### Reading

```json
{
  "id": "r_abc12345",
  "meter_id": "m_gas_main",
  "device_id": "d_gas_001",
  "date": "2025-04-15",
  "counter": 23545.0,
  "price_cents": null,
  "note": "Nach Heizungswartung",
  "is_estimated": false,
  "is_future": false
}
```

`price_cents` ist optional und wird (wenn gesetzt) als forward-fill auf
spätere Readings angewandt — als Fallback wenn kein passender Vertrag
existiert. In der Praxis lässt man `price_cents` leer und pflegt
stattdessen Verträge.

### Meter und Device (Zählertausch)

```json
{
  "id": "m_gas_main",
  "name": "Hauptzähler",
  "icon": "🔥",
  "created_at": "2023-01-01T00:00:00+01:00",
  "active": true,
  "notes": "",
  "devices": [
    {
      "id": "d_gas_001",
      "serial": "GAS-2019-AB7321",
      "installed_on": "2019-04-12",
      "initial_counter": 0.0,
      "removed_on": "2024-08-22",
      "final_counter": 18432.5,
      "reason": "Eichfrist abgelaufen"
    },
    {
      "id": "d_gas_002",
      "serial": "GAS-2024-CD8945",
      "installed_on": "2024-08-22",
      "initial_counter": 0.0,
      "removed_on": null,
      "final_counter": null,
      "reason": ""
    }
  ]
}
```

Die Devices-Liste ist chronologisch. Der aktive (= zuletzt installierte
und nicht ausgebaute) ist derjenige mit `removed_on === null`. Verbrauch
zwischen zwei Readings auf unterschiedlichen Devices wird über die
Funktion `ConsumptionService::consumptionBetween()` korrekt überbrückt.

### Contract

```json
{
  "id": "c_gas_004",
  "meter_id": "m_gas_main",
  "provider": "Vattenfall",
  "tariff_name": "Easy Gas 12 — 2026",
  "start": "2026-01-01",
  "end": "2026-12-31",
  "notes": "Preisanpassung Anfang 2026",
  "working_prices":   [{"from": "2026-01-01", "ct_per_kwh": 8.2}],
  "base_prices":      [{"from": "2026-01-01", "eur_per_month": 10.50}],
  "advance_payments": [{"from": "2026-01-01", "amount_eur": 130.00}],
  "bonuses":          [{"credit_date": "2026-06-30", "amount_eur": 75, "type": "neukunde", "label": "Neukundenbonus"}]
}
```

Bei Wasser bedeutet das Feld `ct_per_kwh` semantisch *ct/m³* —
die Einheit wird aus dem Utility-Config (`consumption_unit`) abgeleitet,
nicht aus dem Feldnamen.

`end: null` entspricht einem offenen Vertrag. Für die Saldo-Projektion
wird das effektive Ende dann auf den nächsten Abrechnungsstichtag der
jeweiligen Utility gesetzt (Settings `billing_cycle_anchor_*`, Default
1. Januar).

### Settings (28 Schlüssel)

Vollständige Liste der konfigurierbaren Werte siehe
[`docs/technical/04-data-model.md`](docs/technical/04-data-model.md) → *Einstellungen*.

---

## Verzeichnisstruktur

```
energietracker/
├── api.php                  ← 20-Z. Entry-Point, delegiert an src/bootstrap.php
├── index.php                ← SPA-Shell (Sidebar + Topbar, lädt /public/js/app.js)
├── VERSION                  ← „1.8.0"
├── README.md                ← diese Datei
├── CHANGELOG.md
├── LICENSE
├── docs/
│   ├── API.md               ← REST-Endpoint-Referenz mit Beispielen
│   ├── ARCHITECTURE.md      ← Service-Map, Datenmodell-Details, Berechnungen
│   ├── MIGRATION-FROM-V090.md
│   └── screenshots/         ← SVG-Mockups (durch echte Screenshots zu ersetzen)
├── src/                     ← PHP backend
│   ├── bootstrap.php        ← App-Container, Routing
│   ├── Config/
│   │   └── Utilities.php    ← Single source of truth für alle 6 Energiearten
│   ├── Http/                ← Router, Request, Response, ErrorHandler
│   ├── Storage/
│   │   ├── JsonStore.php    ← LOCK_EX writes, atomic reads
│   │   └── Migrator.php     ← Bootstrap-Logik für leeres `data/`
│   ├── Services/            ← 22 Services (Consumption, DeliveryConsumption, Forecast, …)
│   └── Controllers/         ← 18 Controllers, 1 Klasse pro Datei
├── public/
│   ├── css/                 ← tokens.css + app.css + components.css
│   └── js/                  ← Vanilla-JS SPA
│       ├── app.js           ← Entry
│       ├── api.js           ← Fetch-Wrapper
│       ├── router.js        ← Hash-Router
│       ├── state.js         ← Lightweight Cache
│       ├── views/           ← dashboard, utility, meters, contracts, …
│       ├── components/      ← chart, modal, toast
│       └── lib/             ← format (de-DE)
├── data/                    ← Laufzeitdaten (gitignored, .gitkeep-Stubs)
├── demo-data/               ← Optional kopierbarer Demo-Datensatz
└── scripts/
    └── init_data.py         ← Python-Helper für Bulk-Import aus Excel
```

---

## Migration aus v0.9.0

Wer aus einem v0.9.0-Backup migrieren möchte:

1. In v0.9.0 ein vollständiges JSON-Backup exportieren (Format-Version
   `2.1` mit Top-Level-Schlüssel `gas`, `strom`, `temperatures`, `settings`,
   `contracts`).
2. v1.2.0 frisch installieren (siehe [Schnellstart](#schnellstart)) oder
   die Demo-Daten löschen.
3. **Einstellungen → Backup & Restore → 📦 Migration aus v0.9.0** öffnen
   und die JSON-Datei hochladen.
4. Der Migrations-Dialog zeigt was importiert würde (Ablesungen pro
   Utility, Verträge, Temperaturen, Settings, Warnungen, erkannte
   Zählerwechsel-Kandidaten).
5. **Ersetzen** oder **Zusammenführen** wählen → Import. Vor dem
   Schreiben wird automatisch ein Sicherungs-Snapshot der aktuellen
   aktuellen Daten unter `data/backups/` angelegt.

Vollständige Schritt-für-Schritt-Anleitung inkl. Schema-Mapping und
Fehlerbehandlung in [`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md).

---

## Weiterführende Dokumentation

- [`docs/README.md`](docs/README.md) — **Kompendium-Index** (technisch · fachlich · UI)
- [`docs/technical/03-api-reference.md`](docs/technical/03-api-reference.md) — vollständige API-Referenz
- [`docs/technical/04-data-model.md`](docs/technical/04-data-model.md) — Datenmodell & Schemata
- [`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md) — Migration aus v0.9.0
- [`CHANGELOG.md`](CHANGELOG.md) — Versionshistorie

---

## Mitwirken & Lizenz

Pull Requests willkommen. Vor größeren Änderungen am Datenmodell bitte
ein Issue öffnen, damit eine Migration sauber durchplanbar bleibt.

MIT License — siehe [`LICENSE`](LICENSE).
