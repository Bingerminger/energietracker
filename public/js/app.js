// =====================================================================
// Energietracker v1.3.0 — Frontend entry point.
// =====================================================================

import { startRouter } from './router.js';
import { getUtilities } from './state.js';
import { toastErr } from './components/toast.js';
import { mountThemeToggle } from './lib/theme.js';
import { buildSidebar } from './lib/sidebar.js';

const container = document.getElementById('view');

// Theme-Toggle binden (Button kommt aus dem SPA-Shell in index.php).
mountThemeToggle(document.getElementById('theme-toggle'));

// Sidebar dynamisch aus active_utilities + neuen Views bauen, dann
// Utilities-Cache wärmen und Router starten.
buildSidebar()
  .catch(e => { console.error('Sidebar-Aufbau fehlgeschlagen', e); })
  .finally(() => {
    getUtilities()
      .catch(e => { console.error(e); toastErr('Konnte Utilities nicht laden: ' + (e?.message || e)); })
      .finally(() => startRouter(container));
  });
