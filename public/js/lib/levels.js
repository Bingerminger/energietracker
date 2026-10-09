// =====================================================================
// v3.2.0 (F1019) — Nutzungsstufen: Einsteiger · Erfahren · Experte.
//
// Die Stufe bestimmt, was die Oberfläche zeigt — nicht, was die App rechnet.
// Ausgeblendetes bleibt erreichbar (ein Link darauf öffnet die Seite mit
// einem Hinweis), Daten und Schnittstellen sind in jeder Stufe dieselben.
//
// Eine Quelle für drei Wege:
//   • Seiten:   VIEW_LEVEL — Navigation (nav-model.js) und Router lesen daraus.
//   • Elemente: `data-min-level="advanced|expert"` in den Vorlagen; CSS blendet
//     sie über `html[data-level]` aus (app.css). Ausgeblendete Formularfelder
//     bleiben im Formular — ein Vertrag verliert beim Speichern in der
//     Einsteigerstufe keine Preiswechsel.
//   • Code:     atLeast('advanced') für Inhalte, die gar nicht erst geladen
//     werden sollen.
//
// Die Stufe gilt je Installation (`ui_level`); mit Benutzern (F1023) hat jede
// Person ihre eigene (`prefs.ui_level`). Bestand nach dem Update: Experte.
// =====================================================================

import { api } from '../api.js';
import { saveSettings, getSettingsSync } from '../state.js';
import { DEMO } from './demo-mode.js';

// Öffentliche Demo: nichts wird gespeichert — die Stufe merkt sich der Browser
const DEMO_KEY = 'et-demo-level';
function demoStored() {
  try { const v = localStorage.getItem(DEMO_KEY); return LEVELS.includes(v) ? v : null; } catch { return null; }
}

export const LEVELS = ['beginner', 'advanced', 'expert'];
const RANK = { beginner: 0, advanced: 1, expert: 2 };
export const LEVEL_ICONS = { beginner: '🌱', advanced: '🌿', expert: '🌳' };

/**
 * Mindeststufe je Ansicht (Schlüssel wie im Router: Ansicht oder
 * `settings:<unterseite>`). Fehlt ein Eintrag, zeigt jede Stufe die Seite.
 */
export const VIEW_LEVEL = {
  'tariffs': 'advanced',
  'bill-check': 'advanced',
  'tenancy': 'advanced',            // Einsteiger: Budget-Karte auf der Übersicht
  'analysis': 'advanced',
  'forecast': 'advanced',
  'meters': 'advanced',             // Zähler-Aufbau (Subzähler, Rollen, Geräte)
  'temperatures': 'advanced',
  'settings:integrations': 'advanced',
  'settings:access': 'expert',
  'settings:expert': 'expert',
  'settings:system': 'expert',
};

let current = 'expert';
let session = null;   // Antwort von GET /api/session

/** Sitzung merken (Person, Rolle) — app.js nach dem Start. */
export function setSession(s) { session = s || null; }
export function sessionUser() { return session?.user || null; }
/** admin | member — ohne Anmeldung darf jeder alles. */
export function sessionRole() { return session?.role || 'admin'; }

/** Stufe dieser Person (mit Anmeldung) oder der Installation. */
export function resolveLevel(settings = getSettingsSync(), sess = session) {
  if (DEMO) return demoStored() || 'expert';
  const own = sess?.user?.prefs?.ui_level;
  const level = own || settings?.ui_level;
  return LEVELS.includes(level) ? level : 'expert';
}

export function currentLevel() { return current; }

export function atLeast(min) {
  return RANK[current] >= (RANK[min] ?? 0);
}

/** Mindeststufe einer Ansicht (Router-Schlüssel). */
export function viewLevel(key) { return VIEW_LEVEL[key] || 'beginner'; }
export function viewAllowed(key) { return atLeast(viewLevel(key)); }

/** Nächsthöhere Stufe (oder null bei Experte). */
export function nextLevel(level = current) {
  const i = LEVELS.indexOf(level);
  return i >= 0 && i < LEVELS.length - 1 ? LEVELS[i + 1] : null;
}

/** Stufe anwenden (Attribut für das CSS, Ereignis für Navigation und Ansichten). */
export function applyLevel(level) {
  const next = LEVELS.includes(level) ? level : 'expert';
  const changed = next !== current;
  current = next;
  document.documentElement.setAttribute('data-level', current);
  if (changed) window.dispatchEvent(new CustomEvent('et:levelchange', { detail: { level: current } }));
}

/**
 * Stufe speichern: mit Anmeldung als eigene Einstellung der Person, sonst für
 * die Installation. Danach gilt sie sofort.
 */
export async function saveLevel(level) {
  if (!LEVELS.includes(level)) return;
  if (DEMO) {
    try { localStorage.setItem(DEMO_KEY, level); } catch { /* gilt bis zum Neuladen */ }
  } else if (session?.user && session.mode !== 'off') {
    const user = await api.updateMe({ ui_level: level });
    session = { ...session, user };
  } else {
    await saveSettings({ ui_level: level });
  }
  applyLevel(level);
}

// ── Hinweis „gehört zu einer höheren Stufe" ─────────────────────────────
// Abgelehnte Vorschläge merkt sich das Gerät (je Stufe und Anlass); der
// Hinweis blockiert nie.

const DISMISS_KEY = 'et-level-hints';

function dismissed() {
  try { return JSON.parse(localStorage.getItem(DISMISS_KEY) || '[]'); } catch { return []; }
}

export function isDismissed(reason) { return dismissed().includes(`${current}:${reason}`); }

export function dismiss(reason) {
  try {
    const list = new Set(dismissed());
    list.add(`${current}:${reason}`);
    localStorage.setItem(DISMISS_KEY, JSON.stringify([...list].slice(-50)));
  } catch { /* privat: gilt bis zum Neuladen */ }
}
