// =====================================================================
// Energietracker — Seitenleiste
//
// v2.11.0 (Review UI-12) — gebaut aus dem Navigationsmodell (nav-model.js):
// sieben Bereiche statt 17 flacher Einträge. Die aktive Markierung setzt das
// Ereignis `et:route` des Routers, nicht mehr eine Liste von Sonderfällen im
// Router. Auf dem iPhone ist dieselbe Leiste das Menü hinter „Mehr"
// (mobile-nav.js). Die Seitenleiste folgt einer geänderten Auswahl der
// Verbrauchsarten sofort (`et:settingschange`, Review FE-12).
// =====================================================================

import { activeUtilities, invalidateUtilities } from '../state.js';
import { api, appScope } from '../api.js';
import { t } from './i18n.js';
import { escapeHtml as esc } from './format.js';
import { sidebarModel } from './nav-model.js';

let lastRoute = null;
const badgeCounts = { reminders: 0, remindersAlert: false, recommendations: 0 };

function itemHtml(item, cls = 'sidebar__item') {
  const attrs = [
    `class="${cls}"`, `href="${item.href}"`, `data-nav="${esc(item.key)}"`,
    item.section ? `data-section="${esc(item.section)}"` : '',
    item.utility ? `data-utility="${esc(item.utility)}"` : '',
  ].filter(Boolean).join(' ');
  return `<a ${attrs}>
      <span class="sidebar__icon" aria-hidden="true">${esc(item.icon || '•')}</span><span class="sidebar__label">${esc(item.label)}</span>${item.badge ? `<span data-badge="${esc(item.badge)}"></span>` : ''}
    </a>`;
}

export async function buildSidebar() {
  const nav = document.getElementById('primary-nav');
  if (!nav) return;
  let utilities = [];
  try {
    utilities = await activeUtilities();
  } catch (e) {
    console.error('Sidebar: Laden fehlgeschlagen', e);
    nav.innerHTML = `<div class="sidebar__error" role="alert">
        <p>${esc(t('errors.view.utilitiesFailed', { msg: e?.message || e }))}</p>
        <button type="button" class="btn btn--sm" data-act="retry">${esc(t('app.retry'))}</button>
      </div>`;
    nav.querySelector('[data-act="retry"]')?.addEventListener('click', () => buildSidebar());
    return;
  }

  nav.innerHTML = sidebarModel(utilities).map(item => {
    if (!item.children) return itemHtml(item);
    const id = `nav-group-${item.key}`;
    return `<div class="sidebar__group" role="group" aria-labelledby="${id}">
        <div class="sidebar__group-label" id="${id}">${esc(item.group)}</div>
        ${item.children.map(c => itemHtml(c)).join('')}
      </div>`;
  }).join('');
  applyBadges();
  if (lastRoute) markActive(lastRoute);
  window.dispatchEvent(new CustomEvent('et:nav-built', { detail: { utilities } }));
}

/** Aktive Markierung für Seitenleiste und Tab-Leiste (aria-current). */
function markActive(route) {
  document.querySelectorAll('[data-nav]').forEach(a => {
    const section = a.getAttribute('data-section');
    const utility = a.getAttribute('data-utility');
    const key = a.getAttribute('data-nav');
    const active = utility
      ? route.utility === utility
      : key === route.key || (!!section && section === route.section);
    a.classList.toggle('active', active);
    if (active) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  });
}

window.addEventListener('et:route', (e) => {
  lastRoute = e.detail;
  markActive(lastRoute);
});

// v2.11.0 (FE-12) — Verbrauchsarten an- oder abgewählt: Leiste neu aufbauen.
window.addEventListener('et:settingschange', (e) => {
  if (!e.detail?.keys?.includes('active_utilities')) return;
  invalidateUtilities();
  buildSidebar().catch(() => {});
});

/** Zahlen in alle Platzhalter schreiben — Seitenleiste, Tab-Leiste, Bereichs-Tabs. */
function applyBadges() {
  const hints = badgeCounts.reminders + badgeCounts.recommendations;
  const values = {
    reminders: [badgeCounts.reminders, badgeCounts.remindersAlert],
    recommendations: [badgeCounts.recommendations, false],
    hints: [hints, badgeCounts.remindersAlert],
  };
  document.querySelectorAll('[data-badge]').forEach(slot => {
    const [count, alert] = values[slot.getAttribute('data-badge')] || [0, false];
    slot.innerHTML = count > 0
      ? `<span class="sidebar__badge${alert ? ' sidebar__badge--alert' : ''}">${count}</span>`
      : '';
  });
}
window.addEventListener('et:badges-slots', applyBadges);

/**
 * v2.2.0 — Zähler an „Hinweise" (Termine + Empfehlungen) nachreichen.
 *
 * Die Zahlen kosten je einen API-Aufruf und dürfen den ersten Inhalt nicht
 * aufhalten. v2.11.0: ein Badge am Bereich, je eins an den Tabs; Ansichten
 * melden Änderungen über `et:badges-refresh`.
 */
export async function refreshSidebarBadges() {
  const [recs, rem] = await appScope(() => Promise.all([
    api.recommendations().catch(() => null),
    api.reminders().catch(() => null),
  ]));
  const due = Array.isArray(rem) ? rem.filter(r => ['due', 'overdue'].includes(r.status)) : [];
  badgeCounts.reminders = due.length;
  badgeCounts.remindersAlert = due.length > 0;
  badgeCounts.recommendations = Array.isArray(recs) ? recs.length : 0;
  applyBadges();
}
window.addEventListener('et:badges-refresh', () => { refreshSidebarBadges().catch(() => {}); });
