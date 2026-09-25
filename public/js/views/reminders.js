// =====================================================================
// Energietracker v1.3.0 — Termine / Wartungserinnerungen
// =====================================================================

import { api } from '../api.js';
import { toastOk, toastErr, toastUndo } from '../components/toast.js';
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { showFieldError } from '../lib/form.js';
import { t, tp } from '../lib/i18n.js';
import { escapeHtml as esc, todayIso, fmt } from '../lib/format.js';

// Labels werden zur Render-Zeit über t() aufgelöst.
const STATUS_CLS  = { ok: 'ok', due_soon: 'warning', due: 'warning', overdue: 'danger' };
const STATUS_RANK = { overdue: 0, due: 1, due_soon: 2, ok: 3 }; // B — Sortierreihenfolge
const CATEGORY_KEYS = ['heizung_wartung', 'schornsteinfeger', 'gaszaehler_eichung', 'stromzaehler_eichung', 'wasserzaehler_eichung', 'waermezaehler_eichung', 'dichtheitspruefung', 'lieferung_planen', 'custom'];
const RECURRENCE_KEYS = ['none', 'yearly', 'semi-yearly', 'custom-months'];

const catLabel = (k) => { const v = t('reminders.category.' + k); return v === 'reminders.category.' + k ? k : v; };
const recLabel = (k) => { const v = t('reminders.recurrence.' + k); return v === 'reminders.recurrence.' + k ? k : v; };

// v2.11.0 — Zahl an „Hinweise" nach jeder Änderung neu holen
const badgesChanged = () => window.dispatchEvent(new CustomEvent('et:badges-refresh'));

