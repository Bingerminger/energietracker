# Datenmodell

**Deutsch** · [English](../en/referenz/datenmodell.md)

[← API-Referenz](api.md) · [Kompendium-Index](../README.md)

Alle Daten liegen als flache JSON-Dateien unter `data/`. Keine Datenbank.
Schreibvorgänge sind durch `LOCK_EX` serialisiert. Schema-Stand: **1.7.0**
(in `data/meta.json` und in jedem Backup).

> **Schema-Historie (Kurzfassung):** 1.0.0 utility-orientiertes Layout ·
> 1.0.3 Wasser-3-Komponenten-Verträge · 1.1.0 Fernwärme/Heizöl/Pellets +
> `reminders.json` · **1.2.0** Meter-Topologie (`parent_meter_id`,
> `meter_group_id`, `meter_groups.json` je Utility — F1006) · **1.3.0**
> Zähler-Alias `external_id` für die Home-Assistant-Anbindung (F1009) ·
> **1.4.0** Analyse-Zäsuren `baseline_events` am Zähler (F1011) ·
> **1.5.0** datierte Gas-Umrechnungsfaktoren `gas_conversion_factors` in
> `settings.json` statt des Skalars `gas_conversion_factor` (F1012) ·
> **1.6.0** bisherige CO₂- und Wasser-Defaults bei Bestandsinstallationen
> festgeschrieben, bevor die korrigierten gelten (v2.10.0, Lektion 36) ·
> **1.7.0** Töpfe `attachments.json` (Belege), `tenancies.json` und
> `tenancy_statements.json` (Mietverhältnis), `market_prices.json`
> (Börsenstrompreise), je Verbrauchsart `periods.json`
> (Verbrauch je Zeitraum) und `bills.json` (Versorgerrechnungen) und die neue
> Verbrauchsart `waerme/` leer angelegt, ebenso fehlende Grundtöpfe (v3.1.0) ·
> v3.2.0 **ohne** Schema-Schritt: `ev_sessions.json` (Ladevorgänge aus evcc)
> entsteht beim ersten Import, Personen stehen in `auth.json`.

---

## 1. Verzeichnis- und Dateilayout

```text
data/
├── meta.json                 # { schema_version, migrated_at, log[] }
├── settings.json             # Einstellungen (Defaults: SettingsService::DEFAULTS)
├── auth.json                 # Anmeldung, Personen (v3.2.0), HA-Token, API-Schlüssel — Passwörter und Schlüssel nur als Hash; nicht im Backup
├── temperatures.json         # { "YYYY-MM-DD": { avg, min, max, source }, … } — source seit v2.8.0
├── climate_normal.json       # Klimanormal am Standort (v2.8.0) — nur Kennzahlen, keine Rohdaten
├── weather_sync.json         # Zustand des letzten Open-Meteo-Abgleichs (v2.8.0)
├── reminders.json            # Termine/Wartung
├── recommendations_dismissed.json
├── attachments.json          # Index der Belege (v3.1.0, Schema 1.7.0)
├── attachments/              # Belegdateien <id>.<jpg|png|webp|pdf> (v3.1.0)
├── tenancies.json            # Mietverhältnisse (v3.1.0, Schema 1.7.0)
├── tenancy_statements.json   # Nebenkostenabrechnungen (v3.1.0, Schema 1.7.0)
├── market_prices.json        # Börsenstrompreise als Monatsmittel (v3.1.0, Schema 1.7.0)
├── ev_sessions.json          # Ladevorgänge aus evcc (v3.2.0) — entsteht beim ersten Import
├── instance.json             # Kennung der Installation (v3.1.0) — nicht im Backup
├── gas/        { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── strom/      { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── wasser/     { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── fernwaerme/ { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── heizoel/    { meters.json, deliveries.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── pellets/    { meters.json, deliveries.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── pv_einspeisung/ { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── pv_erzeugung/   { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }
├── waerme/     { meters.json, readings.json, contracts.json, meter_groups.json, periods.json, bills.json }   # Heizwärme (v3.1.0)
├── logs/       # JSON-Lines-Log (N1010)
├── .write.lock # Schreibsperre (v2.5.3)
└── backups/    # Snapshots: backup_… (eigene), pre-restore-/pre-migration-/pre-demo-/pre-v09-… (automatisch)
```

**Snapshots (v2.6.0):** Name `<präfix>YYYY-MM-DD_HHMMSS[-n].json`; der Präfix
bestimmt den Anlass (`reason` in `GET /api/backup/snapshots`). Aufbewahrung:
von den eigenen die letzten zehn, automatische 30 Tage, je Anlass mindestens
die drei neuesten. Ein Snapshot entsteht gestreamt über eine Temp-Datei und
wird erst am Ende umbenannt. Seit v3.1.0 enthält er die Belege (base64) —
`backups/` wächst deshalb mit jedem Foto, je Snapshot einmal.

