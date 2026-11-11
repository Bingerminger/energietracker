// =====================================================================
// v3.12.0 (M6) — Logo in der Hand der eigenen Grafiken (designsprache.md §2).
//
// Haus als Strichzeichnung auf dem 24er-Raster mit getönter Fläche, darin ein
// Zählerring aus den Farben der Verbrauchsarten (SSOT src/Config/Utilities.php:
// Strom, PV-Einspeisung, Wasser, Fernwärme, Gas). Keine Verläufe, keine fremden
// Grafiken. Bis v3.11 war das Logo ein Haus mit Regenbogenring.
//
//   NODE_PATH=~/node_modules node tools/build-logo.mjs
//
// Schreibt docs/assets/promo/logo.svg (Vorlage), public/img/icon-*.png und die
// beiden favicon.ico. Gerendert mit Chromium (Playwright, nur Entwicklung).
// =====================================================================
import { createRequire } from 'node:module';
import { writeFileSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

// Farben der Verbrauchsarten aus der SSOT lesen (kein Duplikat)
const php = readFileSync(join(ROOT, 'src/Config/Utilities.php'), 'utf8');
const color = (key) => {
  const m = php.match(new RegExp(`'${key}'\\s*=>\\s*\\[[\\s\\S]*?'color'\\s*=>\\s*'(#[0-9a-f]{6})'`, 'i'));
  if (!m) throw new Error(`Farbe von ${key} fehlt in Utilities.php`);
  return m[1];
};
const RING = ['strom', 'pv_einspeisung', 'wasser', 'fernwaerme', 'gas'].map(color);

/** Symbole der README-Tabelle „Auf einen Blick“ (Namen aus public/js/lib/icons.js) */
const README_ICONS = ['ki', 'waage', 'eingang', 'kolben', 'geld', 'beleg', 'stecker', 'schloss'];

const THEMES = {
  light: { ink: '#334155', fill: '#2563eb', fillOpacity: 0.10, bg: '#f6f8fa' },
  dark:  { ink: '#cbd5e1', fill: '#4a90e2', fillOpacity: 0.16, bg: '#111827' },
};

/** Das Logo als SVG. `pad` verkleinert das Motiv (maskierbare Symbole brauchen Rand). */
export function logoSvg(theme = 'light', { size = 512, bg = false, pad = 0 } = {}) {
  const t = THEMES[theme];
  const cx = 12, cy = 14.3, r = 3.55;
  const C = 2 * Math.PI * r;
  const seg = C / RING.length, gap = 0.95;
  const ring = RING.map((c, i) => {
    const rot = -90 + (360 / RING.length) * i + (gap / 2 / C) * 360;
    return `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${c}" stroke-width="2.1" stroke-dasharray="${(seg - gap).toFixed(3)} ${(C - seg + gap).toFixed(3)}" transform="rotate(${rot.toFixed(2)} ${cx} ${cy})"/>`;
  }).join('');
  const s = (24 - 2 * pad) / 24;
  const motif = `<g transform="translate(${pad} ${pad}) scale(${s})">`
    + `<path d="M5 9.7 12 3.5l7 6.2v10.8H5z" fill="${t.fill}" fill-opacity="${t.fillOpacity}"/>`
    + `<path d="M2.8 11.7 12 3.5l9.2 8.2M5 9.7v10.8h14V9.7" fill="none" stroke="${t.ink}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>`
    + ring + '</g>';
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="${size}" height="${size}">`
    + (bg ? `<rect width="24" height="24" fill="${t.bg}"/>` : '') + motif + '</svg>';
}

/** ICO aus PNG-Bildern (PNG im ICO: Vista und neuer, alle heutigen Browser). */
function ico(pngs) {
  const head = Buffer.alloc(6 + 16 * pngs.length);
  head.writeUInt16LE(0, 0); head.writeUInt16LE(1, 2); head.writeUInt16LE(pngs.length, 4);
  let offset = head.length;
  pngs.forEach(({ size, buf }, i) => {
    const o = 6 + 16 * i;
    head.writeUInt8(size >= 256 ? 0 : size, o); head.writeUInt8(size >= 256 ? 0 : size, o + 1);
    head.writeUInt8(0, o + 2); head.writeUInt8(0, o + 3);
    head.writeUInt16LE(1, o + 4); head.writeUInt16LE(32, o + 6);
    head.writeUInt32LE(buf.length, o + 8); head.writeUInt32LE(offset, o + 12);
    offset += buf.length;
  });
  return Buffer.concat([head, ...pngs.map(p => p.buf)]);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const require = createRequire(join(process.env.NODE_PATH || join(process.env.HOME || '', 'node_modules'), 'x'));
  const { chromium } = require('playwright');
  writeFileSync(join(ROOT, 'docs/assets/promo/logo.svg'), logoSvg('light', { size: 512 }) + '\n');
  const browser = await chromium.launch();
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  const render = async (svg, size, transparent) => {
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(`<!doctype html><html><body style="margin:0;background:transparent">${svg}</body></html>`);
    return page.screenshot({ clip: { x: 0, y: 0, width: size, height: size }, omitBackground: transparent });
  };
  const out = (name, buf) => writeFileSync(join(ROOT, 'public/img', name), buf);
  for (const theme of ['light', 'dark']) {
    for (const size of [16, 32, 48, 64, 180, 192, 512]) {
      // Kleine Größen mit etwas weniger Rand, damit das Haus lesbar bleibt
      out(`icon-${theme}-${size}.png`, await render(logoSvg(theme, { size, bg: true, pad: size <= 32 ? 0 : 0.6 }), size, false));
    }
    // Logo in der Kopfleiste: ohne Fläche, die Kopfleiste ist der Grund
    out(`icon-${theme}.png`, await render(logoSvg(theme, { size: 512 }), 512, true));
  }
  for (const size of [192, 512]) {
    // maskierbar: Motiv in der sicheren Zone (mittlere 80 %)
    out(`icon-maskable-${size}.png`, await render(logoSvg('light', { size, bg: true, pad: 3.2 }), size, false));
  }
  const fav = [];
  for (const size of [16, 32, 48]) fav.push({ size, buf: await render(logoSvg('light', { size, bg: true }), size, false) });
  writeFileSync(join(ROOT, 'public/img/favicon.ico'), ico(fav));
  writeFileSync(join(ROOT, 'public/favicon.ico'), ico(fav));
  await browser.close();
  // Symbole für die Tabelle „Auf einen Blick“ im README: dieselben Grafiken wie in der App, als eigene Dateien
  // (GitHub kennt die Variablen der App nicht). Ein Blau, das auf hellem und dunklem Grund trägt.
  const { ICONS } = await import(join(ROOT, 'public/js/lib/icons.js'));
  for (const name of README_ICONS) {
    const body = ICONS[name].replace(/class="f"/g, 'fill="#4a90e2" fill-opacity="0.16" stroke="none"');
    writeFileSync(join(ROOT, `docs/assets/promo/icons/${name}.svg`),
      `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#4a90e2" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${body}</svg>\n`);
  }
  console.log('Logo geschrieben: docs/assets/promo/logo.svg, public/img/icon-*.png, favicon.ico');
}
