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

// v2.11.0 (Review FE-09/UI-16) — Die Zurück-Taste (iPhone: Wischgeste)
// schließt den obersten Dialog, statt die Seite darunter zu verlassen. Jeder
// Dialog legt dazu einen History-Eintrag ohne neue Adresse an; `popstate`
// schließt ihn. Schließt der Dialog anders (Knopf, Escape), nimmt er seinen
// Eintrag mit `history.back()` zurück — dieses eigene `popstate` wird
// übersprungen. Bei einer Navigation bleibt der Eintrag stehen: Ein
// `history.back()` nach dem Adresswechsel machte die Navigation rückgängig.
// Deshalb gilt auch: Code, der einen Dialog schließt und SOFORT navigiert,
// muss `close(value, 'navigation')` aufrufen.
let modalEntries = 0;
let ownPops = 0;
if (typeof window !== 'undefined') {
  window.addEventListener('popstate', () => {
    if (ownPops > 0) { ownPops--; return; }
    if (modalEntries > 0 && openCloses.size) {
      modalEntries--;
      [...openCloses].pop()?.(null, 'history');
    }
  });
}

/** Schließt alle offenen Dialoge (obersten zuerst). */
export function closeAllModals(reason = 'navigation') {
  [...openCloses].reverse().forEach(close => close(null, reason));
}

// v3.7.0 (Qualität) — Was die Tabulator-Taste in einem Dialog erreicht. Bis
// v3.6 fehlten <summary> (aufklappbare Hilfen im Vertragsdialog), <iframe> und
// <object>; Knöpfe mit tabindex="-1" zählten dagegen mit. War ein solches
// Element das erste oder letzte, sprang die Fokusfalle daran vorbei.
const TABBABLE = [
  'a[href]', 'area[href]', 'button', 'input:not([type="hidden"])', 'select', 'textarea',
  'summary', 'iframe', 'object', 'embed', 'audio[controls]', 'video[controls]',
  '[contenteditable]:not([contenteditable="false"])', '[tabindex]',
].join(', ');

/** Dargestellt? Ohne Boxen (display:none, zugeklappte <details>) nicht erreichbar. */
function rendered(el) {
  if (el === document.activeElement) return true;
  if (!el.getClientRects || el.getClientRects().length === 0) return false;
  try { return getComputedStyle(el).visibility !== 'hidden'; } catch { return true; }
}

/**
 * Die Elemente eines Containers in Tab-Reihenfolge, wie der Browser sie mit
 * der Tabulator-Taste erreicht: ohne gesperrte, ohne tabindex="-1", ohne
 * unsichtbare; von einer Gruppe gleichnamiger Optionsfelder nur das gewählte
 * (sonst das erste). Exportiert für die Prüfung (tests/a11y.test.mjs).
 *
 * @param {Element} container
 * @returns {HTMLElement[]}
 */
export function tabbables(container) {
  const radios = new Map();
  const out = [];
  for (const el of container.querySelectorAll(TABBABLE)) {
    if (el.disabled || el.closest('[inert], fieldset[disabled]')) continue;
    const ti = el.getAttribute('tabindex');
    if (ti !== null && Number(ti) < 0) continue;
    if (el.tagName === 'SUMMARY' && el.parentElement?.tagName !== 'DETAILS') continue;
    if (!rendered(el)) continue;
    if (el.type === 'radio' && el.name) {
      const group = radios.get(el.name);
      if (group) {
        if (el.checked && !group.checked) { out[group.index] = el; group.checked = true; }
        continue;
      }
      radios.set(el.name, { index: out.length, checked: el.checked });
    }
    out.push(el);
  }
  return out;
}

/** Eingabe, die am Telefon die Bildschirmtastatur öffnet. */
function opensKeyboard(el) {
  if (el.tagName === 'TEXTAREA' || el.isContentEditable) return true;
  if (el.tagName !== 'INPUT') return false;
  return !['checkbox', 'radio', 'button', 'submit', 'reset', 'file', 'range', 'color', 'image'].includes(el.type);
}

