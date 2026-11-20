# Energietracker — Planung für Mitwirkende

Diese Seite ist die Arbeitsliste hinter der [Roadmap](roadmap.md): Regeln, Arbeitsweise und alle
offenen Punkte, sortiert nach dem Release, in das sie geplant sind. Konzepte und Entscheidungen
früherer Releases stehen unverändert im [Archiv](roadmap-archiv.md); was ausgeliefert ist, im
[CHANGELOG](CHANGELOG.md).

**Stand:** 2026-11-17 · **Baseline:** v3.13.0 · **Schema:** 1.8.0

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

### v3.12.0 — Energietracker für deine KI (ausgeliefert 2026-11-10)

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| F1037 | **Fernsteuerung durch eine KI (MCP):** Energietracker als MCP-Server (`/mcp`, Schlüssel mit Rechten lesen/schreiben). Werkzeuge: Übersicht, Fristen, Verträge, Verbrauch, Prognose-Szenario, „Rechnung prüfen“, Ablesung eintragen, Dokument einreichen und übernehmen; schreibende Werkzeuge nur mit Bestätigung im KI-Client. Ziel: Energietracker als zentrale Akte und Energieverwaltung, bedient von einer KI („hier ist meine Gasrechnung, trag sie ein und sag mir, ob sie stimmt“). Vorbereitung: maschinenlesbare Beschreibung der Schnittstelle (OpenAPI) | L | v3.7.0 Posteingang | Entscheidung 2026-10-21: nur Konzept, eigenes Release |
| **N1011** | API-Versionierung (`/api/v1/…`) | M | Ursprünglich als Smart-Meter-Vorbereitung geplant; mit dessen Streichung kein fester Slot mehr. Wird nur umgesetzt, wenn ein echter Bruch der API-Kompatibilität ansteht. |
| F1038 | HACS-Integration mit eigenen Sensoren | M–L | N1025 | Release-Schnitt 2026-10-13 (nicht gewählt) |
| — | **Briefe leichter finden** (2026-11-04): Rechte-Hinweise vorn mit Filter, Sprungmarke je Hinweis, „Brief schreiben“ an der Vertragskarte auch ohne Hinweis | S–M | v3.11.0 Briefe | Rückmeldung des Maintainers 2026-11-04 |
| — | **Demo beginnt nach jedem Release bei null** (2026-11-04): gemerkte Wahlen versteckten neue Funktionen vor wiederkehrenden Besuchern | S | v3.0.0 Demo | Rückmeldung des Maintainers 2026-11-04 |
| — | **Übernahme nur in der App bestätigen** (2026-11-04): Schalter ab Werk an, Änderungen einer KI bestätigt die Person in der App; Erklärung und Beispiele in der Anleitung | S–M | Vorschlag und Übernahme | Skeptiker-Lauf v3.12.0 |
| — | **Was bringt dir die KI-Schnittstelle, und wie lange dauert es?** (2026-11-04): Beispiele in Anleitung und Schaufenster, Messreihe mit lokalen Modellen in der Anleitung; geprüft mit Open WebUI und Ollama, Cloud-KI und weitere Programme experimentell | S | — | Wunsch des Maintainers 2026-11-04 |
| — | **Neue Oberfläche und Designsprache** (#59, 2026-11-05): Menü in zwei Bereichen, Zu tun, Verbindungen, Einstellungen nach Themen mit Suche, eigene Grafiken, festes Raster. Keine Funktion fällt weg, alte Adressen führen weiter. Konzept freigegeben, Bau in sieben Etappen | XL | nav-model | Wunsch des Maintainers |
| — | **Saldo von Zählergruppen** (#58, 2026-11-05): falsch, wenn die Zähler nicht am selben Tag abgelesen sind. Kein Patch, Korrektur kommt mit diesem Release | M | | Prüfung vor dem Release |

Später (2026-11-04): weitere lokale Modelle auf Laufzeit und Treffsicherheit messen und eine Empfehlung je Rechnergröße geben (Größe S, nach v3.12.0).

Ergänzung 2026-10-28: Bild „Die KI ist die Oberfläche, der Energietracker Akte, Auswertung und
Berichtswerkzeug dahinter“; jede Zahl mit Herkunft (gemessen, verteilt, geschätzt), damit eine KI nichts
erfindet; Berichtsvorlagen (Monats- und Jahresbericht) als MCP-Prompts. Die HACS-Integration wird mit
diesem Release veröffentlicht (eigenes Repo, Aufnahme in die HACS-Standardliste beantragen).

### v3.13.0 — Speicher, Wallbox und HT/NT in der Prognose (erschienen am 17.11.2026)

Entschieden am 2026-11-10. Dazu kommen die Punkte, die v3.12.0 unter „Bekannte Grenzen“ auf v3.13 verweist.
**Stand 2026-11-13:** Was die Prognose nach dem Wetter betrifft, wird am Prüfhaushalt
(`tests/fixtures/heatpump-household/`, Rückrechnung `tools/rueckrechnung.php`) gegen feste Schwellen gemessen
(`schwellen.json`). Ein Baustein, der seine Schwelle nicht schafft, bleibt mit Hinweis beim alten Weg.

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| F10xx | **Prognose für Wärmepumpe, PV, Batterie und E-Auto.** **An:** Saldo der Einspeisung nach der Einstrahlung, Einspeisung nach einem neuen Speicher, Wallbox-Monate ohne Vorgeschichte, Arbeitszahl beim Heizungstausch nach dem Wetter. **Gebaut, aber aus (Hinweis):** Wärmepumpe nach dem Wetter (Prognose, Band, Saldo) und PV-Prognose (Erzeugung und Einspeisung), beide weiter in v3.14; Bezug nach einem neuen Speicher und Zähler vor einer neuen Wallbox, Ziel-Release offen; damit fehlen in der Werkstatt weiter die Beispiele für Winter und Raumtemperatur beim Strom der Wärmepumpe und die PV nach dem Wetter | L | F1013 Heizmodell, F1021 Viertelstunden, F1040 Werkstatt je Energieart | Bekannte Grenze v3.12.0, Entscheidung 2026-11-10 |
| — | **Auszahlungen der Einspeisung erfassen:** Die Saldo-Karte rechnet die Einspeisung seit v3.12.0 je Abrechnungsjahr; dazu kommen die Auszahlungen des Netzbetreibers | M | v3.12.0 Einspeisung je Abrechnungsjahr | Entscheidung 2026-11-10 |
| — | **Abschläge zählen** (Vertragswechsel im Monat): eine Einstellung für alle Verträge, tagesanteilig (Standard, wie bisher) oder ganze Rate am Fälligkeitstag; eine Rate am Tag einer Ablesung zählt im Zeitraum danach, wie die Jahresrechnung. **Stand 2026-11-13:** gebaut | M | — | Bekannte Grenze v3.12.0 |
| — | **Einstellung „Angaben für Briefe nur Verwalter“:** Mit Anmeldung ändert auf Wunsch nur ein Verwalter Absender und Lieferanschrift | S | v3.12.0 Briefe | Entscheidung 2026-11-10 |
| — | **Längengrenzen für Freitext:** Notizen und Namen mit Höchstlänge, dieselbe Grenze im Backup-Import | M | v3.12.0 Eingaben | Bekannte Grenze v3.12.0 |
| — | **Begriffe in den Übersetzungen:** die Heizgrenze in fr, it, es, pt und nl überall gleich, „HGT-Basistemperatur“ unter Rechnen, „Snapshot“ in it und nl, „Annual report“ im Englischen | S–M | — | Bekannte Grenze v3.12.0 |
| — | **Ältere Gegenproben nachziehen:** rund 70 Muster passen nach dem Umbau nicht mehr zum Code und laufen bis dahin nicht | M | — | Entscheidung 2026-11-10 |
| — | **Prüfung der öffentlichen Demo im Januar:** `demo.test` wird ab Mitte Januar rot, weil das Schaufenster zu Beginn seines Abrechnungsjahrs noch keinen gemessenen Teil hat; die Prüfung aus dem Stand der Demo ableiten oder an einem festen Tag laufen lassen | S | — | Bekannte Grenze v3.12.0 |
| — | **Mitglieder sehen nur, was sie ändern dürfen:** Token-Knöpfe, evcc- und Heimnetz-Felder unter Verbindungen ausblenden | S | v3.12.0 Verbindungen | Bekannte Grenze v3.12.0 |
| — | **Haushalt › Zähler „Alle“ mit einer Sammelabfrage** statt bis zu 18 einzelner Anfragen | S–M | v3.12.0 Haushalt | Entscheidung 2026-11-10 |
| — | **Kosten & Verträge:** Zahl am Reiter „Frühere Verträge“, Knopf „Neuer Vertrag“ im Kopf der Seite | S | v3.12.0 Kosten & Verträge | Entscheidung 2026-11-10 |
| — | **Rücknahme von KI-Einträgen** im Verlauf (heute lehnt „Rückgängig“ sie mit `409` ab). **Stand 2026-11-18:** so nicht baubar, offen | M | F1037 | Entscheidung 2026-11-10 |
| — | **Zeitreise in der CI:** die Runde unter verstellter Uhr als eigenen Job prüfen (heute lokal vor jedem Release) | S | v3.12.0 Stichtag | Entscheidung 2026-11-10 |
| — | **Stichtag für Zähler mit Verbrauch je Zeitraum** | M | v3.12.0 Stichtag | Bekannte Grenze v3.12.0 |
| — | **Werkstatt nach dem Wetterabgleich neu rechnen,** damit am selben Tag dieselbe Zahl steht | S | F1040 | Bekannte Grenze v3.12.0 |
| — | **Integration 1.1.0** (Marketplace, ehemals HACS): Sensor für den Füllstand von Öl und Pellets | S | F1038 | Entscheidung 2026-11-10 |
| — | **Rückfrage beim Eintragen eines Zählerstands nach dem Wetter** statt nach dem Median der letzten Abstände (dieselbe Erwartung wie „Zwischenablesung prüfen“), damit der Heizbeginn keine falsche Rückfrage auslöst | S–M | v3.9.0 Zwischenablesung prüfen | GitHub #65 |

### v3.14.0 — Lange Verläufe und Umzug (im Bau seit 2026-11-20)

Planung abgeschlossen am 2026-11-20, kein fester Termin. Jede Zeile kommt mit Test und Gegenprobe. Was am
Prüfhaushalt seine vorab festgelegte Schwelle nicht schafft, bleibt beim alten Weg und steht als Bekannte Grenze im
CHANGELOG.

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| N1006 | **Speicher-Umbau:** das Ergebnis je Zähler zwischen Anfragen merken; alt gegen neu byte-gleich an allen Endpunkten der Messung mit großen Daten | L | v3.7.0 Messung | Entscheidung 2026-10-21 |
| — | **Lange Verläufe:** Die Diagnose meldet ein zu knappes `memory_limit`, der Jahresbericht läuft auch bei sehr langen Verläufen ohne Fehler 500, die Werkstatt rechnet Heizöl über das Tankmodell | S–M | v3.7.0 Messung | Messung 2026-11-18 |
| — | **Umzug aus anderen Apps:** ein ganzer Haushalt mit freier Spaltenzuordnung, Trockenlauf und Sicherung davor, dazu der Verlauf aus Home Assistant. Die Stände laufen durch dieselbe Plausibilitätsprüfung wie beim Eintippen | L | Massen-Import | Planung 2026-10-28, Entscheidung 2026-11-18 |
| — | **Verbrauch zwischen zwei Ablesungen in den Monatswerten nach dem Wetter verteilen:** Gas und Wärme ähnlich dem Gradtagzahlverfahren der Versorger (Grundlast gleichmäßig, Heizanteil nach Heizgradtagen), PV nach der Einstrahlung, Strom tagesanteilig. Abrechnungsjahr, Saldo und Kosten bleiben tagesanteilig | L | F1013 Heizmodell, v3.13 Einstrahlung | Antwort auf #39, Entscheidung 2026-11-18 |
| — | **Beispielhaushalte mit eigener Physik** statt aus dem Modell der App (Heizgrenze unter 15 °C, Solargewinne, lückenhafte Ablesungen), damit Demo und Render-Tests auch Unsicherheit zeigen | M | v3.7.0 unordentlicher Testhaushalt | Entscheidung 2026-10-22 |
| — | **Einspeise-Saldo nach einem neuen Speicher** auf derselben Grundlage wie die Prognose der Einspeisung | S | v3.13 Speicher | Bekannte Grenze v3.13.0 |
| — | **Hinweis bei stehendem Zählerstand aus Home Assistant** (#69): ab 3 Tagen ohne Änderung bei Ständen über die Schnittstelle (`source: ingest`), einstellbar, abhakbar, unter „Zu tun“ und damit auch im Sensor und in den Benachrichtigungen; dazu ein Knopf „stehende Stände entfernen“. Stände von Hand bleiben außen vor | S–M | v3.9.0 Home Assistant, Agenda | Idee GitHub #66, Antwort in #69 |
| — | **Wetterabruf robust:** Rückzug nach HTTP 429 mit „nächster Versuch um …“, das Archiv in Jahresstücken mit Fortschritt. Dazu der Schalter `ET_WEATHER=off` für Betrieb ohne Anfrage nach außen und ein Klimanormal in den Beispieldaten | M | v2.8.0 Wetterabgleich | HTTP 429 im Betrieb, 2026-11-18 |
| — | **Home Assistant:** fehlende Schreibrechte im Ordner für den Posteingang melden | S–M | v3.7.0 Posteingang | Entscheidung 2026-11-18 |
| — | **Abschläge:** Termine nach einem Einzug im Takt; nach dem letzten Termin fortgeschrieben, als „angenommen“ markiert, mit dem Hinweis, den neuen Plan aus der Rechnung einzutragen; „Rechnung prüfen“ verrechnet Abschläge ab Rechnungsbeginn | M | Paket W (v3.13) | Entscheidung 2026-11-18 |
| — | **Rechnen an Grenzen:** Saldo am Vertragsende, Monatszeile einer Gruppe mit fehlendem Mitglied („unvollständig“), Subzähler mit eigenem Vertrag, Herkunft eines Abrechnungsjahrs nach Tagen (KI-Schnittstelle), „Preis fehlt für n Tage“ | M | v3.13 Herkunft (`Provenance`) | Bekannte Grenzen v3.13.0 |
| — | **Korrekturen nach einer Prüfung von v3.13.0:** Saldo von Zählern mit Verbrauch je Zeitraum, Eingaben und Backup-Import, Fehlerantworten, Zahlabzeichen besser lesbar, Kündigung in der Grundversorgung immer zwei Wochen, Doku | M | — | Prüfung 2026-11-20 |
| — | **Integration 1.2.0:** Kategorien im Manifest, Gerätedaten, ein Knopf zum Marketplace statt zwei | S–M | F1038 | Prüfung gegen Home Assistant 2026.11 |
| — | **Werkzeuge:** Tests und Farm ohne Open-Meteo, schnelleres Tor (Suiten parallel), fehlende Tests aus dem Tor 3.13, `cosign verify` lokal | M | PR #70 | Messung des Zugs 3.13 |

### v3.15.0 — Für alle verständlich

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| N10xx | Übersetzter Doku-Kern (Erste Schritte, Einrichtung, Anwendungsfälle) für fr/it/es/pt/nl, jede Übersetzung mit Quell-Hash, veraltet → Englisch | M–L | N1019 | Release-Schnitt 2026-10-12 („Kern später“; 2026-10-13 erneut zurückgestellt) |
| N1027 | **Länder-Regelwerke als Module:** je Land Daten mit Gültigkeitsdatum und Quelle, eigene Regeln, Tests und Doku hinter einer gemeinsamen Schnittstelle; Deutschland zieht als Erstes um, byte-gleich belegt. Konzept vor F1042 | L | — | Ideenrunde 2026-11-20 |
| F1042 | **Länderprofile Österreich und Schweiz** (2026-10-28): Preise, Steuern und Fristen je Land, Rechte-Prüfungen je Land oder ausdrücklich „nur Deutschland“ | M–L | N1027, N1014 Länderprofile, v3.11.0 | Planung 2026-10-28 |
| F1045 | **Mengenaufteilung bei Preisänderungen** wie beim Versorger (Gas: modifizierte Gradtagzahlen für die Heizung, linear für Kochen und Warmwasser; Strom tagesanteilig) in Abrechnungsjahr, Saldo und „Rechnung prüfen“, vor den ersten Gasrechnungen über den 1.1.2027 | M–L | Verteilung nach dem Wetter (v3.14) | CO₂-Preis 2027, Entscheidung 2026-11-18 |
| — | **Gutschrift des Netzbetreibers im Posteingang** als Auszahlung der Einspeisung erkennen | S–M | v3.13 Auszahlungen | Entscheidung 2026-11-18 |

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
| — | **Bezug nach einem neuen Speicher, Zähler vor einer neuen Wallbox:** in v3.13 gebaut, Schalter aus (`DeviceArrival`), bis dahin Hinweis mit Zäsur-Vorschlag; offene Wege: die Ladung in der Kaskade zwischen Wärmepumpe und Netz teilen, das Laden der Wallbox aus der Sonne | M | v3.13 Speicher und Wallbox | Prüfung in v3.13 nicht bestanden (2026-11-12); Ziel-Release offen |
| — | **Prognose der Wärmepumpe und der PV nach dem Wetter, Heizmodell für gekrümmte Heizkurven (#38):** in v3.13 gebaut, Schalter aus (`HeatPumpWeather`, `PvWeatherModel`). Vorstudie 2026-11-18 am Prüfhaushalt gegen `schwellen.json`, auch mit einer zweiten Gradtagzahl ab 6 °C und der Grundlast aus den Sommermonaten: kein Baustein an seiner Schwelle. Ein neuer Anlauf braucht einen neuen Ansatz oder mehr echte Daten | L | v3.13 Prüfhaushalt, F1013 Heizmodell | Vorstudie 2026-11-18; Ziel-Release offen |
| N1030 | **Sicherheit laufend:** Image-Scan auswerten, `cosign verify` als Prüfschritt, Abhängigkeiten der Images | S je Runde | — | Ideenrunde 2026-11-18 |
| N1031 | **Abhängigkeiten gegenprüfen:** Home Assistant erscheint jeden Monat. Je Release die Hinweise für Integrationen und Apps lesen, die Farm gegen die aktuelle Version, die nächste Beta und die Mindestversion (heute 2026.3) laufen lassen, Mindestversion und Doku nachziehen; dazu PHP, Basis-Images, evcc, Open-Meteo und Marketplace. Ab v3.14 (HA 2026.11 und Beta 2026.12) | S je Monat | Farm, HA-App, Integration | Maintainer 2026-11-18 |

### Ohne Release (Ideenrunde 2026-11-20)

| Code (vorläufig) | Thema | Größe | Abhängigkeit | Auslöser |
|---|---|---|---|---|
| F1041 | **Preisbestandteile nach Rechtsstand:** Stromsteuer, Energiesteuer Gas, CO₂ und Umsatzsteuer je Datum im Länderprofil; Prognose und Saldo passen sich bekannten Änderungen an und prüfen Rechnungsposten | L | N1027 | Stromsteuer 2027 |
| F1043 | **Weitere Länder in Europa** nach Nachfrage, zuerst die Sprachen der App (FR, IT, ES, PT, NL, BE, LU), je Land ein Modul | M–L je Land | F1042 | Ideenrunde 2026-11-18 |
| F1044 | **Nordamerika (USA, Kanada):** Einheiten (therms, Gallonen, °F), monatliche Abrechnung, Net Metering; erst ein Konzept | XL | N1027, Einheiten in der Utilities-SSOT | Ideenrunde 2026-11-18 |
| N1028 | `ConsumptionService.php` in kleinere Dienste aufteilen, byte-gleich belegt (Vorschlag: nach v3.14, vor großen Umbauten der Prognose) | L | — | Ideenrunde 2026-11-18 |
| N1029 | `utility.js` vereinfachen (`rerender`, `balanceCard`), nur zusammen mit einem Umbau der Ansicht | M–L | — | Ideenrunde 2026-11-18 |
| F1046 | **Wechselservice:** (1) ein Angebot gegen den eigenen Verbrauch prüfen (der Posteingang liest das Angebot; Bonus, Preisgarantie, Laufzeit, Grundversorgung), (2) ein vorbefüllter Link zu Vergleichsportalen ohne Datenfluss vor dem Klick, (3) Tarifdaten über eine Schnittstelle nur kostenlos und ohne persönliche Daten, vorher Recherche | M–L | Tarifvergleich v2.3, Posteingang, F1045 | Ideenrunde 2026-11-20 |
| F1047 | **Kalender und To-do in Home Assistant:** Fristen, Abschlagstermine und Stichtage als `calendar`, „Zu tun“ als `todo`; Abhaken wirkt im Energietracker | M | Integration, Schreibschlüssel | Ideenrunde 2026-11-20 |
| F1048 | **Preis-Sensoren für das Energie-Dashboard:** Arbeitspreis je Zähler und Grundpreis je Tag aus den Verträgen | S–M | Integration | Ideenrunde 2026-11-20 |
| F1049 | **Komfort in Home Assistant:** Blueprints (Ablesung übertragen, stehender Stand, Monatsbericht) und Assist über den MCP-Client von HA mit lokalem Modell (Anleitung, Farm) | S–M | F1037, Farm | Ideenrunde 2026-11-20 |
| N1032 | **Diagnose und Reparaturen in Home Assistant:** „Diagnose herunterladen“ ohne Schlüssel, Reparaturmeldungen bei ungültigem Schlüssel oder zu alter Version | S | Integration | Ideenrunde 2026-11-20 |
| F1050 | **Lohnt sich ein dynamischer Tarif?** Die eigenen Viertelstunden gegen freie Börsenpreise (Quelle, Lizenz und Kosten vorher prüfen) | M–L | F1021 Viertelstunden | Ideenrunde 2026-11-20 |
| F1051 | **§ 14a EnWG Modul 1 oder 2** für Wärmepumpe und Wallbox, mit den eigenen Daten gerechnet | M | v3.9 Messkonzepte, N1027 | Ideenrunde 2026-11-20 |
| — | **Verweise ins Leere und doppelte Kennungen:** Felder, die auf Gelöschtes zeigen, verwaiste Rechnungen nach dem Löschen eines Vertrags, doppelte Kennungen in der Datenprüfung; heikel, weil der Import sonst eigene Exporte abweisen könnte | M | — | Vorklärung 2026-11-18 |

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
