# Sicherheit & Netzbetrieb

**Deutsch** · [English](../en/betrieb/sicherheit.md)

> Gilt ab **v2.6.0**. Dieses Kapitel beschreibt, wen der Energietracker
> hereinlässt, wie du ihn schützt und was du prüfen solltest, bevor du ihn
> über das eigene Heimnetz hinaus erreichbar machst.

---

## 1. Das Betriebsmodell in einem Satz

**Ohne Anmeldung kann jeder, der die Adresse erreicht, alles lesen, ändern
und löschen** — Zählerstände, Verträge, Standort, Backups. Das ist der
Standard und fürs eigene Heimnetz gedacht, in dem nur du und deine Geräte
sind.

Schalte die Anmeldung ein (§ 3), sobald eines davon zutrifft:

- Die App ist von außen erreichbar: Portfreigabe im Router, Reverse-Proxy,
  Synology QuickConnect, DynDNS.
- Andere nutzen dasselbe Netz: Wohngemeinschaft, Gäste-WLAN ohne Trennung,
  Mehrparteienhaus mit gemeinsamem Netz.
- Die Daten gehören nicht nur dir (Vermietung, Verwaltung).

> **Nicht ohne Schutz ins Internet.** Der beste Weg von unterwegs ist ein VPN
> (WireGuard, Tailscale, das VPN der FRITZ!Box oder des NAS): Die App bleibt im
> Heimnetz, du wählst dich ein. Wer sie trotzdem öffentlich erreichbar macht,
> braucht HTTPS **und** eine Anmeldung — entweder die eingebaute (§ 3) oder die
> eines vorgeschalteten Proxys (§ 6).

## 2. Was ohne Anmeldung trotzdem geschützt ist

Auch im offenen Betrieb gelten seit v2.5.3/v2.6.0:

| Schutz | Wirkung |
|---|---|
| Fremde Webseiten | Eine andere Seite, die du im Browser offen hast, kann nichts ändern (Prüfung von `Sec-Fetch-Site`/`Origin`, Antwort 403) und die App nicht unsichtbar einbetten (`frame-ancestors`, § 8). |
| Webserver-Regeln | `data/` (alle Nutzdaten und Backups), `src/`, `.git/` und andere Nicht-Auslieferungsdateien liefert der Webserver nicht aus (§ 9). |
| Content-Security-Policy | Nur Skripte der eigenen Installation laufen — eine zweite Linie gegen eingeschleustes HTML. |
| Fehlermeldungen | Keine Pfade, Dateinamen oder Zeilennummern in API-Antworten (nur mit `ET_DEBUG=1`); ein Serverfehler nennt eine Fehler-ID, die Einzelheiten stehen im Protokoll. |
| Belege *(v3.1.0)* | Fotos und PDFs werden am Inhalt geprüft und nur über die API ausgeliefert, ein PDF ohne Skripte (§ 9). |
| Texterkennung *(v3.1.0)* | Der Server verbindet sich nur mit Adressen im eigenen Netz, geprüft bei jedem Aufruf (§ 9). |
| Größe und Schreibsperre *(v3.4.0)* | Große Anfragen nur dort, wo sie hingehören, gelesen wird erst nach der Anmeldung; ein langsamer Dienst hält keinen Schreibzugriff auf (§ 12). |
| Hostliste *(v3.4.0)* | In den Einstellungen setzbar, damit eine fremde Seite ihren Namen nicht auf deine Adresse umbiegen kann (§ 8). |

Das schützt vor fremden **Webseiten**, nicht vor fremden **Menschen oder
Geräten im Netz** — dafür ist die Anmeldung da.

## 3. Anmeldung einschalten

**In der App:** Einstellungen → Zugriff → „Anmeldung & Zugriff" → Passwort
(mindestens 8 Zeichen) zweimal eingeben → „Anmeldung einschalten". Dieser
Browser bleibt angemeldet; jeder andere fragt einmal nach dem Passwort und
bleibt dann angemeldet — seit v3.4.0 **30 Tage ab der letzten Nutzung**
(gleitend; bis v3.3 30 Tage ab der Anmeldung). Abmelden geht über den Knopf
in der Kopfleiste. Abmelden löscht das Cookie dieses Browsers und die
gespeicherten Antworten der App in seinem Cache (seit v3.4.0 wieder
vollständig; v3.1 bis v3.3 ließen sie liegen).

