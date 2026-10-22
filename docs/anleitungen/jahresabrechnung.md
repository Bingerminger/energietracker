# Jahresabrechnung eintragen und prüfen

**Deutsch** · [English](../en/anleitungen/jahresabrechnung.md)

[← Kompendium-Index](../README.md)

Eine Gasrechnung in den Energietracker übernehmen, nachrechnen und das Guthaben
buchen. Danach zeigt die App denselben Saldo wie die Rechnung, und die
Prognose rechnet mit dem neuen Abschlag weiter. Für Strom, Wasser und
Fernwärme gilt dasselbe ohne Schritt 2 — seit v3.1.0 auch die
Rechnungsprüfung, und die Werte der Rechnung lassen sich erfassen, vergleichen
und als Sonderzahlung buchen (Schritt 7). Beim Wasser gibt es die
Sonderzahlungen aus Schritt 6 und 7 erst seit v3.5.0
([#34](https://github.com/Bingerminger/energietracker/issues/34)); bis dahin
kam eine Buchung dort nicht im Vertrag an.

---

## Die Rechnung — ein Beispiel

Alle Zahlen sind erfunden, aber durchgerechnet; so oder ähnlich sieht der Teil
einer Gasrechnung aus, auf den es ankommt:

```text
Stadtwerke Musterstadt · Gas Basis 24            Abrechnungszeitraum 01.01.2025 – 31.12.2025

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
| Energie und Kosten je Zeitraum | Rechnung prüfen (Schritt 4) |
| Guthaben bzw. Nachzahlung | Saldo des Vertrags (Schritt 5), danach als Sonderzahlung gebucht (Schritt 6 oder 7) |
| Rechnungsbetrag, Abschläge, Ergebnis, Umlagen, CO₂-Angaben | „Laut Rechnung“ (Schritt 7) |
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

**Fernwärme** hat seit v3.1.0 eigene feste Kosten: im Vertragsdialog die
Listen **„Leistungspreis“** (€ je kW und Jahr) und **„Messpreis“** (€ je Jahr),
je mit Stichtag, und in der Feldgruppe „Fernwärme: Anschluss und Kennwerte“ die
**Anschlussleistung (kW)**. Die App rechnet daraus feste Kosten je Monat
([Fernwärme](../verstehen/04-fernwaerme.md#feste-kosten-leistungs--und-messpreis-v310)).
**Wasser** hat Trink-, Schmutz- und Niederschlagswasser
([Wasser](../verstehen/03-wasser.md)).

**Hoch- und Niedertarif (seit v3.1.0).** Rechnet die Stromrechnung zwei
Zählwerke mit einem Grundpreis ab, legst du beide als Zähler an, fasst sie zu
einer **Gruppe** zusammen (⚙️ Zähler → „Zu Gruppe zusammenfassen“) und den
Vertrag für die Gruppe an: unter „Zähler“ die Gruppe, unter **„Arbeitspreis je
Zähler (z. B. HT/NT)“** je Zählwerk sein Preis. Grundpreis und Abschlag zählen
einmal ([Strom](../verstehen/02-strom.md#hoch--und-niedertarif-ein-vertrag-für-eine-zählergruppe-v310)).

## 4. Nachrechnen

**Kosten & Verträge → Rechnung prüfen** (oder auf der Seite der Verbrauchsart
„Rechnung … prüfen“). Seit v3.1.0 gibt es die Prüfung für **Gas, Strom, Wasser
und Fernwärme** — oben wählst du die Verbrauchsart, daneben den Zähler, wenn es
mehrere gibt. Seit v3.4.0 rechnet die Seite sofort, auf beiden Wegen, mit der
letzten abgeschlossenen Abrechnungsperiode: Stichtag unter Einstellungen →
Verbrauchsarten & Abrechnung, Standard 1. Januar — im Beispiel also **Von**
01.01.2025 und **Bis einschließlich** 31.12.2025, wie auf der Rechnung. Weicht
der Zeitraum der Rechnung ab, beide Daten ändern und **„Nachrechnen“**. Ein
Wechsel von Art oder Zähler behält den Zeitraum und rechnet neu.

Die Tabelle schneidet den Zeitraum in Abschnitte — wie die Zeilen der
Rechnung:

- **Gas:** an jeder Ablesung und jedem Brennwertwechsel; je Abschnitt
  Kubikmeter, Zustandszahl, Brennwert und kWh.
- **Strom, Wasser, Fernwärme:** an jeder Ablesung und an jedem Stichtag des
  Vertrags — Beginn, Ende, Preisänderung, Abschlagsänderung (Grenze
  „Preiswechsel“).

Je Abschnitt stehen außerdem **Preis** (ct je kWh), **Verbrauchskosten** und
**Feste Kosten** — gerechnet mit denselben Bausteinen wie Monatstabelle und
Saldo: der Arbeitspreis am Tag, der Grundpreis nach Kalendertagen des Monats,
bei Fernwärme dazu Leistungs- und Messpreis. Bei Wasser sind die
Verbrauchskosten Trink- und Schmutzwasser je m³, die festen Kosten Grundpreis
und Niederschlagswasser. Darunter die Summe: „Nachgerechnet: …“, abzüglich
eines Bonus, der im Zeitraum gutgeschrieben wurde.

Hinter einem Stand steht, woher er kommt: ohne Zusatz abgelesen, **S** als
geschätzt erfasst, **E** ein Ersatzwert, den die App zwischen zwei Ablesungen
tagesgenau ermittelt, genau dort, wo auch der Versorger schätzt.

**Gruppenvertrag (HT/NT).** Die Seite rechnet je Zähler: Das erste Zählwerk
der Gruppe trägt die festen Kosten, das zweite nur seinen Verbrauch — die
Summe beider ist die Rechnung. Beide auf einmal, mit den Abschnitten
nacheinander und den festen Kosten einmal, rechnet die API nach
(`GET /api/utility/strom/meter-groups/{id}/bill-check`,
[API-Referenz](../referenz/api.md#gruppenvertrag-v310)).

Stimmen die Kubikmeter, aber nicht die kWh, fehlt meist ein Faktor-Zeitraum
(Schritt 2). Die Aufteilung auf die Abschnitte kann um wenige Kubikmeter
abweichen, weil der Versorger den Zwischenstand anders schätzt; die Summe bleibt
gleich. Fehlt für einen Teil des Zeitraums ein Preis im Vertrag, sagt die Seite
das — dort fehlen auch die Kosten.

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

Seit v3.1.0 geht das auch mit einem Knopf aus der erfassten Rechnung
(Schritt 7) — dann immer als Art „ohne Auswirkung“.

## 7. „Laut Rechnung“: erfassen, vergleichen, buchen

Seit **v3.1.0** hält die App die Rechnung des Versorgers selbst fest und
stellt sie der eigenen Rechnung gegenüber. Auf **Rechnung prüfen** steht unter
der Tabelle für den gewählten Zähler der eingeklappte Abschnitt **„Rechnung des
Versorgers erfassen (optional)“** mit der Karte **„Laut Rechnung“** und der
Liste der erfassten Rechnungen. Seit v3.4.0 gilt dort der Zeitraum von oben
(Schritt 4) — ein Zeitraum für Prüfen und Erfassen.

**Erfassen.** Aus dem Beispiel:

| Feld | Eintrag |
|---|---|
| Zeitraum | oben unter „Von“ / „Bis einschließlich“: 01.01.2025 / 31.12.2025 — der letzte Tag zählt mit, wie auf der Rechnung |
| Rechnungsdatum | das Datum der Rechnung, etwa 10.02.2026 |
| Verbrauch laut Rechnung (kWh) | 16.437 — bei Wasser in m³ |
| Rechnungsbetrag (brutto) | 1.655,00 |
| Gezahlte Abschläge | 1.800,00 |
| Nachzahlung / Guthaben | leer lassen: Die App rechnet Betrag − Abschläge = −145,00. **Positiv = Nachzahlung**, negativ = Guthaben |
| Weitere Posten | Umlagen, Gebühren, Gutschriften mit Bezeichnung und Betrag (Gutschrift negativ) — die App rechnet sie nicht nach, sondern zählt sie zur eigenen Summe |
| Emissionen (kg CO₂), CO₂-Kosten laut Rechnung | nur bei Gas: die CO₂-Angaben der Rechnung, den Betrag so, wie er dort steht (mit Umsatzsteuer, CO2KostAufG § 3 Abs. 3). Sie gehen dem Standardfaktor vor ([CO₂-Preis](../verstehen/16-co2-preis.md)) und setzen zur Miete die Frist für die Erstattung ([CO₂-Kosten teilen](co2-aufteilung.md)) |
| Rechnung anhängen (PDF oder Foto) | der Beleg; die Liste zeigt ihn als 📄 |

**„Rechnung speichern“** legt sie an und zeigt gleich den Vergleich.

**Vergleichen.** Die Liste darunter führt jede Rechnung mit Zeitraum, Menge,
Rechnungsbetrag und Nachzahlung/Guthaben. **„Vergleichen“** zeigt eine Tabelle
**Nachgerechnet · Laut Rechnung · Abweichung** für Menge, Betrag und gezahlte
Abschläge, dazu ein Urteil:

- **„passt“** — Menge auf 1 % genau und Betrag auf 1 % oder 2 € genau;
- **„keine Preise“** *(v3.7.0)* — die Menge passt (oder fehlt), aber für einen
  Teil des Zeitraums fehlt im Vertrag ein Preis: Den Betrag kann die App nicht
  nachrechnen. Sie nennt die Abschnitte samt Vertrag und führt zu den
  Verträgen;
- **„prüfen“** — sonst; ohne Menge und Betrag gibt es kein Urteil. Weicht die
  Menge ab und fehlen zugleich Preise, bleibt es „prüfen“, und der Hinweis auf
  die fehlenden Preise steht trotzdem darunter, samt Abschnitten und Sprung zu
  den Verträgen *(v3.7.0)*.

Darunter die möglichen Gründe einer Abweichung, etwa: ein geschätzter oder
interpolierter Stand an einem Rand des Zeitraums, ein Preiswechsel im
Zeitraum, ein Brennwert laut Rechnung, der von den Gas-Umrechnungsfaktoren in
den Einstellungen abweicht (beide Werte, mit Sprung zur Einstellung), weitere
Posten, die die App nur übernimmt, ein fehlender Preis im Vertrag oder
fehlende Zählerstände. Seit v3.7.0 gilt eine Ablesung am letzten Tag des
Zeitraums (oder am Tag vor dem ersten) als Grenzstand wie auf der Rechnung,
nicht als Schätzung, und ein Brennwertwechsel, den die Gas-Umrechnung so kennt,
wie die Rechnung ihn aufteilt, ist kein Grund. Nachgerechnet heißt:
Verbrauchskosten + feste Kosten − Bonus + weitere Posten; an dieser Rechnung
ändert die Einstufung nichts.

**Buchen.** **„Als Sonderzahlung buchen“** legt das Ergebnis als
Sonderzahlung im Vertrag an — eine Nachzahlung bzw. Rückzahlung **„ohne
Auswirkung auf Abschläge“**, Betrag ohne Vorzeichen, Datum = Rechnungsdatum
(ohne Datum der Tag nach dem Zeitraum), Notiz „Jahresabrechnung … – …“. Der
Vertrag ist der, der am Ende des Zeitraums gilt, wenn er den Zeitraum
überwiegend abrechnet; sonst der, der das tut (etwa der alte Vertrag einer
Schlussrechnung, die einen Tag über sein Ende reicht) *(v3.7.0 — bis v3.6 nahm
die App den jüngsten Vertrag des Zählers, auch wenn er den Zeitraum gar nicht
berührte)*. Danach trägt die Rechnung „gebucht“; ein zweites Buchen ändert
nichts. Den neuen Abschlag trägst du als Abschlagszeile am Vertrag ein
(Schritt 6). Ohne Ergebnis oder ohne Vertrag für den Zeitraum lehnt die App ab
und sagt warum.

**Löschen** (🗑️) fragt nach. Eine schon gebuchte Sonderzahlung bleibt im
Vertrag; sie löschst du dort, wenn sie weg soll.

**Bei einem Gruppenvertrag** erfasst du „Laut Rechnung“ an einem der
Zählwerke. Der Vergleich rechnet trotzdem die ganze Gruppe nach — beide
Zählwerke, die festen Kosten und den Abschlag einmal —, denn der Versorger
schickt eine Rechnung für beide. „Als Sonderzahlung buchen“ bucht in den
Gruppenvertrag.

## 8. Danach

- Die **Saldo-Karte** rechnet jetzt bis zum Ende der laufenden
  Abrechnungsperiode, dem Tag vor dem nächsten Stichtag, und schlägt einen
  Abschlag vor. Der **Stichtag** (Einstellungen → Verbrauchsarten & Abrechnung;
  Standard 01-01) ist der **erste Tag** der Abrechnungsperiode: 01-09 heißt
  1.9. bis 31.8. Liegt der Vorschlag weit neben dem neuen Abschlag des
  Versorgers, lohnt ein Blick in die Prognose.
- Hast du das Ergebnis gebucht („Als Sonderzahlung buchen“), rechnet der Saldo
  eines weiterlaufenden Vertrags seit v3.6.0 ab dem Tag nach der Rechnung — die
  abgerechnete Zeit und die Rück- oder Nachzahlung zählen nicht mehr mit. Ohne
  Buchung bleibt das Ergebnis im Saldo stehen.
- Beim nächsten Mal schlägt **Rechnung prüfen** die jüngste abgeschlossene
  Periode im Rhythmus deiner erfassten Rechnungen vor.
- Mit gepflegter Kündigungsfrist erinnert die App rechtzeitig vor dem
  Kündigungsstichtag; **Kosten & Verträge → Wechsel prüfen** vergleicht Angebote.
- **Abrechnungsjahr (v3.6.0):** Schalte in der Verbrauchsart auf
  *Abrechnungsjahr*, dann zeigen Kennzahlen, Diagramm und Monatstabelle genau
  den Zeitraum der erfassten Rechnung, etwa 15.04. bis 30.03., mit Abschlägen
  und Saldo; zwischen und nach den Rechnungen schneidet der Stichtag. Die
  laufende Periode beginnt am Tag nach der Rechnung. Endet die Rechnung nicht
  am Tag vor deinem Stichtag, schlägt die Ansicht den passenden Tag vor; ohne
  erfasste Rechnung *(v3.7.0)* den Beginn des Vertrags nach dem jüngsten
  Lieferantenwechsel oder den Tag, an dem mehrere Verträge beginnen. Die
  Wahl gilt auf diesem Gerät auch für Übersicht und Analyse; der Jahresbericht
  hat dafür den Anhang „Abrechnungsjahre“, der CSV-Export eine Tabelle je
  Periode (Einstellungen → Daten).
- **Versorger gewechselt:** Beginnt mitten in einer Periode der Vertrag eines
  anderen Versorgers und ist noch keine Schlussrechnung erfasst, endet die
  Periode am letzten Tag beim alten Versorger („endet mit dem
  Lieferantenwechsel“), die nächste beginnt mit dem neuen Vertrag — so, wie
  der alte Versorger schlussabrechnet. Eine Verlängerung oder ein neuer Tarif
  beim selben Versorger teilt nicht. Liegt zwischen gekündigtem altem und
  neuem Vertrag eine Lücke, ist sie eine eigene Teilperiode; verlängert sich
  der alte, läuft er bis zum Tag vor dem neuen. Eine erfasste Rechnung geht
  immer vor. *(v3.7.0)* Liegt der Wechsel nur wenige Tage neben dem Stichtag —
  Stichtag 31-08, neuer Vertrag ab 01.09. —, gibt es keine Periode von einem
  Tag: Reste unter 14 Tagen gehen in die Nachbarperiode auf. Die Summen
  bleiben dieselben.
- **Laufendes Jahr in beiden Ansichten:** Das Kalenderjahr zählt Grundpreis
  und Abschlag des Monats der letzten Ablesung ganz, das Abrechnungsjahr nur
  die Tage bis zur Ablesung; das ⓘ an „Abschläge bis …“ sagt es. Ganze Jahre
  sind in beiden Ansichten gleich.
- Nächstes Jahr dieselben Schritte — meist nur 1, 2 und 7.

---

[← Kompendium-Index](../README.md)
