// =====================================================================
// Energietracker v1.2.0 — Lightweight state store
// Caches utilities config & settings so views don't refetch on every nav.
//
// v2.11.0 (Review FE-12) — gemerkt wird das Promise, nicht erst das
// Ergebnis: Parallele Aufrufe beim Start (Seitenleiste, Ansicht, Badges)
// fragten sonst jeder einzeln an. Die Abrufe laufen ohne Navigationssignal
// (`appScope`), weil der Cache jede Ansicht überlebt. Einstellungen werden
// über `saveSettings()` geschrieben — das hält den Cache aktuell und meldet
// die Änderung (`et:settingschange`), damit Seitenleiste und Theme folgen.
// Bis v2.10 las die Temperaturansicht nach dem Speichern den alten Standort
// aus dem Cache und schrieb ihn beim nächsten Klick zurück.
// =====================================================================

import { api, appScope } from './api.js';

const state = {
  utilities: null,
  utilitiesP: null,
  settings: null,
  settingsP: null,
  countries: null,
  version: document.body.getAttribute('data-app-version') || '1.2.0',
};

/** Merkt ein Promise; ein Fehler wird nicht gemerkt (nächster Aufruf fragt neu). */
function memo(key, load, assign) {
  if (!state[key]) {
    state[key] = appScope(load)
      .then(value => { assign(value); return value; })
      .catch(err => { state[key] = null; throw err; });
  }
  return state[key];
}

export function getUtilities() {
  return memo('utilitiesP', () => api.listUtilities(), v => { state.utilities = v; });
}

export function getUtilitiesSync() { return state.utilities || []; }

export async function getUtility(key) {
  const list = await getUtilities();
  return list.find(u => u.key === key) || null;
}

export function getSettings() {
  return memo('settingsP', () => api.settings(), v => { state.settings = v; });
}

/**
 * v2.11.0 — Einstellungen speichern (PATCH) und alle Beteiligten
 * benachrichtigen. Die Antwort des Servers ist der vollständige neue Stand.
 *
 * @param {Record<string, unknown>} patch
 * @returns {Promise<Record<string, unknown>>}
 */
export async function saveSettings(patch) {
  const result = await api.updateSettings(patch);
  const { ignored_keys: _ignored, ...settings } = result || {};
  state.settings = settings;
  state.settingsP = Promise.resolve(settings);
  window.dispatchEvent(new CustomEvent('et:settingschange', {
    detail: { keys: Object.keys(patch || {}), settings },
  }));
  return result;
}

/**
 * v2.2.0 — Verbrauchsarten, die der Nutzer in den Einstellungen aktiviert hat.
 *
 * Dashboard und Seitenleiste filterten danach, Prognose und Analyse nicht —
 * dort tauchten abgeschaltete Arten weiter in der Auswahl auf und führten auf
 * leere Ansichten. Eine leere `active_utilities`-Liste bedeutet „alle".
 */
export async function activeUtilities() {
  const [utilities, settings] = await Promise.all([getUtilities(), getSettings()]);
  const active = Array.isArray(settings?.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities
    : utilities.map(u => u.key);
  return utilities.filter(u => active.includes(u.key));
}

/**
 * v2.7.0 — Länderprofile (GET /api/countries). Statische Daten, einmal je
 * Sitzung geladen; ohne Netz eine leere Liste — dann gilt die Region der
 * Sprache wie vor v2.7.0.
 */
export async function getCountries() {
  if (state.countries) return state.countries;
  try { state.countries = await appScope(() => api.countries()); } catch { return []; }
  return state.countries;
}

export function invalidateSettings() { state.settings = null; state.settingsP = null; }

// Bei Sprachwechsel: die Utility-Labels kommen lokalisiert vom Backend,
// daher den Cache verwerfen, damit getUtilities() neu lädt.
export function invalidateUtilities() { state.utilities = null; state.utilitiesP = null; }
