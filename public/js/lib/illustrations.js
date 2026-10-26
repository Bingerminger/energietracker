// =====================================================================
// v3.9.0 (Paket oberflaeche) — Illustrationen und Schaltbilder als Inline-SVG.
//
// Eine Grundlage für die kleinen Bilder der Ersteinrichtung und die
// Schaltbilder des Assistenten „Messkonzept“, damit beide gleich aussehen:
//
//   • Strichzeichnung wie die Piktogramme des Energieflusses: runde Enden,
//     keine Verläufe, keine Schatten, kein Bildmaterial von außen.
//   • Farben nur über CSS-Variablen (tokens.css: --ill-line, --ill-ink,
//     --ill-accent, --ill-fill, --ill-surface, --ill-ground) und die Klassen
//     .ill-* (components.css). Hell und dunkel folgen damit dem Thema; eine
//     Verbrauchsart färbt über .ill-c-<art> (--c aus der Utilities-SSOT).
//   • Strichstärken in CSS-Pixeln (--ill-stroke 1,5 px, --ill-stroke-strong
//     2 px), unabhängig von der Größe (vector-effect: non-scaling-stroke).
//   • Bedeutung: Ein Bild, das etwas sagt (Schaltbild), bekommt role="img"
//     und einen Titel aus den Katalogen (`title`). Reine Zier (die Frage
//     daneben sagt alles) ist aria-hidden — so liest kein Bildschirmleser
//     „Bild“ ohne Inhalt vor.
//
// Bausteine (`shapes`) liefern SVG-Stücke in Koordinaten der viewBox; `svg()`
// rahmt sie. Beispiel Schaltbild:
//
//   svg(shapes.meter(10, 20, { label: '1.8.0', util: 'strom' })
//     + shapes.wire([[36, 35], [70, 35]]) + shapes.house(70, 10, 40, 40),
//     { viewBox: '0 0 120 60', title: t('…') })
// =====================================================================

import { escapeHtml as esc } from './format.js';

/** SVG-Rahmen. `title` → role="img" mit Namen; ohne → aria-hidden (Zier). */
export function svg(body, { viewBox = '0 0 120 80', title = '', className = '' } = {}) {
  const cls = `ill${className ? ' ' + className : ''}`;
  const head = `<svg class="${cls}" viewBox="${viewBox}" xmlns="http://www.w3.org/2000/svg"`;
  return title
    ? `${head} role="img" aria-label="${esc(title)}"><title>${esc(title)}</title>${body}</svg>`
    : `${head} aria-hidden="true" focusable="false">${body}</svg>`;
}

const n = (v) => Math.round(v * 10) / 10;
const pts = (list) => list.map(([x, y]) => `${n(x)},${n(y)}`).join(' ');

