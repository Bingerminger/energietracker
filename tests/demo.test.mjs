// v3.0.0 — Abdeckungstest der öffentlichen Demo (GitHub Pages).
//
// Baut die Demo (tools/build-demo.mjs, braucht PHP), liefert sie über einen
// statischen Server aus und startet die ECHTE App (app.js aus dem
// Bauergebnis) in JSDOM — so, wie ein Besucher sie lädt. Dann wird jede Seite
// aus dem Navigationsmodell aufgerufen. Grün nur, wenn
//   • keine Anfrage ohne gespeicherte Antwort blieb (demo-mode.js sammelt sie),
//   • keine Ausnahme und kein Konsolenfehler auftrat,
//   • Kennzeichen, Hinweisleiste und Sprachwahl da sind,
//   • Schreibzugriffe mit „nichts gespeichert“ abgelehnt werden.
//
//   node tests/demo.test.mjs            (baut nach einem Temp-Verzeichnis)
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { mkdtempSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, extname, join, resolve, normalize } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { buildDemo } from '../tools/build-demo.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json', '.css': 'text/css',
  '.csv': 'text/csv', '.pdf': 'application/pdf', '.png': 'image/png', '.woff2': 'font/woff2' };

function serve(dir, port) {
  const server = createServer((req, res) => {
    let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (p.endsWith('/')) p += 'index.html';
    const file = normalize(join(dir, p));
    if (!file.startsWith(dir)) { res.writeHead(403); res.end(); return; }
    try {
      if (!statSync(file).isFile()) throw new Error();
      res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' });
      res.end(readFileSync(file));
    } catch { res.writeHead(404); res.end('not found'); }
  });
  return new Promise(r => server.listen(port, '127.0.0.1', () => r(server)));
}

