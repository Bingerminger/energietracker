# Test-Farm (tools/farm/README.md): der Energietracker auf einem vorhandenen
# Webserver, so wie ihn docs/betrieb/webserver.md beschreibt (eigener Server
# oder Synology Web Station). Nur für die Farm, kein Auslieferungsgut.
#
#   - Apache mit mod_php aus dem offiziellen Image php:<Version>-apache
#     (die Erweiterungen des Basis-Images genügen, INSTALL.md nennt keine weiteren);
#   - mod_rewrite und mod_headers eingeschaltet, mod_setenvif ist es schon;
#   - der VirtualHost wörtlich aus docs/betrieb/webserver.md (farm.sh liest ihn
#     dort heraus und reicht ihn als VHOST herein), damit ein Fehler im Beispiel auffällt;
#   - die PHP-Werte aus der Tabelle „Upload-Grenzen und PHP-Speicher“;
#   - der Arbeitsstand wie ein Git-Checkout (farm.sh packt nur, was Git kennt oder
#     nicht ignoriert), data/ im Webroot, Schreibrecht für www-data (INSTALL.md).
#
#   farm.sh build  →  energietracker:farm-apache-php8.4 (Mindestversion) und :farm-apache-php8.5
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-apache

ARG VHOST
RUN test -n "$VHOST" \
    && a2enmod rewrite headers >/dev/null \
    && printf '%s\n' "$VHOST" > /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' 'memory_limit = 768M' 'post_max_size = 256M' 'upload_max_filesize = 256M' \
        'max_execution_time = 120' > /usr/local/etc/php/conf.d/zz-energietracker.ini

COPY . /var/www/energietracker

# Ein echter Checkout hat .git/; die Farm prüft, dass es nicht ausgeliefert wird
RUN mkdir -p /var/www/energietracker/.git \
    && printf 'ref: refs/heads/main\n' > /var/www/energietracker/.git/HEAD \
    && chown -R www-data:www-data /var/www/energietracker/data
