// =====================================================================
// v3.7.0 (Qualität) — Grenzen mit großen Datenmengen.
//
//   node tests/large-data.test.mjs              (eigener Server, freier Port oder ET_LARGE_PORT)
//   ET_PERF_STRICT=1 node tests/large-data.test.mjs   (enge Schranken, Entwicklungsrechner)
//
// Baut mit tools/large-household.mjs den erfundenen Haushalt aus der Messung
// (10 Jahre Ablesungen an jedem Tag für Gas, Strom, Wasser, PV; 2 Jahre
// Viertelstunden), startet den echten Server und ruft die Endpunkte der
// Hauptansichten ab (tools/measure-large-household.mjs, dieselbe Liste wie
// die Messung in docs/entwicklung/messung-grosse-daten.md).
//
// Warum in der CI und mit großzügigen Schranken: Die Antwortzeit hängt am
// Rechner — ein GitHub-Runner ist langsamer und schwankt. Eine enge Schranke
// würde wackeln, gar keine ließe einen Rückfall in quadratische Laufzeit
// (v3.6: Übersicht 1,1 s, Jahresbericht mit Anhang 3,4 s bei zehn Jahren)
// unbemerkt. Die CI-Schranke liegt beim Zehnfachen der Messung auf dem
// Entwicklungsrechner und fängt genau solche Sprünge; ET_PERF_STRICT=1 prüft
// gegen die Messung + 50 % (nur lokal sinnvoll). Dazu, ohne Zeitbezug: Jede
// Anfrage antwortet mit 200 und vollständigen Daten.
// =====================================================================
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildLargeHousehold } from '../tools/large-household.mjs';
import { contextOf, measureEndpoints } from '../tools/measure-large-household.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.once('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

// Messung auf dem Entwicklungsrechner (Median, ms) — Spalte „10 Jahre“ in
// docs/entwicklung/messung-grosse-daten.md; der Test vergleicht beide.
const MEASURED_10Y = {
  '/api/summary': 403, '/api/agenda?days=90': 340, 'comparison': 174, 'gas/consumption': 73, 'gas/contract-status': 83,
  'strom/consumption': 66, 'strom/readings': 9, '/api/pv-summary': 166, 'efficiency': 68, 'gas/billing-periods': 161,
  'utility-billing-periods': 162, 'yearly': 417, 'yearly-detail': 777, 'flexibility': 75, 'flexibility-summary': 57,
  'gas/forecast': 71, 'scenario': 160,
};
const DOC = 'docs/entwicklung/messung-grosse-daten.md';
const keyOf = (path) => {
  if (path.startsWith('/api/benchmarks/comparison')) return 'comparison';
  if (path.startsWith('/api/benchmarks/efficiency')) return 'efficiency';
  if (path.startsWith('/api/reports/yearly')) return path.includes('detail=1') ? 'yearly-detail' : 'yearly';
  if (path.startsWith('/api/flexibility')) return path.includes('summary=1') ? 'flexibility-summary' : 'flexibility';
  if (path === '/api/forecast/scenario') return 'scenario';
  if (path === '/api/utility/gas/billing-periods') return 'utility-billing-periods';
  const m = path.match(/^\/api\/utility\/([a-z_]+)\/(?:meters\/[^/]+\/)?([a-z-]+)/);
  return m ? `${m[1]}/${m[2]}` : path;
};
const STRICT = !!process.env.ET_PERF_STRICT;
// CI: Zehnfaches, mindestens 500 ms; lokal (streng): Messung + 50 %, mindestens + 40 ms (Rauschen kleiner Werte)
const bound = (key) => { const v = MEASURED_10Y[key] ?? 500; return STRICT ? Math.max(v * 1.5, v + 40) : Math.max(v * 10, 500); };

/** Zeile „| Ansicht | Anfrage | …“ der Doku-Tabelle → Wert der Spalte „10 Jahre“ in ms (null = fehlt). */
function documented(doc, view, label) {
  const lines = doc.split('\n');
  const head = lines.find(l => l.startsWith('| Ansicht | Anfrage |'));
  if (!head) return null;
  const col = head.split('|').map(s => s.trim()).indexOf('10 Jahre');
  const row = lines.find(l => { const c = l.split('|').map(s => s.trim()); return c[1] === view && c[2] === label; });
  if (!row || col < 0) return null;
  const cell = row.split('|').map(s => s.trim())[col] || '';
  const m = cell.match(/^([\d.,]+)\s*(ms|s)$/);
  if (!m) return null;
  const x = parseFloat(m[1].replace(/\./g, '').replace(',', '.'));
  return m[2] === 's' ? Math.round(x * 1000) : x;
}

