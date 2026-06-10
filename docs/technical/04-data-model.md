# Datenmodell

**Deutsch** · [English](../en/technical/04-data-model.md)

[← API-Referenz](03-api-reference.md) · [Kompendium-Index](../README.md)

Alle Daten liegen als flache JSON-Dateien unter `data/`. Keine Datenbank.
Schreibvorgänge sind durch `LOCK_EX` serialisiert. Schema-Stand: **1.3.0**
(in `data/meta.json` und in jedem Backup).

> **Schema-Historie (Kurzfassung):** 1.0.0 utility-orientiertes Layout ·
> 1.0.3 Wasser-3-Komponenten-Verträge · 1.1.0 Fernwärme/Heizöl/Pellets +
> `reminders.json` · **1.2.0** Meter-Topologie (`parent_meter_id`,
> `meter_group_id`, `meter_groups.json` je Utility — F1006) · **1.3.0**
> Zähler-Alias `external_id` für die Home-Assistant-Anbindung (F1009).

---

## 1. Verzeichnis- und Dateilayout

```text
data/
├── meta.json                 # { schema_version, migrated_at, log[] }
├── settings.json             # 40 Schlüssel (s. u.)
├── auth.json                 # API-Token-HASH für HA-Ingest (F1009) — nie im Klartext
├── temperatures.json         # { "YYYY-MM-DD": { avg, min, max }, … }
├── reminders.json            # Termine/Wartung
├── recommendations_dismissed.json
├── gas/        { meters.json, readings.json, contracts.json, meter_groups.json }
├── strom/      { meters.json, readings.json, contracts.json, meter_groups.json }
├── wasser/     { meters.json, readings.json, contracts.json, meter_groups.json }
├── fernwaerme/ { meters.json, readings.json, contracts.json, meter_groups.json }
├── heizoel/    { meters.json, deliveries.json, contracts.json, meter_groups.json }
├── pellets/    { meters.json, deliveries.json, contracts.json, meter_groups.json }
├── pv_einspeisung/ { meters.json, readings.json, contracts.json, meter_groups.json }
├── pv_erzeugung/   { meters.json, readings.json, contracts.json, meter_groups.json }
├── logs/       # JSON-Lines-Log (N1010)
└── backups/    # Snapshots
```

Kumulative Arten (Gas, Strom, Wasser, Fernwärme, PV) haben `readings.json`;
lieferbasierte Arten (Heizöl, Pellets) haben stattdessen
`deliveries.json`. `contracts.json` existiert bei allen, ist für
Heizöl/Pellets aber typischerweise leer — dort ist die **Tankrechnung
selbst** die Kostenbasis (siehe [Heizöl](../functional/05-heizoel.md)).
`meter_groups.json` (seit 1.2.0) hält die Gruppen-Stammdaten je Utility;
die Gruppen-*Mitgliedschaft* steht dagegen am Zähler (`meter_group_id`).

