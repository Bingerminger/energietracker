# Szenario: Eigenheimbesitzer

**Deutsch** · [English](../en/verstehen/08-szenario-eigenheim.md)

[← Szenario Wohnung](07-szenario-wohnung.md) · [Kompendium-Index](../README.md)

Typische Ausgangslage: Einfamilienhaus, **eine** Heizquelle
(Gas / Fernwärme / Wärmepumpe-Strom / Heizöl / Pellets), eigener
**Strom**, eigener **Wasser**zähler, ggf. **Tank** für Öl/Pellets,
zunehmend auch **Photovoltaik** auf dem Dach. Hier entfaltet
Energietracker den vollen Funktionsumfang.

---

## 1. Empfohlene Einrichtung

1. **Wohnfläche und Gebäudetyp** in den Einstellungen pflegen —
   Grundlage der **Effizienzklasse**.
2. **Eine** Heizquelle als aktiv führen (siehe §3). Strom + Wasser
   zusätzlich.
3. **Temperaturen** aktivieren: Standortkoordinaten setzen
   (Default Leipzig) — den Abgleich mit Open-Meteo macht die App dann
   selbst, einmal am Tag — oder CSVs importieren. Ohne Temperaturreihe
   keine HGT-Analyse/Wetterbereinigung.
4. **Regelmäßig erfassen**: kumulative Zähler monatlich ablesen;
   Öl/Pellet-Lieferungen direkt nach Erhalt eintragen (Menge +
   Rechnungsbetrag).

---

## 2. Den vollen Analysezyklus nutzen

```text
Ablesungen/Lieferungen --> Monatsverbrauch --> HGT-Regression
        |                                            |
        v                                            v
   Wetterbereinigung <----------------------- Saisonprofil
        |                                            |
        +--------------> Prognose (R2-Blend) <-------+
                                |
                                v
                  Effizienzklasse / Empfehlungen
```

Konkret:

- **Heizsignatur** (Analyse): Wie stark hängt dein Verbrauch von der
  Kälte ab? Hohes `R²` = stark heizgetrieben. Die `sigmoid`-Kurve
  zeigt die Sättigung bei sehr kalten Tagen.
