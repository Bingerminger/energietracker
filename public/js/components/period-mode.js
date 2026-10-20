// =====================================================================
// v3.6.0 (F1030, GitHub #33) — Umschalter „Kalenderjahr · Abrechnungsjahr“
// für Verbrauchsart, Übersicht und Analyse. Eine Wahl je Gerät
// (lib/billing-period.js); ein Klick merkt sie und meldet `et:periodmode` —
// der Router zeichnet die Ansicht neu.
// =====================================================================
import { t } from '../lib/i18n.js';
import { escapeHtml } from '../lib/format.js';
import { periodMode, setPeriodMode } from '../lib/billing-period.js';
import { info } from './info.js';

export const PERIOD_MODE_EVENT = 'et:periodmode';

/** Segment mit beiden Wahlen; `mode` = die angezeigte (Standard: die gemerkte). */
export function periodModeHtml(mode = periodMode()) {
  return `<span class="seg period-mode" role="group" aria-label="${escapeHtml(t('billing.mode.label'))}">${
    ['calendar', 'billing'].map(m => `<button type="button" class="seg__btn ${mode === m ? 'active' : ''}" data-period-mode="${m}" aria-pressed="${mode === m}">${escapeHtml(t('billing.mode.' + m))}</button>`).join('')
  }</span>${info('billingYear')}`;
}

/** Klick: merken und neu zeichnen lassen. */
export function wirePeriodMode(root) {
  root.querySelectorAll('[data-period-mode]').forEach(b => b.addEventListener('click', () => {
    if (b.getAttribute('aria-pressed') === 'true') return;
    setPeriodMode(b.dataset.periodMode);
    window.dispatchEvent(new CustomEvent(PERIOD_MODE_EVENT, { detail: { mode: periodMode() } }));
  }));
}

/** Das Gerät zeigt Abrechnungsjahre, diese Art kennt nur Kalenderjahre: kurz sagen. */
export function calendarOnlyNote(label) {
  return periodMode() === 'billing'
    ? `<p class="muted small period-mode-note" data-role="calendar-only">${escapeHtml(t('billing.mode.calendarOnly', { label }))}</p>` : '';
}
