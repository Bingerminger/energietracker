# Zählerstand-Erfassung (F1004)

**Deutsch** · [English](../en/functional/11-zaehlerstaende.md)

> Gilt für **Gas, Strom, Wasser, Fernwärme** — die Energieträger mit
> kumulativem Zählerstand-Modell. **Heizöl** und **Pellets** sind
> ausgenommen: sie erfassen den Verbrauch über Lieferungen, nicht über
> Ablesungen — ein eigenes Datenmodell mit eigener UI (siehe
> [Heizöl](05-heizoel.md), [Pellets](06-pellets.md)).

## Wozu

Die bisherige Erfassung lief pro Energieart: man wechselte in den Gas-
View, trug die Ablesung ein, ging zurück, wechselte in den Strom-View,
und so weiter. Beim Vor-Ort-Ablesen mit dem Smartphone — Keller,
Hausanschlussraum, draußen am Gartenzähler — ist das eine Kette aus
Klicks und Wartezeiten.

F1004 bündelt diesen Vorgang in einer einzigen Ansicht: alle
kumulativen Zähler in einer kompakten Karten-Liste, jeweils mit dem
letzten bekannten Stand als Referenz und einem Eingabefeld für den
neuen Wert. Ein „Alle speichern"-Button am unteren Rand schickt die
ausgefüllten Zeilen sequenziell ans Backend; leere Karten werden
übersprungen, Fehler isoliert pro Zeile sichtbar gemacht.

## Aufbau einer Karte

Pro Zähler enthält die Karte:

