#!/usr/bin/env bash
# Lokale Test-Farm des Energietrackers (tools/farm/README.md).
#
#   bash tools/farm/farm.sh build     Image aus dem Arbeitsstand bauen (energietracker:farm, :farm-rootless)
#   bash tools/farm/farm.sh up        Farm starten (Integration in beide Home Assistant legen, auf Bereitschaft warten)
#   bash tools/farm/farm.sh pruefen   alle Prüfungen, Bericht mit ✓/✗, Exit-Code ungleich 0 bei einem Fehler
#   bash tools/farm/farm.sh down      alles abbauen (Container, Volumes, Netz; Images bleiben)
#   bash tools/farm/farm.sh alles     build, up, pruefen, down (down auch bei einem Fehler)
#
# Läuft nur auf Zuruf, kein Hintergrunddienst. Nur Beispieldaten.
# Variablen (alle optional):
#   FARM_INTEGRATION   Ordner des Integrations-Repos (Standard: $HOME/Code/energietracker-hacs,
#                      sonst eine Kopie unter $HOME/.cache/energietracker-farm)
#   FARM_HA_NEU        Tag der aktuellen Home-Assistant-Fassung (Standard: stable)
#   FARM_HA_ALT        Tag der ältesten Fassung (Standard: "homeassistant" aus hacs.json)
#   FARM_EVCC_IMAGE    evcc-Image (Standard: evcc/evcc:latest)
#   FARM_PORT_ET, FARM_PORT_ET_ROOTLESS, FARM_PORT_HA_NEU, FARM_PORT_HA_ALT, FARM_PORT_EVCC
#                      Ports auf 127.0.0.1 (Standard: 19840 bis 19844)
set -euo pipefail

FARM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$FARM_DIR/../.." && pwd)"
PROJECT="et-farm"
INTEGRATION_REPO="https://github.com/Bingerminger/energietracker-integration"

export FARM_IMAGE="${FARM_IMAGE:-energietracker:farm}"
export FARM_IMAGE_ROOTLESS="${FARM_IMAGE_ROOTLESS:-energietracker:farm-rootless}"
export FARM_HA_NEU="${FARM_HA_NEU:-stable}"
export FARM_EVCC_IMAGE="${FARM_EVCC_IMAGE:-evcc/evcc:latest}"
export FARM_PORT_ET="${FARM_PORT_ET:-19840}"
export FARM_PORT_ET_ROOTLESS="${FARM_PORT_ET_ROOTLESS:-19841}"
export FARM_PORT_HA_NEU="${FARM_PORT_HA_NEU:-19842}"
export FARM_PORT_HA_ALT="${FARM_PORT_HA_ALT:-19843}"
export FARM_PORT_EVCC="${FARM_PORT_EVCC:-19844}"
export FARM_PROJECT="$PROJECT"
export FARM_COMPOSE_FILE="$FARM_DIR/compose.yml"

say() { printf '%s\n' "$*"; }
fail() { printf 'farm: %s\n' "$*" >&2; exit 1; }

compose() { docker compose -p "$PROJECT" -f "$FARM_DIR/compose.yml" "$@"; }

# Ordner mit custom_components/energietracker und hacs.json finden (oder holen)
integration_dir() {
    local dir="${FARM_INTEGRATION:-}"
    if [ -n "$dir" ]; then
        [ -d "$dir/custom_components/energietracker" ] || fail "FARM_INTEGRATION=$dir enthält kein custom_components/energietracker"
        printf '%s\n' "$dir"; return
    fi
    if [ -d "$HOME/Code/energietracker-hacs/custom_components/energietracker" ]; then
        printf '%s\n' "$HOME/Code/energietracker-hacs"; return
    fi
    dir="$HOME/.cache/energietracker-farm/energietracker-integration"
    if [ ! -d "$dir/custom_components/energietracker" ]; then
        say "Integration nicht gefunden, hole $INTEGRATION_REPO nach $dir" >&2
        mkdir -p "$(dirname "$dir")"
        git clone --quiet --depth 1 "$INTEGRATION_REPO" "$dir" >&2
    fi
    printf '%s\n' "$dir"
}

# Mindestversion von Home Assistant aus hacs.json
ha_min_version() {
    sed -n 's/.*"homeassistant"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1/hacs.json" | head -n 1
}

prepare_env() {
    INTEGRATION="$(integration_dir)" || true
    [ -n "$INTEGRATION" ] || fail "Integration nicht gefunden (FARM_INTEGRATION setzen)"
    export FARM_INTEGRATION="$INTEGRATION"
    if [ -z "${FARM_HA_ALT:-}" ]; then
        FARM_HA_ALT="$(ha_min_version "$INTEGRATION")"
        [ -n "$FARM_HA_ALT" ] || fail "keine Mindestversion in $INTEGRATION/hacs.json"
    fi
    export FARM_HA_ALT
}

