# Installation

**English** · [Deutsch](INSTALL.de.md)

## Requirements

- PHP ≥ 8.4 (the CLI is sufficient for development)
- A web browser with ES modules (anything from mid-2020)
- Optional: Apache, nginx, Caddy for production

## Steps

```bash
git clone https://github.com/Bingerminger/energietracker.git
cd energietracker
```

**Check the directory structure:**

```
energietracker/
├── api.php
├── index.php
├── VERSION                ← 2.3.2
├── public/                ← CSS + JS
├── src/                   ← PHP backend
├── data/                  ← must be writable
├── demo-data/             ← optional dataset
└── ...
```

**Ensure `data/` is writable:**

```bash
chmod -R u+w data/
```

For Apache/nginx, set the owner to the web-server user if needed (typically
`www-data` or `nginx`):

```bash
sudo chown -R www-data:www-data data/
```

## Test locally

```bash
php -S 127.0.0.1:8080
```

Open <http://127.0.0.1:8080> in the browser. On the first request the app
initialises the `data/` directory automatically.

### Relocate the data directory (optional)

By default the JSON storage lives under `./data` relative to `api.php`. With the
environment variable `ET_DATA_DIR` you can force any absolute path (since v1.4.4)
— useful for separate data/code mounts or several instances:

```bash
ET_DATA_DIR=/srv/energietracker-data php -S 127.0.0.1:8080
```

For Apache/nginx the variable is set via `SetEnv` resp. `fastcgi_param
ET_DATA_DIR …`.

## Production: Apache

Example config (document root = project root):

```apache
<VirtualHost *:80>
  ServerName energietracker.example.com
  DocumentRoot /var/www/energietracker

  <Directory /var/www/energietracker>
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
  </Directory>

  # Protect the data directory
  <Directory /var/www/energietracker/data>
    Require all denied
  </Directory>
</VirtualHost>
```

## Production: nginx

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

## Production: Docker (since v1.7.3)

Single-container image (nginx + php-fpm). Persistent data lives in the mounted
volume under `/data`.

**Fastest way — docker compose:**

```bash
docker compose up -d        # → http://localhost:8080
```

**Or directly with the GHCR image:**

```bash
docker run -d --name energietracker \
  -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:2.3.2
```

**Or build locally:**

```bash
docker build -t energietracker .
docker run -d --name energietracker -p 8080:80 -v "$PWD/data:/data" energietracker
```

Configuration via environment variables (`docker run -e …` resp. `environment:`
in the Compose file):

| Variable | Default | Effect |
|----------|---------|--------|
| `ET_DATA_DIR` | `/data` | Storage path inside the container |
| `ET_LOG_DEST` | `stderr` | `stderr` \| `file` \| `null` |
| `ET_LOG_LEVEL` | `info` | `debug` \| `info` \| `warning` \| `error` |
| `ET_LOG_FILE` | `<dataDir>/logs/app.log` | Path when `ET_LOG_DEST=file` |

With `ET_LOG_DEST=stderr` the logs (JSON Lines) appear directly in
`docker logs energietracker`. The container has a `HEALTHCHECK` against
`GET /api/health`.

## Load demo data (optional)

> **Easiest, without the file system:** the repo also ships the demo data as a
> ready-made JSON backup under
> [`demo-data/energietracker-demo-backup.json`](demo-data/energietracker-demo-backup.json).
> In an empty Energietracker you can import it directly via
> *Settings → Backup & restore → Import backup* (since v1.7.4 there is also a
> "Load demo data" button). Before the import, a snapshot of your current data is
> created automatically.

Classic, via file copy:

```bash
find data/ -mindepth 1 -not -name '.gitkeep' -delete
cp -r demo-data/gas demo-data/strom demo-data/wasser \
      demo-data/fernwaerme demo-data/heizoel demo-data/pellets data/
mkdir -p data/backups
cp demo-data/meta.json demo-data/settings.json \
   demo-data/temperatures.json demo-data/reminders.json data/
```

> Alternatively, just copy the whole directory — the migrator is idempotent and
> the demo dataset already carries `schema_version 1.1.0` (since v1.4.4), so no
> migration run is needed:
>
> ```bash
> rm -rf data && cp -r demo-data data
> ```

## Migration from v0.9.0

If you have an old v0.9.0 backup: after installation, simply open it in the UI
under **Settings → Backup & restore → 📦 Migration from v0.9.0** and upload the
JSON file.

A detailed guide is in [`docs/MIGRATION-FROM-V090.md`](docs/MIGRATION-FROM-V090.md)
(German; English translation in progress).

## Connect Home Assistant (optional)

Do you run Home Assistant and want to have meter readings handed over
automatically? Energietracker has an official push endpoint for this
(`POST /api/ingest`) with an optional API token. Setup directly in the UI under
**Settings → 🏠 Home Assistant integration**.

Step-by-step instructions including REST command, automation and use cases
(detached house, rented flat) are in
[`docs/HOME-ASSISTANT.md`](docs/HOME-ASSISTANT.md) (German; English translation
in progress).
