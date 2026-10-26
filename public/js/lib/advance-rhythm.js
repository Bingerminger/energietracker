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

/** Betrag mit Rhythmus: „165,00 € alle 2 Monate“; monatlich wie bisher nur der Betrag. */
export function advanceAmountText(amount, every) {
  const n = Number(every) || 1;
  return n > 1 ? `${fmt.eur(amount)} ${tp('advance.rhythm.every', n)}` : fmt.eur(amount);
}
