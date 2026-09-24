// =====================================================================
// v2.6.0 — Plausibilitätsprüfung bei der Erfassung eines Zählerstands
// (Review 2026-09-24).
//
// Nichts davon blockiert: Zählertausch, Überlauf und Nachträge sind
// legitim. Ein Tippfehler („12345" statt „1234,5") soll aber nicht still in
// Kosten, Prognose und Effizienzklasse wandern, sondern eine Rückfrage
// auslösen — bevor gespeichert wird.
//
//   future     Datum liegt in der Zukunft
//   sameDay    für den Tag gibt es schon einen Stand → ersetzen statt doppeln
//   lower      kleiner als der vorige Stand → Zählertausch nicht erfasst?
//   jump       Tagesverbrauch seit dem vorigen Stand > 3 × typisch
//   magnitude  mehr als 10 × der vorige Stand (solange kein typischer
//              Tagesverbrauch bekannt ist — „Komma vergessen")
//
// Das Backend prüft unabhängig davon nach dem Speichern (eingeklemmte
// Ausreißer, Verdacht aus Home Assistant) und meldet das als `warnings` —
// siehe ConsumptionService::plausibleReadings().
// =====================================================================
import { t } from './i18n.js';
import { fmt, escapeHtml } from './format.js';
import { openModal } from '../components/modal.js';

export const JUMP_FACTOR = 3;

const DAY_MS = 86400000;
const daysBetween = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / DAY_MS);

/**
 * Typischer Tagesverbrauch: Median der letzten ≤ 10 Intervalle desselben
 * Geräts, mindestens zwei — dieselbe Regel wie ReadingService::typicalPerDay().
 * Geplante und verdächtige Stände zählen nicht mit.
 *
 * @param {Array<{date:string, counter:number, device_id?:string, is_future?:boolean, is_suspect?:boolean}>} readings
 * @returns {number|null}
 */
export function typicalPerDay(readings) {
  const real = (readings || [])
    .filter(r => r && !r.is_future && !r.is_suspect && /^\d{4}-\d{2}-\d{2}$/.test(r.date || ''))
    .sort((a, b) => a.date.localeCompare(b.date));
  const rates = [];
  for (let i = real.length - 1; i > 0 && rates.length < 10; i--) {
    const a = real[i - 1], b = real[i];
    if ((a.device_id ?? null) !== (b.device_id ?? null)) continue;
    const days = daysBetween(a.date, b.date);
    const diff = Number(b.counter) - Number(a.counter);
    if (days >= 1 && diff >= 0) rates.push(diff / days);
  }
  if (rates.length < 2) return null;
  rates.sort((x, y) => x - y);
  const n = rates.length;
  return n % 2 ? rates[(n - 1) / 2] : (rates[n / 2 - 1] + rates[n / 2]) / 2;
}

/**
 * Wurde zwischen zwei Daten ein neues Gerät eingebaut? Dann sind „kleiner"
 * und „Sprung" keine Auffälligkeit, sondern der Tausch selbst.
 */
export function deviceChangedBetween(meter, fromDate, toDate) {
  return (meter?.devices || []).some(d => d.installed_on && d.installed_on > fromDate && d.installed_on <= toDate);
}

/**
 * Rückfragen zu einem neuen Stand.
 *
 * @param {object} p
 * @param {number} p.value     neuer Zählerstand
 * @param {string} p.date      Ablesedatum (YYYY-MM-DD)
 * @param {string} p.today     heutiges Datum (YYYY-MM-DD)
 * @param {{date:string, counter:number}|null} p.prev   voriger Stand (vor `date`)
 * @param {number|null} p.typical   typischer Tagesverbrauch
 * @param {{date:string, counter:number}|null} p.sameDay  vorhandener Stand am selben Tag
 * @param {boolean} [p.deviceChanged]  Gerätetausch zwischen prev und date
 * @returns {Array<{type:string, [k:string]:any}>}
 */
export function checkReading({ value, date, today, prev = null, typical = null, sameDay = null, deviceChanged = false }) {
  const issues = [];
  if (date > today) issues.push({ type: 'future' });
  if (sameDay) issues.push({ type: 'sameDay', reading: sameDay });
  if (prev && prev.date < date && !deviceChanged) {
    const last = Number(prev.counter);
    const days = daysBetween(prev.date, date);
    if (value < last) {
      issues.push({ type: 'lower', last });
    } else if (typical > 0 && days >= 1 && (value - last) / days > JUMP_FACTOR * typical) {
      issues.push({ type: 'jump', perDay: (value - last) / days, typical });
    } else if (!(typical > 0) && last >= 10 && value >= last * 10) {
      issues.push({ type: 'magnitude', last });
    }
  }
  return issues;
}

/** Klartext einer Rückfrage (für Hinweiszeilen und Dialoge). */
export function issueText(issue, { unit = '', date = '' } = {}) {
  switch (issue.type) {
    case 'future':    return t('readingCheck.future', { date: fmt.date(date) });
    case 'sameDay':   return t('readingCheck.sameDay', { date: fmt.date(date), counter: fmt.dec(issue.reading.counter, 3), unit });
    case 'lower':     return t('readingCheck.lower', { last: fmt.dec(issue.last, 3), unit });
    case 'jump':      return t('readingCheck.jump', { perDay: fmt.dec(issue.perDay, 1), typical: fmt.dec(issue.typical, 1), unit });
    case 'magnitude': return t('readingCheck.magnitude', { last: fmt.dec(issue.last, 3), unit });
    default:          return '';
  }
}

/**
 * Rückfrage-Dialog. Ist „schon ein Stand an diesem Tag" die einzige
 * Auffälligkeit, heißt die Bestätigung „Ersetzen". Bei „kleiner als der
 * vorige Stand" führt ein Link zum Zählertausch.
 *
 * @returns {Promise<boolean>} true = trotzdem speichern
 */
export function confirmIssues(issues, { unit = '', date = '', utility = null, title = null } = {}) {
  const onlySameDay = issues.every(i => i.type === 'sameDay');
  const swapLink = utility && issues.some(i => i.type === 'lower')
    ? `<p><a href="#/utility/${encodeURIComponent(utility)}/meters">${escapeHtml(t('utility.warnings.gotoMeters'))}</a></p>`
    : '';
  return openModal({
    title: title || t('readingCheck.title'),
    body: `<ul class="check-list">${issues.map(i => `<li>${escapeHtml(issueText(i, { unit, date }))}</li>`).join('')}</ul>${swapLink}`,
    footer: `
      <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button type="button" class="btn btn--primary" data-act="ok">${t(onlySameDay ? 'readingCheck.replace' : 'readingCheck.confirm')}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(false));
      modalEl.querySelector('[data-act="ok"]')?.addEventListener('click', () => close(true));
    },
  }).closedPromise.then(v => v === true);
}
