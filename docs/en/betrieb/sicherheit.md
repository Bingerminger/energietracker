# Security & network operation

**English** · [Deutsch](../../betrieb/sicherheit.md)

> Applies from **v2.6.0**. This chapter describes whom Energietracker lets in,
> how to protect it and what to check before making it reachable beyond your
> own home network.

---

## 1. The operating model in one sentence

**Without sign-in, anyone who can reach the address can read, change and
delete everything** — meter readings, contracts, location, backups. That is the
default and meant for your own home network, where only you and your devices
are.

Switch sign-in on (§ 3) as soon as one of these applies:

- The app is reachable from outside: port forwarding on the router, reverse
  proxy, Synology QuickConnect, dynamic DNS.
- Others use the same network: shared flat, guest Wi-Fi without isolation,
  apartment building with a shared network.
- The data is not only yours (letting, property management).

> **Not onto the internet without protection.** The best way to reach it on
> the road is a VPN (WireGuard, Tailscale, the VPN of your router or NAS): the
> app stays in the home network and you dial in. Whoever makes it publicly
> reachable anyway needs HTTPS **and** sign-in — either the built-in one (§ 3)
> or that of an upstream proxy (§ 6).

## 2. What is protected even without sign-in

Since v2.5.3/v2.6.0 the following also applies in open operation:

| Protection | Effect |
|---|---|
| Foreign websites | Another page open in your browser cannot change anything (check of `Sec-Fetch-Site`/`Origin`, answer 403) and cannot embed the app invisibly (`frame-ancestors`, § 8). |
| Web server rules | `data/` (all user data and backups), `src/`, `.git/` and other non-deliverable files are not served (§ 9). |
| Content Security Policy | Only scripts of your own installation run — a second line of defence against injected HTML. |
| Error messages | No paths, file names or line numbers in API responses (only with `ET_DEBUG=1`); a server error names an error ID, the details are in the log. |
| Receipts *(v3.1.0)* | Photos and PDFs are checked by their content and only served through the API, a PDF without scripts (§ 9). |
| Text recognition *(v3.1.0)* | The server only connects to addresses in your own network, checked on every call (§ 9). |
| Size and write lock *(v3.4.0)* | Large requests only where they belong, the body is read only after sign-in; a slow service no longer holds up any write (§ 12). |
| Host list *(v3.4.0)* | Can be set in the settings, so a foreign site cannot point its name at your address (§ 8). |

This protects against foreign **websites**, not against foreign **people or
devices on the network** — that is what sign-in is for.

## 3. Switching sign-in on

**In the app:** Settings → Access → "Sign-in & access" → enter a password (at
least 8 characters) twice → "Switch sign-in on". This browser stays signed in;
every other one asks for the password once and then stays signed in — since
v3.4.0 for **30 days after the last use** (sliding; up to v3.3 30 days after
signing in). Sign out with the button in the top bar. Signing out deletes this
browser's cookie and the app's stored responses in its cache (complete again
since v3.4.0; v3.1 to v3.3 left them behind).

**Sign out everywhere** *(v3.4.0)*: Settings → General → "My account" or
Settings → Access. All sessions of this person end on every device, including
this one — for example after a lost phone or if someone might have copied the
cookie. API keys and the Home Assistant token stay valid; revoke them
individually.

What changes:

- Every API route requires a session (browser) or an API key (scripts, § 5).
  Without either: `401`.
- **Home Assistant then needs a token** (§ 4) — without a token the push
  rejects every value. Values back to Home Assistant also need an API key
  `read` (§ 5).
- The **calendar subscription** needs a calendar key in the link (§ 5); the app
  creates it when you subscribe (since v3.1.0).
- Without sign-in `/api/health` only answers `{status, version}` — enough for
  Docker and uptime monitors.
- After **five** wrong passwords within 15 minutes sign-in is locked **for
  this device for 5 minutes**; ten failed attempts for the same name lock
  that name, a hundred in total lock everyone (emergency brake). Up to v3.3
  one lock applied to the whole household — any device on the network could
  lock everyone out. Behind a reverse proxy the address from
  `X-Forwarded-For` counts, but only if the proxy is listed in
  `ET_TRUSTED_PROXIES`.
