// =====================================================================
// v3.7.0 (Haushalt aus Dokumenten) — Texte und Auswahl des Entwurfs, ohne
// Server. Der Entwurf (GET /api/inbox/draft) liefert Einträge mit
// Katalogschlüssel, Parametern und Feldern (Wert, Belege, Marke, Bestand,
// Optionen); hier entstehen daraus die Texte in der Sprache der Oberfläche und
// die Anfrage fürs Übernehmen. Eine Stelle für Ansicht und Test
// (tests/inbox-draft.test.mjs).
// =====================================================================

import { t, tp } from './i18n.js';
import { fmt, escapeHtml } from './format.js';
import { resultText } from './inbox.js';
import { proposalNotes } from './inbox-marks.js';
import { priceBasisText } from './price-basis.js';

/** Gründe, die der Entwurf selbst vergibt (die übrigen kommen aus der Belegprüfung, `inbox.reason.*`). */
export const DRAFT_REASONS = ['differs', 'conflict_existing', 'invoice_differs', 'chain_mismatch', 'inferred', 'falling_reading', 'assumed_date', 'number_change', 'swap_open', 'swap_readings',
  'existing_update', 'existing_overlap'];

/** Status eines Eintrags: neu, schon vorhanden, Widerspruch, Überschneidung, ergänzen. */
export const statusText = (status) => (status === 'update' ? t('inboxDraft.status.update') : t(`inbox.pstatus.${status || 'new'}`));

export const reasonText = (reasons) => (reasons || [])
  .map(r => t(DRAFT_REASONS.includes(r) ? `inboxDraft.reason.${r}` : `inbox.reason.${r}`)).join(' · ');

/** Datum aus einem Feldnamen wie `wp:2025-04-01` oder `r:2025-12-31`. */
const dateOf = (name) => String(name).split(':')[1] || '';

/** Beschriftung eines Felds je Eintrag. */
export function fieldLabel(item, f) {
  const n = String(f.name);
  const prefix = n.includes(':') ? n.split(':')[0] : null;
  if (prefix === 'wp') return t('inboxDraft.field.wp', { date: fmt.date(dateOf(n)) });
  if (prefix === 'bp') return t('inboxDraft.field.bp', { date: fmt.date(dateOf(n)) });
  if (prefix === 'ap') return t('inboxDraft.field.ap', { date: fmt.date(dateOf(n)) });
  if (prefix === 'r') return t('inboxDraft.field.reading', { date: fmt.date(dateOf(n)) });
  if (prefix === 'gf') return t('inboxDraft.field.gf', { date: fmt.date(dateOf(n)) });
  const own = { name: 'inboxDraft.field.name', installed_on: 'inboxDraft.field.installed_on', start: 'inboxDraft.field.start', end: 'inboxDraft.field.end',
    min_term_end: 'inboxDraft.field.min_term_end', due: 'inboxDraft.field.due', result: 'inbox.field.result_kind' };
  if (own[n]) return t(own[n]);
  const inbox = { serial: item.kind === 'meter_change' ? 'new_meter_number' : 'meter_number', malo: 'market_location', provider: 'supplier',
    date: 'change_date', old_final: 'old_reading', new_initial: 'new_reading', notice: 'notice_period', signup_bonus_eur: 'bonus' }[n] || n;
  return t(`inbox.field.${inbox}`);
}

/** Ein Wert nach der Art seines Felds. */
export function valueText(f, v = f.value, { unit = '' } = {}) {
  if (v === null || v === undefined || v === '') return '–';
  switch (f.kind) {
    case 'date': return fmt.date(v);
    case 'money': return f.name === 'result' ? (resultText(v) || '–') : fmt.money(v);
    case 'price_ct': return `${fmt.dec(v, 4)} ct/kWh`;
    case 'factor': return t('inboxDraft.value.factor', { z: fmt.dec(v.z, 4), hs: fmt.dec(v.hs, 3), factor: fmt.dec(v.z * v.hs, 4) });
    case 'enum': return t(`inbox.option.${v}`);
    case 'number': {
      const u = f.unit || (String(f.name).startsWith('r:') || ['old_final', 'new_initial'].includes(f.name) ? unit : '');
      return `${fmt.dec(v, 3)}${u ? ` ${u}` : ''}`;
    }
    default: return String(v);
  }
}

