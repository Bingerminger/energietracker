#!/usr/bin/env bash
# Demo der Test-Farm (tools/farm/README.md, „Demo“): alles auf einmal, auf Zuruf.
#   bash tools/farm/farm.sh demo    starten (läuft, bis man abbaut)
#   bash tools/farm/farm.sh down    abbauen (auch die VM stoppen)
#
# Mit eingerichteter VM (Snapshot „demo“): Home Assistant OS mit der App aus dem
# Arbeitsstand, Seitenleiste, Integration mit Dashboard, Benachrichtigungen; dazu
# hier evcc im Demo-Modus und eine eigene Open WebUI auf 127.0.0.1:19845 an Ollama
# des Macs und per MCP an die App. Ohne VM dieselbe Idee mit Containern.
# Kein Hintergrunddienst, kein Autostart. Die Container „open-webui“ (3000) und
# „energietracker“ (8081) eines Arbeitsplatzes bleiben unberührt.
set -euo pipefail

FARM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE="$HOME/.cache/energietracker-farm"
PROJECT="et-farm-demo"
OVERRIDE="$CACHE/demo/vm-ports.yml"
export FARM_IMAGE="${FARM_IMAGE:-energietracker:farm}"
export FARM_EVCC_IMAGE="${FARM_EVCC_IMAGE:-evcc/evcc:latest}"
export FARM_PORT_WEBUI="${FARM_PORT_WEBUI:-19845}"
export FARM_PORT_EVCC_DEMO="${FARM_PORT_EVCC_DEMO:-19852}"
export FARM_PORT_ET_DEMO="${FARM_PORT_ET_DEMO:-19853}"
export FARM_PORT_HA_DEMO="${FARM_PORT_HA_DEMO:-19854}"
export FARM_VM_HOST_IP="${FARM_VM_HOST_IP:-10.211.55.2}"
export FARM_VM_PORT_APP="${FARM_VM_PORT_APP:-19851}"

say() { printf '%s\n' "$*"; }
fail() { printf 'farm demo: %s\n' "$*" >&2; exit 1; }
compose() {
    if [ -f "$OVERRIDE" ]; then docker compose -p "$PROJECT" -f "$FARM_DIR/demo.yml" -f "$OVERRIDE" "$@"
    else docker compose -p "$PROJECT" -f "$FARM_DIR/demo.yml" "$@"; fi
}
wait_http() { # Name, Adresse, Sekunden
    local start=$SECONDS
    while ! curl -sf -o /dev/null --max-time 5 "$2"; do
        [ $((SECONDS - start)) -ge "$3" ] && { say "  ✗ $1 antwortet nach $3 s nicht"; return 1; }
        sleep 2
    done
    say "  ✓ $1 bereit nach $((SECONDS - start)) s"
}
# Werkzeugserver für Open WebUI, Aufbau wie in docs/anleitungen/ki-assistent.md (Schritt 4).
# Über TOOL_SERVER_CONNECTIONS verlangt Open WebUI zusätzlich "config" (sonst 500 beim Lesen
# der Einstellungen); der Import in der Oberfläche ergänzt das Feld selbst.
werkzeugserver() { # Adresse, Schlüssel
    printf '[{"type":"mcp","url":"%s","spec_type":"url","spec":"","path":"openapi.json","auth_type":"bearer","key":"%s","info":{"id":"energietracker","name":"","description":"energietracker"},"config":{"enable":true}}]' "$1" "$2"
}

