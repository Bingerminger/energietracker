# Wasser

[← Strom](02-strom.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **kumulativ** (Zählerstände in m³) |
| Abrechnungseinheit | **m³** (nicht kWh) |
| HGT-relevant | **nein** |
| Farbe | Blau |

## Fachlicher Hintergrund

Wasser ist die einzige Art, die **nicht** in kWh umgerechnet wird — die
Abrechnung erfolgt in m³. Der Verbrauch ist weitgehend wetterunabhängig
(leichte Saisonalität durch Gartenbewässerung im Sommer möglich).

## Drei-Komponenten-Tarif

Wasser hat ein eigenes Vertragsmodell mit drei Bestandteilen:

1. **Trinkwasser** — Arbeitspreis (ct/m³) + Grundpreis (€/Monat).
2. **Schmutzwasser** — Basis wahlweise *Trinkwassermenge* oder ein
   *separater Abwasserzähler*; Arbeitspreis (ct/m³).
3. **Niederschlagswasser** — Pauschale je versiegelte Fläche
   (€/m²·Jahr) auf Basis der gepflegten Fläche.

Dieses Modell wurde mit Schema 1.0.3 eingeführt; ein Auto-Migrator
übernimmt alte einfache Wassertarife in die Trinkwasser-Komponente.

## Wasser-Spar-Index

```text
Spar-Index = (Liter pro Person und Tag) / Referenz × 100
```

mit `wasser_personen_anzahl` und `wasser_personen_referenz`
(Default-Referenz ~127 L/Person/Tag, deutscher Durchschnitt). Werte
deutlich unter 100 = sparsam; Bandgrenzen
(`wasser_sparindex_gut/_warnung`) sind anpassbar.

## Typische Stolpersteine

- **kWh-Erwartung**: Wasser-Auswertungen zeigen m³, nicht kWh — die
  Effizienzklasse (eine Heiz-Kennzahl) gilt für Wasser nicht.
- **Schmutzwasserbasis falsch gewählt**: Bei separatem Abwasserzähler
  muss dieser auch als Zähler/Komponente gepflegt sein, sonst rechnet
  die App auf Trinkwasserbasis.

[← Strom](02-strom.md) · [Fernwärme →](04-fernwaerme.md)
