# Energietracker — Planung für Mitwirkende

Diese Seite ist die Arbeitsliste hinter der [Roadmap](roadmap.md): Regeln, Arbeitsweise und alle
offenen Punkte, sortiert nach dem Release, in das sie geplant sind. Konzepte und Entscheidungen
früherer Releases stehen unverändert im [Archiv](roadmap-archiv.md); was ausgeliefert ist, im
[CHANGELOG](CHANGELOG.md).

**Stand:** 2026-11-03 · **Baseline:** v3.10.0 · **Schema:** 1.8.0

---

## Leitlinien

- **Bewusst nicht:** eine eigene Smart-Meter-Auslesung (das macht Home Assistant), ein Cloud-Dienst
  mit Konten, eine eigene Steuerung von Speicher und Auto (das machen evcc und Home Assistant).
- **Ein Thema je Release,** dazu Altlasten und höchstens ein Schemaschritt. Ein Release umfasst, was
  in etwa eine Arbeitswoche passt; ein großes Thema darf bis zu drei Wochen dauern und wird sonst
  geteilt. Der Termin wird angekündigt (Milestone, Discussions → Announcements).
- **Wünsche der Nutzer** kommen über [Discussions → Ideas](https://github.com/Bingerminger/energietracker/discussions/categories/ideas)
  und werden mit 👍 gewichtet. Beim Release-Schnitt werden die Ideen bewertet: Nutzen für viele,
  Nähe zu Rechnung und Verständnis, Aufwand, Datenschutz. Angenommene Ideen werden ein Issue mit
  Milestone, abgelehnte bekommen eine kurze Begründung. Fehler bleiben Issues.
- **Prüfen gegen die Wirklichkeit** (seit v3.7.0): ein unordentlicher Testhaushalt, der nicht aus dem
  Modell der App stammt, ein Abgleich der Auswertungen mit echten Haushaltsdaten (nur lokal) und ein
  Skeptiker-Lauf vor jeder Freigabe, der jede angezeigte Zahl auf Herkunft und Plausibilität prüft.

## Prozess-Regeln

- **Feste Release-Tage (seit 2026-10-14).** Neue Versionen erscheinen an
  vorher genannten Tagen (v3.6.0: 20.10.2026), Fehlerbehebungen werden
  gesammelt; ein Sofort-Patch nur bei Datenverlust, Sicherheit oder einem
  Fehler, der die Nutzung verhindert. Sieben Versionen in sieben Tagen wirken
  für Haushalte unruhig und verteilen die Nutzer auf viele Stände.
- **Release-Schnitt im Assist-Modus (seit 2026-10-09).** Am Ende einer
  Entwicklungsphase legt der Assistent alles Offene als Auswahl vor —
  Eingang, Patch-Pool, offene GitHub-Issues, offene Punkte des letzten
  Abschlussberichts, Dependabot. Der Maintainer wählt im Chat, was ins
  Release kommt, und entscheidet die Architekturfragen gleich mit. Danach
  vergibt der Assistent die endgültigen Codes, schreibt den Plan hierher und
  liefert bis Prod. Ein Release hat ein Thema plus Altlasten und höchstens
  einen Schemaschritt.
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
- **Nach Release** fällt der Abschnitt des ausgelieferten Release hier weg
  (die Belege stehen im CHANGELOG), Offenes rückt in den nächsten Abschnitt;
  auf `roadmap.md` und `roadmap.en.md` wandert das Release nach „Zuletzt
  erschienen“. Das Detail-Konzept fließt in die Doku.

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

> **Zweisprachige Doku (DE+EN)** ist ab v2.1.0 die Norm: README/INSTALL als `*.md` (EN) + `*.de.md`
> (DE), `docs/` (DE) mit Spiegel `docs/en/` am selben relativen Pfad. DE bleibt kanonisch; ein Test
> prüft seit v2.14.0, dass jede Seite ihren Spiegel hat.

---

## Offene Punkte nach Release

Spalten wie bisher im Eingang: vorläufiger Code, Thema, Größe, Abhängigkeit, Auslöser. Zeilen aus dem
alten Eingang sind unverändert übernommen; neue Zeilen tragen das Datum 2026-10-28.

### v3.11.0 — Deine Rechte als Kunde (geplant 17.11.2026)

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| F10xx | **Rechte-Prüfungen** (2026-10-28): Rechnung zu spät (Frist nach Ende des Abrechnungszeitraums), Abschlag deutlich zu hoch (mit Antrag auf Anpassung), Preiserhöhung mit Sonderkündigungsrecht und Frist, Bonus nicht gutgeschrieben, Schätzung trotz gemeldetem Stand, Entgelt für den Messstellenbetrieb über der gesetzlichen Preisobergrenze, Widerspruchsfrist beim Gebührenbescheid (Wasser/Abwasser); jede Prüfung mit Quelle; Prüfbericht der Rechtsangaben vor dem Release | L | „Rechnung prüfen“, Verträge, Posteingang | Planung 2026-10-28 |
| — | **Rechtsangaben in bestehenden Hinweisen korrigieren** (Konzept v3.11.0, 2026-11-03): Fernwärme ohne Sonderkündigungsrecht nach § 41 Abs. 5 EnWG (AVBFernwärmeV), Laufzeitwarnung über 24 Monate nicht für Fernwärme und Wasser (§ 32 AVBFernwärmeV), Grundversorgung mit § 5 Abs. 3 StromGVV/GasGVV, vereinbarte Preisstufen und reine Umsatzsteueränderungen keine Preiserhöhung mit Kündigungsrecht (§ 41 Abs. 6 EnWG), Ausstellungsdatum der Rechnung aus dem Posteingang übernehmen; Quellen im Prüfbericht | S | — | [#55](https://github.com/Bingerminger/energietracker/issues/55) |
| — | **Widersprüchliche Verträge erkennen** (Skeptiker v3.10.0, M6, 2026-11-03): Überschneiden sich zwei Verträge laut Eintrag über Jahre (ein alter läuft über den Beginn eines späteren hinaus) oder trägt ein Vertrag offensichtlich den Tarif einer anderen Verbrauchsart, kappt die Vertragsliste den alten, das Abrechnungsjahr rechnet den späteren über sein Ende hinaus; daraus entstehen Abrechnungsjahre mit unplausiblem Guthaben, ohne Warnung. Vorschlag: Prüfung „Verträge widersprechen sich“ mit Hinweis, was zu klären ist, und eine Regel für Liste und Abrechnungsjahr | S–M | — | Skeptiker-Lauf 2026-11-03; [#54](https://github.com/Bingerminger/energietracker/issues/54) |
| F10xx | **Briefvorlagen aus den Befunden** (2026-10-28): Widerspruch, Sonderkündigung, Antrag auf Abschlagsanpassung, Rückfrage zur Schätzung; als Text und PDF, mit den Daten aus dem Befund | M | Rechte-Prüfungen | Planung 2026-10-28 |
| — | **Preisänderungsschreiben im Posteingang** (2026-10-28): neue Preise und Stichtag erkennen, Wirkung auf Kosten und Abschlag zeigen, Frist für die Sonderkündigung als Termin | M | v3.7.0 Posteingang | Planung 2026-10-28 |
| — | Zuständigkeit am Mietverhältnis (Nebenkosten- und CO₂-Fristen der Mieter landen bei einer Person) | S | v3.7.0 Zuständigkeit | Entscheidung 2026-10-21 |

### v3.12.0 — Energietracker für deine KI

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| F10xx | **Fernsteuerung durch eine KI (MCP):** Energietracker als MCP-Server (`/mcp`, Schlüssel mit Rechten lesen/schreiben). Werkzeuge: Übersicht, Fristen, Verträge, Verbrauch, Prognose-Szenario, „Rechnung prüfen“, Ablesung eintragen, Dokument einreichen und übernehmen; schreibende Werkzeuge nur mit Bestätigung im KI-Client. Ziel: Energietracker als zentrale Akte und Energieverwaltung, bedient von einer KI („hier ist meine Gasrechnung, trag sie ein und sag mir, ob sie stimmt“). Vorbereitung: maschinenlesbare Beschreibung der Schnittstelle (OpenAPI) | L | v3.7.0 Posteingang | Entscheidung 2026-10-21: nur Konzept, eigenes Release |
| **N1011** | API-Versionierung (`/api/v1/…`) | M | Ursprünglich als Smart-Meter-Vorbereitung geplant; mit dessen Streichung kein fester Slot mehr. Wird nur umgesetzt, wenn ein echter Bruch der API-Kompatibilität ansteht. |
| F10xx | HACS-Integration mit eigenen Sensoren | M–L | N1025 | Release-Schnitt 2026-10-13 (nicht gewählt) |

Ergänzung 2026-10-28: Bild „Die KI ist die Oberfläche, der Energietracker Akte, Auswertung und
Berichtswerkzeug dahinter“; jede Zahl mit Herkunft (gemessen, verteilt, geschätzt), damit eine KI nichts
erfindet; Berichtsvorlagen (Monats- und Jahresbericht) als MCP-Prompts. Die HACS-Integration wird mit
diesem Release veröffentlicht (eigenes Repo, Aufnahme in die HACS-Standardliste beantragen).

### v3.13.0 — Lange Verläufe und Umzug

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| N1006 | Speicher-Umbau (Cache über Anfragen hinweg, weniger Dateilesen): nach der Beschleunigung in v3.7.0 erst bei langen importierten Verläufen nötig; Schwelle in `entwicklung/messung-grosse-daten.md` | L | v3.7.0 Messung | Entscheidung 2026-10-21 |
| — | Heizmodell für gekrümmte Heizkurven (GitHub #38): Grundlast aus den Sommermonaten, Heizgrenze angepasst statt fest, ggf. quadratischer Term; vorher Vergleich über mehrere Haushalte, weil sich jede Erwartung ändert. #38 bleibt bis dahin offen | M–L | F1013 Heizmodell, v3.7.0 Gütesperre | GitHub #38, 2026-10-22 |
| — | Verbrauch zwischen zwei Ablesungen nach Wetter verteilen statt gleichmäßig (Gas nach Heizgradtagen, PV nach Globalstrahlung aus Open-Meteo) | M | v3.7.0 Kennung gemessen/verteilt | Antwort auf #39, 2026-10-22 |
| — | Beispielhaushalte nicht mehr aus dem Modell der App erzeugen (Heizkurve mit Heizgrenze unter 15 °C, Solargewinne, Speichermasse; einige Haushalte mit lückenhaften Ablesungen), damit Demo und Render-Tests auch Unsicherheit zeigen | M | v3.7.0 unordentlicher Testhaushalt | Entscheidung 2026-10-22 |
| — | **Umzug aus anderen Apps** (2026-10-28): Import der Exporte verbreiteter Verbrauchs-Apps samt Historie (Zählerstände, Verträge, Preise), mit Trockenlauf wie beim CSV-Import; Formate nach Auswahl der Apps | M–L | Massen-Import | Planung 2026-10-28 |

### v3.14.0 — Für alle verständlich

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| N10xx | Übersetzter Doku-Kern (Erste Schritte, Einrichtung, Anwendungsfälle) für fr/it/es/pt/nl, jede Übersetzung mit Quell-Hash, veraltet → Englisch | M–L | N1019 | Release-Schnitt 2026-10-12 („Kern später“; 2026-10-13 erneut zurückgestellt) |
| — | **Länderprofile Österreich und Schweiz** (2026-10-28): Preise, Steuern und Fristen je Land, Rechte-Prüfungen je Land oder ausdrücklich „nur Deutschland“ | M–L | N1014 Länderprofile, v3.11.0 | Planung 2026-10-28 |

> **Sprach-Wellen 2+** (cs, uk, pl, el, tr, hr, sr, sl, fi, no, da, lv, et, hu, bg, ro …)
> sind bewusst zurückgestellt (User-Entscheidung 2026-06-10) und werden
> bedarfsgetrieben in weiteren MINOR-Releases nachgereicht — je ~2–4 Sprachen,
> gleiche Methode (Katalog aus en.json, Parität-/Platzhalter-Check, in
> languages.json registrieren). Eine neue Sprache braucht keinen Code mehr.

### v4.0.0 — Mehrere Objekte

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| F10xx | **Mehrere Immobilien und kleine Verwaltungen** (2026-10-28): mehrere Objekte mit Einheiten in einer Installation, Rollen je Objekt, Nebenkosten- und Heizkostenabrechnung als Vermieter, Aufteilung des CO₂-Preises; Schemaschritt (Major). Vorher Konzept und eine Umfrage in Discussions → Polls | XL | v3.1.0 Mietverhältnisse (F1008) | Planung 2026-10-28 |

### Mit Auslöser oder laufend

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| — | **MiSpeL rechnen:** Speicher und bidirektionale Ladepunkte mit Netz- und Solarstrom; Abgrenzungsoption je Viertelstunde aus den Messwerten der Kaskade, Pauschaloption bis 30 kWp mit einem Zähler; Grundlage sind die Viertelstundenwerte aus v3.3.0 | L | v3.9.0 Messkonzepte, F1021 | GitHub #40, 2026-10-26; Auslöser: EU-Genehmigung der Pauschaloption oder Ende der Übergangszeit am 30.09.2027 |
| — | Prüfung in einem laufenden Home Assistant (Benachrichtigungen, IMAP-Weg für Post per E-Mail), danach HA-App ohne `stage: experimental` und mit Anzeigename (`X-Remote-User-Display-Name`) | S | v3.9.0 Home Assistant | Patch-Pool v3.5.0, Bekannte Grenzen v3.9.0 |
| — | Anleitungen live prüfen: LM Studio, Unraid, openHAB (Ollama ist seit v3.5.1 live geprüft) | S | — | Patch-Pool v3.1.0 |
| — | Strompreispauschale 2028 nach der Destatis-Veröffentlichung Ende September 2027 eintragen (jährlicher Punkt „Jahreswerte“ in der Release-Prüfliste) | S | — | Patch-Pool v3.5.0 |
| — | Verzeichnisse und App-Stores (awesome-selfhosted, Unraid Community Apps, Umbrel, CasaOS, YunoHost), Vergleichsseite, Beiträge in Foren; jeweils Entwurf zur Freigabe | S je Eintrag | — | Planung 2026-10-28 |

### Seit dem letzten Eingang ausgeliefert (Abgleich 2026-10-28)

Diese Zeilen standen noch im Eingang, sind aber erschienen; die Belege stehen im CHANGELOG:
Wasser im Jahresbericht (v3.7.0), Flexibilität mit eigenen Werten (v3.7.0), § 14a Modul 3 ohne USt
(v3.7.0), englische Screenshots mit englischem Datum (v3.7.0), Zuständig an Zählern und Verträgen
(v3.7.0), Barrierefreiheit der Dialoge mit Prüfung (v3.7.0), Messung mit großen Datenmengen
(v3.7.0), Sichtprüfung in Safari/WebKit (v3.7.0), Posteingang mit Kopfzeile, Codegruppen und
Zwischenständen (v3.7.0, PDF-Wiederholung v3.9.0), Zwischenablesung prüfen (v3.9.0), Auslesen hinter
einem Webserver mit 504 (v3.9.0), Umgebungsname als Abzeichen (v3.9.0, auf DEV, ACC und PROD
gesetzt), Heizgrenze mit unabhängiger Sommer-Referenz (v3.9.0), Auswertungen nach dem Skeptiker-Lauf
(v3.9.0), Saldo-Karte und Werkstatt aus einer Quelle (v3.9.0), Heizgrenze je Zähler (v3.9.0), Personen
ohne Anmeldung (v3.9.0), Werbebilder neu (v3.9.0), Preisbestandteile im Arbeitspreis (v3.9.0),
Dependabot-PRs #22–#26 (v3.2.0).