/** Bausteine in viewBox-Koordinaten. */
export const shapes = {
  /** Haus: Umriss mit Dach und Tür; `chimney` mit Schornstein. (x, y) = linke obere Ecke der Dachspitze-Box. */
  house(x, y, w = 40, h = 36, { chimney = false, fill = true } = {}) {
    const roof = h * 0.42, wall = y + roof;
    const door = Math.min(w * 0.22, 9);
    return `<g>${fill ? `<path class="ill-fill" d="M${n(x + 3)},${n(wall)} L${n(x + w / 2)},${n(y + 2)} L${n(x + w - 3)},${n(wall)} V${n(y + h)} H${n(x + 3)} Z"/>` : ''}
      ${chimney ? `<path class="ill-line" d="M${n(x + w * 0.7)},${n(y + roof * 0.55)} V${n(y + roof * 0.1)} H${n(x + w * 0.8)} V${n(y + roof * 0.78)}"/>` : ''}
      <path class="ill-line" d="M${n(x)},${n(wall + 2)} L${n(x + w / 2)},${n(y)} L${n(x + w)},${n(wall + 2)} M${n(x + 4)},${n(wall)} V${n(y + h)} H${n(x + w - 4)} V${n(wall)}"/>
      <path class="ill-line" d="M${n(x + w / 2 - door / 2)},${n(y + h)} V${n(y + h - door * 1.5)} H${n(x + w / 2 + door / 2)} V${n(y + h)}"/></g>`;
  },
  /** Mehrfamilienhaus: Block mit Fensterreihen. */
  block(x, y, w = 34, h = 44, { rows = 4, cols = 3 } = {}) {
    let win = '';
    const gx = w / (cols + 1), gy = (h - 10) / (rows + 0.5);
    for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) {
      win += `<rect class="ill-line" x="${n(x + gx * (c + 1) - 2.5)}" y="${n(y + 5 + gy * r)}" width="5" height="${n(gy * 0.5)}" rx="1"/>`;
    }
    return `<g><rect class="ill-fill" x="${n(x)}" y="${n(y)}" width="${n(w)}" height="${n(h)}" rx="2"/>
      <rect class="ill-line" x="${n(x)}" y="${n(y)}" width="${n(w)}" height="${n(h)}" rx="2"/>${win}</g>`;
  },
  /**
   * Zähler: Gehäuse mit Anzeige und Zählwerk. `label` (etwa „1.8.0“) steht in
   * der Anzeige; `util` färbt den Rand nach der Verbrauchsart (strom, gas …).
   */
  meter(x, y, { w = 26, h = 30, label = '', util = '' } = {}) {
    return `<g${util ? ` class="ill-c-${esc(util)}"` : ''}><rect class="ill-face" x="${n(x)}" y="${n(y)}" width="${n(w)}" height="${n(h)}" rx="3"/>
      ${util ? `<rect class="ill-util" x="${n(x)}" y="${n(y)}" width="${n(w)}" height="${n(h)}" rx="3"/>` : ''}
      <rect class="ill-line" x="${n(x + 4)}" y="${n(y + 5)}" width="${n(w - 8)}" height="${n(h * 0.3)}" rx="1.5"/>
      ${label ? `<text class="ill-text" x="${n(x + w / 2)}" y="${n(y + 5 + h * 0.3 - 2.5)}" text-anchor="middle">${esc(label)}</text>` : ''}
      <circle class="ill-dot" cx="${n(x + w / 2)}" cy="${n(y + h * 0.72)}" r="2"/></g>`;
  },
  /** Leitung als Linienzug; `strong` für die Hauptleitung. */
  wire(points, { strong = false } = {}) {
    return `<polyline class="${strong ? 'ill-ink' : 'ill-line'}" points="${pts(points)}"/>`;
  },
  /** Pfeil von (x1,y1) nach (x2,y2), Spitze am Ende. */
  arrow(x1, y1, x2, y2, { accent = true } = {}) {
    const a = Math.atan2(y2 - y1, x2 - x1), s = 4.5;
    const p1 = [x2 - s * Math.cos(a - 0.5), y2 - s * Math.sin(a - 0.5)];
    const p2 = [x2 - s * Math.cos(a + 0.5), y2 - s * Math.sin(a + 0.5)];
    const cls = accent ? 'ill-accent' : 'ill-line';
    return `<path class="${cls}" d="M${n(x1)},${n(y1)} L${n(x2)},${n(y2)} M${n(p1[0])},${n(p1[1])} L${n(x2)},${n(y2)} L${n(p2[0])},${n(p2[1])}"/>`;
  },
  /** Boden unter einer Szene. */
  ground(x1, x2, y) { return `<path class="ill-ground" d="M${n(x1)},${n(y)} H${n(x2)}"/>`; },
  /** Sonne. */
  sun(cx, cy, r = 6) {
    const rays = [0, 45, 90, 135, 180, 225, 270, 315].map(d => {
      const a = d * Math.PI / 180;
      return `M${n(cx + Math.cos(a) * (r + 3))},${n(cy + Math.sin(a) * (r + 3))} L${n(cx + Math.cos(a) * (r + 6))},${n(cy + Math.sin(a) * (r + 6))}`;
    }).join(' ');
    return `<g class="ill-c-pv"><circle class="ill-util-fill" cx="${n(cx)}" cy="${n(cy)}" r="${n(r)}"/><circle class="ill-util" cx="${n(cx)}" cy="${n(cy)}" r="${n(r)}"/><path class="ill-util" d="${rays}"/></g>`;
  },
  /** Blitz (Strom). */
  bolt(cx, cy, s = 1) {
    return `<g class="ill-c-strom"><path class="ill-util" d="M${n(cx + 2 * s)},${n(cy - 9 * s)} L${n(cx - 4 * s)},${n(cy + 1 * s)} H${n(cx + 1 * s)} L${n(cx - 2 * s)},${n(cy + 9 * s)} L${n(cx + 4 * s)},${n(cy - 1 * s)} H${n(cx - 1 * s)} Z"/></g>`;
  },
  /** Flamme (Gas, Heizung). */
  flame(cx, cy, s = 1, util = 'gas') {
    return `<g class="ill-c-${esc(util)}"><path class="ill-util-fill" d="M${n(cx)},${n(cy - 9 * s)} C${n(cx + 7 * s)},${n(cy - 2 * s)} ${n(cx + 6 * s)},${n(cy + 8 * s)} ${n(cx)},${n(cy + 8 * s)} C${n(cx - 6 * s)},${n(cy + 8 * s)} ${n(cx - 7 * s)},${n(cy)} ${n(cx)},${n(cy - 9 * s)} Z"/>
      <path class="ill-util" d="M${n(cx)},${n(cy - 9 * s)} C${n(cx + 7 * s)},${n(cy - 2 * s)} ${n(cx + 6 * s)},${n(cy + 8 * s)} ${n(cx)},${n(cy + 8 * s)} C${n(cx - 6 * s)},${n(cy + 8 * s)} ${n(cx - 7 * s)},${n(cy)} ${n(cx)},${n(cy - 9 * s)} Z M${n(cx)},${n(cy + 8 * s)} C${n(cx - 3 * s)},${n(cy + 8 * s)} ${n(cx - 3 * s)},${n(cy + 3 * s)} ${n(cx)},${n(cy + 1 * s)}"/></g>`;
  },
  /** Tropfen (Wasser). */
  drop(cx, cy, s = 1) {
    const d = `M${n(cx)},${n(cy - 9 * s)} C${n(cx + 3 * s)},${n(cy - 4 * s)} ${n(cx + 7 * s)},${n(cy)} ${n(cx + 7 * s)},${n(cy + 3 * s)} A${n(7 * s)},${n(7 * s)} 0 0 1 ${n(cx - 7 * s)},${n(cy + 3 * s)} C${n(cx - 7 * s)},${n(cy)} ${n(cx - 3 * s)},${n(cy - 4 * s)} ${n(cx)},${n(cy - 9 * s)} Z`;
    return `<g class="ill-c-wasser"><path class="ill-util-fill" d="${d}"/><path class="ill-util" d="${d}"/></g>`;
  },
  /** PV-Modul (schräg auf einem Dach): linke untere Ecke (x, y), Breite w. */
  panel(x, y, w = 18) {
    const h = w * 0.5;
    return `<g class="ill-c-pv"><path class="ill-util-fill" d="M${n(x)},${n(y)} L${n(x + w * 0.25)},${n(y - h)} H${n(x + w * 1.25)} L${n(x + w)},${n(y)} Z"/>
      <path class="ill-util" d="M${n(x)},${n(y)} L${n(x + w * 0.25)},${n(y - h)} H${n(x + w * 1.25)} L${n(x + w)},${n(y)} Z M${n(x + w * 0.5)},${n(y)} L${n(x + w * 0.75)},${n(y - h)} M${n(x + w * 0.12)},${n(y - h / 2)} H${n(x + w * 1.12)}"/></g>`;
  },
  /** Batteriespeicher: Gehäuse mit Pol, Füllstand getönt. */
  battery(x, y, { w = 16, h = 26 } = {}) {
    return `<g><rect class="ill-face" x="${n(x)}" y="${n(y)}" width="${n(w)}" height="${n(h)}" rx="2.5"/>
      <rect class="ill-line" x="${n(x + w * 0.3)}" y="${n(y - 3)}" width="${n(w * 0.4)}" height="3" rx="1"/>
      <rect class="ill-fill" x="${n(x + 3)}" y="${n(y + h * 0.4)}" width="${n(w - 6)}" height="${n(h * 0.6 - 3)}" rx="1"/>
      <path class="ill-accent" d="M${n(x + w / 2 + 1)},${n(y + h * 0.18)} L${n(x + w / 2 - 2)},${n(y + h * 0.36)} H${n(x + w / 2 + 2)} L${n(x + w / 2 - 1)},${n(y + h * 0.54)}"/></g>`;
  },
  /** Wallbox: Kasten mit Anzeige, Kabel nach rechts unten mit Stecker. */
  wallbox(x, y, { w = 16, h = 22 } = {}) {
    return `<g><rect class="ill-face" x="${n(x)}" y="${n(y)}" width="${n(w)}" height="${n(h)}" rx="3"/>
      <rect class="ill-line" x="${n(x + 4)}" y="${n(y + 4)}" width="${n(w - 8)}" height="${n(h * 0.25)}" rx="1"/>
      <circle class="ill-dot" cx="${n(x + w / 2)}" cy="${n(y + h * 0.68)}" r="1.8"/>
      <path class="ill-line" d="M${n(x + w / 2)},${n(y + h)} C${n(x + w / 2)},${n(y + h + 8)} ${n(x + w + 6)},${n(y + h + 8)} ${n(x + w + 6)},${n(y + h)}"/>
      <rect class="ill-line" x="${n(x + w + 3.5)}" y="${n(y + h - 6)}" width="5" height="6" rx="1"/></g>`;
  },
  /** Wärmepumpe (Außengerät): Gehäuse mit Lüftungsschlitzen und Lüfter. */
  heatpump(x, y, { w = 30, h = 22 } = {}) {
    const r = Math.min(h * 0.32, w * 0.2), cx = x + w * 0.66, cy = y + h / 2;
    return `<g class="ill-c-waerme"><rect class="ill-face" x="${n(x)}" y="${n(y)}" width="${n(w)}" height="${n(h)}" rx="2.5"/>
      <path class="ill-line" d="M${n(x + 4)},${n(y + h * 0.3)} H${n(x + w * 0.38)} M${n(x + 4)},${n(y + h * 0.5)} H${n(x + w * 0.38)} M${n(x + 4)},${n(y + h * 0.7)} H${n(x + w * 0.38)}"/>
      <circle class="ill-util" cx="${n(cx)}" cy="${n(cy)}" r="${n(r)}"/>
      <path class="ill-util" d="M${n(cx)},${n(cy - r * 0.7)} V${n(cy + r * 0.7)} M${n(cx - r * 0.7)},${n(cy)} H${n(cx + r * 0.7)}"/></g>`;
  },
  /** Netzanschluss: Strommast, Fuß bei (cx, y). */
  grid(cx, y, s = 1) {
    return `<path class="ill-ink" d="M${n(cx - 7 * s)},${n(y)} L${n(cx - 2.5 * s)},${n(y - 22 * s)} H${n(cx + 2.5 * s)} L${n(cx + 7 * s)},${n(y)} M${n(cx - 5 * s)},${n(y - 9 * s)} H${n(cx + 5 * s)} M${n(cx - 8 * s)},${n(y - 17 * s)} H${n(cx + 8 * s)} M${n(cx - 6 * s)},${n(y - 4 * s)} L${n(cx + 4 * s)},${n(y - 13 * s)}"/>`;
  },
  /** Blatt Papier mit Textzeilen; `check` mit Haken. */
  doc(x, y, { w = 26, h = 32, check = false } = {}) {
    const fold = 7;
    return `<g><path class="ill-face" d="M${n(x)},${n(y)} H${n(x + w - fold)} L${n(x + w)},${n(y + fold)} V${n(y + h)} H${n(x)} Z"/>
      <path class="ill-line" d="M${n(x + w - fold)},${n(y)} V${n(y + fold)} H${n(x + w)} M${n(x + 5)},${n(y + 12)} H${n(x + w - 5)} M${n(x + 5)},${n(y + 17)} H${n(x + w - 8)} M${n(x + 5)},${n(y + 22)} H${n(x + w - 6)}"/>
      ${check ? `<path class="ill-accent" d="M${n(x + w - 11)},${n(y + h - 7)} l3,3 l6,-7"/>` : ''}</g>`;
  },
  /** Ablage (Posteingang). */
  tray(x, y, w = 44, h = 14) {
    return `<g><path class="ill-fill" d="M${n(x)},${n(y)} H${n(x + w)} V${n(y + h)} H${n(x)} Z"/>
      <path class="ill-ink" d="M${n(x)},${n(y)} V${n(y + h)} H${n(x + w)} V${n(y)} M${n(x)},${n(y)} H${n(x + w * 0.3)} L${n(x + w * 0.38)},${n(y + h * 0.45)} H${n(x + w * 0.62)} L${n(x + w * 0.7)},${n(y)} H${n(x + w)}"/></g>`;
  },
  /** Weltkugel mit Längen- und Breitenkreisen. */
  globe(cx, cy, r = 18) {
    return `<g><circle class="ill-fill" cx="${n(cx)}" cy="${n(cy)}" r="${n(r)}"/>
      <circle class="ill-line" cx="${n(cx)}" cy="${n(cy)}" r="${n(r)}"/>
      <ellipse class="ill-line" cx="${n(cx)}" cy="${n(cy)}" rx="${n(r * 0.45)}" ry="${n(r)}"/>
      <path class="ill-line" d="M${n(cx - r)},${n(cy)} H${n(cx + r)} M${n(cx - r * 0.86)},${n(cy - r * 0.5)} H${n(cx + r * 0.86)} M${n(cx - r * 0.86)},${n(cy + r * 0.5)} H${n(cx + r * 0.86)}"/></g>`;
  },
  /** Sprechblase mit Zeilen (Sprache). */
  bubble(x, y, w = 30, h = 20) {
    return `<g><path class="ill-face" d="M${n(x + 4)},${n(y)} H${n(x + w - 4)} Q${n(x + w)},${n(y)} ${n(x + w)},${n(y + 4)} V${n(y + h - 4)} Q${n(x + w)},${n(y + h)} ${n(x + w - 4)},${n(y + h)} H${n(x + 10)} L${n(x + 4)},${n(y + h + 6)} V${n(y + h)} Q${n(x)},${n(y + h)} ${n(x)},${n(y + h - 4)} V${n(y + 4)} Q${n(x)},${n(y)} ${n(x + 4)},${n(y)} Z"/>
      <path class="ill-accent" d="M${n(x + 6)},${n(y + 7)} H${n(x + w - 6)} M${n(x + 6)},${n(y + 13)} H${n(x + w - 12)}"/></g>`;
  },
  /** Stufen (drei Treppenstufen), Punkt auf der obersten. */
  steps(x, y, w = 60, h = 36) {
    const sw = w / 3, sh = h / 3;
    return `<g><path class="ill-fill" d="M${n(x)},${n(y + h)} V${n(y + 2 * sh)} H${n(x + sw)} V${n(y + sh)} H${n(x + 2 * sw)} V${n(y)} H${n(x + w)} V${n(y + h)} Z"/>
      <path class="ill-line" d="M${n(x)},${n(y + h)} V${n(y + 2 * sh)} H${n(x + sw)} V${n(y + sh)} H${n(x + 2 * sw)} V${n(y)} H${n(x + w)} V${n(y + h)} Z"/></g>`;
  },
  /** Keimling (Wachstum), Fuß bei (x, y). */
  sprout(x, y, s = 1) {
    return `<path class="ill-accent" d="M${n(x)},${n(y)} V${n(y - 10 * s)} M${n(x)},${n(y - 6 * s)} C${n(x - 7 * s)},${n(y - 7 * s)} ${n(x - 8 * s)},${n(y - 13 * s)} ${n(x - 8 * s)},${n(y - 14 * s)} C${n(x - 2 * s)},${n(y - 14 * s)} ${n(x)},${n(y - 10 * s)} ${n(x)},${n(y - 8 * s)} M${n(x)},${n(y - 9 * s)} C${n(x + 6 * s)},${n(y - 10 * s)} ${n(x + 8 * s)},${n(y - 15 * s)} ${n(x + 8 * s)},${n(y - 17 * s)} C${n(x + 2 * s)},${n(y - 17 * s)} ${n(x)},${n(y - 13 * s)} ${n(x)},${n(y - 10 * s)}"/>`;
  },
  /** Lupe. */
  magnifier(cx, cy, r = 8) {
    return `<g><circle class="ill-fill" cx="${n(cx)}" cy="${n(cy)}" r="${n(r)}"/><circle class="ill-ink" cx="${n(cx)}" cy="${n(cy)}" r="${n(r)}"/>
      <path class="ill-ink" d="M${n(cx + r * 0.72)},${n(cy + r * 0.72)} L${n(cx + r * 1.6)},${n(cy + r * 1.6)}"/></g>`;
  },
  /** Wärmewellen (Fernwärme, Heizung). */
  heat(x, y, s = 1) {
    const wave = (dx) => `M${n(x + dx)},${n(y)} c${n(-3 * s)},${n(-3 * s)} ${n(3 * s)},${n(-5 * s)} 0,${n(-8 * s)} c${n(-3 * s)},${n(-3 * s)} ${n(3 * s)},${n(-5 * s)} 0,${n(-8 * s)}`;
    return `<g class="ill-c-waerme"><path class="ill-util" d="${wave(0)} ${wave(6 * s)} ${wave(12 * s)}"/></g>`;
  },
  // v3.9.0 (Paket messkonzepte) — Bausteine der Schaltbilder des Assistenten „Messkonzept“
  /** Knoten: hier zweigt eine Leitung ab (gefüllter Punkt in der Farbe der Beschriftung). */
  node(x, y, r = 2.2) {
    return `<circle class="ill-node" cx="${n(x)}" cy="${n(y)}" r="${n(r)}"/>`;
  },
  /**
   * Sammelleitung mit Abzweigen: senkrecht bei x von y1 bis y2, ein Knoten an
   * jedem Abzweig in `nodes` (y-Werte). Die Abzweige selbst zeichnet `wire`.
   */
  bus(x, y1, y2 = y1, { nodes = [] } = {}) {
    return `<polyline class="ill-line" points="${pts([[x, y1], [x, y2]])}"/>${nodes.map(y => shapes.node(x, y)).join('')}`;
  },
  /**
   * Zählwerk-Anzeige: die Kennzahlen eines Zählers (etwa 1.8.1, 1.8.2, 2.8.0)
   * als kleine Schilder untereinander; (x, y) = linke obere Ecke.
   */
  registers(x, y, labels = [], { w = 30, h = 10, gap = 2 } = {}) {
    return labels.map((label, i) => {
      const top = y + i * (h + gap);
      return `<g><rect class="ill-line" x="${n(x)}" y="${n(top)}" width="${n(w)}" height="${n(h)}" rx="2"/>
        <text class="ill-small" x="${n(x + w / 2)}" y="${n(top + h - 2.6)}" text-anchor="middle">${esc(label)}</text></g>`;
    }).join('');
  },
  /** Kleine Beschriftung (gedämpft), etwa „Z1 − Z2“ unter einem Gerät. */
  caption(x, y, text = '', { anchor = 'middle' } = {}) {
    return text ? `<text class="ill-small" x="${n(x)}" y="${n(y)}" text-anchor="${anchor === 'start' || anchor === 'end' ? anchor : 'middle'}">${esc(text)}</text>` : '';
  },
};

