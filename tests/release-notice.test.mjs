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
const patches = [];   // v3.10.0 — Danke-Hinweis: „Nicht mehr zeigen“ speichert die Einstellung
global.fetch = async (url, opts = {}) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  const path = u.replace(/^.*api\.php/, '');
  calls.push(path);
  if (opts.method === 'PATCH' && path === '/api/settings') {
    const patch = JSON.parse(opts.body || '{}');
    patches.push(patch);
    settings = { ...settings, ...patch };
  }
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

// v3.10.0 — Danke sagen (lib/thanks.js): in Nutzen-Momenten, höchstens einmal im Quartal je Gerät,
// abschaltbar, nie in der Demo, nie als Dialog
console.log('Danke-Hinweis');
const D = await import(`${JS}/lib/thanks.js`);
const allowed = (o) => D.thanksAllowed({ settings: { support_hint: true }, demo: false, today: '2026-11-02', ...o });
t('noch nie gezeigt: darf', allowed({ last: null }));
t('vor 91 Tagen: noch nicht', !allowed({ last: '2026-08-03' }));
t(`vor ${D.THANKS_GAP_DAYS} Tagen: wieder (nie zweimal im selben Quartal)`, allowed({ last: '2026-08-02' }) && D.THANKS_GAP_DAYS >= 92);
t('ausgeschaltet: nie', !allowed({ last: null, settings: { support_hint: false } }));
t('in der Demo: nie', !allowed({ last: null, demo: true }));
t('Tag in der Zukunft (Uhr verstellt): darf', allowed({ last: '2027-01-01' }));
t('Frist: vor dem Fälligkeitstag erledigt zählt, am Tag selbst und danach nicht',
  D.doneInTime('2026-11-10', '2026-11-09') && !D.doneInTime('2026-11-10', '2026-11-10') && !D.doneInTime('2026-11-10', '2026-11-12')
  && !D.doneInTime(null, '2026-11-09'));

document.body.innerHTML = '<div id="toast-stack"></div><section id="spot"><p>Ergebnis</p></section>';
localStorage.removeItem(D.THANKS_KEY);
settings = { support_hint: true }; reset();
const spot = document.getElementById('spot');
const first = await D.offerThanks(spot, 'bill_check');
const link = first?.querySelector('a[data-support-link]');
t('erscheint an Ort und Stelle, mit dem Text der Karte „Unterstützen“', !!first && spot.contains(first)
  && first.textContent.includes('Wenn er dir nützt'), first?.textContent.trim().slice(0, 60));
t('Link zu Buy Me a Coffee im neuen Tab, ohne Opener', link?.getAttribute('href') === 'https://buymeacoffee.com/bingerminger'
  && link?.getAttribute('target') === '_blank' && /\bnoopener\b/.test(link?.getAttribute('rel') || ''));
t('kein Dialog, nichts blockiert', !document.querySelector('[role="dialog"], [aria-modal], .modal') && first?.getAttribute('role') === 'status'
  && first?.classList.contains('no-print'));
t('merkt sich den Tag', /^\d{4}-\d{2}-\d{2}$/.test(localStorage.getItem(D.THANKS_KEY) || ''));
t('im selben Quartal kein zweites Mal', (await D.offerThanks(spot, 'report')) === null && spot.querySelectorAll('.thanks-note').length === 1);
first.querySelector('[data-act="close"]').click();
t('Schließen nimmt ihn weg', !spot.querySelector('.thanks-note'));
localStorage.setItem(D.THANKS_KEY, '2020-01-01'); reset();
const after = await D.offerThanks(spot.querySelector('p'), 'deadline', { where: 'after' });
t('ein Quartal später wieder, hinter dem Element', !!after && after.previousElementSibling === spot.querySelector('p'));
after.querySelector('[data-thanks-off]').click();
await new Promise(r => setTimeout(r, 30));
t('„Nicht mehr zeigen“ schaltet die Einstellung ab', patches.some(p => p.support_hint === false) && !spot.querySelector('.thanks-note'));
localStorage.removeItem(D.THANKS_KEY); reset();
t('danach erscheint er nicht mehr', (await D.offerThanks(spot, 'bill_check')) === null);
t('unbekannter Moment: nichts', (await D.offerThanks(spot, 'irgendwas')) === null);
// Skeptiker N11 — „Rechnung prüfen“: nur eine Abweichung, die nicht aus eigenen Daten stammen kann; mit Abstand
t('Rechnung prüfen: Abweichung ist ein Moment, „passt“ nicht', D.compareThanks({ verdict: 'check', reasons: ['price_change_inside'] })
  && !D.compareThanks({ verdict: 'ok', reasons: [] }) && !D.compareThanks({ verdict: 'no_prices', reasons: [] }) && !D.compareThanks(null));
t('Rechnung prüfen: nicht bei eigenen Lücken oder fehlenden Preisen',
  ['gaps', 'price_missing'].every(r => !D.compareThanks({ verdict: 'check', reasons: [r] })));
t('Rechnung prüfen: ein geschätzter Stand am Rand (fast immer) hält den Moment nicht auf',
  D.compareThanks({ verdict: 'check', reasons: ['estimated_reading_at_boundary'] }));
t('mit Abstand: eigene Klasse', D.thanksHtml('bill_check', true).includes('thanks-note--apart') && !D.thanksHtml('report').includes('thanks-note--apart'));
// Lektion 31: Was die Doku zusagt, hängt an der Konstante
const flat = (rel) => readFileSync(resolve(ROOT, rel), 'utf8').replace(/\s+/g, ' ');
t(`Doku DE/EN nennt ${D.THANKS_GAP_DAYS} Tage`, flat('docs/referenz/ansichten.md').includes(`mindestens ${D.THANKS_GAP_DAYS} Tage`)
  && flat('docs/en/referenz/ansichten.md').includes(`at least ${D.THANKS_GAP_DAYS} days`));

console.log(`\n  release-notice: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
