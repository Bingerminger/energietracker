# Release-Prozess

**Deutsch** · [English](../en/technical/06-release-process.md)

[← Tests](05-testing.md) · [Kompendium-Index](../README.md)

Jede fachliche Änderung erzeugt ein vollständiges, in sich konsistentes
Release. Tippfehler oder Kleinst-Doku-Fixes lösen **kein** Release aus.

---

## 1. Semantische Versionierung

| Bump | Wann |
|---|---|
| **PATCH** (x.y.**Z**) | Bugfix ohne Verhaltens-/Datenmodell-/API-Änderung |
| **MINOR** (x.**Y**.0) | neues Feature *oder* additive, abwärtskompatible Modell-/API-Änderung |
| **MAJOR** (**X**.0.0) | Breaking Change an Datenmodell/API |

Beispiele aus der Historie:

- v1.4.0 — Tank-Bestandsmodell + Effizienz pro Heizquelle (additive
  Modell-/API-Änderung) → MINOR
- v1.4.1 — Sigmoid in der Prognose wählbar (reiner UI-Bugfix) → PATCH
- v1.4.2 — Export neue Energiearten, Datumsformat, PDF-Kennzahlen,
  Gesamtbetrag-Vorrang, Logo, Kompendium → MINOR (additive Exporte +
  API-Erweiterung)
- v1.4.3 — Sigmoid in der Analyse, Vertragslogik je Energieart,
  Doku-Markdown, App-Name → PATCH (reine Fixes, kein neues Feature)
- v1.4.4 — Audit-Härtung: Service-Extraktion (`DeliveryConsumptionService`,
  intern, API unverändert), CI-Pipeline, `JsonStore`-Traversal-Schutz,
  Demo-Daten-Schema, Test-Umbenennung → PATCH (kein neues
  Nutzer-Feature, kein API-/Datenmodell-Bruch; rein Code-Qualität und
  Operatives)
- v1.4.5 — CI-Actions auf Node-24-Runtime (`checkout`/`setup-node`
  `@v4`→`@v5`) → PATCH (reine Build-Infrastruktur-Wartung, keine
  Code-/Verhaltens-Änderung; behebt eine GitHub-Deprecation-Warnung)
- v1.5.0 — F1003 Sonderzahlungen (Rück-/Nachzahlung, Abschlagszahlung)
  → MINOR (neues, abwärtskompatibles Feature; additive Datenstruktur,
  kein Migrationsschritt, Schema unverändert 1.1.0)
- v1.5.1 — CI-Fix: Testserver über `router.php` statt `api.php`
  (statische Assets + `/api`-Routing), Server+Tests in einem CI-Step
  → PATCH (reine Test-/CI-Infrastruktur, kein Anwendungscode)
- v1.6.0 — F1004 Zentrale Zählerstand-Erfassung (neuer Menüpunkt
  `#/zaehlerstaende`, Aggregat-Endpunkt `/api/readings-overview`,
  mobile-first View) → MINOR (neues abwärtskompatibles Feature;
  additiver Endpunkt, kein Schemafeld, kein Migrationsschritt)
- v1.6.1 — Bugfix Issue #14 (Wasser-Sub-Dashboard zeigte 0 m³;
  utility.js summierte `m.kwh` statt utility-spezifischem
  `consKey`) + Issue #13 (Riesiger Ausschlag bei Zählertausch;
  vierschichtig: (a) `replaceDevice` verlangt `old_final_counter`
  explizit, (b) Off-by-one in `deviceOnDate` am Tausch-Tag
  behoben, (c) Plausibilitäts-Check auf Wertebereich des alten
  Geräts in `consumptionBetween`, (d) `device_swap`-Flag für
  Wechsel-Monate, AnomalyService respektiert es) → PATCH
  (reine Bugfixes, keine API- oder Schema-Änderungen)

---

## 2. Release-Checkliste

1. **Code gegen reale Wirkung prüfen.** Doku/Schemata nie aus dem
   Gedächtnis — immer gegen den Quellcode (grep/`php -l`/Smoke).
   *(Lesson Learned: im v1.0.0-Refactor mussten Schemata nachträglich
   korrigiert werden, weil Feldnamen aus Erinnerung dokumentiert
   wurden.)*
