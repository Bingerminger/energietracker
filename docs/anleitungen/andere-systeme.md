# Zählerstände aus ioBroker, Node-RED und openHAB

**Deutsch** · [English](../en/anleitungen/andere-systeme.md)

[← Kompendium-Index](../README.md)

Home Assistant ist nicht das einzige System, das Zählerstände kennt. Alles, was
eine HTTP-Anfrage schicken kann, liefert über denselben Eingang
`POST /api/ingest` an den Energietracker. Diese Seite zeigt den gemeinsamen
Vertrag und je ein Rezept für ioBroker, Node-RED und openHAB. Für Home Assistant
gibt es eine eigene Anleitung: [Home Assistant anbinden](home-assistant.md).

Die Beispiele verwenden `192.168.178.10:8080` als Adresse — setze deine ein.

---

## Der gemeinsame Vertrag

**Vorbereitung im Energietracker** (wie bei Home Assistant, Schritte 1 und 2):

- Unter **Einstellungen → Integrationen → 🏠 Home-Assistant-Anbindung** einen
  **Token** erzeugen (`et_…`). Ohne Anmeldung ist er optional, mit
  eingeschalteter Anmeldung Pflicht. Er gilt nur für den Push — ein
  API-Schlüssel (`etk_…`) ist etwas anderes.
- Dort je Zähler einen **Alias** vergeben, etwa `strom_haus`. Die interne
  Zähler-ID geht auch.

**Die Anfrage:**

| | |
|---|---|
| Adresse | `http://<host>:<port>/api.php/api/ingest` — funktioniert immer; `…/api/ingest` nur mit Rewrite-Regel (im Docker-Image beides) |
| Methode | `POST` |
| Kopfzeilen | `Content-Type: application/json`; `Authorization: Bearer et_…`, sobald ein Token existiert |
| Body, einzeln | `{"utility": "strom", "meter": "strom_haus", "value": 12345.6, "date": "2026-10-07"}` — `value` heißt auch `counter`; `date` ist optional (Standard: heute) |
| Body, Stapel | eine Liste solcher Objekte oder `{"readings": [...]}`, höchstens 500 (seit v3.1.0) |

**Die Antwort** steckt in `{"success": true, "data": …}`:

- Einzeln: `201` (neu) oder `200` (am selben Tag aktualisiert) mit `status`,
  `utility`, `meter_id`, `date`, `counter`, `reading_id` und `suspect`. Ist
  `suspect: true`, war der Wert kleiner als der vorige — gespeichert, aber
  nicht gezählt, bis du ihn in der App bestätigst.
- Stapel: `200` auch bei Teilfehlern, mit `results` (je Eintrag `index`,
  `status` = `created`/`updated`/`error`, bei Fehlern `code`) und den Summen
  `created`, `updated`, **`failed`**. Prüfe `failed`.
