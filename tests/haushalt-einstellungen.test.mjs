// =====================================================================
// v3.12.0 (M3) — Haushalt und Einstellungen ohne Server (Antworten erfunden, Form wie die API):
// Register und Suche mit Tastatur, „Weitere zeigen“ ohne Stufenwechsel, Sprung ans Feld auch über der
// Stufe, beide Jahrestabellen auf einer Seite, Zähler an einem Ort mit Filter und Baum, Messkonzept als
// zweite Box, Sicherheits-Check einmal, Rollen.
//
// Aufruf: node tests/haushalt-einstellungen.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..');
const JS = pathToFileURL(resolve(ROOT, 'public', 'js')).href;

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${info ? ` — ${info}` : ''}`);
};
const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><div id="app"><main id="view"></main></div><div id="modal-root"></div><div id="toast-root"></div><div id="toast-stack"></div></body></html>',
  { url: 'http://127.0.0.1/', pretendToBeVisual: true });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'KeyboardEvent', 'localStorage', 'sessionStorage', 'getComputedStyle', 'MutationObserver', 'URLSearchParams']) {
  try { global[k] = dom.window[k]; } catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch { /* */ }
dom.window.scrollTo = () => {};
global.scrollTo = () => {};
dom.window.Element.prototype.scrollIntoView = () => {};
dom.window.matchMedia = dom.window.matchMedia || (() => ({ matches: false, addEventListener() {}, removeEventListener() {} }));
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

const UTIL = (key, label) => ({ key, label, color: '#336699', unit: 'kWh', consumption_unit: 'kWh', reading_kind: 'cumulative', meter_roles: ['main'] });
const STATE = {
  settings: {
    language: 'de', country: 'DE', currency: 'EUR', timezone: 'Europe/Berlin', ui_level: 'beginner', active_utilities: ['gas', 'strom'],
    contract_remind_days_1: 90, contract_remind_days_2: 30, contract_remind_days_3: 1, reminder_warn_days_before: 14, wohnflaeche_m2: 120,
    co2_strom_years: { 2023: 380 }, co2_price_eur_t_years: { 2026: 55 }, gas_conversion_factors: [{ from: null, zustandszahl: null, brennwert: null, kwh_per_m3: 10.5 }], attachments_max_mb: 20,
  },
  utilities: [UTIL('gas', 'Gas'), UTIL('strom', 'Strom')],
  meters: {
    gas: [{ id: 'm1', name: 'Hauptzähler Gas', active: true, devices: [{ id: 'd1', serial: 'X1', installed_on: '2020-01-01' }] },
      { id: 'm2', name: 'Werkstatt', active: true, parent_meter_id: 'm1', devices: [] }],
    strom: [{ id: 'm3', name: 'Hauptzähler Strom', active: true, devices: [] }],
  },
  session: { mode: 'off', authenticated: true, role: 'admin' },
  defaults: [],
  writes: [],
  inboxCloud: { enabled: false, ready: false, provider: 'openai', preset: 'custom', model: '', timeout_s: 60,
    presets: [{ id: 'custom', provider: 'openai', label: '', has_key: false }],
    providers: [{ id: 'openai', label: 'OpenAI-kompatibel', sends: 'text', destination: 'api.example.com' }] },
  haNotify: { enabled: false, target: 'supervisor', supervisor_available: true, url: '', service: 'persistent_notification.create',
    events: [], hour: 8, has_webhook: false, has_token: false, last: null },
};
const json = (data, status = 200) => new Response(JSON.stringify({ success: status < 400, data }), { status, headers: { 'Content-Type': 'application/json' } });
global.fetch = async (url, opts = {}) => {
  const u = String(url);
  const m = u.match(/locales\/([a-z]+)\.json/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  if (/locales\/languages\.json/.test(u)) return new Response(readFileSync(resolve(ROOT, 'public/locales/languages.json'), 'utf8'), { status: 200 });
  const method = (opts.method || 'GET').toUpperCase();
  if (method !== 'GET') STATE.writes.push(`${method} ${u}`);
  // v3.12.0 — Karten mit verborgenen Bereichen (Lokal | Cloud, Ziel der Benachrichtigung)
  if (/\/api\/inbox\/cloud$/.test(u)) return json(STATE.inboxCloud);
  if (/\/api\/homeassistant\/notify$/.test(u)) return json(STATE.haNotify);
  const mm = u.match(/\/api\/utility\/([a-z_]+)\/meters$/);
  if (mm) return json(STATE.meters[mm[1]] || []);
  if (/meter-groups$/.test(u)) return json([]);
  if (/default-updates/.test(u)) return json(STATE.defaults);
  if (/\/api\/settings$/.test(u)) { if (method === 'PATCH') Object.assign(STATE.settings, JSON.parse(opts.body || '{}')); return json(STATE.settings); }
  if (/\/api\/utilities$/.test(u)) return json(STATE.utilities);
  if (/\/api\/countries/.test(u)) return json([{ code: 'DE', currency: 'EUR', timezone: 'Europe/Berlin' }]);
  if (/\/api\/session$/.test(u)) return json(STATE.session);
  if (/\/api\/diagnostics/.test(u)) return json({ php_version: '8.4', app_version: '3.12.0', security: { notices: [] } });
  if (/\/api\/(users|people)$/.test(u)) return json([]);
  if (/\/api\/snapshots/.test(u)) return json([]);
  return json([]);
};

const levels = await import(`${JS}/lib/levels.js`);
const i18n = await import(`${JS}/lib/i18n.js`);
await i18n.initI18n('de');
const Settings = await import(`${JS}/views/settings.js`);
const Household = await import(`${JS}/views/household.js`);
const nm = await import(`${JS}/lib/nav-model.js`);
const view = document.getElementById('view');
console.error = () => {};
const beWho = (role, level) => { STATE.session = { mode: role === 'admin' ? 'off' : 'password', authenticated: true, role, ...(role === 'admin' ? {} : { user: { id: 'u1', name: 'Mia', role, source: 'password', prefs: {} } }) }; levels.setSession(STATE.session); levels.applyLevel(level); };
const page = async (name, query = '') => { view.innerHTML = ''; await Settings.render(view, name ? [name] : [], { query: new URLSearchParams(query) }); await tick(5); };
const house = async (name, query = '') => { view.innerHTML = ''; await Household.render(view, name ? [name] : [], { query: new URLSearchParams(query) }); await tick(5); };
const $ = (s) => view.querySelector(s);
const $$ = (s) => [...view.querySelectorAll(s)];
const type = (q) => { const i = $('#such-feld'); i.value = q; i.dispatchEvent(new dom.window.Event('input', { bubbles: true })); };
const key = (el, k) => el.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));

// ── Register ──
beWho('admin', 'beginner');
await page('');
t('Register: Suche, Für dich, Für alle, Steht woanders', !!$('#such-feld') && !!$('[data-role="for-you"]') && !!$('[data-role="for-all"]') && !!$('[data-role="elsewhere"]'));
t('Überschrift Einstellungen, nicht der Name des Reiters', $('h1').textContent === i18n.t('nav.settings'));
const topics = () => $$('[data-role="topics"] a').map(a => a.getAttribute('href')).join();
t('Einsteiger: Themen seiner Stufe', topics() === '#/settings/region,#/settings/display,#/settings/data', topics());
$('#themen-zeigen').click();
t('„Weitere Themen zeigen“ öffnet alle, Stufe bleibt', topics().includes('#/settings/calculation') && topics().includes('#/settings/access') && !$('#themen-zeigen') && levels.currentLevel() === 'beginner', topics());
t('Für dich: Stufe, Einrichtung, Sprache, Darstellung', !!$('#level-select-settings') && !!$('#btn-setup-wizard') && !!$('#lang-select') && !!$('#theme-select'));
t('Mein Konto fehlt ohne Anmeldung', !$('[data-field="my_account"]'));
t('Steht woanders führt zu Haushalt und Verbindungen', !!$('[data-role="elsewhere"] a[href="#/household"]') && !!$('[data-role="elsewhere"] a[href="#/connections"]'));
t('Keine korrigierten Standardwerte: keine Zeile', !$('a[href="#/settings/calculation?field=default_updates"]'));
STATE.defaults = [{ key: 'co2_strom', current: 400, recommended: 380 }];
await page('');
t('Korrigierte Standardwerte: Zeile auch für Einsteiger', !!$('a[href="#/settings/calculation?field=default_updates"]'));
await page('calculation');
t('… und die Karte unter Rechnen', !!$('#default-updates') && !!$('#btn-take-defaults'));
STATE.defaults = [];

// ── Darstellung, ein Weg mit dem Knopf oben ──
await page('');
const theme = $('#theme-select');
theme.value = 'dark';
theme.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
t('Darstellung wirkt sofort', document.documentElement.getAttribute('data-theme') === 'dark' && localStorage.getItem('et-theme') === 'dark');
theme.value = 'system';
theme.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
t('Wie das System: nichts gemerkt', localStorage.getItem('et-theme') === null);

// ── Suche ──
await page('');
type('Wohnfläche');
t('Tippen zeigt Treffer', !!$('[data-search-key="wohnflaeche_m2"] a[href="#/household/building?field=wohnflaeche_m2"]'));
t('Treffer nennt den Ort', /Haushalt/.test($('[data-search-key="wohnflaeche_m2"]').textContent));
t('Zahl für den Vorleser', /\d+ Treffer/.test($('#such-status').textContent), $('#such-status').textContent);
key($('#such-feld'), 'ArrowDown');
t('Pfeil runter: erster Treffer', document.activeElement === $('[data-search-key] a'));
key(document.activeElement, 'ArrowUp');
t('Pfeil hoch: zurück ins Feld', document.activeElement === $('#such-feld'));
type('Sprache');
const links = $$('#such-treffer a.zeile');
links[0].focus();
key(links[0], 'ArrowDown');
t('Pfeil läuft durch die Treffer', links.length > 1 && document.activeElement === links[1]);
key(document.activeElement, 'Escape');
t('Escape leert und führt zurück ins Feld', $('#such-feld').value === '' && $('#such-treffer').hidden && document.activeElement === $('#such-feld'));
let clicked = '';
type('Wohnfläche');
$('#such-treffer a.zeile').addEventListener('click', (e) => { clicked = e.currentTarget.getAttribute('href'); e.preventDefault(); });
key($('#such-feld'), 'Enter');
t('Eingabe öffnet den ersten Treffer', clicked === '#/household/building?field=wohnflaeche_m2', clicked);
type('Vertragsfrist');
t('Über der Stufe: nicht in der ersten Liste', !$('[data-search-key="contract_remind_days_1"]') && !!$('#such-mehr'));
$('#such-mehr').click();
t('„Weitere zeigen“ zeigt ihn, Stufe bleibt', !!$('[data-search-key="contract_remind_days_1"]') && !$('#such-mehr') && levels.currentLevel() === 'beginner');
type('Vertragsfrist');
t('Neues Tippen setzt zurück', !!$('#such-mehr'));
type('xyzzy');
t('Kein Treffer: Satz statt Liste', /Nichts gefunden/.test($('.such__leer')?.textContent || ''));
beWho('member', 'expert');
await page('');
type('Hostnamen');
t('Mitglied: Verwalter-Feld nicht in den Treffern', !$('[data-search-key="allowed_hosts"]'));
t('Mitglied: Mein Konto da', !!$('[data-field="my_account"]'));
type('Passwort');
t('Mitglied: Passwort ändern gefunden', !!$('[data-search-key="my_account"]'));
beWho('admin', 'expert');
await page('');
type('Hostnamen');
t('Verwalter: Hostnamen gefunden', !!$('[data-search-key="allowed_hosts"]'));
t('Experte: kein „Weitere zeigen“', !$('#such-mehr'));

// ── Weitere zeigen auf einer Seite ──
beWho('admin', 'beginner');
await page('display');
const hiddenCards = () => $$('.settings-card[data-min-level]').length;
t('Einsteiger: Erinnerungen tragen die Stufe', hiddenCards() >= 2 && !!$('#weitere-zeigen'));
$('#weitere-zeigen').click();
t('„Weitere zeigen“ öffnet sie, Stufe bleibt', hiddenCards() === 0 && !$('#weitere-zeigen') && levels.currentLevel() === 'beginner');
t('… und der Fokus steht in der ersten', !!document.activeElement?.closest?.('.settings-card'));
beWho('admin', 'expert');
await page('display');
t('Experte: kein Knopf', !$('#weitere-zeigen'));

// ── Sprung ans Feld ──
beWho('admin', 'beginner');
await page('display', 'field=contract_remind_days_1');
const f1 = $('[data-key="contract_remind_days_1"]');
t('Sprung über der Stufe: Karte offen', !f1.closest('[data-min-level]'));
t('… die andere bleibt verborgen', !!$('[data-key="dashboard_months"]').closest('[data-min-level]'));
t('… Fokus und Marke am Feld', document.activeElement === f1 && f1.closest('.settings-field').hasAttribute('data-target'));
t('… Stufe bleibt', levels.currentLevel() === 'beginner');
beWho('admin', 'expert');
await page('calculation', 'field=co2_strom_years');
const box = $('[data-year-key="co2_strom_years"]');
t('Feld ohne Gestalt: Ziel ist die Tabelle', box.hasAttribute('data-target') && document.activeElement === box);
t('… im aufgeklappten Bereich', !!box.closest('details')?.open);
await page('calculation', 'field=../x');
t('Unsinn in der Adresse: kein Sprung', !$('[data-target]'));

// Feld in einem verborgenen Bereich (Lokal | Cloud): Ziel ist die Karte
{
  const { jumpToField } = await import(`${JS}/lib/field-jump.js`);
  view.innerHTML = '<div class="card" id="k"><h2>Karte</h2><div hidden><input data-field="inbox_cloud_model"></div></div>';
  jumpToField(view, new URLSearchParams('field=inbox_cloud_model'));
  await tick(5);
  t('Verborgener Bereich: Fokus auf der Karte, nicht im Nichts', document.activeElement === $('#k') && $('#k').hasAttribute('data-target'));
  // Die Adresse trägt einen Feldnamen, keinen Selektor: Sonst sprünge sie auf ein beliebiges Element
  view.innerHTML = '<div class="card" id="k"><h2>Karte</h2></div>';
  const fremd = jumpToField(view, new URLSearchParams({ field: 'x"],[id="k' }));
  await tick(5);
  t('Selektor in der Adresse: kein Sprung', fremd === null && !$('#k').hasAttribute('data-target'));
  // v3.12.0 (M3b) — ein Mitglied findet die Vorgabe für alle, das Feld ist gesperrt und kann keinen Fokus halten
  view.innerHTML = '<div class="field settings-field" id="r"><label for="tz">Zeitzone</label><select id="tz" data-field="timezone" disabled><option>Europe/Berlin</option></select></div>';
  jumpToField(view, new URLSearchParams('field=timezone'));
  await tick(5);
  t('Gesperrtes Feld: Fokus auf dem Rahmen, nicht im Nichts', document.activeElement === $('#r') && $('#r').hasAttribute('data-target') && $('#r').getAttribute('tabindex') === '-1', document.activeElement?.id);
}

// v3.12.0 — Feld in einem verborgenen Bereich, der seine Wahl nennt: „Das Feld steht unter **Cloud**.“ und
// „Zeigen“. Der Knopf klappt nur auf (dieselbe Wahl wie ein Klick), gespeichert wird nichts.
{
  const conn = async (pg, sub, query) => {
    view.innerHTML = '';
    await Settings.render(view, [], { connection: { page: pg, sub }, query: new URLSearchParams(query) });
    await tick(5);
  };
  const note = () => $('[data-role="jump-hidden"]');
  const show = () => note()?.querySelector('[data-act="show"]');
  beWho('admin', 'expert');
  STATE.settings.inbox_provider = 'local';
  STATE.writes = [];
  await conn('reading', '', 'field=inbox_cloud_model');
  const card = $('#inbox-reading-card');
  t('Cloud verborgen: Hinweis an der Karte', !!card && !!note() && card.contains(note()) && $('[data-role="rd-cloud"]').hidden);
  t('… nennt den Bereich fett, Satz aus dem Katalog',
    note()?.querySelector('strong')?.textContent === i18n.t('settings.reading.modeCloud')
    && note()?.querySelector('.infobox__text')?.textContent === i18n.t('settings.jump.hiddenUnder', { place: i18n.t('settings.reading.modeCloud') }).replace(/\*\*/g, ''),
    note()?.innerHTML);
  t('… steht bei der Wahl, Fokus auf „Zeigen“', note()?.previousElementSibling === $('[data-role="rd-mode"]')
    && document.activeElement === show() && show()?.textContent === i18n.t('settings.jump.show') && card.hasAttribute('data-target'));
  show().click();
  await tick(5);
  t('„Zeigen“ klappt Cloud auf', !$('[data-role="rd-cloud"]').hidden && $('[data-role="rd-local"]').hidden
    && $('input[name="rd-mode"][value="cloud"]').checked);
  t('… Hinweis weg, Fokus und Marke am Feld', !note() && document.activeElement === $('#ic-model')
    && $('#ic-model').closest('.settings-field').hasAttribute('data-target') && !card.hasAttribute('data-target'), document.activeElement?.id);
  t('… nichts gespeichert', STATE.writes.length === 0, STATE.writes.join());
  // Gegenrichtung: Cloud ist gewählt, das Feld steht unter Lokal
  STATE.settings.inbox_provider = 'openai';
  await conn('reading', '', 'field=ocr_endpoint');
  t('Lokal verborgen: Hinweis nennt Lokal', note()?.querySelector('strong')?.textContent === i18n.t('settings.reading.modeLocal'), note()?.innerHTML);
  STATE.settings.inbox_provider = 'local';
  // Feld im sichtbaren Bereich: kein Hinweis
  await conn('reading', '', 'field=ocr_endpoint');
  t('Sichtbarer Bereich: kein Hinweis, Fokus am Feld', !note() && document.activeElement === $('#rd-endpoint'), document.activeElement?.id);
  // Mitglied: die Wahl ist gesperrt, „Zeigen“ klappt trotzdem auf, gespeichert wird nichts
  beWho('member', 'expert');
  STATE.writes = [];
  await conn('reading', '', 'field=inbox_cloud_model');
  t('Mitglied: Hinweis bei gesperrter Wahl', !!show() && $('input[name="rd-mode"][value="cloud"]').disabled);
  show()?.click();
  await tick(5);
  t('… „Zeigen“ klappt auf, nichts gespeichert', !$('[data-role="rd-cloud"]').hidden && STATE.writes.length === 0, STATE.writes.join());
  // Home Assistant über den Supervisor: die Adresse steht unter „Webhook“
  beWho('admin', 'expert');
  await conn('homeassistant', 'notifications', 'field=ha_notify_url');
  t('Home Assistant: Adresse verborgen, Hinweis nennt Webhook',
    $('[data-role="han-url-field"]')?.hidden && note()?.querySelector('strong')?.textContent === i18n.t('settings.haNotify.target.webhook'), note()?.innerHTML);
  show()?.click();
  await tick(5);
  t('… „Zeigen“ wählt Webhook, Fokus an der Adresse', !$('[data-role="han-url-field"]').hidden
    && $('input[name="han-target"][value="webhook"]').checked && document.activeElement === $('#han-url') && STATE.writes.length === 0, document.activeElement?.id);
}

// ── Rechnen: beide Jahrestabellen ──
await page('calculation');
const ids = $$('[id]').map(e => e.id);
t('Rechnen: keine doppelte id', new Set(ids).size === ids.length, ids.filter((x, i) => ids.indexOf(x) !== i).join(' '));
t('Warnung über allem', !!$('[data-role="calc-warning"]') && !$('details.settings-expert'));
t('Alles aus Experte und CO₂ ist da', ['min_days_period', 'forecast_model', 'recommendation_anomaly_sigma', 'co2_price_scenario_eur_t', 'co2_gas'].every(k => !!$(`[data-key="${k}"]`)));
const addYear = (key, yid, vid, y, v) => {
  $(`#${yid}`).value = y;
  $(`#${vid}`).value = v;
  $(`[data-year-key="${key}"] [data-cy-add]`).click();
  return JSON.parse($(`[data-key="${key}"]`).value)[y] === Number(v);
};
t('CO₂ je Jahr: Eintrag landet in seiner Tabelle', addYear('co2_strom_years', 'cy-year', 'cy-value', '2031', '111'));
t('CO₂-Preis je Jahr: Eintrag landet in seiner Tabelle', addYear('co2_price_eur_t_years', 'cy-price-year', 'cy-price-value', '2032', '222'));
t('… und nicht in der anderen', !('2032' in JSON.parse($('[data-key="co2_strom_years"]').value)));
t('Änderung zeigt die Speicherleiste', $('[data-role="savebar"]').hidden === false);