2. **`VERSION`** aktualisieren (einzige Quelle der Versionsnummer).
3. **`CHANGELOG.md`**: neuer Abschnitt nach „Keep a Changelog"
   (`Added` / `Changed` / `Fixed` / `Migration` / `Hinweise`).
4. **Versionsstempel** synchron ziehen: `README.md` (Badge + Status),
   `INSTALL.md`-Verweis, Kompendium-Header (`docs/README.md` und die
   betroffenen Kapitel).
5. **Kompendium pflegen** — *ab v1.4.2 verpflichtend bei jedem Release*:
   geänderte Endpunkte → `technical/03-api-reference.md`; geändertes
   Verhalten/Modell → betroffenes `functional/*`; neue/­geänderte View
   → `ui/01-views.md` + neuer Screenshot in `ui/screenshots/`;
   Migrationshinweise bei Datenmodell-Änderung in
   `technical/04-data-model.md`.
6. **Tests** grün: `frontend-api-shape` + `browser-render` (inkl.
   Modulgraph-Vorprüfung). Seit v1.4.4 laufen beide plus ein
   PHP-Syntax-Lint automatisch in der CI (`.github/workflows/ci.yml`)
   — der grüne CI-Lauf ist Voraussetzung fürs Taggen. Bei
   Datenmodell-Änderung Demo-Daten und Schemata mitziehen.
7. **Frischer Smoke aus der gepackten ZIP**: entpacken, Migration,
   Server, Kern-Endpunkte + die geänderten Pfade prüfen.
8. **ZIP bauen** (Ausschluss: `.git`, `*.pyc`, `__pycache__`,
   `.DS_Store`, Laufzeit-`data/*.json`, `data/backups/*`).

---

## 3. Doku-Pflege-Regel (ab v1.4.2)

Das Kompendium ist Teil des Releases, **nicht** ein nachgelagertes
Extra. Faustregel je Änderungstyp:

| Änderung | zu pflegende Doku |
|---|---|
| neuer/geänderter Endpunkt | `technical/03-api-reference.md` |
| geändertes Berechnungs-/Datenmodell | passendes `functional/0X-*.md` + ggf. `technical/04-data-model.md` |
| neue Verbrauchsart | neues `functional/0X-*.md`, Index, Architektur |
| neue/­geänderte View | `ui/01-views.md` + Screenshot unter `ui/screenshots/` (echte Aufnahme mit Demo-Daten) |
| neue Lesson Learned | hier in diesem Dokument |

Inhalte des Produkts (Code/Doku) werden nur geändert, wenn der Nutzer
es explizit anstößt — keine ungefragten „Best-Practice-Refactorings".

---

## 4. Git-Veröffentlichung

Der Nutzer übernimmt den ZIP-Inhalt ins lokale Repo und veröffentlicht:

```bash
git add -A
git commit -m "vX.Y.Z — <Kurzbeschreibung>"
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin main --tags
```

---

## 5. Lessons Learned (kumulativ)

- **Frontend browser-realistisch testen.** Backend-curl + JSDOM-
  Direktimport reichen nicht — der Modulgraph muss über HTTP gecrawlt
  werden (fängt 404-Importe wie v1.4.1).
- **Doku gegen realen Code prüfen**, nie aus dem Gedächtnis.
- **Backend↔Frontend-Feldnamen beidseitig prüfen** (mehrere
  Mismatch-Bugs in der Historie).
- **`str_replace` auf große Methoden vorsichtig** — kann benachbarte
  Docblocks abschneiden; danach `php -l`.
- **Modell-Doppelnutzung erkennen.** `dailyDeliveryConsumption` diente
  Kosten *und* Bestandskurve; die Endbestand-0-Annahme war für Kosten
  korrekt, für die Bestandskurve aber falsch → in v1.4.0 entkoppelt.
