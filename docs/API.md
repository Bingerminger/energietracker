# API-Referenz v1.1.0

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
  "error": "Lesbare Fehlermeldung auf Deutsch",
  "detail": { "file": "...", "line": ..., "type": "..." }
}
```

`detail` ist optional und enthält Diagnose-Informationen, die der
ErrorHandler bei Exceptions hinzufügt. Der HTTP-Status-Code spiegelt
die Fehlerklasse:

| Status | Klasse | Wann |
|---|---|---|
| 200 | OK | Erfolg |
| 400 | Bad Request | `InvalidArgumentException` — Validierungsfehler, fehlende Pflichtfelder |
| 404 | Not Found | `RuntimeException` mit „nicht gefunden" im Text — Route oder Entität fehlt |
| 500 | Internal Server Error | sonstige Fehler |

---

## Route-Übersicht

| Methode | Pfad | Zweck |
|---|---|---|
| GET    | `/api/diagnostics`                                                | System-Status (PHP-Version, Schreibrechte, Schema, etc.) |
| GET    | `/api/utilities`                                                  | Liste der Verbrauchsarten + Konfiguration |
| GET    | `/api/settings`                                                   | Aktuelle Einstellungen |
| PATCH  | `/api/settings`                                                   | Einstellungen aktualisieren |
| GET    | `/api/temperatures`                                               | Tagestemperaturen als Map |
| POST   | `/api/temperatures`                                               | Einzelnes Tagesdatum upsert |
| POST   | `/api/temperatures/import-csv`                                    | CSV-Import |
| POST   | `/api/temperatures/sync-open-meteo`                               | Sync via Open-Meteo |
| DELETE | `/api/temperatures/{date}`                                        | Tagesdatum löschen |
| GET    | `/api/utility/{utility}/meters`                                   | Zähler-Liste |
| POST   | `/api/utility/{utility}/meters`                                   | Zähler anlegen |
| GET    | `/api/utility/{utility}/meters/{id}`                              | Einzelner Zähler |
| PATCH  | `/api/utility/{utility}/meters/{id}`                              | Zähler aktualisieren |
| DELETE | `/api/utility/{utility}/meters/{id}`                              | Zähler löschen |
| POST   | `/api/utility/{utility}/meters/{id}/replace-device`               | Zählertausch (F2) |
| GET    | `/api/utility/{utility}/readings`                                 | Ablesungen-Liste |
| POST   | `/api/utility/{utility}/readings`                                 | Ablesung anlegen |
| PATCH  | `/api/utility/{utility}/readings/{id}`                            | Ablesung aktualisieren |
| DELETE | `/api/utility/{utility}/readings/{id}`                            | Ablesung löschen |
| POST   | `/api/utility/{utility}/meters/{id}/readings/import-csv`          | CSV-Bulk-Import von Ablesungen (F-06) |
| GET    | `/api/utility/{utility}/contracts`                                | Vertrags-Liste |
| POST   | `/api/utility/{utility}/contracts`                                | Vertrag anlegen |
| GET    | `/api/utility/{utility}/contracts/{id}`                           | Einzelner Vertrag |
| PATCH  | `/api/utility/{utility}/contracts/{id}`                           | Vertrag aktualisieren |
| DELETE | `/api/utility/{utility}/contracts/{id}`                           | Vertrag löschen |
| GET    | `/api/utility/{utility}/consumption`                              | Monatsverbrauch (utility-weit) |
| GET    | `/api/utility/{utility}/meters/{id}/consumption`                  | Monatsverbrauch eines Zählers + Anomalien + Regressionen |
| GET    | `/api/utility/{utility}/meters/{id}/contract-status`              | Saldo-Aggregation pro Vertrag |
| GET    | `/api/utility/{utility}/meters/{id}/forecast`                     | 12-Monats-Forecast |
| GET    | `/api/backup/export`                                              | Volles Backup als JSON |
| POST   | `/api/backup/import`                                              | Backup zurückspielen (Format 3.0+) |
| POST   | `/api/backup/snapshot`                                            | Snapshot im Datenverzeichnis ablegen |
| GET    | `/api/export/{utility}/monthly.csv`                               | Monatsübersicht als CSV (F-07) |
| GET    | `/api/export/{utility}/readings.csv`                              | Zählerstände als CSV (F-07) |
| GET    | `/api/export/temperatures.csv`                                    | Temperaturreihe als CSV (F-07) |
| POST   | `/api/migration/v09/preview`                                      | v0.9.0-Backup analysieren |
| POST   | `/api/migration/v09/import`                                       | v0.9.0-Backup übernehmen |

---

## Diagnostics

### `GET /api/diagnostics`

Liefert Systemzustand und Schema-Informationen.

**Response:**

```json
{
  "success": true,
  "data": {
    "app_version": "1.1.0",
    "schema_version": "1.0.0",
    "php_version": "8.4.0",
    "data_dir": "/var/www/energietracker/data",
    "data_dir_writable": true,
    "curl_available": true,
    "time_zone": "Europe/Berlin",
    "totals": {
      "gas":    { "meters": 1, "readings": 52, "contracts": 4 },
      "strom":  { "meters": 1, "readings": 22, "contracts": 4 },
      "wasser": { "meters": 1, "readings": 12, "contracts": 1 },
      "temperatures": 1131
    }
  }
}
```

---

## Utilities

### `GET /api/utilities`

Liefert die statische Konfiguration der drei Verbrauchsarten (single
source of truth aus `src/Config/Utilities.php`).

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
      "conversion_setting": "gas_conversion_factor",
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

**Response:** sämtliche 20 Settings-Schlüssel als flaches Objekt
(siehe README → Datenmodell → Settings-Inventar).

### `PATCH /api/settings`

Partielle Aktualisierung — nur die übergebenen Schlüssel werden
geschrieben, alle anderen bleiben unverändert.

**Body:**

```json
{ "hdd_base_temp": 17, "forecast_model": "robust" }
```

**Response:** das vollständige aktualisierte Settings-Objekt.

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

Lädt Temperaturen für die in den Settings hinterlegten Koordinaten
(2023–heute aus Archiv, plus 14 Tage Forecast).

**Body:** optional `{}`. Standortdaten werden aus Settings genommen.

**Response:**

```json
{
  "success": true,
  "data": {
    "imported": 1145,
    "archive_rows": 1131,
    "forecast_rows": 14,
    "archive_error": null,
    "forecast_error": null
  }
}
```

### `DELETE /api/temperatures/{date}`

Löscht einen einzelnen Tag (`date` als `YYYY-MM-DD`).

---

## Meters & Devices

### `GET /api/utility/{utility}/meters`

`{utility}` ∈ `gas | strom | wasser`.

**Response:** Array von Meter-Objekten (Schema siehe README →
Datenmodell → Meter und Device).

### `POST /api/utility/{utility}/meters`

**Body:**

```json
{
  "name": "Gartenzwischenzähler",
  "icon": "💧",
  "notes": "Optional",
  "device": {
    "serial": "WZ-2021-AB123",
    "installed_on": "2021-04-15",
    "initial_counter": 0.0
  }
}
```

Wird automatisch mit einem Default-Device versorgt, falls keines
angegeben ist.

### `PATCH /api/utility/{utility}/meters/{id}`

**Body:** beliebige Teilmenge `{ name, icon, active, notes }`.

### `DELETE /api/utility/{utility}/meters/{id}`

Löscht den Zähler **und alle zugehörigen Readings und Verträge**
(cascade).

### `POST /api/utility/{utility}/meters/{id}/replace-device`

F2-Zählertausch: schließt das aktuelle Device und legt ein neues an.

**Body:**

```json
{
  "removed_on": "2024-08-22",
  "final_counter": 18432.5,
  "reason": "Eichfrist abgelaufen",
  "new_device": {
    "serial": "GAS-2024-CD8945",
    "installed_on": "2024-08-22",
    "initial_counter": 0.0
  }
}
```

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
`ReadingImportService` — eine künftige Smart-Meter-Anbindung kann
denselben Kern (`importRows()`) ohne CSV-Parsing wiederverwenden.

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
        "verdict": "Erstattung",
        "days_until_end": 231,
        "should_remind":  false,
        "remind_stage":   0
      }
    ]
  }
}
```

`verdict` ist `Nachzahlung` bei `projected_end_balance > 5`,
`Erstattung` bei `< -5`, sonst `Ausgeglichen`.

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
  "verdict": "Nachzahlung",
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
        "method": "blend(reg=0.75, seasonal=0.25)"
      }
    ],
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
- `method` — `seasonal_only` oder `blend(reg=…, seasonal=…)`.

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
`backup_version`, `temperatures`, `settings`, `utilities`, …

**Response:**

```json
{
  "success": true,
  "data": {
    "temperatures": 1131,
    "settings": 20,
    "utilities": {
      "gas":    { "meters": 1, "readings": 52, "contracts": 4 },
      "strom":  { ... },
      "wasser": { ... }
    }
  }
}
```

### `POST /api/backup/snapshot`

Legt einen Snapshot unter `data/backups/backup_YYYY-MM-DD_HHMMSS.json` ab.

**Response:** `{ "success": true, "data": { "path": "backup_2026-05-11_140000.json" } }`

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
