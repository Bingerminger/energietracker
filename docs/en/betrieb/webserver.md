# Web server: Apache and nginx

[Deutsch](../../betrieb/webserver.md) · **English**

[← Compendium index](../README.md)

The Docker image brings its own web server ([Docker](docker.md)). This page is
for running on an existing web server with PHP 8.4 — Apache (including Synology
Web Station) or nginx. The rules are the same in both cases and come from the
tested configuration of the image (`docker/nginx.conf`) and the shipped
`.htaccess`:

1. **Serve only what is meant to be served.** `data/` (all user data and
   backups), `src/`, `tests/`, `scripts/`, `docker/`, `docs/`, `demo-data/`,
   `vendor/`, every dot file (`.git/`, `.env` …) and the project files in the
   root directory (`composer.json`, `Dockerfile`, `VERSION`, `*.md`) must never
   go out over HTTP.
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

---

## Apache

The shipped `.htaccess` implements points 1, 4 and 5, `data/.htaccess` is the
second safeguard. Requirements:

- `AllowOverride` at least `FileInfo` for the project directory,
- the modules `mod_rewrite`, `mod_headers` and `mod_setenvif`,
- PHP 8.4 (PHP-FPM or `mod_php`).

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

- **Web Station → Web service**: Apache 2.4 with a PHP 8.4 profile; put the
  folder under `web/`. The `.htaccess` is honoured there.
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
    client_max_body_size 32m;              # backup import, CSV upload

    # 1. only what is meant to be served
    location ~ ^/(data|src|tests|scripts|docker|docs|vendor|demo-data)/ { return 404; }
    location ~ /\. { return 404; }
    location ~* ^/[^/]+\.(md|json|lock|ya?ml|dist|txt|conf|sh|ini)$ { return 404; }
    location ~ ^/(Dockerfile|VERSION)$ { return 404; }

    # 2. API → api.php
    location ~ ^/api(\.php)?(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param SCRIPT_NAME /api.php;
        fastcgi_read_timeout 120s;
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
