# Energietracker — Single-Container-Image (N1005, v1.7.3).
#
# nginx + php-fpm in einem Image, von supervisord zusammengehalten.
# Bewusst ein Container für einen reibungslosen Self-Hosting-Start:
#   docker run -p 8080:80 -v ./data:/data ghcr.io/bingerminger/energietracker
#
# Die Laufzeit bleibt Composer-frei (Hand-rolled-Autoloader in
# src/bootstrap.php) — es wird KEIN `composer install` ausgeführt.
# v3.4.0 — PHP 8.5 (Dependabot #27); die CI prüft 8.2 bis 8.5.
#
# v3.5.0 (N1024) — zwei Ziele aus dieser einen Datei, mit gemeinsamer Stufe
# `base` (Pakete, PHP-Konfiguration, App-Code):
#   docker build .                    → Standard (letzte Stufe, wie bisher)
#   docker build --target rootless .  → ohne root: UID 10001, Gruppe 0, Port 8080
FROM php:8.5-fpm-alpine AS base

# v3.0.0 — Lizenz und Quelle auch bei lokal gebauten Images (das GHCR-Image
# bekommt dieselben Angaben zusätzlich aus docker/metadata-action).
LABEL org.opencontainers.image.title="Energietracker" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/Bingerminger/energietracker"

# nginx + supervisor für den Single-Container-Betrieb; OPcache ist im Image
# enthalten und wird nur eingeschaltet (v2.6.0). v3.4.0 — ab PHP 8.5 ist
# OPcache fest eingebaut; docker-php-ext-enable scheiterte dort, deshalb nur,
# wenn es noch nicht geladen ist.
RUN apk add --no-cache nginx supervisor \
    && if ! php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then docker-php-ext-enable opcache; fi

WORKDIR /app

# Laufzeit-Konfiguration zuerst (bessere Layer-Caches).
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php-fpm-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
# v3.4.0 (N1020) — php-fpm nur auf 127.0.0.1 (lädt nach zz-docker.conf des Basis-Images)
COPY docker/php-fpm-listen.conf /usr/local/etc/php-fpm.d/zzz-energietracker.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-energietracker.ini
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh

# Anwendungscode (Dev-/VCS-Ballast hält .dockerignore raus).
COPY . /app

# v2.6.0 — Der Code gehört root und ist für PHP nur lesbar; schreibbar ist
# allein /data. Bis v2.5.3 gehörte /app www-data — PHP hätte den eigenen Code
# überschreiben können.
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R root:root /app \
    && chmod -R a+rX,go-w /app

# Standardwerte; im docker-compose oder per `docker run -e` überschreibbar.
ENV ET_DATA_DIR=/data \
    ET_LOG_DEST=stderr \
    ET_LOG_LEVEL=info

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]


# ─── Variante ohne root (N1024, v3.5.0) ─────────────────────────────────────
# Tags `X.Y.Z-rootless`, `X.Y-rootless`, `latest-rootless`. Dieselbe App,
# dieselben Umgebungsvariablen, dasselbe /data — aber kein Prozess läuft als
# root, der Container braucht keine Capabilities, und der Start stellt keine
# Rechte um (entrypoint.sh, ET_IMAGE_VARIANT).
#
# Feste UID 10001 mit Gruppe 0 (die root-Gruppe, ohne root-Rechte): /data
# gehört 10001:0 mit gleichen Rechten für die Gruppe (g=u). So schreibt auch
# eine beliebige andere UID mit Gruppe 0 (`user: "1234:0"`, wie OpenShift und
# manche NAS-Oberflächen es vergeben) in ein frisches Named Volume. Alles
# andere, was beschreibbar sein muss (PID-Dateien, Zwischenspeicher von nginx,
# PHP-Uploads), liegt unter /tmp — beschreibbar für jede UID, mit
# `read_only: true` als tmpfs.
FROM base AS rootless

# nginx und supervisord: dieselben Dateien wie im Standard-Image, nur Port
# 8080, PID-Dateien und Zwischenspeicher unter /tmp, kein Benutzerwechsel.
# Abgeleitet statt kopiert, damit die Sperrregeln (data/, src/, Doku …) nicht
# auseinanderlaufen. Greift eine Ersetzung nicht, bricht der Bau ab (grep);
# RootlessImageTest prüft dieselben Ersetzungen an den Dateien im Repo.
# `nginx -e`: das Fehlerprotokoll schon vor dem Lesen der Konfiguration nach
# stderr — der eingebaute Pfad unter /var/lib/nginx gehört dem Benutzer nginx.
# php-fpm: ohne root sind `user`/`group` des Pools wirkungslos (nur Hinweise
# im Log) — entfernt.
COPY docker/nginx-rootless.conf /etc/nginx/energietracker-rootless.conf
RUN sed -i \
        -e 's|^pid /run/nginx/nginx\.pid;$|pid /tmp/nginx.pid;|' \
        -e 's|^http {$|http { include /etc/nginx/energietracker-rootless.conf;|' \
        -e 's|^        listen 80 default_server;$|        listen 8080 default_server;|' \
        /etc/nginx/nginx.conf \
    && sed -i \
        -e '/^user=root$/d' \
        -e 's|^pidfile=/run/supervisord\.pid$|pidfile=/tmp/supervisord.pid|' \
        -e 's|^command=nginx -g "daemon off;"$|command=nginx -e /dev/stderr -g "daemon off;"|' \
        /etc/supervisord.conf \
    && sed -i -e '/^user = /d' -e '/^group = /d' /usr/local/etc/php-fpm.d/www.conf \
    && grep -qx 'pid /tmp/nginx.pid;' /etc/nginx/nginx.conf \
    && grep -qx 'http { include /etc/nginx/energietracker-rootless.conf;' /etc/nginx/nginx.conf \
    && grep -qx '        listen 8080 default_server;' /etc/nginx/nginx.conf \
    && ! grep -q 'listen 80 ' /etc/nginx/nginx.conf \
    && ! grep -q '^user=' /etc/supervisord.conf \
    && grep -qx 'pidfile=/tmp/supervisord.pid' /etc/supervisord.conf \
    && grep -qx 'command=nginx -e /dev/stderr -g "daemon off;"' /etc/supervisord.conf \
    && mkdir -p /data \
    && chown 10001:0 /data \
    && chmod 775 /data

# Weiche für entrypoint.sh: keine root-Schritte in dieser Variante.
ENV ET_IMAGE_VARIANT=rootless

USER 10001:0
EXPOSE 8080
VOLUME ["/data"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/api/health >/dev/null 2>&1 || exit 1


# ─── Standard (letzte Stufe = Ziel von `docker build .`) ────────────────────
# Unverändert seit v3.4.0: Start als root, entrypoint.sh stellt /data auf
# www-data, nginx und php-fpm arbeiten mit eigenen Benutzern, Port 80.
FROM base AS standard

RUN mkdir -p /data /run/nginx \
    && chown -R www-data:www-data /data

EXPOSE 80
VOLUME ["/data"]

# Nutzt den N1003-Health-Endpoint für den Container-Healthcheck. Seit v2.6.0
# antwortet er bei einer Störung mit 503 — wget scheitert dann, der Container
# gilt als „unhealthy".
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1/api/health >/dev/null 2>&1 || exit 1
