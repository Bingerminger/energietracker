// =====================================================================
// Einstellungen › Haushalt: Personen.
// v3.12.0 — aus views/settings.js ausgelagert, Verhalten unverändert.
// =====================================================================
import { api } from '../api.js';
import { escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { t } from '../lib/i18n.js';
import { confirmPersonDelete } from '../components/person-delete.js';
import { icon } from '../lib/icons.js';
import { loginOff, openRenameDialog } from './settings-access.js';


// ── v3.9.0 — Personen im Haushalt (Haushalt & Gebäude, jede Stufe) ───────
// „Zuständig“ an Terminen, Zählern und Verträgen und die Ablesetermine aus dem
// Posteingang nutzen Personen in allen Stufen; bis v3.8 entstanden sie nur mit
// eingeschalteter Anmeldung (Zugriff) oder aus einem Backup. Hier legt, wer
// verwaltet (ohne Anmeldung: jede:r), Personen ohne Zugang an, benennt sie um
// und löscht sie (POST /api/users mit `has_account: false`). Personen mit Zugang
// stehen mit Vermerk in der Liste, verwaltet werden sie unter Zugriff.
// Mitglieder sehen nur die Namen (GET /api/people).

export function renderPeopleCard(people, session, isAdmin) {
  const off = loginOff(session);
  const list = (people || []).filter(p => p?.id && p.name);
  const item = (p) => {
    const account = isAdmin && p.has_account !== false;
    const actions = isAdmin && !account ? `<span class="people-list__actions">
        <button type="button" class="btn btn--sm btn--ghost" data-person-rename="${escapeHtml(p.id)}" data-person-name="${escapeHtml(p.name)}"
          aria-label="${escapeHtml(t('settings.users.renameTitle', { name: p.name }))}">${escapeHtml(t('settings.users.rename'))}</button>
        <button type="button" class="btn btn--sm btn--danger btn--quiet" data-person-delete="${escapeHtml(p.id)}"
          aria-label="${escapeHtml(t('settings.people.deleteLabel', { name: p.name }))}">${escapeHtml(t('settings.users.delete'))}</button>
      </span>` : '';
    return `<li class="people-list__item">
        <span class="people-list__who"><span class="people-list__name">${escapeHtml(p.name)}</span>${p.no ? ` <span class="muted small">${escapeHtml(t('settings.users.no', { no: p.no }))}</span>` : ''}${account ? ` <span class="badge" data-role="has-account">${escapeHtml(t('settings.people.account'))}</span>` : ''}</span>
        ${actions}
      </li>`;
  };
  const accessHint = !isAdmin ? t('settings.users.memberDeleteHint')
    : off ? t('settings.people.accessHintOff') : t('settings.people.accessHint');
  return `
    <div class="card settings-card" id="people-card" data-field="people">
      <h2 class="card__title">${icon('personen')} ${escapeHtml(t('settings.people.title'))}</h2>
      <p class="settings-card__hint">${escapeHtml(t('settings.people.hint'))}</p>
      ${list.length
        ? `<ul class="people-list" aria-label="${escapeHtml(t('settings.people.title'))}">${list.map(item).join('')}</ul>`
        : `<p class="muted small" data-role="people-empty">${escapeHtml(t(isAdmin ? 'settings.people.empty' : 'settings.people.emptyMember'))}</p>`}
      ${isAdmin ? `
      <form class="people-add" data-role="people-form" autocomplete="off">
        <div class="field">
          <label for="people-new-name">${escapeHtml(t('settings.people.add'))}</label>
          <input class="input input--text" id="people-new-name" data-field="person_name" maxlength="40" autocomplete="off" enterkeyhint="done"
            placeholder="${escapeHtml(t('settings.people.namePlaceholder'))}">
        </div>
        <button type="submit" class="btn btn--primary" id="btn-person-add">${escapeHtml(t('settings.users.addButton'))}</button>
      </form>` : ''}
      <p class="settings-field__hint" data-role="people-access-hint">${escapeHtml(accessHint)}${isAdmin ? `
        <a href="#/settings/access">${escapeHtml(t('settings.people.accessLink'))}</a>` : ''}</p>
    </div>`;
}


export function wirePeopleCard(container, rerender) {
  const again = async () => {
    await rerender();
    container.querySelector('#people-new-name')?.focus();
  };
  container.querySelector('form[data-role="people-form"]')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = container.querySelector('#people-new-name');
    const name = input?.value.trim() || '';
    if (!name) { input?.focus(); return; }
    try {
      await api.createUser({ name, has_account: false });
      toastOk(t('settings.people.added', { name }));
      await again();
    } catch (err) { toastErr(err.message); input?.focus(); }
  });
  container.querySelectorAll('[data-person-rename]').forEach(btn => btn.addEventListener('click', () => {
    openRenameDialog(btn.getAttribute('data-person-rename'), btn.getAttribute('data-person-name') || '', false, rerender);
  }));
  // derselbe Dialog wie unter Zugriff: sagt vorher, was mit Terminen, Zählern und Verträgen geschieht
  container.querySelectorAll('[data-person-delete]').forEach(btn => btn.addEventListener('click', () => {
    confirmPersonDelete(btn.getAttribute('data-person-delete'), rerender);
  }));
}
