# Installation

[English](INSTALL.md) · **Deutsch**

> 🔐 **Sicherheit:** Ohne Anmeldung kann jeder, der die App erreicht, alle Daten
> lesen und ändern — im eigenen Heimnetz in Ordnung. Bevor du sie von außen
> erreichbar machst (Portfreigabe, Reverse-Proxy, QuickConnect), schalte die
> Anmeldung ein (Einstellungen → Zugriff → „Anmeldung & Zugriff") und lies
> [Sicherheit & Netzbetrieb](docs/technical/08-security.md). Besser noch:
> unterwegs per VPN zugreifen.

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
├── VERSION                ← 2.13.0
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
php -S 127.0.0.1:8080 router.php
```

Im Browser <http://127.0.0.1:8080> aufrufen. Beim ersten Request
initialisiert die App das `data/`-Verzeichnis automatisch.

> Den eingebauten Server immer **mit `router.php`** starten: Ohne liefert PHP
> jede Datei des Verzeichnisses aus — auch `data/` mit allen Daten. Und auf
> `127.0.0.1` lassen; der eingebaute Server ist nicht für andere im Netz
> gedacht.

### Datenverzeichnis verschieben (optional)

Standardmäßig liegt der JSON-Speicher unter `./data` relativ zu
`api.php`. Mit der Umgebungsvariable `ET_DATA_DIR` lässt sich ein
beliebiger absoluter Pfad erzwingen (seit v1.4.4) — nützlich für
getrennte Daten-/Code-Mounts oder mehrere Instanzen:

```bash
ET_DATA_DIR=/srv/energietracker-data php -S 127.0.0.1:8080 router.php
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
    # FileInfo lässt die mitgelieferte .htaccess wirken: Sie sperrt data/,
    # src/, .git/ und andere Nicht-Auslieferungsdateien (seit v2.6.0) und
    # setzt die Cache-Header.
    AllowOverride FileInfo
    Require all granted
  </Directory>

  # Zweite Sicherung für das Datenverzeichnis
  <Directory /var/www/energietracker/data>
    Require all denied
  </Directory>
</VirtualHost>
```

Braucht `mod_rewrite` (dazu `mod_headers`, `mod_setenvif`). Bis v2.5.3 stand
hier `AllowOverride None` — dann griffen die Regeln der `.htaccess` nicht.
Prüfen mit den Befehlen unter
[Sicherheit → Webserver](docs/technical/08-security.md#9-webserver-was-nicht-ausgeliefert-werden-darf).

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

  # Nutzdaten, Quelltext, Punktdateien (.git, .env): nie ausliefern.
  # Der vollständige Regelsatz steht in docker/nginx.conf.
  location ~ ^/(data|src|tests|scripts|docker|docs|vendor|demo-data)/ {
    return 404;
  }
  location ~ /\. {
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

## Produktiv: Docker (seit v1.7.3)

Single-Container-Image (nginx + php-fpm). Persistente Daten liegen im
gemounteten Volume unter `/data`.

**Schnellster Weg — docker compose:**

```bash
docker compose up -d        # → http://localhost:8080
```

**Oder direkt mit dem GHCR-Image:**

```bash
docker run -d --name energietracker \
  -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:2.13.0
```

**Oder lokal bauen:**

```bash
docker build -t energietracker .
docker run -d --name energietracker -p 8080:80 -v "$PWD/data:/data" energietracker
```

Konfiguration über Umgebungsvariablen (`docker run -e …` bzw. `environment:`
im Compose-File):

| Variable | Default | Wirkung |
|----------|---------|---------|
| `ET_DATA_DIR` | `/data` | Speicherpfad im Container |
| `ET_LOG_DEST` | `stderr` | `stderr` \| `file` \| `null` |
| `ET_LOG_LEVEL` | `info` | `debug` \| `info` \| `warning` \| `error` |
| `ET_LOG_FILE` | `<dataDir>/logs/app.log` | Pfad bei `ET_LOG_DEST=file` |

Die Logs (JSON Lines) erscheinen bei `ET_LOG_DEST=stderr` direkt in
`docker logs energietracker`. Der Container hat einen `HEALTHCHECK` gegen
`GET /api/health`.

## Demo-Daten laden (optional)

> **Am einfachsten ohne Dateisystem:** Das Repo enthält die Demo-Daten auch als
> fertiges JSON-Backup unter
> [`demo-data/energietracker-demo-backup.json`](demo-data/energietracker-demo-backup.json).
> In einem leeren Energietracker kannst du es direkt über
> *Einstellungen → Daten → Backup & Wiederherstellung → Backup importieren*
> einspielen (ab v1.7.4 gibt es dafür zusätzlich einen „Demo-Daten laden"-Button).
> Vor dem Import wird automatisch ein Snapshot deiner aktuellen Daten angelegt.

Klassisch per Dateikopie:

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
UI öffnen unter **Einstellungen → Daten → Backup & Wiederherstellung →
📦 Migration aus v0.9.0** und die JSON-Datei hochladen.

Detaillierte Anleitung in [`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md).

## Home Assistant anbinden (optional)

Du betreibst Home Assistant und möchtest Zählerstände automatisch übergeben
lassen? Der Energietracker hat dafür einen offiziellen Push-Endpoint
(`POST /api/ingest`) mit optionalem API-Token. Einrichtung direkt in der UI
unter **Einstellungen → Integrationen → 🏠 Home-Assistant-Anbindung**.

Schritt-für-Schritt inkl. REST-Command, Automatisierung und Use-Cases
(Eigenheim, Mietwohnung) in [`docs/HOME-ASSISTANT.md`](docs/HOME-ASSISTANT.md).
