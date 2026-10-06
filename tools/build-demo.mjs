#!/usr/bin/env node
/* =========================================================================
   build-demo.mjs — die öffentliche Demo als statische Seite (GitHub Pages).

   v3.0.0. GitHub Pages hat kein PHP. Dieses Skript startet deshalb den echten
   Server mit einem leeren Datenverzeichnis, lädt die Demo-Daten über die
   normale API (`POST /api/demo/import` — dabei schreibt DemoDataAligner sie
   bis heute fort), ruft jede Leseanfrage ab, die die Oberfläche stellt, und
   legt die Antworten je Sprache unter `demo-api/` ab. Dazu kommen die
   App-Hülle (index.php, gerendert mit ET_DEMO_BUILD=1 → `data-demo`), die
   Dateien unter public/ und die Lizenz. Im Browser beantwortet
   public/js/lib/demo-mode.js die Anfragen aus diesem Abzug.

     node tools/build-demo.mjs                  # nach dist/demo
     node tools/build-demo.mjs --out /tmp/demo --port 8896

   Ansehen: `php -S 127.0.0.1:8090 -t dist/demo` (der PHP-Server liefert hier
   nur Dateien aus) und http://127.0.0.1:8090/ öffnen.

   Abgelegt werden die Antworten inhaltsadressiert (`r/<hash>.json`); was in
   allen Sprachen gleich ist, liegt nur einmal da. Ein Server-Fehler (5xx)
   bricht den Bau ab — die Demo zeigt sonst einen Fehler, den es im
   Echtbetrieb nicht gibt.
   ========================================================================= */

import { spawn, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { demoKey, demoFileName } from '../public/js/lib/demo-key.js';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const FORECAST_MODELS = ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'];

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i > 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

/** Heute in Ortszeit (wie der PHP-Server rechnet), nicht in UTC. */
function localToday() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Startet `php -S` mit eigenem, leerem Datenverzeichnis. */
async function startServer(port, dataDir) {
  const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: ROOT,
    env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_LEVEL: 'error', ET_LOG_DEST: 'stderr' },
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let stderr = '';
  proc.stderr.on('data', d => { stderr += d; });
  const base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`${base}/api.php/api/health`);
      if (r.status < 500) return { proc, base };
    } catch { /* noch nicht bereit */ }
    if (proc.exitCode !== null) break;
    await sleep(250);
  }
  proc.kill();
  throw new Error(`PHP-Server auf Port ${port} kam nicht hoch:\n${stderr.slice(-2000)}`);
}

/**
 * Alle Leseanfragen, die die Oberfläche mit den Demo-Daten stellt: globale
 * Endpunkte, je Verbrauchsart, je Zähler (samt Standard-Prognose je Modell,
 * Rechnungsprüfung und Tarifvergleich je Jahr), je Vertrag.
 */
