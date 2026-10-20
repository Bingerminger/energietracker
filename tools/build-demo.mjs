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

   Seit v3.4.0 (N1019) gehört das Handbuch dazu: jede Seite, die der Server
   unter /api/docs freigibt (eigener Index `demo-api/docs-index.json`, für
   alle Haushalte und Sprachen derselbe), und ihre Bilder unter
   `demo-api/docs/`.

   Abgelegt werden die Antworten inhaltsadressiert (`r/<hash>.json`); was in
   allen Sprachen gleich ist, liegt nur einmal da. Ein Server-Fehler (5xx)
   bricht den Bau ab — die Demo zeigt sonst einen Fehler, den es im
   Echtbetrieb nicht gibt.
   ========================================================================= */

import { spawn, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { demoKey, demoFileName, demoBodyKey } from '../public/js/lib/demo-key.js';
import { normalizeAnchor, exclusiveEnd as addDay } from '../public/js/lib/billing-period.js';
// v3.6.0 (F1031) — Prognose-Werkstatt: dieselben Anfragen wie die Oberfläche
import { defaultState, presetState, presetsFor, scenarioBody } from '../public/js/lib/scenario.js';

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
async function enumerate(get, post = null) {
  const keys = new Set();
  // v3.6.0 (F1031) — Szenarien: Ausgangsfall und Beispielszenarien je Zähler
  const scenarios = [];
  const add = (...paths) => paths.forEach(p => keys.add(demoKey(p)));
  add('/api/utilities', '/api/settings', '/api/countries', '/api/settings/default-updates',
    '/api/readings-overview', '/api/temperatures', '/api/strom-saldo', '/api/pv-summary',
    '/api/health', '/api/diagnostics', '/api/backup/snapshots', '/api/session',
    '/api/auth/keys', '/api/auth/token', '/api/demo/status', '/api/reminders',
    '/api/recommendations', '/api/recommendations?include_dismissed=1',
    '/api/benchmarks/efficiency',
    // v3.1.0 (H1) — „Zu tun" aus der Agenda; Kennzahlen wie für Home Assistant
    '/api/agenda?days=90', '/api/summary', '/api/attachments',   // v3.1.0 — H1, H2
    // v3.1.0 (H3–H8) — Mietverhältnis, Börsenpreise, Einordnung auf der Übersicht
    '/api/tenancies', '/api/market-prices', '/api/benchmarks/comparison',
    '/api/intervals', '/api/flexibility',    // v3.3.0 (F1021) — Vorbelegung: Vorjahr
    '/api/activity',                          // v3.4.0 (F1024) — Hinweise › Erledigt, Verlauf je Vertrag
    '/api/people',                            // v3.5.0 (F1028) — Personen für „Zuständig“; v3.6.0 aus dem Verzeichnis
    '/api/users');                            // v3.6.0 — Einstellungen → Zugriff: Personen ohne Zugang
  // v3.6.0 — Vorschau „Person löschen“ je Person (Löschen selbst lehnt die Demo ab)
  for (const p of (await get('/api/people')).json?.data ?? []) add(`/api/users/${p.id}/handover`);

  // v3.5.0 (F1027) — Tagesfilm: welche Tage es gibt, die Tage des Monats der
  // Vorbelegung und der typische Tag je Monat. Andere Tage meldet die Demo als
  // „nicht vorberechnet“ (der Film sagt es unter der Auswahl).
  add('/api/energy-flow/days');
  const efDays = (await get('/api/energy-flow/days')).json?.data;
  if (efDays?.available) {
    const ym = String(efDays.default_day || '').slice(0, 7);
    for (const d of efDays.days.filter(x => x.startsWith(`${ym}-`))) add(`/api/energy-flow/day?date=${d}`);
    for (const m of efDays.months) add(`/api/energy-flow/typical-day?month=${m}`);
  }

  const settings = (await get('/api/settings')).json?.data ?? {};
  // v3.2.0 (F1018) — Mietverhältnis der Beispielhaushalte: Budget und Abrechnungen
  for (const ten of (await get('/api/tenancies')).json?.data ?? []) {
    add(`/api/tenancies/${ten.id}/budget`, `/api/tenancies/${ten.id}/statements`);
  }
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
    if (u.supports_bill_check) add(`/api/utility/${k}/bills`);   // v3.1.0 (H5)
    if (u.supports_bill_check) add(`/api/utility/${k}/billing-periods`);   // v3.6.0 (F1030) — Übersicht im Abrechnungsjahr
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
      if (u.supports_bill_check) add(`/api/utility/${k}/bills?meter_id=${q}`);    // v3.1.0 (H5)
      // v3.6.0 (F1030) — Verbrauchsart und Analyse im Abrechnungsjahr
      if (u.supports_bill_check && m.capture !== 'period') add(`/api/utility/${k}/meters/${id}/billing-periods?months=1`);
      // v3.6.0 (Paket abrechnung) — „Rechnung prüfen“ schlägt die jüngste
      // abgeschlossene Periode ohne Rechnung vor; der Verweis der Verbrauchsart
      // nimmt im Abrechnungsjahr die gewählte Periode: jede abgeschlossene vorrechnen
      if (u.supports_bill_check) {
        add(`/api/utility/${k}/meters/${id}/billing-periods`);
        for (const p of (await get(`/api/utility/${k}/meters/${id}/billing-periods`)).json?.data?.periods ?? []) {
          if (p.complete && !p.running) add(`/api/utility/${k}/meters/${id}/bill-check?from=${p.from}&to=${addDay(p.until)}`);
        }
      }
      if (m.capture === 'period') add(`/api/utility/${k}/periods?meter_id=${q}`); // v3.1.0 (H3)
      for (const model of FORECAST_MODELS) {
        // Vorbelegung der Prognose-Ansicht: ohne Was-wäre-wenn, Horizont aus den Einstellungen
        add(`/api/utility/${k}/meters/${id}/forecast?` + new URLSearchParams({
          temp_offset: 0, price_factor: 1, model, forecast_months: months }).toString());
      }
      allMeters.push({ utility: k, id, billCheck: !!u.supports_bill_check, role: m.role || null });
      // v3.6.0 (F1031) — Prognose-Werkstatt: Die Oberfläche rechnet zuerst den
      // Ausgangsfall (alle Regler auf null), die Beispielszenarien leitet sie aus
      // dessen Antwort ab (lib/scenario.js) — hier genauso, damit dieselben
      // Anfragen ihre vorgerechnete Antwort finden.
      if (post && !delivery && u.has_contracts !== false && u.accounting_kind !== 'feed_in') {
        const base = scenarioBody(defaultState(k, id));
        const r = await post('/api/forecast/scenario', base);
        scenarios.push(base);
        const data = r.json?.data;
        if (r.status === 200 && data?.valid) {
          for (const pid of presetsFor(data)) scenarios.push(scenarioBody(presetState(pid, defaultState(k, id), data)));
        }
      }
    }
    for (const c of (await get(`/api/utility/${k}/contracts`)).json?.data ?? []) {
      add(`/api/utility/${k}/contracts/${c.id}`);
    }
    // v3.6.0 (Paket abrechnung) — Zählergruppen der Übersicht im Abrechnungsjahr
    if (u.supports_bill_check) {
      for (const g of (await get(`/api/utility/${k}/meter-groups`)).json?.data ?? []) add(`/api/utility/${k}/meter-groups/${g.id}/billing-periods`);
    }
  }
  const years = [];
  for (let y = firstYear; y <= lastYear; y++) years.push(y);
  for (const y of years) add(`/api/benchmarks/efficiency?year=${y}`);
  // v3.1.0 — Druckansicht; v3.4.0 (F1025) — immer mit Anhang, die Ansicht blendet ihn ein
  for (const y of years) add(`/api/reports/yearly?year=${y}&detail=1`);
  for (const y of years) add(`/api/co2-costs?year=${y}`);       // v3.1.0 (H4) — CO₂-Preis-Karte in der Verbrauchsansicht
  // v3.1.0 (H4, H7, H8) — CO₂-Aufteilung, Wärmepumpen-Karte, Einordnung je Jahr
  for (const y of years) add(`/api/co2-split?year=${y}`, `/api/heat-pump?year=${y}`, `/api/benchmarks/comparison?year=${y}`);
  for (const y of years) add(`/api/flexibility?year=${y}`);   // v3.3.0 (F1021)
  // v3.6.0 — Kachel „Dein Hebel“ der Übersicht (Vorjahr; ein Jahr Puffer, falls die Demo über Silvester steht)
  for (const y of [lastYear - 2, lastYear - 1, lastYear].filter(x => x >= firstYear)) add(`/api/flexibility?summary=1&year=${y}`);
  for (const m of allMeters) {
    const k = m.utility;
    for (const y of years) {
      add(`/api/utility/${k}/meters/${m.id}/tariff-comparison?year=${y}`);
      // Rechnungsprüfung: Vorbelegung (Vorjahr) und der Verweis je Jahr aus der
      // Verbrauchsansicht — seit v3.1.0 (H5) für jede Art mit Rechnungsprüfung
      if (m.billCheck) add(`/api/utility/${k}/meters/${m.id}/bill-check?from=${y}-01-01&to=${y + 1}-01-01`);
      // v3.4.0 (N1021) — Vorbelegung seit v3.4.0: die Abrechnungsperiode nach dem Stichtag der Art
      const anchor = normalizeAnchor(settings[`billing_cycle_anchor_${k}`]);
      if (m.billCheck) add(`/api/utility/${k}/meters/${m.id}/bill-check?from=${y}-${anchor}&to=${y + 1}-${anchor}`);
      // v3.2.0 — Ladestrom-Nachweis der Wallbox (Vorbelegung: Vertragspreis)
      if (m.role === 'ev_charger') {
        add(`/api/reports/ev-charging?meter_id=${encodeURIComponent(m.id)}&year=${y}&method=contract`);
        add(`/api/ev-sessions?meter_id=${encodeURIComponent(m.id)}&year=${y}`);   // F1022 — Ladevorgänge aus evcc
      }
    }
  }
  const files = [];
  // v3.1.0 (I18N-10) — jede Tabelle auch im Format „local"
  const csv = (p) => files.push(p, `${p}?format=local`);
  for (const u of utilities) {
    csv(`/api/export/${u.key}/monthly.csv`);
    csv(`/api/export/${u.key}/readings.csv`);
    if (u.reading_kind === 'delivery') csv(`/api/export/${u.key}/deliveries.csv`);
    // v3.6.0 (F1030) — je Abrechnungsperiode (die App hängt format=local hinten an)
    if (u.supports_bill_check) files.push(`/api/export/${u.key}/monthly.csv?period=billing`, `/api/export/${u.key}/monthly.csv?period=billing&format=local`);
  }
  csv('/api/export/temperatures.csv');
  files.push('/api/reports/yearly.pdf', '/api/calendar.ics');   // v3.1.0 — Kalender-Abo
  for (const y of years) files.push(`/api/reports/yearly.pdf?year=${y}`);
  return { keys: [...keys].sort(), files, years, scenarios };
}

