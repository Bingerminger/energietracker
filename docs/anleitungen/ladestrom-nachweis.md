# Ladestrom für den Dienstwagen nachweisen

**Deutsch** · [English](../en/anleitungen/ladestrom-nachweis.md)

[← Kompendium-Index](../README.md)

Wer einen Dienstwagen zu Hause lädt, kann sich den Strom vom Arbeitgeber
erstatten lassen. Wie das geht, regelt das Schreiben des
Bundesfinanzministeriums (BMF) vom 11.11.2025: Der Arbeitgeber erstattet die
geladene Menge entweder zum Preis des Stromvertrags oder mit einer
Strompreispauschale je kWh. Seit **v3.1.0** stellt der Energietracker dafür
eine Aufstellung je Monat zusammen — als Karte in der App, als CSV und als PDF
mit Zählerständen und Unterschriftszeile.

> **Eine Aufstellung, keine Steuerberatung.** Die App rechnet mit deinen
> Zählerständen und Verträgen. Ob und wie die Erstattung in deinem Fall
> steuerfrei ist, klärt die Lohnabrechnung oder eine Steuerberatung.

---

## 1. Was du brauchst

- Einen **eigenen Zähler für die Wallbox** — meist ein Zwischenzähler hinter
  dem Haushaltszähler, manchmal ein eigener Zähler des Netzbetreibers (§ 14a
  EnWG, Modul 2, siehe [Strom](../verstehen/02-strom.md#steuerbare-verbraucher-v310)).
  Ohne eigenen Zähler lässt sich die geladene Menge nicht belegen.
- **Zählerstände** der Wallbox, am besten zum Monatswechsel. Die Menge eines
  Monats ist die Differenz der Stände, tagesgenau auf die Monate verteilt;
  Stände am Monatsersten machen sie exakt.
- Einen **zahlenden Vertrag**: einen eigenen Vertrag der Wallbox oder den
  Vertrag des Haushaltszählers, unter dem die Wallbox hängt. Für die
  Pauschale braucht es keinen.

## 2. Wallbox als Zähler einrichten

**Verbrauch → Strom → ⚙️ Zähler → „+ Neuer Zähler“**:

1. **Name**, etwa „Wallbox“.
2. **Rolle: „Wallbox“.** Erst mit dieser Rolle erscheint die Karte
   „Ladestrom-Nachweis (Dienstwagen)“.
3. **Elternzähler:** der Haushaltszähler, wenn die Wallbox dahinter hängt. Dann
   ist sie ein Subzähler; ihr Verbrauch steckt im Elternzähler und wird dort
   abgezogen, nicht doppelt gezählt
   ([Meter-Topologie](../verstehen/13-meter-topologie.md)). Hat die Wallbox
   einen eigenen Zähler des Netzbetreibers mit eigenem Vertrag (Modul 2), bleibt
   das Feld leer.
4. Den ersten Stand mit Datum eintragen, danach monatlich ablesen — von Hand,
   per [Home Assistant](home-assistant.md) oder aus einer Datei des
   Wallbox-Portals ([Zeitreihen aus Portalen](daten-aus-portalen.md)).

## 3. Die Karte „Ladestrom-Nachweis (Dienstwagen)“

Sie steht in der Verbrauchsansicht des Wallbox-Zählers unter den Tabellen:

| Feld | Bedeutung |
|---|---|
| **Jahr** | das laufende und die drei Vorjahre; vorgewählt ist das Vorjahr |
| **Preis** | „Vertragspreis mit anteiligem Grundpreis“ oder „Strompreispauschale“ |
| **Pauschale je kWh (Cent)** | nur bei der Pauschale; vorbelegt mit dem Wert des Länderprofils, überschreibbar |

Darunter steht „Geladen: … kWh · zu erstatten: …“ und die Knöpfe **„CSV
herunterladen“** und **„PDF herunterladen“**. Fehlt für einzelne Monate ein
Vertragspreis, sagt die Karte das; diese Monate zählen ohne Betrag.

## 4. Die zwei Methoden

**Vertragspreis mit anteiligem Grundpreis.** Gerechnet wird mit dem Vertrag,
der den Strom bezahlt: der eigene Vertrag der Wallbox, sonst der des
Elternzählers.

```text
Preis je kWh (Monat)  = Arbeitskosten des zahlenden Zählers / seine kWh   (tagesgenau)
Grundpreis-Anteil     = Grundpreis des Monats × kWh Wallbox / kWh Elternzähler
Betrag                = kWh Wallbox × Preis je kWh + Grundpreis-Anteil
```

Ändert sich der Preis mitten im Monat, ist der Monatspreis der Mittelwert nach
Tagen und Verbrauch — so, wie die Rechnung ihn bildet. Hat die Wallbox einen
eigenen Vertrag, trägt sie dessen ganzen Grundpreis.

> **Beispiel (erfunden).** Im März zählt der Haushaltszähler 400 kWh mit
> 120,00 € Arbeitskosten, also 30 ct/kWh; der Grundpreis beträgt 12,00 €. Die
> Wallbox hat 100 kWh geladen: 100 × 0,30 € = 30,00 € plus 12,00 € × 100 / 400
> = 3,00 € Grundpreis-Anteil — zusammen 33,00 €.

Die Aufteilung des Grundpreises nach kWh ist eine Annahme: Das Schreiben
verlangt nur, dass er „anteilig“ angesetzt wird. Ohne zahlenden Vertrag lehnt
die App ab („Für die Wallbox gibt es keinen zahlenden Vertrag …“).

**Strompreispauschale.** Geladene kWh × Pauschale. Für Deutschland kennt das
Länderprofil die Pauschale für **2026: 34 ct/kWh**. Beispiel aus dem
BMF-Schreiben: 3.000 kWh × 0,34 € = 1.020,00 €. Für ein Jahr ohne hinterlegte
Pauschale trägst du sie im Feld „Pauschale je kWh (Cent)“ selbst ein (0 bis
200 Cent); ohne Wert meldet die App „Für {Jahr} ist keine
Strompreispauschale hinterlegt — trag sie selbst ein“.

**Eine Methode je Jahr.** Wähle für ein Kalenderjahr eine der beiden Methoden
und bleib dabei; ein Wechsel innerhalb des Jahres ist nicht vorgesehen. Die
App rechnet jedes Jahr für sich und merkt sich die Wahl nicht.

## 5. CSV und PDF

**CSV** (`energietracker-ladestrom-JJJJ.csv`): je Monat eine Zeile. Im
Format 1 ist der Kopf fest:

```text
Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode
2026-03;m_wallbox;100;30;3;33;contract
```

Zahlen und Trennzeichen folgen den Regeln von Format 1
([CSV-Formate](../referenz/api.md#csv-formate-v310)). Mit `format=local` stehen
Kopf, Dezimaltrenner und Datum in der Sprache der Installation, für eine
Tabellenkalkulation.

**PDF** (`energietracker-ladestrom-JJJJ.pdf`): die Aufstellung zum Ausdrucken —
Zähler mit Seriennummer, Methode (bei der Pauschale mit dem Satz), je Monat
der erste und der letzte Zählerstand im Monat, kWh, Cent je kWh,
Grundpreis-Anteil und Betrag, die Summe, ein Absatz zur Grundlage der Rechnung,
der Hinweis „keine Steuerberatung“ und eine Zeile für Datum und Unterschrift.
Das PDF entsteht in der Standardsprache der Installation; für eine Sprache, die
die eingebauten PDF-Schriften nicht setzen können, antwortet die App mit
`422`.

## 6. Grenzen

- **Ein Eigenbeleg allein reicht nicht.** Leg der Aufstellung den Stromvertrag
  und die Rechnung bei; das sagt auch der Hinweis auf Karte und PDF.
- **Geschätzte Monate.** Liegen in einem Monat keine zwei Stände, verteilt die
  App die Differenz der umgebenden Stände nach Tagen. Die API kennzeichnet
  solche Monate mit `estimated: true`.
- **Ohne Vertragspreis kein Betrag.** Monate, in denen der zahlende Vertrag
  keinen Arbeitspreis hat, zählen mit ihrer Menge, aber ohne Betrag
  (`price_missing`).
- **Monatspreise.** Hat der Vertrag Preise je Monat (etwa ein dynamischer
  Tarif, eingetragen mit „Monatspreise importieren“), folgt der Vertragspreis
  ihnen ([Strom](../verstehen/02-strom.md#dynamische-tarife-v310)).
- **Mehrere Wallboxen:** je Zähler ein Nachweis.

## 7. Über die API

```text
GET /api/reports/ev-charging?meter_id=m_wallbox&year=2026&method=contract
GET /api/reports/ev-charging.csv?meter_id=m_wallbox&year=2026&method=flat&flat_ct=34
GET /api/reports/ev-charging.pdf?meter_id=m_wallbox&year=2026&method=contract&inline=1
```

Felder, Fehlercodes und Grenzen:
[API-Referenz → Ladestrom-Nachweis](../referenz/api.md#ladestrom-nachweis-v310).

---

[← Kompendium-Index](../README.md)
