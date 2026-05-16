# Installation & Betrieb

[← Kompendium-Index](../README.md)

Energietracker ist bewusst **abhängigkeitsfrei**: kein Composer, kein
npm-Build, keine Datenbank, kein externer Dienst zur Laufzeit (außer dem
optionalen Open-Meteo-Abruf für Temperaturen). Es genügt ein PHP-fähiger
Webserver und ein Browser.

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

# 2. Mit dem eingebauten PHP-Server starten
php -S 127.0.0.1:8080

# 3. Browser öffnen
#    http://127.0.0.1:8080
```

Beim ersten Aufruf legt der **Migrator** automatisch die Datenstruktur
unter `data/` an und hebt sie auf das aktuelle Schema (**1.1.0**). Es ist
kein manueller Schritt nötig.

### Demo-Daten laden

Das Repository enthält unter `demo-data/` einen vollständigen
Beispieldatensatz für **alle sechs** Verbrauchsarten (Gas, Strom,
Wasser, Fernwärme, Heizöl, Pellets), inklusive realistischer
Tankgrößen und Lieferkadenz. Zum Ausprobieren:

```bash
# Datenverzeichnis durch die Demo-Daten ersetzen (Vorsicht: überschreibt!)
rm -rf data && cp -r demo-data data
php -S 127.0.0.1:8080
```

Der Migrator hebt die Demo-Daten beim ersten Start von Schema 1.0.0 auf
1.1.0 — die Demo-Dateien selbst bleiben auf 1.0.0, damit der
Migrationspfad mitgetestet wird.

---

## 3. Produktivbetrieb

### 3.1 Apache

`api.php` ist der API-Einstiegspunkt, `index.php` liefert die SPA-Hülle.
Eine minimale `.htaccess` (Beispiel) im Projektwurzelverzeichnis:

```apache
DirectoryIndex index.php
RewriteEngine On

# API-Aufrufe an api.php leiten
RewriteRule ^api/ api.php [L,QSA]

# Statische Assets direkt ausliefern; alles andere an die SPA
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

Wichtig: Das Verzeichnis `data/` darf **nicht** öffentlich auslieferbar
sein. Empfohlen wird, das Projekt so abzulegen, dass nur `index.php`,
`api.php` und `public/` über den Webserver erreichbar sind, oder `data/`
per Server-Regel zu sperren:

```apache
<Directory "/pfad/zu/energietracker/data">
    Require all denied
</Directory>
```

### 3.2 nginx (Schema)

```nginx
location /api/ { try_files $uri /api.php?$query_string; }
location /     { try_files $uri /index.php?$query_string; }
location ~ /data/ { deny all; }
location ~ \.php$ { fastcgi_pass …; include fastcgi_params; }
```

### 3.3 Schreibrechte

Der Webserver-Benutzer braucht **Schreibrecht** auf `data/` (inklusive
`data/backups/`). Alle Schreibvorgänge nutzen `LOCK_EX`, sodass parallele
Zugriffe sich nicht gegenseitig zerstören. Die System-Diagnose
(`Einstellungen → Diagnose`, bzw. `GET /api/diagnostics`) zeigt an, ob
die Schreibrechte korrekt gesetzt sind.

---

## 4. Datensicherung

Es gibt drei sich ergänzende Mechanismen:

1. **JSON-Vollbackup** (`Einstellungen → Backup`): eine einzelne
   JSON-Datei im Format `3.0`, die *alle* Verbrauchsarten, Zähler,
   Verträge, Lieferungen, Temperaturen und Einstellungen enthält und
   wieder importierbar ist. Das ist das maßgebliche Sicherungsformat.
2. **Snapshot**: legt eine Kopie im Datenverzeichnis unter
   `data/backups/` ab — nützlich vor riskanten Aktionen. Wird vor jeder
   Migration automatisch erstellt.
3. **CSV-Export**: tabellarisch je Datensatz (Monatsübersicht,
   Zählerstände bzw. Lieferungen, Temperaturreihe) für Excel/LibreOffice
   — ergänzend, **nicht** als Vollbackup gedacht (nicht
   wieder-importierbar).

Das schlichte Kopieren des gesamten `data/`-Verzeichnisses ist ebenfalls
ein vollständiges Backup.

---

## 5. Update auf eine neue Version

1. `data/`-Verzeichnis sichern (siehe oben).
2. Programmdateien ersetzen (alles außer `data/`).
3. App im Browser aufrufen — der Migrator hebt das Schema bei Bedarf
   automatisch und idempotent an und legt vorher einen Sicherheits-
   Snapshot an.

Ein Downgrade auf eine ältere Schema-Version wird **nicht** unterstützt;
neuere Verbrauchsarten würden von alten Versionen ignoriert.

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
