# Installation & Betrieb

**Deutsch** · [English](../en/technical/01-installation.md)

[← Kompendium-Index](../README.md)

Energietracker ist bewusst **abhängigkeitsfrei**: kein Composer, kein
npm-Build, keine Datenbank, kein externer Dienst zur Laufzeit — außer
Open-Meteo für den Temperaturabgleich (seit v2.8.0 einmal am Tag automatisch,
abschaltbar unter *Einstellungen → Wetterdaten → Wetter automatisch füllen*)
und, nur auf Knopfdruck, die Ortssuche (seit v2.12.0). Es genügt ein
PHP-fähiger Webserver und ein Browser.

---

## 1. Voraussetzungen

| Komponente | Mindestens | Empfohlen | Zweck |
|---|---|---|---|
| PHP | 8.1 | 8.4 | Backend-Laufzeit |
| PHP-Erweiterungen | `json`, `mbstring` *(optional)* | dito | JSON-Speicher; `iconv` für PDF-Umlaute |
| Webserver | PHP built-in server | Apache/nginx | Auslieferung |
| Browser | aktueller Chromium/Firefox/Safari | dito | SPA-Frontend (ES-Module) |
| Python | 3.9 | 3.11 | nur für den optionalen Excel-Import (`scripts/init_data.py`) |

Es werden **keine** PHP-Pakete via Composer benötigt. Der PDF-Bericht
wird von einem eigenen, eingebauten PDF-Writer erzeugt — kein mPDF,
kein `gd`. `iconv` (Teil der meisten PHP-Distributionen) wird für die
korrekte Umlaut-Darstellung im PDF genutzt; fehlt es, bleibt der Bericht
funktionsfähig, Umlaute werden dann vereinfacht.

Python wird **nur** gebraucht, wenn Messwerte aus einer Excel-Datei
importiert werden sollen (`scripts/init_data.py`, benötigt `openpyxl` —
siehe `requirements.txt`). Für den reinen Betrieb der App ist Python
nicht erforderlich.

---

## 2. Schnellstart (lokaler Test)

```bash
# 1. Repository auspacken / klonen
cd energietracker

# 2. Mit dem eingebauten PHP-Server starten — immer mit router.php
php -S 127.0.0.1:8080 router.php

# 3. Browser öffnen
#    http://127.0.0.1:8080
```

> Ohne `router.php` liefert der eingebaute Server **jede** Datei aus, auch
> `data/` mit allen Nutzdaten. Und nur auf `127.0.0.1` starten — er ist für
> die Entwicklung gedacht, nicht für andere im Netz.

Beim ersten Aufruf legt der **Migrator** automatisch die Datenstruktur
unter `data/` an und hebt sie auf das aktuelle Schema (**1.6.0**). Es ist
kein manueller Schritt nötig. Ein komplett leeres Verzeichnis wird seit
v1.9.1 als Erststart erkannt und mit Standard-Zählern (Gas/Strom/Wasser)
initialisiert.

### Demo-Daten laden

Das Repository enthält unter `demo-data/` einen vollständigen
Beispieldatensatz für **alle acht** Verbrauchsarten (Gas, Strom,
Wasser, Fernwärme, Heizöl, Pellets, PV-Einspeisung, PV-Erzeugung),
inklusive realistischer Tankgrößen und Lieferkadenz. Am bequemsten lädst
du ihn direkt in der App über **Einstellungen → Daten → Backup &
Wiederherstellung → Demo-Daten laden** (F1007). Alternativ per Dateisystem:

```bash
# Datenverzeichnis durch die Demo-Daten ersetzen (Vorsicht: überschreibt!)
rm -rf data && cp -r demo-data data
php -S 127.0.0.1:8080 router.php
```

Die Demo-Daten tragen `schema_version: 1.1.0` und werden beim ersten Start
automatisch auf den aktuellen Stand migriert (additiv, verlustfrei). Der
Migrationspfad (1.0.0 → aktuelles Schema) wird
weiterhin abgesichert, jetzt über einen separaten Migrations-Smoke in
der CI statt über den Demo-Start.

---

## 3. Produktivbetrieb

### 3.1 Apache

`api.php` ist der API-Einstiegspunkt, `index.php` liefert die SPA-Hülle.
Das Frontend ruft die API als `api.php/api/…` auf und navigiert per
Hash-Route (`#/…`) — Umschreiberegeln für API oder SPA sind nicht nötig.

Die **mitgelieferte `.htaccess`** (seit v2.6.0) sperrt alles, was kein
Auslieferungsgut ist — `data/` (Nutzdaten, Backups), `src/`, `tests/`,
`docs/`, `.git/` und alle Punktdateien, Projektdateien wie `composer.json`
oder `*.md` —, reicht den `Authorization`-Header an PHP-FPM weiter (für den
Home-Assistant-Token) und setzt die Cache-Header. `data/.htaccess` ist die
zweite Sicherung. Voraussetzungen:

- `AllowOverride` mindestens `FileInfo` für das Projektverzeichnis
  (in der Synology Web Station Standard),
- `mod_rewrite`, `mod_headers`, `mod_setenvif`.

Zusätzlich im VirtualHost möglich:

```apache
<Directory "/pfad/zu/energietracker/data">
    Require all denied
</Directory>
```

