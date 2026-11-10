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
//     Zu tun, Einstellungen) bekommen Tabs über der Ansicht.
//   • Der Dokumenttitel nennt die Ansicht („Prognose · Energietracker").
//   • `#/pfad?schlüssel=wert` — die Query steht der Ansicht in `ctx.query`.
// =====================================================================

import { t } from './lib/i18n.js';
import { escapeHtml } from './lib/format.js';
import { closeAllModals } from './components/modal.js';
import { renderError } from './components/error.js';
import { setNavigationSignal, api } from './api.js';
import { activeUtilities, getSettingsSync } from './state.js';
import { sectionPages } from './lib/nav-model.js';
import { viewAllowed, viewLevel, saveLevel } from './lib/levels.js';
import { icon, artIcon } from './lib/icons.js';

// Pfad (ohne „#" und Query) → Ansicht. Alte Adressen bleiben gültig.
const ROUTES = [
  [/^\/?$/,                          'dashboard'],
  [/^\/dashboard$/,                  'dashboard'],
  [/^\/zaehlerstaende$/,             'readings-entry'],
  [/^\/inbox$/,                      'inbox'],          // v3.7.0 — Posteingang
  [/^\/inbox\/draft$/,               'inbox-draft'],    // v3.7.0 — Haushalt aus Dokumenten (vor der Vorschau)
  [/^\/inbox\/([^/]+)$/,             'inbox'],          // v3.7.0 — ein Dokument: Vorschau
  [/^\/utility\/([^/]+)\/contracts$/, 'contracts'],
  [/^\/utility\/([^/]+)$/,           'utility'],
  [/^\/contracts$/,                  'contracts-overview'],
  [/^\/contracts\/past$/,             'contracts-past'],   // v3.12.0 (M4) — Frühere Verträge
  [/^\/tariffs$/,                    'tariffs'],
  [/^\/bill-check$/,                 'bill-check'],
  [/^\/tenancy$/,                    'tenancy'],        // v3.1.0 (H3, F1008)
  [/^\/figures$/,                    'figures'],        // v3.12.0 (M4) — Auswertungen › Kennzahlen
  [/^\/analysis$/,                   'analysis'],
  [/^\/forecast$/,                   'forecast'],
  [/^\/forecast\/print$/,             'scenario-print'],   // v3.6.0 (F1031) — Szenario drucken
  [/^\/flexibility$/,                'flexibility'],   // v3.3.0 (F1021)
  [/^\/report$/,                     'report'],
  [/^\/report\/print$/,              'report-print'],   // v3.1.0 (I18N-12)
  [/^\/todo$/,                       'todo'],           // v3.12.0 — Offen, mit Empfehlungen und KI-Vorschlägen
  [/^\/todo\/dates$/,                'reminders'],
  [/^\/todo\/history$/,              'activity'],       // v3.4.0 (F1024) — Erledigt mit Verlauf
  [/^\/todo\/history\/ai$/,          'activity-ai'],    // v3.12.0 — Zugriffe der KI
  [/^\/settings$/,                   'settings'],
  [/^\/settings\/([a-z]+)$/,         'settings'],   // v2.12.0 — Unterseiten
  [/^\/household$/,                  'household'],      // v3.12.0 (M3) — Zähler, Personen, Gebäude, Verbrauchsarten
  [/^\/household\/([a-z]+)$/,        'household'],
  [/^\/connections$/,                'connections'],    // v3.12.0
  [/^\/connections\/weather$/,        'temperatures'],
  [/^\/connections\/([a-z]+)$/,       'connections'],
  [/^\/connections\/([a-z]+)\/([a-z]+)$/, 'connections'],
  [/^\/help$/,                       'help'],           // v2.13.0
  [/^\/help\/doc\/(.+)$/,            'help-doc'],       // v3.4.0 (N1019) — Doku vom eigenen Server
  [/^\/help\/setups$/,               'help-setups'],    // v3.12.0 (Paket wegweiser)
  [/^\/help\/setups\/([a-z-]+)$/,    'help-setups'],
];

