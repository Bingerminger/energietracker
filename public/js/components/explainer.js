// =====================================================================
// v3.2.0 (F1020) — Schaubilder: Erklärgrafiken mit den eigenen Daten.
//
// Vier Bilder, die eine Frage beantworten, statt eine Reihe zu zeigen:
//   moneyHtml    — Wohin geht mein Geld? Abschläge gegen Kosten bis zum
//                  Abrechnungsstichtag, Guthaben oder Nachzahlung.
//   energyHtml   — Energiefluss im Haus: PV → Haus, Speicher, Netz.
//   weatherHtml  — Kälter oder mehr verbraucht? Verbrauch, Heizgradtage und
//                  das witterungsbereinigte Ergebnis.
//   timelineHtml — Vertrags-Zeitstrahl: Beginn, heute, Kündigungsfrist, Ende,
//                  Preiserhöhung.
//
// SVG mit CSS-Animation, ohne Bibliothek. Die Bewegung beginnt, wenn das Bild
// sichtbar wird (playExplainers), und entfällt bei „Bewegung reduzieren“ —
// dann steht gleich das Endbild. Ohne Skript (Druck) ist das Endbild zu
// sehen: Ausgeblendet wird erst, wenn playExplainers die Bilder übernimmt.
// Jedes Bild trägt seine Aussage als Text (figcaption); die Grafik selbst
// ist für Screenreader ausgeblendet.
//
// v3.4.0 (F1026) — zweite Fassung: Energiefluss neu (components/energy-flow.js,
// Jahr und Monat), Zeitstrahl mit Mindestlaufzeit, Preisgarantie und
// Folgevertrag, Beschriftungen ohne Überlappung in allen Sprachen (Geld und
// Wetter: Beschriftung über dem Balken, Legende bricht um). Schriftgrößen
// stehen als Attribut am Text, damit die Prüfung (tests/explainer.test.mjs)
// mit denselben Maßen rechnet wie das Bild.
// =====================================================================

import { t, tp } from '../lib/i18n.js';
import { fmt, escapeHtml, todayIso } from '../lib/format.js';
import { lastMonths, yoyTrend, isPartial, daysInMonth } from '../lib/chart-data.js';
import { energyFigureHtml, bindEnergyFigure, startEnergyFigure } from './energy-flow.js';

const esc = escapeHtml;
const W = 320;
/** v3.9.0 (Skeptiker N13) — Schwelle für „wirklich mehr/weniger“ in Standardabweichungen (80 %, wie das Prognoseband). */
export const WEATHER_VERDICT_Z = 1.28;

function figure(cls, title, svg, caption) {
  return `<figure class="explainer ${cls}">
    <figcaption class="explainer__title">${esc(title)}</figcaption>
    <svg class="explainer__svg" viewBox="0 0 ${W} ${svg.h}" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">${svg.body}</svg>
    <p class="explainer__text">${esc(caption)}</p>
  </figure>`;
}

// Schriftgrößen (Einheiten der viewBox) und geschätzte Zeichenbreite: Sans
// 0,56 em, Ziffernschrift 0,6 em — mit 10 % Reserve für die Anordnung
const FS = { label: 11, small: 9.5, value: 10.5 };
const textWidth = (text, fs, mono = false) => [...String(text)].length * fs * (mono ? 0.6 : 0.56) * 1.1;

/** Text im SVG: Werte und Beschriftungen (nur Anzeige, die Aussage steht im Text darunter). */
const label = (x, y, text, cls = '', anchor = 'start') => {
  const fs = /ex-value/.test(cls) ? FS.value : /ex-small/.test(cls) ? FS.small : FS.label;
  return `<text x="${typeof x === 'number' ? +x.toFixed(1) : x}" y="${y}" class="ex-label ${cls}" font-size="${fs}" text-anchor="${anchor}">${esc(text)}</text>`;
};

/**
 * Legende, die umbricht, statt in das nächste Feld zu laufen.
 * @param {Array<{text: string, swatch: string}>} items  swatch = Attribute des Farbfelds
 * @returns {{svg: string, h: number}}
 */
