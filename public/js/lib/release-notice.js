// =====================================================================
// v3.6.0 — „Was ist neu“ und Hinweis auf neue Versionen.
//
// Nach einem Update zeigt jedes Gerät einmal „Aktualisiert auf X“ mit Link
// zu den Release-Notes und einer Zeile zum Unterstützen; der Vergleich
// läuft auf dem Gerät (zuletzt gesehene Version in localStorage), ohne
// Server. Beim ersten Start einer Installation erscheint nichts.
//
// Ist der Hinweis auf neue Versionen eingeschaltet (Einstellung
// `update_check`), fragt GET /api/update-check — der Server sieht höchstens
// einmal am Tag bei GitHub nach — und meldet „Version Y ist erschienen“,
// bis man den Hinweis für diese Version schließt. In der Demo nicht.
// =====================================================================

import { api } from '../api.js';
import { t } from './i18n.js';
import { escapeHtml as esc } from './format.js';
import { DEMO } from './demo-mode.js';
import { getSettings } from '../state.js';

export const SUPPORT_URL = 'https://buymeacoffee.com/bingerminger';
const SEEN = 'et-seen-version';
const DISMISSED = 'et-dismissed-update';
const notesUrl = (v) => `https://github.com/Bingerminger/energietracker/releases/tag/v${encodeURIComponent(v)}`;
const get = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
const put = (k, v) => { try { localStorage.setItem(k, v); } catch { /* privat: dann eben jedes Mal */ } };

/** a > b für „X.Y.Z“ (Teile als Zahlen). */
export function newer(a, b) {
  const pa = String(a).split('.').map(Number), pb = String(b).split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    const d = (pa[i] || 0) - (pb[i] || 0);
    if (d) return d > 0;
  }
  return false;
}

/** @param {'updated'|'available'} kind */
export function noticeHtml(kind, version, url = null) {
  const head = kind === 'updated' ? t('notice.updated', { version }) : t('notice.available', { version });
  return `<div class="release-notice" role="status" data-notice="${kind}">
    <div class="release-notice__text">
      <p><strong>${esc(head)}</strong> <a href="${esc(url || notesUrl(version))}" target="_blank" rel="noopener">${esc(t('notice.whatsNew'))}</a></p>
      ${kind === 'updated' ? `<p class="muted">${esc(t('notice.support'))} <a href="${SUPPORT_URL}" target="_blank" rel="noopener noreferrer" data-support-link>${esc(t('notice.supportLink'))}</a></p>` : ''}
    </div>
    <button type="button" class="btn btn--ghost btn--sm" data-act="close" aria-label="${esc(t('notice.dismiss'))}">✕</button>
  </div>`;
}

function show(html, onClose) {
  const wrap = document.createElement('div');
  wrap.innerHTML = html;
  const el = wrap.firstElementChild;
  el.querySelector('[data-act="close"]').addEventListener('click', () => { el.remove(); onClose(); });
  document.body.appendChild(el);
  return el;
}

export async function showReleaseNotices() {
  const current = document.body?.dataset.appVersion || '';
  if (!/^\d+\.\d+\.\d+$/.test(current)) return;
  const seen = get(SEEN);
  if (seen === null) put(SEEN, current);                 // erster Start: nichts zeigen
  else if (newer(current, seen)) {
    show(noticeHtml('updated', current), () => put(SEEN, current));
    return;                                              // ein Hinweis zur Zeit
  }
  if (DEMO) return;
  const settings = await getSettings().catch(() => ({}));
  if (!settings.update_check) return;
  const r = await api.updateCheck().catch(() => null);
  if (r?.newer && r.latest && get(DISMISSED) !== r.latest) {
    show(noticeHtml('available', r.latest, r.url), () => put(DISMISSED, r.latest));
  }
}
