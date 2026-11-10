# Wegweiser: Welcher Weg passt zu dir?

<!-- Erzeugt von tools/build-setup-guide.mjs aus public/js/lib/setup-scenes.js und den Katalogen. Nicht von Hand ändern. -->

**Deutsch** · [English](../en/einstieg/wegweiser.md)

[← Kompendium-Index](../README.md)

Hier siehst du, wie die Teile zusammenhängen. **Die Wege sind Bausteine, keine Stufen:** Nimm einen, kombiniere mehrere oder bleib bei dem, der dir reicht. Home Assistant und KI sind Möglichkeiten, keine Voraussetzung.

In der App steht derselbe Wegweiser unter **Hilfe → Wegweiser**, mit der Kurzauswahl zum Antippen. Der Einrichtungsassistent stellt dieselben drei Fragen. Die Schritte stehen in den verlinkten Anleitungen, hier steht nur der Weg.

## Kurzauswahl

| Frage | Antwort | Weg |
|---|---|---|
| Hast du smarte Zähler oder Home Assistant? | Ja | [Mit Home Assistant](#weg-home-assistant) |
| Willst du Versorgerpost auslesen lassen? | Ja | [Mit Versorgerpost](#weg-inbox) |
| Willst du eine KI fragen, und darf etwas aus dem Haus? | Ja, nur im Haus | [Mit KI lokal](#weg-local-ai) |
| Willst du eine KI fragen, und darf etwas aus dem Haus? | Ja, es darf hinaus | [Mit KI in der Cloud (experimentell)](#weg-cloud-ai) |
| Zwei oder mehr davon | | [Alles zusammen](#weg-together) |
| Nichts davon | | [Von Hand](#weg-by-hand) |

Nichts davon ist eine gute Antwort. „Von Hand“ ist ein vollständiger Weg.

<a id="weg-by-hand"></a>

## Von Hand

Du liest ab und trägst ein, im Browser oder am Handy.

<picture><source media="(max-width: 600px)" srcset="../ui/wegweiser/by-hand.de.narrow.svg"><img src="../ui/wegweiser/by-hand.de.svg" alt="Schaubild: Von Hand" width="760"></picture>

**Im Haus**

- Deine Zähler → Du: du liest ab
- Du → Energietracker: Stand eintragen
- Energietracker → Du: Verbrauch, Kosten, Fristen

**Über die Hausgrenze**

- Energietracker → Wetterdienst: gerundeter Standort, abschaltbar

**Für wen:** Für alle, die ihre Zähler selbst ablesen und keine weitere Technik im Haus brauchen.

**Was du bekommst**

- Verbrauch und Kosten je Verbrauchsart, mit Vergleich zum Vorjahr.
- **Den Saldo:** Musst du nachzahlen, oder bekommst du etwas zurück?
- Prognose, Fristen und Hinweise zu deinen Verträgen.
- Rechnung prüfen und Jahresbericht.

**Was du brauchst**

- Software: Den Energietracker, am einfachsten als Docker-Container, sonst auf einem NAS oder Webserver mit PHP ab 8.2. Dazu einen aktuellen Browser.
- Hardware: **Einen Rechner oder ein NAS im Heimnetz.** Zum Eintragen reicht das Handy.

**Aufwand:** **Einmal einrichten:** Die Anleitung „Erste Schritte“ rechnet mit rund 15 Minuten. Danach trägst du nur noch Stände ein, so oft du magst. Aus zwei Ständen entsteht der erste Verbrauch.

**Schritte**

1. Energietracker starten. [Docker](../betrieb/docker.md) · [Webserver und PHP](../betrieb/installation.md)
2. **Die Einrichtung durchgehen:** Sprache und Land, wer du bist, welche Energieträger du nutzt. [Einrichten, Beispielhaushalte und Stufen](einrichtung.md)
3. Den ersten Stand eintragen. (in der App: **Zählerstände**)
4. Den Vertrag anlegen, damit Kosten und Saldo stimmen. (in der App: **Verträge & Abschläge**)
5. **Den Ort festlegen:** Für Heizgradtage und Prognose braucht die App das Wetter. (in der App: **Wetterdaten**)
6. Das Handy dazunehmen, wenn du am Zähler eintragen willst. [Auf dem Handy nutzen](handy.md)

**Wo deine Daten bleiben:** **Alles liegt als Dateien auf deinem Server, ohne Konto und ohne Telemetrie.** Hinaus geht nur der Wetterabgleich: der auf rund 1 km gerundete Standort an Open-Meteo, abschaltbar unter Verbindungen → Wetterdaten. Die Ortssuche fragt dort nur, wenn du sie benutzt.

**Was es nicht kann**

- **Zwischen zwei Ablesungen verteilt die App den Verbrauch gleichmäßig auf die Tage.** Sie misst nicht, und sie sagt dazu, welche Zahl verteilt ist.
- **Keine Viertelstunden:** Wer Flexibilität und Speicher bewerten will, braucht Werte je Viertelstunde.
- **Nichts kommt von selbst:** Ohne neuen Eintrag bleiben die Zahlen auf dem letzten Stand.

**Im Detail:** **Docker packt den Energietracker mit allem Nötigen in einen Container.** Deine Daten liegen außerhalb davon im Ordner, den du einbindest.

**Adressen und Ports**

- Docker: `http://energietracker.local:8080`. Port 8080 des Rechners führt auf Port 80 im Container.
- Apache, nginx, Synology Web Station: `http://energietracker.local/energietracker/`. **Unter dem Ordner, in den du die App gelegt hast.** Apache nutzt die mitgelieferte `.htaccess`.

**Start mit Docker**

```text
docker run -d --name energietracker \
  --restart unless-stopped -p 8080:80 \
  -v "$PWD/data:/data" \
  ghcr.io/bingerminger/energietracker:3
```

**Gut zu wissen**

- **Alles liegt in `/data` im Container, hier im Ordner `data` neben dem Befehl.** Sichern geht auch in der App: Einstellungen → Daten und Sicherung.
- Das Image gibt es für amd64 und arm64, also auch für Rechner mit ARM-Prozessor.
- **Ohne Anmeldung kann jeder, der die Adresse erreicht, alles lesen und ändern.** Die Anmeldung schaltest du unter Einstellungen → Zugang und Sicherheit ein.
- **Als App auf dem Home-Bildschirm und ohne Netz öffnen braucht es HTTPS.** Im Browser im Heimnetz geht es ohne.

**Typische Fehler**

- Port 8080 ist belegt: Anderen Port des Rechners wählen, etwa `-p 9000:80`.
- Daten weg nach `docker rm`: **Kein Ordner eingebunden.** Immer mit `-v` starten, mit einem Ordner oder einem benannten Volume.
- Docker Desktop: Fehler `mounts denied`: **Der Ordner ist nicht freigegeben.** Unter `Settings → Resources → File sharing` freigeben oder `-v energietracker-data:/data` nehmen.
- Image ohne root: `/api/health` antwortet mit `503`: Der Datenordner gehört nicht der UID des Containers: `sudo chown -R 10001:0 ./data` oder ein benanntes Volume.

[Docker](../betrieb/docker.md) · [Webserver und PHP](../betrieb/installation.md) · [Fehlersuche](../betrieb/fehlersuche.md)

---

<a id="weg-inbox"></a>

## Mit Versorgerpost

PDF in den Posteingang. Du prüfst, was ausgelesen wurde, und übernimmst es.

<picture><source media="(max-width: 600px)" srcset="../ui/wegweiser/inbox.de.narrow.svg"><img src="../ui/wegweiser/inbox.de.svg" alt="Schaubild: Mit Versorgerpost" width="760"></picture>

**Im Haus**

- Versorgerpost → Energietracker: PDF ablegen
- Energietracker → KI im Haus: Text des Dokuments
- KI im Haus → Energietracker: Vorschlag mit Belegstelle
- Du → Energietracker: du prüfst und übernimmst

**Über die Hausgrenze**

- Energietracker → Cloud-Dienst: nur das Dokument, nach deiner Zustimmung
- Energietracker → Wetterdienst: gerundeter Standort, abschaltbar

**Für wen:** Für alle, die ihre Rechnungen und Vertragsbriefe als PDF haben und nicht abtippen wollen. Auch ganz ohne KI: Dann ist der Posteingang die Ablage für deine Post.

**Was du bekommst**

- Verträge, Rechnungen, Zähler und Ablesungen aus dem Dokument, jedes Feld mit wörtlicher Belegstelle und Seite.
- **Zu jedem Dokument ein Satz:** was der Brief für dich bedeutet und was jetzt zu tun ist.
- An jedem Vertrag und Zähler eine Akte mit allen Dokumenten.
- Bei einer Preisänderung den Hinweis auf deine Rechte.

**Was du brauchst**

- Software: **Zum Ablegen reicht der Energietracker.** Zum Auslesen dein eigener Dienst im Heimnetz (Ollama oder OpenAI-kompatibel wie LM Studio) oder ein Cloud-Dienst (experimentell).
- Hardware: **Für lokales Auslesen einen Rechner, auf dem ein Sprachmodell flüssig läuft.** Ablegen geht von jedem Gerät.

**Aufwand:** **Ablegen geht sofort.** Zum Auslesen installierst du einen Dienst, lädst ein Modell und trägst die Adresse ein, oder du wählst in der Cloud Anbieter und Schlüssel. Jedes Dokument prüfst du selbst, und lokal braucht es seine Zeit; die Anleitung nennt, wie lange.

**Schritte**

1. Das erste PDF in den Posteingang legen. (in der App: **Posteingang**)
2. **Wenn es ausgelesen werden soll:** unter Verbindungen → Post und Fotos auslesen lokal oder Cloud einrichten. (in der App: **Post und Fotos auslesen**) · [Auslesen einrichten](../anleitungen/posteingang.md#auslesen-einrichten)
3. Den Vorschlag neben dem Dokument prüfen und übernehmen. [Vorschau und Übernehmen](../anleitungen/posteingang.md#vorschau-und-übernehmen)
4. Die Post auch anders hineinbringen, wenn du magst. [Andere Wege hinein](../anleitungen/posteingang.md#andere-wege-in-den-posteingang)

**Wo deine Daten bleiben:** **Das PDF bleibt auf deinem Server und reist mit jedem Backup.** Lokal geht nur der Text an deinen Dienst im Heimnetz. In der Cloud geht jedes Dokument, das du auslesen lässt, an den gewählten Dienst, auch mit Name, Anschrift und Nummern; die App fragt vorher und schickt nichts ohne deine Zustimmung. Andere Daten deines Haushalts gehen dabei nicht hinaus. Der Wetterabgleich läuft wie auf jedem Weg.

**Was es nicht kann**

- **Fotos und Scans ohne Textebene liest der Posteingang nicht.** Die erfasst du von Hand.
- Nur PDF, bis 10 MB je Datei.
- **Ohne eingerichteten Dienst liest nichts mit.** Dann legst du ab, ordnest zu und erfasst von Hand.
- **Die KI schlägt nur vor.** Übernommen wird, was du bestätigst.

**Im Detail:** **Der Browser liest die Textebene des PDFs und schickt sie an deinen Server.** Der schickt sie ohne Seiten mit Geschäftsbedingungen an deinen Dienst. Dessen Adresse muss im eigenen Netz liegen; das prüft die App bei jedem Aufruf.

**Adressen und Ports**

- Lokal mit Ollama: `http://mein-rechner.local:11434`. Aus einem Container heraus muss Ollama im Netz hören: `OLLAMA_HOST=0.0.0.0`.
- Lokal, OpenAI-kompatibel (etwa LM Studio): `http://mein-rechner.local:1234/v1`. Die Adresse reicht bis einschließlich `/v1`.
- PDF von außen ablegen: `http://energietracker.local:8080/api/inbox?name=rechnung.pdf`. Mit einem Schlüssel; Kurzbefehl und E-Mail über Home Assistant nutzen denselben Eingang.

**Ablegen mit curl**

```text
curl -H "Authorization: Bearer etk_…" \
  -H "Content-Type: application/pdf" \
  --data-binary @rechnung.pdf \
  "http://energietracker.local:8080/api/inbox?name=rechnung.pdf"
```

**Gut zu wissen**

- Der überwachte Ordner heißt `posteingang/` und liegt im Datenverzeichnis.
- **Auslesen einrichten dürfen nur Personen, die die Installation verwalten.** Die Karte hat eine eigene Taste zum Speichern.
- **Alle Cloud-Wege sind experimentell:** Sie sind noch nicht mit echten Rückmeldungen geprüft.

**Typische Fehler**

- „Für das Auslesen ist kein Dienst eingetragen“: Unter Verbindungen → Post und Fotos auslesen die Adresse eintragen und die Verbindung prüfen.
- Das Dokument hat keine Textebene: **Es ist ein Scan.** Von Hand erfassen; das PDF bleibt als Beleg.
- Keine Antwort innerhalb der Zeit: **Das erste Auslesen nach einer Pause lädt das Modell erst in den Speicher.** Noch einmal versuchen.

[Posteingang](../anleitungen/posteingang.md) · [Ollama einrichten](../anleitungen/texterkennung.md#ollama-einrichten) · [Wo deine Daten bleiben](../anleitungen/posteingang.md#datenschutz-was-wohin-geht)

---

<a id="weg-home-assistant"></a>

## Mit Home Assistant

Home Assistant liefert die Stände, der Energietracker rechnet und meldet zurück.

<picture><source media="(max-width: 600px)" srcset="../ui/wegweiser/home-assistant.de.narrow.svg"><img src="../ui/wegweiser/home-assistant.de.svg" alt="Schaubild: Mit Home Assistant" width="760"></picture>

**Im Haus**

- Smarte Zähler → Home Assistant: Zählerstände
- Home Assistant → Energietracker: Stände, täglich oder je Viertelstunde
- Energietracker → Home Assistant: Sensoren, Hinweise, Termine
- evcc → Energietracker: Ladevorgänge
- Du → Energietracker: Verträge, Auswertungen

**Über die Hausgrenze**

- Energietracker → Wetterdienst: gerundeter Standort, abschaltbar

**Für wen:** Für alle, die Home Assistant schon nutzen oder smarte Zähler auslesen wollen. Der Energietracker liest selbst keine Zähler aus, das macht Home Assistant.

**Was du bekommst**

- Stände kommen von selbst, täglich oder alle 15 Minuten.
- Mit Werten je Viertelstunde auch die Auswertungen zu Flexibilität und Speicher.
- **Zurück in Home Assistant:** Sensoren für Saldo, Verbrauch, Prognose und „Zu tun“, ohne YAML über die HACS-Integration.
- Benachrichtigungen bei Fristen und neuen Dokumenten, dazu Termine als Kalender.
- Mit evcc die Ladevorgänge der Wallbox samt Anteil aus der Sonne.

**Was du brauchst**

- Software: **Home Assistant.** Den Energietracker als App in Home Assistant OS (experimentell) oder als eigenen Container. Für die Sensoren die HACS-Integration, ab Home Assistant 2026.3.
- Hardware: **Was Home Assistant ohnehin braucht, dazu deine smarten Zähler oder Lesegeräte.** Als App braucht der Energietracker kein eigenes Gerät.

**Aufwand:** **Token erzeugen, Zählern einen Alias geben, REST-Befehl und Automatisierung eintragen.** Das ist YAML; wer Home Assistant kennt, findet sich schnell zurecht. Der Rückweg über die Integration kommt ohne YAML aus.

**Schritte**

1. **Den Energietracker starten:** als App in Home Assistant OS oder als eigener Container. [App in Home Assistant](../anleitungen/home-assistant.md#als-app-in-home-assistant-os) · [Docker](../betrieb/docker.md)
2. Unter Verbindungen → Home Assistant den Token erzeugen und den Zählern einen Alias geben. (in der App: **Home Assistant**)
3. In Home Assistant REST-Befehl und Automatisierung eintragen. [Home Assistant anbinden](../anleitungen/home-assistant.md#schritt-3--rest-command-in-home-assistant)
4. Für die Sensoren die HACS-Integration installieren. [HACS-Integration](../anleitungen/home-assistant.md#hacs-integration-v3120)
5. **Wenn du magst:** Benachrichtigungen einschalten. [Benachrichtigungen](../anleitungen/home-assistant.md#schritt-6--benachrichtigungen-an-home-assistant-v390)
6. **Mit Wallbox:** evcc dazunehmen. [Ladevorgänge aus evcc](../anleitungen/evcc.md)

**Wo deine Daten bleiben:** **Alles bleibt im Heimnetz:** Home Assistant und Energietracker sprechen direkt miteinander. Benachrichtigungen schickt die App erst, wenn jemand sie einschaltet, der die Installation verwaltet, und nur an dein Home Assistant. Der Wetterabgleich läuft wie auf jedem Weg.

**Was es nicht kann**

- Der Energietracker steuert nichts. evcc oder Home Assistant steuern Wallbox und Speicher, er rechnet nach.
- **Die Einheit muss passen:** Was Home Assistant liefert, muss zur Einheit des Zählers passen, sonst stimmen die Stände nicht.
- Heizöl und Pellets arbeiten mit Lieferungen und gehen nicht über diesen Eingang.

**Im Detail:** **Home Assistant schickt die Stände mit `POST /api/ingest` und einem Token (`et_…`).** Zurück liest es `/api/summary` und `/api/todo`; mit eingeschalteter Anmeldung braucht es dafür einen Lese-Schlüssel, der Token gilt dort nicht.

**Adressen und Ports**

- Home Assistant → Energietracker: `http://energietracker.local:8080/api.php/api/ingest`. Mit `api.php` geht es immer; mit einer Umschreibregel im Webserver auch ohne.
- Energietracker → Sensoren: `http://energietracker.local:8080/api/summary`. Die Integration fragt alle 15 Minuten und legt die Sensoren selbst an.
- App in Home Assistant, von außerhalb: `http://homeassistant.local:8095`. **Die Seitenleiste läuft über Ingress.** Für andere Programme in der App unter Konfiguration → Netzwerk einen Port freigeben, etwa `8095`.

**Anfang des REST-Befehls**

```text
rest_command:
  energietracker_push:
    url: "http://energietracker.local:8080/api.php/api/ingest"
    method: POST
    headers:
      Authorization: !secret energietracker_auth
      Content-Type: "application/json"
```

**Gut zu wissen**

- **`!secret` wirkt nur als ganzer Wert.** In `secrets.yaml` steht deshalb `"Bearer et_…"` samt `Bearer`.
- Die Integration „Model Context Protocol“ von Home Assistant geht nicht: Sie spricht nur SSE.
- Alles, was eine HTTP-Anfrage schicken kann, liefert über denselben Eingang, auch ioBroker, Node-RED und openHAB.

**Typische Fehler**

- `401` im Protokoll von Home Assistant: **Token falsch oder Kopfzeile fehlt.** `Authorization: !secret energietracker_auth` als ganzer Wert.
- `400`, kein Zähler gefunden: **Der Alias stimmt nicht mit dem Zähler überein.** In den Einstellungen prüfen.
- Wert kommt an, zählt aber nicht: **Er ist kleiner als der vorige Stand und deshalb als Verdacht markiert.** In der Ansicht der Verbrauchsart prüfen.

[Home Assistant anbinden](../anleitungen/home-assistant.md) · [Ladevorgänge aus evcc](../anleitungen/evcc.md) · [ioBroker, Node-RED, openHAB](../anleitungen/andere-systeme.md)

---

<a id="weg-local-ai"></a>

## Mit KI lokal

Du fragst in deinen Worten. Modell und Zahlen bleiben im Haus.

<picture><source media="(max-width: 600px)" srcset="../ui/wegweiser/local-ai.de.narrow.svg"><img src="../ui/wegweiser/local-ai.de.svg" alt="Schaubild: Mit KI lokal" width="760"></picture>

**Im Haus**

- Du → Open WebUI: du fragst
- Open WebUI → Du: Antwort
- Open WebUI → Ollama: Frage und Zahlen
- Ollama → Open WebUI: Text der Antwort
- Open WebUI → Energietracker: fragt nach: MCP mit Schlüssel
- Energietracker → Open WebUI: Zahlen mit Herkunft
- Du → Energietracker: Änderung in der App bestätigen

**Über die Hausgrenze**

- Modell-Download → Ollama: Modell laden, einmal beim Einrichten
- Energietracker → Wetterdienst: gerundeter Standort, abschaltbar

**Für wen:** Für alle, die ihre Zahlen in eigenen Worten abfragen möchten, ohne dass Fragen oder Zahlen das Haus verlassen. Du solltest Docker nicht scheuen oder jemanden haben, der beim Einrichten hilft.

**Was du bekommst**

- Antworten auf Fragen wie „Wie steht mein Gas-Saldo?“, mit denselben Zahlen wie in der App.
- **Zu jeder Zahl ihre Herkunft:** gemessen, eingetragen, verteilt, angenommen oder geschätzt.
- Fertige Aufträge für Monatsbericht, Jahresbericht, Rechnungsprüfung und Fristen.
- **Vorschläge statt Änderungen:** Die KI trägt nichts selbst ein, du bestätigst in der App.

[Was bringt dir das?](../anleitungen/ki-assistent.md#mehrwert) · [Wer bestätigt eine Änderung?](../anleitungen/ki-assistent.md#wer-bestaetigt)

**Was du brauchst**

- Software: Energietracker ab v3.12.0, Docker, darin Open WebUI ab 0.6.31, dazu Ollama mit einem Modell, das Werkzeuge benutzen kann. Geprüft ist genau diese Verbindung: Open WebUI mit lokalem Ollama. Andere lokale Programme sind experimentell.
- Hardware: Einen Rechner mit viel Arbeitsspeicher, am besten mit einer Grafikeinheit, die das Modell nutzen kann. Je kleiner der Rechner, desto länger dauert eine Antwort. Der Energietracker darf auf einem anderen Gerät im Heimnetz laufen.

**Aufwand:** **Mehr als eine Installation:** drei Programme, ein großer Download für das Modell und eine Verbindung, die du einträgst. Danach fragst du nur noch. Wie lange eine Antwort dauert, hängt von Modell und Rechner ab; die Anleitung nennt Messwerte.

**Schritte**

1. Der Energietracker läuft im Heimnetz. [Von Hand](#weg-by-hand)
2. **Ollama installieren und ein Modell holen, das Werkzeuge benutzen kann.** Die Anleitung zeigt die Installation an einem Bildmodell; nimm hier ein Sprachmodell mit der Fähigkeit `tools`. [Ollama einrichten](../anleitungen/texterkennung.md#ollama-einrichten)
3. Open WebUI im Container starten. [Open WebUI: Schnellstart](https://docs.openwebui.com/getting-started/quick-start/)
4. Unter Verbindungen die Karte „KI-Assistenten (MCP)“ öffnen, „KI-Schnittstelle einschalten“ wählen und einen Schlüssel „KI lesen“ anlegen. (in der App: **KI-Assistenten (MCP)**) · [KI-Assistent anbinden (MCP)](../anleitungen/ki-assistent.md#schritt-2--schlüssel-anlegen)
5. In Open WebUI als Verwalter den Energietracker als Werkzeugserver eintragen: Typ MCP (Streamable HTTP), Adresse und Schlüssel. [KI-Assistent anbinden (MCP)](../anleitungen/ki-assistent.md#open-webui)
6. Im Chat das Werkzeug einschalten und fragen. [Beispiele für Fragen](../anleitungen/ki-assistent.md#beispiele)

**Wo deine Daten bleiben:** **Frage, Zahlen und Antwort bleiben im Heimnetz: Ollama rechnet auf deinem Rechner.** Das gilt, solange du in Open WebUI kein Modell aus der Cloud und keine Websuche einträgst. Aus dem Internet kommen nur einmal die Programme und das Modell. Kennnummern und Originale bekommt die KI nur mit den Schaltern „Kennnummern an die KI geben“ und „Originale an die KI geben“; ab Werk sind beide aus. Der Wetterabgleich läuft wie auf jedem Weg.

**Was es nicht kann**

- **Die KI rechnet nicht, sie erzählt.** Dabei kann sie sich irren. Wichtige Zahlen prüfst du in der App.
- **Keine Werkzeuge für Einstellungen, Personen, Schlüssel, Sicherungen oder Löschen.** Das bleibt in der App.
- Langes Auslesen mit einem lokalen Modell und Berichte als PDF bleiben in der App.
- Programme, die nur SSE sprechen oder zwingend OAuth verlangen, verbinden sich nicht.
- Die öffentliche Demo hat keine KI-Schnittstelle, denn sie läuft ohne Server.

**Im Detail:** **Der Aufbau als Regel:** Open WebUI im Container, Ollama nativ auf dem Rechner, der Energietracker auf demselben Rechner oder dem NAS. Open WebUI ist die Drehscheibe: Es schickt deine Frage an Ollama und ruft selbst die Werkzeuge des Energietrackers auf. Das Modell spricht nie direkt mit dem Energietracker.

**Adressen und Ports**

- Browser → Open WebUI: `http://mein-rechner.local:3000`. Port 3000 des Rechners führt auf Port 8080 im Container.
- Open WebUI → Ollama: `http://host.docker.internal:11434`. **Im Container ist `localhost` der Container selbst.** Ohne eigene Angabe sucht Open WebUI Ollama unter dieser Adresse.
- Open WebUI → Energietracker auf demselben Rechner: `http://host.docker.internal:8080/api/mcp`. **Diese Adresse trägst du in Open WebUI ein.** Sie nimmt nur `POST` mit Schlüssel an, ein Test im Browser hilft nicht.
- Open WebUI → Energietracker auf einem anderen Gerät mit Docker: `http://energietracker.local:8080/api/mcp`. Mit der Adresse dieses Geräts im Heimnetz.
- Open WebUI → Energietracker auf Apache oder Synology Web Station: `http://energietracker.local/energietracker/api.php/api/mcp`. **Hier gehört `api.php` in die Adresse.** Die Karte „KI-Assistenten (MCP)“ nennt immer die Adresse mit `api.php`; sie geht mit jedem Webserver.

**Vorlage nach den Anleitungen der Hersteller**

```text
services:
  energietracker:
    image: ghcr.io/bingerminger/energietracker:3
    restart: unless-stopped
    ports: ["8080:80"]
    volumes: ["./data:/data"]
  open-webui:
    image: ghcr.io/open-webui/open-webui:main
    restart: always
    ports: ["3000:8080"]
    environment:
      WEBUI_SECRET_KEY: "<openssl rand -hex 32>"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes: ["open-webui:/app/backend/data"]
volumes:
  open-webui:
```

**Gut zu wissen**

- Auf dem Mac läuft Ollama als eigenes Programm neben Docker, nicht im Container: Docker Desktop reicht die Grafikeinheit nicht durch. So steht es bei Ollama. Unter Linux mit Nvidia-Karte geht auch der Container `ollama/ollama`.
- **`host.docker.internal` zeigt unter Docker Desktop (Mac, Windows) auf den Rechner.** Unter Linux gibt es den Namen nur mit `extra_hosts` wie in der Datei oben.
- **Schlüssel:** „KI lesen“ darf alle lesenden Werkzeuge, Prompts und Ressourcen. „KI lesen und vorschlagen“ darf dazu Dokumente auslesen und Änderungen vorschlagen. Fang mit „KI lesen“ an. Den Schlüssel (`etk_…`) zeigt die App nur einmal; andere Schlüssel gelten hier nicht.
- **„Übernahme nur in der App bestätigen“ ist ab Werk an: Auch wenn die KI übernehmen will, schreibt sie nichts.** Du bestätigst den Vorschlag unter „Zu tun“.
- **Die Schnittstelle ist ab Werk aus.** `ET_MCP=off` sperrt sie fest, auch wenn jemand den Schalter umlegt.

**Typische Fehler**

- `401`: **Kein oder ein falscher Schlüssel.** Widerrufene Schlüssel neu anlegen.
- `403`, die Schnittstelle ist aus: Der Schalter „KI-Schnittstelle einschalten“ ist aus, oder `ET_MCP=off` ist gesetzt.
- `403` mit `insufficient_scope`: Der Schlüssel hat keinen KI-Bereich, oder ein Werkzeug zum Vorschlagen trifft auf „KI lesen“.
- `404`: **Adresse falsch.** Auf Apache und Synology gehört `api.php` hinein.
- `421`: Du hast eine Hostliste gesetzt (`ET_ALLOWED_HOSTS` oder unter Einstellungen → Zugang und Sicherheit), und `host.docker.internal` steht nicht darauf.
- Open WebUI zeigt keine Modelle: **Läuft Ollama?** Kommt der Container nicht durch, `OLLAMA_HOST=0.0.0.0` setzen (auf dem Mac mit `launchctl setenv`) und Ollama neu starten. Ollama kennt keine Anmeldung: Gib den Port nicht ins Internet frei.
- Keine Werkzeuge im Chat: **Seite neu laden und das Werkzeug im Chat einschalten.** Das Modell muss die Fähigkeit `tools` haben.

[KI-Assistent anbinden (MCP)](../anleitungen/ki-assistent.md) · [Open WebUI: MCP](https://docs.openwebui.com/features/extensibility/mcp) · [Ollama: Docker und Grafikeinheit](https://ollama.com/blog/ollama-is-now-available-as-an-official-docker-image)

---

<a id="weg-cloud-ai"></a>

## Mit KI in der Cloud (experimentell)

Das Modell läuft beim Anbieter. Was es liest, verlässt dein Haus.

<picture><source media="(max-width: 600px)" srcset="../ui/wegweiser/cloud-ai.de.narrow.svg"><img src="../ui/wegweiser/cloud-ai.de.svg" alt="Schaubild: Mit KI in der Cloud (experimentell)" width="760"></picture>

**Im Haus**

- Du → KI-Programm: du fragst
- KI-Programm → Du: Antwort
- KI-Programm → Energietracker: fragt nach: MCP mit Schlüssel
- Energietracker → KI-Programm: Zahlen mit Herkunft
- Du → Energietracker: Änderung in der App bestätigen

**Über die Hausgrenze**

- KI-Programm → Anbieter des Modells: Frage und Antworten der Werkzeuge
- Anbieter des Modells → KI-Programm: Text der Antwort
- Energietracker → Wetterdienst: gerundeter Standort, abschaltbar

**Für wen:** Für alle, die keinen Rechner für ein lokales Modell haben und bewusst entscheiden, dass Zahlen aus dem Haus gehen dürfen.

**Was du bekommst**

- Dieselben Zahlen mit Herkunft wie auf dem lokalen Weg.
- Kein eigener Rechner für das Modell.
- „Übernahme nur in der App bestätigen“ bleibt an: Ändern kann die KI nichts ohne dich.

[Wer bestätigt eine Änderung?](../anleitungen/ki-assistent.md#wer-bestaetigt)

**Was du brauchst**

- Software: Energietracker ab v3.12.0, ein KI-Programm mit MCP über HTTP, das einen Schlüssel senden kann, und ein Konto beim Anbieter des Modells. Alle diese Wege sind experimentell: Sie folgen den Anleitungen der Hersteller und sind nicht von uns geprüft.
- Hardware: Ein gewöhnlicher Rechner reicht, das Modell rechnet beim Anbieter.

**Aufwand:** **Ein Programm einrichten und den Schlüssel eintragen.** Dazu kommen Kosten beim Anbieter, nach dessen Preisen.

**Schritte**

1. Der Energietracker läuft im Heimnetz. [Von Hand](#weg-by-hand)
2. Ein Programm wählen, das auf deinem Rechner läuft und MCP über HTTP spricht. [Programme](../anleitungen/ki-assistent.md#programme)
3. Unter Verbindungen die Karte „KI-Assistenten (MCP)“ öffnen, „KI-Schnittstelle einschalten“ wählen und einen Schlüssel „KI lesen“ anlegen. (in der App: **KI-Assistenten (MCP)**) · [KI-Assistent anbinden (MCP)](../anleitungen/ki-assistent.md#schritt-2--schlüssel-anlegen)
4. Das Programm verbinden, wie es die Anleitung für dein Programm zeigt. [KI-Assistent anbinden (MCP)](../anleitungen/ki-assistent.md#andere-programme-experimentell)
5. Lesen, was hinausgeht, und die Bedingungen des Anbieters prüfen. [Wo deine Daten bleiben](../anleitungen/ki-assistent.md#sicherheit-und-datenschutz)

**Wo deine Daten bleiben:** Was die KI liest, sieht der Anbieter des Modells: deine Fragen und die Antworten der Werkzeuge, also Zahlen zu Verbrauch, Kosten und Verträgen. Nie hinaus gehen Passwörter und Schlüssel, Einstellungen mit Netzadressen, die Personenliste, Sicherungen und Protokolle. Kennnummern und Originale nur mit den Schaltern „Kennnummern an die KI geben“ und „Originale an die KI geben“; ab Werk sind beide aus. Der Wetterabgleich läuft wie auf jedem Weg.

**Was es nicht kann**

- **Programme, die aus der Cloud ihres Anbieters verbinden, erreichen dein Heimnetz nicht.** Das geht nur per Anleitung mit eigenem HTTPS-Zugang, und du stellst damit einen Zugang ins Internet.
- **Kein OAuth:** Programme, die zwingend eine Anmeldung über OAuth verlangen, verbinden sich nicht.
- **Keine Werkzeuge für Einstellungen, Personen, Schlüssel, Sicherungen oder Löschen.** Das bleibt in der App.
- **Experimentell heißt:** Rückmeldungen sind willkommen, und es kann sich ändern.

**Im Detail:** Beispiele für solche Programme, alle experimentell: Claude Code, VS Code (Copilot-Chat) und Cursor auf deinem Rechner. Das Programm spricht im Heimnetz mit dem Energietracker und schickt, was es liest, an sein Modell.

**Adressen und Ports**

- KI-Programm auf deinem Rechner → Energietracker: `http://energietracker.local:8080/api/mcp`. Auf Apache und Synology gehört `api.php` in die Adresse.
- Dienst aus der Cloud → eigener HTTPS-Zugang: `https://energie.example.org/api/mcp`. **Nur wenn der Dienst eine eigene Kopfzeile mit dem Schlüssel erlaubt.** Der Reverse-Proxy lässt nur `/api/mcp` durch. Das ist eine Anleitung, keine Empfehlung.

**Gut zu wissen**

- **Einen eigenen Schlüssel je Programm und Gerät anlegen.** Fang mit „KI lesen“ an, „KI lesen und vorschlagen“ darf dazu vorschlagen.
- Ein Modell aus der Cloud in Open WebUI ist ebenfalls experimentell: Die Daten gehen dann zum Anbieter.
- **Schlüssel widerrufen, sobald du den Zugang nicht mehr brauchst.** Ratengrenzen und die Sperre nach falschen Schlüsseln gelten auch hier.

**Typische Fehler**

- `401`: **Kein oder ein falscher Schlüssel.** Manche Programme suchen danach eine OAuth-Anmeldung und melden dazu einen Fehler; Ursache ist trotzdem der Schlüssel.
- `403`, die Schnittstelle ist aus: Der Schalter „KI-Schnittstelle einschalten“ ist aus, oder `ET_MCP=off` ist gesetzt.
- `404`: **Adresse falsch.** Auf Apache und Synology gehört `api.php` hinein.

[Aus der Cloud: eigener HTTPS-Zugang](../anleitungen/ki-assistent.md#aus-der-cloud-nur-mit-eigenem-https-zugang) · [KI-Assistent anbinden (MCP)](../anleitungen/ki-assistent.md)

---

<a id="weg-together"></a>

## Alles zusammen

Die Bausteine ergänzen sich, und keiner hängt vom anderen ab.

<picture><source media="(max-width: 600px)" srcset="../ui/wegweiser/together.de.narrow.svg"><img src="../ui/wegweiser/together.de.svg" alt="Schaubild: Alles zusammen" width="760"></picture>

**Im Haus**

- Home Assistant → Energietracker: Stände, täglich oder je Viertelstunde
- Energietracker → Home Assistant: Sensoren, Hinweise, Termine
- Versorgerpost → Energietracker: PDF ablegen
- KI im Haus → Energietracker: fragt nach: MCP mit Schlüssel
- Energietracker → KI im Haus: Zahlen mit Herkunft
- Du → Energietracker: Änderung in der App bestätigen
- Du → KI im Haus: du fragst

**Über die Hausgrenze**

- Energietracker → Cloud-Dienst: nur, was du ausdrücklich einschaltest
- Energietracker → Wetterdienst: gerundeter Standort, abschaltbar

**Für wen:** Für alle, die mehrere Wege verbinden wollen. Du musst nichts auf einmal einrichten; jeder Baustein läuft für sich, und die Reihenfolge ist egal.

**Was du bekommst**

- Home Assistant liefert die Stände, die Post liefert Verträge und Rechnungen.
- Die App prüft die Rechnung aus der Post gegen deine Stände.
- Die KI fragt alles an einer Stelle ab und nennt zu jeder Zahl die Herkunft.
- Von Hand bleibt immer möglich, etwa für den Zähler ohne Sensor.

**Was du brauchst**

- Software: Was die einzelnen Wege brauchen, mehr nicht.
- Hardware: **Ein Rechner kann vieles tragen:** Open WebUI im Container, Ollama nativ, der Energietracker auf demselben Rechner oder dem NAS.

**Aufwand:** Die Summe der Bausteine, aber Schritt für Schritt: Du fängst mit einem an und nimmst den nächsten dazu, wenn du ihn brauchst.

**Schritte**

1. Der Energietracker läuft. [Von Hand](#weg-by-hand)
2. Stände aus Home Assistant dazunehmen. [Mit Home Assistant](#weg-home-assistant)
3. Die Post in den Posteingang legen. [Mit Versorgerpost](#weg-inbox)
4. Eine KI anbinden, lokal oder in der Cloud. [Mit KI lokal](#weg-local-ai) · [Mit KI in der Cloud (experimentell)](#weg-cloud-ai)

**Wo deine Daten bleiben:** **Drinnen bleibt, was du nicht ausdrücklich hinausschickst.** Hinaus gehen können nur drei Dinge: der Wetterabgleich (gerundeter Standort, abschaltbar), Dokumente an einen Cloud-Dienst (mit Rückfrage) und was eine KI in der Cloud liest (nur, wenn du sie anbindest).

**Was es nicht kann**

- Mehr Bausteine heißen mehr Programme, die du aktuell halten musst.
- Der Energietracker steuert nichts, auch nicht zusammen mit den anderen.
- **Eine KI ersetzt keine Prüfung:** Was sie vorschlägt, bestätigst du, und wichtige Zahlen siehst du in der App nach.

**Im Detail:** **Jeder Baustein hat seine Adresse.** Die Platzhalter ersetzt du durch deine.

**Adressen und Ports**

- Energietracker: `http://energietracker.local:8080`. Im Docker-Image; auf Apache und Synology ohne Port, im Ordner der App.
- Open WebUI: `http://mein-rechner.local:3000`. Der Chat im Browser.
- Ollama: `http://mein-rechner.local:11434`. **Kennt keine Anmeldung:** Gib den Port nicht ins Internet frei.
- Home Assistant: `http://homeassistant.local:8123`. Aus einem Container löst sich ein Name mit `.local` oft nicht auf; dann die Adresse im Heimnetz eintragen.

**Gut zu wissen**

- **Jeder Zugang hat seinen eigenen Schlüssel:** der Token (`et_…`) für Stände aus Home Assistant, ein Lese-Schlüssel für die Sensoren, ein KI-Schlüssel je KI-Programm. Keiner gilt für alles.
- Sobald mehrere Geräte zugreifen, schalte die Anmeldung ein: Einstellungen → Zugang und Sicherheit.
- Die Anwendungsfälle zeigen fertige Kombinationen, etwa Wallbox und Wärmepumpe.

[Anwendungsfälle](../anleitungen/anwendungsfaelle.md) · [Home Assistant anbinden](../anleitungen/home-assistant.md) · [KI-Assistent anbinden (MCP)](../anleitungen/ki-assistent.md)

---

[← Kompendium-Index](../README.md)
