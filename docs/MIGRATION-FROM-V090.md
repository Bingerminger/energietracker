# Migration aus v0.9.0

v0.9.0 ist eine private Vorgängerversion des Energietrackers. Sie
liegt nicht in der öffentlichen Codebase, ihr Backup-Format wird aber
von v1.0.2 unterstützt, damit private Bestandsdaten ohne Datenverlust
in die öffentliche Codebase überführt werden können.

Diese Anleitung beschreibt den vollständigen Migrationspfad.

---

## TL;DR

1. In v0.9.0: JSON-Backup exportieren.
2. In v1.0.2: **Einstellungen → Backup & Restore → 📦 Migration aus v0.9.0**.
3. Modus wählen (*Ersetzen* oder *Zusammenführen*).
4. Importieren.

Vor jedem Schreibvorgang wird automatisch ein Sicherheits-Snapshot
der aktuellen v1.0.2-Daten in `data/backups/` abgelegt.

---

## 1. Backup-Format-Versionen

Der Migrator akzeptiert Backups, die folgende Felder enthalten:

| Backup-Feld    | Wert                                       | Bedeutung |
|---             |---                                         |---        |
| `version`      | `"1.0"`, `"2.0"`, `"2.1"`                  | v0.9.0-Format |
| `backup_version` | `"3.0"` oder höher                       | natives v1.0.2-Backup (nicht hier, sondern unter „Backup importieren") |

Ein typisches v0.9.0-Backup sieht so aus:

```json
{
  "created_at": "2026-05-11T11:43:27+02:00",
  "version": "2.1",
  "gas":   [ { "id": "...", "date": "2020-12-24", "counter": 37308, "is_notable": false, "comment": null, "is_future": false }, ... ],
  "strom": [ ... ],
  "temperatures": { "YYYY-MM-DD": {"avg": ..., "min": ..., "max": ...}, ... },
  "settings":  { "gas_conversion_factor": 11.46, "hdd_base_temp": 15, ... },
  "contracts": { "gas": [ ... ], "strom": [ ... ] }
}
```

Wichtig: das Wurzelfeld heißt `version`, nicht `backup_version`. Falls
beide vorhanden sind oder das Feld fehlt, wird der Import mit
HTTP 400 abgelehnt.

---

## 2. Feld-Mapping

### 2.1 Settings

Die v0.9.0-Settings sind eine Teilmenge der v1.0.2-Settings. Der
Migrator legt v1.0.2-Defaults für alle Schlüssel an und überschreibt
sie mit den v0.9.0-Werten, sofern vorhanden. Folgende Schlüssel
existieren *nur* in v1.0.2 und werden mit Defaults gefüllt:

| Neuer Key in v1.0.2          | Default      | Bedeutung |
|---                           |---           |---        |
| `co2_wasser`                 | `350` g/m³   | CO₂-Faktor für Wasser |
| `wasser_personen_anzahl`     | `2`          | Personen im Haushalt |
| `wasser_personen_referenz`   | `127` L/d/P  | Pro-Kopf-Referenzverbrauch |

Das interne v0.9.0-Settings-Feld `version` (Schema-Version, nicht
App-Version) wird vor dem Merge entfernt.

### 2.2 Readings

v0.9.0-Format:

```json
{
  "id": "20260507-fba154af",
  "date": "2020-12-24",
  "counter": 37308,
  "price_cents": null,
  "is_notable": false,
  "comment": null,
  "is_future": false
}
```

v1.0.2-Format nach Migration:

```json
{
  "id": "20260507-fba154af",
  "meter_id": "m_gas_main",
  "device_id": "d_gas_001",
  "date": "2020-12-24",
  "counter": 37308.0,
  "price_cents": null,
  "note": "",
  "is_estimated": false,
  "is_future": false
}
```

Transformationen im Detail:

- `id` wird übernommen (für Nachvollziehbarkeit).
- `meter_id` wird auf den synthetischen Default-Meter gesetzt
  (`m_<utility>_main`).
- `device_id` wird auf das synthetische Default-Device gesetzt
  (`d_<utility>_001`).
- `counter` wird zu `float` gecastet.
- **`comment` → `note`** (Feld-Rename).
- `is_notable: true` wird nicht zum Schema-Feld in v1.0.2 — als
  Erhalt-Mechanismus erhält die `note` einen **⭐-Präfix**
  (z.B. `"⭐ wichtige Ablesung"` oder einfach `"⭐"` wenn der Kommentar
  leer war). So bleibt die Information im sichtbaren Notizfeld erhalten.
- `is_future` wird übernommen.
- `is_estimated` ist neu in v1.0.2 und wird auf `false` gesetzt.

### 2.3 Default-Meter und Default-Device

v0.9.0 kannte weder Zähler noch Geräte. Der Migrator erzeugt
deshalb pro Utility einen synthetischen Default-Zähler:

```json
{
  "id":   "m_<utility>_main",
  "name": "Hauptzähler",
  "icon": "🔥",      // bzw. ⚡ / 💧
  "created_at": "<jetzt>",
  "active": true,
  "notes": "Aus v0.9.0-Migration angelegt",
  "devices": [{
    "id":              "d_<utility>_001",
    "serial":          "",
    "installed_on":    "<frühestes Ablesedatum>",
    "initial_counter": 0.0,
    "removed_on":      null,
    "final_counter":   null,
    "reason":          ""
  }]
}
```

`installed_on` wird auf das früheste Lesedatum der jeweiligen Utility
gesetzt. Die Seriennummer bleibt leer und kann nach dem Import
nachgepflegt werden.

### 2.4 Contracts

v0.9.0-Format:

```json
{
  "id": "20260507-c41bed0e",
  "provider": "Grünwelt",
  "tariff_name": "grüngas classic",
  "start": "2020-09-25",
  "end":   "2021-09-24",
  "notes": "140015461407-01-2",
  "advance_payments": [ {"from": "2020-09-25", "amount_eur": 250} ],
  "working_prices":   [ {"from": "2020-09-25", "ct_per_kwh": 4.22} ],
  "base_prices":      [ {"from": "2020-09-25", "eur_per_month": 7.86} ],
  "bonuses": []
}
```

v1.0.2 ergänzt lediglich `meter_id` (auf den Default-Meter) — sonst
identisch.

### 2.5 Temperaturen

Schema identisch — eins-zu-eins kopiert.

```json
{
  "YYYY-MM-DD": {"avg": 4.6, "min": 0.2, "max": 11.2},
  ...
}
```

### 2.6 Wasser

v0.9.0 kennt kein Wasser. Der Migrator legt einen leeren Wasser-Bereich
an (Default-Meter und Default-Device, keine Readings, keine Verträge).

---

## 3. Zählerwechsel-Erkennung

v0.9.0 modellierte Zählerwechsel nicht als eigenständige Entität,
sondern als gewöhnliche Ablesung mit `is_notable: true` und einem
Kommentar wie `"Zählerwechsel"`. v1.0.2 hingegen kennt Geräte (Devices)
mit explizitem Ein-/Ausbaudatum und End-/Anfangsstand. Eine
automatische Heuristik kann die Lücke nicht zuverlässig schließen,
daher das Hybridvorgehen:

1. Der Migrator **importiert stumpf**: alle Ablesungen werden als
   normale Readings auf dem Default-Device angelegt.
2. **Im Preview-Report** zeigt der Migrator eine Liste von
   *Zählerwechsel-Kandidaten*: alle Readings, deren Kommentar
   die Schlüsselwörter `Zählerwechsel`, `Zaehlerwechsel`, `Tausch`,
   `Austausch` oder `neuer Zähler` enthält (case-insensitive,
   Umlaut-tolerant).
3. **Nach dem Import** kann der Anwender diese Kandidaten manuell als
   Device-Replacement nachmodellieren — entweder in der UI unter
   *Verbrauch → Gas → ⚙️ Zähler → Zählertausch*, oder per API:

```http
POST /api/utility/gas/meters/m_gas_main/replace-device
Content-Type: application/json

{
  "removed_on":       "2020-07-22",
  "final_counter":    99999.0,
  "reason":           "Eichfrist abgelaufen",
  "new_device": {
    "serial":          "GAS-2020-XYZ123",
    "installed_on":    "2020-07-22",
    "initial_counter": 0.0
  }
}
```

Ohne diese Nachmodellierung berechnet v1.0.2 den Verbrauch zwischen
Ablesung *n−1* (alter Zähler hoher Stand) und Ablesung *n* (neuer
Zähler niedriger Stand) als `counter_n − counter_(n−1)` und erhält
einen negativen Wert, den `ConsumptionService::forMeter` als ungültig
verwirft (überspringt den Monat). Das ist konservativ korrekt, aber
unschön — daher die Empfehlung, die Kandidaten zeitnah nachzupflegen.

---

## 4. Modi: Ersetzen vs. Zusammenführen

### Ersetzen

- Alle bestehenden v1.0.2-Daten werden komplett überschrieben:
  `meta.json`, `settings.json`, `temperatures.json` und alle drei
  Utility-Unterordner.
- **Vor dem Schreiben** wird automatisch ein Snapshot der aktuellen
  Daten unter `data/backups/backup_YYYY-MM-DD_HHMMSS.json` abgelegt.
- Das ist der Standard- und der gewünschte Pfad nach einer frischen
  v1.0.2-Installation.

### Zusammenführen

- Bestehende v1.0.2-Daten bleiben unangetastet.
- Hinzugefügt werden nur Einträge, deren `id` noch nicht in der
  bestehenden Datei existiert. Schon vorhandene IDs werden
  übersprungen, nicht überschrieben.
- Temperaturtage werden nur ergänzt, wenn das Datum noch nicht
  belegt ist. Bei Settings gewinnen die bestehenden Werte;
  fehlende Schlüssel werden aus dem Backup ergänzt.
- Vor dem Schreiben wird ebenfalls ein Sicherheits-Snapshot abgelegt.

In beiden Modi liefert die API einen detaillierten Bericht
(`written` pro Utility, im Merge-Modus zusätzlich `skipped`).

---

## 5. API-Aufruf direkt (ohne UI)

Wer skriptbasiert importieren will, kann die beiden REST-Endpoints
direkt ansprechen:

### Schritt 1: Preview

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"backup": <inhalt-deines-v090-backups>}' \
  http://localhost/api.php/api/migration/v09/preview
