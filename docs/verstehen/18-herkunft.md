# Woher eine Zahl kommt

**Deutsch** · [English](../en/verstehen/18-herkunft.md)

[← CO₂-Preis im Brennstoff](16-co2-preis.md) · [Kompendium-Index](../README.md)

Seit **v3.12.0** gibt der Energietracker einem KI-Assistenten Auskunft
([KI-Assistent anbinden](../anleitungen/ki-assistent.md)). Eine KI liest eine
Zahl anders als du: Sie sieht nicht, dass der März grau hinterlegt ist oder
dass vor dem Saldo ein „≈“ steht. Deshalb trägt über die KI-Schnittstelle
**jede Zahl ihre Herkunft**, und die KI soll sie mit dieser Herkunft
wiedergeben. Diese Seite erklärt die fünf Stufen, das Zahl-Objekt und das
Vorzeichen beim Saldo. Die KI kann sie selbst lesen, als Ressource
`energietracker://hilfe/herkunft`.

Die Zahlen selbst sind dieselben wie in der Oberfläche: Ein Werkzeug ruft
denselben Dienst wie die Ansicht und hängt nur die Herkunft an.

---

## Die fünf Herkünfte

Von stark nach schwach. Das Feld heißt `basis`.

| `basis` | Deutsch | Bedeutung | Beispiele |
|---|---|---|---|
| `measured` | gemessen | aus echten Ablesungen | Verbrauch eines Monats, dessen Verbrauch und Tage mindestens zur Hälfte aus Ablesungsabständen bis 45 Tage stammen; Viertelstundenwerte; ein abgelesener Zählerstand |
| `entered` | eingetragen | von einer Person eingegeben oder aus einem übernommenen Dokument | Arbeits- und Grundpreis, Abschlag, Beträge einer Rechnung, Gasfaktor mit Datum |
| `distributed` | verteilt | zwischen zwei echten Ablesungen gleichmäßig auf die Tage verteilt | ein Monat zwischen zwei Ablesungen, die weiter als 45 Tage auseinanderliegen |
| `assumed` | angenommen | ohne Eintrag fortgeschrieben | Preis nach dem Ende der Preisgarantie, weiterlaufender Vertrag ohne Nachfolger, Gasfaktor ohne Datum, Klimanormal |
| `estimated` | geschätzt | über die letzte Ablesung hinaus hochgerechnet oder aus einem Modell | Verbrauch seit der letzten Ablesung, Saldo bei der Abrechnung, Prognose, Szenario, eine als geschätzt erfasste Ablesung |

Gemessen ist dabei nicht „genau“: Auch ein gemessener Monat ist zwischen zwei
Ablesungen auf die Tage verteilt. Er heißt gemessen, weil die Ablesungen nah
genug beieinanderliegen, dass die Verteilung den Monat kaum verschiebt. Wie
die App den Verbrauch zwischen zwei Ablesungen verteilt, steht in
[Grundlagen & Methodik](00-overview.md).

## Zusammengesetzte Zahlen

Ein Ergebnis aus mehreren Bestandteilen trägt die **schwächste** Herkunft
seiner Teile:

```text
Herkunft(Ergebnis) = schwächste Herkunft der Bestandteile
Reihenfolge:        gemessen > eingetragen > verteilt > angenommen > geschätzt
```

Beispiel: Der Saldo bis heute setzt sich aus den Kosten bis zur letzten
Ablesung (gemessen), den Kosten seither (geschätzt) und den Abschlägen
(eingetragen) zusammen. Er ist also **geschätzt**. Wie viel davon auf welche
Herkunft fällt, steht in `parts`, etwa `{"measured": 0.71, "estimated": 0.29}`
(Anteile am Betrag, zusammen 1). Solche Zahlen tragen `derived: true`.

Eine Ausnahme nach oben: Eine Summe über ganze Ablesungsabstände (an beiden Rändern
liegt eine Ablesung) heißt **gemessen**, auch wenn alle ihre Monate `distributed` sind.
Das Verteilen auf Monate gleicht sich innerhalb jedes Abstands aus, die Summe ist so gut
wie ihre Ablesungen.

## Das Zahl-Objekt

Jede Kennzahl kommt als Objekt:

```json
{"value": 412.5, "unit": "EUR", "basis": "estimated",
 "range": [380.0, 445.0], "as_of": "2026-11-03",
 "parts": {"measured": 0.71, "estimated": 0.29},
 "uncertain": ["few_measured"], "derived": true,
 "sign": "positive_is_back_payment"}
```

