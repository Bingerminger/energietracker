// =====================================================================
// Energietracker v1.3.0 — Frontend entry point.
// =====================================================================

import { startRouter } from './router.js';
import { getUtilities, getSettings } from './state.js';
import { toastErr } from './components/toast.js';
import { mountThemeToggle } from './lib/theme.js';
import { buildSidebar, refreshSidebarBadges } from './lib/sidebar.js';
import { initI18n, t, getLocale } from './lib/i18n.js';
import { applyUtilityTheme } from './lib/utility-theme.js';

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
// rendert (die nutzen t()).
//
// v2.2.0 — Reihenfolge gestrafft: Die Utilities werden jetzt VOR der Sidebar
// geladen (sie liefern die Farbpalette, siehe applyUtilityTheme), und die
// Zähler-Badges der Seitenleiste kommen nachgelagert. Vorher wartete der erste
// Bildschirminhalt auf vier serielle Roundtrips, darunter zwei nur für die
// Zahlen an „Empfehlungen" und „Termine".
getSettings()
  .then(s => initI18n(s?.language))
  .catch(() => initI18n('de'))
  .finally(async () => {
    applyShellStrings();
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
    // Badges nachreichen — sie sind Beiwerk und dürfen den ersten Inhalt
    // nicht aufhalten.
    refreshSidebarBadges().catch(() => {});
  });
