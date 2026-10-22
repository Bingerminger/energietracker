// =====================================================================
// v3.7.0 (Posteingang) — ein Dokument auslesen und die Warteschlange.
//
// Auslesen in drei Schritten: Der Browser liest die Textebene (pdf.js), der
// Server erkennt die Art (classify) und liest dann die Felder dieser Art
// (extract). Zwei Anfragen statt einer: Jede bleibt unter dem Zeitlimit eines
// vorgeschalteten Webservers (nginx im Docker-Image: 310 s).
//
// Massenverarbeitung ohne Cron: Der Server merkt sich, was vorgemerkt ist
// (Status `queued`); die geöffnete Seite arbeitet Dokument für Dokument ab.
// Der Lauf gehört diesem Modul, nicht der Ansicht — er läuft weiter, wenn man
// in der App eine andere Seite öffnet, und wer den Posteingang später wieder
// öffnet, setzt fort, wo es stand. Abbrechen stellt Vorgemerktes zurück auf
// „neu“. Fortschritt meldet das Ereignis `et:inbox-queue`.
// =====================================================================

import { api } from '../api.js';
import { t } from './i18n.js';
import { openPdf, textPages } from './pdf.js';

/** Zeitlimit je Schritt im Browser: etwas über dem längsten des Servers (300 s). */
const STEP_TIMEOUT_MS = 330000;

/**
 * 3.7.1 — Warten hinter einem Webserver mit kurzer Zeitgrenze: Bricht er ab
 * (504 nach 60 s, etwa auf der Synology) oder reißt das Netz, rechnet PHP
 * weiter und speichert. Dann fragt die Oberfläche den Eintrag ab
 * (GET /api/inbox/{id}), bis der Schritt gespeichert ist — alle 5 s, höchstens
 * so lange, wie der Server für einen Schritt rechnen darf
 * (InboxController::READ_TIME_LIMIT_S, 660 s) —, und zeigt erst danach einen Fehler.
 */
export const WAIT = { intervalMs: 5000, limitMs: 660000 };

/** Abbruch vor der Antwort (504 des Webservers, Netz weg, Zeitlimit im Browser): Der Server rechnet vielleicht weiter. */
export const cutOff = (e) => e?.status === 504 || e?.code === 'network' || e?.code === 'timeout';

/**
 * Ist der Schritt gespeichert? Der Eintrag hat sich seit dem Absenden geändert
 * (`updated_at`) und steht danach so da: Art erkannt (`type`) bzw. ausgelesen
 * oder abgelegt — oder fehlgeschlagen. Sonst null (weiter warten).
 *
 * @returns {'done' | 'failed' | null}
 */
export function stepState(before, now, step) {
  if (!now || (now.updated_at || '') === (before?.updated_at || '')) return null;
  if (now.status === 'failed') return 'failed';
  if (step === 'type') return now.type ? 'done' : null;
  return ['read', 'done', 'filed'].includes(now.status) ? 'done' : null;
}

/**
 * Einen Schritt senden; nach einem Abbruch vor der Antwort (cutOff) den
 * Eintrag abfragen, bis er gespeichert ist. Gibt die Vorschau zurück, wie der
 * Schritt selbst; fehlgeschlagen: der Fehler, den der Server vermerkt hat;
 * nach der Obergrenze: „keine Antwort“ (`code: 'waited'`, mit dem Status des
 * Abbruchs — Netz weg beendet den Lauf, ein 504 nicht).
 */
export async function settle(send, before, step, { onWait = () => {}, wait = WAIT, sleep = (ms) => new Promise(r => setTimeout(r, ms)), now = () => Date.now() } = {}) {
  const started = now();
  try {
    return await send();
  } catch (e) {
    if (!cutOff(e)) throw e;
    onWait();
    while (now() - started < wait.limitMs) {
      await sleep(wait.intervalMs);
      let pv;
      try { pv = await api.inboxEntry(before.id); }
      catch (x) { if (!x?.status || x.status >= 500) continue; throw x; }   // Netz oder Webserver noch weg: weiter warten
      const s = stepState(before, pv?.entry, step);
      if (s === 'done') return pv;
      if (s === 'failed') {
        const err = pv.entry.error || {};
        throw Object.assign(new Error(t(err.key || 'errors.inbox.failed', err.params || {})), { status: 500, code: err.key || 'errors.inbox.failed' });
      }
    }
    throw Object.assign(new Error(t('inbox.wait.gaveUp')), { status: e.status, code: 'waited' });
  }
}

/**
 * Ein Dokument auslesen. `onStep('text' | 'type' | 'fields' | 'wait')` meldet
 * den Schritt; `cancelled()` beendet nach dem laufenden Schritt. `pages`: die
 * Textebene schon gelesen (Tests), sonst liest sie pdf.js.
 *
 * @returns {Promise<object>} Vorschau (wie GET /api/inbox/{id})
 */
