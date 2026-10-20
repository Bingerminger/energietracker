// v3.6.0 — „Was ist neu“ nach einem Update und Hinweis auf neue Versionen (lib/release-notice.js)
//   node tests/release-notice.test.mjs
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dir, '..');
const JS = resolve(ROOT, 'public', 'js');
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body data-app-version="3.6.0"></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'location']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
let settings = { update_check: false };
let latest = '3.7.0';
const calls = [];
global.fetch = async (url) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  const path = u.replace(/^.*api\.php/, '');
  calls.push(path);
  const json = (data) => new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  if (path === '/api/settings') return json(settings);
  if (path === '/api/update-check') return json({ enabled: true, current: '3.6.0', latest, newer: latest !== '3.6.0', url: null });
  return new Response('{}', { status: 404 });
};

const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const N = await import(`${JS}/lib/release-notice.js`);
const state = await import(`${JS}/state.js`);
const notice = () => document.querySelector('.release-notice');
const reset = () => { document.querySelectorAll('.release-notice').forEach(e => e.remove()); state.invalidateSettings?.(); calls.length = 0; };

console.log('Versionen vergleichen');
t('3.6.0 > 3.5.1', N.newer('3.6.0', '3.5.1') && !N.newer('3.5.1', '3.6.0'));
t('3.10.0 > 3.9.9 (Zahlen, kein Text)', N.newer('3.10.0', '3.9.9'));
t('gleich ist nicht neuer', !N.newer('3.6.0', '3.6.0'));

console.log('Was ist neu');
localStorage.clear(); reset();
await N.showReleaseNotices();
t('erster Start: kein Hinweis, Version gemerkt', !notice() && localStorage.getItem('et-seen-version') === '3.6.0');
localStorage.setItem('et-seen-version', '3.5.1'); reset();
await N.showReleaseNotices();
t('nach einem Update: „Aktualisiert auf 3.6.0“ mit Release-Notes und Unterstützen',
  notice()?.dataset.notice === 'updated' && /3\.6\.0/.test(notice().textContent)
  && !!notice().querySelector('a[href$="/releases/tag/v3.6.0"]') && !!notice().querySelector('[data-support-link]'));
notice().querySelector('[data-act="close"]').click();
t('Schließen merkt die Version', !notice() && localStorage.getItem('et-seen-version') === '3.6.0');
reset();
await N.showReleaseNotices();
t('danach kein zweites Mal', !notice());

console.log('Hinweis auf neue Versionen');
reset();
await N.showReleaseNotices();
t('ausgeschaltet: keine Abfrage', !calls.includes('/api/update-check') && !notice());
settings = { update_check: true }; reset();
await N.showReleaseNotices();
t('eingeschaltet: „Version 3.7.0 ist erschienen“', notice()?.dataset.notice === 'available' && /3\.7\.0/.test(notice().textContent)
  && !notice().querySelector('[data-support-link]'));
notice().querySelector('[data-act="close"]').click();
reset();
await N.showReleaseNotices();
t('für diese Version geschlossen: nicht wieder', !notice());
latest = '3.8.0'; reset();
await N.showReleaseNotices();
t('nächste Version: wieder ein Hinweis', /3\.8\.0/.test(notice()?.textContent || ''));

console.log(`\n  release-notice: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
