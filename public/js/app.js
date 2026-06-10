// =====================================================================
// Energietracker v1.3.0 — Frontend entry point.
// =====================================================================

import { startRouter } from './router.js';
import { getUtilities, getSettings } from './state.js';
import { toastErr } from './components/toast.js';
import { mountThemeToggle } from './lib/theme.js';
import { buildSidebar } from './lib/sidebar.js';
import { initI18n, t, getLocale } from './lib/i18n.js';

const container = document.getElementById('view');

// Theme-Toggle binden (Button kommt aus dem SPA-Shell in index.php).
mountThemeToggle(document.getElementById('theme-toggle'));

// Server-gerenderte Shell-Strings (index.php) nach i18n-Init lokalisieren.
// index.php rendert sie bereits in der gewählten Sprache; hier halten wir
// sie synchron, falls der aktive Katalog davon abweicht (z.B. Fallback).
function applyShellStrings() {
  // <html lang> an den tatsächlich geladenen Katalog angleichen.
  document.documentElement.setAttribute('lang', getLocale());

  const tt = document.getElementById('theme-toggle');
  if (tt) {
    const lbl = t('app.themeToggle');
    tt.setAttribute('aria-label', lbl);
    tt.setAttribute('title', lbl);
  }
  const skip = document.querySelector('.skip-link');
  if (skip) skip.textContent = t('app.skipToContent');

  const nav = document.getElementById('primary-nav');
  if (nav) nav.setAttribute('aria-label', t('app.primaryNav'));
}

// N1007 — zuerst die Sprache aus dem `language`-Setting laden und den
// passenden Katalog initialisieren, BEVOR irgendeine View oder die Sidebar
// rendert (die nutzen t()). Dann Sidebar bauen, Utilities-Cache wärmen,
// Router starten.
getSettings()
  .then(s => initI18n(s?.language))
  .catch(() => initI18n('de'))
  .finally(() => {
    applyShellStrings();
    buildSidebar()
      .catch(e => { console.error('Sidebar-Aufbau fehlgeschlagen', e); })
      .finally(() => {
        getUtilities()
          .catch(e => { console.error(e); toastErr('Konnte Utilities nicht laden: ' + (e?.message || e)); })
          .finally(() => startRouter(container));
      });
  });
