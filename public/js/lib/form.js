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
    // v3.7.0 (Qualität) — Meldung und Feld immer verknüpfen: Der Fokus geht
    // gleich aufs Feld, und der Bildschirmleser liest die Meldung nur mit,
    // wenn sie in aria-describedby steht. Mehrere Formulare hatten die
    // Verknüpfung nicht im Markup.
    if (msgEl) describeBy(input, msgEl);
  }
  if (msgEl) {
    msgEl.textContent = message || '';
    msgEl.hidden = !message;
  }
  if (message && input && typeof input.focus === 'function') input.focus();
}

let msgSeq = 0;
/**
 * Hängt die id von `msgEl` an `aria-describedby` des Feldes an (vergibt eine,
 * falls nötig). Idempotent.
 *
 * @param {HTMLElement} input
 * @param {HTMLElement} msgEl
 */
export function describeBy(input, msgEl) {
  if (!msgEl.id) msgEl.id = `field-msg-${++msgSeq}`;
  const ids = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
  if (!ids.includes(msgEl.id)) input.setAttribute('aria-describedby', [...ids, msgEl.id].join(' '));
}
