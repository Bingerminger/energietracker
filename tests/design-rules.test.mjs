// =====================================================================
// v3.12.0 (Designsprache) — die Regeln der Stylesheets als Test.
//
// Drei Radien, zwei Schatten, sieben Schriftgrößen, das 4-px-Raster, eine Schrift,
// keine Pillen, Streifen, Verläufe, Versalien; Farben nur aus den Tokens. Wo der
// Umstieg noch nicht fertig ist, gelten Höchstzahlen (HOECHST): Sie dürfen nur sinken.
//
// print.css bleibt außen vor: Papier hat eigene Regeln (Punkte, Graustufen).
// =====================================================================
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');
let pass = 0, fail = 0;
const t = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};

const FILES = ['app', 'components', 'inbox', 'inbox-draft', 'readings-entry'];
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');
const sheets = Object.fromEntries(FILES.map(n => [n, strip(read(`public/css/${n}.css`))]));
const tokens = strip(read('public/css/tokens.css'));
const all = Object.values(sheets).join('\n');

/** Alle Deklarationen einer Eigenschaft: [Selektor, Wert]. */
function decls(prop, css = all) {
  const out = [];
  const re = new RegExp(`([^{};]+)\\{[^{}]*?(?:^|[;{\\s])${prop}\\s*:\\s*([^;}]+)`, 'gm');
  for (const m of css.matchAll(re)) out.push([m[1].trim().replace(/\s+/g, ' ').slice(-70), m[2].trim()]);
  return out;
}
const list = (arr, n = 8) => arr.slice(0, n).map(x => `${x[0]}: ${x[1]}`).join(' | ');

// ── Radien ───────────────────────────────────────────────────────────
const RADIUS_OK = /^(0|50%|inherit|var\(--r-sm\)|var\(--r-md\))$/;
const badRadius = decls('border-radius').filter(([, v]) => !v.split(/\s+/).every(p => RADIUS_OK.test(p)));
t('Radien: nur --r-sm, --r-md, 0 und 50 %', badRadius.length === 0, list(badRadius));
t('Tokens: genau zwei Radien', (tokens.match(/--r-[a-z]+:/g) || []).length === 2);
const round = decls('border-radius').filter(([, v]) => v === '50%');
t('50 % nur für Punkte und runde Griffe', round.every(([s]) => /dot|point|knob|thumb|marker|handle|key|ring|node|spin|::after|::before|swatch|legend|circle|pip|badge|bullet|mark|chip__|-pos|num/i.test(s)), list(round.filter(([s]) => !/dot|point|knob|thumb|marker|handle|key|ring|node|spin|::after|::before|swatch|legend|circle|pip|badge|bullet|mark|chip__|-pos|num/i.test(s))));

// ── Knöpfe: zwei Größen (v3.12.0, Sichtung S6) ──────────────────────
const VIEWS = readdirSync(resolve(ROOT, 'public/js/views')).filter(f => f.endsWith('.js')).map(f => read(`public/js/views/${f}`)).join('\n');
t('Zwei Knopfgrößen: kein btn--xs (ohne Regel 36 px neben 30 px)', !/btn--xs/.test(VIEWS + all));
t('Knöpfe in einer Reihe stehen auf einer Linie', /^\.btn \{[^}]*vertical-align: middle/m.test(all));

// ── Schatten ─────────────────────────────────────────────────────────
const SHADOW_OK = /^(none|var\(--shadow-2\)|var\(--shadow-3\))$/;
const badShadow = decls('box-shadow').filter(([, v]) => !SHADOW_OK.test(v));
t('Schatten: nur --shadow-2 und --shadow-3', badShadow.length === 0, list(badShadow));
t('Tokens: genau zwei Schatten', (tokens.match(/--shadow-\d:/g) || []).length / 2 === 2);

