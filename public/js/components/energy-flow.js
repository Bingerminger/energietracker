// =====================================================================
// v3.4.0 (F1026) — Energiefluss im Haus, zweite Fassung.
//
// Sonne oben, Netz links, Speicher rechts, Haus unten in der Mitte, darunter
// bis zu drei Verbraucher (Wärmepumpe, Wallbox, Übriges). Die einzige
// Diagonale mit Fluss (Sonne → Haus) liegt senkrecht, die andere
// (Netz → Speicher) gibt es nur bei Netzladung — dann mit einer erklärten
// Brücke. Keine Zahl steht auf einer Linie: Werte nur in den Knoten.
//
// Mengen kommen aus `pv-summary` (`flow` je Monat und Jahr, berechnet in
// PvSummaryService::flows()); fehlt das Feld, rechnet flowsFromTotals()
// dieselbe Formel. tests/fixtures/energy-flows.json prüft beide Seiten.
//
// Bewegung: Knoten springen gestaffelt auf, Bänder zeichnen sich, Ringe drehen
// ein, Zahlen zählen hoch; danach laufen Punkte mit konstantem Tempo
// (SMIL animateMotion), ihre Dichte folgt der Menge. „Bewegung reduzieren“:
// Endbild, Pfeile statt Punkte, SMIL angehalten. Ohne Skript und im Druck
// steht das Endbild.
//
// Bedienung: Jahr/Monat mit Übergang an Ort und Stelle, Knöpfe je Knoten
// (`aria-pressed`) heben dessen Flüsse hervor, die Detailzeile sagt sie an
// (`aria-live`), die Flüsse stehen zusätzlich als Liste für Screenreader;
// die Grafik selbst ist `aria-hidden`.
// =====================================================================

import { t, tp } from '../lib/i18n.js';
import { fmt, escapeHtml, intlLocale } from '../lib/format.js';

const esc = escapeHtml;
const r1 = (v) => Math.round(v * 10) / 10;

// ── 1. Rechnung ─────────────────────────────────────────────────────────

/**
 * Teilflüsse aus Mengen (kWh) — dieselbe Formel wie PvSummaryService::flows().
 *   PV→Netz = Einspeisung · PV→Speicher = min(geladen, Erzeugung − Einspeisung)
 *   Netz→Speicher = geladen − PV→Speicher · PV→Haus = Erzeugung − Einspeisung − PV→Speicher
 *   Speicher→Haus = entladen · Netz→Haus = max(0, Bezug − Netz→Speicher)
 *   Haus = Summe der Zuflüsse · Netzstrom im Haus = min(Bezug, Netz→Haus + min(Netz→Speicher, Speicher→Haus))
 *   Autarkie = 1 − Netzstrom im Haus / Haus
 */
export function flowsFromTotals({ gen = 0, feed = 0, grid = 0, charged = 0, discharged = 0 } = {}) {
  const n = (v) => Math.max(0, Number(v) || 0);
  gen = n(gen); grid = n(grid); charged = n(charged); discharged = n(discharged);
  feed = Math.min(n(feed), gen);
  const pvAvail = gen - feed, pvBat = Math.min(charged, pvAvail), gridBat = charged - pvBat;
  const f = {
    pv_house: pvAvail - pvBat, pv_battery: pvBat, pv_grid: feed,
    grid_house: Math.max(0, grid - gridBat), grid_battery: gridBat, battery_house: discharged,
  };
  f.house = f.pv_house + f.battery_house + f.grid_house;
  f.grid_in_house = Math.min(grid, f.grid_house + Math.min(gridBat, discharged));
  for (const k of Object.keys(f)) f[k] = r1(f[k]);
  f.autarky = f.house > 0.1 ? Math.round((1 - f.grid_in_house / f.house) * 10000) / 10000 : null;
  return f;
}

/** Fluss einer Monats- oder Jahreszeile aus pv-summary (null: nicht abgedeckt). */
export function flowOf(row) {
  if (!row) return null;
  if (row.flow !== undefined) return row.flow;          // Server: eine Rechnung
  const gen = Number(row.erzeugung_kwh) || 0;           // ältere Antwort ohne `flow`
  if (!(gen > 0)) return null;
  const self = row.eigenverbrauch_kwh != null ? Number(row.eigenverbrauch_kwh) : gen - (Number(row.einspeisung_kwh) || 0);
  const f = flowsFromTotals({ gen, feed: gen - self, grid: row.bezug_kwh,
    charged: row.battery?.charged_kwh, discharged: row.battery?.discharged_kwh });
  f.heat_pump = row.heat_pump_kwh ?? null;
  f.ev_charger = row.ev_charger_kwh ?? null;
  return f;
}

const FLOW_KEYS = ['pv_house', 'pv_battery', 'pv_grid', 'grid_house', 'grid_battery', 'battery_house'];

