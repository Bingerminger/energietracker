// =====================================================================
// Energietracker v1.3.0 — Termine / Wartungserinnerungen
// =====================================================================

import { api } from '../api.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal } from '../components/modal.js';

const STATUS = {
  ok:       { label: 'OK',        cls: 'ok'      },
  due_soon: { label: 'Bald fällig', cls: 'warning' },
  due:      { label: 'Fällig',    cls: 'warning' },
  overdue:  { label: 'Überfällig', cls: 'danger'  },
};
const CATEGORIES = {
  heizung_wartung:       'Heizungswartung',
  schornsteinfeger:      'Schornsteinfeger',
  gaszaehler_eichung:    'Gaszähler-Eichung',
  stromzaehler_eichung:  'Stromzähler-Eichung',
  wasserzaehler_eichung: 'Wasserzähler-Eichung',
  dichtheitspruefung:    'Dichtheitsprüfung',
  lieferung_planen:      'Lieferung planen',
  custom:                'Sonstiges',
};
const RECURRENCE = {
  none: 'Einmalig', yearly: 'Jährlich',
  'semi-yearly': 'Halbjährlich', 'custom-months': 'Alle N Monate',
};

export async function render(container) {
  await draw(container);
}

async function draw(container) {
  container.innerHTML = '<div class="loading">Lade Termine…</div>';
  let list;
  try {
    list = await api.reminders();
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">Konnte Termine nicht laden: ${esc(e.message || e)}</div>`;
    return;
  }

  container.innerHTML = `
    <div class="view-head view-head--row">
      <div>
        <h2>Termine &amp; Wartung</h2>
        <p class="muted">Wiederkehrende Wartungen, Eichfristen und Liefer-Erinnerungen.</p>
      </div>
      <button class="btn btn--primary" id="rem-add">+ Termin</button>
    </div>

    ${list.length === 0
      ? `<div class="banner banner--info">Noch keine Termine angelegt.</div>`
      : `<table class="data-table">
          <thead><tr>
            <th>Titel</th><th>Kategorie</th><th>Fällig</th>
            <th>Wiederholung</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>
            ${list.map(rowHtml).join('')}
          </tbody>
        </table>`}
  `;

  container.querySelector('#rem-add')?.addEventListener('click', () => openForm(container, null));
  container.querySelectorAll('[data-edit]').forEach(b =>
    b.addEventListener('click', () => openForm(container, list.find(r => r.id === b.dataset.edit))));
  container.querySelectorAll('[data-done]').forEach(b =>
    b.addEventListener('click', async () => {
      try {
        await api.reminderDone(b.dataset.done);
        toastOk('Als erledigt markiert');
        await draw(container);
      } catch (e) { toastErr('Fehler: ' + (e.message || e)); }
    }));
  container.querySelectorAll('[data-del]').forEach(b =>
    b.addEventListener('click', async () => {
      const ok = await confirmModal({ title: 'Termin löschen', message: 'Diesen Termin wirklich löschen?', confirmLabel: 'Löschen', danger: true });
      if (!ok) return;
      try {
        await api.deleteReminder(b.dataset.del);
        toastOk('Gelöscht');
        await draw(container);
      } catch (e) { toastErr('Fehler: ' + (e.message || e)); }
    }));
}

function rowHtml(r) {
  const st = STATUS[r.status] || STATUS.ok;
  const days = r.days_until;
  const dueStr = r.next_due + (days != null
    ? ` <span class="muted">(${days === 0 ? 'heute' : days > 0 ? `in ${days} T` : `vor ${-days} T`})</span>`
    : '');
  return `<tr>
    <td><strong>${esc(r.title)}</strong>${r.notes ? `<br><span class="muted small">${esc(r.notes)}</span>` : ''}</td>
    <td>${CATEGORIES[r.category] || r.category}</td>
    <td>${dueStr}</td>
    <td>${RECURRENCE[r.recurrence] || r.recurrence}${r.recurrence === 'custom-months' && r.recurrence_months ? ` (${r.recurrence_months})` : ''}</td>
    <td><span class="badge badge--${st.cls}">${st.label}</span></td>
    <td class="cell-actions">
      <button class="btn btn--xs btn--ghost" data-done="${r.id}" title="Erledigt">✓</button>
      <button class="btn btn--xs btn--ghost" data-edit="${r.id}" title="Bearbeiten">✎</button>
      <button class="btn btn--xs btn--ghost" data-del="${r.id}" title="Löschen">🗑</button>
    </td>
  </tr>`;
}

function openForm(container, existing) {
  const r = existing || { title: '', category: 'heizung_wartung', next_due: today(), recurrence: 'yearly', recurrence_months: 12, notes: '' };
  const catOpts = Object.entries(CATEGORIES).map(([k, v]) =>
    `<option value="${k}" ${r.category === k ? 'selected' : ''}>${v}</option>`).join('');
  const recOpts = Object.entries(RECURRENCE).map(([k, v]) =>
    `<option value="${k}" ${r.recurrence === k ? 'selected' : ''}>${v}</option>`).join('');

  const ctrl = openModal({
    title: existing ? 'Termin bearbeiten' : 'Neuer Termin',
    body: `
      <div class="form-grid">
        <label>Titel<input type="text" id="f-title" value="${esc(r.title)}" placeholder="z. B. Heizung warten"></label>
        <label>Kategorie<select id="f-cat">${catOpts}</select></label>
        <label>Fällig am<input type="date" id="f-due" value="${esc(r.next_due)}"></label>
        <label>Wiederholung<select id="f-rec">${recOpts}</select></label>
        <label id="f-rm-wrap" style="${r.recurrence === 'custom-months' ? '' : 'display:none'}">
          Intervall (Monate)<input type="number" id="f-rm" min="1" value="${r.recurrence_months || 12}">
        </label>
        <label>Notizen<input type="text" id="f-notes" value="${esc(r.notes || '')}"></label>
      </div>`,
    footer: `
      <button class="btn btn--ghost" data-act="cancel">Abbrechen</button>
      <button class="btn btn--primary" data-act="save">Speichern</button>`,
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
        if (!payload.title || !payload.next_due) { toastErr('Titel und Datum sind Pflicht'); return; }
        try {
          if (existing) await api.updateReminder(existing.id, payload);
          else await api.createReminder(payload);
          toastOk('Gespeichert');
          close(null);
          await draw(container);
        } catch (e) { toastErr('Fehler: ' + (e.message || e)); }
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
