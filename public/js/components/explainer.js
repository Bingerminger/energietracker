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
// =====================================================================

import { t } from '../lib/i18n.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { lastMonths, yoyTrend, isPartial, daysInMonth } from '../lib/chart-data.js';

const esc = escapeHtml;

function figure(cls, title, svg, caption) {
  return `<figure class="explainer ${cls}">
    <figcaption class="explainer__title">${esc(title)}</figcaption>
    <svg class="explainer__svg" viewBox="0 0 320 ${svg.h}" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">${svg.body}</svg>
    <p class="explainer__text">${esc(caption)}</p>
  </figure>`;
}

/** Text im SVG: Werte und Beschriftungen (nur Anzeige, die Aussage steht im Text darunter). */
const label = (x, y, text, cls = '', anchor = 'start') =>
  `<text x="${x}" y="${y}" class="ex-label ${cls}" text-anchor="${anchor}">${esc(text)}</text>`;

// ── 1. Wohin geht mein Geld ─────────────────────────────────────────────

/**
 * @param {object} row  Zeile aus contract-status (laufender Vertrag):
 *   balance_path (Abrechnungszeitraum, letzter Punkt = erwartete Abrechnung),
 *   energy_cost_to_date, base_to_date, bonus_to_date, projected_end_balance.
 *   Nicht advance_paid: das zählt über die ganze Laufzeit des Vertrags.
 * @param {{color?: string}} [opts]  Farbe der Verbrauchsart (u.color)
 * @returns {string} leer, wenn die Zahlen fehlen
 */
export function moneyHtml(row, { color } = {}) {
  const end = (row?.balance_path || []).slice(-1)[0];
  if (!row || !end || row.projected_end_balance == null) return '';
  const paid = Math.max(0, Number(end.paid) || 0);
  const cost = Math.max(0, Number(end.cost) || 0);
  const energy = Math.min(cost, Math.max(0, Number(row.energy_cost_to_date) || 0));
  const base = Math.min(cost - energy, Math.max(0, (Number(row.base_to_date) || 0) - (Number(row.bonus_to_date) || 0)));
  const rest = Math.max(0, cost - energy - base);
  if (!(paid > 0 || cost > 0)) return '';
  const max = Math.max(paid, cost, 1);
  const W = 150, x0 = 90;
  const w = (v) => Math.max(0, v / max * W);
  const c = color || 'var(--accent)';
  const balance = Number(row.projected_end_balance);
  // positiv = Nachzahlung (Kosten über dem Bezahlten), negativ = Guthaben
  const credit = balance < 0;
  const result = Math.abs(balance) < 1 ? t('explainer.money.even')
    : t(credit ? 'explainer.money.credit' : 'explainer.money.due', { amount: fmt.eur(Math.abs(balance)) });
  const gapX = x0 + Math.min(w(paid), w(cost)), gapW = Math.abs(w(paid) - w(cost));
  const body = `
    ${label(0, 30, t('explainer.money.paid'))}
    <rect class="ex-grow ex-bar--paid" style="--d:.0s" x="${x0}" y="16" width="${w(paid).toFixed(1)}" height="20" rx="3"/>
    ${label(x0 + w(paid) + 4, 30, fmt.eur(paid), 'ex-fade ex-value')}
    ${label(0, 74, t('explainer.money.cost'))}
    <rect class="ex-grow" style="--d:.25s; fill:${c}" x="${x0}" y="60" width="${w(energy).toFixed(1)}" height="20" rx="3"/>
    <rect class="ex-grow" style="--d:.45s; fill:${c}; opacity:.55" x="${(x0 + w(energy)).toFixed(1)}" y="60" width="${w(base).toFixed(1)}" height="20"/>
    <rect class="ex-grow ex-bar--estimate" style="--d:.65s; fill:${c}" x="${(x0 + w(energy) + w(base)).toFixed(1)}" y="60" width="${w(rest).toFixed(1)}" height="20"/>
    ${label(x0 + w(cost) + 4, 74, fmt.eur(cost), 'ex-fade ex-value')}
    ${gapW > 2 ? `<rect class="ex-fade ${credit ? 'ex-gap--credit' : 'ex-gap--due'}" style="--d:1s" x="${gapX.toFixed(1)}" y="${credit ? 40 : 84}" width="${gapW.toFixed(1)}" height="6" rx="3"/>` : ''}
    <g class="ex-legend ex-fade" style="--d:.9s">
      <rect x="0" y="100" width="10" height="10" rx="2" style="fill:${c}"/>${label(14, 109, t('explainer.money.energy'), 'ex-small')}
      <rect x="100" y="100" width="10" height="10" rx="2" style="fill:${c}; opacity:.55"/>${label(114, 109, t('explainer.money.base'), 'ex-small')}
      <rect x="200" y="100" width="10" height="10" rx="2" class="ex-bar--estimate" style="fill:${c}"/>${label(214, 109, t('explainer.money.estimate'), 'ex-small')}
    </g>`;
  const caption = t('explainer.money.text', {
    date: fmt.date(`${end.ym}-${String(daysInMonth(end.ym)).padStart(2, '0')}`), paid: fmt.eur(paid), cost: fmt.eur(cost), result,
  });
  return figure('ex-money', t('explainer.money.title'), { h: 120, body }, caption);
}

