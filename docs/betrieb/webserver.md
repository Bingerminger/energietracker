# Webserver: Apache und nginx

**Deutsch** · [English](../en/betrieb/webserver.md)

[← Kompendium-Index](../README.md)

Das Docker-Image bringt seinen Webserver mit ([Docker](docker.md)). Diese Seite
ist für den Betrieb auf einem vorhandenen Webserver mit PHP 8.2 oder neuer
(seit v3.1.0; empfohlen 8.4) — Apache (auch die Synology Web Station) oder
nginx. Die Regeln sind in beiden Fällen dieselben und stammen aus der
getesteten Konfiguration des Images (`docker/nginx.conf`) bzw. der
mitgelieferten `.htaccess`:

1. **Nur Auslieferungsgut ausliefern.** `data/` (alle Nutzdaten und Backups),
   `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`, `vendor/`,
   seit v3.1.0 auch `tools/`, `dist/` und `deploy/` (Vorlagen für Unraid,
   CasaOS und Umbrel), alle Punktdateien (`.git/`, `.env` …) und die
   Projektdateien im Wurzelverzeichnis (`composer.json`, `Dockerfile`,
   `VERSION`, `*.md`) gehören nie über HTTP heraus.
2. **API über `api.php`.** Die Oberfläche ruft `api.php/api/…` auf; Umschreiben
   ist nicht nötig.
3. **Oberfläche über `index.php`.** Navigiert wird per Hash (`#/…`).
4. **Cache-Header.** Anwendungscode und Sprachkataloge immer revalidieren,
   Schriften lange behalten — sonst liefert ein Browser nach einem Update alte
   Module ([Fehlersuche](fehlersuche.md)).
5. **Den `Authorization`-Header an PHP weiterreichen** — sonst scheitert der
   Home-Assistant-Push mit 401.
