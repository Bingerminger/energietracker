// =====================================================================
// v3.12.0 (M2) — Zu tun und Verbindungen ohne Server (Antworten erfunden, Form wie die API):
// Filter und Zahlen, keine Zeile doppelt, Vorschläge der KI mit der Zusage und dem Hinweis ohne
// Anmeldung, Notiz im Vorschlag, Rolle (Mitglieder sehen keine KI-Zeilen und fragen nichts ab),
// Stufe (das Menü bleibt, Reiter und Zeilen wirken, „Weitere“ ohne Stufenwechsel), jede Zeile
// von Verbindungen führt zu einer Seite, Wegweiser und KI-Anleitung bleiben erreichbar.
//
// Aufruf: node tests/zutun-verbindungen.test.mjs
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
  if (ok) { pass++; console.log(`  ✓ ${name}`); }
  else { fail++; console.log(`  ✗ ${name}${info ? ` — ${info}` : ''}`); }
};
const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><div id="app"><main id="view"></main></div><div id="modal-root"></div><div id="toast-root"></div><div id="toast-stack"></div></body></html>',
  { url: 'http://127.0.0.1/', pretendToBeVisual: true });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'sessionStorage', 'getComputedStyle', 'MutationObserver', 'URLSearchParams']) {
  try { global[k] = dom.window[k]; } catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch { /* */ }
dom.window.scrollTo = () => {};
global.scrollTo = () => {};
dom.window.Element.prototype.scrollIntoView = () => {};
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));
const until = async (fn, n = 120) => { for (let i = 0; i < n && !fn(); i++) await tick(10); return fn(); };

