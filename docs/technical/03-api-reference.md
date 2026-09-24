# API-Referenz

**Deutsch** · [English](../en/technical/03-api-reference.md)

[← Architektur](02-architecture.md) · [Kompendium-Index](../README.md)

Alle Endpunkte unter `/api/…`. Antwort-Hülle einheitlich:

```json
{ "success": true,  "data": … }
{ "success": false, "error": "Meldung in der Sprache der Anfrage", "code": "errors.reading.dateInvalid" }
```

`{utility}` ist eine von: `gas`, `strom`, `wasser`, `fernwaerme`,
`heizoel`, `pellets`, `pv_einspeisung`, `pv_erzeugung`. Stand: **82 Routen**,
v2.6.0 — `ReleaseConsistencyTest` prüft, dass jede registrierte Route in der
Tabelle unten steht (DE und EN).

> Ausführliche Request-/Response-Beispiele für die meistgenutzten Endpunkte
> stehen in [`docs/API.md`](../API.md). Maßgeblich für Pfade und Felder ist
> **dieses** Dokument.

### Statuscodes aller Endpunkte

| Code | Wann |
|------|------|
| `400` | Ungültige Eingabe. Seit v2.5.3 auf allen Schreibpfaden: ein Datum, das kein Kalenderdatum ist (`2026-02-30`, Text), oder ein Betrag/Zählerstand, der keine Zahl ist. Bis v2.5.2 wurde beides gespeichert. Seit v2.6.0 auch Abfrageparameter außerhalb ihres Bereichs (Prognose, Jahresbericht, Temperatur-Sync). |
| `401` | `/api/ingest` bei gesetztem Token ohne oder mit falschem Bearer-Header. *(v2.6.0)* Mit eingeschalteter Anmeldung: jede nicht öffentliche Route ohne Sitzung oder API-Schlüssel (`errors.auth.required`); falsches Passwort. |
| `403` | *(v2.5.3)* Schreibende Anfrage (`POST`/`PUT`/`PATCH`/`DELETE`) aus dem Browser einer **fremden** Webseite — geprüft über `Sec-Fetch-Site`, ersatzweise `Origin` gegen `Host`. Anfragen ohne diese Kopfzeilen (Home Assistant, curl, Skripte) sind nicht betroffen. *(v2.6.0)* Schreibende Anfrage mit einem Lese-Schlüssel (`errors.auth.readOnlyKey`). |
| `404` | Unbekannte Route oder unbekannter Datensatz. Seit v2.6.0 einheitlich auch für Datensätze in der URL (bis v2.5.3 teils 400). |
| `405` | *(v2.6.0)* Pfad bekannt, Methode nicht — mit Kopfzeile `Allow`. `HEAD` wird wie `GET` beantwortet, `OPTIONS` mit `204` und `Allow`. Bis v2.5.3: `404`. |
| `409` | *(v2.6.0)* Der Sicherungs-Snapshot vor einem Import/Einspielen ist gescheitert (`errors.backup.snapshotFailed`) — mit `?allow_without_snapshot=1` trotzdem möglich. Passwort oder Anmeldemodus sind über die Umgebung festgelegt. |
| `421` | *(v2.6.0)* Hostname nicht in `ET_ALLOWED_HOSTS` (nur, wenn gesetzt; IP-Adressen und `localhost` sind immer erlaubt). |
| `429` | *(v2.6.0)* Anmeldung nach fünf Fehlversuchen innerhalb von 15 Minuten für 5 Minuten gesperrt (`errors.auth.locked`). |
| `503` | *(v2.5.3)* Eine Datendatei ist beschädigt (kein gültiges JSON). Die Datei bleibt unverändert, daneben liegt eine Quarantäne-Kopie `<datei>.corrupt-<prüfsumme>`. Bis v2.5.2 wurde sie als leer gelesen und beim nächsten Schreiben überschrieben. *(v2.6.0)* Die Daten stammen von einer **neueren** Version (etwa nach dem Zurückdrehen des Image-Tags): alle Routen außer `/api/health`, geschrieben wird nichts (`errors.storage.dataTooNew`). `/api/health` selbst antwortet `503`, wenn `status` = `error`. |
| `500` | Unerwarteter Fehler. Seit v2.6.0 mit `error_id`; dieselbe ID steht mit allen Einzelheiten im Server-Log. |

