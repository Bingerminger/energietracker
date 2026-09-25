# Release-Prozess

**Deutsch** · [English](../en/entwicklung/release-prozess.md)

[← Tests](tests.md) · [Kompendium-Index](../README.md)

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
   geänderte Endpunkte → `referenz/api.md`; geändertes Verhalten/Modell →
   betroffene Seite unter `verstehen/`; neue/­geänderte View →
   `referenz/ansichten.md` + neuer Screenshot in `ui/screenshots/` (englisch
   in `ui/screenshots/en/`); Migrationshinweise bei Datenmodell-Änderung in
   `referenz/datenmodell.md`; neue Einstellung → `referenz/einstellungen.md`
   (der Test verlangt jeden Schlüssel).
6. **Tests** grün: `frontend-api-shape` + `browser-render` (inkl.
   Modulgraph-Vorprüfung). Seit v1.4.4 laufen beide plus ein
   PHP-Syntax-Lint automatisch in der CI (`.github/workflows/ci.yml`)
   — der grüne CI-Lauf ist Voraussetzung fürs Taggen. Bei
   Datenmodell-Änderung Demo-Daten und Schemata mitziehen.
7. **Frischer Smoke gegen einen sauberen Datenstand**: Server mit
   `ET_DATA_DIR` auf eine Kopie von `demo-data/` starten, Migration prüfen,
   Kern-Endpunkte und die geänderten Pfade abfragen. Nie gegen das lokale
   `data/` — dort liegen echte Nutzdaten.

---

## 3. Doku-Pflege-Regel (ab v1.4.2)

Das Kompendium ist Teil des Releases, **nicht** ein nachgelagertes
Extra. Faustregel je Änderungstyp:

| Änderung | zu pflegende Doku |
|---|---|
| neuer/geänderter Endpunkt | `referenz/api.md` |
| geändertes Berechnungs-/Datenmodell | passende Seite unter `verstehen/` + ggf. `referenz/datenmodell.md` |
| neue Einstellung | `referenz/einstellungen.md` |
| neue Verbrauchsart | neue Seite unter `verstehen/`, Index, Architektur |
| neue/­geänderte View | `referenz/ansichten.md` + Screenshot unter `ui/screenshots/` (echte Aufnahme mit Demo-Daten, englisch unter `ui/screenshots/en/`) |
| neue Frage oder Störung | `einstieg/faq.md` bzw. `betrieb/fehlersuche.md` |
| neue Lesson Learned | hier in diesem Dokument |

Inhalte des Produkts (Code/Doku) werden nur geändert, wenn der Nutzer
es explizit anstößt — keine ungefragten „Best-Practice-Refactorings".

---

## 4. Git-Veröffentlichung

**Zwischen Push und Tag liegt das CI-Gate** — nicht beides in einem Schritt:

```bash
# 1. Commit und Push, noch OHNE Tag
git add -A
git commit -m "vX.Y.Z — <Kurzbeschreibung>"
git push origin main

# 2. Auf die CI warten. Erst bei grün weiter.
#    (Jobs: test, lint-php, phpunit, docker)

# 3. Tag setzen und pushen → löst den GHCR-Publish aus
git tag -a vX.Y.Z -m "vX.Y.Z — …"
git push origin vX.Y.Z
```

Ein Tag ist die Grundlage für Container-Image und GitHub-Release. Er darf erst
entstehen, wenn die Pipeline grün ist. `git push origin main --tags` schiebt
ihn hinaus, bevor die CI ihn bestätigt hat.

Danach das **GitHub-Release** erstellen (`gh release create vX.Y.Z
--verify-tag --latest`). Es ist die Freigabemarke: Veröffentlicht wird, was
abgenommen ist.

