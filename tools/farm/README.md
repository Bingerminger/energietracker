# Test-Farm

**Deutsch** · [English](#test-farm-english)

Lokale Probe vor einem Release: das **Image aus dem Arbeitsstand** im Verbund
mit **Home Assistant** (aktuelle und älteste unterstützte Fassung) samt
Integration, **evcc** im Demo-Modus und der **MCP-Schnittstelle**. Die Farm
läuft **nur auf Zuruf** und wird danach wieder abgebaut, kein
Hintergrunddienst. **Nur Beispieldaten**, alle Ports nur auf `127.0.0.1`.

## Voraussetzungen

- Docker mit Compose v2, etwa 4 GB Platz für die Images (Home Assistant
  zweimal, evcc)
- Node 22 oder neuer (fetch und WebSocket eingebaut, keine Pakete), bash, curl
- die Integration: `$HOME/Code/energietracker-hacs` oder
  `FARM_INTEGRATION=<Ordner>`; fehlt beides, holt `farm.sh` eine Kopie von
  `github.com/Bingerminger/energietracker-integration` nach
  `$HOME/.cache/energietracker-farm/`

## Aufruf

```sh
bash tools/farm/farm.sh alles     # bauen, starten, prüfen, abbauen (abbauen auch bei einem Fehler)
bash tools/farm/farm.sh build     # Image aus dem Arbeitsstand: energietracker:farm und :farm-rootless
bash tools/farm/farm.sh up        # starten, Integration einlegen, auf Bereitschaft warten
bash tools/farm/farm.sh pruefen   # alle Prüfungen; Exit-Code 1 bei einem Fehler; beliebig oft
bash tools/farm/farm.sh down      # Container, Volumes und Netz entfernen; die Images bleiben
```

Ein ganzer Lauf dauert mit vorhandenen Images **unter einer Minute**; beim
ersten Mal kommen die Downloads dazu.

## Dienste

Projektname `et-farm`, Datei [`compose.yml`](compose.yml):

| Dienst | Image | Port |
|---|---|---|
| `energietracker` | `energietracker:farm` (Standard) | 19840 |
| `energietracker-rootless` | `energietracker:farm-rootless`, gehärtet wie in der Doku | 19841 |
| `ha-neu` | `ghcr.io/home-assistant/home-assistant:stable` | 19842 |
| `ha-alt` | dasselbe mit der Mindestversion aus `hacs.json` der Integration | 19843 |
| `evcc` | `evcc/evcc:latest` mit `--demo` | 19844 |

- **Keine Datei des Rechners wird eingehängt**, nur Named Volumes. Die
  Integration kommt per `tar` in das `/config`-Volume, **bevor** Home
  Assistant startet.
- **Ohne HACS:** Home Assistant 2026.11 bringt den Marketplace mit und löscht
  ein eigenes `custom_components/hacs` beim Start. Die Farm legt deshalb nur
  `custom_components/energietracker` hinein.
- **Open WebUI und Ollama gehören nicht dazu** (zu groß). Die MCP-Prüfung
  spricht JSON-RPC direkt mit `POST /api/mcp`.

## Was geprüft wird

- **Energietracker:** health, Version gleich `VERSION`, Schema gleich
  `Migrator::SCHEMA_VERSION` ohne offene Migration, Datenverzeichnis
  beschreibbar, HEALTHCHECK des Images `healthy`.
- **Beispielhaushalt** „showcase“ über `POST /api/demo/import`, alle acht
  Verbrauchsarten.
- **Lesende Probe** (nur GET, nur Struktur, keine Werte): `sw.js`,
  Einstellungen, Übersicht mit den Saldo-Feldern, „Zu tun“, je Verbrauchsart
  Vertragsstatus, Vertragsliste und Abrechnungsjahr, Rechte, MCP-Status,
  zehn gesperrte Pfade (`data/`, `src/`, `.git`, Werkzeuge), **jede Datei
  unter `public/`**. Ein zweites Mal mit Lese-Schlüssel bei eingeschalteter
  Anmeldung.
- **MCP:** Schlüssel mit Bereich `mcp`, `initialize`, `tools/list`,
  `tools/call get_overview`; Zähler, Stand, Verbrauch seit Jahresbeginn und
  Saldo wie `GET /api/summary` (wie `McpReadEndpointTest`); ein
  Lese-Schlüssel wird abgewiesen.
- **Ohne root:** UID 10001, kein Prozess mit UID 0, schreibt ins Volume bei
  nur lesbarem Dateisystem.
- **Home Assistant alt** ohne Anmeldung, **neu** mit Anmeldung und
  Lese-Schlüssel: Einrichtung per API ohne Browser, Konfigurationsablauf
  (ohne Schlüssel `invalid_auth`), **Geräte und Sensoren in der Zahl, die
  `/api/summary` erwarten lässt** (Beispielhaushalt: 14 und 45), **jeder Wert
  wie in der API, leer nur bei `null`**, Version am Gerät, keine Fehler der
  Integration im Protokoll.
- **Anmeldung an und aus:** 401 ohne Anmeldung, Sitzungs-Cookie, health nur
  mit Status und Version, Lese-Schlüssel schreibt nicht, Ausschalten mit dem
  Passwort.

## Grenzen

- **evcc:** Die Demo hat **keine Ladevorgänge**, `GET /api/sessions` liefert
  eine leere Liste. Geprüft wird der Weg bis zur Auswertung: evcc erreichbar,
  der Endpunkt antwortet mit einer Liste, der Energietracker ruft im
  Probelauf ab und meldet `errors.evcc.noSessions`. **Die Übernahme von
  Ladevorgängen prüft die Farm nicht**, das tut `EvccServiceTest` mit einer
  Attrappe.
- **Home Assistant als Container**, nicht Home Assistant OS mit Supervisor:
  Die App (`energietracker-ha`) und Ingress prüft die Farm nicht.
- **Keine KI:** Ob ein Sprachmodell mit den Werkzeugen umgehen kann, prüft
  die Farm nicht.

## Einstellungen

| Variable | Standard |
|---|---|
| `FARM_INTEGRATION` | `$HOME/Code/energietracker-hacs`, sonst die Kopie unter `$HOME/.cache` |
| `FARM_HA_NEU` | `stable` |
| `FARM_HA_ALT` | `homeassistant` aus `hacs.json` |
| `FARM_EVCC_IMAGE` | `evcc/evcc:latest` |
| `FARM_PORT_ET`, `FARM_PORT_ET_ROOTLESS`, `FARM_PORT_HA_NEU`, `FARM_PORT_HA_ALT`, `FARM_PORT_EVCC` | 19840 bis 19844 |

**Wenn das Ziehen scheitert** („keychain cannot be accessed“ des
Credential-Helpers): `DOCKER_CONFIG` auf einen Ordner zeigen lassen, dessen
`config.json` keinen `credsStore` nennt. Bleibt Docker Hub gesperrt, hilft
für evcc der Spiegel: `FARM_EVCC_IMAGE=mirror.gcr.io/evcc/evcc:latest`.

---

## Test farm (English)

A local check before a release: the **image built from the working tree**
together with **Home Assistant** (current and oldest supported version) with
the integration, **evcc** in demo mode and the **MCP interface**. It runs
**on demand only** and is torn down afterwards, no background service.
**Sample data only**, all ports on `127.0.0.1` only.

**Needs** Docker with Compose v2, about 4 GB for the images, Node 22 or later
(built-in fetch and WebSocket, no packages), bash and curl, and the
integration in `$HOME/Code/energietracker-hacs` or `FARM_INTEGRATION=<folder>`
(otherwise `farm.sh` clones it into `$HOME/.cache/energietracker-farm/`).

```sh
bash tools/farm/farm.sh alles     # build, start, check, tear down (tear down on failure too)
bash tools/farm/farm.sh build     # image from the working tree: energietracker:farm and :farm-rootless
bash tools/farm/farm.sh up        # start, add the integration, wait until ready
bash tools/farm/farm.sh pruefen   # all checks; exit code 1 on a failure; can be repeated
bash tools/farm/farm.sh down      # remove containers, volumes and network; images stay
```

A full run takes **under a minute** once the images are there. The services,
ports and variables are in the tables above; no file of the host is mounted,
the integration goes into the `/config` volume by `tar` before Home Assistant
starts, without HACS (Home Assistant 2026.11 ships the Marketplace and
deletes a custom `custom_components/hacs` on start).

**What is checked:** health, version, schema and the image's HEALTHCHECK; the
sample household "showcase"; a read-only probe of the structure (summary,
to do, contract status, contract list and billing year per utility, rights,
MCP status, ten blocked paths, every file under `public/`), once more with a
read key while sign-in is on; MCP `initialize`, `tools/list` and
`get_overview` against `GET /api/summary`; the variant without root (UID
10001, no process as UID 0, writes to the volume); Home Assistant old without
sign-in and new with sign-in and a read key: set up through the API, config
flow, **as many devices and sensors as `/api/summary` implies (sample
household: 14 and 45), every value as in the API, empty only where it is
`null`**; sign-in on and off.

**Limits:** evcc's demo has **no charging sessions** (`GET /api/sessions` is
an empty list), so the farm checks the path up to the evaluation (reachable,
a list, the app's dry run reports `errors.evcc.noSessions`), not taking
sessions over; `EvccServiceTest` covers that with a stand-in. Home Assistant
runs as a container, not Home Assistant OS with the Supervisor, so the app
and Ingress are not covered. No language model is involved.

**If pulling fails** with the credential helper ("keychain cannot be
accessed"), point `DOCKER_CONFIG` to a folder whose `config.json` names no
`credsStore`; for evcc, `FARM_EVCC_IMAGE=mirror.gcr.io/evcc/evcc:latest`
avoids Docker Hub.