function legend(items, y0, d = '.9s') {
  let x = 0, y = y0;
  const out = items.map((it) => {
    const w = 14 + textWidth(it.text, FS.small) + 12;
    if (x > 0 && x + w > W) { x = 0; y += 16; }
    const s = `<rect x="${x}" y="${y}" width="10" height="10" rx="2" ${it.swatch}/>${label(x + 14, y + 9, it.text, 'ex-small')}`;
    x += w;
    return s;
  });
  return { svg: `<g class="ex-legend ex-fade" style="--d:${d}">${out.join('')}</g>`, h: y + 10 - y0 };
}

// ── 1. Wohin geht mein Geld ─────────────────────────────────────────────

/**
 * @param {object} row  Zeile aus contract-status (laufender Vertrag):
 *   balance_path (Abrechnungszeitraum, letzter Punkt = erwartete Abrechnung),
 *   energy_cost_to_date, base_to_date, bonus_to_date, projected_end_balance.
 *   Nicht advance_paid: das zählt über die ganze Laufzeit des Vertrags.
 * @param {{color?: string}} [opts]  Farbe der Verbrauchsart (u.color)
 * @returns {string} leer, wenn die Zahlen fehlen
 */
export function moneyHtml(row, { color, utilityLabel = null } = {}) {
  const end = (row?.balance_path || []).slice(-1)[0];
  if (!row || !end || row.projected_end_balance == null) return '';
  const paid = Math.max(0, Number(end.paid) || 0);
  const cost = Math.max(0, Number(end.cost) || 0);
  const energy = Math.min(cost, Math.max(0, Number(row.energy_cost_to_date) || 0));
  const base = Math.min(cost - energy, Math.max(0, (Number(row.base_to_date) || 0) - (Number(row.bonus_to_date) || 0)));
  const rest = Math.max(0, cost - energy - base);
  if (!(paid > 0 || cost > 0)) return '';
  const max = Math.max(paid, cost, 1);
  // v3.4.0 (F1026) — Beschriftung und Betrag stehen über dem Balken, der Balken
  // nutzt die ganze Breite: „Advance payments“ lief vorher in den Balken, „0 €“
  // auf die Beschriftung
  const x0 = 0;
  const w = (v) => Math.max(0, v / max * W);
  const c = color || 'var(--accent)';
  const balance = Number(row.projected_end_balance);
  // positiv = Nachzahlung (Kosten über dem Bezahlten), negativ = Guthaben
  const credit = balance < 0;
  const word = (v) => Math.abs(v) < 1 ? t('explainer.money.even')
    : t(v < 0 ? 'explainer.money.credit' : 'explainer.money.due', { amount: fmt.eur0(Math.abs(v)) });   // v3.9.0 (L2) — „rund“ ohne Cent
  // v3.7.0 (wirklichkeit-2, Schritt 3) — unsichere Schätzung (projection_uncertain):
  // Kosten und Ergebnis als Spanne, keine Lücke, die einen Punktwert behauptet
  const range = Array.isArray(row.projection_uncertain?.projected_range) ? row.projection_uncertain.projected_range.map(Number) : null;
  const result = range ? t('explainer.money.uncertain', { low: word(Math.min(...range)), high: word(Math.max(...range)) }) : word(balance);
  const costText = range
    ? t('explainer.money.costRange', { low: fmt.eur0(Math.max(0, cost + Math.min(...range) - balance)), high: fmt.eur0(Math.max(0, cost + Math.max(...range) - balance)) })
    : fmt.eur(cost);
  const gapX = x0 + Math.min(w(paid), w(cost)), gapW = range ? 0 : Math.abs(w(paid) - w(cost));
  const leg = legend([
    { text: t('explainer.money.energy'), swatch: `style="fill:${c}"` },
    { text: t('explainer.money.base'), swatch: `style="fill:${c}; opacity:.55"` },
    { text: t('explainer.money.estimate'), swatch: `class="ex-bar--estimate" style="fill:${c}"` },
  ], 96);
  const body = `
    ${label(0, 12, t('explainer.money.paid'))}
    ${label(W, 12, fmt.eur(paid), 'ex-fade ex-value', 'end')}
    <rect class="ex-grow ex-bar--paid" style="--d:.0s" x="${x0}" y="18" width="${w(paid).toFixed(1)}" height="18" rx="3"/>
    ${label(0, 56, t('explainer.money.cost'))}
    ${label(W, 56, costText, 'ex-fade ex-value', 'end')}
    <rect class="ex-grow" style="--d:.25s; fill:${c}" x="${x0}" y="62" width="${w(energy).toFixed(1)}" height="18" rx="3"/>
    <rect class="ex-grow" style="--d:.45s; fill:${c}; opacity:.55" x="${(x0 + w(energy)).toFixed(1)}" y="62" width="${w(base).toFixed(1)}" height="18"/>
    <rect class="ex-grow ex-bar--estimate" style="--d:.65s; fill:${c}" x="${(x0 + w(energy) + w(base)).toFixed(1)}" y="62" width="${w(rest).toFixed(1)}" height="18"/>
    ${gapW > 2 ? `<rect class="ex-fade ${credit ? 'ex-gap--credit' : 'ex-gap--due'}" style="--d:1s" x="${gapX.toFixed(1)}" y="${credit ? 38 : 82}" width="${gapW.toFixed(1)}" height="5" rx="2.5"/>` : ''}
    ${leg.svg}`;
  // v3.9.0 (L1) — der Abrechnungstag des Vertragsstatus (letzter Tag des Zeitraums), sonst das Ende des letzten Monats
  const caption = t('explainer.money.text', {
    date: fmt.date(row.settle_on || `${end.ym}-${String(daysInMonth(end.ym)).padStart(2, '0')}`), paid: fmt.eur(paid), cost: costText, result,
  });
  // v3.9.0 (Skeptiker N10) — auf der Übersicht mit der Verbrauchsart (dort steht nur ein Vertrag)
  return figure('ex-money', utilityLabel ? `${t('explainer.money.title')} · ${utilityLabel}` : t('explainer.money.title'), { h: 96 + leg.h + 4, body }, caption);
}

