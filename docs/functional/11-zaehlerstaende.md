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
- **Datum** — Default heute, pro Zeile überschreibbar
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
   „2 gespeichert, 1 fehlgeschlagen").

Eine fehlerhafte Karte blockiert die anderen **nicht** — robust gegen
Teilfehler. Nach erfolgreichem Speichern wird der „letzter Stand" in
der Karte aktualisiert, damit ein zweiter Klick gegen die neue
Baseline validiert.

## Validierung

- **Numerische Eingabe** — nur Zahlen, Komma oder Punkt als
  Dezimaltrenner.
- **Rückwärts-Zählerstand** — wenn der neue Wert kleiner als der
  letzte bekannte ist, zeigt die Karte einen orangefarbenen Hinweis
  („bei Zählertausch o. Ä. ist das ok"). Es wird **nicht hart
  blockiert** — Zählertausch ist ein realer Fall, und das Backend
  prüft die Geräte-Historie eigenständig.
- **Leere Eingabe** — die Karte wird beim Speichern still übersprungen,
  nicht als Fehler markiert.

## Mobile First

Die Ansicht ist von Grund auf für iPhone-Hochformat gebaut:

- Karten füllen die volle Breite, eine pro Bildschirmzeile
- Eingabefelder mit min. 48 px Touch-Target-Höhe (Apple-HIG-konform)
- Datum + Zähler einspaltig untereinander unter 600 px Breite
- Sticky-Save-Bar mit `env(safe-area-inset-bottom)` für den Home-
  Indicator-Bereich neuerer iPhones
- `inputmode="decimal"` öffnet die Zahlentastatur ohne Buchstaben

Auf Desktop und Tablet wird das Layout in zwei Spalten (Zähler links,
Datum rechts) erweitert und auf max. 720 px Breite zentriert — hohe
Lesbarkeit, kein endloses Scannen über die volle Bildschirmbreite.

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
