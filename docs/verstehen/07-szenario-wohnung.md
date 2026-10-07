# Szenario: Wohnungsnutzer (Mietwohnung)

**Deutsch** · [English](../en/verstehen/07-szenario-wohnung.md)

[← Holzpellets](06-pellets.md) · [Kompendium-Index](../README.md)

Typische Ausgangslage: Mietwohnung, **Strom** über eigenen Vertrag,
**Heizung/Warmwasser** zentral über die Nebenkostenabrechnung
(Gas/Fernwärme des Hauses, oft nur jährlich und nur anteilig sichtbar),
**Wasser** teils kalt/warm getrennt. Kein eigener Tank.

---

## 1. Was sich realistisch tracken lässt

| Größe | Tracking | Hinweis |
|---|---|---|
| Haushaltsstrom | **gut** — eigener Zähler, eigene Rechnung | Kernnutzen |
| Heizung (Heizwärme) | **gut** mit der monatlichen Verbrauchsinfo *(seit v3.1.0)* | Verbrauch je Zeitraum; vorher nur mit eigenem Wohnungszähler |
| Warmwasser | **gut**, falls Wohnungszähler oder Verbrauchsinfo | Rolle „Warmwasser“, Wärme dafür als Rechenwert |
| Kaltwasser | **gut**, falls Wohnungszähler | sonst nur Hausabrechnung |
| Gas/Fernwärme des Hauses | meist **nicht** direkt | zahlt der Vermieter; du siehst die Heizwärme deiner Wohnung |

**Empfehlung:** Fokus auf **Strom**, **Heizwärme** aus der Verbrauchsinfo und
– wenn vorhanden – **Wasser**. Mit „Ich wohne: zur Miete“ kommt die Seite
**Mietverhältnis** dazu (Abschnitt 3a).

---

## 2. Empfohlene Einrichtung

1. **Aktive Verbrauchsarten** in den Einstellungen auf das reduzieren,
   was du wirklich misst (z. B. nur `strom`, `wasser`). Inaktive Arten
   verschwinden aus Sidebar/Dashboard — das hält die Oberfläche klar.
2. **Stromzähler** anlegen, Anfangs-Ablesung mit Datum erfassen.
3. **Stromvertrag** mit Arbeitspreis, Grundpreis, Abschlag eintragen —
   damit der **Saldo** zeigt, ob deine Abschläge zu hoch/niedrig sind.
4. **Monatlich ablesen** (Foto vom Zähler genügt als Erinnerung). Je
   regelmäßiger, desto besser die Auswertung.

---

## 3. Wofür der Saldo gut ist

Der laufende Saldo ist für Mieter besonders wertvoll:

```text
Saldo = Σ tatsächliche Kosten - Σ geleistete Abschläge
```

Ein positiver Saldo Monate vor der Jahresabrechnung warnt früh vor einer
Nachzahlung — du kannst den Abschlag aktiv anpassen lassen, statt
überrascht zu werden; die Saldo-Karte schlägt dafür einen Betrag vor.
Ein stark negativer Saldo bedeutet, dass du dem Versorger zinslos Geld
leihst → Abschlag senken.

