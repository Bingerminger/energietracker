# Zeitreihen aus Portalen übernehmen

**Deutsch** · [English](../en/anleitungen/daten-aus-portalen.md)

[← Kompendium-Index](../README.md)

Netzbetreiber, Messstellenbetreiber, Wechselrichter und Wärmepumpen geben ihre
Messwerte oft als Datei heraus: Viertelstundenwerte (Lastgang), Stunden- oder
Tageswerte. Seit **v3.1.0** liest der Energietracker solche Dateien mit einer
**Spaltenzuordnung** ein und verdichtet sie zu Tageswerten — als Zählerstände
oder als Verbrauch je Tag. Eine Verbindung zu den Portalen baut die App nicht
auf: Du lädst die Datei herunter und liest sie ein.

---

## 1. Woher die Daten kommen

| Quelle | Was es dort meist gibt |
|---|---|
| **Netzbetreiber oder Messstellenbetreiber** (Kundenportal) | Mit einem intelligenten Messsystem Viertelstundenwerte für Bezug und Einspeisung; mit einer modernen Messeinrichtung oft nur Zählerstände oder Tageswerte |
| **Wechselrichter** (App oder Portal des Herstellers) | Erzeugung je Tag oder Stunde, manchmal dazu Einspeisung, Bezug und Speicher, teils in Wh |
| **Wärmepumpe** (Portal des Herstellers) | Wärmemenge und Stromaufnahme je Tag, teils als Zählerstand |
| **Wallbox** (App oder Portal) | geladene Energie je Ladevorgang oder Tag, oft mit Zählerstand |

Die Datei ist fast immer eine CSV-Datei, manchmal als „Export“ oder
„Download“ bezeichnet. Eine Excel-Datei speicherst du vorher als CSV.

**Ein Recht auf die eigenen Daten.** Seit dem 12. September 2025 gilt der EU
Data Act (Verordnung (EU) 2023/2854): Wer ein vernetztes Gerät nutzt — etwa
einen Wechselrichter, eine Wärmepumpe oder eine Wallbox —, kann vom
Dateninhaber, meist dem Hersteller oder dem Anbieter der zugehörigen App, die
ohne Weiteres verfügbaren Daten des Geräts verlangen: unentgeltlich, in einem
gängigen, maschinenlesbaren Format, auf Wunsch auch an einen Dritten (Art. 4
und 5). Ausgenommen sind Geräte von Kleinst- und Kleinunternehmen (Art. 7).
Der Energietracker
ist in diesem Bild der Empfänger, mit einer Besonderheit: Er läuft auf deinem
eigenen Server, die Daten bleiben bei dir. Eine Schnittstelle zu Herstellern
hat er nicht; er liest die Datei, die du bekommst. Das ist ein Hinweis, keine
Rechtsberatung.

## 2. Vorher klären

Öffne die Datei einmal in einem Texteditor und beantworte vier Fragen:

1. **Wie viele Zeilen stehen vor den Daten?** Kopf, Titel, Zählernummer,
   Spaltennamen — alles, was vor dem ersten Wert steht.
2. **In welcher Spalte stehen Datum, Uhrzeit und Wert?** Datum und Uhrzeit
   dürfen in einer Spalte stehen oder in zwei.
3. **Ist der Wert ein Zählerstand oder ein Verbrauch je Intervall?** Ein
   Zählerstand wächst immer weiter (12.345,6 → 12.346,1); ein Verbrauch je
   Intervall ist klein und schwankt (0,061 kWh in einer Viertelstunde).
4. **Bezeichnet der Zeitstempel den Beginn oder das Ende des Intervalls?**
   Viele Netzbetreiber stempeln das Ende: Die Viertelstunde von 23:45 bis 0:00
   trägt dann „0:00“ des Folgetags.

Dazu die Einheit: kWh, Wh oder MWh. Wasser- und Gaszähler zählen in m³.

## 3. Schritt für Schritt

**Verbrauch → *Verbrauchsart* → ⚙️ Zähler**, beim gewünschten Zähler
**„Zeitreihe importieren“** (bei allen Arten mit Zählerständen, nicht bei
Heizöl und Pellets). Den Zähler legst du vorher an, mit der passenden Rolle und
Erfassung.

