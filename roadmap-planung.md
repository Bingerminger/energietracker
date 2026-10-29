# Energietracker — Planung für Mitwirkende

Diese Seite ist die Arbeitsliste hinter der [Roadmap](roadmap.md): Regeln, Arbeitsweise und alle
offenen Punkte, sortiert nach dem Release, in das sie geplant sind. Konzepte und Entscheidungen
früherer Releases stehen unverändert im [Archiv](roadmap-archiv.md); was ausgeliefert ist, im
[CHANGELOG](CHANGELOG.md).

**Stand:** 2026-10-28 · **Baseline:** v3.9.0 · **Schema:** 1.8.0

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

### v3.10.0 — Versprochen und abgesichert

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| — | **Neuere OpenAI-Modelle lehnen Parameter ab** (Bug, GitHub #41): `OpenAiCompatibleProvider` schickt jedem Modell fest `temperature: 0`, der zweite Versuch nach `length` `max_tokens`. Neuere Modelle nehmen nur die Standard-Temperatur und `max_completion_tokens` → 400, das Auslesen scheitert ganz. Kein Fehler des Dienstes, sondern unsere Anfrage. Vorschlag: bei 400 mit `error.param` `temperature` bzw. `max_tokens` (Feld der Antwort, nicht der Meldungstext) einmal ohne `temperature` bzw. mit `max_completion_tokens` wiederholen; keine Liste von Modellnamen | S | v3.7.0 Cloud-Auslesen | GitHub #41, 2026-10-27; **zugesagt für v3.10.0** (Antwort 2026-10-28) |
| — | **Absage eines Fremddienstes erscheint als „Interner Fehler“** (Bug, aus #41): `ErrorHandler::respond()` ersetzt seit v2.5.3 jede 5xx-Meldung außer 503 durch die allgemeine Meldung mit Fehler-ID, auch eine `HttpStatusException` mit Katalogschlüssel (Cloud 502, Geokodierung 502). Der Nutzer sieht neben der richtigen Meldung „Interner Fehler … Server-Log“, das Log führt sie als `error`. Vorschlag: Ausnahmen mit Katalogschlüssel nicht verdecken (Text ist unser eigener, ohne Pfade), Fehler-ID nur bei unerwarteten Ausnahmen | S | — | GitHub #41, 2026-10-27; **zugesagt für v3.10.0** (Antwort 2026-10-28) |
| — | **Abrechnungsjahr in der Übersicht ohne Vorjahresvergleich und mit falscher Beschriftung** (GitHub #36, Kommentar 2026-10-27): Die Kacheln zeigen im Abrechnungsjahr keine Trendpfeile (`dashboard.js` setzt `trendCons`/`trendCost` dort auf `null`), und die Beschriftung bleibt „Verbrauch/Kosten (letzte 12 Monate)“, obwohl der Wert seit Beginn des Abrechnungsjahrs zählt. Vorschlag: Beschriftung „bisher im Abrechnungsjahr“; Vergleich mit denselben Tagen des vorigen Abrechnungsjahrs (gleich lange Spanne, nicht mit dem ganzen Vorjahr) | S–M | v3.6.0 Abrechnungsjahr | GitHub #36, 2026-10-27; **zugesagt für v3.10.0** (Antwort 2026-10-28) |
| — | **Umschalten Kalender-/Abrechnungsjahr springt nach oben** (GitHub #36, Kommentar 2026-10-27): `et:periodmode` (ebenso `et:levelchange`) zeichnet die Ansicht über `handle(false)` wie eine Navigation neu — Platzhalter „Lädt…“ und Sprung an den Anfang der Ansicht. So seit v3.6.0, keine Regression. Vorschlag: Neuzeichnen ohne Navigation, Scrollposition halten, alten Inhalt bis zum neuen stehen lassen | S | v3.6.0 Abrechnungsjahr | GitHub #36, 2026-10-27; **zugesagt für v3.10.0** (Antwort 2026-10-28) |
| — | **Abschlagsvorschlag kurz vor der Abrechnung erklären** (GitHub #38, 2026-10-28): Der gemeldete Fall war kein Fehler, der Abschlagsplan des Melders hatte 12 statt 10 Raten (selbst korrigiert, 13:25). Die Regel ist gewollt: Unter `SUGGEST_MIN_MONTHS` (3) verbleibenden Abschlägen kein Nachholen, sondern der laufende Abschlag (`suggestion_withheld: "short"`), für Nachzahlung und Guthaben gleich; das Vorzeichen bestimmt nur die Richtung. In der Doku (verstehen, Abschlag) klar beschreiben, einschließlich „genau drei“ und Guthaben; Test für den Guthaben-Fall | XS | v3.9.0 L12 | GitHub #38, 2026-10-28; **zugesagt für v3.10.0** |
| — | **Anfangsstand einer Rechnung = Ablesung am Vortag wird nicht als vorhanden erkannt** (Wasserrechnung des Maintainers, 2026-10-28): Der Posteingang erkennt einen Stand der Rechnung nur innerhalb des Rechnungszeitraums als „vorhanden“ (so beim Endstand, gemeldet einige Tage vorher). Der Anfangsstand zum Periodenbeginn ist oft die Jahresablesung vom Vortag mit demselben Zählerstand; er wird als neu angehakt und legt einen zweiten Stand mit 0 Verbrauch an. Vorschlag: gleicher Zählerstand am Vortag des Periodenbeginns (oder wenige Tage davor) = vorhanden | S | v3.9.0 Vertrag aus der Rechnung | Prod-Übernahme Wasser 2026-10-28 |
| — | **Verbindungen ins Heimnetz mit eigenem Zertifikat** (GitHub #36, Rückmeldung 2026-10-28): Home Assistant per HTTPS mit eigener PKI scheitert, weil der Container die eigene CA nicht kennt (`NotifyTransport` nutzt die Systemzertifikate). (1) Eigenes Stammzertifikat: Datei im Container (`ET_CA_FILE`, als `CURLOPT_CAINFO` zusätzlich zu den Systemzertifikaten) und Feld in den Einstellungen; Prüfung bleibt an. (2) Schalter „Zertifikat nicht prüfen“ nur für Adressen im Heimnetz, standardmäßig aus, mit deutlicher Warnung (Token). Gilt für alle Verbindungen ins Heimnetz (Home Assistant, lokales Sprachmodell/OCR, evcc); Doku inkl. Übergangsweg (eigenes Bündel über `/etc/ssl/certs/ca-certificates.crt`, mit dem Image 3.9.0 geprüft 2026-10-28: ohne Bündel „unable to get local issuer certificate“, mit Bündel HTTP 200 auch als `www-data` und als `:ro`-Bind-Mount; das Bündel ersetzt die Liste des Images, deshalb liest (1) die eigene CA zusätzlich). Der Melder bevorzugt (1) | S | v3.9.0 Home Assistant | GitHub #36, 2026-10-28; **zugesagt für v3.10.0** |
| — | **Sensor „Zu tun“ zeigt auch das Kommende** (GitHub #36, 2026-10-28): Der Sensor zählt wie die Liste „Zu tun“ nur `due_now` (Termin ab dem Fälligkeitstag, `ReminderService` Status `due`/`overdue`); ein Termin in zwei Tagen ist `due_soon` und fehlt, obwohl er im Kalender steht. `/api/todo` bekommt zusätzlich `soon` (Zahl der bald fälligen Ereignisse im Vorlauf `reminder_warn_days_before`, Standard 14 Tage) und `next_upcoming` (nächstes Ereignis mit Datum, auch noch nicht fällig); Zustand bleibt `count` (Automationen auf „> 0“), YAML-Vorlage und Doku mit den neuen Attributen | XS | v3.9.0 Sensor „Zu tun“ | GitHub #36, 2026-10-28; **zugesagt für v3.10.0** |
| — | **Unbefristeter Folgevertrag ab dem nächsten Stichtag** (Paket auswertung, nebenbei gefunden, älter als v3.9.0): `contractStatus` setzt als Ende den Stichtag, `settle_on` liegt vor dem eigenen Beginn, der Saldo ist rund ein Tag Kosten; die Vertragsliste zeigt ihn als „≈ … erwartet“ | S | v3.9.0 laufende Periode | Skeptiker-Fixes 2026-10-27 |
| — | **Anomalie am Elternzähler ohne seine Subzähler** (Paket heizgrenze, 2026-10-27): Die Empfehlung „statistische Anomalie“ rechnet den Elternzähler mit seinen Subzählern; ein Ausschlag eines Subzählers (etwa der Wallbox) erscheint dadurch auch am Elternzähler. Elternzähler mit dem eigenen Verbrauch (Rest) prüfen | S | v3.9.0 Rest-Abrechnung | Paket heizgrenze 2026-10-27 |
| — | **Rechnungskorrektur im laufenden Posteingang** (Rest aus der Eingangszeile „Preisbestandteile/Rechnungskorrekturen“): Im Entwurf ersetzt eine Korrektur die Erstfassung schon (v3.7.0), im laufenden Posteingang wird sie nur am Titel erkannt und ersetzt eine schon gebuchte Rechnung nicht | S–M | v3.7.0 Posteingang | Abgleich der Roadmap 2026-10-28 |
| — | **Breite der Saldo-Schätzung überall** (Skeptiker v3.9.0 M6-Rest, R2-3): das Band „≈ Wert, je nach Wetter und Schätzung“ steht nur an der Saldo-Karte und nur für Heizarten; Einsteiger-Übersicht, „Wohin geht mein Geld?“ und Jahresbericht zeigen bei sicherer Schätzung einen Punktwert, Strom und Wasser haben kein Band | S–M | v3.9.0 eine Projektion | Skeptiker-Lauf 2026-10-27 |
| — | **Monatskosten Karte gegen Werkstatt bei schlechter Passung** (Skeptiker v3.9.0 M4-Rest): Die Tabelle der Werkstatt zeigt die Monatsmengen der Prognose, die Karte rechnet je Tag mit Kalibrierfaktor; bei schlecht passendem Heizmodell liegen die Monatskosten rund ein Zehntel auseinander | S | v3.9.0 eine Projektion | Skeptiker-Lauf 2026-10-27 |
| — | **Saldo-Schätzung kurz vor dem Periodenende** (Wirklichkeitsabgleich 2026-10-27): Im Rückblick liegt die Schätzung einen Tag vor dem Ende einer Abrechnungsperiode noch deutlich neben der späteren Rechnung, obwohl fast alle Tage gemessen sind; bestand schon in v3.7.0 (kleiner geworden). Ursache klären (Vertragswechsel im Zeitraum, Preisstand, Verteilung über Preisabschnitte) | S–M | v3.9.0 eine Projektion | Wirklichkeitsabgleich 2026-10-27 |
| — | **Kachel „Abschläge bis …“ gegen Saldo-Karte** (GitHub #49 aus Discussion #42): Die Kachel rechnet das Kalenderjahr bis zur letzten Ablesung, die Saldo-Karte ab Vertragsbeginn bis heute mit Schätzung; mit Vertragsbeginn im Vorjahr zeigen sie verschiedene Vorzeichen, ohne dass man den Grund sieht. Vorschlag: denselben Zeitraum rechnen oder ausdrücklich sagen, wofür die Kachel steht. Entscheidung 2026-10-29 (Rückfrage in #42): Saldo-Karte trennt Gemessenes von Geschätztem; „Aktueller Saldo“ bis zur letzten Ablesung (gemessen), Schätzung bis heute (`measuredThenEstimated`) nur als Zusatzzeile, Prognose bleibt bei „Erwartet bei Abrechnung“; Kachel und Karte enden am selben Tag, Beginn jeweils beschriftet | S | v3.9.0 eine Projektion | Discussion #42, 2026-10-27/29; **zugesagt für v3.10.0** |
| — | **Kaskade je Zählwerk und Einspeisung in der Kaskade** (GitHub #40, Antworten 2026-10-28): Rest am vorderen Zähler je Zählwerk (HT−HT, NT−NT) statt in Summe; Einspeise-Zählwerk 2.8.0 an jedem Zähler der Kaskade, Eigenverbrauch des Sonnenstroms je Strang aus den Differenzen; Assistent „Messkonzept“ und Doku | M | v3.9.0 Rest-Abrechnung (F1032) | GitHub #40, 2026-10-28 |
| — | **Code-Scanning und Image** (2026-10-28): drei Befunde `js/incomplete-sanitization` in Testhilfen beheben; Image ohne `supervisor` (fällt mit python3/setuptools weg); Grype nur Befunde mit verfügbarem Fix, Scan direkt nach jedem Release; SBOM, Signatur (cosign) und Herkunftsnachweis am Image; SECURITY.md mit Fristen (critical/high mit Fix → Image-Neubau binnen 7 Tagen) | M | — | Code-Scanning 2026-10-28 |
| — | **`scripts/init_data.py` entfernen** (Prüfung der Arbeitsunterlagen, 2026-10-29): Der CHANGELOG kündigte das Entfallen für v3.0.0 an, das Skript liegt aber noch im Repo. Entfernen samt Erwähnungen in `docs/betrieb/installation.md` (Tabelle Voraussetzungen, §6) und `docs/entwicklung/architektur.md`, jeweils DE und EN | XS | — | 2026-10-29 |
| — | **Community auf GitHub** (2026-10-28): kurze Roadmap für Nutzer, Planung und Archiv getrennt; öffentliches Projektboard, Milestone je Release, Status-Labels (zu prüfen, angenommen, geplant, abgelehnt); Wünsche über Discussions → Ideas; Formular für Berichte aus automatisierten Tests; `AGENTS.md`; Release-Zug pflegt Milestone und Board | S–M | — | Planung 2026-10-28 |
| — | **Danke sagen** (2026-10-28): dezenter Unterstützen-Hinweis in Nutzen-Momenten („Rechnung prüfen“ findet eine Abweichung, eine Frist wurde rechtzeitig gemeldet, Jahresbericht fertig), höchstens einmal im Quartal, abschaltbar, nie in der Demo; Unterstützer auf Wunsch mit Namen in CREDITS und Release-Notes | S | — | Planung 2026-10-28 |

### Patch Mitte Dezember 2026 — Jahreswert

- CO₂-Preis 2027 nach der Veröffentlichung des Umweltbundesamts (spätestens zehn Werktage vor dem
  1.1.2027) in `CO2_PRICE_DE_BEHG` eintragen; BEHG-Entwurf (BT-Drs. 21/7869) nach der Lesung in der
  Doku nachziehen.

### v3.11.0 — Deine Rechte als Kunde

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| F10xx | **Rechte-Prüfungen** (2026-10-28): Rechnung zu spät (Frist nach Ende des Abrechnungszeitraums), Abschlag deutlich zu hoch (mit Antrag auf Anpassung), Preiserhöhung mit Sonderkündigungsrecht und Frist, Bonus nicht gutgeschrieben, Schätzung trotz gemeldetem Stand, Entgelt für den Messstellenbetrieb über der gesetzlichen Preisobergrenze, Widerspruchsfrist beim Gebührenbescheid (Wasser/Abwasser); jede Prüfung mit Quelle; Prüfbericht der Rechtsangaben vor dem Release | L | „Rechnung prüfen“, Verträge, Posteingang | Planung 2026-10-28 |
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
| — | **Entwurf auf einen bestehenden Haushalt (3.7.1, Vorrang, ACC-Probe 2026-10-22):** Mit vorhandenem Haushalt (Zähler ohne Nummer/MaLo, Verträge ohne Nummer) legt der Entwurf einen zweiten Zähler samt Verträgen, Preisen, Abschlägen, Rechnungen und Ablesungen an. Fix: (1) gibt es genau einen aktiven Zähler der Art mit Daten, wird er übernommen und bekommt Nummer und MaLo nachgetragen; (2) Bestandsverträge ohne Nummer erkennen (gleicher Zähler, gleicher Versorger über `supplierMatch`, Beginn gleich oder wenige Tage daneben) → „vorhanden“ bzw. „ergänzen“; (3) Ergänzungen und Überschneidungen an Bestandsverträgen nie vorbelegen. Danach ACC-Probe wiederholen (Posteingang dort ist schon ausgelesen) und erst dann der einmalige Import auf Prod | M | v3.7.0 Entwurf | ACC-Probe 2026-10-22 |
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
