// =====================================================================
// v3.5.0 (F1028) — Zuständig an Terminen ohne Server: Filter „Alle · Meine
// Aufgaben · Ohne Zuständigkeit · <Namen>“, „Meine Aufgaben“ per Person oder
// Name (Groß-/Kleinschreibung egal), „Ich bin …“ je Gerät, Chip, Werte für
// den Server aus dem Dialog. Dazu (F1029/Nachtrag F1024) die Bereiche des
// Verlaufs und die CSV-Abfrage aus dem Filter.
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

console.log(`\nassignee.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
