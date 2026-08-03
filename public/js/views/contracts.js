// =====================================================================
// Contracts view — F4 implementation:
//   - per-entry-row button "Vertragsbeginn übernehmen" copies start date
//     into the entry's date field
//   - inline validation: each row must have BOTH date+amount or BOTH empty
//   - half-filled rows are highlighted before the user can save
// =====================================================================

import { api } from '../api.js';
import { getUtility } from '../state.js';
import { fmt, escapeHtml, todayIso } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal } from '../components/modal.js';
import { t } from '../lib/i18n.js';
import { associateFieldLabels } from '../lib/a11y.js';

// Titel/Labels werden zur Render-Zeit über t() aufgelöst (nicht beim Modul-
// Laden, da der Sprachkatalog dann ggf. noch nicht steht).
// v2.2.0 — die Betragslabels sind Übersetzungs-Keys statt fester Strings:
// „€/Monat" stand vorher auch in der englischen Oberfläche.
const GROUPS = [
  { key: 'working_prices',   titleKey: 'contracts.group.working', dateKey: 'from', amountKey: 'ct_per_kwh',    amountKey_: 'contracts.unit.ctPerKwh',  step: '0.001' },
  { key: 'base_prices',      titleKey: 'contracts.group.base',    dateKey: 'from', amountKey: 'eur_per_month', amountKey_: 'contracts.unit.eurPerMonth', step: '0.01' },
  { key: 'advance_payments', titleKey: 'contracts.group.advance', dateKey: 'from', amountKey: 'amount_eur',    amountKey_: 'contracts.unit.eurPerMonth', step: '0.01' },
];

// F1003 — Sonderzahlungs-Arten. Die *_mit-Arten verändern zusätzlich den
// künftigen Abschlag. Labels über t('contracts.kinds.<value>').
const SPECIAL_PAYMENT_KINDS = [
  { value: 'rueckzahlung_mit',  affectsAdvance: true  },
  { value: 'rueckzahlung_ohne', affectsAdvance: false },
  { value: 'nachzahlung_mit',   affectsAdvance: true  },
  { value: 'nachzahlung_ohne',  affectsAdvance: false },
  { value: 'abschlagszahlung',  affectsAdvance: false },
];
const SPECIAL_KIND_AFFECTS_ADVANCE = new Set(
  SPECIAL_PAYMENT_KINDS.filter(k => k.affectsAdvance).map(k => k.value)
);

// F1003-Scope (Spiegel von Utilities::hasAdvancePaymentContracts):
// Standard-Abschlagsvertrag = kumulativ und nicht Wasser → Gas, Strom,
// Fernwärme. Heizöl/Pellets (delivery) und Wasser bleiben außen vor.
function hasAdvancePaymentContracts(u) {
  return (u?.reading_kind || 'cumulative') === 'cumulative' && u?.key !== 'wasser';
}

export async function render(container, params) {
  const utilityKey = params[0];
  const u = await getUtility(utilityKey);
  if (!u) {
    container.innerHTML = `<div class="banner banner--error">${t('contracts.unknown', { key: escapeHtml(utilityKey) })}</div>`;
    return;
  }
  container.setAttribute('data-utility', u.key);

  // Heizöl/Pellets haben per Logik keine Verträge — die Tankrechnung
  // (Menge × Preis bzw. Gesamtbetrag der Lieferung) ist die Kostenbasis.
  // Ein Vertragsformular (Arbeits-/Grundpreis, Abschläge, Boni) ergibt
  // hier keinen Sinn; stattdessen zu den Lieferungen leiten.
  if (u.reading_kind === 'delivery') {
    container.innerHTML = `
      <div data-utility="${u.key}">
        <div class="section-head">
          <h1>${u.icon} ${t('contracts.title', { label: escapeHtml(u.label) })}</h1>
          <div class="section-actions">
            <a class="btn btn--ghost" href="#/utility/${u.key}">${t('contracts.toOverview')}</a>
          </div>
        </div>
        <div class="banner banner--info">
          <strong>${t('contracts.delivery.bannerTitle', { label: escapeHtml(u.label) })}</strong>
          ${t('contracts.delivery.bannerText', { unit: escapeHtml(u.volume_unit || ''), label: escapeHtml(u.label) })}
          <div style="margin-top: var(--sp-3)">
            <a class="btn btn--util" href="#/utility/${u.key}">${t('contracts.delivery.toDeliveries')}</a>
          </div>
        </div>
      </div>
    `;
    return;
  }

  await refresh(container, u);
}

async function refresh(container, u) {
  container.innerHTML = `<div class="loading">${t('contracts.loading')}</div>`;
  const [meters, contracts] = await Promise.all([
    api.meters(u.key),
    api.contracts(u.key),
  ]);

  // C — neueste Verträge zuerst (nach Startdatum absteigend).
  const sorted = [...contracts].sort((a, b) => String(b.start || '').localeCompare(String(a.start || '')));

  container.innerHTML = `
    <div data-utility="${u.key}">
      <div class="section-head">
        <h1>${u.icon} ${t('contracts.title', { label: escapeHtml(u.label) })}</h1>
        <div class="section-actions">
          <a class="btn btn--ghost" href="#/utility/${u.key}">${t('contracts.toOverview')}</a>
          <button type="button" class="btn btn--util" data-action="new-contract" ${meters.length ? '' : 'disabled'}>${t('contracts.newContract')}</button>
        </div>
      </div>

      ${meters.length === 0 ? `
        <div class="banner banner--warning">${t('contracts.metersWarning')} <a href="#/utility/${u.key}/meters">${t('contracts.manageMeters')}</a></div>
      ` : ''}

      <div id="contracts-list">
        ${sorted.length === 0 ? `<p class="muted">${t('contracts.empty')}</p>${meters.length ? `<button type="button" class="btn btn--util" data-action="new-contract">${t('contracts.emptyCta')}</button>` : ''}` : ''}
        ${sorted.map(c => renderContractCard(c, meters, u)).join('')}
      </div>
    </div>
  `;

  container.querySelectorAll('[data-action="new-contract"]').forEach(btn => {
    btn.addEventListener('click', () => {
      dispatchContractModal(u, meters, null).then(changed => { if (changed) refresh(container, u); });
    });
  });

  container.querySelectorAll('[data-edit-contract]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit-contract');
      const c = contracts.find(x => x.id === id);
      const changed = await dispatchContractModal(u, meters, c);
      if (changed) refresh(container, u);
    });
  });

  container.querySelectorAll('[data-delete-contract]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-delete-contract');
      const ok = await confirmModal({ title: t('contracts.delete.title'), message: t('contracts.delete.message'), confirmLabel: t('contracts.delete.confirm'), danger: true });
      if (!ok) return;
      try { await api.deleteContract(u.key, id); toastOk(t('contracts.delete.deleted')); refresh(container, u); }
      catch (e) { toastErr(e.message); }
    });
  });
}

