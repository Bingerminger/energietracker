// v3.9.0 (F1032, GitHub #40) — Rest-Abrechnung am Elternzähler: ein Satz, wie gerechnet wird.
//
// Hat ein Subzähler einen eigenen Vertrag, rechnet der Vertrag des Elternzählers
// nur den Rest. Der Server liefert dazu `rest_of` (Monatszeile, Vertragsstatus,
// Rechnungsprüfung, Prognose): { measured, submeters: [{meter_id, name, kwh}],
// rest, estimated_kwh?, open_days?, short_kwh? }. Gerechnet wird hier nichts,
// nur zusammengezählt und in Worte gefasst.
import { t, tp } from './i18n.js';
import { fmt, escapeHtml } from './format.js';

/** Hinweise mehrerer Monate zu einem (Jahr, Periode). Null ohne Hinweis. */
export function mergeRestOf(list) {
  let acc = null;
  for (const r of list || []) {
    if (!r) continue;
    if (!acc) { acc = { measured: 0, rest: 0, submeters: [], estimated_kwh: 0, open_days: 0, short_kwh: 0 }; }
    acc.measured += Number(r.measured) || 0;
    acc.rest += Number(r.rest) || 0;
    acc.estimated_kwh += Number(r.estimated_kwh) || 0;
    acc.open_days += Number(r.open_days) || 0;
    acc.short_kwh += Number(r.short_kwh) || 0;
    for (const s of r.submeters || []) {
      const hit = acc.submeters.find(x => x.meter_id === s.meter_id);
      if (hit) hit.kwh += Number(s.kwh) || 0;
      else acc.submeters.push({ meter_id: s.meter_id, name: s.name, kwh: Number(s.kwh) || 0 });
    }
  }
  return acc;
}

/** „300 kWh = 500 kWh gemessen − 200 kWh Wärmepumpe“ */
export function restFormula(r, unit = 'kWh') {
  const parts = (r?.submeters || []).map(s => t('restBilling.part', { kwh: fmt.unit(s.kwh, unit, 0), name: s.name }));
  return t('restBilling.formula', { rest: fmt.unit(Math.max(0, r?.rest || 0), unit, 0), measured: fmt.unit(r?.measured || 0, unit, 0), parts: parts.join(' − ') });
}

/** Der ganze Hinweis als Text: Formel und, wo nötig, fortgeschrieben, Tage ohne Stand, Rest unter null. */
export function restNoteText(r, unit = 'kWh') {
  if (!r) return '';
  const out = [t('restBilling.note', { formula: restFormula(r, unit) })];
  if ((r.estimated_kwh || 0) >= 0.5) out.push(t('restBilling.estimated', { kwh: fmt.unit(r.estimated_kwh, unit, 0) }));
  if ((r.open_days || 0) > 0) out.push(tp('restBilling.openDays', r.open_days, { days: r.open_days }));
  if ((r.short_kwh || 0) >= 0.5) out.push(t('restBilling.short', { kwh: fmt.unit(r.short_kwh, unit, 0) }));
  return out.join(' ');
}

/** Als Hinweiszeile (Verbrauchsansicht, Rechnung prüfen). */
export function restNoteHtml(r, unit = 'kWh') {
  if (!r) return '';
  return `<p class="banner banner--info small" data-role="rest-note">${escapeHtml(restNoteText(r, unit))}</p>`;
}

/**
 * Satz im Vertragsdialog, wenn der gewählte Zähler hinter einem anderen hängt:
 * Ab dem Beginn dieses Vertrags rechnet der Vertrag dort nur den Rest.
 * `meter` mit `parent_meter_id`, `meters` die Zähler der Art.
 */
export function subContractHint(meter, meters) {
  const pid = meter?.parent_meter_id;
  if (!pid) return '';
  const parent = (meters || []).find(m => m.id === pid);
  return t('restBilling.contractHint', { name: meter.name || meter.id, parent: parent?.name || pid });
}
