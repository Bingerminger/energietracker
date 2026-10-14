// =====================================================================
// v3.5.0 (N1023) — iPad: Seitenleiste erst ab 900 px, eine Schwelle für CSS
// und Skript.
//
//   • nav-model.js: NAV_SIDEBAR_MIN_PX / NAV_SIDEBAR_QUERY / NAV_TABBAR_QUERY
//     und navMode(width) — unter 900 px Tab-Leiste, ab 900 px Seitenleiste,
//   • jede @media-Abfrage im CSS, die Navigation (Seitenleiste, Tab-Leiste,
//     Menü, Platz für die Tab-Leiste) betrifft, ist wörtlich eine dieser
//     beiden — und ausgewertet für die iPad-Breiten gilt dieselbe Navigation
//     wie navMode(),
//   • mobile-nav.js fragt matchMedia mit derselben Abfrage und räumt beim
//     Drehen auf: offenes Menü zu (kein doppelter Zustand), Fokus bleibt
//     sichtbar,
//   • die Doku nennt die Schwelle.
//
// Aufruf: node tests/nav-threshold.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync, readdirSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const JS = resolve(ROOT, 'public', 'js');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM(`<!DOCTYPE html><html><body>
  <div id="app"><aside id="sidebar"><nav id="primary-nav"><a href="#/dashboard" id="nav-a">Übersicht</a><a href="#/contracts" id="nav-b">Kosten</a></nav></aside>
  <main id="view" tabindex="-1"><button id="in-view">x</button></main></div>
  <nav id="tabbar"></nav><div id="nav-backdrop" class="nav-backdrop"></div><div id="modal-root"></div></body></html>`,
{ url: 'http://127.0.0.1/#/dashboard', pretendToBeVisual: true });
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
// matchMedia aufzeichnen: welche Abfrage, welcher Zuhörer
const queries = [];
dom.window.matchMedia = (q) => {
  const mq = { media: q, matches: false, listeners: [], addEventListener(type, fn) { if (type === 'change') this.listeners.push(fn); }, removeEventListener() {} };
  queries.push(mq);
  return mq;
};

const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const N = await import(`${JS}/lib/nav-model.js`);

// ── Konstante ───────────────────────────────────────────────────────────
console.log('Schwelle (nav-model.js)');
t('900 px', N.NAV_SIDEBAR_MIN_PX === 900);
t('Abfragen ohne Lücke: min-width 900px / max-width 899.98px', N.NAV_SIDEBAR_QUERY === '(min-width: 900px)' && N.NAV_TABBAR_QUERY === '(max-width: 899.98px)',
  `${N.NAV_SIDEBAR_QUERY} · ${N.NAV_TABBAR_QUERY}`);
const TAB = [744, 768, 810, 820, 834, 899, 899.5];      // iPad hochkant, Split View
const SIDE = [900, 1024, 1080, 1133, 1180, 1194, 1366]; // iPad quer, Mac
t('iPad hochkant (744–834 px) und knapp darunter: Tab-Leiste', TAB.every(w => N.navMode(w) === 'tabbar'));
t('ab 900 px (iPad quer 1024–1366 px): Seitenleiste', SIDE.every(w => N.navMode(w) === 'sidebar'));

// ── CSS ────────────────────────────────────────────────────────────────
console.log('CSS (app.css, components.css, readings-entry.css …)');
/** @media-Blöcke mit Abfrage und Inhalt (geschweifte Klammern gezählt). */
function mediaBlocks(src) {
  const css = src.replace(/\/\*[\s\S]*?\*\//g, '');
  const out = [];
  const re = /@media\s+([^{]+)\{/g;
  let m;
  while ((m = re.exec(css))) {
    let depth = 1, i = re.lastIndex;
    for (; i < css.length && depth; i++) { if (css[i] === '{') depth++; else if (css[i] === '}') depth--; }
    out.push({ query: m[1].trim().replace(/\s+/g, ' '), body: css.slice(re.lastIndex, i - 1) });
  }
  return out;
}
const files = readdirSync(resolve(ROOT, 'public/css')).filter(f => f.endsWith('.css'));
const blocks = files.flatMap(f => mediaBlocks(read('public/css/' + f)).map(b => ({ ...b, file: f })))
  .filter(b => /(min|max)-width/.test(b.query));   // Breitenabfragen (nicht print, Bewegung, Farbschema)
// Was es nur mit Tab-Leiste gibt (Leiste, Menü, Platz über der Leiste, Touch-Größen am Telefon-Layout) …
const TABBAR_MARK = /\.tabbar|--tabbar-h|nav-open|nav-backdrop|topbar__capture|--control-h: 44px|\.btn \{ min-height: 44px/;
// … und was nur neben der festen Seitenleiste gilt
const SIDEBAR_MARK = /--sidebar-w/;
const maxOf = (q) => Math.max(...[...q.matchAll(/max-width:\s*([\d.]+)px/g)].map(m => Number(m[1])), -Infinity);
// Schwellen-Blöcke: Tab-Leisten-Regeln bis zur Schwelle (Telefon-Feinheiten unter 640 px ausgenommen), Seitenleisten-Regeln ab ihr
const thresholdBlocks = blocks.filter(b => (TABBAR_MARK.test(b.body) && maxOf(b.query) >= 640) || SIDEBAR_MARK.test(b.body));
const wrong = thresholdBlocks.filter(b => !b.query.split(',').map(s => s.trim()).some(s => s === N.NAV_TABBAR_QUERY || s === N.NAV_SIDEBAR_QUERY));
t('jede Schwellen-Abfrage ist wörtlich NAV_TABBAR_QUERY oder NAV_SIDEBAR_QUERY', thresholdBlocks.length >= 8 && wrong.length === 0,
  `${thresholdBlocks.length} Blöcke${wrong.length ? ' · falsch: ' + wrong.map(b => `${b.file} ${b.query}`).join(', ') : ''}`);
const old = blocks.filter(b => /\b(800|801)px/.test(b.query));
t('keine Abfrage mehr auf der alten Schwelle 800/801 px', old.length === 0, old.map(b => `${b.file} ${b.query}`).join(', '));
const tabBlock = blocks.find(b => b.query === N.NAV_TABBAR_QUERY && /\.tabbar \{[^}]*display: grid/.test(b.body.replace(/\s+/g, ' ')));
t('Tab-Leiste erscheint genau unter der Schwelle (app.css)', tabBlock?.file === 'app.css');
t('Touch-Größen bis zur Schwelle: (pointer: coarse), NAV_TABBAR_QUERY', blocks.some(b => b.query === `(pointer: coarse), ${N.NAV_TABBAR_QUERY}` && /--control-h: 44px/.test(b.body)));

// CSS-Abfragen auswerten wie der Browser (nur der Breiten-Teil), gegen navMode()
const evalQuery = (q, w) => q.split(',').some(part => {
  const conds = [...part.matchAll(/\((min|max)-width:\s*([\d.]+)px\)/g)];
  if (!conds.length) return false;
  return conds.every(([, kind, v]) => (kind === 'min' ? w >= Number(v) : w <= Number(v)));
});
const mismatch = [];
for (const w of [...TAB, ...SIDE]) {
  const js = N.navMode(w);
  const tabbarCss = !!tabBlock && evalQuery(tabBlock.query, w);
  if ((js === 'tabbar') !== tabbarCss || evalQuery(N.NAV_SIDEBAR_QUERY, w) !== (js === 'sidebar')) mismatch.push(w);
}
t('CSS (Tab-Leiste) und Skript (matchMedia) sind sich je Breite einig (744 … 1366 px)', mismatch.length === 0, mismatch.join(', '));
// Keine Regel, die die Tab-Leiste voraussetzt, gilt dort, wo die Seitenleiste steht — und umgekehrt
const stray = [];
for (let w = 320; w <= 1600; w += 0.5) {
  for (const b of blocks) {
    if (!evalQuery(b.query, w)) continue;
    if (TABBAR_MARK.test(b.body) && N.navMode(w) !== 'tabbar') stray.push(`${b.file} ${b.query} @${w}`);
    if (SIDEBAR_MARK.test(b.body) && N.navMode(w) !== 'sidebar') stray.push(`${b.file} ${b.query} @${w}`);
  }
}
t('Regeln für Tab-Leiste bzw. Seitenleiste nur in ihrem Layout (320–1600 px)', stray.length === 0, [...new Set(stray)].slice(0, 4).join(', '));

// ── Skript: matchMedia und Drehen ──────────────────────────────────────
console.log('Skript (mobile-nav.js)');
const M = await import(`${JS}/lib/mobile-nav.js`);
const navQuery = queries.find(q => q.media === N.NAV_SIDEBAR_QUERY);
t('mobile-nav fragt matchMedia mit NAV_SIDEBAR_QUERY und hört auf Änderungen', !!navQuery && navQuery.listeners.length === 1);
const jsLiterals = readdirSync(resolve(JS, 'lib')).concat(readdirSync(resolve(JS, 'views')).map(f => '../views/' + f))
  .filter(f => f.endsWith('.js')).filter(f => /matchMedia\?*\.?\(\s*['"`]\((min|max)-width:\s*8\d\dpx/.test(readFileSync(resolve(JS, 'lib', f), 'utf8')));
