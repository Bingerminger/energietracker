# Grundlagen & Methodik

**Deutsch** · [English](../en/functional/00-overview.md)

[← Kompendium-Index](../README.md)

Dieses Kapitel erklärt die Rechenkerne, die für *alle* Verbrauchsarten
gelten: Verbrauchsverteilung, Heizgradtage und Temperaturen, Regression,
Wetterbereinigung, Prognose, Effizienzklasse, Anomalien und Saldo. Alle
Formeln sind gegen den Quellcode geprüft (Stand v2.8.0).

> **Hinweis zur Darstellung:** Formeln stehen bewusst als
> Klartext-Codeblöcke (keine LaTeX-Mathematik), damit sie auf GitHub,
> in Editoren und in jedem Markdown-Viewer **identisch und korrekt**
> dargestellt werden.

---

## 1. Vom Zählerstand zum Monatsverbrauch

Zähler werden in unregelmäßigen Abständen abgelesen. Zwischen zwei
Ablesungen `r1` (Datum `t1`, Stand `c1`) und `r2` (`t2`, `c2`) gilt:

```text
Verbrauch[t1..t2] = c2 - c1
Tage              = t2 - t1
```

Dieser Verbrauch wird **linear über die Tage interpoliert** und dann den
Kalendermonaten zugeschlagen:

```text
Verbrauch pro Tag = (c2 - c1) / (t2 - t1)
```

Über eine **Zählertausch-Grenze** hinweg (altes Gerät → neues Gerät):

```text
Verbrauch = (final_alt - prev) + (curr - initial_neu)
            \__ Restweg altes Gerät __/  \__ neues Gerät __/
```

Zukunfts-Ablesungen (`is_future`) werden ignoriert. Bei lieferbasierten
Arten (Heizöl/Pellets) entfällt die Zähler-Differenz — dort wird der
Verbrauch energetisch bilanziert (siehe [Heizöl](05-heizoel.md)).

---

## 2. Energieumrechnung

Damit Arten vergleichbar werden, rechnet Energietracker intern in
**kWh** (Wasser bleibt in m³). Die Umrechnung steckt in den
Einstellungen:

| Art | Formel | Default |
|---|---|---|
| Gas | `kWh = m³ × Zustandszahl × Brennwert` | `gas_conversion_factors` — datierte Liste, je Stichtag z × Hs (seit v2.5.0; Default 11,5 kWh/m³) |
| Heizöl | `kWh = Liter × Hu` | `heizoel_kwh_per_l` = 10,0 kWh/L |
| Pellets | `kWh = kg × Hu` | `pellets_kwh_per_kg` = 4,8 kWh/kg |
| Strom, Fernwärme | bereits kWh | — |
| Wasser | bleibt m³ | — |

Der Gasfaktor steht so auf der Gasrechnung (Brennwert × Zustandszahl)
und sollte dort abgelesen und in den Einstellungen gepflegt werden.

---

## 3. Heizgradtage (HGT) und Temperaturen

Der zentrale Wetterbezug. Pro Tag mit mittlerer Außentemperatur
`T_avg` und Heizgrenztemperatur `T_base` (`hdd_base_temp`, Default
**15 °C**):

```text
HGT_Tag = max(0, T_base - T_avg)
```

Monats-HGT = Summe der Tageswerte **über die Tage, für die Verbrauch
vorliegt** (seit v2.8.0). Endet die letzte Ablesung am 15., bekommt der
Monat die Gradtage von 15 Tagen — bis v2.7 waren es die des ganzen
Monats, und der Teilmonat sah aus wie ein sparsamer Wintermonat.
`temp_days` zählt, für wie viele dieser Tage eine Temperatur vorliegt.
Fehlen mehr als 10 %, ist der Monat unvollständig und geht in kein
Modell ein.

Anschaulich: An einem 5-°C-Tag fallen `15 - 5 = 10` HGT an, an einem
20-°C-Tag null. Je kälter, desto mehr HGT, desto mehr Heizenergie. Nur
HGT-relevante Arten (Gas, Fernwärme, Heizöl, Pellets) nutzen das; Strom
und Wasser nicht.

### Woher die Temperaturen kommen

Jeder Tag trägt seine Quelle (`source`):