> Bis v2.3.3 stand hier ein ZIP-basierter Ablauf. Seit v2.0.0 trägt kein
> Release mehr Anhänge — die Installation läuft über das Container-Image von
> GHCR, `git clone` oder `git checkout` eines Tags. Der Abschnitt ist mit
> v2.3.4 entfallen.

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
- **„Ein Default statt eines Fehlers" lebte im Frontend weiter (v2.5.3).**
  `type="number"` liefert für „12,5" je nach Browser einen leeren String, und
  `Number("")` ist 0 — zehn Formulare buchten so still eine 0, darunter der
  Endstand beim Zählertausch. Zahlen werden seitdem als Text mit
  Dezimaltastatur erfasst und von **einem** Parser (`parseDecimal`) gelesen, der
  „leer" von „0" unterscheidet. Wer ein Zahlenfeld anlegt, nimmt diesen Weg.
- **Eine Vorlage, die Nutzer kopieren, ist Code (v2.5.3).** Das
  Home-Assistant-Snippet stand in der App und in der Anleitung — zwei
  Fassungen, beide falsch: `float(0)` buchte bei nicht verfügbarem Sensor einen
  Zählerstand 0, `"Bearer !secret …"` schickte den Text wörtlich. Die Vorlage
  lebt jetzt in einem Modul (`public/js/lib/ha-snippet.js`), und
  `tests/ha-snippet.test.mjs` hält beide Sprachfassungen der Anleitung daneben.
- **Ein Scroll-Rahmen beschneidet nur, was sich an ihm ausrichtet (v2.5.3).**
  Das unsichtbare `.sr-only`-Label „Aktionen" im Tabellenkopf ist absolut
  positioniert; sein Bezugsrahmen lag außerhalb des `overflow-x: auto` — die
  ganze Seite wurde auf dem iPhone breiter. `.table-wrap` trägt deshalb
  `position: relative`. Gemessen wird Überbreite über
  `document.documentElement.scrollWidth`, nicht über die Lage einzelner
  Elemente: Beschnittene Elemente melden ihre volle Breite.
- **Das negative Intervall zu verwerfen ist kein Schutz (v2.6.0).** Nach
  einem falschen Stand (eine 0 aus Home Assistant) verwarf die
  Verbrauchsrechnung nur den Rückgang und zählte das nächste Intervall ab dem
  falschen Stand voll — ein Monat bekam den ganzen Zählerstand als Verbrauch.
  Der Fehler sitzt im **Stand**, nicht im Intervall: Eingeklemmte Ausreißer
  werden jetzt als solche erkannt und fallen heraus (`plausibleReadings`).
  Lektion 6 in neuem Gewand — die Ursache prüfen, nicht das Symptom.
- **Ein Schutz, der nur eine Tür bewacht, suggeriert ein Schloss (v2.6.0).**
  Der Home-Assistant-Token schützte nur den Push; der Text in den
  Einstellungen las sich, als schließe er die API. Und eine Anmeldung ohne
  Webserver-Regeln wäre über `data/backups/` zu umgehen gewesen. Reihenfolge
  deshalb: erst die Auslieferungsregeln (Apache, nginx, Entwicklungsserver),
  dann die Anmeldung — und jeder Hinweistext sagt, **was** geschützt ist.
- **Eine Routenliste ohne Test ist eine Behauptung (v2.6.0).** `docs/API.md`
  kannte 37 von 70 Routen, die Referenz behauptete „68, v1.9.2", dokumentierte
  Körper funktionierten nicht, und `verdict` wurde in v2.0.0 still gebrochen.
  `ReleaseConsistencyTest` vergleicht seitdem die Routen aus `bootstrap.php`
  mit der Referenz in beiden Sprachen; früher dokumentierte Feldnamen gelten
  als Alias weiter. Eine Stabilitätszusage legt fest, was sich ändern darf.
- **Listener an einem langlebigen Container gehören abgemeldet (v2.6.0).**
  Die Einstellungen rendern sich nach Import oder Sprachwechsel selbst neu;
  jede Runde hängte `input`- und `beforeunload`-Listener an denselben
  Container und an `window`. Folge: eine falsche „ungespeichert"-Warnung beim
  Schließen. Listener, die an Objekten außerhalb der eigenen Ansicht hängen,
  werden in einer Liste gesammelt und beim nächsten Rendern abgemeldet. Und:
  Eine Meldung darf den Knopf nicht verdecken, auf den sie sich bezieht —
  bei offenem Dialog erscheinen Toasts oben.