// ── 2. Energiefluss im Haus ─────────────────────────────────────────────

/**
 * v3.4.0 (F1026) — zweite Fassung in components/energy-flow.js.
 * @param {object} input  pv-summary-Antwort ({monthly, yearly}: Jahr und Monat
 *   umschaltbar) oder eine einzelne Jahreszeile (nur dieses Jahr)
 * @param {{year?: number}} [opts]  gewünschtes Jahr
 * @returns {string} leer ohne Erzeugung
 */
export function energyHtml(input, opts = {}) {
  return energyFigureHtml(input, opts);
}

// ── 3. Kälter oder mehr verbraucht ──────────────────────────────────────

/**
 * @param {Array<object>} monthly  Monatszeilen eines Heizzählers (kwh, hdd, heat_adjusted)
 * @param {{quality?: object}} [opts]  `anomaly_quality` des Zählers: Besteht das Heizmodell die
 *   Gütesperre nicht (v3.7.0, Nachlauf), gibt es keine bereinigte Aussage — wie im Jahresbericht
 * @returns {string} leer ohne Vorjahr
 */
export function weatherHtml(monthly, { quality } = {}) {
  // Nur Monate mit bereinigtem Wert, ganze Monate: Alle drei Vergleiche laufen
  // über dieselben Monate (und dieselben Monate des Vorjahres)
  const rows = (monthly || []).filter(m => m && m.ym && m.heat_adjusted != null && !isPartial(m));
  const win = lastMonths(rows, 12);
  const within = win?.rows.map(m => m.ym) || null;
  const opts = { months: 12, minMonths: 6, within };
  const cons = yoyTrend(rows, 'kwh', opts);
  const hdd = yoyTrend(rows, 'hdd', opts);
  const adj = yoyTrend(rows, 'kwh', { ...opts, adjustedKey: 'heat_adjusted' });
  if (!cons || !hdd || !adj?.adjusted) return '';
  // Schreibweise der Sprache (12 % / 12%), mit Vorzeichen
  const p = (v) => (v > 0 ? '+' : '') + fmt.pct(v / 100, 0);
  const max = Math.max(cons.current, cons.previous, 1);
  const maxH = Math.max(hdd.current, hdd.previous, 1);
  // v3.4.0 (F1026) — Beschriftung über den Balken; die Balken lassen rechts
  // Platz für die längere der beiden Prozentzahlen (+250 % lief über den Rand)
  const pc = p(cons.pct), ph = p(hdd.pct);
  const x0 = 0, BW = W - Math.max(textWidth(pc, FS.value, true), textWidth(ph, FS.value, true)) - 6;
  const bar = (y, v, m, cls, d) => `<rect class="ex-grow ${cls}" style="--d:${d}s" x="${x0}" y="${y}" width="${(v / m * BW).toFixed(1)}" height="11" rx="2"/>`;
  const poor = quality?.model_quality === 'poor';
  // v3.9.0 (Skeptiker N13) — „wirklich weniger“ nur außerhalb der üblichen Schwankung: Streut ein
  // Monat um ±ratio (Gütesperre), streut der Vergleich zweier Summen über n Monate um ratio × √(2/n);
  // mit 1,28 σ (80 %, wie das Prognoseband) wurde aus −4 % bei ±9 % je Monat kein Befund mehr
  const n = adj.months?.length || 12;
  const noise = Number.isFinite(Number(quality?.ratio)) ? WEATHER_VERDICT_Z * Number(quality.ratio) * Math.sqrt(2 / n) * 100 : 0;
  const limit = Math.max(2, noise);
  const verdict = poor ? 'same' : adj.pct < -limit ? 'less' : adj.pct > limit ? 'more' : 'same';
  const inNoise = !poor && verdict === 'same' && Math.abs(adj.pct) > 2;
  const badge = poor ? t('explainer.weather.poorFitBadge') : t('explainer.weather.adjusted', { pct: p(adj.pct) });
  const bw = Math.min(W, Math.max(170, textWidth(badge, FS.label) + 24));
  const leg = legend([
    { text: t('explainer.weather.before'), swatch: 'class="ex-bar--prev"' },
    { text: t('explainer.weather.now'), swatch: 'class="ex-bar--now"' },
  ], 138);
  const body = `
    ${label(0, 10, t('explainer.weather.usage'))}
    ${bar(15, cons.previous, max, 'ex-bar--prev', 0)}${bar(29, cons.current, max, 'ex-bar--now', .2)}
    ${label(x0 + cons.current / max * BW + 4, 38, pc, 'ex-fade ex-value')}
    ${label(0, 60, t('explainer.weather.hdd'))}
    ${bar(65, hdd.previous, maxH, 'ex-bar--prev', .4)}${bar(79, hdd.current, maxH, 'ex-bar--cold', .6)}
    ${label(x0 + hdd.current / maxH * BW + 4, 88, ph, 'ex-fade ex-value')}
    <g class="ex-fade" style="--d:1s">
      <rect class="ex-badge ex-badge--${verdict}" x="${((W - bw) / 2).toFixed(1)}" y="102" width="${bw.toFixed(1)}" height="24" rx="12"/>
      ${label(W / 2, 118, badge, 'ex-badge__text', 'middle')}
    </g>
    ${leg.svg}`;
  const caption = poor
    ? t('explainer.weather.text.poorFit', { cons: p(cons.pct), hdd: p(hdd.pct), pct: fmt.pct(Number(quality.ratio) || 0, 0) })
    : inNoise ? tp('explainer.weather.text.noise', n, { cons: p(cons.pct), hdd: p(hdd.pct), adj: p(adj.pct), noise: fmt.pct(noise / 100, 0), count: n })
    : t(`explainer.weather.text.${verdict}`, { cons: p(cons.pct), hdd: p(hdd.pct), adj: p(adj.pct) });
  return figure('ex-weather', t('explainer.weather.title'), { h: 138 + leg.h + 4, body }, caption);
}

