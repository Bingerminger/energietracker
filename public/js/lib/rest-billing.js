// v3.9.0 (F1032, GitHub #40) — Rest-Abrechnung am Elternzähler: ein Satz, wie gerechnet wird.
//
// Hat ein Subzähler einen eigenen Vertrag, rechnet der Vertrag des Elternzählers
// nur den Rest. Der Server liefert dazu `rest_of` (Monatszeile, Vertragsstatus,
// Rechnungsprüfung, Prognose): { measured, submeters: [{meter_id, name, kwh}],
// rest, estimated_kwh?, open_days?, short_kwh?, registers? }. Gerechnet wird hier
// nichts, nur zusammengezählt und in Worte gefasst. v3.10.0 (#40): Bei einer Gruppe
// (HT/NT) je Zählwerk eine Zeile `registers` [{meter_id, name, obis, measured,
// deducted, rest, short_kwh?}]: Der Rest gilt je Zählwerk (HT − HT, NT − NT).
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
    // v3.10.0 (#40) — je Zählwerk zusammenzählen
    for (const g of r.registers || []) {
      acc.registers ||= [];
      let hit = acc.registers.find(x => x.meter_id === g.meter_id);
      if (!hit) { hit = { meter_id: g.meter_id, name: g.name, obis: g.obis || '', measured: 0, deducted: 0, rest: 0, short_kwh: 0 }; acc.registers.push(hit); }
      for (const k of ['measured', 'deducted', 'rest', 'short_kwh']) hit[k] += Number(g[k]) || 0;
    }
  }
  return acc;
}

/**
 * v3.10.0 (#40) — Rest je Zählwerk: „HT 250 kWh = 400 kWh − 150 kWh · NT 200 kWh = 300 kWh − 100 kWh“.
 * Ein Zählwerk unter null zählt mit 0 (es wird nicht gegen ein anderes verrechnet).
 */
export function registerFormula(r, unit = 'kWh') {
  return (r?.registers || []).map(g => t('restBilling.registerPart', {
    name: g.obis ? shortName(g) : g.name,
    rest: fmt.unit(Math.max(0, g.rest || 0), unit, 0), measured: fmt.unit(g.measured || 0, unit, 0), deducted: fmt.unit(g.deducted || 0, unit, 0),
  })).join(' · ');
}

/** Kurzname eines Zählwerks: HT/NT aus der Kennzahl in der Sprache der Oberfläche, sonst der Zählername. */
function shortName(g) {
  const k = String(g.obis || '').match(/1\.8\.([12])$/);
  return k ? t(k[1] === '1' ? 'concept.regShort.ht' : 'concept.regShort.nt') : g.name;
}

/** „300 kWh = 500 kWh gemessen − 200 kWh Wärmepumpe“; v3.10.0: mit mehreren Zählwerken je Zählwerk */
export function restFormula(r, unit = 'kWh') {
  if ((r?.registers || []).length > 1) {
    const billed = r.registers.reduce((s, g) => s + Math.max(0, Number(g.rest) || 0), 0);
    return t('restBilling.formulaRegisters', { rest: fmt.unit(billed, unit, 0), parts: registerFormula(r, unit) });
  }
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

/**
 * v3.9.0 (Skeptiker N7) — Subzähler, deren eigener Vertrag vom Vertrag dieses
 * Ziels abgezogen wird (wie ConsumptionService::deductingSubmeters, ohne
 * Rechnung): Ziel ist ein Zähler (`meter_id`) oder eine Gruppe
 * (`meter_group_id`; dann die Subzähler ihrer Mitglieder außerhalb der Gruppe).
 * Abziehend ist ein Subzähler mit einem echten Vertrag (kein Angebot) an ihm
 * oder an seiner Gruppe. Wasser rechnet keinen Rest (Abzug über Schmutzwasser).
 * @returns {string[]} Namen
 */
export function deductingSubNames(target, meters, contracts, utilityKey = '') {
  if (utilityKey === 'wasser') return [];
  const list = meters || [];
  const parents = target?.meter_group_id
    ? list.filter(m => m.meter_group_id === target.meter_group_id).map(m => m.id)
    : (target?.meter_id ? [target.meter_id] : []);
  const real = (contracts || []).filter(c => !c.is_shadow);
  return list.filter(m => parents.includes(m.parent_meter_id)
      && !(target?.meter_group_id && m.meter_group_id === target.meter_group_id)
      && real.some(c => c.meter_id === m.id || (m.meter_group_id && c.meter_group_id === m.meter_group_id)))
    .map(m => m.name || m.id);
}