1. **„Datei wählen“** — die ersten Zeilen der Datei erscheinen im Dialog,
   damit du die Spalten siehst.
2. **„Kopfzeilen“** — die Zahl der Zeilen vor den Daten (Frage 1).
3. **„Spalte Datum (und Uhrzeit)“**, **„Spalte Uhrzeit (falls getrennt)“** und
   **„Spalte Wert“** — die Auswahl zählt die Spalten ab 1 und zeigt den
   Spaltennamen aus der Kopfzeile dazu.
4. **„Die Werte sind“**: „Verbrauch je Intervall“ oder „Zählerstände“
   (Frage 3).
5. **„Einheit der Werte“**: kWh, Wh oder MWh bei Arten in kWh; bei Wasser und
   Gas die Einheit des Zählers.
6. **„Zeitstempel bezeichnet“**: „den Beginn des Intervalls“ oder „das Ende
   des Intervalls“ (Frage 4).
7. **„Startwert (Zählerstand)“** — nur bei Verbrauchswerten auf einem Zähler
   mit Ständen: der Stand zu Beginn des ersten Tages. Leer gilt die Ablesung an
   diesem Tag.
8. **„Vorschau“** — die App liest die Datei, ohne etwas zu speichern, und
   zeigt etwa „365 Tage vom 01.01.2025 bis 31.12.2025, zusammen 3.412,6 kWh“
   (bei Zählerständen „je Tag der letzte Stand“) und wie viele Zeilen ohne
   Datum oder Wert sie übersprungen hat.
9. **„Übernehmen“** schreibt.

Trennzeichen (Semikolon, Tabulator, Komma), Dezimalkomma oder -punkt und die
Zeichenkodierung (UTF-8 oder Windows-1252) erkennt die App selbst. Die
Zuordnung merkt sich der Browser je Zähler; beim nächsten Monat genügen Datei,
Vorschau und Übernehmen.

## 4. Was die App daraus macht