export async function render(container, _params, ctx = {}) {
  await draw(container);
  // Sprungziel des Erfassen-Blatts: #/reminders?add=1
  if (ctx.query?.get('add') === '1') {
    try { history.replaceState(history.state, '', '#/reminders'); } catch { /* egal */ }
    openForm(container, null);
  }
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
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${t('reminders.title')}</h1>
        <p class="view-header__subtitle">${t('reminders.subtitle')}</p>
      </div>
      <div class="view-header__actions">
        <button class="btn btn--primary" id="rem-add" data-rem-add>${t('reminders.add')}</button>
      </div>
    </div>

    ${sorted.length === 0
      ? `<div class="banner banner--info">${t('reminders.empty')}</div>
         <div style="margin-top:var(--sp-3)"><button class="btn btn--primary" data-rem-add>${t('reminders.emptyCta')}</button></div>`
      // v2.12.0 (Review UI-20) — die Tabelle in einer Karte wie auf den anderen Seiten
      : `<div class="card"><div class="table-wrap"><table class="data-table">
          <thead><tr>
            <th scope="col">${t('reminders.col.title')}</th><th scope="col">${t('reminders.col.category')}</th><th scope="col">${t('reminders.col.due')}</th>
            <th scope="col">${t('reminders.col.recurrence')}</th><th scope="col">${t('reminders.col.status')}</th><th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
          </tr></thead>
          <tbody>
            ${sorted.map(rowHtml).join('')}
          </tbody>
        </table></div></div>`}
  `;

  container.querySelectorAll('[data-rem-add]').forEach(b => b.addEventListener('click', () => openForm(container, null)));
  container.querySelectorAll('[data-edit]').forEach(b =>
    b.addEventListener('click', () => openForm(container, list.find(r => r.id === b.dataset.edit))));
  container.querySelectorAll('[data-done]').forEach(b =>
    b.addEventListener('click', async () => {
      const prev = list.find(r => r.id === b.dataset.done);
      try {
        const res = await api.reminderDone(b.dataset.done);
        // v2.12.0 (Review UI-22) — mit Namen, nächstem Termin und „Rückgängig"
        const title = prev?.title || '';
        const msg = res?.active === false
          ? t('reminders.toast.doneOnce', { title })
          : t('reminders.toast.doneNext', { title, date: fmt.date(res?.next_due) });
        toastUndo(msg, async () => {
          try {
            await api.updateReminder(prev.id, {
              next_due: prev.next_due, active: prev.active !== false, last_done: prev.last_done ?? null,
            });
            toastOk(t('reminders.toast.undone', { title }));
            badgesChanged();
            if (container.isConnected) await draw(container);
          } catch (e) { toastErr(t('reminders.toast.error', { msg: e.message || e })); }
        });
        badgesChanged();
        await draw(container);
      } catch (e) { toastErr(t('reminders.toast.error', { msg: e.message || e })); }
    }));
  container.querySelectorAll('[data-del]').forEach(b =>
    b.addEventListener('click', async () => {
      const title = list.find(r => r.id === b.dataset.del)?.title || '';
      const ok = await confirmModal({ title: t('reminders.deleteConfirm.title'), message: t('reminders.deleteConfirm.messageNamed', { title }), confirmLabel: t('reminders.deleteConfirm.confirm'), danger: true });
      if (!ok) return;
      try {
        await api.deleteReminder(b.dataset.del);
        toastOk(t('reminders.toast.deleted'));
        badgesChanged();
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
  // v2.7.0 — Datum in der Schreibweise von Sprache und Land (fmt.date escapt Unlesbares)
  const dueStr = fmt.date(r.next_due) + (dueLabel ? ` <span class="muted">(${dueLabel})</span>` : '');
  return `<tr>
    <td><strong>${esc(r.title)}</strong>${r.notes ? `<br><span class="muted small">${esc(r.notes)}</span>` : ''}</td>
    <td>${esc(catLabel(r.category))}</td>
    <td>${dueStr}</td>
    <td>${esc(r.recurrence === 'custom-months' && r.recurrence_months
      // v2.12.0 (Review UI-20) — „Alle 48 Monate" statt „Alle N Monate (48)"
      ? tp('reminders.everyMonths', r.recurrence_months) : recLabel(r.recurrence))}</td>
    <td><span class="badge badge--${STATUS_CLS[statusKey]}">${t('reminders.status.' + statusKey)}</span></td>
    <td class="cell-actions">
      <!-- v2.12.0 (Review UI-22) — Vorlesetext mit dem Namen des Termins -->
      <button class="btn btn--xs btn--ghost" data-done="${esc(r.id)}" title="${t('reminders.action.done')}" aria-label="${esc(t('reminders.action.doneNamed', { title: r.title }))}"><span aria-hidden="true">✓</span></button>
      <button class="btn btn--xs btn--ghost" data-edit="${esc(r.id)}" title="${t('reminders.action.edit')}" aria-label="${esc(t('reminders.action.editNamed', { title: r.title }))}"><span aria-hidden="true">✎</span></button>
      <button class="btn btn--xs btn--ghost" data-del="${esc(r.id)}" title="${t('reminders.action.delete')}" aria-label="${esc(t('reminders.action.deleteNamed', { title: r.title }))}"><span aria-hidden="true">🗑</span></button>
    </td>
  </tr>`;
}

function openForm(container, existing) {
  const r = existing || { title: '', category: 'heizung_wartung', next_due: todayIso(), recurrence: 'yearly', recurrence_months: 12, notes: '' };
  const catOpts = CATEGORY_KEYS.map(k =>
    `<option value="${k}" ${r.category === k ? 'selected' : ''}>${esc(catLabel(k))}</option>`).join('');
  const recOpts = RECURRENCE_KEYS.map(k =>
    `<option value="${k}" ${r.recurrence === k ? 'selected' : ''}>${esc(recLabel(k))}</option>`).join('');

  const ctrl = openModal({
    title: existing ? t('reminders.form.titleEdit') : t('reminders.form.titleNew'),
    body: `
      <div class="form-grid">
        <!-- v2.12.0 (Review UI-22) — Fehler am Feld statt im Toast -->
        <label>${t('reminders.form.fTitle')}<input type="text" id="f-title" value="${esc(r.title)}" placeholder="${t('reminders.form.fTitlePlaceholder')}" aria-describedby="f-title-msg"></label>
        <div class="field-error" id="f-title-msg" role="alert" hidden></div>
        <label>${t('reminders.form.fCategory')}<select id="f-cat">${catOpts}</select></label>
        <label>${t('reminders.form.fDue')}<input type="date" id="f-due" value="${esc(r.next_due)}" aria-describedby="f-due-msg"></label>
        <div class="field-error" id="f-due-msg" role="alert" hidden></div>
        <label>${t('reminders.form.fRecurrence')}<select id="f-rec">${recOpts}</select></label>
        <label id="f-rm-wrap" style="${r.recurrence === 'custom-months' ? '' : 'display:none'}">
          ${t('reminders.form.fInterval')}<input type="text" inputmode="numeric" autocomplete="off" id="f-rm" value="${esc(String(r.recurrence_months || 12))}" aria-describedby="f-rm-msg">
        </label>
        <div class="field-error" id="f-rm-msg" role="alert" hidden></div>
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
      const saveBtn = modalEl.querySelector('[data-act="save"]');
      saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
        const custom = bodyEl.querySelector('#f-rec').value === 'custom-months';
        // v2.5.3 — Intervall als geprüfte Ganzzahl: parseInt('') ergab NaN,
        // das als null beim Backend ankam.
        const rmEl = bodyEl.querySelector('#f-rm');
        const rm = /^\d{1,3}$/.test(rmEl.value.trim()) ? Number(rmEl.value.trim()) : null;
        const rmOk = !custom || (rm !== null && rm >= 1 && rm <= 120);
        const payload = {
          title: bodyEl.querySelector('#f-title').value.trim(),
          category: bodyEl.querySelector('#f-cat').value,
          next_due: bodyEl.querySelector('#f-due').value,
          recurrence: bodyEl.querySelector('#f-rec').value,
          recurrence_months: custom ? rm : null,
          notes: bodyEl.querySelector('#f-notes').value.trim(),
        };
        const titleEl = bodyEl.querySelector('#f-title'), dueEl = bodyEl.querySelector('#f-due');
        showFieldError(titleEl, bodyEl.querySelector('#f-title-msg'), null);
        showFieldError(dueEl, bodyEl.querySelector('#f-due-msg'), null);
        showFieldError(rmEl, bodyEl.querySelector('#f-rm-msg'), null);
        if (!payload.title) { showFieldError(titleEl, bodyEl.querySelector('#f-title-msg'), t('reminders.form.titleRequired')); return; }
        if (!payload.next_due) { showFieldError(dueEl, bodyEl.querySelector('#f-due-msg'), t('reminders.form.dueRequired')); return; }
        if (!rmOk) { showFieldError(rmEl, bodyEl.querySelector('#f-rm-msg'), t('reminders.form.intervalInvalid')); return; }
        try {
          if (existing) await api.updateReminder(existing.id, payload);
          else await api.createReminder(payload);
          toastOk(t('reminders.toast.saved'));
          close(null);
          badgesChanged();
          await draw(container);
        } catch (e) { toastErr(t('reminders.toast.error', { msg: e.message || e })); }
      }));
    },
  });
  return ctrl;
}