> **`auth.json`** (F1009) enthält ausschließlich den **SHA-256-Hash** des
> API-Tokens, niemals den Klartext, und wird vom Backup **ausgenommen**.
> Solange die Datei fehlt/leer ist, ist die API im offenen Modus (kein Token
> erforderlich). Details: [API-Referenz → Auth](03-api-reference.md).

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

  "devices": [ Device, … ],

  // nur bei lieferbasierten Arten (Heizöl/Pellets):
  "capacity": 3000.0,
  "capacity_unit": "L",
  "initial_stock": 2400.0
}
```

**Meter-Topologie (F1006).** Ein Zähler kann **Subzähler** eines anderen sein
(`parent_meter_id`, Reihenschaltung — sein Verbrauch wird beim Elternzähler
abgezogen) und/oder **Mitglied einer Gruppe** (`meter_group_id`, fasst mehrere
Zähler fürs Dashboard zusammen). Regeln: max. eine Subzähler-Ebene (keine
Ketten/Zyklen); ein Elternzähler mit Subzählern lässt sich nicht löschen, ohne
die Zuordnung zu lösen. Siehe [Meter-Topologie](../functional/13-meter-topologie.md).

**`external_id` (F1009).** Frei vergebbarer, pro Utility eindeutiger Alias
(`[A-Za-z0-9_.-]{1,64}`) für die Home-Assistant-Anbindung. `POST /api/ingest`
akzeptiert ihn anstelle der internen ID. Default `null` = kein Alias.

### Meter-Gruppe (`meter_groups.json`, F1006)

```json
{ "id": "g_strom_ab12cd34", "name": "NT + HT Strom", "created_at": "2026-06-01" }
```

Reine Stammdaten (ID + Name). Welche Zähler dazugehören, steht **nicht** hier,
sondern als `meter_group_id` am jeweiligen Zähler (Single-Source-of-Truth).

### Device (Gerät innerhalb eines Zählers — Zählertausch)

```json
{
  "id": "d_gas_001",
  "serial": "G-2018-447",
  "installed_on": "2018-03-01",
  "initial_counter": 0.0,
  "removed_on": "2024-10-01",
  "final_counter": 1562.0,
  "reason": "Eichtausch"
}
```

Verbrauch über eine Tauschgrenze:
`(altes_final − vorheriges_reading) + (aktuelles_reading − neues_initial)`.

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

### Delivery (Brennstofflieferung — Heizöl/Pellets)

```json
{
  "id": "del_heizoel_a1", "meter_id": "m_heizoel_tank",
  "date": "2023-09-12", "quantity": 1150.0,
  "unit_price_cents": 104.5, "total_eur": 1201.75,
  "supplier": "Öl Müller GmbH", "note": "Herbstbefüllung",
  "is_planned": false
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
  "is_shadow": false, "shadow_label": null
}
```

`is_shadow: true` = hypothetischer Tarif für den Vergleich; beeinflusst
**weder** Saldo **noch** Prognose. Wasser nutzt zusätzlich ein
Drei-Komponenten-Modell (Trink-/Schmutz-/Niederschlagswasser), siehe
[Wasser](../functional/03-wasser.md).

**`special_payments` (F1003, ab v1.5.0)** — nur bei Gas/Strom/Fernwärme
(Single-Source-of-Truth: `Utilities::hasAdvancePaymentContracts()`).
`kind` ∈ {`rueckzahlung_mit`, `rueckzahlung_ohne`, `nachzahlung_mit`,
`nachzahlung_ohne`, `abschlagszahlung`}. `amount_eur` ist stets positiv;
das Vorzeichen im Saldo ergibt sich aus `kind` (Rückzahlung erhöht den
Saldo, Nach-/Abschlagszahlung senkt ihn). Nur `*_mit`-Arten tragen
`new_advance_eur` + `advance_from`; diese Punkte werden in den
effektiven Abschlagsplan gemischt. Additiv & abwärtskompatibel — fehlt
das Feld, wird es beim Normalisieren zu `[]` (kein Migrationsschritt).

---

## 3. Einstellungen (`settings.json`, 40 Schlüssel)

Gruppen (Auswahl der Default-Werte):

| Schlüssel | Default | Bedeutung |
|---|---|---|
| `gas_conversion_factor` | 11.5 | kWh je m³ Gas (Brennwert × Zustandszahl) |
| `heizoel_kwh_per_l` | 10.0 | Heizwert Heizöl EL |
| `pellets_kwh_per_kg` | 4.8 | Heizwert Holzpellets (DIN EN ISO 17225-2 A1) |
| `hdd_base_temp` | 15.0 | Heizgrenztemperatur (°C) für HGT |
| `co2_gas / _strom / _wasser` | 201 / 380 / 350 | g CO₂ je kWh bzw. m³ — *[Unverifiziert]* anpassbar |
| `co2_heizoel / _pellets / _fernwaerme` | 266 / … | dito |
| `blend_max` | 0.80 | Obergrenze Regressionsgewicht in der Prognose |
| `forecast_months` | 12 | Prognosehorizont |
| `forecast_model` | linear | Standard-Regressionsmodell |
| `segmented_split_mode` | auto | Knickpunkt der segmentierten Regression |
| `wohnflaeche_m2` | 100 | für Effizienzklasse |
| `efficiency_class_thresholds` | A+…G | Bandgrenzen kWh/m²·a |
| `billing_cycle_anchor_*` | 01-01 | Abrechnungsstichtag — gespeichert `MM-TT`, **angezeigt `TT-MM`** (v1.4.2) |
| `delivery_baseload_share` | 0.15 | wetterunabhängiger Grundlastanteil bei Lieferarten |
| `tank_warn_pct` | — | Warnschwelle Tankfüllstand |
| `active_utilities` | alle | welche Arten in Sidebar/Dashboard sichtbar |
| `location_name`, `latitude`, `longitude` | Leipzig | für Open-Meteo |

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
  (`parent_meter_id`/`meter_group_id` in 1.2.0, `external_id` in 1.3.0),
- hebt die Version schrittweise auf den aktuellen Stand (**1.3.0**).

Jede Stufe hat ein eigenes `needsVXXXUpgrade()` + `upgradeToVXXX()`-Paar und
ist für sich idempotent (wiederholtes Ausführen ist ein No-Op).

Die mitgelieferten Demo-Daten tragen `schema_version: 1.1.0` und werden
beim ersten Start additiv auf den aktuellen Stand (1.3.0) migriert —
dabei kommen `meter_groups.json` je Utility (1.2.0) und das Zähler-Feld
`external_id` (1.3.0) hinzu, ohne bestehende Werte anzutasten. Der
Migrationspfad (1.0.0 → aktuelles Schema) wird zusätzlich in der CI über
einen separaten Migrations-Smoke geprüft. Ein Downgrade wird nicht
unterstützt.

---

[← API-Referenz](03-api-reference.md) ·
[Tests →](05-testing.md)
