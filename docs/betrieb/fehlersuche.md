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
| „Keine Verbindung zum Energietracker – nichts gespeichert.“ | Der Server ist nicht erreichbar (anderes WLAN, Server aus, VPN getrennt) | Verbindung prüfen; die installierte App zeigt solange den letzten Stand („Offline – Stand vom …“) |
| Die App ist auf dem Handy nicht installierbar oder zeigt ohne Netz nichts | Aufruf über `http://`; Service Worker und Installation brauchen HTTPS | HTTPS über einen Reverse-Proxy — [Auf dem Handy nutzen](../einstieg/handy.md) |
| Nach der Anmeldung kommt immer wieder der Anmeldebildschirm | Das Sitzungs-Cookie kommt nicht an (Proxy, anderer Hostname, Einbettung) | [Sicherheit & Netzbetrieb](sicherheit.md#8-hostnamen-und-einbetten) |
| Passwort vergessen | — | In `data/auth.json` `"mode"` auf `"off"` setzen, im Docker-Container mit `ET_AUTH=off` starten; dann ein neues Passwort setzen ([Sicherheit](sicherheit.md#3-anmeldung-einschalten)) |

## Speichern und Daten

| Symptom | Ursache | Lösung |
|---|---|---|
| Speichern schlägt fehl, die Diagnose meldet fehlende Schreibrechte | Der Webserver-Benutzer darf `data/` nicht schreiben | Rechte setzen ([Installation → Schreibrechte](installation.md#33-schreibrechte)); die Diagnose zeigt, welches Verzeichnis betroffen ist |
| Nach „Mit Beispieldaten ausprobieren“ sind die eigenen Daten weg | Die Beispieldaten ersetzen den Bestand; vorher legt die App einen Snapshot an | Einstellungen → Daten → Gespeicherte Snapshots → den Snapshot „vor Demo-Daten“ mit ↩️ einspielen |
| Ein Backup-Import wird abgelehnt | Die Datei ist kein Backup im Format 3.0 oder beschädigt; die Vorschau nennt die Fundstellen | Datei prüfen; ein altes v0.9.0-Backup gehört in die [Migration](../anleitungen/migration-v090.md) |
| Upload bricht bei großen Dateien ab | Grenzen von PHP (`upload_max_filesize`, `post_max_size`) oder nginx (`client_max_body_size`) | Grenzen erhöhen; das Docker-Image erlaubt 32 MB |
| Ein Stand macht einen riesigen Sprung | Tippfehler oder ein Zählertausch ohne Eintrag | Stand korrigieren; einen Tausch unter ⚙️ Zähler → Zählertausch mit dem Endstand des alten Geräts eintragen. Die Ablesetabelle markiert solche Stände mit „UNPLAUSIBEL“ |
| Werte aus Home Assistant tragen „PRÜFEN“ | Der Stand ist kleiner als der vorherige (Verdacht auf einen Fehlwert) | Prüfen und mit ✅ bestätigen — erst dann zählt er |

## Rechnen und Anzeigen

| Symptom | Ursache | Lösung |
|---|---|---|
| Keine Kosten | Kein Vertrag mit Arbeitspreis für den Zeitraum | Vertrag anlegen ([Jahresabrechnung](../anleitungen/jahresabrechnung.md)) |
| Gas in kWh weicht von der Rechnung ab | Nur der Standardfaktor 11,5 ist eingetragen oder ein Zeitraum fehlt | Zustandszahl und Brennwert je Zeitraum eintragen; Rechnung prüfen zeigt die Abschnitte |
| Saldo weicht von der Abrechnung ab | Stichtag, fehlende Rück- oder Nachzahlung, fehlender Faktor, geschätzter Stand | [Jahresabrechnung eintragen und prüfen](../anleitungen/jahresabrechnung.md#5-saldo-vergleichen) |
| Analyse, Wetterbereinigung oder Prognose leer | Zu wenig Daten: 12 Monate mit mindestens 8 verwertbaren Punkten, Anomalien ab 5 Monaten; oder keine Temperaturen | Weiter ablesen; Temperaturen unter Einstellungen → Wetterdaten abgleichen |
| Keine Temperaturen, der Abgleich scheitert | Kein Standort gesetzt, oder der Server erreicht `archive-api.open-meteo.com` bzw. `api.open-meteo.com` nicht (Firewall, Proxy) | Ort suchen und übernehmen; ausgehende HTTPS-Verbindungen des Servers zu Open-Meteo zulassen — oder Temperaturen als CSV importieren |
| Heizgradtage wirken zu hoch oder zu niedrig | Der Standort steht noch auf der Voreinstellung des Landes | Eigenen Ort unter Einstellungen → Wetterdaten setzen; ein Hinweis zeigt die Voreinstellung an |
| Keine Effizienzklasse | Kein ganzes Jahr (360 abgedeckte Tage), keine Wohnfläche oder ein Land ohne Skala | Wohnfläche unter Einstellungen → Haushalt & Gebäude; siehe [FAQ](../einstieg/faq.md#warum-fehlt-die-effizienzklasse) |
| Zahlen oder Datum im falschen Format | Sprache oder Land passen nicht | Einstellungen → Allgemein → Sprache & Land |
| Umlaute im PDF-Jahresbericht fehlen | Die PHP-Erweiterung `iconv` fehlt | `iconv` nachinstallieren; der Bericht funktioniert auch ohne, mit vereinfachten Umlauten |

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
