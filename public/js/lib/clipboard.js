// =====================================================================
// Energietracker v2.11.0 — Kopieren in die Zwischenablage (Review FE-24)
//
// `navigator.clipboard` gibt es nur in sicheren Kontexten (HTTPS oder
// localhost). Im typischen Betrieb unter http://<NAS>:<Port> meldete die App
// deshalb bei Token, YAML und Jahresverbrauch immer „Zwischenablage nicht
// verfügbar". Der Rückfall kopiert über ein unsichtbares Textfeld und
// `execCommand('copy')` — veraltet, aber in allen Browsern vorhanden.
// =====================================================================

/**
 * @param {string} text
 * @returns {Promise<boolean>} kopiert?
 */
export async function copyText(text) {
  if (navigator.clipboard?.writeText && window.isSecureContext !== false) {
    try { await navigator.clipboard.writeText(text); return true; } catch { /* Rückfall */ }
  }
  const previous = document.activeElement;
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.cssText = 'position:fixed;top:-1000px;left:0;opacity:0;';
  document.body.appendChild(ta);
  ta.select();
  ta.setSelectionRange(0, text.length);
  let ok = false;
  try { ok = document.execCommand('copy'); } catch { ok = false; }
  ta.remove();
  if (previous && typeof previous.focus === 'function' && document.contains(previous)) previous.focus();
  return ok;
}