// v3.12.0 — Weiterleitungen: alte Adresse → neue, dauerhaft. Eine Zeile je Umzug, die Query
// bleibt erhalten (`?field=`, `?rec=`). Lesezeichen, Kalender und Home Assistant tragen alte
// Adressen nach außen, deshalb fällt hier nie etwas weg. Welche Server-Ausgaben Adressen nennen,
// prüft tests/redirects.test.mjs. Eine Funktion darf null liefern: Dann bleibt die Adresse, wo sie ist.
export const REDIRECTS = [
  [/^\/reminders$/, '/todo/dates'],
  [/^\/recommendations$/, '/todo?art=rec'],
  [/^\/activity$/, '/todo/history'],
  [/^\/temperatures$/, '/connections/weather'],
  [/^\/settings\/temperatures$/, '/connections/weather'],
  [/^\/settings\/integrations$/, (_m, q) => integrationsTarget(q)],
  [/^\/settings\/expert$/, (_m, q) => fieldTarget(q) || '/settings/calculation'],
  // v3.12.0 (M3) — Haushalt ist ein eigener Bereich, Einstellungen sind nach Themen geordnet
  [/^\/utility\/([^/]+)\/meters$/, (m) => `/household/meters?art=${m[1]}`],
  [/^\/settings\/household$/, (_m, q) => fieldTarget(q) || '/household/building'],
  [/^\/settings\/utilities$/, (_m, q) => fieldTarget(q) || '/household/utilities'],
  [/^\/settings\/general$/, (_m, q) => fieldTarget(q) || '/settings'],
  [/^\/settings$/, (_m, q) => fieldTarget(q)],
];

// Felder, die aus Einstellungen › Integrationen und › Experte unter Verbindungen gezogen sind (`?field=`)
const FIELD_TARGETS = [
  [/^(inbox_|ocr_)/, '/connections/reading'],
  [/^evcc_/, '/connections/evcc'],
  [/^home_(ca_pem|tls_insecure)$/, '/connections/homenet'],
  [/^ha_notify_/, '/connections/homeassistant/notifications'],
  [/^mcp_/, '/connections/ai'],
  // v3.12.0 (M3) — alte Lesezeichen mit `?field=` auf Allgemein, Haushalt & Gebäude, Verbrauchsarten, Experte
  [/^(language|country|currency|timezone)$/, '/settings/region'],
  [/^person_name$/, '/household/people'],   // „Personen verwalten“ aus v3.11 (Zugang)
  [/^(dashboard_months|forecast_months|alert_days_since_reading|update_check|support_hint|env_name|contract_remind_days_[123]|reminder_(warn_days_before|overdue_days))$/, '/settings/display'],
  [/^(wohnflaeche_m2|gebaeudetyp|beheizter_keller|warmwasser_\w+|wohnverhaeltnis|waerme_energietraeger|reference_\w+|wasser_\w+|letter_\w+)$/, '/household/building'],   // m4b: letter_* (Angaben für Briefe)
  [/^(active_utilities|billing_cycle_anchor_\w+|gas_\w+|hdd_base_temp|heizoel_kwh_per_l|pellets_kwh_per_kg|tank_warn_pct|flex_\w+|pv_assumed_self_consumption_pct)$/, '/household/utilities'],
  [/^(co2_\w+|min_days_period|min_hdd_regression|blend_max|forecast_model|segmented_\w+|confidence_band_sigma|anomaly_threshold|recommendation_\w+|delivery_baseload_share)$/, '/settings/calculation'],
  [/^attachments_max_mb$/, '/settings/data'],
];
const fieldTarget = (q) => {
  const field = q?.get?.('field') || '';
  return FIELD_TARGETS.find(([p]) => p.test(field))?.[1] || null;
};
const integrationsTarget = (q) => (q?.get?.('mcp') ? '/connections/ai' : fieldTarget(q) || '/connections');

/**
 * Ziel einer Weiterleitung als Hash, sonst null. Query des Ziels und der alten Adresse
 * zusammen; was die alte Adresse mitbringt, gilt.
 * @param {string} path  Pfad ohne „#“ und Query
 * @param {URLSearchParams} [query]
 * @param {Array} [redirects]
 * @returns {string|null}
 */
