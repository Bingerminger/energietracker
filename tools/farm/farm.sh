#!/usr/bin/env bash
# Lokale Test-Farm des Energietrackers (tools/farm/README.md).
#
#   bash tools/farm/farm.sh alles     Docker: build, up, pruefen, down (down auch bei einem Fehler)
#   bash tools/farm/farm.sh vm        Home Assistant OS als Parallels-VM et-farm-haos: App, Ingress, Backup, Integration, MCP
#   bash tools/farm/farm.sh demo      alles auf einmal zum Vorführen, läuft bis: bash tools/farm/farm.sh down
#   bash tools/farm/farm.sh down      alles abbauen (Container, Volumes, Netze; VM stoppen; Images bleiben)
#
#   Einzelschritte: build | up | pruefen (Docker) · vm-einrichten (einmalig, Snapshots „sauber“ und „demo“)
#
# Läuft nur auf Zuruf, kein Hintergrunddienst. Nur Beispieldaten.
# Variablen (alle optional):
#   FARM_INTEGRATION   Ordner des Integrations-Repos (Standard: $HOME/Code/energietracker-hacs,
#                      sonst eine Kopie unter $HOME/.cache/energietracker-farm)
#   FARM_HA_NEU        Tag der aktuellen Home-Assistant-Fassung (Standard: stable)
#   FARM_HA_ALT        Tag der ältesten Fassung (Standard: "homeassistant" aus hacs.json)
#   FARM_EVCC_IMAGE    evcc-Image (Standard: evcc/evcc:latest)
#   FARM_UPDATE_VON    veröffentlichte Version für den Update-Weg (Standard: der letzte Tag v*)
#   FARM_PORT_*        Ports auf 127.0.0.1 (Standard: 19840 bis 19854, Tabelle im README)
set -euo pipefail

FARM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$FARM_DIR/../.." && pwd)"
PROJECT="et-farm"
INTEGRATION_REPO="https://github.com/Bingerminger/energietracker-integration"
CACHE="$HOME/.cache/energietracker-farm"

export FARM_IMAGE="${FARM_IMAGE:-energietracker:farm}"
export FARM_IMAGE_ROOTLESS="${FARM_IMAGE_ROOTLESS:-energietracker:farm-rootless}"
export FARM_IMAGE_APACHE84="${FARM_IMAGE_APACHE84:-energietracker:farm-apache-php8.4}"
export FARM_IMAGE_APACHE85="${FARM_IMAGE_APACHE85:-energietracker:farm-apache-php8.5}"
export FARM_HA_NEU="${FARM_HA_NEU:-stable}"
export FARM_EVCC_IMAGE="${FARM_EVCC_IMAGE:-evcc/evcc:latest}"
export FARM_CADDY_IMAGE="${FARM_CADDY_IMAGE:-caddy:2-alpine}"
export FARM_PORT_ET="${FARM_PORT_ET:-19840}"
export FARM_PORT_ET_ROOTLESS="${FARM_PORT_ET_ROOTLESS:-19841}"
export FARM_PORT_HA_NEU="${FARM_PORT_HA_NEU:-19842}"
export FARM_PORT_HA_ALT="${FARM_PORT_HA_ALT:-19843}"
export FARM_PORT_EVCC="${FARM_PORT_EVCC:-19844}"
export FARM_PORT_APACHE84="${FARM_PORT_APACHE84:-19846}"
export FARM_PORT_APACHE85="${FARM_PORT_APACHE85:-19847}"
export FARM_PORT_CADDY="${FARM_PORT_CADDY:-19848}"
export FARM_PORT_ET_PROXY="${FARM_PORT_ET_PROXY:-19849}"
export FARM_PORT_UPDATE="${FARM_PORT_UPDATE:-19850}"
export FARM_PROJECT="$PROJECT"
export FARM_COMPOSE_FILE="$FARM_DIR/compose.yml"

say() { printf '%s\n' "$*"; }
fail() { printf 'farm: %s\n' "$*" >&2; exit 1; }

compose() { docker compose -p "$PROJECT" -f "$FARM_DIR/compose.yml" "$@"; }

