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

Das schützt vor fremden **Webseiten**, nicht vor fremden **Menschen oder
Geräten im Netz** — dafür ist die Anmeldung da.

## 3. Anmeldung einschalten

**In der App:** Einstellungen → Zugriff → „Anmeldung & Zugriff" → Passwort
(mindestens 8 Zeichen) zweimal eingeben → „Anmeldung einschalten". Dieser
Browser bleibt angemeldet; jeder andere fragt einmal nach dem Passwort und
bleibt dann **30 Tage** angemeldet. Abmelden geht über den Knopf in der
Kopfleiste.

Was sich damit ändert:

- Jede API-Route verlangt eine Sitzung (Browser) oder einen API-Schlüssel
  (Skripte, § 5). Ohne beides: `401`.
- **Home Assistant braucht dann einen Token** (§ 4) — ohne Token lehnt der
  Push jeden Wert ab.
- `/api/health` antwortet ohne Anmeldung nur noch mit `{status, version}` —
  genug für Docker und Uptime-Monitore.
- Nach **fünf** falschen Passwörtern innerhalb von 15 Minuten ist die
  Anmeldung **5 Minuten** gesperrt.
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
docker run --rm php:8.4-cli php -r 'echo password_hash("dein-passwort", PASSWORD_DEFAULT), PHP_EOL;'
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

## 5. API-Schlüssel für Skripte

Mit eingeschalteter Anmeldung brauchen Skripte und andere Programme einen
Schlüssel: Einstellungen → Zugriff → „Anmeldung & Zugriff" → „API-Schlüssel
für Skripte". Der Schlüssel (`etk_…`) wird **einmal** angezeigt; gespeichert
ist nur sein Hash.

| Berechtigung | darf |
|---|---|
| Lesen (`read`) | nur abrufen (`GET`) — z. B. für einen REST-Sensor in Home Assistant oder eine Auswertung |
| Verwalten (`admin`) | alles, auch ändern und löschen — z. B. für ein Backup-Skript, das auch einspielt |

```bash
curl -H "Authorization: Bearer etk_…" https://energie.example.org/api.php/api/backup/export > backup.json
```

Die Liste zeigt je Schlüssel, wann er zuletzt benutzt wurde. Nicht mehr
gebrauchte Schlüssel widerrufen.

## 6. Anmeldung über einen vorgeschalteten Proxy

Wer ohnehin einen Anmeldedienst betreibt (Authelia, Authentik, oauth2-proxy,
Synology SSO hinter einem Reverse-Proxy), lässt ihn die Anmeldung übernehmen:

```bash
ET_AUTH=proxy
ET_TRUSTED_PROXIES=172.18.0.0/16   # Adresse(n) oder Netze des Proxys, kommagetrennt
```

Der Energietracker übernimmt dann den Benutzer aus `Remote-User`,
`X-Forwarded-User` oder `X-Remote-User` — **nur** bei Anfragen von den
Adressen in `ET_TRUSTED_PROXIES`. Von anderswo gilt die Kopfzeile nicht, sonst
könnte jeder sie selbst setzen. Der Proxy muss diese Kopfzeilen aus den
Anfragen der Nutzer **entfernen**, bevor er seine eigenen setzt (bei den
genannten Diensten Standard). API-Schlüssel funktionieren im Proxy-Modus
zusätzlich.

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

**`ET_ALLOWED_HOSTS`** (opt-in) schützt gegen *DNS-Rebinding*: Eine fremde
Seite biegt ihre Domain auf die Adresse im Heimnetz um und wäre dann für den
Browser „dieselbe Seite". Mit gesetzter Liste beantwortet die App nur
bekannte Hostnamen, alle anderen mit `421`:

```bash
ET_ALLOWED_HOSTS=energie.example.org,*.fritz.box
```

IP-Adressen und `localhost` sind immer erlaubt. Mit eingeschalteter
Anmeldung ist Rebinding ohnehin wirkungslos (das Cookie gehört nicht zur
fremden Domain); die Liste ist die zweite Linie für den offenen Betrieb.

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
| `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`, `vendor/` | Quelltext, Tests, Hilfsdateien |
| `.git/`, `.github/`, `.env` und alle Punktdateien | Historie, Konfiguration |
| `composer.json`, `Dockerfile`, `VERSION`, `*.md` im Wurzelverzeichnis | Projektdateien |

- **Docker (nginx):** im Image konfiguriert, nichts zu tun.
- **Apache (Synology Web Station, Shared Hosting):** Die mitgelieferte
  `.htaccess` sperrt diese Pfade mit `mod_rewrite` (404); `data/.htaccess`
  ist die zweite Sicherung. Voraussetzung: `AllowOverride` mindestens
  `FileInfo` und ein aktives `mod_rewrite` — in der Web Station Standard.
- **Eigener nginx:** die `location`-Regeln aus `docker/nginx.conf`
  übernehmen.
- **Entwicklungsserver:** immer mit Router starten —
  `php -S 127.0.0.1:8080 router.php`. Ohne `router.php` liefert der
  PHP-Server jede Datei aus, auch `data/`. Nie auf `0.0.0.0` für andere
  freigeben.

**Prüfen** (Adresse anpassen) — alle drei müssen `404` liefern:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/data/meta.json
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/.git/config
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/src/bootstrap.php
```

## 10. Protokoll und Fehlersuche

- `ET_DEBUG=1` schreibt Datei, Zeile und Ausnahmetyp in Fehlerantworten —
  nur kurzzeitig zur Fehlersuche setzen, nie dauerhaft im offenen Betrieb.
- Ein `500` nennt eine Fehler-ID (`error_id`); dieselbe ID steht im
  Protokoll (Docker: `docker logs energietracker`).
- Snapshots und Backups enthalten alle Nutzdaten. `data/auth.json` (Passwort-
  Hash, Token-Hashes, Schlüssel) ist **nicht** Teil des Backups — nach einem
  Umzug Anmeldung und Token neu einrichten.

## 11. Checkliste vor der Freigabe nach außen

- [ ] Anmeldung eingeschaltet (§ 3) oder Proxy-Anmeldung (§ 6)
- [ ] HTTPS über den Reverse-Proxy (§ 7)
- [ ] Home Assistant mit Token (§ 4)
- [ ] `data/` & Co. liefern `404` (§ 9)
- [ ] `ET_ALLOWED_HOSTS` gesetzt (§ 8)
- [ ] `ET_DEBUG` nicht gesetzt (§ 10)
- [ ] Besser noch: VPN statt Freigabe (§ 1)

## 12. Sicherheitslücke gefunden?

Bitte **nicht** als öffentliches Issue melden, sondern wie in
[`SECURITY.md`](../../SECURITY.md) beschrieben.

---

[← Kompendium-Index](../README.md) · [Docker-Betrieb](docker.md) ·
[API-Referenz](../referenz/api.md)
