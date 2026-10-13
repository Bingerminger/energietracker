// =====================================================================
// Ansichten mit präparierten Daten (v3.4.0, N1020).
//
//   node tests/poisoned-render.test.mjs
//
// Die Sicherheitsprüfung 2026-10-12 fand rund 200 Stellen, an denen Text als
// HTML in die Seite geht — alle korrekt escaped, aber nur von Hand geprüft.
// Dieser Test hält das fest: Er startet den echten Server mit den Demo-Daten,
// in denen jeder Freitext (Namen, Notizen, Anbieter, Tarife, Lieferanten …)
// und sogar der Pfad des Datenverzeichnisses HTML und Anführungszeichen
// tragen, lädt die echte App (app.js) in JSDOM und ruft jede Seite auf. Landet
// ein Wert irgendwo ungeschützt im DOM — als Element oder als Attribut —,
// entsteht ein Element mit `data-xss`, und der Test wird rot.
// =====================================================================
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

/** Wird ein Wert irgendwo roh eingesetzt, entsteht ein Element mit data-xss. */
const PAYLOAD = `Q"'><i data-xss></i><b data-xss "data-xss="1`;
/** Freitext-Felder in den Datendateien (Schlüssel, nicht Pfade). */
const TEXT_KEYS = new Set(['name', 'notes', 'note', 'provider', 'tariff_name', 'label', 'supplier', 'title', 'description',
  'serial', 'reason', 'comment', 'location_name', 'reference_source', 'vehicle', 'loadpoint', 'tenant', 'landlord']);

function poison(node) {
  if (Array.isArray(node)) return node.map(poison);
  if (node && typeof node === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(node)) {
      out[k] = typeof v === 'string' && TEXT_KEYS.has(k) ? `${v} ${PAYLOAD}` : poison(v);
    }
    return out;
  }
  return node;
}

function poisonDir(dir) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) { poisonDir(path); continue; }
    if (!entry.endsWith('.json') || ['meta.json', 'temperatures.json'].includes(entry)) continue;
    const data = JSON.parse(readFileSync(path, 'utf8'));
    writeFileSync(path, JSON.stringify(poison(data)));
  }
}

const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.once('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

(async () => {
  // Auch der Pfad des Datenverzeichnisses (System → Diagnose) trägt HTML
  const base = mkdtempSync(join(tmpdir(), 'et-poison-'));
  const dataDir = join(base, `daten-"'><i data-xss>`);   // ohne / (Pfadtrenner)
  mkdirSync(dataDir);
  for (const entry of readdirSync(join(ROOT, 'demo-data'))) {
    if (['personas', 'sources', 'translations.json', 'energietracker-demo-backup.json', 'README.md'].includes(entry)) continue;
    cpSync(join(ROOT, 'demo-data', entry), join(dataDir, entry), { recursive: true });
  }
  poisonDir(dataDir);
  // Kein Wetterabgleich beim Start (ginge ins Netz)
  const settingsFile = join(dataDir, 'settings.json');
  const settings = JSON.parse(readFileSync(settingsFile, 'utf8'));
  writeFileSync(settingsFile, JSON.stringify({ ...settings, weather_auto_fill: false, setup_pending: false, ui_level: 'expert' }));

  const port = await freePort();
  const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null' }, stdio: 'ignore',
  });
  const BASE = `http://127.0.0.1:${port}/`;
  try {
    let up = false;
    for (let i = 0; i < 100 && !up; i++) {
      try { up = (await fetch(BASE + 'api/health')).ok; } catch { await sleep(100); }
    }
    t('Server mit präparierten Daten läuft', up);
    const meters = await (await fetch(BASE + 'api/utility/strom/meters')).json();
    t('Daten tragen die Präparation', JSON.stringify(meters).includes('data-xss'));

    const html = await (await fetch(BASE)).text();
    const dom = new JSDOM(html, { url: BASE + '#/dashboard', pretendToBeVisual: true });
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
      try {
        const r = await nativeFetch(input, init);
        const buf = await r.arrayBuffer();
        const empty = [101, 204, 205, 304].includes(r.status) || (init?.method || 'GET').toUpperCase() === 'HEAD';
        return new Response(empty ? null : buf, { status: r.status, statusText: r.statusText, headers: r.headers });
      } finally { inflight--; }
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
    process.on('unhandledRejection', () => {});

    const settle = async () => {
      for (let i = 0; i < 150; i++) {
        await sleep(30);
        if (inflight === 0 && !w.document.querySelector('#view .loading')) { await sleep(80); if (inflight === 0) return; }
      }
    };
    const injected = () => [...w.document.querySelectorAll('[data-xss]')]
      .map(el => el.outerHTML.slice(0, 80) + ' in ' + (el.parentElement?.outerHTML.slice(0, 120) ?? ''));

    await import(pathToFileURL(join(ROOT, 'public/js/app.js')).href);
    await settle();
    t('App startet', !!w.document.querySelector('#view .view-header, #view h1'));

    const { api } = await import(pathToFileURL(join(ROOT, 'public/js/api.js')).href);
    const utilities = await api.listUtilities();
    const active = utilities.filter(u => (settings.active_utilities || utilities.map(x => x.key)).includes(u.key));
    const year = new Date().getFullYear();
    const routes = ['#/dashboard', '#/zaehlerstaende',
      ...active.flatMap(u => [`#/utility/${u.key}`, `#/utility/${u.key}/meters`, `#/utility/${u.key}/contracts`]),
      `#/utility/gas?year=${year - 1}`,
      '#/contracts', '#/tariffs', '#/bill-check', '#/analysis', '#/forecast', '#/flexibility', '#/report', '#/report/print',
      '#/reminders', '#/recommendations', '#/help', '#/temperatures',
      '#/settings', '#/settings/household', '#/settings/utilities', '#/settings/data',
      '#/settings/integrations', '#/settings/access', '#/settings/expert', '#/settings/system'];
    const hits = [];
    let probe = null;
    for (const r of routes) {
      w.location.hash = r;
      await settle();
      for (const h of injected()) hits.push(`${r}: ${h}`);
      w.document.querySelectorAll('[data-xss]').forEach(el => el.removeAttribute('data-xss'));
      if (r === '#/settings/access') {
        for (let i = 0; i < 50 && w.document.querySelector('#security-data-probe')?.dataset.state === 'pending'; i++) await sleep(30);
        probe = w.document.querySelector('#security-data-probe')?.dataset.state ?? null;
      }
    }
    // v3.4.0 (N1020) — Selbsttest im Browser: router.php sperrt data/ (404) → grün
    t('Zugriff: Selbsttest „data/ gesperrt“ läuft und ist grün', probe === 'ok', String(probe));
    const diag = w.document.querySelector('.diag-grid')?.textContent || '';
    t('System → Diagnose zeigt den Pfad als Text', diag.includes('data-xss'), diag.includes('data-xss') ? 'escaped' : 'Pfad fehlt');
    t(`${routes.length} Seiten: kein Wert aus den Daten als HTML`, hits.length === 0, hits.slice(0, 6).join(' | '));
  } catch (e) {
    t('Test mit präparierten Daten', false, e.stack || e.message);
  } finally {
    proc.kill();
    rmSync(base, { recursive: true, force: true });
    console.log(`\npoisoned-render: ${pass} bestanden, ${fail} fehlgeschlagen`);
    process.exit(fail ? 1 : 0);
  }
})();