const STATE = {
  settings: { language: 'de', ui_level: 'expert', mcp_enabled: true, mcp_apply_in_app: true, location_name: 'Musterstadt', weather_auto_fill: true, evcc_endpoint: '', ha_notify_enabled: false, ocr_endpoint: '', home_ca_pem: '', home_tls_insecure: false },
  events: [
    { uid: 'rem1', kind: 'reminder', due_now: true, severity: 'overdue', date: '2026-10-01', title: 'Heizungswartung', params: { title: 'Heizungswartung' }, ref: { days_until: -7 }, href: '#/todo/dates', can_mark_done: true },
    { uid: 'rights-a', kind: 'rights', due_now: true, severity: 'warning', date: '2026-11-16', title: 'Sonderkündigung', ref: { recommendation_id: 'rights-a', deadline: '2026-11-16' }, href: '#/todo?art=rec&rec=rights-a', can_mark_done: true },
    { uid: 'spaeter', kind: 'reminder', due_now: false, severity: 'upcoming', date: '2027-01-01', title: 'Später', params: { title: 'Später' }, ref: {}, href: '#/todo/dates' },
  ],
  recs: [
    { id: 'rights-a', severity: 'warning', category: 'recht', title: 'Sonderkündigung möglich', detail: 'Der Preis steigt.', evidence: { check: 'special_termination', finding_id: 'rights-a', norms: [] } },
    { id: 'eff1', severity: 'info', category: 'effizienz', title: 'Hoher Sommerverbrauch', detail: 'Im Juli lag der Verbrauch über dem Schnitt.', evidence: {} },
  ],
  inbox: { entries: [{ id: 'd1', name: 'Jahresabrechnung Gas', status: 'read', created_at: '2026-11-01T08:00:00+01:00' }, { id: 'd2', name: 'Erledigt', status: 'filed' }], folder: { dir: 'posteingang', pending: 0 } },
  proposals: [{ id: 'mp_a', kind: 'reading', summary: 'Gas: Stand 13.200 m³ eintragen', key_name: 'Laptop', status: 'open', created_at: '2026-11-04T10:00:00+01:00', expires_at: '2026-11-05T10:00:00+01:00', untrusted: { name: 'Keller', note: 'Vom Flur abgelesen' } }],
  status: { enabled: true, setting: true, locked_by_env: false, apply_in_app: true, login_active: false, share_identifiers: false, share_documents: false },
  journal: [{ at: '2026-11-04T16:02:00+01:00', key_name: 'Laptop', name: 'list_meters', result: 'ok', ms: 12 }],
  rights: { enabled: true, bills_recorded: 1 },
  auth: { enabled: false, created_at: null },
  session: { mode: 'off', authenticated: true, role: 'admin' },
};
const calls = [];
const json = (data, status = 200) => new Response(JSON.stringify({ success: status < 400, data }), { status, headers: { 'Content-Type': 'application/json' } });
global.fetch = async (url, opts = {}) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json/) || u.match(/locales\/([a-z]+)\.json/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  const method = (opts.method || 'GET').toUpperCase();
  calls.push(`${method} ${u.replace(/^.*\/api\//, '/api/')}`);
  if (/\/api\/mcp/.test(u) && STATE.session.role !== 'admin') return json({}, 403);
  if (/\/api\/mcp\/proposals\/[^/]+\/apply$/.test(u) && method === 'POST') { STATE.proposals = []; return json({ failed: [] }); }
  if (/\/api\/mcp\/proposals\/[^/]+$/.test(u) && method === 'DELETE') { STATE.proposals = []; return json({ rejected: true }); }
  if (/\/api\/mcp\/proposals$/.test(u)) return json(STATE.proposals);
  if (/\/api\/mcp\/status$/.test(u)) return json(STATE.status);
  if (/\/api\/mcp\/journal$/.test(u)) return json(STATE.journal);
  if (/\/api\/agenda/.test(u)) return json({ events: STATE.events });
  if (/\/api\/recommendations/.test(u)) return json(STATE.recs);
  if (/\/api\/inbox\/cloud/.test(u)) return json({ enabled: false, provider: null, has_key: false, providers: [] });
  if (/\/api\/inbox/.test(u)) return json(STATE.inbox);
  if (/\/api\/rights/.test(u)) return json(STATE.rights);
  if (/\/api\/session$/.test(u)) return json(STATE.session);
  if (/\/api\/auth\/token$/.test(u)) return json(STATE.auth);
  if (/\/api\/settings$/.test(u)) return json(STATE.settings);
  if (/\/api\/utilities$/.test(u)) return json([{ key: 'gas', label: 'Gas', reading_kind: 'cumulative' }]);
  return json([]);
};

const { t: tr, setLocale, loadLocale, initI18n } = await import(`${JS}/lib/i18n.js`).then(m => ({ ...m }));
if (initI18n) await initI18n('de'); else if (setLocale) await setLocale('de'); else if (loadLocale) await loadLocale('de');
const levels = await import(`${JS}/lib/levels.js`);
const Todo = await import(`${JS}/views/todo.js`);
const Conn = await import(`${JS}/views/connections.js`);
const Hist = await import(`${JS}/views/activity.js`);
const HistAi = await import(`${JS}/views/activity-ai.js`);
const state = await import(`${JS}/state.js`);
const view = document.getElementById('view');
const q = (s) => new URLSearchParams(s);
const open = async (query = '') => { view.innerHTML = ''; await Todo.render(view, [], { query: q(query) }); await tick(20); return view; };
const conn = async (...p) => { view.innerHTML = ''; state.invalidateSettings?.(); await Conn.render(view, p, {}); await tick(20); return view; };
const count = (art) => view.querySelector(`[data-art="${art}"] [data-role="todo-count"]`)?.textContent.trim();
const rows = () => [...view.querySelectorAll('.dash-todo__list > li')];
levels.applyLevel('expert');

