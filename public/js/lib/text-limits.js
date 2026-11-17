// =====================================================================
// v3.13.0 (Paket F) — Höchstlängen für Freitext, dieselben Zahlen wie
// src/Support/TextLimits.php (TextLimitsTest vergleicht beide Listen).
//
// Am Feld: `maxlength` aus LIMITS; lange Felder (Notizen) zeigen darunter einen
// Zähler „123 von 2000 Zeichen“, sobald 80 % erreicht sind. `maxlength` kürzt
// einen schon gespeicherten längeren Wert nicht: Der Server meldet ihn beim
// Speichern mit Feld und Grenze (errors.common.tooLong), gekürzt wird nie.
// =====================================================================

import { t } from './i18n.js';
import { fmt } from './format.js';

export const NAME = 120;
export const COMPANY = 160;
export const TITLE = 300;
export const NOTE = 2000;
export const SHORT_NOTE = 500;
export const NUMBER = 64;
export const PERSON = 60;
export const MARK = 80;
export const MCP_NOTE = 200;
export const DOC_VALUE = 200;

/** Topf → Feld → Höchstlänge (wie TextLimits::FIELDS). */
export const LIMITS = {
  meters: { name: NAME, notes: NOTE, 'baseline_events.label': MARK, 'tank_levels.note': MARK, 'devices.serial': NUMBER },
  meter_groups: { name: NAME },
  readings: { note: NOTE },
  contracts: { provider: COMPANY, tariff_name: NAME, shadow_label: NAME, notes: NOTE, contract_number: NUMBER, customer_number: NUMBER,
    'bonuses.label': NAME, 'special_payments.note': NAME },
  deliveries: { supplier: NAME, note: NOTE },
  periods: { note: NOTE },
  bills: { note: NOTE, invoice_number: 80, 'items.label': NAME, 'notice.file_number': 80 },
  reminders: { title: TITLE, notes: NOTE, assignee: PERSON },
  tenancies: { label: NAME, landlord: NAME, notes: NOTE, 'fixed_costs.label': NAME },
  tenancy_statements: { note: NOTE, 'positions.label': NAME, 'positions.unit': 12 },
  people: { name: PERSON },
  inbox: { name: NAME, note: SHORT_NOTE },
  activity: { note: SHORT_NOTE },
  settings: { location_name: NAME },
  feed_in_payouts: { note: SHORT_NOTE },
};

/** Grenze eines Felds; unbekannt ist ein Programmierfehler. */
export function limitOf(pot, field) {
  const v = LIMITS[pot]?.[field];
  if (!Number.isFinite(v)) throw new Error(`text limit ${pot}.${field} unknown`);
  return v;
}

/** `maxlength="…"` für eine Vorlage. */
export const maxAttr = (pot, field) => `maxlength="${limitOf(pot, field)}"`;

/** Ab diesem Anteil der Grenze erscheint der Zähler. */
export const COUNTER_FROM = 0.8;

/** Länge wie beim Server: Zeichen (nicht UTF-16-Einheiten) ohne Leerraum am Rand. */
export const textLength = (s) => [...String(s ?? '').trim()].length;

/** Text des Zählers oder '' unterhalb der Schwelle. */
export function counterText(value, max) {
  const n = textLength(value);
  return n >= Math.floor(max * COUNTER_FROM) ? t('common.charCount', { n: fmt.int(n), max: fmt.int(max) }) : '';
}

/**
 * Zähler an jedes Feld mit `data-counter` in `root` (Notizen): ein
 * `<small class="char-count">` direkt danach, aktualisiert bei jeder Eingabe.
 * Über der Grenze (Bestand) trägt er `char-count--over`.
 */
export function attachCounters(root) {
  root?.querySelectorAll?.('[data-counter][maxlength]').forEach((el) => {
    if (el.dataset.counterOn) return;
    el.dataset.counterOn = '1';
    const max = Number(el.getAttribute('maxlength'));
    const out = document.createElement('small');
    out.className = 'char-count';
    out.setAttribute('aria-live', 'polite');
    el.insertAdjacentElement('afterend', out);
    const update = () => {
      out.textContent = counterText(el.value, max);
      out.classList.toggle('char-count--over', textLength(el.value) > max);
      out.hidden = out.textContent === '';
    };
    el.addEventListener('input', update);
    update();
  });
}

/** Zähler an jedes neue Feld mit `data-counter` (Dialoge, Ansichten), wie lib/table-stack.js. */
export function startCharCounters(root = document.body) {
  if (typeof MutationObserver === 'undefined') return;
  let queued = false;
  const later = typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (cb) => setTimeout(cb, 16);
  const run = () => {
    if (queued) return;
    queued = true;
    later(() => { queued = false; attachCounters(root); });
  };
  new MutationObserver(run).observe(root, { childList: true, subtree: true });
  run();
}