| Quelle | Bedeutung | wird überschrieben durch |
|---|---|---|
| `archive` | Messwert aus dem Open-Meteo-Archiv (rund sechs Tage Verzug) | nichts |
| `forecast` | Vorhersage für die letzten Tage und die kommende Woche | den Archivwert, sobald er da ist |
| `csv`, `manual` | eigener Import oder eigene Eingabe | nichts — auch nicht durch den Abruf |

Mit `weather_auto_fill` (Default an) holt die App die fehlenden Tage
**einmal am Tag beim Öffnen** selbst, ab der ersten Ablesung. Übermittelt
wird nur der Standort, auf zwei Nachkommastellen gerundet (rund 1 km).
Einträge ohne Quelle stammen aus Versionen vor v2.8.0. Sind sie älter als
die Archiv-Verzögerung, gelten sie als gemessen. Wer sie trotzdem
ersetzen will — frühere Versionen speicherten Vorhersagen wie Messwerte
—, setzt beim Abruf „Auch vorhandene ältere Werte durch Archivwerte
ersetzen" (`reload=1`).

### Klimanormal

Beim ersten Abruf lädt die App zusätzlich die Tagesmittel der letzten
**30 vollen Kalenderjahre** am Standort und speichert daraus nur die
Kennzahlen (`data/climate_normal.json`): die mittleren Heizgradtage je
Kalendermonat, ihre Streuung über die Jahre und die Streuung der
Jahressumme — für Heizgrenzen von 10 bis 22 °C in halben Grad, weil
Gradtage nicht aus Monatsmitteln folgen. Neu geladen wird, wenn sich der
Standort um mehr als rund 5 km verschiebt oder ein weiteres Jahr
abgeschlossen ist.

Wozu: Die Prognose braucht die Gradtage eines **normalen** Januars,
auch wenn die eigene Historie im Mai beginnt, und die Bereinigung
vergleicht mit dem langjährigen Mittel statt mit den eigenen zwölf
Monaten. Ohne Klimanormal rechnet die App mit dem Mittel der eigenen
Temperaturhistorie und sagt das dazu.

---

## 4. Regressionsmodelle

Energietracker fittet den Zusammenhang **HGT → Verbrauch** mit fünf
Modellen (`RegressionService`). Jedes liefert Parameter,
Bestimmtheitsmaß `R²` und ein `valid`-Flag (genug Datenpunkte?).

| Modell | Form | Mindestpunkte |
|---|---|---|
| **linear** | `y = a·x + b` | 3 |
| **polynomial** | `y = a·x² + b·x + c` | 4 |
| **robust** | linear, Huber-gewichtet — dämpft Ausreißer | 4 |
| **segmented** | `y = b + a · max(0, x - k)` — Sommersockel `b`, Heizast ab Knick `k` | 8, je Seite des Knicks 4 |
| **sigmoid** | S-Kurve (Heizsignatur), TU-München/BDEW-Form | 8, gültig ab `R² ≥ 0,5` |

Das Knickmodell ist seit v2.8.0 **stetig**: Sockel und Heizast treffen
sich im Knick. Bis v2.7 wurden beide Äste getrennt gefittet und sprangen
dort auseinander. Der Knick `k` wird
gesucht (`segmented_split_mode = auto`) oder fest vorgegeben.

Die Sigmoid-Form (exakt wie im Backend `sigmoidPredict`):

```text
kWh = A / (1 + (B / (HGT - θ0))^C) + D     für HGT > θ0
kWh = D                                     sonst
```

`R²` misst, wie gut das Modell die gezeigten Monate trifft (`R² = 1`:
perfekt, `0`: nicht besser als der Mittelwert):

```text
R² = 1 - ( Σ (yi - ŷi)² ) / ( Σ (yi - ȳ)² )
```

Das ist **Anpassung**, keine Vorhersagegüte: Ein Modell mit mehr
Parametern trifft dieselben Punkte immer mindestens so gut.

**Welche Monate Punkte sind** — seit v2.8.0 eine Regel für Analyse,
Bereinigung und Prognose (bis v2.7 wählte jede Stelle etwas anders; derselbe
Zähler zeigte R² 0,42 in der Analyse und 0,56 in der Prognose):

```text
Punkt  ⇔  Tage ≥ min_days_period (20)
          und Temperaturen für ≥ 90 % der Tage
          und HGT > min_hdd_regression (5)
          und Verbrauch > 0
          und nicht vor der Zäsur
```

