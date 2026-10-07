# Wasser

**Deutsch** · [English](../en/verstehen/03-wasser.md)

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

## Rollen: Kalt, Warm, Garten *(v3.1.0)*

Jeder Wasserzähler hat seit v3.1.0 eine **Rolle** (Zählerdialog → „Rolle“,
API-Feld `role`):

| Rolle | Oberfläche | Wirkung |
|---|---|---|
| `cold` (Standard) | Kaltwasser | wie bisher |
| `warm` | Warmwasser | zusätzlich die Wärme für dieses Warmwasser, s. u. |
| `garden` | Garten | Wasser, das nicht ins Abwasser geht — eine Markierung, die Rechnung bleibt gleich |

Zähler ohne Angabe sind Kaltwasserzähler; für sie ändert sich nichts.

**Wie du die Zähler anordnest** — damit die Summe der Verbrauchsart stimmt:

- **Mietwohnung:** Kalt- und Warmwasserzähler hängen nebeneinander in der
  Wohnung. Lege beide als eigenständige Zähler an (kein Elternzähler). Ihre
  Summe ist das Frischwasser der Wohnung.
- **Haus:** Der Hauptzähler am Hausanschluss misst alles, ein
  Warmwasserzähler hinter dem Speicher nur den warmen Teil. Lege den
  Warmwasserzähler als **Subzähler** des Hauptzählers an
  ([Meter-Topologie](13-meter-topologie.md)); dann wird er nicht doppelt
  gezählt, und die Summe bleibt der Hauptzähler.

## Wärme für das Warmwasser *(v3.1.0)*

Bei einem Zähler mit der Rolle **Warmwasser** rechnet die App je Monat aus,
wie viel Wärme dieses Wasser gebraucht hat — nach der Formel der
Heizkostenverordnung (HeizkostenV § 9 Abs. 2):

```text
Q [kWh] = 2,5 × V [m³] × (t_w − 10)
```

`t_w` ist die Warmwassertemperatur, Einstellung `warmwasser_temp_c`
(Einstellungen → Haushalt & Gebäude → „Wohnen und Warmwasser“; Standard 60 °C,
erlaubt 30–90). Beispiele:

```text
1 m³ bei 60 °C:  2,5 × 1 × 50 = 125 kWh
3 m³ bei 55 °C:  2,5 × 3 × 45 = 337,5 kWh
```

Das ist ein **Rechenwert, kein Messwert** — die Verordnung nimmt ihn, wenn
die Wärme fürs Warmwasser nicht eigens gemessen wird. Die Verbrauchsansicht
Wasser zeigt unter der Tabelle „Wärme für dieses Warmwasser {Jahr}: rund … kWh
bei 60 °C“ mit einer ⓘ-Erklärung; die API liefert je Monat `dhw_kwh` und
`dhw_temp_c` (additiv). Die Einstellung `warmwasser_energietraeger` hält fest,
womit das Warmwasser erwärmt wird — nur zur Information. Mehr in
[Heizwärme](15-waerme.md).

## Wasser-Spar-Index

```text
Spar-Index = (Liter pro Person und Tag) / Referenz × 100
```

mit `wasser_personen_anzahl` und `wasser_personen_referenz`
(Standard-Referenz 122 L je Person und Tag, BDEW 2024; Installationen von vor
v2.10.0 behalten 127, bis sie die neuen Werte übernehmen). Werte
deutlich unter 100 = sparsam; Bandgrenzen
(`wasser_sparindex_gut/_warnung`) sind anpassbar.

## Typische Stolpersteine

- **kWh-Erwartung**: Wasser-Auswertungen zeigen m³, nicht kWh — die
  Effizienzklasse (eine Heiz-Kennzahl) gilt für Wasser nicht.
- **Schmutzwasserbasis falsch gewählt**: Bei separatem Abwasserzähler
  muss dieser auch als Zähler/Komponente gepflegt sein, sonst rechnet
  die App auf Trinkwasserbasis.
- **Warmwasserzähler doppelt gezählt** *(v3.1.0)*: Im Haus gehört er als
  Subzähler unter den Hauptzähler, in der Mietwohnung neben den
  Kaltwasserzähler — nicht beides.

[← Strom](02-strom.md) · [Fernwärme →](04-fernwaerme.md)