console.log('Zu tun › Offen');
await open();
t('Überschrift Zu tun, Kalender und Neuer Termin oben', view.querySelector('h1')?.textContent === 'Zu tun' && !!view.querySelector('#rem-subscribe') && view.querySelector('#rem-add')?.getAttribute('href') === '#/todo/dates?add=1');
t('Filter: Alle, Fristen und Termine, Empfehlungen, Rechte, Post, KI-Vorschläge', [...view.querySelectorAll('[data-art]')].map(b => b.dataset.art).join() === 'alle,frist,rec,rights,post,ki', [...view.querySelectorAll('[data-art]')].map(b => b.dataset.art).join());
t('Zahl unter „Alle“ ist die Zahl der Zeilen', Number(count('alle')) === rows().length && rows().length === 5, `${count('alle')} / ${rows().length}`);
t('KI-Vorschlag steht oben', rows()[0]?.hasAttribute('data-mcp-proposal'));
t('Nur Fälliges aus der Agenda', !/Später/.test(view.textContent));
t('Empfehlung aus der Agenda steht nur einmal', rows().filter(r => /Sonderkündigung/.test(r.textContent)).length === 1);
t('Empfehlung ohne Agenda als Zeile, sie führt zur Karte', view.querySelector('[data-role="todo-rec"] a')?.getAttribute('href') === '#/todo?art=rec&rec=eff1');
t('Post: nur was noch Arbeit macht, führt zum Dokument', view.querySelectorAll('[data-role="todo-post"]').length === 1 && view.querySelector('[data-role="todo-post"] a')?.getAttribute('href') === '#/inbox/d1');
const offen = await Todo.loadOffen();
t('Die Zahl am Menü ist dieselbe wie unter „Alle“', Todo.offenCount(offen) === 5, String(Todo.offenCount(offen)));

console.log('Vorschläge der KI');
await open('art=ki');
const row = view.querySelector('[data-mcp-proposal]');
t('Adresse wählt den Filter', view.querySelector('[data-art="ki"]')?.classList.contains('active'));
t('Satz, Schlüssel, Art und Frist', /13\.200 m³/.test(row?.textContent || '') && /Laptop/.test(row?.textContent || '') && /Zählerstand/.test(row?.textContent || '') && /Gültig bis/.test(row?.textContent || ''), row?.textContent.replace(/\s+/g, ' '));
t('Notiz und Zähler stehen im Vorschlag', /Vom Flur abgelesen/.test(row?.querySelector('[data-role="mcp-proposal-context"]')?.textContent || '') && /Keller/.test(row?.querySelector('[data-role="mcp-proposal-context"]')?.textContent || ''));
t('Zusage „nur in der App“', /„Übernehmen“/.test(view.querySelector('[data-role="mcp-proposals-hint"]')?.textContent || '') && /hier/.test(view.querySelector('[data-role="mcp-proposals-hint"]')?.textContent || ''));
t('Hinweis ohne Anmeldung', /Anmeldung ist aus/.test(view.querySelector('[data-role="mcp-no-login"]')?.textContent || ''));
t('Weg zu Stand und Schlüsseln bleibt', !!view.querySelector('a[href="#/connections/ai"]'));
row.querySelector('[data-mcp-apply]').click();
await until(() => document.querySelector('.modal [data-act="ok"]'));
t('Übernehmen fragt nach, mit Satz, Zähler und Notiz', /13\.200 m³/.test(document.querySelector('.modal [data-role="confirm-detail"]')?.textContent || '') && /Vom Flur/.test(document.querySelector('.modal [data-role="confirm-detail"]')?.textContent || ''));
document.querySelector('.modal [data-act="ok"]').click();
await until(() => calls.some(c => /proposals\/mp_a\/apply/.test(c)));
await until(() => view.querySelector('[data-role="todo-empty"]'));
t('Übernommen: POST …/apply, Liste leer, Zahl 0, Fokus bleibt auf dem Filter', calls.some(c => c === 'POST /api/mcp/proposals/mp_a/apply') && !!view.querySelector('[data-role="todo-empty"]') && count('ki') === '0' && document.activeElement?.dataset?.art === 'ki');
STATE.proposals = [{ id: 'mp_b', kind: 'reading', summary: 'Strom: Stand eintragen', key_name: 'Laptop', status: 'open' }];
await open('art=ki');
view.querySelector('[data-mcp-reject]').click();
await until(() => document.querySelector('.modal [data-act="ok"]'));
document.querySelector('.modal [data-act="ok"]').click();
await until(() => calls.some(c => /DELETE .*proposals\/mp_b/.test(c)));
await until(() => view.querySelector('[data-role="todo-empty"]'));
t('Ablehnen: DELETE, Liste leer', calls.some(c => c === 'DELETE /api/mcp/proposals/mp_b') && !!view.querySelector('[data-role="todo-empty"]'));
STATE.proposals = [{ id: 'mp_c', kind: 'reading', summary: 'Wasser', key_name: 'L', status: 'open' }];
STATE.status = { ...STATE.status, enabled: false, setting: false };
await open();
t('KI aus: Vorschlag bleibt sichtbar, nur Ablehnen', !!view.querySelector('[data-mcp-reject="mp_c"]') && !view.querySelector('[data-mcp-apply]'));
STATE.status = { ...STATE.status, enabled: true, setting: true, login_active: true };
await open('art=ki');
t('Mit Anmeldung kein Hinweis „Anmeldung ist aus“', !view.querySelector('[data-role="mcp-no-login"]'));
STATE.status = { ...STATE.status, apply_in_app: false };
await open('art=ki');
t('Bestätigung im KI-Programm: der Satz sagt es', /KI-Programm/.test(view.querySelector('[data-role="mcp-proposals-hint"]')?.textContent || ''));
STATE.status = { ...STATE.status, apply_in_app: true, login_active: false };

