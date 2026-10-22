#!/usr/bin/env node
/* =========================================================================
   measure-large-household.mjs — Antwortzeiten mit großen Datenmengen (v3.7.0).

   Baut mit tools/large-household.mjs Haushalte verschiedener Größe, startet
   je einen eigenen PHP-Server und misst die Endpunkte der Hauptansichten
   (Median und langsamster von n Läufen, nach einem Aufwärmlauf). Mit
   --render zusätzlich die Zeit bis eine Ansicht in JSDOM fertig dasteht
   (echte App, alle Anfragen gegen den Server; ohne Zeichnen der Diagramme).

     node tools/measure-large-household.mjs                       # 10 Jahre, 2 Jahre Viertelstunden
     node tools/measure-large-household.mjs --years 5,10,20,30 --runs 5
     NODE_PATH=… node tools/measure-large-household.mjs --render --json /tmp/messung.json

   Optionen: --years (Liste), --qh-years (2), --runs (5), --port (Startport,
   sonst frei), --demo (zusätzlich die Beispieldaten als Vergleich),
   --render, --json <Datei>. Ergebnis als Markdown-Tabelle auf stdout; die
   Zahlen stehen in docs/entwicklung/messung-grosse-daten.md.
   ========================================================================= */
import { spawn } from 'node:child_process';
import { cpSync, mkdtempSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir, cpus } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { buildLargeHousehold } from './large-household.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.once('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

/** Die Endpunkte der Hauptansichten — je Ansicht der Aufruf, auf den sie wartet. */
export function endpoints({ year, qhYear, meters }) {
  const m = (u) => meters[u];
  const scenario = { utility: 'gas', meter_id: m('gas'), model: 'standard', winter_pct: 10, room_temp_delta: 0, saving_pct: 0, baseload_pct: 0, ev_km: 0, measures: [], tariff: { kind: 'current' } };
  return [
    { view: 'Übersicht', label: 'Zusammenfassung', path: '/api/summary' },
    { view: 'Übersicht', label: 'Termine (90 Tage)', path: '/api/agenda?days=90' },
    { view: 'Übersicht', label: 'Einordnung', path: `/api/benchmarks/comparison?year=${year}` },
    { view: 'Verbrauchsart', label: 'Gas: Verbrauch je Monat', path: `/api/utility/gas/meters/${m('gas')}/consumption` },
    { view: 'Verbrauchsart', label: 'Gas: Vertragsstatus/Saldo', path: `/api/utility/gas/meters/${m('gas')}/contract-status` },
    { view: 'Verbrauchsart', label: 'Strom: Verbrauch je Monat', path: `/api/utility/strom/meters/${m('strom')}/consumption` },
    { view: 'Verbrauchsart', label: 'Strom: Ablesungen (Liste)', path: `/api/utility/strom/readings?meter_id=${m('strom')}` },
    { view: 'Verbrauchsart', label: 'PV: Eigenverbrauch', path: '/api/pv-summary' },
    { view: 'Analyse', label: 'Effizienz', path: `/api/benchmarks/efficiency?year=${year}` },
    { view: 'Abrechnungsperioden', label: 'Gas: Perioden je Zähler', path: `/api/utility/gas/meters/${m('gas')}/billing-periods` },
    { view: 'Abrechnungsperioden', label: 'Gas: Perioden der Art', path: '/api/utility/gas/billing-periods' },
    { view: 'Jahresbericht', label: 'Modell (Druckansicht)', path: `/api/reports/yearly?year=${year}` },
    { view: 'Jahresbericht', label: 'Modell mit Anhang', path: `/api/reports/yearly?year=${year}&detail=1` },
    { view: 'Flexibilität', label: 'Auswertung', path: `/api/flexibility?year=${qhYear}` },
    { view: 'Flexibilität', label: 'Kachel der Übersicht', path: `/api/flexibility?year=${qhYear}&summary=1` },
    { view: 'Prognose-Werkstatt', label: 'Gas: Prognose', path: `/api/utility/gas/meters/${m('gas')}/forecast` },
    { view: 'Prognose-Werkstatt', label: 'Gas: Szenario „Kalter Winter“', path: '/api/forecast/scenario', method: 'POST', body: scenario },
  ];
}

async function startServer(dataDir, port) {
  const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null' }, stdio: 'ignore',
  });
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(base + '/api/health')).ok) return { proc, base }; } catch { /* startet noch */ }
    await sleep(100);
  }
  proc.kill();
  throw new Error('Server kam nicht hoch');
}

async function timed(base, e) {
  const t0 = performance.now();
  const r = await fetch(base + e.path, e.method ? { method: e.method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(e.body) } : undefined);
  const buf = await r.arrayBuffer();
  return { ms: performance.now() - t0, status: r.status, bytes: buf.byteLength };
}

