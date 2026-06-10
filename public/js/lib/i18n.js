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

function lookup(cat, key) {
  let node = cat;
  for (const seg of key.split('.')) {
    if (node && typeof node === 'object' && seg in node) node = node[seg];
    else return null;
  }
  return typeof node === 'string' ? node : null;
}

/**
 * Übersetzt einen Punkt-Key. Platzhalter `{name}` werden aus `params` ersetzt.
 * Reihenfolge: aktive Sprache → Default-Sprache → Key selbst.
 */
export function t(key, params) {
  let str = lookup(catalog, key);
  if (str == null) str = lookup(fallback, key);
  if (str == null) return key;
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      str = str.replaceAll(`{${k}}`, String(v));
    }
  }
  return str;
}
