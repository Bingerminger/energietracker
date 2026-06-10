# Migration from v0.9.0

**English** · [Deutsch](../MIGRATION-FROM-V090.md)

v0.9.0 is a private predecessor version of Energietracker. It is not part of the
public codebase, but its backup format is supported by v1.0.2 so that private
legacy data can be transferred into the public codebase without data loss.

This guide describes the complete migration path.

---

## TL;DR

1. In v0.9.0: export a JSON backup.
2. In v1.0.2: **Settings → Backup & restore → 📦 Migration from v0.9.0**.
3. Choose a mode (*Replace* or *Merge*).
4. Import.

Before each write, a safety snapshot of the current v1.0.2 data is stored
automatically in `data/backups/`.

---

## 1. Backup format versions

The migrator accepts backups that contain the following fields:

| Backup field    | Value                                       | Meaning |
|---              |---                                          |---      |
| `version`       | `"1.0"`, `"2.0"`, `"2.1"`                   | v0.9.0 format |
| `backup_version`| `"3.0"` or higher                          | native v1.0.2 backup (not here, but under "Import backup") |

A typical v0.9.0 backup looks like this:

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

Important: the root field is called `version`, not `backup_version`. If both are
present or the field is missing, the import is rejected with HTTP 400.

---

## 2. Field mapping

### 2.1 Settings

The v0.9.0 settings are a subset of the v1.0.2 settings. The migrator creates
v1.0.2 defaults for all keys and overrides them with the v0.9.0 values where
present. The following keys exist *only* in v1.0.2 and are filled with defaults:

| New key in v1.0.2            | Default      | Meaning |
|---                           |---           |---      |
| `co2_wasser`                 | `350` g/m³   | CO₂ factor for water |
| `wasser_personen_anzahl`     | `2`          | People in the household |
| `wasser_personen_referenz`   | `127` L/d/P  | Per-capita reference consumption |

The internal v0.9.0 settings field `version` (schema version, not app version) is
removed before the merge.

### 2.2 Readings

v0.9.0 format:

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

v1.0.2 format after migration:

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

Transformations in detail:

- `id` is kept (for traceability).
- `meter_id` is set to the synthetic default meter (`m_<utility>_main`).
- `device_id` is set to the synthetic default device (`d_<utility>_001`).
- `counter` is cast to `float`.
- **`comment` → `note`** (field rename).
- `is_notable: true` does not become a schema field in v1.0.2 — as a preservation
  mechanism the `note` receives a **⭐ prefix** (e.g. `"⭐ important reading"`, or
  simply `"⭐"` if the comment was empty). This way the information is preserved in
  the visible note field.
- `is_future` is kept.
- `is_estimated` is new in v1.0.2 and is set to `false`.

### 2.3 Default meter and default device

v0.9.0 knew neither meters nor devices. The migrator therefore creates a
synthetic default meter per utility:

```json
{
  "id":   "m_<utility>_main",
  "name": "Main meter",
  "icon": "🔥",      // resp. ⚡ / 💧
  "created_at": "<now>",
  "active": true,
  "notes": "Created from v0.9.0 migration",
  "devices": [{
    "id":              "d_<utility>_001",
    "serial":          "",
    "installed_on":    "<earliest reading date>",
    "initial_counter": 0.0,
    "removed_on":      null,
    "final_counter":   null,
    "reason":          ""
  }]
}
```

`installed_on` is set to the earliest reading date of the respective utility. The
serial number stays empty and can be filled in after the import.

### 2.4 Contracts

v0.9.0 format:

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

v1.0.2 merely adds `meter_id` (to the default meter) — otherwise identical.

### 2.5 Temperatures

Schema identical — copied one-to-one.

```json
{
  "YYYY-MM-DD": {"avg": 4.6, "min": 0.2, "max": 11.2},
  ...
}
```

### 2.6 Water

v0.9.0 has no water. The migrator creates an empty water section (default meter
and default device, no readings, no contracts).

---

## 3. Meter-swap detection

v0.9.0 did not model meter swaps as a separate entity, but as an ordinary reading
with `is_notable: true` and a comment like `"Zählerwechsel"`. v1.0.2, by contrast,
knows devices with an explicit installation/removal date and final/initial
reading. An automatic heuristic cannot reliably close the gap, hence the hybrid
approach:

