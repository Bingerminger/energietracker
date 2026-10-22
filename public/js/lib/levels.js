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
import { isPv } from './semantics.js';

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
 * `settings:<unterseite>`).
 *
 * v3.7.0 (Paket reifegrad) — jede Seite steht hier, auch die für alle: Die
 * Zuordnung war seit v3.2.0 gewachsen statt geplant. Drei Sätze entscheiden:
 *   🌱 Einsteiger — „Ich will wissen, was ich verbrauche, was es kostet und ob
 *      der Abschlag reicht. Die Post vom Versorger soll die App für mich lesen.“
 *   🌿 Erfahren   — „Ich will vergleichen und prüfen.“
 *   🌳 Experte    — „Ich will alles sehen und anbinden.“
 * Der Haushalt bestimmt, welche Seiten jemand braucht, nicht die Stufe: Wer PV,
 * Speicher oder Wallbox hat, sieht die Seiten dazu auch als Einsteiger (Inhalt
 * einfach). tests/levels.test.mjs verlangt für jede Seite des Routers einen
 * Eintrag und prüft die Tabelle in docs/referenz/stufen.md dagegen. Fehlt ein
 * Schlüssel trotzdem (neue Seite), zeigt jede Stufe sie.
 */
export const VIEW_LEVEL = {
  // 🌱 — verbrauchen, kosten, Abschlag, Post, Termine
  'dashboard': 'beginner',          // Einsteiger: drei Antworten
  'readings-entry': 'beginner',
  'inbox': 'beginner',              // v3.7.0 — Posteingang: Ablegen, Auslesen, Übernehmen
  'inbox-draft': 'beginner',        // v3.7.0 — Entwurf aus Dokumenten (Erstbefüllung)
  'utility': 'beginner',            // Verbrauch je Art (auch PV, Wallbox, Wärmepumpe)
  'contracts-overview': 'beginner',
  'contracts': 'beginner',          // Verträge einer Art (Formular einfach, Rest ab Erfahren)
  'forecast': 'beginner',           // v3.6.0 (F1031) — Werkstatt: drei Regler, drei Zahlen
  'scenario-print': 'beginner',
  'flexibility': 'beginner',
  // v3.6.0 — Flexibilität für alle: Einsteiger sehen Ergebnis, Handlungssätze
  // und den typischen Tag; ab Erfahren Woche, Zeitfenster und Hebel; die
  // Nachrechnung je Zähler und die Viertelstundenreihen ab Experte (bis v3.5: Experte)
  'report': 'beginner',
  'report-print': 'beginner',
  'reminders': 'beginner',
  'recommendations': 'beginner',
  'help': 'beginner',
  'help-doc': 'beginner',
  'settings:general': 'beginner',
  'settings:household': 'beginner',
  'settings:utilities': 'beginner',
  'settings:data': 'beginner',
  // 🌿 — vergleichen und prüfen
  'tariffs': 'advanced',
  'bill-check': 'advanced',
  'tenancy': 'advanced',            // Einsteiger: „Reicht die Vorauszahlung?“ auf der Übersicht
  'analysis': 'advanced',
  'meters': 'advanced',             // Zähler-Aufbau (Subzähler, Rollen, Geräte)
  'temperatures': 'advanced',
  'activity': 'advanced',           // v3.4.0 (F1024) — Hinweise › Erledigt
  'settings:integrations': 'advanced',
  // 🌳 — alles sehen und anbinden
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

/** Mindeststufe einer Ansicht (Router-Schlüssel; `utility:gas` wie `utility`). */
export function viewLevel(key) {
  return VIEW_LEVEL[key] || VIEW_LEVEL[String(key).split(':')[0]] || 'beginner';
}
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
//
// v3.7.0 (Paket reifegrad) — Der Haushalt schlägt die Stufe vor, nicht die
// Datenmenge: Hat ein Einsteiger PV, Speicher, Wärmepumpe oder Wallbox, zeigt
// „Erfahren“ dazu Energiefluss je Monat, Speicher und Amortisation,
// Jahresarbeitszahl und Ladestrom-Nachweis. Experte bringt danach vor allem
// die Viertelstunden (Nachrechnung je Zähler, Reihen). Jeder Anlass erscheint
// höchstens einmal je Gerät und Stufe — wer ihn gesehen hat, bekommt ihn nicht
// wieder, auch ohne „Nicht mehr fragen“ (kein Hinweis-Spam).

const DISMISS_KEY = 'et-level-hints';

/** Anlässe je Stufe, in der Reihenfolge des Hinweises. */
export const SUGGEST_REASONS = {
  beginner: ['pv', 'battery', 'heatPump', 'wallbox', 'subMeters', 'severalMeters', 'homeAssistant'],
  advanced: ['quarterHours'],
};

/**
 * Anlässe aus dem Haushalt für den Vorschlag der nächsten Stufe.
 *
 * @param {string} level  Stufe, für die vorgeschlagen wird
 * @param {Array<{utility: string, role?: string|null, is_sub_meter?: boolean, external_id?: string|null}>} meters  Zähler aus /api/summary
 * @param {{utilities?: Array<{key: string, accounting_kind?: string}>, intervals?: number}} [ctx]
 *        utilities — aktive Verbrauchsarten (PV über `accounting_kind`, nicht über Namen);
 *        intervals — Zahl der Viertelstundenreihen (GET /api/intervals)
 * @returns {string[]}
 */
export function suggestReasons(level, meters = [], { utilities = [], intervals = 0 } = {}) {
  const byKey = new Map(utilities.map(u => [u.key, u]));
  const ms = (meters || []).filter(m => !byKey.size || byKey.has(m.utility));
  const role = (...roles) => ms.some(m => roles.includes(m.role));
  const found = {
    beginner: {
      pv: ms.some(m => isPv(byKey.get(m.utility))),
      battery: role('battery_charge', 'battery_discharge'),
      heatPump: role('heat_pump', 'heat_pump_output'),
      wallbox: role('ev_charger'),
      subMeters: ms.some(m => m.is_sub_meter),
      severalMeters: [...new Set(ms.map(m => m.utility))].some(k => ms.filter(m => m.utility === k && !m.is_sub_meter).length > 1),
      homeAssistant: ms.some(m => m.external_id),
    },
    advanced: { quarterHours: Number(intervals) > 0 },
  }[level] || {};
  return (SUGGEST_REASONS[level] || []).filter(r => found[r]);
}

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

/**
 * v3.7.0 — Diese Anlässe sind gezeigt worden: Sie erscheinen auf diesem Gerät
 * in dieser Stufe nicht noch einmal (gleiche Liste wie „Nicht mehr fragen“).
 */
export function markShown(reasons = []) { reasons.forEach(dismiss); }