/** Welche Knoten und Wege ein Bild hat — je Jahr fest, damit Monate an Ort und Stelle wechseln. */
export function nodeSet(flow, hasBattery = false) {
  const v = (k) => Math.max(0, Number(flow?.[k]) || 0);
  const house = flow?.house != null ? v('house') : v('pv_house') + v('battery_house') + v('grid_house');
  const dev = [];
  if (v('heat_pump') > 0.5) dev.push('hp');
  if (v('ev_charger') > 0.5) dev.push('ev');
  if (dev.length && house - v('heat_pump') - v('ev_charger') > 0.5) dev.push('rest');
  return {
    bat: hasBattery || v('pv_battery') + v('grid_battery') + v('battery_house') > 0.05,
    gridBat: v('grid_battery') > 0.05,
    dev,
  };
}

/** Zahlen eines Bildes: Teilflüsse, Haus, Ringanteile, Kennzahlen. */
export function flowModel(flow, set) {
  const v = (k) => Math.max(0, Number(flow?.[k]) || 0);
  const f = Object.fromEntries(FLOW_KEYS.map(k => [k, v(k)]));
  const house = flow?.house != null ? v('house') : f.pv_house + f.battery_house + f.grid_house;
  const gridIn = Math.min(house, flow?.grid_in_house != null ? v('grid_in_house')
    : Math.min(f.grid_house + f.grid_battery, f.grid_house + Math.min(f.grid_battery, f.battery_house)));
  const gen = f.pv_house + f.pv_battery + f.pv_grid;
  const devVal = { hp: v('heat_pump'), ev: v('ev_charger') };
  devVal.rest = Math.max(0, house - devVal.hp - devVal.ev);
  return {
    f, house, gen, gridIn,
    // Netzstrom, der über den Speicher ins Haus kam: im Hausring Netz, nicht Speicher
    viaBat: Math.max(0, Math.min(f.battery_house, gridIn - f.grid_house)),
    feed: f.pv_grid, grid: f.grid_house + f.grid_battery,
    charged: f.pv_battery + f.grid_battery, discharged: f.battery_house,
    dev: set.dev.map(k => ({ key: k, v: devVal[k] })),
    // Kennzahl vom Server, wenn sie da ist — dieselbe Zahl wie der Ring
    autarky: flow?.autarky !== undefined ? flow.autarky : (house > 0.1 ? 1 - gridIn / house : null),
    selfShare: gen > 0.1 ? (f.pv_house + f.pv_battery) / gen : null,
    calc: f.pv_battery > 0.5 || f.grid_battery > 0.5,
  };
}

// ── 2. Anordnung ────────────────────────────────────────────────────────

const cub = (x1, y1, c1x, c1y, c2x, c2y, x2, y2) => `M${x1},${y1} C${c1x},${c1y} ${c2x},${c2y} ${x2},${y2}`;

/**
 * Knoten und Wege (viewBox 320 breit). Geprüft von tests/explainer.test.mjs
 * (R1–R5: kein Text auf Text, auf Linien oder fremden Knoten, keine
 * Kreuzung außer der erklärten Brücke, alles im Bild).
 */
export function flowLayout(set) {
  const N = {}, P = [];
  N.pv = { x: 160, y: 62, r: 34, name: 'above' };
  N.grid = { x: 58, y: 172, r: 36, name: 'below' };
  if (set.bat) N.bat = { x: 262, y: 172, r: 36, name: 'below' };
  N.house = { x: 160, y: 282, r: 42, name: null };
  const k = set.dev.length, xs = k === 3 ? [58, 160, 262] : k === 2 ? [96, 224] : [160];
  set.dev.forEach((d, i) => { N[d] = { x: xs[i], y: 392, r: 26, name: 'below' }; });
  P.push({ id: 'pv_house', from: 'pv', to: 'house', d: 'M160,96 V240' });
  P.push({ id: 'pv_grid', from: 'pv', to: 'grid', d: cub(126, 62, 92, 62, 58, 96, 58, 136) });
  if (N.bat) P.push({ id: 'pv_battery', from: 'pv', to: 'bat', d: cub(194, 62, 228, 62, 262, 96, 262, 136) });
  P.push({ id: 'grid_house', from: 'grid', to: 'house', d: cub(94, 172, 116, 172, 96, 282, 118, 282) });
  if (N.bat) P.push({ id: 'battery_house', from: 'bat', to: 'house', d: cub(226, 172, 204, 172, 224, 282, 202, 282) });
  // Netz → Speicher nur bei Netzladung: flacher Bogen über der Mitte, kreuzt
  // einzig Sonne → Haus — dort als Brücke gezeichnet und im Text erklärt
  if (N.bat && set.gridBat) P.push({ id: 'grid_battery', from: 'grid', to: 'bat', d: cub(89, 154, 128, 138, 192, 138, 231, 154), bridge: true });
  set.dev.forEach((d) => {
    const c = N[d];
    if (c.x === 160) { P.push({ id: `house_${d}`, from: 'house', to: d, d: 'M160,324 V366' }); return; }
    const s = Math.sign(c.x - 160), a = 0.7;   // Austritt unten seitlich am Haus
    const sx = +(160 + s * 42 * Math.sin(a)).toFixed(1), sy = +(282 + 42 * Math.cos(a)).toFixed(1);
    P.push({ id: `house_${d}`, from: 'house', to: d, d: cub(sx, sy, sx + s * 6, sy + 30, c.x, 340, c.x, 366) });
  });
  return { W: 320, H: k ? 438 : 336, N, P };
}

