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

const GROUPS = [
  { key: 'working_prices',   title: 'Arbeitspreis',    dateKey: 'from', amountKey: 'ct_per_kwh',  amountLabel: 'ct/kWh',  step: '0.001' },
  { key: 'base_prices',      title: 'Grundpreis',      dateKey: 'from', amountKey: 'eur_per_month', amountLabel: '€/Monat', step: '0.01' },
  { key: 'advance_payments', title: 'Abschlag',        dateKey: 'from', amountKey: 'amount_eur',  amountLabel: '€/Monat', step: '0.01' },
];

export async function render(container, params) {
  const utilityKey = params[0];
  const u = await getUtility(utilityKey);
  if (!u) {
    container.innerHTML = `<div class="banner banner--error">Unbekannte Verbrauchsart: ${escapeHtml(utilityKey)}</div>`;
    return;
  }
  container.setAttribute('data-utility', u.key);
  await refresh(container, u);
}

async function refresh(container, u) {
  container.innerHTML = '<div class="loading">Lade…</div>';
  const [meters, contracts] = await Promise.all([
    api.meters(u.key),
    api.contracts(u.key),
  ]);

  container.innerHTML = `
    <div data-utility="${u.key}">
      <div class="section-head">
        <h1>${u.icon} ${escapeHtml(u.label)} · Verträge</h1>
        <div class="section-actions">
          <a class="btn btn--ghost" href="#/utility/${u.key}">Zur Übersicht</a>
          <button type="button" class="btn btn--util" data-action="new-contract" ${meters.length ? '' : 'disabled'}>+ Neuer Vertrag</button>
        </div>
      </div>

      ${meters.length === 0 ? `
        <div class="banner banner--warning">Erst Zähler anlegen, bevor Verträge erstellt werden. <a href="#/utility/${u.key}/meters">→ Zähler verwalten</a></div>
      ` : ''}

      <div id="contracts-list">
        ${contracts.length === 0 ? '<p class="muted">Noch keine Verträge.</p>' : ''}
        ${contracts.map(c => renderContractCard(c, meters, u)).join('')}
      </div>
    </div>
  `;

  container.querySelector('[data-action="new-contract"]')?.addEventListener('click', () => {
    openContractModal(u, meters, null).then(changed => { if (changed) refresh(container, u); });
  });

  container.querySelectorAll('[data-edit-contract]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit-contract');
      const c = contracts.find(x => x.id === id);
      const changed = await openContractModal(u, meters, c);
      if (changed) refresh(container, u);
    });
  });

  container.querySelectorAll('[data-delete-contract]').forEach(b => {
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-delete-contract');
      const ok = await confirmModal({ title: 'Vertrag löschen?', message: 'Vertrag dauerhaft löschen?', confirmLabel: 'Löschen', danger: true });
      if (!ok) return;
      try { await api.deleteContract(u.key, id); toastOk('Vertrag gelöscht'); refresh(container, u); }
      catch (e) { toastErr(e.message); }
    });
  });
}

