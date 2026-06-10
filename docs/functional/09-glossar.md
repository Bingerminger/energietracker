# Glossar & Formelsammlung

**Deutsch** · [English](../en/functional/09-glossar.md)

[← Szenario Eigenheim](08-szenario-eigenheim.md) · [Kompendium-Index](../README.md)

Kompakte Referenz aller Begriffe und Formeln. Ausführliche Herleitung
in [Grundlagen & Methodik](00-overview.md).

> Formeln stehen als Klartext-Codeblöcke, damit sie überall (GitHub,
> Editor, Viewer) identisch und korrekt dargestellt werden.

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
| **Sonderzahlung** | F1003: Rück-/Nachzahlung oder zusätzliche Abschlagszahlung. Saldo = Kosten - Abschläge + (Σ Rückzahlung - Σ Nachzahlung - Σ Abschlagszahlung). "mit Auswirkung" setzt zusätzlich den künftigen Abschlag. Nur Gas/Strom/Fernwärme. |
| **Zählerstand-Erfassung** | F1004 (v1.6.0): Zentraler View `#/zaehlerstaende` zur schnellen Vor-Ort-Erfassung aller kumulativen Zähler in einem Durchgang. Nur Gas/Strom/Wasser/Fernwärme — Heizöl/Pellets nutzen Lieferungen. |
| **Effizienzklasse** | kWh/m²·a-Einordnung der Heizenergie (A+…H), seit v1.4.0 pro Quelle. |
| **Grundlast** | Wetterunabhängiger Sockel (Warmwasser, Standby). |
| **Anomalie** | Monat mit Z-Score-Abweichung über Schwelle. |
| **Tank-Bestandskurve** | Modellierter (nicht gemessener) Restbestand bei Öl/Pellets. |
| **Recurrence** | Wiederholregel eines Termins (jährlich, …). |

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
Gas:     kWh = m³ × Brennwertfaktor
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

**Lieferenergie-Bilanz (Öl/Pellets):**

```text
Gesamt-kWh = (initial_stock + Σ Lieferungen) × Hu
```

**Tagesabzug Bestandskurve (v1.4.0):**

```text
rate      = (Σ Lieferungen ohne die letzte) · (1 - s)
            / Σ HGT im Fenster [erste .. letzte Lieferung]

stock_Tag = max(0, stock_Vortag + Lieferung_Tag
                   - (Grundlast_L + rate · HGT_Tag))
```

**Lieferkosten (v1.4.2, Gesamtbetrag-Vorrang):**

```text
Kosten = total_eur                          falls gesetzt
Kosten = Menge × unit_price_cents / 100     sonst
```

**Saldo:**

```text
Saldo = Σ Abschläge - Σ Kosten
```

**Wasser-Spar-Index:**

```text
Spar-Index = (Liter pro Person und Tag) / Referenz × 100
```

**CO₂** *(Default-Faktoren [Unverifiziert])*:

```text
CO2 = Verbrauch × CO2-Faktor
```

**Z-Score (Anomalie):**

```text
z = (Ist - Mittel) / Standardabweichung
```

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