function dispatchContractModal(u, meters, existing) {
  return u.key === 'wasser'
    ? openWaterContractModal(u, meters, existing)
    : openContractModal(u, meters, existing);
}

// A — Vertragsstatus aus Start/Ende ableiten (heute liegt im Intervall?).
// v2.2.0 — Schattenverträge zuerst: sie sind reine Hypothesen für den
// Tarifvergleich und werden von Saldo, Vertragsstatus und Prognose
// herausgefiltert. Als „aktiv" ausgezeichnet suggerierten sie einen laufenden
// Vertrag, der nirgends in den Kosten auftaucht.
function contractStatus(c) {
  if (c.is_shadow) return { cls: 'shadow', label: t('tariff.shadowBadge') };
  const today = todayIso();
  const start = c.start || '';
  const end = c.end || '';
  if (start && start > today) return { cls: 'future', label: t('contracts.status.future') };
  if (end && end < today)     return { cls: 'past',   label: t('contracts.status.past') };
  return { cls: 'active', label: t('contracts.status.active') };
}

function renderContractCard(c, meters, u) {
  const meter = meters.find(m => m.id === c.meter_id);
  let summary;
  if (u.key === 'wasser') {
    const tw = c.trinkwasser || {};
    const sw = c.schmutzwasser || {};
    const nw = c.niederschlagswasser || {};
    summary = [
      t('contracts.summary.twPrices', { count: tw.working_prices?.length || 0 }),
      t('contracts.summary.swPrices', { count: sw.working_prices?.length || 0 }),
      t('contracts.summary.nwDates',  { count: nw.rates?.length || 0 }),
      t('contracts.summary.advances', { count: c.advance_payments?.length || 0 }),
      t('contracts.summary.bonuses',  { count: c.bonuses?.length || 0 }),
    ].join(' · ');
  } else {
    summary = [
      t('contracts.summary.workingPrices', { count: c.working_prices?.length || 0 }),
      t('contracts.summary.basePrices',    { count: c.base_prices?.length || 0 }),
      t('contracts.summary.advances',      { count: c.advance_payments?.length || 0 }),
      t('contracts.summary.bonuses',       { count: c.bonuses?.length || 0 }),
      ...(hasAdvancePaymentContracts(u)
          ? [t('contracts.summary.specialPayments', { count: c.special_payments?.length || 0 })]
          : []),
    ].join(' · ');
  }
  const st = contractStatus(c);
  return `
    <div class="card" style="margin-top: var(--sp-3)" data-utility="${u.key}">
      <div class="section-head">
        <h2 style="margin:0;font-size:var(--fs-lg)">${escapeHtml(c.provider || t('contracts.card.fallbackProvider'))}${c.tariff_name ? ' · ' + escapeHtml(c.tariff_name) : ''}
          <span class="status-pill ${st.cls}">${st.label}</span></h2>
        <div class="section-actions">
          <button class="btn btn--sm btn--ghost" data-edit-contract="${c.id}">${t('contracts.card.edit')}</button>
          <button class="btn btn--sm btn--danger" data-delete-contract="${c.id}" title="${t('contracts.deleteContract')}" aria-label="${t('contracts.deleteContract')}"><span aria-hidden="true">×</span></button>
        </div>
      </div>
      <div class="muted" style="font-size: var(--fs-sm)">
        ${fmt.date(c.start)} – ${c.end ? fmt.date(c.end) : t('contracts.card.open')}
        · ${t('contracts.card.meterLabel')} <strong>${escapeHtml(meter?.name || t('contracts.card.noMeter'))}</strong>
        · ${summary}
      </div>
      ${c.is_shadow ? `<p class="muted" style="margin-top:var(--sp-2);font-size:var(--fs-xs)">${t('contracts.card.shadowHint')}</p>` : ''}
      ${c.notes ? `<p style="margin-top:var(--sp-2)">${escapeHtml(c.notes)}</p>` : ''}
    </div>
  `;
}

