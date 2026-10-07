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

This protects against foreign **websites**, not against foreign **people or
devices on the network** — that is what sign-in is for.

## 3. Switching sign-in on

**In the app:** Settings → Access → "Sign-in & access" → enter a password (at
least 8 characters) twice → "Switch sign-in on". This browser stays signed in;
every other one asks for the password once and then stays signed in for
**30 days**. Sign out with the button in the top bar.

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
- After **five** wrong passwords within 15 minutes sign-in is locked for
  **5 minutes**.
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
docker run --rm php:8.4-cli php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;'
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

Energietracker then takes the user from `Remote-User`, `X-Forwarded-User`,
`X-Remote-User` or — since v3.1.0 — `X-Remote-User-Name` or `X-Remote-User-Id`
(this is how Home Assistant reports the user under Ingress) — **only** for
requests from the addresses in `ET_TRUSTED_PROXIES`. From anywhere else the
header does not count, otherwise anyone could set it themselves. The proxy
must **remove** these headers from user requests before setting its own (the
standard behaviour of the services named). API keys work in proxy mode as
well.

Under Home Assistant Ingress the proxy is the Supervisor: `ET_AUTH=proxy` and
`ET_TRUSTED_PROXIES=172.30.32.2` — details in
[Home Assistant](../anleitungen/home-assistant.md).

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

**`ET_ALLOWED_HOSTS`** (opt-in) protects against *DNS rebinding*: a foreign
page points its domain to the address in your home network and would then be
"the same site" for the browser. With the list set, the app only answers known
host names and all others with `421`:

```bash
ET_ALLOWED_HOSTS=energy.example.org,*.fritz.box
```

IP addresses and `localhost` are always allowed. With sign-in switched on,
rebinding is ineffective anyway (the cookie does not belong to the foreign
domain); the list is the second line of defence for open operation.

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
| `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`, `vendor/`, `tools/`, `dist/`, `deploy/` | source code, tests, auxiliary files, templates (the last three since v3.1.0) |
| `.git/`, `.github/`, `.env` and all dotfiles | history, configuration |
| `composer.json`, `Dockerfile`, `VERSION`, `*.md` in the root directory | project files |

- **Docker (nginx):** configured in the image, nothing to do.
- **Apache (Synology Web Station, shared hosting):** the bundled `.htaccess`
  blocks these paths with `mod_rewrite` (404); `data/.htaccess` is the second
  safeguard. Prerequisite: `AllowOverride` at least `FileInfo` and an active
  `mod_rewrite` — the default in Web Station.
- **Own nginx:** adopt the `location` rules from `docker/nginx.conf`.
- **Development server:** always start it with the router —
  `php -S 127.0.0.1:8080 router.php`. Without `router.php` the PHP server serves
  every file, including `data/`. Never expose it on `0.0.0.0` to others.

**Check** (adjust the address) — all three must return `404`:

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
points to must be local (loopback, private networks, link-local,
`100.64.0.0/10`, IPv6 ULA). The check runs on every call after name
resolution, the connection goes to exactly the checked address, redirects are
not followed. So neither a typo nor a bent DNS entry (DNS rebinding) sends a
meter photo to the internet. There is no switch to lift this; empty means no
connection. The target is set by whoever may change the settings — with sign-in
switched on, only signed-in users and `admin` keys. Setup:
[Text recognition in the home network](../anleitungen/texterkennung.md).

## 10. Logging and troubleshooting

- `ET_DEBUG=1` writes file, line and exception type into error responses — set
  it only briefly for troubleshooting, never permanently in open operation.
- A `500` names an error ID (`error_id`); the log holds the same ID (Docker:
  `docker logs energietracker`).
- Snapshots and backups contain all user data. `data/auth.json` (password hash,
  token hashes, keys) is **not** part of the backup — after a move, set up
  sign-in and the token again.

## 11. Checklist before exposing it

- [ ] Sign-in switched on (§ 3) or proxy sign-in (§ 6)
- [ ] HTTPS via the reverse proxy (§ 7)
- [ ] Home Assistant with a token, the way back with a `read` key (§ 4)
- [ ] Calendar subscription only with a calendar key in the link (§ 5)
- [ ] `data/` & co. return `404` (§ 9)
- [ ] `ET_ALLOWED_HOSTS` set (§ 8)
- [ ] `ET_DEBUG` not set (§ 10)
- [ ] Better still: VPN instead of exposure (§ 1)

## 12. Found a vulnerability?

Please do **not** report it as a public issue, but as described in
[`SECURITY.md`](../../../SECURITY.md).

---

[← Compendium index](../README.md) · [Docker operation](docker.md) ·
[API reference](../referenz/api.md)
