// =====================================================================
// Energietracker v2.11.0 — Kontrast nach WCAG 2.x
//
// Gemeinsame Rechnung für utility-theme.js (Tönung der Verbrauchsart-Farben)
// und den Kontrast-Test (tests/contrast.test.mjs). Nur Hex-Farben (#rgb,
// #rrggbb) — was aus der API kommt, ist vorher geprüft.
// =====================================================================

function channels(hex) {
  let h = String(hex).replace('#', '');
  if (h.length === 3) h = h.split('').map(c => c + c).join('');
  return [0, 2, 4].map(i => parseInt(h.slice(i, i + 2), 16));
}

/** Relative Helligkeit, 0 (schwarz) bis 1 (weiß). */
export function luminance(hex) {
  const [r, g, b] = channels(hex).map(v => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Kontrastverhältnis 1–21. */
export function contrast(a, b) {
  const x = luminance(a), y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/** `share` Anteil von `a`, Rest `b` — wie color-mix(in srgb, a share, b). */
export function mix(a, b, share) {
  const A = channels(a), B = channels(b);
  return '#' + A.map((v, i) => Math.round(v * share + B[i] * (1 - share)).toString(16).padStart(2, '0')).join('');
}
