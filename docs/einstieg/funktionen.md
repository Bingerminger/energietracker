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
- **Heizöl und Pellets** über Lieferungen statt Zählerstände, mit
  **Tankbuch**: Anfangsbestand, „bis voll getankt“ und Peilstände sind
  Stützstellen; dazwischen ist der Verbrauch gerechnet, danach geschätzt und so
  gekennzeichnet.
- **CSV-Import** von Ablesungen mit Vorschau: Jede Zeile zeigt ihre Wirkung
  (neu, ersetzt, unverändert) und die Rückfragen der Erfassung, bevor etwas
  geschrieben wird.
- **Home Assistant** pusht Zählerstände automatisch (`POST /api/ingest`,
  idempotent, mit Token und Zähler-Alias) — siehe
  [Home Assistant anbinden](../anleitungen/home-assistant.md).
- **Temperaturen** automatisch von Open-Meteo für den eigenen Standort
  (Ortssuche, täglicher Abgleich, 30-jähriges Klimanormal) oder als CSV.

## Verträge, Abschläge, Abrechnung

- **Verträge** je Zähler mit Arbeitspreis, Grundpreis und Abschlag als
  Verlauf — tagesgenau: Ein Wechsel oder eine Preisänderung zur Monatsmitte
  gilt ab ihrem Tag. Ein Vertrag ohne Nachfolger läuft zu seinen letzten
  Preisen weiter, bis er gekündigt ist.
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
- **Rechnungsprüfung Gas**: rechnet die Versorgerrechnung Abschnitt für
  Abschnitt nach — m³ × Zustandszahl × Brennwert = kWh, geteilt an jeder
  Ablesung und jedem Brennwertwechsel. Wie man eine Rechnung einträgt:
  [Jahresabrechnung eintragen und prüfen](../anleitungen/jahresabrechnung.md).
- **Verträge & Abschläge** auf einer Seite: alle laufenden Verträge mit
  Kündigungsfrist, Abschlag und zu erwartender Abrechnung.
- **Wasser** mit drei Komponenten: Trinkwasser, Schmutzwasser (nach
  Trinkwasser oder eigenem Zähler) und Niederschlagswasser nach versiegelter
  Fläche.

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

## Verstehen und vorhersehen

- **Heizgradtage** vom eigenen Standort gegen die Heizgrenze (Standard
  15 °C), dazu ein **Heizmodell** mit Grundlast und die **Wetterbereinigung**:
  „mehr verbraucht oder nur kälter?“ — jeder Monat gegen seine Erwartung bei
  diesem Wetter.
- **Fünf Modelle** für Heizgradtage gegen Verbrauch: linear, polynomial,
  robust, segmentiert (mit Knickpunkt aus den Daten) und Sigmoid, mit
  R²-Vergleich.
- **Zäsur**: eine Sanierung am Zähler datieren — ab dort rechnet jede
  Auswertung neu; die Wirkung steht als Vorher/Nachher-Kennzahl je Gradtag,
  mit Aussage, ob sie statistisch belegt ist.
- **Prognose** über bis zu 24 Monate mit **Unsicherheitsband** (80 % der
  Jahre), Kosten je Monat aus dem dann gültigen Tarif, laufendem Saldo und
  Was-wäre-wenn (Temperaturversatz, Preisfaktor).
- **Anomalien** (Monate weit weg von ihrer Erwartung), **Jahresvergleich**
  Monat für Monat und **Wasser-Spar-Index** je Person.
- **Effizienz** in kWh/m²·a — Klassen A+ bis H nach GEG, nur für ganze Jahre —
  und daneben eine **energieausweis-nahe Kennzahl** (Heizwert,
  witterungsbereinigt, Gebäudenutzfläche).
- **CO₂** mit Quelle (BAFA, Strommix je Jahr nach Umweltbundesamt); bei PV als
  vermiedenes CO₂.
- **PV**: Einspeisung als Vergütung, Erzeugung, Eigenverbrauch,
  Autarkiequote und die Ersparnis durch den Eigenverbrauch — siehe
  [PV](../verstehen/12-pv.md).
- **Empfehlungen** aus den eigenen Daten (sieben Regelfamilien, einzeln
  ausblendbar) und **Termine** für Wartung, Schornsteinfeger und Eichfristen.
- **PDF-Jahresbericht** im Browser oder zum Herunterladen.

## Bedienen

- **Mac und iPhone**: Navigation in sieben Bereichen nach den Fragen der
  Nutzer; am iPhone eine Tab-Leiste mit „＋ Erfassen“, Dialoge als Blatt,
  44-px-Tippziele, Kontraste nach WCAG AA, installierbar als App — siehe
  [Auf dem Handy nutzen](handy.md).
- **Hilfe in der App**: erste Schritte mit Häkchen aus den Daten, ein Glossar
  mit 33 Begriffen und ein ⓘ an jeder Kennzahl.
- **Sieben Sprachen** (Deutsch, Englisch, Französisch, Italienisch, Spanisch,
  Portugiesisch, Niederländisch) und **Länderprofile** für neun Länder:
  Währung, Formate, Zeitzone, Wetterstandort, Heizgrenze, CO₂-Faktor,
  Gaseinheiten — siehe [Länderprofile](../verstehen/14-laenderprofile.md).
- **Hell, dunkel oder wie das System**.
- **Beispieldaten** zum Ausprobieren, ohne die eigenen zu verlieren (vorher
  sichert die App den jetzigen Stand).

## Betreiben

- **Keine Datenbank, keine Laufzeit-Abhängigkeiten**: PHP 8.4 und flache
  JSON-Dateien; Chart.js und die Schriften liegen im Repository.
- **Docker** (amd64 und arm64) oder jeder Webserver mit PHP — siehe
  [Installation](../betrieb/installation.md).
- **Backup** als eine JSON-Datei (Format 3.0), vor dem Einspielen geprüft und
  als Vorschau gezeigt; automatische Snapshots vor jedem Import.
- **CSV-Export** für Monatsübersicht, Zählerstände und Temperaturen.
- **Optionale Anmeldung** mit Passwort oder über einen vorgeschalteten Proxy,
  API-Schlüssel mit Lese- oder Verwaltungsrecht — siehe
  [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).
- **Diagnose** unter Einstellungen → System und `GET /api/health` für
  Docker-Healthcheck und Uptime-Monitore.
- **Offene REST-API** mit Stabilitätszusage — siehe
  [API-Referenz](../referenz/api.md).

---

[← Kompendium-Index](../README.md)
