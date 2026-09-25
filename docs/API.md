# API-Referenz (Kurzfassung)

**Deutsch** · [English](en/API.md)

> **Hinweis:** Maßgeblich für Pfade, Felder, Statuscodes und die
> Stabilitätszusage ist die API-Referenz im Kompendium:
> [`docs/technical/03-api-reference.md`](technical/03-api-reference.md) —
> vollständige Routenliste, von einem Test gegen den Code geprüft. Dieses
> Dokument ist der **Leitfaden mit ausführlichen Beispielen** für die
> meistgenutzten Endpunkte (Stand: v2.6.0). Bis v2.5.3 wich es an mehreren
> Stellen vom Code ab (Review API-18); die früher hier beschriebenen Körper
> für Zähler und Zählertausch werden seitdem als Alias angenommen.

REST-API über einen einzigen Entry-Point: `api.php`. Pfade haben das
Präfix `/api/`, das nach dem Script-Name folgt:

```
http://localhost/api.php/api/<endpoint>
```

Bei Apache mit `mod_rewrite` oder ähnlichem Routing kann der
`api.php`-Teil verborgen werden — die Beispiele unten zeigen den
expliziten Pfad, der ohne URL-Rewriting funktioniert.

---

## Antwort-Hülle

Alle Antworten haben dieselbe Struktur:

**Erfolg:**

```json
{
  "success": true,
  "data": { ... }
}
```

**Fehler:**

```json
{
  "success": false,
  "error": "Lesbare Meldung in der Sprache der Anfrage",
  "code": "errors.reading.dateInvalid"
}
```

`code` *(seit v2.6.0)* ist stabil — Skripte werten ihn aus, nicht den
Meldungstext. `detail` mit Datei und Zeile gibt es nur noch mit `ET_DEBUG=1`;
ein `500` trägt `error_id` (dieselbe ID steht im Server-Log). Alle
Statuscodes (400, 401, 403, 404, 405, 409, 421, 429, 500, 503) und ihre
Bedeutung: [API-Referenz → Statuscodes](technical/03-api-reference.md#statuscodes-aller-endpunkte).

---

## Route-Übersicht

Die vollständige Liste aller Routen steht in der
[API-Referenz](technical/03-api-reference.md#1-vollständige-routen-übersicht) —
ein Test prüft sie bei jedem Release gegen den Code. Bis v2.5.3 stand hier
eine eigene Tabelle; sie kannte zuletzt 37 von 70 Routen. Die Abschnitte
unten zeigen Beispiele nach Themen.

---

## Diagnostics

### `GET /api/diagnostics`

Liefert Systemzustand und Schema-Informationen.

**Response:**

```json
{
  "success": true,
  "data": {
    "app_version": "2.6.0",
    "schema_version": "1.5.0",
    "php_version": "8.4.12",
    "data_dir": "/var/www/energietracker/data",
    "data_dir_writable": true,
    "curl_available": true,
    "time_zone": "Europe/Berlin",
    "now": "2026-09-25T00:13:17+02:00",
    "migration_needed": false,
    "utilities": {
      "gas":     { "kind": "cumulative", "meters": 1, "readings": 41, "contracts": 6, "last_reading_date": "2026-03-15" },
      "heizoel": { "kind": "delivery",   "meters": 1, "deliveries": 3, "contracts": 0, "last_delivery_date": "2025-09-18" }
    },
    "temperatures": { "rows": 1277 },
    "settings_known_keys": ["gas_conversion_factors", "hdd_base_temp", "…"]
  }
}
```

Für Monitoring ist `GET /api/health` gedacht (Status, Prüfungen, HTTP 503 im
Fehlerfall); die Diagnose ist die ausführliche Sicht für die Einstellungen.

---

## Utilities

### `GET /api/utilities`

Liefert die statische Konfiguration der acht Verbrauchsarten (`gas`,
`strom`, `wasser`, `fernwaerme`, `heizoel`, `pellets`, `pv_einspeisung`,
`pv_erzeugung`; single source of truth aus `src/Config/Utilities.php`).

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "key": "gas",
      "label": "Gas",
      "icon": "🔥",
      "unit": "m³",
      "consumption_unit": "kWh",
      "unit_to_kwh": true,
      "conversion_setting": null,
      "hgt_relevant": true,
      "color": "#ff7b2e",
      "co2_setting": "co2_gas",
      "default_meter_name": "Hauptzähler",
      "allow_multiple_meters": true
    },
    { "key": "strom", ... },
    { "key": "wasser", ... }
  ]
}
```

---

## Settings

### `GET /api/settings`

**Response:** sämtliche Einstellungen als flaches Objekt; Schlüssel und
Defaults stehen in `SettingsService::DEFAULTS`.

### `PATCH /api/settings`

Partielle Aktualisierung — nur die übergebenen Schlüssel werden
geschrieben, alle anderen bleiben unverändert.

**Body:**

```json
{ "hdd_base_temp": 17, "forecast_model": "robust" }
```

**Response:** das vollständige aktualisierte Settings-Objekt. Unbekannte
Schlüssel werden nicht gespeichert; seit v2.6.0 nennt die Antwort sie in
`ignored_keys` und in der Kopfzeile `X-Ignored-Keys` (bis v2.5.3 still
verworfen — ein Tippfehler im Schlüssel fiel nicht auf).

Seit v2.7.0 prüft der Endpunkt die Länderprofil-Schlüssel `country`,
`currency`, `timezone` und `gas_cv_unit` gegen die erlaubten Werte (400
`errors.settings.valueInvalid`):

```json
{ "country": "AT", "timezone": "Europe/Vienna" }
```

### `GET /api/countries` *(v2.7.0)*

Die Länderprofile (Währung, Zeitzone, Heizgrenze, CO₂-Faktor Strom,
Standort, Effizienzskala, Brennwert-Einheit). Schreibt nichts — was davon
übernommen wird, entscheidet der Aufrufer per `PATCH /api/settings`.
Feldliste: [API-Referenz](technical/03-api-reference.md#länderprofil-country-currency-timezone-gas_cv_unit-v270-additiv).

---

## Temperaturen

### `GET /api/temperatures`

**Response:** Map von `YYYY-MM-DD` auf `{avg, min, max}` (effizient
für O(1)-Lookup, NICHT als Array).

```json
{
  "success": true,
  "data": {
    "2023-04-03": {"avg": 3.4, "min": 2.4, "max": 18.1},
    "2023-04-04": {"avg": 3.4, "min": -0.4, "max": 8.9}
  }
}
```

### `POST /api/temperatures`

**Body:**

```json
{ "date": "2026-05-11", "avg": 18.5, "min": 12.0, "max": 24.3 }
```

### `POST /api/temperatures/import-csv`

**Body:** Plain-Text (Content-Type `text/plain`), Format `DD.MM.YYYY"avg"min"max`:

