// =====================================================================
// v3.12.0 (M4, freigabe.md Punkt 2) — der Trend einer Kachel, eine Form für alle Seiten.
//
// Verglichen wird, was #36 festgelegt hat: im Kalenderjahr dieselben vollen Monate ein
// Jahr zuvor (yoyTrend), im Abrechnungsjahr dieselbe Spanne davor (sameSpanTrend). Sichtbar
// sind Grafik, Vorzeichen und Wert, darunter der Bezug; Farbe ist nie das einzige Zeichen.
// „Etwa gleich“ und „kein Vergleich“ stehen da, statt dass der Platz leer bleibt:
// Wer einen Pfeil erwartet, soll sehen, warum keiner da ist.
// =====================================================================

import { fmt, escapeHtml } from '../lib/format.js';
import { t } from '../lib/i18n.js';
import { trendNeedsCheck } from '../lib/chart-data.js';
import { icon } from '../lib/icons.js';

/**
 * Ab hier gilt eine Änderung als Änderung (bis v3.11 stand darunter nichts). Eine Schwelle für Kachel und
 * Antwort der Übersicht (views/dashboard.js trendAnswer); bis dahin sagte die Antwort unter 2 % „etwa wie im
 * Vorjahr“, während die Kachel daneben schon „−2 %“ zeigte.
 */
export const SAME_BELOW_PCT = 0.5;

/** Zeitraum und Bezug eines Trends, wie `title` und der Satz für Vorleser sie nennen. */
function sentences(trend, up, pctStr) {
  if (trend.prevFrom) {
    const p = { pct: pctStr, span: `${fmt.date(trend.from)} – ${fmt.date(trend.until)}`, prev: `${fmt.date(trend.prevFrom)} – ${fmt.date(trend.prevUntil)}` };
    const calendar = trend.basis === 'calendar';
    return {
      title: t(calendar ? 'dashboard.trend.vsCalendarDays' : 'dashboard.trend.vsPrevPeriod', p),
      label: calendar
        ? t(up ? 'dashboard.trend.moreThanCalendarDays' : 'dashboard.trend.lessThanCalendarDays', p)
        : t(up ? 'dashboard.trend.moreThanPrevPeriod' : 'dashboard.trend.lessThanPrevPeriod', p),
      basis: t(calendar ? 'dashboard.trend.basisYear' : 'dashboard.trend.basisPeriod'),
    };
  }
  const months = trend.months || [];
  const span = months.length > 1 ? `${fmt.month(months[0])} – ${fmt.month(months[months.length - 1])}` : fmt.month(months[0]);
  return {
    title: t('dashboard.trend.vsPrev', { span }),
    label: t(up ? 'dashboard.trend.moreThanPrev' : 'dashboard.trend.lessThanPrev', { pct: pctStr, span }),
    basis: t('dashboard.trend.basisYear'),
  };
}

/**
 * @param {?{pct: number}} trend  aus yoyTrend() oder sameSpanTrend()
 * @param {{moreIsGood?: boolean, checkHref?: ?string, noneTitle?: string, decimals?: number, bare?: boolean, noCheck?: boolean}} [o]
 *        moreIsGood: Einspeisung und Erzeugung; checkHref: Weg zu den Ablesungen, wenn sich die Zahl
 *        eher nach einem falschen Stand ansieht; noneTitle: warum es keinen Vergleich gibt;
 *        bare: ohne den Bezug darunter (im Fließtext, wo er im Satz steht); noCheck: auch große Änderungen
 *        als Zahl (Trend über drei Monate im Hinweis der Verbrauchsart, kein Jahresvergleich)
 */
export function trendHtml(trend, { moreIsGood = false, checkHref = null, noneTitle = '', decimals = 0, bare = false, noCheck = false } = {}) {
  const pct = trend ? Number(trend.pct) : NaN;
  if (!trend || !Number.isFinite(pct)) {
    if (bare) return '';
    const hint = noneTitle || t('dashboard.trend.noneTitle');
    return `<span class="kpi__trend-keiner" data-role="trend-none" title="${escapeHtml(hint)}">${escapeHtml(t('dashboard.trend.none'))}</span>`;
  }
  // v3.10.0 (Skeptiker M1) — mehr als doppelt bzw. weniger als halb so viel: eher eine falsche
  // Ablesung als ein anderer Verbrauch; statt „+1.382 %“ der Weg zu den Ablesungen
  if (!noCheck && trendNeedsCheck(pct)) {
    const label = escapeHtml(t('dashboard.trend.checkLabel'));
    const text = `${icon('suche', { size: 16 })}<span>${escapeHtml(t('dashboard.trend.check'))}</span>`;
    return checkHref
      ? `<a class="kpi__trend kpi__trend--check" href="${escapeHtml(checkHref)}" title="${label}" aria-label="${label}" data-role="trend-check">${text}</a>`
      : `<span class="kpi__trend kpi__trend--check" title="${label}" aria-label="${label}" data-role="trend-check">${text}</span>`;
  }
  const up = pct > 0;
  const pctStr = fmt.num(Math.abs(pct), decimals);
  const { title, label, basis } = sentences(trend, up, pctStr);
  const basisHtml = bare ? '' : `<small class="kpi__trend-bezug" aria-hidden="true">${escapeHtml(basis)}</small>`;
  if (Math.abs(pct) < SAME_BELOW_PCT) {
    const same = t('dashboard.trend.same');
    return `<span class="kpi__trend-reihe"><span class="kpi__trend kpi__trend--gleich" data-role="trend-same" title="${escapeHtml(title)}" aria-label="${escapeHtml(`${same}: ${title}`)}">`
      + `${icon('trend-gleich', { size: 16 })}<span aria-hidden="true">${escapeHtml(same)}</span></span>${basisHtml}</span>`;
  }
  // v2.13.0 (Review FE-06) — bei Einspeisung und Erzeugung ist mehr gut
  const tone = up === moreIsGood ? 'success' : 'danger';
  // Grafik, Vorzeichen und Farbe sind fürs Auge; aria-label nennt Richtung und Bezug im Klartext
  return `<span class="kpi__trend-reihe"><span class="kpi__trend kpi__trend--${tone}" data-trend="${up ? 'up' : 'down'}" title="${escapeHtml(title)}" aria-label="${escapeHtml(label)}">`
    + `${icon(up ? 'trend-hoch' : 'trend-runter', { size: 16 })}<span aria-hidden="true">${up ? '+' : '−'}${pctStr} %</span></span>${basisHtml}</span>`;
}
