// =====================================================================
// Energietracker v2.2.0 — Utility-Farben aus der SSOT
//
// Bis v2.1.5 lagen die Verbrauchsart-Farben doppelt vor: einmal in
// `src/Config/Utilities.php` (aus der die Charts seit v2.1.5 lesen) und
// einmal als handgepflegte `--util-*`-Token in `tokens.css`. Das hatte zwei
// Folgen:
//
//   1. Die Token gab es nur für gas, strom und wasser. Die fünf später
//      ergänzten Verbrauchsarten (Fernwärme, Heizöl, Pellets, PV-Einspeisung,
//      PV-Erzeugung) hatten weder Überschriften- noch Button- noch
//      KPI-Farbe — ein Heizöl-Haushalt sah eine entfärbte App.
//   2. Bei Gas und Strom wichen die beiden Quellen voneinander ab, sodass
//      das Diagramm eine andere Farbe zeigte als der Knopf daneben.
//
// Dieses Modul erzeugt sämtliche Utility-Regeln zur Laufzeit aus der SSOT.
// Damit ist jede künftige Verbrauchsart automatisch vollständig eingefärbt —
// die Farbe steht an genau einer Stelle, in Utilities.php.
//
// v2.11.0 (Review UI-09) — Beide Theme-Varianten werden hier gerechnet und
// als Hex-Werte ausgegeben, je Farbe so weit getönt, bis sie als Schrift auf
// der dunkelsten bzw. hellsten Fläche (--bg-2) 4,5:1 erreicht. Die feste
// Mischung „62 % mit Schwarz" traf Strom (4,0:1) und PV-Erzeugung (3,7:1)
// im Hellmodus nicht, im Dunkelmodus lagen Heizöl und Pellets als Schrift
// unter 4,5:1. Die Schrift auf gefüllten Flächen (Knöpfe, aktive Jahres-Pille)
// ist die mit dem höheren Kontrast — bis v2.10 weiß auf Gas-Orange (2,2:1).
// Beide Varianten stehen im CSS; der Theme-Wechsel braucht keinen Listener.
// =====================================================================

import { contrast, mix } from './contrast.js';

const STYLE_ID = 'et-utility-theme';

// Anteil der Grundfarbe im hellen Theme als Startwert; der Rest ist Schwarz.
const LIGHT_MIX = 62;
// --bg-2 je Theme: Tabellenköpfe, verschachtelte Flächen — die ungünstigste
// Unterlage, auf der Utility-Farben als Schrift stehen
const SURFACE = { dark: '#1c2636', light: '#eef2f7' };
const TARGET = 4.5;
const DARK_TEXT = '#0a0d12';

/** Utility-Farbe für ein Theme: getönt, bis sie auf --bg-2 lesbar ist. */
export function themedColor(color, theme) {
  const toward = theme === 'light' ? '#000000' : '#ffffff';
  let share = theme === 'light' ? LIGHT_MIX / 100 : 1;
  let c = mix(color, toward, share);
  while (contrast(c, SURFACE[theme]) < TARGET && share > 0.2) {
    share -= 0.02;
    c = mix(color, toward, share);
  }
  return c;
}

/** Schrift auf einer gefüllten Fläche: die mit dem höheren Kontrast. */
export function textOn(bg) {
  return contrast('#ffffff', bg) >= contrast(DARK_TEXT, bg) ? '#fff' : DARK_TEXT;
}

/** Fallback, falls die API einmal keine Farbe liefert. */
const FALLBACK = '#4a90e2';

/**
 * Schreibt einen <style>-Block mit allen von der Verbrauchsart abhängigen
 * Regeln. Idempotent — ein erneuter Aufruf ersetzt den Block.
 *
 * @param {Array<{key: string, color?: string}>} utilities
 */
export function applyUtilityTheme(utilities) {
  if (!Array.isArray(utilities) || utilities.length === 0) return;

  const rootDark = [];
  const rootLight = [];
  const rules = [];

  for (const u of utilities) {
    const key = String(u?.key || '').trim();
    if (!key || !/^[a-z0-9_]+$/i.test(key)) continue;   // kein Fremdinhalt im CSS
    const color = normalizeHex(u?.color) || FALLBACK;
    const dark = themedColor(color, 'dark');
    const light = themedColor(color, 'light');

    rootDark.push(
      `  --util-${key}: ${dark};`,
      `  --util-${key}-soft: color-mix(in srgb, ${color} 14%, transparent);`,
      `  --util-${key}-fg: ${textOn(dark)};`
    );
    rootLight.push(
      `  --util-${key}: ${light};`,
      `  --util-${key}-soft: color-mix(in srgb, ${color} 10%, transparent);`,
      `  --util-${key}-fg: ${textOn(light)};`
    );

    rules.push(
      // Bindung für alles, was innerhalb eines [data-utility]-Containers
      // schlicht var(--util) nutzt (Subzähler-Rand, .btn--util, Tags …).
      `[data-utility="${key}"] { --util: var(--util-${key}); --util-soft: var(--util-${key}-soft); --util-fg: var(--util-${key}-fg); }`,
      // Buttons in Utility-Tönung
      `.btn-${key} { background: var(--util-${key}-soft); border-color: color-mix(in srgb, var(--util-${key}) 32%, transparent); color: var(--util-${key}); }`,
      `.btn-${key}:hover { background: var(--util-${key}); color: var(--util-${key}-fg); }`,
      // KPI-Kachel-Akzent
      `.kpi.c-${key} { --kpi-c: var(--util-${key}); }`,
      // Karte mit farbigem linken Rand
      `.card--${key} { border-left: 3px solid var(--util-${key}); }`,
      // Aktive Jahres-Pille
      `.year-pills .pill.active.${key} { background: var(--util-${key}); border-color: var(--util-${key}); color: var(--util-${key}-fg); }`,
      // Aktiv-Marker in der Seitenleiste
      `.sidebar__item[data-utility="${key}"].active::before { background: var(--util-${key}); }`
    );
  }

  const css = [
    '/* v2.2.0 — zur Laufzeit aus der Utilities-SSOT erzeugt. Nicht von Hand',
    '   pflegen: die Farbe einer Verbrauchsart steht in src/Config/Utilities.php. */',
    `:root {\n${rootDark.join('\n')}\n}`,
    `:root[data-theme="light"] {\n${rootLight.join('\n')}\n}`,
    ...rules,
  ].join('\n');

  let el = document.getElementById(STYLE_ID);
  if (!el) {
    el = document.createElement('style');
    el.id = STYLE_ID;
    document.head.appendChild(el);
  }
  el.textContent = css;
}

/**
 * Lässt nur saubere Hex-Farben durch. Alles andere käme aus der API in einen
 * <style>-Block — dort hat unvalidierter Text nichts verloren.
 */
function normalizeHex(value) {
  const v = String(value ?? '').trim();
  return /^#[0-9a-f]{3}$|^#[0-9a-f]{6}$/i.test(v) ? v : null;
}

