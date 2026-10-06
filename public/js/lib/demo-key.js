// =====================================================================
// v3.0.0 — Schlüssel einer Anfrage in der öffentlichen Demo.
//
// Die Demo auf GitHub Pages hat kein PHP. Ihre Antworten berechnet der echte
// Server beim Bauen (tools/build-demo.mjs) und legt sie unter einem Schlüssel
// ab; im Browser sucht lib/demo.js mit demselben Schlüssel. Beide Seiten
// benutzen deshalb diese eine Funktion: Pfad plus sortierte Query, damit
// `?b=1&a=2` und `?a=2&b=1` dieselbe Antwort finden.
// =====================================================================

/** @param {string} path z. B. `/api/utility/gas/meters?x=1` */
export function demoKey(path) {
  const clean = String(path).replace(/\/{2,}/g, '/');
  const i = clean.indexOf('?');
  if (i < 0) return clean;
  const params = [...new URLSearchParams(clean.slice(i + 1))]
    .filter(([, v]) => v !== '')
    .sort(([a, x], [b, y]) => (a === b ? (x < y ? -1 : x > y ? 1 : 0) : a < b ? -1 : 1));
  const q = new URLSearchParams(params).toString();
  return clean.slice(0, i) + (q ? '?' + q : '');
}

/**
 * Datei-Downloads (CSV, PDF) liegen in der Demo als fertige Dateien. Die
 * Query wandert vor die Endung, damit GitHub Pages den Typ richtig meldet;
 * `inline=1` (anzeigen statt herunterladen) spielt dafür keine Rolle.
 * `/api/reports/yearly.pdf?year=2025&inline=1` → `api/reports/yearly-year-2025.pdf`
 */
export function demoFileName(path) {
  const key = demoKey(String(path).replace(/([?&])inline=1(&|$)/, (m, a, b) => (b ? a : '')));
  const [p, q] = key.split('?');
  if (!q) return p.replace(/^\//, '');
  const dot = p.lastIndexOf('.');
  const suffix = '-' + q.replace(/[=&]/g, '-');
  return (dot > p.lastIndexOf('/') ? p.slice(0, dot) + suffix + p.slice(dot) : p + suffix).replace(/^\//, '');
}
