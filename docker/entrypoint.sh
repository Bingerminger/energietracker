#!/bin/sh
# Energietracker — Container-Entrypoint (N1005).
# Stellt sicher, dass das gemountete Datenvolume für www-data schreibbar ist,
# und startet danach den eigentlichen Prozess (supervisord).
set -e

# v3.5.0 (N1024) — Image ohne root (Dockerfile, Stufe `rootless`): Die Weiche
# ist die Variante, nicht die laufende UID. Hier wird nichts umgestellt — kein
# chown, kein Verzeichnis unter /run —, nur geprüft und klar gemeldet. Ein
# nicht beschreibbares Datenverzeichnis meldet danach auch /api/health (503,
# `data_dir_writable: false`).
if [ "${ET_IMAGE_VARIANT:-}" = "rootless" ]; then
    data="${ET_DATA_DIR:-/data}"
    if [ ! -d "$data" ] || [ ! -w "$data" ]; then
        uid="$(id -u)"; gid="$(id -g)"
        dir="$(printf '%s' "$data" | sed 's/\\/\\\\/g; s/"/\\"/g')"
        printf '{"ts":"%s","level":"error","msg":"Datenverzeichnis nicht beschreibbar — Rechte am Volume anpassen","ctx":{"data_dir":"%s","uid":%s,"gid":%s,"hint":"auf dem Host: chown -R %s:%s <Datenordner>"}}\n' \
            "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$dir" "$uid" "$gid" "$uid" "$gid" >&2
    fi
    # v3.9.0 (F1033) — eigener Posteingang-Ordner (ET_INBOX_DIR): nur prüfen und melden
    if [ -n "${ET_INBOX_DIR:-}" ] && { [ ! -d "$ET_INBOX_DIR" ] || [ ! -w "$ET_INBOX_DIR" ]; }; then
        inbox="$(printf '%s' "$ET_INBOX_DIR" | sed 's/\\/\\\\/g; s/"/\\"/g')"
        printf '{"ts":"%s","level":"warning","msg":"Posteingang-Ordner fehlt oder ist nicht beschreibbar","ctx":{"inbox_dir":"%s","uid":%s,"gid":%s}}\n' \
            "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$inbox" "$(id -u)" "$(id -g)" >&2
    fi
    exec "$@"
fi

# v3.4.0 (N1020) — gehärteter Betrieb mit `read_only: true`: /run und
# /var/lib/nginx/tmp sind dann leere tmpfs; nginx braucht sein PID-Verzeichnis.
mkdir -p /run/nginx /var/lib/nginx/tmp 2>/dev/null || true

# v2.6.0 — nur umstellen, wenn der Besitzer nicht stimmt. Ein `chown -R` bei
# jedem Start dauerte auf großen Bind-Mounts lange und änderte bei jedem
# Neustart die Besitzrechte auf dem Host.
if [ -d /data ] && [ "$(stat -c %U /data 2>/dev/null)" != "www-data" ]; then
    chown -R www-data:www-data /data 2>/dev/null || true
fi

# v3.9.0 (F1033) — eigener Posteingang-Ordner außerhalb von /data (ET_INBOX_DIR,
# in der App für Home Assistant /share/energietracker/posteingang): anlegen und
# www-data geben. Nur den Ordner selbst, nicht rekursiv: Dateien, die jemand über
# die Freigabe hineinlegt, bleiben, wie sie sind (lesen genügt der App).
case "${ET_INBOX_DIR:-}" in
    /*)
        mkdir -p "$ET_INBOX_DIR" 2>/dev/null || true
        if [ -d "$ET_INBOX_DIR" ] && [ "$(stat -c %U "$ET_INBOX_DIR" 2>/dev/null)" != "www-data" ]; then
            chown www-data:www-data "$ET_INBOX_DIR" 2>/dev/null || true
        fi
        ;;
esac

exec "$@"
