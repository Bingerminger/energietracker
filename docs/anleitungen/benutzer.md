# Benutzer im Haushalt

**Deutsch** · [English](../en/anleitungen/benutzer.md)

[← Kompendium-Index](../README.md)

Seit **v3.2.0** kann sich jede Person im Haushalt selbst anmelden — mit
eigenem Namen, eigener Nutzungsstufe und eigener Sprache. Die Daten des
Haushalts teilen sich alle: Zähler, Stände, Verträge und Auswertungen gibt es
nur einmal. Zwei Rollen regeln, wer den Zugriff verwaltet und wer „nur“
erfasst.

Personen gibt es nur **mit eingeschalteter Anmeldung**. Ohne Anmeldung bleibt
alles wie bisher: Wer die Adresse erreicht, darf alles, und die Nutzungsstufe
gilt für die ganze Installation. Die Karte „Benutzer und Rechte“ sagt das seit
v3.6.0 auch in der App und springt mit „Anmeldung einschalten“ zur Karte
„Anmeldung & Zugriff“. Seit **v3.6.0** führt die App alle Personen
zusätzlich in einem **Personenverzeichnis** mit fortlaufender Nummer, das zum
Backup gehört (§ 7) — die Personen eines Beispielhaushalts stehen so auch ohne
Anmeldung unter „Zuständig“ zur Wahl. Wann sich die Anmeldung lohnt, steht in
[Sicherheit & Netzbetrieb](../betrieb/sicherheit.md#1-das-betriebsmodell-in-einem-satz).

---

## 1. Zwei Wege: Passwort oder Proxy

| Modus | Wer meldet an | Personen entstehen |
|---|---|---|
| **Anmeldung mit Passwort** | der Energietracker selbst: Name und Passwort | Wer die Installation verwaltet, legt sie unter Einstellungen → Zugriff an |
| **Anmeldung über vorgeschalteten Proxy** | ein Anmeldedienst davor, etwa Authelia, Authentik oder Home Assistant unter Ingress | beim ersten Besuch von selbst, unter dem Namen, den der Proxy meldet |

Einschalten: Passwort unter Einstellungen → Zugriff → „Anmeldung & Zugriff“
([Sicherheit § 3](../betrieb/sicherheit.md#3-anmeldung-einschalten)), Proxy
über `ET_AUTH=proxy` und `ET_TRUSTED_PROXIES`
([Sicherheit § 6](../betrieb/sicherheit.md#6-anmeldung-über-einen-vorgeschalteten-proxy)).

Die Seite **Einstellungen → Zugriff** gehört zur Stufe „Experte“. In einer
niedrigeren Stufe fehlt sie in der Leiste; über die Adresse
`#/settings/access` öffnet sie trotzdem — mit einem Hinweis und dem Knopf zum
Umstellen ([Nutzungsstufen](../einstieg/einrichtung.md#3-nutzungsstufen)).

---

## 2. Die erste Person, die verwaltet

**Mit Passwort.** Das Passwort, mit dem du die Anmeldung einschaltest, gehört
der Person **„admin“** mit der Rolle „Verwaltung“. Das gilt auch für
Installationen, die schon vor v3.2.0 ein Passwort hatten: Nach dem Update
ändert sich nichts, solange niemand weitere Personen anlegt — die Anmeldung
fragt nur nach dem Passwort.

Mit der ersten weiteren Person fragt die Anmeldung zusätzlich nach dem
**Namen**. „admin“ meldet sich dann mit dem Namen „admin“ oder mit leerem
Namensfeld und dem bisherigen Passwort an.

Lieber einen eigenen Namen? Lege dich selbst als Person mit der Rolle
„Verwaltung“ an, melde dich damit an und lösche „admin“. Danach gilt die
Anmeldung ohne Namen nicht mehr.

Steht das Passwort in der Umgebungsvariable `ET_ADMIN_PASSWORD_HASH`, gehört
es ebenfalls „admin“. Die App kann es dann nicht ändern, und „admin“ lässt
sich nicht löschen.

**Mit Proxy.** Wer als Erstes über den Proxy kommt, verwaltet; alle weiteren
sind Mitglieder. Die Rollen lassen sich danach unter Einstellungen → Zugriff
ändern. Eine Person „admin“ aus einem früheren Passwort-Betrieb bleibt in der
Liste, zählt im Proxy-Betrieb aber nicht als Verwaltung.

---

## 3. Personen anlegen

Nur im Modus Passwort und nur, wer die Installation verwaltet:

1. **Einstellungen → Zugriff → „Benutzer und Rechte“**.
2. Unter **„Person hinzufügen“**:
   - **Name** — bis 40 Zeichen. Beim Anmelden zählt Groß- und
     Kleinschreibung nicht; zwei Personen mit demselben Namen gibt es nicht —
     seit v3.6.0 über alle Personen, auch die ohne Zugang (gelöschte zählen
     nicht). Trägt eine Person ohne Zugang den Namen, übernimmt der neue Zugang
     sie (s. u.).
   - **Neues Passwort** — mindestens 8 Zeichen.
   - **Rolle** — „Mitglied“ (vorgewählt) oder „Verwaltung“.
3. **„Hinzufügen“.** Die App meldet „… kann sich jetzt anmelden.“

Die Tabelle darüber zeigt alle Personen mit ihrer Rolle; „(du)“ markiert dich
selbst, **„Nr. …“** ist ihre fortlaufende Nummer *(v3.6.0)*. Die Rolle änderst
du direkt in der Auswahl der Zeile. **„Umbenennen“** *(v3.6.0)* ändert den Namen
— bei Zugängen mit Passwort meldet sich die Person danach mit dem neuen Namen
an. Offene Termine zeigen den neuen Namen, Erledigtes behält den alten. Ein
Name, den schon jemand trägt (auch eine Person ohne Zugang), wird abgelehnt.
Zugänge über einen Proxy haben keinen Knopf: Ihren Namen meldet der Proxy.

**Anmelden:** Der Anmeldebildschirm fragt nach „Name“ und „Passwort“. Jeder
Browser bleibt danach 30 Tage angemeldet; „Abmelden“ steht in der Kopfleiste.

### Personen löschen

**Nur wer verwaltet**, sieht „Person löschen“ in der Zeile; Mitglieder sehen
unter Einstellungen → Zugriff nur den Hinweis, dass das die Verwaltung
erledigt. Über die API antwortet die App einem Mitglied mit **403**
(`errors.auth.adminOnly`). Die eigene Person löscht eine andere Person, die
verwaltet.

Die Person kann sich danach nicht mehr anmelden, ihre Sitzungen enden sofort;
die Daten des Haushalts bleiben. Seit **v3.6.0** gilt außerdem:

- **Offene Termine gehen an dich**, die Person, die löscht — auch Termine mit
  einem freien Namen, der ihrem gleicht. Der Dialog sagt vorher, wie viele es
  sind und an wen sie gehen.
- *(v3.7.0)* **Zähler und Verträge**, für die sie zuständig war, gehen
  ebenso an dich; der Dialog nennt ihre Zahl
  ([Zuständig an Zählern und Verträgen](hinweise-erledigen.md#zähler-und-verträge-v370)).
  Ohne Anmeldung bleibt die Person dort mit „(gelöscht)“ stehen, mit dem
  Haken „ehemalige Person“ steht „ehemalige Person“ da.
- **Erledigtes behält ihren Namen** mit dem Zusatz **„(gelöscht)“** — im
  Verlauf, an erledigten Terminen, in der CSV-Datei und im Kalender. Der Name
  steht so da, wie er beim Erledigen hieß. Der Dialog nennt die Zahl der
  Einträge.
- Mit dem Haken **„ehemalige Person“** (vorgewählt: nein) ersetzt die App den
  Namen bei Erledigtem stattdessen ganz
  ([Hinweise erledigen](hinweise-erledigen.md#5-aufbewahrung-leeren-datenschutz)).
- **Die Nummer bleibt vergeben.** Das Personenverzeichnis zählt weiter: Die
  nächste Person bekommt die nächste Nummer, nie die einer gelöschten. Wer
  später mit demselben Namen angelegt wird, ist eine neue Person.
- Eine gelöschte Person lässt sich keinem Termin, Zähler oder Vertrag mehr
  zuweisen und steht nicht mehr zur Wahl.

Im Proxy-Betrieb sperrt Löschen niemanden aus: Kommt die Person wieder über
den Proxy, legt die App sie neu an (mit neuer Nummer) — aussperren muss dort
der Proxy. Löscht ein API-Schlüssel, übernimmt niemand: Offene Termine, Zähler
und Verträge bleiben dann mit „(gelöscht)“ stehen, bis jemand sie neu zuweist.

### Personen ohne Zugang

Personen aus einem Backup oder einem Beispielhaushalt haben keinen Zugang —
sie stehen im Personenverzeichnis, aber nicht in `auth.json`. Die Tabelle
zeigt sie mit Nummer und dem Vermerk **„ohne Zugang“**. Löschen geht wie oben,
mit derselben Übergabe und demselben Dialog („Die Person steht danach nicht
mehr zur Wahl“).

**Name und Rolle ändern** (seit v3.6.0, nur wer verwaltet): Die Rolle stellst
du in der Auswahl der Zeile um, den Namen mit **„Umbenennen“**. Der Name folgt
denselben Regeln wie bei einem Zugang (höchstens 40 Zeichen, nicht doppelt —
auch nicht zum Namen eines Zugangs). Offene Termine der Person zeigen danach
den neuen Namen; erledigte Termine und der Verlauf behalten den Namen, den die
Person beim Erledigen hatte. Die Rolle wirkt erst, wenn die Person einen Zugang
bekommt; „Zugang anlegen“ übernimmt sie. Ein Mitglied bekommt wie beim Löschen
`403`.

**„Zugang anlegen“** (nur im Modus Passwort) trägt Name und Rolle unter
„Person hinzufügen“ ein; mit einem Passwort wird daraus ein Zugang, der ID und
Nummer der Person übernimmt — ihre Termine und Einträge im Verlauf gehören
dann ihr.

**Ohne Anmeldung** (etwa nach dem Laden eines Beispielhaushalts) zeigt
Einstellungen → Zugriff die Karte „Benutzer und Rechte“, sobald das
Verzeichnis Personen kennt. Bedienen darf sie dann jede Person, die die
Adresse erreicht — wie alle Einstellungen ohne Anmeldung. Weil niemand
angemeldet ist, übernimmt beim Löschen niemand: Offene Termine bleiben mit
„(gelöscht)“ stehen, bis jemand sie neu zuweist; der Dialog sagt es vorher.

---

## 4. Verwaltung und Mitglied

| | Verwaltung | Mitglied |
|---|---|---|
| Zählerstände, Lieferungen, Verträge, Rechnungen, Termine erfassen und ändern | ✓ | ✓ |
| alles ansehen, Jahresbericht, CSV-Export, Backup herunterladen | ✓ | ✓ |
| Einstellungen des Haushalts (Wohnfläche, Verbrauchsarten, Wetter, Rechenparameter …) | ✓ | ✓ |
| eigene Nutzungsstufe, eigene Sprache, eigenes Passwort | ✓ | ✓ |
| Hinweise abhaken, Rückgängig, Notiz im Verlauf *(v3.4.0)* | ✓ | ✓ |
| Verlauf leeren, Aufbewahrung des Verlaufs *(v3.4.0)* | ✓ | — |
| Personen anlegen, Rollen ändern, Passwörter neu setzen, Personen löschen | ✓ | — |
| Anmeldung ein- und ausschalten, API-Schlüssel, Home-Assistant-Token | ✓ | — |
| Backup einspielen, Snapshot einspielen, Beispielhaushalt laden | ✓ | — |
| Adressen im Netz: Einbetten, Texterkennung, evcc | ✓ | — |

Einspielen und Laden ersetzen alle Daten; die Adressen lassen den Server mit
anderen Rechnern sprechen. Darum bleiben sie der Verwaltung vorbehalten.
Versucht ein Mitglied es trotzdem, antwortet die App mit „Das darf nur, wer
die Installation verwaltet.“ Unter Einstellungen → Zugriff sehen Mitglieder
nur den Hinweis, wer den Zugriff verwaltet.

**Mindestens eine Person verwaltet.** Die letzte Person mit der Rolle
„Verwaltung“ lässt sich weder zum Mitglied machen noch löschen.

**API-Schlüssel gehören zu keiner Person.** Ein Schlüssel mit der
Berechtigung „Verwalten“ darf alles, was die Verwaltung darf; „Lesen“ darf nur
abrufen ([Sicherheit § 5](../betrieb/sicherheit.md#5-api-schlüssel-für-skripte)).

---

## 5. Passwort vergessen

**Eine Person, die verwaltet, setzt es neu:** Einstellungen → Zugriff →
„Benutzer und Rechte“ → „Passwort neu setzen“ in der Zeile → neues Passwort
(mindestens 8 Zeichen) → „Speichern“. Bei „admin“ ändert das das Passwort der
Anmeldung ohne Namen. Personen aus dem Proxy-Betrieb haben hier kein
Passwort; sie ändern es beim Anmeldedienst.

Ein neues Passwort meldet die Person auf allen Geräten ab — wer ein Gerät
verloren hat, ist damit sicher wieder draußen. Die anderen Personen bleiben
angemeldet.

**Vergisst die einzige Person, die verwaltet, ihr Passwort,** hilft nur der
Server:

1. In `data/auth.json` den Wert von `"mode"` auf `"off"` setzen (im Docker-
   Container geht es auch mit `ET_AUTH=off`). Die App ist dann ohne
   Anmeldung erreichbar — mit allen Rechten.
2. Die Kennung der Person nachsehen und ihr Passwort neu setzen:

   ```bash
   curl https://energie.example.org/api.php/api/users
   curl -X PATCH -H "Content-Type: application/json" \
     -d '{"password": "neues-passwort"}' \
     https://energie.example.org/api.php/api/users/u_1a2b3c4d
   ```

3. `"mode"` wieder auf `"password"` setzen (bzw. `ET_AUTH=off` entfernen).

---

## 6. Eigenes Konto: Passwort, Stufe, Sprache

Jede Person stellt unter **Einstellungen → Allgemein** selbst ein:

- **Passwort** — Karte „Mein Konto: …“ mit der eigenen Rolle: „Bisheriges
  Passwort“ und „Neues Passwort“ eintragen → „Passwort ändern“. Dieser
  Browser bleibt angemeldet, deine anderen Geräte melden sich neu an. Im Proxy-Betrieb steht dort der Hinweis, das
  Passwort beim vorgeschalteten Dienst zu ändern.
- **Nutzungsstufe** — Karte „Nutzungsstufe und Einrichtung“ oder die Auswahl
  in der Kopfleiste. Mit Anmeldung ist es deine Stufe auf allen deinen
  Geräten („Gilt für dich (…) auf allen Geräten.“); die Stufe der
  Installation bleibt, wie sie ist.
- **Sprache** — „Sprache auf diesem Gerät“ in der Karte „Sprache & Land“. Mit
  Anmeldung wird daraus deine Sprache auf allen deinen Geräten. Die
  „Standardsprache der Installation“ gilt weiter für Jahresbericht,
  CSV-Dateien, Home Assistant und alle, die keine eigene gewählt haben.

Wer keine eigene Stufe oder Sprache gewählt hat, sieht die der Installation.

---

## 7. Was nicht im Backup ist

Zugänge, Passwort-Hashes und die eigenen Einstellungen jeder Person stehen in
`data/auth.json` — wie Passwort, API-Schlüssel und Home-Assistant-Token.
Diese Datei gehört **nicht** zum Backup und nicht zu den Snapshots:

- Ein Backup einspielen ändert keine Zugänge: Wer sich anmelden konnte, kann
  es weiter.
- Nach einem Umzug mit einem Backup richtest du Anmeldung und Zugänge neu
  ein. Wer das ganze Datenverzeichnis umzieht (etwa das Docker-Volume), nimmt
  sie mit.
- Die Benutzer brauchen keinen Schemaschritt; die Nutzdaten bleiben, wie sie
  sind.

*(v3.6.0)* Das **Personenverzeichnis** `data/people.json` gehört dagegen zum
Backup (Topf `people`): je Person Nummer, Name, Rolle und — bei gelöschten —
wann und von wem gelöscht, ohne Passwörter. Bestehende Personen bekommen ihre
Nummer beim ersten Lesen nach dem Update, in der Reihenfolge, in der sie
angelegt wurden. Ein Backup bringt sein Verzeichnis mit; der Zähler geht dabei
nicht zurück. Nach einem Umzug stehen die Personen aus dem Backup unter
„Zuständig“ zur Wahl und unter Zugriff mit „ohne Zugang“; legst du einen
Zugang mit demselben Namen an (oder „Zugang anlegen“), übernimmt er die Person
(ID und Nummer), und ihre Termine und Einträge im Verlauf gehören wieder zu
ihr ([Personen ohne Zugang](#personen-ohne-zugang)).
- *(v3.4.0)* Der **Verlauf** erledigter Hinweise gehört dagegen zum Backup —
  und mit ihm die Namen der Personen, die etwas erledigt haben, als
  Momentaufnahme. Wer ein Backup weitergibt, gibt sie mit.

---

## 8. Wenn etwas nicht klappt

| Meldung | Ursache und Lösung |
|---|---|
| „Das darf nur, wer die Installation verwaltet.“ | Ein Mitglied hat eine Aufgabe der Verwaltung versucht (Tabelle in § 4). Eine Person, die verwaltet, erledigt es — oder ändert die Rolle. |
| „Mindestens eine Person muss die Installation verwalten.“ | Die letzte Person mit „Verwaltung“ sollte Mitglied werden oder gelöscht werden. Erst eine zweite Person zur Verwaltung machen. |
| „Die eigene Person lässt sich nicht löschen — …“ | Eine andere Person, die verwaltet, löscht dich. |
| „Diesen Namen gibt es schon.“ | Namen sind eindeutig, ohne Rücksicht auf Groß- und Kleinschreibung. |
| „Der Name darf nicht leer sein und höchstens 40 Zeichen haben.“ | Namen kürzen oder ausfüllen. |
| „Das Passwort braucht mindestens 8 Zeichen.“ | Längeres Passwort wählen. |
| „Die Rolle muss „Verwaltung“ oder „Mitglied“ sein (API: admin, member).“ | Über die API eine andere Rolle geschickt. |
| „Diese Person gibt es nicht mehr.“ | Jemand hat sie inzwischen gelöscht. Seite neu laden. |
| „Passwort falsch.“ | Name oder Passwort stimmen nicht — die App sagt bewusst nicht, welches. |
| „Zu viele Fehlversuche – bitte 5 Minuten warten.“ | Fünf Fehlversuche in 15 Minuten sperren die Anmeldung für 5 Minuten — für die ganze Installation, nicht je Person. |
| „Bei Anmeldung über den vorgeschalteten Dienst wird das Passwort dort geändert.“ | Im Proxy-Betrieb kennt die App kein Passwort. |
| „Das Passwort ist über die Umgebung (ET_ADMIN_PASSWORD_HASH) festgelegt und lässt sich hier nicht ändern.“ | Das Passwort von „admin“ steht in der Umgebung; dort ändern. „admin“ lässt sich dann auch nicht löschen. |
| „Ohne Anmeldung gibt es keine eigenen Einstellungen — die Stufe gilt dann für die ganze Installation.“ | Eigene Einstellungen über die API ohne Anmeldung (etwa mit einem API-Schlüssel). |

---

## 9. Über die API

```text
GET    /api/session               mode, authenticated, … und named_login, user, role
POST   /api/session               anmelden {name?, password}
PATCH  /api/session/me            eigene Einstellungen {ui_level?, language?} — null = wie die Installation
POST   /api/session/me/password   eigenes Passwort {current, password}
GET    /api/users                 Personen (nur Verwaltung), seit v3.6.0 mit Nummer `no` und
                                  `has_account` (Personen ohne Zugang: `false`, `source: "none"`)
POST   /api/users                 {name, password, role: admin|member}
PATCH  /api/users/{id}            {name?, role?, password?}; v3.6.0 auch für Personen ohne Zugang
                                  ({name?, role?}; ein Passwort: 400 errors.users.noAccountPassword)
GET    /api/users/{id}/handover   (v3.6.0) vor dem Löschen: {person, to, open, done};
                                  v3.7.0 dazu meters, contracts
DELETE /api/users/{id}            ?anonymize_activity=1 — Name im Verlauf ersetzen (v3.4.0);
                                  v3.6.0: handed_over, handed_over_to, kept;
                                  v3.7.0: records_handed_over, records_kept (Zähler und Verträge)
GET    /api/people                (v3.5.0) ID und Name für „Zuständig“; v3.6.0 aus dem Verzeichnis
```

`named_login` ist `true`, sobald die Anmeldung nach dem Namen fragt. Statuscodes
und Fehlercodes (`errors.users.*`, `errors.auth.adminOnly`):
[API-Referenz](../referenz/api.md).

---

[← Kompendium-Index](../README.md)
