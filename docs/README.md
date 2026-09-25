# Energietracker — Kompendium

[English](en/README.md) · **Deutsch**

> Dokumentation zu Energietracker **v2.15.0**, geordnet nach dem, was du
> vorhast. Deutsch ist die maßgebliche Fassung, Englisch der vollständige
> Spiegel; die übrigen Oberflächensprachen haben die Hilfe in der App.
>
> **Neu hier?** → [Erste Schritte](einstieg/erste-schritte.md) — ein
> durchgehendes Beispiel von der Installation bis zur ersten Prognose.

Energietracker ist eine selbst betriebene Web-App für den Energie- und
Wasserverbrauch eines Haushalts: acht Verbrauchsarten, Verträge und
Abrechnung, Wetterbereinigung, Prognose und Wechselentscheidung — ohne
Datenbank, alles als JSON-Dateien auf dem eigenen Server. Nach außen spricht sie
nur mit Open-Meteo (Wetterdaten).

---

## 🚀 Einstieg — *loslegen*

| Dokument | Inhalt |
|---|---|
| [Erste Schritte](einstieg/erste-schritte.md) | Installation → erster Zähler → erste Ablesung → erster Vertrag → erste Prognose |
| [Was der Energietracker kann](einstieg/funktionen.md) | Alle Funktionen, nach Fragen geordnet |
| [Fragen und Antworten](einstieg/faq.md) | Die häufigsten Fragen, kurz beantwortet |
| [Auf dem Handy nutzen](einstieg/handy.md) | Im Heimnetz aufrufen, als App installieren, was offline geht |

## 🧭 Anleitungen — *eine Aufgabe erledigen*

| Dokument | Inhalt |
|---|---|
| [Jahresabrechnung eintragen und prüfen](anleitungen/jahresabrechnung.md) | Stände, Preise, Gasfaktoren und Guthaben einer Rechnung übernehmen und nachrechnen |
| [Home Assistant anbinden](anleitungen/home-assistant.md) | Zählerstände automatisch pushen: Token, Zähler-Alias, REST-Command |
| [Anwendungsfälle](anleitungen/anwendungsfaelle.md) | WG mit geteilten Zählern, Smart Home, PV-Haushalt, Vermieter |
| [Umstieg von v0.9.0](anleitungen/migration-v090.md) | Altes Backup übernehmen |

## 📚 Verstehen — *wie gerechnet wird*

| Dokument | Inhalt |
|---|---|
| [Grundlagen & Methodik](verstehen/00-overview.md) | Heizgradtage, Heizmodell, Regression, Prognose, Wetterbereinigung — die Formeln |
| [Gas](verstehen/01-gas.md) · [Strom](verstehen/02-strom.md) · [Wasser](verstehen/03-wasser.md) · [Fernwärme](verstehen/04-fernwaerme.md) | Die zählerbasierten Verbrauchsarten |
| [Heizöl](verstehen/05-heizoel.md) · [Holzpellets](verstehen/06-pellets.md) | Lieferbasiert, mit Tankbuch |
| [PV-Einspeisung & Erzeugung](verstehen/12-pv.md) | Vergütung, Eigenverbrauch, Autarkie |
| [Sonderzahlungen](verstehen/10-sonderzahlungen.md) | Rück- und Nachzahlung, Abschlagszahlung, Wirkung auf den Saldo |
| [Zählerstand-Erfassung](verstehen/11-zaehlerstaende.md) | Erfassung in einem Durchgang, Plausibilität, Direktsprung |
| [Meter-Topologie](verstehen/13-meter-topologie.md) | Subzähler und Zählergruppen |
| [Länderprofile](verstehen/14-laenderprofile.md) | Währung, Formate, Effizienzskala, CO₂-Faktoren, Gaseinheiten je Land |
| [Szenario Wohnung](verstehen/07-szenario-wohnung.md) · [Szenario Eigenheim](verstehen/08-szenario-eigenheim.md) | Durchgerechnete Beispiele |
| [Glossar & Formelsammlung](verstehen/09-glossar.md) | Alle Begriffe und Formeln kompakt |

## 📖 Referenz — *nachschlagen*

| Dokument | Inhalt |
|---|---|
| [Ansichten](referenz/ansichten.md) | Jede Ansicht erklärt, mit echten Screenshots |
| [Einstellungen](referenz/einstellungen.md) | Jeder Schlüssel mit Standardwert, Ort in der Oberfläche und Wirkung |
| [API-Referenz](referenz/api.md) | Alle Routen mit Statuscodes und Stabilitätszusage (von einem Test gegen den Code geprüft) |
| [API-Beispiele](referenz/api-beispiele.md) | Ausführliche Anfragen und Antworten der meistgenutzten Endpunkte |
| [Datenmodell](referenz/datenmodell.md) | JSON-Schemata, Speicherung, Schema-Migration |

## 🛠️ Betrieb — *installieren und am Laufen halten*

| Dokument | Inhalt |
|---|---|
| [Installation](betrieb/installation.md) | Voraussetzungen, Einrichtung, Update, Backup |
| [Docker](betrieb/docker.md) | Container-Quickstart, `docker compose`, Synology, Updates, Daten-Volume, Logs |
| [Webserver](betrieb/webserver.md) | Apache und nginx mit den geprüften Regeln |
| [Sicherheit & Netzbetrieb](betrieb/sicherheit.md) | Anmeldung, API-Schlüssel, Proxy, HTTPS, Checkliste vor der Freigabe |
| [Fehlersuche](betrieb/fehlersuche.md) | Symptom → Ursache → Lösung |

## 🧑‍💻 Entwicklung — *am Code arbeiten*

| Dokument | Inhalt |
|---|---|
| [Architektur](entwicklung/architektur.md) | Schichten, Services, Controller, Frontend |
| [Datenfluss & Algorithmen](entwicklung/datenfluss.md) | Vom Zählerstand zum Saldo, Prognose-Algorithmus |
| [Tests](entwicklung/tests.md) | PHPUnit, Shape- und Render-Tests, Gegenproben |
| [Release-Prozess](entwicklung/release-prozess.md) | Versionierung, CHANGELOG, Doku-Pflege, Lessons Learned |
| [Screenshots](entwicklung/screenshots.md) | Wie die Bilder dieser Doku entstehen |

Mitwirken: [CONTRIBUTING.de.md](../CONTRIBUTING.de.md) ·
Sicherheitslücken: [SECURITY.md](../SECURITY.md) ·
Versionshistorie: [CHANGELOG.md](../CHANGELOG.md) ·
Planung: [roadmap.md](../roadmap.md)

---

## Konventionen in dieser Doku

- Begriffe der Oberfläche stehen so da, wie die App sie zeigt; Menüpfade als
  „Einstellungen → Wetterdaten“.
- Formeln stehen als Klartext-Codeblock und sind gegen den Quellcode geprüft,
  nicht aus dem Gedächtnis notiert.
- **[Unverifiziert]** markiert Annahmen und Standardwerte ohne belastbare
  Primärquelle, die in den Einstellungen angepasst werden sollten.
- Pfadangaben sind relativ zum Projektverzeichnis. Seit v2.14.0 ist die Doku
  nach Zielgruppen geordnet; die alten Pfade (`technical/`, `functional/`,
  `ui/` …) leiten auf die neuen Orte weiter. Die Dateinamen sind in beiden
  Sprachen gleich — eine Seite und ihr Spiegel liegen am selben relativen Ort.
- Ein Test prüft jeden Link und Anker, den englischen Spiegel jeder Seite und
  dass dieser Index jede Seite führt.
