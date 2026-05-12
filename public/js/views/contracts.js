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
    dispatchContractModal(u, meters, null).then(changed => { if (changed) refresh(container, u); });
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
      const ok = await confirmModal({ title: 'Vertrag löschen?', message: 'Vertrag dauerhaft löschen?', confirmLabel: 'Löschen', danger: true });
      if (!ok) return;
      try { await api.deleteContract(u.key, id); toastOk('Vertrag gelöscht'); refresh(container, u); }
      catch (e) { toastErr(e.message); }
    });
  });
}

function dispatchContractModal(u, meters, existing) {
  return u.key === 'wasser'
    ? openWaterContractModal(u, meters, existing)
    : openContractModal(u, meters, existing);
}

function renderContractCard(c, meters, u) {
  const meter = meters.find(m => m.id === c.meter_id);
  let summary;
  if (u.key === 'wasser') {
    const tw = c.trinkwasser || {};
    const sw = c.schmutzwasser || {};
    const nw = c.niederschlagswasser || {};
    summary = [
      `${tw.working_prices?.length || 0} Trinkw.-Preise`,
      `${sw.working_prices?.length || 0} Schmutzw.-Preise`,
      `${nw.rates?.length || 0} Niederschlag-Stichtage`,
      `${(c.advance_payments?.length || 0)} Abschläge`,
      `${(c.bonuses?.length || 0)} Boni`,
    ].join(' · ');
  } else {
    summary = [
      `${(c.working_prices?.length || 0)} Arbeitspreise`,
      `${(c.base_prices?.length || 0)} Grundpreise`,
      `${(c.advance_payments?.length || 0)} Abschläge`,
      `${(c.bonuses?.length || 0)} Boni`,
    ].join(' · ');
  }
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
        · ${summary}
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
        // Cancel/Save ZUERST binden — selbst wenn die Sub-Handler unten
        // wegen ungewöhnlicher Daten (z.B. migriertes v0.9.0-Format)
        // eine Exception werfen, bleiben die Fußleisten-Buttons funktional.
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => { close(false); resolve(false); });
        modalEl.querySelector('[data-act="save"]')?.addEventListener('click', async () => {
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

        try { bindEntryGroupHandlers(modalEl); }
        catch (e) { console.warn('contract modal: entry-group binding failed:', e); }
        try { bindBonusHandlers(modalEl); }
        catch (e) { console.warn('contract modal: bonus binding failed:', e); }
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

  copyBtn?.addEventListener('click', () => {
    const start = modalEl.querySelector('input[name="start"]')?.value;
    if (!start) { toastErr('Bitte zuerst Vertragsbeginn setzen'); return; }
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
  const sec = modalEl.querySelector('[data-section="bonus"]');
  if (!sec) return;
  sec.querySelector('[data-action="add-bonus"]')?.addEventListener('click', () => {
    const wrap = document.createElement('div');
    wrap.innerHTML = renderBonusRow({});
    const row = wrap.firstElementChild;
    sec.querySelector('.entries')?.appendChild(row);
    bindBonusRow(row);
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
      <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
      <button type="button" class="btn btn--primary" data-act="save">${isEdit ? 'Speichern' : 'Anlegen'}</button>
    `;

    openModal({
      title: isEdit ? `Wasservertrag bearbeiten` : `Neuer Wasservertrag`,
      body: body.innerHTML,
      footer: footer.innerHTML,
      onMount({ modalEl, close }) {
        bindWaterEntryHandlers(modalEl);

        // Toggle separater Zähler input
        const basisRadios = modalEl.querySelectorAll('input[name="schmutz-basis"]');
        const basisSep = modalEl.querySelector('#schmutz-separater-row');
        basisRadios.forEach(r => r.addEventListener('change', () => {
          basisSep.style.display = modalEl.querySelector('input[name="schmutz-basis"]:checked').value === 'separater_zaehler' ? '' : 'none';
        }));

        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(null); resolve(false); });
        modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
          let payload;
          try { payload = collectWaterForm(modalEl); }
          catch (err) { toastErr(err.message); return; }
          try {
            if (isEdit) await api.updateContract(u.key, existing.id, payload);
            else        await api.createContract(u.key, payload);
            toastOk(isEdit ? 'Wasservertrag aktualisiert' : 'Wasservertrag angelegt');
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
        <div class="field"><label>Anbieter</label>
          <input class="input input--text" name="provider" value="${escapeHtml(c.provider || '')}"></div>
        <div class="field"><label>Tarifname</label>
          <input class="input input--text" name="tariff_name" value="${escapeHtml(c.tariff_name || '')}"></div>
      </div>
      <div class="form-row">
        <div class="field"><label>Beginn</label><input class="input" type="date" name="start" value="${escapeHtml(c.start || '')}" required></div>
        <div class="field"><label>Ende (leer = offen)</label><input class="input" type="date" name="end" value="${escapeHtml(c.end || '')}"></div>
        <div class="field"><label>Zähler</label><select class="select" name="meter_id" required>${meterOpts}</select></div>
      </div>
      <div class="field">
        <label>Notizen</label>
        <input class="input input--text" name="notes" value="${escapeHtml(c.notes || '')}">
      </div>

      <!-- Trinkwasser -->
      <details open style="margin-top:var(--sp-4);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">💧 Trinkwasser</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('tw-working', 'Arbeitspreis (ct/m³)', c.trinkwasser?.working_prices || [], ['from', 'ct_per_m3'], ['Datum', 'ct/m³'])}
          ${renderWaterEntryGroup('tw-base', 'Grundpreis (€/Monat)', c.trinkwasser?.base_prices || [], ['from', 'eur_per_month'], ['Datum', '€/Monat'])}
        </div>
      </details>

      <!-- Schmutzwasser -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">🚰 Schmutzwasser</summary>
        <div style="margin-top:12px">
          <div class="field">
            <label>Berechnungsgrundlage</label>
            <label style="display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:normal">
              <input type="radio" name="schmutz-basis" value="trinkwasser" ${basis === 'trinkwasser' ? 'checked' : ''}>
              <span>aus Trinkwasser-Verbrauch (Standard)</span>
            </label>
            <label style="display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:normal">
              <input type="radio" name="schmutz-basis" value="separater_zaehler" ${basis === 'separater_zaehler' ? 'checked' : ''}>
              <span>eigener Schmutzwasserzähler</span>
            </label>
          </div>
          <div class="field" id="schmutz-separater-row" style="${basis === 'separater_zaehler' ? '' : 'display:none'}">
            <label>Schmutzwasser-Zähler</label>
            <select class="select" name="schmutz_separater_zaehler_meter_id">${sepMeterOpts || '<option value="">(noch keine weiteren Zähler vorhanden)</option>'}</select>
          </div>
          ${renderWaterEntryGroup('sw-working', 'Arbeitspreis Schmutzwasser (ct/m³)', sw.working_prices || [], ['from', 'ct_per_m3'], ['Datum', 'ct/m³'])}
        </div>
      </details>

      <!-- Niederschlagswasser -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">☔ Niederschlagswasser</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('nw-rates', 'Stichtag', c.niederschlagswasser?.rates || [], ['from', 'eur_per_m2_year', 'versiegelte_flaeche_m2'], ['Datum', '€/m²/Jahr', 'm² versiegelt'])}
        </div>
      </details>

      <!-- Abschläge -->
      <details open style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">💰 Abschläge</summary>
        <div style="margin-top:12px">
          ${renderWaterEntryGroup('ap', 'Monatsabschlag (€)', c.advance_payments || [], ['from', 'amount_eur'], ['Datum', '€/Monat'])}
        </div>
      </details>

      <!-- Bonuses -->
      <details style="margin-top:var(--sp-3);border:1px solid var(--border-1);border-radius:var(--r-md);padding:12px 14px">
        <summary style="cursor:pointer;font-weight:600;color:var(--text-1)">🎁 Boni</summary>
        <div style="margin-top:12px">
          ${renderWaterBonusGroup(c.bonuses || [])}
        </div>
      </details>
    </form>
  `;
}

function renderWaterEntryGroup(groupKey, title, entries, fields, labels) {
  if (!entries.length) entries = [Object.fromEntries(fields.map(f => [f, '']))];
  const inputs = fields.map((f, i) => `<th>${escapeHtml(labels[i])}</th>`).join('');
  const rows = entries.map((e, idx) => `
    <tr data-row="${idx}">
      ${fields.map((f, i) => `
        <td>
          ${i === 0
            ? `<input class="input" type="date" data-field="${escapeHtml(f)}" value="${escapeHtml(e[f] ?? '')}">`
            : `<input class="input num" type="number" step="0.001" data-field="${escapeHtml(f)}" value="${escapeHtml(String(e[f] ?? ''))}">`}
        </td>
      `).join('')}
      <td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row">×</button></td>
    </tr>
  `).join('');
  return `
    <div class="field" data-group="${escapeHtml(groupKey)}" data-fields='${escapeHtml(JSON.stringify(fields))}'>
      <label>${escapeHtml(title)}</label>
      <table class="table table--compact" style="width:100%">
        <thead><tr>${inputs}<th></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <button type="button" class="btn btn--sm btn--ghost" data-act="add-row" style="margin-top:6px">+ Zeile</button>
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
      <td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row">×</button></td>
    </tr>
  `).join('');
  return `
    <div class="field" data-group="bonuses" data-fields='["credit_date","amount_eur","label"]'>
      <table class="table table--compact" style="width:100%">
        <thead><tr><th>Gutschrift</th><th>Betrag €</th><th>Bezeichnung</th><th></th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <button type="button" class="btn btn--sm btn--ghost" data-act="add-row" style="margin-top:6px">+ Zeile</button>
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
      tr.innerHTML = `${cells}<td><button type="button" class="btn btn--sm btn--ghost" data-act="del-row">×</button></td>`;
      tbody.appendChild(tr);
    }
  });
}

function collectWaterForm(modalEl) {
  const form = modalEl.querySelector('#water-contract-form');
  const fd = new FormData(form);
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
      working_prices: collect('tw-working').map(r => ({ from: r.from, ct_per_m3: parseFloat(r.ct_per_m3 || 0) })),
      base_prices:    collect('tw-base').map(r => ({ from: r.from, eur_per_month: parseFloat(r.eur_per_month || 0) })),
    },
    schmutzwasser: {
      basis: fd.get('schmutz-basis') || 'trinkwasser',
      separater_zaehler_meter_id: (fd.get('schmutz-basis') === 'separater_zaehler')
        ? (fd.get('schmutz_separater_zaehler_meter_id') || null)
        : null,
      working_prices: collect('sw-working').map(r => ({ from: r.from, ct_per_m3: parseFloat(r.ct_per_m3 || 0) })),
    },
    niederschlagswasser: {
      rates: collect('nw-rates').map(r => ({
        from: r.from,
        eur_per_m2_year: parseFloat(r.eur_per_m2_year || 0),
        versiegelte_flaeche_m2: parseFloat(r.versiegelte_flaeche_m2 || 0),
      })),
    },
    advance_payments: collect('ap').map(r => ({ from: r.from, amount_eur: parseFloat(r.amount_eur || 0) })),
    bonuses: collect('bonuses').map(r => ({
      credit_date: r.credit_date,
      amount_eur: parseFloat(r.amount_eur || 0),
      label: r.label || '',
      type: 'neukunde',
    })),
  };

  if (!payload.start) throw new Error('Vertragsbeginn ist Pflicht.');
  return payload;
}
