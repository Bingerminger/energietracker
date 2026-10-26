// =====================================================================
// v3.7.0 (treffsicher) — Hinweise und offene Punkte im Posteingang.
//
// Der Server trennt „fehlt im Dokument“ von „zweifelhaft“: Ein Feld trägt
// neben den Gründen einer Marke auch Hinweise ohne Marke (voraussichtlich,
// gestützt, Preis wie im Vertrag), ein Vorschlag seine offenen Punkte (was
// fehlt oder als unsicher nicht übernommen wird) und Hinweise. Hier werden
// daraus die Texte; eigene Datei, damit die Ansicht klein bleibt.
// =====================================================================

import { t } from './i18n.js';
import { fmt, escapeHtml } from './format.js';

/**
 * Hinweis „wohl dasselbe Dokument wie …“ mit Sprung zum älteren (die Vorschau
 * liefert `duplicates`, nach Inhalt erkannt). Gelöscht wird nichts.
 */
export function duplicateHtml(pv) {
  const d = (pv?.duplicates || [])[0];
  if (!d) return '';
  return `<div class="banner banner--warning" id="inbox-duplicate">${escapeHtml(t('inbox.duplicate.text', {
    name: d.name || d.id, date: fmt.date(String(d.created_at || '').slice(0, 10)) }))}
    <a href="#/inbox/${escapeHtml(encodeURIComponent(d.id))}">${escapeHtml(t('inbox.duplicate.open'))}</a></div>`;
}

/** Gründe, die nur erklären (Evidence::HINTS); „missing“ zeigt der Abschnitt „nicht im Dokument“. */
export const FIELD_HINTS = ['estimated', 'supported', 'price_matches', 'net_price', 'vat_changed'];

/** Hinweise eines Felds ohne Marke. */
export const fieldHints = (f) => (f?.reasons || []).filter(r => FIELD_HINTS.includes(r));

const label = (field) => t(`inbox.field.${field}`);

/**
 * Sätze unter einem Vorschlag: Hinweise („Nur voraussichtlich: Lieferbeginn“)
 * und offene Punkte („Fehlt im Dokument: Abschlag“, „Unsicher, nicht
 * übernommen: Tarif“).
 * @param {{hints?: Array<{field: string, reason: string}>, open?: Array<{field: string, reason: string}>}} p
 * @returns {string[]}
 */
export function proposalNotes(p) {
  const out = [];
  const group = (list, reason) => (list || []).filter(x => x.reason === reason).map(x => label(x.field));
  const estimated = group(p?.hints, 'estimated');
  if (estimated.length) out.push(t('inbox.note.estimated', { fields: estimated.join(', ') }));
  const missing = group(p?.open, 'missing');
  if (missing.length) out.push(t('inbox.note.missing', { fields: missing.join(', ') }));
  const unsure = group(p?.open, 'unsure');
  if (unsure.length) out.push(t('inbox.note.unsure', { fields: unsure.join(', ') }));
  // v3.7.0 (treffsicher, Nachbesserung 2) — Preis aus der Rechnung: netto umgerechnet, nur ein anderer Steuersatz
  if (group(p?.hints, 'vat_changed').length) out.push(t('inbox.note.vatChanged'));
  else if (group(p?.hints, 'net_price').length) out.push(t('inbox.note.netPrice'));
  // v3.9.0 (F1034) — Rhythmus laut Rechnung gegen ihre Termine
  if (group(p?.hints, 'rhythm_conflict').length) out.push(t('inbox.note.rhythmConflict'));
  return out;
}