Jeder Monat trägt dazu `regression_point`. Das Streudiagramm der Analyse
zeichnet Monate außerhalb des Fits als hohle Punkte, Monate vor der Zäsur
grau.

Das Standardmodell ist in den Einstellungen wählbar
(`forecast_model`); in der **Prognose** und im
**Analyse-Korrelationschart** werden alle fünf Modelle dargestellt.

---

## 5. Wetterbereinigung — „mehr verbraucht oder nur kälter?"

### Heizmodell

Für HGT-relevante Arten mit Zählerständen (Gas, Fernwärme) rechnet die
App seit v2.8.0 mit einem Heizmodell je Zähler:

```text
Verbrauch = a × HGT + c × Tage
```

`a` ist der Verbrauch je Gradtag (Heizanteil), `c` die Grundlast je Tag
(Warmwasser, Kochen). Gefittet ohne Achsenabschnitt über alle Monate ab
der Zäsur mit mindestens `min_days_period` Tagen, Temperaturen und
Verbrauch — **der Sommer eingeschlossen**, er bestimmt die Grundlast mit.
Mindestens 8 Monate. Ein Robustheitsschritt nimmt Monate, die mehr als
3,5 robuste Streuungen daneben liegen (etwa ein Sommer mit defekter
Therme), einmal heraus und fittet neu. `a` und `c` sind nie negativ.

Heizöl und Pellets bekommen kein Heizmodell: Ihre Monatswerte sind aus
den Lieferungen **nach Gradtagen verteilt**. Ein Fit würde nur die
Verteilung zurückrechnen.

### Felder je Monat

| Feld | Formel | Bedeutung |
|---|---|---|
| `expected_heat` | `a × HGT + c × Tage` | Erwartung bei genau diesem Wetter und diesen Tagen |
| `weather_delta_pct` | `(Ist - expected_heat) / expected_heat × 100` | Mehr- oder Minderverbrauch **bei gegebenem Wetter** (nur Monate mit genug Tagen) |
| `hdd_normal` | `HGT_Normal[Monat] × Tage / Monatstage` | Gradtage eines Normaljahrs für dieselben Tage |
| `heat_adjusted` | `Ist + a × (hdd_normal - HGT)`, mindestens `c × Tage` | witterungsbereinigter Verbrauch |

`heat_adjusted` rechnet nur den **Wettereinfluss laut Modell** auf ein
Normaljahr um; was der Monat darüber hinaus anders war (Besuch, Urlaub, ein
klemmendes Ventil), bleibt stehen. Unter `min_hdd_regression` Gradtagen oder
unter der Grundlast (Urlaub, Leerstand) bleibt der Monat unverändert, und die
Grundlast ist die Untergrenze. Die aus VDI 3807 bekannte Skalierung des ganzen
Heizanteils mit `HGT_normal / HGT_ist` passt für Jahreswerte; in einem
Übergangsmonat mit 11 statt 30 Gradtagen würde sie jede zufällige Abweichung
mit 2,7 multiplizieren.

Damit lässt sich ein kalter Winter von echtem Mehrverbrauch trennen:
Ein Januar mit `weather_delta_pct = +2 %` war normal, auch wenn er
dreimal so viel verbraucht hat wie der Oktober.

