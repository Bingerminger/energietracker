# Ladevorgänge aus evcc

**Deutsch** · [English](../en/anleitungen/evcc.md)

[← Kompendium-Index](../README.md)

[evcc](https://evcc.io) steuert die Wallbox: Es lädt mit Sonnenstrom oder in
günstigen Stunden und führt Buch über jeden Ladevorgang. Seit **v3.2.0**
übernimmt der Energietracker diese Ladevorgänge und rechnet nach, was dabei
herauskam — Menge, Anteil aus der Sonne und der Preis, den evcc ausgewiesen
hat, je Monat. Die Zählerstände der Wallbox kommen gleich mit.

> **Der Energietracker steuert nichts.** evcc (oder Home Assistant) steuert
> Wallbox und Speicher, der Energietracker rechnet nach. Wie beides
> zusammenspielt, zeigen die [Anwendungsfälle](anwendungsfaelle.md).

Zwei Wege führen die Ladevorgänge herein: die **CSV-Datei** aus evcc — geht
immer — oder der **Abruf** direkt bei evcc im Heimnetz.

---

## 1. Was du brauchst

- evcc mit mindestens einem Ladepunkt.
- Im Energietracker einen **Strom-Zähler mit der Rolle „Wallbox“** (§ 2).
- Die Stufe **„Erfahren“** oder höher: Erst dann zeigt die Ansicht der
  Wallbox die Karte „Ladevorgänge aus evcc“. Die Adresse für den Abruf steht
  unter Einstellungen → Experte (Stufe „Experte“).
- Mit [Benutzern im Haushalt](benutzer.md): Die Adresse von evcc trägt nur
  ein, wer die Installation verwaltet. Übernehmen dürfen alle.

---

## 2. Wallbox-Zähler anlegen

**Verbrauch → Strom → ⚙️ Zähler → „+ Neuer Zähler“**, wie beim
[Ladestrom-Nachweis](ladestrom-nachweis.md):

1. **Name**, etwa „Wallbox“.
2. **Rolle: „Wallbox“.** Erst mit dieser Rolle erscheint die Karte.
3. **Elternzähler:** der Haushaltszähler, wenn die Wallbox dahinter hängt.
   Dann zieht die App ihren Verbrauch dort ab, statt ihn doppelt zu zählen.
4. **Anfangsstand des Geräts:** Sollen die Zählerstände aus evcc kommen
   (§ 5, „vom Zähler der Wallbox“), trägst du den Stand so ein, wie der
   Zähler der Wallbox ihn zählt — sonst passt der erste Stand aus evcc nicht
   zum Anfang.

Ein Zähler, der Verbrauch je Zeitraum erfasst, nimmt keine Ladevorgänge an.

---

## 3. CSV aus evcc herunterladen und hochladen

**In evcc:**

1. Im Menü **„Ladevorgänge“** öffnen.
2. Den Zeitraum wählen — Monat, Jahr oder gesamt.
3. Am Knopf **„Download“** den **Pfeil daneben** antippen und **„CSV“**
   wählen. Der Knopf selbst lädt eine Excel-Datei (XLSX); die liest der
   Energietracker nicht.

Die Sprache der Datei ist egal: Der Energietracker erkennt die Kopfzeile in
Deutsch, Englisch, Französisch, Italienisch, Spanisch, Portugiesisch und
Niederländisch, mit Komma oder Semikolon als Trenner. Pflicht sind die
Spalten für Beginn und Energie; Ende, Ladepunkt, Fahrzeug, Zählerstände,
Sonnenanteil und Preis nimmt er mit, wenn sie da sind.

**Im Energietracker:**

1. **Verbrauch → Strom**, oben den Wallbox-Zähler wählen.
2. In der Karte **„Ladevorgänge aus evcc“** unter „Zählerstände“ wählen, woher
   die Stände kommen (§ 5).
3. **„CSV aus evcc wählen …“** und die Datei auswählen.
4. Die Vorschau lesen (§ 6) und **„Übernehmen“**.

---

## 4. Abruf im Heimnetz einrichten

Statt einer Datei holt der Energietracker die Ladevorgänge auf Knopfdruck
selbst bei evcc ab.

1. **Einstellungen → Experte → „evcc im Heimnetz“ → „Adresse von evcc“**:
   die Adresse, unter der du evcc im Browser öffnest, etwa
   `http://192.168.178.30:7070`. Port 7070 ist der Standard von evcc für
   Oberfläche und Schnittstelle.
2. Speichern. In der Karte „Ladevorgänge aus evcc“ erscheint der Knopf
   **„Von evcc abrufen“**.
3. Antippen, Vorschau lesen, „Übernehmen“.

Der Server des Energietrackers ruft dann `GET <Adresse>/api/sessions` ab. Ein
evcc-Passwort braucht es nicht: evcc gibt die Liste der Ladevorgänge ohne
Anmeldung heraus. Leer heißt aus; die CSV-Datei geht immer.

**Nur im eigenen Netz.** Wie bei der [Texterkennung](texterkennung.md) nimmt
die App nur Adressen im eigenen Netz an: Loopback, private Netze
(`10.x`, `172.16.x`–`172.31.x`, `192.168.x`), Link-local, `100.64.0.0/10`
und die entsprechenden IPv6-Bereiche. Der Name wird bei jedem Abruf
aufgelöst, jede Adresse, auf die er zeigt, muss lokal sein, verbunden wird mit
genau der geprüften Adresse, Weiterleitungen folgt die App nicht. Ein neuer
Weg ins Internet entsteht so nicht. Der Abruf wartet höchstens 30 Sekunden.

### Name oder IP-Adresse?

- **`evcc.local`** kommt über mDNS, nicht über den DNS des Routers. Der
  Browser am Mac oder Handy findet den Namen, ein Server oft nicht —
  besonders der **Energietracker im Docker-Container**. Dann endet der Abruf
  mit „evcc ist nicht erreichbar.“ Abhilfe: die **IP-Adresse** von evcc
  eintragen und sie im Router fest vergeben.
- **evcc als Container im selben Docker-Netz** wie der Energietracker: Der
  Name des Dienstes genügt, etwa `http://evcc:7070`. Läuft evcc mit
  `network_mode: host` (so empfiehlt evcc den Betrieb in Docker), gilt die
  IP-Adresse des Rechners.
- **evcc-Image für den Raspberry Pi:** Es zeigt die Oberfläche unter
  `https://evcc.local/` mit einem selbst signierten Zertifikat. Der Abruf
  prüft Zertifikate und lehnt es ab — trag die unverschlüsselte Adresse mit
  Port 7070 ein, etwa `http://192.168.178.30:7070`.

---

## 5. Zählerstände: welche Wahl?

Die Auswahl „Zählerstände“ in der Karte gilt für CSV und Abruf gleichermaßen:

| Wahl | Was passiert | Nimm sie, wenn … |
|---|---|---|
| **„vom Zähler der Wallbox (sonst aufsummiert)“** — Standard | Der Anfangsstand am Tag, an dem geladen wird, der Endstand am Tag danach — so, wie die App einen Stand liest: als Stand zu Beginn des Tages. Damit zählt jede Ladung zu ihrem Tag und Monat. Meldet die Wallbox keine Zählerstände, summiert die App wie in der nächsten Zeile. | der Zähler im Energietracker der Zähler in der Wallbox ist, den evcc ausliest (etwa ein MID-Zähler in der Wallbox). Meist die richtige Wahl. |
| **„aus der geladenen Energie aufsummieren“** | Ausgehend vom letzten Stand bis zum Tag des ersten Ladevorgangs — ohne Stand vom Anfangsstand des Geräts — zählt die App die geladene Energie auf und legt je Ladetag einen Stand am Tag danach an. | dein Zähler im Energietracker ein anderer ist als der, den die Wallbox meldet (etwa ein eigener Zwischenzähler), oder die Stände der Wallbox nicht zu deinen Ablesungen passen. Was die Wallbox im Stand-by verbraucht, steht in keinem Ladevorgang; der aufsummierte Stand läuft dem echten deshalb etwas hinterher. Ab und zu echt ablesen. |
| **„keine – nur die Ladevorgänge“** | Nur die Ladevorgänge; die Zählerstände bleiben unverändert. | die Stände schon anders kommen — von Hand, aus [Home Assistant](home-assistant.md) oder aus dem [Portal](daten-aus-portalen.md) — und du nur Sonnenanteil und Preis willst. Ebenso, wenn alle Ladepunkte einer Datei an einem Zähler landen sollen (§ 7). |

Stände aus evcc tragen die Notiz „evcc“. Ein vorhandener Stand am selben Tag
wird ersetzt; die Vorschau sagt, wie viele. Der Endstand eines Ladevorgangs von
heute kommt erst morgen dazu (er gehört auf den Tag danach) — beim nächsten
Abruf oder der nächsten Datei.

---

## 6. Vorschau und Wiederholen

Beide Wege zeigen erst eine **Vorschau** („Ladevorgänge übernehmen?“):

- wie viele Ladevorgänge von wann bis wann, zusammen wie viele kWh;
- wie viele Zählerstände dazukommen und woher (§ 5);
- wie viele vorhandene Stände an denselben Tagen ersetzt werden;
- ob ein Ladevorgang noch läuft;
- welcher Ladepunkt übernommen wird, wenn die Datei mehrere enthält (§ 7).

Erst **„Übernehmen“** speichert. Danach meldet die App „… Ladevorgänge
übernommen.“

**Wiederholen schadet nicht.** Derselbe Ladevorgang — gleicher Beginn,
gleicher Ladepunkt — wird ersetzt, nicht doppelt gezählt. Du kannst also
jeden Monat die ganze Datei oder den neuen Monat laden. Ein Ladevorgang, der
noch läuft (ohne Ende), wartet bis zum nächsten Mal. Zeilen ohne Beginn oder
ohne Energie überspringt die App. In der Karte zählt ein Ladevorgang zu dem
Tag, an dem er endet, in der Zeitzone der Installation.

---

## 7. Mehrere Ladepunkte

Die Datei aus evcc enthält alle Ladepunkte des Zeitraums. Zählerstände
gehören aber zu genau einer Wallbox — deshalb fragt die App nach:

- **Je Ladepunkt ein eigener Zähler:** Mit „vom Zähler der Wallbox“ oder
  „aufsummieren“ fragt die App „Welcher Ladepunkt gehört zu diesem Zähler?“.
  Wähle den Ladepunkt dieser Wallbox; übernommen werden nur seine
  Ladevorgänge und Stände. Für die zweite Wallbox dieselbe Datei in deren
  Ansicht noch einmal laden. Über die Schnittstelle heißt das `loadpoint`
  (§ 11).
- **Eine Wallbox im Energietracker für alle Ladepunkte:** „keine – nur die
  Ladevorgänge“ wählen. Dann landen alle Ladevorgänge an diesem Zähler, die
  Zählerstände bleiben unverändert; die Vorschau nennt die Ladepunkte.

---

## 8. Was die Karte zeigt

Die Karte **„Ladevorgänge aus evcc {Jahr}“** steht in der Ansicht des
Wallbox-Zählers, für das Jahr, das oben gewählt ist:

- eine Zeile wie „12 Ladevorgänge, 180 kWh, davon 41 % aus der Sonne · laut
  evcc 38,50 €“ (Beispiel);
- je Monat eine Zeile: **Monat**, **Vorgänge**, **kWh**, **Sonne** (der
  Sonnenanteil, nach kWh gewichtet) und **Preis (evcc)**.

Der **Preis** ist der, den evcc mit seinen eigenen Tarifeinstellungen
ausgerechnet hat — nicht der Vertragspreis im Energietracker. Er steht da zum
Vergleich; gerechnet wird mit ihm nichts.

---

## 9. Zusammenspiel mit dem Ladestrom-Nachweis

Die Stände aus evcc sind gewöhnliche Zählerstände der Wallbox. Damit rechnet
auch die Karte „Ladestrom-Nachweis (Dienstwagen)“ (Stufe „Experte“, unter der
evcc-Karte): Vertragspreis oder Strompreispauschale je Monat, als CSV und PDF
([Ladestrom für den Dienstwagen nachweisen](ladestrom-nachweis.md)). Den
Preis aus evcc verwendet der Nachweis nicht.

Die Stände aus evcc liegen um jeden Ladetag herum (Anfangsstand am Tag,
Endstand am Tag danach). Der Nachweis bildet die Monatsmenge wie immer aus den
Ständen; jede Ladung landet so in ihrem Monat. Nur was die Wallbox zwischen
den Ladetagen im Stand-by verbraucht, verteilt die App nach Tagen.

---

## 10. Wenn etwas nicht klappt

| Meldung | Ursache und Lösung |
|---|---|
| „evcc ist nicht erreichbar.“ | evcc läuft nicht, der Port stimmt nicht, eine Firewall sperrt, der Name lässt sich nicht auflösen (`evcc.local` im Docker-Container, [§ 4](#name-oder-ip-adresse)), oder die Adresse beginnt mit `https://` und evcc hat ein selbst signiertes Zertifikat. Erste Probe: die Adresse mit `/api/sessions` dahinter im Browser öffnen — kommt dort eine Liste, liegt es am Weg vom Server zu evcc (Name, Docker, Firewall). Ohne die PHP-Erweiterung curl geht der Abruf ebenfalls nicht; im Docker-Image ist sie enthalten. |
| „evcc muss im eigenen Netz laufen – „…“ ist keine Adresse im Heimnetz.“ | Die Adresse — oder eine, auf die der Name zeigt — liegt nicht im eigenen Netz. Die IP-Adresse von evcc im Heimnetz eintragen. |
| „evcc hat keine Liste von Ladevorgängen geliefert.“ | Unter der Adresse antwortet etwas anderes als evcc, etwa auf einem falschen Port. |
| „Keine abgeschlossenen Ladevorgänge gefunden.“ | Die Datei ist leer, alle Ladevorgänge laufen noch, oder kein Ladevorgang passt zum gewählten Ladepunkt (`loadpoint`). |
| „Mehrere Ladepunkte (…): Zählerstände gehören zu einer Wallbox – …“ | Über die Schnittstelle ohne `loadpoint`, aber mit Zählerständen. Den Ladepunkt angeben oder `counters: "none"` (§ 7). |
| „Das sieht nicht nach dem CSV-Export aus evcc aus: Es fehlen die Spalten für Beginn und Energie.“ | Eine andere Datei, oder in evcc wurde XLSX statt CSV heruntergeladen (§ 3). |
| „Keine Adresse von evcc eingetragen (Einstellungen → Experte).“ | Abruf über die Schnittstelle ohne eingetragene Adresse. |
| „Dieser Zähler erfasst Verbrauch je Zeitraum – …“ | Der gewählte Zähler erfasst Zeiträume statt Stände. Einen Zähler mit Zählerständen nehmen. |
| „Das darf nur, wer die Installation verwaltet.“ | Ein Mitglied wollte die Adresse von evcc ändern ([Benutzer](benutzer.md)). |
| Die Karte fehlt | Der Zähler hat nicht die Rolle „Wallbox“, oder die Stufe ist „Einsteiger“. |

---

## 11. Über die API

```text
POST /api/utility/strom/meters/{id}/import-evcc?dry_run=1   {csv, counters?, loadpoint?}   Vorschau
POST /api/utility/strom/meters/{id}/import-evcc             {csv, counters?, loadpoint?}   übernehmen
POST /api/utility/strom/meters/{id}/sync-evcc[?dry_run=1]   {counters?, loadpoint?}        Abruf im Heimnetz
GET  /api/ev-sessions?meter_id=m_wallbox&year=2026          → {sessions, monthly}
```

`counters` ist `auto` (Standard), `energy` oder `none` (§ 5). Antwortet evcc
beim Abruf nicht oder unbrauchbar, kommt `502`. Die Ladevorgänge stehen in
`data/ev_sessions.json` und gehören zum Backup. Felder und Fehlercodes:
[API-Referenz](../referenz/api.md).

> evcc-Begriffe geprüft am 09.10.2026 an der
> [evcc-Dokumentation](https://docs.evcc.io/de/features/sessions) und am
> Quelltext von [evcc](https://github.com/evcc-io/evcc).

---

[← Kompendium-Index](../README.md)