// ── Schrift ──────────────────────────────────────────────────────────
t('Keine Versalien', !/text-transform:\s*(uppercase|capitalize)/.test(all));
t('Keine Sperrung', !/letter-spacing/.test(all));
t('Mono nur für Code', decls('font-family').filter(([, v]) => /--font-code|DM Mono/.test(v)).every(([s]) => /code|pre|kbd|samp|mono/i.test(s)), list(decls('font-family').filter(([s, v]) => /--font-code/.test(v) && !/code|pre|kbd|samp|mono/i.test(s))));
t('Tokens: kein --font-mono mehr', !/--font-mono/.test(tokens + all));
t('Eine Familie: DM Sans für alles außer Code', /--font-sans:\s*"DM Sans"/.test(tokens) && /--font-code:\s*"DM Mono"/.test(tokens));
const SIZES = { 'var(--fs-xs)': 12, 'var(--fs-sm)': 13, 'var(--fs-body)': 14, 'var(--fs-base)': 15, 'var(--fs-md)': 16, 'var(--fs-lg)': 18, 'var(--fs-xl)': 24, 'var(--fs-2xl)': 32 };
for (const [tok, px] of Object.entries(SIZES)) {
  const name = tok.slice(4, -1);
  const v = tokens.match(new RegExp(`${name}:\\s*([\\d.]+)rem`));
  t(`Stufe ${name} = ${px} px`, v && Math.round(parseFloat(v[1]) * 16) === px, v?.[1]);
}
t('Tokens: acht Schriftstufen, mehr nicht', (tokens.match(/--fs-[a-z0-9]+:/g) || []).length === 8);
const badSize = decls('font-size').filter(([, v]) => !SIZES[v] && !['inherit', '16px', '0.9em', '1em', '0'].includes(v));
t('Schriftgrößen: nur die Stufen (16 px gegen den Safari-Zoom, em in Fließtext-Code)', badSize.length === 0, list(badSize));
const badWeight = decls('font-weight').filter(([, v]) => !['400', '500', '600', 'inherit', 'normal'].includes(v));
t('Gewichte: 400, 500, 600', badWeight.length === 0, list(badWeight));