async function enumerate(get) {
  const keys = new Set();
  const add = (...paths) => paths.forEach(p => keys.add(demoKey(p)));
  add('/api/utilities', '/api/settings', '/api/countries', '/api/settings/default-updates',
    '/api/readings-overview', '/api/temperatures', '/api/strom-saldo', '/api/pv-summary',
    '/api/health', '/api/diagnostics', '/api/backup/snapshots', '/api/session',
    '/api/auth/keys', '/api/auth/token', '/api/demo/status', '/api/reminders',
    '/api/recommendations', '/api/recommendations?include_dismissed=1',
    '/api/benchmarks/efficiency');

  const settings = (await get('/api/settings')).json?.data ?? {};
  const utilities = (await get('/api/utilities')).json?.data ?? [];
  const allMeters = [];
  const months = Number.isInteger(Number(settings.forecast_months)) ? Number(settings.forecast_months) : 12;
  let firstYear = new Date().getFullYear();
  const lastYear = new Date().getFullYear();

  for (const u of utilities) {
    const k = u.key;
    add(`/api/utility/${k}/meters`, `/api/utility/${k}/meter-groups`, `/api/utility/${k}/readings`,
      `/api/utility/${k}/contracts`, `/api/utility/${k}/consumption`);
    const delivery = u.reading_kind === 'delivery';
    if (delivery) add(`/api/utility/${k}/deliveries`);
    for (const list of ['readings', 'deliveries']) {
      for (const r of (await get(`/api/utility/${k}/${list}`)).json?.data ?? []) {
        const y = Number(String(r.date || '').slice(0, 4));
        if (y && y < firstYear) firstYear = y;
      }
    }
    const meters = (await get(`/api/utility/${k}/meters`)).json?.data ?? [];
    for (const m of meters) {
      const id = m.id;
      const q = encodeURIComponent(id);
      add(`/api/utility/${k}/meters/${id}`, `/api/utility/${k}/readings?meter_id=${q}`,
        `/api/utility/${k}/contracts?meter_id=${q}`, `/api/utility/${k}/meters/${id}/consumption`,
        `/api/utility/${k}/meters/${id}/contract-status`, `/api/utility/${k}/meters/${id}/tariff-comparison`,
        `/api/utility/${k}/meters/${id}/tariff-switch`);
      if (delivery) add(`/api/utility/${k}/deliveries?meter_id=${q}`, `/api/utility/${k}/meters/${id}/stock-history`);
      for (const model of FORECAST_MODELS) {
        // Vorbelegung der Prognose-Ansicht: ohne Was-wäre-wenn, Horizont aus den Einstellungen
        add(`/api/utility/${k}/meters/${id}/forecast?` + new URLSearchParams({
          temp_offset: 0, price_factor: 1, model, forecast_months: months }).toString());
      }
      allMeters.push({ utility: k, id });
    }
    for (const c of (await get(`/api/utility/${k}/contracts`)).json?.data ?? []) {
      add(`/api/utility/${k}/contracts/${c.id}`);
    }
  }
  const years = [];
  for (let y = firstYear; y <= lastYear; y++) years.push(y);
  for (const y of years) add(`/api/benchmarks/efficiency?year=${y}`);
  for (const m of allMeters) {
    const k = m.utility;
    for (const y of years) {
      add(`/api/utility/${k}/meters/${m.id}/tariff-comparison?year=${y}`);
      // Rechnungsprüfung: Vorbelegung (Vorjahr) und der Verweis je Jahr aus der Gas-Ansicht
      if (k === 'gas') add(`/api/utility/${k}/meters/${m.id}/bill-check?from=${y}-01-01&to=${y + 1}-01-01`);
    }
  }
  const files = [];
  for (const u of utilities) {
    files.push(`/api/export/${u.key}/monthly.csv`, `/api/export/${u.key}/readings.csv`);
    if (u.reading_kind === 'delivery') files.push(`/api/export/${u.key}/deliveries.csv`);
  }
  files.push('/api/export/temperatures.csv', '/api/reports/yearly.pdf');
  for (const y of years) files.push(`/api/reports/yearly.pdf?year=${y}`);
  return { keys: [...keys].sort(), files, years };
}