- **„Sprache-Land" ist nicht immer eine Region, die jemand will (v2.7.0).**
  Der erste Entwurf der Länderprofile bildete die `Intl`-Region als
  `${sprache}-${land}` — für Englisch mit dem Default-Land DE also „en-DE",
  und `Intl` schreibt dort deutsche Zahlen. Jede bestehende englische
  Installation hätte sie über Nacht bekommen. Aufgefallen ist es an einem
  älteren Test (`format.test.mjs`), der englische Zahlen erwartete. Regel
  seitdem: Das Land verfeinert nur Sprachen, die dort gesprochen werden;
  das Backend folgt derselben Regel. Und `Intl` trennt Beträge teils anders
  als Zahlen (de-AT „€ 1.234,56" neben „1 234,5") — das Backend bildet es
  nach, damit PDF und Oberfläche gleich schreiben.
- **Ein Default-Profil ist ein Versprechen an alle Bestandsinstallationen
  (v2.7.0).** Dass das deutsche Profil genau den bisherigen Defaults
  entspricht, prüft `CountriesTest` — sonst würde eine kleine Abweichung
  (Standortname, CO₂-Wert) still jede Installation verändern, die „Alle
  übernehmen" wählt. Beim Erststart schreibt die App nur Abweichungen vom
  Default fest; wer alle Profilwerte schreibt, friert die Defaults ein, und
  eine spätere Korrektur erreicht neue Installationen nicht mehr.
- **Eine Erwartung, die den geprüften Wert enthält, prüft nichts (v2.8.0).**
  Das Saisonmittel der Anomalie-Erkennung enthielt den geprüften Monat selbst,
  der Trend maß vor allem, in welchem Monat die Daten enden, und Heizarten
  bekamen im Sommer die Erwartung 0 — jeder Sommer war ein „Ausreißer", ein
  echter +35-%-Februar ging unter. Erwartungen gehören zum Monat (Heizmodell,
  derselbe Kalendermonat anderer Jahre), der geprüfte Wert nie in die eigene
  Erwartung, und die Streuung wird robust geschätzt.
- **Synthetische Testdaten müssen die alte Rechnung widerlegen können
  (v2.8.0).** Mehrere neue Regeln waren auf den ersten Testdaten auch
  **ohne** die Regel grün: kein Rauschen, jedes Jahr dasselbe Klima. Erst die
  Gegenprobe — Regel gezielt zurückdrehen, Test muss rot werden — zeigte das.
  Seitdem bekommt jede Rechenregel eine Gegenprobe, und die Testdaten werden
  so gebaut, dass der alte Weg sichtbar falsch liegt.
- **Ein Saldo folgt dem Kalender, nicht den Ablesungen (v2.8.0).** Die
  Abschläge zählten nur für abgelesene Monate: Wer zuletzt im März ablas, sah
  im September den Saldo von drei Monaten, während neun Abschläge abgebucht
  waren. Und eine Aufschlüsselung muss ihre Summe ergeben — die erste Fassung
  der neuen Karte zeigte Teile, denen der Grundpreis der Schätzmonate fehlte.
  Dasselbe galt für die Jahreskacheln daneben; sie nennen jetzt ihren Stand.
- **Eine Einstellung ohne Wirkung ist eine falsche Auskunft (v2.8.0).**
  `confidence_band_sigma` und `weather_auto_fill` standen in den
  Einstellungen und taten nichts. Beide wirken jetzt — und der tägliche Abruf
  bei Open-Meteo machte den README-Satz „stellt keine externen Anfragen"
  falsch. Wer eine Einstellung zum Leben erweckt, prüft, welche Aussagen der
  Doku an ihrem Nichtstun hingen.
