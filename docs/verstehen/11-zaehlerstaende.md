# Zählerstand-Erfassung (F1004)

**Deutsch** · [English](../en/verstehen/11-zaehlerstaende.md)

> Gilt für **Gas, Strom, Wasser, Fernwärme, PV** und seit v3.1.0
> **Heizwärme** — die Verbrauchsarten mit kumulativem Zählerstand-Modell.
> **Heizöl** und **Pellets** sind ausgenommen: sie erfassen den Verbrauch über
> Lieferungen, nicht über Ablesungen — ein eigenes Datenmodell mit eigener UI
> (siehe [Heizöl](05-heizoel.md), [Pellets](06-pellets.md)). Zähler mit
> **Verbrauch je Zeitraum** (v3.1.0) stehen ebenfalls hier, mit einer eigenen
> Karte ([unten](#zähler-mit-verbrauch-je-zeitraum-v310)).

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
- **Foto** (v3.1.0) — „📷 Foto“ nimmt ein Bild des Zählwerks als Beleg mit
  (am Handy öffnet sich die Kamera). Der Browser verkleinert es auf höchstens
  1600 Pixel und kodiert es neu als JPEG; EXIF-Daten samt GPS-Ort fallen dabei
  weg. Ist ein Texterkennungsdienst im Heimnetz eingetragen, schlägt der Server
  den Stand vor: „Erkannt: … – Übernehmen“
  ([Texterkennung im Heimnetz](../anleitungen/texterkennung.md)).

## Zähler mit Verbrauch je Zeitraum *(v3.1.0)*

Hat ein Zähler die Erfassung „Verbrauch je Zeitraum“ (Zählerdialog →
„Erfassung“), trägst du keinen Stand ein, sondern den Verbrauch eines Monats —
etwa aus der monatlichen Verbrauchsinfo des Messdienstes. Seine Karte zeigt
unter dem Namen „Verbrauch je Zeitraum“ und enthält:

- **Letzter Zeitraum** — von, bis und Verbrauch des jüngsten Eintrags,
- **Monat** — vorbelegt mit dem Monat nach dem letzten Zeitraum, ohne
  Zeitraum mit dem Vormonat,
- **Verbrauch** in der Verbrauchseinheit der Art (kWh, bei Wasser m³),
- aufklappbar **„Vergleichswerte laut Verbrauchsinfo“** — Vormonat,
  Vorjahresmonat, Durchschnittsnutzer; sie werden gespeichert und angezeigt,
  aber nicht gerechnet,
- **Geschätzt** und **Notiz** wie bei einem Stand.

Datum oben, Foto, Texterkennung und die Plausibilitätsrückfragen gelten für
diese Karte nicht. Gespeichert wird mit den anderen Karten über „Alle
speichern“ (`POST /api/utility/{u}/periods` mit `month`); „Rückgängig“ und die
Offline-Warteschlange gelten auch hier. Ein Monat, der sich mit einem
vorhandenen Zeitraum überschneidet, wird abgewiesen — die Meldung steht unter
dem Feld. Wie die App einen Zeitraum auf die Monate verteilt:
[Heizwärme → Verbrauch je Zeitraum](15-waerme.md#2-verbrauch-je-zeitraum).

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

**Ohne Verbindung (v3.1.0):** Scheitert eine Karte an fehlender Verbindung
oder am Zeitlimit, landet der Stand samt Foto in der Offline-Warteschlange
statt verloren zu gehen. Die Karte zeigt ⏳ „Wartet auf Verbindung“, oben steht
die Liste „Noch nicht gespeichert“, und die App sendet von selbst nach, sobald
der Server wieder erreichbar ist. Konflikte am selben Tag entscheidest du
(„Ersetzen“ oder „Vorhandenen behalten“). Einzelheiten und Grenzen:
[Auf dem Handy nutzen](../einstieg/handy.md#die-warteschlange-noch-nicht-gespeichert).

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
[API-Referenz → `warnings`](../referenz/api.md).

## Viele Werte auf einmal: Zeitreihen aus Portalen *(v3.1.0)*

Die Erfassung ist für einzelne Stände gebaut. Liefert ein Portal viele Werte
auf einmal — Viertelstunden vom Netzbetreiber, Tageswerte vom Wechselrichter,
die Wärmemenge der Wärmepumpe —, liest sie der Knopf **„Zeitreihe
importieren“** unter Verbrauch → *Verbrauchsart* → ⚙️ Zähler ein:

- Eine **Spaltenzuordnung** sagt, wo Datum, Uhrzeit und Wert stehen, ob die
  Werte Zählerstände oder Verbrauch je Intervall sind, in welcher Einheit
  (kWh, Wh, MWh) und ob der Zeitstempel Beginn oder Ende des Intervalls meint.
- Die App verdichtet zu **Tageswerten**: bei Zählerständen der letzte Stand
  des Tages als Ablesung, bei Verbrauchswerten die Summe des Tages — als
  Zeitraum (Zähler mit Verbrauch je Zeitraum) oder als Stand, aufsummiert ab
  einem Anfangsstand.
- Zeitumstellung und Endstempel um 0:00 zählen richtig; eine Vorschau zeigt
  Tage, Zeitraum und Summe, bevor etwas geschrieben wird. Die Zuordnung merkt
  sich der Browser je Zähler.

Danach laufen die Plausibilitätsprüfungen nach dem Speichern wie bei jedem
Stand (Ausreißer, Rückgang). Schritt für Schritt:
[Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md).

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
  HTTP-Call, danach reines clientseitiges Rendering. Seit v3.1.0 trägt jede
  Zeile additiv `capture`, `last_period` und `role`; an `capture` entscheidet
  die Ansicht, welche Karte sie zeigt.
- **Speichern:** wiederverwendet die bestehende Route
  `POST /api/utility/{u}/readings` — kein Batch-Endpunkt. Eine fehlerhafte
  Zeile betrifft ausschließlich diese eine Zeile.
- **Kein Doppel beim Nachsenden (v3.1.0):** Jede Erfassung schickt eine
  `client_ref` mit. Kommt dieselbe Kennung für denselben Zähler ein zweites Mal
  an — die Antwort ging unterwegs verloren, die Warteschlange sendet nach —,
  liefert der Server den vorhandenen Stand mit `200` und `duplicate: true`,
  statt einen zweiten anzulegen.
- **Warteschlange (v3.1.0):** `public/js/lib/outbox.js`, gespeichert in
  IndexedDB (`et-outbox`) des Browsers.
- **Foto (v3.1.0):** `POST /api/attachments?kind=reading_photo` legt den Beleg
  an, das Feld `attachment_id` am Stand verknüpft ihn; die Texterkennung läuft
  über `POST /api/ocr/reading`
  ([API-Referenz → Belege](../referenz/api.md#belege-und-texterkennung-v310)).
- **Status:** das bestehende `is_estimated`-Flag im Reading-Schema
  trägt die Statusinformation. `client_ref` und `attachment_id` sind
  additive Felder; vorhandene Stände bleiben, wie sie sind.
- **Scope-Gating:** Single-Source-of-Truth ist
  `Utilities::isCumulative()` im Backend, gespiegelt im Frontend.

## Was bewusst nicht dabei ist

Foto als Beleg, Offline-Warteschlange und Texterkennung gibt es seit v3.1.0
(oben). Bewusst nicht:

- **Texterkennung über einen Cloud-Dienst** — Zählerfotos verlassen das
  Heimnetz nicht. Als Texterkennungsdienst nimmt die App nur Adressen im
  eigenen Netz an; einen Schalter, der das aufhebt, gibt es nicht
  ([Texterkennung im Heimnetz](../anleitungen/texterkennung.md)). Ohne eigenen
  Dienst genügt am iPhone Live Text („Text scannen“ im Feld).
- **Speichern ohne Bestätigung** — die Texterkennung schlägt nur vor. Erst
  „Übernehmen“ setzt den Wert ins Feld, gespeichert wird wie jeder andere Stand,
  mit Plausibilitätsprüfung.
- **Eigene Zählerauslese** (optischer Lesekopf, Smart-Meter-Schnittstelle) —
  das übernimmt Home Assistant und sendet die Stände
  ([Home Assistant anbinden](../anleitungen/home-assistant.md)). Dateien aus
  Portalen liest seit v3.1.0 der Zeitreihen-Import (oben); eine Verbindung zu
  den Portalen selbst baut die App nicht auf.
- **Sammel-Speichern als ein einziger atomarer Endpunkt** — das
  aktuelle sequenzielle Schreiben hat den Vorteil, dass Teilfehler
  präzise lokalisiert werden. Ein Batch-Endpunkt würde diesen
  Vorteil aufgeben; er kommt nur, wenn echte Performance-Messungen
  ihn rechtfertigen.

[← Glossar](09-glossar.md) · [Grundlagen](00-overview.md)
