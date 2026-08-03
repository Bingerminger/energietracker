// =====================================================================
// Formatting helpers — locale-aware (N1007 / v2.0.0).
// Zahlen, Währung, Datum und Monatsnamen richten sich nach der aktiven
// Sprache (getLocale()). EUR bleibt die Währung.
//
// v2.2.0 — Datums- und Monatsformat kommen jetzt aus `Intl` statt aus
// handgepflegten Tabellen. Vorher waren nur 'de' und 'en' abgebildet; die
// 2026 ergänzten Sprachen (es/fr/it/nl/pt) fielen still auf de-DE zurück und
// zeigten deutsche Zahlen, deutsche Datumstrennung und deutsche Monatsnamen
// („Mär", „Mai", „Dez") in einer ansonsten übersetzten Oberfläche.
// =====================================================================

import { getLocale } from './i18n.js';

// Regionalisierung der Sprachcodes. Ohne Region würde 'en' zu en-US werden
// (MM/DD/YYYY, 1,234.56) — das Projekt zeigt britische Konventionen. Für eine
// künftige Sprache ohne Eintrag greift der reine Sprachcode, den Intl versteht.
const REGION = {
  de: 'de-DE', en: 'en-GB', es: 'es-ES',
  fr: 'fr-FR', it: 'it-IT', nl: 'nl-NL', pt: 'pt-PT',
};
export const intlLocale = () => REGION[getLocale()] || getLocale() || 'de-DE';

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

// Dezimalzahlen mit variabler Stellenzahl (max, ohne aufgefüllte Nullen).
const decCache = new Map();
function decFmt(max) {
  const key = `${intlLocale()}|${max}`;
  let f = decCache.get(key);
  if (!f) {
    f = new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: max });
    decCache.set(key, f);
  }
  return f;
}

const dateCache  = new Map();
const monthCache = new Map();
function dtFmt(cache, opts) {
  const loc = intlLocale();
  let f = cache.get(loc);
  if (!f) {
    f = new Intl.DateTimeFormat(loc, opts);
    cache.set(loc, f);
  }
  return f;
}

/**
 * Kurze Monatsnamen der aktiven Sprache, Index 0 = Januar. Für Chart-Achsen,
 * die zwölf Beschriftungen ohne Jahr brauchen.
 * @returns {string[]}
 */
export function monthShortNames() {
  const f = new Intl.DateTimeFormat(intlLocale(), { month: 'short' });
  // Ein Schaltjahr als Basis, damit jeder Monat existiert; Tag 1 mittags,
  // damit keine Zeitzonenverschiebung in den Vormonat rutscht.
  return Array.from({ length: 12 }, (_, i) => f.format(new Date(2024, i, 1, 12)));
}

export const fmt = {
  num:   (v, d=2) => v == null || isNaN(v) ? '–' : numFmt(d).format(Number(v)),
  dec:   (v, max=2) => v == null || isNaN(v) ? '–' : decFmt(max).format(Number(v)),
  int:   (v)      => v == null || isNaN(v) ? '–' : numFmt(0).format(Number(v)),
  eur:   (v)      => v == null || isNaN(v) ? '–' : eurFmt().format(Number(v)),
  pct:   (v, d=1) => v == null || isNaN(v) ? '–' : numFmt(d).format(Number(v) * 100) + ' %',
  date:  (d)      => {
    if (!d) return '–';
    const [y,m,day] = String(d).split('-');
    if (!day) return d;
    // Lokale Datumskomponenten (nicht Date.parse), damit die Zeitzone den Tag
    // nicht verschiebt. Ergebnis pro Sprache: de 03.08.2026 · en 03/08/2026 ·
    // nl 03-08-2026 · fr/es/it/pt 03/08/2026.
    const dt = new Date(Number(y), Number(m) - 1, Number(String(day).slice(0, 2)));
    if (isNaN(dt)) return d;
    return dtFmt(dateCache, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(dt);
  },
  month: (ym) => {
    if (!ym) return '–';
    const [y,m] = String(ym).split('-');
    const dt = new Date(Number(y), Number(m) - 1, 1, 12);
    if (isNaN(dt)) return ym;
    return dtFmt(monthCache, { month: 'short', year: 'numeric' }).format(dt);
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
