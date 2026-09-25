// =====================================================================
// v2.13.0 (Review DOC-10, UI-17) — Erklärungen zum Antippen.
//
// Bis v2.12 standen Erklärungen zu Kennzahlen fast nur in `title`-Attributen:
// am Mac beim Überfahren sichtbar, auf dem iPhone gar nicht. `info('hdd')`
// setzt einen ⓘ-Knopf neben ein Label; Tippen oder Klicken öffnet die
// Erklärung aus dem Glossar-Katalog (`glossary.<id>` in den Sprachdateien).
// Der Katalog ist die einzige Quelle — die Hilfe-Ansicht listet dieselben
// Einträge.
// =====================================================================
import { t } from '../lib/i18n.js';
import { escapeHtml } from '../lib/format.js';

/** Alle Begriffe des Glossars (Schlüssel unter `glossary.`). */
export const GLOSSARY = [
  'hdd', 'heatingLimit', 'heatingSignature', 'baseload', 'weatherAdjusted', 'climateNormal',
  'r2', 'models', 'movingAverage', 'forecastBlend', 'anomaly',
  'balance', 'advance', 'workingPrice', 'basePrice', 'specialPayment',
  'offer', 'switchDate', 'noticeDeadline', 'breakEven',
  'baseline', 'calorificValue', 'zNumber', 'cutoffDate', 'efficiency',
  'feedIn', 'selfConsumption', 'autarky', 'selfConsumptionRate',
  'estimated', 'tankBook', 'subMeter', 'co2Avoided',
];

export const glossaryTerm = (id) => t(`glossary.${id}.term`);
export const glossaryText = (id) => t(`glossary.${id}.text`);

/** HTML eines ⓘ-Knopfs zum Glossar-Begriff `id`. Nicht in Links oder Knöpfe setzen. */
export function info(id) {
  const label = t('help.explain', { term: glossaryTerm(id) });
  return `<button type="button" class="info-btn" data-glossary="${escapeHtml(id)}" aria-expanded="false" aria-label="${escapeHtml(label)}"><span aria-hidden="true">ⓘ</span></button>`;
}

/**
 * ⓘ-Knopf mit einer Erklärung, die nicht im Glossar steht — etwa der Grund,
 * warum genau diese Ablesung als unplausibel markiert ist. Ohne Glossar-Link.
 */
export function infoNote(term, text) {
  const label = t('help.explain', { term });
  return `<button type="button" class="info-btn" data-info-term="${escapeHtml(term)}" data-info-text="${escapeHtml(text)}" aria-expanded="false" aria-label="${escapeHtml(label)}"><span aria-hidden="true">ⓘ</span></button>`;
}

let open = null;   // { btn, pop }

function close(returnFocus = false) {
  if (!open) return;
  const { btn, pop } = open;
  open = null;
  pop.remove();
  btn.setAttribute('aria-expanded', 'false');
  if (returnFocus) btn.focus();
}

// Unter dem Knopf, im Fenster gehalten. Auf schmalen Bildschirmen steht die
// Erklärung als Blatt über der Tab-Leiste (CSS), dann entfällt die Rechnung.
function place(btn, pop) {
  if (window.matchMedia?.('(max-width: 600px)')?.matches) return;
  const r = btn.getBoundingClientRect();
  const w = pop.offsetWidth;
  const h = pop.offsetHeight;
  const left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
  let top = r.bottom + 8;
  if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 8);
  pop.style.left = `${Math.round(left + window.scrollX)}px`;
  pop.style.top = `${Math.round(top + window.scrollY)}px`;
}

function show(btn) {
  const id = btn.dataset.glossary;
  const term = id ? glossaryTerm(id) : (btn.dataset.infoTerm || '');
  const text = id ? glossaryText(id) : (btn.dataset.infoText || '');
  close();
  const pop = document.createElement('div');
  pop.className = 'info-pop';
  pop.id = 'info-pop';
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-label', term);
  pop.innerHTML = `
    <strong class="info-pop__term">${escapeHtml(term)}</strong>
    <p class="info-pop__text">${escapeHtml(text)}</p>
    ${id ? `<a class="info-pop__more" href="#/help?term=${encodeURIComponent(id)}">${escapeHtml(t('help.moreInGlossary'))}</a>` : ''}
    <button type="button" class="info-pop__close" aria-label="${escapeHtml(t('common.close'))}"><span aria-hidden="true">×</span></button>`;
  document.body.appendChild(pop);
  btn.setAttribute('aria-expanded', 'true');
  btn.setAttribute('aria-controls', pop.id);
  open = { btn, pop };
  place(btn, pop);
  pop.querySelector('.info-pop__close')?.addEventListener('click', () => close(true));
}

let installed = false;

/**
 * Einmal beim Start: ein Handler für alle ⓘ-Knöpfe, auch für später
 * gerenderte. In der Capture-Phase, damit ein ⓘ in einer sortierbaren
 * Tabellenüberschrift oder einer Karte mit eigenem Klick nur sich selbst
 * auslöst.
 */
export function installInfoPopovers() {
  if (installed) return;
  installed = true;
  document.addEventListener('click', (e) => {
    const btn = e.target.closest?.('.info-btn');
    if (btn) {
      e.preventDefault();
      e.stopPropagation();
      if (open?.btn === btn) close(); else show(btn);
      return;
    }
    if (open && !open.pop.contains(e.target)) close();
  }, true);
  // Escape schließt nur die Erklärung, nicht einen Dialog darunter
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && open) { e.stopPropagation(); close(true); }
  }, true);
  window.addEventListener('et:route', () => close());
  window.addEventListener('resize', () => close());
}