- **Eine Regel, eine Stelle (v2.8.0).** Welche Monate in die Heizkurve
  eingehen, entschieden Analyse, Bereinigung, Prognose und Anomalien je leicht
  anders; derselbe Zähler zeigte R² 0,42 in der Analyse und 0,56 in der
  Prognose. Jetzt entscheidet `ConsumptionService::isRegressionCandidate()`,
  und das Chart zeichnet genau die Punkte, die im Fit stecken. Lektion 26
  (Monatsarithmetik) schnappte dabei zweimal fast wieder zu — in einem Test
  (`strtotime('-8 months')`) und im Kalibrierfenster (`-12 months` am
  29. Februar); beide rechnen jetzt vom Monatsersten.
- **Eine Formel für Jahreswerte taugt nicht ungeprüft für Monate (v2.8.0).**
  Die erste Fassung von `heat_adjusted` skalierte den Heizanteil
  (`Ist − Grundlast`) mit `HGT_normal / HGT_ist`, wie VDI 3807 es für
  Jahreswerte vorsieht. In einem warmen September mit 11 statt 30 Gradtagen
  ist dieser „Heizanteil" aber Rauschen — ×2,7 ergab +32 %, genug, um den
  Jahrestrend über seine 3-%-Schwelle zu schieben. Jetzt wird nur der
  Wettereinfluss laut Modell umgerechnet (`Ist + a × (HGT_normal − HGT_ist)`).
  Die Tests waren grün, weil ihr synthetisches Klima jedes Jahr gleich war und
  das Verhältnis damit immer 1; aufgefallen ist es erst beim Lesen des fertigen
  PDF-Berichts. Testdaten brauchen ein Jahr, das vom Normal abweicht.
- **Jede Hochrechnung braucht den Fall „nichts hochzurechnen" (v2.8.1).**
  Die Saldo-Hochrechnung aus v2.8.0 rief ihre Schätzung auch dann auf, wenn
  es keine gab — ein Vertrag an einem Zähler mit weniger als zwei Ablesungen,
  der übliche Einstieg eines neuen Nutzers. `contract-status` antwortete mit
  500, die Verbrauchsansicht zeigte nur die Fehlermeldung. Browser- und
  Shape-Tests laufen gegen die vollständigen Demo-Daten und sehen diesen
  Zustand nie. Aufgefallen ist es beim Schreiben eines Tests für das nächste
  Paket. Seitdem gehört zu jeder neuen Rechnung ein Test mit leerem und mit
  vollständig ausgeschlossenem Datenstand (etwa alles vor einer Zäsur).
- **Ein Test kann auch einen Fehler festschreiben (v2.9.0).**
  `ContractEdgeCasesTest` sicherte seit Jahren ausdrücklich zu, dass ein
  Vertragswechsel zum 15. den ganzen Monat dem alten Vertrag zuschlägt
  (`…AttributesEntireMonthToContractActiveOnFirst`). Der Test beschrieb das
  Verhalten, nicht die Rechnung des Versorgers — und stand damit der Korrektur
  im Weg. Ein solcher Test wird nicht still angepasst: Er wird umbenannt
  (`…SplitsTheMonthByDay`), das CHANGELOG nennt die geänderten Werte, und die
  Gegenprobe zeigt, dass der neue Test den alten Weg ablehnt.
- **Ohne Datensatz ist nicht null (v2.9.0).** Verbrauch nach einem
  vergessenen Vertragsende kostete 0 € — die App nahm an, mit dem Enddatum ende
  auch die Rechnung. Der Versorger rechnet weiter. Verwandt mit Lektion 24:
  Wo Daten fehlen, gehört die fachlich richtige Annahme hin (der Vertrag läuft
  weiter) und ein Hinweis, dass es eine Annahme ist — keine stille Null.
