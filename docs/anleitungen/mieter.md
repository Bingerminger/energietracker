# Als Mieter: Heizung, Wasser und Nebenkosten im Blick

**Deutsch** · [English](../en/anleitungen/mieter.md)

[← Kompendium-Index](../README.md)

Seit v3.1.0 hilft der Energietracker auch, wenn du zur Miete wohnst und
Heizung und Wasser über die Nebenkosten an den Vermieter zahlst. Du siehst
deinen Verbrauch Monat für Monat, die App schätzt, ob die Vorauszahlung für
den laufenden Abrechnungszeitraum reicht, hebt die Nebenkostenabrechnungen
samt PDF auf und trägt die Fristen in den Kalender ein.

> **Eine Hilfsrechnung, keine Nebenkostenabrechnung.** Die App rechnet mit
> deinen Zählern und den Preisen der letzten Abrechnung. Was der Vermieter
> abrechnet, kann abweichen. Sie gibt keine Rechtsberatung: Gesetze nennt
> diese Seite zur Orientierung, ohne Gewähr.

**Was du brauchst:**

- die **monatliche Verbrauchsinfo** des Messdienstes (Heizung, oft auch
  Warmwasser) — per Portal, App oder Brief,
- die **letzte Nebenkostenabrechnung** — für Abrechnungszeitraum, Preise und
  Vorauszahlung,
- wenn du sie selbst abliest: die Stände der Wasserzähler in der Wohnung.

---

## 1. Wohnverhältnis einstellen

**Einstellungen → Haushalt & Gebäude → „Wohnen und Warmwasser“ → „Ich wohne“:
„zur Miete“.**

