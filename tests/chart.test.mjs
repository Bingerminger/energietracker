// =====================================================================
// v2.15.0 — Chart-Schicht und Monatsreihen (Review FE-08, FE-11, FE-16, FE-20)
//
// Ohne Server: JSDOM und ein aufzeichnendes Chart-Stub. Geprüft wird, was
// bis v2.14 schiefging — zwei Charts auf einem Canvas, Charts, die nach dem
// Seitenwechsel weiterlebten, Farben, die dem Theme nicht folgten, Strom mit
// 1,8:1 auf Weiß, Trends, die Winter gegen Herbst stellten, halbe Monate wie
// volle — und die neuen Bausteine (Datentabelle, Kurzbeschreibung).
//
// Aufruf: node tests/chart.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..', 'public', 'js');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM('<!DOCTYPE html><html data-theme="dark"><head></head><body><main id="view"></main></body></html>',
  { url: 'http://127.0.0.1/#/dashboard', pretendToBeVisual: true });
for (const k of ['window', 'document', 'location', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'getComputedStyle']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}

// Aufzeichnendes Chart-Stub mit der Oberfläche, die components/chart.js nutzt
const instances = [];
function ChartStub(canvas, config) {
  if (ChartStub.throwNext) { ChartStub.throwNext = false; throw new Error('Canvas is already in use.'); }
  const inst = { canvas, config, destroyed: false, updates: [],
    destroy() { this.destroyed = true; this.canvas = null; },
    update(mode) { this.updates.push(mode); } };
  instances.push(inst);
  return inst;
}
ChartStub.defaults = { font: {}, plugins: { legend: { labels: {} }, tooltip: {} } };
ChartStub.getChart = (canvas) => instances.find(i => i.canvas === canvas && !i.destroyed) || undefined;
dom.window.Chart = ChartStub;
global.Chart = ChartStub;

const data = await import(`${ROOT}/lib/chart-data.js`);
const chart = await import(`${ROOT}/components/chart.js`);
const { contrast } = await import(`${ROOT}/lib/contrast.js`);

// ── Monatsreihen ─────────────────────────────────────────────────────
t('daysInMonth: Februar im Schaltjahr', data.daysInMonth('2024-02') === 29 && data.daysInMonth('2025-02') === 28);
t('isPartial: 14 von 31 Tagen', data.isPartial({ ym: '2026-03', days: 14 }) && !data.isPartial({ ym: '2026-03', days: 31 }));
t('isPartial: ohne Tage kein Teilmonat', !data.isPartial({ ym: '2026-03' }));
t('shiftYm: über den Jahreswechsel', data.shiftYm('2026-01', -12) === '2025-01' && data.shiftYm('2026-01', -1) === '2025-12');

// Zwei Jahre Gas; der jüngste Monat (März 2026) ist halb
const row = (ym, kwh, extra = {}) => ({ ym, year: Number(ym.slice(0, 4)), month: Number(ym.slice(5)), days: data.daysInMonth(ym), kwh, ...extra });
const series = [
  row('2024-10', 400), row('2024-11', 800), row('2024-12', 1200),
  row('2025-01', 1300, { heat_adjusted: 1200 }), row('2025-02', 1100, { heat_adjusted: 1000 }), row('2025-03', 900),
  row('2025-10', 380), row('2025-11', 760), row('2025-12', 1140),
  row('2026-01', 1170, { heat_adjusted: 1260 }), row('2026-02', 990, { heat_adjusted: 1050 }), { ...row('2026-03', 300), days: 14 },
];
const tr = data.yoyTrend(series, 'kwh', { months: 3 });
t('Trend: dieselben Monate des Vorjahres, ohne Teilmonat', tr && tr.months.join() === '2025-12,2026-01,2026-02',
  tr ? tr.months.join() : 'null');
// Dez–Feb 3300 gegen 3600 kWh ein Jahr zuvor
t('Trend: gemessen −8,33 %', tr && Math.abs(tr.pct - (-100 / 12)) < 1e-9 && !tr.adjusted && tr.current === 3300 && tr.previous === 3600,
  tr ? tr.pct.toFixed(2) : '');
