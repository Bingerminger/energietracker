// =====================================================================
// Formular-Helfer (v2.5.3).
//
// Ein Fehler gehört an das Feld, nicht nur in einen Toast: Der Toast steht
// unten rechts, auf dem iPhone oft unter der Tastatur, und Screenreader
// erfahren nicht, welches Feld gemeint ist.
// =====================================================================

/**
 * Markiert ein Feld als ungültig (oder hebt die Markierung auf) und zeigt die
 * Meldung im zugehörigen Element (`aria-describedby`).
 *
 * @param {HTMLElement|null} input
 * @param {HTMLElement|null} msgEl
 * @param {string|null} message  null = Fehler aufheben
 */
export function showFieldError(input, msgEl, message) {
  if (input) {
    input.classList.toggle('invalid', !!message);
    if (message) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
  }
  if (msgEl) {
    msgEl.textContent = message || '';
    msgEl.hidden = !message;
  }
  if (message && input && typeof input.focus === 'function') input.focus();
}
