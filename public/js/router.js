// =====================================================================
// Energietracker v1.3.0 — Hash router
// Each route maps to a view module that exports `render(container, params)`.
// =====================================================================

import * as Dashboard    from './views/dashboard.js';
import * as ReadingsEntry from './views/readings-entry.js';
import * as Utility      from './views/utility.js';
import * as Meters       from './views/meters.js';
import * as Contracts    from './views/contracts.js';
import * as Temperatures from './views/temperatures.js';
import * as Analysis     from './views/analysis.js';
import * as Forecast     from './views/forecast.js';
import * as Settings     from './views/settings.js';
import * as Tariffs      from './views/tariff.js';
import * as Recommendations from './views/recommendations.js';
import * as Reminders    from './views/reminders.js';
import { t } from './lib/i18n.js';
import { escapeHtml } from './lib/format.js';

const ROUTES = [
  { pattern: /^#?\/?$/,                             handler: 'dashboard' },
  { pattern: /^#\/zaehlerstaende$/,                 handler: 'readings-entry' },
  { pattern: /^#\/dashboard$/,                      handler: 'dashboard' },
  { pattern: /^#\/utility\/([^/]+)\/meters$/,       handler: 'meters'    },
  { pattern: /^#\/utility\/([^/]+)\/contracts$/,    handler: 'contracts' },
  { pattern: /^#\/utility\/([^/]+)$/,               handler: 'utility'   },
  { pattern: /^#\/temperatures$/,                   handler: 'temperatures' },
  { pattern: /^#\/analysis$/,                       handler: 'analysis'  },
  { pattern: /^#\/forecast$/,                       handler: 'forecast'  },
  { pattern: /^#\/tariffs$/,                        handler: 'tariffs'   },
  { pattern: /^#\/recommendations$/,                handler: 'recommendations' },
  { pattern: /^#\/reminders$/,                      handler: 'reminders' },
  { pattern: /^#\/settings$/,                       handler: 'settings'  },
];

const HANDLERS = {
  'readings-entry': ReadingsEntry,
  dashboard:    Dashboard,
  utility:      Utility,
  meters:       Meters,
  contracts:    Contracts,
  temperatures: Temperatures,
  analysis:     Analysis,
  forecast:     Forecast,
  tariffs:      Tariffs,
  recommendations: Recommendations,
  reminders:    Reminders,
  settings:     Settings,
};

let currentCleanup = null;

export function startRouter(container) {
  const handle = async (isInitialLoad = false) => {
    const hash = window.location.hash || '#/dashboard';
    for (const { pattern, handler } of ROUTES) {
      const m = hash.match(pattern);
      if (!m) continue;
      const params = m.slice(1);
      // Cleanup previous view
      if (currentCleanup) { try { currentCleanup(); } catch {} }
      container.innerHTML = `<div class="loading" role="status">${escapeHtml(t('common.loading'))}</div>`;
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
          (handler === 'tariffs' && route === 'tariffs') ||
          (handler === 'recommendations' && route === 'recommendations') ||
          (handler === 'reminders' && route === 'reminders') ||
          (handler === 'settings' && route === 'settings');
        a.classList.toggle('active', isActive);
        // A11y (N1009): aktive Route auch für Screenreader auszeichnen.
        if (isActive) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
      });
      try {
        const mod = HANDLERS[handler];
        const cleanup = await mod.render(container, params);
        currentCleanup = typeof cleanup === 'function' ? cleanup : null;
        // A11y: bei echter Navigation (nicht beim Erst-Laden) den Fokus in den
        // Hauptbereich verschieben, damit Tastatur/Screenreader im neuen Inhalt
        // landen statt am Seitenanfang. #view trägt tabindex="-1".
        if (!isInitialLoad) container.focus({ preventScroll: false });
      } catch (e) {
        console.error(e);
        container.innerHTML = `<div class="banner banner--error">${escapeHtml(t('errors.view.loadFailed', { msg: e.message || e }))}</div>`;
      }
      return;
    }
    container.innerHTML = `<div class="banner banner--warning">${escapeHtml(t('errors.view.unknownRoute', { hash }))}</div>`;
  };
  window.addEventListener('hashchange', () => handle(false));
  handle(true);
}

export function navigate(route) {
  window.location.hash = route;
}
