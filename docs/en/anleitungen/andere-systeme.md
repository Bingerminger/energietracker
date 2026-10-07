# Meter readings from ioBroker, Node-RED and openHAB

[Deutsch](../../anleitungen/andere-systeme.md) · **English**

[← Compendium index](../README.md)

Home Assistant is not the only system that knows meter readings. Anything that
can send an HTTP request can deliver to Energietracker through the same intake,
`POST /api/ingest`. This page shows the common contract and one recipe each for
ioBroker, Node-RED and openHAB. Home Assistant has its own guide:
[Connect Home Assistant](home-assistant.md).

The examples use `192.168.178.10:8080` as the address — put in yours.

---

## The common contract

**Preparation in Energietracker** (as for Home Assistant, steps 1 and 2):

- Under **Settings → Integrations → 🏠 Home Assistant integration**, generate a
  **token** (`et_…`). Without sign-in it is optional, with sign-in switched on
  mandatory. It only works for the push — an API key (`etk_…`) is something
  else.
- There, give each meter an **alias**, such as `strom_haus`. The internal meter
  ID works too.

**The request:**

| | |
|---|---|
| Address | `http://<host>:<port>/api.php/api/ingest` — always works; `…/api/ingest` only with a rewrite rule (both in the Docker image) |
| Method | `POST` |
| Headers | `Content-Type: application/json`; `Authorization: Bearer et_…` once a token exists |
| Body, single | `{"utility": "strom", "meter": "strom_haus", "value": 12345.6, "date": "2026-10-07"}` — `value` is also called `counter`; `date` is optional (default: today) |
| Body, batch | a list of such objects or `{"readings": [...]}`, at most 500 (since v3.1.0) |

**The response** is wrapped in `{"success": true, "data": …}`:

- Single: `201` (new) or `200` (updated on the same day) with `status`,
  `utility`, `meter_id`, `date`, `counter`, `reading_id` and `suspect`. If
  `suspect: true`, the value was lower than the previous one — stored, but not
  counted until you confirm it in the app.
- Batch: `200` even with partial errors, with `results` (per entry `index`,
  `status` = `created`/`updated`/`error`, for errors `code`) and the totals
  `created`, `updated`, **`failed`**. Check `failed`.
