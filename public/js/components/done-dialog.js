// =====================================================================
// v3.4.0 (F1024) — Hinweis abhaken: kleiner Dialog mit Datum (rückdatierbar),
// Notiz und Beleg; bei der Kündigung das Kästchen „gekündigt, verlängert sich
// nicht". Danach ein Hinweis mit „Rückgängig" (bestehendes Muster). Wer es
// war, trägt der Server ein (Person der Anmeldung).
// =====================================================================

import { api } from '../api.js';
import { openModal, guardSubmit } from './modal.js';
import { toastUndo, toastOk, toastErr } from './toast.js';
import { showFieldError } from '../lib/form.js';
import { t } from '../lib/i18n.js';
import { escapeHtml as esc, todayIso } from '../lib/format.js';

/**
 * Dialog öffnen. `submit(values)` speichert ({done_on, note, attachment_id,
 * cancelled}) und liefert die Antwort des Servers.
 *
 * @returns {Promise<object|null>} Antwort von `submit`, null bei Abbruch
 */
export function openDoneDialog({ title, cancelOption = false, hint = '', submit }) {
  return new Promise(resolve => {
    const today = todayIso();
    const ctrl = openModal({
      title: t('activity.dialog.title'),
      body: `
        <p class="done-dialog__what"><strong>${esc(title)}</strong></p>
        ${hint ? `<p class="muted small" data-role="done-hint">${esc(hint)}</p>` : ''}
        <div class="fgrid">
          <div class="field field--s">
            <label for="dd-date">${esc(t('activity.dialog.date'))}</label>
            <input class="input" type="date" id="dd-date" value="${today}" max="${today}" aria-describedby="dd-date-msg">
            <div class="field-error" id="dd-date-msg" role="alert" hidden></div>
          </div>
          <div class="field field--l">
            <label for="dd-note">${esc(t('activity.dialog.note'))}</label>
            <input class="input" type="text" id="dd-note" maxlength="500" autocomplete="off">
          </div>
          <div class="field field--full">
            <label for="dd-file">${esc(t('activity.dialog.attachment'))}</label>
            <input class="input" type="file" id="dd-file" accept="image/*,application/pdf">
          </div>
          ${cancelOption ? `<div class="field field--full"><label class="check"><input type="checkbox" id="dd-cancelled"> ${esc(t('activity.dialog.cancelled'))}</label></div>` : ''}
        </div>`,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
        <button type="button" class="btn btn--primary" data-act="save">${esc(t('activity.dialog.save'))}</button>`,
      onMount: ({ bodyEl, modalEl, close }) => {
        // Abbrechen zuerst binden (Lektion 8)
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
        const save = modalEl.querySelector('[data-act="save"]');
        save?.addEventListener('click', guardSubmit(save, async () => {
          const dateEl = bodyEl.querySelector('#dd-date');
          const msg = bodyEl.querySelector('#dd-date-msg');
          showFieldError(dateEl, msg, null);
          const date = dateEl?.value || '';
          if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date > today) {
            showFieldError(dateEl, msg, t('activity.dialog.dateInvalid'));
            return;
          }
          try {
            const file = bodyEl.querySelector('#dd-file')?.files?.[0];
            const attachmentId = file ? ((await api.uploadAttachment(file, 'other', file.name))?.id ?? null) : null;
            const res = await submit({
              done_on: date,
              note: bodyEl.querySelector('#dd-note')?.value.trim() || '',
              attachment_id: attachmentId,
              cancelled: !!bodyEl.querySelector('#dd-cancelled')?.checked,
            });
            close(true);
            resolve(res ?? {});
          } catch (e) {
            toastErr(t('activity.toast.error', { msg: e.message || e }));
          }
        }));
      },
    });
    ctrl.closedPromise.then(v => { if (v !== true) resolve(null); });
  });
}

/**
 * Ein Agenda-Ereignis abhaken (POST /api/agenda/done). `onChange` läuft nach
 * dem Abhaken und nach „Rückgängig".
 *
 * @returns {Promise<object|null>} Antwort des Servers, null bei Abbruch
 */
export async function markEventDone(ev, { title, onChange } = {}) {
  const label = title || ev.title || '';
  const res = await openDoneDialog({
    title: label,
    cancelOption: ev.kind === 'cancel_by',
    // v3.5.0 (F1029) — abhaken heißt hier: Die Rechnung kam, wird aber nicht erfasst
    hint: ev.kind === 'bill_expected' ? t('activity.dialog.billWithout') : '',
    submit: (v) => api.agendaDone({ uid: ev.uid, occurrence: ev.occurrence, done_on: v.done_on, note: v.note,
      attachment_id: v.attachment_id, cancelled: v.cancelled }),
  });
  if (!res) return null;
  onChange?.();
  if (res.activity_id) {
    toastUndo(t('activity.toast.done', { title: label }), async () => {
      try {
        await api.undoActivity(res.activity_id);
        toastOk(t('activity.toast.undone', { title: label }));
        onChange?.();
      } catch (e) { toastErr(t('activity.toast.error', { msg: e.message || e })); }
    });
  }
  return res;
}