export async function buildDemo({ out = join(ROOT, 'dist', 'demo'), port = 8896, log = console.log } = {}) {
  const dataDir = mkdtempSync(join(tmpdir(), 'et-demo-'));
  const { proc, base } = await startServer(port, dataDir);
  const stats = { keys: 0, responses: 0, files: 0, bytes: 0 };
  try {
    const fetchRaw = async (path, lang = 'de', init = {}) => {
      const r = await fetch(`${base}/api.php${path}`, {
        ...init, headers: { 'Accept-Language': lang, ...(init.headers || {}) } });
      return { status: r.status, buf: Buffer.from(await r.arrayBuffer()), type: r.headers.get('content-type') || '' };
    };
    const get = async (path, lang = 'de') => {
      const r = await fetchRaw(path, lang);
      let json = null;
      try { json = JSON.parse(r.buf.toString('utf8')); } catch { /* keine JSON-Antwort */ }
      return { ...r, json };
    };

    // Demo-Daten laden — fortgeschrieben bis heute
    const imp = await fetchRaw('/api/demo/import', 'de', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ force: true }) });
    if (imp.status !== 200) throw new Error(`Demo-Import: HTTP ${imp.status} ${imp.buf.toString('utf8').slice(0, 300)}`);

    const { keys, files, years } = await enumerate(get);
    const languages = Object.keys(JSON.parse(readFileSync(join(ROOT, 'public/locales/languages.json'), 'utf8')));

    rmSync(out, { recursive: true, force: true });
    mkdirSync(join(out, 'demo-api', 'r'), { recursive: true });
    const stored = new Set();
    const store = (text) => {
      const hash = createHash('sha256').update(text).digest('hex').slice(0, 20);
      if (!stored.has(hash)) {
        writeFileSync(join(out, 'demo-api', 'r', `${hash}.json`), text);
        stored.add(hash);
        stats.bytes += Buffer.byteLength(text);
      }
      return `${hash}.json`;
    };
    const asStored = (r) => {
      if (r.status >= 500) throw new Error(`Server-Fehler ${r.status}`);
      // gespeicherte Fehlerantworten tragen ihren Status, damit demo-mode.js ihn weitergibt
      const json = r.json ?? { success: false, error: `HTTP ${r.status}` };
      if (r.status >= 400) json.status = r.status;
      return JSON.stringify(json);
    };

    // Deutsch und Englisch immer; die übrigen Sprachen nur, wo sich die Antwort unterscheidet
    const index = Object.fromEntries(languages.map(l => [l, {}]));
    const byLang = {};
    for (const lang of ['de', 'en']) {
      byLang[lang] = {};
      for (const key of keys) {
        const r = await get(key, lang);
        try { byLang[lang][key] = asStored(r); }
        catch (e) { throw new Error(`${key} (${lang}): ${e.message}`); }
      }
    }
    const varying = keys.filter(k => byLang.de[k] !== byLang.en[k]);
    for (const lang of languages) {
      for (const key of keys) {
        let text = byLang[lang]?.[key];
        if (text === undefined) {
          text = varying.includes(key) ? asStored(await get(key, lang)) : byLang.de[key];
        }
        index[lang][key] = store(text);
      }
      writeFileSync(join(out, 'demo-api', `index-${lang}.json`), JSON.stringify(index[lang]));
    }
    stats.keys = keys.length;
    stats.responses = stored.size;

    // Datei-Downloads je Sprache
    for (const lang of languages) {
      for (const f of files) {
        const r = await fetchRaw(f, lang);
        if (r.status !== 200) throw new Error(`${f} (${lang}): HTTP ${r.status}`);
        const target = join(out, 'demo-api', 'files', lang, demoFileName(f));
        mkdirSync(dirname(target), { recursive: true });
        writeFileSync(target, r.buf);
        stats.files++;
        stats.bytes += r.buf.length;
      }
    }

    // App-Hülle: index.php wie im Betrieb gerendert, mit `data-demo`
    const html = execFileSync('php', ['index.php'], {
      cwd: ROOT, env: { ...process.env, ET_DEMO_BUILD: '1', ET_DATA_DIR: dataDir }, encoding: 'utf8' });
    if (!html.includes(' data-demo')) throw new Error('index.php trägt kein data-demo');
    writeFileSync(join(out, 'index.html'), html);
    cpSync(join(ROOT, 'public'), join(out, 'public'), { recursive: true });
    for (const f of ['manifest.webmanifest', 'LICENSE']) cpSync(join(ROOT, f), join(out, f));
    writeFileSync(join(out, '.nojekyll'), '');

    const version = readFileSync(join(ROOT, 'VERSION'), 'utf8').trim();
    const health = (await get('/api/health')).json?.data ?? {};
    writeFileSync(join(out, 'demo-api', 'meta.json'), JSON.stringify({
      version, today: localToday(), built_at: new Date().toISOString(),
      schema: health.schema_version ?? null, languages, years, keys: keys.length, varying: varying.length,
    }, null, 1));
    log(`Demo gebaut: ${keys.length} Anfragen (${varying.length} sprachabhängig), ` +
      `${stored.size} Antworten, ${stats.files} Dateien, ${(stats.bytes / 1e6).toFixed(1)} MB → ${out}`);
    return { out, ...stats, varying: varying.length, keyList: keys };
  } finally {
    proc.kill();
    rmSync(dataDir, { recursive: true, force: true });
  }
}

if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  buildDemo({
    out: resolve(arg('--out', join(ROOT, 'dist', 'demo'))),
    port: Number(arg('--port', 8896)),
  }).catch((e) => { console.error(e.message || e); process.exit(1); });
}