export async function readDocument(entry, { consent = false, fresh = false, onStep = () => {}, cancelled = () => false, pages = null, wait = WAIT, sleep, now } = {}) {
  onStep('text');
  if (!pages) {
    const doc = await openPdf(api.inboxFileUrl(entry.attachment_id));
    pages = await textPages(doc);
    doc.destroy?.();
  }
  if (cancelled()) return null;
  const opts = { onWait: () => onStep('wait'), wait, ...(sleep ? { sleep } : {}), ...(now ? { now } : {}) };
  onStep('type');
  let pv = await settle(() => api.inboxClassify(entry.id, pages, { consent, fresh, timeoutMs: STEP_TIMEOUT_MS }), entry, 'type', opts);
  if (cancelled() || pv?.recognize_only) return pv;
  onStep('fields');
  const before = pv?.entry || entry;
  pv = await settle(() => api.inboxExtract(entry.id, pages, { consent, fresh, timeoutMs: STEP_TIMEOUT_MS }), before, 'fields', opts);
  return pv;
}

const state = { running: false, total: 0, done: 0, failed: 0, current: null, step: null, stop: false };

function emit() {
  window.dispatchEvent(new CustomEvent('et:inbox-queue', { detail: queueProgress() }));
}

/** Stand des Laufs (für die Anzeige). */
export function queueProgress() {
  return { running: state.running, total: state.total, done: state.done, failed: state.failed, current: state.current, step: state.step };
}

/**
 * v3.7.0 (cloud) — Was eine Zustimmung für einen Lauf abdeckt: die Dokumente,
 * die beim Fragen anstehen — bei „Alle auslesen“ neue, fehlgeschlagene und
 * vorgemerkte, beim Fortsetzen die vorgemerkten (jeweils mit PDF). Der Dialog
 * nennt ihre Zahl („12 Dokumente gehen an …“), der Lauf liest genau diese; was
 * danach dazukommt, fragt der nächste Lauf. Gemerkt wird nichts.
 *
 * @returns {{ids: Set<string>, count: number}}
 */
export function runScope(entries, { resume = false } = {}) {
  const statuses = resume ? ['queued'] : ['new', 'failed', 'queued'];
  const ids = (entries || []).filter(e => statuses.includes(e.status) && e.attachment_id).map(e => e.id);
  return { ids: new Set(ids), count: ids.length };
}

/**
 * Warteschlange abarbeiten, bis nichts mehr vorgemerkt ist oder abgebrochen
 * wird. Ein Fehler beim einzelnen Dokument (der Server vermerkt ihn) hält den
 * Lauf nicht auf; ist der Server nicht erreichbar, endet er (die Vormerkung
 * bleibt — später fortsetzen).
 *
 * `consent`: die Zustimmung zu einem Anbieter außerhalb des Heimnetzes, einmal
 * für diesen Lauf gegeben; sie geht mit jedem Aufruf mit (`consent: true`) und
 * gilt nur für `only` (runScope) — vorgemerkt Neues bleibt für den nächsten
 * Lauf liegen. `read` ist für Tests ersetzbar.
 */
export async function runQueue({ consent = false, only = null, read = readDocument } = {}) {
  if (state.running) return queueProgress();
  Object.assign(state, { running: true, stop: false, done: 0, failed: 0, current: null, step: null });
  try {
    let data = await api.inbox();
    state.total = only ? only.size : (data.queue?.queued ?? 0);
    emit();
    // im Browser gescheitert (PDF nicht lesbar): bleibt vorgemerkt, in diesem Lauf nicht noch einmal
    const skipped = new Set();
    while (!state.stop) {
      const entry = nextQueued(data.entries, skipped, only);
      if (!entry) break;
      state.current = entry.name || entry.id;
      emit();
      try {
        await read(entry, { consent, onStep: (s) => { state.step = s; emit(); }, cancelled: () => state.stop });
        state.done++;
      } catch (e) {
        state.failed++;
        if (e?.status === 0 || e?.code === 'unsupported') break;   // Netz weg oder Browser ohne pdf.js: später weiter
        // im Browser gescheitert, oder nach dem Warten keine Antwort (der Eintrag bliebe vorgemerkt): nicht noch einmal
        if (!e?.status || e?.code === 'waited') skipped.add(entry.id);
      }
      emit();
      data = await api.inbox();
      if (!only) state.total = Math.max(state.total, state.done + state.failed + (data.queue?.queued ?? 0));
    }
  } finally {
    Object.assign(state, { running: false, current: null, step: null });
    emit();
  }
  return queueProgress();
}

/** Nächstes vorgemerktes Dokument in der Reihenfolge des Vormerkens (mit `only`: nur aus diesen). */
export function nextQueued(entries, skipped = new Set(), only = null) {
  return (entries || []).filter(e => e.status === 'queued' && !skipped.has(e.id) && (!only || only.has(e.id)))
    .sort((a, b) => String(a.queued_at || '').localeCompare(String(b.queued_at || '')))[0] || null;
}

/** Abbrechen: nach dem laufenden Schritt aufhören, Vorgemerktes wieder auf „neu“. */
export async function cancelQueue() {
  state.stop = true;
  const r = await api.inboxCancelQueue();
  emit();
  return r;
}