Schreibende Anfragen laufen seit v2.5.3 nacheinander (Sperre auf
`data/.write.lock`): Ein Home-Assistant-Push während einer Eingabe verliert
keine Änderung mehr.

### Fehlercodes *(v2.6.0)*

Jede Fehlerantwort trägt `code` — den Katalogschlüssel der Meldung
(`errors.reading.dateInvalid`, `errors.backup.invalid` …) oder, für
allgemeine HTTP-Fehler, `errors.http.*` (`badRequest`, `unauthorized`,
`forbidden`, `notFound`, `methodNotAllowed`, `unavailable`, `internal`).
`error` ist in die Sprache der Anfrage übersetzt (`Accept-Language`) und darf
sich zwischen Versionen ändern — **Skripte werten `code` aus, nie den
Meldungstext.**

`detail` mit Datei, Zeile und Ausnahmetyp gibt es nur noch mit
`ET_DEBUG=1` (bis v2.5.3 bei jedem Fehler, samt absoluter Pfade). Fachliche
Einzelheiten kommen weiterhin immer, etwa die Fundstellen eines fehlerhaften
Backups in `detail.problems`.

### Anmeldung *(v2.6.0, opt-in)*

Ohne Anmeldung (Standard) bleibt die API offen wie bisher. Ist sie
eingeschaltet (Einstellungen → „Anmeldung & Zugriff", oder `ET_AUTH`), gilt
für jede Route **eines** davon:

| Weg | Für | Übergabe |
|---|---|---|
| Sitzung | den Browser | Cookie `et_session` (HttpOnly, SameSite=Strict, 30 Tage) nach `POST /api/session` |
| API-Schlüssel | Skripte, andere Programme | `Authorization: Bearer etk_…`; Bereich `read` (nur `GET`) oder `admin` |
| Proxy | Betrieb hinter Authelia, Authentik o. Ä. | `ET_AUTH=proxy`; Benutzer aus `Remote-User`/`X-Forwarded-User`, nur von Adressen in `ET_TRUSTED_PROXIES` |

Ohne Anmeldung erreichbar bleiben `POST /api/ingest` (eigener Token, mit
eingeschalteter Anmeldung **Pflicht**), `GET|HEAD /api/health` (dann nur
`{status, version}`), `GET|POST|DELETE /api/session` und `OPTIONS`. Details:
[Sicherheit](08-security.md).

### Stabilitätszusage *(v2.6.0)*

Wer auf der API aufbaut — Home Assistant, Skripte, eigene Auswertungen —,
braucht eine Zusage, was sich ändern darf. Drei Klassen:

| Klasse | Umfang | Zusage |
|---|---|---|
| **A — Schnittstellen für Fremdsysteme** | `POST /api/ingest`, `GET /api/health`, Backup-Format 3.0 (`/api/backup/export`, `/api/backup/import`), CSV-Exporte und -Importe, Stammdaten (`meters`, `readings`, `contracts`, `deliveries`, `reminders`, `settings`, `temperatures`), Fehlerhülle mit `code` | Nur additive Änderungen. Umbenennen oder Entfernen erst mit einer **Major-Version**, angekündigt mindestens eine Minor-Version vorher im CHANGELOG unter „Deprecated". Alte Feldnamen bleiben als Alias gültig. |
| **B — Auswertungen** | Verbrauch, Saldo, Prognose, Tarifvergleich/-wechsel, Rechnungsprüfung, Effizienz, Empfehlungen, PV/Saldo, `readings-overview` | Dokumentierte Felder bleiben mit Namen und Bedeutung erhalten; neue kommen hinzu. **Werte** können sich ändern, wenn eine Berechnung korrigiert wird — das steht im CHANGELOG. |
| **C — Oberfläche** | `session`, `auth/token`, `auth/keys`, `backup/snapshots`, `diagnostics`, `demo`, `migration/v09` | Für die eigene Oberfläche gebaut; Änderungen möglich, stehen aber im CHANGELOG. |

Anlass: v2.0.0 hat `verdict` von „Nachzahlung/Erstattung" still auf Schlüssel
(`surcharge`/`refund`/`balanced`) umgestellt — ohne Ankündigung. Das soll
nicht wieder passieren.

---

## 1. Vollständige Routen-Übersicht

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/health` | Health-Check: `status` ok/degraded/error, Prüfungen, letzter Ingest (HTTP 503 bei `error`); auch `HEAD` |
| GET | `/api/session` | Anmeldemodus, angemeldet?, per Umgebung festgelegt? *(v2.6.0)* |
| POST | `/api/session` | Anmelden `{password}` → Sitzungs-Cookie *(v2.6.0)* |
| DELETE | `/api/session` | Abmelden *(v2.6.0)* |
| POST | `/api/session/password` | Passwort setzen/ändern `{password, current?}` — schaltet die Anmeldung ein *(v2.6.0)* |
| DELETE | `/api/session/password` | Anmeldung ausschalten `{current}` *(v2.6.0)* |
| GET | `/api/auth/keys` | API-Schlüssel (ohne Klartext) *(v2.6.0)* |
| POST | `/api/auth/keys` | Schlüssel erzeugen `{name, scope: read\|admin}`; Klartext einmalig *(v2.6.0)* |
| DELETE | `/api/auth/keys/{id}` | Schlüssel widerrufen *(v2.6.0)* |
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
| GET | `/api/utility/{u}/meters/{id}/contract-status` | Saldo je Vertrag; seit v2.5.1 mit `special_payments[]` (Einzelposten, nur Gas/Strom/Fernwärme) |
| GET | `/api/utility/{u}/meters/{id}/forecast` | 12-Monats-Prognose |
| GET | `/api/utility/{u}/meters/{id}/tariff-comparison` | Tarifvergleich echt vs. Schatten (Rückblick) |
| GET | `/api/utility/{u}/meters/{id}/tariff-switch` | Wechselentscheidung ab Wechseltermin; optional `?switch_date=YYYY-MM-DD` |
| GET | `/api/utility/{u}/meters/{id}/bill-check` | Rechnungsprüfung: Abschnitte je Ablesung und Brennwertwechsel, `?from=&to=` (F1012, **nur Gas**, sonst 400) |
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
| POST | `/api/backup/import` | Backup zurückspielen; `?dry_run=1` prüft nur, `?allow_without_snapshot=1` s. 409 |
| POST | `/api/backup/snapshot` | Snapshot ablegen |
| GET | `/api/backup/snapshots` | Snapshots: Name, Größe, Zeitpunkt, Anlass *(v2.6.0)* |
| GET | `/api/backup/snapshots/{name}` | Snapshot herunterladen (Datei) *(v2.6.0)* |
| POST | `/api/backup/snapshots/{name}/restore` | Snapshot einspielen (vorher Sicherung des jetzigen Stands) *(v2.6.0)* |
| DELETE | `/api/backup/snapshots/{name}` | Snapshot löschen *(v2.6.0)* |
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
        "unit": "m³",              // Einheit des ZÄHLERSTANDS (seit v2.4.2)
        "consumption_unit": "kWh", // Einheit des VERBRAUCHS
        "color": "#f59e0b",
        "meter_id": "m_gas_main",
        "meter_name": "Hauptzähler Gas",
        "meter_icon": "🔥",
        "meter_notes": "Keller",
        "active_device_id": "d_gas_1",
        "last_reading": {
          "date": "2026-04-15",
          "counter": 12345.67,
          "is_estimated": false,
          "id": "20260415-3f2a9c1b",   // seit v2.6.0: „schon ein Stand heute → ersetzen"
          "device_id": "d_gas_1"       // seit v2.6.0: anderes Gerät → kein Rückgang
        },
        "expected_next_min": 12345.67,
        "typical_per_day": 4.2,        // seit v2.6.0: Median der letzten ≤ 10 Intervalle, null bei < 2
        "suspect_count": 0             // seit v2.6.0: unbestätigte Verdachtsfälle (Home Assistant)
      }
    ]
  }
}
```

**Seit v2.6.0** fließen verdächtige Stände (`is_suspect`) nicht in
`last_reading` ein — ein Home-Assistant-Push mit 0 wäre sonst die Basis der
nächsten Erfassung. `typical_per_day` trägt die Rückfrage „Das wären 400 kWh
am Tag, üblich sind 8".

**`unit` gegen `consumption_unit` (seit v2.4.2, GitHub #21).** Ein Gaszähler
zählt Kubikmeter; kWh entsteht erst über den Umrechnungsfaktor. `unit` ist die
Einheit von `counter` und `expected_next_min`, `consumption_unit` die des
daraus berechneten Verbrauchs. Bis v2.4.1 fehlte `unit` in der Antwort, und
die Erfassungsmaske beschriftete den Gas-Zählerstand mit „kWh" — gespeichert
und gerechnet wurde immer in m³. Unter den kumulativen Verbrauchsarten ist Gas
die **einzige**, bei der die beiden Einheiten auseinanderfallen — Strom,
Fernwärme und PV zählen in kWh, Wasser in m³, jeweils identisch mit dem
Verbrauch. Deshalb fiel der Fehler nur bei Gas auf.

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

**Seit v2.4.0 (F1011)** trägt jeder Monat zusätzlich `pre_baseline`
(`true` = liegt vor der Analyse-Zäsur des Zählers). Solche Monate bleiben
in der Antwort, gehen aber in **keine** Auswertung ein: `expected_hgt` und
`delta_pct` sind für sie `null`, die Regressionen lassen sie aus.
`weather_adjusted` wird für sie weiter berechnet — der Wert ist
gebäudeunabhängig und trägt den Vorher/Nachher-Vergleich.

Dazu zwei neue Felder auf oberster Ebene:

```json
"baseline": {
  "active_from": "2021-09-01",     // null = keine Zäsur wirksam
  "active_label": "Dachdämmung",
  "first_month": "2021-10",        // erster voller Monat danach
  "events": [ { "date": "2021-09-01", "label": "Dachdämmung" } ],
  "months_total": 144, "months_after": 58, "points_after": 41,
  "limits": [                       // was gerade nicht gerechnet werden kann
    { "key": "weather_adjustment", "need": 12, "have": 144, "ok": true },
    { "key": "regression",         "need": 8,  "have": 41,  "ok": true },
    { "key": "anomalies",          "need": 5,  "have": 58,  "ok": true }
  ]
},
"baseline_comparison": {            // null, wenn eine Epoche zu dünn ist
  "before": { "slope": 0.42, "base": 11.8, "r2": 0.97, "points": 63 },
  "after":  { "slope": 0.28, "base": 12.1, "r2": 0.98, "points": 41 },
  "delta_pct": -33.3, "unit": "kWh"
}
```

`slope` ist der Verbrauch **je Gradtag** und damit bereits
witterungsbereinigt; `delta_pct` ist die Wirkung der Maßnahme.
`limits` wird auch **ohne** Zäsur befüllt — eine zu kurze Historie wird
damit erklärt, statt eine Auswertung wortlos ausfallen zu lassen.

**Seit v2.6.0** zusätzlich `warnings` — Stände, die nicht oder nur mit
Vorbehalt in die Rechnung eingehen:

```json
"warnings": [
  { "type": "suspect",  "reading_id": "20260920-5c425cf2", "date": "2026-09-20", "counter": 0 },
  { "type": "outlier",  "reading_id": "20260220-dabada46", "date": "2026-02-20", "counter": 13300, "kind": "spike" },
  { "type": "decrease", "reading_id": "…", "date": "…", "counter": 5.0,
    "previous": { "date": "…", "counter": 18432.5 } }
]
```

| `type` | Bedeutung | in der Rechnung? |
|---|---|---|
| `suspect` | Home Assistant hat einen kleineren Stand als den vorigen geliefert; wartet auf Bestätigung (`PATCH …/readings/{id}` mit `is_suspect: false`) | nein |
| `outlier` | eingeklemmter Ausreißer desselben Geräts: `kind` = `spike` (nach oben) oder `dip` (nach unten) | nein |
| `decrease` | Stand fällt, ohne dass sich ein Ausreißer bestimmen lässt — Zählertausch oder Überlauf nicht erfasst? | das negative Intervall nicht |

Bis v2.5.3 verwarf die Rechnung nur das negative Intervall und zählte das
folgende ab dem falschen Stand voll: Ein einziger Wert 0 machte aus 190 kWh
im Monat 50.270 kWh. Ein **Überlauf** (99.998 → 12) wird richtig gerechnet,
wenn am Gerät `digits` (Stellen des Zählwerks) gepflegt ist.

### Ablesungen: `is_suspect`, `source` *(v2.6.0, additiv)*

Zwei optionale Felder an einer Ablesung — nur vorhanden, wenn gesetzt:

- `source`: `ingest` (Home Assistant) oder `csv` (CSV-Import). Grundlage von
  `last_ingest` in `/api/health`.
- `is_suspect: true`: Der Ingest hat einen fallenden Stand auf demselben Gerät
  erhalten. Die Ablesung wird trotzdem gespeichert (201), zählt aber erst nach
  Bestätigung. `PATCH` mit `is_suspect: false` bestätigt, ein korrigierter
  `counter` klärt den Verdacht ebenfalls.

### Zähler: `digits` *(v2.6.0, additiv)*

Stellen des Zählwerks vor dem Komma (3–12) je Gerät. Beim Anlegen als
Einzelfeld `digits` oder im Gerät; `PATCH …/meters/{id}` mit `digits` setzt es
am eingebauten Gerät (`null`/leer entfernt es); `replace-device` übernimmt es
auf das neue Gerät, sofern nicht anders angegeben. Ungültig → 400
`errors.meter.digitsInvalid`.

Seit v2.6.0 werden auch die bis v2.5.3 in `docs/API.md` beschriebenen
Körper angenommen: beim Anlegen ein Objekt `device {serial, installed_on,
initial_counter}`, beim Zählertausch `removed_on`, `final_counter` und
`new_device {serial, installed_on, initial_counter}`. Die aktuellen Namen
haben Vorrang.

### `GET|PATCH /api/settings` — `gas_conversion_factors` *(F1012, v2.5.0)*

Der Skalar `gas_conversion_factor` ist seit Schema 1.5.0 eine **datierte
Liste**; die Migration wandelt den Altwert in den undatierten Eintrag um:

```json
"gas_conversion_factors": [
  { "from": null,         "zustandszahl": null, "brennwert": null,  "kwh_per_m3": 11.5 },
  { "from": "2024-01-01", "zustandszahl": 0.96, "brennwert": 11.4,  "kwh_per_m3": 10.944 },
  { "from": "2025-01-01", "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184 }
]
```

- `PATCH` nimmt die ganze Liste entgegen (kein Einzel-Edit); Dezimalkomma
  wird akzeptiert. Sind `zustandszahl` **und** `brennwert` gesetzt, wird
  `kwh_per_m3` daraus berechnet (5 Nachkommastellen) und überschreibt einen
  mitgeschickten Wert. Nur einer der beiden → 400.
- Plausibilität (400 mit `errors.settings.*`): Zustandszahl 0,8–1,1,
  Brennwert 8–13, Faktor 5–15; höchstens ein undatierter Eintrag; keine
  doppelten Daten. Die Antwort ist nach `from` sortiert, undatiert zuerst.
- Leere Liste → Default (undatiert 11,5).
- Wirkung: `kwh` in allen Verbrauchsantworten rechnet **tagesgenau** mit
  dem am jeweiligen Tag gültigen Faktor; ein Stichtag mitten im
  Ableseintervall teilt das Intervall.

### `GET /api/utility/gas/meters/{id}/bill-check?from=YYYY-MM-DD&to=YYYY-MM-DD` *(F1012, v2.5.0)*

Rechnet die Versorgerrechnung nach: ein Abschnitt je Grenze im Zeitraum —
Anfang, Ende, jede Ablesung, jeder Brennwertwechsel. `to` ist exklusiv,
`to_inclusive` das letzte Tagesdatum des Abschnitts. Nur für Gas (sonst
400 `errors.billCheck.gasOnly`); `from < to` in ISO-Form, sonst 400.

```json
{
  "from": "2025-01-01", "to": "2026-01-01",
  "rows": [
    { "from": "2025-01-01", "to": "2025-03-10", "to_inclusive": "2025-03-09",
      "days": 68, "reason": "start",
      "m3": 412.3, "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184,
      "kwh": 4611.2 },
    { "from": "2025-03-10", "to": "2025-10-01", "to_inclusive": "2025-09-30",
      "days": 205, "reason": "reading", "m3": 301.0, "…": "…" },
    { "from": "2025-10-01", "to": "2025-11-04", "to_inclusive": "2025-11-03",
      "days": 34, "reason": "factor", "…": "…" },
    { "from": "2025-11-04", "to": "2026-01-01", "to_inclusive": "2025-12-31",
      "days": 58, "reason": "reading", "m3": null, "kwh": null, "…": "…" }
  ],
  "totals": { "days": 365, "m3": 812.4, "kwh": 9034.7, "gaps": 1 }
}
```

`reason` ∈ `start`, `end`, `reading`, `reading_estimated`, `factor` —
Kombinationen mit `+` (`reading+factor`). **Seit v2.5.2** trägt jede Zeile
`counter_from`/`counter_to` (Zählerstand am Anfang/Ende des Abschnitts)
mit `counter_from_kind`/`counter_to_kind` ∈ `reading` (abgelesen),
`reading_estimated` (als geschätzt erfasst), `interpolated` (Ersatzwert:
kein Stand an diesem Tag, tagesgenau interpoliert) oder `null` (kein
umschließendes Intervall). Über einen Zählertausch hinweg ist der
Ersatzwert `null` bei `kind = interpolated`. `m3`/`kwh` sind `null`, wenn
kein Ableseintervall den Abschnitt umschließt (vor der ersten, nach der
letzten Ablesung); `totals.gaps` zählt diese Abschnitte, sie fehlen in
den Summen. `m3` je Abschnitt ist die lineare Interpolation des
umschließenden Intervalls — der Versorger schätzt an denselben Stellen.

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
`{ status: "created"|"updated", utility, meter_id, date, counter, reading_id,
suspect }` — `suspect` seit v2.6.0: Ist der Wert kleiner als der vorige Stand
desselben Geräts, wird er gespeichert, aber als Verdacht markiert
(`suspect: true`, dazu `previous: {date, counter}`) und zählt erst nach
Bestätigung in der Oberfläche. Ein Überlauf mit gepflegter Stellenzahl
(`digits`) ist kein Verdacht. Ein Push lehnt deshalb nichts ab, was vorher
angenommen wurde.
Fehler: `401` (Token nötig/falsch; seit v2.6.0 mit eingeschalteter Anmeldung
auch ohne gesetzten Token: `errors.ingest.tokenRequiredWithLogin`), `400`
(unbekannte Utility/Zähler, kein Zahlenwert, kein gültiges Kalenderdatum,
Delivery-Utility Heizöl/Pellets).

Liegt das Datum vor dem Einbau des **ersten** Geräts, wird dieses seit v2.5.3
zurückdatiert statt die Ablesung abzulehnen (typisch beim Nachtragen älterer
Stände nach einer Neuinstallation). Ein Datum in einer Lücke zwischen zwei
Geräten bleibt ein `400`.

> **Vorlage für Home Assistant:** `| float` **ohne** Ersatzwert und vor dem
> Push `has_value(…)` prüfen — siehe [`docs/HOME-ASSISTANT.md`](../HOME-ASSISTANT.md).
> `float(0)` aus Vorlagen bis v2.5.2 buchte bei nicht verfügbarem Sensor einen
> Zählerstand 0.

### `GET|POST|DELETE /api/auth/token` *(F1009)*

Verwaltung des **opt-in**-Tokens für den Push-Endpunkt. Er schützt **nur**
`/api/ingest`: Ohne Token nimmt der Ingest Werte ohne Kopfzeile an; sobald ein
Token existiert, verlangt er `Authorization: Bearer <token>`. Den Rest der API
schützt die Anmeldung (s. o.), nicht dieser Token. Der Token wird nur als
SHA-256-Hash in `data/auth.json` gespeichert und beim Erzeugen **einmalig** im
Klartext zurückgegeben. Seit v2.6.0 nennt `GET` zusätzlich `last_used_at`
(auf die Stunde genau) — hilfreich bei der Frage „kommt überhaupt etwas an?".

### `/api/session`, `/api/session/password`, `/api/auth/keys` *(v2.6.0)*

```jsonc
// GET /api/session
{ "mode": "off", "authenticated": true, "mode_fixed": false,
  "password_fixed": false, "has_password": false }

// POST /api/session/password   (Einschalten: nur password; Ändern: + current)
{ "password": "mindestens-8-Zeichen", "current": "bisheriges" }
// → { "mode": "password" } + Sitzungs-Cookie; ein neues Passwort beendet alle anderen Sitzungen

// POST /api/auth/keys
{ "name": "Backup-Skript", "scope": "read" }
// → 201 { "id": "k_…", "key": "etk_…", "hint": "…" }   Klartext nur hier
```

- `mode`: `off` · `password` · `proxy`. `mode_fixed`/`password_fixed`: über
  `ET_AUTH` bzw. `ET_ADMIN_PASSWORD_HASH` festgelegt — dann antworten die
  ändernden Routen `409`.
- Fehlversuche: nach fünf innerhalb von 15 Minuten ist die Anmeldung
  5 Minuten gesperrt (`429`). Das gilt auch für `current` beim Ändern und
  Ausschalten.
- `GET /api/auth/keys` liefert `id`, `name`, `scope`, `created_at`,
  `last_used_at` — nie den Schlüssel oder seinen Hash.

### Snapshots und Import *(v2.6.0)*

`GET /api/backup/snapshots` listet `data/backups/` (neueste zuerst):

```json
[ { "name": "pre-restore-2026-09-25_000438.json", "size": 215512,
    "created_at": "2026-09-25T00:04:38+02:00", "reason": "restore" } ]
```

`reason` ∈ `manual` (eigener Snapshot), `restore` (vor Import/Einspielen),
`migration`, `demo`, `v09`. **Aufbewahrung:** von den eigenen die letzten
zehn; automatische 30 Tage, je Anlass mindestens die drei neuesten.

`POST /api/backup/import` prüft seit v2.6.0 **zuerst alles**: Jeder Topf muss
eine Liste von Objekten mit Pflichtfeldern und gültigen Daten sein. Ein
fehlerhaftes Backup ändert nichts und antwortet `400` mit
`detail.problems` (höchstens 50):

```json
{ "success": false, "code": "errors.backup.invalid",
  "error": "Das Backup ist unvollständig oder beschädigt (3 Problem(e)). Es wurde nichts eingespielt.",
  "detail": { "problems": [ { "pot": "gas/meters", "index": 0, "problem": "missing:devices" } ] } }
```

`problem` ∈ `not_an_object`, `not_a_list`, `unknown_utility`,
`missing:<feld>`, `date:<feld>`, `counter`, `devices`, `entry:<datum>`. Mit
`?dry_run=1` endet der Import nach der Prüfung und liefert den Bericht
(Anzahlen je Topf, `untouched` = nicht im Backup enthaltene und daher
unveränderte Töpfe). Weitere Änderungen: Die Hülle von
`GET /api/backup/export` (`{success, data}`) wird ausgepackt; scheitert eine
Datei mitten im Schreiben, werden die schon geschriebenen zurückgesetzt;
`recommendations_dismissed` gehört seit v2.6.0 zum Backup.

### `GET|HEAD /api/health` *(N1003; v2.6.0 erweitert)*

```json
{ "status": "ok", "version": "2.6.0", "schema_version": "1.5.0",
  "data_dir_writable": true, "migrations_pending": 0,
  "data_initialized_at": "2026-09-24T23:58:03+02:00",
  "php_version": "8.4.12", "timezone": "Europe/Berlin",
  "last_ingest": { "m_strom_main": "2026-09-20" },
  "checks": {
    "data_dir_writable": { "ok": true, "level": "ok" },
    "schema":            { "ok": true, "level": "ok" },
    "files":             { "ok": true, "level": "ok", "corrupt": [] },
    "disk":              { "ok": true, "level": "ok", "free_mb": 736175 },
    "temp_files":        { "ok": true, "level": "ok", "removed": 0 } } }
```

`status` ∈ `ok` · `degraded` (ausstehende Migration, unter 50 MB frei) ·
`error` (nicht schreibbar, beschädigte Datei, Daten neuer als die App, unter
5 MB frei) — bei `error` mit HTTP `503`, damit Docker-HEALTHCHECK und Monitore
die Störung erkennen. `migrations_pending` zählt seit v2.6.0 die
ausstehenden Migrationsschritte (bis v2.5.3 nur `0` oder `1`; `0` heißt
weiterhin „nichts zu tun"). Ist die Anmeldung eingeschaltet und der Aufrufer
nicht angemeldet, gibt es nur `{status, version}`.

### Parametergrenzen *(v2.6.0)*

Bisher still übernommen, jetzt `400` mit `code` und — bei der Prognose —
`detail {param, value, range}`:

| Endpunkt | Parameter | erlaubt |
|---|---|---|
| `…/forecast` | `forecast_months` | 1–60 |
| | `temp_offset` | −30 bis +30 °C |
| | `price_factor` | 0–10 |
| | `model` | `linear`, `polynomial`, `robust`, `segmented`, `sigmoid` |
| `/api/reports/yearly.pdf` | `year` | 2000–2100 |
| `POST /api/temperatures` | `avg`, `min`, `max` | Zahlen, Pflicht |
| `…/sync-open-meteo` | `start`, `end` | ISO-Datum, `start` ≤ `end` |

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
