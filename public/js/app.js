// =====================================================================
// Energietracker v1.3.0 — Frontend entry point.
// =====================================================================

import { startRouter } from './router.js';
import { getUtilities, getSettings, getCountries } from './state.js';
import { toastErr, toastOk, showPendingToast } from './components/toast.js';
import { mountThemeToggle, refreshThemeToggle } from './lib/theme.js';
import { buildSidebar, refreshSidebarBadges } from './lib/sidebar.js';
// v2.11.0 — Tab-Leiste, Menü und Erfassen-Blatt (hängen sich an die Ereignisse)
import './lib/mobile-nav.js';
import { initI18n, t, tp, getLocale, getLanguages, setCurrencyParams, deviceLanguage, presetLocale } from './lib/i18n.js';
import { startOutbox } from './lib/outbox.js';
import { applyUtilityTheme } from './lib/utility-theme.js';
import { api } from './api.js';
import { showLogin, logout } from './components/login.js';
import { intlLocale, setCountry, fmt } from './lib/format.js';
import { installInfoPopovers, setBillContext } from './components/info.js';
// v3.0.0 — öffentliche Demo: Sprache vom Browser, Kennzeichen und Hinweisleiste
import { DEMO, demoLanguage, mountDemoUi } from './lib/demo-mode.js';
// v3.2.0 (F1019, F1023) — Nutzungsstufe und Person dieser Sitzung
import { setSession, resolveLevel, applyLevel } from './lib/levels.js';
import { mountLevelSwitch, refreshLevelSwitch } from './lib/level-switch.js';
import { maybeStartSetup } from './components/setup-wizard.js';

// v2.13.0 — ⓘ-Erklärungen: ein Handler für alle Knöpfe
installInfoPopovers();

const container = document.getElementById('view');

// Theme-Toggle binden (Button kommt aus dem SPA-Shell in index.php).
mountThemeToggle(document.getElementById('theme-toggle'));

// Server-gerenderte Shell-Strings (index.php) nach i18n-Init lokalisieren.
// index.php rendert sie bereits in der gewählten Sprache; hier halten wir
// sie synchron, falls der aktive Katalog davon abweicht (z.B. Fallback).
function applyShellStrings() {
  // <html lang> an den tatsächlich geladenen Katalog angleichen.
  document.documentElement.setAttribute('lang', getLocale());

  refreshThemeToggle();
  refreshLevelSwitch();
  // v2.11.0 — weitere Shell-Texte tragen ihren Schlüssel in data-shell
  document.querySelectorAll('[data-shell]').forEach(el => { el.textContent = t(el.getAttribute('data-shell')); });
  document.getElementById('tabbar')?.setAttribute('aria-label', t('nav.tabbar'));
  const skip = document.querySelector('.skip-link');
  if (skip) skip.textContent = t('app.skipToContent');

  const nav = document.getElementById('primary-nav');
  if (nav) nav.setAttribute('aria-label', t('app.primaryNav'));

  const out = document.getElementById('logout-btn');
  if (out) { out.textContent = t('login.logout'); out.setAttribute('aria-label', t('login.logout')); }
}

// N1007 — zuerst die Sprache aus dem `language`-Setting laden und den
// passenden Katalog initialisieren, BEVOR irgendeine View oder die Sidebar
// rendert (die nutzen t()).
//
// v2.2.0 — Reihenfolge gestrafft: Die Utilities werden jetzt VOR der Sidebar
// geladen (sie liefern die Farbpalette, siehe applyUtilityTheme), und die
// Zähler-Badges der Seitenleiste kommen nachgelagert. Vorher wartete der erste
// Bildschirminhalt auf vier serielle Roundtrips, darunter zwei nur für die
// Zahlen an „Empfehlungen" und „Termine".
// v2.6.0 — Anmeldung (opt-in). /api/session ist ohne Anmeldung erreichbar;
// ohne Sitzung zeigt die Shell den Anmeldebildschirm statt einer Kaskade von
// 401-Fehlern. Die Sprache kommt dann aus <html lang> (index.php liest sie
// serverseitig aus den Einstellungen).
window.addEventListener('et:auth-required', () => { api.session().then(showLogin, () => showLogin()); });

// v2.6.0 — Offline-Hinweis, wenn Daten aus dem Cache des Service Workers kommen.
window.addEventListener('et:offline', (ev) => {
  const status = document.getElementById('topbar-status');
  if (!status) return;
  const when = ev.detail ? new Date(ev.detail) : null;
  const stamp = when && !isNaN(when) ? when.toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' }) : '';
  status.textContent = stamp ? t('app.offlineSince', { when: stamp }) : t('app.offline');
  status.classList.add('topbar__status--offline');
});
window.addEventListener('et:online', () => {
  const status = document.getElementById('topbar-status');
  if (status?.classList.contains('topbar__status--offline')) {
    status.textContent = '';
    status.classList.remove('topbar__status--offline');
  }
});