Prüfen, ob die Regeln greifen:
[Sicherheit → Webserver](08-security.md#9-webserver-was-nicht-ausgeliefert-werden-darf).

### 3.2 nginx (Schema)

```nginx
location /     { try_files $uri /index.php?$query_string; }
location ~ ^/(data|src|tests|scripts|docker|docs|vendor|demo-data)/ { return 404; }
location ~ /\. { return 404; }
location ~ ^/api(\.php)?(/|$) { fastcgi_pass …; include fastcgi_params; }
```

Der vollständige, getestete Regelsatz steht in `docker/nginx.conf`.

### 3.3 Schreibrechte

Der Webserver-Benutzer braucht **Schreibrecht** auf `data/` (inklusive
`data/backups/`). Schreibende Anfragen laufen nacheinander (Sperre auf
`data/.write.lock`, seit v2.5.3), jede Datei wird atomar geschrieben
(temporäre Datei, dann Umbenennen; seit v2.6.0 vorher `fsync`); ein
Abbruch mitten im Schreiben hinterlässt keine halbe Datei. Die System-Diagnose
(`Einstellungen → System → System-Diagnose`, bzw. `GET /api/diagnostics`)
zeigt an, ob die Schreibrechte korrekt gesetzt sind.

### 3.4 Datenverzeichnis verschieben (`ET_DATA_DIR`)

Standardmäßig liegt der JSON-Speicher unter `./data` relativ zu
`api.php`. Seit **v1.4.4** kann die Umgebungsvariable `ET_DATA_DIR`
einen beliebigen absoluten Pfad erzwingen — sinnvoll für getrennte
Daten-/Code-Mounts, mehrere Instanzen oder schreibgeschützte
Code-Deployments:

```bash
ET_DATA_DIR=/srv/energietracker-data php -S 127.0.0.1:8080 router.php
```

Bei Apache via `SetEnv ET_DATA_DIR /srv/…` im VirtualHost, bei nginx +
PHP-FPM via `fastcgi_param ET_DATA_DIR /srv/…;`. Fehlt die Variable,
bleibt es beim Standardpfad `./data` — vollständig abwärtskompatibel.
Der `realpath`-Traversal-Schutz in `JsonStore` (ebenfalls v1.4.4)
greift unabhängig vom gewählten Verzeichnis.

---

## 4. Datensicherung

Es gibt drei sich ergänzende Mechanismen:

1. **JSON-Vollbackup** (`Einstellungen → Daten → Backup & Wiederherstellung`):
   eine einzelne JSON-Datei im Format `3.0`, die *alle* Verbrauchsarten,
   Zähler, Verträge, Lieferungen, Temperaturen und Einstellungen enthält und
   wieder importierbar ist. Das ist das maßgebliche Sicherungsformat.
2. **Snapshot**: legt eine Kopie im Datenverzeichnis unter
   `data/backups/` ab — nützlich vor riskanten Aktionen. Automatisch
   entsteht einer vor jedem Backup-Import (`pre-restore-…`) und seit
   **v2.5.3** vor jeder Schema-Migration beim Update
   (`pre-migration-<alte Version>_…`). Bis v2.5.2 behauptete diese Stelle
   den Migrations-Snapshot, ohne dass es ihn gab.
3. **CSV-Export**: tabellarisch je Datensatz (Monatsübersicht,
   Zählerstände bzw. Lieferungen, Temperaturreihe) für Excel/LibreOffice
   — ergänzend, **nicht** als Vollbackup gedacht (nicht
   wieder-importierbar).

Das schlichte Kopieren des gesamten `data/`-Verzeichnisses ist ebenfalls
ein vollständiges Backup.

---

## 5. Update auf eine neue Version

1. **Backup ziehen** (*Einstellungen → Daten → Backup & Wiederherstellung →
   JSON-Backup herunterladen*) oder das `data/`-Verzeichnis kopieren. Das ist
   der einzige Rückweg: Eine Schema-Migration lässt sich nicht umkehren.
2. **CHANGELOG lesen** — was unter „Migration" steht, betrifft dich.
3. Programmdateien ersetzen (alles außer `data/`). Docker:
   [Updates durchführen](07-docker.md#updates-durchführen).
4. App im Browser aufrufen — der Migrator hebt das Schema bei Bedarf
   automatisch und idempotent an. Seit v2.5.3 legt er vorher einen
   Snapshot in `data/backups/` an. Scheitert der Snapshot (z. B. Platte
   voll), läuft die Migration trotzdem, und das Log meldet es —
   deshalb Schritt 1.

Ein Downgrade auf eine ältere Schema-Version wird **nicht** unterstützt;
neuere Verbrauchsarten würden von alten Versionen ignoriert. Zurück geht
es nur mit der alten Programmversion **und** dem Backup aus Schritt 1.

Migration aus einem alten privaten **v0.9.0**-Backup:
siehe [Migration aus v0.9.0](../MIGRATION-FROM-V090.md).

---

## 6. Optionaler Excel-Import

Wer Bestandsdaten aus einer Excel-Datei übernehmen möchte:

```bash
pip install -r requirements.txt        # nur openpyxl
python scripts/init_data.py --help
```

Das Skript liest Zählerstände aus einer `input.xlsx` (getrennte Tabs je
Verbrauchsart) und Temperatur-CSVs ein. Details im Skript-Header. Für
den laufenden Betrieb ist das nicht nötig — Daten lassen sich auch
vollständig über die UI pflegen.

---

[← Kompendium-Index](../README.md) ·
[Architektur →](02-architecture.md)

---

## Docker-Betrieb

Ab **v1.7.3** gibt es ein offizielles Multi-Arch-Image
(`ghcr.io/bingerminger/energietracker`, `linux/amd64` + `linux/arm64`).
Der schnellste Start:

```bash
docker compose up -d        # → http://localhost:8080
```

Ausführliche, einsteigerfreundliche Anleitung (Volumes, Updates, Logs,
Umgebungsvariablen, Fehlersuche) im eigenen Kapitel:
**[Docker-Betrieb (für Einsteiger)](07-docker.md)**.
