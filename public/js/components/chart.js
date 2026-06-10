// =====================================================================
// Energietracker v1.2.0 — Chart.js helper
// Liest alle Theme-Farben aus den CSS-Variablen, damit das Dark/Light-
// Theme-Toggle keine zweite Quelle der Wahrheit braucht. Beim Theme-
// Wechsel werden Chart.defaults neu gesetzt; bereits gerenderte Charts
// rendern sich erst auf Re-Render neu (akzeptabel — eine Navigation
// genügt).
// =====================================================================

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
};

export const themeColors = new Proxy({}, {
  get(_t, prop) {
    const cssName = TOKEN_MAP[prop];
    return cssName ? cssVar(cssName) : undefined;
  }
});

function gridColor() {
  // Halbtransparente Gitterlinien — funktionieren auf beiden Themes,
  // tönen aber je nach Hintergrund unterschiedlich.
  return document.documentElement.getAttribute('data-theme') === 'light'
    ? 'rgba(15, 23, 42, 0.06)'
    : 'rgba(255, 255, 255, 0.06)';
}

function applyDefaults() {
  if (!window.Chart) return;
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
}

// Beim Theme-Wechsel die Defaults neu setzen, damit neu erstellte Charts
// sofort korrekt eingefärbt sind.
document.addEventListener('et:themechange', applyDefaults);

// A11y (N1009): Ein <canvas> ist für Screenreader leer. Über `opts.label`
// wird es als `role="img"` mit beschreibendem `aria-label` ausgezeichnet, sodass
// das Diagramm wenigstens eine textuelle Zusammenfassung erhält. Ohne Label
// bleibt das Canvas aus dem Accessibility-Tree (aria-hidden), statt als
// bedeutungsloses Element zu erscheinen.
export function makeChart(canvas, config, opts = {}) {
  applyDefaults();
  if (canvas) {
    if (opts.label) {
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', opts.label);
      canvas.removeAttribute('aria-hidden');
    } else {
      canvas.setAttribute('aria-hidden', 'true');
    }
  }
  return new Chart(canvas, config);
}
