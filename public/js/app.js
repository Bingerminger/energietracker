// =====================================================================
// Energietracker v1.1.0 — Frontend entry point.
// =====================================================================

import { startRouter } from './router.js';
import { getUtilities } from './state.js';
import { toastErr } from './components/toast.js';

const container = document.getElementById('view');

// Warm utilities cache, then start router.
getUtilities()
  .catch(e => { console.error(e); toastErr('Konnte Utilities nicht laden: ' + (e?.message || e)); })
  .finally(() => startRouter(container));
