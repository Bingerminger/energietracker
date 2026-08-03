# UI-Referenz — Alle Ansichten

**Deutsch** · [English](../en/ui/01-views.md)

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

Beantwortet die Frage, um die es im Energietracker geht: **Soll ich wechseln?**
Die Ansicht ist in zwei Blöcke geteilt, und die Reihenfolge ist Absicht.

### Wechselentscheidung

Oben steht der **erwartete Jahresverbrauch** aus der Prognose — genau die Zahl,
die CHECK24, Verivox und andere Vergleichsportale als Eingabe verlangen. Sie
lässt sich mit einem Klick kopieren. Der Ablauf ist damit: Zahl mitnehmen,
draußen suchen, das gefundene Angebot als Schattenvertrag eintragen.

Eine Anbindung an Vergleichsportale gibt es bewusst nicht. Die Anwendung holt
keine Tarife von außen; der Nutzer trägt ein, was er gefunden hat.

Daneben steht der **Wechseltermin**. Er ergibt sich aus Vertragsende und
Kündigungsfrist; die Frist wird mit Restlaufzeit angezeigt und farblich
hervorgehoben, sobald es eng wird — sie ist das, was im Alltag verpasst wird.
Wer ein anderes Szenario durchrechnen will, setzt das Datum von Hand.

Die Rangliste zeigt je Angebot:

| Spalte | Bedeutung |
|---|---|
| **1. Jahr** | Kosten der ersten zwölf Monate, Neukundenbonus bereits abgezogen |
| **ab 2. Jahr** | die dauerhaften Kosten, ohne einmalige Boni |
| **Differenz** | gegen den fortgeschriebenen Bestandsvertrag |
| **Lohnt ab** | der Jahresverbrauch, ab dem das Angebot den Bestandsvertrag schlägt |

**Sortiert wird nach „ab 2. Jahr".** Ein Lockangebot, das nur im ersten Jahr
billig ist, gewinnt die Rangfolge damit nicht — die Jahr-1-Zahl steht trotzdem
daneben, um sie mit der Portalanzeige abzugleichen.

Die Spalte **Lohnt ab** ist die ehrlichste Antwort auf eine unsichere Prognose.
Statt eine Ersparnis auf den Euro genau zu behaupten, nennt sie die Menge, ab
der die Rangfolge kippt: Liegt sie weit vom erwarteten Verbrauch weg, trägt die
Entscheidung auch dann, wenn die Prognose danebenliegt. Ergänzend steht unter
den Jahreskosten eine Spanne für ±10 % Verbrauch.

Das Diagramm legt die Angebote als Kostenverlauf **über** den Bestandsvertrag.
Monatlich statt als Jahressumme, weil man erst daran sieht, wo die Differenz
herkommt — bei Gas entsteht sie fast vollständig im Winter. Monate jenseits der
**Preisgarantie** werden gestrichelt gezeichnet: Dort ist der Preis eine
Annahme, keine Zusage.

Gerechnet wird über zwölf Monate ab Wechseltermin, saisonal gewichtet. Ein
Wechsel zum 1. Juli deckt damit trotzdem einen vollen Winter ab; eine
Zwölftelrechnung würde hier danebenliegen.

### Rückblick auf echte Monate

Darunter, eingeklappt: dieselben Tarife auf den **tatsächlich gemessenen**
Verbrauch gelegt — „Was hätte Tarif X gekostet?". Das ist der Beleg. Wer sieht,
dass die Rechnung auf echten Daten aufgeht, glaubt auch der Prognose.

Jede Zeile bezieht sich auf **genau die Monate, die dieser Vertrag abdeckt**:
Verbrauch, Kosten und Differenz meinen denselben Zeitraum. Verträge mit
kürzerer Laufzeit tragen ihre Monatszahl als Marke und zusätzlich eine
Hochrechnung auf die volle Periode.

Die Spalte **ct/Einheit** trägt die Vollkosten je kWh bzw. m³ — Arbeitspreis,
Grundpreis und Boni zusammen. Sie ist die einzige Größe, die von der Laufzeit
unabhängig ist, und damit der Maßstab für die Rangfolge. Verglichen werden
reine Tarifkosten; Abschläge und Sonderzahlungen sind Zahlungsströme gegen den
Saldo und bleiben außen vor (sie stehen in der Verbrauchsansicht).

### Angebote pflegen

Ein Angebot wird mit den Feldern erfasst, die auf einem Portalergebnis
tatsächlich stehen: Arbeitspreis, Grundpreis, **Neukundenbonus als Betrag**
(nicht als Gutschriftsdatum — das kennt beim Anlegen niemand), Preisgarantie
und Kündigungsfrist. Als Startdatum ist der errechnete Wechseltermin
vorbelegt.

Angebote lassen sich anlegen, bearbeiten und löschen. In der Vertragsliste
tragen sie ein eigenes Kennzeichen, damit sie nicht mit einem laufenden
Vertrag verwechselt werden. Sie beeinflussen **weder Saldo noch Prognose noch
Vertragsstatus** — sie existieren nur für diesen Vergleich.

> **Wasser** bleibt ausgenommen: Das Drei-Komponenten-Modell (Trink-, Schmutz-
> und Niederschlagswasser) braucht eine eigene Rechnung. Heizöl und Pellets
> sind lieferbasiert — dort ist die Lieferrechnung die Kostenbasis.

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
Öl/Pellets ist hier nur die Tank-/Lagerverwaltung relevant — beim Anlegen
und Bearbeiten eines Tanks werden **Tank-Kapazität** und **Anfangsbestand**
erfasst (statt eines kumulativen Zählerstands).

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
