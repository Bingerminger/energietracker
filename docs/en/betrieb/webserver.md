# Web server: Apache and nginx

[Deutsch](../../betrieb/webserver.md) · **English**

[← Compendium index](../README.md)

The Docker image brings its own web server ([Docker](docker.md)). This page is
for running on an existing web server with PHP 8.2 or newer (since v3.1.0;
8.4 recommended) — Apache (including Synology Web Station) or nginx. The rules
are the same in both cases and come from the tested configuration of the image
(`docker/nginx.conf`) and the shipped `.htaccess`:

1. **Serve only what is meant to be served.** `data/` (all user data and
   backups), `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`,
   `vendor/`, since v3.1.0 also `tools/`, `dist/` and `deploy/` (templates for
   Unraid, CasaOS and Umbrel), every dot file (`.git/`, `.env` …) and the
   project files in the root directory (`composer.json`, `Dockerfile`,
   `VERSION`, `*.md`) must never go out over HTTP.
2. **API through `api.php`.** The interface calls `api.php/api/…`; no rewriting
   is needed.
3. **Interface through `index.php`.** Navigation uses the hash (`#/…`).
4. **Cache headers.** Always revalidate application code and language
   catalogues, keep fonts for long — otherwise a browser serves old modules
   after an update ([Troubleshooting](fehlersuche.md)).
5. **Pass the `Authorization` header on to PHP** — otherwise the Home Assistant
   push fails with 401.
6. **Write permission** for the web server user on `data/`
   ([Installation → Write permissions](installation.md#33-write-permissions)).
7. **Enough room for large requests** — since v3.1.0 the backup contains the
   receipts ([Upload limits](#upload-limits-and-php-memory-v310)).

---

## Apache

The shipped `.htaccess` implements points 1, 4 and 5, `data/.htaccess` is the
second safeguard. Requirements:

- `AllowOverride` at least `FileInfo` for the project directory,
- the modules `mod_rewrite`, `mod_headers` and `mod_setenvif`,
- PHP 8.2 or newer (PHP-FPM or `mod_php`).

A VirtualHost for it:

```apache
<VirtualHost *:80>
    ServerName energietracker.example
    DocumentRoot /var/www/energietracker

    <Directory /var/www/energietracker>
        AllowOverride FileInfo
        DirectoryIndex index.php
        Require all granted
    </Directory>

    # second safeguard in case the .htaccess is ever missing
    <Directory /var/www/energietracker/data>
        Require all denied
    </Directory>

    # optional: data outside the web root
    # SetEnv ET_DATA_DIR /srv/energietracker-data
</VirtualHost>
```

`AllowOverride None` silences the `.htaccess` — then Apache serves `data/`, and
the cache rules are missing. Running in a sub-directory (such as
`/energietracker/`) works without changes; the rules of the `.htaccess` are
relative.

### Synology Web Station

- **Web Station → Web service**: Apache 2.4 with a PHP profile from 8.2 (8.4
  if offered); put the folder under `web/`. The `.htaccess` is honoured there.
- The user `http` needs write permission on `data/` (File Station → Properties →
  Permission).
- HTTPS: certificate under Control Panel → Security → Certificate (Let's
  Encrypt), forwarding under Control Panel → Login Portal → Advanced → Reverse
  Proxy.
- Alternatively as a container in **Container Manager** — see
  [Docker](docker.md#synology-container-manager).

## nginx

Derived from `docker/nginx.conf`; adjust the socket path and the root directory.
The order matters: the API rule comes before the general block on `.php` files.

```nginx
server {
    listen 80;
    server_name energietracker.example;
    root /var/www/energietracker;
    index index.php;
    client_max_body_size 256m;             # backup import with receipts, CSV upload (v3.1.0; previously 32m)

    # 1. only what is meant to be served
    location ~ ^/(data|src|tests|scripts|docker|docs|vendor|demo-data|tools|dist|deploy)/ { return 404; }
    location ~ /\. { return 404; }
    location ~* ^/[^/]+\.(md|json|lock|ya?ml|dist|txt|conf|sh|ini)$ { return 404; }
    location ~ ^/(Dockerfile|VERSION)$ { return 404; }

    # 2. API → api.php
    location ~ ^/api(\.php)?(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_read_timeout 310s;         # text recognition up to 300 s (v3.1.0; previously 120s)
        # fastcgi_param ET_DATA_DIR /srv/energietracker-data;
    }

    # never serve other PHP source raw
    location ~ \.php$ { return 404; }

    # 4. cache headers
    location ~ ^/public/(js|locales)/ {
        add_header Cache-Control "no-cache, must-revalidate" always;
        try_files $uri =404;
    }
    location ~ \.(woff2?|ttf|eot)$ {
        add_header Cache-Control "public, max-age=31536000, immutable" always;
        try_files $uri =404;
    }

    # 3. static file or the interface
    location / { try_files $uri @spa; }
    location @spa {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
    }
}
```

nginx passes the `Authorization` header on to PHP-FPM by itself (point 5). For a
sub-directory every pattern would need the prefix — a host name of its own is
simpler.

## Upload limits and PHP memory *(v3.1.0)*

Since v3.1.0 a backup contains the receipts (photos, PDFs) base64-encoded — so
it is about a third larger than the files themselves and grows with every
photo. The Docker image is set up for this; on your own web server you set the
same values:

| Where | Setting | Docker image | What for |
|---|---|---|---|
| PHP | `post_max_size`, `upload_max_filesize` | `256M` | the largest backup import, a PDF receipt up to 10 MB |
| PHP | `memory_limit` | `768M` | importing a large backup needs about twice its size in PHP memory |
| PHP | `max_execution_time` | `120` | large imports |
| nginx | `client_max_body_size` | `256m` | otherwise `413 Request Entity Too Large` before PHP |
| nginx | `fastcgi_read_timeout` | `310s` | large imports, text recognition: its time limit (`ocr_timeout_s`) goes up to 300 s; previously `120s`, when nginx cut off a slow recognition before PHP |

- **PHP values** go into `php.ini` or the PHP-FPM pool
  (`php_admin_value[post_max_size] = 256M`); in Synology Web Station into the
  PHP profile under the script language settings.
- **Apache** limits the body itself with `LimitRequestBody`; current versions
  allow 1 GB by default — that is enough.
- For photos (at most 3 MB) the PHP defaults (8 MB) are enough. For PDF
  receipts and backups with many photos raise the limits — otherwise the upload
  aborts ([Troubleshooting](fehlersuche.md)).
- `memory_limit` is an upper bound; the memory is only needed while restoring
  a large backup.
- An upload above `post_max_size` reaches PHP empty; the app recognises this
  and answers with `400` and `errors.attachment.size` including the limit
  (“The file is too large – … MB at most.”).
- If a reverse proxy sits in front, it too needs a time limit above 300
  seconds when text recognition may take long.

## PHP's own server — only for trying out

```bash
php -S 127.0.0.1:8080 router.php
```

Always with `router.php`: it implements the same rules. Without it, the PHP
server serves every file, including `data/`. It handles one request after the
other and does not belong in permanent use; never expose it on `0.0.0.0` to
others.

## Check

After setting up, `data/`, `.git/` and `src/` must answer with 404 — the
commands are in
[Security & network operation → Web server](sicherheit.md#9-web-server-what-must-not-be-served).
Before allowing access from outside: sign-in and HTTPS
([Security & network operation](sicherheit.md)).

---

[← Compendium index](../README.md)