function renderContractCard(c, meters, u) {
  const meter = meters.find(m => m.id === c.meter_id);
  return `
    <div class="card" style="margin-top: var(--sp-3)" data-utility="${u.key}">
      <div class="section-head">
        <h3 style="margin:0">${escapeHtml(c.provider || 'Vertrag')}${c.tariff_name ? ' · ' + escapeHtml(c.tariff_name) : ''}</h3>
        <div class="section-actions">
          <button class="btn btn--sm btn--ghost" data-edit-contract="${c.id}">Bearbeiten</button>
          <button class="btn btn--sm btn--danger" data-delete-contract="${c.id}">×</button>
        </div>
      </div>
      <div class="muted" style="font-size: var(--fs-sm)">
        ${fmt.date(c.start)} – ${c.end ? fmt.date(c.end) : 'offen'}
        · Zähler: <strong>${escapeHtml(meter?.name || '—')}</strong>
        · ${(c.working_prices?.length || 0)} Arbeitspreise
        · ${(c.base_prices?.length || 0)} Grundpreise
        · ${(c.advance_payments?.length || 0)} Abschläge
        · ${(c.bonuses?.length || 0)} Boni
      </div>
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
    };

    const body = document.createElement('div');
    body.innerHTML = `
      <form id="contract-form">
        <div class="form-row">
          <div class="field">
            <label>Anbieter</label>
            <input class="input input--text" name="provider" value="${escapeHtml(initial.provider || '')}">
          </div>
          <div class="field">
            <label>Tarif</label>
            <input class="input input--text" name="tariff_name" value="${escapeHtml(initial.tariff_name || '')}">
          </div>
          <div class="field">
            <label>Zähler</label>
            <select class="select" name="meter_id">
              ${meters.map(m => `<option value="${m.id}" ${m.id === initial.meter_id ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label>Vertragsbeginn *</label>
            <input class="input" type="date" name="start" required value="${initial.start || ''}">
          </div>
          <div class="field">
            <label>Vertragsende (optional)</label>
            <input class="input" type="date" name="end" value="${initial.end || ''}">
          </div>
        </div>
        <div class="field">
          <label>Notizen</label>
          <textarea class="input input--text" name="notes">${escapeHtml(initial.notes || '')}</textarea>
        </div>

        ${GROUPS.map(g => renderGroupSection(g, initial[g.key] || [])).join('')}

        ${renderBonusSection(initial.bonuses || [])}
      </form>
    `;

    openModal({
      title: isEdit ? 'Vertrag bearbeiten' : 'Neuer Vertrag',
      body,
      size: 'lg',
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
        <button type="button" class="btn btn--util" data-act="save">Speichern</button>
      `,
      onMount({ modalEl, close }) {
        bindEntryGroupHandlers(modalEl);
        bindBonusHandlers(modalEl);

        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          const f = modalEl.querySelector('#contract-form');
          if (!validateAllGroups(modalEl)) {
            toastErr('Bitte halb-leere Zeilen vervollständigen oder leeren');
            return;
          }
          const payload = collectPayload(f);
          try {
            if (isEdit) await api.updateContract(u.key, existing.id, payload);
            else        await api.createContract(u.key, payload);
            toastOk('Vertrag gespeichert');
            close(true); resolve(true);
          } catch (e) { toastErr(e.message); }
        });
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
        <div class="entry-group__title">${g.title}</div>
        <button type="button" class="btn btn--sm btn--ghost" data-action="add-row">+ Zeile</button>
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
        <label>Gültig ab</label>
        <input class="input" type="date" data-role="date" value="${e[g.dateKey] || ''}">
      </div>
      <div class="field">
        <label>${escapeHtml(g.amountLabel)}</label>
        <input class="input" type="number" step="${g.step}" data-role="amount" value="${e[g.amountKey] ?? ''}">
      </div>
      <button type="button" class="btn btn--sm btn--ghost" data-action="copy-start" title="Vertragsbeginn übernehmen">⇧ Start</button>
      <button type="button" class="btn btn--sm btn--danger btn--icon" data-action="remove-row" title="Zeile entfernen">×</button>
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

  copyBtn.addEventListener('click', () => {
    const start = modalEl.querySelector('input[name="start"]').value;
    if (!start) { toastErr('Bitte zuerst Vertragsbeginn setzen'); return; }
    dateInput.value = start;
    validateRow(row);
  });

  removeBtn.addEventListener('click', () => {
    const group = row.closest('[data-group]');
    row.remove();
    // Keep at least one row visible
    if (group.querySelectorAll('.entry-row').length === 0) {
      const gKey = group.getAttribute('data-group');
      const g = GROUPS.find(x => x.key === gKey);
      const wrap = document.createElement('div');
      wrap.innerHTML = renderEntryRow(g, { [g.dateKey]: '', [g.amountKey]: '' });
      const newRow = wrap.firstElementChild;
      group.querySelector('.entries').appendChild(newRow);
      bindRowHandlers(modalEl, newRow);
    }
  });

  [dateInput, amountInput].forEach(i => {
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
    <div class="entry-group" id="bonus-section">
      <div class="entry-group__head">
        <div class="entry-group__title">Boni / Gutschriften</div>
        <button type="button" class="btn btn--sm btn--ghost" data-action="add-bonus">+ Bonus</button>
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
        <label>Gutschriftsdatum</label>
        <input class="input" type="date" data-role="date" value="${b.credit_date || ''}">
      </div>
      <div class="field">
        <label>Betrag €</label>
        <input class="input" type="number" step="0.01" data-role="amount" value="${b.amount_eur ?? ''}">
      </div>
      <div class="field">
        <label>Typ</label>
        <select class="select" data-role="type">
          <option value="sofort"     ${b.type === 'sofort'     ? 'selected' : ''}>Sofort</option>
          <option value="wechsel"    ${b.type === 'wechsel'    ? 'selected' : ''}>Wechsel</option>
          <option value="neukunde"   ${b.type === 'neukunde'   ? 'selected' : ''}>Neukunde</option>
        </select>
      </div>
      <button type="button" class="btn btn--sm btn--danger btn--icon" data-action="remove-bonus" title="Entfernen">×</button>
    </div>
  `;
}

function bindBonusHandlers(modalEl) {
  const sec = modalEl.querySelector('#bonus-section');
  sec.querySelector('[data-action="add-bonus"]').addEventListener('click', () => {
    const wrap = document.createElement('div');
    wrap.innerHTML = renderBonusRow({});
    const row = wrap.firstElementChild;
    sec.querySelector('.entries').appendChild(row);
    bindBonusRow(row);
  });
  sec.querySelectorAll('.bonus-row').forEach(bindBonusRow);
}

function bindBonusRow(row) {
  row.querySelector('[data-action="remove-bonus"]').addEventListener('click', () => row.remove());
  [row.querySelector('[data-role="date"]'), row.querySelector('[data-role="amount"]')].forEach(i => {
    i.addEventListener('input', () => {
      const dateFilled   = row.querySelector('[data-role="date"]').value.trim()   !== '';
      const amountFilled = row.querySelector('[data-role="amount"]').value.trim() !== '';
      const halfFilled   = dateFilled !== amountFilled;
      row.querySelector('[data-role="date"]').classList.toggle('invalid',   halfFilled && !dateFilled);
      row.querySelector('[data-role="amount"]').classList.toggle('invalid', halfFilled && !amountFilled);
    });
  });
}

// ───── Payload extraction ───────────────────────────────────────────
function collectPayload(form) {
  const payload = {
    provider:    form.provider.value,
    tariff_name: form.tariff_name.value,
    meter_id:    form.meter_id.value,
    start:       form.start.value,
    end:         form.end.value || null,
    notes:       form.notes.value,
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

  return payload;
}
