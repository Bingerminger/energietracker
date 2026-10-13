// =====================================================================
// v3.4.0 (F1024) — Hinweise › Erledigt: was erledigt wurde, von wem und wann.
//
// Der Verlauf (GET /api/activity), nach Monaten gruppiert, neueste zuerst.
// Gefiltert wird in der Ansicht: nach Person (nur wenn Personen vorkommen),
// Zeitraum und Bereich. Automatische Einträge nennen ihren Grund
// („Folgevertrag … ab …", „Zählerstand vom … erfasst"). Rückgängig streicht
// durch, statt zu löschen; Notizen lassen sich nachtragen. Verwalter stellen
// die Aufbewahrung ein und leeren den Verlauf.
// =====================================================================

import { api } from '../api.js';
import { t, tp } from '../lib/i18n.js';
import { fmt, escapeHtml as esc, todayIso, intlLocale } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { AREAS, AREA_KEYS, entryTitle, reasonText, whenWho, personsIn } from '../lib/activity.js';
import { sessionRole } from '../lib/levels.js';
import { getSettings, saveSettings } from '../state.js';
import { docUrl } from '../lib/docs.js';

/** Zeiträume des Filters (Schlüssel unter activity.period.*). */
const PERIODS = ['all', 'm3', 'm12', 'thisYear', 'lastYear'];
const ICONS = { done: '✅', auto_done: '⚙️', dismissed: '💤' };

export async function render(container) {
  container.innerHTML = `<div class="loading">${esc(t('common.loading'))}</div>`;
  let entries;
  try {
    entries = await api.activity();
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${esc(t('activity.loadError', { msg: e.message || e }))}</div>`;
    return;
  }
  const filter = { person: '', period: 'all', area: '' };
  const admin = sessionRole() === 'admin';

  const draw = () => {
    const persons = personsIn(entries);
    if (filter.person && !persons.some(p => p.id === filter.person)) filter.person = '';
    const list = entries.filter(e => matches(e, filter));
    container.innerHTML = `
      <div class="view-header">
        <div>
          <h1 class="view-header__title">${esc(t('activity.title'))}</h1>
          <p class="view-header__subtitle">${esc(t('activity.subtitle'))} <a href="${esc(docUrl('done'))}">${esc(t('common.guide'))}</a></p>
        </div>
        ${admin ? `<div class="view-header__actions"><button type="button" class="btn btn--ghost" id="act-manage">${esc(t('activity.manage.button'))}</button></div>` : ''}
      </div>
      <div class="toolbar activity-filters" role="group" aria-label="${esc(t('activity.filter.label'))}">
        ${persons.length ? `<label>${esc(t('activity.filter.person'))}
          <select class="select" id="act-person"><option value="">${esc(t('activity.filter.everyone'))}</option>
            ${persons.map(p => `<option value="${esc(p.id)}"${filter.person === p.id ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}
          </select></label>` : ''}
        <label>${esc(t('activity.filter.period'))}
          <select class="select" id="act-period">${PERIODS.map(p =>
            `<option value="${p}"${filter.period === p ? ' selected' : ''}>${esc(t('activity.period.' + p))}</option>`).join('')}</select></label>
        <label>${esc(t('activity.filter.area'))}
          <select class="select" id="act-area"><option value="">${esc(t('activity.area.all'))}</option>${AREA_KEYS.map(a =>
            `<option value="${a}"${filter.area === a ? ' selected' : ''}>${esc(t('activity.area.' + a))}</option>`).join('')}</select></label>
      </div>
      ${list.length
        ? byMonth(list).map(([ym, items]) => monthHtml(ym, items)).join('')
        : `<div class="banner banner--info">${esc(t(entries.length ? 'activity.emptyFiltered' : 'activity.empty'))}</div>`}`;

    container.querySelector('#act-person')?.addEventListener('change', e => { filter.person = e.target.value; draw(); });
    container.querySelector('#act-period')?.addEventListener('change', e => { filter.period = e.target.value; draw(); });
    container.querySelector('#act-area')?.addEventListener('change', e => { filter.area = e.target.value; draw(); });
    container.querySelector('#act-manage')?.addEventListener('click', () => openManage(async () => {
      entries = await api.activity();
      if (container.isConnected) draw();
    }));
    container.querySelectorAll('[data-undo]').forEach(b => b.addEventListener('click', async () => {
      const e = entries.find(x => x.id === b.dataset.undo);
      try {
        const res = await api.undoActivity(e.id);
        replace(res);
        toastOk(t('activity.toast.undone', { title: entryTitle(e) }));
        window.dispatchEvent(new CustomEvent('et:badges-refresh'));
        if (container.isConnected) draw();
      } catch (err) { toastErr(t('activity.toast.error', { msg: err.message || err })); }
    }));
    container.querySelectorAll('[data-note]').forEach(b => b.addEventListener('click', () => {
      const e = entries.find(x => x.id === b.dataset.note);
      if (e) openNote(e, (saved) => { replace(saved); if (container.isConnected) draw(); });
    }));
  };

  /** Eintrag nach einer Änderung ersetzen (ohne Neuladen). */
  const replace = (res) => {
    if (!res?.id) return;
    const { reverted, ...entry } = res;
    entries = entries.map(x => (x.id === entry.id ? entry : x));
  };

  draw();
}

/** Passt ein Eintrag zum Filter? */
function matches(e, f) {
  if (f.person && e.by?.id !== f.person) return false;
  if (f.area && AREAS[e.subject?.kind] !== f.area) return false;
  const [from, to] = periodRange(f.period);
  const on = String(e.done_on || '');
  return (!from || on >= from) && (!to || on <= to);
}

/** [von, bis] eines Zeitraums als JJJJ-MM-TT (leer = offen). */
function periodRange(p) {
  const today = todayIso();
  const y = Number(today.slice(0, 4));
  const back = (months) => {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - months);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
  };
  switch (p) {
    case 'm3': return [back(3), ''];
    case 'm12': return [back(12), ''];
    case 'thisYear': return [`${y}-01-01`, ''];
    case 'lastYear': return [`${y - 1}-01-01`, `${y - 1}-12-31`];
    default: return ['', ''];
  }
}

