// =====================================================================
// Energietracker v1.3.0 — Dynamische Sidebar
// Baut die Navigation aus der Utilities-Config + active_utilities-Setting.
// Inaktive Verbrauchsarten erscheinen nicht (P10). Neue Views
// (Empfehlungen, Tarifvergleich, Termine) sind fest verdrahtet.
// =====================================================================

import { getUtilities, getSettings } from '../state.js';
import { api } from '../api.js';

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

  // Badges: offene Empfehlungen / fällige Termine
  let recCount = 0, dueCount = 0;
  try {
    const recs = await api.recommendations();
    recCount = Array.isArray(recs) ? recs.length : 0;
  } catch {}
  try {
    const rem = await api.reminders();
    dueCount = Array.isArray(rem)
      ? rem.filter(r => ['due', 'overdue'].includes(r.status)).length : 0;
  } catch {}

  const activeUtils = utilities.filter(u => active.includes(u.key));

  const utilLinks = activeUtils.map(u => {
    const icon = u.icon || STATIC_ICON[u.key] || '•';
    return `<a class="sidebar__item" href="#/utility/${u.key}" data-route="utility:${u.key}" data-utility="${u.key}">
      <span class="sidebar__icon">${icon}</span><span>${esc(u.label)}</span>
    </a>`;
  }).join('');

  nav.innerHTML = `
    <div class="sidebar__group">
      <div class="sidebar__group-label">Erfassung</div>
      <a class="sidebar__item" href="#/zaehlerstaende" data-route="readings-entry">
        <span class="sidebar__icon">📋</span><span>Zählerstände</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">Übersicht</div>
      <a class="sidebar__item" href="#/dashboard" data-route="dashboard">
        <span class="sidebar__icon">🏠</span><span>Dashboard</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">Verbrauch</div>
      ${utilLinks}
      <a class="sidebar__item" href="#/temperatures" data-route="temperatures">
        <span class="sidebar__icon">🌡️</span><span>Temperaturen</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">Analyse</div>
      <a class="sidebar__item" href="#/analysis" data-route="analysis">
        <span class="sidebar__icon">📊</span><span>Korrelation</span>
      </a>
      <a class="sidebar__item" href="#/forecast" data-route="forecast">
        <span class="sidebar__icon">🎯</span><span>Prognose</span>
      </a>
      <a class="sidebar__item" href="#/tariffs" data-route="tariffs">
        <span class="sidebar__icon">💰</span><span>Tarifvergleich</span>
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">Insights</div>
      <a class="sidebar__item" href="#/recommendations" data-route="recommendations">
        <span class="sidebar__icon">💡</span><span>Empfehlungen</span>
        ${recCount > 0 ? `<span class="sidebar__badge">${recCount}</span>` : ''}
      </a>
      <a class="sidebar__item" href="#/reminders" data-route="reminders">
        <span class="sidebar__icon">📌</span><span>Termine</span>
        ${dueCount > 0 ? `<span class="sidebar__badge sidebar__badge--alert">${dueCount}</span>` : ''}
      </a>
    </div>

    <div class="sidebar__group">
      <div class="sidebar__group-label">System</div>
      <a class="sidebar__item" href="#/settings" data-route="settings">
        <span class="sidebar__icon">⚙️</span><span>Einstellungen</span>
      </a>
    </div>
  `;
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
