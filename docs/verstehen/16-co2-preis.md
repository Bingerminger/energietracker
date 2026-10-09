# CO₂-Preis im Brennstoff

**Deutsch** · [English](../en/verstehen/16-co2-preis.md)

[← Heizwärme](15-waerme.md) · [Kompendium-Index](../README.md)

Seit **v3.1.0** zeigt der Energietracker, wie viel CO₂-Preis in deinen Kosten
für Gas und Heizöl steckt — und für Fernwärme, wenn du den Emissionsfaktor
deines Wärmenetzes kennst. Das ist ein **Ausweis, kein Aufschlag**: Der Betrag
ist schon in deinem Arbeitspreis enthalten. Die App macht ihn sichtbar, damit
du siehst, was ein steigender CO₂-Preis für dich bedeutet. Zur Miete teilst du
die CO₂-Kosten unter Umständen mit dem Vermieter:
[CO₂-Kosten mit dem Vermieter teilen](../anleitungen/co2-aufteilung.md).

> Eine Hilfsrechnung, keine Rechtsberatung. Die Gesetze nennt diese Seite zur
> Orientierung, ohne Gewähr.

---

## 1. Was der CO₂-Preis ist

Seit 2021 zahlen in Deutschland die Lieferanten von Erdgas und Heizöl für jede
Tonne CO₂, die beim Verbrennen ihrer Brennstoffe entsteht, einen Preis — nach
dem **Brennstoffemissionshandelsgesetz (BEHG)**. Sie geben ihn über den
Arbeitspreis an ihre Kunden weiter. Bis 2025 war der Preis je Jahr fest, für
2026 gilt ein Korridor von 55 bis 65 € je Tonne. Ein Gesetzentwurf
(BT-Drucksache 21/7869) will den Korridor auf 2027 verlängern; beschlossen ist
das noch nicht (Stand 09.10.2026).

