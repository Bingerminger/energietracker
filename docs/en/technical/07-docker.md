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
- **Clean separation** of the app and your data — updates never change your data.
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
docker compose pull         # fetch a new image version
docker compose up -d        # … and restart with the new version
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
| `:1.7.3` | exactly this version | **Production** — predictable |
| `:1.7` | newest 1.7.x | bugfixes automatically |
| `:latest` | always the newest | for trying out |

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

With `docker run` via `-e NAME=value`, with Compose under `environment:`.

---

## Performing updates

```bash
# Compose
docker compose pull && docker compose up -d

# docker run
docker pull ghcr.io/bingerminger/energietracker:latest
docker rm -f energietracker
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" ghcr.io/bingerminger/energietracker:latest
```

Your data lives in the host volume and stays unchanged. Even so: **pull a backup
before larger updates** (UI → Export backup).

---

## Backing up & restoring data

- **Back up:** *Settings → Backup & Restore → Export backup* downloads a JSON file
  with all your data.
- **Restore:** the same place → *Import backup*. Before overwriting, Energietracker
  automatically creates a snapshot.
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
