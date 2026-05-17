# Installation

## Voraussetzungen

- PHP ≥ 8.4 (CLI ist für die Entwicklung ausreichend)
- Web-Browser mit ES Modules (alles ab Mitte 2020)
- Optional: Apache, nginx, Caddy für Produktivbetrieb

## Schritte

```bash
git clone https://github.com/Bingerminger/energietracker.git
cd energietracker
```

**Verzeichnisstruktur prüfen:**

```
energietracker/
├── api.php
├── index.php
├── VERSION                ← 1.4.5
├── public/                ← CSS + JS
├── src/                   ← PHP-Backend
├── data/                  ← muss schreibbar sein
├── demo-data/             ← optionaler Datensatz
└── ...
```

**`data/`-Schreibrechte sicherstellen:**

```bash
chmod -R u+w data/
```

Bei Apache/nginx ggf. den Owner auf den Webserver-Benutzer setzen
(typisch `www-data` oder `nginx`):

```bash
sudo chown -R www-data:www-data data/
```

## Lokal testen

```bash
php -S 127.0.0.1:8080
```

Im Browser <http://127.0.0.1:8080> aufrufen. Beim ersten Request
initialisiert die App das `data/`-Verzeichnis automatisch.

### Datenverzeichnis verschieben (optional)

Standardmäßig liegt der JSON-Speicher unter `./data` relativ zu
`api.php`. Mit der Umgebungsvariable `ET_DATA_DIR` lässt sich ein
beliebiger absoluter Pfad erzwingen (seit v1.4.4) — nützlich für
getrennte Daten-/Code-Mounts oder mehrere Instanzen:

```bash
ET_DATA_DIR=/srv/energietracker-data php -S 127.0.0.1:8080
```

Bei Apache/nginx wird die Variable über `SetEnv` bzw.
`fastcgi_param ET_DATA_DIR …` gesetzt.

## Produktiv: Apache

Beispiel-Config (Document Root = Projektwurzel):

```apache
<VirtualHost *:80>
  ServerName energietracker.example.com
  DocumentRoot /var/www/energietracker

  <Directory /var/www/energietracker>
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
  </Directory>

  # Schutz des Datenverzeichnisses
  <Directory /var/www/energietracker/data>
    Require all denied
  </Directory>
</VirtualHost>
```

## Produktiv: nginx

```nginx
server {
  listen 80;
  server_name energietracker.example.com;
  root /var/www/energietracker;
  index index.php;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ ^/data/ {
    deny all;
    return 404;
  }

  location ~ \.php(/|$) {
    fastcgi_split_path_info ^(.+\.php)(/.*)$;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
  }
}
```

## Demo-Daten laden (optional)

```bash
find data/ -mindepth 1 -not -name '.gitkeep' -delete
cp -r demo-data/gas demo-data/strom demo-data/wasser \
      demo-data/fernwaerme demo-data/heizoel demo-data/pellets data/
mkdir -p data/backups
cp demo-data/meta.json demo-data/settings.json \
   demo-data/temperatures.json demo-data/reminders.json data/
```

> Alternativ einfach das gesamte Verzeichnis kopieren — der Migrator
> ist idempotent und der Demo-Datensatz trägt bereits `schema_version
> 1.1.0` (seit v1.4.4), sodass kein Migrationslauf nötig ist:
>
> ```bash
> rm -rf data && cp -r demo-data data
> ```

## Migration aus v0.9.0

Wer ein altes v0.9.0-Backup hat: nach der Installation einfach in der
UI öffnen unter **Einstellungen → Backup & Restore → 📦 Migration aus
v0.9.0** und die JSON-Datei hochladen.

Detaillierte Anleitung in [`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md).
