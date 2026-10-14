// =====================================================================
// v3.5.0 (N1022) — Land und Länderprofil an einer Stelle.
//
// Bis v3.4 kannte nur die Einstellungsseite den Weg „Land wechseln“: Sie
// verglich die Werte des Profils mit den eigenen (Dialog „Voreinstellungen
// übernehmen?“) und schrieb sie per PATCH fest — auch die deutschen Defaults.
// Jetzt fragt auch der Einrichtungsassistent nach dem Land. Beide gehen über
// dieselben Bausteine:
//   • profileDiff()  — was sich gegenüber den eigenen Einstellungen ändert,
//   • regionFacts()  — was ein Profil (oder die eigene Einstellung) setzt,
//                      in Worten, samt Schreibweise aus Sprache + Land,
//   • saveRegion()   — speichert (POST /api/settings/country bzw. PATCH für
//                      Währung und Zeitzone) und stellt Schreibweise, Währung
//                      und Rechnungsbegriffe sofort um.
// Der Server schreibt beim Land nur, was vom Default abweicht — dieselbe
// Regel wie beim Erststart (SettingsService::applyCountry).
// =====================================================================

import { t, setCurrencyParams } from './i18n.js';
import { fmt, regionLocale, setCountry } from './format.js';
import { cvUnit } from './gas-factor.js';
import { saveSettings, saveCountry } from '../state.js';
import { setBillContext } from '../components/info.js';

/** Jahreswerte `{Jahr: Wert}` aus Objekt oder Liste `[{year, g_per_kwh}]`. */
export function yearMap(value) {
  if (Array.isArray(value)) {
    return Object.fromEntries(value.filter(e => e && e.year != null).map(e => [String(e.year), e.g_per_kwh]));
  }
  return value && typeof value === 'object' ? { ...value } : {};
}

/** „Umweltbundesamt 2015–2025“ bzw. „ein Wert für alle Jahre“. */
export function yearsText(value) {
  const years = Object.keys(yearMap(value)).sort();
  return years.length ? t('settings.defaultUpdates.years', { from: years[0], to: years[years.length - 1] }) : t('settings.defaultUpdates.noYears');
}

/** Zeilen „bisher → Profil“ für die Werte, in denen das Profil abweicht. */
export function profileDiff(settings, p) {
  const rows = [];
  const add = (key, label, now, next, patch) => {
    if (now !== next) rows.push({ key, label, now, next, patch });
  };
  add('currency', t('settings.region.currency'), settings.currency, p.currency, { currency: p.currency });
  add('timezone', t('settings.region.timezone'), settings.timezone, p.timezone, { timezone: p.timezone });
  add('hdd_base_temp', t('settings.field.hdd_base_temp.label'),
    fmt.num(settings.hdd_base_temp, 1) + ' °C', fmt.num(p.hdd_base_temp, 1) + ' °C', { hdd_base_temp: p.hdd_base_temp });
  add('co2_strom', t('settings.field.co2_strom.label'),
    fmt.num(settings.co2_strom, 0) + ' g/kWh', fmt.num(p.co2_strom, 0) + ' g/kWh', { co2_strom: p.co2_strom });
  // v2.10.0 — Jahreswerte (nur Deutschland); andere Länder rechnen mit einem Wert
  add('co2_strom_years', t('settings.field.co2_strom_years.label'),
    yearsText(settings.co2_strom_years), yearsText(p.co2_strom_years), { co2_strom_years: p.co2_strom_years ?? [] });
  const loc = (name, lat, lon) => `${name} (${fmt.num(lat, 4)}, ${fmt.num(lon, 4)})`;
  add('location', t('settings.group.location.title'),
    loc(settings.location_name, settings.latitude, settings.longitude), loc(p.location_name, p.latitude, p.longitude),
    { location_name: p.location_name, latitude: p.latitude, longitude: p.longitude });
  const cv = (u) => cvUnit(u).label;
  add('gas_cv_unit', t('settings.gasFactors.cvUnit'), cv(settings.gas_cv_unit), cv(p.gas_cv_unit), { gas_cv_unit: p.gas_cv_unit });
  return rows;
}

/**
 * Was ein Länderprofil setzt — oder, mit den eigenen Einstellungen als
 * Quelle, was gerade gilt. Dazu die Schreibweise aus Sprache und Land
 * (Englisch in Deutschland bleibt en-GB, s. regionLocale()).
 *
 * @param {object} src   Profil aus /api/countries oder die Einstellungen
 * @param {{lang: string, country: string, languages?: string[]}} where
 * @returns {Array<{key: string, label: string, value: string}>}
 */
export function regionFacts(src, { lang, country, languages = [] }) {
  const tag = regionLocale(lang, country, languages);
  let format = '';
  try {
    const num = new Intl.NumberFormat(tag, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(1234.5);
    const money = new Intl.NumberFormat(tag, { style: 'currency', currency: src.currency || 'EUR' }).format(1234.56);
    const date = new Intl.DateTimeFormat(tag, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(2026, 11, 31));
    format = `${num} · ${money} · ${date}`;
  } catch { format = tag; }
  const years = Object.keys(yearMap(src.co2_strom_years)).length;
  return [
    { key: 'format', label: t('setup.region.format'), value: format },
    { key: 'currency', label: t('settings.region.currency'), value: src.currency || 'EUR' },
    { key: 'timezone', label: t('settings.region.timezone'), value: src.timezone || '' },
    { key: 'location', label: t('setup.region.weather'), value: src.location_name || '' },
    { key: 'hdd_base_temp', label: t('setup.region.heatingLimit'), value: fmt.num(src.hdd_base_temp, 1) + ' °C' },
    { key: 'co2_strom', label: t('settings.field.co2_strom.label'),
      value: years ? yearsText(src.co2_strom_years) : fmt.num(src.co2_strom, 0) + ' g/kWh' },
    { key: 'gas_cv_unit', label: t('settings.gasFactors.cvUnit'), value: cvUnit(src.gas_cv_unit).label },
  ];
}

/**
 * Speichern und sofort umstellen. `change`: `{country, profile}` (Land, mit
 * oder ohne Profil) oder ein PATCH für Währung bzw. Zeitzone.
 *
 * @param {{country?: string, profile?: boolean, currency?: string, timezone?: string}} change
 * @param {Array<object>} countries  Profile aus /api/countries
 * @returns {Promise<object>} alle Einstellungen nach dem Speichern
 */
export async function saveRegion(change, countries = []) {
  const s = change.country
    ? await saveCountry(change.country, change.profile !== false)
    : await saveSettings(change);
  setCurrencyParams(s?.currency);
  const p = countries.find(c => c.code === s?.country) || null;
  setCountry(s?.country, p?.languages);
  setBillContext(p);
  return s;
}
