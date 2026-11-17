// Baut die Projektseite und prüft ihre Regeln.
//
//   node tools/build.mjs           schreibt index.html, en/index.html, ueber/index.html,
//                                  en/about/index.html, 404.html
//   node tools/build.mjs --check   schreibt nichts, bricht ab, wenn eine Regel verletzt ist
//                                  oder die Dateien nicht zum Quelltext passen
//
// Regeln (Auftrag und Designsprache der App):
//  R1  keine Ressourcen von außen: src, srcset, Stylesheets, Skripte, Schriften, url() nur lokal
//  R2  jede lokale Datei, Sprungmarke und Grafik, auf die die Seite zeigt, gibt es
//  R3  öffentliche Texte ohne Gedankenstrich (– —) und ohne Mittelpunkt (·) als Trenner
//  R4  keine Beträge (€, EUR) und keine Download-, Nutzer- oder Sternzahlen im sichtbaren Text
//  R5  jede Aussage hat eine Kennung, jede Kennung steht in aussagen.md, jede Zeile dort wird benutzt,
//      Deutsch und Englisch belegen je Seite dieselben Kennungen
//  R6  jedes Bild hat einen Alternativtext und feste Maße, jede Quelle eines Bildes Maße
//  R7  CSS: Farbwerte nur in den Token-Blöcken, keine Großschrift, keine Sperrung, nur drei Radien
//  R8  höchstens eine Fettung je Absatz, Listenpunkt oder Bildunterschrift
//  R9  ein h1 je Seite, Sprache gesetzt, Sprachfassungen verknüpft, Seite „Über“ von jeder Seite erreichbar
//  R10 Darstellung wie in der App: Umschalter mit Beschriftung, Thema vor dem ersten Zeichnen gesetzt,
//      dunkle Tokens für System und Wahl gleich, jede dunkle Bildquelle umschaltbar
//  R11 Bilder-Slider: je Reiter genau ein Bild, verknüpft, jedes mit Frage, Text und Alternativtext
//  R12 Animationen (v3.13.0): jede ist ein animiertes WebP mit ihrem Rückfallbild (dieselbe Szene, gleiche Maße,
//      hell und dunkel), gilt nur ohne „Bewegung reduzieren“, lädt erst beim Scrollen; jede Datei unter
//      assets/img/anim/ ist benutzt und höchstens 1,5 MB groß; kein GIF (Entscheidung 2026-11-16)
import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { rende, SEITEN, DATEI, SITE, BASE } from '../src/seiten.mjs';
import { SLIDER, DEMO } from '../src/texte.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK = process.argv.includes('--check');
const fail = [];
const bad = (rule, msg) => fail.push(`${rule}: ${msg}`);

