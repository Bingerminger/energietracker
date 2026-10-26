// =====================================================================
// v3.9.0 (Paket oberflaeche) — Illustrationen, Leerzustände und Kartentitel
// ohne Server:
//   • lib/illustrations.js: ein Bild mit Aussage hat role="img" und einen
//     Namen, Zier ist aria-hidden; Farben nur über Klassen und CSS-Variablen
//     (hell und dunkel), keine festen Farben, keine Bilddateien;
//   • die gemeinsame Grundlage (--ill-* in tokens.css, .ill-* in
//     components.css), die auch die Schaltbilder des Messkonzepts nutzen;
//   • jeder Schritt der Ersteinrichtung und jeder Beispielhaushalt hat ein Bild;
//   • lib/empty.js und der Leerzustand der Termine;
//   • Kartentitel der Einstellungen: Symbol als Zier, nicht im Katalogtext.
//
// Aufruf: node tests/illustrations.test.mjs
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
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><main id="view"></main><div id="modal-root"></div><div id="toast-stack"></div></body></html>', { url: 'http://127.0.0.1/#/reminders' });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
dom.window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
global.fetch = async (url) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json/);
  if (m) return new Response(read(`public/locales/${m[1]}.json`), { status: 200 });
  // jede API-Anfrage: leer (Termine, Personen, Zähler, Einstellungen)
  const data = /\/api\/(settings|session)\b/.test(u) ? {} : [];
  return new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

const { initI18n, t: tr } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const I = await import(`${JS}/lib/illustrations.js`);
const W = await import(`${JS}/components/setup-wizard.js`);
const box = document.createElement('div');
const parse = (html) => { box.innerHTML = html; return box.firstElementChild; };

console.log('Rahmen und Bedeutung (lib/illustrations.js)');
const named = parse(I.svg(I.shapes.meter(4, 4, { label: '1.8.0' }), { viewBox: '0 0 40 40', title: 'Zähler <Bezug>' }));
t('Bild mit Aussage: role="img", Name und <title> (Text maskiert)', named?.getAttribute('role') === 'img'
  && named.getAttribute('aria-label') === 'Zähler <Bezug>' && named.querySelector('title')?.textContent === 'Zähler <Bezug>'
  && !named.hasAttribute('aria-hidden'), named?.outerHTML.slice(0, 160));
const deco = parse(I.svg(I.shapes.house(0, 0)));
t('Zier: aria-hidden, nicht fokussierbar, ohne Rolle', deco?.getAttribute('aria-hidden') === 'true' && deco.getAttribute('focusable') === 'false'
  && !deco.hasAttribute('role') && !deco.querySelector('title'));
t('Zählwerk-Text im Zähler (für Schaltbilder)', named?.querySelector('text.ill-text')?.textContent === '1.8.0');
t('Verbrauchsart färbt über die Klasse (Utilities-SSOT), nicht über eine Farbe im Bild',
  /class="ill-c-strom"/.test(I.shapes.meter(0, 0, { util: 'strom' })) && /class="ill-c-wasser"/.test(I.shapes.drop(0, 0)));

const all = [...I.SETUP_ART_STEPS.map(s => I.setupArt(s)), ...I.PERSONA_ART_IDS.map(p => I.personaArt(p)), I.welcomeArt(),
  ...Object.entries(I.shapes).map(([k, f]) => k === 'wire' ? f([[0, 0], [10, 10]]) : f(10, 10))].join('');
