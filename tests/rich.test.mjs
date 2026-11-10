// =====================================================================
// v3.12.0 (Designsprache) — rich(): Auszeichnung aus den Katalogen, ohne rohes HTML.
//
// Alles wird maskiert, nur **…**, *…*, `…` und [Text](#/route) werden zu Auszeichnung.
// Parameter bleiben Text, auch wenn sie Marken oder <script> enthalten. Der Wegweiser
// ist das erste Beispiel: In allen 7 Sprachen gleich viele Marken, paarig, kein `<`.
// Dazu die Doku-Seite des Wegweisers, die dieselben Marken als Markdown übernimmt.
// =====================================================================
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');
const JS = pathToFileURL(resolve(ROOT, 'public/js')).href;
let pass = 0, fail = 0;
const t = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', { url: 'http://127.0.0.1/', pretendToBeVisual: true });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; } catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch { /* */ }

// Eigener kleiner Katalog für die Fälle, dazu der echte für die Sprachprüfung
const TEST = {
  r: {
    bold: '**Rund {amount} zurück.** Bei der Abrechnung am {date}.',
    em: 'Das *Stichwort* steht im Satz.',
    code: 'Nimm `-v energietracker-data:/data` beim Start.',
    codeStar: 'Der Stern in `a*b*c` bleibt.',
    html: 'Vorher <script>alert(1)</script> nachher <b>fett</b> & Co.',
    link: 'Siehe [Einstellungen](#/settings/data?field=x&y=1).',
    badLink: '[weg](javascript:alert(1)) [fremd](https://example.org) [daten](data:text/html,x)',
    unpaired: 'Ein ** einzelnes Paar und * ein Stern.',
    star: 'Fläche 5 * 3 ergibt 15, kein Kursiv.',
    multi: '**Eins.** Dazwischen. **Zwei.**',
    nested: '**Fett mit `code` darin.**',
    param: 'Wert: {x}.',
    fr: '**Vraiment ?** Oui : 5 %.',
  },
};
global.fetch = async (url) => {
  if (String(url).includes('languages.json')) return new Response(JSON.stringify({ de: 'Deutsch', fr: 'Français' }), { status: 200 });
  const m = String(url).match(/public\/locales\/([a-z]+)\.json/);
  if (m) return new Response(JSON.stringify({ ...TEST, format: { typography: m[1] === 'fr' ? 'fr' : 'none' } }), { status: 200 });
  return new Response('{}', { status: 404 });
};
const { initI18n, setCurrencyParams } = await import(`${JS}/lib/i18n.js`);
const { rich, richText } = await import(`${JS}/lib/rich.js`);
await initI18n('de');
setCurrencyParams('EUR');

// ── Marken ───────────────────────────────────────────────────────────
t('**fett** wird strong', rich('r.bold', { amount: '759 €', date: '31.12.' }) === '<strong>Rund 759 € zurück.</strong> Bei der Abrechnung am 31.12..', rich('r.bold', { amount: '759 €', date: '31.12.' }));
t('*kursiv* wird em', rich('r.em') === 'Das <em>Stichwort</em> steht im Satz.');
t('`code` wird code', rich('r.code') === 'Nimm <code>-v energietracker-data:/data</code> beim Start.');
t('Sterne in Code bleiben', rich('r.codeStar') === 'Der Stern in <code>a*b*c</code> bleibt.', rich('r.codeStar'));
t('Interner Link nur mit #/', rich('r.link').includes('<a href="#/settings/data?field=x&amp;y=1">Einstellungen</a>'), rich('r.link'));
t('Unpaarige Marken bleiben Text', rich('r.unpaired') === 'Ein ** einzelnes Paar und * ein Stern.', rich('r.unpaired'));
t('Rechenzeichen wird nicht kursiv', rich('r.star') === 'Fläche 5 * 3 ergibt 15, kein Kursiv.', rich('r.star'));
t('Mehrere Marken im Satz', rich('r.multi') === '<strong>Eins.</strong> Dazwischen. <strong>Zwei.</strong>', rich('r.multi'));
t('Code in Fett', rich('r.nested') === '<strong>Fett mit <code>code</code> darin.</strong>', rich('r.nested'));
t('Fehlender Schlüssel bleibt der Schlüssel', rich('r.gibtsnicht') === 'r.gibtsnicht');
t('richText für fertige Texte', richText('**a** <b>') === '<strong>a</strong> &lt;b&gt;');