# Zuletzt veröffentlichte Version: der höchste Tag v* (ohne Git: VERSION)
letzte_version() {
    local t
    t="$(git -C "$ROOT" tag --list 'v[0-9]*' --sort=-v:refname 2>/dev/null | head -n 1)" || true
    if [ -n "$t" ]; then printf '%s\n' "${t#v}"; else tr -d '[:space:]' < "$ROOT/VERSION"; echo; fi
}
export FARM_UPDATE_VON="${FARM_UPDATE_VON:-$(letzte_version)}"
export FARM_IMAGE_UPDATE="${FARM_IMAGE_UPDATE:-ghcr.io/bingerminger/energietracker:$FARM_UPDATE_VON}"

# Image holen, falls es fehlt. Docker Hub scheitert am Credential-Helper des
# Schlüsselbunds („keychain cannot be accessed“), wenn die Shell keinen Zugriff
# hat; dann ein zweiter Versuch mit einer config.json ohne Helper. Ein Eintrag
# unter "auths" muss darin stehen, sonst wählt die Docker-CLI den Schlüsselbund
# von selbst wieder aus.
pull_falls_noetig() { # Image
    docker image inspect "$1" >/dev/null 2>&1 && return 0
    docker pull -q "$1" >/dev/null 2>&1 && return 0
    local cfg="$CACHE/docker-ohne-schluesselbund"
    mkdir -p "$cfg"
    [ -f "$cfg/config.json" ] || printf '{"auths":{"farm.invalid":{}}}\n' > "$cfg/config.json"
    DOCKER_CONFIG="$cfg" docker pull -q "$1" >/dev/null 2>&1 && return 0
    # Offizielle Images von Docker Hub (ohne „/“ im Namen) auch über den Spiegel von Google, dann umtaggen
    case "$1" in
        */*) ;;
        *) if DOCKER_CONFIG="$cfg" docker pull -q "mirror.gcr.io/library/$1" >/dev/null 2>&1; then
               docker tag "mirror.gcr.io/library/$1" "$1"; return 0
           fi ;;
    esac
    fail "Image $1 lässt sich nicht ziehen"
}

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
    dir="$CACHE/energietracker-integration"
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

# Der VirtualHost für Apache, wörtlich aus docs/betrieb/webserver.md (erster
# apache-Block im Abschnitt „## Apache“)
vhost_aus_doku() {
    awk '/^## Apache/{a=1} a&&/^```apache/{b=1;next} b&&/^```/{exit} b{print}' "$ROOT/docs/betrieb/webserver.md"
}

# Der Arbeitsstand wie ein Git-Checkout als tar auf stdout: alles, was Git kennt
# oder nicht ignoriert (also nie data/, samples/ oder andere ignorierte Ablagen eines Arbeitsplatzes)
checkout_tar() {
    git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "Apache-Probe braucht einen Git-Checkout"
    git -C "$ROOT" ls-files -z --cached --others --exclude-standard \
        | while IFS= read -r -d '' f; do [ -e "$ROOT/$f" ] && printf '%s\0' "$f"; done \
        | COPYFILE_DISABLE=1 tar -c -f - -C "$ROOT" --null -T -
}

cmd_build() {
    say "== Images aus dem Arbeitsstand bauen ($(cat "$ROOT/VERSION"))"
    pull_falls_noetig php:8.5-fpm-alpine
    docker build -q -t "$FARM_IMAGE" "$ROOT" >/dev/null
    say "  ✓ $FARM_IMAGE"
    docker build -q --target rootless -t "$FARM_IMAGE_ROOTLESS" "$ROOT" >/dev/null
    say "  ✓ $FARM_IMAGE_ROOTLESS"
    local vhost kontext="$CACHE/apache-kontext"
    vhost="$(vhost_aus_doku)"
    [ -n "$vhost" ] || fail "kein apache-Block unter „## Apache“ in docs/betrieb/webserver.md"
    rm -rf "$kontext" && mkdir -p "$kontext"
    checkout_tar | tar -x -f - -C "$kontext"
    for php in 8.4 8.5; do   # Mindestversion (seit v3.13.0) und neueste
        local img="$FARM_IMAGE_APACHE84"; [ "$php" = 8.5 ] && img="$FARM_IMAGE_APACHE85"
        pull_falls_noetig "php:$php-apache"
        docker build -q -f "$kontext/tools/farm/apache.Dockerfile" --build-arg "PHP_VERSION=$php" \
            --build-arg "VHOST=$vhost" -t "$img" "$kontext" >/dev/null
        say "  ✓ $img (Apache, .htaccess, VirtualHost aus der Doku)"
    done
    rm -rf "$kontext"
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
    for img in "$FARM_IMAGE" "$FARM_IMAGE_ROOTLESS" "$FARM_IMAGE_APACHE84" "$FARM_IMAGE_APACHE85"; do
        docker image inspect "$img" >/dev/null 2>&1 || fail "Image $img fehlt, erst: bash tools/farm/farm.sh build"
    done
    pull_falls_noetig "$FARM_EVCC_IMAGE"
    pull_falls_noetig "$FARM_CADDY_IMAGE"
    pull_falls_noetig "$FARM_IMAGE_UPDATE"
    say "== Farm starten (Projekt $PROJECT)"
    say "  Home Assistant neu: $FARM_HA_NEU, alt: $FARM_HA_ALT; evcc: $FARM_EVCC_IMAGE; Update von $FARM_UPDATE_VON"
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
    wait_http "Apache, PHP 8.4"         "http://127.0.0.1:$FARM_PORT_APACHE84/api.php/api/health" 120 || bad=1
    wait_http "Apache, PHP 8.5"           "http://127.0.0.1:$FARM_PORT_APACHE85/api.php/api/health" 120 || bad=1
    wait_http "Energietracker hinter Caddy" "http://127.0.0.1:$FARM_PORT_ET_PROXY/api/health" 120 || bad=1
    wait_http "Energietracker $FARM_UPDATE_VON (Update-Weg)" "http://127.0.0.1:$FARM_PORT_UPDATE/api/health" 120 || bad=1
    wait_http "evcc (Demo)"               "http://127.0.0.1:$FARM_PORT_EVCC/api/state" 120 || bad=1
    # manifest.json statt /api/onboarding: nach fertiger Einrichtung und einem Neustart meldet das 404
    wait_http "Home Assistant neu"        "http://127.0.0.1:$FARM_PORT_HA_NEU/manifest.json" 300 || bad=1
    wait_http "Home Assistant alt"        "http://127.0.0.1:$FARM_PORT_HA_ALT/manifest.json" 300 || bad=1
    [ "$bad" -eq 0 ] || fail "nicht alle Dienste bereit (Protokolle: docker compose -p $PROJECT logs)"
    say "  Energietracker http://127.0.0.1:$FARM_PORT_ET · ohne root :$FARM_PORT_ET_ROOTLESS · Apache 8.4 :$FARM_PORT_APACHE84 · 8.5 :$FARM_PORT_APACHE85"
    say "  Caddy https://localhost:$FARM_PORT_CADDY · Update-Weg :$FARM_PORT_UPDATE · HA neu :$FARM_PORT_HA_NEU · HA alt :$FARM_PORT_HA_ALT · evcc :$FARM_PORT_EVCC"
}

cmd_pruefen() {
    prepare_env
    command -v node >/dev/null 2>&1 || fail "Node 22 oder neuer fehlt"
    node "$FARM_DIR/pruefen.mjs"
}

farm_down() {
    say "== Farm abbauen"
    compose down -v --remove-orphans --timeout 20 >/dev/null 2>&1 || compose down -v --remove-orphans --timeout 20
    say "  ✓ Container, Volumes und Netze von $PROJECT entfernt (Images bleiben)"
}

# down: alles, was die Farm gestartet hat (Docker-Farm, Demo, VM)
cmd_down() {
    farm_down
    bash "$FARM_DIR/demo.sh" down
    bash "$FARM_DIR/vm.sh" stopp
}

# alles: nur die Docker-Farm (eine laufende VM oder Demo bleibt, wie sie ist)
cmd_alles() {
    local start=$SECONDS rc=0
    cmd_build
    trap 'farm_down' EXIT
    cmd_up || rc=$?
    if [ "$rc" -eq 0 ]; then cmd_pruefen || rc=$?; fi
    trap - EXIT
    farm_down
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
    farm-down) farm_down ;;
    alles)   cmd_alles ;;
    vm)      shift; bash "$FARM_DIR/vm.sh" lauf "$@" ;;
    vm-einrichten) shift; bash "$FARM_DIR/vm.sh" einrichten "$@" ;;
    demo)    bash "$FARM_DIR/demo.sh" up ;;
    *)
        sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'
        exit 2 ;;
esac
