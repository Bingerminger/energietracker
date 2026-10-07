// =====================================================================
// Offline-Warteschlange (v3.1.0, Paket H2, FE-32) — ohne Browser.
//
//   node tests/outbox.test.mjs
//
// Speicher im Arbeitsspeicher, API als Attrappe mit dem Verhalten des
// Servers (client_ref → kein zweiter Stand).
// =====================================================================

import { useStore, memoryStore, enqueue, list, count, flush, resolve, retry, remove } from '../public/js/lib/outbox.js';

let failed = 0, passed = 0;
function ok(cond, label) {
  if (cond) { passed++; return; }
  failed++;
  console.log(`  ✗ ${label}`);
}

const netErr = () => Object.assign(new Error('offline'), { status: 0, code: 'network' });
const httpErr = (status, msg = 'HTTP ' + status) => Object.assign(new Error(msg), { status });

function fakeApi() {
  const db = { gas: [], strom: [] };
  const api = {
    online: true, calls: [], uploads: 0, failNext: null,
    async readings(u, meterId) { if (!api.online) throw netErr(); return db[u].filter(r => r.meter_id === meterId); },
    async createReading(u, data) {
      api.calls.push(['POST', u, data]);
      if (!api.online) throw netErr();
      if (api.failNext) { const e = api.failNext; api.failNext = null; throw e; }
      const dup = db[u].find(r => r.client_ref && r.client_ref === data.client_ref && r.meter_id === data.meter_id);
      if (dup) return { ...dup, duplicate: true };
      const r = { id: 'r' + (db[u].length + 1), ...data };
      db[u].push(r);
      return r;
    },
    async updateReading(u, id, data) {
      api.calls.push(['PATCH', u, id, data]);
      const r = db[u].find(x => x.id === id);
      Object.assign(r, data);
      return r;
    },
    async uploadAttachment(blob, kind) { if (!api.online) throw netErr(); api.uploads++; return { id: 'att_000000000000000' + api.uploads, kind }; },
  };
  return { api, db };
}

const entry = (over = {}) => ({ utility: 'gas', meter_id: 'm1', date: '2026-01-0' + (over.day ?? 1), counter: 100, ...over });

// 1. Reihenfolge, offline bleibt alles, online kommt alles genau einmal an
{
  useStore(memoryStore());
  const { api, db } = fakeApi();
  await enqueue(entry({ day: 1, counter: 100 }));
  await new Promise(r => setTimeout(r, 2));
  await enqueue(entry({ day: 2, counter: 110 }));
  await new Promise(r => setTimeout(r, 2));
  await enqueue(entry({ day: 3, counter: 120 }));
  ok(await count() === 3, '3 warten');

  api.online = false;
  let r = await flush(api);
  ok(r.stopped === 'offline' && r.sent === 0, 'offline: Senden endet');
  ok(await count() === 3, 'offline: weiter 3');
  ok((await list())[0].attempts === 1, 'Versuch gezählt');

  api.online = true;
  r = await flush(api);
  ok(r.sent === 3, 'online: 3 gesendet');
  ok(await count() === 0, 'Warteschlange leer');
  ok(db.gas.map(x => x.counter).join() === '100,110,120', 'in Reihenfolge');
  ok(db.gas.every(x => typeof x.client_ref === 'string' && x.client_ref.length >= 8), 'client_ref mitgeschickt');
}

// 2. Zwei Tabs senden gleichzeitig: kein doppelter Stand
{
  useStore(memoryStore());
  const { api, db } = fakeApi();
  const e = await enqueue(entry());
  await Promise.all([flush(api), flush(api)]);
  ok(db.gas.length === 1, 'gleichzeitiges Senden im selben Tab: ein Stand');
  // zweiter Tab mit eigener Kopie desselben Eintrags (dieselbe client_ref)
  await api.createReading('gas', { meter_id: 'm1', date: e.date, counter: e.counter, client_ref: e.client_ref });
  ok(db.gas.length === 1, 'zweiter Tab: der Server erkennt die client_ref');
}