- **Service-Extraktion ohne Breaking Change (v1.4.4).** Beim Herauslösen
  von `DeliveryConsumptionService` wurde die öffentliche Signatur von
  `ConsumptionService` bewahrt: neuer Konstruktor-Parameter ist
  `?DeliveryConsumptionService = null`, ein Lazy-Getter erzeugt den
  Service notfalls selbst. So brechen bestehende Aufrufer (Tests,
  `DeliveryService::stockHistory()`) nicht. Faustregel: interne Refactors
  dürfen die äußere API nicht zwingen, sich zu ändern.
- **CI-Action-Runtime im Blick behalten (v1.4.5).** GitHub deprecatet
  periodisch die Node-Runtime, auf der Actions *selbst* laufen
  (Node 20 → 24). Das ist unabhängig von der Node-Version, die man im
  Workflow für die eigenen Tests einrichtet. Pinning auf Major-Tags
  (`@v5` statt SHA) lässt GitHub Patch-Updates automatisch nachziehen;
  beim Major-Bump prüfen, ob sich Verhalten ändert (z. B. `checkout@v6`
  verlagerte die Credential-Ablage → für simple CI irrelevant, aber
  bewusst entscheiden, nicht blind den neuesten Tag nehmen).
- **Additives Feature ohne Migration (v1.5.0, F1003).** `special_payments`
  wurde wie `bonuses` als optionales Array modelliert, das beim
  Normalisieren auf `[]` defaultet. Dadurch funktionieren Bestands­
  verträge ohne das Feld unverändert — kein Migrationsschritt, Schema
  bleibt 1.1.0. Faustregel: ein neues Vertrags-Unterfeld additiv und
  default-`[]` halten, dann ist es per Konstruktion abwärtskompatibel.
  Scope-Gating gehört in `Utilities` (Single Source of Truth:
  `hasAdvancePaymentContracts()`), nicht in hartkodierte Utility-Listen
  in Service/Frontend.
- **Dev/CI-Server braucht einen Router (v1.5.1).** `php -S host:port
  api.php` macht `api.php` zum Router für ALLES — statische Assets
  (`/public/js/*`) landen dann in `api.php` → 404, der Modulgraph-Crawl
  des Browser-Render-Tests bricht ab. Lösung: ein `router.php`, das das
  nginx-Verhalten spiegelt (Datei → direkt, `/api` → api.php, sonst
  index.php). Zweitens: in GitHub Actions ist jeder `run:` eine eigene
  Shell — ein in Step A gebackgroundeter `php -S … &` ist in Step B
  weg. Server-Start, Readiness-Probe, Tests und Teardown müssen in
  EINEN Step. Lehre: lokale Test-Infrastruktur immer einmal echt gegen
  den CI-Aufbau spiegeln, nicht nur Backend-Endpoints prüfen.
- **Aggregat-Endpunkt vor Sammel-POST (v1.6.0, F1004).** Beim Bau der
  zentralen Zählerstand-Erfassung war die Frage „ein API-Call zum
  Speichern aller Zähler oder pro Zähler einzeln?" eine echte Weichen-
  stellung. Entschieden gegen einen neuen Batch-Endpunkt, **für** den
  bestehenden POST pro Zähler — Begründung: Teilfehler bleiben präzise
  lokalisierbar, kein neues Datenformat, kein zusätzlicher Validations-
  pfad. Stattdessen Aggregation **lesend** über
  `GET /api/readings-overview` (alle Zähler + letzte Ablesung in einem
  Roundtrip) — das adressiert „API-Aufrufe minimieren" dort, wo es
  fachlich Sinn ergibt (initialer Daten-Load), und lässt das Schreiben
  granular. Faustregel: Aggregate sind oft die richtige Antwort für
  Lese-Performance; sie sind selten die richtige Antwort für Schreib-
  Robustheit.
