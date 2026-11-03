// =====================================================================
// v2.11.0 — Router und Dialoge (Review FE-05, FE-09, FE-25)
//
// Ohne Server: JSDOM, erfundene Ansichten und ein gestelltes fetch.
// Geprüft wird, was bis v2.10 schiefging — eine langsame Ansicht, die nach
// dem Weiterklicken die neue überschreibt, ein verlorener Cleanup, die
// Zurück-Taste, die einen Dialog verwaist offen ließ — und was neu ist:
// Query in der Adresse, Fehler mit „Erneut versuchen", Seitentitel.
//
// Aufruf: node tests/router.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..', 'public', 'js');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const dom = new JSDOM(`<!DOCTYPE html><html><head><title>x</title></head><body data-app-version="0">
  <div id="app"><header class="topbar"></header><main id="view"></main></div>
  <div id="toast-stack"></div><div id="modal-root"></div></body></html>`,
  { url: 'http://127.0.0.1/#/start', pretendToBeVisual: true });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event',
                 'getComputedStyle', 'sessionStorage', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
dom.window.scrollTo = () => {};

// Gestelltes fetch: jede Antwort braucht 150 ms und respektiert das Abbruchsignal
const fetchLog = [];
global.fetch = (url, opts = {}) => new Promise((res, rej) => {
  const entry = { url: String(url), signal: opts.signal, aborted: false };
  fetchLog.push(entry);
  const timer = setTimeout(() => res(new Response(JSON.stringify({ success: true, data: { ok: 1 } }),
    { status: 200, headers: { 'content-type': 'application/json' } })), 150);
  opts.signal?.addEventListener('abort', () => {
    entry.aborted = true;
    clearTimeout(timer);
    rej(new dom.window.DOMException('aborted', 'AbortError'));
  });
});

const { startRouter, parseHash } = await import(`${ROOT}/router.js`);
const { api } = await import(`${ROOT}/api.js`);
const { openModal } = await import(`${ROOT}/components/modal.js`);

const calls = { slowCleanup: 0, fastCleanup: 0, failRenders: 0, afterFetch: 0 };
let lastCtx = null;
const views = {
  start: { section: 'overview', load: async () => ({ render: (c) => { c.innerHTML = '<h1>Start</h1>'; } }) },
  slow: { section: 'overview', load: async () => ({
    render: async (c) => {
      c.innerHTML = '<h1>Langsam</h1>';
      await sleep(120);
      c.innerHTML = '<h1>Langsam fertig</h1>';
      return () => { calls.slowCleanup++; };
    } }) },
  fast: { section: 'overview', load: async () => ({
    render: (c, _p, ctx) => { lastCtx = ctx; c.innerHTML = '<h1>🔥 Schnell</h1>'; return () => { calls.fastCleanup++; }; } }) },
  fetching: { section: 'overview', load: async () => ({
    render: async (c) => {
      c.innerHTML = '<h1>Lädt Daten</h1>';
      await api.settings();                 // GET — gehört zur Ansicht
      calls.afterFetch++;
      c.innerHTML = '<h1>Daten da</h1>';
    } }) },
  failing: { section: 'overview', load: async () => ({
    render: () => { calls.failRenders++; throw new Error('kaputt'); } }) },
  // v3.10.0 (GitHub #36) — eine Ansicht wie die Übersicht: leert sich zuerst („Lädt…“), sucht ihre Elemente über die ID
  place: { section: 'overview', load: async () => ({
    render: async (c, _p, ctx) => {
      const n = ++calls.placeRenders;
      calls.inPlace.push(!!ctx?.inPlace);
      c.innerHTML = '<div class="loading">Lädt Platz</div>';
      await sleep(100);
      c.innerHTML = `<h1>Platz ${n}</h1><p id="mark">${n}</p><button type="button" data-period-mode="billing">B</button><canvas></canvas>`;
      if (document.getElementById('mark') === c.querySelector('#mark')) calls.ownId++;
      // ein Schaubild wie Chart.js: Größe und Bild nur im Browser, hier gestellt; das Aufräumen leert es
      const cv = c.querySelector('canvas');
      cv.getBoundingClientRect = () => ({ width: 120, height: 40, top: 0, left: 0, right: 120, bottom: 40 });
      cv.toDataURL = () => `data:image/png;base64,UGxhdHo${n}`;
      return () => { calls.cleanedVisible += document.contains(cv) ? 1 : 0; };
    } }) },
};
calls.placeRenders = 0;
calls.ownId = 0;
calls.inPlace = [];
calls.cleanedVisible = 0;
const routes = [
  [/^\/start$/, 'start'], [/^\/slow$/, 'slow'], [/^\/fast$/, 'fast'],
  [/^\/fetching$/, 'fetching'], [/^\/failing$/, 'failing'], [/^\/place$/, 'place'],
];
const view = document.getElementById('view');
console.error = () => {};   // der Router protokolliert den absichtlichen Fehler
startRouter(view, { routes, views });
await sleep(20);

// 1. Eine langsame Ansicht überschreibt die schnellere nicht
location.hash = '#/slow';
await sleep(30);
location.hash = '#/fast';
await sleep(250);
t('veraltete Ansicht überschreibt die neue nicht', view.textContent.includes('Schnell') && !view.textContent.includes('Langsam'),
  view.textContent.trim());
t('Cleanup der verlassenen Ansicht läuft, sobald sie fertig ist', calls.slowCleanup === 1, `${calls.slowCleanup}×`);
t('Seitentitel aus der Überschrift, ohne Emoji', document.title === 'Schnell · Energietracker', document.title);

// 2. Cleanup der alten Ansicht vor dem Start der neuen
location.hash = '#/start';
await sleep(60);
t('Cleanup beim Verlassen', calls.fastCleanup === 1, `${calls.fastCleanup}×`);

// 3. Query in der Adresse
location.hash = '#/fast?meter=m_gas_1&add=reading';
await sleep(60);
t('Query steht der Ansicht zur Verfügung', lastCtx?.query?.get('meter') === 'm_gas_1' && lastCtx?.query?.get('add') === 'reading');
t('parseHash trennt Pfad und Query', parseHash('#/a/b?x=1').path === '/a/b' && parseHash('#/a/b?x=1').query.get('x') === '1');

// 4. Leseanfrage einer verlassenen Ansicht: abgebrochen, Promise bleibt offen
location.hash = '#/fetching';
await sleep(40);
location.hash = '#/start';
await sleep(250);
const settingsCall = fetchLog.filter(f => f.url.includes('/api/settings')).pop();
t('Leseanfrage der verlassenen Ansicht wird abgebrochen', !!settingsCall?.aborted);
t('verlassene Ansicht läuft nicht weiter', calls.afterFetch === 0, `${calls.afterFetch}×`);
t('keine Fehlermeldung aus der verlassenen Ansicht', !document.querySelector('#toast-stack .toast'));
t('neue Ansicht steht', view.textContent.includes('Start'));

// 4b. Dieselbe Ansicht ohne Wegklicken bekommt ihre Daten
location.hash = '#/fetching';
await sleep(300);
t('Ansicht ohne Wegklicken bekommt ihre Daten', view.textContent.includes('Daten da') && calls.afterFetch === 1);

// 5. Fehler mit „Erneut versuchen"
location.hash = '#/failing';
await sleep(60);
const retry = view.querySelector('[data-act="retry"]');
t('Fehler zeigt „Erneut versuchen"', !!retry && !!view.querySelector('.banner--error'));
retry?.click();
await sleep(60);
t('„Erneut versuchen" lädt die Ansicht neu', calls.failRenders === 2, `${calls.failRenders} Versuche`);

// 6. Unbekannte Adresse
location.hash = '#/gibtsnicht';
await sleep(40);
t('unbekannte Adresse meldet sich', !!view.querySelector('.banner--warning'));

// 7. Zurück-Taste schließt einen offenen Dialog, ohne die Seite zu verlassen
location.hash = '#/start';
await sleep(60);
const before = location.hash;
const ctrl = openModal({ title: 'Test', body: '<p>Inhalt</p>' });
t('Dialog ist offen', !!document.querySelector('#modal-root .modal'));
history.back();
await sleep(80);
t('Zurück schließt den Dialog', !document.querySelector('#modal-root .modal'));
t('… und bleibt auf der Seite', location.hash === before && view.textContent.includes('Start'), location.hash);
let resolved = false;
ctrl.closedPromise.then(() => { resolved = true; });
await sleep(10);
t('Dialog meldet sein Schließen', resolved);

// 8. Schließen per Knopf nimmt den eigenen History-Eintrag zurück: Nach
// der nächsten Navigation ist genau ein Eintrag dazugekommen, nicht zwei.
// Frische Navigation vorweg: Prüfpunkt 7 hinterließ einen Vorwärts-Eintrag.
location.hash = '#/start?frisch=1';
await sleep(60);
const lenBefore = history.length;
const ctrl2 = openModal({ title: 'Test 2', body: '<p>x</p>' });
ctrl2.close(null);
await sleep(80);
location.hash = '#/fast';
await sleep(60);
t('Schließen per Knopf hinterlässt keinen History-Eintrag', history.length === lenBefore + 1, `${lenBefore} → ${history.length}`);
history.back();
await sleep(80);
t('… und Zurück führt zur vorigen Seite', view.textContent.includes('Start'), view.textContent.trim());

// 9. v3.10.0 (GitHub #36) — Kalender-/Abrechnungsjahr und Stufe umschalten zeichnet am Ort neu:
// kein „Lädt…“, der alte Inhalt bleibt stehen, bis der neue da ist, die Seite springt nicht nach oben
location.hash = '#/place';
await sleep(350);
const scrolls = [];
dom.window.scrollTo = (_x, y) => { scrolls.push(y); };
Object.defineProperty(dom.window, 'scrollY', { value: 640, configurable: true, writable: true });
const shownHosts = () => [...view.children].filter(h => !h.classList.contains('view-host--pending'));
view.querySelector('[data-period-mode="billing"]')?.focus();
for (const [ev, before, after] of [['et:periodmode', 1, 2], ['et:levelchange', 2, 3]]) {
  scrolls.length = 0;
  window.dispatchEvent(new CustomEvent(ev));
  await sleep(60);
  const shown = shownHosts();
  t(`${ev}: alter Inhalt bleibt stehen, kein „Lädt…“`, shown.length === 1 && shown[0].textContent.includes(`Platz ${before}`)
    && !shown[0].querySelector('.loading'), shown.map(h => h.textContent.trim()).join(' | '));
  t(`${ev}: der neue entsteht verborgen`, !!view.querySelector('.view-host--pending[aria-hidden="true"]'));
  t(`${ev}: Schaubild des alten Inhalts als Bild festgehalten`, !shown[0]?.querySelector('canvas')
    && shown[0]?.querySelector('img')?.getAttribute('src') === `data:image/png;base64,UGxhdHo${before}`);
  await sleep(300);
  t(`${ev}: danach nur der neue Inhalt`, view.children.length === 1 && view.textContent.includes(`Platz ${after}`)
    && !view.querySelector('.view-host--pending, [aria-hidden]'), view.textContent.trim());
  t(`${ev}: die Seite bleibt, wo sie war`, scrolls.length > 0 && scrolls.every(y => y === 640), scrolls.join(','));
}
t('Umschalten: die Ansicht findet ihre Elemente über die ID im neuen Inhalt', calls.ownId === 3, `${calls.ownId}×`);
t('Umschalten: die Ansicht weiß, dass sie am Ort neu zeichnet (Navigation nicht)', calls.inPlace.join() === 'false,true,true', calls.inPlace.join());
t('Umschalten: das Aufräumen leert kein sichtbares Schaubild', calls.cleanedVisible === 0, `${calls.cleanedVisible}×`);
t('Umschalten: der Fokus bleibt am Umschalter', document.activeElement?.dataset?.periodMode === 'billing' && view.contains(document.activeElement));
// eine Navigation während des Neuzeichnens gewinnt
window.dispatchEvent(new CustomEvent('et:periodmode'));
await sleep(30);
location.hash = '#/start';
await sleep(400);
t('Navigation während des Neuzeichnens: die neue Seite steht allein', view.children.length === 1 && view.textContent.includes('Start')
  && !view.textContent.includes('Platz'), view.textContent.trim());

console.log(`\n  router.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
