# Docker operation (for beginners)

**English** · [Deutsch](../../betrieb/docker.md)

> Applies from **v1.7.3**. This chapter explains step by step how to run
> Energietracker as a Docker container — even without prior Docker knowledge. If you
> prefer a "classic" install with PHP/nginx, see
> [Installation & operation](installation.md).

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

For 32-bit ARM (`linux/arm/v7`, older Raspberry Pi and NAS) there is no published
image yet. A trial run in CI already builds and starts it (since v3.1.0); the
platform joins the published image once that run passes reliably.

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

### Folder or named volume?

- **Folder** (`-v "$PWD/data:/data"`, as in `docker-compose.yml`): the JSON
  files are visible on the host — handy on a NAS.
- **Named volume** (`-v energietracker-data:/data`): Docker manages the storage
  itself. The data survives updates just the same; back it up through the app
  (Settings → Data → Download JSON backup).

> **Docker Desktop (Mac, Windows):** a folder under your home directory can only
> be mounted if it is shared under Settings → Resources → File sharing.
> Otherwise the container does not start — it stays "Created", and Docker
> Desktop reports an error (`mounts denied`, in the interface often just
> "HTTP 500"). A named volume is the easiest choice there.

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

## Synology Container Manager

On a Synology with DSM 7.2 the image runs in the **Container Manager** (package
from the Package Center; ARM and Intel models, the image is multi-arch):

1. Create a folder for the data, for example `docker/energietracker` in the
   shared folder `docker`.
2. **Container Manager → Project → Create**, the same folder as path, "Create
   docker-compose.yml" as source and this content:

   ```yaml
   services:
     energietracker:
       image: ghcr.io/bingerminger/energietracker:latest
       container_name: energietracker
       restart: unless-stopped
       ports:
         - "8080:80"
       volumes:
         - ./data:/data
   ```

3. Start the project and open `http://<NAS address>:8080`.

