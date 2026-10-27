// =====================================================================
// v3.4.0 (F1026) — Schaubilder, zweite Fassung, ohne Server.
//
// Energiefluss: Formel gegen tests/fixtures/energy-flows.json (dieselbe
// Datei prüft PvSummaryServiceTest auf der PHP-Seite), Bilanz, Geometrie
// R1–R5 über eine Fallmatrix in allen sieben Sprachen (R1 kein Text auf
// Text, R2 Text passt in seinen Knoten und berührt keinen fremden, R3 kein
// Text auf einer Linie, R4 keine Kreuzung außer der erklärten Brücke, R5
// alles im Bild), keine Hex-Farben und keine Emoji im Bild, „Bewegung
// reduzieren“, Hervorhebung, Jahr/Monat an Ort und Stelle.
// Zeitstrahl: gebaute Verträge (befristet, verlängert, unbefristet,
// verpasst, Preiserhöhung, Preisgarantie, Mindestlaufzeit, Folgevertrag)
// ohne Überlappung. Geld und Wetter: Extremwerte ohne Überlauf.
//
// Textbreiten sind geschätzt (Sans 0,56 em, Ziffern 0,6 em) mit 10 % Reserve.
//
// Aufruf: node tests/explainer.test.mjs
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

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; if (process.env.VERBOSE) console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const section = (s) => console.log(s);

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'DOMParser']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
global.fetch = async (url) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json$/);
  if (!m) return new Response('{}', { status: 404 });
  return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
};

const { initI18n, t: tr } = await import(`${JS}/lib/i18n.js`);
const { fmt } = await import(`${JS}/lib/format.js`);
const EX = await import(`${JS}/components/explainer.js`);
const EF = await import(`${JS}/components/energy-flow.js`);
const LANGS = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];

// ── Geometrie ───────────────────────────────────────────────────────────
const parse = (html) => { const d = document.createElement('div'); d.innerHTML = html; return d; };
function box(el) {
  const fs = Number(el.getAttribute('font-size'));
  const cls = el.getAttribute('class') || '';
  const mono = /\bef-val\b|\bex-value\b/.test(cls);
  const txt = el.textContent;
  const w = [...txt].length * fs * (mono ? 0.6 : 0.56) * 1.1;
  const x = Number(el.getAttribute('x')), y = Number(el.getAttribute('y'));
  const a = el.getAttribute('text-anchor') || 'start';
  const x0 = a === 'middle' ? x - w / 2 : a === 'end' ? x - w : x;
  return { x0, x1: x0 + w, y0: y - fs * 0.78, y1: y + fs * 0.22, txt, fs, el };
}
const ov = (a, b, p = 1) => a.x0 < b.x1 + p && b.x0 < a.x1 + p && a.y0 < b.y1 + p && b.y0 < a.y1 + p;
const viewBox = (svg) => svg.getAttribute('viewBox').split(/\s+/).map(Number);
function segX(p1, p2, p3, p4) {
  const d = (p2[0] - p1[0]) * (p4[1] - p3[1]) - (p2[1] - p1[1]) * (p4[0] - p3[0]);
  if (Math.abs(d) < 1e-9) return null;
  const ua = ((p3[0] - p1[0]) * (p4[1] - p3[1]) - (p3[1] - p1[1]) * (p4[0] - p3[0])) / d;
  const ub = ((p3[0] - p1[0]) * (p2[1] - p1[1]) - (p3[1] - p1[1]) * (p2[0] - p1[0])) / d;
  return ua >= 0 && ua <= 1 && ub >= 0 && ub <= 1 ? [p1[0] + ua * (p2[0] - p1[0]), p1[1] + ua * (p2[1] - p1[1])] : null;
}

/** R1–R5 am gezeichneten Energiefluss (aus dem DOM, nicht aus der Rechnung). */
function checkFlow(svg) {
  const out = [];
  const [, , W, H] = viewBox(svg);
  const nodes = [...svg.querySelectorAll('[data-node]')].map(g => {
    const c = g.querySelector('.ef-disc');
    return { k: g.dataset.node, x: +c.getAttribute('cx'), y: +c.getAttribute('cy'), r: +c.getAttribute('r') };
  });
  const byKey = Object.fromEntries(nodes.map(n => [n.k, n]));
  const paths = [...svg.querySelectorAll('.ef-flow')].filter(g => !(g.classList.contains('ef-flow--off') && g.classList.contains('ef-flow--bridge')))
    .map(g => ({ id: g.dataset.flow, bridge: g.classList.contains('ef-flow--bridge'), pts: EF.samplePath(g.querySelector('.ef-track').getAttribute('d')) }));
  const B = [...svg.querySelectorAll('text')].map(el => ({ ...box(el), owner: el.closest('[data-node]')?.dataset.node }));
  for (let i = 0; i < B.length; i++) for (let j = i + 1; j < B.length; j++) if (ov(B[i], B[j])) out.push(`R1 „${B[i].txt}“/„${B[j].txt}“`);
  for (const b of B) {
    const own = byKey[b.owner];
    const inside = own && Math.hypot((b.x0 + b.x1) / 2 - own.x, (b.y0 + b.y1) / 2 - own.y) < own.r;
    if (inside) {
      const dy = Math.max(Math.abs(b.y0 - own.y), Math.abs(b.y1 - own.y));
      const chord = 2 * Math.sqrt(Math.max(0, (own.r - 3) ** 2 - dy ** 2));
      if (b.x1 - b.x0 > chord) out.push(`R2 „${b.txt}“ breiter als ${own.k} (${Math.round(b.x1 - b.x0)} > ${Math.round(chord)})`);
    }
    for (const c of nodes) {
      if (c.k === b.owner) continue;
      const cx = Math.max(b.x0, Math.min(c.x, b.x1)), cy = Math.max(b.y0, Math.min(c.y, b.y1));
      if (Math.hypot(cx - c.x, cy - c.y) < c.r + 6) out.push(`R2 „${b.txt}“ berührt ${c.k}`);
    }
    if (!inside) for (const p of paths) {
      if (p.pts.some(([x, y]) => x > b.x0 - 3 && x < b.x1 + 3 && y > b.y0 - 3 && y < b.y1 + 3)) out.push(`R3 „${b.txt}“ auf ${p.id}`);
    }
    if (b.x0 < 2 || b.x1 > W - 2 || b.y0 < 2 || b.y1 > H - 2) out.push(`R5 „${b.txt}“ ragt hinaus`);
  }
  for (let i = 0; i < paths.length; i++) for (let j = i + 1; j < paths.length; j++) {
    const a = paths[i].pts, b = paths[j].pts;
    let hit = null;
    for (let s = 0; s + 1 < a.length && !hit; s++) for (let u = 0; u + 1 < b.length && !hit; u++) {
      const p = segX(a[s], a[s + 1], b[u], b[u + 1]);
      if (p && !nodes.some(c => Math.hypot(p[0] - c.x, p[1] - c.y) < c.r + 8)) hit = p;
    }
    if (!hit) continue;
    const ids = [paths[i].id, paths[j].id].sort().join('×');
    // erklärt ist allein die Brücke Netz → Speicher über Sonne → Haus
    if (!(ids === 'grid_battery×pv_house' && (paths[i].bridge || paths[j].bridge))) out.push(`R4 ${ids} bei ${hit.map(Math.round)}`);
  }
  for (const c of nodes) if (c.x - c.r - 7 < 0 || c.x + c.r + 7 > W || c.y - c.r - 7 < 0 || c.y + c.r + 7 > H) out.push(`R5 Knoten ${c.k}`);
  return out;
}