| Werte in der Datei | Zähler mit | Ergebnis |
|---|---|---|
| Zählerstände | Zählerständen | je Tag der **letzte Stand** als Ablesung; ein vorhandener Stand am selben Tag wird ersetzt |
| Verbrauch je Intervall | Verbrauch je Zeitraum | je Tag ein **Zeitraum** mit der Summe des Tages — wie der [Zeitraum-Import](../verstehen/15-waerme.md#2-verbrauch-je-zeitraum); Tage, die sich mit vorhandenen Zeiträumen überschneiden, werden übersprungen |
| Verbrauch je Intervall | Zählerständen | **Stände**, aufsummiert ab einem Anfangsstand — der Ablesung am ersten Tag oder dem Startwert. Der Stand am Ende eines Tages steht am Folgetag (0:00) |
| Zählerstände | Verbrauch je Zeitraum | abgelehnt: „Die Spaltenzuordnung passt nicht zur Datei …“ |

Ohne Anfangsstand fragt die App danach: „Für Verbrauchswerte braucht es einen
Anfangsstand: eine Ablesung am … oder einen Startwert“.

Danach rechnet alles wie bei Ständen, die du eintippst: Monatsverbrauch,
Kosten, Saldo, Prognose, Plausibilität. Gespeichert werden Tageswerte, keine
Viertelstunden — die Auswertungen der App rechnen in Tagen und Monaten.

## 5. Zeitstempel und Zeitumstellung

- **Formate:** `TT.MM.JJJJ HH:MM`, `JJJJ-MM-TT HH:MM` (Sekunden erlaubt) und
  ISO 8601 mit Zeitzone, etwa `2025-03-30T01:00:00+01:00` oder `…Z`. Datum
  allein geht auch (Tageswerte) — dann „den Beginn des Intervalls“ wählen,
  sonst landet jeder Wert am Vortag.
- **Zeitzone:** Stempel ohne Angabe gelten in der Zeitzone der Installation
  (Einstellungen → Allgemein → Zeitzone). Stempel mit Angabe rechnet die App in
  diese Zeitzone um, bevor sie den Tag bestimmt.
- **Zeitumstellung:** Der Tag der Umstellung hat 23 bzw. 25 Stunden, also 92
  bzw. 100 Viertelstunden. Die App zählt sie alle zu ihrem Tag; nichts fehlt,
  nichts doppelt.
- **Endstempel um 0:00:** Mit „das Ende des Intervalls“ gehört ein Intervall,
  das um 0:00 endet, zum Vortag.

Ein Jahr Viertelstundenwerte (35.040 Zeilen) liest die App in wenigen Sekunden
zu 365 Tagen.

## 6. Beispiele

Alle Dateien sind erfunden; die Spalten heißen in echten Portalen anders.

**Lastgang vom Netzbetreiber** — Viertelstunden in kWh, Endstempel, Datum und
Uhrzeit getrennt:

```text
Zählpunkt;DE0000000000000000000000000000000
Datum;Uhrzeit;Wert (kWh);Status
01.01.2025;00:15;0,061;W
01.01.2025;00:30;0,058;W
…
02.01.2025;00:00;0,071;W
```

Kopfzeilen 2 · Datum Spalte 1 · Uhrzeit Spalte 2 · Wert Spalte 3 ·
„Verbrauch je Intervall“ · kWh · „das Ende des Intervalls“. Auf einen
Stromzähler mit Ständen braucht es den Stand vom 1. Januar — als Ablesung oder
als Startwert.

**Wechselrichter** — Tageswerte in Wh, ein Datum je Zeile:

```text
Date,Energy [Wh]
2025-06-01,18230
2025-06-02,21045
```

Kopfzeilen 1 · Datum Spalte 1 · Wert Spalte 2 · „Verbrauch je Intervall“ ·
Wh · „den Beginn des Intervalls“. Bietet das Portal den Gesamtzähler des
Wechselrichters, ist „Zählerstände“ einfacher: Dann braucht es keinen
Startwert.

**Wärmepumpe** — Zählerstand der Wärmemenge je Tag, für einen
Heizwärme-Zähler mit der Rolle „Wärmemenge der Wärmepumpe“ (für die
[Jahresarbeitszahl](../verstehen/15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310)):

```text
Zeit;Wärmemenge gesamt [kWh]
2025-10-01T00:00:00+02:00;18412,3
2025-10-02T00:00:00+02:00;18431,9
```

Kopfzeilen 1 · Datum Spalte 1 · Wert Spalte 2 · „Zählerstände“ · kWh ·
„den Beginn des Intervalls“.

## 7. Grenzen

- **Keine Abrufe.** Die App holt nichts aus Portalen; für laufende Werte ohne
  Datei ist [Home Assistant](home-assistant.md) der Weg.
- **Tageswerte, keine Viertelstunden.** Eine Abrechnung je Viertelstunde, wie
  sie ein dynamischer Tarif braucht, bildet die App nicht nach
  ([Strom → Dynamische Tarife](../verstehen/02-strom.md#dynamische-tarife-v310)).
- **Fehlerhafte Zeilen** ohne erkennbares Datum oder Wert überspringt die App
  und nennt die ersten zwanzig („Zeile 12: kein Datum oder Wert erkannt …“).
  Passt die Zuordnung gar nicht, meldet sie das, statt etwas zu schreiben.
- **Ersetzen statt Ergänzen** bei Zählerständen: Ein Stand aus der Datei
  ersetzt einen eigenen am selben Tag. Wer seine abgelesenen Stände behalten
  will, prüft in der Vorschau den Zeitraum und kürzt die Datei notfalls.

## 8. Über die API

`POST /api/utility/{u}/meters/{id}/import-series` mit `?dry_run=1` für die
Vorschau; im Körper die Datei als Text und die Zuordnung mit Spalten ab 0:

```json
{ "csv": "Datum;Uhrzeit;Wert (kWh)\n01.01.2025;00:15;0,061\n…",
  "mapping": { "skip_rows": 1, "date_col": 0, "time_col": 1, "value_col": 2,
               "value_kind": "consumption", "unit_factor": 1,
               "interval_stamp": "end", "start_counter": 40211.0 } }
```

Alle Felder und Fehlercodes:
[API-Referenz → Zeitreihe importieren](../referenz/api.md#zeitreihe-importieren-v310).

---

[← Kompendium-Index](../README.md)
