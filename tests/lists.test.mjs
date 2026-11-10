// =====================================================================
// Listen, Verträge, Rechnung prüfen (v3.4.0, N1021).
//
//   node tests/lists.test.mjs
//
// Teil A ohne Server: Reihenfolge der Verträge (lib/contract-order.js),
// Blättern (components/pager.js), Abrechnungsperiode (lib/billing-period.js).
//
// Teil B mit dem echten Server und dem Beispielhaushalt „eigenheim-modern“
// (Wärmepumpe, Angebot für das nächste Jahr, zwei beendete Verträge), dazu
// ein Testzähler mit beendetem und weiterlaufendem Vertrag und ein Zähler mit
// 400 Tageswerten wie aus Home Assistant. Die echte App (app.js) läuft in
// JSDOM:
//   - beide Vertragslisten in derselben Reihenfolge, Status aus `phase`
//     (bis v3.3: „VERGANGEN“ hier, „VERLÄNGERT“ dort)
//   - Ablesungen „N in Jahr · M gesamt“, Jahreswahl mit „Alle Jahre“,
//     Blättern ab 51 Zeilen, Seitengröße gemerkt, Seite zurückgesetzt bei
//     Jahreswechsel, beim Neuzeichnen behalten, „nur auffällige“
//   - Rechnung prüfen ohne Zeitraum rechnet sofort mit der letzten
//     abgeschlossenen Abrechnungsperiode nach dem Stichtag, Wechsel von Art
//     und Zähler behält den Zeitraum, Adresse per replaceState
//
// Port: ET_LISTS_PORT (sonst ein freier).
// =====================================================================
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const JS = (p) => pathToFileURL(join(ROOT, 'public/js', p)).href;

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const iso = (d) => d.toISOString().slice(0, 10);

// ── Teil A: Bausteine ────────────────────────────────────────────────
const order = await import(JS('lib/contract-order.js'));
const pager = await import(JS('components/pager.js'));
const bp = await import(JS('lib/billing-period.js'));

{
  const list = [
    { id: 'angebot',  phase: 'shadow',  start: '2027-01-01', end: null },
    { id: 'wp',       phase: 'current', start: '2022-11-01', end: null },
    { id: 'alt',      phase: 'past',    start: '2023-01-01', end: '2023-12-31' },
    { id: 'kommt',    phase: 'future',  start: '2027-01-01', end: null },
    { id: 'abgeloest', phase: 'past',   start: '2020-01-01', end: null },
    { id: 'weiter',   phase: 'renewed', start: '2024-01-01', end: '2025-12-31' },
    { id: 'neu',      phase: 'current', start: '2026-01-01', end: '2026-12-31' },
    { id: 'vorjahr',  phase: 'past',    start: '2024-01-01', end: '2024-12-31' },
  ];
  const ids = order.sortContracts(list).map(c => c.id);
  t('Reihenfolge: Zukünftig · Aktuell (Beginn absteigend, verlängert dabei) · Beendet (Ende absteigend) · Angebote',
    ids.join() === 'kommt,neu,weiter,wp,abgeloest,vorjahr,alt,angebot', ids.join());
  const groups = order.groupContracts(list);
  t('Gruppen nur mit Einträgen, „Beendet“ ab drei eingeklappt',
    groups.map(g => `${g.group}:${g.items.length}${g.collapsed ? '*' : ''}`).join() === 'future:1,current:3,past:3*,shadow:1',
    groups.map(g => `${g.group}:${g.items.length}${g.collapsed ? '*' : ''}`).join());
  t('zwei beendete bleiben aufgeklappt', order.groupContracts(list.filter(c => c.id !== 'alt')).find(g => g.group === 'past').collapsed === false);
  t('Einträge aus contract-status (contract_id) sortieren gleich',
    order.sortContracts(list.map(({ id, ...c }) => ({ contract_id: id, ...c }))).map(c => c.contract_id).join() === ids.join());
  t('ohne Phase gilt ein Vertrag als aktuell', order.phaseOf({}) === 'current' && order.groupOf({ phase: 'renewed' }) === 'current');
  t('Jahre am Eintrag', order.yearsOf(list[2]).span === '2023' && order.yearsOf(list[5]).span === '2024–2025' && order.yearsOf(list[1]).since === '2022');
  t('Statusmarke aus der Phase', /class="status-pill active" data-phase="renewed"/.test(order.phasePill({ phase: 'renewed' })));
  // v3.11.0 (Skeptiker N9) — abgelöst vor dem eingetragenen Ende: „Abgelöst“ und das wirksame Ende, nicht „Beendet 2024–2029“
  const sup = { phase: 'past', start: '2024-01-01', end: '2029-12-31', coverage: { superseded_on: '2025-12-31' } };
  const pill = order.phasePill(sup);
  t('abgelöst: eigene Marke, Jahre bis zum wirksamen Ende', /data-phase="past"/.test(pill) && /Abgelöst|contracts\.phase\.superseded/.test(pill)
    && order.yearsOf(sup).span === '2024–2025' && order.supersededOn(sup) === '2025-12-31', pill);
  t('abgelöst am Ende selbst (lückenlos): weiter „Beendet“', order.supersededOn({ phase: 'past', start: '2024-01-01', end: '2025-12-31', coverage: { superseded_on: '2025-12-31' } }) === null);
}

