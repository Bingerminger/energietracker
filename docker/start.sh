#!/bin/sh
# Energietracker — startet php-fpm und nginx im Container (v3.10.0).
#
# Ersetzt supervisord (und damit python3 und setuptools im Image). POSIX-sh,
# läuft mit der busybox-Shell des Alpine-Images; entrypoint.sh übergibt mit
# `exec`, das Skript ist also PID 1.
#
# - php-fpm zuerst, dann nginx. Beide schreiben direkt nach stdout/stderr
#   (`docker logs`, auch die JSON-Lines des Loggers aus N1010).
# - SIGTERM, SIGINT und SIGQUIT gehen als SIGTERM an beide Dienste, wie bei
#   supervisord. `docker stop` (auch Home Assistant) schickt SIGQUIT: Das
#   Basis-Image php:fpm setzt STOPSIGNAL SIGQUIT. Ohne Handler ignoriert PID 1
#   jedes Signal, Docker wartete die Frist ab und schickte SIGKILL.
#   Sind beide Dienste beendet, endet der Container mit 0.
# - Endet einer der beiden Dienste von selbst, beendet das Skript den anderen
#   und endet mit einem Fehlercode. Neu startet Docker (restart-Policy) bzw.
#   Home Assistant (Watchdog), nicht mehr ein Wächter im Container: Ein
#   Container, in dem nur noch ein Dienst läuft, fiele sonst nur über den
#   HEALTHCHECK auf.
# - Als PID 1 sammelt das Skript beim Warten auch verwaiste Kindprozesse ein.
set -u

fpm=
web=
tick=
stopping=

log() {
    # Format des Loggers (JSON-Lines), wie in entrypoint.sh
    printf '{"ts":"%s","level":"%s","msg":"%s","ctx":%s}\n' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2" "$3" >&2
}

# Der Handler merkt sich nur das Signal; das Weiterreichen steht unten an
# einer Stelle. So geht auch ein Signal vor dem Start der Dienste nicht verloren.
on_signal() {
    [ -n "$stopping" ] && return 0
    stopping=$1
    log info "Stoppsignal erhalten, Dienste werden beendet" "{\"signal\":\"$1\"}"
}
trap 'on_signal TERM' TERM
trap 'on_signal INT' INT
trap 'on_signal QUIT' QUIT

php-fpm -F &
fpm=$!
nginx -e /dev/stderr -g 'daemon off;' &
web=$!

# Warten, bis ein Signal kommt oder ein Dienst endet. `wait` auf einen kurzen
# Takt statt auf die Dienste: POSIX-sh kennt kein `wait -n`, und ein Signal
# unterbricht das Warten sofort. Jedes `wait` holt dabei beendete Kinder ab.
while [ -z "$stopping" ] && kill -0 "$fpm" 2>/dev/null && kill -0 "$web" 2>/dev/null; do
    sleep 1 &
    tick=$!
    wait "$tick"
done
[ -n "$tick" ] && kill "$tick" 2>/dev/null

if [ -n "$stopping" ]; then
    kill -TERM "$fpm" "$web" 2>/dev/null
    while kill -0 "$fpm" 2>/dev/null || kill -0 "$web" 2>/dev/null; do
        wait
    done
    exit 0
fi

# Ein Dienst ist von selbst beendet: den anderen beenden, mit Fehlercode enden.
if kill -0 "$fpm" 2>/dev/null; then
    name=nginx; dead=$web; other=$fpm
else
    name=php-fpm; dead=$fpm; other=$web
fi
wait "$dead"
code=$?
[ "$code" -eq 0 ] && code=1
log error "Dienst beendet, Container endet" "{\"service\":\"$name\",\"exit_code\":$code}"
kill -TERM "$other" 2>/dev/null
while kill -0 "$other" 2>/dev/null; do
    wait
done
exit "$code"