/** Punkte auf einem Weg (für Länge, Pfeil und Prüfung). */
export function samplePath(d, n = 80) {
  const v = d.match(/-?\d+(\.\d+)?/g).map(Number);
  if (/C/.test(d)) {
    const [x1, y1, a, b, c, e, x2, y2] = v, pts = [];
    for (let i = 0; i <= n; i++) {
      const s = i / n, u = 1 - s;
      pts.push([u ** 3 * x1 + 3 * u * u * s * a + 3 * u * s * s * c + s ** 3 * x2, u ** 3 * y1 + 3 * u * u * s * b + 3 * u * s * s * e + s ** 3 * y2]);
    }
    return pts;
  }
  if (/V/.test(d)) { const [x, y1, y2] = v; return Array.from({ length: n + 1 }, (_, i) => [x, y1 + (y2 - y1) * i / n]); }
  return [];
}
const pathLength = (pts) => pts.slice(1).reduce((s, p, i) => s + Math.hypot(p[0] - pts[i][0], p[1] - pts[i][1]), 0);

// ── 3. Zeichnung ────────────────────────────────────────────────────────

// Farbklassen → CSS-Variablen aus der Utilities-SSOT (components.css)
const FLOW_COLOR = { pv_house: 'pv', pv_battery: 'pv', pv_grid: 'feed', grid_house: 'grid', grid_battery: 'grid', battery_house: 'bat' };
const NAME_KEY = { pv: 'pv', grid: 'grid', bat: 'battery', house: 'house', hp: 'heatPump', ev: 'wallbox', rest: 'other' };
export const nodeName = (k) => t(`explainer.energy.node.${NAME_KEY[k]}`);

// Vektor-Piktogramme statt Emoji: färbbar, animierbar, überall gleich
const ICON = {
  pv: `<g class="ef-ico"><circle r="4.2"/><g class="ef-rays">${[0, 45, 90, 135, 180, 225, 270, 315].map(a => `<line x1="0" y1="-6.6" x2="0" y2="-9" transform="rotate(${a})"/>`).join('')}</g></g>`,
  grid: `<g class="ef-ico"><path d="M-6,9 L-2.5,-9 H2.5 L6,9 M-4.6,0 H4.6 M-5.4,4.5 H5.4 M-3.6,-4.5 H3.6"/></g>`,
  bat: `<g class="ef-ico"><rect x="-5" y="-7.5" width="10" height="16" rx="1.6"/><rect x="-2.2" y="-9.6" width="4.4" height="2.1" rx=".6"/><rect class="ef-batfill" x="-3.2" y="-5.5" width="6.4" height="12" rx=".8"/></g>`,
  house: `<g class="ef-ico"><path d="M-9,-1 L0,-9 L9,-1 M-6.5,-3 V8 H6.5 V-3 M-2,8 V2.5 H2 V8"/></g>`,
  hp: `<g class="ef-ico"><circle r="7.5"/><g class="ef-fan"><path d="M0,0 C-1,-4 1,-6 2.6,-5.6 C2.8,-3 1.6,-1.2 0,0 Z M0,0 C4,-1 6,1 5.6,2.6 C3,2.8 1.2,1.6 0,0 Z M0,0 C1,4 -1,6 -2.6,5.6 C-2.8,3 -1.6,1.2 0,0 Z M0,0 C-4,1 -6,-1 -5.6,-2.6 C-3,-2.8 -1.2,-1.6 0,0 Z"/></g></g>`,
  ev: `<g class="ef-ico"><path d="M-8,3 V-1 L-5.5,-5.5 H5.5 L8,-1 V3 Z M-8,3 V6 M8,3 V6"/><circle cx="-4.5" cy="3" r="1.2"/><circle cx="4.5" cy="3" r="1.2"/></g>`,
  rest: `<g class="ef-ico"><path d="M-3,6 H3 M-2.2,8.4 H2.2 M-3,6 C-3,2.5 -6,1 -6,-2.5 A6,6 0 1 1 6,-2.5 C6,1 3,2.5 3,6"/></g>`,
};

/** Eine Einheit für alle Knoten: kWh bis 99.999, darüber MWh. */
function units(m) {
  const big = Math.max(m.gen, m.house, m.grid, m.feed, m.charged) >= 100000;
  return {
    big, unit: big ? 'MWh' : 'kWh',
    num: (v) => (big ? fmt.num(v / 1000, 1) : fmt.int(v)),
  };
}

