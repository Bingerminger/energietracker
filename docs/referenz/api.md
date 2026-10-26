# API-Referenz

**Deutsch** · [English](../en/referenz/api.md)

[← Architektur](../entwicklung/architektur.md) · [Kompendium-Index](../README.md)

Alle Endpunkte unter `/api/…`. Antwort-Hülle einheitlich:

```json
{ "success": true,  "data": … }
{ "success": false, "error": "Meldung in der Sprache der Anfrage", "code": "errors.reading.dateInvalid" }
```

`{utility}` ist eine von: `gas`, `strom`, `wasser`, `fernwaerme`,
`heizoel`, `pellets`, `pv_einspeisung`, `pv_erzeugung` und seit v3.1.0
`waerme` (Heizwärme). Stand: **186 Routen**,
v3.7.0 — `ReleaseConsistencyTest` prüft, dass jede registrierte Route in der
Tabelle unten steht (DE und EN).

> Ausführliche Request-/Response-Beispiele für die meistgenutzten Endpunkte
> stehen in [`docs/API.md`](api-beispiele.md). Maßgeblich für Pfade und Felder ist
> **dieses** Dokument.

### Statuscodes aller Endpunkte

| Code | Wann |
|------|------|
| `400` | Ungültige Eingabe. Seit v2.5.3 auf allen Schreibpfaden: ein Datum, das kein Kalenderdatum ist (`2026-02-30`, Text), oder ein Betrag/Zählerstand, der keine Zahl ist. Bis v2.5.2 wurde beides gespeichert. Seit v2.6.0 auch Abfrageparameter außerhalb ihres Bereichs (Prognose, Jahresbericht, Temperatur-Sync). *(v3.1.0)* Beleg mit falschem Inhalt, zu groß oder Speicher voll (`errors.attachment.*`) — auch ein Upload über der PHP-Grenze `post_max_size`, dann mit dieser Grenze in der Meldung (`errors.attachment.size`); Texterkennung nicht eingetragen oder nicht im Heimnetz (`errors.ocr.off`, `errors.ocr.notLocal`); Zählerstand auf einem Zähler mit Verbrauch je Zeitraum (`errors.reading.periodMeter`), sich überschneidende Zeiträume (`errors.period.overlap`), Fehler im Mietverhältnis (`errors.tenancy.*`); ungültiges Jahr beim CO₂-Preis (`errors.co2.yearInvalid`), Fehler in einer Versorgerrechnung (`errors.bill.periodInvalid`, `…amountInvalid`, `…noResult`, `…noContract`), Rechnungsprüfung für eine Art ohne Prüfung (`errors.billCheck.unsupportedUtility`), ungültige Markt- oder Messlokations-ID (`errors.meter.maloInvalid`, `…meloInvalid`), Leistungspreis ohne Anschlussleistung (`errors.contract.capacityMissing`), Zähler löschen, an dem noch Zeiträume oder Rechnungen hängen (`errors.meter.hasPeriods`, `…hasBills`); Gruppenvertrag auf eine ungeeignete Gruppe oder mit Preisen für Fremde (`errors.contract.targetInvalid`), ein Mitglied mit eigenem Vertrag im selben Zeitraum (`errors.contract.groupMemberOverlap`), Gruppe mit Verträgen löschen (`errors.meter.groupHasContracts`), dynamischer Tarif als echter Vertrag oder nicht bei Strom (`errors.contract.dynamicShadowOnly`), unbekanntes Preismodell (`errors.contract.priceModelInvalid`), Monatspreise ohne lesbare Zeile oder für Wasser und Einspeisung (`errors.contract.priceImportEmpty`, `…priceImportUnsupported`), Börsenpreise ohne Werte (`errors.marketPrices.noRows`), Ladestrom-Nachweis ohne zahlenden Vertrag, ohne Pauschale oder mit ungültigen Parametern (`errors.evReport.*`), ungültiges Jahr der Wärmepumpe (`errors.heatPump.yearInvalid`), Verknüpfung mit einem Stromzähler ohne Rolle Wärmepumpe (`errors.meter.heatPumpLinkInvalid`), ungültige PV-Angabe am Zähler (`errors.meter.valueInvalid`), Zeitreihe mit unpassender Zuordnung oder ohne Anfangsstand (`errors.import.mappingInvalid`, `…anchorMissing`) — alle v3.1.0. *(v3.2.0)* Unbekannter Beispielhaushalt (`errors.demo.personaUnknown`); Name einer Person leer oder zu lang, unbekannte Rolle, die eigene Person löschen (`errors.users.nameInvalid`, `…roleInvalid`, `…notSelf`), eigene Einstellungen ohne angemeldete Person, Passwort einer Person vom Proxy (`errors.users.noUser`, `…proxyNoPassword`); evcc nicht eingetragen oder nicht im Heimnetz, keine abgeschlossenen Ladevorgänge, CSV ohne die nötigen Spalten, unbekannte Wahl für die Zählerstände, Zählerstände mehrerer Ladepunkte (`errors.evcc.off`, `…notLocal`, `…noSessions`, `…columns`, `…countersInvalid`, `…loadpointNeeded`). *(v3.3.0)* Ungültige Zeitfenster für § 14a Modul 3 am Stromvertrag (`errors.contract.gridTouInvalid`), ein Zeitstempel beim Ingest ohne Zeitzone oder in anderer Form als ISO 8601 (`errors.ingest.timestampFormat`), ein Jahr außerhalb 2018–2100 bei der Flexibilität und beim Abruf der Viertelstundenpreise (`errors.flexibility.yearInvalid`). *(v3.4.0)* Eine Hostliste, die den Namen der Anfrage nicht enthält (`errors.settings.hostNotListed`); eine selbst gewählte Geräte-ID außerhalb von Buchstaben, Ziffern, `_` und `-` (`errors.meter.idInvalid`); ein Backup mit Kennungen aus Steuerzeichen, Anführungszeichen, spitzen Klammern oder Backslash (Problem `unsafe_id:<feld>` in `detail.problems`). *(v3.4.0, F1024)* Erledigt-Tag kein Kalendertag oder in der Zukunft (`errors.activity.dateInvalid`), Notiz über 500 Zeichen (`errors.activity.noteInvalid`), unbekannter Beleg (`errors.attachment.notFound`). *(v3.5.0, F1027)* Tagesfilm: ein Datum, das kein Kalendertag ist oder außerhalb 2000–2100 liegt (`errors.energyFlow.dateInvalid`), ein ungültiger Monat (`errors.energyFlow.monthInvalid`). |
| `401` | `/api/ingest` bei gesetztem Token ohne oder mit falschem Bearer-Header. *(v2.6.0)* Mit eingeschalteter Anmeldung: jede nicht öffentliche Route ohne Sitzung oder API-Schlüssel (`errors.auth.required`); falsches Passwort, seit v3.2.0 auch ein unbekannter Name (`errors.auth.wrongPassword`). *(v3.1.0)* Ein Kalender-Schlüssel auf einer anderen Route als `/api/calendar.ics`, ein `read`- oder `admin`-Schlüssel als `?token=` im Kalender-Link. |
| `403` | *(v2.5.3)* Schreibende Anfrage (`POST`/`PUT`/`PATCH`/`DELETE`) aus dem Browser einer **fremden** Webseite — geprüft über `Sec-Fetch-Site`, ersatzweise `Origin` gegen `Host`. Anfragen ohne diese Kopfzeilen (Home Assistant, curl, Skripte) sind nicht betroffen. *(v2.6.0)* Schreibende Anfrage mit einem Lese-Schlüssel (`errors.auth.readOnlyKey`). *(v3.2.0)* Ein Mitglied an einer Route nur für Verwalter (`errors.auth.adminOnly`) — [Personen im Haushalt](#personen-im-haushalt-v320). |
| `404` | Unbekannte Route oder unbekannter Datensatz. Seit v2.6.0 einheitlich auch für Datensätze in der URL (bis v2.5.3 teils 400). *(v3.1.0)* Auch unbekannter Zeitraum (`errors.period.notFound`), Mietverhältnis (`errors.tenancy.notFound`), Abrechnung (`errors.tenancy.statementNotFound`) oder Versorgerrechnung (`errors.bill.notFound`). *(v3.2.0)* Unbekannte Person (`errors.users.notFound`). *(v3.3.0)* Unbekannte Viertelstundenreihe (`errors.intervals.notFound`). *(v3.4.0)* Eine Seite oder ein Bild der Dokumentation außerhalb der Freigabeliste (`errors.docs.notFound`; bei einem alten Pfad mit `detail.moved_to`); ein Eintrag im Verlauf (`errors.activity.notFound`) oder ein Ereignis der Agenda (`errors.activity.eventNotFound`), das es nicht (mehr) gibt. |
| `405` | *(v2.6.0)* Pfad bekannt, Methode nicht — mit Kopfzeile `Allow`. `HEAD` wird wie `GET` beantwortet, `OPTIONS` mit `204` und `Allow`. Bis v2.5.3: `404`. |
| `409` | *(v2.6.0)* Der Sicherungs-Snapshot vor einem Import/Einspielen ist gescheitert (`errors.backup.snapshotFailed`) — mit `?allow_without_snapshot=1` trotzdem möglich. Passwort oder Anmeldemodus sind über die Umgebung festgelegt. *(v3.2.0)* Der Name einer Person ist vergeben (`errors.users.nameTaken`), der letzte Verwalter soll Mitglied werden oder gelöscht werden (`errors.users.lastAdmin`), das Passwort des ersten Verwalters ist über die Umgebung festgelegt (`errors.auth.passwordFixed`). *(v3.4.0)* Abhaken mit einem `occurrence`, das nicht mehr gilt — etwa nach einem geänderten Vertragsende (`errors.activity.occurrenceChanged`). |
| `413` | *(v3.4.0)* Körper größer als erlaubt (`errors.http.tooLarge`) — geprüft an `Content-Length`, bevor gelesen wird: ohne Anmeldung erreichbare Routen (`POST /api/ingest`, `POST /api/session`) 1 MB, Backup-Import, Belege und die Importe (`…/import-csv`, `…/import-series`, `…/import-evcc`) 256 MB, alle anderen 8 MB. Mit eingeschalteter Anmeldung kommt eine Anfrage ohne Sitzung schon vorher als `401` zurück. |
| `421` | *(v2.6.0)* Hostname nicht in `ET_ALLOWED_HOSTS` (nur, wenn gesetzt; IP-Adressen und `localhost` sind immer erlaubt). *(v3.4.0)* Ebenso für die Einstellung `allowed_hosts`; beide Listen gelten. |
| `422` | *(v3.1.0)* PDF-Jahresbericht, CO₂-Anschreiben oder Ladestrom-Nachweis in einer Sprache, die die eingebauten PDF-Schriften nicht setzen können (`errors.report.pdfUnsupportedLanguage`) — die Druckansicht kann es. *(v3.4.0)* `POST /api/agenda/done` für eine Art, die sich nur aus den Daten erledigt — „Ablesung fällig“, „Nebenkostenabrechnung fällig“, Ende der Preisgarantie (`errors.activity.notManual`). |
| `429` | *(v2.6.0)* Anmeldung nach fünf Fehlversuchen innerhalb von 15 Minuten für 5 Minuten gesperrt (`errors.auth.locked`). *(v3.4.0)* Die Sperre gilt je Gerät (Client-Adresse; hinter einem Proxy aus `ET_TRUSTED_PROXIES` die aus `X-Forwarded-For`), nach zehn Fehlversuchen je Name, nach hundert für alle. |
| `502` | Ein Dienst außerhalb der App antwortet nicht oder unbrauchbar — ein Fehler auf der anderen Seite, keine falsche Eingabe: die Ortssuche (`errors.temperature.geocodeFailed`); *(v3.1.0)* `POST /api/ocr/reading`, wenn der eigene Texterkennungsdienst nicht in der Zeit antwortet, nicht erreichbar ist oder nichts Verwertbares liefert (`errors.ocr.timeout`, `errors.ocr.unreachable`, `errors.ocr.badAnswer`); *(v3.1.0)* `POST /api/market-prices/sync-smard`, wenn SMARD nicht erreichbar ist oder keine Werte liefert (`errors.marketPrices.syncFailed`); *(v3.2.0)* `POST …/sync-evcc`, wenn evcc nicht erreichbar ist oder keine Liste von Ladevorgängen liefert (`errors.evcc.unreachable`, `errors.evcc.badAnswer`); *(v3.3.0)* `POST /api/market-prices/sync-smard-intervals`, wenn SMARD nicht erreichbar ist, ein Wochenpaket nicht liefert oder für das Jahr keinen Wert hat (`errors.marketPrices.syncQuarterHoursFailed`). |
| `503` | *(v2.5.3)* Eine Datendatei ist beschädigt (kein gültiges JSON). Die Datei bleibt unverändert, daneben liegt eine Quarantäne-Kopie `<datei>.corrupt-<prüfsumme>`. Bis v2.5.2 wurde sie als leer gelesen und beim nächsten Schreiben überschrieben. Seit v3.1.0 steht die Meldung in der Sprache der Anfrage (bis v3.0 immer deutsch); `code` bleibt `errors.storage.corrupted`. *(v2.6.0)* Die Daten stammen von einer **neueren** Version (etwa nach dem Zurückdrehen des Image-Tags): alle Routen außer `/api/health`, geschrieben wird nichts (`errors.storage.dataTooNew`). `/api/health` selbst antwortet `503`, wenn `status` = `error`. *(v3.4.0)* Die Schreibsperre ist länger als 15 Sekunden belegt (`errors.http.busy`, Kopfzeile `Retry-After: 5`; `ET_LOCK_WAIT_S` ändert die Frist). *(v3.5.0)* Das Datenverzeichnis ist nicht beschreibbar, und die App müsste beim Start anlegen oder migrieren: alle Routen außer `/api/health` (`errors.storage.notWritable`; bis v3.4 kam `500`). `/api/health` meldet dann `data_dir_writable: false`. |
| `500` | Unerwarteter Fehler. Seit v2.6.0 mit `error_id`; dieselbe ID steht mit allen Einzelheiten im Server-Log. |

Schreibende Anfragen laufen seit v2.5.3 nacheinander (Sperre auf
`data/.write.lock`): Ein Home-Assistant-Push während einer Eingabe verliert
keine Änderung mehr. Seit v3.4.0 sperren Abgleiche mit Diensten außerhalb
erst nach dem Abruf (`POST /api/temperatures/sync-open-meteo`,
`…/market-prices/sync-smard`, `…/sync-smard-intervals`, `…/sync-evcc`), die
Texterkennung (`POST /api/ocr/reading`) gar nicht; wer länger als die Frist
wartet, bekommt `503`.

### Fehlercodes *(v2.6.0)*

Jede Fehlerantwort trägt `code` — den Katalogschlüssel der Meldung
(`errors.reading.dateInvalid`, `errors.backup.invalid` …) oder, für
allgemeine HTTP-Fehler, `errors.http.*` (`badRequest`, `unauthorized`,
`forbidden`, `notFound`, `methodNotAllowed`, `unavailable`, `internal`).
`error` ist in die Sprache der Anfrage übersetzt (s. u.) und darf sich
zwischen Versionen ändern — **Skripte werten `code` aus, nie den
Meldungstext.**

**Veraltet *(v3.1.0)*:** `errors.billCheck.gasOnly` wird nicht mehr gesendet —
die Rechnungsprüfung gibt es für alle Arten mit Zählerständen und Vertrag; eine
Art ohne Prüfung meldet `errors.billCheck.unsupportedUtility`. Der Schlüssel
bleibt bis zur nächsten Hauptversion im Katalog.

`detail` mit Datei, Zeile und Ausnahmetyp gibt es nur noch mit
`ET_DEBUG=1` (bis v2.5.3 bei jedem Fehler, samt absoluter Pfade). Fachliche
Einzelheiten kommen weiterhin immer, etwa die Fundstellen eines fehlerhaften
Backups in `detail.problems`.

### Sprache der Antworten *(v3.1.0)*

Meldungen und Bezeichnungen der API (Namen der Verbrauchsarten, Texte der
Empfehlungen, `error`) stehen in **einer** Sprache je Anfrage. Sie ergibt sich
in dieser Reihenfolge:

1. Kopfzeile `X-ET-Language` — die Oberfläche schickt bei jeder Anfrage die
   Sprache des Geräts mit (Einstellungen → Allgemein → „Sprache auf diesem
   Gerät“).
2. Einstellung `language` — die Standardsprache der Installation.
3. `Accept-Language` — nur, solange keine gültige Einstellung vorliegt (in der
   Praxis beim Erststart).

Ein unbekannter Wert in `X-ET-Language` zählt nicht; dann gilt die
Standardsprache. Downloads (PDF-Jahresbericht, CSV) öffnet der Browser als
Seite ohne diese Kopfzeile — sie entstehen deshalb in der Standardsprache,
ebenso alles, was Home Assistant und Skripte abrufen. Wer als Skript eine
andere Sprache will, schickt `X-ET-Language: fr`. Jede JSON-Antwort trägt
`Vary: X-ET-Language, Accept-Language`, damit ein Zwischenspeicher die
Sprachen nicht mischt. Bis v3.0 galt die Einstellung für alle Geräte.

### Anmeldung *(v2.6.0, opt-in)*

Ohne Anmeldung (Standard) bleibt die API offen wie bisher. Ist sie
eingeschaltet (Einstellungen → Zugriff → „Anmeldung & Zugriff", oder `ET_AUTH`), gilt
für jede Route **eines** davon:

| Weg | Für | Übergabe |
|---|---|---|
| Sitzung | den Browser | Cookie `et_session` (HttpOnly, SameSite=Strict, 30 Tage; seit v3.4.0 gleitend: eine Sitzung, die älter als ein Tag ist, bekommt bei Gebrauch ein neues Cookie mit voller Dauer) nach `POST /api/session` |
| API-Schlüssel | Skripte, andere Programme, Home Assistant | `Authorization: Bearer etk_…`; Bereich `read` (nur `GET`) oder `admin` |
| Kalender-Schlüssel *(v3.1.0)* | Kalender-Apps | API-Schlüssel mit Bereich `calendar`, **nur** als `?token=etk_…` und **nur** für `GET /api/calendar.ics` |
| Posteingang-Schlüssel *(v3.7.0)* | Kurzbefehl „Teilen → Energietracker“, Home Assistant (E-Mail) | API-Schlüssel mit Bereich `inbox`, als `Authorization: Bearer etk_…`, **nur** für `POST /api/inbox` (jede andere Route `401`) |
| Proxy | Betrieb hinter Authelia, Authentik, Home-Assistant-Ingress o. Ä. | `ET_AUTH=proxy`, nur von Adressen in `ET_TRUSTED_PROXIES`. Seit v3.4.0: mit `ET_PROXY_USER_HEADER` genau diese Kopfzeile; unter Home-Assistant-Ingress nur `X-Remote-User-Id`/`X-Remote-User-Name` (Person über die Kennung); sonst wie bisher die erste von `Remote-User`/`X-Forwarded-User`/`X-Remote-User`/`X-Remote-User-Name`/`X-Remote-User-Id` (mit Warnung in `GET /api/diagnostics`) |

Ohne Anmeldung erreichbar bleiben `POST /api/ingest` (eigener Token, mit
eingeschalteter Anmeldung **Pflicht**), `GET|HEAD /api/health` (dann nur
`{status, version}`), `GET|POST|DELETE /api/session`, `GET|HEAD /api/manifest`
(seit v3.1.0; enthält keine Daten) und `OPTIONS`. Details:
[Sicherheit](../betrieb/sicherheit.md).

**Kalender-Abo *(v3.1.0)*.** Kalender-Apps können keine Kopfzeile senden; der
Schlüssel steht deshalb im Link. Damit ein Link in einer Kalender-Synchronisation
oder einem Log nicht die ganze API öffnet, gilt dort nur ein Schlüssel mit
Bereich `calendar` — ein `read`- oder `admin`-Schlüssel im Link ergibt `401`.
Umgekehrt öffnet ein Kalender-Schlüssel keine andere Route, auch nicht als
`Authorization`-Kopfzeile (`401`). Mit einer Sitzung (der Browser) oder einem
`read`-/`admin`-Schlüssel in der Kopfzeile ist `/api/calendar.ics` abrufbar wie
jede andere Route.

**Personen und Rollen *(v3.2.0)*.** Mit Anmeldung kann jede Person des
Haushalts ein eigenes Konto haben (Modus `password`: Name und Passwort; Modus
`proxy`: der gemeldete Name). Verwalter (`admin`) dürfen alles; Mitglieder
(`member`) erfassen und sehen alles, ändern aber nicht den Zugriff, spielen
keine Daten ein und ändern keine Netzadressen — dort antwortet die App mit
`403` `errors.auth.adminOnly`. Ohne Anmeldung und mit einem API-Schlüssel gibt
es keine Person; eine Anfrage darf dann, was sie bisher durfte. Welche Routen
nur Verwalter erreichen: [Personen im Haushalt](#personen-im-haushalt-v320).

### Stabilitätszusage *(v2.6.0)*

Wer auf der API aufbaut — Home Assistant, Skripte, eigene Auswertungen —,
braucht eine Zusage, was sich ändern darf. Drei Klassen:

| Klasse | Umfang | Zusage |
|---|---|---|
| **A — Schnittstellen für Fremdsysteme** | `POST /api/ingest` (auch als Stapel), `GET /api/health`, `GET /api/summary` (`summary_version: 1`, v3.1.0), `GET /api/calendar.ics` (Form und UIDs, v3.1.0), Backup-Format 3.0 (`/api/backup/export`, `/api/backup/import`), CSV-Exporte im Format 1 und die CSV-Importe ([CSV-Formate](#csv-formate-v310)), Stammdaten (`meters`, `readings`, `contracts`, `deliveries`, `reminders`, `settings`, `temperatures`; seit v3.1.0 `periods` samt `periods.csv` und Zeitraum-Import, `tenancies` und `statements`), Belege hochladen und abrufen (`POST /api/attachments`, `GET /api/attachments/{id}`, v3.1.0), Fehlerhülle mit `code` | Nur additive Änderungen. Umbenennen oder Entfernen erst mit einer **Major-Version**, angekündigt mindestens eine Minor-Version vorher im CHANGELOG unter „Deprecated". Alte Feldnamen bleiben als Alias gültig. |
| **B — Auswertungen** | Verbrauch, Saldo, Prognose, Tarifvergleich/-wechsel, Rechnungsprüfung, Effizienz, Empfehlungen, PV/Saldo, `readings-overview`, `agenda` (v3.1.0), Budget des Mietverhältnisses (`tenancies/{id}/budget`, v3.1.0), CO₂-Preis und Aufteilung (`co2-costs`, `co2-split`, `reports/co2-split.pdf`, v3.1.0), Versorgerrechnungen (`bills` samt `check` und `book`, v3.1.0), die Auswertungen einer Zählergruppe (`meter-groups/{id}/…`, v3.1.0), Ladestrom-Nachweis (`reports/ev-charging` als JSON, CSV und PDF, v3.1.0), Jahresarbeitszahl (`heat-pump`, v3.1.0), Einordnung (`benchmarks/comparison`, v3.1.0), Flexibilität (`flexibility`, v3.3.0), Tagesfilm (`energy-flow/day`, `energy-flow/typical-day`, `energy-flow/days`, v3.5.0) | Dokumentierte Felder bleiben mit Namen und Bedeutung erhalten; neue kommen hinzu. **Werte** können sich ändern, wenn eine Berechnung korrigiert wird — das steht im CHANGELOG. |
| **C — Oberfläche** | `session` (seit v3.4.0 samt `session/logout-everywhere`), `auth/token`, `auth/keys`, `backup/snapshots`, `diagnostics`, `demo`, `countries`, Belegliste und Löschen (`GET /api/attachments`, `DELETE /api/attachments/{id}`) und `ocr/reading` (v3.1.0), Börsenstrompreise (`market-prices` samt Import und SMARD-Abruf), Monatspreise aus einer Datei (`contracts/{id}/prices/import-csv`) und Zeitreihen mit Spaltenzuordnung (`meters/{id}/import-series`, alle v3.1.0), Personen (`users`, `session/me`, v3.2.0), Ladevorgänge aus evcc (`import-evcc`, `sync-evcc`, `ev-sessions`, v3.2.0), Viertelstundenreihen (`intervals`) und Börsenpreise je Viertelstunde (`market-prices/sync-smard-intervals`, beide v3.3.0), Doku in der App (`docs`, `docs/asset`, v3.4.0), Verlauf erledigter Hinweise (`activity`, `agenda/done`, v3.4.0) | Für die eigene Oberfläche gebaut; Änderungen möglich, stehen aber im CHANGELOG. |

Anlass: v2.0.0 hat `verdict` von „Nachzahlung/Erstattung" still auf Schlüssel
(`surcharge`/`refund`/`balanced`) umgestellt — ohne Ankündigung. Das soll
nicht wieder passieren.

---

## 1. Vollständige Routen-Übersicht

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/health` | Health-Check: `status` ok/degraded/error, Prüfungen, letzter Ingest (HTTP 503 bei `error`); auch `HEAD` |
| GET | `/api/update-check` | *(v3.6.0)* Gibt es eine neuere Version? `{enabled, current, latest, newer, url, checked_at}` — nur mit Einstellung `update_check`, sonst `{enabled: false, current}` ohne Anfrage nach außen; der Server fragt GitHub höchstens einmal am Tag |
| GET | `/api/session` | Anmeldemodus, angemeldet?, per Umgebung festgelegt? *(v2.6.0)*; seit v3.2.0 `named_login`, `user`, `role` — [Personen](#personen-im-haushalt-v320) |
| POST | `/api/session` | Anmelden `{name?, password}` → Sitzungs-Cookie *(v2.6.0; `name` seit v3.2.0)* |
| DELETE | `/api/session` | Abmelden *(v2.6.0)* |
| POST | `/api/session/logout-everywhere` | *(v3.4.0)* Überall abmelden: alle Sitzungen der angemeldeten Person enden, auf jedem Gerät (auch diesem); API-Schlüssel bleiben — [Personen](#personen-im-haushalt-v320) |
| POST | `/api/session/password` | Passwort setzen/ändern `{password, current?}` — schaltet die Anmeldung ein *(v2.6.0)*; seit v3.2.0 nur Verwalter |
| DELETE | `/api/session/password` | Anmeldung ausschalten `{current}` *(v2.6.0)*; seit v3.2.0 nur Verwalter |
| PATCH | `/api/session/me` | *(v3.2.0)* eigene Einstellungen der angemeldeten Person `{ui_level?, language?}` — [Personen](#personen-im-haushalt-v320) |
| POST | `/api/session/me/password` | *(v3.2.0)* eigenes Passwort ändern `{current, password}` |
| GET | `/api/users` | *(v3.2.0)* Personen im Haushalt, ohne Passwort-Hash — nur Verwalter; *(v3.6.0)* mit fortlaufender Nummer `no` und `has_account`, dahinter die Personen ohne Zugang aus dem Verzeichnis (`has_account: false`, `source: "none"`) |
| GET | `/api/people` | *(v3.5.0)* Personen für die Auswahl „Zuständig“: nur `[{id, name}]`, nach Namen sortiert, für jede angemeldete Rolle (auch Lese-Schlüssel); *(v3.6.0)* aus dem [Personenverzeichnis](#personenverzeichnis-v360), ohne gelöschte, auch ohne eingeschaltete Anmeldung (bis v3.5 dann `[]`) — [Zuständig](#zuständig-an-terminen-v350). Klasse C |
| POST | `/api/users` | *(v3.2.0)* Person anlegen `{name, password, role?}`; `201` — nur Verwalter; *(v3.6.0)* Antwort mit `no` |
| PATCH | `/api/users/{id}` | *(v3.2.0)* Name, Rolle oder Passwort ändern `{name?, role?, password?}` — nur Verwalter; *(v3.6.0)* auch für Personen ohne Zugang (Name und Rolle; ein Passwort `400` `errors.users.noAccountPassword`) — [Personenverzeichnis](#personenverzeichnis-v360) |
| GET | `/api/users/{id}/handover` | *(v3.6.0)* Vorschau „Person löschen“: `{person: {id, name, no}, to: {id, name}\|null, open, done}` — offene Termine und an wen, erledigte Einträge im Verlauf — nur Verwalter; *(v3.7.0)* dazu `meters`, `contracts` (Zuständigkeiten, die mit übergehen). Klasse C |
| DELETE | `/api/users/{id}` | *(v3.2.0)* Person löschen; der letzte Verwalter bleibt — nur Verwalter; seit v3.4.0 ersetzt `?anonymize_activity=1` ihren Namen im Verlauf durch „ehemalige Person“ (Antwort `activity_anonymized`); *(v3.6.0)* offene Termine gehen an die löschende Person, Erledigtes behält den Namen mit „(gelöscht)“ (Antwort additiv `handed_over`, `handed_over_to`, `kept`) — [Personenverzeichnis](#personenverzeichnis-v360); *(v3.7.0)* ebenso Zähler und Verträge (`records_handed_over`, `records_kept`) — [Zuständig](#zuständig-an-zählern-und-verträgen-v370) |
| GET | `/api/auth/keys` | API-Schlüssel (ohne Klartext) *(v2.6.0)*; seit v3.2.0 nur Verwalter |
| POST | `/api/auth/keys` | Schlüssel erzeugen `{name, scope: read\|admin\|calendar\|inbox}`; Klartext einmalig *(v2.6.0; `calendar` seit v3.1.0, `inbox` seit v3.7.0)*; seit v3.2.0 nur Verwalter |
| DELETE | `/api/auth/keys/{id}` | Schlüssel widerrufen *(v2.6.0)*; seit v3.2.0 nur Verwalter |
| GET | `/api/diagnostics` | Systemstatus, Schreibrechte, Schema; seit v3.4.0 `security` (Anmeldemodus, Proxy-Kopfzeile, Hostlisten, `notices`: `proxy_header`, `open_without_hosts` samt Vorschlag `host`) |
| GET | `/api/utilities` | Liste der Verbrauchsarten + Konfiguration; seit v2.13.0 je Art `has_contracts`, `has_advance_payment_contracts` und `accounting_kind` (`consumption`, `feed_in`, `generation`); seit v3.1.0 `meter_roles` bei Arten mit Zählerrollen und `supports_bill_check` (Rechnungsprüfung möglich: Gas, Strom, Wasser, Fernwärme); seit v3.5.0 `has_special_payments` (Sonderzahlungen am Vertrag: Gas, Strom, Wasser, Fernwärme — [#34](https://github.com/Bingerminger/energietracker/issues/34)); `has_advance_payment_contracts` bleibt ohne Wasser (Saldo nach Kalender, Gruppenvertrag) |
| GET | `/api/manifest` | *(v3.1.0)* Web-App-Manifest in einer Sprache (`?lang=`, sonst Sprache des Geräts bzw. der Installation); ohne Anmeldung erreichbar, enthält keine Daten. Klasse C |
| GET | `/api/docs` | *(v3.4.0)* Eine Seite der Dokumentation dieses Release: `?path=` (`README.md`, `en/anleitungen/kalender.md`, `root/CHANGELOG.md`) → `{path, lang, mirror, root_files, markdown}`; nur aus der Freigabeliste — [Doku in der App](#doku-in-der-app-get-apidocs-v340). Klasse C |
| GET | `/api/docs/asset` | *(v3.4.0)* Ein Bild der Dokumentation (`?path=ui/screenshots/…png&v=<Version>`), `image/png`; mit der Version dieses Release unveränderlich zwischengespeichert. Klasse C |
| GET | `/api/settings` | Einstellungen; *(v3.6.0)* dazu `assumed_keys` — [Angenommene Angaben](#angenommene-angaben-assumed_keys-v360) |
| PATCH | `/api/settings` | Einstellungen ändern; `frame_ancestors`, `ocr_endpoint` und `evcc_endpoint` seit v3.2.0 nur Verwalter, ebenso `allowed_hosts` und `activity_keep_months` (v3.4.0) |
| GET | `/api/countries` | Länderprofile: Voreinstellungen je Land *(v2.7.0)*; seit v3.1.0 mit Rechnungsbegriffen (`bill_terms`) und amtlichem Tarifvergleich (`comparison_portal`) — s. u. |
| POST | `/api/settings/country` | Land wechseln: `{"country": "AT", "profile": true}` übernimmt das Länderprofil, `"profile": false` nur das Land; festgeschrieben wird nur, was vom Default abweicht *(v3.5.0)* — s. u. |
| GET | `/api/settings/default-updates` | Korrigierte Standardwerte, die diese Installation noch nicht nutzt (CO₂, Wasser-Referenz) *(v2.10.0)* — s. u. |
| GET | `/api/temperatures` | Tagestemperaturen (Map) |
| POST | `/api/temperatures` | Tagesdatum upsert |
| POST | `/api/temperatures/import-csv` | CSV-Import; `TT.MM.JJJJ;Mittel;Min;Max`, auch Tabulator und das alte Format mit Anführungszeichen — ein Trenner je Zeile (v2.12.0); seit v3.1.0 jedes Exportformat und Datum als `T/M/JJJJ`, `T-M-JJJJ` oder ISO — [Import](#import-v310) |
| GET | `/api/geocode` | Ortssuche für den Standort; `?q=` (2–80 Zeichen) — v2.12.0, s. u. |
| POST | `/api/temperatures/sync-open-meteo` | Open-Meteo-Abgleich; `?start=&end=&reload=1&auto=1` (v2.8.0) — s. u. |
| DELETE | `/api/temperatures/{date}` | Tagesdatum löschen |
| GET | `/api/utility/{u}/meters` | Zähler/Tanks; *(v3.7.0)* je Zähler `assignee`, `assignee_id`, `assignee_former`, `assignee_deleted` — [Zuständig](#zuständig-an-zählern-und-verträgen-v370) |
| POST | `/api/utility/{u}/meters` | anlegen; *(v3.7.0)* mit `assignee_id`; seit v3.1.0 mit `role` und `capture` — [s. u.](#zähler-role-capture-v310-additiv) — sowie `malo_id`, `melo_id` ([s. u.](#zähler-malo_id-melo_id-v310-additiv)); bei PV-Erzeugung `plug_in`, `investment_eur`, `commissioned_on`, `battery_capacity_kwh` ([PV](#pv-speicher-balkonkraftwerk-amortisation-v310-additiv)), bei Heizwärme `heat_pump_meter_ids` ([Wärmepumpe](#jahresarbeitszahl-der-wärmepumpe-v310)) |
| GET | `/api/utility/{u}/meters/{id}` | einzeln |
| PATCH | `/api/utility/{u}/meters/{id}` | ändern; `capture` nur, solange der Zähler keine Daten der bisherigen Art hat (v3.1.0) |
| DELETE | `/api/utility/{u}/meters/{id}` | löschen; `400`, solange Ablesungen, Lieferungen, Verträge oder Subzähler daran hängen, seit v3.1.0 auch Zeiträume (`errors.meter.hasPeriods`) oder Versorgerrechnungen (`errors.meter.hasBills`) |
| POST | `/api/utility/{u}/meters/{id}/replace-device` | Zählertausch |
| GET | `/api/utility/{u}/meter-groups` | Zählergruppen (F1006) |
| POST | `/api/utility/{u}/meter-groups` | Gruppe anlegen |
| POST | `/api/utility/{u}/meter-groups/merge` | „Zu Gruppe zusammenfassen“: mehrere Zähler bündeln |
| PATCH | `/api/utility/{u}/meter-groups/{groupId}` | Gruppe umbenennen |
| DELETE | `/api/utility/{u}/meter-groups/{groupId}` | Gruppe auflösen (Mitglieder bleiben); seit v3.1.0 `400` `errors.meter.groupHasContracts`, solange Verträge an der Gruppe hängen |
| GET | `/api/utility/{u}/meter-groups/{id}/consumption` | *(v3.1.0)* Monatsreihe der Gruppe als Summe der Mitglieder, Felder wie beim Zähler — [Gruppenvertrag](#gruppenvertrag-v310) |
| GET | `/api/utility/{u}/meter-groups/{id}/contract-status` | *(v3.1.0)* Saldo des Gruppenvertrags, wie beim Zähler |
| GET | `/api/utility/{u}/meter-groups/{id}/forecast` | *(v3.1.0)* Prognose der Gruppe; bei Arbeitspreisen je Mitglied mit Mischpreis |
| GET | `/api/utility/{u}/meter-groups/{id}/tariff-switch` | *(v3.1.0)* Wechselentscheidung für die Gruppe |
| GET | `/api/utility/{u}/meter-groups/{id}/bill-check` | *(v3.1.0)* Rechnungsprüfung der Gruppe: Abschnitte je Mitglied, feste Kosten einmal |
| GET | `/api/utility/{u}/meter-groups/{id}/billing-periods` | *(v3.6.0)* Abrechnungsperioden der Gruppe (Mitglieder zusammen) — [Abrechnungsperioden](#abrechnungsperioden-v360) |
| GET | `/api/utility/{u}/readings` | Ablesungen |
| POST | `/api/utility/{u}/readings` | anlegen; seit v3.1.0 mit `client_ref` (gleiche Kennung am selben Zähler → `200` mit `duplicate: true` statt eines zweiten Stands) und `attachment_id` (Foto) — [s. u.](#ablesungen-client_ref-attachment_id-v310-additiv) |
| PATCH | `/api/utility/{u}/readings/{id}` | ändern; seit v3.1.0 auch `attachment_id` (`null` löst das Foto); auf einen Zähler mit Verbrauch je Zeitraum umhängen → `400` `errors.reading.periodMeter` |
| DELETE | `/api/utility/{u}/readings/{id}` | löschen (ein Foto wird gelöst und nach 24 Stunden aufgeräumt) |
| POST | `/api/utility/{u}/meters/{id}/readings/import-csv` | CSV-Bulk-Import; `?dry_run=1` liest nur (Vorschau, v2.12.0); seit v3.1.0 Kopfzeilen und Datumsschreibweisen aller Sprachen — s. u. |
| POST | `/api/utility/{u}/meters/{id}/import-series` | *(v3.1.0)* Zeitreihe aus einem Portal mit Spaltenzuordnung `{csv, mapping}`, zu Tageswerten verdichtet; `?dry_run=1` liest nur — [Zeitreihe importieren](#zeitreihe-importieren-v310); seit v3.3.0 Werte im Takt von 15, 30 oder 60 Minuten zusätzlich je Viertelstunde (`intervals`) |
| POST | `/api/utility/strom/meters/{id}/import-evcc` | *(v3.2.0)* Ladevorgänge aus dem CSV-Export von evcc `{csv, counters?, loadpoint?}`, dazu die Zählerstände der Wallbox; `?dry_run=1` liest nur — [Ladevorgänge aus evcc](#ladevorgänge-aus-evcc-v320) |
| POST | `/api/utility/strom/meters/{id}/sync-evcc` | *(v3.2.0)* dasselbe direkt von evcc im Heimnetz (`evcc_endpoint`) `{counters?, loadpoint?}`; `?dry_run=1` liest nur, `502` bei Fehlern von evcc |
| GET | `/api/utility/{u}/periods` | *(v3.1.0)* Verbrauch je Zeitraum, nach Beginn sortiert; `?meter_id=` nur dieser Zähler — [Verbrauch je Zeitraum](#verbrauch-je-zeitraum-v310) |
| POST | `/api/utility/{u}/periods` | *(v3.1.0)* Zeitraum anlegen `{meter_id, from, to, value}` oder `{meter_id, month, value}`; `client_ref` wie bei Ablesungen (`200` mit `duplicate: true`) |
| PATCH | `/api/utility/{u}/periods/{id}` | *(v3.1.0)* Zeitraum ändern |
| DELETE | `/api/utility/{u}/periods/{id}` | *(v3.1.0)* Zeitraum löschen |
| POST | `/api/utility/{u}/meters/{id}/periods/import-csv` | *(v3.1.0)* Zeiträume aus CSV (`monat;wert[;notiz]` oder `von;bis;wert[;notiz]`); `?dry_run=1` liest nur |
| **GET** | **`/api/readings-overview`** | **alle aktiven kumulativen Zähler + letzte Ablesung (F1004, v1.6.0); seit v3.1.0 mit `capture`, `last_period` und `role`** |
| GET | `/api/utility/{u}/deliveries` | Lieferungen (Heizöl/Pellets) |
| POST | `/api/utility/{u}/deliveries` | anlegen |
| PATCH | `/api/utility/{u}/deliveries/{id}` | ändern |
| DELETE | `/api/utility/{u}/deliveries/{id}` | löschen |
| GET | `/api/utility/{u}/meters/{id}/stock-history` | Tank-Bestandskurve; seit v2.10.0 Tankbuch mit Stützstellen und Schätzbeginn — s. u. |
| GET | `/api/utility/{u}/contracts` | Verträge; seit v3.4.0 je Vertrag `phase` und `values_on` ([s. u.](#verträge-phase-values_on-v340-additiv)); *(v3.7.0)* Zuständigkeit wie beim Zähler |
| POST | `/api/utility/{u}/contracts` | anlegen; *(v3.7.0)* mit `assignee_id`; seit v3.1.0 Fernwärme mit Leistungs- und Messpreis, Antwort ggf. mit `warnings` — [s. u.](#verträge-fernwärme-fixkosten-warnings-v310-additiv); für eine Zählergruppe mit `meter_group_id` ([Gruppenvertrag](#gruppenvertrag-v310)); Strom mit `grid_reduction`, Preismodell `price_model`/`dynamic`, Einspeisung mit `revenue_statements` ([s. u.](#verträge-reduziertes-netzentgelt-preismodell-gutschriften-v310-additiv)); seit v3.3.0 Strom mit `grid_tou` ([zeitvariables Netzentgelt](#verträge-zeitvariables-netzentgelt-modul-3-v330-additiv)) |
| GET | `/api/utility/{u}/contracts/{id}` | einzeln; seit v3.4.0 mit `phase` und `values_on` |
| PATCH | `/api/utility/{u}/contracts/{id}` | ändern; wie beim Anlegen |
| DELETE | `/api/utility/{u}/contracts/{id}` | löschen |
| POST | `/api/utility/{u}/contracts/{id}/prices/import-csv` | *(v3.1.0)* Monatspreise aus einer Datei (`monat;ct_kwh[;grundpreis]`), setzt `price_model: "monthly"`; `?dry_run=1` liest nur |
| GET | `/api/market-prices` | *(v3.1.0)* Großhandelspreise Strom (Day-Ahead DE/LU) als Monatsmittel, mit Namensnennung — [Börsenstrompreise](#börsenstrompreise-und-dynamik-check-v310) |
| POST | `/api/market-prices/import-csv` | *(v3.1.0)* Börsenpreise aus einer Datei (SMARD-Download oder `JJJJ-MM;€/MWh`); `?dry_run=1` liest nur |
| POST | `/api/market-prices/sync-smard` | *(v3.1.0)* Monatswerte von SMARD holen — nur auf Knopfdruck |
| POST | `/api/market-prices/sync-smard-intervals` | *(v3.3.0)* Börsenpreise eines Jahres je Viertelstunde von SMARD holen, `?year=` (Standard Vorjahr) — nur auf Knopfdruck, `502` bei Fehlern von SMARD — [Flexibilität](#flexibilität-bewerten-v330) |
| GET | `/api/utility/{u}/consumption` | Monatsverbrauch (utility-weit) |
| GET | `/api/utility/{u}/meters/{id}/consumption` | Verbrauch + Anomalien + Regressionen |
| GET | `/api/utility/{u}/meters/{id}/contract-status` | Saldo je Vertrag; seit v2.5.1 mit `special_payments[]` (Einzelposten, Gas/Strom/Fernwärme, seit v3.5.0 auch Wasser); seit v2.8.0 nach Kalender bis heute, seit v2.9.0 tagesgenau mit Kündigungsstichtag, seit v3.4.0 mit `phase` — s. u. — und `min_term_end`, `price_guarantee_until`, `auto_renews` ([Schaubilder](#schaubilder-energiefluss-und-zeitstrahl-v340-additiv)) sowie `successor` ([Folgevertrag](#erledigt-mit-verlauf-v340)) |
| GET | `/api/utility/{u}/meters/{id}/forecast` | Prognose mit Unsicherheitsband, Klimanormal und Hinweisen (v2.8.0); seit v3.1.0 CO₂-Preis-Szenario `?co2_scenario_eur_t=&co2_scenario_from=` — s. u. |
| POST | `/api/forecast/scenario` | *(v3.6.0)* Prognose-Werkstatt: Szenario gegen den Ausgangsfall rechnen (Winter, Raumtemperatur, Maßnahmen, Tarif, Modell, Budget); schreibt nichts — [s. u.](#post-apiforecastscenario--prognose-werkstatt-v360-f1031-additiv) |
| GET | `/api/utility/{u}/meters/{id}/tariff-comparison` | Tarifvergleich echt vs. Schatten (Rückblick); seit v3.3.0 rechnet ein dynamischer Schattenvertrag mit dem eigenen Lastgang, wo Viertelstunden vorliegen (`dynamic_profile_months`) — [Dynamik-Check](#börsenstrompreise-und-dynamik-check-v310) |
| GET | `/api/utility/{u}/meters/{id}/tariff-switch` | Wechselentscheidung ab Wechseltermin; optional `?switch_date=YYYY-MM-DD` |
| GET | `/api/utility/{u}/meters/{id}/bill-check` | Rechnungsprüfung: Abschnitte je Ablesung und Brennwertwechsel bzw. Preisstichtag, mit Kosten, `?from=&to=` (F1012; bis v3.0 nur Gas, seit v3.1.0 Gas, Strom, Wasser, Fernwärme, sonst 400) — [s. u.](#get-apiutilityumetersidbill-checkfromto-f1012-v250-alle-arten-seit-v310) |
| GET | `/api/utility/{u}/meters/{id}/billing-periods` | *(v3.6.0)* Abrechnungsperioden des Zählers aus Rechnungen und Stichtag mit Menge, Kosten, Abschlägen, Saldo; `?months=1` mit Monatsreihe — [Abrechnungsperioden](#abrechnungsperioden-v360) |
| GET | `/api/utility/{u}/billing-periods` | *(v3.6.0)* dasselbe für die ganze Art (Zähler ohne Elternzähler, ohne Verbrauch je Zeitraum) |
| GET | `/api/utility/{u}/bills` | *(v3.1.0)* Versorgerrechnungen, die neueste zuerst; `?meter_id=` nur dieser Zähler — [Versorgerrechnungen](#versorgerrechnungen-v310) |
| POST | `/api/utility/{u}/bills` | *(v3.1.0)* Rechnung erfassen; `201` |
| PATCH | `/api/utility/{u}/bills/{id}` | *(v3.1.0)* Rechnung ändern |
| DELETE | `/api/utility/{u}/bills/{id}` | *(v3.1.0)* Rechnung löschen; Belege werden frei, eine gebuchte Sonderzahlung bleibt im Vertrag |
| GET | `/api/utility/{u}/bills/{id}/check` | *(v3.1.0)* eigene Rechnung gegen die des Versorgers: Abweichung, Urteil, Gründe |
| POST | `/api/utility/{u}/bills/{id}/book` | *(v3.1.0)* Ergebnis als Sonderzahlung im Vertrag buchen (einmal je Rechnung) |
| GET | `/api/benchmarks/efficiency` | Effizienzklasse pro Heizquelle; seit v2.10.0 mit Abdeckung und energieausweis-naher Kennzahl — s. u. |
| GET | `/api/benchmarks/comparison` | *(v3.1.0)* Einordnung des Jahresverbrauchs an eigenen Vergleichswerten (Haushaltsstrom, Heizung je m²; seit v3.6.0 mit Ampel `rating`, Wasser je Person und fehlenden Angaben `missing`), `?year=` (Standard Vorjahr) — [Einordnung](#einordnung-get-apibenchmarkscomparison-v310) |
| GET | `/api/heat-pump` | *(v3.1.0)* Jahresarbeitszahl je Wärmepumpe: Wärme ÷ Strom je Monat und Jahr, `?year=` (Standard Vorjahr) — [Wärmepumpe](#jahresarbeitszahl-der-wärmepumpe-v310) |
| GET | `/api/flexibility` | *(v3.3.0)* Flexibilität eines Jahres aus Verbrauch und Börsenpreis je Viertelstunde: je Stromzähler Profilfaktor und Vorteil, Speicher, § 14a Modul 3; *(v3.6.0)* dazu `insights` — Ampel, Hebel, Zeitfenster, Sonnenstrom; `?year=` (Standard Vorjahr), *(v3.6.0)* `?summary=1` nur die Zahl der Ergebniskarte — [Flexibilität](#flexibilität-bewerten-v330) |
| GET | `/api/intervals` | *(v3.3.0)* Index der Viertelstundenreihen (Verbrauch je Zähler, Börsenpreise) |
| DELETE | `/api/intervals/{id}` | *(v3.3.0)* eine Viertelstundenreihe löschen; Zählerstände und Tageswerte bleiben |
| GET | `/api/energy-flow/days` | *(v3.5.0)* Tage und Monate mit Viertelstunden des Netzbezugs, Vorbelegung für den Tagesfilm — [Tagesfilm](#tagesfilm-energiefluss-je-viertelstunde-v350) |
| GET | `/api/energy-flow/day` | *(v3.5.0)* Energiefluss eines Tages je Viertelstunde, `?date=JJJJ-MM-TT` — [Tagesfilm](#tagesfilm-energiefluss-je-viertelstunde-v350) |
| GET | `/api/energy-flow/typical-day` | *(v3.5.0)* typischer Tag eines Monats (Mittel je Viertelstunde), `?month=JJJJ-MM` — [Tagesfilm](#tagesfilm-energiefluss-je-viertelstunde-v350) |
| GET | `/api/recommendations` | statistische Empfehlungen |
| POST | `/api/recommendations/{id}/dismiss` | Empfehlung ausblenden; `{until?}`, sonst 30 Tage — seit v3.4.0 mit Frist (`evidence.deadline`) mindestens bis dahin; Antwort zusätzlich `until` und `activity_id` |
| DELETE | `/api/recommendations/{id}/dismiss` | Ausblenden zurücknehmen (v2.12.0); eine nicht ausgeblendete ID ist kein Fehler |
| GET | `/api/reminders` | Termine + Fälligkeitsstatus; seit v3.4.0 `last_done_by` (wer zuletzt erledigt hat, aus dem Verlauf); seit v3.5.0 je Termin `assignee`, `assignee_id`, `assignee_former` — [Zuständig](#zuständig-an-terminen-v350); *(v3.7.0)* `assignee_inherited` (Person des Zählers, wenn der Termin keine eigene hat) |
| POST | `/api/reminders` | anlegen; seit v3.5.0 optional `assignee` (Name) und `assignee_id` (Person); *(v3.7.0)* `utility` und `meter_id` (Termin am Zähler) |
| PATCH | `/api/reminders/{id}` | ändern; seit v2.12.0 auch `last_done` (Datum oder `null`) — für „Rückgängig" nach „Erledigt"; seit v3.5.0 `assignee`/`assignee_id` |
| DELETE | `/api/reminders/{id}` | löschen |
| POST | `/api/reminders/{id}/done` | erledigt, Recurrence fortschreiben; `{done_date?, note?, attachment_id?}` — seit v3.4.0 mit Eintrag im Verlauf, Antwort zusätzlich `activity_id` |
| GET | `/api/tenancies` | *(v3.1.0)* Mietverhältnisse, das jüngste zuerst — [Mietverhältnis](#mietverhältnis-v310) |
| POST | `/api/tenancies` | *(v3.1.0)* Mietverhältnis anlegen |
| PATCH | `/api/tenancies/{id}` | *(v3.1.0)* ändern; Listen (`prepayments`, `prices`, `fixed_costs`) jeweils ganz |
| DELETE | `/api/tenancies/{id}` | *(v3.1.0)* löschen samt Abrechnungen; deren Belege werden frei |
| GET | `/api/tenancies/{id}/budget` | *(v3.1.0)* Hilfsrechnung für den laufenden Abrechnungszeitraum: Vorauszahlung gegen erwartete Kosten; `?as_of=JJJJ-MM-TT` |
| GET | `/api/tenancies/{id}/statements` | *(v3.1.0)* Nebenkostenabrechnungen, die neueste zuerst |
| POST | `/api/tenancies/{id}/statements` | *(v3.1.0)* Abrechnung erfassen; `apply_prices`, `apply_prepayment` übernehmen Preise und neue Vorauszahlung |
| PATCH | `/api/tenancies/{id}/statements/{sid}` | *(v3.1.0)* Abrechnung ändern |
| DELETE | `/api/tenancies/{id}/statements/{sid}` | *(v3.1.0)* Abrechnung löschen; ihre Belege werden frei |
| GET | `/api/co2-costs` | *(v3.1.0)* CO₂-Preis im Brennstoff je Verbrauchsart, `?year=` (Standard Vorjahr) — [CO₂-Preis und Aufteilung](#co₂-preis-und-aufteilung-v310) |
| GET | `/api/co2-split` | *(v3.1.0)* CO₂-Kosten zwischen Mieter und Vermieter (CO2KostAufG), `?year=` |
| GET | `/api/reports/yearly.pdf` | PDF-Jahresbericht (Datei-Download; `?inline=1` zeigt ihn im Browser, v2.11.0) |
| GET | `/api/reports/yearly` | Jahresbericht als Daten für die Druckansicht (v3.1.0) |
| GET | `/api/reports/co2-split.pdf` | *(v3.1.0)* Anschreiben „Erstattung des Vermieteranteils“ bzw. Prüfergebnis als PDF, `?year=`, `?inline=1` |
| GET | `/api/reports/ev-charging` | *(v3.1.0)* Ladestrom-Nachweis für den Dienstwagen je Monat, `?meter_id=&year=&method=contract\|flat[&flat_ct=]` — [Ladestrom-Nachweis](#ladestrom-nachweis-v310) |
| GET | `/api/reports/ev-charging.csv` | *(v3.1.0)* dasselbe als CSV; `format`, `lang` wie bei den CSV-Exporten |
| GET | `/api/reports/ev-charging.pdf` | *(v3.1.0)* dasselbe als PDF mit Zählerständen und Unterschriftszeile; `?inline=1` |
| GET | `/api/ev-sessions` | *(v3.2.0)* gespeicherte Ladevorgänge aus evcc, neueste zuerst, `?meter_id=&year=`; mit beiden Parametern dazu Monatssummen `monthly` — [Ladevorgänge aus evcc](#ladevorgänge-aus-evcc-v320) |
| GET | `/api/agenda` | *(v3.1.0)* Fristen und Termine der nächsten `?days=` Tage (Standard 90) samt Überfälligem; Quelle für „Zu tun“ im Dashboard — [Agenda](#get-apiagendadays90-v310); seit v3.4.0 je Ereignis `occurrence`, `can_mark_done`, `done`, `title_key` |
| POST | `/api/agenda/done` | *(v3.4.0)* Hinweis abhaken `{uid, occurrence?, done_on?, note?, attachment_id?, cancelled?}`; `201` mit `{activity_id, entry, event}`, schon erledigt `200` mit `duplicate: true` — [Erledigt mit Verlauf](#erledigt-mit-verlauf-v340). Klasse C |
| GET | `/api/activity` | *(v3.4.0)* Verlauf erledigter Hinweise, neueste zuerst; `?from=&to=` (Tag des Erledigens), `?by=` (Personen-ID oder `person`, `key`, `integration`, `auto`, `none`), `?kind=` (kommagetrennt), `?ref=` (eine Kennung, etwa Vertrags-ID), `?limit=` (Standard 500, höchstens 5000). Klasse C |
| DELETE | `/api/activity` | *(v3.4.0)* Verlauf leeren: Einträge, die vor `?before=JJJJ-MM-TT` erledigt wurden, ohne Datum alle → `{deleted}` — nur Verwalter |
| GET | `/api/activity.csv` | *(v3.5.0)* der Verlauf als CSV zum Nachweis: Filter wie `GET /api/activity`, `?format=1\|local&lang=`; Spalten Datum, Aktion, Titel, Wer, Grund, Notiz; ohne Durchgestrichenes — [Verlauf als CSV](#verlauf-als-csv-v350). Klasse C |
| POST | `/api/activity/{id}/undo` | *(v3.4.0)* Rückgängig: streicht durch (`undone {at, by}`) und stellt zurück, was der Eintrag geändert hat → Eintrag mit `reverted` |
| PATCH | `/api/activity/{id}` | *(v3.4.0)* Notiz ändern `{note}` (bis 500 Zeichen) |
| GET | `/api/calendar.ics` | *(v3.1.0)* Dieselben Ereignisse als Kalender-Abo (iCalendar, 365 Tage) — [Kalender](#get-apicalendarics-v310); *(v3.7.0)* `?person=<id>` nur, was bei dieser Person liegt |
| GET | `/api/summary` | *(v3.1.0)* Kennzahlen je Zähler für Home Assistant und Skripte, `?utility=&meter=` — [Kennzahlen](#get-apisummary-v310) |
| GET | `/api/attachments` | *(v3.1.0)* Belege: Index und Speicherbelegung `usage` — [Belege](#belege-und-texterkennung-v310) |
| POST | `/api/attachments` | *(v3.1.0)* Beleg hochladen: Datei als roher Body, `?kind=reading_photo` (bzw. `bill_pdf`, `statement_pdf`, `other`), optional `&name=`; `201` mit dem Indexeintrag |
| GET | `/api/attachments/{id}` | *(v3.1.0)* Datei eines Belegs (Foto, PDF) |
| DELETE | `/api/attachments/{id}` | *(v3.1.0)* Beleg löschen; der Verweis am Datensatz verschwindet mit |
| POST | `/api/ocr/reading` | *(v3.1.0)* Zählerstand aus einem Ablesefoto lesen `{attachment_id}` — über den eigenen Texterkennungsdienst im Heimnetz, `502` bei Fehlern des Dienstes |
| GET | `/api/inbox` | *(v3.7.0)* Posteingang: Dokumente, Warteschlange, Anbieter — [Posteingang](#posteingang-v370) |
| POST | `/api/inbox` | *(v3.7.0)* PDF ablegen (roher Body, `?name=`, oder JSON `{name?, pdf_base64}`); `409 errors.inbox.duplicate` bei gleicher Prüfsumme; auch mit einem Schlüssel „Posteingang“ |
| GET | `/api/inbox/review` | *(v3.7.0)* Prüfliste: nur die markierten Felder |
| GET | `/api/inbox/draft` | *(v3.7.0)* Haushalt aus Dokumenten: Entwurf über alle ausgelesenen Dokumente (`?documents=` Auswahl) — [Entwurf](#haushalt-aus-dokumenten-v370) |
| POST | `/api/inbox/draft/apply` | *(v3.7.0)* Auswahl aus dem Entwurf übernehmen `{items, confirm?, choose?, documents?}` |
| POST | `/api/inbox/folder` | *(v3.7.0)* Überwachten Ordner `posteingang/` einlesen (nur Verwalter) — [Ordner](#ordner--post-apiinboxfolder) |
| POST | `/api/inbox/queue` | *(v3.7.0)* Dokumente zum Auslesen vormerken `{ids?}` |
| DELETE | `/api/inbox/queue` | *(v3.7.0)* Warteschlange abbrechen |
| GET | `/api/inbox/cloud` | *(v3.7.0)* Auslesen in der Cloud: Stand und Anbieter (Name, Ziel-Host, Hinweistext), nie der Schlüssel — nur `has_key` — [Auslesen in der Cloud](#get-apiinboxcloud--patch-apiinboxcloud-v370) |
| PATCH | `/api/inbox/cloud` | *(v3.7.0)* Auslesen in der Cloud speichern und einschalten (nur Verwalter) |
| GET | `/api/inbox/cloud/models` | *(v3.7.0)* Modelle des eingerichteten Cloud-Diensts, der Schlüssel bleibt auf dem Server (nur Verwalter) — [Modellliste](#get-apiinboxcloudmodels--get-apiinboxlocalcheck-v370-karte-auslesen) |
| GET | `/api/inbox/local/check` | *(v3.7.0)* „Verbindung prüfen“ für den eigenen Dienst im Heimnetz (nur Verwalter) |
| GET | `/api/inbox/{id}` | *(v3.7.0)* Vorschau: Auslesung mit Belegstellen, Zuordnung, Vorschläge mit Abgleich |
| PATCH | `/api/inbox/{id}` | *(v3.7.0)* Zuordnung, Korrekturen (`edits`), Art, Status, Notiz |
| DELETE | `/api/inbox/{id}` | *(v3.7.0)* Dokument löschen |
| POST | `/api/inbox/{id}/classify` | *(v3.7.0)* Art erkennen `{pages, consent?, fresh?}` |
| POST | `/api/inbox/{id}/extract` | *(v3.7.0)* Felder lesen und prüfen `{pages, consent?, fresh?}` |
| POST | `/api/inbox/{id}/apply` | *(v3.7.0)* Angehakte Vorschläge übernehmen `{proposals: [id]}`; danach die Kernaussage (`gist`), bei einer Rechnung samt Prüfung |
| POST | `/api/inbox/{id}/remind` | *(v3.7.0)* Termin zu einem abgelegten Dokument (Mahnung) `{date}` |
| GET | `/api/utility/{u}/dossier` | *(v3.7.0)* Akte eines Zählers (`?meter_id=`) oder Vertrags (`?contract_id=`) — [Akte](#akte--get-apiutilityudossier) |
| GET | `/api/export/{u}/monthly.csv` | Monatsaggregate als CSV; `?format=1` (Standard) oder `?format=local&lang=` *(v3.1.0)* — [CSV-Formate](#csv-formate-v310); *(v3.6.0)* `?period=billing`: eine Zeile je Abrechnungsperiode ([Abrechnungsperioden](#abrechnungsperioden-v360)) |
| GET | `/api/export/{u}/readings.csv` | Ablesungen als CSV (kumulativ); `format`, `lang` wie oben |
| GET | `/api/export/{u}/deliveries.csv` | **v1.4.2** Lieferungen als CSV (Heizöl/Pellets); `format`, `lang` wie oben |
| GET | `/api/export/{u}/periods.csv` | *(v3.1.0)* Verbrauch je Zeitraum als CSV, alle Zähler der Art; `format`, `lang` wie oben |
| GET | `/api/export/temperatures.csv` | Temperaturreihe als CSV; `format`, `lang` wie oben |
| GET | `/api/backup/export` | Voll-Backup JSON; seit v3.1.0 mit den Belegen (`attachment_files`), `?attachments=0` ohne die Dateien — [s. u.](#snapshots-und-import-v260); seit v3.3.0 immer mit den Viertelstundenreihen (`interval_files`) |
| POST | `/api/backup/import` | Backup zurückspielen; `?dry_run=1` prüft nur, `?allow_without_snapshot=1` s. 409; seit v3.2.0 nur Verwalter |
| POST | `/api/backup/snapshot` | Snapshot ablegen |
| GET | `/api/backup/snapshots` | Snapshots: Name, Größe, Zeitpunkt, Anlass *(v2.6.0)* |
| GET | `/api/backup/snapshots/{name}` | Snapshot herunterladen (Datei) *(v2.6.0)* |
| POST | `/api/backup/snapshots/{name}/restore` | Snapshot einspielen (vorher Sicherung des jetzigen Stands) *(v2.6.0)*; seit v3.2.0 nur Verwalter |
| DELETE | `/api/backup/snapshots/{name}` | Snapshot löschen *(v2.6.0)*; seit v3.4.0 nur Verwalter |
| GET | `/api/strom-saldo` | Strom-Saldo (Bezug − PV-Einspeisung), F1005 |
| GET | `/api/pv-summary` | PV-Eigenverbrauch + Autarkiequote, F1005; seit v2.10.0 über gemeinsam abgedeckte Monate, mit Ersparnis — s. u.; seit v3.1.0 Speicher, Amortisation, Annahme für ein Balkonkraftwerk und Hinweis zu § 51 EEG ([PV](#pv-speicher-balkonkraftwerk-amortisation-v310-additiv)); seit v3.4.0 Energiefluss je Monat und Jahr (`flow`) ([Schaubilder](#schaubilder-energiefluss-und-zeitstrahl-v340-additiv)) |
| GET | `/api/demo/status` | Demo-Daten verfügbar/Store leer? (F1007); seit v3.2.0 mit `personas` — [Beispielhaushalte](#beispielhaushalte-v320) |
| POST | `/api/demo/import` | Demo-Datensatz laden (F1007); seit v3.0.0 bis heute fortgeschrieben (Stände, Lieferungen, Temperaturen wie im Vorjahreszeitraum, Termine relativ zu heute); seit v3.2.0 `{persona}` für einen Beispielhaushalt, nur Verwalter |
| GET | `/api/auth/token` | API-Token-Status (nie der Token selbst), F1009; seit v3.2.0 nur Verwalter |
| POST | `/api/auth/token` | Token erzeugen (einmalig Klartext), F1009; seit v3.2.0 nur Verwalter |
| DELETE | `/api/auth/token` | Token widerrufen → API wieder offen, F1009; seit v3.2.0 nur Verwalter |
| **POST** | **`/api/ingest`** | **idempotenter Zählerstand-Push für Home Assistant (F1009); seit v3.1.0 auch als Stapel bis 500 Stände; seit v3.3.0 mit `timestamp` für Verbrauch je Viertelstunde** |

---

## 2. Ausgewählte Endpunkte im Detail

### `GET /api/readings-overview` *(F1004, v1.6.0)*

Aggregat-Endpunkt für die zentrale Zählerstand-Erfassung
(`#/zaehlerstaende`). Liefert in einem Roundtrip alle aktiven Zähler
der kumulativen Utilities (Gas/Strom/Wasser/Fernwärme) plus jeweils
die letzte reale (nicht-geplante) Ablesung als Validierungs-Baseline.
Delivery-Utilities (Heizöl/Pellets) sind ausgeschlossen — dort gibt
es keine Zählerstände, sondern Lieferungen.

```json
{
  "success": true,
  "data": {
    "rows": [
      {
        "utility": "gas",
        "utility_label": "Gas",
        "utility_icon": "🔥",
        "unit": "m³",              // Einheit des ZÄHLERSTANDS (seit v2.4.2)
        "consumption_unit": "kWh", // Einheit des VERBRAUCHS
        "color": "#f59e0b",
        "meter_id": "m_gas_main",
        "meter_name": "Hauptzähler Gas",
        "meter_icon": "🔥",
        "meter_notes": "Keller",
        "active_device_id": "d_gas_1",
        "last_reading": {
          "date": "2026-04-15",
          "counter": 12345.67,
          "is_estimated": false,
          "id": "20260415-3f2a9c1b",   // seit v2.6.0: „schon ein Stand heute → ersetzen"
          "device_id": "d_gas_1"       // seit v2.6.0: anderes Gerät → kein Rückgang
        },
        "expected_next_min": 12345.67,
        "typical_per_day": 4.2,        // seit v2.6.0: Median der letzten ≤ 10 Intervalle, null bei < 2
        "suspect_count": 0,            // seit v2.6.0: unbestätigte Verdachtsfälle (Home Assistant)
        "reading_count": 41,           // seit v2.13.0: Zahl der echten Stände (ohne geplante und verdächtige)
        "capture": "counter",          // seit v3.1.0: "counter" (Zählerstände) oder "period"
        "last_period": null,           // seit v3.1.0: letzter Zeitraum eines Zeitraum-Zählers
        "role": null                   // seit v3.1.0: Rolle des Zählers, null bei Arten ohne Rollen
      },
      {
        "utility": "waerme",
        "utility_label": "Heizwärme",
        "unit": "kWh",
        "consumption_unit": "kWh",
        "meter_id": "m_waerme_1",
        "meter_name": "Wärmezähler",
        "last_reading": null,
        "reading_count": 0,
        "capture": "period",
        "last_period": { "id": "p_3c9a…", "from": "2026-09-01", "to": "2026-09-30",
                         "value": 412.0, "value_unit": "consumption" },
        "role": "consumption",
        "…": "…"
      }
    ]
  }
}
```

**Seit v3.1.0** additiv: `capture` (Erfassungsart des Zählers, `counter` oder
`period`), `last_period` (`{id, from, to, value, value_unit}` des Zeitraums mit
dem spätesten Ende, sonst `null`) und `role` (Rolle des Zählers; `null` bei
Arten ohne Rollen — Gas, Fernwärme, PV-Einspeisung). Ein Zähler mit
`capture: "period"` hat keine Stände: Die Erfassung zeigt für ihn eine Karte
mit Monat und Verbrauch und speichert über `POST /api/utility/{u}/periods`
([Verbrauch je Zeitraum](#verbrauch-je-zeitraum-v310)).

**Seit v2.6.0** fließen verdächtige Stände (`is_suspect`) nicht in
`last_reading` ein — ein Home-Assistant-Push mit 0 wäre sonst die Basis der
nächsten Erfassung. `typical_per_day` trägt die Rückfrage „Das wären 400 kWh
am Tag, üblich sind 8". `reading_count` (seit v2.13.0) zählt die Stände, aus
denen ein Verbrauch entstehen kann: Einrichtungs-Checkliste und Leerzustände
fragen danach, ob es schon zwei gibt.

**`unit` gegen `consumption_unit` (seit v2.4.2, GitHub #21).** Ein Gaszähler
zählt Kubikmeter; kWh entsteht erst über den Umrechnungsfaktor. `unit` ist die
Einheit von `counter` und `expected_next_min`, `consumption_unit` die des
daraus berechneten Verbrauchs. Bis v2.4.1 fehlte `unit` in der Antwort, und
die Erfassungsmaske beschriftete den Gas-Zählerstand mit „kWh" — gespeichert
und gerechnet wurde immer in m³. Unter den kumulativen Verbrauchsarten ist Gas
die **einzige**, bei der die beiden Einheiten auseinanderfallen — Strom,
Fernwärme und PV zählen in kWh, Wasser in m³, jeweils identisch mit dem
Verbrauch. Deshalb fiel der Fehler nur bei Gas auf.

`expected_next_min` ist der Wert, gegen den die Frontend-Validierung
einen Rückwärts-Zählerstand warnt (nicht hart blockiert — Zählertausch
ist legitim). Speichern erfolgt **nicht** über diesen Endpunkt,
sondern pro Zeile über die bestehende Route
`POST /api/utility/{u}/readings`.

### `GET /api/utility/{u}/meters/{id}/consumption`

Monatsaggregate eines Zählers samt Regressionen und Anomalien. Felder je
Monat u. a.: `ym`, `days`, `kwh` *oder* `m3`, `cost`, `avg_temp`,
`hdd`, `temp_days`, `co2_kg`, `advance_eur`, `monthly_balance`,
`cumulative_balance` sowie Glättungen (`ma3`/`ma6`/`ma12`).

**Seit v3.1.0:**

- Wasserzähler mit der Rolle `warm` tragen je Monat additiv `dhw_kwh` — die
  Wärme für dieses Warmwasser als Rechenwert nach HeizkostenV § 9 Abs. 2,
  `2,5 × m³ × (dhw_temp_c − 10)`, 1 m³ bei 60 °C = 125 kWh — und
  `dhw_temp_c` (Einstellung `warmwasser_temp_c`, Standard 60). Kein Messwert;
  Zähler ohne diese Rolle bleiben unverändert.
- Ein Zähler mit `capture: "period"` rechnet aus seinen Zeiträumen statt aus
  Ständen: Wert ÷ Tage des Zeitraums, tagesgenau auf die Monate verteilt;
  Lücken bleiben Lücken (`days` zählt nur abgedeckte Tage). Die Felder sind
  dieselben, ebenso Verträge, Wetterbereinigung und Prognose.
- Heizwärme (`waerme`) ist HGT-relevant wie Gas und Fernwärme; `co2_kg`
  rechnet mit dem Faktor des Energieträgers aus `waerme_energietraeger`
  (ohne Angabe 0).
- Strom mit reduziertem Netzentgelt (§ 14a EnWG, Modul 1) trägt
  `grid_reduction_eur`; `base_price_eur` ist dann schon um sie gemindert
  ([Verträge](#verträge-reduziertes-netzentgelt-preismodell-gutschriften-v310-additiv)).
- Einspeisung mit Gutschriften des Direktvermarkters trägt `revenue_source`
  (`statement` oder `mixed`).
- Ein Zähler, der Mitglied einer Gruppe mit Gruppenvertrag ist, rechnet seinen
  Anteil daran; die ID einer Gruppe liefert die Summe der Mitglieder
  ([Gruppenvertrag](#gruppenvertrag-v310)).
- PV-Erzeugung und -Einspeisung rechnen `co2_kg` mit dem eigenen
  Vermeidungsfaktor `co2_pv_avoided`, wenn er gesetzt ist.

**Seit v3.7.0 (GitHub #37, #38, #39), additiv** — gemessen oder verteilt
([Grundlagen §1](../verstehen/00-overview.md#gemessen-oder-verteilt-seit-v370)):

| Feld je Monat | Bedeutung |
|---|---|
| `measured_share` | Anteil des Monatsverbrauchs aus Ablesungsabständen bis 45 Tage (0 … 1); geschätzte Ablesungen messen nicht |
| `interpolated` | `true`, wenn `measured_share` unter 0,5 liegt: Der Wert ist aus einem längeren Abstand gleichmäßig verteilt. Heizöl und Pellets: `null` |
| `reading_gap_days` | längster Ablesungsabstand, aus dem der Monat stammt (`null`, wenn auf einer Seite eine echte Ablesung fehlt) |
| `reading_gap_estimated` | *(v3.7.0)* der Monat ist an einer geschätzten Ablesung als Stütze verteilt (je Seite eine eigene Tagesrate) |

Verteilte Monate gehen in keine Anomalie, Empfehlung R1/R3, Heizkurve
(`regression_point: false`) und kein Heizmodell ein; die Monatswerte, Kosten,
Abschläge und der Saldo bleiben unverändert. `monthly_total` der Art trägt
dieselben Felder, gewichtet nach Verbrauch. Dazu auf oberster Ebene:

```json
"interpolation": {                 // null bei Heizöl und Pellets
  "max_gap_days": 45,
  "months": 36, "interpolated_months": 36, "interpolated": ["2023-06", "…"],
  "readings": 12, "readings_by_year": { "2023": 3, "2024": 4, "2025": 4, "2026": 1 },
  "from": "2023-06", "to": "2026-05",
  "heat_basis": "intervals",        // months | intervals | null (kein Heizmodell)
  "heat_points": 11
},
"anomaly_quality": {               // null ohne Heizmodell oder unter 5 geprüften Monaten
  "model_quality": "poor",          // good | poor (eine der Prüfungen in reasons)
  "reasons": ["scatter", "base_load"],
  "ratio": 0.31, "poor_above": 0.15, // resid_scale / mean_month
  "resid_scale": 210.4, "mean_month": 678.7, "typical": 680.0,
  "base_load": {                    // null unter 2 gemessenen Sommermonaten
    "model_per_day": 0.4, "measured_per_day": 6.1, "ratio": 0.066,
    "allowed": [0.5, 1.5], "months": 4
  },
  "threshold_sigma": 2.0,
  "detect_floor": 420.8, "unit": "kWh",
  "months": 29
}
```

`readings` zählt echte Ablesungen, die in die Rechnung eingehen (ohne
geschätzte und ohne übergangene), bei Zählern mit Zeiträumen die echten
Zeiträume. `heat_basis: "intervals"`: Unter 8 gemessenen Monaten rechnet das
Heizmodell über die Abstände zwischen echten Ablesungen
([Grundlagen §5](../verstehen/00-overview.md#heizmodell)). `detect_floor` ist die
Abweichung, die ein typischer Monat mindestens braucht, um gemeldet zu werden
([Grundlagen §9](../verstehen/00-overview.md#9-anomalien)).

**Seit v3.7.0 (Abgleich mit echten Daten), alles additiv:**

| Wo | Feld | Bedeutung |
|---|---|---|
| `…/meters/{id}/consumption?heat_limit=1` | `heat_limit` | Heizgrenze-Vorschlag des Zählers: `{current, current_ratio, current_ok, best, best_ratio, suggest, default, own, months, grid: [{base, ratio, base_load_ok, summer_ratio, summer_ok}]}`, `null` ohne Heizmodell, unter 12 gemessenen Monaten oder ohne den Parameter ([Grundlagen §5](../verstehen/00-overview.md#heizgrenze-vorschlag-seit-v370)). *(v3.7.1)* `summer_ratio`: Erwartung ÷ gemessener Sommer (dieselben Monate für jede Grenze), `summer_ok`: höchstens 1,10 — Bedingung für einen Vorschlag |
| `anomaly_quality.base_load` | `reference`, `reference_months` | *(v3.7.1)* woher die Sommermonate der Grundlast-Prüfung kommen: `no_hdd_top` (ohne Heizgradtage zu 18 °C) oder `window` (Juni bis August), und welche es sind |
| `monthly[]` | `hdd_top` | *(v3.7.1)* Heizgradtage des Monats zu 18 °C, unabhängig von der Heizgrenze (Sommer-Referenz) |
| `GET …/meters/{id}/forecast` | `?hdd_base=` | *(v3.7.1)* Heizgrenze für diese Prognose (6 bis 22 °C) wie bei `…/consumption`, ohne sie am Zähler zu speichern; außerhalb: 400 |
| `PATCH …/meters/{id}`, Zähler | `hdd_base_temp` | eigene Heizgrenze dieses Zählers in °C (6 bis 22, nur Heizarten mit Zählerständen); `null` oder leer entfernt sie, dann gilt die Einstellung |
| `monthly[]` | `heat_adjusted_basis` | warum `heat_adjusted` so ist: `model`, `summer`, `base_load`, `normal_is_actual` (oder `null`) |
| `regressions.*`, `forecast.regression` | `reason` | bei `valid: false`: `too_few_points`, `poor_fit`, `degenerate` |
| `…/contract-status` | `projection_uncertain` | `{reasons: [factor_clamped \| few_measured \| poor_fit], factor, factor_raw, factor_part, factor_limits, fit_ratio, base_load_per_day, projected_range, suggested_range}` oder `null`; dann ist `suggested_advance` `null`. `factor_part`: welcher Faktor klemmt (`base`, `heat`, `both`); `base_load_per_day`: `{model, measured}`, wenn die Spanne die gemessene Sommer-Grundlast nimmt; `suggested_range` `null`, wenn beide Enden gleich wären |
| `…/contract-status` | `projection_factor_base`, `projection_factor_heat` | Kalibrierung getrennt für Grundlast und Heizanteil (0,5–1,5; `projection_factor_heat` `null` ohne Heizmodell); `projection_factor` ist seit v3.7.0 der wirksame Faktor |
| `…/contract-status` | `superseded_on` | letzter Tag vor dem Folgevertrag, der diesen Vertrag ablöst (sonst `null`); `end` bleibt das gespeicherte Ende |
| `…/contract-status`, `…/billing-periods` | `price_missing` | Vertrag ohne Arbeitspreis: Kosten und Saldo ohne Verbrauchskosten |
| `…/forecast` | `fit_quality` | `{model_quality, ratio, reasons}` der Gütesperre (oder `null`); bei `poor` `annual.low/high = null`, `annual.withheld = "poor_fit"`, Monate ohne Band |
| `…/forecast` | `annual.withheld` | warum das Jahr ohne Band ist: `poor_fit`, `few_measured` (Heizmodell nur über die Ablesungsabstände oder ungeprüft), `few_years` (ohne Wetterbezug weniger als 3 Freiheitsgrade der Streuung) |
| `…/forecast` | `warnings[]` | neu `poor_fit {ratio, reasons}`, `few_measured {heat_basis}`, `regression_invalid {model, reason, n, r2}`, `climate_normal_grid {base, min, max, hdd_source}` |
| `POST /api/forecast/scenario` | `advance.uncertain` | bei nicht bestandener Gütesperre oder zu wenig Gemessenem `{reasons: [few_measured \| poor_fit], fit_ratio, spread, base_range, scen_range}`, dann `base_/scen_recommended: null`; `warnings[]` neu `poor_fit`, `few_measured`, `basis.heat_share_year: null` |

**Seit v2.8.0** zählen `hdd` nur die Tage mit Verbrauch; `temp_days` sagt,
für wie viele davon Temperaturen vorliegen. Bei HGT-relevanten Arten mit
Zählerständen (Gas, Fernwärme, seit v3.1.0 Heizwärme) kommen die Felder des Heizmodells dazu
(Formeln in [Grundlagen §5](../verstehen/00-overview.md#5-wetterbereinigung--mehr-verbraucht-oder-nur-kälter)):

| Feld | Bedeutung |
|---|---|
| `regression_point` | `true`, wenn der Monat in die Heizkurve eingeht (eine Regel für Analyse, Bereinigung und Prognose) |
| `expected_heat` | Erwartung aus `a × HGT + c × Tage` für genau diesen Monat |
| `weather_delta_pct` | Mehr- oder Minderverbrauch in % bei gegebenem Wetter; `null` bei Teilmonaten |
| `hdd_normal` | Heizgradtage eines Normaljahrs für dieselben Tage (Klimanormal, sonst eigene Historie) |
| `heat_adjusted` | witterungsbereinigter Verbrauch: `Ist + a × (hdd_normal − hdd)`, mindestens die Grundlast — umgerechnet wird nur der Wettereinfluss laut Modell |

**Veraltet (Deprecated seit v2.8.0, entfallen mit v3.0.0):** `expected_hgt`,
`weather_adjusted`, `delta_pct`. Sie werden unverändert weiter geliefert.
`weather_adjusted` skalierte auch die Grundlast mit dem HGT-Verhältnis,
`delta_pct` verglich mit dem Mittel aller Monate und maß damit die
Jahreszeit — Nachfolger sind `heat_adjusted` und `weather_delta_pct`.

`regressions` enthält alle fünf Modelle mit `r2`/`n`/`valid`, seit
v2.8.0 dazu `curve` (Punkte `{x, y}` der Kurve bis zum größten HGT-Wert, vom
Backend gerechnet) und beim linearen Modell `se_a` (Standardfehler der
Steigung). Das Knickmodell ist stetig: `split` ist der Knick,
`base.b` der Sockel, `heat.a` die Steigung darüber. Für Heizöl und Pellets
bleibt `regressions` leer und `regressions_note` ist `"delivery_modelled"`:
Ihre Monatswerte sind nach Gradtagen verteilt, eine Kurve darüber wäre ein
Zirkelschluss.

**Seit v2.4.0 (F1011)** trägt jeder Monat zusätzlich `pre_baseline`
(`true` = liegt vor der Analyse-Zäsur des Zählers). Solche Monate bleiben
in der Antwort, gehen aber in **keine** Auswertung ein: `expected_heat`,
`weather_delta_pct` und `heat_adjusted` sind für sie `null` (das Heizmodell
beschreibt das Gebäude nach der Maßnahme), die Regressionen lassen sie aus.
Den Vorher/Nachher-Vergleich trägt `baseline_comparison`.

Dazu zwei neue Felder auf oberster Ebene:

```json
"baseline": {
  "active_from": "2021-09-01",     // null = keine Zäsur wirksam
  "active_label": "Dachdämmung",
  "first_month": "2021-10",        // erster voller Monat danach
  "events": [ { "date": "2021-09-01", "label": "Dachdämmung" } ],
  "months_total": 144, "months_after": 58, "points_after": 41,
  "limits": [                       // was gerade nicht gerechnet werden kann
    { "key": "weather_adjustment", "need": 12, "have": 144, "ok": true },
    { "key": "regression",         "need": 8,  "have": 41,  "ok": true },
    { "key": "anomalies",          "need": 5,  "have": 58,  "ok": true }
  ]
},
"baseline_comparison": {            // null, wenn eine Epoche zu dünn ist
  "before": { "slope": 0.42, "base": 11.8, "r2": 0.97, "points": 63, "se": 0.011 },
  "after":  { "slope": 0.28, "base": 12.1, "r2": 0.98, "points": 41, "se": 0.009 },
  "delta_pct": -33.3, "unit": "kWh",
  "significant": true,               // seit v2.8.0
  "delta_pct_ci95": [-38.5, -28.1]   // seit v2.8.0
}
```

`slope` ist der Verbrauch **je Gradtag** und damit bereits
witterungsbereinigt; `delta_pct` ist die Wirkung der Maßnahme. Seit v2.8.0
sagt `significant`, ob der Unterschied belegt ist (Test der
Steigungsdifferenz, |z| ≥ 1,96), und `delta_pct_ci95` gibt den 95-%-Bereich
der Änderung; `se` ist der Standardfehler der jeweiligen Steigung.
`limits` wird auch **ohne** Zäsur befüllt — eine zu kurze Historie wird
damit erklärt, statt eine Auswertung wortlos ausfallen zu lassen.

**Ohne Heizkurve *(v3.1.0, additiv)*.** Bis v3.0 gab es `baseline_comparison`
nur für Arten mit Heizgradtagen. Seit v3.1.0 vergleicht die App auch Strom,
Wasser und PV vor und nach der Zäsur — über das **Tagesmittel je
Kalendermonat**, nur über Kalendermonate, die es in beiden Phasen gibt
(mindestens drei, Monate mit mindestens `min_days_period` Tagen). Die
Heizkurven-Variante trägt seitdem additiv `method: "hdd_slope"`.

```json
"baseline_comparison": {
  "method": "seasonal_mean",
  "before": { "per_year": 2810.4, "months": 9 },
  "after":  { "per_year": 2395.0, "months": 9 },
  "delta_pct": -14.8, "delta_per_year": -415.4,
  "delta_pct_ci95": [-19.6, -10.0], "significant": true,
  "months_compared": 9, "unit": "kWh"
}
```

`per_year` rechnet die verglichenen Monate auf ein Jahr hoch; `delta_pct_ci95`
kommt aus der Streuung der Monatsverhältnisse nachher ÷ vorher, `significant`
heißt: Der Bereich schließt 0 nicht ein. Beispiel: Zäsur „Balkonkraftwerk in
Betrieb“ am Strombezug ([Szenario Wohnung](../verstehen/07-szenario-wohnung.md#7-sonderfall-balkonkraftwerk)).

**Seit v2.6.0** zusätzlich `warnings` — Stände, die nicht oder nur mit
Vorbehalt in die Rechnung eingehen:

```json
"warnings": [
  { "type": "suspect",  "reading_id": "20260920-5c425cf2", "date": "2026-09-20", "counter": 0 },
  { "type": "outlier",  "reading_id": "20260220-dabada46", "date": "2026-02-20", "counter": 13300, "kind": "spike" },
  { "type": "decrease", "reading_id": "…", "date": "…", "counter": 5.0,
    "previous": { "date": "…", "counter": 18432.5 } }
]
```

| `type` | Bedeutung | in der Rechnung? |
|---|---|---|
| `suspect` | Home Assistant hat einen kleineren Stand als den vorigen geliefert; wartet auf Bestätigung (`PATCH …/readings/{id}` mit `is_suspect: false`) | nein |
| `outlier` | eingeklemmter Ausreißer desselben Geräts: `kind` = `spike` (nach oben) oder `dip` (nach unten) | nein |
| `decrease` | Stand fällt, ohne dass sich ein Ausreißer bestimmen lässt — Zählertausch oder Überlauf nicht erfasst? | das negative Intervall nicht |

Bis v2.5.3 verwarf die Rechnung nur das negative Intervall und zählte das
folgende ab dem falschen Stand voll: Ein einziger Wert 0 machte aus 190 kWh
im Monat 50.270 kWh. Ein **Überlauf** (99.998 → 12) wird richtig gerechnet,
wenn am Gerät `digits` (Stellen des Zählwerks) gepflegt ist.

### `GET /api/utility/{u}/meters/{id}/contract-status` — Saldo nach Kalender *(v2.8.0)*

Für Verträge mit Abschlägen (Gas, Strom, Fernwärme) rechnet der Saldo bis
**heute**, wie die Jahresabrechnung: Abschläge nach Zahlungsplan, Grundpreis
tagesgenau, der Verbrauch seit der letzten Ablesung geschätzt. Neue Felder je
Vertrag (additiv):

| Feld | Bedeutung |
|---|---|
| `balance_as_of` | Stichtag der Rechnung (heute, begrenzt auf die Vertragslaufzeit) |
| `projection_method` | `forecast` (Heizmodell bzw. Saisonprofil) oder `flat_average` |
| `measured_until` | Datum der letzten gültigen Ablesung |
| `cost_to_date` | Kosten bis zum Stichtag = `energy_cost_to_date + base_to_date - bonus_to_date` |
| `energy_cost_to_date` | Arbeitspreis × Verbrauch, gemessen plus geschätzt |
| `base_to_date` | Grundpreis tagesgenau bis zum Stichtag |
| `bonus_to_date` | Boni mit Gutschrift bis einschließlich des laufenden Monats |
| `estimated_cost_to_date` | davon geschätzt: Arbeitspreis für die Zeit seit `measured_until` |
| `estimated_cost_remaining` | geschätzte Kosten vom Stichtag bis Vertragsende |
| `advance_remaining` | noch fällige Abschläge bis Vertragsende |
| `suggested_advance` | Abschlag, der den erwarteten Saldo bis Vertragsende ausgleicht (≥ 0), sonst `null` |
| `projection_factor` | Kalibrierung der Schätzung am jüngsten Niveau (0,5–1,5); seit v3.7.0 der wirksame Faktor aus `projection_factor_base` und `projection_factor_heat` |
| `balance_path` | *(v2.16.0)* nur beim laufenden Vertrag, sonst `null`: dieselbe Rechnung als Monatsreihe vom Vertragsbeginn (seit v3.6.0 ab `balance_from`) bis zum Ende bzw. zur nächsten Abrechnung — je Monat `ym`, aufsummiert `cost` (gemessen + geschätzt + Grundpreis − Boni) und `paid` (Abschläge nach Plan − Sonderzahlungen netto), `balance = cost − paid`, `estimated` (enthält Tage nach der letzten Ablesung), `future` (Monat nach heute). Der letzte Punkt ist `projected_end_balance` |

**Geänderte Werte:** `advance_paid` zählt seit v2.8.0 die Abschläge nach
Kalender bis einschließlich des laufenden Monats (vorher nur Monate mit
Ablesung); `current_balance = cost_to_date − advance_paid +
special_payment_net`, `projected_end_balance = current_balance +
estimated_cost_remaining − advance_remaining`. `actual_cost`,
`actual_kwh_cost` und `actual_base_total` beschreiben weiterhin nur die
gemessenen Monate.

**Saldo nach der letzten gebuchten Rechnung *(v3.6.0)*:** Läuft ein
laufender Vertrag über eine erfasste Versorgerrechnung hinaus, deren Ergebnis
als Rück- oder Nachzahlung im Vertrag gebucht ist (Gas, Strom, Wasser,
Fernwärme), beginnen Saldo, Kosten, Abschläge und `balance_path` am Tag nach
dieser Rechnung. Die gebuchten Ergebnisse der Rechnungen zählen dann nicht
mehr mit, sie gleichen den Zeitraum der Rechnung aus; Sonderzahlungen davor
ebenso. `special_payments` und die Summen `special_*` nennen nur die
Zahlungen, die zählen. Neues Feld (additiv):

| Feld | Bedeutung |
|---|---|
| `balance_from` | ab wann Saldo, Kosten und Abschläge zählen: Vertragsbeginn oder der Tag nach der letzten gebuchten Rechnung des Zählers (bei einer Gruppe: ihrer Mitglieder) |

Ohne gebuchte Rechnung nach Vertragsbeginn bleibt alles wie bisher. Bis v3.5
begann der Saldo immer am Vertragsbeginn; bei einem Vertrag über mehrere
Jahre blieb jedes Jahr ohne gebuchten Ausgleich im Saldo stehen.

### Verträge tagesgenau, Kündigungsstichtag *(v2.9.0)*

**Vertragsfelder (additiv, optional):** `notice_period_days` (0–730, Vorrang
vor `notice_period_months`), `notice_mode` (`term_end` | `month_end` |
`any_day`, `null` = automatisch) und `auto_renews` (`false` = gekündigt).
Ungültige Werte → 400 `errors.contract.noticeDaysOutOfRange` bzw.
`errors.contract.noticeModeInvalid`. Bedeutung:
[Datenmodell](datenmodell.md).

**Monatszeilen** (`…/consumption`, Gas/Strom/Fernwärme/Heizöl/Pellets/PV):
Vertrag und Preise gelten ab ihrem Tag. `contract_id` ist der Vertrag mit
den meisten Tagen im Monat; neu sind `contract_assumed` (`true` = der
Vertrag ist abgelaufen und läuft ohne Nachfolger weiter) und — nur bei mehr
als einem Vertrag im Monat — `contract_parts[]` mit je `contract_id`,
`days`, `kwh`, `kwh_cost`, `base_price_eur`, `advance_eur`, `bonus_eur`,
`cost`, `assumed`. Die Summen der Monatszeile sind die Summen der Teile.
Wasser bleibt beim Vertrag des Monatsersten. *(v3.7.0, additiv)* Monate mit
Bonus tragen `bonus_items[]` — je Bonus `credit_date`, `amount_eur`, `type`
(`sofort`, `wechsel`, `neukunde`, `sonstige`) und `label` (Notiz); ihre Summe
ist `bonus_eur`. In `monthly_total` der ganzen Art stehen dann auch
`bonus_eur` und `bonus_items`. `cost` bleibt nach Abzug des Bonus; die
Oberfläche zeigt in der Kachel „Kosten“ `cost + bonus_eur` und den Bonus als
eigene Zeile.

**`contract-status`, neue Felder je Vertrag:**

| Feld | Bedeutung |
|---|---|
| `renewed` | abgelaufen, ohne Nachfolger, nicht gekündigt: läuft weiter (`is_current: true`); der Saldo rechnet bis zur nächsten Abrechnung |
| `cancel_by` | letzter Tag für die Kündigung; bei jederzeit kündbaren und weiterlaufenden Verträgen heute |
| `days_to_cancel` | Tage bis `cancel_by` (negativ = vorbei) |
| `switch_date` | frühester Tag beim neuen Anbieter |
| `notice_basis` | `fixed_end`, `open_ended`, `min_term`, `renewed` oder `unknown` (keine Frist gepflegt) |
| `remind_basis` | `cancel_by` oder `end` — worauf sich `remind_stage` und `should_remind` beziehen; `null` bei offenen und weiterlaufenden Verträgen (keine Stufen-Erinnerung) |
| `cancel_missed` | Stichtag verstrichen, der Vertrag läuft über sein Ende hinaus |
| `price_increase` | nächste eingetragene Erhöhung: `{from, working_price_ct: [alt, neu] \| null, base_price_eur: [alt, neu] \| null}`, sonst `null` |

Ein weiterlaufender Vertrag ist höchstens mit einem Monat Frist kündbar
(§ 309 Nr. 9 BGB); `switch_date` rechnet mit dem kürzeren von eigener Frist
und einem Monat.

**Geänderte Werte:** Monate mit einem Vertragswechsel oder einer
Preisänderung zur Monatsmitte, Abschläge im ersten und letzten Vertragsmonat
(anteilig) und Verbrauch nach einem Vertragsende ohne Nachfolger (bisher
0 €). `days_until_end` zählt weiter bis zum Vertragsende; `remind_stage` und
`should_remind` beziehen sich bei gepflegter Frist auf `cancel_by`
(`remind_basis: "cancel_by"`).

**`tariff-switch`:** Der Block `current` trägt zusätzlich
`notice_period_days`, `notice_mode` und `renewed`; ein weiterlaufender
Vertrag bildet die Bindungskette, statt „kein laufender Vertrag" zu melden.

**`tariff-comparison` (Rückblick):** Echte Verträge zeigen, was die Rechnung
gebucht hat (bei zwei Verträgen im Monat nur ihren Teil). Schattenverträge
gelten als Preisblatt für **alle** Monate des Zeitraums, vor ihrem ersten
Preiseintrag mit dessen Preis — bis v2.8 nur für ihre Laufzeit, womit ein
Sommerangebot ohne Winter billiger aussah. Seit v2.12.0 trägt die Antwort
`years`: die Jahre mit Verbrauchsdaten, neueste zuerst — auch dann, wenn das
gewählte Jahr leer ist, damit die Jahresauswahl bedienbar bleibt.

**`PATCH /api/settings`:** `billing_cycle_anchor_*` muss ein Kalendertag
`MM-TT` sein, sonst 400 `errors.settings.valueInvalid`.
`min_temp_days_forecast` und `baujahr` sind **veraltet** (ohne Wirkung,
entfallen mit v3.0.0); sie werden weiter geliefert und angenommen. Seit v2.13.0
gilt das auch für `billing_cycle_anchor_heizoel` und `…_pellets`; neu ist
`billing_cycle_anchor_pv_einspeisung` (Default `01-01`).

### Verträge: Fernwärme-Fixkosten, `warnings` *(v3.1.0, additiv)*

**Fernwärme** (CALC-31) — fünf optionale Felder am Vertrag, nur bei
`fernwaerme`:

| Feld | Bedeutung | Grenzen |
|---|---|---|
| `capacity_kw` | Anschlussleistung in kW | 0–100.000 |
| `capacity_prices` | Leistungspreis, datierte Liste `[{from, eur_per_kw_year}]` | wie die anderen Preislisten |
| `metering_prices` | Messpreis, datierte Liste `[{from, eur_per_year}]` | wie die anderen Preislisten |
| `co2_g_per_kwh` | Emissionsfaktor des Wärmenetzes in g CO₂ je kWh | 0–2000 |
| `primary_energy_factor` | Primärenergiefaktor, nur gespeichert und angezeigt | 0–5 |

`null` oder leer entfernt einen Wert; Dezimalkomma wird angenommen. Ein
ungültiger Wert → `400` `errors.contract.valueInvalid`, ein Leistungspreis ohne
Anschlussleistung → `400` `errors.contract.capacityMissing`.

```text
feste Kosten je Monat = Grundpreis + capacity_kw × Leistungspreis / 12 + Messpreis / 12
Beispiel ohne Grundpreis: 10 kW × 60 / 12 + 120 / 12 = 60 € je Monat
```

Die festen Kosten rechnen überall wie der Grundpreis — Monatszeilen (`…/consumption`),
`contract-status` samt Saldo, Prognose und Rechnungsprüfung —, nach
Kalendertagen des Monats; Stichtage der beiden Listen teilen Abschnitte wie
eine Preisänderung. `co2_g_per_kwh` ersetzt für die Monate des Vertrags den
Wert `co2_fernwaerme` in `co2_kg` und ist die Grundlage des CO₂-Preises der
Fernwärme ([CO₂-Preis und Aufteilung](#co₂-preis-und-aufteilung-v310)).
Schattenverträge zählen dafür nicht.

**`warnings`** — `POST` und `PATCH` eines Vertrags (jeder Art) liefern den
gespeicherten Vertrag, seit v3.1.0 additiv mit `warnings`, wenn es etwas
anzumerken gibt. Heute ein Wert: `term_over_24_months` — die Mindestlaufzeit
(`min_term_end`) endet mehr als 24 Monate nach Vertragsbeginn; eine so lange
Erstlaufzeit ist für Verbraucher in Deutschland unwirksam (§ 309 Nr. 9 BGB).
Gespeichert wird der Hinweis nicht, der Vertrag schon; `GET` liefert ihn ebenso
wenig. Die Oberfläche zeigt ihn nach dem Speichern.

```json
{ "success": true, "data": { "id": "c_strom_004", "start": "2026-11-01",
  "min_term_end": "2029-10-31", "…": "…", "warnings": ["term_over_24_months"] } }
```

### Gruppenvertrag *(v3.1.0)*

Ein Vertrag kann seit v3.1.0 statt einem Zähler einer **Zählergruppe**
gehören ([#17](https://github.com/Bingerminger/energietracker/issues/17)) —
etwa ein Doppeltarifzähler mit Hoch- und Niedertarif (HT/NT), dessen zwei
Zählwerke als zwei Zähler in einer Gruppe stehen. Nur bei Arten mit
Abschlagsverträgen: Gas, Strom, Fernwärme.

| Feld | Bedeutung |
|---|---|
| `meter_group_id` | Ziel des Vertrags; dann ist `meter_id` `null`. Die Gruppe muss zur Art gehören und Mitglieder haben, sonst `400` `errors.contract.targetInvalid` |
| `working_prices_by_meter` | optional `{zähler_id: [{from, ct_per_kwh}]}` — eigener Arbeitspreis je Mitglied (HT/NT). Fehlt ein Mitglied, gilt `working_prices`. Preise für einen Zähler außerhalb der Gruppe → `errors.contract.targetInvalid` |

`PATCH` mit `meter_group_id` hängt einen Vertrag auf eine Gruppe um, `PATCH`
mit `meter_id` zurück auf einen Zähler (dann entfallen `meter_group_id` und
`working_prices_by_meter`). `GET …/contracts?meter_id=<gruppen-id>` liefert die
Verträge der Gruppe.

**So rechnet die App.** Jedes Mitglied rechnet seinen Verbrauch zu seinem
Arbeitspreis. Grundpreis, Abschläge und Boni trägt nur das **erste Mitglied**
in der Reihenfolge der Zählerliste — so zählen sie genau einmal, und jede Summe
über Zähler (Verbrauchsart, Übersicht, PDF, CSV, Effizienz) stimmt ohne
Sonderweg.

```text
Beispiel (Spezifikation): HT 2.000 kWh × 30 ct + NT 1.000 kWh × 22 ct + 12 × 12 € Grundpreis
                          = 600 € + 220 € + 144 € = 964 € im Jahr
```

**Keine Doppelung.** Ein Mitglied darf im Zeitraum eines Gruppenvertrags keinen
eigenen echten Vertrag haben, und umgekehrt: `400`
`errors.contract.groupMemberOverlap` — den alten Vertrag vorher beenden.
Schattenverträge auf der Gruppe sind erlaubt. Eine Gruppe, an der Verträge
hängen, lässt sich nicht löschen (`errors.meter.groupHasContracts`).

**Auswertungen der Gruppe** (Summe der Mitglieder) liefern die Routen
`GET /api/utility/{u}/meter-groups/{id}/consumption`, `…/contract-status`,
`…/forecast`, `…/tariff-switch` und `…/bill-check` (Klasse B) in derselben Form
wie für einen Zähler; die Zähler-Routen `…/meters/{id}/…` nehmen die
Gruppen-ID ebenfalls an. Unterschiede:

- **Mischpreis.** Hochrechnung im Saldo, Prognose und Wechselentscheidung
  rechnen mit einer Gesamtmenge. Bei Preisen je Mitglied nehmen sie einen
  Mischpreis, gewichtet mit dem Verbrauch der Mitglieder in ihren letzten zwölf
  Monaten; der Vertrag trägt dort `working_prices_blended: true`.
- **Rechnungsprüfung.** Die Abschnitte der Mitglieder stehen nacheinander, jede
  Zeile mit `meter_id` und `meter_name`; die festen Kosten zählen einmal (beim
  ersten Mitglied). Die Antwort trägt `group_id`.
- **Mitglieder.** `contract-status` eines Mitglieds trägt additiv
  `group_contract: {group_id, contract_id}` — der Saldo steht beim
  Gruppenvertrag.

Agenda, Kalender und Empfehlungen (Kündigungsstichtag, Vertragsende,
Preiserhöhung) laufen auch für Gruppenverträge. Hintergrund:
[Meter-Topologie](../verstehen/13-meter-topologie.md#gruppenvertrag-v310).

### Verträge: reduziertes Netzentgelt, Preismodell, Gutschriften *(v3.1.0, additiv)*

**§ 14a EnWG, Modul 1** — nur Strom: `grid_reduction: [{from, eur_per_year,
module: 1}]`, die jährliche Reduzierung des Netzentgelts für eine steuerbare
Verbrauchseinrichtung (Wärmepumpe, Wallbox). Sie wirkt tagesgenau als Abzug
von den festen Kosten:

```text
feste Kosten je Monat = Grundpreis − Reduzierung / 12      Beispiel: 120 €/a → 10 € je Monat
```

Monatszeilen (`…/consumption`) tragen dann additiv `grid_reduction_eur`;
`base_price_eur` ist der Grundpreis abzüglich der Reduzierung. In der
Wechselentscheidung bleibt sie außen vor — sie gilt für jeden Lieferanten
gleich. Modul 2 (eigener Zähler) braucht kein Feld: ein Subzähler mit eigenem
Vertrag ([Strom](../verstehen/02-strom.md#steuerbare-verbraucher-v310)).

**Preismodell** — `price_model` an jedem Vertrag außer Wasser:

| Wert | Bedeutung |
|---|---|
| fehlt, `fixed` | Preise mit Stichtag wie bisher; `fixed` wird nicht gespeichert |
| `monthly` | ein echter Vertrag mit Preisen je Monat (etwa ein dynamischer Tarif, wie er abgerechnet wurde); gesetzt vom Monatspreis-Import |
| `dynamic` | **nur ein Schattenvertrag Strom** für den Dynamik-Check, mit `dynamic: {markup_ct_per_kwh, base_eur_month, vat_pct, weighting}` — sonst `400` `errors.contract.dynamicShadowOnly` |

Ein unbekannter Wert → `400` `errors.contract.priceModelInvalid`. In `dynamic`
gelten Aufschlag 0–100 ct/kWh, Grundpreis 0–1000 € je Monat, Umsatzsteuer
0–30 % (Standard 19); `weighting` ist immer `flat`. Wie daraus Monatspreise
werden: [Börsenstrompreise und Dynamik-Check](#börsenstrompreise-und-dynamik-check-v310).

**Monatspreise aus einer Datei** — `POST
/api/utility/{u}/contracts/{id}/prices/import-csv[?dry_run=1]`, Körper
`text/plain`, je Zeile `monat;ct_kwh[;grundpreis]` (Monat als `MM.JJJJ`,
`MM/JJJJ` oder `JJJJ-MM`; Kopfzeile erlaubt). Jede Zeile wird ein Arbeitspreis
(und Grundpreis in € je Monat) ab dem Monatsersten; Einträge mit demselben
Stichtag werden ersetzt, das Preismodell wird `monthly`. Antwort `{months,
from, to, base_prices, errors[{line, text}]}`, im Trockenlauf dazu
`would_import`. Keine lesbare Zeile → `400` `errors.contract.priceImportEmpty`;
Wasser und Einspeisung → `errors.contract.priceImportUnsupported`.

```text
monat;ct_kwh;grundpreis
01.2026;31,42;9,90
02.2026;29,87;9,90
```

**Gutschriften des Direktvermarkters** — nur Einspeisung:
`revenue_statements: [{from, to, amount_eur, kwh?, attachment_id?}]`, `to`
einschließlich. Eine Gutschrift ersetzt für ihren Zeitraum die Rechnung kWh ×
Vergütung, tagesgenau auf die Monate verteilt. Ein Monat trägt dann
`revenue_source: "statement"` (ganz abgedeckt) oder `"mixed"`. Beispiel: Eine
Gutschrift für Juni 2026 über 23,40 € steht genau so im Juni.

### Verträge: zeitvariables Netzentgelt (Modul 3) *(v3.3.0, additiv)*

**§ 14a EnWG, Modul 3** — nur Strom: `grid_tou` beschreibt das zeitvariable
Netzentgelt aus dem Preisblatt des Netzbetreibers, einen Standardtarif und
Zeitfenster mit einem anderen Preis je kWh. Beispiel mit erfundenen Preisen,
so wie am Wärmepumpenvertrag des Beispielhaushalts „Eigenheim mit Wärmepumpe
und PV“ (`eigenheim-modern`):

```json
"grid_tou": {
  "standard_ct": 8.1,
  "windows": [
    { "quarters": [1, 2, 3, 4], "from": "00:00", "to": "06:00", "ct": 2.4 },
    { "quarters": [1, 4],       "from": "17:00", "to": "20:00", "ct": 11.9 }
  ]
}
```

| Feld | Bedeutung |
|---|---|
| `standard_ct` | Netzentgelt je kWh außerhalb der Fenster, ct/kWh (0–100); Pflicht, sobald es Fenster gibt |
| `windows[].quarters` | Quartale, in denen das Fenster gilt: 1 = Januar–März … 4 = Oktober–Dezember; gespeichert sortiert und ohne doppelte |
| `windows[].from`, `windows[].to` | Ortszeit `HH:MM`, `to` auch `24:00`; `from` liegt vor `to` — ein Fenster über Mitternacht sind zwei (`22:00`–`24:00` und `00:00`–`06:00`) |
| `windows[].ct` | Netzentgelt je kWh im Fenster (0–100); unter `standard_ct` Niedrigtarif, darüber Hochtarif |
| `net` | *(v3.7.0, additiv)* `true`: die Beträge stehen **ohne Umsatzsteuer** am Vertrag; die Flexibilität rechnet sie mit dem Satz der App auf brutto (der des dynamischen Angebots, sonst 19 %, wie die Börsen-Hebel; `module3[].prices_net`, `vat_pct`). Fehlt oder `false`: wie eingetragen (brutto, wie auf der Rechnung) — gespeichert wird das Feld nur mit `true` |

Höchstens 24 Fenster. Zahlen dürfen auch als Text mit Dezimalkomma kommen
(`"2,4"`). Überschneiden sich zwei Fenster, gilt das erste der Liste. Ungültig
→ `400` `errors.contract.gridTouInvalid`; die Meldung nennt die Stelle
(`standard_ct`, `windows[1]`, `windows[1].ct` oder `windows`). `null`, `{}`
oder ein Objekt ohne Standardtarif und ohne Fenster entfernt das Feld; ein
Fenster, das kein Objekt ist, fällt weg. Bei anderen Arten als Strom wird
`grid_tou` nicht gespeichert.

Saldo, Prognose und Tarifvergleich rechnen weiter mit den Preisen des
Vertrags. Ausgewertet wird `grid_tou` nur in der
[Flexibilität](#flexibilität-bewerten-v330): je Monat mit dem echten Vertrag
des Zählers (kein Schattenvertrag), der am Monatsersten gilt. Modul 3 gibt es
nur zusätzlich zu Modul 1 (`grid_reduction`) und mit intelligentem
Messsystem — Festlegung BK8-22/010-A der Bundesnetzagentur; Stand und Quellen:
[Anwendungsfälle](../anleitungen/anwendungsfaelle.md), Abschnitt G.

### Verträge: `phase`, `values_on` *(v3.4.0, additiv)*

Jeder Vertrag in `GET /api/utility/{u}/contracts` (auch mit `?meter_id=`) und
`GET …/contracts/{id}` trägt zwei Felder, die nur in der Antwort stehen —
gespeichert werden sie nicht, und ein `PATCH` mit ihnen ändert nichts:

| Feld | Bedeutung |
|---|---|
| `phase` | `future` (beginnt nach heute), `current` (gilt heute), `renewed` (beendet, läuft ohne Kündigung weiter), `past` (beendet oder von einem später begonnenen Vertrag abgelöst), `shadow` (Angebot zum Vergleich, rechnet nirgends mit) |
| `values_on` | `{date, working_price_ct, base_price_eur, advance_eur}`: Arbeitspreis (ct je Verbrauchseinheit, bei Wasser Trinkwasser je m³), Grundpreis (€/Monat, bei Wasser Trinkwasser) und Abschlag nach dem effektiven Plan (mit Sonderzahlungen, die ihn ändern) am Tag `date` — heute, bei einem beendeten Vertrag sein letzter Tag, bei einem künftigen sein erster; fehlt ein Wert, `null` |

Die Phase folgt `ContractService::resolveForDate()` je Ziel (Zähler bzw.
Zählergruppe; Angebote zählen dabei nicht): Wer heute rechnet, ist `current`
oder `renewed`; bei überlappenden Verträgen gewinnt der spätere Beginn, der
ältere ist `past`. `GET …/meters/{id}/contract-status` und
`…/meter-groups/{id}/contract-status` tragen je Vertrag dieselbe `phase`
(dort ohne `shadow`, Angebote stehen nicht im Saldo). Die Oberfläche liest den
Status beider Vertragslisten nur noch daraus und ordnet nach Phase:
Zukünftig, Aktuell (mit verlängert), Beendet, Angebote. Bis v3.3 leitete jede
Liste ihn selbst ab — derselbe Vertrag hieß „VERGANGEN“ und „VERLÄNGERT“.

### Börsenstrompreise und Dynamik-Check *(v3.1.0)*

Was hätte ein dynamischer Stromtarif gekostet? Dafür hält die App die
Großhandelspreise (Day-Ahead, Gebotszone Deutschland/Luxemburg) als
**Monatsmittel** im Topf `market_prices.json` (im Backup):

```json
{ "source": "smard", "area": "DE-LU", "unit": "ct/kWh",
  "months": { "2025-01": { "avg_ct": 11.414 }, "2025-02": { "avg_ct": 12.82 } },
  "imported_at": "2026-02-03T19:12:00+01:00",
  "attribution": "Bundesnetzagentur | SMARD.de (CC BY 4.0)" }
```

| Route | Zweck |
|---|---|
| `GET /api/market-prices` | der Topf samt `attribution` |
| `POST /api/market-prices/import-csv[?dry_run=1]` | Datei als Körper: ein SMARD-Download mit Stunden- oder Viertelstundenwerten (Kopf mit „Datum von“ und der Spalte „Deutschland/Luxemburg [€/MWh]“, Dezimalkomma, „-“ für fehlend) oder einfach `JJJJ-MM;€/MWh`. Mittelt je Monat und ersetzt diese Monate. Antwort `{months, from, to, rows, skipped}`, im Trockenlauf dazu `would_import` und `preview` |
| `POST /api/market-prices/sync-smard` | holt die Monatswerte der letzten Jahre von SMARD (Bundesnetzagentur). **Nur auf Knopfdruck**, nie im Hintergrund — der einzige Abruf dieser Art. Antwort `{months, from, to}` |

Fehler: keine Werte in der Datei → `400` `errors.marketPrices.noRows`; SMARD
nicht erreichbar oder leer → `502` `errors.marketPrices.syncFailed`.
Namensnennung: „Bundesnetzagentur | SMARD.de (CC BY 4.0)“.

**Dynamik-Check.** Ein Schattenvertrag Strom mit `price_model: "dynamic"` wird
für Rückblick (`tariff-comparison`) und Wechsel (`tariff-switch`) in
Monatspreise übersetzt:

```text
Arbeitspreis (Monat) = Spotmittel × (1 + USt) + Aufschlag      Grundpreis = base_eur_month
Beispiel: 3.000 kWh, Spot 10 ct, USt 19 %, Aufschlag 15 ct, Grundpreis 10 €
          → 3.000 × 26,9 ct + 12 × 10 € = 807 € + 120 € = 927 € im Jahr
```

Ein Monat ohne Marktwert — in der Zukunft — nimmt denselben Monat des
Vorjahres (bis drei Jahre zurück) als Annahme. Die Zeile des Kandidaten trägt
`price_model: "dynamic"` und `dynamic_assumed_months` (Zahl der angenommenen
Monate). Fehlen die Marktdaten ganz, entfällt der Kandidat, und die Antwort
trägt `dynamic_missing_market: true`.

Ohne Viertelstunden rechnet der Check **ohne Lastprofil** mit dem
Monatsmittel, als verteile sich der Verbrauch gleichmäßig über den Tag.
Abendverbrauch ist meist teurer; ein echter dynamischer Tarif wird je
Viertelstunde über ein intelligentes Messsystem abgerechnet (§ 41a EnWG).
Hintergrund:
[Strom → Dynamische Tarife](../verstehen/02-strom.md#dynamische-tarife-v310).

**Mit eigenem Lastgang *(v3.3.0)*.** Liegen für den Zähler Verbrauch und
Börsenpreis je Viertelstunde vor ([Flexibilität](#flexibilität-bewerten-v330)),
nimmt der Rückblick (`tariff-comparison`) in jedem Monat, in dem mindestens
90 % der Viertelstunden beides haben, den nach Verbrauch gewichteten
Börsenpreis statt des Monatsmittels:

```text
Spot (Monat)  = Σ (kWh × ct/kWh) ÷ Σ kWh      über die Viertelstunden mit Verbrauch und Preis
Arbeitspreis  = Spot × (1 + USt) + Aufschlag
```

Die Zeile des Kandidaten trägt dazu `dynamic_profile_months` — die Zahl dieser
Monate, sonst 0. Die Wechselentscheidung (`tariff-switch`) blickt nach vorn
und bleibt beim Monatsmittel.

### Flexibilität bewerten *(v3.3.0)*

Hat das Verschieben etwas gebracht? Gesteuert wird außerhalb — von evcc, Home
Assistant oder dem Speicher selbst; der Energietracker rechnet mit Verbrauch
und Börsenpreis **je Viertelstunde** nach. Gerechnet wird nur über
Viertelstunden, für die beides vorliegt, und nur der Börsenanteil — ohne
Steuern, Umlagen und Netzentgelt (die Hebel in `insights` rechnen seit v3.6.0
mit Umsatzsteuer, s. u.). Schritt für Schritt:
[Flexibilität bewerten](../anleitungen/flexibilitaet.md); Ablage der Reihen:
[Datenmodell](datenmodell.md#viertelstundenreihen-intervalsjson-v330).

| Route | Zweck | Klasse |
|---|---|---|
| `GET /api/flexibility?year=` | Auswertung eines Kalenderjahres; *(v3.6.0)* `&summary=1` nur die Zahl der Ergebniskarte | B |
| `GET /api/intervals` | Index der Viertelstundenreihen | C |
| `DELETE /api/intervals/{id}` | eine Reihe löschen | C |
| `POST /api/market-prices/sync-smard-intervals?year=` | Börsenpreise je Viertelstunde von SMARD holen | C |

`year` ist ein Kalenderjahr von 2018 bis 2100, ohne Angabe das Vorjahr. Das
Jahr läuft in der Zeitzone der Installation (`timezone`) vom 1. Januar 0:00
bis zum 1. Januar 0:00.

**Woher die Viertelstunden kommen.**

| Quelle | Weg | `source` |
|---|---|---|
| Datei aus einem Portal (Netzbetreiber, Messstellenbetreiber, Wechselrichter) | `POST …/import-series` mit Werten im Takt von 15, 30 oder 60 Minuten — [Zeitreihe importieren](#zeitreihe-importieren-v310) | `import` |
| Home Assistant, Node-RED, Skripte | `POST /api/ingest` mit `timestamp` — [Zeitstempel und Viertelstunden](#zeitstempel-und-viertelstunden-v330) | `ingest` |
| Börsenpreise Day-Ahead DE-LU | `POST /api/market-prices/sync-smard-intervals` — s. u. | `smard` |

Ein neuer Wert ersetzt den alten in derselben Viertelstunde. Verbrauch gibt es
für jeden Zähler einer Art mit Zählerständen; ausgewertet werden Strom und die
Speicherzähler der PV-Erzeugung.

**Auswertung — `GET /api/flexibility`.** Beispiel: der Beispielhaushalt
`eigenheim-modern` im Jahr 2025 (Verbrauch erfunden, Börsenpreise von SMARD),
Listen gekürzt:

```json
{ "year": 2025,
  "prices": { "available": true, "coverage": 1, "area": "DE-LU" },
  "meters": [
    { "meter_id": "m_strom_waermepumpe", "name": "Wärmepumpe", "role": "heat_pump",
      "controllable": true, "coverage": 1,
      "months": [ { "month": 1, "kwh": 768.82, "coverage": 1, "priced_kwh": 768.82,
                    "cheap_kwh": 430.98, "negative_kwh": 12.49, "own_ct": 10.634,
                    "market_ct": 11.414, "profile_factor": 0.932, "advantage_eur": 6 }, "…" ],
      "total": { "priced_kwh": 3890.98, "cheap_kwh": 2125.88, "cheap_share": 0.5464,
                 "negative_kwh": 111.98, "own_ct": 9.596, "market_ct": 10.079,
                 "profile_factor": 0.952, "advantage_eur": 18.78 } },
    "…" ],
  "battery": {
    "months": [ { "charged_kwh": 103.05, "discharged_kwh": 94.8, "charge_ct": 10.373,
                  "discharge_ct": 12.119, "value_eur": 0.8, "month": 1 }, "…" ],
    "total": { "charged_kwh": 1694.7, "discharged_kwh": 1555.91, "charge_ct": 8.114,
               "discharge_ct": 11.612, "value_eur": 43.15 } },
  "module3": [
    { "meter_id": "m_strom_waermepumpe", "name": "Wärmepumpe",
      "months": [ { "month": 1, "low_kwh": 298.74, "high_kwh": 30.75,
                    "standard_kwh": 439.33, "saving_eur": 15.86 }, "…" ],
      "total": { "low_kwh": 1511.79, "high_kwh": 129.41, "standard_kwh": 2249.78,
                 "saving_eur": 81.25 } } ] }
```

| Feld | Bedeutung |
|---|---|
| `prices` | `available`: Preisreihe des Jahres vorhanden; `coverage`: Anteil der Viertelstunden des Jahres mit Preis (0–1); `area`: Gebotszone `DE-LU` |
| `meters[]` | je Stromzähler mit einer Verbrauchsreihe des Jahres: `meter_id`, `name`, `role`, `controllable` (Rolle `heat_pump` oder `ev_charger` — Verbrauch, der sich verschieben lässt), `coverage` (Anteil der Viertelstunden mit Verbrauch). Ohne Preise `months: []` und `total: null` |
| `months[]` | je Monat mit Verbrauch: `month` (1–12), `kwh` (Verbrauch des Monats), `priced_kwh` (davon in Viertelstunden mit Preis), `coverage` (Anteil der Viertelstunden des Monats mit Verbrauch und Preis), `cheap_kwh`, `negative_kwh`, `own_ct`, `market_ct`, `profile_factor`, `advantage_eur` — `own_ct`, `profile_factor` und `advantage_eur` sind `null` ohne Viertelstunde mit Verbrauch und Preis, `market_ct` ohne Preis im Monat |
| `total` | über die Monate mit eigenem Preis und Börsenmittel: dieselben Größen ohne `month`, `kwh` und `coverage`, dazu `cheap_share` (Anteil von `cheap_kwh` an `priced_kwh`); `null`, wenn keine Viertelstunde Verbrauch und Preis hat |
| `battery` | Speicher: je Monat und gesamt `charged_kwh`, `discharged_kwh`, `charge_ct` und `discharge_ct` (Börsenpreis, nach Menge gewichtet; `null` ohne Menge) und `value_eur`. Braucht je eine Verbrauchsreihe der PV-Erzeugung mit der Rolle `battery_charge` („Speicher – Ladung“) und `battery_discharge` („Speicher – Entladung“) und Preise, sonst `null` |
| `module3[]` | je Stromzähler mit Verbrauchsreihe, dessen echter Vertrag an mindestens einem Monatsersten des Jahres `grid_tou` trägt ([Modul 3](#verträge-zeitvariables-netzentgelt-modul-3-v330-additiv)): je Monat und gesamt `low_kwh`, `high_kwh`, `standard_kwh` (Verbrauch im Niedrig-, Hoch- und Standardtarif) und `saving_eur`. Braucht keine Börsenpreise |

```text
günstig         = Viertelstunde mit Preis unter dem einfachen Börsenmittel ihres Monats
own_ct          = Σ (kWh × ct/kWh) ÷ Σ kWh             eigener Börsenpreis, nach Verbrauch gewichtet
market_ct       = einfaches Börsenmittel des Monats; im Jahr nach den kWh je Monat gewichtet
profile_factor  = own_ct ÷ market_ct                   unter 1: mehr Verbrauch in günstigen Viertelstunden
advantage_eur   = (market_ct − own_ct) × kWh ÷ 100     positiv: gespart gegenüber gleichmäßigem Verbrauch
value_eur       = (Σ kWh entladen × ct/kWh − Σ kWh geladen × ct/kWh) ÷ 100
saving_eur      = Σ kWh × (standard_ct − ct/kWh des Fensters) ÷ 100

Beispiel Wärmepumpe 2025: (10,079 − 9,596) ct/kWh × 3.890,98 kWh ÷ 100 ≈ 18,8 €
                          Profilfaktor 9,596 ÷ 10,079 = 0,952
```

`profile_factor` ist `null`, wenn das Börsenmittel bei 0 liegt (Betrag bis
0,01 ct). Für Modul 3 zählen Quartal und Uhrzeit jeder Viertelstunde in
Ortszeit; Monate ohne Vertrag mit `grid_tou` fehlen in `months`.

**Was du tun kannst — `insights`** *(v3.6.0, additiv)*. Dieselbe Antwort trägt
Ampel, Hebel und Zeitfenster (`FlexibilityInsightService`); die Oberfläche
formatiert nur ([Flexibilität, § 4](../anleitungen/flexibilitaet.md#4-die-seite-karte-für-karte)):

| Feld | Bedeutung |
|---|---|
| `signal` | Preissignal der Ampel: `spot` (Börsenpreis je Viertelstunde), `tou` (ohne Börsenpreise: Zeitfenster für Modul 3 am Vertrag) oder `null` |
| `season_now` | `summer` (April–September) oder `winter` — die Jahreszeit der Handlungssätze, nach dem heutigen Datum |
| `pattern` | je `all`, `summer`, `winter`: `week[7][24]` (Montag zuerst; je Feld `ct`, `level` `cheap`/`mid`/`expensive`), `hours[24]` (`ct`, `level`, `sun` 0–1 als Anteil der stärksten Stunde, `sun_level` `strong`/`some`/`none`), `windows[]` (die drei günstigsten Fenster der Woche: `weekday` 1–7, `from`, `to`, `ct`, `below_ct`, `below_pct`, `sun`), `day_cheap`, `day_peak` (günstigste bzw. teuerste drei Stunden des typischen Tages), `days`, `mean_ct`, `min_ct`, `max_ct`, `cheap_ct`, `expensive_ct` (Drittelgrenzen; `null` bei Modul 3, dort gelten die Stufen des Netzentgelts); `null` ohne Preise und ohne Erzeugung |
| `levers[]` | je Gerät und Signal, größter zuerst: `kind` (`ev`, `heat_pump`, `battery`, `washer`, `dishwasher`), `meter_id`, `name`, `signal` (`spot`, `tou`, `solar`), `real` (`true` = mit dem Vertrag, wie er ist), `basis` (`measured` oder `assumed` = Beispielwert), `share`, `kwh`, `shift_kwh`, `eur` (€ im Jahr, ≥ 1; *v3.6.0* bei `spot` mit Umsatzsteuer `vat_pct`), `window` je `summer`/`winter` (`weekday` oder `null`, `from`, `to`, `ct`, `what` `cheap`/`peak`/`sun`/`low`; *v3.6.0* `plugged: true`, wenn das Fenster aus den Steckzeiten kommt — mit Steckzeiten und Sonnenstrom `null` in einer Jahreszeit, in der das Auto bei Sonne nie angesteckt ist), bei Beispielwerten `assumption`; *(v3.6.0)* `availability` (`ev`: `sessions` = Ladefenster aus den Ladevorgängen von evcc, `day` = Annahme „ganzer Ladetag“; sonst `null`), `sessions` (Zahl der Ladevorgänge im Jahr) und `session_share` (Anteil der Ladung in einem Ladefenster, 0–1), beide `null` ohne Ladevorgänge |
| `total_eur`, `total_real_eur` | Summe des größten Hebels je Gerät; davon nur Hebel mit `real` |
| `vat_pct` | *(v3.6.0)* Umsatzsteuer auf den Börsenpreis in Prozent: `dynamic.vat_pct` des dynamischen Angebots, ohne Angebot 19 |
| `ev_availability` | *(v3.6.0)* Ladefenster des Autos über alle Wallboxen: `basis` (`sessions` oder `day`; *v3.7.0* `window` = eigene Steckzeit aus den Einstellungen, ohne evcc), `sessions`, `share` (wie oben), *(v3.7.0)* `window` (`{from, until}` in Stunden oder `null`); `null` ohne Wallbox mit Viertelstunden. In `levers[]` heißt `availability` dann ebenfalls `window` |
| `summary` | *(v3.6.0)* die Zahl der Ergebniskarte, eine Regel für Seite, Bericht und Übersicht: `year`, `eur` (`total_real_eur`, wenn ≥ 1 — sonst `total_eur`), `real` (welches von beiden), `dynamic_eur` (`total_eur`, wenn `real` und um mehr als 1 € größer, sonst `null`), `vat_pct`, `measured` (gemessene Viertelstunden: Strom oder Erzeugung und Einspeisung — nur dann zeigt die Übersicht die Kachel), `action` (erster Satz mit Zeitfenster, bei `real` zuerst einer mit `real`; Felder wie `actions[]`); `null` ohne Hebel |
| `actions[]` | je Gerät ein Satz: der größte Hebel mit `real`, sonst der größte; *(v3.6.0)* hat der mit `real` in der laufenden Jahreszeit kein Zeitfenster, der größte, wenn er eins hat; `kind`, `meter_id`, `name`, `signal`, `real`, `basis`, `eur`, `season`, `window` (laufende Jahreszeit), `other` (die andere) |
| `solar` | mit Erzeugung und Einspeisung je Viertelstunde: `generation_kwh`, `feed_kwh`, `charged_kwh`, `self_kwh` (Erzeugung − Einspeisung), `direct_kwh`, `self_share`, `more_kwh`, `more_eur`, `self_share_after`, `more_share`, `working_ct`, `feed_ct`; sonst `null` |
| `achieved` | aus der Nachrechnung: `profile_eur` (Vorteil der steuerbaren Zähler), `battery_eur`, `module3_eur` — je `null` ohne Daten |
| `missing[]` | was fehlt: `prices`, `intervals`, `devices` (keine Wallbox oder Wärmepumpe mit Viertelstunden), `pv` (PV-Zähler ohne Viertelstunden), `tariff` (weder dynamisches Angebot noch Zeitfenster für Modul 3) |
| `assumptions` | die Annahmen: `heat_pump_share` (Beispiel 0,3), `ev_share` (1), `appliances` (`washer`, `dishwasher` mit `kwh_per_cycle`, `cycles_per_week`, `hours`), `window_hours` (3); *(v3.7.0, additiv)* `ev_window` und `own` (je `heat_pump_share`, `washer`, `dishwasher`, `ev_window`: `true` = eigener Wert aus den [Einstellungen](einstellungen.md#flexibilität-v370), sonst Beispielwert). Ein Gerät mit eigenem Wert hat in `levers[]` die Grundlage `basis: own`; 0 Gänge je Woche = kein Hebel |

```text
Hebel (spot, tou)  = Σ Tage (Kosten heute − Kosten, wenn der freie Teil des Tages (share) die günstigsten
                     Viertelstunden des Tages füllt, höchstens mit der größten gemessenen Leistung) ÷ 100
                     — bei spot × (1 + vat_pct ÷ 100)
Hebel (battery)    = Σ Tage (Entladen in den teuersten Viertelstunden ohne Laden, höchstens Netzbezug + Entladen
                     des Hauses − Wert heute) ÷ 100 × (1 + vat_pct ÷ 100)
Hebel (solar)      = verschobene kWh × (Arbeitspreis − Einspeisevergütung) ÷ 100; nur Hausanschluss und Subzähler
Hebel (Geräte)     = Σ Tage kWh je Tag × (Mittel aller Startzeiten − bestes Fenster) ÷ 100 (spot mit USt)
Auto (v3.6.0)      Tage → Ladefenster: je Ladevorgang aus evcc (`ev_sessions`) die Viertelstunden von `created`
                     (Anstecken) bis `finished` (Abstecken), auch über Mitternacht; an Tagen mit Ladefenster
                     bleibt Laden außerhalb davon, wo es ist; Tage ohne Ladevorgang wie oben
```

**Nur die Zahl der Karte — `GET /api/flexibility?summary=1`** *(v3.6.0,
additiv)*. Antwort `{year, summary}` mit `summary` wie oben (oder `null`),
ohne `prices`, `meters`, `battery`, `module3` und ohne die übrigen Felder von
`insights` — die Übersicht holt so ihre Kachel „Dein Hebel“, ohne die
Nachrechnung je Zähler. `year` wie oben, ohne Angabe das Vorjahr.

```json
{ "year": 2025,
  "summary": { "year": 2025, "eur": 90.63, "real": true, "dynamic_eur": 250.75, "vat_pct": 19,
               "measured": true,
               "action": { "kind": "heat_pump", "meter_id": "m_strom_waermepumpe", "name": "Wärmepumpe",
                           "signal": "tou", "real": true, "basis": "measured", "eur": 39.68, "season": "winter",
                           "window": { "weekday": null, "from": 0, "to": 6, "ct": 2.4, "what": "low" },
                           "other": { "weekday": null, "from": 0, "to": 6, "ct": 2.4, "what": "low" } } } }
```

**Reihen — `GET /api/intervals`.**

```json
{ "series": [
  { "id": "e-strom-m_strom_waermepumpe-2025", "kind": "energy", "utility": "strom",
    "meter_id": "m_strom_waermepumpe", "unit": "kWh", "year": 2025, "step": 900,
    "slots": 35040, "filled": 35040, "from": "2025-01-01T00:00:00+01:00",
    "to": "2026-01-01T00:00:00+01:00", "source": "import", "updated_at": "…" },
  { "id": "p-de-lu-2025", "kind": "price", "area": "DE-LU", "unit": "ct/kWh",
    "year": 2025, "step": 900, "slots": 35040, "filled": 35040,
    "from": "2025-01-01T00:00:00+01:00", "to": "2026-01-01T00:00:00+01:00",
    "source": "smard", "updated_at": "…" } ] }
```

Sortiert nach `kind`, Zähler und Jahr. `slots` sind die Plätze des Jahres,
`filled` die mit Wert; `from` und `to` Beginn der ersten und Ende der letzten
Viertelstunde mit Wert (Ortszeit, `null` ohne Wert); `source` die Quelle des
letzten Schreibens. Die Felder im Einzelnen:
[Datenmodell](datenmodell.md#viertelstundenreihen-intervalsjson-v330).

**Löschen — `DELETE /api/intervals/{id}`** entfernt Indexeintrag und Datei
und antwortet `{"deleted": true}`. Zählerstände, Tageswerte und die
Monatsmittel der Börse bleiben.

**Börsenpreise — `POST /api/market-prices/sync-smard-intervals?year=`**, ohne
Körper. Holt den Großhandelspreis Day-Ahead DE-LU je Viertelstunde von SMARD
(Bundesnetzagentur): erst das Verzeichnis der Wochenpakete, dann jedes Paket
des Jahres — rund 53 Abrufe. Umrechnung €/MWh ÷ 10 = ct/kWh. Vor dem
1. Oktober 2025 wurden Stunden gehandelt; SMARD führt dann den Stundenwert in
allen vier Viertelstunden. Viertelstundenwerte hat SMARD ab Oktober 2018.
Künftige Viertelstunden bleiben leer, vorhandene Werte werden ersetzt. **Nur
auf Knopfdruck**, nie im Hintergrund; hinaus geht nur die Anfrage an SMARD,
keine eigenen Daten.

```json
{ "year": 2025, "slots": 35040, "from": "2025-01-01", "to": "2025-12-31" }
```

`slots` zählt die übernommenen Viertelstunden, `from` und `to` sind der erste
und letzte Tag mit Wert. Namensnennung: „Bundesnetzagentur | SMARD.de (CC BY
4.0)“.

| Status | `code` | Wann |
|---|---|---|
| `400` | `errors.flexibility.yearInvalid` | `year` ist keine Jahreszahl von 2018 bis 2100 (`flexibility`, `sync-smard-intervals`) |
| `400` | `errors.flexibility.monthInvalid` | *(v3.5.1)* `month` ist keine Zahl von 1 bis 12 (`sync-smard-intervals`) |
| `404` | `errors.intervals.notFound` | `DELETE`: keine Reihe mit dieser ID im Index |
| `502` | `errors.marketPrices.syncQuarterHoursFailed` | `sync-smard-intervals`: SMARD nicht erreichbar, ein Paket mit HTTP-Fehler oder kein Wert für das Jahr — gespeichert wird dann nichts |

### `GET /api/utility/{u}/meters/{id}/forecast` *(v2.8.0 erweitert)*

Je Prognosemonat zusätzlich:

| Feld | Bedeutung |
|---|---|
| `band_low`, `band_high` | Unsicherheitsband (Breite `confidence_band_sigma`, Default 1,28 σ ≈ 80 %) |
| `hdd_estimated` | normale Heizgradtage des Monats (Klimanormal, sonst eigene Historie) |
| `contract_assumed` | `true`, wenn nach Vertragsende der letzte Vertrag als Annahme weiterläuft |
| `method` | `blend(reg=…, seasonal=…)`, `seasonal_only`, `regression_only` (Kalendermonat ohne eigene Historie), `heat_model`, `filled` |

Auf oberster Ebene:

```json
"hdd_source": "climate_normal",       // oder "temperature_history", "consumption_months", null
"annual": { "value": 9387.3, "low": 8619.3, "high": 10155.4, "sigma": 1.28, "level_pct": 80 },
"warnings": [
  { "code": "history_short", "months": 8, "missing": [1, 2, 3, 4] },
  { "code": "no_climate_normal", "hdd_source": "temperature_history" }
],
"climate_normal": { "period": { "from": "1996-01-01", "to": "2025-12-31" },
                    "latitude": 51.34, "longitude": 12.37, "fetched_at": "…" }
```

`annual` fehlt (`null`), wenn keine zwölf Monate mit Band vorliegen. Die
Abschläge der Prognose kommen aus dem effektiven Zahlungsplan
(Sonderzahlungen „mit Auswirkung" ändern ihn).

**Seit v3.7.0 (#37, additiv):** Verteilte Monate bilden das Saisonprofil nicht.
Für einen Kalendermonat ohne gemessenen Wert und ohne Modell steht
`method: "seasonal_interpolated"` (die verteilten Werte). `warnings` trägt
dann `{ "code": "interpolated", "months": 12, "readings": 2, "heat_basis": null }`,
und `heat_model` nennt seine Grundlage (`basis`: `months` oder `intervals`).

**CO₂-Preis-Szenario *(v3.1.0, MKT-26, additiv)*.** Was ein höherer CO₂-Preis
kosten würde — nur ein Ausweis neben der Prognose; `cost_estimated` und alle
anderen Werte bleiben unverändert.

| Parameter | Bedeutung |
|---|---|
| `co2_scenario_eur_t` | Szenario-Preis in €/t (0–1000). Fehlt der Parameter, gilt die Einstellung `co2_price_scenario_eur_t`; leer (`co2_scenario_eur_t=`) schaltet das Szenario für diese Anfrage aus |
| `co2_scenario_from` | erstes Jahr des Szenarios (2021–2100); fehlt er, gilt `co2_price_scenario_from` (Standard 2028) |

Ungültig → `400` `errors.forecast.paramInvalid` wie die übrigen Parameter.
Mit Szenario trägt jeder Prognosemonat ab `co2_scenario_from` zusätzlich
`co2_delta_eur` (Mehrkosten des Monats mit Umsatzsteuer), und auf oberster Ebene
steht:

```json
"co2_scenario": { "eur_t": 150.0, "from": 2028,
                  "delta_ct_per_kwh": 1.943, "delta_cost_12m_eur": 0.0 }
```

```text
delta_ct_per_kwh = (eur_t − CO₂-Preis des Jahres) × Faktor [kg/kWh] / 10 × (1 + 0,19)
```

`delta_ct_per_kwh` ist der Wert des ersten Szenario-Monats, `null`, wenn die
Prognose das Jahr `from` nicht erreicht. `delta_cost_12m_eur` summiert
`co2_delta_eur` über die **ersten zwölf** Prognosemonate — liegen sie vor
`from`, ist die Summe 0. Faktor und Preis wie bei `/api/co2-costs` (Gas,
Heizöl, Heizwärme über ihren Energieträger); ohne Faktor (Strom, Wasser,
Fernwärme) ist `co2_scenario` `null`, ebenso ohne Szenario. In einem Land
ohne CO₂-Schema bleibt `delta_ct_per_kwh` `null`.

### `POST /api/forecast/scenario` — Prognose-Werkstatt *(v3.6.0, F1031, additiv)*

Rechnet ein Szenario zu einem Zähler oder einer Zählergruppe gegen den
Ausgangsfall (die Prognose der nächsten zwölf Monate). **Schreibt nichts:**
keine Schreibsperre, auch mit einem Lese-Schlüssel erlaubt. POST nur wegen
der verschachtelten Eingaben. Anleitung und Rechenweg:
[Was wäre wenn?](../anleitungen/was-waere-wenn.md)

| Feld | Bedeutung (Standard) |
|---|---|
| `utility`, `meter_id` | Pflicht; Zähler oder Gruppe. Unbekannter Zähler → `404` |
| `model` | `standard` (Prognose), `last_year`, `trend` (`standard`) |
| `regression` | Regressionsmodell der Prognose: `linear`, `polynomial`, `robust`, `segmented`, `sigmoid` (Einstellung) |
| `winter_pct` | Heizgradtage ± % (−50…50; 0) — nur der heizabhängige Teil |
| `room_temp_delta` | Raumtemperatur ± K (−5…5; 0) — Heizgrenze verschoben, Heizgradtage aus Klimanormal bzw. Historie |
| `saving_pct` | Verbrauch − % (0…80; 0) |
| `baseload_pct` | Grundlast ± % (−80…100; 0) |
| `ev_km`, `ev_kwh_per_100km` | E-Auto (nur Strom): km im Jahr (0…100 000) und Verbrauch (5…60, Pflicht bei km > 0) |
| `jaz_from`, `jaz_to` | JAZ heute und im Szenario (1…8, nur beide; nur Strom mit Rolle Wärmepumpe) |
| `measures[]` | höchstens 10: `id` (A–Z, a–z, 0–9, `_`, `-`), `kind`, `label`, `heat_pct` (0…90), `base_pct` (0…90), `cost_eur` (0…10 000 000 oder `null`), `active` (`true`) |
| `tariff` | `{"kind":"current"}`, `{"kind":"contract","contract_id":…}` (Vertrag oder Angebot des Zählers), `{"kind":"custom","working_price_ct":…,"base_eur_month":…}`, `{"kind":"cheapest"}` |
| `budget_eur` | mit Wert: beste Maßnahmenkombination unter diesem Budget (0…10 000 000) |

Ungültig → `400` `errors.forecast.paramInvalid` mit Feld und erlaubtem
Bereich. Arten ohne Werkstatt (Lieferungen, Einspeisung, Arten ohne Vertrag)
antworten mit `supported: false`, zu kurze Historie mit `valid: false`,
`reason_code: "too_few_months"`.

Antwort (gekürzt):

```json
{
  "supported": true, "valid": true, "utility": "gas", "meter_id": "m_gas_main", "unit": "kWh",
  "model": "standard", "from": "2026-11", "to": "2027-10",
  "months": [ { "ym": "2026-11", "base_qty": 1210.4, "scen_qty": 1302.1, "base_cost": 128.5, "scen_cost": 137.2,
                "base_cum_cost": 128.5, "scen_cum_cost": 137.2, "advance": 130, "heat_share": 0.81,
                "band_low": 1101.3, "band_high": 1319.5, "scen_band_low": 1184.8, "scen_band_high": 1419.4 } ],
  "totals": { "base": { "qty": 9629.5, "cost": 915.62, "co2_kg": 1752.7 },
              "scenario": { "qty": 10120.3, "cost": 962.1, "co2_kg": 1842.0 },
              "delta": { "qty": 490.8, "cost": 46.48, "co2_kg": 89.3, "qty_pct": 5.1, "cost_pct": 5.1 } },
  "band": { "level_pct": 80, "base_low": 9365, "base_high": 9893.9, "scen_low": 9842.6, "scen_high": 10398.0 },
  "models": { "standard": { "base_qty": 9629.5, "base_cost": 915.62, "scen_qty": 10120.3, "scen_cost": 962.1 },
              "last_year": { … }, "trend": null },
  "advance": { "current": 130, "method": "billing_period", "months_left": 9, "base_recommended": 49, "scen_recommended": 54 },
  "billing": { "from": "2026-01-01", "until": "2026-12-31", "as_of": "2026-10-14", "balance_so_far": -159.33,
               "base_end_balance": -726.23, "scen_end_balance": -690.4, "months_left": 2, "beyond_horizon": false },
  "tariff": { "kind": "current", "contract_id": null },
  "offers": [ { "contract_id": "c_…", "computable": true, "base_cost": 867.38, "scen_cost": 905.1 } ],
  "ranking": [ { "kind": "measure", "id": "roof", "saving_eur": 57.05, "saving_qty": 695.7, "cost_eur": 12000, "payback_years": 210.3 } ],
  "optimization": { "budget_eur": 14000, "chosen": ["roof", "hyd"], "saving_eur": 73.21, "cost_eur": 12900,
                    "payback_years": 176.2, "candidates": 3, "evaluated": 3, "method": "enumeration",
                    "lp": { "bound_eur": 78.86, "budget_value_per_eur": 0.0025, "x": { "roof": 1, "win": 0.0733, "hyd": 1 } } },
  "winter_presets": { "cold_pct": 9, "mild_pct": -9, "source": "climate_normal" },
  "capabilities": { "heat_model": true, "room_temp": true, "tariff": true, "ev": false, "jaz": false,
                    "billing": true, "co2": true, "band": true, "models": { "standard": true, "last_year": true, "trend": false } },
  "basis": { "history_months": 39, "last_measured": "2026-10", "hdd_source": "climate_normal", "heat_share_year": 0.48, "co2_kg_per_unit": 0.182 },
  "warnings": [ { "code": "trend_unavailable" } ],
  "inputs": { … }
}
```

- `ranking`: jede Stellschraube allein gegen den Ausgangsfall (normaler
  Winter, laufender Vertrag), sortiert nach `saving_eur` (positiv = spart);
  `kind` = `room_temp`, `saving`, `baseload`, `ev`, `jaz`, `tariff`, `measure`.
- `optimization` (nur mit `budget_eur`): exakt durch Aufzählung aller
  Kombinationen im Budget mit dem Modell; `lp` = lineare Relaxierung mit dem
  Simplex-Löser (`bound_eur` obere Schranke, `budget_value_per_eur`
  Schattenpreis des Budgets in € je € im Jahr).
- `advance.method`: `billing_period` (Abschlag + Endsaldo ÷ verbleibende Monate
  der laufenden Abrechnungsperiode) oder `year_twelfth`.
- `billing`: Saldo am Ende der laufenden Abrechnungsperiode (positiv =
  Nachzahlung); `null` ohne Periode. `balance_so_far` ist der Saldo der
  Periode wie in `…/billing-periods` (Stand `as_of`). *(v3.6.0)* `rest_days`
  (additiv): Tage nach `as_of` bis zum Monatsende, für die es weder Ablesung
  noch Prognosemonat gibt — geschätzt mit der Tagesrate des gemessenen Teils
  dieses Monats, Grundpreis und Abschlag tagesanteilig; vorher fehlten sie im
  Endsaldo.
- `warnings[].code`: `room_temp_unavailable`, `last_year_unavailable`,
  `trend_unavailable`, `model_filled` (mit `months`), `ev_unsupported`,
  `jaz_unsupported`, `no_heat_model`, `tariff_unsupported` (Wasser),
  `tariff_no_price`, `no_offers`.

Dazu trägt `GET …/forecast` seit v3.6.0 je Monat `heat_share` (Heizanteil aus
dem Heizmodell oder `null`), `fixed_eur` und `unit_eur` (Kosten des Monats =
`fixed_eur` + `unit_eur` × Menge) und auf oberster Ebene `heat_model`
(`a`, `c`, `n`) und `hdd_base` — additiv.

### `POST /api/temperatures/sync-open-meteo` *(v2.8.0)*

Holt Tagestemperaturen für den Standort aus den Einstellungen — Messwerte aus
dem Archiv, für die letzten Tage und die kommende Woche die Vorhersage — und
beim ersten Mal das Klimanormal (30 Jahre).

| Parameter | Bedeutung |
|---|---|
| `start`, `end` | Zeitraum (ISO-Datum). Ohne `start`: ab der ersten Ablesung bzw. Lieferung, sonst die letzten 30 Tage |
| `reload=1` | vorhandene Werte durch Archivwerte ersetzen — außer eigenen (`csv`, `manual`) |
| `auto=1` | automatischer Abruf der Oberfläche: höchstens einmal am Tag; bei `weather_auto_fill = false` `{"skipped": true, "reason": "auto_fill_off"}`, sonst ggf. `{"skipped": true, "reason": "already_today"}` |

Antwort: `imported`, `archive_rows`, `forecast_rows`, `archive_range`,
`archive_error`, `forecast_error`, `measured_until`, `forecast_until` und
`climate_normal` (`status`: `present` | `fetched` | `failed`, dazu Periode
und Koordinaten).

**Seit v3.1.0** stehen `archive_error` und `forecast_error` in der Sprache der
Anfrage (Katalog `errors.weather.<code>`; bis v3.0 englischer oder deutscher
Rohtext). Daneben, additiv, der stabile Grund als `archive_error_code` bzw.
`forecast_error_code` — `null` ohne Fehler:

| Code | Bedeutung |
|---|---|
| `network` | Open-Meteo nicht erreichbar (DNS, Verbindung, Zeitlimit) |
| `http` | Open-Meteo hat mit einem HTTP-Fehler geantwortet |
| `badFormat` | Antwort in einem unerwarteten Format |
| `noDays` | Antwort ohne verwendbare Tage |
| `noTransport` | PHP kann keine Webadressen abrufen (weder cURL noch `allow_url_fopen`) |

Scheitert das Klimanormal, trägt `climate_normal` neben `status: "failed"`
und `error` ebenso `error_code`. Skripte werten die Codes aus, nicht den Text.

Jeder Eintrag in `GET /api/temperatures` trägt seit v2.8.0 `source`:
`archive`, `forecast`, `csv` oder `manual`. Vorhersagen werden durch
Archivwerte ersetzt, sobald diese vorliegen; eigene Werte nie. An Open-Meteo
geht nur der Standort, auf zwei Nachkommastellen gerundet.

### `GET /api/geocode?q=…` *(v2.12.0)*

Ortssuche für die Wetterdaten-Seite über das Open-Meteo-Geocoding. `q` muss
2–80 Zeichen lang sein, sonst 400 `errors.temperature.geocodeQuery`; ist
Open-Meteo nicht erreichbar, 502 `errors.temperature.geocodeFailed`. Die
Sprache der Namen folgt der Oberfläche. Übermittelt wird nur der Suchtext,
und nur, wenn jemand sucht.

```json
[ { "name": "Leipzig", "latitude": 51.3396, "longitude": 12.3713,
    "country": "Deutschland", "admin1": "Sachsen", "postcode": "04303" } ]
```

Höchstens fünf Treffer; `country`, `admin1` und `postcode` können `null` sein.

### `POST /api/utility/{u}/meters/{id}/readings/import-csv?dry_run=1` *(v2.12.0)*

Liest die CSV wie der Import, schreibt aber nichts. Die Antwort hat dieselben
Felder (`imported` und `overwritten` sind 0, `skipped`, `errors`, ggf.
`encoding_converted_from` und `other_meter_rows`) und dazu:

```json
{ "dry_run": true,
  "rows": [ { "line": 2, "date": "2024-02-01", "counter": 1395.2,
              "note": "", "is_estimated": false } ] }
```

Die Oberfläche vergleicht `rows` mit den vorhandenen Ständen und zeigt je Zeile
die Wirkung (neu, ersetzt, unverändert) und die Rückfragen der Erfassung.
Zahlen dürfen Punkt, Komma, Leerzeichen oder Apostroph als Tausendertrenner
tragen (`1.395,2`, `1 395,2`, `1'395.2`). Ohne `dry_run` bleibt der Import,
wie er war. Welche Kopfzeilen und Datumsschreibweisen der Import seit v3.1.0
versteht: [Import](#import-v310).

### Zeitreihe importieren *(v3.1.0)*

`POST /api/utility/{u}/meters/{id}/import-series[?dry_run=1]` liest eine Datei
aus einem Portal (Netzbetreiber, Messstellenbetreiber, Wechselrichter,
Wärmepumpe) mit einer **Spaltenzuordnung** und verdichtet sie zu Tageswerten.
Für alle Arten mit Zählerständen, nicht für Heizöl und Pellets. Körper JSON:

```json
{ "csv": "Datum;Uhrzeit;Wert (kWh)\n01.01.2025;00:15;0,061\n…",
  "mapping": { "skip_rows": 1, "date_col": 0, "time_col": 1, "value_col": 2,
               "value_kind": "consumption", "unit_factor": 1,
               "interval_stamp": "end", "start_counter": 40211.0 } }
```

| `mapping` | Bedeutung |
|---|---|
| `delimiter` | `;`, `,` oder Tabulator; leer = je Zeile erkennen |
| `skip_rows` | Zeilen vor den Daten (Standard 0) |
| `date_col` | Spalte mit Datum oder Datum und Uhrzeit, **ab 0** (Standard 0) |
| `time_col` | Spalte mit der Uhrzeit, wenn getrennt; sonst weglassen |
| `tz` | Zeitzone für Stempel ohne Angabe; Standard die der Installation |
| `value_col` | Spalte mit dem Wert — Pflicht |
| `value_kind` | `counter` (Zählerstand: der letzte Wert je Tag) oder `consumption` (Verbrauch je Intervall: Summe je Tag, Standard) |
| `unit_factor` | Faktor auf die Werte, z. B. `0.001` für Wh → kWh (Standard 1, größer 0) |
| `interval_stamp` | `start` (Standard) oder `end` — mit `end` gehört ein Intervall, das um 0:00 endet, zum Vortag |
| `start_counter` | Stand zu Beginn des ersten Tages, nur für `consumption` auf einem Zähler mit Ständen |

Stempel: `TT.MM.JJJJ[ HH:MM[:SS]]`, `JJJJ-MM-TT[ HH:MM]` und ISO 8601 mit
Zeitzone (`2025-03-30T01:00:00+01:00`, `…Z`); die Tage der Zeitumstellung
(23 bzw. 25 Stunden) zählen richtig. Zahlen mit Dezimalkomma oder -punkt;
UTF-8 oder Windows-1252.

| `value_kind` | Zähler | Ergebnis |
|---|---|---|
| `counter` | Zählerstände | je Tag eine Ablesung mit dem letzten Wert; vorhandene Stände am selben Tag werden ersetzt |
| `consumption` | Verbrauch je Zeitraum (`capture: "period"`) | je Tag ein Zeitraum über den Zeitraum-Import; Überschneidungen werden übersprungen |
| `consumption` | Zählerstände | Stände ab einem Anker — der Ablesung am ersten Tag oder `start_counter`; der Stand am Ende eines Tages steht am Folgetag. Ohne Anker `400` `errors.import.anchorMissing` |
| `counter` | Verbrauch je Zeitraum | `400` `errors.import.mappingInvalid` |

Antwort:

```json
{ "rows_read": 35040, "skipped": 0, "errors": [], "days": 365,
  "from": "2025-01-01", "to": "2025-12-31", "total": 3412.618,
  "value_kind": "consumption",
  "intervals": { "step": 900, "slots": 35040 },
  "preview": [ { "date": "2025-01-01", "value": 9.412 }, "…" ],
  "target": "readings", "readings": 366,
  "result": { "imported": 366, "overwritten": 0, "skipped": 0, "errors": [] } }
```

`total` nur bei `consumption`; `preview` sind die ersten zehn Tage; `errors`
nennt bis zu zwanzig unlesbare Zeilen (`errors.import.seriesRow`). Im
Trockenlauf entfällt `result`, bei Ständen steht `dry_run: true`. Mit
`target: "periods"` ist `result` die Antwort des Zeitraum-Imports. Eine
Zuordnung mit ungültigen Werten oder eine Datei ohne einen einzigen Tag →
`400` `errors.import.mappingInvalid`. Schritt für Schritt:
[Zeitreihen aus Portalen](../anleitungen/daten-aus-portalen.md).

**Viertelstunden *(v3.3.0, additiv)*.** Liegen die Werte im Takt von 15, 30
oder 60 Minuten — maßgeblich ist der häufigste Abstand der Stempel —, behält
der Import sie zusätzlich je Viertelstunde für die
[Flexibilität](#flexibilität-bewerten-v330). `intervals` nennt den Takt in
Sekunden (`step`) und die Zahl der Viertelstunden (`slots`); bei gröberen
Werten (Tageswerte) oder weniger als zwei Zeilen ist es `null`. Ein Verbrauch
je Intervall verteilt sich gleichmäßig auf dessen Viertelstunden (mit
`interval_stamp: "end"` endet das Intervall am Stempel). Bei Zählerständen
verteilt sich die Differenz zweier Stände, die genau einen Takt auseinander
liegen; über eine Lücke oder einen Rückgang hinweg entsteht nichts. Der
Trockenlauf zählt nur; gespeichert wird beim Übernehmen (Quelle `import`),
für Zähler mit Ständen wie mit Zeiträumen. Vorhandene Werte derselben
Viertelstunden werden ersetzt.

### `GET /api/utility/{u}/meters/{id}/tariff-switch` — Prognosegüte *(v2.8.0 erweitert)*

Der Block `forecast` (Güte der Verbrauchsannahme) trägt zusätzlich
`warnings` und `hdd_source` wie die Prognose und `annual_band` (= `annual`
der Prognose): Die Wechselentscheidung zeigt, wie weit der Jahresverbrauch je
nach Winter schwanken kann.

### Ablesungen: `is_suspect`, `source` *(v2.6.0, additiv)*

Zwei optionale Felder an einer Ablesung — nur vorhanden, wenn gesetzt:

- `source`: `ingest` (Home Assistant) oder `csv` (CSV-Import). Grundlage von
  `last_ingest` in `/api/health`.
- `is_suspect: true`: Der Ingest hat einen fallenden Stand auf demselben Gerät
  erhalten. Die Ablesung wird trotzdem gespeichert (201), zählt aber erst nach
  Bestätigung. `PATCH` mit `is_suspect: false` bestätigt, ein korrigierter
  `counter` klärt den Verdacht ebenfalls.

### Ablesungen: `client_ref`, `attachment_id` *(v3.1.0, additiv)*

Zwei weitere optionale Felder, ebenfalls nur vorhanden, wenn gesetzt:

- `client_ref` — Kennung der Erfassung, vom Client gewählt: 8–64 Zeichen aus
  `A–Z`, `a–z`, `0–9` und `-` (die Oberfläche nimmt eine UUID). Gibt es am
  selben Zähler schon einen Stand mit derselben `client_ref`, legt
  `POST …/readings` keinen zweiten an, sondern antwortet **`200`** mit dem
  vorhandenen Stand und `duplicate: true`; sonst `201` wie bisher. So darf ein
  Client nach einem Zeitlimit gefahrlos erneut senden — die
  Offline-Warteschlange der Oberfläche tut genau das. Falsches Format → `400`
  `errors.reading.clientRefInvalid`.
- `attachment_id` — Foto des Zählerstands ([Belege](#belege-und-texterkennung-v310)).
  Der Beleg muss existieren und `kind: reading_photo` haben, sonst `400`
  `errors.attachment.notFound` bzw. `errors.attachment.wrongKind`. `PATCH` mit
  einer anderen ID ersetzt das Foto, mit `null` löst es. Ein gelöstes Foto —
  auch das einer gelöschten Ablesung — bleibt 24 Stunden liegen und wird dann
  aufgeräumt.

```jsonc
// POST /api/utility/strom/readings
{ "meter_id": "m_strom_main", "date": "2026-10-07", "counter": 48212.3,
  "client_ref": "7d0f6c1e-2b8a-4c55-9e3d-0a1b2c3d4e5f",
  "attachment_id": "att_5f0c2a9e81d34b67" }
```

### Zähler: `role`, `capture` *(v3.1.0, additiv)*

Zwei optionale Felder am Zähler, beim Anlegen und per `PATCH …/meters/{id}`.

**`role`** — wofür der Zähler steht. Die erste Rolle je Art ist der Standard
und wird **nicht gespeichert** (fehlt = Standard); `null` oder leer setzt auf
den Standard zurück. Die Liste je Art steht in `GET /api/utilities` unter
`meter_roles` (fehlt bei Arten ohne Rollen).

| Art | Rollen (Standard zuerst) | Wirkung |
|---|---|---|
| `strom` | `household`, `heat_pump`, `ev_charger` | `heat_pump` zählt in der Effizienzkennzahl als Heizenergie |
| `wasser` | `cold`, `warm`, `garden` | `warm` bekommt `dhw_kwh` je Monat (Warmwasser-Wärme) |
| `pv_erzeugung` | `generation`, `battery_charge`, `battery_discharge` | Speicher-Ladung und -Entladung als eigene Zähler |
| `waerme` | `consumption`, `heat_pump_output` | nur `consumption` zählt in der Effizienzkennzahl; die Wärmemenge einer Wärmepumpe stünde sonst neben ihrem Strom doppelt |

Andere Arten haben keine Rollen. Eine unbekannte Rolle — oder eine Rolle bei
einer Art ohne Rollen — ergibt `400` `errors.meter.roleInvalid`. Beim Strom
bleibt das ältere Feld `heat_source` (v2.10.0) gleichlaufend: `role:
"heat_pump"` setzt `heat_source: true`, jede andere Rolle entfernt es, und
`heat_source: true` ohne `role` setzt die Rolle `heat_pump`. Ältere Versionen
erkennen die Wärmepumpe so weiter.

Zähler mit den Rollen `battery_charge`, `battery_discharge` und
`heat_pump_output` zählen in den **Summen ihrer Art nicht mit** — wie
Subzähler: nicht in Monatsübersicht der Art, Dashboard, CSV-Monatsübersicht,
PDF, Effizienz, PV-Bilanz und CO₂. Sie messen Energie, die nicht Verbrauch bzw.
Erzeugung der Art ist, und dienen eigenen Kennzahlen.

**`capture`** — die Erfassungsart: `counter` (Zählerstände, Standard, wird
nicht gespeichert) oder `period` (Verbrauch je Zeitraum,
[s. u.](#verbrauch-je-zeitraum-v310)). `period` gibt es nur bei Arten mit
Zählerständen, nicht bei Heizöl und Pellets; ein anderer Wert → `400`
`errors.meter.captureInvalid`. Wechseln lässt sich die Art nur, solange der
Zähler keine Daten der bisherigen Art hat (Ablesungen bzw. Zeiträume), sonst
`400` `errors.meter.captureLocked`.

### Zähler: `malo_id`, `melo_id` *(v3.1.0, additiv)*

Zwei optionale Felder für den Lieferantenwechsel, beim Anlegen und per
`PATCH …/meters/{id}`; nur vorhanden, wenn gesetzt. Leerzeichen werden
entfernt, ein leerer Wert löscht das Feld.

| Feld | Form | Fehler (`400`) |
|---|---|---|
| `malo_id` | Marktlokations-ID: 11 Ziffern, die erste nicht 0, die letzte eine Prüfziffer nach BDEW — Ziffern an ungerader Stelle (1., 3., … 9.) einfach, an gerader Stelle (2., … 10.) doppelt summieren; die Prüfziffer ergänzt die Summe zur nächsten Zehn. Beispiel (synthetisch): `51234567895` | `errors.meter.maloInvalid` |
| `melo_id` | Messlokations-ID: 33 Zeichen, `DE` und 31 Ziffern oder Großbuchstaben; Kleinbuchstaben werden groß geschrieben | `errors.meter.meloInvalid` |

Die Oberfläche bietet beide Felder im Zählerdialog an (nicht bei Heizöl und
Pellets); die Wechselentscheidung zeigt die MaLo-ID unter „Für den Wechsel
bereithalten“.

### Zähler: `digits` *(v2.6.0, additiv)*

Stellen des Zählwerks vor dem Komma (3–12) je Gerät. Beim Anlegen als
Einzelfeld `digits` oder im Gerät; `PATCH …/meters/{id}` mit `digits` setzt es
am eingebauten Gerät (`null`/leer entfernt es); `replace-device` übernimmt es
auf das neue Gerät, sofern nicht anders angegeben. Ungültig → 400
`errors.meter.digitsInvalid`.

Seit v2.6.0 werden auch die bis v2.5.3 in `docs/API.md` beschriebenen
Körper angenommen: beim Anlegen ein Objekt `device {serial, installed_on,
initial_counter}`, beim Zählertausch `removed_on`, `final_counter` und
`new_device {serial, installed_on, initial_counter}`. Die aktuellen Namen
haben Vorrang.

### Verbrauch je Zeitraum *(v3.1.0)*

Die dritte Erfassungsart neben Zählerständen und Lieferungen: Je Zeitraum
steht der Verbrauch selbst — etwa aus der monatlichen Verbrauchsinfo des
Messdienstes (HeizkostenV § 6a), aus Intervallwerten eines Portals oder von
einem Wärmezähler, von dem nur Monatswerte bekannt sind. Gilt für Zähler mit
`capture: "period"` ([s. o.](#zähler-role-capture-v310-additiv)) aller Arten
mit Zählerständen, nicht für Heizöl und Pellets (dort `400`
`errors.common.unknownUtility`). Stabilitätsklasse A (Stammdaten). Hintergrund:
[Heizwärme und Verbrauch je Zeitraum](../verstehen/15-waerme.md).

Ein Zeitraum im Topf `<utility>/periods.json` (Beispielwerte):

```json
{ "id": "p_8f2c41d0a9b3", "meter_id": "m_waerme_1",
  "from": "2026-09-01", "to": "2026-09-30",
  "value": 410.0, "value_unit": "consumption",
  "is_estimated": false, "source": "manual",
  "reference": { "prev_month": 180.0, "prev_year_month": 450.0, "average_user": 390.0 },
  "note": "Verbrauchsinfo September",
  "attachment_id": "att_5f0c2a9e81d34b67",
  "client_ref": "7d0f6c1e-2b8a-4c55-9e3d-0a1b2c3d4e5f" }
```

| Feld | Bedeutung |
|---|---|
| `from`, `to` | erster und letzter Tag, **beide einschließlich** (`JJJJ-MM-TT`). Kein Kalenderdatum → `400` `errors.period.dateInvalid`; Beginn nach Ende → `400` `errors.period.order` |
| `month` | nur beim Schreiben: `JJJJ-MM` als Kurzform für einen ganzen Monat statt `from`/`to` (ungültig → `errors.period.dateInvalid`) |
| `value` | Verbrauch im Zeitraum, Zahl ≥ 0 (als Text auch mit Dezimalkomma), auf drei Nachkommastellen gerundet; sonst `400` `errors.period.valueInvalid` |
| `value_unit` | `consumption` (Standard) = Verbrauchseinheit der Art (kWh, bei Wasser m³) oder `meter` = Zählereinheit. Verschieden sind sie nur bei Gas: `meter` heißt m³, gerechnet mit den datierten Faktoren in kWh; bei `consumption` rechnet die App die m³ zurück (Rechnungsprüfung, CSV). Bei allen anderen Arten wird immer `consumption` gespeichert |
| `is_estimated` | geschätzt (Standard `false`) |
| `source` | `manual` (Standard), `csv` (CSV-Import) oder `import` |
| `reference` | optional, die Vergleichswerte der Verbrauchsinfo: `prev_month` (Vormonat), `prev_year_month` (Vorjahresmonat), `average_user` (Durchschnittsnutzer), je Zahl ≥ 0; ohne Werte `null`. Nur zur Anzeige, sie gehen nicht in die Rechnung ein |
| `note` | höchstens 500 Zeichen |
| `attachment_id` | optional: Beleg zum Zeitraum, etwa die Verbrauchsinfo als PDF ([Belege](#belege-und-texterkennung-v310)); `PATCH` mit einer anderen ID ersetzt ihn, mit `null` löst ihn |
| `client_ref` | wie bei Ablesungen ([s. o.](#ablesungen-client_ref-attachment_id-v310-additiv)): Gibt es am selben Zähler schon einen Zeitraum mit dieser Kennung, antwortet `POST` mit **`200`**, dem vorhandenen Zeitraum und `duplicate: true`; sonst `201`. Falsches Format → `400` `errors.reading.clientRefInvalid` |

**Regeln.**

- `POST` braucht `meter_id` (ohne gilt der Standardzähler der Art). Unbekannter
  Zähler → `404` `errors.common.meterNotFound`; ein Zähler mit Zählerständen →
  `400` `errors.period.meterNotPeriod`.
- Zeiträume desselben Zählers dürfen sich nicht überschneiden, auch nicht an
  einem Tag → `400` `errors.period.overlap`, die Meldung nennt den vorhandenen
  Zeitraum. Lücken dazwischen sind erlaubt.
- `PATCH` ändert nur die mitgeschickten Felder; der Zähler bleibt. Unbekannte
  ID → `404` `errors.period.notFound`, ebenso bei `DELETE` (Antwort
  `{deleted: true}`; ein Beleg wird gelöst und nach 24 Stunden aufgeräumt).
- Ein Zähler mit Verbrauch je Zeitraum nimmt **keine Stände** an: `POST
  …/readings` und der Ablesungs-Import antworten `400`
  `errors.reading.periodMeter`, der Ingest `400` `errors.ingest.periodMeter`
  (im Stapel als Ergebniszeile mit diesem `code`).

**Rechnung.** Je Zeitraum eine Tagesrate = `value` ÷ Tage (von `from` bis `to`
einschließlich), tagesgenau auf die Monate verteilt. Lücken bleiben Lücken: Ein
Monat zählt nur die abgedeckten Tage (`days`), wie bei Ständen. Danach läuft
alles wie bei Zählerständen — Monatszeilen, Verträge und Saldo, CSV-Monatsübersicht,
PDF, Prognose, Wetterbereinigung. In der Agenda ist die nächste Erfassung
`alert_days_since_reading` Tage nach dem Ende des letzten Zeitraums fällig.

**CSV-Import — `POST /api/utility/{u}/meters/{id}/periods/import-csv`.** Die
CSV als roher Body, wie beim Ablesungs-Import. Je Zeile:

```text
monat;wert[;notiz]          Monat als MM.JJJJ, MM/JJJJ oder JJJJ-MM (ganzer Monat)
von;bis;wert[;notiz]        Daten wie beim Ablesungs-Import (ISO oder T.M.JJJJ …)
```

Trenner `;`, Tabulator oder `,`; Kopfzeile optional. Der eigene Export
(`periods.csv`, Format 1 und „local“) lässt sich wieder einlesen: Trägt die
Kopfzeile eine Spalte Zähler-ID, zählen nur die Zeilen dieses Zählers.
Eingelesene Zeiträume haben `source: "csv"` und `value_unit: "consumption"`.
Eine Zeile, die sich mit einem vorhandenen oder einem früheren Zeitraum der
Datei überschneidet, wird **übersprungen und gemeldet**, nicht überschrieben.
Leere Datei → `400` `errors.import.emptyCsv`.

```json
{ "imported": 11, "skipped": 1,
  "errors": [ "Zeile 5: Der Zeitraum überschneidet sich mit einem vorhandenen (2026-03-01 – 2026-03-31)." ] }
```

Zeilenfehler in `errors` (Sprache der Anfrage): `errors.period.csvDate`
(Zeitraum nicht erkannt), `errors.period.csvValue` (Wert keine Zahl oder
negativ), `errors.period.csvLine` (sonst, mit der Meldung — etwa der
Überschneidung). Mit **`?dry_run=1`** wird nichts geschrieben; die Antwort
trägt `imported: 0`, `skipped`, `errors`, `dry_run: true`, `would_import` (so
viele ließen sich einlesen) und `rows` (`[{line, from, to, value, note}]`).

**Export:** `GET /api/export/{u}/periods.csv` — alle Zeiträume der Art,
[CSV-Formate](#csv-formate-v310).

### Mietverhältnis *(v3.1.0)*

Für Mieter, die Heizung und Wasser über die Nebenkosten bezahlen (F1008). Die
App hält fest, was der Vermieter verlangt und abrechnet, und rechnet daraus
eine **Hilfsrechnung** — keine Nebenkostenabrechnung und kein
Abrechnungsprogramm für Vermieter. Die Oberfläche zeigt die Seite (Kosten &
Verträge → Mietverhältnis) nur mit der Einstellung `wohnverhaeltnis: "miete"`;
die Routen antworten unabhängig davon. Stammdaten (Mietverhältnis,
Abrechnungen) Stabilitätsklasse A, das Budget Klasse B. Schritt für Schritt:
[Als Mieter](../anleitungen/mieter.md).

Beträge (`*_eur`) stehen in der Haupteinheit der eingestellten Währung, nicht
umgerechnet. Datierte Listen (`prepayments`, `prices`, `fixed_costs`) gelten je
Eintrag ab `from`; `PATCH` nimmt eine Liste immer **ganz** entgegen und
sortiert sie nach `from`.

**Mietverhältnis** (`tenancies.json`, Beispielwerte):

```json
{ "id": "t_1a2b3c4d5e6f", "label": "Wohnung 2. OG", "landlord": "Beispiel-Verwaltung",
  "start": "2024-04-01", "end": null, "wohnflaeche_m2": 68,
  "co2_own_appliances": false, "co2_restriction": "none",
  "billing_anchor": "01-01",
  "prepayments": [ { "from": "2024-04-01", "heating_eur_month": 70, "operating_eur_month": 50 } ],
  "prices": [ { "from": "2026-01-01", "heat_eur_per_kwh": 0.15, "warm_water_eur_per_m3": 12,
                "cold_water_eur_per_m3": 5, "source": "statement", "statement_id": "s_9e8d7c6b5a40" } ],
  "fixed_costs": [ { "from": "2026-01-01", "label": "Müllabfuhr", "eur_per_year": 180 } ],
  "meter_ids": { "heat": ["m_waerme_1"], "warm_water": ["m_wasser_warm"], "cold_water": ["m_wasser_kalt"] },
  "notes": "" }
```

| Feld | Bedeutung |
|---|---|
| `start`, `end` | Beginn (Pflicht) und Ende (`null` oder leer = läuft); Ende vor Beginn → `400` `errors.tenancy.endBeforeStart` |
| `label`, `landlord`, `notes` | Text, höchstens 120 bzw. 2000 Zeichen |
| `wohnflaeche_m2` | optional, 1–10.000 — Wohnfläche laut Mietvertrag; leer gilt die Einstellung `wohnflaeche_m2`. Grundlage der CO₂-Stufe ([CO₂-Preis und Aufteilung](#co₂-preis-und-aufteilung-v310)) |
| `co2_own_appliances` | *(v3.1.0, H4)* `true`, wenn das Gas auch eigene Geräte wie einen Gasherd versorgt — die Erstattung bei Etagenheizung sinkt auf 95 % (Standard `false`) |
| `co2_restriction` | *(v3.1.0, H4)* öffentlich-rechtliche Vorgaben nach § 9 CO2KostAufG: `none` (Standard), `one` (gegen Sanierung **oder** Heizungstausch — Anteil halbiert), `both` (gegen beides — keine Aufteilung); ein unbekannter Wert wird `none` |
| `billing_anchor` | Beginn des Abrechnungszeitraums als `MM-TT`, Standard `01-01`; kein Kalendertag → `400` `errors.tenancy.anchorInvalid` |
| `prepayments` | `{from, heating_eur_month, operating_eur_month}` — Vorauszahlung je Monat für Heizung und Betriebskosten |
| `prices` | `{from, heat_eur_per_kwh?, warm_water_eur_per_m3?, cold_water_eur_per_m3?, source, statement_id?}` — Preise je kWh Wärme, je m³ Warmwasser und je m³ Kaltwasser (einschließlich Abwasser), je 0–1000; `source` `statement` (aus einer Abrechnung) oder `estimate` (Standard). Je Preis gilt der jüngste Eintrag, der ihn trägt |
| `fixed_costs` | `{from, label, eur_per_year}` — pauschale Umlagen (Müll, Hausreinigung, Versicherung …); je Bezeichnung gilt der jüngste Eintrag |
| `meter_ids` | zugeordnete Zähler: `heat` (Heizwärme), `warm_water` und `cold_water` (Wasser); unbekannte ID → `400` `errors.tenancy.meterNotFound` |

Ein Eintrag einer Liste ohne gültiges `from` → `400`
`errors.tenancy.dateInvalid`; etwas anderes als eine Liste von Objekten →
`errors.tenancy.listInvalid`; ein Betrag, der keine Zahl ist oder außerhalb
seines Bereichs liegt → `errors.tenancy.amountInvalid`. `DELETE` löscht das
Mietverhältnis **samt seinen Abrechnungen**; deren Belege werden gelöst und
nach 24 Stunden aufgeräumt. Antwort `{deleted: true}`.

**Nebenkostenabrechnung** (`tenancy_statements.json`, Beispielwerte):

```json
{ "id": "s_9e8d7c6b5a40", "tenancy_id": "t_1a2b3c4d5e6f",
  "period_from": "2025-01-01", "period_to": "2025-12-31", "received_on": "2026-06-15",
  "total_cost_eur": 1740, "prepaid_eur": 1440, "result_eur": 300,
  "positions": [
    { "label": "Heizung",     "category": "heating",    "amount_eur": 900 },
    { "label": "Warmwasser",  "category": "warm_water", "amount_eur": 240, "consumption": 20, "unit": "m³" },
    { "label": "Kaltwasser",  "category": "cold_water", "amount_eur": 150, "consumption": 60, "unit": "m³" },
    { "label": "Abwasser",    "category": "sewage",     "amount_eur": 150 },
    { "label": "Betriebskosten", "category": "operating", "amount_eur": 300 } ],
  "heat": { "consumption": 6000, "unit": "kWh", "cost_eur": 900 },
  "co2": null,
  "new_prepayment": { "from": "2026-07-01", "heating_eur_month": 80, "operating_eur_month": 55 },
  "attachment_ids": [ "att_0b1c2d3e4f5a6b7c" ], "booked": false, "note": "",
  "created_at": "2026-06-16T19:02:11+02:00" }
```

| Feld | Bedeutung |
|---|---|
| `period_from`, `period_to` | abgerechneter Zeitraum (Pflicht); Beginn nach Ende → `400` `errors.tenancy.periodInvalid` |
| `received_on` | Tag, an dem die Abrechnung ankam (optional) — Beginn der Einwendungsfrist in der Agenda |
| `total_cost_eur`, `prepaid_eur` | Kosten insgesamt und angerechnete Vorauszahlungen |
| `result_eur` | Ergebnis, **positiv = Nachzahlung**, negativ = Guthaben (wie Saldo und Budget). Ohne Angabe `total_cost_eur − prepaid_eur`, auch nach einer Änderung dieser beiden |
| `positions` | Posten `{label, category, amount_eur, consumption?, unit?}`; `category` ∈ `heating`, `warm_water`, `cold_water`, `sewage`, `operating`, `other` (unbekannt → `other`) |
| `heat` | optional: Wärmeverbrauch laut Abrechnung `{consumption, unit: kWh \| MWh, cost_eur?}` |
| `co2` | optional: die CO₂-Angaben der Heizkostenabrechnung (§ 7 CO2KostAufG) — `{emissions_kg?, cost_eur?, stage?, landlord_share_pct?, landlord_amount_eur?}`; Emissionen 0–10.000.000 kg, Beträge 0–1.000.000, `stage` 1–10, `landlord_share_pct` 0–100, sonst `400` `errors.tenancy.amountInvalid`; ohne Werte `null`. Seit v3.1.0 (H4) geprüft statt wie geliefert gespeichert. Ist er gesetzt, rechnet `GET /api/co2-split` für das Jahr, in dem der Zeitraum endet, den Fall Zentralheizung |
| `new_prepayment` | optional: neue Vorauszahlung laut Abrechnung `{from, heating_eur_month, operating_eur_month}` |
| `attachment_ids` | Belege (PDF oder Foto, [Belege](#belege-und-texterkennung-v310)); ein aus der Liste entfernter Beleg wird gelöst |
| `booked` | gebucht ja/nein (Standard `false`) |
| `note`, `created_at` | Notiz (höchstens 2000 Zeichen), Zeitpunkt des Anlegens |

**Übernehmen beim Speichern.** `POST` und `PATCH` einer Abrechnung nehmen zwei
Schalter an, die selbst nicht gespeichert werden:

- `apply_prices: true` — Preise aus der Abrechnung ins Mietverhältnis: Kosten
  der Kategorie (Grundkosten eingeschlossen) geteilt durch ihren Verbrauch.
  Wärme = `heat.cost_eur` (ohne Angabe die Summe der Posten `heating`) ÷
  `heat.consumption` (MWh × 1000); Warmwasser = Posten `warm_water` ÷ ihr
  Verbrauch; Kaltwasser = Posten `cold_water` **und** `sewage` ÷ Verbrauch der
  Posten `cold_water` (Abwasser je m³ Frischwasser). Fehlt ein Verbrauch, fehlt
  dieser Preis. Der neue Eintrag gilt ab dem Tag nach `period_to`, mit `source:
  "statement"` und `statement_id`; ein früher aus derselben Abrechnung
  übernommener Eintrag wird ersetzt. Im Beispiel: 900 € ÷ 6.000 kWh =
  0,15 €/kWh, 240 € ÷ 20 m³ = 12 €/m³, (150 € + 150 €) ÷ 60 m³ = 5 €/m³.
- `apply_prepayment: true` — `new_prepayment` in `prepayments` übernehmen
  (ein Eintrag mit demselben `from` wird ersetzt).

Unbekanntes Mietverhältnis → `404` `errors.tenancy.notFound`, unbekannte
Abrechnung → `404` `errors.tenancy.statementNotFound`.

**Budget — `GET /api/tenancies/{id}/budget[?as_of=JJJJ-MM-TT]`.** Vorauszahlung
gegen die Kosten, die der laufende Abrechnungszeitraum voraussichtlich bringt —
eine Hilfsrechnung aus den eigenen Zählern und den Preisen der letzten
Abrechnung; was der Vermieter abrechnet, kann abweichen. `as_of` ist der
Stichtag (fehlt er oder ist er kein Datum: heute).

- **Zeitraum:** ein Jahr ab dem `billing_anchor`, in das `as_of` fällt,
  begrenzt auf Beginn und Ende des Mietverhältnisses — der erste Zeitraum
  beginnt mit dem Einzug. Monate am Rand zählen anteilig (`share`).
- **Je Monat** (Preise, Vorauszahlung und Umlagen gelten zum 15.):

```text
erwartet = Wärme-kWh × Preis je kWh + Warmwasser-m³ × Preis + Kaltwasser-m³ × Preis
           + Umlagen je Jahr / 12                                    (alles × share)
bezahlt  = Vorauszahlung Heizung + Vorauszahlung Betriebskosten     (× share)
```

- **Verbrauch:** gemessen aus den zugeordneten Zählern (Stände oder Zeiträume),
  wenn sie den Monat ganz abdecken; sonst geschätzt — Wärme aus dem Heizmodell
  des Zählers mit dem Klimanormal, sonst aus dem Vorjahresmonat, sonst aus dem
  Tagesmittel aller bekannten Monate. `measured` ist ein Monat, der bis `as_of`
  abgelaufen ist und von allen Zählern ganz abgedeckt wird.

```json
{ "success": true, "data": {
  "tenancy_id": "t_1a2b3c4d5e6f",
  "period_from": "2026-01-01", "period_to": "2026-12-31", "as_of": "2026-10-07",
  "months": [
    { "ym": "2026-01", "share": 1, "heat_kwh": 1200.0, "warm_water_m3": 1.7, "cold_water_m3": 2.0,
      "expected_eur": 225.4, "prepaid_eur": 120, "balance_eur": 105.4, "measured": true },
    { "…": "…" } ],
  "expected_eur": 1500.0, "prepaid_eur": 1440, "projected_result_eur": 60.0,
  "to_date": { "expected_eur": 1150.0, "prepaid_eur": 1080, "result_eur": 70.0 },
  "risk": "medium", "suggested_prepayment_eur": 125,
  "components": { "heat_eur": 960.0, "warm_water_eur": 240.0, "cold_water_eur": 120.0, "fixed_eur": 180.0,
                  "heat_kwh": 6400.0, "warm_water_m3": 20.0, "cold_water_m3": 24.0 },
  "assumptions": [ "months_estimated" ], "months_estimated": 3 } }
```

Im Beispiel: 6.400 kWh × 0,15 € + 20 m³ × 12 € + 24 m³ × 5 € + 180 € Umlagen =
1.500 € erwartet, gegen 12 × 120 € = 1.440 € Vorauszahlung → 60 €
voraussichtliche Nachzahlung, gut 4 % der Vorauszahlung, also `medium`;
passende Vorauszahlung 1.500 € ÷ 12 = 125 €. Oktober bis Dezember sind
geschätzt.

| Feld | Bedeutung |
|---|---|
| `tenancy_id`, `period_from`, `period_to`, `as_of` | Mietverhältnis, Abrechnungszeitraum, Stichtag |
| `months[]` | je Monat `ym`, `share` (Anteil am Zeitraum, 0–1), `heat_kwh`, `warm_water_m3`, `cold_water_m3`, `expected_eur`, `prepaid_eur`, `balance_eur` (aufgelaufen: erwartet − bezahlt), `measured` |
| `expected_eur`, `prepaid_eur` | Summen über den ganzen Zeitraum, gemessen und geschätzt |
| `to_date` | `{expected_eur, prepaid_eur, result_eur}` — nur über die gemessenen Monate |
| `projected_result_eur` | `expected_eur − prepaid_eur`: positiv = voraussichtliche Nachzahlung, negativ = Guthaben |
| `risk` | `low` (Ergebnis ≤ 0), `medium` (Nachzahlung bis 10 % der Vorauszahlung), `high` (mehr); `null` ohne Vorauszahlung |
| `suggested_prepayment_eur` | erwartete Kosten des Zeitraums ÷ Zahl seiner Monate, auf ganze Euro aufgerundet — bei einem vollen Jahr die Jahreskosten / 12 |
| `components` | Summen des Zeitraums: `heat_eur`, `warm_water_eur`, `cold_water_eur`, `fixed_eur`, `heat_kwh`, `warm_water_m3`, `cold_water_m3` |
| `assumptions` | was die Rechnung annimmt: `price_missing_heat`, `price_missing_warm_water`, `price_missing_cold_water` (Zähler zugeordnet, aber kein Preis — die Kosten fehlen), `prepayment_missing` (für einen Monat keine Vorauszahlung), `months_estimated`, `no_meters` (nur Umlagen) |
| `months_estimated` | Zahl der geschätzten Monate |

Die Fristen der Nebenkostenabrechnung stehen in der [Agenda](#get-apiagendadays90-v310)
und im Kalender, sobald `wohnverhaeltnis` auf `miete` steht. Die Aufteilung der
CO₂-Kosten zwischen Mieter und Vermieter:
[CO₂-Preis und Aufteilung](#co₂-preis-und-aufteilung-v310).

### `GET|PATCH /api/settings` — `gas_conversion_factors` *(F1012, v2.5.0)*

Der Skalar `gas_conversion_factor` ist seit Schema 1.5.0 eine **datierte
Liste**; die Migration wandelt den Altwert in den undatierten Eintrag um:

```json
"gas_conversion_factors": [
  { "from": null,         "zustandszahl": null, "brennwert": null,  "kwh_per_m3": 11.5 },
  { "from": "2024-01-01", "zustandszahl": 0.96, "brennwert": 11.4,  "kwh_per_m3": 10.944 },
  { "from": "2025-01-01", "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184 }
]
```

- `PATCH` nimmt die ganze Liste entgegen (kein Einzel-Edit); Dezimalkomma
  wird akzeptiert. Sind `zustandszahl` **und** `brennwert` gesetzt, wird
  `kwh_per_m3` daraus berechnet (5 Nachkommastellen) und überschreibt einen
  mitgeschickten Wert. Nur einer der beiden → 400.
- Plausibilität (400 mit `errors.settings.*`): Zustandszahl 0,8–1,1,
  Brennwert 8–13, Faktor 5–15; höchstens ein undatierter Eintrag; keine
  doppelten Daten. Die Antwort ist nach `from` sortiert, undatiert zuerst.
- Leere Liste → Default (undatiert 11,5).
- Wirkung: `kwh` in allen Verbrauchsantworten rechnet **tagesgenau** mit
  dem am jeweiligen Tag gültigen Faktor; ein Stichtag mitten im
  Ableseintervall teilt das Intervall.

### Angenommene Angaben: `assumed_keys` *(v3.6.0)*

`GET /api/settings`, `PATCH /api/settings` und `POST /api/settings/country`
liefern neben den Einstellungen die Liste `assumed_keys` (additiv): Angaben,
deren Wert nur der Standard ist, weil niemand sie eingetragen hat — heute
`wohnflaeche_m2` und `wasser_personen_anzahl`.

```json
{ "wohnflaeche_m2": 100, "wasser_personen_anzahl": 3, …,
  "assumed_keys": ["wohnflaeche_m2"] }
```

- Der Wert selbst bleibt der Standard; Effizienzklasse, CO₂-Stufe,
  Wasser-Spar-Index und Jahresbericht rechnen damit wie bisher. Nur die
  Einordnung (`GET /api/benchmarks/comparison`) wartet auf den Eintrag.
  Wo die angenommene Fläche einfließt, sagt es die Antwort mit
  `area_assumed: true`: `GET /api/benchmarks/efficiency`, `GET /api/co2-split`
  und `per_m2` in `GET /api/co2-costs` (dort zählt zuerst die Fläche im
  Mietverhältnis).
- Eingetragen heißt: `settings.json` trägt den Schlüssel — gespeichert per
  `PATCH`, aus einem Backup oder einem Beispielhaushalt, auch mit einem Wert
  gleich dem Standard. `PATCH` mit `null` oder `""` nimmt die Angabe zurück
  (der Schlüssel verschwindet aus `settings.json`).
- `assumed_keys` ist keine Einstellung: In einem `PATCH` steht der Schlüssel
  unter `ignored_keys`.
- *(v3.7.0, additiv)* `example_keys`: dieselbe Form für die Beispielwerte der
  Flexibilität (`flex_*`, [Einstellungen](einstellungen.md#flexibilität-v370)),
  die niemand eingetragen hat. Gerechnet wird mit dem Standard; eingetragen
  (auch gleich dem Standard) heißt „eigener Wert“, `null` oder `""` nimmt ihn
  zurück. Auch `example_keys` ist keine Einstellung.

### Länderprofil: `country`, `currency`, `timezone`, `gas_cv_unit` *(v2.7.0, additiv)*

Vier neue Einstellungen, Defaults = bisheriges Verhalten (`DE`, `EUR`,
`Europe/Berlin`, `kwh`). `PATCH` lehnt unbekannte Werte mit 400 ab
(`errors.settings.valueInvalid`):

| Schlüssel | Erlaubt | Wirkung |
|---|---|---|
| `country` | `DE AT CH FR IT ES PT NL GB` | Schreibweise von Zahlen und Daten (mit der Sprache), Effizienzskala |
| `currency` | `EUR CHF GBP` | Symbol und Untereinheit in allen Texten. Beträge werden **nicht** umgerechnet; `*_eur`/`ct_per_kwh` bedeuten Haupt-/Untereinheit der gewählten Währung |
| `timezone` | IANA-Name (`Europe/Vienna`) | PHP-Zeitzone (heute, Fälligkeiten) und Tagesgrenzen der Wetterdaten |
| `gas_cv_unit` | `kwh mj gj` | Eingabeeinheit des Brennwerts in der Oberfläche; gespeichert wird immer kWh/m³ |

`GET /api/countries` liefert die Profile, aus denen die Oberfläche beim
Landeswechsel Werte vorschlägt (Klasse C):

```json
{ "code": "AT", "languages": ["de"], "currency": "EUR", "timezone": "Europe/Vienna",
  "hdd_base_temp": 15.0, "co2_strom": 103.0, "co2_strom_source": "ember-2024",
  "efficiency_scale": null, "gas_cv_unit": "kwh",
  "location_name": "Wien", "latitude": 48.2082, "longitude": 16.3738 }
```

Das deutsche Profil trägt zusätzlich `co2_strom_years` (Jahreswerte des
Umweltbundesamts, seit v2.10.0) und `"co2_strom_source": "uba"`.

**Seit v3.1.0** additiv je Profil:

```json
"bill_terms": { "workingPrice": "Energie-Verbrauchspreis", "basePrice": "Energie-Grundpreis",
                "advance": "Teilbetrag", "calorificValue": "Verrechnungsbrennwert",
                "balance": "Nachzahlung / Guthaben" },
"comparison_portal": "https://www.e-control.at/tarifkalkulator"
```

- `bill_terms`: Glossar-ID (`glossary.<id>` im Katalog) → Wortlaut auf der
  Rechnung des Landes, in der Sprache der Rechnung. Nur belegte Begriffe —
  fehlt eine ID, gibt es dort keinen gleichbedeutenden Posten. Die Schweiz
  trägt eine Ebene mehr, je Sprache: `{"de": {…}, "fr": {…}, "it": {…}}`.
- `comparison_portal`: Adresse des amtlichen bzw. regulatorischen
  Tarifvergleichs oder `null` (Deutschland, Schweiz, Niederlande, Vereinigtes
  Königreich).

Begriffe, Portale und Quellen:
[Länderprofile §8](../verstehen/14-laenderprofile.md#8-so-heißt-das-auf-deiner-rechnung).

Beim **Erststart** (leeres Datenverzeichnis) wählt die App Sprache und Land
aus `Accept-Language` und schreibt nur die Werte, die vom Default abweichen.
Danach ändert die Kopfzeile nichts mehr; ein Gerät wählt seine Sprache seit
v3.1.0 über `X-ET-Language` ([Sprache der Antworten](#sprache-der-antworten-v310)). Details:
[Länderprofile](../verstehen/14-laenderprofile.md).

**Land wechseln *(v3.5.0)*:** `POST /api/settings/country` mit
`{"country": "AT", "profile": true}` setzt das Land und alle Werte seines
Profils (`currency`, `timezone`, `hdd_base_temp`, `co2_strom`,
`co2_strom_years`, Standort, `gas_cv_unit`), mit `"profile": false` nur das
Land. Es gilt dieselbe Regel wie beim Erststart: Ein Wert, der dem Default
entspricht, wird nicht festgeschrieben, eine frühere Festschreibung entfällt.
Wer nach einem englischen Erststart (Profil GB) Deutschland wählt, hat danach
keinen Profilwert mehr in `settings.json`. Antwort: alle Einstellungen wie bei
`PATCH`; ein unbekanntes Land endet mit 400 `errors.settings.valueInvalid`.
Einrichtungsassistent und Einstellungen wechseln das Land über diesen Weg;
`PATCH /api/settings` mit `country` bleibt gültig.

### CO₂-Faktoren je Jahr, korrigierte Standardwerte *(v2.10.0)*

- `co2_strom_years`: Objekt `{"2024": 353, "2025": 344}` (Jahr → g/kWh).
  `PATCH` nimmt es auch als Liste `[{year, g_per_kwh}]`; Jahre 1990–2100,
  Werte 0–2000, sonst 400 `errors.settings.valueInvalid`. Für ein Jahr gilt
  der Wert des letzten eingetragenen Jahres bis dahin, davor `co2_strom`.
  Monatszeilen (`co2_kg`) rechnen mit dem Faktor ihres Jahres.
- Neue Defaults mit Quelle: `co2_gas` 182 (BAFA 201 auf Heizwert × 0,906),
  `co2_pellets` 36, `co2_fernwaerme` 280, `co2_strom_years` Umweltbundesamt
  2015–2025, `wasser_personen_referenz` 122 (BDEW 2024).
- **Bestandsinstallationen:** Die Migration 1.6.0 schreibt für jeden dieser
  Schlüssel, den die Installation nie gespeichert hat, den bisherigen
  Default fest (`co2_strom_years: []`). `GET /api/settings/default-updates`
  liefert, was sich übernehmen ließe:
  `[{ "key": "co2_gas", "current": 201, "recommended": 182 }, …]` —
  nur Schlüssel, die noch genau den alten Default tragen. Übernommen wird per
  `PATCH /api/settings`.
- `beheizter_keller`, `warmwasser_dezentral` (bool, Default `false`) für
  die energieausweis-nahe Kennzahl.

### PV: Quoten, Ersparnis, Tarifrang *(v2.10.0)*

`GET /api/pv-summary`: Monatszeilen tragen `covered` (alle drei Zähler
haben Daten), `bezug_price_ct`, `savings_eur` (Eigenverbrauch ×
Arbeitspreis des Bezugs) und `feed_in_revenue_eur`; in nicht abgedeckten
Monaten sind `eigenverbrauch_kwh` und die Quoten `null`. Jahreszeilen
tragen `months_with_data`, `months_covered`, `savings_eur`,
`feed_in_revenue_eur`, `pv_benefit_eur`; Eigenverbrauch und Quoten
rechnen nur über abgedeckte Monate (bis v2.9 über alle — mit „0" bei
ungleicher Abdeckung).

`tariff-switch` und `tariff-comparison` liefern `higher_is_better`: bei der
Einspeisung `true`, die Angebote stehen dann nach **höherem** Erlös sortiert.

### PV: Speicher, Balkonkraftwerk, Amortisation *(v3.1.0, additiv)*

**Felder am Zähler** (`pv_erzeugung`, alle optional; leer bzw. `false`
entfernt sie, Ungültiges → `400` `errors.meter.valueInvalid`):

| Feld | Bedeutung |
|---|---|
| `plug_in` | `true` = Balkonkraftwerk (Steckersolargerät) ohne Einspeisezähler |
| `investment_eur` | Investition brutto, 0–10.000.000 |
| `commissioned_on` | Tag der Inbetriebnahme (ISO) |
| `battery_capacity_kwh` | Speicherkapazität, 0–10.000 — am Zähler mit der Rolle `battery_charge` |

Zähler mit den Rollen `battery_charge` und `battery_discharge` zählen nicht zur
Erzeugung (ebenso wenig in Summen). Der Eigenverbrauch bleibt definiert wie
bisher; die Autarkie rechnet seit v3.4.0 mit dem Energiefluss
([unten](#schaubilder-energiefluss-und-zeitstrahl-v340-additiv)).

**`GET /api/pv-summary`** additiv:

- je Jahreszeile `battery: {charged_kwh, discharged_kwh, losses_kwh,
  efficiency_pct, full_cycles}` oder `null` (ohne Speicherzähler);
  `full_cycles` = geladen ÷ Kapazität, nur mit `battery_capacity_kwh`.
- `payback: {investment_eur, commissioned_on, benefit_to_date_eur,
  avg_benefit_12m_eur, years_to_break_even, break_even_ym,
  break_even_projected}` — `null` ohne Investition. Nutzen = vermiedener Bezug
  (Eigenverbrauch × Arbeitspreis) + Einspeiseerlös seit der Inbetriebnahme; ist
  die Investition noch nicht zurück, rechnet `break_even_ym` mit dem Nutzen der
  letzten zwölf Monate hoch (`break_even_projected: true`). Beispiel: 800 € bei
  160 € im Jahr = 5 Jahre.
- `self_consumption_assumed_pct` — gesetzt, wenn ein Erzeugungszähler
  `plug_in` trägt, es keinen Einspeisezähler gibt und die Einstellung
  `pv_assumed_self_consumption_pct` einen Wert hat. Dann ist der Eigenverbrauch
  Erzeugung × Anteil, und die Monate tragen `self_consumption_assumed: true`.
- `hints: ["negative_prices"]` — Land Deutschland und Inbetriebnahme ab dem
  25.02.2025: Für Zeiten mit negativem Börsenpreis gibt es keine Vergütung —
  bei Anlagen unter 100 kW ab dem Kalenderjahr nach dem Einbau eines
  intelligenten Messsystems (§ 51 EEG). Zum Teil ausgeglichen wird das durch
  einen längeren Vergütungszeitraum (§ 51a EEG).
- *(seit v3.7.0, #39)* `hints` enthält `"interpolated"`, wenn ein Monat auf
  einer verteilten Reihe beruht; dazu `interpolation: {utility, readings, from,
  to, months, interpolated_months}` — die Reihe mit den meisten verteilten
  Monaten und ihre echten Ablesungen, sonst `null`. Je Monatszeile
  `interpolated` (bool) und `interpolated_series` (welche Felder:
  `erzeugung_kwh`, `einspeisung_kwh`, `bezug_kwh`), je Jahreszeile
  `months_interpolated`. Eigenverbrauch, Autarkie, Ersparnis und Amortisation
  dieser Monate sind Schätzungen; ihre Werte bleiben unverändert.

Die Einstellung `co2_pv_avoided` (g/kWh) ersetzt für PV-Erzeugung und
-Einspeisung den Strommix im vermiedenen CO₂ ([Einstellungen](einstellungen.md)).
Gutschriften des Direktvermarkters an der Einspeisung:
[Verträge](#verträge-reduziertes-netzentgelt-preismodell-gutschriften-v310-additiv).

### Schaubilder: Energiefluss und Zeitstrahl *(v3.4.0, additiv)*

**`GET /api/pv-summary`**, je Monatszeile zusätzlich:

| Feld | Bedeutung |
|---|---|
| `battery` | `{charged_kwh, discharged_kwh}` des Monats; `null` ohne Speicherzähler |
| `heat_pump_kwh` | Verbrauch der Stromzähler mit der Rolle `heat_pump` im Monat (auch als Subzähler); `null` ohne solchen Zähler |
| `ev_charger_kwh` | dasselbe für die Rolle `ev_charger` (Wallbox) |
| `flow` | Energiefluss des Monats (s. u.); `null`, wenn der Monat nicht abgedeckt ist (`covered: false`) |

Je Jahreszeile zusätzlich `heat_pump_kwh` und `ev_charger_kwh` (Summe aller
Monate, wie `bezug_kwh`) und `flow`: die Summe der Monatsflüsse über die
**abgedeckten** Monate, mit `months` (= `months_covered`); `null` ohne
abgedeckten Monat. So rechnen Kennzahl und Schaubild über dieselben Monate.

`flow` — alle Mengen in kWh:

| Feld | Bedeutung |
|---|---|
| `pv_house`, `pv_battery`, `pv_grid` | Sonne → Haus, → Speicher, → Netz (Summe = Erzeugung) |
| `grid_house`, `grid_battery` | Netz → Haus, Netz → Speicher (Summe = Bezug) |
| `battery_house` | Speicher → Haus (= entladen) |
| `house` | Hausverbrauch = `pv_house + battery_house + grid_house` |
| `grid_in_house` | Netzstrom im Haus, auch über den Speicher |
| `heat_pump`, `ev_charger` | Anteil Wärmepumpe und Wallbox am Hausverbrauch; `null` ohne Zähler |
| `autarky` | `1 − grid_in_house / house` (0–1), `null` unter 0,1 kWh |
| `months` | nur in Jahreszeilen: Zahl der Monate |

`pv_battery` und `grid_battery` sind aus Monatssummen gerechnet: Die Sonne lädt
zuerst den Speicher, den Rest lieferte das Netz. Formel:
[PV §4](../verstehen/12-pv.md#4-eigenverbrauch--autarkiequote).

**Geänderte Werte:** `autarkiequote` (Monat und Jahr) ist seit v3.4.0
`flow.autarky`. Ohne Speicher ist das dieselbe Zahl wie bisher; mit Speicher
zählen seine Verluste nicht mehr als selbst genutzt, die Quote sinkt leicht.
`eigenverbrauch_kwh` und `eigenverbrauchsquote` bleiben unverändert;
`summary.pv.autarky_pct_ytd` folgt der Kennzahl.

**`contract-status`**, je Vertrag zusätzlich:

| Feld | Bedeutung |
|---|---|
| `min_term_end` | Ende der Mindestlaufzeit aus dem Vertrag oder `null` |
| `price_guarantee_until` | Ende der Preisgarantie aus dem Vertrag oder `null` |
| `auto_renews` | verlängert sich ohne Kündigung (bool, wirksamer Wert: fehlt im Vertrag = `true`) |

Die Bedeutung von `cancel_by`, `switch_date` und `notice_basis` bleibt
unverändert; das Schaubild „Vertrag auf einen Blick“ zeigt bei verlängerten und
unbefristeten Verträgen „jederzeit kündbar“ und als frühestes Ende
`switch_date − 1`.

### Tagesfilm: Energiefluss je Viertelstunde *(v3.5.0)*

Der Energiefluss eines Tages aus den Viertelstundenreihen
([Flexibilität](#flexibilität-bewerten-v330), Ablage im
[Datenmodell](datenmodell.md#viertelstundenreihen-intervalsjson-v330)) — für
das Schaubild „Energiefluss im Haus“, Stufe **„Tag“**
([Ansichten](ansichten.md#1-übersicht-dashboard)). Klasse B, nur lesend.

| Route | Zweck |
|---|---|
| `GET /api/energy-flow/days` | Tage und Monate, die sich zeigen lassen; Vorbelegung |
| `GET /api/energy-flow/day?date=JJJJ-MM-TT` | ein Tag je Viertelstunde |
| `GET /api/energy-flow/typical-day?month=JJJJ-MM` | typischer Tag eines Monats |

**Welche Zähler.** Wie im Energiefluss von Monat und Jahr: Netzbezug sind die
Stromzähler, die in Summen zählen — **Subzähler zählen nicht** (F1006);
Einspeisung und Erzeugung die PV-Zähler, die in Summen zählen; der Speicher die
Zähler mit den Rollen `battery_charge` und `battery_discharge`. Eine Art ohne
Zähler ist 0 (kein PV: Erzeugung und Einspeisung 0, das Haus ist der Bezug).

**Vollständig oder Lücke.** Eine Viertelstunde ist vollständig, wenn jeder
Zähler, der an dem Tag in Betrieb ist (aktiv, mit eingebautem Gerät), einen
Wert hat. Sonst ist sie eine **Lücke**: die fehlende Art `null`, `house` und
`flow` `null` — nie eine stille 0. Gibt es Erzeugung ohne Einspeisezähler
(oder umgekehrt), fehlt die andere Hälfte immer: Ohne sie lässt sich die
Erzeugung nicht aufteilen. Ohne Viertelstunden des Netzbezugs gibt es keinen
Tagesfilm.

**Ortszeit.** Ein Tag läuft von Mitternacht bis Mitternacht in der Zeitzone
der Installation (`timezone`): 96 Viertelstunden, am Tag der Umstellung im
März 92 (2:00–2:45 gibt es nicht), im Oktober 100 (2:00–2:45 zweimal, das
zweite Mal mit `"repeat": true`). Der typische Tag mittelt nach Uhrzeit:
96 Viertelstunden, `n` = Zahl der Viertelstunden im Mittel.

**`GET /api/energy-flow/day?date=2025-06-14`** (Werte erfunden, gekürzt):

```json
{ "mode": "day", "date": "2025-06-14", "month": "2025-06",
  "timezone": "Europe/Berlin", "step_minutes": 15,
  "slots": [
    { "t": "00:00", "grid": 0.082, "gen": 0, "feed": 0, "charged": 0, "discharged": 0.05,
      "house": 0.132, "price": 9.84,
      "flow": { "pv_house": 0, "pv_battery": 0, "pv_grid": 0, "grid_house": 0.082, "grid_battery": 0,
                "battery_house": 0.05, "house": 0.132, "grid_in_house": 0.082, "autarky": 0.3788 } },
    "…",
    { "t": "15:00", "grid": null, "gen": 0.41, "feed": 0.12, "charged": 0, "discharged": 0,
      "house": null, "price": 7.9, "flow": null },
    "…" ],
  "totals": { "grid": 6.412, "gen": 24.87, "feed": 11.903, "charged": 4.2, "discharged": 3.75,
              "flow": { "house": 18.929, "grid_in_house": 6.412, "autarky": 0.6613, "…": "…" },
              "house": 18.929, "autarky": 0.6613 },
  "completeness": { "slots": 96, "complete": 94, "price": 96 },
  "kinds": { "gen": true, "feed": true, "battery": true, "price": true },
  "missing": [] }
```

| Feld | Bedeutung |
|---|---|
| `slots[].t` | Beginn der Viertelstunde, Ortszeit `HH:MM`; `repeat` nur bei der zweiten Stunde im Oktober |
| `slots[].grid`, `gen`, `feed`, `charged`, `discharged` | kWh in der Viertelstunde (Summe der Zähler der Art); `null` = fehlt |
| `slots[].house` | Hausverbrauch aus dem Fluss; `null` in einer Lücke |
| `slots[].price` | Börsenpreis der Viertelstunde in ct/kWh (SMARD, DE-LU); `null` ohne Preis |
| `slots[].flow` | Fluss der Viertelstunde wie in [Schaubilder](#schaubilder-energiefluss-und-zeitstrahl-v340-additiv), Mengen in kWh mit 4 Stellen; `null` in einer Lücke |
| `slots[].n` | nur typischer Tag: Zahl der Viertelstunden im Mittel |
| `totals` | Mengen über die vollständigen Viertelstunden und der Fluss **aus diesen Mengen** — gerechnet wie ein Monat, damit Netzladung Netzstrom bleibt, auch wenn der Speicher erst abends entlädt; `house`, `autarky` daraus |
| `completeness` | Zahl der Viertelstunden, der vollständigen und der mit Preis |
| `kinds` | welche Arten es im Haushalt gibt (für die Knoten des Bildes) |
| `missing[]` | `{kind, utility, meter_id, name}`: Zähler, die an dem Tag Werte liefern müssten, aber keine Viertelstundenreihe haben; `meter_id: null` = fehlende Hälfte der PV |
| `days` | nur typischer Tag: Tage des Monats mit mindestens einer vollständigen Viertelstunde |

Leistung in kW ist `kWh × 60 / step_minutes` (bei 15 Minuten × 4).

**`GET /api/energy-flow/days`**: `available` (bool), `days[]` (Tage mit
mindestens einer Viertelstunde Netzbezug, aufsteigend, ohne heute und später),
`complete_days[]` (davon ohne Lücke), `months[]`, `default_day` (letzter
vollständiger Tag, sonst der letzte mit Daten) und `default_month` (jüngster
Monat) — `null` ohne Daten.

Fehler: `400` mit `errors.energyFlow.dateInvalid` bzw.
`errors.energyFlow.monthInvalid`, wenn `date` kein Kalendertag oder `month`
kein Monat ist oder das Jahr außerhalb 2000–2100 liegt.

### `GET /api/utility/{u}/meters/{id}/bill-check?from=…&to=…` *(F1012, v2.5.0; alle Arten seit v3.1.0)*

Rechnet die Versorgerrechnung nach: ein Abschnitt je Grenze im Zeitraum. `to`
ist exklusiv, `to_inclusive` das letzte Tagesdatum des Abschnitts;
`from < to` in ISO-Form, sonst 400 `errors.billCheck.invalidRange`.

**Seit v3.1.0 (Paket H5)** für **alle Arten mit Zählerständen und Vertrag**:
Gas, Strom, Wasser, Fernwärme (`supports_bill_check` in `GET /api/utilities`).
Heizöl, Pellets, PV-Einspeisung, PV-Erzeugung und Heizwärme nicht — dort
`400` mit dem neuen Code `errors.billCheck.unsupportedUtility`. Bis v3.0 hieß
der Fehler `errors.billCheck.gasOnly`; der Schlüssel bleibt im Katalog, wird
aber nicht mehr gesendet (**veraltet**). Unbekannter Zähler → `404`.

Grenzen der Abschnitte:

- **Gas:** Anfang, Ende, jede Ablesung, jeder Brennwert- bzw.
  Zustandszahlwechsel — wie bisher. Ändert sich der Preis innerhalb eines
  Abschnitts, teilt die Kostenrechnung ihn intern nach Tagen; eine eigene Zeile
  entsteht nicht.
- **Strom, Wasser, Fernwärme:** Anfang, Ende, jede Ablesung und jeder Stichtag
  der Verträge (Beginn, Tag nach dem Ende, Arbeits-, Grund-, Leistungs- und
  Messpreis, Abschläge) — `reason` dann `price`, mit einer Ablesung am selben Tag
  `reading+price`. Verbrauch je Abschnitt in `kwh` (Wasser: `m3`).

Die Gas-Antwort (Kostenfelder seit v3.1.0, s. u.):

```json
{
  "from": "2025-01-01", "to": "2026-01-01",
  "rows": [
    { "from": "2025-01-01", "to": "2025-03-10", "to_inclusive": "2025-03-09",
      "days": 68, "reason": "start",
      "m3": 412.3, "zustandszahl": 0.96, "brennwert": 11.65, "kwh_per_m3": 11.184,
      "kwh": 4611.2 },
    { "from": "2025-03-10", "to": "2025-10-01", "to_inclusive": "2025-09-30",
      "days": 205, "reason": "reading", "m3": 301.0, "…": "…" },
    { "from": "2025-10-01", "to": "2025-11-04", "to_inclusive": "2025-11-03",
      "days": 34, "reason": "factor", "…": "…" },
    { "from": "2025-11-04", "to": "2026-01-01", "to_inclusive": "2025-12-31",
      "days": 58, "reason": "reading", "m3": null, "kwh": null, "…": "…" }
  ],
  "totals": { "days": 365, "m3": 812.4, "kwh": 9034.7, "gaps": 1 }
}
```

`reason` ∈ `start`, `end`, `reading`, `reading_estimated`, `factor`, seit
v3.1.0 `price` — Kombinationen mit `+` (`reading+factor`). **Seit v2.5.2** trägt jede Zeile
`counter_from`/`counter_to` (Zählerstand am Anfang/Ende des Abschnitts)
mit `counter_from_kind`/`counter_to_kind` ∈ `reading` (abgelesen),
`reading_estimated` (als geschätzt erfasst), `interpolated` (Ersatzwert:
kein Stand an diesem Tag, tagesgenau interpoliert) oder `null` (kein
umschließendes Intervall). Über einen Zählertausch hinweg ist der
Ersatzwert `null` bei `kind = interpolated`. `m3`/`kwh` sind `null`, wenn
kein Ableseintervall den Abschnitt umschließt (vor der ersten, nach der
letzten Ablesung); `totals.gaps` zählt diese Abschnitte, sie fehlen in
den Summen. `m3` je Abschnitt ist die lineare Interpolation des
umschließenden Intervalls — der Versorger schätzt an denselben Stellen.

**Kosten *(v3.1.0, additiv)*.** Jede Zeile trägt zusätzlich die Kosten des
Abschnitts, gerechnet mit denselben Bausteinen wie Monatszeilen und Saldo
(Vertragsabschnitte, Preis am Tag, feste Kosten je Monat nach Kalendertagen;
Schattenverträge zählen nicht):

| Feld | Bedeutung |
|---|---|
| `ct_per_kwh` | Arbeitspreis des Abschnitts (ct je kWh; nicht bei Wasser) |
| `energy_cost` | Verbrauchskosten: Menge × Arbeitspreis, bei einem Preiswechsel im Abschnitt nach Tagen geteilt; `null`, wenn ein Preis fehlt |
| `fixed_cost` | feste Kosten: Grundpreis, bei Fernwärme dazu Leistungs- und Messpreis ([Verträge](#verträge-fernwärme-fixkosten-warnings-v310-additiv)) |
| `fixed_days` | Tage, über die die festen Kosten laufen |
| `contract_id` | der Vertrag des Abschnitts |
| `tw_m3`, `tw_cost`, `sw_m3`, `sw_cost`, `nw_cost` | nur Wasser: Trinkwasser (Arbeits- und Grundpreis), Schmutzwasser und Niederschlagswasser. Die Schmutzwassermenge folgt der Basis des Vertrags: Trinkwasser, ein eigener Zähler (`separater_zaehler`) oder Trinkwasser abzüglich der Abzugszähler (`trinkwasser_minus_abzug`) — deren Menge im selben Abschnitt, tagesgenau *(seit v3.6.0; bis v3.5 nur mit Basis Trinkwasser, sonst `null`)*. `energy_cost` = Trink- + Schmutzwasser je m³, `fixed_cost` = Grundpreis + Niederschlagswasser |

`totals` additiv: `energy_cost`, `fixed_cost`, `bonus` (Boni mit Gutschrift im
Zeitraum), `total` (= `energy_cost + fixed_cost − bonus`) und `price_missing`
(`true`, wenn für einen Teil ein Preis fehlt). Dazu auf oberster Ebene
`utility`.

```json
{ "from": "2025-01-01", "to": "2026-01-01", "utility": "strom",
  "rows": [
    { "from": "2025-01-01", "to": "2025-04-01", "to_inclusive": "2025-03-31",
      "days": 90, "reason": "start", "kwh": 812.4,
      "counter_from": 40211.0, "counter_from_kind": "reading",
      "counter_to": 41023.4, "counter_to_kind": "interpolated",
      "ct_per_kwh": 29.8, "energy_cost": 242.1, "fixed_days": 90,
      "fixed_cost": 37.5, "contract_id": "c_strom_003" },
    { "from": "2025-04-01", "to": "2026-01-01", "to_inclusive": "2025-12-31",
      "days": 275, "reason": "price", "kwh": 2190.6, "…": "…" } ],
  "totals": { "days": 365, "kwh": 3003.0, "gaps": 0,
              "energy_cost": 901.47, "fixed_cost": 150.0, "bonus": 0.0,
              "total": 1051.47, "price_missing": false },
  "warnings": [] }
```

Die Werte der Versorgerrechnung selbst hält der Topf `<art>/bills.json`
fest; den Vergleich liefert `GET …/bills/{id}/check`
([Versorgerrechnungen](#versorgerrechnungen-v310)).

### Abrechnungsperioden *(v3.6.0)*

`GET /api/utility/{u}/meters/{id}/billing-periods` (auch
`…/meter-groups/{id}/…` und `/api/utility/{u}/billing-periods` für die ganze
Art) — F1030, GitHub #33. Nur Arten mit Rechnungsprüfung, sonst `400`
`errors.billCheck.unsupportedUtility`. Die eine Quelle für Verbrauchsart,
Übersicht und Analyse im Abrechnungsjahr, den Anhang „Abrechnungsjahre“ und
`monthly.csv?period=billing` (`BillingPeriodService`).

Perioden, aufsteigend: zuerst die erfassten **Versorgerrechnungen**
(`period_from` bis `period_to`, auch kürzer oder länger als ein Jahr;
überlappt eine Rechnung eine frühere, gilt die frühere). Davor, dazwischen und
danach der **Stichtag** der Art (`billing_cycle_anchor_<art>`, 29.02. wirkt
als 28.02.): Eine Lücke wird an den Stichtagen geteilt, die laufende Periode
beginnt am Tag nach der letzten Rechnung und endet am Tag vor dem nächsten
Stichtag. Die erste Periode beginnt mit der ersten Ablesung bzw. einer
wirksamen Analyse-Zäsur; davor gibt es keine. *(v3.6.0, Nachtrag)* Ein
**Lieferantenwechsel** ohne erfasste Rechnung teilt eine Stichtag-Periode: Sie
endet am letzten Tag beim alten Versorger, die nächste beginnt mit dem
Folgevertrag. Wechsel heißt: Folgevertrag eines anderen Versorgers (Anbieter,
sonst Tarifname, ohne Groß/klein) oder desselben erst nach einer Lücke, wenn
sich der alte nicht verlängert (`auto_renews: false`) — dieselbe Regel, nach
der „Abrechnung erwartet“ eine Schlussrechnung erwartet. Eine Lücke ohne
Vertrag ist eine eigene Teilperiode; ein Vertrag, der sich verlängert, läuft
bis zum Tag vor dem Folgevertrag. Bei mehreren Zählern (ganze Art, Gruppe)
schneiden die Wechsel aller Zähler, wie ihre Rechnungen. Den Abschlag eines
Monats mit zwei Verträgen zählt jede Periode (und `…/bills/{id}/check`) je
Vertrag nach dessen Tagen. *(v3.7.0)* Der Stichtag ist der **erste Tag** einer
Periode. **Keine Kurzreste:** Ein Stück unter 14 Tagen zwischen Stichtag und
Wechsel (Stichtag 31-08, Verträge ab 01.09.) geht über den Stichtag hinweg in
die Nachbarperiode auf, nie über einen Wechsel oder eine Rechnung; liegt heute
im Rest, läuft die laufende Periode bis zum übernächsten Stichtag. Die Summen
bleiben dieselben.

| Feld | Bedeutung |
|---|---|
| `anchor` | Stichtag der Art (MM-TT) |
| `suggested_anchor` | Tag nach dem Ende der letzten Rechnung (MM-TT), wenn er vom Stichtag abweicht; ohne Rechnung *(v3.7.0)* der Beginn des Folgevertrags beim jüngsten Lieferantenwechsel, sonst der Tag, an dem mindestens zwei Verträge beginnen; sonst `null` |
| `suggested_anchor_source` | *(v3.7.0, additiv)* `bill`, `contracts` oder `null` — woher der Vorschlag kommt |
| `data_from`, `measured_until` | erste und letzte Ablesung |
| `unit_key` | `kwh` oder `m3` (Wasser) |
| `periods[].from`, `until` | Zeitraum, `until` einschließlich |
| `periods[].source`, `bill_id` | `bill` (mit ID der Rechnung) oder `anchor` |
| `periods[].complete`, `running` | ganze, abgeschlossene Periode mit Daten ab Beginn bzw. läuft noch; eine Periode bis zu einem Wechsel bzw. ab einem Folgevertrag gilt als ganz |
| `periods[].ends_at_switch` | *(v3.6.0, additiv)* `true`, wenn die Periode am letzten Tag beim alten Versorger endet (Lieferantenwechsel) |
| `periods[].label` | „2025“ (Kalenderjahr) oder „2025/26“ für ein ganzes Jahr, sonst `null` (die App nennt dann die Daten) |
| `periods[].as_of`, `days` | gerechnet bis zur letzten Ablesung in der Periode; Tage bis dahin |
| `periods[].kwh` bzw. `m3` (Gas beide), `energy_cost`, `fixed_cost`, `bonus`, `cost` | wie `bill-check` (`cost` = `total`, nach Abzug des Bonus) |
| `periods[].bonus_items` | *(v3.7.0, additiv)* die Boni der Periode einzeln (`credit_date`, `amount_eur`, `type`, `label`; wie `totals.bonus_items` von `bill-check`) |
| `periods[].advances` | Abschläge tagesanteilig aus der Monatsreihe (wie `…/bills/{id}/check`) |
| `periods[].special` | Sonderzahlungen mit Datum in der Periode: `extra_advances`, `refunds`, `surcharges` |
| `periods[].balance` | `cost − advances − extra_advances`; positiv = Nachzahlung. Rück- und Nachzahlungen gleichen eine frühere Abrechnung aus und zählen nicht mit |
| `periods[].invoice` | nur bei `bill`: `amount_eur`, `advances_paid_eur`, `result_eur`, `energy_kwh` bzw. `volume_m3` der Rechnung |
| `periods[].months[]` | nur mit `?months=1`: Kalendermonate, erster und letzter als Teilmonat (`ym`, `from`, `until`, `days`, Menge, `cost`, `kwh_cost`, `base_price_eur`, `bonus_eur`, `advance_eur`, `co2_kg`) |

Subzähler zählen in der ganzen Art nicht mit (sie stecken im Elternzähler),
eine Gruppe ist die Summe ihrer Mitglieder; feste Kosten und Abschläge eines
Gruppenvertrags trägt das erste Mitglied.

`monthly.csv?period=billing` (auch mit `format=local`): Spalten Von, Bis,
Grundlage, Tage, Verbrauch, Kosten, Abschläge, zusätzliche
Abschlagszahlungen, Saldo, vollständig; *(v3.7.0, additiv am Ende)* Bonus und
Kosten ohne Bonus (die Zahl der Kachel „Kosten“ und des Jahresberichts) —
„Kosten“ bleibt nach Abzug des Bonus; Dateiname `…-abrechnungsjahre-…`. Die
Monatsübersicht im Format „local“ hat *(v3.7.0)* als letzte Spalte den Bonus
des Monats; Format 1 bleibt Byte für Byte, wie es ist.
Ein anderer Wert für `period` als `calendar` oder `billing` ist `400`
`errors.export.periodInvalid`.

### Versorgerrechnungen *(v3.1.0)*

Die Rechnung des Versorgers mit ihren eigenen Zahlen — Grundlage für den
Vergleich mit der eigenen Rechnung, für das Buchen des Ergebnisses und für die
CO₂-Angaben (Paket H5, B3/UI-35). Für dieselben Arten wie die
Rechnungsprüfung, sonst `400` `errors.billCheck.unsupportedUtility`.
Stabilitätsklasse B. Schritt für Schritt:
[Jahresabrechnung](../anleitungen/jahresabrechnung.md#7-laut-rechnung-erfassen-vergleichen-buchen).

Topf `<art>/bills.json` (Schema 1.7.0, im Backup), ein Eintrag (Beispielwerte):

```json
{ "id": "b_3f9c0a1d2e4b", "meter_id": "m_gas_main", "contract_id": null,
  "kind": "annual", "period_from": "2025-01-01", "period_to": "2025-12-31",
  "issued_on": "2026-02-10",
  "invoice": { "energy_kwh": 16437, "amount_eur": 1667.5, "advances_paid_eur": 1800,
               "result_eur": -132.5 },
  "items": [ { "label": "Messstellenbetrieb", "amount_eur": 12.5, "kind": "other" } ],
  "co2": { "emissions_kg": 2981.5, "cost_eur": 163.98 },
  "attachment_ids": [ "att_0b1c2d3e4f5a6b7c" ],
  "special_payment_id": null, "note": "",
  "created_at": "2026-02-14T18:20:05+01:00" }
```

| Feld | Bedeutung |
|---|---|
| `meter_id` | Zähler; ohne Angabe der Standardzähler der Art. Unbekannt → `404` `errors.common.meterNotFound` |
| `contract_id` | optional: der Vertrag, in den `book` bucht; ohne Angabe der Vertrag, der am Ende des Zeitraums galt |
| `kind` | `annual` (Jahresabrechnung, Standard), `final` (Schlussrechnung), `interim` (Zwischenrechnung) |
| `period_from`, `period_to` | abgerechneter Zeitraum, **beide einschließlich**; kein Datum oder Beginn nach Ende → `400` `errors.bill.periodInvalid` |
| `issued_on` | Rechnungsdatum (optional) — Datum der Buchung und Beginn der CO₂-Frist (der Tag, an dem der Lieferant abgerechnet hat) |
| `invoice` | `energy_kwh` (bei Wasser `volume_m3`), `amount_eur` (Rechnungsbetrag brutto), `advances_paid_eur`, `result_eur` (**positiv = Nachzahlung**, negativ = Guthaben; ohne Angabe `amount_eur − advances_paid_eur`), `net` (optional, wird nur gespeichert). Jeder Wert optional; keine Zahl oder außerhalb des Bereichs → `400` `errors.bill.amountInvalid` |
| `items` | weitere Posten `{label, amount_eur, kind}` mit `kind` ∈ `levy` (Umlage), `fee` (Gebühr), `credit` (Gutschrift), `other` (Standard); Bezeichnung höchstens 120 Zeichen. Die App rechnet sie nicht nach |
| `co2` | optional, nur sinnvoll bei Gas: `{emissions_kg, cost_eur?, stated_factor?}` — Emissionen und CO₂-Betrag laut Rechnung, der Betrag **mit Umsatzsteuer**, wie die Rechnung ihn ausweist (CO2KostAufG § 3 Abs. 3; bis v3.1 als netto gelesen), angegebener Faktor in kg/kWh. Gehen beim [CO₂-Preis](#co₂-preis-und-aufteilung-v310) dem Standardfaktor vor |
| `factors` | *(v3.7.0, optional, Gas)* Zustandszahl und Brennwert laut Rechnung je Abschnitt `[{from, to?, zustandszahl, brennwert}]` (Zustandszahl 0,5–1,5, Brennwert 5–20 kWh/m³), etwa aus dem Posteingang; der Vergleich hält sie gegen die [Gas-Umrechnung](einstellungen.md). Ohne Angabe fehlt das Feld |
| `attachment_ids` | Belege (`kind: bill_pdf`, sonst `other`); ein aus der Liste entfernter Beleg wird gelöst, `ref.type` des Belegs ist `bill` |
| `special_payment_id` | gesetzt, sobald das Ergebnis gebucht ist |
| `note`, `created_at` | Notiz (höchstens 2000 Zeichen), Zeitpunkt des Anlegens |

`GET …/bills` liefert die Rechnungen nach `period_to` absteigend, `?meter_id=`
nur die eines Zählers. `POST` antwortet `201` mit der Rechnung, `PATCH` ändert
nur die mitgeschickten Felder, `DELETE` antwortet `{deleted: true}`. Unbekannte
ID → `404` `errors.bill.notFound`. Ein Zähler mit Rechnungen lässt sich nicht
löschen (`errors.meter.hasBills`).

**Vergleich — `GET …/bills/{id}/check`.** Rechnet den Zeitraum der Rechnung
mit der Rechnungsprüfung nach (`period_to` einschließlich) und stellt beides
gegenüber:

```json
{ "bill_id": "b_3f9c0a1d2e4b", "utility": "gas", "meter_id": "m_gas_main", "group_id": null,
  "period_from": "2025-01-01", "period_to": "2025-12-31",
  "ours": { "kwh": 16437.2, "energy_cost": 1512.22, "fixed_cost": 142.8, "bonus": 0.0,
            "items_total": 12.5, "total": 1667.52, "advances": 1800.0 },
  "invoice": { "energy_kwh": 16437, "amount_eur": 1667.5, "advances_paid_eur": 1800, "result_eur": -132.5 },
  "delta": { "kwh": -0.2, "kwh_pct": 0.0, "eur": -0.02, "eur_pct": 0.0 },
  "verdict": "ok",
  "reasons": [ "factor_change_inside", "items_not_modelled" ],
  "rows": [ "… wie bill-check …" ] }
```

| Feld | Bedeutung |
|---|---|
| `group_id` | ID der Zählergruppe, wenn ein Gruppenvertrag den Zeitraum berührt: Dann rechnet die App die ganze Gruppe nach (HT und NT einer Rechnung), auch wenn die Rechnung an einem Mitglied erfasst ist; sonst `null` |
| `ours` | nachgerechnet: Menge (`kwh` bzw. `m3`), `energy_cost`, `fixed_cost`, `bonus`, `items_total` (Summe der weiteren Posten), `total` = Verbrauchskosten + feste Kosten − Bonus + weitere Posten, `advances` (Abschläge im Zeitraum laut Zahlungsplan, anteilig nach Tagen; `null` ohne Abschläge) |
| `delta` | Rechnung − nachgerechnet: Menge absolut und in % (`kwh_pct` bzw. `m3_pct`), Betrag in € und % |
| `verdict` | `ok`, wenn die Menge um höchstens 1 % und der Betrag um höchstens 1 % **oder** 2 € abweicht; *(v3.7.0)* `no_prices`, wenn die Menge passt (oder fehlt), aber für einen Teil des Zeitraums ein Preis im Vertrag fehlt (oder ein Vertrag) und der Betrag deshalb nicht nachzurechnen ist; sonst `check` (eine abweichende Menge bleibt `check`). `null`, wenn die Rechnung weder Menge noch Betrag nennt |
| `reasons` | mögliche Gründe: `estimated_reading_at_boundary` (geschätzter oder interpolierter Stand an einem Rand des Zeitraums; *seit v3.7.0* nur dort, und eine Ablesung am letzten Tag bzw. am Tag vor dem ersten gilt als Grenzstand wie auf der Rechnung), `price_change_inside`, `factor_change_inside` (Gas: Die Gas-Umrechnung wechselt im Zeitraum, die Rechnung nennt ihre Werte nicht), *(v3.7.0)* `factor_mismatch` (Brennwert oder Zustandszahl laut Rechnung weichen von der Gas-Umrechnung ab), `items_not_modelled` (weitere Posten übernommen, nicht nachgerechnet), `price_missing`, `gaps` (Abschnitte ohne Zählerstände; nicht der eine Tag nach einem Grenzstand am letzten Tag) |
| `boundaries` | *(v3.7.0)* Ableseart an den Rändern `{start, end}`: `reading` (Ablesung am ersten Tag bzw. am Tag nach dem letzten), `reading_adjacent` (am Tag davor bzw. am letzten Tag), `reading_estimated`, `interpolated` oder `null` (keine Ablesung davor bzw. danach); bei einer Gruppe das schwächste Mitglied |
| `price_missing_at` | *(v3.7.0)* wo der Preis fehlt: `[{from, to, contract_id, provider?, meter_id?}]`, `to` einschließlich, `contract_id` `null` ohne Vertrag — bei jedem Urteil, also auch bei `check` mit abweichender Menge (die Seite zeigt dann denselben Hinweis samt Sprung zu den Verträgen) |
| `factor_mismatches` | *(v3.7.0, Gas)* je Tag mit abweichenden Werten `{from, invoice: {zustandszahl, brennwert, kwh_per_m3}, list: {…}}`; leer, wenn die Gas-Umrechnung den Wechsel wie die Rechnung kennt |
| `rows` | die Abschnitte der Rechnungsprüfung — die Einstufung oben ändert an ihnen nichts |

**Buchen — `POST …/bills/{id}/book`.** Legt das Ergebnis (`invoice.result_eur`)
als Sonderzahlung (F1003) im Vertrag an: positiv als `nachzahlung_ohne`,
negativ als `rueckzahlung_ohne` — **ohne Auswirkung auf den Abschlag** —,
Betrag ohne Vorzeichen, Datum = `issued_on`, sonst der Tag nach `period_to`,
Notiz „Jahresabrechnung {von} – {bis}“ in der Sprache der Anfrage. Vertrag:
`contract_id`, sonst *(seit v3.7.0)* der Vertrag, der am `period_to` gilt
(auch ein weiterlaufender), wenn er mehr als die Hälfte der Tage abrechnet;
sonst der, der mehr als die Hälfte abrechnet (etwa der alte Vertrag einer
Schlussrechnung, die einen Tag über sein Ende reicht); sonst der am
`period_to` — jeweils auch ein Gruppenvertrag der Gruppe, zu der der Zähler
gehört (ohne Schattenverträge). Bis v3.6 nahm die App ohne Vertrag am
`period_to` den jüngsten Vertrag des Zählers, auch wenn er den Zeitraum gar
nicht berührte. Antwort: die Rechnung mit `special_payment_id`
und `contract_id`. **Einmal je Rechnung** — ist schon gebucht, ändert ein
weiterer Aufruf nichts und liefert die Rechnung unverändert. Ohne Ergebnis
(fehlt oder 0) → `400` `errors.bill.noResult`, ohne Vertrag → `400`
`errors.bill.noContract` (die Meldung nennt den Zeitraum). Wer die Rechnung löscht, behält die gebuchte
Sonderzahlung im Vertrag.

*(v3.5.0, [#34](https://github.com/Bingerminger/energietracker/issues/34))*
Auch beim Wasservertrag: Bis v3.4 antwortete der Aufruf dort mit
`special_payment_id`, der Vertrag verwarf die Sonderzahlung aber still. Eine
so „gebuchte“ Rechnung liefern die Abfragen jetzt mit `special_payment_id: null`,
solange ihr Vertrag das Feld `special_payments` gar nicht hat — sie lässt sich
dann buchen. Übernimmt ein Vertrag die Sonderzahlung nicht, antwortet `book`
mit `500` statt „gebucht“.

### CO₂-Preis und Aufteilung *(v3.1.0)*

Paket H4. Beide Routen rechnen nur in Ländern mit CO₂-Schema
(`Countries::co2Scheme`, heute nur Deutschland: `behg`) und antworten sonst mit
`supported: false` und einem Hinweis in `note`. `?year=` ist das Jahr (2021–2100,
Standard: Vorjahr); ein anderes → `400` `errors.co2.yearInvalid`.
Stabilitätsklasse B. Hintergrund: [CO₂-Preis im Brennstoff](../verstehen/16-co2-preis.md),
[CO₂-Kosten mit dem Vermieter teilen](../anleitungen/co2-aufteilung.md).

**`GET /api/co2-costs?year=2025`** — der CO₂-Preis, der im Arbeitspreis von Gas,
Heizöl und Fernwärme steckt (CALC-27). Ein **Ausweis, kein Aufschlag**:

```json
{ "supported": true, "scheme": "behg", "year": 2025,
  "rows": [
    { "utility": "gas", "kwh": 10000.0, "factor_kg_per_kwh": 0.18139,
      "emissions_kg": 1813.9, "price_eur_t": 55.0,
      "cost_eur_net": 99.76, "cost_eur_gross": 118.72, "ct_per_kwh": 1.187,
      "source": "computed", "vat": 0.19, "approx": false, "coverage_days": 365 } ],
  "total": { "emissions_kg": 1813.9, "cost_eur_net": 99.76, "cost_eur_gross": 118.72 },
  "per_m2": { "kg": 18.1, "area_m2": 100.0, "stage": 3, "landlord_share_pct": 20 },
  "price": { "eur_t": 55.0, "assumed": false },
  "vat": 0.19, "note": null }
```

| Feld | Bedeutung |
|---|---|
| `rows[]` | je Verbrauchsart (`gas`, `heizoel`, `fernwaerme`, `waerme`) eine Zeile, wenn es Emissionen und einen Preis gibt. Gezählt werden Zähler, die in die Summen eingehen (keine Subzähler; bei Heizwärme nur die Rolle `consumption`) |
| `kwh` | Verbrauch des Kalenderjahres |
| `factor_kg_per_kwh` | Standardfaktor des BEHG — Gas 0,18139 (je kWh Brennwert), Heizöl 0,2664 (je kWh Heizwert) —, bei Fernwärme `co2_g_per_kwh` des Vertrags / 1000, bei Heizwärme der Faktor des Energieträgers (`waerme_energietraeger`, nur Gas oder Heizöl); bei Werten aus der Rechnung Emissionen ÷ Verbrauch |
| `source` | `bill` (Versorgerrechnungen, deren Zeitraum im Jahr endet und die `co2.emissions_kg` tragen — summiert), `contract` (Fernwärme mit Netzfaktor) oder `computed` (Verbrauch × Standardfaktor). In dieser Reihenfolge; Fernwärme ohne Netzfaktor, Pellets und Strom erscheinen nie |
| `price_eur_t` | maßgeblicher Preis des Jahres: Einstellung `co2_price_eur_t_years`, sonst Länderprofil (DE 2021: 25, 2022: 30, 2023: 30, 2024: 45, 2025: 55, 2026: 60), sonst der letzte bekannte Wert (`price.assumed: true`). Für 2027 gilt nach CO2KostAufG § 4 Abs. 1 Nr. 3 der Durchschnitt der Versteigerungen vom 1. Juli bis 30. November 2026; bis er eingetragen ist, rechnet die API mit dem letzten bekannten Wert (60) als Annahme |
| `cost_eur_net` | Emissionen / 1000 × Preis; mit Rechnung deren `co2.cost_eur` ÷ (1 + `vat`), sofern jede dieser Rechnungen ihn trägt — der Rechnungsbetrag enthält die Umsatzsteuer (CO2KostAufG § 3 Abs. 3) |
| `cost_eur_gross` | netto × (1 + `vat`); mit Rechnung deren `co2.cost_eur` (seit v3.2.0; bis v3.1 netto × (1 + `vat`), die Steuer also doppelt) |
| `ct_per_kwh` | brutto je kWh in ct |
| `vat` | *(v3.2.0)* angewandte Umsatzsteuer der Zeile: je Monat 19 %, bei `gas` und `fernwaerme` (und Heizwärme aus Gas oder Fernwärme) vom 10/2022 bis 3/2024 7 % (§ 28 Abs. 5 und 6 UStG), für das Jahr nach Verbrauch gewichtet, ohne Verbrauch das Mittel der zwölf Monate. Gas 2023: `0.07`; Heizöl immer `0.19`; Heizwärme nach dem Energieträger (`waerme_energietraeger`) |
| `approx` | `true` bei Heizwärme mit gerechnetem Wert — gezählt wird die Wärme, nicht der Brennstoff |
| `coverage_days` | Tage des Jahres mit Verbrauchsdaten |
| `total` | Summen aller Zeilen, `null` ohne Zeile |
| `per_m2` | Emissionen je m² Wohnfläche (auf eine Nachkommastelle) mit `stage` (1–10) und `landlord_share_pct` nach der Anlage zum CO2KostAufG; Fläche aus dem Mietverhältnis des Jahres (bei `wohnverhaeltnis: miete`), sonst `wohnflaeche_m2`. `null` ohne Zeilen oder Fläche. *(v3.6.0)* `area_assumed`: die Fläche ist nur der Standard (nirgends eingetragen) |
| `price` | `{eur_t, assumed}` |
| `vat` | Regelsatz der Umsatzsteuer auf den CO₂-Preis (`0.19`); den je Zeile angewandten Satz nennt `rows[].vat` |

Stufen (kg CO₂ je m² und Jahr, Obergrenze ausschließlich → Anteil des
Vermieters): < 12 → 0 %, < 17 → 10 %, < 22 → 20 %, < 27 → 30 %, < 32 → 40 %,
< 37 → 50 %, < 42 → 60 %, < 47 → 70 %, < 52 → 80 %, darüber 95 %. Für einen
kürzeren Zeitraum als ein Jahr werden die Grenzen anteilig gekürzt.

**`GET /api/co2-split?year=2025`** — der Anteil des Vermieters an den
CO₂-Kosten (MKT-15). Nur mit `wohnverhaeltnis: miete` und CO₂-Schema, sonst
`{supported: false, year, note}`. Eine Hilfsrechnung, keine Rechtsberatung. Den
Fall bestimmt die App:

- **`central`** (Zentralheizung) — eine Nebenkostenabrechnung des
  Mietverhältnisses, deren Zeitraum im Jahr endet, trägt `co2`
  ([Mietverhältnis](#mietverhältnis-v310)). Die App rechnet aus Emissionen und
  Fläche Stufe, Anteil und Betrag (`co2.cost_eur` × Anteil) nach und vergleicht
  mit den Angaben der Abrechnung.
- **`self_supplied`** (Etagenheizung) — sonst, aus den Zeilen `gas` und
  `heizoel` von `/api/co2-costs`: Kosten **brutto**, Kürzungen aus dem
  Mietverhältnis (`co2_own_appliances` × 0,95; `co2_restriction` `one` × 0,5,
  `both` → 0).

```json
{ "supported": true, "year": 2025, "tenancy_id": "t_1a2b3c4d5e6f", "area_m2": 80.0,
  "case": "self_supplied", "source": "computed",
  "emissions_kg": 2176.7, "kg_per_m2": 27.2, "stage": 5, "landlord_share_pct": 40,
  "co2_cost_eur": 142.46, "landlord_amount_eur": 56.98,
  "checks": [], "reductions": [], "deadline": "2027-02-10",
  "price": { "eur_t": 55.0, "assumed": false }, "utilities": [ "gas" ] }
```

| Feld | Bedeutung |
|---|---|
| `case` | `self_supplied`, `central` oder `null` (keine Gas- oder Heizöldaten und keine CO₂-Angaben; dann `note`) |
| `tenancy_id`, `area_m2` | das Mietverhältnis des Jahres (das jüngste) und seine Wohnfläche (`wohnflaeche_m2`, sonst die Einstellung; `null` ohne) |
| `area_assumed` | *(v3.6.0)* `true`, wenn die Fläche im Mietverhältnis leer und die Wohnfläche nie eingetragen ist — gerechnet wird weiter mit dem Standard (100 m²), die Oberfläche bittet ums Eintragen ([Angenommene Angaben](#angenommene-angaben-assumed_keys-v360)) |
| `emissions_kg`, `kg_per_m2`, `stage`, `landlord_share_pct` | Emissionen, je m² (eine Nachkommastelle), Stufe und Anteil des Vermieters |
| `co2_cost_eur`, `landlord_amount_eur` | CO₂-Kosten des Jahres und der Betrag des Vermieters |
| `source` | `self_supplied`: die Quellen der Zeilen aus `/api/co2-costs`, mit Komma verbunden (etwa `bill` oder `bill,computed`); `central`: `statement` |
| `checks[]` | `central`: `stage`, `share`, `amount` (Abweichung über 0,50 €), `missing_values` (Emissionen oder Fläche fehlen); `self_supplied`: `area_missing` (ohne Fläche keine Stufe) |
| `reductions[]` | `own_appliances`, `restriction_one`, `restriction_both` |
| `deadline` | nur `self_supplied`: Frist für die Erstattung (§ 6 Abs. 2: zwölf Monate, nachdem der Lieferant abgerechnet hat) = Rechnungsdatum der Gasrechnung, deren Zeitraum im Jahr endet (`issued_on`, sonst Tag nach dem Zeitraum), + 12 Monate; `null` ohne erfasste Gasrechnung |
| `statement_id`, `days`, `stated` | nur `central`: die Abrechnung, die Tage ihres Zeitraums und ihre Angaben `{stage, landlord_share_pct, landlord_amount_eur}` |
| `price`, `utilities` | nur `self_supplied`: Preis wie oben und die Arten der Rechnung (`gas`, `heizoel`) |

**`GET /api/reports/co2-split.pdf?year=2025`** — das Ergebnis als PDF: bei
`self_supplied` ein Anschreiben „Erstattung des Vermieteranteils an den
CO₂-Kosten (CO2KostAufG)“, bei `central` das Prüfergebnis mit den Abweichungen,
immer mit dem Hinweis „Hilfsrechnung … keine Rechtsberatung“. Datei
`energietracker-co2-<jahr>.pdf`, `?inline=1` zeigt sie im Browser statt sie
herunterzuladen. Sprache wie beim PDF-Jahresbericht (ohne `X-ET-Language` die
Standardsprache); eine Sprache ohne PDF-Zeichensatz → `422`
`errors.report.pdfUnsupportedLanguage`.

**Einstellungen:** `co2_price_eur_t_years`, `co2_price_scenario_eur_t`,
`co2_price_scenario_from` ([Einstellungen](einstellungen.md#co₂-preis-v310));
das Szenario steht bei der [Prognose](#get-apiutilityumetersidforecast-v280-erweitert).

### `GET /api/utility/{u}/meters/{id}/stock-history` *(nur Heizöl/Pellets)*

```json
{ "success": true, "data": {
  "capacity": 3000, "capacity_unit": "L", "initial_stock": 2400,
  "days": [ { "date": "2023-01-01", "stock": 2389.4,
              "delivery": 0, "consumption": 10.6, "estimated": false }, … ],
  "anchors": [ { "date": "2023-01-01", "kind": "start", "stock": 2400 },
               { "date": "2025-09-17", "kind": "level", "stock": 1650 } ],
  "estimated_from": "2025-09-17",
  "calibration": "anchors",
  "warnings": []
}}
```

**Seit v2.10.0 (Tankbuch)** kommen Bestand und Verbrauch aus **einer**
Rechnung — derselben, aus der die Monatszeilen, Kosten und die
Effizienzkennzahl kommen. `stock` ist der Bestand am Ende des Tages.
`anchors` sind die Stützstellen (`start` = Anfangsbestand, `full` =
Lieferung „bis voll", `level` = Peilstand); zwischen ihnen ist der
Verbrauch gerechnet, ab `estimated_from` geschätzt (`estimated` je Tag).
`calibration` nennt die Herkunft der Rate: `anchors`, `deliveries`
(Lieferkadenz), `first_delivery` oder `none`. `warnings`:
`inconsistent_level` (`from`, `to`, `excess` — Stände passen nicht
zusammen, dazwischen wird nichts gebucht), `stock_exhausted` (`date` —
rechnerisch leer, nur an geschätzten Tagen geprüft), `no_calibration` (keine
Rate: weniger als 14 Tage zwischen bekannten Ständen, keine zwei Lieferungen
und keine einzelne Lieferung nach einem Anfangsbestand über 0),
`flat_no_temperatures`. Bis v2.9 war die Kurve ein zweites Modell neben der
Kostenrechnung. Details: [Heizöl](../verstehen/05-heizoel.md).

**Monatszeilen** von Heizöl und Pellets (`…/consumption`) tragen seit
v2.10.0 `estimated_days` (Tage nach der letzten Stützstelle) und
`working_price_ct` (effektiver Preis je kWh aus dem gleitenden
Durchschnittspreis des Tankinhalts; bis v2.9 `null`). `cost` rechnet zum
Durchschnittspreis des Tankinhalts, der Anfangsbestand zu
`initial_stock_price_ct` bzw. dem Preis der ersten Lieferung (bis v2.9: 0 €).

### Tank: `tank_levels`, `initial_stock_price_ct`; Lieferung: `fill_to_full` *(v2.10.0, additiv)*

- `PATCH …/meters/{id}` (nur Heizöl/Pellets) mit `tank_levels`: die
  **ganze** Liste `[{date, level, note}]`. Streng: echtes Datum, nicht in
  der Zukunft, ein Stand je Tag, 0 ≤ `level` ≤ Kapazität (+2 %),
  Dezimalkomma erlaubt. Fehler → 400 `errors.meter.invalidTankLevels`,
  `…invalidTankLevelDate`, `…tankLevelFuture`, `…duplicateTankLevel`,
  `…tankLevelInvalid`, `…tankLevelAboveCapacity`.
- `initial_stock_price_ct` (ct je L bzw. kg) beim Anlegen oder per
  `PATCH`; leer/`null` entfernt ihn (dann gilt der Preis der ersten
  Lieferung). Ungültig → 400 `errors.meter.initialPriceInvalid`.
- `POST|PATCH …/deliveries` mit `fill_to_full: true` (auch `"true"`, `1`;
  `"false"` ist falsch): Nach der Lieferung ist der Tank voll. Eine Menge
  über der Kapazität (+2 %) → 400 `errors.delivery.fullAboveCapacity`.
- Strom: `heat_source: true` am Zähler kennzeichnet eine Wärmepumpe —
  er zählt dann in der Effizienzkennzahl. Seit v3.1.0 gleichbedeutend mit der
  Rolle `heat_pump` ([Zählerrollen](#zähler-role-capture-v310-additiv)).

Alle Felder reisen im Backup mit (ganze Datensätze); der CSV-Export der
Lieferungen bleibt unverändert.

### `GET /api/benchmarks/efficiency?year=YYYY`

Seit **v1.4.0** pro Heizquelle:

```json
{ "success": true, "data": {
  "year": 2024, "wohnflaeche_m2": 100,
  "per_source": [
    { "utility": "gas", "label": "Gas", "kwh": 10685.8,
      "kwh_per_m2": 106.9, "class": "D" }
  ],
  "primary":  { "utility": "gas", "label": "Gas", "kwh": 10685.8,
                "kwh_per_m2": 106.9, "class": "D" },
  "combined": { "kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D" },
  "certificate": { "area_m2": 120, "area_factor": 1.2, "kwh": 9681,
                   "kwh_per_m2": 80.7, "dhw_surcharge": 0,
                   "weather_adjusted": true, "complete": true,
                   "class": "C", "months_36": 36,
                   "rules": "geg2024", "fuel_basis": "hi", "fuel_basis_approx": [],
                   "dhw_rule": 20, "months_required": 36, "months_in_window": 36,
                   "upcoming": { "from": "2027-01-01", "rules": "gmodg2027",
                                 "kwh": 10685, "kwh_per_m2": 89, "dhw_surcharge": 0, "class": "C",
                                 "fuel_basis": "hs", "fuel_basis_approx": [], "dhw_rule": 16,
                                 "months_required": 24, "months_in_window": 24 } },
  "thresholds": { "A+": 30, "A": 50, "…": 0 },
  "scale": "geg", "scale_note": null,
  "note": null,
  "total_kwh": 10685.8, "kwh_per_m2": 106.9, "class": "D",
  "breakdown": { "gas": 10685.8 }
}}
```

`per_source` führt jede Heizenergie-Art (Gas, Fernwärme, Heizöl,
Pellets, seit v3.1.0 Heizwärme) **einzeln** auf — ein Haus heizt real meist mit einer Quelle;
mehrere summiert ergäben eine unsinnige Klasse. `primary` =
verbrauchsstärkste Quelle, `combined` = Summe (nur bei bewusst
kombiniertem Heizbetrieb sinnvoll, `note` weist darauf hin). Die
Top-Level-Felder sind rückwärtskompatible Aliase und spiegeln seit
v1.4.0 die **primäre** Quelle.

*(v3.6.0)* `area_assumed`: `true`, wenn `wohnflaeche_m2` nur der Standard ist
(nie eingetragen). Kennzahl und Klasse rechnen weiter damit; die Übersicht und
der Jahresbericht sagen „angenommene Wohnfläche“ und bitten ums Eintragen.

*(v2.7.0)* `scale` nennt die Effizienzskala des eingestellten Landes —
heute nur `geg` (Deutschland). Für andere Länder ist `scale` `null`, alle
`class`-Felder sind `null`, und `scale_note` erklärt den Grund; die
Kennzahl `kwh_per_m2` bleibt. Eine Klasse nach deutschem Recht wäre in
Frankreich (DPE) oder Österreich (HWB) irreführend.

*(v2.10.0)* Jede Quelle trägt `coverage_days` und `complete` (≥ 360 Tage);
**ohne ganzes Jahr keine Klasse** (`class: null`, `note` erklärt es).
Grenzen gelten einschließlich („bis 100" = C). Stromzähler mit
`heat_source: true` (seit v3.1.0: Rolle `heat_pump`) erscheinen als Quelle
`strom`. *(v3.1.0)* Heizwärme (`waerme`) zählt nur mit Zählern der Rolle
`consumption`; die Rolle `heat_pump_output` (Wärmemenge einer Wärmepumpe)
bleibt draußen, sonst stünde dieselbe Wärme neben dem Heizstrom doppelt. `certificate` ist die
energieausweis-nahe Kennzahl: witterungsbereinigt (`weather_adjusted`),
bezogen auf `area_m2` = Wohnfläche × `area_factor` (1,2; 1,35 bei
`gebaeudetyp` efh/rh mit `beheizter_keller`), plus `dhw_surcharge` bei
`warmwasser_dezentral`. `months_36` zählt die Monate mit Heizdaten in den
drei Jahren bis zum Bezugsjahr (Bedeutung unverändert).

*(v3.5.0, additiv)* Die Kennzahl folgt dem Verfahren des Verbrauchsausweises,
das **am Rechentag** gilt (§ 82 GModG; „als würde der Ausweis heute
ausgestellt“), für jedes Bezugsjahr:

| Feld | bis 31.12.2026 | ab 01.01.2027 |
|---|---|---|
| `rules` | `geg2024` | `gmodg2027` |
| `fuel_basis` | `hi` — Heizwert, Gas × 0,906 | `hs` — Brennwert, Gas × 1 |
| `dhw_rule` | 20 (Zuschlag je m² bei dezentralem Warmwasser) | 16 |
| `months_required` | 36 | 24 (zwei Jahre) |

`months_in_window` zählt die Monate mit Heizdaten im Zeitraum der Regel bis
zum Bezugsjahr. `fuel_basis_approx` nennt Verbrauchsarten, die nicht auf
`fuel_basis` umgerechnet sind (ab 2027 `heizoel` und `pellets`: die App zählt
sie nach Heizwert, einen frei zugänglichen Faktor zum Brennwert gibt es
nicht). `upcoming` steht bis zum Inkrafttreten der nächsten Regel da (`from`,
dieselben Felder nach der neuen Regel), danach `null`. Klassengrenzen und
`thresholds` sind in beiden Regeln gleich (Anlage 10).
Formeln: [Grundlagen §7](../verstehen/00-overview.md#7-effizienzklasse).

### Einordnung: `GET /api/benchmarks/comparison` *(v3.1.0)*

Ordnet den Jahresverbrauch an **eigenen Vergleichswerten** ein (Klasse B,
`?year=`, Standard Vorjahr). Tabellen aus Strom- oder Heizspiegel liefert die
App bewusst nicht mit — ihre Nutzung verlangt eine schriftliche Genehmigung
(§ 87b UrhG); die Werte trägt der Haushalt selbst ein
([Einstellungen](einstellungen.md#eigene-vergleichswerte-v310)).

```json
{ "year": 2025, "supported": true, "source": "Stromspiegel 2025",
  "strom": { "kwh": 2950, "complete": true, "own_value": 2800, "delta_pct": 5.4,
             "rating": "near",
             "persons": 2, "building": "house", "dhw_electric": false,
             "special_household": true },
  "heating": [ { "utility": "gas", "kwh_per_m2": 118.4, "weather_adjusted": true,
                 "complete": true, "own_value": 130, "delta_pct": -8.9, "rating": "good" } ],
  "water": { "m3": 88.0, "complete": true, "persons": 2, "reference_l": 122,
             "liters_per_person_day": 121, "index": 99, "delta_pct": -1.0, "rating": "good" },
  "missing": [],
  "area_m2": 120,
  "links": { "stromspiegel": "https://www.stromspiegel.de/",
             "heizspiegel": "https://www.heizspiegel.de/" } }
```

| Feld | Bedeutung |
|---|---|
| `strom` | Haushaltsstrom: alle Stromzähler in Summen **ohne** die Rollen `heat_pump` und `ev_charger`; `null` ohne Werte im Jahr |
| `strom.special_household` | `true` bei PV, Wärmepumpe oder Wallbox — dann passt der allgemeine Stromspiegel nicht |
| `strom.persons`, `building`, `dhw_electric` | die Merkmale, nach denen der Stromspiegel unterscheidet: Personen im Haushalt, `house` (Ein-/Zweifamilien- oder Reihenhaus) oder `flat`, Warmwasser mit Strom (`warmwasser_elektrisch`) |
| `heating[]` | je Heizart (Gas, Heizöl, Pellets, Fernwärme, Heizwärme) kWh je m² Wohnfläche, witterungsbereinigt, wo es das Heizmodell gibt (`weather_adjusted`) |
| `complete` | alle zwölf Monate mit Werten; nur dann gibt es `delta_pct` |
| `own_value`, `delta_pct` | der eigene Vergleichswert (`reference_strom_kwh` bzw. `reference_heat_kwh_m2`) und die Abweichung in % |
| `rating` | *(v3.6.0)* Ampel: `good` am oder unter dem Vergleichswert, `near` bis 10 % darüber, `high` mehr; `null` ohne `delta_pct` |
| `water` | *(v3.6.0)* Wasser der Hauptzähler im Jahr gegen den Richtwert: `liters_per_person_day` = m³ × 1000 / Tage / `persons` (`wasser_personen_anzahl`), `index` = Liter / `reference_l` (`wasser_personen_referenz`) × 100, `delta_pct` = `index` − 100; `rating` nach den Bändern des Wasser-Spar-Index (`good` bis `wasser_sparindex_gut`, `high` ab `wasser_sparindex_warnung`), nur im vollen Jahr; `null` ohne Werte im Jahr oder ohne aktive Verbrauchsart Wasser |
| `missing` | *(v3.6.0)* Einstellungen, die eine Einordnung erst möglich machen, in der Reihenfolge der Einladung auf der Übersicht: `reference_strom_kwh`, `wohnflaeche_m2` (vor `reference_heat_kwh_m2`), `wasser_personen_anzahl` (vor `wasser_personen_referenz`). Wohnfläche und Personen fehlen, bis jemand sie eingetragen hat — der Standard (100 m², 2 Personen) zählt nicht; dann gibt es keine Zeile `heating[]`, `water.persons` ist `0` und `area_m2` `null` ([Angenommene Angaben](#angenommene-angaben-assumed_keys-v360)) |
| `source` | `reference_source`, sonst `null` |
| `links` | die Seiten zur Selbstprüfung — nur für das Land Deutschland, sonst leer; die Übersicht zeigt sie seit v3.6.0 nicht mehr |

### Jahresarbeitszahl der Wärmepumpe *(v3.1.0)*

**Verknüpfung am Zähler.** Ein Heizwärme-Zähler mit der Rolle
`heat_pump_output` (Wärmemenge der Wärmepumpe) nennt in `heat_pump_meter_ids`
die Stromzähler der Wärmepumpe — Stromzähler mit der Rolle `heat_pump`, sonst
`400` `errors.meter.heatPumpLinkInvalid`. Eine leere Liste entfernt das Feld.

**`GET /api/heat-pump?year=`** (Klasse B, Standard Vorjahr, Jahr 1990–2100,
sonst `400` `errors.heatPump.yearInvalid`):

```json
{ "year": 2025,
  "pumps": [ { "heat_meter_id": "m_wp_waerme", "name": "Wärmemenge WP",
    "elec_meter_ids": ["m_wp_strom"], "linked": true,
    "months": [ { "ym": "2025-01", "month": 1, "heat_kwh": 1620.0, "elec_kwh": 520.0,
                  "cop": 3.12, "hdd": 512.3 }, "…" ],
    "months_covered": 12, "heat_kwh": 9000.0, "elec_kwh": 2500.0,
    "jaz": 3.6, "jaz_heating_season": 3.3,
    "by_hdd": [ { "ym": "2025-01", "hdd": 512.3, "cop": 3.12 }, "…" ] } ],
  "reference": { "air_water": 3.4, "ground_water": 4.3 } }
```

```text
Arbeitszahl (Monat) = Wärme / Strom          JAZ = Σ Wärme / Σ Strom
Beispiel: 9.000 kWh Wärme / 2.500 kWh Strom = JAZ 3,6
```

Gezählt werden nur Monate, in denen **beide** Seiten Werte haben (bei mehreren
Stromzählern alle). `jaz_heating_season` rechnet über Oktober bis April.
`reference` sind die Mittelwerte des Fraunhofer-Feldtests „WP-QS im Bestand“
(2025) zur Einordnung. Eine Arbeitszahl wird **nicht** witterungsbereinigt;
`by_hdd` zeigt, wie sie mit den Heizgradtagen schwankt. Welche Energie
mitzählt (Heizstab, Warmwasser, Pumpen), hängt an der Lage der Zähler; je nach
dieser Bilanzgrenze unterscheiden sich Arbeitszahlen derselben Anlage um rund
15 %.

Der Heizwärme-Zähler mit `heat_pump_output` zählt weder in die Summen der
Heizwärme noch in die Effizienzkennzahl: Die Wärmepumpe steht dort schon mit
ihrem Strom.

### `GET /api/export/{u}/deliveries.csv` *(v1.4.2, Heizöl/Pellets)*

CSV mit einer Zeile je Lieferung (Spalten unten). Nur für Heizöl und Pellets;
für kumulative Arten antwortet die Route mit 400
(`errors.csv.notDeliveryBased`) — dort gilt `readings.csv`.

### CSV-Formate *(v3.1.0)*

Die Exporte (`/api/export/{u}/monthly.csv`, `…/readings.csv`,
`…/deliveries.csv`, seit v3.1.0 `…/periods.csv`, und
`/api/export/temperatures.csv`) liefern eine Datei
(`text/csv`, `Content-Disposition: attachment`) in einem von zwei Formaten:

| Parameter | Format | Gedacht für |
|---|---|---|
| `format=1` oder ohne | **Format 1** — eingefroren, Stabilitätsklasse A | Skripte, eigene Auswertungen |
| `format=local`, optional `lang=xx` | **Tabelle in einer Sprache** | Excel, LibreOffice, Numbers |

Ein anderer Wert für `format` → 400 mit `code` `errors.export.formatInvalid`.
Die Oberfläche bietet beide unter Einstellungen → Daten → Datenexport an
(„Tabelle in der Standardsprache — empfohlen“ und „CSV-Format 1 — stabil, für
Skripte“) und merkt sich die Wahl im Browser.

#### Format 1

So, wie es seit v1.1.0 ausgegeben wird; `CsvFormatV1Test` hält es seit
v3.1.0 Byte für Byte gegen die Dateien unter `tests/fixtures/csv-format-1/`
fest. Eine Änderung daran wäre ein neues Format, kein Format 1 mehr.

- Feldtrenner `;`, Zeilenende CRLF, UTF-8 mit BOM.
- Zahlen mit Dezimalkomma, ohne Tausendertrennung, höchstens vier
  Nachkommastellen, Nullen am Ende entfernt (`1480,5`, `80`, `-0,1`). Leere
  Zelle = kein Wert.
- Datum ISO `JJJJ-MM-TT`, Monat `JJJJ-MM`.
- Wahrheitswerte `ja` und `nein`.
- Eine Zelle mit `;`, `"` oder Zeilenumbruch steht in Anführungszeichen,
  `"` darin verdoppelt.
- **Formelschutz:** Freitext (Zählername, Notiz, Lieferant), der mit `=`,
  `+`, `-`, `@`, Tabulator oder Wagenrücklauf beginnt, bekommt ein
  vorangestelltes `'` — Tabellenprogramme werten ihn dann nicht als Formel
  aus. Zahlen bleiben unberührt, auch negative.

Die Kopfzeilen, wörtlich:

| Datei | Kopfzeile |
|---|---|
| `readings.csv` | `Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand;Preis (ct);Notiz;Geschaetzt;Zukunft` |
| `deliveries.csv` | `Tank/Lager-ID;Tank/Lager;Datum;Menge (L);Preis (ct/L);Gesamt (EUR);Lieferant;Notiz;Geplant` — bei Pellets `kg` statt `L` |
| `temperatures.csv` | `Datum;oe Temp (C);Min (C);Max (C)` |
| `periods.csv` *(v3.1.0)* | `Von;Bis;Wert;Notiz;Zaehler-ID;Zaehler;Einheit;Geschaetzt;Quelle` |
| `monthly.csv` | die eingefrorenen Schlüssel `csv.*` der Sprache der Anfrage (s. u.) |

`periods.csv` hat eine Zeile je Zeitraum über alle Zähler der Art: `Bis` ist
der letzte Tag (einschließlich), `Einheit` die Einheit von `Wert` (bei Gas
`m³` für `value_unit: meter`, sonst die Verbrauchseinheit), `Quelle` ist
`manual`, `csv` oder `import`.

Die Monatsübersicht trägt ihre Kopfzeile in der Sprache der Anfrage — ohne
`X-ET-Language` also in der Standardsprache der Installation. Deutsch:

```text
Monat;Tage;Verbrauch (kWh);Kosten (EUR);Abschlag (EUR);Monatssaldo (EUR);Saldo kumuliert (EUR);Ø Temp (°C);HGT;CO2 (kg)
```

Englisch: `Month;Days;Consumption (kWh);Cost (EUR);Advance payment (EUR);…`;
die Kopfzeilen aller sieben Sprachen liegen als Vergleichsdateien im
Testverzeichnis. Die Schlüssel `csv.*` sind eingefroren: Übersetzungen
ändern sie nicht, die Typografie der Ausgabe lässt sie aus. Die Einheit des
Verbrauchs ist die der Verbrauchsart (`kWh` oder `m³`), der Saldo hat das
Vorzeichen der API (positiv = Nachzahlung).

`EUR` und `ct` sind in Format 1 feste Bezeichner für Haupt- und Untereinheit
der eingestellten Währung — Beträge werden nicht umgerechnet. Nur die
Monatsübersicht setzt den Währungscode ein (`EUR`, `CHF` oder `GBP`).

Dateinamen (Datum des Exports):
`energietracker-<utility>-monatsuebersicht-JJJJ-MM-TT.csv`,
`energietracker-<utility>-ablesungen-JJJJ-MM-TT.csv`,
`energietracker-<utility>-lieferungen-JJJJ-MM-TT.csv`,
`energietracker-<utility>-zeitraeume-JJJJ-MM-TT.csv` (v3.1.0),
`energietracker-temperaturen-JJJJ-MM-TT.csv`.

#### Format „local“

Die Tabelle in einer Sprache, so dass Excel oder LibreOffice sie mit einem
Doppelklick richtig öffnen. Die Sprache ist `lang` (eine der sieben), ohne
oder bei unbekanntem `lang` die **Standardsprache der Installation** — nicht
die Sprache des Geräts.

- Kopfzeilen aus den Katalogschlüsseln `csvLocal.*` der Sprache, mit
  Währung und Untereinheit (`Zählerstand`, `Preis (ct)`; französisch
  `Index`, `Prix (ct)`; mit Franken als Währung `Preis (Rp.)`). Zeiträume
  (v3.1.0) aus `csvLocal.periods.*`, deutsch
  `Von;Bis;Wert;Notiz;Zähler-ID;Zähler;Einheit;Geschätzt;Quelle`.
- Dezimaltrenner von Sprache und Land (Deutsch in der Schweiz `.`), keine
  Tausendertrennung, höchstens vier Nachkommastellen.
- Feldtrenner `;` bei Dezimalkomma, sonst `,`.
- Datum im Muster der Sprache: de `15.01.2026`, en, fr, it, es und pt
  `15/01/2026`, nl `15-01-2026` (Länderabweichungen wie Französisch in der
  Schweiz `15.01.2026`). Monat `JJJJ-MM`.
- Ja und Nein in der Sprache (`Oui`/`Non`).
- UTF-8 mit BOM, CRLF, Anführungszeichen und Formelschutz wie in Format 1.
- Dateiname aus `csvLocal.file.*`, etwa
  `energietracker-strom-releves-2026-01-15.csv` (französisch). Der Schlüssel
  der Verbrauchsart bleibt.

Spaltennamen und Wörter kommen aus den Katalogen und können sich mit einer
besseren Übersetzung ändern. Skripte lesen Format 1.

#### Import *(v3.1.0)*

Ablesungen (`…/readings/import-csv`) und Temperaturen
(`/api/temperatures/import-csv`) lesen ihre Exporte (`readings.csv`,
`temperatures.csv`) wieder ein — Format 1 und „local“ in jeder Sprache
(`CsvLocalFormatTest`):

- **Datum** ISO `JJJJ-MM-TT` oder Tag vor Monat mit vierstelligem Jahr:
  `T.M.JJJJ`, `T/M/JJJJ`, `T-M-JJJJ`, Tag und Monat ein- oder zweistellig.
  Geprüft wird gegen den Kalender (`31.02.2026` ist kein Datum).
- **Monat vor Tag** (US-Schreibweise) wird nie umgedeutet: Steht an zweiter
  Stelle ein Wert über 12 (`01/15/2026`), ist die Zeile ein Fehler — beim
  Ablesungs-Import mit eigener Meldung (`errors.import.dateMonthFirst`) in
  `errors`, beim Temperatur-Import zählt sie unter `skipped`.
- **Kopfzeile** ist die erste Zeile, wenn sie Buchstaben enthält und nicht mit
  einem Datum oder einer Zahl beginnt — „Date;Moyenne;Min;Max“ wird erkannt,
  eine Datenzeile nicht verschluckt.
- **Spalten** der Ablesungen findet der Import über ihren Namen: Namen aus
  `csvLocal.readings.*` aller Sprachen, aus Format 1 und die bisherigen
  (`datum`, `zaehlerstand`, `counter`, `wert`, `notiz` …). Groß und klein,
  Akzente, Umlaute und eine Einheit in Klammern zählen nicht. Ohne Kopfzeile
  gilt: Datum, Stand, Notiz, geschätzt.
- **„Geschätzt“** gilt bei `1`, `true`, `x`, `ja` und dem Ja jeder Sprache
  (`oui`, `sì`, `sí`, `sim` …).
- Meldungen in `errors` stehen in der Sprache der Anfrage.

**Zeiträume** (`…/meters/{id}/periods/import-csv`, v3.1.0) lesen je Zeile
entweder `monat;wert[;notiz]` mit dem Monat als `MM.JJJJ`, `MM/JJJJ` oder
`JJJJ-MM` (ganzer Monat) oder `von;bis;wert[;notiz]` mit Daten wie oben;
Trenner `;`, Tabulator oder `,`, Kopfzeile optional. Der Import liest den
eigenen Export `periods.csv` (Format 1 und „local“) wieder ein: Hat die
Kopfzeile eine Spalte Zähler-ID, zählen nur die Zeilen dieses Zählers.
Einzelheiten: [Verbrauch je Zeitraum](#verbrauch-je-zeitraum-v310).

Die Datei hinter „Beispiel-CSV herunterladen“ steht seit v3.1.0 in der
Sprache der Oberfläche, mit deren Datumsschreibweise und Dezimalzeichen.

### `POST /api/utility/{u}/deliveries`

Pflicht: `meter_id`, `date`, `quantity` (> 0). Optional
`unit_price_cents` **oder** `total_eur`, `supplier`, `note`,
`is_planned`, `fill_to_full` (v2.10.0). **Seit v1.4.2** hat `total_eur` Vorrang vor
`unit_price_cents` — der Rechnungsbetrag ist die tatsächlich bezahlte
Größe (inkl. Liefergebühr/Rabatt); der effektive Stückpreis wird daraus
abgeleitet (`total_eur · 100 / Menge`).

### `GET /api/reports/yearly.pdf?year=YYYY`

Liefert **kein JSON**, sondern direkt ein PDF
(`Content-Type: application/pdf`). Seit **v1.4.2** ohne das frühere
achsenlose Mini-Diagramm — stattdessen eine Kennzahlen-Leiste
(Jahresverbrauch, Ø/Monat, Gesamtkosten, stärkster/schwächster Monat)
plus die Monatstabelle. Erzeugt vom eingebauten, abhängigkeitsfreien
PDF-Writer.

Seit **v2.11.0** mit `inline=1`: `Content-Disposition: inline` statt
`attachment` — der Browser zeigt das PDF, statt es herunterzuladen. Die
Oberfläche öffnet es so in einem neuen Tab („Im Browser öffnen"); in der
Home-Bildschirm-App auf dem iPhone kam der Download oft nicht an. Ohne die
Option bleibt alles wie bisher.

Seit **v3.1.0** setzt der Writer Zeichen um, statt sie wegzuwerfen („CO₂" →
„CO2", der Mittelpunkt in „kWh/m²·a" bleibt), und misst Breiten in Zeichen.
Die eingebauten Schriften kennen nur CP1252; eine Sprache mit
`format.pdfCharset = none` bekommt `422` mit `code`
`errors.report.pdfUnsupportedLanguage` und den Hinweis auf die Druckansicht.
Heute sind alle sieben Sprachen `cp1252`. Das PDF steht in der Sprache der
Anfrage; aus der Oberfläche geladen (ohne `X-ET-Language`) also in der
Standardsprache der Installation — die Druckansicht dagegen in der Sprache des
Geräts.

Seit **v3.4.0** (F1025, „Jahresbericht für alle“) ist das PDF für Leser ohne
Technikhintergrund neu gesetzt: Das Wichtigste mit vier Ampeln und den drei
wichtigsten Aufgaben, was sich gelohnt hat, was zu tun ist (Priorität, Betrag,
Frist), Fristen und Termine, Geld, je Verbrauchsart eine Seite, Themenseiten nur
mit Daten (Sonnenstrom, Wärmepumpe, E-Auto, günstige Stunden, Mietwohnung),
kleines Wörterbuch — mit Diagrammen als Vektorgrafik, Fußzeile „Seite x von y“,
Lesezeichen, `/Lang` und Dokumenttitel. Die Monatstabellen stehen nur noch im
Anhang „Zahlen im Detail“. Lesehilfe:
[Jahresbericht lesen](../anleitungen/jahresbericht-lesen.md). Zusätzliche
Parameter (für beide Routen):

| Parameter | Bedeutung |
|---|---|
| `lang` | Sprache des Berichts (`de`, `en`, `fr`, `it`, `es`, `pt`, `nl`), wie bei `/api/manifest`; eine unbekannte fällt still auf die Sprache der Anfrage zurück. Die Oberfläche hängt die Sprache des Geräts an den PDF-Link |
| `utilities` | Teilbericht: kommagetrennte Verbrauchsarten (`gas,wasser`); eine unbekannte → `400` mit `code` `errors.common.unknownUtility`. Der Dateiname bekommt die Arten angehängt (`energietracker-2025-gas-wasser.pdf`) |
| `detail` | `1` = mit Anhang „Zahlen im Detail“ (eine Monatstabelle je Verbrauchsart; Temperatur und Gradtage nur bei Heizarten) |
| `billing` | *(v3.6.0)* `1` = mit Anhang „Abrechnungsjahre“: je Art mit Rechnungsprüfung die Perioden, die im Berichtsjahr enden ([Abrechnungsperioden](#abrechnungsperioden-v360)); die übrigen Seiten bleiben Kalenderjahr |
| `flex` | *(v3.6.0)* `1` = mit Seite „Flexibilität“: Ergebnis, Handlungssätze, typischer Tag je Halbjahr, beste Zeitfenster, „Dein Hebel“, Sonnenstrom ([Flexibilität](#flexibilität-bewerten-v330)); ohne Ampel und ohne Hebel entfällt sie |

### `GET /api/reports/yearly?year=YYYY` *(v3.1.0)*

Derselbe Bericht als Daten, ohne Formatierung — Grundlage der Druckansicht
(`#/report/print`), die Zahlen, Daten und Beträge im Browser formatiert und
jede Schrift druckt. Stabilitätsklasse B. Felder: `year`, `created_on`,
`location_name`, `version`, `efficiency` (wie `/api/benchmarks/efficiency`),
`utilities[]` (`utility`, `label`, `accounting_kind`, `unit`, `consumption`,
`cost`, `co2_kg`), `has_generation`, `meters[]` (`utility`, `label`,
`meter_id`, `meter_name`, `accounting_kind`, `unit`, `months[]` mit `ym`,
`value`, `cost`, `avg_temp`, `hdd`, `heat_adjusted`, `weather_delta_pct`;
`kpis` mit `sum`, `avg_month`, `cost`, `peak`, `low`;
`has_weather_adjusted`, `has_temperature`) und `recommendations[]` (die
ersten 12, wie `/api/recommendations`). Auswahl: aktive
Verbrauchsarten, je Zähler mit Verbrauch im Jahr ein Eintrag.

Seit **v3.4.0** (additiv) zusätzlich **`model`** — das Datenmodell, aus dem auch
das PDF entsteht (`ReportModelService`), mit fertigen Sätzen in der Sprache der
Anfrage und den Zahlen für die Grafiken. Parameter `lang`, `utilities` und
`detail` wie beim PDF; sie gelten für `model`, `utilities` auch für die Felder
bis v3.3. Felder:

| Feld | Inhalt |
|---|---|
| `version`, `year`, `today`, `today_text`, `lang`, `created_on`, `location_name`, `options` | Rahmen; `today` = „Stand heute“ |
| `texts` | Überschriften und feste Sätze beider Ausgaben |
| `utilities[]` | Verbrauchsarten des Berichts mit `color` (SSOT) und `print_color` (für den Druck abgedunkelt, ≥ 3:1 auf Weiß) |
| `headline` | `tiles[]` (`key`, `label`, `value`, `value_text`, `pct`, `sub`, `spark` über drei Jahre), `lights[]` (`key` cost/consumption/billing/deadlines, `level` ok/warn/alert/none, `word`, `detail`, `as_of_today`), `top_tasks[]` |
| `tasks[]`, `successes[]`, `infos[]` | je Eintrag `id`, `kind`, `rule`, `priority` (1–3, nur Aufgaben), `utility`, `title`, `reason`, `euro` (`value`, `per` year/once/per100, `basis` measured/projected/example, `effect`, `text`, `basis_text`) oder `null`, `deadline`, `deadline_text`, `steps[]`, `as_of_today` |
| `agenda` | `events[]` (`date`, `date_text`, `title`, `kind`, `severity`, `days`, `state`), `timelines[]` je laufendem Vertrag (`points[]`, `sentence`, `explainer`) |
| `money` | `total` (`cost`, `prev`, `pct`, `price_effect`, `volume_effect`, `sentence` — wie die Kachel „Kosten“: Arten mit Vertrag, seit v3.6.0 auch Heizöl und Pellets aus den Lieferungen), `by_utility[]`, `revenue[]`, `monthly[]` (mit Vorjahr), `bonus_notes[]`, `balances[]` je Vertrag (`projected_end_balance`, `current_advance`, `suggested_advance` — `null`, wenn der Vorschlag ≤ 0 wäre —, `text`, `advance_text`, `path[]`, `explainer`), `forecast`; `null` ohne Kosten |
| `pages[]` | je Verbrauchsart: `value`, `prev_value`, `pct`, `months[]` (mit `prev`, `estimated`), `sentence`, `cost` (`basis` contract/deliveries *(v3.6.0: Heizöl, Pellets)*/tenancy/none, `value`, `text`), `weather`, `efficiency`, `roles`, `water`, `tank`, `sub_meters[]`, `notes[]`, `weather_rows[]` |
| `topics` | `pv`, `heat_pump`, `ev`, `flexibility`, `tenancy` — je `null`, wenn es keine Daten gibt |
| `glossary[]`, `sources[]` | Wörterbuch (nur Begriffe, die vorkommen) und Quellen |
| `appendix[]` | nur mit `detail=1`: je Verbrauchsart `head[]`, `rows[]`, `total[]`, `notes[]` |
| `billing[]` | *(v3.6.0)* mit `detail=1` oder `billing=1`: Anhang „Abrechnungsjahre“ je Art mit Rechnungsprüfung, dieselbe Form; Perioden, die im Berichtsjahr enden. Das PDF zeigt ihn nur mit `billing=1` (`options.billing`) |
| `flex_page` | *(v3.6.0)* mit `detail=1` oder `flex=1`: Seite „Flexibilität“ als fertige Sätze — `big`, `sentence`, `facts[]` (`label`, `value`), `footer`, `actions[]` (`text`, `tag`, `other`, `signal`), `actions_lead`, `bands[]` je Halbjahr (`season`, `label`, `hours[]` mit `level`, `sun`, `ct`, `summary`), `band_lead`, `band_lead_pdf`, `windows` (`lead`, `head[]`, `rows[]`), `levers` (`head[]`, `rows[]` mit `label`, `signal`, `real`, `real_text`, `kwh`, `eur`, `total`, `dynamic_note`), `solar_text`, `notes[]`, `legend[]`; `null` ohne Ampel und ohne Hebel. Das PDF zeigt sie nur mit `flex=1` (`options.flex`), die Druckansicht mit `flex=1` in ihrer Adresse |
| `errors[]` | Sektionen, die nicht gelesen werden konnten — der Rest des Berichts steht |

### Ladestrom-Nachweis *(v3.1.0)*

Die Aufstellung für die Erstattung des Ladestroms eines Dienstwagens nach dem
BMF-Schreiben vom 11.11.2025 — eine Aufstellung, keine Steuerberatung
([Anleitung](../anleitungen/ladestrom-nachweis.md)). Klasse B.

| Route | Antwort |
|---|---|
| `GET /api/reports/ev-charging` | JSON (s. u.) |
| `GET /api/reports/ev-charging.csv` | CSV `energietracker-ladestrom-JJJJ.csv`; `format=1` (Standard) oder `local` mit `lang` wie die [CSV-Formate](#csv-formate-v310) |
| `GET /api/reports/ev-charging.pdf` | PDF `energietracker-ladestrom-JJJJ.pdf` in der Standardsprache, `?inline=1` zeigt es im Browser; Sprache ohne PDF-Schrift → `422` `errors.report.pdfUnsupportedLanguage` |

| Parameter | Bedeutung |
|---|---|
| `meter_id` | Stromzähler der Wallbox (Rolle `ev_charger`, meist Subzähler des Haushaltszählers); unbekannt → `404` |
| `year` | 2017–2100, Standard Vorjahr; sonst `400` `errors.evReport.yearInvalid` |
| `method` | `contract` (Standard) oder `flat`; sonst `400` `errors.evReport.methodInvalid` |
| `flat_ct` | eigene Pauschale in ct/kWh (0–200) für `flat`; sonst `400` `errors.evReport.flatInvalid` |

**`contract`** — Preis des zahlenden Vertrags: der eigene Vertrag der Wallbox
(§ 14a EnWG, Modul 2) oder der des Elternzählers. Je Monat Arbeitspreis =
Arbeitskosten ÷ kWh des zahlenden Zählers (tagesgenau), dazu der Grundpreis
anteilig nach kWh (Wallbox ÷ Elternzähler; mit eigenem Vertrag ganz). Die
Aufteilung nach kWh ist eine Annahme — das Schreiben sagt „anteilig“. Ohne
zahlenden Vertrag `400` `errors.evReport.noContract`.

**`flat`** — Strompreispauschale × kWh. Die Pauschale kommt aus `flat_ct` oder
aus dem Länderprofil (`ev_flat_rate_ct_years`, Deutschland 2026: 34 ct/kWh,
2027: 32 ct/kWh; ein Jahr fehlt, bis Destatis das 1. Halbjahr des Vorjahres
veröffentlicht hat);
fehlt sie, `400` `errors.evReport.flatMissing`. Beispiel des Schreibens:
3.000 kWh × 0,34 € = 1.020 €. Die Wahl gilt einheitlich je Kalenderjahr.

```json
{ "meter_id": "m_wallbox", "meter_name": "Wallbox", "serial": "WB-0001",
  "role": "ev_charger", "year": 2026, "method": "contract", "flat_ct": null,
  "payer_meter_id": "m_strom_haus",
  "rows": [ { "ym": "2026-03", "kwh": 100.0, "price_ct": 30.0, "base_share_eur": 3.0,
              "amount_eur": 33.0, "estimated": false,
              "first_reading": { "date": "2026-03-01", "counter": 1520.4 },
              "last_reading":  { "date": "2026-03-31", "counter": 1617.9 } }, "…" ],
  "total": { "kwh": 1180.5, "amount_eur": 389.62 },
  "price_missing": false }
```

`estimated` = der Monat enthält geschätzte Tage; `first_reading`/`last_reading`
sind der erste und letzte Stand im Monat (`null` ohne Stand). `price_ct` und
`amount_eur` sind `null`, wo ein Preis fehlt; dann ist `price_missing` `true`.

CSV im Format 1 mit festem Kopf:

```text
Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode
```

*(v3.2.0)* Die Zählerstände der Wallbox können aus evcc kommen — als
Ablesungen am Zähler gehen sie in den Nachweis ein wie erfasste:
[Ladevorgänge aus evcc](#ladevorgänge-aus-evcc-v320).

### Ladevorgänge aus evcc *(v3.2.0)*

evcc steuert die Wallbox — mit Sonnenstrom oder in günstigen Stunden — und
führt je Ladevorgang Beginn, Ende, Energie, Sonnenanteil, Preis und, wo die
Wallbox misst, den Zählerstand. Der Energietracker übernimmt diese Vorgänge
und rechnet nach; er steuert nichts. Zwei Wege mit derselben Verarbeitung: der
CSV-Export aus evcc (Ladevorgänge → herunterladen) oder der Abruf über die
evcc-API im eigenen Netz. Schritt für Schritt:
[Ladevorgänge aus evcc](../anleitungen/evcc.md). Klasse C.

| Route | Body | Zweck |
|---|---|---|
| `POST /api/utility/strom/meters/{id}/import-evcc[?dry_run=1]` | `{csv, counters?, loadpoint?}` | CSV-Export übernehmen |
| `POST /api/utility/strom/meters/{id}/sync-evcc[?dry_run=1]` | `{counters?, loadpoint?}` | Vorgänge von `GET <evcc_endpoint>/api/sessions` holen |
| `GET /api/ev-sessions?meter_id=&year=` | — | gespeicherte Vorgänge und Monatssummen |

`{id}` ist ein Stromzähler mit Zählerständen. Die Oberfläche bietet beides in
der Ansicht eines Zählers mit der Rolle `ev_charger` (Wallbox) an.

| Feld | Bedeutung |
|---|---|
| `csv` | Inhalt der Datei als Text. Kopfzeile in einer der Sprachen de, en, fr, it, es, pt, nl oder mit den englischen Feldnamen (`created`, `chargedEnergy` …); ein BOM wird entfernt; Trenner Komma oder Semikolon (entschieden an der Kopfzeile); Zahlen mit Punkt oder Komma. Nötig sind die Spalten für Beginn und Energie |
| `counters` | woher die Zählerstände kommen: `auto` (Standard) — die Zählerstände der Wallbox, fehlen sie, die aufsummierte Energie; `energy` — immer aufsummieren, ab dem letzten Stand vor dem ersten Vorgang, ohne ihn ab dem Anfangsstand des eingebauten Geräts (etwa wenn der Zähler im Energietracker nicht der in der Wallbox ist); `none` — nur die Vorgänge, keine Ablesungen |
| `loadpoint` | nur Vorgänge dieses Ladepunkts (Name wie in evcc); fehlt er, alle |

Verarbeitung:

- Ein Vorgang braucht Beginn und Energie (nicht negativ), sonst zählt er unter
  `skipped`. Läuft er noch (Ende leer oder `0001-01-01…`), zählt er unter
  `running` und kommt beim nächsten Import vollständig.
- Zeiten ohne Zeitzone gelten in der Zeitzone der Installation (`timezone`);
  `date` ist der Tag des Endes.
- Jeder Vorgang bekommt eine Kennung aus Beginn und Ladepunkt. Ein zweiter
  Import desselben Vorgangs ersetzt ihn, statt ihn doppelt zu zählen.
- Zählerstände (ein Stand am Tag D gilt als Stand zu Beginn von D): mit
  Zählerständen der Wallbox (`counter_source: "meter"`) der Anfangsstand am
  Tag des Beginns und der Endstand am Tag nach dem Ende (je Tag der höchste);
  sonst (`"energy"`) der Stand bis zum Tag des ersten Vorgangs plus die
  geladene Energie, am Tag nach jedem Vorgang. Stände nach heute und ein
  Rückgang entfallen. Sie gehen als Ablesungen mit der Notiz `evcc` an den
  Zähler; ein vorhandener Stand am selben Tag wird ersetzt.
- Zählerstände gehören zu einer Wallbox: Stammen die Vorgänge von mehreren
  Ladepunkten und ist `counters` nicht `none`, antwortet die Route mit `400`
  `errors.evcc.loadpointNeeded` — dann `loadpoint` angeben.

Antwort, hier die Vorschau mit `?dry_run=1`:

```json
{ "sessions": 12, "skipped": 0, "running": 1, "loadpoints": ["Garage"],
  "from": "2026-09-02", "to": "2026-09-30",
  "charged_kwh": 214.37, "solar_kwh": 131.902,
  "readings": 12, "replaces": 0, "counter_source": "meter", "dry_run": true }
```

| Feld | Bedeutung |
|---|---|
| `sessions` | übernommene Vorgänge (nach dem Filter `loadpoint`) |
| `skipped`, `running` | unlesbare bzw. noch laufende Vorgänge |
| `loadpoints` | alle Ladepunkte der Datei bzw. der Antwort — auch die, die `loadpoint` ausfiltert |
| `from`, `to` | erster und letzter Tag |
| `charged_kwh`, `solar_kwh` | geladene Energie und der Teil aus der Sonne (Energie × Sonnenanteil je Vorgang) |
| `readings`, `replaces` | abgeleitete Zählerstände; `replaces` davon ersetzen einen vorhandenen Stand am selben Tag |
| `counter_source` | `meter`, `energy` oder `none` |
| `import` | nur ohne `dry_run`: Ergebnis des Ablesungs-Imports wie beim CSV-Import (`imported`, `overwritten`, `skipped`, `errors`); `null` ohne Zählerstände |

**Abruf — `sync-evcc`.** Der Server holt `GET <evcc_endpoint>/api/sessions`
(höchstens 30 Sekunden) und erwartet eine Liste mit den Feldern `created`,
`finished`, `loadpoint`, `vehicle`, `meterStart`, `meterStop`,
`chargedEnergy`, `solarPercentage`, `price`, `pricePerKWh`; die Hülle
`{result: […]}` älterer evcc-Versionen wird ausgepackt. Wie bei der
[Texterkennung](#post-apiocrreading) nur im eigenen Netz: Der Name wird
aufgelöst, jede Adresse muss lokal sein, verbunden wird mit genau der
geprüften, Weiterleitungen werden nicht verfolgt.

**Gespeicherte Vorgänge — `GET /api/ev-sessions`.**

```json
{ "sessions": [ { "id": "evs_1a2b3c4d5e6f", "meter_id": "m_wallbox",
      "created": "2026-09-30T17:02:11+02:00", "finished": "2026-09-30T21:40:03+02:00",
      "date": "2026-09-30", "loadpoint": "Garage", "vehicle": "Kleinwagen",
      "charged_kwh": 18.402, "solar_pct": 0, "price_eur": 5.15, "price_per_kwh": 0.28,
      "meter_start": 4120.551, "meter_stop": 4138.953, "source": "csv" } ],
  "monthly": { "2026-09": { "kwh": 214.37, "solar_kwh": 131.902, "solar_pct": 61.5,
                            "price_eur": 38.4, "sessions": 12 } } }
```

`meter_id` und `year` filtern, beide sind optional; ein unbekannter Zähler
ergibt eine leere Liste. `monthly` gibt es nur mit beiden, sonst `null`: je
Monat `kwh`, `solar_kwh`, `solar_pct` (nach Energie gewichtet), `price_eur`
(Summe der Preise laut evcc, `null` ohne Preise) und `sessions`. `year`
außerhalb 2017–2100 → `400` `errors.evReport.yearInvalid`. Die Felder je
Vorgang: [Datenmodell](datenmodell.md#ladevorgänge-ev_sessionsjson-v320).

| Status | `code` | Wann |
|---|---|---|
| `404` | `errors.common.meterNotFound` | kein Stromzähler mit dieser ID |
| `400` | `errors.reading.periodMeter` | Zähler mit Verbrauch je Zeitraum — er nimmt keine Stände an |
| `400` | `errors.evcc.countersInvalid` | `counters` ist nicht `auto`, `energy` oder `none` |
| `400` | `errors.evcc.loadpointNeeded` | Vorgänge mehrerer Ladepunkte mit Zählerständen — `loadpoint` angeben oder `counters: "none"` |
| `400` | `errors.evcc.columns` | CSV ohne die Spalten für Beginn oder Energie |
| `400` | `errors.evcc.noSessions` | kein abgeschlossener Vorgang — auch bei leerer Datei oder einem `loadpoint` ohne Treffer |
| `400` | `errors.evcc.off` | `sync-evcc`: `evcc_endpoint` ist leer |
| `400` | `errors.evcc.notLocal` | `sync-evcc`: eine Adresse von evcc liegt nicht im eigenen Netz |
| `502` | `errors.evcc.unreachable` | `sync-evcc`: Name nicht auflösbar, keine Verbindung oder HTTP-Fehler |
| `502` | `errors.evcc.badAnswer` | `sync-evcc`: die Antwort ist keine Liste von Ladevorgängen |

### `GET /api/agenda?days=90` *(v3.1.0)*

Fristen und Termine aus **einer** Quelle: Das Dashboard („Zu tun“), der
Kalender und Home Assistant lesen dieselben Ereignisse (`AgendaService`). Bis
v3.0 setzte das Dashboard „Zu tun“ im Browser zusammen. Stabilitätsklasse B.

`days` ist das Fenster ab heute (Standard 90, auf 0–730 begrenzt). Dazu kommt
alles, was schon fällig oder überfällig ist. Sortiert nach Datum, dann Art.

```json
{ "success": true, "data": { "events": [
  { "uid": "reading-strom-m_strom_main", "kind": "reading_due", "date": "2026-10-07",
    "title": "Strom: Zählerstand ablesen (Hauptzähler Strom)",
    "params": { "label": "Strom", "meter": "Hauptzähler Strom" },
    "severity": "due", "due_now": true,
    "href": "#/zaehlerstaende?meter=m_strom_main",
    "ref": { "utility": "strom", "meter_id": "m_strom_main", "last_reading": "2026-08-19",
             "days_since_reading": 49, "icon": "⚡" } },
  { "uid": "cancel_by-c_strom_003", "kind": "cancel_by", "date": "2026-11-30",
    "title": "Strom: letzter Tag für die Kündigung (Stadtwerke Musterstadt)",
    "params": { "label": "Strom", "provider": "Stadtwerke Musterstadt" },
    "severity": "upcoming", "due_now": false,
    "href": "#/utility/strom/contracts",
    "ref": { "utility": "strom", "meter_id": "m_strom_main", "contract_id": "c_strom_003", "days": 54 } }
] } }
```

| `kind` | Ereignis | `date` | `due_now` (steht unter „Zu tun“) |
|---|---|---|---|
| `reminder` | Termin (Wartung, Eichfrist …) | nächste Fälligkeit | Termin fällig oder überfällig |
| `reading_due` | Ablesung fällig (kumulative Zähler in Betrieb) | letzte Ablesung + `alert_days_since_reading`; ist das vorbei oder gibt es keine Ablesung: heute | letzte Ablesung älter als `alert_days_since_reading` |
| `cancel_by` | letzter Tag für die Kündigung | Kündigungsstichtag eines laufenden oder künftigen Vertrags | Vertragserinnerung (Empfehlung R6) aktiv und nicht ausgeblendet |
| `term_end` | festes Vertragsende (nicht bei weiterlaufenden Verträgen) | Vertragsende | wie `cancel_by`, wenn sich die Erinnerung auf das Ende bezieht |
| `price_guarantee_end` | Ende der Preisgarantie | `price_guarantee_until` | nie |
| `price_increase` | eingetragene Preiserhöhung — Sonderkündigung prüfen | Tag der Erhöhung | *(seit v3.1.0)* solange die Empfehlung `r_price_increase` (nur Land DE) steht und nicht ausgeblendet ist — dann `severity` `due` und `ref.recommendation_id`; ausgeblendet wieder `upcoming`. Bis v3.0: nie |
| `tank_reorder` | Heizöl/Pellets: Bestand niedrig (Empfehlung R5) | heute | immer, solange die Empfehlung nicht ausgeblendet ist |
| `tenancy_statement_due` *(v3.1.0)* | Nebenkostenabrechnung des letzten abgelaufenen Abrechnungszeitraums fällig (§ 556 Abs. 3 BGB) — nur, solange für diesen Zeitraum keine Abrechnung erfasst ist | Ende des Zeitraums + 12 Monate | nie |
| `objection_deadline` *(v3.1.0)* | letzter Tag für Einwände gegen eine Abrechnung | Zugang (`received_on`) + 12 Monate | ab 30 Tagen vorher (`severity` dann `due`) |
| `co2_claim_deadline` *(v3.1.0)* | Etagenheizung zur Miete: Anteil des Vermieters an den CO₂-Kosten einfordern (CO2KostAufG § 6 Abs. 2) — nur mit `case: self_supplied` und Betrag über 0 | `deadline` aus `/api/co2-split`: Rechnungsdatum der Gasrechnung + 12 Monate | ab 30 Tagen vorher (`severity` dann `due`) |
| `bill_expected` *(v3.5.0)* | Abrechnungszeitraum eines Vertrags beendet, keine Versorgerrechnung erfasst — [Abrechnung erwartet](#abrechnung-erwartet-v350) | Strom/Gas in DE: Ende + 42 Tage (§ 40c Abs. 2 EnWG); sonst Tag nach dem Ende | immer; `severity` `due`, nach der gesetzlichen Frist `overdue` |

**Zeitraum-Zähler *(v3.1.0)*.** Für einen Zähler mit Verbrauch je Zeitraum
rechnet `reading_due` ab dem Ende des letzten Zeitraums statt ab der letzten
Ablesung; `ref.last_reading` trägt dann dieses Datum.

**Mietverhältnis *(v3.1.0)*.** Die beiden Fristen der Nebenkostenabrechnung
gibt es nur mit `wohnverhaeltnis: "miete"` und je Mietverhältnis
([Mietverhältnis](#mietverhältnis-v310)). Der Abrechnungszeitraum folgt aus
`billing_anchor`; `tenancy_statement_due` erscheint, wenn dieser Zeitraum im
Mietverhältnis liegt und keine Abrechnung mit einem Ende ab 31 Tagen vor seinem
Ende erfasst ist — `ref`: `{tenancy_id, period_to}`, `params.period` = Ende
des Zeitraums, `severity` `upcoming` (nach Ablauf `overdue`). `objection_deadline` gibt es je Abrechnung mit `received_on`,
bis die Frist verstrichen ist — `ref`: `{tenancy_id, statement_id, days}`.
Beide verweisen auf `#/tenancy`. Die Fristen sind ein Hinweis, keine
Rechtsberatung.

**CO₂-Kosten *(v3.1.0, H5)*.** `co2_claim_deadline` gibt es mit
`wohnverhaeltnis: "miete"` für die Abrechnungsjahre bis zwei Jahre zurück, wenn
`GET /api/co2-split` für das Jahr `case: self_supplied`, einen
`landlord_amount_eur` über 0 und eine `deadline` liefert, die noch nicht
verstrichen ist. Titel „CO₂-Kosten {Jahr}: Vermieteranteil von {Betrag} bis
heute einfordern“, `params` `{year, amount}` (Betrag formatiert), `href`
`#/tenancy`, `ref` `{year, landlord_amount_eur, days}`.

`severity`: `overdue` (Termin überfällig), `urgent` (Frist in höchstens
14 Tagen, Bestand dringend), `due` (fällig), `upcoming` (kommt noch). `title`
steht in der Sprache der Anfrage, `params` enthält seine Bausteine. `href` ist
die passende Ansicht der Oberfläche, `ref` verweist auf die Daten (Zähler,
Vertrag, Termin, Empfehlung). `uid` bleibt für dasselbe Objekt gleich — ein
erledigter Termin behält sie und rückt auf das neue Datum.

**Erledigt *(v3.4.0, F1024)*.** Jedes Ereignis trägt zusätzlich `title_key`
(Katalogschlüssel des Titels), `occurrence` (`uid@anker`), `can_mark_done` und
`done` (`null` oder ein Vermerk); Erledigtes ist nie `due_now` und steht mit
`severity` `upcoming`. Ein lückenloser Folgevertrag erledigt `cancel_by`,
`term_end`, `price_guarantee_end` und `price_increase` des Vorgängers von
selbst; weiterlaufende und unbefristete Verträge haben kein `cancel_by` mehr
(es stand jeden Tag auf heute). Einzelheiten:
[Erledigt mit Verlauf](#erledigt-mit-verlauf-v340).

**Zuständig *(v3.5.0, F1028)*.** Jedes Ereignis trägt additiv `assignee`,
`assignee_id` und `assignee_former` — bei Terminen die Zuständigkeit
([Zuständig an Terminen](#zuständig-an-terminen-v350)), sonst `null`, `null`,
`false`; *(v3.6.0)* dazu `assignee_deleted` (Person gelöscht, Name bleibt).

**Geerbt *(v3.7.0)*.** Ohne eigene Zuständigkeit tragen die vier Felder die
des Vertrags, sonst die des Zählers
([Zuständig an Zählern und Verträgen](#zuständig-an-zählern-und-verträgen-v370)),
dazu additiv `assignee_source`: `own` (am Termin), `contract`, `meter` oder
`null` (niemand). Ein Termin am Zähler trägt `ref.utility` und
`ref.meter_id`.

| Art | geerbt von |
|---|---|
| `reminder` | Zähler des Termins (`utility`, `meter_id`) |
| `cancel_by`, `term_end`, `price_guarantee_end`, `price_increase`, `bill_expected` | Vertrag, dann Zähler (eine Gruppe hat keine Zuständigkeit) |
| `reading_due`, `tank_reorder` | Zähler |
| `tenancy_statement_due`, `objection_deadline`, `co2_claim_deadline` | – |

Beim Abhaken (`POST /api/agenda/done`) steht die Zuständigkeit als
Momentaufnahme `assignee` im Verlaufseintrag.

#### Abrechnung erwartet *(v3.5.0)*

`bill_expected` (F1029): Endet ein Abrechnungszeitraum eines echten Vertrags
(kein Schattenvertrag) einer Art mit Rechnungsprüfung (`supports_bill_check`:
Gas, Strom, Wasser, Fernwärme — nie Heizöl, Pellets, Einspeisung) und deckt
keine erfasste Versorgerrechnung ihn ab, steht das Ereignis in der Agenda.
Je Vertrag nur der jüngste beendete Zeitraum:

- **läuft** (auch weiterlaufend): die zuletzt abgeschlossene Periode nach
  `billing_cycle_anchor_<art>`, ab Vertragsbeginn; kürzer als 60 Tage → kein
  Ereignis;
- **beendet**: Schlussrechnung vom letzten Stichtag bis zum Ende (ein
  weiterlaufender Vertrag endet am Tag vor dem Folgevertrag); schließt ein
  Folgevertrag desselben Versorgers lückenlos an, keine.

Nicht für Zeiträume, die vor mehr als 120 Tagen endeten
(`BillExpectedService::WINDOW_DAYS`: Frist 42 Tage plus gut elf Wochen; danach
entfällt das Ereignis ohne Verlaufseintrag), die vor der ersten
Ablesung (bzw. dem ersten Zeitraum) enden oder vor einer wirksamen
Analyse-Zäsur. **Passend** ist eine Rechnung am Zähler, an einem Mitglied
der Gruppe oder an der Gruppe, ohne oder mit diesem `contract_id`, deren
`period_to` höchstens 31 Tage vom Periodenende abweicht — dann gibt es kein
Ereignis.

**Frist** (`Countries::BILL_DEADLINE_DAYS`): nur Strom und Gas in
Deutschland, 42 Tage nach dem Ende (§ 40c Abs. 2 EnWG: sechs Wochen nach dem
Abrechnungszeitraum bzw. nach Beendigung des Lieferverhältnisses); danach
`overdue` mit dem Titel „… — gesetzliche Frist verstrichen, beim Versorger
nachfragen“. Fernwärme (§ 4 Abs. 3 FFVAV) und Wasser (§ 24 Abs. 1
AVBWasserV) kennen keine Frist: `deadline: null`, nie `overdue`. Die
monatliche Abrechnung (drei Wochen) und § 193 BGB bildet die App nicht ab.

| Feld | Wert |
|---|---|
| `uid` | `bill_expected-<vertrag>-<periodenende>`; `occurrence` = `uid@<periodenende>` |
| `title_key` | `calendar.event.billExpected`, `…billOverdue`, `…finalBillExpected`, `…finalBillOverdue`; `params` `{label, provider, from, until}` (Tage als JJJJ-MM-TT, im `title` in der Schreibweise der Sprache) |
| `date` | `deadline`, ohne Frist der Tag nach dem Periodenende |
| `ref` | `{utility, meter_id (Zähler oder Gruppe), contract_id, period_from, period_until, final, deadline, overdue}` |
| `href` | `#/bill-check?utility=…&meter=…&from=…&until=…&capture=1` — Rechnung prüfen mit diesem Zeitraum und offenem Abschnitt zum Erfassen |
| `can_mark_done` | ja — abgehakt heißt „kam ohne Erfassung“ (`reason` `activity.reason.billWithout`) |

Erfasst (`POST /api/utility/{u}/bills`) oder geändert, sodass eine Rechnung
die Periode abdeckt, entsteht ein Verlaufseintrag `auto_done` mit `reason`
`activity.reason.bill` und `ref.bill_id`; Löschen oder Verschieben der
Rechnung streicht ihn durch, das Ereignis ist wieder offen.

### `GET /api/calendar.ics` *(v3.1.0)*

Dieselben Ereignisse als abonnierbarer Kalender nach RFC 5545, ohne
Drittdienst. Stabilitätsklasse A für die **Form** (ganztägige Ereignisse,
Felder unten) und die **UIDs**; die Texte können sich mit einer Übersetzung
ändern. Einrichtung in Apple Kalender, Thunderbird und Google Kalender:
[Kalender abonnieren](../anleitungen/kalender.md).

- Ereignisse der nächsten **365 Tage** (`/api/agenda` mit `days=365`), ganztägig
  (`DTSTART;VALUE=DATE`, `DTEND` = Folgetag), `TRANSP:TRANSPARENT` (blockiert
  keine Zeit), `CATEGORIES` = Art in Großbuchstaben.
- `UID:<art>-<id>@<instance_id>`, etwa `cancel_by-c_strom_003@et_3f9a1c0b7d2e4a61`.
  Formen: `reminder-<id>`, `reading-<utility>-<zähler>`, `cancel_by-<vertrag>`,
  `term_end-<vertrag>`, `price_guarantee_end-<vertrag>`,
  `price_increase-<vertrag>-<datum>`, `tank_reorder-<utility>-<zähler>`, seit
  v3.1.0 `tenancy_statement_due-<mietverhältnis>-<zeitraumende>`,
  `objection_deadline-<abrechnung>` und `co2_claim_deadline-<jahr>`, seit
  v3.5.0 `bill_expected-<vertrag>-<periodenende>`. Wird ein
  Termin erledigt, steht beim nächsten Abruf dasselbe Ereignis am neuen Datum.
- *(v3.5.0)* Ein Termin mit Zuständigkeit trägt `DESCRIPTION:Zuständig: <Name>`
  (Text `calendar.assignee`; nach dem Löschen der Person mit „ehemalige
  Person“) — nur die Zeile, keine Benachrichtigung. *(v3.7.0)* Geerbte
  Zuständigkeit mit Zusatz: `Zuständig: <Name> (über den Vertrag)` bzw.
  `(über den Zähler)` (`calendar.assigneeContract`, `…Meter`).
- *(v3.7.0)* **`?person=<id>`** (eine Personen-ID wie `u_3f9a1c2e`, sonst 400
  `errors.assignee.unknown`): nur Ereignisse, die bei dieser Person liegen —
  eigene oder geerbte Zuständigkeit per ID, ein freier Name am Termin, wenn er
  ihrem gleicht; gelöschte und „ehemalige Person“ zählen nicht.
  `X-WR-CALNAME` nennt die Person (`calendar.nameFor`). Mit dem Kalender-Link
  zusammen: `…/calendar.ics?token=etk_…&person=u_…`.
- Vorab-Erinnerung (`VALARM`, `TRIGGER:-P<n>D`) für Termine, Kündigungsstichtage,
  Vertragsenden, Preisgarantien und Preiserhöhungen; `<n>` ist
  `reminder_warn_days_before` (Einstellung, Standard 14, `0` = keine). Die
  Fristen der Nebenkostenabrechnung und der CO₂-Erstattung (v3.1.0) tragen
  keine Vorab-Erinnerung.
- *(v3.4.0)* **Erledigtes** (`done` in der Agenda) bleibt mit derselben UID im
  Kalender, `SUMMARY` beginnt mit „✓ “, ein `VALARM` entfällt.
- `REFRESH-INTERVAL;VALUE=DURATION:PT12H` und `X-PUBLISHED-TTL:PT12H`: Kalender
  fragen alle 12 Stunden neu an. `X-WR-CALNAME` ist der Name des Kalenders.
- Texte in der **Standardsprache der Installation** — Kalender schicken keine
  Sprache mit.
- Antwortkopf `Content-Type: text/calendar; charset=utf-8`,
  `Cache-Control: private, max-age=900`; Zeilen über 75 Byte gefaltet, CRLF.

```text
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Energietracker//Kalender//DE
CALSCALE:GREGORIAN
METHOD:PUBLISH
X-WR-CALNAME:Energietracker — Fristen und Termine
REFRESH-INTERVAL;VALUE=DURATION:PT12H
X-PUBLISHED-TTL:PT12H
BEGIN:VEVENT
UID:cancel_by-c_strom_003@et_3f9a1c0b7d2e4a61
DTSTAMP:20261007T071502Z
DTSTART;VALUE=DATE:20261130
DTEND;VALUE=DATE:20261201
SUMMARY:Strom: letzter Tag für die Kündigung (Stadtwerke Musterstadt)
CATEGORIES:CANCEL_BY
TRANSP:TRANSPARENT
BEGIN:VALARM
ACTION:DISPLAY
DESCRIPTION:Strom: letzter Tag für die Kündigung (Stadtwerke Musterstadt)
TRIGGER:-P14D
END:VALARM
END:VEVENT
END:VCALENDAR
```

**Zugang.** Ohne Anmeldung offen wie jede Route. Mit Anmeldung: Sitzung oder
API-Schlüssel in der Kopfzeile, für Kalender-Apps ein Schlüssel mit Bereich
`calendar` im Link — `…/api.php/api/calendar.ics?token=etk_…`. Nur dieser
Bereich gilt im Link (s. [Anmeldung](#anmeldung-v260-opt-in)). Die Oberfläche legt
ihn unter Hinweise → Termine & Wartung → „Im Kalender abonnieren“ an; widerrufen lässt er
sich unter Einstellungen → Zugriff.

### `GET /api/summary` *(v3.1.0)*

Kennzahlen für Home Assistant und Skripte in einer flachen Antwort: eine
Abfrage für alle Sensoren statt `consumption`, `contract-status` und
`forecast` je Zähler. Stabilitätsklasse A mit eigener Versionsnummer
`summary_version` (heute `1`): **Jeder Schlüssel ist immer da** — mit Wert oder
`null` —, neue kommen nur hinzu, die Nummer steigt nur bei einem Bruch.

| Parameter | Bedeutung |
|---|---|
| `utility` | nur diese Verbrauchsart |
| `meter` | nur dieser Zähler — interne ID oder Alias (`external_id`) |

```json
{ "success": true, "data": {
  "summary_version": 1,
  "instance_id": "et_3f9a1c0b7d2e4a61",
  "generated_at": "2026-10-07T09:15:02+02:00",
  "app_version": "3.1.0",
  "currency": "EUR",
  "meters": [
    { "key": "strom.m_strom_main", "utility": "strom", "meter_id": "m_strom_main",
      "external_id": "stromzaehler_haus", "name": "Hauptzähler Strom",
      "unit": "kWh", "consumption_unit": "kWh", "role": "household", "is_sub_meter": false,
      "last_reading_date": "2026-09-30", "last_counter": 48211.4,
      "days_since_reading": 7, "reading_due": false,
      "month_to_date": { "value": 61.2, "estimated": true },
      "year_to_date":  { "value": 2318.7, "estimated": true },
      "contract": { "contract_id": "c_strom_003", "provider": "Stadtwerke Musterstadt",
        "tariff_name": "Strom Basis", "working_price_ct": 29.8, "base_price_month": 12.5,
        "advance_month": 78, "suggested_advance": 74, "balance": -41.3,
        "projected_end_balance": -52.9, "verdict": "refund", "balance_as_of": "2026-10-07",
        "period_end": "2027-03-31", "period_last_day": "2027-03-31",
        "cancel_by": "2027-02-28", "days_to_cancel": 144,
        "price_increase_from": null },
      "forecast_12m": { "value": 3120.4, "cost": 1080.55, "low": 2890.1, "high": 3350.7 },
      "tank": null,
      "capture": "counter" }
  ],
  "pv": { "autarky_pct_ytd": null, "self_consumption_pct_ytd": null, "strom_saldo_ytd": null },
  "agenda": { "due": 1, "overdue": 0,
              "next": { "kind": "reminder", "date": "2026-10-15", "title": "Rauchmelder prüfen" } },
  "warnings": 0
}}
```

| Feld | Bedeutung |
|---|---|
| `instance_id` | Kennung dieser Installation (`et_` + 16 Hexziffern), beim ersten Bedarf angelegt in `data/instance.json`. Der Kalender bildet daraus seine UIDs; Skripte unterscheiden damit mehrere Installationen. Bewusst **nicht** im Backup — sonst trügen zwei Installationen nach einem Restore dieselbe |
| `currency` | Währung der Beträge (Einstellung, nicht umgerechnet) |
| `meters[]` | je Zähler in Betrieb der aktiven Verbrauchsarten ein Eintrag |
| `pv` | Autarkie- und Eigenverbrauchsquote (%) und Strom-Saldo des laufenden Jahres, sonst `null` |
| `agenda` | `due` = Einträge unter „Zu tun“, `overdue` = davon überfällig oder dringend, `next` = nächster Termin bzw. nächste Frist (`kind`, `date`, `title`) oder `null`; seit v3.4.0 jeweils ohne Erledigtes; seit v3.5.0 zählt `bill_expected` mit (überfällig nach der gesetzlichen Frist, `next` mit ihrem Datum) |
| `warnings` | Zahl der unbestätigten verdächtigen Stände (Home Assistant) |

Je Zähler:

| Feld | Bedeutung |
|---|---|
| `key` | `<utility>.<meter_id>` — eindeutig, Grundlage der Home-Assistant-Vorlage |
| `external_id` | Alias für Home Assistant oder `null` |
| `unit`, `consumption_unit` | Einheit des Zählerstands bzw. des Verbrauchs (bei Gas m³ und kWh) |
| `role`, `is_sub_meter` | die **wirksame** Rolle des Zählers — ohne gespeicherte Rolle die Standardrolle der Art (etwa `household`), `null` nur bei Arten ohne Rollen —, Subzähler ja/nein |
| `last_reading_date`, `last_counter`, `days_since_reading` | letzte gültige Ablesung (ohne verdächtige und geplante). Bei einem Zähler mit Verbrauch je Zeitraum: `last_reading_date` = Ende des letzten Zeitraums, `last_counter` `null`, die Tage ab diesem Ende |
| `reading_due` | Ablesung bzw. nächster Zeitraum älter als `alert_days_since_reading` (nur kumulative Zähler) |
| `capture` | *(v3.1.0, letzter Schlüssel des Eintrags)* Erfassungsart: `counter` (Zählerstände) oder `period` (Verbrauch je Zeitraum) |
| `month_to_date`, `year_to_date` | Verbrauch des laufenden Monats bzw. Jahres in `consumption_unit`, `estimated: true` = enthält geschätzte Tage; `null` ohne Daten im Jahr |
| `contract` | laufender Vertrag (Arten mit Verträgen), sonst `null`: Preise und Abschlag, `balance` und `projected_end_balance` mit dem Vorzeichen von `contract-status` (positiv = Nachzahlung, bei der Einspeisung = Auszahlung), `verdict`, `period_end`, *(v3.7.0, additiv)* `period_last_day`, `cancel_by`, `days_to_cancel`, `price_increase_from`. `period_end` ist bei einem Vertrag mit gepflegtem Ende dieses Ende (einschließlich), sonst der Stichtag der nächsten Abrechnung — der **erste Tag der nächsten Periode**; so bleibt es für bestehende Sensoren. `period_last_day` ist immer der **letzte Tag** der Periode: das Ende selbst bzw. der Tag vor dem Stichtag (Stichtag 01-09: `period_end` 2027-09-01, `period_last_day` 2027-08-31). Seit v3.4.0 sind `cancel_by` und `days_to_cancel` `null`, wenn der Vertrag keinen datierten Stichtag hat (weiterlaufend, unbefristet, jederzeit kündbar — bis v3.3 stand dort jeden Tag „heute“) oder ein Folgevertrag lückenlos anschließt |
| `forecast_12m` | Prognose der nächsten zwölf Monate: `value` in `consumption_unit`, `cost` (bei reiner Erzeugung `null`), `low`/`high` = Jahresband; `null` bei Heizöl/Pellets oder ohne gültige Prognose |
| `tank` | Heizöl/Pellets: `stock`, `capacity`, `unit`, `percent`, `estimated_from`; sonst `null` |

**Zugang.** Ohne Anmeldung offen. Mit Anmeldung ein API-Schlüssel mit Bereich
`read` als `Authorization: Bearer etk_…` (der Ingest-Token `et_…` gilt hier
nicht). Antwortkopf `Cache-Control: private, max-age=300`: Home Assistant fragt
periodisch, fünf Minuten genügen. `agenda.next.title` steht in der
Standardsprache der Installation, sofern die Anfrage kein `X-ET-Language`
schickt. Vorlage für Home Assistant:
[Home Assistant, Schritt 5](../anleitungen/home-assistant.md#schritt-5--werte-zurück-nach-home-assistant).

### Erledigt mit Verlauf *(v3.4.0)*

Hinweise lassen sich als erledigt kennzeichnen — von Hand oder von selbst aus
den Daten —, und ein Verlauf hält fest, was wann von wem erledigt wurde
([Anleitung](../anleitungen/hinweise-erledigen.md), Topf
[`activity`](datenmodell.md#verlauf-erledigter-hinweise-activityjson-v340)).
`/api/activity` und `POST /api/agenda/done` sind Stabilitätsklasse C; die
neuen Felder in Agenda und `contract-status` gehören zu deren Klasse B.

**Folgevertrag (`contract-status`, additiv).** Je Vertrag `successor`: `null`
oder `{contract_id, provider, start, gap_days}` — der nächste echte Vertrag
desselben Zählers bzw. derselben Gruppe (kein Schattenvertrag), der später
beginnt und nicht vor diesem endet. `provider` ist ersatzweise der Tarifname.
`gap_days` = Tage ohne Vertrag zwischen Ende + 1 und Beginn, `0` = lückenlos
(auch bei Überlappung); ein weiterlaufender oder unbefristeter Vertrag läuft bis
zum Folgevertrag, dort ist sie immer `0`. Bei `gap_days: 0` sind
`should_remind` `false`, `remind_stage` `0` und `cancel_missed` `false` —
Empfehlung R6, Hinweise der Auswertung und „Kündigen bis“ entfallen. Mit Lücke
bleibt die Erinnerung; der Text von R6 nennt die Lücke. *Geänderter Wert:*
`remind_basis` ist `cancel_by` nur noch bei einem datierten Stichtag; ein
befristeter, jederzeit kündbarer Vertrag (`notice_mode` `month_end`/`any_day`)
erinnert ans Ende (`end`) statt täglich an „Kündigung bis heute“.

**Agenda (additiv).**

| Feld | Bedeutung |
|---|---|
| `title_key` | Katalogschlüssel des Titels (mit `params` in jeder Sprache darstellbar) |
| `occurrence` | `uid@anker`: Vertrag sein Ende bzw. `renewed`/`open`, Tank die letzte Lieferung (`none` ohne), Termin `next_due`, Ablesung die letzte Ablesung, Fristen der Miete ihr Datum. Ein Haken gilt für dieses Vorkommen; ändert sich der Anker, ist der Hinweis wieder offen |
| `can_mark_done` | von Hand abhakbar: `reminder`, `cancel_by`, `term_end`, `price_increase`, `tank_reorder`, `objection_deadline`, `co2_claim_deadline`, seit v3.5.0 `bill_expected` — und noch nicht erledigt |
| `done` | `null` oder `{auto, on, by, activity_id, note, reason, successor_id}` (Felder ohne Wert fehlen). `auto: true` mit `reason {key: "activity.reason.successor", params: {provider, date}}` = lückenloser Folgevertrag |

Weiterlaufende und unbefristete Verträge sowie befristete, jederzeit kündbare
haben kein `cancel_by`-Ereignis mehr (`switchTiming` liefert dort weiter „heute“
für die Wechselplanung). R6 bzw. `r_price_increase` und R5 tragen additiv
`evidence.contract_id`, `evidence.deadline` (Stichtag, Vertragsende bzw. Tag
der Erhöhung) bzw. `evidence.anchor` (letzte Lieferung). Von Hand abgehakt
erscheinen sie für dieses Vorkommen nicht mehr.

**`POST /api/agenda/done`** `{uid, occurrence?, done_on?, note?, attachment_id?, cancelled?}`

- `uid` aus der Agenda (Fenster 730 Tage); `occurrence` optional als Prüfung —
  weicht es ab, `409` `errors.activity.occurrenceChanged`.
- `done_on` Kalendertag, nicht in der Zukunft (Standard heute); `note` bis 500
  Zeichen; `attachment_id` ein vorhandener Beleg (hochladen mit `kind=other`,
  Verweisart `activity`).
- `cancelled: true` beim `cancel_by`: setzt `auto_renews: false` am Vertrag
  (Rückgängig stellt es zurück).
- Ein Termin läuft über `ReminderService::markDone` — wie
  `POST /api/reminders/{id}/done` (nächste Fälligkeit, `last_done`).
- Antwort `201` `{activity_id, entry, event}`; schon erledigt `200` mit
  `duplicate: true` und dem bestehenden Eintrag. `404`
  `errors.activity.eventNotFound`, `422` `errors.activity.notManual`
  (`reading_due`, `tenancy_statement_due`, `price_guarantee_end`).

**Automatische Einträge** (`action: "auto_done"`, `reason`): lückenloser
Folgevertrag angelegt oder geändert (Vorgänger läuft noch oder weiter;
`subject.kind: "contract"`, `ref.successor_id`; Löschen oder Ändern des
Folgevertrags streicht ihn durch), Ablesung erfasst — **nur, wenn
`reading_due` fällig war** und die Ablesung neuer ist als die letzte, sonst
füllte der tägliche Home-Assistant-Ingest den Verlauf —, Lieferung bei
niedrigem Bestand, Nebenkostenabrechnung für den offenen Zeitraum. `by` ist die
handelnde Person bzw. der Schlüssel, beim Ingest `{type: "integration", name:
"home_assistant"}`, bei evcc `name: "evcc"`, ohne Anmeldung `{type: "auto",
reason}`. Seit v3.5.0 dazu: neuer Zeitraum bei Zählern mit Verbrauch je
Zeitraum (wie die Ablesung, nur wenn fällig; `reason` `activity.reason.period`
mit `from`, `to`), erfasste Versorgerrechnung für eine erwartete Abrechnung
(`activity.reason.bill`, `ref.bill_id`).

**Geändert *(v3.5.0)*** (`action: "updated"`): Eine geänderte
Nebenkostenabrechnung (`PATCH /api/tenancies/{id}/statements/{sid}`) steht
als `subject.kind: "tenancy_statement"`, `title_key`
`activity.changed.statement` im Verlauf — nur zum Nachweis, sie erledigt
nichts und lässt sich nicht zurücknehmen. Schließt erst die Änderung den
offenen Zeitraum, ist der Eintrag stattdessen das `auto_done` für
`tenancy_statement_due` (nie zwei); öffnet die Änderung ihn wieder, wird diese
Erledigung durchgestrichen. Unveränderte Abrechnungen erzeugen keinen Eintrag.

**Zuständig *(v3.5.0)*.** Beim Erledigen eines Termins steht additiv
`assignee` im Eintrag: `{name, id?}` als Momentaufnahme oder
`{former: true}` nach „ehemalige Person“.

**`GET /api/activity`** — Liste der Einträge (Felder im
[Datenmodell](datenmodell.md#verlauf-erledigter-hinweise-activityjson-v340)),
neueste zuerst nach `done_on`, dann `recorded_at`. Filter `from`, `to`
(`400` `errors.activity.dateInvalid` bei einem ungültigen Tag), `by`, `kind`,
`ref`, `limit`. Lese-Schlüssel dürfen lesen.

**`POST /api/activity/{id}/undo`** — setzt `undone {at, by}` und stellt zurück,
was `revert` nennt (Termin: `next_due`, `active`, `last_done`; Kündigung:
`auto_renews`; Ausblenden: Empfehlung wieder einblenden), solange der Zustand
noch der nach dem Erledigen ist. Antwort: der Eintrag mit `reverted`
(`true`/`false`). Ein zweites Rückgängig ändert nichts. `404`
`errors.activity.notFound`.

**`PATCH /api/activity/{id}`** `{note}` — Notiz ändern; ohne `note` `400`
`errors.activity.noteInvalid`.

**`DELETE /api/activity?before=JJJJ-MM-TT`** — entfernt Einträge, die vor dem
Tag erledigt wurden, ohne `before` alle; Antwort `{deleted}`. Nur Verwalter.
Belege der entfernten Einträge werden frei.

**Rechte.** Abhaken, Rückgängig und Notiz dürfen Verwalter und Mitglieder;
leeren und `activity_keep_months` ändern nur Verwalter (`403`
`errors.auth.adminOnly`); ein Lese-Schlüssel schreibt nichts (`403`
`errors.auth.readOnlyKey`). Ohne Anmeldung ist alles erlaubt, `by` ist dann
`null`. **Ausblenden** (`POST /api/recommendations/{id}/dismiss`) legt einen
Eintrag `dismissed` an (`subject.kind: "recommendation"`); `DELETE …/dismiss`
streicht ihn durch. **Aufbewahrung:** `activity_keep_months` (Standard 60,
`0` = unbegrenzt), aufgeräumt beim Schreiben.

### Verlauf als CSV *(v3.5.0)*

`GET /api/activity.csv` — derselbe Verlauf als Datei zum Nachweis (etwa für
Vermieter oder Hausverwaltung). Filter wie `GET /api/activity` (`from`, `to`,
`by`, `kind`, `ref`, `limit`; ohne `limit` bis 5000 Einträge), neueste zuerst;
durchgestrichene (zurückgenommene) Einträge fehlen. Format wie die übrigen
CSV-Exporte ([CSV-Formate](#csv-formate-v310)): UTF-8 mit BOM, CRLF, Schutz
gegen Formeln (Apostroph vor `=`, `+`, `-`, `@`).

| `format` | Kopfzeile | Aktion | Sprache von Titel, Wer, Grund |
|---|---|---|---|
| `1` (Standard) | `Datum;Aktion;Titel;Wer;Grund;Notiz` | Code: `done`, `auto_done`, `dismissed`, `updated` | der Anfrage (ohne `X-ET-Language` die Standardsprache) |
| `local` (`&lang=`) | `csvLocal.activity.*` der Sprache, Trenner `;` bei Dezimalkomma, sonst `,` | `activity.actionName.*` | `lang`, sonst die Standardsprache |

Dateiname `energietracker-verlauf-JJJJ-MM-TT.csv` (Format 1) bzw. nach
`csvLocal.file.activity`. Die Oberfläche lädt „Als CSV“ mit `format=local` und
der Sprache des Geräts. Unbekanntes Format `400`
`errors.export.formatInvalid`, ungültiger Tag `400`
`errors.activity.dateInvalid`. Klasse C.

### Zuständig an Terminen *(v3.5.0)*

Ein Termin (`reminders.json`) hat optional eine zuständige Person (F1028).
Kein Schemaschritt; fehlende Felder = niemand. Seit v3.7.0 auch Zähler und
Verträge ([unten](#zuständig-an-zählern-und-verträgen-v370)). Benachrichtigt
wird niemand.

| Feld | Bedeutung |
|---|---|
| `assignee` | Anzeigename, getrimmt, bis 60 Zeichen, ohne Steuerzeichen; leer bzw. fehlend = niemand |
| `assignee_id` | Personen-ID ([Personen](#personen-im-haushalt-v320), [Verzeichnis](#personenverzeichnis-v360)), nur wenn aus der Personenliste gewählt; dann gilt der heutige Name der Person — bei einem erledigten einmaligen Termin der Name beim Erledigen |
| `assignee_former` | `true`, nachdem die Person gelöscht und ihr Name ersetzt wurde („ehemalige Person“) |
| `assignee_deleted` | *(v3.6.0)* `true`: Die Person ist gelöscht (Vermerk am Termin oder laut Verzeichnis); Name und ID bleiben, die Oberfläche zeigt „Name (gelöscht)“, der Kalender ebenso |

`GET /api/reminders` liefert alle vier immer (`null`, `null`, `false`,
`false` ohne Zuständigkeit). `POST`/`PATCH /api/reminders…` nehmen `assignee` und
`assignee_id`: Eine bekannte `assignee_id` setzt den Namen der Person,
`assignee` ohne `assignee_id` ist ein freier Name, beides leer = niemand; ein
`PATCH` ohne die Felder ändert die Zuständigkeit nicht. Fehler `400`
`errors.reminder.assigneeInvalid` (zu lang, Steuerzeichen, kein Text) bzw.
`errors.reminder.assigneeUnknown` (unbekannte oder seit v3.6.0 gelöschte
Person). Erledigen (`…/done`) lässt die Zuständigkeit stehen; der
Verlaufseintrag nennt sie.

**Person gelöscht** (`DELETE /api/users/{id}`), *v3.6.0* — bis v3.5.0
verschwand nur die `assignee_id`, der Name blieb als freier Name: **Offene**
Termine (aktiv; per ID oder mit gleichem freiem Namen ohne ID) gehen an die
löschende Person. **Erledigte** behalten Name und ID mit
`assignee_deleted: true`, mit `?anonymize_activity=1` stattdessen
`assignee_former: true`. Löscht ein API-Schlüssel (keine Person), bleiben auch
offene Termine mit `assignee_deleted: true` stehen. Im Verlauf bekommen `by`,
`undone.by` und `assignee` der Person `deleted: true` (bzw. mit
`anonymize_activity` wie bisher `former: true`).

**Personenliste:** `GET /api/people` → `[{id, name}]` für jede angemeldete Rolle
(die volle Liste `GET /api/users` bleibt Verwaltern). Seit v3.6.0 aus dem
Personenverzeichnis, ohne gelöschte, und auch ohne Anmeldung. Der Verwalter
aus der Zeit vor v3.2 zählt erst, wenn er eingetragen ist.

### Zuständig an Zählern und Verträgen *(v3.7.0)*

Zähler und Verträge nehmen optional `assignee_id` an — eine Person aus dem
[Verzeichnis](#personenverzeichnis-v360), keine freien Namen. Additiv, kein
Schemaschritt; ein `PATCH` ohne das Feld ändert nichts.

| Weg | Was |
|---|---|
| `POST`/`PATCH /api/utility/{u}/meters…`, `…/contracts…` | `assignee_id`: Personen-ID; `null` oder leer = niemand. Unbekannt, gelöscht oder keine ID → `400` `errors.assignee.unknown`. Die bisherige Person darf unverändert stehen bleiben, auch wenn sie inzwischen gelöscht ist |
| `GET` (Liste, einzeln) und die Antworten von `POST`/`PATCH` | dazu `assignee` (Name aus dem Verzeichnis), `assignee_former`, `assignee_deleted` — immer alle vier; eine unbekannte ID zeigt niemanden |
| `POST`/`PATCH /api/reminders…` | `utility` und `meter_id`: der Zähler des Termins (muss es geben, sonst `400` `errors.reminder.meterUnknown`); `meter_id: null` löst ihn |
| `GET /api/reminders` | `assignee_inherited`: `{assignee, assignee_id, assignee_former, assignee_deleted, assignee_source: "meter"}` oder `null` — nur, wenn der Termin keine eigene Zuständigkeit hat |
| `GET /api/agenda` | je Ereignis die geerbte Zuständigkeit und `assignee_source` ([Agenda](#get-apiagendadays90-v310)) |
| `GET /api/calendar.ics` | geerbt mit Zusatz in `DESCRIPTION`, `?person=<id>` ([Kalender](#get-apicalendarics-v310)) |
| `GET /api/users/{id}/handover` | dazu `meters`, `contracts`: wie viele mit übergehen |
| `DELETE /api/users/{id}` | Zähler und Verträge an die löschende Person (`records_handed_over`); ohne sie bleibt die ID stehen (`records_kept`, „Name (gelöscht)“), mit `?anonymize_activity=1` ersetzt `assignee_former: true` die ID |

Home Assistant: `POST /api/ingest` und `GET /api/summary` bleiben unverändert.

### Personenverzeichnis *(v3.6.0)*

Jede Person bekommt eine **fortlaufende Nummer** `no`, die nie wieder
vergeben wird; gelöschte Personen bleiben im Verzeichnis vermerkt
(`data/people.json`, Topf `people` im Backup, ohne Passwörter —
[Datenmodell](datenmodell.md#personenverzeichnis-peoplejson-v360)). Die ID
bleibt die des Zugangs (`u_…`). Bestehende Zugänge bekommen ihre Nummer beim
ersten Lesen. Ein neuer Zugang mit dem Namen einer Person ohne Zugang (etwa
aus einem Backup) übernimmt deren ID und Nummer.

**Personen ohne Zugang** (aus Backup oder Beispielhaushalt) liefert
`GET /api/users` hinter den Zugängen mit `has_account: false` und
`source: "none"`. `GET …/handover` und `DELETE /api/users/{id}` gelten auch
für sie (nur das Verzeichnis, `auth.json` bleibt unberührt), ebenso
`PATCH /api/users/{id}` mit `{name?, role?}` — dieselben
Regeln wie bei einem Zugang (Name 1–40 Zeichen, `400` `errors.users.nameInvalid`;
eindeutig unter allen Personen und Zugängen, Groß/klein egal, sonst `409`
`errors.users.nameTaken` bzw. `errors.users.nameTakenPerson`, wenn eine Person
ohne Zugang den Namen trägt; Rolle `admin` oder `member`, sonst `400`
`errors.users.roleInvalid`), ein Passwort `400` `errors.users.noAccountPassword`
(dafür einen Zugang anlegen). Antwort wie ein Eintrag in `GET /api/users`
(`has_account: false`). Offene Termine zeigen danach den neuen Namen (sie lesen
ihn über `assignee_id`); erledigte Termine und der Verlauf behalten den Namen
vom Erledigen. Eine gelöschte Person lässt sich nicht mehr ändern (`404`).

**Namen sind über alle Personen eindeutig** (Zugänge und Personen ohne Zugang;
gelöschte zählen nicht). Benennt `PATCH /api/users/{id}` einen Zugang auf den
Namen einer Person ohne Zugang um, antwortet die App `409`
`errors.users.nameTakenPerson`. `POST /api/users` mit diesem Namen ist kein
Konflikt: Der neue Zugang übernimmt ID und Nummer der Person (wie bisher).
Ohne Anmeldung sind alle `/api/users`-Routen erreichbar wie jede
Einstellung; `to` ist dann `null`, offene Termine bleiben mit
`assignee_deleted: true`.

`GET /api/users/{id}/handover` (nur Verwalter) für den Dialog:

```json
{ "person": { "id": "u_8b0d4f17", "name": "Kim", "no": 2, "has_account": true },
  "to": { "id": "u_3f9a1c2e", "name": "Alex" },
  "open": 1, "done": 3 }
```

`open` = offene Termine, die übergehen; `done` = Einträge im Verlauf, in
denen die Person vorkommt; `to` = die anfragende Person (mit einem
API-Schlüssel `null`). `DELETE /api/users/{id}` antwortet zusätzlich mit
`handed_over` (übergebene Termine), `handed_over_to` (`{id, name}` oder
`null`) und `kept` (Termine, die mit Vermerk stehen bleiben). Unbekannte
Person `404` `errors.users.notFound`; ein Mitglied `403`
`errors.auth.adminOnly`.

### Belege und Texterkennung *(v3.1.0)*

Belege sind Dateien zu einem Datensatz: das Foto eines Zählerstands, seit
v3.1.0 auch die Verbrauchsinfo zu einem Zeitraum (`attachment_id`) und PDF
oder Foto einer Nebenkostenabrechnung (`attachment_ids`) und einer
Versorgerrechnung (`attachment_ids`, v3.1.0). Die Dateien liegen unter
`data/attachments/<id>.<ext>`, der Index im Topf `attachments.json`
([Datenmodell](datenmodell.md#belege-v310)). Ausgeliefert werden sie nur über
die API — das Datenverzeichnis ist für den Webserver gesperrt.

**Hochladen — `POST /api/attachments?kind=reading_photo`.** Die Datei kommt als
**roher Body**, nicht als `multipart/form-data`. `kind` ∈ `reading_photo`,
`bill_pdf`, `statement_pdf`, `other` (ein unbekannter Wert wird `other`);
`&name=` merkt sich den ursprünglichen Dateinamen (`original_name`, höchstens
120 Zeichen).

| Prüfung | Regel | Fehler (`400`) |
|---|---|---|
| Inhalt | JPEG, PNG, WebP oder PDF — erkannt an den ersten Bytes, nicht an Endung oder `Content-Type`. SVG, HTML und alles andere wird abgelehnt | `errors.attachment.type` |
| Größe | Foto höchstens 3 MB, PDF höchstens 10 MB. Ist der Upload größer als `post_max_size` von PHP, kommt der Body leer an; auch dann `errors.attachment.size`, mit dieser Grenze in der Meldung | `errors.attachment.size` |
| Speicher | alle Belege zusammen höchstens `attachments_max_mb` (Standard 500 MB) | `errors.attachment.quota` |

Antwort `201` mit dem Indexeintrag:

```json
{ "success": true, "data": {
  "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "mime": "image/jpeg",
  "size": 284113, "sha256": "9c1e…", "created_at": "2026-10-07T08:12:40+02:00",
  "ref": null } }
```

Über die API nimmt der Server ein Foto, wie es kommt. Verkleinern und das
Entfernen der EXIF-Daten (samt GPS-Ort) übernimmt nur die Oberfläche im
Browser, bevor sie hochlädt.

`ref` bleibt `null`, bis ein Datensatz den Beleg verknüpft — bei einer Ablesung
über `attachment_id` ([s. o.](#ablesungen-client_ref-attachment_id-v310-additiv)),
`ref.type` dann `reading`; bei einem Zeitraum `period`, bei einer
Nebenkostenabrechnung `tenancy_statement`, bei einer Versorgerrechnung `bill`
(v3.1.0).
Ein Beleg ohne Verweis ist verwaist: hochgeladen und nie gespeichert, oder seine
Ablesung wurde gelöscht bzw. das Foto gelöst. Nach **24 Stunden** räumt die App
ihn auf, beim nächsten Hochladen und beim Rotieren der Snapshots; bis dahin
lässt er sich wieder verknüpfen. Dateien in `data/attachments/` ohne
Indexeintrag verschwinden ebenso nach 24 Stunden.

**Abrufen — `GET /api/attachments/{id}`.** Liefert die Datei selbst, keine
JSON-Hülle: `Content-Type` aus dem Index, `Content-Disposition: inline`,
`Cache-Control: private, max-age=31536000, immutable` (ein Beleg ändert sich
nie — neue Datei, neue ID), `X-Content-Type-Options: nosniff`, bei PDF dazu
`Content-Security-Policy: sandbox`: Ein PDF läuft ohne Skripte und ohne Zugriff
auf die App. Unbekannte ID → `404`.

**Liste — `GET /api/attachments`.**

```json
{ "success": true, "data": {
  "attachments": [ { "id": "att_5f0c2a9e81d34b67", "kind": "reading_photo", "mime": "image/jpeg",
      "size": 284113, "sha256": "9c1e…", "created_at": "2026-10-07T08:12:40+02:00",
      "ref": { "type": "reading", "utility": "strom", "id": "20261007-1a2b3c4d" } } ],
  "usage": { "count": 1, "bytes": 284113, "max_bytes": 524288000 } } }
```

**Löschen — `DELETE /api/attachments/{id}`.** Entfernt Datei und Indexeintrag
sofort und nimmt den Verweis am Datensatz mit (`attachment_id` an der
Ablesung); Antwort `{deleted: true}`.

#### `POST /api/ocr/reading`

```json
{ "attachment_id": "att_5f0c2a9e81d34b67" }
```

Liest den Zählerstand aus einem Ablesefoto über den **eigenen
Texterkennungsdienst im Heimnetz** — Ollama oder ein OpenAI-kompatibler Server
wie LM Studio (Einstellungen `ocr_*`,
[Anleitung](../anleitungen/texterkennung.md)). Die Anfrage an den Dienst stellt
der Server, nie der Browser. Antwort `200`:

```json
{ "success": true, "data": {
  "value": 12345.6, "confidence": 0.92,
  "raw": "{\"value\": 12345.6, \"confidence\": 0.92}",
  "model": "qwen2.5vl", "duration_ms": 23810 } }
```

| Feld | Bedeutung |
|---|---|
| `value` | erkannter Stand oder `null`, wenn das Modell nichts lesen konnte. **Gespeichert wird nichts** — die Oberfläche bietet den Wert zum Übernehmen an |
| `confidence` | Selbsteinschätzung des Modells, 0–1, oder `null` |
| `raw` | Antwort des Modells, höchstens 500 Zeichen — für die Fehlersuche |
| `model`, `duration_ms` | Modell aus `ocr_model` und die Dauer der Anfrage |

Der Prompt ist fest und englisch: nur die Ziffern des Zählwerks,
Nachkommastellen wie auf dem Rollenzählwerk, Antwort als
`{"value": …, "confidence": …}`. Gelesen wird tolerant — JSON auch in
Codezäunen oder mitten im Text, sonst die erste Zahl; „12.345,6“ und „12345,6“
werden zu `12345.6`.

**Schnittstelle (`ocr_api`).** `ollama`: `POST <ocr_endpoint>/api/chat`, das
Bild base64-kodiert in `images[]`. `openai`: `POST <ocr_endpoint>/v1/chat/completions`
(endet die Adresse auf `/v1`, wird nur `/chat/completions` angehängt), das Bild
als Data-URI. Eine schon vollständige Adresse bleibt, wie sie ist.

**Nur im eigenen Netz.** Der Server löst den Namen aus `ocr_endpoint` auf, und
**jede** Adresse muss lokal sein: Loopback (`127.0.0.0/8`, `::1`),
`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10` (Tailscale,
CGNAT) und IPv6-ULA (`fc00::/7`). Link-local (`169.254.0.0/16`, `fe80::/10`)
zählt seit v3.4.0 nicht mehr dazu (dort antworten bei gemieteten Servern die
Metadatendienste der Anbieter), ebenso wenig `fd00:ec2::254`. Die Antwort
darf höchstens 20 MB groß sein.
Geprüft wird bei jedem Aufruf; verbunden wird mit genau der geprüften Adresse
(Schutz gegen DNS-Rebinding), Weiterleitungen werden nicht verfolgt. Einen
Schalter, der das aufhebt, gibt es nicht — ein Cloud-Dienst ist bewusst nicht
vorgesehen.

| Status | `code` | Wann |
|---|---|---|
| `400` | `errors.ocr.off` | `ocr_endpoint` ist leer |
| `400` | `errors.ocr.notLocal` | eine Adresse des Dienstes liegt nicht im eigenen Netz |
| `400` | `errors.attachment.notFound`, `errors.attachment.wrongKind` | Beleg fehlt bzw. ist kein Ablesefoto |
| `502` | `errors.ocr.timeout` | keine Antwort innerhalb von `ocr_timeout_s` |
| `502` | `errors.ocr.unreachable` | Name nicht auflösbar, keine Verbindung oder HTTP-Fehler des Dienstes (etwa ein unbekanntes Modell) |
| `502` | `errors.ocr.badAnswer` | Antwort ohne verwertbaren Inhalt |

### Posteingang *(v3.7.0)*

Versorgerpost als PDF ablegen, auslesen lassen, prüfen und übernehmen
([Anleitung](../anleitungen/posteingang.md), Topf `inbox.json` im
[Datenmodell](datenmodell.md#posteingang-v370)).

**Ablegen — `POST /api/inbox?name=…`.** Das PDF kommt als roher Body wie ein
Beleg (bis 10 MB, erkannt an den ersten Bytes; anderes → `400 errors.inbox.pdfOnly`).
Gespeichert wird es im Belegspeicher (Art `inbox_pdf`, Verweis
`{type: "inbox", id}`). Liegt dieselbe Datei (gleiche SHA-256) schon im
Posteingang: `409 errors.inbox.duplicate`, die Meldung nennt den Tag, und
`detail.existing` ist der vorhandene Eintrag. Antwort `201` mit dem Eintrag.
*(Paket betrieb)* Ohne `name` heißt das Dokument „Dokument JJJJ-MM-TT HH.MM.pdf“
(in der Sprache der Installation). Statt des rohen Körpers geht auch JSON
`{"name": "…", "pdf_base64": "…"}` — so reicht Home Assistant Anhänge aus der
IMAP-Integration weiter; Zeilenumbrüche im Base64 sind erlaubt, ungültiges
Base64 → `400 errors.inbox.base64Invalid`. Mit eingeschalteter Anmeldung genügt
ein API-Schlüssel mit dem Bereich **`inbox`** („nur Posteingang“): Er darf genau
diese Route und sonst keine (`401`); ein Lese-Schlüssel bekommt `403
errors.auth.readOnlyKey`. Beispiel:

```sh
curl -X POST -H "Authorization: Bearer etk_…" -H "Content-Type: application/pdf" \
  --data-binary @Abrechnung.pdf "http://DEINE-ENERGIETRACKER-IP:8080/api.php/api/inbox?name=Abrechnung.pdf"
```

**Liste — `GET /api/inbox`.** `entries` (neueste zuerst: `id`,
`attachment_id`, `name`, `status`, `type`, `flags`, `queued_at`, `created_at`,
`applied`, `error`, *(Paket betrieb)* `gist`, `meter_id`, `contract_id`,
*(v3.7.0)* `duplicate_of` — das ältere inhaltsgleiche Dokument
oder `null`), `folder` (`dir`, `present`, `pending` — der überwachte Ordner),
`queue` (`counts` je Status, `queued`, `next`) und
`provider` (`id`, `label`, `local`, `available`, `model`, `destination`,
`notice`, `pdf`, `recommended_model`). `available` heißt: Der Anbieter sendet
wirklich — lokal mit eingetragener Adresse, in der Cloud eingeschaltet und mit
Schlüssel. `destination` (Ziel-Host) und `notice` (was hinausgeht, in der
Sprache der Anfrage) liefern die Cloud-Anschlüsse; die Oberfläche zeigt beides
im Dialog „Dieses Dokument geht an …“, beim lokalen Anbieter sind sie `null`.

**Vorschau — `GET /api/inbox/{id}`.** `entry` (ohne Zwischenspeicher),
`assignment` (gespeichert oder vorbelegt: `utility`, `meter_id`,
`contract_id`), `assignment_saved`, `suggested` (`by`: `meter_number`,
`market_location`, `contract_number`, `utility`, `single_meter`, `date`),
`proposals`, `schema` (Art je Feld), `review_all`, `recognize_only`,
*(Paket betrieb)* `gist`, *(v3.7.0)* `duplicates`
(`[{id, name, created_at, status}]`: Dokumente gleicher Art mit demselben
Zeitraum bzw. Stichtag und denselben Nummern und Beträgen — in einer anderen
Datei; gelöscht wird nichts).

`by: date` heißt *seit v3.7.0*: der Vertrag **desselben Versorgers** (Name ohne
Groß/klein, Satzzeichen und Rechtsform) am Stichtag, bei einer Rechnung der,
der ihren Zeitraum überwiegend abrechnet. Den Vertrag eines anderen Versorgers
belegt die Vorschau nicht vor; ohne Versorger im Dokument auch keinen.

**Kernaussage — `gist`** (Vorschau frisch gerechnet; in der Liste die nach dem
letzten Schreiben gespeicherte, Feld `gist` am Eintrag in `inbox.json`):

```json
{ "type": "price_change", "key": "inbox.gist.priceChange.more",
  "params": { "date": "2026-04-01", "provider": "Stadtwerke Musterstadt", "amount": 52.4 },
  "figure": { "kind": "money_year", "value": 52.4, "tone": "bad", "signed": true },
  "action": { "kind": "apply", "key": "inbox.gist.action.applyPrices" },
  "details": [{ "key": "inbox.gist.detail.termination", "params": { "date": "2026-03-31" } }],
  "computed_at": "2026-10-21T10:00:00+02:00" }
```

`figure.kind`: `money_year`, `money_month`, `money`, `result` (positiv =
Nachzahlung), `date`, `price_ct`; `tone`: `good`, `warn`, `bad`, `neutral`.
`action.kind`: `apply` (die angehakten Vorschläge übernehmen), `link` (`href`
in der App), `remind` (Termin mit `due` anlegen, `POST …/remind`), `file`
(ablegen). Texte sind Katalogschlüssel mit rohen Werten (Tage ISO, Beträge als
Zahl). Eine Rechnung trägt nach der Übernahme `check` (`verdict`, `delta_eur`,
`reasons`, `bill_id`) aus „Rechnung prüfen“; bei `verdict: no_prices`
lautet der Satz `inbox.gist.invoice.noPrices`, die Details
nennen die Abschnitte ohne Preis, der Knopf führt zu den Verträgen
(`inbox.gist.action.addPrices`). Weicht die Menge ab und fehlen zugleich
Preise (`verdict: check`, `price_missing_at` nicht leer), lautet er
`inbox.gist.invoice.offQuantityNoPrices` mit `pct` (Abweichung der Menge),
die Details nennen ebenso die Abschnitte ohne Preis, der Knopf öffnet „Rechnung
prüfen“. Offene Punkte der Vorschläge
stehen als Details `inbox.note.missing` bzw. `inbox.note.unsure` mit `fields`;
`duplicate_of` nennt ein inhaltsgleiches Dokument (ist nichts mehr zu
übernehmen, ist `action` „ablegen“). Ohne Art oder Auslesung `null`.

Ein Feld der Auslesung (`entry.extraction.fields.<name>`):

```json
{ "value": 1235.6, "raw": "1.235,6", "quote": "Zählerstand neu am 31.12.2025: 1.235,6 m³",
  "page": 1, "status": "ok", "reasons": [] }
```

`status` ist `ok`, `check` (prüfen) oder `empty`. Gründe in `reasons`:
`empty` (leer, aber ein Vorschlag braucht das Feld — *seit v3.7.0* je
Vorschlag, nicht je Dokumentart), `no_quote`, `quote_not_found` (das Zitat steht nach
Normalisierung von Leerraum, Silbentrennung und Groß/klein nicht im Text),
`value_not_in_quote`, `unreadable`, `estimated` („voraussichtlich“),
`mandatory` (Zählerwechsel, Zählerstandsanforderung, Abschlagsänderung —
*(v3.7.0)* ohne `document_date` und `document_title`, außer ein Vorschlag
braucht sie),
`price_from_invoice`, `sum_mismatch` (Summe der Abschläge), `inconsistent`
(Ergebnis ≠ Gesamtbetrag − Abschläge; *(v3.7.0)* markiert sind dann alle
beteiligten Felder: Ergebnis, Gesamtbetrag und die Summe der Abschläge, ohne
Summe jeder Abschlag), *(v3.7.0)* `invalid_format`
(Marktlokations-ID nicht in ihrer Form: 11 Ziffern, die erste 1–9, Prüfziffer
nach der BDEW-Anwendungshilfe „Identifikatoren in der Marktkommunikation“,
Kap. 3 und 8.1; Zählernummer mit Sternchen im Wert oder im Zitat, etwa ein
Kürzel „*EMH“ davor — was zur Nummer gehört, bliebe offen; beim Abgleich mit
den Geräten zählt ein Sternchen nicht). Listenfelder (`advances_paid`,
`gas_factors`, `working_prices`) tragen `items` mit je eigenem Zitat; die
Summe der Abschläge bildet der Server (`sum`).

*(v3.7.0)* Diese Gründe markieren nicht, sie erklären — der
`status` bleibt `ok` bzw. `empty`:

| Grund | Bedeutung |
|---|---|
| `missing` | fehlt im Dokument, kein Vorschlag braucht es (`status: empty`) |
| `estimated` | „voraussichtlich“: übernommen als Hinweis, der Vorschlag bleibt angehakt (bis v3.6 eine Marke) |
| `supported` | ein Auswahlfeld ohne gültiges Zitat, das andere Angaben stützen: Zählernummer, Marktlokation oder Vertragsnummer am zugeordneten Zähler bzw. Vertrag, Einheiten und Stichwörter der übrigen Zitate, bei der Rechnungsart der neue Abschlag, beim Ergebnis Betrag gegen Abschläge |
| `price_matches` | Preis aus der Rechnung = Preiszeile des zugeordneten Vertrags am selben Tag (auf Rundung genau); der Vorschlag ist `exists` — ein Nettopreis zählt dabei brutto |
| `net_price` | der Preis steht netto da, Steuersatz gelesen: umgerechnet in brutto (die App führt Bruttopreise); der Eintrag trägt `net: {vat_pct, gross}`, der Vorschlag den Bruttopreis |
| `vat_changed` | gleicher Nettopreis wie im Vertrag, nur ein anderer Steuersatz: keine Marke, eine neue Preiszeile (brutto) ab dem Tag, für den die Rechnung den Preis ausweist |

*(v3.7.0)* **Netto und Steuersatz.** Ein Preis der Rechnung gilt als netto,
wenn der Satz im Zitat steht (gelesen, nie vermutet — auch nicht nach
Verbrauchsart oder Datum; nur aus einem Zitat, das dasteht —
bei einem Zitat mit „…“ nur aus den Teilen, die dastehen) und netto ausgewiesen ist: vom Modell (`basis: net`
am Arbeitspreis, `base_price_basis` am Grundpreis) oder im Zitat („netto“,
„zzgl.“), ohne „brutto“/„inkl.“ daneben. Nennt das Modell einen Satz
(`vat_pct`, `base_price_vat_pct`), muss genau er im Zitat stehen. Steht im
Zitat kein Satz, gilt einer, den der Server beim Auslesen in der Textebene
liest — am Eintrag bzw. Feld als `vat_read: {vat_pct, from}`: `from: row`, wenn
das Modell netto/brutto **und** einen Satz nennt und genau dieser Satz mit „%“
als einziger in der Textzeile steht, in der das ganze Zitat steht (eine Zeile
tiefer nicht); `from: column`,
wenn die Zeile genau eine Zahl ohne „%“ trägt, die ein üblicher Satz ist (0, 5,
7, 16 oder 19 — § 12 Abs. 1–3 und § 28 Abs. 1, 2 und 5 UStG), nicht der Preis
selbst, und darüber auf derselben Seite der Kopf einer Steuerspalte steht
(„USt %“, „MwSt.-Satz in %“); `from: line`, wenn die Rechnung in einer eigenen
Zeile genau einen Satz ausweist („Umsatzsteuer 19 % auf …“, „zzgl. 19 % MwSt.“).
Stehen dort zwei Sätze (etwa 7 % und 19 %), gilt keiner — welche Preiszeile
welchen trägt, wäre geraten. Sonst bleibt
der Preis, wie er gedruckt ist, und die Marke wie bisher. „Anderer Steuersatz“
braucht den Satz des Vertragspreises: aus einem Dokument am Vertrag
(Vertragsbestätigung oder Preisänderung aus dem Posteingang, Satz im Zitat des
Preises oder im Feld `vat_pct`) oder aus derselben Rechnung. Saldo und Prognose
rechnen weiter mit den Bruttopreisen des Vertrags. Hilfsfelder (`basis`,
`vat_pct`, `base_price_basis`, `base_price_vat_pct`, `vat_pct` der Bestätigung)
markieren nie. Die Auslesung trägt `schema_version` **5**; an dieser Version
hängt der Zwischenspeicher. Ein Dokument, das mit einer älteren Version
ausgelesen ist, behält seine Auslesung, bis du es neu ausliest: Netto erkennt
die App dort aus dem Zitat, nach den neuen Feldern fragt erst das neue Auslesen
das Modell. Ein neues Auslesen ohne `fresh` mit derselben Version trifft den
Zwischenspeicher: Das Modell wird nicht gefragt, die Prüfung läuft mit der
mitgeschickten Textebene neu.

*(v3.7.0, `schema_version` 3)* **Datum, Titel, Stände je Zähler.** Jede
ausgelesene Art trägt `document_date` (Brief- bzw. Rechnungsdatum) und
`document_title` (Überschrift, etwa „Rechnungskorrektur“, auch aus dem
Seitenkopf) — geprüft wie jedes Feld (das Datum steht im Zitat), ohne Marke,
wenn sie fehlen (kein Vorschlag braucht sie). Die Rechnung trägt zusätzlich
`meter_readings` (`items` je Zähler: `meter_number`, `reading_old`,
`reading_old_date`, `reading_new`, `reading_new_date`, je Eintrag mit eigenem
Zitat); die Einzelfelder `meter_number`, `reading_old` … bleiben und werden
weiter gefüllt. Aus `meter_readings` entstehen Ablesungen am zugeordneten
Zähler (nach Zählernummer, ohne Doppel zu den Einzelfeldern). Steht genau ein
Eintrag mit fremder Nummer neben genau einem des Zählers, schlägt die App einen
Zählertausch vor (`meter_change`, `label_key`
`inbox.proposal.meterSwapInvoice`, Endstand alt und Anfangsstand neu, Tauschtag
= Datum des Anfangsstands). Vorbelegt ist er nur, wenn alles zusammenpasst: Der
Eintrag des Zählers trägt die Nummer des Geräts, das bis dahin lief; Ausbau am
selben Tag oder am Vortag; die Stände steigen; kein bekannter Stand des alten
Geräts liegt über dem Endstand, keiner ab dem Tauschtag; nichts markiert. Die
Stände am Tauschtag trägt der Tausch, nicht eine Ablesung. Dieselbe Nummer
zweimal mit zwei Ständen am selben Tag (zwei Zählwerke) oder ein fremder Zähler
ohne Endstand des alten ergeben keinen Vorschlag.

*(v3.7.0, `schema_version` 4)* **Zeiträume je Zähler, Nummer in der
Kopfzeile.** Einträge derselben Nummer (je Ablesezeitraum einer) fasst der
Server zu einem zusammen: frühester Anfangsstand, spätester Endstand, die Form
des Eintrags bleibt. Die Stände dazwischen stehen am Eintrag als `between:
[{date, counter, estimated}]`, dazu `merged` (Zahl der Zeiträume) und
`estimated_ends` (Ablese-Art von Anfangs- und Endstand); jeder Zwischenstand
wird eine Ablesung wie der Endstand. Fällt ein Stand mit dem Tag, ist der
Eintrag markiert (`inconsistent`). Steht die Nummer nicht im Zitat, sondern in
der Kopfzeile direkt über den Standzeilen, gilt sie als belegt
(`number_from: header`); die Kopfzeile eines anderen Zählers belegt nichts.
Die Stände des neuen Zählers sind nur vorbelegt, wenn der Tausch es ist — sonst
landeten sie am alten Gerät. Stehen mehrere Nummern auf der Rechnung, ist ein
Stand der Einzelfelder nur vorbelegt, wenn ein vorbelegbarer Eintrag ihn an
diesem Gerät trägt.

*(v3.7.0, `schema_version` 3)* **Geschätzte Zählerstände.** Jeder Stand der
Rechnung trägt seine Ablese-Art: `reading_new_estimated`,
`reading_old_estimated` (bool, Hilfsfelder ohne Marke) und `estimated` je
Eintrag von `meter_readings` (gilt für beide Stände des Eintrags). Geschätzt ist
ein Stand nur, wenn seine Belegstelle es sagt — mit einem Wort („geschätzt“,
„Schätzung“, „rechnerisch ermittelt“, *(Version 4)* „hochgerechnet“) oder einem Ablesecode, den das Dokument
selbst als Schätzung erklärt („S = geschätzt“, „R: rechnerisch ermittelt“); ein
Code ohne diese Erklärung zählt nicht, die Erklärung allein auch nicht.
*(Version 4)* Ebenso ein Fußnotenzeichen („5)“, „*“), das das Dokument als
Schätzung erklärt („5) hochgerechnet“), ein Code in einer Codegruppe
(„10/12/26“ mit „12 = geschätzt“) und eine Zahl unter einer Spalte
„Ableseart“ oder „Ablesegrund“, wenn das Dokument sie erklärt; was in der
Zeile direkt hinter dem Zitat steht, gehört dazu.
`estimated_by` (`word`/`code`/`mark`) sagt, woran. Der Ablesungsvorschlag trägt dann
`is_estimated: true` in `data` (`label_key` `inbox.proposal.readingEstimated`)
und legt die Ablesung als geschätzt an — die Monatskennung zählt sie nicht als
Messung.

*(v3.7.0)* **Belegstelle aus mehreren Stellen.** Ein Zitat mit „…“ (oder
„...“) gilt als gefunden, wenn seine Teile in dieser Reihenfolge auf derselben
Seite stehen, jeder in derselben Textzeile wie der vorige oder höchstens 2
Textzeilen unter dem vorigen — Bezeichnung und Wert stehen in derselben Zeile
oder in der darunter, auch in einer breiten Tabellenzeile (gezählt nach
Zeilen, nicht nach Zeichen); der Wert muss in einem Teil stehen. Ohne
„…“ darf ein Zitat in zwei Teile zerfallen (Spaltenkopf und Zeile), wenn beide
auf derselben Seite stehen, der erste vor dem zweiten und höchstens 2
Textzeilen darüber, jeder mindestens vier Zeichen lang, und der Wert im zweiten
steht, nicht im ersten. Umlaute als zwei Zeichen in der Textebene („Ã¤“ oder
„√§“ statt „ä“, Buchstabe + Trema) und Mac-Roman-Bytes, als Windows-1252
gelesen („Š“ statt „ä“, „š“ statt „ö“, „Ÿ“ statt „ü“), gleicht der Vergleich aus. Belegstellen prüft der Server beim
Auslesen gegen die Textebene, die er nicht aufbewahrt — eine ältere Auslesung
profitiert davon erst beim neuen Auslesen.

Die Marken bestimmt der Server nach dem heutigen Bestand: Vorschau, Liste
(`flags`) und Prüfliste rechnen bei jedem Abruf, auch für Dokumente, die eine
ältere Version ausgelesen hat. Gespeichert werden sie beim Auslesen, Ändern
und Übernehmen — ein `PATCH /api/inbox/{id}` mit leerem Körper schreibt sie
nach `inbox.json`. `flags` zählt, was noch zu prüfen ist (ohne Bestätigtes).

Ein Vorschlag:

```json
{ "id": "p_3f2a9c1e0b7d", "kind": "reading", "label_key": "inbox.proposal.reading",
  "params": { "date": "2025-12-31", "counter": 1235.6, "meter": "Hauptzähler Gas" },
  "data": { "meter_id": "m_gas_main", "date": "2025-12-31", "counter": 1235.6 },
  "sources": ["reading_new", "reading_new_date"], "status": "new", "detail": null,
  "check": false, "checked": true }
```

`kind`: `reading`, `bill`, `bill_book` (Ergebnis als Sonderzahlung),
`contract_create`, `contract_end`, `contract_start`, `contract_price`,
`contract_base_price`, `contract_advance`, `contract_ids`, `meter_ids`
(Marktlokation), `meter_change`, `gas_factor` (Stichtag der Gas-Umrechnung),
`reminder`. `status`: `new`, `exists` (wird übersprungen), `conflict`
(überschreibt nur angehakt), `overlap`. `checked` ist die Vorbelegung: neu und
ohne Marke. Die `id` hängt an Art und Inhalt.

*(v3.7.0, additiv)* `core` — die Felder, deren Zweifel den
Vorschlag markieren (fehlt es, gilt `sources`); `reasons` — `[{field,
reasons}]`, warum `check` gilt; `hints` — `[{field, reason}]` ohne Marke
(`estimated`); `open` — `[{field, reason}]` mit `missing` (fehlt im Dokument)
oder `unsure` (markiert und deshalb **nicht** übernommen, etwa der Abschlag
einer Vertragsbestätigung: Der Vertrag entsteht ohne ihn). Neu als Angebot
(nicht vorbelegt): `contract_create` mit `label_key`
`inbox.proposal.contractFromInvoice` (Rechnung mit Zeitraum und Versorger ohne
Vertrag dieses Versorgers; eine Schlussrechnung endet mit ihrem Zeitraum) und
`inbox.proposal.contractFromSupplyStart` (Lieferbeginn ohne Vertrag). Ohne
Vertrag dieses Versorgers ist `bill_book` nur ein Angebot (`open` nennt
`contract`); übernommen bucht es nur an einen Vertrag des Versorgers, der den
Zeitraum abrechnet, sonst `errors.bill.noContract`. Der neue Abschlag einer
Rechnung geht nur an einen Folgevertrag desselben Versorgers.

**Ändern — `PATCH /api/inbox/{id}`** mit `assignment`, `edits`
(`{feld: wert}` bestätigt oder korrigiert; `null` nimmt zurück), `type`
(andere Art: Auslesung verworfen), `status` (`filed` ablegen, `new` zurück)
oder `note`. Antwort wie die Vorschau.

**Auslesen — `POST /api/inbox/{id}/classify`, dann `…/extract`** mit
`{pages: [Text je Seite], consent?, fresh?}`. Die Textebene liest der Browser
(pdf.js). `classify` erkennt die Art, `extract` liest die Felder dieser Art und
prüft sie; ohne Art läuft `extract` beides. Zwischengespeichert je Anbieter,
Modell und Schema-Version (`fresh: true` fragt neu). Gesperrt wird erst nach der
Antwort des Diensts. Ein Anbieter außer `local` verlangt `consent: true`,
sonst `409 errors.inbox.consentRequired` mit dem Namen des Diensts.

| Status | `code` | Wann |
|---|---|---|
| `400` | `errors.inbox.off` | kein Dienst eingetragen (`ocr_endpoint` leer) |
| `400` | `errors.ocr.notLocal` | eine Adresse des Diensts liegt nicht im Heimnetz |
| `400` | `errors.inbox.noText` | keine Textebene (Scan) und ein Anbieter, der nur Text nimmt |
| `400` | `errors.inbox.pagesInvalid`, `errors.inbox.providerUnknown` | Textebene unlesbar bzw. Anbieter nicht angemeldet |
| `409` | `errors.inbox.consentRequired` | Anbieter außerhalb des Heimnetzes ohne Zustimmung |
| `502` | `errors.ocr.timeout`, `errors.ocr.unreachable`, `errors.inbox.badAnswer`, `errors.inbox.modelMissing` | der Dienst antwortet nicht, nicht verwertbar, oder das Modell fehlt |
| `502` | `errors.inbox.truncated` *(v3.7.0)* | die Antwort brach am Kontextfenster ab (`done_reason` bzw. `finish_reason`: `length`), auch beim zweiten Versuch mit größerem Fenster und doppeltem Antwortbudget; nennt Budget und Fenster |

Liest ein Cloud-Dienst aus, kommen dessen Meldungen dazu
([unten](#get-apiinboxcloud--patch-apiinboxcloud-v370), „Beim Auslesen“).

**Übernehmen — `POST /api/inbox/{id}/apply`** mit `{proposals: [id, …]}`. Nur
diese, über dieselben Dienste wie die Handeingabe. Eine unbekannte ID →
`400 errors.inbox.proposalStale` (nichts geschieht). Antwort: `applied`,
`skipped` (vorhanden), `failed` (Fehler je Vorschlag), `entry`, `proposals`.

**Warteschlange — `POST /api/inbox/queue`** (`{ids?}`; ohne alle neuen und
fehlgeschlagenen) und **`DELETE /api/inbox/queue`** (Vorgemerktes wieder neu).
Abgearbeitet wird von der geöffneten Seite, Dokument für Dokument.

**Prüfliste — `GET /api/inbox/review`.** Nur die markierten Felder aller
ausgelesenen, noch nicht übernommenen Dokumente: `entry_id`, `name`, `type`,
`field`, `item`, `value`, `quote`, `page`, `reasons`.

#### Haushalt aus Dokumenten *(v3.7.0)*

Ein Entwurf über alle ausgelesenen, noch nicht übernommenen Dokumente
([Anleitung](../anleitungen/haushalt-aus-dokumenten.md)). Er arbeitet nur mit
den gespeicherten Auslesungen (`extraction`, dazu `edits`) und fragt keinen
Dienst. Schreiben darf, wer ein einzelnes Dokument übernehmen darf.

**Entwurf — `GET /api/inbox/draft`** (`?documents=inb_…,inb_…` nimmt nur diese).
Antwort: `summary` (`documents`, `used`, `duplicates`, `without`, `meters`,
`meter_changes`, `contracts`, `prices`, `advances`, `readings`, `bills`,
`reminders`, `questions`), `utilities` (Reihenfolge der Abschnitte), `items`,
`documents` (je Dokument `state`: `used`, `duplicate` mit `duplicate_of` —
dasselbe ältere Dokument wie `entries[].duplicate_of` in `GET /api/inbox`,
auch wenn es schon übernommen ist —, `no_utility`, `no_meter`, `no_start`,
`meter_choice` *(3.7.1)* — Zähler offen, siehe `missing` —, `nothing`; dazu
`items`), `findings` (`start_near` *(3.7.1)* — Vertrag im Bestand mit Beginn
bis 7 Tage daneben, `{provider, from, date}`: Beginn laut Dokument und im
Bestand —, `gap`, `overlap`, `falling`,
`chain_mismatch`, `duplicate`, `no_meter`, `same_supplier` — Rechnungen in
anderer Schreibweise am Zähler und zur Zeit eines Vertrags mit gemeinsamem
Namen —, `invoice_wp`/`invoice_bp` — Rechnungspreis weicht von Bestätigung
oder Preisänderung ab, `{name, date, invoice, chain}` —, `start_from_invoice`
— Beginn auf die erste Rechnung desselben Vertrags vorgezogen —,
`bill_corrected` — Rechnungskorrektur, die spätere Fassung gilt — mit `params`
und `docs`), `missing` (`advance`, `prices`, `working_price`, `base_price`,
`prices_unconfirmed` — Preise nur auf Rechnungen, noch zu bestätigen; je mit
`which`: `wp`, `bp`, was nach dem Übernehmen des Vorbelegten fehlt —,
`working_price_gap`/`base_price_gap` — ein Abschnitt ohne Preis, `params`
`{provider, from, to}` —, `meter_choice` *(3.7.1)* — Nummer oder
Marktlokation ohne Zähler im Bestand, und mehrere Zähler mit Daten kommen in
Frage, der eine hat schon eine andere Messstelle oder passt nur halb; `params`
`{meter, count, meters}`, `docs`, `href` zum ersten Dokument (dort den Zähler
wählen; eine gespeicherte Zuordnung gilt im nächsten Entwurf) —, `water_prices`, `contract_start`, `old_final`
(Zählertausch ohne Endstand des alten Zählers: erschlossen wird er nur aus einem
Stand höchstens 7 Tage davor), `open` — die offenen Punkte der
Vorschläge, `open: [{field, reason: missing|unsure}]`, eine Zeile je Vertrag
bzw. Lieferverhältnis mit ihren Dokumenten in `docs`, soweit der Entwurf sie
nicht aus einem anderen Dokument hat — mit `href`), `targets`
und `queue` (wie bei `GET /api/inbox`).

Ein Eintrag ist eine Feldgruppe mit einem Haken:

```json
{ "id": "d_5c1e0a9b3f27", "kind": "contract", "utility": "strom", "status": "new",
  "check": false, "checked": true, "actionable": true, "optional": false,
  "label_key": "inboxDraft.item.contract.new",
  "params": { "provider": "Nordlicht Energie GmbH", "from": "2025-04-01", "to": null },
  "fields": [
    { "name": "start", "kind": "date", "value": "2025-04-01", "core": true, "basis": "supply_start",
      "check": false, "reasons": [], "status": "new",
      "sources": [{ "doc": "inb_…", "name": "lieferbeginn.pdf", "field": "supply_start", "quote": "…", "page": 1 }] },
    { "name": "tariff_name", "kind": "text", "value": "Basis 12", "check": true, "reasons": ["differs"],
      "options": [{ "value": "Basis 12", "sources": [] }, { "value": "Basis 24", "sources": [] }] }
  ],
  "docs": ["inb_…"] }
```

`kind` in der Reihenfolge der Übernahme: `meter` (`action`: `create`, `adopt` —
der leere Standardzähler —, `existing`; *(3.7.1)* auch der eine aktive Zähler
mit Daten ohne Nummer und Marktlokation, wenn nichts widerspricht; das Feld
`serial` trägt dann `device_id`, das Gerät, das in der Zeit der Nummer lief), `meter_change`, `contract`,
`contract_end` (bisheriger Vertrag, nur als Angebot), `prices` (Felder
`wp:<Datum>`, `bp:<Datum>` je Monat), `advances` (`ap:<Datum>`), `readings`
(`r:<Datum>`), `bill` (mit Buchung des Ergebnisses), `gas_factor`
(`gf:<Datum>`), `reminder`. `status` des Eintrags: `new`, `update` (vorhanden,
wird ergänzt), `exists`, `conflict`, `overlap`; `update` und `overlap` sind
*(3.7.1)* immer `check` mit Grund `existing_update` bzw. `existing_overlap` und
nie `checked` (vorbelegt ist nur, was den Bestand nicht berührt); ein Vertrag
am vorhandenen Zähler gilt als vorhanden bei gleichem Beginn oder — gleicher
Versorger oder gleiche Vertragsnummer — bei einem Beginn bis 7 Tage daneben
(`start_near`, der Beginn des Bestands bleibt); Verträge der Gruppe des
Zählers zählen mit. *(3.9.0)* Abgeglichen wird der endgültige Beginn, auch
nach dem Vorziehen auf die erste Rechnung (`invoice_earlier`) und für einen
Vorvertrag aus Rechnungen. Bestätigungen derselben Vertragsnummer geben den
frühesten Beginn, gleich in welcher Reihenfolge sie abgelegt sind. Im selben
Lieferverhältnis ist ein Beginn bis 7 Tage daneben am selben Zähler derselbe
Vertrag (etwa Bestätigung der Marke und Lieferbeginn-Brief des abrechnenden
Stadtwerks), verschiedene Vertragsnummern bleiben zwei. Gleicher Beginn mit
anderem Versorgernamen, und der Bestand trägt keine andere Vertragsnummer: der
vorhandene Vertrag als Rückfrage „derselbe Versorger?“ (`label_key`
`inboxDraft.item.contract.sameSupplier`, `params.existing` = Name im Bestand,
Grund `same_supplier`, `detail` `{provider, start, end, document}`, Feld
`provider` mit `status: conflict`, `existing` und `check: false`; den Namen im
Bestand schreibt das Übernehmen nie). `requires` *(3.9.0)*: an `prices`,
`advances` und `bill` die `id` des Vertragseintrags, ohne den der Eintrag
nicht übernommen wird (der Vertrag entsteht erst, oder sein Versorger ist eine
Rückfrage), sonst `null`; dann ist der Eintrag nur `checked`, wenn sein Vertrag
es ist, und `checked_with` ist seine Vorbelegung, sobald der Vertrag angehakt
ist. Ablesungen hängen am Zähler, nicht am Vertrag. Je Feld `new`, `exists`,
`conflict` (mit `existing`; ein Rechnungspreis mit `price_matches` ist
`exists`). `check` am Feld: zweifelhaft (Belegstelle markiert — die Marken
nach dem heutigen Bestand wie in Liste und Vorschau, `Proposals::marks` — und
kein zweites Dokument, das sie bestätigt; Rechnungspreise bestätigen sich nicht
gegenseitig), Gründe aus der Belegprüfung und `differs`,
`conflict_existing`, `chain_mismatch`, `inferred`, `falling_reading`,
`assumed_date`, `swap_open` (Stand nach einem Tausch ohne Endstand),
`swap_readings` (Tausch aus den Ständen passt nicht ganz: anderes laufendes
Gerät, Stand über dem Endstand, fallender neuer Zähler oder ein Stand ab dem
Tauschtag im Bestand — dann nicht vorbelegt, wie im Einzeldokument). `hint`
`newest`: verschiedene Kennungen, vorgewählt die aus dem jüngsten Dokument,
die anderen in `options`. Gelesen, wo die Auslesung sie hat:
`document_date`, `document_title` (Rechnungskorrektur) und `meter_readings`
(Stände je Zähler einer Rechnung; dieselbe Nummer mit aufeinanderfolgenden
Zeiträumen → Ablesungen desselben Zählers, mit überlappenden → `missing`
`two_registers`). Am Tauschtag entsteht keine Ablesung (die Stände trägt der
Tausch). Ein als geschätzt markiertes Ablesungsfeld (`is_estimated`, laut
Rechnung: `reading_*_estimated`, `meter_readings[].estimated`) legt eine
geschätzte Ablesung an. `basis` am Beginn: `confirmation`, `supply_start`, `invoice`,
`invoice_earlier` (vorgezogen auf die erste Rechnung), `existing`; am Ende
`document`, `successor`; an einer Preiszeile `vat_changed` (gleicher
Nettopreis, anderer Steuersatz laut Rechnung). Rechnungspreise zählen brutto
(netto mit gelesenem Steuersatz umgerechnet wie im Posteingang, auch bestätigt
oder korrigiert — eingegeben in derselben Basis wie gedruckt); stammt eine
Preiszeile nur aus Nettopreisen, trägt sie `net: {vat_pct}`. `core`: Kernfeld — ist eines zweifelhaft, ist
der Eintrag `check` und nicht vorbelegt.

**Übernehmen — `POST /api/inbox/draft/apply`** mit
`{items: [id, …], confirm: {id: [feld, …]}, choose: {id: {feld: option}}, documents?}`.
Ein ausgewählter Eintrag schreibt seine Felder, die neu und nicht zweifelhaft
sind, dazu die Kernfelder (anhaken bestätigt sie), die in `confirm` genannten
und die in `choose` gewählte Option. Eine unbekannte ID, ein unbekanntes Feld
oder eine Option außerhalb der Liste → `400 errors.inbox.proposalStale`, bevor
etwas geschrieben wird; ohne Auswahl `400 errors.inboxDraft.nothingSelected`;
*(3.9.0)* ein Eintrag ohne den Vertragseintrag aus seinem `requires` →
`400 errors.inboxDraft.needs.contract`, ebenfalls bevor etwas geschrieben
wird. Fehlt einer Rechnung beim Übernehmen ihr Vertrag (gescheitert), steht
sie in `failed` mit diesem Code und entsteht nicht; bis 3.8 entstand sie ohne
Vertrag und buchte ihr Ergebnis auf den Vertrag, der damals lief.
Vorher legt der Server eine Sicherung `pre-inbox-…` an (Einstellungen → Daten,
Anlass „vor Haushalt aus Dokumenten“). Antwort: `applied` (je Eintrag `ref`),
`failed` (je Eintrag oder Feld mit `error`, `code`), `created` (`meters`,
`meter_changes`, `contracts`, `rows`, `readings`, `bills`, `booked`,
`gas_factors`, `reminders`), `bills` (für „Rechnung prüfen“: `utility`, `id`,
`meter_id`, `period_from`, `period_to`, `booked`), `missing`, `documents`
(`done`, `filed`, `open`) und `snapshot`. Dokumente, die der Entwurf vollständig
abdeckt, stehen danach auf `done` (Übernommenes in `applied`, Zuordnung
gesetzt, Marken und Kernaussage `gist` neu wie nach `POST /api/inbox/{id}/apply`),
wohl doppelte auf `filed`, wenn ihr Original `done` ist; `duplicate_of`
rechnet die Liste weiter aus dem Inhalt, gespeichert wird es nicht.

**Löschen — `DELETE /api/inbox/{id}`.** Der Eintrag; das PDF nur, solange es
nur am Posteingang hängt.

**Termin — `POST /api/inbox/{id}/remind`** mit `{date: "JJJJ-MM-TT"}` *(Paket
betrieb)*: ein Termin zum Dokument (Mahnung: „… klären“) am zugeordneten Zähler,
mit der Zuständigen des Vertrags; das Dokument ist danach abgelegt. Antwort wie
die Vorschau. Ohne gültigen Tag `400 errors.reminder.dueRequired`.

#### Ordner — `POST /api/inbox/folder`

*(v3.7.0, Paket betrieb)* Liest `<Datenverzeichnis>/posteingang/` ein (nur
Verwalter, sonst `403 errors.auth.adminOnly`; fehlt der Ordner, wird er
angelegt). Nur Dateien direkt im Ordner, keine versteckten, keine
symbolischen Links; PDF nach Inhalt, bis 10 MB; höchstens 20 je Aufruf, eine
Datei, die jünger als 10 Sekunden ist, wartet. Danach liegt sie in
`posteingang/übernommen/` (auch ein Doppel), eine abgelehnte in
`posteingang/fehler/`. Antwort:

```json
{ "dir": "posteingang", "imported": [{ "name": "Abrechnung.pdf", "id": "inb_…", "moved": "übernommen/Abrechnung.pdf" }],
  "duplicates": [], "failed": [{ "name": "foto.pdf", "reason": "type", "moved": "fehler/foto.pdf" }],
  "skipped": [{ "name": "link.pdf", "reason": "symlink" }], "waiting": 0, "more": false }
```

`reason`: `type` (kein PDF), `size`, `rejected` (mit `code`), `quota` (Speicher
der Belege voll — die Datei bleibt liegen), bei `skipped` `symlink`,
`not_a_file`, `outside`, `unreadable`. `moved` ist `null`, wenn das
Verschieben nicht ging.

#### Akte — `GET /api/utility/{u}/dossier`

*(v3.7.0, Paket betrieb)* `?meter_id=` oder `?contract_id=` (sonst `400
errors.dossier.scope`; unbekannt → `404`). `items`, neueste zuerst:

| `kind` | Was | Felder |
|---|---|---|
| `inbox` | erledigtes oder abgelegtes Dokument des Posteingangs, dem Zähler bzw. Vertrag zugeordnet | `type` (Dokumentart), `inbox_id`, `status`, `gist`, `bill_id` (wenn daraus eine Rechnung wurde) |
| `bill` | Versorgerrechnung | `type` (`annual`, `final`, `interim`), `bill_id`, `period_from`, `period_to`, `result_eur`, `amount_eur`, `attachment_ids` |
| `receipt` | Beleg am Vertrag | `contract_id`, `provider` |
| `photo` | Foto einer Ablesung (nur beim Zähler) | `counter` |

Jeder Eintrag hat `date` (Stichtag des Dokuments, sonst Eingang), `title` und
`attachment_id` (oder `null`). Ein PDF steht nur einmal.

#### `GET /api/inbox/cloud` · `PATCH /api/inbox/cloud` *(v3.7.0)*

Auslesen in der Cloud für den Posteingang: ein Opt-in, aus, bis es jemand
einschaltet, der die Installation verwaltet
([Anleitung](../anleitungen/posteingang.md#in-der-cloud-auslesen)). Die
Texterkennung bleibt im Heimnetz; dies ist ein eigener Weg. `GET` liefert
den Stand, **nie den Schlüssel**:

```json
{ "success": true, "data": {
  "enabled": false, "provider": "anthropic", "endpoint": "", "model": "",
  "timeout_s": 120, "has_key": false, "keys": { "anthropic": false, "openai": false }, "ready": false,
  "active": false,
  "preset": "anthropic", "experimental": true,
  "presets": [
    { "id": "anthropic", "provider": "anthropic", "endpoint": null, "label": "Claude (Anthropic)", "host": "api.anthropic.com", "has_key": false },
    { "id": "mistral", "provider": "openai", "endpoint": "https://api.mistral.ai/v1", "label": "Mistral", "host": "api.mistral.ai", "has_key": false },
    { "id": "custom", "provider": "openai", "endpoint": null, "label": null, "host": "", "has_key": false } ],
  "providers": [
    { "id": "anthropic", "label": "Claude (Anthropic)", "destination": "api.anthropic.com",
      "notice": "Das Dokument geht als PDF an …", "sends": "pdf", "default_model": "claude-opus-5-5" },
    { "id": "openai", "label": "OpenAI-kompatibler Dienst", "destination": "",
      "notice": "Der Text des Dokuments geht an …", "sends": "text", "default_model": "" } ] } }
```

(`presets` gekürzt; vollständig: `anthropic`, `openai`, `mistral`, `openrouter`,
`gemini`, `custom`.) `notice` ist der Hinweis, den der Posteingang vor jedem
Auslesen zeigt (in der Sprache der Anfrage), `destination` der Host, an den das
Dokument geht, `sends` was hinausgeht (`pdf` oder `text`). `ready` heißt: Der
Dienst könnte senden — eingeschaltet **und** mit Schlüssel. Nach dem Einspielen
einer Sicherung auf einem anderen Server ist `enabled` wahr, `ready` nicht.
`active` heißt: bereit **und** im Umschalter der Karte „Auslesen“ gewählt
(`inbox_provider`) — dann geht wirklich etwas hinaus. *(Karte „Auslesen“,
additiv)* `preset` ist die Voreinstellung, die zu Anbieter und Adresse passt
(sonst `custom`), `presets[].has_key`, ob für dieses Ziel ein Schlüssel liegt —
beim OpenAI-kompatiblen Weg gehört er zum Host. `experimental`: Alle Cloud-Wege
sind noch nicht mit echten Rückmeldungen geprüft; `GET /api/inbox` meldet es
ebenso (`provider.experimental`).

`PATCH` nimmt dieselben Felder (`enabled`, `provider`, `endpoint`, `model`,
`timeout_s`), alle optional, dazu `api_key` (neuer Schlüssel; leer = behalten)
und `clear_key` (`true` = den des Anbieters, sonst die Kennung eines Anbieters;
die Auswahl bleibt). *(Karte „Auslesen“, additiv)* `preset` setzt Anbieter und
Adresse aus der Voreinstellung; `select: true` lässt den Dienst auslesen, sobald
er bereit ist, `select: false` lässt `inbox_provider`, wie es ist. Antwort wie
`GET`. Eingeschaltet wird nur ein vollständiger Anschluss. Wird er dadurch
bereit, setzt der Server ohne `select` `inbox_provider` auf den Anbieter, beim
Ausschalten zurück auf `local`; wer bei bereiter Cloud „Lokal“ gewählt hat,
behält das. Über `PATCH /api/settings` nimmt `inbox_provider`
einen Cloud-Dienst nur an, solange er bereit ist, sonst `400`
(`errors.inboxCloud.off` als Text). Nur Verwalter, sonst `403`
`errors.auth.adminOnly` — ebenso für die Schlüssel `inbox_*` über
`PATCH /api/settings`.

| Status | `code` | Wann |
|---|---|---|
| `400` | `errors.inboxCloud.noKey` | einschalten ohne Schlüssel |
| `400` | `errors.inboxCloud.noEndpoint`, `errors.inboxCloud.noModel` | OpenAI-kompatibel ohne Adresse bzw. Modell |
| `400` | `errors.inboxCloud.httpsOnly` | Adresse nicht `https://` oder mit Zugangsdaten |
| `400` | `errors.inboxCloud.notPublic` | Adresse oder Name im Heimnetz (`192.168.…`, `nas.local`, `fritz.box`, ein Name ohne Punkt) |
| `400` | `errors.inboxCloud.keyFormat` | Schlüssel nicht 8–500 druckbare Zeichen ohne Leerzeichen |

**Beim Auslesen** (über den Posteingang) melden die Anschlüsse: `400`
`errors.inboxCloud.off` bzw. `noKey` (nicht eingeschaltet), `notPublic` (eine
aufgelöste Adresse ist nicht öffentlich), `notPdf`, `emptyDocument`; `413`
`tooLarge` bzw. `tooLargeLimits`; `422` `refusal` (das Modell lehnt ab); `429`
`rateLimited` mit `Retry-After`; `502` `unauthorized` (der Dienst lehnt den
Schlüssel ab — bewusst nicht `401`, das hieße in der Oberfläche „neu
anmelden“), `redirect`, `unreachable`, `badAnswer`, `truncated`, `rejected`;
`503` `overloaded` mit `Retry-After`; `504` `timeout`. Was jeder Anbieter
sieht und wie die Verbindung geprüft wird:
[Sicherheit](../betrieb/sicherheit.md#auslesen-in-der-cloud-v370).

#### `GET /api/inbox/cloud/models` · `GET /api/inbox/local/check` *(v3.7.0, Karte „Auslesen“)*

Beide nur für Verwalter (sonst `403` `errors.auth.adminOnly`), beide ohne
Dokument.

**`GET /api/inbox/cloud/models`** — die Modelle des eingerichteten Cloud-Diensts
(gespeicherter Anbieter, Adresse und Schlüssel; der Schlüssel bleibt auf dem
Server). OpenAI-kompatibel `GET <basis>/models` mit `Authorization: Bearer`,
Claude `GET https://api.anthropic.com/v1/models?limit=1000` mit `x-api-key` und
`anthropic-version`. Dieselben Regeln wie beim Auslesen (nur HTTPS, nie das
Heimnetz, geprüfte Adresse, keine Weiterleitung) und dieselben Fehler
(`noKey`, `noEndpoint`, `notPublic`, `unauthorized`, `redirect` …). Nur
Kennungen, die als `inbox_cloud_model` gespeichert werden dürfen, höchstens
1000, in der Reihenfolge des Diensts:

```json
{ "success": true, "data": { "provider": "openai", "preset": "mistral",
  "models": [ { "id": "test-model-a", "label": "" }, { "id": "vendor/test-model-b", "label": "Test Model B" } ] } }
```

**`GET /api/inbox/local/check?endpoint=…&api=ollama|openai`** — „Verbindung
prüfen“ für den eigenen Dienst (ohne Parameter die gespeicherten
`ocr_endpoint`/`ocr_api`): Ollama `GET /api/tags`, OpenAI-kompatibel
`GET /v1/models`, nur im Heimnetz wie die Texterkennung. Antwort
`{ host, api, models: ["qwen3.8:latest", …], model, model_found }` — `model` ist
`inbox_model`, `model_found` sagt, ob es dabei ist (auch mit `:latest`). Fehler
wie bei der Texterkennung: `400` `errors.ocr.off`, `errors.ocr.notLocal`,
`errors.ocr.unreachable`, `errors.ocr.timeout`, `errors.ocr.badAnswer`.

### `POST /api/ingest` *(F1009, v1.9.0 — Home Assistant)*

Idempotenter Push-Eingang für externe Datenlieferanten. **Upsert pro
(Zähler, Datum):** ein erneuter Push am selben Tag aktualisiert den Wert,
statt eine zweite Ablesung anzulegen.

```jsonc
// Header (nur falls ein Token gesetzt ist): Authorization: Bearer <token>
{
  "utility": "strom",
  "meter":   "stromzaehler_haus",  // external_id-Alias ODER interne Meter-ID
  "value":   12345.6,              // alias: "counter"
  "date":    "2026-06-02"          // optional, Default heute; ISO-Stempel wird gekürzt
}
```

Antwort `201` (neu) bzw. `200` (aktualisiert) mit
`{ status: "created"|"updated", utility, meter_id, date, counter, reading_id,
suspect }` — `suspect` seit v2.6.0: Ist der Wert kleiner als der vorige Stand
desselben Geräts, wird er gespeichert, aber als Verdacht markiert
(`suspect: true`, dazu `previous: {date, counter}`) und zählt erst nach
Bestätigung in der Oberfläche. Ein Überlauf mit gepflegter Stellenzahl
(`digits`) ist kein Verdacht. Ein Push lehnt deshalb nichts ab, was vorher
angenommen wurde.
Fehler: `401` (Token nötig/falsch; seit v2.6.0 mit eingeschalteter Anmeldung
auch ohne gesetzten Token: `errors.ingest.tokenRequiredWithLogin`), `400`
(unbekannte Utility/Zähler, kein Zahlenwert, kein gültiges Kalenderdatum,
Delivery-Utility Heizöl/Pellets; seit v3.1.0 ein Zähler mit Verbrauch je
Zeitraum: `errors.ingest.periodMeter` — er nimmt keine Stände an).

Liegt das Datum vor dem Einbau des **ersten** Geräts, wird dieses seit v2.5.3
zurückdatiert statt die Ablesung abzulehnen (typisch beim Nachtragen älterer
Stände nach einer Neuinstallation). Ein Datum in einer Lücke zwischen zwei
Geräten bleibt ein `400`.

#### Zeitstempel und Viertelstunden *(v3.3.0)*

Optional `timestamp` — der Zeitpunkt, zu dem der Stand galt, als ISO 8601
**mit Zeitzone**: `JJJJ-MM-TTTHH:MM[:SS[.ffffff]]` und dahinter `Z`,
`±HH:MM` oder `±HHMM`, so wie Home Assistant `now().isoformat()` schreibt.
Anderes → `400` `errors.ingest.timestampFormat`. Ohne `date` gilt der Tag des
Zeitstempels in der Zeitzone der Installation. Die Ablesung des Tages bleibt,
wie sie war: ein Stand je Zähler und Tag, der letzte Push gewinnt.

```json
{ "utility": "strom", "meter": "stromzaehler_haus", "value": 12345.6,
  "timestamp": "2026-10-12T14:37:00+02:00" }
```

Aus zwei Ständen mit Zeitstempel entsteht Verbrauch je Viertelstunde: Die
Differenz verteilt sich nach Zeitanteil auf die Viertelstunden dazwischen.

```text
Stand 12.345,0 um 14:07, Stand 12.345,6 um 14:37  →  0,6 kWh in 30 Minuten
14:00–14:15   8 Minuten  → 0,16 kWh   zum Anteil vor 14:07 dazu
14:15–14:30  15 Minuten  → 0,30 kWh
14:30–14:45   7 Minuten  → 0,14 kWh   den Rest bringt der nächste Stand
```

Nichts verteilt wird beim ersten Stand mit Zeitstempel, bei einem Zeitpunkt
gleich oder vor dem vorigen (der vorige bleibt Bezug), bei einem Rückgang und
bei einer Lücke über sechs Stunden — in den beiden letzten Fällen ist der neue
Stand der neue Anfang.
Ein Stand unter Verdacht (`suspect: true`) zählt gar nicht. Mit `timestamp`
trägt die Antwort zusätzlich `intervals`, die Zahl der berührten
Viertelstunden (`0`, wenn nichts verteilt wurde); bei Verdacht fehlt das Feld.

```json
{ "status": "updated", "utility": "strom", "meter_id": "m_strom_main",
  "date": "2026-10-12", "counter": 12345.6, "reading_id": "20261012-1a2b3c4d",
  "suspect": false, "intervals": 3 }
```

Für ein Profil je Viertelstunde muss der Stand mindestens alle 15 Minuten
kommen — in Home Assistant eine Automatisierung mit `time_pattern` und
`minutes: "/15"`. Der REST-Command der Vorlage schickt `timestamp` seit v3.3.0
mit; ihre Automatisierung sendet aber einmal am Tag (23:55), und über mehr als
sechs Stunden verteilt sich nichts
([Home Assistant](../anleitungen/home-assistant.md)). Stände im Abstand bis
sechs Stunden verteilen sich gleichmäßig über ihren Abstand.

#### Stapel *(v3.1.0)*

Mehrere Stände in einer Anfrage — zum Nachliefern nach einem Ausfall von Home
Assistant oder aus Node-RED, ioBroker und openHAB
([Andere Systeme](../anleitungen/andere-systeme.md)). Der Body ist eine
**Liste** von Einzelobjekten oder ein Objekt `{"readings": [...]}`; Token wie
beim Einzel-Push.

```json
{ "readings": [
  { "utility": "strom", "meter": "stromzaehler_haus", "value": 48190.2, "date": "2026-10-05" },
  { "utility": "strom", "meter": "stromzaehler_haus", "value": 48201.7, "date": "2026-10-06" },
  { "utility": "gas",   "meter": "gaszaehler_garten", "value": 9876.5,  "date": "2026-10-06" }
] }
```

- Höchstens **500** Einträge, sonst `400` mit `code` `errors.ingest.tooMany`.
  Eine leere Liste unter `readings` oder etwas anderes als eine Liste →
  `400` `errors.ingest.bodyInvalid`. In beiden Fällen wird nichts gespeichert.
- Jeder Eintrag wird wie ein Einzel-Push behandelt: gleiche Prüfungen, Upsert je
  Zähler und Tag, gleiche Antwortfelder. Ein fehlerhafter Eintrag hält die
  anderen nicht auf.
- Verarbeitet wird nach Verbrauchsart, Zähler und Datum, seit v3.3.0 am
  selben Tag nach `timestamp` (für die Reihenfolge zählt bei einem Eintrag
  ohne `date` das Datum am Anfang seines Zeitstempels). So sieht die Verdachtsprüfung frühere Stände
  desselben Stapels, und die Viertelstunden entstehen in der richtigen
  Reihenfolge. Jede Datendatei wird einmal geschrieben.
- Antwort **`200` auch bei Teilfehlern**, die Ergebnisse in Eingabereihenfolge:

```json
{ "success": true, "data": {
  "results": [
    { "index": 0, "status": "created", "utility": "strom", "meter_id": "m_strom_main",
      "date": "2026-10-05", "counter": 48190.2, "reading_id": "20261005-1a2b3c4d", "suspect": false },
    { "index": 1, "status": "created", "utility": "strom", "meter_id": "m_strom_main",
      "date": "2026-10-06", "counter": 48201.7, "reading_id": "20261006-5e6f7a8b", "suspect": false },
    { "index": 2, "status": "error", "code": "errors.ingest.meterNotFound",
      "error": "gas: kein Zähler für „gaszaehler_garten“ gefunden (weder als Alias noch als ID)" }
  ],
  "created": 2, "updated": 0, "failed": 1 } }
```

`status` ist `created`, `updated` oder `error`; ein Fehler trägt `code` (stabil)
und `error` (Text in der Sprache der Anfrage) — etwa `errors.ingest.periodMeter`
für einen Eintrag an einen Zähler mit Verbrauch je Zeitraum (v3.1.0). **Wer nachliefert, prüft
`failed`** — der HTTP-Status allein sagt nur, dass der Stapel angekommen ist.

Home Assistant: `rest_command` mit `response_variable` (die Antwort steht dann
unter `content`):

```yaml
rest_command:
  energietracker_batch:
    url: "http://192.168.178.10:8080/api.php/api/ingest"
    method: POST
    headers:
      Authorization: !secret energietracker_auth
    content_type: "application/json"
    payload: "{{ readings | tojson }}"

# in einer Automatisierung oder einem Skript:
actions:
  - action: rest_command.energietracker_batch
    data:
      readings: "{{ stapel }}"        # Liste von {utility, meter, value, date}
    response_variable: antwort
  - if:
      - condition: template
        value_template: "{{ antwort.status != 200 or antwort.content.data.failed > 0 }}"
    then:
      - action: persistent_notification.create
        data:
          title: "Energietracker"
          message: >-
            {% if antwort.status != 200 %}HTTP {{ antwort.status }}: {{ antwort.content.code | default('') }}
            {% else %}Abgelehnt: {{ antwort.content.data.results | selectattr('status', 'eq', 'error')
            | map(attribute='code') | join(', ') }}{% endif %}
```

Node-RED: ein Funktionsknoten hinter dem `http request`-Knoten (Rückgabe „a
parsed JSON object“):

```js
const d = msg.payload && msg.payload.data;
if (msg.statusCode !== 200 || !d) {
  node.error(`Energietracker: HTTP ${msg.statusCode} ${(msg.payload && msg.payload.code) || ''}`, msg);
  return null;
}
if (d.failed > 0) {
  const bad = d.results.filter(r => r.status === 'error').map(r => `#${r.index} ${r.code}`);
  node.warn(`Energietracker: ${d.failed} abgelehnt – ${bad.join(', ')}`);
}
return msg;
```

Ein einzelnes Objekt im Body verhält sich wie bisher (`201`/`200`).

> **Vorlage für Home Assistant:** `| float` **ohne** Ersatzwert und vor dem
> Push `has_value(…)` prüfen — siehe [`docs/HOME-ASSISTANT.md`](../anleitungen/home-assistant.md).
> `float(0)` aus Vorlagen bis v2.5.2 buchte bei nicht verfügbarem Sensor einen
> Zählerstand 0.

### `GET|POST|DELETE /api/auth/token` *(F1009)*

Verwaltung des **opt-in**-Tokens für den Push-Endpunkt. Er schützt **nur**
`/api/ingest`: Ohne Token nimmt der Ingest Werte ohne Kopfzeile an; sobald ein
Token existiert, verlangt er `Authorization: Bearer <token>`. Den Rest der API
schützt die Anmeldung (s. o.), nicht dieser Token. Der Token wird nur als
SHA-256-Hash in `data/auth.json` gespeichert und beim Erzeugen **einmalig** im
Klartext zurückgegeben. Seit v2.6.0 nennt `GET` zusätzlich `last_used_at`
(auf die Stunde genau) — hilfreich bei der Frage „kommt überhaupt etwas an?".

### `/api/session`, `/api/session/password`, `/api/auth/keys` *(v2.6.0)*

```jsonc
// GET /api/session
{ "mode": "off", "authenticated": true, "mode_fixed": false,
  "password_fixed": false, "has_password": false,
  "named_login": false, "user": null, "role": "admin" }   // die letzten drei seit v3.2.0

// POST /api/session/password   (Einschalten: nur password; Ändern: + current)
{ "password": "mindestens-8-Zeichen", "current": "bisheriges" }
// → { "mode": "password" } + Sitzungs-Cookie; ein neues Passwort beendet alle anderen Sitzungen

// POST /api/auth/keys
{ "name": "Backup-Skript", "scope": "read" }
// → 201 { "id": "k_…", "key": "etk_…", "hint": "…" }   Klartext nur hier
```

- `mode`: `off` · `password` · `proxy`. `mode_fixed`/`password_fixed`: über
  `ET_AUTH` bzw. `ET_ADMIN_PASSWORD_HASH` festgelegt — dann antworten die
  ändernden Routen `409`.
- Fehlversuche: nach fünf innerhalb von 15 Minuten ist die Anmeldung
  5 Minuten gesperrt (`429`). Das gilt auch für `current` beim Ändern und
  Ausschalten. Seit v3.4.0 je Gerät (Client-Adresse, hinter einem Proxy aus
  `ET_TRUSTED_PROXIES` die aus `X-Forwarded-For`); zehn Fehlversuche für einen
  Namen sperren diesen Namen, hundert insgesamt alle. Ein unbekannter Name
  braucht so lange wie ein bekannter.
- *(v3.4.0)* `POST /api/session/logout-everywhere` — ohne Körper; erhöht die
  Sitzungs-Epoche der angemeldeten Person, alle ihre Sitzungen enden (auch
  die dieser Anfrage, das Cookie wird gelöscht). Antwort
  `{authenticated: false, logged_out_everywhere: true}`; ohne angemeldete
  Person (offener Betrieb, API-Schlüssel) `400` `errors.users.noUser`.
- *(v3.4.0)* Gleitende Sitzung: Eine gültige Sitzung, die älter als ein Tag
  ist, bekommt bei der nächsten Anfrage ein neues Cookie mit voller Dauer
  (30 Tage). Ein Cookie, das niemand benutzt, läuft 30 Tage nach dem letzten
  Gebrauch ab.
- `GET /api/auth/keys` liefert `id`, `name`, `scope`, `created_at`,
  `last_used_at` — nie den Schlüssel oder seinen Hash.
- *(v3.2.0)* `/api/session/password`, `/api/auth/keys` und `/api/auth/token`
  erreichen nur Verwalter. `POST /api/session/password` setzt das Passwort der
  Installation (das des ersten Verwalters `u_admin`); `current` ist das
  Passwort der angemeldeten Person. Das eigene Passwort ändert jede Person über
  `POST /api/session/me/password`.

### Personen im Haushalt *(v3.2.0)*

Mit eingeschalteter Anmeldung kann jede Person des Haushalts ein eigenes Konto
haben: eigene Anmeldung, eigene Nutzungsstufe und Sprache. Die Daten des
Haushalts teilen sich alle. Personen stehen in `data/auth.json`, nicht im
Backup, ohne Schemaschritt ([Datenmodell](datenmodell.md#personen-in-authjson-v320));
seit v3.6.0 dazu im [Personenverzeichnis](#personenverzeichnis-v360), das ins
Backup gehört.
Anleitung: [Benutzer im Haushalt](../anleitungen/benutzer.md).

| Rolle | `role` | darf |
|---|---|---|
| Verwaltung | `admin` | alles |
| Mitglied | `member` | erfassen und alles sehen; die eigene Stufe, Sprache und das eigene Passwort ändern |

**Nur für Verwalter** — ein Mitglied bekommt `403` `errors.auth.adminOnly`:

- `/api/users…`, `/api/auth/keys…`, `/api/auth/token` und
  `/api/session/password` — jede Methode, auch `GET`;
- `POST /api/backup/import`, `POST /api/backup/snapshots/{name}/restore` und
  `POST /api/demo/import` — alle drei ersetzen die Daten;
- *(v3.4.0)* `DELETE /api/backup/snapshots/{name}` — darunter sind die
  Sicherungen vor einem Import, der Rückweg;
- `PATCH /api/settings` mit `frame_ancestors`, `ocr_endpoint`,
  `evcc_endpoint` oder (seit v3.4.0) `allowed_hosts` bzw. `activity_keep_months`
  im Body, wenn sich der Wert ändert — Einstellungen, mit denen die App andere
  Adressen anspricht, sich einbetten lässt, Namen sperrt oder den Verlauf
  kürzt. Ohne diese Schlüssel dürfen auch Mitglieder Einstellungen ändern;
- *(v3.4.0)* `DELETE /api/activity` — den Verlauf leeren
  ([Erledigt mit Verlauf](#erledigt-mit-verlauf-v340)).

Die Rolle gilt für angemeldete Personen. Ohne Anmeldung und mit einem
API-Schlüssel gibt es keine Person; eine Anfrage darf dann, was sie bisher
durfte (ein `read`-Schlüssel weiter nur lesen).

**Übergang aus v3.1.** Bis v3.1 gab es ein Passwort. Es gehört jetzt dem
ersten Verwalter: Kennung `u_admin`, Name `admin`. Solange niemand eine Person
anlegt, steht er nur in der Liste (`GET /api/users`); mit der ersten Änderung
wird er in `auth.json` eingetragen. Er meldet sich weiter **ohne Namen** an,
mit dem Passwort der Installation bzw. `ET_ADMIN_PASSWORD_HASH`. Sitzungen aus
v3.1 gelten für ihn weiter. Wird `u_admin` gelöscht, entfällt das Passwort der
Installation und mit ihm die Anmeldung ohne Namen.

**Proxy.** Im Modus `proxy` erkennt die App die Person am gemeldeten Namen
([Anmeldung](#anmeldung-v260-opt-in)) und legt sie beim ersten Besuch an
(`source: "proxy"`, Name auf 40 Zeichen gekürzt): als Verwalter, solange es
keinen Verwalter vom Proxy gibt, sonst als Mitglied. Ein Passwort hat sie in
der App nicht.

**Person.** Jede Antwort mit einer Person hat diese Felder, nie einen
Passwort-Hash:

```json
{ "id": "u_3f9a1c2e", "name": "Alex", "role": "member", "source": "password",
  "prefs": { "ui_level": "beginner", "language": "en" },
  "created_at": "2026-10-09T18:20:11+02:00" }
```

| Feld | Bedeutung |
|---|---|
| `id` | `u_` und acht Hexziffern; der erste Verwalter `u_admin` |
| `name` | 1–40 Zeichen ohne Steuerzeichen, mehrfacher Leerraum wird zu einem; eindeutig ohne Rücksicht auf Groß- und Kleinschreibung |
| `role` | `admin` oder `member` |
| `source` | `password` (Anmeldung in der App) oder `proxy` (vom vorgeschalteten Dienst) |
| `prefs` | eigene Einstellungen, nur die gesetzten: `ui_level` (`beginner`, `advanced`, `expert`) und `language`. Ohne eigene Stufe gilt die der Installation, ohne eigene Sprache die des Geräts bzw. der Installation |
| `created_at` | angelegt (ISO 8601) |
| `no` | *(v3.6.0, nur `GET`/`POST /api/users`)* fortlaufende Nummer aus dem [Personenverzeichnis](#personenverzeichnis-v360) |

**`GET /api/session`** nennt seit v3.2.0 zusätzlich:

```jsonc
{ "mode": "password", "authenticated": true, "mode_fixed": false,
  "password_fixed": false, "has_password": true,
  "named_login": true,
  "user": { "id": "u_3f9a1c2e", "name": "Alex", "role": "member", "source": "password",
            "prefs": { "ui_level": "beginner" }, "created_at": "…" },
  "role": "member" }
```

- `named_login`: Modus `password`, und es gibt mindestens eine angelegte Person
  mit Passwort außer `u_admin` — die Anmeldung fragt dann nach dem Namen.
- `user`: die Person dieser Sitzung; `null` ohne Anmeldung, mit API-Schlüssel
  und nicht angemeldet.
- `role`: `admin` oder `member`; nicht angemeldet `null`, ohne Person `admin`.

**`POST /api/session`** — `{name?, password}`. Ohne Namen (leer oder fehlend)
gilt der erste Verwalter mit dem Passwort der Installation. Der Name zählt ohne
Rücksicht auf Groß- und Kleinschreibung und nur für Personen mit
`source: "password"`. Unbekannter Name und falsches Passwort antworten gleich:
`401` `errors.auth.wrongPassword`. Die Sperre nach fünf Fehlversuchen (`429`)
gilt für die ganze Installation, nicht je Person. Außerhalb des Modus
`password` → `400` `errors.auth.notPasswordMode`.

| Route | Body | Antwort | Fehler |
|---|---|---|---|
| `PATCH /api/session/me` | `{ui_level?, language?}`; `null` oder `""` löscht den eigenen Wert | die eigene Person | `400` `errors.users.noUser` (keine Person angemeldet: ohne Anmeldung oder mit API-Schlüssel); `400` `errors.settings.valueInvalid` (unbekannte Stufe oder Sprache) |
| `POST /api/session/me/password` | `{current, password}` | `{changed: true}` und ein neues Sitzungs-Cookie | `400` `errors.users.noUser`; `400` `errors.users.proxyNoPassword` (Person vom Proxy); `401` `errors.auth.wrongPassword` bzw. `429` `errors.auth.locked` (bisheriges Passwort); `400` `errors.auth.passwordTooShort` (unter 8 Zeichen); `409` `errors.auth.passwordFixed` (`u_admin` bei `ET_ADMIN_PASSWORD_HASH`) |
| `GET /api/users` | — | Liste der Personen, samt `u_admin`, solange es ihn gibt | — |
| `POST /api/users` | `{name, password, role?}`; `role` Standard `member` | `201` mit der Person (`source: "password"`) | `400` `errors.users.nameInvalid`, `errors.users.roleInvalid`, `errors.auth.passwordTooShort`; `409` `errors.users.nameTaken` |
| `PATCH /api/users/{id}` | `{name?, role?, password?}`, jedes Feld für sich; das Passwort ohne das bisherige („Passwort neu setzen“) | die geänderte Person | `404` `errors.users.notFound`; `400` wie beim Anlegen; `409` `errors.users.nameTaken`, `errors.users.lastAdmin` (der letzte Verwalter würde Mitglied), `errors.auth.passwordFixed` |
| `GET /api/users/{id}/handover` | — *(v3.6.0)* | `{person, to, open, done}` ([Personenverzeichnis](#personenverzeichnis-v360)); *(v3.7.0)* dazu `meters`, `contracts` | `404` `errors.users.notFound` |
| `DELETE /api/users/{id}` | — ; *(v3.4.0)* `?anonymize_activity=1` ersetzt den Namen im Verlauf durch „ehemalige Person“ | `{deleted: true, activity_anonymized}`; *(v3.6.0)* dazu `handed_over`, `handed_over_to`, `kept`; *(v3.7.0)* `records_handed_over`, `records_kept` | `400` `errors.users.notSelf` (die eigene Person); `404` `errors.users.notFound`; `409` `errors.users.lastAdmin`, `errors.auth.passwordFixed` (`u_admin` bei `ET_ADMIN_PASSWORD_HASH`) |

Eine gelöschte Person ist sofort abgemeldet: Ihr Sitzungs-Cookie gilt nicht
mehr. Ein neues Passwort — selbst geändert oder vom Verwalter neu gesetzt —
meldet die Person auf allen Geräten ab; wer es selbst ändert, bekommt für das
eigene Gerät gleich ein neues Cookie. Die anderen Personen bleiben angemeldet.

### Snapshots und Import *(v2.6.0)*

`GET /api/backup/snapshots` listet `data/backups/` (neueste zuerst):

```json
[ { "name": "pre-restore-2026-09-25_000438.json", "size": 215512,
    "created_at": "2026-09-25T00:04:38+02:00", "reason": "restore" } ]
```

`reason` ∈ `manual` (eigener Snapshot), `restore` (vor Import/Einspielen),
`migration`, `demo`, `v09`. **Aufbewahrung:** von den eigenen die letzten
zehn; automatische 30 Tage, je Anlass mindestens die drei neuesten.

`POST /api/backup/import` prüft seit v2.6.0 **zuerst alles**: Jeder Topf muss
eine Liste von Objekten mit Pflichtfeldern und gültigen Daten sein. Ein
fehlerhaftes Backup ändert nichts und antwortet `400` mit
`detail.problems` (höchstens 50):

```json
{ "success": false, "code": "errors.backup.invalid",
  "error": "Das Backup ist unvollständig oder beschädigt (Probleme: 3). Es wurde nichts eingespielt.",
  "detail": { "problems": [ { "pot": "gas/meters", "index": 0, "problem": "missing:devices" } ] } }
```

`problem` ∈ `not_an_object`, `not_a_list`, `unknown_utility`,
`missing:<feld>`, `date:<feld>`, `counter`, `devices`, `entry:<datum>`; seit
v3.1.0 für Belege außerdem `id` und `mime` (Indexeintrag im Topf
`attachments`) sowie `unknown:<id>`, `sha256:<id>` und `mime:<id>` (Datei im
Topf `attachment_files`, s. u.). Mit `?dry_run=1` endet der Import nach der
Prüfung und liefert den Bericht (Anzahlen je Topf, `untouched` = nicht im
Backup enthaltene und daher unveränderte Töpfe). Weitere Änderungen: Die Hülle
von `GET /api/backup/export` (`{success, data}`) wird ausgepackt; scheitert
eine Datei mitten im Schreiben, werden die schon geschriebenen zurückgesetzt;
`recommendations_dismissed` gehört seit v2.6.0 zum Backup.

**Belege im Backup *(v3.1.0)*.** Das Format bleibt `3.0`. Neu sind der Topf
`attachments` (der Index) und der Schlüssel `attachment_files` mit dem Inhalt
jeder Datei, base64-kodiert: `{"att_…": "<base64>"}`. Ältere Versionen
übergehen den unbekannten Schlüssel.

- `GET /api/backup/export?attachments=0` lässt die Dateien weg, der Index
  bleibt. Gedacht für eine schnelle Sicherung der Zahlen: Wer ein solches
  Backup einspielt, hat Verweise auf Fotos ohne Datei (`404` beim Abrufen).
- Export und Snapshot werden Datei für Datei gestreamt; der Export behält die
  Hülle `{success, data}`.
- Der Import prüft jede Datei **vor dem ersten Schreiben**: Sie braucht einen
  Indexeintrag (`unknown:<id>`), ihre SHA-256-Prüfsumme muss stimmen
  (`sha256:<id>`) und ihr Inhalt zum Typ im Index passen (`mime:<id>`).
  Geschrieben werden erst die Belege, dann der Index; eine schon vorhandene
  Datei gleicher ID bleibt unberührt. Der Bericht zählt die Dateien unter
  `attachment_files`.
- Snapshots enthalten die Belege ebenfalls — `data/backups/` wächst also mit
  jedem Foto, je Snapshot einmal. Ein Backup mit Belegen ist größer als die
  Dateien selbst (base64: rund ein Drittel mehr); Grenzen für den Upload beim
  Einspielen: [Webserver](../betrieb/webserver.md).

**Ladevorgänge im Backup *(v3.2.0)*.** Neuer Topf `ev_sessions` (Format bleibt
`3.0`); jeder Eintrag braucht `id`, `meter_id`, `date` und `charged_kwh`, und
`date` muss ein Kalenderdatum sein. Ein älteres Backup ohne den Topf lässt die
gespeicherten Ladevorgänge unverändert (`untouched`). Personen und Passwörter
(`auth.json`) gehören weiterhin nicht zum Backup.

**Verlauf im Backup *(v3.4.0)*.** Neuer Topf `activity` (Format bleibt `3.0`);
jeder Eintrag braucht `id`, `action` (`done`, `auto_done`, `dismissed`),
`subject` mit `kind` und `occurrence` und `done_on` als Kalenderdatum. Die
Namen der Personen stehen darin als Momentaufnahme. Ein älteres Backup ohne
den Topf lässt den Verlauf unverändert (`untouched`).

**Viertelstundenreihen im Backup *(v3.3.0)*.** Das Format bleibt `3.0`. Neu
sind der Topf `intervals` (der Index `intervals.json` samt `samples`) und der
Schlüssel `interval_files` mit jeder Reihe des Index:
`{"<id>": {id, kind, year, step, start, values}}`. Er steht immer im Backup,
auch mit `?attachments=0` und in jedem Snapshot — eine Reihe ist Daten, kein
Beleg, und belegt als Datei bis zu rund 250 KB je Jahr.

- Beim Einspielen braucht jede Reihe eine gültige ID, einen Eintrag im Index
  des Backups und eine Liste `values`; sonst meldet die Prüfung `invalid:<id>`
  (Topf `interval_files`, kein Objekt: `not_an_object`), und nichts wird
  eingespielt. Der Bericht zählt die Reihen unter `interval_files`.
- Bringt das Backup den Topf `intervals` mit, werden danach Reihen ohne
  Eintrag im neuen Index gelöscht — nach einem Haushaltswechsel liegen keine
  Reihen des vorigen herum. Ohne den Topf bleiben die vorhandenen Reihen
  unverändert (`untouched`).

### Beispielhaushalte *(v3.2.0)*

Neben dem bisherigen Demo-Haushalt mit allen Verbrauchsarten (jetzt
„Schaufenster“, `showcase`) gibt es vier Beispielhaushalte, je einen für eine
Persona des Einrichtungsassistenten
([Einrichtung](../einstieg/einrichtung.md)). Klasse C.

`GET /api/demo/status`:

```json
{ "available": true, "is_empty": true,
  "personas": ["mieterin", "etw-fernwaerme", "eigenheim-klassisch", "eigenheim-modern", "showcase"] }
```

- `available`: Das Schaufenster liegt bei.
- `is_empty`: noch nichts erfasst — keine Ablesung, Lieferung, kein Zeitraum
  und kein Vertrag in irgendeiner Verbrauchsart. Seit v3.2.0 zählen die
  Standardzähler einer Neuinstallation nicht mehr als Daten.
- `personas`: die Beispielhaushalte, deren Datei vorliegt; `showcase` steht
  immer darin.

`POST /api/demo/import` — Body `{force?, persona?}`, nur Verwalter:

| `persona` | Haushalt |
|---|---|
| `mieterin` | Mietwohnung: Strom, Heizwärme aus der monatlichen Verbrauchsinfo, Warm- und Kaltwasser, Mietverhältnis mit Nebenkostenabrechnungen |
| `etw-fernwaerme` | Eigentumswohnung mit Fernwärme (eigener Liefervertrag) und Strom |
| `eigenheim-klassisch` | Einfamilienhaus mit Gas, Strom und Wasser samt Gartenzähler |
| `eigenheim-modern` | Wärmepumpe, PV mit Speicher, Wallbox mit Ladevorgängen aus evcc |
| `showcase` oder leer | das Schaufenster mit allen Verbrauchsarten |

- Unbekannte Persona → `400` `errors.demo.personaUnknown`; sind schon Daten
  da und fehlt `force: true` → `400` `errors.demo.dataExists`.
- Ein Beispielhaushalt ersetzt den **ganzen** Haushalt: alle Töpfe aller
  Verbrauchsarten, auch die, die er nicht nutzt. Vorher legt die App einen
  Snapshot des jetzigen Stands an. Die Nutzungsstufe (`ui_level`) bleibt;
  `setup_pending` wird `false`, `setup_persona` die geladene Persona.
- Antwort: der Bericht des Backup-Imports, dazu `demo_import: true` und
  `persona`.

### Doku in der App: `GET /api/docs` *(v3.4.0)*

Die Hilfe liest die Dokumentation vom eigenen Server — dieselben
Markdown-Dateien wie auf GitHub, im Stand dieses Release — und rendert sie
selbst (`public/js/lib/markdown.js`, Ansicht `#/help/doc/<pfad>`).

`GET /api/docs?path=anleitungen/kalender.md`

```json
{ "path": "anleitungen/kalender.md", "lang": "de", "mirror": "en/anleitungen/kalender.md",
  "root_files": ["CHANGELOG.md", "CONTRIBUTING.de.md", "CONTRIBUTING.md", "CREDITS.md",
                 "LICENSE", "SECURITY.md", "TRADEMARKS.md"],
  "markdown": "# Kalender …" }
```

- `path` ist ein Schlüssel der **Freigabeliste**: Seiten unter `docs/`
  (englisch unter `en/`) ohne die Weiterleitungen alter Pfade, dazu eine feste
  Liste von Dateien im Wurzelverzeichnis als `root/<name>` — `CHANGELOG.md`
  (und `CHANGELOG.en.md`, sobald es sie gibt), `CONTRIBUTING.md`,
  `CONTRIBUTING.de.md`, `SECURITY.md`, `CREDITS.md`, `TRADEMARKS.md`, `LICENSE`.
- `lang` ist `de`, `en` oder `mul` (beide Sprachen in einer Datei), `mirror`
  dieselbe Seite in der anderen Sprache oder `null`. `root_files` nennt die
  ausgelieferten Dateien des Wurzelverzeichnisses: Links der Doku dorthin
  bleiben in der App, alle anderen Dateien des Repositorys (etwa `roadmap.md`
  oder die Vorlagen unter `deploy/`) öffnen GitHub im Tag dieser Version.
- Alles andere — `..`, `%2e%2e`, absolute Pfade, `docs/…`, unbekannte Seiten —
  ergibt `404` `errors.docs.notFound`. Der Pfad wird nie mit einem Verzeichnis
  verkettet, symbolische Links stehen nicht in der Liste. Eine Weiterleitung
  (alter Pfad seit v2.14.0) nennt ihr Ziel in `detail.moved_to`; die App folgt
  ihm.
- `GET /api/docs/asset?path=ui/screenshots/dashboard.png&v=<Version>` liefert nur
  PNG unter `docs/ui/screenshots/`, mit `Content-Type: image/png` und
  `X-Content-Type-Options: nosniff`. Mit der Version dieses Release in `v`
  gilt `Cache-Control: private, max-age=31536000, immutable`, sonst
  `private, no-cache`.
- Anmeldung wie jede andere Route — die Doku ist nicht öffentlich. Der Service
  Worker hält gelesene Seiten und ihre Bilder offline vor.
- Direkt unter `/docs/…` bleibt die Doku gesperrt (Apache, nginx,
  `router.php`). Im Docker-Image liegt sie seit v3.4.0, ohne
  `docs/assets/promo/`.

### `GET|HEAD /api/health` *(N1003; v2.6.0 erweitert)*

```json
{ "status": "ok", "version": "2.10.0", "schema_version": "1.6.0",
  "data_dir_writable": true, "migrations_pending": 0,
  "data_initialized_at": "2026-09-24T23:58:03+02:00",
  "php_version": "8.4.12", "timezone": "Europe/Berlin",
  "last_ingest": { "m_strom_main": "2026-09-20" },
  "checks": {
    "data_dir_writable": { "ok": true, "level": "ok" },
    "schema":            { "ok": true, "level": "ok" },
    "files":             { "ok": true, "level": "ok", "corrupt": [] },
    "disk":              { "ok": true, "level": "ok", "free_mb": 736175 },
    "temp_files":        { "ok": true, "level": "ok", "removed": 0 } } }
```

`status` ∈ `ok` · `degraded` (ausstehende Migration, unter 50 MB frei) ·
`error` (nicht schreibbar, beschädigte Datei, Daten neuer als die App, unter
5 MB frei) — bei `error` mit HTTP `503`, damit Docker-HEALTHCHECK und Monitore
die Störung erkennen. `migrations_pending` zählt seit v2.6.0 die
ausstehenden Migrationsschritte (bis v2.5.3 nur `0` oder `1`; `0` heißt
weiterhin „nichts zu tun"). Ist die Anmeldung eingeschaltet und der Aufrufer
nicht angemeldet, gibt es nur `{status, version}`.

### Parametergrenzen *(v2.6.0)*

Bisher still übernommen, jetzt `400` mit `code` und — bei der Prognose —
`detail {param, value, range}`:

| Endpunkt | Parameter | erlaubt |
|---|---|---|
| `…/forecast` | `forecast_months` | 1–60 |
| | `temp_offset` | −30 bis +30 °C |
| | `price_factor` | 0–10 |
| | `model` | `linear`, `polynomial`, `robust`, `segmented`, `sigmoid` |
| | `co2_scenario_eur_t` | 0–1000 €/t, leer = aus (v3.1.0) |
| | `co2_scenario_from` | 2021–2100 (v3.1.0) |
| `/api/co2-costs`, `/api/co2-split`, `/api/reports/co2-split.pdf` | `year` | 2021–2100, Standard Vorjahr, sonst `errors.co2.yearInvalid` (v3.1.0) |
| `…/bill-check` | `from`, `to` | ISO-Datum, `from` < `to`, sonst `errors.billCheck.invalidRange` |
| `/api/reports/yearly.pdf` | `year` | 2000–2100 |
| | `inline` | `1` = anzeigen statt herunterladen (v2.11.0) |
| `/api/reports/yearly` | `year` | 2000–2100 (v3.1.0) |
| `/api/reports/yearly…` | `utilities` | kommagetrennte Verbrauchsarten, sonst `errors.common.unknownUtility` (v3.4.0) |
| | `lang` | Sprachcode; unbekannt = Sprache der Anfrage (v3.4.0) |
| | `detail` | `1` = mit Anhang (v3.4.0) |
| `/api/reports/ev-charging…` | `year` | 2017–2100, Standard Vorjahr, sonst `errors.evReport.yearInvalid` (v3.1.0) |
| | `method` | `contract`, `flat`, sonst `errors.evReport.methodInvalid` |
| | `flat_ct` | 0–200 ct/kWh, sonst `errors.evReport.flatInvalid` |
| `/api/ev-sessions` | `year` | 2017–2100 oder leer (alle Jahre), sonst `errors.evReport.yearInvalid` (v3.2.0) |
| `…/import-evcc`, `…/sync-evcc` | `counters` | `auto` (Standard), `energy`, `none`, sonst `errors.evcc.countersInvalid` (v3.2.0) |
| `/api/heat-pump` | `year` | 1990–2100, Standard Vorjahr, sonst `errors.heatPump.yearInvalid` (v3.1.0) |
| `/api/flexibility`, `…/sync-smard-intervals` | `year` | 2018–2100, Standard Vorjahr, sonst `errors.flexibility.yearInvalid` (v3.3.0) |
| `…/sync-smard-intervals` | `month` | optional 1–12: nur dieser Monat (die Oberfläche lädt Monat für Monat mit Fortschritt), sonst `errors.flexibility.monthInvalid` (v3.5.1) |
| `POST /api/ingest` | `timestamp` | ISO 8601 mit Zeitzone oder leer, sonst `errors.ingest.timestampFormat` (v3.3.0) |
| `/api/energy-flow/day` | `date` | Kalendertag JJJJ-MM-TT, Jahr 2000–2100, Pflicht, sonst `errors.energyFlow.dateInvalid` (v3.5.0) |
| `/api/energy-flow/typical-day` | `month` | JJJJ-MM, Jahr 2000–2100, Pflicht, sonst `errors.energyFlow.monthInvalid` (v3.5.0) |
| `/api/benchmarks/comparison` | `year` | Jahreszahl, Standard Vorjahr (v3.1.0) |
| `/api/export/…` | `format` | `1` (Standard) oder `local`, sonst `errors.export.formatInvalid` (v3.1.0) |
| | `lang` | Sprachkürzel für `format=local`; unbekannt = Standardsprache |
| `POST /api/temperatures` | `avg`, `min`, `max` | Zahlen, Pflicht |
| `…/sync-open-meteo` | `start`, `end` | ISO-Datum, `start` ≤ `end` |

> Vollständige Beispiele zu Auth + Ingest und die Schritt-für-Schritt-Einrichtung
> in Home Assistant: [`docs/HOME-ASSISTANT.md`](../anleitungen/home-assistant.md) und
> [`docs/API.md`](api-beispiele.md).

### Zählergruppen *(F1006, v1.8.0)*

`GET/POST /api/utility/{u}/meter-groups`, `PATCH/DELETE …/{groupId}` sowie
`POST …/meter-groups/merge` („Zu Gruppe zusammenfassen“). Mitgliedschaft wird über
`meter_group_id` am Zähler gesetzt, nicht in der Gruppe. Seit v3.1.0 kann eine
Gruppe einen Vertrag tragen, und die Auswertungen gibt es für die ganze Gruppe
(`…/meter-groups/{id}/consumption` usw., [Gruppenvertrag](#gruppenvertrag-v310));
löschen lässt sie sich dann erst, wenn kein Vertrag mehr an ihr hängt.
Subzähler werden über `parent_meter_id` am Zähler verknüpft (siehe
[Datenmodell](datenmodell.md) und
[Meter-Topologie](../verstehen/13-meter-topologie.md)).

---

[← Architektur](../entwicklung/architektur.md) ·
[Datenmodell →](datenmodell.md)