// ── Bilder der Ersteinrichtung (Zier: die Frage daneben sagt alles) ──────

const SETUP = {
  /** Sprache und Land: Weltkugel mit Sprechblase. */
  region: () => shapes.globe(44, 44, 24) + shapes.bubble(64, 10, 34, 20),
  /** Wer bist du: Mehrfamilienhaus und Einfamilienhaus. */
  persona: () => shapes.ground(6, 114, 72) + shapes.block(14, 26, 34, 46) + shapes.house(60, 30, 44, 42, { chimney: true }),
  /** Energieträger: drei ruhige Zähler, darüber Blitz, Flamme und Tropfen in der Farbe der Art. */
  utilities: () => shapes.ground(6, 114, 72) + ['bolt', 'flame', 'drop'].map((icon, i) => {
    const x = 9 + i * 37;
    return shapes.meter(x, 40, { w: 28, h: 32 }) + shapes[icon](x + 14, 20, 1);
  }).join(''),
  /** Erfahrung: Stufen mit einem Keimling oben. */
  level: () => shapes.ground(6, 114, 72) + shapes.steps(22, 36, 72, 36) + shapes.sprout(83, 34, 1.1),
  /** Los geht's: Dokument fällt in den Posteingang. */
  finish: () => shapes.doc(46, 6, { check: true }) + shapes.arrow(82, 16, 82, 44) + shapes.tray(36, 50, 56, 18) + shapes.ground(6, 114, 74),
};

