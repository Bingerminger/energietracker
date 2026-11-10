// =====================================================================
// Einstellungen › Zugriff: Stufe, eigenes Konto, Benutzer, Anmeldung, Schlüssel, Sicherheits-Check.
// v3.12.0 — aus views/settings.js ausgelagert, Verhalten unverändert.
// =====================================================================
import { api } from '../api.js';
import { saveSettings } from '../state.js';
import { escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { confirmModal, openModal } from '../components/modal.js';
import { logout } from '../components/login.js';
import { t } from '../lib/i18n.js';
import { docUrl } from '../lib/docs.js';
import { LEVELS, LEVEL_ICONS, currentLevel, saveLevel } from '../lib/levels.js';
import { openSetupWizard } from '../components/setup-wizard.js';
import { confirmPersonDelete } from '../components/person-delete.js';
import { icon } from '../lib/icons.js';
import { copyText } from './settings-integrations.js';
import { stampHtml } from './settings-data.js';


// ── Seite „Zugriff": Anmeldung und API-Schlüssel (v2.6.0) ─────────────────
export function wireAccessPage(container, rerender) {
  const pwValue = (id) => container.querySelector('#' + id)?.value ?? '';
  const newPassword = () => {
    const pw = pwValue('sec-new');
    if (pw.length < 8) { toastErr(t('errors.auth.passwordTooShort', { min: 8 })); return null; }
    if (pw !== pwValue('sec-repeat')) { toastErr(t('settings.security.mismatch')); return null; }
    return pw;
  };
  const sessionChanged = (mode) => window.dispatchEvent(new CustomEvent('et:session-changed', { detail: { mode } }));

  container.querySelectorAll('form[data-role="sec-form"]').forEach(f => f.addEventListener('submit', (e) => e.preventDefault()));
  container.querySelector('#btn-sec-enable')?.addEventListener('click', async () => {
    const pw = newPassword();
    if (!pw) return;
    try {
      const r = await api.setPassword(pw);
      sessionChanged(r?.mode || 'password');
      toastOk(t('settings.security.enabled'));
      rerender();
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-sec-change')?.addEventListener('click', async () => {
    const pw = newPassword();
    if (!pw) return;
    try {
      await api.setPassword(pw, pwValue('sec-current'));
      toastOk(t('settings.security.changed'));
      rerender();
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-sec-logout')?.addEventListener('click', () => logout());
  wireLogoutEverywhere(container);

  container.querySelector('#btn-sec-disable')?.addEventListener('click', () => {
    openModal({
      title: t('settings.security.disableTitle'),
      // v3.6.0 — Passwortfeld im Formular (Safari am iPad); Enter bestätigt
      body: `<p>${escapeHtml(t('settings.security.disableMsg'))}</p>
        <form data-role="sec-disable-form" autocomplete="on">
          <input type="text" name="username" autocomplete="username" value="energietracker" hidden>
          <div class="field">
            <label for="sec-disable-pw">${t('settings.security.currentPassword')}</label>
            <input class="input input--text" id="sec-disable-pw" type="password" autocomplete="current-password">
            <div class="field-error" id="sec-disable-msg" role="alert" hidden></div>
          </div>
        </form>`,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--danger" data-act="ok">${t('settings.security.disable').replace(/…$/, '')}</button>`,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
        const okBtn = modalEl.querySelector('[data-act="ok"]');
        modalEl.querySelector('form[data-role="sec-disable-form"]')?.addEventListener('submit', (e) => { e.preventDefault(); okBtn?.click(); });
        okBtn?.addEventListener('click', async () => {
          okBtn.disabled = true;
          try {
            await api.disableLogin(modalEl.querySelector('#sec-disable-pw')?.value ?? '');
            close(true);
            sessionChanged('off');
            toastOk(t('settings.security.disabled'));
            rerender();
          } catch (e) {
            const msg = modalEl.querySelector('#sec-disable-msg');
            if (msg) { msg.textContent = e.message; msg.hidden = false; }
            okBtn.disabled = false;
          }
        });
      },
    });
  });

  const refreshKeys = async () => {
    const el = container.querySelector('#sec-keys');
    if (!el) return;
    try { el.innerHTML = renderKeyTable(await api.apiKeys()); } catch { /* alte Liste bleibt */ }
  };

  container.querySelector('#btn-key-create')?.addEventListener('click', async () => {
    const nameEl = container.querySelector('#key-name');
    // v3.7.0 (Paket betrieb) — `inbox`: nur PDFs in den Posteingang (Kurzbefehl, Home Assistant)
    const picked = container.querySelector('#key-scope')?.value;
    const scope = ['admin', 'inbox'].includes(picked) ? picked : 'read';
    const name = (nameEl?.value || '').trim();
    try {
      const res = await api.createApiKey(name, scope);
      const shown = name || t(scopeLabelKey(scope));
      container.querySelector('#key-reveal').innerHTML = `
        <div class="infobox infobox--erfolg" style="margin-top:.5rem">
          <strong>${t('settings.security.keyReveal', { name: escapeHtml(shown) })}</strong>
          <code class="mono" style="display:block;word-break:break-all;margin:6px 0">${escapeHtml(res.key)}</code>
          <button type="button" class="btn btn--sm" id="btn-key-copy">${t('settings.security.keyCopy')}</button>
        </div>`;
      container.querySelector('#btn-key-copy')?.addEventListener('click', () => copyText(res.key, t('settings.security.keyCopied')));
      if (nameEl) nameEl.value = '';
      refreshKeys();
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#sec-keys')?.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-key-revoke]');
    if (!btn) return;
    const ok = await confirmModal({
      title: t('settings.security.keyRevokeTitle'),
      message: t('settings.security.keyRevokeMsg', { name: btn.getAttribute('data-key-name') || '' }),
      confirmLabel: t('settings.security.keyRevoke'), danger: true,
    });
    if (!ok) return;
    try {
      await api.revokeApiKey(btn.getAttribute('data-key-revoke'));
      toastOk(t('settings.security.keyRevoked'));
      container.querySelector('#key-reveal').innerHTML = '';
      refreshKeys();
    } catch (e) { toastErr(e.message); }
  });
}


// ── v2.6.0 — Anmeldung & Zugriff ─────────────────────────────────────────
//
// Die Anmeldung ist opt-in (Review 2026-09-24): Ohne sie verhält sich die App
// wie bisher. Die Karte zeigt den Modus, schaltet die Passwort-Anmeldung ein
// und aus und verwaltet API-Schlüssel für Skripte. Was über Umgebungsvariablen
// festgelegt ist (ET_AUTH, ET_ADMIN_PASSWORD_HASH), lässt sich hier nicht ändern.
// ── v3.2.0 (F1019, F1018) — Nutzungsstufe und Einrichtung ───────────────

export function renderLevelCard(session) {
  const person = session?.user && session.mode !== 'off' ? session.user.name : null;
  return `
    <div class="card settings-card" data-field="ui_level">
      <h2 class="card__title">${icon('stufe1')} ${escapeHtml(t('settings.level.title'))}</h2>
      <p class="settings-card__hint">${escapeHtml(person ? t('settings.level.hintPerson', { name: person }) : t('settings.level.hint'))}</p>
      <div class="settings-fields">
        <div class="field settings-field">
          <label for="level-select-settings">${escapeHtml(t('level.switch'))}</label>
          <select class="select" id="level-select-settings">
            ${LEVELS.map(l => `<option value="${l}" ${l === currentLevel() ? 'selected' : ''}>${escapeHtml(t('level.' + l))}</option>`).join('')}
          </select>
        </div>
      </div>
      <ul class="settings-level-list">
        ${LEVELS.map(l => `<li><strong>${icon(LEVEL_ICONS[l], { size: 16 })} ${escapeHtml(t('level.' + l))}</strong> — ${escapeHtml(t('settings.level.describe.' + l))}</li>`).join('')}
      </ul>
      <div class="section-actions">
        <button type="button" class="btn btn--ghost" id="btn-setup-wizard" data-field="setup">${escapeHtml(t('setup.restart'))}</button>
        <a class="btn btn--ghost" href="${escapeHtml(docUrl('setup'))}">${escapeHtml(t('common.guide'))}</a>
        <!-- v3.7.0 (Paket reifegrad) — die Funktionstabelle nach Stufe im Handbuch -->
        <a class="btn btn--ghost" data-role="level-table" href="${escapeHtml(docUrl('levels'))}">${escapeHtml(t('level.table'))}</a>
      </div>
    </div>`;
}


export function wireLevelCard(container, session) {
  container.querySelector('#level-select-settings')?.addEventListener('change', async (e) => {
    try { await saveLevel(e.target.value); toastOk(t('settings.level.saved')); }
    catch (err) { toastErr(err.message); e.target.value = currentLevel(); }
  });
  container.querySelector('#btn-setup-wizard')?.addEventListener('click', () => openSetupWizard({ session }));
  wireMyAccount(container);
}


// ── v3.2.0 (F1023) — Mein Konto und Personen im Haushalt ─────────────────

export function renderMyAccountCard(session) {
  const u = session?.user;
  if (!u || session.mode === 'off') return '';
  const pw = (id, key, ac) => `<div class="field"><label for="${id}">${escapeHtml(t(key))}</label>
    <input class="input input--text" id="${id}" type="password" autocomplete="${ac}"></div>`;
  return `
    <div class="card settings-card" data-field="my_account">
      <h2 class="card__title">${icon('person')} ${escapeHtml(t('settings.me.title', { name: u.name }))}</h2>
      <p class="settings-card__hint">${escapeHtml(t('settings.users.role.' + (session.role || u.role)))}</p>
      ${u.source === 'password' ? `
      <!-- v3.6.0 — Passwortfelder im Formular wie unter „Zugriff“ (Safari am iPad) -->
      <form class="fgrid" data-role="me-form" autocomplete="on">
        <input type="text" name="username" autocomplete="username" value="${escapeHtml(u.name)}" hidden>
        ${pw('me-current', 'settings.security.currentPassword', 'current-password')}
        ${pw('me-new', 'settings.security.newPassword', 'new-password')}
        <div class="field"><button type="button" class="btn" id="btn-me-password">${escapeHtml(t('settings.security.change'))}</button></div>
      </form>
      <div class="section-actions">
        <button type="button" class="btn btn--ghost" data-logout-everywhere>${escapeHtml(t('settings.security.logoutEverywhere'))}</button>
      </div>` : `<p class="muted small">${escapeHtml(t('settings.me.proxyHint'))}</p>`}
    </div>`;
}


// v3.4.0 (N1020) — „Überall abmelden": alle Sitzungen der Person enden
function wireLogoutEverywhere(container) {
  container.querySelectorAll('[data-logout-everywhere]').forEach(btn => btn.addEventListener('click', async () => {
    const ok = await confirmModal({
      title: t('settings.security.logoutEverywhereTitle'),
      message: t('settings.security.logoutEverywhereMsg'),
      confirmLabel: t('settings.security.logoutEverywhere'), danger: true,
    });
    if (ok) await logout({ everywhere: true });
  }));
}


function wireMyAccount(container) {
  wireLogoutEverywhere(container);
  // Abgeschickt wird nie (Enter löst denselben Knopf aus)
  container.querySelector('form[data-role="me-form"]')?.addEventListener('submit', (e) => {
    e.preventDefault();
    container.querySelector('#btn-me-password')?.click();
  });
  container.querySelector('#btn-me-password')?.addEventListener('click', async () => {
    const cur = container.querySelector('#me-current')?.value || '';
    const next = container.querySelector('#me-new')?.value || '';
    if (next.length < 8) { toastErr(t('errors.auth.passwordTooShort', { min: 8 })); return; }
    try {
      await api.changeMyPassword(cur, next);
      container.querySelector('#me-current').value = '';
      container.querySelector('#me-new').value = '';
      toastOk(t('settings.me.passwordChanged'));
    } catch (e) { toastErr(e.message); }
  });
}


/** v3.9.0 — Anmeldung aus (oder unbekannt): Zugänge gibt es dann keine. */
export const loginOff = (session) => !session?.mode || session.mode === 'off';


export function renderUsersCard(users, session) {
  // v3.6.0 — ohne Anmeldung sagt die Karte, dass Zugänge erst mit der Anmeldung
  // entstehen, und springt zu „Anmeldung einschalten“ (Karte „Anmeldung & Zugriff“).
  // v3.9.0 — die Personen selbst (bis v3.8 hier, nur ohne Anmeldung als Tabelle)
  // stehen unter Haushalt & Gebäude, Karte „Personen im Haushalt“
  const off = loginOff(session);
  const loginNeeded = off ? `
      <p class="settings-field__hint" data-role="users-login-needed">${escapeHtml(t('settings.users.loginNeeded'))}</p>
      <button type="button" class="btn btn--ghost btn--sm" data-role="users-enable-login">${escapeHtml(t('settings.security.enable'))}</button>` : '';
  if (off) {
    return `
    <div class="card settings-card" id="users-card" data-field="users">
      <h2 class="card__title">${icon('personen')} ${escapeHtml(t('settings.users.title'))}</h2>
      ${loginNeeded}
      <p class="settings-field__hint" data-role="users-people-moved">${escapeHtml(t('settings.people.movedHint'))}
        <a href="#/household/people?field=person_name">${escapeHtml(t('settings.people.goto'))}</a></p>
    </div>`;
  }
  const meId = session.user?.id;
  // v3.6.0 — Personen ohne Zugang (`has_account: false`): Rolle umstellen und
  // umbenennen wie bei einem Zugang (PATCH /api/users/{id}, nur Verwalter); die
  // Rolle wirkt, sobald jemand für sie einen Zugang anlegt
  const noAccess = (u) => u.has_account === false;
  const roleSelect = (u) => `<select class="select select--sm" data-user-role="${escapeHtml(u.id)}" aria-label="${escapeHtml(t('settings.users.roleLabel', { name: u.name }))}">
      ${['admin', 'member'].map(r => `<option value="${r}" ${u.role === r ? 'selected' : ''}>${escapeHtml(t('settings.users.role.' + r))}</option>`).join('')}
    </select>`;
  const anyNoAccess = (users || []).some(noAccess);
  return `
    <div class="card settings-card settings-card--wide">
      <h2 class="card__title">${icon('personen')} ${escapeHtml(t('settings.users.title'))}</h2>
      <p class="settings-card__hint">${escapeHtml(t(session.mode === 'proxy' ? 'settings.users.hintProxy' : 'settings.users.hint'))}
        <a href="${escapeHtml(docUrl('users'))}">${escapeHtml(t('common.guide'))}</a></p>
      <!-- v3.9.0 — im Scrollrahmen wie jede Tabelle: am iPhone schob die Tabelle die Seite
           auf 415 px, und die Rollenauswahl schrumpfte auf ihren Pfeil -->
      <div class="table-wrap"><table class="table users-table">
        <thead><tr><th>${escapeHtml(t('settings.users.name'))}</th><th>${escapeHtml(t('settings.users.roleHead'))}</th><th><span class="sr-only">${escapeHtml(t('common.actions'))}</span></th></tr></thead>
        <tbody>
          ${(users || []).map(u => `<tr>
            <td>${escapeHtml(u.name)}${u.id === meId ? ` <span class="muted small">(${escapeHtml(t('settings.users.you'))})</span>` : ''}${u.no ? ` <span class="muted small" data-role="person-no">${escapeHtml(t('settings.users.no', { no: u.no }))}</span>` : ''}${noAccess(u) ? ` <span class="badge" data-role="no-access">${escapeHtml(t('settings.users.noAccess'))}</span>` : ''}</td>
            <td>${roleSelect(u)}</td>
            <td class="row-actions">
              ${u.source === 'password' ? `<button type="button" class="btn btn--sm btn--ghost" data-user-password="${escapeHtml(u.id)}">${escapeHtml(t('settings.users.resetPassword'))}</button>` : ''}
              ${u.source !== 'proxy' ? `<button type="button" class="btn btn--sm btn--ghost" data-user-rename="${escapeHtml(u.id)}" data-user-name="${escapeHtml(u.name)}"${u.source === 'password' ? ' data-user-login="1"' : ''}>${escapeHtml(t('settings.users.rename'))}</button>` : ''}
              ${noAccess(u) && session.mode === 'password' ? `<button type="button" class="btn btn--sm btn--ghost" data-user-grant="${escapeHtml(u.name)}" data-user-grant-role="${u.role === 'admin' ? 'admin' : 'member'}">${escapeHtml(t('settings.users.grant'))}</button>` : ''}
              ${u.id === meId ? '' : `<button type="button" class="btn btn--sm btn--danger btn--quiet" data-user-delete="${escapeHtml(u.id)}">${escapeHtml(t('settings.users.delete'))}</button>`}
            </td>
          </tr>`).join('')}
        </tbody>
      </table></div>
      ${anyNoAccess && session.mode === 'password' ? `<p class="settings-field__hint" data-role="no-access-hint">${escapeHtml(t('settings.users.noAccessHint'))}</p>` : ''}
      <p class="settings-field__hint" data-role="users-delete-hint">${escapeHtml(t('settings.users.deleteHint'))}</p>
      ${session.mode === 'password' ? `
      <h3 class="settings-subtitle">${escapeHtml(t('settings.users.add'))}</h3>
      <!-- v3.4.0 (N1021) — Person anlegen im Formularraster; v3.6.0 als Formular
           (Passwortfeld, Safari am iPad) -->
      <form class="fgrid" data-role="user-form" autocomplete="off">
        <div class="field field--m"><label for="user-new-name">${escapeHtml(t('settings.users.name'))}</label>
          <input class="input input--text" id="user-new-name" autocomplete="off" maxlength="40"></div>
        <div class="field field--m"><label for="user-new-password">${escapeHtml(t('settings.security.newPassword'))}</label>
          <input class="input input--text" id="user-new-password" type="password" autocomplete="new-password"></div>
        <div class="field field--xs"><label for="user-new-role">${escapeHtml(t('settings.users.roleHead'))}</label>
          <select class="select" id="user-new-role">${['member', 'admin'].map(r => `<option value="${r}">${escapeHtml(t('settings.users.role.' + r))}</option>`).join('')}</select></div>
        <div class="field field--xs"><button type="button" class="btn btn--primary" id="btn-user-add">${escapeHtml(t('settings.users.addButton'))}</button></div>
      </form>` : ''}
    </div>`;
}


export function wireUsersCard(container, session, rerender) {
  // v3.6.0 — ohne Anmeldung: zum Knopf „Anmeldung einschalten“ springen
  container.querySelector('[data-role="users-enable-login"]')?.addEventListener('click', () => {
    const btn = container.querySelector('#btn-sec-enable');
    if (!btn) return;
    btn.scrollIntoView({ block: 'center', behavior: 'smooth' });
    btn.focus({ preventScroll: true });
  });
  // v3.6.0 — Formular nie abschicken; Enter legt an
  container.querySelector('form[data-role="user-form"]')?.addEventListener('submit', (e) => {
    e.preventDefault();
    container.querySelector('#btn-user-add')?.click();
  });
  container.querySelector('#btn-user-add')?.addEventListener('click', async () => {
    const name = container.querySelector('#user-new-name').value.trim();
    const password = container.querySelector('#user-new-password').value;
    const role = container.querySelector('#user-new-role').value;
    if (password.length < 8) { toastErr(t('errors.auth.passwordTooShort', { min: 8 })); return; }
    try { await api.createUser({ name, password, role }); toastOk(t('settings.users.added', { name })); rerender(); }
    catch (e) { toastErr(e.message); }
  });
  container.querySelectorAll('[data-user-role]').forEach(sel => sel.addEventListener('change', async () => {
    try { await api.updateUser(sel.getAttribute('data-user-role'), { role: sel.value }); toastOk(t('settings.users.saved')); }
    catch (e) { toastErr(e.message); rerender(); }
  }));
  container.querySelectorAll('[data-user-password]').forEach(btn => btn.addEventListener('click', () => {
    openModal({
      title: t('settings.users.resetPassword'),
      // v3.6.0 — Passwortfeld im Formular (Safari am iPad); Enter speichert
      body: `<form data-role="pw-reset-form" autocomplete="off"><div class="field"><label for="user-reset-pw">${escapeHtml(t('settings.security.newPassword'))}</label>
        <input class="input input--text" id="user-reset-pw" type="password" autocomplete="new-password"></div></form>`,
      footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${escapeHtml(t('common.cancel'))}</button>
        <button type="button" class="btn btn--primary" data-act="ok">${escapeHtml(t('common.save'))}</button>`,
      onMount({ modalEl, close }) {
        modalEl.querySelector('form[data-role="pw-reset-form"]')?.addEventListener('submit', (e) => { e.preventDefault(); modalEl.querySelector('[data-act="ok"]')?.click(); });
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
        modalEl.querySelector('[data-act="ok"]').addEventListener('click', async () => {
          const pw = modalEl.querySelector('#user-reset-pw').value;
          if (pw.length < 8) { toastErr(t('errors.auth.passwordTooShort', { min: 8 })); return; }
          try { await api.updateUser(btn.getAttribute('data-user-password'), { password: pw }); close(true); toastOk(t('settings.users.saved')); }
          catch (e) { toastErr(e.message); }
        });
      },
    });
  }));
  // v3.4.0 (F1024) — dazu die Wahl, den Namen im Verlauf durch „ehemalige Person" zu ersetzen;
  // v3.6.0 — der Dialog sagt vorher, was an wen übergeht (components/person-delete.js)
  container.querySelectorAll('[data-user-delete]').forEach(btn => btn.addEventListener('click', () => {
    confirmPersonDelete(btn.getAttribute('data-user-delete'), rerender);
  }));
  // v3.6.0 — Person umbenennen (mit und ohne Zugang, nicht per Proxy — dort kommt
  // der Name vom Proxy): offene Termine zeigen danach den neuen Namen, Erledigtes
  // und der Verlauf behalten den vom Erledigen; mit Passwort meldet sie sich danach
  // mit dem neuen Namen an
  container.querySelectorAll('[data-user-rename]').forEach(btn => btn.addEventListener('click', () => {
    openRenameDialog(btn.getAttribute('data-user-rename'), btn.getAttribute('data-user-name') || '', btn.hasAttribute('data-user-login'), rerender);
  }));
  // v3.6.0 — Zugang für eine Person ohne Zugang: Name und Rolle in „Person hinzufügen“;
  // der neue Zugang übernimmt ihre ID und Nummer (PeopleService::adoptableId)
  container.querySelectorAll('[data-user-grant]').forEach(btn => btn.addEventListener('click', () => {
    const name = container.querySelector('#user-new-name');
    if (!name) return;
    name.value = btn.getAttribute('data-user-grant');
    const role = container.querySelector('#user-new-role');
    if (role) role.value = btn.getAttribute('data-user-grant-role') || 'member';
    container.querySelector('#user-new-password')?.focus();
  }));
}


/**
 * Dialog „Umbenennen“ (v3.6.0, unter Zugriff; v3.9.0 auch unter Haushalt & Gebäude):
 * offene Termine zeigen danach den neuen Namen, Erledigtes und der Verlauf behalten
 * den vom Erledigen; mit Passwort (`login`) meldet sich die Person danach so an.
 */
export function openRenameDialog(id, old, login, rerender) {
  openModal({
    title: t('settings.users.renameTitle', { name: old }),
    body: `<form data-role="rename-form" autocomplete="off"><div class="field"><label for="user-rename">${escapeHtml(t('settings.users.name'))}</label>
      <input class="input input--text" id="user-rename" maxlength="40" autocomplete="off" value="${escapeHtml(old)}"></div>
      <p class="settings-field__hint">${escapeHtml(t('settings.users.renameHint'))}${login ? ' ' + escapeHtml(t('settings.users.renameHintLogin')) : ''}</p></form>`,
    footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${escapeHtml(t('common.cancel'))}</button>
      <button type="button" class="btn btn--primary" data-act="ok">${escapeHtml(t('common.save'))}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
      modalEl.querySelector('form[data-role="rename-form"]')?.addEventListener('submit', (e) => { e.preventDefault(); modalEl.querySelector('[data-act="ok"]')?.click(); });
      modalEl.querySelector('[data-act="ok"]')?.addEventListener('click', async () => {
        const name = modalEl.querySelector('#user-rename')?.value.trim() || '';
        if (name === old) { close(null); return; }
        try {
          await api.updateUser(id, { name });
          close(true);
          toastOk(t('settings.users.renamed', { name }));
          rerender();
        } catch (e) { toastErr(e.message); }
      });
    },
  });
}


export function renderSecurityCard(session) {
  const mode = ['off', 'password', 'proxy'].includes(session?.mode) ? session.mode : 'off';
  const modeFixed = !!session?.mode_fixed;
  const pwFixed   = !!session?.password_fixed;
  const label = { off: 'modeOff', password: 'modePassword', proxy: 'modeProxy' }[mode];
  const pwField = (id, key, autocomplete) => `
    <div class="field">
      <label for="${id}">${t(key)}</label>
      <input class="input input--text" id="${id}" type="password" autocomplete="${autocomplete}">
    </div>`;

  // v3.5.1 — Passwortfelder stehen in einem Formular (mit Benutzername für
  // Passwortmanager): Safari am iPad erkennt sonst kein Passwortformular und
  // legt seine Vorschläge über die Felder. Abgeschickt wird nie (Knöpfe sind
  // type="button", submit wird abgefangen).
  const pwForm = (inner) => `<form class="fgrid" data-role="sec-form" autocomplete="on">
        <input type="text" name="username" autocomplete="username" value="energietracker" hidden>
        ${inner}
      </form>`;
  let body = '';
  if (mode === 'off' && !modeFixed) {
    body = `
      ${pwForm(`${pwField('sec-new', 'settings.security.newPassword', 'new-password')}
        ${pwField('sec-repeat', 'settings.security.repeatPassword', 'new-password')}
        <div class="field">
          <button type="button" class="btn btn--primary" id="btn-sec-enable">${t('settings.security.enable')}</button>
        </div>`)}
      <p class="settings-field__hint">${t('settings.security.enableHint')}</p>`;
  } else if (mode === 'password') {
    // v3.2.0 (F1023) — mit Personen ändert jede ihr Passwort unter „Mein Konto“
    body = pwFixed
      ? `<p class="settings-field__hint">${t('settings.security.passwordFixed')}</p>`
      : session?.named_login ? `<p class="settings-field__hint">${t('settings.users.passwordHint')}</p>`
      : pwForm(`${pwField('sec-current', 'settings.security.currentPassword', 'current-password')}
          ${pwField('sec-new', 'settings.security.newPassword', 'new-password')}
          ${pwField('sec-repeat', 'settings.security.repeatPassword', 'new-password')}
          <div class="field">
            <button type="button" class="btn" id="btn-sec-change">${t('settings.security.change')}</button>
          </div>`);
    body += `
      <div class="section-actions" style="margin-top:var(--sp-2)">
        <button type="button" class="btn btn--ghost" id="btn-sec-logout">${t('login.logout')}</button>
        <button type="button" class="btn btn--ghost" data-logout-everywhere>${escapeHtml(t('settings.security.logoutEverywhere'))}</button>
        ${modeFixed ? '' : `<button type="button" class="btn btn--ghost" id="btn-sec-disable">${t('settings.security.disable')}</button>`}
      </div>`;
  } else if (mode === 'proxy') {
    body = `<p class="settings-field__hint">${t('settings.security.proxyHint')}</p>`;
  }

  return `
    <div class="card settings-card" id="security-card" data-field="security">
      <h2 class="card__title">${icon('schloss')} ${t('settings.security.title')}</h2>
      <p class="settings-card__hint">${t('settings.security.hint')}</p>
      <div class="section-actions" style="align-items:center;margin-bottom:var(--sp-3)">
        <span class="tag ${mode === 'off' ? 'tag--warning' : 'tag--success'}" id="sec-mode">${t('settings.security.' + label)}</span>
        ${modeFixed ? `<span class="settings-field__hint">${t('settings.security.modeFixed')}</span>` : ''}
      </div>
      ${body}
    </div>`;
}


export function renderKeysCard(keys) {
  return `
    <div class="card settings-card settings-card--wide" id="keys-card">
      <h2 class="card__title">${icon('schluessel')} ${t('settings.security.keysTitle')}</h2>
      <p class="settings-card__hint">${t('settings.security.keysHint')}</p>
      <div id="sec-keys">${renderKeyTable(keys)}</div>
      <div class="fgrid" style="margin-top:var(--sp-3)">
        <div class="field field--m">
          <label for="key-name">${t('settings.security.keyName')}</label>
          <input class="input input--text" id="key-name" type="text" maxlength="60" autocomplete="off"
                 placeholder="${escapeHtml(t('settings.security.keyNamePlaceholder'))}">
        </div>
        <div class="field field--m">
          <label for="key-scope">${t('settings.security.keyScope')}</label>
          <select class="select" id="key-scope">
            <option value="read">${t('settings.security.scopeRead')}</option>
            <option value="admin">${t('settings.security.scopeAdmin')}</option>
            <option value="inbox">${t('settings.security.scopeInbox')}</option>
          </select>
        </div>
        <div class="field field--m">
          <button type="button" class="btn" id="btn-key-create">${t('settings.security.keyCreate')}</button>
        </div>
      </div>
      <div id="key-reveal"></div>
      <p class="settings-field__hint" data-role="keys-mcp-hint">${escapeHtml(t('settings.security.keysMcpHint'))}</p>
    </div>`;
}


/**
 * Katalogschlüssel des Bereichs eines API-Schlüssels (v3.7.0 (Paket betrieb): dazu `inbox`;
 * v3.12.0 (F1037): `mcp`, `mcp_write`, angelegt unter Integrationen → KI-Assistenten).
 */
const scopeLabelKey = (scope) => ({
  admin: 'settings.security.scopeAdmin', calendar: 'settings.security.scopeCalendar', inbox: 'settings.security.scopeInbox',
  mcp: 'settings.security.scopeMcp', mcp_write: 'settings.security.scopeMcpWrite',
}[scope] || 'settings.security.scopeRead');


function renderKeyTable(keys) {
  // v3.6.0 — in der Größe der übrigen Hinweise der Seite (bis v3.5 Grundschrift)
  if (!Array.isArray(keys) || !keys.length) return `<p class="settings-field__hint">${t('settings.security.keysNone')}</p>`;
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${t('settings.security.keyName')}</th>
      <th scope="col">${t('settings.security.keyScope')}</th>
      <th scope="col">${t('settings.security.keyCreatedAt')}</th>
      <th scope="col">${t('settings.security.keyLastUsed')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>${keys.map(k => `<tr>
      <td>${escapeHtml(k.name)}</td>
      <td>${t(scopeLabelKey(k.scope))}</td>
      <td>${stampHtml(k.created_at)}</td>
      <td>${k.last_used_at ? stampHtml(k.last_used_at) : `<span class="muted">${t('settings.security.keyNever')}</span>`}</td>
      <td style="text-align:right">
        <button type="button" class="btn btn--ghost btn--sm" data-key-revoke="${escapeHtml(k.id)}" data-key-name="${escapeHtml(k.name)}">${t('settings.security.keyRevoke')}</button>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}


// ── v3.4.0 (N1020) — Sicherheits-Check (Zugriff, System) ─────────────────
//
// Drei Prüfungen an einer Stelle: Liefert der Webserver data/ aus (der Browser
// fragt selbst nach data/meta.json — auf Apache hängt der Schutz an den
// Regeln in .htaccess)? Meldet ein Proxy den Benutzer über eine feste
// Kopfzeile? Ist die App ohne Anmeldung und ohne Hostliste erreichbar?
export function renderSecurityCheck(diag, isAdmin) {
  const notices = Array.isArray(diag?.security?.notices) ? diag.security.notices : [];
  const items = notices.map(n => {
    if (n.code === 'proxy_header') {
      return `<li class="security-check__item security-check__item--warn">${t('settings.securityCheck.proxyHeader')}</li>`;
    }
    if (n.code === 'open_without_hosts') {
      const adopt = isAdmin && n.host
        ? ` <button type="button" class="btn btn--sm btn--ghost" data-adopt-host="${escapeHtml(n.host)}">${escapeHtml(t('settings.securityCheck.adoptHost', { host: n.host }))}</button>`
        : '';
      return `<li class="security-check__item security-check__item--warn">${escapeHtml(t('settings.securityCheck.openNoHosts'))}${adopt}</li>`;
    }
    return '';
  }).join('');
  return `
    <div class="card settings-card" id="security-check" data-field="security_check">
      <h2 class="card__title">${icon('schild')} ${escapeHtml(t('settings.securityCheck.title'))}</h2>
      <p class="settings-card__hint">${escapeHtml(t('settings.securityCheck.hint'))}</p>
      <ul class="security-check">
        <li class="security-check__item" id="security-data-probe" data-state="pending">${escapeHtml(t('settings.securityCheck.dataPending'))}</li>
        ${items}
      </ul>
    </div>`;
}


export function wireSecurityCheck(container, rerender) {
  const probe = container.querySelector('#security-data-probe');
  if (probe) {
    // Antwortet der Webserver mit den Daten selbst (JSON mit Schemaversion),
    // sind data/ und damit alle Backups öffentlich — rot. 404 oder eine andere
    // Seite: gesperrt.
    fetch('data/meta.json', { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
      .then(async r => r.status === 200 && 'schema_version' in (await r.json().catch(() => ({}))))
      .catch(() => false)
      .then(exposed => {
        probe.dataset.state = exposed ? 'bad' : 'ok';
        probe.className = `security-check__item security-check__item--${exposed ? 'bad' : 'ok'}`;
        probe.innerHTML = exposed ? t('settings.securityCheck.dataExposed') : escapeHtml(t('settings.securityCheck.dataOk'));
      });
  }
  container.querySelector('[data-adopt-host]')?.addEventListener('click', async (e) => {
    const host = e.currentTarget.getAttribute('data-adopt-host') || '';
    try {
      await saveSettings({ allowed_hosts: host });
      toastOk(t('settings.securityCheck.adopted', { host }));
      rerender();
    } catch (err) { toastErr(err.message); }
  });
}