/** Nach Monat des Erledigens gruppiert, neueste zuerst. */
function byMonth(list) {
  const groups = new Map();
  for (const e of list) {
    const ym = String(e.done_on || '').slice(0, 7);
    if (!groups.has(ym)) groups.set(ym, []);
    groups.get(ym).push(e);
  }
  return [...groups].sort((a, b) => b[0].localeCompare(a[0]));
}

function monthTitle(ym) {
  const m = /^(\d{4})-(\d{2})$/.exec(ym);
  if (!m) return esc(ym);
  return new Intl.DateTimeFormat(intlLocale(), { month: 'long', year: 'numeric' }).format(new Date(Number(m[1]), Number(m[2]) - 1, 1, 12));
}

function monthHtml(ym, items) {
  return `<section class="card activity-month">
    <h2 class="card__title">${esc(monthTitle(ym))} <span class="muted small">${esc(tp('activity.count', items.length))}</span></h2>
    <ul class="activity-list">${items.map(itemHtml).join('')}</ul>
  </section>`;
}

function itemHtml(e) {
  const undone = !!e.undone;
  const title = entryTitle(e);
  const reason = reasonText(e.reason);
  const badge = e.action === 'auto_done' ? t('activity.action.auto') : e.action === 'dismissed' ? t('activity.action.dismissed') : '';
  return `<li class="activity-item activity-item--${esc(e.action)}${undone ? ' activity-item--undone' : ''}" data-id="${esc(e.id)}">
    <span class="activity-item__icon" aria-hidden="true">${ICONS[e.action] || '•'}</span>
    <div class="activity-item__body">
      <div class="activity-item__title">${undone ? `<s>${esc(title)}</s>` : esc(title)}
        ${badge ? ` <span class="badge badge--info">${esc(badge)}</span>` : ''}</div>
      <div class="muted small">${esc(whenWho(e.done_on, e.by))}</div>
      ${reason ? `<div class="muted small">${esc(t('activity.reasonLine', { reason }))}</div>` : ''}
      ${e.note ? `<div class="activity-item__note">${esc(e.note)}</div>` : ''}
      ${e.attachment_id ? `<a class="small" href="${esc(api.attachmentUrl(e.attachment_id))}" target="_blank" rel="noopener"><span aria-hidden="true">📎</span> ${esc(t('activity.attachment'))}</a>` : ''}
      ${undone ? `<div class="muted small">${esc(t('activity.undoneLine', { when: whenWho(String(e.undone.at || '').slice(0, 10), e.undone.by) }))}</div>` : ''}
    </div>
    <div class="activity-item__actions">
      <button type="button" class="btn btn--xs btn--ghost" data-note="${esc(e.id)}" title="${esc(t('activity.note.edit'))}"
        aria-label="${esc(t('activity.note.editNamed', { title }))}"><span aria-hidden="true">✎</span></button>
      ${!undone && e.action !== 'auto_done' ? `<button type="button" class="btn btn--xs btn--ghost" data-undo="${esc(e.id)}"
        aria-label="${esc(t('activity.undoNamed', { title }))}">${esc(t('common.undo'))}</button>` : ''}
    </div>
  </li>`;
}

