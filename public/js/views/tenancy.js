// =====================================================================
// Energietracker v3.1.0 — Mietverhältnis (Paket H3, F1008/MKT-07)
//
// Für Mieter, die Heizung und Wasser über die Nebenkosten bezahlen:
//   1. Budget — Vorauszahlung gegen das, was der laufende Abrechnungszeitraum
//      voraussichtlich kostet (Hilfsrechnung, TenancyBudgetService)
//   2. Stammdaten — Vorauszahlungen, Preise, Umlagen, zugeordnete Zähler
//   3. Abrechnungen — was der Vermieter abgerechnet hat, mit PDF; daraus
//      Preise und die neue Vorauszahlung übernehmen
// Die monatliche Verbrauchsinfo wird bei der Verbrauchsart Heizwärme
// erfasst (Zähler mit „Verbrauch je Zeitraum").
// =====================================================================
import { api } from '../api.js';
import { activeUtilities } from '../state.js';
import { fmt, escapeHtml as esc, parseDecimal, formatForInput, todayIso } from '../lib/format.js';
import { t, tp, getCurrencyMinor } from '../lib/i18n.js';
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { toastOk, toastErr } from '../components/toast.js';
import { info } from '../components/info.js';

const CATEGORIES = ['heating', 'warm_water', 'cold_water', 'sewage', 'operating', 'other'];
let selectedId = null;

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('tenancy.loading')}</div>`;
  // nur aktive Arten: Heizwärme verlinken bzw. ihre Zähler anbieten, wenn sie eingeschaltet ist
  const [tenancies, utilities] = await Promise.all([api.tenancies(), activeUtilities().catch(() => [])]);
  const list = Array.isArray(tenancies) ? tenancies : [];
  if (!list.some(x => x.id === selectedId)) selectedId = list[0]?.id ?? null;
  const ten = list.find(x => x.id === selectedId) || null;

  const header = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${t('tenancy.title')}</h1>
        <p class="view-header__subtitle">${t('tenancy.subtitle')}</p>
      </div>
      <div class="view-header__actions">
        ${list.length > 1 ? `<select class="select" id="ten-select" aria-label="${esc(t('tenancy.select'))}">
          ${list.map(x => `<option value="${esc(x.id)}" ${x.id === selectedId ? 'selected' : ''}>${esc(x.label || fmt.date(x.start))}</option>`).join('')}
        </select>` : ''}
        <button class="btn btn--primary btn--sm" id="ten-new">${t('tenancy.new')}</button>
      </div>
    </div>`;

  if (!ten) {
    container.innerHTML = `${header}
      <div class="empty" style="padding:32px">
        <div class="empty-icon">🔑</div>
        <h2>${t('tenancy.empty.title')}</h2>
        <p class="muted">${t('tenancy.empty.text')}</p>
      </div>`;
    wire(container, null, utilities);
    return;
  }

  const [budget, statements] = await Promise.all([
    api.tenancyBudget(ten.id).catch(e => ({ error: e.message })),
    api.tenancyStatements(ten.id).catch(() => []),
  ]);

  const co2Year = new Date().getFullYear() - 1;
  const co2 = await api.co2Split(co2Year).catch(() => null);
  container.innerHTML = `${header}
    ${budgetCard(budget)}
    <div data-min-level="expert">${co2Card(co2, co2Year)}</div>
    ${detailsCard(ten, utilities)}
    ${statementsCard(statements)}`;
  wire(container, ten, utilities, statements);
}

// ── Karten ───────────────────────────────────────────────────────────

