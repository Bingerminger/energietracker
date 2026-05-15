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

export function invalidateSettings() { state.settings = null; }
