// =====================================================================
// Energietracker v2.11.0 — Fehler einer Ansicht mit „Erneut versuchen"
// (Review FE-25). Bis v2.10 blieb eine Ansicht bei einem Netzfehler bei
// „Lädt…" stehen oder zeigte den rohen Browsertext ohne Ausweg.
// =====================================================================

import { escapeHtml } from '../lib/format.js';
import { t } from '../lib/i18n.js';

/**
 * @param {HTMLElement} el
 * @param {unknown} err
 * @param {() => unknown} retry
 */
export function renderError(el, err, retry) {
  const msg = err instanceof Error ? err.message : String(err);
  el.innerHTML = `<div class="banner banner--error" role="alert">
      <p>${escapeHtml(t('errors.view.loadFailed', { msg }))}</p>
      <button type="button" class="btn btn--sm" data-act="retry">${escapeHtml(t('app.retry'))}</button>
    </div>`;
  el.querySelector('[data-act="retry"]')?.addEventListener('click', () => retry());
}