**Überall abmelden** *(v3.4.0)*: Einstellungen → Allgemein → „Mein Konto“
bzw. Einstellungen → Zugriff. Alle Sitzungen dieser Person enden auf jedem
Gerät, auch auf diesem — etwa nach einem verlorenen Handy oder wenn jemand
das Cookie kopiert haben könnte. API-Schlüssel und der Home-Assistant-Token
bleiben gültig; die widerruft man einzeln.

Was sich damit ändert:

- Jede API-Route verlangt eine Sitzung (Browser) oder einen API-Schlüssel
  (Skripte, § 5). Ohne beides: `401`.
- **Home Assistant braucht dann einen Token** (§ 4) — ohne Token lehnt der
  Push jeden Wert ab. Für die Werte zurück nach Home Assistant braucht es
  zusätzlich einen API-Schlüssel `read` (§ 5).
- Das **Kalender-Abo** braucht einen Kalender-Schlüssel im Link (§ 5); die App
  legt ihn beim Abonnieren an (seit v3.1.0).
- `/api/health` antwortet ohne Anmeldung nur noch mit `{status, version}` —
  genug für Docker und Uptime-Monitore.
- Nach **fünf** falschen Passwörtern innerhalb von 15 Minuten ist die
  Anmeldung **für dieses Gerät 5 Minuten** gesperrt; zehn Fehlversuche für
  denselben Namen sperren diesen Namen, hundert insgesamt alle (Notbremse).
  Bis v3.3 galt eine Sperre für den ganzen Haushalt — jedes Gerät im Netz
  konnte so alle aussperren. Hinter einem Reverse-Proxy zählt die Adresse aus
  `X-Forwarded-For`, aber nur, wenn der Proxy in `ET_TRUSTED_PROXIES` steht.
- Die Antwortzeit verrät nicht, ob es einen Namen gibt (v3.4.0).
- Ein neues Passwort meldet alle anderen Geräte ab.

**Passwort vergessen?** Auf dem Server in `data/auth.json` den Wert von
`"mode"` auf `"off"` setzen — dann ist die Anmeldung aus, und du kannst in
den Einstellungen ein neues Passwort setzen. Im Docker-Container geht es
einfacher: den Container mit `ET_AUTH=off` starten.

### Über Umgebungsvariablen (Docker)

| Variable | Wirkung |
|---|---|
| `ET_AUTH` | `off`, `password` oder `proxy` — legt den Modus fest; die Einstellungen können ihn dann nicht ändern |
| `ET_ADMIN_PASSWORD_HASH` | Passwort als Hash statt in `data/auth.json`; die App kann es dann nicht ändern |

Einen Hash erzeugen:

```bash
php -r 'echo password_hash("dein-passwort", PASSWORD_DEFAULT), PHP_EOL;'
# oder ohne lokales PHP:
docker run --rm php:8.5-cli php -r 'echo password_hash("dein-passwort", PASSWORD_DEFAULT), PHP_EOL;'
```

> In `docker-compose.yml` jedes `$` im Hash verdoppeln (`$$2y$$10$$…`) —
> Compose liest `$` sonst als Variable und kürzt den Hash still.

## 4. Home Assistant

Der **Token** schützt ausschließlich den Push-Endpunkt `/api/ingest` — nicht
den Rest der API. Ohne Anmeldung ist er freiwillig; mit Anmeldung ist er
Pflicht. Einrichtung: [Home Assistant](../anleitungen/home-assistant.md).

- Einstellungen → Integrationen → „Home-Assistant-Anbindung" zeigt, wann
  zuletzt ein Wert mit dem Token ankam (auf die Stunde genau) — die erste
  Frage bei der Fehlersuche.
- Ein neuer Token macht den alten sofort ungültig — den neuen dann in Home
  Assistant (`secrets.yaml`) eintragen und Home Assistant neu starten.