// ── 2. Energiefluss im Haus ─────────────────────────────────────────────

/**
 * @param {object} year  Jahreszeile aus pv-summary: year, erzeugung_kwh,
 *   eigenverbrauch_kwh, einspeisung_kwh, bezug_kwh, autarkiequote, battery
 * @returns {string} leer ohne Erzeugung
 */
export function energyHtml(year) {
  const gen = Number(year?.erzeugung_kwh) || 0;
  if (!(gen > 0)) return '';
  const self = Number(year.eigenverbrauch_kwh) || 0;
  const feed = Number(year.einspeisung_kwh) || 0;
  const grid = Number(year.bezug_kwh) || 0;
  const charged = Number(year.battery?.charged_kwh) || 0;
  const discharged = Number(year.battery?.discharged_kwh) || 0;
  const max = Math.max(gen, grid, 1);
  const sw = (v) => (v > 0 ? 2 + v / max * 14 : 0).toFixed(1);
  // Knoten: PV oben in der Mitte, Netz links, Haus rechts, Speicher unten
  const N = { pv: [160, 32], grid: [40, 120], house: [280, 120], bat: [160, 190] };
  const node = (k, icon, text) => `<g class="ex-node">
      <circle cx="${N[k][0]}" cy="${N[k][1]}" r="20"/>
      <text x="${N[k][0]}" y="${N[k][1] + 6}" text-anchor="middle" class="ex-icon">${icon}</text>
      ${k === 'pv' ? label(N[k][0] + 26, N[k][1] + 4, text, 'ex-small') : label(N[k][0], N[k][1] + 34, text, 'ex-small', 'middle')}
    </g>`;
  const flow = (a, b, v, cls, d) => {
    if (!(v > 0)) return '';
    const [x1, y1] = N[a], [x2, y2] = N[b];
    const mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
    // Bogen nach außen; die Zahl sitzt auf dem Bogen, mit Hof aus der Kartenfarbe
    const bend = x1 === x2 ? [mx + 22, my] : [mx, my - 16];
    return `<path class="ex-flow ${cls}" style="--d:${d}s" d="M${x1},${y1} Q${bend[0]},${bend[1]} ${x2},${y2}" stroke-width="${sw(v)}"/>`
      + label((mx + bend[0]) / 2, (my + bend[1]) / 2 + 3, `${fmt.num(v, 0)} kWh`, 'ex-small ex-fade ex-value ex-halo', 'middle');
  };
  const directSelf = Math.max(0, self - discharged);
  const body = `
    ${flow('pv', 'house', directSelf, 'ex-flow--pv', 0)}
    ${flow('pv', 'grid', feed, 'ex-flow--feed', .2)}
    ${flow('grid', 'house', grid, 'ex-flow--grid', .4)}
    ${charged > 0 ? flow('pv', 'bat', charged, 'ex-flow--pv', .6) : ''}
    ${discharged > 0 ? flow('bat', 'house', discharged, 'ex-flow--bat', .8) : ''}
    ${node('pv', '☀️', t('explainer.energy.pv'))}
    ${node('house', '🏠', t('explainer.energy.house'))}
    ${node('grid', '⚡', t('explainer.energy.grid'))}
    ${charged > 0 || discharged > 0 ? node('bat', '🔋', t('explainer.energy.battery')) : ''}`;
  const caption = t('explainer.energy.text', {
    year: year.year, gen: fmt.num(gen, 0), self: fmt.num(self, 0), feed: fmt.num(feed, 0), grid: fmt.num(grid, 0),
    autarky: year.autarkiequote == null ? '–' : fmt.pct(year.autarkiequote, 0),
  });
  return figure('ex-energy', t('explainer.energy.title'), { h: charged > 0 || discharged > 0 ? 235 : 165, body }, caption);
}

// ── 3. Kälter oder mehr verbraucht ──────────────────────────────────────

/**
 * @param {Array<object>} monthly  Monatszeilen eines Heizzählers (kwh, hdd, heat_adjusted)
 * @returns {string} leer ohne Vorjahr
 */
