// =====================================================================
// Modal component. Opens an overlay with given title/body/buttons.
// =====================================================================
import { escapeHtml } from '../lib/format.js';

/**
 * Open a modal with arbitrary body (string HTML or DOM node).
 * Returns a controller object with .close() and a promise via onClose().
 */
export function openModal({ title, body, footer = '', onMount = null, size = 'md' }) {
  const root = document.getElementById('modal-root');
  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  backdrop.innerHTML = `
    <div class="modal modal--${size}" role="dialog" aria-modal="true">
      <div class="modal__head">
        <div class="modal__title">${escapeHtml(title)}</div>
        <button type="button" class="modal__close" aria-label="Schließen">×</button>
      </div>
      <div class="modal__body"></div>
      ${footer ? `<div class="modal__foot">${footer}</div>` : ''}
    </div>
  `;
  const modalEl = backdrop.querySelector('.modal');
  const bodyEl  = backdrop.querySelector('.modal__body');
  if (body instanceof Node) bodyEl.appendChild(body);
  else bodyEl.innerHTML = body;
  root.appendChild(backdrop);

  let resolveClose;
  const closedPromise = new Promise(r => { resolveClose = r; });

  function close(value) {
    backdrop.remove();
    document.removeEventListener('keydown', onKey);
    resolveClose(value);
  }

  function onKey(e) { if (e.key === 'Escape') close(null); }
  document.addEventListener('keydown', onKey);

  backdrop.querySelector('.modal__close').addEventListener('click', () => close(null));
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(null); });

  if (typeof onMount === 'function') onMount({ modalEl, bodyEl, close });

  return { close, closedPromise, modalEl, bodyEl };
}

/**
 * Confirm dialog — returns a Promise<boolean>.
 */
export function confirmModal({ title = 'Bestätigen', message, confirmLabel = 'OK', danger = false }) {
  return new Promise(resolve => {
    const ctrl = openModal({
      title,
      body: `<p>${escapeHtml(message)}</p>`,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
        <button type="button" class="btn ${danger ? 'btn--danger' : 'btn--primary'}" data-act="ok">${escapeHtml(confirmLabel)}</button>
      `,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => { close(false); resolve(false); });
        modalEl.querySelector('[data-act="ok"]').addEventListener('click',     () => { close(true);  resolve(true); });
      }
    });
    ctrl.closedPromise.then(v => resolve(v === true));
  });
}
