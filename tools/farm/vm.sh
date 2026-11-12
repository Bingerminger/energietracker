#!/usr/bin/env bash
# Home Assistant OS als Parallels-VM für die Test-Farm (tools/farm/README.md, „VM“).
# Aufruf über farm.sh:
#   bash tools/farm/farm.sh vm-einrichten   einmalig: Abbild holen, VM anlegen, einrichten, Snapshots „sauber“ und „demo“
#   bash tools/farm/farm.sh vm              jedes Release: aus „sauber“ starten, App aus dem Arbeitsstand prüfen,
#                                           zurück auf „sauber“, VM stoppen
#   bash tools/farm/farm.sh down            VM stoppen (auch die Demo)
#
# Nur die eigene VM et-farm-haos. Netz nur „Shared“ von Parallels (NAT, eigenes
# Netz 10.211.55.0/24), nichts im Heimnetz. Downloads und Schlüssel nur unter
# $HOME/.cache/energietracker-farm. Kein Autostart, kein Hintergrunddienst.
set -euo pipefail

FARM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$FARM_DIR/../.." && pwd)"
CACHE="$HOME/.cache/energietracker-farm"
VM="et-farm-haos"
BOARD="generic-aarch64"
export FARM_VM_SSH_KEY="$CACHE/ssh/id_ed25519"
export FARM_VM_PORT_APP="${FARM_VM_PORT_APP:-19851}"        # direkter Port der App in der VM (MCP)
export FARM_HA_APP="${FARM_HA_APP:-$HOME/Code/energietracker-ha}"
export FARM_VM_APP_DIR="$CACHE/vm-app"
FARM_VM_DISK_MB="${FARM_VM_DISK_MB:-32768}"
FARM_VM_MEM_MB="${FARM_VM_MEM_MB:-4096}"

say() { printf '%s\n' "$*"; }
fail() { printf 'farm vm: %s\n' "$*" >&2; exit 1; }

hat_prlctl() { command -v prlctl >/dev/null 2>&1; }
# Zustand nur dieser VM (nie eine Liste aller VMs auswerten)
vm_zustand() { prlctl status "$VM" 2>/dev/null | awk '{print $NF}' || true; }
vm_da() { [ -n "$(vm_zustand)" ]; }
snapshot_id() { # Name → ID des Snapshots dieser VM
    prlctl snapshot-list "$VM" -j 2>/dev/null | node "$FARM_DIR/vm-pruefen.mjs" snapshot-id "$1"
}

# MAC der VM, dann ihre Adresse aus den DHCP-Leases des Shared-Netzes
vm_ip() {
    local mac
    mac="$(prlctl list -i "$VM" 2>/dev/null | sed -n 's/.*net0 .*mac=\([0-9A-Fa-f]\{12\}\).*/\1/p' | head -n 1 | tr 'A-F' 'a-f')"
    [ -n "$mac" ] || return 1
    node "$FARM_DIR/vm-pruefen.mjs" lease "$mac" /Library/Preferences/Parallels/parallels_dhcp_leases
}
warte_ip() { # Sekunden
    local start=$SECONDS ip=""
    while [ $((SECONDS - start)) -lt "$1" ]; do
        ip="$(vm_ip 2>/dev/null || true)"
        # Home Assistant OS 18 antwortet auf Port 80, 8123 leitet um; eine Antwort auf einem der beiden genügt
        if [ -n "$ip" ] && { curl -s -o /dev/null --max-time 3 "http://$ip:8123/" || curl -s -o /dev/null --max-time 3 "http://$ip/"; }; then
            printf '%s\n' "$ip"; return 0
        fi
        sleep 3
    done
    return 1
}

