// =====================================================================
// Energietracker v1.2.0 — Frontend entry point.
// =====================================================================

import { startRouter } from './router.js';
import { getUtilities } from './state.js';
import { toastErr } from './components/toast.js';
import { mountThemeToggle } from './lib/theme.js';

const container = document.getElementById('view');

// Theme-Toggle binden (Button kommt aus dem SPA-Shell in index.php).
mountThemeToggle(document.getElementById('theme-toggle'));

// Warm utilities cache, then start router.
getUtilities()
  .catch(e => { console.error(e); toastErr('Konnte Utilities nicht laden: ' + (e?.message || e)); })
  .finally(() => startRouter(container));
