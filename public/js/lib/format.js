// =====================================================================
// Formatting helpers — locale-aware (N1007 / v2.0.0).
// Zahlen, Währung, Datum und Monatsnamen richten sich nach der aktiven
// Sprache (getLocale()); seit v2.7.0 Region und Währung aus dem Länderprofil.
//
// v2.2.0 — Datums- und Monatsformat kommen jetzt aus `Intl` statt aus
// handgepflegten Tabellen. Vorher waren nur 'de' und 'en' abgebildet; die
// 2026 ergänzten Sprachen (es/fr/it/nl/pt) fielen still auf de-DE zurück und
// zeigten deutsche Zahlen, deutsche Datumstrennung und deutsche Monatsnamen
// („Mär", „Mai", „Dez") in einer ansonsten übersetzten Oberfläche.
// =====================================================================

import { getLocale, getCurrency } from './i18n.js';

// Regionalisierung der Sprachcodes. Ohne Region würde 'en' zu en-US werden
// (MM/DD/YYYY, 1,234.56) — das Projekt zeigt britische Konventionen. Für eine
// künftige Sprache ohne Eintrag greift der reine Sprachcode, den Intl versteht.
const REGION = {
  de: 'de-DE', en: 'en-GB', es: 'es-ES',
  fr: 'fr-FR', it: 'it-IT', nl: 'nl-NL', pt: 'pt-PT',
};

// v2.7.0 — Länderprofil (I18N-17): Wird die Sprache im Land gesprochen,
// kommt die Region aus beiden — Deutsch in der Schweiz trennt Tausender mit
// Apostroph und schreibt einen Dezimalpunkt (1'234.50), Deutsch in
// Österreich stellt das €-Zeichen voran. Sonst gilt die Tabelle oben:
// Englisch in Deutschland bleibt en-GB (Intl schriebe für en-DE deutsche
// Zahlen — jede bestehende englische Installation hätte sie über Nacht
// bekommen, denn das Land ist dort DE). Das Backend (PDF, Empfehlungstexte)
// folgt derselben Regel über Config\Countries::FORMAT_OVERRIDES.
let country = 'DE';
let countryLanguages = [];
/**
 * @param {string} code       Land (ISO 3166, z. B. 'CH')
 * @param {string[]} languages Sprachen des Landes laut Länderprofil
 */
export function setCountry(code, languages = []) {
  country = /^[A-Z]{2}$/.test(String(code || '')) ? code : 'DE';
  countryLanguages = Array.isArray(languages) ? languages : [];
  numCache.clear(); eurCache.clear(); decCache.clear(); dateCache.clear(); monthCache.clear();
}
const regionCache = new Map();
export const intlLocale = () => {
  const lang = getLocale();
  const key = `${lang}|${country}|${countryLanguages.join(',')}`;
  if (!regionCache.has(key)) {
    const tag = `${lang}-${country}`;
    let ok = countryLanguages.includes(lang);
    try { ok = ok && Intl.NumberFormat.supportedLocalesOf([tag]).length > 0; } catch { ok = false; }
    regionCache.set(key, ok ? tag : (REGION[lang] || lang || 'de-DE'));
  }
  return regionCache.get(key);
};

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

