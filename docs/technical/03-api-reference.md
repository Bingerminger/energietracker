# API-Referenz

[← Architektur](02-architecture.md) · [Kompendium-Index](../README.md)

Alle Endpunkte unter `/api/…`. Antwort-Hülle einheitlich:

```json
{ "success": true,  "data": … }
{ "success": false, "error": "Meldung", "detail": … }
```

`{utility}` ist eine von: `gas`, `strom`, `wasser`, `fernwaerme`,
`heizoel`, `pellets`. Stand: **53 Routen**, v1.4.2.

---

## 1. Vollständige Routen-Übersicht

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/diagnostics` | Systemstatus, Schreibrechte, Schema |
| GET | `/api/utilities` | Liste der Verbrauchsarten + Konfiguration |
| GET | `/api/settings` | Einstellungen |
| PATCH | `/api/settings` | Einstellungen ändern |
| GET | `/api/temperatures` | Tagestemperaturen (Map) |
| POST | `/api/temperatures` | Tagesdatum upsert |
| POST | `/api/temperatures/import-csv` | CSV-Import |
| POST | `/api/temperatures/sync-open-meteo` | Open-Meteo-Sync |
| DELETE | `/api/temperatures/{date}` | Tagesdatum löschen |
| GET | `/api/utility/{u}/meters` | Zähler/Tanks |
| POST | `/api/utility/{u}/meters` | anlegen |
| GET | `/api/utility/{u}/meters/{id}` | einzeln |
| PATCH | `/api/utility/{u}/meters/{id}` | ändern |
| DELETE | `/api/utility/{u}/meters/{id}` | löschen |
| POST | `/api/utility/{u}/meters/{id}/replace-device` | Zählertausch |
| GET | `/api/utility/{u}/readings` | Ablesungen |
| POST | `/api/utility/{u}/readings` | anlegen |
| PATCH | `/api/utility/{u}/readings/{id}` | ändern |
| DELETE | `/api/utility/{u}/readings/{id}` | löschen |
| POST | `/api/utility/{u}/meters/{id}/readings/import-csv` | CSV-Bulk-Import |
| GET | `/api/utility/{u}/deliveries` | Lieferungen (Heizöl/Pellets) |
| POST | `/api/utility/{u}/deliveries` | anlegen |
| PATCH | `/api/utility/{u}/deliveries/{id}` | ändern |
| DELETE | `/api/utility/{u}/deliveries/{id}` | löschen |
| GET | `/api/utility/{u}/meters/{id}/stock-history` | Tank-Bestandskurve |
| GET | `/api/utility/{u}/contracts` | Verträge |
| POST | `/api/utility/{u}/contracts` | anlegen |
| GET | `/api/utility/{u}/contracts/{id}` | einzeln |
| PATCH | `/api/utility/{u}/contracts/{id}` | ändern |
| DELETE | `/api/utility/{u}/contracts/{id}` | löschen |
| GET | `/api/utility/{u}/consumption` | Monatsverbrauch (utility-weit) |
| GET | `/api/utility/{u}/meters/{id}/consumption` | Verbrauch + Anomalien + Regressionen |
| GET | `/api/utility/{u}/meters/{id}/contract-status` | Saldo je Vertrag |
| GET | `/api/utility/{u}/meters/{id}/forecast` | 12-Monats-Prognose |
| GET | `/api/utility/{u}/meters/{id}/tariff-comparison` | Tarifvergleich echt vs. Schatten |
| GET | `/api/benchmarks/efficiency` | Effizienzklasse pro Heizquelle |
| GET | `/api/recommendations` | statistische Empfehlungen |
| POST | `/api/recommendations/{id}/dismiss` | Empfehlung ausblenden |
| GET | `/api/reminders` | Termine + Fälligkeitsstatus |
| POST | `/api/reminders` | anlegen |
| PATCH | `/api/reminders/{id}` | ändern |
| DELETE | `/api/reminders/{id}` | löschen |
| POST | `/api/reminders/{id}/done` | erledigt, Recurrence fortschreiben |
| GET | `/api/reports/yearly.pdf` | PDF-Jahresbericht (Datei-Download) |
| GET | `/api/export/{u}/monthly.csv` | Monatsaggregate als CSV |
| GET | `/api/export/{u}/readings.csv` | Ablesungen als CSV (kumulativ) |
| GET | `/api/export/{u}/deliveries.csv` | **v1.4.2** Lieferungen als CSV (Heizöl/Pellets) |
| GET | `/api/export/temperatures.csv` | Temperaturreihe als CSV |
| GET | `/api/backup/export` | Voll-Backup JSON |
| POST | `/api/backup/import` | Backup zurückspielen |
| POST | `/api/backup/snapshot` | Snapshot ablegen |
| POST | `/api/migration/v09/preview` | v0.9.0-Backup analysieren |
| POST | `/api/migration/v09/import` | v0.9.0-Backup übernehmen |

---

## 2. Ausgewählte Endpunkte im Detail

### `GET /api/utility/{u}/meters/{id}/consumption`

Monatsaggregate eines Zählers samt Regressionen und Anomalien. Felder je
Monat u. a.: `ym`, `days`, `kwh` *oder* `m3`, `cost`, `avg_temp`,
`hdd`, `co2_kg`, `advance_eur`, `monthly_balance`,
`cumulative_balance`; bei HGT-relevanten Arten zusätzlich
`expected_hgt`, `weather_adjusted`, `delta_pct` sowie Glättungen
(MA-3/MA-6). `regressions` enthält alle fünf Modelle mit `r2`/`valid`.

### `GET /api/utility/{u}/meters/{id}/stock-history` *(nur Heizöl/Pellets)*

```json
{ "success": true, "data": {
  "capacity": 3000, "capacity_unit": "L", "initial_stock": 2400,
  "days": [ { "date": "2023-01-01", "stock": 2389.4,
              "delivery": 0, "consumption": 10.6 }, … ]
}}
```

Der Bestand ist eine **kalibrierte Modellschätzung** (Anfangsbestand +
Lieferungen − HGT-gewichteter Verbrauch, Rate aus den geschlossenen
Lieferintervallen), **keine** Tankpeilung. Seit v1.4.0 erzwingt das
Modell **keinen** Endbestand 0 mehr. Details:
[Heizöl](../functional/05-heizoel.md).

### `GET /api/benchmarks/efficiency?year=YYYY`

Seit **v1.4.0** pro Heizquelle:

```json
{ "success": true, "data": {
  "year": 2024, "wohnflaeche_m2": 100,
  "per_source": [
    { "utility": "gas", "label": "Gas", "kwh": 10685.8,
      "kwh_per_m2": 106.9, "class": "D" }
  ],
  "primary":  { "utility": "gas", "label": "Gas", "kwh": 10685.8,
                "kwh_per_m2": 106.9, "class": "D" },
  "combined": { "kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D" },
  "thresholds": { "A+": 30, "A": 50, "…": 0 },
  "note": null,
  "total_kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D",
  "breakdown": { "gas": 10685.8 }
}}
```

`per_source` führt jede Heizenergie-Art (Gas, Fernwärme, Heizöl,
Pellets) **einzeln** auf — ein Haus heizt real meist mit einer Quelle;
mehrere summiert ergäben eine unsinnige Klasse. `primary` =
verbrauchsstärkste Quelle, `combined` = Summe (nur bei bewusst
kombiniertem Heizbetrieb sinnvoll, `note` weist darauf hin). Die
Top-Level-Felder sind rückwärtskompatible Aliase und spiegeln seit
v1.4.0 die **primäre** Quelle.

### `GET /api/export/{u}/deliveries.csv` *(v1.4.2, Heizöl/Pellets)*

CSV mit einer Zeile je Lieferung: `Tank/Lager-ID`, `Tank/Lager`,
`Datum`, `Menge (L|kg)`, `Preis (ct/L|kg)`, `Gesamt (EUR)`, `Lieferant`,
`Notiz`, `Geplant`. Semikolon-getrennt, UTF-8-BOM, deutsches
Dezimalkomma. Für kumulative Arten stattdessen `readings.csv` nutzen.

### `POST /api/utility/{u}/deliveries`

Pflicht: `meter_id`, `date`, `quantity` (> 0). Optional
`unit_price_cents` **oder** `total_eur`, `supplier`, `note`,
`is_planned`. **Seit v1.4.2** hat `total_eur` Vorrang vor
`unit_price_cents` — der Rechnungsbetrag ist die tatsächlich bezahlte
Größe (inkl. Liefergebühr/Rabatt); der effektive Stückpreis wird daraus
abgeleitet (`total_eur · 100 / Menge`).

### `GET /api/reports/yearly.pdf?year=YYYY`

Liefert **kein JSON**, sondern direkt ein PDF
(`Content-Type: application/pdf`). Seit **v1.4.2** ohne das frühere
achsenlose Mini-Diagramm — stattdessen eine Kennzahlen-Leiste
(Jahresverbrauch, Ø/Monat, Gesamtkosten, stärkster/schwächster Monat)
plus die Monatstabelle. Erzeugt vom eingebauten, abhängigkeitsfreien
PDF-Writer.

---

[← Architektur](02-architecture.md) ·
[Datenmodell →](04-data-model.md)