// ───── Contract modal (F4) ──────────────────────────────────────────
async function openContractModal(u, meters, existing) {
  return new Promise(resolve => {
    const isEdit = !!existing;
    const initial = existing || {
      meter_id: meters[0]?.id || '',
      provider: '', tariff_name: '',
      start: todayIso(), end: '', notes: '',
      working_prices: [{ from: '', ct_per_kwh: '' }],
      base_prices:    [{ from: '', eur_per_month: '' }],
      advance_payments:[{ from: '', amount_eur: '' }],
      bonuses:        [],
      special_payments: [],
    };

    const body = document.createElement('div');
    body.innerHTML = `
      <form id="contract-form">
        <div class="form-row">
          <div class="field">
            <label>${t('contracts.modal.provider')}</label>
            <input class="input input--text" name="provider" value="${escapeHtml(initial.provider || '')}">
          </div>
          <div class="field">
            <label>${t('contracts.modal.tariff')}</label>
            <input class="input input--text" name="tariff_name" value="${escapeHtml(initial.tariff_name || '')}">
          </div>
          <div class="field">
            <label>${t('contracts.modal.meter')}</label>
            <select class="select" name="meter_id">
              ${meters.map(m => `<option value="${m.id}" ${m.id === initial.meter_id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>${t('contracts.modal.start')}</label>
            <input class="input" type="date" name="start" required value="${initial.start || ''}">
          </div>
          <div class="field">
            <label>${t('contracts.modal.end')}</label>
            <input class="input" type="date" name="end" value="${initial.end || ''}">
          </div>
        </div>
        <!-- v2.3.1 — Wechselplanung. Diese drei speisen den Tarifvergleich:
             Ohne Kündigungsfrist kann er keinen Wechseltermin errechnen und
             nicht vor ablaufenden Fristen warnen. Alle optional. -->
        <div class="form-row">
          <div class="field">
            <label>${t('contracts.modal.noticePeriod')}</label>
            <input class="input" type="number" min="0" max="24" step="1" name="notice_period_months"
                   value="${initial.notice_period_months ?? ''}"
                   placeholder="${t('contracts.modal.noticePeriodPlaceholder')}">
            <span class="settings-field__hint">${t('contracts.modal.noticePeriodHint')}</span>
          </div>
          <div class="field">
            <label>${t('contracts.modal.priceGuarantee')}</label>
            <input class="input" type="date" name="price_guarantee_until"
                   value="${initial.price_guarantee_until || ''}">
            <span class="settings-field__hint">${t('contracts.modal.priceGuaranteeHint')}</span>
          </div>
        </div>
        <div class="field">
          <label>${t('contracts.modal.minTermEnd')}</label>
          <input class="input" type="date" name="min_term_end"
                 value="${initial.min_term_end || ''}">
          <span class="settings-field__hint">${t('contracts.modal.minTermEndHint')}</span>
        </div>
        <div class="field">
          <label>${t('contracts.modal.notes')}</label>
          <textarea class="input input--text" name="notes">${escapeHtml(initial.notes || '')}</textarea>
        </div>

        ${GROUPS.map(g => renderGroupSection(g, initial[g.key] || [])).join('')}

        ${renderBonusSection(initial.bonuses || [])}

        ${hasAdvancePaymentContracts(u) ? renderSpecialPaymentSection(initial.special_payments || []) : ''}
      </form>
    `;

    openModal({
      title: isEdit ? t('contracts.modal.titleEdit') : t('contracts.modal.titleNew'),
      body,
      size: 'lg',
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--util" data-act="save">${t('contracts.modal.save')}</button>
      `,
      onMount({ modalEl, close }) {
        // Cancel/Save ZUERST binden — selbst wenn die Sub-Handler unten
        // wegen ungewöhnlicher Daten (z.B. migriertes v0.9.0-Format)
        // eine Exception werfen, bleiben die Fußleisten-Buttons funktional.
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => { close(false); resolve(false); });
        modalEl.querySelector('[data-act="save"]')?.addEventListener('click', async () => {
          const f = modalEl.querySelector('#contract-form');
          if (!validateAllGroups(modalEl)) {
            toastErr(t('contracts.modal.validationHalfRows'));
            return;
          }
          const payload = collectPayload(f);
          try {
            if (isEdit) await api.updateContract(u.key, existing.id, payload);
            else        await api.createContract(u.key, payload);
            toastOk(t('contracts.modal.saved'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });

        try { bindEntryGroupHandlers(modalEl); }
        catch (e) { console.warn('contract modal: entry-group binding failed:', e); }
        try { bindBonusHandlers(modalEl); }
        catch (e) { console.warn('contract modal: bonus binding failed:', e); }
        try { bindSpecialPaymentHandlers(modalEl); }
        catch (e) { console.warn('contract modal: special-payment binding failed:', e); }
        // A11y: alle Formularfelder mit ihren Labels verknüpfen.
        associateFieldLabels(modalEl);
      }
    });
  });
}

// ───── Group rendering ──────────────────────────────────────────────
function renderGroupSection(g, entries) {
  if (entries.length === 0) entries = [{ [g.dateKey]: '', [g.amountKey]: '' }];
  return `
    <div class="entry-group" data-group="${g.key}" data-date-key="${g.dateKey}" data-amount-key="${g.amountKey}">
      <div class="entry-group__head">
        <div class="entry-group__title">${t(g.titleKey)}</div>
        <button type="button" class="btn btn--sm btn--ghost" data-action="add-row">${t('contracts.group.addRow')}</button>
      </div>
      <div class="entries">
        ${entries.map(e => renderEntryRow(g, e)).join('')}
      </div>
    </div>
  `;
}

function renderEntryRow(g, e) {
  return `
    <div class="entry-row">
      <div class="field">
        <label>${t('contracts.row.validFrom')}</label>
        <input class="input" type="date" data-role="date" value="${e[g.dateKey] || ''}">
      </div>
      <div class="field">
        <label>${escapeHtml(t(g.amountKey_))}</label>
        <input class="input" type="number" step="${g.step}" data-role="amount" value="${e[g.amountKey] ?? ''}">
      </div>
      <button type="button" class="btn btn--sm btn--ghost" data-action="copy-start" title="${t('contracts.row.copyStartTitle')}">${t('contracts.row.copyStart')}</button>
      <button type="button" class="btn btn--sm btn--danger btn--icon" data-action="remove-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button>
    </div>
  `;
}

function bindEntryGroupHandlers(modalEl) {
  // Add-row buttons
  modalEl.querySelectorAll('[data-action="add-row"]').forEach(btn => {
    btn.addEventListener('click', () => {
      const group = btn.closest('[data-group]');
      const gKey  = group.getAttribute('data-group');
      const g = GROUPS.find(x => x.key === gKey);
      const wrap = document.createElement('div');
      wrap.innerHTML = renderEntryRow(g, { [g.dateKey]: '', [g.amountKey]: '' });
      const row = wrap.firstElementChild;
      group.querySelector('.entries').appendChild(row);
      bindRowHandlers(modalEl, row);
      associateFieldLabels(row);
    });
  });

  // Initial rows
  modalEl.querySelectorAll('.entry-row').forEach(row => bindRowHandlers(modalEl, row));
}

function bindRowHandlers(modalEl, row) {
  const dateInput   = row.querySelector('[data-role="date"]');
  const amountInput = row.querySelector('[data-role="amount"]');
  const copyBtn     = row.querySelector('[data-action="copy-start"]');
  const removeBtn   = row.querySelector('[data-action="remove-row"]');

  copyBtn?.addEventListener('click', () => {
    const start = modalEl.querySelector('input[name="start"]')?.value;
    if (!start) { toastErr(t('contracts.row.setStartFirst')); return; }
    if (dateInput) dateInput.value = start;
    validateRow(row);
  });

  removeBtn?.addEventListener('click', () => {
    const group = row.closest('[data-group]');
    row.remove();
    // Keep at least one row visible
    if (group && group.querySelectorAll('.entry-row').length === 0) {
      const gKey = group.getAttribute('data-group');
      const g = GROUPS.find(x => x.key === gKey);
      if (!g) return;
      const wrap = document.createElement('div');
      wrap.innerHTML = renderEntryRow(g, { [g.dateKey]: '', [g.amountKey]: '' });
      const newRow = wrap.firstElementChild;
      group.querySelector('.entries')?.appendChild(newRow);
      bindRowHandlers(modalEl, newRow);
      associateFieldLabels(newRow);
    }
  });

  [dateInput, amountInput].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => validateRow(row));
    i.addEventListener('blur',  () => validateRow(row));
  });
}

