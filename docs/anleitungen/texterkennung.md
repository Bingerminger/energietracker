# Texterkennung im Heimnetz

**Deutsch** · [English](../en/anleitungen/texterkennung.md)

[← Kompendium-Index](../README.md)

Seit v3.1.0 kann der Energietracker den Zählerstand aus einem Foto vorschlagen.
Dafür schickt er das Foto an ein Bildmodell (Vision-Modell), das du selbst im
Heimnetz betreibst — mit Ollama, LM Studio oder einem anderen
OpenAI-kompatiblen Server. Die Texterkennung ist optional und zunächst
ausgeschaltet. Ohne sie bleibt alles, wie es ist: Foto als Beleg, Stand von Hand oder am
iPhone mit Live Text („Text scannen“ im Feld).

---

## Wozu

- **Ablesen ohne Abtippen:** Foto aufnehmen, Vorschlag prüfen, „Übernehmen“.
- **Beleg:** Das Foto bleibt am Stand. Wer später zweifelt, sieht nach.
- **Du entscheidest:** Gespeichert wird nie ohne deinen Klick. Danach prüft die
  App den Wert wie jeden anderen
  ([Plausibilität](../verstehen/11-zaehlerstaende.md)).

---

## Datenschutz: nur im eigenen Netz

- Das Foto geht an **deinen** Dienst, nicht an einen Cloud-Anbieter. Als
  Adresse nimmt die App nur Ziele im eigenen Netz an: Loopback, private Netze
  (`10.x`, `172.16.x`–`172.31.x`, `192.168.x`), Link-local, `100.64.0.0/10`
  (Tailscale) und die entsprechenden IPv6-Bereiche. Eine öffentliche Adresse
  lehnt sie ab. Einen Schalter, der das aufhebt, gibt es nicht.
- Geprüft wird bei **jedem** Aufruf, nach der Namensauflösung, und verbunden
  wird mit genau der geprüften Adresse. Ein Name, der plötzlich ins Internet
  zeigt (DNS-Rebinding), kommt so nicht durch. Weiterleitungen folgt die App
  nicht.
- **Warum über den Server?** Die Anfrage an den Dienst stellt der Server des
  Energietrackers, nicht der Browser. Die Sicherheitsregeln der App
  (Content-Security-Policy) erlauben dem Browser nur Verbindungen zur eigenen
  Seite — das bleibt auch mit Texterkennung so. Und das Foto liegt als Beleg
  ohnehin auf dem Server.
- Ein Foto aus der App ist schon im Browser verkleinert (höchstens
  1600 Pixel) und ohne EXIF-Daten — also ohne GPS-Ort und Kamerainformationen.
- Ohne eingetragene Adresse baut die App keine Verbindung auf. Der
  Datenschutztext in der Hilfe der App nennt den eigenen Texterkennungsdienst
  neben Open-Meteo als einziges Ziel nach außen — und nur, wenn du ihn
  einträgst.

---

## Was du brauchst

- Einen Rechner im Heimnetz, auf dem der Dienst läuft: ein PC, ein Mac oder
  ein NAS. Mit Grafikkarte (oder auf einem Mac mit Apple-Chip) antworten
  Bildmodelle deutlich schneller als auf einem NAS ohne Grafikkarte; Speicher
  brauchen sie mehrere Gigabyte.
- Ein **Bildmodell**. Reine Sprachmodelle können keine Bilder lesen.
- Auf dem Server des Energietrackers die PHP-Erweiterung curl. Die
  Texterkennung kommt ohne sie nicht aus (anders als der Wetterabgleich); im
  Docker-Image ist sie enthalten.

---

## Ollama einrichten