- The response time does not reveal whether a name exists (v3.4.0).
- A new password signs out all other devices.

**Forgot the password?** On the server, set `"mode"` to `"off"` in
`data/auth.json` — sign-in is then off and you can set a new password in the
settings. In the Docker container it is simpler: start the container with
`ET_AUTH=off`.

### Via environment variables (Docker)

| Variable | Effect |
|---|---|
| `ET_AUTH` | `off`, `password` or `proxy` — fixes the mode; the settings can then not change it |
| `ET_ADMIN_PASSWORD_HASH` | the password as a hash instead of in `data/auth.json`; the app can then not change it |

Create a hash:

```bash
php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;'
# or without a local PHP:
docker run --rm php:8.5-cli php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;'
```

> In `docker-compose.yml` double every `$` in the hash (`$$2y$$10$$…`) —
> otherwise Compose reads `$` as a variable and silently truncates the hash.

## 4. Home Assistant

The **token** protects only the push endpoint `/api/ingest` — not the rest of
the API. Without sign-in it is optional; with sign-in it is mandatory. Setup:
[Home Assistant](../anleitungen/home-assistant.md).

- Settings → Integrations → "Home Assistant integration" shows when a value last
  arrived with the token (accurate to the hour) — the first question when
  troubleshooting.
- A new token invalidates the old one immediately — then enter the new one in
  Home Assistant (`secrets.yaml`) and restart Home Assistant.
- If a meter reading drops compared with the previous one (sensor dropout,
  swap), it is stored but **marked as suspect** and only counts after your
  confirmation — so a 0 from Home Assistant creates no phantom consumption.
- **Values back to Home Assistant** (since v3.1.0): the REST sensors read
  `GET /api/summary`. The token does not apply there — with sign-in switched
  on, Home Assistant needs an API key with the `read` permission (§ 5), stored
  in `secrets.yaml` as `energietracker_read`. It can only read; an `admin` key
  does not belong in Home Assistant.

## 5. API keys for scripts

With sign-in switched on, scripts and other programs need a key: Settings →
Access → "Sign-in & access" → "API keys for scripts". The key (`etk_…`) is shown
**once**; only its hash is stored.

| Permission | may |
|---|---|
| Read (`read`) | fetch only (`GET`) — e.g. for the REST sensors in Home Assistant (`/api/summary`) or an evaluation |
| Manage (`admin`) | everything, including changing and deleting — e.g. for a backup script that also restores |
| Calendar (`calendar`, since v3.1.0) | only the calendar subscription `GET /api/calendar.ics`, and only as `?token=…` in the link |

```bash
curl -H "Authorization: Bearer etk_…" https://energy.example.org/api.php/api/backup/export > backup.json
```

The list shows for every key when it was last used. Revoke keys you no longer
need.

**Calendar key.** Calendar apps cannot send a header; the key therefore sits in
the link and ends up in calendar syncs and logs. That is why it is narrowly
scoped:

- It only works for `/api/calendar.ics` and only as `?token=…`. On any other
  route, including as an `Authorization` header, the app answers `401`.
- A `read` or `admin` key in the link is rejected (`401`) — otherwise a key
  with read access to everything would sit in every calendar app.
- The app creates the key when you choose “Subscribe in your calendar” under
  Reminders & tips → Reminders & maintenance and confirm the question with
  “Create link” — not merely on opening. Revoke it under Settings → Access; the
  calendar then receives nothing more. Setup:
  [Subscribing to the calendar](../anleitungen/kalender.md).

## 6. Sign-in via an upstream proxy

If you already run a sign-in service (Authelia, Authentik, oauth2-proxy,
Synology SSO behind a reverse proxy), let it handle sign-in:

```bash
ET_AUTH=proxy
ET_TRUSTED_PROXIES=172.18.0.0/16   # address(es) or networks of the proxy, comma-separated
```

