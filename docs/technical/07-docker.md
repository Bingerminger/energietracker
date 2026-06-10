# Docker-Betrieb (für Einsteiger)

**Deutsch** · [English](../en/technical/07-docker.md)

> Gilt ab **v1.7.3**. Dieses Kapitel erklärt Schritt für Schritt, wie du
> Energietracker als Docker-Container betreibst — auch ohne Docker-Vorwissen.
> Wenn du lieber „klassisch" mit PHP/nginx installierst, siehe
> [Installation & Betrieb](01-installation.md).

---

## Warum Docker?

Ein **Container** ist eine fertig geschnürte Box, in der schon alles steckt,
was Energietracker braucht (PHP 8.4, nginx, der App-Code). Du musst auf deinem
Rechner/Server **nichts** außer Docker installieren. Vorteile:

- **Ein Befehl, läuft überall** gleich (Mac, Linux, Windows, Synology/NAS).
- **Saubere Trennung** von App und deinen Daten — Updates ändern nie deine
  Daten.
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
docker compose pull         # neue Image-Version holen
docker compose up -d        # … und mit neuer Version neu starten
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

## Welchen Tag soll ich nehmen?

| Tag | Bedeutung | Empfehlung |
|-----|-----------|------------|
| `:1.7.3` | exakt diese Version | **Produktiv** — vorhersehbar |
| `:1.7` | neueste 1.7.x | Bugfixes automatisch |
| `:latest` | immer die neueste | Zum Ausprobieren |

Best Practice für einen Server: eine **konkrete Versionsnummer** pinnen und
Updates bewusst durchführen.

---

## Erster Start: Demo-Daten oder leer?

Beim allerersten Start mit leerem `data`-Volume legt Energietracker ein
frisches, leeres Datenverzeichnis an. Möchtest du zum Ausprobieren die
**Demo-Daten** sehen, gibt es zwei Wege:

1. **Komfortabel (ab v1.7.4) in der UI:** *Einstellungen → Backup & Restore →
   „Demo-Daten laden"*.
2. **Manuell jetzt schon:** das mitgelieferte
   [`demo-data/energietracker-demo-backup.json`](../../demo-data/energietracker-demo-backup.json)
   über *Einstellungen → Backup & Restore → Backup importieren* hochladen.
   Vorher wird automatisch ein Sicherungs-Snapshot deiner aktuellen Daten
   angelegt (N1004).

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

Bei `docker run` mit `-e NAME=wert`, bei Compose unter `environment:`.

---

## Updates durchführen

```bash
# Compose
docker compose pull && docker compose up -d

# docker run
docker pull ghcr.io/bingerminger/energietracker:latest
docker rm -f energietracker
docker run -d --name energietracker -p 8080:80 \
  -v "$PWD/data:/data" ghcr.io/bingerminger/energietracker:latest
```

Deine Daten liegen im Host-Volume und bleiben dabei unverändert. Trotzdem gilt:
**vor größeren Updates ein Backup ziehen** (UI → Backup exportieren).

---

## Daten sichern & wiederherstellen

- **Sichern:** *Einstellungen → Backup & Restore → Backup exportieren* lädt
  eine JSON-Datei mit all deinen Daten herunter.
- **Wiederherstellen:** dieselbe Stelle → *Backup importieren*. Vor dem
  Überschreiben legt Energietracker automatisch einen Snapshot an.
- Auf Dateiebene liegt alles im gemounteten `data/`-Ordner — den kannst du
  zusätzlich klassisch sichern (kopieren).

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
| Daten weg nach `docker rm` | Kein `-v`-Volume gemountet. Immer `-v "$PWD/data:/data"`. |
| Port 8080 belegt | Anderen Host-Port wählen, z. B. `-p 9000:80`. |
| „403/Permission denied" auf `data` | Der Container setzt die Rechte beim Start; bei eigenem Host-Ordner ggf. Schreibrechte prüfen. |

---

[← Kompendium-Index](../README.md) · [Installation & Betrieb](01-installation.md)
