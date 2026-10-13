# Hinweise erledigen und der Verlauf

**Deutsch** · [English](../en/anleitungen/hinweise-erledigen.md)

[← Kompendium-Index](../README.md)

Seit **v3.4.0** lässt sich ein Hinweis als erledigt kennzeichnen — von Hand
oder von selbst, sobald die Daten es zeigen. Ein Verlauf hält fest, **was**
erledigt wurde, **wann** und **von wem**: Familie, Mitbewohner, Hausmeister.
Rückgängig streicht einen Eintrag durch, statt ihn zu löschen.

---

## 1. Was sich von selbst erledigt

Die Wahrheit kommt aus den Daten. Was hier steht, gilt auch nach einem
Backup-Import, in der Demo und bei Ständen aus Home Assistant — ohne dass
jemand etwas abhakt.

| Du erfasst … | … dann ist erledigt |
|---|---|
| einen **Folgevertrag ohne Lücke** — derselbe Zähler oder dieselbe Zählergruppe, kein Angebot (Schattenvertrag), Beginn spätestens am Tag nach dem Ende | Kündigungsstichtag, Vertragsende, Ende der Preisgarantie und Preiserhöhung des bisherigen Vertrags. Die Empfehlung „Kündigung bis …“, der Hinweis in der Auswertung und „Kündigen bis …“ unter Verträge & Abschläge entfallen; dort steht stattdessen „Folgevertrag … ab …“ |
| eine **Ablesung**, wenn sie fällig war (letzte Ablesung länger her als eingestellt) | „Zählerstand ablesen“ |
| eine **Lieferung** Heizöl oder Pellets, wenn der Bestand vorher niedrig war | „Lieferung planen“ |
| eine **Nebenkostenabrechnung** für den abgelaufenen Zeitraum (zur Miete) | „Nebenkostenabrechnung fällig“ |

**Folgevertrag mit Lücke.** Beginnt der neue Vertrag später, bleibt die
Erinnerung — sie nennt die Lücke: „Der Folgevertrag … beginnt erst am … –
dazwischen 1 Tag ohne Vertrag.“

**Weiterlaufende und unbefristete Verträge** sind jederzeit kündbar. Bis v3.3
stand für sie jeden Tag ein neuer „letzter Tag für die Kündigung“ im Kalender
— das ist vorbei: Sie haben keinen datierten Kündigungsstichtag mehr, weder in
„Zu tun“ noch im Kalender oder in Home Assistant. Die Wechselplanung („Wechsel
prüfen“) rechnet weiter mit „kündbar ab heute“.

Eine Ablesung, die täglich aus Home Assistant kommt, trägt sich nicht jeden Tag
ein — nur, wenn der Hinweis offen war.

---

## 2. Von Hand abhaken

**Wo:** auf der **Übersicht unter „Zu tun“** (Haken ✓ rechts neben der Zeile)
und unter **Hinweise → Termine & Wartung** (✓ in der Zeile).

Ein kleiner Dialog fragt:

- **Erledigt am** — heute vorbelegt; ein früherer Tag geht, ein späterer
  nicht.
- **Notiz** (optional) — etwa „per Einschreiben gekündigt“.
- **Beleg** (optional) — Foto oder PDF, etwa die Kündigungsbestätigung.
- Beim Kündigungsstichtag dazu **„Gekündigt – der Vertrag verlängert sich
  nicht“**: Das setzt am Vertrag „verlängert sich ohne Kündigung“ auf nein.

Danach verschwindet die Zeile; der Hinweis unten bietet **Rückgängig**.

| Hinweis | abhaken? |
|---|---|
| Termin (Wartung, Eichfrist …) | ja — der Termin rückt wie bisher auf die nächste Fälligkeit |
| Kündigungsstichtag, Vertragsende, Preiserhöhung | ja |
| Lieferung planen (Heizöl, Pellets) | ja — etwa „bestellt“; erledigt bis zur nächsten Lieferung |
| Einwände gegen die Nebenkostenabrechnung, CO₂-Kosten einfordern | ja |
| Zählerstand ablesen | nein — das erledigt eine Ablesung |
| Nebenkostenabrechnung fällig | nein — das erledigt die erfasste Abrechnung |

**Ein Haken gilt für dieses eine Vorkommen.** Beim Vertrag ist das sein Ende:
Ändert sich das Vertragsende, ist der Hinweis wieder offen — gewollt, denn es
ist eine neue Frist. Beim Tank zählt die letzte Lieferung, beim Termin seine
Fälligkeit, bei den Fristen der Miete ihr Datum.

**Empfehlungen ausblenden** gehört dazu: Auch „Ausblenden“ steht im Verlauf.
Eine Empfehlung mit Frist (Kündigung, Vertragsende, Preiserhöhung) bleibt
mindestens bis zu diesem Tag ausgeblendet — der Knopf sagt dann „Bis …
ausblenden“. Bis v3.3 kam „Kündigung bis 30.11.“ schon am 11.11. zurück.

---

## 3. Der Verlauf: Hinweise → Erledigt

Der Tab **„Erledigt“** unter Hinweise (ab Stufe „Erfahren“) zeigt alles, was
erledigt wurde, nach Monaten, das Neueste oben:

