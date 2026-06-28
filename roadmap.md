# Energietracker — Roadmap

> Lebendiges Planungs-Dokument. Wird mit jedem Release fortgeschrieben.
> Bei Konflikt zwischen Roadmap-Reihenfolge und akutem User-Bedarf
> (z. B. kritischer Bug) gewinnt der Bedarf, und die Roadmap rückt nach.

**Stand:** 2026-06-10 (synchron mit v2.0.1; Bugfix Zählergruppen im Dashboard)
**Aktuelle Baseline:** v2.0.1
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
| P-PV-01 | PV-Einspeisung als Erlös statt Kosten (Verdict-Achse, Projektionshorizont, feed_in-Labels/Farben) + realistische Forecast-Demodaten | v1.7.2 | 2026-05-23 |
| N1005 | Docker-Image (Single-Container nginx+php-fpm) + `docker-compose.yml` + GHCR-Publikation + CI-Smoke-Job | v1.7.3 | 2026-05-31 |
| N1010 | Strukturiertes Logging (abhängigkeitsfreier JSON-Lines-Logger, ENV-gesteuert; ErrorHandler + Lebenszyklus geloggt) | v1.7.3 | 2026-05-31 |
| F1007 | Demo-Daten-Import über die Einstellungen (Ein-Klick, Warnung bei vorhandenen Daten, Auto-Snapshot; serverseitiger Endpoint) | v1.7.4 | 2026-05-31 |
| N1012 | CI-Actions Node-24-fähig (`docker/*` per `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24`; Zwangsumstellung 2026-06-16 neutralisiert) | — (CI-Wartung, kein Bump) | 2026-05-31 |
| F1006 | Meter-Topologie: Subzähler (Reihenschaltung, vom Eltern abgezogen) + Gruppen (Dashboard-Summe) + Merge-Wizard; Schema 1.2.0 | v1.8.0 | 2026-06-01 |
| F1009 | Home-Assistant-Anbindung: opt-in Token-Auth (Hash in `data/auth.json`) + idempotenter `POST /api/ingest` (upsert-by-date) + Zähler-Alias `external_id`; Schema 1.3.0 | v1.9.0 | 2026-06-01 |
| **v2.0.0-Bündel** (N1007 + EN-L10n + N1009 + UX + N1008) | Full-Stack-i18n (JSON-Kataloge + `t()` + `I18nService`, `language`-Setting) · vollständige EN-Lokalisierung (DE/EN-Parität) · Barrierefreiheit (Skip-Link, Fokus-Management, Label↔Feld, `scope`, ARIA-Labels, Chart-Alt, Live-Regions) · UX-Politur · PWA (Manifest + Service-Worker, installierbar + offline). Schema unverändert 1.3.0 (additiv). | v2.0.0 | 2026-06-10 |

---

## Geplante Reihenfolge (logisch sortiert)

Leitlogik dieser Sequenz:

1. **NFRs vor riskanten Refactors.** `F1006` (Meter-Topologie) berührt
   `ConsumptionService` substanziell — vorher müssen Test-Suite (N1001/02),
   Backup (N1004), Container-Testumgebung (N1005) und strukturiertes Logging
   (N1010) stehen, sonst lassen sich Regressionen nicht zuverlässig vermeiden.
2. **Backup vor Schema-Migration.** F1006 hebt Schema 1.1.0 → 1.2.0; das
   UI-seitige Backup/Restore (N1004) davor erspart Datenverluste.
3. **Kundennutzen vor Infrastruktur.** Die spürbare UX-Welle (PWA, A11y)
   kommt vor dem trockenen i18n-Unterbau.
4. **Thematisch bündeln.** Ops-Themen (Docker + Logging) und UX-Themen
   (PWA + A11y) reisen paarweise, statt jeden N-Code einzeln zu releasen.
5. **v2.0.0 als bewusst gebündelter Major (User-Entscheidung 2026-06-02).**
   Die frühere Leitlinie „Major nicht mit anderen Themen vermischen" ist hier
   bewusst aufgehoben: N1007 (i18n-Foundation), EN-L10n, N1008 (PWA), N1009
   (A11y) und eine UX-Überarbeitung werden **gemeinsam** als v2.0.0 entwickelt
   und erst dann ausgeliefert. Grund: i18n, A11y und UX fassen ohnehin dieselben
   12 View-Dateien an — ein einziger großer Durchgang statt fünf einzelner
   Releases. Die i18n-Reichweite ist **Full-Stack** (Frontend-`t()` + Backend-
   Katalog via Accept-Language), Kataloge als JSON je Sprache, Sprachwahl als
   additives `language`-Setting (kein Schema-Bump). Schema bleibt 1.3.0.
   Die früher geplante Smart-Meter-Anbindung (v3.0.0, eigener Datenpipeline-
   Major) ist **gestrichen** — echtes Metering (Smart-Meter-Auslesung) wird
   bewusst an **Home Assistant** delegiert, das die Werte per F1009-Ingest an
   den Energietracker pusht. Der Energietracker bleibt damit die schlanke
   Vertrags-, Kosten- und Prognose-Oberfläche; die Hardware-/Protokollwelt
   (SML, IEC 62056, Lastgang) lebt in HA. Die strategische Ausbaurichtung ist
   deshalb der **Ausbau der HA-Integration** (siehe Bedarfsgetrieben).