The proxy reports the user in a header — and it counts **only** for requests
from the addresses in `ET_TRUSTED_PROXIES`. From anywhere else it does not
count, otherwise anyone could set it themselves. Which header counts (since
v3.4.0):

| Operation | What counts |
|---|---|
| `ET_PROXY_USER_HEADER` is set (e.g. `X-Forwarded-User`, `Remote-User`) | exactly this one header — **recommended** for every proxy except Home Assistant |
| Home Assistant Ingress (recognised by `X-Ingress-Path` or `X-Remote-User-Id`) | only `X-Remote-User-Id` and `X-Remote-User-Name`; the person is kept by the stable ID (renaming in Home Assistant does not create a new person) |
| neither | as up to v3.3, the first non-empty one of `Remote-User`, `X-Forwarded-User`, `X-Remote-User`, `X-Remote-User-Name`, `X-Remote-User-Id` — with a warning in the security check |

```bash
ET_PROXY_USER_HEADER=X-Forwarded-User   # the header your proxy sets
```

Why one fixed header: some proxies only remove their own header from user
requests and pass others through unchanged. If the first header of a list
counts, a signed-in person can then pose as another — including an
administrator. With `ET_PROXY_USER_HEADER` only the header the proxy sets
itself counts. API keys work in proxy mode as well.

Under Home Assistant Ingress the proxy is the Supervisor: `ET_AUTH=proxy` and
`ET_TRUSTED_PROXIES=172.30.32.2`; leave `ET_PROXY_USER_HEADER` empty. Since
v3.4.0 the app also reports the internal host name for the templates only to
the Supervisor. Details in [Home Assistant](../anleitungen/home-assistant.md).

## 7. HTTPS

The app itself does not speak TLS — the reverse proxy does (Caddy, nginx,
Traefik, Synology). The session cookie automatically gets the `Secure`
attribute when the request arrives via HTTPS or the proxy sets
`X-Forwarded-Proto: https`.

**HTTPS pays off in the home network too** (v2.11.0). Browsers allow some
features only in a secure context, that is via HTTPS or `localhost`:

- The service worker, and with it the offline view, does not run via
  `http://<NAS address>`.
- The home-screen app on the iPhone is then only a bookmark.

Copying (token, YAML, annual consumption) works without HTTPS since v2.11.0;
the app has a fallback for it. A reverse proxy with a certificate unlocks
the rest, for example the Synology with Let's Encrypt, or Caddy.

## 8. Host names and embedding

A **host list** (opt-in) protects against *DNS rebinding*: a foreign page
points its domain to the address in your home network and would then be "the
same site" for the browser. With the list set, the app only answers known host
names and all others with `421`. Two ways, both apply together:

- **In the app** *(v3.4.0)*: Settings → Access → "Host names" (administrators
  only, setting `allowed_hosts`). For Apache and the Synology, which have no
  environment variables. When saving, the name you are using right now must
  be included — otherwise the app refuses instead of locking you out. The
  security check (Settings → Access) reports open operation without a host
  list and offers to take over the current name.
- **Via the environment:**

```bash
ET_ALLOWED_HOSTS=energy.example.org,*.fritz.box
```

IP addresses and `localhost` are always allowed — whoever opens the app by its
address always gets in. With sign-in switched on, rebinding is ineffective
anyway (the cookie does not belong to the foreign domain); the list is the
second line of defence for open operation.

**Embedding:** foreign pages may not show the app in a frame — otherwise clicks
on "Load demo data" or "Generate token" could be slipped in. For a webpage card
on a Home Assistant dashboard, enter its address: Settings → Access →
"Embedding", or
`ET_FRAME_ANCESTORS=http://homeassistant.local:8123`. Origins are allowed
(scheme, host, optional port, also `*.domain`), several separated by spaces.

## 9. Web server: what must not be served

