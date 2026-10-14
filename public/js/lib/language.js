// =====================================================================
// v3.5.0 (N1022) — Sprache wechseln, ohne die Seite neu zu laden.
//
// Bis v3.4 stand dieser Ablauf allein in den Einstellungen. Jetzt wechselt
// auch der Einrichtungsassistent die Sprache, sobald man sie in Schritt 1
// wählt — über denselben Weg: Katalog laden, die Bezeichnungen der
// Verbrauchsarten neu holen (sie kommen übersetzt vom Server), Seitenleiste
// neu aufbauen. Das Ereignis `et:languagechange` beschriftet die Kopfleiste
// (app.js) nach. Gespeichert wird hier nichts.
// =====================================================================

import { initI18n, getLocale } from './i18n.js';
import { invalidateUtilities } from '../state.js';
import { buildSidebar } from './sidebar.js';

/**
 * @param {string} lang  Sprachcode (de, en, …); Unbekanntes wird Deutsch
 * @returns {Promise<string>} die jetzt aktive Sprache
 */
export async function switchLanguage(lang) {
  await initI18n(lang);
  invalidateUtilities();
  try { await buildSidebar(); } catch (e) { console.error('Seitenleiste', e); }
  window.dispatchEvent(new CustomEvent('et:languagechange', { detail: { lang: getLocale() } }));
  return getLocale();
}
