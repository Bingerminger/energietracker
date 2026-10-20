// =====================================================================
// v3.5.0 — Jahresbericht-Dialog (Nachzug F1025), ohne Server.
//
//   • Auswahl → genau die vorhandenen Parameter (year, detail, utilities,
//     lang, inline) für Druckansicht, „Im Browser öffnen“ und Download,
//   • nur aktive Verbrauchsarten; alle angehakt = ganzer Bericht (kein
//     `utilities`), keine angehakt = Hinweis statt Link,
//   • Sprache nur fürs PDF, die Druckansicht nennt die Sprache des Geräts,
//   • die Auswahl merkt sich das Gerät (ohne Jahr), auch wenn der Speicher
//     gesperrt ist, öffnet der Dialog,
//   • Demo: nur der ganze Bericht (fertige Dateien), Anhang nur in der
//     Druckansicht,
//   • Fokus und Tastatur wie die übrigen Dialoge, Seite und Druckansicht
//     benutzen den Dialog.
//
// Aufruf: node tests/report-dialog.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const JS = resolve(ROOT, 'public', 'js');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const tick = (ms = 20) => new Promise(r => setTimeout(r, ms));

const dom = new JSDOM(`<!DOCTYPE html><html><body><div id="app"><main id="view"></main></div>
  <div id="modal-root"></div><div id="toast-stack"></div></body></html>`, { url: 'http://127.0.0.1/#/report', pretendToBeVisual: true, virtualConsole: new VirtualConsole() });   // „Not implemented: navigation“ (Download-Link) stumm
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'KeyboardEvent', 'MouseEvent', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
dom.window.matchMedia = () => ({ matches: true, addEventListener() {}, removeEventListener() {} });

const UTILS = [{ key: 'gas', label: 'Gas', icon: '🔥' }, { key: 'strom', label: 'Strom', icon: '⚡' },
  { key: 'wasser', label: 'Wasser', icon: '💧' }, { key: 'heizoel', label: 'Heizöl', icon: '🛢️' }];
