// =====================================================================
// v3.12.0 — Die öffentliche Demo beginnt nach jedem Release bei null.
//
// Eine Demo, die sich Wahlen merkt, versteckt neue Funktionen vor wiederkehrenden
// Besuchern: Der Maintainer fand „Brief entwerfen“ in seiner eigenen Demo nicht,
// weil ein alter Beispielhaushalt gespeichert war. Geprüft ohne Server (fetch
// erfunden), je Fall ein frischer Stand des Moduls:
//   - gespeicherter Haushalt + alte Version → alles weg, der Assistent startet;
//   - gleiche Version → die Wahl bleibt;
//   - meta.json fehlt, hängt oder ist kaputt, Speicher gesperrt → läuft;
//   - `?persona=` und `?lang=` gelten weiter;
//   - fremde Schlüssel auf demselben Ursprung bleiben (GitHub Pages);
//   - jeder Schlüssel, den die Oberfläche ablegt, ist abgedeckt (Quelltext geprüft);
//   - eine Installation läuft nie durch diesen Code.
//
// Aufruf: node tests/demo-start.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import { readFileSync, readdirSync, statSync } from 'node:fs';

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

const META = { version: '3.12.0', built_at: '2026-11-17T08:00:00.000Z', today: '2026-11-17', languages: ['de', 'en'] };
let n = 0;

/**
 * Ein Besuch: frisches Fenster, gespeicherter Stand, erfundenes fetch, frisches Modul.
 * @param {{ stored?: object, session?: object, search?: string, meta?: object|null, status?: number, hang?: boolean, demo?: boolean, blocked?: boolean }} opts
 */
async function visit({ stored = {}, session = {}, search = '', meta = META, status = 200, hang = false, demo = true, blocked = false } = {}) {
  const dom = new JSDOM(`<!DOCTYPE html><html${demo ? ' data-demo' : ''}><head></head><body><div id="view"></div></body></html>`,
    { url: `http://127.0.0.1/${search}`, pretendToBeVisual: true });
  for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'sessionStorage']) {
    try { global[k] = dom.window[k]; }
    catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
  }
  try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch { /* egal */ }
  for (const [k, v] of Object.entries(stored)) dom.window.localStorage.setItem(k, v);
  for (const [k, v] of Object.entries(session)) dom.window.sessionStorage.setItem(k, v);
  if (blocked) {
    const refuse = () => { throw new Error('gesperrt'); };
    Object.defineProperty(global, 'localStorage', { value: { getItem: refuse, setItem: refuse, removeItem: refuse, key: refuse, get length() { return refuse(); } }, configurable: true, writable: true });
    Object.defineProperty(global, 'sessionStorage', { value: { getItem: refuse, setItem: refuse, removeItem: refuse, key: refuse, get length() { return refuse(); } }, configurable: true, writable: true });
  }
  const calls = [];
  global.fetch = (url, init = {}) => {
    calls.push({ url: String(url), init });
    if (hang) return new Promise((_, reject) => { init.signal?.addEventListener('abort', () => reject(new Error('abgebrochen'))); });
    if (String(url).endsWith('demo-api/meta.json')) {
      return Promise.resolve(meta === null ? new Response('{kaputt', { status }) : new Response(JSON.stringify(meta), { status }));
    }
    return Promise.resolve(new Response(JSON.stringify({ success: true, data: { ok: 1 } }), { status: 200 }));
  };
  // Frischer Stand des Moduls je Besuch (`metaPromise`, Marke des Baus)
  const mod = await import(`${pathToFileURL(join(JS, 'lib/demo-mode.js')).href}?besuch=${++n}`);
  return { dom, mod, calls };
}

