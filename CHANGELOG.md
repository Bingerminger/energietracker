# Changelog

Alle nennenswerten Änderungen werden hier dokumentiert. Format orientiert
sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/) und
[Semantic Versioning](https://semver.org/lang/de/).

---

## [1.4.5] — 2026-05-17 — CI: Node-24-Action-Runtime

### Fixed

- **GitHub-Actions-Deprecation-Warnung behoben.** `actions/checkout`
  und `actions/setup-node` von `@v4` auf `@v5` gehoben. Die `@v4`-Tags
  laufen intern auf Node.js 20, das von GitHub deprecated wurde (ab
  2026-06-02 ist Node 24 Runner-Default, ab 2026-09-16 wird Node 20
  entfernt). `@v5` ist die etablierte Node-24-Baseline. **Kein
  funktionaler Eingriff** — die CI lief auch mit `@v4` grün durch
  (es war eine Warnung, kein Fehler); der Bump entfernt sie und macht
  die Pipeline zukunftssicher.
- Bewusst **nicht** geändert: `node-version: "20"` im Test-Job (das ist
  die Node-Version für die *Testausführung*, nicht die Action-Runtime
  — die Test-Harnesses sind auf Node ≥ 20 ausgelegt) sowie
  `shivammathur/setup-php@v2` (von der Deprecation nicht betroffen,
  in GitHubs Warnung nicht gelistet).

---

## [1.4.4] — 2026-05-17 — Code-Qualität, CI-Pipeline, Audit-Härtung

### Added

- **CI/CD-Pipeline (GitHub Actions).** Neues `.github/workflows/ci.yml`
  mit zwei Jobs:
  - `test` — startet den PHP-Backend-Server mit Demo-Daten, führt
    `frontend-api-shape.test.js` und `browser-render.test.mjs` aus.
  - `lint-php` — prüft die Syntax aller PHP-Dateien parallel mit
    `php -l` (PHP 8.4). Beide Jobs laufen auf `push` und `pull_request`
    gegen `main`.
- **`ET_DATA_DIR`-Umgebungsvariable in `api.php`.** Der Storage-Pfad
  ist jetzt per ENV überschreibbar (Fallback: `./data`). Ermöglicht
  CI-Tests gegen `/tmp/etdata` ohne Änderung an produktivem Code.
- **`DeliveryConsumptionService` (neue Klasse).** Die drei Delivery-
  Berechnungsmethoden (`dailyDeliveryConsumption`, `dailyDeliveryStockDraw`,
  `deliveryMeterStartDate`) wurden aus `ConsumptionService` in eine
  eigenständige Klasse extrahiert. `ConsumptionService` delegiert über
  schlanke Wrapper; das öffentliche API bleibt vollständig kompatibel.
  Der interne Dispatch (`computeForDeliveryMeter`) nutzt einen
  Lazy-Getter, der auch funktioniert, wenn `DeliveryConsumptionService`
  nicht explizit injiziert wird — kein Breaking Change für bestehende
  Aufrufer. `ConsumptionService` schrumpft dadurch um ~350 Zeilen.

### Fixed

- **`JsonStore::path()` Traversal-Schutz (Defense-in-Depth).** Nach dem
  Pfadaufbau wird per `realpath` + Präfix-Check geprüft, dass der
  resultierende Pfad innerhalb von `rootDir` liegt. Bisher war nur der
  Service-Layer-Whitelist-Check in `Utilities::exists()` aktiv; der neue
  Schutz auf Speicherebene ist unabhängig davon und fängt jeden Pfad ab,
  der aus dem Datenverzeichnis ausbricht.
- **`DiagnosticsService` Fallback-Version.** `'1.2.0'` → `'unknown'`.
  Der Fallback tritt nur auf, wenn die `VERSION`-Datei fehlt; eine
  konkrete veraltete Versionsnummer war irreführend.
- **Demo-Daten `schema_version`.** `demo-data/meta.json` wurde auf
  `"schema_version": "1.1.0"` aktualisiert und `demo-data/reminders.json`
  angelegt. Damit startet eine Demo-Instanz ohne unnötige
  Migrations-Durchläufe und der Migrator-Status ist konsistent mit dem
  tatsächlichen Datenstand.

### Changed

- **`tests/backend-shape.test.js` → `tests/frontend-api-shape.test.js`.**
  Datei umbenannt — der neue Name spiegelt die tatsächliche Perspektive
  wider (Frontend-seitige Erwartungen an die API-Shapes, nicht
  Backend-interne Prüfung). Leerer `loadModule`-Stub entfernt (war
  toter, nie aufgerufener Code). `tests/README.md` entsprechend
  aktualisiert.
- **Stale Versions-Kommentare bereinigt.** `utility.js` (trug `v1.0.2`),
  `api.js` (`v1.2.0`) und `api.php` (`v1.2.0`) trugen veraltete
  Versionsnummern im Datei-Header. Kommentare auf neutralen Text ohne
  konkrete Versionsnummer umgestellt — die kanonische Quelle ist
  `VERSION`.

### Internal

- `src/bootstrap.php` initialisiert `DeliveryConsumptionService` explizit
  vor `ConsumptionService` und übergibt es als Abhängigkeit.
- `ConsumptionService`-Konstruktor hat neuen optionalen Parameter
  `?DeliveryConsumptionService $deliveryConsumption = null` (abwärtskompatibel).

---

## [1.4.3] — 2026-05-16 — Sigmoid in der Analyse, Vertragslogik je Energieart, valides Doku-Markdown, korrekter App-Name

### Fixed

- **#1 Sigmoid-Kurve fehlte im Analyse-Korrelationschart.** Der
  HGT-Streudiagramm-Block iterierte nur über vier Modelle (linear,
  polynomial, robust, segmentiert). `sigmoid` ist jetzt ergänzt: in der
  Vorhersagefunktion (spiegelt `RegressionService::sigmoidPredict`
  exakt — numerisch verifiziert, Abweichung < 1e-9), im Kurvenstil, in
  der Modell-Iteration und in der R²-Koeffizienten-Übersicht.
- **#2 Vertrags-Views ergaben für lieferbasierte Arten keinen Sinn.**
  Heizöl/Pellets haben per Logik keine Verträge (die Tankrechnung ist
  die Kostenbasis). Behoben an drei Stellen:
  - `utility.js` blendet die Vertrags- und Saldo-Karte für
    lieferbasierte Arten aus.
  - Die Vertrags-View (`contracts.js`) zeigt für Heizöl/Pellets einen
    erklärenden Hinweis statt eines (sinnlosen) Gas-Vertragsformulars
    und leitet zu den Lieferungen.
  - Der Tarifvergleich (`tariff.js`) schließt lieferbasierte Arten aus
    (Schattenverträge sind dort nicht anwendbar) — zusätzlich zum
    bereits ausgeschlossenen Wasser.
  - Geprüft und bestätigt: **Fernwärme** ist korrekt kumulativ mit
    echten Verträgen (Arbeits- + Grundpreis) — bleibt unverändert.
- **#3 Doku-Markdown wurde teils falsch dargestellt.** Alle
  LaTeX-Formeln (`$…$` / `$$…$$`) wurden durch GitHub-sichere
  Klartext-Codeblöcke ersetzt (rendern überall identisch). Das
  Architektur- und das Analysezyklus-Diagramm wurden mit sauber
  ausgerichtetem Plain-ASCII neu gezeichnet. Alle Codeblöcke erhielten
  eine Sprachangabe; geprüft: balancierte Fences, keine defekten
  internen Links, keine LaTeX-Reste, keine render-kritischen
  Lint-Fehler.
- **#4 Falscher App-Name im Topbar-Titel.** „ENERGIE TRACKING" →
  **„ENERGIETRACKER"** (einzige Fundstelle; die extrahierten Logo-Icons
  sind bereits textfrei).

