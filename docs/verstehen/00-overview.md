# Grundlagen & Methodik

**Deutsch** · [English](../en/verstehen/00-overview.md)

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

### Gemessen oder verteilt *(seit v3.7.0)*

Liegen zwei Ablesungen weit auseinander, ist der Monatswert keine Messung,
sondern die gleichmäßige Verteilung eines längeren Zeitraums: Bei Ablesungen
Ende September und Ende Dezember haben Oktober, November und Dezember dieselbe
Tagesrate. Jeder Monat trägt deshalb eine Kennung:

```text
gemessen          = Ablesungsabstand ≤ 45 Tage
measured_share      = Anteil des Monatsverbrauchs aus solchen Abständen (0 … 1)
measured_day_share  = Anteil der Tage des Monats aus solchen Abständen (seit v3.9.0)
interpolated        = measured_share < 0,5 oder measured_day_share < 0,5   („verteilt“)
reading_gap_days    = längster Abstand, aus dem der Monat stammt
```

**Seit v3.9.0 zählen auch die Tage.** Stammen die meisten Tage eines Monats aus
einem langen Abstand, ist er verteilt, auch wenn die wenigen gemessenen Tage mehr
Verbrauch tragen: Im unordentlichen Testhaushalt kam der Dezember 2024 zu 18 von
31 Tagen aus einem Abstand von 103 Tagen und galt bis v3.8 als gemessen, weil der
verteilte Teil die milderen Herbstmonate mitnimmt und damit eine zu niedrige
Tagesrate hat. So ging er in die Anomalien ein.

Maßgeblich ist die **Länge des Abstands**, nicht, ob im Monat abgelesen wurde:
Ablesungen am 31. Januar und am 1. März messen den Februar gut. Ein Abstand bis
45 Tage reicht über einen vollen Monat um höchstens zwei halbe Wochen hinaus; die
Verteilung verschiebt dann so wenig, dass es unter der Schwelle bleibt, ab der
die Anomalie-Erkennung überhaupt meldet. Eine **geschätzte Ablesung**
(`is_estimated`) misst nichts: Der Abstand reicht von der echten Ablesung davor
bis zur echten danach. Bei Zählern mit Zeiträumen ist der Zeitraum der Abstand.
Heizöl und Pellets tragen keine Kennung (`null`), ihre Monate sind ohnehin nach
Gradtagen modelliert.

**Verteilte Monate zählen nicht, wo die App aus Monaten lernt** — Anomalien
(Abschnitt 9), Empfehlungen, Heizmodell und Heizkurve (Abschnitt 5), das
Saisonprofil der Prognose (Abschnitt 6) und Trends gegen das Vorjahr. Ihr Wert
sagt nichts darüber, was in genau diesem Monat verbraucht wurde; als Messung
behandelt, ergab er überzeugend wirkende Warnungen und ein verzerrtes
Heizmodell. **Die Monatswerte selbst, Kosten, Abschläge und Saldo bleiben, wie
sie sind** — die Summe zwischen zwei Ablesungen ist bekannt und stimmt.
Verbrauchsart, Analyse und PV-Übersicht sagen, wenn Monatswerte verteilt sind,
mit der Zahl der Ablesungen im Zeitraum; Tabellen zeigen „≈“, Diagramme
schraffierte Säulen.

**Grenze (Stand v3.9.0):** Innerhalb eines Abstands verteilt die App
gleichmäßig je Tag, nicht nach dem Wetter. Ein Abstand von September bis
Dezember gibt dem Oktober deshalb zu viel Gas und dem Dezember zu wenig; bei
der PV bekommt in einem Abstand von Februar bis Juli der März so viel Sonne wie
der Juni. Nach dem Wetter verteilt sie nur
über einen Stand „nur für die Abrechnung“ hinweg (unten). Verbrauch allgemein
nach Wetter zu verteilen ist für eine spätere Version vorgemerkt. Bis dahin
helfen kürzere Abstände: Jede Ablesung macht die Monate daneben genauer.

### Ablesung prüfen *(seit v3.9.0)*

Ein falscher Stand zwischen zwei richtigen verschiebt Verbrauch still von einem
Abschnitt in den nächsten. Die Summe stimmt, aber Monatswerte, Abrechnungsjahr,
Heizmodell (und damit der Heizgrenze-Vorschlag) und Prognose nicht. Die App sucht
solche Stände und fragt nach. Sie korrigiert nie selbst.

**Erwartung je Abstand.** Bei Gas, Fernwärme, Heizwärme und dem Strom einer
Wärmepumpe das Heizmodell `a × HGT + c × Tage` mit der Heizgrenze des Zählers (bzw.
seiner Gruppe), gefittet über die Ablesungsabstände. Bei Strom und Wasser die mittlere
Tagesmenge der Abstände bis ein halbes Jahr davor und danach. Bei PV, Gartenwasser und
anderen Zählern mit ausgeprägten Jahreszeiten (die Tagesrate des Sommerhalbjahrs, April
bis September, und die des Winterhalbjahrs liegen um mehr als das Doppelte auseinander,
je Halbjahr der Median der Monate) dieselben Tage in anderen Jahren. Ebenso bei einer milderen Jahreszeit,
die wiederkehrt: Liegt das Sommerhalbjahr in mindestens zwei Jahren gleichsinnig
mindestens ein Viertel über oder unter den Wintern daneben (gezählt aus Abständen, die
überwiegend in einer Jahreshälfte liegen), sagt die mittlere Tagesmenge im Winter „zu
wenig“ und im Sommer „zu viel“, obwohl alles stimmt. Der Ladepunkt einer Wallbox wird
nicht geprüft: Geladen wird, wenn gefahren wurde.

**Ein Kandidat** ist ein Stand oder ein Block von bis zu sechs aufeinanderfolgenden
Ständen (höchstens ein halbes Jahr vom ersten bis zum letzten), bei dem

```text
Abstand davor   deutlich unter der Erwartung   (bzw. darüber)
Abstand danach  deutlich über der Erwartung    (bzw. darunter)
im Block        jeder Abstand normal
Summe           |Abweichung davor + Abweichung danach| ≤ ½ × Versatz
Nachbarn        der Abstand vor dem ersten und der nach dem letzten weichen
                nicht in dieselbe Richtung ab
Versatz         (Abweichung danach − Abweichung davor) ÷ 2
```

**Deutlich** heißt alle drei zugleich: gemessen unter 3/4 bzw. über 4/3 der
Erwartung, mindestens ein Viertel eines typischen Monats (Verbrauch der letzten
zwölf Monate ÷ 12) und mindestens drei Streuungen des Modells (beim Heizmodell dessen
Streuung je Monat, auf die Länge des Abstands umgerechnet; bei anderen Jahren die
Streuung zwischen den Jahren, mindestens ein Fünftel der Erwartung). Für den Kandidaten
rechnet die App die Erwartungen noch einmal ohne die geprüften Abstände.

Warum so: Eine schnelle Prüfung „unter 0,5× oder über 2×“ meldete an einem echten
Haushalt elf Abstände, davon war einer ein falscher Stand. Sechs waren kurze Abstände im
Sommer mit großem Verhältnis und wenigen kWh (deshalb die Mindestmenge), zwei ganze
Jahre auf anderem Niveau (deshalb die Nachbarn und der Hinweis auf die Zäsur unten). Der
echte Fall war ein Block: drei Stände um denselben Betrag zu niedrig, im Block jeder
Abstand normal. Ein Prüfer, der nur Nachbarpaare ansieht, findet ihn nicht. Und ein
Zahlendreher in einem Wintermonat (900 von 1.845 kWh) liegt bei 1,49× und 0,43×, die
schnelle Prüfung hätte ihn übersehen.

