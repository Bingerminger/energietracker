// =====================================================================
// Energietracker — Hash-Router
// Jede Route verweist auf ein View-Modul mit `render(container, params, ctx)`.
//
// v2.11.0 (Review FE-05, FE-25, FE-26, UI-12, UI-29):
//   • Jede Navigation bekommt ein Token, ein Abbruchsignal und einen eigenen
//     Container. Bis v2.10 schrieben alle Ansichten nach ihrem `await` in
//     denselben Container: Wer schnell weiterklickte, sah die Antwort der
//     alten Seite unter der Überschrift der neuen („Übersicht" überschrieb
//     „Strom"), und der Cleanup der liegengebliebenen Ansicht ging verloren —
//     ihr Diagramm lebte weiter. Jetzt schreibt eine verspätete Ansicht in
//     ihren ausgehängten Container, ihre Leseanfragen werden abgebrochen
//     (api.js), und ihr Cleanup läuft, sobald sie fertig ist.
//   • Der Cleanup der alten Ansicht läuft, BEVOR die neue startet.
//   • Ansichten werden erst geladen, wenn man sie öffnet. Die Import-Map in
//     index.php versioniert auch diese dynamischen Importe.
//   • Bereiche mit mehreren Seiten (Kosten & Verträge, Auswertungen,
//     Hinweise, Einstellungen) bekommen Tabs über der Ansicht.
//   • Der Dokumenttitel nennt die Ansicht („Prognose · Energietracker").
//   • `#/pfad?schlüssel=wert` — die Query steht der Ansicht in `ctx.query`.
// =====================================================================

import { t } from './lib/i18n.js';
import { escapeHtml } from './lib/format.js';
import { closeAllModals } from './components/modal.js';
import { renderError } from './components/error.js';
import { setNavigationSignal } from './api.js';
import { activeUtilities } from './state.js';
import { sectionPages } from './lib/nav-model.js';

// Pfad (ohne „#" und Query) → Ansicht. Alte Adressen bleiben gültig.
const ROUTES = [
  [/^\/?$/,                          'dashboard'],
  [/^\/dashboard$/,                  'dashboard'],
  [/^\/zaehlerstaende$/,             'readings-entry'],
  [/^\/utility\/([^/]+)\/meters$/,   'meters'],
  [/^\/utility\/([^/]+)\/contracts$/, 'contracts'],
  [/^\/utility\/([^/]+)$/,           'utility'],
  [/^\/contracts$/,                  'contracts-overview'],
  [/^\/tariffs$/,                    'tariffs'],
  [/^\/bill-check$/,                 'bill-check'],
  [/^\/analysis$/,                   'analysis'],
  [/^\/forecast$/,                   'forecast'],
  [/^\/report$/,                     'report'],
  [/^\/reminders$/,                  'reminders'],
  [/^\/recommendations$/,            'recommendations'],
  [/^\/settings$/,                   'settings'],
  [/^\/settings\/([a-z]+)$/,         'settings'],   // v2.12.0 — Unterseiten
  [/^\/temperatures$/,               'temperatures'],
];

// Ansicht → Modul und Bereich. `tab` nennt den Tab, der bei einer Unterseite
// aktiv ist (die Verträge einer Verbrauchsart gehören zu „Verträge").
const VIEWS = {
  'dashboard':          { section: 'overview',    load: () => import('./views/dashboard.js') },
  'readings-entry':     { section: 'capture',     load: () => import('./views/readings-entry.js') },
  'utility':            { section: 'consumption', load: () => import('./views/utility.js') },
  'meters':             { section: 'consumption', load: () => import('./views/meters.js') },
  'contracts':          { section: 'costs', tab: 'contracts-overview', load: () => import('./views/contracts.js') },
  'contracts-overview': { section: 'costs',       load: () => import('./views/contracts-overview.js') },
  'tariffs':            { section: 'costs',       load: () => import('./views/tariff.js') },
  'bill-check':         { section: 'costs',       load: () => import('./views/bill-check.js') },
  'analysis':           { section: 'analysis',    load: () => import('./views/analysis.js') },
  'forecast':           { section: 'analysis',    load: () => import('./views/forecast.js') },
  'report':             { section: 'analysis',    load: () => import('./views/report.js') },
  'reminders':          { section: 'hints',       load: () => import('./views/reminders.js') },
  'recommendations':    { section: 'hints',       load: () => import('./views/recommendations.js') },
  'settings':           { section: 'settings',    load: () => import('./views/settings.js') },
  'temperatures':       { section: 'settings',    load: () => import('./views/temperatures.js') },
};

// Bereiche, deren Seiten als Tabs über der Ansicht stehen. Die Verbrauchsarten
// stehen am Mac in der Seitenleiste; ihre Tabs zeigt nur das iPhone (CSS).
const TABBED = new Set(['consumption', 'costs', 'analysis', 'hints', 'settings']);

let navSeq = 0;
let current = null;   // { token, controller, cleanup }