- **Eine Erinnerung zählt bis zum Handeln, nicht bis zum Ereignis (v2.9.0).**
  Die drei Stufen zählten bis zum Vertragsende. Mit einem Monat Frist kamen
  zwei von drei Erinnerungen nach dem letzten Kündigungstag — pünktlich und
  nutzlos. Wer erinnert, rechnet vom letzten Tag zurück, an dem man noch
  etwas tun kann.
- **Ein Vergleich braucht denselben Zeitraum (v2.9.0).** Ein Schattenvertrag
  galt nur für seine Laufzeit. Ein Angebot, das von April bis September
  eingetragen war, sah damit günstiger aus als dasselbe Angebot fürs ganze
  Jahr: Ihm fehlte der Winter. Ein Angebot ist ein Preisblatt und gilt für den
  ganzen Vergleichszeitraum.
- **Was „inaktiv" heißt, stand an vier Stellen verschieden (v2.9.0).**
  Verbrauchsansicht und CSV zählten einen abgewählten Zähler mit, PDF-Bericht
  und Effizienzklasse nicht — derselbe Zähler, zwei Jahressummen. Dieselbe
  Klasse wie Lektion 19 und 22. Jetzt steht die Bedeutung an einer Stelle
  (`MeterService::countsInTotals()` für Summen, `inService()` für Erfassung
  und Warnungen): Ein Zähler außer Betrieb hat trotzdem eine Vergangenheit.
- **Eine wirkungslose Einstellung entfernt man nicht still (v2.9.0).**
  `min_temp_days_forecast` und `baujahr` taten nichts. Aus der Oberfläche
  sind sie verschwunden, aus der API nicht: Wer sie per Skript setzt, bekäme
  sonst ohne Ankündigung einen anderen Stand zurück. Sie sind als veraltet
  markiert und entfallen erst mit einer Major-Version.
- **Zwei Rechnungen für dieselbe Größe widersprechen sich irgendwann
  (v2.10.0).** Bei Heizöl und Pellets kamen die Kosten aus einer Bilanz, die
  den Tank heute für leer hielt, die Bestandskurve aus einer kalibrierten
  Rate. Lektion 12 hatte das Auseinanderziehen empfohlen — richtig war es
  nur, solange beide Annahmen stimmten. Die der Kosten stimmte nie: Eine
  Lieferung von heute erhöhte die Vorjahre um 20 %. Die Antwort war nicht ein
  zweites Modell, sondern ein richtiges: bekannte Bestände als Stützstellen,
  eine Rechnung für Verbrauch, Kosten und Kurve.
- **Eine Größe, die ein Jahr beschreibt, gehört aus einem Jahr geschätzt
  (v2.10.0).** Die Tagesform des Tankbuchs braucht die Gradtage eines
  Normaljahrs. Die erste Fassung nahm sie aus dem Fenster des Tanks — wer im
  Mai anfing, hatte ein „Jahr" mit kaum Gradtagen, die im Sommer kalibrierte
  Rate wurde im Herbst zu 767 L am Tag. Aufgefallen ist es an einem Test mit
  Daten relativ zu heute. Jetzt: Klimanormal, sonst eine Historie mit allen
  zwölf Monaten, sonst ein grobes Monatsmittel.
- **Ein korrigierter Default braucht einen Migrationsschritt (v2.10.0).**
  Lektion 36 angewandt: Gas-, Pellet-, Fernwärme- und Strom-CO₂ und die
  Wasser-Referenz haben neue, belegte Defaults. Wer sie nie gespeichert hat,
  hätte ohne Zutun andere Zahlen gesehen. Schema 1.6.0 schreibt bei
  Bestandsinstallationen die alten Werte fest — aber nur bei Daten älter als
  1.6.0, sonst würde ein späterer Schritt einer neuen Installation alte Werte
  aufzwingen. Die Einstellungen bieten den Wechsel an.
