# Was wäre wenn? Die Prognose-Werkstatt

**Deutsch** · [English](../en/anleitungen/was-waere-wenn.md)

[← Kompendium-Index](../README.md)

Was kostet ein kalter Winter? Was bringt die Dämmung? Lohnt der Wechsel zum
günstigeren Angebot? Seit **v3.6.0** beantwortet die **Prognose-Werkstatt**
solche Fragen mit deinen eigenen Zahlen: Regler bewegen, Ergebnis sofort
sehen — als eine große Zahl, als Kurve und als Rangliste „Was bringt am
meisten?“. Gerechnet wird auf deinem Server, mit deinem Heizmodell, deinen
Verträgen und deiner Abrechnungsperiode. Nichts verlässt dein Gerät, bis du
selbst ein Bild oder eine PDF weitergibst.

> **In einer Minute:** Auswertungen → **Prognose** öffnen (auch in der Stufe
> Einsteiger), oben **„Kalter Winter“** anklicken. Die Karte sagt dir, was ein
> Winter, wie ihn etwa jedes zehnte Jahr bringt, mehr kostet. Dann den Regler
> **„Sparen“** auf 10 % ziehen und zusehen, wie die Zahl kippt.

---

## 1. Was du brauchst

- Mindestens **sechs Monate Verbrauch** eines Zählers mit Zählerständen und
  einem Vertrag — Gas, Strom, Wasser oder Fernwärme. Lieferarten (Heizöl,
  Pellets) und die Einspeisung bleiben bei der [Prognose im Detail](../referenz/ansichten.md#6-prognose).
- Für **Winter, Raumtemperatur und Dämmung** ein Heizmodell: Gas oder
  Fernwärme mit Temperaturen (Wetterabgleich). Mit
  [Klimanormal](../verstehen/09-glossar.md) sind die Heizgradtage die von 30
  Jahren am Standort.
- Für **Tarifwechsel** eingetragene Angebote (Wechsel prüfen → Angebot
  eintragen) oder einen eigenen Preis.

## 2. Die Stellschrauben

| Regler | Was er bewegt | ab Stufe |
|---|---|---|
| **Winter** (± % Heizgradtage) | nur den heizabhängigen Teil, proportional | Einsteiger |
| **Sparen** (%) | den ganzen Verbrauch — Verhalten | Einsteiger |
| **Tarif** | laufender Vertrag, ein Angebot, das günstigste Angebot, ab Erfahren ein eigener Arbeits- und Grundpreis | Einsteiger |
| **Raumtemperatur** (± °C) | die Heizgrenze um denselben Wert (§ 6) | Erfahren |
| **Grundlast** (± %) | Warmwasser, Kochen, Geräte — den Teil ohne Wetterbezug | Erfahren |
| **Maßnahmen** | je Maßnahme % auf den Heizanteil, % auf die Grundlast, Kosten | Erfahren |
| **Prognosemodell** | witterungsbereinigt (die Prognose), wie im Vorjahr, Trend | Erfahren |
| **Heizkurve** | das Regressionsmodell der Prognose (linear, robust …) | Experte |
| **E-Auto** | Strom: km im Jahr × kWh/100 km, gleichmäßig auf die Tage | Experte |
| **Wärmepumpe (JAZ)** | Strom eines Zählers mit der Rolle Wärmepumpe: Menge × JAZ heute ÷ JAZ im Szenario | Experte |
| **Budget** | sucht die beste Kombination der Maßnahmen (§ 7) | Experte |

Die Prozente einer Maßnahme trägst du selbst ein, am besten aus deiner
Energieberatung oder dem Angebot des Handwerkers. Die Werkstatt gibt bewusst
keine „typischen“ Werte vor — die hängen vom Haus ab.

## 3. Was du siehst

- **Ergebniskarte:** eine Aussage, eine große Zahl — „Ein kalter Winter kostet
  dich 214 € mehr im Jahr.“ Dazu Verbrauch, CO₂ und der empfohlene Abschlag.
- **Kennzahlen:** Kosten im Jahr (gegen den Ausgangsfall), Verbrauch im Jahr,
  empfohlener Abschlag; ab Erfahren Saldo zur nächsten Abrechnung und CO₂.
- **Kosten kumuliert:** Ausgangsfall gestrichelt, Szenario farbig — wo sich
  die Linien trennen, wirkt die Änderung.
- **Monat für Monat** (ab Erfahren): Verbrauch je Monat, beim Experten mit
  Unsicherheitsband.
- **Was bringt am meisten?** (ab Erfahren): jede Stellschraube allein gegen
  den Ausgangsfall (normaler Winter, laufender Vertrag), Ersparnis im ersten
  Jahr und — wo du Kosten eingetragen hast — die Amortisation.
- **Prognosemodelle im Vergleich** (Experte): dieselbe Frage mit drei
  Modellen; liegen sie weit auseinander, ist die Antwort unsicher.

Unter der Werkstatt steht aufklappbar die **Prognose im Detail** wie bisher:
Verlauf mit Band, Monatstabelle mit Kosten, Abschlag, Saldo und Methode.

## 4. Beispielszenarien

| Beispiel | Was es setzt | Hinweis |
|---|---|---|
| **Kalter Winter** | Heizgradtage so hoch wie etwa in jedem zehnten Winter (aus der Streuung im Klimanormal) | ohne Klimanormal 10 % als Annahme |
| **Dach gedämmt** | eine Maßnahme „Dach dämmen“ mit 15 % weniger Heizwärme | ein Beispielwert, kein Richtwert — trag deinen ein |
| **Günstigstes Angebot** | das günstigste deiner Angebote, gerechnet mit deinem erwarteten Verbrauch | Bonus nicht eingerechnet |
| **10 % sparen** | zehn Prozent weniger Verbrauch durch Verhalten | — |

Ein Beispiel erscheint nur, wenn es zum Zähler passt (ohne Heizmodell kein
Winter, ohne Angebot kein Wechsel). In der öffentlichen Demo sind Ausgangsfall
und Beispiele vorgerechnet — ein Klick zeigt sofort ein Ergebnis.

## 5. Speichern, vergleichen, teilen

- **Speichern:** Namen eingeben, „Speichern“. Szenarien liegen **im Browser
  dieses Geräts** (höchstens 20); ein anderes Gerät sieht sie nicht.
- **Vergleichen:** rechnet alle gespeicherten Szenarien neu und stellt Kosten,
  Differenz, Verbrauch und CO₂ nebeneinander.
- **Ergebnis als Bild speichern:** die Karte als PNG (1200 × 630, das Format
  von Vorschaubildern) — gezeichnet im Browser, ohne Dienst nach außen.
- **Drucken oder als PDF:** die Druckansicht mit Annahmen, Ausgangsfall gegen
  Szenario, Kurven, Monatstabelle und Rangliste, sauber auf A4. Über den
  Druckdialog des Browsers wird daraus eine PDF.
- **Monatswerte als CSV** (Experte): Menge, Kosten und kumulierte Kosten je
  Monat für Ausgangsfall und Szenario.

## 6. Wie gerechnet wird

**Eine Quelle.** Der Ausgangsfall ist die Prognose der nächsten zwölf Monate
mit allen ihren Regeln: Klimanormal, Heizmodell, Analyse-Zäsur, Verträge
tagesgenau, Abschläge nach Plan. Die Werkstatt ändert nur Mengen — und beim
Tarif Preise. Die Kosten eines Monats sind in der Prognose eine lineare
Funktion der Menge (Arbeitspreis × Menge + Grundpreis − Boni); genau diese
Funktion bewertet auch die Szenario-Mengen. Ohne Änderung kommt deshalb auf
den Cent die Prognose heraus.

**Heizanteil.** Das Heizmodell beschreibt den Verbrauch als a × Heizgradtage
+ c × Tage. Der heizabhängige Teil eines Monats ist a × HGT; nur er reagiert
auf Winter, Raumtemperatur und Dämmung. Sommermonate ohne Heizgradtage
ändern sich dadurch nicht.

**Raumtemperatur.** Ein Grad weniger im Raum senkt die Heizgrenze um ein Grad.
Die Werkstatt nimmt die Heizgradtage bei der verschobenen Grenze aus dem
Klimanormal (sonst aus deiner Temperaturhistorie) und multipliziert die
Differenz mit a — dein eigenes Modell, keine Faustregel; dieselbe Überlegung
wie beim Temperaturversatz der Prognose seit v2.8.0.

**Maßnahmen wirken nacheinander.** Zweimal 30 % weniger Heizwärme ergibt
0,7 × 0,7 = 49 % weniger, nicht 60 %. Die Rangliste rechnet jede Maßnahme
allein, die Kombination rechnet sie zusammen.

**Tarif.** Ein Angebot wird mit derselben Preisregel wie in der
[Wechselentscheidung](../referenz/ansichten.md#wechselentscheidung) gerechnet:
Arbeitspreis × Menge + Grundpreis je Monat, Bonus nicht eingerechnet. Beim
Wasser (drei Preisbestandteile) bleibt der laufende Vertrag.

**Modelle.** *Witterungsbereinigt* ist die Prognose. *Wie im Vorjahr* nimmt
die gemessenen Monate ein Jahr früher (Tagesrate × Tage); fehlt ein Monat,
füllt ihn die Prognose, und die Werkstatt sagt, wie viele. *Trend* schreibt
das Vorjahr mit der Veränderung zum Jahr davor fort, höchstens ± 25 % — und
nur mit zwei vollen Jahren Messung. Das Unsicherheitsband gehört zum
Prognosemodell.

**Abschlag.** Mit laufender Abrechnungsperiode (F1030) wie der Vorschlag im
Vertragsstatus: heutiger Abschlag + erwarteter Endsaldo ÷ verbleibende
Monate. Sonst ein Zwölftel der Jahreskosten, aufgerundet.

**Saldo zur nächsten Abrechnung.** Der bisher gemessene Saldo der laufenden
Abrechnungsperiode plus die Prognosemonate bis zu ihrem Ende (Kosten −
Abschlag), der letzte Monat anteilig.

**CO₂.** Verbrauch × der Faktor, mit dem die Verbrauchsansicht der letzten
zwölf Monate gerechnet hat.

## 7. Die beste Kombination unter einem Budget

Trag ein **Budget** ein und bei den Maßnahmen die **Kosten**. Die Werkstatt
rechnet dann **jede Kombination**, die ins Budget passt, mit dem echten Modell
— Wechselwirkungen eingeschlossen — und nennt die mit der größten Ersparnis
im Jahr (bei Gleichstand die billigere). Bei höchstens zehn Maßnahmen sind
das höchstens 1 024 Kombinationen; das Ergebnis ist exakt.

Daneben löst ein kleiner **Simplex-Löser** die lineare Relaxierung: Er darf
Maßnahmen auch anteilig nehmen und rechnet mit den Einzelersparnissen. Das
ergibt eine **obere Schranke** und den **Schattenpreis des Budgets** — was ein
weiterer Euro Budget dort im Jahr bringen würde. „Auswahl übernehmen“ setzt
die gefundenen Maßnahmen ins Szenario.

## 8. Grenzen

- Ein Szenario ist eine **Modellrechnung, keine Messung**. Die Werkstatt
  sagt dazu, bis wann gemessen wurde und wie groß der Heizanteil ist.
- Die Werkstatt rechnet für Gas, Strom, Wasser und Fernwärme mit
  Zählerständen und Vertrag; Lieferarten und die Einspeisung nicht.
- Dynamische Tarife (Börsenpreis je Monat) bietet sie nicht als Angebot an —
  die rechnet [Wechsel prüfen](../referenz/ansichten.md#wechselentscheidung).
- Lastverschiebung gegen Viertelstundenpreise rechnet sie nicht; was das
  Verschieben gebracht hat, zeigt [Flexibilität bewerten](flexibilitaet.md).
- Maßnahmen-Prozente und Kosten sind deine Eingaben.

## 9. Für Entwickler

Die Werkstatt ruft `POST /api/forecast/scenario` auf — eine Route, die nur
rechnet und nichts schreibt (auch mit einem Lese-Schlüssel erlaubt). Felder,
Grenzen und Antwort: [API-Referenz](../referenz/api.md).
