// =====================================================================
// Anmeldebildschirm (v2.6.0, opt-in).
//
// Erscheint, wenn die Anmeldung eingeschaltet ist und keine Sitzung besteht
// — beim Start (app.js fragt /api/session) oder wenn eine Sitzung abläuft
// (api.js meldet `et:auth-required`). Nach erfolgreicher Anmeldung lädt die
// Seite neu; das Cookie gilt dann für alle weiteren Anfragen.
// =====================================================================
import { api } from '../api.js';
import { t } from '../lib/i18n.js';
import { escapeHtml } from '../lib/format.js';

export function showLogin() {
  if (document.getElementById('login-screen')) return;
  const app = document.getElementById('app');
  app?.setAttribute('inert', '');

  const el = document.createElement('div');
  el.id = 'login-screen';
  el.className = 'login-screen';
  el.innerHTML = `
    <form class="login-card" novalidate aria-labelledby="login-title">
      <div class="login-card__brand"><span class="topbar__logo" aria-hidden="true"></span> ENERGIETRACKER</div>
      <h1 id="login-title">${escapeHtml(t('login.title'))}</h1>
      <label class="login-card__label" for="login-pw">${escapeHtml(t('login.password'))}</label>
      <input id="login-pw" class="input input--text" type="password" autocomplete="current-password" required>
      <div class="field-error" id="login-msg" role="alert" hidden></div>
      <button type="submit" class="btn btn--primary">${escapeHtml(t('login.submit'))}</button>
      <p class="muted login-card__hint">${escapeHtml(t('login.forgot'))}</p>
    </form>`;
  document.body.appendChild(el);

  const form = el.querySelector('form');
  const input = el.querySelector('#login-pw');
  const msg = el.querySelector('#login-msg');
  const btn = el.querySelector('button[type="submit"]');
  input.focus();

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (btn.disabled) return;
    btn.disabled = true;
    msg.hidden = true;
    try {
      await api.login(input.value);
      location.reload();
    } catch (e) {
      msg.textContent = e.message;
      msg.hidden = false;
      input.classList.add('invalid');
      input.select();
      btn.disabled = false;
    }
  });
}

/** Abmelden: Sitzung beenden, Offline-Daten dieses Browsers verwerfen, neu laden. */
export async function logout() {
  try { await api.logout(); } catch { /* auch ohne Antwort abmelden */ }
  try {
    const keys = await caches.keys();
    await Promise.all(keys.filter(k => k.startsWith('et-runtime-')).map(k => caches.delete(k)));
  } catch { /* keine Cache-API */ }
  location.reload();
}