// ── Daten und Sicherung, System, Zugang ──
await page('data');
t('Daten: Export, Sicherung, Belege mit Speicherleiste', !!$('[data-field="export"]') && !!$('[data-field="backup"]') && !!$('[data-key="attachments_max_mb"]') && !!$('[data-role="savebar"]'));
t('Daten: Verweis-Karte Jahresbericht entfällt', !$('#pdf-card'));
t('Belege ab Experte', $('[data-key="attachments_max_mb"]').closest('[data-min-level]')?.getAttribute('data-min-level') === 'expert');
await page('system');
t('System: Über, Unterstützen, Diagnose', !!$('[data-field="about"]') && !!$('[data-field="support"]') && !!$('[data-field="diagnostics"]'));
t('System: kein zweiter Sicherheits-Check', !$('#security-check'));
await page('access');
t('Zugang: Sicherheits-Check, Anmeldung, Hostnamen, Personen', !!$('#security-check') && !!$('[data-field="security"]') && !!$('[data-key="allowed_hosts"]') && !!$('[data-field="users"]'));
t('Zugang: Weg zu den Schlüsseln', !!$('#keys-moved a[href="#/connections/keys"]'));
beWho('member', 'expert');
await page('access');
t('Mitglied: Sicherheits-Check bleibt, keine Verwalter-Karten', !!$('#security-check') && !$('[data-field="security"]') && !$('[data-key="allowed_hosts"]'));
await page('region');
t('Region: alle Felder auch für Mitglieder (offene Entscheidung, Stand wie bisher)', !!$('#lang-default-select') && !!$('#country-select') && !!$('#currency-select') && !!$('#tz-select'));
beWho('admin', 'expert');
await page('region');
t('Region: Sprungmarken', ['language', 'country', 'currency', 'timezone'].every(k => !!$(`[data-field="${k}"]`)));
t('Region: Für dich und Für alle getrennt', !!$('[data-role="lang-for-you"]') && !!$('[data-role="lang-for-all"]') && !!$('[data-role="lang-effective"]'));
t('Region: keine Speicherleiste, Änderungen wirken sofort', !$('[data-role="savebar"]'));