> **Ersetzt (seit v2.8.0 veraltet, weiter geliefert):**
> `weather_adjusted` skalierte den **ganzen** Verbrauch mit dem
> HGT-Verhältnis — auch das Warmwasser; ein September wurde so um 54 %
> „bereinigt". `delta_pct` verglich mit dem Mittel aller Monate und maß
> damit die Jahreszeit (Januar „+58 %"). Beide Felder bleiben bis zur
> nächsten Hauptversion in der API, die Oberfläche nutzt sie nicht mehr.

### Wirkung einer Maßnahme (Zäsur)

Ist eine Zäsur gesetzt (F1011), vergleicht die Analyse den Verbrauch je
Gradtag (Steigung `a` der linearen Heizkurve) davor und danach. Seit
v2.8.0 mit Beleg:

```text
signifikant  ⇔  |a_nach - a_vor| / √(se_vor² + se_nach²) ≥ 1,96
95-%-Bereich der Änderung (Delta-Methode):
  Δ% ± 196 × √(se_nach² + (a_nach/a_vor)² × se_vor²) / a_vor
```

`se` ist der Standardfehler der Steigung. Die Oberfläche sagt dazu, ob
der Unterschied belegt ist oder im Rauschen liegen kann — bei wenigen
Wintermonaten ist das oft der Fall.

---

## 6. Prognose

`ForecastService` rechnet je Prognosemonat mit zwei Schätzern:

1. **Regression** des gewählten Modells auf die **normalen**
   Heizgradtage des Monats — aus dem Klimanormal, sonst aus der eigenen
   Temperaturhistorie,
2. **Saisonprofil**: die mittlere **Tagesrate** desselben
   Kalendermonats (Monate mit mindestens `min_days_period` Tagen), mal
   Tage des Prognosemonats.

Die Mischung ist mit dem Regressions-`R²` gewichtet, gedeckelt durch
`blend_max` (Default **0,80**):

```text
w        = min(R², blend_max)
Prognose = w · Regressionswert + (1 - w) · Saisonwert
```

Anschaulich: Erklärt die Heizkurve den Verbrauch gut (`R²` hoch), zählt
sie stärker — aber nie mehr als 80 %. Für **nicht** HGT-relevante Arten
(Strom, Wasser) entfällt die Regression: reine Saisonprognose.

**Fehlt ein Kalendermonat in der eigenen Historie** (wer im Mai beginnt,
hat noch keinen Januar), kommt er aus dem Modell allein
(`regression_only`), sonst aus dem Heizmodell (`heat_model`), sonst aus
dem Tagesmittel aller Monate (`filled`). Bis v2.7 bekam er 0 Gradtage und
den Saisonwert 0 — der Januar lag bei weniger als einem Zehntel des
richtigen Werts. `warnings` meldet
eine kurze Historie (`history_short`) und fehlendes Klimanormal
(`no_climate_normal`).

**Temperaturversatz** (What-if): Ein um δ wärmeres Jahr hat genau die
Heizgradtage der Heizgrenze `T_base - δ`. Mit Klimanormal oder eigener
Temperaturhistorie ist das exakt; nur ohne beide wird wie bisher linear
über alle Monatstage verschoben.

### Unsicherheitsband

Je Monat und fürs Jahr:

```text
σ_Monat = √( (a × σ_HGT[Monat])² + σ_Rest² )
Band    = Prognose ± z × σ_Monat

σ_Jahr  = √( (a × σ_HGT,Jahr)² + Σ σ_Rest² )
```

`a` ist der Verbrauch je Gradtag, `σ_HGT` die Streuung der Gradtage
dieses Monats über 30 Jahre (Klimanormal), `σ_HGT,Jahr` die der
Jahressumme — ein kalter Winter wirkt auf alle Monate zugleich, deshalb
nicht die Summe der Monatsstreuungen. `σ_Rest` ist das Rauschen des
Heizmodells; bei Arten ohne Wetterbezug die Streuung der Tagesrate um
das Saisonprofil. `z = confidence_band_sigma`, Default **1,28 ≈ 80 %**
der Jahre (die Einstellung war bis v2.7 ohne Wirkung).

### Kosten

Zusätzlich projiziert die Prognose die **Kosten** je Monat mit dem dann
gültigen Arbeits- und Grundpreis und dem Abschlag aus dem **effektiven**
Zahlungsplan (Sonderzahlungen „mit Auswirkung" ändern ihn). Nach dem Ende
des letzten Vertrags läuft dieser als Annahme weiter — Verträge
verlängern sich in der Regel —, die Monate sind markiert
(`contract_assumed`).

---

## 7. Effizienzklasse

`BenchmarkService` rechnet den spezifischen Heizenergiebedarf:

```text
Kennzahl = (Σ Heiz-kWh des Jahres) / Wohnfläche_m²     [kWh / (m²·a)]
```

und ordnet ihn anhand der Bandgrenzen
(`efficiency_class_thresholds`, Default A+ < 30, A < 50, B < 75,
C < 100, D < 130, E < 160, F < 200, G < 250, sonst H) ein.

**Seit v1.4.0 pro Heizquelle getrennt.** Ein Haus heizt real meist mit
einer Quelle; alle Heizarten zu summieren ergäbe eine unsinnige Klasse.
Ausgewiesen werden `per_source` (Klasse je Quelle), `primary` (größte)
und `combined` (Summe — nur bei bewusst kombiniertem Heizbetrieb wie
Pellets-Grundlast + Gas-Spitzenlast sinnvoll).

---

## 8. CO₂

```text
CO2 = Verbrauch × CO2-Faktor
```

mit artspezifischem Faktor (`co2_gas`, `co2_strom`, …). Die Defaults
sind **[Unverifiziert]** grobe Richtwerte und sollten in den
Einstellungen an die eigene Quelle (Stromtarif-Mix, Heizöl-Norm)
angepasst werden.

---

## 9. Anomalien

`AnomalyService` markiert Monate, deren Verbrauch deutlich von der
**Erwartung für genau diesen Monat** abweicht. Seit v2.8.0:

| Art | Erwartung |
|---|---|
| Gas, Fernwärme | `expected_heat` aus dem Heizmodell (Abschnitt 5) — im Sommer ist das die Grundlast |
| Strom, Wasser, PV | Tagesrate desselben Kalendermonats in **anderen** Jahren (Median), im ersten Jahr die der beiden Nachbarmonate — mal Tage des Monats |
| Heizöl, Pellets | keine Anomalien: Die Monatswerte sind nach Gradtagen verteilt, jede „Abweichung" wäre ein Artefakt der Verteilung |

Der geprüfte Monat steckt nie in seiner eigenen Erwartung
(leave-one-out). Bis v2.7 verglich sich ein +60-%-März bei einem Jahr
Historie mit einem Mittel, das ihn selbst enthielt, und Heizarten
bekamen im Sommer die Erwartung 0 — jeder Sommer war „Ausreißer".

Die Streuung wird **robust** geschätzt (Median und MAD der Residuen
statt Mittel und Standardabweichung — ein einzelner Ausreißer bläht die
Standardabweichung sonst auf und versteckt sich darin), mit einer
Untergrenze von 10 % der Erwartung bzw. eines typischen Monats:

```text
r       = Ist - Erwartung
σ       = max( 1,4826 × MAD(r),  0,10 × max(Erwartung, typischer Monat) )
z       = (r - Median(r)) / σ
Anomalie ⇔ |z| ≥ anomaly_threshold      (Default 2,0)
```

Die Untergrenze verhindert, dass bei sehr gleichmäßigen Daten schon
+5 % als „6σ" gemeldet werden: Gemeldet wird erst, was bei 2σ über rund
20 % eines typischen Monats hinausgeht. Nicht geprüft werden Monate mit
weniger als `min_days_period` Tagen, Monate mit Zählertausch und Monate
vor der Zäsur; unter 5 verwertbaren Monaten gar nichts.

Die Empfehlungen bauen darauf auf: **R1** (Mehrverbrauch bei gegebenem
Wetter) misst Gas und Fernwärme am Heizmodell mit derselben Streuung und
Untergrenze (Schwelle `recommendation_anomaly_sigma`), **R4** meldet die
„hohen" Anomalien der Arten ohne Wetterbezug — PV ausgenommen, ein
ertragreicher Monat ist kein Warnsignal. **R2** (Trend) vergleicht die
witterungsbereinigten Werte (`heat_adjusted`) der letzten zwölf Monate
mit denselben Kalendermonaten des Vorjahrs, sobald mindestens neun
solche Paare vorliegen.

Anomalien sind Hinweise, keine Urteile — ein Umzug, eine neue
Wärmepumpe oder ein defekter Zähler erzeugen sie gleichermaßen.

---

## 10. Saldo bis heute

Für Verträge mit Abschlägen (Gas, Strom, Fernwärme) rechnet die
Saldo-Karte seit v2.8.0 **nach Kalender bis heute**, wie die
Jahresabrechnung:

```text
Kosten bis heute   = Arbeitspreis × gemessener Verbrauch      (bis zur letzten Ablesung)
                   + Arbeitspreis × geschätzter Verbrauch     (letzte Ablesung → heute)
                   + Grundpreis tagesgenau bis heute
                   - Boni bis heute
Abschläge bezahlt  = Abschläge laut Zahlungsplan, fällig am Monatsanfang,
                     bis einschließlich des laufenden Monats
Saldo heute        = Kosten bis heute - Abschläge bezahlt + Sonderzahlungen netto
Erwartet am Ende   = Saldo heute + geschätzte Restkosten - restliche Abschläge
```

Bis v2.7 zählten Kosten **und** Abschläge nur für Monate mit Ablesung.
Wer zuletzt im März abgelesen hatte, sah im September einen Saldo aus
drei Monaten, während die Bank längst neun Abschläge abgebucht hatte.

**Die Schätzung** der Lücke und des Rests läuft je Tag: bei Gas und
Fernwärme mit dem Heizmodell (`a × HGT + c`, mit der gemessenen
Tagestemperatur, für Tage ohne Wert mit dem Klimanormal), sonst mit der
Tagesrate desselben Kalendermonats. Liegen die letzten gemessenen Monate
(bis zu sechs volle aus den letzten zwölf) deutlich über oder unter dem
Modell — neue Heizung ohne Zäsur, anderes Verhalten —, folgt die
Schätzung diesem Niveau (`projection_factor`, begrenzt auf 0,5 bis 1,5).

**Abschlagsvorschlag:** Weicht der erwartete Saldo spürbar ab, schlägt
die Karte einen Abschlag vor, der ihn bis zum Vertragsende ausgleicht:

```text
Vorschlag = max(0, gerundet( aktueller Abschlag + erwarteter Saldo / verbleibende Monate ))
```

Die Aufschlüsselung auf der Karte (`energy_cost_to_date`,
`base_to_date`, `bonus_to_date`) ergibt die Summe; der geschätzte Teil
steht darunter mit dem Datum der letzten Ablesung.

### Tagesgenau wie die Rechnung (seit v2.9.0)

Vertrag und Preise gelten **ab ihrem Tag**, nicht ab dem Monatsersten. Ein
Monat wird an jedem Vertragsbeginn und -ende und an jedem Stichtag der
Arbeitspreise, Grundpreise und Abschläge geteilt:

```text
Arbeitspreis  je Abschnitt: Verbrauch der Abschnittstage × Preis dieses Tages
Grundpreis    Monatsbetrag × Tage des Abschnitts / Tage des Monats
Abschlag      Monatsbetrag am ersten Vertragstag des Monats,
              anteilig nach den Vertragstagen im Monat
```

Bis v2.8 galt der Vertrag vom Monatsersten für den ganzen Monat: Ein Wechsel
zum 15. rechnete den Juni zum alten Preis, eine Preiserhöhung zum 15. März griff
erst im April. Liegen zwei Verträge in einem Monat, gehört die Monatszeile dem
mit den meisten Tagen; beide Teile stehen in `contract_parts`, und der Saldo
jedes Vertrags zählt nur seinen Teil.

**Ohne Kündigung läuft ein Vertrag weiter.** Endet ein Vertrag, ohne dass ein
Nachfolger beginnt, rechnet die App mit seinen letzten Preisen und Abschlägen
weiter (`contract_assumed`) — so wie der Versorger. Bis v2.8 kostete
Verbrauch nach einem vergessenen Vertragsende 0 €. Wer gekündigt hat, nimmt
am Vertrag den Haken „Verlängert sich ohne Kündigung" heraus (`auto_renews:
false`). Ein weiterlaufender Vertrag ist seit März 2022 jederzeit mit
höchstens einem Monat Frist kündbar (§ 309 Nr. 9 BGB); die Saldo-Karte sagt
das dazu, und der Saldo läuft bis zur nächsten Abrechnung.

### Kündigungsstichtag und Erinnerung (seit v2.9.0)

```text
Stichtag (befristet)   = Vertragsende − Kündigungsfrist
Wechsel (jederzeit)    = heute + Frist, zum Monatsende oder zu jedem Tag
Wechsel (weiterlaufend)= heute + min(Frist, 1 Monat)
```

Fristen gibt es in Monaten, Wochen oder Tagen (`notice_period_months` bzw.
`notice_period_days`), dazu die Kündigungsweise (`notice_mode`: zum
Vertragsende, jederzeit zum Monatsende, jederzeit zu jedem Tag — etwa die
Grundversorgung mit zwei Wochen). Die drei Erinnerungsstufen
(`contract_remind_days_1/2/3`, Standard 90/30/1 Tage) zählen bis zum
**Kündigungsstichtag**, nicht mehr bis zum Vertragsende: Mit einem Monat Frist
kamen bisher zwei der drei Erinnerungen nach dem Stichtag. Ist der Stichtag
verpasst, sagt die Karte das (`cancel_missed`). Eine eingetragene
Preiserhöhung in der Zukunft erscheint als Hinweis, in Deutschland mit dem
Sonderkündigungsrecht zum Zeitpunkt der Erhöhung (§ 41 Abs. 5 EnWG).

---

[← Kompendium-Index](../README.md) ·
[Gas →](01-gas.md)