**Zweite Stufe: Es passt gar nicht zusammen.** Liegt der Abstand davor höchstens bei
der Hälfte und der danach mindestens beim Doppelten der Erwartung (oder umgekehrt),
beide über der Mindestmenge und den Streuungen, fragt die App auch ohne passende Summe.
Im Block dürfen die Abstände dann weiter in dieselbe Richtung abweichen, nur nicht schon
zurück. Das ist der Fall, in dem Stände nach und nach falscher werden: In einer
erfundenen Nachprüfung lag der Abstand davor bei 0,23×, die beiden im Block bei 0,78× und 0,69×,
der danach bei 4,08×, und der Überschuss danach war rund 2,4-mal so groß wie der Mangel
davor. Kein gemeinsamer Versatz erklärt das, also schlägt die App keinen Stand vor; der
Eintrag heißt „Verbrauch vor und nach dem … passt nicht zusammen“, mit denselben
Knöpfen.

**In „Zu tun“** steht dann „Ablesung vom … prüfen“ mit gemessen gegen erwartet für
den Abstand davor und danach und der Vermutung („vermutlich um rund 900 m³ zu hoch“),
an der Ablesung in der Liste die Marke „AUFFÄLLIG“. Drei Knöpfe, alle mit Rückgängig:

- **Stand ist richtig:** Die App fragt bei diesem Stand nie wieder (`is_verified`). Wer
  den Stand später ändert, hebt das auf.
- **Nur für die Abrechnung:** Der Stand bleibt für Rechnung und Saldo (Rechnung prüfen,
  Abrechnungsjahr, Kosten, Abschläge), aber Monatswerte, Heizmodell und Prognose
  überspringen ihn (`is_billing_only`). Der Verbrauch von der Ablesung davor bis zur
  nächsten wird **nach dem Wetter** verteilt: je Tag `a × HGT + c` aus demselben
  Heizmodell (bei Arten ohne Heizmodell gleichmäßig). So passt das, wenn der Versorger
  mit einem geschätzten oder falschen Stand abgerechnet hat: Seine nächste Rechnung
  gleicht es aus, und bis dahin rechnet der Saldo wie er.
- **Korrigieren:** öffnet den Bearbeiten-Dialog mit dem Stand, der passen würde.

Warum ein eigener Merker und nicht „geschätzt“: `is_estimated` ändert nur die Kennung
(ein geschätzter Stand misst nichts, die Monate daneben gelten als verteilt), die
Verteilung geht weiter durch ihn. Geschätzte Stände kommen oft vom Versorger und sind
meist brauchbar; sie alle zu überbrücken, hätte Monatswerte geändert, die niemand
angefasst hat.

**Doppelt eingetragen:** Derselbe Stand am selben Tag zweimal ergibt „Ablesung vom …
doppelt eingetragen“ mit „Eine löschen“ (behalten wird die mit Foto, sonst die mit
Notiz).

