// =====================================================================
// Energietracker v3.1.0 — Offline-Warteschlange für Zählerstände
// (Paket H2, Review FE-32 / MKT-08)
//
// Im Keller ohne Netz scheiterte jedes Speichern, und die Eingabe war weg.
// Jetzt landet ein Stand, der wegen fehlender Verbindung nicht ankommt, in
// IndexedDB (`et-outbox`, Store `readings`, Schlüssel `client_ref`) und wird
// nachgesendet: beim `online`-Ereignis, wenn der Server wieder antwortet
// (`et:online`), beim App-Start und wenn die Seite wieder sichtbar wird.
//
// Regeln beim Nachsenden, der Reihe nach:
//   - 2xx → aus der Warteschlange (der Server erkennt ein zweites Senden
//     derselben client_ref und legt keinen zweiten Stand an)
//   - kein Netz, Zeitlimit, 5xx → bleibt, Senden endet für diesmal
//   - 401 → Senden endet; die Shell zeigt die Anmeldung
//   - andere 4xx → bleibt als „nicht gespeichert" mit Grund
//   - am selben Tag gibt es schon einen anderen Stand (Home Assistant, zweites
//     Gerät) → „Konflikt": ersetzen oder behalten entscheidet der Nutzer
//
// Ein Foto liegt als Blob im Eintrag und wird vor dem Stand hochgeladen.
// Der Speicher ist austauschbar (`useStore`), damit der Node-Test ohne
// IndexedDB läuft.
// =====================================================================

const DB = 'et-outbox';
const STORE = 'readings';

/** @typedef {{client_ref:string, utility:string, meter_id:string, meter_label?:string, unit?:string, date:string, counter:number, note?:string, is_estimated?:boolean, created_at:string, attempts:number, status:'waiting'|'failed'|'conflict', last_error?:string|null, conflict?:{id:string, counter:number}|null, resolve?:'replace'|null, photo_blob?:Blob|null, attachment_id?:string|null}} OutboxEntry */

let store = null;
const listeners = new Set();
let running = null;

/** Speicher im Arbeitsspeicher (Tests, Browser ohne IndexedDB). */
export function memoryStore() {
  const m = new Map();
  return {
    all: async () => [...m.values()].map(v => ({ ...v })),
    put: async (e) => { m.set(e.client_ref, { ...e }); },
    del: async (ref) => { m.delete(ref); },
  };
}

function idbStore() {
  const open = () => new Promise((resolve, reject) => {
    const req = indexedDB.open(DB, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'client_ref' });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  let dbp = null;
  const tx = async (mode, fn) => {
    dbp ??= open();
    const db = await dbp;
    return new Promise((resolve, reject) => {
      const t = db.transaction(STORE, mode);
      const r = fn(t.objectStore(STORE));
      t.oncomplete = () => resolve(r?.result);
      t.onerror = () => reject(t.error);
    });
  };
  return {
    all: () => tx('readonly', s => s.getAll()),
    put: (e) => tx('readwrite', s => s.put(e)),
    del: (ref) => tx('readwrite', s => s.delete(ref)),
  };
}

/** Speicher setzen (Tests); ohne Aufruf IndexedDB, wo vorhanden. */
export function useStore(s) { store = s; }

function db() {
  if (!store) store = typeof indexedDB !== 'undefined' ? idbStore() : memoryStore();
  return store;
}

/** Neue Kennung für eine Erfassung (auch für das erste, direkte Senden). */
export function newClientRef() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return 'r' + Date.now().toString(16) + Math.random().toString(16).slice(2, 10);
}

/** Bei jeder Änderung: `fn(count)`. Liefert die Abmeldung. */
export function onChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

async function changed() {
  const n = await count();
  for (const fn of listeners) { try { fn(n); } catch { /* Ansicht weg */ } }
  if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('et:outbox', { detail: n }));
}

/** @returns {Promise<OutboxEntry[]>} älteste zuerst */
export async function list() {
  const all = (await db().all()) || [];
  return all.sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
}

export async function count() {
  return (await list()).length;
}

/** Einen Stand einreihen. `entry.client_ref` bleibt, falls schon gesendet wurde. */
export async function enqueue(entry) {
  const e = {
    client_ref: entry.client_ref || newClientRef(),
    created_at: new Date().toISOString(),
    attempts: 0, status: 'waiting', last_error: null, conflict: null, resolve: null,
    ...entry,
  };
  await db().put(e);
  await changed();
  return e;
}