/** Woher ein Wert kommt, wenn nicht aus einem Dokument (Beginn aus dem Brief, Ende vor dem Folgevertrag …). */
export function basisText(f) {
  const parts = [];
  const group = f.name === 'end' ? 'end' : (/^(wp|bp|ap):/.test(String(f.name)) ? 'price' : 'start');
  if (f.basis && !['confirmation', 'document'].includes(f.basis)) parts.push(t(`inboxDraft.basis.${group}.${f.basis}`));
  if (f.hint === 'earliest') parts.push(t('inboxDraft.basis.earliest'));
  if (f.hint === 'newest') parts.push(t('inboxDraft.basis.newest'));
  // Preis aus einer Rechnung mit Nettopreis: Basis und Ergebnis (dieselbe Hilfe wie in der Vorschau)
  if (f.net) parts.push(priceBasisText({ vatPct: f.net.vat_pct, gross: f.value, kind: f.kind }));
  if (f.estimated) parts.push(t('inbox.reason.estimated'));
  return parts.join(' · ');
}

/** Überschrift eines Eintrags („Vertrag Nordlicht ab 01.04.2025“). */
export function itemText(item) {
  const a = { ...(item.params || {}) };
  for (const k of ['date', 'from', 'to']) if (typeof a[k] === 'string') a[k] = fmt.date(a[k]);
  if (item.kind === 'bill') a.result = resultText(item.params?.result) || '–';
  if (typeof a.to === 'undefined' || a.to === null) a.to = '…';
  a.name = a.name || '';
  a.serial = a.serial || '–';
  a.provider = a.provider || t('inboxDraft.unknownProvider');
  return t(item.label_key, a);
}

/** Was der Eintrag tut, in einem Satz unter der Überschrift (Anzahl der Zeilen). */
export function itemCount(item) {
  const todo = (item.fields || []).filter(f => f.status !== 'exists').length;
  if (['readings', 'prices', 'advances', 'gas_factor'].includes(item.kind)) return tp('inboxDraft.rows', todo);
  return '';
}

/** Zusammenfassung oben: „1 Zähler, 1 Vertrag, 2 Ablesungen, 1 Rechnung aus 3 Dokumenten; 1 Punkt braucht dich.“ */
export function summaryText(s) {
  if (!s || !s.documents) return t('inboxDraft.empty');
  const parts = [];
  for (const [k, key] of [['meters', 'meters'], ['contracts', 'contracts'], ['readings', 'readings'], ['bills', 'bills'], ['reminders', 'reminders']]) {
    if (s[k]) parts.push(tp(`inboxDraft.count.${key}`, s[k]));
  }
  const what = parts.length ? parts.join(', ') : t('inboxDraft.count.nothing');
  const from = tp('inboxDraft.count.documents', s.documents);
  const ask = s.questions ? tp('inboxDraft.count.questions', s.questions) : t('inboxDraft.count.noQuestions');
  return t('inboxDraft.summary', { what, from, ask });
}

/** Hinweise unter der Zusammenfassung: wohl doppelt, ohne Beitrag. */
export function summaryNote(s) {
  const out = [];
  if (s?.duplicates) out.push(tp('inboxDraft.count.duplicates', s.duplicates));
  if (s?.without) out.push(tp('inboxDraft.count.without', s.without));
  return out.join(' · ');
}

