// =====================================================================
// v3.12.0 (m4b) — Menü an einer Zeile: Ein Knopf je Aufgabe steht sichtbar an der Zeile, die übrigen
// Aufgaben stehen hier. Muster „Aufklappen“ (kein role="menu"): ein Knopf mit aria-expanded und
// aria-controls, darunter die Liste mit gewöhnlichen Knöpfen und Links, Tab läuft hindurch.
// Escape schließt und gibt den Fokus an den Knopf zurück, ein Klick daneben oder auf einen Eintrag
// schließt ebenfalls. Höchstens ein Menü ist offen.
// =====================================================================
import { escapeHtml } from './format.js';
import { t } from './i18n.js';
import { icon } from './icons.js';

let seq = 0;

/**
 * @param {{label: string, items: string[]}} o  `label` für Vorleser (etwa „Weitere Aufgaben: Gas“),
 *   `items` als fertiges HTML (Knöpfe, Links); leere fallen weg, ohne Eintrag kein Menü
 */
export function rowMenuHtml({ label, items }) {
  const list = (items || []).map(s => String(s || '').trim()).filter(Boolean);
  if (!list.length) return '';
  const id = `zeilenmenue-${++seq}`;
  return `<div class="zeilenmenue" data-role="row-menu">
      <button type="button" class="btn btn--ghost btn--sm zeilenmenue__knopf" aria-expanded="false" aria-controls="${id}" aria-label="${escapeHtml(label)}">${icon('menue', { size: 16 })} ${escapeHtml(t('rowMenu.more'))}</button>
      <div class="zeilenmenue__liste" id="${id}" data-role="row-menu-list" hidden>${list.join('')}</div>
    </div>`;
}

function setOpen(menu, on) {
  const btn = menu.querySelector('.zeilenmenue__knopf');
  const list = menu.querySelector('.zeilenmenue__liste');
  if (!btn || !list) return;
  btn.setAttribute('aria-expanded', on ? 'true' : 'false');
  list.hidden = !on;
  menu.classList.toggle('zeilenmenue--offen', on);
}

function closeAll(except = null) {
  document.querySelectorAll('.zeilenmenue--offen').forEach(m => { if (m !== except) setOpen(m, false); });
}

let docBound = false;
function bindDocument() {
  if (docBound) return;
  docBound = true;
  document.addEventListener('click', (e) => {
    if (!e.target.closest?.('.zeilenmenue')) closeAll();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = document.querySelector('.zeilenmenue--offen');
    if (!open) return;
    setOpen(open, false);
    open.querySelector('.zeilenmenue__knopf')?.focus();
  });
}

/** Menüs unter `root` verdrahten (nach jedem Zeichnen; doppelt gebunden wird nichts). */
export function bindRowMenus(root) {
  bindDocument();
  root?.querySelectorAll('.zeilenmenue').forEach(menu => {
    if (menu.dataset.bound) return;
    menu.dataset.bound = '1';
    const btn = menu.querySelector('.zeilenmenue__knopf');
    btn?.addEventListener('click', () => {
      const on = btn.getAttribute('aria-expanded') !== 'true';
      closeAll(menu);
      setOpen(menu, on);
      if (on) menu.querySelector('.zeilenmenue__liste button, .zeilenmenue__liste a')?.focus();
    });
    // ein Eintrag gewählt: zu (sein eigener Klick läuft trotzdem)
    menu.querySelector('.zeilenmenue__liste')?.addEventListener('click', (e) => {
      if (e.target.closest('button, a')) setOpen(menu, false);
    });
  });
}