/**
 * SVG des Bildes. Jedes Element, das sich beim Wechsel des Zeitraums ändert,
 * trägt `data-k`; gleiche Signatur (`data-sig`) heißt gleiche Knoten und Wege.
 * @returns {{svg: string, texts: Array<object>}}
 */
export function flowSvg(m, L, uid = 'flow0') {
  const U = units(m);
  const val = (p) => (p.id.startsWith('house_') ? m.dev.find(d => `house_${d.key}` === p.id).v : m.f[p.id] || 0);
  const maxF = Math.max(1, ...L.P.map(val));
  const texts = [];
  const tx = (x, y, txt, cls, fs, owner, { anchor = 'middle', key = null, count = null, mono = false } = {}) => {
    texts.push({ x, y, txt: String(txt), fs, mono, anchor, owner });
    return `<text x="${x}" y="${y}" class="${cls}" font-size="${fs}" text-anchor="${anchor}"${key ? ` data-k="${key}"` : ''}${count != null ? ` data-count="${r1(count)}"` : ''}>${esc(txt)}</text>`;
  };

  // Wege: Spur, Band ∝ Menge, Punkte mit konstantem Tempo und Dichte ∝ Menge,
  // Pfeil für „Bewegung reduzieren“ und den Druck
  const paths = L.P.map((p, i) => {
    const v = val(p), pts = samplePath(p.d), len = pathLength(pts);
    const col = p.id.startsWith('house_') ? p.id.slice(6) : FLOW_COLOR[p.id];
    const on = v > 0.5;
    const w = (1.5 + 6.5 * v / maxF).toFixed(1);
    const n = on ? Math.max(1, Math.min(8, Math.round(len * (0.012 + 0.05 * v / maxF)))) : 0;
    const dur = (len / 34).toFixed(2);   // 34 Einheiten/s: eine längere Linie ist keine größere Menge
    const dots = Array.from({ length: n }, (_, j) =>
      `<circle class="ef-dot" r="${(2 + 1.2 * v / maxF).toFixed(1)}"><animateMotion dur="${dur}s" begin="-${(j * dur / n).toFixed(2)}s" repeatCount="indefinite" calcMode="linear"><mpath href="#${uid}-${p.id}" xlink:href="#${uid}-${p.id}"/></animateMotion></circle>`).join('');
    const mid = pts[Math.floor(pts.length / 2)], nx = pts[Math.floor(pts.length / 2) + 1];
    const ang = Math.atan2(nx[1] - mid[1], nx[0] - mid[0]) * 180 / Math.PI;
    const calc = p.id === 'pv_battery' || p.id === 'grid_battery';
    const cls = `ef-flow ef-c-${col}${on ? '' : ' ef-flow--off'}${p.bridge ? ' ef-flow--bridge' : ''}${calc ? ' ef-flow--calc' : ''}`;
    return `<g class="${cls}" data-k="flow-${p.id}" data-flow="${p.id}" data-from="${p.from}" data-to="${p.to}">
      <path id="${uid}-${p.id}" d="${p.d}" class="ef-track"/>${p.bridge ? `<path d="${p.d}" class="ef-bridge"/>` : ''}
      <path d="${p.d}" class="ef-band" data-k="band-${p.id}" style="stroke-width:${w};--len:${len.toFixed(1)};--d:${(0.35 + i * 0.12).toFixed(2)}s"/>${calc ? `<path d="${p.d}" class="ef-calc" data-k="calc-${p.id}" style="stroke-width:${(+w + 1).toFixed(1)}"/>` : ''}
      <g class="ef-dots" data-k="dots-${p.id}">${dots}</g>
      <path class="ef-arrow" d="M-3.5,-3.5 L1.5,0 L-3.5,3.5" transform="translate(${mid[0].toFixed(1)},${mid[1].toFixed(1)}) rotate(${ang.toFixed(0)})"/>
    </g>`;
  });

  // Ringe: Anteile mit absoluten Längen (2πr — Safari rechnet pathLength auf
  // Kreisen unzuverlässig), Start oben, im Uhrzeigersinn. Alle Teile werden
  // immer gezeichnet (auch mit Länge 0), damit ein Wechsel sie verschieben kann.
  const ring = (key, c, r, parts, d) => {
    const C = 2 * Math.PI * r, tot = parts.reduce((s, p) => s + p.v, 0);
    const live = parts.filter(p => p.v > 0).length;
    let acc = 0;
    return `<g class="ef-ring" transform="translate(${c.x},${c.y}) rotate(-90)">${parts.map((p, i) => {
      const share = tot > 0 ? p.v / tot * C : 0;
      const gap = live > 1 && p.v > 0 ? Math.min(1.6, share / 2) : 0;
      const s = `<circle r="${r}" class="ef-arc ef-c-${p.c}" data-k="ring-${key}-${i}" style="stroke-dasharray:${Math.max(0, share - gap).toFixed(2)} ${C.toFixed(2)};stroke-dashoffset:${(-acc).toFixed(2)};--cl:${C.toFixed(2)};--d:${d}s"/>`;
      acc += share; return s;
    }).join('')}</g>`;
  };
  const nodes = [];
  const node = (key, inner, ringSvg, d, iconDy) => {
    const c = L.N[key];
    let name = '';
    if (c.name === 'above') name = tx(c.x, c.y - c.r - 9, nodeName(key), 'ef-name', 11, key);
    else if (c.name === 'below') name = tx(c.x, c.y + c.r + 15, nodeName(key), 'ef-name', 11, key);
    nodes.push(`<g class="ef-node ef-node--${key} ef-c-${key}" data-node="${key}" style="--d:${d}s">
      ${ringSvg}<circle cx="${c.x}" cy="${c.y}" r="${c.r}" class="ef-disc"/>
      <g class="ef-icon" transform="translate(${c.x},${c.y - iconDy})">${ICON[key]}</g>
      ${inner}${name}</g>`);
  };
  const N = L.N;
  node('pv', tx(N.pv.x, N.pv.y + 9, U.num(m.gen), 'ef-val', 13, 'pv', { key: 'v-pv', count: m.gen, mono: true })
    + tx(N.pv.x, N.pv.y + 21, U.unit, 'ef-unit', 9, 'pv', { key: 'u-pv' }),
  ring('pv', N.pv, N.pv.r + 5, [{ v: m.f.pv_house, c: 'pv' }, { v: m.f.pv_battery, c: 'bat' }, { v: m.f.pv_grid, c: 'feed' }], 0.9), 0, 13);
  node('grid', tx(N.grid.x, N.grid.y + 4, `↓${U.num(m.grid)}`, 'ef-val ef-val--sm', 11, 'grid', { key: 'v-grid-in', count: m.grid, mono: true })
    + tx(N.grid.x, N.grid.y + 18, `↑${U.num(m.feed)}`, 'ef-val ef-val--sm ef-val--out', 11, 'grid', { key: 'v-grid-out', count: m.feed, mono: true }),
  ring('grid', N.grid, N.grid.r + 5, [{ v: m.grid, c: 'grid' }, { v: m.feed, c: 'feed' }], 1.0), 0.1, 17);
  if (N.bat) {
    node('bat', tx(N.bat.x, N.bat.y + 4, `↓${U.num(m.charged)}`, 'ef-val ef-val--sm', 11, 'bat', { key: 'v-bat-in', count: m.charged, mono: true })
      + tx(N.bat.x, N.bat.y + 18, `↑${U.num(m.discharged)}`, 'ef-val ef-val--sm', 11, 'bat', { key: 'v-bat-out', count: m.discharged, mono: true }),
    ring('bat', N.bat, N.bat.r + 5, [{ v: Math.min(m.discharged, m.charged || m.discharged), c: 'bat' }, { v: Math.max(0, m.charged - m.discharged), c: 'rest' }], 1.1), 0.15, 17);
  }
  // Hausring = Herkunft: Sonne direkt, Speicher, Netz (auch über den Speicher)
  // — der Netzanteil ist genau 1 − Autarkie
  node('house', tx(N.house.x, N.house.y + 10, U.num(m.house), 'ef-val ef-val--lg', 14, 'house', { key: 'v-house', count: m.house, mono: true })
    + tx(N.house.x, N.house.y + 23, U.unit, 'ef-unit', 9, 'house', { key: 'u-house' }),
  ring('house', N.house, N.house.r + 6, [{ v: m.f.pv_house, c: 'pv' }, { v: m.f.battery_house - m.viaBat, c: 'bat' }, { v: m.gridIn, c: 'grid' }], 1.2), 0.25, 17);
  m.dev.forEach((d, i) => {
    node(d.key, tx(N[d.key].x, N[d.key].y + 10, U.num(d.v), 'ef-val ef-val--xs', 9.5, d.key, { key: `v-${d.key}`, count: d.v, mono: true }), '', 0.4 + i * 0.08, 8);
  });

  const sig = `${Object.keys(N).join(',')}|${L.P.map(p => p.id).join(',')}`;
  const svg = `<svg class="explainer__svg ef" viewBox="0 0 ${L.W} ${L.H}" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false" data-sig="${sig}" xmlns:xlink="http://www.w3.org/1999/xlink">
    <g class="ef-flows">${paths.join('')}</g>
    <g class="ef-nodes">${nodes.join('')}</g>
  </svg>`;
  return { svg, texts };
}