- **Eine Einheit an der Oberfläche ist eine Behauptung (v2.10.0).** Die
  CO₂-Faktoren für Heizöl und Pellets waren mit g/L und g/kg beschriftet,
  gerechnet wurde je kWh — neun Releases lang, weil kein Test die Beschriftung
  mit der Rechnung verglich. Der Render-Test prüft sie jetzt.
- **Ein Kennzeichen wirkt nur, wo es gelesen wird (v2.10.0).**
  `accounting_kind` gibt es seit v1.7.0, aber Jahresbericht, Tarifwechsel
  und die Erzeugungsansicht fragten es nie: Die Einspeisung stand als
  „Kosten", mehr Vergütung als „teurer", die Erzeugung als Emission.
  Dieselbe Klasse wie Lektion 22 — eine Eigenschaft, die nur manche
  Konsumenten beachten, ist keine.
- **Quoten brauchen dieselbe Grundlage in Zähler und Nenner (v2.10.0).** Die
  PV-Quoten summierten Jahreswerte von Zählern mit unterschiedlicher
  Abdeckung: Ein Erzeugungszähler ab Juli ergab „Eigenverbrauch 0". Jetzt
  zählen nur Monate, in denen alle drei Zähler Daten haben, und die Karte
  sagt, wie viele das sind.
- **Ein gemeinsamer Container ist ein Wettlauf (v2.11.0).** Alle Ansichten
  schrieben nach ihrem `await` in `#view`. Wer schnell weiterklickte, sah die
  Antwort der vorigen Seite unter der neuen Überschrift, und deren Cleanup ging
  verloren. Die Lösung liegt im Router, nicht in zwölf Ansichten: je Navigation
  ein eigener Container, ein Token und ein Abbruchsignal. Eine verspätete
  Ansicht schreibt ins Leere und räumt danach auf.
- **`history.back()` nach einer Navigation macht sie rückgängig (v2.11.0).**
  Damit die Zurück-Taste einen Dialog schließt, legt er einen
  History-Eintrag an und nimmt ihn beim Schließen zurück. Schließt ihn
  dagegen ein Seitenwechsel, muss der Eintrag stehen bleiben — sonst springt
  der asynchrone Rückschritt auf die alte Seite. Der Router schließt Dialoge
  deshalb mit dem Grund „Navigation".
- **Kontraste gehören an einen Test (v2.11.0).** v2.2.0 hatte sie schon einmal
  korrigiert. Danach senkten ein Farbverlauf auf den Knöpfen, eine feste
  Mischung „62 % mit Schwarz" für jede Verbrauchsart und weiße Schrift auf
  Orange sie wieder unter 4,5:1, ohne dass es jemand merkte.
  `tests/contrast.test.mjs` rechnet jetzt jedes Paar aus Schrift und Fläche
  aus den Token nach.
- **Beim lokalen Testen liefert der Service Worker alte Dateien (v2.11.0).**
  Statische Dateien kommen aus seinem Cache, und er vergleicht ohne Query. Im
  Release wechselt der Cache mit der Version; zwischen zwei Ständen derselben
  Version zeigt der Browser aber alte CSS. Für Sichtprüfungen den Worker vorher
  abmelden.
- **Ein Trenner je Zeile (v2.12.0).** Der Temperatur-Import trennte mit
  `[;,]` — in Ländern mit Dezimalkomma trifft das die Zahl selbst: Aus
  `01.01.2024;4,2;-1,0;7,1` wurden Mittel 4, Min 2, Max −1. Aufgefallen ist es
  erst, als ein Test das übliche Excel-Format einlas. Den Trenner an der Zeile
  erkennen (Semikolon, sonst Tabulator, sonst Komma), nicht als Zeichenklasse
  raten; der Ablesungs-Import machte es schon so.
