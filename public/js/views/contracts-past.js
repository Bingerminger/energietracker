// =====================================================================
// v3.12.0 (M4) — Kosten › Frühere Verträge: beendete und abgelöste Verträge an einem Ort.
//
// Bis v3.11 standen sie nur je Zähler unter „Beendet (n)“ in der Tabelle der Verbrauchsart; das
// bleibt. Gerechnet wird nichts Neues: Die Zahlen kommen aus `contract-status`, derselben Quelle
// wie diese Tabelle. Der Saldo ist der Saldo am Vertragsende (Verbraucht minus Bezahlt, mit
// Sonderzahlungen), kein Preisvergleich mit dem Folgevertrag (Sichtung S2). Seit v3.13.0 zählt er
// ab dem Tag nach der letzten gebuchten Rechnung des Vertrags; deckt sie das Ende, ist er abgerechnet.
// =====================================================================

import { api } from '../api.js';
import { activeUtilities } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { t, tp, getCurrencyMinor } from '../lib/i18n.js';
import { rich } from '../lib/rich.js';
import { artIcon } from '../lib/icons.js';
import { emptyHtml } from '../lib/empty.js';
import { balanceView, isFeedIn } from '../lib/semantics.js';
import { advanceAmountText } from '../lib/advance-rhythm.js';
import { groupOf, compareContracts, phasePill } from '../lib/contract-order.js';
import { dispatchContractModal } from './contracts.js';

/** Filter, solange die Seite offen ist. */
const filter = { art: '', meter: '', year: '' };

/**
 * Früherer Vertrag als flache Zeile. Rein, ohne DOM.
 * @param {object} c       Zeile aus contract-status (Phase past)
 * @param {object} u       Verbrauchsart
 * @param {{meter: string, stored: ?object, bills: Array<object>, targetId: string, isGroup: boolean, successorName?: string}} o
 */
export function pastRow(c, u, { meter, stored, bills, targetId, isGroup, successorName = '' }) {
  const end = c.effective_end || c.end;
  const overlapping = bills.filter(b => (!isGroup && b.meter_id === targetId) && b.period_from <= end && b.period_to >= c.start);
  return {
    id: c.contract_id,
    art: u.key,
    meterId: targetId,
    meterName: meter,
    years: yearsOf(c.start, end),
    c, u, stored, bills: overlapping, isGroup,
    successor: c.successor ? (successorName || c.successor.provider || '') : '',
  };
}

function yearsOf(from, until) {
  const a = Number(String(from || '').slice(0, 4)), b = Number(String(until || '').slice(0, 4));
  if (!a || !b) return [];
  return Array.from({ length: Math.max(0, b - a) + 1 }, (_, i) => a + i);
}

