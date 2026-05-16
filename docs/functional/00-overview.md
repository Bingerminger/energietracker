# Grundlagen & Methodik

[← Kompendium-Index](../README.md)

Dieses Kapitel erklärt die Rechenkerne, die für *alle* Verbrauchsarten
gelten: Verbrauchsverteilung, Heizgradtage, Regression, Wetterbereinigung,
Prognose, Effizienzklasse. Alle Formeln sind gegen den Quellcode geprüft.

---

## 1. Vom Zählerstand zum Monatsverbrauch

Zähler werden in unregelmäßigen Abständen abgelesen. Zwischen zwei
Ablesungen $r_1$ (Datum $t_1$, Stand $c_1$) und $r_2$ ($t_2$, $c_2$) gilt:

$$
\text{Verbrauch}_{[t_1,t_2]} = c_2 - c_1
\qquad
\text{Tage} = t_2 - t_1
$$

Dieser Verbrauch wird **linear über die Tage interpoliert** und dann den
Kalendermonaten zugeschlagen:

$$
\text{Verbrauch}_{\text{Tag}} = \frac{c_2 - c_1}{t_2 - t_1}
$$

Über eine **Zählertausch-Grenze** hinweg (altes Gerät → neues Gerät):

$$
\text{Verbrauch} =
\underbrace{(c_{\text{final,alt}} - c_{\text{prev}})}_{\text{Restweg altes Gerät}}
+
\underbrace{(c_{\text{curr}} - c_{\text{initial,neu}})}_{\text{neues Gerät}}
$$

Zukunfts-Ablesungen (`is_future`) werden ignoriert. Bei lieferbasierten
Arten (Heizöl/Pellets) entfällt die Zähler-Differenz — dort wird der
Verbrauch energetisch bilanziert (siehe
[Heizöl](05-heizoel.md)).

---

## 2. Energieumrechnung

Damit Arten vergleichbar werden, rechnet Energietracker intern in **kWh**
(Wasser bleibt in m³). Die Umrechnung steckt in den Einstellungen:

| Art | Formel | Default |
|---|---|---|
| Gas | $\text{kWh} = V_{m^3} \times \text{Brennwert} \times \text{Zustandszahl}$ | `gas_conversion_factor` = 11.5 kWh/m³ |
| Heizöl | $\text{kWh} = L \times H_u$ | `heizoel_kwh_per_l` = 10.0 kWh/L |
| Pellets | $\text{kWh} = \text{kg} \times H_u$ | `pellets_kwh_per_kg` = 4.8 kWh/kg |
| Strom, Fernwärme | bereits kWh | — |
| Wasser | bleibt m³ | — |

Der Gasfaktor steht so auf der Gasrechnung (Brennwert × Zustandszahl)
und sollte dort abgelesen und in den Einstellungen gepflegt werden.

---

## 3. Heizgradtage (HGT)

Der zentrale Wetterbezug. Pro Tag mit mittlerer Außentemperatur
$T_{\text{avg}}$ und Heizgrenztemperatur $T_{\text{base}}$
(`hdd_base_temp`, Default **15 °C**):

$$
\text{HGT}_{\text{Tag}} = \max\!\left(0,\; T_{\text{base}} - T_{\text{avg}}\right)
$$

Monats-HGT = Summe der Tageswerte. Anschaulich: An einem 5-°C-Tag fallen
$15-5 = 10$ HGT an, an einem 20-°C-Tag null. Je kälter, desto mehr HGT,
desto mehr Heizenergie. Nur HGT-relevante Arten (Gas, Fernwärme, Heizöl,
Pellets) nutzen das; Strom und Wasser nicht.

---

## 4. Regressionsmodelle

Energietracker fittet den Zusammenhang **HGT → Verbrauch** mit fünf
Modellen (`RegressionService`). Jedes liefert Parameter, Bestimmtheits-
maß $R^2$ und ein `valid`-Flag (genug Datenpunkte?).

