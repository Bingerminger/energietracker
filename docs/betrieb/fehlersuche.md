# Fehlersuche

**Deutsch** · [English](../en/betrieb/fehlersuche.md)

[← Kompendium-Index](../README.md)

Symptom → Ursache → Lösung. Fragen zur Bedienung beantwortet die
[FAQ](../einstieg/faq.md); hilft nichts davon, gehört der Fall in die
[GitHub-Issues](https://github.com/Bingerminger/energietracker/issues) — mit den
Angaben aus Einstellungen → System → System-Diagnose.

---

## Start und Aufruf

| Symptom | Ursache | Lösung |
|---|---|---|
| Docker Desktop: Der Container bleibt auf „Created“, die Oberfläche meldet „HTTP 500“ oder `mounts denied` | Der Datenordner liegt unter dem Benutzerverzeichnis und ist in Docker Desktop nicht freigegeben | Unter Settings → Resources → File sharing freigeben — oder ein Named Volume nehmen: `-v energietracker-data:/data` ([Docker](docker.md#ordner-oder-named-volume)) |
| Nach einem Update bleibt die Oberfläche bei „Lädt…“ stehen oder zeigt die alte Fassung | Der Browser hält alte Module im Cache | Seite neu laden; die installierte App ganz schließen und neu öffnen. Bleibt es, fehlen am Webserver die Cache-Regeln (`AllowOverride None` legt die `.htaccess` still) — [Webserver](webserver.md) |
| „Keine Verbindung zum Energietracker – nichts gespeichert.“ | Der Server ist nicht erreichbar (anderes WLAN, Server aus, VPN getrennt) | Verbindung prüfen; die installierte App zeigt solange den letzten Stand („Offline – Stand vom …“). Zählerstände in „Zählerstände“ warten seit v3.1.0 in der Warteschlange und werden nachgesendet ([Auf dem Handy nutzen](../einstieg/handy.md#die-warteschlange-noch-nicht-gespeichert)) |
| Die App ist auf dem Handy nicht installierbar oder zeigt ohne Netz nichts | Aufruf über `http://`; Service Worker und Installation brauchen HTTPS | HTTPS über einen Reverse-Proxy — [Auf dem Handy nutzen](../einstieg/handy.md) |
| Nach der Anmeldung kommt immer wieder der Anmeldebildschirm | Das Sitzungs-Cookie kommt nicht an (Proxy, anderer Hostname, Einbettung) | [Sicherheit & Netzbetrieb](sicherheit.md#8-hostnamen-und-einbetten) |
| Passwort vergessen | — | In `data/auth.json` `"mode"` auf `"off"` setzen, im Docker-Container mit `ET_AUTH=off` starten; dann ein neues Passwort setzen ([Sicherheit](sicherheit.md#3-anmeldung-einschalten)) |

## Speichern und Daten

| Symptom | Ursache | Lösung |
|---|---|---|
| Speichern schlägt fehl, die Diagnose meldet fehlende Schreibrechte | Der Webserver-Benutzer darf `data/` nicht schreiben | Rechte setzen ([Installation → Schreibrechte](installation.md#33-schreibrechte)); die Diagnose zeigt, welches Verzeichnis betroffen ist |
| Nach „Mit Beispieldaten ausprobieren“ sind die eigenen Daten weg | Die Beispieldaten ersetzen den Bestand; vorher legt die App einen Snapshot an | Einstellungen → Daten → Gespeicherte Snapshots → den Snapshot „vor Demo-Daten“ mit ↩️ einspielen |
| Ein Backup-Import wird abgelehnt | Die Datei ist kein Backup im Format 3.0 oder beschädigt; die Vorschau nennt die Fundstellen | Datei prüfen; ein altes v0.9.0-Backup gehört in die [Migration](../anleitungen/migration-v090.md) |
| Upload bricht bei großen Dateien ab, oder „Die Datei ist zu groß – höchstens … MB.“ | Grenzen von PHP (`upload_max_filesize`, `post_max_size`) oder nginx (`client_max_body_size`, sonst `413`). Über `post_max_size` antwortet die App mit `400` und `errors.attachment.size` samt der Grenze (v3.1.0) | Grenzen erhöhen; das Docker-Image erlaubt seit v3.1.0 256 MB (vorher 32 MB) — [Upload-Grenzen](webserver.md#upload-grenzen-und-php-speicher-v310). Für Belege gelten außerdem die eigenen Grenzen: Foto 3 MB, PDF 10 MB |
| Backup-Import bricht ab (zu groß) | Seit v3.1.0 stehen die Belege im Backup; es ist größer als die Upload-Grenze, oder PHP geht beim Einspielen der Speicher aus (`memory_limit` — gebraucht wird etwa die doppelte Größe des Backups) | Grenzen anheben ([Upload-Grenzen](webserver.md#upload-grenzen-und-php-speicher-v310)). Ein Snapshot unter Einstellungen → Daten lässt sich ohne Upload einspielen. Für eine schnelle Sicherung nur der Zahlen: `GET /api/backup/export?attachments=0` ([API](../referenz/api.md#snapshots-und-import-v260)) |
| Ein Stand macht einen riesigen Sprung | Tippfehler oder ein Zählertausch ohne Eintrag | Stand korrigieren; einen Tausch unter ⚙️ Zähler → Zählertausch mit dem Endstand des alten Geräts eintragen. Die Ablesetabelle markiert solche Stände mit „UNPLAUSIBEL“ |
| Werte aus Home Assistant tragen „PRÜFEN“ | Der Stand ist kleiner als der vorherige (Verdacht auf einen Fehlwert) | Prüfen und mit ✅ bestätigen — erst dann zählt er |
| „Dieser Zähler erfasst Verbrauch je Zeitraum …“ (auch aus Home Assistant) | *(v3.1.0)* Der Zähler hat die Erfassung „Verbrauch je Zeitraum“ und nimmt keine Stände an (`errors.reading.periodMeter`, `errors.ingest.periodMeter`) | Einen Zeitraum eintragen, oder für Stände einen eigenen Zähler mit „Zählerstände“ anlegen ([Heizwärme](../verstehen/15-waerme.md)) |
| „Die Erfassungsart lässt sich nicht ändern …“ | *(v3.1.0)* Der Zähler hat schon Stände bzw. Zeiträume der bisherigen Art (`errors.meter.captureLocked`) | Einen neuen Zähler mit der gewünschten Erfassung anlegen; die alten Daten bleiben am alten Zähler |
| Die Seite „Mietverhältnis“ fehlt | *(v3.1.0)* Sie erscheint nur bei „Ich wohne: zur Miete“ | Einstellungen → Haushalt & Gebäude → Wohnen und Warmwasser ([Als Mieter](../anleitungen/mieter.md)) |

## Fotos, Warteschlange und Texterkennung *(v3.1.0)*

| Symptom | Ursache | Lösung |
|---|---|---|
| „Das Bild lässt sich nicht lesen – bitte ein anderes Foto wählen.“ | Der Browser kann das Bildformat nicht öffnen (etwa HEIC in manchen Android-Browsern) | Foto mit der Kamera aus der App aufnehmen oder als JPEG speichern |
| „Der Speicher für Belege ist voll …“ | Alle Belege zusammen erreichen `attachments_max_mb` (Standard 500 MB) | Grenze unter Einstellungen → Experte → Belege anheben oder alte Fotos entfernen (Ablesetabelle → Vorschaubild → „Foto entfernen“; die Datei verschwindet nach 24 Stunden) |
| Ein Stand bleibt unter „Noch nicht gespeichert“ | Kein Netz, der Server antwortet nicht, die Anmeldung ist abgelaufen — oder der Eintrag zeigt „Konflikt“ bzw. „Nicht gespeichert: …“ | Verbindung prüfen und „Jetzt senden“; anmelden; einen Konflikt mit „Ersetzen“ oder „Vorhandenen behalten“ lösen, einen abgelehnten Stand mit „Bearbeiten“ korrigieren ([Auf dem Handy nutzen](../einstieg/handy.md#die-warteschlange-noch-nicht-gespeichert)) |
| Offline erfasste Stände sind weg | Die Warteschlange liegt im Browser des Geräts; Safari kann Daten nicht installierter Websites nach sieben Tagen ohne Nutzung löschen; das Löschen der Websitedaten im Browser leert sie ebenso | App auf den Home-Bildschirm legen und bald wieder mit Verbindung öffnen |
| Texterkennung: „nicht erreichbar“, „nicht innerhalb von … Sekunden“, „keine Adresse im Heimnetz“ | Dienst nicht erreichbar, zu langsam oder Adresse außerhalb des eigenen Netzes | [Texterkennung im Heimnetz → Wenn etwas nicht klappt](../anleitungen/texterkennung.md#wenn-etwas-nicht-klappt) |
| Texterkennung mit langem Zeitlimit endet ohne Meldung der App (`504`) | Der Webserver wartet kürzer als `ocr_timeout_s`. Das Docker-Image wartet seit v3.1.0 bis 310 s (vorher 120 s) | Eigenen Webserver bzw. Reverse-Proxy auf über 300 s stellen (`fastcgi_read_timeout`, [Webserver](webserver.md#upload-grenzen-und-php-speicher-v310)) oder das Zeitlimit senken |

## Rechnen und Anzeigen

| Symptom | Ursache | Lösung |
|---|---|---|
| Keine Kosten | Kein Vertrag mit Arbeitspreis für den Zeitraum | Vertrag anlegen ([Jahresabrechnung](../anleitungen/jahresabrechnung.md)) |
| Gas in kWh weicht von der Rechnung ab | Nur der Standardfaktor 11,5 ist eingetragen oder ein Zeitraum fehlt | Zustandszahl und Brennwert je Zeitraum eintragen; Rechnung prüfen zeigt die Abschnitte |
| Saldo weicht von der Abrechnung ab | Stichtag, fehlende Rück- oder Nachzahlung, fehlender Faktor, geschätzter Stand | [Jahresabrechnung eintragen und prüfen](../anleitungen/jahresabrechnung.md#5-saldo-vergleichen) |
| Analyse, Wetterbereinigung oder Prognose leer | Zu wenig Daten: 12 Monate mit mindestens 8 verwertbaren Punkten, Anomalien ab 5 Monaten; oder keine Temperaturen | Weiter ablesen; Temperaturen unter Einstellungen → Wetterdaten abgleichen |
| Keine Temperaturen, der Abgleich scheitert | Kein Standort gesetzt, oder der Server erreicht `archive-api.open-meteo.com` bzw. `api.open-meteo.com` nicht (Firewall, Proxy) | Ort suchen und übernehmen; ausgehende HTTPS-Verbindungen des Servers zu Open-Meteo zulassen — oder Temperaturen als CSV importieren |
| „Von SMARD laden“ meldet einen Fehler *(v3.1.0)* | Der Server erreicht `www.smard.de` nicht (Firewall, Proxy, kein curl in PHP), oder SMARD liefert gerade keine Werte | Später noch einmal versuchen; ausgehende HTTPS-Verbindungen zu SMARD zulassen — oder den Download von smard.de als Datei importieren („Datei importieren“) |
| Heizgradtage wirken zu hoch oder zu niedrig | Der Standort steht noch auf der Voreinstellung des Landes | Eigenen Ort unter Einstellungen → Wetterdaten setzen; ein Hinweis zeigt die Voreinstellung an |
| Keine Effizienzklasse | Kein ganzes Jahr (360 abgedeckte Tage), keine Wohnfläche oder ein Land ohne Skala | Wohnfläche unter Einstellungen → Haushalt & Gebäude; siehe [FAQ](../einstieg/faq.md#warum-fehlt-die-effizienzklasse) |
| Zahlen oder Datum im falschen Format | Sprache oder Land passen nicht | Einstellungen → Allgemein → Sprache & Land |
| Umlaute im PDF-Jahresbericht fehlen | Die PHP-Erweiterung `iconv` fehlt | `iconv` nachinstallieren; der Bericht funktioniert auch ohne, mit vereinfachten Umlauten |
| PDF oder CSV in einer anderen Sprache als die App | Downloads entstehen in der Standardsprache der Installation, die App zeigt seit v3.1.0 die Sprache des Geräts | Standardsprache unter Einstellungen → Allgemein → Sprache & Land ändern — oder die Druckansicht des Jahresberichts nutzen, sie folgt dem Gerät |
| CSV öffnet sich in Excel in einer Spalte oder mit falschen Zahlen | CSV-Format 1 (Semikolon, Dezimalkomma) in einem Excel, das Komma und Dezimalpunkt erwartet | Einstellungen → Daten → Datenexport: „Tabelle in der Standardsprache“ wählen ([CSV-Formate](../referenz/api.md#csv-formate-v310)) |
| CSV-Import meldet „Monat über 12“ | Datum mit dem Monat vor dem Tag (US-Schreibweise `01/15/2026`); die App deutet es nicht um | Datum als `TT.MM.JJJJ`, `TT/MM/JJJJ` oder `JJJJ-MM-TT` schreiben |

## Home Assistant

| Symptom | Ursache | Lösung |
|---|---|---|
| `401` beim Push | Token falsch oder widerrufen — oder Apache reicht den `Authorization`-Header nicht an PHP weiter | Token neu erzeugen; bei Apache prüfen, ob die mitgelieferte `.htaccess` greift |
| `400` „Kein Zähler für … gefunden (weder als Alias noch als ID)“ | Alias oder Zähler-ID stimmt nicht, oder die Verbrauchsart in der URL passt nicht | Alias unter Einstellungen → Integrationen prüfen |
| Werte kommen an, aber in der falschen Einheit | Home Assistant liefert kWh, der Zähler zählt m³ (oder umgekehrt) | [Einheiten müssen passen](../anleitungen/home-assistant.md#wichtig-einheiten-müssen-passen) |

Mehr in der [Fehlersuche der Home-Assistant-Anleitung](../anleitungen/home-assistant.md#fehlersuche).

## Mehr herausfinden

- **Diagnose:** Einstellungen → System → System-Diagnose — Version, Schema,
  Datenverzeichnis, Schreibrechte, Zahl der Zähler und Stände.
- **Zustand:** `GET /api/health` meldet `ok`, `degraded` oder `error` (dann mit
  HTTP 503).
- **Protokoll:** Docker: `docker logs energietracker`; ausführlicher mit
  `ET_LOG_LEVEL=debug`. Ein `500` nennt eine Fehler-ID, dieselbe steht im
  Protokoll ([Sicherheit → Protokoll](sicherheit.md#10-protokoll-und-fehlersuche)).

---

[← Kompendium-Index](../README.md)
