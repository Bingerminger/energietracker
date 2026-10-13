// v3.4.0 (N1019) — Hilfe auf dem Server: Jede Seite der Doku rendert sicher
// und vollständig.
//
//   node tests/docs.test.mjs
//
// Die Freigabeliste kommt vom echten DocsService (über die PHP-Kommandozeile),
// gerendert wird mit lib/markdown.js und den Linkregeln aus lib/docs.js — wie
// in der App. Grün nur, wenn
//   • jedes In-App-Ziel freigegeben ist und jede Sprungmarke (`?h=`) dort existiert,
//   • jedes Bild freigegeben ist,
//   • die Ausgabe nur erlaubte Tags und Attribute enthält (kein <script>, kein
//     on…=, kein javascript:), externe Links mit rel="noopener noreferrer",
//   • keine Markdown-Reste stehen bleiben,
//   • Angriffs-Payloads entschärft werden — im Renderer allein und in den Linkregeln allein,
//   • der Service Worker die Doku offline vorhält.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const { renderMarkdown, slug, ID_PREFIX } = await import(`${ROOT}/public/js/lib/markdown.js`);
const { docLinks, CASES } = await import(`${ROOT}/public/js/lib/docs.js`);

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

// ── Freigabeliste vom Server-Code (eine Quelle) ──
const php = `require 'src/bootstrap.php';
$d = new Energietracker\\Services\\DocsService(getcwd());
$pages = [];
foreach ($d->pages() as $k => $f) $pages[$k] = ['lang' => Energietracker\\Services\\DocsService::lang($k), 'file' => substr($f, strlen(getcwd()) + 1)];
echo json_encode(['pages' => $pages, 'assets' => array_keys($d->assets()), 'root_files' => $d->rootFiles(), 'version' => $d->version()]);`;
const allow = JSON.parse(execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' }));
const pages = allow.pages;
const assets = new Set(allow.assets);
const VERSION = allow.version;
const ASSET = 'api.php/api/docs/asset?path=';
const assetUrl = (p) => `${ASSET}${encodeURIComponent(p)}&v=${encodeURIComponent(VERSION)}`;
t('Freigabeliste', Object.keys(pages).length > 50 && assets.size > 10 && allow.root_files.includes('CHANGELOG.md'),
  `${Object.keys(pages).length} Seiten, ${assets.size} Bilder, Wurzel: ${allow.root_files.join(' ')}`);
t('Freigabeliste: keine Weiterleitung, kein Promo-Bild',
  !Object.keys(pages).some(k => /^<!-- (umgezogen|moved):/.test(readFileSync(`${ROOT}/${pages[k].file}`, 'utf8').slice(0, 40)))
  && ![...assets].some(a => !a.startsWith('ui/screenshots/') || !a.endsWith('.png')));

// ── Prüfung der Ausgabe im DOM ──
const { document } = new JSDOM('<!doctype html><body></body>').window;
const TAGS = new Set(['a', 'blockquote', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'img', 'input',
  'li', 'ol', 'p', 'pre', 'strong', 'table', 'tbody', 'td', 'th', 'thead', 'tr', 'ul']);
const ATTRS = {
  a: ['href', 'target', 'rel', 'id'], img: ['src', 'alt', 'width', 'loading'], pre: ['class', 'data-lang'],
  div: ['class'], p: ['class'], ol: ['start'], input: ['type', 'disabled', 'checked'],
  h1: ['id'], h2: ['id'], h3: ['id'], h4: ['id'], h5: ['id'], h6: ['id'],
};
const SAFE_HREF = /^(?:#\/|https?:\/\/|mailto:)/i;

/** Unsichere Stellen eines gerenderten HTML: unbekannte Tags/Attribute, Skript-Schemata, Handler. */
function unsafe(html, { relativeOnly = true } = {}) {
  const tpl = document.createElement('template');
  tpl.innerHTML = html;
  const bad = [];
  for (const el of tpl.content.querySelectorAll('*')) {
    const tag = el.localName;
    if (!TAGS.has(tag)) { bad.push(`<${tag}>`); continue; }
    for (const { name, value } of [...el.attributes]) {
      if (!(ATTRS[tag] || []).includes(name)) bad.push(`<${tag} ${name}>`);
      if (/^on/i.test(name)) bad.push(`<${tag} ${name}> Handler`);
      if (/^\s*(javascript|data|vbscript):/i.test(value)) bad.push(`<${tag} ${name}="${value.slice(0, 30)}">`);
    }
    if (tag === 'a' && el.hasAttribute('href')) {
      const href = el.getAttribute('href');
      if (!SAFE_HREF.test(href)) bad.push(`href ${href.slice(0, 40)}`);
      const ext = /^(https?:|mailto:)/i.test(href);
      if (ext !== (el.getAttribute('target') === '_blank') || (ext && el.getAttribute('rel') !== 'noopener noreferrer')) bad.push(`extern ohne rel/target: ${href.slice(0, 40)}`);
    }
    if (tag === 'a' && el.hasAttribute('id') && !el.id.startsWith(ID_PREFIX)) bad.push(`id ohne Präfix: ${el.id}`);
    if (/^h[1-6]$/.test(tag) && !el.id.startsWith(ID_PREFIX)) bad.push(`Überschrift ohne Präfix: ${el.id}`);
    if (tag === 'img') {
      const src = el.getAttribute('src') || '';
      if (/^[a-z][a-z0-9+.-]*:/i.test(src) || src.startsWith('/') || src.includes('\\')) bad.push(`img ${src.slice(0, 40)}`);
      if (relativeOnly && !src.startsWith(ASSET)) bad.push(`img nicht über den Endpunkt: ${src.slice(0, 40)}`);
    }
  }
  if (/<script/i.test(html)) bad.push('<script');
  return { bad, root: tpl.content };
}

// ── 1. Alle Seiten rendern ──
const rendered = {};
let totalMs = 0;
let slowest = ['', 0];
for (const [key, info] of Object.entries(pages)) {
  if (!key.endsWith('.md')) continue;   // LICENSE: reiner Text
  const md = readFileSync(`${ROOT}/${info.file}`, 'utf8');
  const links = docLinks(key, { rootFiles: allow.root_files, version: VERSION, assetUrl });
  const t0 = performance.now();
  const out = renderMarkdown(md, links);
  const ms = performance.now() - t0;
  totalMs += ms;
  if (ms > slowest[1]) slowest = [key, ms];
  rendered[key] = { ...out, ids: new Set(out.ids) };
}
t('alle Seiten gerendert', Object.keys(rendered).length === Object.keys(pages).filter(k => k.endsWith('.md')).length,
  `${Object.keys(rendered).length} Seiten in ${Math.round(totalMs)} ms, langsamste ${slowest[0]} (${Math.round(slowest[1])} ms)`);

const badLinks = [], badAnchors = [], badImages = [], unsafeOut = [], artefacts = [];
let inApp = 0, external = 0, images = 0, githubTag = 0;
for (const [key, out] of Object.entries(rendered)) {
  const { bad, root } = unsafe(out.html);
  if (bad.length) unsafeOut.push(`${key}: ${bad.slice(0, 3).join(', ')}`);
  for (const a of root.querySelectorAll('a[href]')) {
    const href = a.getAttribute('href');
    if (href.startsWith('#/help/doc/')) {
      inApp++;
      const [path, query = ''] = href.slice('#/help/doc/'.length).split('?');
      const target = decodeURIComponent(path);
      if (!pages[target]) { badLinks.push(`${key} → ${target}`); continue; }
      const h = new URLSearchParams(query).get('h');
      if (h && target.endsWith('.md') && !rendered[target]?.ids.has(h)) badAnchors.push(`${key} → ${target}#${h}`);
    } else {
      external++;
      if (href.startsWith(`https://github.com/Bingerminger/energietracker/tree/v${VERSION}/`)) githubTag++;
      if (/github\.com\/Bingerminger\/energietracker\/blob\//.test(href)) badLinks.push(`${key} → ${href} (blob)`);
    }
  }
  for (const img of root.querySelectorAll('img')) {
    images++;
    const p = new URLSearchParams(img.getAttribute('src').split('?')[1] || '').get('path');
    if (!assets.has(p)) badImages.push(`${key} → ${p}`);
  }
  const text = out.html.replace(/<pre[\s\S]*?<\/pre>/g, '').replace(/<code>[\s\S]*?<\/code>/g, '');
  const rest = text.match(/\*\*|\]\(|^\|.*\|$|```/m);
  if (rest) artefacts.push(`${key}: ${rest[0]}`);
}
t('jedes In-App-Ziel ist freigegeben', badLinks.length === 0, badLinks.slice(0, 5).join(' · ') || `${inApp} Links in der App, ${external} extern, ${githubTag} auf den Tag v${VERSION}`);
t('jede Sprungmarke existiert im Ziel', badAnchors.length === 0, badAnchors.slice(0, 5).join(' · '));
t('jedes Bild ist freigegeben', badImages.length === 0 && images > 20, badImages.slice(0, 5).join(' · ') || `${images} Bilder`);
t('nur erlaubte Tags und Attribute, extern mit rel="noopener noreferrer"', unsafeOut.length === 0, unsafeOut.slice(0, 5).join(' | '));
t('keine Markdown-Reste', artefacts.length === 0, artefacts.slice(0, 5).join(' · '));

// ── 2. Anwendungsfälle: jeder Kurztext springt in seinen Abschnitt (DE und EN) ──
const PAGE_OF = { useCases: 'anleitungen/anwendungsfaelle.md', setup: 'einstieg/einrichtung.md' };
const caseMiss = [];
for (const c of CASES) {
  for (const prefix of ['', 'en/']) {
    const key = prefix + PAGE_OF[c.page];
    if (!rendered[key]?.ids.has(c.anchor)) caseMiss.push(`${key}#${c.anchor}`);
  }
}
t('Anwendungsfälle: Anker in DE und EN', caseMiss.length === 0 && CASES.length >= 8, caseMiss.join(' · ') || `${CASES.length} Fälle`);
const caseEl = renderMarkdown('<a id="case-heat"></a>\n## F — Fernwärme', {}).html;
t('Sprungmarke vor einer Überschrift ohne leeren Absatz', /^<a id="d-case-heat"><\/a>\n<h2 id="d-f--fernwärme">/.test(caseEl), caseEl.slice(0, 80).replace(/\n/g, ' '));

// ── 3. Angriffs-Payloads — der Renderer allein (Linkregeln, die alles durchlassen) ──
const permissive = { link: (href) => ({ href }), image: (src) => src };
const PAYLOADS = [
  '<script>alert(1)</script>',
  '<img src=x onerror=alert(1)>',
  '<img src="ok.png" onerror="alert(1)" width="100px">',
  '[x](javascript:alert(1))',
  '[x](JaVaScRiPt:alert(1))',
  '[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
  '[x](vbscript:msgbox)',
  '![x](javascript:alert(1))',
  '![x](//evil.example/x.png)',
  '![x](https://evil.example/x.png)',
  '![x](data:image/svg+xml;base64,PHN2Zy8+)',
  '![x](\\\\evil.example\\x.png)',
  '<a href="javascript:alert(1)">x</a>',
  '<a id="x" onclick="alert(1)"></a>',
  '<a id="X&quot; onmouseover=&quot;alert(1)"></a>',
  '<a name="ok-1"></a> <a id="Großes"></a>',
  '<iframe src="https://evil.example"></iframe>',
  '<svg onload=alert(1)><circle/></svg>',
  '<style>body{display:none}</style>',
  '<form action="https://evil.example"><input name="pw"></form>',
  '[x](https://ok"onmouseover="alert(1))',
  '[x](https://ok" onmouseover="alert(1))',
  '<https://x" onmouseover="alert(1)>',
  '<javascript:alert(1)>',
  '# Titel <img src="x.png" onerror="alert(1)">',
  '| <script>x</script> | <img src=x onerror=y> |\n|---|---|\n| [a](javascript:b) | ![c](javascript:d) |',
  '`<script>alert(1)</script>`',
  '```html\n</code></pre><script>alert(1)</script>\n```',
  '**<script>alert(1)</script>**',
  '[<img src="a.png" onerror="x">](https://ok)',
  '<p onclick="alert(1)">x</p>',
  'Text <!-- <script>alert(1)</script> --> weiter',
  '> <script>alert(1)</script>\n> [x](javascript:y)',
  '- [x](javascript:y)\n  - <img src=x onerror=y>',
  '[x](java\tscript:alert(1))',
  '[x](#/help/doc/a.md" onclick="y)',
  '<a id="case-ok"></a>',
  '&lt;script&gt;alert(1)&lt;/script&gt;',
];
const leaks = [];
for (const p of PAYLOADS) {
  const { html } = renderMarkdown(p, permissive);
  const { bad } = unsafe(html, { relativeOnly: false });
  if (bad.length) leaks.push(`${JSON.stringify(p).slice(0, 50)} → ${bad.join(', ')}`);
}
t(`Angriffs-Payloads entschärft (${PAYLOADS.length}, Renderer allein)`, leaks.length === 0, leaks.slice(0, 4).join(' | '));
const imgOut = renderMarkdown('<img src="ok.png" onerror="alert(1)" width="560">', permissive).html;
t('rohes <img> neu gebaut: nur src, alt, width', /^<p><img src="ok\.png" alt="" width="560" loading="lazy"><\/p>/.test(imgOut), imgOut.trim());
const anchorOut = renderMarkdown('<a id="x" onclick="alert(1)"></a> <a id="case-ok"></a>', permissive).html;
const oddIds = renderMarkdown('<a id="Gross"></a> <a id="a&quot;b"></a> <a name="ü"></a>', permissive).html;
t('<a id> nur mit [a-z0-9-], sonst Text', anchorOut.includes('&lt;a id=&quot;x&quot; onclick') && anchorOut.includes('<a id="d-case-ok"></a>')
  && !/<a [^>]*id=/.test(oddIds), `${anchorOut.trim()} | ${oddIds.trim()}`);
const extOut = renderMarkdown('[x](https://example.org)', { link: (href) => ({ href, external: false }) }).html;
t('extern entscheidet das Ziel, nicht die Auflösung', extOut.includes('target="_blank" rel="noopener noreferrer"'), extOut.trim());
const escOut = renderMarkdown('5 < 6 & "a" \'b\' > 4', permissive).html;
t('Text wird escaped', escOut.includes('5 &lt; 6 &amp; &quot;a&quot; &#39;b&#39; &gt; 4'), escOut.trim());
const idOut = renderMarkdown('## Übersicht\n## Übersicht', permissive);
t('Überschriften: Präfix d-, doppelte zählen weiter', idOut.html.includes('id="d-übersicht"') && idOut.html.includes('id="d-übersicht-1"')
  && idOut.ids.join(',') === 'übersicht,übersicht-1', idOut.ids.join(','));
t('Anker nach GitHubs Regel', slug('F — Fernwärme oder Heizwärme?') === 'f--fernwärme-oder-heizwärme'
  && slug('Use-Case C — Wärmepumpe mit `Code` und [Link](x.md)') === 'use-case-c--wärmepumpe-mit-code-und-link');

// ── 4. Angriffs-Payloads — die Linkregeln allein ──
const rules = docLinks('anleitungen/kalender.md', { rootFiles: allow.root_files, version: VERSION, assetUrl });
const ruleCases = [
  ['javascript:alert(1)', null], ['JAVASCRIPT:alert(1)', null], ['data:text/html,x', null], ['//evil.example/x', null],
  ['\\\\evil.example\\x', null], ['../../../../etc/passwd', null], ['vbscript:x', null],
  ['https://example.org/a', 'https://example.org/a'], ['mailto:a@example.org', 'mailto:a@example.org'],
  ['#abschnitt', '#/help/doc/anleitungen/kalender.md?h=abschnitt'],
  ['../einstieg/faq.md#frage', '#/help/doc/einstieg/faq.md?h=frage'],
  ['../../CHANGELOG.md', '#/help/doc/root/CHANGELOG.md'],
  ['../../roadmap.md', `https://github.com/Bingerminger/energietracker/tree/v${VERSION}/roadmap.md`],
];
const ruleBad = ruleCases.filter(([href, want]) => (rules.link(href)?.href ?? null) !== want)
  .map(([href, want]) => `${href} → ${rules.link(href)?.href ?? null} (erwartet ${want})`);
t('Linkregeln: nur erlaubte Ziele, Pfade bleiben im Repository', ruleBad.length === 0, ruleBad.join(' · '));
const imgBad = [['https://evil.example/x.png', null], ['javascript:x', null], ['//evil.example/x.png', null],
  ['../../README.md', null], ['../../../x.png', null],
  ['../ui/screenshots/dashboard.png', assetUrl('ui/screenshots/dashboard.png')]]
  .filter(([src, want]) => rules.image(src) !== want).map(([src]) => `${src} → ${rules.image(src)}`);
t('Bildregeln: nur Bilder unter docs/ über den Endpunkt', imgBad.length === 0, imgBad.join(' · '));

// ── 5. Offline: der Service Worker hält die Doku vor ──
const sw = readFileSync(`${ROOT}/sw.js`, 'utf8');
const m = sw.match(/const CACHEABLE_API = \/(.+)\/;/);
const re = m ? new RegExp(m[1]) : null;
t('Service Worker cacht /api/docs und /api/docs/asset', !!re && re.test('docs') && re.test('docs/asset') && !re.test('backup/export'));

console.log(`\n  docs: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