```

Antwort:

```json
{
  "success": true,
  "data": {
    "ok": true,
    "legacy_version": "2.1",
    "translated": { "meta": {...}, "settings": {...}, "temperatures": {...}, "utilities": { "gas": {...}, "strom": {...}, "wasser": {...} } },
    "report": {
      "readings": { "gas": 52, "strom": 22, "wasser": 0 },
      "contracts": { "gas": 8, "strom": 4, "wasser": 0 },
      "temperatures": 1131,
      "settings": 20,
      "warnings": [ "v0.9.0 kennt kein Wasser — der Wasser-Utility-Bereich wird leer angelegt." ],
      "device_replacement_candidates": [
        { "utility": "strom", "reading_id": "...", "date": "2020-07-22", "counter": 6, "comment": "Zählerwechsel", "reason": "Schlüsselwort im Kommentar erkannt" }
      ]
    }
  }
}
```

### Schritt 2: Import

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"translated": <preview.data.translated>, "mode": "replace"}' \
  http://localhost/api.php/api/migration/v09/import
```

Antwort:

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

Im `merge`-Modus enthält jedes Utility zusätzlich `skipped`
(Anzahl übersprungener Einträge wegen ID-Kollision).

---

## 6. Fehlerbilder

| Symptom | Wahrscheinliche Ursache | Lösung |
|---|---|---|
| `Kein "version"-Feld im Backup` | Falsches Format (z.B. natives v1.0.2-Backup unter „Migration" hochgeladen) | Den normalen Importpfad nutzen, nicht den Migrator |
| `Backup-Version "X" wird nicht erkannt` | Backup ist neuer als v0.9.0 oder ein anderes Tool | Wenn `backup_version: "3.0"` → unter *Backup importieren*; sonst Migrator-Code ergänzen |
| Nach Import: ein Monat fehlt im Verbrauch | Negative Konsumption durch nicht-modellierten Zählerwechsel | Im Preview-Report nachschauen, Device-Replacement nachpflegen (siehe Abschnitt 3) |
| Saldo eines alten Vertrags ist nicht 0 | v0.9.0 hatte keine Boni → in v1.0.2 als 0 importiert | Falls Bonus existierte: manuell unter *Verträge → Bonus hinzufügen* nachtragen |
| Temperaturen fehlen für ein Datum | Im Merge-Modus wurden bestehende Tage nicht überschrieben | Im Replace-Modus wiederholen, oder bestehende Daten löschen und neu mergen |

---

## 7. Rollback

Im Datenverzeichnis liegt unter `data/backups/` der automatisch vor
dem Import erzeugte Snapshot. Er hat exakt das `backup_version: "3.0"`-
Format und kann jederzeit über *Einstellungen → Backup importieren*
zurückgespielt werden.

Im Dateisystem (z.B. via SSH) lässt sich der Snapshot manuell
entpacken, indem man den Inhalt unter `data/<utility>/*.json`
zurückschreibt — der Snapshot ist menschenlesbar und folgt der gleichen
Struktur wie das Live-Datenverzeichnis.