- **Vorbelegt ist nicht eingegeben (v2.12.0).** Die erste Preiszeile eines
  neuen Vertrags trägt jetzt den Vertragsbeginn. Ohne weitere Vorkehrung wäre
  eine Zeile mit Datum und ohne Betrag „halb ausgefüllt" gewesen und hätte das
  Speichern blockiert — etwa bei einem Vertrag ohne Abschläge. Die Vorbelegung
  trägt deshalb eine Markierung (`data-auto`), bis jemand das Datum ändert;
  eine markierte Zeile ohne Betrag gilt als leer. Die m³-Umrechnungshilfe
  suchte „die erste Zeile ohne Datum" und fand keine mehr — ein bestehender
  Render-Test fiel, bevor es jemand bemerkte.
- **Rückgängig braucht einen Rückweg in der API (v2.12.0).** „Erledigt" und
  „Ausblenden" ließen sich nicht zurücknehmen: `last_done` war nicht änderbar,
  und für das Ausblenden gab es kein Gegenstück. Ein Toast mit „Rückgängig"
  ist schnell gebaut, der Rückweg im Datenmodell nicht; beides gehört in
  denselben Change, sonst verspricht die Oberfläche etwas, das sie nicht kann.
- **Ein lokaler Name überschattet einen Import (v2.13.0).** In der Prognose
  hieß das Element `#fc-info` seit jeher `info`; seit v2.13.0 heißt so auch
  der ⓘ-Knopf. Die lokale Variable gewann, `info('balance')` warf, und der
  Fehler landete im `try/catch` der Ansicht: Die Seite stand, nur die Tabelle
  blieb leer. Ein bestehender Render-Test fiel; ein Scan über alle Module fand
  keinen zweiten Fall. Kurze Namen für geteilte Helfer sind bequem und
  kollidieren genau deshalb.
- **Ein Tooltip ist keine Erklärung (v2.13.0).** Gut dreißig Erklärungen
  standen in `title`-Attributen — am Mac beim Überfahren sichtbar, auf dem
  iPhone gar nicht. Jetzt öffnet ein ⓘ sie zum Antippen, aus einem Katalog,
  der zugleich das Glossar der Hilfe ist; ein Test hält Liste und Katalog
  deckungsgleich.
- **Ein Vorzeichen ist eine Perspektive (v2.13.0).** Der Saldo rechnet
  Kosten − Abschläge, Minus heißt Guthaben: richtig für die Buchhaltung,
  falsch für jemanden, dessen Abrechnung „Guthaben“ sagt. Rechnung und
  Schnittstelle behalten ihr Vorzeichen, Integrationen hängen daran; die
  Oberfläche übersetzt in Worte und dreht es in Tabellen, mit Legende.
  Dasselbe bei „CO₂ vermieden −674 kg“: Das Wort trägt die Richtung, ein Minus
  davor verneint doppelt.
- **Zusammengesetzte Schlüssel sieht keine Literalprüfung (v2.13.0).**
  `` t(`glossary.${id}.term`) `` rutscht durch den Test auf literale Schlüssel;
  ein fehlender Eintrag stünde roh in der Oberfläche. Geprüft wird jetzt über
  die Listen, aus denen die Schlüssel entstehen (Glossar, Einstellungsfelder).
- **Eine Doku ohne Test verrottet (v2.14.0).** Unterhalb von README und
  INSTALL prüfte bis v2.13 kein Test die Doku. Der Index versprach „Alle 68
  Endpunkte“, als es 86 waren, und Anleitungen führten durch Menüpunkte unter
  Namen, die die App seit Releases nicht mehr trägt („Merge-Wizard“,
  „Analyse → Heizsignatur“). `DocsIntegrityTest` prüft jetzt
  Links, Anker, Spiegel, Weiterleitungen, Index und die Einstellungsreferenz
  gegen `SettingsService::DEFAULTS`. Den Pfad vergleicht er Segment für Segment
  mit dem Verzeichnisinhalt: `file_exists` hätte auf dem Mac jeden Link mit
  falscher Groß-/Kleinschreibung durchgewinkt, der auf GitHub tot ist.
