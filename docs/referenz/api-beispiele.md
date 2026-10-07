# API-Beispiele

**Deutsch** · [English](../en/referenz/api-beispiele.md)

[← Kompendium-Index](../README.md)

> **Hinweis:** Maßgeblich für Pfade, Felder, Statuscodes und die
> Stabilitätszusage ist die [API-Referenz](api.md) — vollständige Routenliste,
> von einem Test gegen den Code geprüft. Diese Seite ist der **Leitfaden mit
> ausführlichen Beispielen** für die meistgenutzten Endpunkte (inhaltlich
> zuletzt mit v2.6.0 überarbeitet; bis v2.13 lag sie als `docs/API.md`). Bis v2.5.3 wich es an mehreren
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
Statuscodes (400, 401, 403, 404, 405, 409, 421, 422, 429, 500, 502, 503) und ihre
Bedeutung: [API-Referenz → Statuscodes](api.md#statuscodes-aller-endpunkte).

---

## Route-Übersicht

Die vollständige Liste aller Routen steht in der
[API-Referenz](api.md#1-vollständige-routen-übersicht) —
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
    "app_version": "2.10.0",
    "schema_version": "1.6.0",
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

Liefert die statische Konfiguration der neun Verbrauchsarten (`gas`,
`strom`, `wasser`, `fernwaerme`, `heizoel`, `pellets`, `pv_einspeisung`,
`pv_erzeugung`, seit v3.1.0 `waerme`; single source of truth aus
`src/Config/Utilities.php`).

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
Feldliste: [API-Referenz](api.md#länderprofil-country-currency-timezone-gas_cv_unit-v270-additiv).

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

**Body:** Plain-Text (Content-Type `text/plain`), eine Zeile je Tag: Datum,
Mittel, Minimum, Maximum. Üblich ist `TT.MM.JJJJ;Mittel;Min;Max` mit
Dezimalkomma (seit v2.12.0); Tabulator und das alte Format mit `"` als Trenner
werden ebenfalls gelesen. Seit v3.1.0 auch jede eigene Export-Datei (Format 1
und „local“ jeder Sprache): Datum als `T/M/JJJJ`, `T-M-JJJJ` oder ISO, Komma
als Feldtrenner bei Dezimalpunkt, Kopfzeile in jeder Sprache.

```
Datum;Mittel (°C);Min (°C);Max (°C)
15.01.2024;4,2;-1,0;7,1
16.01.2024;3,8;0,5;6,9
```

```
Date,Mean (°C),Min (°C),Max (°C)
15/01/2024,4.2,-1,7.1
```

**Response:**

```json
{ "success": true, "data": { "imported": 365, "skipped": 0, "errors": [] } }
```

Zeilen ohne gültiges Datum oder mit fehlenden Werten zählen unter `skipped`
— auch ein Datum mit dem Monat vor dem Tag (`01/15/2024`); umgedeutet wird es
nie. Details: [API-Referenz → Import](api.md#import-v310).

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
    "archive_error_code": null,
    "forecast_error": null,
    "forecast_error_code": null,
    "measured_until": "2026-09-19",
    "forecast_until": "2026-10-08",
    "climate_normal": { "status": "fetched", "period": { "from": "1996-01-01", "to": "2025-12-31" },
                        "latitude": 51.34, "longitude": 12.37, "fetched_at": "…" }
  }
}
```

Ist Open-Meteo nicht erreichbar, steht der Grund in der Sprache der Anfrage
in `archive_error` bzw. `forecast_error` und als stabiler Code (seit v3.1.0)
in `archive_error_code` bzw. `forecast_error_code` — etwa `network`, `http`
oder `noDays`:

```json
"archive_error": "Open-Meteo hat mit HTTP 503 geantwortet. Später erneut versuchen.",
"archive_error_code": "http"
```

Jeder Tag in `GET /api/temperatures` trägt seit v2.8.0 `source`
(`archive`, `forecast`, `csv`, `manual`); Vorhersagen werden durch
Archivwerte ersetzt, sobald diese vorliegen. Details:
[API-Referenz](api.md).

### `DELETE /api/temperatures/{date}`

Löscht einen einzelnen Tag (`date` als `YYYY-MM-DD`).

---

## Meters & Devices

### `GET /api/utility/{utility}/meters`

`{utility}` ∈ `gas | strom | wasser | fernwaerme | heizoel | pellets |
pv_einspeisung | pv_erzeugung | waerme` (Heizwärme seit v3.1.0).

**Response:** Array von Meter-Objekten (Schema:
[Datenmodell](datenmodell.md)).

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

**Rolle und Erfassungsart (v3.1.0).** `role` sagt, wofür der Zähler steht
(etwa `warm` für einen Warmwasserzähler), `capture: "period"` macht ihn zum
Zähler mit Verbrauch je Zeitraum. Ein Warmwasserzähler und ein Wärmezähler für
die monatliche Verbrauchsinfo:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"name":"Warmwasser Bad","role":"warm"}' \
  'http://nas.local:8080/api.php/api/utility/wasser/meters'

curl -X POST -H 'Content-Type: application/json' \
  -d '{"name":"Wärmezähler","capture":"period"}' \
  'http://nas.local:8080/api.php/api/utility/waerme/meters'
```

Die Standardrolle (`cold`, `household` …) und `capture: "counter"` werden
nicht gespeichert. Rollen je Art und Fehlercodes:
[API-Referenz → Zähler](api.md#zähler-role-capture-v310-additiv).

### `PATCH /api/utility/{utility}/meters/{id}`

**Body:** beliebige Teilmenge
`{ name, icon, active, notes, parent_meter_id, meter_group_id, external_id }`;
seit v3.1.0 auch `role` und `capture` (die Erfassungsart nur, solange der
Zähler keine Daten der bisherigen Art hat, sonst `400`
`errors.meter.captureLocked`).

- `parent_meter_id` / `meter_group_id` — Meter-Topologie (F1006, ab Schema 1.2.0).
- `external_id` — frei vergebbarer Alias für die Home-Assistant-Anbindung
  (F1009, ab Schema 1.3.0). Pro Utility eindeutig; erlaubt sind 1–64 Zeichen aus
  `[A-Za-z0-9_.-]`. Leerer Wert/`null` entfernt den Alias.

### `DELETE /api/utility/{utility}/meters/{id}`

Löscht den Zähler. Hat er Ablesungen, Lieferungen, Verträge oder Subzähler,
lehnt die Route mit `400` ab (`errors.meter.hasReadings`,
`errors.meter.hasDeliveries`, `errors.meter.hasContracts`,
`errors.meter.isParent`) — gelöscht wird nichts davon mit. Zeiträume eines
Zählers mit Verbrauch je Zeitraum (v3.1.0) prüft die Route nicht; sie vorher
löschen.

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

**Nachsenden ohne Doppel (`client_ref`, v3.1.0).** Wer nach einem Zeitlimit
nicht weiß, ob der Stand angekommen ist, schickt ihn mit derselben Kennung
noch einmal. Die Kennung wählt der Client: 8–64 Zeichen aus Buchstaben,
Ziffern und `-`.

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_gas_main","date":"2026-10-07","counter":24990.5,"client_ref":"keller-2026-10-07-gas"}' \
  'http://nas.local:8080/api.php/api/utility/gas/readings'
```

Der erste Versuch antwortet `201` mit dem neuen Stand. Jeder weitere mit
derselben `client_ref` am selben Zähler legt nichts an und antwortet `200`:

```json
{
  "success": true,
  "data": {
    "id": "20261007-9f8e7d6c", "meter_id": "m_gas_main", "device_id": "d_gas_001",
    "date": "2026-10-07", "counter": 24990.5, "price_cents": null, "note": "",
    "is_estimated": false, "is_future": false,
    "client_ref": "keller-2026-10-07-gas",
    "duplicate": true
  }
}
```

Eine Kennung im falschen Format → `400` `errors.reading.clientRefInvalid`. Die
Oberfläche schickt bei jeder Erfassung in „Zählerstände“ eine `client_ref` mit
(Offline-Warteschlange).

Ein Zähler mit Verbrauch je Zeitraum (v3.1.0) hat keine Stände: `POST` und der
CSV-Import antworten dort `400` `errors.reading.periodMeter` — der Wert gehört
in einen Zeitraum ([s. u.](#verbrauch-je-zeitraum-v310)).

### `PATCH /api/utility/{utility}/readings/{id}`

**Body:** beliebige Teilmenge der Reading-Felder. Seit v3.1.0 auch
`attachment_id`: eine andere ID ersetzt das Foto, `null` löst es
([Belege](#belege-und-fotos-v310)).

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

Seit v3.1.0 liest der Import jede eigene Export-Datei wieder ein — Format 1
und die Tabelle in jeder Sprache —, etwa eine französische:

```
ID compteur;Compteur;ID appareil;Date;Index;Prix (ct);Note;Estimé;Futur
m_gas_main;Compteur principal;d_gas_1;01/02/2026;12345,6;;;Non;Non
```

- **Trenner:** `;` bevorzugt, `,` als Fallback (entschieden an der ersten
  Zeile).
- **Datum:** ISO `JJJJ-MM-TT` oder Tag vor Monat mit vierstelligem Jahr —
  `T.M.JJJJ`, `T/M/JJJJ`, `T-M-JJJJ` (seit v3.1.0; bis v3.0 nur
  `TT.MM.JJJJ`). Ein Datum mit dem Monat vor dem Tag (`01/15/2026`) wird nicht
  umgedeutet, sondern als Fehler gemeldet.
- **Kopfzeile:** Spalten werden über ihren Namen gefunden, in jeder Sprache
  der App; ohne Kopfzeile gilt die Reihenfolge Datum, Stand, Notiz,
  geschätzt. Enthält die Datei eine Spalte mit der Zähler-ID (eigener Export
  mehrerer Zähler), übernimmt der Import nur die Zeilen dieses Zählers.
- **Zählerstand:** Dezimalkomma oder -punkt; Punkt, Leerzeichen oder
  Apostroph als Tausendertrenner werden erkannt.
- **notiz** und **geschaetzt** sind optional; „geschätzt“ gilt bei
  `true`, `1`, `x`, `ja` und dem Ja jeder Sprache (`oui`, `sì`, `sí` …),
  sonst und leer `false`.

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

`errors` enthält pro nicht verarbeitbarer Zeile eine Meldung mit
Zeilennummer, seit v3.1.0 in der Sprache der Anfrage (bis v3.0 deutsch):

```json
"errors": [ "Zeile 4: Monat über 12 (01/15/2026) — Datum bitte mit dem Tag vor dem Monat (TT.MM.JJJJ, TT/MM/JJJJ) oder als JJJJ-MM-TT" ]
```

Die Importlogik steckt im quell-agnostischen
`ReadingImportService` — externe Datenquellen wie die
[Home-Assistant-Anbindung](../anleitungen/home-assistant.md) (`POST /api/ingest`) nutzen
denselben Kern ohne CSV-Parsing wieder.

---

## Verbrauch je Zeitraum *(v3.1.0)*

Für Werte, die schon als Verbrauch vorliegen — etwa die monatliche
Verbrauchsinfo des Messdienstes. Der Zähler braucht `capture: "period"`
([Zähler anlegen](#post-apiutilityutilitymeters)); er nimmt dann keine Stände
mehr an. Felder, Regeln und Fehlercodes:
[API-Referenz → Verbrauch je Zeitraum](api.md#verbrauch-je-zeitraum-v310).
Die Werte unten sind Beispiele.

### Einen Monat eintragen — `POST /api/utility/{utility}/periods`

`month` ist die Kurzform für einen ganzen Monat; die Vergleichswerte der
Verbrauchsinfo (`reference`) sind optional und nur zur Anzeige:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_waerme_1a2b3c4d","month":"2026-09","value":410,
       "reference":{"prev_month":180,"prev_year_month":450,"average_user":390},
       "client_ref":"uvi-2026-09-waerme"}' \
  'http://nas.local:8080/api.php/api/utility/waerme/periods'
```

**Response** (`201`):

```json
{
  "success": true,
  "data": {
    "id": "p_8f2c41d0a9b3", "meter_id": "m_waerme_1a2b3c4d",
    "from": "2026-09-01", "to": "2026-09-30",
    "value": 410, "value_unit": "consumption", "is_estimated": false,
    "source": "manual", "note": "",
    "reference": { "prev_month": 180, "prev_year_month": 450, "average_user": 390 },
    "client_ref": "uvi-2026-09-waerme"
  }
}
```

Derselbe Aufruf noch einmal (gleiche `client_ref`) legt nichts an und
antwortet `200` mit `duplicate: true`. Ein beliebiger Zeitraum geht mit
`from` und `to` (beide einschließlich) statt `month`. Überschneidet er sich mit
einem vorhandenen → `400` `errors.period.overlap`. Bei Gas lässt sich der Wert
auch in m³ eintragen (`"value_unit": "meter"`), dann rechnet die App mit den
datierten Umrechnungsfaktoren.

Lesen, ändern, löschen:

```bash
curl 'http://nas.local:8080/api.php/api/utility/waerme/periods?meter_id=m_waerme_1a2b3c4d'
curl -X PATCH -H 'Content-Type: application/json' -d '{"value":415,"note":"korrigiert"}' \
  'http://nas.local:8080/api.php/api/utility/waerme/periods/p_8f2c41d0a9b3'
curl -X DELETE 'http://nas.local:8080/api.php/api/utility/waerme/periods/p_8f2c41d0a9b3'
```

### Viele Monate aus CSV — `POST /api/utility/{utility}/meters/{id}/periods/import-csv`

Der Body ist der rohe CSV-Text. Je Zeile ein Monat und der Verbrauch, oder
`von;bis;wert`; eine Kopfzeile ist optional:

```
monat;wert;notiz
01.2026;1480;Verbrauchsinfo
02.2026;1210
2026-03;980
01.04.2026;30.04.2026;520
```

Erst als Trockenlauf — es wird nichts geschrieben:

```bash
curl -X POST -H 'Content-Type: text/plain' --data-binary @verbrauchsinfo.csv \
  'http://nas.local:8080/api.php/api/utility/waerme/meters/m_waerme_1a2b3c4d/periods/import-csv?dry_run=1'
```

```json
{
  "success": true,
  "data": {
    "imported": 0, "skipped": 0, "errors": [],
    "dry_run": true, "would_import": 4,
    "rows": [
      { "line": 2, "from": "2026-01-01", "to": "2026-01-31", "value": 1480, "note": "Verbrauchsinfo" },
      { "line": 3, "from": "2026-02-01", "to": "2026-02-28", "value": 1210, "note": "" },
      { "line": 4, "from": "2026-03-01", "to": "2026-03-31", "value": 980, "note": "" },
      { "line": 5, "from": "2026-04-01", "to": "2026-04-30", "value": 520, "note": "" }
    ]
  }
}
```

Dann ohne `?dry_run=1`: Die Antwort nennt `imported`, `skipped` und `errors`.
Eine Zeile, die sich mit einem vorhandenen Zeitraum überschneidet, wird
übersprungen und in `errors` gemeldet („Zeile 3: Der Zeitraum überschneidet
sich mit einem vorhandenen (…)“), nicht überschrieben. Die eigene Datei aus
`GET /api/export/waerme/periods.csv` lässt sich ebenso wieder einlesen.

---

## Belege und Fotos *(v3.1.0)*

Ein Foto zum Zählerstand in zwei Schritten: erst die Datei hochladen, dann die
Ablesung mit der ID des Belegs anlegen. Felder, Grenzen und Fehlercodes:
[API-Referenz → Belege](api.md#belege-und-texterkennung-v310).

### Foto hochladen — `POST /api/attachments`

Die Datei geht als **roher Body**, nicht als Formular (`multipart`). Den Typ
bestimmt der Server am Inhalt; der `Content-Type` ist nur Höflichkeit.

```bash
curl -X POST --data-binary @zaehler.jpg -H 'Content-Type: image/jpeg' \
  'http://nas.local:8080/api.php/api/attachments?kind=reading_photo'
```

**Response** (`201`):

```json
{
  "success": true,
  "data": {
    "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "mime": "image/jpeg",
    "size": 284113, "sha256": "9c1e…", "created_at": "2026-10-07T08:12:40+02:00",
    "ref": null
  }
}
```

Über die API nimmt der Server das Foto, wie es kommt. Verkleinern und das
Entfernen der EXIF-Daten (samt GPS-Ort) übernimmt nur die Oberfläche im
Browser — ein Skript sollte das selbst tun, bevor es hochlädt. Höchstens 3 MB
je Foto.

### Ablesung mit Foto anlegen

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_gas_main","date":"2026-10-07","counter":24990.5,"attachment_id":"att_5f0c2a9e81d34b67"}' \
  'http://nas.local:8080/api.php/api/utility/gas/readings'
```

Die Antwort ist die neue Ablesung mit `attachment_id`; der Beleg trägt danach
`ref: {"type": "reading", "utility": "gas", "id": "…"}`. Ein Beleg, der nach
24 Stunden an keiner Ablesung hängt, wird aufgeräumt.

Mit eingeschalteter Anmeldung braucht jeder Aufruf einen API-Schlüssel mit
Berechtigung „Verwalten“: `-H 'Authorization: Bearer etk_…'`.

### Foto abrufen und löschen

```bash
curl -o foto.jpg 'http://nas.local:8080/api.php/api/attachments/att_5f0c2a9e81d34b67'
curl -X DELETE 'http://nas.local:8080/api.php/api/attachments/att_5f0c2a9e81d34b67'
```

`DELETE` entfernt Datei und Verweis sofort; die Ablesung bleibt, nur ohne
Foto. `GET /api/attachments` listet alle Belege samt Speicherbelegung
(`usage`).

### Zählerstand vom Foto lesen — `POST /api/ocr/reading`

Nur mit eingetragenem Texterkennungsdienst im Heimnetz
([Anleitung](../anleitungen/texterkennung.md)):

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"attachment_id":"att_5f0c2a9e81d34b67"}' \
  'http://nas.local:8080/api.php/api/ocr/reading'
```

```json
{
  "success": true,
  "data": { "value": 24990.5, "confidence": 0.9, "raw": "{\"value\": 24990.5, \"confidence\": 0.9}",
            "model": "qwen2.5vl", "duration_ms": 31250 }
}
```

Gespeichert wird dabei nichts — wer den Wert übernehmen will, legt die
Ablesung wie oben an. Antwortet der Dienst nicht, zu spät oder unbrauchbar,
kommt `502` (`errors.ocr.timeout`, `errors.ocr.unreachable`,
`errors.ocr.badAnswer`); ohne Dienst oder mit einer Adresse außerhalb des
Heimnetzes `400` (`errors.ocr.off`, `errors.ocr.notLocal`).

---

## Contracts

### `GET /api/utility/{utility}/contracts`

Query-Parameter `meter_id` filtert auf einen Zähler.

### `POST /api/utility/{utility}/contracts`

**Body für Gas / Strom:**

```json
{
  "meter_id": "m_gas_main",
  "provider": "Nordwind Energie",
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
  "provider": "Wasserwerke Musterstadt",
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
        "provider": "Nordwind Energie",
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
[API-Referenz](api.md). `actual_*` beschreibt weiterhin
nur die gemessenen Monate.

`verdict` ist ein Schlüssel: `surcharge` (Nachzahlung) bei
`projected_end_balance > 5`, `refund` (Guthaben) bei `< -5`, sonst
`balanced`. Bei PV-Einspeisung ist die Achse umgedreht: `payout`, `reclaim`,
`balanced`. Die Oberfläche übersetzt den Schlüssel (`utility.verdict.*`).
Bis v1.9.x standen hier deutsche Wörter — v2.0.0 hat das **ohne Ankündigung**
geändert; genau deshalb gibt es seit v2.6.0 die
[Stabilitätszusage](api.md#stabilitätszusage-v260).

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

**Seit v2.9.0** zählen die Stufen bis zum **Kündigungsstichtag**
(`remind_basis: "cancel_by"`), sobald eine Kündigungsfrist gepflegt ist —
sonst wie bisher bis zum Ende (`"end"`). Dazu `cancel_by`,
`days_to_cancel`, `switch_date`, `notice_basis` (`fixed_end`,
`open_ended`, `min_term`, `renewed`, `unknown`), `cancel_missed`,
`renewed` (abgelaufen ohne Nachfolger, läuft weiter) und `price_increase`
(nächste eingetragene Erhöhung). Details: [API-Referenz](api.md).

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
  Negativ = Guthaben, positiv = Nachzahlung (die Oberfläche zeigt seit v2.13.0
  die Kundensicht mit Worten); der Wert des letzten Monats
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

## Mietverhältnis *(v3.1.0)*

Für Mieter, die Heizung und Wasser über die Nebenkosten zahlen: Vorauszahlung,
Preise aus der letzten Abrechnung und die zugeordneten Zähler — daraus eine
**Hilfsrechnung** für den laufenden Abrechnungszeitraum. Sie ersetzt keine
Nebenkostenabrechnung; was der Vermieter abrechnet, kann abweichen. Felder und
Regeln: [API-Referenz → Mietverhältnis](api.md#mietverhältnis-v310), Schritt für
Schritt in der Oberfläche: [Als Mieter](../anleitungen/mieter.md). Alle Werte
unten sind Beispiele.

### Mietverhältnis anlegen — `POST /api/tenancies`

Vorauszahlung 100 € Heizung und 20 € Betriebskosten im Monat, Abrechnung nach
Kalenderjahr, ein Wärmezähler mit Verbrauch je Zeitraum
([s. o.](#verbrauch-je-zeitraum-v310)):

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"label":"Wohnung","start":"2024-01-01","billing_anchor":"01-01",
       "prepayments":[{"from":"2024-01-01","heating_eur_month":100,"operating_eur_month":20}],
       "meter_ids":{"heat":["m_waerme_1a2b3c4d"]}}' \
  'http://nas.local:8080/api.php/api/tenancies'
```

**Response** (`201`):

```json
{
  "success": true,
  "data": {
    "id": "t_1a2b3c4d5e6f", "start": "2024-01-01", "label": "Wohnung", "landlord": "",
    "notes": "", "wohnflaeche_m2": null, "billing_anchor": "01-01",
    "prepayments": [ { "from": "2024-01-01", "heating_eur_month": 100, "operating_eur_month": 20 } ],
    "prices": [], "fixed_costs": [],
    "meter_ids": { "heat": ["m_waerme_1a2b3c4d"], "warm_water": [], "cold_water": [] }
  }
}
```

`PATCH /api/tenancies/{id}` ändert einzelne Felder; eine Liste
(`prepayments`, `prices`, `fixed_costs`) geht immer ganz.

### Abrechnung erfassen und Preise übernehmen — `POST /api/tenancies/{id}/statements`

Die Abrechnung für 2025: 8.600 kWh Wärme für 1.290 € Heizkosten, 180 €
Betriebskosten, 1.440 € vorausgezahlt. `apply_prices` leitet daraus den
Wärmepreis ab und trägt ihn ab dem Tag nach dem Zeitraum ins Mietverhältnis
ein:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"period_from":"2025-01-01","period_to":"2025-12-31","received_on":"2026-03-20",
       "total_cost_eur":1470,"prepaid_eur":1440,
       "positions":[{"label":"Heizung","category":"heating","amount_eur":1290},
                    {"label":"Betriebskosten","category":"operating","amount_eur":180}],
       "heat":{"consumption":8600,"unit":"kWh","cost_eur":1290},
       "apply_prices":true}' \
  'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements'
```

Die Antwort (`201`) ist die Abrechnung mit `result_eur: 30` (Kosten −
Vorauszahlung; positiv = Nachzahlung). Im Mietverhältnis steht danach:

```json
"prices": [ { "from": "2026-01-01", "heat_eur_per_kwh": 0.15,
              "source": "statement", "statement_id": "s_9e8d7c6b5a40" } ]
```

1.290 € ÷ 8.600 kWh = 0,15 €/kWh. Mit `new_prepayment` und
`"apply_prepayment": true` übernimmt derselbe Aufruf auch eine neue
Vorauszahlung. Ein PDF der Abrechnung lädst du vorher als Beleg hoch
(`POST /api/attachments?kind=statement_pdf`, [Belege](#belege-und-fotos-v310))
und gibst seine ID in `attachment_ids` mit. `received_on` setzt die
Einwendungsfrist (zwölf Monate) in Agenda und Kalender, sofern
`wohnverhaeltnis` auf `miete` steht.

### Was kommt auf mich zu? — `GET /api/tenancies/{id}/budget`

```bash
curl 'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/budget'
```

Handrechnung für 2026: rund 9.000 kWh Wärme im Jahr (gemessen bis September,
Oktober bis Dezember aus dem Heizmodell geschätzt) × 0,15 € = 1.350 € erwartet,
gegen 12 × 120 € = 1.440 € Vorauszahlung → **−90 €, also etwa 90 € Guthaben**:

```json
{
  "success": true,
  "data": {
    "tenancy_id": "t_1a2b3c4d5e6f",
    "period_from": "2026-01-01", "period_to": "2026-12-31", "as_of": "2026-10-07",
    "months": [
      { "ym": "2026-01", "share": 1, "heat_kwh": 1650.0, "warm_water_m3": 0, "cold_water_m3": 0,
        "expected_eur": 247.5, "prepaid_eur": 120, "balance_eur": 127.5, "measured": true },
      { "…": "…" }
    ],
    "expected_eur": 1350.0, "prepaid_eur": 1440, "projected_result_eur": -90.0,
    "to_date": { "expected_eur": 1035.0, "prepaid_eur": 1080, "result_eur": -45.0 },
    "risk": "low", "suggested_prepayment_eur": 113,
    "components": { "heat_eur": 1350.0, "warm_water_eur": 0, "cold_water_eur": 0, "fixed_eur": 0,
                    "heat_kwh": 9000.0, "warm_water_m3": 0, "cold_water_m3": 0 },
    "assumptions": [ "months_estimated" ], "months_estimated": 3
  }
}
```

`risk: "low"`, weil das Ergebnis nicht über 0 liegt; die passende
Vorauszahlung ist 1.350 € ÷ 12 = 112,50 €, aufgerundet 113 €. `to_date` zählt
nur die gemessenen Monate (Januar bis September). `?as_of=2026-06-30` rechnet
auf einen anderen Stichtag. Fehlt ein Preis zu einem zugeordneten Zähler, steht
in `assumptions` etwa `price_missing_heat` — dann fehlen diese Kosten in der
Rechnung.

Abrechnungen lesen, ändern, löschen:

```bash
curl 'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements'
curl -X PATCH -H 'Content-Type: application/json' -d '{"booked":true}' \
  'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements/s_9e8d7c6b5a40'
curl -X DELETE 'http://nas.local:8080/api.php/api/tenancies/t_1a2b3c4d5e6f/statements/s_9e8d7c6b5a40'
```

`DELETE /api/tenancies/{id}` löscht das Mietverhältnis samt seinen
Abrechnungen.

---

## Versorgerrechnung erfassen, prüfen, buchen *(v3.1.0)*

Die Jahresabrechnung des Versorgers festhalten, gegen die eigene Rechnung
halten und das Ergebnis als Sonderzahlung buchen — für Gas, Strom, Wasser und
Fernwärme. Felder und Regeln:
[API-Referenz → Versorgerrechnungen](api.md#versorgerrechnungen-v310), in der
Oberfläche: [Jahresabrechnung](../anleitungen/jahresabrechnung.md#7-laut-rechnung-erfassen-vergleichen-buchen).
Alle Werte sind Beispiele.

### Rechnung anlegen — `POST /api/utility/{utility}/bills`

Eine Stromrechnung für 2025: 3.010 kWh, 1.066,08 € brutto, darin 12,50 €
Messstellenbetrieb, 12 × 90 € Abschläge:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_strom_main","period_from":"2025-01-01","period_to":"2025-12-31",
       "issued_on":"2026-01-20",
       "invoice":{"energy_kwh":3010,"amount_eur":1066.08,"advances_paid_eur":1080},
       "items":[{"label":"Messstellenbetrieb","amount_eur":12.5,"kind":"fee"}]}' \
  'http://nas.local:8080/api.php/api/utility/strom/bills'
```

**Response** (`201`) — `result_eur` hat die App ausgerechnet (Betrag −
Abschläge; negativ = Guthaben):

```json
{
  "success": true,
  "data": {
    "id": "b_7c2e19a0f4d3", "meter_id": "m_strom_main",
    "period_from": "2025-01-01", "period_to": "2025-12-31", "issued_on": "2026-01-20",
    "kind": "annual",
    "invoice": { "energy_kwh": 3010, "amount_eur": 1066.08, "advances_paid_eur": 1080,
                 "result_eur": -13.92 },
    "items": [ { "label": "Messstellenbetrieb", "amount_eur": 12.5, "kind": "fee" } ],
    "co2": null, "note": "", "attachment_ids": [],
    "created_at": "2026-01-24T19:05:12+01:00"
  }
}
```

Ein PDF der Rechnung lädst du vorher als Beleg hoch
(`POST /api/attachments?kind=bill_pdf`, [Belege](#belege-und-fotos-v310)) und
gibst seine ID in `attachment_ids` mit.

### Vergleichen — `GET /api/utility/{utility}/bills/{id}/check`

```bash
curl 'http://nas.local:8080/api.php/api/utility/strom/bills/b_7c2e19a0f4d3/check'
```

```json
{
  "success": true,
  "data": {
    "bill_id": "b_7c2e19a0f4d3", "utility": "strom", "meter_id": "m_strom_main",
    "period_from": "2025-01-01", "period_to": "2025-12-31",
    "ours": { "kwh": 3003.0, "energy_cost": 901.47, "fixed_cost": 150.0, "bonus": 0.0,
              "items_total": 12.5, "total": 1063.97, "advances": 1080.0 },
    "invoice": { "energy_kwh": 3010, "amount_eur": 1066.08, "advances_paid_eur": 1080,
                 "result_eur": -13.92 },
    "delta": { "kwh": 7.0, "kwh_pct": 0.23, "eur": 2.11, "eur_pct": 0.2 },
    "verdict": "ok",
    "reasons": [ "estimated_reading_at_boundary", "price_change_inside", "items_not_modelled" ],
    "rows": [ "…" ]
  }
}
```

Nachgerechnet: 901,47 € Arbeitspreis + 150,00 € Grundpreis + 12,50 € Posten =
1.063,97 €. Die Rechnung liegt 7 kWh (0,23 %) und 2,11 € (0,2 %) darüber —
beides unter 1 %, also `ok`. Die Gründe nennen, woran eine Abweichung liegen
kann: ein interpolierter Stand am 1. April, ein Preiswechsel im Zeitraum und der
übernommene Posten.

### Buchen — `POST /api/utility/{utility}/bills/{id}/book`

```bash
curl -X POST 'http://nas.local:8080/api.php/api/utility/strom/bills/b_7c2e19a0f4d3/book'
```

Die Antwort ist die Rechnung mit `special_payment_id` und `contract_id`. Im
Vertrag steht danach eine Sonderzahlung
`{"date": "2026-01-20", "kind": "rueckzahlung_ohne", "amount_eur": 13.92,
"note": "Jahresabrechnung 01.01.2025 – 31.12.2025"}`. Ein zweiter Aufruf ändert
nichts. Ohne Ergebnis antwortet die Route `400` mit `code`
`errors.bill.noResult`, ohne Vertrag `errors.bill.noContract`.

Bei Gas nimmt die Rechnung zusätzlich ihre CO₂-Angaben auf; sie gehen dann dem
Standardfaktor beim CO₂-Preis vor:

```bash
curl -X PATCH -H 'Content-Type: application/json' \
  -d '{"co2":{"emissions_kg":2981.5,"cost_eur":163.98}}' \
  'http://nas.local:8080/api.php/api/utility/gas/bills/b_3f9c0a1d2e4b'
```

Liste, Löschen:

```bash
curl 'http://nas.local:8080/api.php/api/utility/strom/bills?meter_id=m_strom_main'
curl -X DELETE 'http://nas.local:8080/api.php/api/utility/strom/bills/b_7c2e19a0f4d3'
```

Eine schon gebuchte Sonderzahlung bleibt nach dem Löschen im Vertrag.

---

## CO₂-Preis und Aufteilung *(v3.1.0)*

Nur für Deutschland; sonst `supported: false`. Hintergrund:
[CO₂-Preis im Brennstoff](../verstehen/16-co2-preis.md), Felder:
[API-Referenz → CO₂-Preis und Aufteilung](api.md#co₂-preis-und-aufteilung-v310).

### CO₂-Preis abrufen — `GET /api/co2-costs`

```bash
curl 'http://nas.local:8080/api.php/api/co2-costs?year=2025'
```

```json
{
  "success": true,
  "data": {
    "supported": true, "scheme": "behg", "year": 2025,
    "rows": [
      { "utility": "gas", "kwh": 10000.0, "factor_kg_per_kwh": 0.18139,
        "emissions_kg": 1813.9, "price_eur_t": 55.0,
        "cost_eur_net": 99.76, "cost_eur_gross": 118.72, "ct_per_kwh": 1.187,
        "source": "computed", "approx": false, "coverage_days": 365 }
    ],
    "total": { "emissions_kg": 1813.9, "cost_eur_net": 99.76, "cost_eur_gross": 118.72 },
    "per_m2": { "kg": 18.1, "area_m2": 100.0, "stage": 3, "landlord_share_pct": 20 },
    "price": { "eur_t": 55.0, "assumed": false }, "vat": 0.19, "note": null
  }
}
```

10.000 kWh × 0,18139 kg/kWh = 1.813,9 kg; × 55 €/t = 99,76 € netto, mit
19 % Umsatzsteuer 118,72 € — rund 1,19 ct je kWh. Der Betrag steckt schon im
Arbeitspreis. Ohne `year` gilt das Vorjahr; `year=2020` ergibt `400`
`errors.co2.yearInvalid`.

### Anteil des Vermieters — `GET /api/co2-split`

Nur mit `wohnverhaeltnis: "miete"`:

```bash
curl 'http://nas.local:8080/api.php/api/co2-split?year=2025'
curl -o co2-2025.pdf 'http://nas.local:8080/api.php/api/reports/co2-split.pdf?year=2025'
```

Bei eigener Gastherme (`case: self_supplied`) nennt die Antwort Stufe, Anteil,
`landlord_amount_eur` und `deadline`, die Frist für die Erstattung; das PDF ist
das Anschreiben an den Vermieter. Bei Zentralheizung (`case: central`) prüft sie
die CO₂-Angaben der Nebenkostenabrechnung und meldet Abweichungen in `checks`.
Beispiele beider Antworten:
[API-Referenz](api.md#co₂-preis-und-aufteilung-v310).

### Szenario in der Prognose

```bash
curl 'http://nas.local:8080/api.php/api/utility/gas/meters/m_gas_main/forecast?co2_scenario_eur_t=150&co2_scenario_from=2027'
```

Die Antwort trägt zusätzlich `co2_scenario` (`eur_t`, `from`,
`delta_ct_per_kwh`, `delta_cost_12m_eur`) und je Prognosemonat ab 2027
`co2_delta_eur`. Bei 150 statt 60 €/t sind das für Gas
(150 − 60) × 0,18139 / 10 × 1,19 = 1,94 ct je kWh mehr. Die Prognose selbst
ändert sich nicht.

---

## Gruppenvertrag für HT/NT *(v3.1.0)*

Ein Stromvertrag für die Zählergruppe `g_strom_ab12cd34` mit den Zählwerken
`m_strom_ht` und `m_strom_nt`: ein Grundpreis, zwei Arbeitspreise. Regeln:
[API-Referenz → Gruppenvertrag](api.md#gruppenvertrag-v310). Alle Werte sind
Beispiele.

### Anlegen — `POST /api/utility/strom/contracts`

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_group_id":"g_strom_ab12cd34","provider":"Beispiel-Energie","tariff_name":"Doppeltarif",
       "start":"2026-01-01",
       "working_prices":[{"from":"2026-01-01","ct_per_kwh":30}],
       "working_prices_by_meter":{"m_strom_nt":[{"from":"2026-01-01","ct_per_kwh":22}]},
       "base_prices":[{"from":"2026-01-01","eur_per_month":12}],
       "advance_payments":[{"from":"2026-01-01","amount_eur":80}]}' \
  'http://nas.local:8080/api.php/api/utility/strom/contracts'
```

`m_strom_ht` rechnet mit dem allgemeinen Arbeitspreis (30 ct), `m_strom_nt`
mit seinem eigenen (22 ct). **Response** (gekürzt):

```json
{ "success": true, "data": {
  "id": "c_9f31d2a7b0e4", "meter_id": null, "meter_group_id": "g_strom_ab12cd34",
  "working_prices": [ { "from": "2026-01-01", "ct_per_kwh": 30 } ],
  "working_prices_by_meter": { "m_strom_nt": [ { "from": "2026-01-01", "ct_per_kwh": 22 } ] },
  "base_prices": [ { "from": "2026-01-01", "eur_per_month": 12 } ], "…": "…" } }
```

Hat `m_strom_ht` im selben Zeitraum noch einen eigenen Vertrag, antwortet die
API mit `400` und `"code": "errors.contract.groupMemberOverlap"`.

### Auswerten — `GET /api/utility/strom/meter-groups/{id}/contract-status`

```bash
curl 'http://nas.local:8080/api.php/api/utility/strom/meter-groups/g_strom_ab12cd34/contract-status'
curl 'http://nas.local:8080/api.php/api/utility/strom/meter-groups/g_strom_ab12cd34/forecast'
```

Dieselbe Form wie für einen Zähler; in der Prognose trägt der Vertrag
`working_prices_blended: true` (Mischpreis aus HT und NT). Der
`contract-status` eines Mitglieds verweist mit
`"group_contract": {"group_id": "g_strom_ab12cd34", "contract_id": "c_9f31d2a7b0e4"}`
auf den Gruppenvertrag.

---

## Börsenstrompreise und Dynamik-Check *(v3.1.0)*

Regeln und Formel: [API-Referenz → Börsenstrompreise](api.md#börsenstrompreise-und-dynamik-check-v310).

### Von SMARD laden — `POST /api/market-prices/sync-smard`

Der einzige Abruf dieser Art, nur auf Anfrage:

```bash
curl -X POST 'http://nas.local:8080/api.php/api/market-prices/sync-smard'
```

```json
{ "success": true, "data": { "months": 48, "from": "2022-11", "to": "2026-10" } }
```

### Oder aus einer Datei — `POST /api/market-prices/import-csv?dry_run=1`

```bash
curl -X POST -H 'Content-Type: text/plain' --data-binary $'2025-01;114,14\n2025-02;128,20\n' \
  'http://nas.local:8080/api.php/api/market-prices/import-csv?dry_run=1'
```

```json
{ "success": true, "data": { "months": 2, "from": "2025-01", "to": "2025-02",
  "rows": 2, "skipped": 0, "would_import": 2,
  "preview": { "2025-01": { "avg_ct": 11.414 }, "2025-02": { "avg_ct": 12.82 } } } }
```

Die Werte in €/MWh werden zu ct/kWh (÷ 10). Ohne `dry_run` sind sie
gespeichert; `GET /api/market-prices` liefert sie mit der Namensnennung.

### Dynamisches Angebot anlegen

Ein Schattenvertrag Strom mit Preismodell `dynamic`:

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"meter_id":"m_strom_main","is_shadow":true,"shadow_label":"Dynamisch (Beispiel)",
       "start":"2026-11-01","price_model":"dynamic",
       "dynamic":{"markup_ct_per_kwh":15,"base_eur_month":10,"vat_pct":19}}' \
  'http://nas.local:8080/api.php/api/utility/strom/contracts'
```

`GET …/meters/m_strom_main/tariff-switch` führt das Angebot dann mit
`"price_model": "dynamic"` und `dynamic_assumed_months` (Monate, für die der
Vorjahresmonat angenommen wurde). Fehlen Börsenpreise, fehlt das Angebot, und
die Antwort trägt `"dynamic_missing_market": true`.

---

## Ladestrom-Nachweis *(v3.1.0)*

Für die Wallbox `m_wallbox` (Rolle `ev_charger`, Subzähler des
Haushaltszählers). Felder: [API-Referenz → Ladestrom-Nachweis](api.md#ladestrom-nachweis-v310).

```bash
# Vertragspreis, als JSON
curl 'http://nas.local:8080/api.php/api/reports/ev-charging?meter_id=m_wallbox&year=2026&method=contract'

# Strompreispauschale, als CSV im Format 1
curl -o ladestrom.csv \
  'http://nas.local:8080/api.php/api/reports/ev-charging.csv?meter_id=m_wallbox&year=2026&method=flat'

# Aufstellung als PDF
curl -o ladestrom.pdf \
  'http://nas.local:8080/api.php/api/reports/ev-charging.pdf?meter_id=m_wallbox&year=2026&method=flat'
```

Die CSV (Pauschale 34 ct/kWh, erfundene Mengen):

```text
Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode
2026-01;m_wallbox;180,5;34;0;61,37;flat
2026-02;m_wallbox;162;34;0;55,08;flat
```

Für ein Jahr ohne Pauschale im Länderprofil gibst du sie mit `&flat_ct=…` mit;
sonst antwortet die API mit `400` und `"code": "errors.evReport.flatMissing"`.

---

## Zeitreihe aus einem Portal *(v3.1.0)*

Ein Lastgang des Netzbetreibers (Viertelstunden in kWh, Endstempel) als
Trockenlauf auf den Stromzähler `m_strom_main`; der Stand am 1. Januar ist
`40211`. Felder: [API-Referenz → Zeitreihe importieren](api.md#zeitreihe-importieren-v310).

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{"csv":"Datum;Uhrzeit;Wert (kWh)\n01.01.2025;00:15;0,061\n01.01.2025;00:30;0,058\n02.01.2025;00:00;0,071",
       "mapping":{"skip_rows":1,"date_col":0,"time_col":1,"value_col":2,
                  "value_kind":"consumption","unit_factor":1,"interval_stamp":"end",
                  "start_counter":40211}}' \
  'http://nas.local:8080/api.php/api/utility/strom/meters/m_strom_main/import-series?dry_run=1'
```

```json
{ "success": true, "data": {
  "rows_read": 3, "skipped": 0, "errors": [], "days": 1,
  "from": "2025-01-01", "to": "2025-01-01", "total": 0.19,
  "value_kind": "consumption",
  "preview": [ { "date": "2025-01-01", "value": 0.19 } ],
  "target": "readings", "readings": 2, "dry_run": true } }
```

Alle drei Viertelstunden gehören zum 1. Januar — die letzte endet um 0:00 des
2. Januar. Ohne `dry_run` entstehen zwei Stände: 40211 am 1. Januar (der
Startwert) und 40211,19 am 2. Januar.

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
    },
    "attachments": [ { "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "…": "…" } ],
    "attachment_files": { "att_5f0c2a9e81d34b67": "/9j/4AAQSkZJRgABAQ…" }
  }
}
```

Seit v3.1.0 stehen die Belege im Backup: der Index unter `attachments`, die
Dateien base64-kodiert unter `attachment_files`. Das Format bleibt `3.0`.
`?attachments=0` lässt die Dateien weg (der Index bleibt) — für eine schnelle
Sicherung der Zahlen:

```bash
curl -o backup-ohne-fotos.json 'http://nas.local:8080/api.php/api/backup/export?attachments=0'
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
    "attachments": 12,
    "attachment_files": 12,
    "auto_snapshot_before_restore": "pre-restore-2026-09-25_000438.json"
  }
}
```

`untouched` nennt Töpfe, die im Backup fehlen und deshalb unverändert
bleiben (Teil-Restore). Bei `dry_run` steht statt des Snapshots
`"dry_run": true`. Seit v3.1.0 zählt `attachment_files` die Belegdateien des
Backups; jede wird vorher gegen `sha256` und Inhaltstyp ihres Indexeintrags
geprüft (Probleme `sha256:<id>`, `mime:<id>`, `unknown:<id>`). Eine schon
vorhandene Datei gleicher ID bleibt unberührt.

### `POST /api/backup/snapshot`

Legt einen Snapshot unter `data/backups/backup_YYYY-MM-DD_HHMMSS.json` ab.

**Response:** `{ "success": true, "data": { "file": "backup_2026-09-25_001317.json" } }`

Liste, Download, Einspielen und Löschen: `GET /api/backup/snapshots`,
`GET|DELETE /api/backup/snapshots/{name}`,
`POST /api/backup/snapshots/{name}/restore` *(v2.6.0)* — siehe
[API-Referenz](api.md#snapshots-und-import-v260).

---

## CSV-Export

Tabellarischer Export für Tabellenkalkulationen (F-07, seit v1.1.0).
Fünf Datensätze (seit v3.1.0 mit den Zeiträumen), jeweils als **Datei-Download** — die Antwort ist
**kein** JSON, sondern `text/csv` mit `Content-Disposition: attachment`.

Seit v3.1.0 in zwei Formaten:

- **Format 1** (Standard, `?format=1` oder ohne Parameter): eingefroren für
  Skripte — Semikolon, UTF-8 mit BOM, CRLF, Dezimalkomma ohne
  Tausendertrennung, ISO-Datum, `ja`/`nein`, feste Kopfzeilen.
- **Format „local“** (`?format=local`, optional `&lang=xx`): die Tabelle in
  einer Sprache — Kopfzeilen, Dezimalzeichen, Datum und Ja/Nein der Sprache,
  Feldtrenner `;` oder `,` passend zum Dezimalzeichen. Ohne `lang` die
  Standardsprache der Installation.

Alle Regeln, Kopfzeilen und Dateinamen:
[API-Referenz → CSV-Formate](api.md#csv-formate-v310). Ein unbekanntes
Format → 400 mit `"code": "errors.export.formatInvalid"`.

Ergänzt das vollständige JSON-Backup — für eine wieder-importierbare
Sicherung weiterhin `GET /api/backup/export` verwenden.

### `GET /api/export/{utility}/monthly.csv`

Monatsaggregate einer Verbrauchsart über alle Zähler: Monat, Tage,
Verbrauch, Kosten, Abschlag, Monatssaldo, kumulierter Saldo, ø Temperatur,
HGT, CO₂.

```
Monat;Tage;Verbrauch (kWh);Kosten (EUR);Abschlag (EUR);Monatssaldo (EUR);Saldo kumuliert (EUR);Ø Temp (°C);HGT;CO2 (kg)
2025-01;31;310,5;93,15;80;13,15;13,15;0,6;28,9;106,8
```

### `GET /api/export/{utility}/readings.csv`

Alle Rohablesungen einer Verbrauchsart, eine Zeile pro Ablesung: Zähler-ID,
Zählername, Geräte-ID, Datum, Zählerstand, Preis, Notiz, geschätzt-Flag,
Zukunft-Flag.

Format 1:

```
Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand;Preis (ct);Notiz;Geschaetzt;Zukunft
m_strom_main;Hauptzähler;d_strom_1;2025-02-01;310,5;;;nein;nein
```

`?format=local&lang=en` — Komma als Trenner, weil Englisch den Dezimalpunkt
schreibt; Namen und Notizen sind Daten und bleiben, wie sie erfasst wurden:

```
Meter ID,Meter,Device ID,Date,Reading,Price (ct),Note,Estimated,Future
m_strom_main,Hauptzähler,d_strom_1,01/02/2025,310.5,,,No,No
```

### `GET /api/export/{utility}/deliveries.csv`

Lieferungen von Heizöl oder Pellets, eine Zeile je Lieferung: Tank-ID,
Tankname, Datum, Menge, Preis je Einheit, Gesamtbetrag, Lieferant, Notiz,
geplant-Flag. Für die übrigen Verbrauchsarten 400.

### `GET /api/export/{utility}/periods.csv`

*(v3.1.0)* Verbrauch je Zeitraum, eine Zeile je Zeitraum über alle Zähler der
Art: von, bis (einschließlich), Wert, Notiz, Zähler-ID, Zählername, Einheit,
geschätzt-Flag, Quelle. Format 1:

```
Von;Bis;Wert;Notiz;Zaehler-ID;Zaehler;Einheit;Geschaetzt;Quelle
2026-09-01;2026-09-30;410;Verbrauchsinfo;m_waerme_1a2b3c4d;Wärmezähler;kWh;nein;manual
```

Der Zeitraum-Import liest die Datei wieder ein (nur die Zeilen des gewählten
Zählers).

### `GET /api/export/temperatures.csv`

Die Temperaturreihe als Tageswerte: Datum, ø, Min, Max.

---

## Migration aus v0.9.0

Zweistufiger Flow — Preview (kein Schreibvorgang) gefolgt von Import
mit Modus-Auswahl. Detaillierte Anleitung siehe
[`MIGRATION-FROM-V090.md`](../anleitungen/migration-v090.md).

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
> [`docs/HOME-ASSISTANT.md`](../anleitungen/home-assistant.md).

### Authentifizierungs-Modell (opt-in)

Der Token schützt **nur** den Ingest-Endpoint: Ohne Token nimmt er Werte ohne
Kopfzeile an, sobald ein Token erzeugt wurde, verlangt er
`Authorization: Bearer <token>`. Die übrigen Routen schützt seit v2.6.0 die
**Anmeldung** (opt-in, Einstellungen → Zugriff → „Anmeldung & Zugriff"); ist sie
eingeschaltet, ist der Token für den Ingest **Pflicht**. Details:
[Sicherheit](../betrieb/sicherheit.md).

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
  pv_einspeisung|pv_erzeugung|waerme`; Heizöl/Pellets werden abgelehnt — sie nutzen
  Lieferungen statt Ablesungen). Ein Zähler mit Verbrauch je Zeitraum
  (v3.1.0) nimmt ebenfalls keine Stände an: `400` `errors.ingest.periodMeter`.
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
kein/ungültiger Wert, Delivery-Utility, Zähler mit Verbrauch je Zeitraum).
