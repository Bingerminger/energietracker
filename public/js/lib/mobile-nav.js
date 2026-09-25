// =====================================================================
// Energietracker v2.11.0 — Navigation auf dem iPhone (Review UI-05)
//
// Bis v2.10 stand die komplette Seitenleiste auf schmalen Bildschirmen als
// 500 px hoher Block über jedem Inhalt — auf dem iPhone SE drei Viertel des
// Bildschirms, bevor die erste Zahl kam. Jetzt:
//   • eine Tab-Leiste unten in der Daumenzone: Übersicht · Verbrauch ·
//     ＋ Erfassen · Kosten · Mehr,
//   • „Mehr" öffnet die Seitenleiste als Menü (Schublade von links),
//   • „＋" öffnet das Erfassen-Blatt: Zählerstände, Lieferung und Peilstand
//     (Heizöl, Pellets), Termin. Am Mac öffnet es der Knopf in der Kopfleiste.
// Die Tab-Leiste zeigt nur das CSS unter 800 px; das Blatt gibt es überall.
// =====================================================================

import { t } from './i18n.js';
import { escapeHtml as esc } from './format.js';
import { tabbarModel } from './nav-model.js';
import { openModal } from '../components/modal.js';
import { activeUtilities } from '../state.js';

let lastUtility = null;

function tabHtml(item) {
  const inner = `<span class="tabbar__icon" aria-hidden="true">${esc(item.icon)}</span>
      <span class="tabbar__label">${esc(item.label)}</span>${item.badge ? `<span class="tabbar__badge" data-badge="${esc(item.badge)}"></span>` : ''}`;
  if (item.action) {
    return `<button type="button" class="tabbar__item${item.action === 'capture' ? ' tabbar__item--capture' : ''}" data-action="${item.action}"
        aria-label="${esc(item.label)}"${item.action === 'more' ? ' aria-controls="sidebar" aria-expanded="false"' : ''}>${inner}</button>`;
  }
  return `<a class="tabbar__item" href="${item.href}" data-nav="${esc(item.key)}" data-section="${esc(item.section)}">${inner}</a>`;
}

/** Tab-Leiste aufbauen; nach jedem Neuaufbau der Seitenleiste erneut. */
export function buildTabbar(utilities = []) {
  const bar = document.getElementById('tabbar');
  if (!bar) return;
  const first = (lastUtility && utilities.some(u => u.key === lastUtility)) ? lastUtility : utilities[0]?.key;
  bar.innerHTML = tabbarModel(first).map(tabHtml).join('');
  bar.querySelector('[data-action="capture"]')?.addEventListener('click', () => openCaptureSheet());
  bar.querySelector('[data-action="more"]')?.addEventListener('click', () => toggleMenu(true));
  window.dispatchEvent(new CustomEvent('et:badges-slots'));
}

// ── Menü („Mehr"): die Seitenleiste als Schublade ────────────────────────
let menuOpen = false;
let menuPushed = false;
let menuReturnFocus = null;

function toggleMenu(open) {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar || open === menuOpen) return;
  menuOpen = open;
  document.body.classList.toggle('nav-open', open);
  document.querySelector('#tabbar [data-action="more"]')?.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (open) {
    menuReturnFocus = document.activeElement;
    sidebar.setAttribute('role', 'dialog');
    sidebar.setAttribute('aria-modal', 'true');
    sidebar.setAttribute('aria-label', t('nav.menu'));
    // Zurück-Taste schließt das Menü wie einen Dialog
    try { history.pushState(history.state, ''); menuPushed = true; } catch { menuPushed = false; }
    sidebar.querySelector('a, button')?.focus();
  } else {
    sidebar.removeAttribute('role');
    sidebar.removeAttribute('aria-modal');
    sidebar.removeAttribute('aria-label');
    if (menuReturnFocus && document.contains(menuReturnFocus)) menuReturnFocus.focus?.();
  }
}

function closeMenu(viaHistory = false) {
  if (!menuOpen) return;
  toggleMenu(false);
  if (menuPushed && !viaHistory) { menuPushed = false; history.back(); }
  menuPushed = false;
}

window.addEventListener('popstate', () => { if (menuOpen) closeMenu(true); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menuOpen) closeMenu(); });
document.addEventListener('click', (e) => {
  if (!menuOpen) return;
  if (e.target.closest?.('#nav-backdrop')) closeMenu();
});
window.addEventListener('et:route', (e) => {
  // Eine Navigation aus dem Menü heraus schließt es — ohne history.back(),
  // das die Navigation rückgängig machte
  if (menuOpen) { toggleMenu(false); menuPushed = false; }
  if (e.detail?.utility) {
    lastUtility = e.detail.utility;
    const tab = document.querySelector('#tabbar [data-nav="consumption"]');
    tab?.setAttribute('href', `#/utility/${lastUtility}`);
  }
});
window.addEventListener('et:nav-built', (e) => buildTabbar(e.detail?.utilities || []));

// ── Erfassen-Blatt (＋) ───────────────────────────────────────────────────
export async function openCaptureSheet() {
  let utilities = [];
  try { utilities = await activeUtilities(); } catch { /* nur die festen Einträge */ }
  const deliveryUtils = utilities.filter(u => u.reading_kind === 'delivery');
  const entry = (href, icon, label, hint = '') => `<li><a class="capture-sheet__item" href="${href}">
      <span class="capture-sheet__icon" aria-hidden="true">${esc(icon)}</span>
      <span><span class="capture-sheet__label">${esc(label)}</span>${hint ? `<span class="capture-sheet__hint">${esc(hint)}</span>` : ''}</span>
    </a></li>`;
  const items = [
    entry('#/zaehlerstaende', '📋', t('nav.sheet.readings'), t('nav.sheet.readingsHint')),
    ...deliveryUtils.flatMap(u => [
      entry(`#/utility/${u.key}?add=delivery`, u.icon || '🛢️', t('nav.sheet.delivery', { utility: u.label })),
      entry(`#/utility/${u.key}?add=level`, '📏', t('nav.sheet.tankLevel', { utility: u.label })),
    ]),
    entry('#/reminders?add=1', '📌', t('nav.sheet.reminder')),
  ];
  openModal({
    title: t('nav.sheet.title'),
    size: 'sm',
    body: `<ul class="capture-sheet">${items.join('')}</ul>`,
  });
}

document.addEventListener('click', (e) => {
  if (e.target.closest?.('[data-action="open-capture"]')) openCaptureSheet();
});