console.log('Empfehlungen und Rechte');
await open('art=rec&rec=rights-a');
await until(() => view.querySelector('.rec-card'));
t('Sprungmarke: volle Karte hervorgehoben', view.querySelector('[data-rec-target]')?.dataset.recId === 'rights-a' && view.querySelector('[data-art="rec"]')?.classList.contains('active'));
t('Stufen-Filter unter Empfehlungen', view.querySelectorAll('.rec-sev .seg__btn').length === 4);
t('Rechte-Hinweis mit Brief oder Handbuch, Ausblenden an der Karte', !!view.querySelector('[data-role="rights-card"] [data-role="rights-doc"], [data-role="rights-card"] [data-role="letter-button"]') && !!view.querySelector('[data-dismiss="rights-a"]'));
await open('rec=rights-a');
await until(() => view.querySelector('.rec-card'));
t('Sprungmarke allein wählt den Filter Empfehlungen', view.querySelector('[data-art="rec"]')?.classList.contains('active'));
await open('art=rights');
await until(() => view.querySelector('.rec-card'));
t('Filter Rechte zeigt nur Rechte', view.querySelectorAll('.rec-card').length === 1 && !!view.querySelector('[data-role="rights-card"]') && !view.querySelector('.rec-sev'));
const before = STATE.rights;
STATE.recs = STATE.recs.filter(r => r.category !== 'recht');
await open('art=rights');
await until(() => view.querySelector('[data-role="rights-letter-way"]'));
t('Rechte ohne Befund: der Weg über die Vertragskarte bleibt', !!view.querySelector('[data-role="rights-letter-way"] a[href="#/contracts"]'));
STATE.rights = { enabled: false, bills_recorded: 0 };
await open();
t('Land ohne Regeln: kein Filter Rechte', !view.querySelector('[data-art="rights"]'));
STATE.rights = before;
STATE.recs = [{ id: 'rights-a', severity: 'warning', category: 'recht', title: 'Sonderkündigung möglich', detail: 'x', evidence: {} }];

console.log('Verlauf');
view.innerHTML = '';
await Hist.render(view);
t('Erledigt und Zugriffe der KI als Knöpfe', !!view.querySelector('[data-role="history-pills"] a[href="#/todo/history/ai"]') && view.querySelector('[data-role="history-pills"] .pill.active')?.getAttribute('href') === '#/todo/history');
view.innerHTML = '';
await HistAi.render(view);
await until(() => view.querySelector('[data-mcp-journal]'));
t('Zugriffe: Protokoll mit Schlüssel und Aufruf, unter dem Reiter Verlauf', /Laptop/.test(view.querySelector('[data-mcp-journal]')?.textContent || '') && /list_meters/.test(view.querySelector('[data-mcp-journal]')?.textContent || ''));

