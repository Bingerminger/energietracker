# API-Referenz

[← Architektur](02-architecture.md) · [Kompendium-Index](../README.md)

Alle Endpunkte unter `/api/…`. Antwort-Hülle einheitlich:

```json
{ "success": true,  "data": … }
{ "success": false, "error": "Meldung", "detail": … }
```

`{utility}` ist eine von: `gas`, `strom`, `wasser`, `fernwaerme`,
`heizoel`, `pellets`, `pv_einspeisung`, `pv_erzeugung`. Stand: **68 Routen**,
v1.9.2.

> Eine ausführliche Variante dieser Referenz (mit Request-/Response-Beispielen
> für **alle** Endpunkte) liegt zusätzlich unter [`docs/API.md`](../API.md).
> Dieses Dokument ist die kompakte Übersicht im Kompendium.

---

## 1. Vollständige Routen-Übersicht

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/health` | Health-Check (Version, Schema, Schreibrechte, Migrationen) |
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
| GET | `/api/utility/{u}/meter-groups` | Zählergruppen (F1006) |
| POST | `/api/utility/{u}/meter-groups` | Gruppe anlegen |
| POST | `/api/utility/{u}/meter-groups/merge` | Merge-Wizard: mehrere Zähler bündeln |
| PATCH | `/api/utility/{u}/meter-groups/{groupId}` | Gruppe umbenennen |
| DELETE | `/api/utility/{u}/meter-groups/{groupId}` | Gruppe auflösen (Mitglieder bleiben) |
| GET | `/api/utility/{u}/readings` | Ablesungen |
| POST | `/api/utility/{u}/readings` | anlegen |
| PATCH | `/api/utility/{u}/readings/{id}` | ändern |
| DELETE | `/api/utility/{u}/readings/{id}` | löschen |
| POST | `/api/utility/{u}/meters/{id}/readings/import-csv` | CSV-Bulk-Import |
| **GET** | **`/api/readings-overview`** | **alle aktiven kumulativen Zähler + letzte Ablesung (F1004, v1.6.0)** |
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
| GET | `/api/strom-saldo` | Strom-Saldo (Bezug − PV-Einspeisung), F1005 |
| GET | `/api/pv-summary` | PV-Eigenverbrauch + Autarkiequote, F1005 |
| GET | `/api/demo/status` | Demo-Daten verfügbar/Store leer? (F1007) |
| POST | `/api/demo/import` | Demo-Datensatz laden (F1007) |
| GET | `/api/auth/token` | API-Token-Status (nie der Token selbst), F1009 |
| POST | `/api/auth/token` | Token erzeugen (einmalig Klartext), F1009 |
| DELETE | `/api/auth/token` | Token widerrufen → API wieder offen, F1009 |
| **POST** | **`/api/ingest`** | **idempotenter Zählerstand-Push für Home Assistant (F1009)** |

---

## 2. Ausgewählte Endpunkte im Detail

### `GET /api/readings-overview` *(F1004, v1.6.0)*

Aggregat-Endpunkt für die zentrale Zählerstand-Erfassung
(`#/zaehlerstaende`). Liefert in einem Roundtrip alle aktiven Zähler
der kumulativen Utilities (Gas/Strom/Wasser/Fernwärme) plus jeweils
die letzte reale (nicht-geplante) Ablesung als Validierungs-Baseline.
Delivery-Utilities (Heizöl/Pellets) sind ausgeschlossen — dort gibt
es keine Zählerstände, sondern Lieferungen.

```json
{
  "success": true,
  "data": {
    "rows": [
      {
        "utility": "gas",
        "utility_label": "Gas",
        "utility_icon": "🔥",
        "consumption_unit": "kWh",
        "color": "#f59e0b",
        "meter_id": "m_gas_main",
        "meter_name": "Hauptzähler Gas",
        "meter_icon": "🔥",
        "meter_notes": "Keller",
        "active_device_id": "d_gas_1",
        "last_reading": {
          "date": "2026-04-15",
          "counter": 12345.67,
          "is_estimated": false
        },
        "expected_next_min": 12345.67
      }
    ]
  }
}
```

`expected_next_min` ist der Wert, gegen den die Frontend-Validierung
einen Rückwärts-Zählerstand warnt (nicht hart blockiert — Zählertausch
ist legitim). Speichern erfolgt **nicht** über diesen Endpunkt,
sondern pro Zeile über die bestehende Route
`POST /api/utility/{u}/readings`.

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

### `POST /api/ingest` *(F1009, v1.9.0 — Home Assistant)*

Idempotenter Push-Eingang für externe Datenlieferanten. **Upsert pro
(Zähler, Datum):** ein erneuter Push am selben Tag aktualisiert den Wert,
statt eine zweite Ablesung anzulegen.

```jsonc
// Header (nur falls ein Token gesetzt ist): Authorization: Bearer <token>
{
  "utility": "strom",
  "meter":   "stromzaehler_haus",  // external_id-Alias ODER interne Meter-ID
  "value":   12345.6,              // alias: "counter"
  "date":    "2026-06-02"          // optional, Default heute; ISO-Stempel wird gekürzt
}
```

Antwort `201` (neu) bzw. `200` (aktualisiert) mit
`{ status: "created"|"updated", utility, meter_id, date, counter, reading_id }`.
Fehler: `401` (Token nötig/falsch), `400` (unbekannte Utility/Zähler, kein
Zahlenwert, Delivery-Utility Heizöl/Pellets).

### `GET|POST|DELETE /api/auth/token` *(F1009)*

Verwaltung des **opt-in**-API-Tokens. Ohne Token ist die API offen (LAN-Modus);
sobald ein Token existiert, verlangt `/api/ingest` einen Bearer-Header. Der
Token wird nur als SHA-256-Hash in `data/auth.json` gespeichert und beim
Erzeugen **einmalig** im Klartext zurückgegeben.

> Vollständige Beispiele zu Auth + Ingest und die Schritt-für-Schritt-Einrichtung
> in Home Assistant: [`docs/HOME-ASSISTANT.md`](../HOME-ASSISTANT.md) und
> [`docs/API.md`](../API.md).

### Zählergruppen *(F1006, v1.8.0)*

`GET/POST /api/utility/{u}/meter-groups`, `PATCH/DELETE …/{groupId}` sowie
`POST …/meter-groups/merge` (Merge-Wizard). Mitgliedschaft wird über
`meter_group_id` am Zähler gesetzt, nicht in der Gruppe. Subzähler werden über
`parent_meter_id` am Zähler verknüpft (siehe
[Datenmodell](04-data-model.md) und
[Meter-Topologie](../functional/13-meter-topologie.md)).

---

[← Architektur](02-architecture.md) ·
[Datenmodell →](04-data-model.md)
