# Aussagen und Belege der Projektseite

Jede Tatsachenbehauptung auf den Seiten trägt eine Kennung (`data-c` im HTML, `c` in
`src/texte.mjs`). Diese Liste sagt je Kennung, was behauptet wird und wo es belegt ist.
`node tools/build.mjs --check` bricht ab, wenn die Seite eine Kennung benutzt, die hier
fehlt, oder hier eine Zeile steht, die keine Seite benutzt.

Pfade ohne Zusatz liegen im Haupt-Repo `Bingerminger/energietracker`, Stand
`e3ed91b0` (Stand v3.12.0 vom 2026-11-10: Bilder neu seit `0e09c74a`, PHP 8.3 als Mindestversion seit `e3ed91b0`; übrige Inhalte wie `40b8704d`, gegen den die Texte geprüft wurden). „HA“ ist `energietracker-ha`, „HACS“ ist
`energietracker-integration`. Wo ein Test genannt ist, sichert er die Aussage im Code.

## Einstieg

| Kennung | Aussage | Beleg |
|---|---|---|
| `tagline` | Zählerstände rein, Antworten raus. Selbst gehostet, ohne Konto und ohne Datenbank. | `README.de.md` Zeilen 5 bis 7, `README.md` Zeilen 5 bis 7 |
| `wie-abrechnung` | Der Energietracker rechnet Energie- und Wasserkosten so, wie die Abrechnung es tut. | `README.de.md` „Auf einen Blick“ (Saldo „wie auf der Rechnung“), `docs/einstieg/funktionen.md` „Verträge, Abschläge, Abrechnung“ (tagesgenau, Saldo wie die Abrechnung ihn nennt), CHANGELOG 2.8.0 „Rechnen wie die Abrechnung“ |
| `daten-bleiben` | Deine Daten bleiben bei dir: keine Konten, keine Werbung, keine Telemetrie, keine Datenbank, nur JSON-Dateien auf deinem Server. | `README.de.md` „Deine Daten“; Test `tests/unit/Services/OutboundHostsTest.php` (`testOnlyTheListedServicesOpenConnections`, `testTheReadmeNamesThem`) |
| `saldo` | Saldo je Vertrag: gemessen bis zur letzten Ablesung, geschätzt bis heute, erwartet bei der Abrechnung, mit Spanne. | `README.de.md` „Auf einen Blick“ (doc-claim, `ReadmeClaimsTest`), `docs/einstieg/funktionen.md` Abschnitt Saldo (seit v3.10.0) |
| `rechnung-pruefen` | „Rechnung prüfen“ rechnet die Versorgerrechnung Abschnitt für Abschnitt nach: Menge, Preis, Verbrauchs- und feste Kosten. | `docs/einstieg/funktionen.md` „Rechnungsprüfung“, `docs/anleitungen/jahresabrechnung.md` |
| `wechsel` | Wechsel mit Wechseltermin aus Vertragsende und Kündigungsfrist, Gewinnschwelle, Rangliste nach den Kosten ab dem zweiten Jahr. | `docs/einstieg/funktionen.md` „Wechseln“ (Wechselentscheidung), CHANGELOG 3.12.0 „Wechselentscheidung ab 2. Jahr“ |
| `demo-browser` | Die Demo läuft ganz im Browser, ohne Installation. | CHANGELOG 3.12.0 „Bekannte Grenzen“, Absatz HACS („sie läuft ohne Server“); CHANGELOG 3.0.0 (Demo auf GitHub Pages) |
| `bsp-erfunden` | Die Beispielhaushalte und alle Zahlen und Angaben darin sind erfunden. | `docs/anleitungen/anwendungsfaelle.md` „Beispielhaushalte zum Ansehen“ („mit erfundenen Daten“); CHANGELOG 3.12.0 „Schaufenster und Demo: erfundener Absender, Anschriften und Nummern“; Bilder aus `demo-data/` (`docs/entwicklung/screenshots.md`) |
| `uebersicht` | Die Übersicht antwortet zuerst: Was ist zu tun, bekomme ich Geld zurück, mehr oder weniger als im Vorjahr; darunter eine Kachel je Verbrauchsart mit Trend. | CHANGELOG 3.12.0 „Neue Oberfläche“ („Die Übersicht antwortet zuerst“), Bild `docs/assets/promo/screens/uebersicht.de.png` |
| `neu-312` | Neu in 3.12.0: Deine KI fragt im Energietracker nach, Home Assistant bekommt Sensoren über HACS, die Oberfläche ist neu. | CHANGELOG 3.12.0 „Kurzfassung“, erste drei Punkte |
| `handy` | Am Handy eine Leiste mit Übersicht, Zu tun, Erfassen, Verbrauch und Mehr; als App auf dem Home-Bildschirm. | CHANGELOG 3.12.0 „Menü in zwei Bereichen“ („Am iPhone eine Leiste mit Übersicht, Zu tun, ＋, Verbrauch und Mehr“), `docs/einstieg/handy.md` § 2 |
| `arten` | Neun Verbrauchsarten: Gas, Strom, Wasser, Fernwärme, Heizöl, Pellets, PV-Einspeisung, PV-Erzeugung, Heizwärme. | `README.de.md` „Neun Verbrauchsarten“, `src/Config/Utilities.php` (neun Einträge) |
| `sprachen` | Sieben Sprachen: Deutsch, Englisch, Französisch, Italienisch, Spanisch, Portugiesisch, Niederländisch. | `README.de.md` „In sieben Sprachen“, `public/locales/` |

## Haus mit Wärmepumpe, PV, Speicher und E-Auto

