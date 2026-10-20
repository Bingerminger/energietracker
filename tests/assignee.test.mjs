// =====================================================================
// v3.5.0 (F1028) — Zuständig an Terminen ohne Server: Filter „Alle · Meine
// Aufgaben · Ohne Zuständigkeit · <Namen>“, „Meine Aufgaben“ per Person oder
// Name (Groß-/Kleinschreibung egal), „Ich bin …“ je Gerät, Chip, Werte für
// den Server aus dem Dialog. Dazu (F1029/Nachtrag F1024) die Bereiche des
// Verlaufs und die CSV-Abfrage aus dem Filter. v3.6.0 — gelöschte Personen
// („Name (gelöscht)“), der Dialog „Person löschen“ und Einstellungen → Zugriff
// für Verwalter und Mitglieder.
//
// Aufruf: node tests/assignee.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..');
const JS = resolve(ROOT, 'public', 'js');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><main id="view"></main></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
global.fetch = async (url) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json$/);
  if (!m) return new Response('{}', { status: 404 });
  return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
};

const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const L = await import(`${JS}/lib/levels.js`);
const S = await import(`${JS}/lib/assignee.js`);

const anna = { assignee: 'Anna', assignee_id: 'u_anna', assignee_former: false };
const annaFree = { assignee: 'anna ', assignee_id: null, assignee_former: false };
const ben = { assignee: 'Ben', assignee_id: null, assignee_former: false };
const nobody = { assignee: null, assignee_id: null, assignee_former: false };
const former = { assignee: null, assignee_id: null, assignee_former: true };
const legacy = {};   // Termin von vor v3.5.0: Felder fehlen = niemand

console.log('Zuständigkeit');
t('fehlende Felder = niemand', S.assigneeOf(legacy) === null && S.assigneeOf(nobody) === null);
t('ehemalige Person', S.assigneeOf(former)?.former === true);
t('Name getrimmt', S.assigneeOf(annaFree).name === 'anna');

console.log('Filter');
const items = [anna, annaFree, ben, nobody, former, legacy];
const pick = (f, me) => items.filter(x => S.matchesAssignee(x, f, me));
t('Alle: alles', pick('all', null).length === items.length);
t('Ohne Zuständigkeit: niemand, ehemalige Person, alte Termine', pick('none', null).length === 3 && pick('none', null).includes(former));
t('Name: Groß-/Kleinschreibung egal, getrimmt', pick('name:anna', null).length === 2);
t('Name: nur dieser', pick('name:ben', null).length === 1);
const meLogged = { id: 'u_anna', name: 'Anna', loggedIn: true };
t('Meine Aufgaben (angemeldet): per ID und per gleichem Namen', pick('mine', meLogged).length === 2);
t('Meine Aufgaben: ID allein genügt', S.isMine({ assignee: 'Anna M.', assignee_id: 'u_anna' }, meLogged));
t('Meine Aufgaben: eine ehemalige Person ist niemand', !S.isMine(former, meLogged));
t('Meine Aufgaben ohne bekannte Person: nichts', pick('mine', null).length === 0);

console.log('„Ich bin …“ und gemerkte Wahl');
localStorage.clear();
t('ohne gemerkte Wahl: Alle', S.storedFilter() === 'all');
S.setStoredFilter('mine');
t('Wahl wird je Gerät gemerkt', S.storedFilter() === 'mine' && localStorage.getItem('et-assignee-filter') === 'mine');
S.setStoredFilter('kaputt');
t('unbekannte Wahl → Alle', S.storedFilter() === 'all');
L.setSession(null);
t('ohne Anmeldung und ohne „Ich bin“: unbekannt', S.currentMe() === null);
S.setStoredMe(' Ben ');
t('„Ich bin“ gemerkt (getrimmt)', S.currentMe()?.name === 'Ben' && S.currentMe()?.loggedIn === false);
t('… und „Meine Aufgaben“ folgt ihr', pick('mine', S.currentMe()).length === 1);
L.setSession({ mode: 'password', user: { id: 'u_anna', name: 'Anna' }, role: 'member' });
t('angemeldet: die Person der Sitzung geht vor', S.currentMe()?.id === 'u_anna' && S.currentMe()?.loggedIn === true);
L.setSession(null);
// localStorage, das wirft (privates Fenster): kein Absturz
const store = Object.getOwnPropertyDescriptor(global, 'localStorage');
Object.defineProperty(global, 'localStorage', { value: { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() { throw new Error('blocked'); } }, configurable: true, writable: true });
let ok = true;
try { S.setStoredFilter('mine'); ok = S.storedFilter() === 'all' && S.currentMe() === null; } catch { ok = false; }
t('gesperrter Speicher: kein Fehler, Alle', ok);
Object.defineProperty(global, 'localStorage', store);