/**
 * v3.4.0 (N1019) — Kandidaten für das Handbuch der Demo: alle Markdown-Dateien
 * und PNG unter docs/, dazu die Dateien im Wurzelverzeichnis. Was davon
 * ausgeliefert wird, entscheidet der Server (Freigabeliste in DocsService) —
 * hier steht keine zweite Liste.
 */
function docCandidates() {
  const pages = [], images = [];
  const walk = (dir, rel) => {
    for (const e of readdirSync(dir, { withFileTypes: true })) {
      if (e.isSymbolicLink()) continue;
      if (e.isDirectory()) walk(join(dir, e.name), `${rel}${e.name}/`);
      else if (e.name.endsWith('.md')) pages.push(rel + e.name);
      else if (/\.png$/i.test(e.name)) images.push(rel + e.name);
    }
  };
  walk(join(ROOT, 'docs'), '');
  for (const e of readdirSync(ROOT, { withFileTypes: true })) {
    if (e.isFile() && (e.name.endsWith('.md') || e.name === 'LICENSE')) pages.push(`root/${e.name}`);
  }
  return { pages: pages.sort(), images: images.sort() };
}

export async function buildDemo({ out = join(ROOT, 'dist', 'demo'), port = 8896, log = console.log } = {}) {
  const dataDir = mkdtempSync(join(tmpdir(), 'et-demo-'));
  const { proc, base } = await startServer(port, dataDir);
  const stats = { keys: 0, responses: 0, files: 0, bytes: 0, docs: 0, docImages: 0 };
  try {
    // v3.1.0 — `X-ET-Language` wie die Oberfläche (Sprache pro Gerät): Bezeichnungen
    // und Meldungen kommen so in jeder Sprache, nicht in der Standardsprache des Servers.
    const fetchRaw = async (path, lang = 'de', init = {}) => {
      const r = await fetch(`${base}/api.php${path}`, {
        ...init, headers: { 'Accept-Language': lang, 'X-ET-Language': lang, ...(init.headers || {}) } });
      return { status: r.status, buf: Buffer.from(await r.arrayBuffer()), type: r.headers.get('content-type') || '' };
    };
    const get = async (path, lang = 'de') => {
      const r = await fetchRaw(path, lang);
      let json = null;
      try { json = JSON.parse(r.buf.toString('utf8')); } catch { /* keine JSON-Antwort */ }
      return { ...r, json };
    };
    // v3.6.0 (F1031) — POST, der nur rechnet (Szenario)
    const post = async (path, body, lang = 'de') => {
      const r = await fetchRaw(path, lang, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      let json = null;
      try { json = JSON.parse(r.buf.toString('utf8')); } catch { /* keine JSON-Antwort */ }
      return { ...r, json };
    };

    // Demo-Daten laden — fortgeschrieben bis heute, Namen und Notizen in der Sprache
    // der Anfrage (v3.1.0, I18N-24). Je Sprache neu, die IDs bleiben dieselben.
    // v3.2.0 (F1018) — je Beispielhaushalt (Persona) eine eigene Ablage:
    // „showcase" (alle Arten) an der bisherigen Stelle, die anderen unter p/<persona>/
    const importDemo = async (lang, persona) => {
      const imp = await fetchRaw('/api/demo/import', lang, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ force: true, ...(persona === 'showcase' ? {} : { persona }) }) });
      if (imp.status !== 200) throw new Error(`Demo-Import (${persona}, ${lang}): HTTP ${imp.status} ${imp.buf.toString('utf8').slice(0, 300)}`);
    };
    await importDemo('de', 'showcase');
    const personas = (await get('/api/demo/status')).json?.data?.personas || ['showcase'];
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

    let keys = [], files = [], years = [], varying = [];
    const allKeys = new Set();
    for (const persona of personas) {
      const dir = persona === 'showcase' ? join(out, 'demo-api') : join(out, 'demo-api', 'p', persona);
      mkdirSync(dir, { recursive: true });
      await importDemo('de', persona);
      const found = await enumerate(get, post);
      // v3.6.0 (F1031) — Szenarien sind sprachunabhängig (nur Zahlen und IDs):
      // ein Index je Haushalt, gerechnet mit den deutschen Daten (gleiche IDs)
      const scenarioIndex = {};
      for (const body of found.scenarios) {
        let text;
        try { text = asStored(await post('/api/forecast/scenario', body)); }
        catch (e) { throw new Error(`Szenario ${body.utility}/${body.meter_id} (${persona}): ${e.message}`); }
        scenarioIndex[demoBodyKey('/api/forecast/scenario', body)] = store(text);
      }
      writeFileSync(join(dir, 'scenarios.json'), JSON.stringify(scenarioIndex));
      stats.scenarios = (stats.scenarios || 0) + found.scenarios.length;
      if (persona === 'showcase') ({ keys, files, years } = found);
      found.keys.forEach(k => allKeys.add(k));

      // Jede Sprache vollständig; gleiche Antworten liegen dank Inhaltsadresse nur einmal da
      const index = Object.fromEntries(languages.map(l => [l, {}]));
      const firstOf = {};
      for (const lang of languages) {
        await importDemo(lang, persona);
        for (const key of found.keys) {
          let text;
          try { text = asStored(await get(key, lang)); }
          catch (e) { throw new Error(`${key} (${persona}, ${lang}): ${e.message}`); }
          index[lang][key] = store(text);
          firstOf[key] ??= index[lang][key];
        }
        writeFileSync(join(dir, `index-${lang}.json`), JSON.stringify(index[lang]));
        // Datei-Downloads dieser Sprache, solange ihre Daten geladen sind
        for (const f of found.files) {
          // „local" in der Sprache der Demo (im Betrieb: Standardsprache der Installation)
          const r = await fetchRaw(f.includes('format=local') ? `${f}&lang=${lang}` : f, lang);
          if (r.status !== 200) throw new Error(`${f} (${persona}, ${lang}): HTTP ${r.status}`);
          const target = join(dir, 'files', lang, demoFileName(f));
          mkdirSync(dirname(target), { recursive: true });
          writeFileSync(target, r.buf);
          stats.files++;
          stats.bytes += r.buf.length;
        }
      }
      if (persona === 'showcase') varying = keys.filter(k => languages.some(l => index[l][k] !== firstOf[k]));
    }
    // v3.4.0 (N1019) — Handbuch: in allen Haushalten und Sprachen dasselbe, also
    // ein eigener Index (docs-index.json) und die Bilder als fertige Dateien.
    // Gespeichert wird auch die 404 einer Weiterleitung (nennt ihr Ziel).
    const docsIndex = {};
    const docCand = docCandidates();
    for (const p of docCand.pages) {
      const path = `/api/docs?path=${encodeURIComponent(p)}`;
      const r = await get(path);
      if (r.status === 404 && !r.json?.detail?.moved_to) continue;   // nicht freigegeben
      try { docsIndex[demoKey(path)] = store(asStored(r)); }
      catch (e) { throw new Error(`${path}: ${e.message}`); }
      if (r.status === 200) stats.docs++;
    }
    writeFileSync(join(out, 'demo-api', 'docs-index.json'), JSON.stringify(docsIndex));
    for (const img of docCand.images) {
      const r = await fetchRaw(`/api/docs/asset?path=${encodeURIComponent(img)}`);
      if (r.status !== 200) continue;
      const target = join(out, 'demo-api', 'docs', img);
      mkdirSync(dirname(target), { recursive: true });
      writeFileSync(target, r.buf);
      stats.docImages++;
      stats.bytes += r.buf.length;
    }

    stats.keys = allKeys.size;
    stats.responses = stored.size;

    // App-Hülle: index.php wie im Betrieb gerendert, mit `data-demo`
    const html = execFileSync('php', ['index.php'], {
      cwd: ROOT, env: { ...process.env, ET_DEMO_BUILD: '1', ET_DATA_DIR: dataDir }, encoding: 'utf8' });
    if (!html.includes(' data-demo')) throw new Error('index.php trägt kein data-demo');
    writeFileSync(join(out, 'index.html'), html);
    cpSync(join(ROOT, 'public'), join(out, 'public'), { recursive: true });
    for (const f of ['manifest.webmanifest', 'LICENSE']) cpSync(join(ROOT, f), join(out, f));
    // v3.1.0 (I18N-30) — Manifest je Sprache; die Pfade zeigen von api.php/api/ (../../) auf die Wurzel der Demo
    for (const lang of languages) {
      const m = (await get(`/api/manifest?lang=${lang}`)).buf.toString('utf8').replaceAll('../../', './');
      writeFileSync(join(out, `manifest-${lang}.webmanifest`), m);
    }
    writeFileSync(join(out, '.nojekyll'), '');

    const version = readFileSync(join(ROOT, 'VERSION'), 'utf8').trim();
    const health = (await get('/api/health')).json?.data ?? {};
    writeFileSync(join(out, 'demo-api', 'meta.json'), JSON.stringify({
      version, today: localToday(), built_at: new Date().toISOString(),
      schema: health.schema_version ?? null, languages, years, keys: keys.length, varying: varying.length, personas,
      docs: stats.docs, doc_images: stats.docImages, scenarios: stats.scenarios || 0,
    }, null, 1));
    log(`Demo gebaut: ${keys.length} Anfragen (${varying.length} sprachabhängig), ` +
      `${stored.size} Antworten, ${stats.files} Dateien, Handbuch ${stats.docs} Seiten + ${stats.docImages} Bilder, ` +
      `${(stats.bytes / 1e6).toFixed(1)} MB → ${out}`);
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