| Kennung | Aussage | Beleg |
|---|---|---|
| `messkonzept` | Jeder Vertrag zahlt nur seinen Teil; der Assistent „Messkonzept einrichten“ kennt zwei Zähler nebeneinander, Subzähler dahinter und Kaskade, auch mit Wärmepumpe und HT/NT; er fragt nach dem Aufbau. | CHANGELOG 3.9.0 (Titel „Jeder Vertrag zahlt nur seinen Teil. Messkonzepte …“), `README.de.md` („Messkonzepte mit Wärmepumpe und HT/NT“), `docs/anleitungen/anwendungsfaelle.md` Fall H, Bild `docs/ui/screenshots/messkonzept.png` (Text „deshalb fragt der Assistent nach dem Aufbau“) |
| `viertelstunden` | Viertelstunden aus Home Assistant alle 15 Minuten oder aus Dateien von Netzbetreiber und Wechselrichter. | `docs/anleitungen/home-assistant.md` „Viertelstunden: zusätzlich alle 15 Minuten (v3.3.0)“, `docs/einstieg/funktionen.md` „Zeitreihen aus Portalen“ |
| `flex` | Die Seite Flexibilität rechnet nach, ob Verschieben etwas gebracht hat. | `docs/anleitungen/flexibilitaet.md` Einleitung (seit v3.3.0, je Viertelstunde) |
| `pv` | PV: Einspeisung als Vergütung, Erzeugung, Eigenverbrauch, Autarkiequote, Speicher mit Verlusten, Amortisation. | `docs/einstieg/funktionen.md` „PV“ (seit v3.1.0 Speicher, Amortisation) |
| `jaz` | Jahresarbeitszahl der Wärmepumpe aus Wärmemengen- und Stromzähler, je Monat und für die Heizperiode. | `docs/einstieg/funktionen.md` „Wärmepumpe (v3.1.0)“ |
| `werkstatt-pv` | Die Werkstatt rechnet größere Anlage, Speicher, mehr Eigenverbrauch oder andere Vergütung als Modellwert aus dem gemessenen Jahr, mit genannter Grundlage. | CHANGELOG 3.12.0 „PV in der Werkstatt“ (F1040), `docs/anleitungen/was-waere-wenn.md` § 6b, Bild `docs/ui/screenshots/werkstatt-pv.png` |
| `evcc` | Ladevorgänge aus evcc. | `docs/einstieg/funktionen.md` („Ladevorgänge aus evcc“), `docs/anleitungen/evcc.md` |
| `grenze-wp-wetter` | Die Prognose für Wärmepumpe, PV und Speicher nach dem Wetter kommt mit v3.13 (ohne festen Termin seit 2026-11-12). | CHANGELOG 3.12.0 „Bekannte Grenzen“ und Schlusssatz („v3.13.0 ist für Dienstag, 17. November 2026 geplant“), `roadmap.md` „Als Nächstes“ |

## Haus mit Gas, Öl oder Pellets

