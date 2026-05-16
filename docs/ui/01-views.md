# UI-Referenz — Alle Ansichten

[← Kompendium-Index](../README.md)

> **Hinweis:** Die folgenden Bilder sind **schematische SVG-Mockups**,
> keine echten Pixel-Screenshots. Sie bilden Layout, Anordnung und
> Farblogik (reale CSS-Tokens) korrekt ab, sind aber von Hand gezeichnet.
> Echte Screenshots lassen sich lokal nach Anleitung in der
> [Installation](../technical/01-installation.md) erstellen.

Die App ist eine Single-Page-Anwendung mit fester **Topbar** (Logo,
Theme-Toggle) und einer **Sidebar**, die dynamisch aus den *aktiven*
Verbrauchsarten gebaut wird (Einstellungen → Aktive Verbrauchsarten).

---

## 1. Übersicht (Dashboard)

Einstieg. 12-Monats-Kennzahlen je Art, Effizienzklasse **pro
Heizquelle**, Tank-Bestände (Öl/Pellets), kombinierter
Verbrauchsverlauf, fällige Termine.

![Dashboard](mockups/dashboard.svg)

---

## 2. Verbrauchsansicht — kumulative Arten (Gas/Strom/Wasser/Fernwärme)

Pro Art identischer Aufbau: Jahr-Auswahl, Zähler-Auswahl, KPI-Leiste
(Verbrauch, Kosten, Saldo heute, erwarteter Saldo), Monatstabelle mit
gleitenden Mitteln (MA-3/MA-6) und Wetterbereinigung, Vertrags-/
Saldo-Karte.

![Gas-Ansicht](mockups/gas-view.svg)

---

## 3. Verbrauchsansicht — lieferbasierte Arten (Heizöl/Pellets)

Statt Zählerständen: Tank-Bestandskurve (modelliert, kalibriert) und
Lieferungstabelle (Datum, Menge, Preis, Gesamt, Lieferant). Kein
Vertrags-Bereich — die Tankrechnung ist die Kostenbasis.

![Heizöl-Ansicht](mockups/heizoel-view.svg)

---

## 4. Analyse (Heizsignatur)

HGT-Korrelations-Streudiagramm mit Regressionsgerade, R²-Vergleich
**aller fünf** Modelle (linear, polynomial, robust, segmentiert,
sigmoid), Anomalien.

![Analyse](mockups/analyse.svg)

---

## 5. Prognose

Modellauswahl (alle fünf, seit v1.4.1 vollständig), 12-Monats-Prognose
als R²-gewichteter Blend aus Regression und Saisonprofil,
Kostenprognose mit Saldo offener Verträge.

![Prognose](mockups/prognose.svg)

---

## 6. Tarifvergleich

Echte **und** Schattenverträge auf den Ist-Verbrauch gerechnet —
„Was hätte Tarif X gekostet?", ohne Saldo/Prognose zu verändern.

![Tarifvergleich](mockups/tarifvergleich.svg)

---

## 7. Empfehlungen

Sieben statistische Regelfamilien (Mehrverbrauch-Trend, Sommer-Sockel,
Anomalie, Tank-Niveau, Vertragsende, Effizienz, …), nach Dringlichkeit
sortiert, einzeln ausblendbar. Rein datengetrieben, keine Werbung.

![Empfehlungen](mockups/empfehlungen.svg)

---

## 8. Termine & Wartung

Wiederkehrende Termine (Heizungswartung, Schornsteinfeger,
Eichfristen). Fällige/überfällige erscheinen auf dem Dashboard; beim
Erledigen wird der nächste Termin gemäß Recurrence fortgeschrieben.

![Termine](mockups/termine.svg)

---

## 9. Temperaturen

CSV-Import (Drag & Drop), Open-Meteo-Sync für den hinterlegten
Standort, Monatschart Min/Ø/Max. Grundlage jeder HGT-Auswertung.

![Temperaturen](mockups/temperaturen.svg)

---

## 10. Einstellungen

Alle 50 Schlüssel gruppiert: Umrechnung & HGT, **Abrechnungszyklus
(TT-MM, Fix v1.4.2)**, Gebäude & Effizienz, Heizwerte, Prognosemodell,
aktive Verbrauchsarten, **CSV-Export aller Arten (Fix v1.4.2)**,
Backup & Migration.

![Einstellungen](mockups/einstellungen.svg)

---

## 11. Zähler & Verträge

Zähler-/Geräteverwaltung inkl. Zählertausch (Device-Kette) und
Vertragspflege (Arbeits-/Grundpreis-Historie, Abschläge, Boni). Bei
Öl/Pellets ist hier nur die Tank-/Lagerverwaltung relevant.

![Zähler & Verträge](mockups/zaehler-vertraege.svg)

---

[← Kompendium-Index](../README.md)