t('kein Skript prüft eine eigene Navigationsbreite', jsLiterals.length === 0, jsLiterals.join(', '));

M.buildTabbar([{ key: 'gas', label: 'Gas' }]);
const more = document.querySelector('#tabbar [data-action="more"]');
const sidebar = document.getElementById('sidebar');
const rotate = (sidebarLayout) => navQuery?.listeners.forEach(fn => fn({ matches: sidebarLayout }));

more.focus();
more.click();
t('hochkant: „Mehr“ öffnet das Menü', M.isMenuOpen() && document.body.classList.contains('nav-open') && sidebar.getAttribute('role') === 'dialog');
document.getElementById('nav-b').focus();
rotate(true);
t('ins Querformat gedreht: Menü zu, kein Dialog-Zustand mehr', !M.isMenuOpen() && !document.body.classList.contains('nav-open')
  && !sidebar.hasAttribute('role') && !sidebar.hasAttribute('aria-modal') && more.getAttribute('aria-expanded') === 'false');
t('… Fokus bleibt in der (jetzt festen) Seitenleiste', document.activeElement?.id === 'nav-b', document.activeElement?.id);
rotate(false);
t('zurück hochkant: Fokus aus der verborgenen Schublade auf „Mehr“', document.activeElement === more);
t('… Menü bleibt zu (kein doppelter Zustand)', !M.isMenuOpen() && !document.body.classList.contains('nav-open'));
document.getElementById('in-view').focus();
rotate(true); rotate(false);
t('Fokus im Inhalt bleibt beim Drehen, wo er ist', document.activeElement?.id === 'in-view');

// ── Doku ──────────────────────────────────────────────────────────────
console.log('Doku');
for (const [lang, file] of [['de', 'docs/referenz/ansichten.md'], ['en', 'docs/en/referenz/ansichten.md']]) {
  const d = read(file);
  t(`${lang}: Ansichten nennen die Schwelle 900 px und das iPad`, /900\s?px/.test(d) && /iPad/.test(d));
}

console.log(`\nnav-threshold: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
