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
| seit v3.5.0 einen **neuen Zeitraum** bei einem Zähler mit Verbrauch je Zeitraum, wenn er fällig war — wie eine Ablesung, beim CSV-Import einmal mit dem neuesten Zeitraum | „Zählerstand ablesen“ |
| eine **Lieferung** Heizöl oder Pellets, wenn der Bestand vorher niedrig war | „Lieferung planen“ |
| eine **Nebenkostenabrechnung** für den abgelaufenen Zeitraum (zur Miete) | „Nebenkostenabrechnung fällig“ |
| seit v3.5.0 eine **Versorgerrechnung**, die einen abgelaufenen Abrechnungszeitraum abdeckt ([Abschnitt 9](#9-abrechnung-erwartet-v350)) | „Abrechnung erwartet“ |
| seit v3.9.0 **„Stand ist richtig“**, **„Nur für die Abrechnung“** oder einen **korrigierten Stand** ([Abschnitt 11](#11-ablesung-prüfen-v390)) | „Ablesung vom … prüfen“, „Verbrauch vor und nach dem … passt nicht zusammen“ |
| seit v3.9.0 das **Löschen** einer doppelt eingetragenen Ablesung | „Ablesung vom … doppelt eingetragen“ |

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
| Abrechnung erwartet *(v3.5.0)* | ja — heißt „kam ohne Erfassung“; wer die Rechnung erfasst, braucht nichts abzuhaken |
| Zählerstand ablesen | nein — das erledigt eine Ablesung |
| Nebenkostenabrechnung fällig | nein — das erledigt die erfasste Abrechnung |
| Ablesung prüfen, doppelt eingetragen *(v3.9.0)* | nein: Das erledigen die Knöpfe am Eintrag ([Abschnitt 11](#11-ablesung-prüfen-v390)) |
| Hinweis auf die Analyse-Zäsur *(v3.9.0)* | ja, mit „Nicht jetzt“ am Eintrag (ohne Dialog, mit Rückgängig) |

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
- Seit v3.5.0 steht bei einem erledigten Termin, **wer zuständig war**
  (Momentaufnahme, [Abschnitt 8](#8-zuständig-an-terminen-v350)), und eine
  **geänderte Nebenkostenabrechnung** erscheint mit dem Kennzeichen
  „geändert“ — nur zum Nachweis, sie erledigt nichts. Schließt erst die
  Änderung den offenen Zeitraum (etwa ein korrigiertes Ende), zählt sie als
  Erledigung wie das Erfassen, nie doppelt.
- **Als CSV** *(v3.5.0)* lädt den Verlauf mit den gewählten Filtern als
  Tabelle — etwa als Nachweis für Vermieter oder Hausverwaltung
  ([Abschnitt 10](#10-verlauf-als-csv-v350)).

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

Als Person handelt nur, wer angemeldet ist
([Benutzer im Haushalt](benutzer.md)). Ist die Person inzwischen gelöscht,
steht ihr Name mit dem Zusatz **„(gelöscht)“** da *(v3.6.0)*. Rechte:

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
Passwörter, Sitzungen und Schlüssel bleiben in `auth.json` und nicht im
Backup; das Personenverzeichnis (Nummer, Name, Rolle, Löschvermerk) gehört seit
v3.6.0 dazu (Topf `people`). Ein Backup ohne Verlauf (aus einer Version vor
3.4) lässt den vorhandenen Verlauf unverändert.

**Person löschen:** Unter Einstellungen → Zugriff → „Benutzer und Rechte“ fragt
„Person löschen“, ob der Name im Verlauf durch **„ehemalige Person“** ersetzt
werden soll. Vorgewählt ist nein — der Verlauf behält den Namen dann, seit
v3.6.0 mit dem Zusatz **„(gelöscht)“**
([Benutzer im Haushalt](benutzer.md#personen-löschen)).

Die Beispielhaushalte und die öffentliche Demo bringen seit v3.5.0 einen
Verlauf mit erfundenen Personen mit; ein Beispielhaushalt laden ersetzt den
vorhandenen Verlauf.

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
GET    /api/activity.csv           (v3.5.0) dieselben Filter, ?format=1|local&lang=
GET    /api/people                 (v3.5.0) Personen für „Zuständig“: nur ID und Name
GET    /api/users/{id}/handover    (v3.6.0) vor dem Löschen: offene Termine an wen, erledigte Einträge;
                                   (v3.7.0) dazu meters, contracts
GET    /api/calendar.ics           (v3.7.0) ?person=<id>: nur, was bei dieser Person liegt
```

`POST /api/reminders/{id}/done` und `POST /api/recommendations/{id}/dismiss`
antworten zusätzlich mit `activity_id`. Seit v3.5.0 nehmen
`POST`/`PATCH /api/reminders…` `assignee` und `assignee_id` an, seit v3.7.0
auch `utility` und `meter_id` (Termin am Zähler); Zähler und Verträge nehmen
`assignee_id` an, die Ereignisse der Agenda tragen `assignee_source`. Felder,
Statuscodes und Stabilität:
[API-Referenz](../referenz/api.md#erledigt-mit-verlauf-v340); der Topf
`activity`: [Datenmodell](../referenz/datenmodell.md).

---

## 8. Zuständig an Terminen *(v3.5.0)*

Ein Termin unter **Hinweise → Termine & Wartung** kann eine zuständige Person
haben — wer die Heizung warten lässt, wer den Rauchmelder prüft. Seit v3.7.0
auch Zähler und Verträge; ihre Fristen landen dann bei dieser Person
([unten](#zähler-und-verträge-v370)). **Benachrichtigt wird niemand**;
dafür gibt es den [Kalender](kalender.md) und Home Assistant.

**Eintragen:** Im Termin-Dialog das Feld **„Zuständig“**. Es schlägt die
Personen des Haushalts vor ([Benutzer im Haushalt](benutzer.md)) — seit
v3.6.0 aus dem Personenverzeichnis, also auch ohne Anmeldung (etwa im
Beispielhaushalt); gelöschte Personen nicht. Wer einen davon wählt, ist als
Person verknüpft — benennt der Verwalter sie um, zeigt der Termin den neuen
Namen; ein erledigter einmaliger Termin behält den Namen vom Erledigen.
Jeder andere Name geht auch (frei, bis 60 Zeichen), etwa „Oma Gerda“ oder der
Hausmeister. Leer heißt: niemand.

**Anzeige:** als Namens-Chip in der Terminliste, unter „Zu tun“ auf der
Übersicht, in der Agenda (Home Assistant) und im Kalender als Zeile
„Zuständig: …“ in der Beschreibung.

**Filter:** In den Terminen und unter „Zu tun“ steht, sobald jemand zuständig
ist, die Auswahl **Alle · Meine Aufgaben · Ohne Zuständigkeit · Namen**. Das
Gerät merkt sich die Wahl. „Meine Aufgaben“ ist mit Anmeldung die
angemeldete Person — per Verknüpfung oder gleichem Namen,
Groß-/Kleinschreibung egal. **Ohne Anmeldung** fragt die App daneben
**„Ich bin …“** und merkt sich die Antwort auf diesem Gerät. Unter „Zu tun“
blendet der Filter auch Hinweise ohne Zuständigkeit aus (etwa Ablesungen und
Verträge ohne zuständige Person); eine Zeile darunter sagt, wie viele.

**Erledigen:** Ein wiederkehrender Termin behält seine Zuständigkeit. Der
Eintrag im Verlauf nennt, wer zuständig war — als Momentaufnahme.

**Person löschen** *(v3.6.0, bis v3.5 blieb der Name als freier Name stehen)*:
Ihre **offenen Termine gehen an die Person, die löscht** — auch solche mit
einem freien Namen, der ihrem gleicht. Der Dialog sagt vorher, wie viele und
an wen. **Erledigtes behält den Namen** vom Erledigen mit dem Zusatz
**„(gelöscht)“**: im Verlauf (wer erledigt hat und wer zuständig war), an
erledigten einmaligen Terminen, in der CSV-Datei des Verlaufs und im
Kalender. Wählt der Verwalter beim Löschen „ehemalige Person“
([Abschnitt 5](#5-aufbewahrung-leeren-datenschutz)), steht dort
**„ehemalige Person“** statt des Namens. Der Filter zählt eine gelöschte oder
ehemalige Person zu „Ohne Zuständigkeit“, bis jemand neu eingetragen ist; eine
gelöschte Person lässt sich nicht mehr zuweisen. Wer mit einem API-Schlüssel
löscht, ist keine Person: Dann bleiben offene Termine bei der gelöschten
Person stehen, mit „(gelöscht)“, bis jemand sie übernimmt.

### Zähler und Verträge *(v3.7.0)*

Auch ein Zähler und ein Vertrag können eine zuständige Person haben — die
Fristen daran landen dann bei ihr. Gewählt wird im Zähler- bzw.
Vertragsformular unter **„Zuständig“**, nur aus dem Personenverzeichnis (keine
freien Namen); **„– niemand –“** heißt niemand. Die Karten der Zähler und
Verträge zeigen die Person.

**Wer bei einer Frist zuständig ist** — die erste Angabe, die es gibt, gilt:

| Frist | zuerst | dann | sonst |
|---|---|---|---|
| Termin (Eichfrist, Zählerwechsel, Wartung …) | eigene Zuständigkeit am Termin | Zähler, an dem der Termin hängt | niemand |
| Kündigungsfrist, Vertragsende, Preisgarantie, Preiserhöhung | Vertrag | Zähler des Vertrags | niemand |
| Abrechnung erwartet | Vertrag | Zähler des Vertrags | niemand |
| Ablesung fällig, Bestand niedrig (Tank) | Zähler | – | niemand |
| Nebenkosten- und CO₂-Fristen (Mietwohnung) | – | – | niemand |

Eine eigene Zuständigkeit am Termin geht immer vor — auch eine gelöschte
Person oder „ehemalige Person“, bis jemand neu einträgt. Ein Gruppenvertrag
hat keinen einzelnen Zähler; dort gilt nur die Person des Vertrags.

**Termin am Zähler:** Im Termin-Dialog sagt das Feld **„Zähler“**, zu welchem
Zähler ein Termin gehört. Bei einer neuen Eichfrist oder Lieferung ist der
einzige passende Zähler vorgewählt. Bleibt „Zuständig“ leer, steht darunter,
wer dann zuständig ist („Ohne Eintrag zuständig: Jonas (über den Zähler)“).

**Anzeige:** Geerbtes trägt im Chip den Zusatz **„über den Vertrag“** bzw.
**„über den Zähler“** — in den Terminen, unter „Zu tun“, auf **Kosten &
Verträge → Verträge & Abschläge** und im Kalender („Zuständig: Anna (über den
Vertrag)“).

**Filter:** „Meine Aufgaben“ und die Namen zählen Geerbtes mit — in den
Terminen, unter „Zu tun“ (auch die Sammelzeile der Ablesungen) und auf
**Verträge & Abschläge**, das den Filter jetzt ebenfalls hat. Den
[Kalender](kalender.md) abonniert, wer angemeldet ist, auf Wunsch **nur mit
dem, was bei ihr oder ihm liegt** (Haken im Dialog „Im Kalender abonnieren“).

**Erledigen:** Auch bei einer Frist mit geerbter Zuständigkeit nennt der
Verlauf, wer zuständig war — als Momentaufnahme.

**Person löschen:** Zähler und Verträge gehen wie offene Termine an die
Person, die löscht. Ohne Anmeldung bleibt die Person mit „(gelöscht)“ stehen,
mit dem Haken „ehemalige Person“ steht dort „ehemalige Person“. Der Dialog
nennt die Zahl vorher ([Personen löschen](benutzer.md#personen-löschen)).

---

## 9. Abrechnung erwartet *(v3.5.0)*

Endet ein Abrechnungszeitraum eines Vertrags und ist dafür keine
Versorgerrechnung erfasst (**Kosten → Rechnung prüfen → Rechnung des
Versorgers erfassen**), erscheint unter „Zu tun“ **„Abrechnung erwartet:
Strom (Anbieter) für 01.01.2025 – 31.12.2025“**. Der Knopf **„Rechnung
erfassen“** öffnet die Rechnungsprüfung mit diesem Zeitraum und dem
aufgeklappten Abschnitt zum Erfassen.

| Fall | erwarteter Zeitraum |
|---|---|
| laufender (auch weiterlaufender) Vertrag | die zuletzt abgeschlossene Periode nach dem Abrechnungsstichtag der Verbrauchsart (Einstellungen → Verbrauchsarten, Standard 01.01.), ab Vertragsbeginn |
| beendeter Vertrag | **Schlussrechnung** vom letzten Stichtag bis zum Vertragsende; ein weiterlaufender Vertrag endet am Tag vor dem Folgevertrag |
| Folgevertrag **desselben Versorgers** ohne Lücke | keine Schlussrechnung — die Belieferung endet nicht |

**Frist:**

| Verbrauchsart | gesetzliche Frist | in der App |
|---|---|---|
| Strom, Gas (Deutschland) | Rechnung spätestens **sechs Wochen** nach Ende des Abrechnungszeitraums, Abschlussrechnung sechs Wochen nach Ende der Belieferung (§ 40c Abs. 2 EnWG); der Abrechnungszeitraum ist höchstens ein Jahr (§ 40b Abs. 1 EnWG) | nach Ende + 42 Tage **„überfällig — gesetzliche Frist verstrichen, beim Versorger nachfragen“**, rot unter „Zu tun“ |
| Fernwärme | keine — abgerechnet wird „mindestens einmal jährlich“ (§ 4 Abs. 3 FFVAV, über § 24 Abs. 1 AVBFernwärmeV) | nur „erwartet“, nie „überfällig“ |
| Wasser | keine (§ 24 Abs. 1 AVBWasserV; kommunale Gebühren nach Landesrecht, § 35 Abs. 1 AVBWasserV) | nur „erwartet“, nie „überfällig“ |
| andere Länder | in der App nicht hinterlegt | nur „erwartet“ |

Die monatliche Abrechnung (Frist drei Wochen, § 40c Abs. 2 EnWG) bildet die
App nicht ab. Ob ein Wochenende oder Feiertag die Frist verschiebt, rechnet
sie nicht — sie nimmt schlicht das Ende plus 42 Tage. Die Frist ist ein
Hinweis, keine Rechtsberatung.

**Nie erwartet:** Heizöl und Pellets (Rechnung je Lieferung),
PV-Einspeisung (Gutschrift statt Rechnung), Angebote (Schattenverträge),
Zähler außer Betrieb.

**Passende Rechnung:** am Zähler, an einem Mitglied der Gruppe oder an der
Gruppe; ohne oder mit genau diesem Vertrag; das Rechnungsende darf bis zu
**31 Tage** vom Periodenende abweichen — Versorger lesen selten genau am
Stichtag ab.

**Wie lange:** Der Hinweis steht **bis 120 Tage nach Periodenende** — die
gesetzliche Frist von 42 Tagen plus gut elf Wochen Spielraum. Danach
verschwindet er ohne Eintrag im Verlauf. So sieht, wer Versorgerrechnungen
bisher nicht erfasst hat, nach dem Update nicht für jeden Vertrag ein altes
„überfällig“, obwohl die Rechnung längst da ist.

**Kein Hinweis** für Zeiträume, die vor mehr als 120 Tagen endeten (nicht
rückwirkend für alte Jahre), die vor der ersten Ablesung des Zählers enden oder
vor einer [Zäsur](../verstehen/00-overview.md#wirkung-einer-maßnahme-zäsur). Begann ein Vertrag weniger als zwei
Monate vor dem Stichtag, erwartet die App für dieses Stück keine eigene
Rechnung: ob es eine gibt oder ob es in der nächsten Jahresrechnung steht,
legt der Versorger fest.

**Erledigen:** Mit der erfassten Rechnung verschwindet der Hinweis von selbst;
der Verlauf zeigt „automatisch“ mit dem Grund „Rechnung bis … erfasst“.
Gelöscht oder auf einen anderen Zeitraum geändert, ist er wieder offen. Wer die
Rechnung nicht erfassen will, hakt ihn ab („kam ohne Erfassung“) —
Rückgängig wie üblich.

---

## 10. Verlauf als CSV *(v3.5.0)*

Unter **Hinweise → Erledigt** lädt **„Als CSV“** den Verlauf als Tabelle —
mit genau den gewählten Filtern (Person, Zeitraum, Bereich). Spalten:
**Datum, Aktion, Titel, Wer, Grund, Notiz**. Titel, Aktion und Grund stehen in
der Sprache der Oberfläche, Datum und Trennzeichen wie bei den übrigen
CSV-Exporten ([Datenexport](../referenz/api.md#csv-formate-v310)). Durchgestrichene
(zurückgenommene) Einträge stehen nicht darin. Freitext, der mit `=`, `+`, `-`
oder `@` beginnt, bekommt ein Apostroph davor, damit Excel ihn nicht als
Formel ausführt.

---

## 11. Ablesung prüfen *(v3.9.0)*

Ein falscher Stand zwischen zwei richtigen verschiebt Verbrauch von einem Monat in
den nächsten. Findet die App so einen Stand (oder mehrere hintereinander mit
demselben Fehler), steht unter **„Zu tun“** „Ablesung vom … prüfen“, mit dem, was im
Abstand davor und danach gemessen und erwartet war, und der Vermutung („vermutlich
um rund 300 m³ zu niedrig“). In der Liste der Ablesungen trägt der Stand die Marke
**AUFFÄLLIG**. Wie die App das erkennt, steht in den
[Grundlagen](../verstehen/00-overview.md#ablesung-prüfen-seit-v390).

| Knopf | was passiert |
|---|---|
| **Stand ist richtig** | Die App fragt bei diesem Stand nicht mehr. In der Liste steht **GEPRÜFT**. Ändert jemand später den Stand, fragt sie wieder. Auch in der Liste (✅ in der Zeile). |
| **Nur für die Abrechnung** | Der Stand bleibt für Rechnung und Saldo, die Monatswerte und das Heizmodell überspringen ihn: Der Verbrauch dazwischen wird nach dem Wetter verteilt. Das passt, wenn der Versorger mit einem geschätzten oder falschen Stand abgerechnet hat. In der Liste steht **NUR ABRECHNUNG**; im Bearbeiten-Dialog lässt sich das Häkchen wieder entfernen. |
| **Korrigieren** | öffnet den Bearbeiten-Dialog der Ablesung mit dem Stand, der passen würde („Einsetzen“). Gespeichert wird erst mit „Speichern“. |

Alle drei mit **Rückgängig** im Hinweis danach.

**„Verbrauch vor und nach dem … passt nicht zusammen“:** Liegt der Abstand davor weit
unter und der danach weit über der Erwartung (oder umgekehrt), aber kein gemeinsamer
Versatz erklärt beides, etwa weil die Stände nach und nach falscher wurden, fragt die App
mit diesem Text. Die Knöpfe sind dieselben; einen passenden Stand schlägt sie hier nicht
vor. Ein Foto der Ablesung oder die Abrechnung zeigt, welcher Stand stimmt.

**Doppelt eingetragen:** Steht derselbe Stand am selben Tag zweimal da, löscht
**„Eine löschen“** den zweiten (behalten wird der mit Foto, sonst der mit Notiz).
Rückgängig legt ihn neu an.

**Anderes Niveau:** Lag der Verbrauch über mindestens ein Jahr gleichbleibend weit
über oder unter heute, ist das eher ein anderer Verbrauch (etwa eine neue Heizung)
als ein Ablesefehler. Dann schlägt die App eine
[Analyse-Zäsur](../verstehen/00-overview.md#wirkung-einer-maßnahme-zäsur) vor:
**„Zäsur hier setzen“** öffnet einen kleinen Dialog: Der Tag ist mit dem Ende der
auffälligen Zeit vorbelegt (kennst du den Tag der Änderung, etwa den Heizungstausch,
trag ihn ein), dazu eine Bezeichnung. **„Zäsur setzen“** trägt sie am Zähler ein,
**„Nicht jetzt“** hakt den Hinweis ab. Beides mit Rückgängig.

Die Einträge stehen in jeder Stufe und zählen im Sensor „Zu tun“ von Home Assistant
mit (`/api/todo`).

---

[← Kompendium-Index](../README.md) · [Fristen und Termine im Kalender](kalender.md)