| Path | Content |
|---|---|
| `data/` | all user data, `auth.json`, backups and snapshots |
| `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`, `vendor/`, `tools/`, `dist/`, `deploy/` | source code, tests, auxiliary files, templates (the last three since v3.1.0). Since v3.4.0 the app reads the handbook under `docs/` via `GET /api/docs` — only from an allowlist and with sign-in when that is on; directly it stays blocked |
| `.git/`, `.github/`, `.env` and all dotfiles | history, configuration |
| `composer.json`, `Dockerfile`, `VERSION`, `*.md` in the root directory | project files |

- **Docker (nginx):** configured in the image, nothing to do.
- **Apache (Synology Web Station, shared hosting):** the bundled `.htaccess`
  blocks these paths with `mod_rewrite` (404); `data/.htaccess` is the second
  safeguard. Prerequisite: `AllowOverride` at least `FileInfo` and an active
  `mod_rewrite` — the default in Web Station. If `mod_rewrite` is missing,
  `mod_alias` blocks the same paths since v3.4.0 (`RedirectMatch 404`, also
  only `FileInfo`); up to v3.3 the protection was then silently off.
- **Own nginx:** adopt the `location` rules from `docker/nginx.conf`.
- **Development server:** always start it with the router —
  `php -S 127.0.0.1:8080 router.php`. Without `router.php` the PHP server serves
  every file, including `data/`. Never expose it on `0.0.0.0` to others.

**Self-test in the app** *(v3.4.0)*: Settings → Access (and System) →
"Security check". The browser itself asks for `data/meta.json` there; if the
web server serves the file, the item turns red — then the rules have no effect
(`AllowOverride` too narrow or neither module active).

**Check by hand** (adjust the address) — all three must return `404`:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/data/meta.json
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/.git/config
curl -s -o /dev/null -w '%{http_code}\n' http://nas.local/energietracker/src/bootstrap.php
```

### Receipts and text recognition *(v3.1.0)*

**Receipts.** Photos and PDFs live under `data/attachments/` — blocked for the
web server like the rest of `data/`. They only come out through
`GET /api/attachments/{id}`, i.e. with the same sign-in as everything else.

- Only what is a JPEG, PNG, WebP or PDF by its **content** (the first bytes) is
  accepted; the extension and `Content-Type` do not count. The app rejects SVG
  and HTML, which could carry scripts.
- Files are served with the stored type and `X-Content-Type-Options: nosniff`
  — the browser does not guess what the file might be.
- A PDF comes with `Content-Security-Policy: sandbox`: it runs without scripts
  and without access to the app.
- Limits: photo 3 MB, PDF 10 MB, all together `attachments_max_mb`.

**Privacy of the photo.** The browser scales a meter photo down before the
upload and re-encodes it as JPEG. This drops the EXIF data — including the GPS
location that phones often store. Backups and snapshots contain the photos:
whoever passes on a backup passes on the photos.

**Text recognition in the home network only.** If a text recognition service
is set up (`ocr_endpoint`), the **server** connects to it — never the browser,
whose Content Security Policy only allows its own site. Every address the name
points to must be local (loopback, private networks, `100.64.0.0/10`, IPv6
ULA). Link-local (`169.254.0.0/16`, `fe80::/10`) no longer counts since
v3.4.0: on rented servers that is where the providers' metadata services
answer. The check runs on every call after name
resolution, the connection goes to exactly the checked address, redirects are
not followed. So neither a typo nor a bent DNS entry (DNS rebinding) sends a
meter photo to the internet. There is no switch to lift this; empty means no
connection. The target is set by whoever may change the settings — with sign-in
switched on, only signed-in users and `admin` keys. Setup:
[Text recognition in the home network](../anleitungen/texterkennung.md).

**Outgoing connections** *(v3.4.0)*: weather (Open-Meteo) and exchange prices
(SMARD) only via HTTPS, also after a redirect, and at most two redirects; text
recognition and evcc in the home network without redirects. Every response at
most 20 MB — the app aborts a larger one instead of loading it into memory.

## 10. Logging and troubleshooting

- `ET_DEBUG=1` writes file, line and exception type into error responses — set
  it only briefly for troubleshooting, never permanently in open operation.
- A `500` names an error ID (`error_id`); the log holds the same ID (Docker:
  `docker logs energietracker`).
- Snapshots and backups contain all user data. `data/auth.json` (password hash,
  token hashes, keys) is **not** part of the backup — after a move, set up
  sign-in and the token again.

## 11. Checklist before exposing it

- [ ] Sign-in switched on (§ 3) or proxy sign-in with `ET_PROXY_USER_HEADER` (§ 6)
- [ ] HTTPS via the reverse proxy (§ 7)
- [ ] Home Assistant with a token, the way back with a `read` key (§ 4)
- [ ] Calendar subscription only with a calendar key in the link (§ 5)
- [ ] `data/` & co. return `404` — the security check shows it (§ 9)
- [ ] Host list set: Settings → Access or `ET_ALLOWED_HOSTS` (§ 8)
- [ ] `ET_DEBUG` not set (§ 10)
- [ ] Docker: hardened, port only on `127.0.0.1` behind the reverse proxy (§ 13)
- [ ] Better still: VPN instead of exposure (§ 1)

## 12. Size limits and write lock *(v3.4.0)*

**Size of a request.** The app checks the declared size before it reads a
single byte, and reads the body only after sign-in. Up to v3.3 it read every
request in full beforehand — a few parallel requests from one device without
sign-in brought a NAS to its memory limit.

| Route | At most |
|---|---|
| reachable without sign-in: `POST /api/ingest`, `POST /api/session` | 1 MB |
| backup import, receipts, CSV and time-series imports (`…/import-csv`, `…/import-series`, `…/import-evcc`) | 256 MB |
| all others | 8 MB |

Above that the app answers `413`. In the Docker image nginx sets the same
limits (`client_max_body_size` 8 MB, 256 MB only for these routes). With
**Apache** the equivalent belongs in the server or VirtualHost configuration —
`LimitRequestBody` is only allowed in `.htaccess` with `AllowOverride All` and
would otherwise answer every request with `500`:

```apache
LimitRequestBody 8388608
<LocationMatch "/api(\.php/api)?/(backup/import|attachments|.+/import-(csv|series|evcc))$">
    LimitRequestBody 268435456
