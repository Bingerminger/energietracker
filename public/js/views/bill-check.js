// =====================================================================
// Energietracker — Rechnung prüfen (F1012, v2.5.0; eigene Seite seit v2.11.0)
//
// Rechnet die Versorgerrechnung nach: Für den gewählten Zeitraum liefert das
// Backend Abschnitte an jeder Ablesung (bei Gas auch an jedem
// Brennwertwechsel, sonst an Vertrags- und Preisstichtagen) — mit Menge und,
// seit v3.1.0, mit den Kosten nach denselben Regeln wie Monatssicht und Saldo.
//
// v3.1.0 (Paket H5, UI-35/MKT-17) — für alle Arten mit Zählerständen und
// Verträgen (Gas, Strom, Fernwärme, Wasser), dazu „Laut Rechnung": die Werte
// des Versorgers eintragen, die App zeigt die Abweichung und bucht das
// Ergebnis auf Wunsch als Sonderzahlung.
// =====================================================================

import { api } from '../api.js';
import { activeUtilities } from '../state.js';
import { fmt, escapeHtml, parseDecimal, formatForInput } from '../lib/format.js';
import { toastErr, toastOk } from '../components/toast.js';
import { confirmModal } from '../components/modal.js';
import { t, tp, getCurrencyMinor } from '../lib/i18n.js';
import { info } from '../components/info.js';

const ISO = /^\d{4}-\d{2}-\d{2}$/;

