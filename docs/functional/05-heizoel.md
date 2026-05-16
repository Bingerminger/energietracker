# Heizöl

[← Fernwärme](04-fernwaerme.md) · [Kompendium-Index](../README.md)

| Eigenschaft | Wert |
|---|---|
| Erfassung | **lieferbasiert** (Tankrechnungen, keine Zählerstände) |
| Eingabeeinheit | **Liter (L)** |
| Abrechnungseinheit | kWh |
| Heizwert | `heizoel_kwh_per_l` (Default **10,0 kWh/L**, Hu Heizöl EL) |
| HGT-relevant | **ja** |
| Farbe | Violett |

---

## 1. Warum Heizöl anders funktioniert

Es gibt **keinen Zähler**. Was real existiert: ein **Tank** mit einer
Kapazität und einem Anfangsbestand, und gelegentliche **Lieferungen**
mit Rechnung. Daraus muss der laufende Verbrauch *modelliert* werden.

Deshalb hat Heizöl (wie Pellets) ein eigenes Datenmodell:

- **Tank/Lager** = ein „Meter" mit `capacity`, `capacity_unit` (`L`),
  `initial_stock`.
- **Lieferung** = `{date, quantity, unit_price_cents | total_eur,
  supplier, note, is_planned}`.

---

## 2. Verträge? — Nein, die Tankrechnung *ist* der Vertrag

Heizöl wird zu Tagespreisen gekauft, nicht über einen Liefervertrag mit
festem Arbeitspreis. **Es gibt bewusst keine Vertrags-Entität für
Heizöl.** Die Kostenbasis ist die jeweilige **Tankrechnung**:

$$
\text{Kosten der Lieferung} =
\begin{cases}
\text{total\_eur} & \text{falls Gesamtbetrag erfasst} \\[4pt]
\text{quantity}\times \dfrac{\text{unit\_price\_cents}}{100} & \text{sonst}
\end{cases}
$$

**Seit v1.4.2** hat `total_eur` Vorrang: Der Rechnungs-Gesamtbetrag ist
die tatsächlich bezahlte Größe und enthält Liefergebühr, Mindermengen-
zuschlag oder Rabatte, die ein reines *Preis × Menge* nicht abbildet.
Der effektive Stückpreis wird daraus abgeleitet
($\text{ct/L} = \text{total\_eur}\times 100 / \text{Menge}$) und über
Forward-Fill den Verbrauchstagen zugeordnet.

> Praktisch: Trage einfach den **Rechnungsbetrag** und die **Liter** der
> Tankrechnung ein. Den ct/L-Preis musst du nicht ausrechnen.

---

## 3. Verbrauchsverteilung (Energiebilanz)

Über die Nutzungsdauer gilt die Bilanz: Was im Tank war plus was
geliefert wurde, wird verbraucht. In Energie:

$$
\text{Gesamt-kWh} = \big(\text{initial\_stock} + \textstyle\sum \text{Lieferungen}\big)\times H_u
$$

Diese Energie wird auf die Tage verteilt: ein wetterunabhängiger
**Grundlastanteil** (`delivery_baseload_share`, Default 0,15 — z. B.
Warmwasser) flach, der **Rest HGT-gewichtet**:

$$
\text{kWh}_{\text{Tag}} =
\underbrace{\frac{\text{Gesamt-kWh}\cdot s}{\text{Tage}}}_{\text{Grundlast},\ s=0{,}15}
+
\underbrace{(1-s)\cdot\text{Gesamt-kWh}\cdot\frac{\text{HGT}_{\text{Tag}}}{\sum \text{HGT}}}_{\text{Heizanteil}}
$$

Daraus folgen Monatsverbrauch, Kosten (über den Lieferpreis je Tag) und
die Heizsignatur-Analyse — analog zu Gas.

---

## 4. Tank-Bestandskurve (das v1.4.0-Modell)

> **Wichtig:** Der angezeigte Bestand ist eine **kalibrierte
> Modellschätzung**, *keine* Tankpeilung.

Bis v1.3.0 verteilte das Modell die *gesamte* Energie HGT-gewichtet und
erzwang damit Endbestand ≈ 0 — die Tankkurve war praktisch unbrauchbar
(immer ~0 %). Außerdem mischte die alte Berechnung Liter mit kWh
(latenter Einheitenfehler, von der 0-Normierung verdeckt).

**Seit v1.4.0** ist die Bestandskurve entkoppelt
(`dailyDeliveryStockDraw`). Der Tagesabzug ist in **Litern** und nutzt
eine **aus den geschlossenen Lieferintervallen kalibrierte
HGT-Rate**:

$$
\text{rate} =
\frac{\big(\sum \text{Lieferungen ohne die letzte}\big)\cdot(1-s)}
     {\sum \text{HGT}_{[\text{erste Lieferung},\ \text{letzte Lieferung}]}}
\quad\left[\frac{L}{\text{HGT}}\right]
$$

Begründung: Im eingeschwungenen Betrieb refüllt ein Haushalt je Zyklus
ungefähr das, was es seit der letzten Lieferung verbraucht hat — also
entspricht „alle Lieferungen außer der letzten" dem Verbrauch im
geschlossenen Zeitfenster. Diese Rate wird auf Kopf (vor erster
Lieferung) und offenen Schwanz (nach letzter Lieferung) extrapoliert:

$$
\text{stock}_{\text{Tag}} = \max\!\big(0,\;
\text{stock}_{\text{Vortag}} + \text{Lieferung}_{\text{Tag}}
- (\text{Grundlast}_L + \text{rate}\cdot \text{HGT}_{\text{Tag}})\big)
$$

Es wird **kein** Endbestand 0 mehr erzwungen — der Restbestand ergibt
sich physisch. Fallback bei < 2 Lieferungen (keine Kadenz ableitbar):
Rate aus (Anfangsbestand + Σ Lieferungen) über die Fenster-HGT;
ohne Temperaturen: flacher Abzug.

Die **Kosten-/Effizienzrechnung** nutzt weiterhin die Energiebilanz aus
§3 — dort ist „gekauft ≈ verbraucht über die Laufzeit" korrekt; nur die
*Bestandskurve* braucht die kalibrierte Rate.

---

## 5. Praxis: Tank realistisch dimensionieren

Damit die Bestandskurve einen plausiblen Sägezahn zeigt, sollten
Tankgröße, Anfangsbestand und Lieferkadenz zur Verbrauchsskala passen
(Beispiel Demo: 3000-L-Tank, Start 2400 L, jährliche Herbst-Lieferung
~1150 L → Min ~49 %, Max ~93 %). Ein 4000-L-Tank mit nur kleinen
Teil-Lieferungen würde nie hoch gefüllt erscheinen — das ist kein
Fehler, sondern bildet die Realität ab.

---

## 6. Typische Stolpersteine

- **Heizwert falsch**: 10,0 kWh/L gilt für Heizöl EL. Bei abweichender
  Qualität in den Einstellungen anpassen, sonst kippen kWh und
  Effizienzklasse.
- **Erwartung einer Tankpeilung**: Der Bestand ist modelliert, nicht
  gemessen. Eine echte Peilstab-Eingabe ist (noch) nicht vorgesehen.
- **Geplante Lieferung** (`is_planned`) zählt nicht in Bilanz/Bestand —
  bewusst, damit Vorausplanung den Ist-Stand nicht verfälscht.

---

[← Fernwärme](04-fernwaerme.md) · [Holzpellets →](06-pellets.md)