// ── 4. Vertrags-Zeitstrahl ──────────────────────────────────────────────
//
// v3.4.0 (F1026) — zweite Fassung. Was der Vertrag vorhat, steht in
// `contract-status`; das Bild unterscheidet:
//   befristet          Kündigen bis (cancel_by) → Ende (end); Stichtag verpasst
//                      → eigener Text; verlängert sich nicht (auto_renews:
//                      false) → kein Stichtag, endet ohnehin
//   verlängert         Laufzeit abgelaufen, läuft weiter (renewed): jederzeit
//                      kündbar, frühestens Ende = switch_date − 1
//   unbefristet        jederzeit kündbar, frühestens Ende = switch_date − 1
//                      (mit Mindestlaufzeit deren Ende); ohne Frist kein Termin
// Dazu Mindestlaufzeit, Preisgarantie (Band auf der Achse), Preiserhöhung und
// der Folgevertrag (`successor`, sobald contract-status ihn liefert).
// Die Achse zeigt ein Fenster um heute (gut ein Jahr zurück, gut zwei voraus);
// was davor oder danach liegt, sitzt am Rand. Beschriftungen liegen nach Nähe
// auf Ebenen über und unter der Achse, am Rand links- bzw. rechtsbündig.

const DAY = 86400000;
const isDate = (s) => typeof s === 'string' && /^\d{4}-\d{2}-\d{2}/.test(s);
const dayNum = (iso) => { const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number); return Date.UTC(y, m - 1, d) / DAY; };
const isoOf = (n) => new Date(n * DAY).toISOString().slice(0, 10);   // Tageszahl, keine Zeitzone
const addDays = (iso, n) => isoOf(dayNum(iso) + n);

