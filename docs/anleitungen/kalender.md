# Fristen und Termine im Kalender

**Deutsch** · [English](../en/anleitungen/kalender.md)

[← Kompendium-Index](../README.md)

Seit v3.1.0 gibt der Energietracker seine Fristen und Termine als
Kalender-Abo aus: Kündigungsstichtage, Vertragsenden, Wartungstermine, fällige
Ablesungen. Dein Kalender holt sie selbst ab — auf dem Mac, dem iPhone, in
Thunderbird oder Google Kalender. Ein Dienst dazwischen ist nicht nötig.

---

## Was drinsteht

Dieselben Einträge, die das Dashboard unter „Zu tun“ und Home Assistant sehen,
als ganztägige Termine der nächsten 365 Tage, dazu alles schon Fällige:

| Eintrag im Kalender | Wann |
|---|---|
| Termin aus „Termine & Wartung“ (Rauchmelder, Eichfrist …) | am Tag der nächsten Fälligkeit |
| „Strom: Zählerstand ablesen (…)“ | wenn die letzte Ablesung länger her ist als unter Einstellungen → Allgemein → „Warnung nach“ eingestellt (Standard 45 Tage); ist das schon so, steht der Eintrag heute |
| „…: letzter Tag für die Kündigung (Anbieter)“ | am Kündigungsstichtag eines laufenden oder künftigen Vertrags — dafür braucht der Vertrag eine Kündigungsfrist |
| „…: Vertragsende (Anbieter)“ | am festen Vertragsende; nicht bei Verträgen, die sich verlängern |
| „…: Preisgarantie endet (Anbieter)“ | am letzten Tag der Preisgarantie |
| „…: Preiserhöhung (Anbieter) — Sonderkündigung prüfen“ | am Tag einer eingetragenen Preiserhöhung; seit v3.1.0 auch unter „Zu tun“, solange die Empfehlung „Preiserhöhung“ dazu steht (in Deutschland) |
| „Heizöl: Lieferung planen (…)“ | heute, solange der Bestand niedrig ist (Heizöl, Pellets) |
| „Nebenkostenabrechnung bis zum … fällig: …“ *(v3.1.0)* | nur bei „Ich wohne: zur Miete“ — Ende des letzten abgelaufenen Abrechnungszeitraums plus 12 Monate (§ 556 Abs. 3 BGB), solange für diesen Zeitraum keine Abrechnung erfasst ist |
| „Einwände gegen die Nebenkostenabrechnung bis heute: …“ *(v3.1.0)* | nur bei „zur Miete“ — Zugang der Abrechnung („Erhalten am“) plus 12 Monate; unter „Zu tun“ ab 30 Tagen vorher |
| „CO₂-Kosten {Jahr}: Vermieteranteil von … bis heute einfordern“ *(v3.1.0)* | nur bei „zur Miete“ in Deutschland mit eigener Gastherme und einem Betrag über 0 — Rechnungsdatum der erfassten Gasrechnung plus 12 Monate; unter „Zu tun“ ab 30 Tagen vorher |