- **Umziehen heißt weiterleiten (v2.14.0).** 61 Dateien zogen um. Auf die
  alten Pfade zeigen Issues, Forenbeiträge und Lesezeichen von Leuten, die man
  nicht erreicht. Jede alte Datei bleibt als kurze Weiterleitung stehen; der
  Test verbietet, dass die Doku selbst eine davon verlinkt — sonst wird der
  Übergang zum Dauerzustand.
- **Ein dokumentierter Befehl muss einmal gelaufen sein (v2.14.0).** Die
  Installationsanleitung empfahl `python3 scripts/init_data.py --help`. Das
  Skript kannte keine Optionen: Der Aufruf startete den Import und schrieb nach
  `./data`. Jetzt antwortet `--help` vor jedem Import, und das Skript ist als
  veraltet gekennzeichnet.
- **Eine Übersetzung braucht ein Glossar (v2.14.0).** Im englischen Katalog
  hieß der Arbeitspreis „working price“ und „energy price“, der Grundpreis
  „base price“ und „base charge“ — vier Wörter für zwei Dinge, und die Doku
  benutzte wieder andere. Jetzt heißt es überall „unit price“ und „standing
  charge“, wie auf britischen Rechnungen. Dasselbe für Zahlwörter: „1 meters“
  und „group(s)“ sind keine Übersetzung; Zählungen laufen über
  `Intl.PluralRules`; der Katalogtest prüft, dass jeder Zählschlüssel seine
  Formen in jeder Sprache hat, und kennt die Kategorien, die Französisch,
  Spanisch, Italienisch und Portugiesisch zusätzlich brauchen.
- **Ein Update liest die Vorgaben nicht neu (v2.15.0).** Offene Diagramme
  sollten beim Theme-Wechsel umfärben: Vorgaben neu setzen, `chart.update()`.
  Die Balken folgten, die Achsen nicht — Chart.js kopiert die Achsen-Vorgaben
  beim Anlegen in die Konfiguration des Charts. Aufgefallen ist es erst im
  Browser; das Stub im Test kennt diese Kopie nicht. Jetzt löst die
  Chart-Schicht die kopierten Farben vor dem Neuzeichnen, und der Test bildet
  die Kopie nach.
- **Ein ersetztes Modul ist ein ungetestetes Modul (v2.15.0).** Der Render-Test
  lud an Stelle von `components/chart.js` einen Stub aus der Zeit, als Chart.js
  ein ES-Modul war. Registry, Farben und Beschreibungen der Diagramme liefen in
  keinem Test; erst die neuen Prüfungen scheiterten an fehlenden Exporten.
  Ersetzt wird nur, was die Umgebung nicht kann — hier das Canvas, also
  `window.Chart`, nicht die eigene Schicht darüber.
- **Ein Trend braucht denselben Zeitraum (v2.15.0).** Drei Monate gegen die drei
  davor maßen die Jahreszeit: Fernwärme +470 %, Gas −48 % mit einem halben März.
  Der Pfeil vergleicht jetzt dieselben vollen Monate ein Jahr zuvor, bei
  Heizarten witterungsbereinigt, und jede Karte der Übersicht nennt ihr
  Fenster, weil die Verbrauchsarten in verschiedenen Monaten enden.
  Beinahe hätte der neue Trend dabei das falsche Feld genommen: `weather_adjusted`
  heißt richtig, skaliert aber auch das Warmwasser und steht nur noch für die
  Schnittstelle da; bereinigt wird seit v2.8.0 über `heat_adjusted`. Ein Test
  rechnet den angezeigten Wert jetzt aus dem richtigen Feld nach.
- **Eine Beschreibung, die niemand sieht, prüft niemand (v2.15.0).** Die
  Kurzbeschreibungen von Jahresvergleich und Saisonprofil waren vertauscht, in
  allen Sprachen: Screenreader hörten „Balkendiagramm“ zu einer Linie. Jetzt
  nennen sie Zeitraum, Summe und Extreme, und der Render-Test prüft den Typ.

---

[← Tests](tests.md) ·
[Kompendium-Index](../README.md)
