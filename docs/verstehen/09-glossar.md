# Glossar & Formelsammlung

**Deutsch** · [English](../en/verstehen/09-glossar.md)

[← Szenario Eigenheim](08-szenario-eigenheim.md) · [Kompendium-Index](../README.md)

Kompakte Referenz aller Begriffe und Formeln. Ausführliche Herleitung
in [Grundlagen & Methodik](00-overview.md). Wie Arbeitspreis, Grundpreis,
Abschlag, Zustandszahl, Brennwert und Saldo auf der Rechnung anderer Länder
heißen, steht unter
[Länderprofile §8](14-laenderprofile.md#8-so-heißt-das-auf-deiner-rechnung);
die App zeigt es seit v3.1.0 in der Hilfe und an jedem ⓘ.

> Formeln stehen als Klartext-Codeblöcke, damit sie überall (GitHub,
> Editor, Viewer) identisch und korrekt dargestellt werden.

---

## Begriffe

| Begriff | Bedeutung |
|---|---|
| **Kumulativ** | Erfassung über fortlaufende Zählerstände (Gas, Strom, Wasser, Fernwärme, PV, seit v3.1.0 Heizwärme). |
| **Lieferbasiert** | Erfassung über Brennstofflieferungen statt Zähler (Heizöl, Pellets). |
| **Verbrauch je Zeitraum** | v3.1.0: Eine Erfassungsart neben Zählerständen: Je Zeitraum steht der Verbrauch selbst, etwa aus der monatlichen Verbrauchsinfo. Die App verteilt ihn tagesgenau auf die Monate; danach rechnet alles wie bei Zählerständen. Am Zähler als „Erfassung“ gewählt ([Heizwärme](15-waerme.md)). |
| **Verbrauchsinfo (UVI)** | v3.1.0: Seit 2022 müssen Mieter mit fernablesbaren Zählern jeden Monat ihren Verbrauch an Heizung und Warmwasser erfahren, mit Vormonat, Vorjahresmonat und Durchschnittsnutzer (HeizkostenV § 6a). Die Werte lassen sich als Verbrauch je Zeitraum eintragen. |
| **Heizwärme** | v3.1.0: Verbrauchsart für die Wärme, die in der Wohnung ankommt (Wärmezähler oder Verbrauchsinfo), nicht für den Brennstoff. In kWh, ohne Versorgerverträge ([Heizwärme](15-waerme.md)). |
| **Zählerrolle** | v3.1.0: Was ein Zähler innerhalb seiner Verbrauchsart misst — etwa Warmwasser statt Kaltwasser, Wärmepumpe statt Haushalt (`role`). Fehlt sie, gilt die erste Rolle der Art. |
| **Vorauszahlung (Nebenkosten)** | v3.1.0: Was ein Mieter jeden Monat für Heizung und Betriebskosten an den Vermieter zahlt. Die Nebenkostenabrechnung stellt sie den tatsächlichen Kosten gegenüber. |
| **Nebenkostenabrechnung** | v3.1.0: Die jährliche Abrechnung des Vermieters. In Deutschland muss sie spätestens zwölf Monate nach Ende des Abrechnungszeitraums kommen; Einwände sind bis zwölf Monate nach Zugang möglich (BGB § 556). Beide Fristen stehen im Kalender ([Als Mieter](../anleitungen/mieter.md)). |
| **Warmwasser-Wärme** | v3.1.0: Die Wärme, die ein Warmwasserzähler verbraucht, gerechnet nach HeizkostenV § 9: 2,5 kWh je m³ und Grad über 10 °C. 1 m³ bei 60 °C sind 125 kWh. Ein Rechenwert, kein Messwert. |
| **HGT (Heizgradtage)** | Maß für „Heizbedarf wegen Kälte" pro Tag/Monat. |
| **Heizgrenztemperatur** | Außentemperatur, ab der geheizt wird (`hdd_base_temp`, Default 15 °C). |
| **Heizsignatur** | Regressionszusammenhang HGT → Verbrauch. |
| **Wetterbereinigung** | Verbrauch auf ein Normaljahr umgerechnet: Seit v2.8.0 wird nur der Wettereinfluss laut Heizmodell umgerechnet, Grundlast und eigene Abweichung des Monats bleiben (`heat_adjusted`). |
| **Heizmodell** | v2.8.0: `Verbrauch = a × HGT + c × Tage` je Zähler — `a` Verbrauch je Gradtag, `c` Grundlast je Tag. Liefert die Erwartung für jeden Monat (`expected_heat`), auch im Sommer. |
| **Klimanormal** | v2.8.0: Mittel und Streuung der Heizgradtage je Kalendermonat aus 30 Jahren Tagesmitteln am Standort (Open-Meteo-Archiv). Grundlage für Prognose, Bereinigung und Unsicherheitsband. |
| **Unsicherheitsband** | v2.8.0: Bereich, in dem der Verbrauch in 80 % der Jahre liegt (`confidence_band_sigma`) — aus der Streuung der Winter und dem Rauschen des Modells. |
| **R²** | Bestimmtheitsmaß: Anteil erklärter Streuung (0…1). |
| **Saisonprofil** | Monatsmittel des Verbrauchs über die Historie. |
| **Blend** | R²-gewichtete Mischung Regression × Saisonprofil in der Prognose. |
| **Saldo** | Tatsächliche Kosten − geleistete Abschläge (dazu das Netto der Sonderzahlungen). **Positiv = Nachzahlung droht, negativ = Guthaben** — so rechnen API und CSV-Export. Die Oberfläche zeigt seit v2.13.0 die Kundensicht: „Guthaben“ bzw. „Nachzahlung“ ohne Vorzeichen, in Tabellen + = Guthaben. |
| **Schattenvertrag** | Ein Tarif, den man nicht hat: entweder ein Angebot vom Vergleichsportal (für die Wechselentscheidung) oder eine Hypothese über die Vergangenheit („Was hätte das gekostet?"). Wirkt **nur** im Tarifvergleich — nie auf Saldo, Prognose oder Vertragsstatus. |
| **Kündigungsstichtag** | Letzter Tag, an dem die Kündigung beim Anbieter sein muss (`cancel_by`). Seit v2.9.0 zählen die Erinnerungsstufen bis zu diesem Tag, nicht bis zum Vertragsende. |
| **Kündigungsweise** | v2.9.0 (`notice_mode`): zum Vertragsende, jederzeit zum Monatsende oder jederzeit zu jedem Tag (etwa die Grundversorgung mit zwei Wochen). |
| **Weiterlaufender Vertrag** | v2.9.0: abgelaufen, ohne Nachfolger und nicht gekündigt — läuft zu den letzten Preisen weiter (`renewed`, Monatszeilen `contract_assumed`) und ist jederzeit mit höchstens einem Monat Frist kündbar. |
| **Wechseltermin** | v2.3.0: Der erste Tag, an dem ein neuer Tarif liefern könnte. Ergibt sich aus Vertragsende und Kündigungsfrist; davon zu unterscheiden ist der **Kündigungsstichtag**, bis zu dem die Kündigung raus muss. |
| **Break-even-Verbrauch** | v2.3.0: Die Jahresmenge, ab der ein Angebot den laufenden Vertrag schlägt (Spalte „Lohnt ab"). Liegt sie weit vom erwarteten Verbrauch weg, trägt die Wechselentscheidung auch bei ungenauer Prognose. |
| **Neukundenbonus** | v2.3.0: Einmalbetrag am Angebot (`signup_bonus_eur`), der nur im ersten Jahr zählt. Die Rangfolge richtet sich bewusst nach den Kosten **ab** dem zweiten Jahr. |
| **Sonderzahlung** | F1003: Rück-/Nachzahlung oder zusätzliche Abschlagszahlung. Saldo = Kosten - Abschläge + (Σ Rückzahlung - Σ Nachzahlung - Σ Abschlagszahlung). "mit Auswirkung" setzt zusätzlich den künftigen Abschlag. Nur Gas/Strom/Fernwärme. |
| **Zählerstand-Erfassung** | F1004 (v1.6.0): Zentraler View `#/zaehlerstaende` zur schnellen Vor-Ort-Erfassung aller kumulativen Zähler in einem Durchgang. Heizöl/Pellets nutzen Lieferungen; Zähler mit Verbrauch je Zeitraum bekommen seit v3.1.0 eine Karte mit Monat und Verbrauch. |
| **Effizienzklasse** | kWh/m²·a-Einordnung der Heizenergie (A+…H), seit v1.4.0 pro Quelle. |
| **Grundlast** | Wetterunabhängiger Sockel (Warmwasser, Standby). |
| **Anomalie** | Monat, der deutlicher als die Schwelle von der Erwartung für genau diesen Monat abweicht (Heizmodell bzw. derselbe Kalendermonat anderer Jahre); robuste Streuung mit Untergrenze, seit v2.8.0. |
| **Tank-Bestandskurve** | Restbestand bei Öl/Pellets je Tag — seit v2.10.0 aus derselben Rechnung wie der Verbrauch (Tankbuch), zwischen Stützstellen gerechnet, danach geschätzt. |
| **Tankbuch** | v2.10.0: eine Rechnung für Verbrauch, Kosten und Bestand bei Öl/Pellets, gestützt auf bekannte Bestände (Anfangsbestand, Lieferung „bis voll", Peilstand). |
| **Stützstelle** | Tag mit bekanntem Tankbestand. Zwischen zwei Stützstellen ist der Verbrauch gerechnet, nicht geschätzt. |
| **Peilstand** | Abgelesener Tankbestand (Anzeiger, Peilstab, Sensor), am Tank unter `tank_levels` gespeichert. |
| **Energieausweis-nahe Kennzahl** | v2.10.0: zweite Effizienzzahl — Heizwert, witterungsbereinigt, je m² Gebäudenutzfläche, mit Warmwasser-Zuschlag bei dezentraler Bereitung. Kein Verbrauchsausweis (der verlangt 36 Monate). |
| **Gebäudenutzfläche (AN)** | Bezugsfläche des Energieausweises: 1,2 × Wohnfläche, 1,35 × bei Wohngebäuden mit bis zu zwei Wohnungen und beheiztem Keller — in der App Ein-/Zweifamilienhaus oder Reihenhaus (§ 82 Abs. 2 GModG, bis Juli 2026 GEG). |
| **Brennwert / Heizwert** | Gas wird nach Brennwert abgerechnet (kWh inklusive Kondensationswärme), Energieausweis und BAFA-CO₂-Faktoren beziehen sich auf den Heizwert: Heizwert-kWh = Brennwert-kWh × 0,906. |
| **Ersparnis Eigenverbrauch** | v2.10.0: selbst genutzter PV-Strom × Arbeitspreis des Bezugs — was der Eigenverbrauch an Stromkosten vermeidet. |
| **Recurrence** | Wiederholregel eines Termins (jährlich, …). |
| **Zäsur** | F1011 (v2.4.0): datierte bauliche Änderung am Zähler (neue Heizung, Dämmung, Fenster). Ab ihrem Tag rechnen Heizmodell, Wetterbereinigung, Anomalien und Prognose neu; Monate davor bleiben sichtbar, aber grau. Die Karte „Wirkung der Maßnahme“ vergleicht vorher und nachher je Heizgradtag. |
| **Zustandszahl** | Rechnet das gemessene Gasvolumen auf den Normzustand um (Druck, Temperatur am Einbauort); steht auf der Gasrechnung, typisch 0,93–0,97. |
| **Gas-Umrechnungsfaktor** | v2.5.0 (F1012): Zustandszahl × Brennwert je Zeitraum, mit dem Tag, ab dem er gilt („Gültig ab“). Ein Ableseintervall über einen Wechsel wird tagesgenau geteilt. |
| **Abrechnungsstichtag** | Tag der Jahresabrechnung je Verbrauchsart (`billing_cycle_anchor_*`, Standard 1. Januar); bis dorthin rechnet die Saldo-Karte die erwartete Abrechnung. |
| **Ableseart** | Woher ein Zählerstand stammt. In der Rechnungsprüfung: ohne Zusatz abgelesen, **S** als geschätzt erfasst, **E** Ersatzwert — tagesgenau zwischen zwei Ablesungen ermittelt, wo der Versorger ebenfalls schätzt. Seit v3.1.0 folgen die Kürzel der Sprache der Oberfläche (Englisch E/I, Italienisch S/I …, [Gas](01-gas.md)). Die Kürzel auf Versorgerrechnungen unterscheiden sich; ihre Legende steht dort. |
| **Rechnungsprüfung** | v2.5.0: rechnet eine Gasrechnung Abschnitt für Abschnitt nach — m³ × Zustandszahl × Brennwert = kWh, geteilt an jeder Ablesung und jedem Brennwertwechsel. Seit v3.1.0 auch für Strom, Wasser und Fernwärme (geteilt an Ablesungen und Preisstichtagen) und mit den Kosten je Abschnitt ([Anleitung](../anleitungen/jahresabrechnung.md)). |
| **Versorgerrechnung („Laut Rechnung“)** | v3.1.0: die Werte der Jahresabrechnung des Versorgers — Zeitraum, Menge, Betrag, Abschläge, Ergebnis, weitere Posten, bei Gas die CO₂-Angaben. Die App stellt sie der eigenen Rechnung gegenüber („passt“ bis 1 % bzw. 2 €, sonst „prüfen“) und bucht das Ergebnis auf Wunsch als Sonderzahlung ([Anleitung](../anleitungen/jahresabrechnung.md#7-laut-rechnung-erfassen-vergleichen-buchen)). |
| **CO₂-Preis (BEHG)** | v3.1.0: Seit 2021 zahlen Lieferanten von Gas und Heizöl in Deutschland für jede Tonne CO₂ einen Preis und geben ihn im Arbeitspreis weiter: 2025 fest 55 Euro, 2026 ersteigert im Korridor von 55 bis 65 Euro – auf Rechnung und Heizkostenabrechnung angesetzt mit 60 Euro je Tonne (§ 4 CO2KostAufG). Die App weist ihn aus – er ist kein Aufschlag. Gerechnet wird mit den Standardfaktoren des BEHG; Holz, Pellets und Strom tragen keinen ([CO₂-Preis im Brennstoff](16-co2-preis.md)). |
| **CO₂-Kostenaufteilung** | v3.1.0: In deutschen Mietwohnungen teilen sich Mieter und Vermieter die CO₂-Kosten nach zehn Stufen (CO2KostAufG): unter 12 kg CO₂ je m² und Jahr trägt der Mieter alles, ab 52 kg der Vermieter 95 %. Der Wert wird auf eine Nachkommastelle gerundet ([CO₂-Kosten teilen](../anleitungen/co2-aufteilung.md)). |
| **Leistungspreis, Messpreis** | v3.1.0, Fernwärme: feste Kosten neben dem Grundpreis — Leistungspreis in € je kW Anschlussleistung und Jahr, Messpreis in € je Jahr. Beide fließen je Monat in Kosten, Saldo und Rechnungsprüfung ([Fernwärme](04-fernwaerme.md)). |
| **Marktlokations-ID (MaLo)** | v3.1.0: die elfstellige Nummer der Stelle, an der Energie geliefert wird; die letzte Ziffer ist eine Prüfziffer. Der neue Anbieter fragt sie beim Wechsel ab. Daneben die **Messlokations-ID (MeLo)** mit 33 Zeichen ab „DE“. Beide stehen auf der Rechnung. |
| **Sonderkündigungsrecht** | Erhöht der Lieferant die Preise, darfst du in Deutschland zu diesem Tag kündigen (§ 41 Abs. 5 EnWG). Seit v3.1.0 meldet die App eine eingetragene Preiserhöhung als Empfehlung und unter „Zu tun“. |
| **Gruppenvertrag** | v3.1.0: ein Vertrag für eine Zählergruppe statt für einen Zähler — etwa Hoch- und Niedertarif eines Doppeltarifzählers mit eigenem Arbeitspreis je Zählwerk. Grundpreis, Abschläge und Boni zählen einmal ([Meter-Topologie](13-meter-topologie.md#gruppenvertrag-v310)). |
| **§ 14a EnWG (steuerbare Verbraucher)** | v3.1.0: Wärmepumpen, Wallboxen, Klimaanlagen und Speicher über 4,2 kW, die seit dem 01.01.2024 in Betrieb gehen, darf der Netzbetreiber bei Engpässen drosseln – auf mindestens 4,2 kW, nicht auf null; dafür sinkt das Netzentgelt. Modul 1: ein fester Betrag im Jahr. Modul 2: ein eigener Zähler mit 40 % des Netz-Arbeitspreises. Modul 3: zeitvariable Netzentgelte zusätzlich zu Modul 1 ([Strom](02-strom.md#steuerbare-verbraucher-v310)). |
| **Dynamischer Tarif** | v3.1.0: Ein Stromtarif, dessen Arbeitspreis dem Börsenpreis folgt (§ 41a EnWG). Abgerechnet wird er mit einem intelligenten Messsystem je Viertelstunde. Die App schätzt ihn mit dem Monatsmittel der Börse plus Aufschlag — eine Näherung, kein Ersatz für die Abrechnung ([Strom](02-strom.md#dynamische-tarife-v310)). |
| **Jahresarbeitszahl (JAZ)** | v3.1.0: Wie viel Wärme eine Wärmepumpe aus einer kWh Strom macht, gemessen über ein Jahr: Wärme ÷ Strom. Braucht einen Wärmezähler an der Wärmepumpe. Im Feldtest lagen Luft/Wasser-Geräte bei rund 3,4, Sole/Wasser bei 4,3 ([Heizwärme](15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310)). |
| **Strompreispauschale** | v3.1.0: Pauschaler Preis je kWh, mit dem der Arbeitgeber den zu Hause geladenen Strom eines Dienstwagens erstatten kann (BMF-Schreiben vom 11.11.2025; 2026: 34 ct/kWh) — Alternative zum Vertragspreis ([Ladestrom-Nachweis](../anleitungen/ladestrom-nachweis.md)). |

---

## Formeln (gegen Code geprüft)

**Tagesverbrauch (kumulativ):**

```text
kWh_Tag = (c2 - c1) / (t2 - t1)
```

**Zählertausch:**

```text
Verbrauch = (final_alt - prev) + (curr - initial_neu)
```

**Energieumrechnung:**

```text
Gas:     kWh = m³ × Zustandszahl × Brennwert   (je Zeitraum ab „Gültig ab“, seit v2.5.0)
Heizöl:  kWh = Liter × Hu
Pellets: kWh = kg × Hu
```

**Heizgradtage:**

```text
HGT_Tag = max(0, T_base - T_avg)
```

**Bestimmtheitsmaß:**

```text
R² = 1 - ( Σ (yi - ŷi)² ) / ( Σ (yi - ȳ)² )
```

**Sigmoid-Heizsignatur** (Backend `sigmoidPredict`):

```text
kWh = A / (1 + (B / (HGT - θ0))^C) + D     für HGT > θ0
kWh = D                                     sonst
```

**Prognose-Blend:**

```text
w        = min(R², blend_max)
Prognose = w · Regressionswert + (1 - w) · Saisonwert
```

**Effizienzkennzahl (je Heizquelle):**

```text
Kennzahl = (Σ Heiz-kWh des Jahres) / Wohnfläche_m²     [kWh / (m²·a)]
```

**Energieausweis-nahe Kennzahl (seit v2.10.0):**

```text
AN       = Wohnfläche × 1,2   (1,35 bei EFH/RH mit beheiztem Keller)
Kennzahl = Σ bereinigte kWh (Gas × 0,906) / AN  (+ 20 bei dezentralem Warmwasser)
```

**Tankbuch (Öl/Pellets, seit v2.10.0):**

```text
Verbrauch zwischen Stützstellen = Bestand_vorher + Σ Lieferungen − Bestand_nachher
Anteil_Tag ∝ ρ + HGT_Tag           ρ = s · HGT_Jahr / ((1 − s) · 365,25)
nach der letzten Stützstelle: Rate × (ρ + HGT_Tag), geschätzt
Preis: gleitender Durchschnitt des Tankinhalts
```

**Lieferkosten (v1.4.2, Gesamtbetrag-Vorrang):**

```text
Kosten = total_eur                          falls gesetzt
Kosten = Menge × unit_price_cents / 100     sonst
```

**Saldo:**

```text
Saldo = Σ Kosten - Σ Abschläge + (Σ Rückzahlung - Σ Nachzahlung - Σ Abschlagszahlung)

Saldo > 0  → Nachzahlung droht
Saldo < 0  → Guthaben
```

**Wasser-Spar-Index:**

```text
Spar-Index = (Liter pro Person und Tag) / Referenz × 100
```

**CO₂** *(Default-Faktoren mit Quelle seit v2.10.0; Strom je Jahr)*:

```text
CO2 = Verbrauch × CO2-Faktor
```

**Z-Score (Anomalie, seit v2.8.0):**

```text
r = Ist - Erwartung
z = (r - Median(r)) / max( 1,4826 × MAD(r), 0,10 × max(Erwartung, typischer Monat) )
```

**Heizmodell und Bereinigung (seit v2.8.0):**

```text
expected_heat = a × HGT + c × Tage
heat_adjusted = Ist + a × (HGT_normal - HGT_ist)      (mindestens c × Tage)
```

**Verbrauch je Zeitraum (seit v3.1.0):**

```text
Tagesrate      = Wert / Tage des Zeitraums     (von und bis einschließlich)
Monatsanteil   = Tagesrate × Tage des Zeitraums in diesem Monat
```

**Warmwasser-Wärme (seit v3.1.0, HeizkostenV § 9 Abs. 2):**

```text
Q [kWh] = 2,5 × V [m³] × (t_w − 10)      t_w = warmwasser_temp_c, Standard 60 °C
1 m³ bei 60 °C = 125 kWh;  3 m³ bei 55 °C = 337,5 kWh
```

**Mietverhältnis — Hilfsrechnung je Monat (seit v3.1.0):**

```text
erwartet = Wärme-kWh × Preis + Warmwasser-m³ × Preis + Kaltwasser-m³ × Preis + Umlagen / 12
bezahlt  = Vorauszahlung Heizung + Betriebskosten
Ergebnis = Σ erwartet − Σ bezahlt        > 0 Nachzahlung, < 0 Guthaben
```

**CO₂-Preis im Brennstoff (seit v3.1.0, BEHG):**

```text
Emissionen [kg] = Verbrauch [kWh] × Faktor   (Gas 0,18139 je kWh Brennwert, Heizöl 0,2664 je kWh Heizwert)
netto [€]       = Emissionen / 1000 × Preis [€/t]
brutto [€]      = netto × (1 + USt)   (USt 19 %; Gas und Fernwärme 10/2022–3/2024 7 %, § 28 UStG)
mit Rechnung    brutto = CO₂-Betrag laut Rechnung (mit USt, CO2KostAufG § 3 Abs. 3), netto = brutto / (1 + USt)
Szenario [ct/kWh] = (Szenario-Preis − Preis des Jahres) × Faktor / 10 × 1,19
```

**CO₂-Kostenaufteilung (seit v3.1.0, CO2KostAufG):**

```text
kg je m²   = Emissionen / Wohnfläche          (eine Nachkommastelle)
Stufe      < 12 → 0 % · < 17 → 10 % · < 22 → 20 % · < 27 → 30 % · < 32 → 40 %
           < 37 → 50 % · < 42 → 60 % · < 47 → 70 % · < 52 → 80 % · sonst 95 %   (Anteil Vermieter)
Erstattung = CO₂-Kosten × Anteil × Kürzung   (eigene Geräte × 0,95; eine Vorgabe × 0,5; beide 0)
```

**Fernwärme — feste Kosten je Monat (seit v3.1.0):**

```text
fest = Grundpreis + Anschlussleistung [kW] × Leistungspreis [€/(kW·a)] / 12 + Messpreis [€/a] / 12
```

**§ 14a EnWG, Modul 1 (seit v3.1.0):**

```text
fest = Grundpreis − Reduzierung [€/a] / 12          120 €/a → 10 € je Monat weniger
```

**Dynamik-Check (seit v3.1.0):**

```text
Arbeitspreis [ct/kWh] = Börsen-Monatsmittel [ct/kWh] × (1 + USt) + Aufschlag [ct/kWh]
3.000 kWh, 10 ct, 19 %, 15 ct, 10 €/Monat → 3.000 × 26,9 ct + 120 € = 927 €
```

**Ladestrom-Nachweis (seit v3.1.0):**

```text
Vertragspreis: Betrag = kWh × Arbeitskosten/kWh des zahlenden Zählers + Grundpreis × kWh Wallbox / kWh Elternzähler
Pauschale:     Betrag = kWh × Pauschale          3.000 kWh × 0,34 € = 1.020 €
```

**Jahresarbeitszahl (seit v3.1.0):**

```text
JAZ = Σ Wärme [kWh] / Σ Strom [kWh]        nur Monate mit Werten auf beiden Seiten
9.000 kWh / 2.500 kWh = 3,6
```

**PV-Amortisation (seit v3.1.0):**

```text
Nutzen = Eigenverbrauch × Arbeitspreis + Einspeiseerlös     (seit Inbetriebnahme)
Jahre  = Investition / Nutzen der letzten 12 Monate          800 € / 160 €/a = 5 Jahre
```

---

## Default-Werte (Auswahl)

| Schlüssel | Default | Einheit |
|---|---|---|
| `gas_conversion_factors` | `[{from:null, kwh_per_m3:11,5}]` | datierte Liste (z × Hs je Stichtag) |
| `heizoel_kwh_per_l` | 10,0 | kWh/L |
| `pellets_kwh_per_kg` | 4,8 | kWh/kg |
| `hdd_base_temp` | 15,0 | °C |
| `blend_max` | 0,80 | — |
| `delivery_baseload_share` | 0,15 | Anteil |
| `forecast_months` | 12 | Monate |
| `wohnflaeche_m2` | 100 | m² |
| `co2_gas` | 182 (BAFA, Heizwert × 0,906) | g/kWh |
| `co2_strom` / `co2_strom_years` | 380 bis 2014, danach Umweltbundesamt je Jahr (2025: 344) | g/kWh |
| `co2_heizoel` / `co2_pellets` / `co2_fernwaerme` | 266 / 36 / 280 (BAFA) | g/kWh |
| `co2_wasser` | 350 *(ohne Quelle)* | g/m³ |
| `warmwasser_temp_c` | 60 (v3.1.0) | °C |
| `co2_price_eur_t_years` | `{}` = Länderprofil (DE 2025: 55, 2026: 60) (v3.1.0) | €/t |
| `co2_price_scenario_eur_t` / `co2_price_scenario_from` | leer / 2028 (v3.1.0) | €/t / Jahr |

---

[← Szenario Eigenheim](08-szenario-eigenheim.md) ·
[Kompendium-Index](../README.md)