console.log('Verbindungen');
await conn();
const ids = () => [...view.querySelectorAll('.zeile-wrap')].map(z => z.id.slice(2)).join();
t('Experte: alle neun Zeilen in drei Gruppen', ids() === 'post,ha,wetter,evcc,kalender,ki,benachr,schluessel,tls' && view.querySelectorAll('section.zeilen').length === 3, ids());
t('Weg zum Wegweiser', !!view.querySelector('[data-role="connections-way"] a[href="#/help/setups"]'));
const hrefs = [...view.querySelectorAll('.zeile-wrap a')].map(a => a.getAttribute('href'));
const { routeExists, redirectFor } = await import(`${JS}/router.js`);
t('Jede Zeile führt zu einer Seite', hrefs.every(h => routeExists(h.slice(1))) && hrefs.length === 9, hrefs.join(' '));
t('Stand aus den Daten: Wetter aktiv, evcc aus, KI an', view.querySelector('#v-wetter [data-state="active"]') && view.querySelector('#v-evcc [data-state="off"]') && view.querySelector('#v-ki [data-state="on"]') && /Musterstadt/.test(view.querySelector('#v-wetter')?.textContent || ''));
STATE.settings = { ...STATE.settings, evcc_endpoint: 'http://192.0.2.1:7070', home_tls_insecure: true };
await conn();
t('Stand folgt: evcc an, Heimnetz ohne Prüfung', !!view.querySelector('#v-evcc [data-state="on"]') && !!view.querySelector('#v-tls [data-state="unchecked"]'));
STATE.settings = { ...STATE.settings, evcc_endpoint: '', home_tls_insecure: false };
await conn('ai');
t('KI: Anleitung und Reiter', !!view.querySelector('[data-role="mcp-guide"]') && [...view.querySelectorAll('.section-tabs a')].map(a => a.getAttribute('href')).join() === '#/connections/ai,#/connections/ai/connect,#/todo?art=ki,#/todo/history/ai' && !!view.querySelector('[data-role="connection-crumb"] a[href="#/connections"]'));
t('KI: Schalter und Kennnummern stehen hier', !!view.querySelector('#mcp-enabled') && !!view.querySelector('#mcp-apply-in-app') && !!view.querySelector('#mcp-share-identifiers') && !!view.querySelector('#mcp-share-documents'));
t('KI: Hinweis ohne Anmeldung auch am Schalter', !!view.querySelector('#mcp-card [data-role="mcp-no-login"]'));
await conn('ai', 'connect');
t('KI verbinden: Schlüssel und Vorlagen', !!view.querySelector('#mcp-connect-card #btn-mcp-key-create') && !!view.querySelector('[data-mcp-client="openwebui"]'));
STATE.status = { ...STATE.status, locked_by_env: true };
await conn('ai');
t('ET_MCP=off: Kein Reiter Verbinden', !view.querySelector('a[href="#/connections/ai/connect"]'));
STATE.status = { ...STATE.status, locked_by_env: false };
await conn('homeassistant');
t('Home Assistant: vier Reiter, Token', view.querySelectorAll('.section-tabs a').length === 4 && !!view.querySelector('#btn-ha-generate'));
await conn('keys');
t('Schlüssel: API-Schlüssel, Wege zu Token und KI-Schlüsseln', !!view.querySelector('#keys-card #btn-key-create') && !!view.querySelector('a[href="#/connections/homeassistant"]') && !!view.querySelector('a[href="#/connections/ai/connect"]'));
await conn('reading');
t('Post und Fotos: Auslesen, Texterkennung, Ordner und Kurzbefehl', !!view.querySelector('#inbox-reading-card') && !!view.querySelector('[data-key="ocr_model"]') && !!view.querySelector('#inbox-channels'));
await conn('evcc');
t('evcc: Feld und Speicherleiste', !!view.querySelector('[data-key="evcc_endpoint"]') && !!view.querySelector('[data-role="savebar"]'));
await conn('homenet');
t('Heimnetz: Felder und Speicherleiste', !!view.querySelector('[data-key="home_ca_pem"]') && !!view.querySelector('[data-key="home_tls_insecure"]') && !!view.querySelector('[data-role="savebar"]'));
await conn('calendar');
t('Kalender: Abo von hier', !!view.querySelector('#rem-subscribe'));