console.log('Neues Release');
{
  const stored = {
    'et-demo-version': '3.11.0', 'et-demo-persona': 'mieterin', 'et-demo-level': 'beginner', 'et-demo-lang': 'fr', 'et-level-hints': '["a"]',
    'et-seen-version': '3.11.0', 'et-thanks-shown': '1', 'et-settings-co2-open': '1', 'et-page-size:meters': '25', 'et-period-mode': 'billing',
    'et-theme': 'dark', 'et-language': 'fr', 'et-assignee-filter': 'mine', 'et-scenarios': '[]', 'et-series-map-strom-m1': '{}', 'et.letter.sender': '{"sender":["Erika"]}',
    'et-kuenftiger-schluessel': 'x',     // ein Schlüssel, den es erst später gibt
    'anderes-projekt': 'bleibt', 'etwas': 'bleibt auch',
  };
  const { dom, mod, calls } = await visit({ stored, session: { 'et-flash': '{}', 'fremd-sitzung': '1' } });
  t('Modul weiß, dass es die Demo ist', mod.DEMO === true);
  t('vor dem Start: alter Haushalt gespeichert', mod.demoPersona(true) === 'mieterin');
  const reset = await mod.demoFreshStart();
  const ls = dom.window.localStorage;
  t('zurückgesetzt', reset === true);
  t('kein Haushalt mehr, der Assistent startet', mod.demoPersona(true) === null);
  t('… ohne Wahl gilt das Schaufenster', mod.demoPersona() === 'showcase');
  t('alle Schlüssel der Oberfläche weg, auch künftige', Object.keys(stored).filter(k => k.startsWith('et-') || k.startsWith('et.')).every(k => k === 'et-demo-version' || ls.getItem(k) === null),
    JSON.stringify(Object.keys(dom.window.localStorage)));
  t('Version gemerkt', ls.getItem('et-demo-version') === '3.12.0');
  t('fremde Schlüssel bleiben (Ursprung gehört allen Seiten des Kontos)', ls.getItem('anderes-projekt') === 'bleibt' && ls.getItem('etwas') === 'bleibt auch');
  t('Sitzung: eigene weg, fremde bleiben', dom.window.sessionStorage.getItem('et-flash') === null && dom.window.sessionStorage.getItem('fremd-sitzung') === '1');
  t('Sprache: keine gemerkte mehr, der Browser zählt', dom.window.localStorage.getItem('et-demo-lang') === null && mod.demoLanguage() === (dom.window.navigator.languages?.[0] ?? dom.window.navigator.language));
  const mc = calls.filter(c => c.url.endsWith('demo-api/meta.json'));
  t('meta.json am Cache vorbei, einmal', mc.length === 1 && mc[0].init.cache === 'no-cache');
  t('zweimal gefragt: bleibt ein Abruf', await mod.demoMeta() === await mod.demoMeta() && calls.filter(c => c.url.endsWith('demo-api/meta.json')).length === 1);
  // Das Skript in index.php lief vor dem Zurücksetzen: Der gemerkte Modus stand schon am Dokument
  dom.window.document.documentElement.setAttribute('data-theme', 'dark');
  dom.window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });
  const { reapplyStoredTheme } = await import(pathToFileURL(join(JS, 'lib/theme.js')).href);
  reapplyStoredTheme();
  t('Darstellung folgt wieder dem System', dom.window.document.documentElement.getAttribute('data-theme') === 'light' && ls.getItem('et-theme') === null);
}

console.log('Erster Besuch');
{
  const { dom, mod } = await visit();
  t('nichts gemerkt: Version gemerkt, Assistent startet', await mod.demoFreshStart() === true && dom.window.localStorage.getItem('et-demo-version') === '3.12.0' && mod.demoPersona(true) === null);
}

console.log('Besuch vor dieser Regel (Haushalt, aber keine Version)');
{
  const { dom, mod } = await visit({ stored: { 'et-demo-persona': 'mieterin', 'et-demo-level': 'expert' } });
  t('zurückgesetzt', await mod.demoFreshStart() === true && mod.demoPersona(true) === null && dom.window.localStorage.getItem('et-demo-level') === null);
}