export async function render(container, _params = [], ctx = {}) {
  const query = ctx.query || new URLSearchParams();
  const utils = (await activeUtilities().catch(() => [])).filter(u => u.supports_bill_check);
  const u = utils.find(x => x.key === query.get('utility')) || utils.find(x => x.key === 'gas') || utils[0];
  // v3.1.0 (H5) — Untertitel je Art: Gas mit Zustandszahl und Brennwert, sonst Arbeits- und Grundpreis
  const header = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('nav.billCheck'))}</h1>
        <p class="view-header__subtitle">${t(!u || u.key === 'gas' ? 'utility.billCheck.hint' : 'utility.billCheck.hintGeneric')}</p>
      </div>
    </div>`;
  const meters = u ? await api.meters(u.key).catch(() => []) : [];
  if (!u || !meters.length) {
    container.innerHTML = `${header}
      <div class="empty">
        <p>${escapeHtml(t('billCheck.noMeter'))}</p>
        <a class="btn btn--primary" href="#/utility/${escapeHtml(u?.key || 'gas')}/meters">${escapeHtml(t('billCheck.addMeter'))}</a>
      </div>`;
    return;
  }

  const wanted = query.get('meter');
  let current = meters.find(m => m.id === wanted) || meters[0];
  const year = new Date().getFullYear() - 1;
  const from = ISO.test(query.get('from') || '') ? query.get('from') : `${year}-01-01`;
  const to   = ISO.test(query.get('to') || '')   ? query.get('to')   : `${year + 1}-01-01`;

  container.innerHTML = `${header}
    <div class="card" data-utility="${escapeHtml(u.key)}">
      <div class="form-row">
        ${utils.length > 1 ? `
        <div class="field">
          <label for="bc-utility">${escapeHtml(t('billCheck.pickUtility'))}</label>
          <select class="select" id="bc-utility">
            ${utils.map(x => `<option value="${escapeHtml(x.key)}"${x.key === u.key ? ' selected' : ''}>${escapeHtml(x.icon || '')} ${escapeHtml(x.label)}</option>`).join('')}
          </select>
        </div>` : ''}
        ${meters.length > 1 ? `
        <div class="field">
          <label for="bc-meter">${escapeHtml(t('billCheck.meter'))}</label>
          <select class="select" id="bc-meter">
            ${meters.map(m => `<option value="${escapeHtml(m.id)}"${m.id === current.id ? ' selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
          </select>
        </div>` : ''}
      </div>
      ${billCheckForm(from, to)}
    </div>
    <div class="card" data-role="bills"></div>`;

  container.querySelector('#bc-utility')?.addEventListener('change', (e) => {
    location.hash = `#/bill-check?utility=${encodeURIComponent(e.target.value)}`;
  });
  container.querySelector('#bc-meter')?.addEventListener('change', (e) => {
    current = meters.find(m => m.id === e.target.value) || current;
    container.querySelector('#bc-result').innerHTML = '';
    drawBills(container, u, current);
  });
  wireBillCheck(container, u, () => current);
  drawBills(container, u, current);
  // Mit Zeitraum in der Adresse gleich rechnen (Verweis von der Gas-Seite)
  if (query.get('from') && query.get('to')) container.querySelector('#bc-run')?.click();
}

/** Eingaben „von – bis" und Ergebnisbereich. */
export function billCheckForm(from, to) {
  return `
      <div class="form-row" style="align-items:flex-end; margin-bottom:10px">
        <div class="field">
          <label for="bc-from">${t('utility.billCheck.from')}</label>
          <input class="input" id="bc-from" type="date" value="${escapeHtml(from)}">
        </div>
        <div class="field">
          <label for="bc-to">${t('utility.billCheck.to')}</label>
          <input class="input" id="bc-to" type="date" value="${escapeHtml(to)}">
        </div>
        <div class="field">
          <button type="button" class="btn btn--util" id="bc-run">${t('utility.billCheck.run')}</button>
        </div>
      </div>
      <div id="bc-result"></div>`;
}

function reasonLabel(reason) {
  const map = {
    start: 'start', end: 'end', reading: 'reading',
    reading_estimated: 'readingEstimated', factor: 'factor', price: 'price',
  };
  // Kombinationen wie „reading+factor": beide Gründe nennen
  return reason.split('+').map(r => t('utility.billCheck.reason.' + (map[r] || r))).join(' · ');
}

// v2.5.2 — Zählerstand an einer Abschnittsgrenze mit Ableseart, wie die
// Rechnung ihn ausweist: abgelesen (ohne Zusatz), als geschätzt erfasst (S)
// oder Ersatzwert (E) — kein Stand an diesem Tag, tagesgenau interpoliert.
// Die Fußnote unter der Tabelle erklärt die Kürzel.
function counterCell(value, kind) {
  if (kind == null) return `<td class="num muted counter-cell">–</td>`;
  const mark = kind === 'reading_estimated' ? t('utility.billCheck.mark.estimated')
             : kind === 'interpolated'      ? t('utility.billCheck.mark.interpolated') : '';
  const kindKey = kind === 'reading_estimated' ? 'readingEstimated' : kind;
  const title = escapeHtml(t('utility.billCheck.kind.' + kindKey));
  const val = value != null ? fmt.num(value, Number.isInteger(value) ? 0 : 1) : '–';
  return `<td class="num counter-cell" data-kind="${escapeHtml(kind)}" title="${title}">${val}${mark ? `<sup class="muted"> ${mark}</sup>` : ''}</td>`;
}

const eur = (v) => v == null ? '–' : fmt.eur(v);

export function renderBillCheck(bill, u = { key: 'gas' }) {
  const rows = bill.rows || [];
  if (!rows.length) return `<p class="muted">${t('utility.billCheck.empty')}</p>`;
  const tot = bill.totals || {};
  const gas = u.key === 'gas';
  const water = u.key === 'wasser';
  const qty = water ? 'm3' : 'kwh';
  const qtyUnit = water ? 'm³' : 'kWh';
  const costCols = `
        <th scope="col" class="num">${t('billCheck.row.price', { minor: getCurrencyMinor(), unit: qtyUnit })}</th>
        <th scope="col" class="num">${t('billCheck.row.energyCost')}</th>
        <th scope="col" class="num">${t('billCheck.row.fixed')}</th>`;
  const costCells = (r) => `
            <td class="num">${water ? '' : (r.ct_per_kwh != null ? fmt.num(r.ct_per_kwh, 2) : '–')}</td>
            <td class="num">${eur(r.energy_cost)}</td>
            <td class="num">${eur(r.fixed_cost)}</td>`;
  return `
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr>
        <th scope="col">${t('utility.billCheck.col.period')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.counterFrom')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.counterTo')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.days')}</th>
        <th scope="col">${t('utility.billCheck.col.reason')}</th>
        ${gas ? `
        <th scope="col" class="num">m³</th>
        <th scope="col" class="num">${t('utility.billCheck.col.z')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.hs')}</th>
        <th scope="col" class="num">kWh/m³</th>` : ''}
        <th scope="col" class="num">${qtyUnit}</th>
        ${costCols}
      </tr></thead>
      <tbody>
        ${rows.map(r => `
          <tr class="${r[gas ? 'm3' : qty] == null ? 'muted' : ''}">
            <td>${fmt.date(r.from)} – ${fmt.date(r.to_inclusive)}</td>
            ${counterCell(r.counter_from, r.counter_from_kind)}
            ${counterCell(r.counter_to, r.counter_to_kind)}
            <td class="num">${r.days}</td>
            <td>${reasonLabel(r.reason)}</td>
            ${gas ? `
            <td class="num">${r.m3 != null ? fmt.num(r.m3, 1) : `<em>${t('utility.billCheck.noReading')}</em>`}</td>
            <td class="num">${r.zustandszahl != null ? fmt.num(r.zustandszahl, 4) : '–'}</td>
            <td class="num">${r.brennwert != null ? fmt.num(r.brennwert, 3) : '–'}</td>
            <td class="num">${fmt.num(r.kwh_per_m3, 3)}</td>` : ''}
            <td class="num"><strong>${r[qty] != null ? fmt.num(r[qty], water ? 2 : 0) : (gas ? '–' : `<em>${t('utility.billCheck.noReading')}</em>`)}</strong></td>
            ${costCells(r)}
          </tr>`).join('')}
      </tbody>
      <tfoot><tr>
        <td><strong>${t('utility.billCheck.total')}</strong></td>
        <td></td><td></td>
        <td class="num">${tot.days ?? ''}</td>
        <td></td>
        ${gas ? `<td class="num"><strong>${fmt.num(tot.m3, 1)}</strong></td><td></td><td></td><td></td>` : ''}
        <td class="num"><strong>${fmt.num(tot[qty], water ? 2 : 0)}</strong></td>
        <td></td>
        <td class="num">${eur(tot.energy_cost)}</td>
        <td class="num">${eur(tot.fixed_cost)}</td>
      </tr></tfoot>
    </table></div>
    <p class="bill-check-total"><strong>${escapeHtml(tot.bonus ? t('billCheck.ownTotal', { total: eur(tot.total), rebate: eur(tot.bonus) }) : t('billCheck.ownTotalOnly', { total: eur(tot.total) }))}</strong></p>
    <p class="muted bill-check-legend" style="margin-top:8px">${t('utility.billCheck.legend', { est: t('utility.billCheck.mark.estimated'), int: t('utility.billCheck.mark.interpolated') })}</p>
    ${tot.gaps ? `<p class="muted" style="margin-top:8px">${tp('utility.billCheck.gapsN', tot.gaps)}</p>` : ''}
    ${tot.price_missing ? `<p class="muted">${escapeHtml(t('billCheck.reason.price_missing'))}</p>` : ''}
    ${gas ? `<p class="muted" style="margin-top:8px">${t('utility.billCheck.formula')}</p>` : ''}`;
}

/**
 * @param {HTMLElement} container
 * @param {{key: string}} u
 * @param {() => {id: string}} getMeter  der gerade gewählte Zähler
 */
export function wireBillCheck(container, u, getMeter) {
  const btn = container.querySelector('#bc-run');
  const out = container.querySelector('#bc-result');
  if (!btn || !out) return;
  let seq = 0;
  btn.addEventListener('click', async () => {
    const from = container.querySelector('#bc-from')?.value;
    const to   = container.querySelector('#bc-to')?.value;
    if (!from || !to || from >= to) { toastErr(t('utility.billCheck.errRange')); return; }
    const my = ++seq;   // nur die letzte Rechnung zeigt ihr Ergebnis
    out.innerHTML = `<div class="loading">${t('common.loading')}</div>`;
    try {
      const bill = await api.billCheck(u.key, getMeter().id, from, to);
      if (my === seq) out.innerHTML = renderBillCheck(bill, u);
    } catch (e) {
      if (my !== seq) return;
      out.innerHTML = '';
      toastErr(e.message);
    }
  });
}

// ── v3.1.0 (H5, UI-35) — „Laut Rechnung": Werte des Versorgers ───────

async function drawBills(container, u, meter) {
  const el = container.querySelector('[data-role="bills"]');
  if (!el) return;
  const water = u.key === 'wasser';
  const bills = await api.bills(u.key, meter.id).catch(() => []);
  const year = new Date().getFullYear() - 1;
  el.innerHTML = `
    <h2 class="card__title">${t('billCheck.invoice.title')}${info('utilityStatement')}</h2>
    <p class="muted">${t('billCheck.invoice.hint')}</p>
    <form id="bi-form" class="bill-form">
      <div class="form-row">
        <div class="field"><label for="bi-from">${t('utility.billCheck.from')}</label><input class="input" id="bi-from" name="period_from" type="date" value="${year}-01-01"></div>
        <div class="field"><label for="bi-to">${t('billCheck.invoice.toInclusive')}</label><input class="input" id="bi-to" name="period_to" type="date" value="${year}-12-31"></div>
        <div class="field"><label for="bi-issued">${t('billCheck.invoice.issued')}</label><input class="input" id="bi-issued" name="issued_on" type="date"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="bi-qty">${water ? t('billCheck.invoice.m3') : t('billCheck.invoice.kwh')}</label><input class="input" id="bi-qty" name="qty" type="text" inputmode="decimal"></div>
        <div class="field"><label for="bi-amount">${t('billCheck.invoice.amount')}</label><input class="input" id="bi-amount" name="amount" type="text" inputmode="decimal"></div>
        <div class="field"><label for="bi-adv">${t('billCheck.invoice.advances')}</label><input class="input" id="bi-adv" name="advances" type="text" inputmode="decimal"></div>
        <div class="field"><label for="bi-res">${t('billCheck.invoice.result')}</label><input class="input" id="bi-res" name="result" type="text" inputmode="decimal" placeholder="${escapeHtml(t('billCheck.invoice.resultHint'))}"></div>
      </div>
      <fieldset class="field"><legend>${t('billCheck.invoice.items')}</legend>
        <div data-items></div>
        <button type="button" class="btn btn--ghost btn--sm" data-add-item>${t('billCheck.invoice.addItem')}</button>
      </fieldset>
      ${u.key === 'gas' ? `
      <div class="form-row">
        <div class="field"><label for="bi-co2kg">${t('co2split.statement.emissions')}</label><input class="input" id="bi-co2kg" name="co2_kg" type="text" inputmode="decimal"></div>
        <div class="field"><label for="bi-co2eur">${t('billCheck.invoice.co2Cost')}</label><input class="input" id="bi-co2eur" name="co2_eur" type="text" inputmode="decimal"></div>
      </div>` : ''}
      <div class="field">
        <label class="btn btn--ghost btn--sm"><span aria-hidden="true">📄</span> ${t('billCheck.invoice.attach')}
          <input type="file" accept="application/pdf,image/*" data-role="bill-pdf" class="sr-only"></label>
        <span class="muted small" data-role="bill-pdf-n"></span>
      </div>
      <p class="muted small" data-role="bill-editing" hidden></p>
      <div class="form-actions">
        <button type="button" class="btn btn--primary" id="bi-save">${t('billCheck.invoice.save')}</button>
        <button type="button" class="btn btn--ghost" id="bi-cancel" hidden>${t('common.cancel')}</button>
      </div>
    </form>
    <div data-role="bill-list">${billList(bills, water)}</div>
    <div data-role="bill-compare"></div>`;

  let attachments = [];
  let editing = null;   // v3.2.0 — Rechnung, die gerade bearbeitet wird
  const itemRow = (it = {}) => `<div class="form-row bill-item">
      <div class="field"><label>${t('billCheck.invoice.itemLabel')}</label><input class="input input--text" data-i="label" type="text" maxlength="120" value="${escapeHtml(it.label ?? '')}"></div>
      <div class="field"><label>${t('billCheck.invoice.itemAmount')}</label><input class="input" data-i="amount_eur" type="text" inputmode="decimal" value="${escapeHtml(formatForInput(it.amount_eur))}"></div>
      <div class="field" style="display:flex;align-items:flex-end"><button type="button" class="btn btn--ghost btn--sm" data-del-item aria-label="${escapeHtml(t('tenancy.form.removeRow'))}">✕</button></div>
    </div>`;
  el.querySelector('[data-add-item]').addEventListener('click', () => el.querySelector('[data-items]').insertAdjacentHTML('beforeend', itemRow()));
  el.addEventListener('click', (e) => { const d = e.target.closest('[data-del-item]'); if (d) d.closest('.bill-item')?.remove(); });
  const pdf = el.querySelector('[data-role="bill-pdf"]');
  pdf.addEventListener('change', async () => {
    const file = pdf.files?.[0];
    if (!file) return;
    try {
      const a = await api.uploadAttachment(file, file.type === 'application/pdf' ? 'bill_pdf' : 'other', file.name);
      attachments.push(a.id);
      el.querySelector('[data-role="bill-pdf-n"]').textContent = tp('tenancy.statement.attached', attachments.length);
    } catch (err) { toastErr(err.message); }
    pdf.value = '';
  });
  el.querySelector('#bi-save').addEventListener('click', async () => {
    const f = el.querySelector('#bi-form');
    const n = (v) => { const s = String(v ?? '').trim(); if (s === '') return null; const x = parseDecimal(s); if (x == null) throw new Error(t('common.invalidNumber', { example: formatForInput(1234.5) })); return x; };
    let data;
    try {
      const invoice = { amount_eur: n(f.amount.value), advances_paid_eur: n(f.advances.value), result_eur: n(f.result.value) };
      invoice[water ? 'volume_m3' : 'energy_kwh'] = n(f.qty.value);
      Object.keys(invoice).forEach(k => invoice[k] == null && delete invoice[k]);
      data = {
        meter_id: meter.id, period_from: f.period_from.value, period_to: f.period_to.value, issued_on: f.issued_on.value || null,
        invoice,
        items: [...el.querySelectorAll('.bill-item')].map(r => ({ label: r.querySelector('[data-i="label"]').value, amount_eur: n(r.querySelector('[data-i="amount_eur"]').value) ?? 0 })),
        co2: f.co2_kg ? { emissions_kg: n(f.co2_kg.value), cost_eur: n(f.co2_eur.value) } : null,
        attachment_ids: attachments,
      };
    } catch (err) { toastErr(err.message); return; }
    try {
      const b = editing ? await api.updateBill(u.key, editing.id, data) : await api.createBill(u.key, data);
      toastOk(t(editing ? 'billCheck.updated' : 'billCheck.saved'));
      await drawBills(container, u, meter);
      showCompare(container, u, b.id);
    } catch (err) { toastErr(err.message); }
  });
  // v3.2.0 (Patch-Pool) — Rechnung bearbeiten: Formular mit ihren Werten füllen
  const startEdit = (b) => {
    const f = el.querySelector('#bi-form');
    const v = (x) => formatForInput(x);
    editing = b;
    f.period_from.value = b.period_from || '';
    f.period_to.value = b.period_to || '';
    f.issued_on.value = b.issued_on || '';
    f.qty.value = v(water ? b.invoice?.volume_m3 : b.invoice?.energy_kwh);
    f.amount.value = v(b.invoice?.amount_eur);
    f.advances.value = v(b.invoice?.advances_paid_eur);
    f.result.value = v(b.invoice?.result_eur);
    if (f.co2_kg) { f.co2_kg.value = v(b.co2?.emissions_kg); f.co2_eur.value = v(b.co2?.cost_eur); }
    el.querySelector('[data-items]').innerHTML = (b.items || []).map(itemRow).join('');
    attachments = [...(b.attachment_ids || [])];
    el.querySelector('[data-role="bill-pdf-n"]').textContent = attachments.length ? tp('tenancy.statement.attached', attachments.length) : '';
    const note = el.querySelector('[data-role="bill-editing"]');
    note.textContent = t('billCheck.editing', { from: fmt.date(b.period_from), to: fmt.date(b.period_to) })
      + (b.special_payment_id ? ' ' + t('billCheck.editBookedNote') : '');
    note.hidden = false;
    el.querySelector('#bi-save').textContent = t('billCheck.invoice.update');
    el.querySelector('#bi-cancel').hidden = false;
    f.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
  };
  el.querySelector('#bi-cancel').addEventListener('click', () => drawBills(container, u, meter));
  el.querySelector('[data-role="bill-list"]').addEventListener('click', async (e) => {
    const chk = e.target.closest('[data-bill-check]');
    const book = e.target.closest('[data-bill-book]');
    const del = e.target.closest('[data-bill-del]');
    const edit = e.target.closest('[data-bill-edit]');
    if (edit) { const b = bills.find(x => x.id === edit.dataset.billEdit); if (b) startEdit(b); }
    if (chk) showCompare(container, u, chk.dataset.billCheck);
    if (book) {
      try { await api.bookBill(u.key, book.dataset.billBook); toastOk(t('billCheck.booked')); drawBills(container, u, meter); }
      catch (err) { toastErr(err.message); }
    }
    if (del) {
      const ok = await confirmModal({ message: t('billCheck.confirmDelete'), confirmLabel: t('utility.readingsTable.delete'), danger: true });
      if (!ok) return;
      try { await api.deleteBill(u.key, del.dataset.billDel); drawBills(container, u, meter); }
      catch (err) { toastErr(err.message); }
    }
  });
}

function billList(bills, water) {
  if (!bills.length) return `<p class="muted">${t('billCheck.noBills')}</p>`;
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${t('utility.billCheck.col.period')}</th>
      <th scope="col" class="num">${water ? 'm³' : 'kWh'}</th>
      <th scope="col" class="num">${t('billCheck.invoice.amount')}</th>
      <th scope="col" class="num">${t('billCheck.invoice.result')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>${bills.map(b => `<tr>
      <td>${fmt.date(b.period_from)} – ${fmt.date(b.period_to)}</td>
      <td class="num">${fmt.num(water ? b.invoice?.volume_m3 : b.invoice?.energy_kwh, water ? 2 : 0)}</td>
      <td class="num">${eur(b.invoice?.amount_eur)}</td>
      <td class="num">${eur(b.invoice?.result_eur)}</td>
      <td style="text-align:right;white-space:nowrap">
        ${(b.attachment_ids || []).map(id => `<a class="icon-btn" href="${escapeHtml(api.attachmentUrl(id))}" target="_blank" rel="noopener" aria-label="${escapeHtml(t('billCheck.invoice.attach'))}"><span aria-hidden="true">📄</span></a>`).join('')}
        <button type="button" class="btn btn--ghost btn--sm" data-bill-check="${escapeHtml(b.id)}">${t('billCheck.compare')}</button>
        ${b.special_payment_id ? `<span class="tag">${t('billCheck.bookedTag')}</span>`
          : (b.invoice?.result_eur ? `<button type="button" class="btn btn--ghost btn--sm" data-bill-book="${escapeHtml(b.id)}">${t('billCheck.book')}</button>` : '')}
        <button type="button" class="icon-btn" data-bill-edit="${escapeHtml(b.id)}" aria-label="${escapeHtml(t('utility.readingsTable.edit'))}"><span aria-hidden="true">✏️</span></button>
        <button type="button" class="icon-btn" data-bill-del="${escapeHtml(b.id)}" aria-label="${escapeHtml(t('utility.readingsTable.delete'))}"><span aria-hidden="true">🗑️</span></button>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

async function showCompare(container, u, id) {
  const el = container.querySelector('[data-role="bill-compare"]');
  if (!el) return;
  el.innerHTML = `<div class="loading">${t('common.loading')}</div>`;
  try {
    const c = await api.billCompare(u.key, id);
    const water = u.key === 'wasser';
    const q = water ? 'm3' : 'kwh';
    const unit = water ? 'm³' : 'kWh';
    const badge = c.verdict === 'ok' ? 'success' : c.verdict === 'check' ? 'warning' : 'neutral';
    el.innerHTML = `
      <h3 class="settings-subhead">${escapeHtml(t('billCheck.delta.title', { from: fmt.date(c.period_from), to: fmt.date(c.period_to) }))}
        ${c.verdict ? `<span class="badge badge--${badge}">${escapeHtml(t('billCheck.delta.' + c.verdict))}</span>` : ''}</h3>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th scope="col"></th><th scope="col" class="num">${t('billCheck.delta.ours')}</th><th scope="col" class="num">${t('billCheck.delta.invoice')}</th><th scope="col" class="num">${t('billCheck.delta.diff')}</th></tr></thead>
        <tbody>
          <tr><th scope="row">${unit}</th><td class="num">${fmt.num(c.ours[q], water ? 2 : 0)}</td><td class="num">${fmt.num(water ? c.invoice.volume_m3 : c.invoice.energy_kwh, water ? 2 : 0)}</td>
            <td class="num">${c.delta[q] != null ? `${fmt.num(c.delta[q], 1)} (${fmt.num(c.delta[q + '_pct'], 1)} %)` : '–'}</td></tr>
          <tr><th scope="row">${t('billCheck.delta.amount')}</th><td class="num">${eur(c.ours.total)}</td><td class="num">${eur(c.invoice.amount_eur)}</td>
            <td class="num">${c.delta.eur != null ? `${eur(c.delta.eur)} (${fmt.num(c.delta.eur_pct, 1)} %)` : '–'}</td></tr>
          <tr><th scope="row">${t('billCheck.invoice.advances')}</th><td class="num">${eur(c.ours.advances)}</td><td class="num">${eur(c.invoice.advances_paid_eur)}</td><td></td></tr>
        </tbody>
      </table></div>
      ${(c.reasons || []).length ? `<ul class="muted small">${c.reasons.map(r => `<li>${escapeHtml(t('billCheck.reason.' + r))}</li>`).join('')}</ul>` : ''}`;
  } catch (e) { el.innerHTML = ''; toastErr(e.message); }
}
