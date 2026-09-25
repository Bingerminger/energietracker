// =====================================================================
// Energietracker v2.0.0 — i18n (N1007)
// Lädt JSON-Sprachkataloge (public/locales/<lang>.json) und löst
// Übersetzungs-Keys in Punkt-Notation auf: t('nav.dashboard').
// Single source of truth: dieselben Katalogdateien nutzt das Backend
// (src/Services/I18nService.php).
// =====================================================================

export const DEFAULT_LOCALE = 'de';

// Datengetriebene Sprachliste: die unterstützten Sprachen + ihre Anzeigenamen
// (Endonyme) stehen in public/locales/languages.json. Bis die Registry geladen
// ist, gilt der Minimal-Fallback. Eine neue Sprache = JSON-Katalog ablegen +
// eine Zeile in languages.json — keine Code-Änderung nötig.
let LANGUAGES = { de: 'Deutsch', en: 'English' };
export let SUPPORTED = Object.keys(LANGUAGES);

let locale = DEFAULT_LOCALE;
let catalog = {};          // aktive Sprache
let fallback = {};         // Default-Sprache (de) für fehlende Keys

export function getLocale() { return locale; }

/** Registry { code: Anzeigename } der unterstützten Sprachen. */
export function getLanguages() { return LANGUAGES; }

async function loadLanguages() {
  try {
    const res = await fetch('public/locales/languages.json', { cache: 'no-cache' });
    if (res.ok) {
      const data = await res.json();
      if (data && typeof data === 'object' && Object.keys(data).length) {
        LANGUAGES = data;
        SUPPORTED = Object.keys(data);
      }
    }
  } catch (e) {
    console.error('i18n: languages.json konnte nicht geladen werden', e);
  }
}

function normalize(lang) {
  const loc = String(lang || '').slice(0, 2).toLowerCase();
  return SUPPORTED.includes(loc) ? loc : DEFAULT_LOCALE;
}

async function loadCatalog(loc) {
  try {
    // Statische Datei direkt laden (kein API-Roundtrip, kein Accept-Language).
    const res = await fetch(`public/locales/${loc}.json`, { cache: 'no-cache' });
    if (res.ok) return await res.json();
  } catch (e) {
    console.error(`i18n: Katalog ${loc} konnte nicht geladen werden`, e);
  }
  return {};
}

/**
 * Initialisiert die Lokalisierung für eine Sprache. Lädt den Katalog (und den
 * Default-Katalog als Fallback) und setzt <html lang>.
 */
export async function initI18n(lang) {
  await loadLanguages();     // unterstützte Sprachen kennen, bevor normalisiert wird
  locale = normalize(lang);
  catalog = await loadCatalog(locale);
  fallback = locale === DEFAULT_LOCALE ? catalog : await loadCatalog(DEFAULT_LOCALE);
  document.documentElement.setAttribute('lang', locale);
  return locale;
}

/** Gibt es den Schlüssel im geladenen Katalog? (vor initI18n: nein) */
export function hasTranslation(key) {
  return lookup(catalog, key) != null || lookup(fallback, key) != null;
}

function lookup(cat, key) {
  let node = cat;
  for (const seg of key.split('.')) {
    if (node && typeof node === 'object' && seg in node) node = node[seg];
    else return null;
  }
  return typeof node === 'string' ? node : null;
}

// v2.7.0 — Länderprofil: Die Kataloge schreiben Währungen als Platzhalter
// ({cur} Symbol, {minor} Untereinheit, {code} ISO-Code). t() setzt sie aus
// der Einstellung `currency` ein (app.js ruft setCurrencyParams() nach dem
// Laden der Einstellungen); explizite Parameter haben Vorrang.
const CURRENCIES = {
  EUR: { cur: '€',   minor: 'ct' },
  CHF: { cur: 'CHF', minor: 'Rp.' },
  GBP: { cur: '£',   minor: 'p' },
};
let currencyParams = { ...CURRENCIES.EUR, code: 'EUR' };

/** @param {string} code ISO 4217 (EUR, CHF, GBP) */
export function setCurrencyParams(code) {
  const c = CURRENCIES[code] ? code : 'EUR';
  currencyParams = { ...CURRENCIES[c], code: c };
}

/** Aktuelle Währung als ISO-Code. */
export function getCurrency() { return currencyParams.code; }

/** Währungssymbol für Achsen und Spaltenköpfe (€, CHF, £). */
export function getCurrencySymbol() { return currencyParams.cur; }

/** Untereinheit der Währung (ct, Rp., p) — für Preise je kWh oder Liter. */
export function getCurrencyMinor() { return currencyParams.minor; }

/**
 * Übersetzt einen Punkt-Key. Platzhalter `{name}` werden aus `params` ersetzt.
 * Reihenfolge: aktive Sprache → Default-Sprache → Key selbst.
 */
export function t(key, params) {
  let str = lookup(catalog, key);
  if (str == null) str = lookup(fallback, key);
  if (str == null) return key;
  if (str.includes('{')) {
    for (const [k, v] of Object.entries({ ...currencyParams, ...(params || {}) })) {
      str = str.replaceAll(`{${k}}`, String(v));
    }
  }
  return str;
}

/**
 * v2.11.0 — Pluralform nach den Regeln der Sprache (Intl.PluralRules):
 * `tp('x.days', n)` sucht `x.days.one`, `x.days.other` (… `few`, `many`) und
 * setzt `{count}`. Vorher stand „1 Arbeitspreise" oder „noch 1 Tage" da.
 */
export function tp(key, count, params = {}) {
  let cat = 'other';
  try { cat = new Intl.PluralRules(locale).select(Number(count)); } catch { /* 'other' */ }
  const k = lookup(catalog, `${key}.${cat}`) != null || lookup(fallback, `${key}.${cat}`) != null
    ? `${key}.${cat}` : `${key}.other`;
  return t(k, { count, ...params });
}