console.log('Anzeige');
const chip = S.chipHtml(anna);
t('Chip mit Namen und Vorlesetext', chip.includes('chip--assignee') && chip.includes('Anna') && chip.includes('Zuständig'));
t('Chip „ehemalige Person“', S.chipHtml(former).includes('ehemalige Person') && S.chipHtml(former).includes('chip--former'));
t('kein Chip ohne Zuständigkeit', S.chipHtml(nobody) === '' && S.chipHtml(legacy) === '');
t('Chip escapt', !S.chipHtml({ assignee: '<b>x</b>' }).includes('<b>'));
t('Namen je einmal, sortiert', S.assigneeNames(items, ['ben', 'Carla']).join() === 'Anna,Ben,Carla');
const host = document.createElement('div');
host.innerHTML = S.filterHtml({ id: 'f', value: 'mine', names: ['Anna', 'Ben'], me: null });
const opts = [...host.querySelectorAll('#f option')].map(o => o.value);
t('Filter: Alle · Meine Aufgaben · Ohne Zuständigkeit · Namen', opts.join() === 'all,mine,none,name:anna,name:ben');
t('ohne Anmeldung bei „Meine Aufgaben“: „Ich bin …“', !!host.querySelector('[data-assignee-me]'));
host.innerHTML = S.filterHtml({ id: 'f', value: 'mine', names: ['Anna'], me: meLogged });
t('angemeldet: kein „Ich bin …“', !host.querySelector('[data-assignee-me]'));
let changed = 0;
host.innerHTML = S.filterHtml({ id: 'f', value: 'all', names: ['Anna'], me: null });
S.bindFilter(host, () => changed++);
const sel = host.querySelector('#f');
sel.value = 'name:anna';
sel.dispatchEvent(new window.Event('change'));
t('Wahl speichern und neu zeichnen', changed === 1 && S.storedFilter() === 'name:anna');
t('Hinweis auf Ausgeblendetes (Plural)', S.filterNoteHtml(2, 'name:anna', null).includes('2 weitere Einträge'));
t('„Meine Aufgaben“ ohne „Ich bin“: Bitte wählen', S.filterNoteHtml(0, 'mine', null).includes('Ich bin'));
localStorage.clear();

console.log('Dialog → Server');
const R = await import(`${JS}/views/reminders.js`);
const people = [{ id: 'u_anna', name: 'Anna' }, { id: 'u_ben', name: 'Ben' }];
t('Person aus der Liste → ID und ihr Name', JSON.stringify(R.assigneePayload(' anna ', null, people)) === '{"assignee":"Anna","assignee_id":"u_anna"}');
t('freier Name ohne ID', JSON.stringify(R.assigneePayload('Oma Gerda', null, people)) === '{"assignee":"Oma Gerda","assignee_id":null}');
t('leer = niemand', JSON.stringify(R.assigneePayload('  ', null, people)) === '{"assignee":"","assignee_id":null}');
t('unveränderter Name behält seine Person (auch ohne Liste)',
  JSON.stringify(R.assigneePayload('Anna', anna, [])) === '{"assignee":"Anna","assignee_id":"u_anna"}');