- Fällt ein Zählerstand gegenüber dem vorigen (Sensor-Aussetzer, Tausch),
  wird er gespeichert, aber **als Verdacht markiert** und zählt erst nach
  deiner Bestätigung — so erzeugt eine 0 aus Home Assistant keinen
  Phantomverbrauch.
- **Werte zurück nach Home Assistant** (seit v3.1.0): Die REST-Sensoren lesen
  `GET /api/summary`. Dafür gilt der Token nicht — mit eingeschalteter
  Anmeldung braucht Home Assistant einen API-Schlüssel mit Berechtigung
  `read` (§ 5), in der `secrets.yaml` als `energietracker_read`. Er darf nur
  lesen; ein `admin`-Schlüssel gehört nicht in Home Assistant.

## 5. API-Schlüssel für Skripte

Mit eingeschalteter Anmeldung brauchen Skripte und andere Programme einen
Schlüssel: Einstellungen → Zugriff → „Anmeldung & Zugriff" → „API-Schlüssel
für Skripte". Der Schlüssel (`etk_…`) wird **einmal** angezeigt; gespeichert
ist nur sein Hash.

| Berechtigung | darf |
|---|---|
| Lesen (`read`) | nur abrufen (`GET`) — z. B. für die REST-Sensoren in Home Assistant (`/api/summary`) oder eine Auswertung |
| Verwalten (`admin`) | alles, auch ändern und löschen — z. B. für ein Backup-Skript, das auch einspielt |
| Kalender (`calendar`, seit v3.1.0) | nur das Kalender-Abo `GET /api/calendar.ics`, und nur als `?token=…` im Link |

```bash
curl -H "Authorization: Bearer etk_…" https://energie.example.org/api.php/api/backup/export > backup.json
```

Die Liste zeigt je Schlüssel, wann er zuletzt benutzt wurde. Nicht mehr
gebrauchte Schlüssel widerrufen.

**Kalender-Schlüssel.** Kalender-Apps können keine Kopfzeile senden; der
Schlüssel steht deshalb im Link und landet damit in Kalender-Synchronisationen
und Protokollen. Darum ist er eng gefasst:

- Er gilt nur für `/api/calendar.ics` und nur als `?token=…`. Auf jeder
  anderen Route, auch als `Authorization`-Kopfzeile, antwortet die App mit
  `401`.
- Ein `read`- oder `admin`-Schlüssel im Link wird abgewiesen (`401`) — sonst
  stünde ein Schlüssel mit Lesezugriff auf alles in jeder Kalender-App.
- Die App legt den Schlüssel an, wenn du unter Hinweise → Termine & Wartung
  „Im Kalender abonnieren“ wählst und die Rückfrage mit „Link erzeugen“
  bestätigst — nicht schon beim Öffnen. Widerrufen unter Einstellungen → Zugriff; der
  Kalender bekommt danach nichts mehr. Einrichtung:
  [Kalender abonnieren](../anleitungen/kalender.md).

## 6. Anmeldung über einen vorgeschalteten Proxy

Wer ohnehin einen Anmeldedienst betreibt (Authelia, Authentik, oauth2-proxy,
Synology SSO hinter einem Reverse-Proxy), lässt ihn die Anmeldung übernehmen:

```bash
ET_AUTH=proxy
ET_TRUSTED_PROXIES=172.18.0.0/16   # Adresse(n) oder Netze des Proxys, kommagetrennt
```

Den Benutzer meldet der Proxy in einer Kopfzeile — und die gilt **nur** bei
Anfragen von den Adressen in `ET_TRUSTED_PROXIES`. Von anderswo zählt sie
nicht, sonst könnte jeder sie selbst setzen. Welche Kopfzeile zählt
(seit v3.4.0):

