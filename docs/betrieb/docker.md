# Docker-Betrieb (für Einsteiger)

**Deutsch** · [English](../en/betrieb/docker.md)

> Gilt ab **v1.7.3**. Dieses Kapitel erklärt Schritt für Schritt, wie du
> Energietracker als Docker-Container betreibst — auch ohne Docker-Vorwissen.
> Wenn du lieber „klassisch" mit PHP/nginx installierst, siehe
> [Installation & Betrieb](installation.md).

---

## Warum Docker?

Ein **Container** ist eine fertig geschnürte Box, in der schon alles steckt,
was Energietracker braucht (PHP 8.4, nginx, der App-Code). Du musst auf deinem
Rechner/Server **nichts** außer Docker installieren. Vorteile:

- **Ein Befehl, läuft überall** gleich (Mac, Linux, Windows, Synology/NAS).
- **Saubere Trennung** von App und deinen Daten — ein neuer Container lässt
  dein Datenverzeichnis stehen. (Hebt ein Update das Datenschema an, passt
  Energietracker die Dateien einmalig an, siehe
  [Updates durchführen](#updates-durchführen).)
- **Kein PHP/nginx-Setup** von Hand.

Du brauchst dafür **Docker Desktop** (Mac/Windows) bzw. **Docker Engine**
(Linux). Prüfen, ob Docker läuft:

```bash
docker --version
```

---

## Die zwei Bausteine: Image und Container

- **Image** = die unveränderliche Vorlage (wird aus dem Internet geladen).
  Adresse: `ghcr.io/bingerminger/energietracker`.
- **Container** = eine laufende Instanz dieses Images.

Das offizielle Image ist **Multi-Arch** (`linux/amd64` **und** `linux/arm64`),
läuft also nativ auf Intel/AMD-Servern **und** auf Apple Silicon (M1–M4) sowie
ARM-NAS.

Für 32-Bit-ARM (`linux/arm/v7`, ältere Raspberry Pi und NAS) gibt es noch
kein veröffentlichtes Image. Ein Probelauf in der CI baut und startet es
bereits (seit v3.1.0); erst wenn er verlässlich durchläuft, kommt die
Plattform ins veröffentlichte Image.

---

## Wo liegen meine Daten? (das Wichtigste!)

Energietracker speichert alles als JSON-Dateien im Container-Ordner `/data`.
**Damit deine Daten ein Container-Update überleben, musst du `/data` aus dem
Container heraus auf deinen Host „spiegeln"** — das nennt man ein *Volume*.

```
-v "$PWD/data:/data"
   └── Host-Ordner ──┘ └─ Ordner im Container
```

`$PWD/data` ist der Unterordner `data` im aktuellen Verzeichnis. Liegt deine
Energietracker-Daten also immer dort, egal wie oft du den Container neu baust.

> ⚠️ **Ohne `-v`-Volume sind deine Daten weg, sobald der Container gelöscht
> wird.** Immer ein Volume mounten.

### Ordner oder Named Volume?

- **Ordner** (`-v "$PWD/data:/data"`, so steht es in `docker-compose.yml`): Die
  JSON-Dateien liegen sichtbar auf dem Host — praktisch auf einem NAS.
- **Named Volume** (`-v energietracker-data:/data`): Docker verwaltet den
  Speicher selbst. Die Daten überleben Updates genauso; gesichert wird über die
  App (Einstellungen → Daten → JSON-Backup herunterladen).

> **Docker Desktop (Mac, Windows):** Ein Ordner unter dem Benutzerverzeichnis
> lässt sich nur einbinden, wenn er unter Settings → Resources → File sharing
> freigegeben ist. Sonst startet der Container nicht — er bleibt auf
> „Created“, und Docker Desktop meldet einen Fehler (`mounts denied`, in der
> Oberfläche oft nur „HTTP 500“). Am einfachsten ist dort ein Named Volume.

---

## Variante A — `docker compose` (empfohlen)

Das ist der einfachste und robusteste Weg. Du brauchst nur die mitgelieferte
Datei `docker-compose.yml` aus dem Projekt.

```bash
# im Projektordner (dort liegt docker-compose.yml)
docker compose up -d
```

- `up` startet den Container, `-d` lässt ihn im Hintergrund laufen.
- Aufrufen im Browser: **<http://localhost:8080>**
- Der Container heißt immer **`energietracker`** (in der Compose-Datei via
  `container_name` festgelegt) und startet nach einem Reboot automatisch neu
  (`restart: unless-stopped`).

Nützliche Folgebefehle:

```bash
docker compose logs -f      # Logs live ansehen (JSON-Lines, siehe unten)
docker compose down         # Container stoppen & entfernen (Daten bleiben!)
docker compose pull         # das in docker-compose.yml eingetragene Image holen
docker compose up -d        # … und damit neu starten
```

---

## Variante B — `docker run` (ohne Compose)

```bash
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:latest
```

Zeile für Zeile:

| Teil | Bedeutung |
|------|-----------|
| `-d` | im Hintergrund (detached) |
| `--name energietracker` | **fester Container-Name** |
| `-p 8080:80` | Host-Port 8080 → Container-Port 80 |
| `-v "$PWD/data:/data"` | Daten-Volume (siehe oben) |
| `…/energietracker:latest` | das Image samt Tag |

> ⚠️ **Lässt du `--name energietracker` weg, vergibt Docker einen zufälligen
> Namen** wie `thirsty_archimedes`. Mit `--name` heißt der Container immer
> `energietracker` — übersichtlicher bei `docker ps`, `docker logs` usw.

---

## Synology Container Manager

Auf einer Synology mit DSM 7.2 läuft das Image im **Container Manager** (Paket
aus dem Paket-Zentrum; ARM- und Intel-Modelle, das Image ist Multi-Arch):

1. Einen Ordner für die Daten anlegen, etwa `docker/energietracker` im
   freigegebenen Ordner `docker`.
2. **Container Manager → Projekt → Erstellen**, als Pfad denselben Ordner,
   als Quelle „docker-compose.yml erstellen“ und diesen Inhalt:

   ```yaml
   services:
     energietracker:
       image: ghcr.io/bingerminger/energietracker:latest
       container_name: energietracker
       restart: unless-stopped
       ports:
         - "8080:80"
       volumes:
         - ./data:/data
   ```

3. Das Projekt starten und `http://<NAS-Adresse>:8080` öffnen.

Updates: im Projekt „Aktion → Erstellen“ nach dem Holen des neuen Images, oder
den Tag in der Datei auf die neue Version setzen. HTTPS kommt über den Reverse
Proxy des DSM (Systemsteuerung → Anmeldeportal → Erweitert → Reverse Proxy) mit
einem Let's-Encrypt-Zertifikat. Ohne Container geht es auch über die Web
Station — siehe [Webserver](webserver.md#synology-web-station).

---

## Unraid

Seit v3.1.0 liegt im Projekt eine Vorlage für den Docker-Tab von Unraid:
[`deploy/unraid/energietracker.xml`](../../deploy/unraid/energietracker.xml).
In den Community Applications ist Energietracker noch nicht gelistet; die
Vorlage kommt deshalb von Hand auf den Server.

1. Die Vorlage in den Ordner für eigene Vorlagen laden (Unraid-Terminal, Symbol
   `>_` oben rechts):

   ```bash
   wget -O /boot/config/plugins/dockerMan/templates-user/my-Energietracker.xml \
     https://raw.githubusercontent.com/Bingerminger/energietracker/main/deploy/unraid/energietracker.xml
   ```

2. **Docker → Add Container**, unter *Template* die Vorlage „Energietracker“
   (eigene Vorlagen) wählen.
3. Die Vorgaben passen meist: Port **8080** auf dem Host (der Container hört
   immer auf 80), Daten unter **`/mnt/user/appdata/energietracker`** (im
   Container `/data`). Die `ET_*`-Variablen stehen unter *Show more
   settings*, alle optional. **Apply** startet den Container.
4. Über das Container-Symbol → *WebUI* öffnen.

Die Vorlage nutzt `:latest`. Wer Updates bewusst einspielen will, setzt unter
*Repository* eine feste Version (siehe [Welchen Tag soll ich nehmen?](#welchen-tag-soll-ich-nehmen)).

**Rechte:** Unraid-Freigaben gehören `nobody:users` (99:100). Der Container
stellt `/data` beim Start auf seinen Webserver-Benutzer `www-data` (UID 82),
wenn der Besitzer nicht stimmt — auf dem Host gehört der Ordner danach UID 82.
Setzt das Unraid-Werkzeug *New Permissions* die Rechte zurück, startest du den
Container neu; er stellt sie wieder um.

---

## CasaOS und ZimaOS

Seit v3.1.0 liegt eine Compose-Datei im Format von CasaOS bei:
[`deploy/casaos/docker-compose.yml`](../../deploy/casaos/docker-compose.yml).
Sie pinnt die aktuelle Version, Port **8080**, Daten unter
`/DATA/AppData/$AppID/data` (im Container `/data`).

1. In CasaOS (oder ZimaOS) im Bereich *Apps* auf **+** → **Custom Install**
   (eigene App installieren).
2. Oben rechts **Import** wählen, den Inhalt der Datei einfügen und
   übernehmen.
3. Installieren, danach die App über ihr Symbol öffnen.

Für ein Update den Tag unter `image:` und `version:` auf die neue Version
setzen.

---

## Umbrel

[`deploy/umbrel/`](../../deploy/umbrel/) enthält seit v3.1.0 die Vorlage für
ein Umbrel-Paket (`umbrel-app.yml` und `docker-compose.yml`) — für einen
eigenen Community App Store oder eine spätere Einreichung im offiziellen Store.
Dort ist Energietracker noch nicht gelistet.

- Vor der App steht der `app_proxy` von Umbrel: Wer die Oberfläche öffnet,
  meldet sich bei Umbrel an.
- Ausnahme ist der Home-Assistant-Push: `/api/ingest` und `/api.php/api/ingest`
  stehen in `PROXY_AUTH_WHITELIST` und sind ohne Umbrel-Cookie erreichbar.
  Erzeuge deshalb in der App einen Ingest-Token (Einstellungen → Integrationen),
  sonst kann jeder im Netz Zählerstände schicken.
- Alle anderen Pfade verlangen die Umbrel-Anmeldung, auch `/api/summary`
  (Werte zurück nach Home Assistant) und das Kalender-Abo. Wer sie von außen
  braucht, ergänzt sie in `PROXY_AUTH_WHITELIST` und schaltet die Anmeldung
  der App ein ([Sicherheit & Netzbetrieb](sicherheit.md)).
- Die Daten liegen unter `${APP_DATA_DIR}/data` (im Container `/data`).
- Für eine Einreichung muss das Image per Digest gepinnt werden
  (`repo:tag@sha256:…`); die Datei sagt, was noch fehlt.

---

## Home Assistant

Eine Home-Assistant-App (früher „Add-on“) ist in Vorbereitung. Wer die App
schon jetzt in Home Assistant einbettet oder unter Ingress betreibt, findet die
Besonderheiten in der Anleitung [Home Assistant](../anleitungen/home-assistant.md).

---

## Welchen Tag soll ich nehmen?

| Tag | Bedeutung | Empfehlung |
|-----|-----------|------------|
| `:X.Y.Z` (z. B. die aktuelle Version aus dem CHANGELOG) | exakt diese Version | **Produktiv** — vorhersehbar |
| `:X.Y` | neueste X.Y.z | Bugfixes automatisch |
| `:latest` | immer die neueste | Zum Ausprobieren |

Die mitgelieferte `docker-compose.yml` pinnt eine feste Version.

Best Practice für einen Server: eine **konkrete Versionsnummer** pinnen und
Updates bewusst durchführen.

---

## Erster Start: Demo-Daten oder leer?

Beim allerersten Start mit leerem `data`-Volume legt Energietracker ein
frisches, leeres Datenverzeichnis an. Möchtest du zum Ausprobieren die
**Demo-Daten** sehen, gibt es zwei Wege:

1. **Komfortabel (ab v1.7.4) in der UI:** *Einstellungen → Daten → Backup &
   Wiederherstellung → „Demo-Daten laden"*.
2. **Manuell jetzt schon:** das mitgelieferte
   [`demo-data/energietracker-demo-backup.json`](../../demo-data/energietracker-demo-backup.json)
   über *Einstellungen → Daten → Backup & Wiederherstellung → Backup importieren*
   hochladen. Vorher wird automatisch ein Sicherungs-Snapshot deiner aktuellen
   Daten angelegt (N1004).

---

## Logs ansehen (Fehlersuche)

Energietracker schreibt strukturierte Logs (ein JSON-Objekt pro Zeile) nach
`stderr` — sie erscheinen direkt in den Docker-Logs:

```bash
docker logs -f energietracker
# oder mit Compose:
docker compose logs -f
```

Mehr Details (z. B. einen Eintrag pro HTTP-Request) bekommst du, indem du die
Log-Stufe auf `debug` stellst:

```bash
docker run -e ET_LOG_LEVEL=debug …   # bzw. in docker-compose.yml unter environment:
```

---

## Konfiguration über Umgebungsvariablen

| Variable | Default | Wirkung |
|----------|---------|---------|
| `ET_DATA_DIR` | `/data` | Datenpfad im Container (normal nicht ändern) |
| `ET_LOG_DEST` | `stderr` | `stderr` \| `file` \| `null` |
| `ET_LOG_LEVEL` | `info` | `debug` \| `info` \| `warning` \| `error` |
| `ET_LOG_FILE` | `<dataDir>/logs/app.log` | Pfad, wenn `ET_LOG_DEST=file` |
| `ET_AUTH` | *(leer)* | *(v2.6.0)* `off` \| `password` \| `proxy` — legt den Anmeldemodus fest; leer = in den Einstellungen umschaltbar. `ET_AUTH=off` ist auch der Notausgang bei vergessenem Passwort |
| `ET_ADMIN_PASSWORD_HASH` | *(leer)* | *(v2.6.0)* Passwort als `password_hash()`-Wert; in Compose jedes `$` verdoppeln |
| `ET_TRUSTED_PROXIES` | *(leer)* | *(v2.6.0)* Adressen/Netze des vorgeschalteten Proxys (kommagetrennt, CIDR erlaubt) — nur von dort gilt `Remote-User` |
| `ET_ALLOWED_HOSTS` | *(leer = alle)* | *(v2.6.0)* erlaubte Hostnamen, kommagetrennt, `*.domain` möglich; andere → 421. IP-Adressen und `localhost` immer |
| `ET_FRAME_ANCESTORS` | *(leer)* | *(v2.6.0)* Ursprünge, die die App einbetten dürfen (z. B. das Home-Assistant-Dashboard) |
| `ET_DEBUG` | *(leer)* | *(v2.6.0)* `1` = Datei/Zeile in Fehlerantworten — nur kurz zur Fehlersuche |

Bei `docker run` mit `-e NAME=wert`, bei Compose unter `environment:`.
Was die Anmeldung bewirkt und wann du sie brauchst:
[Sicherheit & Netzbetrieb](sicherheit.md).

**PHP-Einstellungen (seit v2.6.0):** Das Image bringt eine eigene `php.ini`
mit — `max_execution_time` 120 s, Fehlerausgabe aus, OPcache an. Seit v3.1.0
`post_max_size`/`upload_max_filesize` **256 MB** und `memory_limit` **768 MB**
(vorher 32 MB und 256 MB), nginx nimmt Anfragen bis 256 MB an
(`client_max_body_size`, vorher 32 MB): Mit Belegen (Fotos, PDFs) wird ein
Backup schnell größer, und der Import hält es beim Einspielen etwa doppelt im
Speicher. Bis v2.5.3 galten die PHP-Vorgaben (128 MB, 8 MB); ein Backup mit
vielen Jahren Tagesdaten ließ sich damit nicht zurückspielen. Ebenfalls seit
v3.1.0 wartet nginx bis zu **310 Sekunden** auf PHP (`fastcgi_read_timeout`,
vorher 120 s): Die Texterkennung im Heimnetz darf bis 300 Sekunden rechnen
(`ocr_timeout_s`), auf einem NAS ohne Grafikkarte kommt das vor. Das Warten
auf den Dienst zählt unter Linux nicht in `max_execution_time`. Steht ein
eigener Reverse-Proxy davor, gilt dessen Zeitlimit zusätzlich.

**Healthcheck:** Der Container fragt `GET /api/health` ab. Seit v2.6.0
antwortet der Endpunkt bei einer echten Störung (Daten nicht schreibbar,
beschädigte Datei, Daten neuer als die App) mit HTTP 503 — Docker zeigt den
Container dann als *unhealthy*.

---

## Updates durchführen

1. **Backup ziehen:** *Einstellungen → Daten → Backup & Wiederherstellung →
   JSON-Backup herunterladen*. Das ist dein Rückweg — eine Schema-Migration
   lässt sich nicht umkehren.
2. **CHANGELOG lesen:** Was unter „Migration" steht, betrifft dich.
3. **Neue Version holen:**

```bash
# Compose: zuerst den Tag in docker-compose.yml auf die neue Version setzen
# (oder im Projektordner `git pull` — dann steht er schon drin)
docker compose pull && docker compose up -d

# docker run
docker pull ghcr.io/bingerminger/energietracker:X.Y.Z
docker rm -f energietracker
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" ghcr.io/bingerminger/energietracker:X.Y.Z
```

> `docker compose pull` holt genau das Image, das in der `docker-compose.yml`
> steht. Ohne geänderten Tag bekommst du dieselbe Version noch einmal.

Deine Daten bleiben im Host-Volume. Braucht die neue Version ein neues
Datenschema, passt Energietracker die Dateien beim ersten Start an und legt
vorher einen Snapshot in `data/backups/` ab (`pre-migration-…`, seit v2.5.3).
Den Rückweg ersetzt er nicht: Eine ältere Version kann das neue Schema nicht
lesen — zurück geht es nur mit der alten Version **und** dem Backup aus
Schritt 1. Seit v2.6.0 erkennt eine ältere Version neuere Daten und
**schreibt nichts** (HTTP 503, `/api/health` meldet `error`), statt sie wie
bis v2.5.3 still auf das alte Schema zurückzustempeln.

---

## Daten sichern & wiederherstellen

- **Sichern:** *Einstellungen → Daten → Backup & Wiederherstellung → JSON-Backup
  herunterladen* lädt eine JSON-Datei mit all deinen Daten herunter.
- **Wiederherstellen:** dieselbe Stelle → *Backup importieren*. Seit v2.6.0
  prüft die App das Backup zuerst vollständig und zeigt eine Vorschau; ein
  fehlerhaftes Backup ändert nichts. Vor dem Überschreiben legt Energietracker
  automatisch einen Snapshot an.
- **Snapshots** (seit v2.6.0): *Einstellungen → Daten → Backup &
  Wiederherstellung → Gespeicherte Snapshots* listet sie mit Zeitpunkt und
  Anlass; von dort herunterladen, einspielen oder löschen. Automatische
  Snapshots räumt die App nach 30 Tagen auf (mindestens drei je Anlass
  bleiben), eigene nach zehn.
- Auf Dateiebene liegt alles im gemounteten `data/`-Ordner — den kannst du
  zusätzlich klassisch sichern (kopieren).
- **Belege** (seit v3.1.0): Fotos von Zählerständen liegen in
  `data/attachments/` und stehen in jedem Backup und jedem Snapshot. Das
  Volume wächst deshalb mit jedem Foto — einmal für die Datei, einmal je
  Snapshot in `data/backups/`. Wie viel die Belege belegen, zeigt
  Einstellungen → Daten über den Backup-Knöpfen; die Obergrenze setzt
  `attachments_max_mb` (Standard 500 MB).

---

## Eigenes Image bauen (optional)

Wer den Code selbst angepasst hat, baut lokal:

```bash
docker build -t energietracker .
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" energietracker
```

Das Image bündelt nginx + php-fpm (von `supervisord` zusammengehalten) und
nutzt für den Container-`HEALTHCHECK` den Endpoint `GET /api/health`.

---

## Häufige Probleme

| Symptom | Ursache / Lösung |
|---------|------------------|
| `no matching manifest for linux/arm64/v8` | Veraltetes Image. Ab v1.7.3 ist es Multi-Arch — `docker pull …:latest` erneut ausführen. |
| Container heißt `nervous_…`/`thirsty_…` | `--name energietracker` vergessen (oder `docker compose` nutzen). |
| Daten weg nach `docker rm` | Kein `-v`-Volume gemountet. Immer `-v "$PWD/data:/data"` oder ein Named Volume. |
| Docker Desktop: Container bleibt auf „Created“, Fehler `mounts denied` bzw. „HTTP 500“ | Der Datenordner ist unter Settings → Resources → File sharing nicht freigegeben. Freigeben oder ein Named Volume nehmen: `-v energietracker-data:/data`. |
| Port 8080 belegt | Anderen Host-Port wählen, z. B. `-p 9000:80`. |
| „403/Permission denied" auf `data` | Der Container setzt die Rechte beim Start; bei eigenem Host-Ordner ggf. Schreibrechte prüfen. |
| Unraid: nach *New Permissions* „Daten nicht schreibbar“ | Container neu starten — er stellt `/data` wieder auf `www-data` (UID 82). |
| Texterkennung „nicht erreichbar“, obwohl Ollama auf demselben Rechner läuft | Im Container ist `localhost` der Container selbst. Die Adresse des Rechners im Heimnetz eintragen und Ollama mit `OLLAMA_HOST=0.0.0.0` starten — [Texterkennung im Heimnetz](../anleitungen/texterkennung.md#wenn-der-energietracker-in-docker-läuft). |
| Texterkennung mit langem Zeitlimit endet mit `504` | Das Image selbst wartet seit v3.1.0 bis 310 s; ein Reverse-Proxy davor wartet kürzer. Dessen Zeitlimit über 300 s setzen oder `ocr_timeout_s` senken. |

---

[← Kompendium-Index](../README.md) · [Installation & Betrieb](installation.md)
