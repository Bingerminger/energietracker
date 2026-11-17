// =====================================================================
// v3.13.0 (Paket K1, aus Paket F) — Text einer Fundstelle beim Einspielen eines Backups.
//
// Der Server meldet je Fundstelle `{pot, index, problem}`, `problem` als Art mit optionaler Angabe hinter dem
// Doppelpunkt (`missing:devices`, `date_range:start`, `amount`, `amount:working_prices` …), dazu bei `too_long`
// `field` und `max`. Bis v3.12 kannten rund zwanzig Arten keinen Text, der Dialog zeigte den Schlüssel.
//
// Jede Art hat einen Katalogtext `settings.backup.problem.<art>` (BackupProblemCatalogTest liest die Arten aus
// BackupService). Steht die Art in PROBLEM_ARG_IN_TEXT, nennt der Text die Angabe selbst ({field} bzw. {what});
// sonst steht sie in Klammern dahinter. Eine unbekannte Art erscheint, wie der Server sie meldet.
// =====================================================================
import { t, hasTranslation } from './i18n.js';

/** Arten, deren Text die Angabe hinter dem Doppelpunkt nennt (BackupProblemCatalogTest prüft es in allen Sprachen). */
export const PROBLEM_ARG_IN_TEXT = new Set(['missing', 'date', 'unsafe_id', 'entry', 'too_long', 'date_range', 'value', 'personal', 'setting', 'unknown', 'sha256', 'invalid', 'bool', 'list', 'object', 'number',
  // v3.13.0 (K2d) — gemeinsame Regeln (Support\FieldRules): Text statt Liste, Verweis auf einen Zähler
  'text', 'reference']);

/**
 * @param {{problem?: string, field?: string, max?: number}} p  Fundstelle aus `detail.problems`
 * @param {(key: string, params?: object) => string} [tr]  Übersetzung (Standard: t)
 * @param {(key: string) => boolean} [has]  Text vorhanden? (Standard: hasTranslation)
 * @returns {string}
 */
export function backupProblemText(p, tr = t, has = hasTranslation) {
  const raw = String(p?.problem || '');
  const [kind, arg] = raw.split(/:(.*)/s);
  const key = 'settings.backup.problem.' + kind;
  if (!kind || !has(key)) return raw;
  const text = tr(key, { field: p?.field ?? arg ?? '', what: arg ?? '', max: p?.max ?? '' });
  return arg && !PROBLEM_ARG_IN_TEXT.has(kind) ? `${text} (${arg})` : text;
}