function validateRow(row) {
  const dateInput   = row.querySelector('[data-role="date"]');
  const amountInput = row.querySelector('[data-role="amount"]');
  const dateFilled   = dateInput.value.trim() !== '';
  const amountFilled = amountInput.value.trim() !== '';
  const halfFilled   = dateFilled !== amountFilled;
  dateInput.classList.toggle('invalid',   halfFilled && !dateFilled);
  amountInput.classList.toggle('invalid', halfFilled && !amountFilled);
  return !halfFilled;
}

function validateAllGroups(modalEl) {
  let ok = true;
  modalEl.querySelectorAll('.entry-row').forEach(row => {
    if (!validateRow(row)) ok = false;
  });
  return ok;
}

// ───── Bonus section ────────────────────────────────────────────────
function renderBonusSection(bonuses) {
  if (!bonuses || bonuses.length === 0) bonuses = [];
  return `
    <div class="entry-group" data-section="bonus">
      <div class="entry-group__head">
        <div class="entry-group__title">${t('contracts.bonus.title')}</div>
        <button type="button" class="btn btn--sm btn--ghost" data-action="add-bonus">${t('contracts.bonus.add')}</button>
      </div>
      <div class="entries">
        ${bonuses.map(b => renderBonusRow(b)).join('')}
      </div>
    </div>
  `;
}

function renderBonusRow(b = {}) {
  return `
    <div class="entry-row bonus-row">
      <div class="field">
        <label>${t('contracts.bonus.creditDate')}</label>
        <input class="input" type="date" data-role="date" value="${b.credit_date || ''}">
      </div>
      <div class="field">
        <label>${t('contracts.bonus.amount')}</label>
        <input class="input" type="number" step="0.01" data-role="amount" value="${b.amount_eur ?? ''}">
      </div>
      <div class="field">
        <label>${t('contracts.bonus.type')}</label>
        <select class="select" data-role="type">
          <option value="sofort"     ${b.type === 'sofort'     ? 'selected' : ''}>${t('contracts.bonusTypes.sofort')}</option>
          <option value="wechsel"    ${b.type === 'wechsel'    ? 'selected' : ''}>${t('contracts.bonusTypes.wechsel')}</option>
          <option value="neukunde"   ${b.type === 'neukunde'   ? 'selected' : ''}>${t('contracts.bonusTypes.neukunde')}</option>
        </select>
      </div>
      <button type="button" class="btn btn--sm btn--danger btn--icon" data-action="remove-bonus" title="${t('contracts.bonus.remove')}" aria-label="${t('contracts.bonus.remove')}"><span aria-hidden="true">×</span></button>
    </div>
  `;
}

function bindBonusHandlers(modalEl) {
  const sec = modalEl.querySelector('[data-section="bonus"]');
  if (!sec) return;
  sec.querySelector('[data-action="add-bonus"]')?.addEventListener('click', () => {
    const wrap = document.createElement('div');
    wrap.innerHTML = renderBonusRow({});
    const row = wrap.firstElementChild;
    sec.querySelector('.entries')?.appendChild(row);
    bindBonusRow(row);
    associateFieldLabels(row);
  });
  sec.querySelectorAll('.bonus-row').forEach(bindBonusRow);
}

function bindBonusRow(row) {
  row.querySelector('[data-action="remove-bonus"]')?.addEventListener('click', () => row.remove());
  const dateEl   = row.querySelector('[data-role="date"]');
  const amountEl = row.querySelector('[data-role="amount"]');
  [dateEl, amountEl].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => {
      const dateFilled   = (dateEl?.value || '').trim()   !== '';
      const amountFilled = (amountEl?.value || '').trim() !== '';
      const halfFilled   = dateFilled !== amountFilled;
      dateEl?.classList.toggle('invalid',   halfFilled && !dateFilled);
      amountEl?.classList.toggle('invalid', halfFilled && !amountFilled);
    });
  });
}