/** Kleines Bild für einen Schritt der Ersteinrichtung (aria-hidden). */
export function setupArt(step) {
  const draw = SETUP[step];
  return draw ? svg(draw(), { viewBox: '0 0 120 80', className: 'ill--setup' }) : '';
}

const PERSONA = {
  mieterin: () => shapes.block(9, 6, 22, 30, { rows: 3, cols: 2 }) + shapes.ground(3, 37, 36),
  'etw-fernwaerme': () => shapes.block(5, 8, 20, 28, { rows: 3, cols: 2 }) + shapes.heat(29, 34, 0.7) + shapes.ground(3, 37, 36),
  'eigenheim-klassisch': () => shapes.house(5, 7, 30, 29, { chimney: true }) + shapes.ground(3, 37, 36),
  'eigenheim-modern': () => shapes.house(5, 9, 30, 27) + shapes.sun(31, 7, 3.5) + shapes.ground(3, 37, 36),
  showcase: () => shapes.magnifier(17, 17, 9),
};

/** Kleines Bild je Beispielhaushalt in der Auswahl „Wer bist du?“ (aria-hidden). */
export function personaArt(id) {
  const draw = PERSONA[id];
  return draw ? svg(draw(), { viewBox: '0 0 40 40', className: 'ill--persona' }) : '';
}

/**
 * Willkommen (leere Übersicht): Haus mit Zähler, daneben ein Blatt mit Haken,
 * das, was die App aus den Ständen macht (aria-hidden, der Text sagt es).
 */
export function welcomeArt() {
  return svg(shapes.ground(4, 156, 86)
    + shapes.house(10, 30, 58, 56, { chimney: true })
    + shapes.meter(80, 52, { w: 24, h: 30 })
    + shapes.arrow(108, 66, 122, 56)
    + shapes.doc(124, 22, { w: 28, h: 36, check: true })
    + shapes.sun(140, 8, 4), { viewBox: '0 0 160 92', className: 'ill--welcome' });
}

/** Für Tests: welche Schritte und Haushalte ein Bild haben. */
export const SETUP_ART_STEPS = Object.keys(SETUP);
export const PERSONA_ART_IDS = Object.keys(PERSONA);
