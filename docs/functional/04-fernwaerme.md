# Fernwärme

[← Wasser](03-wasser.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **kumulativ** (Zählerstände in kWh) |
| Abrechnungseinheit | kWh |
| Umrechnung | keine (bereits kWh) |
| HGT-relevant | **ja** |
| Farbe | Rot-Rosé |

## Fachlicher Hintergrund

Fernwärme verhält sich für die Auswertung wie Gas — ein kumulativer
kWh-Zähler, stark heizgetrieben — aber **ohne** Volumenumrechnung
(der Zähler liefert direkt kWh). Typisch ist ein hoher **Grundpreis**
(Leistungspreis nach Anschlussleistung) plus Arbeitspreis.

## Was Energietracker damit macht

- Monatsverbrauch durch lineare Interpolation der kWh-Zählerstände.
- Volle **Heizsignatur-Analyse** (HGT-Regression, alle fünf Modelle).
- **Wetterbereinigung** und R²-gewichtete **Prognose** wie bei Gas.
- Zählt als **Heizquelle** in der Effizienzklasse.

## Verträge

Arbeitspreis (ct/kWh) + Grundpreis (€/Monat, oft als Leistungspreis).
Preisänderungen werden über die `working_prices`/`base_prices`-Historie
mit Forward-Fill korrekt zeitlich zugeordnet.

Rück-/Nachzahlungen und zusätzliche Abschlagszahlungen werden als
**[Sonderzahlungen](10-sonderzahlungen.md)** (F1003) erfasst und gehen
in den Saldo ein.

## Typische Stolpersteine

- **Grundpreis unterschätzt**: Bei Fernwärme ist der fixe Anteil oft
  hoch — unbedingt den Leistungspreis als `base_prices` pflegen, sonst
  ist der Saldo zu optimistisch.

[← Wasser](03-wasser.md) · [Heizöl →](05-heizoel.md)
