# Was der Energietracker kann

**Deutsch** · [English](../en/einstieg/funktionen.md)

[← Kompendium-Index](../README.md)

Die ausführliche Funktionsübersicht. Wie die Ansichten aussehen, zeigt die
[Referenz der Ansichten](../referenz/ansichten.md); wie gerechnet wird, steht
unter [Grundlagen & Methodik](../verstehen/00-overview.md).

---

## Erfassen

- **Zählerstände** je Zähler, mit Notiz und Markierung „geschätzt“; geplante
  Stände (etwa der nächste Abrechnungstermin) zählen erst, wenn der Tag da ist.
- **Zentrale Erfassung** aller Zähler in einem Durchgang — am iPhone mit
  Weiter-Taste, Live Text zum Abfotografieren des Zählwerks und
  „Rückgängig“; ein Lesezeichen `#/zaehlerstaende?meter=<id>` springt direkt
  zu einem Zähler.
- **Ohne Netz im Keller** (v3.1.0): Ein Stand, der nicht beim Server ankommt,
  wartet im Browser und wird nachgesendet — ohne Doppel, mit Rückfrage, wenn
  am selben Tag schon ein anderer Stand da ist
  ([Auf dem Handy nutzen](handy.md#die-warteschlange-noch-nicht-gespeichert)).
- **Foto als Beleg** (v3.1.0) je Stand, im Browser verkleinert und ohne
  GPS-Daten; mit einem eigenen Texterkennungsdienst im Heimnetz schlägt die App
  den Stand aus dem Foto vor
  ([Texterkennung im Heimnetz](../anleitungen/texterkennung.md)).
- **Plausibilitätsprüfung** vor dem Speichern: ein Mehrfaches des üblichen
  Tagesverbrauchs („Komma vergessen?“), ein kleinerer Stand ohne Zählertausch,
  ein Datum in der Zukunft, ein zweiter Stand am selben Tag. Ein Überlauf des
  Zählwerks (99.999 → 0) wird richtig gerechnet.
- **Zählertausch** als eigenes Datenmodell: Ein Zähler bündelt seine Geräte
  mit Seriennummer, Ein- und Ausbau, Anfangs- und Endstand; der Verbrauch
  über den Tausch hinweg stimmt.
- **Mehrere Zähler** je Verbrauchsart, **Subzähler** (vom Elternzähler
  abgezogen, keine Doppelzählung) und **Gruppen** (Summe auf der Übersicht) —
  siehe [Meter-Topologie](../verstehen/13-meter-topologie.md).
- **Verbrauch je Zeitraum** (v3.1.0) statt Zählerständen, wo der Verbrauch
  schon fertig vorliegt — etwa die monatliche Verbrauchsinfo für Heizung und
  Warmwasser. Die App verteilt jeden Zeitraum tagesgenau auf die Monate;
  danach rechnet alles wie bei Ständen. Einzeln, Monat für Monat in der
  Erfassung oder als CSV — siehe [Heizwärme](../verstehen/15-waerme.md).
- **Rollen** für Zähler (v3.1.0): Wärmepumpe oder Wallbox beim Strom,
  Kalt-, Warm- oder Gartenwasser, Speicher bei der PV, Wärmemenge der
  Wärmepumpe bei der Heizwärme.
- **Heizöl und Pellets** über Lieferungen statt Zählerstände, mit
  **Tankbuch**: Anfangsbestand, „bis voll getankt“ und Peilstände sind
  Stützstellen; dazwischen ist der Verbrauch gerechnet, danach geschätzt und so
  gekennzeichnet.
- **CSV-Import** von Ablesungen mit Vorschau: Jede Zeile zeigt ihre Wirkung
  (neu, ersetzt, unverändert) und die Rückfragen der Erfassung, bevor etwas
  geschrieben wird. Tabellen in jeder Sprache der App und mit
  `TT.MM.JJJJ`, `TT/MM/JJJJ` oder `TT-MM-JJJJ` werden gelesen, jede eigene
  Export-Datei ebenso.
- **Home Assistant** pusht Zählerstände automatisch (`POST /api/ingest`,
  idempotent, mit Token und Zähler-Alias, seit v3.1.0 auch als Stapel zum
  Nachliefern) und liest Saldo, Prognose und „Tage seit Ablesung“ als
  Sensoren zurück (`GET /api/summary`) — siehe
  [Home Assistant anbinden](../anleitungen/home-assistant.md). ioBroker,
  Node-RED und openHAB schicken Stände über denselben Weg — siehe
  [Andere Systeme](../anleitungen/andere-systeme.md).
- **Ladevorgänge aus evcc** (v3.2.0): den CSV-Export aus evcc hochladen oder
  direkt bei evcc im Heimnetz abrufen, mit Vorschau; je Monat Menge,
  Sonnenanteil und der Preis laut evcc, die Zählerstände der Wallbox gleich
  mit — siehe [Ladevorgänge aus evcc](../anleitungen/evcc.md).
- **Zeitreihen aus Portalen** (v3.1.0): Dateien von Netzbetreiber,
  Messstellenbetreiber, Wechselrichter oder Wärmepumpe — Viertelstunden-,
  Stunden- oder Tageswerte — mit Spaltenzuordnung einlesen, zu Tageswerten
  verdichtet, Zeitumstellung inklusive — siehe
  [Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md).
- **Temperaturen** automatisch von Open-Meteo für den eigenen Standort
  (Ortssuche, täglicher Abgleich, 30-jähriges Klimanormal) oder als CSV.

## Verträge, Abschläge, Abrechnung

- **Verträge** je Zähler mit Arbeitspreis, Grundpreis und Abschlag als
  Verlauf — tagesgenau: Ein Wechsel oder eine Preisänderung zur Monatsmitte
  gilt ab ihrem Tag. Ein Vertrag ohne Nachfolger läuft zu seinen letzten
  Preisen weiter, bis er gekündigt ist. Bei Fernwärme (v3.1.0) dazu
  **Leistungs- und Messpreis** nach Anschlussleistung — siehe
  [Fernwärme](../verstehen/04-fernwaerme.md).
- **Ein Vertrag für eine Zählergruppe** (v3.1.0): etwa Hoch- und Niedertarif
  mit einem Grundpreis und eigenem Arbeitspreis je Zählwerk; Saldo, Prognose,
  Wechsel und Rechnungsprüfung für die ganze Gruppe — siehe
  [Meter-Topologie](../verstehen/13-meter-topologie.md#gruppenvertrag-v310).
- **Steuerbare Verbraucher** (v3.1.0): das reduzierte Netzentgelt nach § 14a
  EnWG (Modul 1) im Stromvertrag, Modul 2 als eigener Zähler mit eigenem
  Vertrag; **Monatspreise** aus einer Datei für einen dynamischen Vertrag;
  **Gutschriften des Direktvermarkters** bei der Einspeisung — siehe
  [Strom](../verstehen/02-strom.md).
- **Ladestrom-Nachweis** (v3.1.0) für den Dienstwagen: je Monat die Menge an
  der Wallbox mit Vertragspreis oder Strompreispauschale, als CSV und PDF mit
  Zählerständen — eine Aufstellung, keine Steuerberatung — siehe
  [Ladestrom-Nachweis](../anleitungen/ladestrom-nachweis.md).
- **Boni** (Neukunden-, Treuebonus) und **Sonderzahlungen** (Rückzahlung,
  Nachzahlung, freiwillige Abschlagszahlung) — siehe
  [Sonderzahlungen](../verstehen/10-sonderzahlungen.md).
- **Saldo** je Vertrag, wie die Abrechnung ihn nennt: „Guthaben“ oder
  „Nachzahlung“, nach Kalender bis heute, mit Schätzung ab der letzten
  Ablesung, dazu die **erwartete Abrechnung** am Stichtag und ein
  **Abschlagsvorschlag**.
- **Kündigungsfristen** in Monaten, Wochen oder Tagen: Erinnerung in drei
  Stufen bis zum letzten Kündigungstag, Hinweis auf verpasste Fristen und
  angekündigte Preiserhöhungen (mit Sonderkündigungsrecht in Deutschland).
- **Rechnungsprüfung** für Gas, Strom, Wasser und Fernwärme (seit v3.1.0, bis
  dahin nur Gas): rechnet die Versorgerrechnung Abschnitt für Abschnitt nach —
  Menge, Preis, Verbrauchs- und feste Kosten, bei Gas m³ × Zustandszahl ×
  Brennwert = kWh. Die Werte der Rechnung lassen sich erfassen („Laut
  Rechnung“, mit PDF), vergleichen („passt“ oder „prüfen“, mit Gründen) und als
  Sonderzahlung buchen. Wie man eine Rechnung einträgt:
  [Jahresabrechnung eintragen und prüfen](../anleitungen/jahresabrechnung.md).
- **Verträge & Abschläge** auf einer Seite: alle laufenden Verträge mit
  Kündigungsfrist, Abschlag und zu erwartender Abrechnung.
- **Wasser** mit drei Komponenten: Trinkwasser, Schmutzwasser (nach
  Trinkwasser oder eigenem Zähler) und Niederschlagswasser nach versiegelter
  Fläche.
- **Mietverhältnis** (v3.1.0) für alle, die Heizung und Wasser über die
  Nebenkosten zahlen: Vorauszahlung gegen die erwarteten Kosten des laufenden
  Abrechnungszeitraums, mit Einschätzung und passender Vorauszahlung je Monat
  (eine Hilfsrechnung, keine Nebenkostenabrechnung); Nebenkostenabrechnungen
  mit PDF, aus denen die App Preise und neue Vorauszahlung übernimmt; die
  Fristen für Abrechnung und Einwände im Kalender — siehe
  [Als Mieter](../anleitungen/mieter.md).
- **CO₂-Kosten teilen** (v3.1.0, Deutschland, zur Miete): der Anteil des
  Vermieters nach dem CO2KostAufG — bei eigener Gastherme ausgerechnet, mit
  Anschreiben als PDF und der Frist im Kalender; bei Zentralheizung die
  Heizkostenabrechnung nachgerechnet — siehe
  [CO₂-Kosten mit dem Vermieter teilen](../anleitungen/co2-aufteilung.md).

## Wechseln

- **Wechselentscheidung**: der erwartete Jahresverbrauch zum Mitnehmen ins
  Vergleichsportal, der Wechseltermin aus Vertragsende und Kündigungsfrist,
  gefundene Angebote als Rangliste nach den dauerhaften Kosten ab dem zweiten
  Jahr, mit **Gewinnschwelle** („lohnt ab …“) und Kostenverlauf je Monat.
- Die **Bindungskette** zählt: Ist der Anschlussvertrag schon geschlossen,
  richtet sich der Termin nach dessen Ende.
- **Rückblick**: dieselben Angebote auf den tatsächlich gemessenen Verbrauch
  gelegt — „was hätte Tarif X gekostet?“.
- Bei der PV-Einspeisung umgekehrt: Die höhere Vergütung steht vorn.
- **Dynamik-Check** (v3.1.0, Strom): Was hätte ein dynamischer Tarif gekostet?
  Mit den Monatsmitteln der Börsenpreise von SMARD (nur auf Knopfdruck) oder
  aus einer Datei, Aufschlag und Umsatzsteuer — eine Näherung ohne Lastprofil —
  siehe [Strom → Dynamische Tarife](../verstehen/02-strom.md#dynamische-tarife-v310).
- Der **amtliche Tarifvergleich** des Landes ist verlinkt, wo es einen gibt
  (Österreich, Frankreich, Italien, Spanien, Portugal).
- **Für den Wechsel bereithalten** (v3.1.0): Marktlokations-ID, Zählernummer
  und letzter Stand zum Kopieren — das, was der neue Anbieter abfragt.
- **Preiserhöhung** im laufenden Vertrag (v3.1.0, Deutschland): Empfehlung mit
  dem Sonderkündigungsrecht und Eintrag unter „Zu tun“; beim Speichern eines
  Vertrags ein Hinweis, wenn die Mindestlaufzeit über 24 Monate reicht.

## Verstehen und vorhersehen

- **Schaubilder** (v3.2.0): „Wohin geht mein Geld?“, „Energiefluss im Haus“,
  „Kälter oder mehr verbraucht?“ und „Vertrag auf einen Blick“ — bewegte
  Erklärbilder mit den eigenen Zahlen, still bei „Bewegung reduzieren“, mit
  der Aussage als Text — siehe
  [Einrichten und Nutzungsstufen](einrichtung.md#4-schaubilder).
- **Heizgradtage** vom eigenen Standort gegen die Heizgrenze (Standard
  15 °C), dazu ein **Heizmodell** mit Grundlast und die **Wetterbereinigung**:
  „mehr verbraucht oder nur kälter?“ — jeder Monat gegen seine Erwartung bei
  diesem Wetter.
- **Fünf Modelle** für Heizgradtage gegen Verbrauch: linear, polynomial,
  robust, segmentiert (mit Knickpunkt aus den Daten) und Sigmoid, mit
  R²-Vergleich.
- **Zäsur**: eine Sanierung am Zähler datieren — ab dort rechnet jede
  Auswertung neu; die Wirkung steht als Vorher/Nachher-Kennzahl je Gradtag,
  mit Aussage, ob sie statistisch belegt ist. Seit v3.1.0 auch für Strom,
  Wasser und PV über dieselben Kalendermonate — etwa „Balkonkraftwerk in
  Betrieb“.
- **Prognose** über bis zu 24 Monate mit **Unsicherheitsband** (80 % der
  Jahre), Kosten je Monat aus dem dann gültigen Tarif, laufendem Saldo und
  Was-wäre-wenn (Temperaturversatz, Preisfaktor, seit v3.1.0 ein höherer
  CO₂-Preis).
- **Anomalien** (Monate weit weg von ihrer Erwartung), **Jahresvergleich**
  Monat für Monat und **Wasser-Spar-Index** je Person.
- **Effizienz** in kWh/m²·a — Klassen A+ bis H nach GModG (bis Juli 2026
  GEG), nur für ganze Jahre —
  und daneben eine **energieausweis-nahe Kennzahl** (Heizwert,
  witterungsbereinigt, Gebäudenutzfläche).
- **Heizwärme** (v3.1.0): die Wärme, die in der Wohnung ankommt, als eigene
  Verbrauchsart mit Heizmodell, Wetterbereinigung und Prognose; CO₂ als
  Näherung über den Energieträger der Heizung. Für Warmwasserzähler rechnet
  die App die **Wärme fürs Warmwasser** nach HeizkostenV § 9 aus — siehe
  [Heizwärme](../verstehen/15-waerme.md).
- **CO₂** mit Quelle (BAFA, Strommix je Jahr nach Umweltbundesamt); bei PV als
  vermiedenes CO₂.
- **CO₂-Preis im Brennstoff** (v3.1.0, Deutschland): wie viel BEHG-Preis in
  Gas, Heizöl und Fernwärme steckt — ausgewiesen, nicht aufgeschlagen; Preise
  je Jahr anpassbar — siehe [CO₂-Preis im Brennstoff](../verstehen/16-co2-preis.md).
- **PV**: Einspeisung als Vergütung, Erzeugung, Eigenverbrauch,
  Autarkiequote und die Ersparnis durch den Eigenverbrauch; seit v3.1.0
  **Speicher** (Verluste, Wirkungsgrad, Vollzyklen), **Amortisation**,
  **Balkonkraftwerk** ohne Einspeisezähler mit angenommenem Eigenverbrauch,
  der Hinweis auf § 51 EEG und ein eigener CO₂-Vermeidungsfaktor — siehe
  [PV](../verstehen/12-pv.md).
- **Wärmepumpe** (v3.1.0): Jahresarbeitszahl aus Wärmemengen- und Stromzähler,
  je Monat und für die Heizperiode, mit Werten eines Feldtests zur Einordnung
  — siehe [Heizwärme](../verstehen/15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310).
- **Einordnung** (v3.1.0): Haushaltsstrom und Heizung je m² gegen eigene
  Vergleichswerte, etwa aus Strom- oder Heizspiegel, mit Links zur
  Selbstprüfung in Deutschland.
- **Empfehlungen** aus den eigenen Daten (sieben Regelfamilien, einzeln
  ausblendbar) und **Termine** für Wartung, Schornsteinfeger und Eichfristen.
- **Kalender-Abo** (seit v3.1.0): Termine, Kündigungsstichtage, Vertragsenden,
  Ende der Preisgarantie, fällige Ablesungen und — zur Miete — die Fristen der
  Nebenkostenabrechnung und der CO₂-Erstattung im eigenen Kalender, bei
  Terminen und Verträgen mit Vorwarnung — siehe
  [Kalender abonnieren](../anleitungen/kalender.md).
- **Jahresbericht** als Druckansicht in der Sprache des Geräts — drucken oder
  als PDF sichern, am iPhone über Teilen → Drucken — und als fertige
  PDF-Datei im Browser oder zum Herunterladen.

## Bedienen

- **Mac und iPhone**: Navigation in sieben Bereichen nach den Fragen der
  Nutzer; am iPhone eine Tab-Leiste mit „＋ Erfassen“, Dialoge als Blatt,
  44-px-Tippziele, Kontraste nach WCAG AA, installierbar als App — siehe
  [Auf dem Handy nutzen](handy.md).
- **Hilfe in der App**: erste Schritte mit Häkchen aus den Daten, ein Glossar
  mit 43 Begriffen und ein ⓘ an jeder Kennzahl.
- **Sieben Sprachen** (Deutsch, Englisch, Französisch, Italienisch, Spanisch,
  Portugiesisch, Niederländisch), je Gerät wählbar, und **Länderprofile** für
  neun Länder: Währung, Formate, Zeitzone, Wetterstandort, Heizgrenze,
  CO₂-Faktor, Gaseinheiten und die Begriffe der Rechnung des Landes — siehe
  [Länderprofile](../verstehen/14-laenderprofile.md).
- **Hell, dunkel oder wie das System**.
- **Nutzungsstufen** (v3.2.0): Einsteiger, Erfahren oder Experte — die Stufe
  bestimmt, was die Oberfläche zeigt, gerechnet wird in jeder alles. Für
  Einsteiger eine Übersicht mit drei Antworten; Vorschläge zum Hochstufen,
  wenn die Daten mehr hergeben — siehe
  [Einrichten und Nutzungsstufen](einrichtung.md#3-nutzungsstufen).
- **Einrichtungsassistent** (v3.2.0) beim ersten Start: drei Fragen, danach
  passende Verbrauchsarten und Stufe; jederzeit erneut aus den Einstellungen.
- **Beispieldaten** zum Ausprobieren, ohne die eigenen zu verlieren (vorher
  sichert die App den jetzigen Stand); seit v3.2.0 vier Beispielhaushalte —
  Mietwohnung, Eigentumswohnung mit Fernwärme, Eigenheim klassisch und
  modern — neben dem Schaufenster mit fast allen Verbrauchsarten — siehe
  [Einrichten und Nutzungsstufen](einrichtung.md#2-die-beispielhaushalte).

## Betreiben

- **Keine Datenbank, keine Laufzeit-Abhängigkeiten**: PHP ab 8.2 und flache
  JSON-Dateien; Chart.js und die Schriften liegen im Repository.
- **Docker** (amd64 und arm64, mit PHP 8.4) oder jeder Webserver mit PHP —
  siehe [Installation](../betrieb/installation.md); Vorlagen für Synology,
  Unraid, CasaOS und Umbrel unter [Docker-Betrieb](../betrieb/docker.md).
- **Backup** als eine JSON-Datei (Format 3.0), vor dem Einspielen geprüft und
  als Vorschau gezeigt; automatische Snapshots vor jedem Import. Seit v3.1.0
  mit den Fotos.
- **CSV-Export** für Monatsübersicht, Zählerstände, Lieferungen, Zeiträume
  (v3.1.0) und Temperaturen — als Tabelle in der Standardsprache für Excel und LibreOffice
  oder im eingefrorenen Format 1 für Skripte.
- **Optionale Anmeldung** mit Passwort oder über einen vorgeschalteten Proxy,
  API-Schlüssel mit Lese- oder Verwaltungsrecht und eigene Schlüssel nur für
  das Kalender-Abo — siehe
  [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).
- **Benutzer im Haushalt** (v3.2.0): mit Anmeldung eigene Personen mit Name
  und Passwort oder vom Proxy gemeldet, Rollen Verwaltung und Mitglied,
  Stufe und Sprache je Person — siehe
  [Benutzer im Haushalt](../anleitungen/benutzer.md).
- **Diagnose** unter Einstellungen → System und `GET /api/health` für
  Docker-Healthcheck und Uptime-Monitore.
- **Offene REST-API** mit Stabilitätszusage — siehe
  [API-Referenz](../referenz/api.md).

---

[← Kompendium-Index](../README.md)