const requested = [];
const json = (data) => new Response(JSON.stringify({ success: true, data }), { status: 200 });
global.fetch = dom.window.fetch = async (url) => {
  const u = String(url);
  const loc = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (loc) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${loc[1]}.json`), 'utf8'), { status: 200 });
  const path = u.replace(/^.*api\.php/, '');
  requested.push(path);
  if (path === '/api/settings') return json({ active_utilities: ['gas', 'strom', 'wasser'] });
  if (path === '/api/utilities') return json(UTILS);
  if (path.startsWith('/api/reports/yearly')) {
    return json({ model: { lang: 'de', year: 2025, utilities: [{ utility: 'gas', label: 'Gas' }], texts: {},
      headline: { tiles: [], lights: [], top_tasks: [] }, successes: [], tasks: [], infos: [], agenda: { events: [], timelines: [] },
      pages: [], topics: {}, glossary: [] } });
  }
  return json(null);
};

const { initI18n, getLanguages } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const R = await import(`${JS}/components/report-dialog.js`);
const now = new Date().getFullYear();
const years = R.reportYears();
const active = UTILS.slice(0, 3);
const ctx = { active, languages: Object.keys(getLanguages()), locale: 'de', years };

// ── Auswahl → Parameter ─────────────────────────────────────────────────
console.log('Auswahl → Parameter');
t('Jahre: dieses und sechs davor, vorgewählt das Vorjahr', years.length === 7 && years[0] === now && R.resolveChoice({}, ctx).year === now - 1);
t('alle angehakt = ganzer Bericht (kein utilities)', R.resolveChoice({ utilities: ['gas', 'strom', 'wasser'] }, ctx).utilities === null);
t('keine gemerkt = ganzer Bericht', R.resolveChoice({ utilities: [] }, ctx).utilities === null);
t('nur aktive, in der Reihenfolge der Verbrauchsarten', R.resolveChoice({ utilities: ['wasser', 'heizoel', 'gas'] }, ctx).utilities?.join() === 'gas,wasser');
t('unbekannte Sprache → Sprache der Oberfläche', R.resolveChoice({ lang: 'xx' }, ctx).lang === 'de' && R.resolveChoice({ lang: 'fr' }, ctx).lang === 'fr');
const c = { year: 2025, utilities: ['gas', 'wasser'], lang: 'en', detail: true };
t('Druckansicht: #/report/print?year=…&detail=1&utilities=…', R.reportHref('print', c) === '#/report/print?year=2025&detail=1&utilities=gas,wasser', R.reportHref('print', c));
const open = R.reportHref('open', c);
t('Im Browser öffnen: yearly.pdf mit inline, detail, utilities, lang', /\/api\/reports\/yearly\.pdf\?year=2025&inline=1&detail=1&utilities=gas,wasser&lang=en$/.test(open), open);
const down = R.reportHref('download', { ...c, utilities: null, detail: false });
t('Herunterladen: ohne inline; ganzer Bericht ohne utilities', /yearly\.pdf\?year=2025&lang=en$/.test(down), down);
t('Demo: PDF nur ganz und ohne Anhang, Druckansicht mit Anhang', JSON.stringify(R.demoLimits('download', true)) === '{"subset":false,"detail":false,"billing":false,"flex":false}'
  && JSON.stringify(R.demoLimits('print', true)) === '{"subset":false,"detail":true,"billing":true,"flex":true}' && JSON.stringify(R.demoLimits('open', false)) === '{"subset":true,"detail":true,"billing":true,"flex":true}');
// v3.6.0 — Seite „Flexibilität“ und ein gespeichertes Szenario (nur Druckansicht)
t('Flexibilität: flex=1 in Druckansicht und PDF', R.reportHref('print', { year: 2025, flex: true }) === '#/report/print?year=2025&flex=1'
  && /yearly\.pdf\?year=2025&flex=1$/.test(R.reportHref('download', { year: 2025, flex: true })) && R.resolveChoice({ flex: true }, ctx).flex === true);
t('Szenario anhängen: nur in der Druckansicht, Name kodiert', R.reportHref('print', { year: 2025, scenario: 'Dach & Fenster' }) === '#/report/print?year=2025&scenario=Dach%20%26%20Fenster'
  && !/scenario/.test(R.reportHref('download', { year: 2025, scenario: 'Dach' })));
// v3.6.0 (F1030) — Anhang „Abrechnungsjahre“ als eigene Option
t('Abrechnungsjahre: billing=1 in Druckansicht und PDF', R.reportHref('print', { year: 2025, billing: true }) === '#/report/print?year=2025&billing=1'
  && /yearly\.pdf\?year=2025&billing=1$/.test(R.reportHref('download', { year: 2025, billing: true })) && R.resolveChoice({ billing: true }, ctx).billing === true);

// ── Dialog ──────────────────────────────────────────────────────────────
console.log('Dialog');
const q = (sel) => document.querySelector('#modal-root .modal')?.querySelector(sel);
const change = (el) => el.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
localStorage.removeItem(R.REPORT_OPTIONS_KEY);
let result = R.openReportDialog({ mode: 'download' });
await tick();
t('Titel, Jahr, Sprache, Verbrauchsarten, Anhang', q('.modal__title')?.textContent === 'Jahresbericht erstellen' && q('#rd-year')?.options.length === 7
  && !!q('#rd-lang') && q('#rd-detail') && q('legend')?.textContent === 'Verbrauchsarten');
t('Formularraster .fgrid, Verbrauchsarten als Feldgruppe', !!q('.fgrid') && !!q('fieldset.fgroup'));
t('Option „Abrechnungsjahre“ sagt, dass die übrigen Seiten Kalenderjahr bleiben', !!q('#rd-billing') && q('#rd-billing').checked === false
  && /übrigen Seiten bleiben beim Kalenderjahr/.test(q('#rd-billing-hint')?.textContent || ''));
t('nur aktive Verbrauchsarten, alle angehakt', [...document.querySelectorAll('input[name="rd-util"]')].map(i => `${i.value}:${i.checked}`).join() === 'gas:true,strom:true,wasser:true');
t('Vorjahr vorgewählt, Sprache der Oberfläche', q('#rd-year')?.value === String(now - 1) && q('#rd-lang')?.value === 'de');
t('Fokus im Dialog (modal.js: Fokusfalle, Escape)', document.querySelector('#modal-root .modal')?.contains(document.activeElement));
const go = q('[data-act="go"]');
t('Aktion ist ein echter Download-Link', go?.tagName === 'A' && go.hasAttribute('download') && /yearly\.pdf\?year=\d{4}&lang=de$/.test(go.getAttribute('href')), go?.getAttribute('href'));
q('input[name="rd-util"][value="strom"]').checked = false; change(q('input[name="rd-util"][value="strom"]'));
q('#rd-lang').value = 'fr'; change(q('#rd-lang'));
q('#rd-detail').checked = true; change(q('#rd-detail'));
q('#rd-year').value = String(now - 2); change(q('#rd-year'));
t('Link folgt der Auswahl (Teilbericht, Sprache, Anhang, Jahr)', go.getAttribute('href').endsWith(`yearly.pdf?year=${now - 2}&detail=1&utilities=gas,wasser&lang=fr`), go.getAttribute('href'));
for (const b of document.querySelectorAll('input[name="rd-util"]')) { b.checked = false; change(b); }
const ev = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true });
go.dispatchEvent(ev);
t('keine Verbrauchsart: kein Download, Hinweis, Fokus aufs erste Kästchen', ev.defaultPrevented && q('#rd-util-msg')?.hidden === false
  && /Mindestens eine/.test(q('#rd-util-msg')?.textContent || '') && document.activeElement === q('input[name="rd-util"]') && go.getAttribute('aria-disabled') === 'true');
q('input[name="rd-util"][value="gas"]').checked = true; change(q('input[name="rd-util"][value="gas"]'));
q('input[name="rd-util"][value="wasser"]').checked = true; change(q('input[name="rd-util"][value="wasser"]'));
go.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
await tick();
const saved = JSON.parse(localStorage.getItem(R.REPORT_OPTIONS_KEY) || '{}');
t('gemerkt je Gerät: Verbrauchsarten, Sprache, Anhang — ohne Jahr', JSON.stringify(saved) === '{"utilities":["gas","wasser"],"lang":"fr","detail":true,"billing":false,"flex":false}', JSON.stringify(saved));
t('Dialog nach dem Download zu', !document.querySelector('#modal-root .modal') && typeof (await result) === 'string');

result = R.openReportDialog({ mode: 'open' });
await tick();
t('erneut geöffnet: gemerkte Auswahl, Jahr wieder das Vorjahr', q('#rd-lang')?.value === 'fr' && q('#rd-detail')?.checked === true
  && q('input[name="rd-util"][value="strom"]')?.checked === false && q('#rd-year')?.value === String(now - 1));
t('Im Browser öffnen: neuer Tab, inline', q('[data-act="go"]')?.getAttribute('target') === '_blank' && /inline=1/.test(q('[data-act="go"]')?.getAttribute('href')));
document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
t('Escape schließt ohne Aktion', (await result) === null && !document.querySelector('#modal-root .modal'));

result = R.openReportDialog({ mode: 'print' });
await tick();
t('Druckansicht: keine Sprachwahl, Hinweis auf die Sprache des Geräts', !q('#rd-lang') && /Sprache dieses Geräts \(Deutsch\)/.test(q('.report-dialog__lang')?.textContent || ''));
q('[data-act="go"]').dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true }));
await tick();
t('Druckansicht: Sprung mit den Parametern', location.hash === `#/report/print?year=${now - 1}&detail=1&utilities=gas,wasser`, location.hash);
await result;

const orig = dom.window.Storage.prototype.getItem;
dom.window.Storage.prototype.getItem = () => { throw new Error('gesperrt'); };
result = R.openReportDialog({ mode: 'download' });
await tick();
t('Speicher gesperrt: Dialog öffnet mit der Voreinstellung', q('#rd-lang')?.value === 'de' && document.querySelectorAll('input[name="rd-util"]:checked').length === 3);
q('[data-act="cancel"]').click();
await result;
dom.window.Storage.prototype.getItem = orig;

// ── Seite und Druckansicht ─────────────────────────────────────────────
console.log('Seite und Druckansicht');
const view = document.getElementById('view');
const page = await import(`${JS}/views/report.js`);
await page.render(view);
const dl = view.querySelector('#report-download');
t('Seite: Links tragen die gemerkte Auswahl', /utilities=gas,wasser/.test(dl?.getAttribute('href') || '') && /lang=fr/.test(dl.getAttribute('href'))
  && /#\/report\/print\?year=\d{4}&detail=1&utilities=gas,wasser/.test(view.querySelector('#report-print')?.getAttribute('href') || ''));
dl.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
await tick();
t('Seite: Klick öffnet zuerst den Dialog', /Jahresbericht erstellen/.test(q('.modal__title')?.textContent || '') && q('[data-act="go"]')?.hasAttribute('download'));
q('[data-act="cancel"]').click();
await tick();
const print = await import(`${JS}/views/report-print.js`);
requested.length = 0;
await print.render(view, [], { query: new URLSearchParams('year=2025&utilities=gas') });
t('Druckansicht: Teilbericht an den Server (utilities=gas)', requested.some(p => p === '/api/reports/yearly?year=2025&detail=1&utilities=gas'), requested.join(' '));
t('Druckansicht: als Teilbericht gekennzeichnet', /Teilbericht: Gas/.test(view.querySelector('.report-print__partial')?.textContent || ''));
t('Druckansicht: „Optionen …“ öffnet den Dialog, vorbelegt mit der Ansicht', (() => {
  view.querySelector('#print-options')?.click();
  return true;
})());
await tick();
t('… Teilbericht vorbelegt', q('input[name="rd-util"][value="gas"]')?.checked === true && q('input[name="rd-util"][value="strom"]')?.checked === false
  && q('#rd-year')?.value === '2025');

// ── Doku ──────────────────────────────────────────────────────────────
console.log('Doku');
for (const [lang, file] of [['de', 'docs/anleitungen/jahresbericht-lesen.md'], ['en', 'docs/en/anleitungen/jahresbericht-lesen.md']]) {
  const d = readFileSync(resolve(ROOT, file), 'utf8');
  const c = JSON.parse(readFileSync(resolve(ROOT, 'public', 'locales', `${lang}.json`), 'utf8')).report;
  t(`${lang}: Dialog, „Optionen“ und Hinweis ohne Haken wie im Katalog`, d.includes(c.dialog.title) && d.includes(c.dialog.options)
    && d.includes(c.dialog.utilitiesNone) && d.includes(c.dialog.language));
  t(`${lang}: alle drei Parameter genannt, die der Dialog setzt`, ['?lang=', '?utilities=', '?detail=1'].every(p => d.includes(p)));
  // v3.6.0 — Seite „Flexibilität“ und „Szenario anhängen“ stehen in der Anleitung wie im Katalog
  t(`${lang}: Seite „Flexibilität“ (flex=1) und „Szenario anhängen“ beschrieben`, d.includes(c.dialog.flex) && d.includes('`?flex=1`') && d.includes(c.dialog.scenario));
}

console.log(`\nreport-dialog: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
