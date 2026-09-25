# Heizöl

**Deutsch** · [English](../en/functional/05-heizoel.md)

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

```text
Kosten der Lieferung =
    total_eur                          falls Gesamtbetrag erfasst
    quantity × unit_price_cents / 100  sonst
```

**Seit v1.4.2** hat `total_eur` Vorrang: Der Rechnungs-Gesamtbetrag ist
die tatsächlich bezahlte Größe und enthält Liefergebühr, Mindermengen-
zuschlag oder Rabatte, die ein reines *Preis × Menge* nicht abbildet.
Der effektive Stückpreis wird daraus abgeleitet
(`ct/L = total_eur × 100 / Menge`).

**Seit v2.10.0 kostet der verbrauchte Liter, was er im Tank kostet:** Eine
Lieferung mischt sich mit ihrem Preis unter den Bestand (gleitender
Durchschnitt), verbraucht wird zum Durchschnittspreis. Der
**Anfangsbestand** kostet den Preis, der am Tank gepflegt ist
(`initial_stock_price_ct`), sonst den der ersten Lieferung. Bis v2.9 war
er kostenlos, und jeder Tag trug den Preis der letzten Lieferung — nach
einer teuren Herbstlieferung wurde auch das billige Öl vom Frühjahr teuer
verbucht. Die Monatstabelle zeigt den effektiven Preis je kWh.

> Praktisch: Trage einfach den **Rechnungsbetrag** und die **Liter** der
> Tankrechnung ein. Den ct/L-Preis musst du nicht ausrechnen.

---

## 3. Tankbuch — eine Rechnung für Verbrauch und Bestand (seit v2.10.0)

Bis v2.9 rechnete die App zweimal: Die Kosten verteilten Anfangsbestand
plus **alle** Lieferungen auf die Zeit bis heute, als wäre der Tank heute
leer; die Bestandskurve rechnete mit einer kalibrierten Rate. Folge: Eine
Lieferung von heute erhöhte den Verbrauch aller Vorjahre um rund 20 %, und
die Bilanz sagte „Tank leer", während die Kurve 1.466 L zeigte. Jetzt gibt
es **eine** Rechnung, aus der Verbrauch, Kosten und Bestandskurve kommen.

**Stützstellen** sind Tage, an denen der Bestand bekannt ist:

- der Starttag mit dem **Anfangsbestand**,
- eine Lieferung **„bis voll getankt"** (`fill_to_full`) — danach ist der
  Bestand die Tankkapazität,
- ein **Peilstand** (`tank_levels` am Tank) — abgelesen am Tankanzeiger,
  mit dem Peilstab oder vom Füllstandssensor.

Zwischen zwei Stützstellen ist der Verbrauch **bekannt**:

```text
Verbrauch = Bestand_vorher + Σ Lieferungen dazwischen − Bestand_nachher
```

Er wird nach Grundlast und Gradtagen auf die Tage verteilt:

```text
Anteil_Tag ∝ ρ + HGT_Tag        ρ = s · HGT_Jahr / ((1 − s) · 365,25)
```

ρ ist die Grundlast in Gradtag-Einheiten (`delivery_baseload_share` s,
Default 0,15 — Warmwasser, Stand-by). Dadurch bekommt ein Sommerintervall
vor allem Grundlast, statt dass 85 % auf die wenigen kühlen Tage fallen.
Die Gradtage eines Normaljahrs kommen aus dem Klimanormal, sonst aus der
eigenen Temperaturhistorie (wenn jeder der zwölf Monate mindestens 15 Tage
hat), sonst aus
einem groben mitteleuropäischen Monatsmittel. Fehlende Tagestemperaturen
füllt das Klimanormal.

**Nach der letzten Stützstelle** rechnet die App mit einer kalibrierten
Rate weiter und kennzeichnet diese Tage als **geschätzt**
(`estimated_from`; Monatszeilen `estimated_days`, in der Tabelle „≈"). Die
Rate kommt aus den Intervallen zwischen Stützstellen, sonst aus der
Lieferkadenz (was vor der letzten Lieferung geliefert wurde, war zwischen
erster und letzter Lieferung verbraucht), bei genau einer Lieferung aus der
Annahme, dass der Anfangsbestand bis zu ihr verbraucht war. Jede Quelle
braucht mindestens 14 Tage. Reicht keine (etwa nur der Anfangsbestand, oder
die einzige Lieferung am ersten Tag), gibt es **keine** Verbrauchsrechnung
und einen Hinweis — bis v2.9 galt dann der ganze Anfangsbestand als bis
heute verbraucht.

Widersprechen sich zwei Stände (mehr im Tank, als dort sein kann), bucht
die App dazwischen nichts und meldet es; wird der Tank rechnerisch leer,
fragt sie nach einer fehlenden Lieferung oder einem Peilstand.

---

## 4. Tank-Bestandskurve

Die Kurve ist dieselbe Rechnung wie der Verbrauch (§3): Bestand am Ende
jedes Tages. Die bekannten Stände stehen in `anchors` (Beginn des Tages,
nach einer Lieferung) und erscheinen als Punkte. Die Tank-Ansicht zeichnet
den gerechneten Teil durchgezogen, den geschätzten gestrichelt und die
bekannten Bestände als Punkte, und sie sagt dazu, bis wann aus bekannten
Beständen gerechnet ist.

> **Genauer wird es mit Stützstellen:** Eine Lieferung als „bis voll
> getankt" markieren oder ab und zu einen Peilstand erfassen (Tank-Ansicht,
> „Peilstand erfassen"). Ab dann ist der Verbrauch bis zu diesem Tag
> gerechnet, nicht geschätzt — und eine spätere Lieferung ändert ihn nicht
> mehr.

*(Bis v2.9 gab es hier zwei Modelle: die kalibrierte Bestandskurve seit
v1.4.0 und daneben die Energiebilanz für Kosten und Effizienz. Die
Kadenz-Regel der alten Kurve lebt als Kalibrierung ohne Stützstellen
weiter.)*

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
- **Kein Peilstand, keine „bis voll"-Lieferung**: Dann ist alles
  geschätzt, und eine neue Lieferung verschiebt die Rate. Ein Peilstand im
  Jahr genügt, damit die Vorjahre feststehen (seit v2.10.0).
- **Geplante Lieferung** (`is_planned`) zählt nicht in Bilanz/Bestand —
  bewusst, damit Vorausplanung den Ist-Stand nicht verfälscht.

---

[← Fernwärme](04-fernwaerme.md) · [Holzpellets →](06-pellets.md)
