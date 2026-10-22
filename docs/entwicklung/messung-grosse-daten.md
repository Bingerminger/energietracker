# Messung mit großen Datenmengen

**Deutsch** · [English](../en/entwicklung/messung-grosse-daten.md)

[← Tests](tests.md) · [Kompendium-Index](../README.md)

Wie lange braucht die App, wenn ein Haushalt zehn oder zwanzig Jahre lang
jeden Tag abliest — etwa über Home Assistant oder einen CSV-Import langer
Verläufe? Die Messung beantwortet das mit einem erfundenen Haushalt und legt
fest, ab wann N1006 (Caching und Speicher-Umbau, siehe Roadmap) nötig wird.
Stand: v3.7.0.

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
```

**Der Haushalt** (alles erfunden, fester Startwert): Gas, Strom, Wasser,
PV-Erzeugung und PV-Einspeisung mit je einem Zähler und einer Ablesung an
**jedem Tag** bis gestern, Tagestemperaturen für denselben Zeitraum, je
Kalenderjahr ein Vertrag für Gas, Strom und Wasser, eine Einspeisevergütung
über 20 Jahre und für die letzten zwei vollen Kalenderjahre Viertelstunden für
Strom, PV-Erzeugung und PV-Einspeisung (Summe je Tag = Ablesung) samt
Börsenpreisen. Zehn Jahre sind 18.260 Ablesungen und 280.704
Viertelstundenwerte. Nicht in `demo-data/`: Die Beispieldaten entstehen in
`tools/build-personas.mjs`.

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