console.log('Gleiche Version');
{
  const stored = { 'et-demo-version': '3.12.0', 'et-demo-persona': 'eigenheim-modern', 'et-demo-level': 'beginner', 'et-demo-lang': 'nl', 'et-theme': 'light' };
  const { dom, mod } = await visit({ stored });
  t('nicht zurückgesetzt', await mod.demoFreshStart() === false);
  t('die Wahl bleibt', mod.demoPersona(true) === 'eigenheim-modern' && mod.demoLanguage() === 'nl' && dom.window.localStorage.getItem('et-demo-level') === 'beginner' && dom.window.localStorage.getItem('et-theme') === 'light');
}

console.log('Älterer Bau (Rücknahme)');
{
  const { mod } = await visit({ stored: { 'et-demo-version': '3.13.0', 'et-demo-persona': 'mieterin' } });
  t('jede andere Version setzt zurück', await mod.demoFreshStart() === true && mod.demoPersona(true) === null);
}

console.log('meta.json nicht erreichbar');
{
  const stored = { 'et-demo-version': '3.11.0', 'et-demo-persona': 'mieterin' };
  for (const [name, opts] of [['404', { status: 404, meta: {} }], ['kaputt', { meta: null }], ['ohne Version', { meta: { built_at: 'x' } }]]) {
    const { dom, mod } = await visit({ stored, ...opts });
    t(`${name}: läuft, nichts gelöscht`, await mod.demoFreshStart() === false && mod.demoPersona(true) === 'mieterin' && dom.window.localStorage.getItem('et-demo-version') === '3.11.0');
  }
  // Netz hängt: die Grenze greift. Der Test wartet die echte Frist ab und bleibt damit knapp über META_TIMEOUT_MS.
  const { mod } = await visit({ stored, hang: true });
  t('Wartezeit begrenzt', mod.META_TIMEOUT_MS <= 5000, `${mod.META_TIMEOUT_MS} ms`);
  const start = Date.now();
  const reset = await mod.demoFreshStart();
  t('Netz hängt: nach der Frist weiter, nichts gelöscht', reset === false && mod.demoPersona(true) === 'mieterin' && Date.now() - start < mod.META_TIMEOUT_MS + 1500, `${Date.now() - start} ms`);
}

console.log('Speicher gesperrt');
{
  const { mod } = await visit({ blocked: true });
  let threw = null;
  let reset;
  try { reset = await mod.demoFreshStart(); } catch (e) { threw = e; }
  t('läuft, kein Fehler', threw === null && reset === false, threw?.message);
  t('Haushalt und Sprache gehen weiter ohne Speicher', mod.demoPersona() === 'showcase' && typeof mod.demoLanguage() === 'string');
}

console.log('Adresse');
{
  const stored = { 'et-demo-version': '3.11.0', 'et-demo-persona': 'mieterin', 'et-demo-lang': 'fr' };
  const { dom, mod } = await visit({ stored, search: '?persona=eigenheim-klassisch&lang=en' });
  await mod.demoFreshStart();
  t('`?persona=` gilt nach dem Zurücksetzen, kein Assistent', mod.demoPersona(true) === 'eigenheim-klassisch' && dom.window.localStorage.getItem('et-demo-persona') === 'eigenheim-klassisch');
  t('`?lang=` gilt nach dem Zurücksetzen', mod.demoLanguage() === 'en' && dom.window.localStorage.getItem('et-demo-lang') === 'en');
  const again = await visit({ stored: { 'et-demo-version': '3.12.0', 'et-demo-persona': 'mieterin' }, search: '?persona=showcase' });
  await again.mod.demoFreshStart();
  t('… und bei gleicher Version', again.mod.demoPersona(true) === 'showcase');
}