// ── 4. Abbildung mit Bedienung ──────────────────────────────────────────

const unitText = (v) => fmt.unit(v, 'kWh');
const pct = (v) => (v == null ? '–' : fmt.pct(v, 0));
const monthLong = (ym) => {
  const [y, mo] = String(ym).split('-').map(Number);
  return new Intl.DateTimeFormat(intlLocale(), { month: 'long', year: 'numeric' }).format(new Date(y, mo - 1, 1, 12));
};

/** Zeiträume aus einer pv-summary-Antwort oder einer einzelnen Jahreszeile. */
function prepare(input, opts) {
  const isSummary = Array.isArray(input?.yearly);
  const yearly = isSummary ? input.yearly : (input ? [input] : []);
  const genOf = (f) => (f ? (Number(f.pv_house) || 0) + (Number(f.pv_battery) || 0) + (Number(f.pv_grid) || 0) : 0);
  const years = yearly.map(row => ({ row, flow: flowOf(row) })).filter(y => y.flow && genOf(y.flow) > 0.5);
  if (!years.length) return null;
  const months = isSummary ? (input.monthly || []).map(row => ({ row, flow: flowOf(row) })).filter(x => x.flow) : [];
  // Knoten je Jahr: der Speicher erscheint in Jahren mit Speicherdaten
  const sets = new Map(years.map(y => [y.row.year, nodeSet(y.flow, !!y.row.battery)]));
  const pick = years.find(y => y.row.year === opts.year) || years[years.length - 1];
  return { years, months, sets, interactive: months.length > 0, scope: 'year', year: pick.row.year, ym: null, focus: null };
}

