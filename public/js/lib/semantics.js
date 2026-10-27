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
/**
 * v3.7.0 (Nachlauf vor dem Release) — Spanne einer unsicheren Saldo-Schätzung
 * (`projection_uncertain.projected_range`) als kurzer Text, etwa „ca. 120 € Guthaben
 * bis ca. 80 € Nachzahlung“ — dieselben Wörter wie der Satz der Saldo-Karte. Positiver
 * Saldo = Nachzahlung. Kein Punktwert: Wo die Schätzung unsicher ist, steht nur die Spanne.
 *
 * @param {object|null|undefined} unc
 * @returns {string} leer ohne Spanne
 */
export function uncertainRangeText(unc) {
  const r = Array.isArray(unc?.projected_range) ? unc.projected_range.map(Number) : null;
  if (!r || r.length !== 2 || !r.every(Number.isFinite)) return '';
  const word = (v) => Math.abs(v) < 1 ? t('balanceUncertain.even')
    : t(v > 0 ? 'balanceUncertain.due' : 'balanceUncertain.credit', { amount: t('report.fmt.approx', { amount: fmt.eur0(Math.abs(v)) }) });
  const [a, b] = balanceRangeEnds(r);
  return t('balanceUncertain.short', { low: word(a), high: word(b) });
}

/**
 * v3.9.0 (Paket doku, Befund am Heizgrenze-Hinweis) — die Enden einer Saldo-Spanne
 * (positiv = Nachzahlung) in Lesereihenfolge: Liegen beide im Guthaben, vom kleineren zum
 * größeren Betrag („zwischen ca. 325 € und ca. 431 € Guthaben“; bis dahin „zwischen ca. 431 €
 * Guthaben und ca. 325 € Guthaben“, der Zahl nach). Sonst der Zahl nach: Guthaben vor
 * Nachzahlung, zwei Nachzahlungen vom kleineren zum größeren Betrag. Für Saldo-Karte,
 * Vertragsübersicht, „Wohin geht mein Geld?“ und den Heizgrenze-Hinweis.
 *
 * @param {number[]} r  [ein Ende, anderes Ende]
 * @returns {number[]}  [zuerst, danach]
 */
export function balanceRangeEnds(r) {
  const lo = Math.min(Number(r[0]), Number(r[1])), hi = Math.max(Number(r[0]), Number(r[1]));
  return hi < 0 ? [hi, lo] : [lo, hi];
}

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