// ───── F1003 — Sonderzahlungen ──────────────────────────────────────
function renderSpecialPaymentSection(items) {
  if (!Array.isArray(items)) items = [];
  return `
    <div class="entry-group" data-section="special">
      <div class="entry-group__head">
        <div class="entry-group__title">${t('contracts.special.title')}</div>
        <button type="button" class="btn btn--sm btn--ghost" data-action="add-special">${t('contracts.special.add')}</button>
      </div>
      <div class="muted" style="font-size:var(--fs-sm);margin:-4px 0 8px">
        ${t('contracts.special.hint')}
      </div>
      <div class="entries">
        ${items.map(s => renderSpecialPaymentRow(s)).join('')}
      </div>
    </div>
  `;
}

function renderSpecialPaymentRow(s = {}) {
  const kind = s.kind || 'abschlagszahlung';
  const showAdvance = SPECIAL_KIND_AFFECTS_ADVANCE.has(kind);
  return `
    <div class="entry-row special-row" data-kind="${kind}">
      <div class="field">
        <label>${t('contracts.special.date')}</label>
        <input class="input" type="date" data-role="date" value="${s.date || ''}">
      </div>
      <div class="field">
        <label>${t('contracts.special.amount')}</label>
        <input class="input" type="number" step="0.01" min="0" data-role="amount" value="${s.amount_eur ?? ''}">
      </div>
      <div class="field">
        <label>${t('contracts.special.kind')}</label>
        <select class="select" data-role="kind">
          ${SPECIAL_PAYMENT_KINDS.map(k =>
            `<option value="${k.value}" ${k.value === kind ? 'selected' : ''}>${t('contracts.kinds.' + k.value)}</option>`
          ).join('')}
        </select>
      </div>
      <div class="field">
        <label>${t('contracts.special.note')}</label>
        <input class="input input--text" data-role="note" value="${escapeHtml(s.note || '')}">
      </div>
      <button type="button" class="btn btn--sm btn--danger btn--icon" data-action="remove-special" title="${t('contracts.special.remove')}" aria-label="${t('contracts.special.remove')}"><span aria-hidden="true">×</span></button>
      <div class="special-advance" data-role="advance-block"
           style="${showAdvance ? '' : 'display:none'};flex-basis:100%;display:${showAdvance ? 'flex' : 'none'};gap:var(--sp-3);margin-top:var(--sp-2)">
        <div class="field">
          <label>${t('contracts.special.newAdvance')}</label>
          <input class="input" type="number" step="0.01" min="0" data-role="new-advance" value="${s.new_advance_eur ?? ''}">
        </div>
        <div class="field">
          <label>${t('contracts.special.advanceFrom')}</label>
          <input class="input" type="date" data-role="advance-from" value="${s.advance_from || ''}">
        </div>
      </div>
    </div>
  `;
}

function bindSpecialPaymentHandlers(modalEl) {
  const sec = modalEl.querySelector('[data-section="special"]');
  if (!sec) return;
  sec.querySelector('[data-action="add-special"]')?.addEventListener('click', () => {
    const wrap = document.createElement('div');
    wrap.innerHTML = renderSpecialPaymentRow({});
    const row = wrap.firstElementChild;
    sec.querySelector('.entries')?.appendChild(row);
    bindSpecialPaymentRow(row);
    associateFieldLabels(row);
  });
  sec.querySelectorAll('.special-row').forEach(bindSpecialPaymentRow);
}

function bindSpecialPaymentRow(row) {
  row.querySelector('[data-action="remove-special"]')?.addEventListener('click', () => row.remove());
  const kindEl    = row.querySelector('[data-role="kind"]');
  const advBlock  = row.querySelector('[data-role="advance-block"]');
  const dateEl    = row.querySelector('[data-role="date"]');
  const amountEl  = row.querySelector('[data-role="amount"]');
  const naEl      = row.querySelector('[data-role="new-advance"]');
  const afEl      = row.querySelector('[data-role="advance-from"]');

  const syncAdvanceVisibility = () => {
    const affects = SPECIAL_KIND_AFFECTS_ADVANCE.has(kindEl?.value);
    if (advBlock) advBlock.style.display = affects ? 'flex' : 'none';
    if (!affects) { // beim Wechsel auf "ohne" die Abschlagsfelder leeren
      if (naEl) naEl.value = '';
      if (afEl) afEl.value = '';
    }
    row.dataset.kind = kindEl?.value || '';
  };
  kindEl?.addEventListener('change', syncAdvanceVisibility);

  // Halb-leer-Markierung Datum/Betrag (analog Bonus)
  [dateEl, amountEl].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => {
      const d = (dateEl?.value || '').trim() !== '';
      const a = (amountEl?.value || '').trim() !== '';
      const half = d !== a;
      dateEl?.classList.toggle('invalid',   half && !d);
      amountEl?.classList.toggle('invalid', half && !a);
    });
  });
  // Halb-leer-Markierung der Abschlagsfelder (nur bei *_mit relevant)
  [naEl, afEl].filter(Boolean).forEach(i => {
    i.addEventListener('input', () => {
      const n = (naEl?.value || '').trim() !== '';
      const f = (afEl?.value || '').trim() !== '';
      const half = n !== f;
      naEl?.classList.toggle('invalid', half && !n);
      afEl?.classList.toggle('invalid', half && !f);
    });
  });
}