1. **Installieren:** [ollama.com/download](https://ollama.com/download) — für
   macOS, Windows und Linux; als Container gibt es das Image `ollama/ollama`.
2. **Ein Bildmodell holen:**

   ```sh
   ollama pull qwen2.5vl
   ```

   Andere Bildmodelle sind etwa `llama3.2-vision` oder `minicpm-v`. Welches
   deine Zähler am besten liest, hängt vom Zähler und vom Foto ab — probier es
   aus.
3. **Im Netz erreichbar machen.** Ollama hört von Haus aus nur auf
   `127.0.0.1`, also nur auf Anfragen vom selben Rechner. Damit der
   Energietracker es aus einem Container oder von einem NAS erreicht, muss es
   auf allen Schnittstellen hören: Umgebungsvariable `OLLAMA_HOST=0.0.0.0`
   setzen und Ollama neu starten. Wie das je Betriebssystem geht, steht in der
   [Ollama-FAQ](https://github.com/ollama/ollama/blob/main/docs/faq.md)
   („How do I configure Ollama server?“). Im Container-Image `ollama/ollama`
   ist das schon so eingestellt.
   Ollama kennt keine Anmeldung: Jeder im Heimnetz kann das Modell dann
   nutzen. Gib den Port nicht ins Internet frei.
4. **Prüfen**, am besten vom Server des Energietrackers aus:

   ```sh
   curl http://192.168.178.20:11434/api/tags
   ```

   Die Antwort listet die geholten Modelle mit ihren Namen.
5. **In der App:** Adresse `http://192.168.178.20:11434`, Schnittstelle
   **Ollama**, Modell `qwen2.5vl` (s. [Einstellungen](#einstellungen)).

Laufen Ollama und der Energietracker auf demselben Rechner und der
Energietracker **nicht** in Docker, genügt `http://127.0.0.1:11434` — Schritt 3
entfällt dann.

---

## LM Studio einrichten

1. [LM Studio](https://lmstudio.ai) installieren und ein Bildmodell laden — in
   der Modellsuche als „Vision“ gekennzeichnet, etwa Qwen2.5-VL.
2. **Server starten:** im Bereich **Developer** den Server einschalten
   (Port 1234) und **„Serve on Local Network“** aktivieren. Sonst nimmt er nur
   Anfragen vom selben Rechner an.
3. **Prüfen:**

   ```sh
   curl http://192.168.178.20:1234/v1/models
   ```

4. **In der App:** Adresse `http://192.168.178.20:1234/v1`, Schnittstelle
   **„OpenAI-kompatibel (LM Studio, LocalAI)“**, Modell = der Name, den
   `/v1/models` nennt.

Andere OpenAI-kompatible Server wie LocalAI gehen genauso: Schnittstelle
„OpenAI-kompatibel“, Adresse bis einschließlich `/v1`. Das Modell muss Bilder
annehmen; die App schickt das Foto als Data-URI.

---

## Wenn der Energietracker in Docker läuft

Im Container ist `localhost` (und `127.0.0.1`) der Container selbst, nicht der
Rechner, auf dem er läuft. Deshalb:

- Die **Adresse des Rechners im Heimnetz** eintragen, etwa
  `http://192.168.178.20:11434` — und Ollama mit `OLLAMA_HOST=0.0.0.0`
  starten.
- `http://host.docker.internal:11434` zeigt nur unter **Docker Desktop** (Mac,
  Windows) auf den Rechner. Unter Linux gibt es den Namen nur, wenn die
  Compose-Datei ihn anlegt: `extra_hosts: ["host.docker.internal:host-gateway"]`.
- Läuft Ollama als eigener Container in derselben Compose-Datei, genügt der
  Name des Dienstes, etwa `http://ollama:11434`.

Alle drei Wege führen auf Adressen im eigenen Netz und bestehen die Prüfung.

---

## Einstellungen

**Einstellungen → Experte → 🔎 Texterkennung im Heimnetz** (oben auf der Seite,
vor den eingeklappten Rechenparametern):

| Feld | Schlüssel | Eintrag |
|---|---|---|
| Adresse des Dienstes | `ocr_endpoint` | Basisadresse mit `http://` oder `https://`; den Pfad (`/api/chat` bzw. `/v1/chat/completions`) hängt die App an. Leer = aus |
| Schnittstelle | `ocr_api` | **Ollama** oder **OpenAI-kompatibel (LM Studio, LocalAI)** |
| Modell | `ocr_model` | Name wie im Dienst, etwa `qwen2.5vl` |
| Zeitlimit | `ocr_timeout_s` | Sekunden, die der Server wartet; Standard 30 (5–300) — im Docker-Image voll nutzbar ([Grenzen](#grenzen)) |

Speichern. Beim nächsten Öffnen von „Zählerstände“ ist die Texterkennung
aktiv. Alle Schlüssel:
[Einstellungen → Texterkennung im Heimnetz](../referenz/einstellungen.md#texterkennung-im-heimnetz-v310).

---

## So läuft es in der App

1. **Zählerstände** öffnen und an der Karte des Zählers **„📷 Foto“** tippen. Am
   Handy öffnet sich die Kamera.
2. Das Foto wird verkleinert, hochgeladen und an den Dienst geschickt. Die
   Karte zeigt „Foto wird hochgeladen …“, dann „Texterkennung läuft …“.
3. Es erscheint „Erkannt: 12.345,6“ mit **„Übernehmen“**. Ein Tipp setzt den
   Wert ins Feld — prüfen und bei Bedarf korrigieren.
4. **„Alle speichern“.** Ist der Wert ungewöhnlich, fragt die
   Plausibilitätsprüfung nach. Das Foto wird mit dem Stand gespeichert.

Erkennt das Modell nichts, steht „Kein Zählerstand erkannt – bitte von Hand
eintragen.“ da; das Foto bleibt als Beleg. Ohne Verbindung entfällt der
Vorschlag, das Foto geht mit dem Stand in die Warteschlange; „Bearbeiten“ holt
Stand und Foto von dort zurück in die Karte
([Auf dem Handy nutzen](../einstieg/handy.md#die-warteschlange-noch-nicht-gespeichert)).

**Gute Fotos:** gerade von vorn, scharf, ohne Spiegelung; das Zählwerk füllt
einen großen Teil des Bildes. Andere Zahlen daneben (Zählernummer, Baujahr)
können ein Modell ablenken — dann näher herangehen.

Die App schickt dem Modell eine feste Anweisung auf Englisch: nur die Ziffern
des Zählwerks lesen, Nachkommastellen wie auf dem Rollenzählwerk, Antwort als
JSON mit Wert und Selbsteinschätzung. Technisch:
[API-Referenz → `POST /api/ocr/reading`](../referenz/api.md#post-apiocrreading).

---

## Wenn etwas nicht klappt

| Meldung | Ursache und Lösung |
|---|---|
| „Die Texterkennung muss im eigenen Netz laufen – „…“ ist keine Adresse im Heimnetz.“ | Die Adresse — oder eine der Adressen, auf die der Name zeigt — liegt nicht im eigenen Netz. Die IP-Adresse des Rechners im Heimnetz eintragen. Ein Cloud-Dienst geht bewusst nicht. |
| „Die Texterkennung hat nicht innerhalb von 30 Sekunden geantwortet.“ | Das Modell ist zu langsam für das Zeitlimit, besonders beim ersten Aufruf nach einer Pause, wenn der Dienst das Modell erst in den Speicher lädt. Zeitlimit erhöhen, ein kleineres Modell nehmen oder den Dienst auf einem schnelleren Rechner betreiben. |
| „Die Texterkennung ist nicht erreichbar.“ | Der Dienst läuft nicht, hört nur auf `127.0.0.1` (`OLLAMA_HOST`, „Serve on Local Network“), eine Firewall sperrt den Port, im Container steht `localhost` ([Docker](#wenn-der-energietracker-in-docker-läuft)) oder der Name lässt sich nicht auflösen. Ebenso, wenn der Dienst mit einem Fehler antwortet — etwa weil es das Modell unter diesem Namen nicht gibt (`ollama list` zeigt die Namen) oder die Schnittstelle nicht passt — oder dem Server fehlt die PHP-Erweiterung curl. Erste Probe: das `curl` von oben, vom Server des Energietrackers aus. |
| „Die Texterkennung hat keine verwertbare Antwort geliefert.“ | Der Dienst hat geantwortet, aber ohne Text — etwa ein Modell, das mit dem Bild nichts anfangen kann, oder unter der Adresse antwortet ein anderer Dienst. |
| „Kein Zählerstand erkannt – bitte von Hand eintragen.“ | Das Modell hat geantwortet, aber keine Zahl gefunden. Näher, gerader und schärfer fotografieren oder ein anderes Modell probieren. |
| Der Vorschlag ist falsch oder Unsinn | Ein Modell **ohne Bildfähigkeit** weist das Bild ab (dann „nicht erreichbar“) oder antwortet, ohne es zu sehen — mit einem erfundenen oder ohne Wert. Ein Bildmodell eintragen, etwa `qwen2.5vl`, `llama3.2-vision` oder `minicpm-v`. Fehlen oder stören Nachkommastellen, den Wert vor dem Speichern korrigieren: Gespeichert wird nur, was im Feld steht. |

---

## Grenzen

- **Dauer.** Auf einem NAS ohne Grafikkarte brauchen Bildmodelle oft 20 bis
  60 Sekunden je Foto; ein Rechner mit Grafikkarte ist deutlich schneller.
- **Trefferquote.** Sie hängt am Modell und am Foto. Die App verspricht keine
  — sie schlägt vor, du entscheidest.
- **Zeitlimit hinter dem Webserver.** Im Docker-Image wartet nginx seit v3.1.0
  bis zu 310 Sekunden auf eine Antwort von PHP (`fastcgi_read_timeout`, vorher
  120 s) — das höchste Zeitlimit von 300 Sekunden läuft also durch. Hinter
  einem eigenen Webserver oder Reverse-Proxy gilt dessen Grenze; für lange
  Zeitlimits dort ebenfalls über 300 Sekunden einstellen
  ([Webserver](../betrieb/webserver.md)).
- **Nur mit Verbindung.** Ohne Netz gibt es keinen Vorschlag.
- **Fotos nur in „Zählerstände“.** Die Ablesetabelle einer Verbrauchsart zeigt
  ein Foto und entfernt es, nimmt aber keines auf.

---

[← Kompendium-Index](../README.md) · [Auf dem Handy nutzen](../einstieg/handy.md)