const trAdj = data.yoyTrend(series, 'kwh', { months: 2, adjustedKey: 'heat_adjusted' });
t('Trend: witterungsbereinigt, wenn alle Monate einen Wert tragen', trAdj && trAdj.adjusted && Math.abs(trAdj.pct - 5) < 1e-9,
  trAdj ? `${trAdj.pct.toFixed(2)} % ${trAdj.months.join()}` : 'null');
t('Trend: gemessen, wenn ein Monat keinen bereinigten Wert hat',
  data.yoyTrend(series, 'kwh', { months: 3, adjustedKey: 'heat_adjusted' })?.adjusted === false);
t('Trend: kein Vorjahr → keiner', data.yoyTrend(series.slice(6), 'kwh', { months: 3 }) === null);
t('Trend: zu wenige Paare → keiner', data.yoyTrend(series, 'kwh', { months: 3, minMonths: 4 }) === null);
const trWin = data.yoyTrend(series, 'kwh', { months: 12, minMonths: 2, within: ['2025-10', '2025-11'] });
t('Trend: nur im Fenster', trWin && trWin.months.join() === '2025-10,2025-11', trWin ? trWin.months.join() : 'null');

const win = data.lastMonths(series, 12);
t('Fenster: Anfang, Ende, Teilmonat am Ende', win.from === '2024-10' && win.to === '2026-03' && win.partialLast
  && win.lastDays === 14 && win.lastTotal === 31);
const sum = data.seriesSummary(['a', 'b', 'c'], [5, null, 2]);
t('Kurzbeschreibung: Summe, Minimum, Maximum ohne Lücken', sum.sum === 7 && sum.n === 2 && sum.max.label === 'a' && sum.min.label === 'c');

// ── Farben: 3:1 für Grafik (WCAG 1.4.11) auf der Kartenfläche, beide Themes ──
const utilPhp = readFileSync(resolve(__dir, '..', 'src', 'Config', 'Utilities.php'), 'utf8');
const utilColors = [...utilPhp.matchAll(/'color'\s*=>\s*'(#[0-9a-fA-F]{6})'/g)].map(m => m[1]);
// Die festen Paletten der Ansichten (Modelle, Jahre, Angebote) laufen über dieselbe Tönung
const palettes = ['analysis.js', 'tariff.js'].flatMap(f =>
  [...readFileSync(resolve(ROOT, 'views', f), 'utf8').matchAll(/'(#[0-9a-fA-F]{6})'/g)].map(m => m[1]));