const HEX = /#[0-9a-f]{3}(?:[0-9a-f]{3})?(?:[0-9a-f]{2})?(?![\w-])/i;

// ── 1. Formel: eine Rechnung, zwei Stellen ──────────────────────────────
section('Energiefluss: Formel (tests/fixtures/energy-flows.json)');
const fixture = JSON.parse(readFileSync(resolve(ROOT, 'tests', 'fixtures', 'energy-flows.json'), 'utf8'));
for (const c of fixture.cases) {
  const f = EF.flowsFromTotals(c.in);
  const bad = Object.entries(c.out).filter(([k, v]) => (v === null ? f[k] !== null : Math.abs(f[k] - v) > (k === 'autarky' ? 1e-4 : 0.05)));
  t(`Formel: ${c.name}`, !bad.length, bad.map(([k, v]) => `${k} ${f[k]} statt ${v}`).join(', '));
}

// ── 2. Fallmatrix ───────────────────────────────────────────────────────
const CASES = {
  'Speicher, Wärmepumpe, Wallbox': [{ gen: 9187.5, feed: 5145.8, grid: 6298.2, charged: 1944.8, discharged: 1789.3 }, { heat_pump: 3896.0, ev_charger: 2612.7 }],
  'Speicher ohne Geräte': [{ gen: 9187.5, feed: 5145.8, grid: 6298.2, charged: 1944.8, discharged: 1789.3 }, {}],
  'ohne Speicher, Wärmepumpe': [{ gen: 6500, feed: 4200, grid: 3800 }, { heat_pump: 2500 }],
  'ohne Speicher, ohne Geräte': [{ gen: 6500, feed: 4200, grid: 3800 }, {}],
  'Balkon ohne Einspeisung': [{ gen: 600, feed: 0, grid: 2400 }, {}],
  'autark ohne Bezug': [{ gen: 14000, feed: 9000, grid: 0, charged: 2500, discharged: 2300 }, { ev_charger: 1200 }],
  'groß, fünfstellig': [{ gen: 48000, feed: 31000, grid: 22000, charged: 12000, discharged: 11000 }, { heat_pump: 9000, ev_charger: 7000 }],
  'Gewerbe, MWh': [{ gen: 160000, feed: 98000, grid: 64000, charged: 30000, discharged: 27500 }, { heat_pump: 21000, ev_charger: 18000 }],
  'Netzladung im Winter': [{ gen: 180, feed: 10, grid: 900, charged: 260, discharged: 240 }, { heat_pump: 520 }],
  'knapp unter 100.000 kWh': [{ gen: 99999, feed: 50000, grid: 99000, charged: 30000, discharged: 28000 }, { heat_pump: 21000, ev_charger: 15000 }],
  'Wintermonat, kaum Sonne': [{ gen: 12, feed: 0, grid: 850, charged: 5, discharged: 4 }, { heat_pump: 600, ev_charger: 120 }],
  'nur Wallbox mit Speicher': [{ gen: 5200, feed: 2100, grid: 1900, charged: 900, discharged: 820 }, { ev_charger: 1500 }],
};
const flowFor = ([tot, dev]) => ({ ...EF.flowsFromTotals(tot), heat_pump: dev.heat_pump ?? null, ev_charger: dev.ev_charger ?? null });

section('Energiefluss: Bilanz');
for (const [name, c] of Object.entries(CASES)) {
  const f = flowFor(c);
  const [tot] = c;
  const pvOut = f.pv_house + f.pv_battery + f.pv_grid;
  const inflow = f.pv_house + f.battery_house + f.grid_house;
  const m = EF.flowModel(f, EF.nodeSet(f));
  t(`Bilanz ${name}: Sonne raus = Erzeugung`, Math.abs(pvOut - Math.max(tot.gen, 0)) < 0.15, `${pvOut} / ${tot.gen}`);
  t(`Bilanz ${name}: Haus = Summe der Zuflüsse`, Math.abs(f.house - inflow) < 0.15 && Math.abs(m.house - inflow) < 0.15);
  t(`Bilanz ${name}: geladen = Sonne + Netz → Speicher`, Math.abs(f.pv_battery + f.grid_battery - (tot.charged || 0)) < 0.15);
  t(`Bilanz ${name}: Bezug = Netz → Haus + Netz → Speicher`, Math.abs(f.grid_house + f.grid_battery - tot.grid) < 0.15);
  t(`Bilanz ${name}: Hausring Netzanteil = 1 − Autarkie`, f.autarky == null || Math.abs(m.gridIn / m.house - (1 - m.autarky)) < 1e-3);
}

