# Energietracker — Roadmap

> **Kurzfassung.** Jetzt: v2.14.0 — die Doku nach Zielgruppen. Als Nächstes:
> überarbeitete Diagramme (v2.15.0). Danach: Nebenkostenabrechnung für Mieter
> (F1008, [#15](https://github.com/Bingerminger/energietracker/issues/15)),
> Verträge je Zählergruppe ([#17](https://github.com/Bingerminger/energietracker/issues/17))
> und eine engere Home-Assistant-Anbindung. Bewusst nicht: eigene
> Smart-Meter-Auslesung (das macht Home Assistant), ein Cloud-Dienst, Konten.
>
> *In English:* now v2.14.0 (docs by audience); next revised charts (v2.15.0);
> then a utility-cost statement for tenants (#15), contracts per meter group (#17)
> and a deeper Home Assistant integration. Deliberately not: reading smart meters
> ourselves (Home Assistant does that), a cloud service, accounts.
>
> Der Rest dieser Seite ist das Planungsdokument mit Entscheidungen und
> Historie; es wird mit jedem Release fortgeschrieben. Bei Konflikt zwischen
> Reihenfolge und akutem Bedarf (etwa einem kritischen Fehler) gewinnt der
> Bedarf.

**Stand:** 2026-09-25 (synchron mit v2.14.0; Pakete A, B, Länderprofile, C, D und E aus dem Gesamtreview)
**Aktuelle Baseline:** v2.14.0
**Schema:** 1.6.0

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
| L10n-Welle-1 + Doku-i18n + Logos | 5 neue UI-Sprachen (fr, it, es, pt, nl) auf datengetriebener Registry (`languages.json`); zweisprachige Doku DE+EN; neues Logo-/Icon-Set | v2.1.0 | 2026-06-10 |
| F1011 | Analyse-Zäsur: `baseline_events` am Zähler; Auswertungen rechnen ab der baulichen Maßnahme (acht Verbraucher), Punkte davor ausgegraut statt entfernt, Vorher/Nachher-Kennzahl je Gradtag, Klartext-Hinweis für drei bis dahin stumme Untergrenzen; Schema 1.4.0 (GitHub #20) | v2.4.0 | 2026-08-20 |
| F1012 | Gas-Umrechnung mit Stichtagen: datierte Liste `gas_conversion_factors` (Zustandszahl × Brennwert je Periode, Faktor abgeleitet), tagesgenaue Teilung am Stichtag, Plausibilitätsbänder beim Speichern, Rechnungsprüfung `bill-check` Abschnitt für Abschnitt; Schema 1.5.0 (GitHub #21) | v2.5.0 | 2026-09-15 |
| N1013 | Verlässlich und sicher im Heimnetz: optionale Anmeldung (Passwort, Proxy, API-Schlüssel), Webserver-Regeln, CSP, Downgrade-Schutz, stabile Fehlercodes und Stabilitätszusage; Plausibilität der Zählerstände (Rückfragen, Ausreißer, Verdacht, Überlauf); Backup-Import mit Prüfung und Vorschau, Snapshot-Verwaltung. Schema unverändert 1.5.0 | v2.6.0 | 2026-09-25 |
| N1014 | Länderprofile für DE, AT, CH, FR, IT, ES, PT, NL, GB: Land, Währung (EUR, CHF, GBP), Zeitzone; Schreibweise aus Sprache und Land (auch im PDF); Erststart nach Browsersprache; Effizienzklasse nur mit Skala; Heizgrenze, CO₂-Faktor und Standort je Land; Brennwert in kWh/m³, MJ/m³ oder GJ/Smc, Umrechnungshilfe für Gaspreise je m³. Schema unverändert 1.5.0 | v2.7.0 | 2026-09-25 |
| F1013 | Rechnen wie die Abrechnung: Klimanormal am Standort (30 Jahre), Heizmodell `a × HGT + c × Tage` mit modellbasierter Bereinigung, Anomalien gegen die Erwartung je Monat (robust), Prognose mit Unsicherheitsband, Saldo nach Kalender mit Schätzung ab der letzten Ablesung und Abschlagsvorschlag, Temperaturen mit Quelle und täglichem Abgleich, stetiges Knickmodell, Beleg für die Wirkung einer Zäsur. Schema unverändert 1.5.0 | v2.8.0 | 2026-09-25 |
| F1014 | Verträge wie die Rechnung: Vertrag und Preise gelten ab ihrem Tag (Monate geteilt, `contract_parts`), ein Vertrag ohne Nachfolger läuft weiter, bis er gekündigt ist; Kündigungsfrist in Monaten, Wochen oder Tagen mit Kündigungsweise, Erinnerung am Kündigungsstichtag, verpasste Fristen und angekündigte Preiserhöhungen auf der Saldo-Karte; Schattenverträge im Rückblick für den ganzen Zeitraum; Zähler außer Betrieb zählen überall mit ihrer Historie. Schema unverändert 1.5.0 | v2.9.0 | 2026-09-25 |
| F1015 | Energieträger ehrlich: Tankbuch für Heizöl/Pellets (Stützstellen Anfangsbestand, „bis voll", Peilstand; eine Rechnung für Verbrauch, Kosten und Bestand; Durchschnittspreis des Tankinhalts), energieausweis-nahe Effizienzkennzahl neben der Hauskennzahl, Klassen nur für ganze Jahre, CO₂-Faktoren mit Quelle und Strom je Jahr (bisherige Defaults bei Bestandsinstallationen festgeschrieben), PV als Erlös und vermiedenes CO₂ mit Ersparnis durch Eigenverbrauch. Schema 1.6.0 | v2.10.0 | 2026-09-25 |
| N1015 | Mobil: Navigation in sieben Bereichen nach Nutzerfragen (Tabs je Bereich, Menüname = Seitentitel), Tab-Leiste mit ＋ Erfassen und Menü auf dem iPhone, Seiten Verträge & Abschläge / Rechnung prüfen / Jahresbericht, Router mit Token und Abbruch, Zurück-Taste schließt Dialoge, 44-px-Ziele, 16-px-Eingaben, Safe-Area/PWA, Kontraste nach WCAG AA mit Test, Theme dreistufig | v2.11.0 | 2026-09-25 |
| N1015 | Aufgeräumt (Teil 2): Einstellungen als neun Unterseiten mit Speicherleiste und Expertenbereich, Wetterdaten mit Ortssuche, „Zu tun" auf der Übersicht, schnellere Erfassung (Weiter-Taste, Feldfehler, Rückgängig, Direktsprung je Zähler), CSV-Import mit Vorschau, Rückgängig für Termine und Empfehlungen, einheitliche Seitenköpfe, Pluralformen und Benennungen | v2.12.0 | 2026-09-25 |
| N1016 | Versteht sich von selbst (Teil 1, App): Hilfe-Ansicht mit Glossar (33 Begriffe, sieben Sprachen), ⓘ-Erklärungen zum Antippen statt Tooltips, Willkommen mit Einrichtungs-Checkliste und Beispieldaten, Leerzustände, Saldo aus Kundensicht, PV als Einspeisung/Vergütung durchgehend, Feldhinweise in den Einstellungen, Quellenangabe der Wetterdaten; API additiv (`has_contracts`, `has_advance_payment_contracts`, `accounting_kind`, `reading_count`) | v2.13.0 | 2026-09-25 |
| N1016 | Versteht sich von selbst (Teil 2, Doku): Doku nach Zielgruppen (Einstieg, Anleitungen, Verstehen, Referenz, Betrieb, Entwicklung) mit Weiterleitungen für alle 61 alten Pfade, englischer Spiegel mit eigenen Screenshots; neu: Funktionen, FAQ, Handy, Jahresabrechnung, Fehlersuche, Webserver, Einstellungsreferenz; README als Schaufenster, CONTRIBUTING und Issue-Vorlagen; `DocsIntegrityTest` für Links, Anker, Spiegel, Index und Einstellungen | v2.14.0 | 2026-09-25 |

> **Lücke in dieser Tabelle:** v2.2.0 (Vollreview, Tarifvergleich neu, Assets
> selbst gehostet) und v2.3.0 (Tarifvergleich wird zur Wechselentscheidung)
> sind hier nicht geführt — beide sind ohne F-/N-Code entstanden, obwohl v2.3.0
> ein MINOR mit erheblichem Funktionsumfang war. Vollständig dokumentiert sind
> sie in [CHANGELOG.md](CHANGELOG.md) und in der Änderungs-Historie unten.

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
| **F1008** | NKA für Mieter (modulares Datenmodell, GitHub #15) | offen | L | 1.6.0 → 1.7.0 | **nächster Slot**, Detail-Konzept unten |
| *(Code offen)* | Verträge pro Zählergruppe (GitHub #17) | offen | M | additiv | aus F1006 offen geblieben („Vertrag pro Gruppe", s. v2.0.1); F-Code wird bei Übernahme vergeben |

> **Sprach-Wellen 2+** (cs, uk, pl, el, tr, hr, sr, sl, fi, no, da, lv, et, hu, bg, ro …)
> sind bewusst zurückgestellt (User-Entscheidung 2026-06-10) und werden
> bedarfsgetrieben in weiteren MINOR-Releases nachgereicht — je ~2–4 Sprachen,
> gleiche Methode (Katalog aus en.json, Parität-/Platzhalter-Check, in
> languages.json registrieren). Eine neue Sprache braucht keinen Code mehr.
>
> **Zweisprachige Doku (DE+EN)** ist ab v2.1.0 die Norm: README/INSTALL als
> `*.md` (EN) + `*.de.md` (DE), `docs/` (DE) mit Spiegel `docs/en/` am selben
> relativen Pfad. DE bleibt kanonisch; ein Test prüft seit v2.14.0, dass jede
> Seite ihren Spiegel hat.

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

→ Details in [CHANGELOG.md](CHANGELOG.md#170--2026-05-23--f1005-pv-einspeisung--erzeugung--autarkiequote-n1003-health-check) und
[`docs/verstehen/12-pv.md`](docs/verstehen/12-pv.md). Der ursprüngliche
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

→ Details in [CHANGELOG.md](CHANGELOG.md#171--2026-05-23--n1004-backuprestore-ui--demo-daten-und-doku-für-pv-nachgereicht). Auslieferung
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

## F1011 — Baseline-Zäsur: Analyse-Epochen (GitHub #20)

**Quelle:** GitHub-Issue #20, offen seit 2026-08-15, gestellt von einem Nutzer
außerhalb des Projekts — der erste Feature-Wunsch von außen. Er hat sein Haus
gedämmt und möchte die Gradtag-Korrelation erst ab der Sanierung rechnen
lassen; die Jahre davor sollen nicht mehr ins Modell eingehen.

**Problem — Strukturbruch.** Nach einer baulichen Maßnahme ist das Gebäude
thermisch ein anderes. Die Steigung der Heizkurve (Verbrauch je Gradtag) fällt,
der Sockel (Warmwasser, Kochen) bleibt. Eine Regression über beide Epochen
beschreibt keine von beiden: Sie liefert einen gewichteten Mittelwert aus
Vorher und Nachher — und zwar so lange, bis die neuen Punkte die alten
zahlenmäßig überwiegen. Bei einer zwölfjährigen Historie dauert das Jahre.

### Befund der Code-Durchsicht (2026-08-20)

Ein Zeitfenster existiert **nirgends**. `ConsumptionController::meter()`
filtert ausschließlich auf `hdd > 0` und Verbrauch `> 0`; die Analyse-Ansicht
hat keinen Zeitraumwähler. Betroffen ist aber weit mehr als der Chart:

| Wert / Auswertung | Ort | Zustand |
|---|---|---|
| `weather_adjusted` je Monat | `ConsumptionService::applyWeatherAdjustment` | **sauber** — reine HGT-Verhältnisnormierung `Wert × hdd_ref / hdd`, ohne Regressionsbezug |
| `delta_pct` | dieselbe Methode, Pass 2 | verzerrt — gegen `adjMean` = Mittel **aller** Monate |
| `expected_hgt` | dieselbe Methode, Pass 1 | verzerrt — Regression über die volle Historie |
| 5 Regressionsmodelle im Analyse-Chart | `ConsumptionController::meter()` | verzerrt — der Chart aus dem Issue |
| Prognose | `ForecastService` | verzerrt — Regression **und** saisonale Monatsmittel |
| Anomalie-Erkennung | `AnomalyService::detect` | verzerrt — die lineare Regression ist der Erwartungswert |
| Empfehlungen R1 + Trend | `RecommendationService` | verzerrt — Mehrverbrauch gegen das Gesamtmittel, Trend über die volle Reihe |
| Tarifvergleich / Wechselentscheidung | `TariffSwitchService` | verzerrt — setzt auf der Prognose auf |

Für den Nutzer heißt das konkret: Die App meldet seit der Sanierung **jeden
Monat** „unter dem Mittel", weil das Mittel die ungedämmten Jahre enthält. Die
Mehrverbrauchs-Empfehlung kann faktisch nicht mehr auslösen, auch wenn im
sanierten Haus wirklich etwas aus dem Ruder läuft. Und der erwartete
Jahresverbrauch — die Eingabe für jeden Tarifvergleich — fällt systematisch zu
hoch aus.

**Das ist dieselbe Klasse wie die F1006-Subzähler-Doppelzählung (v2.1.3):**
eine Bereichsregel, die in einem Aggregator steht und in den anderen nicht.
Eine Zäsur nur im Chart würde die Grafik richtig machen und Prognose,
Anomalien und Wechselentscheidung still falsch lassen.

### Was die Doku bereits verspricht

`docs/functional/08-szenario-eigenheim.md` §5 nennt „Vor/Nach einer Sanierung
messen" **die wertvollste Anwendung** und schreibt die Formel hin
(`Einsparung_echt ≈ Verbrauch_vorher_wetterbereinigt − Verbrauch_nachher_…`).
Ausgerechnet wird sie von nichts. Derselbe Befundtyp wie „Chart.js per CDN"
in v2.3.5: eine Doku-Behauptung ohne Deckung im Code.

### Lösungsskizze

Kein nacktes Startdatum, sondern eine **Zäsur** — ein Ereignis mit Datum und
Bezeichnung („Dachdämmung", „Wärmepumpe", „neue Fenster"). Drei Gründe:

1. **Daten bleiben sichtbar.** Punkte vor der Zäsur werden im Chart ausgegraut
   statt entfernt — ausgeschlossen wird aus dem *Modell*, nicht aus der Anzeige.
2. **Beide Segmente werden gefittet.** Die Differenz der Steigungen ist die
   wetterbereinigte Wirkung der Maßnahme: *„0,42 → 0,28 m³ je Gradtag, −33 %."*
   Damit wird aus einer Filterbitte genau das Feature, das die Doku als
   wertvollstes bewirbt — der Beleg, dass die Investition etwas gebracht hat.
3. **Verallgemeinerbar.** Heizungstausch, Fenster, Anbau — und beim Wasser die
   Personenzahl, für die es heute nur einen Gegenwartswert
   (`wasser_personen_anzahl`) ohne Historie gibt.

**Durchreichen ist Pflichtbestandteil**, nicht Ausbaustufe: Alle acht Zeilen
der Befundtabelle müssen die Zäsur kennen, sonst wiederholt sich v2.1.3.

### Getroffene Detail-Entscheidungen (2026-08-20)

**(1) Datenhaltung — Ereignisliste am Zähler.** Neues Feld `baseline_events`,
Default `[]`, nach dem Muster, das `devices` am selben Objekt schon vorlebt:

```json
"baseline_events": [
  { "date": "2021-09-01", "label": "Dachdämmung" },
  { "date": "2027-04-15", "label": "Wärmepumpe" }
]
```

- **Aktive Zäsur = spätestes Ereignis mit `date <= heute`.** Ein künftig
  datiertes Ereignis darf eingetragen werden und wirkt schlicht noch nicht —
  wer den Heizungstausch plant, kann ihn vormerken.
- **Leeres Array = heutiges Verhalten, exakt.** Das ist die Rückfalllinie für
  jede Regressionsprüfung.
- Mehrere Maßnahmen über die Zeit bleiben erhalten; ein Feld hätte die
  Dämmung überschrieben, sobald die Wärmepumpe kommt.
- **Backup braucht keine Änderung:** Das Feld reist in `meters.json` mit, und
  `BackupService` schreibt den Zähler-Topf als Ganzes. Ein eigener Datentopf
  hätte in die hartkodierte Feldliste gemusst — die Falle aus v2.1.2. Ein
  Roundtrip-Test hält es trotzdem fest.

**(2) Reichweite — überall, der Chart zeigt zusätzlich beide Segmente.**
Ein einziger Wahrheitsstand: Alle acht Zeilen der Befundtabelle rechnen ab der
Zäsur. Der Analyse-Chart stellt den Vorher-Fit zusätzlich ausgegraut dar; das
ist Darstellung, keine Ausnahme, und liefert nebenbei den Vorher/Nachher-
Vergleich aus (4). Je-Auswertung-Schalter wurden verworfen — sie erlauben
genau den Widerspruch, den F1011 beseitigen soll (Chart rechnet saniert, die
Wechselentscheidung nicht).

*Umsetzungsweg:* Die Monatszeilen bekommen **einmal** eine Markierung
`pre_baseline`, dort wo `ConsumptionService::markSwapMonths()` bereits
`device_swap` setzt. Jeder Verbraucher überspringt markierte Zeilen. Damit ist
die Propagation greifbar prüfbar — ein `grep pre_baseline` zeigt, wer die Regel
kennt und wer nicht. Genau das fehlte bei der F1006-Subzählerregel, die in
`forUtility` stand und in drei weiteren Aggregatoren nicht (v2.1.3).

**(3) Zu wenige Daten — Zäsur greift, die Oberfläche warnt.** Kein stiller
Rückfall auf die volle Historie: Der Nutzer sähe seine eingetragene Zäsur und
bekäme trotzdem den vermischten Mittelwert. Stattdessen meldet die API je
Auswertung, was fehlt, und die Oberfläche schreibt es aus — „Seit der Zäsur
liegen 7 Monate vor; Wetterbereinigung und Prognose brauchen mindestens 12."

Die drei Untergrenzen, die dabei sichtbar werden:

| Grenze | Ort | Folge heute |
|---|---|---|
| `< 12` Monate | `ConsumptionService:981` | Wetterbereinigung entfällt **komplett** — `weather_adjusted`, `delta_pct` und `expected_hgt` fehlen ersatzlos |
| `< 8` Regressionspunkte | `ConsumptionService:1008` | keine Regression, `expected_hgt` bleibt `null` |
| `< 5` gültige Monate | `AnomalyService:39` | keine Anomalie-Erkennung |

Alle drei greifen heute **wortlos**. Der Hinweis-Mechanismus wird deshalb
unabhängig von der Zäsur gebaut: Auch wer ohne Zäsur erst sieben Monate Daten
hat, bekommt künftig die Erklärung statt einer leeren Spalte. Dasselbe
Bauteil, kein Mehraufwand.

**(4) Umfang — Schnitt und Vergleich zusammen in v2.4.0.** Der Schnitt macht
die Zahlen richtig; der Vergleich ist das, wofür der Nutzer die Zäsur einträgt.
Beide Segmente werden gefittet und die Differenz der Steigungen ausgewiesen:
*„0,42 → 0,28 m³ je Gradtag, −33 %."* Die Steigung ist die Wetterbereinigung —
Verbrauch je Gradtag ist bereits witterungsnormiert. Damit deckt der Code, was
`docs/functional/08-szenario-eigenheim.md` §5 seit jeher verspricht; §5 wird im
selben Zug auf das Feature umgeschrieben, statt eine Handrechnung zu
beschreiben.

### Schema-Migration

Additiv, **1.3.0 → 1.4.0**, nach dem Muster von `needsV130Upgrade()`:
idempotente Prüfung per `array_key_exists('baseline_events', $m)`, fehlendes
Feld wird zu `[]` ergänzt. Ohne Zäsur ändert sich kein einziger Rechenweg.

**Validierung** (nach dem Vorbild von `normalizeExternalId`): ISO-Datum,
Duplikate je Zähler abgelehnt, Liste beim Schreiben nach Datum sortiert,
`label` optional mit Längenbegrenzung. Ereignisse sind einzeln editier- und
löschbar.

### Testliste

- **Je ein Test für jede der acht Zeilen der Befundtabelle**, dass die Zäsur
  greift — Chart-Regressionen, `expected_hgt`, `delta_pct`, Prognose
  (Regression **und** Saisonmittel getrennt), Anomalien, Empfehlung R1,
  Trend, Wechselentscheidung. Das ist die Versicherung gegen v2.1.3.
- `baseline_events: []` → Ergebnisse identisch zum heutigen Verhalten.
- Künftig datiertes Ereignis wirkt nicht; sobald sein Datum erreicht ist, doch.
- Mehrere Ereignisse: das späteste vergangene gewinnt.
- Jede der drei Untergrenzen einzeln: Hinweis kommt, kein stiller Rückfall.
- Hinweis erscheint auch **ohne** Zäsur bei zu kurzer Historie.
- Backup-Roundtrip trägt `baseline_events`.
- Vorher/Nachher-Kennzahl gegen eine handgerechnete Referenz.
- Migration ist idempotent (zweimal laufen lassen ändert nichts).

### Abgrenzung

Nicht Teil von F1011: automatische Erkennung des Bruchs aus den Daten
(Chow-Test o. Ä.). Der Nutzer weiß, wann er gedämmt hat — Raten wäre
schlechter als Eintragen.

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
  Detail-Konzept (entweder direkt in dieser Datei oder als eigene Seite unter
  `docs/verstehen/` bzw. `docs/entwicklung/`).
- **Konzept-Entscheidungen** klärt der Maintainer vor der Umsetzung — als
  Auswahl mit der Konsequenz jeder Option —, statt sie anzunehmen.
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
| 2026-06-28 | v2.1.2 ausgeliefert (Patch) | Stiller Datenverlust im Backup/Restore behoben: `BackupService::export()/import()` sicherten nur meters/readings/contracts — `deliveries` (Heizöl/Pellets, v1.3.0), `meter_groups` (F1006, v1.8.0) und top-level `reminders` fielen bei jedem Backup lautlos weg. Jetzt vollständig (isset-Guards = abwärtskompatibel zu alten Backups). Demo-Backup um je 3 Heizöl-/Pellets-Lieferungen ergänzt (Tanks waren im „Demo laden"-Pfad leer). 2 Regressionstests (Roundtrip + Demo-Restore), 93→95. Kein Schema-Bump. Bestätigt nachträglich die F1006-Planungsannahme „Backup zieht ohnehin alle JSON-Dateien", die nie zutraf. |
| 2026-06-28 | v2.1.3 ausgeliefert (Patch) | Systemische F1006-Subzähler-Doppelzählung behoben: `PdfReportService::yearAggregate` (PDF-Jahresbericht Verbrauch/Kosten/CO₂), `BenchmarkService` (Effizienzklasse kWh/m²·a) und Dashboard-`groupBreakdown` summierten über alle Zähler ohne den Subzähler-Ausschluss, den nur `forUtility::monthly_total` hatte → Eltern-Brutto + Sub doppelt. Alle drei gespiegelt. 1 Regressionstest (95→96). Reine Anzeige-/Report-Korrektheit, kein Datenverlust, kein Schema-Bump. Wurzel: Bereichsregel bei v1.8.0 nicht propagiert. |
| 2026-06-28 | v2.1.4 ausgeliefert (Patch) | Code-Sweep-Bündel: (A) Wasser-Schmutzwasser bei `basis='separater_zaehler'` ohne Zähler-Referenz rechnete still aufs Trinkwasser-Volumen statt 0 (nur via unvalidierte Daten/Backup-Import erreichbar, da der Speicherpfad eine Referenz erzwingt); (B) Lösch-Dialoge der Verbrauchsansicht auf gestyltes `confirmModal` statt native `confirm()`; (C) Liefer-Modal verknüpft Gesamt ↔ Menge×Preis live; (D) Sofort-Validierung der Tank-Kapazität. 1 Regressionstest (96→97) + Browser-Verifikation (B/C/D). Außerdem EN-Kompendium (06-release-process Lessons v2.1.1–2.1.4, 01-views Tank-Satz) auf DE-Stand nachgezogen — DE↔EN-Sync war seit v2.1.1 verrutscht. Kein Schema-Bump. |
| 2026-06-28 | v2.1.5 ausgeliefert (Patch) | Politur-/Robustheits-Bündel: (A) Monatschart nutzt `u.color` aus der SSOT statt hartkodierter 2-Farben-Palette (6/8 Verbrauchsarten waren blau); (B) Prognose-Chart verbindet Historie↔Prognose ohne Lücke; (C) Reminder-`markDone`/`suggestNextDelivery` clampen den Tag (PHP-`+N months`-Monatsende-Überlauf, 31.08.+6→28.02.); (D) `listWithStatus` fängt kaputtes `next_due` ab (kein 500). 3 Regressionstests (97→100) + Frontend-Verifikation. DE+EN-Lessons synchron. Kein Schema-Bump. |
| 2026-09-25 | v2.6.0 ausgeliefert (Minor, N1013) | **Anmeldung, Plausibilität, sichere Backups** — Paket B aus dem Gesamtreview. Optionale Anmeldung (Passwort, vorgeschalteter Proxy, API-Schlüssel mit Lese-/Verwaltungsrecht), Webserver-Regeln für Apache/nginx/Entwicklungsserver (`data/` und `.git/` waren auf Apache per HTTP erreichbar), CSP mit Nonce und `frame-ancestors`, Host-Freigabeliste, Downgrade-Schutz, Fehlerantworten ohne Interna mit stabilem `code`, Stabilitätszusage in drei Klassen. Plausibilität: Rückfragen bei der Erfassung, eingeklemmte Ausreißer fallen aus der Rechnung, fallende HA-Werte als Verdacht, Überlauf über die Stellen des Zählwerks. Backup-Import prüft vor dem Schreiben und zeigt eine Vorschau; Snapshots lassen sich auflisten, herunterladen, einspielen, löschen und werden rotiert. CSV-Import mit Kopfzeilen-Erkennung und Windows-1252. Health mit `status`/`checks` und 503. 285 → 324 Testmethoden; ein Test hält die API-Referenz mit den Routen des Codes synchron. Neues Kapitel „Sicherheit & Netzbetrieb", `SECURITY.md`. Nächstes Paket: Länderprofile. |
| 2026-09-25 | v2.7.0 ausgeliefert (Minor, N1014) | **Länderprofile** — auf User-Entscheidung aus Paket G vorgezogen („euro sprachen schon drin, länderprofile dazu“). Neun Länderprofile (SSOT `Config\Countries`), Einstellungen Land/Währung/Zeitzone/Brennwert-Einheit, Dialog beim Landeswechsel, Erststart nach `Accept-Language` (schreibt nur Abweichungen), Schreibweise aus Sprache und Land in Oberfläche und PDF, Effizienzklasse nur mit Skala, Zeitzone für Open-Meteo, Umrechnungshilfe für Gaspreise je m³. `GET /api/countries`. 324 → 346 Testmethoden. Übrige Themen aus Paket G (Druckansicht, CSV-Neuformat, Sprache je Gerät, Plural, Terminologie) bleiben dort. Nächstes Paket: C. |
| 2026-09-25 | v2.8.0 ausgeliefert (Minor, F1013) | **Rechnen wie die Abrechnung** — Paket C, erster Teil (C1: Wetter, Modelle, Saldo). Klimanormal am Standort (30 Jahre, nur Kennzahlen gespeichert), Heizmodell je Zähler mit Grundlast, `heat_adjusted`/`weather_delta_pct` statt der veralteten `weather_adjusted`/`delta_pct` (bleiben bis v3.0.0), Anomalien und Empfehlungen gegen die Erwartung je Monat mit robuster Streuung (Demo: 7 → 2 Empfehlungen, 22 → 7 Anomalien, die entfallenen waren Artefakte), Prognose mit Band und Klimanormal, Saldo nach Kalender mit Schätzung und Abschlagsvorschlag, Temperaturen mit Quelle und täglichem Abgleich (`weather_auto_fill` wirkt jetzt — README-Datenschutzsatz angepasst). Eine Regel für die Punkte der Heizkurve. 346 → 378 Testmethoden, 25 Gegenproben. Nächstes Paket: C2 (Verträge tagesgenau, Heizöl-Tankbuch, Effizienz, CO₂, PV). |
| 2026-09-25 | v2.8.1 ausgeliefert (Patch) | **Saldo ohne Messwerte** — Hotfix zu v2.8.0: Ein Vertrag mit Abschlägen an einem Zähler ohne zwei Ablesungen (oder mit allen Ablesungen vor einer frischen Zäsur) ließ `contract-status` mit HTTP 500 abbrechen, die Verbrauchsansicht zeigte nur die Fehlermeldung. Die Saldo-Hochrechnung rechnet dann ohne Schätzung. Aufgefallen beim Schreiben der Tests für Paket C2. 378 → 379 Testmethoden. |
| 2026-09-25 | v2.9.0 ausgeliefert (Minor, F1014) | **Verträge wie die Rechnung** — Paket C, zweiter Teil (C2a: Verträge). Tagesgenaue Vertrags- und Preisstichtage in Ist-Rechnung, Saldo und Prognose; weiterlaufende Verträge (`auto_renews`, `renewed`) statt 0 € nach einem vergessenen Ende; Kündigungsstichtag mit Frist in Monaten, Wochen oder Tagen und Kündigungsweise, Erinnerungen und Empfehlung relativ zum Stichtag, Hinweise auf verpasste Fristen und Preiserhöhungen (Sonderkündigungsrecht in DE); Schattenverträge im Rückblick für den ganzen Zeitraum; eine Definition für „außer Betrieb"; `dashboard_months` und `alert_days_since_reading` wirken, `min_temp_days_forecast` und `baujahr` veraltet; Abrechnungsstichtag geprüft; Terminkategorie Wärmezähler-Eichung. 379 → 398 Testmethoden, 26 Gegenproben. Nächstes: v2.10.0 (C2b: Heizöl/Pellets-Tankbuch, Effizienz, CO₂ datiert, PV), danach D. |
| 2026-09-25 | v2.10.0 ausgeliefert (Minor, F1015, Schema 1.6.0) | **Energieträger ehrlich** — Paket C, dritter Teil (C2b). Tankbuch statt Endbestand-0-Bilanz (eine Lieferung von heute änderte die Vorjahre um 20 %), Peilstände und „bis voll getankt" als Stützstellen, Kosten zum Durchschnittspreis inklusive Anfangsbestand; Effizienz mit zweiter, energieausweis-naher Zahl, Klassen nur für ganze Jahre, Grenzen einschließlich, Wärmepumpen-Strom; CO₂-Faktoren mit Quelle (BAFA, UBA je Jahr), Gas auf Brennwert, bisherige Defaults bei Bestandsinstallationen festgeschrieben und zur Übernahme angeboten; PV als Erlös und vermiedenes CO₂, Quoten über gemeinsam abgedeckte Monate, Ersparnis durch Eigenverbrauch. 398 → 434 Testmethoden, 36 Gegenproben. Paket C damit abgeschlossen; nächstes Paket: D. |
| 2026-09-25 | v2.11.0 ausgeliefert (Minor, N1015) | **Mobil** — Paket D, erster Teil (D1). Navigation nach Nutzerfragen in sieben Bereichen mit Tabs; auf dem iPhone Tab-Leiste, Menü als Schublade und Erfassen-Blatt statt 500 px Menüblock über jedem Inhalt; neue Seiten Verträge & Abschläge, Rechnung prüfen (bisher am Ende der Gas-Seite) und Jahresbericht (bisher in den Einstellungen); Router mit eigenem Container, Token und Abbruchsignal je Navigation (langsame Antworten überschrieben die nächste Seite); Zurück-Taste schließt Dialoge; Kontraste nach WCAG AA mit neuem Test; 44-px-Ziele, 16-px-Eingaben, Dialoge als Blatt; Safe-Area, `theme-color`, maskierbares Icon; Theme dreistufig; Einstellungen aktualisieren Zwischenspeicher und Seitenleiste. Kein Schema-Wechsel. Router- und Kontrast-Test neu, 19 Gegenproben. Nächstes: v2.12.0 (D2: Einstellungen als Unterseiten, Dashboard „Zu tun", Schnellerfassung, CSV-Vorschau). |
| 2026-09-25 | v2.12.0 ausgeliefert (Minor, N1015) | **Aufgeräumt** — Paket D, zweiter Teil (D2). Einstellungen als neun Unterseiten (Speicherleiste, Rückfrage beim Verlassen, Experte eingeklappt); Wetterdaten als einziger Ort für Standort und Wetter, mit Ortssuche (`GET /api/geocode`); „Zu tun" oben auf der Übersicht mit Sprung zur Erfassung je Zähler; Erfassung mit eingeklapptem Datum, Weiter-Taste, Feldfehlern, Zusammenfassung und Rückgängig; CSV-Import zweistufig mit Vorschau (`dry_run`); Rückgängig für „Erledigt" (`last_done` im PATCH) und „Ausblenden" (`DELETE …/dismiss`); einheitliche Seitenköpfe, Pluralformen, Monatsnamen, benannte Rückfragen, „Zu Gruppe zusammenfassen", „Angebot erfassen", Rückblick nur mit Jahren mit Daten; Vertragsformular mit Preiszeilen ab Vertragsbeginn. Behoben: Temperatur-CSV trennte am Dezimalkomma. Kein Schema-Wechsel. 434 → 444 Testmethoden, 27 Gegenproben. Paket D damit abgeschlossen; nächstes Paket: E (v2.13.0, „Versteht sich von selbst"). |
| 2026-09-25 | v2.13.0 ausgeliefert (Minor, N1016) | **Erklärt** — Paket E, erster Teil (E1, App). Hilfe-Ansicht (`#/help`) mit Erste-Schritte-Checkliste, Doku-Links je Sprache, Datenschutz und Glossar mit Suche; ⓘ an Kennzahlen, Spalten und Markierungen (Blase am Mac, Blatt am iPhone) statt Tooltips; Willkommen ohne Daten mit Beispieldaten; Leerzustände für Verbrauchsarten und Empfehlungen; Saldo als „Guthaben“/„Nachzahlung“ und in Tabellen + = Guthaben; PV-Einspeisung als Vergütung (Erlös, Erhalten, Rückforderung), Erzeugung ohne Kosten, Temperatur und HGT, vermiedenes CO₂ ohne Minus; 18 Feldhinweise, Prognosemodelle mit Namen, Breite des Prognosebands pflegbar, Stichtag PV-Einspeisung statt Heizöl/Pellets; Open-Meteo mit Lizenz in Temperaturen und PDF. API additiv, kein Schema-Wechsel. 444 → 448 Testmethoden, 26 Gegenproben. Nächstes: v2.14.0 (E2: Doku nach Zielgruppen). |
| 2026-09-25 | v2.14.0 ausgeliefert (Minor, N1016) | **Nachlesbar** — Paket E, zweiter Teil (E2, Doku). `docs/` nach Zielgruppen (Einstieg, Anleitungen, Verstehen, Referenz, Betrieb, Entwicklung), englischer Spiegel unter denselben Dateinamen, 61 alte Pfade als Weiterleitung; neue Seiten Funktionen, FAQ (20 Fragen), Handy, Jahresabrechnung, Fehlersuche, Webserver und Einstellungsreferenz (alle 60 Schlüssel); 17 englische Screenshots; README als Schaufenster (140 statt rund 600 Zeilen), CONTRIBUTING, Issue-Vorlagen; `ARCHITECTURE.md` in „Datenfluss & Algorithmen“ aufgegangen, SVG-Entwürfe entfernt; Englisch mit „unit price“/„standing charge“, Pluralformen in der Zählerübersicht; `scripts/init_data.py` als veraltet gekennzeichnet (`--help` startete den Import). Keine API-Änderung, kein Schema-Wechsel. 448 → 455 Testmethoden (`DocsIntegrityTest`, Pluralformen je `tp()`-Schlüssel). Repo-Beschreibung, Topics und Discussions (Review MKT-06, DOC-23) liegen beim Maintainer. Nächstes Paket: F (v2.15.0, Diagramme). |
| 2026-09-24 | v2.5.3 ausgeliefert (Patch) | **Keine stillen Fehlbuchungen** — Paket A aus einem Gesamtreview (Berechnungen, Oberfläche Mac/iPhone, Übersetzungen, Doku, API). Schwerpunkt: Eingaben, die bisher still in eine gültige Buchung verwandelt wurden (leeres Zahlenfeld → 0, „12,5" je nach Browser → nichts, HA-Sensor „unavailable" → Zählerstand 0 durch `float(0)` in der eigenen Vorlage, unlesbarer Stichtag → 01-01, leerer Vertrag → „aktiv"). Dazu CSRF-Schutz, Escaping gespeicherter Werte, Datumsprüfung auf allen Schreibpfaden, Beschädigung als 503 mit Quarantäne-Kopie, globale Schreibsperre, Snapshot vor Migration, iPhone-Überbreite (13 von 21 Ansichten → keine), Glossar-Vorzeichen, fachliche Fehlübersetzungen. Kein Schema-Bump, keine Schnittstellenänderung. 256 → 285 Testmethoden, zwei neue Node-Tests in der CI. Die weiteren Pakete folgen als eigene Releases: „Verlässlich und sicher im Heimnetz" (optionale Anmeldung), Länderprofile, „Richtig rechnen", „Mobil und aufgeräumt", „Versteht sich von selbst", „Auswertungen, die man sieht". |
| 2026-09-15 | v2.5.2 ausgeliefert (Patch) | UX-Politur an der Rechnungsprüfung: je Abschnitt **Stand alt / Stand neu** mit Ableseart wie die Fußnoten der Rechnung — abgelesen, als geschätzt erfasst (`S`), Ersatzwert (`E`, tagesgenau interpoliert, kein Stand über einen Zählertausch hinweg). Anlass: Beim Abgleich zweier echter Jahresrechnungen war nicht zu sehen, welche Abschnittsgrenzen auf echten Ablesungen ruhen und welche auf Schätzungen — bei der zweiten Rechnung vier von sieben Endständen. `bill-check` liefert `counter_*` + `counter_*_kind`. 254 → 256 Tests, der Browser-Test führt die Prüfung jetzt wirklich aus, 7 Toggles greifend. Doku DE + EN. Nächster Slot: F1008. |
| 2026-09-15 | v2.5.1 ausgeliefert (Patch) | UX-Politur ohne Code: Die Tabelle *Verträge & Abschläge* zeigt je Vertrag jetzt eine Spalte **Sonderzahlungen** (Netto aus Kundensicht, Einzelposten im Tooltip, Hinweistext; nur Gas/Strom/Fernwärme). Aufgefallen beim Nachrechnen einer echten Endabrechnung mit der neuen Rechnungsprüfung: Die gebuchte Gutschrift war in der Vertragshistorie unsichtbar, nur *Bonus* hatte eine Spalte. Drei Weichen per Multiple-Choice (eigene Spalte statt „Boni & Sonder"-Saldo — Bonus ist Vertragsbestandteil, Sonderzahlung Geld außerhalb des Plans; Netto mit Kundenvorzeichen; PATCH statt F-Code). `contract-status` liefert dafür `special_payments[]`. 250 → 254 Tests, 9 Toggles greifend, ein zehnter fand totes `abs()`. Doku DE + EN. Nächster Slot: F1008. |
| 2026-09-15 | v2.5.0 ausgeliefert (Minor) | **F1012 umgesetzt — Gas-Umrechnung mit Stichtagen.** Der eine Skalar `gas_conversion_factor` wird zur datierten Liste `gas_conversion_factors`: je Eintrag Stichtag, Zustandszahl, Brennwert, Faktor abgeleitet (fünf Nachkommastellen) oder direkt. Wirksam ist der letzte Eintrag mit Stichtag ≤ Tag; der undatierte ist der migrierte Altwert, die Historie rechnet exakt wie vorher. **Tagesgenau:** ein Stichtag mitten im Ableseintervall teilt es — `distributeToMonths` segmentiert jetzt an Monatsgrenzen *und* Stichtagen, der Rohverbrauch (m³) reist bis zur Anwendung der Felder mit, statt aus kWh zurückgerechnet zu werden. Plausibilitätsbänder greifen beim Speichern (0,8–1,1 · 8–13 · 5–15), beim Lesen bewusst nicht — ein Altbestand außerhalb wird nicht still ersetzt. **Rechnungsprüfung** `GET /api/utility/gas/meters/{id}/bill-check`: Abschnitte an jeder Ablesung und jedem Brennwertwechsel mit Tage · m³ · z · Hs · kWh/m³ · kWh, Lücken sichtbar statt aufgefüllt. Schema 1.4.0 → 1.5.0 über `UPGRADE_STEPS` (Lehre aus v2.4.1), Skalar → undatierter Eintrag, idempotent, vorhandene Liste gewinnt. 48 Katalogschlüssel × 7 Sprachen chirurgisch gesetzt, Demo-Daten mit vier Perioden (Verzeichnis + Backup, Gleichstands-Test). 230 → 250 Tests, **12 Kernannahmen per Toggle als greifend nachgewiesen** — zwei Tests wurden erst dadurch scharf. Doku DE + EN nachgezogen, README-Funktionsliste um F1011 und F1012 ergänzt. Nächster Slot: F1008. |
| 2026-09-15 | F1012 Detail-Entscheidungen getroffen | Sieben Weichen per Multiple-Choice mit dem User: (1) Speicherort **Einstellungen, datierte Liste** — nicht am Vertrag, nicht am Zähler: der Brennwert ist eine Netz-, keine Vertragsgröße. (2) Granularität: der User wollte zunächst einen Wert je Rechnung, seine eigene Jahresrechnung zeigte dann **drei Brennwerte mit eigenen Zeiträumen** bei konstanter Zustandszahl — daher (3) **Zustandszahl + Brennwert je Eintrag, Faktor berechnet**, Direkteingabe bleibt möglich. (4) **Tagesgenau** statt „ab dem Monat" — die Rechnung schneidet auch tagesgenau, nur mit geschätzten Zwischenständen. (5) **Nur Gas** in v2.5.0; Heizöl/Pellets behalten den Skalar. (6) **Rechnungsprüfung gleich mit** — die Rechnung ist die einzige Quelle für die Werte, also soll die App sie auch nachrechnen können. (7) Vor dem ersten Stichtag gilt **der migrierte Altwert ohne Datum**, kein Rückwärts-Vererben des ersten datierten. |
| 2026-09-15 | F1012 aufgenommen (GitHub #21) | Beim Bugfix v2.4.2 (m³-Etikett) stellte sich die eigentliche Frage hinter #21: Der eine Umrechnungsfaktor für die ganze Historie passt zu keiner Gasrechnung — dort wechselt der Brennwert mehrmals im Jahr. Wer nachzog, veränderte rückwirkend jeden Monat; wer stehen ließ, hatte kWh, Kosten je kWh, CO₂ und Tarifvergleich neben der Rechnung. Als F1012 mit Detail-Konzept aufgenommen: datierte Faktoren, Slot v2.5.0, Schema 1.4.0 → 1.5.0. F1008 rückt auf 1.5.0 → 1.6.0. |
| 2026-09-15 | v2.4.2 ausgeliefert (Patch) | Bugfix zu GitHub #21, dem zweiten Wunsch von außen: Die zentrale Zählerstand-Erfassung beschriftete das Gas-Eingabefeld mit „kWh", obwohl der Zähler Kubikmeter zählt — gespeichert und gerechnet wurde immer in m³, nur das Etikett war falsch. Ursache: `GET /api/readings-overview` lieferte seit F1004 (v1.6.0) nur `consumption_unit` (Verbrauch), nie `unit` (Zählerstand); die Maske hatte keine andere Wahl. Gas ist unter den kumulativen Verbrauchsarten die **einzige**, bei der die beiden Einheiten auseinanderfallen — deshalb fiel es sonst nirgends auf, und dort fünfzehn Releases lang nicht. Der Melder hatte das Verhalten als Feature-Wunsch formuliert („m³ eingeben dürfen") — es war ein Anzeigefehler, der genau dieses Verhalten verdeckte. Fix: Endpunkt liefert beide Einheiten, Maske nutzt `unit`. Drei Testschichten (PHPUnit gegen die SSOT, API-Shape, Browser-Render), per Toggle als greifend nachgewiesen. 227→230 Tests, keine Katalogänderung. |
| 2026-08-20 | v2.4.1 ausgeliefert (Patch, Hotfix) | **Die v1.4.0-Migration lief auf Bestandsdaten nicht.** `upgradeToV140()` war in `migrate()` eingehängt, aber nicht in `needsMigration()` — die Bedingung, die `migrate()` überhaupt auslöst, endete weiter bei v1.3.0. Auf einer Installation mit Schema 1.3.0 meldete sie deshalb „nichts zu tun", und der Bootstrap fiel in seinen dritten Zweig `!isAlreadyMigrated() → initFresh()`. Der schrieb `meta.json` auf die neue Version, ohne die Zähler anzufassen; danach galt die Installation als migriert, obwohl kein Zähler `baseline_events` trug — und die Migration konnte es nie mehr nachholen. **Nutzdaten waren nie in Gefahr** (`initFresh()` legt nur an, was fehlt): auf der betroffenen Instanz 48 von 49 Dateien byte-identisch, alle Zählungen unverändert, verändert war nur `meta.json` — erkennbar am `created_at` statt `migrated_at`. **Fix:** Die Stufen stehen jetzt in **einer** Liste (`Migrator::UPGRADE_STEPS`), aus der beide Methoden lesen. **Warum kein Test das fing:** Alle Migrationstests riefen `needsVXXXUpgrade()`/`upgradeToVXXX()` direkt auf, also an `needsMigration()` vorbei. Neu ist `MigrationCompletenessTest` mit einem Reflection-Wächter, der die Liste vollständig hält. 222→227 Tests, zwei Toggle-Beweise (einer stellt exakt den v2.4.0-Code wieder her und wird rot). Auf der betroffenen Instanz wurde `meta.json` aus der Sicherung zurückgeschrieben, damit die Migration sauber nachlaufen konnte. **Lehre:** Dieselbe Klasse wie Lehre 32 und 34 — eine Regel, die an zwei Orten gepflegt wird, driftet; hier innerhalb derselben Datei und am selben Tag, an dem die Lehre aufgeschrieben wurde. |
| 2026-08-20 | v2.4.0 ausgeliefert (Minor) | **F1011 umgesetzt — Analyse-Zäsur.** Am Zähler lassen sich `baseline_events` eintragen (Datum + Bezeichnung); wirksam ist die späteste erreichte, künftig datierte dürfen vorgemerkt werden, ein leeres Array verhält sich exakt wie vor v2.4.0. Die Zäsur greift in **allen acht** Auswertungen der Befundtabelle — über eine einmalige Zeilenmarkierung `pre_baseline` dort, wo schon `device_swap` gesetzt wird, statt über fünf einzeln nachgebaute Bedingungen. Punkte davor bleiben im Chart, nur ausgegraut. **Vorher/Nachher-Kennzahl** weist beide Heizkurven aus (Verbrauch je Gradtag, damit bereits witterungsbereinigt) — die Rechnung, die §5 des Szenario-Kapitels seit jeher als wertvollste Anwendung beschrieb, ohne dass sie jemand ausführte; §5 ist in DE und EN neu gefasst. **Drei bis dahin wortlose Untergrenzen** (12 Monate Wetterbereinigung, 8 Punkte Regression, 5 Monate Anomalien) melden sich jetzt im Klartext, auch ohne Zäsur bei schlicht zu kurzer Historie; ein stiller Rückfall auf die volle Historie wurde ausdrücklich verworfen. Nebenbei zusammengeführt: `ConsumptionService::regressionPoints()` entscheidet an **einer** Stelle, was ein Regressionspunkt ist — Chart, Wetterbereinigung und Vergleich fragten das vorher an drei Orten leicht verschieden. Schema 1.3.0 → 1.4.0 additiv; das Feld reist in `meters.json` im Backup mit, ein eigener Datentopf hätte in die hartkodierte `BackupService`-Liste gemusst (v2.1.2). 26 Katalogschlüssel × 7 Sprachen, chirurgisch gesetzt statt neu formatiert — die Kataloge sind handgepflegt, kein Dumper reproduziert sie byte-gleich. Demo-Daten führen die Zäsur vor (Verzeichnis **und** Backup, mit Gleichstands-Test). 196→222 Tests, **17 Kernannahmen per Toggle als greifend nachgewiesen**, dazu 28 Prüfungen über echtes HTTP und ein Browser-Durchlauf. Nächster Slot: F1008. |
| 2026-08-20 | F1011 Detail-Entscheidungen getroffen | Vier Weichen per Multiple-Choice geklärt. **(1) Datenhaltung:** Ereignisliste `baseline_events` am Zähler (Muster `devices`), aktive Zäsur ist das späteste Ereignis mit Datum ≤ heute — künftig datierte Maßnahmen dürfen vorgemerkt werden und wirken noch nicht; leeres Array = heutiges Verhalten. Am Zähler statt in eigener Datei, weil das Feld dann in `meters.json` automatisch im Backup mitreist; ein eigener Datentopf hätte in die hartkodierte `BackupService`-Feldliste gemusst (Falle aus v2.1.2). **(2) Reichweite:** gilt für alle acht Auswertungen, der Chart zeigt den Vorher-Fit zusätzlich ausgegraut. Je-Auswertung-Schalter verworfen — sie erlauben genau den Widerspruch, den F1011 beseitigen soll. Umsetzungsweg: eine einmalige Zeilenmarkierung `pre_baseline` dort, wo `markSwapMonths()` schon `device_swap` setzt, damit die Propagation per `grep` prüfbar ist. **(3) Zu wenige Daten:** Zäsur greift immer, die Oberfläche warnt im Klartext; stiller Rückfall auf die volle Historie ausdrücklich verworfen (der Nutzer sähe seine Zäsur und bekäme trotzdem den vermischten Mittelwert). Dabei werden drei heute **wortlose** Untergrenzen erstmals sichtbar — unter 12 Monaten entfällt die Wetterbereinigung komplett, unter 8 Punkten die Regression, unter 5 Monaten die Anomalie-Erkennung. Der Hinweis wird zäsur-unabhängig gebaut, damit auch eine schlicht zu kurze Historie erklärt wird. **(4) Umfang:** Schnitt und Vorher/Nachher-Kennzahl gemeinsam in v2.4.0; `docs/functional/08-szenario-eigenheim.md` §5 wird im selben Zug vom Handrechen-Rezept auf das Feature umgeschrieben. Status: bereit zur Umsetzung. |
| 2026-08-20 | F1011 aufgenommen (GitHub #20) | Ein Nutzer außerhalb des Projekts fragte nach einem Startdatum für die Gradtag-Korrelation — er hat sein Haus gedämmt und will die Jahre davor nicht mehr im Modell haben. Die Code-Durchsicht ergab: Ein Zeitfenster existiert **nirgends**, und der Schaden reicht weit über den Chart hinaus. Acht Auswertungen hängen an einer gemeinsamen Basislinie über die volle Historie — Regressionen im Analyse-Chart, `expected_hgt`, `delta_pct`, Prognose (Regression *und* Saisonmittel), Anomalie-Erkennung, Empfehlungen R1 samt Trend, und darüber die Wechselentscheidung. Einzig `weather_adjusted` je Monat ist sauber, weil es die Regression nie berührt. Praktische Folge für den Betroffenen: dauerhaft „unter dem Mittel", Mehrverbrauchs-Empfehlung faktisch stumm, Jahresverbrauch für den Tarifvergleich systematisch zu hoch. **Dieselbe Klasse wie die F1006-Subzähler-Doppelzählung (v2.1.3)** — eine Bereichsregel, die nicht in alle Aggregatoren propagiert wurde. Als F1011 mit Detail-Konzept aufgenommen (Zäsur mit Datum und Bezeichnung statt nacktem Filter, alte Punkte ausgegraut statt entfernt, beide Segmente gefittet → wetterbereinigte Wirkung der Maßnahme). Vier Detail-Entscheidungen offen. Nebenbefund: `docs/functional/08-szenario-eigenheim.md` §5 nennt „Vor/Nach einer Sanierung messen" seit jeher **die wertvollste Anwendung** und schreibt die Formel hin — ausgerechnet wird sie von nichts (Klasse „Doku-Behauptung ohne Deckung", vgl. v2.3.5). Slot: v2.4.0, Schema 1.3.0 → 1.4.0. F1008 rückt dahinter. |
| 2026-08-20 | Roadmap-Kopf und Planungstabelle nachgezogen | Der Kopf stand seit dem 2026-06-10 auf **Baseline v2.0.1 / Schema 1.1.0**, während ausgeliefert v2.3.5 mit Schema 1.3.0 läuft — 18 Releases Rückstand, und die Schema-Angabe lag zwei Bumps daneben (F1006 → 1.2.0, F1009 → 1.3.0). In der Planungstabelle stand die L10n-Welle-1 noch als „in Arbeit", obwohl sie am 2026-06-10 mit v2.1.0 ausgeliefert wurde, und F1008 trug den Slot v2.2.0, der längst mit anderem Inhalt vergeben ist. Kopf auf v2.3.5/1.3.0 gesetzt, L10n-Zeile nach „Bereits ausgeliefert" verschoben, F1008 auf „offen" gestellt, GitHub #17 (Verträge pro Zählergruppe) als sichtbare Zeile ohne Code ergänzt. In „Bereits ausgeliefert" bleibt eine benannte Lücke: v2.2.0 und v2.3.0 sind ohne F-/N-Code entstanden, obwohl v2.3.0 ein MINOR mit erheblichem Funktionsumfang war. |
| 2026-08-03 | v2.3.5 ausgeliefert (Patch) | **README zeigt, was das Projekt kann.** Statusabzeichen in DE und EN: CI und Docker-Publish als Live-Badges aus GitHub Actions, dazu Version, Lizenz, PHP, Testzahl, PWA, Docker-Architekturen, Sprachen, Verbrauchsarten und Abhängigkeiten. Vorher drei Abzeichen; die Eigenschaften, die das Projekt ausmachen (PWA, multi-arch, 7 Sprachen, 8 Verbrauchsarten, **0 Laufzeit-Abhängigkeiten**), standen nur im Fließtext. **Nebenbefund und behoben:** Beide READMEs schränkten „keine externen Abhängigkeiten" mit „außer Chart.js per CDN" ein — seit v2.2.0 falsch, Chart.js und Schriften liegen unter `public/vendor/`. Zwei neue Tests halten die Abzeichen ehrlich: Die Zahlen für Tests/Sprachen/Verbrauchsarten werden gegen die Wirklichkeit gezählt (der Test schlug beim ersten Lauf sofort an — er zählte 196, das Abzeichen sagte 194, weil er sich selbst mitzählte), und die CI-Abzeichen müssen auf existierende Workflows zeigen. 194→196 Tests. |
| 2026-08-03 | v2.3.4 ausgeliefert (Patch) | **Release-Prozess entrümpelt**, DE+EN. (A) Der **ZIP-Bau** ist entfallen: Seit v2.0.0 trägt kein Release mehr Anhänge, die Installation läuft über GHCR, `git clone` oder `git checkout` eines Tags — die Beschreibung stand seit v1.4.2 unverändert da und beschrieb einen Ablauf, den es nicht mehr gab. (B) Das **CI-Gate steht jetzt ausdrücklich zwischen Push und Tag**; bisher zeigte die Anleitung `git push origin main --tags`, wodurch der Tag entsteht, bevor die Pipeline ihn bestätigt hat — und er ist die Grundlage für Image und Release. (C) Der Smoke-Test läuft gegen eine Kopie der Demo-Daten über `ET_DATA_DIR`, nie gegen das lokale `data/`. Zeitgleich intern harmonisiert: Der Release-Zug steht an **einem** Ort (Skill `workflow` §0) und nennt für jede Station ihr Makro (`/git-commit-acc`, `/git-commit-prod`); der lokale Testlauf existierte doppelt (Codeblock **und** Skript, bereits auseinandergelaufen) und ist jetzt nur noch das Skript. Kein Code betroffen, 194 Tests unverändert. |
| 2026-08-03 | v2.3.3 ausgeliefert (Patch) | **Beispieldaten in der Dokumentation.** `MIGRATION-FROM-V090.md` (DE+EN) enthielt seit dem Initial Public Release Datensätze aus einer realen Installation statt erfundener Beispiele — Vertrags-/Ablese-IDs, Anbieter- und Tarifname, ein Zählerstand, ein Abschlagsbetrag und eine Nummer im `notes`-Feld, die wie ein Zählpunkt aussieht. Ebenso beschrieben CHANGELOG und Roadmap Befunde mit Vertragslaufzeiten und Beträgen. Alles durch fiktive Werte ersetzt bzw. entfernt, Struktur und Sachverhalt unverändert. Die Release-Notes von v2.3.1 und v2.3.2 wurden auf GitHub nachträglich ersetzt. **Nutzdaten waren nie versioniert** — unter `data/` liegen nur vier leere `.gitkeep`, `.gitignore` deckt alle Datentöpfe ab, die Historie enthält nie etwas anderes. **Regel:** Befunde aus einer konkreten Installation gehören nicht mit Laufzeiten, Anbietern, Nummern oder Beträgen in Repository, Commit-Message oder Release-Notes — Sachverhalt beschreiben, Daten weglassen. Kein Code betroffen, 194 Tests unverändert. |
| 2026-08-03 | v2.3.2 ausgeliefert (Patch) | Ergebnis eines Qualitätsdurchlaufs über Code, Kataloge und Doku. Befund: `ContractService::findActiveForDate()` nahm bei überlappenden Verträgen den **ersten Eintrag des Arrays** — dessen Position ergibt sich aus der Anlage-Reihenfolge in der JSON-Datei, nicht aus der Fachlichkeit. Dieselbe Datenlage konnte damit unterschiedliche Kosten ergeben. Jetzt gewinnt der **späteste Beginn** (ein neuer Vertrag löst den älteren ab); ohne Überlappung unverändert. Vier Services hängen daran (Verbrauch, Prognose, Tarifvergleich, Wechselentscheidung). Der Fall ist praxisrelevant: Ein Vertrag, der vollständig in der Laufzeit eines anderen liegt, entsteht leicht beim Nachtragen älterer Verträge. `OverlappingContractsTest` (5 Fälle), 189→194, per Toggle als greifend nachgewiesen. **Lehre aus diesem Release:** Befunde aus einer konkreten Installation gehören nicht mit Laufzeiten, Anbietern oder Beträgen in CHANGELOG, roadmap, Commit-Message oder Release-Notes — das Repository ist öffentlich. Sachverhalt beschreiben, Daten weglassen. |
| 2026-08-03 | v2.3.1 ausgeliefert (Patch, Hotfix) | Zwei Fehler aus v2.3.0, beide **erst im laufenden Betrieb** aufgefallen — nicht im Test. (A) **„api.tariffSwitch is not a function"**: Der Cache-Buster hing nur an `app.js`, die Module importieren einander ohne Query. Der v2.2.3-Fix griff nicht, weil er die Cache-API räumt, nicht den HTTP-Cache — und Prod sendete für Module kein `Cache-Control`, nur ETag/Last-Modified; Safari cachte heuristisch. Jetzt **Import-Map** (aus dem Dateibestand erzeugt, versioniert jeden Modulpfad ohne eine einzige `import`-Zeile zu ändern) plus Cache-Header in `.htaccess` und Docker-nginx. (B) **Folgeverträge übergangen**: Lief ein Anschlussvertrag bereits, meldete das Modul dessen Beginn als Wechseltermin und rechnete die Referenz mit den Preisen des auslaufenden Vertrags weiter — je nach Preisunterschied ein dreistelliger Betrag pro Jahr. Jetzt folgt der Vergleich der **Bindungskette** (laufender Vertrag + lückenlose Anschlüsse); deren Ende bestimmt Termin und Fenster, jeder Monat rechnet mit dem dann gültigen Tarif, eine Lücke > 1 Tag beendet die Kette. (C) Kündigungsfrist, Mindestlaufzeit und Preisgarantie sind jetzt **im Vertragsformular** pflegbar — vorher nur beim Anlegen eines Angebots, an Bestandsverträgen also unerreichbar. 183→189 Tests, Kettenlogik per Toggle als greifend nachgewiesen. **Wurzel bei (A):** In v2.2.3 als Ursache benannt („Module werden ohne ?v= importiert") und trotzdem nur symptomatisch behandelt. **Bei der Abnahme von v2.3.0 wurde `app.js` geprüft statt `api.js`** — also gerade nicht die geänderte Datei. |
| 2026-08-03 | v2.3.0 ausgeliefert (Minor) | **Tarifvergleich wird zur Wechselentscheidung.** Auf User-Ansage („der wesentliche Part des gesamten Programms") vom Rückblick auf die Handlung umgestellt: Der erwartete Jahresverbrauch aus der Prognose steht groß und kopierbar oben — genau die Eingabe, die CHECK24/Verivox verlangen; der Nutzer sucht **selbst** (Portal-API ausdrücklich nicht gewünscht) und trägt das Angebot als Schattenvertrag ein. Vier Weichen vorab entschieden: Wechseltermin aus Vertragsende + Kündigungsfrist (neue optionale Felder `notice_period_months`, `min_term_end`, `price_guarantee_until`, im Vergleich überschreibbar) · Fenster **12 Monate ab Wechseltermin**, saisonal gewichtet statt in Zwölfteln · Bonus als Betrag (`signup_bonus_eur`) mit **Rangfolge nach dem dauerhaften Preis** (sonst gewinnt jedes Lockangebot) · Referenz ist der fortgeschriebene Bestandsvertrag. Dazu **Break-even-Verbrauch** statt Euro-genauer Ersparnis, ±10 %-Spanne, und ein Overlay-Chart, das Angebote über den Bestandsvertrag legt (Monate jenseits der Preisgarantie gestrichelt). Neuer `TariffSwitchService` + Endpoint `tariff-switch`; `TariffComparisonService` bleibt als eingeklappter Beleg-Block auf echten Monaten. **Ein Fehler entstand und wurde vor Auslieferung gefunden:** `strtotime('2026-03-31 -1 month')` liefert 2026-03-03 (Monatsüberlauf) — die Kündigungsfrist hätte um vier Wochen danebengelegen; Monatsarithmetik klemmt jetzt auf das Monatsende. 56 neue Katalogschlüssel × 7 Sprachen, strukturell über den JSON-Baum gesetzt (nicht per Textanker wie in v2.2.0) und mit formaterhaltendem Dumper geschrieben. Demo-Daten um ein vollständiges Wechselszenario ergänzt. `TariffSwitchServiceTest` (17 Fälle), 164→181; alle fünf Kernannahmen per Toggle als greifend nachgewiesen. Kein Schema-Bump (1.3.0). |
| 2026-08-03 | v2.2.3 ausgeliefert (Patch, Hotfix) | **Oberfläche blieb nach dem Update bei „Lädt…".** Die ES-Module importieren einander ohne Cache-Buster (`./lib/sidebar.js`); der Service Worker lieferte sie unter `stale-while-revalidate` aus dem alten Cache, während die Shell schon neu war → eine frische `app.js` importierte `refreshSidebarBadges` aus einem gecachten `sidebar.js` der Vorversion, das diesen Export nicht kennt → `SyntaxError`, Modulgraph komplett abgebrochen. Betraf jede Installation mit aktivem Worker, also ACC und Prod nach dem v2.2.2-Rollout. **Zwei Maßnahmen:** (1) Selbstheilung in der Shell — trägt ein Cache eine andere Version, werden Caches und Worker abgeräumt und genau einmal neu geladen (Sperre in `sessionStorage`); bestehende Installationen reparieren sich beim nächsten Aufruf selbst. (2) `/public/js/` und `/public/locales/` laufen jetzt **network-first**; Stile, Schriften und Chart.js bleiben stale-while-revalidate. Nachgestellt (v2.1.5 mit Worker → v2.2.2 = Fehler reproduziert; mit Fix heilt derselbe Browser beim ersten Aufruf, keine Konsolenfehler). Neuer `ModuleCacheSafetyTest` (3 Fälle) hält beide Maßnahmen und die Auflösbarkeit aller Modul-Importe fest. 161→164 Tests. **Wurzel:** Das Loch war im Review vom selben Tag benannt („Module werden ohne ?v= importiert") und blieb ungeschlossen — der Cache-Buster hing nur an `app.js`. |
| 2026-08-03 | v2.2.2 ausgeliefert (Patch) | (A) **Demo-Daten um Termine ergänzt**: weder `demo-data/reminders.json` noch das Demo-Backup führten Einträge → die Termin-Ansicht blieb nach „Demo laden" leer. Dieselbe Klasse wie die fehlenden Heizöl-/Pellets-Lieferungen in v2.1.2 (feste Feldliste im Backup, neuer Datentopf nicht mitgezogen). Jetzt 6 Termine über 5 Kategorien mit überfälligem/fälligem/ruhendem Eintrag. (B) **Die letzten beiden Dienste ohne Test haben einen**: `MigrationServiceTest` (10 Fälle — Formaterkennung, Übersetzung, Zählerwechsel-Heuristik, beide Schreibmodi, Sicherheitskopie) und `PdfReportServiceTest` (5 Fälle — inkl. der v2.1.3-Subzähler-Regel, die im Bericht bisher nur „analog" abgedeckt war; die Prüfung liest die gedruckten Zahlen direkt und meldet ohne Ausschluss 1.680 statt 1.200 kWh). `DemoServiceTest` um 3 Fälle erweitert. **Alle 29 Dienste sind jetzt getestet**; 143→161 Tests (721 Assertions). Kein Schema-Bump. |
| 2026-08-03 | v2.2.1 ausgeliefert (Patch) | Nachzug zum Vollreview. (A) **HTTP-Status hing an der Anzeigesprache**: `ErrorHandler::statusFor()` erkannte „nicht gefunden" am Wortlaut — seit v2.0.0 werfen die Dienste lokalisiert, sodass Spanisch („no encontrado") und Französisch („introuvable") **500 statt 404** ergaben. Jetzt typisiert über `Http\NotFoundException`, Textprüfung bleibt als Rückfall. (B) **Restliche Backend-i18n**: alle acht Controller bekommen den I18nService; Zähler-/Vertragsmeldungen, Token-Hinweis, v0.9.0-Migrationsrückmeldungen, Temperatur- und CSV-Importfehler sowie die Kopfzeilen des Monatsexports folgen jetzt der Sprache — ebenso die Bezeichner in den Vertragsprüfungen. Bewusst deutsch bleiben JsonStore (Zirkelabhängigkeit I18n→Settings→JsonStore), das Migrationsprotokoll in `meta.json` (Betriebsdoku) und Ausnahmen für Programmierfehler; der Router antwortet jetzt englisch. (C) **Alle 13 Screenshots neu aufgenommen** — die alten stammten aus v1.9.2 und zeigten u. a. den Tarifvergleich mit dem in v2.2.0 behobenen Rechenfehler; trotz größerem Ausschnitt 824 KB statt 3,4 MB. 139→143 Tests. Kein Schema-Bump. |
| 2026-08-03 | v2.2.0 ausgeliefert (Minor) | Vollständiges Review von Code, Oberfläche, Sprachen, Tests und Doku. **Vier stille Rechenfehler**: (0a) `ForecastService` filterte `is_shadow` nicht — sobald der letzte echte Vertrag vor dem Prognosehorizont endete, übernahm ein Schattenvertrag die Preis-/Abschlagsprojektion (7 von 12 Monaten, 797 € statt ~85 €/Monat); (0b) `drawMonthChart` las hart `m.kwh` → Wasser-Monatschart war eine Nullreihe (Rest von Fix #14); (0c) `collectWaterForm` machte aus einem leeren Preisfeld via `parseFloat(x \|\| 0)` einen Tarif von 0 ct/m³; (0d) `lib/format.js` bildete nur de/en ab, die 2026 ergänzten Sprachen bekamen deutsche Zahlen, Datumstrennung und Monatskürzel. Dazu: (1) **Tarifvergleich neu aufgesetzt**: jede Kennzahl bezieht sich auf die Monate, die der Vertrag wirklich abdeckt (vorher Gesamtverbrauch neben Teilzeitraum-Kosten → ein Halbjahrestarif wies 49 % Ersparnis aus, wo real ~15 % waren); neue Spalte „ct/Einheit" als zeitraumunabhängiger Maßstab; Hochrechnung auf die volle Periode; Differenz gegen dieselben Monate statt gegen die Summe aller echten Verträge; Schattenverträge im Modul bearbeit- und löschbar samt Ende-Datum; Balkendiagramm; Einheit aus der SSOT. Abschläge und Sonderzahlungen bleiben bewusst draußen (Zahlungsströme, keine Tarifkosten). (2) **Utility-Farben aus der SSOT zur Laufzeit** — die handgepflegten CSS-Token kannten nur gas/strom/wasser, die fünf später ergänzten Arten hatten weder Überschriften-, Button-, KPI- noch Sidebar-Farbe; Gas und Strom wechseln sichtbar auf ihre SSOT-Farbe. (3) **A11y**: Textkontrast auf WCAG AA (war 3,7–4,4:1), Toasts schließbar und assertiv, Dialoge setzen den Hintergrund inert und sperren das Scrollen. (4) **Ablesungstabelle folgt der Jahresauswahl** (mit HA-Ingest sonst tausende Zeilen), Prognose/Analyse nur aktive Arten, Ungespeichert-Marker in den Einstellungen, Sidebar-Badges nachgelagert (erster Inhalt brauchte vier serielle Roundtrips). (5) **Schriften und Chart.js selbst gehostet** (260 KB, OFL/MIT) — keine IP mehr an Dritte, erster Offline-Start vollständig; SW precacht die Shell; Cache-Buster an VERSION. Dabei entdeckt und behoben: `vendor/` in .gitignore hätte `public/vendor/` verschluckt. (6) **i18n** der sichtbaren Backend-Texte + zentrale Helfer `utilityLabel()`/`defaultMeterName()`. 130→134 Tests inkl. `ReleaseConsistencyTest`, der Versionsstempel und externe Verweise maschinell prüft. Kein Schema-Bump. |

---

[← Kompendium-Index](README.md)