6. **F1008 (NKA) nach v2.0.0.** Auf User-Entscheidung (2026-06-02) rückt die NKA
   für Mieter hinter das v2.0.0-Bündel (Ziel **v2.1.0**, Schema 1.3.0 → 1.4.0).
   Begründung: i18n/A11y/UX/PWA bringen der breiten Nutzerbasis schneller Wert;
   die NKA ist ein größeres, modulares Datenmodell-Vorhaben ohne akuten
   Zeitdruck. Das Detail-Konzept unten bleibt gültig und wird vor v2.1.0
   finalisiert.

| Code | Thema | Release | Größe | Schema | Status |
|------|-------|---------|-------|--------|--------|
| **L10n-Welle-1 + Doku-i18n + Logos** | 5 neue UI-Sprachen (fr, it, es, pt, nl) auf datengetriebener Sprach-Infra (languages.json-Registry); zweisprachige Doku DE+EN (GitHub-Best-Practice); neues App-Logo/Icon-Set | v2.1.0 | L | — | **in Arbeit** (Sprachen fertig+verifiziert; Doku-Übersetzung läuft wellenweise) |
| **F1008** | NKA für Mieter (modulares Datenmodell, GitHub #15) | v2.2.0 | L | 1.3.0 → 1.4.0 | nach dem i18n/Doku-Release, Detail-Konzept unten |

> **Sprach-Wellen 2+** (cs, uk, pl, el, tr, hr, sr, sl, fi, no, da, lv, et, hu, bg, ro …)
> sind bewusst zurückgestellt (User-Entscheidung 2026-06-10) und werden
> bedarfsgetrieben in weiteren MINOR-Releases nachgereicht — je ~2–4 Sprachen,
> gleiche Methode (Katalog aus en.json, Parität-/Platzhalter-Check, in
> languages.json registrieren). Eine neue Sprache braucht keinen Code mehr.
>
> **Zweisprachige Doku (DE+EN)** ist ab v2.1.0 die Norm: README/INSTALL als
> `*.md` (EN) + `*.de.md` (DE), `docs/` als `docs/en/` + `docs/de/`. DE bleibt
> kanonisch; das große Kompendium (API, funktional, technisch) wird wellenweise
> ins EN gespiegelt, ohne Info-Verlust.

> Das **v2.0.0-Bündel** (N1007 + EN-L10n + N1009 + UX + N1008) ist am
> 2026-06-10 ausgeliefert → siehe „Bereits ausgeliefert".

**Bedarfsgetrieben (kein fester Slot):**

| Code | Thema | Größe | Auslöser |
|------|-------|-------|----------|
| **N1006** | Performance-Caching (`computeForMeter`-Memoization, ETag / `If-Modified-Since`) | M | Wird vorgezogen, sobald eine echte Messung (>10 J × 6 Zähler) spürbare Latenz zeigt. Vorab-Optimierung verstößt gegen die „erst bei echtem Problem"-Regel. |
| **N1011** | API-Versionierung (`/api/v1/…`) | M | Ursprünglich als Smart-Meter-Vorbereitung geplant; mit dessen Streichung kein fester Slot mehr. Wird nur umgesetzt, wenn ein echter Bruch der API-Kompatibilität ansteht. |
| **F1010+** | Ausbau der Home-Assistant-Integration (Ideen) | M–L | Strategische Leitlinie statt Smart-Meter. Mögliche Bausteine, sobald Nutzerbedarf entsteht: Rückkanal/Status-Endpoint für HA (z. B. Saldo/Prognose als Sensor), Mehrfach-Tokens bzw. pro-Gerät-Token, Bulk-Ingest mehrerer Zähler in einem Request, optionales HA-Auto-Discovery. Noch nicht spezifiziert. |

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

## v1.7.3 — N1005 Docker-Image

**Auslöser:** Self-hosted ja, aber bisher ausschließlich auf Synology
mit individueller nginx-Konfiguration. Ein Docker-Image macht das Tool
für andere Hosts und für Tests reproduzierbar.

### Ziel
- `Dockerfile` (Multi-Stage, Basis `php:8.4-fpm-alpine` + nginx).
- `docker-compose.yml` mit Volume-Mount für `./data`.
- README-Sektion „Docker-Quickstart".
- CI-Job: Image bauen und smoke-testen (gegen Demo-Daten).
- Image-Publikation auf GHCR (`ghcr.io/bingerminger/energietracker:1.7.3`).

### Schema-Migration
Keine.

---

## v1.7.4 — F1007 Demo-Daten-Import über die Einstellungen

**Auslöser:** Ein frisch installierter (oder containerisierter) Energietracker
ist leer. Demo-Daten lassen sich bisher nur per Dateisystem-Kopie einspielen
(`cp -r demo-data data`) — für Nicht-Techniker zu hürdenreich. F1007 macht den
Demo-Import zu einem Ein-Klick-Schritt direkt in der UI.

### Ziel
- Im Einstellungs-View unter „Backup & Restore" ein Button
  **„Demo-Daten laden"**.
- Klick importiert ein mitgeliefertes Demo-JSON-Backup über den bereits
  bestehenden Restore-Pfad (`BackupService::import()` inkl. Schema-Guard und
  Auto-Snapshot vor Restore aus N1004).
- **Warnung vorab**, wenn bereits Daten vorhanden sind („Dies überschreibt
  deine aktuellen Daten. Vorher wird automatisch ein Snapshot angelegt.") —
  Abbruch möglich. Bei komplett leerem Tracker direkter Import ohne Warnung.

### Skizze
- Das Demo-Backup liegt bereits import-fertig als JSON im Repo:
  `demo-data/energietracker-demo-backup.json` (über den echten Export-Endpoint
  erzeugt → garantiert kompatibel). Wird in der Doku verlinkt und kann auch
  jetzt schon manuell über „Backup importieren" eingespielt werden.
- Damit es **im Container** verfügbar ist (dort ist `demo-data/` per
  `.dockerignore` ausgeschlossen), wird für F1007 eine ausgelieferte Kopie
  nötig — Variante A: Datei unter `public/demo/…` als statisches Asset, das
  das Frontend lädt und an `POST /api/backup/import` schickt; Variante B:
  serverseitiger Endpoint `POST /api/demo/import`, der die mitgelieferte Datei
  liest und importiert (Guard „leer? sonst Warnung" serverseitig).
  → **Detail-Entscheidung A vs. B beim Implementieren** (Multiple-Choice).
- „Ist leer?"-Erkennung: keine Meter über alle Utilities hinweg.

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
- **Migrations-Wizard:** geführter Dialog „mehrere Zähler zu einer Gruppe
  zusammenführen" (Mehrfachauswahl bestehender Zähler → neue/bestehende
  `meter_group_id`), inkl. Validierung gegen den oben verbotenen Mischfall.

### Getroffene Detail-Entscheidungen (2026-06-01)
- **Verschachtelung — „Subzähler in Gruppe erlaubt" (begrenzt).** Ein Zähler
  darf `parent_meter_id` UND `meter_group_id` tragen (ein Subzähler kann also
  Gruppenmitglied sein). **Keine** mehrstufigen Subzähler-Ketten: ein Zähler
  mit `parent_meter_id` darf selbst nicht Elternzähler eines weiteren
  Subzählers sein (max. 1 Subzähler-Ebene). Validierung im Migrator/Service
  muss das erzwingen (kein Großeltern→Eltern→Kind, keine Zyklen).
- **Vertrag + Gruppe — „Gruppen nur fürs Dashboard" (Entscheidung 2026-06-01).**
  Gruppen fassen in v1.8.0 ausschließlich den *Verbrauch* fürs Dashboard
  zusammen. Verträge bleiben **unverändert pro Zähler**; es gibt keinen
  Gruppen-Vertrag und keine neue Vertrags-Validierung. Der Gruppen-Vertrags-
  Saldo (Vertrag gegen Gruppensumme, Mitglieder ohne eigenen Vertrag) ist
  bewusst auf ein späteres Release vertagt — hält v1.8.0 klein und vermeidet
  Doppelzählungs-Bugs in der Saldo-Auswertung.
- **Migrations-Wizard — ja, in v1.8.0 enthalten.** Geführter Dialog
  „mehrere bestehende Zähler zu einer Gruppe zusammenführen" (typischer Fall:
  NT + HT Strom). Eigener UI-Flow + Tests. Erhöht den Aufwand auf M–L.

### Schema-Migration (Skizze, 2026-06-01)

Schema **1.1.0 → 1.2.0**. Rein **additiv**, keine destruktive Änderung an
bestehenden Daten; die Schemaversion springt, weil neue Aggregations- und
Validierungs-Semantik im Migrator verankert wird. Folgt dem bestehenden
Migrator-Muster (`needsV120Upgrade()` + `upgradeToV120()`, idempotent).

**Datenmodell-Änderungen:**
- Jeder Meter in `data/<utility>/meters.json` erhält zwei neue Felder mit
  Default `null`:
  - `parent_meter_id: ?string` — verweist auf den Elternzähler (Subzähler).
  - `meter_group_id: ?string` — Gruppen-Mitgliedschaft.
- Neue Datei je Utility: `data/<utility>/meter_groups.json` — Liste von
  Gruppen-Stammdaten `[{ id: "g_<utility>_<hex>", name: string,
  created_at: "Y-m-d" }]`. **Mitgliedschaft wird NICHT hier dupliziert**,
  sondern bleibt single-source-of-truth über `meter_group_id` am Meter
  (vermeidet Drift zwischen zwei Listen).
- `meta.json.schema_version` → `1.2.0`.

**`upgradeToV120()` (idempotent, additiv):**
1. Für jede Utility `meter_groups.json` via `ensureFile(…, [])` anlegen, falls
   nicht vorhanden.
2. Über alle Meter jeder Utility iterieren; wo `parent_meter_id` bzw.
   `meter_group_id` als Key fehlt → mit `null` ergänzen. Bestehende Werte
   bleiben unangetastet (Idempotenz).
3. `needsV120Upgrade()` ist `true`, solange eine `meter_groups.json` fehlt
   ODER ein Meter eines der beiden Keys nicht besitzt.

**Validierungsregeln (im Migrator/`MeterService` verankert, neue 1.2.0-Semantik):**
- **Keine mehrstufigen Subzähler-Ketten:** ein Meter mit gesetztem
  `parent_meter_id` darf selbst nicht als `parent_meter_id` eines anderen
  Meters auftreten (max. 1 Ebene). Schreibpfad lehnt Verstoß ab.
- **Keine Zyklen / Selbstreferenz:** `parent_meter_id` ≠ eigene `id`; der
  Elternzähler muss in derselben Utility existieren und aktiv referenzierbar
  sein.
- **Subzähler darf Gruppenmitglied sein** (beide Felder gleichzeitig erlaubt).
  Die Aggregation zieht Subzähler beim Elternzähler ab (Eltern misst brutto
  inkl. Subzähler) — in der Utility-Gesamtsumme zählen nur Zähler OHNE
  `parent_meter_id`, sodass kein Subzähler-Anteil doppelt einfließt.
- **Verträge bleiben in v1.8.0 unverändert pro Zähler** (Entscheidung
  2026-06-01, „Gruppen nur fürs Dashboard"): Gruppen fassen ausschließlich den
  *Verbrauch* fürs Dashboard zusammen. Es gibt **keinen Gruppen-Vertrag** und
  folglich **keine** neue Vertrags-Validierung. Jedes Mitglied behält seine
  eigenen Verträge; Saldo/`contractStatus` laufen weiterhin pro Zähler. Der
  Gruppen-Vertrags-Saldo (Vertrag gegen Gruppensumme) ist auf ein späteres
  Release vertagt.
- **`delete`-Guards erweitern:** ein Elternzähler mit noch zugeordneten
  Subzählern (`parent_meter_id`-Ziel) kann nicht gelöscht werden, ohne die
  Subzähler-Zuordnung vorher aufzulösen (analog zu den bestehenden
  Readings-/Contracts-Guards in `MeterService::delete()`). Das Löschen einer
  Gruppe löst die Mitglieder (`meter_group_id` → null), statt sie zu blocken.

**Aggregations-Eingriff (`ConsumptionService::forUtility()`):**
- Heute summiert `forUtility()` stumpf über alle Meter zu `monthly_total` —
  das würde Eltern- *und* Subzähler doppelt zählen. Neu: vor der YM-Summe
  Subzähler-Verbräuche vom Elternzähler abziehen (Eltern-Netto) und Subzähler
  nicht zusätzlich in die Gesamtsumme aufnehmen; Gruppen als ein logischer
  Eintrag mit aufklappbarer Aufschlüsselung ausweisen.
- Recursion-Guard analog zum bestehenden `meterComputeStack` (Schmutzwasser-
  `separater_zaehler`-Lookup) wiederverwenden/erweitern.

**Backup/Restore:** `BackupService` zieht ohnehin alle JSON-Dateien je Utility
ein; `meter_groups.json` wird additiv mitgesichert. Schema-Guard
(`version_compare` gegen `Migrator::SCHEMA_VERSION`) greift automatisch, sobald
`SCHEMA_VERSION = '1.2.0'`.

**Tests (mind.):** Migration idempotent (zweifacher Aufruf = No-Op),
Felder-Default-Ergänzung, Verschachtelungs-Validierung (2-Ebenen-Kette wird
abgelehnt), Zyklus-Ablehnung, Mischfall-Vertrag-Block, Aggregation
Eltern-Netto + Gruppen-Summe ohne Doppelzählung, `delete`-Guards.

---

## F1009 — Home-Assistant-Anbindung (Push-Ingest)

**Auslöser:** Community-Nutzer betreiben Energietracker zunehmend zusammen mit
Home Assistant (HA): HA liest Smart Meter aus, Energietracker macht Verträge,
Kosten und Prognosen. Es kursiert eine (KI-generierte, **technisch falsche**)
Forenanleitung, die einen `POST /api.php` mit `{action:"add_reading"}` und
einen Bearer-Token aus `settings.json` beschreibt — beides existiert **nicht**.
F1009 liefert die **offizielle, korrekte** Lösung.

**Architektur-Entscheidungen (2026-06-01, Multiple-Choice):**
1. **Auth = optionaler Token.** Die API hat heute keine Authentifizierung
   (LAN-Annahme). F1009 führt einen **opt-in**-Token ein: Solange keiner
   gesetzt ist, ändert sich nichts (abwärtskompatibel). Der Token wird einmalig
   im Klartext angezeigt und nur als **Hash** in einer separaten `data/auth.json`
   gespeichert (NICHT in `settings.json`, da `GET /api/settings` alles
   ausliefert und `SettingsService::set()` nur bekannte Keys whitelistet).
2. **Dedizierter `/api/ingest`-Endpoint** statt der bestehenden readings-Route.
   Token-geschützt, **upsert-by-date** (idempotent): der tägliche HA-Push um
   23:55 überschreibt den Wert desselben Tages, statt Duplikate anzulegen.
   Bestehende UI-Routen bleiben unverändert offen → das Web-UI (das den
   Klartext-Token nicht kennt) wird nicht ausgesperrt; der einzige extern
   token-pflichtige Schreibpfad ist `/api/ingest`.
3. **Zähler-Alias `external_id`.** HA-Nutzer kennen interne IDs wie
   `m_strom_main` nicht. Jeder Zähler bekommt optional eine frei vergebbare,
   pro Utility eindeutige `external_id` (z. B. `stromzaehler_haus`), die in HA
   eingetragen wird. Additiv; `/api/ingest` akzeptiert Alias **oder** interne ID.

**Echter Endpoint-Vertrag (Korrektur der Forum-Fehlinfo):**
- Real ist `POST /api/utility/{utility}/readings` mit Feldern **`counter`**
  (Zahl) + **`date`** (`YYYY-MM-DD`) — NICHT `value`/`timestamp`/`action`.
- `POST /api/ingest` (neu) nimmt:
  `{ utility, meter (= external_id ODER interne id), value, date? }`,
  Header `Authorization: Bearer <token>` (falls Token gesetzt). `date`
  optional → Default heute. Antwort meldet `created` vs. `updated`.

**Schema:** **1.2.0 → 1.3.0** (additiv): neues optionales Meter-Feld
`external_id` (Default null). `data/auth.json` ist KEINE Schema-Datei, sondern
ein separater Credential-Store (wird vom Backup ausgenommen).

**Offene Detailpunkte:**
- Rate-Limit / Brute-Force-Schutz am Token-Check? (vorerst nein — LAN-fokus,
  konstanter Zeitvergleich via `hash_equals` reicht für v1.9.0.)
- Mehrere Tokens / pro-Gerät? (vorerst genau einer, widerrufbar.)

**Tests:** Token erzeugen/verifizieren (Hash, `hash_equals`), `/api/ingest`
ohne Token bei gesetztem Token → 401, mit Token → 200, upsert-by-date
idempotent (zweiter Push am selben Tag aktualisiert statt dupliziert),
Alias-Auflösung + Eindeutigkeits-Validierung, Migration `external_id`.

**Doku:** echte `docs/API.md`-Sektion + eigene HA-Anleitung mit korrektem
REST-Command/Automation-YAML und zwei Use-Cases (Eigenheim mit PV/Fernwärme,
Mietwohnung Strom/Gas/Wasser).

---

## F1008 — NKA für Mieter (GitHub #15)

**Quelle:** GitHub-Issue #15, offen seit 2026-05-22 (vom User selbst).

**Problem:** Eine vollständige Nebenkostenabrechnung ist für Mieter zu komplex
(Verwaltungsverträge fehlen, Umlage nach m² liegt bei der Hausverwaltung). Das
Datenmodell soll **modular** umgebaut und die NKA in drei getrennte Bereiche
geteilt werden. Überschneidet sich konzeptionell mit F1006 (Meter-Topologie)
und baut darauf auf. F1006 ist ausgeliefert (v1.8.0); das Topologie-Datenmodell
steht also. **Slot zurückgestellt** ans Ende der MINOR-Sequenz (v1.13.0, Schema
1.3.0 → 1.4.0), hinter N1008/N1009/N1007 (User-Entscheidung 2026-06-02,
s. Leitlogik 6).

**Lösungsskizze (3 Module):**
1. **Relevante Zählerstände** — Unterscheidung *abrechnungsrelevant* vs. reine
   *Verbrauchsüberwachung*. Beispiel: Warmwasser (m³) ist abrechnungsrelevant,
   Heizung (kWh) nur Monitoring ohne Finanzumrechnung. Umsetzbar als
   Zähler-Flag o. Ä.
2. **Pauschale Umlagen** — Posten ohne aktiven Zähler (z. B. Kaltwasser nach
   m²), als fixer Prognose-Posten hinterlegbar.
3. **Jährliche Endabrechnung** — PDF-Upload der Hausverwaltung + Felder für
   Nach-/Rückzahlung zur Budgetkontrolle.

**Offene Fragen:**
- Datei-Upload/-Storage für PDFs (Flat-File-Persistenz: Pfad-/Größenlimit,
  Backup-Einbindung)?
- „abrechnungsrelevant" als Zähler-Flag oder eigener Entitätstyp?
- Mehrjahres-Abgleich der Endabrechnungen (Verknüpfung mit F1005)?

(Detail-Konzept und die vier offenen Architektur-Weichenstellungen — Release-
Schnitt, abrechnungsrelevant-Modellierung, pauschale Umlagen, PDF-Storage —
werden per Multiple-Choice geklärt, sobald F1008 an die Reihe kommt, d. h.
nach N1007/v1.12.0.)

---

## Backlog (ungeplant, ohne Slot)

Mit der Einsortierung vom 2026-05-31 wurde der gesamte bisherige Backlog in
die „Geplante Reihenfolge" überführt (siehe oben). Aktuell steht hier nichts
Offenes mehr. Neue Themen ohne festen Slot landen wieder hier; F-/N-Codes
sind dann vorläufig und werden bei Übernahme in „Geplant" fortlaufend
vergeben.

*— derzeit leer —*

---

## Patch-Pool (Bugfix- / UX- / kleine NFR-Polituren)

Kleine Verbesserungen ohne F- oder N-Code. Werden in PATCH-Releases
gebündelt oder vor dem nächsten MINOR mit hinein gezogen.

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
| 2026-05-23 | v1.7.2 ausgeliefert | P-PV-01 (PV-Einspeisung als Erlös statt Kosten) aus dem Patch-Pool vorgezogen, nachdem ein realer Screenshot „+10.756 € Nachzahlung" auf der PV-Einspeise-View zeigte. Verdict-Achse + Projektionshorizont (Backend), feed_in-Labels/Farben (Frontend), 3-Monats-Trend bei PV unterdrückt. PV-Demodaten mit realistischer Jahres-Streuung neu generiert. N1005 (Docker) von v1.7.2 auf v1.7.3 verschoben. 2 neue PHPUnit-Tests. |
| 2026-05-31 | Backlog vollständig einsortiert | Gesamter Backlog in die „Geplante Reihenfolge" überführt und auf Releases verteilt: v1.7.3 N1005+N1010 (Ops-Bündel Docker+Logging), v1.8.0 F1006, v1.9.0 N1008 (PWA), v1.10.0 N1009 (A11y), v1.11.0 N1007 (i18n-Foundation), v2.0.0 EN-Lokalisierung (Major), v2.1.0 N1011 (API-Versionierung), v3.0.0 Smart-Meter (Major). N1006 (Performance) bleibt bewusst bedarfsgetrieben ohne festen Slot. Leitlogik: NFRs vor Refactors, Backup vor Migration, Kundennutzen vor Infrastruktur, thematisch bündeln, Majors trennen. |
| 2026-05-31 | v1.7.3 ausgeliefert | N1005 (Docker-Single-Container nginx+php-fpm, docker-compose, GHCR-Publikation per Tag, CI-Smoke-Job) + N1010 (abhängigkeitsfreier JSON-Lines-Logger, ENV-gesteuert; ErrorHandler loggt jetzt Exceptions/Fatals, Lebenszyklus + Access-Log) gebündelt ausgeliefert. nginx-Config spiegelt router.php; `clear_env=no` reicht ET_*-ENV durch. Keine Schema-Migration. 4 neue PHPUnit-Tests (47/166 gesamt). Nächster Slot: F1006. |
| 2026-05-31 | N1012 aufgenommen | Nach v1.7.3-Release meldete GitHub eine Node-20-Deprecation für die `docker/*`-Actions (Zwangsumstellung auf Node 24 ab 16.06.2026). Als fristgebundene CI-Wartung N1012 in die geplante Reihenfolge aufgenommen — kein Versions-Bump nötig, reine Action-Versionspflege. |
| 2026-05-31 | F1007 aufgenommen | Auf User-Wunsch: Demo-Daten-Komfort-Import über die Einstellungen (Ein-Klick, Warnung bei vorhandenen Daten) als nächstes Feature-Release v1.7.4 (vor F1006) eingeplant. Demo-Backup-JSON `demo-data/energietracker-demo-backup.json` bereits beigelegt und in der Doku verlinkt. |
| 2026-05-31 | v1.7.4 ausgeliefert | F1007 umgesetzt (Variante B serverseitig): `DemoService` + Controller, `GET /api/demo/status` + `POST /api/demo/import`, Button „Demo-Daten laden" im Einstellungs-View mit Warnung+Auto-Snapshot. Demo-Backup via `.dockerignore`-Ausnahme im Image. 4 neue PHPUnit-Tests. Keine Schema-Migration. Nächster Slot: F1006. |
| 2026-06-01 | N1012 erledigt | `docker/*`-Actions per `env: FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: "true"` in `docker-publish.yml` auf Node 24 erzwungen, CI grün — die GitHub-Zwangsumstellung am 2026-06-16 ist damit neutralisiert. Aus „Geplante Reihenfolge" entfernt, nach „Bereits ausgeliefert" verschoben (reine CI-Wartung, kein Versions-Bump). |
| 2026-06-01 | F1008 aufgenommen | GitHub #15 „NKA für Mieter" als eigenes Feature triagiert: modulares Datenmodell (relevante Zählerstände / pauschale Umlagen / jährliche Endabrechnung mit PDF-Upload). Eingeplant nach F1006 (Schema 1.2.0 → 1.3.0, Slot noch offen), Detail-Konzept-Abschnitt ergänzt. |
| 2026-06-01 | F1006 Detail-Konzept entschieden | Drei offene Architektur-Punkte per Multiple-Choice geklärt: (1) Subzähler dürfen Gruppenmitglied sein, aber keine mehrstufigen Subzähler-Ketten (max. 1 Ebene); (2) Vertrag-+-Gruppen-Mischfall auf späteres Release vertagt — v1.8.0 erlaubt entweder Einzelvertrag oder Gruppenvertrag; (3) Merge-Wizard für Bestandszähler kommt in v1.8.0 mit. Aufwand dadurch M→M–L. Status: bereit zur Umsetzung. |
| 2026-06-01 | F1006 Schema-Migrations-Skizze | Konkrete 1.1.0→1.2.0-Skizze nachgezogen: neue Meter-Felder `parent_meter_id`/`meter_group_id` (Default null), neue `meter_groups.json` je Utility (Mitgliedschaft bleibt single-source am Meter), idempotenter `upgradeToV120()` nach bestehendem Migrator-Muster, Validierungsregeln (keine 2-Ebenen-Ketten, keine Zyklen, Mischfall-Block, delete-Guards), Aggregations-Eingriff in `forUtility()` gegen Doppelzählung, Backup additiv, Testliste. |
| 2026-06-01 | v1.8.0 ausgeliefert | F1006 umgesetzt: Subzähler (`parent_meter_id`, Reihenschaltung — vom Eltern abgezogen, keine Doppelzählung in der Gesamtsumme) + Gruppen (`meter_group_id` + `meter_groups.json` je Utility, Dashboard-Zusammenfassung) + Merge-Wizard. Schema 1.1.0→1.2.0 (additiv/idempotent, Auto-Migration). Verträge bleiben pro Zähler („Gruppen nur fürs Dashboard"). Neue Gruppen-API-Endpoints; Validierung (keine mehrstufigen Ketten/Zyklen) + delete-Guards. 15 neue PHPUnit-Tests (66/219). Nächster Slot: F1008. |
| 2026-06-01 | F1009 aufgenommen + vorgezogen | Home-Assistant-Anbindung als vorrangiges Feature (v1.9.0) eingeplant — vom User aus einem Community-Bedarf vorgezogen, VOR F1008. Eine kursierende KI-generierte Forenanleitung beschreibt die API falsch (`POST /api.php` + `action`/`value`/`timestamp` + Bearer aus `settings.json` — existiert alles nicht); F1009 liefert die offizielle Lösung: opt-in Token-Auth (Hash in separater `data/auth.json`), dedizierter idempotenter `POST /api/ingest` (upsert-by-date), Zähler-Alias `external_id`. Schema 1.2.0→1.3.0 additiv. F1008/N1008/N1009/N1007 je einen Slot nach hinten. |
| 2026-06-01 | v1.9.0 ausgeliefert | F1009 umgesetzt: offizielle Home-Assistant-Anbindung. Opt-in Token-Auth (`/api/auth/token`, SHA-256-Hash in separater `data/auth.json`, `hash_equals`), idempotenter Push-Endpoint `POST /api/ingest` (upsert pro Zähler+Datum, akzeptiert Alias oder interne ID, `date` optional/ISO-tolerant), Zähler-Alias `external_id` (eindeutig je Utility). Einstellungs-Sektion mit Token-Verwaltung, Alias-Pflege und Copy-YAML. Neue `docs/HOME-ASSISTANT.md` mit 2 Use-Cases; `docs/API.md` erweitert. Schema 1.2.0→1.3.0 additiv. 15 neue PHPUnit-Tests (81/261). Nächster Slot: F1008. |
| 2026-06-01 | v1.9.1 ausgeliefert (PATCH) | Bugfix + CI-Wartung, kein Schema/Feature. (1) `Migrator::isPristine()` erkennt ein komplett leeres Datenverzeichnis → beim Erststart läuft `initFresh()` statt `migrate()`, sodass ein frischer Docker-Container Standard-Zähler (Gas/Strom/Wasser) bekommt statt 0. (2) `ci.yml` setzt `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24` (Node-20-Deprecation der Actions neutralisiert). 5 neue PHPUnit-Tests (86/274). Nächster Slot: F1008. |
| 2026-06-02 | Smart-Meter gestrichen, Roadmap neu sortiert | Auf User-Entscheidung: echtes Metering (Smart-Meter-Auslesung) wird vollständig an Home Assistant delegiert (F1009-Ingest), daher den geplanten v3.0.0-Major **Smart-Meter** (SML/IEC 62056/Lastgang) komplett entfernt. N1011 (API-Versionierung) war nur „Vorbereitung Smart-Meter" → aus der festen Reihenfolge in den Bedarfsgetrieben-Block verschoben. Neue strategische Leitlinie statt Smart-Meter: Ausbau der HA-Integration (als bedarfsgetriebenes F1010+ skizziert). Geplante Reihenfolge endet damit bei EN-L10n (v2.0.0). Nächster Slot unverändert: F1008. |
| 2026-06-02 | v1.9.2 ausgeliefert (Doku-PATCH) | Vollständiger Dokumentations-Review (kein Code): Faktenabgleich aller Docs auf Code-Stand (Schema 1.3.0, 68 Routen, 24 Services/20 Controller, 12 Views, 40 Settings, Testzahlen), F1006/F1009 durchgängig dokumentiert, Smart-Meter-Verweise bereinigt. Drei neue nutzerorientierte Dokumente: `ERSTE-SCHRITTE.md`, `USE-CASES.md` (4 Praxisfälle), `functional/13-meter-topologie.md`. Index/Struktur/Verlinkung ausgebaut, alle internen Links geprüft. Nächster Slot: F1008. |
| 2026-06-02 | v1.9.3 ausgeliefert (Doku-PATCH) | UI-Referenz auf **echte Screenshots** umgestellt (Playwright-Aufnahmen der laufenden App mit Demo-Daten): 11 handgezeichnete SVG-Mockups durch 13 echte PNGs in `docs/ui/screenshots/` ersetzt, `ui/01-views.md` überarbeitet (alle 12 Views inkl. PV + Topologie-Hinweis), Mockup-Disclaimer projektweit entfernt. Nächster Slot: F1008. |
| 2026-06-02 | F1008 (NKA) zurückgestellt | Auf User-Entscheidung rückt die NKA für Mieter vom „nächsten Slot" ans Ende. Leitlogik-Punkt 6 ergänzt, Detail-Konzept bleibt gültig. |
| 2026-06-02 | v2.0.0 als gebündelter Major beschlossen | Auf User-Entscheidung werden **N1007 (i18n-Foundation), EN-L10n, N1008 (PWA), N1009 (A11y) und eine UX-Überarbeitung gemeinsam als v2.0.0** entwickelt und erst dann ausgeliefert (frühere „Majors nicht mischen"-Leitlinie bewusst aufgehoben — Leitlogik 5 neu gefasst). Architektur-Festlegungen: i18n **Full-Stack** (Frontend-`t()` + Backend-Katalog via Accept-Language), **JSON-Kataloge je Sprache** + `t('key')`-Wrapper, Sprachwahl als additives **`language`-Setting** (kein Schema-Bump, Schema bleibt 1.3.0), UX **View-für-View mit Vorschlägen** ohne Info-Verlust. Phasen: 1 i18n-Foundation → 2 EN-L10n → 3 A11y → 4 UX → 5 PWA → Release. F1008 (NKA) rückt auf v2.1.0. |
| 2026-06-10 | v2.0.0 ausgeliefert | Das gebündelte v2.0.0 (N1007 + EN-L10n + N1009 + UX + N1008) fertiggestellt und nach „Bereits ausgeliefert" verschoben. Full-Stack-i18n (DE/EN-Parität, `language`-Setting, Schema unverändert 1.3.0), Barrierefreiheit über alle 13 Views + Shell, UX-Politur, PWA (installierbar + offline). Zwei Bugs nebenbei behoben: PDF-/Backend-Texte folgen jetzt dem `language`-Setting statt `Accept-Language`; unsichtbarer Hellmodus-Text in der Zählerstands-Erfassung (`--fg-muted`-Fallback). Nächster Slot: F1008 (NKA, v2.1.0). |
| 2026-06-10 | v2.0.1 ausgeliefert (Patch) | Bugfix zu GitHub #16: Mit F1006 angelegte Zähler-Gruppen wurden nie im Dashboard angezeigt, obwohl „Gruppen (Dashboard-Summe)" zugesagt war (Backend lieferte `meter_groups`, Frontend renderte sie nie). Jede Utility-Karte mit gruppierten Zählern zeigt nun eine aufklappbare Gruppen-Summe (12-Monats-Verbrauch + ggf. Kosten je Gruppe). Reiner Frontend-Fix, kein F/N-Code, kein Schema-Bump. Der ebenfalls in #16 gewünschte „Vertrag pro Gruppe" bleibt als Feature offen. |
| 2026-06-28 | v2.1.1 ausgeliefert (Patch) | Bugfix zu GitHub #18: Für lieferbasierte Verbrauchsarten (Heizöl/Pellets) ließ sich über die UI kein Tank anlegen — das „Neuer Zähler"-Formular rendert jetzt die Pflichtfelder Tank-Kapazität + Anfangsbestand (Anlegen & Bearbeiten) und sendet sie an `createMeter`; bisher brach `MeterService::create()` mit „capacity > 0 Pflicht" ab. Reiner Frontend-Fix + 2 neue i18n-Keys (alle 7 Sprachen), kein Schema-Bump. Nebenbei: INSTALL-Versionsstempel von 2.0.1 auf 2.1.1 nachgezogen. |

---

[← Kompendium-Index](README.md)