function budgetCard(b) {
  if (!b || b.error) {
    return `<div class="card"><h2 class="card__title">${t('tenancy.budget.title')}</h2>
      <div class="banner banner--error">${esc(b?.error || t('tenancy.budget.unavailable'))}</div></div>`;
  }
  const r = Number(b.projected_result_eur || 0);
  const verdict = r > 0.5 ? t('tenancy.budget.additional', { amount: fmt.eur(r) })
    : r < -0.5 ? t('tenancy.budget.credit', { amount: fmt.eur(-r) }) : t('tenancy.budget.even');
  const riskCls = { low: 'success', medium: 'warning', high: 'danger' }[b.risk] || 'neutral';
  return `
    <div class="card">
      <h2 class="card__title">${t('tenancy.budget.title')}${info('prepayment')}</h2>
      <p class="muted">${esc(t('tenancy.budget.period', { from: fmt.date(b.period_from), to: fmt.date(b.period_to) }))}</p>
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi__label">${t('tenancy.budget.expected')}</div><div class="kpi__value">${fmt.eur(b.expected_eur)}</div></div>
        <div class="kpi"><div class="kpi__label">${t('tenancy.budget.prepaid')}</div><div class="kpi__value">${fmt.eur(b.prepaid_eur)}</div></div>
        <div class="kpi"><div class="kpi__label">${t('tenancy.budget.result')}</div><div class="kpi__value">${esc(verdict)}</div>
          ${b.risk ? `<span class="badge badge--${riskCls}">${esc(t('tenancy.risk.' + b.risk))}</span>` : ''}</div>
        <div class="kpi"><div class="kpi__label">${t('tenancy.budget.suggested')}</div><div class="kpi__value">${fmt.eur(b.suggested_prepayment_eur)}</div></div>
      </div>
      <p class="muted small">${esc(t('tenancy.budget.disclaimer'))}</p>
      ${(b.assumptions || []).length ? `<ul class="muted small">${b.assumptions.map(a => `<li>${esc(a === 'months_estimated' ? tp('tenancy.assumption.monthsEstimated', b.months_estimated) : t('tenancy.assumption.' + a))}</li>`).join('')}</ul>` : ''}
      <details>
        <summary>${t('tenancy.budget.months')}</summary>
        <div class="table-wrap"><table class="table">
          <thead><tr>
            <th scope="col">${t('tenancy.budget.colMonth')}</th>
            <th scope="col" class="num">${t('tenancy.budget.colHeat')}</th>
            <th scope="col" class="num">${t('tenancy.budget.colWater')}</th>
            <th scope="col" class="num">${t('tenancy.budget.expected')}</th>
            <th scope="col" class="num">${t('tenancy.budget.prepaid')}</th>
            <th scope="col" class="num">${t('tenancy.budget.colBalance')}</th>
          </tr></thead>
          <tbody>${(b.months || []).map(m => `<tr${m.measured ? '' : ' class="muted"'}>
            <td>${fmt.month(m.ym)}${m.measured ? '' : ` <span class="tag">${t('tenancy.budget.estimated')}</span>`}</td>
            <td class="num">${fmt.num(m.heat_kwh, 0)} kWh</td>
            <td class="num">${fmt.num(Number(m.warm_water_m3) + Number(m.cold_water_m3), 1)} m³</td>
            <td class="num">${fmt.eur(m.expected_eur)}</td>
            <td class="num">${fmt.eur(m.prepaid_eur)}</td>
            <td class="num">${fmt.eur(m.balance_eur)}</td>
          </tr>`).join('')}</tbody>
        </table></div>
      </details>
    </div>`;
}

// v3.1.0 (H4, MKT-15) — CO₂-Kosten zwischen Mieter und Vermieter (CO2KostAufG)
function co2Card(r, year) {
  if (!r?.supported) return '';
  const head = `<h2 class="card__title">${esc(t('co2split.title', { year }))}${info('co2Split')}</h2>`;
  if (!r.case || r.stage == null) {
    const why = (r.checks || []).includes('area_missing') ? t('co2split.check.area_missing') : t('co2split.noData');
    return `<div class="card">${head}<p class="muted">${esc(why)}</p></div>`;
  }
  return `<div class="card">${head}
    <p class="muted">${esc(t('co2split.case.' + r.case))}</p>
    <div class="kpi-grid">
      <div class="kpi"><div class="kpi__label">${t('co2split.perM2')}</div><div class="kpi__value">${fmt.num(r.kg_per_m2, 1)} kg</div></div>
      <div class="kpi"><div class="kpi__label">${t('co2split.stage')}</div><div class="kpi__value">${r.stage} / 10</div></div>
      <div class="kpi"><div class="kpi__label">${t('co2split.landlordShare')}</div><div class="kpi__value">${r.landlord_share_pct} %</div></div>
      <div class="kpi"><div class="kpi__label">${t('co2split.amount')}</div><div class="kpi__value">${fmt.eur(r.landlord_amount_eur)}</div></div>
    </div>
    ${(r.checks || []).length ? `<div class="banner banner--warning">${r.checks.map(c => esc(t('co2split.check.' + c))).join('<br>')}</div>` : ''}
    ${(r.reductions || []).length ? `<p class="muted small">${r.reductions.map(x => esc(t('co2split.reduction.' + x))).join(' · ')}</p>` : ''}
    <p class="muted small">${esc(t('co2split.disclaimer'))}</p>
    <a class="btn btn--ghost btn--sm" href="${esc(api.co2SplitPdfUrl(year, { inline: true }))}" target="_blank" rel="noopener">${t('co2split.letter.open')}</a>
  </div>`;
}

