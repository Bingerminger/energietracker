# Jahresabrechnung eintragen und prüfen

**Deutsch** · [English](../en/anleitungen/jahresabrechnung.md)

[← Kompendium-Index](../README.md)

Eine Gasrechnung in den Energietracker übernehmen, nachrechnen und das Guthaben
buchen. Danach zeigt die App denselben Saldo wie die Rechnung, und die
Prognose rechnet mit dem neuen Abschlag weiter. Für Strom und Fernwärme gilt
dasselbe ohne Schritt 2.

---

## Die Rechnung — ein Beispiel

Alle Zahlen sind erfunden, aber durchgerechnet; so oder ähnlich sieht der Teil
einer Gasrechnung aus, auf den es ankommt:

```text
Stadtwerke Musterstadt · Erdgas Klassik          Abrechnungszeitraum 01.01.2025 – 31.12.2025

Zählerstände           01.01.2025    12.480 m³   Ableseart K (Kundenablesung)
                       31.12.2025    14.010 m³   Ableseart S (Schätzung)
Verbrauch                             1.530 m³

Zeitraum              Menge    Zustandszahl   Brennwert       Energie
01.01.–30.06.2025     910 m³   0,9520         11,280 kWh/m³    9.772 kWh
01.07.–31.12.2025     620 m³   0,9505         11,310 kWh/m³    6.665 kWh
                                                              16.437 kWh

Arbeitspreis          16.437 kWh × 9,20 ct/kWh                1.512,20 €
Grundpreis            12 Monate × 11,90 €                       142,80 €
Rechnungsbetrag (brutto)                                      1.655,00 €
geleistete Abschläge  12 × 150,00 €                           1.800,00 €
Guthaben                                                        145,00 €

Ihr neuer Abschlag ab 01.03.2026: 140,00 €
```

| Auf der Rechnung | In der App |
|---|---|
| Zählerstände mit Datum und Ableseart | zwei Ablesungen am Gaszähler (Schritt 1) |
| Zustandszahl und Brennwert je Zeitraum | Gas-Umrechnungsfaktoren (Schritt 2) |
| Arbeitspreis, Grundpreis, Abschlag | der Vertrag (Schritt 3) |
| Energie je Zeitraum | Rechnung prüfen (Schritt 4) |
| Guthaben bzw. Nachzahlung | Saldo des Vertrags (Schritt 5), danach als Sonderzahlung gebucht (Schritt 6) |
| neuer Abschlag | am Vertrag, mit seinem Datum (Schritt 6) |

## 1. Zählerstände

**Verbrauch → Gas → „+ Ablesung“**: die Stände vom Beginn und vom Ende des
Abrechnungszeitraums mit ihrem Datum. Hat der Versorger geschätzt (auf der
Rechnung etwa „S“ oder „geschätzt“ — die Legende steht dort), das Häkchen
**„Geschätzt / korrigierter Wert“** setzen. Gibt es für das Datum schon einen
Stand, fragt die App, ob sie ihn ersetzen soll.

> Zwei Stände genügen. Liegen eigene Ablesungen dazwischen, rechnet die App die
> Monate genauer — die Summe über das Jahr ändert sich dadurch nicht.

## 2. Umrechnungsfaktoren (nur Gas)

Der Gaszähler zählt Kubikmeter, abgerechnet wird in kWh. **Einstellungen →
Verbrauchsarten & Abrechnung → Gas-Umrechnungsfaktoren**: je Zeitraum der
Rechnung eine Zeile mit **Gültig ab**, **Zustandszahl** und **Brennwert** —
im Beispiel

| Gültig ab | Zustandszahl | Brennwert (kWh/m³) |
|---|---|---|
| 01.01.2025 | 0,9520 | 11,280 |
| 01.07.2025 | 0,9505 | 11,310 |

Steht der Brennwert in MJ/m³ auf der Rechnung, stellt „Brennwert angeben in“
die Einheit um. Ohne eigene Einträge rechnet die App mit dem Standard 11,5 kWh/m³
— dann weicht jede kWh-Zahl von der Rechnung ab.

## 3. Vertrag

**Verbrauch → Gas → „⚙️ Verträge verwalten“** → **„+ Neuer Vertrag“** bzw. den
laufenden Vertrag bearbeiten:

- **Anbieter** und **Tarif**, **Beginn**, das Ende oder
  **„Verlängert sich ohne Kündigung“**, dazu die **Kündigungsfrist** — daraus
  entstehen die Erinnerungen und der Wechseltermin.