| Betrieb | Es zählt |
|---|---|
| `ET_PROXY_USER_HEADER` ist gesetzt (z. B. `X-Forwarded-User`, `Remote-User`) | genau diese eine Kopfzeile — **empfohlen** für jeden Proxy außer Home Assistant |
| Home-Assistant-Ingress (erkennbar an `X-Ingress-Path` bzw. `X-Remote-User-Id`) | nur `X-Remote-User-Id` und `X-Remote-User-Name`; die Person wird über die stabile Kennung geführt (eine Umbenennung in Home Assistant macht keine neue Person) |
| keines von beiden | wie bis v3.3 die erste nicht leere von `Remote-User`, `X-Forwarded-User`, `X-Remote-User`, `X-Remote-User-Name`, `X-Remote-User-Id` — mit Warnung im Sicherheits-Check |

```bash
ET_PROXY_USER_HEADER=X-Forwarded-User   # die Kopfzeile, die dein Proxy setzt
```

Warum die feste Kopfzeile: Manche Proxys entfernen nur ihre eigene Kopfzeile
aus den Anfragen der Nutzer und reichen andere unverändert durch. Gilt dann
die erste Kopfzeile einer Liste, kann sich eine angemeldete Person als eine
andere ausgeben — auch als Verwalter. Mit `ET_PROXY_USER_HEADER` zählt nur
die Kopfzeile, die der Proxy selbst setzt. API-Schlüssel funktionieren im
Proxy-Modus zusätzlich.

Unter Home-Assistant-Ingress ist der Proxy der Supervisor:
`ET_AUTH=proxy` und `ET_TRUSTED_PROXIES=172.30.32.2`; `ET_PROXY_USER_HEADER`
bleibt leer. Den internen Hostnamen für die Vorlagen meldet die App seit
v3.4.0 ebenfalls nur dem Supervisor. Einzelheiten in
[Home Assistant](../anleitungen/home-assistant.md).

## 7. HTTPS

Die App selbst spricht kein TLS — das übernimmt der Reverse-Proxy (Caddy,
nginx, Traefik, Synology). Das Sitzungs-Cookie bekommt das Attribut `Secure`
automatisch, wenn die Anfrage über HTTPS kommt oder der Proxy
`X-Forwarded-Proto: https` setzt.

**Auch im Heimnetz lohnt HTTPS** (v2.11.0). Browser erlauben manche
Funktionen nur in einem sicheren Kontext, also über HTTPS oder `localhost`:

- Den Service Worker, und damit die Anzeige ohne Netz, gibt es über
  `http://<NAS-Adresse>` nicht.
- Die Home-Bildschirm-App auf dem iPhone ist dann nur ein Lesezeichen.

Kopieren (Token, YAML, Jahresverbrauch) geht seit v2.11.0 auch ohne HTTPS;
die App hat dafür einen Rückfall. Ein Reverse-Proxy mit Zertifikat schaltet
den Rest frei, etwa die Synology mit Let's Encrypt oder Caddy.

## 8. Hostnamen und Einbetten

Eine **Hostliste** (opt-in) schützt gegen *DNS-Rebinding*: Eine fremde
Seite biegt ihre Domain auf die Adresse im Heimnetz um und wäre dann für den
Browser „dieselbe Seite". Mit gesetzter Liste beantwortet die App nur
bekannte Hostnamen, alle anderen mit `421`. Zwei Wege, beide gelten
zusammen:

- **In der App** *(v3.4.0)*: Einstellungen → Zugriff → „Hostnamen“ (nur
  Verwalter, Einstellung `allowed_hosts`). Für Apache und die Synology, die
  keine Umgebungsvariablen kennen. Beim Speichern muss der Name dabei sein,
  unter dem du die App gerade aufrufst — sonst lehnt die App ab, statt dich
  auszusperren. Der Sicherheits-Check (Einstellungen → Zugriff) meldet den
  offenen Betrieb ohne Hostliste und bietet an, den aktuellen Namen zu
  übernehmen.
- **Über die Umgebung:**

```bash
ET_ALLOWED_HOSTS=energie.example.org,*.fritz.box
```

IP-Adressen und `localhost` sind immer erlaubt — wer die App über ihre
Adresse aufruft, kommt immer hinein. Mit eingeschalteter Anmeldung ist
Rebinding ohnehin wirkungslos (das Cookie gehört nicht zur fremden Domain);
die Liste ist die zweite Linie für den offenen Betrieb.