# Weiterleitung 127.0.0.1:19855/19856 → VM (vm/relay.yml): Node aus Homebrew darf ohne Freigabe
# „Lokales Netzwerk“ die VM nicht erreichen, Docker schon
relay() { docker compose -p et-farm-vm -f "$FARM_DIR/vm/relay.yml" "$@"; }
relay_start() { # IP
    local port=80
    # Home Assistant OS 18 bedient Home Assistant auf Port 80 (8123 leitet um); ältere auf 8123
    [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://$1:8123/manifest.json" || true)" = "200" ] && port=8123
    FARM_VM_IP="$1" FARM_VM_HA_PORT="$port" relay up -d --force-recreate >/dev/null 2>&1 \
        || FARM_VM_IP="$1" FARM_VM_HA_PORT="$port" relay up -d --force-recreate
}
relay_stopp() { FARM_VM_IP=0.0.0.0 relay down --remove-orphans >/dev/null 2>&1 || true; }

vm_stopp() {
    relay_stopp
    hat_prlctl || return 0
    vm_da || return 0
    local z; z="$(vm_zustand)"
    if [ "$z" != "stopped" ]; then
        # Jeder Start geht von einem Snapshot aus; was beim Stoppen verloren geht, wird nie gebraucht
        prlctl stop "$VM" --kill >/dev/null 2>&1 || true
        say "  ✓ VM $VM gestoppt" >&2
    fi
}

zurueck_auf() { # Snapshot-Name
    local id; id="$(snapshot_id "$1")"
    [ -n "$id" ] || fail "Snapshot „$1“ fehlt, erst: bash tools/farm/farm.sh vm-einrichten"
    vm_stopp
    prlctl snapshot-switch "$VM" --id "$id" >/dev/null
}

# ── Abbild: Home Assistant OS für aarch64 von der offiziellen Quelle ─────────
haos_abbild() {
    mkdir -p "$CACHE/haos" "$CACHE/haos-meta"
    local ver name url digest datei ist
    curl -sfL --retry 3 -o "$CACHE/haos-meta/stable.json" https://version.home-assistant.io/stable.json \
        || fail "version.home-assistant.io nicht erreichbar"
    ver="$(node "$FARM_DIR/vm-pruefen.mjs" haos-version "$CACHE/haos-meta/stable.json" "$BOARD")"
    [ -n "$ver" ] || fail "keine Version für $BOARD in stable.json"
    name="haos_$BOARD-$ver.img.xz"
    curl -sfL --retry 3 -o "$CACHE/haos-meta/release-$ver.json" \
        "https://api.github.com/repos/home-assistant/operating-system/releases/tags/$ver" || fail "Release $ver nicht abrufbar"
    read -r url digest < <(node "$FARM_DIR/vm-pruefen.mjs" haos-asset "$CACHE/haos-meta/release-$ver.json" "$name")
    [ -n "$digest" ] || fail "keine Prüfsumme für $name im Release $ver"
    datei="$CACHE/haos/$name"
    if [ ! -f "$datei" ]; then
        say "  Lade $name ($ver) von github.com/home-assistant/operating-system" >&2
        curl -fL --retry 8 --retry-all-errors --retry-delay 3 -C - -s -o "$datei.part" "$url" || fail "Download abgebrochen"
        mv "$datei.part" "$datei"
    fi
    ist="sha256:$(shasum -a 256 "$datei" | awk '{print $1}')"
    if [ "$ist" != "$digest" ]; then
        rm -f "$datei"
        fail "Prüfsumme von $name stimmt nicht ($ist statt $digest), Datei gelöscht"
    fi
    say "  ✓ $name, Prüfsumme $digest" >&2
    printf '%s\n' "$datei"
}

# ── VM anlegen (einmalig) ────────────────────────────────────────────────────
vm_anlegen() {
    local abbild="$1" home hds
    say "== VM $VM anlegen"
    prlctl create "$VM" --distribution linux --no-hdd >/dev/null
    prlctl set "$VM" --cpus 2 --memsize "$FARM_VM_MEM_MB" >/dev/null
    prlctl set "$VM" --autostart off >/dev/null 2>&1 || true
    prlctl set "$VM" --isolate-vm on >/dev/null 2>&1 || true
    prlctl set "$VM" --device-set net0 --type shared >/dev/null
    prlctl set "$VM" --device-del cdrom0 >/dev/null 2>&1 || true
    # Rohe Platte („plain“, dünn belegt), Abbild hinein, dann in eine Platte mit Snapshots umwandeln
    prlctl set "$VM" --device-add hdd --type plain --size "$FARM_VM_DISK_MB" --alloc-policy sparse >/dev/null
    home="$(prlctl list -i "$VM" | sed -n 's/^Home: *//p' | head -n 1)"
    hds="$(ls "$home"/*.hdd/*.hds 2>/dev/null | head -n 1)"
    [ -f "$hds" ] || fail "Plattendatei der VM nicht gefunden ($home)"
    # Entpacken ohne xz auf dem Mac: xzcat von busybox in einem Alpine-Container, nur über stdin/stdout
    # (nichts eingehängt); vm/einschreiben.mjs schreibt dünn belegt in die Platte
    local xzbild="${FARM_XZ_IMAGE:-caddy:2-alpine}"
    docker image inspect "$xzbild" >/dev/null 2>&1 || fail "Image $xzbild fehlt (einmal bash tools/farm/farm.sh up oder docker pull $xzbild)"
    docker run --rm -i "$xzbild" xzcat < "$abbild" | node "$FARM_DIR/vm/einschreiben.mjs" "$hds" >/dev/null \
        || fail "Abbild ließ sich nicht in die Platte schreiben"
    prlctl set "$VM" --device-set hdd0 --type expand >/dev/null
    say "  ✓ VM angelegt: 2 CPU, $FARM_VM_MEM_MB MB, Platte $FARM_VM_DISK_MB MB, Netz Shared (Parallels)"
}

ssh_schluessel() {
    if [ ! -f "$FARM_VM_SSH_KEY" ]; then
        mkdir -p "$(dirname "$FARM_VM_SSH_KEY")"
        chmod 700 "$(dirname "$FARM_VM_SSH_KEY")"
        ssh-keygen -q -t ed25519 -N '' -C 'et-farm-haos' -f "$FARM_VM_SSH_KEY"
    fi
}

# ── App für die VM vorbereiten (auf dem Mac) ─────────────────────────────────
# veroeffentlicht/energietracker: die App aus dem App-Repository, wie sie ist (mit image:)
# arbeitsstand/energietracker:     dieselbe ohne image:, dazu der Bau-Kontext des Arbeitsstands
app_vorbereiten() {
    local quelle="$FARM_HA_APP/energietracker" v w
    [ -f "$quelle/config.yaml" ] || fail "App nicht gefunden: $quelle (FARM_HA_APP setzen)"
    v="$FARM_VM_APP_DIR/veroeffentlicht/energietracker"; w="$FARM_VM_APP_DIR/arbeitsstand/energietracker"
    rm -rf "$FARM_VM_APP_DIR" && mkdir -p "$v" "$w"
    COPYFILE_DISABLE=1 tar -C "$quelle" -cf - . | tar -C "$v" -xf -
    COPYFILE_DISABLE=1 tar -C "$quelle" -cf - . | tar -C "$w" -xf -
    # Ohne image: baut der Supervisor die App selbst; die Version bekommt den Zusatz -farm,
    # damit der Supervisor den Wechsel als Update anbietet
    local version; version="$(tr -d '[:space:]' < "$ROOT/VERSION")-farm"
    grep -q '^image: ' "$w/config.yaml" || fail "config.yaml der App hat kein image:"
    sed -e '/^image: /d' -e "s/^version: .*/version: \"$version\"/" "$quelle/config.yaml" > "$w/config.yaml"
    # Dockerfile des Arbeitsstands, Quellen unter quelle/ (der Rest des Ordners gehört der App)
    sed -e 's#^COPY \. /app$#COPY quelle/ /app#' -e 's#^COPY docker/#COPY quelle/docker/#' "$ROOT/Dockerfile" > "$w/Dockerfile"
    [ "$(grep -c '^COPY ' "$w/Dockerfile")" = "$(grep -c '^COPY quelle/' "$w/Dockerfile")" ] \
        || fail "Dockerfile: nicht jede COPY-Zeile ließ sich auf quelle/ umstellen"
    # Bau-Kontext genau wie beim Image: .dockerignore des Arbeitsstands entscheidet
    docker build -q -f "$FARM_DIR/vm/kontext.Dockerfile" --output "type=local,dest=$w/quelle" "$ROOT" >/dev/null \
        || fail "Bau-Kontext ließ sich nicht erzeugen"
    [ -f "$w/quelle/VERSION" ] && [ -d "$w/quelle/src" ] || fail "Bau-Kontext unvollständig"
    say "  ✓ App vorbereitet: veröffentlicht und Arbeitsstand ($version, Bau in der VM)"
}

cmd_einrichten() {
    hat_prlctl || fail "prlctl fehlt (Parallels Desktop Pro oder Business)"
    local start=$SECONDS abbild ip
    ssh_schluessel
    if ! vm_da; then
        abbild="$(haos_abbild)"
        vm_anlegen "$abbild"
    fi
    if [ -z "$(snapshot_id sauber)" ]; then
        say "== Home Assistant OS starten und einrichten (erster Start lädt Home Assistant, 5 bis 20 min)"
        [ "$(vm_zustand)" = "running" ] || prlctl start "$VM" >/dev/null
        ip="$(warte_ip 900)" || fail "VM bekommt keine Adresse oder Home Assistant antwortet nicht"
        say "  ✓ VM unter $ip (Netz Shared)"
        relay_start "$ip"
        node "$FARM_DIR/vm-pruefen.mjs" einrichten "$ip"
        node "$FARM_DIR/vm-pruefen.mjs" herunterfahren "$ip" || prlctl stop "$VM" >/dev/null
        local t=0; while [ "$(vm_zustand)" != "stopped" ] && [ $t -lt 120 ]; do sleep 2; t=$((t + 2)); done
        vm_stopp
        prlctl snapshot "$VM" -n sauber -d "Test-Farm: Home Assistant OS eingerichtet, ohne Energietracker" >/dev/null
        say "  ✓ Snapshot „sauber“"
    fi
    if [ "${1:-}" = "--demo" ] || [ -z "$(snapshot_id demo)" ]; then
        say "== Snapshot „demo“ (App aus dem Arbeitsstand mit Schaufenster, Integration, Benachrichtigungen)"
        app_vorbereiten
        zurueck_auf sauber
        prlctl start "$VM" >/dev/null
        ip="$(warte_ip 600)" || fail "VM antwortet nicht"
        relay_start "$ip"
        node "$FARM_DIR/vm-pruefen.mjs" demo-einrichten "$ip"
        node "$FARM_DIR/vm-pruefen.mjs" herunterfahren "$ip" || prlctl stop "$VM" >/dev/null
        local t=0; while [ "$(vm_zustand)" != "stopped" ] && [ $t -lt 120 ]; do sleep 2; t=$((t + 2)); done
        vm_stopp
        local alt; alt="$(snapshot_id demo)"
        [ -n "$alt" ] && prlctl snapshot-delete "$VM" --id "$alt" >/dev/null
        prlctl snapshot "$VM" -n demo -d "Test-Farm: Demo mit Schaufenster" >/dev/null
        say "  ✓ Snapshot „demo“"
        zurueck_auf sauber
    fi
    local t=$((SECONDS - start))
    say "Einrichtung fertig nach $((t / 60)) min $((t % 60)) s; Platz der VM: $(du -sh "$(prlctl list -i "$VM" | sed -n 's/^Home: *//p' | head -n 1)" | awk '{print $1}')"
}

cmd_lauf() {
    hat_prlctl || fail "prlctl fehlt"
    vm_da || fail "VM $VM fehlt, erst: bash tools/farm/farm.sh vm-einrichten"
    command -v node >/dev/null 2>&1 || fail "Node 22 oder neuer fehlt"
    local start=$SECONDS rc=0 ip
    say "== Home Assistant OS ($VM) aus Snapshot „sauber“"
    app_vorbereiten
    zurueck_auf sauber
    trap 'zurueck_auf sauber; say "  ✓ zurück auf „sauber“, VM gestoppt"' EXIT
    prlctl start "$VM" >/dev/null
    ip="$(warte_ip 600)" || fail "VM antwortet nicht"
    say "  ✓ VM unter $ip"
    relay_start "$ip"
    node "$FARM_DIR/vm-pruefen.mjs" lauf "$ip" || rc=$?
    trap - EXIT
    zurueck_auf sauber
    say "  ✓ zurück auf „sauber“, VM gestoppt"
    local t=$((SECONDS - start))
    say ""
    say "Laufzeit: $((t / 60)) min $((t % 60)) s"
    return "$rc"
}

# Für farm.sh demo: VM aus „demo“ starten, Adresse ausgeben
cmd_demo_start() {
    hat_prlctl || return 1
    vm_da || return 1
    [ -n "$(snapshot_id demo)" ] || return 1
    zurueck_auf demo >&2
    prlctl start "$VM" >/dev/null
    local ip
    ip="$(warte_ip 600)" || return 1
    relay_start "$ip" >&2
    printf '%s\n' "$ip"
}

case "${1:-}" in
    einrichten) shift; cmd_einrichten "$@" ;;
    lauf)       cmd_lauf ;;
    demo-start) cmd_demo_start ;;
    stopp)      vm_stopp ;;
    ip)         vm_ip ;;
    *) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