Danach steht unter **Kosten & Verträge** die Seite **„Mietverhältnis“**, und
Kalender und „Zu tun“ kennen zwei neue Fristen ([Abschnitt 8](#8-fristen-im-kalender)).
Wer „im Eigentum“ wohnt (Standard), sieht keine Änderung.

In derselben Gruppe:

| Feld | Wozu |
|---|---|
| „Heizwärme kommt aus“ | Energieträger der Heizung im Haus (Gas, Heizöl, Pellets, Fernwärme, Strom) — für den CO₂-Wert der Heizwärme. Eine Näherung, s. [Heizwärme](../verstehen/15-waerme.md#5-co₂--eine-näherung). |
| „Warmwasser wird erwärmt mit“ | Nur zur Information. |
| „Warmwassertemperatur“ | Für die Wärme der Warmwasserzähler, Standard 60 °C. Unbekannt: so lassen. |

---

## 2. Heizwärme einschalten

**Einstellungen → Verbrauchsarten & Abrechnung → Aktive Verbrauchsarten →
Heizwärme** anhaken.

Heizwärme ist die Wärme, die in deiner Wohnung ankommt — nicht der Brennstoff
im Keller. Sie hat keine Versorgerverträge; bezahlt wird über das
Mietverhältnis. Hintergrund: [Heizwärme](../verstehen/15-waerme.md).

---

## 3. Einen Zähler für die Verbrauchsinfo anlegen

**Verbrauch → Heizwärme → Zähler → „+ Neuer Zähler“:**

- **Name:** frei, etwa „Heizung Wohnung“.
- **Rolle:** „Verbrauch der Wohnung“ (Standard).
- **Erfassung:** **„Verbrauch je Zeitraum“**. Die Verbrauchsinfo nennt den
  Verbrauch des Monats, keinen Zählerstand — genau dafür ist diese
  Erfassungsart da.

Die Erfassungsart lässt sich nur ändern, solange der Zähler keine Daten hat.
Hast du einen eigenen Wärmezähler in der Wohnung, dessen Stand du ablesen
kannst, nimm stattdessen „Zählerstände“.

**Jeden Monat eintragen:** **Zählerstände** (bzw. „Erfassen“ auf dem Handy).
Der Zähler hat dort eine eigene Karte:

1. **Monat** — vorbelegt mit dem Monat nach dem letzten Eintrag.
2. **Verbrauch (kWh)** — der Wert aus der Verbrauchsinfo.
3. Aufklappbar **„Vergleichswerte laut Verbrauchsinfo“**: Vormonat,
   Vorjahresmonat, Durchschnittsnutzer. Freiwillig; die App hebt sie auf und
   zeigt sie in der Tabelle, rechnet aber nicht damit.

Ohne Netz landet der Eintrag in der Warteschlange und wird nachgeholt.

**Ältere Monate auf einmal:** Verbrauch → Heizwärme → Zähler → **CSV-Import**,
je Zeile Monat und Verbrauch:

```text
Monat;Verbrauch
01.2026;1180
02.2026;960
03.2026;720
```

(Beispielwerte.) Statt `01.2026` geht auch `01/2026` oder `2026-01`, statt
eines Monats `von;bis;Verbrauch` mit zwei Daten. Die Vorschau zeigt vorher, was
eingelesen würde; schon vorhandene Monate werden übersprungen und gemeldet.

Einzelne Zeiträume bearbeitest oder löschst du unter Verbrauch → Heizwärme in
der Tabelle **„Zeiträume“**.

---

## 4. Warm- und Kaltwasser

In den meisten Mietwohnungen hängen ein Kalt- und ein Warmwasserzähler
nebeneinander. Lege unter **Verbrauch → Wasser → Zähler** für jeden einen Zähler
an:

| Zähler | Rolle | Elternzähler |
|---|---|---|
| Kaltwasser | „Kaltwasser“ | keiner |
| Warmwasser | „Warmwasser“ | keiner |

Beide stehen **nebeneinander**, nicht als Subzähler — ihre Summe ist das
Frischwasser der Wohnung.

**Erfassung:** „Zählerstände“, wenn du die Zähler selbst abliest; „Verbrauch
je Zeitraum“ (in m³), wenn die Verbrauchsinfo das Warmwasser monatlich nennt.

Für den Warmwasserzähler rechnet die App aus, wie viel Wärme das Wasser
gebraucht hat — „Wärme für dieses Warmwasser {Jahr}: rund … kWh“ unter der
Tabelle in der Verbrauchsansicht Wasser. Ein Rechenwert nach HeizkostenV § 9
(2,5 kWh je m³ und Grad über 10 °C), kein Messwert;
[Wasser](../verstehen/03-wasser.md#wärme-für-das-warmwasser-v310).

Einen Wasservertrag brauchst du als Mieter nicht — das Wasser zahlst du über
die Nebenkosten.

---

## 5. Mietverhältnis anlegen

**Kosten & Verträge → Mietverhältnis → „+ Mietverhältnis“.**

| Feld | Was eintragen |
|---|---|
| Bezeichnung | frei, etwa „Wohnung“ — erscheint in den Fristen im Kalender |
| Vermieter oder Verwaltung | freiwillig |
| Wohnfläche laut Mietvertrag (m²) | *(v3.1.0)* die Fläche aus dem Mietvertrag; leer gilt die Wohnfläche aus Einstellungen → Haushalt & Gebäude. Grundlage der CO₂-Stufe |
| Beginn / Ende | Einzug; Ende leer lassen, solange es läuft |
| Abrechnungszeitraum beginnt am (TT.MM.) | Erster Tag des Abrechnungszeitraums laut Abrechnung, etwa `01.01.` beim Kalenderjahr oder `01.07.` |
| Vorauszahlungen | je Zeile: **Ab**, „Heizung € je Monat“, „Betriebskosten € je Monat“. Ändert sich die Vorauszahlung, eine neue Zeile mit dem neuen Datum — die alte bleibt für die Vergangenheit. |
| Preise | je Zeile: **Ab**, „Wärme € je kWh“, „Warmwasser € je m³“, „Kaltwasser € je m³“. Aus der letzten Abrechnung; die App kann sie selbst übernehmen ([Abschnitt 7](#7-abrechnung-erfassen-und-preise-übernehmen)). |
| Pauschale Umlagen | je Zeile: **Ab**, Bezeichnung, „€ je Jahr“ — was nicht nach Verbrauch abgerechnet wird: Müll, Hausreinigung, Versicherung, Kabel … |
| Zugeordnete Zähler | Heizung: der Heizwärme-Zähler; Warmwasser und Kaltwasser: die Wasserzähler |
| CO₂-Kosten (CO2KostAufG) | *(v3.1.0)* „Gas auch für eigene Geräte (z. B. Gasherd)“ und „Öffentlich-rechtliche Vorgaben (§ 9)“ — Kürzungen beim Anteil des Vermieters an den CO₂-Kosten ([CO₂-Kosten teilen](co2-aufteilung.md#5-etagenheizung-selbst-ausrechnen-und-einfordern)) |
| Notiz | frei |

Ein Zähler ohne Häkchen fließt nicht in die Rechnung ein. Den Preis für
Kaltwasser rechnet die App **mit Abwasser** (beides je m³ Frischwasser).

In Deutschland zeigt die Seite seit v3.1.0 außerdem die Karte **„CO₂-Kosten
teilen“**: welchen Teil der CO₂-Kosten fürs Heizen der Vermieter trägt — bei
eigener Gastherme zum Einfordern, bei Zentralheizung zum Nachprüfen der
Heizkostenabrechnung. Wie: [CO₂-Kosten mit dem Vermieter teilen](co2-aufteilung.md).

> **Tipp zu den Umlagen.** Trägst du eine Vorauszahlung für Betriebskosten
> ein, gehören die Betriebskosten auch als Umlagen hinein — sonst steht der
> Vorauszahlung keine Ausgabe gegenüber, und die Rechnung zeigt ein zu großes
> Guthaben. Wer nur die Heizung im Blick haben will, lässt beides weg.

---

## 6. Die Hilfsrechnung lesen

Die erste Karte, **„Vorauszahlung und Kosten“**, rechnet den **laufenden
Abrechnungszeitraum** — vom Stichtag bis ein Jahr später; der erste Zeitraum
beginnt mit dem Einzug.

| Anzeige | Bedeutung |
|---|---|
| Erwartete Kosten | Was der ganze Zeitraum voraussichtlich kostet |
| Vorausgezahlt | Summe der Vorauszahlungen im Zeitraum |
| Voraussichtlich | „… Nachzahlung“, „… Guthaben“ oder „etwa ausgeglichen“, dazu eine Einschätzung |
| Passende Vorauszahlung je Monat | Erwartete Kosten geteilt durch die Monate des Zeitraums, auf ganze Euro aufgerundet |

Die **Einschätzung**:

| Anzeige | Wann |
|---|---|
| „Kaum Nachzahlung zu erwarten“ | Ergebnis Guthaben oder ausgeglichen |
| „Kleine Nachzahlung möglich“ | Nachzahlung bis 10 % der Vorauszahlung |
| „Nachzahlung wahrscheinlich“ | mehr als 10 % |

Ohne Vorauszahlung gibt es keine Einschätzung.

**So rechnet die App, je Monat:**

```text
erwartet = Wärme-kWh × Wärmepreis
         + Warmwasser-m³ × Warmwasserpreis
         + Kaltwasser-m³ × Kaltwasserpreis
         + pauschale Umlagen / 12
bezahlt  = Vorauszahlung Heizung + Betriebskosten
```

- **Gemessene Monate** kommen aus den zugeordneten Zählern.
- **Die übrigen Monate sind geschätzt:** die Wärme aus dem Heizmodell mit dem
  langjährigen Mittel des Wetters (Klimanormal), sonst aus dem Vorjahresmonat,
  sonst aus dem Tagesmittel der bekannten Monate. In der Tabelle „Monat für
  Monat“ tragen sie den Vermerk „geschätzt“.
- Monate am Rand des Zeitraums zählen anteilig.
- Fehlt etwas, steht es unter der Karte: kein Preis für Wärme, Warmwasser oder
  Kaltwasser, keine Vorauszahlung für einen Teil des Zeitraums, keine Zähler
  zugeordnet, Zahl der geschätzten Monate.

> **Beispiel** (runde Beispielzahlen, nur Heizung): Wärmepreis 0,15 € je kWh,
> erwartete Heizwärme im Jahr 9.000 kWh, Vorauszahlung Heizung 120 € im Monat.
>
> ```text
> erwartet  = 9.000 kWh × 0,15 €  = 1.350 €
> bezahlt   = 12 × 120 €          = 1.440 €
> Ergebnis  = 1.350 € − 1.440 €   =   −90 €   → 90 € Guthaben
> passende Vorauszahlung = 1.350 € / 12 = 112,50 € → 113 €
> ```
>
> Die Karte zeigt „90 € Guthaben“ und „Kaum Nachzahlung zu erwarten“.

---

## 7. Abrechnung erfassen und Preise übernehmen

Kommt die Nebenkostenabrechnung, trage sie unter **„Nebenkostenabrechnungen“ →
„+ Abrechnung“** ein:

| Feld | Was eintragen |
|---|---|
| Zeitraum von / bis | Abrechnungszeitraum laut Abrechnung |
| Erhalten am | Tag, an dem die Abrechnung ankam — daraus rechnet die App die Einwandfrist |
| Kosten insgesamt / Vorausgezahlt | die Summen der Abrechnung; das Ergebnis (positiv = Nachzahlung) rechnet die App |
| Wärmeverbrauch laut Abrechnung (kWh) / Heizkosten | aus dem Abschnitt Heizkosten |
| Posten | je Zeile Bezeichnung, Art (Heizung, Warmwasser, Kaltwasser, Abwasser, Betriebskosten, Sonstiges), Betrag und — für Wasser — der Verbrauch in m³ |
| Neue Vorauszahlung laut Abrechnung | Ab, Heizung und Betriebskosten je Monat |
| PDF oder Foto anhängen | die Abrechnung selbst, als Beleg |

Zwei Häkchen, beide vorausgewählt:

- **„Preise daraus übernehmen (ab dem Tag nach dem Zeitraum)“** — die App
  rechnet die Preise aus und trägt sie mit der Quelle „Abrechnung“ ins
  Mietverhältnis ein:

  ```text
  Wärme       = Heizkosten / Wärmeverbrauch       (ohne Heizkosten: Summe der Posten „Heizung“)
  Warmwasser  = Posten „Warmwasser“ / deren m³
  Kaltwasser  = (Posten „Kaltwasser“ + „Abwasser“) / m³ der Posten „Kaltwasser“
  ```

  Fehlt ein Verbrauch, fehlt dieser Preis. Speicherst du dieselbe Abrechnung
  noch einmal, ersetzt die App ihre Preiszeile, statt eine zweite anzulegen.
- **„Neue Vorauszahlung übernehmen“** — die neue Vorauszahlung kommt in die
  Liste der Vorauszahlungen.

> **Beispiel** (Beispielzahlen): Heizkosten 1.200 € bei 8.000 kWh → 0,15 € je
> kWh. Warmwasser 300 € bei 25 m³ → 12 € je m³. Kaltwasser 150 € und Abwasser
> 100 € bei 50 m³ → 5 € je m³.

> **Worauf bezieht sich der Wasserpreis?** Viele Abrechnungen verteilen Wasser
> und Abwasser nach dem **gesamten** Frischwasser (kalt und warm) und das
> Erwärmen des Warmwassers extra. Steht das so auf deiner, trage beim
> Kaltwasser-Posten das gesamte Frischwasser als Verbrauch ein und ordne im
> Mietverhältnis bei „Kaltwasser“ **beide** Wasserzähler zu, bei
> „Warmwasser“ nur den Warmwasserzähler.

---

## 8. Fristen im Kalender

Mit „zur Miete“ rechnet die App zwei Fristen aus. Sie stehen im
[Kalender-Abo](kalender.md) und in der Übersicht der Termine:

| Eintrag | Datum | Wann er erscheint |
|---|---|---|
| „Nebenkostenabrechnung bis zum … fällig: …“ | Ende des letzten abgelaufenen Abrechnungszeitraums + 12 Monate (§ 556 Abs. 3 BGB) | solange für diesen Zeitraum keine Abrechnung erfasst ist; nicht unter „Zu tun“ |
| „Einwände gegen die Nebenkostenabrechnung bis heute: …“ | „Erhalten am“ + 12 Monate | nur mit eingetragenem Zugang; unter „Zu tun“ ab 30 Tagen vorher |

Mit eigener Gastherme kommt seit v3.1.0 eine dritte dazu: „CO₂-Kosten {Jahr}:
Vermieteranteil von … bis heute einfordern“ — zwölf Monate nach dem Datum der
Gasrechnung ([CO₂-Kosten teilen](co2-aufteilung.md#7-frist-im-kalender)).

Keine davon hat im Kalender eine eigene Vorwarnung. Die App rechnet nur die Daten
aus; ob und wie eine Frist im Einzelfall gilt, beurteilt sie nicht. Bei
Fragen helfen Mietervereine oder eine Rechtsberatung.

---

## 9. Was die Heizkostenverordnung vorsieht

Zur Orientierung, ohne Gewähr — maßgeblich ist der Text der Verordnung:

- **§ 6a — monatliche Verbrauchsinfo.** Seit 2022 müssen Mieter mit
  fernablesbaren Zählern bzw. Heizkostenverteilern jeden Monat ihren Verbrauch
  an Heizung und Warmwasser erfahren, mit Vormonat, Vorjahresmonat und einem
  Durchschnittsnutzer.
- **§ 5 — Nachrüstung.** Geräte, die nicht fernablesbar sind, müssen bis
  31.12.2026 nachgerüstet oder ersetzt werden — außer, das ist im Einzelfall
  technisch nicht möglich oder unbillig.
- **§ 12 — Kürzungsrecht.** Fehlen fernablesbare Geräte, obwohl sie
  vorgeschrieben sind (bei Einbau seit dem 01.12.2021, für alle übrigen ab
  2027), oder kommt die Verbrauchsinfo nicht oder unvollständig, darfst du
  deinen Kostenanteil um 3 % kürzen; werden die Kosten nicht verbrauchsabhängig
  abgerechnet, um 15 %.

Kommt keine Verbrauchsinfo, obwohl die Zähler fernablesbar sind, frag beim
Vermieter oder der Verwaltung nach.

---

## 10. Grenzen

- **Hilfsrechnung.** Die Abrechnung des Vermieters folgt eigenen Regeln: Sie
  teilt die Heizkosten in Grund- und Verbrauchskosten, rechnet mit den
  Brennstoffkosten des ganzen Hauses und kennt Posten, die du nicht
  eingetragen hast. Die App nähert das mit den Preisen der letzten Abrechnung
  an.
- **Preise ändern sich.** Steigen die Preise im laufenden Jahr, weiß die App
  das erst, wenn du eine neue Zeile unter „Preise“ mit einem späteren „Ab“
  einträgst.
- **Geschätzte Monate** sind Schätzungen — je mehr gemessene Monate, desto
  besser die Rechnung.
- **Kein Abrechnungsprogramm für Vermieter.** Die App hält fest, was dir
  berechnet wird; Nebenkosten verteilen kann sie nicht
  ([Anwendungsfälle](anwendungsfaelle.md)).
- **Umzug:** Lege für die neue Wohnung ein zweites Mietverhältnis an und trage
  beim alten ein Ende ein. Oben auf der Seite wählst du, welches angezeigt
  wird.
- **Löschen:** Ein Mietverhältnis löschen entfernt auch seine Abrechnungen; die
  angehängten Belege werden gelöst und nach 24 Stunden aufgeräumt.

Technische Einzelheiten:
[API-Referenz](../referenz/api.md#mietverhältnis-v310) ·
[Datenmodell](../referenz/datenmodell.md) ·
[Ansichten](../referenz/ansichten.md#mietverhältnis-v310).

---

[← Kompendium-Index](../README.md) · [Fristen und Termine im Kalender](kalender.md)