// ───── Payload extraction ───────────────────────────────────────────
function collectPayload(form) {
  // Leeres Feld → null, nicht 0 oder "": Eine Kündigungsfrist von null heißt
  // „nicht gepflegt" und schaltet die Terminrechnung ab; eine von 0 hieße
  // „jederzeit kündbar" und ist eine Aussage.
  const notice = form.notice_period_months?.value.trim();
  const payload = {
    provider:    form.provider.value,
    tariff_name: form.tariff_name.value,
    meter_id:    form.meter_id.value,
    start:       form.start.value,
    end:         form.end.value || null,
    notes:       form.notes.value,
    notice_period_months:  notice === '' || notice === undefined ? null : Number(notice),
    min_term_end:          form.min_term_end?.value || null,
    price_guarantee_until: form.price_guarantee_until?.value || null,
  };

  for (const g of GROUPS) {
    const group = form.querySelector(`[data-group="${g.key}"]`);
    const rows = group.querySelectorAll('.entry-row');
    const entries = [];
    rows.forEach(row => {
      const date   = row.querySelector('[data-role="date"]').value.trim();
      const amount = row.querySelector('[data-role="amount"]').value.trim();
      // Send all rows including half-filled — backend will reject loudly.
      // But skip fully-empty so blank template rows don't trigger silent drops.
      if (!date && !amount) return;
      entries.push({ [g.dateKey]: date, [g.amountKey]: amount === '' ? '' : Number(amount) });
    });
    payload[g.key] = entries;
  }

  const bonusEntries = [];
  form.querySelectorAll('.bonus-row').forEach(row => {
    const cd  = row.querySelector('[data-role="date"]').value.trim();
    const am  = row.querySelector('[data-role="amount"]').value.trim();
    const tp  = row.querySelector('[data-role="type"]').value;
    if (!cd && !am) return;
    bonusEntries.push({ credit_date: cd, amount_eur: am === '' ? '' : Number(am), type: tp, label: '' });
  });
  payload.bonuses = bonusEntries;

  // F1003 — Sonderzahlungen
  const specialEntries = [];
  form.querySelectorAll('.special-row').forEach(row => {
    const date = row.querySelector('[data-role="date"]').value.trim();
    const am   = row.querySelector('[data-role="amount"]').value.trim();
    const kind = row.querySelector('[data-role="kind"]').value;
    const note = row.querySelector('[data-role="note"]')?.value.trim() || '';
    if (!date && !am) return; // leere Vorlagezeile überspringen
    const entry = {
      date,
      amount_eur: am === '' ? '' : Number(am),
      kind,
      note,
    };
    if (SPECIAL_KIND_AFFECTS_ADVANCE.has(kind)) {
      const na = row.querySelector('[data-role="new-advance"]')?.value.trim() || '';
      const af = row.querySelector('[data-role="advance-from"]')?.value.trim() || '';
      entry.new_advance_eur = na === '' ? '' : Number(na);
      entry.advance_from    = af;
    }
    specialEntries.push(entry);
  });
  payload.special_payments = specialEntries;

  return payload;
}

// ───── Water Contract modal (v1.0.3) ────────────────────────────────
// Drei separate Komponenten: Trinkwasser, Schmutzwasser, Niederschlagswasser.
// Eigene Saldo-Logik, eigene Felder.
async function openWaterContractModal(u, meters, existing) {
  return new Promise(resolve => {
    const isEdit = !!existing;
    const initial = existing || {
      meter_id: meters[0]?.id || '',
      provider: '', tariff_name: '',
      start: todayIso(), end: '', notes: '',
      trinkwasser: {
        working_prices: [{ from: '', ct_per_m3: '' }],
        base_prices:    [{ from: '', eur_per_month: '' }],
      },
      schmutzwasser: {
        basis: 'trinkwasser',
        separater_zaehler_meter_id: null,
        working_prices: [{ from: '', ct_per_m3: '' }],
      },
      niederschlagswasser: {
        rates: [{ from: '', eur_per_m2_year: '', versiegelte_flaeche_m2: '' }],
      },
      advance_payments:[{ from: '', amount_eur: '' }],
      bonuses: [],
    };

    const body = document.createElement('div');
    body.innerHTML = waterFormHtml(initial, meters, u);

    const footer = document.createElement('div');
    footer.innerHTML = `
      <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button type="button" class="btn btn--primary" data-act="save">${isEdit ? t('contracts.modal.save') : t('contracts.modal.create')}</button>
    `;

    openModal({
      title: isEdit ? t('contracts.water.titleEdit') : t('contracts.water.titleNew'),
      body: body.innerHTML,
      footer: footer.innerHTML,
      onMount({ modalEl, close }) {
        bindWaterEntryHandlers(modalEl);
        associateFieldLabels(modalEl);

        // Toggle separater Zähler input
        const basisRadios = modalEl.querySelectorAll('input[name="schmutz-basis"]');
        const basisSep = modalEl.querySelector('#schmutz-separater-row');
        basisRadios.forEach(r => r.addEventListener('change', () => {
          basisSep.style.display = modalEl.querySelector('input[name="schmutz-basis"]:checked').value === 'separater_zaehler' ? '' : 'none';
        }));

        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(null); resolve(false); });
        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          // v2.2.0 — halb gefüllte Zeilen vor dem Absenden markieren (analog
          // validateAllGroups im Standard-Formular). Vorher rutschten sie als
          // stille 0 durch, siehe collectWaterForm().
          if (!validateWaterRows(modalEl)) {
            toastErr(t('contracts.modal.validationHalfRows'));
            return;
          }
          let payload;
          try { payload = collectWaterForm(modalEl); }
          catch (err) { toastErr(err.message); return; }
          try {
            if (isEdit) await api.updateContract(u.key, existing.id, payload);
            else        await api.createContract(u.key, payload);
            toastOk(isEdit ? t('contracts.water.savedEdit') : t('contracts.water.savedNew'));
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });
      },
    });
  });
}

