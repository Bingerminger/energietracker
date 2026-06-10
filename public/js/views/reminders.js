// =====================================================================
// Energietracker v1.3.0 — Termine / Wartungserinnerungen
// =====================================================================

import { api } from '../api.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal } from '../components/modal.js';
import { t } from '../lib/i18n.js';

// Labels werden zur Render-Zeit über t() aufgelöst.
const STATUS_CLS  = { ok: 'ok', due_soon: 'warning', due: 'warning', overdue: 'danger' };
const STATUS_RANK = { overdue: 0, due: 1, due_soon: 2, ok: 3 }; // B — Sortierreihenfolge
const CATEGORY_KEYS = ['heizung_wartung', 'schornsteinfeger', 'gaszaehler_eichung', 'stromzaehler_eichung', 'wasserzaehler_eichung', 'dichtheitspruefung', 'lieferung_planen', 'custom'];
const RECURRENCE_KEYS = ['none', 'yearly', 'semi-yearly', 'custom-months'];

const catLabel = (k) => { const v = t('reminders.category.' + k); return v === 'reminders.category.' + k ? k : v; };
const recLabel = (k) => { const v = t('reminders.recurrence.' + k); return v === 'reminders.recurrence.' + k ? k : v; };

export async function render(container) {
  await draw(container);
}

