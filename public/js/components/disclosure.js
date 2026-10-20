// =====================================================================
// v3.6.0 — Aufklappbereich: lange Listen standardmäßig zu, damit die
// Kacheln (Bento) ruhig stehen. Ein Baustein für „Prognose im Detail:
// Monatstabelle, Heizkurve, Band“ (Prognose) und „CO₂-Emissionsfaktoren“
// (Einstellungen → Verbrauchsarten).
//
//   disclosureHtml({ summary, body, storageKey, id, className, open })
//     → <details class="disclosure …" data-disclosure="storageKey">
//   wireDisclosures(root)   merkt sich auf diesem Gerät, ob offen (localStorage)
//   openDisclosureOf(el)    klappt jeden Bereich um ein Element auf (Fehler im
//                           Feld, nach dem Speichern, Sprung zu einem Feld)
//
// Der Zustand gehört zum Gerät, nicht zur Installation: ein iPad darf zu
// haben, was der Rechner offen hält.
// =====================================================================

import { escapeHtml } from '../lib/format.js';

/** Gemerkter Zustand: true/false, oder null ohne Eintrag (bzw. ohne Speicher). */
export function storedOpen(storageKey) {
  if (!storageKey) return null;
  try {
    const v = localStorage.getItem(storageKey);
    return v === '1' ? true : v === '0' ? false : null;
  } catch { return null; }   // privater Modus
}

/**
 * @param {{summary: string, body: string, storageKey?: string, id?: string,
 *          className?: string, open?: boolean}} o
 *   summary ist Text (wird escapt), body fertiges HTML. `open` ist die
 *   Voreinstellung, solange auf dem Gerät nichts gemerkt ist.
 */
export function disclosureHtml({ summary, body, storageKey = '', id = '', className = '', open = false }) {
  const isOpen = storedOpen(storageKey) ?? open;
  return `<details class="disclosure${className ? ' ' + className : ''}"${id ? ` id="${escapeHtml(id)}"` : ''}${storageKey ? ` data-disclosure="${escapeHtml(storageKey)}"` : ''}${isOpen ? ' open' : ''}>
      <summary class="disclosure__summary">${escapeHtml(summary)}</summary>
      <div class="disclosure__body">${body}</div>
    </details>`;
}

/** Zustand je Bereich merken (einmal je Render aufrufen). */
export function wireDisclosures(root) {
  root?.querySelectorAll?.('details.disclosure[data-disclosure]').forEach(d => {
    d.addEventListener('toggle', () => {
      try { localStorage.setItem(d.dataset.disclosure, d.open ? '1' : '0'); } catch { /* privat */ }
    });
  });
}

/** Klappt alle Bereiche auf, in denen das Element steht; true, wenn einer zu war. */
export function openDisclosureOf(el) {
  let changed = false;
  for (let d = el?.closest?.('details.disclosure'); d; d = d.parentElement?.closest?.('details.disclosure')) {
    if (!d.open) { d.open = true; changed = true; }
  }
  return changed;
}
