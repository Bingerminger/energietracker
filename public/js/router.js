// =====================================================================
// Energietracker v1.0.2 — Hash router
// Each route maps to a view module that exports `render(container, params)`.
// =====================================================================

import * as Dashboard    from './views/dashboard.js';
import * as Utility      from './views/utility.js';
import * as Meters       from './views/meters.js';
import * as Contracts    from './views/contracts.js';
import * as Temperatures from './views/temperatures.js';
import * as Analysis     from './views/analysis.js';
import * as Forecast     from './views/forecast.js';
import * as Settings     from './views/settings.js';

const ROUTES = [
  { pattern: /^#?\/?$/,                             handler: 'dashboard' },
  { pattern: /^#\/dashboard$/,                      handler: 'dashboard' },
  { pattern: /^#\/utility\/([^/]+)\/meters$/,       handler: 'meters'    },
  { pattern: /^#\/utility\/([^/]+)\/contracts$/,    handler: 'contracts' },
  { pattern: /^#\/utility\/([^/]+)$/,               handler: 'utility'   },
  { pattern: /^#\/temperatures$/,                   handler: 'temperatures' },
  { pattern: /^#\/analysis$/,                       handler: 'analysis'  },
  { pattern: /^#\/forecast$/,                       handler: 'forecast'  },
  { pattern: /^#\/settings$/,                       handler: 'settings'  },
];

const HANDLERS = {
  dashboard:    Dashboard,
  utility:      Utility,
  meters:       Meters,
  contracts:    Contracts,
  temperatures: Temperatures,
  analysis:     Analysis,
  forecast:     Forecast,
  settings:     Settings,
};

let currentCleanup = null;

export function startRouter(container) {
  const handle = async () => {
    const hash = window.location.hash || '#/dashboard';
    for (const { pattern, handler } of ROUTES) {
      const m = hash.match(pattern);
      if (!m) continue;
      const params = m.slice(1);
      // Cleanup previous view
      if (currentCleanup) { try { currentCleanup(); } catch {} }
      container.innerHTML = '<div class="loading">Lade…</div>';
      // Highlight active nav
      document.querySelectorAll('#primary-nav a').forEach(a => {
        const route = a.getAttribute('data-route') || '';
        const utility = a.getAttribute('data-utility') || '';
        const isActive =
          (handler === 'dashboard' && route === 'dashboard') ||
          (handler === 'utility' && utility && params[0] === utility) ||
          (handler === 'meters' && utility && params[0] === utility) ||
          (handler === 'contracts' && utility && params[0] === utility) ||
          (handler === 'temperatures' && route === 'temperatures') ||
          (handler === 'analysis' && route === 'analysis') ||
          (handler === 'forecast' && route === 'forecast') ||
          (handler === 'settings' && route === 'settings');
        a.classList.toggle('active', isActive);
      });
      try {
        const mod = HANDLERS[handler];
        const cleanup = await mod.render(container, params);
        currentCleanup = typeof cleanup === 'function' ? cleanup : null;
      } catch (e) {
        console.error(e);
        container.innerHTML = `<div class="banner banner--error">Fehler beim Laden: ${escapeHtml(e.message || e)}</div>`;
      }
      return;
    }
    container.innerHTML = `<div class="banner banner--warning">Unbekannte Route: ${escapeHtml(hash)}</div>`;
  };
  window.addEventListener('hashchange', handle);
  handle();
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

export function navigate(route) {
  window.location.hash = route;
}