- **Bezeichnung** (Zählername, Verbrauchsart, optional Standort-Notiz)
- **Letzter bekannter Stand** (Wert + Datum + ggf. Tag „geschätzt")
- **Neuer Stand** — numerisches Eingabefeld, `inputmode="decimal"`
  öffnet auf iPhone/Android direkt die Zahlentastatur
- **Datum** — oben ein Datum für alle Karten (Default heute); je Karte seit
  v2.12.0 eingeklappt hinter „Anderes Datum". Eine Karte mit eigenem Datum
  behält es, wenn sich das Datum oben ändert.
- **Geschätzt** — Toggle, der die Ablesung als Schätzung markiert
  (mappt auf das bestehende `is_estimated`-Flag des Reading-Schemas)
- **Notiz** — auf Klick aufklappbar, optional, max. 200 Zeichen

## Speichern

Ein einziger Sticky-Button am unteren Rand. Beim Klick:

1. Iteriert über alle Karten,
2. überspringt leere („Neuer Stand" nicht gefüllt),
3. POSTet pro Karte gegen `/api/utility/{u}/readings`,
4. zeigt pro Karte ✓ (gespeichert) oder ✗ (Fehler) als Statusindikator,
5. fasst am Ende per Toast zusammen („3 gespeichert · 1 leer" oder
   „2 gespeichert, 1 fehlgeschlagen"); seit v2.12.0 mit der Änderung je
   Verbrauchsart („Gas +5,4 m³") und zehn Sekunden **„Rückgängig"**, das die
   eben angelegten Stände wieder löscht. Ersetzte Stände (Rückfrage „Ersetzen")
   nimmt „Rückgängig" nicht zurück.

Eine fehlerhafte Karte blockiert die anderen **nicht** — robust gegen
Teilfehler. Nach erfolgreichem Speichern wird der „letzter Stand" in
der Karte aktualisiert, damit ein zweiter Klick gegen die neue
Baseline validiert.

## Validierung

- **Numerische Eingabe** — nur Zahlen, Komma oder Punkt als
  Dezimaltrenner.
- **Leere Eingabe** — die Karte wird beim Speichern still übersprungen,
  nicht als Fehler markiert.
- **Fehler am Feld** (v2.12.0) — ein unlesbarer Wert oder eine Ablehnung des
  Servers steht unter dem Feld (`aria-invalid`, `aria-describedby`), nicht
  nur im Titel des ✗.

## Direktsprung je Zähler (v2.12.0)

`#/zaehlerstaende?meter=<id>` öffnet die Erfassung mit der Karte dieses
Zählers markiert und im Fokus. Gedacht für ein Lesezeichen auf dem
Home-Bildschirm („Gaszähler ablesen"), einen Kurzbefehl und „Zu tun" auf der
Übersicht. Die Zähler-ID steht in der Adresszeile der Zähleransicht und in
`GET /api/utility/{u}/meters`.

## Plausibilität (v2.6.0)

Ein Tippfehler wandert sonst still in Kosten, Prognose und Effizienzklasse:
„12345" statt „1234,5" ist ein Monatsverbrauch in Höhe eines Jahres. Deshalb
fragt die Erfassung — Karte wie Ablese-Dialog der Verbrauchsansicht —
**vor dem Speichern** nach. Nichts davon blockiert hart; Zählertausch,
Überlauf und Nachträge sind legitim.

| Rückfrage | Wann | Hinweis |
|---|---|---|
| **Sprung** | Tagesverbrauch seit der letzten Ablesung > **3 ×** der typische | „Das wären 400 kWh am Tag, sonst sind es etwa 8. Tippfehler?" |
| **Komma vergessen?** | noch kein typischer Wert bekannt und neuer Stand > 10 × der letzte | nur ohne Vergleichswert, sonst greift der Sprung |
| **Rückgang** | kleiner als der letzte Stand — außer dazwischen wurde ein neues Gerät eingebaut | mit Link zum Zählertausch |
| **Zukunft** | Datum nach heute | Tippfehler im Jahr? |
| **Gleicher Tag** | für den Tag gibt es schon einen Stand | Knopf „Ersetzen": der vorhandene Stand wird aktualisiert statt ein zweiter angelegt |

Der **typische Tagesverbrauch** ist der Median der letzten bis zu zehn
Ableseintervalle desselben Geräts (mindestens zwei) — robust gegen einen
einzelnen Ausreißer. Das Backend liefert ihn in `readings-overview` als
`typical_per_day`; die Verbrauchsansicht rechnet ihn nach derselben Regel.
Die Hinweise erscheinen schon beim Tippen; die Rückfrage beim Speichern nennt
den Zähler im Titel. Wer in der Sammelerfassung ablehnt, behält die Eingabe;
die Karte zeigt „Nicht gespeichert – bitte prüfen", eine Meldung zählt die
zurückgestellten Karten.

### Nach dem Speichern: Ausreißer, Verdacht, Überlauf

Die Verbrauchsrechnung prüft unabhängig von der Erfassung (auch CSV-Import
und Home Assistant):

- **Eingeklemmte Ausreißer** — fällt der Stand zwischen zwei Ablesungen
  desselben Geräts, ist entweder der frühere eine Spitze oder der spätere eine
  Delle. Passt eine Deutung, fällt dieser Stand aus der Rechnung; passen
  beide, entscheidet der gleichmäßigere Tagesverbrauch. Bis v2.5.3 zählte die
  Rechnung das folgende Intervall ab dem falschen Stand voll: Ein einziger
  Wert 0 machte aus 190 kWh im Monat 50.270 kWh.
- **Verdacht** — ein fallender Wert aus Home Assistant wird gespeichert, aber
  markiert und bis zur Bestätigung übergangen.
- **Rückgang** — ein fallender Stand ohne bestimmbaren Ausreißer (meist ein
  nicht erfasster Zählertausch) wird gemeldet; das negative Intervall zählt
  nicht.
- **Überlauf** — ist am Gerät die Stellenzahl des Zählwerks gepflegt
  (Zähler bearbeiten → „Stellen des Zählwerks"), ist 99.998 → 12 ein
  Verbrauch von 14, kein Rückgang.

Die Verbrauchsansicht zeigt alle Fälle in einem Hinweis oberhalb der
Jahresauswahl, die Tabelle markiert die Stände („PRÜFEN", „UNPLAUSIBEL");
ein Verdacht lässt sich mit ✅ bestätigen. Technisch:
[API-Referenz → `warnings`](../technical/03-api-reference.md).

## Mobile First

Die Ansicht ist von Grund auf für iPhone-Hochformat gebaut:

- Karten füllen die volle Breite, eine pro Bildschirmzeile
- Eingabefelder mit min. 48 px Touch-Target-Höhe (Apple-HIG-konform)
- Enter bzw. „Weiter" springt ins nächste Zählerfeld, beim letzten auf
  „Alle speichern" (`enterkeyhint`, v2.12.0)
- Sticky-Save-Bar mit `env(safe-area-inset-bottom)` für den Home-
  Indicator-Bereich neuerer iPhones
- `inputmode="decimal"` öffnet die Zahlentastatur ohne Buchstaben

Auf Desktop und Tablet bleibt die Karte einspaltig und wird auf max. 720 px
Breite zentriert — hohe Lesbarkeit, kein endloses Scannen über die volle
Bildschirmbreite. (Bis v2.11 stand das Datum je Karte rechts neben dem
Zählerfeld.)

## Architektur

- **Backend:** ein einziger Aggregat-Endpunkt `GET /api/readings-overview`,
  der alle aktiven kumulativen Zähler plus jeweils letzte reale
  Ablesung in einem Roundtrip liefert. Beim Öffnen der Ansicht: ein
  HTTP-Call, danach reines clientseitiges Rendering.
- **Speichern:** wiederverwendet die bestehende Route
  `POST /api/utility/{u}/readings` — kein neues Schema, kein
  Batch-Endpunkt, keine Migration. Eine fehlerhafte Zeile betrifft
  ausschließlich diese eine Zeile.
- **Status:** das bestehende `is_estimated`-Flag im Reading-Schema
  trägt die Statusinformation. Kein neues Feld, keine Datenmodell-
  Änderung.
- **Scope-Gating:** Single-Source-of-Truth ist
  `Utilities::isCumulative()` im Backend, gespiegelt im Frontend.

## Was bewusst nicht in v1.6.0 ist

- **Foto-Aufnahme** — auf der Roadmap. Bringt Binary-Storage,
  Thumbnails, ggf. EXIF-Übernahme des Aufnahmedatums mit und wäre
  ein eigenständiger Major-Aufwand.
- **Offline-Modus / Zwischenspeicherung bei Verbindungsabbruch** —
  derzeit verliert die Karte ihre Eingaben, wenn der Save scheitert
  und der Browser neugeladen wird. Eine Implementierung mit
  IndexedDB ist in der Roadmap vorgesehen.
- **OCR / automatische Ziffernerkennung** — gehört thematisch zur
  Foto-Aufnahme; gemeinsam in einer späteren Iteration.
- **Sammel-Speichern als ein einziger atomarer Endpunkt** — das
  aktuelle sequenzielle Schreiben hat den Vorteil, dass Teilfehler
  präzise lokalisiert werden. Ein Batch-Endpunkt würde diesen
  Vorteil aufgeben; er kommt nur, wenn echte Performance-Messungen
  ihn rechtfertigen.

[← Glossar](09-glossar.md) · [Grundlagen](00-overview.md)
