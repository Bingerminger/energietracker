# Energietracker — Roadmap

> Lebendiges Planungs-Dokument. Wird mit jedem Release fortgeschrieben.
> Bei Konflikt zwischen Roadmap-Reihenfolge und akutem User-Bedarf
> (z. B. kritischer Bug) gewinnt der Bedarf, und die Roadmap rückt nach.

**Stand:** 2026-05-23 (synchron mit v1.7.1, N1004 ausgeliefert)
**Aktuelle Baseline:** v1.7.1
**Schema:** 1.1.0

---

## Code-Konventionen

Zwei parallele Nummern-Reihen:

- **F-Codes** (`F1003`, `F1004`, …): größere fachliche Features.
- **N-Codes** (`N1001`, `N1002`, …): nicht-funktionale Anforderungen
  (Testbarkeit, Deployment, Edge-Case-Robustheit, Performance, Sicherheit,
  i18n-Foundation, A11y, Mobile/PWA).

Patch-Releases (reine Bugfixes, UX-Politur, Doku) erhalten **keinen Code**.
Ein Release kann F-Code, N-Code, beide oder keinen davon haben.

Pre-public-Releases (v1.0.0 – v1.4.x) trugen teilweise noch 1-stellige
F-Codes (`F1`, `F2`, …) — diese Reihe ist mit `F1003` (v1.5.0) auf
4-stellige Nummerierung umgestellt.

---

## Bereits ausgeliefert

