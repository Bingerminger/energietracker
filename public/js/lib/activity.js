// =====================================================================
// v3.4.0 (F1024) — Erledigt mit Verlauf: Texte und Bereiche eines Eintrags.
//
// Ein Eintrag (GET /api/activity) trägt `title_key` + `params`; der Titel
// entsteht hier in der Sprache der Oberfläche neu (`title` ist nur die
// Momentaufnahme beim Erledigen). Tage in `params` stehen als JJJJ-MM-TT da
// und werden in der Schreibweise von Sprache und Land gezeigt.
// =====================================================================

import { t } from './i18n.js';
import { fmt } from './format.js';

/** Bereich je Art — Filter „Bereich" im Tab „Erledigt". Eine Stelle für Ansicht und Test. */
export const AREAS = {
  reminder: 'reminders',
  cancel_by: 'contracts', term_end: 'contracts', price_increase: 'contracts', price_guarantee_end: 'contracts', contract: 'contracts',
  bill_expected: 'contracts',   // v3.5.0 (F1029) — Abrechnung erwartet
  reading_due: 'readings',
  tank_reorder: 'tank',
  tenancy_statement_due: 'tenancy', objection_deadline: 'tenancy', co2_claim_deadline: 'tenancy',
  tenancy_statement: 'tenancy',  // v3.5.0 — Nebenkostenabrechnung geändert
  recommendation: 'recommendations',
  inbox: 'contracts',            // v3.7.0 — aus einem Dokument im Posteingang übernommen
};
export const AREA_KEYS = ['reminders', 'contracts', 'readings', 'tank', 'tenancy', 'recommendations'];

/** Integrationen tragen Produktnamen — gleich in jeder Sprache. */
const INTEGRATIONS = { home_assistant: 'Home Assistant', evcc: 'evcc' };

const ISO = /^\d{4}-\d{2}-\d{2}$/;

/** Parameter für die Anzeige: Tage in der Schreibweise der Sprache. */
export function displayParams(params) {
  const out = {};
  for (const [k, v] of Object.entries(params || {})) out[k] = typeof v === 'string' && ISO.test(v) ? fmt.date(v) : v;
  return out;
}

/** Titel in der Sprache der Oberfläche, sonst die Momentaufnahme. */
export function entryTitle(e) {
  if (e?.title_key) {
    const s = t(e.title_key, displayParams(e.params));
    if (s && s !== e.title_key) return s;
  }
  return e?.title || '';
}

/** Grund eines automatisch erledigten Eintrags („Folgevertrag … ab …"). */
export function reasonText(reason) {
  return reason?.key ? t(reason.key, displayParams(reason.params)) : '';
}

/**
 * v3.6.0 — Name einer Person, wie er beim Erledigen stand; gelöscht mit dem
 * Zusatz „(gelöscht)“, ersetzt durch „ehemalige Person“. Gilt für `by` und die
 * Momentaufnahme `assignee` ({name, id?, deleted?} bzw. {former: true}).
 */
export function personText(p) {
  if (!p) return '';
  if (p.former) return t('activity.by.former');
  return p.deleted ? t('people.deletedName', { name: p.name || '' }) : (p.name || '');
}

/** Wer: Person, ehemalige Person, API-Schlüssel, Integration, von selbst — ohne Anmeldung leer. */
export function byText(by) {
  if (!by) return '';
  switch (by.type) {
    case 'person': return personText(by);
    case 'key': return t('activity.by.key', { name: by.name || '' });
    case 'integration': return INTEGRATIONS[by.name] || by.name || '';
    case 'auto': return t('activity.by.auto');
    default: return '';
  }
}

/** „am 12.10.2026 von Anna", „am 12.10.2026 automatisch" bzw. „am 12.10.2026". */
export function whenWho(date, by) {
  if (by?.type === 'auto') return t('activity.onAuto', { date: fmt.date(date) });
  const who = byText(by);
  return who ? t('activity.onBy', { date: fmt.date(date), by: who }) : t('activity.on', { date: fmt.date(date) });
}

/** Personen, die im Verlauf vorkommen (für den Filter): [{id, name}]; v3.6.0 — gelöschte mit „(gelöscht)“ */
export function personsIn(entries) {
  const seen = new Map();
  for (const e of entries || []) {
    const by = e.by;
    if (by?.type === 'person' && by.id && !seen.has(by.id)) seen.set(by.id, by.name ? personText(by) : by.id);
  }
  return [...seen].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
}