function detailsCard(ten, utilities) {
  const today = todayIso();
  const pp = validAt(ten.prepayments, today);
  const price = (f) => [...(ten.prices || [])].filter(p => p.from <= today && p[f] != null).sort((a, b) => b.from.localeCompare(a.from))[0]?.[f];
  const meterCount = Object.values(ten.meter_ids || {}).reduce((s, l) => s + (l?.length || 0), 0);
  const heatUtil = utilities.find(u => u.key === 'waerme');
  return `
    <div class="card">
      <h2 class="card__title">${esc(ten.label || t('tenancy.details.title'))}
        <span class="card__title-action"><button class="btn btn--ghost btn--sm" id="ten-edit">${t('tenancy.details.edit')}</button></span>
      </h2>
      <dl class="diag-grid">
        <dt>${t('tenancy.field.start')}</dt><dd>${fmt.date(ten.start)}${ten.end ? ` – ${fmt.date(ten.end)}` : ''}</dd>
        ${ten.landlord ? `<dt>${t('tenancy.field.landlord')}</dt><dd>${esc(ten.landlord)}</dd>` : ''}
        <dt>${t('tenancy.field.anchor')}</dt><dd>${esc(ddmm(ten.billing_anchor))}</dd>
        <dt>${t('tenancy.field.prepayment')}</dt><dd>${pp ? esc(t('tenancy.details.prepaymentValue', {
          heating: fmt.eur(pp.heating_eur_month), operating: fmt.eur(pp.operating_eur_month) })) : '–'}</dd>
        <dt>${t('tenancy.field.heatPrice')}</dt><dd>${price('heat_eur_per_kwh') != null ? `${fmt.num(price('heat_eur_per_kwh') * 100, 2)} ${esc(getCurrencyMinor())}/kWh` : '–'}</dd>
        <dt>${t('tenancy.field.warmWaterPrice')}</dt><dd>${price('warm_water_eur_per_m3') != null ? `${fmt.eur(price('warm_water_eur_per_m3'))}/m³` : '–'}</dd>
        <dt>${t('tenancy.field.coldWaterPrice')}</dt><dd>${price('cold_water_eur_per_m3') != null ? `${fmt.eur(price('cold_water_eur_per_m3'))}/m³` : '–'}</dd>
        <dt>${t('tenancy.field.meters')}</dt><dd>${esc(tp('tenancy.details.meterCount', meterCount))}</dd>
      </dl>
      ${heatUtil ? `<p class="muted small">${t('tenancy.details.uviHint')} <a href="#/utility/waerme">${esc(heatUtil.label)}</a>${info('uvi')}</p>` : ''}
    </div>`;
}

function statementsCard(statements) {
  return `
    <div class="card">
      <h2 class="card__title">${t('tenancy.statements.title')}${info('utilityStatement')}
        <span class="card__title-action"><button class="btn btn--primary btn--sm" id="st-new">${t('tenancy.statements.add')}</button></span>
      </h2>
      ${statements.length ? `<div class="table-wrap"><table class="table">
        <thead><tr>
          <th scope="col">${t('tenancy.statements.colPeriod')}</th>
          <th scope="col">${t('tenancy.statements.colReceived')}</th>
          <th scope="col" class="num">${t('tenancy.statements.colTotal')}</th>
          <th scope="col" class="num">${t('tenancy.statements.colPrepaid')}</th>
          <th scope="col" class="num">${t('tenancy.statements.colResult')}</th>
          <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
        </tr></thead>
        <tbody>${statements.map(s => `<tr>
          <td>${fmt.date(s.period_from)} – ${fmt.date(s.period_to)}</td>
          <td>${s.received_on ? fmt.date(s.received_on) : '–'}</td>
          <td class="num">${fmt.eur(s.total_cost_eur)}</td>
          <td class="num">${fmt.eur(s.prepaid_eur)}</td>
          <td class="num">${esc(resultText(s.result_eur))}</td>
          <td style="text-align:right;white-space:nowrap">
            ${(s.attachment_ids || []).map(id => `<a class="icon-btn" href="${esc(api.attachmentUrl(id))}" target="_blank" rel="noopener" title="${esc(t('tenancy.statements.pdf'))}" aria-label="${esc(t('tenancy.statements.pdf'))}"><span aria-hidden="true">📄</span></a>`).join('')}
            <button class="icon-btn" data-st-edit="${esc(s.id)}" title="${esc(t('utility.readingsTable.edit'))}" aria-label="${esc(t('utility.readingsTable.edit'))}"><span aria-hidden="true">✏️</span></button>
            <button class="icon-btn" data-st-del="${esc(s.id)}" title="${esc(t('utility.readingsTable.delete'))}" aria-label="${esc(t('utility.readingsTable.delete'))}"><span aria-hidden="true">🗑️</span></button>
          </td>
        </tr>`).join('')}</tbody>
      </table></div>` : `<p class="muted">${t('tenancy.statements.empty')}</p>`}
    </div>`;
}

