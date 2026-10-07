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
`waerme` (Heizwärme). Stand: **135 Routen**,
v3.1.0 — `ReleaseConsistencyTest` prüft, dass jede registrierte Route in der
Tabelle unten steht (DE und EN).

> Ausführliche Request-/Response-Beispiele für die meistgenutzten Endpunkte
> stehen in [`docs/API.md`](api-beispiele.md). Maßgeblich für Pfade und Felder ist
> **dieses** Dokument.

### Statuscodes aller Endpunkte

| Code | Wann |
|------|------|
| `400` | Ungültige Eingabe. Seit v2.5.3 auf allen Schreibpfaden: ein Datum, das kein Kalenderdatum ist (`2026-02-30`, Text), oder ein Betrag/Zählerstand, der keine Zahl ist. Bis v2.5.2 wurde beides gespeichert. Seit v2.6.0 auch Abfrageparameter außerhalb ihres Bereichs (Prognose, Jahresbericht, Temperatur-Sync). *(v3.1.0)* Beleg mit falschem Inhalt, zu groß oder Speicher voll (`errors.attachment.*`) — auch ein Upload über der PHP-Grenze `post_max_size`, dann mit dieser Grenze in der Meldung (`errors.attachment.size`); Texterkennung nicht eingetragen oder nicht im Heimnetz (`errors.ocr.off`, `errors.ocr.notLocal`); Zählerstand auf einem Zähler mit Verbrauch je Zeitraum (`errors.reading.periodMeter`), sich überschneidende Zeiträume (`errors.period.overlap`), Fehler im Mietverhältnis (`errors.tenancy.*`); ungültiges Jahr beim CO₂-Preis (`errors.co2.yearInvalid`), Fehler in einer Versorgerrechnung (`errors.bill.periodInvalid`, `…amountInvalid`, `…noResult`, `…noContract`), Rechnungsprüfung für eine Art ohne Prüfung (`errors.billCheck.unsupportedUtility`), ungültige Markt- oder Messlokations-ID (`errors.meter.maloInvalid`, `…meloInvalid`), Leistungspreis ohne Anschlussleistung (`errors.contract.capacityMissing`), Zähler löschen, an dem noch Zeiträume oder Rechnungen hängen (`errors.meter.hasPeriods`, `…hasBills`); Gruppenvertrag auf eine ungeeignete Gruppe oder mit Preisen für Fremde (`errors.contract.targetInvalid`), ein Mitglied mit eigenem Vertrag im selben Zeitraum (`errors.contract.groupMemberOverlap`), Gruppe mit Verträgen löschen (`errors.meter.groupHasContracts`), dynamischer Tarif als echter Vertrag oder nicht bei Strom (`errors.contract.dynamicShadowOnly`), unbekanntes Preismodell (`errors.contract.priceModelInvalid`), Monatspreise ohne lesbare Zeile oder für Wasser und Einspeisung (`errors.contract.priceImportEmpty`, `…priceImportUnsupported`), Börsenpreise ohne Werte (`errors.marketPrices.noRows`), Ladestrom-Nachweis ohne zahlenden Vertrag, ohne Pauschale oder mit ungültigen Parametern (`errors.evReport.*`), ungültiges Jahr der Wärmepumpe (`errors.heatPump.yearInvalid`), Verknüpfung mit einem Stromzähler ohne Rolle Wärmepumpe (`errors.meter.heatPumpLinkInvalid`), ungültige PV-Angabe am Zähler (`errors.meter.valueInvalid`), Zeitreihe mit unpassender Zuordnung oder ohne Anfangsstand (`errors.import.mappingInvalid`, `…anchorMissing`) — alle v3.1.0. |
| `401` | `/api/ingest` bei gesetztem Token ohne oder mit falschem Bearer-Header. *(v2.6.0)* Mit eingeschalteter Anmeldung: jede nicht öffentliche Route ohne Sitzung oder API-Schlüssel (`errors.auth.required`); falsches Passwort. *(v3.1.0)* Ein Kalender-Schlüssel auf einer anderen Route als `/api/calendar.ics`, ein `read`- oder `admin`-Schlüssel als `?token=` im Kalender-Link. |
| `403` | *(v2.5.3)* Schreibende Anfrage (`POST`/`PUT`/`PATCH`/`DELETE`) aus dem Browser einer **fremden** Webseite — geprüft über `Sec-Fetch-Site`, ersatzweise `Origin` gegen `Host`. Anfragen ohne diese Kopfzeilen (Home Assistant, curl, Skripte) sind nicht betroffen. *(v2.6.0)* Schreibende Anfrage mit einem Lese-Schlüssel (`errors.auth.readOnlyKey`). |
| `404` | Unbekannte Route oder unbekannter Datensatz. Seit v2.6.0 einheitlich auch für Datensätze in der URL (bis v2.5.3 teils 400). *(v3.1.0)* Auch unbekannter Zeitraum (`errors.period.notFound`), Mietverhältnis (`errors.tenancy.notFound`), Abrechnung (`errors.tenancy.statementNotFound`) oder Versorgerrechnung (`errors.bill.notFound`). |
| `405` | *(v2.6.0)* Pfad bekannt, Methode nicht — mit Kopfzeile `Allow`. `HEAD` wird wie `GET` beantwortet, `OPTIONS` mit `204` und `Allow`. Bis v2.5.3: `404`. |
| `409` | *(v2.6.0)* Der Sicherungs-Snapshot vor einem Import/Einspielen ist gescheitert (`errors.backup.snapshotFailed`) — mit `?allow_without_snapshot=1` trotzdem möglich. Passwort oder Anmeldemodus sind über die Umgebung festgelegt. |
| `421` | *(v2.6.0)* Hostname nicht in `ET_ALLOWED_HOSTS` (nur, wenn gesetzt; IP-Adressen und `localhost` sind immer erlaubt). |
| `422` | *(v3.1.0)* PDF-Jahresbericht, CO₂-Anschreiben oder Ladestrom-Nachweis in einer Sprache, die die eingebauten PDF-Schriften nicht setzen können (`errors.report.pdfUnsupportedLanguage`) — die Druckansicht kann es. |
| `429` | *(v2.6.0)* Anmeldung nach fünf Fehlversuchen innerhalb von 15 Minuten für 5 Minuten gesperrt (`errors.auth.locked`). |
| `502` | Ein Dienst außerhalb der App antwortet nicht oder unbrauchbar — ein Fehler auf der anderen Seite, keine falsche Eingabe: die Ortssuche (`errors.temperature.geocodeFailed`); *(v3.1.0)* `POST /api/ocr/reading`, wenn der eigene Texterkennungsdienst nicht in der Zeit antwortet, nicht erreichbar ist oder nichts Verwertbares liefert (`errors.ocr.timeout`, `errors.ocr.unreachable`, `errors.ocr.badAnswer`); *(v3.1.0)* `POST /api/market-prices/sync-smard`, wenn SMARD nicht erreichbar ist oder keine Werte liefert (`errors.marketPrices.syncFailed`). |
| `503` | *(v2.5.3)* Eine Datendatei ist beschädigt (kein gültiges JSON). Die Datei bleibt unverändert, daneben liegt eine Quarantäne-Kopie `<datei>.corrupt-<prüfsumme>`. Bis v2.5.2 wurde sie als leer gelesen und beim nächsten Schreiben überschrieben. Seit v3.1.0 steht die Meldung in der Sprache der Anfrage (bis v3.0 immer deutsch); `code` bleibt `errors.storage.corrupted`. *(v2.6.0)* Die Daten stammen von einer **neueren** Version (etwa nach dem Zurückdrehen des Image-Tags): alle Routen außer `/api/health`, geschrieben wird nichts (`errors.storage.dataTooNew`). `/api/health` selbst antwortet `503`, wenn `status` = `error`. |
| `500` | Unerwarteter Fehler. Seit v2.6.0 mit `error_id`; dieselbe ID steht mit allen Einzelheiten im Server-Log. |