### Changed

- **Test-Harness browser-realistisch erweitert.** `browser-render`
  löst relative URLs jetzt wie ein Browser gegen die Basis-URL auf und
  stellt einen Chart.js-Stub bereit (zuvor zwei Setup-Lücken, die
  Render-Checks blockierten). Neue Regressionstests für #1 (Sigmoid in
  der Analyse) und #2 (Liefer-Arten ohne Verträge / kumulative Arten
  mit Verträgen). Stand: **Backend-Shape 9/9, Browser-Render 28/28**.

### Verifiziert (numerische Logik)

- Eigenständige Referenz-Nachrechnung von 16 Kern-Formeln gegen den
  realen Code: HGT, lineare Tagesinterpolation, R², Regressions-
  steigung/-achsenabschnitt, Sigmoid (fit↔predict konsistent),
  Prognose-Blend (`w = min(R², blend_max)`).
- End-to-End: `total_eur`-Vorrang isoliert exakt (1500 €/2000 L → genau
  7,5000 ct/kWh, Energiebilanz aufs kWh genau); Saldo-Formel
  (`current_balance = actual_cost − advance_paid`) und Kostenzerlegung
  (`Arbeit + Grund − Bonus`) über alle Demo-Verträge mit 0,00
  Abweichung; Tank-Bestandskurve nie negativ / nie über Kapazität.

### Migration

- **Kein Schema-Change** (Schema bleibt 1.1.0). Reine UI-/Anzeige- und
  Doku-Korrekturen; keine Daten- oder API-Änderung.

---

## [1.4.2] — 2026-05-16 — Export aller Energiearten, Datumsformat, PDF-Kennzahlen, neues Logo, Doku-Kompendium

### Added

- **CSV-Lieferungs-Export** für lieferbasierte Arten:
  `GET /api/export/{utility}/deliveries.csv` (Heizöl/Pellets) mit
  korrekter Einheit (L bzw. kg) im Spaltenkopf.
- **Neues App-Icon** (Haus mit Farbring) aus dem gelieferten Logo
  extrahiert — Tag-/Nacht-Variante, als Favicon, Apple-Touch-Icon und
  themenabhängiges Topbar-Logo eingebunden (`public/img/`).
- **Doku-Kompendium** unter `docs/` — getrennt technisch
  (`docs/technical/`, 6 Kapitel), fachlich (`docs/functional/`, je
  Energieart + Szenarien + Glossar) und UI (`docs/ui/` mit Mockups
  aller 11 Views). Wird ab dieser Version bei jedem Release gepflegt.

### Fixed

- **#2 Export war nicht für alle Energiearten verfügbar.** Die
  Export-UI in den Einstellungen war auf gas/strom/wasser hartkodiert.
  Sie baut die Kacheln jetzt dynamisch aus den aktiven Verbrauchsarten;
  Fernwärme erhält Monats-/Ablesungs-Export, Heizöl/Pellets den neuen
  Lieferungs-Export.
- **#3 PDF-Diagramm ohne Achsen/Beschriftung.** Das achsenlose
  Mini-Liniendiagramm im Jahresbericht wurde entfernt und durch eine
  Kennzahlen-Leiste (Jahresverbrauch, Ø/Monat, Gesamtkosten,
  stärkster/schwächster Monat) plus die bestehende Monatstabelle
  ersetzt.
- **#4 Abrechnungszyklus im falschen Datumsformat.** Der Stichtag wurde
  als `MM-TT` angezeigt/eingegeben. Die UI nutzt jetzt das deutsche
  Format `TT-MM`; die Speicherung bleibt kanonisch `MM-TT`, damit das
  Backend valide Datümer baut (Konvertierung nur an der UI-Grenze,
  inkl. Validierung/Februar-Klemmung).

### Changed

- **#5/#6 Heizöl/Pellets-Kosten — Gesamtbetrag hat Vorrang.** Ist auf
  einer Tankrechnung der Gesamtbetrag (`total_eur`) erfasst, wird
  dieser als Kostenbasis genutzt (enthält Liefergebühr/Rabatt); der
  effektive Stückpreis wird daraus abgeleitet. Nur ohne Gesamtbetrag
  wird wie bisher `unit_price_cents` × Menge gerechnet. Bestätigt:
  Heizöl/Pellets haben bewusst **keine** Vertrags-Entität — die
  Tankrechnung selbst ist die Kostenbasis.