const pages = {};
const claims = {};
for (const art of Object.keys(SEITEN)) {
  for (const lang of ['de', 'en']) {
    const r = rende(lang, art, ROOT);
    pages[DATEI(SEITEN[art][lang])] = r.html;
    claims[`${art}.${lang}`] = r.claims;
  }
}
pages['404.html'] = seite404();
// Unter BASE ausliefern: Wurzelpfade bekommen das Präfix. Alte Demo-Links von der Zeit, als die
// Demo selbst unter /energietracker/ lag (?persona=, ?lang=, #/route), springen in die Demo.
for (const f of Object.keys(pages)) pages[f] = mitBasis(pages[f]);
function mitBasis(html) {
  return html
    .replace(/(\s(?:href|src|poster|action)=")\/(?!\/)/g, `$1${BASE}`)
    .replace(/(\ssrcset=")([^"]+)"/g, (m, a, v) => a + v.split(',').map(x => x.trim().replace(/^\/(?!\/)/, BASE)).join(', ') + '"')
    .replace('<head>', `<head>\n<script>if(/[?&](persona|lang)=/.test(location.search)||location.hash.indexOf('#/')===0)location.replace('${BASE}demo/'+location.search+location.hash)</script>`);
}
// Wurzelpfade (mit oder ohne BASE) gegen das Verzeichnis der Seite auflösen
const lokal = (u, base) => u.startsWith(BASE) ? join(ROOT, u.slice(BASE.length)) : u.startsWith('/') ? join(ROOT, u) : resolve(base, u);

function seite404() {
  return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Seite nicht gefunden | Page not found</title>
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<script>(function(){try{var m=localStorage.getItem('projektseite.thema');if(m==='light'||m==='dark'){document.documentElement.setAttribute('data-theme',m);}}catch(e){}})();</script>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="stylesheet" href="/assets/stil.css">
</head>
<body>
<main id="inhalt" class="abschnitt"><div class="wrap">
<h1>Seite nicht gefunden</h1>
<p class="lead">Diese Adresse gibt es hier nicht. <a href="/">Zur Startseite</a> oder <a href="${DEMO}">zur Demo</a>.</p>
<p class="lead" lang="en">This address does not exist here. <a href="/en/">To the home page</a> or <a href="${DEMO}?lang=en">to the demo</a>.</p>
</div></main>
</body>
</html>
`;
}

const attrOf = (html, re) => [...html.matchAll(re)].map(m => m[1]);

// ── R1, R2, R6, R9: Verweise und Bilder ────────────────────────────
for (const [file, html] of Object.entries(pages)) {
  const base = dirname(join(ROOT, file));
  const attr = (re) => attrOf(html, re);
  const loaded = [
    ...attr(/<(?:img|script|source|video)\b[^>]*\ssrc="([^"]+)"/g),
    ...attr(/\s(?:srcset|poster)="([^"]+)"/g).flatMap(s => s.split(',').map(x => x.trim().split(/\s+/)[0])),
    ...[...html.matchAll(/<link\b([^>]*)>/g)]
      .filter(m => /rel="(?:stylesheet|icon|preload|apple-touch-icon|manifest|modulepreload)"/.test(m[1]))
      .map(m => (m[1].match(/href="([^"]+)"/) || [])[1]),
  ];
  for (const u of loaded) {
    if (!u) continue;
    if (/^(?:[a-z]+:)?\/\//i.test(u) || /^data:/i.test(u)) { bad('R1', `${file} lädt von außen: ${u}`); continue; }
    const p = lokal(u, base);
    if (!existsSync(p.replace(/[?#].*$/, ''))) bad('R2', `${file} zeigt auf fehlende Datei: ${u}`);
  }
  for (const u of attr(/<a\b[^>]*\shref="([^"]+)"/g)) {
    if (/^(?:https?:|mailto:|#)/.test(u)) continue;
    let p = lokal(u, base);
    const hash = u.includes('#') ? u.slice(u.indexOf('#') + 1) : '';
    p = p.replace(/[?#].*$/, '');
    if (u.replace(/#.*$/, '').endsWith('/')) p = join(p, 'index.html');
    const ziel = Object.keys(pages).find(f => join(ROOT, f) === p);
    if (!ziel && !existsSync(p)) bad('R2', `${file} verlinkt fehlende Datei: ${u}`);
    if (ziel && hash && !new RegExp(`\\sid="${hash}"`).test(pages[ziel])) bad('R2', `${file}: Sprungmarke ${u} fehlt in ${ziel}`);
  }
  for (const u of attr(/<meta property="og:image" content="([^"]+)"/g)) {
    const local = u.startsWith(SITE) ? u.slice(SITE.length) : u;
    if (local === u) bad('R1', `${file}: Vorschaubild nicht auf der eigenen Seite: ${u}`);
    else if (!existsSync(join(ROOT, local))) bad('R2', `${file}: Vorschaubild fehlt: ${local} (node tools/bilder.mjs)`);
  }
  const ids = new Set(attr(/\sid="([^"]+)"/g));
  for (const u of attr(/\shref="#([^"]+)"/g)) if (!ids.has(u)) bad('R2', `${file}: Sprungmarke #${u} fehlt`);
  for (const use of attr(/<use href="#([^"]+)"/g)) if (!ids.has(use)) bad('R2', `${file}: Grafik #${use} fehlt im Sprite`);
  for (const m of html.matchAll(/<img\b[^>]*>/g)) {
    const tag = m[0];
    if (!/\salt="[^"]+"/.test(tag)) bad('R6', `${file}: Bild ohne Alternativtext: ${tag.slice(0, 80)}`);
    if (!/\swidth="\d+"/.test(tag) || !/\sheight="\d+"/.test(tag)) bad('R6', `${file}: Bild ohne Maße: ${tag.slice(0, 80)}`);
  }
  for (const m of html.matchAll(/<source\b[^>]*>/g)) {
    if (!/\swidth="\d+"/.test(m[0]) || !/\sheight="\d+"/.test(m[0])) bad('R6', `${file}: Bildquelle ohne Maße: ${m[0].slice(0, 80)}`);
  }
  if (file !== '404.html') {
    if ((html.match(/<h1\b/g) || []).length !== 1) bad('R9', `${file}: nicht genau ein h1`);
    if (!/<html lang="(de|en)">/.test(html)) bad('R9', `${file}: Sprache fehlt`);
    if (!/hreflang="de"/.test(html) || !/hreflang="en"/.test(html)) bad('R9', `${file}: Sprachfassungen nicht verknüpft`);
    const lang = html.match(/<html lang="(de|en)">/)?.[1];
    if (lang && !html.includes(`href="${BASE}${SEITEN.ueber[lang].slice(1)}"`)) bad('R9', `${file}: kein Link auf „Über das Projekt und Datenschutz“`);
  }
}

// ── R3, R4, R8: Text ───────────────────────────────────────────────
const sichtbar = (html) => html
  .replace(/<svg class="sprite"[\s\S]*?<\/svg>/, '')
  .replace(/<(script|style)\b[\s\S]*?<\/\1>/g, '')
  .replace(/<[^>]+\s(?:alt|content|aria-label|title|data-[a-z-]+)="([^"]*)"[^>]*>/g, ' $1 ')
  .replace(/<[^>]+>/g, ' ')
  .replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
const texte = { ...pages, 'aussagen.md': readFileSync(join(ROOT, 'aussagen.md'), 'utf8') };
for (const [file, content] of Object.entries(texte)) {
  const text = file.endsWith('.html') ? sichtbar(content) : content;
  for (const [ch, name] of [['\u2013', 'Halbgeviertstrich'], ['\u2014', 'Geviertstrich'], ['\u00b7', 'Mittelpunkt']]) {
    const i = text.indexOf(ch);
    if (i >= 0) bad('R3', `${file}: ${name} in „${text.slice(Math.max(0, i - 30), i + 30).replace(/\s+/g, ' ')}“`);
  }
  if (file.endsWith('.html')) {
    if (/€|\bEUR\b/.test(text)) bad('R4', `${file}: Betrag im Text`);
    const zahl = text.match(/\d[\d.,]*\s*(?:Downloads?|Sterne|Stars?|Nutzer\w*|Nutzerinnen|users?|installs?|Installationen)\b/i);
    if (zahl) bad('R4', `${file}: Zahl von Nutzern oder Downloads: ${zahl[0]}`);
  }
}
for (const [file, html] of Object.entries(pages)) {
  for (const m of html.matchAll(/<(p|li|figcaption|summary)\b[^>]*>([\s\S]*?)<\/\1>/g)) {
    const n = (m[2].match(/<strong>/g) || []).length;
    if (n > 1) bad('R8', `${file}: ${n} Fettungen in „${sichtbar(m[2]).trim().slice(0, 60)}“`);
  }
}

// ── R5: Belege ─────────────────────────────────────────────────────
const aussagen = readFileSync(join(ROOT, 'aussagen.md'), 'utf8');
const belegt = new Set([...aussagen.matchAll(/^\| `([a-z0-9-]+)` \|/gm)].map(m => m[1]));
const benutzt = new Set(Object.values(claims).flatMap(s => [...s]));
for (const c of benutzt) if (!belegt.has(c)) bad('R5', `Kennung ohne Beleg in aussagen.md: ${c}`);
for (const c of belegt) if (!benutzt.has(c)) bad('R5', `Zeile in aussagen.md, die keine Seite benutzt: ${c}`);
for (const art of Object.keys(SEITEN)) {
  const de = claims[`${art}.de`], en = claims[`${art}.en`];
  for (const c of de) if (!en.has(c)) bad('R5', `${art}: nur Deutsch belegt: ${c}`);
  for (const c of en) if (!de.has(c)) bad('R5', `${art}: nur Englisch belegt: ${c}`);
}

// ── R7: CSS ────────────────────────────────────────────────────────
const css = readFileSync(join(ROOT, 'assets/stil.css'), 'utf8');
const [tokens = '', rest = ''] = css.split('/* ── Ende der Tokens ── */');
if (!rest) bad('R7', 'Marke „Ende der Tokens“ fehlt in stil.css');
const hex = rest.match(/#[0-9a-f]{3,8}\b/i);
if (hex) bad('R7', `Farbwert außerhalb der Tokens: ${hex[0]}`);
if (/rgba?\(/.test(rest)) bad('R7', 'rgb()/rgba() außerhalb der Tokens');
if (/text-transform\s*:\s*uppercase/i.test(css)) bad('R7', 'Großschrift (text-transform: uppercase)');
if (/letter-spacing/i.test(css)) bad('R7', 'Sperrung (letter-spacing)');
for (const m of css.matchAll(/border-radius\s*:\s*([^;]+);/g)) {
  if (!/^(var\(--r-(sm|md)\)|50%)$/.test(m[1].trim())) bad('R7', `Radius außerhalb der drei Stufen: ${m[1]}`);
}
for (const m of css.matchAll(/url\(([^)]+)\)/g)) {
  const u = m[1].replace(/["']/g, '');
  if (/^(?:[a-z]+:)?\/\//i.test(u)) bad('R1', `stil.css lädt von außen: ${u}`);
  else if (!existsSync(resolve(join(ROOT, 'assets'), u))) bad('R2', `stil.css: Datei fehlt: ${u}`);
}
const js = readFileSync(join(ROOT, 'assets/seite.js'), 'utf8');
if (/https?:\/\/|fetch\(|XMLHttpRequest|import\(|sendBeacon|document\.cookie/.test(js)) bad('R1', 'seite.js spricht mit einem Server oder setzt Cookies');
const schluessel = [...js.matchAll(/'(projektseite\.[a-z]+)'/g)].map(m => m[1]);
if (!schluessel.length || schluessel.some(k => !k.startsWith('projektseite.'))) bad('R1', 'seite.js: Speicherschlüssel ohne Präfix projektseite.');

// ── R10: Darstellung ───────────────────────────────────────────────
const block = (re) => { const m = tokens.match(re); return m ? m[1].replace(/\s+/g, ' ').trim() : ''; };
const dunkelSystem = block(/:root:not\(\[data-theme="light"\]\)\s*\{([^}]*)\}/);
const dunkelWahl = block(/:root\[data-theme="dark"\]\s*\{([^}]*)\}/).replace(/color-scheme: dark; ?/, '');
if (!dunkelSystem || dunkelSystem !== dunkelWahl) bad('R10', 'dunkle Tokens für System und Wahl sind nicht gleich');
const hell = new Set([...block(/:root\s*\{([^}]*)\}/).matchAll(/(--[a-z0-9_-]+):/g)].map(m => m[1]));
for (const v of dunkelSystem.matchAll(/(--[a-z0-9_-]+):/g)) if (!hell.has(v[1])) bad('R10', `dunkles Token ohne helles: ${v[1]}`);
for (const [file, html] of Object.entries(pages)) {
  const kopf = html.slice(0, html.indexOf('<link rel="stylesheet"'));
  if (!/localStorage\.getItem\('projektseite\.thema'\)[\s\S]*setAttribute\('data-theme'/.test(kopf)) bad('R10', `${file}: Thema wird nicht vor dem Stylesheet gesetzt`);
  if (file === '404.html') continue;
  const knopf = html.match(/<button type="button" class="thema" id="thema"[^>]*>/)?.[0] || '';
  if (!knopf) bad('R10', `${file}: Umschalter fehlt`);
  for (const a of ['aria-label', 'data-label', 'data-system', 'data-light', 'data-dark', 'data-meldung-system', 'data-meldung-light', 'data-meldung-dark']) {
    if (knopf && !new RegExp(`\\s${a}="[^"]+"`).test(knopf)) bad('R10', `${file}: Umschalter ohne ${a}`);
  }
  for (const s of html.matchAll(/<source\b[^>]*>/g)) {
    const dunkel = /prefers-color-scheme: dark/.test(s[0]);
    if (dunkel !== /data-thema="dunkel"/.test(s[0])) bad('R10', `${file}: dunkle Bildquelle nicht umschaltbar: ${s[0].slice(0, 90)}`);
  }
}
if (!/projektseite\.thema/.test(js)) bad('R10', 'seite.js kennt den Schlüssel projektseite.thema nicht');

// ── R11: Slider ────────────────────────────────────────────────────
for (const lang of ['de', 'en']) {
  const file = DATEI(SEITEN.start[lang]);
  const html = pages[file];
  const tabs = attrOf(html, /<a role="tab"[^>]*aria-controls="([^"]+)"/g);
  const panels = attrOf(html, /<figure class="folie[^"]*" id="([^"]+)" role="tabpanel"/g);
  if (tabs.length !== SLIDER.folien.length || panels.length !== tabs.length) bad('R11', `${file}: ${tabs.length} Reiter, ${panels.length} Bilder, ${SLIDER.folien.length} im Quelltext`);
  tabs.forEach((t, i) => { if (panels[i] !== t) bad('R11', `${file}: Reiter ${i + 1} zeigt auf ${t}, Bild heißt ${panels[i]}`); });
  for (const f of html.matchAll(/<figure class="folie[\s\S]*?<\/figure>/g)) {
    if (!/<img\b[^>]*\salt="[^"]+"/.test(f[0]) && !/<video\b/.test(f[0])) bad('R11', `${file}: Bild im Slider ohne Alternativtext`);
    if (!/class="folie__frage"/.test(f[0]) || !/class="folie__demo"/.test(f[0])) bad('R11', `${file}: Bild im Slider ohne Frage oder Sprung in die Demo`);
  }
}

// ── R12: Animationen ───────────────────────────────────────────────
const ANIM_MAX = 1.5 * 1024 * 1024;
const BEWEGUNG = '(prefers-reduced-motion: no-preference)';
const ANIM_DIR = join(ROOT, 'assets/img/anim');
const animListe = existsSync(join(ANIM_DIR, 'quellen.json')) ? JSON.parse(readFileSync(join(ANIM_DIR, 'quellen.json'), 'utf8')).bilder : [];
const bildListe = JSON.parse(readFileSync(join(ROOT, 'assets/img/quellen.json'), 'utf8')).bilder;
const ohneBasis = (u) => u.replace(/^\//, '').replace(BASE.slice(1), '');
const animGenutzt = new Set();
for (const [file, html] of Object.entries(pages)) {
  for (const m of html.matchAll(/<picture>([\s\S]*?)<\/picture>/g)) {
    const quellen = [...m[1].matchAll(/<source\b([^>]*)>/g)].map(s => ({
      tag: s[1],
      media: (s[1].match(/\smedia="([^"]*)"/) || [])[1] || '',
      src: ohneBasis((s[1].match(/\ssrcset="([^"]*)"/) || [])[1] || ''),
      type: (s[1].match(/\stype="([^"]*)"/) || [])[1] || '',
    }));
    const anim = quellen.filter(q => q.src.startsWith('assets/img/anim/'));
    if (!anim.length) continue;
    const img = ohneBasis((m[1].match(/<img\b[^>]*\ssrc="([^"]+)"/) || [])[1] || '');
    if (!/<img\b[^>]*\sloading="lazy"/.test(m[1])) bad('R12', `${file}: Animation lädt nicht erst beim Scrollen (${anim[0].src})`);
    const ersteStatisch = quellen.findIndex(q => !q.src.startsWith('assets/img/anim/'));
    if (ersteStatisch >= 0 && quellen.slice(ersteStatisch).some(q => q.src.startsWith('assets/img/anim/'))) bad('R12', `${file}: Animation steht nach einem Rückfallbild (${anim[0].src})`);
    for (const q of anim) {
      animGenutzt.add(q.src);
      const n = q.src.match(/^assets\/img\/anim\/(.+?)(-dunkel)?\.(de|en)\.webp$/);
      if (!n) { bad('R12', `${file}: Animation nicht als WebP nach dem Muster <szene>[-dunkel].<de|en>.webp: ${q.src}`); continue; }
      const [, szene, dunkel = '', lang] = n;
      if (!q.media.includes(BEWEGUNG)) bad('R12', `${file}: ${q.src} gilt auch bei „Bewegung reduzieren“`);
      if (dunkel && !q.tag.includes(`data-breite="${BEWEGUNG}"`)) bad('R12', `${file}: ${q.src} verliert beim Umschalter die Bedingung „Bewegung“`);
      if (q.type !== 'image/webp') bad('R12', `${file}: ${q.src} ohne type="image/webp"`);
      // Rückfallbild: dieselbe Szene, hell als <img>, dunkel als Quelle für das dunkle Thema
      const rueckfall = `assets/img/screens/${szene}${dunkel}.${lang}.webp`;
      if (!dunkel && img !== rueckfall) bad('R12', `${file}: Rückfallbild zu ${q.src} ist „${img}“, erwartet ${rueckfall}`);
      if (dunkel && !quellen.some(x => x.src === rueckfall && x.media === '(prefers-color-scheme: dark)')) bad('R12', `${file}: dunkles Rückfallbild ${rueckfall} fehlt neben ${q.src}`);
      const a = animListe.find(b => b.datei === q.src);
      const p = bildListe.find(b => b.datei === rueckfall);
      if (!a) bad('R12', `${q.src} fehlt in assets/img/anim/quellen.json (node tools/build-animations.mjs im Haupt-Repo)`);
      else if (!p) bad('R12', `${rueckfall} fehlt in assets/img/quellen.json (node tools/bilder.mjs)`);
      else if (p.breite !== a.breite || p.hoehe !== a.hoehe) bad('R12', `${rueckfall}: ${p.breite} × ${p.hoehe}, die Animation ${a.breite} × ${a.hoehe}`);
    }
  }
}
if (existsSync(ANIM_DIR)) {
  for (const f of readdirSync(ANIM_DIR).sort()) {
    if (f === 'quellen.json' || f.startsWith('.')) continue;
    const rel = `assets/img/anim/${f}`;
    if (/\.gif$/i.test(f)) { bad('R12', `${rel}: GIF unter assets/img/anim/ (Animationen nur als WebP)`); continue; }
    const n = f.match(/^(.+?)(-dunkel)?\.(de|en)\.webp$/);
    if (!n) { bad('R12', `${rel}: keine Animation nach dem Muster <szene>[-dunkel].<de|en>.webp`); continue; }
    if (!existsSync(join(ROOT, `assets/img/screens/${n[1]}${n[2] || ''}.${n[3]}.webp`))) bad('R12', `${rel} ohne Rückfallbild unter assets/img/screens/`);
    const groesse = statSync(join(ANIM_DIR, f)).size;
    if (groesse > ANIM_MAX) bad('R12', `${rel}: ${(groesse / 1048576).toFixed(2).replace('.', ',')} MB, mehr als 1,5 MB`);
    if (!animGenutzt.has(rel)) bad('R12', `${rel} benutzt keine Seite`);
  }
}

// ── Schreiben oder vergleichen ─────────────────────────────────────
if (CHECK) {
  for (const [file, html] of Object.entries(pages)) {
    const p = join(ROOT, file);
    if (!existsSync(p) || readFileSync(p, 'utf8') !== html) bad('Bau', `${file} passt nicht zum Quelltext: node tools/build.mjs`);
  }
} else {
  for (const [file, html] of Object.entries(pages)) {
    mkdirSync(dirname(join(ROOT, file)), { recursive: true });
    writeFileSync(join(ROOT, file), html);
  }
}

if (fail.length) {
  console.error(`${fail.length} Regelverstöße:\n  ${fail.join('\n  ')}`);
  process.exit(1);
}
console.log(`${CHECK ? 'geprüft' : 'gebaut'}: ${Object.keys(pages).join(', ')}; ${belegt.size} belegte Aussagen; ${animGenutzt.size} Animationen; Regeln R1 bis R12 eingehalten`);
