#!/bin/sh
# Energietracker — Container-Entrypoint (N1005).
# Stellt sicher, dass das gemountete Datenvolume für www-data schreibbar ist,
# und startet danach den eigentlichen Prozess (supervisord).
set -e

# v2.6.0 — nur umstellen, wenn der Besitzer nicht stimmt. Ein `chown -R` bei
# jedem Start dauerte auf großen Bind-Mounts lange und änderte bei jedem
# Neustart die Besitzrechte auf dem Host.
if [ -d /data ] && [ "$(stat -c %U /data 2>/dev/null)" != "www-data" ]; then
    chown -R www-data:www-data /data 2>/dev/null || true
fi

exec "$@"