/**
 * Misst alle Endpunkte gegen einen laufenden Server.
 * @returns {Promise<Array<{view, label, path, median, max, status, bytes}>>}
 */
export async function measureEndpoints(base, ctx, runs = 5) {
  const out = [];
  for (const e of endpoints(ctx)) {
    await timed(base, e);   // Aufwärmen (Opcache, Dateicache)
    const ms = [];
    let last = null;
    for (let i = 0; i < runs; i++) { last = await timed(base, e); ms.push(last.ms); }
    ms.sort((a, b) => a - b);
    out.push({ view: e.view, label: e.label, path: e.path, median: ms[Math.floor(ms.length / 2)], max: ms[ms.length - 1], status: last.status, bytes: last.bytes });
  }
  return out;
}

/** Kontext (Jahr, Viertelstundenjahr, Zähler) aus einem laufenden Server. */
export async function contextOf(base) {
  const get = async (p) => (await (await fetch(base + p)).json()).data;
  const meters = {};
  for (const u of ['gas', 'strom', 'wasser', 'pv_einspeisung', 'pv_erzeugung']) meters[u] = (await get(`/api/utility/${u}/meters`))?.[0]?.id ?? null;
  const intervals = await get('/api/intervals').catch(() => []);
  const qhYears = (Array.isArray(intervals) ? intervals : intervals?.series || []).filter(s => s.kind === 'energy').map(s => s.year);
  const year = new Date().getFullYear() - 1;
  return { year, qhYear: qhYears.length ? Math.max(...qhYears) : year, meters };
}

// ── Render-Zeit in JSDOM (eigener Prozess: die App-Module laden einmal je Prozess) ──
async function renderChild(base, routes) {
  const { createRequire } = await import('node:module');
  const require = createRequire(import.meta.url);
  const { JSDOM, VirtualConsole } = require('jsdom');
  const html = await (await fetch(base + '/')).text();
  const dom = new JSDOM(html, { url: base + '/#/help', pretendToBeVisual: true, virtualConsole: new VirtualConsole() });
  const w = dom.window;
  for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Element', 'Node', 'CustomEvent', 'Event',
    'getComputedStyle', 'localStorage', 'sessionStorage', 'MouseEvent', 'KeyboardEvent', 'requestAnimationFrame', 'MutationObserver']) {
    try { global[k] = w[k]; } catch { Object.defineProperty(global, k, { value: w[k], configurable: true, writable: true }); }
  }
  Object.defineProperty(global, 'navigator', { value: w.navigator, configurable: true, writable: true });
  let inflight = 0;
  const nativeFetch = global.fetch;
  const f = async (input, init) => {
    let url = typeof input === 'string' ? input : input?.url;
    if (!/^https?:/.test(url)) url = new URL(url, w.location.href).href;
    if (!url.startsWith(base) || /update-check|geocode|sync/.test(url)) return new Response('{"success":false}', { status: 503 });
    inflight++;
    try { const r = await nativeFetch(url, init); const b = await r.arrayBuffer(); return new Response([204, 304].includes(r.status) ? null : b, { status: r.status, headers: r.headers }); }
    finally { inflight--; }
  };
  w.fetch = f; global.fetch = f;
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.scrollTo = () => {};
  const Chart = function (canvas, config) { return { canvas, config, destroy() {}, update() {}, resize() {} }; };
  Chart.defaults = { color: '', borderColor: '', font: { family: '', size: 12 }, plugins: { legend: { labels: {} }, tooltip: {} }, scale: { grid: {}, ticks: {} } };
  Chart.register = () => {};
  w.Chart = Chart; global.Chart = Chart;
  process.on('unhandledRejection', () => {});
  const settle = async () => {
    let quiet = 0;
    for (let i = 0; i < 3000; i++) {
      if (inflight === 0 && !w.document.querySelector('#view .loading, #view [aria-busy="true"]')) { if (++quiet >= 3) return; } else quiet = 0;
      await sleep(5);
    }
  };
  await import(pathToFileURL(join(ROOT, 'public/js/app.js')).href);
  await settle();
  const out = [];
  for (const r of routes) {
    const ms = [];
    for (let i = 0; i < 3; i++) {
      w.location.hash = '#/help';
      await settle();
      const t0 = performance.now();
      w.location.hash = r.hash;
      await sleep(5);
      await settle();
      ms.push(performance.now() - t0 - 15);   // drei ruhige Prüfungen à 5 ms abgezogen
    }
    ms.sort((a, b) => a - b);
    out.push({ ...r, median: ms[1], max: ms[2] });
  }
  return out;
}