| Feld | Bedeutung |
|---|---|
| `value` | der Wert, gerundet wie in der Oberfläche: Beträge auf 2, Mengen auf 3 Nachkommastellen |
| `unit` | Einheit (`EUR`, `kWh`, `m³` …) |
| `basis` | Herkunft, siehe oben |
| `range` | Spanne `[niedrig, hoch]`, nur wenn es ein Band gibt (Prognose, erwarteter Saldo, Abschlagsvorschlag) |
| `as_of` | Stichtag, etwa der Tag der letzten Ablesung beim gemessenen Saldo |
| `parts` | Anteile der Herkünfte am Wert, nur bei Summen aus mehreren Herkünften |
| `uncertain` | Gründe für Unsicherheit als Schlüssel, etwa `few_measured` (zu wenige gemessene Monate) |
| `derived` | `true` für Rechenergebnisse; dann ist `basis` die schwächste Herkunft der Teile |
| `sign` | nur beim Saldo: wie das Vorzeichen zu lesen ist (unten) |

Pflicht sind `value`, `unit` und `basis`. In **Reihen** (Monate,
Abrechnungsjahre) steht die Herkunft einmal je Zeile im Feld `basis`, nicht an
jeder Zahl, damit die Antwort klein bleibt. Zählwerte und Kalenderangaben
(Tage, Anzahl, Seiten, Jahr, Monat) tragen keine Herkunft.

## Das Vorzeichen beim Saldo

Ein Saldo nennt im Feld `sign`, wie sein Vorzeichen zu lesen ist:

| `sign` | positiv | negativ |
|---|---|---|
| `positive_is_back_payment` | Nachzahlung (du zahlst drauf) | Guthaben (du bekommst Geld zurück) |
| `positive_is_payout` | Auszahlung (Einspeisung, du bekommst Geld) | Rückforderung |

Daneben steht es in Klartext, weil Modelle ein Vorzeichen falsch lesen können: `meaning`
(`credit`, `back_payment`, `balanced`, `payout`, `reclaim`, ohne Arbeitspreis `no_price`) und
`text`, ein fertiger Satz in der Sprache des Servers, etwa „Guthaben von 828,01 €“; bei 0
„Ausgeglichen“, bei geschätzten Werten mit „Geschätzt:“ davor. Eine Antwort mit Saldo nennt
diese Sätze zusätzlich in einem eigenen Textblock.

Bei Einspeisung steht Geld im Feld `revenue` (`accounting_kind: feed_in`), bei Erzeugung
gar nicht. Gilt kein Vertrag, fehlt der Betrag (`no_contract: true`): Er ist nicht 0.

So rechnen auch API und CSV-Export
([Glossar: Saldo](09-glossar.md#begriffe)). Die Oberfläche zeigt die
Kundensicht ohne Vorzeichen: „Guthaben“ oder „Nachzahlung“. Die KI soll es
ebenso sagen, in Worten statt mit Plus und Minus.

## So soll die KI es sagen

Die Server-Anweisungen verlangen, dass die KI Zahlen nur aus den Werkzeugen
nennt, jede mit ihrer Herkunft, Geschätztes ausdrücklich als geschätzt und mit
Spanne, und dass sie nichts selbst hochrechnet. Gute Antworten klingen so:

- „Gas im Oktober: 412 kWh, gemessen.“
- „Im März sind 380 kWh verteilt: Zwischen den beiden Ablesungen lagen mehr
  als sechs Wochen, die App hat den Verbrauch gleichmäßig auf die Tage
  aufgeteilt.“
- „Bei der Abrechnung erwartet: etwa 120 € Nachzahlung, geschätzt, Spanne 60
  bis 180 €. Gemessen bis zur letzten Ablesung am 28.10.: 40 € Guthaben.“
- „Ab Januar gilt der Preis nur angenommen: Die Preisgarantie endet, ein neuer
  Preis ist nicht eingetragen.“

Wenn eine KI eine Zahl ohne Herkunft nennt oder selbst weiterrechnet, frag
nach, woher sie kommt. Die Zahl in der Oberfläche ist die maßgebliche.

---

[← CO₂-Preis im Brennstoff](16-co2-preis.md) · [Kompendium-Index](../README.md)
