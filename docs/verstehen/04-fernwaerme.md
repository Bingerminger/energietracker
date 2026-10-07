# Fernwärme

**Deutsch** · [English](../en/verstehen/04-fernwaerme.md)

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
(der Zähler liefert direkt kWh). Typisch ist ein hoher fester Anteil: neben
dem Arbeitspreis ein **Leistungspreis** nach der Anschlussleistung und oft ein
**Messpreis** für Zähler und Abrechnung.

## Was Energietracker damit macht

- Monatsverbrauch durch lineare Interpolation der kWh-Zählerstände.
- Volle **Heizsignatur-Analyse** (HGT-Regression, alle fünf Modelle).
- **Wetterbereinigung** und R²-gewichtete **Prognose** wie bei Gas.
- Zählt als **Heizquelle** in der Effizienzklasse.
- **Rechnungsprüfung** (seit v3.1.0) Abschnitt für Abschnitt, mit Arbeitspreis
  und festen Kosten ([Jahresabrechnung](../anleitungen/jahresabrechnung.md#4-nachrechnen)).

## Verträge

Arbeitspreis (ct/kWh) + Grundpreis (€/Monat). Preisänderungen werden über die
`working_prices`/`base_prices`-Historie tagesgenau zugeordnet (seit v2.9.0;
vorher ab dem Folgemonat).

Rück-/Nachzahlungen und zusätzliche Abschlagszahlungen werden als
**[Sonderzahlungen](10-sonderzahlungen.md)** (F1003) erfasst und gehen
in den Saldo ein.

## Feste Kosten: Leistungs- und Messpreis *(v3.1.0)*

Im Vertragsdialog der Fernwärme stehen seit v3.1.0 zwei weitere Preislisten,
je mit Stichtag, und die Feldgruppe **„Fernwärme: Anschluss und Kennwerte“**:

| Feld | Einheit | Bedeutung |
|---|---|---|
| **Anschlussleistung (kW)** | kW | die vereinbarte Leistung des Anschlusses (`capacity_kw`) |
| **Leistungspreis** | € je kW und Jahr | datierte Liste (`capacity_prices`); braucht die Anschlussleistung |
| **Messpreis** | € je Jahr | datierte Liste (`metering_prices`), auch Verrechnungs- oder Zählerpreis genannt |
| **CO₂-Faktor des Netzes (g/kWh)** | g CO₂ je kWh | Emissionsfaktor deines Wärmenetzes (`co2_g_per_kwh`) |
| **Primärenergiefaktor** | — | nur zur Information, gespeichert und angezeigt (`primary_energy_factor`) |

Die Werte stehen auf der Rechnung oder im Preisblatt des Wärmeversorgers.

```text
feste Kosten je Monat = Grundpreis
                      + Anschlussleistung × Leistungspreis / 12
                      + Messpreis / 12
Beispiel ohne Grundpreis:
           10 kW × 60 €/(kW·a) / 12 + 120 €/a / 12 = 50 € + 10 € = 60 € je Monat
```

Die festen Kosten gehen wie der Grundpreis überall ein: in die Monatskosten,
den Saldo, die Prognose und die Rechnungsprüfung — nach Kalendertagen des
Monats. Ein Stichtag in einer der Listen teilt den Monat wie eine
Preisänderung. Ein Leistungspreis ohne Anschlussleistung lehnt die App ab
(„Ein Leistungspreis braucht die Anschlussleistung in kW“), ebenso ungültige
Werte.

**Der CO₂-Faktor des Netzes** ersetzt für die Monate des Vertrags den
allgemeinen Wert „CO₂ Fernwärme“ (Einstellungen → Verbrauchsarten &
Abrechnung) in der CO₂-Bilanz. Er ist außerdem die einzige Grundlage für den
**CO₂-Preis** der Fernwärme — einen Standardfaktor dafür gibt es nicht
([CO₂-Preis im Brennstoff](16-co2-preis.md)).

## Typische Stolpersteine

- **Feste Kosten unterschätzt**: Bei Fernwärme ist der feste Anteil oft
  hoch — Leistungs- und Messpreis eintragen (seit v3.1.0 als eigene Listen;
  früher als Teil des Grundpreises), sonst ist der Saldo zu optimistisch. Wer
  bisher alles im Grundpreis führt, lässt es dort; doppelt eintragen zählt
  doppelt.

[← Wasser](03-wasser.md) · [Heizöl →](05-heizoel.md)