/** Ein Befund über Dokumente hinweg (Lücke, Überschneidung, fallender Stand …). */
export function findingText(f) {
  const p = { ...(f.params || {}) };
  for (const k of ['date', 'from', 'to']) if (typeof p[k] === 'string') p[k] = fmt.date(p[k]);
  for (const k of ['a', 'b']) if (typeof p[k] === 'number') p[k] = fmt.dec(p[k], 3);
  // Rechnungspreis gegen die Kette: Arbeitspreis in ct/kWh, Grundpreis in € je Monat
  for (const k of ['invoice', 'chain']) if (typeof p[k] === 'number') p[k] = valueText({ kind: f.kind === 'invoice_wp' ? 'price_ct' : 'money' }, p[k]);
  return t(`inboxDraft.finding.${f.kind}`, p);
}

/** Was in keinem Dokument steht (freundlich, kein Fehler). */
export function missingText(m, utilityLabel = '') {
  // offene Punkte eines Dokuments (fehlt / unsicher): dieselben Sätze wie unter einem Vorschlag
  if (m.kind === 'open') return t('inboxDraft.missing.open', { utility: utilityLabel, name: m.params?.name || '', notes: proposalNotes({ open: m.open }).join(' · ') });
  const p = { ...(m.params || {}), utility: utilityLabel };
  for (const k of ['date', 'from', 'to']) if (typeof p[k] === 'string') p[k] = fmt.date(p[k]);
  p.provider = p.provider || t('inboxDraft.unknownProvider');
  return t(`inboxDraft.missing.${m.kind}`, p);
}

export const docStateText = (state) => t(`inboxDraft.docState.${state}`);

/** Vorbelegte Auswahl: angehakte Einträge, keine bestätigten Felder, die erste Option jeder Entscheidung. */
export function initialSelection(draft) {
  return {
    items: new Set((draft?.items || []).filter(i => i.checked && i.actionable).map(i => i.id)),
    confirm: new Map(),   // id → Set(Feldnamen)
    choose: new Map(),    // id → Map(Feldname → Option)
  };
}

/** Ist das Feld zweifelhaft und braucht eine eigene Bestätigung (nicht Kern, nicht vorhanden)? */
export const needsConfirm = (f) => !!f.check && f.status !== 'exists' && !(f.core && f.status !== 'conflict') && !f.options;

/** Die Anfrage fürs Übernehmen (POST /api/inbox/draft/apply). */
export function applyPayload(sel) {
  const confirm = {};
  const choose = {};
  for (const id of sel.items) {
    const c = sel.confirm.get(id);
    if (c && c.size) confirm[id] = [...c];
    const ch = sel.choose.get(id);
    if (ch && ch.size) choose[id] = Object.fromEntries(ch);
  }
  return { items: [...sel.items], confirm, choose };
}

/** Was nach dem Übernehmen entstanden ist, in Zeilen. */
export function resultLines(res) {
  const c = res?.created || {};
  const lines = [];
  for (const k of ['meters', 'meter_changes', 'contracts', 'rows', 'readings', 'bills', 'booked', 'gas_factors', 'reminders']) {
    if (c[k]) lines.push(tp(`inboxDraft.result.${k}`, c[k]));
  }
  if (res?.documents?.done) lines.push(tp('inboxDraft.result.done', res.documents.done));
  return lines;
}

/** Sprung zu „Rechnung prüfen“ für eine übernommene Rechnung. */
export const billCheckHref = (b) => `#/bill-check?${new URLSearchParams({ utility: b.utility, meter: b.meter_id, from: b.period_from, until: b.period_to })}`;

/** Einstieg im Posteingang: ab einem ausgelesenen Dokument (gezählt wie im Entwurf: Stand „ausgelesen“). */
export function draftEntryCount(entries) {
  return (entries || []).filter(e => e?.status === 'read').length;
}

/** Der Knopf zum Entwurf über der Liste des Posteingangs (leer, solange nichts ausgelesen ist). */
export function draftEntryHtml(entries) {
  const n = draftEntryCount(entries);
  if (!n) return '';
  return `<p class="inbox-draft-entry" id="inbox-draft-entry"><a class="btn btn--primary" href="#/inbox/draft">${escapeHtml(tp('inboxDraft.entry', n))}</a>
    <span class="muted small">${escapeHtml(t('inboxDraft.entryHint'))}</span></p>`;
}