Updates: in the project "Action → Build" after pulling the new image, or set the
tag in the file to the new version. HTTPS comes through the DSM reverse proxy
(Control Panel → Login Portal → Advanced → Reverse Proxy) with a Let's Encrypt
certificate. Without a container it also works through Web Station — see
[Web server](webserver.md#synology-web-station).

---

## Unraid

Since v3.1.0 the project ships a template for the Unraid Docker tab:
[`deploy/unraid/energietracker.xml`](../../../deploy/unraid/energietracker.xml).
Energietracker is not listed in Community Applications yet, so the template
goes onto the server by hand.

1. Load the template into the folder for your own templates (Unraid terminal,
   the `>_` icon at the top right):

   ```bash
   wget -O /boot/config/plugins/dockerMan/templates-user/my-Energietracker.xml \
     https://raw.githubusercontent.com/Bingerminger/energietracker/main/deploy/unraid/energietracker.xml
   ```

2. **Docker → Add Container**, under *Template* pick “Energietracker” (user
   templates).
3. The defaults usually fit: port **8080** on the host (the container always
   listens on 80), data under **`/mnt/user/appdata/energietracker`** (`/data`
   in the container). The `ET_*` variables are under *Show more settings*, all
   optional. **Apply** starts the container.
4. Open it via the container icon → *WebUI*.

The template uses `:latest`. If you want to apply updates deliberately, set a
fixed version under *Repository* (see [Which tag should I use?](#which-tag-should-i-use)).

**Permissions:** Unraid shares belong to `nobody:users` (99:100). On start the
container hands `/data` to its web server user `www-data` (UID 82) if the owner
is wrong — on the host the folder then belongs to UID 82. If the Unraid tool
*New Permissions* resets the permissions, restart the container; it changes
them back.

---

## CasaOS and ZimaOS

Since v3.1.0 a compose file in the CasaOS format is included:
[`deploy/casaos/docker-compose.yml`](../../../deploy/casaos/docker-compose.yml).
It pins the current version, port **8080**, data under
`/DATA/AppData/$AppID/data` (`/data` in the container).

1. In CasaOS (or ZimaOS), in the *Apps* area click **+** → **Custom Install**
   (install a customised app).
2. Choose **Import** at the top right, paste the file’s content and apply it.
3. Install, then open the app through its icon.

For an update, set the tag under `image:` and `version:` to the new version.

---

## Umbrel

[`deploy/umbrel/`](../../../deploy/umbrel/) contains, since v3.1.0, the template
for an Umbrel package (`umbrel-app.yml` and `docker-compose.yml`) — for your own
community app store or a later submission to the official store. Energietracker
is not listed there yet.

- Umbrel’s `app_proxy` sits in front of the app: whoever opens the interface
  signs in to Umbrel.
- The exception is the Home Assistant push: `/api/ingest` and
  `/api.php/api/ingest` are in `PROXY_AUTH_WHITELIST` and reachable without the
  Umbrel cookie. So create an ingest token in the app (Settings →
  Integrations), otherwise anyone on the network can send readings.
- Every other path requires the Umbrel sign-in, including `/api/summary`
  (values back to Home Assistant) and the calendar subscription. If you need
  them from outside, add them to `PROXY_AUTH_WHITELIST` and switch on the app’s
  own sign-in ([Security & network operation](sicherheit.md)).
- The data lives under `${APP_DATA_DIR}/data` (`/data` in the container).
- For a submission the image has to be pinned by digest (`repo:tag@sha256:…`);
  the file says what is still missing.

---

## Home Assistant

A Home Assistant app (formerly “add-on”) is in preparation. If you already embed
the app in Home Assistant or run it under Ingress, the specifics are in the
[Home Assistant](../anleitungen/home-assistant.md) guide.

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

1. **Convenient (from v1.7.4) in the UI:** *Settings → Data → Backup & restore →
   "Load demo data"*.
2. **Manually even now:** upload the bundled
   [`demo-data/energietracker-demo-backup.json`](../../../demo-data/energietracker-demo-backup.json)
   via *Settings → Data → Backup & restore → Import backup*. Beforehand a safety
   snapshot of your current data is created automatically (N1004).

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
[Security & network operation](sicherheit.md).

**PHP settings (since v2.6.0):** the image ships its own `php.ini` —
`max_execution_time` 120 s, error display off, OPcache on. Since v3.1.0
`post_max_size`/`upload_max_filesize` **256 MB** and `memory_limit` **768 MB**
(previously 32 MB and 256 MB), and nginx accepts requests up to 256 MB
(`client_max_body_size`, previously 32 MB): with receipts (photos, PDFs) a
backup quickly grows, and the import holds it about twice in memory while
restoring. Up to v2.5.3 the PHP defaults applied (128 MB, 8 MB); a backup with
many years of daily data could not be restored with them. Also since v3.1.0,
nginx waits up to **310 seconds** for PHP (`fastcgi_read_timeout`, previously
120 s): text recognition in the home network may take up to 300 seconds
(`ocr_timeout_s`), which happens on a NAS without a graphics card. Under Linux,
waiting for the service does not count towards `max_execution_time`. If your
own reverse proxy sits in front, its time limit applies as well.

**Health check:** the container queries `GET /api/health`. Since v2.6.0 the
endpoint answers a real fault (data not writable, corrupt file, data newer than
the app) with HTTP 503 — Docker then shows the container as *unhealthy*.

---

## Performing updates

1. **Export a backup:** *Settings → Data → Backup & restore → Download JSON
   backup*. This is your way back — a schema migration cannot be undone.
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

- **Back up:** *Settings → Data → Backup & restore → Download JSON backup*
  downloads a JSON file with all your data.
- **Restore:** the same place → *Import backup*. Since v2.6.0 the app checks the
  backup completely first and shows a preview; a faulty backup changes nothing.
  Before overwriting, Energietracker automatically creates a snapshot.
- **Snapshots** (since v2.6.0): *Settings → Data → Backup & restore → Stored
  snapshots* lists them with time and occasion; download, restore or delete
  them from there. Automatic snapshots are cleaned up after 30 days (at least
  three per occasion remain), your own after ten.
- On the file level everything is in the mounted `data/` folder — you can
  additionally back it up classically (copy it).
- **Receipts** (since v3.1.0): photos of meter readings live in
  `data/attachments/` and are part of every backup and every snapshot. So the
  volume grows with every photo — once for the file, once per snapshot in
  `data/backups/`. Settings → Data shows above the backup buttons how much the
  receipts take; `attachments_max_mb` sets the upper limit (default 500 MB).

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
| Data gone after `docker rm` | No `-v` volume mounted. Always `-v "$PWD/data:/data"` or a named volume. |
| Docker Desktop: container stays "Created", error `mounts denied` or "HTTP 500" | The data folder is not shared under Settings → Resources → File sharing. Share it or use a named volume: `-v energietracker-data:/data`. |
| Port 8080 occupied | Choose another host port, e.g. `-p 9000:80`. |
| "403/Permission denied" on `data` | The container sets the permissions on start; with your own host folder, check the write permissions if needed. |
| Unraid: “data not writable” after *New Permissions* | Restart the container — it hands `/data` back to `www-data` (UID 82). |
| Text recognition “cannot be reached” although Ollama runs on the same computer | Inside the container, `localhost` is the container itself. Enter the address of the computer in the home network and start Ollama with `OLLAMA_HOST=0.0.0.0` — [Text recognition in the home network](../anleitungen/texterkennung.md#if-the-energietracker-runs-in-docker). |
| Text recognition with a long time limit ends with `504` | Since v3.1.0 the image itself waits up to 310 s; a reverse proxy in front waits less. Set its time limit above 300 s or lower `ocr_timeout_s`. |

---

[← Compendium index](../README.md) · [Installation & operation](installation.md)
