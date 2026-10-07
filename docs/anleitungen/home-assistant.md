# Energietracker mit Home Assistant verbinden

**Deutsch** · [English](../en/anleitungen/home-assistant.md)

> **Ziel:** Home Assistant (HA) liest deine Smart Meter automatisch aus und
> schickt die Zählerstände an den Energietracker. Du pflegst keine Werte mehr
> von Hand — der Energietracker übernimmt Verträge, Kostenberechnung und
> Prognosen, HA liefert still im Hintergrund die Daten.

Diese Anleitung ist die **offizielle** Integration (ab Energietracker **v1.9.0**,
Feature F1009).

> ⚠️ **Achtung vor kursierenden Forenanleitungen.** Es gibt eine populäre, aber
> **technisch falsche** Anleitung (KI-generiert), die `POST /api.php` mit einem
> `{"action":"add_reading", "value":…, "timestamp":…}` und einen Token aus
> `settings.json` beschreibt. **Nichts davon existiert im Energietracker.** Nutze
> ausschließlich die hier beschriebene Schnittstelle (`POST /api/ingest`).

---

## Überblick: Wie die Anbindung funktioniert

```
┌─────────────────┐   täglicher Push      ┌────────────────────┐
│  Home Assistant │  ──────────────────▶  │   Energietracker   │
│  (Smart Meter)  │   POST /api/ingest    │  Verträge · Kosten │
│                 │   Bearer-Token        │  Prognosen · UI    │
│                 │  ◀──────────────────  │                    │
│  Sensoren       │   GET /api/summary    │                    │
└─────────────────┘   stündlich           └────────────────────┘
```

1. **API-Token** im Energietracker erzeugen (einmalig) → schützt den Push.
2. Jedem Zähler einen **Alias** geben (z. B. `stromzaehler_haus`).
3. In HA ein **REST-Command** + eine **Automatisierung** anlegen, die abends
   die Zählerstände sendet.
