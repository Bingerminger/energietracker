# Energietracker — Single-Container-Image (N1005, v1.7.3).
#
# nginx + php-fpm in einem Image, von supervisord zusammengehalten.
# Bewusst ein Container für einen reibungslosen Self-Hosting-Start:
#   docker run -p 8080:80 -v ./data:/data ghcr.io/bingerminger/energietracker
#
# Die Laufzeit bleibt Composer-frei (Hand-rolled-Autoloader in
# src/bootstrap.php) — es wird KEIN `composer install` ausgeführt.
FROM php:8.4-fpm-alpine

# nginx + supervisor für den Single-Container-Betrieb.
RUN apk add --no-cache nginx supervisor

WORKDIR /app

# Laufzeit-Konfiguration zuerst (bessere Layer-Caches).
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php-fpm-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh

# Anwendungscode (Dev-/VCS-Ballast hält .dockerignore raus).
COPY . /app

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /data /run/nginx \
    && chown -R www-data:www-data /app /data

# Standardwerte; im docker-compose oder per `docker run -e` überschreibbar.
ENV ET_DATA_DIR=/data \
    ET_LOG_DEST=stderr \
    ET_LOG_LEVEL=info

EXPOSE 80
VOLUME ["/data"]

# Nutzt den N1003-Health-Endpoint für den Container-Healthcheck.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1/api/health >/dev/null 2>&1 || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