**Einbetten:** Fremde Seiten dürfen die App nicht in einem Rahmen anzeigen —
sonst ließen sich Klicks auf „Demo-Daten laden" oder „Token erzeugen"
unterschieben. Für eine Webseiten-Karte im Home-Assistant-Dashboard dessen
Adresse eintragen: Einstellungen → Zugriff → „Einbetten", oder
`ET_FRAME_ANCESTORS=http://homeassistant.local:8123`. Erlaubt sind Ursprünge
(Schema, Host, optional Port, auch `*.domain`), mehrere durch Leerzeichen
getrennt.

## 9. Webserver: Was nicht ausgeliefert werden darf

| Pfad | Inhalt |
|---|---|
| `data/` | alle Nutzdaten, `auth.json`, Backups und Snapshots |
| `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`, `vendor/`, `tools/`, `dist/`, `deploy/` | Quelltext, Tests, Hilfsdateien, Vorlagen (die drei letzten seit v3.1.0). Das Handbuch unter `docs/` liest die App seit v3.4.0 über `GET /api/docs` — nur aus einer Freigabeliste und mit Anmeldung, falls eingeschaltet; direkt bleibt es gesperrt |
| `.git/`, `.github/`, `.env` und alle Punktdateien | Historie, Konfiguration |
| `composer.json`, `Dockerfile`, `VERSION`, `*.md` im Wurzelverzeichnis | Projektdateien |

- **Docker (nginx):** im Image konfiguriert, nichts zu tun.
- **Apache (Synology Web Station, Shared Hosting):** Die mitgelieferte
  `.htaccess` sperrt diese Pfade mit `mod_rewrite` (404); `data/.htaccess`
  ist die zweite Sicherung. Voraussetzung: `AllowOverride` mindestens
  `FileInfo` und ein aktives `mod_rewrite` — in der Web Station Standard.
  Fehlt `mod_rewrite`, sperrt seit v3.4.0 `mod_alias` dieselben Pfade
  (`RedirectMatch 404`, ebenfalls nur `FileInfo`); bis v3.3 war der Schutz
  dann still aus.
- **Eigener nginx:** die `location`-Regeln aus `docker/nginx.conf`
  übernehmen.
- **Entwicklungsserver:** immer mit Router starten —
  `php -S 127.0.0.1:8080 router.php`. Ohne `router.php` liefert der
  PHP-Server jede Datei aus, auch `data/`. Nie auf `0.0.0.0` für andere
  freigeben.

**Selbsttest in der App** *(v3.4.0)*: Einstellungen → Zugriff (und System)
→ „Sicherheits-Check“. Der Browser fragt dort selbst nach `data/meta.json`;
liefert der Webserver die Datei aus, steht der Punkt rot da — dann wirken die
Regeln nicht (`AllowOverride` zu eng oder keines der beiden Module aktiv).