console.log('Marke des Baus');
{
  const stamp = `b=${encodeURIComponent(META.built_at)}`;
  const { mod, calls } = await visit({ stored: { 'et-demo-version': '3.12.0', 'et-demo-persona': 'mieterin' } });
  await mod.demoFreshStart();
  t('Datei-Adressen', mod.demoFileUrl('/api/reports/yearly.pdf?year=2025', 'de') === `demo-api/p/mieterin/files/de/api/reports/yearly-year-2025.pdf?${stamp}`, mod.demoFileUrl('/api/reports/yearly.pdf?year=2025', 'de'));
  t('Bilder der Doku', mod.demoDocAssetUrl('ui/screenshots/a b.png') === `demo-api/docs/ui/screenshots/a%20b.png?${stamp}`);
  await mod.demoRequest('GET', '/api/settings', 'de').catch(() => {});
  const fetched = calls.map(c => c.url).filter(u => !u.endsWith('meta.json'));
  t('Abrufe mit der Marke', fetched.length > 0 && fetched.every(u => u.includes(stamp)), fetched.join(' · '));
  const { demoKey } = await import(pathToFileURL(join(JS, 'lib/demo-key.js')).href);
  t('demoKey bleibt ohne Marke', demoKey('/api/settings') === '/api/settings');
  // auch die allererste Antwort kommt vom neuen Bau, selbst wenn noch niemand nach meta.json gefragt hat
  const early = await visit({ stored: { 'et-demo-persona': 'mieterin' } });
  await early.mod.demoRequest('GET', '/api/settings', 'de').catch(() => {});
  const earlyFetched = early.calls.map(c => c.url).filter(u => !u.endsWith('meta.json'));
  t('erster Abruf ohne vorherigen Start: wartet auf die Marke', earlyFetched.length > 0 && earlyFetched.every(u => u.includes(stamp)), earlyFetched.join(' · '));
  const plain = await visit({ status: 404, meta: {} });
  await plain.mod.demoFreshStart();
  t('ohne meta.json: Adressen ohne Marke, Demo läuft', plain.mod.demoFileUrl('/api/x.csv', 'de') === 'demo-api/files/de/api/x.csv', plain.mod.demoFileUrl('/api/x.csv', 'de'));
}

console.log('Hinweis „Neu in vX.Y“');
{
  const { mod } = await visit();
  t('passt zur Version (auch mit Korrekturstand)', mod.demoNews('3.12.0')?.href === mod.DEMO_NEWS.href && mod.demoNews('3.12.3') !== null);
  t('beim nächsten Release schweigt die Zeile, statt Altes als neu zu nennen', mod.demoNews('3.13.0') === null && mod.demoNews('3.1.0') === null && mod.demoNews('13.12.0') === null && mod.demoNews(undefined) === null);
  t('Text in jeder Sprache mit der Version', ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'].every(l =>
    JSON.parse(readFileSync(resolve(ROOT, 'public/locales', `${l}.json`), 'utf8')).demo?.news?.includes('{version}')));
}

console.log('Installation');
{
  const stored = { 'et-demo-version': '3.11.0', 'et-demo-persona': 'mieterin', 'et-theme': 'dark' };
  const { dom, mod, calls } = await visit({ stored, demo: false });
  t('Modul weiß, dass es keine Demo ist', mod.DEMO === false);
  // der einzige Aufrufer prüft DEMO zuerst; ohne das darf nichts geholt werden
  const app = readFileSync(join(JS, 'app.js'), 'utf8');
  t('app.js: nur in der Demo, vor allem anderen', /^if \(DEMO && await demoFreshStart\(\)\) reapplyStoredTheme\(\);$/m.test(app)
    && app.indexOf('demoFreshStart())') < app.indexOf('installInfoPopovers();') && app.indexOf('demoFreshStart())') < app.indexOf('presetLocale('));
  t('kein Abruf, nichts gelöscht', calls.length === 0 && dom.window.localStorage.getItem('et-theme') === 'dark');
}

