// =====================================================================
// Energietracker v1.3.0 — Termine / Wartungserinnerungen
// =====================================================================

import { api } from '../api.js';
import { toastOk, toastErr, toastUndo } from '../components/toast.js';
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { showFieldError } from '../lib/form.js';
import { t, tp } from '../lib/i18n.js';
import { escapeHtml as esc, todayIso, fmt } from '../lib/format.js';
import { copyText } from '../lib/clipboard.js';
import { ingressHost, haUrl } from '../lib/ha-snippet.js';
// v3.4.0 (F1024) — Erledigt mit Verlauf
import { openDoneDialog } from '../components/done-dialog.js';
import { whenWho } from '../lib/activity.js';
// v3.5.0 (F1028) — Zuständig: Chip, Filter, Auswahl im Dialog
import { ownAssigneeOf, assigneeNames, anyAssigned, chipHtml, filterHtml, bindFilter, filterNoteHtml,
  matchesAssignee, storedFilter, normName, displayName } from '../lib/assignee.js';
// v3.7.0 — Termin am Zähler (Eichfrist, Zählerwechsel, Lieferung): Zähler zur Wahl
import { activeUtilities } from '../state.js';

// Labels werden zur Render-Zeit über t() aufgelöst.
const STATUS_CLS  = { ok: 'ok', due_soon: 'warning', due: 'warning', overdue: 'danger' };
const STATUS_RANK = { overdue: 0, due: 1, due_soon: 2, ok: 3 }; // B — Sortierreihenfolge
// v3.7.0 (Paket betrieb) — `zaehlerstand_melden`: Zählerstandsanforderung des Versorgers (mit Zähler: Direktlink in die Erfassung)
const CATEGORY_KEYS = ['heizung_wartung', 'schornsteinfeger', 'gaszaehler_eichung', 'stromzaehler_eichung', 'wasserzaehler_eichung', 'waermezaehler_eichung', 'dichtheitspruefung', 'lieferung_planen', 'zaehlerstand_melden', 'custom'];
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

// v3.5.0 (F1028) — Personen (Anmeldung) und vergebene Namen für den Dialog;
// v3.7.0 — Zähler je Verbrauchsart für das Feld „Zähler“
const known = { people: [], names: [], meters: [] };

