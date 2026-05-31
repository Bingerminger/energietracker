#!/bin/sh
# Energietracker — Container-Entrypoint (N1005).
# Stellt sicher, dass das gemountete Datenvolume für www-data schreibbar ist,
# und startet danach den eigentlichen Prozess (supervisord).
set -e

if [ -d /data ]; then
    chown -R www-data:www-data /data 2>/dev/null || true
fi

exec "$@"