{
  const items = Array.from({ length: 51 }, (_, i) => i);
  t('bis 50 Zeilen keine Blätterleiste', pager.paginate(items.slice(0, 50)).active === false && pager.paginate(items.slice(0, 50)).items.length === 50
    && pager.pagerHtml(pager.paginate(items.slice(0, 50)), { listKey: 'x' }) === '');
  const p = pager.paginate(items);
  t('ab 51 Zeilen: Seiten zu 25', p.active && p.pages === 3 && p.items.length === 25 && p.from === 1 && p.to === 25 && p.total === 51);
  const last = pager.paginate(items, { page: 99 });
  t('Seite wird geklemmt (etwa nach dem Löschen)', last.page === 3 && last.from === 51 && last.to === 51 && last.items[0] === 50);
  t('Blätterleiste als nav mit aria-label und Seitengröße', /<nav class="pager" aria-label="[^"]+" data-pager="x">/.test(pager.pagerHtml(p, { listKey: 'x' }))
    && /data-pager-go="prev" disabled/.test(pager.pagerHtml(p, { listKey: 'x' })) && /<option value="25" selected>/.test(pager.pagerHtml(p, { listKey: 'x' })));

  const store = new Map();
  globalThis.localStorage = { getItem: k => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: k => store.delete(k) };
  t('Standard 25', pager.pageSize('liste-a') === 25);
  pager.savePageSize('liste-a', 50);
  pager.savePageSize('liste-a', 30);
  t('Seitengröße je Liste gemerkt (nur 25/50/100)', store.get('et-page-size:liste-a') === '50' && pager.pageSize('liste-a') === 50 && pager.pageSize('liste-b') === 25);
  globalThis.localStorage = { getItem() { throw new Error('gesperrt'); }, setItem() { throw new Error('gesperrt'); } };
  pager.savePageSize('liste-c', 100);
  t('ohne Speicher gilt die Wahl bis zum Neuladen', pager.pageSize('liste-c') === 100);
  delete globalThis.localStorage;
}

{
  const lc = (a, d) => { const r = bp.lastClosedPeriod(a, d); return `${r.from}..${r.until}`; };
  t('Stichtag 01-01: Vorjahr', lc('01-01', '2026-10-13') === '2025-01-01..2025-12-31');
  t('Stichtag 06-01: über den Jahreswechsel', lc('06-01', '2026-10-13') === '2025-06-01..2026-05-31' && lc('06-01', '2026-05-31') === '2024-06-01..2025-05-31');
  t('am Stichtag endet die Periode gestern', lc('06-01', '2026-06-01') === '2025-06-01..2026-05-31');
  t('29.02. wie der Server als 28.02., ungültig als 01-01', lc('02-29', '2026-03-01') === '2025-02-28..2026-02-27' && lc('13-45', '2026-10-13') === '2025-01-01..2025-12-31'
    && lc(undefined, '2026-10-13') === '2025-01-01..2025-12-31' && bp.normalizeAnchor('04-31') === '01-01');
  t('bis einschließlich → `to` der Schnittstelle', bp.exclusiveEnd('2025-12-31') === '2026-01-01' && bp.exclusiveEnd('2024-02-28') === '2024-02-29');
  t('Kalenderjahr erkannt', bp.calendarYearOf({ from: '2025-01-01', until: '2025-12-31' }) === 2025 && bp.calendarYearOf({ from: '2025-04-01', until: '2026-03-31' }) === null);
}