/** Notiz nachtragen oder ändern. */
function openNote(e, onSaved) {
  openModal({
    title: t('activity.note.edit'),
    body: `<p><strong>${esc(entryTitle(e))}</strong></p>
      <div class="fgrid">
        <div class="field field--full">
          <label for="an-note">${esc(t('activity.dialog.note'))}</label>
          <input class="input" type="text" id="an-note" maxlength="500" value="${esc(e.note || '')}">
        </div>
      </div>`,
    footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
      <button type="button" class="btn btn--primary" data-act="save">${esc(t('common.save'))}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
      const save = modalEl.querySelector('[data-act="save"]');
      save?.addEventListener('click', guardSubmit(save, async () => {
        try {
          const saved = await api.updateActivity(e.id, { note: bodyEl.querySelector('#an-note').value.trim() });
          close(true);
          toastOk(t('activity.note.saved'));
          onSaved(saved);
        } catch (err) { toastErr(t('activity.toast.error', { msg: err.message || err })); }
      }));
    },
  });
}

/** Verwalter: Aufbewahrung (`activity_keep_months`) und Verlauf leeren. */
async function openManage(onChanged) {
  const settings = await getSettings().catch(() => ({}));
  const keep = Number.isFinite(Number(settings.activity_keep_months)) ? Number(settings.activity_keep_months) : 60;
  openModal({
    title: t('activity.manage.title'),
    body: `
      <div class="fgrid">
        <div class="field field--full">
          <label for="am-keep">${esc(t('activity.manage.keep'))}</label>
          <input class="input input--short" type="text" inputmode="numeric" id="am-keep" value="${esc(String(keep))}" aria-describedby="am-keep-hint am-keep-msg">
          <small class="settings-field__hint" id="am-keep-hint">${esc(t('activity.manage.keepHint'))}</small>
          <div class="field-error" id="am-keep-msg" role="alert" hidden></div>
        </div>
      </div>
      <h3 class="settings-subtitle">${esc(t('activity.manage.clearTitle'))}</h3>
      <div class="fgrid">
        <div class="field field--s">
          <label for="am-before">${esc(t('activity.manage.before'))}</label>
          <input class="input" type="date" id="am-before" max="${todayIso()}" aria-describedby="am-before-hint">
          <small class="settings-field__hint" id="am-before-hint">${esc(t('activity.manage.clearHint'))}</small>
        </div>
        <div class="field field--l field__actions">
          <button type="button" class="btn btn--danger btn--quiet" id="am-clear">${esc(t('activity.manage.clear'))}</button>
        </div>
      </div>`,
    footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
      <button type="button" class="btn btn--primary" data-act="save">${esc(t('common.save'))}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
      const save = modalEl.querySelector('[data-act="save"]');
      save?.addEventListener('click', guardSubmit(save, async () => {
        const el = bodyEl.querySelector('#am-keep');
        const msg = bodyEl.querySelector('#am-keep-msg');
        const v = el.value.trim();
        if (!/^\d{1,4}$/.test(v) || Number(v) > 1200) {
          msg.textContent = t('activity.manage.keepInvalid');
          msg.hidden = false;
          el.setAttribute('aria-invalid', 'true');
          return;
        }
        try {
          await saveSettings({ activity_keep_months: Number(v) });
          close(true);
          toastOk(t('activity.manage.saved'));
          await onChanged();
        } catch (err) { toastErr(t('activity.toast.error', { msg: err.message || err })); }
      }));
      bodyEl.querySelector('#am-clear')?.addEventListener('click', async () => {
        const before = bodyEl.querySelector('#am-before').value;
        const ok = await confirmModal({
          title: t('activity.manage.clear'),
          message: before ? t('activity.manage.confirmBefore', { date: fmt.date(before) }) : t('activity.manage.confirmAll'),
          confirmLabel: t('activity.manage.clear'), danger: true,
        });
        if (!ok) return;
        try {
          const res = await api.clearActivity(before);
          close(true);
          toastOk(tp('activity.manage.cleared', Number(res?.deleted) || 0));
          await onChanged();
        } catch (err) { toastErr(t('activity.toast.error', { msg: err.message || err })); }
      });
    },
  });
}