/** Eine Zeile der Tabelle; unter 900 px stapelt lib/table-stack.js sie nach den Spaltenköpfen. */
export function pastRowHtml(row) {
  const { c, u } = row;
  const feedIn = isFeedIn(u);
  const name = [c.provider, c.tariff_name].filter(Boolean).join(' · ') || '–';
  const end = c.effective_end || c.end;
  const prices = [];
  if (c.current_working_price_ct != null) prices.push(`${fmt.num(c.current_working_price_ct, 4)} ${getCurrencyMinor()}`);
  if (c.current_base_price_eur != null) prices.push(t('utility.contractsTable.gpSuffix', { value: fmt.num(c.current_base_price_eur, 2) }));
  const bv = balanceView(c.current_balance, u);
  const bonus = c.bonus_to_date ?? c.actual_bonus_total;
  const special = Number(c.special_payment_net) || 0;
  // v3.13.0 — abgerechnet: Eine gebuchte Rechnung deckt das Ende (`settled_until`). Nichts ist mehr offen, der Saldo steht
  // bei 0,00 €; Verbraucht und Bezahlt zählen erst nach der Rechnung, also nichts mehr (–), statt 0,00 € neben einem
  // Vertrag, der lief
  const settled = typeof c.settled_until === 'string' && c.settled_until !== '';
  const settledNote = settled ? `<small data-role="past-settled">${escapeHtml(t('contractsPast.settled', { date: fmt.date(c.settled_until) }))}</small>` : '';
  // v3.13.0 (Paket K2e) — „Rechnung prüfen/erfassen“ steht bei „Ansehen“ in der angehefteten Spalte der Aktionen
  // (.spalte-aktionen): Zwischen 900 und 1280 px lagen beide Knöpfe außerhalb des Tabellenrahmens. Die Spalte
  // „Rechnung“ nennt den Stand.
  const canBill = u.supports_bill_check && !row.isGroup;
  const billCell = !canBill ? '<span class="muted">–</span>'
    : `<small>${escapeHtml(row.bills.length ? tp('contractsPast.billCount', row.bills.length, {}) : t('contractsPast.billNone'))}</small>`;
  const billBtn = !canBill ? ''
    : row.bills.length
      ? `<a class="btn btn--ghost btn--sm" href="#/bill-check?utility=${encodeURIComponent(u.key)}&meter=${encodeURIComponent(row.meterId)}&from=${encodeURIComponent(row.bills[0].period_from)}&until=${encodeURIComponent(row.bills[0].period_to)}" data-role="past-bill">${escapeHtml(t('contractsPast.billOpen'))}</a>`
      : `<a class="btn btn--ghost btn--sm" href="#/bill-check?utility=${encodeURIComponent(u.key)}&meter=${encodeURIComponent(row.meterId)}&from=${encodeURIComponent(c.start)}&until=${encodeURIComponent(end)}" data-role="past-bill">${escapeHtml(t('contractsPast.billAdd'))}</a>`;
  return `<tr data-contract-id="${escapeHtml(c.contract_id)}" data-art="${escapeHtml(u.key)}">
    <td class="provider-cell"><span class="past__name">${artIcon(u, { size: 16 })} ${escapeHtml(name)}</span><small>${escapeHtml(u.label)}, ${escapeHtml(row.meterName)}</small></td>
    <td class="period-cell">${escapeHtml(t('contractsOverview.term', { start: fmt.date(c.start), end: fmt.date(end) }))}
      ${c.end && end !== c.end ? `<small>${escapeHtml(t('contractsPast.enteredUntil', { date: fmt.date(c.end) }))}</small>` : ''}
      <small>${phasePill(c)}${row.successor ? ` ${escapeHtml(t('contractsPast.supersededBy', { name: row.successor }))}` : ''}</small></td>
    <td class="muted">${prices.length ? prices.map(p => `<span class="tariff-part">${escapeHtml(p)}</span>`).join('<br>') : '–'}</td>
    <td class="num">${c.current_advance_amount != null ? escapeHtml(advanceAmountText(c.current_advance_amount, c.current_advance_every_months)) : '–'}</td>
    <td class="num">${settled ? '<span class="muted">–</span>' : `${escapeHtml(fmt.eur(c.cost_to_date ?? c.actual_cost))}${bonus > 0 ? `<small class="ton-ok">${escapeHtml(t('contractsPast.bonus', { value: fmt.eur(bonus) }))}</small>` : ''}`}${feedIn ? `<small>${escapeHtml(t('utility.contractsTable.colEarned'))}</small>` : ''}</td>
    <td class="num">${settled ? '<span class="muted">–</span>' : escapeHtml(fmt.eur(c.advance_paid))}${feedIn ? `<small>${escapeHtml(t('utility.contractsTable.colReceived'))}</small>` : ''}</td>
    <td class="num" data-role="past-balance">${bv ? `<strong class="${bv.cls}">${escapeHtml(bv.signed)}</strong><small class="${bv.cls}">${escapeHtml(bv.credit === null ? '' : bv.word)}</small>` : '<span class="muted">–</span>'}${special && !settled ? `<small>${escapeHtml(t('contractsPast.special', { value: `${special > 0 ? '+' : '−'}${fmt.eur(Math.abs(special))}` }))}</small>` : ''}${settledNote}</td>
    <td>${billCell}</td>
    <td class="spalte-aktionen past__aktionen">${billBtn}<button type="button" class="btn btn--ghost btn--sm" data-past-view="${escapeHtml(c.contract_id)}" aria-label="${escapeHtml(t('contractsPast.viewNamed', { name }))}">${escapeHtml(t('contractsPast.view'))}</button></td>
  </tr>`;
}

