// =====================================================================
// v2.11.0 — Kontraste nach WCAG AA (Review UI-09)
//
// Liest die Farb-Token aus public/css/tokens.css (Dunkel = :root, Hell =
// [data-theme="light"]) und die Farben der Verbrauchsarten aus
// src/Config/Utilities.php und prüft die Paare, die in der Oberfläche als
// Schrift auf Fläche vorkommen, gegen 4,5:1. Die Tönung der
// Verbrauchsart-Farben rechnet dieselbe Funktion wie die App
// (lib/utility-theme.js).
//
// Bis v2.10 lagen Primärknöpfe im Hellmodus bei 3,8:1, weiße Schrift auf
// Gas-Orange bei 2,2:1 und die Gruppenlabels der Seitenleiste unter 3:1 —
// obwohl v2.2.0 die Kontraste schon einmal korrigiert hatte. Ohne Test
// kommen solche Werte mit der nächsten Farbe zurück.
//
// Aufruf: node tests/contrast.test.mjs
// =====================================================================
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const { contrast, mix } = await import(`${root}/public/js/lib/contrast.js`);
const { themedColor, textOn } = await import(`${root}/public/js/lib/utility-theme.js`);

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

// ── Token lesen ──
const css = readFileSync(`${root}/public/css/tokens.css`, 'utf8');
function block(selector) {
  const i = css.indexOf(selector + ' {');
  if (i < 0) throw new Error('Block fehlt: ' + selector);
  return css.slice(i, css.indexOf('\n}', i));
}
function tokens(text) {
  const out = {};
  for (const m of text.matchAll(/--([a-z0-9-]+):\s*([^;]+);/gi)) out[m[1]] = m[2].trim();
  return out;
}
const dark = tokens(block(':root'));
const light = { ...dark, ...tokens(block('[data-theme="light"]')) };

/** Farbe als Hex; rgba(...) wird über `under` gelegt. var(--x) wird aufgelöst. */
function color(set, value, under) {
  let v = String(value).trim();
  for (let i = 0; i < 5 && v.startsWith('var('); i++) v = set[v.slice(6, v.indexOf(')')).trim()] ?? v;
  const rgba = v.match(/^rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([\d.]+)\s*\)$/);
  if (rgba) {
    const hex = '#' + [rgba[1], rgba[2], rgba[3]].map(n => Number(n).toString(16).padStart(2, '0')).join('');
    return mix(hex, color(set, under), Number(rgba[4]));
  }
  if (!/^#[0-9a-f]{3,6}$/i.test(v)) throw new Error('keine Farbe: ' + value);
  return v;
}

const AA = 4.5;
const themes = { dunkel: dark, hell: light };
for (const [name, set] of Object.entries(themes)) {
  const c = (k, under) => color(set, set[k] ?? k, under);
  const check = (label, fg, bg) => {
    const r = contrast(fg, bg);
    t(`${name}: ${label}`, r >= AA, r.toFixed(2) + ':1');
  };
  for (const txt of ['text-1', 'text-2', 'text-3']) {
    for (const bg of ['bg-0', 'bg-1', 'bg-2']) check(`--${txt} auf --${bg}`, c(txt), c(bg));
  }
  for (const s of ['success', 'warning', 'danger']) {
    check(`--${s} auf --bg-1`, c(s), c('bg-1'));
    check(`--${s} auf der eigenen Tönung`, c(s), c(`${s}-soft`, set['bg-1']));
  }
  check('Primärknopf (--accent-fg auf --accent)', c('accent-fg'), c('accent'));
  check('Primärknopf beim Überfahren', c('accent-fg'), c('accent-hover'));
  check('Löschen-Knopf (weiß auf --danger-strong)', '#ffffff', c('danger-strong'));
  check('Links (--accent auf --bg-1)', c('accent'), c('bg-1'));
}

// ── Verbrauchsart-Farben (SSOT) ──
const php = readFileSync(`${root}/src/Config/Utilities.php`, 'utf8');
const utilColors = [...php.matchAll(/'key'\s*=>\s*'([a-z_]+)'[\s\S]*?'color'\s*=>\s*'(#[0-9a-f]{6})'/gi)].map(m => [m[1], m[2]]);
t('Verbrauchsarten mit Farbe gefunden', utilColors.length >= 8, utilColors.map(u => u[0]).join(','));
for (const [key, raw] of utilColors) {
  for (const [name, set] of Object.entries(themes)) {
    const theme = name === 'hell' ? 'light' : 'dark';
    const col = themedColor(raw, theme);
    const onBg1 = contrast(col, color(set, set['bg-1']));
    const onBg2 = contrast(col, color(set, set['bg-2']));
    const fill = contrast(textOn(col), col);
    t(`${name}: ${key} als Schrift auf --bg-1/--bg-2`, onBg1 >= AA && onBg2 >= AA, `${onBg1.toFixed(2)} / ${onBg2.toFixed(2)}`);
    t(`${name}: Schrift auf ${key}-Fläche (Knopf, aktive Pille)`, fill >= AA, `${textOn(col)} ${fill.toFixed(2)}:1`);
  }
}

// ── CSS: keine festen Schriftfarben auf Akzentflächen ──
const comp = readFileSync(`${root}/public/css/components.css`, 'utf8');
t('Primärknopf ohne feste Schriftfarbe', /\.btn--primary \{[^}]*color: var\(--accent-fg\)/.test(comp));
t('Knöpfe ohne Farbverlauf (drückte den Kontrast)', !/\.btn--(primary|util)[^{]*\{[^}]*linear-gradient/.test(comp));

console.log(`\n  contrast.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
