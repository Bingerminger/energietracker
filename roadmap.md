# Energietracker — Roadmap

> Lebendiges Planungs-Dokument. Wird mit jedem Release fortgeschrieben.
> Bei Konflikt zwischen Roadmap-Reihenfolge und akutem User-Bedarf
> (z. B. kritischer Bug) gewinnt der Bedarf, und die Roadmap rückt nach.

**Stand:** 2026-05-21 (synchron mit v1.6.1, NFR-Sektion ergänzt)
**Aktuelle Baseline:** v1.6.1
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

---

## Geplante Reihenfolge (logisch sortiert)

Logik: **NFRs vor riskanten Refactors**. `F1006` (Meter-Topologie) berührt
`ConsumptionService` substanziell — vorher muss eine echte Unit-Test-Suite
existieren, sonst lassen sich Regressionen nicht zuverlässig vermeiden.
Außerdem: **Backup vor Schema-Migration**. F1006 hebt Schema 1.1.0 → 1.2.0;
ein UI-seitiges Backup/Restore davor erspart Datenverluste.

| Code | Thema | Release | Größe | Status |
|------|-------|---------|-------|--------|
| **N1001** | PHPUnit-Foundation + Unit-Tests `ConsumptionService` | v1.6.2 | M | Konzept-Skizze unten |
| **N1002** | Edge-Case-Test-Suite (Zählerüberlauf, lange Lücken, Tausch-Varianten, Schaltjahr, doppelte Daten) | v1.6.3 | M | Konzept-Skizze unten |
| **F1005** | PV-Einspeisezähler | v1.7.0 | S | Detail-Konzept offen |
| **N1003** | Health-Check-Endpoint `GET /health` | v1.7.0 (mitgeliefert) | XS | inline |
| **N1004** | Backup/Restore im UI (Snapshot-Download + Upload-Restore) | v1.7.1 | M | inline |
| **N1005** | Docker-Image + `docker-compose.yml` | v1.7.2 | M | inline |
| **F1006** | Meter-Topologie (Subzähler + Gruppe) | v1.8.0 | M | Detail-Konzept offen |
| TBD | offen für weitere Issues / Bedarfe | v1.9.0+ | — | offen |

---

## v1.6.2 — N1001 PHPUnit-Foundation

**Auslöser:** Issue #13 hat gezeigt, dass die Bridging-Logik im
`ConsumptionService` ohne Unit-Tests fragil ist. Der Bugfix v1.6.1 wurde
mit einem ad-hoc-Smoke-Skript verifiziert — eine wiederholbare Suite
existiert nur API-Shape-seitig.

### Ziel
Eine echte PHPUnit-Suite für die Service-Schicht, mit Initialfokus auf
`ConsumptionService` (Bridging-Pfade) und `MeterService` (`replaceDevice`,
`deviceOnDate`-Stichtag-Konvention).

### Skizze
- `composer.json` anlegen (bisher gibt es keine).
- PHPUnit als dev-Dependency.
- Verzeichnis `tests/unit/` mit Test-Klassen pro Service.
- Fixtures: realer Tausch-Datensatz aus Issue #13 als
  `tests/fixtures/issue13-tausch.json`, Wasser-3-Komponenten als
  `tests/fixtures/wasser-vertrag.json`.
- Erste Test-Klassen:
  - `ConsumptionServiceBridgingTest` — die fünf Bridging-Fälle aus
    Issue #13 (sauber, Off-by-one, fehlender `final_counter`,
    Plausibilitäts-Cap, `device_swap`-Flag-Setzung).
  - `MeterServiceReplaceDeviceTest` — 400-Validierung,
    `deviceOnDate`-Konvention.
  - `AnomalyServiceTest` — Wechsel-Monat wird übersprungen.
- CI-Job `phpunit` erweitert `.github/workflows/ci.yml`.

### Schema-Migration
Keine. Schema bleibt **1.1.0**.

---

## v1.6.3 — N1002 Edge-Case-Test-Suite

**Auslöser:** Reviews v1.4.4 / v1.6.1 haben mehrere unbedeckte
Konstellationen sichtbar gemacht. Bevor F1006 (Schema 1.2.0 + Topologie)
gebaut wird, muss die Regression-Sicherheit für diese Fälle stehen.

### Ziel
Test-Suite, die unrealistische Werte und seltene Konstellationen abdeckt.
Aufbauend auf der PHPUnit-Foundation aus N1001.

### Abzudeckende Edge Cases (vorläufige Liste)
1. **Zählerüberlauf** — 5-stellig → 6-stellig, oder Reset auf 0 ohne
   Device-Tausch.
2. **Sehr lange Intervalle** — User vergisst Ablesungen monatelang (>90
   Tage), `alert_days_since_reading`-Schwelle, Forecast-Verhalten.
3. **Doppelte Ablesungen am gleichen Datum** auf verschiedenen Devices.
4. **Negative Verbräuche** ohne Device-Tausch (sollte verworfen werden).
5. **Schaltjahr** (Feb 29) — Monatslängen-Berechnung.
6. **Zeitumstellung (DST)** — `DateTime`-Arithmetik in
   `distributeToMonths`.
7. **Leerer Zähler** — gar keine Ablesung, soll nicht crashen.
8. **Vertragswechsel mitten im Monat** — partielle Preise.
9. **Wasser ohne Schmutzwasser oder Niederschlagswasser** — eine oder
   zwei Komponenten leer.
10. **Bulk-Import von Ablesungen** vor Zähler-Beginn (`installed_on`
    liegt nach der ersten Ablesung).

### Erfolgsmaß
- Jeder Edge Case ist als PHPUnit-Test fixiert.
- Im Frontend wird mindestens ein User-sichtbarer Fall (Zählerüberlauf,
  lange Lücke) im `browser-render`-Test als Smoke geprüft.

### Schema-Migration
Keine. Schema bleibt **1.1.0**.

---

## v1.7.0 — F1005 PV-Einspeisezähler + N1003 Health-Check

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

## v1.7.1 — N1004 Backup / Restore im UI

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

*— derzeit leer; offene GitHub-Issues und beobachtete Polituren landen hier —*

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

---

[← Kompendium-Index](README.md)
