#!/usr/bin/env bash
# =====================================================================
# v3.13.0 — Zeitreise: PHPUnit und die Node-Suiten der CI unter verstellter Uhr (libfaketime).
#
# Die Testsuite muss an jedem Tag grün sein, nicht nur heute (Lektion 103: Stichtage durchreichen,
# Tests mit festen Tagen). Dieses Werkzeug stellt die Uhr für PHP, Node und alle Kindprozesse
# (php -S der Suiten) auf einen Stichtag und lässt dort die Runde laufen. Lokal vor jedem Release,
# in der CI als eigener Job (.github/workflows/ci.yml, Job „zeitreise“) an wenigen Stichtagen.
#
# Aufruf:
#   bash tools/zeitreise.sh [Port-Basis, Standard 19200] [Stichtag …]
#   Stichtag: "JJJJ-MM-TT hh:mm:ss" (Ortszeit), "+N" (heute + N Tage, 12:00) oder ein Name:
#     jan15    15.01. des nächsten Jahres, 12:00 (Abrechnungsjahre ab 01.01. ohne gemessenen Teil)
#     silvester-utc  31.12. 23:30 UTC (in der Ortszeit schon Neujahr, im Testprozess noch Silvester)
#     mar01    01.03. des nächsten Jahres, 12:00 (nach Februar, Schaltjahr oder nicht)
#   Ohne Stichtage: heute +3, +30, +90, +180, +365, 29.02. des nächsten Schaltjahrs,
#   31.12. 23:30 (Ortszeit und UTC), 15.01. des nächsten Jahres und beide Tage der Zeitumstellung
#   des nächsten Jahres.
#
# Umgebung:
#   ET_REPO=<arbeitskopie>  Standard: das Repository dieses Skripts
#   ZR_ONLY=phpunit|node    nur eine Hälfte
#   ZR_FILTER=<Muster>      an PHPUnit als --filter
#   ZR_PHPUNIT_ARGS=<…>     weitere Schalter für PHPUnit (etwa --display-deprecations)
#   ZR_NODE=<Regex>         nur Node-Suiten, deren Pfad passt (etwa 'lists|demo')
#   ZR_LIST=1               nur die Stichtage zeigen
#   ZR_LOGDIR=<ordner>      Protokolle (Standard: neuer Ordner unter dem Temp-Verzeichnis)
#   FAKETIME=<pfad>         libfaketime (Standard: faketime im PATH, sonst /opt/homebrew/bin/faketime)
#
# Ports: Port-Basis bis Port-Basis+8 für die Node-Suiten (Server der Render-Tests, Demo, Listen,
# Entwurf, Barrierefreiheit, große Daten, MCP, Raster und Sichten nacheinander auf +8),
# Port-Basis+9 für das nachgebaute Home Assistant in PHPUnit.
#
# Wichtig:
# - `php` direkt aufrufen, nicht über das Shebang von vendor/bin/phpunit (`#!/usr/bin/env php`):
#   macOS entfernt DYLD_* beim Start geschützter Programme wie /usr/bin/env, dann sieht PHP die echte Uhr.
# - Die Uhr wird um einen Versatz verstellt ("+Sekunden"), nicht auf eine Startzeit ("@Datum"): Mit
#   "@" beginnt jeder neue Prozess wieder beim Stichtag, ein Server, den ein Test nach zwei Minuten
#   startet, läge zwei Minuten hinter dem Test.
# - Auf macOS verstellt libfaketime stat() nicht; NO_FAKE_STAT=1 gleicht Linux daran an.
# - Die Zeitzone kommt aus TZ (die CI setzt Europe/Berlin, sonst fielen Ortszeit und UTC zusammen).
# =====================================================================
set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
REPO=${ET_REPO:-$HERE}
BASE=${1:-19200}
[ $# -gt 0 ] && shift
cd "$REPO" || exit 2
export NO_FAKE_STAT=${NO_FAKE_STAT:-1}

FT=${FAKETIME:-$(command -v faketime || echo /opt/homebrew/bin/faketime)}
[ -x "$FT" ] || { echo "libfaketime fehlt ($FT)"; exit 2; }

# Datum + N Tage (BSD- und GNU-date)
plus_days() { date -v+"$1"d +%Y-%m-%d 2>/dev/null || date -d "+$1 days" +%Y-%m-%d; }
YEAR=$(date +%Y)
NEXT=$((YEAR + 1))
LEAP=$NEXT
while ! { [ $((LEAP % 4)) = 0 ] && { [ $((LEAP % 100)) != 0 ] || [ $((LEAP % 400)) = 0 ]; }; }; do LEAP=$((LEAP + 1)); done
# Zeitumstellung in der EU: letzter Sonntag im März bzw. Oktober
last_sunday() {   # $1 Jahr, $2 Monat (03|10)
  local d
  for d in 31 30 29 28 27 26 25; do
    if [ "$(date -j -f %Y-%m-%d "$1-$2-$d" +%u 2>/dev/null || date -d "$1-$2-$d" +%u)" = 7 ]; then echo "$1-$2-$d"; return; fi
  done
}

# Versatz von jetzt bis zum Stichtag (Ortszeit) als faketime-Angabe "+S" bzw. "-S"
offset_to() {
  local t
  t=$(date -j -f '%Y-%m-%d %H:%M:%S' "$1" +%s 2>/dev/null || date -d "$1" +%s) || return 1
  t=$((t - $(date +%s)))
  [ "$t" -ge 0 ] && echo "+$t" || echo "$t"
}

# Eine UTC-Zeit als Ortszeit
utc_to_local() {
  local s
  if s=$(date -j -u -f '%Y-%m-%d %H:%M:%S' "$1" +%s 2>/dev/null); then date -r "$s" '+%Y-%m-%d %H:%M:%S'
  else date -d "$1 UTC" '+%Y-%m-%d %H:%M:%S'; fi
}

STAMPS=()
LABELS=()
add() { STAMPS+=("$1"); LABELS+=("$2"); }
if [ $# -eq 0 ]; then
  for n in 3 30 90 180 365; do add "$(plus_days "$n") 12:00:00" "heute +$n"; done
  add "$LEAP-02-29 12:00:00" "29.02.$LEAP"
  add "$YEAR-12-31 23:30:00" "31.12. 23:30 Ortszeit"
  add "$(utc_to_local "$YEAR-12-31 23:30:00")" "31.12. 23:30 UTC"
  add "$NEXT-01-15 12:00:00" "15.01.$NEXT"
  add "$(last_sunday "$NEXT" 03) 12:00:00" "Sommerzeit $NEXT"
  add "$(last_sunday "$NEXT" 10) 12:00:00" "Winterzeit $NEXT"
else
  for s in "$@"; do
    case "$s" in
      +*) add "$(plus_days "${s#+}") 12:00:00" "heute $s" ;;
      jan15) add "$NEXT-01-15 12:00:00" "15.01.$NEXT" ;;
      silvester-utc) add "$(utc_to_local "$YEAR-12-31 23:30:00")" "31.12. 23:30 UTC" ;;
      mar01) add "$NEXT-03-01 12:00:00" "01.03.$NEXT" ;;
      *)  add "$s" "$s" ;;
    esac
  done