- **Arbeitspreis** 9,20 ct/kWh, **Grundpreis** 11,90 €/Monat und **Abschlag**
  150 €, jeweils ab 01.01.2025. Nennt die Rechnung den Grundpreis je Jahr, durch
  12 teilen.
- Preise **brutto**, also mit Mehrwertsteuer, wie auf der Rechnung — die App
  rechnet mit genau den Beträgen, die du einträgst.
- Hat sich ein Preis unterm Jahr geändert, eine weitere Zeile mit dem Datum der
  Änderung; sie gilt ab diesem Tag.

## 4. Nachrechnen

**Kosten & Verträge → Rechnung prüfen** (oder auf der Gas-Seite
„Rechnung … prüfen“): Zähler und Zeitraum 01.01.2025 bis 31.12.2025 wählen. Die
Seite zeigt je Abschnitt Kubikmeter, Zustandszahl, Brennwert und kWh — dieselben
Zeilen wie die Rechnung. Hinter einem Stand steht, woher er kommt: ohne Zusatz
abgelesen, **S** als geschätzt erfasst, **E** ein Ersatzwert, den die App
zwischen zwei Ablesungen tagesgenau ermittelt, genau dort, wo auch der Versorger
schätzt.

Stimmen die Kubikmeter, aber nicht die kWh, fehlt meist ein Faktor-Zeitraum
(Schritt 2). Die Aufteilung auf die Abschnitte kann um wenige Kubikmeter
abweichen, weil der Versorger den Zwischenstand anders schätzt; die Summe bleibt
gleich.

## 5. Saldo vergleichen

**Verbrauch → Gas**, Tabelle **Verträge & Abschläge**: Für den Vertrag 2025
steht dort *Verbraucht* 1.655,00 €, *Bezahlt* 1.800,00 € und als Saldo
**+145,00 €** — ein Guthaben, wie auf der Rechnung. Das Vorzeichen folgt der
Kundensicht: + ist Guthaben, − Nachzahlung.

Weicht der Betrag ab, ist es fast immer eines davon: ein fehlender Faktor, ein
anders datierter Preis, ein Abschlag, der nicht in jedem Monat gezahlt wurde,
oder eine Rück- oder Nachzahlung aus dem Vorjahr, die noch nicht gebucht ist
(Schritt 6 der letzten Abrechnung).

## 6. Guthaben oder Nachzahlung buchen

Das Guthaben fließt erst nach der Rechnung — gebucht wird es als
**Sonderzahlung** im Vertrag (**„+ Sonderzahlung“**), Betrag immer positiv. Zwei
Fälle:

- **Der Vertrag läuft weiter** (derselbe Vertrag über den Jahreswechsel):
  Art **„Rückzahlung (mit Auswirkung auf Abschläge)“**, Betrag 145,00 €, dazu
  der **neue Abschlag** 140 € ab 01.03.2026. Der Saldo verrechnet das Guthaben,
  und ab März rechnet die App mit 140 € weiter.
- **Mit der Rechnung endet der Vertrag**, und ein neuer beginnt: im alten
  Vertrag **„Rückzahlung (ohne Auswirkung auf Abschläge)“** über 145,00 € — sein
  Saldo steht danach bei null —, und im neuen Vertrag den Abschlag 140 € ab
  01.03.2026 als gewöhnliche Abschlagszeile.

Eine **Nachzahlung** bucht sich genauso, mit der Art „Nachzahlung …“. Nicht
beides tun: Wer den neuen Abschlag schon als Abschlagszeile eingetragen hat,
nimmt die Art „ohne Auswirkung“ ([Sonderzahlungen](../verstehen/10-sonderzahlungen.md)).

## 7. Danach

- Die **Saldo-Karte** rechnet jetzt bis zum nächsten Abrechnungsstichtag
  (Einstellungen → Verbrauchsarten & Abrechnung → Stichtag Gas; Standard
  1. Januar) und schlägt einen Abschlag vor. Liegt der Vorschlag weit neben dem
  neuen Abschlag des Versorgers, lohnt ein Blick in die Prognose.
- Mit gepflegter Kündigungsfrist erinnert die App rechtzeitig vor dem
  Kündigungsstichtag; **Kosten & Verträge → Wechsel prüfen** vergleicht Angebote.
- Nächstes Jahr dieselben Schritte — meist nur 1, 2 und 6.

---

[← Kompendium-Index](../README.md)
