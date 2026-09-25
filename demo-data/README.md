# Beispieldaten

**Deutsch** · [English](#sample-data-english)

Erfundene Verbrauchsdaten eines Einfamilienhauses in Leipzig, gedacht zum
Ausprobieren, für die Screenshots der Doku und als Grundlage der Tests. Alle acht
Verbrauchsarten sind aktiv.

| Verbrauchsart | Zähler | Zeitraum | Verträge | Besonderes |
|---|---|---|---|---|
| Gas | Hauptzähler | Ablesungen 01/2023 – 03/2026 | 4 und 2 Angebote für 2027 | **Zählertausch** am 01.10.2024, **Zäsur** „Dachdämmung“ am 01.08.2024, 4 Brennwert-Zeiträume, Sonderzahlungen (Rückzahlung, Abschlagszahlung) |
| Strom | Hauptzähler | 01/2023 – 03/2026 | 4 und 2 Angebote für 2027 | Anbieterwechsel, Neukundenbonus |
| Wasser | Hauptzähler und Gartenzähler | 01/2023 – 03/2026 | 4 | zwei Zähler einer Art |
| Fernwärme | ein Zähler | 01/2023 – 01/2026 | 1 | |
| Heizöl | Heizöltank Keller | Lieferungen 2023 – 2025 | — | Tankbuch mit Peilstand |
| Holzpellets | Pelletlager | Lieferungen 2023 – 2025 | — | |
| PV-Einspeisung | ein Zähler | 04/2023 – 05/2026 | 1 (Einspeisevergütung) | |
| PV-Erzeugung | ein Zähler | 04/2023 – 05/2026 | — | Autarkie und Eigenverbrauch |

Dazu Tagestemperaturen für Leipzig vom 01.01.2023 bis 30.06.2026 und sechs
Termine. Die Anbieter- und Namen sind Beispiele; es sind keine echten Verträge.

## Verwenden

- **In der App:** auf der leeren Übersicht „Mit Beispieldaten ausprobieren“, sonst
  Einstellungen → Daten → „Demo-Daten laden“. Vorher legt die App einen
  Snapshot des jetzigen Stands an; zurück geht es über Einstellungen → Daten →
  Gespeicherte Snapshots.
- **Als Backup-Datei:** [`energietracker-demo-backup.json`](energietracker-demo-backup.json)
  über Einstellungen → Daten → „Backup importieren…“.
- **Nebenher, ohne die eigenen Daten anzufassen:** eine Kopie als eigenes
  Datenverzeichnis starten —

  ```bash
  cp -R demo-data /tmp/etdata
  ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8080 router.php
  ```

Die Dateien tragen das Schema 1.1.0; beim ersten Start hebt der Migrator sie auf
den aktuellen Stand — das ist Absicht und prüft nebenbei die Migration.

## Ändern

Die Daten entstehen mit einem Generator außerhalb dieses Repositorys (fester
Zufallsstartwert, reproduzierbar). Wünsche an die Beispieldaten bitte als
[Issue](https://github.com/Bingerminger/energietracker/issues). Die Tests
verlassen sich auf einzelne Werte; nach einer Änderung laufen Shape- und
Render-Tests gegen eine Kopie ([Tests](../tests/README.md)).

---

## Sample data (English)

Invented consumption data of a detached house in Leipzig, meant for trying the
app out, for the screenshots in the docs and as the basis of the tests. All
eight utilities are active: gas (meter swap on 1 Oct 2024, baseline date
"roof insulation" on 1 Aug 2024, four calorific-value periods, special
payments), electricity (switch of provider, sign-up bonus), water (main and
garden meter), district heating, heating oil and pellets (deliveries, tank
book), PV feed-in and generation — readings from 01/2023 to 03/2026 (PV to
05/2026), temperatures from 1 Jan 2023 to 30 Jun 2026, six reminders.

Use it in the app ("Try with sample data" on the empty overview, otherwise
Settings → Data → "Load demo data" — the app saves a snapshot first), import
[`energietracker-demo-backup.json`](energietracker-demo-backup.json) as a
backup, or run a copy as a data directory of its own
(`ET_DATA_DIR=/tmp/etdata php -S 127.0.0.1:8080 router.php`). The files carry
schema 1.1.0 on purpose; the migrator upgrades them on the first start.