fi

if [ -n "${ZR_LIST:-}" ]; then
  for i in "${!STAMPS[@]}"; do printf '%-24s %s\n' "${LABELS[$i]}" "${STAMPS[$i]}"; done
  exit 0
fi

LOGDIR=${ZR_LOGDIR:-$(mktemp -d "${TMPDIR:-/tmp}/et-zeitreise.XXXXXX")}
mkdir -p "$LOGDIR"

run_phpunit() {   # $1 Stichtag, $2 Protokoll
  local args=(--no-coverage --do-not-cache-result) off
  [ -n "${ZR_FILTER:-}" ] && args+=(--filter "$ZR_FILTER")
  [ -n "${ZR_PHPUNIT_ARGS:-}" ] && read -r -a extra <<< "$ZR_PHPUNIT_ARGS" && args+=("${extra[@]}")
  off=$(offset_to "$1") || return 2
  # das nachgebaute Home Assistant der Tests: fester Port je Lauf (sonst ein freier Port über TestPorts)
  ET_HA_FAKE_PORT=$((BASE+9)) "$FT" -f "$off" php vendor/phpunit/phpunit/phpunit "${args[@]}" < /dev/null > "$2" 2>&1
}

# Läuft diese Node-Suite mit (ZR_NODE)?
pick() { [ -z "${ZR_NODE:-}" ] || printf '%s\n' "$1" | grep -qE "$ZR_NODE"; }

