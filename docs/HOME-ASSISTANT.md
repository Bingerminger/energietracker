# Energietracker mit Home Assistant verbinden

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
└─────────────────┘                       └────────────────────┘
```

1. **API-Token** im Energietracker erzeugen (einmalig) → schützt den Push.
2. Jedem Zähler einen **Alias** geben (z. B. `stromzaehler_haus`).
3. In HA ein **REST-Command** + eine **Automatisierung** anlegen, die abends
   die Zählerstände sendet.

Alle drei Schritte lassen sich direkt im Energietracker unter
**Einstellungen → 🏠 Home-Assistant-Anbindung** vorbereiten (inkl.
Copy-&-Paste-YAML).

---

## Schritt 1 — API-Token erzeugen

1. Energietracker öffnen → **Einstellungen** → **🏠 Home-Assistant-Anbindung**.
2. Auf **„Token erzeugen"** klicken. Der Token wird **nur einmal** angezeigt —
   sofort kopieren und sicher ablegen (z. B. in den HA-Secrets).
3. Solange kein Token gesetzt ist, ist der Push-Endpoint offen erreichbar (nur
   fürs lokale Netz gedacht). **Sobald ein Token existiert, muss HA ihn
   mitsenden** — andernfalls antwortet der Endpoint mit `401`.

> Der Token wird serverseitig nur als **Hash** gespeichert (in `data/auth.json`),
> nie im Klartext und nicht in den normalen Einstellungen. Geht er verloren,
> erzeugst du einfach einen neuen (der alte wird damit ungültig).

---

## Schritt 2 — Zähler-Aliase vergeben

HA soll die Zähler nicht über kryptische interne IDs (`m_strom_main`)
ansprechen. Vergib stattdessen pro Zähler einen **Alias**:

- In **Einstellungen → 🏠 Home-Assistant-Anbindung → Zähler-Aliase** je Zähler
  einen Alias eintragen (z. B. `stromzaehler_haus`, `gaszaehler_wohnung`) und
  **speichern**.
- Erlaubt sind 1–64 Zeichen aus Buchstaben, Ziffern, `_`, `.`, `-`.
- Der Alias muss innerhalb einer Verbrauchsart eindeutig sein.

Der Ingest-Endpoint akzeptiert sowohl den Alias als auch die interne ID — der
Alias ist nur die bequemere, lesbare Variante.

---

## Schritt 3 — REST-Command in Home Assistant

In die `configuration.yaml` (URL/Token anpassen — das fertige Snippet steht
auch in den Einstellungen zum Kopieren):

```yaml
rest_command:
  energietracker_push:
    url: "http://DEINE-ENERGIETRACKER-IP:8080/api.php/api/ingest"
    method: POST
    headers:
      Authorization: "Bearer !secret energietracker_token"
      Content-Type: "application/json"
    payload: >
      {
        "utility": "{{ utility }}",
        "meter": "{{ meter }}",
        "value": {{ states(sensor_entity) | float(0) }},
        "date": "{{ now().strftime('%Y-%m-%d') }}"
      }
```

> **Pfad-Hinweis:** `…/api.php/api/ingest` funktioniert immer. Wenn dein Webserver
> eine Rewrite-Regel hat (Apache `.htaccess` / nginx), geht auch `…/api/ingest`.

Token in `secrets.yaml`:

```yaml
energietracker_token: "et_dein_kopierter_token"
```

---

## Schritt 4 — Automatisierung (täglicher Push)

```yaml
alias: "Energie: Zählerstände an Energietracker senden"
description: "Sendet abends die Tageszählerstände zur Vertrags- & Kostenpflege"
triggers:
  - trigger: time
    at: "23:55:00"
actions:
  - action: rest_command.energietracker_push
    data:
      utility: "strom"
      meter: "stromzaehler_haus"
      sensor_entity: "sensor.stromzaehler_total_kwh"
  - action: rest_command.energietracker_push
    data:
      utility: "gas"
      meter: "gaszaehler_haus"
      sensor_entity: "sensor.gaszaehler_total_m3"
mode: single
```

> **Idempotent:** Ein erneuter Push am selben Tag (z. B. manueller Test +
> Automatik) erzeugt **kein** Duplikat — der Energietracker aktualisiert den
> bestehenden Tageswert (Upsert pro Zähler & Datum).

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

> **Nicht unterstützt:** Heizöl und Pellets (`heizoel`/`pellets`) — die arbeiten
> mit **Lieferungen** statt Zählerständen. Ein Ingest darauf wird mit `400`
> abgelehnt.

Wichtig ist der **absolute Zählerstand** (der Wert auf dem Zähler), nicht der
Tagesverbrauch — der Energietracker bildet Differenzen selbst und rechnet
Zählertausch verlustfrei heraus.

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
  - action: rest_command.energietracker_push
    data: { utility: "strom",          meter: "strom_haus",          sensor_entity: "sensor.netz_bezug_total_kwh" }
  - action: rest_command.energietracker_push
    data: { utility: "fernwaerme",     meter: "fernwaerme_haus",     sensor_entity: "sensor.waermemenge_total_kwh" }
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
  - action: rest_command.energietracker_push
    data: { utility: "strom",  meter: "strom_wohnung",  sensor_entity: "sensor.strom_total_kwh" }
  - action: rest_command.energietracker_push
    data: { utility: "gas",    meter: "gas_wohnung",    sensor_entity: "sensor.gas_total_m3" }
  - action: rest_command.energietracker_push
    data: { utility: "wasser", meter: "wasser_wohnung", sensor_entity: "sensor.wasser_total_m3" }
mode: single
```

Der Energietracker übernimmt Abschlagskontrolle, Nachzahlungsprognose und (bei
Wasser) den Spar-Index — ideal, um die jährliche Nebenkostenabrechnung
vorzubereiten.

---

## Fehlersuche

| Symptom (HA-Log) | Ursache & Lösung |
|------------------|------------------|
| `401` | Token gesetzt, aber Header fehlt/falsch. `Authorization: Bearer <token>` prüfen; Token ggf. neu erzeugen. |
| `400 Kein Zähler für „…" gefunden` | Alias/ID stimmt nicht mit dem Zähler überein. In den Einstellungen den Alias prüfen. |
| `400 … arbeitet mit Lieferungen` | Heizöl/Pellets werden nicht per Ingest unterstützt. |
| `400 Zählerstand … keine Zahl` | Der HA-Sensor liefert `unknown`/`unavailable`. Mit `| float(0)` absichern oder Trigger an Sensor-Verfügbarkeit koppeln. |
| Wert taucht nicht auf | Falsche `utility`, oder der Zähler ist im Energietracker inaktiv. |

**Schnelltest** (von der HA-Maschine aus, offener Modus oder mit Token):

```bash
curl -X POST "http://DEINE-IP:8080/api.php/api/ingest" \
  -H "Authorization: Bearer DEIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"utility":"strom","meter":"strom_haus","value":12345.6}'
```

Eine erfolgreiche Antwort enthält `"status":"created"` (oder `"updated"` beim
zweiten Aufruf am selben Tag).

---

← [Doku-Index](README.md) · [API-Referenz](API.md)
