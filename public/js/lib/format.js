// =====================================================================
// Formatting helpers — locale-aware (N1007 / v2.0.0).
// Zahlen, Währung, Datum und Monatsnamen richten sich nach der aktiven
// Sprache (getLocale()): 'de' → de-DE, 'en' → en-GB. EUR bleibt die Währung.
// =====================================================================

import { getLocale } from './i18n.js';

const INTL = { de: 'de-DE', en: 'en-GB' };
const intlLocale = () => INTL[getLocale()] || 'de-DE';

// Formatter sind pro (locale, digits) gecached, damit nicht bei jedem Aufruf
// ein neues Intl-Objekt entsteht.
const numCache = new Map();
function numFmt(d) {
  const key = `${intlLocale()}|${d}`;
  let f = numCache.get(key);
  if (!f) {
    f = new Intl.NumberFormat(intlLocale(), { minimumFractionDigits: d, maximumFractionDigits: d });
    numCache.set(key, f);
  }
  return f;
}

const eurCache = new Map();
function eurFmt() {
  const loc = intlLocale();
  let f = eurCache.get(loc);
  if (!f) {
    f = new Intl.NumberFormat(loc, { style: 'currency', currency: 'EUR' });
    eurCache.set(loc, f);
  }
  return f;
}

const MONTHS = {
  de: ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'],
  en: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
};

export const fmt = {
  num:   (v, d=2) => v == null || isNaN(v) ? '–' : numFmt(d).format(Number(v)),
  int:   (v)      => v == null || isNaN(v) ? '–' : numFmt(0).format(Number(v)),
  eur:   (v)      => v == null || isNaN(v) ? '–' : eurFmt().format(Number(v)),
  pct:   (v, d=1) => v == null || isNaN(v) ? '–' : numFmt(d).format(Number(v) * 100) + ' %',
  date:  (d)      => {
    if (!d) return '–';
    const [y,m,day] = String(d).split('-');
    if (!day) return d;
    // de: TT.MM.JJJJ · en: DD/MM/YYYY
    return getLocale() === 'en' ? `${day}/${m}/${y}` : `${day}.${m}.${y}`;
  },
  month: (ym) => {
    if (!ym) return '–';
    const [y,m] = ym.split('-');
    const names = MONTHS[getLocale()] || MONTHS.de;
    return `${names[Number(m)-1]} ${y}`;
  },
  unit: (v, unit, digits=0) => v == null || isNaN(v) ? '–' : `${numFmt(digits).format(Number(v))} ${unit}`,
};

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

export function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

export function yearOf(dateStr) {
  return dateStr ? Number(String(dateStr).slice(0, 4)) : null;
}