/** Welche Art Vertrag das Bild erzählt (s. o.). */
function timelineKind(row) {
  const basis = row.notice_basis || null;
  const earliest = isDate(row.switch_date) ? addDays(row.switch_date, -1) : null;
  if ((row.renewed || basis === 'renewed') && earliest) return { kind: 'renewed', earliest };
  if (row.is_open_ended || basis === 'open_ended' || basis === 'min_term') {
    if (!earliest) return { kind: 'running', earliest: null };
    return { kind: basis === 'min_term' ? 'minTerm' : 'anytime', earliest };
  }
  if (!isDate(row.end)) return { kind: 'running', earliest: null };
  if (row.auto_renews === false) return { kind: 'noRenew', earliest: null };
  if (isDate(row.cancel_by)) return { kind: row.cancel_missed ? 'missed' : 'fixed', earliest: null };
  return { kind: 'noNotice', earliest: null };
}

/**
 * @param {object} row  Zeile aus contract-status: start, end, is_open_ended,
 *   renewed, notice_basis, cancel_by, cancel_missed, switch_date, auto_renews,
 *   min_term_end, price_guarantee_until, price_increase; optional
 *   successor {contract_id, provider, start, gap_days}
 * @param {string} [today]  JJJJ-MM-TT in Ortszeit
 */