export function redirectFor(path, query = new URLSearchParams(), redirects = REDIRECTS) {
  for (const [pattern, to] of redirects) {
    const m = path.match(pattern);
    if (!m) continue;
    const target = typeof to === 'function' ? to(m, query) : to;
    if (!target) continue;
    const [p, q = ''] = String(target).split('?');
    const merged = new URLSearchParams(q);
    for (const [k, v] of query) merged.set(k, v);
    const rest = merged.toString();
    return `#${p}${rest ? `?${rest}` : ''}`;
  }
  return null;
}

// Ansicht → Modul und Bereich. `tab` nennt den Tab, der bei einer Unterseite
// aktiv ist (die Verträge einer Verbrauchsart gehören zu „Verträge").
const VIEWS = {
  'dashboard':          { section: 'overview',    load: () => import('./views/dashboard.js') },
  'readings-entry':     { section: 'capture',     load: () => import('./views/readings-entry.js') },
  'inbox':              { section: 'inbox',       load: () => import('./views/inbox.js') },   // v3.7.0
  'inbox-draft':        { section: 'inbox', tab: 'inbox', load: () => import('./views/inbox-draft.js') },   // v3.7.0 — Haushalt aus Dokumenten
  'utility':            { section: 'consumption', load: () => import('./views/utility.js') },
  'contracts':          { section: 'costs', tab: 'contracts-overview', load: () => import('./views/contracts.js') },
  'contracts-overview': { section: 'costs',       load: () => import('./views/contracts-overview.js') },
  'contracts-past':     { section: 'costs',       load: () => import('./views/contracts-past.js') },   // v3.12.0 (M4)
  'tariffs':            { section: 'costs',       load: () => import('./views/tariff.js') },
  'bill-check':         { section: 'costs',       load: () => import('./views/bill-check.js') },
  'tenancy':            { section: 'costs',       load: () => import('./views/tenancy.js') },
  'figures':            { section: 'analysis',    load: () => import('./views/figures.js') },   // v3.12.0 (M4)
  'analysis':           { section: 'analysis',    load: () => import('./views/analysis.js') },
  'forecast':           { section: 'analysis',    load: () => import('./views/forecast.js') },
  'scenario-print':     { section: 'analysis', tab: 'forecast', load: () => import('./views/scenario-print.js') },   // v3.6.0 (F1031)
  'flexibility':        { section: 'analysis',    load: () => import('./views/flexibility.js') },   // v3.3.0 (F1021)
  'report':             { section: 'analysis',    load: () => import('./views/report.js') },
  'report-print':       { section: 'analysis', tab: 'report', load: () => import('./views/report-print.js') },
  'todo':               { section: 'todo',        load: () => import('./views/todo.js') },   // v3.12.0
  'reminders':          { section: 'todo',        load: () => import('./views/reminders.js') },
  'activity':           { section: 'todo',        load: () => import('./views/activity.js') },   // v3.4.0 (F1024)
  'activity-ai':        { section: 'todo', tab: 'activity', load: () => import('./views/activity-ai.js') },   // v3.12.0
  'settings':           { section: 'settings',    load: () => import('./views/settings.js') },
  'household':          { section: 'household',   load: () => import('./views/household.js') },   // v3.12.0 (M3)
  'connections':        { section: 'connections', load: () => import('./views/connections.js') },   // v3.12.0
  'temperatures':       { section: 'connections', load: () => import('./views/temperatures.js') },
  'help':               { section: 'help',        load: () => import('./views/help.js') },
  'help-doc':           { section: 'help', tab: 'help', load: () => import('./views/help-doc.js') },   // v3.4.0 (N1019)
  'help-setups':        { section: 'help', tab: 'help', load: () => import('./views/help-setups.js') },   // v3.12.0 (Paket wegweiser)
};

// Bereiche, deren Seiten als Tabs über der Ansicht stehen. Die Verbrauchsarten
// stehen am Mac in der Seitenleiste; ihre Tabs zeigt nur das iPhone (CSS).
const TABBED = new Set(['consumption', 'costs', 'analysis', 'todo', 'settings', 'household']);

let navSeq = 0;
let current = null;   // { token, controller, cleanup }