- **Default-Werte sind versteckte Datenkorruption (v1.6.1, Issue #13).**
  `(float)($input['old_final_counter'] ?? 0)` in `replaceDevice` ließ
  einen unvollständig konfigurierten Zählertausch aussehen wie einen
  sauber geschlossenen — die Bridging-Logik fand danach einen plausibel
  aussehenden `final_counter=0` vor, rechnete `partA = 0 −
  prev.counter`, das verworf zwar als negativ einen Teilfall, ein
  anderer Fall (Tausch-Tags-Reading mit `device_id=alt`) produzierte
  aber einen Riesensprung. Lehre: eine fehlende Pflichtangabe muss
  ein expliziter 400-Fehler sein, kein stiller Numerik-Default —
  insbesondere wenn das Feld später in Plausibilitäts-Checks
  einfließt.
- **Off-by-one am Stichtag muss überall identisch sein (v1.6.1, Issue
  #13).** `deviceOnDate` (Anlegen einer Ablesung) und
  `deviceIdOnDate` (Auswertung) verwendeten beide `$date > removed_on`,
  d. h. am Tausch-Tag selbst gehörte das Reading noch zum ALTEN Gerät.
  Im Bridging-Pfad führt das zu einem fatalen Sprung. Konvention
  geklärt: ein Intervall am `removed_on` endet vor diesem Tag, der
  Tausch-Tag selbst gehört zum NEUEN Gerät (`$date >= removed_on`
  überall). Lehre: bei Datums-Inklusivitäten an Stichtagen vor dem
  ersten Commit konventionell entscheiden und mit einem Kommentar
  am Tag-1-Check festnageln — nicht „so wirkt es richtig" pro Stelle.
- **Inhaltliche Plausibilitätsprüfung statt magic numbers (v1.6.1,
  Issue #13).** Erste Verteidigungslinie war ein Cap `total > 100 ×
  finalOld`. Das hat die echten Daten von Viktor NICHT gefangen,
  weil 17572 < 100 × 17549. Erst der **inhaltliche** Check „liegt
  `prev.counter` überhaupt im Wertebereich `[initial_counter_alt,
  final_counter_alt]` des angeblich alten Geräts?" griff sauber, weil
  er die URSACHE prüft (device_id-Zuordnung) statt das Symptom
  (großer Wert). Lehre: bevor man Schwellwerte tunt, prüfen, ob die
  zugrunde liegende Annahme der Daten überhaupt stimmt — meistens
  ist genau dort der Hebel.
- **Frontend-Backend-Feldnamen über mehrere Stages (v1.6.1, Issue
  #14).** `kwh_per_day` wurde in `enrichWithWeather` aus dem rohen
  `kwh`-Feld berechnet, BEVOR `applyUtilityFields` für Wasser
  `kwh → m3` umlegte und `kwh` nullte. Resultat: in derselben
  Monatszeile stand `kwh = 0` (was die m³-Spalte zeigte) und
  `kwh_per_day = 0,3` (was die m³/Tag-Spalte zeigte) — ein
  selbstwidersprüchlicher Datensatz. Lehre: utility-spezifische
  Umlagen entweder ganz am Ende oder konsistent über alle
  abgeleiteten Felder. Zweitens: Frontend muss Feldnamen
  Utility-aware auflösen (`consKey = consumption_unit==='kWh' ?
  'kwh' : 'm3'`), nicht hartkodiert auf ein Feld setzen.
- **Backend-Pflichtfeld ohne Frontend-Eingabe = garantierter Fehlerpfad
  (v2.1.1, Issue #18).** `MeterService::create()` verlangt für
  lieferbasierte Verbrauchsarten `capacity > 0` + `initial_stock`, doch das
  „Neuer Zähler"-Formular rendert diese Felder nie und sendet sie nie →
  jeder Heizöl-/Pellets-Tank schlug beim Anlegen fehl, ohne dass es ein Feld
  zum Ausfüllen gab. Lehre: `reading_kind`-abhängige Pflichtfelder als Paar
  denken — Validierung in `Utilities`/`MeterService` UND das passende
  Eingabefeld im selben Change; ein Pflichtfeld, das die UI nicht erfassen
  kann, ist per Konstruktion ein 400-Generator.
- **Serializer mit hartkodierter Feldliste wird still unvollständig
  (v2.1.2).** `BackupService::export()` sicherte nur `meters/readings/
  contracts`; `deliveries` (v1.3.0) und `meter_groups` (v1.8.0) kamen als
  neue Utility-Datentöpfe dazu, die Export-Liste wurde nie nachgezogen →
  Backup/Restore verlor die Daten lautlos, ebenso top-level `reminders`.
  Verschärfend: die Roadmap nahm „BackupService zieht ohnehin alle
  JSON-Dateien je Utility ein" an, und kein Test prüfte einen Roundtrip.
  Lehre: Ein Backup/Export braucht einen export→import-Roundtrip-Test, der
  bei jedem neuen Datentopf zwingend erweitert wird; Vollständigkeits-
  Annahmen über den Serializer gehören getestet, nicht dokumentiert.
- **Bereichsregel muss in JEDEN Aggregator propagiert werden (v2.1.3).** Die
  F1006-Regel „Subzähler zählen nicht in Utility-Summen" lebte nur in
  `ConsumptionService::forUtility`. Drei andere Stellen, die selbst über Zähler
  summieren — `PdfReportService::yearAggregate`, `BenchmarkService` und das
  Dashboard-`groupBreakdown` — bekamen sie bei v1.8.0 nie → Subzähler-Doppel-
  zählung im PDF-Jahresbericht und in der Effizienzklasse. Lehre: Eine
  Ausschluss-/Filterregel, die für eine Aggregation gilt, gilt für ALLE; sie
  gehört in eine gemeinsame Quelle (z. B. „Root-Meter"-Helper), nicht
  pro Konsument kopiert/vergessen.
- **Save-Pfad-Validierung schützt den Berechnungs-Pfad nicht (v2.1.4).**
  `applyWaterContracts` rechnete Schmutzwasser bei `basis='separater_zaehler'`
  ohne Meter-Referenz still aufs Trinkwasser-Volumen — entgegen dem eigenen
  Code-Kommentar (sollte 0 sein). `ContractService` verhindert diesen Zustand zwar
  beim Speichern, aber unvalidierte Daten (Backup-Import, Legacy) erreichen die
  Berechnung trotzdem. Lehre: Die Compute-Schicht muss defensiv korrekt sein
  (ihrem dokumentierten Verhalten folgen), nicht auf die Validierung des
  Schreibpfads vertrauen — besonders, da Restore Daten direkt in den Store
  schreibt.
- **Keine zweite Farb-/Wert-Quelle neben der SSOT (v2.1.5).** Der Monatschart
  hatte eine eigene hartkodierte 2-Farben-Palette (`utilityColor`: nur gas/strom,
  Rest blau) statt der Utility-`color` aus der SSOT — 6 von 8 Verbrauchsarten
  bekamen die falsche Chart-Farbe, während der Rest der UI `u.color` nutzte. Plus:
  PHP `modify('+N months')` überläuft am Monatsende (31.08. + 6 Mon. → 03.03.
  statt 28.02.) — Tag auf die Ziel-Monatslänge clampen. Lehre: jede Anzeige-
  Eigenschaft, die schon in der SSOT (Utilities) steht, von dort ziehen; und
  Datums-Arithmetik gegen Monatsende-Überlauf absichern.
- **Ein Ausschlussfilter gilt für jeden Konsumenten (v2.2.0).** `is_shadow` wurde
  im `ConsumptionService` an beiden Stellen gefiltert, im `ForecastService` aber
  nicht — obwohl `ContractService::create()` im Kommentar zusicherte,
  Schattenverträge flössen nicht in die Prognose ein. Sobald der letzte echte
  Vertrag vor dem Prognosehorizont endete, rechnete die Prognose mit der
  Hypothese. Dieselbe Klasse wie die Subzähler-Doppelzählung aus v2.1.3: Ein
  Ausschluss, der nur an manchen Aufrufstellen sitzt, ist kein Ausschluss. Lehre:
  Roh-Getter (`contracts->list()`) verpflichten den Aufrufer — besser ist ein
  Getter, der den Filter erzwingt; und eine Zusicherung im Kommentar ist kein
  Mechanismus, ein Test schon.
- **Nachgezogene Sprachen brauchen einen Vollständigkeits-Check (v2.2.0).**
  `lib/format.js` bildete Zahl-, Datums- und Monatsformat auf einer
  handgepflegten `{de, en}`-Tabelle ab; die 2026 ergänzten Sprachen fielen still
  auf `de-DE` zurück, fünf von sieben Oberflächen zeigten deutsche Zahlen und
  „Mär/Mai/Dez". Lehre: Eine neue Sprache (oder Verbrauchsart) ist erst fertig,
  wenn jede Stelle mit einer Aufzählung mitgezogen wurde — Katalog, Formatierung,
  CSS-Token, Default-Namen. Wo die Plattform es kann (`Intl`), gehört keine
  eigene Tabelle in den Code.
- **Ein Default statt eines Fehlers ist Datenverlust (v2.2.0).**
  `collectWaterForm()` machte via `parseFloat(x || 0)` aus einem vergessenen
  Preis einen gültigen Tarif von 0 ct/m³. Der Backend-Guard konnte nicht greifen,
  weil beide Felder gefüllt ankamen. Lehre: `|| 0` auf Benutzereingaben zerstört
  genau die Information, die der Validierungspfad braucht („nicht ausgefüllt") —
  leer weiterreichen und den Guard entscheiden lassen.
- **Eine Kennzahl braucht einen Bezugsraum, sonst lügt sie (v2.2.0).** Der
  Tarifvergleich meldete je Zeile den Gesamtverbrauch des Zeitraums, rechnete
  die Kosten aber nur über die Monate des jeweiligen Vertrags. Beide Zahlen
  waren für sich richtig — nebeneinander ergaben sie eine erfundene Ersparnis
  (49 % statt real 15 %). Lehre: Wenn eine Tabelle Werte vergleichbar
  nebeneinanderstellt, muss jede Spalte denselben Bezugsraum haben; wo Laufzeiten
  verschieden sind, braucht es eine normierte Größe (hier Vollkosten je Einheit).
  Und: Kosten und Zahlungsströme (Abschläge, Sonderzahlungen) nicht vermengen —
  sie beantworten verschiedene Fragen.
- **`vendor` in .gitignore trifft jedes Verzeichnis (v2.2.0).** Beim
  Selbst-Hosten von Schriften und Chart.js unter `public/vendor/` hätte das
  ungeankerte Muster `vendor/` die Dateien aus Repository UND Docker-Image
  gehalten — die Anwendung wäre ohne Schrift und ohne Diagramme ausgeliefert
  worden, ohne dass ein Test es merkt. Lehre: gitignore-Muster ohne führenden
  Schrägstrich greifen auf jeder Ebene; neu hinzugefügte Asset-Verzeichnisse
  gegen `git check-ignore` prüfen und die Auslieferung im Docker-Smoke-Test
  festnageln.
- **Was am Releasetag von Hand nachgezogen wird, wird irgendwann vergessen
  (v2.2.0).** `docker-compose.yml` pinnte über sieben Releases hinweg noch
  `1.9.3`; wer aus dem Repository startete, bekam eine Version vor dem gesamten
  v2.x-Bündel. Lehre: Jede Versionsangabe außerhalb der Datei `VERSION` gehört
  in einen Test (siehe `ReleaseConsistencyTest`) — Service-Worker-Cache,
  Compose-Pin, CHANGELOG-Abschnitt, README- und INSTALL-Stempel.
- **Verhalten darf nicht am Wortlaut einer Meldung hängen (v2.2.1).**
  `ErrorHandler::statusFor()` erkannte „nicht gefunden" per `str_contains` und
  leitete daraus 404 ab. Solange alle Meldungen deutsch waren, funktionierte
  das; mit der Lokalisierung ab v2.0.0 traf es nur noch Deutsch und Englisch —
  eine spanische Oberfläche („no encontrado") bekam **500 statt 404**. Lehre:
  Sobald Texte übersetzt werden, wird jede Textprüfung im Code zur Zeitbombe.
  Bedeutung gehört in den Typ (hier `Http\NotFoundException`), Text nur in die
  Anzeige. Beim Einführen von i18n gezielt nach `str_contains`, `match` und
  `switch` über Meldungstexte suchen.
- **Ein Cache-Buster, der nur den Einstiegspunkt versioniert, versioniert nichts
  (v2.2.3).** `index.php` hängt `?v=<version>` an `app.js` — die Module
  importieren einander aber mit nackten Pfaden (`./lib/sidebar.js`). Unter
  `stale-while-revalidate` lieferte der Service Worker sie nach einem Update aus
  dem alten Cache aus: Eine frische `app.js` traf auf ein altes `sidebar.js`
  ohne den erwarteten Export, der Modulgraph brach mit einem SyntaxError ab, und
  die Oberfläche blieb bei „Lädt…" stehen. Jede laufende Installation war betroffen.
  **Lehre:** Bei ES-Modulen entscheidet nicht der Einstiegspunkt über die
  Frische, sondern der schwächste Baustein. Anwendungscode gehört daher
  network-first in den Service Worker — der Geschwindigkeitsgewinn von
  stale-while-revalidate wiegt einen Totalausfall nicht auf. Und: Wer einen
  Export hinzufügt, ändert damit den Vertrag zwischen zwei Dateien; über
  Versionsgrenzen hinweg ist das ein Breaking Change, den kein Test im selben
  Stand sieht. Eine Selbstheilung in der Shell (Cache-Version ≠ Shell-Version →
  abräumen und einmal neu laden) fängt genau diesen Fall ab.
  Zweite Lehre, unabhängig davon: Dieses Loch **war im Review benannt** und
  blieb offen, weil es als „C12, Cache-Buster" unter Politur einsortiert wurde.
  Ein erkannter Defekt am Auslieferungsweg ist keine Politur.
- **Demo-Daten sind Teil des Features (v2.2.2).** Weder `demo-data/reminders.json`
  noch das Demo-Backup führten Termine — wer die Demo lud, sah ein leeres Modul
  und hielt es womöglich für kaputt. Exakt dieselbe Klasse wie die fehlenden
  Heizöl-/Pellets-Lieferungen in v2.1.2: Das Backup trägt eine feste Feldliste,
  und ein neuer Datentopf muss dort mitgezogen werden. Lehre: Ein neues
  Datenmodul ist erst fertig, wenn die Demo-Daten es zeigen — **beide** Wege
  (Verzeichnis kopieren und „Demo laden") mit demselben Inhalt. Der Test
  `DemoServiceTest::testDemoDirectoryAndBackupCarryTheSameReminders` hält die
  beiden Wege künftig zusammen.
- **Ein PDF ist prüfbar (v2.2.2).** `PdfReportService` blieb lange ungetestet,
  weil „ein PDF kann man schlecht prüfen". Der `PdfWriter` schreibt jedoch
  unkomprimiert: `preg_match_all('/\((.*?)\) Tj/s', $pdf)` liefert die
  gedruckten Textfragmente, und damit lassen sich die Kennzahlen direkt
  vergleichen. Ein Vergleich der Dateigröße taugt dagegen nicht — ein zusätzlicher
  Zähler fügt eine eigene Seite hinzu und verschiebt die Länge, ohne dass eine
  Summe falsch wäre.
- **Screenshots veralten lautlos (v2.2.1).** Die UI-Referenz zeigte Bilder aus
  v1.9.2 — darunter den Tarifvergleich mit genau dem Rechenfehler, den v2.2.0
  behoben hat. Kein Test schlägt an, wenn ein Bild alt ist. Lehre: Bei einer
  sichtbaren Änderung an einer Ansicht gehört der Screenshot in dasselbe
  Release. Aufnahme mit Demo-Daten, hellem Theme und einem hohen Ansichtsfenster
  (1440 × 1500) statt `fullPage` — bei ganzseitigen Aufnahmen bricht die
  fixierte Seitenleiste ab. Danach durch `pngquant`.

---

[← Tests](05-testing.md) ·
[Kompendium-Index](../README.md)
