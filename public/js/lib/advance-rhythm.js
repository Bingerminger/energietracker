// =====================================================================
// v3.9.0 (F1034) — Rhythmus eines Abschlags in Worten: monatlich (nichts),
// alle zwei oder drei Monate, oder an genannten Terminen. Eine Stelle für den
// Posteingang, die Vertragskarte, die Saldo-Karte und die Übersicht — der
// Betrag gilt dann je Termin und wird nie still in einen Monatsbetrag umgedeutet
// (ContractService::installments).
// =====================================================================

import { t, tp } from './i18n.js';
import { fmt } from './format.js';

/** Erlaubter Rhythmus in Monaten (Spiegel von ContractService::ADVANCE_EVERY). */
export const ADVANCE_EVERY = [1, 2, 3];

/**
 * Rhythmus als Satzteil: monatlich '', sonst „alle 2 Monate“; mit genannten
 * Terminen „alle 2 Monate, am 15.05.2026, 15.07.2026 …“ bzw. „am …“.
 * @param {number} every Monate (1 = monatlich)
 * @param {string[]} [dates] ISO-Tage
 */
export function advanceRhythm(every, dates = []) {
  const n = Number(every) || 1;
  const list = Array.isArray(dates) ? dates.map(fmt.date).join(', ') : '';
  if (list && n > 1) return t('advance.rhythm.everyDates', { every: tp('advance.rhythm.every', n), dates: list });
  if (list) return t('advance.rhythm.dates', { dates: list });
  return n > 1 ? tp('advance.rhythm.every', n) : '';
}

/**
 * Skeptiker R2-8 — Zeitraum einer Abschlagszeile mit 0 € (etwa aus der Rechnung: nach dem Zeitraum bis zum
 * ersten neuen Termin): `{ from, until }`, `until` = Vortag der nächsten Zeile oder null (offen); sonst null.
 * Eine leere Zeile (noch kein Betrag) ist keine 0-€-Zeile.
 * @param {Array<{from?: string, amount_eur?: number|string}>} rows alle Zeilen des Plans
 * @param {{from?: string, amount_eur?: number|string}} row
 */
export function zeroAdvanceSpan(rows, row) {
  const amount = row?.amount_eur;
  if (amount === '' || amount == null || Number(amount) !== 0 || !/^\d{4}-\d{2}-\d{2}$/.test(String(row.from ?? ''))) return null;
  const next = (Array.isArray(rows) ? rows : []).map(r => String(r?.from ?? '')).filter(d => /^\d{4}-\d{2}-\d{2}$/.test(d) && d > row.from).sort()[0];
  if (!next) return { from: row.from, until: null };
  const d = new Date(`${next}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - 1);
  return { from: row.from, until: d.toISOString().slice(0, 10) };
}

/** Hinweis zur 0-€-Zeile: „01.04.2026 bis 14.05.2026: kein Abschlag eingetragen …“ bzw. offen „Ab …“. */
export function zeroAdvanceText(span) {
  if (!span) return '';
  return span.until ? t('contracts.advance.none', { from: fmt.date(span.from), to: fmt.date(span.until) }) : t('contracts.advance.noneOpen', { from: fmt.date(span.from) });
}

/** Betrag mit Rhythmus: „165,00 € alle 2 Monate“; monatlich wie bisher nur der Betrag. */
export function advanceAmountText(amount, every) {
  const n = Number(every) || 1;
  return n > 1 ? `${fmt.eur(amount)} ${tp('advance.rhythm.every', n)}` : fmt.eur(amount);
}