| Modell | Form | Einsatz |
|---|---|---|
| **linear** | $y = a\cdot x + b$ | Grundfall, robust ab 3 Punkten |
| **polynomial** | $y = a x^2 + b x + c$ | leichte Krümmung, ab 4 Punkten |
| **robust** | linear, Huber-gewichtet | dämpft Ausreißer |
| **segmented** | Knick: Sommer-Sockel + Heizast | Heiz-/Sommerbetrieb getrennt; Knickpunkt `auto` oder fix |
| **sigmoid** | S-Kurve (Heizsignatur) | sättigende Heizkennlinie, TU-München/BDEW-Form |

$R^2$ misst, wie gut das Modell die Streuung erklärt
($R^2 = 1$: perfekt, $0$: nicht besser als der Mittelwert):

$$
R^2 = 1 - \frac{\sum_i (y_i - \hat{y}_i)^2}{\sum_i (y_i - \bar{y})^2}
$$

Das Standardmodell ist in den Einstellungen wählbar
(`forecast_model`); in der **Prognose** kann es zusätzlich pro Abruf
umgeschaltet werden (alle fünf Modelle, seit v1.4.1).

---

## 5. Wetterbereinigung — „mehr verbraucht oder nur kälter?"

Für HGT-relevante Arten wird je Monat ausgewiesen:

- **`expected_hgt`** — der vom Regressionsmodell für das
  Monats-HGT *erwartete* Verbrauch,
- **`weather_adjusted`** — der auf das *langjährige* Kalendermonats-HGT
  normierte Verbrauch (nach VDI-3807-Logik),
- **`delta_pct`** — die prozentuale Abweichung Ist gegen Erwartung.

Damit lässt sich ein kalter Winter von echtem Mehrverbrauch trennen.
Schwachlastmonate (kaum HGT) werden korrekt ausgeblendet, um
Division-durch-fast-null-Artefakte zu vermeiden.

---

## 6. Prognose (12 Monate)

`ForecastService` mischt zwei Schätzer:

1. **Regression** auf erwartete künftige Monats-HGT (aus dem
   langjährigen Saisonprofil der Temperaturen),
2. **reines Saisonprofil** des Verbrauchs (Monatsmittel der Historie).

Die Mischung ist mit dem Regressions-$R^2$ gewichtet, gedeckelt durch
`blend_max` (Default **0.80**):

$$
w = \min(R^2,\; \text{blend\_max})
$$
$$
\hat{y}_{\text{Monat}} = w \cdot \hat{y}_{\text{Regression}}
                        + (1-w)\cdot \hat{y}_{\text{Saison}}
$$

Anschaulich: Erklärt die Heizsignatur den Verbrauch gut ($R^2$ hoch),
zählt die Regression stärker — aber nie mehr als 80 %, damit ein
einzelnes gutes Jahr die Prognose nicht dominiert. Für **nicht**
HGT-relevante Arten (Strom, Wasser) entfällt die Regression komplett:
reine Saisonprognose.

Zusätzlich projiziert die Prognose die **Kosten** offener Verträge bis
zum nächsten Abrechnungs-Stichtag (`billing_cycle_anchor_<art>`,
Format `TT-MM` in der UI) inklusive Abschlag und laufendem Saldo.

---

## 7. Effizienzklasse

`BenchmarkService` rechnet den spezifischen Heizenergiebedarf:

$$
\text{Kennzahl} = \frac{\sum \text{Heiz-kWh des Jahres}}{\text{Wohnfläche}_{m^2}}
\quad \left[\frac{\text{kWh}}{m^2\cdot a}\right]
$$

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

$$
\text{CO}_2 = \text{Verbrauch} \times f_{\text{CO}_2}
$$

mit artspezifischem Faktor (`co2_gas`, `co2_strom`, …). Die Defaults
sind **[Unverifiziert]** grobe Richtwerte und sollten in den
Einstellungen an die eigene Quelle (Stromtarif-Mix, Heizöl-Norm)
angepasst werden.

---

## 9. Anomalien

`AnomalyService` markiert Monate, deren Verbrauch mehr als
`recommendation_anomaly_sigma` Standardabweichungen vom erwarteten Wert
abweicht (Z-Score). Anomalien sind Hinweise, keine Urteile — ein
Umzug, eine neue Wärmepumpe oder ein defekter Zähler erzeugen sie
gleichermaßen.

---

[← Kompendium-Index](../README.md) ·
[Gas →](01-gas.md)