```
15.01.2024"4.2"-1.0"7.1
16.01.2024"3.8"0.5"6.9
```

**Response:**

```json
{ "success": true, "data": { "imported": 365, "skipped": 0 } }
```

### `POST /api/temperatures/sync-open-meteo`

Lädt Temperaturen für die in den Settings hinterlegten Koordinaten:
Messwerte aus dem Open-Meteo-Archiv, für die letzten Tage und die kommende
Woche die Vorhersage, beim ersten Mal zusätzlich das Klimanormal (30 Jahre).
Übermittelt wird nur der Standort, auf zwei Nachkommastellen gerundet.

**Query (alle optional, seit v2.8.0):** `start`, `end` (ISO-Datum; ohne
`start` ab der ersten Ablesung bzw. Lieferung), `reload=1` (vorhandene Werte
durch Archivwerte ersetzen — außer eigenen aus CSV oder Handeingabe),
`auto=1` (höchstens einmal am Tag; so ruft die Oberfläche beim Öffnen auf).
**Body:** optional `{}`.

**Response:**

```json
{
  "success": true,
  "data": {
    "imported": 1145,
    "archive_rows": 1131,
    "forecast_rows": 14,
    "archive_range": "2023-01-01..2026-09-19",
    "archive_error": null,
    "forecast_error": null,
    "measured_until": "2026-09-19",
    "forecast_until": "2026-10-08",
    "climate_normal": { "status": "fetched", "period": { "from": "1996-01-01", "to": "2025-12-31" },
                        "latitude": 51.34, "longitude": 12.37, "fetched_at": "…" }
  }
}
```

Jeder Tag in `GET /api/temperatures` trägt seit v2.8.0 `source`
(`archive`, `forecast`, `csv`, `manual`); Vorhersagen werden durch
Archivwerte ersetzt, sobald diese vorliegen. Details:
[API-Referenz](technical/03-api-reference.md).

### `DELETE /api/temperatures/{date}`

Löscht einen einzelnen Tag (`date` als `YYYY-MM-DD`).

---

## Meters & Devices

### `GET /api/utility/{utility}/meters`

`{utility}` ∈ `gas | strom | wasser | fernwaerme | heizoel | pellets |
pv_einspeisung | pv_erzeugung`.

**Response:** Array von Meter-Objekten (Schema:
[Datenmodell](technical/04-data-model.md)).

### `POST /api/utility/{utility}/meters`

**Body:**

```json
{
  "name": "Gartenzwischenzähler",
  "icon": "💧",
  "notes": "Optional",
  "device_serial": "WZ-2021-AB123",
  "installed_on": "2021-04-15",
  "initial_counter": 0.0,
  "digits": 5
}
```

Alle Gerätefelder sind optional (Einbau heute, Anfangsstand 0). `digits`
*(v2.6.0)* = Stellen des Zählwerks vor dem Komma (3–12), damit ein Überlauf
richtig gerechnet wird. Heizöl/Pellets verlangen stattdessen `capacity`
(> 0) und `initial_stock`.

Der bis v2.5.3 hier beschriebene Körper mit einem Objekt
`"device": {"serial", "installed_on", "initial_counter"}` wurde vom Code nie
gelesen — die Werte gingen still verloren. Seit v2.6.0 wird er als Alias
angenommen; die Einzelfelder oben haben Vorrang.