// ── Verdrahtung ──────────────────────────────────────────────────────

function wire(container, ten, utilities, statements = []) {
  const redraw = () => render(container);
  container.querySelector('#ten-select')?.addEventListener('change', (e) => { selectedId = e.target.value; redraw(); });
  container.querySelector('#ten-new')?.addEventListener('click', () => openTenancyModal(null, utilities, redraw));
  container.querySelector('#ten-edit')?.addEventListener('click', () => openTenancyModal(ten, utilities, redraw));
  container.querySelector('#st-new')?.addEventListener('click', () => openStatementModal(ten, null, redraw));
  container.querySelectorAll('[data-st-edit]').forEach(b => b.addEventListener('click', () =>
    openStatementModal(ten, statements.find(s => s.id === b.dataset.stEdit), redraw)));
  container.querySelectorAll('[data-st-del]').forEach(b => b.addEventListener('click', async () => {
    const ok = await confirmModal({ message: t('tenancy.statements.confirmDelete'), confirmLabel: t('utility.readingsTable.delete'), danger: true });
    if (!ok) return;
    try { await api.deleteStatement(ten.id, b.dataset.stDel); toastOk(t('tenancy.statements.deleted')); redraw(); }
    catch (e) { toastErr(e.message); }
  }));
}

// ── Mietverhältnis anlegen/bearbeiten ───────────────────────────────