// v3.6.0 (F1030, #33) — Abrechnungsjahr: gemerkte Wahl, Anordnung der Perioden
{
  t('Abrechnungsjahr: ohne Speicher gilt das Kalenderjahr', bp.periodMode() === 'calendar');
  const store = {};
  globalThis.localStorage = { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); } };
  bp.setPeriodMode('billing');
  t('Abrechnungsjahr: die Wahl merkt sich das Gerät', bp.periodMode() === 'billing' && store['et-period-mode'] === 'billing');
  globalThis.localStorage = { getItem: () => { throw new Error('gesperrt'); }, setItem: () => { throw new Error('gesperrt'); } };
  bp.setPeriodMode('billing');
  t('Abrechnungsjahr: gesperrter Speicher bricht nichts', bp.periodMode() === 'calendar');
  delete globalThis.localStorage;
  t('nur Arten mit Rechnungsprüfung und Ständen', bp.billingApplies({ supports_bill_check: true }) && !bp.billingApplies({ supports_bill_check: false })
    && !bp.billingApplies({ supports_bill_check: true }, { capture: 'period' }));
  const periods = [
    { from: '2024-04-15', until: '2025-04-14', label: '2024/25', days: 365, months: [] },
    { from: '2025-04-15', until: '2026-03-30', label: null, days: 200, months: [
      { ym: '2025-04', month: 4, days: 16, kwh: 100, cost: 30, advance_eur: 48, kwh_cost: 25 },
      { ym: '2025-05', month: 5, days: 31, kwh: 200, cost: 60, advance_eur: 90, kwh_cost: 50 },
    ] },
    { from: '2026-03-31', until: '2027-04-14', label: null, days: 0, months: [] },
  ];
  t('Periode: Name vom Server, sonst die Daten', bp.periodName(periods[0]) === '2024/25' && bp.periodName(periods[1], (d) => d.split('-').reverse().join('.')) === '15.04.2025–30.03.2026');
  t('ohne Wahl die jüngste mit Daten, die laufende ohne Ablesung fällt weg', bp.pickPeriod(periods)?.from === '2025-04-15' && bp.pickPeriod(periods, '2024-04-15')?.from === '2024-04-15'
    && bp.periodsWithData(periods).length === 2);
  t('Stelle im Abrechnungsjahr: Stichtagsmonat am Ende ein zweites Mal (13)', bp.slotOf(4, 0, 4) === 1 && bp.slotOf(3, 11, 4) === 12 && bp.slotOf(4, 12, 4) === 13 && bp.slotOf(1, 0, 9) === 5);
  const rows = bp.periodRows(periods[1], [{ ym: '2025-05', avg_temp: 12.5, hdd: 62, days: 31, contract_id: 'c1' }], 'kwh', 4);
  t('Monatszeilen: Stelle, Temperatur und Vertrag aus dem Kalendermonat, Saldo fortlaufend', rows[1].slot === 2 && rows[1].avg_temp === 12.5 && rows[1].contract_id === 'c1'
    && rows[1].cumulative_balance === -48 && rows[0].kwh_per_day === 100 / 16 && rows[0].working_price_ct === 25);
  t('Spaltennamen ab dem ersten Monat', bp.slotLabels(13, 4, (m) => m).join() === '4,5,6,7,8,9,10,11,12,1,2,3,4');
  // v3.10.0 (GitHub #36) — Trend im Abrechnungsjahr gegen dieselbe Spanne davor (vom Server)
  const run = { from: '2026-01-01', as_of: '2026-02-28', kwh: 600, cost: 190, bonus: 10,
    prev_same_span: { from: '2025-01-01', until: '2025-02-28', days: 59, kwh: 500, cost: 160, bonus: 0 } };
  const tr = bp.sameSpanTrend(run, s => s.kwh);
  t('Abrechnungsjahr: Trend gegen dieselben Tage davor (+20 %), mit beiden Spannen', Math.abs(tr?.pct - 20) < 1e-9
    && tr.from === '2026-01-01' && tr.until === '2026-02-28' && tr.prevFrom === '2025-01-01' && tr.prevUntil === '2025-02-28');
  t('Abrechnungsjahr: Kosten ohne Bonus auf beiden Seiten (200 gegen 160 → +25 %)',
    Math.abs(bp.sameSpanTrend(run, s => s.cost + s.bonus)?.pct - 25) < 1e-9);
  t('Abrechnungsjahr: ohne Vergleich, ohne Stand oder ohne Menge davor kein Trend',
    bp.sameSpanTrend({ ...run, prev_same_span: null }, s => s.kwh) === null && bp.sameSpanTrend({ ...run, as_of: null }, s => s.kwh) === null
    && bp.sameSpanTrend({ ...run, prev_same_span: { ...run.prev_same_span, kwh: 0 } }, s => s.kwh) === null);
  // Nachtrag #36 — die Grundlage reicht durch: Periode davor oder dieselben Kalendertage ein Jahr früher
  t('Abrechnungsjahr: Grundlage des Vergleichs (period, calendar; ohne Angabe period)',
    tr.basis === 'period' && bp.sameSpanTrend({ ...run, prev_same_span: { ...run.prev_same_span, basis: 'calendar' } }, s => s.kwh)?.basis === 'calendar'
    && bp.sameSpanTrend({ ...run, prev_same_span: { ...run.prev_same_span, basis: 'period' } }, s => s.kwh)?.basis === 'period');
  // v3.10.0 (Skeptiker N3) — ohne Namen die Daten nur einmal („Abrechnungsjahr 31.08.2026–29.07.2027: 31.08.2026 bis …“)
  const named = bp.periodWindow({ from: '2025-09-01', until: '2026-08-31', label: '2025/26' });
  const unnamed = bp.periodWindow({ from: '2026-08-31', until: '2027-07-29', label: null });
  const cat = JSON.parse(readFileSync(join(ROOT, 'public/locales/de.json'), 'utf8')).billing.dashboard;
  t('Abrechnungsjahr: Zeile der Übersicht mit Namen, ohne Namen nur die Daten',
    named.key === 'billing.dashboard.window' && named.params.name === '2025/26'
    && unnamed.key === 'billing.dashboard.windowUnnamed' && !('name' in unnamed.params)
    && unnamed.params.from === '2026-08-31' && unnamed.params.until === '2027-07-29'
    && !cat.windowUnnamed.includes('{name}') && cat.windowUnnamed.includes('{from}'), JSON.stringify(unnamed));
  // v3.6.0 (Paket abrechnung) — Vorschlag für „Rechnung prüfen“
  const ptc = (list, a, d) => { const r = bp.periodToCheck(list, a, d); return `${r.from}..${r.until}`; };
  const billed = [
    { from: '2024-04-15', until: '2025-04-14', source: 'bill', complete: true, running: false, days: 365 },
    { from: '2025-04-15', until: '2026-04-14', source: 'anchor', complete: true, running: false, days: 365 },
    { from: '2026-04-15', until: '2027-04-14', source: 'anchor', complete: false, running: true, days: 180 },
  ];
  // Stichtag 01-01, Rechnungen vom 15.04.: nicht das Kalenderjahr quer zu den Rechnungen
  t('Rechnung prüfen: die jüngste abgeschlossene Abrechnungsperiode', ptc(billed, '01-01', '2026-10-13') === '2025-04-15..2026-04-14');
  t('… auch die einer Rechnung, nie die laufende oder eine Teilperiode', ptc([billed[0], billed[2]], '01-01', '2026-10-13') === '2024-04-15..2025-04-14'
    && ptc([{ ...billed[1], complete: false }], '01-01', '2026-10-13') === '2025-01-01..2025-12-31');
  t('… ohne Perioden wie bisher nach dem Stichtag', ptc(null, '06-01', '2026-10-13') === '2025-06-01..2026-05-31');
}

