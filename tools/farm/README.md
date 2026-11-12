# Test-Farm

**Deutsch** · [English](#test-farm-english)

Lokale Probe vor einem Release: **jede Form, die die Doku Nutzern vorschlägt**,
mit dem **Arbeitsstand** statt mit veröffentlichten Images, dazu eine **Demo mit
allem auf einmal**. Die Farm läuft **nur auf Zuruf** und wird danach wieder
abgebaut, kein Hintergrunddienst, kein Autostart. **Nur Beispieldaten**, alle
Ports nur auf `127.0.0.1`, die VM nur im eigenen Netz von Parallels.

## Drei Befehle

| Befehl | Wann | Dauer | Inhalt |
|---|---|---|---|
| `bash tools/farm/farm.sh alles` | jedes Release (Tor A3) | etwa 1 min (mit vorhandenen Images) | Docker: Standard-Image, ohne root, Apache mit `.htaccess` (PHP 8.4 und 8.5), Caddy mit HTTPS und Proxy-Anmeldung, Update von der zuletzt veröffentlichten Version, Home Assistant neu und alt mit Integration und den YAML-Vorlagen aus der Doku, evcc, MCP |
| `bash tools/farm/farm.sh vm` | jedes Release (Tor A3) | etwa 2 min | Home Assistant OS als Parallels-VM `et-farm-haos` aus dem Snapshot „sauber“: die App wie beim Nutzer (veröffentlichte App aus dem App-Repository, Update auf den Arbeitsstand, den der Supervisor in der VM baut), Seitenleiste über Ingress, Anmeldung mit HA-Benutzern, HA-Backup und Rücksicherung, Integration, Benachrichtigungen über den Supervisor, MCP über den direkten Port; danach zurück auf „sauber“, VM gestoppt |
| `bash tools/farm/farm.sh demo` | auf Zuruf, läuft bis `farm.sh down` | Start etwa 1 min | alles auf einmal: die VM aus dem Snapshot „demo“ (App mit Schaufenster, Seitenleiste, Integration mit Dashboard, Benachrichtigungen), evcc im Demo-Modus, eine eigene Open WebUI an Ollama des Macs und per MCP an die App |

Einmalig vorher: `bash tools/farm/farm.sh vm-einrichten` (etwa 25 min, davon
der größte Teil der erste Start von Home Assistant OS, der Home Assistant lädt;
legt die VM an, richtet sie ohne Browser ein und legt die Snapshots „sauber“ und
„demo“ an). Den Snapshot „demo“ mit dem neuesten
Arbeitsstand auffrischen: `bash tools/farm/farm.sh vm-einrichten --demo`.
`bash tools/farm/farm.sh down` baut alles ab, was die Farm gestartet hat
(Docker-Farm, Demo, VM).

Ein Lauf ist **grün**, wenn er mit Exit-Code 0 und „GRÜN: n ok, 0 Fehler“ endet.
Zeilen mit `·` sind Beobachtungen ohne Urteil (etwa eine Grenze der Doku).

## Welche Nutzerform prüft was

| Nutzerform (Doku) | Wo | Was geprüft wird |
|---|---|---|
| Docker, Standard-Image (nginx nach `docker/nginx.conf`) | `alles`: `energietracker` | health, Version, Schema, HEALTHCHECK, Beispielhaushalt, lesende Probe mit und ohne Anmeldung, jede Datei unter `public/`, gesperrte Pfade, Anmeldung an und aus. nginx auf einem eigenen Server nutzt dieselben Regeln (`docs/betrieb/webserver.md`), einen eigenen nginx-Dienst hat die Farm deshalb nicht |
| Docker ohne root (`-rootless`, gehärtet) | `alles`: `energietracker-rootless` | UID 10001, kein Prozess als root, nur lesbares Dateisystem, schreibt ins Volume |
| Eigener Webserver, Synology Web Station (Apache mit `.htaccess`) | `alles`: `apache-php84`, `apache-php85` | PHP 8.4 (Mindestversion seit v3.13.0) und 8.5 (neueste), der VirtualHost wörtlich aus `docs/betrieb/webserver.md`, der Arbeitsstand wie ein Git-Checkout mit `data/` im Webroot: Erststart, Beispielhaushalt, gesperrte Pfade (`data/`, `src/`, `.git/`, `tools/` …), JS-Module mit MIME-Typ, Cache-Regel und `nosniff`, Push mit Token (Kopfzeile `Authorization` kommt bei PHP an), MCP über `api.php/api/mcp`, lesende Probe |
| Reverse-Proxy mit HTTPS und Anmeldung durch den Proxy | `alles`: `caddy` vor `energietracker-proxy` | Zertifikat gegen die eigene Stelle von Caddy geprüft, `ET_AUTH=proxy` mit `ET_TRUSTED_PROXIES` und `ET_PROXY_USER_HEADER`: erste Person verwaltet, zweite ist Mitglied, eine selbst gesetzte Kopfzeile überschreibt der Proxy, **dieselbe Kopfzeile direkt am Container zählt nicht**, Sicherheits-Check ohne Warnung |
| Update auf eine neue Version (Docker) | `alles`: `et-update` | zuletzt veröffentlichte Version (`ghcr.io/bingerminger/energietracker:<letzter Tag>`) mit Beispielhaushalt, dann dasselbe Volume mit dem Arbeitsstand: Schema, keine offene Migration, Zähler, Ablesungen, Lieferungen und Verträge je Art gleich gezählt, HEALTHCHECK, Backup der alten Version lässt sich einspielen |
| App in Home Assistant OS (Supervisor) | `vm` | veröffentlichte App aus dem App-Repository installiert und gestartet, lokale Kopie gleich der veröffentlichten, Update auf den Arbeitsstand mit denselben Daten, Seitenleiste für alle Benutzer, erste Person verwaltet, zweite ist Mitglied, Teilsicherung mit den Daten und ohne `*.tmp.*`, Rücksicherung, Benachrichtigung über den Supervisor kommt in Home Assistant an, direkter Port ohne Person nur mit Schlüssel, MCP |
| Integration (HACS bzw. Marketplace) | `alles`: `ha-neu`, `ha-alt`; `vm` | über `custom_components` (der Marketplace braucht eine Verbindung zu GitHub mit Konto): Konfigurationsablauf mit und ohne Lese-Schlüssel, Geräte und Sensoren in der Zahl aus `/api/summary`, jeder Wert wie in der API |
| YAML-Vorlagen für Home Assistant | `alles`: `ha-neu` (Anmeldung an) | **wörtlich aus `docs/anleitungen/home-assistant.md`**: Schritt 3 (`rest_command`, `secrets.yaml`), Schritt 4 (Automation, Stand kommt an), Schritt 5 (Sensoren und Attribute wie `/api/summary`), Schritt 6 (Webhook: Testnachricht löst die Automation mit Titel und Text aus); ersetzt werden nur Adresse, Token, Schlüssel, Webhook-ID und Zählerschlüssel |
| evcc | `alles`: `evcc` | Weg bis zur Auswertung (die Demo von evcc hat keine Ladevorgänge) |
| KI über MCP | `alles`, `vm` | JSON-RPC direkt: `initialize`, `tools/list`, `get_overview` wie `/api/summary`, falscher Schlüssel abgewiesen |
| KI mit Sprachmodell (Open WebUI und Ollama) | `demo` | zum Ansehen, nicht automatisch geprüft |
| amd64 | CI-Smoke-Test | die Farm läuft auf Apple Silicon (arm64) |
| Echte NAS (Synology Web Station mit eigenen Daten) | Strecke B des Maintainers | – |
| Vorlagen für Unraid, CasaOS, Umbrel (`deploy/`) | – | nicht geprüft |

## Voraussetzungen

- **Docker** mit Compose v2, **Node 22** oder neuer (fetch und WebSocket eingebaut,
  keine Pakete), bash, curl, git
- **Platz:** etwa 9 GB für die Images der Docker-Farm (Home Assistant zweimal fast
  7 GB, Apache mit PHP 8.4 und 8.5 zusammen etwa 1 GB, dazu evcc, Caddy und die
  veröffentlichte Version für den Update-Weg); für die VM etwa 8 GB im Ordner der VM
  von Parallels (dünn belegte Platte mit 32 GB, zwei Snapshots) und 0,4 GB für das
  Abbild unter `~/.cache/energietracker-farm/`; für die Demo das Image von Open WebUI
  (etwa 6 GB)
- die **Integration**: `$HOME/Code/energietracker-hacs` oder
  `FARM_INTEGRATION=<Ordner>`; fehlt beides, holt `farm.sh` eine Kopie von
  `github.com/Bingerminger/energietracker-integration` nach
  `$HOME/.cache/energietracker-farm/`
- **für `vm`:** Apple Silicon, **Parallels Desktop Pro oder Business** (`prlctl`),
  `ssh` und `ssh-keygen` (kein `xz` nötig: das Abbild entpackt `xzcat` in einem
  Alpine-Container, Standard `caddy:2-alpine`), die App unter `$HOME/Code/energietracker-ha` oder `FARM_HA_APP=<Ordner>`,
  Internet für die VM (sie lädt Home Assistant und baut die App)
- **für `demo`:** das Image `ghcr.io/open-webui/open-webui:main` und Ollama auf dem
  Mac (`127.0.0.1:11434`) mit einem Modell, das Werkzeuge aufrufen kann

## Ports

| Port | Dienst |
|---|---|
| 19840 | Energietracker (Standard-Image) |
| 19841 | Energietracker ohne root |
| 19842, 19843 | Home Assistant neu, alt |
| 19844 | evcc (Farm) |
| 19845 | Open WebUI (Demo) |
| 19846, 19847 | Apache mit PHP 8.4, 8.5 |
| 19848 | Caddy (HTTPS, `https://localhost:19848`) |
| 19849 | Energietracker hinter Caddy, direkt (nur zur Gegenprobe) |
| 19850 | Update-Weg |
| 19851 | direkter Port der App in der VM (Adresse der VM) |
| 19852 | evcc (Demo; für die VM auch auf `10.211.55.2`, der Adresse des Macs im Netz „Shared“) |
| 19853, 19854 | Demo ohne VM: Energietracker, Home Assistant |
| 19855, 19856 | Weiterleitung zur VM: Home Assistant, direkter Port der App |

Alle Ports auf `127.0.0.1`, außer 19851 (in der VM) und 19852 für die VM (Netz
„Shared“ von Parallels, nicht im Heimnetz).

## Docker-Farm (`alles`)

```sh
bash tools/farm/farm.sh alles     # bauen, starten, prüfen, abbauen (abbauen auch bei einem Fehler)
bash tools/farm/farm.sh build     # Images aus dem Arbeitsstand: energietracker:farm, :farm-rootless, :farm-apache-php8.4, :farm-apache-php8.5
bash tools/farm/farm.sh up        # starten, Integration einlegen, auf Bereitschaft warten
bash tools/farm/farm.sh pruefen   # alle Prüfungen; Exit-Code 1 bei einem Fehler; beliebig oft
bash tools/farm/farm.sh farm-down # nur die Docker-Farm abbauen; die Images bleiben
```

Projektname `et-farm`, Datei [`compose.yml`](compose.yml). **Keine Datei des
Rechners wird eingehängt**, nur Named Volumes; die Integration und die Vorlagen
kommen per `tar` bzw. `docker compose exec` in das `/config`-Volume. Ohne HACS:
Home Assistant 2026.11 bringt den Marketplace mit und löscht ein eigenes
`custom_components/hacs` beim Start.

- **Apache** ([`apache.Dockerfile`](apache.Dockerfile)): `php:<Version>-apache` mit
  `mod_rewrite` und `mod_headers`, die PHP-Werte aus der Tabelle „Upload-Grenzen“
  der Doku, der Arbeitsstand als Checkout (alles, was Git kennt oder nicht
  ignoriert; nie `data/` oder `samples/` eines Arbeitsplatzes). Die API läuft wie in
  der Oberfläche über `api.php/api/…`.
- **Caddy** (`caddy:2-alpine`): `tls internal`, `basic_auth` für zwei erfundene
  Namen, `header_up X-Forwarded-User`. Das Netz `proxy-netz` hat feste Adressen,
  damit `ET_TRUSTED_PROXIES` genau den Proxy nennt (`FARM_PROXY_NETZ`, Standard
  `10.198.47.0/24`).
- **Update-Weg:** `farm.sh` nimmt den höchsten Tag `v*` (`FARM_UPDATE_VON`);
  `pruefen` startet den Dienst `et-update` jedes Mal frisch mit dieser Version und
  wechselt danach auf `energietracker:farm`.
- **Vorlagen für Home Assistant:** `pruefen` schreibt sie in `configuration.yaml`,
  `secrets.yaml` und `automations.yaml` von `ha-neu` (jede Automation mit einer
  `id`, wie beim Einfügen über „In YAML bearbeiten“), prüft die Konfiguration
  und startet Home Assistant neu.

## Home Assistant OS als VM (`vm`)

```sh
bash tools/farm/farm.sh vm-einrichten          # einmalig (etwa 25 min)
bash tools/farm/farm.sh vm                     # jedes Release (etwa 2 min)
bash tools/farm/farm.sh vm-einrichten --demo   # Snapshot „demo“ mit dem neuesten Arbeitsstand
```

- **Weiterleitung auf `127.0.0.1`:** macOS lässt Programme ohne die Freigabe
  „Lokales Netzwerk“ keine Adressen im lokalen Netz erreichen, auch nicht die VM;
  Node aus Homebrew bekommt dann `EHOSTUNREACH`, `curl` und `ssh` des Systems nicht.
  `vm.sh` startet deshalb für die Dauer des Laufs einen kleinen Caddy-Container
  ([`vm/relay.yml`](vm/relay.yml)), der von `127.0.0.1:19855` zu Home Assistant der
  VM und von `127.0.0.1:19856` zum direkten Port der App weiterreicht (ohne
  `X-Forwarded-For`, das Home Assistant ohne eingerichteten Proxy mit 400 ablehnt).
- **Port 80:** Home Assistant OS 18 mit Home Assistant 2026.11 antwortet auf Port
  80, `:8123` leitet dorthin um; `vm.sh` erkennt beides.
- **Ordner der lokalen Apps:** seit Home Assistant 2026 heißt er in „Terminal &
  SSH“ `/local_apps` (vorher `/addons`); die Farm nimmt, was es gibt.

- **Nur die eigene VM** `et-farm-haos`; andere VMs fasst die Farm nicht an (sie
  fragt nur `prlctl status et-farm-haos`). Netz „Shared“ von Parallels (NAT,
  `10.211.55.0/24`), kein Port im Heimnetz. Die VM bekommt den Hostnamen
  `et-farm-haos`, damit `homeassistant.local` im Netz nicht doppelt auftaucht.
- **Abbild:** Home Assistant OS `generic-aarch64` in der Version, die
  `version.home-assistant.io/stable.json` nennt, von
  `github.com/home-assistant/operating-system`, Prüfsumme gegen das Feld `digest`
  des Releases, abgelegt unter `~/.cache/energietracker-farm/haos/`. Parallels
  bekommt eine rohe Platte („plain“, dünn belegt), das Abbild wird hineingeschrieben
  (`xzcat` im Container, [`vm/einschreiben.mjs`](vm/einschreiben.mjs) überspringt
  Nullblöcke), danach wird die Platte mit
  `prlctl set … --type expand` umgewandelt (Snapshots).
- **Einrichtung ohne Browser:** Benutzer `farm`, Ort Musterstadt, App „Terminal &
  SSH“ mit einem Schlüssel unter `~/.cache/energietracker-farm/ssh/`, das
  App-Repository `github.com/Bingerminger/energietracker-ha`. Dann Snapshot
  „sauber“.
- **Die App aus dem Arbeitsstand ohne Registry:** `vm.sh` legt unter
  `~/.cache/energietracker-farm/vm-app/` zwei Fassungen der App aus
  `energietracker-ha/energietracker` an: die veröffentlichte (unverändert, mit
  `image:`) und den Arbeitsstand (ohne `image:`, Version mit Zusatz `-farm`, dazu
  das `Dockerfile` des Arbeitsstands und sein Bau-Kontext unter `quelle/`, genau
  wie `docker build .` ihn sieht). Beide kommen nacheinander als lokale App in den
  Ordner der lokalen Apps; der Supervisor zieht zuerst das veröffentlichte Image und
  baut beim Update den Arbeitsstand selbst (`build: true`).
- **Seitenleiste:** Nach einer Installation über die API steht „In der Seitenleiste
  anzeigen“ (`ingress_panel`) auf aus; die Farm schaltet den Schalter auf der Seite
  der App ein und prüft dann den Eintrag.
- **Ablauf von `vm`:** veröffentlichte App aus dem App-Repository installieren und
  starten, mit der lokalen Kopie vergleichen (Beschreibung im Store gleich), dann
  die lokale App in der veröffentlichten Fassung mit Beispielhaushalt, Update auf
  den Arbeitsstand (Daten bleiben), Seitenleiste und zweiter Benutzer, HA-Backup
  und Rücksicherung, Integration, Benachrichtigung über den Supervisor, MCP über
  den direkten Port `19851`. Danach Snapshot „sauber“ zurück, VM gestoppt, auch
  bei einem Fehler.

## Demo (`demo`)

```sh
bash tools/farm/farm.sh demo    # gibt am Ende die Adressen und Zugänge aus
bash tools/farm/farm.sh down
```

- Mit der VM: Snapshot „demo“ (App aus dem Arbeitsstand mit Schaufenster,
  Seitenleiste, Integration mit einem Dashboard „Energietracker“, Benachrichtigungen
  über den Supervisor, evcc unter `http://10.211.55.2:19852`, KI-Schnittstelle an
  mit eigenem Schlüssel). Beim Start lädt die Demo das Schaufenster neu
  (`DemoDataAligner`): Ablesungen, Termine und Rechnungen folgen dem heutigen
  Datum, die Integration lädt danach neu. **Ein Haushalt durchgängig:** dieselben
  Zahlen in der App, in den Sensoren und in der Antwort der KI.
- Ohne VM (oder mit `FARM_DEMO_OHNE_VM=1`) dieselbe Idee mit Containern
  (Energietracker `:19853`, Home Assistant `:19854`, Benachrichtigungen über
  „Adresse und Token“).
- **Eigene Open WebUI** (Projekt `et-farm-demo`, eigenes Volume, ohne Anmeldung,
  nur `127.0.0.1:19845`) an Ollama des Macs (`host.docker.internal:11434`) und mit
  dem Werkzeugserver „energietracker“ im Format aus
  `docs/anleitungen/ki-assistent.md`. Der Container `open-webui` (Port 3000) und
  der Container `energietracker` (Port 8081) eines Arbeitsplatzes bleiben unberührt.
- Zugänge sind erfunden und gelten nur lokal: Home Assistant `farm` /
  `farm-ha-123` (in der VM auch `mitbewohner` / `farm-ha-456`), Caddy `farm` bzw.
  `mitbewohner` / `farm-proxy-123`.

## Grenzen

- **evcc:** Die Demo hat **keine Ladevorgänge**, `GET /api/sessions` liefert eine
  leere Liste. Geprüft wird der Weg bis zur Auswertung; die Übernahme prüft
  `EvccServiceTest` mit einer Attrappe.
- **Marketplace:** Die Integration kommt über `custom_components`, nicht über den
  Marketplace (der verlangt eine Verbindung zu GitHub mit Konto).
- **`backup_exclude`:** Einen Rest `*.tmp.*` kann die Farm nicht in `/data` der App
  legen; geprüft ist, dass keiner in der Sicherung steht und die Daten darin sind.
- **Apache ohne Umschreiben:** Die mitgelieferte `.htaccess` leitet `/api/…` nicht
  auf `api.php` um; die Farm meldet das als Beobachtung, geprüft wird `api.php/api/…`.
- **Keine KI im automatischen Lauf:** Ob ein Sprachmodell mit den Werkzeugen
  umgehen kann, zeigt nur die Demo.

## Einstellungen

| Variable | Standard |
|---|---|
| `FARM_INTEGRATION` | `$HOME/Code/energietracker-hacs`, sonst die Kopie unter `$HOME/.cache` |
| `FARM_HA_APP` | `$HOME/Code/energietracker-ha` |
| `FARM_HA_NEU` | `stable` |
| `FARM_HA_ALT` | `homeassistant` aus `hacs.json` |
| `FARM_EVCC_IMAGE` | `evcc/evcc:latest` |
| `FARM_UPDATE_VON` | der höchste Tag `v*` |
| `FARM_PROXY_NETZ` | `10.198.47.0/24` |
| `FARM_VM_DISK_MB`, `FARM_VM_MEM_MB` | 32768, 4096 |
| `FARM_DEMO_MODELL` | leer (Open WebUI fragt nach dem Modell) |
| `FARM_VM_HOST_IP` | `10.211.55.2` (der Mac im Netz „Shared“, für evcc der Demo) |
| `FARM_PORT_*` | Tabelle „Ports“ |

**Wenn das Ziehen von Docker Hub scheitert** („keychain cannot be accessed“ des
Credential-Helpers): `farm.sh` versucht es von selbst ein zweites Mal mit einer
`config.json` ohne Helper unter `~/.cache/energietracker-farm/`. Darin muss ein
Eintrag unter `auths` stehen, sonst wählt die Docker-CLI den Schlüsselbund wieder
von selbst. Für evcc hilft auch der Spiegel:
`FARM_EVCC_IMAGE=mirror.gcr.io/evcc/evcc:latest`.

---

## Test farm (English)

A local check before a release: **every form the documentation suggests to
users**, built from the **working tree** instead of published images, plus a
**demo with everything at once**. It runs **on demand only** and is torn down
afterwards, no background service, no autostart. **Sample data only**, all ports
on `127.0.0.1`, the VM only on Parallels' own network.

| Command | When | Time | What |
|---|---|---|---|
| `bash tools/farm/farm.sh alles` | every release (gate A3) | about 1 min with cached images | Docker: standard image, rootless, Apache with `.htaccess` (PHP 8.4, the minimum since v3.13.0, and 8.5), Caddy with HTTPS and proxy sign-in, update from the last published version, Home Assistant current and oldest with the integration and the YAML templates from the docs, evcc, MCP |
| `bash tools/farm/farm.sh vm` | every release (gate A3) | about 2 min | Home Assistant OS as the Parallels VM `et-farm-haos` from snapshot "sauber": the app as users get it (published app from the app repository, update to the working tree built by the Supervisor inside the VM), sidebar via Ingress, sign-in with HA users, HA backup and restore, integration, notifications through the Supervisor, MCP on the direct port; then back to "sauber", VM stopped |
| `bash tools/farm/farm.sh demo` | on demand, runs until `farm.sh down` | about 1 min to start | everything at once: the VM from snapshot "demo", evcc in demo mode, a separate Open WebUI wired to the Mac's Ollama and via MCP to the app |

Once beforehand: `bash tools/farm/farm.sh vm-einrichten` (about 25 min:
downloads Home Assistant OS, creates and sets up the VM without a browser, takes
the snapshots "sauber" and "demo"). `bash tools/farm/farm.sh down` removes
everything the farm started.

**Which user setup is covered where** — see the table above: Docker (standard,
nginx from `docker/nginx.conf`), rootless, own web server / Synology Web Station
(Apache with `.htaccess`, the VirtualHost taken verbatim from
`docs/betrieb/webserver.md`), reverse proxy with HTTPS and
proxy sign-in (Caddy; the same header sent straight to the container is ignored),
update from the last release on the same volume, the Home Assistant app
(Supervisor, VM), the integration (via `custom_components`; the Marketplace needs
a GitHub account), the Home Assistant YAML templates **read verbatim from the
docs** (steps 3 to 6), evcc, MCP; a language model only in the demo; amd64 only in
the CI smoke test; a real NAS only in the maintainer's track B.

**Needs** Docker with Compose v2, Node 22 or later (no packages), bash, curl and
git; about 9 GB for the Docker images; for `vm` Apple Silicon, Parallels Desktop
Pro or Business (`prlctl`), `ssh` and `ssh-keygen` (no `xz` needed: `xzcat` in an
Alpine container unpacks the image), about 8 GB for the VM; for `demo` the
image `ghcr.io/open-webui/open-webui:main` and Ollama on the Mac. Ports, settings
and limits are in the tables above. No file of the host is mounted into a
container; downloads and keys only go to `~/.cache/energietracker-farm/`.
macOS keeps programs without the "Local Network" permission (such as Node from
Homebrew) away from the VM (`EHOSTUNREACH`), so `vm.sh` runs a small Caddy relay
on `127.0.0.1:19855` and `:19856` while it needs the VM. Home Assistant OS 18
serves Home Assistant on port 80, and the local apps folder of "Terminal & SSH"
is `/local_apps` since Home Assistant 2026 (formerly `/addons`).

**If pulling from Docker Hub fails** with the credential helper ("keychain cannot
be accessed"), `farm.sh` retries with a `config.json` without a helper under
`~/.cache/energietracker-farm/`; it needs an entry under `auths`, otherwise the
Docker CLI picks the keychain again by itself.
