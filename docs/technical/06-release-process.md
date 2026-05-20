# Release-Prozess

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
   → `ui/01-views.md` + Mockup; Migrationshinweise bei
   Datenmodell-Änderung in `technical/04-data-model.md`.
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
| neue/­geänderte View | `ui/01-views.md` + Mockup unter `ui/mockups/` |
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

---

[← Tests](05-testing.md) ·
[Kompendium-Index](../README.md)