Seit v2.8.0 rechnet der Saldo nach Kalender bis heute, auch wenn die
letzte Ablesung Monate zurückliegt: Die Abschläge zählen, wie sie
abgebucht wurden, der Verbrauch seit der letzten Ablesung wird
geschätzt und als Schätzung ausgewiesen
([Grundlagen §10](00-overview.md#10-saldo-bis-heute)).

---

## 3a. Heizung und Nebenkosten *(seit v3.1.0)*

Heizung und Warmwasser zahlst du nicht an einen Versorger, sondern über die
Nebenkosten an den Vermieter. Dafür gibt es keinen Saldo im Sinne eines
Versorgervertrags, aber ein Gegenstück — die Seite **Mietverhältnis** unter
Kosten & Verträge:

1. **Einstellungen → Haushalt & Gebäude → „Ich wohne“: „zur Miete“.**
2. Verbrauchsart **Heizwärme** einschalten und einen Zähler mit der Erfassung
   **„Verbrauch je Zeitraum“** anlegen. Jeden Monat den Wert aus der
   Verbrauchsinfo eintragen (HeizkostenV § 6a, seit 2022 bei fernablesbaren
   Geräten).
3. Wasserzähler mit den Rollen **Kaltwasser** und **Warmwasser** anlegen —
   nebeneinander, nicht als Subzähler.
4. **Mietverhältnis** anlegen: Vorauszahlung, Abrechnungsstichtag, Preise aus
   der letzten Abrechnung, pauschale Umlagen, Zuordnung der Zähler.

Die Karte „Vorauszahlung und Kosten“ rechnet dann wie der Saldo: erwartete
Kosten des laufenden Abrechnungszeitraums gegen die Vorauszahlung, mit einer
passenden Vorauszahlung je Monat.

```text
Ergebnis = Σ (Wärme-kWh × Preis + Wasser-m³ × Preis + Umlagen / 12) − Σ Vorauszahlung
```

Eine **Hilfsrechnung, keine Nebenkostenabrechnung** — was der Vermieter
abrechnet, kann abweichen. Die Fristen für Abrechnung und Einwände stehen im
Kalender. Schritt für Schritt: [Als Mieter](../anleitungen/mieter.md);
Hintergrund: [Heizwärme](15-waerme.md).

---

## 4. Schattenverträge: Tarifwechsel durchrechnen

Lege einen **Schattenvertrag** (`is_shadow`) mit den Konditionen eines
Wunschtarifs an. Der Tarifvergleich rechnet ihn auf deinen **echten**
historischen Verbrauch — ohne Saldo oder Prognose zu verändern. So
siehst du belastbar, ob ein Wechsel sich gelohnt hätte, bevor du
wechselst.

---

## 5. Stromverbrauch verstehen (ohne HGT)

Strom ist nicht heizgetrieben (siehe [Strom](02-strom.md)). Nützliche
Lesarten:

- **Saisonprofil**: Winterhöhe oft durch Beleuchtung/Standby; ein
  Sommerpeak deutet auf Klimagerät/Ventilator.
- **Grundlast-Anstieg**: Steigt der Sockel über Monate, lohnt die Suche
  nach Dauerverbrauchern (alter Kühlschrank, Server, Aquarium). Die
  Trend-/Anomalieregel macht darauf aufmerksam.
- **Spar-Check**: Eine Reduktion der Grundlast um 50 W spart über ein
  Jahr ≈ `0,05 kW × 8760 h ≈ 438 kWh`.

---

## 6. Was du NICHT erzwingen solltest

- Keine „geschätzten" Hausheizungswerte erfinden, nur damit eine
  Effizienzklasse erscheint — die Klasse ist eine Gebäudekennzahl
  und für Wohnungsnutzer ohne eigene Heizmessung wenig aussagekräftig.
  Mit Heizwärme aus der Verbrauchsinfo (seit v3.1.0) erscheint eine
  Kennzahl für die Wohnung — ein Anhaltspunkt, denn Lage im Haus und
  Nachbarwohnungen wirken mit.
- Wasser nicht in kWh denken — es bleibt m³.

---

## 7. Sonderfall Balkonkraftwerk

Steckersolargeräte („Balkonkraftwerke“) speisen über eine Steckdose ins
Hausnetz ein. Seit dem Solarpaket I (2024) gilt:

- **Größe:** bis **2.000 Wp** Modulleistung und **800 VA**
  Wechselrichterleistung.
- **Anmeldung:** nur im **Marktstammdatenregister**, binnen eines Monats nach
  Inbetriebnahme; beim Netzbetreiber nicht mehr.
- **Überschuss:** Was ins Netz geht, nimmt der Netzbetreiber **unentgeltlich**
  ab — eine Vergütung gibt es nicht.
- **Zähler:** Ein alter Ferraris-Zähler ohne Rücklaufsperre darf übergangsweise
  rückwärts laufen, bis der Messstellenbetreiber ihn gegen einen modernen
  Zähler tauscht. Ein moderner Zähler zählt Bezug und Einspeisung getrennt
  oder nur den Bezug — rückwärts läuft er nie.

Bis v3.0 stand hier, ein rückwärts laufender Zähler sei mit modernen Zählern
„automatisch ausgeschlossen“ — für alte Ferraris-Zähler stimmte das nicht.

**Einrichten (seit v3.1.0):**

1. **Verbrauchsart PV-Erzeugung** einschalten (Einstellungen →
   Verbrauchsarten & Abrechnung → Aktive Verbrauchsarten) und den Zähler des
   Wechselrichters anlegen, wenn er einen kWh-Stand zeigt (am Gerät oder in
   der App des Herstellers; eine Datei daraus liest
   [Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md)). Im
   Zählerdialog **„Balkonkraftwerk (Steckersolargerät, ohne
   Einspeisezähler)“** ankreuzen; unter „PV-Anlage“ auf Wunsch Investition und
   Inbetriebnahme für die Amortisation.
2. Unter Einstellungen → Verbrauchsarten & Abrechnung → **Photovoltaik** den
   **angenommenen Eigenverbrauch** eintragen, zum Beispiel 70 %. Ohne
   Einspeisezähler rechnet die App den Eigenverbrauch damit und zeigt
   Ersparnis, Autarkie und Amortisation — als Annahme gekennzeichnet
   ([PV §8](12-pv.md#8-balkonkraftwerk-v310)).
3. **PV-Einspeisung** brauchst du nicht: Es gibt keinen Vertrag und meist
   keinen Zähler dafür. Kannst du das Einspeise-Zählwerk eines modernen
   Zählers ablesen, leg es dort an — dann rechnet die App mit der gemessenen
   Einspeisung statt mit der Annahme.

**Wirkung messen — die Zäsur.** Der eigentliche Effekt zeigt sich am
Stromzähler: weniger Bezug. Trag am Stromzähler unter *Analyse-Zäsuren* das
Datum der Inbetriebnahme mit der Bezeichnung „Balkonkraftwerk in Betrieb“ ein.
Seit v3.1.0 vergleicht die Analyse auch bei Strom vorher und nachher: das
Tagesmittel je Kalendermonat in beiden Phasen, über die Monate, die es in
beiden gibt (mindestens drei), hochgerechnet auf ein Jahr — in der Karte
„Wirkung der Maßnahme“ mit der Aussage, ob der Unterschied statistisch belegt
ist. Das Wetter ist dabei nicht bereinigt; ein sonniger Sommer zählt mit.
Belastbar wird der Vergleich nach einem Jahr mit dem Gerät.

---

## Weiterführend

- **Werte automatisch erfassen** statt monatlich abtippen? Wenn du Home
  Assistant nutzt: [Home-Assistant-Anbindung](../anleitungen/home-assistant.md).
- **Mehr Praxisfälle** (u. a. WG mit geteilten Zählern):
  [Anwendungsbeispiele & Use-Cases](../anleitungen/anwendungsfaelle.md).

---

[← Holzpellets](06-pellets.md) · [Szenario Eigenheim →](08-szenario-eigenheim.md)
