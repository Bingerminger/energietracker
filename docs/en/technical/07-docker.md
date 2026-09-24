# Docker operation (for beginners)

**English** · [Deutsch](../../technical/07-docker.md)

> Applies from **v1.7.3**. This chapter explains step by step how to run
> Energietracker as a Docker container — even without prior Docker knowledge. If you
> prefer a "classic" install with PHP/nginx, see
> [Installation & operation](01-installation.md).

---

## Why Docker?

A **container** is a ready-packed box that already contains everything
Energietracker needs (PHP 8.4, nginx, the app code). You need to install **nothing**
on your computer/server except Docker. Advantages:

- **One command, runs everywhere** the same (Mac, Linux, Windows, Synology/NAS).
- **Clean separation** of the app and your data — a new container leaves your data
  directory in place. (If an update raises the data schema, Energietracker adapts
  the files once, see [Performing updates](#performing-updates).)
- **No manual PHP/nginx setup.**

For this you need **Docker Desktop** (Mac/Windows) or **Docker Engine** (Linux).
Check whether Docker is running:

```bash
docker --version
```

---

## The two building blocks: image and container

- **Image** = the immutable template (loaded from the internet). Address:
  `ghcr.io/bingerminger/energietracker`.
- **Container** = a running instance of this image.

The official image is **multi-arch** (`linux/amd64` **and** `linux/arm64`), so it
runs natively on Intel/AMD servers **and** on Apple Silicon (M1–M4) as well as ARM
NAS.

---

## Where is my data? (the most important part!)

Energietracker stores everything as JSON files in the container folder `/data`. **So
that your data survives a container update, you must "mirror" `/data` out of the
container onto your host** — this is called a *volume*.

```
-v "$PWD/data:/data"
   └── host folder ──┘ └─ folder in the container
```

`$PWD/data` is the subfolder `data` in the current directory. So your Energietracker
data always lives there, no matter how often you rebuild the container.

> ⚠️ **Without a `-v` volume your data is gone as soon as the container is
> deleted.** Always mount a volume.

---

## Variant A — `docker compose` (recommended)

This is the simplest and most robust way. You only need the bundled
`docker-compose.yml` from the project.

```bash
# in the project folder (where docker-compose.yml is)
docker compose up -d
```

- `up` starts the container, `-d` lets it run in the background.
- Open in the browser: **<http://localhost:8080>**
- The container is always called **`energietracker`** (set in the compose file via
  `container_name`) and restarts automatically after a reboot
  (`restart: unless-stopped`).

Useful follow-up commands:

```bash
docker compose logs -f      # watch the logs live (JSON Lines, see below)
docker compose down         # stop & remove the container (data stays!)
docker compose pull         # fetch the image named in docker-compose.yml
docker compose up -d        # … and restart with it
```

---

## Variant B — `docker run` (without Compose)

```bash
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:latest
```

Line by line:

| Part | Meaning |
|------|-----------|
| `-d` | in the background (detached) |
| `--name energietracker` | **fixed container name** |
| `-p 8080:80` | host port 8080 → container port 80 |
| `-v "$PWD/data:/data"` | data volume (see above) |
| `…/energietracker:latest` | the image including the tag |

> ⚠️ **If you leave out `--name energietracker`, Docker assigns a random name** like
> `thirsty_archimedes`. With `--name` the container is always called
> `energietracker` — clearer with `docker ps`, `docker logs` etc.

---

## Which tag should I use?

| Tag | Meaning | Recommendation |
|-----|-----------|------------|
| `:X.Y.Z` (e.g. the current version from the CHANGELOG) | exactly this version | **Production** — predictable |
| `:X.Y` | newest X.Y.z | bugfixes automatically |
| `:latest` | always the newest | for trying out |

The bundled `docker-compose.yml` pins a fixed version.

Best practice for a server: pin a **concrete version number** and perform updates
consciously.

---

## First start: demo data or empty?

On the very first start with an empty `data` volume, Energietracker creates a fresh,
empty data directory. If you want to see the **demo data** for trying out, there are
two ways:

1. **Convenient (from v1.7.4) in the UI:** *Settings → Backup & Restore → "Load demo
   data"*.
2. **Manually even now:** upload the bundled
   [`demo-data/energietracker-demo-backup.json`](../../../demo-data/energietracker-demo-backup.json)
   via *Settings → Backup & Restore → Import backup*. Beforehand a safety snapshot of
   your current data is created automatically (N1004).

---

## Viewing logs (troubleshooting)

Energietracker writes structured logs (one JSON object per line) to `stderr` — they
appear directly in the Docker logs:

```bash
docker logs -f energietracker
# or with Compose:
docker compose logs -f
```

You get more detail (e.g. one entry per HTTP request) by setting the log level to
`debug`:

```bash
docker run -e ET_LOG_LEVEL=debug …   # or in docker-compose.yml under environment:
```

---

## Configuration via environment variables

| Variable | Default | Effect |
|----------|---------|---------|
| `ET_DATA_DIR` | `/data` | data path in the container (normally do not change) |
| `ET_LOG_DEST` | `stderr` | `stderr` \| `file` \| `null` |
| `ET_LOG_LEVEL` | `info` | `debug` \| `info` \| `warning` \| `error` |
| `ET_LOG_FILE` | `<dataDir>/logs/app.log` | path when `ET_LOG_DEST=file` |
| `ET_AUTH` | *(empty)* | *(v2.6.0)* `off` \| `password` \| `proxy` — fixes the sign-in mode; empty = switchable in the settings. `ET_AUTH=off` is also the emergency exit for a forgotten password |
| `ET_ADMIN_PASSWORD_HASH` | *(empty)* | *(v2.6.0)* the password as a `password_hash()` value; in Compose double every `$` |
| `ET_TRUSTED_PROXIES` | *(empty)* | *(v2.6.0)* addresses/networks of the upstream proxy (comma-separated, CIDR allowed) — `Remote-User` only counts from there |
| `ET_ALLOWED_HOSTS` | *(empty = all)* | *(v2.6.0)* allowed host names, comma-separated, `*.domain` possible; others → 421. IP addresses and `localhost` always |
| `ET_FRAME_ANCESTORS` | *(empty)* | *(v2.6.0)* origins allowed to embed the app (e.g. the Home Assistant dashboard) |
| `ET_DEBUG` | *(empty)* | *(v2.6.0)* `1` = file/line in error responses — only briefly for troubleshooting |

With `docker run` via `-e NAME=value`, with Compose under `environment:`.
What sign-in does and when you need it:
[Security & network operation](08-security.md).

**PHP settings (since v2.6.0):** the image ships its own `php.ini` —
`memory_limit` 256 MB, `post_max_size`/`upload_max_filesize` 32 MB,
`max_execution_time` 120 s, error display off, OPcache on. Up to v2.5.3 the PHP
defaults applied (128 MB, 8 MB); a backup with many years of daily data could
not be restored with them.

**Health check:** the container queries `GET /api/health`. Since v2.6.0 the
endpoint answers a real fault (data not writable, corrupt file, data newer than
the app) with HTTP 503 — Docker then shows the container as *unhealthy*.

---

## Performing updates

1. **Export a backup:** *Settings → Backup & Restore → Export backup*. This is
   your way back — a schema migration cannot be undone.
2. **Read the CHANGELOG:** whatever is listed under “Migration” applies to you.
3. **Fetch the new version:**

```bash
# Compose: first set the tag in docker-compose.yml to the new version
# (or run `git pull` in the project folder — then it is already there)
docker compose pull && docker compose up -d

# docker run
docker pull ghcr.io/bingerminger/energietracker:X.Y.Z
docker rm -f energietracker
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" ghcr.io/bingerminger/energietracker:X.Y.Z
```

> `docker compose pull` fetches exactly the image named in `docker-compose.yml`.
> Without a changed tag you get the same version again.

Your data stays in the host volume. If the new version needs a new data schema,
Energietracker adapts the files on first start and first stores a snapshot in
`data/backups/` (`pre-migration-…`, since v2.5.3). It does not replace the way
back: an older version cannot read the new schema — you can only go back with the
old version **and** the backup from step 1. Since v2.6.0 an older version
recognises newer data and **writes nothing** (HTTP 503, `/api/health` reports
`error`) instead of silently stamping it back to the old schema as up to v2.5.3.

---

## Backing up & restoring data

- **Back up:** *Settings → Backup & Restore → Export backup* downloads a JSON file
  with all your data.
- **Restore:** the same place → *Import backup*. Since v2.6.0 the app checks the
  backup completely first and shows a preview; a faulty backup changes nothing.
  Before overwriting, Energietracker automatically creates a snapshot.
- **Snapshots** (since v2.6.0): *Settings → Backup & Restore → Stored snapshots*
  lists them with time and occasion; download, restore or delete them from
  there. Automatic snapshots are cleaned up after 30 days (at least three per
  occasion remain), your own after ten.
- On the file level everything is in the mounted `data/` folder — you can
  additionally back it up classically (copy it).

---

## Building your own image (optional)

If you have adapted the code yourself, build locally:

```bash
docker build -t energietracker .
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" energietracker
```

The image bundles nginx + php-fpm (held together by `supervisord`) and uses the
endpoint `GET /api/health` for the container `HEALTHCHECK`.

---

## Common problems

| Symptom | Cause / solution |
|---------|------------------|
| `no matching manifest for linux/arm64/v8` | An outdated image. From v1.7.3 it is multi-arch — run `docker pull …:latest` again. |
| Container called `nervous_…`/`thirsty_…` | Forgot `--name energietracker` (or use `docker compose`). |
| Data gone after `docker rm` | No `-v` volume mounted. Always `-v "$PWD/data:/data"`. |
| Port 8080 occupied | Choose another host port, e.g. `-p 9000:80`. |
| "403/Permission denied" on `data` | The container sets the permissions on start; with your own host folder, check the write permissions if needed. |

---

[← Compendium index](../README.md) · [Installation & operation](01-installation.md)