**Prüfen von Hand** (Adresse anpassen) — alle drei müssen `404` liefern:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/data/meta.json
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/.git/config
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/src/bootstrap.php
```

### Belege und Texterkennung *(v3.1.0)*

**Belege.** Fotos und PDFs liegen unter `data/attachments/` — für den
Webserver gesperrt wie der Rest von `data/`. Heraus kommen sie nur über
`GET /api/attachments/{id}`, also mit derselben Anmeldung wie alles andere.

- Angenommen wird nur, was nach seinem **Inhalt** (den ersten Bytes) ein JPEG,
  PNG, WebP oder PDF ist; Endung und `Content-Type` zählen nicht. SVG und
  HTML, die Skripte tragen könnten, lehnt die App ab.
- Ausgeliefert wird mit dem gespeicherten Typ und
  `X-Content-Type-Options: nosniff` — der Browser rät nicht, was die Datei
  sein könnte.
- Ein PDF kommt mit `Content-Security-Policy: sandbox`: Es läuft ohne Skripte
  und ohne Zugriff auf die App.
- Grenzen: Foto 3 MB, PDF 10 MB, alle zusammen `attachments_max_mb`.

**Datenschutz beim Foto.** Der Browser verkleinert ein Zählerfoto vor dem
Hochladen und kodiert es neu als JPEG. Dabei fallen die EXIF-Daten weg — auch
der GPS-Ort, den Handys oft mitspeichern. Backups und Snapshots enthalten die
Fotos: Wer ein Backup weitergibt, gibt die Fotos mit.

**Texterkennung nur im Heimnetz.** Ist ein Texterkennungsdienst eingetragen
(`ocr_endpoint`), verbindet sich der **Server** mit ihm — nie der Browser,
dessen Content-Security-Policy nur die eigene Seite erlaubt. Jede Adresse, auf
die der Name zeigt, muss lokal sein (Loopback, private Netze,
`100.64.0.0/10`, IPv6-ULA). Link-local (`169.254.0.0/16`, `fe80::/10`) zählt
seit v3.4.0 nicht mehr dazu: Dort antworten bei gemieteten Servern die
Metadatendienste der Anbieter. Geprüft wird bei jedem Aufruf nach der
Namensauflösung, verbunden wird mit genau der geprüften Adresse, Weiterleitungen
werden nicht verfolgt. So schickt weder ein Tippfehler noch ein umgebogener
DNS-Eintrag (DNS-Rebinding) ein Zählerfoto ins Internet. Einen Schalter dagegen
gibt es nicht; leer heißt: keine Verbindung. Das Ziel bestimmt, wer die
Einstellungen ändern darf — mit eingeschalteter Anmeldung nur Angemeldete und
`admin`-Schlüssel. Einrichtung:
[Texterkennung im Heimnetz](../anleitungen/texterkennung.md).

**Verbindungen nach außen** *(v3.4.0)*: Wetter (Open-Meteo) und
Börsenpreise (SMARD) nur über HTTPS, auch nach einer Weiterleitung, und
höchstens zwei Weiterleitungen; Texterkennung und evcc im Heimnetz ohne
Weiterleitung. Jede Antwort höchstens 20 MB — eine größere bricht die App ab,
statt sie in den Speicher zu laden.

## 10. Protokoll und Fehlersuche

- `ET_DEBUG=1` schreibt Datei, Zeile und Ausnahmetyp in Fehlerantworten —
  nur kurzzeitig zur Fehlersuche setzen, nie dauerhaft im offenen Betrieb.
- Ein `500` nennt eine Fehler-ID (`error_id`); dieselbe ID steht im
  Protokoll (Docker: `docker logs energietracker`).
- Snapshots und Backups enthalten alle Nutzdaten. `data/auth.json` (Passwort-
  Hash, Token-Hashes, Schlüssel) ist **nicht** Teil des Backups — nach einem
  Umzug Anmeldung und Token neu einrichten.

## 11. Checkliste vor der Freigabe nach außen

- [ ] Anmeldung eingeschaltet (§ 3) oder Proxy-Anmeldung mit `ET_PROXY_USER_HEADER` (§ 6)
- [ ] HTTPS über den Reverse-Proxy (§ 7)
- [ ] Home Assistant mit Token, Rückkanal mit `read`-Schlüssel (§ 4)
- [ ] Kalender-Abo nur mit Kalender-Schlüssel im Link (§ 5)
- [ ] `data/` & Co. liefern `404` — der Sicherheits-Check zeigt es (§ 9)
- [ ] Hostliste gesetzt: Einstellungen → Zugriff oder `ET_ALLOWED_HOSTS` (§ 8)
- [ ] `ET_DEBUG` nicht gesetzt (§ 10)
- [ ] Docker: gehärtet oder ohne root, Port nur auf `127.0.0.1` hinter dem Reverse-Proxy (§ 13)
- [ ] Besser noch: VPN statt Freigabe (§ 1)

## 12. Größengrenzen und Schreibsperre *(v3.4.0)*

**Größe einer Anfrage.** Die App prüft die angegebene Größe, bevor sie ein
Byte liest, und liest den Körper erst nach der Anmeldung. Bis v3.3 las sie
jede Anfrage vorab ganz ein — wenige parallele Anfragen eines Geräts ohne
Anmeldung brachten ein NAS an die Speichergrenze.

| Route | Höchstens |
|---|---|
| ohne Anmeldung erreichbar: `POST /api/ingest`, `POST /api/session` | 1 MB |
| Backup-Import, Belege, CSV- und Zeitreihen-Importe (`…/import-csv`, `…/import-series`, `…/import-evcc`) | 256 MB |
| alle anderen | 8 MB |

Darüber antwortet die App mit `413`. Im Docker-Image setzt nginx dieselben
Grenzen (`client_max_body_size` 8 MB, 256 MB nur für diese Routen). Mit
**Apache** gehört die Entsprechung in die Server- oder VirtualHost-
Konfiguration — `LimitRequestBody` ist in `.htaccess` nur mit
`AllowOverride All` erlaubt und würde sonst jede Anfrage mit `500`
beantworten:

```apache
LimitRequestBody 8388608
<LocationMatch "/api(\.php/api)?/(backup/import|attachments|.+/import-(csv|series|evcc))$">
    LimitRequestBody 268435456