(async () => {
  const dir = mkdtempSync(join(tmpdir(), 'et-large-'));
  let proc = null;
  try {
    const info = buildLargeHousehold(dir, { years: 10, qhYears: 2 });
    t('Haushalt: 10 Jahre Tageswerte, 2 Jahre Viertelstunden', info.readings >= 5 * 3650 && info.intervals > 200000 && info.qhYearsList.length === 2,
      `${info.readings} Ablesungen, ${info.intervals} Viertelstunden (${info.qhYearsList.join(', ')})`);
    const port = Number(process.env.ET_LARGE_PORT) || await freePort();
    proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], { cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dir, ET_LOG_DEST: 'null' }, stdio: 'ignore' });
    const base = `http://127.0.0.1:${port}`;
    let up = false;
    for (let i = 0; i < 100 && !up; i++) { try { up = (await fetch(base + '/api/health')).ok; } catch { await sleep(100); } }
    t('Server läuft', up);
    const health = await (await fetch(base + '/api/health')).json();
    t('Health ok (Schema des Haushalts = Schema der App)', health?.data?.status === 'ok', JSON.stringify(health?.data?.schema_version ?? health?.data?.status));

    const ctx = await contextOf(base);
    const get = async (p) => (await (await fetch(base + p)).json()).data;
    // vollständige Daten
    const gas = await get(`/api/utility/gas/meters/${ctx.meters.gas}/consumption`);
    const months = (gas?.monthly ?? gas ?? []).length;
    t('Gas: eine Zeile je Monat über zehn Jahre', months >= 120, `${months} Monate`);
    const periods = await get(`/api/utility/gas/meters/${ctx.meters.gas}/billing-periods`);
    t('Abrechnungsperioden: zehn Jahre', (periods?.periods ?? []).length >= 10, `${(periods?.periods ?? []).length} Perioden`);
    const flex = await get(`/api/flexibility?year=${ctx.qhYear}`);
    t(`Flexibilität ${ctx.qhYear}: Stromzähler mit Viertelstunden`, (flex?.meters ?? []).length >= 1);

    const res = await measureEndpoints(base, ctx, 2);
    const bad = res.filter(r => r.status !== 200).map(r => `${r.label}: HTTP ${r.status}`);
    t(`${res.length} Endpunkte der Hauptansichten antworten mit 200`, res.length >= 15 && bad.length === 0, bad.join(' | '));
    const slow = res.filter(r => r.median > bound(keyOf(r.path))).map(r => `${r.label} ${Math.round(r.median)} ms > ${Math.round(bound(keyOf(r.path)))} ms`);
    t(`Antwortzeiten unter ${STRICT ? 'Messung + 50 %' : 'dem Zehnfachen der Messung'} (${res.length} Endpunkte)`, slow.length === 0,
      slow.length ? slow.join(' | ') : `langsamster: ${res.slice().sort((a, b) => b.median - a.median)[0]?.label} ${Math.round(res.slice().sort((a, b) => b.median - a.median)[0]?.median)} ms`);
    const total = res.reduce((s, r) => s + r.median, 0);
    const totalBound = Object.values(MEASURED_10Y).reduce((s, v) => s + v, 0) * (STRICT ? 1.5 : 10);
    t('Summe aller Antwortzeiten', total <= totalBound, `${Math.round(total)} ms ≤ ${Math.round(totalBound)} ms`);
    t('Jede Messung hat einen Richtwert im Test', res.every(r => keyOf(r.path) in MEASURED_10Y), res.filter(r => !(keyOf(r.path) in MEASURED_10Y)).map(r => r.path).join(', '));
    const doc = readFileSync(join(ROOT, DOC), 'utf8');
    const drift = res.filter(r => documented(doc, r.view, r.label) !== MEASURED_10Y[keyOf(r.path)])
      .map(r => `${r.view} / ${r.label}: Doku ${documented(doc, r.view, r.label) ?? 'fehlt'}, Test ${MEASURED_10Y[keyOf(r.path)]}`);
    t(`Doku-Tabelle (${DOC}, Spalte „10 Jahre“) = Richtwerte des Tests`, drift.length === 0, drift.join(' | '));
    t('Doku nennt Schranke und Schalter des Tests', /Zehnfachen/.test(doc) && doc.includes('ET_PERF_STRICT=1') && doc.includes('plus 50 %') && doc.includes('mindestens 500 ms'));
  } catch (e) {
    t('Grenzen mit großen Datenmengen', false, e.stack || e.message);
  } finally {
    proc?.kill();
    rmSync(dir, { recursive: true, force: true });
    console.log(`\nlarge-data: ${pass} bestanden, ${fail} fehlgeschlagen`);
    process.exit(fail ? 1 : 0);
  }
})();
