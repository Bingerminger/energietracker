# Strom

[← Gas](01-gas.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **kumulativ** (Zählerstände in kWh) |
| Abrechnungseinheit | kWh |
| Umrechnung | keine (bereits kWh) |
| HGT-relevant | **nein** |
| Farbe | Mint-Grün |

## Fachlicher Hintergrund

Strom wird direkt in kWh gemessen — keine Umrechnung. Anders als Gas
hängt der Stromverbrauch **nicht** systematisch von der Außentemperatur
ab (Ausnahmen: Wärmepumpe, Klimaanlage, elektrische Zusatzheizung — die
würden eine HGT-Kopplung erzeugen, hier aber bewusst nicht modelliert,
weil sie haushaltsindividuell ist).

## Was Energietracker damit macht

- **Monatsverbrauch** durch lineare Interpolation.
- **Kein HGT, keine Heizsignatur-Regression.** Die Analyse zeigt
  stattdessen das **Saisonprofil** (Monatsmittel) und Trends.
- **Prognose**: reines Saisonprofil — Regression entfällt
  (siehe [Grundlagen §6](00-overview.md)).
- **Grundlast**: Ein konstanter Sockel (Kühlschrank, Standby, Router)
  plus variable Spitzen. Auffällige Sockelanstiege erkennt die
  Anomalie-/Trendregel.

## Verträge

Wie Gas: Arbeitspreis (ct/kWh), Grundpreis (€/Monat), Abschläge, Boni.
Schattenverträge erlauben „Was hätte Tarif X gekostet?" auf den echten
Verbrauch — ohne Saldo/Prognose zu verfälschen.

## Typische Stolpersteine

- **Erwartung einer Temperaturkorrelation.** Bei reinem Haushaltsstrom
  ist eine schwache/keine HGT-Kopplung normal — kein Fehler.
- **Wärmepumpen-Strom** vermischt Heiz- und Haushaltsstrom. Wer das
  trennen will, legt einen zweiten Zähler an.

[← Gas](01-gas.md) · [Wasser →](03-wasser.md)
