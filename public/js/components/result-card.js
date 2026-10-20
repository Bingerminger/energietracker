// =====================================================================
// v3.6.0 (F1031) — Ergebniskarte der Prognose-Werkstatt.
//
// Eine Aussage, eine große Zahl: „Ein kalter Winter kostet dich 214 € mehr
// im Jahr.“ Auf der Seite als HTML (lesbar für Screenreader), zum Teilen als
// Bild: Der Knopf zeichnet die Karte auf ein Canvas (1200 × 630, das Format
// von Vorschaubildern) und speichert sie als PNG — im Browser, ohne
// Bibliothek, ohne Dienst nach außen. Nichts verlässt das Gerät, bis der
// Nutzer die Datei selbst weitergibt.
// =====================================================================

import { escapeHtml } from '../lib/format.js';

/**
 * @typedef {{ title: string, sentence: string, big: string, tone: 'more'|'less'|'same',
 *   facts: Array<{label: string, value: string}>, footer: string, brand: string }} CardSpec
 */

/**
 * @param {CardSpec} c
 * @param {string} [id] v3.6.0 — Kennung des Satzes, wenn mehrere Karten auf einer Seite stehen (Jahresbericht)
 */
export function resultCardHtml(c, id = 'ws-card-sentence') {
  const sid = escapeHtml(id);
  return `<figure class="ws-card ws-card--${c.tone}" aria-labelledby="${sid}">
      <p class="ws-card__brand">${escapeHtml(c.brand)}</p>
      <p class="ws-card__title">${escapeHtml(c.title)}</p>
      <p class="ws-card__big" aria-hidden="true">${escapeHtml(c.big)}</p>
      <figcaption class="ws-card__sentence" id="${sid}">${escapeHtml(c.sentence)}</figcaption>
      ${c.facts.length ? `<dl class="ws-card__facts">${c.facts.map(f =>
        `<div><dt>${escapeHtml(f.label)}</dt><dd>${escapeHtml(f.value)}</dd></div>`).join('')}</dl>` : ''}
      <p class="ws-card__footer">${escapeHtml(c.footer)}</p>
    </figure>`;
}

const COLORS = {
  more: { accent: '#b42318', soft: '#fdecea' },
  less: { accent: '#067647', soft: '#e7f6ec' },
  same: { accent: '#344054', soft: '#eef2f6' },
};
const FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';

/** Zeilenumbruch nach Breite. */
function wrap(ctx, text, maxWidth) {
  const words = String(text).split(/\s+/);
  const lines = [];
  let line = '';
  for (const w of words) {
    const next = line ? `${line} ${w}` : w;
    if (ctx.measureText(next).width > maxWidth && line) { lines.push(line); line = w; } else line = next;
  }
  if (line) lines.push(line);
  return lines;
}

/**
 * Die Karte auf ein Canvas zeichnen (hell, unabhängig vom Theme — so liest
 * sie sich überall, wo man sie zeigt).
 * @param {HTMLCanvasElement} canvas
 * @param {CardSpec} c
 */
export function drawResultCard(canvas, c) {
  const W = 1200, H = 630, P = 72;
  canvas.width = W; canvas.height = H;
  const ctx = canvas.getContext('2d');
  if (!ctx) return false;
  const col = COLORS[c.tone] || COLORS.same;
  ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
  ctx.fillStyle = col.soft; ctx.fillRect(0, 0, W, 14);
  ctx.fillStyle = col.accent; ctx.fillRect(0, 0, 14, H);
  ctx.textBaseline = 'alphabetic';
  ctx.fillStyle = '#475467'; ctx.font = `600 26px ${FONT}`;
  ctx.fillText(c.brand.toUpperCase(), P, P + 10);
  ctx.fillStyle = '#101828'; ctx.font = `600 34px ${FONT}`;
  ctx.fillText(c.title, P, P + 62, W - 2 * P);
  ctx.fillStyle = col.accent; ctx.font = `800 132px ${FONT}`;
  ctx.fillText(c.big, P, P + 210, W - 2 * P);
  ctx.fillStyle = '#101828'; ctx.font = `500 36px ${FONT}`;
  let y = P + 280;
  for (const l of wrap(ctx, c.sentence, W - 2 * P).slice(0, 2)) { ctx.fillText(l, P, y); y += 46; }
  ctx.font = `500 26px ${FONT}`;
  let x = P;
  y = H - P - 44;
  for (const f of c.facts.slice(0, 3)) {
    ctx.fillStyle = '#475467'; ctx.fillText(f.label, x, y);
    ctx.fillStyle = '#101828'; ctx.font = `700 30px ${FONT}`; ctx.fillText(f.value, x, y + 38);
    ctx.font = `500 26px ${FONT}`;
    x += (W - 2 * P) / 3;
  }
  ctx.fillStyle = '#667085'; ctx.font = `400 20px ${FONT}`;
  ctx.fillText(c.footer, P, H - 22, W - 2 * P);
  return true;
}

/**
 * Als PNG speichern. Gibt false zurück, wenn der Browser kein Canvas kann.
 * @param {CardSpec} c
 * @param {string} filename
 */
export async function downloadResultCard(c, filename) {
  const canvas = document.createElement('canvas');
  if (!drawResultCard(canvas, c)) return false;
  const blob = await new Promise(res => canvas.toBlob ? canvas.toBlob(res, 'image/png') : res(null));
  if (!blob) return false;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
  return true;
}