// 3. 4xx bleibt mit Grund, die anderen gehen trotzdem
{
  useStore(memoryStore());
  const { api, db } = fakeApi();
  await enqueue(entry({ day: 1 }));
  await new Promise(r => setTimeout(r, 2));
  await enqueue(entry({ day: 2 }));
  api.failNext = httpErr(400, 'Zähler nicht gefunden');
  const r = await flush(api);
  ok(r.failed === 1 && r.sent === 1, '4xx: einer bleibt, der andere geht');
  const left = await list();
  ok(left.length === 1 && left[0].status === 'failed' && left[0].last_error === 'Zähler nicht gefunden', 'Grund steht am Eintrag');
  ok((await flush(api)).sent === 0, 'fehlgeschlagen wird nicht von selbst wiederholt');
  await retry(left[0].client_ref, { counter: 105 });
  ok((await flush(api)).sent === 1 && db.gas.some(x => x.counter === 105), 'nach dem Bearbeiten gesendet');
}

// 4. 401 hält an
{
  useStore(memoryStore());
  const { api } = fakeApi();
  await enqueue(entry({ day: 1 }));
  await enqueue(entry({ day: 2 }));
  api.failNext = httpErr(401);
  const r = await flush(api);
  ok(r.stopped === 'auth' && await count() === 2, '401: Senden endet, beide bleiben');
  ok((await list()).every(e => e.status === 'waiting'), '401: Einträge bleiben wartend');
}

// 5. Konflikt: am selben Tag schon ein Stand (Home Assistant)
{
  useStore(memoryStore());
  const { api, db } = fakeApi();
  db.gas.push({ id: 'ha1', meter_id: 'm1', date: '2026-01-01', counter: 99, source: 'home_assistant' });
  const e = await enqueue(entry({ day: 1, counter: 100 }));
  let r = await flush(api);
  ok(r.conflicts === 1 && db.gas.length === 1, 'Konflikt: nichts gesendet');
  const c = (await list())[0];
  ok(c.status === 'conflict' && c.conflict.id === 'ha1' && c.conflict.counter === 99, 'Konflikt nennt den vorhandenen Stand');

  await resolve(e.client_ref, 'replace');
  r = await flush(api);
  ok(r.sent === 1 && db.gas.length === 1 && db.gas[0].counter === 100, 'ersetzen: der vorhandene Stand wird überschrieben');

  const e2 = await enqueue(entry({ day: 1, counter: 101 }));
  await flush(api);
  await resolve(e2.client_ref, 'keep');
  ok(await count() === 0 && db.gas[0].counter === 100, 'behalten: Eintrag verworfen, Stand unverändert');
}

// 6. Ein eigener, schon angekommener Stand (Antwort verloren) ist kein Konflikt
{
  useStore(memoryStore());
  const { api, db } = fakeApi();
  const e = await enqueue(entry({ day: 1 }));
  db.gas.push({ id: 'r9', meter_id: 'm1', date: e.date, counter: e.counter, client_ref: e.client_ref });
  const r = await flush(api);
  ok(r.sent === 1 && r.conflicts === 0 && db.gas.length === 1, 'eigene client_ref: kein Konflikt, kein Doppel');
}

// 7. Foto zuerst hochladen, einmal
{
  useStore(memoryStore());
  const { api, db } = fakeApi();
  await enqueue(entry({ photo_blob: { size: 10, type: 'image/jpeg' } }));
  api.online = false;
  await flush(api);
  api.online = true;
  await flush(api);
  ok(api.uploads === 1, 'Foto genau einmal hochgeladen');
  ok(db.gas[0].attachment_id === 'att_0000000000000001', 'Stand trägt den Beleg');
}

// 7b. v3.1.0 (H3) — Verbrauch je Zeitraum: ohne Tageskonflikt, mit client_ref
{
  useStore(memoryStore());
  const { api } = fakeApi();
  const sent = [];
  api.createPeriod = async (u, data) => { sent.push([u, data]); return { id: 'p1', ...data }; };
  await enqueue({ kind: 'period', utility: 'gas', meter_id: 'm9', date: '2026-01-01', month: '2026-01', counter: 812, reference: { average_user: 900 } });
  const r = await flush(api);
  ok(r.sent === 1 && sent.length === 1, 'Zeitraum nachgesendet');
  ok(sent[0][1].month === '2026-01' && sent[0][1].value === 812 && sent[0][1].reference.average_user === 900
    && typeof sent[0][1].client_ref === 'string', 'Monat, Wert, Vergleichswerte und client_ref');
}

// 8. Verwerfen
{
  useStore(memoryStore());
  const e = await enqueue(entry());
  await remove(e.client_ref);
  ok(await count() === 0, 'verworfen');
}

console.log(`outbox: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