function currentEntry(st) {
  if (st.scope === 'month') return st.months.find(x => x.row.ym === st.ym) || null;
  return st.years.find(y => y.row.year === st.year) || null;
}

function periodLabel(st, e) {
  if (st.scope === 'month') return monthLong(e.row.ym);
  const n = Number(e.row.flow?.months ?? e.row.months_covered ?? 12);
  return n > 0 && n < 12 ? t('explainer.energy.yearPart', { year: e.row.year, span: tp('explainer.energy.months', n) }) : String(e.row.year);
}

/** Inhalt für den gewählten Zeitraum: SVG, Knöpfe, Liste, Hinweise, Text. */
function renderView(st, uid) {
  const e = currentEntry(st);
  const year = st.scope === 'month' ? Number(String(e.row.ym).slice(0, 4)) : e.row.year;
  const set = st.sets.get(year) || nodeSet(e.flow);
  const m = flowModel(e.flow, set);
  const L = flowLayout(set);
  const { svg } = flowSvg(m, L, uid);
  const label = periodLabel(st, e);

  // Flüsse als Text: Liste für Screenreader und Detailzeile
  const items = L.P.map(p => {
    const v = p.id.startsWith('house_') ? m.dev.find(d => `house_${d.key}` === p.id).v : m.f[p.id];
    if (!(v > 0.5)) return null;
    const calc = p.id === 'pv_battery' || p.id === 'grid_battery';
    const text = t('explainer.energy.flowItem', { from: nodeName(p.from), to: nodeName(p.to), value: unitText(v) })
      + (calc ? ` ${t('explainer.energy.calc')}` : '');
    return { from: p.from, to: p.to, text };
  }).filter(Boolean);

  const chip = (key, text) => `<button type="button" class="ef-chip ef-c-${key}" data-node="${key}" aria-pressed="${st.focus === key}">${esc(text)}</button>`;
  const chips = [
    chip('pv', t('explainer.energy.chip.pv', { value: unitText(m.gen), share: pct(m.selfShare) })),
    chip('house', t('explainer.energy.chip.house', { value: unitText(m.house), autarky: pct(m.autarky) })),
    set.bat ? chip('bat', t('explainer.energy.chip.battery', { in: unitText(m.charged), out: unitText(m.discharged) })) : '',
    chip('grid', t('explainer.energy.chip.grid', { in: unitText(m.grid), out: unitText(m.feed) })),
  ].join('');

  const parts = [t('explainer.energy.text', {
    period: label, gen: unitText(m.gen), feed: unitText(m.feed), house: unitText(m.house), grid: unitText(m.gridIn), autarky: pct(m.autarky),
  })];
  if (m.charged > 0.5 || m.discharged > 0.5) parts.push(t('explainer.energy.textBattery', { in: unitText(m.charged), out: unitText(m.discharged) }));
  if (m.f.grid_battery > 0.5) parts.push(t('explainer.energy.textGridCharge', { kwh: unitText(m.f.grid_battery) }));
  const devs = m.dev.filter(d => d.key !== 'rest' && d.v > 0.5);
  if (devs.length) parts.push(t('explainer.energy.textDevices', { list: devs.map(d => `${nodeName(d.key)} ${unitText(d.v)}`).join(', ') }));
  const notes = [];
  if (m.calc) notes.push(t('explainer.energy.calcNote'));
  if (e.row.self_consumption_assumed) notes.push(t('explainer.energy.assumedNote'));
  return { svg, chips, items, label, caption: parts.join(' '), note: notes.join(' ') };
}

// Zustand je Abbildung (für Wechsel und Hervorhebung); nur die jüngsten bleiben
const FIGS = new Map();
let seq = 0;

/**
 * Abbildung „Energiefluss im Haus“.
 * @param {object} input  pv-summary-Antwort ({monthly, yearly}) — mit Jahr/Monat
 *   zum Umschalten — oder eine einzelne Jahreszeile (nur das Jahr)
 * @param {{year?: number}} [opts]  gewünschtes Jahr (sonst das jüngste)
 * @returns {string} leer ohne Erzeugung
 */
