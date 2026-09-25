# Webserver: Apache und nginx

**Deutsch** · [English](../en/betrieb/webserver.md)

[← Kompendium-Index](../README.md)

Das Docker-Image bringt seinen Webserver mit ([Docker](docker.md)). Diese Seite
ist für den Betrieb auf einem vorhandenen Webserver mit PHP 8.4 — Apache (auch
die Synology Web Station) oder nginx. Die Regeln sind in beiden Fällen dieselben
und stammen aus der getesteten Konfiguration des Images (`docker/nginx.conf`)
bzw. der mitgelieferten `.htaccess`:

1. **Nur Auslieferungsgut ausliefern.** `data/` (alle Nutzdaten und Backups),
   `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`, `vendor/`,
   alle Punktdateien (`.git/`, `.env` …) und die Projektdateien im
   Wurzelverzeichnis (`composer.json`, `Dockerfile`, `VERSION`, `*.md`) gehören
   nie über HTTP heraus.
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

---

## Apache

Die mitgelieferte `.htaccess` setzt Punkt 1, 4 und 5 um, `data/.htaccess` ist
die zweite Sicherung. Voraussetzungen:

- `AllowOverride` mindestens `FileInfo` für das Projektverzeichnis,
- die Module `mod_rewrite`, `mod_headers` und `mod_setenvif`,
- PHP 8.4 (PHP-FPM oder `mod_php`).

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
</VirtualHost>
```

`AllowOverride None` legt die `.htaccess` still — dann liefert Apache `data/`
aus, und die Cache-Regeln fehlen. Der Betrieb in einem Unterverzeichnis (etwa
`/energietracker/`) funktioniert ohne Änderung; die Regeln der `.htaccess` sind
relativ.

### Synology Web Station

- **Web Station → Webdienst**: Apache 2.4 mit einem PHP-8.4-Profil; den Ordner
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
    client_max_body_size 32m;              # Backup-Import, CSV-Upload

    # 1. nur Auslieferungsgut
    location ~ ^/(data|src|tests|scripts|docker|docs|vendor|demo-data)/ { return 404; }
    location ~ /\. { return 404; }
    location ~* ^/[^/]+\.(md|json|lock|ya?ml|dist|txt|conf|sh|ini)$ { return 404; }
    location ~ ^/(Dockerfile|VERSION)$ { return 404; }

    # 2. API → api.php
    location ~ ^/api(\.php)?(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_read_timeout 120s;
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
