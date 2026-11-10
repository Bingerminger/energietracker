// =====================================================================
// v3.12.0 (M3) — Register der Suche: jede Einstellung und jede Seite, die wie eine wirkt.
//
// Die Felder kommen aus GROUPS (views/settings-fields.js), was dort nicht steht (eigene Karten,
// Verbindungen, Wetter), aus EXTRA. Texte zur Laufzeit aus dem Katalog: Die Suche findet in der
// gewählten Sprache, dazu immer über den Schlüssel der Einstellung. tests/settings-search.test.mjs
// verlangt jeden Schlüssel aus SettingsService::DEFAULTS, außer den vier ohne Wirkung.
//
// Sichtbar nach Rolle: `admin` fehlt für Mitglieder, wie auf den Seiten selbst. Über der Stufe steht
// der Eintrag unter „Weitere zeigen“; der Sprung öffnet das Feld, ohne die Stufe zu wechseln.
// =====================================================================

import { t, tp, hasTranslation } from './i18n.js';
import { viewLevel, atLeast } from './levels.js';
import { docUrl } from './docs.js';

const set = (p) => ({ href: p === 'general' ? '#/settings' : `#/settings/${p}`, gate: `settings:${p}`, area: 'nav.settings', title: `settings.page.${p}.title` });
const hh = (p) => ({ href: `#/household/${p}`, gate: `household:${p}`, area: 'nav.household', title: `household.${p}.tab` });
const co = (p) => ({ href: `#/connections/${p}`, gate: `connections:${p}`, area: 'nav.connections', title: `connections.${p}.title` });
const view = (href, gate, area, title) => ({ href, gate, area, title });

/** Wo eine Karte oder ein Feld steht (Schlüssel wie `page` in GROUPS). */
export const PLACES = {
  general: set('general'), region: set('region'), display: set('display'), data: set('data'),
  access: set('access'), calculation: set('calculation'), system: set('system'),
  'household:meters': hh('meters'), 'household:people': hh('people'), 'household:building': hh('building'), 'household:utilities': hh('utilities'),
  'connections:reading': co('reading'), 'connections:homeassistant': co('homeassistant'), 'connections:evcc': co('evcc'),
  'connections:calendar': co('calendar'), 'connections:ai': co('ai'), 'connections:keys': co('keys'), 'connections:homenet': co('homenet'),
  weather: view('#/connections/weather', 'temperatures', 'nav.connections', 'nav.weather'),
  haNotify: { ...co('homeassistant'), href: '#/connections/homeassistant/notifications', title: 'settings.haNotify.title' },
  haMeters: { ...co('homeassistant'), href: '#/connections/homeassistant/meters', title: 'connections.ha.tab.meters' },
  haTemplates: { ...co('homeassistant'), href: '#/connections/homeassistant/templates', title: 'connections.ha.tab.templates' },
  aiConnect: { ...co('ai'), href: '#/connections/ai/connect', title: 'connections.ai.tab.connect' },
  history: view('#/todo/history', 'activity', 'nav.todo', 'nav.activity'),
  dates: view('#/todo/dates', 'reminders', 'nav.todo', 'nav.reminders'),
  contracts: view('#/contracts', 'contracts-overview', 'nav.group.costs', 'nav.contracts'),
  tariffs: view('#/tariffs', 'tariffs', 'nav.group.costs', 'nav.tariffs'),
  billCheck: view('#/bill-check', 'bill-check', 'nav.group.costs', 'nav.billCheck'),
  tenancy: view('#/tenancy', 'tenancy', 'nav.group.costs', 'nav.tenancy'),
  figures: view('#/figures', 'figures', 'nav.group.analysis', 'nav.figures'),   // v3.12.0 (M4)
  contractsPast: view('#/contracts/past', 'contracts-past', 'nav.group.costs', 'nav.contractsPast'),   // v3.12.0 (M4)
  analysis: view('#/analysis', 'analysis', 'nav.group.analysis', 'nav.analysis'),
  forecast: view('#/forecast', 'forecast', 'nav.group.analysis', 'nav.forecast'),
  flexibility: view('#/flexibility', 'flexibility', 'nav.group.analysis', 'nav.flexibility'),
  report: view('#/report', 'report', 'nav.group.analysis', 'nav.report'),
};