// ── Teil B: echte App mit Beispielhaushalt ───────────────────────────
const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.once('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

const dataDir = mkdtempSync(join(tmpdir(), 'et-lists-'));
writeFileSync(join(dataDir, 'settings.json'), JSON.stringify({ language: 'de' }));
const port = Number(process.env.ET_LISTS_PORT) || await freePort();
const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
  cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null' }, stdio: 'ignore',
});
const BASE = `http://127.0.0.1:${port}/`;
const nativeFetch = globalThis.fetch;
const call = async (method, path, body) => {
  const r = await nativeFetch(BASE + path.replace(/^\//, ''), {
    method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined,
  });
  const j = await r.json().catch(() => null);
  if (!r.ok) throw new Error(`${method} ${path}: ${r.status} ${JSON.stringify(j).slice(0, 200)}`);
  return j?.data ?? j;
};

try {
  let up = false;
  for (let i = 0; i < 100 && !up; i++) {
    try { up = (await nativeFetch(BASE + 'api/health')).ok; } catch { await sleep(100); }
  }
  t('Testserver läuft', up);
  await call('POST', '/api/demo/import', { force: true, persona: 'eigenheim-modern' });

  const today = new Date();
  const Y = today.getFullYear();
  // Testzähler Strom: 2023 beendet (gekündigt), danach beendet und weiterlaufend
  const testMeter = await call('POST', '/api/utility/strom/meters', { name: 'Testzähler' });
  const mk = (c, more = []) => call('POST', '/api/utility/strom/contracts', { meter_id: testMeter.id, provider: 'Test', ...c,
    working_prices: [{ from: c.start, ct_per_kwh: 30 }, ...more], base_prices: [{ from: c.start, eur_per_month: 10 }] });
  const oldC = await mk({ start: '2023-01-01', end: '2023-12-31', auto_renews: false, tariff_name: 'Alt' });
  const renewedC = await mk({ start: '2024-01-01', end: `${Y - 1}-12-31`, tariff_name: 'Weiter' }, [{ from: '2025-01-01', ct_per_kwh: 32 }]);

  // Wasser: 500 Tageswerte bis heute (wie aus Home Assistant), zwei Verdachtsfälle weit hinten.
  // v3.12.0 — geblättert wird im Jahr mit den meisten Werten (PY), gewechselt in ein zweites mit
  // mindestens 51 (OY). Bis v3.11 waren es 400 Werte und immer das laufende Jahr: Von Dezember bis
  // März hatte es zu wenige für Seite 51–100, und an manchen Tagen gab es kein zweites Jahr mit
  // Blätterleiste. Mit 500 Werten gibt es an jedem Tag beide.
  const N = 500;
  const water = await call('POST', '/api/utility/wasser/meters', { name: 'Garten täglich' });
  const deviceId = water.devices?.[0]?.id ?? null;
  const daily = Array.from({ length: N }, (_, i) => {
    const d = new Date(Date.UTC(Y, today.getMonth(), today.getDate() - i));
    return { id: `r_daily_${i}`, meter_id: water.id, device_id: deviceId, date: iso(d), counter: 5000 - i * 0.3,
      price_cents: null, note: '', is_estimated: false, is_future: false, ...(i === 300 || i === 350 ? { is_suspect: true } : {}) };
  });
  const rFile = join(dataDir, 'wasser', 'readings.json');
  let existing = [];
  try { existing = JSON.parse(readFileSync(rFile, 'utf8')); } catch { /* noch keine */ }
  writeFileSync(rFile, JSON.stringify([...existing, ...daily]));
  const perYear = {};
  for (const r of daily) perYear[r.date.slice(0, 4)] = (perYear[r.date.slice(0, 4)] || 0) + 1;
  const byCount = Object.keys(perYear).map(Number).sort((a, b) => perYear[b] - perYear[a] || b - a);
  const PY = byCount[0];
  const inPY = perYear[PY];
  const OY = byCount.find(y => y !== PY && perYear[y] >= 51);
  const inOY = perYear[OY] || 0;
  const newestPY = daily.find(r => r.date.startsWith(String(PY))).date.split('-').reverse().join('.');

  // Einstellungen: Experte, kein Wetterabruf, Wasser aktiv, Stichtag Strom 01.04.
  const sFile = join(dataDir, 'settings.json');
  const settings = JSON.parse(readFileSync(sFile, 'utf8'));
  writeFileSync(sFile, JSON.stringify({ ...settings, ui_level: 'expert', weather_auto_fill: false, setup_pending: false,
    active_utilities: [...new Set([...(settings.active_utilities || []), 'wasser'])], billing_cycle_anchor_strom: '04-01' }));
  const ymd = iso(new Date(Date.UTC(Y, today.getMonth(), today.getDate())));
  const curStart = ymd >= `${Y}-04-01` ? Y : Y - 1;
  const stromPeriod = { from: `${curStart - 1}-04-01`, until: `${curStart}-03-31` };

  // App in JSDOM, wie tests/poisoned-render.test.mjs
  const { JSDOM } = require('jsdom');
  const html = await (await nativeFetch(BASE)).text();
  const dom = new JSDOM(html, { url: BASE + '#/dashboard', pretendToBeVisual: true });
  const w = dom.window;
  for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event',
    'getComputedStyle', 'localStorage', 'sessionStorage', 'MouseEvent', 'KeyboardEvent', 'requestAnimationFrame']) {
    try { global[k] = w[k]; } catch { Object.defineProperty(global, k, { value: w[k], configurable: true, writable: true }); }
  }
  Object.defineProperty(global, 'navigator', { value: w.navigator, configurable: true, writable: true });
  let inflight = 0;
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
  w.HTMLElement.prototype.scrollIntoView = function () {};
  const Chart = function (canvas, config) { return { canvas, config, destroy() {}, update() {}, resize() {} }; };
  Chart.defaults = { color: '', borderColor: '', font: { family: '', size: 12 },
    plugins: { legend: { labels: {} }, tooltip: {} }, scale: { grid: {}, ticks: {} } };
  Chart.register = () => {};
  w.Chart = Chart; global.Chart = Chart;
  w.confirm = () => true; global.confirm = w.confirm;
  process.on('unhandledRejection', () => {});

  const settle = async () => {
    for (let i = 0; i < 200; i++) {
      await sleep(30);
      if (inflight === 0 && !w.document.querySelector('#view .loading')) { await sleep(80); if (inflight === 0) return; }
    }
  };
  const view = () => w.document.getElementById('view');
  const go = async (hash) => { w.location.hash = hash; await settle(); };
  const change = async (el, value) => {
    el.value = value;
    el.dispatchEvent(new w.Event('change', { bubbles: true }));
    await settle();
  };
  const text = (el) => (el?.textContent || '').replace(/\s+/g, ' ').trim();

  await import(JS('app.js'));
  await settle();
  t('App startet', !!w.document.querySelector('#view .view-header, #view h1'));

  // ── Verträge: Kosten → Verträge ──
  const api = await call('GET', '/api/utility/strom/contracts');
  const apiPhase = Object.fromEntries(api.map(c => [c.id, c.phase]));
  await go('#/utility/strom/contracts');
  const cards = [...view().querySelectorAll('.contract-card')].map(el => ({
    id: el.dataset.contractId,
    phase: el.querySelector('.status-pill')?.dataset.phase,
    group: el.closest('[data-phase-group]')?.dataset.phaseGroup,
    label: text(el.querySelector('.status-pill')),
  }));
  t('Kosten → Verträge: alle Verträge als Karten', cards.length === api.length, `${cards.length}/${api.length}`);
  t('Kosten → Verträge: Status aus `phase` des Servers', cards.every(c => c.phase === apiPhase[c.id]),
    cards.filter(c => c.phase !== apiPhase[c.id]).map(c => `${c.id}:${c.phase}≠${apiPhase[c.id]}`).join(' '));
  const rank = { future: 0, current: 1, past: 2, shadow: 3 };
  const byId = Object.fromEntries(api.map(c => [c.id, c]));
  const sortedOk = cards.every((c, i) => {
    if (i === 0) return true;
    const p = cards[i - 1];
    if (rank[p.group] !== rank[c.group]) return rank[p.group] < rank[c.group];
    const key = (x) => c.group === 'past' ? (byId[x.id].end || '9999-12-31') : (byId[x.id].start || '');
    return key(p) >= key(c);
  });
  t('Kosten → Verträge: Zukünftig · Aktuell · Beendet · Angebote, jeweils der neueste oben', sortedOk,
    cards.map(c => `${c.group}:${c.id}`).join(' '));
  // v3.5.0 (N1026) — der Wärmepumpentarif wurde gewechselt: unbefristet ist der Folgevertrag
  const wpOpen = api.find(c => c.meter_id === 'm_strom_waermepumpe' && !c.end && !c.is_shadow)?.id;
  t('Kosten → Verträge: unbefristeter Vertrag der Wärmepumpe steht unter „Aktuell“',
    !!wpOpen && cards.find(c => c.id === wpOpen)?.group === 'current', `${wpOpen}: ${cards.find(c => c.id === wpOpen)?.group}`);
  t('Kosten → Verträge: Angebot (Schattenvertrag) am Ende, mit Verweis auf den Tarifvergleich',
    cards.at(-1)?.group === 'shadow' && !!view().querySelector('[data-phase-group="shadow"] a[href="#/tariffs"]'), cards.at(-1)?.id);
  const pastBox = view().querySelector('details[data-phase-group="past"]');
  const nPast = cards.filter(c => c.group === 'past').length;
  t('Kosten → Verträge: „Beendet“ ab drei Einträgen eingeklappt', !!pastBox && pastBox.open === (nPast < 3)
    && /Beendet \(\d+\)/.test(text(pastBox.querySelector('summary'))), `${nPast} beendet, offen=${pastBox?.open}`);
  t('Kosten → Verträge: weiterlaufender Vertrag heißt „Verlängert“', cards.find(c => c.id === renewedC.id)?.label === 'Verlängert'
    && cards.find(c => c.id === oldC.id)?.label === 'Beendet', `${cards.find(c => c.id === renewedC.id)?.label}`);
  const curCard = view().querySelector(`.contract-card[data-contract-id="${wpOpen}"]`);
  t('Kosten → Verträge: Karte nennt heutige Werte, Zählungen erst ab „Experte“',
    /Arbeitspreis [\d,.]+ ct\/kWh/.test(text(curCard?.querySelector('.contract-card__values')))
      && curCard?.querySelector('.contract-card__counts')?.dataset.minLevel === 'expert'
      && /\d Arbeitspreis/.test(text(curCard?.querySelector('.contract-card__counts'))), text(curCard?.querySelector('.contract-card__values')));
  // Vertragsdialog: Preiszeilen neueste oben, „+ Zeile“ fügt oben ein
  view().querySelector(`[data-edit-contract="${renewedC.id}"]`)?.click();
  await settle();
  const wpDates = () => [...w.document.querySelectorAll('#modal-root [data-group="working_prices"] .entry-row [data-role="date"]')].map(i => i.value);
  t('Vertragsdialog: Preiszeilen neueste oben', wpDates().join() === '2025-01-01,2024-01-01', wpDates().join());
  w.document.querySelector('#modal-root [data-group="working_prices"] [data-action="add-row"]')?.click();
  t('Vertragsdialog: „+ Zeile“ fügt oben ein', wpDates().join() === ',2025-01-01,2024-01-01', wpDates().join());
  w.document.querySelector('#modal-root [data-act="cancel"]')?.click();
  await settle();

  // ── Verträge & Abschläge auf der Seite der Verbrauchsart ──
  const rowsOf = () => [...view().querySelectorAll('.contracts-table tbody tr[data-contract-id]')].map(tr => ({
    id: tr.dataset.contractId, phase: tr.querySelector('.status-pill')?.dataset.phase,
    group: tr.closest('tbody')?.dataset.phaseGroup, hidden: tr.hidden,
  }));
  await go(`#/utility/strom?meter=${encodeURIComponent(testMeter.id)}`);
  const tRows = rowsOf();
  t('Verträge & Abschläge: derselbe Status wie in Kosten → Verträge',
    tRows.length === 2 && tRows.every(r => r.phase === cards.find(c => c.id === r.id)?.phase),
    tRows.map(r => `${r.id}:${r.phase}`).join(' '));
  t('Verträge & Abschläge: verlängert unter „Aktuell“ oben, beendet darunter',
    tRows[0]?.id === renewedC.id && tRows[0]?.group === 'current' && tRows[1]?.id === oldC.id && tRows[1]?.group === 'past');
  await go('#/utility/strom?meter=m_strom_haushalt');
  const hRows = rowsOf();
  const hOk = hRows.every((r, i) => i === 0 || rank[hRows[i - 1].group] <= rank[r.group]);
  t('Verträge & Abschläge: Zukünftig vor Aktuell vor Beendet (bis v3.3 älteste oben)', hOk && hRows[0]?.group !== 'past',
    hRows.map(r => `${r.group}:${r.id}`).join(' '));
  t('Verträge & Abschläge: keine Angebote im Saldo', !hRows.some(r => r.phase === 'shadow'));
  t('Verträge & Abschläge: Kopfzeilen je Gruppe', view().querySelectorAll('.contracts-table tr.contracts-table__group').length
    === new Set(hRows.map(r => r.group)).size);
  // Ablesungen einer Art mit Monatswerten: keine Blätterleiste
  const cnt = text(view().querySelector('[data-role="list-count"]'));
  t('Ablesungen: „N in Jahr · M gesamt“', new RegExp(`^\\d+ in \\d{4} · \\d+ gesamt$`).test(cnt), cnt);
  t('Ablesungen: kurze Liste ohne Blätterleiste', !view().querySelector('[data-role="list-card"] nav.pager'));
  t('Ablesungen: Jahreswahl in der Karte mit „Alle Jahre“',
    [...(view().querySelector('[data-role="list-year"]')?.options || [])].some(o => o.value === 'all' && o.textContent === 'Alle Jahre'));
  // v3.6.0 (Paket abrechnung) — beide Wege nehmen die jüngste abgeschlossene
  // Abrechnungsperiode wie im Abrechnungsjahr: Der Haushalt hat Rechnungen je
  // Kalenderjahr, der Stichtag 01.04. passt nicht dazu — der Vorschlag folgt den
  // Rechnungen (bis v3.5: Periode nach dem Stichtag, quer zu den Rechnungen)
  const bpStrom = (await call('GET', '/api/utility/strom/meters/m_strom_haushalt/billing-periods')).periods;
  const doneStrom = bpStrom.filter(p => p.complete && !p.running && p.days > 0 && p.until < ymd);
  const checkPeriod = doneStrom.length ? { from: doneStrom.at(-1).from, until: doneStrom.at(-1).until } : stromPeriod;
  await settle();
  const bcHref = view().querySelector('a[href^="#/bill-check"]')?.getAttribute('href') || '';
  t('Verbrauchsart → Rechnung prüfen: jüngste abgeschlossene Abrechnungsperiode (Rechnungen vor Stichtag)',
    checkPeriod !== stromPeriod && bcHref.includes(`from=${checkPeriod.from}&until=${checkPeriod.until}`), `${bcHref} · Stichtag: ${stromPeriod.from}`);

  // ── Ablesungen mit Tageswerten: Blättern ──
  const W = `#/utility/wasser?meter=${encodeURIComponent(water.id)}`;
  // zum Blätterjahr: Die Seite öffnet im jüngsten Jahr mit Verbrauch (am 01.01. das Vorjahr),
  // die Jahreswahl der Karte stellt um
  const shownYear = () => Number(view().querySelector('.year-pills .pill.active')?.dataset.year);
  const openW = async () => {
    await go(W);
    if (shownYear() !== PY) await change(view().querySelector('[data-role="list-year"]'), String(PY));
  };
  await go(W);
  const range = () => text(view().querySelector('[data-role="list-card"] .pager__range'));
  const firstDate = () => text(view().querySelector('[data-role="list-body"] tbody tr td strong'));
  const rowCount = () => view().querySelectorAll('[data-role="list-body"] tbody tr').length;
  const nf = new Intl.NumberFormat('de-DE');
  const y0 = shownYear();
  t('Tageswerte: Anzahl im Jahr und gesamt', (y0 === Y || y0 === Y - 1)
    && text(view().querySelector('[data-role="list-count"]')) === `${perYear[y0] || 0} in ${y0} · ${N} gesamt`,
    text(view().querySelector('[data-role="list-count"]')));
  if (y0 !== PY) await change(view().querySelector('[data-role="list-year"]'), String(PY));
  t('Tageswerte: Blätterleiste ab 51 Zeilen, Seite 1 mit den neuesten 25',
    range() === `1–25 von ${nf.format(inPY)}` && rowCount() === 25 && firstDate() === newestPY,
    `${range()} · ${rowCount()} Zeilen · ${firstDate()} (Jahr ${PY})`);
  t('Tageswerte: Blätterleiste als nav mit Bezeichnung', !!view().querySelector('nav.pager[aria-label="Seiten der Liste"]'));
  view().querySelector('[data-pager-go="next"]').click();
  await settle();
  t('Weiter: 26–50', range() === `26–50 von ${nf.format(inPY)}`, range());
  await change(view().querySelector('[data-pager-size]'), '50');
  t('Seitengröße 50: gemerkt, oberste Zeile bleibt im Blick', w.localStorage.getItem('et-page-size:utility-readings') === '50'
    && range() === `1–50 von ${nf.format(inPY)}`, range());
  view().querySelector('[data-pager-go="next"]').click();
  await settle();
  // dasselbe Jahr noch einmal wählen zeichnet die Seite neu, wie nach dem Bearbeiten einer Zeile
  view().querySelector(`.year-pills .pill[data-year="${PY}"]`).click();
  await settle();
  t('Neu zeichnen (wie nach dem Bearbeiten): Seite bleibt', range() === `51–100 von ${nf.format(inPY)}`, range());
  await go('#/dashboard');
  await openW();
  t('Neuer Aufruf: Seite 1, Seitengröße gemerkt', range() === `1–50 von ${nf.format(inPY)}`, range());
  view().querySelector('[data-pager-go="next"]').click();
  await settle();
  await change(view().querySelector('[data-role="list-year"]'), String(OY));
  t('Jahreswechsel: zurück auf Seite 1', range() === `1–50 von ${nf.format(inOY)}`
    && text(view().querySelector('[data-role="list-count"]')) === `${inOY} in ${OY} · ${N} gesamt`, range());
  await change(view().querySelector('[data-role="list-year"]'), 'all');
  t(`„Alle Jahre“: alle ${N}, blätterbar`, range() === `1–50 von ${nf.format(N)}` && text(view().querySelector('[data-role="list-count"]')) === `${N} gesamt`, range());
  const flt = view().querySelector('[data-role="flagged-only"]');
  t('„Nur auffällige“ angeboten', !!flt && /Nur auffällige \(2\)/.test(text(flt.closest('label'))));
  flt.checked = true;
  flt.dispatchEvent(new w.Event('change', { bubbles: true }));
  await settle();
  t('„Nur auffällige“: die zwei Verdachtsfälle von Seite 7 und 8, ohne Blätterleiste',
    rowCount() === 2 && !view().querySelector('[data-role="list-card"] nav.pager')
      && [...view().querySelectorAll('[data-role="list-body"] tbody tr')].every(tr => tr.classList.contains('row--flagged')), `${rowCount()} Zeilen`);
  await change(view().querySelector('[data-role="list-year"]'), String(PY));
  t('Jahreswahl der Karte stellt die Seite um (Kacheln, Liste)', !!view().querySelector(`.year-pills .pill.active[data-year="${PY}"]`)
    && range() === `1–50 von ${nf.format(inPY)}`, range());

  // ── Rechnung prüfen ──
  const tableShown = () => !!view().querySelector('#bc-result table');
  await go('#/bill-check');
  const selUtil = view().querySelector('#bc-utility')?.value;
  t('Rechnung prüfen ohne Zeitraum: Tabelle ohne Klick', tableShown() && selUtil === 'strom', `Art ${selUtil}`);
  t('… mit der jüngsten abgeschlossenen Abrechnungsperiode wie die Verbrauchsart', view().querySelector('#bc-from')?.value === checkPeriod.from
    && view().querySelector('#bc-until')?.value === checkPeriod.until, `${view().querySelector('#bc-from')?.value} – ${view().querySelector('#bc-until')?.value}`);
  const lastRow = text(view().querySelector('#bc-result tbody tr:last-child td'));
  t('… bis einschließlich auch an der Schnittstelle (letzter Abschnitt endet am gewählten Tag)',
    lastRow.endsWith(new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(new Date(checkPeriod.until))), lastRow);
  t('… ein Zeitraum, „bis einschließlich“', /einschließlich/.test(text(view().querySelector('label[for="bc-until"]'))) && !view().querySelector('#bi-from, #bi-to'));
  t('… Zustand in der Adresse', w.location.hash.includes(`from=${checkPeriod.from}`) && w.location.hash.includes(`until=${checkPeriod.until}`), w.location.hash);
  const capture = view().querySelector('details[data-role="bills"]');
  t('„Rechnung des Versorgers erfassen (optional)“ eingeklappt unter dem Ergebnis, mit Formular',
    !!capture && !capture.open && /erfassen \(optional\)/.test(text(capture.querySelector('summary'))) && !!capture.querySelector('#bi-form')
      && (capture.compareDocumentPosition(view().querySelector('#bc-result')) & w.Node.DOCUMENT_POSITION_PRECEDING) !== 0
      && text(capture.querySelector('[data-role="bill-period"]')).includes(new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(new Date(checkPeriod.until))));
  // anderer Zeitraum (von beiden Zählern abgedeckt), dann Zähler wechseln: Zeitraum bleibt, neu gerechnet
  const dayIso = (n) => iso(new Date(Date.UTC(Y, today.getMonth(), today.getDate() - n)));
  const pick = { from: dayIso(100), until: dayIso(10) };
  view().querySelector('#bc-from').value = pick.from;
  view().querySelector('#bc-until').value = pick.until;
  view().querySelector('#bc-run').click();
  await settle();
  view().querySelector('#bc-result').innerHTML = '';
  await change(view().querySelector('#bc-meter'), 'm_strom_waermepumpe');
  t('Zähler wechseln: Zeitraum bleibt, neu gerechnet, Adresse folgt', tableShown() && view().querySelector('#bc-from').value === pick.from
    && w.location.hash.includes('meter=m_strom_waermepumpe') && w.location.hash.includes(`until=${pick.until}`), w.location.hash);
  await change(view().querySelector('#bc-utility'), 'wasser');
  t('Verbrauchsart wechseln: Zeitraum bleibt, neu gerechnet, Adresse folgt', tableShown()
    && view().querySelector('#bc-from')?.value === pick.from && view().querySelector('#bc-until')?.value === pick.until
    && /utility=wasser/.test(w.location.hash) && w.location.hash.includes(`from=${pick.from}`), w.location.hash);
  // v3.12.0 (M3b) — ein Zeitraum, der nicht zufällig der vorgewählte ist (2025 war es: Die Probe blieb grün)
  await go('#/bill-check?utility=strom&from=2024-01-01&to=2025-01-01');
  t('ältere Verweise (`to` ausschließlich) gelten weiter', view().querySelector('#bc-until')?.value === '2024-12-31' && tableShown(), view().querySelector('#bc-until')?.value);
} catch (e) {
  t('Test mit Beispielhaushalt', false, e.stack || e.message);
} finally {
  proc.kill();
  rmSync(dataDir, { recursive: true, force: true });
  console.log(`\nlists: ${pass} bestanden, ${fail} fehlgeschlagen`);
  process.exit(fail ? 1 : 0);
}
