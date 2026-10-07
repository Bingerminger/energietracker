# Heizwärme, Verbrauch je Zeitraum und Zählerrollen

**Deutsch** · [English](../en/verstehen/15-waerme.md)

[← Länderprofile](14-laenderprofile.md) · [Kompendium-Index](../README.md)

Seit **v3.1.0** kennt der Energietracker eine neunte Verbrauchsart,
**Heizwärme**, eine dritte Erfassungsart, **Verbrauch je Zeitraum**, und
**Rollen** für Zähler. Alle drei kamen mit dem Mieter-Paket, sind aber nicht
auf Mieter beschränkt. Wie du sie als Mieter Schritt für Schritt einrichtest,
steht in [Als Mieter](../anleitungen/mieter.md).

| Eigenschaft | Wert |
|---|---|
| Schlüssel | `waerme` |
| Erfassung | **kumulativ** (Wärmezähler in kWh) oder **Verbrauch je Zeitraum** |
| Einheit | kWh (Zählerstand und Verbrauch) |
| Umrechnung | keine |
| HGT-relevant | **ja** — Heizmodell, Wetterbereinigung und Prognose wie bei Gas |
| Verträge | **keine** — bezahlt wird über das Mietverhältnis |
| Standardmäßig aktiv | **nein** |
| Farbe | Orange |

---

## 1. Was Heizwärme ist

Heizwärme ist die Wärme, die **in der Wohnung ankommt** — gezählt vom
Wärmezähler der Wohnung oder mitgeteilt in der monatlichen Verbrauchsinfo des
Messdienstes. Sie ist nicht der Brennstoff, aus dem die Wärme entsteht.

| | Heizwärme | Gas, Heizöl, Pellets | Fernwärme |
|---|---|---|---|
| Was gezählt wird | Wärme in der Wohnung | Brennstoff vor dem Kessel | Wärme am Hausanschluss |
| Wer bezahlt wird | der Vermieter (Nebenkosten) | der Versorger bzw. Lieferant | der Versorger |
| Verträge, Abschläge, Saldo | keine | ja | ja |

Typische Fälle:

- **Mieter in einem Haus mit Zentralheizung.** Die Heizung gehört dem
  Vermieter; du siehst nur, was in deiner Wohnung verbraucht wird. Das ist
  Heizwärme. Die Kosten laufen über die Nebenkosten
  ([Mietverhältnis](../anleitungen/mieter.md)).
