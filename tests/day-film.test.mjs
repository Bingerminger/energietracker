// =====================================================================
// v3.5.0 (F1027) — Tagesfilm des Energieflusses, ohne Server.
//
// Viertelstundenmodell (Leistung = kWh × 4, aufgelaufene Summen wie ein
// Monat, Knoten je Tag, Uhrzeit der Zeitumstellung, Leiste mit Preis und
// Lücken) und die Bedienung im DOM: „Tag“ erscheint nur mit Viertelstunden,
// Abspielen von selbst, Schieberegler mit Uhrzeit (aria-valuetext),
// Tastatur (Leertaste, Pfeile, Pos1/Ende, Bild auf/ab), Lücke als „–“,
// „Typischer Tag“, Monat und Tag wählen, „Bewegung reduzieren“ ohne
// Abspielen und ohne Punkte. Die API wird mit gebauten Antworten im
// Format von EnergyFlowDayService beantwortet.
//
// Aufruf: node tests/day-film.test.mjs
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
const wait = (ms) => new Promise(r => setTimeout(r, ms));

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'DOMParser']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}

// ── gebaute Antworten im Format der API ─────────────────────────────────
const EF = await import(`${JS}/components/energy-flow.js`);
const hhmm = (min) => `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;

/** Ein Tag: Sonne mittags, Netzladung nachts, Speicher abends, Lücke 15:00–15:15. */
function valuesAt(k) {
  const gen = k === 52 ? 2.0 : (k >= 32 && k < 72 ? 1.2 : 0);
  const feed = k >= 40 && k < 64 ? 0.4 : 0;
  const grid = k >= 8 && k < 12 ? 0.7 : k < 24 ? 0.2 : k >= 80 ? 0.1 : 0;
  const charged = k >= 8 && k < 12 ? 0.5 : k >= 36 && k < 48 ? 0.3 : 0;
  const discharged = k >= 76 && k < 92 ? 0.2 : 0;
  return { gen, feed, grid, charged, discharged };
}
const price = (k) => (k === 50 ? -1.5 : k === 70 ? null : 8 + (k % 7));

function totalsOf(slots) {
  const s = { gen: 0, feed: 0, grid: 0, charged: 0, discharged: 0 };
  slots.filter(x => x.flow).forEach(x => Object.keys(s).forEach(k => { s[k] += x[k]; }));
  const flow = EF.flowsFromTotals(s);
  return { ...s, flow, house: flow.house, autarky: flow.autarky };
}

function makeDay(date, { times, gaps = [] } = {}) {
  const slots = times.map((tt, k) => {
    const v = valuesAt(k % 96);
    if (gaps.includes(k)) return { ...tt, gen: v.gen, feed: v.feed, grid: null, charged: v.charged, discharged: v.discharged, house: null, price: price(k), flow: null };
    const flow = EF.flowsFromTotals(v);
    return { ...tt, ...v, house: flow.house, price: price(k), flow };
  });
  return {
    mode: 'day', date, month: date.slice(0, 7), timezone: 'Europe/Berlin', step_minutes: 15, slots,
    totals: totalsOf(slots), completeness: { slots: slots.length, complete: slots.filter(s => s.flow).length, price: slots.filter(s => s.price != null).length },
    kinds: { gen: true, feed: true, battery: true, price: true }, missing: [],
  };
}
const normal = Array.from({ length: 96 }, (_, k) => ({ t: hhmm(k * 15) }));
// Herbst: 2:00–2:45 zweimal, das zweite Mal mit `repeat`
const fallBack = [];
for (let k = 0; k < 100; k++) {
  if (k < 12) fallBack.push({ t: hhmm(k * 15) });
  else if (k < 16) fallBack.push({ t: hhmm((k - 4) * 15), repeat: true });
  else fallBack.push({ t: hhmm((k - 4) * 15) });
}
const DAY = {
  '2025-06-13': makeDay('2025-06-13', { times: normal }),
  '2025-06-14': makeDay('2025-06-14', { times: normal, gaps: [60, 61] }),
  '2025-10-26': makeDay('2025-10-26', { times: fallBack }),
};
const TYP = {
  '2025-06': { ...makeDay('2025-06-30', { times: normal }), mode: 'typical', month: '2025-06', days: 30 },
  '2025-10': { ...makeDay('2025-10-31', { times: normal }), mode: 'typical', month: '2025-10', days: 1 },
};
for (const k of Object.keys(TYP)) { delete TYP[k].date; TYP[k].slots.forEach(s => { s.n = 30; }); }
const DAYS = {
  available: true, days: ['2025-06-13', '2025-06-14', '2025-10-26'], complete_days: ['2025-06-13'],
  months: ['2025-06', '2025-10'], default_day: '2025-06-14', default_month: '2025-10',
};
const NO_DAYS = { available: false, days: [], complete_days: [], months: [], default_day: null, default_month: null };

let serverDays = DAYS;
const calls = [];
global.fetch = async (url) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  calls.push(u);
  const ok = (data) => new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  let q;
  if (u.includes('/api/energy-flow/days')) return ok(serverDays);
  if ((q = u.match(/energy-flow\/day\?date=([\d-]+)/)) && DAY[q[1]]) return ok(DAY[q[1]]);
  if (q) await wait(30);   // ein fehlender Tag antwortet langsam (Wechsel während des Ladens)
  if ((q = u.match(/energy-flow\/typical-day\?month=([\d-]+)/)) && TYP[q[1]]) return ok(TYP[q[1]]);
  return new Response(JSON.stringify({ success: false, error: 'nicht gefunden' }), { status: 404 });
};

const { initI18n, t: tr } = await import(`${JS}/lib/i18n.js`);
const { fmt } = await import(`${JS}/lib/format.js`);
const EX = await import(`${JS}/components/explainer.js`);
await initI18n('de');

// ── 1. Modell ───────────────────────────────────────────────────────────
section('Tagesfilm: Viertelstundenmodell');
const d14 = DAY['2025-06-14'];
const p52 = EF.slotPower(d14.slots[52]);
t('Leistung = kWh × 4 (Sonne → Haus 13:00)', Math.abs(p52.pv_house - d14.slots[52].flow.pv_house * 4) < 1e-9 && p52.autarky === d14.slots[52].flow.autarky);
t('Leistung bei 30-Minuten-Takt = kWh × 2', Math.abs(EF.slotPower(d14.slots[52], 30).house - d14.slots[52].flow.house * 2) < 1e-9);
t('Lücke hat keine Leistung (null, nicht 0)', EF.slotPower(d14.slots[60]) === null);
const cum = EF.cumulative(d14.slots);
t('Aufgelaufen: nur vollständige Viertelstunden', cum[95].n === 94 && cum[61].n === cum[59].n);
t('Aufgelaufen: Bezug über den Tag', Math.abs(cum[95].grid - d14.totals.grid) < 1e-9, `${cum[95].grid} / ${d14.totals.grid}`);
const cfEnd = EF.flowsFromTotals(cum[95]);
t('Summe am Ende = Tagessumme des Servers (Fluss aus Mengen, wie ein Monat)', Math.abs(cfEnd.house - d14.totals.flow.house) < 0.05 && cfEnd.autarky === d14.totals.flow.autarky);
// Netzladung nachts, Entladung abends: über die Mengen bleibt sie Netzstrom
const nightOnly = [
  { t: '02:00', gen: 0, feed: 0, grid: 1.2, charged: 1.0, discharged: 0, flow: EF.flowsFromTotals({ grid: 1.2, charged: 1.0 }) },
  { t: '18:00', gen: 0, feed: 0, grid: 0, charged: 0, discharged: 0.9, flow: EF.flowsFromTotals({ discharged: 0.9 }) },
];
const c2 = EF.cumulative(nightOnly);
const sumOfSlots = nightOnly.reduce((a, s) => ({ gih: a.gih + s.flow.grid_in_house, house: a.house + s.flow.house }), { gih: 0, house: 0 });
t('Netzladung bleibt Netzstrom: Autarkie aus den Mengen 0, Summe der Viertelstunden hätte sie verloren',
  EF.flowsFromTotals(c2[1]).autarky === 0 && 1 - sumOfSlots.gih / sumOfSlots.house > 0.5);
const M14 = EF.dayModel(d14);
t('Knoten: Speicher und Netz → Speicher (Netzladung in einer Viertelstunde)', M14.set.bat && M14.set.gridBat && M14.L.N.bat && M14.L.P.some(p => p.id === 'grid_battery'));
t('Knoten ohne Daten entfallen: keine Wärmepumpe, keine Wallbox', !M14.L.N.hp && !M14.L.N.ev && M14.set.dev.length === 0);
const Mnb = EF.dayModel({ ...makeDay('2025-01-01', { times: normal }), kinds: { battery: false }, slots: normal.map(tt => ({ ...tt, gen: 0, feed: 0, grid: 0.2, charged: 0, discharged: 0, flow: EF.flowsFromTotals({ grid: 0.2 }), price: null })), totals: { flow: EF.flowsFromTotals({ grid: 19.2 }) } });
t('ohne Speicher kein Speicherknoten, ohne Preis keine Preislinie', !Mnb.L.N.bat && !Mnb.hasPrice && !EF.stripSvg(Mnb, 0).includes('ef-strip__price'));
t('Tagesmaximum der Leistung als Maßstab', Math.abs(M14.maxKw - Math.max(...M14.power.filter(Boolean).flatMap(p => ['pv_house', 'pv_battery', 'pv_grid', 'grid_house', 'grid_battery', 'battery_house'].map(k => p[k])))) < 1e-9);
t('Uhrzeit ohne Zusatz', EF.timeText(d14.slots[53]) === '13:15');
t('wiederholte Stunde der Zeitumstellung mit Zusatz', EF.timeText(DAY['2025-10-26'].slots[13]) === tr('explainer.energy.day.repeat', { time: '02:15' })
  && /Zeitumstellung/.test(EF.timeText(DAY['2025-10-26'].slots[13])));
t('ein Tag dauert 15 Sekunden (Doku: „rund 15 Sekunden“)', EF.PLAY_MS === 15000);
t('ohne Abspielen beginnt der Film um 12:00', EF.startIndex(M14) === 48 && EF.startIndex(EF.dayModel(DAY['2025-10-26'])) === 52);
const strip = new DOMParser().parseFromString(EF.stripSvg(M14, 52), 'image/svg+xml');
t('Leiste: eine Lückenmarke für 15:00–15:15', strip.querySelectorAll('.ef-strip__gap').length === 1);
t('Leiste: Preislinie bricht an der fehlenden Viertelstunde (zwei Stücke)', strip.querySelectorAll('.ef-strip__price').length === 2);
t('Leiste: Nulllinie bei negativem Preis', strip.querySelectorAll('.ef-strip__zero').length === 1);
const cx = Number(strip.querySelector('.ef-strip__cursor').getAttribute('x1'));
t('Leiste: Cursor auf 13:00', Math.abs(cx - (4 + 312 * 52 / 95)) < 0.1, String(cx));

// ── 2. Bedienung ────────────────────────────────────────────────────────
const month = (y, mo, gen, feed, grid) => ({
  ym: `${y}-${String(mo).padStart(2, '0')}`, year: y, month: mo, covered: true,
  erzeugung_kwh: gen, einspeisung_kwh: feed, bezug_kwh: grid, battery: { charged_kwh: 100, discharged_kwh: 90 },
  heat_pump_kwh: null, ev_charger_kwh: null,
  flow: { ...EF.flowsFromTotals({ gen, feed, grid, charged: 100, discharged: 90 }), heat_pump: null, ev_charger: null },
});
const monthly = [];
for (let mo = 1; mo <= 12; mo++) monthly.push(month(2025, mo, 300 + 60 * mo, 100 + 20 * mo, 400));
const yearFlow = EF.flowsFromTotals({ gen: 8280, feed: 2760, grid: 4800, charged: 1200, discharged: 1080 });
const summary = { monthly, yearly: [{ year: 2025, months_covered: 12, battery: {}, flow: { ...yearFlow, months: 12 } }] };

const mount = async (id) => {
  document.body.innerHTML = `<div id="${id}">${EX.energyHtml(summary, { year: 2025 })}</div>`;
  const fig = document.querySelector('.ex-energy');
  EX.playExplainers(document.getElementById(id));
  await EF.dayReady(fig);
  return fig;
};
const key = (el, k) => el.dispatchEvent(new window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));

section('Tagesfilm: „Tag“ nur mit Viertelstunden');
window.matchMedia = () => ({ matches: false });
serverDays = NO_DAYS;
let fig = await mount('r0');
t('ohne Viertelstunden: „Tag“ verborgen', fig.querySelector('[data-ef-scope="day"]')?.hidden === true);
serverDays = DAYS;
const before = calls.length;
document.body.innerHTML = `<div id="r00">${EX.energyHtml(summary.yearly[0])}</div>`;
EX.playExplainers(document.getElementById('r00'));
await wait(5);
t('Einzelbild ohne Zeitraumwahl (Druck): keine Abfrage, kein „Tag“', calls.length === before && !document.querySelector('[data-ef-scope="day"]'));

section('Tagesfilm: Ein Tag');
fig = await mount('r1');
const dayBtn = fig.querySelector('[data-ef-scope="day"]');
t('mit Viertelstunden: „Tag“ sichtbar', dayBtn && !dayBtn.hidden);
t('… vorher stehen Auswahl und Abspieler leer und verborgen', fig.querySelector('.ef-day').hidden && fig.querySelector('.ef-player').hidden);
dayBtn.click();
await wait(10);
const label = () => fig.querySelector('.ef-period__label').textContent;
const slider = () => fig.querySelector('.ef-slider');
t('Vorbelegung: letzter vollständiger Tag des Servers (default_day)', calls.some(u => u.includes('day?date=2025-06-14')) && /14\. Juni 2025/.test(label()), label());
t('„Tag“ gedrückt, Jahr nicht', dayBtn.getAttribute('aria-pressed') === 'true' && fig.querySelector('[data-ef-scope="year"]').getAttribute('aria-pressed') === 'false');
t('Schieberegler über alle Viertelstunden, Name „Uhrzeit“', slider()?.getAttribute('max') === '95' && slider().getAttribute('aria-label') === 'Uhrzeit');
const play = fig.querySelector('.ef-play');
t('spielt von selbst ab 00:00 (Knopf heißt „Anhalten“)', play?.getAttribute('aria-label') === 'Anhalten');
await wait(420);
const ran = Number(slider().value);
t('… rund 15 s je Tag: nach 0,4 s zwei bis vier Viertelstunden weiter', ran >= 2 && ran <= 4, String(ran));
t('… aria-valuetext folgt der Uhrzeit', slider().getAttribute('aria-valuetext') === hhmm(ran * 15), slider().getAttribute('aria-valuetext'));
key(slider(), ' ');
const held = Number(slider().value);
await wait(350);
t('Leertaste hält an', play.getAttribute('aria-label') === 'Abspielen' && Number(slider().value) === held);
key(slider(), 'ArrowRight');
t('Pfeil rechts: eine Viertelstunde weiter', Number(slider().value) === held + 1 && slider().getAttribute('aria-valuetext') === hhmm((held + 1) * 15));
key(slider(), 'ArrowLeft'); key(slider(), 'ArrowLeft');
t('Pfeil links: eine zurück', Number(slider().value) === held - 1);
key(slider(), 'PageUp');
t('Bild auf: eine Stunde weiter', Number(slider().value) === held + 3);
key(slider(), 'End');
t('Ende: 23:45', slider().getAttribute('aria-valuetext') === '23:45');
key(slider(), 'Home');
t('Pos1: 00:00', Number(slider().value) === 0);
key(play, 'ArrowRight');
t('Pfeile auch auf dem Abspielknopf', Number(slider().value) === 1);
slider().value = '52';
slider().dispatchEvent(new window.Event('input', { bubbles: true }));
const txt = (k) => fig.querySelector(`[data-k="${k}"]`)?.textContent;
t('13:00: Sonne in kW (2,0 kWh × 4)', txt('v-pv') === fmt.num(8, 1) && txt('u-pv') === 'kW', `${txt('v-pv')} ${txt('u-pv')}`);
t('13:00: Haus in kW', txt('v-house') === fmt.num(d14.slots[52].flow.house * 4, 1), txt('v-house'));
t('13:00: Einspeisung ↑ in kW am Netz', txt('v-grid-out') === `↑${fmt.num(1.6, 1)}`, txt('v-grid-out'));
const c52 = EF.flowsFromTotals(cum[52]);
const houseChip = fig.querySelector('.ef-chip[data-node="house"]').textContent;
t('Knöpfe: aufgelaufene Summen bis 13:00 (kWh)', houseChip.includes(fmt.unit(c52.house, 'kWh', 1)) && houseChip.includes(fmt.pct(c52.autarky, 0)), houseChip);
t('Zeile „Bis 13:00 aufgelaufen:“', fig.querySelector('.ef-sums').textContent === tr('explainer.energy.day.sums', { time: '13:00' }) && !fig.querySelector('.ef-sums').hidden);
t('Text nennt Tag und Uhrzeit', fig.querySelector('.explainer__text').textContent.startsWith(tr('explainer.energy.day.until', { period: label(), time: '13:00' })));
t('Börsenpreis 13:00 läuft mit', fig.querySelector('.ef-price').textContent === tr('explainer.energy.day.price', { time: '13:00', price: fmt.unit(d14.slots[52].price, 'ct/kWh', 1) }));
t('Liste für Screenreader in kW', /kW/.test(fig.querySelector('.ef-list').textContent) && fig.querySelectorAll('.ef-list li').length > 0);
const sv = fig.querySelector('svg.ef');
t('Bänder gegen das Tagesmaximum: nachts schmal, mittags breit', (() => {
  const w = (k) => Number(/stroke-width:([\d.]+)/.exec(fig.querySelector(`[data-k="band-${k}"]`).getAttribute('style'))[1]);
  const noon = w('pv_house');
  slider().value = '4'; slider().dispatchEvent(new window.Event('input', { bubbles: true }));
  // 0,2 kWh × 4 = 0,8 kW gegen das Maximum des Tages, nicht gegen das der Viertelstunde
  const expect = 1.5 + 6.5 * 0.8 / M14.maxKw;
  return noon > 7.5 && Math.abs(w('grid_house') - expect) < 0.06 && fig.querySelector('[data-flow="pv_house"]').classList.contains('ef-flow--off');
})(), `Maximum ${M14.maxKw} kW`);
t('Bild an Ort und Stelle (dasselbe SVG-Element)', fig.querySelector('svg.ef') === sv);
slider().value = '60'; slider().dispatchEvent(new window.Event('input', { bubbles: true }));
t('Lücke 15:00: „–“ statt 0 in den Knoten', txt('v-pv') === '–' && txt('v-house') === '–');
t('… und im Text genannt', fig.querySelector('.explainer__text').textContent.startsWith(tr('explainer.energy.day.gapAt', { time: '15:00' })));
t('… Hinweis zählt die Lücken', fig.querySelector('.ef-note').textContent.includes('2 Viertelstunden ohne Messwerte'));
slider().value = '70'; slider().dispatchEvent(new window.Event('input', { bubbles: true }));
t('fehlender Börsenpreis wird gesagt, nicht 0', fig.querySelector('.ef-price').textContent === tr('explainer.energy.day.noPrice', { time: '17:30' }));
t('Hinweis: kW in den Knoten, gerechnet je Viertelstunde', fig.querySelector('.ef-note').textContent.includes('(kW)') && fig.querySelector('.ef-note').textContent.includes(tr('explainer.energy.day.calcNote')));
const dateSel = fig.querySelector('.ef-day-date');
t('Tagesauswahl: nur Tage des Monats mit Daten, Lücken gekennzeichnet', dateSel && dateSel.options.length === 2 && dateSel.value === '2025-06-14'
  && dateSel.options[1].textContent.includes('mit Lücken') && !dateSel.options[0].textContent.includes('mit Lücken'));
fig.querySelector('.ef-chip[data-node="pv"]').click();
t('Hervorhebung wirkt im Film', fig.querySelector('svg.ef').getAttribute('data-focus') === 'pv');
fig.querySelector('[data-ef-step="-1"]').click();
await wait(10);
t('‹ springt auf den vorigen Tag mit Daten', /13\. Juni 2025/.test(label()) && fig.querySelector('[data-ef-step="-1"]').disabled, label());
key(fig.querySelector('.ef-play'), ' ');
t('Leertaste auf dem Knopf: der Klick entscheidet (kein Doppelschalten)', fig.querySelector('.ef-play').getAttribute('aria-label') === 'Anhalten');
fig.querySelector('.ef-play').click();
t('Knopf hält an', fig.querySelector('.ef-play').getAttribute('aria-label') === 'Abspielen');

const monthSel = fig.querySelector('.ef-day-month');
monthSel.value = '2025-10';
monthSel.dispatchEvent(new window.Event('change', { bubbles: true }));
await wait(10);
t('Monat Oktober: letzter Tag mit Daten (ohne vollständigen)', calls.some(u => u.includes('day?date=2025-10-26')) && /26\. Oktober 2025/.test(label()), label());
t('Zeitumstellung: 100 Viertelstunden', slider().getAttribute('max') === '99');
fig.querySelector('.ef-play').click();
slider().value = '13'; slider().dispatchEvent(new window.Event('input', { bubbles: true }));
t('… die zweite 2:15 heißt „nach der Zeitumstellung“', slider().getAttribute('aria-valuetext') === tr('explainer.energy.day.repeat', { time: '02:15' }));

section('Tagesfilm: Typischer Tag');
fig.querySelector('[data-ef-mode="typical"]').click();
await wait(10);
t('Typischer Tag des gewählten Monats', calls.some(u => u.includes('typical-day?month=2025-10')) && label() === tr('explainer.energy.day.typicalPeriod', { month: 'Oktober 2025' }), label());
t('… ohne Tagesauswahl, mit Monat und Erklärung (ⓘ)', !fig.querySelector('.ef-day-date') && !!fig.querySelector('.ef-day-month') && !!fig.querySelector('.ef-day [data-glossary="typicalDay"]'));
t('… Hinweis: Mittel aus wie vielen Tagen', fig.querySelector('.ef-note').textContent.includes(tr('explainer.energy.day.typicalDays.one', { count: 1 })));
t('… Modus-Knöpfe mit aria-pressed', fig.querySelector('[data-ef-mode="typical"]').getAttribute('aria-pressed') === 'true' && fig.querySelector('[data-ef-mode="single"]').getAttribute('aria-pressed') === 'false');
fig.querySelector('[data-ef-step="-1"]').click();
await wait(10);
t('‹ springt beim typischen Tag einen Monat zurück', label() === tr('explainer.energy.day.typicalPeriod', { month: 'Juni 2025' }), label());
fig.querySelector('[data-ef-mode="single"]').click();
await wait(10);
t('zurück zu „Ein Tag“: Tag im gewählten Monat (letzter vollständiger)', /13\. Juni 2025/.test(label()), label());

section('Tagesfilm: zurück zu Jahr und Monat');
fig.querySelector('[data-ef-scope="month"]').click();
t('Monat aus dem Tag: Juni', /Juni 2025/.test(label()) && !/13\./.test(label()) && fig.querySelector('.ef-day').hidden && fig.querySelector('.ef-player').hidden && fig.querySelector('.ef-sums').hidden, label());
t('… Knoten zeigen wieder kWh', txt('u-pv') === 'kWh');
fig.querySelector('[data-ef-scope="day"]').click();
await wait(10);
t('erneut „Tag“: gemerkter Tag ohne neue Abfrage', /13\. Juni 2025/.test(label()) && calls.filter(u => u.includes('day?date=2025-06-13')).length === 1);
fig.querySelector('[data-ef-scope="year"]').click();
const afterYear = Number(slider()?.value ?? -1);
await wait(300);
t('Jahr: Film angehalten, Bedienung verborgen', label() === '2025' && fig.querySelector('.ef-player').hidden && Number(slider()?.value ?? -1) === afterYear);

section('Tagesfilm: Bewegung reduzieren');
window.matchMedia = (q) => ({ matches: /reduce/.test(q) });
fig = await mount('r2');
fig.querySelector('[data-ef-scope="day"]').click();
await wait(10);
t('kein Abspielknopf, nur der Schieberegler', !fig.querySelector('.ef-play') && !!fig.querySelector('.ef-slider'));
t('beginnt um 12:00', fig.querySelector('.ef-slider').getAttribute('aria-valuetext') === '12:00');
await wait(350);
t('kein Abspielen von selbst', fig.querySelector('.ef-slider').value === '48');
t('keine laufenden Punkte im Bild', fig.querySelectorAll('svg.ef .ef-dot').length === 0);
key(fig.querySelector('.ef-slider'), ' ');
await wait(200);
t('Leertaste startet nichts', fig.querySelector('.ef-slider').value === '48');
key(fig.querySelector('.ef-slider'), 'ArrowRight');
t('Pfeile gehen weiter', fig.querySelector('.ef-slider').getAttribute('aria-valuetext') === '12:15');
window.matchMedia = () => ({ matches: false });

section('Tagesfilm: Server antwortet nicht');
fig = await mount('r3');
DAY['2025-06-14'] = undefined;
fig.querySelector('[data-ef-scope="day"]').click();
await wait(60);
const status = fig.querySelector('.ef-day__status');
t('Fehler wird gesagt, kein leerer Film', status && !status.hidden && status.textContent.length > 0 && fig.querySelector('.ef-player').hidden);
fig = await mount('r4');
fig.querySelector('[data-ef-scope="day"]').click();
fig.querySelector('[data-ef-scope="year"]').click();
await wait(60);
t('Fehler nach dem Wechsel zurück zum Jahr: Auswahl bleibt verborgen', fig.querySelector('.ef-day').hidden && fig.querySelector('.ef-period__label').textContent === '2025');

const css = readFileSync(resolve(ROOT, 'public', 'css', 'components.css'), 'utf8');
t('CSS: Film ohne Übergänge bei „Bewegung reduzieren“', /@media \(prefers-reduced-motion: reduce\)\s*\{\s*\.ef--film \.ef \.ef-band[^}]*transition: none;/.test(css));
t('CSS: Bedienung im Druck ausgeblendet', /@media print \{ \.ef-day, \.ef-player, \.ef-sums \{ display: none; \} \}/.test(css));

console.log(`\nday-film.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
