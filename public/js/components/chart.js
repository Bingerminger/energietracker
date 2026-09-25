// =====================================================================
// Energietracker v1.2.0 — Chart.js helper
// Liest alle Theme-Farben aus den CSS-Variablen, damit das Dark/Light-
// Theme-Toggle keine zweite Quelle der Wahrheit braucht.
//
// v2.15.0 (Review FE-11, FE-16, FE-20) — eine Stelle für alle Diagramme:
//   • Registry: Jedes Chart ist hier eingetragen, und ein Canvas trägt genau
//     eins — ein vorhandenes wird vorher zerstört. Zwei schnelle Klicks in
//     der Prognose warfen „Canvas is already in use“ roh in einen Toast, der
//     Tarifvergleich hängte je Terminwechsel ein Chart ab. Beim Seitenwechsel
//     (`et:route`) räumt die Schicht ab, was eine Ansicht liegen ließ.
//   • Theme: Beim Umschalten setzt sie die Vorgaben neu und zeichnet jedes
//     offene Chart neu. Farben, die vom Theme abhängen, stehen in den
//     Konfigurationen als Funktionen (`utilColor`, `tokenColor`) — Chart.js
//     wertet sie bei jedem Update aus. Bis v2.14 behielten offene Charts
//     ihre Farben: Die Prognoselinie verschwand im hellen Theme (1,0:1).
//   • Farben: `chartColor(u)` ist die Farbe der Verbrauchsart so getönt wie
//     im CSS (`themedColor`, 4,5:1 auf --bg-2). Bis v2.14 zeichneten die
//     Charts `u.color` unverändert — Strom stand mit 1,8:1 auf Weiß.
//   • Fehler landen in der Konsole, nie roh in einem Toast.
// =====================================================================

import { intlLocale, escapeHtml } from '../lib/format.js';
import { themedColor, normalizeHex } from '../lib/utility-theme.js';
import { t } from '../lib/i18n.js';

function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// Live-View auf die aktuellen Theme-Tokens. Frühere Versionen (≤ v1.1.0)
// exportierten dies als statisches Objekt — der Lookup über einen Proxy
// hält den Zugriff API-kompatibel, liefert aber stets den Live-Wert.
const TOKEN_MAP = {
  text1:  '--text-1',
  text2:  '--text-2',
  text3:  '--text-3',
  accent: '--accent',
  bg1:    '--bg-1',
  bg2:    '--bg-2',
  border: '--border-2',
  danger: '--danger',
  info:   '--info',
};

export const themeColors = new Proxy({}, {
  get(_t, prop) {
    const cssName = TOKEN_MAP[prop];
    return cssName ? cssVar(cssName) : undefined;
  }
});

const FALLBACK = '#4a90e2';

