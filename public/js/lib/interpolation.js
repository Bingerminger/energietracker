// =====================================================================
// Energietracker v3.7.0 — gemessen oder verteilt (GitHub #37, #39)
//
// Liegen zwei Ablesungen mehr als 45 Tage auseinander, verteilt die Rechnung
// den Verbrauch gleichmäßig auf die Tage; der Server kennzeichnet solche
// Monate (`interpolated`, `reading_gap_days`) und zählt die Ablesungen
// (`interpolation` an der Verbrauchsantwort, `interpolation` in der
// PV-Übersicht). Hier steht der eine Satz, den Verbrauchsart, Analyse und
// PV-Übersicht dazu sagen — ein Hinweis je Ansicht, kein Hinweis-Spam — und
// die Markierung „≈“ für Tabellen und Tooltips.
//
// Reine Funktionen ohne DOM — geprüft in tests/chart.test.mjs.
// =====================================================================

import { t, tp } from './i18n.js';
import { fmt } from './format.js';
import { isInterpolated } from './chart-data.js';

export { isInterpolated };

/** Zeichen für einen verteilten Monat in Tabellen. */
export const MARK = '≈';

/** Klartext zum Monat: aus wie vielen Tagen zwischen zwei Ablesungen er verteilt ist. */
export function markTitle(row) {
  // v3.7.0 (Nachlauf) — mit einer geschätzten Ablesung als Stütze gilt je Seite eine eigene Tagesrate
  if (row?.reading_gap_days != null && row.reading_gap_estimated) return t('interpolation.markTitleEstimated', { gap: row.reading_gap_days });
  return row?.reading_gap_days != null
    ? t('interpolation.markTitle', { gap: row.reading_gap_days })
    : t('interpolation.markTitleOpen');
}

/**
 * Der Hinweis einer Ansicht — oder '' ohne verteilte Monate.
 *
 * @param {object} o
 * @param {Array<object>} o.rows       Monatszeilen, für die der Hinweis gilt (Jahr, Periode, alle)
 * @param {object|null} o.info         `interpolation` vom Server (readings, readings_by_year, from, to, max_gap_days, heat_basis, heat_points)
 * @param {number|null} [o.year]       Kalenderjahr der Ansicht: Zahl der Ablesungen in diesem Jahr; sonst über den ganzen Zeitraum
 * @param {boolean} [o.heat]           Art mit Heizkurve (sagt, dass auch sie die Monate auslässt)
 * @param {boolean} [o.pv]             PV: Eigenverbrauch, Ersparnis und Amortisation sind Schätzungen
 * @param {string|null} [o.series]     Bezeichnung der Reihe, wenn es nicht die der Ansicht ist (PV-Übersicht)
 * @returns {string} Klartext (noch nicht maskiert)
 */
export function interpolationNote({ rows = [], info = null, year = null, heat = false, pv = false, series = null } = {}) {
  const k = (rows || []).filter(isInterpolated).length;
  if (!k) return '';
  const total = rows.length;
  const lead = year != null
    ? tp('interpolation.leadYear', k, { count: k, total, year })
    : tp('interpolation.leadAll', k, { count: k, total });
  let readings = '';
  if (info && year != null && info.readings_by_year) {
    const n = Number(info.readings_by_year[year] ?? info.readings_by_year[String(year)] ?? 0);
    readings = n > 0 ? tp('interpolation.readingsYear', n, { count: n, year }) : t('interpolation.noReadingYear', { year });
  } else if (info && info.readings != null && info.from && info.to) {
    readings = tp('interpolation.readingsRange', info.readings, { count: info.readings, from: fmt.month(info.from), to: fmt.month(info.to) });
  }
  const parts = [
    (series ? `${series}: ` : '') + lead + (readings ? ` (${readings}).` : '.'),
    t('interpolation.why', { limit: info?.max_gap_days ?? 45 }),
    t(heat ? 'interpolation.skipHeat' : 'interpolation.skip'),
  ];
  // v3.7.0 (Nachlauf) — nicht überall „gleichmäßig“: an einer geschätzten Ablesung je Seite eine eigene Tagesrate
  if ((rows || []).some(r => isInterpolated(r) && r.reading_gap_estimated)) parts.splice(2, 0, t('interpolation.whyEstimated'));
  if (heat && info?.heat_basis === 'intervals' && info.heat_points) parts.push(t('interpolation.heatIntervals', { intervals: info.heat_points }));
  if (pv) parts.push(t('interpolation.pv'));
  return parts.join(' ');
}