Schreibende Anfragen laufen seit v2.5.3 nacheinander (Sperre auf
`data/.write.lock`): Ein Home-Assistant-Push während einer Eingabe verliert
keine Änderung mehr.

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
| Sitzung | den Browser | Cookie `et_session` (HttpOnly, SameSite=Strict, 30 Tage) nach `POST /api/session` |
| API-Schlüssel | Skripte, andere Programme, Home Assistant | `Authorization: Bearer etk_…`; Bereich `read` (nur `GET`) oder `admin` |
| Kalender-Schlüssel *(v3.1.0)* | Kalender-Apps | API-Schlüssel mit Bereich `calendar`, **nur** als `?token=etk_…` und **nur** für `GET /api/calendar.ics` |
| Proxy | Betrieb hinter Authelia, Authentik, Home-Assistant-Ingress o. Ä. | `ET_AUTH=proxy`; Benutzer aus `Remote-User`/`X-Forwarded-User`/`X-Remote-User`, seit v3.1.0 auch `X-Remote-User-Name`/`X-Remote-User-Id` (Home Assistant), nur von Adressen in `ET_TRUSTED_PROXIES` |

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

### Stabilitätszusage *(v2.6.0)*

Wer auf der API aufbaut — Home Assistant, Skripte, eigene Auswertungen —,
braucht eine Zusage, was sich ändern darf. Drei Klassen:

