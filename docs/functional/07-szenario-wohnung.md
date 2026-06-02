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

```text
Saldo = Σ geleistete Abschläge - Σ tatsächliche Kosten
```

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
  Jahr ≈ `0,05 kW × 8760 h ≈ 438 kWh`.

---

## 6. Was du NICHT erzwingen solltest

- Keine „geschätzten" Hausheizungswerte erfinden, nur damit eine
  Effizienzklasse erscheint — die Klasse ist eine Eigenheim-Kennzahl
  und für Wohnungsnutzer ohne eigene Heizmessung wenig aussagekräftig.
- Wasser nicht in kWh denken — es bleibt m³.

---

## 7. Sonderfall Balkonkraftwerk

Steckerfertige Mini-PV-Anlagen („Balkonkraftwerk", bis 800 W) speisen
seit dem Solarpaket I über die normale Steckdose ein. Es gibt **keinen
Einspeisezähler** (rückwärts laufender Bezugszähler ist mit modernen
Zweirichtungs-Zählern automatisch ausgeschlossen, der Bezugszähler
zeigt nur den Netto-Bezug). Folgen für die App:

- **`pv_einspeisung` aktivieren bringt nichts** — du hast keinen Zähler
  dafür. Die App rechnet ohne Daten leer.
- **`pv_erzeugung` ist optional sinnvoll**, wenn dein Wechselrichter
  einen kWh-Zähler hat. Du erfasst dann monatlich seinen Stand und
  siehst die Erzeugung als reine Statistik. Strom-Saldo bleibt
  unverändert (kein Einspeisungs-Anteil), aber du hast eine
  Performance-Kontrolle deines Balkonkraftwerks.
- Der eigentliche Effekt zeigt sich am normalen `strom`-Zähler:
  *weniger* Bezug. Die Wirtschaftlichkeit eines Balkonkraftwerks misst
  du daher am Bezugs-kWh-Vergleich Vorher/Nachher (am besten
  wetterbereinigt, aber Strom ist meist nicht stark HGT-getrieben —
  Jahres-Mittelwert reicht).

---

## Weiterführend

- **Werte automatisch erfassen** statt monatlich abtippen? Wenn du Home
  Assistant nutzt: [Home-Assistant-Anbindung](../HOME-ASSISTANT.md).
- **Mehr Praxisfälle** (u. a. WG mit geteilten Zählern):
  [Anwendungsbeispiele & Use-Cases](../USE-CASES.md).

---

[← Holzpellets](06-pellets.md) · [Szenario Eigenheim →](08-szenario-eigenheim.md)
