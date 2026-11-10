# KI-Assistent anbinden (MCP)

**Deutsch** · [English](../en/anleitungen/ki-assistent.md)

> **Ziel:** Du fragst deine KI „Wie steht mein Gas-Saldo?“ oder „Was steht in
> den nächsten Wochen an?“, und sie schlägt im Energietracker nach. Sie nennt
> zu jeder Zahl, woher sie kommt. Ändern kann sie nur als Vorschlag: Ab Werk
> trägst du ihn selbst in der App ein ([Wer bestätigt eine
> Änderung?](#wer-bestätigt-eine-änderung)).

Seit **v3.12.0** spricht der Energietracker das **Model Context Protocol
(MCP)**, die gemeinsame Sprache, mit der KI-Programme Werkzeuge benutzen. Die
KI ist dabei die Oberfläche, der Energietracker die Akte dahinter: Er rechnet,
die KI erzählt. Sie bekommt dieselben Zahlen wie die Ansichten der App, jede
mit ihrer [Herkunft](../verstehen/18-herkunft.md) (gemessen, eingetragen,
verteilt, angenommen, geschätzt).

Die Schnittstelle ist **ab Werk aus**. Ein Verwalter schaltet sie ein und
legt für jedes KI-Programm einen eigenen Schlüssel an.

---

<a id="mehrwert"></a>

## Was bringt dir das?

MCP ist ein offener Standard, über den ein KI-Programm selbst erkennt, welche
Werkzeuge ein Dienst anbietet. Du fragst in normaler Sprache, die KI holt die
Antwort aus deinem Energietracker, gerechnet wird weiter dort.

Sechs Beispiele. **Alle Antworten sind erfunden**, die Werkzeuge gibt es wirklich.

**1. „Stimmt meine Gasrechnung?“** Werkzeuge: Posteingang, `get_document`,
`check_bill`.
> *Erfundene Antwort:* „Die Menge passt, der Betrag liegt 14,20 € über der
> eigenen Rechnung: Der Grundpreis auf der Rechnung ist höher als im Vertrag.
> Das ist ein Hinweis, keine Rechtsberatung.“

Den Brief dazu erstellst du in der App.

**2. „Warum ist mein Saldo schlechter als letztes Jahr?“** Werkzeuge:
`get_overview`, `get_consumption`, `get_contracts`. Dafür gibt es keine eigene
Ansicht. Die KI legt die Zahlen nebeneinander und trennt Gemessenes und
Geschätztes.
> *Erfundene Antwort:* „Gemessen 9 % mehr Verbrauch bis zur letzten Ablesung,
> dazu ein höherer Arbeitspreis seit April. Der Rest bis Dezember ist geschätzt,
> Spanne 40 bis 95 € Nachzahlung.“

**3. „Was bringt mir 1 Grad weniger, und was ein neuer Tarif?“** Werkzeug:
`run_scenario`, gegen den Ausgangsfall gerechnet.
> *Erfundene Antwort:* „Gegen den Ausgangsfall: 1 Grad weniger spart geschätzt
> 110 € im Jahr, der Tarif aus deinem Angebot 65 €, beides zusammen 170 €.“

**4. „Was steht diesen Monat an?“** Werkzeug: `get_deadlines`, dazu der Prompt
`monthly_report`.
> *Erfundene Antwort:* „Kündigungsstichtag Strom am 30.11., Ablesung Wasser
> fällig, Anna kümmert sich um die Wartung am 18.11.“

**5. „Gasstand 13.200“** Werkzeug: `propose_reading`. Es prüft mit denselben
Rückfragen wie das Formular; bestätigt wird in der App.
> *Erfundene Antwort:* „Vorschlag angelegt: Gas, Stand 13.200 m³ von heute, 96 m³
> seit der letzten Ablesung, keine Auffälligkeit. Noch nichts geändert: Bestätige
> ihn in der App.“

**6. Mehrere Dienste zusammen.** Das hängt vom KI-Programm und von weiteren
MCP-Servern ab; **der Energietracker allein kann das nicht**. Mit einer Tarifsuche
im Web prüft `run_scenario` ein gefundenes Angebot gegen deinen Verbrauch. Über
einen Kalender-Server werden Fristen aus `get_deadlines` zu Terminen. Mit Home
Assistant lässt sich der Grundverbrauch neben deine Zählerstände legen.

**Kopierter Text gegen MCP:**

| | Text in einen Chat kopieren | Über MCP |
|---|---|---|
| Aktualität | Stand des Kopierens | bei jeder Frage neu aus deinen Daten |
| Herkunft je Zahl | geht verloren | gemessen, eingetragen, verteilt, angenommen oder geschätzt steht an jeder Zahl |
| Wer rechnet | das Sprachmodell | der Energietracker, die KI erzählt |
| Ändern | von Hand | nur als Vorschlag, den du bestätigst |

Anders als bei der [Programmierschnittstelle](../referenz/api.md) programmierst du
nichts: Du trägst eine Vorlage in dein KI-Programm ein.

**Die Grenzen:**

- Die KI kann richtige Zahlen falsch deuten. Der Energietracker liefert jede
  Zahl mit Herkunft und jeden Saldo mit einem fertigen Satz, aber was das Modell
  daraus macht, entscheidet er nicht.
- Was sie liest, geht an ihr Sprachmodell. Lokal mit Ollama über Open WebUI oder
  mit LM Studio bleibt es im Haus, in der Cloud geht es an den Anbieter.
- Wer keine KI nutzt, hat keinen Nachteil: Die Schnittstelle ist ab Werk aus.

<a id="programme"></a>

## Was du brauchst

- Energietracker ab v3.12.0, erreichbar im Heimnetz (Docker, Webserver oder
  die App für Home Assistant).
- Ein KI-Programm mit MCP über HTTP („Streamable HTTP“), das eine
  Kopfzeile mit einem Schlüssel senden kann.

<!-- doc-claim: mcp-tested-with -->
**Geprüft mit Open WebUI 0.12.4 und einem lokalen Modell über Ollama 0.40.14
(November 2026).** Claude Code, VS Code, Cursor, LM Studio und
Cloud-Modelle sind **experimentell**: Die Vorlagen folgen den Anleitungen der
Hersteller, wir haben sie nicht selbst geprüft. Rückmeldungen sind willkommen, in
den [Discussions](https://github.com/Bingerminger/energietracker/discussions) oder
den [Issues](https://github.com/Bingerminger/energietracker/issues).

| Programm | Stand | Wo es läuft | Hinweis |
|---|---|---|---|
| Open WebUI ab 0.6.31 | **empfohlen** | auf einem Server im Heimnetz, mit Ollama | alles bleibt im Heimnetz; nur Verwalter tragen Werkzeugserver ein |
| Claude Code | experimentell | auf deinem Rechner | Prompts als Befehle, Ressourcen zum Anhängen |
| VS Code (Copilot-Chat) | experimentell | auf deinem Rechner | Schlüssel als verdeckte Eingabe |
| Cursor | experimentell | auf deinem Rechner | |
| LM Studio | experimentell | auf deinem Rechner, mit einem lokalen Modell | alles bleibt im Heimnetz |

Ein **Modell in der Cloud** ist ebenfalls experimentell: Die Daten gehen dann zum
Anbieter, und dort können Kosten entstehen.

Programme, die aus der **Cloud** ihres Anbieters verbinden (Connectoren im
Browser oder in Desktop-Apps), erreichen dein Heimnetz nicht. Dazu unten
[Aus der Cloud](#aus-der-cloud-nur-mit-eigenem-https-zugang).

## Schritt 1 — Einschalten

**Verbindungen → KI-Assistenten**, Schalter **KI-Schnittstelle
einschalten** (nur Verwalter, Einstellung `mcp_enabled`). Solange er aus ist,
antwortet die Schnittstelle mit `403` und dem Hinweis, wo man einschaltet.

Wer die Schnittstelle auf einem Server nie will, setzt die Umgebungsvariable
`ET_MCP=off` ([Docker](../betrieb/docker.md#konfiguration-über-umgebungsvariablen)).
Dann bleibt sie zu, auch wenn jemand den Schalter umlegt.

Drei weitere Schalter auf derselben Karte:

- **Übernahme nur in der App bestätigen** (`mcp_apply_in_app`), ab Werk an:
  [Wer bestätigt eine Änderung?](#wer-bestätigt-eine-änderung)
- **Kennnummern an die KI geben** (`mcp_share_identifiers`), ab Werk aus:
  Zählernummer, Markt- und Messlokation, Kunden-, Vertrags- und Rechnungsnummer.
  Ohne den Schalter ersetzt die App **Nummern, die sie kennt**, überall, wo die KI Text
  bekommt: Felder, Zitate, Dateinamen, Anbieter, Tarif, Notizen, Namen. Ein Zitat fällt
  weg, wenn danach noch eine lange Nummer oder eine Nummer hinter einer Bezeichnung
  („Kunden-Nr.“, „Kassenzeichen“) darin steht. Im Dateinamen wird jede Ziffernfolge ab
  fünf Stellen ersetzt, die kein Tag ist.

  **Die Grenzen:**

  - **Anbieter und Tarif** gelten nicht als Kennnummer und gehen an die KI.
  - Eine **Nummer ohne Bezeichnung** erkennt die App nicht, wenn sie kurz ist und im
    Haushalt nirgends steht („Bitte 54321 angeben“).
  - Was die KI **selbst aus einem Original** liest oder was du ihr gibst, kann die App
    nicht zurückhalten.
- **Originale an die KI geben** (`mcp_share_documents`), ab Werk aus: Die KI darf die PDFs
  aus dem Posteingang abrufen, etwa um eine Rechnung selbst auszulesen.

## Schritt 2 — Schlüssel anlegen

Auf derselben Karte einen Schlüssel anlegen, **einen je Programm und Gerät**
(etwa „Claude Code am Laptop“). Der Name erscheint im Protokoll und im Verlauf.
Den Schlüssel (`etk_…`) zeigt die App **einmal**; gespeichert ist nur sein
Hash.

| Bereich | darf |
|---|---|
| KI lesen (`mcp`) | alle lesenden Werkzeuge, die Prompts und die Ressourcen |
| KI lesen und vorschlagen (`mcp_write`) | dazu Dokumente auslesen, Änderungen vorschlagen und nach deiner Zustimmung übernehmen; außerdem ein PDF in den Posteingang legen (`POST /api/inbox`) |

Fang mit **KI lesen** an. Die Schnittstelle verlangt **immer** einen solchen
Schlüssel, auch wenn die Anmeldung im Energietracker aus ist. Andere Schlüssel
(Lesen, Verwalten, Kalender, Posteingang) gelten hier nicht, und ein
KI-Schlüssel gilt nirgends sonst.

## Schritt 3 — Adresse der Schnittstelle

Die Karte zeigt die Adresse. Sie hängt davon ab, wie der Energietracker läuft:

| Betrieb | Adresse |
|---|---|
| Docker (nginx im Image) | `http://192.168.178.20:8080/api/mcp` |
| Apache, Synology Web Station | `http://192.168.178.20/energietracker/api.php/api/mcp` |
| Reverse-Proxy mit HTTPS | `https://energie.example.org/api/mcp` |
| App in Home Assistant | `http://homeassistant.local:8095/api/mcp`; über Ingress (Seitenleiste) geht es nicht: in der App unter **Konfiguration → Netzwerk** einen Port freigeben, etwa `8095` ([Home Assistant](home-assistant.md#als-app-in-home-assistant-os)) |

Die Beispieladressen ersetzt du durch deine. Die Karte nennt immer die Adresse
mit `api.php`: Sie geht mit jedem Webserver; im Docker-Image und in der App in
Home Assistant geht auch `/api/mcp` wie in der Tabelle. Ein Test im Browser
hilft nicht: Die Schnittstelle nimmt nur `POST` mit Schlüssel an.

## Schritt 4 — Open WebUI mit Ollama verbinden

Das ist der empfohlene Weg: Modell und Daten bleiben im Heimnetz. In allen
Beispielen steht `etk_…` für deinen Schlüssel.

### Open WebUI

1. **Ollama** installieren und ein Modell laden, das Werkzeuge aufrufen kann (in
   der Modellübersicht von Ollama als „tools“ gekennzeichnet):
   `ollama pull <modell>`.
2. **Open WebUI** ab 0.6.31 starten und mit Ollama verbinden, wie es dessen
   Anleitung beschreibt.
3. Im Energietracker den Schlüssel anlegen (Schritt 2). Fang mit **KI lesen** an.
4. In Open WebUI als Verwalter: **Einstellungen → Integrationen → Werkzeuge,
   Externe Tool-Server → +** (so heißt es in Version 0.12.4 auf Deutsch; auf
   Englisch „External Tool Servers“, andere Versionen können abweichen), Typ
   **MCP (Streamable HTTP)**, als URL die Adresse aus Schritt 3,
   Authentifizierung **Bearer** mit dem Schlüssel. Du kannst den Eintrag dort auch
   importieren, dann mit deiner Adresse und deinem Schlüssel:

   ```json
   [{"type":"mcp","url":"http://host.docker.internal:8080/api/mcp","spec_type":"url","spec":"","path":"openapi.json","auth_type":"bearer","key":"etk_…","info":{"id":"energietracker","name":"","description":"energietracker"}}]
   ```
5. Open WebUI läuft oft im Container. Dann heißt `localhost` der Container selbst:
   Nimm `http://host.docker.internal:8080/api/mcp` (unter Linux mit
   `--add-host=host.docker.internal:host-gateway`) oder die Adresse des Rechners im
   Heimnetz, etwa `http://192.168.178.20:8080/api/mcp`; auf Apache und Synology mit
   `api.php` im Pfad (Schritt 3). Ist `ET_ALLOWED_HOSTS` gesetzt, muss der Name dort
   stehen.
6. Im Chat unter den Werkzeugen „energietracker“ einschalten und fragen: „Wie steht
   mein Gas-Saldo?“. Die Antwort soll dieselbe Zahl nennen wie die Saldo-Karte.
   Erscheint das Werkzeug nicht zur Auswahl, einmal in Open WebUI ab- und wieder
   anmelden.

Mit einem Modell aus Ollama verlässt dabei nichts das Heimnetz.

## Andere Programme (experimentell)

Diese Vorlagen folgen den Anleitungen der Hersteller und sind nicht selbst
geprüft. Wo es geht, liegt der Schlüssel nicht im Klartext in der Datei.

### Claude Code

```bash
claude mcp add --transport http --scope user energietracker 'http://192.168.178.20:8080/api/mcp' --header 'Authorization: Bearer etk_…'
```

`--scope user` lässt den Eintrag in allen Projekten gelten: Dein Haushalt hängt
an keinem. Oder für ein Projekt in `.mcp.json`, der Schlüssel aus der
Umgebungsvariablen `ENERGIETRACKER_KEY`:

```json
{
  "mcpServers": {
    "energietracker": {
      "type": "http",
      "url": "http://192.168.178.20:8080/api/mcp",
      "headers": { "Authorization": "Bearer ${ENERGIETRACKER_KEY}" }
    }
  }
}
```

### VS Code

`.vscode/mcp.json` (oder „MCP: Add Server“ in der Befehlspalette); VS Code
fragt den Schlüssel beim ersten Start ab und speichert ihn verdeckt:

```json
{
  "inputs": [
    { "type": "promptString", "id": "energietracker-key", "description": "API-Schlüssel für den Energietracker", "password": true }
  ],
  "servers": {
    "energietracker": {
      "type": "http",
      "url": "http://192.168.178.20:8080/api/mcp",
      "headers": { "Authorization": "Bearer ${input:energietracker-key}" }
    }
  }
}
```

### Cursor und LM Studio

Beide lesen dieselbe Form: Cursor `~/.cursor/mcp.json`, LM Studio die Datei
`mcp.json`, die du unter **Program → Install → Edit mcp.json** öffnest:

```json
{
  "mcpServers": {
    "energietracker": {
      "url": "http://192.168.178.20:8080/api/mcp",
      "headers": { "Authorization": "Bearer etk_…" }
    }
  }
}
```

LM Studio hat in manchen Versionen einen Eintrag mit `url` als alten
SSE-Transport behandelt; kommt keine Verbindung zustande, die Version
aktualisieren.

## Beispiele

Frag einfach in deinen Worten:

- „Wie steht mein Gas-Saldo?“ Die KI nennt den gemessenen Saldo bis zur
  letzten Ablesung und den erwarteten bei der Abrechnung, mit Spanne.
- „Wie viel Strom habe ich im März verbraucht, und ist das gemessen?“
- „Was steht in den nächsten 60 Tagen an, und wer kümmert sich?“
- „Was wäre, wenn der Winter 20 % kälter wird?“
- „Trag Gas 12 345,6 m³ von heute ein.“ Mit einem Schlüssel „KI lesen und
  vorschlagen“ zeigt dir die KI zuerst den Vorschlag; eingetragen wird erst,
  wenn du ihn bestätigst.
- „Prüf die Gasrechnung im Posteingang.“

**Prompts** sind fertige Aufträge. Je nach Programm erscheinen sie im Menü für
Prompts oder als Befehle, wenn du `/` tippst:

<!-- mcp-prompts -->
| Prompt | Was er tut | Argumente |
|---|---|---|
| `monthly_report` | Monatsbericht: Verbrauch gegen den Vorjahresmonat, Kosten, Saldo, was ansteht, ein Tipp | `month` (JJJJ-MM, sonst der letzte volle Monat), `utility` |
| `yearly_report` | Jahresbericht wie das PDF der App | `year` (sonst das Vorjahr), `billing_period` (`true`: Abrechnungsjahr) |
| `bill_review` | Rechnung prüfen: stimmt sie, Gründe, Abweichung in Euro und Menge | `document_id` (Posteingang) oder `bill_id` (erfasst), `utility` |
| `deadlines_check` | Was steht an: was zu tun ist, bis wann, wer zuständig ist | `days` (1 bis 366, sonst 90) |
<!-- /mcp-prompts -->

**Ressourcen** sind Lesestoff für die KI (je nach Programm zum Anhängen):

<!-- mcp-resources -->
| Ressource | Inhalt |
|---|---|
| `energietracker://hilfe/glossar` | Glossar und Formeln |
| `energietracker://hilfe/herkunft` | [Woher eine Zahl kommt](../verstehen/18-herkunft.md) |
| `energietracker://hilfe/{path}` | jede Seite dieses Handbuchs, etwa `verstehen/00-overview.md`, englisch unter `en/` |
| `energietracker://dokument/{id}.pdf` | ein Original aus dem Posteingang, nur mit „Originale an die KI geben“, bis 5 MB |
<!-- /mcp-resources -->

## Was die KI kann

<!-- mcp-tools -->
- **Lesen** (Bereich `mcp`): `get_overview` (Einstieg: wie steht es?),
  `list_meters`, `get_deadlines`, `get_contracts`, `get_consumption`,
  `get_forecast`, `run_scenario` (was wäre wenn), `list_readings`,
  `list_bills`, `check_bill`, `get_insights`, `get_yearly_report`,
  `list_documents`, `get_document`, `get_document_schema`.
- **Vorschlagen und übernehmen** (Bereich `mcp_write`): `read_document` (die
  KI liest ein Dokument selbst aus), `propose_reading`, `propose_document`,
  `apply_proposal`, `cancel_proposal`.
<!-- /mcp-tools -->

Eingaben und Ausgaben jedes Werkzeugs beschreibt der Server selbst
(JSON-Schema in `tools/list`); die Übersicht steht in der
[API-Referenz](../referenz/api.md#ki-schnittstelle-mcp-v3120).

### Rechnungen und andere Dokumente

Ein Sprachmodell kann ein PDF nicht als Text an ein Werkzeug schicken. Das
Original kommt deshalb immer über den [Posteingang](posteingang.md): über die
Oberfläche, den überwachten Ordner, die E-Mail über Home Assistant oder mit
dem KI-Schlüssel (Bereich `mcp_write`) per `curl`:

```bash
curl -H "Authorization: Bearer etk_…" -H "Content-Type: application/pdf" \
  --data-binary @gasrechnung.pdf \
  "http://192.168.178.20:8080/api/inbox?name=gasrechnung.pdf"
```

Danach findet die KI das Dokument mit `list_documents`. Ist es schon
ausgelesen (in der App), schlägt sie die Übernahme vor; sonst liest sie es
selbst aus und liefert jede Angabe mit Zitat und Seite, die der Energietracker
gegen den Text prüft wie beim Auslesen in der App.

<a id="wer-bestaetigt"></a>

## Wer bestätigt eine Änderung?

Die KI schreibt nie direkt. Sie legt einen **Vorschlag** an (`propose_reading`,
`propose_document`); der prüft alles, was auch beim Speichern geprüft würde,
schreibt aber nichts. Die Zusammenfassung baut der Energietracker selbst aus den
Daten, mit allem, was Geld oder Fristen bewegt.

**Warum es den Schalter gibt.** Die Nachfrage des KI-Programms ist nur eine Bitte:
Der Energietracker setzt `_meta["anthropic/requiresUserInteraction"]`, aber ob ein
Programm das beachtet, ist nicht geprüft, und er kann es auch nicht prüfen. Steht ein
Programm auf „immer erlauben“, läuft ein Skript mit dem Schlüssel oder steht in einem
Dokument eine eingeschleuste Anweisung, gäbe es ohne den Schalter keinen Menschen
dazwischen. Deshalb: **Übernahme nur in der App bestätigen** (`mcp_apply_in_app`), ab
Werk an.

**Was er schützt:** Keine KI und kein Dokument kann **über den Schlüssel und die
Werkzeuge der Schnittstelle** am Haushalt etwas ändern, ohne dass du in der App auf
**Übernehmen** klickst und dabei den ganzen Satz siehst. Übernehmen und Ablehnen nehmen
keinen Schlüssel an (403), auch ohne Anmeldung, und gelten nur aus der Oberfläche der App.

**Was er nicht schützt:**

- **Lesen.** Was die KI liest, geht an ihr Sprachmodell.
- **Die App selbst, wenn die Anmeldung aus ist.** Ohne Anmeldung kann jedes Gerät im
  Heimnetz die App bedienen. Ein Programm ohne Schlüssel, das sich wie ein Browser
  ausgibt, kann dann einen Vorschlag übernehmen oder den Schalter ausstellen. Das gilt
  auch für eine KI, die neben ihrem Schlüssel selbst Netzzugriff oder eine Shell hat. Der
  Server kann sie nicht von dir unterscheiden. **Dagegen hilft nur die Anmeldung:** Dann
  braucht es deine Sitzung und die Rolle Verwalter. Ist sie aus, sagt die Karte das unter
  dem Schalter.
- **Deine Entscheidung.** Einen falschen Vorschlag, den du trotzdem übernimmst, trägt die
  App ein. `read_document` darf eine noch nicht geprüfte Auslesung am Dokument ersetzen
  (mit Eintrag im Verlauf, nie nach einer Korrektur, einer Übernahme oder wenn du es
  abgelegt hast).

### Mit dem Schalter (ab Werk)

1. Du schreibst: „Trag meinen Gasstand 13.200 ein.“
2. Die KI ruft `propose_reading` auf. Geschrieben wird nichts außer dem Vorschlag.
3. Die KI antwortet sinngemäß: „Vorschlag angelegt: *Gas: Stand 13.200 m³ am
   04.11.2026 eintragen (geschätzt: nein).* Noch nichts geändert. Bestätige ihn in
   der App.“ Der Wortlaut mit Weg und Frist steht im Feld `message`.
4. Du öffnest in der App **Zu tun**, Filter **KI-Vorschläge**, und klickst
   **Übernehmen**. Die Rückfrage **Vorschlag
   übernehmen?** zeigt den ganzen Satz, den Schlüssel und den Zähler.
5. Die KI fragt mit `apply_proposal` nach und meldet das Ergebnis: Vorher kam
   `status: awaiting_confirmation`, jetzt `status: applied` mit `repeated: true`.

Der Verlauf nennt beides: **KI (Schlüssel „…“), bestätigt von** dir.

### Ohne den Schalter

1. Du schreibst: „Trag meinen Gasstand 13.200 ein.“
2. Die KI ruft `propose_reading` auf und zeigt dir den Satz.
3. Du sagst „ja“. Die KI ruft `apply_proposal` mit der Kennung und dem Satz wörtlich
   auf; dabei bittet der Energietracker dein Programm um eine Nachfrage. Ob es
   nachfragt, entscheidet das Programm.
4. Der Stand ist eingetragen (`status: applied`). Der Verlauf nennt **KI (Schlüssel
   „…“)**.

### Beispiel mit einer Rechnung

1. Du legst das PDF in den Posteingang und schreibst: „Übernimm die Gasrechnung.“
2. Die KI liest das Dokument mit `read_document` aus oder nimmt die Auslesung aus der
   App, dann `propose_document`. Sie sagt dir:
   *Gas: aus dem Dokument inb_… (Rechnung) übernehmen: Ablesung (31.12.2025, 13.580
   m³); Rechnung (01.01.2025 – 31.12.2025, 13.216 kWh, Betrag 1.722,70 €, Abschläge
   1.680,00 €, Nachzahlung 42,70 €); Ergebnis der Rechnung buchen (Nachzahlung 42,70
   €).* Die Zahlen stehen im Satz, damit du sie vergleichen kannst. Der Anbieter steht
   nur daneben, als Text aus dem Dokument.
3. Du vergleichst mit der Rechnung und klickst **Übernehmen**, oder **Ablehnen**.

### Was immer gilt

- Mit dem Schalter gilt ein Vorschlag **24 Stunden**, weil du erst zur App musst und
  nicht neben dem KI-Programm sitzen musst. Ohne ihn gilt ein Vorschlag **15
  Minuten**. In beiden Fällen nur einmal; danach fängt die KI neu an.
- Er gehört zu dem Schlüssel, der ihn angelegt hat. Höchstens **50** offene
  Vorschläge je Schlüssel. Der Vorschlag eines widerrufenen Schlüssels gilt nicht mehr.
- Kommt nach dem Vorschlag eine Rückfrage dazu, etwa weil inzwischen ein anderer Stand
  erfasst ist, wird nichts geschrieben und die KI legt neu an.
- Die Übernahme steht im **Verlauf** mit dem Namen des Schlüssels und der Liste der
  Änderungen; eine Ablesung trägt die Quelle `mcp`. **Rückgängig gibt es dafür nicht:**
  Einzeln in der App löschen, was nicht stimmt.

Offene Vorschläge stehen unter **Zu tun**, das Protokoll der Zugriffe (wer, wann, welches
Werkzeug, ohne Inhalte) unter **Zu tun → Verlauf → Zugriffe der KI**. Wartet ein Vorschlag,
sagt es auch die Übersicht über „Zu tun“.

## Grenzen

- **Die KI kann weglassen und sich irren.** Die Zahlen aus den Werkzeugen stimmen,
  die Erzählung darüber nicht immer. In unserer Abnahme nannte ein lokales Modell die
  Fristen der nächsten 30 Tage richtig, ließ aber einzelne Einträge weg und nahm einen
  späteren Termin dazu. Bei Fristen, die zählen, schau unter „Zu tun“ nach.
- **Ratengrenzen je Schlüssel:** 120 Anfragen je Minute, davon 60 Aufrufe
  lesender Werkzeuge; 30 Vorschläge und Auslesungen je Stunde und 30
  Übernahmen je Stunde. Nach 20 falschen Schlüsseln in 10 Minuten sperrt der
  Server die Adresse für 5 Minuten. Darüber antwortet er mit `429` und
  `Retry-After`. `cancel_proposal` zählt wie ein lesendes Werkzeug: Verwerfen
  geht auch, wenn die Vorschläge der Stunde aufgebraucht sind. Ablegen im
  Posteingang mit dem KI-Schlüssel: 30 je Stunde, ein PDF bis 10 MB, nur bei
  eingeschalteter Schnittstelle, mit Zeile im Zugriffsprotokoll.
- **Anfrage höchstens 1 MB.** Ein Original geht bis 5 MB an die KI, größere
  öffnest du in der App.
- **Texte an die KI** (Beschreibungen, Prompts, Meldungen) gibt es auf Deutsch
  und Englisch; bei einer anderen Sprache der Installation auf Englisch. Die KI
  antwortet trotzdem in deiner Sprache.
- **Fremdtext gekürzt:** Namen bis 120, Zitate bis 300, Notizen bis 500 Zeichen.
  Texte der App zu einem Befund (Rechte, Hinweise) bis 2000 Zeichen.
- **Nur Anfrage und Antwort:** keine Sitzung, kein Ereignisstrom (SSE), keine
  Abos. Programme, die nur SSE sprechen, gehen nicht; dazu gehört die
  Integration „Model Context Protocol“ von Home Assistant.
- **Kein OAuth.** Programme, die zwingend eine Anmeldung über OAuth verlangen,
  verbinden sich nicht.
- **Keine Werkzeuge für Einstellungen, Personen, Schlüssel, Sicherungen oder
  Löschen.** Das bleibt in der App.
- Langes Auslesen mit einem lokalen Modell und Berichte als PDF bleiben in der
  App; die KI verweist darauf.
- Die öffentliche Demo hat keine KI-Schnittstelle (sie läuft ohne Server).

## Aus der Cloud: nur mit eigenem HTTPS-Zugang

KI-Dienste, die aus der Cloud ihres Anbieters verbinden, brauchen eine
**öffentliche HTTPS-Adresse**. Meist verlangen sie außerdem eine Anmeldung über
OAuth; die bietet der Energietracker in v3.12.0 nicht. Nur wenn dein Dienst
eine eigene Kopfzeile (`Authorization: Bearer …`) erlaubt, geht es über einen
eigenen Reverse-Proxy. Das ist eine Anleitung, keine Empfehlung: Du stellst
damit einen Zugang zu deinem Haushalt ins Internet, geschützt nur durch den
Schlüssel.

1. Einen Reverse-Proxy mit gültigem Zertifikat vor den Energietracker stellen,
   der **nur** `/api/mcp` durchlässt. Beispiel Caddy:

   ```text
   energie.example.org {
       @mcp path /api/mcp
       handle @mcp {
           reverse_proxy 192.168.178.20:8080
       }
       handle {
           respond 404
       }
   }
   ```

2. Im Energietracker `ET_ALLOWED_HOSTS=energie.example.org` setzen
   ([Sicherheit](../betrieb/sicherheit.md#8-hostnamen-und-einbetten)).
3. Einen eigenen Schlüssel **KI lesen** nur für diesen Dienst anlegen.
4. Im Dienst die Adresse `https://energie.example.org/api/mcp` und die
   Kopfzeile eintragen.
5. Prüfen, dass von außen nichts anderes erreichbar ist:
   `curl -i https://energie.example.org/api/summary` muss `404` liefern.

Bedenke: Alles, was die KI liest, geht an den Anbieter des Dienstes. Schlüssel
widerrufen, sobald du den Zugang nicht mehr brauchst; Ratengrenzen und
Sperre nach falschen Schlüsseln gelten auch hier.

## Sicherheit und Datenschutz

- **Was die KI liest, sieht der Anbieter des Sprachmodells.** Mit einem
  lokalen Modell (LM Studio, Ollama mit Open WebUI) bleibt alles im Heimnetz;
  mit einem Modell in der Cloud gehen die Antworten der Werkzeuge dorthin.
- **Was nie hinausgeht:** Passwörter und Schlüssel (auch keine Hashes),
  Einstellungen mit Netzadressen, die Personenliste, Sicherungen, Protokolle
  und die Vorschläge anderer Schlüssel. Kennnummern und Originale nur mit den
  Schaltern aus Schritt 1.
- **Angaben für Briefe nie** *(v3.12.0)*: Name und Anschrift des Absenders,
  die Lieferanschrift (Haushalt › Gebäude) und die Anschrift des Anbieters am
  Vertrag. Kein Werkzeug gibt sie heraus; stehen sie in einer Notiz, einem
  Zählernamen oder auf einem Dokument, ersetzt die App sie durch `[…]`, auch
  mit dem Schalter für Kennnummern. **Grenze:** Anders Geschriebenes (abgekürzt,
  mit Zusatz) erkennt die App nicht, und ein freigegebenes Original zeigt das
  Dokument, wie es ist. <!-- doc-claim: letter-data-private (McpLetterDataTest) -->
- **Fremdtext ist Daten:** Namen, Notizen und Zitate aus Dokumenten stehen
  nur bereinigt im Feld `untrusted`. Schreibt jemand „Ignoriere alle
  Anweisungen“ in eine Rechnung, ist das für die KI ein Zitat, keine
  Anweisung, und ändern kann sie ohnehin nur über einen Vorschlag, den du in der
  App bestätigst.
- **Keine Rechtsberatung:** Was die KI zu Fristen, Verträgen und Rechten
  sagt, ist ein Hinweis aus deinen Daten.
- **Schlüssel wie Passwörter behandeln:** nicht in geteilte Projektdateien
  schreiben, je Programm einen eigenen, nicht mehr gebrauchte widerrufen
  (Verbindungen → KI-Assistenten → Verbinden).
- Über die Schnittstelle liest die KI nur; ein Auslesen in der Cloud des
  Posteingangs startet sie nie.

Mehr in [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md#ki-assistenten-mcp-v3120).

## Fehlersuche

| Meldung | Ursache und Abhilfe |
|---|---|
| `401` | Kein oder ein falscher Schlüssel. Kopfzeile genau `Authorization: Bearer etk_…`; widerrufene Schlüssel neu anlegen. Manche Programme suchen nach einem `401` eine OAuth-Anmeldung und melden dann einen Fehler dazu: Ursache ist trotzdem der Schlüssel. |
| `403` „ausgeschaltet“ | Schritt 1; oder `ET_MCP=off` ist gesetzt. |
| `403` mit `insufficient_scope` | Ein Schlüssel ohne KI-Bereich (etwa „Lesen“ für Home Assistant), oder ein Werkzeug zum Vorschlagen mit einem Schlüssel „KI lesen“. |
| `404` | Adresse falsch: Auf Apache und Synology gehört `api.php` hinein (Schritt 3). |
| `413` | Anfrage über 1 MB. |
| `429` | Ratengrenze; nach `Retry-After` Sekunden geht es weiter. |
| Keine Werkzeuge sichtbar | Programm neu laden; manche Programme zeigen Werkzeuge erst nach dem Einschalten im Chat. |

---

[Posteingang](posteingang.md) · [Woher eine Zahl kommt](../verstehen/18-herkunft.md) ·
[Kompendium-Index](../README.md)