- **Wärmepumpe mit Wärmemengenzähler.** Wer sehen will, wie viel Wärme die
  Wärmepumpe liefert, legt dafür einen Heizwärme-Zähler mit der Rolle
  „Wärmemenge der Wärmepumpe“ an ([Rollen](#3-zählerrollen)); mit ihrem
  Stromzähler verknüpft, ergibt er die Jahresarbeitszahl
  ([§7](#7-jahresarbeitszahl-der-wärmepumpe-v310)).

**Einschalten:** Einstellungen → Verbrauchsarten & Abrechnung → Aktive
Verbrauchsarten → **Heizwärme**. Die Datentöpfe (`data/waerme/`) legt die
Schema-Stufe 1.7.0 leer an, auch in bestehenden Installationen. Einen
Standardzähler gibt es nicht: Lege ihn unter Verbrauch → Heizwärme → Zähler
an.

Die App rechnet Heizwärme in kWh und rechnet keine anderen Einheiten um.

---

## 2. Verbrauch je Zeitraum

Bisher kannte die App zwei Erfassungsarten: **Zählerstände** (Gas, Strom,
Wasser, Fernwärme, PV) und **Lieferungen** (Heizöl, Pellets). Seit v3.1.0 gibt
es eine dritte: **Verbrauch je Zeitraum**. Statt eines Zählerstands trägst du
den Verbrauch selbst ein, zusammen mit dem Zeitraum, für den er gilt.

Gedacht für Werte, die schon als Verbrauch vorliegen:

- die **monatliche Verbrauchsinfo** des Messdienstes (HeizkostenV § 6a: seit
  2022 bei fernablesbaren Geräten jeden Monat, mit Vormonat, Vorjahresmonat und
  Durchschnittsnutzer),
- Monats- oder Intervallwerte aus einem Kundenportal,
- ein Wärmezähler, von dem nur Monatswerte bekannt sind.

### Einstellen

Im Zählerdialog (Verbrauch → *Verbrauchsart* → Zähler → Bearbeiten) steht die
Auswahl **„Erfassung“**: „Zählerstände“ oder „Verbrauch je Zeitraum“. Das
geht bei jeder Verbrauchsart mit Zählerständen, nicht bei Heizöl und Pellets.
Wechseln lässt sich nur, solange der Zähler **keine Daten der bisherigen Art**
hat — wer von Ständen auf Zeiträume umstellen will, legt sonst besser einen
zweiten Zähler an.

Ein Zähler mit Verbrauch je Zeitraum nimmt **keine Zählerstände** an, weder
in der Oberfläche noch über die API oder Home Assistant. Einen Zählertausch
gibt es für ihn nicht.

### Eintragen

- **Zählerstände (Erfassung):** Für solche Zähler zeigt die Erfassung eine
  eigene Karte mit **Monat** und **Verbrauch**. Vorbelegt ist der Monat nach
  dem letzten Zeitraum. Aufklappbar darunter: **„Vergleichswerte laut
  Verbrauchsinfo“** — Vormonat, Vorjahresmonat, Durchschnittsnutzer. Die
  Vergleichswerte werden nur gespeichert und angezeigt, gerechnet wird mit
  ihnen nicht. Ohne Netz wandert der Eintrag in die Warteschlange wie ein
  Zählerstand ([Zählerstand-Erfassung](11-zaehlerstaende.md)).
- **Verbrauchsansicht:** Statt der Ablesungen steht dort die Tabelle
  **„Zeiträume {Jahr}“** mit „+ Zeitraum“, Bearbeiten und Löschen. Hier lassen
  sich beliebige Zeiträume eintragen (von, bis einschließlich), nicht nur ganze
  Monate.
- **CSV:** Verbrauch → *Verbrauchsart* → Zähler → **CSV-Import** liest
  Zeiträume ein — je Zeile `Monat;Verbrauch[;Notiz]` (Monat als `01.2026`,
  `01/2026` oder `2026-01`) oder `von;bis;Verbrauch[;Notiz]`. Die Kopfzeile ist
  freiwillig, eine Vorschau zeigt vorher, was eingelesen würde. Überlappende
  Zeilen werden übersprungen und gemeldet, nicht überschrieben. Der eigene
  Export (`periods.csv`) lässt sich wieder einlesen.

### So rechnet die App

Jeder Zeitraum wird **tagesgenau** auf die Monate verteilt:

```text
Tagesrate    = Verbrauch / Tage des Zeitraums     (von und bis einschließlich)
Monatsanteil = Tagesrate × Tage des Zeitraums in diesem Monat
```

> **Beispiel.** Ein Zeitraum vom 15. Januar bis 14. Februar mit 900 kWh hat
> 31 Tage, also rund 29 kWh am Tag. Der Januar bekommt 17 Tage (rund 494 kWh),
> der Februar 14 Tage (rund 406 kWh). Ein Monatswert aus der Verbrauchsinfo —
> 1. bis 31. Januar — landet ganz im Januar.

Danach läuft **alles wie bei Zählerständen**: Monatsdiagramm, Heizmodell,
Wetterbereinigung, Prognose, Verträge und Saldo (bei Arten mit Verträgen),
CSV-Monatsübersicht, Jahresbericht.

- **Lücken bleiben Lücken.** Fehlt ein Monat, hat er keine Abdeckung. Ein
  teilweise abgedeckter Monat ist ein Teilmonat und geht — wie bei Ständen —
  nur ins Heizmodell, wenn er genug Tage hat (`min_days_period`).
- **Keine Überschneidung.** Zwei Zeiträume desselben Zählers dürfen sich nicht
  überschneiden; der zweite wird abgewiesen.
- **Gas:** Ein Zeitraum kann in kWh (Verbrauch) oder in m³ (Zählereinheit)
  angegeben sein. m³ rechnet die App mit den datierten Gasfaktoren in kWh um,
  kWh rechnet sie für Rechnungsprüfung und CSV in m³ zurück. Bei allen anderen
  Arten gibt es nur eine Einheit.
- **Ablesung fällig:** Die Erinnerung „Zählerstand ablesen“ (Zu tun, Kalender)
  richtet sich nach dem **Ende des letzten Zeitraums**, mit derselben Frist
  wie bei Ständen (Einstellungen → Allgemein → „Warnung nach“, Standard 45
  Tage).

---

## 3. Zählerrollen

Ein Zähler kann innerhalb seiner Verbrauchsart verschiedene Dinge messen:
Haushaltsstrom oder Wärmepumpe, kaltes oder warmes Wasser. Dafür gibt es seit
v3.1.0 im Zählerdialog die Auswahl **„Rolle“** (API-Feld `role`). Sie ersetzt
beim Strom die frühere Checkbox „Heizstrom (Wärmepumpe)“.

| Verbrauchsart | Rollen (erste = Standard) | Wirkung |
|---|---|---|
| Strom | Haushalt · **Wärmepumpe (Heizstrom)** · **Wallbox** | Wärmepumpe zählt in der Effizienzkennzahl als Heizenergie; Wallbox bekommt den [Ladestrom-Nachweis](../anleitungen/ladestrom-nachweis.md); beide stehen nicht im Haushaltsstrom der Einordnung |
| Wasser | Kaltwasser · **Warmwasser** · Garten | Warmwasser: Wärme dafür als Rechenwert ([Wasser](03-wasser.md#wärme-für-das-warmwasser-v310)) |
| PV-Erzeugung | Erzeugung · **Speicher – Ladung** · **Speicher – Entladung** | Speicher zählen nicht zur Erzeugung und nicht in Summen, sondern nur in der Karte „Speicher“ ([PV §7](12-pv.md#7-speicher-v310)) |
| Heizwärme | **Verbrauch der Wohnung** · **Wärmemenge der Wärmepumpe** | Verbrauch der Wohnung zählt als Heizenergie; die Wärmemenge der Wärmepumpe zählt weder in Summen noch in der Effizienzkennzahl, sondern ergibt mit dem Strom die Jahresarbeitszahl ([§7](#7-jahresarbeitszahl-der-wärmepumpe-v310)) |

- Ohne Angabe gilt die **erste Rolle** der Art; sie wird nicht eigens
  gespeichert. Bestehende Zähler behalten so ihr Verhalten.
- Beim Strom ist die Rolle „Wärmepumpe“ dasselbe wie das ältere Feld
  `heat_source`; die App hält beide gleich, damit auch ältere Versionen und
  Skripte die Wärmepumpe erkennen.
- **Summen:** Die Speicherrollen und die Wärmemenge der Wärmepumpe messen
  Energie, die schon anderswo steht; sie zählen deshalb nicht in die Summen
  ihrer Art. Alle anderen Rollen zählen wie jeder Zähler — eine Wallbox mit
  eigenem Zähler steht im Strom. Hängt ein solcher Zähler hinter einem anderen,
  gehört er als Subzähler darunter ([Meter-Topologie](13-meter-topologie.md)).

Der Zähler zeigt seine Rolle als Etikett in der Zählerliste, sofern es nicht
die Standardrolle ist.

---

## 4. Effizienzkennzahl

Heizwärme zählt in der Effizienzkennzahl (kWh je m² und Jahr) als eigene
Heizquelle — aber nur Zähler mit der Rolle **„Verbrauch der Wohnung“**.

Die **Wärmemenge der Wärmepumpe** zählt nicht: Die Wärmepumpe steht schon mit
ihrem Strom (Rolle „Wärmepumpe“ am Stromzähler) in der Kennzahl. Zählte ihre
Wärme dazu, stünde dieselbe Heizung doppelt da.

Die Fläche kommt aus Einstellungen → Haushalt & Gebäude → Wohnfläche. Die
Effizienzklassen sind für Gebäude gedacht; für eine einzelne Wohnung in einem
größeren Haus ist die Kennzahl ein Anhaltspunkt — Lage im Haus und Nachbarn
spielen mit.

---

## 5. CO₂ — eine Näherung

Heizwärme hat keinen eigenen CO₂-Faktor. Der Wert kommt vom **Energieträger
der Heizung**: Einstellung `waerme_energietraeger` (Einstellungen → Haushalt &
Gebäude → „Wohnen und Warmwasser“ → „Heizwärme kommt aus“) — Gas, Heizöl,
Holzpellets, Fernwärme oder Strom. Die App nimmt dessen Faktor, beim Strom den
des jeweiligen Jahres.

```text
CO2 [kg] = Heizwärme [kWh] × Faktor des Energieträgers [g/kWh] / 1000
ohne Angabe: 0
```

Das ist eine **Näherung**, denn gezählt wird die Wärme, nicht der Brennstoff:

- Bei **Gas, Heizöl und Pellets** fehlen die Verluste der Anlage — der Wert
  liegt eher zu niedrig.
- Bei **Fernwärme** passt die Bezugsgröße am besten: Auch ihr Faktor gilt je
  gelieferter kWh Wärme.
- Bei **Strom aus einer Wärmepumpe** liegt der Wert deutlich zu hoch, denn aus
  1 kWh Strom werden je nach Anlage etwa 3 kWh Wärme. Wer die Wärmepumpe mit
  ihrem Stromzähler erfasst, hat dort den richtigen Wert.

Dieselbe Näherung nutzt die Karte „CO₂-Preis im Brennstoff“ (v3.1.0), wenn die
Heizwärme aus Gas oder Heizöl kommt — dort mit den Standardfaktoren des BEHG
([CO₂-Preis im Brennstoff](16-co2-preis.md)).

---

## 6. Warmwasser

Die Wärme fürs Warmwasser steckt bei vielen Zentralheizungen in der
Heizwärme der Wohnung nicht drin; sie wird über den **Warmwasserzähler**
abgerechnet. Hat ein Wasserzähler die Rolle **Warmwasser**, rechnet die App
die Wärme dafür nach HeizkostenV § 9 Abs. 2 aus:

```text
Q [kWh] = 2,5 × V [m³] × (t_w − 10)      t_w = warmwasser_temp_c, Standard 60 °C
1 m³ bei 60 °C = 125 kWh
```

Ein Rechenwert, kein Messwert — Einzelheiten unter
[Wasser](03-wasser.md#wärme-für-das-warmwasser-v310). Die Einstellung
`warmwasser_energietraeger` („Warmwasser wird erwärmt mit“) hält fest, womit
das Warmwasser erwärmt wird, auch „Heizwärme (zentral)“. Sie dient nur der
Information und ändert keine Rechnung.

---

## 7. Jahresarbeitszahl der Wärmepumpe *(v3.1.0)*

Wie viel Wärme macht die Wärmepumpe aus einer kWh Strom? Gemessen über ein
Jahr ist das die **Jahresarbeitszahl (JAZ)**:

```text
Arbeitszahl (Monat) = Wärme / Strom          JAZ = Σ Wärme / Σ Strom
Beispiel (erfunden): 9.000 kWh Wärme / 2.500 kWh Strom = JAZ 3,6
```

**Einrichten.**

1. Den Stromzähler der Wärmepumpe mit der Rolle **„Wärmepumpe (Heizstrom)“**
   anlegen — meist als Subzähler des Hausanschlusses.
2. Den Wärmemengenzähler der Wärmepumpe unter **Heizwärme** anlegen, Rolle
   **„Wärmemenge der Wärmepumpe“**. Im Zählerdialog erscheint dann **„Stromzähler
   der Wärmepumpe“**: dort den Zähler aus Schritt 1 wählen (bei zwei
   Stromzählern, etwa Verdichter und Heizstab, beide).
3. Beide Zähler ablesen — von Hand, per Home Assistant oder aus einer Datei des
   Herstellerportals ([Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md)).

**Die Karte „Wärmepumpe {Jahr}“** steht in der Verbrauchsansicht beider
Zähler: die Jahresarbeitszahl mit der Zahl der Monate, aus denen sie stammt,
die Arbeitszahl der **Heizperiode** (Oktober bis April), Wärme und Strom des
Jahres und je Monat Wärme, Strom und Arbeitszahl. Fehlt der Wärmezähler oder
die Verknüpfung, sagt die Karte, was zu tun ist.

**Zur Einordnung** nennt sie den Feldtest „WP-QS im Bestand“ des Fraunhofer
ISE (2025): Luft/Wasser-Wärmepumpen im Mittel 3,4, Sole/Wasser 4,3.

**Grenzen.**

- Gezählt werden nur Monate, in denen **beide** Seiten Werte haben. Ein
  Sommer ohne Ablesung am Wärmezähler fehlt also in beiden Summen, nicht nur in
  einer.
- Eine Arbeitszahl wird **nicht witterungsbereinigt**. Kalte Monate haben
  meist niedrigere Werte; die API liefert dafür je Monat die Heizgradtage
  (`by_hdd`).
- **Wo die Zähler sitzen**, entscheidet, was in der Zahl steckt: Heizstab,
  Warmwasser, Umwälzpumpen. Je nach dieser Bilanzgrenze unterscheiden sich
  Arbeitszahlen derselben Anlage um rund 15 % — Werte aus verschiedenen Quellen
  sind deshalb nur bedingt vergleichbar.
- Die Wärmemenge zählt nicht in der Effizienzkennzahl (§4) und nicht in den
  Summen der Heizwärme; die Wärmepumpe steht dort mit ihrem Strom.

API: `GET /api/heat-pump?year=` ([API-Referenz](../referenz/api.md#jahresarbeitszahl-der-wärmepumpe-v310)).

---

## 8. Technische Sicht

- Zähler: Felder `role` und `capture` (`counter` | `period`; fehlt =
  Zählerstände).
- Zeiträume: Topf `<art>/periods.json`, Routen `…/periods`, CSV-Import und
  -Export — [API-Referenz](../referenz/api.md#verbrauch-je-zeitraum-v310),
  [Datenmodell](../referenz/datenmodell.md).
- Monatswerte von Warmwasserzählern tragen additiv `dhw_kwh` und `dhw_temp_c`.
- Wärmemengenzähler der Wärmepumpe: Feld `heat_pump_meter_ids` (v3.1.0), die
  verknüpften Stromzähler mit der Rolle `heat_pump`.

---

[← Länderprofile](14-laenderprofile.md) · [Kompendium-Index](../README.md) ·
[CO₂-Preis im Brennstoff →](16-co2-preis.md)