- **Doku ersetzt.** Die alten Einzeldateien `docs/API.md`,
  `docs/ARCHITECTURE.md` und `docs/screenshots/` sind in das
  strukturierte Kompendium übergegangen. `docs/MIGRATION-FROM-V090.md`
  bleibt erhalten und wird aus dem Kompendium verlinkt.

### Migration

- **Kein Schema-Change** (Schema bleibt 1.1.0). Neue Exporte und der
  neue Lieferungs-Endpoint sind rein additiv. Bestehende
  `billing_cycle_anchor_*`-Werte bleiben gültig (kanonische Speicherung
  unverändert `MM-TT`; geändert wurde nur die Anzeige).

---

## [1.4.1] — 2026-05-16 — Bugfix: Sigmoid-Modell in der Prognose wählbar

### Fixed

- **Sigmoid-Regressionsmodell war in der Prognose nicht auswählbar.**
  Der Modell-Selektor in der Forecast-Ansicht
  (`public/js/views/forecast.js`) listete nur vier der fünf Modelle —
  `sigmoid` fehlte, obwohl es in den Einstellungen als Standardmodell
  wählbar war und das Backend (`ForecastService` →
  `RegressionService::fit`) es längst unterstützte. Reine Frontend-
  Lücke; Option ergänzt. Der Browser-Render-Test prüft jetzt, dass
  alle fünf Modelle (linear, polynomial, robust, segmented, sigmoid)
  in der Prognose wählbar sind.

### Migration

- Keine Schema-, Datenmodell- oder API-Änderung. Reiner UI-Bugfix.

## [1.4.0] — 2026-05-16 — Tank-Bestandsmodell & Effizienz pro Heizquelle

Korrektur zweier Modell-/Darstellungsschwächen, die beim Anlegen der
Demo-Daten für die neuen Energieträger sichtbar wurden, plus ein
kritischer Startup-Bugfix.

### Fixed

- **App startete nicht (kritisch).** `public/js/lib/sidebar.js`
  importierte `./state.js` / `./api.js` statt `../state.js` /
  `../api.js`. Da `sidebar.js` von `app.js` geladen wird, brach dieser
  404 den gesamten ES-Modulgraphen — die Oberfläche blieb bei „Lade…"
  stehen. Pfade korrigiert; neuer Modulgraph-Regressionstest
  (`tests/browser-render.test.mjs`) crawlt `app.js` samt aller
  transitiven Importe über HTTP und fängt solche Fehler künftig.
- **Tank-Bestandskurve zeigte konstruktionsbedingt immer ~0 %.** Das
  alte Modell verteilte die *gesamte* gelieferte Energie HGT-gewichtet
  über die Laufzeit und erzwang damit Endbestand ≈ 0 — unabhängig von
  den Liefermengen. Zusätzlich mischte `stockHistory()` Liter mit kWh
  (latenter Einheitenfehler, von der 0-Normierung maskiert). Neu:
  `ConsumptionService::dailyDeliveryStockDraw()` berechnet den Abzug in
  Mengeneinheiten aus einer **aus den geschlossenen Lieferintervallen
  kalibrierten kWh/HGT-Rate**. Die Bestandskurve ist jetzt ein
  realistischer Sägezahn ohne Zwang auf 0. Die Kosten-/Effizienz-
  Berechnung (`dailyDeliveryConsumption()`, Energiebilanz) bleibt
  unverändert — sie ist für die Verbrauchs-/Kostensicht korrekt.

### Changed

- **Effizienz-Benchmark jetzt pro Heizquelle.** Statt alle
  Heizenergie-Arten zu summieren (was bei mehreren aktiven Heizquellen
  eine unsinnige Klasse ergab), weist `/api/benchmarks/efficiency` nun
  `per_source` (Klasse je Quelle), `primary` (größte Quelle) und
  `combined` (Summe, nur für bewusst kombinierten Heizbetrieb) aus.
  Die Top-Level-Felder `class`/`kwh_per_m2`/`total_kwh` bleiben als
  rückwärtskompatible Aliase erhalten, zeigen aber nun die **primäre
  Heizquelle** statt der Summe. Dashboard-Karte und PDF-Bericht zeigen
  bei mehreren Quellen eine Aufschlüsselung; die Empfehlungsregel R7
  bewertet jede Quelle einzeln.
- **Demo-Daten vollständig.** Demo-Datensätze für Fernwärme, Heizöl und
  Pellets ergänzt (zuvor nur Gas/Strom/Wasser), inkl. realistischer
  Tankgrößen und Lieferkadenz, sodass jede Verbrauchsart ab Start
  bedienbar ist.

### Migration

- Keine Schema- oder Datenmodell-Änderung; Schema bleibt **1.1.0**.
  Reine Berechnungs-/Darstellungs- und Bugfix-Änderungen. API-Antwort
  von `/api/benchmarks/efficiency` ist additiv erweitert; bestehende
  Felder bleiben kompatibel.

---

## [1.3.0] — 2026-05-16 — Lieferbasierte Energieträger, Effizienz, Insights

Das bislang umfangreichste Release. Drei neue Verbrauchsarten, ein
zweites Datenmodell (lieferbasiert statt kumulativ), Wetterbereinigung,
Effizienzklassen, eine Empfehlungs-Engine, Termin-/Wartungsverwaltung,
Tarifvergleich mit Schattenverträgen und ein PDF-Jahresbericht.

**Schema-Migration 1.0.3 → 1.1.0** — automatisch und idempotent beim
ersten Start. Bestehende Gas-/Strom-/Wasser-Daten bleiben unverändert;
neue Verbrauchsarten und `data/reminders.json` werden angelegt.

### Added

- **Drei neue Verbrauchsarten.** Fernwärme (kumulativ, kWh,
  HGT-relevant), Heizöl und Pellets (beide **lieferbasiert** —
  Brennstofflieferungen statt Zählerständen). Heizöl rechnet in Litern,
  Pellets in kg; Energiegehalt je Einheit ist konfigurierbar
  (`heizoel_kwh_per_l`, `pellets_kwh_per_kg`).
