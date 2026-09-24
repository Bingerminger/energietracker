// =====================================================================
// Modal component. Opens an overlay with given title/body/buttons.
// =====================================================================
import { escapeHtml } from '../lib/format.js';
import { t } from '../lib/i18n.js';

/**
 * Open a modal with arbitrary body (string HTML or DOM node).
 * Returns a controller object with .close() and a promise via onClose().
 */
// Eindeutige IDs für aria-labelledby (mehrere Modals nacheinander möglich).
let modalSeq = 0;
// v2.5.3 (FE-09) — offene Dialoge, damit der Router sie beim Seitenwechsel
// schließen kann. Sonst blieb ein Dialog nach „Zurück" verwaist offen, #app
// blieb `inert`, und Speichern rendert in eine fremde Ansicht.
const openCloses = new Set();

/** Schließt alle offenen Dialoge (obersten zuerst). */
export function closeAllModals() {
  [...openCloses].reverse().forEach(close => close(null));
}

export function openModal({ title, body, footer = '', onMount = null, size = 'md' }) {
  const root = document.getElementById('modal-root');
  // A11y (N1009): Fokus merken, damit er beim Schließen zurückkehrt.
  const prevFocus = document.activeElement;
  const titleId = `modal-title-${++modalSeq}`;
  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  backdrop.innerHTML = `
    <div class="modal modal--${size}" role="dialog" aria-modal="true" aria-labelledby="${titleId}">
      <div class="modal__head">
        <div class="modal__title" id="${titleId}">${escapeHtml(title)}</div>
        <button type="button" class="modal__close" aria-label="${t('common.close')}">×</button>
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

  // A11y (v2.2.0): Der Rest der Seite wird inert. Der Fokus-Trap unten fängt
  // nur die Tab-Taste — der virtuelle Cursor eines Screenreaders wanderte
  // weiterhin frei durch die Inhalte hinter dem Dialog. `inert` nimmt den
  // Bereich aus dem Accessibility-Tree UND aus der Tab-Reihenfolge.
  const appEl = document.getElementById('app');
  const hadInert = appEl?.hasAttribute('inert');
  if (appEl && !hadInert) appEl.setAttribute('inert', '');
  // Hintergrund-Scroll sperren, damit das Rad nicht die Seite unter dem
  // Dialog bewegt.
  const prevOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';

  let resolveClose;
  const closedPromise = new Promise(r => { resolveClose = r; });
  let closed = false;

  function close(value) {
    if (closed) return;
    closed = true;
    openCloses.delete(close);
    backdrop.remove();
    document.removeEventListener('keydown', onKey);
    if (appEl && !hadInert) appEl.removeAttribute('inert');
    document.body.style.overflow = prevOverflow;
    resolveClose(value);
    // Fokus auf das auslösende Element zurückgeben (sofern noch im DOM).
    if (prevFocus && typeof prevFocus.focus === 'function' && document.contains(prevFocus)) {
      prevFocus.focus();
    }
  }

  // Sichtbar fokussierbare Elemente innerhalb des Modals.
  const focusableSel = 'a[href], button:not([disabled]), input:not([disabled]), ' +
    'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const focusable = () => [...modalEl.querySelectorAll(focusableSel)]
    .filter(el => el.offsetParent !== null || el === document.activeElement);

  function onKey(e) {
    // v2.5.3 — Nur der oberste Dialog reagiert. Eine Rückfrage über einem
    // offenen Formular (z. B. „Stand liegt unter dem letzten") schloss per
    // Escape sonst auch das Formular darunter.
    if (root.lastElementChild !== backdrop) return;
    if (e.key === 'Escape') { close(null); return; }
    // Focus-Trap: Tab/Shift+Tab zykeln innerhalb des Modals.
    if (e.key === 'Tab') {
      const items = focusable();
      if (items.length === 0) { e.preventDefault(); return; }
      const first = items[0];
      const last  = items[items.length - 1];
      const active = document.activeElement;
      if (e.shiftKey && (active === first || !modalEl.contains(active))) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && active === last) {
        e.preventDefault(); first.focus();
      }
    }
  }
  document.addEventListener('keydown', onKey);
  openCloses.add(close);

  backdrop.querySelector('.modal__close').addEventListener('click', () => close(null));
  // v2.5.3 — Nur schließen, wenn Maus-Druck UND -Freigabe auf dem Hintergrund
  // liegen. Wer im Feld Text markiert und die Maus dabei über den Rand zieht,
  // löst ein click-Ereignis am gemeinsamen Vorfahren aus — bisher schloss das
  // den Dialog, und die Eingaben eines langen Vertragsformulars waren weg.
  let downOnBackdrop = false;
  backdrop.addEventListener('mousedown', (e) => { downOnBackdrop = e.target === backdrop; });
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop && downOnBackdrop) close(null);
    downOnBackdrop = false;
  });

  if (typeof onMount === 'function') onMount({ modalEl, bodyEl, close });

  // Initial-Fokus in das Modal verschieben (erstes Feld, sonst der Dialog selbst).
  const initial = focusable()[0];
  if (initial) initial.focus();
  else { modalEl.setAttribute('tabindex', '-1'); modalEl.focus(); }

  return { close, closedPromise, modalEl, bodyEl };
}

/**
 * v2.5.3 — Klick-Handler für „Speichern", der den Knopf sperrt, bis die
 * Aktion fertig ist. Vorher löste ein Doppelklick zwei POSTs aus, und das
 * Backend legte zwei Ablesungen am selben Tag an.
 *
 *   saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => { … }));
 *
 * @param {HTMLButtonElement|null} btn
 * @param {() => Promise<unknown>|unknown} handler
 */
export function guardSubmit(btn, handler) {
  let running = false;
  return async (event) => {
    if (running) { event?.preventDefault?.(); return; }
    running = true;
    if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
    try {
      await handler(event);
    } finally {
      running = false;
      if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); }
    }
  };
}

/**
 * Confirm dialog — returns a Promise<boolean>.
 */
export function confirmModal({ title, message, confirmLabel = 'OK', danger = false }) {
  return new Promise(resolve => {
    const ctrl = openModal({
      title: title ?? t('common.confirm'),
      body: `<p>${escapeHtml(message)}</p>`,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
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
