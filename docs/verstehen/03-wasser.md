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
   *separater Abwasserzähler*; Arbeitspreis (ct/m³) und *(v3.9.0)* ein eigener
   Grundpreis (€/Monat), wenn der Versorger einen ausweist (Feld
   `schmutzwasser.base_prices`, optional).
3. **Niederschlagswasser** — Pauschale je versiegelte Fläche
   (€/m²·Jahr) auf Basis der gepflegten Fläche.

Dieses Modell wurde mit Schema 1.0.3 eingeführt; ein Auto-Migrator
übernimmt alte einfache Wassertarife in die Trinkwasser-Komponente.

Jede Sparte führt ihre Preise als **Abschnitte ab einem Tag**: Ändert der
Versorger die Preise zum 01.10., stehen zwei Zeilen da, und jeder Tag rechnet
mit dem Preis, der an ihm gilt. Die App führt **Bruttopreise**. Weist die
Rechnung netto aus, gilt je Sparte ihr eigener Steuersatz (bei Trinkwasser oft
7 %, bei Schmutz- und Niederschlagswasser oft 19 %). Den Satz liest die App aus
der Rechnung, sie vermutet ihn nicht. Basis- und Bereitstellungspreis derselben
Sparte sind zusammen ihr Grundpreis; ein Jahrespreis zählt je Monat als ein
Zwölftel.

Beispiel (erfunden): Basispreis 50,00 € und Bereitstellungspreis 100,00 € je
Jahr netto, 7 % USt, ergeben brutto (53,50 € + 107,00 €) ÷ 12 = 13,375 € je
Monat; ein Wasserpreis von 2,10 € je m³ netto wird 2,247 € = 224,7 ct je m³.

*(v3.9.0)* Aus einer **Wasser-Jahresrechnung** legt der Posteingang den
Vertrag mit allen drei Sparten, den Preisabschnitten und dem Abschlagsplan an
([Posteingang → Vertrag aus der Rechnung](../anleitungen/posteingang.md#vertrag-aus-der-rechnung-v390)).

## Abschläge, Saldo und Sonderzahlungen *(v3.5.0)*

Ein Wasservertrag hat wie Gas und Strom monatliche **Abschläge** und am Ende
der Abrechnung eine **Nach- oder Rückzahlung**. Seit v3.5.0 trägst du sie als
**[Sonderzahlung](10-sonderzahlungen.md)** im Vertrag ein (Abschnitt
„Sonderzahlungen“ im Wasservertrag, nach den Boni) oder buchst das Ergebnis
der Versorgerrechnung unter *Rechnung prüfen*
([#34](https://github.com/Bingerminger/energietracker/issues/34)). Eine Art
*mit Auswirkung* setzt den neuen Abschlag ab ihrem Stichtag — in den Monaten,
im Saldo und in der Prognose.

*(v3.7.0)* Hat der Wasservertrag **Abschläge**, rechnet der Saldo **nach
Kalender** wie bei Gas, Strom und Fernwärme
([Grundlagen §10](00-overview.md#10-saldo-bis-heute)): gemessen bis zur letzten
Ablesung, danach geschätzt, Grundpreis tagesanteilig, Abschläge nach Plan bis
heute. Je m³ zählen Trinkwasser und Schmutzwasser (bei eigenem oder
Garten-Abzugszähler mit dem gemessenen Anteil Schmutzwasser), als feste Kosten
der Grundpreis und das Niederschlagswasser (Fläche × €/m²·a ÷ 12). Dazu kommen
wie dort ein **Vorschlag für den Abschlag** (`suggested_advance`), der
**Saldo-Verlauf** (`balance_path`) und im Jahresbericht Saldo und Abschlags-Rat.
In der API steht `projection_method: forecast`.

Ohne Abschläge bleibt es beim Saldo über die Monate mit Ablesung
(`projection_method: flat_average`, so rechnete Wasser bis v3.6 immer):

```text
Saldo heute       = Kosten der abgelesenen Monate − Abschläge dieser Monate + Sonderzahlungen netto
Erwartet am Ende  = Saldo heute + (Kosten je Monat im Schnitt − aktueller Abschlag) × verbleibende Monate
```

Die Übersicht, die Seite *Verträge & Abschläge* und die Verbrauchsansicht
Wasser zeigen beide Werte.

## Abschläge alle zwei oder drei Monate *(v3.9.0)*

Viele Wasserversorger ziehen den Abschlag nicht monatlich ein, sondern alle zwei
Monate oder zu festen Terminen. Jede Zeile des Abschlagsplans hat dafür einen
**Rhythmus** (Vertragsformular → „Rhythmus“): monatlich, alle 2 Monate oder
alle 3 Monate. Hat der Posteingang den Plan aus einer Rechnung übernommen,
stehen unter der Zeile die **Termine laut Rechnung**; sie bleiben beim
Speichern erhalten und entfallen, wenn du den Rhythmus änderst. Das gilt für
jede Verbrauchsart, nicht nur für Wasser.

- Der Betrag gilt **je Termin**. Die App rechnet ihn nie still in einen
  Monatsbetrag um.
- Alle N Monate: ab dem Tag der Zeile, jeweils zum selben Tag (am Monatsende
  auf den letzten Tag des Monats gesetzt).
- Mit Terminen: an den genannten Tagen, danach jährlich zu denselben Tagen. Die
  Rechnung nennt oft fünf Termine, weil die sechste Rate in die nächste Rechnung
  fällt; ohne neuen Plan geht es im selben Takt weiter.
- Eine neue Zeile beendet den Rhythmus der alten.
- Monatlich bleibt alles wie bisher; es wird kein neues Feld gespeichert.

So rechnen die Auswertungen damit:

- **Saldo nach Kalender**: bezahlt sind die Raten, deren Tag bis heute
  erreicht ist, jede ganz in ihrem Monat.
- **Vorschlag für den Abschlag**: der Rest bis zur nächsten Abrechnung verteilt
  sich auf die Raten, die noch fällig werden; der Vorschlag gilt je Termin.
- **Prognose** und Monatszeilen: jede Rate in ihrem Monat, die Monate
  dazwischen ohne Abschlag.
- **Saldo-Karte** und Vertragsübersicht nennen den Rhythmus („165,00 € alle
  2 Monate“), die Saldo-Karte dazu den nächsten Termin (API:
  `current_advance_every_months`, `next_advance_dates`, nur mit Rhythmus).

Beispiel (erfunden): 165,00 € alle zwei Monate ab 15.05.2026. Am 31.07.2026 sind
zwei Raten bezahlt (15.05. und 15.07.), 330,00 €, nicht dreimal ein
Monatsbetrag. Bis zur nächsten Abrechnung am 01.04.2027 folgen noch drei Raten
(15.09., 15.11., 15.01.), zusammen 495,00 €.

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
- **Abschlag alle zwei Monate als Monatsbetrag eingetragen** *(v3.9.0)*: Dann
  rechnet der Saldo mit dem doppelten Abschlag. Trag den Betrag je Termin ein
  und stell den Rhythmus ein.

[← Strom](02-strom.md) · [Fernwärme →](04-fernwaerme.md)