</LocationMatch>
```

**Write lock.** Writing requests run one after another (otherwise changes would
be lost with simultaneous writers). Since v3.4.0 a call to an outside service
no longer holds the lock: text recognition writes nothing and does not lock at
all, weather, SMARD and evcc first fetch the data and only lock for writing.
Up to v3.3 a text recognition blocked every write for up to five minutes,
including the Home Assistant push. If a request waits longer than 15 seconds
for the lock, the app answers `503` with `Retry-After: 5` instead of waiting
forever (`ET_LOCK_WAIT_S` changes the deadline, 1 to 120 seconds).

## 13. Hardening the container *(v3.4.0)*

**php-fpm internal only.** The base image lets php-fpm listen on all
interfaces. Since v3.4.0 the image binds it to `127.0.0.1`; other containers on
the same Docker network — with `network_mode: host` every device on the
network — no longer reach FastCGI directly, past any sign-in. CI checks this on
every run against the running container.

**Hardened operation.** If you like, start the container with fewer rights: no
new privileges, only the capabilities needed, a read-only file system and the
port only on `127.0.0.1` behind a reverse proxy. The example is in
[Docker operation](docker.md#hardened-operation-v340); CI starts the image
exactly like that on every run.

**Current base image.** Every week GitHub rebuilds the latest release tag on
the current base image (same code, new packages) and scans the image for known
vulnerabilities. A pinned tag such as `3.4.0` thus receives security updates of
Alpine and PHP without anything in the app changing.

## 14. Found a vulnerability?

Please do **not** report it as a public issue, but as described in
[`SECURITY.md`](../../../SECURITY.md).

---

[← Compendium index](../README.md) · [Docker operation](docker.md) ·
[API reference](../referenz/api.md)