### `PATCH /api/utility/{utility}/meters/{id}`

**Body:** beliebige Teilmenge
`{ name, icon, active, notes, parent_meter_id, meter_group_id, external_id }`.

- `parent_meter_id` / `meter_group_id` — Meter-Topologie (F1006, ab Schema 1.2.0).
- `external_id` — frei vergebbarer Alias für die Home-Assistant-Anbindung
  (F1009, ab Schema 1.3.0). Pro Utility eindeutig; erlaubt sind 1–64 Zeichen aus
  `[A-Za-z0-9_.-]`. Leerer Wert/`null` entfernt den Alias.

### `DELETE /api/utility/{utility}/meters/{id}`

Löscht den Zähler **und alle zugehörigen Readings und Verträge**
(cascade).

### `POST /api/utility/{utility}/meters/{id}/replace-device`

F2-Zählertausch: schließt das aktuelle Device und legt ein neues an.

**Body:**

```json
{
  "date": "2024-08-22",
  "old_final_counter": 18432.5,
  "new_initial_counter": 0.0,
  "serial": "GAS-2024-CD8945",
  "reason": "Eichfrist abgelaufen"
}
```

`old_final_counter` ist Pflicht (fehlt er → 400; ein stiller Endstand 0
erzeugte in Issue #13 einen 200-fachen Ausschlag). Der Tauschtag gehört zum
**neuen** Gerät. Der bis v2.5.3 hier beschriebene Körper (`removed_on`,
`final_counter`, `new_device {serial, installed_on, initial_counter}`)
endete in „old_final_counter fehlt"; seit v2.6.0 wird er als Alias
angenommen.

---

## Readings

### `GET /api/utility/{utility}/readings`

Query-Parameter `meter_id` filtert auf einen Zähler.

**Response:** Array von Readings (Schema siehe README).

### `POST /api/utility/{utility}/readings`

**Body:**

```json
{
  "meter_id": "m_gas_main",
  "date": "2026-05-11",
  "counter": 24890.5,
  "note": "",
  "is_estimated": false,
  "is_future": false
}
```

`device_id` wird automatisch aus dem aktuellen (= zuletzt installierten,
nicht ausgebauten) Device des Meters abgeleitet.

### `PATCH /api/utility/{utility}/readings/{id}`

**Body:** beliebige Teilmenge der Reading-Felder.

### `DELETE /api/utility/{utility}/readings/{id}`

### `POST /api/utility/{utility}/meters/{id}/readings/import-csv`

CSV-Bulk-Import von Ablesungen in einen konkreten Zähler (F-06, seit
v1.1.0). Eine bereits vorhandene Ablesung am selben Datum wird
**überschrieben**, nicht dupliziert.

**Content-Type:** `text/plain` — der Request-Body ist der rohe CSV-Text,
**kein** JSON.

**CSV-Format** (eine Kopfzeile wird automatisch erkannt und übersprungen):

```
datum;zaehlerstand;notiz;geschaetzt
01.02.2026;12345,6;Jahresanfang;false
2026-03-01;12567.8;;ja
```

- **Trenner:** `;` bevorzugt, `,` als Fallback.
- **Datum:** `TT.MM.JJJJ` oder ISO `JJJJ-MM-TT`.
- **Zählerstand:** deutsches Dezimalkomma und Tausenderpunkt werden erkannt.
- **notiz** und **geschaetzt** sind optional; `geschaetzt` akzeptiert
  `true/false/1/0/ja/nein/x` (leer = `false`).

**Response:**

```json
{
  "success": true,
  "data": {
    "imported":    2,
    "overwritten": 0,
    "skipped":     0,
    "errors":      []
  }
}
```

`errors` enthält pro nicht verarbeitbarer Zeile eine deutschsprachige
Meldung mit Zeilennummer. Die Importlogik steckt im quell-agnostischen
`ReadingImportService` — externe Datenquellen wie die
[Home-Assistant-Anbindung](HOME-ASSISTANT.md) (`POST /api/ingest`) nutzen
denselben Kern ohne CSV-Parsing wieder.

---

## Contracts

### `GET /api/utility/{utility}/contracts`

Query-Parameter `meter_id` filtert auf einen Zähler.

### `POST /api/utility/{utility}/contracts`

**Body für Gas / Strom:**

```json
{
  "meter_id": "m_gas_main",
  "provider": "Vattenfall",
  "tariff_name": "Easy Gas 12",
  "start": "2025-01-01",
  "end":   "2025-12-31",
  "notes": "",
  "working_prices":   [{"from": "2025-01-01", "ct_per_kwh": 9.2}],
  "base_prices":      [{"from": "2025-01-01", "eur_per_month": 10.50}],
  "advance_payments": [{"from": "2025-01-01", "amount_eur": 145.00}],
  "bonuses":          [{"credit_date": "2025-06-30", "amount_eur": 100, "type": "neukunde", "label": "Neukundenbonus"}]
}
```

**Body für Wasser** (drei Komponenten-Blöcke, seit v1.0.3):

```json
{
  "meter_id": "m_wasser_haupt",
  "provider": "Kommunale Wasserwerke Leipzig",
  "tariff_name": "Trink-, Schmutz- und Niederschlagswasser 2025",
  "start": "2025-01-01",
  "end":   "2026-12-31",
  "notes": "",
  "trinkwasser": {
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 255.0}],
    "base_prices":    [{"from": "2025-01-01", "eur_per_month": 8.50}]
  },
  "schmutzwasser": {
    "basis": "trinkwasser",
    "separater_zaehler_meter_id": null,
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 305.0}]
  },
  "niederschlagswasser": {
    "rates": [{"from": "2025-01-01", "eur_per_m2_year": 1.50, "versiegelte_flaeche_m2": 120}]
  },
  "advance_payments": [{"from": "2025-01-01", "amount_eur": 72.00}],
  "bonuses": []
}
```

`schmutzwasser.basis` ist `"trinkwasser"` (Standard, Schmutzwasser-Menge =
Trinkwasser-Verbrauch) oder `"separater_zaehler"` (mit
`separater_zaehler_meter_id` als Verweis auf einen zweiten Zähler — seit
v1.1.0 wird in diesem Fall das monatliche m³ des referenzierten Zählers
verwendet; die historische Auswertung rechnet damit korrekt, die
Forecast-Vorausschau nutzt vereinfachend das Trinkwasser-Volumen).

Strikte F4-Validierung: halb-ausgefüllte Subzeilen (z.B. `{from: "2025-01-01"}` ohne
`ct_per_kwh`) führen zu HTTP 400 mit präziser Fehlermeldung wie
`"working_prices-Eintrag #2: ct_per_kwh fehlt"`.

### `PATCH /api/utility/{utility}/contracts/{id}`

### `DELETE /api/utility/{utility}/contracts/{id}`

---

## Verbrauchs-Aggregation

### `GET /api/utility/{utility}/consumption`

Monatsverbrauch **utility-weit** (über alle Zähler aggregiert).

**Query-Parameter:**
- `hdd_base` (optional, float) — überschreibt die HGT-Basistemperatur
  für diese eine Antwort

**Response:**

```json
{
  "success": true,
  "data": {
    "meters": [
      { "meter": {...}, "monthly": [ ... ] }
    ],
    "monthly_total": [
      {
        "ym": "2025-04",
        "year": 2025, "month": 4,
        "kwh": 1840.5, "m3": 160.0, "cost": 169.33,
        "days": 30,
        "avg_temp": 9.2, "min_temp": 2.1, "max_temp": 18.4, "hdd": 174,
        "ma3": 2210.0, "ma6": 2540.0, "ma12": 2620.0
      }
    ]
  }
}
```

### `GET /api/utility/{utility}/meters/{id}/consumption`

Monatsverbrauch eines **einzelnen Zählers** plus Anomalien und
Regressionsmodelle.

**Response:**

```json
{
  "success": true,
  "data": {
    "meter": { ... },
    "monthly": [
      {
        "ym": "2025-04", "year": 2025, "month": 4,
        "days": 30, "kwh": 1840.5, "m3": 160.0,
        "kwh_per_day": 61.4, "avg_temp": 9.2, "hdd": 174,
        "cost": 169.33,
        "contract_id": "c_gas_003",
        "working_price_ct": 9.2,
        "base_price_eur": 10.5,
        "advance_eur": 145.0,
        "bonus_eur": 0.0,
        "kwh_cost": 158.83,
        "monthly_balance": 24.33,
        "cumulative_balance": -98.50,
        "co2_kg": 369.9,
        "ma3": 2210.0, "ma6": 2540.0
      }
    ],
    "anomalies": [
      { "ym": "2025-02", "actual": 3192, "expected": 3653, "z": -2.02 }
    ],
    "regressions": {
      "linear":     { "model": "linear",     "valid": true, "r2": 0.9668, "a": 11.86, "b": 1023.0, "n": 30 },
      "polynomial": { "model": "polynomial", "valid": true, "r2": 0.9682, "a": 0.012, "b": 8.3, "c": 1180.0, "n": 30 },
      "robust":     { "model": "robust",     "valid": true, "r2": 0.9667, "a": 11.84, "b": 1025.0, "n": 30 },
      "segmented":  { "model": "segmented",  "valid": true, "r2": 0.9669, "split": 50, "heat": {...}, "base": {...} }
    }
  }
}
```

Bei `hgt_relevant: false` (Wasser) ist `regressions` ein leeres Objekt.

### `GET /api/utility/{utility}/meters/{id}/contract-status`

Saldo-Aggregation pro Vertrag — Datenquelle für die *Saldo aktueller
Vertrag* Karte und die *Verträge & Abschläge* Tabelle in der UI.

**Response:**

```json
{
  "success": true,
  "data": {
    "contracts": [
      {
        "contract_id": "c_gas_003",
        "provider": "Vattenfall",
        "tariff_name": "Easy Gas 12",
        "start": "2025-01-01",
        "end":   "2025-12-31",
        "effective_end": "2025-12-31",
        "is_current": false,
        "is_past":    true,
        "is_future":  false,
        "is_open_ended": false,
        "current_working_price_ct": 9.2,
        "current_base_price_eur":   10.5,
        "current_advance_amount":   145.0,
        "months_actual":      12,
        "actual_kwh":         18432.5,
        "actual_kwh_cost":    1695.79,
        "actual_base_total":  126.0,
        "actual_bonus_total": 100.0,
        "actual_cost":        1721.79,
        "advance_paid":       1740.0,
        "current_balance":    -18.21,
        "projected_end_balance": -18.21,
        "verdict": "refund",
        "days_until_end": 231,
        "should_remind":  false,
        "remind_stage":   0
      }
    ]
  }
}
```

**Seit v2.5.1** trägt jeder Vertrag zusätzlich `special_payments`: die
Einzelposten als `[{date, kind, amount_eur, note}]` (nach Datum sortiert,
Beträge positiv — die Richtung steckt in `kind`), Datenquelle für den
Tooltip der Spalte *Sonderzahlungen*. `special_payment_net` ist das Netto aus
Kundensicht (Σ Rückzahlung − Σ Nachzahlung − Σ Abschlagszahlung). Das Feld
fehlt bei Wasser und PV-Einspeisung.

**Seit v2.8.0** rechnet der Saldo bei Gas, Strom und Fernwärme **nach
Kalender bis heute**: `advance_paid` zählt die Abschläge laut Zahlungsplan
bis einschließlich des laufenden Monats (vorher nur Monate mit Ablesung),
`cost_to_date` den Verbrauch bis heute — gemessen bis `measured_until`,
danach geschätzt (`estimated_cost_to_date`). Dazu `energy_cost_to_date`,
`base_to_date`, `bonus_to_date` (die Teile der Summe),
`estimated_cost_remaining`, `advance_remaining`, `suggested_advance`,
`balance_as_of`, `projection_method` und `projection_factor` — Tabelle in der
[API-Referenz](technical/03-api-reference.md). `actual_*` beschreibt weiterhin
nur die gemessenen Monate.

`verdict` ist ein Schlüssel: `surcharge` (Nachzahlung) bei
`projected_end_balance > 5`, `refund` (Erstattung) bei `< -5`, sonst
`balanced`. Bei PV-Einspeisung ist die Achse umgedreht: `payout`, `reclaim`,
`balanced`. Die Oberfläche übersetzt den Schlüssel (`utility.verdict.*`).
Bis v1.9.x standen hier deutsche Wörter — v2.0.0 hat das **ohne Ankündigung**
geändert; genau deshalb gibt es seit v2.6.0 die
[Stabilitätszusage](technical/03-api-reference.md#stabilitätszusage-v260).

`effective_end` ist bei Verträgen mit gepflegtem Ende identisch mit
`end`; bei offenen Verträgen (`end: null`, `is_open_ended: true`) ist es
der nächste Abrechnungsstichtag der Utility (Settings
`billing_cycle_anchor_<utility>`, Default `01-01`) — bis dorthin wird der
`projected_end_balance` projiziert (F-03, seit v1.1.0).

**Vertragsende-Erinnerung** (F-05, seit v1.1.0) — drei zusätzliche Felder
pro Vertrag:

- `days_until_end` — Tage bis zum Vertragsende, vorzeichenbehaftet
  (negativ = Ende liegt in der Vergangenheit); `null` bei offenen Verträgen.
- `remind_stage` — `0` (keine Erinnerung) bis `3` (dringend). Die
  Schwellen sind als Settings-Keys `contract_remind_days_1|2|3`
  konfigurierbar (Default 90 / 30 / 1 Tage).
- `should_remind` — `true`, sobald `remind_stage > 0`.

**Wasser-spezifische Antwort** (seit v1.0.3): jedes Vertrags-Objekt enthält
zusätzlich `actual_m3` und `components` mit der Aufschlüsselung der drei
Komponenten:

```json
{
  "contract_id": "c_wasser_...",
  "actual_m3": 187.5,
  "current_balance": +52.28,
  "projected_end_balance": +85.40,
  "verdict": "surcharge",
  "components": {
    "trinkwasser": {
      "working_cost": 482.69,
      "base_cost": 102.00,
      "total": 584.69,
      "current_ct_per_m3": 265.0,
      "current_eur_per_month": 8.50
    },
    "schmutzwasser": {
      "total": 424.59,
      "current_ct_per_m3": 315.0,
      "basis": "trinkwasser"
    },
    "niederschlagswasser": {
      "total": 225.00,
      "current_eur_per_m2_year": 1.50,
      "current_versiegelte_m2": 120,
      "current_monthly": 15.00
    }
  }
}
```

Pro Monat liefert `meterConsumption` für Wasser jede Komponente separat
unter `monthly[].trinkwasser`, `.schmutzwasser`, `.niederschlagswasser`.

### `GET /api/utility/{utility}/meters/{id}/forecast`

12-Monats-Forecast pro Zähler. Für HGT-relevante Verbrauchsarten (Gas)
eine R²-gewichtete Mischung aus Regressionsmodell und Saisonprofil; für
nicht-HGT-relevante (Strom, Wasser) ein reines Saisonprofil.

Die **Kostenprognose ist vertragsbasiert** (F-02, seit v1.1.0): für jeden
Prognosemonat wird der dann aktive Vertrag aufgelöst und der für diesen
Monat gültige Arbeits- und Grundpreis aus der Preishistorie verwendet.

**Query-Parameter** (alle optional):
- `model` — `linear | polynomial | robust | segmented` (default = setting
  `forecast_model`)
- `temp_offset` — °C-Verschiebung der HGT-Annahme (What-if)
- `price_factor` — Multiplikator auf die Arbeitspreise (What-if)
- `forecast_months` — Horizont in Monaten (default = setting
  `forecast_months`)

**Response:**

```json
{
  "success": true,
  "data": {
    "valid": true,
    "utility": "gas",
    "meter_id": "m_gas_main",
    "blend_weight": 0.75,
    "last_price_ct": 8.2,
    "regression": { "model": "linear", "valid": true, "r2": 0.967, "a": 11.86, "b": 1023.0, "n": 30 },
    "historical": [ ... Monatsreihe wie bei meterConsumption ... ],
    "forecast": [
      {
        "ym": "2026-06", "year": 2026, "month": 6,
        "kwh": 540,
        "hdd_estimated": 12.4,
        "cost_estimated": 47.79,
        "advance_estimated": 130.0,
        "balance_running": -216.71,
        "working_price_ct": 8.2,
        "contract_id": "c_gas_004",
        "contract_assumed": false,
        "band_low": 470.2,
        "band_high": 609.8,
        "method": "blend(reg=0.75, seasonal=0.25)"
      }
    ],
    "hdd_source": "climate_normal",
    "annual": { "value": 9387.3, "low": 8619.3, "high": 10155.4, "sigma": 1.28, "level_pct": 80 },
    "warnings": [],
    "climate_normal": { "period": { "from": "1996-01-01", "to": "2025-12-31" }, "latitude": 51.34, "longitude": 12.37, "fetched_at": "…" },
    "options": { "temp_offset": 0, "price_factor": 1, "forecast_months": 12 }
  }
}
```

Pro Prognosemonat:
- `kwh` bzw. `m3` — der prognostizierte Verbrauch (Feldname je nach
  `consumption_unit` der Utility).
- `cost_estimated` — Arbeitspreis × Menge + Grundpreis − bekannte Boni.
- `advance_estimated` — der für den Monat gültige Abschlag, oder `null`
  wenn kein Vertrag/kein Abschlag gepflegt ist.
- `balance_running` — kumuliert `cost_estimated − advance_estimated`.
  Negativ = Guthaben, positiv = Nachzahlung; der Wert des letzten Monats
  ist der projizierte Saldo am Horizontende.
- `working_price_ct` — der angesetzte Arbeitspreis (Headline-Tarif).
- `contract_id` — der aktive Vertrag, oder `null` (dann Fallback auf
  `last_price_ct`).
- `method` — `blend(reg=…, seasonal=…)`, `seasonal_only`, seit v2.8.0 auch
  `regression_only` (Kalendermonat ohne eigene Historie), `heat_model` und
  `filled`.
- `band_low`/`band_high` *(v2.8.0)* — Unsicherheitsband, Breite
  `confidence_band_sigma` (Default 1,28 σ ≈ 80 % der Jahre).
- `hdd_estimated` — seit v2.8.0 die normalen Heizgradtage aus dem
  Klimanormal (`hdd_source`), sonst aus der eigenen Historie.
- `contract_assumed` *(v2.8.0)* — `true`, wenn nach Vertragsende der letzte
  Vertrag als Annahme weiterläuft.

Auf oberster Ebene seit v2.8.0 `annual` (Jahressumme mit Band), `warnings`
(`history_short`, `no_climate_normal`), `hdd_source` und `climate_normal`.

Künftige Boni werden **nicht** fortgeschrieben — nur im Vertrag mit
Gutschriftdatum im Prognosezeitraum gepflegte Boni fließen ein. Bei zu
wenig Historie (< 6 Monate) ist die Antwort
`{ "valid": false, "reason": "…" }`.

---

## Backup & Restore

### `GET /api/backup/export`

Vollständiges Backup im aktuellen Format. Frontend kann die Antwort
direkt als JSON-Datei abspeichern.

**Response (gekürzt):**

```json
{
  "success": true,
  "data": {
    "backup_version": "3.0",
    "app_version": "1.1.0",
    "exported_at": "2026-05-11T14:00:00+02:00",
    "meta": { ... },
    "temperatures": { ... },
    "settings": { ... },
    "utilities": {
      "gas":    { "meters": [...], "readings": [...], "contracts": [...] },
      "strom":  { ... },
      "wasser": { ... }
    }
  }
}
```

### `POST /api/backup/import`

Spielt ein Backup zurück. Nur Formate `backup_version: "3.0"` oder
höher werden akzeptiert — für ältere Formate ist der Migrator (siehe
unten) zuständig.

**Body:** das `data`-Objekt aus dem Export, also Top-Level mit
`backup_version`, `temperatures`, `settings`, `utilities`, … Seit v2.6.0 darf
es auch die ganze Export-Antwort sein (`{success, data}`) — eine per
`curl …/backup/export > backup.json` gesicherte Datei lässt sich so direkt
zurückspielen.

**Ablauf seit v2.6.0:** erst prüfen, dann schreiben. Ist ein Topf keine Liste
von Objekten, fehlen Pflichtfelder oder ist ein Datum ungültig, ändert der
Import nichts und antwortet `400` mit den Fundstellen in `detail.problems`.
Vor dem Schreiben legt er einen Sicherungs-Snapshot `pre-restore-…` an;
scheitert der, antwortet er `409` — mit `?allow_without_snapshot=1` geht es
trotzdem. `?dry_run=1` endet nach der Prüfung.

**Response:**

```json
{
  "success": true,
  "data": {
    "utilities": {
      "gas":   { "meters": 1, "readings": 41, "contracts": 6, "deliveries": 0, "meter_groups": 0 },
      "strom": { "…": "…" }
    },
    "untouched": [],
    "problems": [],
    "temperatures": 1277,
    "settings": 21,
    "reminders": 6,
    "recommendations_dismissed": 0,
    "auto_snapshot_before_restore": "pre-restore-2026-09-25_000438.json"
  }
}
```

`untouched` nennt Töpfe, die im Backup fehlen und deshalb unverändert
bleiben (Teil-Restore). Bei `dry_run` steht statt des Snapshots
`"dry_run": true`.

### `POST /api/backup/snapshot`

Legt einen Snapshot unter `data/backups/backup_YYYY-MM-DD_HHMMSS.json` ab.

**Response:** `{ "success": true, "data": { "file": "backup_2026-09-25_001317.json" } }`

Liste, Download, Einspielen und Löschen: `GET /api/backup/snapshots`,
`GET|DELETE /api/backup/snapshots/{name}`,
`POST /api/backup/snapshots/{name}/restore` *(v2.6.0)* — siehe
[API-Referenz](technical/03-api-reference.md#snapshots-und-import-v260).

---

## CSV-Export

Tabellarischer Export für Tabellenkalkulationen (F-07, seit v1.1.0).
Drei Datensätze, jeweils als **Datei-Download** — die Antwort ist
**kein** JSON, sondern `text/csv` mit `Content-Disposition: attachment`.
Format: Semikolon-getrennt, UTF-8 mit BOM (Excel erkennt die Kodierung),
CRLF-Zeilenenden, deutsches Dezimalkomma, ISO-Datumsangaben.

Ergänzt das vollständige JSON-Backup — für eine wieder-importierbare
Sicherung weiterhin `GET /api/backup/export` verwenden.

### `GET /api/export/{utility}/monthly.csv`

Monatsaggregate einer Verbrauchsart über alle Zähler: Monat, Tage,
Verbrauch, Kosten, Abschlag, Monatssaldo, kumulierter Saldo, ø Temperatur,
HGT, CO₂.

### `GET /api/export/{utility}/readings.csv`

Alle Rohablesungen einer Verbrauchsart, eine Zeile pro Ablesung: Zähler-ID,
Zählername, Geräte-ID, Datum, Zählerstand, Preis, Notiz, geschätzt-Flag,
Zukunft-Flag.

### `GET /api/export/temperatures.csv`

Die Temperaturreihe als Tageswerte: Datum, ø, Min, Max.

---

## Migration aus v0.9.0

Zweistufiger Flow — Preview (kein Schreibvorgang) gefolgt von Import
mit Modus-Auswahl. Detaillierte Anleitung siehe
[`MIGRATION-FROM-V090.md`](MIGRATION-FROM-V090.md).

### `POST /api/migration/v09/preview`

**Body:**

```json
{ "backup": <v0.9.0-Backup-Objekt> }
```

**Response:**

```json
{
  "success": true,
  "data": {
    "ok": true,
    "legacy_version": "2.1",
    "translated": { ... vollständig übersetzter Inhalt ... },
    "report": {
      "readings":     { "gas": 52, "strom": 22, "wasser": 0 },
      "contracts":    { "gas": 8,  "strom": 4,  "wasser": 0 },
      "temperatures": 1131,
      "settings":     20,
      "warnings":     [ "v0.9.0 kennt kein Wasser — ..." ],
      "device_replacement_candidates": [
        { "utility": "strom", "reading_id": "...", "date": "2020-07-22", "counter": 6, "comment": "Zählerwechsel", "reason": "..." }
      ]
    }
  }
}
```

### `POST /api/migration/v09/import`

**Body:**

```json
{ "translated": <preview.data.translated>, "mode": "replace" }
```

`mode` ∈ `"replace" | "merge"`.

**Response:**

```json
{
  "success": true,
  "data": {
    "mode": "replace",
    "snapshot": "backup_2026-05-11_113000.json",
    "written": {
      "gas":    { "meters": 1, "readings": 52, "contracts": 8 },
      "strom":  { "meters": 1, "readings": 22, "contracts": 4 },
      "wasser": { "meters": 1, "readings": 0,  "contracts": 0 }
    }
  }
}
```

Im `merge`-Modus enthält jedes Utility zusätzlich ein
`skipped`-Feld mit den Anzahlen wegen ID-Kollision übersprungener Einträge.

---

## Home-Assistant-Anbindung (F1009, ab v1.9.0)

> **Hinweis für Home-Assistant-Nutzer:** In Foren kursiert eine fehlerhafte
> Anleitung mit `POST /api.php` und Feldern `action`/`value`/`timestamp`. Das
> ist **falsch**. Die korrekte, offizielle Schnittstelle ist unten beschrieben;
> die ausführliche Schritt-für-Schritt-Anleitung steht in
> [`docs/HOME-ASSISTANT.md`](HOME-ASSISTANT.md).

### Authentifizierungs-Modell (opt-in)

Der Token schützt **nur** den Ingest-Endpoint: Ohne Token nimmt er Werte ohne
Kopfzeile an, sobald ein Token erzeugt wurde, verlangt er
`Authorization: Bearer <token>`. Die übrigen Routen schützt seit v2.6.0 die
**Anmeldung** (opt-in, Einstellungen → „Anmeldung & Zugriff"); ist sie
eingeschaltet, ist der Token für den Ingest **Pflicht**. Details:
[Sicherheit](technical/08-security.md).

### `GET /api/auth/token`

Status (nie der Token selbst):

```json
{ "success": true, "data": { "enabled": true, "created_at": "2026-06-01T12:00:00+02:00",
                             "last_used_at": "2026-09-24T18:00:00+02:00" } }
```

`last_used_at` *(v2.6.0)*: letzter Push mit diesem Token, auf die Stunde
genau — `null`, solange nichts angekommen ist.

### `POST /api/auth/token`

Erzeugt einen neuen Token (ersetzt einen vorhandenen). Der Klartext-Token wird
**nur in dieser Antwort** zurückgegeben — danach ist nur noch sein SHA-256-Hash
gespeichert (in `data/auth.json`, nicht in `settings.json`; vom Backup
ausgenommen).

```json
{ "success": true, "data": { "token": "et_…48hex…", "created_at": "…", "hint": "…" } }
```

### `DELETE /api/auth/token`

Widerruft den Token → der Ingest ist wieder ohne Token erreichbar (nur ohne
Anmeldung; mit Anmeldung lehnt er dann jeden Push mit `401` ab).

### `POST /api/ingest`

Idempotenter Push-Endpoint für externe Datenlieferanten (Home Assistant).
**Upsert-by-date:** existiert für den Zähler bereits eine Ablesung am selben
Datum, wird sie aktualisiert; sonst neu angelegt. Ein wiederholter Push am
selben Tag erzeugt also **keine** Duplikate.

**Header:** `Authorization: Bearer <token>` (nur falls ein Token gesetzt ist).

**Body:**

```json
{
  "utility": "strom",
  "meter": "stromzaehler_haus",
  "value": 12345.6,
  "date": "2026-06-01"
}
```

- `utility` — Verbrauchsart mit Zählerständen (`gas|strom|wasser|fernwaerme|
  pv_einspeisung|pv_erzeugung`; Heizöl/Pellets werden abgelehnt — sie nutzen
  Lieferungen statt Ablesungen).
- `meter` — **Alias** (`external_id`) **oder** interne Meter-ID. Alias zuerst.
- `value` — Zählerstand (Zahl). Alias `counter` wird ebenfalls akzeptiert.
- `date` — optional, Default heute. Akzeptiert `YYYY-MM-DD`; ein voller
  ISO-Zeitstempel (z. B. HA `now().isoformat()`) wird auf das Datum gekürzt.

**Response** (`201` bei neu, `200` bei Aktualisierung):

```json
{
  "success": true,
  "data": {
    "status": "created",
    "utility": "strom",
    "meter_id": "m_strom_main",
    "date": "2026-06-01",
    "counter": 12345.6,
    "reading_id": "20260601-ab12cd34",
    "suspect": false
  }
}
```

`suspect` *(v2.6.0)*: Ist der Wert kleiner als der vorige Stand desselben
Geräts, wird er gespeichert, aber als Verdacht markiert (`"suspect": true`,
dazu `"previous": {"date", "counter"}`) und zählt erst nach Bestätigung in
der Oberfläche (Ansicht der Verbrauchsart). Ein Sensor-Aussetzer mit 0 kann
damit keinen Phantomverbrauch mehr erzeugen. Ein Überlauf des Zählwerks
(99.998 → 12) ist kein Verdacht, wenn am Zähler die Stellenzahl (`digits`)
gepflegt ist.

**Fehler:** `401` (Token nötig/falsch; mit eingeschalteter Anmeldung auch ohne
gesetzten Token), `400` (unbekannte Utility, Zähler nicht gefunden,
kein/ungültiger Wert, Delivery-Utility).