export async function remove(ref) {
  await db().del(ref);
  await changed();
}

/** Konflikt auflösen: 'replace' = vorhandenen Stand ersetzen, 'keep' = den vorhandenen behalten. */
export async function resolve(ref, choice) {
  const e = (await list()).find(x => x.client_ref === ref);
  if (!e) return;
  if (choice === 'keep') { await remove(ref); return; }
  await db().put({ ...e, status: 'waiting', resolve: 'replace', last_error: null });
  await changed();
}

/** Nach einem Fehler wieder senden lassen (z. B. nach dem Bearbeiten). */
export async function retry(ref, patch = {}) {
  const e = (await list()).find(x => x.client_ref === ref);
  if (!e) return;
  await db().put({ ...e, ...patch, status: 'waiting', last_error: null, conflict: null });
  await changed();
}

const transient = (err) => err?.code === 'network' || err?.code === 'timeout' || (err?.status ?? 0) >= 500;

/**
 * Alles Wartende senden, der Reihe nach. Läuft je Tab nur einmal gleichzeitig.
 *
 * @param {{createReading:Function, updateReading:Function, readings:Function, uploadAttachment:Function}} api
 * @returns {Promise<{sent:number, failed:number, conflicts:number, stopped:string|null}>}
 */
export function flush(api) {
  if (running) return running;
  running = (async () => {
    const res = { sent: 0, failed: 0, conflicts: 0, stopped: null };
    try {
      for (const e of await list()) {
        if (e.status !== 'waiting') continue;
        try {
          // 1. Foto zuerst — einmal hochgeladen, merkt sich der Eintrag die ID
          if (e.photo_blob && !e.attachment_id) {
            const att = await api.uploadAttachment(e.photo_blob, 'reading_photo');
            e.attachment_id = att.id;
            e.photo_blob = null;
            await db().put(e);
          }
          // v3.1.0 (H3) — Verbrauch je Zeitraum: kein Konfliktvergleich am Tag;
          // eine Überlappung lehnt der Server ab (bleibt als „nicht gespeichert")
          if (e.kind === 'period') {
            await api.createPeriod(e.utility, { meter_id: e.meter_id, month: e.month, value: e.counter, note: e.note || '',
              is_estimated: !!e.is_estimated, ...(e.reference ? { reference: e.reference } : {}), client_ref: e.client_ref });
            await db().del(e.client_ref);
            res.sent++;
            continue;
          }
          const data = { meter_id: e.meter_id, date: e.date, counter: e.counter, note: e.note || '', is_estimated: !!e.is_estimated };
          if (e.attachment_id) data.attachment_id = e.attachment_id;
          // 2. Am selben Tag schon ein anderer Stand? Nicht still doppeln.
          const existing = ((await api.readings(e.utility, e.meter_id)) || [])
            .find(r => r.date === e.date && r.client_ref !== e.client_ref);
          if (existing && e.resolve !== 'replace') {
            await db().put({ ...e, status: 'conflict', conflict: { id: existing.id, counter: existing.counter } });
            res.conflicts++;
            continue;
          }
          if (existing) await api.updateReading(e.utility, existing.id, data);
          else await api.createReading(e.utility, { ...data, client_ref: e.client_ref });
          await db().del(e.client_ref);
          res.sent++;
        } catch (err) {
          if (err?.status === 401) { res.stopped = 'auth'; break; }
          if (transient(err)) {
            await db().put({ ...e, attempts: (e.attempts || 0) + 1, last_error: err?.message || null });
            res.stopped = 'offline';
            break;
          }
          await db().put({ ...e, status: 'failed', attempts: (e.attempts || 0) + 1, last_error: err?.message || String(err) });
          res.failed++;
        }
      }
    } finally {
      running = null;
      await changed();
    }
    return res;
  })();
  return running;
}

/**
 * Auslöser anmelden (einmal, beim App-Start). `onResult` bekommt das Ergebnis
 * jedes Durchlaufs, in dem etwas gesendet wurde.
 */
export function startOutbox(api, onResult = () => {}) {
  const go = async () => {
    // nur, wenn etwas wartet — `et:online` kommt nach jeder erfolgreichen Antwort
    if (!(await list().catch(() => [])).some(e => e.status === 'waiting')) return;
    const r = await flush(api).catch(() => null);
    if (r && (r.sent || r.failed || r.conflicts)) onResult(r);
  };
  window.addEventListener('online', go);
  window.addEventListener('et:online', go);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') go(); });
  go();
  changed().catch(() => {});
}