function waterFormHtml(c, meters, u) {
  const meterOpts = meters.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === c.meter_id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');
  const sw = c.schmutzwasser || {};
  const basis = sw.basis || 'trinkwasser';
  const otherMeters = meters.filter(m => m.id !== c.meter_id);
  const sepMeterOpts = otherMeters.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === sw.separater_zaehler_meter_id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('');

  return `
    <form id="water-contract-form">
      <div class="form-row">
        <div class="field"><label>${t('contracts.water.provider')}</label>
          <input class="input input--text" name="provider" value="${escapeHtml(c.provider || '')}"></div>
        <div class="field"><label>${t('contracts.water.tariff')}</label>
          <input class="input input--text" name="tariff_name" value="${escapeHtml(c.tariff_name || '')}"></div>
      </div>
      <div class="form-row">
        <div class="field"><label>${t('contracts.water.start')}</label><input class="input" type="date" name="start" value="${escapeHtml(c.start || '')}" required></div>
        <div class="field"><label>${t('contracts.water.end')}</label><input class="input" type="date" name="end" value="${escapeHtml(c.end || '')}"></div>
        <div class="field"><label>${t('contracts.water.meter')}</label><select class="select" name="meter_id" required>${meterOpts}</select></div>
      </div>
      <div class="field">
        <label>${t('contracts.water.notes')}</label>
        <input class="input input--text" name="notes" value="${escapeHtml(c.notes || '')}">
      </div>

      <!-- Trinkwasser -->
      <details open style="margin-top:var(--sp-4);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.tw')}</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('tw-working', t('contracts.water.twWorking'), c.trinkwasser?.working_prices || [], ['from', 'ct_per_m3'], [t('contracts.water.colDate'), t('contracts.water.colCtM3')])}
          ${renderWaterEntryGroup('tw-base', t('contracts.water.twBase'), c.trinkwasser?.base_prices || [], ['from', 'eur_per_month'], [t('contracts.water.colDate'), t('contracts.water.colEurMonth')])}
        </div>
      </details>

      <!-- Schmutzwasser -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.sw')}</summary>
        <div style="margin-top:12px">
          <div class="field">
            <label>${t('contracts.water.basis')}</label>
            <label style="display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:normal">
              <input type="radio" name="schmutz-basis" value="trinkwasser" ${basis === 'trinkwasser' ? 'checked' : ''}>
              <span>${t('contracts.water.basisTw')}</span>
            </label>
            <label style="display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:normal">
              <input type="radio" name="schmutz-basis" value="separater_zaehler" ${basis === 'separater_zaehler' ? 'checked' : ''}>
              <span>${t('contracts.water.basisSep')}</span>
            </label>
          </div>
          <div class="field" id="schmutz-separater-row" style="${basis === 'separater_zaehler' ? '' : 'display:none'}">
            <label>${t('contracts.water.sepMeter')}</label>
            <select class="select" name="schmutz_separater_zaehler_meter_id">${sepMeterOpts || `<option value="">${t('contracts.water.noMoreMeters')}</option>`}</select>
          </div>
          ${renderWaterEntryGroup('sw-working', t('contracts.water.swWorking'), sw.working_prices || [], ['from', 'ct_per_m3'], [t('contracts.water.colDate'), t('contracts.water.colCtM3')])}
        </div>
      </details>

      <!-- Niederschlagswasser -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.nw')}</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('nw-rates', t('contracts.water.nwRates'), c.niederschlagswasser?.rates || [], ['from', 'eur_per_m2_year', 'versiegelte_flaeche_m2'], [t('contracts.water.colDate'), t('contracts.water.colEurM2Year'), t('contracts.water.colSealedM2')])}
        </div>
      </details>

      <!-- Abschläge -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.advances')}</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('ap', t('contracts.water.apMonthly'), c.advance_payments || [], ['from', 'amount_eur'], [t('contracts.water.colDate'), t('contracts.water.colEurMonth')])}
        </div>
      </details>

      <!-- Bonuses -->
      <details style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">${t('contracts.water.bonuses')}</summary>
        <div style="margin-top:12px">
          ${renderWaterBonusGroup(c.bonuses || [])}
        </div>
      </details>
    </form>
  `;
}

function renderWaterEntryGroup(groupKey, title, entries, fields, labels) {
  if (!entries.length) entries = [Object.fromEntries(fields.map(f => [f, '']))];
  const inputs = fields.map((f, i) => `<th scope="col">${escapeHtml(labels[i])}</th>`).join('');
  const rows = entries.map((e, idx) => `
    <tr data-row="${idx}">
      ${fields.map((f, i) => `
        <td>
          ${i === 0
            ? `<input class="input" type="date" data-field="${escapeHtml(f)}" value="${escapeHtml(e[f] ?? '')}">`
            : `<input class="input num" type="number" step="0.001" data-field="${escapeHtml(f)}" value="${escapeHtml(String(e[f] ?? ''))}">`}
        </td>
      `).join('')}
      <td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button></td>
    </tr>
  `).join('');
  return `
    <div class="field" data-group="${escapeHtml(groupKey)}" data-fields='${escapeHtml(JSON.stringify(fields))}'>
      <label>${escapeHtml(title)}</label>
      <table class="table table--compact" style="width:100%">
        <thead><tr>${inputs}<th scope="col"><span class="sr-only">${t('common.actions')}</span></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <button type="button" class="btn btn--sm btn--ghost" data-act="add-row" style="margin-top:6px">${t('contracts.water.addRow')}</button>
    </div>
  `;
}

function renderWaterBonusGroup(bonuses) {
  if (!bonuses.length) bonuses = [{ credit_date: '', amount_eur: '', type: 'neukunde', label: '' }];
  const rows = bonuses.map((b, idx) => `
    <tr data-row="${idx}">
      <td><input class="input" type="date" data-field="credit_date" value="${escapeHtml(b.credit_date || '')}"></td>
      <td><input class="input num" type="number" step="0.01" data-field="amount_eur" value="${escapeHtml(String(b.amount_eur || ''))}"></td>
      <td><input class="input input--text" data-field="label" value="${escapeHtml(b.label || '')}"></td>
      <td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button></td>
    </tr>
  `).join('');
  return `
    <div class="field" data-group="bonuses" data-fields='["credit_date","amount_eur","label"]'>
      <table class="table table--compact" style="width:100%">
        <thead><tr><th scope="col">${t('contracts.water.bonusCredit')}</th><th scope="col">${t('contracts.water.bonusAmount')}</th><th scope="col">${t('contracts.water.bonusLabel')}</th><th scope="col"><span class="sr-only">${t('common.actions')}</span></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <button type="button" class="btn btn--sm btn--ghost" data-act="add-row" style="margin-top:6px">${t('contracts.water.addRow')}</button>
    </div>
  `;
}