wait_http() { # Name, Adresse, Sekunden
    local name="$1" url="$2" limit="$3" start=$SECONDS
    while ! curl -sf -o /dev/null --max-time 5 "$url"; do
        if [ $((SECONDS - start)) -ge "$limit" ]; then
            say "  ✗ $name antwortet nach ${limit} s nicht ($url)"
            return 1
        fi
        sleep 2
    done
    say "  ✓ $name bereit nach $((SECONDS - start)) s"
}

cmd_build() {
    say "== Image aus dem Arbeitsstand bauen ($(cat "$ROOT/VERSION"))"
    docker build -q -t "$FARM_IMAGE" "$ROOT" >/dev/null
    say "  ✓ $FARM_IMAGE"
    docker build -q --target rootless -t "$FARM_IMAGE_ROOTLESS" "$ROOT" >/dev/null
    say "  ✓ $FARM_IMAGE_ROOTLESS"
}

# Integration in das /config-Volume einer Home-Assistant-Fassung legen, bevor sie
# startet (Home Assistant liest custom_components nur beim Start). Ohne HACS:
# Home Assistant 2026.11 bringt den Marketplace mit und löscht ein eigenes
# custom_components/hacs beim Start.
install_integration() { # Dienst
    COPYFILE_DISABLE=1 tar -C "$INTEGRATION/custom_components" --exclude '__pycache__' -cf - energietracker \
        | compose run --rm --no-deps -T --entrypoint sh "$1" -c \
            'rm -rf /config/custom_components/energietracker && mkdir -p /config/custom_components && tar -C /config/custom_components -xf - && test -f /config/custom_components/energietracker/manifest.json' \
        >/dev/null 2>&1 || fail "Integration ließ sich nicht nach $1 kopieren"
}

cmd_up() {
    prepare_env
    for img in "$FARM_IMAGE" "$FARM_IMAGE_ROOTLESS"; do
        docker image inspect "$img" >/dev/null 2>&1 || fail "Image $img fehlt, erst: bash tools/farm/farm.sh build"
    done
    say "== Farm starten (Projekt $PROJECT)"
    say "  Home Assistant neu: $FARM_HA_NEU, alt: $FARM_HA_ALT; evcc: $FARM_EVCC_IMAGE"
    local tilde='~'
    say "  Integration: ${INTEGRATION/#$HOME/$tilde}"
    compose up --no-start --quiet-pull >/dev/null 2>&1 || compose up --no-start
    install_integration ha-neu
    install_integration ha-alt
    say "  ✓ Integration in ha-neu und ha-alt gelegt"
    compose up -d >/dev/null 2>&1 || compose up -d
    local bad=0
    wait_http "Energietracker"            "http://127.0.0.1:$FARM_PORT_ET/api/health" 120 || bad=1
    wait_http "Energietracker ohne root"  "http://127.0.0.1:$FARM_PORT_ET_ROOTLESS/api/health" 120 || bad=1
    wait_http "evcc (Demo)"               "http://127.0.0.1:$FARM_PORT_EVCC/api/state" 120 || bad=1
    wait_http "Home Assistant neu"        "http://127.0.0.1:$FARM_PORT_HA_NEU/api/onboarding" 300 || bad=1
    wait_http "Home Assistant alt"        "http://127.0.0.1:$FARM_PORT_HA_ALT/api/onboarding" 300 || bad=1
    [ "$bad" -eq 0 ] || fail "nicht alle Dienste bereit (Protokolle: docker compose -p $PROJECT logs)"
    say "  Energietracker http://127.0.0.1:$FARM_PORT_ET · ohne root :$FARM_PORT_ET_ROOTLESS · HA neu :$FARM_PORT_HA_NEU · HA alt :$FARM_PORT_HA_ALT · evcc :$FARM_PORT_EVCC"
}

cmd_pruefen() {
    prepare_env
    command -v node >/dev/null 2>&1 || fail "Node 22 oder neuer fehlt"
    node "$FARM_DIR/pruefen.mjs"
}

cmd_down() {
    say "== Farm abbauen"
    compose down -v --remove-orphans --timeout 20 >/dev/null 2>&1 || compose down -v --remove-orphans --timeout 20
    say "  ✓ Container, Volumes und Netz von $PROJECT entfernt (Images bleiben)"
}

cmd_alles() {
    local start=$SECONDS rc=0
    cmd_build
    trap 'cmd_down' EXIT
    cmd_up || rc=$?
    if [ "$rc" -eq 0 ]; then cmd_pruefen || rc=$?; fi
    trap - EXIT
    cmd_down
    local t=$((SECONDS - start))
    say ""
    say "Laufzeit: $((t / 60)) min $((t % 60)) s"
    return "$rc"
}

case "${1:-}" in
    build)   cmd_build ;;
    up)      cmd_up ;;
    pruefen) cmd_pruefen ;;
    down)    cmd_down ;;
    alles)   cmd_alles ;;
    *)
        sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'
        exit 2 ;;
esac
