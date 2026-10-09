# Beispieldaten

**Deutsch** · [English](#sample-data-english)

Erfundene Verbrauchsdaten eines Einfamilienhauses in Leipzig, gedacht zum
Ausprobieren, für die Screenshots der Doku und als Grundlage der Tests. Alle acht
Verbrauchsarten sind aktiv. Seit v3.2.0 heißt dieser Haushalt im
Einrichtungsassistenten „Schaufenster“; daneben gibt es vier kleinere
[Beispielhaushalte je Persona](#beispielhaushalte-je-persona-v320).

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

## Beispielhaushalte je Persona *(v3.2.0)*

Unter [`personas/`](personas/) liegen vier Haushalte, wie sie der
Einrichtungsassistent und die öffentliche Demo zeigen — je einer zu einer
Antwort auf „Wer bist du?“
([Einrichten und Nutzungsstufen](../docs/einstieg/einrichtung.md#2-die-beispielhaushalte)):

| Datei | Haushalt | Verbrauchsarten | Besonderes |
|---|---|---|---|
| `personas/mieterin.json` | Mietwohnung, 62 m², eine Person | Strom, Heizwärme, Wasser | Heizwärme als Monatswerte der Verbrauchsinfo, Kalt- und Warmwasserzähler, Mietverhältnis mit zwei Nebenkostenabrechnungen |
| `personas/etw-fernwaerme.json` | Eigentumswohnung, 85 m², zwei Personen | Strom, Fernwärme | Fernwärmevertrag mit Leistungs- und Messpreis, Jahresrechnung 2025 |
| `personas/eigenheim-klassisch.json` | Einfamilienhaus, 140 m², vier Personen | Gas, Strom, Wasser | Gasvertragswechsel, Sonderzahlungen, Zäsur „Hydraulischer Abgleich“, Gartenzähler als Subzähler |
| `personas/eigenheim-modern.json` | Einfamilienhaus, 150 m², vier Personen | Strom, PV-Einspeisung, PV-Erzeugung, Heizwärme | Wärmepumpe mit eigenem Tarif (§ 14a EnWG, Modul 1), PV 9,8 kWp, Speicher 10 kWh, Wallbox mit Ladevorgängen aus evcc, dynamischer Tarif als Angebot |

- **Format** wie ein Export (Backup-Format 3.0) mit allen Töpfen aller
  Verbrauchsarten, auch leeren — ein Import ersetzt so den ganzen Haushalt.
- **Zeitraum:** drei Jahre bis zum Exportstand des Schaufensters; beim Laden
  schreibt die App Stände, Monatswerte und Temperaturen bis heute fort.
  Standort und Temperaturen teilen sich alle mit dem Schaufenster.
- **Erfunden:** alle Namen und Anbieter; die Marktlokations-IDs sind
  synthetisch.
- **Laden:** im Einrichtungsassistenten (Einstellungen → Allgemein →
  „Einrichtungsassistent starten“) oder mit `POST /api/demo/import` und
  `{"persona": "mieterin", "force": true}`. Das Docker-Image enthält die
  Dateien.

Die Persona-Dateien pflegt niemand von Hand: [`tools/build-personas.mjs`](../tools/build-personas.mjs)
erzeugt sie aus einem Tagesmodell mit festem Zufallsstartwert — Heizen nach
den Heizgradtagen der Demo-Temperaturen, Strom mit Jahreszeitenverlauf, PV
nach Monat und Wetter, Speicher als Tagesbilanz. Abrechnungen, Rechnungen,
Sonderzahlungen und Ladevorgänge rechnet das Skript aus denselben Tageswerten
nach, damit sie zu den Ständen passen. Jeder Lauf schreibt dieselben Dateien.

```bash
node tools/build-personas.mjs           # schreibt demo-data/personas/<id>.json
node tools/build-personas.mjs --check   # vergleicht nur, Exit 1 bei Abweichung
```

Neue Texte (Namen, Notizen, Termine) brauchen einen Eintrag in
[`translations.json`](translations.json); das Skript nennt fehlende. Der Test
`PersonaDemoTest` spielt jede Datei wie ein Backup ein und prüft, dass jeder
Zähler rechnet.

## Ändern

Die Daten des Schaufensters entstehen mit einem Generator außerhalb dieses
Repositorys (fester Zufallsstartwert, reproduzierbar). Wünsche an die Beispieldaten bitte als
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

Since v3.2.0 this household is called the “showcase” in the setup assistant.
Next to it, [`personas/`](personas/) holds four smaller sample households, one
per answer to “Who are you?”
([Setup and experience levels](../docs/en/einstieg/einrichtung.md#2-the-sample-households)):
a rented flat (electricity, heat from the monthly consumption information,
cold and hot water, tenancy with two service charge statements), an
owner-occupied flat with district heating (contract with capacity and
metering charges, 2025 bill), a classic house (gas, electricity, water with a
garden sub-meter, baseline date) and a modern house (heat pump with § 14a
module 1, 9.8 kWp PV, 10 kWh battery, wallbox with sessions from evcc). They
have the format of an export (backup format 3.0) with every pot of every
utility, so an import replaces the whole household; three years up to the
showcase’s export date, carried forward to today on loading. Load them through
the setup assistant or with `POST /api/demo/import` and
`{"persona": "mieterin", "force": true}`. Nobody edits them by hand:
[`tools/build-personas.mjs`](../tools/build-personas.mjs) generates them from a
daily model with a fixed seed (`--check` only compares, exit 1 on a
difference); new texts need an entry in `translations.json`, and
`PersonaDemoTest` imports every file like a backup.
