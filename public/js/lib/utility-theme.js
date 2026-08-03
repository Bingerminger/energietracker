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
// Die Light-Variante wird per `color-mix` im erzeugten CSS abgeleitet, nicht
// in JavaScript gerechnet: So folgt sie dem Theme-Wechsel ohne Neuberechnung
// und ohne Listener.
// =====================================================================

const STYLE_ID = 'et-utility-theme';

// Anteil der Grundfarbe im hellen Theme; der Rest ist Schwarz. 62 % trifft
// für die gesamte Palette einen Kontrast über 4.5:1 auf weißem Grund.
const LIGHT_MIX = 62;

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

    // Schrift auf gefüllter Fläche: helle Utility-Farben (Amber, Cyan,
    // Emerald) brauchen dunklen Text, dunkle (Violett, Rosé) hellen. Im
    // hellen Theme ist die Farbe ohnehin abgedunkelt — dort immer weiß.
    const fgDark = luminance(color) > 0.5 ? '#0a0d12' : '#fff';

    rootDark.push(
      `  --util-${key}: ${color};`,
      `  --util-${key}-soft: color-mix(in srgb, ${color} 14%, transparent);`,
      `  --util-${key}-fg: ${fgDark};`
    );
    rootLight.push(
      `  --util-${key}: color-mix(in srgb, ${color} ${LIGHT_MIX}%, #000);`,
      `  --util-${key}-soft: color-mix(in srgb, ${color} 10%, transparent);`,
      `  --util-${key}-fg: #fff;`
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

/** Relative Helligkeit nach WCAG, 0 (schwarz) bis 1 (weiß). */
function luminance(hex) {
  let h = hex.slice(1);
  if (h.length === 3) h = h.split('').map(c => c + c).join('');
  const chan = (i) => {
    const c = parseInt(h.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * chan(0) + 0.7152 * chan(2) + 0.0722 * chan(4);
}
