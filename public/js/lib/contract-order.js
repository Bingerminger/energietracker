// =====================================================================
// v3.4.0 (N1021) — Reihenfolge der Verträge, eine Regel für zwei Listen:
// „Kosten → Verträge“ (views/contracts.js) und „Verträge & Abschläge“ auf der
// Seite der Verbrauchsart (views/utility.js), dort auch mit Gruppenvertrag.
//
// Rückwärts durch die Zeit, der neueste oben: Zukünftig · Aktuell (auch
// verlängert) · Beendet · Angebote. In Zukünftig und Aktuell nach Beginn
// absteigend, in Beendet nach Ende absteigend. Welche Phase ein Vertrag hat,
// sagt der Server (`phase`, ContractService::phase über resolveForDate) —
// hier wird nichts nachgerechnet.
//
// Bis v3.3 sortierte die Vertragsliste nur nach Beginn (Angebote für 2027
// standen als „neuester Vertrag“ ganz oben, ein lange laufender Vertrag ganz
// unten), die Tabelle der Verbrauchsart übernahm die Reihenfolge des Servers:
// älteste oben.
// =====================================================================

import { t } from './i18n.js';
import { escapeHtml } from './format.js';

/** Gruppen in der Reihenfolge der Liste. */
export const GROUPS = ['future', 'current', 'past', 'shadow'];

/** Phase → Gruppe (verlängert zählt zu Aktuell). */
const GROUP_OF = { future: 'future', current: 'current', renewed: 'current', past: 'past', shadow: 'shadow' };

/** Phase → Klasse der Statusmarke (components.css `.status-pill`). */
export const PHASE_PILL = { future: 'future', current: 'active', renewed: 'active', past: 'past', shadow: 'shadow' };

/** Ab so vielen Einträgen ist „Beendet“ eingeklappt. */
export const COLLAPSE_PAST_FROM = 3;

/** Phase eines Vertrags; ohne Angabe des Servers gilt er als aktuell (ältere Antworten). */
export function phaseOf(c) {
  return GROUP_OF[c?.phase] ? c.phase : 'current';
}

/** Statusmarke eines Vertrags — in beiden Listen dieselbe. */
export function phasePill(c) {
  const phase = phaseOf(c);
  return `<span class="status-pill ${PHASE_PILL[phase]}" data-phase="${phase}">${escapeHtml(t('contracts.phase.' + phase))}</span>`;
}

/** Gruppe eines Vertrags (`future`, `current`, `past`, `shadow`). */
export function groupOf(c) {
  return GROUP_OF[phaseOf(c)];
}

// Ohne Ende gilt ein beendeter Vertrag als zuletzt beendet: Er wurde von einem
// später begonnenen abgelöst, steht also oben unter „Beendet“.
const endKey = (c) => String(c?.end || '9999-12-31');
const startKey = (c) => String(c?.start || '');

/** Vergleich für Array.sort: Gruppe, dann rückwärts nach Beginn bzw. Ende. */
export function compareContracts(a, b) {
  const ga = GROUPS.indexOf(groupOf(a)), gb = GROUPS.indexOf(groupOf(b));
  if (ga !== gb) return ga - gb;
  const byKey = groupOf(a) === 'past'
    ? endKey(b).localeCompare(endKey(a)) || startKey(b).localeCompare(startKey(a))
    : startKey(b).localeCompare(startKey(a)) || endKey(b).localeCompare(endKey(a));
  return byKey || String(a?.id ?? a?.contract_id ?? '').localeCompare(String(b?.id ?? b?.contract_id ?? ''));
}

/** Neue, sortierte Liste. */
export function sortContracts(list) {
  return [...(list || [])].sort(compareContracts);
}

/**
 * Sortiert und gruppiert: nur Gruppen mit Einträgen, in der Reihenfolge von
 * GROUPS. `collapsed` sagt, ob die Gruppe eingeklappt beginnt.
 * @returns {{group: string, items: object[], collapsed: boolean}[]}
 */
export function groupContracts(list) {
  const sorted = sortContracts(list);
  return GROUPS
    .map(group => {
      const items = sorted.filter(c => groupOf(c) === group);
      return { group, items, collapsed: group === 'past' && items.length >= COLLAPSE_PAST_FROM };
    })
    .filter(g => g.items.length);
}

/**
 * Jahre als Zusatz am Eintrag (gruppiert wird nach Phase, nicht nach Jahr):
 * `{ span: '2024' }`, `{ span: '2022–2025' }` oder offen `{ since: '2022' }`.
 */
export function yearsOf(c) {
  const a = startKey(c).slice(0, 4);
  const b = String(c?.end || '').slice(0, 4);
  if (!a) return { span: '' };
  if (!b) return { since: a };
  return { span: a === b ? a : `${a}–${b}` };
}
