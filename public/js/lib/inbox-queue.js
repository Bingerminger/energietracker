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
import { openPdf, textPages } from './pdf.js';

/** Zeitlimit je Schritt im Browser: etwas über dem längsten des Servers (300 s). */
const STEP_TIMEOUT_MS = 330000;

/**
 * Ein Dokument auslesen. `onStep('text' | 'type' | 'fields')` meldet den
 * Schritt; `cancelled()` beendet nach dem laufenden Schritt.
 *
 * @returns {Promise<object>} Vorschau (wie GET /api/inbox/{id})
 */
export async function readDocument(entry, { consent = false, fresh = false, onStep = () => {}, cancelled = () => false } = {}) {
  onStep('text');
  const doc = await openPdf(api.inboxFileUrl(entry.attachment_id));
  const pages = await textPages(doc);
  doc.destroy?.();
  if (cancelled()) return null;
  onStep('type');
  let pv = await api.inboxClassify(entry.id, pages, { consent, fresh, timeoutMs: STEP_TIMEOUT_MS });
  if (cancelled() || pv?.recognize_only) return pv;
  onStep('fields');
  pv = await api.inboxExtract(entry.id, pages, { consent, fresh, timeoutMs: STEP_TIMEOUT_MS });
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
        if (!e?.status) skipped.add(entry.id);
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