(async () => {
  const out = mkdtempSync(join(tmpdir(), 'et-demo-dist-'));
  let server;
  try {
    const built = await buildDemo({ out, port: 8895, log: () => {} });
    t('Demo gebaut', built.keys > 100 && built.files > 10, `${built.keys} Anfragen, ${built.responses} Antworten, ${built.files} Dateien`);
    const meta = JSON.parse(readFileSync(join(out, 'demo-api/meta.json'), 'utf8'));
    t('meta.json: Version und Bautag', meta.version === readFileSync(join(ROOT, 'VERSION'), 'utf8').trim()
      && /^\d{4}-\d{2}-\d{2}$/.test(meta.today), `${meta.version} · ${meta.today}`);
    const langs = Object.keys(JSON.parse(readFileSync(join(ROOT, 'public/locales/languages.json'), 'utf8')));
    const sameKeys = langs.every(l => {
      const idx = JSON.parse(readFileSync(join(out, `demo-api/index-${l}.json`), 'utf8'));
      return Object.keys(idx).length === meta.keys;   // v3.2.0 — built.keys zählt alle Haushalte
    });
    t('Index je Sprache vollständig', sameKeys, langs.join(' '));
    // v3.1.0 — Server-Texte in der Sprache der Demo (X-ET-Language), nicht in der Standardsprache
    const labelOf = (lang) => {
      const idx = JSON.parse(readFileSync(join(out, `demo-api/index-${lang}.json`), 'utf8'));
      const res = JSON.parse(readFileSync(join(out, 'demo-api/r', idx['/api/utilities']), 'utf8'));
      return res.data.find(u => u.key === 'strom')?.label;
    };
    t('Bezeichnungen je Sprache vom Server', labelOf('en') === 'Electricity' && labelOf('fr') === 'Électricité'
      && labelOf('de') === 'Strom', `${labelOf('de')} · ${labelOf('en')} · ${labelOf('fr')}`);
    // v3.1.0 (I18N-24) — die Demo-Daten selbst in der Sprache (Zählername)
    const meterOf = (lang) => {
      const idx = JSON.parse(readFileSync(join(out, `demo-api/index-${lang}.json`), 'utf8'));
      const res = JSON.parse(readFileSync(join(out, 'demo-api/r', idx['/api/utility/gas/meters']), 'utf8'));
      return res.data[0]?.name;
    };
    t('Demo-Daten je Sprache', meterOf('de') === 'Hauptzähler Gas' && meterOf('en') === 'Main gas meter'
      && meterOf('nl') === 'Hoofdgasmeter', `${meterOf('de')} · ${meterOf('en')} · ${meterOf('nl')}`);
    const html = readFileSync(join(out, 'index.html'), 'utf8');
    t('index.html: data-demo, kein Service Worker', html.includes(' data-demo') && !html.includes("serviceWorker.register('sw.js')"));

    server = await serve(out, 8894);
    const BASE = 'http://127.0.0.1:8894/';
    const dom = new JSDOM(html, { url: BASE + '?lang=de#/dashboard', pretendToBeVisual: true });
    const w = dom.window;
    for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event',
      'getComputedStyle', 'localStorage', 'sessionStorage', 'MouseEvent', 'KeyboardEvent', 'requestAnimationFrame']) {
      try { global[k] = w[k]; } catch { Object.defineProperty(global, k, { value: w[k], configurable: true, writable: true }); }
    }
    Object.defineProperty(global, 'navigator', { value: w.navigator, configurable: true, writable: true });
    let inflight = 0;
    const nativeFetch = global.fetch;
    const browserFetch = async (input, init) => {
      if (typeof input === 'string' && !/^https?:\/\//i.test(input)) input = new URL(input, w.location.href).href;
      inflight++;
      try { return await nativeFetch(input, init); } finally { inflight--; }
    };
    w.fetch = browserFetch;
    global.fetch = browserFetch;
    w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    w.scrollTo = () => {};
    const Chart = function (canvas, config) { return { canvas, config, destroy() {}, update() {}, resize() {} }; };
    Chart.defaults = { color: '', borderColor: '', font: { family: '', size: 12 },
      plugins: { legend: { labels: {} }, tooltip: {} }, scale: { grid: {}, ticks: {} } };
    Chart.register = () => {};
    w.Chart = Chart; global.Chart = Chart;
    w.confirm = () => true; global.confirm = w.confirm;

    const errors = [];
    const origError = console.error;
    console.error = (...a) => { errors.push(a.map(x => x?.message || String(x)).join(' ')); };
    w.addEventListener('error', e => errors.push('error: ' + (e.message || e.error?.message)));
    process.on('unhandledRejection', e => errors.push('unhandled: ' + (e?.message || e)));

    const settle = async () => {
      for (let i = 0; i < 100; i++) {
        await sleep(30);
        if (inflight === 0 && !w.document.querySelector('#view .loading')) { await sleep(60); if (inflight === 0) return; }
      }
    };

    // v3.2.0 (F1018) — der Rundgang zeigt das Schaufenster; ohne gewählten Haushalt
    // öffnet die Demo den Einrichtungsassistenten (geprüft weiter unten)
    w.localStorage.setItem('et-demo-persona', 'showcase');
    w.localStorage.setItem('et-demo-level', 'expert');
    await import(pathToFileURL(join(out, 'public/js/app.js')).href);
    await settle();
    await sleep(200);
    t('App startet in der Demo', !!w.document.querySelector('#view .view-header, #view h1'), w.document.title);
    t('Kennzeichen „Demo“ in der Kopfleiste', !!w.document.querySelector('.topbar__brand .demo-chip'));
    const bar = w.document.getElementById('demo-bar');
    t('Hinweisleiste mit Bautag, Installieren-Link und Sprachwahl',
      !!bar && bar.textContent.includes(meta.today.slice(8, 10)) && !!bar.querySelector('a[href*="github.com/Bingerminger/energietracker"]')
      && bar.querySelectorAll('select option').length === langs.length);

    const { api } = await import(pathToFileURL(join(out, 'public/js/api.js')).href);
    const utilities = await api.listUtilities();
    const settings = await api.settings();
    const active = utilities.filter(u => (settings.active_utilities || []).includes(u.key));
    const year = new Date().getFullYear();
    const routes = ['#/dashboard', '#/zaehlerstaende',
      ...active.flatMap(u => [`#/utility/${u.key}`, `#/utility/${u.key}/meters`, `#/utility/${u.key}/contracts`]),
      `#/utility/gas?year=${year - 1}`,
      '#/contracts', '#/tariffs', '#/bill-check', '#/analysis', '#/forecast', '#/report', '#/report/print',
      '#/reminders', '#/recommendations', '#/help', '#/temperatures',
      '#/settings', '#/settings/household', '#/settings/utilities', '#/settings/data',
      '#/settings/integrations', '#/settings/access', '#/settings/expert', '#/settings/system'];
    const before = errors.length;
    for (const r of routes) {
      w.location.hash = r;
      await settle();
      const view = w.document.getElementById('view');
      const broken = view.querySelector('.banner--error')?.textContent?.trim();
      if (broken) errors.push(`${r}: ${broken.slice(0, 160)}`);
      if (r === '#/bill-check') {
        view.querySelector('#bc-run')?.click();
        await settle();
        t('Rechnungsprüfung mit Vorbelegung rechnet', !!view.querySelector('#bc-result table'));
      }
      if (r === '#/settings/system') {
        const link = view.querySelector('[data-source-link]')?.getAttribute('href') || '';
        t('System: Link auf den Quellcode dieser Version', link.endsWith(`/tree/v${meta.version}`), link);
      }
    }
    const misses = [...(w.__etDemoMisses || [])];
    t(`${routes.length} Seiten ohne fehlende Antworten`, misses.length === 0, misses.slice(0, 8).join(' · '));
    const newErrors = errors.slice(before);
    t('keine Fehler beim Durchklicken', newErrors.length === 0, newErrors.slice(0, 5).join(' | '));

    // v3.2.0 (F1018) — der Einrichtungsassistent bietet die Beispielhaushalte an
    const { openSetupWizard } = await import(pathToFileURL(join(out, 'public/js/components/setup-wizard.js')).href);
    await openSetupWizard({ firstRun: true });
    await settle();
    const choices = w.document.querySelectorAll('.modal input[name="setup-persona"]').length;
    t('Einrichtungsassistent mit fünf Personas', choices === 5, String(choices));
    w.document.querySelector('.modal .modal__close')?.click();

    // v3.2.0 (F1018, F1019) — jeder Beispielhaushalt, als Einsteiger und als Experte
    const state = await import(pathToFileURL(join(out, 'public/js/state.js')).href);
    const levels = await import(pathToFileURL(join(out, 'public/js/lib/levels.js')).href);
    for (const persona of (meta.personas || []).filter(x => x !== 'showcase')) {
      w.localStorage.setItem('et-demo-persona', persona);
      state.invalidateSettings(); state.invalidateUtilities();
      w.__etDemoMisses?.clear();
      const pErrors = errors.length;
      const pSettings = await api.settings();
      const pUtils = (await api.listUtilities()).filter(u => (pSettings.active_utilities || []).includes(u.key));
      const pRoutes = ['#/dashboard', '#/zaehlerstaende', ...pUtils.map(u => `#/utility/${u.key}`), '#/contracts',
        '#/tariffs', '#/bill-check', '#/report', ...(pSettings.wohnverhaeltnis === 'miete' ? ['#/tenancy'] : [])];
      for (const level of ['beginner', 'expert']) {
        w.localStorage.setItem('et-demo-level', level);
        levels.applyLevel(level);
        for (const r of pRoutes) {
          w.location.hash = r + (r.includes('?') ? '&' : '?') + 'p=' + persona + level;
          await settle();
          const broken = w.document.getElementById('view').querySelector('.banner--error')?.textContent?.trim();
          if (broken) errors.push(`${persona} ${r}: ${broken.slice(0, 160)}`);
        }
      }
      const pMisses = [...(w.__etDemoMisses || [])];
      t(`Beispielhaushalt ${persona}: ${pRoutes.length} Seiten × 2 Stufen ohne Lücke`,
        pMisses.length === 0 && errors.length === pErrors, [...pMisses.slice(0, 5), ...errors.slice(pErrors, pErrors + 3)].join(' · '));
    }
    w.localStorage.setItem('et-demo-persona', 'showcase');
    state.invalidateSettings(); state.invalidateUtilities();

    let code = null;
    try { await api.createReading('gas', { meter_id: 'x', date: '2026-01-01', counter: 1 }); }
    catch (e) { code = e.code; }
    t('Schreiben wird abgelehnt: „nichts gespeichert“', code === 'demo.readOnly', String(code));
    const csv = await (await browserFetch(api.exportMonthlyCsvUrl('gas'))).text();
    t('CSV-Export als Datei', csv.split('\n').length > 5, csv.split('\n')[0].slice(0, 60));
    const pdf = await browserFetch(api.yearlyReportUrl(year - 1, { inline: true }));
    t('Jahresbericht als PDF', pdf.ok && (await pdf.arrayBuffer()).byteLength > 1000);
    console.error = origError;
  } catch (e) {
    t('Demo-Test', false, e.stack || e.message);
  } finally {
    server?.close();
    rmSync(out, { recursive: true, force: true });
    console.log(`\n${pass} ok, ${fail} Fehler`);
    process.exit(fail ? 1 : 0);
  }
})();