/** Arten, deren Verträge diese Seite zeigt (ohne Lieferungen und Arten ohne Verträge). */
const pastUtilities = async () => (await activeUtilities()).filter(u => u.has_contracts !== false && u.reading_kind !== 'delivery');

/**
 * v3.13.0 — Zahl der früheren Verträge für den Reiter (lib/sidebar.js): dieselbe Menge wie die Seite, aus
 * weniger Anfragen. Die Seite liest den Vertragsstatus je Zähler und Gruppe; die Phase (`past`) rechnet der
 * Server für die Vertragsliste mit derselben Regel (ContractService::phase). Gezählt werden Verträge an
 * Zählern und Gruppen, die es gibt; Angebote zählen nicht (eigene Phase).
 * @returns {Promise<number>}
 */
export async function countPastContracts() {
  const utilities = await pastUtilities();
  if (!utilities.length) return 0;
  const [ov, lists] = await Promise.all([
    api.metersOverview(),
    Promise.all(utilities.map(u => api.contracts(u.key).catch(() => []))),
  ]);
  const byKey = new Map((ov?.utilities || []).map(x => [x.utility, x]));
  return utilities.reduce((n, u, i) => {
    const row = byKey.get(u.key);
    const targets = new Set([...(row?.meters || []).map(m => m.id), ...(row?.groups || []).map(g => g.id)]);
    return n + (lists[i] || []).filter(c => groupOf(c) === 'past'
      && ((c.meter_id && targets.has(c.meter_id)) || (c.meter_group_id && targets.has(c.meter_group_id)))).length;
  }, 0);
}

export async function render(container) {
  const header = `<div class="view-header"><div>
      <h1 class="view-header__title">${escapeHtml(t('contractsPast.title'))}</h1>
      <p class="view-header__subtitle">${rich('contractsPast.subtitle')}</p>
    </div></div>`;
  container.innerHTML = `${header}<div class="loading" role="status">${escapeHtml(t('common.loading'))}</div>`;
  const utilities = await pastUtilities();
  const ctxOf = new Map();
  const lists = await Promise.all(utilities.map(async (u) => {
    const [meters, stored, groups, bills] = await Promise.all([api.meters(u.key), api.contracts(u.key).catch(() => []),
      api.meterGroups(u.key).catch(() => []), u.supports_bill_check ? api.bills(u.key).catch(() => []) : []]);
    ctxOf.set(u.key, { u, meters, stored, groups });
    const byId = new Map((stored || []).map(c => [c.id, c]));
    const targets = [...meters.map(m => ({ id: m.id, name: m.name, isGroup: false })), ...(groups || []).map(g => ({ id: g.id, name: g.name, isGroup: true }))];
    const statuses = await Promise.all(targets.map(x => api.contractStatus(u.key, x.id).catch(() => null)));
    const rows = new Map();
    statuses.forEach((st, i) => {
      for (const c of st?.contracts || []) {
        if (groupOf(c) !== 'past' || rows.has(c.contract_id)) continue;
        const own = byId.get(c.contract_id);
        // Der Folgevertrag mit Tarifnamen, wie ihn die Verträge nennen
        const next = c.successor ? byId.get(c.successor.contract_id) : null;
        rows.set(c.contract_id, pastRow(c, u, {
          meter: targets[i].name, targetId: targets[i].id, isGroup: targets[i].isGroup, bills: bills || [],
          stored: own || null, successorName: next?.tariff_name || next?.provider || '',
        }));
      }
    });
    return [...rows.values()];
  }));
  const rows = lists.flat().sort((a, b) => compareContracts(a.c, b.c));
  if (filter.art && !rows.some(r => r.art === filter.art)) filter.art = '';
  draw(container, header, rows, ctxOf, utilities);
}