Bei Ablesungen von Zählern mit **Verbrauch je Zeitraum** (v3.1.0) zählt das
Ende des letzten Zeitraums statt der letzten Ablesung. Die beiden Fristen der
Nebenkostenabrechnung rechnet die App aus dem Mietverhältnis aus — wie, steht
in [Als Mieter](mieter.md#8-fristen-im-kalender); die Frist für die
CO₂-Erstattung aus der Gasrechnung
([CO₂-Kosten teilen](co2-aufteilung.md#7-frist-im-kalender)). Ob eine Frist im
Einzelfall gilt, beurteilt die App nicht.

**Erledigt** rückt einen Termin auf seine nächste Fälligkeit; im Kalender
wandert derselbe Eintrag mit, ein Duplikat entsteht nicht. Die Texte stehen in
der **Standardsprache der Installation** (Einstellungen → Allgemein), denn
Kalender schicken keine Sprache mit.

---

## Abo einrichten

In der App: **Hinweise → Termine & Wartung → „Im Kalender abonnieren“**. Das
Fenster zeigt zwei Adressen mit je einem Knopf „Adresse kopieren“:

- `webcal://…` öffnet auf Mac und iPhone direkt die Kalender-App.
- `https://…` (bzw. `http://…`) für alle anderen.

Die Adresse hat die Form `http://DEINE-IP:8080/api.php/api/calendar.ics`, mit
eingeschalteter Anmeldung dazu `?token=etk_…` (s. [Zugang](#zugang-und-schlüssel)).

### Apple Kalender auf dem Mac

1. **Ablage → Neues Kalenderabonnement …**
2. Die Adresse einfügen und **Abonnieren** wählen.
3. Bei **Ort „Auf meinem Mac“** wählen, nicht iCloud. Dann ruft der Mac selbst
   ab und erreicht die App im Heimnetz. Mit iCloud fragen Apples Server an —
   und die erreichen dein Heimnetz nicht.
4. Bei „Automatisch aktualisieren“ genügt „Jeden Tag“ oder kürzer. Damit die
   Vorwarnung erscheint, die Hinweise (Alerts) nicht entfernen lassen.

### iPhone und iPad

- Am einfachsten den `webcal://`-Link auf dem iPhone öffnen (etwa das Fenster in
  der App auf dem iPhone aufrufen und den Link antippen) und **Abonnieren**
  bestätigen.
- Oder von Hand: **Einstellungen → Kalender → Accounts → Account hinzufügen →
  Andere → Kalenderabo hinzufügen** (in neueren iOS-Versionen unter
  Einstellungen → Apps → Kalender) und die Adresse eintragen.
- Fragt das iPhone nach dem Ort, **„Auf meinem iPhone“** statt iCloud wählen —
  aus demselben Grund wie am Mac.

Unterwegs, außerhalb des Heimnetzes, erreicht das Gerät die App nicht; der
Kalender zeigt dann den letzten Stand und holt nach, sobald du zu Hause bist.

### Thunderbird

1. Im Kalender **Neuer Kalender … → Im Netzwerk**.
2. Bei „Adresse“ die `https://`-Fassung einfügen, den Benutzernamen leer lassen —
   ein Schlüssel steht schon in der Adresse.
3. **Kalender suchen**, dann **Abonnieren**. Thunderbird erkennt das Format
   (iCalendar) selbst.

In den Eigenschaften des Kalenders lässt sich einstellen, wie oft er neu lädt
und ob er Erinnerungen zeigt.

### Google Kalender

Google ruft Abos von **Google-Servern** ab, nicht von deinem Gerät. Das klappt
nur, wenn die App **aus dem Internet erreichbar** ist — in einem reinen
Heimnetz-Betrieb nicht.

Ist sie erreichbar (Reverse-Proxy mit HTTPS), dann **mit eingeschalteter
Anmeldung** und Kalender-Schlüssel: In Google Kalender bei „Weitere Kalender“
**+ → Per URL** wählen, die `https://`-Adresse mit `?token=…` einfügen und
**Kalender hinzufügen**. Google bestimmt selbst, wie oft es neu lädt, oft
seltener als alle 12 Stunden, und übernimmt die Vorwarnungen aus Abos nicht
verlässlich. Bevor du die App dafür ins Internet stellst: Checkliste in
[Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).

---

## Aktualisierung und Vorwarnung

- **Alle 12 Stunden.** Der Kalender bittet um eine Aktualisierung alle
  12 Stunden. Apple Kalender und Thunderbird lassen dich den Takt selbst
  wählen. Änderungen in der App erscheinen mit dem nächsten Abruf.
- **Vorwarnung.** Termine, Kündigungsstichtage, Vertragsenden, Preisgarantien
  und Preiserhöhungen tragen eine Erinnerung so viele Tage vorher, wie unter
  Einstellungen → Allgemein → Erinnerungen → „Termin „bald fällig“ ab“ steht
  (Standard 14). `0` schaltet sie ab. Fällige Ablesungen und Lieferungen haben
  keine Vorwarnung — sie stehen ohnehin am heutigen Tag. Die Fristen der
  Nebenkostenabrechnung und der CO₂-Erstattung (v3.1.0) haben ebenfalls keine;
  die Einwandfrist und die CO₂-Frist erscheinen dafür 30 Tage vorher unter
  „Zu tun“.

---

## Zugang und Schlüssel

- **Ohne Anmeldung** braucht die Adresse keinen Schlüssel. Wer die App im
  Heimnetz erreicht, kann auch den Kalender lesen — wie die App selbst.
- **Mit eingeschalteter Anmeldung** braucht das Abo einen eigenen Schlüssel.
  „Im Kalender abonnieren“ fragt seit v3.1.0 erst nach und nennt dabei, wie
  viele Kalender-Schlüssel es schon gibt; erst **„Link erzeugen“** legt den
  Schlüssel an und hängt ihn an die Adresse. Wer abbricht, bekommt keinen. Der
  Schlüssel hat die Berechtigung **„nur Kalender-Abo“**: Er gilt nur für den
  Kalender, nur in der Adresse und öffnet nichts sonst. Ein Lese- oder
  Verwaltungs-Schlüssel in der Adresse wird abgewiesen — so landet nie ein
  Schlüssel mit mehr Rechten in einer Kalender-Synchronisation.
- Die Adresse mit Schlüssel lässt sich später nicht noch einmal anzeigen —
  gleich kopieren. Jedes weitere „Link erzeugen“ legt einen weiteren Schlüssel
  an. **Widerrufen** unter **Einstellungen → Zugriff → API-Schlüssel für
  Skripte → Widerrufen**; danach bekommt das Abo keine neuen Einträge mehr. Für
  ein neues Abo das Fenster erneut öffnen und den Link erzeugen.
- Die Adresse mit Schlüssel verrät Fristen und Anbieternamen. Behandle sie wie
  ein Passwort und gib sie nicht weiter.

Technische Einzelheiten (Form der Ereignisse, UIDs, Zugriffsregeln):
[API-Referenz](../referenz/api.md#get-apicalendarics-v310).

---

## Wenn etwas nicht klappt

| Symptom | Ursache und Lösung |
|---|---|
| Der Kalender bleibt leer oder alt | Das abrufende Gerät erreicht die App nicht: Abo in iCloud statt „Auf meinem Mac/iPhone“, unterwegs außerhalb des Heimnetzes, oder Google ohne Erreichbarkeit aus dem Internet. |
| Fehler „nicht autorisiert“ (401) | Der Kalender-Schlüssel wurde widerrufen, oder in der Adresse steht ein Lese- bzw. Verwaltungs-Schlüssel. Über „Im Kalender abonnieren“ eine neue Adresse holen. |
| Ein Kündigungsstichtag fehlt | Am Vertrag ist keine Kündigungsfrist gepflegt, oder der Stichtag liegt mehr als 365 Tage voraus. |
| Keine Vorwarnung | Vorwarnung auf 0 gestellt, im Abo die Hinweise entfernt, oder Google Kalender. |
| Die Fristen der Nebenkostenabrechnung fehlen | Unter Einstellungen → Haushalt & Gebäude steht „Ich wohne: im Eigentum“, es gibt kein Mietverhältnis, oder bei der Abrechnung fehlt „Erhalten am“ (dann gibt es keine Einwandfrist). Ist die Abrechnung für den letzten Zeitraum erfasst, entfällt die Erinnerung an sie. |
| Die Frist für die CO₂-Erstattung fehlt | Keine Gasrechnung des Jahres unter Rechnung prüfen → „Laut Rechnung“ erfasst, das Land ist nicht Deutschland, die Heizkostenabrechnung trägt CO₂-Angaben (dann gilt Zentralheizung, und der Vermieter rechnet), oder der Betrag ist 0 (Stufe 1 oder Kürzung auf 0). |

---

[← Kompendium-Index](../README.md) · [Home Assistant anbinden](home-assistant.md)
