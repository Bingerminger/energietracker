// =====================================================================
// v2.13.0 (Review FE-06, UI-18, UI-19) — was eine Zahl je Verbrauchsart
// bedeutet, an einer Stelle.
//
// Bis v2.12 behandelten Übersicht, Analyse und Tabellen jede Verbrauchsart
// als Verbrauch: Weniger Einspeisung erschien grün, ein Erlös als „Kosten",
// und ein Guthaben als negative Zahl. Die Arten kommen aus der SSOT
// (`accounting_kind` in `/api/utilities`).
// =====================================================================
import { t } from './i18n.js';
import { fmt } from './format.js';

export const isFeedIn     = (u) => u?.accounting_kind === 'feed_in';
export const isGeneration = (u) => u?.accounting_kind === 'generation';
export const isPv         = (u) => isFeedIn(u) || isGeneration(u);
/** Mehr ist besser: Einspeisung und Erzeugung. */
export const moreIsBetter = (u) => isPv(u);
/** Gas: m³ werden über Zustandszahl × Brennwert zu kWh (Rechnungsprüfung, m³-Spalte). */
export const usesGasFactors = (u) => u?.unit === 'm³' && u?.consumption_unit === 'kWh';

/** Farbklasse einer Veränderung: mehr Verbrauch rot, mehr Einspeisung grün. */
export function changeTone(delta, u) {
  if (!delta) return '';
  const good = moreIsBetter(u) ? delta > 0 : delta < 0;
  return good ? 'success-text' : 'danger-text';
}

/**
 * Saldo in Kundensicht (Review UI-18, Entscheidung „saldo"): „Guthaben
 * 449,72 €" grün, „Nachzahlung 62,74 €" rot — ohne Vorzeichen. Intern gilt
 * Kosten − Abschläge, positiv heißt Nachzahlung; bei der Einspeisung ist ein
 * positiver Saldo ein Anspruch an den Netzbetreiber.
 *
 * `signed` ist die Form für dichte Tabellen: Vorzeichen aus Kundensicht,
 * „+" heißt Guthaben.
 *
 * @param {number|null|undefined} v
 * @param {object} u  Verbrauchsart
 * @param {(n: number) => string} [money]  Formatierer, Standard fmt.eur
 */
export function balanceView(v, u, money = fmt.eur) {
  const n = Number(v);
  if (v == null || !Number.isFinite(n)) return null;
  if (Math.abs(n) < 0.005) {
    return { credit: null, amount: 0, word: t('saldo.even'), cls: 'muted', signed: money(0) };
  }
  const credit = isFeedIn(u) ? n > 0 : n < 0;
  const amount = Math.abs(n);
  return {
    credit,
    amount,
    word: t(credit ? 'saldo.credit' : (isFeedIn(u) ? 'saldo.reclaim' : 'saldo.due')),
    cls: credit ? 'success-text' : 'danger-text',
    signed: `${credit ? '+' : '−'}${money(amount)}`,
  };
}
