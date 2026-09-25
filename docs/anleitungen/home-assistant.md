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
└─────────────────┘                       └────────────────────┘
```

1. **API-Token** im Energietracker erzeugen (einmalig) → schützt den Push.
2. Jedem Zähler einen **Alias** geben (z. B. `stromzaehler_haus`).
3. In HA ein **REST-Command** + eine **Automatisierung** anlegen, die abends
   die Zählerstände sendet.

Alle drei Schritte lassen sich direkt im Energietracker unter
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

> **Nicht unterstützt:** Heizöl und Pellets (`heizoel`/`pellets`) — die arbeiten
> mit **Lieferungen** statt Zählerständen. Ein Ingest darauf wird mit `400`
> abgelehnt.

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

← [Doku-Index](../README.md) · [API-Referenz](../referenz/api-beispiele.md)