t('Farben gefunden', utilColors.length === 8 && palettes.length >= 10, `${utilColors.length} Verbrauchsarten, ${palettes.length} Palettenfarben`);
const CARD = { dark: '#111827', light: '#ffffff' };
const low = [];
for (const theme of ['dark', 'light']) {
  document.documentElement.setAttribute('data-theme', theme);
  for (const c of [...utilColors, ...palettes]) {
    const x = chart.chartColor({ color: c });
    const r = contrast(x, CARD[theme]);
    if (r < 3) low.push(`${theme} ${c}→${x} ${r.toFixed(2)}`);
  }
}
t('Jede Chartfarbe ≥ 3:1 auf der Karte', low.length === 0, low.slice(0, 4).join('; '));
document.documentElement.setAttribute('data-theme', 'light');
const strom = chart.chartColor({ color: '#22d3ee' });
t('Strom im Hellmodus abgedunkelt (bis v2.14: 1,8:1)', strom !== '#22d3ee' && contrast(strom, '#ffffff') >= 4.5, `${strom} ${contrast(strom, '#ffffff').toFixed(2)}:1`);
const fn = chart.utilColor({ color: '#22d3ee' }, 0.3);
const lightVal = fn();
document.documentElement.setAttribute('data-theme', 'dark');
t('utilColor folgt dem Theme (Funktion, nicht Wert)', typeof fn === 'function' && fn() !== lightVal && /^rgba\(/.test(lightVal), `${lightVal} → ${fn()}`);
t('withAlpha: #rgb und #rrggbb', chart.withAlpha('#fff', 0.5) === 'rgba(255, 255, 255, 0.5)' && chart.withAlpha('#102030', 1) === 'rgba(16, 32, 48, 1)');
t('chartColor: ungültige Farbe fällt auf Blau zurück', /^#[0-9a-f]{6}$/i.test(chart.chartColor({ color: 'red; }' })));

// ── Registry ─────────────────────────────────────────────────────────
const view = document.getElementById('view');
view.innerHTML = '<canvas id="a"></canvas><canvas id="b"></canvas>';
const [ca, cb] = view.querySelectorAll('canvas');
const c1 = chart.makeChart(ca, { type: 'bar', data: {} }, { label: 'Test A' });
const c2 = chart.makeChart(ca, { type: 'bar', data: {} });
t('Ein Canvas trägt ein Chart: das alte wird zerstört', c1.destroyed && !c2.destroyed && chart.liveChartCount() === 1);
t('Ohne Label bleibt das Canvas aus dem Accessibility-Tree', ca.getAttribute('aria-hidden') === 'true' && !ca.hasAttribute('aria-label'));
// Chart.js kopiert Achsen-Vorgaben beim Anlegen in die Konfiguration — so sieht es danach aus
const c3 = chart.makeChart(cb, { type: 'line', data: {}, options: { scales: { y: { ticks: { color: '#475569' }, title: { display: true, color: '#475569' }, grid: { color: 'x' } } } } }, { label: 'Test B' });
t('Mit Label: role=img und aria-label', cb.getAttribute('role') === 'img' && cb.getAttribute('aria-label') === 'Test B');
document.dispatchEvent(new CustomEvent('et:themechange', { detail: { theme: 'light' } }));
t('Theme-Wechsel zeichnet offene Charts neu', c2.updates.includes('none') && c3.updates.includes('none'));
const ys = c3.config.options.scales.y;
t('… und löst die kopierten Achsenfarben (sonst blieben die Beschriftungen im alten Theme)',
  !('color' in ys.ticks) && !('color' in ys.title) && !('color' in ys.grid) && ys.title.display === true);
cb.remove();
document.dispatchEvent(new CustomEvent('et:themechange', { detail: { theme: 'dark' } }));
t('… und räumt abgehängte ab', c3.destroyed && chart.liveChartCount() === 1);
window.dispatchEvent(new CustomEvent('et:route', { detail: { view: 'x' } }));
t('Seitenwechsel räumt alle ab', c2.destroyed && chart.liveChartCount() === 0);
ChartStub.throwNext = true;
let threw = false, res;
const logErr = console.error; console.error = () => {};   // der erwartete Fehler gehört in die Konsole, nicht ins Testprotokoll
try { res = chart.makeChart(ca, { type: 'bar', data: {} }); } catch { threw = true; }
console.error = logErr;
t('Ein Chart.js-Fehler wirft nicht (nie roh in einen Toast)', !threw && res === null);

// ── Datentabelle ─────────────────────────────────────────────────────
const html = chart.chartTableHtml({ caption: 'K', columns: ['Monat', 'Wert'], rows: [['<b>Jan</b>', '1'], ['Feb', null]] });
const box = document.createElement('div'); box.innerHTML = html;
t('Datentabelle: aufklappbar, Kopf, Zeilen', !!box.querySelector('details.chart-data > summary') && box.querySelectorAll('tbody tr').length === 2
  && box.querySelectorAll('thead th').length === 2 && !!box.querySelector('caption'));
t('Datentabelle: escaped und „–“ für leere Werte', !box.querySelector('b') && box.querySelectorAll('tbody tr')[1].lastElementChild.textContent === '–');
t('Datentabelle: nichts ohne Zeilen', chart.chartTableHtml({ columns: ['x'], rows: [] }) === '');

console.log(`\n  chart.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
