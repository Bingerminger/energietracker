// =====================================================================
// v3.7.0 (GitHub #33, #35) — Boni neben den Kosten.
//
// Die Kachel „Kosten“ (Verbrauchsart, Übersicht; Kalender- wie
// Abrechnungsjahr) zeigt die Kosten ohne Bonus. Der Bonus steht als eigene
// Zeile darunter („Bonus −50,00 € am 15.03.2025“). Der Saldo rechnet ihn ein —
// er steht auf der Rechnung und mindert, was am Ende zu zahlen ist. Der Ø je
// Monat gilt ohne Bonus: Eine einmalige Gutschrift ist kein Monatspreis; mit
// ihr sähe ein Jahr mit Bonus billiger aus als das nächste ohne, und der
// Vergleich mit dem Abschlag stimmte nicht.
//
// Die Einzelnen (`bonus_items`: Gutschriftsdatum, Betrag, Art, Notiz) liefert
// der Server an den Monatszeilen und an den Abrechnungsperioden.
// =====================================================================
import { t } from './i18n.js';
import { fmt } from './format.js';

/** Die Bonusarten (wie ContractService::BONUS_TYPES); `sonstige` seit v3.7.0 (#35). */
export const BONUS_TYPES = ['sofort', 'wechsel', 'neukunde', 'sonstige'];

/** Name einer Bonusart; unbekannte heißen „Bonus“. */
export function bonusTypeLabel(type) {
  return BONUS_TYPES.includes(type) ? t(`contracts.bonusTypes.${type}`) : t('bonus.generic');
}

/** Bezeichnung mit Art, wie im Jahresbericht: „Bonus (Neukunde)“, „Sonstige Gutschrift“. */
export function bonusWhat(type) {
  if (type === 'sonstige') return t('contracts.bonusTypes.sonstige');
  return BONUS_TYPES.includes(type) ? t('bonus.typed', { type: t(`contracts.bonusTypes.${type}`) }) : t('bonus.generic');
}

/** Je Bonus „Bonus (Neukunde) −X € am …“ mit Notiz — für die Vertragskarte. */
export function bonusDetailLines(items) {
  return (Array.isArray(items) ? items : [])
    .filter(b => b && b.credit_date)
    .sort((a, b) => String(a.credit_date).localeCompare(String(b.credit_date)))
    .map(b => {
      const line = t('bonus.lineTyped', { what: bonusWhat(b.type), amount: fmt.eur(Number(b.amount_eur) || 0), date: fmt.date(b.credit_date) });
      return b.label ? t('bonus.withNote', { line, note: b.label }) : line;
    });
}

/** Summe der Boni über Zeilen (`bonus_eur` der Monatszeilen). */
export function bonusOf(rows) {
  return (Array.isArray(rows) ? rows : []).reduce((s, r) => s + (Number(r?.bonus_eur) || 0), 0);
}

/** Die einzelnen Boni der Zeilen, nach Gutschriftsdatum. */
export function bonusItemsOf(rows) {
  return (Array.isArray(rows) ? rows : [])
    .flatMap(r => (Array.isArray(r?.bonus_items) ? r.bonus_items : []))
    .sort((a, b) => String(a?.credit_date || '').localeCompare(String(b?.credit_date || '')));
}

/** Eine Zeile je Bonus: „Bonus −X € am …“, bei sonstiger Gutschrift „Gutschrift −X € am …“, mit Notiz in Klammern. */
export function bonusLines(items) {
  return (Array.isArray(items) ? items : []).map(b => {
    const line = t(b?.type === 'sonstige' ? 'bonus.lineOther' : 'bonus.line', {
      amount: fmt.eur(Number(b?.amount_eur) || 0), date: fmt.date(b?.credit_date),
    });
    return b?.label ? t('bonus.withNote', { line, note: b.label }) : line;
  });
}

/**
 * Text der Bonuszeile unter einer Kostenkachel; '' ohne Bonus. Passen die
 * Einzelnen nicht zur Summe der Kachel (ältere Antwort des Servers), steht nur
 * die Summe da — nie ein Datum, das nicht zur Zahl gehört.
 */
export function bonusLineText(items, total) {
  if (!(Math.abs(Number(total) || 0) > 0.005)) return '';
  const list = Array.isArray(items) ? items : [];
  const sum = list.reduce((s, b) => s + (Number(b?.amount_eur) || 0), 0);
  if (!list.length || Math.abs(sum - total) > 0.011) return t('bonus.total', { amount: fmt.eur(total) });
  return bonusLines(list).join(' · ');
}