**Anderes Niveau, kein Ablesefehler:** Lag der Verbrauch mindestens ein Jahr am Stück
gleichbleibend weit über bzw. unter heute (jeder nennenswerte Abstand mindestens ein
Viertel darüber bzw. darunter, zusammen mindestens das Anderthalbfache), ohne
Gegenbewegung danach (höchstens ein Zehntel der Tage danach wieder auf dem alten
Niveau; dafür zählt ein Abstand nur, wenn er selbst oder einer am Stück daneben auch
um mindestens die Mindestmenge von heute abweicht, ein kurzer Sommerabstand mit wenigen
kWh mehr ist keine Rückkehr), schlägt die App eine [Analyse-Zäsur](#wirkung-einer-maßnahme-zäsur) am Tag
des Wechsels vor: „Zäsur hier setzen“ (ein Dialog mit dem Ende der auffälligen Zeit als
Tag, zum Bestätigen oder Ändern) oder „Nicht jetzt“. Das heutige Niveau kommt aus
mindestens einem halben Jahr nach dem Wechsel (beim Heizmodell ab vier Abständen, auch
wenn danach erst wenige Ablesungen vorliegen). Ein gleitender Übergang ist erlaubt:
Direkt vor der Zeit danach darf ein einzelner Abstand zwischen altem und heutigem
Niveau liegen, der Tag des Wechsels ist dann das Ende des letzten Abstands auf altem
Niveau. Mit einer wirksamen Zäsur zählt nur die Zeit danach; steht sie am Wechsel,
entfällt der Hinweis. Die Zeit vor einem erkannten Wechsel ist kein Maßstab für
einzelne Stände. Bei einer wiederkehrenden Jahreszeit rechnet der Hinweis mit der
mittleren Tagesmenge über die Zeit danach (über ein ganzes Jahr gleicht sich die
Jahreszeit aus).

**Grenzen.** Die Prüfung ist bewusst vorsichtig: An keinem Beispielhaushalt und
nicht am unordentlichen Testhaushalt meldet sie etwas, solange alle Stände
stimmen. Dafür übersieht sie kleine Fehler. Passt das Heizmodell schlecht (große
Streuung), fragt sie erst bei großen Versätzen; eine passende Heizgrenze macht
sie empfindlicher. Bei Strom mit starker Jahreszeit (der Winter unter vier
Fünfteln des Mittels) zählt nach einem Einzug jeder Winter als Rückkehr zum
alten, niedrigeren Niveau, dann kommt kein Hinweis auf die Zäsur. In einem
Monat, den ein Stand „nur für die Abrechnung“ überbrückt, passen Menge und
Kosten nicht zueinander: Die Menge ist nach dem Wetter verteilt, die Kosten
rechnen mit dem Stand (`billed_kwh` sagt, womit).

Alle drei Einträge zählen in `/api/todo` und in den Benachrichtigungen an Home
Assistant mit, in jeder Stufe.

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
Jahressumme — für Heizgrenzen von 6 bis 22 °C in halben Grad, weil
Gradtage nicht aus Monatsmitteln folgen. Neu geladen wird, wenn sich der
Standort um mehr als rund 5 km verschiebt oder ein weiteres Jahr
abgeschlossen ist.

**Seit v3.7.0 ab 6 °C** (bis v3.6 ab 10 °C): Bei echten Daten passte das
Heizmodell weit unter 15 °C am besten, und für niedrigere Heizgrenzen rechnete
das Normal still mit 10 °C. Ein Normal ohne die neuen Rasterpunkte gilt als
veraltet und wird beim nächsten Wetterabgleich einmal neu geholt — ein
Archivabruf wie beim ersten Mal. Bis dahin, und für eine Heizgrenze außerhalb
des Rasters, gibt es für diese Grenze kein Normal: Die App rechnet mit der
eigenen Temperaturhistorie und sagt es (`climate_normal_grid`), statt mit der
nächsten Rastergrenze zu rechnen.

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

**Warum ein Modell nicht gilt (seit v3.7.0):** `reason` sagt es —
`too_few_points` (unter den Mindestpunkten), `poor_fit` (die Sigmoide mit
`R² < 0,5`) oder `degenerate` (kein Unterschied in den Werten). Bis v3.6 hieß
jeder ungültige Fit „zu wenige Datenpunkte“, auch bei 50 Punkten. Gilt das in
der Prognose gewählte Modell nicht, rechnet sie ohne Heizkurve weiter und sagt
es (`regression_invalid`).

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

**Verteilte Monate gehen nicht ein (seit v3.7.0, Abschnitt 1).** Bleiben
dadurch weniger als 8 gemessene Monate, rechnet das Modell über die
**Ablesungsabstände selbst**: Das Modell ist eine Summe über Tage und gilt für
ein Vierteljahr genauso wie für einen Monat. Jeder Abstand zwischen zwei echten
Ablesungen ist ein Punkt (Verbrauch, Heizgradtage und Tage des Abstands),
gewichtet mit `30,4 / Tage` — die Streuung wächst mit der Länge, ein
Vierteljahr zählt wie drei Monate. Auch hier mindestens 8 Punkte; sonst gibt
es kein Heizmodell, und die App sagt es, statt still über verteilte Monate zu
rechnen. Die API nennt die Grundlage (`heat_basis`: `months` oder
`intervals`).

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

**Warum „bereinigt“ gleich dem Rohwert ist (seit v3.7.0):**
`heat_adjusted_basis` nennt den Grund — `summer` (höchstens
`min_hdd_regression` Gradtage), `base_load` (Verbrauch unter der Grundlast),
`normal_is_actual` (kein Klimanormal und nur ein Jahr eigene Temperaturen für
diesen Monat: Das „Normal“ ist der Monat selbst) — sonst `model`. Der letzte
Fall ist bei echten Daten ein Kandidat für „bereinigt = roh in vielen gemessenen
Monaten“.

### Heizgrenze-Vorschlag *(seit v3.7.0)*

Die Heizgrenze (`hdd_base_temp`, Standard 15 °C) bestimmt, ab welcher
Tagestemperatur Gradtage zählen. Die Analyse rechnet die gemessenen Monate mit
jeder Heizgrenze von 6 bis 18 °C in halben Grad durch und misst die Streuung wie
die Gütesperre (Abschnitt 9). Vorgeschlagen wird die Grenze mit der kleinsten
Streuung, deren Modell auch die Grundlast-Prüfung besteht — aber nur, wenn sie
**deutlich** besser ist:

```text
Vorschlag  ⇔  Streuung_neu ≤ 2/3 × Streuung_heute   (mindestens 33 % weniger)
              und Streuung_heute − Streuung_neu ≥ 3 Prozentpunkte
              und mindestens 12 gemessene, geprüfte Monate
```

Die robuste Streuung schwankt bei zwei Jahren Monatswerten um rund ein Fünftel
ihres Werts; ein Drittel weniger liegt deutlich darüber, und die Prozentpunkte
verhindern Vorschläge zwischen zwei gut passenden Modellen.

*Seit v3.7.1 (GitHub #38)* muss die vorgeschlagene Grenze außerdem den gemessenen
Sommer treffen: Ihr Modell darf die Sommermonate der Gütesperre (für jede Grenze
dieselben, Abschnitt 9) um höchstens 10 % überschätzen (`summer_ok` im Raster).
Ein einzelner Sommermonat schwankt im Alltag um 10 bis 20 %, das Mittel über die
Sommer mehrerer Jahre deutlich weniger. Beim Melder lag das Modell der bis dahin
vorgeschlagenen niedrigen Grenze im Juli und August rund ein Viertel über dem
Gemessenen; so eine Grenze wird nicht mehr angeboten.

Die Grenze gilt **je Zähler** (`hdd_base_temp` am Zähler, 6 bis 22 °C wie das
Raster des Klimanormals); die Einstellung bleibt der Standard aller Zähler ohne
eigene — die beste Grenze ist je Haus verschieden. Mit ihr rechnen Gradtage,
Heizmodell, Bereinigung, Saldo-Schätzung und Prognose dieses Zählers. Übernommen
wird nie automatisch: ab der Stufe Erfahren per Knopf in der Analyse, dort lässt
sie sich auch zurücknehmen; darunter steht der Satz mit dem Stufenhinweis. Bei
echten Daten lag das Optimum weit unter 15 °C; ein erfundener Haushalt in derselben
Form (Grundlast 6,5 kWh am Tag, Heizung erst unter 12 °C) zeigt bei 15 °C eine
Grundlast von 1,25 statt 6,5 kWh am Tag und schlägt 12 °C vor
(`RealDataFollowUpTest`).

**Ruhigeres Raster (seit v3.9.0, GitHub #38).** Bei zwei Jahren Monatswerten
springt die Streuung von Rasterpunkt zu Rasterpunkt um ein Viertel und mehr. Beim
Maintainer lag das Minimum bei einer einzigen Grenze in einem schmalen Tal, und
genau dort verwarf der Robustheitsschritt des Fits ein Viertel der Monate: Das
Modell passte auf den Rest sehr gut, der Rückblick auf Mai bis September lag mit
dieser Grenze ein Drittel über dem Gemessenen. Seitdem gilt:

```text
geglättet(g)   = Mittel der Streuung aller Grenzen bis 1 °C neben g   (fünf Rasterpunkte)
wählbar        ⇔ Grundlast-Prüfung bestanden, Sommer höchstens 10 % überschätzt,
                 Mai bis September zusammen höchstens 10 % überschätzt,
                 und der Fit verwirft höchstens jeden zehnten Monat (mindestens einen)
                 als echten Ausreißer (mehr als 10 % eines typischen Monats daneben)
Plateau        = lückenlos anschließende wählbare Grenzen, geglättet höchstens 15 %
                 über der besten
Vorschlag      = die Grenze des Plateaus, die der heutigen am nächsten liegt
deutlich besser: geglättet ≤ 2/3 × Streuung heute und mindestens 3 Prozentpunkte weniger
```

Fast rauschfreie Monate (±2 %) fallen bei winziger Streuung ebenfalls aus dem Fit,
liegen aber innerhalb des Alltagsrauschens und zählen nicht als Ausreißer. Die
Spanne des Plateaus steht im Vorschlag (`range`): „Zwischen 10,5 und 11,5 °C passt
das Modell etwa gleich gut“ (`HeatLimitFollowUpTest`, auch an einer verrauschten
Reihe und am unordentlichen Testhaushalt).

**Wo der Vorschlag steht (seit v3.9.0).** Unter „Zu tun“ auf der Übersicht
(Agenda `heat_limit`, Empfehlung der Kategorie `modell`) mit vorher und nachher und
der Wirkung auf den erwarteten Saldo und den Abschlagsvorschlag des laufenden
Vertrags, mit „Übernehmen (nur dieser Zähler)“ und „Nicht jetzt“. „Nicht jetzt“
blendet genau diesen Datenstand aus (vorgeschlagene Grenze und Zahl der geprüften
Monate stecken in der Kennung der Empfehlung); kommt ein gemessener Monat dazu,
erscheint er wieder. Derselbe Hinweis steht an der Saldo-Karte, in der Prognose und
in der Werkstatt, wenn deren Schätzung wegen schlechter Passung unsicher ist
(`poor_fit`). Übernehmen geht dort in jeder Stufe und lässt sich rückgängig machen;
die Analyse mit Raster und Zurücknehmen bleibt ab Erfahren. Eine eigene Grenze
haben auch **Heizöl und Pellets** (sie verteilen ihre Lieferungen nach den
Gradtagen dieser Grenze) und **Zählergruppen** (`hdd_base_temp` an der Gruppe: Die
Mitglieder rechnen für die Summe mit ihr, die Erwartung der Summe kommt aus dem
Heizmodell der Summe). Einen Vorschlag gibt es für Zähler mit Zählerständen und
für Gruppen, nicht für Tanks (deren Monate sind nach Gradtagen verteilt).

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

**Ohne Heizkurve (seit v3.1.0).** Strom, Wasser und PV haben keine Steigung je
Gradtag. Für sie vergleicht die App das Tagesmittel je Kalendermonat vor und
nach der Zäsur — nur Monate, die es in beiden Phasen gibt (mindestens drei):

```text
vorher/nachher je Jahr = Σ (Tagesmittel des Monats × Tage des Monats) × 365 / Σ Tage
Δ%                     = (nachher − vorher) / vorher × 100
95-%-Bereich           = Δ% ± 1,96 × Streuung der Monatsverhältnisse (nachher/vorher) / √n × 100
```

Belegt ist der Unterschied, wenn der Bereich 0 nicht einschließt. Das Wetter
ist hier nicht bereinigt (`method: "seasonal_mean"`; die Heizkurven-Variante
heißt `hdd_slope`).

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

**Seit v3.7.0 nur, wo die Heizkurve etwas weiß:** Gibt es einen gemessenen
Saisonwert, zählt die Regression nicht in Monaten mit weniger Gradtagen als ihr
kleinster Punkt (der Sommer; die Kurve ist nur über Heizmonate gefittet) und
nicht, wenn das Heizmodell die Gütesperre nicht besteht (Abschnitt 9) — dann gilt
`seasonal_only`. Bei einer konvexen Heizkurve lag die Gerade im Sommer auf null;
mit Gewicht 0,8 fehlte so fast das ganze Warmwasser, bei echten Daten rund 23 %
im Jahr, auch in der Menge fürs Vergleichsportal.

Seit v3.9.0 zählt die Regression auch dort nicht, wo sie flach ist: beim
Knickmodell unter dem Knick (dort rechnet es das feste Niveau b), bei der
Sigmoide bis θ₀ (dort die Grundlast D). Gefittet ist b an allen Monaten unter
dem Knick, den Übergangsmonaten; Juni, August und September bekamen so 80 %
desselben Niveaus, statt ihres gemessenen Saisonwerts.

**Fehlt ein Kalendermonat in der eigenen Historie** (wer im Mai beginnt,
hat noch keinen Januar), kommt er seit v3.9.0 aus dem Heizmodell
(`heat_model`): a × Heizgradtage des Klimanormals zur Heizgrenze des
Zählers + c × Tage. Das gilt, wo das Modell so warme Monate selbst kennt
(mindestens zwei seiner Punkte mit höchstens 0,5 Gradtagen je Tag mehr als
das Normal des Monats). Sonst ist die Grundlast c dort geraten, gefittet nur
an Heiz- und Übergangsmonaten, und die Prognose nimmt die verteilten Werte
desselben Kalendermonats (`seasonal_interpolated`), wenn es sie gibt. Ebenso,
wenn das Heizmodell die Gütesperre nicht besteht oder seine Grundlast c unter
der Hälfte der mittleren Tagesrate gemessener Sommermonate (Juni bis August)
liegt; die Gütesperre prüft die Grundlast erst ab zwei Sommermonaten, diese
Prüfung schon mit einem. Verteilte Werte zählen dabei nur aus Abständen bis
62 Tagen: Innerhalb eines Abstands wird nach Tagen verteilt, nicht nach Wetter,
und ein Abstand über den Winter trägt Heizverbrauch in den Juli. Gibt es keine,
kommt der Monat aus dem Heizmodell; ist es nicht verlässlich, mit der gemessenen
Sommerrate (Juni bis August) als Grundlast, wenn sie über c liegt
(`heat_model_summer`). Ohne
Heizmodell kommt er aus der Regression allein (`regression_only`), sonst aus
dem Tagesmittel aller Monate (`filled`).

Bis v3.8 stand die Regression der Monatssummen vorn. Sie ist nur über Monate
mit Heizgradtagen gefittet und kennt keine Tage; das Knickmodell gab jedem
Monat unter dem Knick dasselbe Niveau, das der Übergangsmonate (Mai = Juli),
und die Gerade setzte Sommermonate auf ihren Achsenabschnitt. Bis v2.7 bekam
ein fehlender Monat 0 Gradtage und den Saisonwert 0 — der Januar lag bei
weniger als einem Zehntel des richtigen Werts. `warnings` meldet eine kurze
Historie (`history_short`) und fehlendes Klimanormal (`no_climate_normal`).

**Verteilte Monate (seit v3.7.0)** bilden das Saisonprofil nicht. Wurde ein
Kalendermonat nie gemessen und hilft kein Modell (oder kennt das Heizmodell so
warme Monate nicht, s. o.), nimmt die Prognose für ihn
die verteilten Werte (`seasonal_interpolated`) — für einen Gartenzähler, der im
Winter nicht abgelesen wird, ist das besser als das Mittel des ganzen Jahres.
Der Hinweis `interpolated` nennt die Zahl der verteilten Monate und der
Ablesungen; das Tagesmittel aller Monate (`filled`) stimmt auch für verteilte.
`history_short` zählt nur **gemessene** Kalendermonate: Wer zwei Jahre lang nur
zweimal im Jahr abliest, hat zwar 24 Monatswerte, aber keinen gemessenen Monat.
Die Prognose-Werkstatt gibt den Hinweis `interpolated` weiter; „Wie im Vorjahr“
nimmt verteilte Vorjahresmonate wie fehlende aus der Prognose.

**Jahresmenge der letzten 365 Tage (seit v3.9.0).** Ohne Wetterbezug (Strom,
Wasser) und ohne zwölf gemessene Kalendermonate ist das Profil ein Flickwerk:
gemessene Monate aus einem Jahr, verteilte als Mittel aller Jahre. Bei echten
Strom-Daten mit fast nur verteilten Monaten lag die Prognose im Rückblick durchweg
zu tief; im unordentlichen Testhaushalt senkten vier Sommer-Ablesungen die
Jahresmenge um gut 5 %, obwohl der Verbrauch derselbe blieb, und ein Jahr mit 25 %
mehr Verbrauch kam als Mittel dreier Jahre an (−13 %). Dann gilt:

```text
Jahresmenge  = Verbrauch der letzten 365 Tage (Monatswerte, der älteste anteilig nach Tagen)
Monat        = Profilwert × Jahresmenge ÷ Σ Profilwerte der zwölf Prognosemonate
```

Zwischen zwei Ablesungen stimmt die Summe auch verteilter Monate. Der Hinweis
`level_last_year` nennt Menge und Zeitraum. Heizarten rechnen weiter mit Wetter
und Heizmodell; mit zwölf gemessenen Kalendermonaten bleibt das eigene Profil.
Ändert sich der Verbrauch mitten im Jahr (neue Wärmepumpe), hinkt auch diese
Menge bis zu einem Jahr nach.

**Anteil der Heizkurve (seit v3.9.0).** `blend_weight` ist das Gewicht laut
Modellgüte, `blend_effective` der Teil der Jahresmenge, der wirklich aus der
Heizkurve kommt. Monate unter den Punkten der Heizkurve oder bei nicht
bestandener Gütesperre rechnen mit dem Saisonprofil; bis v3.8 stand dann
„Anteil Heizkurve 80 %“ neben „Saisonprofil“ in jedem Monat. Ein Monat aus dem
Heizmodell (`heat_model`) zählt ganz zur Heizkurve.

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

**Seit v3.7.0 nur bei bestandener Gütesperre.** Passt das Heizmodell schlecht
(Abschnitt 9), gibt es kein Band (`annual.low/high = null`,
`annual.withheld = "poor_fit"`) und den Hinweis `poor_fit`: Bei echten Daten
traf das 80-%-Band in keinem von fünf Rückblicken, weil das Modell selbst
danebenlag. Die Prognose-Werkstatt zeigt dann weder Band noch Heizanteil und
statt des Abschlags eine Spanne — Dämmung und Raumtemperatur wirken auf einen
Heizanteil, den das Modell nicht kennt.

**Zu wenig gemessen (seit v3.7.0).** Steht das Heizmodell nur auf den
Ablesungsabständen, oder kann die Gütesperre es nicht prüfen (unter 5 gemessenen
Monaten), gibt es ebenso kein Band (`annual.withheld = "few_measured"`, Hinweis
`few_measured`); Saldo und Werkstatt nennen eine Spanne (Abschnitt 10).

**Ohne Wetterbezug** (Strom, Wasser) ist `σ_Rest` die gepoolte Streuung der
Tagesrate je Kalendermonat über die Jahre, mit Σ(n − 1) Freiheitsgraden. Unter 3
Freiheitsgraden gibt es kein Band (`annual.withheld = "few_years"`): Mit einem Wert
je Kalendermonat ist jede Abweichung null — bis v3.6 hatte das Band bei Wasser so
die Breite null und bei Strom mit zwei Jahren ±1 bis 2 %. Je Monat gilt mindestens
die eigene Streuung des Kalendermonats und 10 % seines Werts, dieselbe Untergrenze
wie in der Anomalie-Erkennung (Abschnitt 9). Das Saisonprofil ohne Wetterbezug nimmt
ab drei Werten je Kalendermonat den **Median**: Ein Urlaubsmonat mit halbem Wert
verschiebt die Prognose dann nicht; bei zwei Werten trägt das Band dieses Monats
den Widerspruch der Jahre. Bei Heizarten bleibt es das Mittel — dort ist der Wert
eines Kalendermonats vor allem das Wetter seines Winters.

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
(`efficiency_class_thresholds`, Default A+ bis 30, A bis 50, B bis 75,
C bis 100, D bis 130, E bis 160, F bis 200, G bis 250, sonst H) ein. Seit
v2.10.0 gilt eine Grenze **einschließlich** („bis 100" ist C), wie in
Anlage 10 des GModG (Gebäudemodernisierungsgesetz, bis Juli 2026 GEG); vorher
lag ein Wert genau auf der Grenze eine Klasse schlechter. Die Grenzen bleiben
auch ab 01.01.2027 dieselben: Art. 2 Nr. 54 des Gesetzes vom 23.07.2026
(BGBl. 2026 I Nr. 226) ändert an Anlage 10 nur die Überschrift („zu § 86
Absatz 1“) und den Spaltenkopf, „um die Effizienzklassen unverändert zu
lassen“ (Begründung, BT-Drs. 21/6278, S. 122).

**Klassen nur für ganze Jahre (seit v2.10.0).** Deckt das Bezugsjahr
weniger als 360 Tage ab, gibt es keine Klasse, und ein Hinweis sagt, dass
die Kennzahl nur für den gemessenen Zeitraum gilt. Wer im Juni anfing, bekam
bisher für sein halbes Jahr ohne Winter eine Traumklasse.

**Zwei Zahlen (seit v2.10.0).** Die Hauskennzahl oben bleibt, wie sie ist:
Verbrauch je m² Wohnfläche, so wie gemessen. Daneben steht eine
**energieausweis-nahe** Kennzahl (`certificate`):

```text
AN        = Wohnfläche × 1,2      (× 1,35 bei Wohngebäuden mit bis zu zwei Wohnungen und beheiztem Keller,
                                   § 82 Abs. 2 GModG, bis Juli 2026 GEG; in der App EFH oder Reihenhaus)
E         = Σ Heizquellen: witterungsbereinigter Jahresverbrauch, Brennstoffe je nach Verfahren (unten)
Kennzahl  = E / AN  (+ Warmwasser-Zuschlag bei dezentralem Warmwasser, je nach Verfahren)
```

**Welches Verfahren (seit v3.5.0).** Am 01.01.2027 ändert sich, wie ein
Verbrauchsausweis rechnet (§ 82 GModG in der Fassung von Art. 2 Nr. 32 des
Gesetzes vom 23.07.2026, BGBl. 2026 I Nr. 226; in Kraft nach Art. 9 Abs. 2).
Die App rechnet die Kennzahl so, **als würde der Ausweis heute ausgestellt**:
bis 31.12.2026 nach dem bisherigen Verfahren, ab 01.01.2027 nach dem neuen —
für jedes Bezugsjahr. Maßgeblich ist das Ausstellungsdatum, nicht der
Zeitraum der Daten; so unterscheidet auch das Gesetz alte und neue Ausweise
(§ 112 Abs. 3 GModG n. F.: Ausweise, die „vor dem 1. Januar 2027 ausgestellt“
wurden). Die API nennt das Verfahren im Feld `rules`.

```text
                      bis 31.12.2026 (geg2024)              ab 01.01.2027 (gmodg2027)
Brennstoffe           Heizwert: Gas × 0,906                 Brennwert: Gas wie gezählt (× 1)
                      (Bekanntmachung vom 29.03.2021,       („Brennstoffe sind mit ihrem Brennwert
                      BAnz AT 16.04.2021 B1, Abschnitt 2)   zu berücksichtigen“, § 82 Abs. 2 Satz 2)
Warmwasser dezentral  + 20 kWh/m²·a (§ 82 Abs. 2)           + 16 kWh/m²·a (§ 82 Abs. 2 Satz 3)
Datenzeitraum         36 Monate (§ 82 Abs. 4)               „jährlich über einen Zeitraum von zwei
                                                            Jahren“ (§ 82 Abs. 1 Satz 2) = 24 Monate
```

Fernwärme, Heizwärme und Wärmepumpen-Strom sind keine Brennstoffe und werden
in keinem Verfahren umgerechnet. **Heizöl und Pellets** zählt die App nach
Heizwert (kWh je Liter bzw. kg). Den Faktor zum Brennwert nennt die
Bekanntmachung nur über eine nicht frei zugängliche Norm (DIN V 18599-1,
Tabelle B.1); ab 2027 bleiben sie deshalb beim Heizwert, und die API führt sie
in `fuel_basis_approx` als Näherung — die Kennzahl ist für sie etwas zu
niedrig. Ein Gashaus mit gleichem Verbrauch bekommt ab 2027 eine um rund 10 %
höhere Zahl (1 / 0,906) bei gleichen Klassengrenzen; dieselbe Wohnung kann
also eine andere Klasse bekommen.

Vor dem 01.01.2027 zeigt die Übersicht unter der Kennzahl, was das neue
Verfahren ändert und welche Zahl es ergäbe (API: `upcoming`).

Bereinigt wird Monat für Monat mit dem Heizmodell (`heat_adjusted`), wenn
jeder Monat des Jahres einen solchen Wert hat — bei Gas und Fernwärme mit
Heizmodell der Normalfall. Sonst (Heizöl, Pellets, Wärmepumpe, Monate ohne
Modell oder vor einer Zäsur) wird über das Jahr mit dem Klimanormal
bereinigt (nur der Heizanteil, VDI 3807). Ohne Klimanormal steht der
Wert wie gemessen da und ist so gekennzeichnet. Ein **Verbrauchsausweis ist
das nicht**: Der rechnet den ganzen Zeitraum (36 Monate, ab 2027 zwei Jahre)
mit den Klimafaktoren des DWD, ab 2027 dazu normalisiert auf die
Nutzungsrandbedingungen nach DIN/TS 18599-10 (§ 82 Abs. 3 n. F.); die App
rechnet ein Bezugsjahr und bildet die Normalisierung nicht nach. Die
Einstellungen *Beheizter Keller* und *Warmwasser dezentral* und die Rolle
*Wärmepumpe (Heizstrom)* an einem Stromzähler (bis v3.0 das Kennzeichen
*Heizstrom (Wärmepumpe)*) fließen hier ein — mit dieser Rolle bekommt auch ein
Wärmepumpenhaus eine Kennzahl. Seit v3.1.0 zählt auch die **Heizwärme** mit,
aber nur Zähler mit der Rolle *Verbrauch der Wohnung*
([Heizwärme](15-waerme.md#4-effizienzkennzahl)).

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

mit artspezifischem Faktor (`co2_gas`, `co2_strom`, …), bezogen auf die
Einheit, in der die App zählt. Seit v2.10.0 hat jeder Default eine Quelle:

| Faktor | Default | Quelle |
|---|---|---|
| Gas | 182 g/kWh | BAFA-Infoblatt CO₂-Faktoren (2026): 201 g/kWh bezogen auf den **Heizwert**; die App zählt Gas nach Brennwert, daher × 0,906 |
| Strom | je Jahr, 2015–2025 (2025: 344 g/kWh) | Umweltbundesamt, Emissionsfaktor Strommix (`co2_strom_years`); nach dem letzten Jahr gilt dessen Wert, davor `co2_strom` (380) |
| Heizöl | 266 g/kWh | BAFA, bezogen auf den Heizwert — so rechnet die App Heizöl |
| Pellets | 36 g/kWh | BAFA, CO₂-Äquivalente inklusive Vorkette (bis v2.9: 26) |
| Fernwärme | 280 g/kWh | BAFA-Pauschale; der Wert des eigenen Netzes steht beim Versorger (bis v2.9: 180) |
| Wasser | 350 g/m³ | grober Richtwert ohne belegte Quelle |

Bis v2.9 galt für Strom ein Wert für alle Jahre, und der Gasfaktor bezog
sich auf den Heizwert, während die App Brennwert-kWh zählt (+10 %).

**Bestandsinstallationen behalten ihre Zahlen.** Die Migration auf
Schema 1.6.0 schreibt bei jeder Installation, die einen dieser Werte nie
gespeichert hat, den bisherigen Default fest (Lektion 36: Ein Update ändert
keine Zahl, die der Nutzer nicht selbst angefasst hat). Die Einstellungen
zeigen dann „Neuere Standardwerte verfügbar" mit bisher und neu — übernommen
wird nur auf Knopfdruck. Dasselbe gilt für die Wasser-Referenz (127 → 122 L
je Person und Tag, BDEW 2024). Neue Installationen starten mit den neuen
Werten.

**PV** vermeidet CO₂, statt es auszustoßen: Erzeugung und Einspeisung
stehen als „vermieden" da, im Jahresbericht nur einmal
([PV](12-pv.md#5-co₂-als-vermieden)). Seit v3.1.0 kann statt des Strommixes
ein eigener Vermeidungsfaktor gelten (`co2_pv_avoided`).

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
weniger als `min_days_period` Tagen, Monate mit Zählertausch, Monate
vor der Zäsur und seit v3.7.0 **verteilte Monate** (Abschnitt 1) — ihr Wert ist
die Tagesrate eines längeren Ablesungsabstands, eine „Abweichung“ davon wäre
ein Artefakt der Verteilung; unter 5 verwertbaren Monaten gar nichts.

**Gütesperre (seit v3.7.0).** Das lineare Heizmodell trifft eine gekrümmte
Heizkurve schlecht; dann misst die Erkennung vor allem den eigenen
Modellfehler, und eine leere Tabelle hieße nicht „alles in Ordnung“. Die App
prüft deshalb zweierlei, beides nur über gemessene Monate:

```text
Verhältnis       = σ_robust / mittlerer gemessener Monat   (Mittel der geprüften Monate)
Grundlast        = Erwartung / Verbrauch gemessener Sommermonate
                   (ohne Heizgradtage zu 18 °C, sonst Juni bis August; mindestens 2 Monate;
                   gemessen als Median der Tagesraten)
Grundlast c      = c × Tage / Verbrauch derselben Monate   (seit v3.9.0, die Untergrenze)
model_quality    = poor, wenn Verhältnis > 15 % oder Grundlast außerhalb 0,5 … 1,5
                   (seit v3.9.0 zählt nach unten Grundlast c, nach oben die Grundlast)
detect_floor     = anomaly_threshold × max(σ_robust, 10 % des typischen Monats)
```

**Untergrenze mit der Grundlast selbst (seit v3.9.0, GitHub #38).** Bis v3.7.1 maß
auch die Untergrenze die ganze Erwartung der Sommermonate. Bei hohen Heizgrenzen
fällt `c` auf null, aber die kühlen Sommertage bekommen Heizgradtage, und `a × HGT`
hob die Erwartung wieder über die Hälfte des Gemessenen. Beim Melder schlug die
Prüfung in v3.7.0 zwischen 14 und 16,5 °C an und bei 17 bis 18 °C nicht mehr. Die
feste Sommer-Referenz aus v3.7.1 änderte daran nichts, weil die Erwartung selbst
nicht monoton in der Grenze ist: Im unordentlichen Testhaushalt schlug sie mit
v3.7.1 von 12,5 bis 17,5 °C an und bei 18 °C nicht. Jetzt prüft die Untergrenze `c` (`base_load.base_ratio`,
`base_per_day`), die Obergrenze weiter die ganze Erwartung; die bestandenen Grenzen
sind ein zusammenhängendes Stück.

**Wiederkehrende Monate (seit v3.9.0).** Ein Kalendermonat, der in mindestens
zwei Jahren in dieselbe Richtung gemeldet wird und in jedem geprüften Jahr auf
derselben Seite der Erwartung liegt, ist ein **Muster** und kein Ereignis
(`anomalies[].pattern`, `pattern_years`): Bei einer gekrümmten Heizkurve liegen April
und Oktober jedes Jahr unter oder über der Geraden. Die Analyse fasst sie zu einer
Zeile je Monat zusammen und verweist auf die Heizgrenze; R1 und R4 machen daraus
keine Empfehlung mehr.

**Sommer für jede Heizgrenze derselbe (seit v3.7.1, GitHub #38).** Bis v3.7.0
waren die Sommermonate die mit HGT ≤ `min_hdd_regression` — gerechnet mit der
Heizgrenze des geprüften Modells. Bei einer niedrigen Grenze zählten so
Übergangsmonate mit echtem Heizbedarf als Sommer; die Referenz stieg, und ein
Modell mit zu hoher Grundlast bestand die Prüfung. Jetzt sind es gemessene Monate
ohne einen einzigen Heizgradtag zur obersten Grenze des Rasters (18 °C, `hdd_top`
der Monatszeile); gibt es davon keine zwei — in Mitteleuropa die Regel, fast jeder
Juli hat kühle Tage —, die gemessenen Monate Juni bis August, in allen
Länderprofilen die drei wärmsten. Jede Heizgrenze wird gegen dieselben Monate
geprüft (`base_load.reference`, `reference_months`).

Bei `poor` sagt die Abweichungstabelle das, nennt die Grenze `detect_floor`,
unter der sie nichts erkennt, bei der Grundlast auch Modell und Messung je Tag,
und verweist auf die Heizgrenze (Einstellungen › Verbrauchsarten & Abrechnung ›
Physikalische Konstanten, ab Stufe Erfahren). Ein gut passendes Modell erkennt
bei 2σ ab 20 % eines typischen Monats; über 15 % erst ab 30 %, anderthalbmal so
spät. 15 % ist auch die Grenze, die ASHRAE Guideline 14 für kalibrierte Modelle
auf Monatsdaten nennt. Gemessen wird gegen den **Mittelwert** der gemessenen
Monate, nicht gegen den typischen Monat der Erkennung: Dessen Median der
Erwartungen lässt die Sommer eines Modells mit Grundlast 0 fallen, das Modell
sähe rund ein Viertel besser aus, als es ist. Eine konvexe Heizkurve drückt die
Grundlast `c` fast auf null; die zweite Prüfung fängt das, auch wenn die
Streuung knapp unter 15 % bleibt. Sommerverbrauch schwankt im Alltag um 10 bis
20 %, weit innerhalb des Bandes. Das Modell selbst bleibt in v3.7.0 unverändert.

Was aus `poor` folgt: Die Prognose rechnet mit den gemessenen Monaten und zeigt
kein Band (Abschnitt 6), der Saldo nennt eine Spanne statt eines
Abschlagsvorschlags (Abschnitt 10), die Prognose-Werkstatt zeigt weder Band noch
Heizanteil, und die Analyse schlägt — wenn sie eine deutlich bessere findet —
eine andere Heizgrenze vor (Abschnitt 5). Seit v3.7.0 meldet außerdem **R1**
nichts, und der Jahresbericht vergleicht nicht witterungsbereinigt (keine
bereinigte Kachel, kein „kälter oder mehr verbraucht?“, im Anhang „–“ statt der
bereinigten Werte); an ihrer Stelle steht ein Hinweis. Hat die Abweichungstabelle
trotzdem Zeilen, sagt der Satz darüber, dass sie ebenso den Fehler des Modells
zeigen können — bis v3.6 sprach er auch dann von einer leeren Tabelle.

Die Empfehlungen bauen darauf auf: **R1** (Mehrverbrauch bei gegebenem
Wetter) misst Gas und Fernwärme am Heizmodell mit derselben Streuung und
Untergrenze (Schwelle `recommendation_anomaly_sigma`), **R4** meldet die
„hohen" Anomalien der Arten ohne Wetterbezug — PV ausgenommen, ein
ertragreicher Monat ist kein Warnsignal. **R2** (Trend) vergleicht die
witterungsbereinigten Werte (`heat_adjusted`) der letzten zwölf Monate
mit denselben Kalendermonaten des Vorjahrs, sobald mindestens neun
solche Paare vorliegen. R1 und R3 (Sommerverbrauch der Heizung) lassen
verteilte Monate aus; R2 nicht — zwölf Monate zusammen ändert die Verteilung
nicht, und die Bereinigung ist je Monat additiv. **Seit v3.7.0 melden R1, R3
und R4 nur Monate der letzten 24 Monate**; die Abweichungstabelle der Analyse
zeigt weiter alle. Ein Ausreißer von vor drei Jahren ist keine Handlung mehr,
und „30 Tage ausblenden“ soll ihn nicht jeden Monat zurückbringen.

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
Seit v3.7.0 zählen dafür nur **gemessene** Monate (Abschnitt 1), und die
Tagesrate eines Kalendermonats kommt aus gemessenen Monaten, solange es welche
gibt; nur sonst aus verteilten. Das Heizmodell ist dasselbe wie in Abschnitt 5,
bei wenigen Ablesungen also auch das über die Ablesungsabstände.

**Zwei Faktoren (seit v3.7.0).** Der Faktor wirkt nur auf den Teil, aus dem er
stammt: Monate, deren Erwartung zu weniger als 30 % aus Heizung besteht,
kalibrieren die Grundlast (`projection_factor_base`), die übrigen den Heizanteil
(`projection_factor_heat`) — je mit mindestens zwei Monaten, sonst 1, je begrenzt
auf 0,5 bis 1,5:

```text
Faktor_Grundlast = Σ Verbrauch ÷ Σ Erwartung            (Monate fast ohne Heizung)
Faktor_Heizung   = (Σ Verbrauch − Faktor_Grundlast × Σ c × Tage) ÷ Σ a × HGT   (Heizmonate)
Tag              = a × HGT × Faktor_Heizung + c × Faktor_Grundlast
```

Bei echten Daten kam der eine Faktor bis v3.6 aus Sommer- und Übergangsmonaten
und skalierte die ganze Winterschätzung mit. `projection_factor` ist jetzt der
wirksame Faktor (Schätzung ÷ Schätzung ohne Kalibrierung). **Besteht das
Heizmodell die Gütesperre nicht, bleibt es ein Faktor für das Ganze:** Die
Aufteilung setzt ein Modell voraus, das Grundlast und Heizung richtig trennt —
bei echten Daten lag die Grundlast des Modells bei etwa einem Zehntel des
gemessenen Sommers, der Grundlast-Faktor klemmte an 1,5 und der Heizanteil aus
Übergangsmonaten drückte den Winter. Die Schätzung ist dann ohnehin unsicher
(`poor_fit`); ein klemmender Teilfaktor zählt als `factor_clamped`
(`factor_part`: `base`, `heat` oder `both`). Ohne Heizmodell bleibt
es ein Faktor; die Tagesrate eines Kalendermonats ist dort ab drei Werten der
Median (wie in der Prognose, Abschnitt 6).

**Abschlagsvorschlag:** Weicht der erwartete Saldo spürbar ab, schlägt
die Karte einen Abschlag vor, der ihn bis zur Abrechnung ausgleicht (seit v3.9.0
der letzte Tag der laufenden Abrechnungsperiode, unten):

```text
Vorschlag = max(0, gerundet( aktueller Abschlag + erwarteter Saldo / verbleibende Monate ))
```

Die Aufschlüsselung auf der Karte (`energy_cost_to_date`,
`base_to_date`, `bonus_to_date`) ergibt die Summe; der geschätzte Teil
steht darunter mit dem Datum der letzten Ablesung.

**Unsicher statt falsch sicher (seit v3.7.0).** Klemmt ein Faktor an einer
Grenze (`factor_clamped`: das jüngste Niveau liegt unter 0,5 oder über 1,5 des
Modells), ist zu wenig gemessen, um das Heizmodell zu prüfen (`few_measured`: es
steht nur auf den Ablesungsabständen, oder die Gütesperre kann es nicht prüfen),
oder besteht das Heizmodell die Gütesperre nicht (`poor_fit`), weiß die App
nicht, ob ihre Schätzung stimmt. Dann gibt es keinen einzelnen Vorschlag
(`suggested_advance = null`), sondern `projection_uncertain` mit Grund und
Spanne. Gerechnet wird dieselbe geschätzte Energie mit anderen Annahmen:

```text
Faktor klemmt:    mit den ungeklemmten Faktoren und mit 1 (das jüngste Niveau ganz oder gar nicht)
zu wenig gemessen: × 0,5 und × 1,5 (die ganze Kalibrierspanne)
Modell schlecht:  jede Annahme zusätzlich × (1 ± Verhältnis der Gütesperre);
                  fällt die Grundlast-Prüfung, auch mit der gemessenen Sommer-Grundlast
                  (`base_load_per_day`), denn sonst schätzt das Modell Sommertage fast mit null
Spanne            = erwarteter Saldo + (Energie_Annahme − geschätzte Energie), kleinste bis größte
Abschlag          = aktueller Abschlag + Spanne / verbleibende Monate
```

Ohne Heizmodell (Strom, Wasser) gibt es weder Gütesperre noch einen Heizanteil,
der über das Jahr fortgeschrieben wird; ein nie gemessener Kalendermonat nimmt die
Tagesrate seines Ablesungsabstands. Ist nichts mehr zu schätzen (abgerechnet,
beendet: keine geschätzte Menge), gilt die Schätzung als sicher — entschieden über
die Menge, nicht über Euro, damit ein Vertrag ohne Arbeitspreis nicht still als
sicher gilt; eine Spanne des Abschlags mit gleichen Enden („zwischen 0 € und 0 €“)
entfällt. Dieselbe Kennzeichnung zeigen Vertragsstatus, Übersicht (auch für Home
Assistant), Saldo-Karte, Jahresbericht und Werkstatt; wo sie gesetzt ist, steht
statt eines Betrags nur die Spanne (Saldo-Karte, „Erwartet zum …“ unter Kosten &
Verträge, Verträge-Tabelle).

Bei echten Daten lag der Vorschlag mit klemmendem Faktor rund ein Drittel zu
niedrig; wer ihm folgte, hätte rund ein Viertel der Jahreskosten nachgezahlt,
und der Jahresbericht machte daraus Aufgabe Nr. 1. Jetzt zeigen Karte,
Jahresbericht (ein Hinweis statt einer Aufgabe; die Ampel nimmt das ungünstige
Ende der Spanne und zeigt, solange eine Schätzung unsicher ist, kein
„Abschläge passen“) und Prognose-Werkstatt die Spanne mit dem Grund; das
Schaubild „Wohin geht mein Geld?“ nennt Kosten und Ergebnis als Spanne.

Bis v3.8 rechneten Werkstatt und Karte den Saldo verschieden. Seit v3.9.0 gibt
es eine Projektion für beide ([unten](#eine-projektion-für-saldo-und-werkstatt-seit-v390)).

**Abgelöst (seit v3.7.0).** Beginnt ein Folgevertrag, während ein Vertrag noch
läuft (offen oder mit Ende danach), gilt ab dessen Beginn der Folgevertrag
(spätester Beginn gewinnt). Abschläge, Grundpreis und Saldo des alten Vertrags
enden am Tag davor (`superseded_on`); gespeichert bleibt sein Ende, wie es ist.
Bis v3.6 zählten seine Abschläge im Saldo bis heute weiter — der Saldo lag um
etwa einen Jahresbetrag zu günstig. Das gilt auch für einen Folgevertrag, der
**ganz im alten liegt**: Der alte läuft danach nicht wieder auf, nach dem Ende des
Folgevertrags läuft dieser als Annahme weiter (§ 309 Nr. 9 BGB). Ablösen kann nur
ein Vertrag desselben Zählers bzw. derselben Gruppe — zwei Zähler mit eigenen
Verträgen (Doppelbelieferung) und Schattenverträge lösen nichts ab. Verträge ohne
Nachfolger und lückenlose Ketten bleiben unverändert.

**Preis fehlt (seit v3.7.0).** Hat ein Vertrag keinen Arbeitspreis, enthalten
Kosten und Saldo nur Grundpreis und Abschläge. Vertragsstatus und
Abrechnungsjahr sagen es beide (`price_missing`), statt zwei verschiedene,
gleich falsche Salden zu zeigen.

### Eine Projektion für Saldo und Werkstatt *(seit v3.9.0)*

Saldo-Karte, Kosten & Verträge, Jahresbericht, „Wohin geht mein Geld?“ und
Prognose-Werkstatt rechnen aus derselben Projektion mit demselben
Abrechnungstag. Die Periode ist die laufende der Abrechnungsperioden (Stichtag
`billing_cycle_anchor_<art>`, Rechnungen, Lieferantenwechsel; ohne Stichtag das
Kalenderjahr), auch ohne erfasste Rechnung:

```text
Saldo ab     = max(Vertragsbeginn, Periodenbeginn), nach einer Rechnung der Tag danach
Saldo bis    = min(Vertragsende, Periodenende)
Abrechnung   = letzter Tag der Periode (settle_on); endet der Vertrag vorher ohne
               Folgevertrag desselben Versorgers, sein letzter Tag (Schlussrechnung)
Werkstatt    = Summe der Salden der Karten; das Szenario ändert nur die Tage von heute bis zur Abrechnung
```

Ein Datum für alle Ansichten: der **letzte Tag** des Zeitraums (31.12.2026), nicht
der Folgetag. Ein abgelöster Vertrag endet am Tag vor dem Folgevertrag. In einer
angeschnittenen Periode zählen von den Sonderzahlungen nur zusätzliche
Abschlagszahlungen; Rück- und Nachzahlungen gleichen eine frühere Abrechnung
aus. Bis v3.8 zählte die Karte ohne erfasste Rechnung ab Vertragsbeginn: Im
unordentlichen Testhaushalt standen so rund 1.163 € Guthaben zum 01.01.2027
neben 357 € zum 31.12.2026 in der Werkstatt, außerhalb der Spanne der Karte.
Verträge außerhalb der laufenden Periode (abgerechnet, vergangen) rechnen wie
bisher über ihre eigene Laufzeit.

**Kurz vor der Abrechnung kein Nachholbetrag.** Bleiben weniger als drei
Abschläge bis zur Abrechnung (`months_left`), schlägt die Karte keinen
Nachholbetrag vor (`suggestion_withheld: "short"`): Den Fehlbetrag auf ein, zwei
Abschläge zu legen, vervielfacht sie (Testhaushalt Strom: 208 € statt 75 €), und
den Rest gleicht die Abrechnung ohnehin aus. Stattdessen nennt sie den laufend
angemessenen Abschlag (`advance_ongoing`):

```text
laufend = gerundet( geschätzte Kosten der 12 Monate nach der Abrechnung / 12 )
```

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

## 11. Weitere Rechnungen *(v3.1.0)*

Kurz zusammengefasst, mit dem Ort der ausführlichen Erklärung:

```text
Gruppenvertrag      Kosten Mitglied = Verbrauch × eigener Arbeitspreis;
                    Grundpreis, Abschlag, Boni nur beim ersten Mitglied
                    Mischpreis (Prognose, Wechsel) = Σ Preis × Anteil am Verbrauch der letzten 12 Monate
§ 14a Modul 1       feste Kosten je Monat = Grundpreis − Reduzierung je Jahr / 12   (tagesgenau)
Dynamik-Check       Arbeitspreis je Monat = Börsen-Monatsmittel × (1 + USt) + Aufschlag
Ladestrom           Vertragspreis: kWh × (Arbeitskosten / kWh des zahlenden Zählers)
                                   + Grundpreis × kWh Wallbox / kWh Elternzähler
                    Pauschale:     kWh × Pauschale
Jahresarbeitszahl   JAZ = Σ Wärme / Σ Strom, nur Monate mit beiden Werten
Speicher            Wirkungsgrad = entladen / geladen;  Vollzyklen = geladen / Kapazität
Balkonkraftwerk     Eigenverbrauch = Erzeugung × angenommener Anteil   (ohne Einspeisezähler)
Amortisation        Nutzen = Eigenverbrauch × Arbeitspreis + Einspeiseerlös seit Inbetriebnahme
                    Jahre  = Investition / Nutzen der letzten 12 Monate
Gutschriften        ersetzen kWh × Vergütung im Zeitraum, tagesgenau verteilt
Einordnung          Δ% = (Jahreswert − eigener Vergleichswert) / Vergleichswert × 100   (nur volle Jahre)
                    Ampel (v3.6.0): gut Δ ≤ 0 · darüber 0 < Δ ≤ 10 · deutlich darüber Δ > 10
                    Wasser: L/Person·Tag = m³ × 1000 / Tage / Personen;
                            Index = L / Richtwert × 100, Ampel nach den Spar-Index-Bändern
Zeitreihen          je Tag: letzter Zählerstand bzw. Summe der Intervalle
```

Mehr dazu: [Strom](02-strom.md) (Gruppenvertrag, § 14a, dynamische Tarife),
[Ladestrom-Nachweis](../anleitungen/ladestrom-nachweis.md),
[Heizwärme §7](15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310),
[PV §7–11](12-pv.md#7-speicher-v310),
[Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md) und die
Einordnung in der [API-Referenz](../referenz/api.md#einordnung-get-apibenchmarkscomparison-v310).

---

[← Kompendium-Index](../README.md) ·
[Gas →](01-gas.md)