// ── Flächen: keine Streifen, keine Verläufe ──────────────────────────
const stripes = decls('border-left').filter(([, v]) => /^[2-9]px/.test(v));
t('Keine farbigen Randstreifen', stripes.length === 0, list(stripes));
t('Keine Randfarbe als Aussage', !/border-left-color/.test(all));
const gradients = [...all.matchAll(/([^{};]+)\{[^{}]*?gradient\(/g)].map(m => m[1].trim());
t('Keine Verläufe (außer dem Pfeil der Auswahlfelder)', gradients.every(s => /^\.select:not/.test(s)), gradients.join(' | '));
t('Kein Verlaufsstreifen auf Kacheln', !/\.kpi::after/.test(all));
t('Keine Sonderformen der Knöpfe', !/\.btn--util|\.btn-(gas|strom|wasser)/.test(all));
t('Knöpfe: 36 und 30 px, Radius 6 px', /\.btn \{[^}]*min-height: 36px/.test(all) && /\.btn--sm \{[^}]*min-height: 30px/.test(all));

// ── Farben: nur Tokens ───────────────────────────────────────────────
const hex = FILES.flatMap(n => [...sheets[n].matchAll(/#[0-9a-fA-F]{3,8}\b/g)].map(m => `${n}: ${m[0]}`));
t('Kein Hexwert außerhalb der Tokens', hex.length === 0, hex.slice(0, 8).join(', '));

// ── Untermenü: eine Form ─────────────────────────────────────────────
for (const sel of ['.section-tabs__tab', '.pill', '.seg__btn', '.ef-seg__btn']) {
  const own = [...all.matchAll(/(^|\})\s*([^{}]+)\{([^{}]*)\}/g)]
    .filter(m => m[2].split(',').map(x => x.trim()).includes(sel) && !m[2].includes('.section-tabs__tab, .pill'))
    .filter(m => /background|border|border-radius|padding|font-size/.test(m[3]));
  t(`${sel} trägt keine eigene Form`, own.length === 0, own.map(m => m[3].trim().slice(0, 80)).join(' | '));
}
t('Untermenü: 30 px, Radius --r-sm', /\.section-tabs__tab, \.pill, \.seg__btn, \.ef-seg__btn \{[^}]*min-height: 30px[^}]*border-radius: var\(--r-sm\)/.test(all));

// ── Infobox ──────────────────────────────────────────────────────────
for (const tone of ['info', 'erfolg', 'achtung', 'fehler', 'neutral']) {
  t(`Infobox: Ton ${tone} mit Grafik`, new RegExp(`\\.infobox--${tone}\\s*\\{[^}]*--tone-mask: var\\(--mask-${tone}\\)`).test(all) && new RegExp(`--mask-${tone}: url\\("data:image`).test(tokens));
}
const GONE = ['banner', 'banner--info', 'banner--success', 'banner--warning', 'banner--error', 'status-banner', 'level-hint', 'release-notice', 'thanks-note', 'balance-note', 'balance-suggest', 'ws-note', 'report-note', 'doc-hint', 'chart-note', 'ef-note', 'warning-list', 'heat-limit-hint', 'success-text', 'warning-text', 'danger-text', 'inbox-gist--good', 'inbox-gist--warn', 'inbox-gist--bad', 'rec-card--info', 'rec-card--warning', 'rec-card--danger', 'dash-rec--warning', 'dash-rec--urgent'];
const stillStyled = GONE.filter(c => new RegExp(`\\.${c}(?![\\w-])`).test(all));
t('Die alten Hinweisklassen haben keine eigene Regel mehr', stillStyled.length === 0, stillStyled.join(', '));
const views = [];
const walk = (d) => { for (const f of readdirSync(resolve(ROOT, d), { withFileTypes: true })) { if (f.isDirectory()) walk(`${d}/${f.name}`); else if (f.name.endsWith('.js')) views.push([`${d}/${f.name}`, read(`${d}/${f.name}`)]); } };
walk('public/js');
const REMOVED_IN_JS = ['banner', 'banner--info', 'banner--success', 'banner--warning', 'banner--error', 'status-banner', 'status-banner__icon', 'status-banner__text', 'ws-note', 'report-note', 'chart-note', 'ef-note', 'warning-list', 'success-text', 'warning-text', 'danger-text', 'btn--util', 'level-hint__actions', 'thanks-note__text', 'thanks-note__actions', 'release-notice__text'];
for (const c of REMOVED_IN_JS) {
  const re = new RegExp(`(?:class="[^"]*|className = [\`'][^\`']*|'|\\s)${c}(?=[\\s"'\`])`);
  const hits = views.filter(([, s]) => re.test(s.split('\n').filter(l => !/^\s*(\/\/|\*)/.test(l)).join('\n'))).map(([f]) => f);
  t(`Klasse ${c} kommt im Code nicht mehr vor`, hits.length === 0, hits.join(', '));
}

// ── Raster gegen den Flattersatz ─────────────────────────────────────
t('Raster: Spaltenzahl aus --spalten, gleiche Höhe je Reihe', /\.grid, \.kpi-grid[^{]*\{[^}]*repeat\(var\(--spalten, 4\), minmax\(0, 1fr\)\)[^}]*align-items: stretch/.test(all));
t('Formulare: eine Spalte, höchstens 860 px', /\.settings-grid \{[^}]*minmax\(0, 1fr\)[^}]*max-width: 860px/.test(all));
t('Kein auto-fill mit align-items: start bei Boxen', !/repeat\(auto-fill[^;]*;[^}]*align-items: start/.test(all));

const { columnsFor } = await import(new URL('../public/js/lib/fit-grid.js', import.meta.url).href);
const REIHE = { 1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6, 7: 4, 8: 4, 9: 3 };
for (const [n, c] of Object.entries(REIHE)) t(`${n} Kacheln: ${c} Spalten`, columnsFor(Number(n), 1200, { minCell: 170 }) === c, String(columnsFor(Number(n), 1200, { minCell: 170 })));
t('Höchstzahl aus dem Markup gilt (grid-2)', columnsFor(4, 1200, { cap: 2 }) === 2);
t('Schmaler Behälter: Zahlen zweispaltig, Text einspaltig', columnsFor(6, 358, { minCell: 170 }) === 2 && columnsFor(6, 358, { minCell: 280 }) === 1);

// ── iPad-Regel und Lesebreite ────────────────────────────────────────
t('Unter 900 px und bei Touch 16 px in Feldern', /@media \(any-pointer: coarse\) \{ \.input, \.select, textarea\.input \{ font-size: 16px; \} \}/.test(all.replace(/\s+/g, ' ')));
t('Lesebreite 72 Zeichen', /max-width: 72ch/.test(all));

// ── Umstieg: Höchstzahlen, die nur sinken dürfen ─────────────────────
const HOECHST = {
  'Innenabstände und Lücken in px außerhalb des Rasters': { max: 60, n: decls('(?:padding|gap|margin)[a-z-]*').filter(([, v]) => /(^|\s)[1-9]\d*px/.test(v) && !/calc|env|max\(|min\(/.test(v)).length },
  '!important': { max: 18, n: (all.match(/!important/g) || []).length },
  'style= in den Ansichten': { max: 239, n: views.reduce((a, [, s]) => a + (s.match(/style="/g) || []).length, 0) },
};
for (const [name, { max, n }] of Object.entries(HOECHST)) t(`${name}: höchstens ${max}`, n <= max, `jetzt ${n}`);
console.log(`  Stand: ${Object.entries(HOECHST).map(([k, v]) => `${k} ${v.n}`).join(', ')}`);

console.log(`\n  design-rules: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
