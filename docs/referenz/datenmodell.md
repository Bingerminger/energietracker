# Datenmodell

**Deutsch** · [English](../en/referenz/datenmodell.md)

[← API-Referenz](api.md) · [Kompendium-Index](../README.md)

Alle Daten liegen als flache JSON-Dateien unter `data/`. Keine Datenbank.
Schreibvorgänge sind durch `LOCK_EX` serialisiert. Schema-Stand: **1.8.0**
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
> entsteht beim ersten Import, Personen stehen in `auth.json` ·
> **1.8.0** Index `intervals.json` für Werte je Viertelstunde leer angelegt;
> die Reihen unter `intervals/` entstehen mit dem ersten Import (v3.3.0) ·
> v3.4.0 **ohne** Schema-Schritt: `activity.json` (Verlauf erledigter
> Hinweise) entsteht mit dem ersten Eintrag · v3.6.0 **ohne** Schema-Schritt:
> `people.json` (Personenverzeichnis) entsteht beim ersten Lesen der Personen.

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
├── intervals.json            # Index der Viertelstundenreihen { series, samples } (v3.3.0, Schema 1.8.0)
├── intervals/                # Viertelstundenreihen <id>.json, je Zähler bzw. Börsenpreis und Kalenderjahr (v3.3.0)
├── activity.json             # Verlauf erledigter Hinweise (v3.4.0) — entsteht mit dem ersten Eintrag
├── people.json               # Personenverzeichnis: Nummer, Name, Rolle, Löschvermerk (v3.6.0) — im Backup, ohne Passwörter
├── inbox.json                # Posteingang: Versorgerpost mit Auslesung (v3.7.0) — entsteht mit dem ersten Dokument
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
`backups/` wächst deshalb mit jedem Foto, je Snapshot einmal. Seit v3.3.0
enthält er auch die Viertelstundenreihen, bis zu rund 250 KB je Reihe und Jahr.

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

  // v3.7.0, Heizarten, optional — fehlt = Einstellung hdd_base_temp (seit v3.9.0 auch Heizöl, Pellets
  // und an der Zählergruppe in meter_groups.json):
  "hdd_base_temp": 12.0,                 // eigene Heizgrenze dieses Zählers (6–22 °C, Heizgrenze-Vorschlag)

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
  "heat_pump_meter_ids": ["m_wp_strom"], // Stromzähler der Wärmepumpe (Rolle heat_pump)

  // v3.7.0, optional — fehlt = niemand zuständig:
  "assignee_id": "u_3f9a1c2e"            // Person aus dem Personenverzeichnis
}
```

**Zuständig (`assignee_id`, v3.7.0).** Am Zähler wie am Vertrag
([Zuständig an Zählern und Verträgen](#zuständig-an-zählern-und-verträgen-v370)).

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

- `source`: `ingest` (Home Assistant), `csv` (CSV-Import) oder seit v3.7.0
  `inbox` (aus einem Dokument im [Posteingang](#posteingang-v370) übernommen).
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
`bill`; seit v3.7.0 `inbox` und `contract`). Die Datei liegt unter
`data/attachments/<id>.<ext>`, ihr Eintrag im Index `attachments.json` (eine
Liste):

```jsonc
{
  "id": "att_5f0c2a9e81d34b67",        // att_ + 16 Hexziffern
  "kind": "reading_photo",             // reading_photo | bill_pdf | statement_pdf | inbox_pdf (v3.7.0) | other
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
- **Gas-Umrechnung laut Rechnung** *(v3.7.0, optional)*: `factors`
  `[{from, to, zustandszahl, brennwert}]`, etwa aus dem Posteingang. „Rechnung
  prüfen“ hält sie gegen die Stichtag-Liste; ohne Angabe fehlt das Feld.
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
                          "amount_eur": 60, "type": "wechsel",
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

*(v3.7.0)* Optional `assignee_id`: die zuständige Person — Kündigungsfrist,
Vertragsende und „Abrechnung erwartet“ landen bei ihr
([Zuständig an Zählern und Verträgen](#zuständig-an-zählern-und-verträgen-v370)).

**Boni** (`bonuses[]`): `credit_date`, `amount_eur`, `type` — `sofort`,
`wechsel`, `neukunde` oder *(v3.7.0)* `sonstige` („Sonstige Gutschrift“, etwa
Kulanz) — und `label`, die Notiz (im Vertragsformular seit v3.7.0 sichtbar;
bis v3.6 löschte das Formular sie beim Speichern). Jeder Bonus zählt im Monat
seiner Gutschrift, nach dem Vertragsende im letzten Vertragsmonat. Die
Monatszeilen und Abrechnungsperioden tragen sie einzeln als `bonus_items`
*(v3.7.0, additiv)*; die Kachel „Kosten“ zeigt die Kosten ohne Bonus und den
Bonus als eigene Zeile, der Saldo rechnet ihn ein.

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

**Zeitvariables Netzentgelt (v3.3.0)** — nur Strom, optional: § 14a EnWG,
Modul 3, aus dem Preisblatt des Netzbetreibers.

```json
"grid_tou": { "standard_ct": 8.1,
              "windows": [ { "quarters": [1, 2, 3, 4], "from": "00:00", "to": "06:00", "ct": 2.4 } ] }
```

| Feld | Bedeutung |
|---|---|
| `standard_ct` | Netzentgelt je kWh außerhalb der Fenster (ct/kWh, 0–100) |
| `windows[]` | höchstens 24 Fenster: `quarters` (1–4, sortiert, ohne doppelte), `from` und `to` als Ortszeit `HH:MM` (`to` bis `24:00`, `from` vor `to`), `ct` (0–100). Ein Fenster über Mitternacht sind zwei; bei Überschneidung gilt das erste |

Ohne Standardtarif und Fenster fehlt das Feld. Es ändert weder Saldo noch
Prognose noch Tarifvergleich; die Flexibilität rechnet damit die Ersparnis
gegen den Standardtarif — Validierung und Fehler:
[API](api.md#verträge-zeitvariables-netzentgelt-modul-3-v330-additiv). Kein
Schema-Schritt.

**Vertrags- und Kundennummer, Belege (v3.7.0)** — optional: `contract_number`
und `customer_number` (Text, höchstens 64 Zeichen; leer entfernt das Feld),
`attachment_ids` (Liste von Beleg-IDs, etwa das Dokument aus dem
[Posteingang](#posteingang-v370); eine unbekannte ID lehnt die API ab,
`errors.attachment.notFound`). Der Posteingang ordnet Dokumente über die
Nummern zu. Kein Schema-Schritt.

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

**`special_payments` (F1003, ab v1.5.0)** — bei Gas, Strom, Wasser (seit
v3.5.0, [#34](https://github.com/Bingerminger/energietracker/issues/34), neben
den drei Komponenten) und Fernwärme (Single-Source-of-Truth:
`Utilities::hasSpecialPayments()`, in `/api/utilities` als
`has_special_payments`; bis v3.4 `hasAdvancePaymentContracts()`, das Wasser
ausschließt). Heizöl, Pellets und PV-Einspeisung verwerfen das Feld beim
Speichern.
`kind` ∈ {`rueckzahlung_mit`, `rueckzahlung_ohne`, `nachzahlung_mit`,
`nachzahlung_ohne`, `abschlagszahlung`}. `amount_eur` ist stets positiv;
das Vorzeichen im Saldo ergibt sich aus `kind` (Rückzahlung erhöht den
Saldo, Nach-/Abschlagszahlung senkt ihn). Nur `*_mit`-Arten tragen
`new_advance_eur` + `advance_from`; diese Punkte werden in den
effektiven Abschlagsplan gemischt. Additiv & abwärtskompatibel — fehlt
das Feld, wird es beim Normalisieren zu `[]` (kein Migrationsschritt); ein
älterer Wasservertrag behält es fehlend, bis Sonderzahlungen kommen, und wird
wie `[]` gelesen.

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

### Viertelstundenreihen (`intervals.json`) *(v3.3.0)*

Werte je Viertelstunde für die
[Flexibilität](api.md#flexibilität-bewerten-v330): Verbrauch je Zähler und
Börsenpreis, je Kalenderjahr eine Reihe. Der Index steht in `intervals.json`,
jede Reihe in einer eigenen Datei `intervals/<id>.json`. Schema 1.8.0 legt den
leeren Index `{"series": [], "samples": {}}` an; Reihen entstehen erst mit dem
ersten Import. Zählerstände und Tageswerte bleiben, wo sie sind — die
Viertelstunden sind eine zusätzliche Ablage.

**Index:**

```json
{ "series": [
    { "id": "e-strom-m_strom_waermepumpe-2025", "kind": "energy", "utility": "strom",
      "meter_id": "m_strom_waermepumpe", "unit": "kWh", "year": 2025, "step": 900,
      "slots": 35040, "filled": 35040, "from": "2025-01-01T00:00:00+01:00",
      "to": "2026-01-01T00:00:00+01:00", "source": "import",
      "updated_at": "2026-05-31T13:19:08+02:00" } ],
  "samples": { "strom/m_strom_main": { "ts": 1791808620, "counter": 12345.6 } } }
```

| Feld | Bedeutung |
|---|---|
| `id` | Kennung und Dateiname: Verbrauch `e-<art>-<zähler>-<jahr>`, Börsenpreis `p-de-lu-<jahr>`; klein geschrieben, andere Zeichen als `A–Z`, `a–z`, `0–9`, `_`, `.` und `-` werden zu `-`. Muster `^[a-z0-9][a-z0-9_.-]{1,100}$` |
| `kind` | `energy` (Verbrauch) oder `price` (Börsenpreis) |
| `utility`, `meter_id` | nur bei `energy`: Verbrauchsart und Zähler |
| `area` | nur bei `price`: Gebotszone `DE-LU` |
| `unit` | `kWh` (Verbrauch je Viertelstunde) bzw. `ct/kWh` (Großhandelspreis, netto) |
| `year` | Kalenderjahr in der Zeitzone der Installation |
| `step` | `900` — Sekunden je Platz |
| `slots`, `filled` | Plätze des Jahres und davon die mit Wert |
| `from`, `to` | Beginn der ersten und Ende der letzten Viertelstunde mit Wert, ISO 8601 in Ortszeit; `null` ohne Wert |
| `source` | Quelle des letzten Schreibens: `import` (Zeitreihen-Import), `ingest` (Zählerstand mit Zeitstempel), `smard` (Börsenpreise) |
| `updated_at` | Zeitpunkt des letzten Schreibens |

`samples` hält je `<art>/<zähler>` den letzten Zählerstand mit Zeitstempel aus
dem Ingest — `ts` in Unix-Sekunden und `counter` —, den Bezug für den nächsten
Push ([API](api.md#zeitstempel-und-viertelstunden-v330)).

**Reihe** (`intervals/<id>.json`):

```json
{ "id": "p-de-lu-2025", "kind": "price", "year": 2025, "step": 900,
  "start": "2025-01-01T00:00:00+01:00",
  "values": [0.216, 0.216, 0.216, 0.216, 0.16, "…"] }
```

`values` hat für jede Viertelstunde des Jahres einen Platz; Platz *i* beginnt
um `start` + *i* × 900 Sekunden. Das Jahr läuft vom 1. Januar 0:00 bis zum
1. Januar 0:00 in der Zeitzone der Installation (`timezone`):

```text
Plätze = (Beginn des Folgejahres − Beginn des Jahres) ÷ 900 s
2025:  365 Tage × 96 = 35.040        Schaltjahr 2028:  366 × 96 = 35.136
Tag der Umstellung auf Sommerzeit: 92 Plätze, zurück auf Winterzeit: 100
```

Fehlende Werte sind `null`. Verbrauch steht in kWh mit vier, Preise in ct/kWh
mit drei Nachkommastellen; eine volle Reihe belegt als Datei rund 250 KB (kompakt
geschrieben, nicht eingerückt wie die übrigen Dateien).
Gerechnet wird mit dem Jahresbeginn in der **aktuellen** Zeitzone der
Installation, `start` hält ihn beim Schreiben fest — wechselt die Zeitzone
später, verschieben sich die Plätze um den Unterschied.

- **Nur Reihen im Index zählen.** Eine Datei ohne Indexeintrag liest die App
  nicht, und das Backup nimmt sie nicht mit.
- **Ein neuer Wert ersetzt den alten** am selben Platz. Ausnahme beim Ingest:
  Lag der vorige Zählerstand mitten in einer Viertelstunde, kommt der neue
  Anteil zum vorhandenen dazu.
- **Löschen** (`DELETE /api/intervals/{id}`) entfernt Eintrag und Datei;
  `samples` bleibt, der nächste Push verteilt ab diesem Stand weiter.
- **Backup:** Topf `intervals` (der Index) und Schlüssel `interval_files`
  `{"<id>": Reihe}` — immer, auch ohne Belege. Beim Einspielen braucht jede
  Reihe einen Eintrag im Index des Backups; danach fallen Reihen ohne Eintrag
  im neuen Index weg ([API](api.md#snapshots-und-import-v260)).

### Termin (`reminders.json`) — Zuständig *(v3.5.0)*

Termine stehen in einer Liste (`id`, `title`, `category`, `next_due`,
`recurrence`, `recurrence_months`, `last_done`, `notes`, `active`). Seit
v3.5.0 optional dazu, ohne Schema-Schritt — fehlende Felder heißen
„niemand zuständig“:

| Feld | Bedeutung |
|---|---|
| `assignee` | Anzeigename der zuständigen Person, getrimmt, bis 60 Zeichen; bei einem erledigten einmaligen Termin der Name beim Erledigen |
| `assignee_id` | Personen-ID (`u_…`, [Personenverzeichnis](#personenverzeichnis-peoplejson-v360)), nur wenn aus der Personenliste gewählt |
| `assignee_former` | `true`, nachdem die Person gelöscht und ihr Name ersetzt wurde („ehemalige Person“) |
| `assignee_deleted` | *(v3.6.0)* `true`, nachdem die Person gelöscht wurde: Name und ID bleiben, angezeigt als „Name (gelöscht)“ |

Beim Löschen einer Person gehen ihre **offenen** Termine (per ID oder mit
gleichem freiem Namen) an die löschende Person; **erledigte** behalten Name
und ID mit `assignee_deleted` bzw. werden „ehemalige Person“. Die Antworten
der API tragen `assignee_deleted` immer (auch `false`); es gilt auch, wenn das
Verzeichnis die Person als gelöscht führt. Das Backup (Topf `reminders`)
trägt die Namen wie gespeichert. Einzelheiten:
[API](api.md#zuständig-an-terminen-v350).

*(v3.7.0)* Ein Termin kann zu einem Zähler gehören (Eichfrist, Zählerwechsel,
Lieferung) — optional, ohne Schema-Schritt:

| Feld | Bedeutung |
|---|---|
| `utility`, `meter_id` | Verbrauchsart und Zähler; beim Speichern muss es den Zähler geben (sonst `errors.reminder.meterUnknown`), `meter_id: null` löst die Verbindung. Gibt es den Zähler nicht mehr, erbt der Termin nichts |

Ohne eigene Zuständigkeit gilt die Person des Zählers; `GET /api/reminders`
trägt sie als `assignee_inherited` (sonst `null`).

### Zuständig an Zählern und Verträgen *(v3.7.0)*

Zähler (`meters.json`) und Verträge (`contracts.json`) tragen optional
`assignee_id` — eine Person aus dem
[Personenverzeichnis](#personenverzeichnis-peoplejson-v360), keine freien
Namen. Fehlt das Feld, ist niemand zuständig; kein Schema-Schritt, Bestand
bleibt unverändert.

| Feld | Bedeutung |
|---|---|
| `assignee_id` | Personen-ID (`u_…`). Beim Speichern muss es die Person geben und sie darf nicht gelöscht sein (sonst `errors.assignee.unknown`); eine inzwischen gelöschte Person bleibt beim unveränderten Speichern stehen. `null` oder leer = niemand |
| `assignee_former` | `true`, nachdem die Person ohne Übernahme mit „ehemalige Person“ gelöscht wurde; ersetzt die ID |

Den Namen liest die Anzeige über die ID aus dem Verzeichnis — eine gelöschte
Person steht dort weiter und erscheint als „Name (gelöscht)“; eine ID, die das
Verzeichnis nicht kennt (fremdes Backup), zeigt niemanden.

**Erben:** Ein Termin oder eine Frist ohne eigene Zuständigkeit bekommt die
des Vertrags, sonst die des Zählers, sonst niemand
([Tabelle](../anleitungen/hinweise-erledigen.md#zähler-und-verträge-v370)). Die
eigene Zuständigkeit eines Termins geht vor, auch gelöscht oder als
„ehemalige Person“.

**Person löschen:** Zähler und Verträge gehen an die löschende Person; ohne sie
bleibt die ID stehen (mit „ehemalige Person“: `assignee_former`).

**Backup:** Die Felder reisen in den Töpfen `meters`, `contracts` und
`reminders` mit. Der Import prüft die Form — `assignee_id` eine Personen-ID
oder fehlt, `assignee_former` ein Wahrheitswert —, nicht, ob es die Person
gibt: Ein Backup ohne Topf `people` behält das vorhandene Verzeichnis.

### Verlauf erledigter Hinweise (`activity.json`) *(v3.4.0)*

Was erledigt wurde, wann und von wem
([Anleitung](../anleitungen/hinweise-erledigen.md),
[API](api.md#erledigt-mit-verlauf-v340)). Eine Liste; die Datei entsteht mit
dem ersten Eintrag, fehlt sie, ist der Verlauf leer. Kein Schema-Schritt.

```json
{ "id": "act_3f9a1c2e4b5d", "action": "done",
  "subject": { "kind": "cancel_by", "uid": "cancel_by-c_strom_003",
               "occurrence": "cancel_by-c_strom_003@2026-12-31" },
  "ref": { "utility": "strom", "meter_id": "m_strom_main", "contract_id": "c_strom_003", "cancelled": true },
  "title_key": "calendar.event.cancelBy",
  "params": { "label": "Strom", "provider": "Stadtwerke Musterstadt" },
  "title": "Strom: letzter Tag für die Kündigung (Stadtwerke Musterstadt)",
  "done_on": "2026-10-12", "recorded_at": "2026-10-13T08:15:02+02:00",
  "by": { "type": "person", "id": "u_3f9a1c2e", "name": "Alex" },
  "note": "per Einschreiben", "attachment_id": "att_0123456789abcdef",
  "revert": { "type": "contract_auto_renews", "utility": "strom", "id": "c_strom_003", "before": null, "after": false } }
```

| Feld | Bedeutung |
|---|---|
| `id` | `act_` und 12 Hexziffern |
| `action` | `done` (von Hand abgehakt), `auto_done` (aus den Daten erledigt: lückenloser Folgevertrag, Ablesung, Lieferung, Nebenkostenabrechnung; seit v3.5.0 neuer Zeitraum, Versorgerrechnung), `dismissed` (Empfehlung ausgeblendet) oder seit v3.5.0 `updated` (geänderte Nebenkostenabrechnung, `subject.kind: "tenancy_statement"` — nur Nachweis, erledigt nichts) |
| `subject` | `kind` = Art wie in der Agenda (`reminder`, `cancel_by`, `term_end`, `price_increase`, `tank_reorder`, `objection_deadline`, `co2_claim_deadline`, `reading_due`, `tenancy_statement_due`) bzw. `contract` (Folgevertrag) oder `recommendation`; `uid` wie in der Agenda; `occurrence` = `uid@anker` — Vertrag: sein Ende bzw. `renewed`/`open`, Tank: letzte Lieferung, Termin: Fälligkeit, Fristen: ihr Datum. Ein Haken gilt nur für dieses Vorkommen |
| `ref` | Verweise: `utility`, `meter_id` bzw. `meter_group_id`, `contract_id`, `successor_id`, `reminder_id`, `tenancy_id`, `statement_id`, `delivery_id`, `recommendation_id`, `cancelled` (bei der Kündigung `auto_renews` auf `false` gesetzt) |
| `title_key`, `params` | Katalogschlüssel und Bausteine — der Titel lässt sich in jeder Sprache neu darstellen; Tage stehen als `JJJJ-MM-TT` darin |
| `title` | Titel als Momentaufnahme in der Sprache beim Erledigen |
| `done_on` | Tag der Tat, rückdatierbar, nicht in der Zukunft |
| `recorded_at` | Zeitpunkt des Eintrags (ISO 8601) |
| `by` | `{type: "person", id, name}`, `{type: "key", name}` (API-Schlüssel), `{type: "integration", name}` (`home_assistant`, `evcc`), `{type: "auto", reason}` (von selbst) oder `null` (ohne Anmeldung). Nach „ehemalige Person“: `{type: "person", former: true}`; *(v3.6.0)* nach dem Löschen sonst `{type: "person", id, name, deleted: true}` („Name (gelöscht)“) |
| `reason` | bei `auto_done` und `dismissed`: `{key, params}` — `activity.reason.successor`, `…reading`, `…delivery`, `…statement`, `…dismissed`; seit v3.5.0 `…period` (neuer Zeitraum), `…bill` (Versorgerrechnung erfasst), beim Abhaken von `bill_expected` `…billWithout` („kam ohne Erfassung“) |
| `assignee` | *(v3.5.0)* nur beim Erledigen eines Termins mit Zuständigkeit: `{name, id?}` als Momentaufnahme, nach „ehemalige Person“ `{former: true}`, *(v3.6.0)* nach dem Löschen `{name, id, deleted: true}`; *(v3.7.0)* auch bei Fristen und Terminen mit geerbter Zuständigkeit (Vertrag, Zähler) |
| `note`, `attachment_id` | Notiz (bis 500 Zeichen, auch leer) und optional ein Beleg ([Belege](#belege-v310), Verweisart `activity`) |
| `undone` | `{at, by}` nach Rückgängig — der Eintrag bleibt, durchgestrichen |
| `revert` | was Rückgängig zurückstellt: `reminder` (Fälligkeit, Status, `last_done`), `contract_auto_renews`, `recommendation_dismiss` — nur, solange niemand es inzwischen anders geändert hat |

**Aufbewahrung:** `activity_keep_months` (Standard 60, `0` = unbegrenzt);
aufgeräumt wird beim Schreiben, nach `done_on`.

**Backup:** Topf `activity`. Die Namen stehen darin als **Momentaufnahme** —
wer ein Backup weitergibt, gibt sie mit; die Zugänge selbst bleiben in
`auth.json`, das Personenverzeichnis ist Topf `people`. Der Import prüft `id`, `action` (eine der vier), `subject`
(`kind` und `occurrence`) und `done_on` (Kalenderdatum). Ein Backup ohne den
Topf lässt den Verlauf unverändert. Beispielhaushalte und Demo bringen seit
v3.5.0 einen Verlauf mit erfundenen Personen mit; ein Beispielhaushalt ersetzt
den vorhandenen.

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

### Personenverzeichnis (`people.json`) *(v3.6.0)*

Jede Person des Haushalts mit **fortlaufender Nummer**, die nie wieder
vergeben wird — auch gelöschte bleiben als Eintrag stehen
([Anleitung](../anleitungen/benutzer.md#personen-löschen)). Kein
Schema-Schritt: Fehlt die Datei, bekommen die Zugänge aus `auth.json` beim
ersten Lesen ihre Nummer, in der Reihenfolge `created_at`, dann `id`.

```json
{ "last_no": 2,
  "people": [
    { "id": "u_3f9a1c2e", "no": 1, "name": "Alex", "role": "admin",
      "created_at": "2026-01-15T10:00:00+01:00", "deleted_at": null, "deleted_by": null },
    { "id": "u_8b0d4f17", "no": 2, "name": "Kim", "role": "member",
      "created_at": "2026-03-02T10:00:00+01:00",
      "deleted_at": "2026-10-19T19:30:00+02:00", "deleted_by": "u_3f9a1c2e" } ] }
```

| Feld | Bedeutung |
|---|---|
| `last_no` | zuletzt vergebene Nummer; steigt nur — nicht zurück beim Löschen und nicht durch ein älteres Backup |
| `id` | die ID des Zugangs (`u_…`), wie an Terminen (`assignee_id`) und im Verlauf (`by.id`). Sie bleibt zufällig, weil Backups zwischen Installationen wandern; eine hochgezählte ID träfe dort auf eine fremde Person |
| `no` | fortlaufende Personennummer (1, 2, …), je Person einmal |
| `name`, `role` | wie im Zugang (bis 60 Zeichen, `admin`/`member`); folgen ihm bei jeder Änderung |
| `created_at` | angelegt (ISO 8601) |
| `deleted_at`, `deleted_by` | gelöscht am (ISO 8601) und ID der löschenden Person; sonst `null` |

Ein Eintrag ohne Zugang ist eine **Person ohne Anmeldung** — aus einem Backup
oder einem Beispielhaushalt. Sie steht unter „Zuständig“ zur Wahl und unter
Einstellungen → Zugriff mit „ohne Zugang“, wo sie sich löschen und seit v3.6.0
umbenennen lässt, auch ihre Rolle (dann gelten für `name` die Regeln eines
Zugangs: 1–40 Zeichen, eindeutig); ein neuer Zugang mit demselben Namen
übernimmt ihre ID und Nummer. **Backup:** Topf
`people`; der Import prüft je Eintrag `id` (`u_` und 1–32 Kleinbuchstaben
oder Ziffern) und `name`, ersetzt das Verzeichnis und behält den höheren
Zähler. Ein Backup ohne den Topf lässt das Verzeichnis unverändert.

### Posteingang *(v3.7.0)*

`inbox.json` — Versorgerpost, die im Posteingang liegt
([Anleitung](../anleitungen/posteingang.md), [API](api.md#posteingang-v370)).
Eine Liste; die Datei entsteht mit dem ersten Dokument, kein Schema-Schritt.

```jsonc
{
  "id": "inb_56ae17da0845",                  // inb_ + 12 Hexziffern
  "attachment_id": "att_5240ff1e4d3c847a",   // das PDF im Belegspeicher (kind inbox_pdf)
  "sha256": "1778…", "size": 4087, "name": "Jahresabrechnung-Erdgas-2025.pdf", "pages": 3,
  "created_at": "2026-02-05T18:42:00+01:00", "updated_at": "2026-02-05T18:44:00+01:00",
  "status": "read",            // new | queued | read | failed | done | filed
  "queued_at": null,           // nur vorgemerkt: Reihenfolge der Warteschlange
  "type": "invoice",           // Dokumentart, s. u.; type_confidence 0–1 oder null
  "extraction": {              // das geprüfte Ergebnis, null vor dem Auslesen
    "provider": "local", "model": "qwen3.8", "schema_version": 4, "type": "invoice",
    "fields": {
      "consumption": { "value": 10450, "raw": "10.450", "quote": "Verbrauch: 945,6 m³ = 10.540 kWh",
                       "page": 1, "status": "check", "reasons": ["value_not_in_quote"] }
      // Listenfelder (gezahlte Abschläge, Gas-Umrechnung, Arbeitspreise) tragen "items", je mit eigener Belegstelle
    },
    "flags": 3, "seconds": 74.2, "extracted_at": "…",
    "text": { "chars": 1180, "kept": [1, 2], "dropped": [3], "truncated": false }   // AGB-Seiten nicht gesendet
  },
  "cache": { "local|qwen3.8|4": { … } },   // Rohantworten je Anbieter, Modell und Schema-Version (höchstens drei)
  "assignment": null,          // {utility, meter_id, contract_id} — vom Nutzer gesetzt, sonst gilt die Vorbelegung
  "edits": {},                 // bestätigte oder korrigierte Feldwerte, {feld: wert}
  "applied": [],               // [{id, kind, key, ref, at}] — was übernommen wurde
  "error": null,               // {key, params, at} beim letzten Fehlschlag
  "gist": null                 // v3.7.0 (Paket betrieb): Kernaussage nach dem letzten Schreiben, s. u.
}
```

- **Kernaussage** (`gist`, additiv): Satz, Zahl und nächster Schritt
  ({key, params, figure, action, details, computed_at}, Form wie in der
  [API](api.md#posteingang-v370)). Gerechnet nach jedem Schreiben am Eintrag
  (Auslesen, Zuordnung, Korrektur, Übernehmen, Termin) und für die Liste
  gespeichert; die Vorschau rechnet sie frisch. Fehlt das Feld (ältere
  Einträge), rechnet die Liste sie beim Lesen. Ein Backup trägt sie mit, sie
  lässt sich aber jederzeit neu rechnen.
- **Überwachter Ordner** `posteingang/` im Datenverzeichnis, mit den Ablagen
  `posteingang/übernommen/` und `posteingang/fehler/` — Dateien, die dort
  liegen, gehören nicht zum Backup (übernommen sind sie als Beleg im
  Belegspeicher).

- **Arten** (`type`): `invoice`, `contract_confirmation`, `price_change`,
  `cancellation`, `supply_start`, `meter_change`, `reading_request`,
  `advance_change`; nur erkannt und abgelegt: `dunning`, `withdrawal`, `other`.
- **Feldstatus:** `ok` oder `check` mit Gründen (`empty`, `no_quote`,
  `quote_not_found`, `value_not_in_quote`, `unreadable`, `estimated`,
  `mandatory`, `price_from_invoice`, `sum_mismatch`, `inconsistent`,
  *(v3.7.0)* `invalid_format`); `flags`
  zählt die markierten Felder. *(v3.7.0)* Ohne Marke, als
  Hinweis: `missing` (Status `empty`: fehlt, kein Vorschlag braucht es),
  `estimated`, `supported`, `price_matches`, `net_price`, `vat_changed` — ein
  Feld mit diesen bleibt `ok`. `empty` heißt seither: leer, aber ein Vorschlag
  braucht es. Gespeichert wird der Stand nach dem Bestand beim Auslesen, Ändern
  und Übernehmen ([API](api.md#posteingang-v370)).
- **Netto und Steuersatz** *(v3.7.0, `schema_version` 2)*: Ein Arbeitspreis
  der Rechnung trägt `basis` (`net`/`gross`) und `vat_pct`, der Grundpreis die
  Felder `base_price_basis` und `base_price_vat_pct`, Bestätigung und
  Preisänderung `vat_pct` — Hilfsfelder ohne Marke. Erkennt die App einen
  Nettopreis sicher (Satz im Zitat), steht am Eintrag bzw. Feld
  `net: {vat_pct, gross}`; in den Vertrag geht der Bruttopreis. Einen Satz
  ohne „%“ im Zitat, den der Server beim Auslesen in der Textebene liest
  (dieselbe Textzeile, Steuerspalte oder die eine Umsatzsteuerzeile), hält
  `vat_read: {vat_pct, from: row|column|line}` fest.
- **Datum, Titel, Stände je Zähler** *(v3.7.0, `schema_version` 3)*: jede
  ausgelesene Art `document_date` und `document_title`, die Rechnung
  `meter_readings` (`items`: `meter_number`, `reading_old`,
  `reading_old_date`, `reading_new`, `reading_new_date`, `estimated`), dazu die
  Ablese-Art `reading_new_estimated`/`reading_old_estimated` (bool,
  `estimated_by: word|code`). Der Schlüssel des
  Zwischenspeichers endete damit auf `|3`; Auslesungen mit `|2` bleiben
  stehen, bis das Dokument neu ausgelesen wird.
- **Zeiträume je Zähler** *(v3.7.0, `schema_version` 4)*: Beschreibungen für
  das Modell geändert (Zählernummer ohne Fußnotenzeichen, ein Eintrag je
  Zeitraum, Nummer auch aus der Kopfzeile, „hochgerechnet“, Fußnoten und
  Codegruppen), Felder unverändert. Einträge derselben Nummer fasst die
  Prüfung zusammen; am Eintrag stehen dann `between` (`[{date, counter,
  estimated}]`), `merged` und `estimated_ends`, bei einer Nummer aus der
  Kopfzeile `number_from: header`; `estimated_by` kennt zusätzlich `mark`
  (Fußnote). Der Schlüssel des Zwischenspeichers endet seither auf `|4`;
  Auslesungen mit `|3` bleiben stehen, bis das Dokument neu ausgelesen wird.
- **Doppel:** Ein zweites Dokument mit derselben `sha256` lehnt die API mit
  `409` ab (`errors.inbox.duplicate`, der vorhandene Eintrag unter
  `detail.existing`). Inhaltsgleiche Dokumente in verschiedenen Dateien
  erkennt die Vorschau nach dem Auslesen (`duplicates`), ohne sie zu löschen.
- **Die Textebene** wird nicht gespeichert; der Browser liest sie bei jedem
  Auslesen aus dem PDF.
- **Übernehmen** legt Datensätze mit den üblichen Feldern an. Eine Ablesung
  trägt `source: "inbox"`, ein Vertrag oder eine Rechnung das Dokument in
  `attachment_ids`; `applied` macht ein zweites Übernehmen wirkungslos.
- **Haushalt aus Dokumenten** *(v3.7.0)*: Übernimmt der Entwurf
  ([API](api.md#haushalt-aus-dokumenten-v370)), trägt `applied` je Eintrag
  zusätzlich `draft: true`; ein Dokument, das der Entwurf vollständig abdeckt,
  steht auf `done`. Ein inhaltsgleiches Dokument (andere Datei, gleiche Art und
  Kernfelder) steht danach auf `filed`, die Datei bleibt; `duplicate_of` wird
  nicht gespeichert — die Liste rechnet es aus dem Inhalt.
- **Backup:** Topf `inbox`, das PDF reist mit den Belegen. Der Import prüft je
  Eintrag `id`, `sha256` und `status`.

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
| `wohnflaeche_m2` | 100 | für Effizienzklasse. *(v3.6.0)* Wie `wasser_personen_anzahl` (Standard 2) gilt der Wert für die Einordnung erst als eingetragen, wenn der Schlüssel in `settings.json` steht (auch gleich dem Standard); fehlt er, meldet `GET /api/settings` ihn unter `assumed_keys`. `PATCH` mit `null` entfernt den Schlüssel wieder |
| `gebaeudetyp`, `beheizter_keller`, `warmwasser_dezentral` | efh, false, false | *(v2.10.0 wirksam)* Gebäudenutzfläche 1,2 bzw. 1,35 × Wohnfläche, Warmwasser-Zuschlag 20 kWh/m²·a (ab 01.01.2027: 16, § 82 GModG; v3.5.0) für die energieausweis-nahe Kennzahl |
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
| `timezone` | Europe/Berlin | *(v2.7.0)* IANA-Zeitzone: „heute“, Fälligkeiten, Tagesgrenzen der Wetterdaten; seit v3.3.0 auch Jahresgrenzen und Plätze der [Viertelstundenreihen](#viertelstundenreihen-intervalsjson-v330) |
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
- legt in 1.8.0 (v3.3.0) den leeren Index der Viertelstundenreihen
  `intervals.json` an (`{series: [], samples: {}}`); der Ordner `intervals/`
  entsteht mit der ersten Reihe. Kein vorhandener Datensatz ändert sich; ein
  Erststart legt den Index ebenso an,
- hebt die Version schrittweise auf den aktuellen Stand (**1.8.0**).

Jede Stufe hat ein eigenes `needsVXXXUpgrade()` + `upgradeToVXXX()`-Paar und
ist für sich idempotent (wiederholtes Ausführen ist ein No-Op).

Die mitgelieferten Demo-Daten tragen `schema_version: 1.1.0` und werden
beim ersten Start additiv auf den aktuellen Stand (1.8.0) migriert —
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
Schema 1.8.0 und enthalten alle Töpfe aller Verbrauchsarten, auch leere — so
ersetzt ein Beispielhaushalt den ganzen Haushalt. Seit v3.3.0 bringt
`eigenheim-modern` Viertelstundenreihen für 2025 mit: Haushalt, Wärmepumpe
(gesteuert, bevorzugt 0–6 Uhr), Wallbox aus ihren Ladevorgängen, Laden und
Entladen des Speichers, seit v3.5.0 auch Erzeugung und Einspeisung — je
Viertelstunde stimmig (Erzeugung = selbst genutzt + eingespeist + geladen) —,
dazu die Börsenpreise 2025 je Viertelstunde von SMARD
(`demo-data/sources/smard-day-ahead-de-lu-2025-qh.json`, Bundesnetzagentur |
SMARD.de, CC BY 4.0) und Modul-3-Zeitfenster mit erfundenen Preisen am
Vertrag der Wärmepumpe. Seit v3.5.0 (N1026) führen alle Haushalte und das
Schaufenster den Verlauf „Erledigt“ (`activity`, erfundene Namen), Termine mit
Zuständigkeit (`assignee`, seit v3.6.0 mit `assignee_id` und dem
Personenverzeichnis `people`, je Haushalt mindestens eine Person mit der Rolle
Verwaltung, im Eigenheim klassisch eine gelöschte), erfasste Versorgerrechnungen
(`bills`) und Folgeverträge. Alle vier tragen Wohnfläche und Personen ein;
seit v3.6.0 hat `eigenheim-klassisch` dazu eigene, erfundene Vergleichswerte
(`reference_strom_kwh`, `reference_heat_kwh_m2`) und zeigt die Einordnung mit
voller Ampel. Seit v3.7.0 haben in allen Haushalten und im Schaufenster ein
Vertrag und ein Zähler eine Zuständige (`assignee_id`), Eichfristen hängen am
Zähler (`utility`, `meter_id`) und erben dessen Person. Erzeugt werden sie von
`tools/build-personas.mjs` aus einem Tagesmodell mit festem Startwert: Jeder
Lauf schreibt dieselben Dateien, `--check` vergleicht nur. Namen sind
erfunden, Marktlokations-IDs synthetisch. Beim Laden schreibt sie
`DemoDataAligner` wie das Schaufenster bis heute fort — Viertelstunden für die
letzten zwei bis drei Monate bis gestern, der Verlauf zu Terminen wandert mit
den Terminen. Ein optionaler Schlüssel `demo_align` im Demo-Backup verlangt
einen Abrechnungsstichtag relativ zu heute
(`billing_anchor_days_ago: {Art: Tage}`); er wird nicht eingespielt. Seit
v3.6.0 wandern die Jahresrechnungen dieser Art mit: Die jüngste endet am Tag
vor dem Stichtag des Vorjahres, davor eine lückenlose Kette ab der letzten
Schlussrechnung (die bleibt); Rechnungsdatum, verknüpfte Sonderzahlung und
Verlauf wandern mit, die Beträge rechnet der Import aus der eigenen Rechnung
neu. Das Docker-Image enthält sie ([API](api.md#beispielhaushalte-v320)).

**Downgrade-Schutz (v2.6.0).** Ein Downgrade wird nicht unterstützt — aber
jetzt erkannt: Ist `schema_version` der Daten **neuer** als die App, schreibt
sie nichts, alle Routen außer `/api/health` antworten `503`
(`errors.storage.dataTooNew`), und `/api/health` meldet `error`. Bis v2.5.3
stempelte eine ältere Version die neueren Daten still auf ihr Schema zurück.

---

[← API-Referenz](api.md) ·
[Tests →](../entwicklung/tests.md)