4. Optional (seit v3.1.0): **Werte zurück** — REST-Sensoren zeigen Saldo,
   Prognose und Tage seit der letzten Ablesung in Home Assistant
   ([Schritt 5](#schritt-5--werte-zurück-nach-home-assistant)).

Alle Schritte lassen sich direkt im Energietracker unter
**Einstellungen → Integrationen → 🏠 Home-Assistant-Anbindung** vorbereiten
(inkl. Copy-&-Paste-YAML).

---

## Schritt 1 — API-Token erzeugen

1. Energietracker öffnen → **Einstellungen** → **Integrationen** →
   **🏠 Home-Assistant-Anbindung**.
2. Auf **„Token erzeugen"** klicken. Der Token wird **nur einmal** angezeigt —
   sofort kopieren und sicher ablegen (z. B. in den HA-Secrets).
3. Der Token schützt **nur** den Push-Endpoint `/api/ingest`, nicht den Rest
   der App. Solange keiner gesetzt ist, nimmt der Push Werte ohne Token an
   (nur fürs Heimnetz gedacht). **Sobald ein Token existiert, muss HA ihn
   mitsenden** — andernfalls antwortet der Endpoint mit `401`.
4. **Mit eingeschalteter Anmeldung** (Einstellungen → Zugriff → „Anmeldung &
   Zugriff", seit v2.6.0) ist der Token **Pflicht**: Ohne Token lehnt der Push
   dann jeden Wert ab. Die App selbst schützt die Anmeldung — siehe
   [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).

> Der Token wird serverseitig nur als **Hash** gespeichert (in `data/auth.json`),
> nie im Klartext und nicht in den normalen Einstellungen. Geht er verloren,
> erzeugst du einfach einen neuen (der alte wird damit ungültig).
>
> Seit v2.6.0 zeigt die Karte, wann zuletzt ein Wert mit dem Token ankam (auf
> die Stunde genau) — die erste Frage bei der Fehlersuche.

---

## Schritt 2 — Zähler-Aliase vergeben

HA soll die Zähler nicht über kryptische interne IDs (`m_strom_main`)
ansprechen. Vergib stattdessen pro Zähler einen **Alias**:

- In **Einstellungen → Integrationen → 🏠 Home-Assistant-Anbindung →
  Zähler-Aliase** je Zähler einen Alias eintragen (z. B. `stromzaehler_haus`,
  `gaszaehler_wohnung`) und **speichern**.
- Erlaubt sind 1–64 Zeichen aus Buchstaben, Ziffern, `_`, `.`, `-`.
- Der Alias muss innerhalb einer Verbrauchsart eindeutig sein.

Der Ingest-Endpoint akzeptiert sowohl den Alias als auch die interne ID — der
Alias ist nur die bequemere, lesbare Variante.

---

## Schritt 3 — REST-Command in Home Assistant

In die `configuration.yaml` (URL anpassen — das fertige Snippet mit der
richtigen Adresse steht auch in den Einstellungen zum Kopieren):

```yaml
rest_command:
  energietracker_push:
    url: "http://DEINE-ENERGIETRACKER-IP:8080/api.php/api/ingest"
    method: POST
    headers:
      Authorization: !secret energietracker_auth   # ganzer Wert aus der secrets.yaml, samt „Bearer“
      Content-Type: "application/json"
    # float ohne Ersatzwert: Ist der Sensor nicht verfügbar, scheitert der Push, statt eine 0 zu buchen.
    payload: >
      {
        "utility": "{{ utility }}",
        "meter": "{{ meter }}",
        "value": {{ states(sensor_entity) | float }},
        "date": "{{ now().strftime('%Y-%m-%d') }}"
      }
```

> **Pfad-Hinweis:** `…/api.php/api/ingest` funktioniert immer. Wenn dein Webserver
> eine Rewrite-Regel hat (Apache `.htaccess` / nginx), geht auch `…/api/ingest`.

Token in `secrets.yaml` — der **ganze** Header-Wert samt `Bearer`:

```yaml
energietracker_auth: "Bearer et_dein_kopierter_token"
```

> **Warum so?** `!secret` wirkt in Home Assistant nur als vollständiger YAML-Wert.
> In `"Bearer !secret energietracker_token"` (so stand es bis v2.5.2 in dieser
> Anleitung) ist es gewöhnlicher Text — der Energietracker bekommt die Zeichenkette
> wörtlich und antwortet `401`.

> ⚠️ **Hast du die Vorlage vor v2.5.3 übernommen?** Dann steht bei dir
> `| float(0)`. Bitte entfernen (`| float`) und die Bedingung aus Schritt 4
> ergänzen. `float(0)` macht aus einem nicht verfügbaren Sensor einen
> Zählerstand **0**; die nächste echte Ablesung zählt dann als Verbrauch eines
> einzigen Tages und verfälscht Kosten, Saldo und Prognose.

---

## Schritt 4 — Automatisierung (täglicher Push)

```yaml
alias: "Energie: Zählerstände an Energietracker senden"
description: "Sendet abends die Tageszählerstände zur Vertrags- & Kostenpflege"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  # Nur senden, wenn der Sensor einen Wert hat – nach einem Neustart steht er kurz auf „unavailable“.
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.stromzaehler_total_kwh') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "strom"
          meter: "stromzaehler_haus"
          sensor_entity: "sensor.stromzaehler_total_kwh"
  - if:
      - condition: template
        value_template: "{{ has_value('sensor.gaszaehler_total_m3') }}"
    then:
      - action: rest_command.energietracker_push
        data:
          utility: "gas"
          meter: "gaszaehler_haus"
          sensor_entity: "sensor.gaszaehler_total_m3"
mode: single
```

> **Die Bedingung gehört dazu.** Ist ein Sensor gerade nicht verfügbar
> (HA-Neustart, Funkaussetzer um 23:55), lässt die Automatisierung diesen Zähler
> für heute aus — die übrigen laufen weiter. Ein ausgelassener Tag kostet nichts:
> Der Energietracker verteilt den Verbrauch zwischen zwei Ablesungen ohnehin
> linear. Eine falsche Ablesung dagegen verfälscht alles, bis jemand sie findet.
> Die Einstellungen erzeugen diese Automatisierung fertig aus deinen Aliasen.

> **Idempotent:** Ein erneuter Push am selben Tag (z. B. manueller Test +
> Automatik) erzeugt **kein** Duplikat — der Energietracker aktualisiert den
> bestehenden Tageswert (Upsert pro Zähler & Datum).

---

## Schritt 5 — Werte zurück nach Home Assistant

Seit v3.1.0 geht es auch in die andere Richtung: Der Energietracker rechnet
Saldo, Prognose und Fälligkeiten, Home Assistant zeigt sie an und reagiert
darauf. Quelle ist `GET /api/summary` — eine Abfrage für alle Sensoren, die
Felder stehen in der
[API-Referenz](../referenz/api.md#get-apisummary-v310).

Die Einstellungen erzeugen den Block fertig aus deinen Zählern (Karte
**„Schritt 5 · Werte zurück nach Home Assistant“**): je Zähler die Prognose der
nächsten zwölf Monate und die Tage seit der letzten Ablesung, bei Zählern mit
Vertrag dazu der Saldo. Für einen Stromzähler sieht das so aus — in die
`configuration.yaml`:

```yaml
rest:
  - resource: "http://DEINE-ENERGIETRACKER-IP:8080/api.php/api/summary"
    scan_interval: 3600   # einmal je Stunde genügt
    headers:
      Authorization: !secret energietracker_read   # nur nötig, wenn die Anmeldung eingeschaltet ist
    sensor:
      - name: "Strom – Saldo"
        unique_id: energietracker_strom_m_strom_default_balance
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='contract.balance', default=none) | first }}"
        device_class: monetary
        unit_of_measurement: "EUR"
      - name: "Strom – Prognose 12 Monate"
        unique_id: energietracker_strom_m_strom_default_forecast_12m
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='forecast_12m.value', default=none) | first }}"
        device_class: energy
        unit_of_measurement: "kWh"
      - name: "Strom – Tage seit Ablesung"
        unique_id: energietracker_strom_m_strom_default_days_since_reading
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='days_since_reading', default=none) | first }}"
        device_class: duration
        unit_of_measurement: "d"
```

- **`key`** ist `<Verbrauchsart>.<interne Zähler-ID>` (nicht der Alias) — die
  Vorlage aus den Einstellungen setzt ihn richtig ein. Die `unique_id` leitet
  sich daraus ab und bleibt stabil, solange der Zähler bleibt.
- **`scan_interval: 3600`**: Die Werte ändern sich höchstens mit einer neuen
  Ablesung; einmal je Stunde genügt. Die Antwort darf fünf Minuten
  zwischengespeichert werden.
- **Saldo** hat das Vorzeichen der API: positiv = Nachzahlung, negativ =
  Guthaben (bei der PV-Einspeisung: positiv = Auszahlung). Die Einheit `EUR`
  steht für die Währung deiner Einstellungen.
- Ein Sensor zeigt „unbekannt“, wenn es den Wert nicht gibt — etwa ohne
  laufenden Vertrag oder bevor die Prognose genug Daten hat.

**Mit eingeschalteter Anmeldung** braucht Home Assistant zum Lesen einen
API-Schlüssel mit Bereich **„Lesen“** (Einstellungen → Zugriff → „API-Schlüssel
für Skripte“). Der Ingest-Token aus Schritt 1 gilt dafür nicht. In die
`secrets.yaml`, wieder der ganze Header-Wert:

```yaml
energietracker_read: "Bearer etk_…"
```

Ohne Anmeldung lässt du die beiden Zeilen `headers:` und `Authorization: …`
weg — sonst bemängelt Home Assistant das fehlende Secret.

**Dashboard-Karte** (Karte „Entitäten“, im Karten-Editor „Code-Editor“):

```yaml
type: entities
title: Energietracker
entities:
  - entity: sensor.strom_saldo
  - entity: sensor.strom_prognose_12_monate
  - entity: sensor.strom_tage_seit_ablesung
```

Die Entitäts-IDs bildet Home Assistant aus dem Namen; prüfe sie unter
Einstellungen → Geräte & Dienste → Entitäten.

**Automatisierung „Kündigungsfrist in 30 Tagen“.** Dafür ein vierter Sensor,
unter `sensor:` im Block oben angefügt:

```yaml
      - name: "Strom – Tage bis Stichtag"
        unique_id: energietracker_strom_m_strom_default_days_to_cancel
        value_template: "{{ value_json.data.meters | selectattr('key', 'eq', 'strom.m_strom_default') | map(attribute='contract.days_to_cancel', default=none) | first }}"
        unit_of_measurement: "d"
```

Und die Automatisierung:

```yaml
alias: "Energietracker: Kündigungsfrist in 30 Tagen"
triggers:
  - trigger: numeric_state
    entity_id: sensor.strom_tage_bis_stichtag
    below: 31
    above: 0
actions:
  - action: persistent_notification.create
    data:
      title: "Strom: Kündigungsfrist"
      message: >-
        Noch {{ states('sensor.strom_tage_bis_stichtag') }} Tage bis zum letzten
        Tag für die Kündigung. Jetzt Angebote vergleichen.
mode: single
```

Sie löst einmal aus, wenn der Wert unter 31 fällt. Wer Fristen lieber im
Kalender sieht: [Kalender abonnieren](kalender.md).

---

## Wichtig: Einheiten müssen passen

Der Energietracker rechnet mit den Einheiten der jeweiligen Verbrauchsart. Der
HA-Sensor muss **denselben kumulativen Zählerstand** in dieser Einheit liefern:

| Verbrauchsart | `utility` | Erwartete Einheit |
|---------------|-----------|-------------------|
| Strom         | `strom`     | kWh |
| Gas           | `gas`       | m³  |
| Wasser        | `wasser`    | m³  |
| Fernwärme     | `fernwaerme`| kWh |
| PV-Einspeisung | `pv_einspeisung` | kWh |
| PV-Erzeugung  | `pv_erzeugung` | kWh |
| Heizwärme *(v3.1.0)* | `waerme` | kWh |

> **Nicht unterstützt:** Heizöl und Pellets (`heizoel`/`pellets`) — die arbeiten
> mit **Lieferungen** statt Zählerständen. Ein Ingest darauf wird mit `400`
> abgelehnt. Ebenso ein Zähler mit der Erfassung „Verbrauch je Zeitraum“
> (`errors.ingest.periodMeter`, seit v3.1.0).

Wichtig ist der **absolute Zählerstand** (der Wert auf dem Zähler), nicht der
Tagesverbrauch — der Energietracker bildet Differenzen selbst und rechnet
Zählertausch verlustfrei heraus.

**Datenmenge:** Ein Push je Tag und Zähler genügt. Weitere am selben Tag
überschreiben den Stand dieses Tages (`"status":"updated"`) — die Datei wächst
um einen Eintrag je Tag, rund 90 KB im Jahr je Zähler.

> **Datenqualität:** Nur echte, absolute Zählerstände senden — nie einen
> Ersatzwert. Wird ein Zähler getauscht, gehört das in den Energietracker
> (Zähleransicht → **Zählertausch**), nicht in den Push: Der neue Zähler beginnt wieder bei
> einem kleinen Wert, und ohne Tausch-Eintrag sähe das wie ein Rückwärtslauf aus.

---

## Use-Case A — Eigenheim mit PV und Fernwärme

**Situation:** Einfamilienhaus, Smart Meter für Strombezug, Wärmemengenzähler
für Fernwärme, PV-Anlage mit Einspeisezähler. HA hat all diese Sensoren bereits.

**Aliase im Energietracker:**

| Zähler | Verbrauchsart | Alias |
|--------|---------------|-------|
| Hausanschluss Strom | `strom` | `strom_haus` |
| Fernwärme | `fernwaerme` | `fernwaerme_haus` |
| PV-Einspeisung | `pv_einspeisung` | `pv_einspeisung_haus` |

**HA-Automatisierung:**

```yaml
alias: "Energie: Eigenheim → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - if: [{ condition: template, value_template: "{{ has_value('sensor.netz_bezug_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "strom",          meter: "strom_haus",          sensor_entity: "sensor.netz_bezug_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.waermemenge_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "fernwaerme",     meter: "fernwaerme_haus",     sensor_entity: "sensor.waermemenge_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.einspeisung_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "pv_einspeisung", meter: "pv_einspeisung_haus", sensor_entity: "sensor.einspeisung_total_kwh" }
mode: single
```

Im Energietracker siehst du dann Bezugskosten, Fernwärme-Abrechnung und die
PV-Einspeisevergütung — ohne je einen Wert manuell einzutippen.

---

## Use-Case B — Mietwohnung (Strom, Gas, Wasser)

**Situation:** Mietwohnung mit Strom-, Gas- und (ablesbarem) Wasserzähler. HA
liest Strom/Gas über Smart-Meter-Lesekopf, Wasser z. B. über einen
Impuls-Sensor.

**Aliase im Energietracker:**

| Zähler | Verbrauchsart | Alias |
|--------|---------------|-------|
| Stromzähler Wohnung | `strom` | `strom_wohnung` |
| Gaszähler Wohnung | `gas` | `gas_wohnung` |
| Wasserzähler | `wasser` | `wasser_wohnung` |

**HA-Automatisierung:**

```yaml
alias: "Energie: Wohnung → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - if: [{ condition: template, value_template: "{{ has_value('sensor.strom_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "strom",  meter: "strom_wohnung",  sensor_entity: "sensor.strom_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.gas_total_m3') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "gas",    meter: "gas_wohnung",    sensor_entity: "sensor.gas_total_m3" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.wasser_total_m3') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "wasser", meter: "wasser_wohnung", sensor_entity: "sensor.wasser_total_m3" }
mode: single
```

Der Energietracker übernimmt Abschlagskontrolle, Nachzahlungsprognose und (bei
Wasser) den Spar-Index — ideal, um die jährliche Nebenkostenabrechnung
vorzubereiten.

---

## Use-Case C — Wärmepumpe mit Wärmemengenzähler

**Situation:** Eigenheim mit Wärmepumpe. HA liest den Stromzähler der
Wärmepumpe und ihren Wärmemengenzähler (über die Integration des Herstellers
oder einen M-Bus-Adapter). Seit v3.1.0 rechnet der Energietracker daraus die
Jahresarbeitszahl ([Heizwärme §7](../verstehen/15-waerme.md#7-jahresarbeitszahl-der-wärmepumpe-v310)).

**Im Energietracker:**

| Zähler | Verbrauchsart, Rolle | Alias |
|--------|---------------|-------|
| Wärmepumpe Strom | `strom`, Rolle „Wärmepumpe (Heizstrom)“, Subzähler des Hausanschlusses | `strom_waermepumpe` |
| Wärmemenge Wärmepumpe | `waerme`, Rolle „Wärmemenge der Wärmepumpe“, unter „Stromzähler der Wärmepumpe“ mit `strom_waermepumpe` verknüpft | `waerme_wp` |

Die Verbrauchsart **Heizwärme** muss eingeschaltet sein (Einstellungen →
Verbrauchsarten & Abrechnung). Beide Sensoren liefern kumulative Zählerstände
in kWh.

**HA-Automatisierung:**

```yaml
alias: "Energie: Wärmepumpe → Energietracker"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - if: [{ condition: template, value_template: "{{ has_value('sensor.waermepumpe_strom_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "strom",  meter: "strom_waermepumpe", sensor_entity: "sensor.waermepumpe_strom_total_kwh" }
  - if: [{ condition: template, value_template: "{{ has_value('sensor.waermepumpe_waerme_total_kwh') }}" }]
    then:
      - action: rest_command.energietracker_push
        data: { utility: "waerme", meter: "waerme_wp",         sensor_entity: "sensor.waermepumpe_waerme_total_kwh" }
mode: single
```

Beide Stände kommen am selben Abend an; die Karte „Wärmepumpe {Jahr}“ zählt
nur Monate, in denen beide Zähler Werte haben.

---

## Nachliefern nach einem Ausfall

War Home Assistant ein paar Tage aus oder hat der Lesekopf nichts geliefert,
fehlen diese Tage. Das kostet nichts: Der Energietracker verteilt den Verbrauch
zwischen zwei Ablesungen linear. Wer die Stände trotzdem hat — aus der
HA-Statistik, einer InfluxDB oder dem Log des Lesekopfs —, schickt sie seit
v3.1.0 in **einem** Stapel nach: eine Liste von bis zu 500 Einträgen, jeder wie
ein einzelner Push.

```bash
curl -X POST "http://DEINE-IP:8080/api.php/api/ingest" \
  -H "Authorization: Bearer DEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"readings":[
        {"utility":"strom","meter":"strom_haus","value":12301.4,"date":"2026-10-03"},
        {"utility":"strom","meter":"strom_haus","value":12312.9,"date":"2026-10-04"},
        {"utility":"strom","meter":"strom_haus","value":12324.0,"date":"2026-10-05"}
      ]}'
```

Die Antwort ist `200`, auch wenn einzelne Einträge scheitern. **Prüfe
`failed`**: Die Antwort nennt je Eintrag `status` (`created`, `updated` oder
`error`) und bei Fehlern den `code`, dazu die Summen `created`, `updated` und
`failed`. Mit `jq`:

```bash
… | jq '.data.failed, [.data.results[] | select(.status == "error") | {index, code}]'
```

Mit Node-RED baut ein Funktionsknoten den Stapel, etwa aus einer Abfrage der
letzten Tage, und ein `http request`-Knoten schickt ihn ab:

```js
// msg.payload: [{ day: '2026-10-03', kwh: 12301.4 }, …] aus der vorigen Abfrage
const readings = msg.payload
  .filter(r => Number.isFinite(Number(r.kwh)))          // nie 0 oder leer senden
  .map(r => ({ utility: 'strom', meter: 'strom_haus', value: Number(r.kwh), date: r.day }));
if (readings.length === 0) return null;
msg.payload = { readings };
msg.headers = { Authorization: 'Bearer ' + env.get('ET_TOKEN') };
return msg;
```

Wie ein Funktionsknoten danach `failed` auswertet und wie Home Assistant das
mit `response_variable` tut, steht in der
[API-Referenz](../referenz/api.md#stapel-v310); die vollständigen Rezepte für
Node-RED, ioBroker und openHAB unter [Andere Systeme](andere-systeme.md).

---

## Betrieb unter Home-Assistant-Ingress

Läuft der Energietracker selbst hinter Home-Assistant-Ingress (aufgerufen über
die Seitenleiste von Home Assistant), gilt seit v3.1.0:

- **Nicht offline.** Unter Ingress teilt die App ihren Ursprung mit Home
  Assistant und registriert keinen Service Worker. Offline erfassen geht nur beim
  direkten Aufruf über den eigenen Port.
- **Adresse in den Vorlagen.** Die Ingress-Adresse braucht eine HA-Sitzung und
  wechselt. Die Vorlagen in den Einstellungen nennen deshalb
  `http://<Container-Hostname>` — darüber erreicht Home Assistant die App im
  internen Netz.
- **Anmeldung über Home Assistant.** Ingress lässt nur angemeldete HA-Benutzer
  durch. Soll die App den Benutzer übernehmen, setze `ET_AUTH=proxy` und
  `ET_TRUSTED_PROXIES=172.30.32.2` (die Adresse, von der Ingress-Anfragen
  kommen). Home Assistant schickt `X-Remote-User-Name` und `X-Remote-User-Id`;
  der Energietracker erkennt sie seit v3.1.0. Push und Sensoren laufen nicht über
  Ingress und brauchen dann Token bzw. Lese-Schlüssel wie oben. Mehr:
  [Sicherheit & Netzbetrieb](../betrieb/sicherheit.md).
- **Uploads bis 16 MiB.** Ohne `ingress_stream: true` in der Konfiguration der
  App begrenzt Home Assistant Uploads über Ingress auf 16 MiB. Ein größeres
  Backup spielst du über den direkten Port ein.

---

## Fehlersuche

| Symptom (HA-Log) | Ursache & Lösung |
|------------------|------------------|
| `401` | Token gesetzt, aber Header fehlt/falsch. `!secret` wirkt nur als **ganzer** Wert: `Authorization: !secret energietracker_auth`, und in der `secrets.yaml` steht `"Bearer et_…"`. Ein `"Bearer !secret …"` schickt den Text wörtlich. Sonst Token ggf. neu erzeugen. Seit v2.6.0 auch: Anmeldung eingeschaltet, aber noch kein Token erzeugt. |
| Wert kommt an, zählt aber nicht | Er ist kleiner als der vorige Stand und deshalb **als Verdacht markiert** (seit v2.6.0; Antwort `"suspect": true`). In der Ansicht der Verbrauchsart steht ein Hinweis, der Stand trägt „PRÜFEN" — bestätigen (✅), korrigieren oder einen Zählertausch erfassen. |
| `400 Kein Zähler für „…" gefunden` | Alias/ID stimmt nicht mit dem Zähler überein. In den Einstellungen den Alias prüfen. |
| `400 … arbeitet mit Lieferungen` | Heizöl/Pellets werden nicht per Ingest unterstützt. |
| `400 Zählerstand … keine Zahl` | Der HA-Sensor liefert `unknown`/`unavailable`. Den Push dann **auslassen**, nie durch 0 ersetzen: Bedingung `has_value(…)` wie in Schritt 4. `| float(0)` tauscht den sichtbaren Fehler gegen eine stille Falschbuchung. |
| Template-Fehler „float got invalid input“ im HA-Log | Dieselbe Ursache, von `| float` ohne Ersatzwert gemeldet — gewollt: Es wird nichts gebucht. Bedingung `has_value(…)` ergänzen, dann bleibt das Log ruhig. |
| Antwort `created`/`updated`, aber der Wert taucht nicht auf | Die Verbrauchsart ist unter Einstellungen → Verbrauchsarten & Abrechnung → Aktive Verbrauchsarten abgewählt (dann fehlt sie im Menü, gespeichert ist der Wert trotzdem), oder die Ansicht zeigt einen anderen Zähler bzw. ein anderes Jahr. Auch ein auf „inaktiv“ gesetzter Zähler nimmt Werte an und zählt mit. |
| Sensoren aus Schritt 5: `401` oder „unbekannt“ | `401`: Anmeldung eingeschaltet, aber kein Lese-Schlüssel (`energietracker_read`) — der Ingest-Token gilt dafür nicht. „unbekannt“: `key` passt nicht (er nennt die interne Zähler-ID, nicht den Alias) oder der Wert fehlt (kein laufender Vertrag, noch keine Prognose). Die Vorlage in den Einstellungen setzt die Schlüssel richtig ein. |
| Stapel: Antwort `200`, aber Stände fehlen | Einzelne Einträge sind gescheitert — `failed` und die Einträge mit `"status": "error"` nennen Grund (`code`) und Position (`index`). |

**Schnelltest** (von der HA-Maschine aus, offener Modus oder mit Token):

```bash
curl -X POST "http://DEINE-IP:8080/api.php/api/ingest" \
  -H "Authorization: Bearer DEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"utility":"strom","meter":"strom_haus","value":12345.6}'
```

Eine erfolgreiche Antwort enthält `"status":"created"` (oder `"updated"` beim
zweiten Aufruf am selben Tag).

### Fallende Werte (seit v2.6.0)

Ein Zählerstand kann nicht sinken — außer beim Zählertausch oder Überlauf.
Liefert Home Assistant trotzdem einen kleineren Wert als den vorigen desselben
Geräts (typisch: ein Lesekopf-Aussetzer, der als 0 ankommt), speichert der
Energietracker ihn, **markiert ihn aber als Verdacht**: Er zählt in keiner
Auswertung, bis du ihn bestätigst. Die Antwort nennt `"suspect": true` und
den vorigen Stand. Bis v2.5.3 machte ein einziger solcher Wert aus einem
normalen Monat einen Verbrauch in Höhe des ganzen Zählerstands.

Pflege beim Zähler die **Stellen des Zählwerks** (Zähler bearbeiten → „Stellen
des Zählwerks"), wenn er nach 99.999 wieder bei 0 beginnen kann — dann gilt
ein Überlauf nicht als Verdacht, und die Auswertung rechnet ihn richtig.

---

← [Doku-Index](../README.md) · [API-Referenz](../referenz/api-beispiele.md) ·
[Andere Systeme](andere-systeme.md) · [Kalender abonnieren](kalender.md)