export const RENDER_ROUTES = (ctx) => [
  { view: 'Übersicht', hash: '#/dashboard' },
  { view: 'Verbrauchsart (Gas)', hash: '#/utility/gas' },
  { view: 'Verbrauchsart (Strom)', hash: '#/utility/strom' },
  { view: 'Analyse', hash: '#/analysis' },
  { view: 'Prognose-Werkstatt', hash: '#/forecast' },
  { view: 'Flexibilität', hash: '#/flexibility' },
  { view: 'Jahresbericht (Druckansicht)', hash: `#/report/print?year=${ctx.year}` },
  { view: 'Zählerstände erfassen', hash: '#/zaehlerstaende' },
];

function renderInChild(base, ctx) {
  return new Promise((res, rej) => {
    const p = spawn(process.execPath, [fileURLToPath(import.meta.url), '--render-child', base, JSON.stringify(RENDER_ROUTES(ctx))], { stdio: ['ignore', 'pipe', 'inherit'], env: process.env });
    let out = '';
    p.stdout.on('data', d => { out += d; });
    p.on('close', code => { try { res(JSON.parse(out.trim().split('\n').pop())); } catch { rej(new Error(`Render-Messung fehlgeschlagen (${code})`)); } });
  });
}

// ── Aufruf ──
if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  const args = process.argv.slice(2);
  if (args[0] === '--render-child') {
    const res = await renderChild(args[1], JSON.parse(args[2]));
    console.log(JSON.stringify(res));
    process.exit(0);
  }
  const opt = (k, d) => { const i = args.indexOf(`--${k}`); return i >= 0 ? args[i + 1] : d; };
  const sizes = String(opt('years', '10')).split(',').map(Number).filter(n => n > 0);
  const runs = Number(opt('runs', 5));
  const qhYears = Number(opt('qh-years', 2));
  let port = Number(opt('port', 0));
  const result = { measured_at: new Date().toISOString(), node: process.version, cpus: cpus()[0]?.model, runs, sets: [] };
  const sets = [...(args.includes('--demo') ? [{ name: 'Beispieldaten (demo-data)', demo: true }] : []), ...sizes.map(y => ({ name: `${y} Jahre`, years: y }))];
  for (const s of sets) {
    const dir = mkdtempSync(join(tmpdir(), 'et-measure-'));
    let info = null;
    const t0 = Date.now();
    if (s.demo) {
      for (const entry of readdirSync(join(ROOT, 'demo-data'))) {
        if (['personas', 'sources', 'translations.json', 'energietracker-demo-backup.json', 'README.md'].includes(entry)) continue;
        cpSync(join(ROOT, 'demo-data', entry), join(dir, entry), { recursive: true });
      }
    } else {
      info = buildLargeHousehold(dir, { years: s.years, qhYears });
    }
    const p = port ? port++ : await freePort();
    const { proc, base } = await startServer(dir, p);
    try {
      const firstStart = performance.now();
      await fetch(base + '/api/settings');
      const first = performance.now() - firstStart;
      const ctx = await contextOf(base);
      const eps = await measureEndpoints(base, ctx, runs);
      const render = args.includes('--render') ? await renderInChild(base, ctx) : null;
      result.sets.push({ name: s.name, years: s.years ?? null, readings: info?.readings ?? null, intervals: info?.intervals ?? null, build_ms: Date.now() - t0, first_request_ms: first, endpoints: eps, render });
      console.error(`· ${s.name}: ${eps.length} Endpunkte gemessen${render ? ', Ansichten gerendert' : ''}`);
    } finally {
      proc.kill();
      rmSync(dir, { recursive: true, force: true });
    }
  }
  if (opt('json')) writeFileSync(opt('json'), JSON.stringify(result, null, 2));
  // Markdown
  const fmt = (x) => x >= 1000 ? `${(x / 1000).toFixed(2)} s` : `${Math.round(x)} ms`;
  const names = result.sets.map(s => s.name);
  console.log(`\n| Ansicht | Anfrage | ${names.join(' | ')} |\n|---|---|${names.map(() => '---:').join('|')}|`);
  result.sets[0].endpoints.forEach((e, i) => {
    console.log(`| ${e.view} | ${e.label} | ${result.sets.map(s => { const x = s.endpoints[i]; return x.status >= 400 ? `HTTP ${x.status}` : fmt(x.median); }).join(' | ')} |`);
  });
  if (result.sets.some(s => s.render)) {
    console.log(`\n| Ansicht (JSDOM) | ${names.join(' | ')} |\n|---|${names.map(() => '---:').join('|')}|`);
    result.sets.find(s => s.render).render.forEach((r, i) => console.log(`| ${r.view} | ${result.sets.map(s => s.render ? fmt(s.render[i].median) : '–').join(' | ')} |`));
  }
  console.log(`\nAblesungen: ${result.sets.map(s => `${s.name} ${s.readings ?? '–'}`).join(', ')} · Median aus ${runs} Läufen nach einem Aufwärmlauf · ${result.cpus}, Node ${result.node}`);
}
