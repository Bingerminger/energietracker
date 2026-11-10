// =====================================================================
// v3.12.0 (M2) — Verbindungen: was mit dem Energietracker spricht und was seine Daten bekommt.
//
// #/connections zeigt die Liste, in Gruppen: Daten holen, Daten geben, Zugang von außen.
// Das Menü ist in jeder Stufe gleich; Zeilen über der eigenen Stufe öffnet „Weitere
// Verbindungen zeigen“, ohne die Stufe zu wechseln. Die Unterseiten zeichnet das Gerüst der
// Einstellungen (views/settings.js, Speicherleiste und `?field=`), nur der Kopf kommt von hier.
// Wetter bleibt die Ansicht temperatures.js (Route #/connections/weather).
// Der Stand in den Zeilen kommt aus Einstellungen und Status, nichts wird dafür angefragt,
// was das Mitglied nicht lesen darf: KI und Schlüssel sehen nur Verwalter.
// =====================================================================

import { api } from '../api.js';
import { t, tp } from '../lib/i18n.js';
import { escapeHtml as esc, intlLocale } from '../lib/format.js';
import { rich } from '../lib/rich.js';
import { icon } from '../lib/icons.js';
import { atLeast, sessionRole, viewLevel } from '../lib/levels.js';
import { connectionCrumbHtml, connectionTabsHtml } from '../lib/connection-nav.js';
import { render as renderSettingsPage } from './settings.js';
import { channelsHtml } from './inbox.js';
import { openCalendarSubscription } from './reminders.js';
import { loadMcp } from '../components/mcp-card.js';

/** Unterseiten (ohne Wetter), mit ihrer Stufe in lib/levels.js */
export const CONNECTION_PAGES = ['reading', 'homeassistant', 'evcc', 'calendar', 'ai', 'keys', 'homenet'];

const stamp = (iso) => {
  const d = iso ? new Date(iso) : null;
  if (!d || Number.isNaN(d.getTime())) return '';
  return d.toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' });
};

const TAG = {
  active: ['tag--success', 'connections.tag.active'],
  on: ['tag--success', 'connections.tag.on'],
  off: ['', 'connections.tag.off'],
  notSet: ['', 'connections.tag.notSet'],
  locked: ['tag--warning', 'connections.tag.locked'],
  unchecked: ['tag--warning', 'connections.tag.unchecked'],
};
const tagHtml = (kind) => (kind ? `<span class="zeile__status"><span class="tag ${TAG[kind][0]}" data-state="${kind}">${esc(t(TAG[kind][1]))}</span></span>` : '');

function rowHtml({ id, href, ico, title, meta, tag = '' }) {
  return `<li class="zeile-wrap" id="v-${id}"><a class="zeile" href="${href}">
      <span class="zeile__icon" aria-hidden="true">${icon(ico, { size: 20 })}</span>
      <span class="zeile__text"><span class="zeile__titel">${esc(title)}</span><span class="zeile__meta">${esc(meta)}</span></span>
      ${tagHtml(tag)}
      <span class="zeile__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span></a></li>`;
}

/** Zeilen je Gruppe, mit dem echten Stand. `level` ist die Mindeststufe der Zeile. */
function rowsOf(settings, auth, mcp, admin) {
  const weatherOn = settings.weather_auto_fill !== false;
  const where = settings.location_name || '';
  const weatherMeta = where
    ? t(weatherOn ? 'connections.row.weather.metaOn' : 'connections.row.weather.metaOff', { location: where })
    : t('connections.row.weather.metaNone');
  const reading = settings.ocr_endpoint || settings.inbox_cloud_enabled === true ? 'active' : 'notSet';
  const fetchRows = [
    { id: 'post', level: 'connections:reading', href: '#/connections/reading', ico: 'eingang', title: t('connections.reading.title'), meta: t('connections.row.reading.meta'), tag: reading },
    { id: 'ha', level: 'connections:homeassistant', href: '#/connections/homeassistant', ico: 'home', title: t('connections.homeassistant.title'), meta: t('connections.row.homeassistant.meta'), tag: auth?.enabled ? 'active' : 'notSet' },
    { id: 'wetter', level: 'temperatures', href: '#/connections/weather', ico: 'sonne', title: t('nav.weather'), meta: weatherMeta, tag: where && weatherOn ? 'active' : 'off' },
    { id: 'evcc', level: 'connections:evcc', href: '#/connections/evcc', ico: 'auto', title: t('connections.evcc.title'), meta: t('connections.row.evcc.meta'), tag: settings.evcc_endpoint ? 'on' : 'off' },
  ];
  const n = mcp?.proposals?.length || 0;
  const last = mcp?.journal?.[0];
  const kiMeta = [n ? tp('connections.ai.proposals', n, { count: n }) : '', last ? t('connections.row.ai.last', { when: stamp(last.at) }) : ''].filter(Boolean).join(', ') || t('connections.row.ai.meta');
  const giveRows = [
    { id: 'kalender', level: 'connections:calendar', href: '#/connections/calendar', ico: 'kalender', title: t('connections.calendar.title'), meta: t('connections.row.calendar.meta') },
    ...(admin ? [{ id: 'ki', level: 'connections:ai', href: '#/connections/ai', ico: 'ki', title: t('settings.mcp.title'), meta: kiMeta,
      tag: mcp?.status?.locked_by_env ? 'locked' : mcp?.status?.enabled ? 'on' : 'off' }] : []),
    { id: 'benachr', level: 'connections:homeassistant', href: '#/connections/homeassistant/notifications', ico: 'merker', title: t('connections.notifications.title'), meta: t('connections.row.notifications.meta'), tag: settings.ha_notify_enabled ? 'on' : 'off' },
  ];
  const accessRows = [
    ...(admin ? [{ id: 'schluessel', level: 'connections:keys', href: '#/connections/keys', ico: 'schluessel', title: t('connections.keys.title'), meta: t('connections.row.keys.meta') }] : []),
    { id: 'tls', level: 'connections:homenet', href: '#/connections/homenet', ico: 'schloss', title: t('connections.homenet.title'), meta: t('connections.row.homenet.meta'),
      tag: settings.home_tls_insecure ? 'unchecked' : settings.home_ca_pem ? 'active' : '' },
  ];
  return [['fetch', fetchRows], ['give', giveRows], ['access', accessRows]];
}