| Code | Thema | Release | Datum |
|------|-------|---------|-------|
| F1003 | Sonderzahlungen (5 Arten) für Gas/Strom/Fernwärme | v1.5.0 | 2026-05-17 |
| F1004 | Zentrale Zählerstand-Erfassung (`#/zaehlerstaende`) | v1.6.0 | 2026-05-18 |
| N1001 | PHPUnit-Foundation + Unit-Tests `ConsumptionService` / `MeterService` / `AnomalyService` | v1.6.2 | 2026-05-22 |
| N1002 | Edge-Case-Test-Suite (10 Fälle: Überlauf, lange Lücke, doppelte Daten, negativ, Schaltjahr, DST, leer, Vertragswechsel, Wasser ohne SW/NW, Import vor installed_on) | v1.6.3 | 2026-05-22 |
| F1005 | PV-Einspeisung + PV-Erzeugung, Strom-Saldo, Autarkiequote (8 Utilities statt 6, CO₂ als „vermieden") | v1.7.0 | 2026-05-23 |
| N1003 | Health-Check-Endpoint `GET /api/health` (Version, Schema, Schreibrechte, Migrationen) | v1.7.0 | 2026-05-23 |
| N1004 | Backup/Restore-Sicherungen: Schema-Guard + Auto-Snapshot vor Restore + UI-Toast mit Snapshot-Name. Demo-Daten und Szenario-Doku für PV nachgereicht. | v1.7.1 | 2026-05-23 |

---

## Geplante Reihenfolge (logisch sortiert)

Logik: **NFRs vor riskanten Refactors**. `F1006` (Meter-Topologie) berührt
`ConsumptionService` substanziell — vorher muss eine echte Unit-Test-Suite
existieren, sonst lassen sich Regressionen nicht zuverlässig vermeiden.
Außerdem: **Backup vor Schema-Migration**. F1006 hebt Schema 1.1.0 → 1.2.0;
ein UI-seitiges Backup/Restore davor erspart Datenverluste.

| Code | Thema | Release | Größe | Status |
|------|-------|---------|-------|--------|
| **N1005** | Docker-Image + `docker-compose.yml` | v1.7.2 | M | inline |
| **F1006** | Meter-Topologie (Subzähler + Gruppe) | v1.8.0 | M | Detail-Konzept offen |
| TBD | offen für weitere Issues / Bedarfe | v1.9.0+ | — | offen |

---

## (ausgeliefert) v1.7.0 — F1005 PV + N1003 Health-Check

→ Details in [CHANGELOG.md](CHANGELOG.md#170--2026-05-23) und
[`docs/functional/12-pv.md`](docs/functional/12-pv.md). Der ursprüngliche
Roadmap-Eintrag (Skizze) bleibt unten als historischer Kontext stehen,
damit die Konzept-Wegstrecke nachvollziehbar bleibt.

---

## v1.7.0 (Original-Skizze, vor Implementierung) — F1005 PV-Einspeisezähler + N1003 Health-Check

**Issue:** [#12](https://github.com/Bingerminger/energietracker/issues/12)
(Teil 3)
**Größe F1005:** S — isoliert.
**Größe N1003:** XS — wird mitgeliefert.

### F1005 Ziel
Zähler können Ertrag (Einspeisung) statt Verbrauch erfassen. Saldo dreht
sich: aus Kosten werden Gutschriften.

### F1005 Skizze
- Neuer Flag pro Meter: `direction: 'consumption' | 'feed_in'`
  (Default `'consumption'`).
- Vorzeichen-Konvention in `ConsumptionService`: bei `feed_in` wird der
  berechnete „Verbrauch" als **Ertrag** interpretiert; Saldo = Ertrag ×
  Einspeisevergütung − …
- Vertragsmodell: `working_prices` werden zu Einspeisevergütungen
  (ct/kWh, zeitlich gestaffelt). Bestehende Felder bleiben benutzt —
  nur die Interpretation ist eine andere; kein neues Schemafeld am
  Contract.
- Dashboard: separates KPI „Einspeisung" mit eigener Farbe.

### F1005 Offene Detail-Entscheidungen
- Eigener Utility-Eintrag (`pv_feed_in`) oder Flag am bestehenden
  Strom-Zähler?
- CO₂-Behandlung: Einspeisung als negative Bilanz oder neutral?
- Saldo-Aggregation: PV-Ertrag und Strom-Verbrauch in einer Gesamtsicht
  („Stromsaldo") zusammenführen, oder getrennt darstellen?

### N1003 Skizze
Neuer Endpunkt `GET /api/health`. Antwortet mit:
```json
{"success": true, "data": {
  "version": "1.7.0",
  "schema_version": "1.1.0",
  "data_dir_writable": true,
  "migrations_pending": 0,
  "uptime_seconds": 12345
}}
```
Hilft beim Monitoring / Synology-Healthcheck und bei der Diagnose, wenn
ein User „bei mir geht nichts" meldet.

### Schema-Migration
Schema bleibt **1.1.0** (additives Feld `direction` mit Default — kein
Bruch).

---

## (ausgeliefert) v1.7.1 — N1004 Backup/Restore-Sicherungen + Demo/Doku-Nachreichung

→ Details in [CHANGELOG.md](CHANGELOG.md#171--2026-05-23). Auslieferung
fiel kleiner aus als die ursprüngliche Skizze, weil der bestehende
`BackupService` bereits einen vollständigen JSON-Export/-Import hatte
— N1004 hat nur die fehlenden Sicherungen ergänzt (Schema-Guard,
Auto-Snapshot vor Restore) und die UI-Erfahrung präzisiert. Der
gewonnene Headroom wurde genutzt, um die Demo-Daten und Szenario-Doku
für PV nachzureichen (Versäumnis aus v1.7.0).

---

## v1.7.1 (Original-Skizze, vor Implementierung) — N1004 Backup / Restore im UI

**Auslöser:** Vor der Schema-Migration in F1006 sollte der User ein
Snapshot-Backup ziehen können, ohne ins Dateisystem zu müssen.

### Ziel
- `GET /api/backup` → ZIP-Stream mit dem gesamten Inhalt von `data/`.
- `POST /api/restore` → ZIP-Upload, prüft Schema-Version, ersetzt nach
  Bestätigung das Datenverzeichnis (mit automatischem Sicherungs-Snapshot
  unter `data/backups/<timestamp>/`).
- UI: neuer Menüpunkt unter „Einstellungen / Daten" mit Download- und
  Upload-Button und einer expliziten Warnung beim Restore.

### Skizze
- `BackupService` neu, nutzt `ZipArchive` aus PHP-Standard.
- Restore prüft `meta.json.schema_version` ≤ aktuelle App-Schema-Version;
  bei höherer Version Abbruch mit Hinweis.
- Vor dem Restore wird automatisch der aktuelle Stand als
  `data/backups/restore-before-<ts>.zip` weggeschrieben.

### Schema-Migration
Keine.

---

## v1.7.2 — N1005 Docker-Image

**Auslöser:** Self-hosted ja, aber bisher ausschließlich auf Synology
mit individueller nginx-Konfiguration. Ein Docker-Image macht das Tool
für andere Hosts und für Tests reproduzierbar.

### Ziel
- `Dockerfile` (Multi-Stage, Basis `php:8.4-fpm-alpine` + nginx).
- `docker-compose.yml` mit Volume-Mount für `./data`.
- README-Sektion „Docker-Quickstart".
- CI-Job: Image bauen und smoke-testen (gegen Demo-Daten).
- Image-Publikation auf GHCR (`ghcr.io/bingerminger/energietracker:1.7.2`).

### Schema-Migration
Keine.

---

## v1.8.0 — F1006 Meter-Topologie

**Issue:** [#12](https://github.com/Bingerminger/energietracker/issues/12)
(Teil 1 + Teil 2, gebündelt)
**Größe:** M
**Voraussetzung:** N1001 + N1002 abgeschlossen, N1004 ausgeliefert
(Schema-Migration mit Backup-Möglichkeit).

### Ziel
Zähler können in zwei neuen Beziehungen zueinander stehen:

1. **Reihenschaltung / Subzähler** (z. B. Wärmepumpe hinter
   Haushaltsstrom): Verbrauch des Subzählers wird vom Elternzähler
   abgezogen.
2. **Gruppierung** (z. B. NT + HT Strom, mehrere Wallboxen): Verbräuche
   mehrerer Zähler werden für Dashboard und Vertragsauswertung zu einer
   Gruppe summiert.

### Skizze
- Neue optionale Meter-Felder: `parent_meter_id` (Subzähler) und
  `meter_group_id` (Gruppen-Mitgliedschaft).
- `ConsumptionService` erhält neue Aggregations-Logik:
  - Eltern-Netto-Verbrauch = Eigen-Stand − Σ Subzähler-Verbräuche
  - Gruppen-Verbrauch = Σ Mitglieder-Verbräuche
- Verträge können einen Einzelmeter ODER eine `meter_group_id`
  referenzieren.
- Dashboard: Gruppen erscheinen als ein Eintrag mit aufklappbarer
  Aufschlüsselung; Subzähler werden unter dem Elternzähler eingerückt
  dargestellt.

### Offene Detail-Entscheidungen
- Können Subzähler selbst Gruppen-Mitglieder sein? (Verschachtelung)
- Verträge auf Gruppen-Ebene: was passiert, wenn ein Mitglied seinen
  eigenen Vertrag hat?
- Migrations-Hilfe: UI-Wizard für „bestehende NT/HT-Zähler zu einer
  Gruppe zusammenführen"?

### Schema-Migration
Schema **1.1.0 → 1.2.0**. Felder additiv mit Defaults — keine destruktive
Änderung an bestehenden Daten, aber Schemaversion springt, weil neue
Aggregations-Semantik in der Migrator-Validierung verankert wird.

---

## Backlog (ungeplant, ohne Slot)

Themen ohne festen Release-Slot. Werden in die nächsten freien Slots
verschoben, sobald Aufwand und Bedarf konkretisiert sind. F-/N-Codes hier
sind vorläufig und werden bei Übernahme in „Geplant" fortlaufend vergeben.

### Funktional (F-Codes vorläufig)

| Code (vorl.) | Thema | Größe | Notiz |
|--------------|-------|-------|-------|
| — | EN-Lokalisierung (Aktivierung) | L | Setzt N1007 voraus. Wahrscheinlich v2.0.0 Major. Aktuell nicht fest eingeplant. |
| — | Smart-Meter-Anbindung (SML / IEC 62056) | XL | Neue Datenpipeline für Lastgang-Daten, eigener Datenmodell-Strang. Längerfristig. |

### Nicht-funktional (N-Codes vorläufig)

| Code (vorl.) | Thema | Größe | Notiz |
|--------------|-------|-------|-------|
| N1006 | Performance-Caching (`computeForMeter`-Memoization, ETag / `If-Modified-Since` auf Read-Endpunkten) | M | Erst aktivieren, wenn echte Performance-Probleme auftreten (>10 Jahre × 6 Zähler). |
| N1007 | i18n-Foundation (Wrapper `t('key')` einführen, Strings extrahieren) | M | Voraussetzung für EN-Lokalisierung im funktionalen Backlog. Selbst keine neue Sprache. |
| N1008 | PWA-Manifest + Service-Worker | M | Mobile-App-Installation aufs iPhone (F1004 ist mobile-first; PWA komplettiert das). |
| N1009 | Accessibility-Audit + Fixes | M | ARIA-Labels, Tastatur-Navigation, Kontrast-Prüfung. |
| N1010 | Strukturiertes Logging (PSR-3 + JSON-Lines) | S | Aktuell `error_log` ohne Kontext. Für Diagnose bei User-Issues nützlich. |
| N1011 | API-Versionierung (`/api/v1/…`) | M | Vorbereitung für Smart-Meter-Anbindung mit potenziell anderer Schreiblogik. |

---

## Patch-Pool (Bugfix- / UX- / kleine NFR-Polituren)

Kleine Verbesserungen ohne F- oder N-Code. Werden in PATCH-Releases
gebündelt oder vor dem nächsten MINOR mit hinein gezogen.

### P-PV-01 — PV-Einspeisung: Verbrauchs-Semantik statt Erlös-Semantik in der Detail-View

**Beobachtet:** 2026-05-23 (v1.7.1), PV-Einspeisungs-Detail-View.
**Schwere:** hoch (fachlich falsch, schädigt Kundennutzen / Vertrauen).
**Ursache:** Die Detail-View (`public/js/views/utility.js`) und die
Saldo-Berechnung (`ConsumptionService::contractStatus`) behandeln
`pv_einspeisung` mit der generischen **Verbrauchs**-Semantik. Bei einer
Utility mit `accounting_kind = 'feed_in'` ist die wirtschaftliche
Bedeutung jedoch **invertiert**: der „Saldo" ist ein Vergütungs-Erlös
(Gutschrift), keine Kosten.

**Sichtbare Artefakte im Screenshot:**

| Element | Aktuell (falsch) | Korrekt (feed_in) |
|---|---|---|
| KPI „💶 Kosten 2026: 36,00 €" | „Kosten", neutral | „Erlös / Einspeisevergütung", grün |
| KPI „💰 Abschläge 2026: 0 € · Saldo Jahr +36 €" (rot) | Abschlags-/Saldo-Logik | bei Einspeisung gibt es **keine Abschläge** → Karte ausblenden oder zu „Vergütung Jahr" umlabeln (grün) |
| „Aktueller Saldo +1.588,56 € · **Unterzahlt**" (rot) | „Unterzahlt" | „Vergütungsanspruch / Guthaben", **grün** |
| „Erwartet bei Abrechnung +10.756,82 € · **Nachzahlung**" (rot) | „Nachzahlung" | „Erwartete Auszahlung", **grün** |
| KPI „☀️ Verbrauch 2026: 439 kWh" | „Verbrauch" | „Einspeisung / Ertrag" |

**Zweiter, eigenständiger Bug (Projektions-Horizont):** „Erwartet bei
Abrechnung" projiziert bis **Vertragsende 31.03.2043** → 10.756,82 €.
Bei einem 20-Jahres-EEG-Vertrag ist das absurd: die Größe soll die
**nächste Jahresabrechnung** schätzen, nicht die Gesamt-Restlaufzeit.
`contractStatus` projiziert linear bis `effective_end`; bei sehr langen
Verträgen (EEG) muss der Horizont auf die nächste Abrechnungsperiode
(billing_cycle_anchor) begrenzt werden — genau wie es für offene
Bezugsverträge schon geschieht.

**Verdict-Logik (Backend):** `ConsumptionService::contractStatus` setzt
`verdict = projected > 5 ? 'Nachzahlung' : …`. Für `feed_in` muss die
Achse umgedreht werden: positiver Saldo = Gutschrift/Auszahlung (gut,
grün), nicht Nachzahlung.

**Saisonaler Reading-Trend:** Die Banner-Warnung „3-Monats-Trend
−48,7 %" ist bei PV im Winterhalbjahr **normal** (weniger Sonne) und
sollte für `feed_in`/`generation` nicht als auffälliger negativer Trend
rot markiert werden. (Der „letzte Ablesung vor 83 Tagen"-Hinweis bleibt
berechtigt.)

**Vorschlag Scope:** eigener kleiner Patch (v1.7.2 oder gebündelt vor
F1006). Single source: ein `accounting_kind`-Switch in `balanceCard()`
+ KPI-Block + im Backend `contractStatus` (Verdict-Achse + Projektions-
Horizont). Tests: ein `ContractStatusFeedInTest`, der positiven Saldo
als Gutschrift (nicht Nachzahlung) erwartet und den Projektions-Horizont
auf die nächste Abrechnung begrenzt prüft.

---

*— weitere offene GitHub-Issues und beobachtete Polituren landen hier —*

---

## Prozess-Regeln

- **Reihenfolge ist verbindlich**, bis sich neue Erkenntnisse ergeben (ein
  Bug mit hoher Priorität schiebt sich vor; ein neues User-Feedback kann
  einen Slot übernehmen).
- **Jedes Feature bekommt** vor Implementation ein ausformuliertes
  Detail-Konzept (entweder direkt in dieser Datei oder als eigene
  `docs/functional/NN-…md` bzw. `docs/technical/NN-…md`).
- **Konzept-Entscheidungen** werden mit Multiple-Choice-Buttons im Chat
  geklärt, nicht angenommen (Memory-Regel).
- **NFRs vor riskanten Refactors:** N-Codes, die ein F-Feature absichern
  (z. B. Unit-Tests vor Topologie-Refactor), kommen vor diesem F-Feature.
- **Nach Release** wird der Eintrag von „Geplant" in „Bereits ausgeliefert"
  verschoben, das Detail-Konzept fließt in die Doku, der nächste Slot
  öffnet sich.

---

## Änderungs-Historie der Roadmap selbst

| Datum | Anlass | Änderung |
|-------|--------|----------|
| 2026-05-21 | Erstanlage (mit v1.6.1) | Roadmap als lebendiges Dokument eingeführt. F1005 (PV) / F1006 (Meter-Topologie) konkretisiert. EN-Lokalisierung und Smart-Meter ins Backlog. F1006 + F1007 zu einem gemeinsamen v1.8.0 gebündelt (Henne-Entscheidung). |
| 2026-05-21 | NFR-Erweiterung | N-Code-Reihe eingeführt. N1001 (PHPUnit), N1002 (Edge-Cases), N1003 (Health), N1004 (Backup/Restore), N1005 (Docker) als geplante Slots vor F1006. N1006–N1011 ins Backlog. Reihenfolge erklärt: NFRs vor riskanten Refactors, Backup vor Schema-Migration. |
| 2026-05-22 | v1.6.2 ausgeliefert | N1001 (PHPUnit-Foundation, 12 Tests / 41 Assertions über `ConsumptionService` + `MeterService` + `AnomalyService`) ausgeliefert. Composer dev-only, Runtime bleibt Composer-frei. Detail-Skizze aus „Geplant" entfernt. |
| 2026-05-22 | v1.6.3 ausgeliefert | N1002 (Edge-Case-Suite, 13 zusätzliche Tests in vier neuen Klassen) ausgeliefert. Alle 10 Roadmap-Fälle abgedeckt, kein Bug aufgedeckt, kein Code-Change. Frontend-Smoke bewusst weggelassen (PHPUnit ist der richtige Ort). Detail-Skizze aus „Geplant" entfernt. |
| 2026-05-23 | v1.7.0 ausgeliefert | F1005 (PV) + N1003 (Health-Check) gebündelt ausgeliefert. F1005-Scope auf User-Wunsch von „S — nur Einspeisung" auf „M–L — Einspeisung + Erzeugung + Autarkiequote" hochgezogen (Multiple-Choice-Klärung, Kundennutzen-Priorisierung Eigenheimbesitzer). 13 neue PHPUnit-Tests, 2 zusätzliche Browser-Render-Smokes. Schema bleibt 1.1.0. Pre-existing JsonStore/macOS-realpath-Bug nebenbei gefixt. Original-Skizze als historischer Kontext im Roadmap-File behalten. |
| 2026-05-23 | v1.7.1 ausgeliefert | N1004 (Backup/Restore-UI) kleiner als Skizze (bestehender JSON-Backup ausreichend, nur Schema-Guard + Auto-Snapshot ergänzt). Headroom genutzt, um Demo-Daten und Szenario-Doku für PV nachzureichen (Versäumnis aus v1.7.0). 3 neue PHPUnit-Tests. |

---

[← Kompendium-Index](README.md)
