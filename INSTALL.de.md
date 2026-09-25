# Installation

[English](INSTALL.md) · **Deutsch**

> 🔐 **Sicherheit:** Ohne Anmeldung kann jeder, der die App erreicht, alle Daten
> lesen und ändern — im eigenen Heimnetz in Ordnung. Bevor du sie von außen
> erreichbar machst (Portfreigabe, Reverse-Proxy, QuickConnect), schalte die
> Anmeldung ein (Einstellungen → Zugriff → „Anmeldung & Zugriff") und lies
> [Sicherheit & Netzbetrieb](docs/betrieb/sicherheit.md). Besser noch:
> unterwegs per VPN zugreifen.

## Voraussetzungen

- PHP ≥ 8.4 (CLI ist für die Entwicklung ausreichend)
- Web-Browser mit ES Modules (alles ab Mitte 2020)
- Optional: Apache, nginx, Caddy für Produktivbetrieb

## Schritte

```bash
git clone https://github.com/Bingerminger/energietracker.git
cd energietracker
```

**Verzeichnisstruktur prüfen:**

```
energietracker/
├── api.php
├── index.php
├── VERSION                ← 2.16.0
├── public/                ← CSS + JS
├── src/                   ← PHP-Backend
├── data/                  ← muss schreibbar sein
├── demo-data/             ← optionaler Datensatz
└── ...
```

**`data/`-Schreibrechte sicherstellen:**

```bash
chmod -R u+w data/
```

Bei Apache/nginx ggf. den Owner auf den Webserver-Benutzer setzen
(typisch `www-data` oder `nginx`):

```bash
sudo chown -R www-data:www-data data/
```

## Lokal testen

```bash
php -S 127.0.0.1:8080 router.php
```

Im Browser <http://127.0.0.1:8080> aufrufen. Beim ersten Request
initialisiert die App das `data/`-Verzeichnis automatisch.

> Den eingebauten Server immer **mit `router.php`** starten: Ohne liefert PHP
> jede Datei des Verzeichnisses aus — auch `data/` mit allen Daten. Und auf
> `127.0.0.1` lassen; der eingebaute Server ist nicht für andere im Netz
> gedacht.

### Datenverzeichnis verschieben (optional)

Standardmäßig liegt der JSON-Speicher unter `./data` relativ zu
`api.php`. Mit der Umgebungsvariable `ET_DATA_DIR` lässt sich ein
beliebiger absoluter Pfad erzwingen (seit v1.4.4) — nützlich für
getrennte Daten-/Code-Mounts oder mehrere Instanzen:

```bash
ET_DATA_DIR=/srv/energietracker-data php -S 127.0.0.1:8080 router.php
```

Bei Apache/nginx wird die Variable über `SetEnv` bzw.
`fastcgi_param ET_DATA_DIR …` gesetzt.

## Produktiv: Apache oder nginx

Die mitgelieferte `.htaccess` erledigt bei Apache alles Nötige — sie braucht
`AllowOverride FileInfo` und die Module `mod_rewrite`, `mod_headers` und
`mod_setenvif`. Für nginx gelten die Regeln aus `docker/nginx.conf`. Beides
vollständig, mit VirtualHost, `server`-Block und Synology Web Station:
**[Webserver: Apache und nginx](docs/betrieb/webserver.md)**. Bis v2.13 standen
hier eigene, abweichende Beispiele.

Prüfen mit den Befehlen unter
[Sicherheit → Webserver](docs/betrieb/sicherheit.md#9-webserver-was-nicht-ausgeliefert-werden-darf).

## Produktiv: Docker (seit v1.7.3)

Single-Container-Image (nginx + php-fpm). Persistente Daten liegen im
gemounteten Volume unter `/data`.

**Schnellster Weg — docker compose:**

```bash
docker compose up -d        # → http://localhost:8080
```

**Oder direkt mit dem GHCR-Image:**

```bash
docker run -d --name energietracker \
  -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:2.16.0
```

> **Docker Desktop (Mac, Windows):** Ein Ordner unter dem Benutzerverzeichnis
> muss unter Settings → Resources → File sharing freigegeben sein, sonst startet
> der Container nicht (`mounts denied`, in der Oberfläche oft „HTTP 500“).
> Einfacher ist ein Named Volume: `-v energietracker-data:/data` — siehe
> [Docker](docs/betrieb/docker.md#ordner-oder-named-volume).

**Oder lokal bauen:**

```bash
docker build -t energietracker .
docker run -d --name energietracker -p 8080:80 -v "$PWD/data:/data" energietracker
```

Konfiguration über Umgebungsvariablen (`docker run -e …` bzw. `environment:`
im Compose-File):

| Variable | Default | Wirkung |
|----------|---------|---------|
| `ET_DATA_DIR` | `/data` | Speicherpfad im Container |
| `ET_LOG_DEST` | `stderr` | `stderr` \| `file` \| `null` |
| `ET_LOG_LEVEL` | `info` | `debug` \| `info` \| `warning` \| `error` |
| `ET_LOG_FILE` | `<dataDir>/logs/app.log` | Pfad bei `ET_LOG_DEST=file` |

Die Logs (JSON Lines) erscheinen bei `ET_LOG_DEST=stderr` direkt in
`docker logs energietracker`. Der Container hat einen `HEALTHCHECK` gegen
`GET /api/health`.

## Beispieldaten laden (optional)

Am einfachsten in der App: auf der leeren Übersicht **„Mit Beispieldaten
ausprobieren“**, sonst Einstellungen → Daten → „Demo-Daten laden“. Vorher legt
die App einen Snapshot des jetzigen Stands an. Das Repository enthält dieselben
Daten auch als Backup-Datei:
[`demo-data/energietracker-demo-backup.json`](demo-data/energietracker-demo-backup.json).

Per Dateikopie nur in ein **leeres** Datenverzeichnis — so bleiben `data/.htaccess`
und `data/.gitkeep` erhalten:

```bash
cp -R demo-data/. data/
```

Die Dateien tragen das Schema 1.1.0; beim ersten Start hebt der Migrator sie auf
den aktuellen Stand. Mehr dazu in [`demo-data/README.md`](demo-data/README.md).

## Migration aus v0.9.0

Wer ein altes v0.9.0-Backup hat: nach der Installation einfach in der
UI öffnen unter **Einstellungen → Daten → Backup & Wiederherstellung →
📦 Migration aus v0.9.0** und die JSON-Datei hochladen.

Detaillierte Anleitung: [Umstieg von v0.9.0](docs/anleitungen/migration-v090.md).

## Home Assistant anbinden (optional)

Du betreibst Home Assistant und möchtest Zählerstände automatisch übergeben
lassen? Der Energietracker hat dafür einen offiziellen Push-Endpoint
(`POST /api/ingest`) mit optionalem API-Token. Einrichtung direkt in der UI
unter **Einstellungen → Integrationen → 🏠 Home-Assistant-Anbindung**.

Schritt-für-Schritt inkl. REST-Command, Automatisierung und Use-Cases
(Eigenheim, Mietwohnung): [Home Assistant anbinden](docs/anleitungen/home-assistant.md).