- **Filter:** Person (nur, wenn Personen im Verlauf stehen), Zeitraum (letzte 3
  oder 12 Monate, dieses oder letztes Jahr) und Bereich (Termine, Verträge,
  Ablesungen, Tank und Lager, Mietverhältnis, Empfehlungen).
- **Automatische Einträge** tragen das Kennzeichen „automatisch“ und ihren
  Grund — „Folgevertrag Neuwerk ab 01.01.2027“, „Zählerstand vom 12.10.2026
  erfasst“.
- **Rückgängig** streicht den Eintrag durch und nennt, wann und von wem. Bei
  einem Termin stellt es Fälligkeit und „Zuletzt erledigt“ zurück, bei der
  Kündigung „verlängert sich ohne Kündigung“, bei einer Empfehlung blendet es
  sie wieder ein — solange niemand das inzwischen anders geändert hat.
- **Notiz** (✎) lässt sich nachtragen und ändern.

**Am Objekt:** Unter Kosten & Verträge → Verträge & Abschläge trägt ein
Vertrag mit Einträgen den Knopf **„Verlauf (n)“**. Unter Termine & Wartung
steht an jedem Termin „Zuletzt erledigt am … von …“.

---

## 4. Wer im Verlauf steht

| Wie die App benutzt wird | im Verlauf |
|---|---|
| ohne Anmeldung | niemand — nur Tag und Tat |
| mit Anmeldung, als Person | der Name der Person |
| mit einem API-Schlüssel | „API-Schlüssel …“ mit seinem Namen |
| Home Assistant (Ingest), evcc | „Home Assistant“ bzw. „evcc“ |
| aus den Daten erkannt, ohne handelnde Person | „automatisch“ mit Grund |

Personen gibt es nur mit eingeschalteter Anmeldung
([Benutzer im Haushalt](benutzer.md)). Rechte:

| | Verwaltung | Mitglied | Lese-Schlüssel |
|---|---|---|---|
| abhaken, Rückgängig, Notiz | ✓ | ✓ | — |
| Verlauf ansehen | ✓ | ✓ | ✓ |
| Verlauf leeren, Aufbewahrung einstellen | ✓ | — | — |

---

## 5. Aufbewahrung, Leeren, Datenschutz

**Aufbewahrung:** 60 Monate (fünf Jahre), einstellbar unter Hinweise →
Erledigt → **„Verlauf verwalten“** (nur Verwaltung, Einstellung
`activity_keep_months`, `0` = unbegrenzt). Ältere Einträge fallen beim
nächsten Eintrag heraus.

**Leeren:** im selben Fenster — alle Einträge, die vor einem Tag erledigt
wurden, oder ohne Datum alle. Gelöscht ist gelöscht; einen Rückweg bietet ein
Snapshot unter Einstellungen → Daten.

**Namen im Backup.** Der Verlauf gehört zum Backup (Topf `activity`). Die Namen
stehen darin **als Momentaufnahme** — wer ein Backup weitergibt, gibt sie mit.
Die Personenliste selbst (Namen, Rollen, Passwörter) bleibt in `auth.json` und
nicht im Backup. Ein Backup ohne Verlauf (aus einer Version vor 3.4) lässt den
vorhandenen Verlauf unverändert.

**Person löschen:** Unter Einstellungen → Zugriff → „Benutzer und Rechte“ fragt
„Person löschen“, ob der Name im Verlauf durch **„ehemalige Person“** ersetzt
werden soll. Vorgewählt ist nein — der Verlauf bleibt, wie er ist.

Die Beispielhaushalte und die öffentliche Demo bringen einen leeren Verlauf
mit; ein Beispielhaushalt laden leert ihn.

---

## 6. Kalender und Home Assistant

- **Kalender:** Ein erledigtes Ereignis bleibt mit derselben UID stehen,
  bekommt „✓ “ vor den Titel und verliert die Vorab-Erinnerung
  ([Kalender](kalender.md)).
- **Home Assistant** (`/api/summary`): `agenda.due` und `agenda.overdue` zählen
  nur Offenes, `agenda.next` überspringt Erledigtes; `contract.cancel_by` ist
  `null`, wenn ein Folgevertrag lückenlos anschließt oder der Vertrag jederzeit
  kündbar ist. Die Form der Antwort bleibt gleich.

---

## 7. Über die API

```text
GET    /api/agenda                 je Ereignis occurrence, can_mark_done, done
POST   /api/agenda/done            {uid, occurrence?, done_on?, note?, attachment_id?, cancelled?}
GET    /api/activity               ?from&to&by&kind&ref&limit — neueste zuerst
POST   /api/activity/{id}/undo     Rückgängig (streicht durch, stellt zurück)
PATCH  /api/activity/{id}          {note}
DELETE /api/activity               ?before=JJJJ-MM-TT — nur Verwaltung
```

`POST /api/reminders/{id}/done` und `POST /api/recommendations/{id}/dismiss`
antworten zusätzlich mit `activity_id`. Felder, Statuscodes und Stabilität:
[API-Referenz](../referenz/api.md#erledigt-mit-verlauf-v340); der Topf
`activity`: [Datenmodell](../referenz/datenmodell.md).

---

[← Kompendium-Index](../README.md) · [Fristen und Termine im Kalender](kalender.md)
