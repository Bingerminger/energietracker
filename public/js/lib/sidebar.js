// =====================================================================
// Energietracker v1.3.0 — Dynamische Sidebar
// Baut die Navigation aus der Utilities-Config + active_utilities-Setting.
// Inaktive Verbrauchsarten erscheinen nicht (P10). Neue Views
// (Empfehlungen, Tarifvergleich, Termine) sind fest verdrahtet.
// =====================================================================

import { getUtilities, getSettings } from '../state.js';
import { api } from '../api.js';
import { t } from './i18n.js';
import { escapeHtml as esc } from './format.js';

const STATIC_ICON = {
  gas: '🔥', strom: '⚡', wasser: '💧',
  fernwaerme: '🌡️', heizoel: '🛢️', pellets: '🪵',
};

export async function buildSidebar() {
  const nav = document.getElementById('primary-nav');
  if (!nav) return;

  let utilities = [];
  let settings = {};
  try {
    [utilities, settings] = await Promise.all([getUtilities(), getSettings()]);
  } catch (e) {
    console.error('Sidebar: Laden fehlgeschlagen', e);
    return;
  }

  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities
    : utilities.map(u => u.key);

  const activeUtils = utilities.filter(u => active.includes(u.key));

  const utilLinks = activeUtils.map(u => {
    const icon = u.icon || STATIC_ICON[u.key] || '•';
    return `<a class="sidebar__item" href="#/utility/${u.key}" data-route="utility:${u.key}" data-utility="${u.key}">
      <span class="sidebar__icon">${icon}</span><span>${esc(u.label)}</span>
    </a>`;
  }).join('');

  nav.innerHTML = `
    <div class="sidebar__group">
      <div class="sidebar__group-label">${esc(t('nav.group.capture'))}</div>
      <a class="sidebar__item" href="#/zaehlerstaende" data-route="readings-entry">
        <span class="sidebar__icon">📋</span><span>${esc(t('nav.readings'))}</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">${esc(t('nav.group.overview'))}</div>
      <a class="sidebar__item" href="#/dashboard" data-route="dashboard">
        <span class="sidebar__icon">🏠</span><span>${esc(t('nav.dashboard'))}</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">${esc(t('nav.group.consumption'))}</div>
      ${utilLinks}
      <a class="sidebar__item" href="#/temperatures" data-route="temperatures">
        <span class="sidebar__icon">🌡️</span><span>${esc(t('nav.temperatures'))}</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">${esc(t('nav.group.analysis'))}</div>
      <a class="sidebar__item" href="#/analysis" data-route="analysis">
        <span class="sidebar__icon">📊</span><span>${esc(t('nav.correlation'))}</span>
      </a>
      <a class="sidebar__item" href="#/forecast" data-route="forecast">
        <span class="sidebar__icon">🎯</span><span>${esc(t('nav.forecast'))}</span>
      </a>
      <a class="sidebar__item" href="#/tariffs" data-route="tariffs">
        <span class="sidebar__icon">💰</span><span>${esc(t('nav.tariffs'))}</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">${esc(t('nav.group.insights'))}</div>
      <a class="sidebar__item" href="#/recommendations" data-route="recommendations">
        <span class="sidebar__icon">💡</span><span>${esc(t('nav.recommendations'))}</span>
        <span data-badge="recommendations"></span>
      </a>
      <a class="sidebar__item" href="#/reminders" data-route="reminders">
        <span class="sidebar__icon">📌</span><span>${esc(t('nav.reminders'))}</span>
        <span data-badge="reminders"></span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">${esc(t('nav.group.system'))}</div>
      <a class="sidebar__item" href="#/settings" data-route="settings">
        <span class="sidebar__icon">⚙️</span><span>${esc(t('nav.settings'))}</span>
      </a>
    </div>
  `;
}

/**
 * v2.2.0 — Zähler an „Empfehlungen" und „Termine" nachreichen.
 *
 * Diese beiden Zahlen kosteten je einen API-Aufruf und hingen bis v2.1.5 im
 * kritischen Pfad: `app.js` startete den Router erst, nachdem die Seitenleiste
 * fertig war, und die wartete auf beide Antworten. Der erste Bildschirminhalt
 * kam damit erst nach vier seriellen Roundtrips. Jetzt rendert die Navigation
 * sofort, die Badges erscheinen, sobald sie da sind.
 */
export async function refreshSidebarBadges() {
  const nav = document.getElementById('primary-nav');
  if (!nav) return;

  const [recs, rem] = await Promise.all([
    api.recommendations().catch(() => null),
    api.reminders().catch(() => null),
  ]);

  const set = (name, count, alert = false) => {
    const slot = nav.querySelector(`[data-badge="${name}"]`);
    if (!slot) return;
    slot.innerHTML = count > 0
      ? `<span class="sidebar__badge${alert ? ' sidebar__badge--alert' : ''}">${count}</span>`
      : '';
  };

  set('recommendations', Array.isArray(recs) ? recs.length : 0);
  set('reminders', Array.isArray(rem)
    ? rem.filter(r => ['due', 'overdue'].includes(r.status)).length : 0, true);
}