| Kennung | Aussage | Beleg |
|---|---|---|
| `abschlag` | Saldo und erwartete Abrechnung zum Stichtag, dazu ein Vorschlag für den Abschlag. | `docs/einstieg/funktionen.md` „Saldo“ („erwartete Abrechnung am Stichtag und ein Abschlagsvorschlag“) |
| `heizgrenze` | Heizgradtage vom eigenen Standort, Heizgrenze je Zähler; passt eine andere Heizgrenze deutlich besser, steht es unter Zu tun. | `docs/einstieg/funktionen.md` „Heizgradtage“ (seit v3.9.0), CHANGELOG 3.9.0 (#38) |
| `gas-umrechnung` | Bei Gas die Umrechnung von m³ in kWh über Zustandszahl und Brennwert, mit Stichtagen. | `docs/einstieg/funktionen.md` „Rechnungsprüfung“ (m³ × Zustandszahl × Brennwert), CHANGELOG 2.5.0 „Gas-Umrechnung mit Stichtagen“ (F1012) |
| `tank` | Tankbuch für Öl und Pellets: Bestand aus Lieferungen und Peilständen; in der Werkstatt, wann der Tank leer ist und ab wann nachgefüllt werden sollte. | `docs/verstehen/05-heizoel.md` § 3 „Tankbuch“ (seit v2.10.0), CHANGELOG 3.12.0 „Heizöl und Pellets in der Werkstatt“, Bild `docs/ui/screenshots/heizoel-view.png` (Peilstände) |
| `heizungstausch` | Heizungstausch: dieselbe Wärme mit Wärmepumpe oder Pelletheizung, mit genannten, änderbaren Annahmen. | CHANGELOG 3.12.0 „Heizungstausch als zweite Rechnung“ |
| `werkstatt-annahmen` | Jede Zahl eines Beispiels in der Werkstatt steht als angenommener Beispielwert da, kein Richtwert und kein Sparversprechen. | CHANGELOG 3.12.0 „Prognose-Werkstatt je Energieart“, zweiter Punkt (wörtlich) |

## Zur Miete

| Kennung | Aussage | Beleg |
|---|---|---|
| `miete-vorauszahlung` | Vorauszahlung gegen die erwarteten Kosten des laufenden Abrechnungszeitraums, passende Vorauszahlung je Monat; eine Hilfsrechnung, keine Nebenkostenabrechnung. | `docs/anleitungen/mieter.md` Einleitung und Kasten, `docs/einstieg/funktionen.md` „Mietverhältnis“; Bild aufgenommen mit v3.12.0, Beispielhaushalt „Ich wohne zur Miete“ (`tools/aufnahme.mjs`) |
| `co2` | CO₂-Kosten teilen: bei eigener Gastherme Anteil des Vermieters mit Anschreiben als PDF, bei Zentralheizung Prüfung der Heizkostenabrechnung. | `docs/anleitungen/co2-aufteilung.md` § 2 „Welcher Fall bist du?“ |
| `miete-fristen` | Fristen für Abrechnung und Einwände im Kalender, Abrechnungen samt PDF an einem Ort. | `docs/anleitungen/mieter.md` Einleitung und § 8, `docs/einstieg/funktionen.md` „Mietverhältnis“ |
| `co2-de` | Die CO₂-Aufteilung gibt es nur für Deutschland, nach dem CO2KostAufG, als Hilfsrechnung ohne Rechtsberatung. | `docs/anleitungen/co2-aufteilung.md` § 1 (Land Deutschland) und Kasten „Eine Hilfsrechnung, keine Rechtsberatung“ |

## Mit Home Assistant

| Kennung | Aussage | Beleg |
|---|---|---|
| `ha-tagline` | Home Assistant misst, der Energietracker rechnet ab; HA liefert die Stände still im Hintergrund. | `README.de.md` Zeile 5, `docs/anleitungen/home-assistant.md` „Ziel“ |
| `ha-ingest` | Zählerstände kommen täglich oder je Viertelstunde über einen REST-Befehl, geschützt mit einem Schlüssel. | `docs/anleitungen/home-assistant.md` „Überblick“ (API-Token, REST-Command), Schritte 3 und 4, „Viertelstunden“ (v3.3.0) |
| `ha-app` | Als App in Home Assistant OS: Seitenleiste, Anmeldung mit HA-Benutzern, Daten in HA-Sicherungen. | HA `README.md`, `docs/anleitungen/home-assistant.md` „Als App in Home Assistant OS“ |
| `hacs` | HACS-Integration: Sensoren ohne YAML für Zu tun, nächsten Termin, je Zähler Saldo, Verbrauch seit Jahresbeginn, Prognose 12 Monate, Tage seit Ablesung. | HACS `README.md` „Sensors“, CHANGELOG 3.12.0 „HACS-Integration 1.0.0 (F1038)“, `custom_components/energietracker/manifest.json` (1.0.0) |
| `ha-notify` | Fristen und neue Post meldet der Energietracker selbst an Home Assistant. | `docs/anleitungen/home-assistant.md` „Überblick“ Punkt 5 und Schritt 6 (seit v3.9.0) |
| `ha-experimentell` | Die App für Home Assistant ist als experimentell markiert. | HA `energietracker/config.yaml` (`stage: experimental`), HA `README.md` |
| `hacs-custom` | Die Integration ist ein benutzerdefiniertes Repository im Marketplace oder in HACS, bis HACS sie aufnimmt; das dauert laut HACS Monate. | CHANGELOG 3.12.0 „Bekannte Grenzen“, Punkt HACS; Antrag hacs/default#13856; Marketplace ab Home Assistant 2026.11 (Katalog `hacs/default`, Abnahme 2026-11-10) |
| `verbindungen` | Verbindungen: Daten holen und Daten geben, je mit Stand; alles freiwillig, ohne Verbindung läuft die App vollständig. | Bild `docs/ui/screenshots/verbindungen.png`, Text in `public/locales/de.json` („Ohne eine einzige Verbindung läuft die App vollständig“), CHANGELOG 3.12.0 „Verbindungen“ |
| `wegweiser` | Der Wegweiser der App zeigt sechs Wege: von Hand, mit Versorgerpost, mit Home Assistant, mit KI lokal, mit KI in der Cloud (experimentell), alles zusammen. | CHANGELOG 3.12.0 „Wegweiser (F1039)“, Schaubilder `docs/ui/wegweiser/*.svg` |

## Deine Rechte als Kunde

| Kennung | Aussage | Beleg |
|---|---|---|
| `rechte-frage` | Der Energietracker liest Verträge, Rechnungen und Abschläge mit einer zweiten Frage: Steht dir hier als Kunde etwas zu? | `docs/verstehen/17-rechte.md` Einleitung (seit v3.11.0) |
| `rechte-sieben` | Sieben Prüfungen nach deutschem Recht, darunter Rechnung zu spät, Abschlag zu hoch, Sonderkündigung nach einer Preiserhöhung, fehlender Bonus. | `docs/verstehen/17-rechte.md` „Auf einen Blick“ (sieben Zeilen), `README.de.md` „Sieben Prüfungen“ |
| `rechte-quelle` | Jeder Hinweis nennt die Vorschrift mit Link und Stand. | `docs/verstehen/17-rechte.md` Einleitung und „Grundsätze“, `docs/einstieg/funktionen.md` „Deine Rechte als Kunde“, Bild `docs/ui/screenshots/rechte-hinweis.png` |
| `rechte-fristen` | Sonderkündigung und Gebührenbescheid stehen 14 Tage vorher unter Zu tun und im Kalender. | `docs/verstehen/17-rechte.md` „Grundsätze“ („Fristen sind Termine“) |
| `briefe` | Briefe als Text und PDF, gefüllt aus deinen Daten, aus festen Bausteinen und nie aus einem Sprachmodell; die App schickt nichts ab. | `docs/verstehen/17-rechte.md` „Grundsätze“ („Vorlagen statt freier Text“), `README.de.md` („als Text und PDF“), CHANGELOG 3.12.0 „Briefe füllen sich aus deinen Daten“, Bild `docs/ui/screenshots/brief-dialog.png` |
| `rechte-kein-rat` | Ein Hinweis, keine Rechtsberatung; die App sagt nie, dass etwas rechtswidrig ist; Hilfe bei Verbraucherzentrale und Schlichtungsstelle Energie. | `docs/verstehen/17-rechte.md` Kasten „Hinweis, keine Rechtsberatung“ |
| `rechtsquellen` | Jede Vorschrift mit Link, Lesedatum und Sicherheit der Aussage; unsichere Regeln nennt die App nie; ein Test hält Seite und Code gleich. | `docs/referenz/rechtsgrundlagen.md` Einleitung und „Status“ (W, S, G, U), `docs/verstehen/17-rechte.md` „Grundsätze“, `src/Config/Rights.php` |
| `rechte-nur-de` | Rechte nur nach deutschem Recht; in anderen Ländern keine Hinweise zu Rechten. | `docs/verstehen/17-rechte.md` „Grundsätze“ („Nur Deutschland“), `docs/referenz/rechtsgrundlagen.md` |
| `grenze-gegenlesen` | Vor jedem Release liest ein Mensch jede Zeile der Rechtsgrundlagen gegen den Wortlaut; für v3.11.0 steht das laut der Seite noch aus. | `docs/referenz/rechtsgrundlagen.md` Absatz „Gegenlesen“ (Zeilen 37 und 38) |

## Mit deiner eigenen KI

| Kennung | Aussage | Beleg |
|---|---|---|
| `ki-frage` | Frag deine KI, wie es um Strom und Gas steht. | `README.de.md` „Auf einen Blick“, erste Zeile |
| `ki-akte` | Die KI ist die Oberfläche, der Energietracker die Akte dahinter: Er rechnet, die KI erzählt. | `docs/anleitungen/ki-assistent.md` Zeilen 13 und 14 (wörtlich) |
| `ki-werkzeuge` | 20 Werkzeuge über MCP; sie rechnen mit denselben Diensten wie die Oberfläche und nennen dieselben Zahlen. | CHANGELOG 3.12.0 „20 Werkzeuge“; Tests `ReadmeClaimsTest::testToolCountMatchesTheRegistry`, `McpConsistencyTest` |
| `ki-herkunft` | Jede Zahl mit Herkunft (gemessen, eingetragen, verteilt, angenommen, geschätzt), Geschätztes mit Spanne. | CHANGELOG 3.12.0 „Herkunft je Zahl“ und „Server-Anweisungen“, `docs/verstehen/18-herkunft.md`, Test `McpProvenanceTest` |
| `ki-vorschlag` | Die KI schlägt vor; ab Werk bestätigst du jeden Vorschlag in der App. | `README.de.md` „Auf einen Blick“, CHANGELOG 3.12.0 „Übernahme nur in der App (ab Werk)“, Test `ReadmeClaimsTest::testAiIsOffByDefaultAndProposalsAreConfirmedInTheApp`, `McpApplyInAppTest` |
| `ki-aus` | Ab Werk aus, eigener Schlüssel je Programm. | CHANGELOG 3.12.0 „KI-Schnittstelle ab Werk aus“ und „Eigene Schlüssel für die KI“, `docs/anleitungen/ki-assistent.md` Zeilen 18 und 19 |
| `ki-geprueft` | Geprüft mit Open WebUI und einem lokalen Modell über Ollama; andere Programme und Cloud-Modelle experimentell. | `docs/anleitungen/ki-assistent.md` doc-claim `mcp-tested-with`, Test `ReadmeClaimsTest::testTestedClientsMatchTheGuide` |
| `grenze-ki-nachfrage` | Ob ein anderes Programm vor einer Übernahme nachfragt, ist nicht geprüft; deshalb bestätigst du ab Werk in der App. | CHANGELOG 3.12.0 „Bekannte Grenzen“, erster Punkt |
| `demo-ohne-ki` | Die öffentliche Demo zeigt die KI-Seite, hat aber keine KI-Schnittstelle. | CHANGELOG 3.12.0 „In der App, nur für Verwalter“ (letzter Satz) und „Bekannte Grenzen“, Punkt HACS |
| `mcp-aus` | Die KI-Schnittstelle ist ab Werk aus und nimmt nur Anfragen mit eigenem Schlüssel an. | `README.de.md` „Deine Daten“, letzter Absatz |

## In jedem Haushalt

| Kennung | Aussage | Beleg |
|---|---|---|
| `zutun` | Zu tun sammelt Fristen, Termine, Empfehlungen, Rechte und Post in einer Liste. | CHANGELOG 3.12.0 „Zu tun sammelt alles Offene“ |
| `kalender` | Termine und Fristen als Abo im eigenen Kalender. | `docs/einstieg/funktionen.md` „Kalender-Abo“, `docs/anleitungen/kalender.md` |
| `jahresbericht` | Jahresbericht als PDF für Leser ohne Technikhintergrund, das Wichtigste vorn, je Verbrauchsart eine Seite. | `docs/einstieg/funktionen.md` „Jahresbericht“ (seit v3.4.0) |
| `werkstatt` | Die Werkstatt rechnet Winter, Dämmung, Raumtemperatur und Tarif mit deinen Daten durch. | `README.de.md` „Auf einen Blick“, fünfte Zeile |

## Vertrauen

| Kennung | Aussage | Beleg |
|---|---|---|
| `daten-aussen` | Hinaus geht ab Werk nur der Wetterabgleich mit gerundetem Standort, abschaltbar; alles andere schaltest du selbst ein; ein Cloud-Dienst nur ausdrücklich. | `README.de.md` „Deine Daten“ und „Auf einen Blick“ (letzte Zeile), Schaubild `docs/ui/wegweiser/together.de.svg`, Test `OutboundHostsTest` |
| `agpl` | Offener Code unter der GNU AGPL v3.0 oder neuer; zu Hause gehört er dir. | `LICENSE`, `README.de.md` „Lizenz“ |
| `quellcode-laufend` | Unter Einstellungen › System führt ein Link zum Quellcode genau der laufenden Version. | `README.de.md` „Lizenz“, dritter Punkt |
| `signiert` | Seit 3.10.0 ist jedes Image signiert, ohne Schlüssel (cosign), im öffentlichen Transparenzprotokoll. | `SECURITY.md` „Das Image prüfen“, `docs/betrieb/docker.md` „Signatur und Stückliste prüfen (v3.10.0)“ |
| `sbom` | Jedes Image trägt Stückliste und Herkunftsnachweis. | `SECURITY.md` „Das Image prüfen“ (seit 2.6.0) |
| `neubau` | Jeden Dienstag wird das neueste Release neu gebaut und auf bekannte Lücken geprüft. | `SECURITY.md` „Container-Image: Prüfung und Fristen“ |
| `security-melden` | Sicherheitslücken vertraulich über GitHub melden; Eingangsbestätigung binnen 7 Tagen. | `SECURITY.md` „Eine Sicherheitslücke melden“ |
| `tests` | 3.12.0 hat 2.667 Testmethoden; jede neue Regel hat eine Gegenprobe, die sie bricht; Rechenwege von Hand nachgerechnet; jede Zahl im README hängt an einem Test. | CHANGELOG 3.12.0 Abschnitt „Tests“ (letzter Punkt, „Rechnen, von Hand nachgerechnet“, `ReadmeClaimsTest`) |
| `stabil` | API, CSV-Formate und Backup-Format ändern sich nur additiv; entfernt wird erst mit neuer Hauptversion nach Ankündigung. | `README.de.md` „Stand“ |
| `roadmap-takt` | Ein Release hat ein Thema, erscheint, wenn es fertig und geprüft ist, in der Regel nach ein bis drei Wochen. | `roadmap.md` „Als Nächstes“ |
| `ideen-bewertet` | Vor jedem Release werden Ideen nach Nutzen für viele, Nähe zur Rechnung, Aufwand und Datenschutz bewertet; angenommene bekommen einen Milestone, abgelehnte eine Begründung. | `roadmap.md` „Wünsche und Fehler melden“ |
| `grenzen-changelog` | Seit 3.7.0 nennt jedes Release im CHANGELOG „Bekannte Grenzen“; die Roadmap sagt, was bewusst nicht geplant ist. | `CHANGELOG.md`: Abschnitte „Bekannte Grenzen“ in 3.7.0, 3.9.0, 3.10.0, 3.10.1, 3.11.0, 3.12.0; `roadmap.md` „Bewusst nicht geplant“ |
| `aussen-wetter` | Wetterabgleich mit Open-Meteo: ab Werk an, abschaltbar, Standort auf rund 1 km gerundet, täglich. | `README.de.md` „Deine Daten“ |
| `aussen-ort` | Ortssuche bei Open-Meteo nur, wenn du sie benutzt; hinaus geht der gesuchte Ort. | `README.de.md` „Deine Daten“ („bei der Ortssuche, wenn du sie benutzt“). Dass dabei der Suchbegriff übertragen wird, folgt aus der Funktion und steht dort nicht wörtlich. |
| `aussen-version` | Hinweis auf neue Versionen: du schaltest ihn ein; einmal am Tag wird GitHub nach der neuesten Versionsnummer gefragt. | `README.de.md` „Wege, die du selbst einschaltest“, letzter Punkt |
| `aussen-smard` | Börsenstrompreise von SMARD nur auf Knopfdruck; dabei geht nichts von dir hinaus. | `README.de.md` „Wege, die du selbst einschaltest“ |
| `aussen-heimnetz` | Texterkennung, evcc und Home Assistant nur, wenn du sie einträgst, an deine eigenen Dienste im Heimnetz. | `README.de.md` „Wege, die du selbst einschaltest“, erste drei Punkte |
| `aussen-cloud` | Auslesen in der Cloud ist aus, bis ein Verwalter es einschaltet; dann gehen Dokumente an den gewählten Dienst; experimentell. | `README.de.md` „Deine Daten“, Absatz „Auslesen in der Cloud ist aus“ |
| `vendored` | Schriften, Chart.js und PDF.js liegen im Repository, nichts kommt zur Laufzeit von einem CDN. | `README.de.md` „Deine Daten“ (letzter Satz), `CREDITS.md` („nothing is loaded from a CDN at runtime“) |
| `laender` | Länderprofile für acht weitere Länder setzen Währung, Formate und Wetter; dort keine Effizienzklassen, keine Währungsumrechnung; fachlich in Deutschland zu Hause. | `README.de.md` „Länder und Annahmen“ (neun Länderprofile einschließlich Deutschland) |
| `grenze-iphone` | iPhone und iPad im Testbrowser mit WebKit geprüft, nicht auf einem echten Gerät. | CHANGELOG 3.12.0 „Bekannte Grenzen“, Punkt Oberfläche |

## Installieren

| Kennung | Aussage | Beleg |
|---|---|---|
| `image-gleich` | Überall läuft dasselbe Programm; die App für Home Assistant nutzt dasselbe Image wie Docker. | HA `README.md` („runs the regular multi-arch image“), `docs/anleitungen/home-assistant.md` („dasselbe Image wie der Docker-Betrieb“) |
| `multiarch` | Das Image gibt es für amd64 und arm64. | `README.de.md` Abzeichen „Docker: amd64 und arm64“, `docs/betrieb/docker.md` „Synology Container Manager“ („das Image ist Multi-Arch“) |
| `install-docker` | `docker run` wie angegeben, `http://localhost:8080` öffnen, „Mit Beispieldaten ausprobieren“ ohne Verlust eigener Daten. | `README.de.md` „Schnellstart“ |
| `install-ha` | Repository hinzufügen (ein Klick oder Einstellungen › Apps › ⋮ › Repositories), installieren und starten, in der Seitenleiste öffnen; wer zuerst öffnet, verwaltet. | `docs/anleitungen/home-assistant.md` „Als App in Home Assistant OS“, „Installieren“, HA `README.md` |
| `install-hacs` | Sensoren über die Integration als benutzerdefiniertes Repository, im Marketplace (ab Home Assistant 2026.11, einmal mit GitHub verbinden) oder in HACS; der Knopf öffnet sie in Home Assistant. | `README.md` der Integration „Installation“; Abnahme 2026-11-10 in Home Assistant 2026.11.1 (Marketplace bietet v1.0.1 an, `hacs_repository` leitet auf den Marketplace um) |
| `install-nas` | Synology: Container Manager, Projekt › Erstellen, Ordner und `docker-compose.yml`, Projekt starten, `http://<NAS-Adresse>:8080`. | `docs/betrieb/docker.md` „Synology Container Manager“ |
| `nas-vorlagen` | Vorlagen für Unraid, CasaOS und Umbrel liegen bei; die Unraid-Vorlage ist gegen Format und Image geprüft, auf einem echten Server nicht ausprobiert. | `docs/betrieb/docker.md` „Unraid“ (Kasten „Wie geprüft“), „CasaOS und ZimaOS“, „Umbrel“; `deploy/` |
| `install-web` | Apache oder nginx mit PHP ab 8.3, ohne Composer; `.htaccess` bzw. `docker/nginx.conf`; Schreibrecht auf `data/`. | `docs/betrieb/installation.md` §§ 1 und 3, `docs/betrieb/webserver.md`, `README.de.md` („Ohne Docker genügt PHP ab 8.3“), CHANGELOG 3.12.0 („PHP 8.3 ist jetzt die Mindestversion“) |
| `anmeldung` | Ohne Anmeldung kann jeder, der die App erreicht, alles lesen und ändern; im Heimnetz in Ordnung, vor Zugriff von außen einschalten. | `README.de.md` Warnkasten unter „Schnellstart“ |

## Vorhaben und Mitmachen

| Kennung | Aussage | Beleg |
|---|---|---|
| `offen` | Code, Handbuch, Roadmap und jede Änderung sind öffentlich. | öffentliche Repositories auf GitHub, `CHANGELOG.md`, `roadmap.md`, `docs/` |
| `repo-main` | Hauptrepo: die App mit Handbuch auf Deutsch und Englisch, Roadmap und CHANGELOG, AGPL-3.0-or-later. | `README.de.md`, `docs/README.md`, `docs/en/README.md`, `LICENSE` |
| `repo-ha` | Die App für Home Assistant nutzt dasselbe Image wie Docker; ihre Version ist die des Energietrackers. | HA `README.md` |
| `repo-hacs` | Die HACS-Integration liest nur stabile Schnittstellen; ein neues Release des Energietrackers braucht kein neues der Integration. | HACS `README.md` „Versions“ |
| `unabhaengig` | Unabhängig: keine Firma dahinter, keine Werbung, kein Cloud-Konto. | `README.de.md` „Unterstützen“ |
| `freizeit` | Der Energietracker wird in der Freizeit gepflegt. | `SECURITY.md` „Eine Sicherheitslücke melden“ |
| `stern` | Ein Stern auf GitHub hilft, wie Fehlermeldungen und Weitersagen. | `README.de.md` „Unterstützen“ |
| `ideen` | Ideen unter Discussions › Ideas, mit Daumen hoch für bestehende Ideen. | `roadmap.md` „Wünsche und Fehler melden“, `README.de.md` („Ideen mit Abstimmung“) |
| `fehler-melden` | Fehler als Issue mit Version, Schritten und erfundenem Beispiel statt echter Rechnungen. | `roadmap.md` „Wünsche und Fehler melden“ |
| `mitwirken` | Pull Requests sind willkommen; eine neue Sprache ist vor allem Übersetzung. | `README.de.md` „Mitwirken“, `CONTRIBUTING.de.md` |
| `unterstuetzen` | Unterstützen ganz freiwillig über buymeacoffee.com/bingerminger. | `README.de.md` „Unterstützen“ |

## Die Seite selbst

| Kennung | Aussage | Beleg |
|---|---|---|
| `seite-selbst` | Die Seite lädt nichts von fremden Servern, setzt keine Cookies, zählt keine Besuche; Schriften, Bilder und Skripte liegen auf ihr selbst; ausgeliefert von GitHub Pages. | `tools/build.mjs --check` (Regel R1: keine Ressource von außen, `seite.js` ohne Serverzugriff und ohne `document.cookie`), `assets/seite.js`, Einstellungen des Repos (Pages aus `main`) |
| `marken` | Produktnamen nur zur Beschreibung, keine Verbindung zu den Inhabern. | `TRADEMARKS.md` („purely descriptive … no connection“) |
| `schrift` | Schrift DM Sans und DM Mono unter der SIL Open Font License 1.1. | `CREDITS.md`, `assets/fonts/OFL.txt` |

## Überarbeitung: Wertversprechen, Ablauf, Slider, Nutzen

| Kennung | Aussage | Beleg |
|---|---|---|
| `wertversprechen` | Deine Energiekosten im Griff: Abschlag, Rechnung, Wechsel und Rechte. | Zusammenfassung der hier belegten Funktionen `saldo`, `abschlag`, `rechnung-pruefen`, `wechsel`, `rechte-sieben`; darüber hinaus keine eigene Behauptung |
| `agpl-kostenlos` | Kostenlos, offener Code (AGPL): nutzen, verändern und weitergeben ohne Konto und ohne Gebühr. | `README.de.md` „Lizenz“ („kostenlos nutzen, verändern, weitergeben, ohne Bedingungen, ohne Konto, ohne Gebühr“), `LICENSE` |
| `ohne-gewinn` | Ein privates Projekt ohne Gewinnabsicht. | Auskunft des Maintainers vom 2026-11-10 im Auftrag zur Projektseite („keine Gewinnabsichten“); `README.de.md` „Unterstützen“ (keine Firma dahinter, keine Werbung) |
| `kuendigung-erinnerung` | Der Energietracker sagt rechtzeitig, was ansteht: Erinnerung an Kündigungsfristen bis zum letzten Kündigungstag. | `docs/einstieg/funktionen.md` „Kündigungsfristen … Erinnerung in drei Stufen bis zum letzten Kündigungstag“ |
| `eingabe-wege` | Stände von Hand, per CSV, als PDF aus der Versorgerpost oder automatisch aus Home Assistant. | `README.de.md` „Für wen“, Zeile Zählerstände („von Hand, per CSV, aus der Versorgerpost oder von Home Assistant“), `docs/anleitungen/posteingang.md` |
| `vertrag-tagesgenau` | Gerechnet wird mit dem Vertrag auf den Tag genau. | `docs/einstieg/funktionen.md` „Verträge … tagesgenau: Ein Wechsel oder eine Preisänderung zur Monatsmitte gilt ab ihrem Tag“ |
| `wetter` | Mit dem Wetter am eigenen Ort; jeder Monat gegen seine Erwartung bei diesem Wetter. | `docs/einstieg/funktionen.md` „Temperaturen automatisch von Open-Meteo für den eigenen Standort“ und „Heizgradtage … Wetterbereinigung“ |
| `prognose` | Prognose bis zu 24 Monate voraus, mit Unsicherheitsband und den dann gültigen Preisen; bis zur Abrechnung. | `docs/einstieg/funktionen.md` „Prognose über bis zu 24 Monate mit Unsicherheitsband (80 % der Jahre), Kosten je Monat aus dem dann gültigen Tarif“ |
| `zaesur` | Die Auswertung misst die Wirkung einer Sanierung je Gradtag und sagt, ob sie statistisch belegt ist. | `docs/einstieg/funktionen.md` „Zäsur … Vorher/Nachher-Kennzahl je Gradtag, mit Aussage, ob sie statistisch belegt ist“, Bild `docs/ui/screenshots/analyse.png` |
| `kennzahlen` | Kennzahlen: Effizienz in kWh je m² mit Klasse, Einordnung als Ampel, Bestand in den Tanks. | `docs/einstieg/funktionen.md` „Effizienz“ und „Einordnung … als Ampel“, CHANGELOG 3.12.0 „Auswertungen › Kennzahlen“ |
| `sparen-belegbar` | Keine erfundenen Sparbeträge, du siehst deine eigenen Zahlen. | `README.de.md` „Auf einen Blick“, Zeile „Sparen, wo es belegbar ist“ |
| `abschlag-brief` | Ist der Abschlag deutlich zu hoch, gibt es den Antrag als Brief (Strom, Gas, Fernwärme). | `docs/verstehen/17-rechte.md` „Auf einen Blick“, Zeile „Abschlag deutlich zu hoch“ (Antrag auf Abschlagsanpassung), CHANGELOG 3.12.0 „Brief schreiben am Vertrag“ |
| `demo-routen` | Die Sprünge führen in den genannten Beispielhaushalt und die genannte Ansicht der Demo. | `public/js/lib/demo-mode.js` (`?persona=`, `?lang=`; Haushalte `mieterin`, `etw-fernwaerme`, `eigenheim-klassisch`, `eigenheim-modern`, `showcase`), `public/js/router.js` `ROUTES` (jede verlinkte Ansicht steht dort), Stufe Experte ab Werk in der Demo (`public/js/lib/levels.js`); `#/utility/strom`, `#/tenancy`, `#/bill-check`, `#/tariffs`, `#/forecast`, `#/todo?art=rights`, `#/connections/ai?mcp=value` am 2026-11-10 in v3.12.0 lokal geöffnet |

## Alle Energieträger

| Kennung | Aussage | Beleg |
|---|---|---|
| `art-gas` | Gas: m³ in kWh nach Stichtagen, Rechnung prüfen, Wetter herausgerechnet. | `docs/verstehen/01-gas.md` „Umrechnungsfaktoren mit Stichtagen“, „Rechnungsprüfung“; `docs/einstieg/funktionen.md` „Wetterbereinigung“ |
| `art-strom` | Strom: HT/NT, Wärmepumpe und Wallbox, steuerbare Verbraucher, dynamische Tarife. | `docs/verstehen/02-strom.md` „Hoch- und Niedertarif“, „Steuerbare Verbraucher“, „Dynamische Tarife“, „Wallbox und Dienstwagen“ |
| `art-wasser` | Wasser: Trinkwasser, Schmutzwasser und Niederschlag, Garten- und Warmwasserzähler. | `docs/verstehen/03-wasser.md` „Drei-Komponenten-Tarif“, „Rollen: Kalt, Warm, Garten“ |
| `art-fernwaerme` | Fernwärme: Leistungs- und Messpreis wie auf der Rechnung, Rechnung prüfen. | `docs/verstehen/04-fernwaerme.md` „Feste Kosten: Leistungs- und Messpreis“, `docs/einstieg/funktionen.md` „Rechnungsprüfung für Gas, Strom, Wasser und Fernwärme“ |
| `art-heizoel` | Heizöl: Tankbuch aus Lieferungen und Peilständen, Bestand über das Jahr. | `docs/verstehen/05-heizoel.md` „Tankbuch“, „Tank-Bestandskurve“ |
| `art-pellets` | Pellets: wie Heizöl, in Kilogramm, die Lieferrechnung ist der Vertrag. | `docs/verstehen/06-pellets.md` §§ 1 und 2 |
| `art-pv-einspeisung` | PV-Einspeisung: Vergütung je Abrechnungsjahr, Gutschriften des Direktvermarkters. | CHANGELOG 3.12.0 „PV-Einspeisung je Abrechnungsjahr“, `docs/verstehen/12-pv.md` § 11 |
| `art-pv-erzeugung` | PV-Erzeugung: Eigenverbrauch, Autarkie, Speicher, Balkonkraftwerk, Amortisation. | `docs/verstehen/12-pv.md` §§ 4, 7, 8, 9 |
| `art-waerme` | Heizwärme: Verbrauchsinfo des Messdienstes, Warmwasser, Jahresarbeitszahl der Wärmepumpe. | `docs/verstehen/15-waerme.md` §§ 2, 6, 7, `docs/anleitungen/mieter.md` § 3 |

## Home Assistant und Häufige Fragen

| Kennung | Aussage | Beleg |
|---|---|---|
| `ha-vorlagen` | Unter Verbindungen › Home Assistant stehen die Vorlagen zum Kopieren. | `docs/anleitungen/home-assistant.md` „Überblick“ („unter Verbindungen → Home Assistant vorbereiten, inkl. Copy-&-Paste-YAML“) |
| `hacs-voraussetzung` | Die HACS-Integration braucht den Energietracker ab 3.9.0 und Home Assistant ab 2026.3. | HACS `README.md` „Requirements“, `hacs.json` (`homeassistant`: `2026.3.0`) |
| `ki-cloud-kosten` | Wer eine KI in der Cloud anbindet, zahlt dort womöglich deren Anbieter. | `docs/anleitungen/ki-assistent.md` („Ein Modell in der Cloud … dort können Kosten entstehen“) |
| `backup` | Backup als Datei. | `README.de.md` „Für wen“ („CSV und JSON-Backup, keine Datenbank“) |
| `vertrag-eintragen` | Vertrag und Preise trägst du selbst ein oder übernimmst sie aus der Rechnung. | `README.de.md` „Für wen“, Zeile Kosten; CHANGELOG 3.9.0 (Vertrag aus der Rechnung) |
| `kein-versorger` | Eine Verbindung zum Versorger braucht es nicht. | `README.de.md` „Deine Daten“ (vollständige Liste der Verbindungen nach außen, kein Versorger darunter), Test `OutboundHostsTest` |
| `ki-kein-nachteil` | Wer keine KI nutzt, hat keinen Nachteil. | `docs/anleitungen/ki-assistent.md` „Die Grenzen“ (wörtlich) |
| `posteingang-dienst` | Für das Auslesen der Versorgerpost trägst du einen eigenen Dienst ein. | `README.de.md` „Wege, die du selbst einschaltest“ (eigener Texterkennungsdienst im Heimnetz) und „Auslesen in der Cloud ist aus“ |
| `json-ld` | Maschinenlesbare Beschreibung (schema.org SoftwareApplication): Name, Version 3.12.0, Lizenz AGPL, kostenlos, sieben Sprachen, Docker amd64/arm64, Home Assistant OS, PHP ab 8.3, Releases, Handbuch, Autor als GitHub-Konto. | `VERSION`, `LICENSE`, Kennungen `agpl-kostenlos`, `sprachen`, `multiarch`, `ha-app`, `install-web`, `pseudonym`; ohne Zählung oder Verweis auf Dienste Dritter |

## Über das Projekt und Datenschutz

| Kennung | Aussage | Beleg |
|---|---|---|
| `pseudonym` | Entwickelt und gepflegt von Bingerminger auf GitHub. | Konto `github.com/Bingerminger` (Inhaber der drei Repositories), Autor der Commits |
| `kontakt-github` | Kontakt über GitHub: Discussions, Issues, vertraulich über Security Advisories. | `README.de.md` „Hilfe und Gemeinschaft“, `SECURITY.md` „Eine Sicherheitslücke melden“, CHANGELOG 3.12.0 („Gemeldet wird nur über GitHub“) |
| `impressum` | Impressum nach § 18 Abs. 1 MStV: Name und Anschrift des Anbieters (Zustellanschrift c/o), auf der Seite „Über“, von jeder Seite über „Impressum“ in der Fußzeile erreichbar. | Angabe des Anbieters (2026-11-10); § 18 Abs. 1 MStV (Name und Anschrift, leicht erkennbar, unmittelbar erreichbar, ständig verfügbar); Fußzeile in `src/seiten.mjs` |
| `verantwortlich` | Verantwortlich für die Projektseite ist der Anbieter aus dem Impressum. | Kennung `impressum`; die Seite gehört zum Konto `github.com/Bingerminger` (Repository `Bingerminger.github.io`) |
| `seite-speicher` | Im Browser merkt sich die Seite nur die Wahl der Darstellung und ob der Sprachhinweis geschlossen wurde. | `assets/seite.js` (einzige Schlüssel `projektseite.thema` und `projektseite.sprache`, nur `localStorage`), Kopf jeder Seite (liest nur `projektseite.thema`), Check R1 (kein `document.cookie`) |
| `github-pages-log` | GitHub Pages protokolliert beim Aufruf die IP-Adresse zur Sicherheit, auch ohne Anmeldung. | docs.github.com, „About GitHub Pages“, Abschnitt „Data collection“ (abgerufen 2026-11-10) |
| `links-aussen` | Links nach außen führen zu GitHub, zur Demo, zu Home Assistant und zu buymeacoffee; erst beim Anklicken gelten deren Regeln. | Seiten selbst (alle fremden Adressen stehen nur in Links), Check R1 (nichts wird von außen geladen) |
