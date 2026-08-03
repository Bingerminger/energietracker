// =====================================================================
// Energietracker v1.2.0 — Lightweight state store
// Caches utilities config & settings so views don't refetch on every nav.
// =====================================================================

import { api } from './api.js';

const state = {
  utilities: null,
  settings: null,
  version: document.body.getAttribute('data-app-version') || '1.2.0',
};

export async function getUtilities() {
  if (state.utilities) return state.utilities;
  state.utilities = await api.listUtilities();
  return state.utilities;
}

export function getUtilitiesSync() { return state.utilities || []; }

export async function getUtility(key) {
  const list = await getUtilities();
  return list.find(u => u.key === key) || null;
}

export async function getSettings() {
  if (state.settings) return state.settings;
  state.settings = await api.settings();
  return state.settings;
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

export function invalidateSettings() { state.settings = null; }

// Bei Sprachwechsel: die Utility-Labels kommen lokalisiert vom Backend,
// daher den Cache verwerfen, damit getUtilities() neu lädt.
export function invalidateUtilities() { state.utilities = null; }
