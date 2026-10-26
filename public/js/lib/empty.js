// =====================================================================
// v3.9.0 (Paket oberflaeche) — ein Leerzustand für Listen und Seiten.
//
// Bis v3.8 sah „noch nichts da“ auf fast jeder Seite anders aus: ein blauer
// Hinweisbalken mit Knopf darunter (Termine), ein graues Emoji mit großer
// Überschrift (Verträge, Ablesungen), ein Satz ohne Weg weiter. Jetzt ein
// Baustein: Symbol im ruhigen Kreis (Zier, aria-hidden), kurzer Titel, ein Satz,
// was hier entsteht, und höchstens eine Aktion. Aussehen: .empty in app.css.
// =====================================================================

import { escapeHtml as esc } from './format.js';

/**
 * @param {{icon?: string, title?: string, text?: string, action?: string, role?: string, card?: boolean}} o
 *        action: fertiges HTML eines Knopfs oder Links (höchstens einer)
 */
export function emptyHtml({ icon = '', title = '', text = '', action = '', role = '', card = true } = {}) {
  return `<div class="${card ? 'card ' : ''}empty"${role ? ` data-role="${esc(role)}"` : ''}>
    ${icon ? `<div class="empty-icon" aria-hidden="true">${esc(icon)}</div>` : ''}
    ${title ? `<h2 class="empty__title">${esc(title)}</h2>` : ''}
    ${text ? `<p>${esc(text)}</p>` : ''}
    ${action}
  </div>`;
}
