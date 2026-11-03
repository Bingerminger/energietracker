# Energietracker — Single-Container-Image (N1005, v1.7.3).
#
# nginx + php-fpm in einem Image, gestartet von docker/start.sh (v3.10.0;
# bis v3.9 hielt supervisord beide zusammen und brachte python3 mit).
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

# nginx für den Single-Container-Betrieb; OPcache ist im Image enthalten und
# wird nur eingeschaltet (v2.6.0). v3.4.0 — ab PHP 8.5 ist OPcache fest
# eingebaut; docker-php-ext-enable scheiterte dort, deshalb nur, wenn es noch
# nicht geladen ist. v3.10.0 — kein supervisor mehr (und damit kein python3):
# weniger Pakete, weniger Befunde im Scan.
# v3.10.1 — `apk upgrade`: das PHP-Basis-Image hinkt den Sicherheitskorrekturen von Alpine
# hinterher (3.10.0 enthielt curl 8.22.0, Alpine bot 8.23.0); jeder Bau holt sie jetzt nach.
RUN apk upgrade --no-cache \
    && apk add --no-cache nginx \
    && if ! php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then docker-php-ext-enable opcache; fi

WORKDIR /app

# Laufzeit-Konfiguration zuerst (bessere Layer-Caches).
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/php-fpm-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
# v3.4.0 (N1020) — php-fpm nur auf 127.0.0.1 (lädt nach zz-docker.conf des Basis-Images)
COPY docker/php-fpm-listen.conf /usr/local/etc/php-fpm.d/zzz-energietracker.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-energietracker.ini
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh
COPY docker/start.sh         /usr/local/bin/start.sh

# Anwendungscode (Dev-/VCS-Ballast hält .dockerignore raus).
COPY . /app

# v2.6.0 — Der Code gehört root und ist für PHP nur lesbar; schreibbar ist
# allein /data. Bis v2.5.3 gehörte /app www-data — PHP hätte den eigenen Code
# überschreiben können.
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/start.sh \
    && chown -R root:root /app \
    && chmod -R a+rX,go-w /app

# Standardwerte; im docker-compose oder per `docker run -e` überschreibbar.
ENV ET_DATA_DIR=/data \
    ET_LOG_DEST=stderr \
    ET_LOG_LEVEL=info

# entrypoint.sh übergibt mit exec an start.sh: Das Startskript ist PID 1,
# reicht das Stoppsignal (SIGQUIT von `docker stop`, STOPSIGNAL des
# Basis-Images; SIGTERM, SIGINT) an php-fpm und nginx weiter und beendet den
# Container, sobald einer der beiden Dienste endet (Neustart über die
# restart-Policy).
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/local/bin/start.sh"]


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

# nginx: dieselbe Datei wie im Standard-Image, nur Port 8080, PID-Datei und
# Zwischenspeicher unter /tmp. Abgeleitet statt kopiert, damit die Sperrregeln
# (data/, src/, Doku …) nicht auseinanderlaufen. Greift eine Ersetzung nicht,
# bricht der Bau ab (grep); RootlessImageTest prüft dieselben Ersetzungen an
# den Dateien im Repo. Das Startskript (start.sh) ist in beiden Varianten
# dasselbe: `nginx -e /dev/stderr` schreibt das Fehlerprotokoll schon vor dem
# Lesen der Konfiguration nach stderr — der eingebaute Pfad unter
# /var/lib/nginx gehört dem Benutzer nginx.
# php-fpm: ohne root sind `user`/`group` des Pools wirkungslos (nur Hinweise
# im Log) — entfernt.
COPY docker/nginx-rootless.conf /etc/nginx/energietracker-rootless.conf
RUN sed -i \
        -e 's|^pid /run/nginx/nginx\.pid;$|pid /tmp/nginx.pid;|' \
        -e 's|^http {$|http { include /etc/nginx/energietracker-rootless.conf;|' \
        -e 's|^        listen 80 default_server;$|        listen 8080 default_server;|' \
        /etc/nginx/nginx.conf \
    && sed -i -e '/^user = /d' -e '/^group = /d' /usr/local/etc/php-fpm.d/www.conf \
    && grep -qx 'pid /tmp/nginx.pid;' /etc/nginx/nginx.conf \
    && grep -qx 'http { include /etc/nginx/energietracker-rootless.conf;' /etc/nginx/nginx.conf \
    && grep -qx '        listen 8080 default_server;' /etc/nginx/nginx.conf \
    && ! grep -q 'listen 80 ' /etc/nginx/nginx.conf \
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
