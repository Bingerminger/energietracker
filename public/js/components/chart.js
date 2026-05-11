// =====================================================================
// Chart.js helper. Applies theme defaults (dark, DM Mono, ticks etc.)
// =====================================================================

const T = {
  text1: '#dce8f5',
  text2: '#6b7f99',
  text3: '#3d5070',
  accent:'#4a90e2',
  grid:  'rgba(255,255,255,0.06)',
  border:'rgba(255,255,255,0.12)',
};

function applyDefaults() {
  if (!window.Chart) return;
  if (window.Chart._etThemed) return;
  Chart.defaults.color = T.text2;
  Chart.defaults.borderColor = T.grid;
  Chart.defaults.font.family = 'DM Sans, system-ui, sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.plugins.legend.labels.color = T.text1;
  Chart.defaults.plugins.tooltip.backgroundColor = '#1a1f29';
  Chart.defaults.plugins.tooltip.borderColor = '#2e3645';
  Chart.defaults.plugins.tooltip.borderWidth = 1;
  Chart.defaults.plugins.tooltip.titleColor = T.text1;
  Chart.defaults.plugins.tooltip.bodyColor  = T.text2;
  window.Chart._etThemed = true;
}

export function makeChart(canvas, config) {
  applyDefaults();
  return new Chart(canvas, config);
}

export const themeColors = T;