export function timelineHtml(row, today = todayIso()) {
  if (!isDate(row?.start) || !isDate(today)) return '';
  const { kind, earliest } = timelineKind(row);
  const anytime = kind === 'renewed' || kind === 'anytime' || kind === 'minTerm';
  const end = anytime ? earliest : (isDate(row.end) && kind !== 'running' ? row.end : null);
  const succ = row.successor && isDate(row.successor.start) ? row.successor : null;

  const marks = [{ key: 'start', date: row.start }];
  if (kind === 'renewed' && isDate(row.end)) marks.push({ key: 'termEnd', date: row.end });
  if (isDate(row.min_term_end) && row.min_term_end !== end && row.min_term_end !== row.end) marks.push({ key: 'minTerm', date: row.min_term_end });
  if (isDate(row.price_guarantee_until)) marks.push({ key: 'guarantee', date: row.price_guarantee_until });
  if (isDate(row.price_increase?.from)) marks.push({ key: 'price', date: row.price_increase.from });
  if (kind === 'fixed' || kind === 'missed') marks.push({ key: kind === 'missed' ? 'missed' : 'cancel', date: row.cancel_by });
  if (end) marks.push({ key: anytime ? 'earliest' : 'end', date: end });
  if (succ) marks.push({ key: 'successor', date: succ.start });

  // Fenster um heute
  const now = dayNum(today);
  const days = marks.map(m => dayNum(m.date));
  let w0 = Math.max(Math.min(now, ...days), now - 400);
  let w1 = Math.min(Math.max(now, ...days), now + 760);
  if (w1 - w0 < 60) { const pad = (60 - (w1 - w0)) / 2; w0 -= pad; w1 += pad; }
  const PAD = 10, X = (d) => PAD + (Math.min(w1, Math.max(w0, d)) - w0) / (w1 - w0) * (W - 2 * PAD);

  // Beschriftungen: je Marke zwei Zeilen (Name, Datum), heute eine oder zwei
  const items = marks.map((m, i) => ({ ...m, x: X(dayNum(m.date)), clip: dayNum(m.date) < w0 || dayNum(m.date) > w1,
    lines: [t(`explainer.timeline.${m.key}`), fmt.date(m.date)], d: (0.3 + i * 0.15).toFixed(2) }));
  items.push({ key: 'today', x: X(now), lines: anytime ? [t('explainer.timeline.today'), t('explainer.timeline.anytime')] : [t('explainer.timeline.today')], d: '0.2' });
  const lanes = [];                      // je Ebene das rechte Ende der letzten Beschriftung
  const order = ['u0', 'd0', 'u1', 'd1', 'u2', 'd2', 'u3', 'd3'];
  [...items].sort((a, b) => a.x - b.x || (a.key === 'today' ? 1 : -1)).forEach((it) => {
    const w = Math.max(...it.lines.map(s => textWidth(s, FS.small)));
    let anchor = 'middle', tx = it.x;
    if (it.x - w / 2 < 2) { anchor = 'start'; tx = Math.max(2, it.x - 4); }
    else if (it.x + w / 2 > W - 2) { anchor = 'end'; tx = Math.min(W - 2, it.x + 4); }
    const x0 = anchor === 'start' ? tx : anchor === 'end' ? tx - w : tx - w / 2;
    const lane = order.find(l => (lanes[l] ?? -Infinity) + 6 <= x0) || order[order.length - 1];
    lanes[lane] = x0 + w;
    Object.assign(it, { anchor, tx, lane });
  });
  const used = (s) => order.filter(l => l[0] === s && lanes[l] !== undefined).map(l => Number(l[1]));
  const nUp = Math.max(0, ...used('u').map(i => i + 1)), nDown = Math.max(0, ...used('d').map(i => i + 1));
  const A = nUp ? 38 + 26 * (nUp - 1) : 14;
  const H = nDown ? A + 34 + 26 * (nDown - 1) + 6 : A + 14;
  const ys = (it) => {
    const i = Number(it.lane[1]);
    // oben: Name nah an der Achse, Datum darüber; unten: Name, Datum darunter
    return it.lane[0] === 'u' ? [A - 16 - 26 * i, A - 28 - 26 * i] : [A + 22 + 26 * i, A + 34 + 26 * i];
  };

  const xs = (d) => X(dayNum(d)).toFixed(1);
  const ranges = [];
  // Preisgarantie: Band auf der Achse vom Beginn bis zum Ende der Garantie
  if (isDate(row.price_guarantee_until)) ranges.push(`<line class="ex-grow ex-range--guarantee" style="--d:.5s" x1="${xs(row.start)}" y1="${A}" x2="${xs(row.price_guarantee_until)}" y2="${A}"/>`);
  // Folgevertrag: eigene Strecke ab seinem Beginn, eine Lücke davor gestrichelt
  if (succ) {
    ranges.push(`<line class="ex-fade ex-range--next" style="--d:1s" x1="${xs(succ.start)}" y1="${A}" x2="${(W - PAD).toFixed(1)}" y2="${A}"/>`);
    if (end && Number(succ.gap_days) > 0) ranges.push(`<line class="ex-fade ex-range--gap" style="--d:1s" x1="${xs(addDays(end, 1))}" y1="${A}" x2="${xs(succ.start)}" y2="${A}"/>`);
  }
  const cutLeft = dayNum(row.start) < w0, cutRight = days.some(d => d > w1);
  const markSvg = items.filter(it => it.key !== 'today').map((it) => {
    const [ly, dy] = ys(it);
    const past = dayNum(it.date) < now ? ' ex-mark--past' : '';
    return `<g class="ex-fade ex-mark ex-mark--${it.key}${it.clip ? ' ex-mark--clip' : ''}${past}" style="--d:${it.d}s">
      <circle cx="${it.x.toFixed(1)}" cy="${A}" r="5"/>
      ${label(it.tx, ly, it.lines[0], 'ex-small', it.anchor)}
      ${label(it.tx, dy, it.lines[1], 'ex-small ex-muted', it.anchor)}
    </g>`;
  }).join('');
  const today_ = items.find(it => it.key === 'today');
  const [tly, tdy] = ys(today_);
  const body = `
    <line class="ex-axis" x1="${PAD}" y1="${A}" x2="${W - PAD}" y2="${A}"/>
    ${cutLeft ? `<line class="ex-axis ex-axis--cut" x1="0" y1="${A}" x2="${PAD}" y2="${A}"/>` : ''}
    ${cutRight ? `<line class="ex-axis ex-axis--cut" x1="${W - PAD}" y1="${A}" x2="${W}" y2="${A}"/>` : ''}
    ${ranges.join('')}
    <line class="ex-grow ex-progress" style="--d:0s" x1="${xs(isoOf(Math.max(dayNum(row.start), w0)))}" y1="${A}" x2="${today_.x.toFixed(1)}" y2="${A}"/>
    ${markSvg}
    <g class="ex-fade ex-today" style="--d:.2s"><line x1="${today_.x.toFixed(1)}" y1="${A - 10}" x2="${today_.x.toFixed(1)}" y2="${A + 10}"/>
      ${label(today_.tx, tly, today_.lines[0], 'ex-small', today_.anchor)}${today_.lines[1] ? label(today_.tx, tdy, today_.lines[1], 'ex-small ex-muted', today_.anchor) : ''}</g>`;

  // Aussage als Text (die Grafik ist für Screenreader ausgeblendet)
  const D = fmt.date;
  const p = { start: D(row.start), end: end ? D(end) : '–', cancel: isDate(row.cancel_by) ? D(row.cancel_by) : '–' };
  const base = {
    fixed: () => t('explainer.timeline.text', p),
    missed: () => t('explainer.timeline.textMissed', p),
    noRenew: () => t('explainer.timeline.textNoRenew', p),
    noNotice: () => t('explainer.timeline.textNoNotice', p),
    renewed: () => t('explainer.timeline.textRenewed', { ...p, term: D(row.end) }),
    anytime: () => t('explainer.timeline.textAnytime', p),
    minTerm: () => t('explainer.timeline.textMinTerm', p),
    running: () => t('explainer.timeline.textRunning', p),
  }[kind]();
  const extra = [];
  if (marks.some(m => m.key === 'minTerm') && dayNum(row.min_term_end) >= now) extra.push(t('explainer.timeline.textMinTermUntil', { date: D(row.min_term_end) }));
  if (isDate(row.price_guarantee_until) && dayNum(row.price_guarantee_until) >= now) extra.push(t('explainer.timeline.textGuarantee', { date: D(row.price_guarantee_until) }));
  if (isDate(row.price_increase?.from)) extra.push(t('explainer.timeline.textPrice', { date: D(row.price_increase.from) }));
  if (succ) {
    extra.push(succ.provider ? t('explainer.timeline.textSuccessor', { provider: succ.provider, start: D(succ.start) })
      : t('explainer.timeline.textSuccessorPlain', { start: D(succ.start) }));
    if (Number(succ.gap_days) > 0) extra.push(tp('explainer.timeline.gap', Number(succ.gap_days)));
  }
  return figure('ex-timeline', t('explainer.timeline.title'), { h: H, body }, [base, ...extra].join(' '));
}