// ── Bösartige Eingaben ───────────────────────────────────────────────
const render = (html) => { const d = document.createElement('div'); d.innerHTML = html; return d; };
const htmlOut = rich('r.html');
t('HTML im Katalog wird Text', !/<script|<b>/.test(htmlOut) && htmlOut.includes('&lt;script&gt;') && htmlOut.includes('&amp; Co.'), htmlOut);
const POISON = ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>', "'; alert(1); //", '**fett**', '*kursiv*', '`code`', '[link](#/settings)', '[böse](javascript:alert(1))', '0', '{x}', '$& $1', '&amp;'];
for (const p of POISON) {
  const out = rich('r.param', { x: p });
  const el = render(out);
  t(`Parameter bleibt Text: ${p}`, el.children.length === 0 && el.textContent === `Wert: ${p.replace(/[-]/g, '')}.`, out);
}
const bad = render(rich('r.badLink'));
t('Nur #/-Links: javascript:, https: und data: bleiben Text', bad.querySelectorAll('a').length === 0, bad.innerHTML);
t('Kein Marken-Überlauf aus Parametern in den Katalogtext', rich('r.bold', { amount: '**', date: '**' }).startsWith('<strong>Rund ** zurück.</strong>'), rich('r.bold', { amount: '**', date: '**' }));
t('Fremdes in Text: richText maskiert auch Steuerzeichen der Parameter', !richText('a0b').includes(''));

// ── typography() verträgt die Marken ─────────────────────────────────
await initI18n('fr');
const fr = rich('r.fr');
t('Französisch: geschützte Leerzeichen neben den Marken', fr.includes('<strong>Vraiment ?</strong>') && fr.includes('Oui :') && fr.includes('5 %'), fr);

// ── Der Wegweiser in allen 7 Sprachen ────────────────────────────────
const LANGS = Object.keys(JSON.parse(read('public/locales/languages.json')));
const cats = Object.fromEntries(LANGS.map(l => [l, JSON.parse(read(`public/locales/${l}.json`))]));
const flat = (o, pre = '', a = []) => { for (const [k, v] of Object.entries(o)) { if (v && typeof v === 'object') flat(v, pre + k + '.', a); else a.push([pre + k, String(v)]); } return a; };
const get = (l, k) => k.split('.').reduce((n, s) => n?.[s], cats[l]);
const keys = flat(cats.de.setups, 'setups.').map(([k]) => k);
t('Sieben Sprachen', LANGS.length === 7);
const count = (s, re) => (s.match(re) || []).length;
let marked = 0;
for (const k of keys) {
  const de = String(get('de', k));
  const b = count(de, /\*\*/g), c = count(de, /`/g);
  if (b) marked++;
  t(`${k}: Marken paarig`, b % 2 === 0 && c % 2 === 0, de);
  t(`${k}: kein < im Text`, !de.includes('<'), de);
  for (const l of LANGS.slice(1)) {
    const v = String(get(l, k) ?? '');
    if (count(v, /\*\*/g) !== b || count(v, /`/g) !== c) t(`${k} [${l}]: gleich viele Marken wie Deutsch`, false, v);
    if (v.includes('<')) t(`${k} [${l}]: kein < im Text`, false, v);
  }
  // Fett höchstens einmal je Absatz, nie der ganze Absatz (wenn weiterer Text folgt)
  if (b) t(`${k}: höchstens eine Fettstelle`, b === 2, de);
}
t('Der Wegweiser ist ausgezeichnet (mindestens 60 Texte mit Kernaussage)', marked >= 60, String(marked));
const view = read('public/js/views/help-setups.js');
t('Der Wegweiser nutzt rich() aus lib/rich.js, keine eigene Fassung', /from '\.\.\/lib\/rich\.js'/.test(view) && !/const rich =/.test(view));
t('Doku-Seite trägt die Auszeichnung als Markdown', /\*\*[^*]+\*\*/.test(read('docs/einstieg/wegweiser.md')) && !/<code>|<strong>/.test(read('docs/einstieg/wegweiser.md')));

console.log(`\n  rich.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