async function renderList(container) {
  container.innerHTML = `<div class="loading" role="status">${esc(t('common.loading'))}</div>`;
  const admin = sessionRole() === 'admin';
  const [settings, auth, mcp] = await Promise.all([
    api.settings(),
    api.authStatus().catch(() => null),
    admin ? loadMcp() : null,
  ]);
  const groups = rowsOf(settings, auth, mcp, admin);
  let showAll = false;
  const draw = () => {
    const shown = (r) => showAll || atLeast(viewLevel(r.level));
    const hidden = groups.some(([, rows]) => rows.some(r => !shown(r)));
    container.innerHTML = `
      <div class="view-header">
        <div>
          <h1 class="view-header__title">${esc(t('nav.connections'))}</h1>
          <div class="view-header__subtitle">${rich('connections.subtitle')}</div>
        </div>
      </div>
      <div class="infobox infobox--info" data-role="connections-way">${rich('connections.way')}</div>
      ${groups.map(([g, rows]) => {
        const list = rows.filter(shown);
        return list.length ? `<section class="card zeilen" data-role="connections-${g}">
          <div class="zeilen__kopf"><h2 class="card__title">${esc(t(`connections.group.${g}`))}</h2></div>
          <ul class="zeilen__liste">${list.map(rowHtml).join('')}</ul>
        </section>` : '';
      }).join('')}
      ${hidden ? `<button type="button" class="btn btn--ghost btn--sm" id="weitere-zeigen">${esc(t('connections.more'))}</button>` : ''}`;
    container.querySelector('#weitere-zeigen')?.addEventListener('click', () => {
      showAll = true;
      draw();
      container.querySelector('.zeile')?.focus();
    });
  };
  draw();
}

function renderCalendarPage(container) {
  container.innerHTML = `${connectionCrumbHtml()}
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${esc(t('connections.calendar.title'))}</h1>
        <div class="view-header__subtitle">${esc(t('connections.calendar.subtitle'))}</div>
      </div>
    </div>
    <div class="settings-grid"><div class="card settings-card" id="calendar-card">
      <h2 class="card__title">${icon('kalender')} ${esc(t('calendar.subscribe'))}</h2>
      <p class="settings-card__hint">${esc(t('calendar.intro'))}</p>
      <button type="button" class="btn" id="rem-subscribe">${esc(t('calendar.subscribe'))}</button>
    </div></div>`;
  container.querySelector('#rem-subscribe').addEventListener('click', () => openCalendarSubscription());
}

/** Kopf einer Unterseite: Rücksprung und, wo es mehrere Seiten gibt, Reiter. */
function topOf(page, sub, extra = {}) {
  const crumb = connectionCrumbHtml();
  if (page === 'homeassistant') {
    const base = '#/connections/homeassistant';
    return crumb + connectionTabsHtml([
      { href: base, label: t('connections.ha.tab.connect'), active: !sub },
      { href: `${base}/meters`, label: t('connections.ha.tab.meters'), active: sub === 'meters' },
      { href: `${base}/notifications`, label: t('connections.ha.tab.notifications'), active: sub === 'notifications' },
      { href: `${base}/templates`, label: t('connections.ha.tab.templates'), active: sub === 'templates' },
    ]);
  }
  if (page === 'ai' && sessionRole() === 'admin') {
    return crumb + connectionTabsHtml([
      { href: '#/connections/ai', label: t('connections.ai.tab.status'), active: !sub },
      ...(extra.locked ? [] : [{ href: '#/connections/ai/connect', label: t('connections.ai.tab.connect'), active: sub === 'connect' }]),
      { href: '#/todo?art=ki', label: t('connections.ai.tab.proposals'), badge: extra.proposals || 0 },
      { href: '#/todo/history/ai', label: t('connections.ai.tab.access') },
    ]);
  }
  return crumb;
}

const SUBS = { homeassistant: ['meters', 'notifications', 'templates'], ai: ['connect'] };

export async function render(container, params = [], ctx = {}) {
  const page = CONNECTION_PAGES.includes(params?.[0]) ? params[0] : null;
  if (!page) return renderList(container);
  if (page === 'calendar') return renderCalendarPage(container);
  const sub = SUBS[page]?.includes(params?.[1]) ? params[1] : '';
  const extra = {};
  let after = '';
  if (page === 'ai' && sessionRole() === 'admin') {
    const [open, status] = await Promise.all([api.mcpProposals().catch(() => []), api.mcpStatus().catch(() => null)]);
    extra.proposals = Array.isArray(open) ? open.length : 0;
    extra.locked = !!status?.locked_by_env;
  }
  // Überwachter Ordner und Kurzbefehl „Teilen“: dieselbe Zeile wie im Posteingang
  if (page === 'reading') after = channelsHtml((await api.inbox().catch(() => null))?.folder);
  return renderSettingsPage(container, [], { ...ctx, connection: { page, sub, top: topOf(page, sub, extra), after } });
}