async function draw(container) {
  container.innerHTML = `<div class="loading">${t('reminders.loading')}</div>`;
  let list, people, meters;
  try {
    // v3.6.0 — Personen aus dem Personenverzeichnis (auch ohne Anmeldung, etwa im Beispielhaushalt)
    [list, people, meters] = await Promise.all([api.reminders(), api.people().catch(() => []), loadMeters()]);
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${t('reminders.loadError', { msg: esc(e.message || e) })}</div>`;
    return;
  }
  known.people = Array.isArray(people) ? people : [];
  known.meters = Array.isArray(meters) ? meters : [];
  known.names = assigneeNames(list, known.people.map(p => p.name));

  // B — Sortierung: überfällige/fällige zuerst, dann nach Datum aufsteigend.
  const sorted = [...list].sort((a, b) => {
    const ra = STATUS_RANK[a.status] ?? 3, rb = STATUS_RANK[b.status] ?? 3;
    if (ra !== rb) return ra - rb;
    return String(a.next_due || '').localeCompare(String(b.next_due || ''));
  });
  // v3.5.0 (F1028) — Filter nach Zuständigkeit, je Gerät gemerkt; ohne
  // Zuständigkeiten (und ohne gemerkte Auswahl) bleibt er weg
  const filter = storedFilter();
  const withFilter = anyAssigned(list) || filter !== 'all';
  const shown = withFilter ? sorted.filter(r => matchesAssignee(r, filter)) : sorted;

  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${t('reminders.title')}</h1>
        <p class="view-header__subtitle">${t('reminders.subtitle')}</p>
      </div>
      <div class="view-header__actions">
        <button class="btn btn--ghost" id="rem-subscribe"><span aria-hidden="true">📅</span> ${t('calendar.subscribe')}</button>
        <button class="btn btn--primary" id="rem-add" data-rem-add>${t('reminders.add')}</button>
      </div>
    </div>

    ${sorted.length === 0
      ? `<div class="banner banner--info">${t('reminders.empty')}</div>
         <div style="margin-top:var(--sp-3)"><button class="btn btn--primary" data-rem-add>${t('reminders.emptyCta')}</button></div>`
      // v2.12.0 (Review UI-20) — die Tabelle in einer Karte wie auf den anderen Seiten
      : `${withFilter ? filterHtml({ id: 'rem-assignee', value: filter, names: assigneeNames(list) }) : ''}
        ${shown.length ? `<div class="card"><div class="table-wrap"><table class="data-table">
          <thead><tr>
            <th scope="col">${t('reminders.col.title')}</th><th scope="col">${t('reminders.col.category')}</th><th scope="col">${t('reminders.col.due')}</th>
            <th scope="col">${t('reminders.col.recurrence')}</th><th scope="col">${t('reminders.col.status')}</th><th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
          </tr></thead>
          <tbody>
            ${shown.map(rowHtml).join('')}
          </tbody>
        </table></div></div>` : `<div class="banner banner--info" data-role="assignee-empty">${esc(t('assignee.emptyFiltered'))}</div>`}
        ${withFilter ? filterNoteHtml(sorted.length - shown.length, filter) : ''}`}
  `;

  bindFilter(container, () => draw(container));   // v3.5.0 (F1028)
  container.querySelectorAll('[data-rem-add]').forEach(b => b.addEventListener('click', () => openForm(container, null)));
  container.querySelector('#rem-subscribe')?.addEventListener('click', () => openCalendarSubscription());
  container.querySelectorAll('[data-edit]').forEach(b =>
    b.addEventListener('click', () => openForm(container, list.find(r => r.id === b.dataset.edit))));
  container.querySelectorAll('[data-done]').forEach(b =>
    b.addEventListener('click', async () => {
      const prev = list.find(r => r.id === b.dataset.done);
      const title = prev?.title || '';
      // v3.4.0 (F1024) — kleiner Dialog: Datum (rückdatierbar), Notiz, Beleg; ein Eintrag im Verlauf
      const res = await openDoneDialog({ title, submit: (v) => api.reminderDone(prev.id, v.done_on,
        { note: v.note, attachmentId: v.attachment_id }) });
      if (!res) return;
      // v2.12.0 (Review UI-22) — mit Namen, nächstem Termin und „Rückgängig"
      const msg = res?.active === false
        ? t('reminders.toast.doneOnce', { title })
        : t('reminders.toast.doneNext', { title, date: fmt.date(res?.next_due) });
      toastUndo(msg, async () => {
        try {
          // v3.4.0 (F1024) — Rückgängig über den Verlauf: stellt den Termin zurück und streicht den Eintrag durch
          if (res.activity_id) await api.undoActivity(res.activity_id);
          else await api.updateReminder(prev.id, {
            next_due: prev.next_due, active: prev.active !== false, last_done: prev.last_done ?? null,
          });
          toastOk(t('reminders.toast.undone', { title }));
          badgesChanged();
          if (container.isConnected) await draw(container);
        } catch (e) { toastErr(t('reminders.toast.error', { msg: e.message || e })); }
      });
      badgesChanged();
      await draw(container);
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

/** v3.7.0 (Paket betrieb) — „Zählerstand melden“ am Zähler: direkt in dessen Erfassung. */
export function readingLinkHtml(r) {
  if (r?.category !== 'zaehlerstand_melden' || !r.meter_id) return '';
  return `<a class="btn btn--xs btn--primary" data-reading-link href="#/zaehlerstaende?meter=${esc(encodeURIComponent(r.meter_id))}">${esc(t('reminders.action.enterReading'))}</a>`;
}

function rowHtml(r) {
  const statusKey = STATUS_CLS[r.status] ? r.status : 'ok';
  const days = r.days_until;
  const dueLabel = days == null ? ''
    : days === 0 ? t('reminders.due.today')
    : days > 0 ? tp('reminders.due.inDays', days, { days })
    : tp('reminders.due.agoDays', -days, { days: -days });
  // v2.7.0 — Datum in der Schreibweise von Sprache und Land (fmt.date escapt Unlesbares)
  const dueStr = fmt.date(r.next_due) + (dueLabel ? ` <span class="muted">(${dueLabel})</span>` : '');
  // v3.4.0 (F1024) — „zuletzt erledigt am … von …" (Person aus dem Verlauf)
  const last = r.last_done ? `<br><span class="muted small">${esc(t('reminders.lastDone', { when: whenWho(r.last_done, r.last_done_by) }))}</span>` : '';
  return `<tr>
    <td><strong>${esc(r.title)}</strong>${chipHtml(r)}${r.notes ? `<br><span class="muted small">${esc(r.notes)}</span>` : ''}${last}</td>
    <td>${esc(catLabel(r.category))}</td>
    <td>${dueStr}</td>
    <td>${esc(r.recurrence === 'custom-months' && r.recurrence_months
      // v2.12.0 (Review UI-20) — „Alle 48 Monate" statt „Alle N Monate (48)"
      ? tp('reminders.everyMonths', r.recurrence_months) : recLabel(r.recurrence))}</td>
    <td><span class="badge badge--${STATUS_CLS[statusKey]}">${t('reminders.status.' + statusKey)}</span></td>
    <td class="cell-actions">
      ${readingLinkHtml(r)}
      <!-- v2.12.0 (Review UI-22) — Vorlesetext mit dem Namen des Termins -->
      <button class="btn btn--xs btn--ghost" data-done="${esc(r.id)}" title="${t('reminders.action.done')}" aria-label="${esc(t('reminders.action.doneNamed', { title: r.title }))}"><span aria-hidden="true">✓</span></button>
      <button class="btn btn--xs btn--ghost" data-edit="${esc(r.id)}" title="${t('reminders.action.edit')}" aria-label="${esc(t('reminders.action.editNamed', { title: r.title }))}"><span aria-hidden="true">✎</span></button>
      <button class="btn btn--xs btn--ghost" data-del="${esc(r.id)}" title="${t('reminders.action.delete')}" aria-label="${esc(t('reminders.action.deleteNamed', { title: r.title }))}"><span aria-hidden="true">🗑</span></button>
    </td>
  </tr>`;
}

/** v3.7.0 — Zähler der aktiven Verbrauchsarten, je Art (mit Zuständigkeit aus GET …/meters). */
async function loadMeters() {
  try {
    const us = await activeUtilities();
    return await Promise.all((us || []).map(async u => ({ u, meters: await api.meters(u.key).catch(() => []) })));
  } catch { return []; }
}

/** v3.7.0 — Kategorie → Verbrauchsarten, deren einziger Zähler beim Anlegen vorgewählt wird. */
const CATEGORY_UTILITIES = {
  gaszaehler_eichung: ['gas'], stromzaehler_eichung: ['strom'], wasserzaehler_eichung: ['wasser'],
  waermezaehler_eichung: ['fernwaerme', 'waerme'], lieferung_planen: ['heizoel', 'pellets'],
};

function openForm(container, existing) {
  const r = existing || { title: '', category: 'heizung_wartung', next_due: todayIso(), recurrence: 'yearly', recurrence_months: 12, notes: '' };
  const catOpts = CATEGORY_KEYS.map(k =>
    `<option value="${k}" ${r.category === k ? 'selected' : ''}>${esc(catLabel(k))}</option>`).join('');
  const recOpts = RECURRENCE_KEYS.map(k =>
    `<option value="${k}" ${r.recurrence === k ? 'selected' : ''}>${esc(recLabel(k))}</option>`).join('');
  // v3.7.0 — Zähler zur Wahl (mit der Liste geladen): Ohne eigene Zuständigkeit gilt die Person des Zählers
  const meterGroups = known.meters;
  const meterByLink = new Map(meterGroups.flatMap(g => g.meters.map(m => [`${g.u.key}|${m.id}`, m])));
  const curLink = r.utility && r.meter_id ? `${r.utility}|${r.meter_id}` : '';
  const meterOpts = meterGroups.filter(g => g.meters.length).map(g => `<optgroup label="${esc(g.u.label || g.u.key)}">${g.meters.map(m => {
    const v = `${g.u.key}|${m.id}`;
    return `<option value="${esc(v)}"${v === curLink ? ' selected' : ''}>${esc(m.name)}</option>`;
  }).join('')}</optgroup>`).join('');

  const ctrl = openModal({
    title: existing ? t('reminders.form.titleEdit') : t('reminders.form.titleNew'),
    // v3.4.0 (N1021) — Formularraster statt `.form-grid` (eigene Schrift,
    // Höhe und Satzschreibung waren ein zweites System neben `.field`)
    body: `
      <div class="fgrid">
        <!-- v2.12.0 (Review UI-22) — Fehler am Feld statt im Toast -->
        <div class="field field--full"><label for="f-title">${t('reminders.form.fTitle')}</label>
          <input class="input input--text" type="text" id="f-title" value="${esc(r.title)}" placeholder="${t('reminders.form.fTitlePlaceholder')}" aria-describedby="f-title-msg">
          <div class="field-error" id="f-title-msg" role="alert" hidden></div></div>
        <div class="field"><label for="f-cat">${t('reminders.form.fCategory')}</label><select class="select" id="f-cat">${catOpts}</select></div>
        <div class="field field--s"><label for="f-due">${t('reminders.form.fDue')}</label>
          <input class="input" type="date" id="f-due" value="${esc(r.next_due)}" aria-describedby="f-due-msg">
          <div class="field-error" id="f-due-msg" role="alert" hidden></div></div>
        <div class="field field--new"><label for="f-rec">${t('reminders.form.fRecurrence')}</label><select class="select" id="f-rec">${recOpts}</select></div>
        <div class="field field--s" id="f-rm-wrap" ${r.recurrence === 'custom-months' ? '' : 'hidden'}><label for="f-rm">${t('reminders.form.fInterval')}</label>
          <input class="input" type="text" inputmode="numeric" autocomplete="off" id="f-rm" value="${esc(String(r.recurrence_months || 12))}" aria-describedby="f-rm-msg">
          <div class="field-error" id="f-rm-msg" role="alert" hidden></div></div>
        <div class="field field--full"><label for="f-notes">${t('reminders.form.fNotes')}</label><input class="input input--text" type="text" id="f-notes" value="${esc(r.notes || '')}"></div>
        <!-- v3.7.0 — Termin am Zähler: ohne eigene Zuständigkeit gilt die Person des Zählers -->
        <div class="field field--m"><label for="f-meter">${esc(t('reminders.form.fMeter'))}</label>
          <select class="select" id="f-meter" aria-describedby="f-meter-hint"><option value="">${esc(t('reminders.form.meterNone'))}</option>${meterOpts}</select>
          <small class="settings-field__hint" id="f-meter-hint">${esc(t('reminders.form.meterHint'))}</small></div>
        <!-- v3.5.0 (F1028) — Zuständig: Person aus der Anmeldung oder freier Name -->
        <div class="field field--l"><label for="f-assignee">${esc(t('assignee.label'))}</label>
          <input class="input input--text" type="text" id="f-assignee" list="f-assignee-list" maxlength="60" autocomplete="off"
            value="${esc(ownAssigneeOf(r)?.name || '')}" placeholder="${esc(t('assignee.placeholder'))}" aria-describedby="f-assignee-hint f-assignee-inherit f-assignee-msg">
          <datalist id="f-assignee-list">${known.names.map(n => `<option value="${esc(n)}"></option>`).join('')}</datalist>
          <small class="settings-field__hint" id="f-assignee-hint">${esc(t(known.people.length ? 'assignee.hintPeople' : 'assignee.hint'))}${ownAssigneeOf(r)?.former ? ' ' + esc(t('assignee.wasFormer')) : ''}${ownAssigneeOf(r)?.deleted ? ' ' + esc(t('assignee.wasDeleted')) : ''}</small>
          <small class="settings-field__hint" id="f-assignee-inherit" data-role="assignee-inherit" aria-live="polite"></small>
          <div class="field-error" id="f-assignee-msg" role="alert" hidden></div></div>
      </div>`,
    footer: `
      <button class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button class="btn btn--primary" data-act="save">${t('reminders.form.save')}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      bodyEl.querySelector('#f-rec').addEventListener('change', e => {
        bodyEl.querySelector('#f-rm-wrap').hidden = e.target.value !== 'custom-months';
      });
      // v3.7.0 — wer ohne eigenen Eintrag zuständig ist (Person des Zählers)
      const meterSel = bodyEl.querySelector('#f-meter');
      const asIn = bodyEl.querySelector('#f-assignee');
      const inheritEl = bodyEl.querySelector('[data-role="assignee-inherit"]');
      const showInherit = () => {
        if (!inheritEl) return;
        const a = asIn?.value.trim() ? null : ownAssigneeOf(meterByLink.get(meterSel?.value || ''));
        inheritEl.textContent = a ? t('assignee.inheritsMeter', { name: displayName(a) }) : '';
        inheritEl.hidden = !a;
      };
      // neue Eichfrist oder Lieferung: der einzige passende Zähler ist vorgewählt, solange niemand wählt
      const preselect = () => {
        if (existing || !meterSel || meterSel.dataset.touched) return;
        const keys = CATEGORY_UTILITIES[bodyEl.querySelector('#f-cat')?.value] || [];
        const cands = meterGroups.filter(g => keys.includes(g.u.key)).flatMap(g => g.meters.map(m => `${g.u.key}|${m.id}`));
        meterSel.value = cands.length === 1 ? cands[0] : '';
        showInherit();
      };
      meterSel?.addEventListener('change', () => { meterSel.dataset.touched = '1'; showInherit(); });
      asIn?.addEventListener('input', showInherit);
      bodyEl.querySelector('#f-cat')?.addEventListener('change', preselect);
      preselect();
      showInherit();
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
          ...assigneePayload(bodyEl.querySelector('#f-assignee').value, existing),
          ...meterPayload(meterSel?.value || ''),   // v3.7.0
        };
        const titleEl = bodyEl.querySelector('#f-title'), dueEl = bodyEl.querySelector('#f-due');
        const asEl = bodyEl.querySelector('#f-assignee');
        showFieldError(titleEl, bodyEl.querySelector('#f-title-msg'), null);
        showFieldError(dueEl, bodyEl.querySelector('#f-due-msg'), null);
        showFieldError(rmEl, bodyEl.querySelector('#f-rm-msg'), null);
        showFieldError(asEl, bodyEl.querySelector('#f-assignee-msg'), null);
        if (!payload.title) { showFieldError(titleEl, bodyEl.querySelector('#f-title-msg'), t('reminders.form.titleRequired')); return; }
        if (!payload.next_due) { showFieldError(dueEl, bodyEl.querySelector('#f-due-msg'), t('reminders.form.dueRequired')); return; }
        if (!rmOk) { showFieldError(rmEl, bodyEl.querySelector('#f-rm-msg'), t('reminders.form.intervalInvalid')); return; }
        if ((payload.assignee ?? '').length > ASSIGNEE_MAX) { showFieldError(asEl, bodyEl.querySelector('#f-assignee-msg'), t('assignee.tooLong', { max: ASSIGNEE_MAX })); return; }
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

/** v3.5.0 (F1028) — längster Name, wie der Server (ReminderService::ASSIGNEE_MAX). */
const ASSIGNEE_MAX = 60;

/**
 * v3.5.0 (F1028) — Zuständigkeit für den Server: Ein Name aus der
 * Personenliste wird zur Person (`assignee_id`), sonst ist er ein freier
 * Name; leer = niemand. Bleibt der Name des Termins unverändert, bleibt auch
 * seine Person (auch wenn die Liste gerade fehlt, etwa ohne Anmeldung).
 */
export function assigneePayload(value, existing = null, people = known.people) {
  const name = String(value ?? '').trim();
  const prev = ownAssigneeOf(existing);   // v3.7.0 — nur die eigene, nie die geerbte
  // v3.6.0 — gelöschte Person, Name unverändert: bleibt stehen (sie lässt sich nicht neu zuweisen)
  if (prev?.deleted && normName(prev.name) === normName(name)) return {};
  const person = name ? people.find(p => normName(p.name) === normName(name)) : null;
  if (person) return { assignee: person.name, assignee_id: person.id };
  if (name && prev?.id && normName(prev.name) === normName(name)) return { assignee: prev.name, assignee_id: prev.id };
  // „ehemalige Person“ bleibt stehen, bis jemand einen neuen Namen einträgt
  if (!name && prev?.former) return {};
  return { assignee: name, assignee_id: null };
}

/** v3.7.0 — Zähler eines Termins für den Server: „utility|meter_id“ oder leer (ohne Zähler). */
export function meterPayload(value) {
  const v = String(value || '');
  const i = v.indexOf('|');
  return i > 0 ? { utility: v.slice(0, i), meter_id: v.slice(i + 1) } : { meter_id: null };
}

/** v3.7.0 — Kalender-Link nur für eine Person (`person=<id>`, an vorhandene Parameter angehängt). */
export function calendarLinkFor(url, personId) {
  return url + (url.includes('?') ? '&' : '?') + 'person=' + encodeURIComponent(personId);
}

// v3.1.0 (Paket H1, MKT-09) — Fristen und Termine als Kalender-Abo. Mit
// eingeschalteter Anmeldung bekommt das Abo einen eigenen Schlüssel (Bereich
// „calendar"), der nur für den Kalender gilt und im Link stehen darf.
async function openCalendarSubscription() {
  // v3.5.0 (N1025) — Unter Home-Assistant-Ingress braucht die Adresse aus der
  // Adresszeile eine HA-Sitzung; kein Kalender kann sie abrufen. Dann die
  // Adresse im Netz von Home Assistant (wie die HA-Vorlagen) und ein Hinweis,
  // wie Handy und Computer sie über den direkten Port erreichen.
  const underIngress = ingressHost() !== null;
  let url = underIngress ? haUrl(api.calendarUrl()) : new URL(api.calendarUrl(), location.href).href;
  let tokenNote = '';
  let me = null;   // v3.7.0 — angemeldet: Kalender nur mit dem, was bei mir liegt
  try {
    const session = await api.session();
    me = session?.authenticated && session?.user?.id ? String(session.user.id) : null;
    if (session?.mode && session.mode !== 'off') {
      // Ein Link braucht einen eigenen Schlüssel — erst nach Rückfrage, nicht
      // bei jedem Öffnen (der Schlüssel lässt sich später nicht mehr anzeigen)
      const existing = ((await api.apiKeys().catch(() => [])) || []).filter(k => k.scope === 'calendar').length;
      const ok = await confirmModal({
        title: t('calendar.subscribe'),
        message: t('calendar.createConfirm') + (existing ? ' ' + tp('calendar.existingKeys', existing) : ''),
        confirmLabel: t('calendar.createLink'),
      });
      if (!ok) return;
      const key = await api.createApiKey(t('calendar.name'), 'calendar');
      url += `?token=${encodeURIComponent(key.key)}`;
      tokenNote = `<p class="muted small">${esc(t('calendar.tokenCreated'))}</p>`;
    }
  } catch (e) {
    toastErr(t('reminders.toast.error', { msg: e.message || e }));
    return;
  }
  const webcal = url.replace(/^https?:/, 'webcal:');
  // v3.7.0 (Qualität) — beide Adressen beschriftet (Bildschirmleser lasen nur „Eingabefeld“)
  const field = (id, value, label) => `
    <div class="field cal-field">
      <label for="${id}">${esc(label)}</label>
      <input class="input" id="${id}" readonly value="${esc(value)}">
      <button type="button" class="btn btn--sm" data-copy="#${id}">${esc(t('calendar.copy'))}</button>
    </div>`;
  openModal({
    title: t('calendar.subscribe'),
    body: `
      <p>${esc(t('calendar.intro'))}</p>
      ${me ? `<label class="check"><input type="checkbox" id="cal-mine" aria-describedby="cal-mine-hint"> ${esc(t('calendar.onlyMine'))}</label>
        <p class="muted small" id="cal-mine-hint">${esc(t('calendar.onlyMineHint'))}</p>` : ''}
      ${field('cal-webcal', webcal, t('calendar.webcalLabel'))}
      ${field('cal-https', url, t('calendar.httpsLabel'))}
      ${tokenNote}
      ${underIngress ? `<p class="muted small" id="cal-ingress-hint">${esc(t('calendar.ingressHint'))}</p>` : ''}
      <p class="muted small">${esc(t('calendar.appleHint'))}</p>
      <p class="muted small">${esc(t('calendar.googleHint'))}</p>`,
    onMount: ({ modalEl }) => {
      // v3.7.0 — `person=<id>`: nur Fristen und Termine, für die ich zuständig bin (auch geerbt)
      modalEl.querySelector('#cal-mine')?.addEventListener('change', (e) => {
        const u = e.target.checked ? calendarLinkFor(url, me) : url;
        modalEl.querySelector('#cal-https').value = u;
        modalEl.querySelector('#cal-webcal').value = u.replace(/^https?:/, 'webcal:');
      });
      modalEl.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
        await copyText(modalEl.querySelector(b.dataset.copy)?.value || '');
        toastOk(t('settings.ha.yamlCopied'));
      }));
    },
  });
}