section('Energiefluss: Geometrie R1–R5 × 7 Sprachen, Farben, Symbole');
const css = readFileSync(resolve(ROOT, 'public', 'css', 'components.css'), 'utf8');
for (const lang of LANGS) {
  await initI18n(lang);
  for (const [name, c] of Object.entries(CASES)) {
    const f = flowFor(c);
    const html = EX.energyHtml({ year: 2025, flow: f, battery: c[0].charged ? {} : null });
    const fig = parse(html);
    const svg = fig.querySelector('svg.ef');
    if (!svg) { t(`[${lang}] ${name}: Bild`, false, 'kein Bild'); continue; }
    const iss = checkFlow(svg);
    t(`[${lang}] ${name}: R1–R5`, !iss.length, iss.slice(0, 4).join(' | '));
    if (lang === 'de') {
      t(`${name}: keine Hex-Farbe im Bild`, !HEX.test(html), (html.match(HEX) || [])[0]);
      t(`${name}: keine Emoji im Bild`, !/\p{Extended_Pictographic}/u.test(svg.outerHTML));
      t(`${name}: Grafik aria-hidden, Flüsse als Liste`, svg.getAttribute('aria-hidden') === 'true'
        && fig.querySelectorAll('.ef-list li').length === [...svg.querySelectorAll('.ef-flow:not(.ef-flow--off)')].length);
      t(`${name}: jeder Fluss hat einen Pfeil für „Bewegung reduzieren“`,
        [...svg.querySelectorAll('.ef-flow:not(.ef-flow--off)')].every(g => g.querySelector('.ef-arrow') && g.querySelectorAll('.ef-dot').length >= 1));
      const ringC = svg.querySelector('[data-k="ring-house-2"]');
      if (f.autarky != null && ringC) {
        const r = Number(ringC.getAttribute('r')), C = 2 * Math.PI * r;
        const len = Number(/stroke-dasharray:([\d.]+)/.exec(ringC.getAttribute('style'))[1]);
        t(`${name}: Hausring Netz = 1 − Autarkie`, Math.abs((len + (len > 0 ? Math.min(1.6, len) : 0)) / C - (1 - f.autarky)) < 0.02, `${(len / C).toFixed(3)} / ${(1 - f.autarky).toFixed(3)}`);
      }
    }
  }
}
// v3.5.0 (F1027) — Tagesfilm: dieselbe Anordnung, Werte in kW mit einer
// Stelle; auch große Anlagen und die Lücke („–“) ohne Überlappung
section('Tagesfilm: Geometrie R1–R5 in kW × 7 Sprachen');
const DAYCASES = {
  'Mittag mit Speicher': { gen: 2.4, feed: 0.9, grid: 0, charged: 0.6, discharged: 0 },
  'Nacht mit Netzladung': { gen: 0, feed: 0, grid: 1.1, charged: 0.8, discharged: 0 },
  'Abend aus dem Speicher': { gen: 0, feed: 0, grid: 0.1, charged: 0, discharged: 0.5 },
  'große Anlage, 99,9 kW': { gen: 24.975, feed: 18, grid: 3, charged: 2, discharged: 0 },
};
for (const lang of LANGS) {
  await initI18n(lang);
  const set = { bat: true, gridBat: true, dev: [] };
  const L = EF.flowLayout(set);
  for (const [name, v] of [...Object.entries(DAYCASES), ['Lücke', null]]) {
    const kw = v ? EF.flowsFromTotals(Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x * 4]))) : {};
    const m = Object.assign(EF.flowModel(kw, set), { power: true, gap: !v, scaleMax: 100, minOn: 0.01 });
    const svg = parse(EF.flowSvg(m, L, 'day').svg).querySelector('svg.ef');
    const iss = checkFlow(svg);
    t(`[${lang}] Tag ${name}: R1–R5`, !iss.length, iss.slice(0, 4).join(' | '));
    if (lang === 'de' && !v) t('Lücke: „–“ in allen Knoten, kein Fluss an', [...svg.querySelectorAll('[data-k^="v-"]')].every(e => e.textContent.replace(/[↓↑]/, '') === '–')
      && !svg.querySelector('.ef-flow:not(.ef-flow--off)'));
  }
}
await initI18n('de');
t('Netzladung: Brücke gezeichnet und im Text erklärt', (() => {
  const fig = parse(EX.energyHtml({ year: 2025, flow: flowFor(CASES['Netzladung im Winter']), battery: {} }));
  return !!fig.querySelector('.ef-flow--bridge:not(.ef-flow--off) .ef-bridge') && /Bogen/.test(fig.querySelector('.explainer__text').textContent);
})());
t('Sonne → Speicher als „rechnerisch“ gekennzeichnet (gestrichelt, Liste, Hinweis)', (() => {
  const fig = parse(EX.energyHtml({ year: 2025, flow: flowFor(CASES['Speicher ohne Geräte']), battery: {} }));
  return !!fig.querySelector('[data-flow="pv_battery"] .ef-calc') && /rechnerisch/.test(fig.querySelector('.ef-list').textContent)
    && !fig.querySelector('.ef-note').hidden;
})());
t('Kennzahl vom Server: Hausknopf zeigt flow.autarky (eine Quelle)', (() => {
  const f = { ...flowFor(CASES['Speicher, Wärmepumpe, Wallbox']), autarky: 0.42 };
  return parse(EX.energyHtml({ year: 2025, flow: f })).querySelector('.ef-chip[data-node="house"]').textContent.includes(fmt.pct(0.42, 0));
})());
t('Punkte mit konstantem Tempo (Dauer ∝ Weglänge), Dichte ∝ Menge', (() => {
  const svg = parse(EX.energyHtml({ year: 2025, flow: flowFor(CASES['Speicher, Wärmepumpe, Wallbox']), battery: {} })).querySelector('svg.ef');
  const flows = [...svg.querySelectorAll('.ef-flow:not(.ef-flow--off)')].map(g => {
    const len = Number(/--len:([\d.]+)/.exec(g.querySelector('.ef-band').getAttribute('style'))[1]);
    const dur = Number.parseFloat(g.querySelector('animateMotion').getAttribute('dur'));
    const width = Number(/stroke-width:([\d.]+)/.exec(g.querySelector('.ef-band').getAttribute('style'))[1]);
    return { speed: len / dur, density: g.querySelectorAll('.ef-dot').length / len, width };
  });
  const speeds = flows.map(f => f.speed);
  const big = flows.reduce((a, b) => (b.width > a.width ? b : a)), small = flows.reduce((a, b) => (b.width < a.width ? b : a));
  return Math.max(...speeds) - Math.min(...speeds) < 0.5 && big.density > small.density;
})());
t('Ältere Jahreszeile ohne flow: Bild aus den Mengen, Speicherverluste nicht im Haus', (() => {
  const fig = parse(EX.energyHtml({ year: 2025, erzeugung_kwh: 9187.5, einspeisung_kwh: 5145.8, eigenverbrauch_kwh: 4041.7, bezug_kwh: 6298.2,
    battery: { charged_kwh: 1944.8, discharged_kwh: 1789.3 } }));
  return fig.querySelector('[data-k="v-house"]')?.getAttribute('data-count') === '10184.4' && !fig.querySelector('.ef-period');
})());
t('Ohne Erzeugung kein Bild', EX.energyHtml({ year: 2025, flow: EF.flowsFromTotals({ gen: 0, grid: 500 }) }) === '');
t('CSS: „Bewegung reduzieren“ blendet Punkte aus und Pfeile ein',
  /@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*\.ef-dots \{ display: none; \}[^@]*\.ef-arrow \{ display: inline; \}/.test(css));