/** `#/pfad?x=1` → { path: '/pfad', query: URLSearchParams } */
export function parseHash(hash) {
  const raw = String(hash || '').replace(/^#/, '');
  const i = raw.indexOf('?');
  const path = (i < 0 ? raw : raw.slice(0, i)) || '/';
  return { path, query: new URLSearchParams(i < 0 ? '' : raw.slice(i + 1)) };
}

function resolve(path, routes) {
  for (const [pattern, view] of routes) {
    const m = path.match(pattern);
    if (m) return { view, params: m.slice(1).map(decodeURIComponent) };
  }
  return null;
}

function runCleanup(fn) {
  if (typeof fn !== 'function') return;
  try { fn(); } catch (e) { console.error(e); }
}

/** Tabs eines Bereichs; der Tab der aktiven Seite trägt aria-current. */
function sectionTabsHtml(section, activeKey, utilities) {
  const pages = sectionPages(section, { utilities });
  if (pages.length < 2) return '';
  const cls = section === 'consumption' ? 'section-tabs section-tabs--mobile' : 'section-tabs';
  return `<nav class="${cls}" aria-label="${escapeHtml(t('nav.sectionNav'))}">
    ${pages.map(p => {
      const active = p.view === activeKey;
      return `<a class="section-tabs__tab${active ? ' active' : ''}" href="${p.href}"${active ? ' aria-current="page"' : ''}${p.utility ? ` data-utility="${escapeHtml(p.utility)}"` : ''}>
        ${p.icon ? `<span aria-hidden="true">${escapeHtml(p.icon)}</span> ` : ''}${escapeHtml(p.label)}${p.badge ? ` <span data-badge="${p.badge}"></span>` : ''}
      </a>`;
    }).join('')}
  </nav>`;
}

/**
 * Nach dem ersten Bild die übrigen Ansichten im Leerlauf laden: Der Start
 * bleibt schlank, und der Service Worker hat danach alle Module im Cache —
 * offline öffnet sich auch eine Seite, die man in dieser Sitzung noch nicht
 * besucht hat (bis v2.10 lud der Router ohnehin alle beim Start).
 */
function prefetch(views) {
  const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 1500));
  idle(() => Object.values(views).forEach(v => v.load().catch(() => {})));
}

/** Titel des Dokuments aus der Überschrift der Ansicht, ohne Emoji. */
function updateTitle(host) {
  const h1 = host.querySelector('h1');
  const text = (h1?.textContent || '').replace(/[\p{Extended_Pictographic}\uFE0F]/gu, '').replace(/\s+/g, ' ').trim();
  document.title = text ? `${text} · Energietracker` : 'Energietracker';
}

/**
 * @param {HTMLElement} container  #view
 * @param {{routes?: Array, views?: Object}} [opts]  für Tests austauschbar
 */
export function startRouter(container, { routes = ROUTES, views = VIEWS } = {}) {
  const handle = async (isInitialLoad = false) => {
    const token = ++navSeq;
    const { path, query } = parseHash(window.location.hash || '#/dashboard');
    const match = resolve(path, routes);

    // Alte Ansicht abbauen, bevor die neue startet. Offene Dialoge gehören
    // zur alten Ansicht (v2.5.3, FE-09).
    closeAllModals('navigation');
    if (current) {
      current.controller.abort();
      runCleanup(current.cleanup);
    }
    const controller = new AbortController();
    const entry = { token, controller, cleanup: null };
    current = entry;
    setNavigationSignal(controller.signal);

    const host = document.createElement('div');
    host.className = 'view-host';
    container.replaceChildren(host);

    if (!match || !views[match.view]) {
      host.innerHTML = `<div class="banner banner--warning">${escapeHtml(t('errors.view.unknownRoute', { hash: window.location.hash }))}</div>`;
      document.title = 'Energietracker';
      return;
    }
    const { view, params } = match;
    const def = views[view];
    const activeKey = view === 'utility' || view === 'meters' ? 'utility:' + params[0]
      : view === 'settings' ? 'settings:' + (params[0] || 'general')
      : (def.tab || view);
    window.dispatchEvent(new CustomEvent('et:route', {
      detail: { view, section: def.section, key: activeKey, utility: view === 'utility' || view === 'meters' ? params[0] : null },
    }));

    let body = host;
    if (TABBED.has(def.section)) {
      let utilities = [];
      try { utilities = await activeUtilities(); } catch { /* Tabs ohne Verbrauchsarten */ }
      if (token !== navSeq) return;
      const tabs = sectionTabsHtml(def.section, activeKey, utilities);
      if (tabs) {
        host.innerHTML = `${tabs}<div class="view-body"></div>`;
        body = host.querySelector('.view-body');
        window.dispatchEvent(new CustomEvent('et:badges-slots'));
      }
    }
    body.innerHTML = `<div class="loading" role="status">${escapeHtml(t('common.loading'))}</div>`;

    try {
      const mod = await def.load();
      if (token !== navSeq) return;                 // überholt, bevor das Modul da war
      const cleanup = await mod.render(body, params, { query, signal: controller.signal });
      if (token !== navSeq) { runCleanup(cleanup); return; }   // verlassen, während sie lud
      entry.cleanup = typeof cleanup === 'function' ? cleanup : null;
      updateTitle(host);
      if (isInitialLoad) prefetch(views);
      // A11y: bei echter Navigation (nicht beim Erst-Laden) den Fokus in den
      // Hauptbereich verschieben, damit Tastatur/Screenreader im neuen Inhalt
      // landen statt am Seitenanfang. #view trägt tabindex="-1".
      // v2.5.3 (UI-06) — preventScroll und gezielt scrollen: Der Browser
      // scrollte den Fokus unter die klebende Kopfleiste.
      if (!isInitialLoad) {
        container.focus({ preventScroll: true });
        const topbarH = document.querySelector('.topbar')?.offsetHeight || 0;
        const top = container.getBoundingClientRect().top + window.scrollY - topbarH;
        window.scrollTo(0, Math.max(0, top));
      }
    } catch (e) {
      if (token !== navSeq) return;
      console.error(e);
      renderError(body, e, () => handle(false));
      updateTitle(host);
    }
  };
  window.addEventListener('hashchange', () => handle(false));
  handle(true);
  return { reload: () => handle(false) };
}

export function navigate(route) {
  window.location.hash = route;
}