export function energyFigureHtml(input, opts = {}) {
  const st = prepare(input, opts);
  if (!st) return '';
  const id = `flow${++seq}`;
  FIGS.set(id, st);
  if (FIGS.size > 24) FIGS.delete(FIGS.keys().next().value);
  const v = renderView(st, id);
  return `<figure class="explainer ex-energy" data-ef="${id}">
    <figcaption class="explainer__title">${esc(t('explainer.energy.title'))}</figcaption>
    ${st.interactive ? controlsHtml(st, v.label) : ''}
    <div class="ef-stage">${v.svg}</div>
    <div class="ef-chips" role="group" aria-label="${esc(t('explainer.energy.chipsGroup'))}">${v.chips}</div>
    <p class="ef-detail" aria-live="polite"></p>
    <ul class="sr-only ef-list">${v.items.map(i => `<li>${esc(i.text)}</li>`).join('')}</ul>
    <p class="ef-note"${v.note ? '' : ' hidden'}>${esc(v.note)}</p>
    <p class="explainer__text">${esc(v.caption)}</p>
  </figure>`;
}

function stepState(st) {
  const list = st.scope === 'month' ? st.months.map(x => x.row.ym) : st.years.map(y => y.row.year);
  const i = list.indexOf(st.scope === 'month' ? st.ym : st.year);
  return { list, i };
}

function controlsHtml(st, label) {
  const { list, i } = stepState(st);
  const seg = (scope) => `<button type="button" class="ef-seg__btn" data-ef-scope="${scope}" aria-pressed="${st.scope === scope}">${esc(t(`explainer.energy.scope.${scope}`))}</button>`;
  return `<div class="ef-period" role="group" aria-label="${esc(t('explainer.energy.periodGroup'))}">
      <span class="ef-seg">${seg('year')}${seg('month')}</span>
      <span class="ef-step">
        <button type="button" class="ef-step__btn" data-ef-step="-1" aria-label="${esc(t('explainer.energy.prev'))}"${i <= 0 ? ' disabled' : ''}>‹</button>
        <span class="ef-period__label" aria-live="polite">${esc(label)}</span>
        <button type="button" class="ef-step__btn" data-ef-step="1" aria-label="${esc(t('explainer.energy.next'))}"${i >= list.length - 1 ? ' disabled' : ''}>›</button>
      </span>
    </div>`;
}

const reduceMotion = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Zahl hochzählen (von → bis) in der Schreibweise des Zielbildes; am Ende
 * steht genau der Text, den das Bild ohne Bewegung zeigt.
 */
function countUp(el, from, to, final, big, ms = 800) {
  const prefix = (final.match(/^[↓↑]/) || [''])[0];
  const show = (v) => prefix + (big ? fmt.num(v / 1000, 1) : fmt.int(v));
  const raf = window.requestAnimationFrame;
  if (!raf || from === to) { el.textContent = final; return; }
  let t0 = null;
  const tick = (ts) => {
    if (t0 === null) t0 = ts;
    const k = Math.min(1, (ts - t0) / ms), e = 1 - (1 - k) ** 3;
    el.textContent = k < 1 ? show(from + (to - from) * e) : final;
    if (k < 1 && el.isConnected) raf(tick); else el.textContent = final;
  };
  raf(tick);
}
const isBig = (svg) => /MWh/.test(svg?.querySelector('[data-k="u-pv"]')?.textContent || '');

/** Neues Bild an Ort und Stelle: gleiche Knoten → Werte gleiten, sonst Überblendung. */
function morph(stage, html, reduce) {
  const old = stage.querySelector('svg.ef');
  const box = document.createElement('div');
  box.innerHTML = html;
  const neu = box.querySelector('svg.ef');
  if (!old || !neu || old.getAttribute('data-sig') !== neu.getAttribute('data-sig')) {
    if (neu && !reduce) neu.classList.add('ef--enter');
    stage.replaceChildren(...box.childNodes);
    if (reduce) neu?.pauseAnimations?.();
    return;
  }
  const big = isBig(neu);
  neu.querySelectorAll('[data-k]').forEach(n => {
    const o = old.querySelector(`[data-k="${n.getAttribute('data-k')}"]`);
    if (!o) return;
    if (n.hasAttribute('class')) o.setAttribute('class', n.getAttribute('class'));
    if (n.hasAttribute('style')) o.setAttribute('style', n.getAttribute('style'));
    if (n.classList.contains('ef-dots')) { o.innerHTML = n.innerHTML; return; }
    if (n.hasAttribute('data-count')) {
      const from = Number(o.getAttribute('data-count')) || 0, to = Number(n.getAttribute('data-count')) || 0;
      o.setAttribute('data-count', String(to));
      if (reduce) o.textContent = n.textContent; else countUp(o, from, to, n.textContent, big, 600);
    } else if (n.tagName.toLowerCase() === 'text') o.textContent = n.textContent;
  });
}

