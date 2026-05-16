# Datenmodell

[← API-Referenz](03-api-reference.md) · [Kompendium-Index](../README.md)

Alle Daten liegen als flache JSON-Dateien unter `data/`. Keine Datenbank.
Schreibvorgänge sind durch `LOCK_EX` serialisiert. Schema-Stand: **1.1.0**
(in `data/meta.json` und in jedem Backup).

---

## 1. Verzeichnis- und Dateilayout

```
data/
├── meta.json                 # { schema_version, migrated_at, log[] }
├── settings.json             # 50 Schlüssel (s. u.)
├── temperatures.json         # { "YYYY-MM-DD": { avg, min, max }, … }
├── reminders.json            # Termine/Wartung
├── recommendations_dismissed.json
├── gas/        { meters.json, readings.json, contracts.json }
├── strom/      { meters.json, readings.json, contracts.json }
├── wasser/     { meters.json, readings.json, contracts.json }
├── fernwaerme/ { meters.json, readings.json, contracts.json }
├── heizoel/    { meters.json, deliveries.json, contracts.json }
├── pellets/    { meters.json, deliveries.json, contracts.json }
└── backups/    # Snapshots
```

Kumulative Arten (Gas, Strom, Wasser, Fernwärme) haben `readings.json`;
lieferbasierte Arten (Heizöl, Pellets) haben stattdessen
`deliveries.json`. `contracts.json` existiert bei allen, ist für
Heizöl/Pellets aber typischerweise leer — dort ist die **Tankrechnung
selbst** die Kostenbasis (siehe [Heizöl](../functional/05-heizoel.md)).

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
  "devices": [ Device, … ],

  // nur bei lieferbasierten Arten (Heizöl/Pellets):
  "capacity": 3000.0,
  "capacity_unit": "L",
  "initial_stock": 2400.0
}
```

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
  "is_shadow": false, "shadow_label": null
}
```

`is_shadow: true` = hypothetischer Tarif für den Vergleich; beeinflusst
**weder** Saldo **noch** Prognose. Wasser nutzt zusätzlich ein
Drei-Komponenten-Modell (Trink-/Schmutz-/Niederschlagswasser), siehe
[Wasser](../functional/03-wasser.md).

---

## 3. Einstellungen (`settings.json`, 50 Schlüssel)

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
- ergänzt fehlende Verzeichnisse/Dateien (z. B. neue Verbrauchsarten,
  `reminders.json`),
- legt **vor** dem ersten Schreiben einen Sicherheits-Snapshot an,
- hebt die Version schrittweise auf den aktuellen Stand (**1.1.0**).

Die Demo-Daten tragen bewusst `schema_version: 1.0.0`, damit der
Migrationspfad bei jedem Demo-Start real durchlaufen und mitgetestet
wird. Ein Downgrade wird nicht unterstützt.

---

[← API-Referenz](03-api-reference.md) ·
[Tests →](05-testing.md)