function draw(container, header, rows, ctxOf, utilities) {
  if (!rows.length) {
    container.innerHTML = `${header}${emptyHtml({ icon: 'dokumente', title: t('contractsPast.empty.title'), text: t('contractsPast.empty.text'), role: 'past-empty' })}`;
    return;
  }
  const arts = utilities.filter(u => rows.some(r => r.art === u.key));
  const inArt = rows.filter(r => !filter.art || r.art === filter.art);
  const meters = [...new Map(inArt.map(r => [r.meterId, r.meterName])).entries()];
  if (filter.meter && !meters.some(([id]) => id === filter.meter)) filter.meter = '';
  const years = [...new Set(rows.flatMap(r => r.years))].sort((a, b) => b - a);
  const shown = inArt.filter(r => (!filter.meter || r.meterId === filter.meter) && (!filter.year || r.years.includes(Number(filter.year))));
  container.innerHTML = `${header}
    <div class="seg past__arten" role="group" aria-label="${escapeHtml(t('contractsPast.filter.art'))}">
      <button type="button" class="seg__btn${filter.art ? '' : ' active'}" data-past-art="" aria-pressed="${!filter.art}">${escapeHtml(t('contractsPast.filter.all'))} <span class="muted">${rows.length}</span></button>
      ${arts.map(u => `<button type="button" class="seg__btn${filter.art === u.key ? ' active' : ''}" data-past-art="${escapeHtml(u.key)}" aria-pressed="${filter.art === u.key}">${escapeHtml(u.label)} <span class="muted">${rows.filter(r => r.art === u.key).length}</span></button>`).join('')}
    </div>
    <div class="past__filter">
      <div class="field"><label for="past-meter">${escapeHtml(t('contractsPast.filter.meter'))}</label>
        <select class="select" id="past-meter"><option value="">${escapeHtml(t('contractsPast.filter.all'))}</option>${meters.map(([id, name]) => `<option value="${escapeHtml(id)}"${filter.meter === id ? ' selected' : ''}>${escapeHtml(name)}</option>`).join('')}</select></div>
      <div class="field"><label for="past-year">${escapeHtml(t('contractsPast.filter.year'))}</label>
        <select class="select" id="past-year"><option value="">${escapeHtml(t('contractsPast.filter.all'))}</option>${years.map(y => `<option value="${y}"${String(filter.year) === String(y) ? ' selected' : ''}>${y}</option>`).join('')}</select></div>
    </div>
    <section class="card past__liste" aria-label="${escapeHtml(t('contractsPast.title'))}">
      ${shown.length ? `<div class="table-wrap"><table class="table past__tabelle">
        <thead><tr>
          <th scope="col">${escapeHtml(t('contractsPast.col.contract'))}</th>
          <th scope="col">${escapeHtml(t('contractsPast.col.term'))}</th>
          <th scope="col">${escapeHtml(t('contractsPast.col.prices'))}</th>
          <th scope="col" class="num">${escapeHtml(t('utility.contractsTable.colAdvance'))}</th>
          <th scope="col" class="num">${escapeHtml(t('utility.contractsTable.colConsumed'))}</th>
          <th scope="col" class="num">${escapeHtml(t('utility.contractsTable.colPaid'))}</th>
          <th scope="col" class="num">${escapeHtml(t('contractsPast.col.balance'))}</th>
          <th scope="col">${escapeHtml(t('contractsPast.col.bill'))}</th>
          <th scope="col" class="spalte-aktionen"><span class="sr-only">${escapeHtml(t('contractsPast.col.action'))}</span></th>
        </tr></thead>
        <tbody>${shown.map(pastRowHtml).join('')}</tbody>
      </table></div>
      <p class="fussnote" data-role="past-legend">${escapeHtml(t('utility.contractsTable.pastLegend'))}</p>`
      : `<p class="muted" data-role="past-none">${escapeHtml(t('contractsPast.emptyFiltered'))}</p>`}
    </section>`;
  const again = () => draw(container, header, rows, ctxOf, utilities);
  container.querySelectorAll('[data-past-art]').forEach(b => b.addEventListener('click', () => { filter.art = b.dataset.pastArt; filter.meter = ''; again(); }));
  container.querySelector('#past-meter')?.addEventListener('change', (e) => { filter.meter = e.target.value; again(); });
  container.querySelector('#past-year')?.addEventListener('change', (e) => { filter.year = e.target.value; again(); });
  container.querySelectorAll('[data-past-view]').forEach(b => b.addEventListener('click', async () => {
    const row = rows.find(r => r.id === b.dataset.pastView);
    const x = row && ctxOf.get(row.art);
    if (!x || !row.stored) return;
    if (await dispatchContractModal(x.u, x.meters, row.stored, x.stored, x.groups)) render(container);
  }));
}
