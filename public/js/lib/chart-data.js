// =====================================================================
// Energietracker v2.15.0 — Monatsreihen für Diagramme und Trends
//
// Review FE-08: Die API liefert je Monat `days`; die Oberfläche nutzte es
// kaum. Ein März mit 14 erfassten Tagen stand im Chart wie ein voller Monat,
// und die Trendpfeile verglichen „die letzten drei Monate mit den drei davor“
// — Heizsaison gegen Sommer: Fernwärme +470 %, Gas −48 %. Hier stehen die
// Regeln an einer Stelle: Was ein Teilmonat ist, und dass ein Trend dieselben
// Monate des Vorjahres vergleicht, nur volle, witterungsbereinigt, wo es geht.
//
// Reine Funktionen ohne DOM — geprüft in tests/chart.test.mjs.
// =====================================================================

/** Tage des Monats `YYYY-MM`. */
export function daysInMonth(ym) {
  const [y, m] = String(ym).split('-').map(Number);
  return new Date(y, m, 0).getDate();   // Tag 0 des Folgemonats = letzter Tag
}

/** Deckt die Monatszeile den Monat nur zum Teil ab (`days` kleiner als der Monat)? */
export function isPartial(row) {
  const d = Number(row?.days);
  return Number.isFinite(d) && d > 0 && d < daysInMonth(row.ym);
}

/** `YYYY-MM` um `n` Monate verschieben. */
export function shiftYm(ym, n) {
  const [y, m] = String(ym).split('-').map(Number);
  const d = new Date(y, m - 1 + n, 1, 12);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * Trend gegen dieselben Monate des Vorjahres.
 *
 * Verglichen werden nur volle Monate, die es ein Jahr früher ebenfalls voll
 * gibt — „Januar bis März gegen Januar bis März“. Tragen alle beteiligten
 * Monate einen witterungsbereinigten Wert (`adjustedKey`), zählt der: Dann
 * sagt der Pfeil, ob gespart wurde, nicht, ob der Winter mild war.
 *
 * @param {Array<object>} rows  Monatszeilen mit `ym`, `days` und `key`
 * @param {string} key          'kwh' | 'm3' | 'cost'
 * @param {{months?: number, minMonths?: number, adjustedKey?: string|null, within?: Iterable<string>|null}} [opts]
 *   months: die jüngsten so vielen vollen Monate; minMonths: so viele Paare
 *   mindestens; within: nur Monate aus diesem Fenster (Liste von `ym`)
 * @returns {{pct: number, months: string[], adjusted: boolean, current: number, previous: number}|null}
 */
export function yoyTrend(rows, key, { months = 3, minMonths = months, adjustedKey = null, within = null } = {}) {
  const list = Array.isArray(rows) ? rows : [];
  const byYm = new Map(list.map(r => [r.ym, r]));
  const allowed = within ? new Set(within) : null;
  const usable = (r) => !!r && !isPartial(r) && r[key] != null && Number.isFinite(Number(r[key]));
  const candidates = list.filter(r => usable(r) && (!allowed || allowed.has(r.ym))).slice(-months);
  const pairs = candidates
    .map(r => [r, byYm.get(shiftYm(r.ym, -12))])
    .filter(([, prev]) => usable(prev));
  if (pairs.length < Math.max(1, minMonths)) return null;
  const adjusted = !!adjustedKey && pairs.every(([a, b]) =>
    a[adjustedKey] != null && b[adjustedKey] != null
    && Number.isFinite(Number(a[adjustedKey])) && Number.isFinite(Number(b[adjustedKey])));
  const k = adjusted ? adjustedKey : key;
  const current  = pairs.reduce((s, [a]) => s + Number(a[k]), 0);
  const previous = pairs.reduce((s, [, b]) => s + Number(b[k]), 0);
  if (!(previous > 0)) return null;
  const pct = (current - previous) / previous * 100;
  if (!Number.isFinite(pct)) return null;
  return { pct, months: pairs.map(([a]) => a.ym), adjusted, current, previous };
}

/** Die jüngsten `n` Monatszeilen mit Anfang, Ende und ob der letzte Monat unvollständig ist. */
export function lastMonths(rows, n = 12) {
  const win = (Array.isArray(rows) ? rows : []).slice(-n);
  if (!win.length) return null;
  const last = win[win.length - 1];
  return {
    rows: win,
    from: win[0].ym,
    to: last.ym,
    partialLast: isPartial(last),
    lastDays: Number(last.days) || null,
    lastTotal: daysInMonth(last.ym),
  };
}

/**
 * Kennzahlen einer Reihe für die Kurzbeschreibung eines Diagramms (aria):
 * Summe, Anzahl, kleinster und größter Wert mit Beschriftung.
 *
 * @param {string[]} labels
 * @param {Array<number|null>} values
 */
export function seriesSummary(labels, values) {
  let sum = 0, n = 0, min = null, max = null;
  (values || []).forEach((v, i) => {
    if (v == null) return;
    const x = Number(v);
    if (!Number.isFinite(x)) return;
    sum += x; n++;
    if (!min || x < min.value) min = { value: x, label: labels[i] };
    if (!max || x > max.value) max = { value: x, label: labels[i] };
  });
  return n ? { sum, n, min, max } : null;
}