console.log('Alle Fundstellen im Quelltext');
{
  const { mod } = await visit();
  const files = [];
  const walk = (d) => { for (const f of readdirSync(d)) { const p = join(d, f); if (statSync(p).isDirectory()) walk(p); else if (f.endsWith('.js')) files.push(p); } };
  walk(JS);
  // Schlüssel als Text im Aufruf oder als Konstante, die im selben Datei-Aufruf steht: alles, was nach einem
  // Namen im Browser-Speicher aussieht. Eine Fundstelle ohne erkennbaren Schlüssel ist ein Fehler, nicht „in Ordnung“.
  const keys = new Map();
  const touching = [];
  for (const f of files) {
    const src = readFileSync(f, 'utf8');
    const found = new Set();
    // Aufklapper: der Schlüssel steht beim Aufrufer, als Text oder als Konstante derselben Datei
    for (const m of src.matchAll(/(?:storageKey|collapse):\s*['"`]([^'"`]+)/g)) found.add(m[1]);
    for (const m of src.matchAll(/storageKey:\s*([A-Z_]+)\b/g)) {
      const def = src.match(new RegExp(`\\bconst\\s+${m[1]}\\s*=\\s*['"\`]([^'"\`]+)`));
      found.add(def ? def[1] : `(unbekannt: ${m[1]})`);
    }
    if (/\b(?:localStorage|sessionStorage)\b|storage\?\.(?:get|set|remove)Item/.test(src)) {
      touching.push(f.slice(JS.length + 1));
      for (const m of src.matchAll(/(?:local|session)Storage\??\.(?:get|set|remove)Item\(\s*['"`]([^'"`]+)/g)) found.add(m[1]);
      for (const m of src.matchAll(/\b(?:const|let)\s+(\w*(?:KEY|STORE|SEEN|DISMISSED)\w*|storeKey)\s*=\s*['"`]([^'"`]+)/g)) found.add(m[2]);
    } else if (found.size === 0) continue;
    keys.set(f.slice(JS.length + 1), found);
  }
  const bad = [...keys].flatMap(([f, set]) => [...set].filter(k => !mod.STORAGE_PREFIXES.some(p => k.startsWith(p))).map(k => `${f}: ${k}`));
  t('jeder Schlüssel beginnt mit einem Präfix, das der Neustart löscht', bad.length === 0, bad.join(' · ') || `${[...keys.values()].reduce((a, s) => a + s.size, 0)} Schlüssel in ${keys.size} Dateien`);
  // Wer Speicher benutzt, ohne dass die Prüfung einen Schlüssel findet, gehört begründet in diese Liste.
  // disclosure.js: Den Schlüssel gibt der Aufrufer (`storageKey`), oben geprüft.
  const known = new Set(['components/disclosure.js']);
  const silent = [...keys].filter(([f, set]) => set.size === 0 && !known.has(f)).map(([f]) => f);
  t('keine Datei mit Speicher ohne erkennbaren Schlüssel', silent.length === 0, silent.join(' · '));
  t('die Prüfung findet die bekannten Stellen', ['lib/levels.js', 'lib/theme.js', 'lib/assignee.js', 'components/pager.js', 'components/letter-dialog.js', 'views/meters.js'].every(f => touching.includes(f)), `${touching.length} Dateien`);
  const inline = readFileSync(resolve(ROOT, 'index.php'), 'utf8');
  const inlineKeys = [...inline.matchAll(/(?:local|session)Storage\.(?:get|set|remove)Item\(\s*'([^']+)'/g)].map(m => m[1]);
  t('auch index.php', inlineKeys.length >= 2 && inlineKeys.every(k => mod.STORAGE_PREFIXES.some(p => k.startsWith(p))), inlineKeys.join(', '));
  t('Präfixe wie vereinbart', mod.STORAGE_PREFIXES.join() === 'et-,et.');
}

console.log(`\ndemo-start.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