t('CSS: Farben aus der Utilities-SSOT mit Rückfall für Wärme', /\.ef-c-hp\s*\{ --c: var\(--util-waerme, var\(--/.test(css) && /\.ef-c-pv\s*\{ --c: var\(--util-pv_erzeugung,/.test(css));
t('Ringe mit absoluten Längen (kein pathLength auf Kreisen)', !/<circle[^>]*pathLength/.test(EX.energyHtml({ year: 2025, flow: flowFor(CASES['Speicher ohne Geräte']) })));

// ── 3. Bedienung: Hervorhebung, Zeitraum, Bewegung ──────────────────────
section('Energiefluss: Bedienung');
const month = (y, mo, gen, feed, grid, charged, discharged, hp, ev) => ({
  ym: `${y}-${String(mo).padStart(2, '0')}`, year: y, month: mo, covered: true,
  erzeugung_kwh: gen, einspeisung_kwh: feed, bezug_kwh: grid, battery: { charged_kwh: charged, discharged_kwh: discharged },
  heat_pump_kwh: hp, ev_charger_kwh: ev,
  flow: { ...EF.flowsFromTotals({ gen, feed, grid, charged, discharged }), heat_pump: hp, ev_charger: ev },
});
const monthly = [];
for (const y of [2024, 2025]) for (let mo = 1; mo <= 12; mo++) {
  const sun = Math.max(0.15, Math.sin((mo - 0.5) / 12 * Math.PI));
  monthly.push(month(y, mo, Math.round(1200 * sun), Math.round(700 * sun), Math.round(900 - 500 * sun), Math.round(200 * sun), Math.round(185 * sun), Math.round(500 - 400 * sun), 200));
}
const sumFlow = (rows) => {
  const acc = {};
  rows.forEach(r => Object.entries(r.flow).forEach(([k, v]) => { if (k !== 'autarky' && v != null) acc[k] = (acc[k] || 0) + v; }));
  acc.autarky = 1 - acc.grid_in_house / acc.house; acc.months = rows.length;
  return acc;
};
const summary = { monthly, yearly: [2024, 2025].map(y => ({ year: y, months_covered: 12, battery: {}, flow: sumFlow(monthly.filter(m => m.year === y)) })) };
document.body.innerHTML = `<div id="root">${EX.energyHtml(summary, { year: 2025 })}</div>`;
let fig = document.querySelector('.ex-energy');
window.matchMedia = () => ({ matches: false });
EX.playExplainers(document.getElementById('root'));
const label = () => fig.querySelector('.ef-period__label').textContent;
t('Start im gewünschten Jahr', label() === '2025', label());
const svg0 = fig.querySelector('svg.ef');
const pvChip = fig.querySelector('.ef-chip[data-node="pv"]');
pvChip.click();
t('Knopf Sonne: aria-pressed, Bild fokussiert', pvChip.getAttribute('aria-pressed') === 'true' && svg0.getAttribute('data-focus') === 'pv');
t('… nur die Flüsse der Sonne hervorgehoben', [...svg0.querySelectorAll('.ef-flow')].every(g => g.classList.contains('ef-on') === (g.dataset.from === 'pv')));
const detail = fig.querySelector('.ef-detail');
t('… Detailzeile (aria-live) nennt die Flüsse', detail.getAttribute('aria-live') === 'polite' && /Sonne → Haus/.test(detail.textContent) && !/Netz → Haus/.test(detail.textContent), detail.textContent);
fig.querySelector('.ef-chip[data-node="pv"]').click();
t('… zweites Tippen hebt auf', !svg0.hasAttribute('data-focus') && detail.textContent === '');
svg0.querySelector('[data-node="grid"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
t('Tippen auf den Knoten Netz im Bild', svg0.getAttribute('data-focus') === 'grid' && fig.querySelector('.ef-chip[data-node="grid"]').getAttribute('aria-pressed') === 'true');
const houseBefore = svg0.querySelector('[data-k="v-house"]').getAttribute('data-count');
fig.querySelector('[data-ef-scope="month"]').click();
const svg1 = fig.querySelector('svg.ef');
t('Monat: letzter Monat des Jahres, Bild an Ort und Stelle (dasselbe Element)', svg1 === svg0 && /2025/.test(label()) && label() !== '2025', label());
t('… Werte des Monats', svg1.querySelector('[data-k="v-house"]').getAttribute('data-count') === String(Math.round(monthly[23].flow.house * 10) / 10) && houseBefore !== svg1.querySelector('[data-k="v-house"]').getAttribute('data-count'));
t('… Hervorhebung bleibt', svg1.getAttribute('data-focus') === 'grid');
const l1 = label();
fig.querySelector('[data-ef-step="-1"]').click();
t('… Schritt zurück: Vormonat', label() !== l1 && fig.querySelector('.explainer__text').textContent.startsWith(label()), label());
fig.querySelector('[data-ef-scope="year"]').click();
fig.querySelector('[data-ef-step="-1"]').click();
t('Jahr: Schritt zurück auf 2024, nächster Schritt möglich', label() === '2024' && fig.querySelector('[data-ef-step="-1"]').disabled && !fig.querySelector('[data-ef-step="1"]').disabled);
t('Zeitraum-Knöpfe mit aria-pressed', fig.querySelector('[data-ef-scope="year"]').getAttribute('aria-pressed') === 'true'
  && fig.querySelector('[data-ef-scope="month"]').getAttribute('aria-pressed') === 'false');
t('Ohne Skript und Bewegung: Endwerte stehen im Bild', /^\d/.test(fig.querySelector('[data-k="v-pv"]').textContent));

// Bewegung reduzieren: Endbild sofort, SMIL angehalten
document.body.innerHTML = `<div id="root2">${EX.energyHtml(summary, { year: 2025 })}</div>`;
fig = document.querySelector('.ex-energy');
let paused = 0;
fig.querySelector('svg.ef').pauseAnimations = () => { paused++; };
window.matchMedia = (q) => ({ matches: /reduce/.test(q) });
const final = fig.querySelector('[data-k="v-house"]').textContent;
EX.playExplainers(document.getElementById('root2'));
t('Bewegung reduzieren: sofort Endbild, SMIL angehalten, kein Hochzählen', fig.classList.contains('ex--play') && paused === 1
  && fig.querySelector('[data-k="v-house"]').textContent === final);
window.matchMedia = () => ({ matches: false });

// Hochzählen (ohne requestAnimationFrame im Test: nach dem Start der Endwert)
document.body.innerHTML = `<div id="root3">${EX.energyHtml(summary, { year: 2025 })}</div>`;
fig = document.querySelector('.ex-energy');
const endValue = fig.querySelector('[data-k="v-pv"]').textContent;
EX.playExplainers(document.getElementById('root3'));
const atStart = fig.querySelector('[data-k="v-pv"]').textContent;
await new Promise(r => setTimeout(r, 700));
t('Zahlen zählen hoch (Start bei 0, dann Endwert)', atStart === fmt.int(0) && fig.querySelector('[data-k="v-pv"]').textContent === endValue, `${atStart} → ${fig.querySelector('[data-k="v-pv"]').textContent}`);

// ── 3b. v3.9.0 (V4): geschätzt, widersprüchlich, erfasster Zeitraum ──────
// Der Changelog 3.7.0 versprach „markiert die Monate im Energiefluss“; bis
// v3.8 galt das nur für das Säulendiagramm der PV-Seite. Hier das Schaubild
// „Energiefluss im Haus“ selbst (Übersicht, PV-Seite, Jahresbericht).
section('Energiefluss: geschätzt, widersprüchlich, Zeitraum (V4)');
{
  const jun = { ...month(2025, 6, 172, 196, 201.5, 0, 0, null, null), interpolated: true, interpolated_series: ['erzeugung_kwh', 'bezug_kwh'], feed_above_generation: true };
  const may = { ...month(2025, 5, 177.7, 158.2, 208.2, 0, 0, null, null), interpolated: true, interpolated_series: ['erzeugung_kwh', 'bezug_kwh'], feed_above_generation: false };
  const meas = { ...month(2025, 4, 172, 111.2, 201.5, 0, 0, null, null), interpolated: false, interpolated_series: [], feed_above_generation: false };
  const flowY = sumFlow([meas, may, jun]);
  const est = {
    interpolation: { utility: 'pv_erzeugung', readings: 2, from: '2023-05', to: '2025-08' },
    monthly: [meas, may, jun],
    yearly: [{ year: 2025, months_covered: 3, flow: flowY,
      flow_period: { from: '2025-04', to: '2025-06', months: 3, interpolated_months: 2, interpolated_series: ['erzeugung_kwh', 'bezug_kwh'],
        feed_above_generation: ['2025-06'], partial: ['2025-08'] } }],
  };
  // die gemessene Einspeisung bleibt: Juni 196, nicht 172
  t('V4: Formel kappt die Einspeisung nicht (Juni 196 > 172)', jun.flow.pv_grid === 196 && jun.flow.pv_house === 0);
  document.body.innerHTML = `<div id="root4">${EX.energyHtml(est, { year: 2025 })}</div>`;
  fig = document.querySelector('.ex-energy');
  EX.playExplainers(document.getElementById('root4'));
  const lab = () => fig.querySelector('.ef-period__label').textContent;
  const note = () => fig.querySelector('.ef-note');
  t('V4: Jahr nennt den erfassten Zeitraum und ≈', lab() === `≈ ${tr('explainer.energy.yearRange', { year: 2025, from: 'April', to: 'Juni', span: '3 Monate' })}`, lab());
  t('V4: Jahr: Hinweis verteilt (mit Ablesungen), widersprüchlich, Teilmonat', !note().hidden
    && note().textContent.includes('Verteilt, nicht gemessen: Erzeugung und Netzbezug dieser Monate (2 Ablesungen von Mai 2023 bis Aug. 2025)')
    && note().textContent.includes('Widersprüchlich in Juni 2025') && note().textContent.includes('Ohne August 2025'), note().textContent);
  t('V4: Satz und Knöpfe tragen ≈ an geschätzten Werten', /Die Sonne lieferte ≈ /.test(fig.querySelector('.explainer__text').textContent)
    && /Autarkie: ≈ /.test(fig.querySelector('.explainer__text').textContent)
    && fig.querySelector('.ef-chip[data-node="pv"]').textContent.startsWith('Sonne: ≈ ')
    && fig.querySelector('.ef-chip[data-node="grid"]').textContent.includes('≈ '), fig.querySelector('.explainer__text').textContent);
  fig.querySelector('[data-ef-scope="month"]').click();
  t('V4: Monat Juni: ≈ und Widerspruch mit den Zahlen', lab().startsWith('≈ ') && note().textContent.includes(tr('explainer.energy.feedAboveMonth', { feed: fmt.unit(196, 'kWh'), gen: fmt.unit(172, 'kWh') })), `${lab()} | ${note().textContent}`);
  fig.querySelector('[data-ef-step="-1"]').click();
  fig.querySelector('[data-ef-step="-1"]').click();
  t('V4: gemessener Monat ohne ≈ und ohne Hinweis', !lab().startsWith('≈') && note().hidden && !/≈/.test(fig.querySelector('.explainer__text').textContent), `${lab()} | ${note().textContent}`);
  // gemessenes Jahr ohne Zeitraumangabe (die Demo bleibt, wie sie war)
  const plain = parse(EX.energyHtml(summary, { year: 2025 }));
  t('V4: gemessenes ganzes Jahr ohne ≈ und ohne Hinweis zur Schätzung', !/≈/.test(plain.textContent) && !/Verteilt|Widersprüchlich|Ohne /.test(plain.querySelector('.ef-note').textContent));
}

// ── 4. Zeitstrahl ───────────────────────────────────────────────────────
section('Zeitstrahl');
const TODAY = '2026-10-12';
const TL = {
  'befristet, 1 Monat Frist': { start: '2025-01-01', end: '2026-12-31', is_open_ended: false, notice_basis: 'fixed_end', cancel_by: '2026-11-30', switch_date: '2027-01-01', auto_renews: true },
  'befristet + Preiserhöhung': { start: '2025-01-01', end: '2026-12-31', is_open_ended: false, notice_basis: 'fixed_end', cancel_by: '2026-11-30', switch_date: '2027-01-01', price_increase: { from: '2026-11-01' } },
  'befristet, 6 Wochen + Preiserhöhung Dezember': { start: '2026-01-01', end: '2026-12-31', notice_basis: 'fixed_end', cancel_by: '2026-11-19', switch_date: '2027-01-01', price_increase: { from: '2026-12-01' } },
  'Stichtag verpasst': { start: '2025-01-01', end: '2026-10-31', notice_basis: 'fixed_end', cancel_by: '2026-09-30', switch_date: '2026-11-01', cancel_missed: true },
  'verlängert (renewed)': { start: '2024-07-01', end: '2026-06-30', renewed: true, notice_basis: 'renewed', cancel_by: TODAY, switch_date: '2026-11-13', is_open_ended: false },
  'unbefristet, 1 Monat zum Monatsende': { start: '2022-11-01', end: null, is_open_ended: true, notice_basis: 'open_ended', cancel_by: TODAY, switch_date: '2026-12-01' },
  'unbefristet mit Mindestlaufzeit': { start: '2026-03-01', end: null, is_open_ended: true, notice_basis: 'min_term', cancel_by: TODAY, switch_date: '2027-03-01', min_term_end: '2027-02-28' },
  'unbefristet ohne Frist + Preiserhöhung': { start: '2020-01-01', end: null, is_open_ended: true, notice_basis: 'unknown', cancel_by: null, switch_date: null, price_increase: { from: '2026-11-01' } },
  'neu seit gestern, zwei Jahre': { start: '2026-10-11', end: '2028-10-10', notice_basis: 'fixed_end', cancel_by: '2028-09-10', switch_date: '2028-10-11' },
  'verlängert sich nicht': { start: '2025-06-01', end: '2026-11-30', auto_renews: false, notice_basis: 'fixed_end', cancel_by: '2026-10-31', switch_date: '2026-12-01' },
  'Preisgarantie, Mindestlaufzeit, Folgevertrag mit Lücke': { start: '2025-12-01', end: '2027-11-30', notice_basis: 'fixed_end', cancel_by: '2027-10-31', switch_date: '2027-12-01',
    min_term_end: '2026-11-30', price_guarantee_until: '2026-12-31', successor: { contract_id: 'c_next', provider: 'Stadtwerk Beispiel', start: '2027-12-15', gap_days: 14 } },
  'Folgevertrag nahtlos, ohne Anbieter': { start: '2025-11-01', end: '2026-10-31', notice_basis: 'fixed_end', cancel_by: '2026-09-30', switch_date: '2026-11-01',
    successor: { contract_id: 'c_n', provider: '', start: '2026-11-01', gap_days: 0 } },
  'alles dicht um heute': { start: '2026-10-01', end: '2026-11-30', notice_basis: 'fixed_end', cancel_by: '2026-11-15', switch_date: '2026-12-01', min_term_end: '2026-11-01',
    price_guarantee_until: '2026-10-20', price_increase: { from: '2026-10-25' }, successor: { contract_id: 'c_d', provider: 'X', start: '2026-12-01', gap_days: 0 } },
  'EEG-artig, 20 Jahre': { start: '2023-04-01', end: '2043-03-31', notice_basis: 'fixed_end', cancel_by: '2043-02-28', switch_date: '2043-04-01' },
};
for (const lang of LANGS) {
  await initI18n(lang);
  for (const [name, row] of Object.entries(TL)) {
    const fig = parse(EX.timelineHtml(row, TODAY));
    const svg = fig.querySelector('svg');
    if (!svg) { t(`[${lang}] ${name}: Bild`, false); continue; }
    const [, , W, H] = viewBox(svg);
    const B = [...svg.querySelectorAll('text')].map(box);
    const iss = [];
    for (let i = 0; i < B.length; i++) for (let j = i + 1; j < B.length; j++) if (ov(B[i], B[j])) iss.push(`„${B[i].txt}“↔„${B[j].txt}“`);
    for (const b of B) if (b.x0 < 0 || b.x1 > W || b.y0 < 0 || b.y1 > H) iss.push(`„${b.txt}“ ragt hinaus`);
    t(`[${lang}] Zeitstrahl ${name}: keine Überlappung, alles im Bild`, !iss.length, iss.slice(0, 3).join('; '));
  }
}
await initI18n('de');
const tl = (name) => parse(EX.timelineHtml(TL[name], TODAY));
const txt = (fig) => fig.querySelector('.explainer__text').textContent;
const svgTxt = (fig) => fig.querySelector('svg').textContent;
let f1 = tl('verlängert (renewed)');
t('verlängert: frühestes Ende = switch_date − 1, nicht das alte Ende', txt(f1).includes('12.11.2026') && fig1Has(f1, 'earliest') && !/kündigt bis 12\.10\.2026/.test(txt(f1)), txt(f1));
function fig1Has(fig, key) { return !!fig.querySelector(`.ex-mark--${key}`); }
t('verlängert: Laufzeitende als eigene Marke, „jederzeit kündbar“ statt Stichtag', fig1Has(f1, 'termEnd') && !fig1Has(f1, 'cancel') && svgTxt(f1).includes('jederzeit kündbar'));
f1 = tl('unbefristet, 1 Monat zum Monatsende');
t('unbefristet: jederzeit kündbar, frühestes Ende 30.11.2026, kein Stichtag heute', !fig1Has(f1, 'cancel') && txt(f1).includes('30.11.2026') && /jederzeit kündbar/.test(txt(f1)) && !txt(f1).includes('12.10.2026'), txt(f1));
f1 = tl('Stichtag verpasst');
t('Stichtag verpasst: eigener Text und Marke', fig1Has(f1, 'missed') && /Frist zum Kündigen lief am 30\.09\.2026 ab/.test(txt(f1)) && !/kündigt bis/.test(txt(f1)), txt(f1));
f1 = tl('verlängert sich nicht');
t('verlängert sich nicht: kein Stichtag, endet ohne Kündigung', !fig1Has(f1, 'cancel') && /ohne dass du kündigen musst/.test(txt(f1)));
f1 = tl('Preisgarantie, Mindestlaufzeit, Folgevertrag mit Lücke');
t('Preisgarantie: Marke mit Datum, Band und Satz', fig1Has(f1, 'guarantee') && svgTxt(f1).includes('Preisgarantie bis')
  && !!f1.querySelector('.ex-range--guarantee') && txt(f1).includes('bis 31.12.2026 garantiert'));
t('Mindestlaufzeit: Marke und Satz', fig1Has(f1, 'minTerm') && txt(f1).includes('Mindestlaufzeit endet am 30.11.2026'));
t('Folgevertrag (successor): Marke, Strecke, Anbieter, Lücke in Tagen', fig1Has(f1, 'successor') && !!f1.querySelector('.ex-range--next') && !!f1.querySelector('.ex-range--gap')
  && txt(f1).includes('Stadtwerk Beispiel') && txt(f1).includes('14 Tage ohne Vertrag'), txt(f1));
t('ohne successor kein Folgevertrag', !fig1Has(tl('befristet, 1 Monat Frist'), 'successor'));
f1 = tl('Folgevertrag nahtlos, ohne Anbieter');
t('Folgevertrag ohne Anbieter und ohne Lücke', fig1Has(f1, 'successor') && !f1.querySelector('.ex-range--gap') && /folgt ein neuer Vertrag/.test(txt(f1)));
f1 = tl('unbefristet ohne Frist + Preiserhöhung');
t('Fenster um heute: alter Beginn sitzt am Rand', !!f1.querySelector('.ex-mark--start.ex-mark--clip') && !!f1.querySelector('.ex-axis--cut') && /steigen die Preise/.test(txt(f1)));
f1 = tl('EEG-artig, 20 Jahre');
const todayX = Number(f1.querySelector('.ex-today line').getAttribute('x1'));
t('lange Laufzeit: heute nicht an den Rand gedrückt', todayX > 60 && todayX < 260, String(todayX));
t('heute ohne Angabe = Ortszeit (todayIso)', EX.timelineHtml(TL['befristet, 1 Monat Frist']).includes('ex-today'));

// ── 5. Geld und Wetter mit Extremwerten ─────────────────────────────────
section('Geld und Wetter');
const money = (paid, cost, energy, base, bal, range = null) => EX.moneyHtml({
  balance_path: [{ ym: '2026-01', paid: paid / 12, cost: cost / 12 }, { ym: '2026-12', paid, cost }],
  energy_cost_to_date: energy, base_to_date: base, bonus_to_date: 0, projected_end_balance: bal,
  projection_uncertain: range ? { reasons: ['few_measured'], projected_range: range } : null,
});
const wm = (y, mo, kwh, hdd) => ({ ym: `${y}-${String(mo).padStart(2, '0')}`, kwh, hdd, heat_adjusted: kwh });
const weather = (k0, k1, h0, h1, adjF) => {
  const r = [];
  for (let i = 1; i <= 12; i++) r.push(wm(2025, i, k0, h0));
  for (let i = 1; i <= 12; i++) r.push({ ...wm(2026, i, k1, h1), heat_adjusted: k0 * adjF });
  return EX.weatherHtml(r);
};
const EXTREME = {
  'Geld groß, Guthaben': () => money(123456.78, 98765.43, 80000, 15000, -24691.35),
  'Geld groß, Nachzahlung': () => money(9800, 14321.09, 12000, 2000, 4521.09),
  'Geld klein': () => money(12, 9.5, 6, 2, -2.5),
  'Geld ohne Abschläge (0 €)': () => money(0, 1500, 1200, 300, 1500),
  // v3.7.0 (Schritt 3) — unsichere Schätzung: Kosten als Spanne
  'Geld unsicher, groß': () => money(98765.43, 123456.78, 80000, 15000, 24691.35, [-12345.67, 61728.39]),
  'Wetter +250 % / Gradtage −60 %': () => weather(1000, 3500, 300, 120, 1.5),
  'Wetter −80 % / Gradtage +60 %': () => weather(1000, 200, 300, 480, 0.2),
  'Wetter gleich': () => weather(1000, 1010, 300, 300, 1.0),
  'Wetter +999 %': () => weather(100, 1099, 300, 3000, 9),
};
for (const lang of LANGS) {
  await initI18n(lang);
  for (const [name, make] of Object.entries(EXTREME)) {
    const html = make();
    const fig = parse(html);
    const svg = fig.querySelector('svg');
    if (!svg) { t(`[${lang}] ${name}: Bild`, false); continue; }
    const [, , W, H] = viewBox(svg);
    const B = [...svg.querySelectorAll('text')].map(box);
    const bars = [...svg.querySelectorAll('rect')].filter(r => !r.closest('.ex-legend') && !(r.getAttribute('class') || '').includes('ex-badge'))
      .map(r => ({ x0: +r.getAttribute('x'), y0: +r.getAttribute('y'), x1: +r.getAttribute('x') + +r.getAttribute('width'), y1: +r.getAttribute('y') + +r.getAttribute('height') }))
      .filter(r => r.x1 - r.x0 > 0.5);
    const swatches = [...svg.querySelectorAll('.ex-legend rect')].map(r => ({ x0: +r.getAttribute('x'), y0: +r.getAttribute('y'), x1: +r.getAttribute('x') + 10, y1: +r.getAttribute('y') + 10 }));
    const iss = [];
    for (let i = 0; i < B.length; i++) for (let j = i + 1; j < B.length; j++) if (ov(B[i], B[j])) iss.push(`„${B[i].txt}“↔„${B[j].txt}“`);
    for (const b of B) {
      if (b.x0 < 0 || b.x1 > W + 0.5 || b.y0 < 0 || b.y1 > H) iss.push(`„${b.txt}“ ragt hinaus (${Math.round(b.x0)}…${Math.round(b.x1)})`);
      for (const r of bars) if (ov(b, r, 0)) iss.push(`„${b.txt}“ auf einem Balken`);
      for (const r of swatches) if (ov(b, r, 0)) iss.push(`„${b.txt}“ auf einem Legendenfeld`);
    }
    const badge = svg.querySelector('.ex-badge'), badgeText = svg.querySelector('.ex-badge__text');
    if (badge && badgeText) { const bb = box(badgeText); if (bb.x1 - bb.x0 > +badge.getAttribute('width') - 8) iss.push('Plakette zu schmal'); }
    t(`[${lang}] ${name}: keine Überlappung, alles im Bild`, !iss.length, [...new Set(iss)].slice(0, 3).join('; '));
  }
}
await initI18n('de');
t('Geld: 0 € Abschläge werden genannt', parse(EXTREME['Geld ohne Abschläge (0 €)']()).querySelector('svg').textContent.includes(fmt.eur(0)));
// v3.7.0 (wirklichkeit-2, Schritt 3) — „Wohin geht mein Geld?“ bei unsicherer Schätzung: Spanne statt Punktwert
{
  const fig = parse(money(1200, 1300, 700, 300, 100, [-50, 250]));
  const cap = fig.querySelector('.explainer__text')?.textContent || '';
  const svg = fig.querySelector('svg').textContent;
  t('Geld unsicher: Kosten als Spanne (Bild und Satz)', svg.includes(`${fmt.eur0(1150)} bis ${fmt.eur0(1450)}`) && cap.includes(`${fmt.eur0(1150)} bis ${fmt.eur0(1450)}`), `${svg} | ${cap}`);
  t('Geld unsicher: Ergebnis als Spanne, kein „rund 100 € Nachzahlung“ allein', /je nach Schätzung zwischen rund .+ Guthaben und rund .+ Nachzahlung/.test(cap)
    && !cap.includes(`Das ergibt rund ${fmt.eur0(100)} Nachzahlung`), cap);
  t('Geld unsicher: keine Lücke, die einen Punktwert zeigt', !fig.querySelector('.ex-gap--due, .ex-gap--credit'));
  const sure = parse(money(1200, 1300, 700, 300, 100));
  // v3.9.0 — „rund“ ohne Cent (bis v3.8 „rund 100,00 €“)
  t('Geld sicher: ein gerundeter Betrag', (sure.querySelector('.explainer__text')?.textContent || '').includes(`Das ergibt rund ${fmt.eur0(100)} Nachzahlung`) && !!sure.querySelector('.ex-gap--due'));
}

// v3.9.0 (L1) — „Wohin geht mein Geld?“ nennt den Abrechnungstag des Vertragsstatus (letzter Tag), nicht das Monatsende
{
  const cap = parse(EX.moneyHtml({ balance_path: [{ ym: '2026-01', paid: 100, cost: 90 }, { ym: '2027-02', paid: 1400, cost: 1300 }],
    energy_cost_to_date: 700, base_to_date: 300, bonus_to_date: 0, projected_end_balance: -100, settle_on: '2027-02-14' }))
    .querySelector('.explainer__text')?.textContent || '';
  t('L1: Geld — Abrechnung am letzten Tag des Zeitraums (settle_on)', cap.includes(`am ${fmt.date('2027-02-14')}`) && !cap.includes(fmt.date('2027-02-28')), cap);
}
// v3.9.0 (A1) — meldet der Beobachter ein Bild nie als sichtbar, steht nach zwei Sekunden das Endbild
{
  const prevIO = globalThis.IntersectionObserver;
  globalThis.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  window.IntersectionObserver = globalThis.IntersectionObserver;
  document.body.innerHTML = `<div id="root-a1">${money(1200, 1300, 700, 300, 100)}</div>`;
  const f = document.querySelector('#root-a1 .explainer');
  EX.playExplainers(document.getElementById('root-a1'));
  const before = f.classList.contains('ex--play');
  await new Promise(r => setTimeout(r, 2100));
  t('A1: ohne Sichtbarkeitsmeldung nach 2 s abgespielt (Balken nicht auf Breite 0)', !before && f.classList.contains('ex--ready') && f.classList.contains('ex--play'));
  globalThis.IntersectionObserver = prevIO; window.IntersectionObserver = prevIO;
}

// v3.9.0 (Skeptiker N10) — „Wohin geht mein Geld?“ nennt auf der Übersicht die Verbrauchsart
{
  const withLabel = parse(EX.moneyHtml({ balance_path: [{ ym: '2026-01', paid: 100, cost: 90 }, { ym: '2026-12', paid: 1200, cost: 1300 }],
    energy_cost_to_date: 700, base_to_date: 300, bonus_to_date: 0, projected_end_balance: 100 }, { utilityLabel: 'Wasser' }));
  t('N10: Geld — Titel mit Verbrauchsart', withLabel.querySelector('.explainer__title')?.textContent === `${tr('explainer.money.title')} · Wasser`,
    withLabel.querySelector('.explainer__title')?.textContent);
  t('N10: ohne Angabe der Titel wie bisher', parse(money(1200, 1300, 700, 300, 100)).querySelector('.explainer__title')?.textContent === tr('explainer.money.title'));
}
// v3.9.0 (Skeptiker N13) — „Kälter oder mehr verbraucht?“: −4 % bei ±9 % Streuung je Monat ist kein Befund.
// Handrechnung: 1,28 × 0,09 × √(2/12) = 4,7 % > 4 % → „übliche Schwankung“; bei ±3 % je Monat: 1,6 % → Schwelle 2 % → „weniger“
{
  const rows = [];
  for (let i = 1; i <= 12; i++) rows.push(wm(2025, i, 1000, 300));
  for (let i = 1; i <= 12; i++) rows.push({ ...wm(2026, i, 1010, 330), heat_adjusted: 960 });
  const noisy = parse(EX.weatherHtml(rows, { quality: { model_quality: 'good', ratio: 0.09 } }));
  const cap = noisy.querySelector('.explainer__text')?.textContent || '';
  t('N13: −4 % bei ±9 % je Monat — „übliche Schwankung“, kein „wirklich weniger“', /üblichen Schwankung/.test(cap) && /±5\s?%/.test(cap)
    && !!noisy.querySelector('.ex-badge--same'), cap);
  const quiet = parse(EX.weatherHtml(rows, { quality: { model_quality: 'good', ratio: 0.03 } }));
  t('N13: −4 % bei ±3 % je Monat — weniger geheizt', !!quiet.querySelector('.ex-badge--less')
    && (quiet.querySelector('.explainer__text')?.textContent || '').includes(tr('explainer.weather.text.less', { cons: '+1\u00a0%', hdd: '+10\u00a0%', adj: '-4\u00a0%' }).slice(0, 8)));
}

console.log(`\nexplainer.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
