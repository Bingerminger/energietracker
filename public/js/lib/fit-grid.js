// =====================================================================
// v3.12.0 (Designsprache) — Raster ohne Flattersatz (Regeln R3 und R4).
//
// Die Spaltenzahl folgt aus der Zahl sichtbarer Kacheln: 1 bis 6 in einer Reihe,
// 7 und 8 in vier Spalten, 9 in drei. Bleibt am Ende ein Rest, füllt die letzte
// Kachel die Reihe. Schmale Behälter bekommen weniger Spalten, damit keine Kachel
// unter ihre Mindestbreite fällt. Stufe und Breite ändern, was sichtbar ist; deshalb
// läuft das nach jedem Neuzeichnen und jeder Größenänderung.
// CSS: --spalten und --rest in app.css. Prüfung: tests/box-raster.test.mjs.
// =====================================================================

const GRID = '.grid, .kpi-grid, .dash-answers, .guide-tiles, .export-grid, .raster, .art-raster';
const SPALTEN = { 1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6, 7: 4, 8: 4, 9: 3, 10: 5, 11: 4, 12: 4 };
const GAP = 16;

/** Höchstzahl Spalten aus dem Markup: data-spalten oder .grid-2/-3/-4. */
function capOf(g) {
  if (g.dataset?.spalten) return Number(g.dataset.spalten);
  if (g.classList.contains('dash-answers')) return 0;
  for (const n of [2, 3, 4]) if (g.classList.contains(`grid-${n}`)) return n;
  return 0;
}

/** Spaltenzahl für n Kacheln in einem Behälter dieser Breite. */
export function columnsFor(n, width, { cap = 0, minCell = 170 } = {}) {
  let cols = SPALTEN[n] || 4;
  if (cap) cols = Math.min(cols, cap);
  if (width > 0) {
    const fit = Math.max(1, Math.floor((width + GAP) / (minCell + GAP)));
    if (fit < cols) {
      cols = fit;
      // v3.12.0 (Sichtung S7) — passt die Wunschzahl nicht, lieber volle Reihen: sechs Kacheln 3 × 2 statt 5 + 1
      for (let c = fit; c >= 2; c--) if (n % c === 0) { cols = c; break; }
    }
  }
  return Math.max(1, cols);
}

/** Kacheln mit Text oder Formular dürfen nicht so schmal werden wie reine Zahlen. */
function minCellOf(g) {
  if (g.classList.contains('kpi-grid') || g.dataset?.zellen === 'zahl') return 170;
  if (g.classList.contains('guide-tiles') || g.classList.contains('export-grid')) return 220;
  if (g.classList.contains('art-raster')) return 160;   // v3.12.0 (M4) — iPhone zwei Spalten, wie die Zahlen
  // v3.12.0 (m4b) — Antworten mit Sätzen (Einordnung, Tanks): erst ab 400 px nebeneinander, sonst bleibt unter der kürzeren zu viel leer
  if (g.dataset?.zellen === 'breit') return 400;
  return 280;
}

/** Ein Raster einrichten. Schreibt nur, was sich ändert: Der Tagesfilm löst das viele Male je Sekunde aus. */
export function fitGrid(g) {
  const kids = [...g.children].filter(c => getComputedStyle(c).display !== 'none');
  const n = kids.length;
  if (!n) return;
  let cols = columnsFor(n, g.clientWidth, { cap: capOf(g), minCell: minCellOf(g) });
  // v3.12.0 (M4) — Kacheln je Art: sechs stehen 3 × 2 statt 4 und eine breite
  if (g.classList.contains('art-raster') && n % cols) {
    for (let c = cols - 1; c >= 2; c--) if (n % c === 0) { cols = c; break; }
  }
  if (g.style.getPropertyValue('--spalten') !== String(cols)) g.style.setProperty('--spalten', String(cols));
  const rest = n % cols;
  const last = rest ? kids[n - 1] : null;
  for (const c of g.children) if (c !== last && c.classList.contains('raster-rest')) c.classList.remove('raster-rest');
  if (last) {
    const span = String(cols - rest + 1);
    if (!last.classList.contains('raster-rest')) last.classList.add('raster-rest');
    if (last.style.getPropertyValue('--rest') !== span) last.style.setProperty('--rest', span);
  }
}

export function fitGrids(root = document) {
  root.querySelectorAll(GRID).forEach(fitGrid);
}

/** Einmal beim Start: nach jedem Neuzeichnen, jeder Größenänderung und jedem Stufenwechsel. */
export function startFitGrids(root = document.body) {
  if (typeof MutationObserver === 'undefined') return;
  let queued = false;
  const later = typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (cb) => setTimeout(cb, 16);
  const run = () => {
    if (queued) return;
    queued = true;
    later(() => { queued = false; fitGrids(); });
  };
  new MutationObserver(run).observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
  window.addEventListener('resize', run);
  window.addEventListener('et:levelchange', run);
  run();
}