run_node() {   # $1 Stichtag, $2 Protokoll → Liste der roten Suiten auf stdout
  local stamp=$1 log=$2 red=() data srv rc f off
  : > "$log"
  off=$(offset_to "$stamp") || return 2
  # Frontend-API-Shape und Browser-Render gegen einen Server mit einer Kopie der Demo-Daten (wie in der CI)
  if pick tests/frontend-api-shape.test.js || pick tests/browser-render.test.mjs; then
    # belegter Port: lieber rot als gegen einen fremden (oder alten) Server prüfen
    if curl -s -o /dev/null "http://127.0.0.1:$BASE/" 2>/dev/null; then
      echo "Port $BASE ist belegt" >> "$log"
      echo "port-$BASE-belegt"
      return 1
    fi
    data=$(mktemp -d "${TMPDIR:-/tmp}/et-zeitreise-data.XXXXXX")
    cp -R demo-data/. "$data"/
    ET_DATA_DIR="$data" "$FT" -f "$off" php -S 127.0.0.1:"$BASE" router.php < /dev/null >> "$log.server" 2>&1 &
    srv=$!
    for _ in $(seq 1 30); do curl -sf "http://127.0.0.1:$BASE/api/settings" >/dev/null 2>&1 && break; sleep 0.5; done
    if pick tests/frontend-api-shape.test.js; then
      echo "── tests/frontend-api-shape.test.js" >> "$log"
      ET_TEST_HOST="http://127.0.0.1:$BASE" "$FT" -f "$off" node tests/frontend-api-shape.test.js < /dev/null >> "$log" 2>&1 \
        || red+=(frontend-api-shape)
    fi
    if pick tests/browser-render.test.mjs; then
      echo "── tests/browser-render.test.mjs" >> "$log"
      ET_TEST_HOST="http://127.0.0.1:$BASE" "$FT" -f "$off" node \
        --import='data:text/javascript,import{register}from"node:module";import{pathToFileURL}from"node:url";register("./tests/esm-loader.mjs",pathToFileURL("./"));' \
        tests/browser-render.test.mjs < /dev/null >> "$log" 2>&1 || red+=(browser-render)
    fi
    # $! ist faketime, php -S läuft als dessen Kind weiter: beide beenden, bis der Port frei ist
    pkill -P "$srv" 2>/dev/null; kill "$srv" 2>/dev/null; wait "$srv" 2>/dev/null
    for _ in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$BASE/" 2>/dev/null || break; sleep 0.25; done
    rm -rf "$data"
  fi
  # alle übrigen Suiten und Prüfungen aus ci.yml, in ihrer Reihenfolge
  while read -r f; do
    [ "$f" = tests/frontend-api-shape.test.js ] && continue
    pick "$f" || continue
    echo "── $f" >> "$log"
    case "$f" in
      tools/*) "$FT" -f "$off" node "$f" --check < /dev/null >> "$log" 2>&1; rc=$? ;;
      *) ET_DEMO_PORT=$((BASE+1)) ET_DEMO_BUILD_PORT=$((BASE+2)) ET_LISTS_PORT=$((BASE+3)) ET_DRAFT_PORT=$((BASE+4)) \
         ET_A11Y_PORT=$((BASE+5)) ET_LARGE_PORT=$((BASE+6)) ET_MCP_PORT=$((BASE+7)) ET_BOX_PORT=$((BASE+8)) \
         ET_SICHT_PORT=$((BASE+8)) "$FT" -f "$off" node "$f" < /dev/null >> "$log" 2>&1; rc=$? ;;
    esac
    [ $rc -ne 0 ] && red+=("$(basename "$f" | sed -E 's/(\.test)?\.m?js$//')")
  done < <(grep -oE 'node (tests|tools)/[a-z0-9-]+(\.test)?\.m?js' .github/workflows/ci.yml | awk '!seen[$2]++ {print $2}')
  echo "${red[*]:-}"
}

printf '\nZeitreise in %s (Protokolle: %s, TZ %s)\n\n' "$REPO" "$LOGDIR" "${TZ:-System}"
printf '%-24s %-21s %-34s %s\n' "Stichtag" "Uhr" "PHPUnit" "Node (rot)"
fail=0
for i in "${!STAMPS[@]}"; do
  stamp=${STAMPS[$i]}
  tag=$(printf '%s' "$stamp" | tr ' :' '__')
  pu="—"; nd="—"
  started=$(date +%s)
  if [ "${ZR_ONLY:-}" != node ]; then
    run_phpunit "$stamp" "$LOGDIR/phpunit-$tag.log"
    if grep -qE '^OK \(' "$LOGDIR/phpunit-$tag.log"; then
      pu=$(grep -E '^OK \(' "$LOGDIR/phpunit-$tag.log" | tail -1)
    else
      fail=1
      pu="ROT $(grep -E '^Tests:' "$LOGDIR/phpunit-$tag.log" | tail -1 | sed -E 's/Tests: ([0-9]+), Assertions: [0-9]+,? ?/\1 Tests, /')"
    fi
  fi
  if [ "${ZR_ONLY:-}" != phpunit ]; then
    nd=$(run_node "$stamp" "$LOGDIR/node-$tag.log")
    if [ -n "$nd" ]; then fail=1; else nd="alle grün"; fi
  fi
  printf '%-24s %-21s %-34s %s (%ss)\n' "${LABELS[$i]}" "$stamp" "$pu" "$nd" "$(( $(date +%s) - started ))"
  if [ "${ZR_ONLY:-}" != node ] && ! grep -qE '^OK \(' "$LOGDIR/phpunit-$tag.log"; then
    grep -E '^[0-9]+\) [A-Z]' "$LOGDIR/phpunit-$tag.log" | sed 's/^/    /' | head -25
  fi
  # rote Prüfungen der Node-Suiten gleich mit ausgeben (in der CI ist das Protokoll sonst weg)
  if [ "${ZR_ONLY:-}" != phpunit ] && [ "$nd" != "alle grün" ]; then
    grep -E '^── |✗' "$LOGDIR/node-$tag.log" | grep -B1 '✗' | sed 's/^/    /' | head -40
  fi
done
printf '\nErgebnis: %s\n' "$([ $fail = 0 ] && echo 'alle Stichtage GRÜN' || echo 'ROT (Protokolle oben)')"
exit $fail