</LocationMatch>
```

**Schreibsperre.** Schreibende Anfragen laufen nacheinander (sonst gingen bei
gleichzeitigen Schreibern Änderungen verloren). Seit v3.4.0 hält ein Aufruf
eines fremden Dienstes die Sperre nicht mehr: Die Texterkennung schreibt
nichts und sperrt gar nicht, Wetter, SMARD und evcc holen erst die Daten und
sperren dann nur für das Schreiben. Bis v3.3 blockierte eine Texterkennung bis
zu fünf Minuten jeden Schreibzugriff, auch den Home-Assistant-Push. Wartet
eine Anfrage länger als 15 Sekunden auf die Sperre, antwortet die App mit
`503` und `Retry-After: 5`, statt unbegrenzt zu warten (`ET_LOCK_WAIT_S`
ändert die Frist, 1 bis 120 Sekunden).

## 13. Container härten *(v3.4.0)*

**php-fpm nur intern.** Das Basis-Image lässt php-fpm auf allen
Schnittstellen lauschen. Seit v3.4.0 bindet das Image es an `127.0.0.1`;
andere Container im selben Docker-Netz — bei `network_mode: host` jedes Gerät
im Netz — erreichen FastCGI nicht mehr direkt, an jeder Anmeldung vorbei. Die
CI prüft das bei jedem Lauf am laufenden Container.

**Gehärteter Betrieb.** Wer mag, startet den Container mit weniger Rechten:
keine neuen Privilegien, nur die nötigen Capabilities, nur lesbares
Dateisystem und der Port nur auf `127.0.0.1` hinter einem Reverse-Proxy. Das
Beispiel steht in [Docker-Betrieb](docker.md#gehärteter-betrieb-v340); die CI
startet das Image bei jedem Lauf genau so.

**Ohne root *(v3.5.0)*.** Noch einen Schritt weiter geht die Variante mit der
Endung `-rootless`: Kein Prozess läuft als root, der Container braucht keine
einzige Capability, und der Start stellt keine Rechte um. Dafür muss der
Datenordner der UID des Containers (10001) gehören — Beispiel und Umstieg in
[Docker-Betrieb → Ohne root](docker.md#ohne-root-v350). Die CI startet auch
diese Variante bei jedem Lauf und prüft, dass kein Prozess mit UID 0 läuft.

**Aktuelles Basis-Image.** Jede Woche baut GitHub den neuesten Release-Tag
auf dem aktuellen Basis-Image neu (gleicher Code, neue Pakete; seit v3.5.0
beide Varianten) und prüft die Images auf bekannte Lücken. Ein festgeschriebener Tag wie `3.4.0` bekommt so
Sicherheitsupdates von Alpine und PHP, ohne dass sich an der App etwas ändert.

## 14. Sicherheitslücke gefunden?

Bitte **nicht** als öffentliches Issue melden, sondern wie in
[`SECURITY.md`](../../SECURITY.md) beschrieben.

---

[← Kompendium-Index](../README.md) · [Docker-Betrieb](docker.md) ·
[API-Referenz](../referenz/api.md)
