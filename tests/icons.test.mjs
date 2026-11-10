// =====================================================================
// v3.12.0 (Designsprache) — eine Quelle für die Grafiken, keine Emoji als Symbol.
//
// Prüft die Bauweise der Zeichnungen, den Aufruf, die Masken der fünf Töne gegen die
// Quelle, die SSOT der Verbrauchsarten und vor allem: Keine Oberflächen-Datei und
// kein Katalog gibt mehr Emoji oder Symbolzeichen aus. Erlaubt sind sie nur an Zählern
// und in dem, was Nutzer tippen; das Feld `icon` bleibt in API, CSV, Home Assistant.
// Die erlaubten Ausnahmen stehen unten namentlich, mit Grund.
// =====================================================================
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');
let pass = 0, fail = 0;
const t = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};

const { ICONS, icon, artIcon, artGlyph, meterGlyph } = await import(pathToFileURL(resolve(ROOT, 'public/js/lib/icons.js')).href);

// ── Die Zeichnungen ──────────────────────────────────────────────────
const names = Object.keys(ICONS);
t('Mindestens die 89 Grafiken des Entwurfs', names.length >= 89, String(names.length));
for (const n of ['art-gas', 'art-strom', 'art-wasser', 'art-fernwaerme', 'art-heizoel', 'art-pellets', 'art-pv_einspeisung', 'art-pv_erzeugung', 'art-waerme',
  'info', 'erfolg', 'achtung', 'fehler', 'neutral', 'kreuz', 'haken', 'stift', 'eimer', 'plus', 'menue', 'home']) {
  t(`Grafik ${n} vorhanden`, typeof ICONS[n] === 'string' && ICONS[n].length > 10);
}
const badDraw = names.filter(n => !/^<(path|rect|circle|ellipse|line|polyline|polygon) /.test(ICONS[n]) || /(script|on\w+=|href|style=|fill="#|stroke="#)/.test(ICONS[n]));
t('Nur Formen, keine Skripte, keine festen Farben', badDraw.length === 0, badDraw.join(', '));
const outside = names.filter(n => [...ICONS[n].matchAll(/-?\d+\.?\d*/g)].some(m => Math.abs(parseFloat(m[0])) > 24));
t('Alles auf dem 24er-Raster', outside.length === 0, outside.join(', '));

// ── Der Aufruf ───────────────────────────────────────────────────────
t('icon(): Zier mit aria-hidden über <use>', icon('home') === '<svg class="ico" aria-hidden="true" focusable="false"><use href="#i-home"/></svg>', icon('home'));
t('icon(): Größen als Klasse', icon('home', { size: 20 }).includes('ico--20') && icon('home', { size: 48 }).includes('ico--48') && !icon('home', { size: 18 }).includes('ico--18'));
t('icon(): Beschriftung macht daraus ein Bild, maskiert', icon('home', { title: '<a>"' }).includes('role="img"') && icon('home', { title: '<a>"' }).includes('aria-label="&lt;a&gt;&quot;"'));
t('icon(): Unbekanntes führt nicht in den Sprite-Namensraum', icon('"><script>').includes('#i-neutral') && !icon('"><script>').includes('script'));
t('artIcon(): Farbe der Art über die Klasse', artIcon('gas').includes('ico--art') && artIcon('gas').includes('art-gas') && artIcon({ key: 'strom' }).includes('#i-art-strom'));
t('artIcon(): Glyph aus der SSOT hat Vorrang', artGlyph({ key: 'gas', glyph: 'art-strom' }) === 'art-strom' && artGlyph({ key: 'neu' }) === 'neutral');
t('artIcon(): fremder Schlüssel kommt nicht in die Klasse', !artIcon({ key: '"><img src=x onerror=1>' }).includes('onerror'));
t('meterGlyph(): Vorgabewert wird zur Grafik', meterGlyph({ icon: '🔥' }, { key: 'gas', icon: '🔥' }).includes('#i-art-gas'));
t('meterGlyph(): Ohne Eintrag die Grafik', meterGlyph({}, { key: 'gas', icon: '🔥' }).includes('#i-art-gas'));
t('meterGlyph(): Selbst Eingetragenes bleibt, maskiert', meterGlyph({ icon: '🚗' }, { key: 'strom', icon: '⚡' }).includes('🚗') && !meterGlyph({ icon: '<img>' }, { key: 'strom', icon: '⚡' }).includes('<img>'));

// ── Masken der Töne: dieselben Zeichnungen wie die Quelle ────────────
const tokens = read('public/css/tokens.css');
for (const tone of ['info', 'erfolg', 'achtung', 'fehler', 'neutral']) {
  const body = encodeURIComponent(ICONS[tone].replace(/class="f"/g, "fill='#000' fill-opacity='.16' stroke='none'").replace(/"/g, "'"));
  t(`Maske ${tone} entspricht lib/icons.js`, tokens.includes(`--mask-${tone}: url("data:image/svg+xml,`) && tokens.includes(body));
}

// ── SSOT: `glyph` additiv, `icon` unverändert ────────────────────────
const util = read('src/Config/Utilities.php');
const glyphs = [...util.matchAll(/'glyph'\s*=>\s*'([^']+)'/g)].map(m => m[1]);
t('Jede Verbrauchsart hat eine Grafik', glyphs.length === 9 && glyphs.every(g => ICONS[g]), glyphs.join(', '));
const emoji = [...util.matchAll(/'icon'\s*=>\s*'([^']+)'/g)].map(m => m[1]);
t('Das Feld icon bleibt in der SSOT', emoji.length === 9 && emoji.every(e => /\p{Extended_Pictographic}/u.test(e)), emoji.join(' '));

// ── Keine Emoji und Symbolzeichen als Symbol ─────────────────────────
// v3.12.0 (Sichtung S7) — `Extended_Pictographic` kennt ★ ◆ ▲ ● ❶ nicht: Deshalb stand ★ unbemerkt in der Druckansicht.
// Dazu die Blöcke, aus denen solche Zeichen kommen: umschlossene Zeichen, geometrische Formen, Symbole, Dingbats.
const SYM = /(?:\p{Extended_Pictographic}|[\u2460-\u24FF\u25A0-\u27BF\u2B00-\u2BFF]|[＋☰✓✕✎›‹✔✖ⓘ])/u;
const walk = (d, f = []) => { for (const n of readdirSync(d)) { const p = `${d}/${n}`; if (statSync(p).isDirectory()) walk(p, f); else f.push(p); } return f; };

/**
 * Erlaubte Ausnahmen, namentlich: Datei → Muster der Zeile, Grund.
 * Ein Eintrag, der nicht mehr gebraucht wird, lässt den Test scheitern.
 */
const ERLAUBT = [
  // Pfadangabe mitten im Satz („Einstellungen › Verbrauchsarten › …“): reiner Text in einem Link
  ['public/js/views/analysis.js', /join\(' › '\)/],
  // v3.12.0 (M3) — Ort eines Treffers in der Suche: derselbe reine Text
  ['public/js/lib/settings-index.js', /join\(' › '\)/],
];
const benutzt = new Set();
const stripLine = (l) => l.replace(/<!--.*?-->/g, '').replace(/(^|\s)\/\/.*$/, '$1');
const hits = [];
for (const abs of [...walk(resolve(ROOT, 'public/js')).filter(x => x.endsWith('.js')), resolve(ROOT, 'index.php')]) {
  const rel = abs.slice(ROOT.length + 1);
  let inHtmlComment = false, inBlock = false;
  readFileSync(abs, 'utf8').split('\n').forEach((raw, i) => {
    // Kommentare: Sie erklären oft, was früher da stand
    if (inBlock) { if (raw.includes('*/')) inBlock = false; return; }
    if (/^\s*\/\*/.test(raw)) { inBlock = !raw.includes('*/'); return; }
    if (/^\s*(\/\/|\*)/.test(raw)) return;
    if (inHtmlComment) { if (raw.includes('-->')) inHtmlComment = false; return; }
    if (raw.includes('<!--') && !raw.includes('-->')) { inHtmlComment = true; return; }
    const line = stripLine(raw);
    if (!SYM.test(line)) return;
    const ok = ERLAUBT.findIndex(([f, re]) => f === rel && re.test(line));
    if (ok >= 0) { benutzt.add(ok); return; }
    hits.push(`${rel}:${i + 1}`);
  });
}
t('Keine Oberflächen-Datei gibt Emoji oder Symbolzeichen aus', hits.length === 0, hits.slice(0, 12).join(', '));
ERLAUBT.forEach(([f], i) => t(`Ausnahme wird noch gebraucht: ${f}`, benutzt.has(i)));

// ── v3.12.0 (Sichtung S7) — auch serverseitige Ausgaben ──────────────
// Bis dahin prüfte der Test nur public/js und die Kataloge. Das PDF zeichnete ❶ ★ ◆ ✔ ✖ ▲ aus ZapfDingbats, per Name aus PHP.
const SERVER_ERLAUBT = [
  // Das Feld `icon` bleibt in API, CSV und Home Assistant, die Oberfläche liest `glyph`
  ['src/Config/Utilities.php', /'icon'\s*=>/],
  // Alte Daten: Text und Vorgabewert einer Migration von v1.0.2, die laufen muss wie damals
  ['src/Storage/Migrator.php', /\$hint = '⚠|'icon'\s*=>/],
  // Reparatur von Zeichensalat beim Auslesen (™ aus „â€™“): Eingabe, keine Ausgabe
  ['src/Services/Inbox/Evidence.php', /=>/],
  // Termin im Kalender des Nutzers (ICS): dort gibt es nur Text, das Häkchen steht im Titel eines erledigten Termins
  ['src/Services/CalendarService.php', /DONE_MARK = /],
];
const serverBenutzt = new Set();
const serverHits = [];
for (const abs of walk(resolve(ROOT, 'src')).filter(x => x.endsWith('.php'))) {
  const rel = abs.slice(ROOT.length + 1);
  let block = false;
  readFileSync(abs, 'utf8').split('\n').forEach((raw, i) => {
    if (block) { if (raw.includes('*/')) block = false; return; }
    if (/^\s*\/\*/.test(raw)) { block = !raw.includes('*/'); return; }
    if (/^\s*(\/\/|\*|#)/.test(raw)) return;
    const line = raw.replace(/(^|\s)\/\/.*$/, '$1');
    if (!SYM.test(line)) return;
    const ok = SERVER_ERLAUBT.findIndex(([f, re]) => f === rel && re.test(line));
    if (ok >= 0) { serverBenutzt.add(ok); return; }
    serverHits.push(`${rel}:${i + 1}`);
  });
}
t('Keine Server-Datei gibt Emoji oder Symbolzeichen aus', serverHits.length === 0, serverHits.slice(0, 12).join(', '));
SERVER_ERLAUBT.forEach(([f], i) => t(`Ausnahme wird noch gebraucht: ${f}`, serverBenutzt.has(i)));
for (const f of ['src/Services/PdfReportService.php', 'src/Services/Pdf/Chart.php']) {
  t(`${f}: zeichnet Grafiken, keine Symbolschrift`, !/->symbol\(/.test(read(f)));
}

const flat = (o, pre = '', a = []) => { for (const [k, v] of Object.entries(o)) { if (v && typeof v === 'object') flat(v, pre + k + '.', a); else a.push([pre + k, String(v)]); } return a; };
const languages = Object.keys(JSON.parse(read('public/locales/languages.json')));
t('Sieben Sprachen geprüft', languages.length === 7, languages.join());
for (const l of languages) {
  const bad = flat(JSON.parse(read(`public/locales/${l}.json`))).filter(([, v]) => SYM.test(v)).map(([k]) => k);
  t(`Katalog ${l}: kein Emoji, kein Symbolzeichen`, bad.length === 0, bad.slice(0, 8).join(', '));
}

console.log(`\n  icons: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