// v2.7.0 — Währung aus der Einstellung (EUR, CHF, GBP). Der Name `eur`
// bleibt für die bestehenden Aufrufer; fmt.money ist derselbe Formatierer.
const eurCache = new Map();
function eurFmt() {
  const loc = intlLocale();
  const cur = getCurrency();
  const key = `${loc}|${cur}`;
  let f = eurCache.get(key);
  if (!f) {
    f = new Intl.NumberFormat(loc, { style: 'currency', currency: cur });
    eurCache.set(key, f);
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

// v2.10.0 — Was auf die angezeigte Stellenzahl zu 0 rundet, ist 0: Intl
// schrieb −0,04 °C mit einer Stelle als „-0,0".
const noNegZero = (v, d) => { const n = Number(v); return Math.abs(n) < 0.5 / 10 ** d ? 0 : n; };

export const fmt = {
  num:   (v, d=2) => v == null || isNaN(v) ? '–' : numFmt(d).format(noNegZero(v, d)),
  dec:   (v, max=2) => v == null || isNaN(v) ? '–' : decFmt(max).format(noNegZero(v, max)),
  int:   (v)      => v == null || isNaN(v) ? '–' : numFmt(0).format(noNegZero(v, 0)),
  eur:   (v)      => v == null || isNaN(v) ? '–' : eurFmt().format(Number(v)),
  money: (v)      => v == null || isNaN(v) ? '–' : eurFmt().format(Number(v)),
  pct:   (v, d=1) => v == null || isNaN(v) ? '–' : numFmt(d).format(Number(v) * 100) + ' %',
  // v2.5.3 — Unlesbare Werte kommen ESCAPED zurück. Vorher gab `date()` jeden
  // String roh zurück, der kein Datum war, und die Aufrufer setzten das
  // Ergebnis in innerHTML: HTML in einem Vertragsdatum (per API oder aus
  // einer präparierten Backup-Datei) wurde ausgeführt. Außerdem rollte
  // `new Date(2026, 12, 45)` still in ein anderes Datum über; jetzt muss das
  // Datum kalendergültig sein.
  date:  (d)      => {
    if (!d) return '–';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d));
    if (!m) return escapeHtml(d);
    // Lokale Datumskomponenten (nicht Date.parse), damit die Zeitzone den Tag
    // nicht verschiebt. Ergebnis pro Sprache: de 03.08.2026 · en 03/08/2026 ·
    // nl 03-08-2026 · fr/es/it/pt 03/08/2026.
    const dt = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    if (isNaN(dt) || dt.getMonth() !== Number(m[2]) - 1 || dt.getDate() !== Number(m[3])) return escapeHtml(d);
    return dtFmt(dateCache, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(dt);
  },
  month: (ym) => {
    if (!ym) return '–';
    const m = /^(\d{4})-(\d{2})$/.exec(String(ym));
    if (!m || Number(m[2]) < 1 || Number(m[2]) > 12) return escapeHtml(ym);
    const dt = new Date(Number(m[1]), Number(m[2]) - 1, 1, 12);
    return dtFmt(monthCache, { month: 'short', year: 'numeric' }).format(dt);
  },
  unit: (v, unit, digits=0) => v == null || isNaN(v) ? '–' : `${numFmt(digits).format(Number(v))} ${unit}`,
};

export function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

/**
 * Heutiges Datum als JJJJ-MM-TT in ORTSZEIT.
 *
 * v2.5.3 — Vorher `toISOString()`, also UTC: Zwischen Mitternacht und 1 bzw.
 * 2 Uhr (MEZ/MESZ) lieferte das den Vortag, und jede Ablesung, Lieferung
 * oder jeder Vertrag bekam dort das falsche Vorgabedatum.
 */
export function todayIso() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Dezimaltrennzeichen der App-Sprache („,“ oder „.“). */
export function localeDecimalSeparator() {
  try {
    const part = new Intl.NumberFormat(intlLocale()).formatToParts(1.5).find(p => p.type === 'decimal');
    return part ? part.value : '.';
  } catch {
    return '.';
  }
}

/**
 * v2.5.3 — Zahleneingabe mit Komma ODER Punkt, für alle Formulare.
 *
 * Liefert eine endliche Zahl oder `null` für leer bzw. ungültig — nie 0 für
 * „nichts eingegeben" (Lektion 24). Vorher machten `Number('')` und
 * `parseFloat(x || 0)` aus einem leeren Feld eine gültige 0, und
 * `<input type="number">` lieferte bei „12345,6" je nach Browser einen leeren
 * Wert: Eine Ablesung wurde zum Zählerstand 0, ein Zählertausch schloss das
 * alte Gerät mit 0 ab.
 *
 * Verstanden werden „1234,5", „1234.5", „1.234,5", „1,234.5", „1 234,5",
 * „1'234.5". Bei genau einem Trennzeichen mit drei Ziffern dahinter
 * („1.234", „1,234") entscheidet die App-Sprache: Ist es ihr
 * Dezimaltrennzeichen, gilt es als Dezimalstelle, sonst als Tausenderpunkt.
 *
 * @param {unknown} raw
 * @returns {number|null}
 */
export function parseDecimal(raw) {
  if (raw == null) return null;
  if (typeof raw === 'number') return Number.isFinite(raw) ? raw : null;
  let s = String(raw).trim().replace(/[\s  ']/g, '');
  if (s === '' || !/^[+-]?[\d.,]+$/.test(s)) return null;
  let sign = '';
  if (s[0] === '+' || s[0] === '-') { sign = s[0] === '-' ? '-' : ''; s = s.slice(1); }

  const hasComma = s.includes(','), hasDot = s.includes('.');
  let decimal = null;
  if (hasComma && hasDot) {
    decimal = s.lastIndexOf(',') > s.lastIndexOf('.') ? ',' : '.';
  } else if (hasComma || hasDot) {
    const sep = hasComma ? ',' : '.';
    const parts = s.split(sep);
    if (parts.length > 2) decimal = null;                                  // 1.234.567
    else if (parts[1].length === 3) decimal = localeDecimalSeparator() === sep ? sep : null;
    else decimal = sep;
  }
  const group = decimal === ',' ? '.' : decimal === '.' ? ',' : (hasComma ? ',' : '.');
  let [intPart, fracPart = ''] = decimal ? s.split(decimal) : [s, ''];
  if (decimal && s.split(decimal).length > 2) return null;               // zwei Dezimaltrenner
  if (intPart.includes(group)) {
    const groups = intPart.split(group);
    if (!/^\d{1,3}$/.test(groups[0]) || groups.slice(1).some(g => !/^\d{3}$/.test(g))) return null;
    intPart = groups.join('');
  }
  if (!/^\d+$/.test(intPart) || !/^\d*$/.test(fracPart)) return null;
  const n = Number(`${sign}${intPart}${fracPart ? '.' + fracPart : ''}`);
  return Number.isFinite(n) ? n : null;
}

/**
 * Anzeige einer Zahl in einem Eingabefeld der App-Sprache („1234,5" statt
 * „1234.5"), ohne Tausendertrenner, damit sie beim erneuten Speichern sicher
 * zurückgelesen wird.
 */
export function formatForInput(v, maxDigits = 6) {
  if (v == null || v === '' || !Number.isFinite(Number(v))) return '';
  const s = String(Number(Number(v).toFixed(maxDigits)));
  return localeDecimalSeparator() === ',' ? s.replace('.', ',') : s;
}

export function yearOf(dateStr) {
  return dateStr ? Number(String(dateStr).slice(0, 4)) : null;
}
