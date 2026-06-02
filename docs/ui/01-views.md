# UI-Referenz — Alle Ansichten

[← Kompendium-Index](../README.md)

> **Echte Screenshots.** Die folgenden Bilder sind **tatsächliche
> Bildschirmaufnahmen** der laufenden App mit dem mitgelieferten
> [Demo-Datensatz](../../demo-data/) (Light-Theme, Stand v1.9.2). Wer sie
> selbst neu erzeugen will: Demo-Daten laden und die Views nacheinander
> aufnehmen — die App braucht dafür keinen Build-Schritt.

Die App ist eine Single-Page-Anwendung mit fester **Topbar** (Logo,
Theme-Toggle) und einer **Sidebar**, die dynamisch aus den *aktiven*
Verbrauchsarten gebaut wird (Einstellungen → Aktive Verbrauchsarten).

---

## 1. Übersicht (Dashboard)

Einstieg. 12-Monats-Kennzahlen je Art, Effizienzklasse **pro
Heizquelle**, Tank-Bestände (Öl/Pellets), **Strom-Saldo & Autarkie** bei
PV, kombinierter Verbrauchsverlauf und fällige Termine.

![Dashboard](screenshots/dashboard.png)

---

## 2. Zählerstand-Erfassung (F1004)

Zentrale, mobil-freundliche Eingabemaske: alle aktiven kumulativen Zähler
(Gas/Strom/Wasser/Fernwärme/PV) mit jeweils dem letzten Stand als
Orientierung — ideal fürs monatliche Ablesen am Handy.

![Zählerstände](screenshots/zaehlerstaende.png)

---

## 3. Verbrauchsansicht — kumulative Arten (Gas/Strom/Wasser/Fernwärme)

Pro Art identischer Aufbau: Jahr-Auswahl, Zähler-Auswahl, KPI-Leiste
(Verbrauch, Kosten, Saldo heute, erwarteter Saldo), Vertrags-/Saldo-Karte,
Verbrauchschart mit Temperaturüberlagerung sowie Monatstabelle mit
gleitenden Mitteln (MA-3/MA-6) und Wetterbereinigung.

![Gas-Ansicht](screenshots/gas-view.png)

---

## 4. Verbrauchsansicht — lieferbasierte Arten (Heizöl/Pellets)

Statt Zählerständen: Tank-Bestandskurve (modelliert, kalibriert) und
Lieferungstabelle (Datum, Menge, Preis, Gesamt, Lieferant). Kein
Vertrags-Bereich — die Tankrechnung ist die Kostenbasis.

![Heizöl-Ansicht](screenshots/heizoel-view.png)

---

## 5. Analyse (Heizsignatur)

HGT-Korrelations-Streudiagramm mit Regressionsgerade, R²-Vergleich
**aller fünf** Modelle (linear, polynomial, robust, segmentiert,
sigmoid), Anomalien.

![Analyse](screenshots/analyse.png)

---

## 6. Prognose

Modellauswahl (alle fünf), 12-Monats-Prognose als R²-gewichteter Blend
aus Regression und Saisonprofil, Kostenprognose mit Saldo offener
Verträge.

![Prognose](screenshots/prognose.png)

---

## 7. Tarifvergleich

Echte **und** Schattenverträge auf den Ist-Verbrauch gerechnet —
„Was hätte Tarif X gekostet?", ohne Saldo/Prognose zu verändern.

![Tarifvergleich](screenshots/tarifvergleich.png)

---

## 8. Empfehlungen

Sieben statistische Regelfamilien (Mehrverbrauch-Trend, Sommer-Sockel,
Anomalie, Tank-Niveau, Vertragsende, Effizienz, …), nach Dringlichkeit
sortiert, einzeln ausblendbar. Rein datengetrieben, keine Werbung.

![Empfehlungen](screenshots/empfehlungen.png)

---

## 9. Termine & Wartung

Wiederkehrende Termine (Heizungswartung, Schornsteinfeger,
Eichfristen). Fällige/überfällige erscheinen auf dem Dashboard; beim
Erledigen wird der nächste Termin gemäß Recurrence fortgeschrieben.

![Termine](screenshots/termine.png)

---

## 10. Temperaturen

CSV-Import (Drag & Drop), Open-Meteo-Sync für den hinterlegten
Standort, Monatschart Min/Ø/Max. Grundlage jeder HGT-Auswertung.

![Temperaturen](screenshots/temperaturen.png)

---

## 11. Einstellungen

Alle 40 Schlüssel gruppiert: Umrechnung & HGT, **Abrechnungszyklus
(TT-MM)**, Gebäude & Effizienz, Heizwerte, Prognosemodell, aktive
Verbrauchsarten, CSV-Export aller Arten, Backup & Migration,
Demo-Daten-Import (F1007) und die **🏠 Home-Assistant-Anbindung (F1009)**
— API-Token verwalten, Zähler-Aliase pflegen, fertiges HA-YAML kopieren.

![Einstellungen](screenshots/einstellungen.png)

---

## 12. Zähler & Verträge — inkl. Topologie (F1006)

Zähler-/Geräteverwaltung inkl. Zählertausch (Device-Kette) und
Vertragspflege (Arbeits-/Grundpreis-Historie, Abschläge, Boni). Bei
Öl/Pellets ist hier nur die Tank-/Lagerverwaltung relevant.

**Meter-Topologie:** Subzähler werden unter ihrem Elternzähler eingerückt
dargestellt, Gruppen als aufklappbarer Sammeleintrag; ein **Merge-Wizard**
führt mehrere bestehende Zähler zu einer Gruppe zusammen. Pro Zähler lässt
sich hier auch der HA-Alias (`external_id`) setzen.

![Zähler & Verträge](screenshots/zaehler-vertraege.png)

---

## 13. PV — Einspeisung & Erzeugung (F1005)

Eigene Ansicht für Photovoltaik: Einspeisezähler (Vergütung als Erlös),
Erzeugungszähler, **Strom-Saldo** (Netzbezug − Einspeisung) und
**Autarkiequote/Eigenverbrauch**. PV-Verbrauchsarten haben keinen
Default-Zähler — wer keine Anlage hat, sieht keine Phantom-Zähler.

![PV](screenshots/pv.png)

---

[← Kompendium-Index](../README.md)
