# Installation

**English** · [Deutsch](INSTALL.de.md)

> 🔐 **Security:** without sign-in, anyone who can reach the app can read and
> change all data — fine in your own home network. Before making it reachable
> from outside (port forwarding, reverse proxy, QuickConnect), switch on sign-in
> (Settings → Access → "Sign-in & access") and read
> [Security & network operation](docs/en/betrieb/sicherheit.md). Better
> still: reach it on the road via VPN.

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
├── VERSION                ← 2.14.0
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
php -S 127.0.0.1:8080 router.php
```

Open <http://127.0.0.1:8080> in the browser. On the first request the app
initialises the `data/` directory automatically.

> Always start the built-in server **with `router.php`**: without it, PHP serves
> every file of the directory — including `data/` with all your data. And keep
> it on `127.0.0.1`; the built-in server is not meant for others on the network.

### Relocate the data directory (optional)

By default the JSON storage lives under `./data` relative to `api.php`. With the
environment variable `ET_DATA_DIR` you can force any absolute path (since v1.4.4)
— useful for separate data/code mounts or several instances:

```bash
ET_DATA_DIR=/srv/energietracker-data php -S 127.0.0.1:8080 router.php
```

For Apache/nginx the variable is set via `SetEnv` resp. `fastcgi_param
ET_DATA_DIR …`.

## Production: Apache or nginx

With Apache the shipped `.htaccess` does everything needed — it requires
`AllowOverride FileInfo` and the modules `mod_rewrite`, `mod_headers` and
`mod_setenvif`. For nginx the rules from `docker/nginx.conf` apply. Both in
full, with VirtualHost, `server` block and Synology Web Station:
**[Web server: Apache and nginx](docs/en/betrieb/webserver.md)**. Up to v2.13
this section had examples of its own that differed.

Check with the commands under
[Security → web server](docs/en/betrieb/sicherheit.md#9-web-server-what-must-not-be-served).

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
  ghcr.io/bingerminger/energietracker:2.14.0
```

> **Docker Desktop (Mac, Windows):** a folder under your home directory must be
> shared under Settings → Resources → File sharing, otherwise the container does
> not start (`mounts denied`, in the interface often just "HTTP 500"). A named
> volume is simpler: `-v energietracker-data:/data` — see
> [Docker](docs/en/betrieb/docker.md#folder-or-named-volume).

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

## Load sample data (optional)

Easiest in the app: on the empty overview **"Try with sample data"**, otherwise
Settings → Data → "Load demo data". The app saves a snapshot of the current
state first. The repository also ships the same data as a backup file:
[`demo-data/energietracker-demo-backup.json`](demo-data/energietracker-demo-backup.json).

By file copy only into an **empty** data directory — this keeps `data/.htaccess`
and `data/.gitkeep`:

```bash
cp -R demo-data/. data/
```

The files carry schema 1.1.0; on the first start the migrator upgrades them to
the current state. More in [`demo-data/README.md`](demo-data/README.md).

## Migration from v0.9.0

If you have an old v0.9.0 backup: after installation, simply open it in the UI
under **Settings → Data → Backup & restore → 📦 Migration from v0.9.0** and
upload the JSON file.

A detailed guide: [Moving from v0.9.0](docs/en/anleitungen/migration-v090.md).

## Connect Home Assistant (optional)

Do you run Home Assistant and want to have meter readings handed over
automatically? Energietracker has an official push endpoint for this
(`POST /api/ingest`) with an optional API token. Setup directly in the UI under
**Settings → Integrations → 🏠 Home Assistant integration**.

Step-by-step instructions including REST command, automation and use cases
(detached house, rented flat): [Connect Home Assistant](docs/en/anleitungen/home-assistant.md).