/** `#/pfad?x=1` → { path: '/pfad', query: URLSearchParams } */
export function parseHash(hash) {
  const raw = String(hash || '').replace(/^#/, '');
  const i = raw.indexOf('?');
  const path = (i < 0 ? raw : raw.slice(0, i)) || '/';
  return { path, query: new URLSearchParams(i < 0 ? '' : raw.slice(i + 1)) };
}

/** Ansicht zu einem Pfad (für die Prüfung, dass jede Adresse des Servers ankommt). */
export function routeExists(path, routes = ROUTES) {
  return resolve(path, routes) !== null;
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
  const pages = sectionPages(section, { utilities, tenant: getSettingsSync().wohnverhaeltnis === 'miete' });
  if (pages.length < 2) return '';
  const cls = section === 'consumption' ? 'section-tabs section-tabs--mobile' : 'section-tabs';
  return `<nav class="${cls}" aria-label="${escapeHtml(t('nav.sectionNav'))}">
    ${pages.map(p => {
      const active = p.view === activeKey;
      return `<a class="section-tabs__tab${active ? ' active' : ''}" href="${p.href}"${active ? ' aria-current="page"' : ''}${p.utility ? ` data-utility="${escapeHtml(p.utility)}"` : ''}>
        ${p.utility ? `${artIcon(p.utility, { size: 16 })} ` : ''}${escapeHtml(p.label)}${p.badge ? ` <span data-badge="${p.badge}"></span>` : ''}
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

/** Titel des Dokuments aus der Überschrift der Ansicht, ohne Emoji (ein Name kann eins tragen). */
function updateTitle(host) {
  const h1 = host.querySelector('h1');
  const text = (h1?.textContent || '').replace(/[\p{Extended_Pictographic}\uFE0F]/gu, '').replace(/\s+/g, ' ').trim();
  document.title = text ? `${text} · Energietracker` : 'Energietracker';
}

/**
 * v3.10.0 (GitHub #36) — Neuzeichnen am Ort: Der alte Inhalt bleibt stehen, der
 * neue entsteht verborgen darunter (`.view-host--pending`: Höhe 0, unsichtbar,
 * aber mit Breite, damit Schaubilder ihre Größe finden). Die IDs des alten
 * Inhalts werden gelöst, sonst fände `getElementById` der neuen Ansicht das alte
 * Element. Zurück kommt der Tausch: alter Inhalt weg, Scrollposition wie vorher,
 * Fokus auf denselben Umschalter. Ohne alten Inhalt null (dann wie Navigation).
 * @returns {null|(() => void)}
 */
function holdInPlace(container, host) {
  const old = [...container.children];
  if (!old.length) return null;
  const y = window.scrollY || 0;
  const active = document.activeElement;
  const mode = container.contains(active) ? active.closest('[data-period-mode]')?.dataset.periodMode : null;
  for (const el of old) {
    el.removeAttribute('id');
    el.querySelectorAll('[id]').forEach(n => n.removeAttribute('id'));
  }
  host.classList.add('view-host--pending');
  host.setAttribute('aria-hidden', 'true');
  container.append(host);
  return () => {
    host.classList.remove('view-host--pending');
    host.removeAttribute('aria-hidden');
    container.replaceChildren(host);
    window.scrollTo(0, y);
    if (mode) host.querySelector(`[data-period-mode="${mode}"]`)?.focus({ preventScroll: true });
  };
}

/**
 * v3.10.0 (GitHub #36) — Schaubilder des alten Inhalts als Bild festhalten: Das
 * Aufräumen der alten Ansicht (Chart.js `destroy`) leert ihre Zeichenflächen und
 * setzt ihre Größe zurück. Die Seite würde kürzer, der Browser verschöbe die
 * Leseposition, und die Schaubilder wären leer, solange der neue Inhalt entsteht.
 */
function freezeCanvases(root) {
  for (const c of root.querySelectorAll('canvas')) {
    const r = c.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    try {
      const img = document.createElement('img');
      img.src = c.toDataURL();
      img.alt = '';
      img.style.cssText = `display:block;width:${r.width}px;height:${r.height}px`;
      c.replaceWith(img);
    } catch { /* ohne Bild bleibt die Zeichenfläche, wie sie ist */ }
  }
}

/**
 * v3.12.0 — Ein Tab, der über ein Update offen blieb, kennt die neuen Adressen nicht.
 * Meldet der Server eine andere Version als die geladene Seite (`body[data-app-version]`),
 * lädt die Seite einmal neu, statt bei „Unbekannte Route“ zu bleiben. Die Sperre in
 * sessionStorage verhindert eine Schleife, wenn die Adresse auch neu unbekannt ist.
 */
const RELOAD_KEY = 'et-route-reloaded';
async function reloadIfUpdated(reload) {
  const mine = document.body?.dataset.appVersion || '';
  if (!mine) return;
  let server = '';
  try { server = String((await api.health())?.version || ''); } catch { return; }
  if (!server || server === mine) return;
  try {
    if (sessionStorage.getItem(RELOAD_KEY) === server) return;
    sessionStorage.setItem(RELOAD_KEY, server);
  } catch { return; }
  reload();
}

/**
 * @param {HTMLElement} container  #view
 * @param {{routes?: Array, views?: Object, redirects?: Object, reload?: Function}} [opts]  für Tests austauschbar
 */
export function startRouter(container, { routes = ROUTES, views = VIEWS, redirects = REDIRECTS, reload = () => window.location.reload() } = {}) {
  /**
   * @param {boolean} isInitialLoad
   * @param {{inPlace?: boolean}} [opts]  v3.10.0 (GitHub #36) — dieselbe Seite neu zeichnen
   *   (Stufe, Kalender-/Abrechnungsjahr): kein „Lädt…“, kein Sprung nach oben
   */
  const handle = async (isInitialLoad = false, { inPlace = false } = {}) => {
    const token = ++navSeq;
    let { path, query } = parseHash(window.location.hash || '#/dashboard');
    // Umgezogene Seite: die Adresse ersetzen, damit „Zurück“ nicht wieder hierher führt
    for (let hops = 0; hops < 5; hops++) {
      const to = redirectFor(path, query, redirects);
      if (!to) break;
      history.replaceState(history.state, '', to);
      ({ path, query } = parseHash(to));
    }
    const match = resolve(path, routes);

    // Alte Ansicht abbauen, bevor die neue startet. Offene Dialoge gehören
    // zur alten Ansicht (v2.5.3, FE-09).
    closeAllModals('navigation');
    // v3.10.0 (#36) — am Ort: Schaubilder festhalten, bevor das Aufräumen sie leert
    if (inPlace) freezeCanvases(container);
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
    const swap = inPlace ? holdInPlace(container, host) : null;
    if (!swap) container.replaceChildren(host);

    if (!match || !views[match.view]) {
      host.innerHTML = `<div class="infobox infobox--achtung">${escapeHtml(t('errors.view.unknownRoute', { hash: window.location.hash }))}</div>`;
      document.title = 'Energietracker';
      swap?.();
      reloadIfUpdated(reload);
      return;
    }
    const { view, params } = match;
    const def = views[view];
    const activeKey = view === 'utility' ? 'utility:' + params[0]
      : view === 'settings' ? 'settings:' + (params[0] || 'general')
      : view === 'household' ? 'household:' + (params[0] || 'meters')
      : (def.tab || view);
    window.dispatchEvent(new CustomEvent('et:route', {
      detail: { view, section: def.section, key: activeKey, utility: view === 'utility' ? params[0] : null },
    }));

    // v3.2.0 (F1019) — Seite über der Nutzungsstufe (Link, Lesezeichen): sie
    // öffnet trotzdem, darüber steht der Vorschlag, umzustellen
    // v3.12.0 — Verbindungen: das Menü ist in jeder Stufe gleich, die Unterseite trägt die Stufe
    const gateKey = view === 'settings' || view === 'household' ? activeKey : view === 'connections' && params[0] ? `connections:${params[0]}` : view;
    const hinted = !viewAllowed(gateKey);
    if (hinted) host.prepend(levelHint(viewLevel(gateKey)));

    let body = host;
    if (TABBED.has(def.section)) {
      let utilities = [];
      try { utilities = await activeUtilities(); } catch { /* Tabs ohne Verbrauchsarten */ }
      if (token !== navSeq) return;
      const tabs = sectionTabsHtml(def.section, activeKey, utilities);
      if (tabs) {
        host.insertAdjacentHTML('beforeend', `${tabs}<div class="view-body"></div>`);
        body = host.querySelector('.view-body');
        window.dispatchEvent(new CustomEvent('et:badges-slots'));
      }
    }
    // v3.7.0 (Paket reifegrad) — ohne Tabs schrieb die Ansicht in den Container
    // selbst und löschte den Hinweis: Einsteiger sahen „Wechsel prüfen“,
    // „Rechnung prüfen“ und „Mietverhältnis“ (Kosten hat für sie nur einen Tab)
    // ohne „Diese Seite gehört zur Stufe …“. Mit Hinweis bekommt die Ansicht
    // einen eigenen Bereich darunter.
    if (hinted && body === host) {
      host.insertAdjacentHTML('beforeend', '<div class="view-body"></div>');
      body = host.querySelector('.view-body');
    }
    body.innerHTML = `<div class="loading" role="status">${escapeHtml(t('common.loading'))}</div>`;

    try {
      const mod = await def.load();
      if (token !== navSeq) return;                 // überholt, bevor das Modul da war
      // v3.10.0 (#36) — `inPlace`: Die Ansicht darf Nachgeladenes abwarten, bevor sie fertig meldet
      const cleanup = await mod.render(body, params, { query, signal: controller.signal, inPlace: !!swap });
      if (token !== navSeq) { runCleanup(cleanup); return; }   // verlassen, während sie lud
      entry.cleanup = typeof cleanup === 'function' ? cleanup : null;
      swap?.();
      updateTitle(host);
      if (isInitialLoad) prefetch(views);
      // A11y: bei echter Navigation (nicht beim Erst-Laden) den Fokus in den
      // Hauptbereich verschieben, damit Tastatur/Screenreader im neuen Inhalt
      // landen statt am Seitenanfang. #view trägt tabindex="-1".
      // v2.5.3 (UI-06) — preventScroll und gezielt scrollen: Der Browser
      // scrollte den Fokus unter die klebende Kopfleiste.
      // v3.10.0 (#36) — beim Neuzeichnen am Ort nicht: Seite und Fokus bleiben, wo sie sind
      if (!isInitialLoad && !swap) {
        container.focus({ preventScroll: true });
        const topbarH = document.querySelector('.topbar')?.offsetHeight || 0;
        const top = container.getBoundingClientRect().top + window.scrollY - topbarH;
        window.scrollTo(0, Math.max(0, top));
      }
    } catch (e) {
      if (token !== navSeq) return;
      console.error(e);
      renderError(body, e, () => handle(false));
      swap?.();
      updateTitle(host);
    }
  };
  window.addEventListener('hashchange', () => handle(false));
  // v3.2.0 — andere Stufe: Tabs und Ansicht neu
  // v3.10.0 (GitHub #36) — beide am Ort: Bis v3.9 zeichneten sie wie eine Navigation
  // („Lädt…“, Sprung an den Anfang), auch weiter unten bei Strom oder Wasser
  window.addEventListener('et:levelchange', () => handle(false, { inPlace: true }));
  // v3.6.0 (F1030) — Kalenderjahr / Abrechnungsjahr umgeschaltet: Ansicht neu
  window.addEventListener('et:periodmode', () => handle(false, { inPlace: true }));
  handle(true);
  return { reload: () => handle(false) };
}

/** v3.2.0 — Hinweis über einer Seite, die erst eine höhere Stufe zeigt. */
function levelHint(level) {
  const el = document.createElement('div');
  el.className = 'infobox infobox--info infobox--zeile level-hint';
  el.setAttribute('role', 'note');
  const name = t('level.' + level);
  el.innerHTML = `<span>${escapeHtml(t('level.hint.page', { level: name }))}</span>
    <span class="infobox__aktion">
      <button type="button" class="btn btn--sm btn--primary" data-act="raise">${escapeHtml(t('level.hint.switch', { level: name }))}</button>
      <button type="button" class="btn btn--sm btn--ghost" data-act="close" aria-label="${escapeHtml(t('common.close'))}">${icon('kreuz', { size: 16 })}</button>
    </span>`;
  el.querySelector('[data-act="raise"]').addEventListener('click', () => { saveLevel(level).catch(() => {}); });
  el.querySelector('[data-act="close"]').addEventListener('click', () => el.remove());
  return el;
}

export function navigate(route) {
  window.location.hash = route;
}