async function openTenancyModal(ten, utilities, done) {
  const [heatMeters, waterMeters] = await Promise.all([
    utilities.some(u => u.key === 'waerme') ? api.meters('waerme').catch(() => []) : [],
    api.meters('wasser').catch(() => []),
  ]);
  const ids = ten?.meter_ids || { heat: [], warm_water: [], cold_water: [] };
  const meterBoxes = (role, meters) => meters.length
    ? meters.map(m => `<label class="settings-field__check"><input type="checkbox" data-meter-role="${role}" value="${esc(m.id)}" ${(ids[role] || []).includes(m.id) ? 'checked' : ''}> ${esc(m.name)}</label>`).join('')
    : `<span class="muted small">${t('tenancy.form.noMeters')}</span>`;
  const rowsHtml = (kind, list) => (list || []).map(e => datedRow(kind, e)).join('');
  const body = `
    <form id="ten-form">
      <div class="form-row">
        <div class="field"><label for="tf-label">${t('tenancy.field.label')}</label><input class="input input--text" id="tf-label" name="label" maxlength="120" value="${esc(ten?.label || '')}"></div>
        <div class="field"><label for="tf-landlord">${t('tenancy.field.landlord')}</label><input class="input input--text" id="tf-landlord" name="landlord" maxlength="120" value="${esc(ten?.landlord || '')}"></div>
        <div class="field"><label for="tf-area">${t('tenancy.field.area')}</label><input class="input" id="tf-area" name="wohnflaeche_m2" type="text" inputmode="decimal" placeholder="${esc(t('tenancy.field.areaPlaceholder'))}" value="${esc(formatForInput(ten?.wohnflaeche_m2))}"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="tf-start">${t('tenancy.field.start')}</label><input class="input" id="tf-start" name="start" type="date" required value="${esc(ten?.start || todayIso())}"></div>
        <div class="field"><label for="tf-end">${t('tenancy.field.end')}</label><input class="input" id="tf-end" name="end" type="date" value="${esc(ten?.end || '')}"></div>
        <div class="field"><label for="tf-anchor">${t('tenancy.field.anchor')}</label><input class="input" id="tf-anchor" name="billing_anchor" type="text" placeholder="${esc(t('settings.placeholder.dayMonth'))}" value="${esc(ddmm(ten?.billing_anchor || '01-01'))}"></div>
      </div>
      <fieldset class="field"><legend>${t('tenancy.field.prepayments')}${info('prepayment')}</legend>
        <div data-rows="prepayment">${rowsHtml('prepayment', ten?.prepayments)}</div>
        <button type="button" class="btn btn--ghost btn--sm" data-add-row="prepayment">${t('tenancy.form.addRow')}</button>
      </fieldset>
      <fieldset class="field"><legend>${t('tenancy.field.prices')}</legend>
        <p class="muted small">${t('tenancy.form.pricesHint')}</p>
        <div data-rows="price">${rowsHtml('price', ten?.prices)}</div>
        <button type="button" class="btn btn--ghost btn--sm" data-add-row="price">${t('tenancy.form.addRow')}</button>
      </fieldset>
      <fieldset class="field"><legend>${t('tenancy.field.fixedCosts')}</legend>
        <p class="muted small">${t('tenancy.form.fixedHint')}</p>
        <div data-rows="fixed">${rowsHtml('fixed', ten?.fixed_costs)}</div>
        <button type="button" class="btn btn--ghost btn--sm" data-add-row="fixed">${t('tenancy.form.addRow')}</button>
      </fieldset>
      <fieldset class="field"><legend>${t('tenancy.field.meters')}</legend>
        <div class="field"><strong>${t('tenancy.meters.heat')}</strong> ${meterBoxes('heat', heatMeters)}</div>
        <div class="field"><strong>${t('tenancy.meters.warm_water')}</strong> ${meterBoxes('warm_water', waterMeters)}</div>
        <div class="field"><strong>${t('tenancy.meters.cold_water')}</strong> ${meterBoxes('cold_water', waterMeters)}</div>
      </fieldset>
      <fieldset class="field"><legend>${t('co2split.form.title')}</legend>
        <label class="settings-field__check"><input type="checkbox" name="co2_own_appliances" ${ten?.co2_own_appliances ? 'checked' : ''}> ${t('co2split.form.ownAppliances')}</label>
        <div class="field"><label for="tf-co2r">${t('co2split.form.restriction')}</label>
          <select class="input" id="tf-co2r" name="co2_restriction">
            ${['none', 'one', 'both'].map(v => `<option value="${v}" ${(ten?.co2_restriction || 'none') === v ? 'selected' : ''}>${esc(t('co2split.form.restrictions.' + v))}</option>`).join('')}
          </select></div>
      </fieldset>
      <div class="field"><label for="tf-notes">${t('tenancy.field.notes')}</label><textarea class="input input--text" id="tf-notes" name="notes">${esc(ten?.notes || '')}</textarea></div>
    </form>`;
  openModal({
    title: ten ? t('tenancy.form.titleEdit') : t('tenancy.form.titleNew'),
    size: 'lg',
    body,
    footer: `${ten ? `<button type="button" class="btn btn--danger btn--quiet" data-act="delete">${t('tenancy.form.delete')}</button>` : ''}
      <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button type="button" class="btn btn--primary" data-act="save">${t('common.save')}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(false));
      modalEl.addEventListener('click', (e) => {
        const add = e.target.closest('[data-add-row]');
        if (add) modalEl.querySelector(`[data-rows="${add.dataset.addRow}"]`).insertAdjacentHTML('beforeend', datedRow(add.dataset.addRow, { from: todayIso() }));
        const del = e.target.closest('[data-del-row]');
        if (del) del.closest('.dated-row')?.remove();
      });
      modalEl.querySelector('[data-act="delete"]')?.addEventListener('click', async () => {
        const ok = await confirmModal({ message: t('tenancy.form.confirmDelete'), confirmLabel: t('tenancy.form.delete'), danger: true });
        if (!ok) return;
        try { await api.deleteTenancy(ten.id); selectedId = null; close(true); toastOk(t('tenancy.form.deleted')); done(); }
        catch (err) { toastErr(err.message); }
      });
      const saveBtn = modalEl.querySelector('[data-act="save"]');
      saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
        const f = modalEl.querySelector('#ten-form');
        const anchor = mmdd(f.billing_anchor.value);
        if (!anchor) { toastErr(t('settings.dayMonthInvalid')); return; }
        let data;
        try {
          data = {
            label: f.label.value, landlord: f.landlord.value, start: f.start.value, end: f.end.value || null,
            billing_anchor: anchor, notes: f.notes.value,
            wohnflaeche_m2: areaValue(f.wohnflaeche_m2.value),
            co2_own_appliances: f.co2_own_appliances.checked, co2_restriction: f.co2_restriction.value,
            prepayments: collectRows(modalEl, 'prepayment'), prices: collectRows(modalEl, 'price'), fixed_costs: collectRows(modalEl, 'fixed'),
            meter_ids: Object.fromEntries(['heat', 'warm_water', 'cold_water'].map(r =>
              [r, [...modalEl.querySelectorAll(`[data-meter-role="${r}"]:checked`)].map(b => b.value)])),
          };
        } catch (err) { toastErr(err.message); return; }
        try {
          const saved = ten ? await api.updateTenancy(ten.id, data) : await api.createTenancy(data);
          selectedId = saved?.id ?? selectedId;
          toastOk(t('tenancy.form.saved'));
          close(true);
          done();
        } catch (err) { toastErr(err.message); }
      }));
    },
  });
}

/** Eine Zeile einer datierten Liste (Vorauszahlung, Preise, Umlagen). */
function datedRow(kind, e = {}) {
  const num = (name, label, v) => `<div class="field"><label>${label}</label><input class="input" data-f="${name}" type="text" inputmode="decimal" autocomplete="off" value="${esc(formatForInput(v))}"></div>`;
  const cells = {
    prepayment: num('heating_eur_month', t('tenancy.form.heatingMonth'), e.heating_eur_month)
      + num('operating_eur_month', t('tenancy.form.operatingMonth'), e.operating_eur_month),
    price: num('heat_eur_per_kwh', t('tenancy.form.heatPrice'), e.heat_eur_per_kwh)
      + num('warm_water_eur_per_m3', t('tenancy.form.warmWaterPrice'), e.warm_water_eur_per_m3)
      + num('cold_water_eur_per_m3', t('tenancy.form.coldWaterPrice'), e.cold_water_eur_per_m3)
      + `<input type="hidden" data-f="source" value="${esc(e.source || 'estimate')}"><input type="hidden" data-f="statement_id" value="${esc(e.statement_id || '')}">`,
    fixed: `<div class="field"><label>${t('tenancy.form.fixedLabel')}</label><input class="input input--text" data-f="label" type="text" maxlength="120" value="${esc(e.label || '')}"></div>`
      + num('eur_per_year', t('tenancy.form.perYear'), e.eur_per_year),
  }[kind];
  return `<div class="form-row dated-row" data-kind="${kind}">
    <div class="field"><label>${t('tenancy.form.from')}</label><input class="input" data-f="from" type="date" value="${esc(e.from || '')}"></div>
    ${cells}
    <div class="field" style="display:flex;align-items:flex-end"><button type="button" class="btn btn--ghost btn--sm" data-del-row aria-label="${esc(t('tenancy.form.removeRow'))}">✕</button></div>
  </div>`;
}

// leer = Wohnfläche aus den Einstellungen (CO₂-Aufteilung, Vergleichswerte)
function areaValue(v) {
  const s = String(v ?? '').trim();
  if (s === '') return null;
  const n = parseDecimal(s);
  if (n == null) throw new Error(t('common.invalidNumber', { example: formatForInput(72.5) }));
  return n;
}

function collectRows(root, kind) {
  return [...root.querySelectorAll(`[data-rows="${kind}"] .dated-row`)].map(row => {
    const out = {};
    row.querySelectorAll('[data-f]').forEach(el => {
      const k = el.dataset.f;
      const v = el.value.trim();
      if (k === 'from' || k === 'label' || k === 'source' || k === 'statement_id') { if (v !== '') out[k] = v; return; }
      if (v === '') return;
      const n = parseDecimal(v);
      if (n == null || n < 0) throw new Error(t('common.invalidNumber', { example: formatForInput(12.5) }));
      out[k] = n;
    });
    return out;
  }).filter(r => r.from);
}

// ── Abrechnung erfassen ──────────────────────────────────────────────

function openStatementModal(ten, st, done) {
  const posRow = (p = {}) => `<div class="form-row dated-row pos-row">
    <div class="field"><label>${t('tenancy.statement.posLabel')}</label><input class="input input--text" data-p="label" type="text" maxlength="120" value="${esc(p.label || '')}"></div>
    <div class="field"><label>${t('tenancy.statement.posCategory')}</label><select class="input" data-p="category">
      ${CATEGORIES.map(c => `<option value="${c}" ${c === (p.category || 'other') ? 'selected' : ''}>${esc(t('tenancy.category.' + c))}</option>`).join('')}
    </select></div>
    <div class="field"><label>${t('tenancy.statement.posAmount')}</label><input class="input" data-p="amount_eur" type="text" inputmode="decimal" value="${esc(formatForInput(p.amount_eur))}"></div>
    <div class="field"><label>${t('tenancy.statement.posConsumption')}</label><input class="input" data-p="consumption" type="text" inputmode="decimal" value="${esc(formatForInput(p.consumption))}"></div>
    <div class="field" style="display:flex;align-items:flex-end"><button type="button" class="btn btn--ghost btn--sm" data-del-row aria-label="${esc(t('tenancy.form.removeRow'))}">✕</button></div>
  </div>`;
  let attachments = [...(st?.attachment_ids || [])];
  const body = `
    <form id="st-form">
      <div class="form-row">
        <div class="field"><label for="sf-from">${t('tenancy.statement.from')}</label><input class="input" id="sf-from" name="period_from" type="date" required value="${esc(st?.period_from || '')}"></div>
        <div class="field"><label for="sf-to">${t('tenancy.statement.to')}</label><input class="input" id="sf-to" name="period_to" type="date" required value="${esc(st?.period_to || '')}"></div>
        <div class="field"><label for="sf-received">${t('tenancy.statement.received')}</label><input class="input" id="sf-received" name="received_on" type="date" value="${esc(st?.received_on || '')}"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="sf-total">${t('tenancy.statement.total')}</label><input class="input" id="sf-total" name="total_cost_eur" type="text" inputmode="decimal" required value="${esc(formatForInput(st?.total_cost_eur))}"></div>
        <div class="field"><label for="sf-prepaid">${t('tenancy.statement.prepaid')}</label><input class="input" id="sf-prepaid" name="prepaid_eur" type="text" inputmode="decimal" required value="${esc(formatForInput(st?.prepaid_eur))}"></div>
      </div>
      <div class="form-row">
        <div class="field"><label for="sf-heat">${t('tenancy.statement.heatKwh')}</label><input class="input" id="sf-heat" name="heat_kwh" type="text" inputmode="decimal" value="${esc(formatForInput(st?.heat?.consumption))}"></div>
        <div class="field"><label for="sf-heatcost">${t('tenancy.statement.heatCost')}</label><input class="input" id="sf-heatcost" name="heat_cost" type="text" inputmode="decimal" value="${esc(formatForInput(st?.heat?.cost_eur))}"></div>
      </div>
      <fieldset class="field"><legend>${t('tenancy.statement.positions')}</legend>
        <p class="muted small">${t('tenancy.statement.positionsHint')}</p>
        <div data-pos>${(st?.positions || []).map(posRow).join('')}</div>
        <button type="button" class="btn btn--ghost btn--sm" data-add-pos>${t('tenancy.form.addRow')}</button>
      </fieldset>
      <fieldset class="field"><legend>${t('co2split.statement.title')}</legend>
        <div class="form-row">
          <div class="field"><label for="sf-co2-kg">${t('co2split.statement.emissions')}</label><input class="input" id="sf-co2-kg" name="co2_emissions" type="text" inputmode="decimal" value="${esc(formatForInput(st?.co2?.emissions_kg))}"></div>
          <div class="field"><label for="sf-co2-eur">${t('co2split.statement.cost')}</label><input class="input" id="sf-co2-eur" name="co2_cost" type="text" inputmode="decimal" value="${esc(formatForInput(st?.co2?.cost_eur))}"></div>
        </div>
        <div class="form-row">
          <div class="field"><label for="sf-co2-stage">${t('co2split.statement.stage')}</label><input class="input" id="sf-co2-stage" name="co2_stage" type="text" inputmode="numeric" value="${esc(st?.co2?.stage ?? '')}"></div>
          <div class="field"><label for="sf-co2-share">${t('co2split.statement.share')}</label><input class="input" id="sf-co2-share" name="co2_share" type="text" inputmode="numeric" value="${esc(st?.co2?.landlord_share_pct ?? '')}"></div>
          <div class="field"><label for="sf-co2-amount">${t('co2split.statement.amount')}</label><input class="input" id="sf-co2-amount" name="co2_amount" type="text" inputmode="decimal" value="${esc(formatForInput(st?.co2?.landlord_amount_eur))}"></div>
        </div>
      </fieldset>
      <fieldset class="field"><legend>${t('tenancy.statement.newPrepayment')}</legend>
        <div class="form-row">
          <div class="field"><label for="sf-np-from">${t('tenancy.form.from')}</label><input class="input" id="sf-np-from" name="np_from" type="date" value="${esc(st?.new_prepayment?.from || '')}"></div>
          <div class="field"><label for="sf-np-h">${t('tenancy.form.heatingMonth')}</label><input class="input" id="sf-np-h" name="np_heating" type="text" inputmode="decimal" value="${esc(formatForInput(st?.new_prepayment?.heating_eur_month))}"></div>
          <div class="field"><label for="sf-np-o">${t('tenancy.form.operatingMonth')}</label><input class="input" id="sf-np-o" name="np_operating" type="text" inputmode="decimal" value="${esc(formatForInput(st?.new_prepayment?.operating_eur_month))}"></div>
        </div>
      </fieldset>
      <div class="field">
        <label class="btn btn--ghost btn--sm"><span aria-hidden="true">📄</span> ${t('tenancy.statement.attachPdf')}
          <input type="file" accept="application/pdf,image/*" data-role="pdf" class="sr-only"></label>
        <span class="muted small" data-role="pdf-list">${esc(tp('tenancy.statement.attached', attachments.length))}</span>
      </div>
      <div class="field"><label><input type="checkbox" name="apply_prices" checked> ${t('tenancy.statement.applyPrices')}</label></div>
      <div class="field"><label><input type="checkbox" name="apply_prepayment" checked> ${t('tenancy.statement.applyPrepayment')}</label></div>
      <div class="field"><label for="sf-note">${t('tenancy.field.notes')}</label><input class="input input--text" id="sf-note" name="note" maxlength="2000" value="${esc(st?.note || '')}"></div>
    </form>`;
  openModal({
    title: st ? t('tenancy.statement.titleEdit') : t('tenancy.statement.titleNew'),
    size: 'lg',
    body,
    footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button type="button" class="btn btn--primary" data-act="save">${t('common.save')}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(false));
      modalEl.querySelector('[data-add-pos]').addEventListener('click', () => modalEl.querySelector('[data-pos]').insertAdjacentHTML('beforeend', posRow()));
      modalEl.addEventListener('click', (e) => { const d = e.target.closest('[data-del-row]'); if (d) d.closest('.pos-row')?.remove(); });
      const pdf = modalEl.querySelector('[data-role="pdf"]');
      pdf.addEventListener('change', async () => {
        const file = pdf.files?.[0];
        if (!file) return;
        try {
          const a = await api.uploadAttachment(file, file.type === 'application/pdf' ? 'statement_pdf' : 'other', file.name);
          attachments.push(a.id);
          modalEl.querySelector('[data-role="pdf-list"]').textContent = tp('tenancy.statement.attached', attachments.length);
        } catch (err) { toastErr(err.message); }
        pdf.value = '';
      });
      const saveBtn = modalEl.querySelector('[data-act="save"]');
      saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
        const f = modalEl.querySelector('#st-form');
        const n = (v, allowEmpty = true) => { const s = String(v ?? '').trim(); if (s === '' && allowEmpty) return null; const x = parseDecimal(s); if (x == null) throw new Error(t('common.invalidNumber', { example: formatForInput(1234.5) })); return x; };
        let data;
        try {
          const positions = [...modalEl.querySelectorAll('.pos-row')].map(r => {
            const p = { label: r.querySelector('[data-p="label"]').value, category: r.querySelector('[data-p="category"]').value,
              amount_eur: n(r.querySelector('[data-p="amount_eur"]').value, false) };
            const c = n(r.querySelector('[data-p="consumption"]').value);
            if (c != null) p.consumption = c;
            return p;
          });
          const heatKwh = n(f.heat_kwh.value);
          data = {
            period_from: f.period_from.value, period_to: f.period_to.value, received_on: f.received_on.value || null,
            total_cost_eur: n(f.total_cost_eur.value, false), prepaid_eur: n(f.prepaid_eur.value, false),
            positions, heat: heatKwh != null ? { consumption: heatKwh, unit: 'kWh', cost_eur: n(f.heat_cost.value) } : null,
            new_prepayment: f.np_from.value ? { from: f.np_from.value, heating_eur_month: n(f.np_heating.value) ?? 0, operating_eur_month: n(f.np_operating.value) ?? 0 } : null,
            co2: { emissions_kg: n(f.co2_emissions.value), cost_eur: n(f.co2_cost.value), stage: n(f.co2_stage.value),
              landlord_share_pct: n(f.co2_share.value), landlord_amount_eur: n(f.co2_amount.value) },
            attachment_ids: attachments, note: f.note.value,
            apply_prices: f.apply_prices.checked, apply_prepayment: f.apply_prepayment.checked,
          };
        } catch (err) { toastErr(err.message); return; }
        try {
          if (st) await api.updateStatement(ten.id, st.id, data);
          else await api.createStatement(ten.id, data);
          toastOk(t('tenancy.statement.saved'));
          close(true);
          done();
        } catch (err) { toastErr(err.message); }
      }));
    },
  });
}

// ── Hilfen ───────────────────────────────────────────────────────────

function validAt(list, date) {
  return [...(list || [])].filter(e => e.from <= date).sort((a, b) => b.from.localeCompare(a.from))[0] || null;
}

function resultText(v) {
  const r = Number(v || 0);
  return r > 0.005 ? t('tenancy.statements.additional', { amount: fmt.eur(r) })
    : r < -0.005 ? t('tenancy.statements.credit', { amount: fmt.eur(-r) }) : fmt.eur(0);
}

/** MM-TT → TT.MM (Anzeige) und zurück */
function ddmm(mmddStr) {
  const m = /^(\d{2})-(\d{2})$/.exec(String(mmddStr || ''));
  return m ? `${m[2]}.${m[1]}.` : '';
}
function mmdd(s) {
  const m = /^\s*(\d{1,2})[.\-/](\d{1,2})\.?\s*$/.exec(String(s || ''));
  if (!m) return null;
  const d = +m[1], mo = +m[2];
  if (mo < 1 || mo > 12 || d < 1 || d > new Date(2024, mo, 0).getDate()) return null;
  return `${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

export function cleanup() { /* keine globalen Listener */ }