- Fehler: `400` (Zähler unbekannt, keine Zahl, ungültiges Datum, Heizöl oder
  Pellets), `401` (Token fehlt oder falsch). Skripte werten `code` aus, nicht
  den Text. Alle Felder: [API-Referenz](../referenz/api.md#stapel-v310).

**Regeln, die überall gelten:**

- Nur den **absoluten Zählerstand** senden, nicht den Tagesverbrauch.
- **Einmal am Tag genügt.** Der Eingang ersetzt den Stand desselben Zählers am
  selben Tag, statt einen zweiten anzulegen.
- **Fehlt ein Wert, nichts senden — nie 0.** Eine 0 landet als Verdacht in der
  App; ein ausgelassener Tag kostet nichts.
- **Einheiten** wie die Verbrauchsart: Strom, Fernwärme und PV in kWh, Gas und
  Wasser in m³. Heizöl und Pellets arbeiten mit Lieferungen und gehen hier nicht.
- Einen **Zählertausch** im Energietracker erfassen, nicht im Push.

---

## ioBroker

Am einfachsten mit dem **JavaScript-Adapter** (ab Version 7.9 mit `httpPost`).
Ein Skript unter „Skripte“ anlegen:

```js
// Energietracker: Zählerstände täglich um 23:55 senden (javascript-Adapter ≥ 7.9)
const ET_URL   = 'http://192.168.178.10:8080/api.php/api/ingest';
const ET_TOKEN = 'et_…';   // Energietracker → Einstellungen → Integrationen → Token erzeugen
const METERS = [
  { utility: 'strom', meter: 'strom_haus', state: 'smartmeter.0.1-0:1_8_0__255.value' },
  { utility: 'gas',   meter: 'gas_haus',   state: '0_userdata.0.gaszaehler_m3' },
];

schedule('55 23 * * *', () => {
  const date = formatDate(new Date(), 'YYYY-MM-DD');
  for (const m of METERS) {
    const s = getState(m.state);
    const value = s && s.val !== null && s.val !== '' ? Number(s.val) : NaN;
    if (!Number.isFinite(value)) {                         // nie 0 senden
      log(`Energietracker: ${m.state} hat keinen gültigen Wert – heute ausgelassen`, 'warn');
      continue;
    }
    httpPost(ET_URL, { utility: m.utility, meter: m.meter, value, date },
      { timeout: 10000, bearerAuth: ET_TOKEN },
      (err, res) => {
        const body = typeof res?.data === 'string'
          ? (() => { try { return JSON.parse(res.data); } catch { return null; } })()
          : res?.data;
        if (err || !body?.success) {
          log(`Energietracker ${m.meter}: HTTP ${res?.statusCode} ${body?.code ?? err?.message}`, 'error');
        } else if (body.data.suspect) {
          log(`Energietracker ${m.meter}: Wert kleiner als zuvor – in der App prüfen`, 'warn');
        }
      });
  }
});
```

- Die Datenpunkte unter `state` durch deine ersetzen — es muss der absolute
  Zählerstand sein.
- **Blockly:** Baustein „Zeitplan“ (Cron `55 23 * * *`), „Wert von Objekt-ID“
  und „falls“ (ist eine Zahl); den Aufruf selbst in einen Block „Funktion“ mit
  dem `httpPost` von oben. Einen Standard-Block für einen POST mit Token gibt es
  nicht verlässlich.
- Der Adapter `rest-api` hilft hier nicht — er stellt ioBroker-Daten bereit,
  statt sie zu senden.
- ioBroker hat keinen eigenen Speicher für Geheimnisse in Skripten. Den Token
  besser in einem Datenpunkt unter `0_userdata.0` mit eingeschränkten Rechten
  ablegen als im Skripttext.

---

## Node-RED

Vier Knoten: **inject → function → http request → function**.

1. **inject:** „wiederholen“ zu einer Uhrzeit, täglich 23:55.
2. **function** „Zählerstände → Stapel“ — baut einen Stapel für alle Zähler:

   ```js
   // Quelle der Werte anpassen: hier globale Kontextvariablen
   const meters = [
     { utility: 'strom', meter: 'strom_haus', value: global.get('stromzaehler_kwh') },
     { utility: 'gas',   meter: 'gas_haus',   value: global.get('gaszaehler_m3') },
   ];
   const date = new Date().toLocaleDateString('sv-SE');   // JJJJ-MM-TT in lokaler Zeit
   const readings = [];
   for (const m of meters) {
     const v = Number(m.value);
     if (m.value === undefined || m.value === null || m.value === '' || !Number.isFinite(v)) {
       node.warn(`${m.meter}: kein gültiger Wert – ausgelassen`);   // nie 0 senden
       continue;
     }
     readings.push({ utility: m.utility, meter: m.meter, value: v, date });
   }
   if (readings.length === 0) return null;
   msg.payload = { readings };
   msg.headers = { Authorization: 'Bearer ' + env.get('ET_TOKEN') };
   return msg;
   ```

3. **http request:** Methode `POST`, URL
   `http://192.168.178.10:8080/api.php/api/ingest`, Rückgabe „ein analysiertes
   JSON-Objekt“. Ist `msg.payload` ein Objekt, schickt der Knoten es als JSON.
4. **function** „failed prüfen“:

   ```js
   const d = msg.payload && msg.payload.data;
   if (msg.statusCode !== 200 || !d) {
     node.error(`Energietracker: HTTP ${msg.statusCode} ${(msg.payload && msg.payload.code) || ''}`, msg);
     return null;
   }
   if (d.failed > 0) {
     const bad = d.results.filter(r => r.status === 'error').map(r => `#${r.index} ${r.code}`);
     node.warn(`Energietracker: ${d.failed} abgelehnt – ${bad.join(', ')}`);
   }
   return msg;
   ```

- `ET_TOKEN` als **Umgebungsvariable** des Flows oder der Gruppe setzen, nicht
  in den Knotentext — so landet er nicht im Flow-Export.
- Mit Home Assistant als Quelle (`node-red-contrib-home-assistant-websocket`)
  liefert je Sensor ein Knoten „current state“ den Wert statt `global.get`.
- Statt eines Stapels geht auch je Zähler eine Nachricht mit einem einzelnen
  Objekt als `msg.payload`; die Antwort ist dann `201` bzw. `200`.

---

## openHAB

Eine Regel in der Rules DSL (`conf/rules/energietracker.rules`) mit der
HTTP-Aktion `sendHttpPostRequest(url, contentType, content, headers, timeout)`.
Sie gibt die Antwort als Text zurück, im Fehlerfall `null`.

```java
val String ET_URL  = "http://192.168.178.10:8080/api.php/api/ingest"
val String ET_AUTH = "Bearer et_…"

rule "Energietracker: Zählerstand Strom senden"
when
    Time cron "0 55 23 * * ?"
then
    if (!(Stromzaehler_Total.state instanceof Number)) {   // NULL/UNDEF: nicht senden, nie 0
        logWarn("energietracker", "Stromzaehler_Total ohne gültigen Wert – ausgelassen")
        return;
    }
    val headers = newHashMap("Authorization" -> ET_AUTH)
    val today = now.toLocalDate.toString                  // JJJJ-MM-TT
    val value = (Stromzaehler_Total.state as Number).doubleValue
    val body = '{"utility":"strom","meter":"strom_haus","value":' + value + ',"date":"' + today + '"}'
    val resp = sendHttpPostRequest(ET_URL, "application/json", body, headers, 10000)
    if (resp === null || !resp.contains('"success":true')) {
        logError("energietracker", "Push fehlgeschlagen: " + resp)
    } else if (resp.contains('"suspect":true')) {
        logWarn("energietracker", "Wert kleiner als zuvor – in der App prüfen")
    }
end
```

- `Stromzaehler_Total` ist das Item mit dem absoluten Zählerstand; für weitere
  Zähler die Regel mit eigenem Item, `utility` und `meter` kopieren.
- Hat das Item eine Einheit (`Number:Energy`), vorher umrechnen:
  `(Stromzaehler_Total.state as QuantityType<Number>).toUnit("kWh").doubleValue`
  (Gas und Wasser `"m³"`).
- In JS Scripting heißt dieselbe Aktion `actions.HTTP.sendHttpPostRequest`.
- openHAB hat keinen Speicher für Geheimnisse in Regeln. Die Datei mit dem
  Token nur für den openHAB-Benutzer lesbar halten.

---

[← Kompendium-Index](../README.md) · [Home Assistant anbinden](home-assistant.md) ·
[API-Referenz](../referenz/api.md)