// ── Abspielen ───────────────────────────────────────────────────────────

/**
 * Bewegung starten, sobald ein Bild sichtbar wird. Erst hier werden die
 * Ausgangszustände gesetzt (`ex--ready`): Ohne Skript bleibt das Endbild.
 * v3.4.0 — der Energiefluss bekommt hier seine Bedienung (Zeitraum,
 * Hervorhebung) und zählt beim Erscheinen hoch.
 * @param {ParentNode} root
 */
export function playExplainers(root) {
  const figs = [...(root?.querySelectorAll?.('.explainer:not(.ex--ready)') || [])];
  if (!figs.length) return;
  figs.filter(f => f.classList.contains('ex-energy')).forEach(bindEnergyFigure);
  const reduce = !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const play = (f) => {
    f.classList.add('ex--play');
    if (f.classList.contains('ex-energy')) startEnergyFigure(f, { reduce });
  };
  if (reduce || typeof IntersectionObserver === 'undefined') {
    figs.forEach(f => { f.classList.add('ex--ready'); play(f); });
    return;
  }
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      play(e.target);
      io.unobserve(e.target);
    });
  }, { threshold: 0.3 });
  figs.forEach(f => { f.classList.add('ex--ready'); io.observe(f); });
  // v3.9.0 (A1) — spätestens nach zwei Sekunden das Endbild: Meldet der Beobachter
  // eine Abbildung nie als sichtbar (ganze Seite als Bild, eingebettete Ansicht,
  // eingeklappter Bereich), standen bisher nur die Beschriftungen da, die Balken
  // blieben auf Breite 0
  setTimeout(() => figs.forEach(f => {
    if (!f.isConnected || f.classList.contains('ex--play')) return;
    io.unobserve(f);
    play(f);
  }), 2000);
}