cmd_up() {
    local start=$SECONDS ip="" mcp key app ha
    docker image inspect ghcr.io/open-webui/open-webui:main >/dev/null 2>&1 \
        || fail "Image ghcr.io/open-webui/open-webui:main fehlt (docker pull ghcr.io/open-webui/open-webui:main)"
    curl -sf -o /dev/null --max-time 3 http://127.0.0.1:11434/api/version || say "  · Ollama antwortet nicht auf 127.0.0.1:11434; Open WebUI zeigt dann keine Modelle"
    mkdir -p "$CACHE/demo"
    rm -f "$OVERRIDE"
    say "== Demo starten"
    # FARM_DEMO_OHNE_VM=1: die Container-Fassung auch dann, wenn die VM eingerichtet ist
    if [ "${FARM_DEMO_OHNE_VM:-}" != "1" ] && ip="$(bash "$FARM_DIR/vm.sh" demo-start 2>/dev/null)" && [ -n "$ip" ]; then
        # Im Browser die Weiterleitung auf 127.0.0.1 (geht ohne Freigabe „Lokales Netzwerk“), sonst die VM direkt
        ha="http://127.0.0.1:${FARM_PORT_VM_HA:-19855} (oder http://$ip)"
        say "  ✓ VM aus Snapshot „demo“ unter $ip"
        # evcc auch für die VM erreichbar, nur auf der Adresse des Macs im Netz „Shared“
        printf 'services:\n  evcc:\n    ports:\n      - "%s:%s:7070"\n' "$FARM_VM_HOST_IP" "$FARM_PORT_EVCC_DEMO" > "$OVERRIDE"
        compose up -d evcc >/dev/null 2>&1 || compose up -d evcc
        node "$FARM_DIR/vm-pruefen.mjs" demo "$ip"
        key="$(cat "$CACHE/demo/mcp-key")"
        mcp="http://$ip:$FARM_VM_PORT_APP/api/mcp"
        app="http://127.0.0.1:${FARM_PORT_VM_HA:-19855}/local_energietracker (Seitenleiste „Energietracker“)"
    else
        say "  · Demo mit Containern (FARM_DEMO_OHNE_VM=1 oder keine VM mit Snapshot „demo“, siehe farm.sh vm-einrichten)"
        docker image inspect "$FARM_IMAGE" >/dev/null 2>&1 || fail "Image $FARM_IMAGE fehlt, erst: bash tools/farm/farm.sh build"
        local integration="${FARM_INTEGRATION:-$HOME/Code/energietracker-hacs}"
        [ -d "$integration/custom_components/energietracker" ] || fail "Integration nicht gefunden (FARM_INTEGRATION setzen)"
        compose --profile ohne-vm up --no-start >/dev/null 2>&1 || compose --profile ohne-vm up --no-start
        COPYFILE_DISABLE=1 tar -C "$integration/custom_components" --exclude '__pycache__' -cf - energietracker \
            | compose --profile ohne-vm run --rm --no-deps -T --entrypoint sh home-assistant -c \
                'mkdir -p /config/custom_components && tar -C /config/custom_components -xf -' >/dev/null 2>&1 \
            || fail "Integration ließ sich nicht in Home Assistant legen"
        compose --profile ohne-vm up -d >/dev/null 2>&1 || compose --profile ohne-vm up -d
        wait_http "Energietracker" "http://127.0.0.1:$FARM_PORT_ET_DEMO/api/health" 120
        wait_http "Home Assistant" "http://127.0.0.1:$FARM_PORT_HA_DEMO/api/onboarding" 300
        node "$FARM_DIR/demo.mjs" docker
        key="$(cat "$CACHE/demo/mcp-key-docker")"
        mcp="http://energietracker/api/mcp"
        app="http://127.0.0.1:$FARM_PORT_ET_DEMO"
        ha="http://127.0.0.1:$FARM_PORT_HA_DEMO"
    fi
    wait_http "evcc (Demo)" "http://127.0.0.1:$FARM_PORT_EVCC_DEMO/api/state" 120
    FARM_DEMO_TOOL_SERVERS="$(werkzeugserver "$mcp" "$key")" compose --profile webui up -d open-webui >/dev/null 2>&1 \
        || FARM_DEMO_TOOL_SERVERS="$(werkzeugserver "$mcp" "$key")" compose --profile webui up -d open-webui
    wait_http "Open WebUI" "http://127.0.0.1:$FARM_PORT_WEBUI/health" 300
    node "$FARM_DIR/demo.mjs" webui || say "  · Open WebUI: nicht alles bereit (siehe oben)"
    local t=$((SECONDS - start))
    say ""
    say "Demo läuft (gestartet in $((t / 60)) min $((t % 60)) s). Abbauen: bash tools/farm/farm.sh down"
    say "  Energietracker   $app"
    say "  Home Assistant   $ha   Benutzer farm, Passwort farm-ha-123 (zweiter: mitbewohner / farm-ha-456, falls angelegt)"
    say "  Open WebUI       http://127.0.0.1:$FARM_PORT_WEBUI   ohne Anmeldung; Werkzeug „energietracker“ im Chat einschalten"
    say "  evcc (Demo)      http://127.0.0.1:$FARM_PORT_EVCC_DEMO"
    say "  KI-Schnittstelle $mcp für Open WebUI$( [ -z "$ip" ] && printf ', vom Mac http://127.0.0.1:%s/api/mcp' "$FARM_PORT_ET_DEMO" ) (Schlüssel in ~/.cache/energietracker-farm/demo/, nur für diese Demo)"
    say "  Frage zum Ausprobieren: „Wie steht mein Gas-Saldo?“ (dieselbe Zahl wie die Saldo-Karte und der Sensor in Home Assistant)"
}

cmd_down() {
    compose --profile webui --profile ohne-vm down -v --remove-orphans --timeout 20 >/dev/null 2>&1 \
        || compose --profile webui --profile ohne-vm down -v --remove-orphans --timeout 20
    say "  ✓ Demo-Container und -Volumes von $PROJECT entfernt"
    rm -f "$OVERRIDE"
}

case "${1:-}" in
    up)   cmd_up ;;
    down) cmd_down ;;
    *) sed -n '2,4p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
