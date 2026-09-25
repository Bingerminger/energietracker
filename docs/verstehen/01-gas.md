# Gas

**Deutsch** · [English](../en/verstehen/01-gas.md)

[← Grundlagen](00-overview.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **kumulativ** (Zählerstände in m³) |
| Abrechnungseinheit | kWh |
| Umrechnung | `gas_conversion_factors` — datierte Liste (Zustandszahl × Brennwert je Stichtag), seit v2.5.0 |
| HGT-relevant | **ja** — Heizen dominiert den Verbrauch |
| Farbe | Orange |

## Fachlicher Hintergrund

Der Gaszähler misst **Volumen** (m³), abgerechnet wird **Energie**
(kWh). Die Umrechnung steht auf jeder Gasrechnung:

```text
kWh = m³ × Zustandszahl × Brennwert
          (≈ 0,95–1,0)    (≈ 10–11,7 kWh/m³)
```

Die **Zustandszahl** hängt an der Entnahmestelle (Höhenlage, Druck) und
ändert sich praktisch nie. Der **Brennwert** ist ein Periodenmittel des
Netzbetreibers und wechselt mehrmals im Jahr — eine Jahresrechnung führt
ihn typischerweise mit drei bis vier verschiedenen Werten, jeder mit eigenem
Zeitraum, weil der Lieferant wechselnde Bezugsquellen hat.

## Umrechnungsfaktoren mit Stichtagen (F1012, seit v2.5.0)

Bis v2.4.2 kannte der Energietracker einen einzigen Faktor. Seit v2.5.0 ist
es eine **datierte Liste** unter *Einstellungen → Verbrauchsarten & Abrechnung →
Physikalische Konstanten → Gas-Umrechnungsfaktoren* — ein Eintrag je
Brennwertperiode, so wie die Rechnung sie ausweist:

| Gültig ab | Zustandszahl | Brennwert | → Faktor |
|---|---|---|---|
| *(ohne Datum)* | — | — | 11,5000 |
| 01.01.2024 | 0,9600 | 11,400 | 10,9440 |
| 01.01.2025 | 0,9600 | 11,650 | 11,1840 |
| 01.10.2025 | 0,9600 | 11,520 | 11,0592 |

(Das sind die Werte der Demo-Daten — *Einstellungen → Daten → Backup &
Wiederherstellung → Demo-Daten laden* zeigt die Liste samt Rechnungsprüfung
sofort.)

Wichtig zu wissen:

- **Wirksam ist der letzte Eintrag, dessen Datum nicht nach dem Tag liegt.**
  Der Eintrag ohne Datum gilt für alles davor — er ist der migrierte Altwert,
  und die Historie rechnet damit exakt wie vor v2.5.0. Nichts springt
  rückwirkend.
- **Der Faktor wird aus Zustandszahl × Brennwert berechnet** und mit fünf
  Nachkommastellen gespeichert. Wer keine Aufschlüsselung hat, trägt den
  Faktor direkt ein. Die Zustandszahl wird aus dem letzten Eintrag vorbelegt.
- **Tagesgenau.** Liegt ein Stichtag mitten in einem Ableseintervall, wird
  das Intervall dort geteilt — jeder Tag rechnet mit seinem Faktor. Der
  Versorger tut dasselbe, allerdings mit *geschätzten* Zwischenständen
  (Ableseart „S" auf der Rechnung); hier braucht es keine Schätzung.
- **Plausibilitätsprüfung beim Speichern:** Zustandszahl 0,8–1,1, Brennwert
  8–13, Faktor 5–15 kWh/m³. Ein Tippfehler wie 115 statt 11,5 würde sonst
  jeden Verbrauch verzehnfachen — still.
- Heizöl und Pellets behalten ihren Skalar; dort veröffentlicht niemand
  monatlich einen neuen Brennwert.
- *(v2.7.0)* Den Brennwert nimmt die Eingabe auch in **MJ/m³** (Vereinigtes
  Königreich, Niederlande) oder **GJ/Smc** (Italien) entgegen und rechnet ihn
  in kWh/m³ um — gespeichert und geprüft wird weiter in kWh/m³. Siehe
  [Länderprofile](14-laenderprofile.md#7-gas-brennwert-einheit-und-preis-je-kubikmeter).

## Rechnungsprüfung

In der Gas-Verbrauchsansicht rechnet der Block **Rechnungsprüfung** die
Versorgerrechnung nach: Für den gewählten Zeitraum entstehen Abschnitte an
jeder Ablesung und an jedem Brennwertwechsel, je Abschnitt

```text
Zeitraum | Stand alt | Stand neu | Tage | Grenze | m³ | Zustandszahl | Brennwert | kWh/m³ | kWh
```

— genau die Zeilen, die die Rechnung zeigt, samt Zählerstand am Anfang
und Ende jedes Abschnitts. Jeder Stand trägt seine **Ableseart** wie die
Fußnoten der Rechnung (seit v2.5.2): ohne Zusatz ein abgelesener Stand,
`S` eine als geschätzt erfasste Ablesung, `E` ein **Ersatzwert** — an
diesem Tag gibt es keinen Zählerstand, er ist tagesgenau zwischen den
umschließenden Ablesungen interpoliert. Das sind genau die Stellen, an
denen auch der Versorger schätzt (Brennwert- und Zeitraumgrenzen); je
weniger `E` in der Tabelle, desto weniger Schätzung steckt im Vergleich.
Über einen Zählertausch hinweg gibt es keinen fortlaufenden Stand, der
Ersatzwert bleibt dann ohne Zahl. Weicht eine Zeile ab, ist entweder ein
Faktor falsch eingetragen oder der Versorger hat einen Zwischenstand
anders geschätzt. Abschnitte ohne umschließende Ablesung
(vor der ersten, nach der letzten) erscheinen ohne Verbrauch, damit die
Lücke sichtbar ist.

## Was Energietracker damit macht

- **Monatsverbrauch** durch lineare Interpolation zwischen Ablesungen.
- **Heizsignatur**: Da Gas meist heizt, korreliert der Verbrauch stark
  mit den Heizgradtagen. Die Analyse zeigt die Regression (oft hohes
  `R²`); die `sigmoid`-Kurve bildet die Sättigung an sehr kalten Tagen
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
erwartete Endsaldierung bis zum Abrechnungsstichtag — seit v2.8.0 nach
Kalender, mit geschätztem Verbrauch seit der letzten Ablesung, seit v2.9.0
tagesgenau: Ein Wechsel oder eine Preisänderung zur Monatsmitte gilt ab
ihrem Tag, und ein Vertrag ohne Nachfolger läuft weiter
([Grundlagen §10](00-overview.md#10-saldo-bis-heute)).

Rück-/Nachzahlungen und zusätzliche Abschlagszahlungen werden als
**[Sonderzahlungen](10-sonderzahlungen.md)** (F1003) erfasst und gehen in
den Saldo ein.

Steht der Arbeitspreis auf der Rechnung **je m³** (Italien, Niederlande),
rechnet die Hilfe „Preis je m³ umrechnen“ unter den Arbeitspreisen ihn in
ct/kWh um — geteilt durch den Brennwert, der am gewählten Tag gilt *(v2.7.0)*.

## Typische Stolpersteine

- **Falscher Brennwertfaktor** → Kosten stimmen nicht. Immer von der
  realen Rechnung übernehmen.
- **Lange Ableseintervalle** verschmieren kalte/warme Phasen. Für gute
  HGT-Korrelation häufiger ablesen (idealerweise monatlich).

[← Grundlagen](00-overview.md) · [Strom →](02-strom.md)