const attrValue = (v) => String(v).replace(/["\\]/g, '\\$&');
/**
 * Ein Selektor, der den Auslöser nach einem Neuaufbau der Ansicht wiederfindet
 * (gleiche id bzw. gleiche data-Attribute) — oder null.
 */
function triggerLocator(el) {
  if (!el || el === document.body || !el.tagName) return null;
  if (el.id) return `[id="${attrValue(el.id)}"]`;
  const attrs = [...el.attributes].filter(a => a.name.startsWith('data-') && a.value !== '');
  if (!attrs.length) return null;
  return el.tagName.toLowerCase() + attrs.map(a => `[${a.name}="${attrValue(a.value)}"]`).join('');
}

/**
 * Öffnet einen Dialog.
 *
 * @param {object} o
 * @param {string} o.title
 * @param {string|Node} o.body
 * @param {string} [o.footer]
 * @param {Function|null} [o.onMount]  setzt sie den Fokus in den Dialog, bleibt er dort
 * @param {'sm'|'md'|'lg'} [o.size]
 * @param {'dialog'|'alertdialog'} [o.role]  alertdialog für Rückfragen (v3.7.0)
 * @param {string|null} [o.describedBy]  id im Dialog, die der Bildschirmleser beim Öffnen vorliest
 */
export function openModal({ title, body, footer = '', onMount = null, size = 'md', role = 'dialog', describedBy = null }) {
  const root = document.getElementById('modal-root');
  // A11y (N1009): Fokus merken, damit er beim Schließen zurückkehrt.
  const prevFocus = document.activeElement;
  const prevLocator = triggerLocator(prevFocus);
  const titleId = `modal-title-${++modalSeq}`;
  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop';
  // v3.7.0 (Qualität) — Der Titel ist eine Überschrift (Bildschirmleser
  // springen per Überschriften-Navigation hinein), Rückfragen sind ein
  // alertdialog mit Beschreibung.
  backdrop.innerHTML = `
    <div class="modal modal--${size}" role="${role === 'alertdialog' ? 'alertdialog' : 'dialog'}" aria-modal="true" aria-labelledby="${titleId}"${describedBy ? ` aria-describedby="${escapeHtml(describedBy)}"` : ''}>
      <div class="modal__head">
        <h2 class="modal__title" id="${titleId}">${escapeHtml(title)}</h2>
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
  let pushed = false;
  try { history.pushState(history.state, ''); pushed = true; modalEntries++; } catch { /* ohne History: nur Knöpfe */ }

  function close(value, reason = 'ui') {
    if (closed) return;
    closed = true;
    openCloses.delete(close);
    if (pushed && reason !== 'history') {
      modalEntries = Math.max(0, modalEntries - 1);
      if (reason !== 'navigation') { ownPops++; history.back(); }
    }
    backdrop.remove();
    document.removeEventListener('keydown', onKey);
    if (appEl && !hadInert) appEl.removeAttribute('inert');
    document.body.style.overflow = prevOverflow;
    resolveClose(value);
    restoreFocus(reason);
  }

  // Fokus auf das auslösende Element zurückgeben. v3.7.0 (Qualität) — Ist es
  // nicht mehr im Dokument, oder baut die Ansicht nach dem Speichern ihre
  // Liste neu auf (der Knopf wird ersetzt, der Fokus fiele auf <body>), gilt
  // der gleiche Knopf der neuen Liste (gleiche id bzw. data-Attribute) —
  // sonst der Inhaltsbereich. Bisher landete der Fokus dann am Seitenanfang.
  function restoreFocus(reason) {
    if (!prevFocus || prevFocus === document.body || typeof prevFocus.focus !== 'function') return;
    const find = () => (prevLocator && [...document.querySelectorAll(prevLocator)].find(el => !root.contains(el))) || null;
    let target = document.contains(prevFocus) ? prevFocus : find();
    if (!target) {
      // Rückfrage über einem Formular: zurück in den Dialog darunter
      const below = root.lastElementChild?.querySelector('.modal');
      const view = document.getElementById('view');
      if (below) below.focus();
      else if (view && reason !== 'navigation') view.focus({ preventScroll: true });
      return;
    }
    target.focus();
    if (reason === 'navigation' || !prevLocator || typeof MutationObserver === 'undefined') return;
    const lost = () => !target.isConnected && (!document.activeElement || document.activeElement === document.body);
    const watch = new MutationObserver(() => {
      if (root.lastElementChild) { watch.disconnect(); return; }   // ein neuer Dialog übernimmt
      if (!lost()) return;
      const again = find();
      if (again) { target = again; again.focus(); }
    });
    watch.observe(document.body, { childList: true, subtree: true });
    setTimeout(() => watch.disconnect(), 2500);
  }

  function onKey(e) {
    // v2.5.3 — Nur der oberste Dialog reagiert. Eine Rückfrage über einem
    // offenen Formular (z. B. „Stand liegt unter dem letzten") schloss per
    // Escape sonst auch das Formular darunter.
    if (root.lastElementChild !== backdrop) return;
    if (e.key === 'Escape') { close(null); return; }
    // Focus-Trap: Tab/Shift+Tab zykeln innerhalb des Modals. v3.7.0 — auch
    // wenn der Fokus gerade außerhalb liegt (Klick auf eine leere Fläche).
    if (e.key === 'Tab') {
      const items = tabbables(modalEl);
      if (items.length === 0) { e.preventDefault(); modalEl.focus(); return; }
      const first = items[0];
      const last  = items[items.length - 1];
      const active = document.activeElement;
      const inside = modalEl.contains(active) && active !== modalEl;
      if (e.shiftKey && (active === first || !inside)) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && (active === last || !inside)) {
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

  // Der Dialog selbst ist fokussierbar (Rückfall und Ziel der Fokusfalle),
  // steht aber nicht in der Tab-Reihenfolge.
  modalEl.setAttribute('tabindex', '-1');

  if (typeof onMount === 'function') onMount({ modalEl, bodyEl, close });

  initialFocus();

  return { close, closedPromise, modalEl, bodyEl };

  // Fokus beim Öffnen. v3.7.0 (Qualität) — Bisher landete er auf „×“: Das
  // erste fokussierbare Element im Dokument ist der Schließen-Knopf im Kopf,
  // und er überschrieb sogar den Fokus, den onMount gesetzt hatte (etwa auf
  // das erste Feld des Einrichtungsassistenten). Jetzt der Reihe nach:
  //   1. was onMount fokussiert hat,
  //   2. ein Element mit autofocus,
  //   3. das erste Feld im Inhalt,
  //   4. der erste Knopf der Fußleiste (bei Rückfragen „Abbrechen“),
  //   5. sonst das erste erreichbare Element, zuletzt der Dialog selbst.
  // Am Touchscreen kein Textfeld: Die Bildschirmtastatur verdeckte sonst den
  // Dialog, bevor man ihn gelesen hat — dort bekommt der Dialog den Fokus,
  // der Bildschirmleser liest seinen Titel.
  function initialFocus() {
    const active = document.activeElement;
    if (active && active !== modalEl && modalEl.contains(active)) return;
    const items = tabbables(modalEl);
    const foot = modalEl.querySelector('.modal__foot');
    const auto = items.find(el => el.hasAttribute('autofocus') || el.hasAttribute('data-autofocus'));
    let target = auto
      || items.find(el => bodyEl.contains(el) && el.matches('input, select, textarea'))
      || items.find(el => foot?.contains(el))
      || items.find(el => !el.classList.contains('modal__close'))
      || items[0] || null;
    let touch = false;
    try { touch = !!window.matchMedia?.('(pointer: coarse)')?.matches; } catch { /* ohne matchMedia: Maus */ }
    if (target && touch && opensKeyboard(target)) target = null;
    (target || modalEl).focus();
  }
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
let confirmSeq = 0;
// v3.7.0 (cloud) — `detail`: ein zweiter, leiserer Absatz unter der Frage (etwa
// der Hinweis, was ein Cloud-Dienst zu sehen bekommt)
export function confirmModal({ title, message, detail = '', confirmLabel = 'OK', danger = false }) {
  return new Promise(resolve => {
    // v3.7.0 (Qualität) — alertdialog: Der Bildschirmleser liest beim Öffnen
    // Titel UND Frage; der Fokus steht auf „Abbrechen“ (erster Knopf der
    // Fußleiste), ein versehentliches Enter löscht nichts.
    const msgId = `modal-message-${++confirmSeq}`;
    const ctrl = openModal({
      title: title ?? t('common.confirm'),
      role: 'alertdialog',
      describedBy: detail ? `${msgId} ${msgId}-detail` : msgId,
      body: `<p id="${msgId}">${escapeHtml(message)}</p>${detail ? `<p class="muted" id="${msgId}-detail" data-role="confirm-detail">${escapeHtml(detail)}</p>` : ''}`,
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
