// =====================================================================
// v3.10.0 — Danke sagen: ein dezenter Hinweis aufs Unterstützen, gerade wenn
// die App geholfen hat: „Rechnung prüfen“ findet eine Abweichung, eine Frist
// ist vor dem Fälligkeitstag erledigt, der Jahresbericht steht.
//
// Höchstens einmal im Quartal je Gerät (Tag des letzten Hinweises in
// localStorage), abschaltbar (Einstellung `support_hint`), nie in der Demo.
// Kein Dialog: eine Zeile an Ort und Stelle mit Link (neuer Tab), „Nicht mehr
// zeigen“ und Schließen. Der Text ist der der Karte „Energietracker
// unterstützen“ unter Einstellungen › System.
// =====================================================================

import { t } from './i18n.js';
import { escapeHtml as esc, todayIso } from './format.js';
import { DEMO } from './demo-mode.js';
import { SUPPORT_URL } from './release-notice.js';
import { getSettings, saveSettings } from '../state.js';
import { toastOk, toastErr } from '../components/toast.js';

export const THANKS_KEY = 'et-thanks-shown';
/** Ein Quartal hat höchstens 92 Tage: Zwei Hinweise liegen so nie im selben Quartal. */
export const THANKS_GAP_DAYS = 92;
/** Die Momente, in denen der Hinweis erscheinen darf. */
export const THANKS_MOMENTS = ['bill_check', 'deadline', 'report'];

const ISO = /^\d{4}-\d{2}-\d{2}$/;
const get = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
const put = (k, v) => { try { localStorage.setItem(k, v); } catch { /* privat: dann eben nicht gemerkt */ } };
const utc = (iso) => { const [y, m, d] = iso.split('-').map(Number); return Date.UTC(y, m - 1, d); };

/**
 * Darf der Hinweis jetzt erscheinen?
 * @param {{settings?: object, last?: ?string, today?: string, demo?: boolean}} o
 */
export function thanksAllowed({ settings = {}, last = null, today = todayIso(), demo = DEMO } = {}) {
  if (demo || settings?.support_hint === false) return false;
  // nie gezeigt, unlesbar oder in der Zukunft (Uhr verstellt): jetzt darf er
  if (!ISO.test(last || '') || last > today) return true;
  return Math.round((utc(today) - utc(last)) / 86400000) >= THANKS_GAP_DAYS;
}

/** Frist rechtzeitig erledigt: vor dem Fälligkeitstag als erledigt markiert. */
export function doneInTime(due, doneOn) {
  return ISO.test(due || '') && ISO.test(doneOn || '') && doneOn < due;
}

/**
 * v3.10.0 (Skeptiker N11) — Ist der Vergleich mit der Rechnung ein Nutzen-Moment? Eine
 * Abweichung (Urteil `check`), aber nicht, wenn sie aus eigenen Datenlücken stammen kann:
 * Zeit ohne Ablesung oder Vertrag (`gaps`), fehlende Preise (`price_missing`). Ein
 * geschätzter Stand am Rand (`estimated_reading_at_boundary`) zählt nicht dazu: Die
 * Ränder liegen fast immer zwischen zwei Ablesungen, der Moment käme sonst kaum vor.
 */
export const OWN_DATA_REASONS = ['gaps', 'price_missing'];
export function compareThanks(c) {
  return c?.verdict === 'check' && !(c.reasons || []).some(r => OWN_DATA_REASONS.includes(r));
}

/** Die Zeile selbst (ohne Verhalten); `apart`: mit Abstand zum Ergebnis darüber. */
export function thanksHtml(moment, apart = false) {
  return `<div class="thanks-note${apart ? ' thanks-note--apart' : ''} no-print" role="status" data-thanks="${esc(moment)}">
    <p class="thanks-note__text"><span aria-hidden="true">☕</span> ${esc(t('settings.support.text'))}
      <a href="${SUPPORT_URL}" target="_blank" rel="noopener noreferrer" data-support-link>${esc(t('settings.support.link'))}</a></p>
    <div class="thanks-note__actions">
      <button type="button" class="btn btn--ghost btn--sm" data-thanks-off>${esc(t('thanks.off'))}</button>
      <button type="button" class="btn btn--ghost btn--sm" data-act="close" aria-label="${esc(t('notice.dismiss'))}">✕</button>
    </div>
  </div>`;
}

/**
 * Hinweis anbieten: Er erscheint nur, wenn er darf, und merkt sich den Tag.
 * @param {Element|null} anchor  Ort im Inhalt
 * @param {'bill_check'|'deadline'|'report'} moment
 * @param {{where?: 'append'|'prepend'|'after', apart?: boolean}} [opts]
 * @returns {Promise<Element|null>} die Zeile oder null
 */
export async function offerThanks(anchor, moment, { where = 'append', apart = false } = {}) {
  if (!anchor || !THANKS_MOMENTS.includes(moment)) return null;
  const settings = await getSettings().catch(() => ({}));
  if (!anchor.isConnected || !thanksAllowed({ settings, last: get(THANKS_KEY) })) return null;
  const wrap = document.createElement('div');
  wrap.innerHTML = thanksHtml(moment, apart);
  const el = wrap.firstElementChild;
  if (where === 'after') anchor.after(el);
  else if (where === 'prepend') anchor.prepend(el);
  else anchor.append(el);
  put(THANKS_KEY, todayIso());
  el.querySelector('[data-act="close"]').addEventListener('click', () => el.remove());
  el.querySelector('[data-thanks-off]').addEventListener('click', async () => {
    try {
      await saveSettings({ support_hint: false });
      el.remove();
      toastOk(t('thanks.offDone'));
    } catch (e) { toastErr(e.message || String(e)); }
  });
  return el;
}