Danach soll der **europäische Emissionshandel für Gebäude und Verkehr (ETS2)**
den nationalen Preis ablösen — nach heutigem Stand ab 2028. Wie hoch der Preis
dann ist, steht nicht fest. Für Jahre ohne festen Preis rechnet die App mit dem
zuletzt bekannten Wert und sagt das dazu ([§ 4](#4-preis-je-jahr)).

Den CO₂-Preis kennt die App nur für **Deutschland** (Länderprofil DE). In
anderen Ländern fehlt die Karte; die API antwortet mit `supported: false` und
einem Hinweis ([Länderprofile](14-laenderprofile.md)).

## 2. Ausweis, kein Aufschlag

Die App addiert den CO₂-Preis **nicht** zu deinen Kosten — er steckt in dem
Arbeitspreis, den du am Vertrag eingetragen hast. Sie rechnet nur aus, welcher
Teil davon CO₂-Preis ist:

- **mit Umsatzsteuer**, wie der Arbeitspreis auf deiner Rechnung — so weisen
  auch die Rechnungen den CO₂-Anteil aus (CO2KostAufG § 3 Abs. 3: Emissionen ×
  CO₂-Preis „zuzüglich einer auf diesen Betrag anfallenden Umsatzsteuer“); das
  ist die große Zahl auf der Karte;
- **ohne Umsatzsteuer** (netto) in der Zeile darunter.

**Welche Umsatzsteuer.** In der Regel 19 %. Auf Gas über das Erdgasnetz und auf
Fernwärme galten vom 01.10.2022 bis 31.03.2024 7 % (§ 28 Abs. 5 und 6 UStG);
Heizöl blieb bei 19 %. Die App setzt den Satz je Monat an und gewichtet ihn für
das Jahr nach dem Verbrauch: Gas 2023 rechnet sie ganz mit 7 %, Gas 2024 nur
für Januar bis März. Ohne Verbrauch im Jahr nimmt sie das Mittel der zwölf
Monate. Den angewandten Satz nennt die API je Zeile im Feld `vat`.

Die Zahl hilft beim Einordnen: Welcher Anteil am Preis ist politisch gesetzt,
und was ändert sich, wenn der CO₂-Preis steigt?

## 3. Emissionen: die Standardfaktoren des BEHG

Gerechnet wird mit den **Standardemissionsfaktoren des BEHG**, nicht mit den
BAFA-Faktoren der CO₂-Bilanz. Die BAFA-Faktoren enthalten die Vorkette
(Förderung, Transport), der CO₂-Preis wird nur auf das Verbrennen erhoben.
Deshalb stehen in der App zwei leicht verschiedene CO₂-Zahlen: die CO₂-Bilanz
in der Monatstabelle und die Emissionen auf der Karte „CO₂-Preis“.

| Verbrauchsart | Faktor | Bezug |
|---|---|---|
| Gas | 0,18139 kg CO₂ je kWh | je kWh **Brennwert**, wie die Gasrechnung zählt (Zustandszahl × Brennwert) |
| Heizöl | 0,2664 kg CO₂ je kWh | je kWh **Heizwert**, wie die App Heizöl rechnet (Liter × kWh je Liter) |
| Fernwärme | Emissionsfaktor des Wärmenetzes | nur, wenn er am Vertrag steht (Feld „CO₂-Faktor des Netzes (g/kWh)“, [Fernwärme](04-fernwaerme.md)); sonst keine Zeile |
| Heizwärme | Faktor des Energieträgers | nur, wenn „Heizwärme kommt aus“ auf Gas oder Heizöl steht — eine **Näherung** ([§ 7](#7-grenzen)) |
| Pellets, Holz, Strom | — | nicht im BEHG, nie eine Zeile |

**Woher die Emissionen einer Zeile kommen,** in dieser Reihenfolge:

1. **Versorgerrechnung** (`bill`) — eine erfasste Rechnung, deren Zeitraum in
   diesem Jahr endet und die CO₂-Angaben trägt
   ([Jahresabrechnung](../anleitungen/jahresabrechnung.md#7-laut-rechnung-erfassen-vergleichen-buchen)).
   Steht dort auch der CO₂-Betrag, nimmt die App ihn als Betrag **mit
   Umsatzsteuer**, so wie er auf der Rechnung steht, und rechnet den
   Nettobetrag heraus (Betrag ÷ (1 + Umsatzsteuer)). Bis v3.1 las sie ihn als
   netto und schlug die Steuer ein zweites Mal auf.
2. **Vertrag** (`contract`) — bei Fernwärme der Emissionsfaktor des Netzes.
3. **Gerechnet** (`computed`) — Verbrauch des Jahres × Standardfaktor.

Gezählt werden die Zähler, die auch in die Summen der Art eingehen: keine
Subzähler, bei Heizwärme nur Zähler mit der Rolle „Verbrauch der Wohnung“.

## 4. Preis je Jahr

Die Werte des deutschen Länderprofils in € je Tonne:

| Jahr | 2021 | 2022 | 2023 | 2024 | 2025 | 2026 |
|---|---|---|---|---|---|---|
| Preis | 25 | 30 | 30 | 45 | 55 | 60 |

2021 bis 2025 sind die Festpreise, 2026 der Mittelwert des Korridors von 55 bis
65 € (§ 4 Abs. 1 Nr. 2 CO2KostAufG). Für **2027** gilt der Durchschnitt der
Versteigerungen vom 1. Juli bis 30. November 2026 (§ 4 Abs. 1 Nr. 3); das
Umweltbundesamt veröffentlicht ihn spätestens zehn Werktage vor Jahresbeginn
(§ 4 Abs. 2). Solange er nicht im Länderprofil steht, setzt die App für 2027
und spätere Jahre den **letzten bekannten Wert als Annahme** an — also 60 € —
und schreibt darunter: „Der Preis dieses Jahres steht noch nicht fest;
angesetzt ist der zuletzt bekannte.“ Die 60 € sind nur ein Platzhalter, nicht
der Preis für 2027.

Eigene Werte trägst du unter **Einstellungen → Experte → Rechenparameter →
„CO₂-Preis“ → „CO₂-Preis je Jahr“** ein (Jahr und € je Tonne). Sie gehen dem
Länderprofil vor und ersetzen die Annahme — etwa, sobald das Umweltbundesamt den
Preis für 2027 veröffentlicht hat oder wenn dein Lieferant einen anderen Wert
ausweist ([Einstellungen](../referenz/einstellungen.md#co₂-preis-v310)).

## 5. So wird gerechnet

```text
Emissionen [kg]    = Verbrauch [kWh] × Faktor [kg/kWh]
Betrag netto [€]   = Emissionen [kg] / 1000 × Preis [€/t]
Betrag brutto [€]  = Betrag netto × (1 + USt)      USt 19 %, Gas und Fernwärme 10/2022–3/2024 7 %
je kWh [ct]        = Betrag brutto / Verbrauch [kWh] × 100

mit CO₂-Betrag laut Rechnung:
Betrag brutto [€]  = CO₂-Betrag laut Rechnung
Betrag netto [€]   = Betrag brutto / (1 + USt)
```

**Beispiel** (erfundene Zahlen): 10.000 kWh Gas im Jahr 2025.

```text
Emissionen    10.000 kWh × 0,18139 kg/kWh  = 1.813,9 kg
netto         1.813,9 kg / 1000 × 55 €/t   =    99,76 €
brutto        99,76 € × 1,19               =   118,72 €
je kWh        118,72 € / 10.000 kWh        =     1,19 ct
```

Rund 1,2 ct jeder Kilowattstunde Gas waren 2025 also CO₂-Preis. Dieselbe Menge
im Jahr 2023 (30 €/t, Umsatzsteuer 7 %): 54,42 € netto × 1,07 = 58,23 €.

**Je m² Wohnfläche.** Die API rechnet zusätzlich die Emissionen aller Zeilen
je m² Wohnfläche und die **Stufe nach dem CO2KostAufG** aus (zehn Stufen, auf
eine Nachkommastelle gerundet). Die Fläche kommt bei „zur Miete“ aus dem
Mietverhältnis, sonst aus Einstellungen → Haushalt & Gebäude. Wofür die Stufe
gebraucht wird: [CO₂-Kosten mit dem Vermieter teilen](../anleitungen/co2-aufteilung.md).

## 6. Wo du ihn siehst

**Karte „CO₂-Preis im Brennstoff {Jahr}“** in der Verbrauchsansicht von Gas,
Heizöl, Fernwärme und Heizwärme, für das dort gewählte Jahr:

- **Darin enthalten** — der Betrag mit Umsatzsteuer,
- **Je kWh** — derselbe Betrag je kWh,
- **Emissionen (BEHG)** in kg,
- **CO₂-Preis** in € je Tonne.

Darunter eine Zeile: „Steckt schon im Arbeitspreis – kein Aufschlag. Netto …,
oben mit Umsatzsteuer.“, dazu die Quelle (Standardfaktor, Versorgerrechnung
oder Emissionsfaktor des Wärmenetzes), bei Heizwärme der Hinweis auf die
Näherung und bei einem angenommenen Preis der Hinweis darauf. Ohne Verbrauch
im Jahr oder ohne Faktor erscheint die Karte nicht. Das ⓘ erklärt den Begriff.

**Prognose — CO₂-Preis-Szenario.** Was ein höherer Preis kosten würde: Auf der
Prognose-Seite steht das Feld **„CO₂-Preis ab 2028 (€/t)“** — leer = aus. Mit
einem Wert, etwa 150, schreibt die Seite unter die Prognose:

> „Mit 150 €/t CO₂ ab 2028: +1,94 ct/kWh, in den nächsten 12 Monaten … mehr
> (mit Umsatzsteuer).“

```text
Mehrkosten je kWh [ct] = (Szenario − Preis des Jahres) × Faktor / 10 × 1,19
Beispiel Gas:           (150 − 60) × 0,18139 / 10 × 1,19 = 1,94 ct/kWh
                        bei 12.000 kWh im Jahr rund 233 €
```

- Das Szenario ist **nur ein Ausweis neben der Prognose**; deren Kosten ändert
  es nicht.
- Es gilt ab dem Jahr unter **Einstellungen → Experte → Rechenparameter →
  „CO₂-Preis“ → „Szenario ab Jahr“** (Standard 2028), für jeden Prognosemonat
  ab diesem Jahr. Den Betrag „in den nächsten 12 Monaten“ zählt die App nur
  über die Monate ab diesem Jahr: Reicht die Prognose nicht bis dorthin,
  erscheint keine Zeile, und liegen die nächsten zwölf Monate davor, steht dort
  0 €. Wer die Wirkung aufs kommende Jahr sehen will, stellt „Szenario ab Jahr“
  auf das laufende Jahr.
- Vorbelegt ist das Feld mit „Szenario: CO₂-Preis“ aus denselben
  Einstellungen.
- Ein Szenario gibt es für Gas, Heizöl und — als Näherung — Heizwärme mit Gas
  oder Heizöl als Energieträger, nicht für Fernwärme.

## 7. Grenzen

- **Standardfaktoren.** Die App rechnet mit den Faktoren des BEHG, nicht mit
  denen deines Lieferanten. Ein Tarif mit Biogasanteil trägt weniger
  CO₂-Preis; den Anteil kennt die App nicht. Trag dann die Angaben deiner
  Rechnung ein — sie gehen vor.
- **Rechnungsjahr ist nicht Kalenderjahr.** Eine Versorgerrechnung zählt mit
  ihrem ganzen Zeitraum zu dem Jahr, in dem dieser endet. Ohne Rechnung rechnet
  die App das Kalenderjahr.
- **Heizwärme ist eine Näherung.** Gezählt wird die Wärme, die in der Wohnung
  ankommt, nicht der Brennstoff dafür. Die Verluste der Heizung fehlen, die
  Zahl ist eher zu niedrig. Die Umsatzsteuer folgt dem Energieträger, der unter
  „Heizwärme kommt aus“ eingestellt ist — bei Gas und Fernwärme also auch die
  7 % von 10/2022 bis 3/2024.
- **Fernwärme** nur mit dem Faktor deines Wärmenetzes. Er steht beim Versorger,
  oft im Preisblatt oder auf der Rechnung.
- **Künftige Preise** sind Annahmen, bis sie feststehen.
- **Nur Deutschland.** Andere Länder haben eigene Regeln, die die App nicht
  abbildet.

Technische Einzelheiten:
[API-Referenz](../referenz/api.md#co₂-preis-und-aufteilung-v310) ·
[Einstellungen](../referenz/einstellungen.md#co₂-preis-v310) ·
[Glossar](09-glossar.md).

---

[← Heizwärme](15-waerme.md) · [Kompendium-Index](../README.md)