- **Lieferbasiertes Datenmodell.** Für Heizöl/Pellets werden Lieferungen
  erfasst (`{date, quantity, unit_price_cents, total_eur, supplier,
  note, is_planned}`). Der Monatsverbrauch wird aus
  Anfangsbestand + Lieferungen energetisch bilanziert und über einen
  konfigurierbaren Sockelanteil (flach) plus HGT-Gewichtung (Rest)
  auf die Monate verteilt. Tank-Bestandskurve über die Zeit.
- **Wetterbereinigung.** Monatstabelle HGT-relevanter Arten erhält
  `expected_hgt` (Regressionserwartung), `weather_adjusted`
  (auf das langjährige Kalendermonats-HGT normierter Verbrauch nach
  VDI-3807-Logik) und `delta_pct`. Schwachlastmonate werden korrekt
  ausgeblendet.
- **Effizienzklasse.** Heizenergiebedarf in kWh/m²·a über alle
  Heizenergie-Arten, eingestuft in A+…H gegen konfigurierbare
  Bandgrenzen (`/api/benchmarks/efficiency`). Wohnfläche, Baujahr und
  Gebäudetyp sind in den Einstellungen pflegbar.
- **Empfehlungs-Engine.** Sieben rein statistische Regelfamilien aus
  den Eigendaten (wetterbereinigter Mehrverbrauch, schleichender Trend,
  hoher Sommerverbrauch, Anomalie, Tank-Niveau, Vertragsende,
  Effizienzklasse) mit Schweregrad und stabiler ID; einzeln
  ausblendbar (`/api/recommendations`).
- **Termin- & Wartungsverwaltung.** Wiederkehrende Termine
  (Heizungswartung, Schornsteinfeger, Zähler-Eichfristen u. a.) mit
  Fälligkeitsstatus und Recurrence-Fortschreibung beim Erledigen
  (`/api/reminders`).
- **Tarifvergleich mit Schattenverträgen.** Hypothetische Tarife
  (`is_shadow`) lassen sich auf die tatsächlichen historischen
  Verbräuche rechnen und echten Verträgen gegenüberstellen, ohne Saldo
  oder Prognose zu beeinflussen
  (`/api/utility/{u}/meters/{id}/tariff-comparison`).
- **PDF-Jahresbericht.** Mehrseitiger A4-Bericht (Deckblatt,
  Übersicht/Effizienz, je Verbrauchsart Tabelle + Verlaufsdiagramm,
  Empfehlungen) als Datei-Download — erzeugt von einem eigenen,
  abhängigkeitsfreien PDF-Writer (`/api/reports/yearly.pdf`).
- **Neue Regressionsmodelle.** `sigmoid` (Heizsignatur nach
  TU-München/BDEW-Form, robust gefittet) sowie `segmented` mit
  **datenbasiertem Knickpunkt** (`segmented_split_mode = auto`).
- **Aktivierbare Verbrauchsarten.** `active_utilities` steuert, welche
  Arten in Sidebar, Dashboard und Bericht erscheinen — inaktive Daten
  bleiben erhalten. Sidebar wird dynamisch daraus aufgebaut.
- **Dashboard-Insight-Karten.** Effizienzklasse, Tank-Bestände,
  Top-Empfehlungen und anstehende Termine auf einen Blick.

### Changed

- **Einstellungen erweitert** von 28 auf **50 Schlüssel** plus die
  `active_utilities`-Auswahl: Gebäude/Effizienz, Energieträger-
  Energiegehalte, Tank-Warnschwelle, Termin- und Empfehlungs-Schwellen,
  Abrechnungs-Stichtage der neuen Arten, Segment-Knickpunktmodus,
  `sigmoid` im Prognosemodell-Picker, PDF-Download.
- **Sidebar** ist nicht mehr statisch in `index.php` verdrahtet, sondern
  wird zur Laufzeit aus aktiven Verbrauchsarten und neuen Views
  (Tarifvergleich, Empfehlungen, Termine) erzeugt; Badges für offene
  Empfehlungen und fällige Termine.

### Migration

- Schema-Version steigt auf **1.1.0**. Der Migrator erkennt 1.0.3 und
  ergänzt fehlende Verzeichnisse/Dateien idempotent — kein manueller
  Schritt nötig. Ein Sicherheits-Snapshot wird wie üblich vor dem
  ersten Schreiben angelegt. Ein Downgrade auf 1.0.x wird nicht
  unterstützt (die neuen Verbrauchsarten würden ignoriert).

### Hinweise

- Der PDF-Writer nutzt bewusst **keine externe Bibliothek** (kein
  composer, mPDF, gd oder mbstring) — konsistent mit der
  abhängigkeitsfreien, flat-file-Architektur der App.
- Die Tank-Bestandskurve ist eine Modellschätzung (Anfangsbestand +
  Lieferungen − HGT-gewichteter Verbrauch), keine Tankpeilung; bei
  langen Lieferintervallen kann der modellierte Bestand 0 erreichen.
- Tarifvergleich ist für Wasser (Drei-Komponenten-Tarif) bewusst nicht
  enthalten.

---

## [1.2.0] — 2026-05-15 — Theme-Toggle + Diagnose-Bugfix

Tag/Nacht-Umschaltung für die gesamte UI plus eine kosmetische
Aufräumung der System-Diagnose. Keine Backend- oder Datenmodell-
Änderungen — Schema bleibt unverändert bei **1.0.3**.

### Added

- **Tag/Nacht-Umschaltung.** Ein Toggle-Knopf rechts in der Topbar
  schaltet zwischen Dunkel- und Hellmodus. Die Wahl wird in
  `localStorage["et-theme"]` persistiert; auf den ersten Besuch ohne
  gespeicherte Wahl wird `prefers-color-scheme` respektiert. Ein
  Anti-Flash-Inline-Skript in `index.php` setzt das Theme bereits vor
  dem CSS-Laden, damit es keinen Aufblitz-Effekt gibt. Solange der User
  noch nicht selbst geklickt hat, folgt die App OS-Theme-Änderungen
  live (z.B. macOS schaltet abends auf dunkel). Implementierung via
  `[data-theme="light|dark"]`-Attribut auf `<html>` plus CSS-Variablen
  in `tokens.css` — keine Style-Duplikation.

