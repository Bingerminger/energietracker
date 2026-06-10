# Sonderzahlungen (F1003)

**Deutsch** · [English](../en/functional/10-sonderzahlungen.md)

> Gilt für **Gas, Strom, Fernwärme** — die Energieträger mit
> klassischem Abschlags-/Saldo-Modell. Wasser (Drei-Komponenten-Tarif)
> und die lieferbasierten Träger Heizöl/Pellets haben keine
> Abschlags-Saldierung und damit keine Sonderzahlungen.

## Wozu

Der laufende Saldo eines Vertrags ist im Normalfall
`Kosten − gezahlte Abschläge`. In der Realität gibt es darüber hinaus
einmalige Geldbewegungen, die nicht in das monatliche Abschlagsraster
passen: die Jahresabrechnung bringt eine Rückzahlung oder Nachzahlung,
oder man leistet freiwillig eine zusätzliche Abschlagszahlung. F1003
bildet genau diese fünf Fälle ab.

## Die fünf Arten

| Art | Geldrichtung | Wirkung auf den Saldo | Verändert künftigen Abschlag? |
|---|---|---|---|
| Rückzahlung (mit Auswirkung) | Versorger → Kunde | Saldo **steigt** | **Ja** |
| Rückzahlung (ohne Auswirkung) | Versorger → Kunde | Saldo **steigt** | Nein |
| Nachzahlung (mit Auswirkung) | Kunde → Versorger | Saldo **sinkt** | **Ja** |
| Nachzahlung (ohne Auswirkung) | Kunde → Versorger | Saldo **sinkt** | Nein |
| Abschlagszahlung | Kunde → Versorger | Saldo **sinkt** | Nein |

Die Beträge werden immer **positiv** erfasst — das Vorzeichen im Saldo
ergibt sich ausschließlich aus der gewählten Art, nicht aus der
Eingabe.

## Saldo-Formel

```
Saldo = Kosten − gezahlte Abschläge + Sonderzahlungs-Netto

Sonderzahlungs-Netto = Σ Rückzahlung − Σ Nachzahlung − Σ Abschlagszahlung
```

Intuition: Eine **Rückzahlung** bedeutet, dass man zuvor zu viel
gezahlt hatte; erhält man dieses Guthaben zurück, ist die Überzahlung
ausgeglichen — der Saldo bewegt sich nach oben Richtung Null. Eine
**Nachzahlung** begleicht eine Unterzahlung — der Saldo sinkt Richtung
Null. Eine zusätzliche **Abschlagszahlung** wirkt wie ein weiterer
Abschlag und senkt die offene Schuld.

## „mit Auswirkung auf Abschlagszahlungen"

Nach einer Jahresabrechnung passt der Versorger oft auch den künftigen
Monatsabschlag an (z. B. nach einem Guthaben nach unten, nach einer
Nachforderung nach oben). Wählt man eine *mit-Auswirkung*-Art, erfasst
man zusätzlich den **neuen Monatsabschlag** und den **Stichtag**, ab
dem er gilt. Dieser Punkt wird intern in den effektiven Abschlagsplan
gemischt — die monatliche Abschlagsberechnung greift ihn automatisch
auf, genau so, als hätte man eine reguläre Abschlagsänderung gepflegt.

Beispiel: Jahresabrechnung 2023 ergibt 142,50 € Guthaben; der Abschlag
sinkt zum 01.04.2024 von 110 € auf 95 €.

→ Eine Sonderzahlung „Rückzahlung (mit Auswirkung)" mit Betrag 142,50,
  neuem Abschlag 95 € und Stichtag 2024-04-01. Der Saldo verbucht das
  Guthaben, und ab April rechnet die App mit 95 €/Monat weiter.

## Typische Stolpersteine

- **Doppelerfassung Rückzahlung + Abschlagssenkung getrennt.** Wer die
  Abschlagssenkung schon als regulären `advance_payments`-Eintrag
  pflegt, sollte **nicht** zusätzlich die *mit-Auswirkung*-Variante
  nehmen, sonst wird der Abschlag doppelt gesetzt. Faustregel: entweder
  reguläre Abschlagsänderung **oder** *mit-Auswirkung* — nicht beides
  für denselben Stichtag.
- **Vorzeichen.** Betrag immer positiv eingeben. Eine Nachzahlung mit
  negativem Betrag zu „tricksen" ist nicht nötig und wird ohnehin auf
  den Positivwert normalisiert.
- **Falsche Art.** „Abschlagszahlung" ist die freiwillige
  Zusatzzahlung. Die Zahlung, die man nach einer Abrechnung leistet,
  ist eine **Nachzahlung**.

[← Glossar](09-glossar.md) · [Grundlagen](00-overview.md)
