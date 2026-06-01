# Energietracker — Kompendium

> Vollständige Dokumentation zu Energietracker **v1.9.0**.
> Getrennt in einen **technischen** und einen **fachlichen** Teil, plus
> eine **UI-Referenz** mit Mockups aller Ansichten.

Energietracker ist eine lokal betriebene, abhängigkeitsfreie Web-App zur
Erfassung und Analyse des häuslichen Energieverbrauchs über bis zu acht
Verbrauchsarten — **Gas, Strom, Wasser, Fernwärme, Heizöl, Holzpellets,
PV-Einspeisung, PV-Erzeugung**.
Kein externer Dienst, keine Datenbank: alles liegt als flache JSON-Datei
auf dem eigenen Rechner.

---

## Wegweiser

### 🔧 Technischer Teil — *für Betrieb & Weiterentwicklung*

| Dokument | Inhalt |
|---|---|
| [Installation & Betrieb](technical/01-installation.md) | Voraussetzungen, Einrichtung, Webserver, Update, Backup |
| [Architektur](technical/02-architecture.md) | Schichtenmodell, Services, Controller, Datenfluss |
| [API-Referenz](technical/03-api-reference.md) | Alle 53 Endpunkte mit Beispielen |
| [Datenmodell](technical/04-data-model.md) | JSON-Schemata, Speicherung, Schema-Migration |
| [Tests](technical/05-testing.md) | Backend-Shape- und Browser-Render-Harness |
| [Release-Prozess](technical/06-release-process.md) | Versionierung, CHANGELOG, Doku-Pflege |
| [Docker-Betrieb](technical/07-docker.md) | Container-Quickstart für Einsteiger, `docker compose`, Updates, Daten-Volume, Logs |
| [Home-Assistant-Anbindung (F1009)](HOME-ASSISTANT.md) | Zählerstände automatisch aus HA pushen: Token, Zähler-Alias, REST-Command, Use-Cases Eigenheim & Wohnung |

### 📚 Fachlicher Teil — *für Verständnis & Anwendung*

| Dokument | Inhalt |
|---|---|
| [Grundlagen & Methodik](functional/00-overview.md) | HGT, Regression, Prognose, Wetterbereinigung — die Formeln |
| [Gas](functional/01-gas.md) | Brennwert, m³→kWh, Heizsignatur |
| [Strom](functional/02-strom.md) | Grundlast, Saisonprofil, kein HGT |
| [Wasser](functional/03-wasser.md) | m³, Drei-Komponenten-Tarif, Spar-Index |
| [Fernwärme](functional/04-fernwaerme.md) | Kumulativ, HGT-relevant, Grundpreis |
| [Heizöl](functional/05-heizoel.md) | Lieferbasiert, Tankmodell, Hu |
| [Holzpellets](functional/06-pellets.md) | Lieferbasiert, kg statt Liter |
| [Szenario: Wohnungsnutzer](functional/07-szenario-wohnung.md) | Best Practices Mietwohnung |
| [Szenario: Eigenheimbesitzer](functional/08-szenario-eigenheim.md) | Best Practices Eigenheim |
| [Glossar & Formelsammlung](functional/09-glossar.md) | Alle Begriffe und Formeln kompakt |
| [Sonderzahlungen (F1003)](functional/10-sonderzahlungen.md) | Rück-/Nachzahlung, Abschlagszahlung, Saldo-Wirkung |
| [Zählerstand-Erfassung (F1004)](functional/11-zaehlerstaende.md) | Zentrale mobile Ablesungs-Ansicht (Gas/Strom/Wasser/Fernwärme) |
| [PV-Einspeisung & Erzeugung (F1005)](functional/12-pv.md) | Einspeisezähler, Erzeugungszähler, Strom-Saldo, Autarkiequote |

### 🖥️ UI-Referenz

| Dokument | Inhalt |
|---|---|
| [Alle Ansichten](ui/01-views.md) | Jede der 11 Views erklärt, mit Mockup |

---

## Schnelleinstieg nach Rolle

- **„Ich will es nur installieren und benutzen."**
  → [Installation](technical/01-installation.md) →
  [Szenario Wohnung](functional/07-szenario-wohnung.md) *oder*
  [Szenario Eigenheim](functional/08-szenario-eigenheim.md)

- **„Ich will verstehen, wie die Prognose rechnet."**
  → [Grundlagen & Methodik](functional/00-overview.md)

- **„Ich heize mit Öl/Pellets."**
  → [Heizöl](functional/05-heizoel.md) bzw.
  [Holzpellets](functional/06-pellets.md)

- **„Ich will an der Software arbeiten."**
  → [Architektur](technical/02-architecture.md) →
  [Datenmodell](technical/04-data-model.md) →
  [Tests](technical/05-testing.md)

---

## Konventionen in dieser Doku

- **[Unverifiziert]** markiert Annahmen oder Default-Werte, die nicht aus
  einer belastbaren Primärquelle stammen und in den Einstellungen
  angepasst werden sollten (z. B. CO₂-Faktoren).
- Formeln stehen in LaTeX-ähnlicher Notation; alle hier dokumentierten
  Formeln sind **gegen den realen Quellcode geprüft**, nicht aus dem
  Gedächtnis notiert.
- Pfadangaben sind relativ zum Projektwurzelverzeichnis.

Versionsstand dieses Kompendiums: **v1.9.0** (2026-06-01). Die Doku wird
ab v1.4.2 bei **jedem** Release synchron mitgeführt — siehe
[Release-Prozess](technical/06-release-process.md).
