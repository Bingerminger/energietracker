# Szenario: Wohnungsnutzer (Mietwohnung)

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
| Warmwasser/Heizung | **eingeschränkt** | nur falls eigene Wohnungszähler vorhanden |
| Kaltwasser | **gut**, falls Wohnungszähler | sonst nur Hausabrechnung |
| Gas/Fernwärme des Hauses | meist **nicht** direkt | nur über die jährliche Abrechnung schätzbar |

**Empfehlung:** Fokus auf **Strom** und – wenn vorhanden – **Wasser**.
Heizung nur tracken, wenn die Wohnung eigene Zähler hat.

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

$$
\text{Saldo} = \sum \text{geleistete Abschläge} - \sum \text{tatsächliche Kosten}
$$

Ein stark negativer Saldo Monate vor der Jahresabrechnung warnt früh
vor einer Nachzahlung — du kannst den Abschlag aktiv anpassen lassen,
statt überrascht zu werden. Ein hoher positiver Saldo bedeutet, dass du
dem Versorger zinslos Geld leihst → Abschlag senken.

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
  Jahr ≈ $0{,}05\,\text{kW}\times 8760\,\text{h} \approx 438$ kWh.

---

## 6. Was du NICHT erzwingen solltest

- Keine „geschätzten" Hausheizungswerte erfinden, nur damit eine
  Effizienzklasse erscheint — die Klasse ist eine Eigenheim-Kennzahl
  und für Wohnungsnutzer ohne eigene Heizmessung wenig aussagekräftig.
- Wasser nicht in kWh denken — es bleibt m³.

---

[← Holzpellets](06-pellets.md) · [Szenario Eigenheim →](08-szenario-eigenheim.md)