t('„ehemalige Person“ bleibt, bis jemand einen Namen einträgt', JSON.stringify(R.assigneePayload('', former, people)) === '{}');

console.log('Verlauf');
const A = await import(`${JS}/lib/activity.js`);
t('Bereich: Abrechnung erwartet → Verträge, geänderte Abrechnung → Mietverhältnis',
  A.AREAS.bill_expected === 'contracts' && A.AREAS.tenancy_statement === 'tenancy');
const V = await import(`${JS}/views/activity.js`);
const q = V.csvQuery({ person: 'u_anna', period: 'lastYear', area: 'tenancy' });
const y = new Date().getFullYear() - 1;
t('CSV: Filter wie die Liste (Person, Zeitraum, Arten des Bereichs), Sprache der Oberfläche',
  q.by === 'u_anna' && q.from === `${y}-01-01` && q.to === `${y}-12-31` && q.format === 'local' && q.lang === 'de'
  && q.kind.split(',').sort().join() === ['co2_claim_deadline', 'objection_deadline', 'tenancy_statement', 'tenancy_statement_due'].sort().join());

// v3.6.0 — gelöschte Personen: Name bleibt mit „(gelöscht)“, zählen zu „Ohne Zuständigkeit“
console.log('Gelöschte Person (v3.6.0)');
const gone = { assignee: 'Lukas', assignee_id: 'u_lukas', assignee_former: false, assignee_deleted: true };
t('Zuständigkeit: Name bleibt, Vermerk gelöscht', S.assigneeOf(gone)?.name === 'Lukas' && S.assigneeOf(gone)?.deleted === true);
t('Filter „Ohne Zuständigkeit“ zählt sie mit', S.matchesAssignee(gone, 'none', null));
t('Filter nach Namen zeigt sie nicht', !S.matchesAssignee(gone, 'name:lukas', null));
t('„Meine Aufgaben“: eine gelöschte Person ist niemand', !S.isMine(gone, { id: 'u_lukas', name: 'Lukas', loggedIn: true }));
t('Chip „Lukas (gelöscht)“', S.chipHtml(gone).includes('Lukas (gelöscht)') && S.chipHtml(gone).includes('chip--former'));
t('Namen für Filter und Vorschläge ohne gelöschte', S.assigneeNames([anna, gone]).join() === 'Anna');
t('Dialog: gelöschte Person, Name unverändert → bleibt (keine ID schicken)',
  JSON.stringify(R.assigneePayload('Lukas', gone, [...people, { id: 'u_lukas2', name: 'Lukas' }])) === '{}');
t('Dialog: anderer Name ersetzt die gelöschte Person', JSON.stringify(R.assigneePayload('Ben', gone, people)) === '{"assignee":"Ben","assignee_id":"u_ben"}');
t('Verlauf: „Lukas (gelöscht)“ bei der Person und als Zuständige',
  A.byText({ type: 'person', id: 'u_lukas', name: 'Lukas', deleted: true }) === 'Lukas (gelöscht)'
  && A.personText({ name: 'Lukas', id: 'u_lukas', deleted: true }) === 'Lukas (gelöscht)' && A.personText({ former: true }) === 'ehemalige Person');
t('Verlauf-Filter: Person mit Vermerk', A.personsIn([{ by: { type: 'person', id: 'u_lukas', name: 'Lukas', deleted: true } }])[0].name === 'Lukas (gelöscht)');
const P = await import(`${JS}/components/person-delete.js`);
const lines = P.handoverText({ to: { id: 'u_x', name: 'Thomas' }, open: 2, done: 1 });
t('Dialog „Person löschen“: an wen und wie viele', lines[0] === '2 offene Termine gehen an Thomas.'
  && lines[1] === '1 erledigter Eintrag im Verlauf behält den Namen mit dem Zusatz „(gelöscht)“.', lines.join(' | '));