function bindWaterEntryHandlers(modalEl) {
  modalEl.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-act]');
    if (!btn) return;
    const group = btn.closest('[data-group]');
    if (!group) return;
    if (btn.getAttribute('data-act') === 'del-row') {
      const row = btn.closest('tr');
      if (row && row.parentElement.querySelectorAll('tr').length > 1) row.remove();
      else row?.querySelectorAll('input').forEach(i => i.value = '');
    } else if (btn.getAttribute('data-act') === 'add-row') {
      const tbody = group.querySelector('tbody');
      const fields = JSON.parse(group.getAttribute('data-fields') || '[]');
      const tr = document.createElement('tr');
      tr.setAttribute('data-row', tbody.children.length);
      const cells = fields.map((f, i) => {
        if (f === 'credit_date' || f === 'from') {
          return `<td><input class="input" type="date" data-field="${f}"></td>`;
        }
        if (f === 'label') {
          return `<td><input class="input input--text" data-field="${f}"></td>`;
        }
        return `<td><input class="input num" type="number" step="0.001" data-field="${f}"></td>`;
      }).join('');
      tr.innerHTML = `${cells}<td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row" title="${t('contracts.row.removeRow')}" aria-label="${t('contracts.row.removeRow')}"><span aria-hidden="true">×</span></button></td>`;
      tbody.appendChild(tr);
    }
  });
}

// v2.2.0 — Pflichtfelder je Wasser-Gruppe. Beim Bonus ist `label` optional,
// überall sonst müssen alle Spalten einer Zeile gefüllt sein (oder keine).
function requiredWaterFields(groupKey, fields) {
  return groupKey === 'bonuses'
    ? fields.filter(f => f !== 'label')
    : fields;
}

/**
 * Markiert halb gefüllte Zeilen in den Wasser-Tabellen und meldet, ob das
 * Formular abgeschickt werden darf. Gegenstück zu validateAllGroups() im
 * Standard-Vertragsformular.
 */
function validateWaterRows(modalEl) {
  let ok = true;
  modalEl.querySelectorAll('[data-group]').forEach(group => {
    const key = group.getAttribute('data-group');
    let fields;
    try { fields = JSON.parse(group.getAttribute('data-fields') || '[]'); }
    catch { return; }
    const required = requiredWaterFields(key, fields);
    group.querySelectorAll('tbody tr').forEach(tr => {
      const inputs = required
        .map(f => tr.querySelector(`[data-field="${f}"]`))
        .filter(Boolean);
      const filled = inputs.filter(i => i.value.trim() !== '').length;
      const halfFilled = filled > 0 && filled < inputs.length;
      inputs.forEach(i => i.classList.toggle('invalid', halfFilled && i.value.trim() === ''));
      if (halfFilled) ok = false;
    });
  });
  return ok;
}

function collectWaterForm(modalEl) {
  const form = modalEl.querySelector('#water-contract-form');
  const fd = new FormData(form);
  // v2.2.0 — Leere Zahlenfelder NICHT auf 0 zwingen. `parseFloat(x || 0)` machte
  // aus einem vergessenen Preis einen echten Tarif von 0 ct/m³ ab Stichtag; die
  // Kosten fielen ab da still auf den Grundpreis. Ein leerer String erreicht
  // stattdessen den Backend-Guard (normalizePriceList), der die halb gefüllte
  // Zeile ablehnt, statt sie zu speichern.
  const num = (v) => {
    const s = String(v ?? '').trim();
    if (s === '') return '';
    const n = parseFloat(s.replace(',', '.'));
    return Number.isFinite(n) ? n : '';
  };
  const collect = (groupKey) => {
    const group = modalEl.querySelector(`[data-group="${groupKey}"]`);
    if (!group) return [];
    const fields = JSON.parse(group.getAttribute('data-fields') || '[]');
    const rows = [...group.querySelectorAll('tbody tr')];
    return rows.map(tr => {
      const obj = {};
      fields.forEach(f => {
        const inp = tr.querySelector(`[data-field="${f}"]`);
        obj[f] = inp ? inp.value : '';
      });
      // Drop fully empty rows
      const allEmpty = Object.values(obj).every(v => v === '' || v === null);
      return allEmpty ? null : obj;
    }).filter(Boolean);
  };

  const payload = {
    provider:    fd.get('provider') || '',
    tariff_name: fd.get('tariff_name') || '',
    start:       fd.get('start') || '',
    end:         fd.get('end') || '',
    notes:       fd.get('notes') || '',
    meter_id:    fd.get('meter_id') || '',
    trinkwasser: {
      working_prices: collect('tw-working').map(r => ({ from: r.from, ct_per_m3: num(r.ct_per_m3) })),
      base_prices:    collect('tw-base').map(r => ({ from: r.from, eur_per_month: num(r.eur_per_month) })),
    },
    schmutzwasser: {
      basis: fd.get('schmutz-basis') || 'trinkwasser',
      separater_zaehler_meter_id: (fd.get('schmutz-basis') === 'separater_zaehler')
        ? (fd.get('schmutz_separater_zaehler_meter_id') || null)
        : null,
      working_prices: collect('sw-working').map(r => ({ from: r.from, ct_per_m3: num(r.ct_per_m3) })),
    },
    niederschlagswasser: {
      rates: collect('nw-rates').map(r => ({
        from: r.from,
        eur_per_m2_year: num(r.eur_per_m2_year),
        versiegelte_flaeche_m2: num(r.versiegelte_flaeche_m2),
      })),
    },
    advance_payments: collect('ap').map(r => ({ from: r.from, amount_eur: num(r.amount_eur) })),
    bonuses: collect('bonuses').map(r => ({
      credit_date: r.credit_date,
      amount_eur: num(r.amount_eur),
      label: r.label || '',
      type: 'neukunde',
    })),
  };

  if (!payload.start) throw new Error(t('contracts.water.startRequired'));
  return payload;
}