### Fixed

- **[#1] System-Diagnose-Optik.** In v1.0.4 → v1.1.0 sah die System-
  Diagnose (unter Einstellungen) hässlich aus, vor allem die Zeilen
  `utilities`, `temperatures` und `settings_known_keys` waren rohe
  JSON-Dumps und liefen seitlich aus dem Layout. Neu:
  - Header-Felder (App-Version, PHP, Datenverzeichnis usw.) in einem
    sauberen Definition-Grid.
  - `utilities` als kompakte Tabelle mit Spalten Zähler / Ablesungen /
    Verträge / Letzte Ablesung.
  - `temperatures` als „N Tageswerte gespeichert"-Klartext.
  - `settings_known_keys` als gewickelte Mono-Chips.
  - Boolean-Felder (`data_dir_writable`, `curl_available`,
    `migration_needed`) als farbige Badges.

### Notes

- Mehrere bislang hardcodierte `rgba(…)`-Farbwerte in `app.css` und
  `components.css` wurden durch `var(--token)` bzw. `color-mix(in srgb,
  var(--token) NN%, transparent)` ersetzt, damit das Light-Theme korrekt
  durchschlägt. Funktional unverändert für das Dark-Theme.
- `components/chart.js` liest Theme-Farben jetzt live aus CSS-Variablen
  (per Proxy). Beim Theme-Wechsel werden `Chart.defaults` neu gesetzt;
  bereits gerenderte Charts ziehen die neuen Farben aber erst beim
  nächsten Re-Render an (eine Navigation in der App genügt).
- Keine neuen Settings-Keys, keine Datenmodell-Änderung. Schema-Version
  unverändert `1.0.3`.

[1.2.0]: https://github.com/Bingerminger/energietracker/releases/tag/v1.2.0

---

Erstes MINOR-Release nach der v1.0.x-Bugfix-Serie. Sieben neue Funktionen
und ein Datenmodell-Fix — alle additiv und abwärtskompatibel. **Das
Speicherschema bleibt unverändert bei 1.0.3**; die neuen Einstellungen
werden über die Defaults gemerged, eine `settings.json` aus einer älteren
Version funktioniert ohne Migrationsschritt weiter.

### Added

- **[F-02] Vertragsbasierte Kostenprognose.** Die Prognose rechnete bisher
  mit dem letzten bekannten Arbeitspreis. Jetzt löst der `ForecastService`
  pro Prognosemonat den dann aktiven Vertrag auf und verwendet den **für
  diesen Monat gültigen** Arbeits- und Grundpreis aus der Preishistorie —
  ein im Vertrag für die Zukunft gepflegter Preiswechsel schlägt damit
  korrekt in `cost_estimated` durch. Die Monatsdetail-Tabelle hat zwei
  neue Spalten: *projizierter Abschlag* und *laufender Saldo* (kumuliert
  Kosten − Abschlag). Künftige Boni werden nicht fortgeschrieben — nur im
  Vertrag mit Gutschriftdatum gepflegte Boni fließen ein.
- **[F-03] Abrechnungszyklus je Verbrauchsart.** Drei neue Settings-Keys
  `billing_cycle_anchor_gas|strom|wasser` (Format `MM-TT`, Default
  `01-01`). Der Saldo offener Verträge (`end = null`) wird nun bis zum
  nächsten Abrechnungsstichtag projiziert statt bis „heute + 12 Monate".
  Verträge mit gepflegtem Ende verwenden weiterhin dieses Ende.
- **[F-04] Jahresvergleich mit Monatsdeltas.** Die Korrelations-Ansicht
  bekommt ein eigenes Widget mit der Monat-für-Monat-Differenz der beiden
  jüngsten Jahre mit Daten — absolut und prozentual, inklusive
  Summenzeile über die gemeinsamen Monate. Das bestehende
  „Jahresvergleich"-Liniendiagramm bleibt unverändert daneben bestehen.
- **[F-05] Erinnerung an Vertragsende.** Die `contract-status`-Antwort
  enthält pro Vertrag `days_until_end`, `should_remind` und `remind_stage`
  (0–3). Drei Schwellen sind als Settings-Keys `contract_remind_days_1|2|3`
  konfigurierbar (Default 90 / 30 / 1 Tage). Die Korrelations-Ansicht
  zeigt fällige Verträge als gestuften Hinweis-Banner.
- **[F-06] CSV-Import von Ablesungen.** Neuer zähler-gebundener Endpoint
  `POST /api/utility/{utility}/meters/{id}/readings/import-csv` (Body:
  CSV als `text/plain`). Bereits vorhandene Ablesungen am selben Datum
  werden **überschrieben und im Ergebnis gemeldet** (`imported`,
  `overwritten`, `skipped`, `errors`). Akzeptiert `;`/`,` als Trenner,
  `TT.MM.JJJJ` oder ISO-Datum, deutsches Dezimalkomma. Die Import-Logik
  steckt im quell-agnostischen `ReadingImportService` — eine künftige
  Smart-Meter-Anbindung (siehe Roadmap) kann denselben Kern ohne
  CSV-Parsing wiederverwenden. UI: „CSV-Import"-Knopf je Zähler in der
  Zählerverwaltung.
- **[F-07] CSV-Export.** Drei neue Endpoints liefern tabellarische
  Exporte als Datei-Download: `GET /api/export/{utility}/monthly.csv`
  (Monatsaggregate), `GET /api/export/{utility}/readings.csv`
  (Rohablesungen), `GET /api/export/temperatures.csv` (Temperaturreihe).
  Semikolon-getrennt, UTF-8 mit BOM, deutsches Dezimalkomma — direkt in
  Excel/LibreOffice/Google Sheets nutzbar. UI: Export-Kacheln in den
  Einstellungen. Ergänzt das vollständige JSON-Backup, ersetzt es nicht.
- **[F-10] Wasser-Spar-Index.** Die Korrelations-Ansicht zeigt für Wasser
  einen Index `(Liter/Person/Tag) / Referenz × 100` über die jüngsten bis
  zu 12 Monate, mit Einordnung auf einem Band. Die Bandgrenzen sind als
  Settings-Keys `wasser_sparindex_gut` / `wasser_sparindex_warnung`
  konfigurierbar (Default 100 / 150).
- Einstellungs-Ansicht überarbeitet: gruppierte Settings-Karten in einem
  responsiven Raster, Feld-Erklärungen, Einheitenkennzeichnung. Das
  Settings-Inventar wächst von 20 auf 28 Schlüssel.

### Fixed

- **[#2 — Datenmodell] Schmutzwasser-Basis `separater_zaehler` war ohne
  Wirkung.** Im Wasser-Vertragsmodell (v1.0.3) konnte für die
  Schmutzwasser-Komponente die Basis `separater_zaehler` gewählt werden —
  die Verbrauchsberechnung nutzte aber in jedem Fall das Trinkwasser-m³.
  Jetzt löst der `ConsumptionService` die Basis `separater_zaehler` über
  das Feld `separater_zaehler_meter_id` auf und rechnet mit dem
  monatlichen m³ des referenzierten Zählers. Eine Rekursionssperre
  verhindert Endlosschleifen bei (fehlerhaft) gegenseitig
  referenzierenden Zählern. Kein Schema-Eingriff — das Feld existierte
  bereits, wurde nur nicht ausgewertet.

### Notes

- Schema-Version unverändert **1.0.3**. Keine Migration nötig: fehlende
  Settings-Keys werden beim Lesen aus den Defaults ergänzt.
- Bekannte Einschränkung F-02: bei Wasser mit Schmutzwasser-Basis
  `separater_zaehler` nutzt die *Vorausschau* das Trinkwasser-Volumen als
  Schmutzwasser-Basis (der separate Zähler wird nicht selbst prognostiziert).
  Die *historische* Auswertung rechnet das separate Volumen korrekt.

[1.1.0]: https://github.com/Bingerminger/energietracker/releases/tag/v1.1.0

---

Drei via GitHub gemeldete Bugs in der Vertragsverwaltung und in der
Korrelations-Ansicht.

### Fixed

- **[#1] Vertrags-Button irreführend benannt.** Auf den Utility-Seiten
  (Gas/Strom/Wasser) zeigte der Action-Button in der „Verträge &
  Abschläge"-Karte den Text *„+ Neuer Vertrag"* — er führte aber zur
  Vertragsverwaltungs-Seite, nicht zum Vertrag-Anlegen-Dialog. Neu:
  *„⚙️ Verträge verwalten"* mit passendem Icon. Das eigentliche
  Anlegen ist weiterhin in der Vertragsverwaltung selbst per
  „+ Neuer Vertrag"-Button erreichbar.

- **[#2] Speichern/Abbrechen-Buttons im Vertrag-Edit-Modal funktionierten
  nicht bei manchen Verträgen.** Wenn beim Aufbau des Modals ein
  Wiring-Schritt (Boni-Sektion oder Zeilen-Buttons einer Stichtag-
  Gruppe) eine Exception warf — etwa weil ein migriertes v0.9.0-Format
  unerwartete Strukturen mitbrachte — wurden die Fußleisten-Buttons nie
  verbunden, weil sie nach den anderen Handlern gebunden wurden.
  Fixes:
  - Cancel/Save werden jetzt **als allererstes** gebunden, bevor
    irgendein anderer Sub-Handler läuft. Selbst wenn ein nachfolgender
    Schritt fehlschlägt, bleibt das Modal benutzbar.
  - Alle DOM-Lookups in `bindRowHandlers`, `bindBonusHandlers`,
    `bindBonusRow` defensiv mit Optional-Chaining (`?.`). Fehlt ein
    Element, gibt's eine `console.warn`, aber kein TypeError mehr.
  - Die globale ID `#bonus-section` wurde durch `[data-section="bonus"]`
    ersetzt, damit gestapelte Modale nicht über IDs kollidieren können.

- **[#3] Anomalien-Tabelle in der Korrelations-Ansicht zeigte leere
  Werte.** Feldnamen-Mismatch zwischen Backend und Frontend:
  `AnomalyService` liefert `value` / `z_score` / `deviation` / `percent`
  / `kind` / `hdd` / `avg_temp` — das Frontend las `a.actual` und `a.z`
  (existieren nicht), wodurch *Verbrauch* und *Abweichung (σ)* immer
  NaN bzw. leer waren. Das schuf den Eindruck, dass die Anomalien nicht
  zu den eigentlichen Zählerdaten passten.
  Die Anomalien-Tabelle wurde dabei gleich angereichert: Absolute
  Abweichung, Prozent-Abweichung, σ-Wert, plus HGT und ø-Temperatur bei
  HGT-relevanten Utilities. Empty-State-Text erklärt jetzt, gegen welches
  Modell die Schwelle gerechnet wird.

### Notes

- Das geänderte Anomalien-Markup ist nicht-breaking; alle Backend-Felder
  waren bereits vorhanden, nur das Frontend hat sie schlicht nicht
  gelesen.
- Schema-Version (`data/meta.json`) bleibt auf `1.0.3` — keine
  Datenmodell-Änderung in v1.0.4.

[1.0.4]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.4

---

## [1.0.3] — 2026-05-11 — Wasser-Vertragsmodell

Fachlicher Bugfix am Wassermodul. In v1.0.2 wurden Wasserverträge mit
demselben Schema wie Gas und Strom modelliert (ein `working_prices`-Array
mit `ct_per_kwh`), was bei Wasser strukturell falsch ist: eine deutsche
Wasserrechnung enthält drei separate Positionen — Trinkwasser, Schmutzwasser
und Niederschlagswasser — mit jeweils eigener Berechnungslogik.

### Changed — Wasser-Vertragsmodell (Schema 1.0.3)

Wasserverträge haben jetzt drei Komponenten-Blöcke. Gas- und Strom-
verträge bleiben strukturell unverändert.

```json
{
  "id": "c_wasser_...",
  "meter_id": "m_wasser_haupt",
  "provider": "Kommunale Wasserwerke Leipzig",
  "tariff_name": "Trink-, Schmutz- und Niederschlagswasser 2025",
  "start": "2025-01-01",
  "end":   "2026-12-31",

  "trinkwasser": {
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 255.0}],
    "base_prices":    [{"from": "2025-01-01", "eur_per_month": 8.50}]
  },

  "schmutzwasser": {
    "basis": "trinkwasser",
    "separater_zaehler_meter_id": null,
    "working_prices": [{"from": "2025-01-01", "ct_per_m3": 305.0}]
  },

  "niederschlagswasser": {
    "rates": [
      {"from": "2025-01-01", "eur_per_m2_year": 1.50, "versiegelte_flaeche_m2": 120}
    ]
  },

  "advance_payments": [{"from": "2025-01-01", "amount_eur": 72.00}],
  "bonuses": []
}
```

**Berechnungen pro Monat:**

- *Trinkwasser*: `m³ × ct_per_m3 / 100 + eur_per_month`
- *Schmutzwasser*: `m³ × ct_per_m3 / 100`. `m³` ist standardmäßig der
  Trinkwasser-Verbrauch (Basis `trinkwasser`, Standard in 95% der DE-Haushalte).
  Bei Basis `separater_zaehler` wird in v1.0.3 vorerst der gleiche Trinkwasser-
  Wert verwendet — eine echte Auswertung des separaten Zählers folgt in
  einer späteren Version.
- *Niederschlagswasser*: `(versiegelte_flaeche_m2 × eur_per_m2_year) / 12`.
  Stichtag-Historie für Tarif **und** Fläche, falls sich beides ändert.

### Added — Auto-Migration

Beim ersten Start auf v1.0.3 prüft `Storage\Migrator::needsWaterContractsUpgrade()`
ob noch Wasser-Verträge in der alten v1.0.2-Form (`working_prices` + `base_prices` direkt
am Vertrag) vorliegen. Falls ja:

- Alte `working_prices` wandern nach `trinkwasser.working_prices`, wobei das
  Feld `ct_per_kwh` (das bei Wasser semantisch schon ct/m³ war) zu `ct_per_m3`
  umbenannt wird.
- Alte `base_prices` wandern nach `trinkwasser.base_prices`.
- `schmutzwasser` und `niederschlagswasser` werden mit leeren Arrays
  initialisiert. Eine Notiz im `notes`-Feld weist die User darauf hin,
  beide Komponenten manuell nachzupflegen, da die alten Daten sie
  nicht enthalten haben.
- `advance_payments` und `bonuses` bleiben unverändert.

Schema-Marker in `data/meta.json` springt von `1.0.0` auf `1.0.3`.

### Added — UI

- **Wasser-Saldo-Karte** zeigt unterhalb der 4 Hauptspalten eine
  Komponenten-Zeile mit drei Kacheln (Trinkwasser, Schmutzwasser,
  Niederschlagswasser), jeweils mit aktuellem Tarif, kumuliertem
  Verbrauchsanteil und Grundpreis-Aufschlüsselung.
- **Wasser-Vertragsdialog** (`openWaterContractModal`) mit drei
  ein-/ausklappbaren Komponenten-Sektionen plus Abschläge und Boni.
  Schmutzwasser-Basis ist umschaltbar (Trinkwasser-Verbrauch /
  separater Zähler).
- **Vertragstabelle** für Wasser zeigt die Anzahl der Stichtage pro
  Komponente statt der Standard-Spalten.

### Backend-Änderungen

- `ContractService::normalizeWater()` — strikte F4-Validierung der
  drei Komponenten-Blöcke. Halb-ausgefüllte Niederschlagswasser-Zeilen
  (z.B. Fläche fehlt, Tarif vorhanden) werden mit HTTP 400 abgelehnt.
- `ConsumptionService::applyWaterContracts()` — neue Pro-Monat-Berechnung
  mit drei Komponenten und Aufschlüsselung in `monthly[].trinkwasser`,
  `.schmutzwasser`, `.niederschlagswasser`.
- `ConsumptionService::contractStatus()` für Wasser ergänzt um
  `components.{trinkwasser, schmutzwasser, niederschlagswasser}` mit
  Pro-Komponenten-Summen und aktuellen Tarifwerten.

### Migration

Update von v1.0.2 → v1.0.3 ist beim ersten App-Start automatisch.
Bestehende Backups (Format `backup_version: "3.0"`) bleiben kompatibel,
weil sie unter `utilities.wasser.contracts` die alte Struktur
enthielten — beim Import wird sie wie laufende v1.0.2-Daten erkannt und
in die neue Struktur überführt.

[1.0.3]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.3

---

## [1.0.2] — 2026-05-11 — Initial public release

Erste öffentliche Version des Energietrackers. Selbst-gehostete Web-App
zum Erfassen und Analysieren des privaten Energie- und Wasserverbrauchs.
Single-File-PHP-Backend, Vanilla-JS-SPA-Frontend, flat-file JSON-Persistenz.

Eine private Vorgängerversion existierte unter dem Namen *Energietracker
v0.9.0* (Backup-Format `version: "2.1"`). Backups aus dieser Version
können mit dem eingebauten Migrator importiert werden — siehe
[`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md). Außerhalb
dieses Migrationspfads ist v0.9.0 für die öffentliche Codebase irrelevant.

### Funktionen

- **Drei Verbrauchsarten parallel**: Gas, Strom, Wasser. Pro Utility
  eigene Zähler, Verträge, Tarifhistorie und Berechnungslogik.
- **Mehrere Zähler pro Utility**, jeder mit unabhängiger Vertragsserie
  (z.B. Hauptzähler + Gartenwasser-Zwischenzähler).
- **Zählertausch** als erstklassiges Datenmodell: ein Zähler bündelt
  beliebig viele Geräte (Devices) mit Seriennummer, Einbaudatum,
  Anfangs-/Endzähler, Begründung. Verbrauch wird über Tausch-Grenzen
  hinweg korrekt überbrückt.
- **Vertragshistorie** mit stichtag-genauen Arbeits- und Grundpreisen,
  monatlichen Abschlägen und Boni. Mehrere Stichtage pro Vertrag werden
  als forward-fill auf die Monatszeilen angewandt.
- **Saldo-Berechnung pro Vertrag**: aktueller Stand (Kosten −
  Abschläge) und erwarteter End-Saldo (extrapoliert über die
  verbleibenden Monate). Verdict: Erstattung, Nachzahlung, Ausgeglichen.
- **Heizgradtage** (HGT) je Monat gegen die Basistemperatur, mit
  Open-Meteo-Sync und CSV-Import für historische Tagestemperaturen
  (Format `DD.MM.YYYY"avg"min"max`, double-quote-getrennt).
- **Vier Regressionsmodelle** für HGT vs. Verbrauch: linear,
  polynomial Grad 2, robust (Huber), segmentiert
  (Heiz-/Sommerlast bei HGT = 50 als Default-Schwelle).
- **12-Monats-Forecast** als R²-gewichtete Mischung aus Regressions-
  modell und Saisonprofil.
- **Anomalie-Erkennung**: Monate mit > 2σ Abweichung vom
  Modell-Erwartungswert.
- **Backup & Restore** über die UI (Format `backup_version: "3.0"`),
  inklusive automatischer Sicherheits-Snapshots im Datenverzeichnis.
- **Migration aus v0.9.0**: ein altes Backup-Format (`version: "2.1"`)
  kann zweistufig importiert werden — erst Preview mit Übersicht, dann
  *Ersetzen* oder *Zusammenführen* (Konflikt-Resolution per ID).

### Technische Architektur

- **Backend (PHP ≥ 8.4)**: 13 Services + 11 Controllers, organisiert
  in einem kleinen App-Container (`src/bootstrap.php`). Speicher-Layer
  via `JsonStore` mit `LOCK_EX`-Schreibverbindung. Routing über einen
  kompakten Hand-rolled Router (`src/Http/Router.php`).
- **Frontend (Vanilla JS, ES Modules)**: 8 Views + 3 Komponenten + 1
  Hash-Router, 1 API-Fetch-Wrapper, 1 Lightweight-State-Cache. Kein
  Build-Step, keine Node-Toolchain nötig zur Auslieferung.
- **Daten**: flache JSON-Dateien unter `data/`. Schema-Version
  in `data/meta.json`. Atomic-write-Pattern überall.
- **Visualisierung**: Chart.js 4 per CDN-Import in `index.php`.
- **Typografie**: DM Mono (Zahlen, Datumsangaben) und DM Sans
  (Prosa) per Google Fonts.

### Datenmodell (siehe README → Datenmodell für Details)

- **Reading**: `{id, meter_id, device_id, date, counter, price_cents?,
  note, is_estimated, is_future}`
- **Meter**: `{id, name, icon, created_at, active, notes, devices: [...]}`
- **Device**: `{id, serial, installed_on, initial_counter, removed_on,
  final_counter, reason}`
- **Contract**: `{id, meter_id, provider, tariff_name, start, end, notes,
  working_prices: [{from, ct_per_kwh}], base_prices, advance_payments, bonuses}`

### Settings-Inventar (20 Schlüssel)

```
gas_conversion_factor (kWh/m³)     hdd_base_temp (°C)
co2_gas (g/kWh)                    co2_strom (g/kWh)
co2_wasser (g/m³)                  min_days_period
min_hdd_regression                 blend_max
forecast_months                    min_temp_days_forecast
forecast_model                     dashboard_months
alert_days_since_reading           anomaly_threshold
location_name                      latitude
longitude                          weather_auto_fill
wasser_personen_anzahl             wasser_personen_referenz
```

### Bekannte Einschränkungen

- HGT-Anwendung nur für Gas und Strom relevant; bei Wasser fällt der
  Forecast auf 100 % Saisonprofil zurück.
- Open-Meteo-Sync benötigt `curl` als PHP-Extension.
- Keine Mehrbenutzer- oder Auth-Schicht — Single-Tenant per Design.

[1.0.2]: https://github.com/Bingerminger/energietracker/releases/tag/v1.0.2

---

## Roadmap

Nicht terminiert — Reihenfolge und Umfang können sich ändern.

### Smart-Meter-Anbindung (Aufbau auf F-06)

F-06 hat den Ablesungs-Import in zwei Schichten getrennt: die
CSV-Parsing-Schicht und den quell-agnostischen Kern
`ReadingImportService::importRows()`. Damit ist **Variante 1** (gekapselte,
wiederverwendbare Importlogik) bereits umgesetzt.

Offen ist **Variante 2** — ein unbeaufsichtigter Endpoint, über den ein
Smart-Meter-Gateway oder ein Heimautomatisierungs-System Ablesungen ohne
UI-Interaktion einliefert. Dafür nötig:

- Ein Token-geschützter Endpoint (z.B.
  `POST /api/ingest/{utility}/{meter}` mit `Authorization: Bearer …`),
  da die übrige App bewusst auth-frei und Single-Tenant ist.
- Token-Verwaltung (Erzeugen/Widerrufen) in den Einstellungen.
- `importRows()` ist bereits der passende Aufsetzpunkt — der neue
  Endpoint muss nur Auth + Payload-Parsing ergänzen, die Schreib- und
  Überschreiblogik bleibt unverändert.

### Weitere Verbrauchsarten

- Heizöl und Pellets als zusätzliche Utilities (`Config/Utilities.php`
  ist die Single Source of Truth — additiv erweiterbar, ähnlich wie
  Wasser in v1.0.0).

### Internationalisierung

- Englische UI-Sprache. Aktuell sind Labels und Meldungen
  durchgängig deutsch verdrahtet.