- Errors: `400` (unknown meter, not a number, invalid date, heating oil or
  pellets), `401` (token missing or wrong). Scripts evaluate `code`, not the
  text. All fields: [API reference](../referenz/api.md#batch-v310).

**Rules that apply everywhere:**

- Only send the **absolute meter reading**, not the daily consumption.
- **Once a day is enough.** The intake replaces the reading of the same meter on
  the same day instead of adding a second one.
- **If a value is missing, send nothing — never 0.** A 0 ends up as a suspect
  reading in the app; a skipped day costs nothing.
- **Units** as for the utility: electricity, district heating and PV in kWh, gas
  and water in m³. Heating oil and pellets work with deliveries and are not
  possible here.
- Record a **meter swap** in Energietracker, not in the push.

---

## ioBroker

Easiest with the **JavaScript adapter** (from version 7.9 with `httpPost`).
Create a script under “Scripts”:

```js
// Energietracker: send meter readings daily at 23:55 (javascript adapter ≥ 7.9)
const ET_URL   = 'http://192.168.178.10:8080/api.php/api/ingest';
const ET_TOKEN = 'et_…';   // Energietracker → Settings → Integrations → Generate token
const METERS = [
  { utility: 'strom', meter: 'strom_haus', state: 'smartmeter.0.1-0:1_8_0__255.value' },
  { utility: 'gas',   meter: 'gas_haus',   state: '0_userdata.0.gaszaehler_m3' },
];

schedule('55 23 * * *', () => {
  const date = formatDate(new Date(), 'YYYY-MM-DD');
  for (const m of METERS) {
    const s = getState(m.state);
    const value = s && s.val !== null && s.val !== '' ? Number(s.val) : NaN;
    if (!Number.isFinite(value)) {                         // never send 0
      log(`Energietracker: ${m.state} has no valid value – skipped today`, 'warn');
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
          log(`Energietracker ${m.meter}: value lower than before – check in the app`, 'warn');
        }
      });
  }
});
```

- Replace the data points under `state` with yours — it has to be the absolute
  meter reading.
- **Blockly:** blocks “schedule” (cron `55 23 * * *`), “get value of object ID”
  and “if” (is a number); the call itself goes into a “function” block with the
  `httpPost` from above. There is no reliable standard block for a POST with a
  token.
- The `rest-api` adapter does not help here — it serves ioBroker data instead of
  sending it.
- ioBroker has no store of its own for secrets in scripts. Better keep the token
  in a data point under `0_userdata.0` with restricted rights than in the script
  text.

---

## Node-RED

Four nodes: **inject → function → http request → function**.

1. **inject:** “repeat” at a specific time, daily at 23:55.
2. **function** “meter readings → batch” — builds one batch for all meters:

   ```js
   // adjust the source of the values: global context variables here
   const meters = [
     { utility: 'strom', meter: 'strom_haus', value: global.get('stromzaehler_kwh') },
     { utility: 'gas',   meter: 'gas_haus',   value: global.get('gaszaehler_m3') },
   ];
   const date = new Date().toLocaleDateString('sv-SE');   // YYYY-MM-DD in local time
   const readings = [];
   for (const m of meters) {
     const v = Number(m.value);
     if (m.value === undefined || m.value === null || m.value === '' || !Number.isFinite(v)) {
       node.warn(`${m.meter}: no valid value – skipped`);   // never send 0
       continue;
     }
     readings.push({ utility: m.utility, meter: m.meter, value: v, date });
   }
   if (readings.length === 0) return null;
   msg.payload = { readings };
   msg.headers = { Authorization: 'Bearer ' + env.get('ET_TOKEN') };
   return msg;
   ```

3. **http request:** method `POST`, URL
   `http://192.168.178.10:8080/api.php/api/ingest`, return “a parsed JSON
   object”. If `msg.payload` is an object, the node sends it as JSON.
4. **function** “check failed”:

   ```js
   const d = msg.payload && msg.payload.data;
   if (msg.statusCode !== 200 || !d) {
     node.error(`Energietracker: HTTP ${msg.statusCode} ${(msg.payload && msg.payload.code) || ''}`, msg);
     return null;
   }
   if (d.failed > 0) {
     const bad = d.results.filter(r => r.status === 'error').map(r => `#${r.index} ${r.code}`);
     node.warn(`Energietracker: ${d.failed} rejected – ${bad.join(', ')}`);
   }
   return msg;
   ```

- Set `ET_TOKEN` as an **environment variable** of the flow or group, not in
  the node text — that way it does not end up in a flow export.
- With Home Assistant as the source (`node-red-contrib-home-assistant-websocket`)
  a “current state” node per sensor delivers the value instead of `global.get`.
- Instead of a batch, one message per meter with a single object as
  `msg.payload` works too; the response is then `201` or `200`.

---

## openHAB

A rule in the Rules DSL (`conf/rules/energietracker.rules`) with the HTTP action
`sendHttpPostRequest(url, contentType, content, headers, timeout)`. It returns
the response as text, `null` on failure.

```java
val String ET_URL  = "http://192.168.178.10:8080/api.php/api/ingest"
val String ET_AUTH = "Bearer et_…"

rule "Energietracker: send electricity meter reading"
when
    Time cron "0 55 23 * * ?"
then
    if (!(Stromzaehler_Total.state instanceof Number)) {   // NULL/UNDEF: do not send, never 0
        logWarn("energietracker", "Stromzaehler_Total has no valid value – skipped")
        return;
    }
    val headers = newHashMap("Authorization" -> ET_AUTH)
    val today = now.toLocalDate.toString                  // YYYY-MM-DD
    val value = (Stromzaehler_Total.state as Number).doubleValue
    val body = '{"utility":"strom","meter":"strom_haus","value":' + value + ',"date":"' + today + '"}'
    val resp = sendHttpPostRequest(ET_URL, "application/json", body, headers, 10000)
    if (resp === null || !resp.contains('"success":true')) {
        logError("energietracker", "Push failed: " + resp)
    } else if (resp.contains('"suspect":true')) {
        logWarn("energietracker", "Value lower than before – check in the app")
    }
end
```

- `Stromzaehler_Total` is the item holding the absolute meter reading; for more
  meters, copy the rule with its own item, `utility` and `meter`.
- If the item has a unit (`Number:Energy`), convert first:
  `(Stromzaehler_Total.state as QuantityType<Number>).toUnit("kWh").doubleValue`
  (gas and water `"m³"`).
- In JS Scripting the same action is called `actions.HTTP.sendHttpPostRequest`.
- openHAB has no store for secrets in rules. Keep the file with the token
  readable only for the openHAB user.

---

[← Compendium index](../README.md) · [Connect Home Assistant](home-assistant.md) ·
[API reference](../referenz/api.md)