// key: Schlüssel der Einstellung oder Name der Stelle; field: Sprungmarke (`data-field`, `data-key`);
// also: weitere Schlüssel, die hier wohnen; terms: Katalogtexte, die mitgesucht werden
const EXTRA = [
  // Für dich
  { key: 'ui_level', label: 'level.switch', place: 'general', field: 'ui_level', terms: ['settings.level.title', 'settings.level.hint'] },
  { key: 'setup', label: 'setup.restart', place: 'general', field: 'setup', also: ['setup_pending', 'setup_persona'] },
  { key: 'device_language', label: 'settings.lang.label', place: 'general', field: 'device_language', terms: ['settings.lang.deviceHint', 'settings.lang.personHint'] },
  { key: 'theme', label: 'settings.theme.title', place: 'general', field: 'theme', terms: ['settings.theme.light', 'settings.theme.dark', 'settings.theme.hint'] },
  { key: 'my_account', label: 'settings.security.change', place: 'general', field: 'my_account', login: true, terms: ['settings.security.logoutEverywhere', 'settings.security.newPassword'] },
  // Region und Sprache
  { key: 'language', label: 'settings.lang.defaultLabel', place: 'region', field: 'language', terms: ['settings.lang.forAll', 'settings.lang.defaultHint'] },
  { key: 'country', label: 'settings.region.country', place: 'region', field: 'country', terms: ['settings.region.countryHint'] },
  { key: 'currency', label: 'settings.region.currency', place: 'region', field: 'currency' },
  { key: 'timezone', label: 'settings.region.timezone', place: 'region', field: 'timezone' },
  // Haushalt
  { key: 'meters', label: 'household.meters.tab', place: 'household:meters', terms: ['meters.newMeter', 'meters.merge', 'meters.card.sub', 'meters.info.topologyLabel', 'meters.groupsBar.label', 'meters.replace.title', 'meters.heatLimit.title'] },
  { key: 'people', label: 'settings.people.title', place: 'household:people', field: 'people' },
  { key: 'active_utilities', label: 'settings.activeUtils.title', place: 'household:utilities', field: 'active_utilities', terms: ['settings.activeUtils.hint'] },
  { key: 'concept', label: 'concept.title', place: 'household:utilities', field: 'concept', terms: ['concept.entry.text'] },
  { key: 'gas_cv_unit', label: 'settings.gasFactors.cvUnit', place: 'household:utilities', field: 'gas_cv_unit', level: 'advanced' },
  // Daten und Sicherung
  { key: 'export', label: 'settings.export.title', place: 'data', field: 'export' },
  { key: 'backup', label: 'settings.backup.title', place: 'data', field: 'backup' },
  // Zugang und Sicherheit
  { key: 'security', label: 'settings.security.title', place: 'access', field: 'security', admin: true, terms: ['settings.security.hint', 'settings.security.enable'] },
  { key: 'users', label: 'settings.users.title', place: 'access', field: 'users', admin: true, terms: ['settings.users.hint'] },
  { key: 'security_check', label: 'settings.securityCheck.title', place: 'access', field: 'security_check' },
  // Rechnen, System
  { key: 'default_updates', label: 'settings.defaultUpdates.title', place: 'calculation', field: 'default_updates', terms: ['settings.defaultUpdates.hint'] },
  { key: 'about', label: 'settings.system.aboutTitle', place: 'system', field: 'about' },
  { key: 'support', label: 'settings.support.title', place: 'system', field: 'support' },
  { key: 'diagnostics', label: 'settings.diag.title', place: 'system', field: 'diagnostics' },
  // Post und Fotos auslesen
  { key: 'inbox_provider', label: 'settings.reading.title', place: 'connections:reading', field: 'inbox_provider', terms: ['settings.reading.hint'] },
  { key: 'ocr_endpoint', label: 'settings.field.ocr_endpoint.label', place: 'connections:reading', field: 'ocr_endpoint' },
  { key: 'ocr_api', label: 'settings.field.ocr_api.label', place: 'connections:reading', field: 'ocr_api' },
  { key: 'inbox_model', label: 'settings.field.inbox_model.label', place: 'connections:reading', field: 'inbox_model' },
  { key: 'inbox_timeout_s', label: 'settings.field.inbox_timeout_s.label', place: 'connections:reading', field: 'inbox_timeout_s' },
  { key: 'inbox_cloud_enabled', label: 'settings.reading.modeCloud', place: 'connections:reading', field: 'inbox_cloud_enabled', terms: ['settings.inboxCloud.enabledHint'] },
  { key: 'inbox_cloud_provider', label: 'settings.inboxCloud.provider', place: 'connections:reading', field: 'inbox_cloud_provider' },
  { key: 'inbox_cloud_endpoint', label: 'settings.inboxCloud.endpoint', place: 'connections:reading', field: 'inbox_cloud_endpoint' },
  { key: 'inbox_cloud_model', label: 'settings.inboxCloud.model', place: 'connections:reading', field: 'inbox_cloud_model' },
  { key: 'inbox_cloud_timeout_s', label: 'settings.inboxCloud.timeout', place: 'connections:reading', field: 'inbox_cloud_timeout_s' },
  // Home Assistant
  { key: 'ha_token', label: 'settings.ha.title', place: 'connections:homeassistant', terms: ['settings.ha.generate', 'settings.ha.revoke'] },
  { key: 'ha_aliases', label: 'connections.ha.tab.meters', place: 'haMeters', terms: ['settings.ha.colAlias', 'settings.ha.saveAliases'] },
  { key: 'ha_templates', label: 'connections.ha.tab.templates', place: 'haTemplates', terms: ['settings.ha.setupTitle', 'settings.ha.copyYaml'] },
  { key: 'ha_notify_enabled', label: 'settings.haNotify.enabled', place: 'haNotify', field: 'ha_notify_enabled', terms: ['settings.haNotify.title', 'settings.haNotify.intro'] },
  { key: 'ha_notify_target', label: 'settings.haNotify.targetLabel', place: 'haNotify', field: 'ha_notify_target' },
  { key: 'ha_notify_url', label: 'settings.haNotify.url', place: 'haNotify', field: 'ha_notify_url' },
  { key: 'ha_notify_service', label: 'settings.haNotify.service', place: 'haNotify', field: 'ha_notify_service' },
  { key: 'ha_notify_events', label: 'settings.haNotify.events', place: 'haNotify', field: 'ha_notify_events' },
  { key: 'ha_notify_hour', label: 'settings.haNotify.hour', place: 'haNotify', field: 'ha_notify_hour' },
  // Wetter, Kalender, KI, Schlüssel
  { key: 'location_name', label: 'settings.field.location_name.label', place: 'weather', field: 'location_name' },
  { key: 'latitude', label: 'settings.field.latitude.label', place: 'weather', field: 'latitude' },
  { key: 'longitude', label: 'settings.field.longitude.label', place: 'weather', field: 'longitude' },
  { key: 'weather_auto_fill', label: 'settings.field.weather_auto_fill.label', place: 'weather', field: 'weather_auto_fill' },
  { key: 'calendar', label: 'connections.calendar.title', place: 'connections:calendar', terms: ['calendar.intro'] },
  { key: 'mcp_enabled', label: 'settings.mcp.enabled', place: 'connections:ai', field: 'mcp_enabled', admin: true, terms: ['settings.mcp.title', 'settings.mcp.enabledHint'] },
  { key: 'mcp_share_identifiers', label: 'settings.mcp.shareIdentifiers', place: 'connections:ai', field: 'mcp_share_identifiers', admin: true },
  { key: 'mcp_share_documents', label: 'settings.mcp.shareDocuments', place: 'connections:ai', field: 'mcp_share_documents', admin: true },
  { key: 'mcp_apply_in_app', label: 'settings.mcp.applyInApp', place: 'connections:ai', field: 'mcp_apply_in_app', admin: true },
  { key: 'ai_connect', label: 'connections.ai.tab.connect', place: 'aiConnect', admin: true, terms: ['settings.mcp.connectTitle'] },
  { key: 'api_keys', label: 'settings.security.keysTitle', place: 'connections:keys', admin: true, terms: ['connections.keys.title', 'connections.keys.aiTitle'] },
  // Verlauf, Termine
  { key: 'activity_keep_months', label: 'activity.manage.title', place: 'history', field: 'activity_keep_months', admin: true, terms: ['activity.manage.keep', 'activity.manage.keepHint'] },
  { key: 'dates', label: 'nav.reminders', place: 'dates' },
  // Keine eigene Oberfläche
  { key: 'efficiency_class_thresholds', label: 'settings.search.efficiency', href: () => docUrl('api'), place: 'system', apiOnly: true },
  // Seiten, die wie eine Einstellung wirken
  { key: 'contracts', label: 'nav.contracts', place: 'contracts' },
  { key: 'tariffs', label: 'nav.tariffs', place: 'tariffs' },
  { key: 'bill_check', label: 'nav.billCheck', place: 'billCheck' },
  { key: 'tenancy', label: 'nav.tenancy', place: 'tenancy' },
  { key: 'figures', label: 'nav.figures', place: 'figures', terms: ['dashboard.efficiency.title', 'dashboard.tanks.title', 'dashboard.stromSaldo.title', 'dashboard.explainers.title'] },
  { key: 'contractsPast', label: 'nav.contractsPast', place: 'contractsPast', terms: ['contractsPast.subtitle'] },
  { key: 'analysis', label: 'nav.analysis', place: 'analysis' },
  { key: 'forecast', label: 'nav.forecast', place: 'forecast' },
  { key: 'flexibility', label: 'nav.flexibility', place: 'flexibility' },
  { key: 'report', label: 'nav.report', place: 'report' },
];