- **Wetterbereinigung**: Trennt „kalter Winter" von echtem
  Mehrverbrauch — die Kernfrage nach einer Sanierung („bringt die neue
  Dämmung wirklich etwas, wetterbereinigt?").
- **Effizienzklasse pro Quelle** (seit v1.4.0): Eine ehrliche
  kWh/m²·a-Einordnung deiner Hauptheizung.
- **Empfehlungen**: sieben statistische Regeln (Mehrverbrauch-Trend,
  Sommer-Sockel, Anomalie, Tank-Niveau, Vertragsende, Effizienz …) —
  rein aus den Eigendaten, keine Werbung.

---

## 3. Genau EINE Heizquelle aktiv führen

Ein Haus heizt real mit einer Quelle. Sind mehrere Heizarten gleichzeitig
aktiv, wäre die *kombinierte* Effizienzklasse unsinnig (Summe mehrerer
Vollheizungen auf dieselbe Fläche). Deshalb:

- Führe deine reale Heizquelle aktiv (z. B. nur `gas`).
- Die anderen Heizarten **inaktiv** lassen (Daten bleiben erhalten,
  nur aus Sidebar/Dashboard ausgeblendet).
- **Ausnahme** — bewusst kombinierter Betrieb (z. B. Pellet-Grundlast
  + Gas-Spitzenlast): mehrere aktiv lassen und die `combined`-Sicht der
  Effizienz nutzen; `per_source` zeigt zusätzlich jede Quelle einzeln.

---

## 4. Öl-/Pellet-Haushalte: Tank im Blick

- Trage **jede Lieferung** mit Menge und **Rechnungsbetrag** ein
  (`total_eur` hat seit v1.4.2 Vorrang — enthält Liefergebühr/Rabatt).
- Die **Bestandskurve** zeigt einen realistischen Sägezahn und warnt über
  `tank_warn_pct` rechtzeitig vor Leerstand → rechtzeitig nachbestellen
  (idealerweise im Sommer, wenn Öl/Pellets günstiger sind).
- Markiere eine Lieferung als **„bis voll getankt"** oder erfasse ab und zu
  einen **Peilstand** (seit v2.10.0): Dann ist der Verbrauch bis zu diesem
  Tag gerechnet statt geschätzt, und spätere Lieferungen ändern die Vorjahre
  nicht mehr.
- Plane die nächste Lieferung als `is_planned` vor — sie erscheint,
  verfälscht aber Bilanz/Bestand nicht.

Details und Formeln: [Heizöl](05-heizoel.md) / [Pellets](06-pellets.md).

---

## 5. Vor/Nach einer Sanierung messen

Die wertvollste Anwendung — und seit **v2.4.0 (F1011)** rechnet der
Energietracker sie selbst aus, statt sie nur zu beschreiben.

**Das Problem.** Nach einer Dämmung ist das Haus thermisch ein anderes
Gebäude: Es braucht dauerhaft weniger je Kältegrad. Eine Regression über
beide Zustände beschreibt keinen von beiden — sie liefert einen gewichteten
Mittelwert, und zwar so lange, bis die neuen Monate die alten zahlenmäßig
überwiegen. Bei zwölf Jahren Historie dauert das Jahre.

**Vorgehen:**

1. Mindestens **eine volle Heizperiode vor** der Maßnahme sauber erfassen
   (für eine belastbare Heizkurve).
2. Maßnahme durchführen (Dämmung, Fenster, Heizungstausch, hydraulischer
   Abgleich).
3. Im Zähler unter *Analyse-Zäsuren* das Datum eintragen, mit einer
   Bezeichnung wie „Dachdämmung". Ein künftiges Datum darf vorgemerkt
   werden — es wirkt erst, wenn es erreicht ist.

Danach rechnen **alle** Auswertungen ab diesem Zeitpunkt: die Regressionen
im Analyse-Chart, der Erwartungswert je Monat, die Prognose (Heizkurve und
Saisonmittel), die Anomalie-Erkennung, die Empfehlungen — und über die
Prognose auch der Tarifvergleich. Die Monate davor bleiben im Chart
sichtbar, nur ausgegraut: Ausgeschlossen wird aus dem **Modell**, nicht aus
der Anzeige.

**Was dabei herauskommt.** Der Analyse-Bereich weist die Heizkurve beider
Epochen aus:

```text
vorher    0,42 m³ je Gradtag   (63 Messpunkte)
nachher   0,28 m³ je Gradtag   (41 Messpunkte)
Veränderung  −33 %             witterungsbereinigt
```

Der Verbrauch **je Gradtag** ist bereits die Wetterbereinigung: Er sagt, wie
viel das Gebäude pro Kältegrad braucht — unabhängig davon, wie kalt der
Winter war. Eine reine kWh-Differenz Jahr/Jahr wäre irreführend, sobald sich
die Winter unterscheiden.

> **Wenn nach der Zäsur noch zu wenig Historie vorliegt**, sagt die
> Oberfläche das im Klartext („Die Regression braucht mindestens 8
> Messpunkte ab der Zäsur — vorhanden sind 5"), statt eine Auswertung
> wortlos ausfallen zu lassen. Der Vergleich erscheint erst, wenn **beide**
> Epochen dick genug besetzt sind.

Mehrere Maßnahmen über die Zeit sind möglich: Es wirkt jeweils die späteste
Zäsur, deren Datum erreicht ist.

---

## 6. Photovoltaik — Einrichtung und Lesart

Seit v1.7.0 gehören zwei PV-Verbrauchsarten zum Standard-Eigenheim-Setup:
`pv_einspeisung` (Zähler des Verteilnetzbetreibers) und `pv_erzeugung`
(Wechselrichter-Gesamtertrag). Sie sind in den Default-Einstellungen
NICHT aktiv — wer keine Anlage hat, sieht nichts davon. Wer eine
Anlage hat, aktiviert beide in *Einstellungen → Verbrauchsarten &
Abrechnung → Aktive Verbrauchsarten*.

### 6.1 Welche Zähler brauche ich?

| Du hast | Empfohlene Einrichtung |
|---|---|
| Nur Einspeisezähler (Standard-Inbetriebnahme bis ~2022) | Nur `pv_einspeisung` aktivieren. Strom-Saldo wird berechnet, Eigenverbrauch/Autarkiequote bleiben null (`has_generation_meter: false`). |
| Einspeisezähler + Erzeugungszähler am Wechselrichter | Beide aktivieren. Volle Sicht: Strom-Saldo, Eigenverbrauchsquote, Autarkiequote. |
| Anlage mit Speicher | Beide aktivieren wie oben; die App zeigt die *effektive* Autarkiequote (Speicher erhöht den Eigenverbrauch automatisch in den Zahlen). Seit v3.1.0 zusätzlich je ein Zähler für Ladung und Entladung unter PV-Erzeugung (Rollen „Speicher – Ladung“, „Speicher – Entladung“) — dann zeigt die Karte „Speicher“ Verluste, Wirkungsgrad und Vollzyklen ([PV §7](12-pv.md#7-speicher-v310)). |
| Mehrere PV-Stränge (z. B. Süd- und Ost-Dach mit getrennten Wechselrichtern) | Pro Strang einen `pv_erzeugung`-Zähler anlegen — die App summiert sie für das Dashboard automatisch. |

### 6.2 Vertrag = Einspeisevergütung

Ein PV-Vertrag in der App trägt **nur** die EEG-Einspeisevergütung in
ct/kWh, mit Gültigkeitsdatum ab Inbetriebnahme. Kein Grundpreis, kein
Abschlagsplan, keine Sonderzahlungen — das ist bewusst so, weil der
Verteilnetzbetreiber nach tatsächlicher Erzeugung zahlt, nicht nach
einem Plan.

Typische Sätze (deutsches EEG, 20 Jahre fest ab Inbetriebnahme):

| Inbetriebnahme | Anlagen ≤ 10 kWp | ≤ 40 kWp |
|---|---|---|
| 2022 | 6,2 ct/kWh | 5,2 ct/kWh |
| 2023 | 8,2 ct/kWh | 7,1 ct/kWh |
| 2024 | 8,1 ct/kWh | 7,0 ct/kWh |
| 2025 | 7,9 ct/kWh | 6,9 ct/kWh |

[Unverifiziert] — Quelle: § 48 EEG. Bei Inbetriebnahme prüfen, da
gesetzliche Anpassungen halbjährlich erfolgen.

### 6.3 Strom-Saldo — die wichtigste Kennzahl

Im Hauptdashboard erscheint die Kachel **„⚡ Strom-Saldo {Jahr}"**:

```
Bezug (Netz)           − Einspeisung (PV)        = Strom-Saldo netto
1.838 € (4.700 kWh)    −   545 € (6.650 kWh)     =   1.293 € Kosten
```

Vorzeichen-Konvention:

- **Saldo > 0** → du zahlst unterm Strich noch (Bezugskosten höher als
  PV-Vergütung). Der Normalfall bei einem Haushalt ohne Speicher.
- **Saldo < 0** → die PV bringt mehr ein, als der Bezug kostet. Das
  passiert bei großen Anlagen, kleinem Bezug (z. B. langes Reisen,
  Klein-Haushalt mit überdimensionierter PV) oder bei alten EEG-Sätzen.

### 6.4 Autarkie- und Eigenverbrauchsquote

Mit einem Erzeugungszähler werden zusätzlich angezeigt:

```
eigenverbrauch_kwh   = pv_erzeugung_kwh − pv_einspeisung_kwh
eigenverbrauchsquote = eigenverbrauch / pv_erzeugung
autarkiequote        = eigenverbrauch / (eigenverbrauch + bezug)
```

| Anlage | EV-Quote | Autarkiequote |
|---|---|---|
| 10 kWp, kein Speicher | ~30 % | ~35–45 % |
| 10 kWp, 8 kWh Speicher | ~55 % | ~65–75 % |
| 10 kWp + Speicher + Wallbox + Wärmepumpe | ~70 % | ~50–60 % (mehr Bedarf!) |

Die **Autarkiequote** ist die ehrlichere Kennzahl für den Stolz-Effekt
(„wie unabhängig bin ich"), die **Eigenverbrauchsquote** für die
Wirtschaftlichkeit („was bleibt von meiner Erzeugung im Haus").

### 6.5 CO₂ als „vermieden"

Die App zeigt für `pv_einspeisung` und seit v2.10.0 auch für
`pv_erzeugung` den CO₂-Wert mit dem Wort „vermieden“ (seit v2.13.0 ohne
Minus) und einem ⓘ mit Methoden-Hinweis. Die Rechnung ist
`kWh × co2_strom`-Faktor des Jahres (Umweltbundesamt, Strommix; 2025:
344 g/kWh). Sie berücksichtigt **nicht** den
PV-Lebenszyklus (Herstellung, Transport, Recycling), liegt damit aber
auf derselben methodischen Ebene wie der CO₂-Faktor für den Bezug — die
Zahlen sind also direkt vergleichbar. Seit v3.1.0 lässt sich stattdessen ein
eigener Vermeidungsfaktor eintragen („CO₂ vermieden durch PV“,
[PV §5](12-pv.md#5-co₂-als-vermieden)).

### 6.6 Erfassungs-Disziplin

- Ablesungen am **selben Datum** wie der Bezugs-Strom-Zähler — sonst
  laufen die Monats-Aggregate auseinander und der Saldo schwankt
  künstlich.
- Bei Anlagen mit Speicher: den Ladezustand (SoC) erfasst die App nicht.
  Geladene und entladene kWh laufen seit v3.1.0 als eigene Zähler mit; sie
  zählen nicht zur Erzeugung.
- Bei reduzierter Direktvermarktung nach den 20 EEG-Jahren: einen neuen
  Vertrag mit aktuellem Sonstige-Direktvermarktung-Satz anlegen, das
  Vertragsende des alten EEG-Vertrags setzen — die App rechnet den
  Übergang stichtagsgenau. Schwankt der Erlös mit dem Marktwert, trägst du seit
  v3.1.0 die Gutschriften des Direktvermarkters ein ([PV §11](12-pv.md#11-gutschriften-des-direktvermarkters-v310)).
- Mit Investition und Inbetriebnahme am Erzeugungszähler zeigt die App, wann
  sich die Anlage bezahlt gemacht hat ([PV §9](12-pv.md#9-amortisation-v310)).

Vollständige technische Referenz: [PV-Detailkonzept](12-pv.md).

---

## 6a. Wärmepumpe *(v3.1.0)*

Eine Wärmepumpe heizt mit Strom. Damit Heizstrom und Haushaltsstrom getrennt
bleiben und die App sagen kann, wie gut die Anlage arbeitet, braucht es bis zu
drei Zähler:

| Zähler | Verbrauchsart, Rolle | Wofür |
|---|---|---|
| Hausanschluss | Strom, „Haushalt“ | Bezug und Kosten des ganzen Hauses |
| Wärmepumpe | Strom, „Wärmepumpe (Heizstrom)“ — als Subzähler des Hausanschlusses, wenn er dahinter sitzt | Heizstrom; zählt in der Effizienzkennzahl als Heizenergie |
| Wärmemenge | Heizwärme, „Wärmemenge der Wärmepumpe“, verknüpft unter „Stromzähler der Wärmepumpe“ | die gelieferte Wärme — für die Jahresarbeitszahl |

**Jahresarbeitszahl.** Mit Wärmemengenzähler zeigen die Ansichten beider Zähler
die Karte **„Wärmepumpe {Jahr}“**: Jahresarbeitszahl (Wärme ÷ Strom), die
Arbeitszahl der Heizperiode (Oktober bis April) und je Monat Wärme, Strom und
Arbeitszahl. Im Feldtest „WP-QS im Bestand“ des Fraunhofer ISE (2025) lagen
Luft/Wasser-Wärmepumpen im Mittel bei 3,4, Sole/Wasser bei 4,3.

> **Beispiel (erfunden).** 9.000 kWh Wärme aus 2.500 kWh Strom: JAZ 3,6.

Gezählt werden nur Monate, in denen beide Zähler Werte haben. Ob Heizstab,
Warmwasser und Pumpen in der Zahl stecken, hängt davon ab, wo die Zähler
sitzen. Die Wärmemenge zählt weder in der Effizienzkennzahl noch in den Summen
der Heizwärme — die Wärmepumpe steht dort schon mit ihrem Strom. Hintergrund:
[Heizwärme §7](15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310).

**Netzentgelt.** Ist die Wärmepumpe nach § 14a EnWG steuerbar, sinkt das
Netzentgelt: mit Modul 1 um einen festen Betrag im Jahr (im Stromvertrag
„Reduziertes Netzentgelt“), mit Modul 2 über einen eigenen Zähler mit eigenem
Vertrag ([Strom](02-strom.md#steuerbare-verbraucher-v310)).

**PV und Wärmepumpe zusammen** — durchgerechnet in
[Anwendungsfall C](../anleitungen/anwendungsfaelle.md#c--pv-haushalt-mit-wärmepumpe).

---

## 7. Termine & Wartung

Lege wiederkehrende Termine an (Heizungswartung, Schornsteinfeger,
Zähler-Eichfrist, PV-Anlagen-Inspektion alle 4 Jahre). Fällige/
überfällige Termine erscheinen auf dem Dashboard; beim Erledigen wird
der nächste Termin gemäß Recurrence fortgeschrieben.

---

## Weiterführend

- **Wärmepumpe oder Wallbox getrennt erfassen?** Lege sie als **Subzähler**
  hinter dem Hausanschluss an: [Meter-Topologie](13-meter-topologie.md). Für
  den Dienstwagen gibt es den
  [Ladestrom-Nachweis](../anleitungen/ladestrom-nachweis.md).
- **Werte aus dem Portal** von Wechselrichter, Wärmepumpe oder Netzbetreiber
  übernehmen: [Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md).
- **Zähler automatisch aus Home Assistant füttern:**
  [Home-Assistant-Anbindung](../anleitungen/home-assistant.md).
- **Komplett durchgerechnete Beispiele** (PV + Wärmepumpe, Vermieter mit
  mehreren Einheiten): [Anwendungsbeispiele & Use-Cases](../anleitungen/anwendungsfaelle.md).

---

[← Szenario Wohnung](07-szenario-wohnung.md) · [Glossar →](09-glossar.md)