export function weatherHtml(monthly) {
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
  const W = 170, x0 = 120;
  const bar = (y, v, m, cls, d) => `<rect class="ex-grow ${cls}" style="--d:${d}s" x="${x0}" y="${y}" width="${(v / m * W).toFixed(1)}" height="12" rx="2"/>`;
  const verdict = adj.pct < -2 ? 'less' : adj.pct > 2 ? 'more' : 'same';
  const body = `
    ${label(0, 20, t('explainer.weather.usage'))}
    ${bar(8, cons.previous, max, 'ex-bar--prev', 0)}${bar(24, cons.current, max, 'ex-bar--now', .2)}
    ${label(x0 + cons.current / max * W + 4, 34, p(cons.pct), 'ex-fade ex-value')}
    ${label(0, 66, t('explainer.weather.hdd'))}
    ${bar(54, hdd.previous, maxH, 'ex-bar--prev', .4)}${bar(70, hdd.current, maxH, 'ex-bar--cold', .6)}
    ${label(x0 + hdd.current / maxH * W + 4, 80, p(hdd.pct), 'ex-fade ex-value')}
    <g class="ex-fade" style="--d:1s">
      <rect class="ex-badge ex-badge--${verdict}" x="${x0}" y="94" width="${W}" height="24" rx="12"/>
      ${label(x0 + W / 2, 110, t('explainer.weather.adjusted', { pct: p(adj.pct) }), 'ex-badge__text', 'middle')}
    </g>
    <g class="ex-legend ex-fade" style="--d:.9s">
      <rect x="0" y="128" width="10" height="10" rx="2" class="ex-bar--prev"/>${label(14, 137, t('explainer.weather.before'), 'ex-small')}
      <rect x="170" y="128" width="10" height="10" rx="2" class="ex-bar--now"/>${label(184, 137, t('explainer.weather.now'), 'ex-small')}
    </g>`;
  const caption = t(`explainer.weather.text.${verdict}`, { cons: p(cons.pct), hdd: p(hdd.pct), adj: p(adj.pct) });
  return figure('ex-weather', t('explainer.weather.title'), { h: 145, body }, caption);
}

// ── 4. Vertrags-Zeitstrahl ──────────────────────────────────────────────

/**
 * @param {object} row  Zeile aus contract-status: start, effective_end,
 *   is_open_ended, cancel_by, switch_date, price_increase
 * @param {string} today  JJJJ-MM-TT
 */
export function timelineHtml(row, today) {
  if (!row?.start) return '';
  // Ein weiterlaufender Vertrag endet erst mit der Kündigung (switch_date)
  const end = row.is_open_ended ? (row.cancel_by ? row.switch_date : null) : (row.end || row.effective_end || null);
  const points = [
    { key: 'start', date: row.start },
    { key: 'cancel', date: row.cancel_by },
    { key: 'price', date: row.price_increase?.from || null },
    { key: 'end', date: end },
  ].filter(x => x.date);
  const dates = [...points.map(x => x.date), today].sort();
  const t0 = Date.parse(dates[0]), t1 = Date.parse(dates[dates.length - 1]);
  if (!(t1 > t0)) return '';
  const X = (d) => 16 + (Date.parse(d) - t0) / (t1 - t0) * 288;
  let row2 = false;
  const marks = points.map((x, i) => {
    const xx = X(x.date);
    const up = (row2 = !row2);
    return `<g class="ex-fade ex-mark ex-mark--${x.key}" style="--d:${(.3 + i * .2).toFixed(1)}s">
      <circle cx="${xx.toFixed(1)}" cy="60" r="6"/>
      ${label(xx, up ? 38 : 88, t('explainer.timeline.' + x.key), 'ex-small', 'middle')}
      ${label(xx, up ? 26 : 100, fmt.date(x.date), 'ex-small ex-muted', 'middle')}
    </g>`;
  }).join('');
  const tx = X(today);
  const body = `
    <line class="ex-axis" x1="16" y1="60" x2="304" y2="60"/>
    <line class="ex-grow ex-progress" style="--d:0s" x1="16" y1="60" x2="${tx.toFixed(1)}" y2="60"/>
    ${marks}
    <g class="ex-fade ex-today" style="--d:.2s"><line x1="${tx.toFixed(1)}" y1="46" x2="${tx.toFixed(1)}" y2="74"/>${label(tx, 118, t('explainer.timeline.today'), 'ex-small', 'middle')}</g>`;
  const caption = row.cancel_by
    ? t(row.is_open_ended ? 'explainer.timeline.textOpen' : 'explainer.timeline.text', {
        start: fmt.date(row.start), cancel: fmt.date(row.cancel_by), end: end ? fmt.date(end) : '–' })
    : end ? t('explainer.timeline.textNoNotice', { start: fmt.date(row.start), end: fmt.date(end) })
      : t('explainer.timeline.textRunning', { start: fmt.date(row.start) });
  return figure('ex-timeline', t('explainer.timeline.title'), { h: 125, body }, caption);
}

// ── Abspielen ───────────────────────────────────────────────────────────

/**
 * Bewegung starten, sobald ein Bild sichtbar wird. Erst hier werden die
 * Ausgangszustände gesetzt (`ex--ready`): Ohne Skript bleibt das Endbild.
 * @param {ParentNode} root
 */
export function playExplainers(root) {
  const figs = [...(root?.querySelectorAll?.('.explainer:not(.ex--ready)') || [])];
  if (!figs.length) return;
  const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reduce || typeof IntersectionObserver === 'undefined') {
    figs.forEach(f => f.classList.add('ex--ready', 'ex--play'));
    return;
  }
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      e.target.classList.add('ex--play');
      io.unobserve(e.target);
    });
  }, { threshold: 0.3 });
  figs.forEach(f => { f.classList.add('ex--ready'); io.observe(f); });
}
