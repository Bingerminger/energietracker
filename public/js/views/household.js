// =====================================================================
// v3.12.0 (M3) — Haushalt: Zähler, Personen, Gebäude, Verbrauchsarten.
//
// Zähler stehen an einem Ort (views/meters.js, Filter `?art=`). Die anderen drei Seiten zeichnet das
// Gerüst der Einstellungen (views/settings.js, Speicherleiste und `?field=`), wie bei den Verbindungen.
// =====================================================================

import { render as renderSettingsPage } from './settings.js';
import { renderHouseholdMeters } from './meters.js';

export const HOUSEHOLD_PAGES = ['meters', 'people', 'building', 'utilities'];

export async function render(container, params = [], ctx = {}) {
  const page = HOUSEHOLD_PAGES.includes(params?.[0]) ? params[0] : 'meters';
  if (page === 'meters') return renderHouseholdMeters(container, ctx);
  return renderSettingsPage(container, [], { ...ctx, household: { page } });
}
