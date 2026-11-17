# Messung mit großen Datenmengen

**Deutsch** · [English](../en/entwicklung/messung-grosse-daten.md)

[← Tests](tests.md) · [Kompendium-Index](../README.md)

Wie lange braucht die App, wenn ein Haushalt zehn oder zwanzig Jahre lang
jeden Tag abliest — etwa über Home Assistant oder einen CSV-Import langer
Verläufe? Die Messung beantwortet das mit einem erfundenen Haushalt und legt
fest, ab wann N1006 (Caching und Speicher-Umbau, siehe Roadmap) nötig wird.
Stand: v3.7.0; Druckansicht und Tankbuch: v3.13.0 ([unten](#v3130-druckansicht-und-tankbuch)).

---

## Werkzeuge

| Werkzeug | Zweck |
|---|---|
| `tools/large-household.mjs` | baut den erfundenen Haushalt in ein leeres Datenverzeichnis |
| `tools/measure-large-household.mjs` | baut Haushalte verschiedener Größe, startet je einen Server, misst die Endpunkte und mit `--render` die Ansichten |
| `tests/large-data.test.mjs` | sichert die Grenzen in der CI (siehe unten) |

```bash
node tools/large-household.mjs --out /tmp/et-large              # 10 Jahre, 2 Jahre Viertelstunden
ET_DATA_DIR=/tmp/et-large php -S 127.0.0.1:8930 router.php      # ansehen
NODE_PATH=<node_modules mit jsdom> node tools/measure-large-household.mjs \
  --years 5,10,20 --runs 5 --demo --render                      # Tabellen unten
node tools/large-household.mjs --out /tmp/et-tank --tank --tank-years 75   # dazu ein Heizöltank über 75 Jahre
node tools/measure-large-household.mjs --years 10 --tank        # misst auch die Heizöl-Ansichten
```

**Der Haushalt** (alles erfunden, fester Startwert): Gas, Strom, Wasser,
PV-Erzeugung und PV-Einspeisung mit je einem Zähler und einer Ablesung an
**jedem Tag** bis gestern, Tagestemperaturen für denselben Zeitraum, je
Kalenderjahr ein Vertrag für Gas, Strom und Wasser, eine Einspeisevergütung
über 20 Jahre und für die letzten zwei vollen Kalenderjahre Viertelstunden für
Strom, PV-Erzeugung und PV-Einspeisung (Summe je Tag = Ablesung) samt
Börsenpreisen. Zehn Jahre sind 18.260 Ablesungen und 280.704
Viertelstundenwerte. Nicht in `demo-data/`: Die Beispieldaten entstehen in
`tools/build-personas.mjs`. *(v3.13.0)* Mit `--tank` kommt ein Heizöltank
dazu (6.000 L, jedes Jahr am 1. Juli „bis voll“, vierteljährlich ein
Peilstand), mit `--tank-years` über mehr Jahre als der Rest; die Temperaturen
davor kommen aus einer eigenen Reihe, der Haushalt ohne Tank bleibt Datei für
Datei derselbe.

**Gemessen** auf dem Entwicklungsrechner (Apple M1 Max, PHP 8.5 mit dem
eingebauten Server `php -S`, Node 26): je Endpunkt der Median aus fünf
Abrufen nach einem Aufwärmlauf, Antwort vollständig gelesen.

## Ergebnis: Antwortzeiten der Endpunkte

Je Ansicht die Anfragen, auf die sie wartet. Die letzte Spalte ist der Stand
vor den Änderungen dieses Release (v3.6.0, zehn Jahre).

| Ansicht | Anfrage | Beispieldaten | 5 Jahre | 10 Jahre | 20 Jahre | 10 Jahre (v3.6.0) |
|---|---|---:|---:|---:|---:|---:|
| Übersicht | Zusammenfassung | 67 ms | 199 ms | 403 ms | 876 ms | 1,13 s |
| Übersicht | Termine (90 Tage) | 53 ms | 170 ms | 340 ms | 771 ms | 873 ms |
| Übersicht | Einordnung | 29 ms | 84 ms | 174 ms | 361 ms | 324 ms |
| Verbrauchsart | Gas: Verbrauch je Monat | 6 ms | 36 ms | 73 ms | 152 ms | 150 ms |
| Verbrauchsart | Gas: Vertragsstatus/Saldo | 7 ms | 41 ms | 83 ms | 181 ms | 260 ms |
| Verbrauchsart | Strom: Verbrauch je Monat | 5 ms | 33 ms | 66 ms | 139 ms | 143 ms |
| Verbrauchsart | Strom: Ablesungen (Liste) | 2 ms | 5 ms | 9 ms | 17 ms | 9 ms |
| Verbrauchsart | PV: Eigenverbrauch | 8 ms | 83 ms | 166 ms | 353 ms | 253 ms |
| Analyse | Effizienz | 26 ms | 35 ms | 68 ms | 146 ms | 149 ms |
| Abrechnungsperioden | Gas: Perioden je Zähler | 6 ms | 76 ms | 161 ms | 374 ms | 761 ms |
| Abrechnungsperioden | Gas: Perioden der Art | 6 ms | 76 ms | 162 ms | 370 ms | 760 ms |
| Jahresbericht | Modell (Druckansicht) | 94 ms | 215 ms | 417 ms | 901 ms | 1,59 s |
| Jahresbericht | Modell mit Anhang | 114 ms | 410 ms | 777 ms | 1,70 s | 3,41 s |
| Flexibilität | Auswertung | 12 ms | 77 ms | 75 ms | 76 ms | 71 ms |
| Flexibilität | Kachel der Übersicht | 7 ms | 59 ms | 57 ms | 58 ms | 53 ms |
| Prognose-Werkstatt | Gas: Prognose | 6 ms | 36 ms | 71 ms | 147 ms | 147 ms |
| Prognose-Werkstatt | Gas: Szenario „Kalter Winter“ | 8 ms | 80 ms | 160 ms | 369 ms | 761 ms |

Bei zwanzig Jahren brauchten unter v3.6.0 die Zusammenfassung 3,91 s, die
Abrechnungsperioden 2,75 s und der Jahresbericht mit Anhang 12,2 s. Seit v3.7.0
wächst jede Anfrage **linear** mit der Zahl der Ablesungen (doppelte Datenmenge,
doppelte Zeit); vorher wuchsen mehrere quadratisch. Die Viertelstunden wachsen
nicht mit: je Jahr eine Datei, die Flexibilität rechnet ein Jahr.

## Ergebnis: Ansichten

Zeit vom Seitenwechsel, bis die Ansicht ohne „Lädt …“ dasteht — die echte App in
JSDOM gegen denselben Server, ohne das Zeichnen der Diagramme.

| Ansicht | Beispieldaten | 5 Jahre | 10 Jahre | 20 Jahre |
|---|---:|---:|---:|---:|
| Übersicht | 359 ms | 1,23 s | 2,36 s | 4,83 s |
| Verbrauchsart (Gas) | 77 ms | 251 ms | 452 ms | 926 ms |
| Verbrauchsart (Strom) | 51 ms | 204 ms | 369 ms | 764 ms |
| Analyse | 38 ms | 104 ms | 191 ms | 386 ms |
| Prognose-Werkstatt | 48 ms | 148 ms | 266 ms | 534 ms |
| Flexibilität | 20 ms | 111 ms | 112 ms | 107 ms |
| Jahresbericht (Druckansicht) | 159 ms | 429 ms | 822 ms | 1,67 s |
| Zählerstände erfassen | 21 ms | 29 ms | 46 ms | 76 ms |

Die Übersicht stellt rund zehn Anfragen. `php -S` beantwortet sie
nacheinander; hinter nginx mit php-fpm (Docker-Image) laufen sie parallel, die
Übersicht steht dann etwa nach der langsamsten Anfrage.

## Was v3.7.0 behoben hat

Vier kleine Änderungen in `ConsumptionService` und `ContractService`, ohne
andere Zahlen (die Ergebnisse aller Zähler, Vertragsstatus,
Rechnungsprüfungen und Abrechnungsperioden sind vorher und nachher
byte-gleich, gemessen an den Beispieldaten und am großen Haushalt):

1. **Schnitttage einmal je Zähler.** Die Monatsrechnung zerlegt jedes
   Ableseintervall an Vertragsgrenzen und Preisstichtagen
   (`segmentsBetween`) und baute dafür jedes Mal die Liste aller Stichtage
   aller Verträge neu — bei täglichen Ablesungen und einem Vertrag je Jahr
   Ablesungen × Verträge. Jetzt holt sie die Liste einmal
   (`ContractService::cutDates()`, `segmentsWithin()`); die
   Rechnungsprüfung je Zeile ebenso.
2. **Letzte Ablesung einmal je Zähler.** Der Vertragsstatus fragt für jeden
   Vertrag, bis wann gemessen ist, und las dafür jedes Mal alle Ablesungen.
3. **Grundlage der Rechnungsprüfung einmal je Zähler.** Die
   Abrechnungsperioden prüfen jede Periode (mit Anhang jeden Monat); jede
   Prüfung baute alle Intervalle neu und suchte die Tagesrate jeder Zeile von
   vorn.
4. **Tagestemperaturen einmal je Anfrage** statt je Zähler und Vertrag.

Alles gilt nur innerhalb einer Anfrage (wie die vorhandene Zwischenablage von
`forMeter()`), bis zum nächsten Schreiben.

## v3.13.0: Druckansicht und Tankbuch

In der CI lag die Antwortzeit „Modell (Druckansicht)“ einmal bei 4.328 ms,
über der Grenze von 4.170 ms (Zehnfaches der Messung). Die Grenze bleibt; die
Druckansicht ist schneller geworden. Dazu rechnet das Tankbuch (Heizöl,
Pellets) seine Tagesreihe je Anfrage einmal. Alle Werte bleiben gleich: alt
gegen neu byte-gleich an den Beispielhaushalten, am unordentlichen
Testhaushalt und an einem Haushalt mit Tank, an mehreren Stichtagen.

1. **Letzte echte Ablesung einmal je Zähler und Stichtag.** Seit v3.12.0
   fragt der Vertragsstatus für jeden Vertrag, bis wann echt gemessen ist, und
   las dafür jedes Mal alle Ablesungen neu. Bei zehn Jahren Tageswerten mit
   einem Vertrag je Jahr war das über die Hälfte der Druckansicht und fast die
   Hälfte der Übersicht. Jetzt wie die letzte Ablesung: einmal je Anfrage.
2. **Das Tankbuch in Spalten, einmal je Tank und Stichtag.** Gemerkt wird nicht
   mehr eine Zeile je Tag, sondern je Größe eine Liste (Verbrauch, Bestand,
   Durchschnittspreis). Die Temperaturen liest die Anfrage einmal, nicht je
   Tank. Das Datum eines Tages steht als Text mit genau seinen zehn Zeichen:
   `DateTimeImmutable::format()` liefert unter PHP 8.5 rund 250 Byte je Text,
   bei 75 Jahren waren das 7 MB je Tankbuch.
3. **Nur der abgefragte Zeitraum.** Übersicht, Empfehlungen und Agenda brauchen
   den Bestand von heute (`DeliveryService::stockLatest()`), der Jahresbericht
   die Tage ab dem Berichtsjahr. Bis v3.12 bauten sie dafür die Tageszeilen
   aller Jahre. Das Tankbuch selbst rechnet weiter ab dem Start: Bestand,
   Preis und Rate hängen an allen Tagen davor.

Die Regeln halten `TankSeriesTest` (einmal je Tank und Stichtag, nur der
Stand, Zeitraum als Ausschnitt, rund 130 Byte je Tag) und
`LastMeasuredOnceTest`.

**Gemessen** abwechselnd alt und neu auf demselben Rechner, je Runde ein
eigener Server, Median aus drei Runden mit je drei Abrufen. Während der
Messung liefen andere Prozesse (Last 4 bis 10 bei 10 Kernen); die Zahlen sind
deshalb höher als die Spalte „10 Jahre“ oben und nur untereinander
vergleichbar. Die Spalte oben und die Grenzen des Tests bleiben unverändert.

| Ansicht · Anfrage (10 Jahre) | v3.12 | v3.13 |
|---|---:|---:|
| Übersicht · Zusammenfassung | 2,27 s | 1,11 s |
| Übersicht · Termine (90 Tage) | 1,87 s | 1,03 s |
| Verbrauchsart · Gas: Vertragsstatus/Saldo | 295 ms | 173 ms |
| Jahresbericht · Modell (Druckansicht) | 3,14 s | 1,36 s |
| Jahresbericht · Modell mit Anhang | 3,63 s | 1,86 s |
| Prognose-Werkstatt · Gas: Szenario „Kalter Winter“ | 390 ms | 206 ms |

Die übrigen elf Anfragen der Tabelle oben sind alt und neu gleich schnell
(Unterschiede bis 5 %, im Rahmen der Messunruhe). Mit Heizöltank über zehn
Jahre (`--tank`): Verbrauch je Monat 30 → 23 ms, Bestand 13 → 12 ms,
Prognose 36 → 24 ms.

**Ein Tank über 75 Jahre** (`--tank --tank-years 75`, sonst zehn Jahre):

| Anfrage | v3.12 | v3.13 |
|---|---:|---:|
| Übersicht · Zusammenfassung | 2,57 s · 134 MB | 1,32 s · 112 MB |
| Übersicht · Termine (90 Tage) | 2,07 s · 134 MB | 1,25 s · 112 MB |
| Jahresbericht · Modell (Druckansicht) | 3,28 s · 146 MB | 1,57 s · 126 MB |
| Jahresbericht · Modell mit Anhang | HTTP 500 · 154 MB | 2,03 s · 132 MB |
| Heizöl · Verbrauch je Monat | 186 ms · 88 MB | 145 ms · 54 MB |
| Heizöl · Bestand (Tankbuch) | 76 ms · 64 MB | 72 ms · 52 MB |

Zeit am Server wie oben; Speicher als Spitze je Anfrage in einem eigenen
PHP-Prozess ohne Grenze. Mit der Grenze des Servers (`memory_limit = 128M`)
scheiterte unter v3.12 am Server der Anhang, im eigenen Prozess auch die
Druckansicht (Fehler 500); unter v3.13 kommen beide durch, der Anhang knapp an
der Grenze. Ein Tankbuch über 75 Jahre hält jetzt rund 3 MB statt 18 MB.

**Was bleibt:** Das Schaufenster mit Heizöl und Pellets, bis 2099
fortgeschrieben (Skeptiker-Lauf zu v3.12.0), braucht für Übersicht, Agenda,
Jahresbericht und Kalender weiter mehr als 128 MB: 134 bis 142 MB statt 164
bis 176 MB; nur die Empfehlungen passen jetzt (116 MB). Der Rest liegt nicht
mehr im Tank, sondern in allen Zählern über 77 Jahre: Monatszeilen (rund
32 MB), Temperaturen (15 MB), das Gradtage-Raster der Heizgrenze (15 MB) und
die Tagesabschnitte (14 MB). Das gehört zu N1006.

## Was bleibt — und die Schwelle für N1006

Offen, weil kein kleiner und sicherer Eingriff:

- **Jede Anfrage liest die Dateien neu.** Zehn Jahre Tagesablesungen sind je
  Zähler eine JSON-Datei von 0,7 bis 1 MB; jede Anfrage liest und dekodiert sie,
  eine Zwischenablage über Anfragen hinweg gibt es nicht.
- **Die Übersicht rechnet dieselben Zähler mehrfach** — Zusammenfassung,
  Termine, Einordnung, PV und Vertragsstatus sind getrennte Anfragen und
  rechnen jede für sich die Monatswerte aller Zähler. Ein Ergebnis-Cache je
  Zähler (Schlüssel: Änderungszeit der Dateien) würde das teilen; genau das ist
  N1006.
- Home Assistant fragt die Zusammenfassung alle fünf Minuten ab
  (`Cache-Control: max-age=300`).

**Schwelle:** N1006 wird nötig, sobald die **Zusammenfassung
(`/api/summary`) auf dem Entwicklungsrechner länger als 300 ms** braucht. Auf
einem NAS oder Raspberry Pi ist mit dem Drei- bis Fünffachen zu rechnen
(Annahme aus der Rechenleistung, nicht gemessen), also mit 1 bis 1,5 s je
Abruf und einer spürbar zähen Übersicht. Seit v3.7.0 liegt die Schwelle bei
rund **7,5 Jahren täglicher Ablesungen für fünf Zähler (etwa 13.500
Ablesungen im Haushalt)**; unter v3.6.0 lag sie bei etwa vier Jahren. Mit
monatlichen Ablesungen ist sie praktisch unerreichbar. Wer viele Jahre
Tageswerte importiert, ist der Fall, für den N1006 kommt — vorher mit
`tools/measure-large-household.mjs` nachmessen, gern mit der eigenen
Zählerzahl (`--years`).

## Der Test

`tests/large-data.test.mjs` läuft **in der CI**, mit großzügigen Schranken:
Jeder Endpunkt muss unter dem **Zehnfachen** seines Werts aus der Spalte
„10 Jahre“ bleiben (mindestens 500 ms). Ein GitHub-Runner ist langsamer als der
Entwicklungsrechner und schwankt; eine enge Schranke würde wackeln, gar keine
ließe einen Rückfall in quadratische Laufzeit durch (v3.6.0: bei zehn Jahren
bis fünfmal langsamer, bei zwanzig bis siebenmal). Ohne Zeitbezug prüft er,
dass jede Anfrage mit 200 und vollständigen Daten antwortet. Lokal prüft
`ET_PERF_STRICT=1 node tests/large-data.test.mjs` gegen den gemessenen Wert
plus 50 %. Die Richtwerte im Test und die Spalte „10 Jahre“ oben müssen
übereinstimmen; der Test vergleicht beide.

---

[← Tests](tests.md) · [Kompendium-Index](../README.md)
