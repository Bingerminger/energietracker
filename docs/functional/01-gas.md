# Gas

[← Grundlagen](00-overview.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **kumulativ** (Zählerstände in m³) |
| Abrechnungseinheit | kWh |
| Umrechnung | `gas_conversion_factor` (Default 11,5 kWh/m³) |
| HGT-relevant | **ja** — Heizen dominiert den Verbrauch |
| Farbe | Orange |

## Fachlicher Hintergrund

Der Gaszähler misst **Volumen** (m³), abgerechnet wird **Energie**
(kWh). Die Umrechnung steht auf jeder Gasrechnung:

$$
\text{kWh} = V_{m^3}\times \underbrace{\text{Brennwert}}_{\approx 10\text{–}11,5\,\frac{kWh}{m^3}}\times \underbrace{\text{Zustandszahl}}_{\approx 0,95\text{–}1,0}
$$

Trage das Produkt (oft 11,4–11,6) als `gas_conversion_factor` ein —
sonst weichen die Kosten von der Rechnung ab.

## Was Energietracker damit macht

- **Monatsverbrauch** durch lineare Interpolation zwischen Ablesungen.
- **Heizsignatur**: Da Gas meist heizt, korreliert der Verbrauch stark
  mit den Heizgradtagen. Die Analyse zeigt die Regression (oft hohes
  $R^2$); die `sigmoid`-Kurve bildet die Sättigung an sehr kalten Tagen
  gut ab.
- **Wetterbereinigung**: trennt „kalter Winter" von „real mehr
  verbraucht" (siehe [Grundlagen §5](00-overview.md)).
- **Prognose**: R²-gewichtete Mischung aus Heizsignatur-Regression und
  Saisonprofil.
- **Effizienzklasse**: Gas zählt als Heizquelle in kWh/m²·a.

## Verträge

Gas hat klassische Lieferverträge: Arbeitspreis (ct/kWh), Grundpreis
(€/Monat), Abschläge, Boni. Mehrere Verträge mit Wechsel werden über
ihre Laufzeiten korrekt verkettet; der Saldo zeigt Stand heute und
erwartete Endsaldierung bis zum Abrechnungsstichtag.

## Typische Stolpersteine

- **Falscher Brennwertfaktor** → Kosten stimmen nicht. Immer von der
  realen Rechnung übernehmen.
- **Lange Ableseintervalle** verschmieren kalte/warme Phasen. Für gute
  HGT-Korrelation häufiger ablesen (idealerweise monatlich).

[← Grundlagen](00-overview.md) · [Strom →](02-strom.md)