1. The migrator **imports bluntly**: all readings are created as normal readings
   on the default device.
2. **In the preview report** the migrator shows a list of *meter-swap
   candidates*: all readings whose comment contains the keywords `Zählerwechsel`,
   `Zaehlerwechsel`, `Tausch`, `Austausch` or `neuer Zähler` (case-insensitive,
   umlaut-tolerant — these are the literal German keywords the code looks for).
3. **After the import** you can manually re-model these candidates as a device
   replacement — either in the UI under
   *Consumption → Gas → ⚙️ Meters → Meter swap*, or via the API:

```http
POST /api/utility/gas/meters/m_gas_main/replace-device
Content-Type: application/json

{
  "removed_on":       "2020-07-22",
  "final_counter":    99999.0,
  "reason":           "Calibration period expired",
  "new_device": {
    "serial":          "GAS-2020-XYZ123",
    "installed_on":    "2020-07-22",
    "initial_counter": 0.0
  }
}
```

Without this re-modelling, v1.0.2 computes the consumption between reading *n−1*
(old meter, high value) and reading *n* (new meter, low value) as
`counter_n − counter_(n−1)` and gets a negative value, which
`ConsumptionService::forMeter` discards as invalid (skips the month). That is
conservatively correct but ugly — hence the recommendation to fill in the
candidates promptly.

---

## 4. Modes: Replace vs. Merge

### Replace

- All existing v1.0.2 data is completely overwritten: `meta.json`,
  `settings.json`, `temperatures.json` and all three utility subfolders.
- **Before writing**, a snapshot of the current data is automatically stored
  under `data/backups/backup_YYYY-MM-DD_HHMMSS.json`.
- This is the default and the desired path after a fresh v1.0.2 installation.

### Merge

- Existing v1.0.2 data is left untouched.
- Only entries whose `id` does not yet exist in the existing file are added. IDs
  already present are skipped, not overwritten.
- Temperature days are only added if the date is not yet occupied. For settings,
  the existing values win; missing keys are added from the backup.
- A safety snapshot is stored before writing here as well.

In both modes the API returns a detailed report (`written` per utility, plus
`skipped` in merge mode).

---

## 5. Direct API call (without the UI)

If you want to import via a script, you can address the two REST endpoints
directly:

### Step 1: Preview

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"backup": <content-of-your-v090-backup>}' \
  http://localhost/api.php/api/migration/v09/preview
```

Response:

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
      "warnings": [ "v0.9.0 has no water — the water utility section is created empty." ],
      "device_replacement_candidates": [
        { "utility": "strom", "reading_id": "...", "date": "2020-07-22", "counter": 6, "comment": "Zählerwechsel", "reason": "Keyword detected in the comment" }
      ]
    }
  }
}
```

### Step 2: Import

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"translated": <preview.data.translated>, "mode": "replace"}' \
  http://localhost/api.php/api/migration/v09/import
```

Response:

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

In `merge` mode each utility additionally contains `skipped` (number of entries
skipped due to an ID collision).

---

## 6. Error scenarios

| Symptom | Likely cause | Fix |
|---|---|---|
| `No "version" field in the backup` | Wrong format (e.g. a native v1.0.2 backup uploaded under "Migration") | Use the normal import path, not the migrator |
| `Backup version "X" is not recognised` | Backup is newer than v0.9.0 or from another tool | If `backup_version: "3.0"` → under *Import backup*; otherwise extend the migrator code |
| After import: a month is missing in consumption | Negative consumption from a non-modelled meter swap | Check the preview report, fill in the device replacement (see section 3) |
| The balance of an old contract is not 0 | v0.9.0 had no bonuses → imported as 0 in v1.0.2 | If a bonus existed: add it manually under *Contracts → Add bonus* |
| Temperatures missing for a date | In merge mode, existing days were not overwritten | Repeat in replace mode, or delete existing data and merge again |

---

## 7. Rollback

In the data directory, under `data/backups/`, lies the snapshot created
automatically before the import. It has exactly the `backup_version: "3.0"`
format and can be restored at any time via *Settings → Import backup*.

In the file system (e.g. via SSH) the snapshot can be unpacked manually by
writing the content back under `data/<utility>/*.json` — the snapshot is
human-readable and follows the same structure as the live data directory.