console.log('Stufen');
levels.applyLevel('beginner');
await conn();
t('Einsteiger: Auslesen und Kalender, kein Stufenwechsel nötig', ids() === 'post,kalender', ids());
view.querySelector('#weitere-zeigen')?.click();
t('„Weitere Verbindungen zeigen“ öffnet alle, Stufe bleibt', ids() === 'post,ha,wetter,evcc,kalender,ki,benachr,schluessel,tls' && !view.querySelector('#weitere-zeigen') && levels.currentLevel() === 'beginner', ids());
const nm = await import(`${JS}/lib/nav-model.js`);
t('Einsteiger: dasselbe Menü', nm.sidebarModel([{ key: 'gas', label: 'Gas' }]).flatMap(g => g.children.map(c => c.key)).join() === 'dashboard,todo,readings-entry,inbox,consumption,costs,analysis,household,connections,settings');
t('Einsteiger: Offen, Termine und Verlauf', nm.sectionPages('todo').map(p => p.view).join() === 'todo,reminders,activity');
levels.applyLevel('advanced');
await conn();
t('Erfahren: ohne Schlüssel, evcc und Heimnetz', ids() === 'post,ha,wetter,kalender,ki,benachr', ids());
levels.applyLevel('expert');

console.log('Mitglied');
STATE.session = { mode: 'password', authenticated: true, role: 'member', user: { id: 'u_a', name: 'A', role: 'member', prefs: {} } };
levels.setSession(STATE.session);
calls.length = 0;
await open('art=ki');
t('Zu tun: kein Filter und keine Zeile der KI, Adresse fällt auf Alle', !view.querySelector('[data-art="ki"], [data-mcp-proposal]') && !!view.querySelector('[data-art="alle"].active'));
await conn();
t('Verbindungen: keine KI, keine Schlüssel', !view.querySelector('#v-ki, #v-schluessel') && !!view.querySelector('#v-ha'));
await conn('ai');
t('KI-Seite: ein Satz statt der Karten', !!view.querySelector('[data-role="mcp-admin-only"]') && !view.querySelector('#mcp-card'));
await conn('keys');
t('Schlüssel: nur der Hinweis', !view.querySelector('#keys-card'));
view.innerHTML = '';
await Hist.render(view);
t('Verlauf: keine Zugriffe', !view.querySelector('[data-role="history-pills"]'));
view.innerHTML = '';
await HistAi.render(view);
t('Zugriffe: ein Satz', !!view.querySelector('[data-role="mcp-admin-only"]'));
t('Kein Abruf von /api/mcp', !calls.some(c => /\/api\/mcp/.test(c)), calls.filter(c => /\/api\/mcp/.test(c)).join());

console.log('Alte Adressen');
t('Sprungmarke des Servers kommt an', routeExists('/todo') && redirectFor('/recommendations', q('rec=x')) === '#/todo?art=rec&rec=x');
const demo = readFileSync(resolve(ROOT, 'public/js/lib/demo-mode.js'), 'utf8');
t('Demo: Zeile „Neu in“ führt zur neuen Adresse', /DEMO_NEWS = \{[^}]*#\/connections\/ai\?mcp=value/.test(demo));
console.log(`\n  zutun-verbindungen: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