6. **Schreibrecht** für den Webserver-Benutzer auf `data/`
   ([Installation → Schreibrechte](installation.md#33-schreibrechte)).
7. **Genug Platz für große Anfragen** — seit v3.1.0 stehen Belege im Backup
   ([Upload-Grenzen](#upload-grenzen-und-php-speicher-v310)).

---

## Apache

Die mitgelieferte `.htaccess` setzt Punkt 1, 4 und 5 um, `data/.htaccess` ist
die zweite Sicherung. Voraussetzungen:

- `AllowOverride` mindestens `FileInfo` für das Projektverzeichnis,
- die Module `mod_rewrite` (oder ersatzweise `mod_alias`, seit v3.4.0),
  `mod_headers` und `mod_setenvif`,
- PHP 8.2 oder neuer (PHP-FPM oder `mod_php`; geprüft bis 8.5).

Ein VirtualHost dafür:

```apache
<VirtualHost *:80>
    ServerName energietracker.example
    DocumentRoot /var/www/energietracker

    <Directory /var/www/energietracker>
        AllowOverride FileInfo
        DirectoryIndex index.php
        Require all granted
    </Directory>

    # zweite Sicherung, falls die .htaccess einmal fehlt
    <Directory /var/www/energietracker/data>
        Require all denied
    </Directory>

    # optional: Daten außerhalb des Webroots
    # SetEnv ET_DATA_DIR /srv/energietracker-data

    # v3.4.0 — Größengrenzen wie die App: 8 MB, groß nur für die Importe
    LimitRequestBody 8388608
    <LocationMatch "/api(\.php/api)?/(backup/import|attachments|inbox|.+/import-(csv|series|evcc))$">
        LimitRequestBody 268435456
    </LocationMatch>
</VirtualHost>
```

`AllowOverride None` legt die `.htaccess` still — dann liefert Apache `data/`
aus, und die Cache-Regeln fehlen. Fehlt nur `mod_rewrite`, sperrt seit v3.4.0
ein Rückfall mit `mod_alias` (`RedirectMatch 404`) dieselben Pfade. Ob der
Schutz wirkt, zeigt Einstellungen → Zugriff → „Sicherheits-Check“. Der Betrieb in einem Unterverzeichnis (etwa
`/energietracker/`) funktioniert ohne Änderung; die Regeln der `.htaccess` sind
relativ.

### Synology Web Station

- **Web Station → Webdienst**: Apache 2.4 mit einem PHP-Profil ab 8.2 (8.4,
  wenn angeboten); den Ordner
  unter `web/` ablegen. Die `.htaccess` greift dort.
- Der Benutzer `http` braucht Schreibrecht auf `data/` (File Station →
  Eigenschaften → Berechtigung).
- HTTPS: Zertifikat unter Systemsteuerung → Sicherheit → Zertifikat (Let's
  Encrypt), Weiterleitung unter Systemsteuerung → Anmeldeportal → Erweitert →
  Reverse Proxy.
- Alternativ als Container im **Container Manager** — siehe
  [Docker](docker.md#synology-container-manager).

## nginx

Abgeleitet aus `docker/nginx.conf`; Socket-Pfad und Wurzelverzeichnis anpassen.
Die Reihenfolge zählt: Die API-Regel steht vor der allgemeinen Sperre für
`.php`-Dateien.

```nginx
server {
    listen 80;
    server_name energietracker.example;
    root /var/www/energietracker;
    index index.php;
    client_max_body_size 256m;             # Backup-Import mit Belegen, CSV-Upload (v3.1.0; vorher 32m)

    # 1. nur Auslieferungsgut
    location ~ ^/(data|src|tests|scripts|docker|docs|vendor|demo-data|tools|dist|deploy)/ { return 404; }
    location ~ /\. { return 404; }
    location ~* ^/[^/]+\.(md|json|lock|ya?ml|dist|txt|conf|sh|ini)$ { return 404; }
    location ~ ^/(Dockerfile|VERSION)$ { return 404; }

    # 2a. Posteingang: Erkennen und Auslesen warten auf das KI-Modell (v3.7.0)
    location ~ ^/api(\.php/api)?/inbox/[^/]+/(classify|extract)$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_read_timeout 660s;         # Cloud-Dienst bis 600 s, PHP hebt seine Grenze auf 660 s
        # fastcgi_param ET_DATA_DIR /srv/energietracker-data;
    }

    # 2. API → api.php
    location ~ ^/api(\.php)?(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_read_timeout 310s;         # Texterkennung bis 300 s (v3.1.0; vorher 120s)
        # fastcgi_param ET_DATA_DIR /srv/energietracker-data;
    }

    # sonstigen PHP-Quelltext nie roh ausliefern
    location ~ \.php$ { return 404; }

    # 4. Cache-Header
    location ~ ^/public/(js|locales)/ {
        add_header Cache-Control "no-cache, must-revalidate" always;
        try_files $uri =404;
    }
    location ~ \.(woff2?|ttf|eot)$ {
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        try_files $uri =404;
    }

    # 3. statische Datei oder die Oberfläche
    location / { try_files $uri @spa; }
    location @spa {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
    }
}
```

nginx reicht den `Authorization`-Header von sich aus an PHP-FPM weiter
(Punkt 5). Für ein Unterverzeichnis müssten alle Muster das Präfix tragen —
einfacher ist ein eigener Hostname.

## Upload-Grenzen und PHP-Speicher *(v3.1.0)*

Seit v3.1.0 enthält ein Backup die Belege (Fotos, PDFs) base64-kodiert — es
ist damit rund ein Drittel größer als die Dateien selbst und wächst mit jedem
Foto. Das Docker-Image ist darauf eingestellt; auf einem eigenen Webserver
setzt du dieselben Werte:

| Wo | Einstellung | Docker-Image | Wozu |
|---|---|---|---|
| PHP | `post_max_size`, `upload_max_filesize` | `256M` | größter Backup-Import, PDF-Beleg bis 10 MB |
| PHP | `memory_limit` | `768M` | Der Import eines großen Backups braucht etwa die doppelte Größe an PHP-Speicher |
| PHP | `max_execution_time` | `120` | große Importe |
| nginx | `client_max_body_size` | `8m`, `256m` nur für Backup-Import, Belege und Importe *(v3.4.0)* | sonst `413 Request Entity Too Large` vor PHP |
| nginx | `fastcgi_read_timeout` | `310s`; `660s` nur für Erkennen und Auslesen im Posteingang *(v3.7.0)* | große Importe, Texterkennung: Ihr Zeitlimit (`ocr_timeout_s`) reicht bis 300 s; vorher `120s`, dann brach nginx eine langsame Erkennung vor PHP ab. Der Posteingang wartet auf einen Cloud-Dienst bis 600 s (`inbox_cloud_timeout_s`), PHP hebt seine Grenze dafür auf 660 s — nginx wartet auf diesen zwei Routen genauso lange (`/api/inbox/{id}/classify`, `/extract`) |

- **PHP-Werte** stehen in der `php.ini` bzw. im PHP-FPM-Pool
  (`php_admin_value[post_max_size] = 256M`); in der Synology Web Station im
  PHP-Profil unter den Skriptsprache-Einstellungen.
- **Apache** begrenzt den Körper selbst mit `LimitRequestBody`; aktuelle
  Versionen erlauben von Haus aus 1 GB — das reicht.
- **Seit v3.4.0 klein, wo es geht:** Die App selbst nimmt außerhalb von
  Backup-Import, Belegen und den CSV-/Zeitreihen-Importen höchstens 8 MB an,
  ohne Anmeldung erreichbare Routen höchstens 1 MB (`413`), und liest den
  Körper erst nach der Anmeldung. Der Webserver sollte dasselbe tun, damit
  große Körper gar nicht erst bis PHP kommen: für nginx die zwei Locations aus
  `docker/nginx.conf`, für Apache `LimitRequestBody` in der Server- oder
  VirtualHost-Konfiguration (in `.htaccess` nur mit `AllowOverride All`) —
  [Sicherheit § 12](sicherheit.md#12-größengrenzen-und-schreibsperre-v340).
- Für Fotos (höchstens 3 MB) genügen die PHP-Vorgaben (8 MB). Für PDF-Belege
  und Backups mit vielen Fotos die Grenzen anheben — sonst bricht der Upload
  ab ([Fehlersuche](fehlersuche.md)).
- `memory_limit` wirkt als Obergrenze; gebraucht wird der Speicher nur beim
  Einspielen eines großen Backups.
- Ein Upload über `post_max_size` kommt bei PHP leer an; die App erkennt das
  und antwortet mit `400` und `errors.attachment.size` samt der Grenze („Die
  Datei ist zu groß – höchstens … MB.“).
- Steht ein Reverse-Proxy davor, braucht auch er ein Zeitlimit über 300
  Sekunden, wenn die Texterkennung lange rechnen darf — für das Auslesen im
  Posteingang über einen Cloud-Dienst bis zu 660 Sekunden *(v3.7.0)*.

## Kurze Zeitgrenze vor PHP *(3.9.0)*

Erkennen und Auslesen im Posteingang rechnen je Schritt bis zu 300 Sekunden
(eigene KI), mit einem Cloud-Dienst bis zu 600. Gibt ein Webserver oder
Reverse-Proxy davor früher auf, antwortet er mit `504` — PHP rechnet trotzdem
zu Ende und speichert (die App setzt dafür `ignore_user_abort`). Auf einer
Synology geschah das nach 60 Sekunden.

- **Die Oberfläche wartet dann:** Nach einem `504` oder einem Netzabbruch
  fragt sie alle 5 Sekunden nach dem Dokument (`GET /api/inbox/{id}`), bis der
  Schritt gespeichert ist — höchstens 660 Sekunden ab dem Absenden — und zeigt
  erst danach einen Fehler. Das gilt im einzelnen Dokument wie beim Auslesen
  vieler Dokumente.
- **Besser die Grenze anheben**, dann wartet niemand: nginx
  `fastcgi_read_timeout` (als Reverse-Proxy `proxy_read_timeout`), Apache
  `Timeout` (Vorgabe 60 Sekunden) bzw. `ProxyTimeout` — auf 660 Sekunden, für
  `/api/inbox/{id}/classify` und `/extract` genügt das. Auf einer Synology
  betrifft das den Webdienst der Web Station und einen Reverse Proxy des
  Anmeldeportals davor, soweit DSM die Einstellung anbietet.

## Der PHP-eigene Server — nur zum Ausprobieren

```bash
php -S 127.0.0.1:8080 router.php
```

Immer mit `router.php`: Er setzt dieselben Regeln um. Ohne ihn liefert der
PHP-Server jede Datei aus, auch `data/`. Er bedient eine Anfrage nach der
anderen und gehört nicht in den Dauerbetrieb; nie auf `0.0.0.0` für andere
freigeben.

## Prüfen

Nach der Einrichtung müssen `data/`, `.git/` und `src/` mit 404 antworten —
die Befehle stehen unter
[Sicherheit & Netzbetrieb → Webserver](sicherheit.md#9-webserver-was-nicht-ausgeliefert-werden-darf).
Vor dem Zugriff von außen: Anmeldung und HTTPS
([Sicherheit & Netzbetrieb](sicherheit.md)).

---

[← Kompendium-Index](../README.md)