/** Aktuelles Theme, wie es auf <html data-theme> steht. */
export function currentTheme() {
  return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

/** Farbe einer Verbrauchsart fürs Canvas im aktuellen Theme — immer Hex (Lektion 27). */
export function chartColor(u) {
  return themedColor(normalizeHex(u?.color) || FALLBACK, currentTheme());
}

/** Hex-Farbe mit Deckkraft als rgba(); alles andere bleibt, wie es ist. */
export function withAlpha(color, alpha) {
  const v = String(color || '').trim();
  let h = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v)?.[1];
  if (!h) return v;
  if (h.length === 3) h = h.split('').map(c => c + c).join('');
  const n = parseInt(h, 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

/** Farbe der Verbrauchsart als Funktion für Chart.js — folgt dem Theme. */
export function utilColor(u, alpha = 1) {
  return () => (alpha >= 1 ? chartColor(u) : withAlpha(chartColor(u), alpha));
}

/** Theme-Token (text1, accent …) als Funktion für Chart.js — folgt dem Theme. */
export function tokenColor(name, alpha = 1) {
  return () => {
    const c = themeColors[name] || FALLBACK;
    return alpha >= 1 ? c : withAlpha(c, alpha);
  };
}

function gridColor() {
  // Halbtransparente Gitterlinien — funktionieren auf beiden Themes,
  // tönen aber je nach Hintergrund unterschiedlich.
  return currentTheme() === 'light'
    ? 'rgba(15, 23, 42, 0.06)'
    : 'rgba(255, 255, 255, 0.06)';
}

function applyDefaults() {
  if (!window.Chart) return;
  // v2.5.3 (FE-07) — Achsen und Tooltips in der App-Sprache. Ohne diese Zeile
  // nahm Chart.js die Browser-Locale: Bei englischem macOS und deutscher App
  // stand im Chart „1,000" für tausend und in der Tabelle „1,000" für eins.
  Chart.defaults.locale = intlLocale();
  Chart.defaults.color = themeColors.text2;
  Chart.defaults.borderColor = gridColor();
  Chart.defaults.font.family = 'DM Sans, system-ui, sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.plugins.legend.labels.color = themeColors.text1;
  Chart.defaults.plugins.tooltip.backgroundColor = themeColors.bg2;
  Chart.defaults.plugins.tooltip.borderColor = themeColors.border;
  Chart.defaults.plugins.tooltip.borderWidth = 1;
  Chart.defaults.plugins.tooltip.titleColor = themeColors.text1;
  Chart.defaults.plugins.tooltip.bodyColor  = themeColors.text2;
  Chart.defaults.plugins.tooltip.footerColor = themeColors.text2;
}

// ── Registry ─────────────────────────────────────────────────────────
const live = new Map();   // Canvas → Chart

function destroyOn(canvas) {
  const mine = live.get(canvas);
  let other = null;
  try { other = window.Chart?.getChart?.(canvas) || null; } catch { /* Stub */ }
  for (const c of new Set([mine, other])) {
    if (c) { try { c.destroy(); } catch { /* schon zerstört */ } }
  }
  live.delete(canvas);
}

/** Alle eingetragenen Charts zerstören — beim Seitenwechsel. */
export function destroyCharts() {
  for (const canvas of [...live.keys()]) destroyOn(canvas);
}

/** Zahl der offenen Charts (für Tests und Diagnose). */
export function liveChartCount() {
  return live.size;
}

/**
 * Chart.js kopiert die Achsen-Vorgaben (Schrift-, Gitter-, Randfarbe) beim
 * Anlegen in die Konfiguration des Charts; eine spätere Änderung an
 * `Chart.defaults` erreicht bestehende Achsen deshalb nicht — nach dem
 * Umschalten standen die Beschriftungen in der Farbe des alten Themes.
 * Die Ansichten setzen keine eigenen Achsenfarben; die kopierten werden
 * entfernt und beim nächsten update() aus den neuen Vorgaben gefüllt.
 */
function resetScaleColors(chart) {
  const scales = chart.config?.options?.scales;
  if (!scales || typeof scales !== 'object') return;
  for (const scale of Object.values(scales)) {
    for (const part of ['ticks', 'title', 'grid', 'border', 'pointLabels', 'angleLines']) {
      const o = scale?.[part];
      if (o && typeof o === 'object') delete o.color;
    }
  }
}

/** Nach einem Theme-Wechsel: Vorgaben neu, jedes offene Chart neu zeichnen. */
export function refreshCharts() {
  applyDefaults();
  for (const [canvas, chart] of [...live]) {
    // Abgehängt (Ansicht verlassen) oder von der Ansicht selbst zerstört
    if (!canvas.isConnected || chart.canvas === null) { destroyOn(canvas); continue; }
    try { resetScaleColors(chart); chart.update('none'); } catch (e) { console.error(e); }
  }
}

document.addEventListener('et:themechange', refreshCharts);
// Der Router meldet jede Navigation, bevor die neue Ansicht zeichnet —
// alles, was jetzt noch eingetragen ist, gehört zur alten.
window.addEventListener('et:route', destroyCharts);

// A11y (N1009): Ein <canvas> ist für Screenreader leer. Über `opts.label`
// wird es als `role="img"` mit beschreibendem `aria-label` ausgezeichnet, sodass
// das Diagramm wenigstens eine textuelle Zusammenfassung erhält. Ohne Label
// bleibt das Canvas aus dem Accessibility-Tree (aria-hidden), statt als
// bedeutungsloses Element zu erscheinen.
export function makeChart(canvas, config, opts = {}) {
  applyDefaults();
  if (!canvas) return null;
  if (opts.label) {
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', opts.label);
    canvas.removeAttribute('aria-hidden');
  } else {
    // Ein wiederverwendetes Canvas behielte sonst die Beschreibung des vorigen
    canvas.removeAttribute('role');
    canvas.removeAttribute('aria-label');
    canvas.setAttribute('aria-hidden', 'true');
  }
  if (!window.Chart) return null;
  destroyOn(canvas);
  try {
    const chart = new Chart(canvas, config);
    live.set(canvas, chart);
    return chart;
  } catch (e) {
    // v2.15.0 — nie roh in einen Toast: Die Ansicht bleibt bedienbar, das
    // Diagramm fehlt; die Ursache steht in der Konsole
    console.error(e);
    return null;
  }
}

/**
 * v2.15.0 (Review FE-20) — die Zahlen hinter einem Diagramm als Tabelle zum
 * Aufklappen: für Screenreader und für alle, die einen Wert genau wissen
 * wollen. Werte kommen fertig formatiert; leere werden „–“.
 *
 * @param {{caption?: string, columns: string[], rows: Array<Array<string|null>>}} spec
 */
export function chartTableHtml({ caption = '', columns = [], rows = [] }) {
  if (!rows.length) return '';
  const cell = (v, i) => i === 0
    ? `<th scope="row">${escapeHtml(v ?? '')}</th>`
    : `<td class="num">${escapeHtml(v == null || v === '' ? '–' : v)}</td>`;
  return `<details class="chart-data">
      <summary>${escapeHtml(t('chart.dataTable'))}</summary>
      <div class="table-wrap"><table class="table table--compact">
        ${caption ? `<caption class="sr-only">${escapeHtml(caption)}</caption>` : ''}
        <thead><tr>${columns.map((c, i) => `<th scope="col"${i ? ' class="num"' : ''}>${escapeHtml(c)}</th>`).join('')}</tr></thead>
        <tbody>${rows.map(r => `<tr>${r.map(cell).join('')}</tr>`).join('')}</tbody>
      </table></div>
    </details>`;
}
