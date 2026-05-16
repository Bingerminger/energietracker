# Glossar & Formelsammlung

[← Szenario Eigenheim](08-szenario-eigenheim.md) · [Kompendium-Index](../README.md)

Kompakte Referenz aller Begriffe und Formeln. Ausführliche Herleitung in
[Grundlagen & Methodik](00-overview.md).

---

## Begriffe

| Begriff | Bedeutung |
|---|---|
| **Kumulativ** | Erfassung über fortlaufende Zählerstände (Gas, Strom, Wasser, Fernwärme). |
| **Lieferbasiert** | Erfassung über Brennstofflieferungen statt Zähler (Heizöl, Pellets). |
| **HGT (Heizgradtage)** | Maß für „Heizbedarf wegen Kälte" pro Tag/Monat. |
| **Heizgrenztemperatur** | Außentemperatur, ab der geheizt wird (`hdd_base_temp`, Default 15 °C). |
| **Heizsignatur** | Regressionszusammenhang HGT → Verbrauch. |
| **Wetterbereinigung** | Verbrauch normiert auf langjähriges Monats-HGT (VDI-3807-Logik). |
| **R²** | Bestimmtheitsmaß: Anteil erklärter Streuung (0…1). |
| **Saisonprofil** | Monatsmittel des Verbrauchs über die Historie. |
| **Blend** | R²-gewichtete Mischung Regression × Saisonprofil in der Prognose. |
| **Saldo** | Geleistete Abschläge − tatsächliche Kosten. |
| **Schattenvertrag** | Hypothetischer Tarif für „Was hätte das gekostet?" — ohne Saldo/Prognose-Wirkung. |
| **Effizienzklasse** | kWh/m²·a-Einordnung der Heizenergie (A+…H), seit v1.4.0 pro Quelle. |
| **Grundlast** | Wetterunabhängiger Sockel (Warmwasser, Standby). |
| **Anomalie** | Monat mit Z-Score-Abweichung über Schwelle. |
| **Tank-Bestandskurve** | Modellierter (nicht gemessener) Restbestand bei Öl/Pellets. |
| **Recurrence** | Wiederholregel eines Termins (jährlich, …). |

---

## Formeln (gegen Code geprüft)

**Tagesverbrauch (kumulativ):**
$$
\text{kWh}_{\text{Tag}} = \frac{c_2 - c_1}{t_2 - t_1}
$$

**Zählertausch:**
$$
(c_{\text{final,alt}} - c_{\text{prev}}) + (c_{\text{curr}} - c_{\text{initial,neu}})
$$

**Energieumrechnung:**
$$
\text{Gas: } \text{kWh}=V_{m^3}\cdot f_{\text{conv}}\quad
\text{Öl: } \text{kWh}=L\cdot H_u\quad
\text{Pellets: } \text{kWh}=\text{kg}\cdot H_u
$$

**Heizgradtage:**
$$
\text{HGT}_{\text{Tag}}=\max(0,\;T_{\text{base}}-T_{\text{avg}})
$$

**Bestimmtheitsmaß:**
$$
R^2 = 1 - \frac{\sum (y_i-\hat{y}_i)^2}{\sum (y_i-\bar{y})^2}
$$

**Prognose-Blend:**
$$
w=\min(R^2,\;\text{blend\_max}),\qquad
\hat{y}=w\,\hat{y}_{\text{Reg}}+(1-w)\,\hat{y}_{\text{Saison}}
$$

**Effizienzkennzahl (je Heizquelle):**
$$
\frac{\sum \text{Heiz-kWh}_{\text{Jahr}}}{\text{Wohnfläche}_{m^2}}
$$

**Lieferenergie-Bilanz (Öl/Pellets):**
$$
\text{Gesamt-kWh}=\big(\text{initial\_stock}+\textstyle\sum\text{Lieferungen}\big)\cdot H_u
$$

**Tagesabzug Bestandskurve (v1.4.0):**
$$
\text{rate}=\frac{(\sum\text{Lief. ohne letzte})(1-s)}{\sum\text{HGT}_{[\text{erste},\text{letzte}]}},\quad
\text{stock}_t=\max(0,\;\text{stock}_{t-1}+\text{Lief}_t-(\text{GL}+\text{rate}\cdot\text{HGT}_t))
$$

**Lieferkosten (v1.4.2, Gesamtbetrag-Vorrang):**
$$
\text{Kosten}=\begin{cases}\text{total\_eur}&\text{falls gesetzt}\\ \text{Menge}\cdot\text{unit\_price\_cents}/100&\text{sonst}\end{cases}
$$

**Saldo:**
$$
\text{Saldo}=\sum\text{Abschläge}-\sum\text{Kosten}
$$

**Wasser-Spar-Index:**
$$
\frac{\text{L/Person/Tag}}{\text{Referenz}}\times 100
$$

**CO₂:**
$$
\text{CO}_2=\text{Verbrauch}\times f_{\text{CO}_2}\quad[\text{Unverifiziert: Default-Faktoren}]
$$

---

## Default-Werte (Auswahl)

| Schlüssel | Default | Einheit |
|---|---|---|
| `gas_conversion_factor` | 11,5 | kWh/m³ |
| `heizoel_kwh_per_l` | 10,0 | kWh/L |
| `pellets_kwh_per_kg` | 4,8 | kWh/kg |
| `hdd_base_temp` | 15,0 | °C |
| `blend_max` | 0,80 | — |
| `delivery_baseload_share` | 0,15 | Anteil |
| `forecast_months` | 12 | Monate |
| `wohnflaeche_m2` | 100 | m² |
| `co2_gas / _strom / _wasser` | 201 / 380 / 350 | g/kWh bzw. g/m³ *(Unverifiziert)* |

---

[← Szenario Eigenheim](08-szenario-eigenheim.md) ·
[Kompendium-Index](../README.md)
