// =====================================================================
// v3.4.0 (N1021) — Blättern in langen Listen.
//
// Lang werden Listen nur mit täglicher Quelle: Zählerstände aus Home
// Assistant oder evcc (365 im Jahr), und „Alle Jahre“ in der Ablesungsliste.
// Alles andere bleibt unter rund 50 Zeilen — deshalb erscheint die Leiste erst
// ab mehr als PAGER_THRESHOLD Zeilen; bis dahin bleibt die Liste, wie sie war.
//
// Auf dem Gerät, nicht auf dem Server: Die Ansicht braucht ohnehin alle Stände
// (Jahreswahl, „letzte Ablesung“, Plausibilität im Dialog).
//
// Die Seitengröße gilt je Liste und Gerät (localStorage, try/catch wie
// lib/i18n.js); die Seite selbst merkt sich niemand: Wer wiederkommt, steht
// auf Seite 1 bei den neuesten Einträgen.
// =====================================================================

import { t } from '../lib/i18n.js';
import { fmt, escapeHtml } from '../lib/format.js';

export const PAGE_SIZES = [25, 50, 100];
export const DEFAULT_PAGE_SIZE = 25;
/** Blätterleiste erst ab mehr als so vielen Zeilen. */
export const PAGER_THRESHOLD = 50;

const STORE = 'et-page-size:';
// Ohne Speicher (privates Fenster, gesperrt) gilt die Wahl bis zum Neuladen
const memory = new Map();

/** Gemerkte Seitengröße einer Liste (sonst 25). */
export function pageSize(listKey) {
  let n = null;
  try { n = Number(localStorage.getItem(STORE + listKey)); } catch { /* kein Speicher */ }
  if (PAGE_SIZES.includes(n)) return n;
  return memory.get(listKey) ?? DEFAULT_PAGE_SIZE;
}

/** Seitengröße merken (nur erlaubte Werte). */
export function savePageSize(listKey, n) {
  if (!PAGE_SIZES.includes(n)) return;
  memory.set(listKey, n);
  try { localStorage.setItem(STORE + listKey, String(n)); } catch { /* privates Fenster: nur bis zum Neuladen */ }
}

/**
 * Ausschnitt einer Liste. Bis zur Schwelle alles auf einer Seite
 * (`active: false`), darüber Seiten zu `size` Zeilen; `page` wird in die
 * gültigen Grenzen geklemmt (etwa nach dem Löschen der letzten Zeile).
 * @returns {{items: any[], page: number, pages: number, size: number, from: number, to: number, total: number, active: boolean}}
 */
export function paginate(items, { page = 1, size = DEFAULT_PAGE_SIZE, threshold = PAGER_THRESHOLD } = {}) {
  const list = items || [];
  const total = list.length;
  if (total <= threshold) {
    return { items: [...list], page: 1, pages: 1, size, from: total ? 1 : 0, to: total, total, active: false };
  }
  const pages = Math.ceil(total / size);
  const p = Math.min(Math.max(1, Math.trunc(Number(page)) || 1), pages);
  const start = (p - 1) * size;
  const slice = list.slice(start, start + size);
  return { items: slice, page: p, pages, size, from: start + 1, to: start + slice.length, total, active: true };
}

/** Blätterleiste zu `paginate()`; leer, solange die Liste kurz ist. */
export function pagerHtml(p, { listKey }) {
  if (!p?.active) return '';
  const btn = (dir, disabled) => `<button type="button" class="btn btn--ghost btn--sm" data-pager-go="${dir}"${disabled ? ' disabled' : ''}>`
    + (dir === 'prev' ? `<span aria-hidden="true">‹</span> ${escapeHtml(t('pager.prev'))}` : `${escapeHtml(t('pager.next'))} <span aria-hidden="true">›</span>`)
    + '</button>';
  return `<nav class="pager" aria-label="${escapeHtml(t('pager.label'))}" data-pager="${escapeHtml(listKey)}">
    ${btn('prev', p.page <= 1)}
    <span class="pager__range" aria-live="polite">${escapeHtml(t('pager.range', { from: fmt.int(p.from), to: fmt.int(p.to), total: fmt.int(p.total) }))}</span>
    ${btn('next', p.page >= p.pages)}
    <label class="pager__size">${escapeHtml(t('pager.size'))}
      <select class="select" data-pager-size>${PAGE_SIZES.map(n => `<option value="${n}"${n === p.size ? ' selected' : ''}>${n}</option>`).join('')}</select>
    </label>
  </nav>`;
}

/**
 * Knöpfe und Auswahl der Leiste in `root` binden. `onChange({page, size})`
 * zeichnet die Liste neu; danach steht der Fokus wieder auf dem bedienten
 * Element. Eine neue Seitengröße behält die oberste sichtbare Zeile im Blick.
 */
export function wirePager(root, p, { listKey, onChange }) {
  const nav = [...(root?.querySelectorAll('[data-pager]') || [])].find(n => n.dataset.pager === listKey);
  if (!nav || !p?.active) return;
  const refocus = (sel) => {
    const again = [...root.querySelectorAll('[data-pager]')].find(n => n.dataset.pager === listKey);
    const el = again?.querySelector(sel);
    (el && !el.disabled ? el : again?.querySelector('[data-pager-size]'))?.focus?.();
  };
  nav.querySelectorAll('[data-pager-go]').forEach(b => b.addEventListener('click', async () => {
    const dir = b.dataset.pagerGo;
    await onChange({ page: p.page + (dir === 'next' ? 1 : -1), size: p.size });
    refocus(`[data-pager-go="${dir}"]`);
  }));
  nav.querySelector('[data-pager-size]')?.addEventListener('change', async (ev) => {
    const size = Number(ev.target.value);
    savePageSize(listKey, size);
    await onChange({ page: Math.floor((p.from - 1) / size) + 1, size });
    refocus('[data-pager-size]');
  });
}