| Klasse | Umfang | Zusage |
|---|---|---|
| **A — Schnittstellen für Fremdsysteme** | `POST /api/ingest` (auch als Stapel), `GET /api/health`, `GET /api/summary` (`summary_version: 1`, v3.1.0), `GET /api/calendar.ics` (Form und UIDs, v3.1.0), Backup-Format 3.0 (`/api/backup/export`, `/api/backup/import`), CSV-Exporte im Format 1 und die CSV-Importe ([CSV-Formate](#csv-formate-v310)), Stammdaten (`meters`, `readings`, `contracts`, `deliveries`, `reminders`, `settings`, `temperatures`; seit v3.1.0 `periods` samt `periods.csv` und Zeitraum-Import, `tenancies` und `statements`), Belege hochladen und abrufen (`POST /api/attachments`, `GET /api/attachments/{id}`, v3.1.0), Fehlerhülle mit `code` | Nur additive Änderungen. Umbenennen oder Entfernen erst mit einer **Major-Version**, angekündigt mindestens eine Minor-Version vorher im CHANGELOG unter „Deprecated". Alte Feldnamen bleiben als Alias gültig. |
| **B — Auswertungen** | Verbrauch, Saldo, Prognose, Tarifvergleich/-wechsel, Rechnungsprüfung, Effizienz, Empfehlungen, PV/Saldo, `readings-overview`, `agenda` (v3.1.0), Budget des Mietverhältnisses (`tenancies/{id}/budget`, v3.1.0), CO₂-Preis und Aufteilung (`co2-costs`, `co2-split`, `reports/co2-split.pdf`, v3.1.0), Versorgerrechnungen (`bills` samt `check` und `book`, v3.1.0), die Auswertungen einer Zählergruppe (`meter-groups/{id}/…`, v3.1.0), Ladestrom-Nachweis (`reports/ev-charging` als JSON, CSV und PDF, v3.1.0), Jahresarbeitszahl (`heat-pump`, v3.1.0), Einordnung (`benchmarks/comparison`, v3.1.0) | Dokumentierte Felder bleiben mit Namen und Bedeutung erhalten; neue kommen hinzu. **Werte** können sich ändern, wenn eine Berechnung korrigiert wird — das steht im CHANGELOG. |
| **C — Oberfläche** | `session`, `auth/token`, `auth/keys`, `backup/snapshots`, `diagnostics`, `demo`, `migration/v09`, `countries`, Belegliste und Löschen (`GET /api/attachments`, `DELETE /api/attachments/{id}`) und `ocr/reading` (v3.1.0), Börsenstrompreise (`market-prices` samt Import und SMARD-Abruf), Monatspreise aus einer Datei (`contracts/{id}/prices/import-csv`) und Zeitreihen mit Spaltenzuordnung (`meters/{id}/import-series`, alle v3.1.0) | Für die eigene Oberfläche gebaut; Änderungen möglich, stehen aber im CHANGELOG. |

Anlass: v2.0.0 hat `verdict` von „Nachzahlung/Erstattung" still auf Schlüssel
(`surcharge`/`refund`/`balanced`) umgestellt — ohne Ankündigung. Das soll
nicht wieder passieren.

---

## 1. Vollständige Routen-Übersicht

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/health` | Health-Check: `status` ok/degraded/error, Prüfungen, letzter Ingest (HTTP 503 bei `error`); auch `HEAD` |
| GET | `/api/session` | Anmeldemodus, angemeldet?, per Umgebung festgelegt? *(v2.6.0)* |
| POST | `/api/session` | Anmelden `{password}` → Sitzungs-Cookie *(v2.6.0)* |
| DELETE | `/api/session` | Abmelden *(v2.6.0)* |
| POST | `/api/session/password` | Passwort setzen/ändern `{password, current?}` — schaltet die Anmeldung ein *(v2.6.0)* |
| DELETE | `/api/session/password` | Anmeldung ausschalten `{current}` *(v2.6.0)* |
| GET | `/api/auth/keys` | API-Schlüssel (ohne Klartext) *(v2.6.0)* |
| POST | `/api/auth/keys` | Schlüssel erzeugen `{name, scope: read\|admin\|calendar}`; Klartext einmalig *(v2.6.0; `calendar` seit v3.1.0)* |
| DELETE | `/api/auth/keys/{id}` | Schlüssel widerrufen *(v2.6.0)* |
| GET | `/api/diagnostics` | Systemstatus, Schreibrechte, Schema |
| GET | `/api/utilities` | Liste der Verbrauchsarten + Konfiguration; seit v2.13.0 je Art `has_contracts`, `has_advance_payment_contracts` und `accounting_kind` (`consumption`, `feed_in`, `generation`); seit v3.1.0 `meter_roles` bei Arten mit Zählerrollen und `supports_bill_check` (Rechnungsprüfung möglich: Gas, Strom, Wasser, Fernwärme) |
| GET | `/api/manifest` | *(v3.1.0)* Web-App-Manifest in einer Sprache (`?lang=`, sonst Sprache des Geräts bzw. der Installation); ohne Anmeldung erreichbar, enthält keine Daten. Klasse C |
| GET | `/api/settings` | Einstellungen |
| PATCH | `/api/settings` | Einstellungen ändern |
| GET | `/api/countries` | Länderprofile: Voreinstellungen je Land *(v2.7.0)*; seit v3.1.0 mit Rechnungsbegriffen (`bill_terms`) und amtlichem Tarifvergleich (`comparison_portal`) — s. u. |
| GET | `/api/settings/default-updates` | Korrigierte Standardwerte, die diese Installation noch nicht nutzt (CO₂, Wasser-Referenz) *(v2.10.0)* — s. u. |
| GET | `/api/temperatures` | Tagestemperaturen (Map) |
| POST | `/api/temperatures` | Tagesdatum upsert |
| POST | `/api/temperatures/import-csv` | CSV-Import; `TT.MM.JJJJ;Mittel;Min;Max`, auch Tabulator und das alte Format mit Anführungszeichen — ein Trenner je Zeile (v2.12.0); seit v3.1.0 jedes Exportformat und Datum als `T/M/JJJJ`, `T-M-JJJJ` oder ISO — [Import](#import-v310) |
| GET | `/api/geocode` | Ortssuche für den Standort; `?q=` (2–80 Zeichen) — v2.12.0, s. u. |
| POST | `/api/temperatures/sync-open-meteo` | Open-Meteo-Abgleich; `?start=&end=&reload=1&auto=1` (v2.8.0) — s. u. |
| DELETE | `/api/temperatures/{date}` | Tagesdatum löschen |
| GET | `/api/utility/{u}/meters` | Zähler/Tanks |
| POST | `/api/utility/{u}/meters` | anlegen; seit v3.1.0 mit `role` und `capture` — [s. u.](#zähler-role-capture-v310-additiv) — sowie `malo_id`, `melo_id` ([s. u.](#zähler-malo_id-melo_id-v310-additiv)); bei PV-Erzeugung `plug_in`, `investment_eur`, `commissioned_on`, `battery_capacity_kwh` ([PV](#pv-speicher-balkonkraftwerk-amortisation-v310-additiv)), bei Heizwärme `heat_pump_meter_ids` ([Wärmepumpe](#jahresarbeitszahl-der-wärmepumpe-v310)) |
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
| GET | `/api/utility/{u}/readings` | Ablesungen |
| POST | `/api/utility/{u}/readings` | anlegen; seit v3.1.0 mit `client_ref` (gleiche Kennung am selben Zähler → `200` mit `duplicate: true` statt eines zweiten Stands) und `attachment_id` (Foto) — [s. u.](#ablesungen-client_ref-attachment_id-v310-additiv) |
| PATCH | `/api/utility/{u}/readings/{id}` | ändern; seit v3.1.0 auch `attachment_id` (`null` löst das Foto); auf einen Zähler mit Verbrauch je Zeitraum umhängen → `400` `errors.reading.periodMeter` |
| DELETE | `/api/utility/{u}/readings/{id}` | löschen (ein Foto wird gelöst und nach 24 Stunden aufgeräumt) |
| POST | `/api/utility/{u}/meters/{id}/readings/import-csv` | CSV-Bulk-Import; `?dry_run=1` liest nur (Vorschau, v2.12.0); seit v3.1.0 Kopfzeilen und Datumsschreibweisen aller Sprachen — s. u. |
| POST | `/api/utility/{u}/meters/{id}/import-series` | *(v3.1.0)* Zeitreihe aus einem Portal mit Spaltenzuordnung `{csv, mapping}`, zu Tageswerten verdichtet; `?dry_run=1` liest nur — [Zeitreihe importieren](#zeitreihe-importieren-v310) |
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
| GET | `/api/utility/{u}/contracts` | Verträge |
| POST | `/api/utility/{u}/contracts` | anlegen; seit v3.1.0 Fernwärme mit Leistungs- und Messpreis, Antwort ggf. mit `warnings` — [s. u.](#verträge-fernwärme-fixkosten-warnings-v310-additiv); für eine Zählergruppe mit `meter_group_id` ([Gruppenvertrag](#gruppenvertrag-v310)); Strom mit `grid_reduction`, Preismodell `price_model`/`dynamic`, Einspeisung mit `revenue_statements` ([s. u.](#verträge-reduziertes-netzentgelt-preismodell-gutschriften-v310-additiv)) |
| GET | `/api/utility/{u}/contracts/{id}` | einzeln |
| PATCH | `/api/utility/{u}/contracts/{id}` | ändern; wie beim Anlegen |
| DELETE | `/api/utility/{u}/contracts/{id}` | löschen |
| POST | `/api/utility/{u}/contracts/{id}/prices/import-csv` | *(v3.1.0)* Monatspreise aus einer Datei (`monat;ct_kwh[;grundpreis]`), setzt `price_model: "monthly"`; `?dry_run=1` liest nur |
| GET | `/api/market-prices` | *(v3.1.0)* Großhandelspreise Strom (Day-Ahead DE/LU) als Monatsmittel, mit Namensnennung — [Börsenstrompreise](#börsenstrompreise-und-dynamik-check-v310) |
| POST | `/api/market-prices/import-csv` | *(v3.1.0)* Börsenpreise aus einer Datei (SMARD-Download oder `JJJJ-MM;€/MWh`); `?dry_run=1` liest nur |
| POST | `/api/market-prices/sync-smard` | *(v3.1.0)* Monatswerte von SMARD holen — nur auf Knopfdruck |
| GET | `/api/utility/{u}/consumption` | Monatsverbrauch (utility-weit) |
| GET | `/api/utility/{u}/meters/{id}/consumption` | Verbrauch + Anomalien + Regressionen |
| GET | `/api/utility/{u}/meters/{id}/contract-status` | Saldo je Vertrag; seit v2.5.1 mit `special_payments[]` (Einzelposten, nur Gas/Strom/Fernwärme); seit v2.8.0 nach Kalender bis heute, seit v2.9.0 tagesgenau mit Kündigungsstichtag — s. u. |
| GET | `/api/utility/{u}/meters/{id}/forecast` | Prognose mit Unsicherheitsband, Klimanormal und Hinweisen (v2.8.0); seit v3.1.0 CO₂-Preis-Szenario `?co2_scenario_eur_t=&co2_scenario_from=` — s. u. |
| GET | `/api/utility/{u}/meters/{id}/tariff-comparison` | Tarifvergleich echt vs. Schatten (Rückblick) |
| GET | `/api/utility/{u}/meters/{id}/tariff-switch` | Wechselentscheidung ab Wechseltermin; optional `?switch_date=YYYY-MM-DD` |
| GET | `/api/utility/{u}/meters/{id}/bill-check` | Rechnungsprüfung: Abschnitte je Ablesung und Brennwertwechsel bzw. Preisstichtag, mit Kosten, `?from=&to=` (F1012; bis v3.0 nur Gas, seit v3.1.0 Gas, Strom, Wasser, Fernwärme, sonst 400) — [s. u.](#get-apiutilityumetersidbill-checkfromto-f1012-v250-alle-arten-seit-v310) |
| GET | `/api/utility/{u}/bills` | *(v3.1.0)* Versorgerrechnungen, die neueste zuerst; `?meter_id=` nur dieser Zähler — [Versorgerrechnungen](#versorgerrechnungen-v310) |
| POST | `/api/utility/{u}/bills` | *(v3.1.0)* Rechnung erfassen; `201` |
| PATCH | `/api/utility/{u}/bills/{id}` | *(v3.1.0)* Rechnung ändern |
| DELETE | `/api/utility/{u}/bills/{id}` | *(v3.1.0)* Rechnung löschen; Belege werden frei, eine gebuchte Sonderzahlung bleibt im Vertrag |
| GET | `/api/utility/{u}/bills/{id}/check` | *(v3.1.0)* eigene Rechnung gegen die des Versorgers: Abweichung, Urteil, Gründe |
| POST | `/api/utility/{u}/bills/{id}/book` | *(v3.1.0)* Ergebnis als Sonderzahlung im Vertrag buchen (einmal je Rechnung) |
| GET | `/api/benchmarks/efficiency` | Effizienzklasse pro Heizquelle; seit v2.10.0 mit Abdeckung und energieausweis-naher Kennzahl — s. u. |
| GET | `/api/benchmarks/comparison` | *(v3.1.0)* Einordnung des Jahresverbrauchs an eigenen Vergleichswerten (Haushaltsstrom, Heizung je m²), `?year=` (Standard Vorjahr) — [Einordnung](#einordnung-get-apibenchmarkscomparison-v310) |
| GET | `/api/heat-pump` | *(v3.1.0)* Jahresarbeitszahl je Wärmepumpe: Wärme ÷ Strom je Monat und Jahr, `?year=` (Standard Vorjahr) — [Wärmepumpe](#jahresarbeitszahl-der-wärmepumpe-v310) |
| GET | `/api/recommendations` | statistische Empfehlungen |
| POST | `/api/recommendations/{id}/dismiss` | Empfehlung ausblenden |
| DELETE | `/api/recommendations/{id}/dismiss` | Ausblenden zurücknehmen (v2.12.0); eine nicht ausgeblendete ID ist kein Fehler |
| GET | `/api/reminders` | Termine + Fälligkeitsstatus |
| POST | `/api/reminders` | anlegen |
| PATCH | `/api/reminders/{id}` | ändern; seit v2.12.0 auch `last_done` (Datum oder `null`) — für „Rückgängig" nach „Erledigt" |
| DELETE | `/api/reminders/{id}` | löschen |
| POST | `/api/reminders/{id}/done` | erledigt, Recurrence fortschreiben |
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
| GET | `/api/agenda` | *(v3.1.0)* Fristen und Termine der nächsten `?days=` Tage (Standard 90) samt Überfälligem; Quelle für „Zu tun“ im Dashboard — [Agenda](#get-apiagendadays90-v310) |
| GET | `/api/calendar.ics` | *(v3.1.0)* Dieselben Ereignisse als Kalender-Abo (iCalendar, 365 Tage) — [Kalender](#get-apicalendarics-v310) |
| GET | `/api/summary` | *(v3.1.0)* Kennzahlen je Zähler für Home Assistant und Skripte, `?utility=&meter=` — [Kennzahlen](#get-apisummary-v310) |
| GET | `/api/attachments` | *(v3.1.0)* Belege: Index und Speicherbelegung `usage` — [Belege](#belege-und-texterkennung-v310) |
| POST | `/api/attachments` | *(v3.1.0)* Beleg hochladen: Datei als roher Body, `?kind=reading_photo` (bzw. `bill_pdf`, `statement_pdf`, `other`), optional `&name=`; `201` mit dem Indexeintrag |
| GET | `/api/attachments/{id}` | *(v3.1.0)* Datei eines Belegs (Foto, PDF) |
| DELETE | `/api/attachments/{id}` | *(v3.1.0)* Beleg löschen; der Verweis am Datensatz verschwindet mit |
| POST | `/api/ocr/reading` | *(v3.1.0)* Zählerstand aus einem Ablesefoto lesen `{attachment_id}` — über den eigenen Texterkennungsdienst im Heimnetz, `502` bei Fehlern des Dienstes |
| GET | `/api/export/{u}/monthly.csv` | Monatsaggregate als CSV; `?format=1` (Standard) oder `?format=local&lang=` *(v3.1.0)* — [CSV-Formate](#csv-formate-v310) |
| GET | `/api/export/{u}/readings.csv` | Ablesungen als CSV (kumulativ); `format`, `lang` wie oben |
| GET | `/api/export/{u}/deliveries.csv` | **v1.4.2** Lieferungen als CSV (Heizöl/Pellets); `format`, `lang` wie oben |
| GET | `/api/export/{u}/periods.csv` | *(v3.1.0)* Verbrauch je Zeitraum als CSV, alle Zähler der Art; `format`, `lang` wie oben |
| GET | `/api/export/temperatures.csv` | Temperaturreihe als CSV; `format`, `lang` wie oben |
| GET | `/api/backup/export` | Voll-Backup JSON; seit v3.1.0 mit den Belegen (`attachment_files`), `?attachments=0` ohne die Dateien — [s. u.](#snapshots-und-import-v260) |
| POST | `/api/backup/import` | Backup zurückspielen; `?dry_run=1` prüft nur, `?allow_without_snapshot=1` s. 409 |
| POST | `/api/backup/snapshot` | Snapshot ablegen |
| GET | `/api/backup/snapshots` | Snapshots: Name, Größe, Zeitpunkt, Anlass *(v2.6.0)* |
| GET | `/api/backup/snapshots/{name}` | Snapshot herunterladen (Datei) *(v2.6.0)* |
| POST | `/api/backup/snapshots/{name}/restore` | Snapshot einspielen (vorher Sicherung des jetzigen Stands) *(v2.6.0)* |
| DELETE | `/api/backup/snapshots/{name}` | Snapshot löschen *(v2.6.0)* |
| POST | `/api/migration/v09/preview` | v0.9.0-Backup analysieren |
| POST | `/api/migration/v09/import` | v0.9.0-Backup übernehmen |
| GET | `/api/strom-saldo` | Strom-Saldo (Bezug − PV-Einspeisung), F1005 |
| GET | `/api/pv-summary` | PV-Eigenverbrauch + Autarkiequote, F1005; seit v2.10.0 über gemeinsam abgedeckte Monate, mit Ersparnis — s. u.; seit v3.1.0 Speicher, Amortisation, Annahme für ein Balkonkraftwerk und Hinweis zu § 51 EEG ([PV](#pv-speicher-balkonkraftwerk-amortisation-v310-additiv)) |
| GET | `/api/demo/status` | Demo-Daten verfügbar/Store leer? (F1007) |
| POST | `/api/demo/import` | Demo-Datensatz laden (F1007); seit v3.0.0 bis heute fortgeschrieben (Stände, Lieferungen, Temperaturen wie im Vorjahreszeitraum, Termine relativ zu heute) |
| GET | `/api/auth/token` | API-Token-Status (nie der Token selbst), F1009 |
| POST | `/api/auth/token` | Token erzeugen (einmalig Klartext), F1009 |
| DELETE | `/api/auth/token` | Token widerrufen → API wieder offen, F1009 |
| **POST** | **`/api/ingest`** | **idempotenter Zählerstand-Push für Home Assistant (F1009); seit v3.1.0 auch als Stapel bis 500 Stände** |

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
| `projection_factor` | Kalibrierung der Schätzung am jüngsten Niveau (0,5–1,5) |
| `balance_path` | *(v2.16.0)* nur beim laufenden Vertrag, sonst `null`: dieselbe Rechnung als Monatsreihe vom Vertragsbeginn bis zum Ende bzw. zur nächsten Abrechnung — je Monat `ym`, aufsummiert `cost` (gemessen + geschätzt + Grundpreis − Boni) und `paid` (Abschläge nach Plan − Sonderzahlungen netto), `balance = cost − paid`, `estimated` (enthält Tage nach der letzten Ablesung), `future` (Monat nach heute). Der letzte Punkt ist `projected_end_balance` |

**Geänderte Werte:** `advance_paid` zählt seit v2.8.0 die Abschläge nach
Kalender bis einschließlich des laufenden Monats (vorher nur Monate mit
Ablesung); `current_balance = cost_to_date − advance_paid +
special_payment_net`, `projected_end_balance = current_balance +
estimated_cost_remaining − advance_remaining`. `actual_cost`,
`actual_kwh_cost` und `actual_base_total` beschreiben weiterhin nur die
gemessenen Monate.

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
Wasser bleibt beim Vertrag des Monatsersten.

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

Bewusst **ohne Lastprofil**: Gerechnet wird mit dem Monatsmittel, als
verteile sich der Verbrauch gleichmäßig über den Tag. Abendverbrauch ist meist
teurer; ein echter dynamischer Tarif wird je Viertelstunde über ein
intelligentes Messsystem abgerechnet (§ 41a EnWG). Hintergrund:
[Strom → Dynamische Tarife](../verstehen/02-strom.md#dynamische-tarife-v310).

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
Erzeugung (ebenso wenig in Summen). Eigenverbrauch und Autarkie bleiben
definiert wie bisher.

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
  25.02.2025: Für Zeiten mit negativem Börsenpreis gibt es keine Vergütung,
  sobald ein intelligentes Messsystem eingebaut ist (§ 51 EEG); ausgeglichen
  wird über eine längere Förderdauer.

Die Einstellung `co2_pv_avoided` (g/kWh) ersetzt für PV-Erzeugung und
-Einspeisung den Strommix im vermiedenen CO₂ ([Einstellungen](einstellungen.md)).
Gutschriften des Direktvermarkters an der Einspeisung:
[Verträge](#verträge-reduziertes-netzentgelt-preismodell-gutschriften-v310-additiv).

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
| `tw_m3`, `tw_cost`, `sw_m3`, `sw_cost`, `nw_cost` | nur Wasser: Trinkwasser (Arbeits- und Grundpreis), Schmutzwasser (nur mit Basis Trinkwasser, sonst `null` — ein eigener Zähler wird nicht nachgebildet) und Niederschlagswasser. `energy_cost` = Trink- + Schmutzwasser je m³, `fixed_cost` = Grundpreis + Niederschlagswasser |

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
| `issued_on` | Rechnungsdatum (optional) — Datum der Buchung und Zugang für die CO₂-Frist |
| `invoice` | `energy_kwh` (bei Wasser `volume_m3`), `amount_eur` (Rechnungsbetrag brutto), `advances_paid_eur`, `result_eur` (**positiv = Nachzahlung**, negativ = Guthaben; ohne Angabe `amount_eur − advances_paid_eur`), `net` (optional, wird nur gespeichert). Jeder Wert optional; keine Zahl oder außerhalb des Bereichs → `400` `errors.bill.amountInvalid` |
| `items` | weitere Posten `{label, amount_eur, kind}` mit `kind` ∈ `levy` (Umlage), `fee` (Gebühr), `credit` (Gutschrift), `other` (Standard); Bezeichnung höchstens 120 Zeichen. Die App rechnet sie nicht nach |
| `co2` | optional, nur sinnvoll bei Gas: `{emissions_kg, cost_eur?, stated_factor?}` — Emissionen und CO₂-Betrag **netto** laut Rechnung, angegebener Faktor in kg/kWh. Gehen beim [CO₂-Preis](#co₂-preis-und-aufteilung-v310) dem Standardfaktor vor |
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
| `verdict` | `ok`, wenn die Menge um höchstens 1 % und der Betrag um höchstens 1 % **oder** 2 € abweicht; sonst `check`. `null`, wenn die Rechnung weder Menge noch Betrag nennt |
| `reasons` | mögliche Gründe: `estimated_reading_at_boundary` (geschätzter oder interpolierter Stand an einer Grenze), `price_change_inside`, `factor_change_inside`, `items_not_modelled` (weitere Posten übernommen, nicht nachgerechnet), `price_missing`, `gaps` (Abschnitte ohne Zählerstände) |
| `rows` | die Abschnitte der Rechnungsprüfung |

**Buchen — `POST …/bills/{id}/book`.** Legt das Ergebnis (`invoice.result_eur`)
als Sonderzahlung (F1003) im Vertrag an: positiv als `nachzahlung_ohne`,
negativ als `rueckzahlung_ohne` — **ohne Auswirkung auf den Abschlag** —,
Betrag ohne Vorzeichen, Datum = `issued_on`, sonst der Tag nach `period_to`,
Notiz „Jahresabrechnung {von} – {bis}“ in der Sprache der Anfrage. Vertrag:
`contract_id`, sonst der Vertrag, der am `period_to` galt, sonst der letzte des
Zählers — auch ein Gruppenvertrag der Gruppe, zu der der Zähler gehört (ohne
Schattenverträge). Antwort: die Rechnung mit `special_payment_id`
und `contract_id`. **Einmal je Rechnung** — ist schon gebucht, ändert ein
weiterer Aufruf nichts und liefert die Rechnung unverändert. Ohne Ergebnis
(fehlt oder 0) → `400` `errors.bill.noResult`, ohne Vertrag → `400`
`errors.bill.noContract`. Wer die Rechnung löscht, behält die gebuchte
Sonderzahlung im Vertrag.

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
      "source": "computed", "approx": false, "coverage_days": 365 } ],
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
| `price_eur_t` | maßgeblicher Preis des Jahres: Einstellung `co2_price_eur_t_years`, sonst Länderprofil (DE 2021: 25, 2022: 30, 2023: 30, 2024: 45, 2025: 55, 2026: 60), sonst der letzte bekannte Wert (`price.assumed: true`) |
| `cost_eur_net` | Emissionen / 1000 × Preis; mit Rechnung deren `co2.cost_eur` (netto, CO2KostAufG § 3 Abs. 3), sofern jede dieser Rechnungen ihn trägt |
| `cost_eur_gross` | netto × (1 + `vat`) |
| `ct_per_kwh` | brutto je kWh in ct |
| `approx` | `true` bei Heizwärme mit gerechnetem Wert — gezählt wird die Wärme, nicht der Brennstoff |
| `coverage_days` | Tage des Jahres mit Verbrauchsdaten |
| `total` | Summen aller Zeilen, `null` ohne Zeile |
| `per_m2` | Emissionen je m² Wohnfläche (auf eine Nachkommastelle) mit `stage` (1–10) und `landlord_share_pct` nach der Anlage zum CO2KostAufG; Fläche aus dem Mietverhältnis des Jahres (bei `wohnverhaeltnis: miete`), sonst `wohnflaeche_m2`. `null` ohne Zeilen oder Fläche |
| `price` | `{eur_t, assumed}` |
| `vat` | Umsatzsteuer auf den CO₂-Preis (`0.19`) |

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
| `emissions_kg`, `kg_per_m2`, `stage`, `landlord_share_pct` | Emissionen, je m² (eine Nachkommastelle), Stufe und Anteil des Vermieters |
| `co2_cost_eur`, `landlord_amount_eur` | CO₂-Kosten des Jahres und der Betrag des Vermieters |
| `source` | `self_supplied`: die Quellen der Zeilen aus `/api/co2-costs`, mit Komma verbunden (etwa `bill` oder `bill,computed`); `central`: `statement` |
| `checks[]` | `central`: `stage`, `share`, `amount` (Abweichung über 0,50 €), `missing_values` (Emissionen oder Fläche fehlen); `self_supplied`: `area_missing` (ohne Fläche keine Stufe) |
| `reductions[]` | `own_appliances`, `restriction_one`, `restriction_both` |
| `deadline` | nur `self_supplied`: Frist für die Erstattung (§ 6 Abs. 2) = Zugang der Gasrechnung, deren Zeitraum im Jahr endet (`issued_on`, sonst Tag nach dem Zeitraum), + 12 Monate; `null` ohne erfasste Gasrechnung |
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
                   "class": "C", "months_36": 36 },
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
energieausweis-nahe Kennzahl: Gas × 0,906 (Brennwert → Heizwert),
witterungsbereinigt (`weather_adjusted`), bezogen auf `area_m2` =
Wohnfläche × `area_factor` (1,2; 1,35 bei `gebaeudetyp` efh/rh mit
`beheizter_keller`), plus `dhw_surcharge` (20 bei
`warmwasser_dezentral`). `months_36` zählt die Monate mit Heizdaten in den
drei Jahren bis zum Bezugsjahr — ein Verbrauchsausweis verlangt 36.
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
             "persons": 2, "building": "house", "dhw_electric": false,
             "special_household": true },
  "heating": [ { "utility": "gas", "kwh_per_m2": 118.4, "weather_adjusted": true,
                 "complete": true, "own_value": 130, "delta_pct": -8.9 } ],
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
| `source` | `reference_source`, sonst `null` |
| `links` | die Seiten zur Selbstprüfung — nur für das Land Deutschland, sonst leer |

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
ersten 12, wie `/api/recommendations`). Auswahl wie im PDF: aktive
Verbrauchsarten, je Zähler mit Verbrauch im Jahr ein Eintrag.

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
aus dem Länderprofil (`ev_flat_rate_ct_years`, Deutschland 2026: 34 ct/kWh);
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
| `co2_claim_deadline` *(v3.1.0)* | Etagenheizung zur Miete: Anteil des Vermieters an den CO₂-Kosten einfordern (CO2KostAufG § 6 Abs. 2) — nur mit `case: self_supplied` und Betrag über 0 | `deadline` aus `/api/co2-split`: Zugang der Gasrechnung + 12 Monate | ab 30 Tagen vorher (`severity` dann `due`) |

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
  `objection_deadline-<abrechnung>` und `co2_claim_deadline-<jahr>`. Wird ein
  Termin erledigt, steht beim nächsten Abruf dasselbe Ereignis am neuen Datum.
- Vorab-Erinnerung (`VALARM`, `TRIGGER:-P<n>D`) für Termine, Kündigungsstichtage,
  Vertragsenden, Preisgarantien und Preiserhöhungen; `<n>` ist
  `reminder_warn_days_before` (Einstellung, Standard 14, `0` = keine). Die
  Fristen der Nebenkostenabrechnung und der CO₂-Erstattung (v3.1.0) tragen
  keine Vorab-Erinnerung.
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
        "period_end": "2027-03-31", "cancel_by": "2027-02-28", "days_to_cancel": 144,
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
| `agenda` | `due` = Einträge unter „Zu tun“, `overdue` = davon überfällig oder dringend, `next` = nächster Termin bzw. nächste Frist (`kind`, `date`, `title`) oder `null` |
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
| `contract` | laufender Vertrag (Arten mit Verträgen), sonst `null`: Preise und Abschlag, `balance` und `projected_end_balance` mit dem Vorzeichen von `contract-status` (positiv = Nachzahlung, bei der Einspeisung = Auszahlung), `verdict`, `period_end` (Ende bzw. nächste Abrechnung), `cancel_by`, `days_to_cancel`, `price_increase_from` |
| `forecast_12m` | Prognose der nächsten zwölf Monate: `value` in `consumption_unit`, `cost` (bei reiner Erzeugung `null`), `low`/`high` = Jahresband; `null` bei Heizöl/Pellets oder ohne gültige Prognose |
| `tank` | Heizöl/Pellets: `stock`, `capacity`, `unit`, `percent`, `estimated_from`; sonst `null` |

**Zugang.** Ohne Anmeldung offen. Mit Anmeldung ein API-Schlüssel mit Bereich
`read` als `Authorization: Bearer etk_…` (der Ingest-Token `et_…` gilt hier
nicht). Antwortkopf `Cache-Control: private, max-age=300`: Home Assistant fragt
periodisch, fünf Minuten genügen. `agenda.next.title` steht in der
Standardsprache der Installation, sofern die Anfrage kein `X-ET-Language`
schickt. Vorlage für Home Assistant:
[Home Assistant, Schritt 5](../anleitungen/home-assistant.md#schritt-5--werte-zurück-nach-home-assistant).

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
`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, Link-local (`169.254.0.0/16`,
`fe80::/10`), `100.64.0.0/10` (Tailscale, CGNAT) und IPv6-ULA (`fc00::/7`).
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
- Verarbeitet wird nach Verbrauchsart, Zähler und Datum. So sieht die
  Verdachtsprüfung frühere Stände desselben Stapels. Jede Datendatei wird
  einmal geschrieben.
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
  "password_fixed": false, "has_password": false }

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
  Ausschalten.
- `GET /api/auth/keys` liefert `id`, `name`, `scope`, `created_at`,
  `last_used_at` — nie den Schlüssel oder seinen Hash.

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
| `/api/reports/ev-charging…` | `year` | 2017–2100, Standard Vorjahr, sonst `errors.evReport.yearInvalid` (v3.1.0) |
| | `method` | `contract`, `flat`, sonst `errors.evReport.methodInvalid` |
| | `flat_ct` | 0–200 ct/kWh, sonst `errors.evReport.flatInvalid` |
| `/api/heat-pump` | `year` | 1990–2100, Standard Vorjahr, sonst `errors.heatPump.yearInvalid` (v3.1.0) |
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