t('keine festen Farben im Bild (nur Klassen und CSS-Variablen)', !/(fill|stroke|color)\s*[=:]\s*["']?(#|rgb|hsl)/i.test(all) && !/style="/.test(all));
t('keine Bilddateien und keine Adressen von außen', !/<image|href=|url\(/i.test(all));
t('jede Form zeichnet über eine .ill-Klasse', [...all.matchAll(/<(path|rect|circle|ellipse|polyline|text)\b([^>]*)>/g)].every(m => /class="ill-/.test(m[2])));

console.log('Grundlage für alle Zeichnungen (tokens.css, components.css)');
const tokens = read('public/css/tokens.css').replace(/\/\*[\s\S]*?\*\//g, '');
const rootBlock = (tokens.match(/:root\s*\{([\s\S]*?)\n\}/) || [])[1] || '';
const vars = ['--ill-line', '--ill-ink', '--ill-accent', '--ill-fill', '--ill-surface', '--ill-ground', '--ill-stroke', '--ill-stroke-strong'];
const missing = vars.filter(v => !new RegExp(`${v}\\s*:`).test(rootBlock));
t('Variablen --ill-* in :root (dunkel) definiert', missing.length === 0, missing.join(', '));
t('Farben aus den Themen-Variablen abgeleitet (hell und dunkel ohne eigene Werte)',
  ['--ill-line', '--ill-ink', '--ill-accent', '--ill-fill', '--ill-surface', '--ill-ground'].every(v => new RegExp(`${v}\\s*:[^;]*var\\(--`).test(rootBlock)));
const comp = read('public/css/components.css').replace(/\/\*[\s\S]*?\*\//g, '');
const illRules = comp.match(/\.ill[\w-]*[^{}]*\{[^}]*\}/g) || [];
t('.ill-Regeln ohne feste Farben', illRules.length >= 10 && illRules.every(r => !/#[0-9a-f]{3,8}\b|rgb\(|hsl\(/i.test(r)), `${illRules.length} Regeln`);
t('Strichstärke unabhängig von der Größe', /\.ill \* \{ vector-effect: non-scaling-stroke; \}/.test(comp));
t('Klassen der Formen haben eine Regel', ['ill-line', 'ill-ink', 'ill-accent', 'ill-fill', 'ill-face', 'ill-ground', 'ill-dot', 'ill-text', 'ill-util', 'ill-util-fill']
  .every(c => new RegExp(`\\.ill \\.${c}\\s*\\{`).test(comp)));

console.log('Ersteinrichtung');
t('jeder Schritt hat ein Bild', W.setupSteps(false).every(s => I.SETUP_ART_STEPS.includes(s)), W.setupSteps(false).join());
t('jeder Beispielhaushalt hat ein Bild', W.PERSONAS.every(p => I.PERSONA_ART_IDS.includes(p.id)));

console.log('Leerzustand (lib/empty.js)');
const E = await import(`${JS}/lib/empty.js`);
const empty = parse(E.emptyHtml({ icon: '📌', title: 'Noch nichts', text: 'Ein Satz <b>', action: '<button class="btn">Los</button>', role: 'x' }));
t('Symbol als Zier, Titel, ein Satz, eine Aktion', empty?.querySelector('.empty-icon')?.getAttribute('aria-hidden') === 'true'
  && empty.querySelector('h2')?.textContent === 'Noch nichts' && empty.querySelector('p')?.textContent === 'Ein Satz <b>'
  && empty.querySelectorAll('button').length === 1 && empty.dataset.role === 'x' && empty.classList.contains('card'));
const R = await import(`${JS}/views/reminders.js`);
const view = document.getElementById('view');
await R.render(view, [], {});
const re = view.querySelector('[data-role="reminders-empty"]');
t('Termine ohne Eintrag: Leerzustand mit Titel, Satz und genau einer Aktion zum Anlegen', !!re && re.classList.contains('empty')
  && re.querySelector('h2')?.textContent === tr('reminders.empty') && re.querySelector('p')?.textContent === tr('reminders.emptyText')
  && re.querySelectorAll('[data-rem-add]').length === 1 && !view.querySelector('.banner.banner--info'), view.textContent.replace(/\s+/g, ' ').slice(0, 200));

console.log('Kartentitel der Einstellungen');
const titleKeys = ['lang', 'activeUtils', 'pdf', 'export', 'backup', 'security', 'diag'];
const pict = /^\p{Extended_Pictographic}/u;
const bad = [];
for (const l of ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl']) {
  const cat = JSON.parse(read(`public/locales/${l}.json`));
  for (const k of titleKeys) if (pict.test(cat.settings?.[k]?.title || '')) bad.push(`${l}:${k}`);
}
t('Symbol nicht im Katalogtext (der Bildschirmleser liest sonst „Diskette Backup …“)', bad.length === 0, bad.join(', '));
const settingsSrc = read('public/js/views/settings.js');
const noIcon = titleKeys.filter(k => !new RegExp(`<h2 class="card__title"><span aria-hidden="true">[^<]+</span> \\$\\{t\\('settings\\.${k}\\.title'\\)\\}`).test(settingsSrc));
t('… sondern als Zier vor dem Titel', noIcon.length === 0, noIcon.join(', '));

console.log(`\n  illustrations: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