const RANK = { beginner: 0, advanced: 1, expert: 2 };
const higher = (a, b) => (RANK[a] >= RANK[b] ? a : b);
const has = (k) => hasTranslation(k);
const text = (k) => { try { return has(k) ? t(k) : ''; } catch { return ''; } };

/**
 * @param {Array} groups  GROUPS aus views/settings-fields.js
 * @returns {Array<{key: string, title: string, where: string, href: string, level: string, admin: boolean, login: boolean, apiOnly: boolean, haystack: string}>}
 */
export function buildIndex(groups) {
  const out = [];
  const add = ({ key, title, place, field, level, admin = false, login = false, terms = [], also = [], href = null, apiOnly = false }) => {
    const p = PLACES[place];
    const where = [text(p.area), text(p.title)].join(' › ');
    const target = href ? href() : field ? `${p.href}${p.href.includes('?') ? '&' : '?'}field=${field}` : p.href;
    const hay = [title, where, key, ...also, ...terms.map(x => (x.startsWith('=') ? x.slice(1) : text(x)))];
    out.push({ key, title, where, href: target, level: higher(viewLevel(p.gate), level || 'beginner'), admin, login, apiOnly, haystack: normalize(hay.join(' ')) });
  };
  for (const g of groups) {
    const groupTitle = text(`settings.group.${g.gkey}.title`);
    // Felder der Kachel und die Karte als Ganzes; Mitglieder sehen keine Karten unter „Zugang“
    const admin = g.page === 'access';
    for (const f of g.fields) {
      add({ key: f.key, title: text(`settings.field.${f.key}.label`), place: g.page, field: f.key, level: g.level, admin,
        terms: [`=${groupTitle}`, `settings.field.${f.key}.hint`, `settings.group.${g.gkey}.hint`, `settings.search.terms.${g.gkey}`] });
    }
  }
  for (const e of EXTRA) add({ ...e, title: text(e.label) });
  return out;
}