// ── Haushalt ──
beWho('admin', 'beginner');
await house('utilities');
t('Messkonzept ist die zweite Box (S9)', $$('.settings-grid > .card').slice(0, 2).map(c => c.id).join() === 'active-utils-card,concept-card');
t('Verbrauchsarten: Stichtag, Kopf des Haushalts', !!$('[data-key="billing_cycle_anchor_gas"]') && $('h1').textContent === i18n.t('household.utilities.title'));
t('CO₂ steht nicht mehr hier', !$('[data-key="co2_gas"]'));
await house('building');
t('Gebäude: Felder und Speicherleiste', !!$('[data-key="wohnflaeche_m2"]') && !!$('[data-role="savebar"]'));
t('Gebäude: Personen stehen woanders', !$('[data-field="people"]'));
await house('people');
t('Personen: eigene Seite ohne Speicherleiste', !!$('[data-field="people"]') && !$('[data-role="savebar"]'));

await house('');
t('Haushalt öffnet die Zähler', !!$('[data-role="household-meters"]'));
t('Zähler an einem Ort: je aktive Art ein Abschnitt', !!$('#art-gas') && !!$('#art-strom') && $$('[data-role="household-meters"] section').length === 2);
t('Die Erklärung steht einmal', $$('.infobox--info').length === 1);
const mids = $$('[id]').map(e => e.id);
t('Zähler: keine doppelte id', new Set(mids).size === mids.length, mids.filter((x, i) => mids.indexOf(x) !== i).join(' '));
t('Eine Überschrift erster Ordnung', $$('h1').length === 1);
t('Unterzähler eingerückt unter dem Hauptzähler', !!$('#art-gas [data-role="meter-tree"] .zaehlerbaum__kinder .meter-card--sub'));
t('Knöpfe je Art', !!$('#art-gas [data-action="new-meter"]') && !!$('#art-strom [data-action="new-meter"]') && !!$('#art-gas [data-edit-meter]') && !!$('#art-gas [data-replace-device]'));
t('Filter „Alle“ aktiv', $('.section-tabs--filter [aria-current="page"]')?.getAttribute('href') === '#/household/meters');
await house('meters', 'art=gas');
t('Filter: eine Art', $$('[data-role="household-meters"] section').length === 1 && !!$('#art-gas') && $('.section-tabs--filter [aria-current="page"]')?.getAttribute('href') === '#/household/meters?art=gas');
await house('meters', 'art=gibtsnicht');
t('Unbekannte Art: Meldung statt leerer Seite', !!$('#art-gibtsnicht .infobox--fehler'));

// ── Menü ──
levels.applyLevel('beginner');
const setup = nm.sidebarModel([{ key: 'gas', label: 'Gas' }]).find(g => g.key === 'setup').children.map(c => c.key).join();
t('Einrichtung: Haushalt, Verbindungen, Einstellungen', setup === 'household,connections,settings', setup);
t('Haushalt: vier Reiter in jeder Stufe', nm.sectionPages('household').map(p => p.view).join() === 'household:meters,household:people,household:building,household:utilities');
t('Einsteiger: Reiter seiner Stufe', nm.sectionPages('settings').map(p => p.view).join() === 'settings:general,settings:region,settings:display,settings:data');
levels.applyLevel('expert');
t('Experte: alle sieben Reiter, Rechnen dabei', nm.sectionPages('settings').length === 7 && nm.sectionPages('settings').some(p => p.view === 'settings:calculation'));

console.log(`\n  haushalt-einstellungen: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