t('… mit „ehemalige Person“', P.handoverText({ to: { name: 'Thomas' }, open: 1, done: 3 }, true)[1].includes('ehemalige Person'));
t('… ohne offene Termine', P.handoverText({ to: { name: 'Thomas' }, open: 0, done: 0 }).join() === 'Die Person hat keine offenen Termine.');
// der Dialog mit Vorschau vom Server; Löschen mit „ehemalige Person“
{
  const root = document.createElement('div');
  root.id = 'modal-root';
  document.body.appendChild(root);
  const calls = [];
  const localeFetch = global.fetch;
  global.fetch = async (url, init = {}) => {
    const u = String(url);
    if (!u.includes('/api/users/')) return localeFetch(url, init);
    calls.push(`${init.method || 'GET'} ${u.slice(u.indexOf('/api/'))}`);
    const data = u.endsWith('/handover') ? { person: { id: 'u_lukas', name: 'Lukas', no: 4 }, to: { id: 'u_x', name: 'Thomas' }, open: 2, done: 1 }
      : { deleted: true, handed_over: 2, handed_over_to: { id: 'u_x', name: 'Thomas' }, kept: 0 };
    return new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  let done = 0;
  await P.confirmPersonDelete('u_lukas', () => done++);
  const list = () => root.querySelector('[data-role="handover"]')?.textContent || '';
  t('Dialog: Vorschau vom Server', list().includes('2 offene Termine gehen an Thomas.') && list().includes('„(gelöscht)“'), list().trim());
  const anon = root.querySelector('#user-del-anon');
  anon.checked = true;
  anon.dispatchEvent(new window.Event('change'));
  t('Dialog: Haken „ehemalige Person“ ändert den Satz', list().includes('ehemalige Person'));
  root.querySelector('[data-act="ok"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  for (let i = 0; i < 20 && !done; i++) await new Promise(r => setTimeout(r, 20));
  t('Dialog: löscht mit anonymize_activity und zeichnet neu', done === 1 && calls.at(-1) === 'DELETE /api/users/u_lukas?anonymize_activity=1', calls.join(' · '));

  // Einstellungen → Zugriff: Verwalter sehen „Person löschen“ (nicht bei sich selbst) und die Nummer;
  // Mitglieder sehen keine Personen, nur den Hinweis
  let session = { mode: 'password', authenticated: true, user: { id: 'u_admin', name: 'admin', role: 'admin' }, role: 'admin' };
  const users = [{ id: 'u_admin', name: 'admin', role: 'admin', source: 'password', prefs: {}, no: 1 },
                 { id: 'u_lukas', name: 'Lukas', role: 'member', source: 'password', prefs: {}, no: 4 }];
  const writes = [];   // v3.6.0 (Paket einpers) — PATCH an Personen ohne Zugang
  global.fetch = async (url, init = {}) => {
    const u = String(url);
    if (!u.includes('/api/')) return localeFetch(url, init);
    const path = u.slice(u.indexOf('/api/')).split('?')[0];
    if (init.method && init.method !== 'GET') writes.push(`${init.method} ${path} ${init.body || ''}`);
    const data = path === '/api/session' ? session : path === '/api/users' ? users
      : path === '/api/settings' ? {} : path === '/api/diagnostics' ? null : [];
    return new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  const view = document.getElementById('view');
  const Settings = await import(`${JS}/views/settings.js`);
  await Settings.render(view, ['access']);
  const delBtns = [...view.querySelectorAll('[data-user-delete]')].map(b => b.getAttribute('data-user-delete'));
  t('Zugriff (Verwalter): „Person löschen“ für andere, nicht für sich', delBtns.join() === 'u_lukas', delBtns.join());
  t('Zugriff (Verwalter): Nummer und Hinweis zum Löschen', [...view.querySelectorAll('[data-role="person-no"]')].map(e => e.textContent).join() === 'Nr. 1,Nr. 4'
    && !!view.querySelector('[data-role="users-delete-hint"]'));
  t('Zugriff mit Anmeldung: kein Hinweis „Anmeldung einschalten“', !view.querySelector('[data-role="users-login-needed"]'));
  root.innerHTML = '';
  view.querySelector('[data-user-delete="u_lukas"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  for (let i = 0; i < 20 && !root.querySelector('[data-role="handover"]'); i++) await new Promise(r => setTimeout(r, 20));
  t('Zugriff: „Person löschen“ öffnet den Dialog mit Vorschau', !!root.querySelector('[data-role="handover"]') && !!root.querySelector('#user-del-anon'));
  root.innerHTML = '';
  // Nachtrag (Paket einpers): „Umbenennen“ auch für Zugänge (nicht per Proxy), mit dem Satz zur Anmeldung
  users.push({ id: 'u_proxy', name: 'Paula', role: 'member', source: 'proxy', prefs: {}, no: 6 });
  await Settings.render(view, ['access']);
  t('Zugriff: „Umbenennen“ für Zugänge mit Passwort, nicht für Proxy-Zugänge',
    [...view.querySelectorAll('[data-user-rename]')].map(b => b.getAttribute('data-user-rename')).join() === 'u_admin,u_lukas'
      && view.querySelector('[data-user-rename="u_lukas"]')?.hasAttribute('data-user-login'));
  users.pop();
  writes.length = 0;
  view.querySelector('[data-user-rename="u_lukas"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  t('„Umbenennen“ (Zugang): Dialog sagt, dass die Anmeldung den neuen Namen braucht', root.querySelector('#user-rename')?.value === 'Lukas'
    && root.textContent.includes('Angemeldet wird danach mit dem neuen Namen'));
  root.querySelector('#user-rename').value = 'Luke';
  root.querySelector('[data-act="ok"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  for (let i = 0; i < 20 && !writes.length; i++) await new Promise(r => setTimeout(r, 10));
  t('„Umbenennen“ (Zugang) schickt den Namen an PATCH /api/users/{id}', writes[0] === 'PATCH /api/users/u_lukas {"name":"Luke"}', writes.join(' · '));
  for (let i = 0; i < 20 && root.querySelector('#user-rename'); i++) await new Promise(r => setTimeout(r, 10));
  root.innerHTML = '';
  await Settings.render(view, ['access']);
  session = { ...session, user: { id: 'u_lukas', name: 'Lukas', role: 'member' }, role: 'member' };
  await Settings.render(view, ['access']);
  t('Zugriff (Mitglied): kein Löschen, kurzer Hinweis', !view.querySelector('[data-user-delete]')
    && view.querySelector('[data-role="member-delete-hint"]')?.textContent.includes('nur, wer verwaltet'));

  // Nachtrag: Personen ohne Zugang (Backup, Beispielhaushalt) in derselben Liste
  users.push({ id: 'u_mia', name: 'Mia', role: 'admin', source: 'none', prefs: {}, no: 5, has_account: false });
  session = { mode: 'password', authenticated: true, user: { id: 'u_admin', name: 'admin', role: 'admin' }, role: 'admin' };
  await Settings.render(view, ['access']);
  const miaRow = view.querySelector('[data-user-delete="u_mia"]')?.closest('tr');
  // v3.6.0 (Entscheidung des Maintainers, Paket einpers) — bis zum Nachtrag stand die Rolle
  // als Text da; jetzt Auswahl und „Umbenennen“ wie bei einem Zugang (PATCH /api/users/{id})
  t('Zugriff: Person ohne Zugang mit Nr., Vermerk, Löschknopf, Rolle als Auswahl und „Umbenennen“',
    !!miaRow && miaRow.querySelector('[data-role="no-access"]')?.textContent === 'ohne Zugang'
      && miaRow.textContent.includes('Nr. 5') && miaRow.querySelector('[data-user-role="u_mia"]')?.value === 'admin'
      && miaRow.querySelector('[data-user-rename="u_mia"]')?.textContent === 'Umbenennen');
  t('Zugriff: Hinweis zu Personen ohne Zugang', !!view.querySelector('[data-role="no-access-hint"]'));
  writes.length = 0;
  const miaRole = miaRow.querySelector('[data-user-role="u_mia"]');
  miaRole.value = 'member';
  miaRole.dispatchEvent(new window.Event('change', { bubbles: true }));
  for (let i = 0; i < 20 && !writes.length; i++) await new Promise(r => setTimeout(r, 10));
  t('Zugriff: Rolle einer Person ohne Zugang umstellen', writes[0] === 'PATCH /api/users/u_mia {"role":"member"}', writes.join(' · '));
  miaRole.value = 'admin';
  root.innerHTML = '';
  miaRow.querySelector('[data-user-rename="u_mia"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  const renameIn = root.querySelector('#user-rename');
  t('„Umbenennen“: Dialog mit dem Namen und dem Satz zum Verlauf', renameIn?.value === 'Mia' && root.textContent.includes('Erledigtes behält den Namen'));
  writes.length = 0;
  renameIn.value = 'Maria';
  root.querySelector('[data-act="ok"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  for (let i = 0; i < 20 && !writes.length; i++) await new Promise(r => setTimeout(r, 10));
  t('„Umbenennen“ speichert nur den Namen', writes[0] === 'PATCH /api/users/u_mia {"name":"Maria"}', writes.join(' · '));
  for (let i = 0; i < 20 && root.querySelector('#user-rename'); i++) await new Promise(r => setTimeout(r, 10));
  root.innerHTML = '';
  await Settings.render(view, ['access']);
  miaRow.querySelector('[data-user-grant]')?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  t('„Zugang anlegen“ trägt Name und Rolle unter „Person hinzufügen“ ein',
    view.querySelector('#user-new-name')?.value === 'Mia' && view.querySelector('#user-new-role')?.value === 'admin');
  // ohne Anmeldung: Karte nur mit Personen, bedienbar wie jede Einstellung, Hinweis „niemand übernimmt“
  session = { mode: 'off', authenticated: true, user: null, role: 'admin' };
  const withAccounts = users.splice(0, users.length, users.at(-1));
  await Settings.render(view, ['access']);
  t('ohne Anmeldung: Personen ohne Zugang löschbar, ohne „Zugang anlegen“',
    !!view.querySelector('[data-user-delete="u_mia"]') && !view.querySelector('[data-user-grant]')
      && view.querySelector('[data-role="users-delete-hint"]')?.textContent.includes('übernimmt beim Löschen niemand'));
  t('ohne Anmeldung: Hinweis, dass Zugänge erst mit der Anmeldung entstehen, mit Knopf',
    view.querySelector('[data-role="users-login-needed"]')?.textContent.includes('sobald die Anmeldung eingeschaltet ist')
      && !!view.querySelector('[data-role="users-enable-login"]'));
  users.splice(0, users.length);
  await Settings.render(view, ['access']);
  t('ohne Anmeldung und ohne Personen: Karte nur mit dem Hinweis zur Anmeldung',
    !view.querySelector('[data-role="users-delete-hint"]')
      && !!view.querySelector('[data-role="users-login-needed"]') && !!view.querySelector('[data-role="users-enable-login"]'));
  users.push(...withAccounts);
  t('Dialog ohne löschende Person: niemand übernimmt', P.handoverText({ to: null, open: 2, done: 0 })[0].includes('bleiben bei der Person'));
  global.fetch = async (url, init = {}) => {
    const u = String(url);
    if (!u.includes('/api/users/')) return localeFetch(url, init);
    const data = { person: { id: 'u_mia', name: 'Mia', no: 5, has_account: false }, to: null, open: 0, done: 0 };
    return new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  };
  root.innerHTML = '';
  await P.confirmPersonDelete('u_mia', () => {});
  t('Dialog: Person ohne Zugang „steht danach nicht mehr zur Wahl“', root.textContent.includes('nicht mehr zur Wahl'));
  root.innerHTML = '';
  global.fetch = localeFetch;
}

console.log(`\nassignee.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