function applyFocus(fig, st) {
  const svg = fig.querySelector('svg.ef');
  const k = st.focus;
  fig.querySelectorAll('.ef-chip').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.node === k)));
  if (!svg) return;
  if (k) svg.setAttribute('data-focus', k); else svg.removeAttribute('data-focus');
  const near = new Set(k ? [k] : []);
  svg.querySelectorAll('.ef-flow').forEach(g => {
    const on = !!k && (g.dataset.from === k || g.dataset.to === k);
    g.classList.toggle('ef-on', on);
    if (on) { near.add(g.dataset.from); near.add(g.dataset.to); }
  });
  svg.querySelectorAll('.ef-node').forEach(n => n.classList.toggle('ef-on', near.has(n.dataset.node)));
  const detail = fig.querySelector('.ef-detail');
  if (detail) {
    const view = st.view;
    detail.textContent = k && view
      ? t('explainer.energy.detail', { node: nodeName(k), flows: view.items.filter(i => i.from === k || i.to === k).map(i => i.text).join(' · ') || '–' })
      : '';
  }
}

function update(fig, st) {
  const id = fig.getAttribute('data-ef');
  const v = renderView(st, id);
  st.view = v;
  const reduce = reduceMotion();
  morph(fig.querySelector('.ef-stage'), v.svg, reduce);
  const chips = fig.querySelector('.ef-chips');
  if (chips) chips.innerHTML = v.chips;
  const list = fig.querySelector('.ef-list');
  if (list) list.innerHTML = v.items.map(i => `<li>${esc(i.text)}</li>`).join('');
  const note = fig.querySelector('.ef-note');
  if (note) { note.textContent = v.note; note.hidden = !v.note; }
  const text = fig.querySelector('.explainer__text');
  if (text) text.textContent = v.caption;
  const period = fig.querySelector('.ef-period');
  if (period) {
    const { list: l, i } = stepState(st);
    period.querySelector('.ef-period__label').textContent = v.label;
    period.querySelector('[data-ef-step="-1"]').disabled = i <= 0;
    period.querySelector('[data-ef-step="1"]').disabled = i >= l.length - 1;
    period.querySelectorAll('[data-ef-scope]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.efScope === st.scope)));
  }
  if (st.focus && !fig.querySelector(`.ef-chip[data-node="${st.focus}"]`)) st.focus = null;
  applyFocus(fig, st);
}

/**
 * Bedienung anbinden (einmal je Abbildung): Zeitraum, Hervorhebung.
 * Aufgerufen von playExplainers().
 */
export function bindEnergyFigure(fig) {
  if (!fig || fig.dataset.efBound) return;
  const st = FIGS.get(fig.getAttribute('data-ef'));
  if (!st) return;
  fig.dataset.efBound = '1';
  st.view = renderView(st, fig.getAttribute('data-ef'));
  const toggle = (k) => { st.focus = st.focus === k ? null : k; applyFocus(fig, st); };
  fig.addEventListener('click', (ev) => {
    const el = ev.target?.closest?.('[data-ef-scope],[data-ef-step],.ef-chip,[data-node]');
    if (!el || !fig.contains(el)) return;
    if (el.dataset.efScope) {
      if (el.dataset.efScope === st.scope) return;
      if (el.dataset.efScope === 'month') {
        const inYear = st.months.filter(x => x.row.year === st.year || String(x.row.ym).startsWith(`${st.year}-`));
        const last = (inYear.length ? inYear : st.months).slice(-1)[0];
        if (!last) return;
        st.scope = 'month'; st.ym = last.row.ym;
      } else {
        const y = Number(String(st.ym).slice(0, 4));
        st.scope = 'year';
        st.year = st.years.some(x => x.row.year === y) ? y : st.year;
      }
      update(fig, st);
      return;
    }
    if (el.dataset.efStep) {
      const { list, i } = stepState(st);
      const j = i + Number(el.dataset.efStep);
      if (j < 0 || j >= list.length) return;
      if (st.scope === 'month') st.ym = list[j]; else st.year = list[j];
      update(fig, st);
      return;
    }
    const k = el.dataset.node;
    if (k && ['pv', 'grid', 'bat', 'house'].includes(k)) toggle(k);
  });
}

/** Bewegung starten, wenn die Abbildung sichtbar wird (Zahlen zählen hoch). */
export function startEnergyFigure(fig, { reduce = reduceMotion() } = {}) {
  const svg = fig?.querySelector('svg.ef');
  if (!svg) return;
  if (reduce) { svg.pauseAnimations?.(); return; }
  const big = isBig(svg);
  svg.querySelectorAll('[data-count]').forEach(el => {
    const to = Number(el.getAttribute('data-count')) || 0, final = el.textContent;
    el.textContent = (final.match(/^[↓↑]/) || [''])[0] + fmt.int(0);
    setTimeout(() => countUp(el, 0, to, final, big, 900), 600);
  });
}