Kumulative Arten (Gas, Strom, Wasser, Fernwärme, PV, seit v3.1.0 Heizwärme)
haben `readings.json`; lieferbasierte Arten (Heizöl, Pellets) haben stattdessen
`deliveries.json`. `contracts.json` existiert bei allen, ist für
Heizöl/Pellets aber typischerweise leer — dort ist die **Tankrechnung
selbst** die Kostenbasis (siehe [Heizöl](../verstehen/05-heizoel.md)). Bei
Heizwärme und PV-Erzeugung bleibt er leer: Beide haben keine Verträge.
`meter_groups.json` (seit 1.2.0) hält die Gruppen-Stammdaten je Utility;
die Gruppen-*Mitgliedschaft* steht dagegen am Zähler (`meter_group_id`).
`periods.json` (v3.1.0) hält die Zeiträume der Zähler mit „Verbrauch je
Zeitraum" ([Zeitraum](#zeitraum-v310)); angelegt wird er bei jeder Art, genutzt
nur bei den Arten mit Zählerständen. `bills.json` (v3.1.0) hält die
Versorgerrechnungen ([Versorgerrechnung](#versorgerrechnung-v310)); ebenfalls
bei jeder Art angelegt, genutzt bei Gas, Strom, Wasser und Fernwärme.

**Heizwärme (`waerme/`, v3.1.0).** Die neunte Verbrauchsart: Wärme, die in der
Wohnung ankommt, in kWh. Ihr Ordner entsteht mit Schema 1.7.0 leer, auch in
Bestandsinstallationen; Standardzähler gibt es keine, und aktiv ist sie erst,
wenn sie unter Einstellungen → Verbrauchsarten & Abrechnung gewählt ist
(`active_utilities`). Mehr in [Heizwärme](../verstehen/15-waerme.md).

**`instance.json` (v3.1.0).** `{instance_id, created_at}` — die Kennung der
Installation, `et_` und 16 Hexziffern, beim ersten Bedarf zufällig angelegt.
Der Kalender bildet daraus die UIDs seiner Ereignisse, `GET /api/summary`
liefert sie als `instance_id`. Die Datei gehört bewusst **nicht** ins Backup:
Nach einem Restore auf eine zweite Installation hätten sonst beide dieselbe
Kennung.

**Temperaturen und Wetter (v2.8.0, additiv — kein Schema-Bump):**

- `temperatures.json`: Jeder Tag trägt `source` — `archive` (Messwert aus
  dem Open-Meteo-Archiv), `forecast` (Vorhersage, wird durch den Archivwert
  ersetzt), `csv` oder `manual` (eigene Werte, werden nie überschrieben).
  Einträge ohne `source` stammen aus älteren Versionen; älter als die
  Archiv-Verzögerung (sechs Tage) gelten sie als gemessen.
- `climate_normal.json`: `version`, `latitude`/`longitude` (gerundet),
  `period` `{from, to}` (die letzten 30 vollen Kalenderjahre), `fetched_at`,
  `days`, `doy_avg` (366 mittlere Tagesmittel, Tag 60 = 29. Februar) und
  `hdd` — je Heizgrenze von `"10.0"` bis `"22.0"` in halben Grad
  `{mean[12], sd[12], year_sd, years}`. Wird neu geholt, wenn der Standort
  sich um mehr als 0,05° verschiebt oder die Periode nicht mehr an das
  Vorjahr heranreicht. Kein Backup-Bestandteil: Die Datei lässt sich
  jederzeit neu erzeugen.
- `weather_sync.json`: `last_sync_at`, `last_sync_date` (für „einmal am
  Tag"), `measured_until`, `forecast_until`, `archive_error`,
  `forecast_error`, `climate_normal` (Status des letzten Abgleichs).

> **`auth.json`** (F1009, erweitert in v2.6.0) enthält nur **Hashes**, nie
> Klartext, und wird vom Backup **ausgenommen**. Fehlt die Datei, ist die
> Anmeldung aus und der Ingest ohne Token erreichbar. Eine **unlesbare** Datei
> gilt nicht als leer: Der Zugriff scheitert (503), statt still zu öffnen.
>
> ```jsonc
> {
>   "token_hash": "…sha256…", "created_at": "…", "token_last_used_at": "…",  // HA-Ingest (F1009)
>   "mode": "password",                 // off | password | proxy (ET_AUTH hat Vorrang)
>   "password_hash": "$2y$10$…",        // password_hash(); ET_ADMIN_PASSWORD_HASH hat Vorrang
>   "session_secret": "…",              // HMAC-Schlüssel der Sitzungs-Cookies; neu bei jedem neuen Passwort der Installation
>   "login_failures": { "count": 1, "first_at": 1758700000, "locked_until": 0 },
>   "api_keys": [ { "id": "k_…", "name": "Backup-Skript", "scope": "read",
>                   "hash": "…sha256…", "created_at": "…", "last_used_at": null } ],
>   "users": [ … ]                      // Personen im Haushalt (v3.2.0)
> }
> ```
>
> Die Personen: [Personen in `auth.json`](#personen-in-authjson-v320).
> Details: [Sicherheit](../betrieb/sicherheit.md), [API-Referenz → Anmeldung](api.md).

---

## 2. Kernschemata

### Meter (Zähler bzw. Tank/Lager)

```json
{
  "id": "m_gas_main",
  "name": "Hauptzähler Gas",
  "icon": "🔥",
  "created_at": "2023-01-01",
  "active": true,
  "notes": "Keller, links",

  // Meter-Topologie (F1006, seit Schema 1.2.0) — Default null:
  "parent_meter_id": null,   // gesetzt = Subzähler dieses Elternzählers
  "meter_group_id": null,    // gesetzt = Mitglied dieser Zählergruppe

  // HA-Anbindung (F1009, seit Schema 1.3.0) — Default null:
  "external_id": "gaszaehler_haus",  // Alias für POST /api/ingest

  // Analyse-Zäsuren (F1011, seit Schema 1.4.0) — Default []:
  "baseline_events": [
    { "date": "2021-09-01", "label": "Dachdämmung" }
  ],

  "devices": [ Device, … ],

  // nur bei lieferbasierten Arten (Heizöl/Pellets):
  "capacity": 3000.0,
  "capacity_unit": "L",
  "initial_stock": 2400.0,
  "initial_stock_price_ct": 98.5,       // v2.10.0, optional: Preis des Anfangsbestands
  "tank_levels": [                       // v2.10.0, optional: Peilstände (Stützstellen)
    { "date": "2025-09-17", "level": 1650, "note": "Peilstab" }
  ],

  // nur bei Strom, optional (v2.10.0):
  "heat_source": true,                   // Wärmepumpe: zählt in der Effizienzkennzahl

  // v3.1.0, optional — fehlt = Standard:
  "role": "heat_pump",                   // Rolle des Zählers, je Art (s. u.)
  "capture": "period",                   // Erfassungsart: fehlt = Zählerstände

  // v3.1.0, optional — für den Lieferantenwechsel (nicht bei Heizöl/Pellets):
  "malo_id": "51234567895",              // Marktlokations-ID (synthetisches Beispiel)
  "melo_id": "DE…",                      // Messlokations-ID, 33 Zeichen

  // v3.1.0, nur bei PV-Erzeugung, optional:
  "plug_in": true,                       // Balkonkraftwerk ohne Einspeisezähler
  "investment_eur": 800,                 // Investition brutto (Amortisation)
  "commissioned_on": "2025-04-12",       // Inbetriebnahme (Amortisation, § 51 EEG)
  "battery_capacity_kwh": 5.0,           // Speicherkapazität, am Zähler mit Rolle battery_charge

  // v3.1.0, nur bei Heizwärme mit Rolle heat_pump_output, optional:
  "heat_pump_meter_ids": ["m_wp_strom"]  // Stromzähler der Wärmepumpe (Rolle heat_pump)
}
```

**PV- und Wärmepumpen-Felder (v3.1.0).** `plug_in` (`true` oder fehlt),
`investment_eur` (0–10.000.000), `commissioned_on` (ISO-Datum) und
`battery_capacity_kwh` (0–10.000) gibt es nur bei `pv_erzeugung`; ungültige
Werte → `errors.meter.valueInvalid`, leer entfernt das Feld. Sie speisen
Amortisation, Speicher-Kennzahlen und die Annahme für ein Balkonkraftwerk in
`GET /api/pv-summary` ([API](api.md#pv-speicher-balkonkraftwerk-amortisation-v310-additiv)).
`heat_pump_meter_ids` (nur `waerme`) nennt Stromzähler mit der Rolle
`heat_pump`, sonst `errors.meter.heatPumpLinkInvalid`; daraus rechnet
`GET /api/heat-pump` die Jahresarbeitszahl
([API](api.md#jahresarbeitszahl-der-wärmepumpe-v310)).

**Markt- und Messlokation (`malo_id`, `melo_id`, v3.1.0).** Die
Marktlokations-ID hat 11 Ziffern, die erste nicht 0, die letzte ist eine
Prüfziffer nach BDEW; die Messlokations-ID hat 33 Zeichen, „DE“ und 31 Ziffern
oder Großbuchstaben. Leerzeichen entfernt die App, ein leerer Wert löscht das
Feld; Falsches lehnt sie ab (`errors.meter.maloInvalid`, `…meloInvalid`). Die
Wechselentscheidung zeigt die MaLo-ID unter „Für den Wechsel bereithalten“.

**Rolle (`role`, v3.1.0).** Was ein Zähler misst, bei den Arten, die Rollen
kennen. Die erste ist der Standard und wird **nicht** gespeichert — ein Zähler
ohne `role` hat sie.

| Art | Rollen (erste = Standard) |
|---|---|
| `strom` | `household`, `heat_pump` (Heizstrom, zählt in der Effizienzkennzahl), `ev_charger` (Wallbox: Ladestrom-Nachweis; nicht im Haushaltsstrom der Einordnung, ebenso wenig `heat_pump`) |
| `wasser` | `cold`, `warm` (Warmwasser: zusätzlich die Wärme dafür als Rechenwert), `garden` |
| `pv_erzeugung` | `generation`, `battery_charge`, `battery_discharge` (Speicher: zählen nicht zur Erzeugung und nicht in Summen, nur in den Speicher-Kennzahlen) |
| `waerme` | `consumption` (Wärme der Wohnung, zählt in der Effizienzkennzahl), `heat_pump_output` (Wärmemenge einer Wärmepumpe, zählt weder in Summen noch in der Effizienzkennzahl — sonst stünde sie neben dem Heizstrom doppelt; Grundlage der Jahresarbeitszahl) |

Beim Strom bleiben `role: heat_pump` und das ältere Feld `heat_source: true`
gleich: Wer das eine setzt, setzt das andere mit, damit ältere Versionen die
Wärmepumpe weiter erkennen. Eine Rolle, die die Art nicht kennt (oder eine
Rolle bei einer Art ohne Rollen), lehnt die API mit 400 ab
(`errors.meter.roleInvalid`). `GET /api/readings-overview` nennt die Rolle
jedes Zählers (`null` bei Arten ohne Rollen).

**Erfassungsart (`capture`, v3.1.0).** `counter` (Zählerstände, Standard, wird
nicht gespeichert) oder `period` (Verbrauch je Zeitraum) — für alle Arten mit
Zählerständen, nicht für Heizöl und Pellets. Ein Zähler mit `period` hat statt
Ablesungen Zeiträume in `periods.json`; Ablesungen und Home-Assistant-Pushes
lehnt er ab (`errors.reading.periodMeter`, `errors.ingest.periodMeter`).
Wechseln lässt sich die Art nur, solange der Zähler keine Daten der bisherigen
Art hat (400 `errors.meter.captureLocked`); ein unbekannter Wert ergibt
`errors.meter.captureInvalid`.

**Tankbuch (v2.10.0).** `tank_levels` und Lieferungen mit `fill_to_full`
sind Stützstellen mit bekanntem Bestand; dazwischen ist der Verbrauch
gerechnet, danach geschätzt ([Heizöl §3](../verstehen/05-heizoel.md)).
Fehlt `initial_stock_price_ct`, kostet der Anfangsbestand den Preis der
ersten Lieferung. Kein Schema-Schritt: Die Felder sind optional und reisen
im Backup mit.

**Meter-Topologie (F1006).** Ein Zähler kann **Subzähler** eines anderen sein
(`parent_meter_id`, Reihenschaltung — sein Verbrauch wird beim Elternzähler
abgezogen) und/oder **Mitglied einer Gruppe** (`meter_group_id`, fasst mehrere
Zähler fürs Dashboard zusammen; seit v3.1.0 auch für einen gemeinsamen
Vertrag). Regeln: max. eine Subzähler-Ebene (keine
Ketten/Zyklen); ein Elternzähler mit Subzählern lässt sich nicht löschen, ohne
die Zuordnung zu lösen. Siehe [Meter-Topologie](../verstehen/13-meter-topologie.md).

**`external_id` (F1009).** Frei vergebbarer, pro Utility eindeutiger Alias
(`[A-Za-z0-9_.-]{1,64}`) für die Home-Assistant-Anbindung. `POST /api/ingest`
akzeptiert ihn anstelle der internen ID. Default `null` = kein Alias.

### Meter-Gruppe (`meter_groups.json`, F1006)

```json
{ "id": "g_strom_ab12cd34", "name": "NT + HT Strom", "created_at": "2026-06-01" }
```

Reine Stammdaten (ID + Name). Welche Zähler dazugehören, steht **nicht** hier,
sondern als `meter_group_id` am jeweiligen Zähler (Single-Source-of-Truth).
Seit v3.1.0 kann eine Gruppe Ziel eines Vertrags sein (`meter_group_id` am
Vertrag, s. u.); die Reihenfolge der Mitglieder ist die der Zählerliste, und das
erste trägt die festen Kosten des Gruppenvertrags.

### Device (Gerät innerhalb eines Zählers — Zählertausch)

```json
{
  "id": "d_gas_001",
  "serial": "G-2018-447",
  "installed_on": "2018-03-01",
  "initial_counter": 0.0,
  "removed_on": "2024-10-01",
  "final_counter": 1562.0,
  "reason": "Eichtausch",
  "digits": 5                  // optional (v2.6.0): Stellen des Zählwerks, 3–12
}
```

Verbrauch über eine Tauschgrenze:
`(altes_final − vorheriges_reading) + (aktuelles_reading − neues_initial)`.

**`digits`** (v2.6.0, additiv, fehlt = unbekannt): Fällt der Stand auf
demselben Gerät um höchstens ein Zehntel des Zählbereichs „zurück", ist das
ein Überlauf: Verbrauch = `neu + 10^digits − alt` (99.998 → 12 bei fünf
Stellen = 14).

### Reading (Zählerstand — kumulative Arten)

```json
{
  "id": "r_gas_0001", "meter_id": "m_gas_main", "device_id": "d_gas_001",
  "date": "2023-02-02", "counter": 148.7, "price_cents": null,
  "note": "", "is_estimated": false, "is_future": false
}
```

`is_future: true` markiert vorgemerkte Termine — sie bleiben sichtbar,
werden aber **nicht** in die Verbrauchsberechnung einbezogen.

Zwei optionale Felder seit v2.6.0 (additiv, nur vorhanden, wenn gesetzt):

- `source`: `ingest` (Home Assistant) oder `csv` (CSV-Import).
- `is_suspect: true`: fallender Stand aus dem Ingest; zählt nicht, bis er
  bestätigt (`PATCH` mit `is_suspect: false`) oder korrigiert ist.

Zwei weitere seit v3.1.0, ebenso optional:

- `client_ref`: Kennung der Erfassung (8–64 Zeichen `[A-Za-z0-9-]`), vom
  Client gewählt. Eine zweite Anlage mit derselben Kennung am selben Zähler
  legt keinen neuen Stand an (Offline-Warteschlange,
  [API](api.md#ablesungen-client_ref-attachment_id-v310-additiv)).
- `attachment_id`: Foto des Zählerstands — ID eines Belegs mit
  `kind: reading_photo` ([Belege](#belege-v310)).

Die Verbrauchsrechnung übergeht außerdem **eingeklemmte Ausreißer** desselben
Geräts und meldet sie als `warnings` (siehe
[Zählerstände → Plausibilität](../verstehen/11-zaehlerstaende.md)).

### Belege *(v3.1.0)*

Dateien zu einem Datensatz — Fotos von Zählerständen, Belege zu Zeiträumen,
PDFs oder Fotos von Nebenkostenabrechnungen und seit v3.1.0 von
Versorgerrechnungen (`ref.type` `reading`, `period`, `tenancy_statement`,
`bill`). Die Datei liegt unter
`data/attachments/<id>.<ext>`, ihr Eintrag im Index `attachments.json` (eine
Liste):

```jsonc
{
  "id": "att_5f0c2a9e81d34b67",        // att_ + 16 Hexziffern
  "kind": "reading_photo",             // reading_photo | bill_pdf | statement_pdf | other
  "mime": "image/jpeg",                // image/jpeg | image/png | image/webp | application/pdf — aus dem Inhalt bestimmt
  "size": 284113,                      // Byte
  "sha256": "9c1e…",
  "created_at": "2026-10-07T08:12:40+02:00",
  "ref": { "type": "reading", "utility": "strom", "id": "20261007-1a2b3c4d" },   // oder null
  "original_name": "zaehler.jpg"       // optional, wenn beim Hochladen genannt
}
```

- **Verweis in beide Richtungen.** `ref` zeigt auf den Datensatz, der
  Datensatz trägt `attachment_id`. Gesetzt und gelöst wird beides zusammen
  über die API ([Belege](api.md#belege-und-texterkennung-v310)).
- **Verwaist** ist ein Beleg mit `ref: null` — hochgeladen und nie
  gespeichert, oder die Ablesung wurde gelöscht bzw. das Foto gelöst; dann
  steht der Zeitpunkt in `unlinked_at`. Er wird nach **24 Stunden** (ab
  `unlinked_at`, sonst ab `created_at`) aufgeräumt, beim nächsten Hochladen
  und beim Rotieren der Snapshots. Dateien in `attachments/` ohne
  Indexeintrag ebenso.
- **Grenzen.** Foto 3 MB, PDF 10 MB, alle zusammen `attachments_max_mb`
  (Standard 500 MB).
- **Backup.** `attachments` ist ein Topf wie jeder andere; die Dateien stehen
  base64-kodiert unter `attachment_files` (`{id: base64}`). Das Backup-Format
  bleibt `3.0`; ältere Versionen übergehen den Schlüssel. Der Import prüft
  jede Datei gegen `sha256` und Inhaltstyp, bevor er schreibt
  ([Snapshots und Import](api.md#snapshots-und-import-v260)).

### Zeitraum *(v3.1.0)*

Verbrauch je Zeitraum — für Werte, die schon als Verbrauch vorliegen, etwa die
monatliche Verbrauchsinfo des Messdienstes. Nur an Zählern mit
`capture: "period"`, je Verbrauchsart in `<art>/periods.json` (eine Liste):

```jsonc
{
  "id": "p_3a9f1c20b7e4",            // p_ + 12 Hexziffern
  "meter_id": "m_waerme_1",
  "from": "2026-01-01",
  "to": "2026-01-31",                // inklusive
  "value": 820,                      // ≥ 0, auf drei Nachkommastellen gerundet
  "value_unit": "consumption",       // consumption | meter — nur bei Gas verschieden
  "is_estimated": false,
  "source": "manual",                // manual | csv | import
  "reference": {                     // optional, Vergleichswerte der Verbrauchsinfo
    "prev_month": 760, "prev_year_month": 900, "average_user": 850
  },
  "note": "",                        // bis 500 Zeichen
  "attachment_id": null,             // optional: Beleg (Foto, PDF)
  "client_ref": "…"                  // optional, wie bei Ablesungen
}
```

- **Einheit.** `consumption` ist die Verbrauchseinheit der Art (kWh, bei
  Wasser m³), `meter` die Zählereinheit. Die beiden unterscheiden sich nur bei
  Gas: `meter` = m³, gerechnet mit den datierten Umrechnungsfaktoren in kWh;
  `consumption` = kWh, die m³ rechnet die App für Rechnungsprüfung und CSV
  zurück. Bei allen anderen Arten wird `value_unit` immer `consumption`.
- **Regeln.** `from ≤ to` (sonst `errors.period.order`); zwei Zeiträume
  desselben Zählers dürfen sich nicht berühren (`errors.period.overlap`).
  `client_ref` wirkt wie bei Ablesungen: dieselbe Kennung am selben Zähler legt
  keinen zweiten Zeitraum an.
- **Rechnung.** Tagesrate = Wert / Tage des Zeitraums, tagesgenau auf die
  Monate verteilt; Lücken bleiben Lücken (Abdeckung wie bei Zählerständen).
  Danach läuft alles wie bei Ständen. Mehr in
  [Heizwärme](../verstehen/15-waerme.md).

### Mietverhältnis und Nebenkostenabrechnung *(v3.1.0)*

Für Mieter, die Heizung und Wasser über die Nebenkosten zahlen
([Anleitung](../anleitungen/mieter.md)). Zwei Listen auf oberster Ebene.

`tenancies.json`:

```jsonc
{
  "id": "t_8c21e4f09a3b",
  "start": "2024-04-01",
  "end": null,                       // optional; leer = läuft
  "label": "Wohnung 2. OG",
  "landlord": "",                    // optional
  "wohnflaeche_m2": 68,              // optional, laut Mietvertrag; leer = Einstellung
  "co2_own_appliances": false,       // v3.1.0 (H4): Gas auch für eigene Geräte → Erstattung × 0,95
  "co2_restriction": "none",         // v3.1.0 (H4): none | one | both (§ 9 CO2KostAufG)
  "billing_anchor": "01-01",         // MM-TT, Beginn des Abrechnungszeitraums (Standard 01-01)
  "prepayments": [                   // datiert, nach from sortiert
    { "from": "2024-04-01", "heating_eur_month": 70, "operating_eur_month": 50 }
  ],
  "prices": [
    { "from": "2025-01-01", "heat_eur_per_kwh": 0.15, "warm_water_eur_per_m3": 9.5,
      "cold_water_eur_per_m3": 4.5, "source": "statement", "statement_id": "s_…" }
  ],
  "fixed_costs": [                   // pauschale Umlagen je Jahr
    { "from": "2025-01-01", "label": "Müll", "eur_per_year": 180 }
  ],
  "meter_ids": { "heat": ["m_waerme_1"], "warm_water": ["m_ww_1"], "cold_water": ["m_kw_1"] },
  "notes": ""
}
```

- `prepayments`, `prices`, `fixed_costs` sind datierte Listen: Es gilt der
  späteste Eintrag mit `from ≤ Tag`; bei den Preisen je Feld, bei den Umlagen
  je Bezeichnung. Preise sind optional (`source`: `statement` aus einer
  Abrechnung übernommen, `estimate` selbst eingetragen).
- `meter_ids`: `heat` nimmt Zähler der Heizwärme, `warm_water` und `cold_water`
  Wasserzähler. Ein unbekannter Zähler ergibt `errors.tenancy.meterNotFound`.

`tenancy_statements.json`:

```jsonc
{
  "id": "s_51d0a7c3e2f6",
  "tenancy_id": "t_8c21e4f09a3b",
  "period_from": "2025-01-01", "period_to": "2025-12-31",
  "received_on": "2026-06-15",       // optional: Zugang, Beginn der Einwandfrist
  "total_cost_eur": 1500, "prepaid_eur": 1440,
  "result_eur": 60,                  // positiv = Nachzahlung, negativ = Guthaben
  "positions": [
    { "label": "Warmwasser", "category": "warm_water", "amount_eur": 285, "consumption": 30, "unit": "m³" }
  ],
  "heat": { "consumption": 9000, "unit": "kWh", "cost_eur": 1350 },   // optional; unit kWh | MWh
  "co2": null,                       // optional (v3.1.0, H4): {emissions_kg, cost_eur, stage,
                                     //   landlord_share_pct, landlord_amount_eur} laut Heizkostenabrechnung
  "new_prepayment": { "from": "2026-07-01", "heating_eur_month": 75, "operating_eur_month": 50 },
  "attachment_ids": [],              // Belege (PDF, Foto)
  "booked": false,
  "note": "",
  "created_at": "2026-06-20T18:02:11+02:00"
}
```

- `category` ∈ {`heating`, `warm_water`, `cold_water`, `sewage`, `operating`,
  `other`}; ein unbekannter Wert wird `other`.
- `result_eur` fehlt in der Anfrage → Kosten − Vorauszahlung; positiv heißt
  Nachzahlung (wie beim Saldo).
- Wer eine Abrechnung mit `apply_prices` speichert, bekommt im Mietverhältnis
  einen Preiseintrag `source: statement` ab dem Tag nach `period_to`;
  `apply_prepayment` übernimmt `new_prepayment` in `prepayments`. Beides sind
  Schalter der Anfrage, keine gespeicherten Felder
  ([API](api.md)).
- Ein gelöschtes Mietverhältnis nimmt seine Abrechnungen mit; deren Belege
  werden frei und nach 24 Stunden aufgeräumt.
- **CO₂-Kosten (v3.1.0, H4).** `co2_own_appliances` und `co2_restriction` am
  Mietverhältnis kürzen den Anteil des Vermieters bei eigener Gastherme;
  `co2` an der Abrechnung trägt die CO₂-Angaben der Heizkostenabrechnung
  (Emissionen 0–10.000.000 kg, Beträge bis 1.000.000, Stufe 1–10, Anteil
  0–100 %). Ist `co2` gesetzt, gilt für das Jahr, in dem der Zeitraum endet, der
  Fall Zentralheizung ([CO₂-Kosten teilen](../anleitungen/co2-aufteilung.md)).

Alle Beträge in der Hauptwährung (`*_eur` meint die eingestellte Währung,
s. `currency`). Die Werte oben sind Beispiele.

**Backup.** `tenancies`, `tenancy_statements` und je Verbrauchsart `periods`
und `bills` sind Töpfe wie die anderen und reisen im Backup mit; das Format
bleibt `3.0`. `instance.json` bleibt draußen (s. o.).

### Versorgerrechnung *(v3.1.0)*

Die Rechnung des Versorgers mit ihren eigenen Zahlen, je Verbrauchsart in
`<art>/bills.json` (eine Liste) — für den Vergleich mit der eigenen Rechnung,
das Buchen des Ergebnisses und die CO₂-Angaben
([Jahresabrechnung](../anleitungen/jahresabrechnung.md#7-laut-rechnung-erfassen-vergleichen-buchen)):

```jsonc
{
  "id": "b_3f9c0a1d2e4b",            // b_ + 12 Hexziffern
  "meter_id": "m_gas_main",
  "contract_id": null,               // optional; sonst der Vertrag am Ende des Zeitraums
  "kind": "annual",                  // annual | final | interim
  "period_from": "2025-01-01",
  "period_to": "2025-12-31",         // inklusive
  "issued_on": "2026-02-10",         // optional: Rechnungsdatum
  "invoice": {                       // jeder Wert optional
    "energy_kwh": 16437,             // bei Wasser volume_m3
    "amount_eur": 1655,              // Rechnungsbetrag brutto
    "advances_paid_eur": 1800,
    "result_eur": -145               // positiv = Nachzahlung; ohne Angabe Betrag − Abschläge
  },
  "items": [                         // weitere Posten, nicht nachgerechnet
    { "label": "Messstellenbetrieb", "amount_eur": 12.5, "kind": "fee" }   // levy | fee | credit | other
  ],
  "co2": { "emissions_kg": 2981.5, "cost_eur": 163.98 },   // optional; Betrag mit USt wie auf der Rechnung (CO2KostAufG § 3 Abs. 3), dazu stated_factor?
  "attachment_ids": [],              // Belege (PDF, Foto)
  "special_payment_id": null,        // gesetzt, sobald das Ergebnis gebucht ist
  "note": "",
  "created_at": "2026-02-14T18:20:05+01:00"
}
```

- **Arten.** Nur Gas, Strom, Wasser und Fernwärme nehmen Rechnungen an
  (`errors.billCheck.unsupportedUtility` sonst); der Topf entsteht bei allen.
- **Regeln.** `period_from ≤ period_to` (`errors.bill.periodInvalid`), Zahlen
  im erlaubten Bereich (`errors.bill.amountInvalid`). Ein Zähler mit
  Rechnungen lässt sich nicht löschen (`errors.meter.hasBills`).
- **Buchen** legt eine Sonderzahlung „ohne Auswirkung“ im Vertrag an und
  merkt sich ihre ID in `special_payment_id` — einmal je Rechnung. Wer die
  Rechnung löscht, behält die Sonderzahlung; die Belege werden frei.
- **CO₂.** Emissionen und Betrag gehen beim CO₂-Preis dem Standardfaktor vor;
  `issued_on` einer Gasrechnung setzt zur Miete die Frist für die Erstattung
  ([CO₂-Preis im Brennstoff](../verstehen/16-co2-preis.md)).

### Delivery (Brennstofflieferung — Heizöl/Pellets)

```json
{
  "id": "del_heizoel_a1", "meter_id": "m_heizoel_tank",
  "date": "2023-09-12", "quantity": 1150.0,
  "unit_price_cents": 104.5, "total_eur": 1201.75,
  "supplier": "Ölhandel Am Hafen GmbH", "note": "Herbstbefüllung",
  "is_planned": false,
  "fill_to_full": false          // v2.10.0: true = danach voll (Stützstelle)
}
```

`quantity` in `volume_unit` der Art (Liter bei Heizöl, kg bei Pellets).
Kostenbasis seit v1.4.2: **`total_eur` hat Vorrang**; nur wenn kein
Gesamtbetrag gesetzt ist, wird `unit_price_cents` genutzt.

### Contract (Vertrag — primär für Gas/Strom/Wasser/Fernwärme)

```json
{
  "id": "c_gas_001", "meter_id": "m_gas_main",
  "provider": "Stadtwerke", "tariff_name": "Basis 2023",
  "start": "2023-01-01", "end": "2023-12-31", "notes": "",
  "working_prices":   [ { "from": "2023-01-01", "ct_per_kwh": 11.8 } ],
  "base_prices":      [ { "from": "2023-01-01", "eur_per_month": 11.9 } ],
  "advance_payments": [ { "from": "2023-01-01", "amount_eur": 90 } ],
  "bonuses":          [ { "credit_date": "2024-01-15",
                          "amount_eur": 60, "type": "wechselbonus",
                          "label": "Neukundenbonus" } ],
  "special_payments": [ { "id": "sp_…", "date": "2024-03-15",
                          "kind": "rueckzahlung_mit",
                          "amount_eur": 142.5, "note": "JA 2023",
                          "new_advance_eur": 95,
                          "advance_from": "2024-04-01" } ],
  "is_shadow": false, "shadow_label": null,
  "notice_period_months": 1, "notice_period_days": null,
  "notice_mode": null, "auto_renews": null,
  "min_term_end": null, "price_guarantee_until": null,
  "signup_bonus_eur": null
}
```

`is_shadow: true` = hypothetischer Tarif für den Vergleich; beeinflusst
**weder** Saldo **noch** Prognose.

**Kündigung (v2.3.0, erweitert v2.9.0)** — alle Felder optional, `null` =
nicht gepflegt:

| Feld | Bedeutung |
|---|---|
| `notice_period_months` | Kündigungsfrist in Monaten; `0` = jederzeit |
| `notice_period_days` | *(v2.9.0)* Frist in Tagen (0–730), hat Vorrang vor den Monaten; die Oberfläche speichert Wochen als Tage |
| `notice_mode` | *(v2.9.0)* `term_end` (zum Vertragsende), `month_end` (jederzeit zum Monatsende), `any_day` (jederzeit zu jedem Tag); `null` = mit Ende zum Vertragsende, ohne Ende zum Monatsende |
| `auto_renews` | *(v2.9.0)* `false` = gekündigt, endet wirklich; sonst läuft der Vertrag ohne Nachfolger zu seinen letzten Preisen weiter |
| `min_term_end` | Ende der Mindestlaufzeit (unbefristete Verträge) |
| `price_guarantee_until` | Ende der Preisgarantie |
| `signup_bonus_eur` | Neukundenbonus als Betrag (Angebote im Tarifvergleich) |

Ungültige Werte lehnt die API mit 400 ab (`errors.contract.noticeDaysOutOfRange`,
`errors.contract.noticeModeInvalid`). Endet `min_term_end` mehr als 24 Monate
nach `start`, antwortet die API seit v3.1.0 beim Speichern zusätzlich mit
`warnings: ["term_over_24_months"]` (§ 309 Nr. 9 BGB); gespeichert wird der
Hinweis nicht.

**Fernwärme (v3.1.0, CALC-31)** — nur bei `fernwaerme`, alle optional:

```jsonc
{
  "capacity_kw": 10,                                          // Anschlussleistung (kW)
  "capacity_prices": [ { "from": "2025-01-01", "eur_per_kw_year": 60 } ],   // Leistungspreis
  "metering_prices": [ { "from": "2025-01-01", "eur_per_year": 120 } ],     // Messpreis
  "co2_g_per_kwh": 180,                                       // Emissionsfaktor des Wärmenetzes
  "primary_energy_factor": 0.6                                // nur zur Information
}
```

Feste Kosten je Monat = Grundpreis + `capacity_kw` × Leistungspreis / 12 +
Messpreis / 12 (im Beispiel ohne Grundpreis 60 €). Ein Leistungspreis braucht
`capacity_kw` (`errors.contract.capacityMissing`); ungültige Werte →
`errors.contract.valueInvalid`. `co2_g_per_kwh` ersetzt `co2_fernwaerme` für
die Monate des Vertrags ([Fernwärme](../verstehen/04-fernwaerme.md)). Kein
Schema-Schritt: Fehlende Felder bedeuten „nicht gepflegt“.

**Gruppenvertrag, Netzentgelt, Preismodell, Gutschriften (v3.1.0)** — alle
optional, fehlend = wie bisher:

```jsonc
{
  "meter_id": null,                                   // bei einem Gruppenvertrag
  "meter_group_id": "g_strom_ab12cd34",               // Gas, Strom, Fernwärme
  "working_prices_by_meter": {                        // Arbeitspreis je Mitglied (HT/NT)
    "m_strom_ht": [ { "from": "2026-01-01", "ct_per_kwh": 30.0 } ],
    "m_strom_nt": [ { "from": "2026-01-01", "ct_per_kwh": 22.0 } ]
  },
  "grid_reduction": [ { "from": "2026-01-01", "eur_per_year": 120, "module": 1 } ],   // nur Strom, § 14a EnWG
  "price_model": "dynamic",                           // fehlt = fest; "monthly"; "dynamic" nur Schattenvertrag Strom
  "dynamic": { "markup_ct_per_kwh": 15, "base_eur_month": 10, "vat_pct": 19, "weighting": "flat" },
  "revenue_statements": [                             // nur Einspeisung
    { "from": "2026-06-01", "to": "2026-06-30", "amount_eur": 23.40, "kwh": 290.5 }
  ]
}
```

| Feld | Bedeutung |
|---|---|
| `meter_group_id`, `working_prices_by_meter` | Vertrag für eine Zählergruppe. Jedes Mitglied rechnet zu seinem Arbeitspreis (fehlt er, `working_prices`); Grundpreis, Abschläge und Boni trägt nur das erste Mitglied. Kein Mitglied darf im selben Zeitraum einen eigenen echten Vertrag haben (`errors.contract.groupMemberOverlap`) — [API](api.md#gruppenvertrag-v310) |
| `grid_reduction` | reduziertes Netzentgelt nach § 14a EnWG, Modul 1, in € je Jahr; tagesgenau als Abzug von den festen Kosten |
| `price_model`, `dynamic` | Preismodell: fest (fehlt), `monthly` (Monatspreise, etwa aus dem Import) oder `dynamic` (Dynamik-Check, nur als Schattenvertrag Strom; Aufschlag 0–100 ct/kWh, Grundpreis 0–1000 €/Monat, USt 0–30 %, Standard 19) |
| `revenue_statements` | Gutschriften des Direktvermarkters, `to` einschließlich; ersetzen für ihren Zeitraum kWh × Vergütung, tagesgenau |

Kein Schema-Schritt: Die Felder reisen mit dem Vertrag im Backup.

Wasser nutzt zusätzlich ein
Drei-Komponenten-Modell (Trink-/Schmutz-/Niederschlagswasser), siehe
[Wasser](../verstehen/03-wasser.md).

### Börsenstrompreise (`market_prices.json`) *(v3.1.0)*

```json
{ "source": "smard", "area": "DE-LU", "unit": "ct/kWh",
  "months": { "2025-01": { "avg_ct": 11.414 } },
  "imported_at": "2026-02-03T19:12:00+01:00" }
```

Großhandelspreise Strom (Day-Ahead, Deutschland/Luxemburg) als Monatsmittel in
ct/kWh, netto — gefüllt aus einer Datei (`source: "csv"`) oder auf Knopfdruck
von SMARD (`"smard"`); ein Import ersetzt nur die Monate, die er enthält.
Grundlage des Dynamik-Checks ([API](api.md#börsenstrompreise-und-dynamik-check-v310)).
Teil des Backups; Schema 1.7.0 legt den Topf leer an.

**`special_payments` (F1003, ab v1.5.0)** — nur bei Gas/Strom/Fernwärme
(Single-Source-of-Truth: `Utilities::hasAdvancePaymentContracts()`).
`kind` ∈ {`rueckzahlung_mit`, `rueckzahlung_ohne`, `nachzahlung_mit`,
`nachzahlung_ohne`, `abschlagszahlung`}. `amount_eur` ist stets positiv;
das Vorzeichen im Saldo ergibt sich aus `kind` (Rückzahlung erhöht den
Saldo, Nach-/Abschlagszahlung senkt ihn). Nur `*_mit`-Arten tragen
`new_advance_eur` + `advance_from`; diese Punkte werden in den
effektiven Abschlagsplan gemischt. Additiv & abwärtskompatibel — fehlt
das Feld, wird es beim Normalisieren zu `[]` (kein Migrationsschritt).

### Ladevorgänge (`ev_sessions.json`) *(v3.2.0)*

Ladevorgänge der Wallbox aus evcc, eine Liste über alle Zähler — gefüllt aus
dem CSV-Export oder dem Abruf im Heimnetz
([API](api.md#ladevorgänge-aus-evcc-v320)). Die Datei entsteht beim ersten
Import; kein Schema-Schritt.

```json
{ "id": "evs_1a2b3c4d5e6f", "meter_id": "m_wallbox",
  "created": "2026-09-30T17:02:11+02:00", "finished": "2026-09-30T21:40:03+02:00",
  "date": "2026-09-30", "loadpoint": "Garage", "vehicle": "Kleinwagen",
  "charged_kwh": 18.402, "solar_pct": 0, "price_eur": 5.15, "price_per_kwh": 0.28,
  "meter_start": 4120.551, "meter_stop": 4138.953, "source": "csv" }
```

| Feld | Bedeutung |
|---|---|
| `id` | `evs_` und 12 Hexziffern aus Beginn und Ladepunkt — derselbe Vorgang ergibt dieselbe Kennung, ein zweiter Import ersetzt ihn |
| `meter_id` | Stromzähler, an den der Vorgang übernommen wurde (Wallbox) |
| `created`, `finished` | Beginn und Ende, ISO 8601 mit Zeitzone; Zeiten ohne Zone aus evcc gelten in der Zeitzone der Installation |
| `date` | Tag des Endes (`JJJJ-MM-TT`) — danach zählen Jahr und Monat |
| `loadpoint`, `vehicle` | Ladepunkt und Fahrzeug wie in evcc, je höchstens 60 Zeichen, auch leer |
| `charged_kwh` | geladene Energie in kWh (3 Nachkommastellen) |
| `solar_pct` | Sonnenanteil in % (0–100), wenn evcc ihn nennt |
| `price_eur`, `price_per_kwh` | Preis des Vorgangs und je kWh laut evcc, wenn genannt — Angaben von evcc, keine Rechnung des Energietrackers |
| `meter_start`, `meter_stop` | Zählerstand der Wallbox zu Beginn und Ende in kWh, wenn die Wallbox misst |
| `source` | `csv` (CSV-Export) oder `evcc` (Abruf) |

Felder ohne Wert fehlen. Die Zählerstände, die ein Import ableitet, stehen
nicht hier, sondern als gewöhnliche Ablesungen in `strom/readings.json` (Notiz
`evcc`). Teil des Backups als Topf `ev_sessions`; der Import prüft `id`,
`meter_id`, `date` (Kalenderdatum) und `charged_kwh`.

### Personen in `auth.json` *(v3.2.0)*

Mit eingeschalteter Anmeldung kann ein Haushalt mehrere Personen haben
([API](api.md#personen-im-haushalt-v320)). Sie stehen unter `users` in
`data/auth.json` — wie Passwort und Schlüssel **nicht im Backup**: Ein Backup,
das auf einer anderen Installation eingespielt wird, bringt keine Zugänge mit.
Kein Schema-Schritt.

```jsonc
"users": [
  { "id": "u_admin", "name": "admin", "role": "admin", "source": "password",
    "prefs": {}, "created_at": "…" },                       // erster Verwalter: Passwort = password_hash oben
  { "id": "u_3f9a1c2e", "name": "Alex", "role": "member", "source": "password",
    "password_hash": "$2y$10$…", "prefs": { "ui_level": "beginner" }, "created_at": "…" },
  { "id": "u_8b0d4f17", "name": "kim", "role": "admin", "source": "proxy",
    "prefs": {}, "created_at": "…" }
]
```

| Feld | Bedeutung |
|---|---|
| `id` | `u_` und acht Hexziffern; `u_admin` ist der erste Verwalter aus der Zeit vor v3.2 |
| `name` | 1–40 Zeichen, eindeutig ohne Rücksicht auf Groß- und Kleinschreibung; bei `proxy` der gemeldete Name |
| `role` | `admin` (Verwaltung) oder `member` (Mitglied); mindestens eine Person bleibt Verwalter |
| `source` | `password` (Anmeldung in der App) oder `proxy` (vorgeschalteter Dienst, angelegt beim ersten Besuch) |
| `password_hash` | nur bei `password`: Hash nach `password_hash()` (bcrypt), **nie das Passwort selbst**. `u_admin` hat keinen eigenen und benutzt das Passwort der Installation (`password_hash` auf oberster Ebene bzw. `ET_ADMIN_PASSWORD_HASH`) |
| `prefs` | eigene Einstellungen: `ui_level`, `language`. Fehlt die Stufe, gilt die der Installation; fehlt die Sprache, die des Geräts bzw. der Installation |
| `created_at` | angelegt (ISO 8601) |
| `session_epoch` | zählt jedes neue Passwort dieser Person; fehlt bis zur ersten Änderung. Ein Sitzungs-Cookie gilt nur mit der aktuellen Epoche — ein neues Passwort meldet die Person auf allen Geräten ab |

Solange niemand eine Person anlegt, fehlt `users`; `u_admin` gibt es dann nur
in den Antworten der API, solange ein Passwort gesetzt ist. Die Sperre nach
Fehlversuchen (`login_failures`) gilt für alle Personen gemeinsam. Sitzungs-Cookies
tragen die Kennung der Person (`<ablauf>.<id>.<hmac>`); die Form aus v3.1 ohne
Kennung steht für `u_admin`, bis dessen Passwort zum ersten Mal neu gesetzt
wird. Eine gelöschte Person hat keine gültige Sitzung mehr.

---

## 3. Einstellungen (`settings.json`)

Gespeichert werden nur Abweichungen vom Standard. Jeden Schlüssel mit Ort in der
Oberfläche und Wirkung listet die [Einstellungsreferenz](einstellungen.md); hier
eine Auswahl mit Hintergrund:

| Schlüssel | Default | Bedeutung |
|---|---|---|
| `gas_conversion_factors` | `[{from:null, kwh_per_m3:11.5}]` | **Datierte Liste** (F1012): je Eintrag `from` (ISO-Datum oder `null` für „davor"), `zustandszahl`, `brennwert`, `kwh_per_m3` (abgeleitet = z × Hs, 5 Nachkommastellen). Wirksam ist der letzte Eintrag mit `from ≤ Tag`. |
| `heizoel_kwh_per_l` | 10.0 | Heizwert Heizöl EL |
| `pellets_kwh_per_kg` | 4.8 | Heizwert Holzpellets (DIN EN ISO 17225-2 A1) |
| `hdd_base_temp` | 15.0 | Heizgrenztemperatur (°C) für HGT |
| `co2_gas` | 182 | g CO₂ je kWh (Brennwert): BAFA 201 auf Heizwert × 0,906 — *v2.10.0, vorher 201* |
| `co2_strom` | 380 | g/kWh für Jahre vor dem ersten Eintrag in `co2_strom_years` |
| `co2_strom_years` | UBA 2015–2025 | *(v2.10.0)* Jahr → g/kWh, Umweltbundesamt (Emissionsfaktor Strommix); nach dem letzten Jahr gilt dessen Wert |
| `co2_heizoel / _pellets / _fernwaerme` | 266 / 36 / 280 | g/kWh, BAFA (Pellets CO₂-Äq. inkl. Vorkette; Fernwärme-Pauschale) — *v2.10.0, vorher 266 / 26 / 180* |
| `co2_wasser` | 350 | g/m³ — grober Richtwert ohne belegte Quelle |
| `blend_max` | 0.80 | Obergrenze Regressionsgewicht in der Prognose |
| `confidence_band_sigma` | 1.28 | Breite des Prognosebands in σ (1,28 ≈ 80 % der Jahre); bis v2.7 ohne Wirkung |
| `anomaly_threshold` | 2.0 | Schwelle der Anomalie-Erkennung (robuster z-Wert) |
| `min_days_period`, `min_hdd_regression` | 20, 5 | Mindesttage bzw. -Gradtage, ab denen ein Monat in Modelle eingeht |
| `forecast_months` | 12 | Prognosehorizont |
| `forecast_model` | linear | Standard-Regressionsmodell |
| `segmented_split_mode` | auto | Knickpunkt der segmentierten Regression |
| `wohnflaeche_m2` | 100 | für Effizienzklasse |
| `gebaeudetyp`, `beheizter_keller`, `warmwasser_dezentral` | efh, false, false | *(v2.10.0 wirksam)* Gebäudenutzfläche 1,2 bzw. 1,35 × Wohnfläche, Warmwasser-Zuschlag 20 kWh/m²·a für die energieausweis-nahe Kennzahl |
| `wasser_personen_referenz` | 122 | L je Person und Tag, BDEW 2024 (*v2.10.0, vorher 127*) |
| `dashboard_months` | 12 | *(seit v2.9.0 wirksam)* Monate im Verbrauchsverlauf des Dashboards (3–36) |
| `alert_days_since_reading` | 45 | *(seit v2.9.0 wirksam)* „Ablesung überfällig": Warnung ab ⅔, Alarm ab dem Wert |
| `contract_remind_days_1/2/3` | 90 / 30 / 1 | Erinnerungsstufen; seit v2.9.0 Tage vor dem Kündigungsstichtag, ohne Frist vor dem Vertragsende |
| `min_temp_days_forecast`, `baujahr` | 20, null | **veraltet (v2.9.0)**, ohne Wirkung und nicht mehr in der Oberfläche; entfallen mit v3.0.0 |
| `efficiency_class_thresholds` | A+…G | Bandgrenzen kWh/m²·a |
| `billing_cycle_anchor_*` | 01-01 | Abrechnungsstichtag — gespeichert `MM-TT`, **angezeigt `TT-MM`** (v1.4.2); seit v2.9.0 muss es ein Kalendertag sein, sonst 400. Seit v2.13.0 auch `…_pv_einspeisung` (die Vergütung rechnet bis zum Stichtag) |
| `billing_cycle_anchor_heizoel`, `…_pellets` | 01-01 | **veraltet (v2.13.0)**, ohne Wirkung (keine Abschläge, kein Saldo) und nicht mehr in der Oberfläche; entfallen mit v3.0.0 |
| `delivery_baseload_share` | 0.15 | wetterunabhängiger Grundlastanteil bei Lieferarten |
| `tank_warn_pct` | 15 | Warnschwelle Tankfüllstand in %; Alarm ab der Hälfte (seit v2.13.0 auch in der Oberfläche) |
| `active_utilities` | gas, strom, wasser | welche Arten Menü und Auswertungen zeigen; abgewählte behalten ihre Daten. Heizwärme (`waerme`, v3.1.0) ist nicht standardmäßig aktiv |
| `wohnverhaeltnis` | eigentum | *(v3.1.0)* `eigentum` oder `miete`; bei `miete` erscheint die Seite „Mietverhältnis“, und die Agenda kennt die Fristen der Nebenkostenabrechnung |
| `waerme_energietraeger` | null | *(v3.1.0)* womit die Heizwärme erzeugt wird (`gas`, `heizoel`, `pellets`, `fernwaerme`, `strom`) — ihr CO₂-Wert kommt mit dessen Faktor, ohne Angabe 0 |
| `warmwasser_temp_c` | 60 | *(v3.1.0)* Warmwassertemperatur (30–90 °C) für die Wärme der Warmwasserzähler nach HeizkostenV § 9 Abs. 2 |
| `warmwasser_energietraeger` | null | *(v3.1.0)* womit das Warmwasser erwärmt wird (wie oben, dazu `waerme`) — nur zur Information |
| `location_name`, `latitude`, `longitude` | Leipzig | für Open-Meteo (übermittelt auf zwei Nachkommastellen gerundet) |
| `weather_auto_fill` | true | *(seit v2.8.0 wirksam)* Temperaturen einmal am Tag beim Öffnen der App abgleichen |
| `language` | de | Standardsprache der Installation (Geräte ohne eigene Wahl, PDF, CSV, Home Assistant); ein Gerät kann per `X-ET-Language` abweichen (v3.1.0) |
| `country` | DE | *(v2.7.0)* Land: Schreibweise (mit der Sprache), Effizienzskala — [Länderprofile](../verstehen/14-laenderprofile.md) |
| `currency` | EUR | *(v2.7.0)* `EUR`, `CHF`, `GBP` — Symbol und Untereinheit; Beträge werden nicht umgerechnet, `*_eur`/`ct_*` meinen Haupt-/Untereinheit |
| `timezone` | Europe/Berlin | *(v2.7.0)* IANA-Zeitzone: „heute“, Fälligkeiten, Tagesgrenzen der Wetterdaten |
| `gas_cv_unit` | kwh | *(v2.7.0)* Eingabeeinheit des Brennwerts (`kwh`, `mj`, `gj`); gespeichert wird immer kWh/m³ |
| `frame_ancestors` | *(leer)* | *(v2.6.0)* Ursprünge, die die App einbetten dürfen (CSP `frame-ancestors`), z. B. `http://homeassistant.local:8123`; seit v3.2.0 nur Verwalter |
| `attachments_max_mb` | 500 | *(v3.1.0)* Speicher für alle Belege zusammen in MB (10–100000) |
| `ocr_endpoint`, `ocr_api`, `ocr_model`, `ocr_timeout_s` | *(leer)*, ollama, *(leer)*, 30 | *(v3.1.0)* Texterkennung im Heimnetz; leer = aus, keine Verbindung — [Einstellungen](einstellungen.md), [Anleitung](../anleitungen/texterkennung.md). `ocr_endpoint` ändern seit v3.2.0 nur Verwalter |
| `evcc_endpoint` | *(leer)* | *(v3.2.0)* Adresse von evcc im Heimnetz für den Abruf der Ladevorgänge; leer = aus, keine Verbindung, nur lokale Adressen; nur Verwalter — [Ladevorgänge aus evcc](../anleitungen/evcc.md) |
| `ui_level` | expert | *(v3.2.0)* Nutzungsstufe der Installation: `beginner`, `advanced`, `expert` — was die Oberfläche zeigt, nicht was die App rechnet. Personen haben ihre eigene in `auth.json` (`prefs.ui_level`); Bestand nach dem Update: Experte |
| `setup_pending` | false | *(v3.2.0)* `true` nur nach dem Erststart einer Neuinstallation, bis der Einrichtungsassistent fertig oder übersprungen ist |
| `setup_persona` | null | *(v3.2.0)* zuletzt gewählte Persona des Assistenten bzw. geladener Beispielhaushalt (`mieterin`, `etw-fernwaerme`, `eigenheim-klassisch`, `eigenheim-modern`, `showcase`) |
| `co2_price_eur_t_years` | `{}` | *(v3.1.0)* eigene CO₂-Preise je Jahr in €/t (Jahr → Wert, 0–1000); leer = Länderprofil — [CO₂-Preis](../verstehen/16-co2-preis.md) |
| `co2_price_scenario_eur_t`, `co2_price_scenario_from` | null, 2028 | *(v3.1.0)* CO₂-Preis-Szenario der Prognose: Preis in €/t (leer = aus) und erstes Jahr |
| `co2_pv_avoided` | null | *(v3.1.0)* eigener Vermeidungsfaktor der PV in g/kWh (0–2000); leer = Strommix |
| `pv_assumed_self_consumption_pct` | null | *(v3.1.0)* angenommener Eigenverbrauch eines Balkonkraftwerks ohne Einspeisezähler in % (0–100); leer = keine Annahme |
| `reference_strom_kwh`, `reference_heat_kwh_m2`, `reference_source`, `warmwasser_elektrisch` | null, null, leer, false | *(v3.1.0)* eigene Vergleichswerte für die Einordnung: Haushaltsstrom in kWh/a (0–100000), Heizung in kWh/m²·a (0–1000), Quelle (bis 120 Zeichen), Warmwasser mit Strom |

Die vollständige Liste steht in `SettingsService::DEFAULTS`. Unbekannte
Schlüssel speichert `PATCH /api/settings` nicht und nennt sie seit v2.6.0 in
`ignored_keys`.

> Die `billing_cycle_anchor_*`-Werte werden **kanonisch als `MM-TT`**
> gespeichert (damit das Backend ein valides `YYYY-MM-TT` bauen kann),
> aber in der UI seit v1.4.2 im deutschen Format **`TT-MM`** angezeigt
> und eingegeben. Die Konvertierung passiert ausschließlich an der
> UI-Grenze.

---

## 4. Schema-Migration

`Storage/Migrator` läuft beim ersten App-Start und ist **idempotent**:

- erkennt die `schema_version` in `meta.json`,
- erkennt ein komplett leeres Verzeichnis (`isPristine()`, seit v1.9.1) und
  legt dann frische Standard-Zähler an (`initFresh()`) statt blind zu migrieren,
- ergänzt fehlende Verzeichnisse/Dateien (neue Verbrauchsarten,
  `reminders.json`, `meter_groups.json`) und neue Zähler-Felder additiv
  (`parent_meter_id`/`meter_group_id` in 1.2.0, `external_id` in 1.3.0,
  `baseline_events` in 1.4.0) und wandelt in 1.5.0 den Skalar
  `gas_conversion_factor` in die Liste `gas_conversion_factors` um,
- schreibt in 1.6.0 für die korrigierten Defaults (`co2_gas`,
  `co2_strom_years`, `co2_pellets`, `co2_fernwaerme`,
  `wasser_personen_referenz`) den bisherigen Wert fest, wo die Installation
  ihn nie gespeichert hat — nur bei Daten älter als 1.6.0,
- legt in 1.7.0 (v3.1.0) die Töpfe `attachments.json`, `tenancies.json`,
  `tenancy_statements.json` und `market_prices.json` sowie je Verbrauchsart fehlende Grundtöpfe
  (`meters`, `contracts`, `meter_groups`, `readings` bzw. `deliveries`),
  `periods.json` und `bills.json` leer an — so bekommt auch eine Bestandsinstallation den
  Ordner der neuen Verbrauchsart `waerme/`, ohne Standardzähler. Ein
  vorhandener Datensatz wird dabei nicht verändert; ein Erststart legt
  dieselben Töpfe an,
- hebt die Version schrittweise auf den aktuellen Stand (**1.7.0**).

Jede Stufe hat ein eigenes `needsVXXXUpgrade()` + `upgradeToVXXX()`-Paar und
ist für sich idempotent (wiederholtes Ausführen ist ein No-Op).

Die mitgelieferten Demo-Daten tragen `schema_version: 1.1.0` und werden
beim ersten Start additiv auf den aktuellen Stand (1.7.0) migriert —
dabei kommen `meter_groups.json` je Utility (1.2.0) und die Zähler-Felder
`external_id` (1.3.0) und `baseline_events` (1.4.0) hinzu, ohne bestehende
Werte anzutasten. Der
Migrationspfad (1.0.0 → aktuelles Schema) wird zusätzlich in der CI über
einen separaten Migrations-Smoke geprüft. Vor jeder Migration legt der
Migrator einen Snapshot `pre-migration-…` an (seit v2.5.3).

**Beispielhaushalte *(v3.2.0)*.** Neben diesem Demo-Haushalt (dem
„Schaufenster“ mit allen Verbrauchsarten) liegen vier Beispielhaushalte unter
`demo-data/personas/<persona>.json`: `mieterin`, `etw-fernwaerme`,
`eigenheim-klassisch`, `eigenheim-modern`. Sie haben das Backup-Format 3.0 mit
Schema 1.7.0 und enthalten alle Töpfe aller Verbrauchsarten, auch leere — so
ersetzt ein Beispielhaushalt den ganzen Haushalt. Erzeugt werden sie von
`tools/build-personas.mjs` aus einem Tagesmodell mit festem Startwert: Jeder
Lauf schreibt dieselben Dateien, `--check` vergleicht nur. Namen sind
erfunden, Marktlokations-IDs synthetisch. Beim Laden schreibt sie
`DemoDataAligner` wie das Schaufenster bis heute fort. Das Docker-Image enthält
sie ([API](api.md#beispielhaushalte-v320)).

**Downgrade-Schutz (v2.6.0).** Ein Downgrade wird nicht unterstützt — aber
jetzt erkannt: Ist `schema_version` der Daten **neuer** als die App, schreibt
sie nichts, alle Routen außer `/api/health` antworten `503`
(`errors.storage.dataTooNew`), und `/api/health` meldet `error`. Bis v2.5.3
stempelte eine ältere Version die neueren Daten still auf ihr Schema zurück.

---

[← API-Referenz](api.md) ·
[Tests →](../entwicklung/tests.md)
