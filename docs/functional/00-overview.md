# Grundlagen & Methodik

[← Kompendium-Index](../README.md)

Dieses Kapitel erklärt die Rechenkerne, die für *alle* Verbrauchsarten
gelten: Verbrauchsverteilung, Heizgradtage, Regression,
Wetterbereinigung, Prognose, Effizienzklasse. Alle Formeln sind gegen
den Quellcode geprüft.

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
| Gas | `kWh = m³ × Brennwert × Zustandszahl` | `gas_conversion_factor` = 11,5 kWh/m³ |
| Heizöl | `kWh = Liter × Hu` | `heizoel_kwh_per_l` = 10,0 kWh/L |
| Pellets | `kWh = kg × Hu` | `pellets_kwh_per_kg` = 4,8 kWh/kg |
| Strom, Fernwärme | bereits kWh | — |
| Wasser | bleibt m³ | — |

Der Gasfaktor steht so auf der Gasrechnung (Brennwert × Zustandszahl)
und sollte dort abgelesen und in den Einstellungen gepflegt werden.

---

## 3. Heizgradtage (HGT)

Der zentrale Wetterbezug. Pro Tag mit mittlerer Außentemperatur
`T_avg` und Heizgrenztemperatur `T_base` (`hdd_base_temp`, Default
**15 °C**):

```text
HGT_Tag = max(0, T_base - T_avg)
```

Monats-HGT = Summe der Tageswerte. Anschaulich: An einem 5-°C-Tag
fallen `15 - 5 = 10` HGT an, an einem 20-°C-Tag null. Je kälter, desto
mehr HGT, desto mehr Heizenergie. Nur HGT-relevante Arten (Gas,
Fernwärme, Heizöl, Pellets) nutzen das; Strom und Wasser nicht.

---

## 4. Regressionsmodelle

Energietracker fittet den Zusammenhang **HGT → Verbrauch** mit fünf
Modellen (`RegressionService`). Jedes liefert Parameter,
Bestimmtheitsmaß `R²` und ein `valid`-Flag (genug Datenpunkte?).

| Modell | Form | Einsatz |
|---|---|---|
| **linear** | `y = a·x + b` | Grundfall, robust ab 3 Punkten |
| **polynomial** | `y = a·x² + b·x + c` | leichte Krümmung, ab 4 Punkten |
| **robust** | linear, Huber-gewichtet | dämpft Ausreißer |
| **segmented** | Knick: Sommer-Sockel + Heizast | Heiz-/Sommerbetrieb getrennt; Knickpunkt `auto` oder fix |
| **sigmoid** | S-Kurve (Heizsignatur) | sättigende Heizkennlinie, TU-München/BDEW-Form |

Die Sigmoid-Form (exakt wie im Backend `sigmoidPredict`):

```text
kWh = A / (1 + (B / (HGT - θ0))^C) + D     für HGT > θ0
kWh = D                                     sonst
```

`R²` misst, wie gut das Modell die Streuung erklärt (`R² = 1`:
perfekt, `0`: nicht besser als der Mittelwert):

```text
R² = 1 - ( Σ (yi - ŷi)² ) / ( Σ (yi - ȳ)² )
```

Das Standardmodell ist in den Einstellungen wählbar
(`forecast_model`); in der **Prognose** und im
**Analyse-Korrelationschart** werden alle fünf Modelle dargestellt.

---

## 5. Wetterbereinigung — „mehr verbraucht oder nur kälter?"

Für HGT-relevante Arten wird je Monat ausgewiesen:

- **`expected_hgt`** — der vom Regressionsmodell für das
  Monats-HGT *erwartete* Verbrauch,
- **`weather_adjusted`** — der auf das *langjährige*
  Kalendermonats-HGT normierte Verbrauch (nach VDI-3807-Logik),
- **`delta_pct`** — die prozentuale Abweichung Ist gegen Erwartung:

```text
delta_pct = (Ist - Erwartet) / Erwartet × 100
```

Damit lässt sich ein kalter Winter von echtem Mehrverbrauch trennen.
Schwachlastmonate (kaum HGT) werden ausgeblendet, um
Division-durch-fast-null-Artefakte zu vermeiden.

---

## 6. Prognose (12 Monate)

`ForecastService` mischt zwei Schätzer:

1. **Regression** auf erwartete künftige Monats-HGT (aus dem
   langjährigen Saisonprofil der Temperaturen),
2. **reines Saisonprofil** des Verbrauchs (Monatsmittel der Historie).

Die Mischung ist mit dem Regressions-`R²` gewichtet, gedeckelt durch
`blend_max` (Default **0,80**):

```text
w        = min(R², blend_max)
Prognose = w · Regressionswert + (1 - w) · Saisonwert
```

Anschaulich: Erklärt die Heizsignatur den Verbrauch gut (`R²` hoch),
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

`AnomalyService` markiert Monate, deren Verbrauch mehr als
`recommendation_anomaly_sigma` Standardabweichungen vom erwarteten
Wert abweicht (Z-Score):

```text
z = (Ist - Mittel) / Standardabweichung
```

Anomalien sind Hinweise, keine Urteile — ein Umzug, eine neue
Wärmepumpe oder ein defekter Zähler erzeugen sie gleichermaßen.

---

[← Kompendium-Index](../README.md) ·
[Gas →](01-gas.md)