/** Klein, ohne Akzente, ß als ss: „Zähler“, „zahler“ und „ZÄHLER“ sind dasselbe. */
export function normalize(s) {
  return String(s ?? '').toLowerCase().replace(/ß/g, 'ss').normalize('NFD').replace(/\p{M}/gu, '').replace(/\s+/g, ' ').trim();
}

/** Wer „Zaehler“ tippt, meint „Zähler“. */
const unfold = (w) => w.replace(/ae/g, 'a').replace(/oe/g, 'o').replace(/ue/g, 'u');

const wordHits = (hay, word) => hay.includes(word) || hay.includes(unfold(word));

/**
 * @param {Array} index
 * @param {string} query
 * @param {{admin?: boolean, login?: boolean, isAllowed?: (level: string) => boolean}} [who]
 * @returns {{shown: Array, more: Array}} `more` liegt über der Stufe („Weitere zeigen“)
 */
export function searchIndex(index, query, { admin = true, login = false, isAllowed = atLeast } = {}) {
  const words = normalize(query).split(' ').filter(Boolean);
  if (!words.length) return { shown: [], more: [] };
  const score = (e) => {
    const title = normalize(e.title);
    const q = words.join(' ');
    if (title === q || e.key === q) return 0;
    if (title.startsWith(q)) return 1;
    if (words.every(w => wordHits(title, w))) return 2;
    return 3;
  };
  const hits = index
    .filter(e => (admin || !e.admin) && (login || !e.login))
    .filter(e => words.every(w => wordHits(e.haystack, w)))
    .map(e => [score(e), e])
    .sort((a, b) => a[0] - b[0] || a[1].title.localeCompare(b[1].title))
    .map(x => x[1]);
  return { shown: hits.filter(e => isAllowed(e.level)), more: hits.filter(e => !isAllowed(e.level)) };
}

/** Text über den Treffern, für den Vorleser und alle. */
export const countText = (n) => tp('settings.search.count', n, { count: n });