async function draw(container) {
  container.innerHTML = `<div class="loading">${t('reminders.loading')}</div>`;
  let list;
  try {
    list = await api.reminders();
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${t('reminders.loadError', { msg: esc(e.message || e) })}</div>`;
    return;
  }

  // B — Sortierung: überfällige/fällige zuerst, dann nach Datum aufsteigend.
  const sorted = [...list].sort((a, b) => {
    const ra = STATUS_RANK[a.status] ?? 3, rb = STATUS_RANK[b.status] ?? 3;
    if (ra !== rb) return ra - rb;
    return String(a.next_due || '').localeCompare(String(b.next_due || ''));
  });

  container.innerHTML = `
    <div class="view-head view-head--row">
      <div>
        <h1>${t('reminders.title')}</h1>
        <p class="muted">${t('reminders.subtitle')}</p>
      </div>
      <button class="btn btn--primary" id="rem-add" data-rem-add>${t('reminders.add')}</button>
    </div>

    ${sorted.length === 0
      ? `<div class="banner banner--info">${t('reminders.empty')}</div>
         <div style="margin-top:var(--sp-3)"><button class="btn btn--primary" data-rem-add>${t('reminders.emptyCta')}</button></div>`
      : `<table class="data-table">
          <thead><tr>
            <th scope="col">${t('reminders.col.title')}</th><th scope="col">${t('reminders.col.category')}</th><th scope="col">${t('reminders.col.due')}</th>
            <th scope="col">${t('reminders.col.recurrence')}</th><th scope="col">${t('reminders.col.status')}</th><th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
          </tr></thead>
          <tbody>
            ${sorted.map(rowHtml).join('')}
          </tbody>
        </table>`}
  `;

  container.querySelectorAll('[data-rem-add]').forEach(b => b.addEventListener('click', () => openForm(container, null)));
  container.querySelectorAll('[data-edit]').forEach(b =>
    b.addEventListener('click', () => openForm(container, list.find(r => r.id === b.dataset.edit))));
  container.querySelectorAll('[data-done]').forEach(b =>
    b.addEventListener('click', async () => {
      try {
        await api.reminderDone(b.dataset.done);
        toastOk(t('reminders.toast.markedDone'));
        await draw(container);
      } catch (e) { toastErr(t('reminders.toast.error', { msg: e.message || e })); }
    }));
  container.querySelectorAll('[data-del]').forEach(b =>
    b.addEventListener('click', async () => {
      const ok = await confirmModal({ title: t('reminders.deleteConfirm.title'), message: t('reminders.deleteConfirm.message'), confirmLabel: t('reminders.deleteConfirm.confirm'), danger: true });
      if (!ok) return;
      try {
        await api.deleteReminder(b.dataset.del);
        toastOk(t('reminders.toast.deleted'));
        await draw(container);
      } catch (e) { toastErr(t('reminders.toast.error', { msg: e.message || e })); }
    }));
}

function rowHtml(r) {
  const statusKey = STATUS_CLS[r.status] ? r.status : 'ok';
  const days = r.days_until;
  const dueLabel = days == null ? ''
    : days === 0 ? t('reminders.due.today')
    : days > 0 ? t('reminders.due.inDays', { days })
    : t('reminders.due.agoDays', { days: -days });
  const dueStr = esc(r.next_due) + (dueLabel ? ` <span class="muted">(${dueLabel})</span>` : '');
  return `<tr>
    <td><strong>${esc(r.title)}</strong>${r.notes ? `<br><span class="muted small">${esc(r.notes)}</span>` : ''}</td>
    <td>${esc(catLabel(r.category))}</td>
    <td>${dueStr}</td>
    <td>${esc(recLabel(r.recurrence))}${r.recurrence === 'custom-months' && r.recurrence_months ? ` (${r.recurrence_months})` : ''}</td>
    <td><span class="badge badge--${STATUS_CLS[statusKey]}">${t('reminders.status.' + statusKey)}</span></td>
    <td class="cell-actions">
      <button class="btn btn--xs btn--ghost" data-done="${r.id}" title="${t('reminders.action.done')}" aria-label="${t('reminders.action.done')}"><span aria-hidden="true">✓</span></button>
      <button class="btn btn--xs btn--ghost" data-edit="${r.id}" title="${t('reminders.action.edit')}" aria-label="${t('reminders.action.edit')}"><span aria-hidden="true">✎</span></button>
      <button class="btn btn--xs btn--ghost" data-del="${r.id}" title="${t('reminders.action.delete')}" aria-label="${t('reminders.action.delete')}"><span aria-hidden="true">🗑</span></button>
    </td>
  </tr>`;
}

function openForm(container, existing) {
  const r = existing || { title: '', category: 'heizung_wartung', next_due: today(), recurrence: 'yearly', recurrence_months: 12, notes: '' };
  const catOpts = CATEGORY_KEYS.map(k =>
    `<option value="${k}" ${r.category === k ? 'selected' : ''}>${esc(catLabel(k))}</option>`).join('');
  const recOpts = RECURRENCE_KEYS.map(k =>
    `<option value="${k}" ${r.recurrence === k ? 'selected' : ''}>${esc(recLabel(k))}</option>`).join('');

  const ctrl = openModal({
    title: existing ? t('reminders.form.titleEdit') : t('reminders.form.titleNew'),
    body: `
      <div class="form-grid">
        <label>${t('reminders.form.fTitle')}<input type="text" id="f-title" value="${esc(r.title)}" placeholder="${t('reminders.form.fTitlePlaceholder')}"></label>
        <label>${t('reminders.form.fCategory')}<select id="f-cat">${catOpts}</select></label>
        <label>${t('reminders.form.fDue')}<input type="date" id="f-due" value="${esc(r.next_due)}"></label>
        <label>${t('reminders.form.fRecurrence')}<select id="f-rec">${recOpts}</select></label>
        <label id="f-rm-wrap" style="${r.recurrence === 'custom-months' ? '' : 'display:none'}">
          ${t('reminders.form.fInterval')}<input type="number" id="f-rm" min="1" value="${r.recurrence_months || 12}">
        </label>
        <label>${t('reminders.form.fNotes')}<input type="text" id="f-notes" value="${esc(r.notes || '')}"></label>
      </div>`,
    footer: `
      <button class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button class="btn btn--primary" data-act="save">${t('reminders.form.save')}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      bodyEl.querySelector('#f-rec').addEventListener('change', e => {
        bodyEl.querySelector('#f-rm-wrap').style.display = e.target.value === 'custom-months' ? '' : 'none';
      });
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
        const payload = {
          title: bodyEl.querySelector('#f-title').value.trim(),
          category: bodyEl.querySelector('#f-cat').value,
          next_due: bodyEl.querySelector('#f-due').value,
          recurrence: bodyEl.querySelector('#f-rec').value,
          recurrence_months: bodyEl.querySelector('#f-rec').value === 'custom-months'
            ? parseInt(bodyEl.querySelector('#f-rm').value, 10) : null,
          notes: bodyEl.querySelector('#f-notes').value.trim(),
        };
        if (!payload.title || !payload.next_due) { toastErr(t('reminders.form.validation')); return; }
        try {
          if (existing) await api.updateReminder(existing.id, payload);
          else await api.createReminder(payload);
          toastOk(t('reminders.toast.saved'));
          close(null);
          await draw(container);
        } catch (e) { toastErr(t('reminders.toast.error', { msg: e.message || e })); }
      });
    },
  });
  return ctrl;
}

function today() { return new Date().toISOString().slice(0, 10); }
function esc(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