// v3.1.0 (I18N-29) — die ersten Anfragen schon in der Sprache dieses Geräts
presetLocale(DEMO ? demoLanguage() : deviceLanguage());

api.session()
  .catch(() => ({ mode: 'off', authenticated: true }))
  .then(async (session) => {
    if (session.mode !== 'off' && !session.authenticated) {
      await initI18n(deviceLanguage() || document.documentElement.lang || 'de');
      showLogin(session);
      return;
    }
    setSession(session);
    if (session.mode === 'password') mountLogoutButton();
    await boot(session);
  });

/** Abmelden-Knopf in der Kopfleiste (nur bei Passwort-Anmeldung). */
function mountLogoutButton() {
  const actions = document.querySelector('.topbar__actions');
  if (!actions || document.getElementById('logout-btn')) return;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.id = 'logout-btn';
  btn.className = 'topbar__btn topbar__btn--text';
  // Beim Start steht der Katalog noch nicht; applyShellStrings() beschriftet nach.
  btn.textContent = t('login.logout');
  btn.setAttribute('aria-label', btn.textContent);
  btn.addEventListener('click', () => logout());
  actions.prepend(btn);
}

// Anmeldung in den Einstellungen ein- oder ausgeschaltet.
window.addEventListener('et:session-changed', (ev) => {
  if (ev.detail?.mode === 'password') mountLogoutButton();
  else document.getElementById('logout-btn')?.remove();
});

const boot = (session = null) => Promise.all([getSettings(), getCountries()])
  .then(([s, countries]) => {
    // v2.7.0 — Länderprofil: Währung und Region vor dem ersten Rendern
    setCurrencyParams(s?.currency);
    setCountry(s?.country, countries.find(c => c.code === s?.country)?.languages);
    setBillContext(countries.find(c => c.code === s?.country) || null);
    // v3.2.0 (F1019) — Stufe vor dem ersten Rendern (CSS blendet nach ihr aus)
    applyLevel(resolveLevel(s, session));
    // v3.1.0 — eigene Sprache des Geräts vor der Standardsprache der Installation;
    // v3.2.0 (F1023) — mit Anmeldung zuerst die Sprache der Person
    return initI18n(DEMO ? demoLanguage() : (session?.user?.prefs?.language || deviceLanguage() || s?.language));
  })
  .catch(() => initI18n(DEMO ? demoLanguage() : 'de'))
  .finally(async () => {
    applyShellStrings();
    mountLevelSwitch(document.querySelector('.topbar__actions'));
    // v3.1.0 (I18N-30) — Manifest in der Sprache dieses Geräts (beim Installieren gelesen)
    document.querySelector('link[rel="manifest"]')?.setAttribute('href',
      DEMO ? `manifest-${getLocale()}.webmanifest` : `api.php/api/manifest?lang=${getLocale()}`);
    if (DEMO) {
      mountDemoUi({
        languages: Object.entries(getLanguages()).map(([code, label]) => ({ code, label })),
        locale: getLocale(),
        formatDate: (d) => fmt.date(d),
      }).catch((e) => console.error('Demo-Hinweis', e));
    }
    try {
      const utilities = await getUtilities();
      applyUtilityTheme(utilities);
    } catch (e) {
      console.error(e);
      toastErr(t('errors.view.utilitiesFailed', { msg: e?.message || e }));
    }
    try {
      await buildSidebar();
    } catch (e) {
      console.error('Sidebar-Aufbau fehlgeschlagen', e);
    }
    startRouter(container);
    // v2.11.0 — Meldung von vor einem Neustart (Import, Demo-Daten)
    showPendingToast();
    // v3.2.0 (F1018) — Einrichtungsassistent: Neuinstallation oder Demo ohne Haushalt
    maybeStartSetup(session).catch((e) => console.error('Einrichtung', e));
    // Badges nachreichen — sie sind Beiwerk und dürfen den ersten Inhalt
    // nicht aufhalten.
    refreshSidebarBadges().catch(() => {});
    // v3.1.0 (Paket H2, FE-32) — offline erfasste Stände nachsenden (online,
    // Server antwortet wieder, App-Start, Seite wieder sichtbar)
    if (!DEMO) {
      startOutbox(api, (r) => {
        if (r.sent) toastOk(tp('readingsEntry.queue.sent', r.sent));
        if (r.failed || r.conflicts) toastErr(t('readingsEntry.queue.attention'));
      });
    }
    // v2.8.0 (Review CALC-08) — `weather_auto_fill` wirkt jetzt: Temperaturen
    // im Hintergrund nachladen, höchstens einmal am Tag (der Server prüft das
    // selbst). Ohne Netz oder mit ausgeschalteter Einstellung passiert nichts.
    // In der Demo gibt es keinen Server, der abgleichen könnte.
    if (!DEMO) {
      getSettings()
        .then(s => { if (s?.weather_auto_fill !== false) return api.syncOpenMeteo({ auto: 1 }); })
        .catch(() => {});
    }
  });
