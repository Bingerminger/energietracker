// v3.12.0 (Paket wegweiser) — Die Wege im Wegweiser: Bilder, Kurzauswahl, Links und Wortwahl in allen Sprachen.
//
//   node tests/setup-guide.test.mjs
//
// lib/setup-scenes.js ist rein und läuft hier ohne Browser, mit den echten Katalogen. Ob die Bilder der
// Doku aktuell sind, prüft `node tools/build-setup-guide.mjs --check` (CI); hier nur, dass sie sicher sind.
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const S = await import(`${ROOT}/public/js/lib/setup-scenes.js`);
const { docPagePath, DOC_PATHS } = await import(`${ROOT}/public/js/lib/docs.js`);
const { slug } = await import(`${ROOT}/public/js/lib/markdown.js`);
const { wrapText } = await import(`${ROOT}/public/js/lib/illustrations.js`);

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}`); } else { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const LANGS = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];
const catalogs = Object.fromEntries(LANGS.map(l => [l, JSON.parse(readFileSync(`${ROOT}/public/locales/${l}.json`, 'utf8'))]));
const translator = (lang) => {
  const missing = [];
  const tr = (key, params) => {
    let str = key.split('.').reduce((a, k) => a?.[k], catalogs[lang]);
    if (typeof str !== 'string') { missing.push(key); return key; }
    for (const [k, v] of Object.entries(params || {})) str = str.replaceAll(`{${k}}`, String(v));
    return str;
  };
  tr.missing = missing;
  return tr;
};
const flat = (o, pre = '') => Object.entries(o).flatMap(([k, v]) => (v && typeof v === 'object' ? flat(v, `${pre}${k}.`) : [[`${pre}${k}`, v]]));
const textOf = (svg) => [...svg.matchAll(/<tspan[^>]*>([^<]*)<\/tspan>/g)].map(m => m[1]).join(' ');

// ── Bilder in allen Sprachen ─────────────────────────────────────────
console.log('Bilder');
t('sechs Wege', S.SETUP_IDS.length === 6 && S.SETUP_IDS.every(id => S.SETUPS[id]));
for (const lang of LANGS) {
  const tr = translator(lang);
  const all = [];
  for (const id of S.SETUP_IDS) for (const variant of ['wide', 'narrow']) for (const detail of [false, true]) {
    all.push({ id, variant, detail, svg: S.sceneSvg(id, { variant, detail, tr }) });
  }
  t(`${lang}: alle Bilder ohne fehlenden Schlüssel`, tr.missing.length === 0, [...new Set(tr.missing)].join(' '));
  t(`${lang}: kein Rechenfehler im Bild`, all.every(b => !/NaN|undefined/.test(b.svg)), all.filter(b => /NaN|undefined/.test(b.svg)).map(b => b.id).join());
  t(`${lang}: Hausgrenze in jedem Bild`, all.every(b => /class="ill-boundary"/.test(b.svg)));
  t(`${lang}: jedes Bild benannt`, all.every(b => /role="img"/.test(b.svg) && /<title>[^<]+<\/title>/.test(b.svg)));
  t(`${lang}: Wetterdienst in jedem Bild, sein Pfeil kreuzt die Hausgrenze`, all.every(b => textOf(b.svg).includes(tr('setups.node.weather')) && /ill-flow--out/.test(b.svg)));
  t(`${lang}: schmale Fassung in 320 breit, breite in 760`, all.every(b => (b.variant === 'wide' ? /viewBox="0 0 760 /.test(b.svg) : /viewBox="0 0 320 /.test(b.svg))));
  t(`${lang}: Ports nur im Detail`, all.filter(b => !b.detail).every(b => !/class="ill-port"/.test(b.svg)) && all.filter(b => b.detail).every(b => /class="ill-port"/.test(b.svg)));
  // Zeilenlänge: lange Wörter passen in ihren Kasten (geschätzt; die Sichtprüfung misst es im Browser)
  const longest = flat(catalogs[lang].setups.node).map(([, v]) => String(v)).flatMap(v => v.split(/\s+/)).sort((a, b) => b.length - a.length)[0];
  t(`${lang}: längstes Wort im Kasten passt in die schmalste Karte`, longest.length * 12.5 * 0.54 <= 116, longest);
}
t('Umbruch wie im Bild: nichts geht verloren', wrapText('a b c d', 8, 11).join(' ') === 'a b c d');
const tDe = translator('de');
t('Beispiele nur im Detail: Im Bild steht allgemein „KI-Programm“', ['wide', 'narrow'].flatMap(variant => [false, true].map(detail => S.sceneSvg('cloud-ai', { variant, detail, tr: tDe })))
  .every(svg => textOf(svg).includes('KI-Programm') && !/Claude|Cursor|VS Code|ChatGPT|Copilot/.test(textOf(svg))));
const cloudKeys = flat(catalogs.de.setups.scene.cloudAi);
t('… Programmnamen stehen nur in „detailIntro“ und sind experimentell', cloudKeys.filter(([k, v]) => /Claude|Cursor|VS Code/.test(String(v))).map(([k]) => k).join() === 'detailIntro'
  && /experimentell/.test(catalogs.de.setups.scene.cloudAi.detailIntro));
t('Weg in der Cloud heißt in jeder Sprache experimentell', LANGS.every(l => /experiment|expérimental|sperimental/i.test(catalogs[l].setups.scene.cloudAi.title)));
t('Lokal: geprüft ist nur Open WebUI mit Ollama, der Rest experimentell', /Open WebUI mit lokalem Ollama/.test(catalogs.de.setups.scene.localAi.software) && /experimentell/.test(catalogs.de.setups.scene.localAi.software));
t('Der Hub stimmt: Das Modell spricht nie mit dem Energietracker', (() => {
  const { inside } = S.sceneFlows('local-ai', tDe);
  return !inside.some(f => (f.from === 'Ollama' && f.to === 'Energietracker') || (f.from === 'Energietracker' && f.to === 'Ollama'))
    && inside.some(f => f.from === 'Open WebUI' && f.to === 'Energietracker');
})());
for (const id of S.SETUP_IDS) {
  const { inside, across } = S.sceneFlows(id, tDe);
  t(`${id}: Pfeile im Haus und über die Grenze`, inside.length > 0 && across.length > 0 && across.some(f => f.to === tDe('setups.node.weather')));
}
t('Mit Home Assistant: Stände kommen aus Home Assistant, nicht aus dem Zähler direkt', S.sceneFlows('home-assistant', tDe).inside.some(f => f.from === 'Home Assistant' && f.to === 'Energietracker')
  && !S.sceneFlows('home-assistant', tDe).inside.some(f => f.from === tDe('setups.node.smartMeters') && f.to === 'Energietracker'));
t('Mit Post: ohne Zustimmung nichts in die Cloud (Text des Pfeils)', /Zustimmung/.test(tDe('setups.flow.toCloud')));

// ── Kurzauswahl ──────────────────────────────────────────────────────
console.log('Kurzauswahl');
const pick = (a) => S.setupsFor(a).join();
t('ohne Antwort kein Weg (keine Frage ist Pflicht)', pick({}) === '' && pick(undefined) === '' && pick({ smart: '' }) === '');
t('alles nein führt zu „Von Hand“', pick({ smart: 'no', post: 'no', ai: 'no' }) === 'by-hand' && pick({ smart: 'no' }) === 'by-hand');
t('smarte Zähler → Home Assistant', pick({ smart: 'yes' }) === 'home-assistant');
t('Post → Mit Versorgerpost', pick({ post: 'yes' }) === 'inbox');
t('KI im Haus / darf hinaus', pick({ ai: 'home' }) === 'local-ai' && pick({ ai: 'out' }) === 'cloud-ai');
t('zwei Bausteine auch „Alles zusammen“', pick({ smart: 'yes', ai: 'home' }) === 'home-assistant,local-ai,together');
t('ein Baustein ohne „Alles zusammen“', !pick({ post: 'yes', smart: 'no', ai: 'no' }).includes('together'));
t('„Von Hand“ nie neben einem anderen Weg (kein Rang)', !pick({ smart: 'yes', post: 'yes', ai: 'out' }).includes('by-hand'));
t('unbekannte Antwort zählt nicht', pick({ ai: 'irgendwas' }) === '');
t('Fragen in jeder Sprache', LANGS.every(l => { const tr = translator(l); S.questionsHtml({}, tr); return tr.missing.length === 0; }));
t('nichts vorbelegt', !/checked/.test(S.questionsHtml({}, tDe)));
t('Antworten kommen wieder', /value="home" checked/.test(S.questionsHtml({ ai: 'home' }, tDe)));

// ── Texte je Weg ─────────────────────────────────────────────────────
console.log('Texte');
for (const lang of LANGS) {
  const tr = translator(lang);
  const labels = S.setupLabels(tr);
  for (const id of S.SETUP_IDS) {
    const s = S.SETUPS[id];
    const k = `setups.scene.${s.key}`;
    const keys = ['title', 'teaser', 'forWhom', 'software', 'hardware', 'effort', 'data', 'detailIntro',
      ...Array.from({ length: s.get }, (_, i) => `get${i + 1}`), ...Array.from({ length: s.limits }, (_, i) => `limit${i + 1}`),
      ...s.steps.map((_, i) => `step${i + 1}`), ...Array.from({ length: s.detail.notes }, (_, i) => `note${i + 1}`),
      ...Array.from({ length: s.detail.errors }, (_, i) => `err${i + 1}.symptom`), ...Array.from({ length: s.detail.errors }, (_, i) => `err${i + 1}.fix`),
      ...s.detail.rows.flatMap(r => [`row.${r.id}.label`, `row.${r.id}.note`]), ...(s.detail.code ? ['codeTitle'] : [])];
    const open = keys.filter(key => /\{[a-zA-Z]+\}/.test(tr(`${k}.${key}`, labels)));
    t(`${lang} ${id}: alle Texte da, kein Platzhalter offen`, tr.missing.length === 0 && open.length === 0, [...tr.missing, ...open].join(' '));
    tr.missing.length = 0;
    // Der Katalog hat nichts, was der Code nicht braucht
    if (lang === 'de') {
      const used = new Set(keys);
      const extra = flat(catalogs.de.setups.scene[s.key]).map(([key]) => key).filter(key => !used.has(key));
      t(`${id}: kein Text ohne Stelle`, extra.length === 0, extra.join(' '));
    }
  }
}
t('Bezeichnungen aus der App: auch „Übernahme nur in der App bestätigen“', S.setupLabels(tDe).applyInApp === catalogs.de.settings.mcp.applyInApp
  && tDe('setups.scene.localAi.note4', S.setupLabels(tDe)).includes(catalogs.de.settings.mcp.applyInApp) && !/Übernahme nur in der App/.test(catalogs.de.setups.scene.localAi.note4));
t('… Wegweiser nennt den Ort: Zu tun', tDe('setups.scene.localAi.note4', S.setupLabels(tDe)).includes(catalogs.de.nav.todo));

const words = flat(catalogs.de.setups).map(([, v]) => String(v)).join(' ');
t('Bausteine, keine Stufenleiter: keine Rangwörter', !/Grundstufe|Ausbaustufe|Ausbau\b|Einstiegsstufe|Profi-Weg|fortgeschritten/i.test(words), (words.match(/Grundstufe|Ausbaustufe|Ausbau\b|fortgeschritten/i) || [''])[0]);
t('… auch im Englischen', !/\bbasic\b|\bupgrade\b|\badvanced\b|\bbeginner\b/i.test(flat(catalogs.en.setups).map(([, v]) => String(v)).join(' ')));
t('Wegweiser, „Weg“ statt „Szenario“', catalogs.de.setups.title === 'Wegweiser' && catalogs.en.setups.title === 'Setup guide' && !/Szenario/i.test(words));
t('Keine Rechnerzahlen und keine Laufzeiten im Wegweiser', !/\b\d+\s?(GB|Sekunden|Minuten pro|s je)\b/.test(words.replace(/15 Minuten|10 MB/g, '')));
t('Compose-Datei nicht als erprobt bezeichnet', !/erprobt|getestet|geprüft/.test(catalogs.de.setups.scene.localAi.codeTitle));
t('Kein Menüpfad von Open WebUI im Wegweiser (er verlinkt nur)', !/Admin Panel|External Tool|Settings > Admin/.test(words));

// Nur Platzhalter, nie ein echtes Heimnetz
const everything = [JSON.stringify(flat(catalogs.de.setups)), JSON.stringify(S.SETUPS), readFileSync(`${ROOT}/public/js/lib/setup-scenes.js`, 'utf8')].join('\n');
// 0.0.0.0 ist keine Adresse eines Heimnetzes, sondern der Wert für „alle Schnittstellen“ (OLLAMA_HOST)
const ips = (everything.match(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g) || []).filter(ip => ip !== '0.0.0.0');
t('Keine IP-Adresse als Beispiel', ips.length === 0, ips.join(' '));
const addrs = S.SETUP_IDS.flatMap(id => S.SETUPS[id].detail.rows.map(r => r.addr));
t('Adressen nur mit Platzhaltern', addrs.every(a => /^https?:\/\/(energietracker\.local|mein-rechner\.local|homeassistant\.local|host\.docker\.internal|energie\.example\.org)\b/.test(a)), addrs.join(' '));

// ── Links ────────────────────────────────────────────────────────────
console.log('Links');
const ALL_LINKS = S.SETUP_IDS.flatMap(id => {
  const s = S.SETUPS[id];
  return [...s.steps.flat(), ...(s.getLinks || []), ...s.detail.sources].map(l => ({ ...l, way: id }));
});
const routes = [...readFileSync(`${ROOT}/public/js/router.js`, 'utf8').matchAll(/^\s*\[\/(.+)\/,\s+'[a-z-]+'\],/gm)].map(m => new RegExp(m[1]));
t('Router gelesen', routes.length > 20, `${routes.length}`);
t('Wegweiser hat seine Routen', routes.some(r => r.test('/help/setups')) && S.SETUP_IDS.every(id => routes.some(r => r.test(`/help/setups/${id}`))));
const anchorsOf = (rel) => {
  const md = readFileSync(`${ROOT}/${rel}`, 'utf8');
  const out = new Set([...md.matchAll(/<a id="([^"]+)"><\/a>/g)].map(m => m[1]));
  let fence = false;
  for (const line of md.split('\n')) {
    if (line.startsWith('```')) fence = !fence;
    const m = !fence && line.match(/^#{1,6}\s+(.*)$/);
    if (m) out.add(slug(m[1].trim()));
  }
  return out;
};
const bad = [];
for (const l of ALL_LINKS) {
  if (!l.label || !catalogs.de && !l.label) bad.push(`${l.way}: ohne Text`);
  if (l.app && !routes.some(r => r.test(l.app.slice(1)))) bad.push(`${l.way}: Route ${l.app}`);
  if (l.scene && !S.SETUP_IDS.includes(l.scene)) bad.push(`${l.way}: Weg ${l.scene}`);
  if (l.doc) {
    for (const lang of ['de', 'en']) {
      const rel = docPagePath(l.doc, lang);
      if (!DOC_PATHS.includes(rel) || !existsSync(`${ROOT}/${rel}`)) { bad.push(`${l.way}: Seite ${rel}`); continue; }
      const a = S.anchorFor(l.anchor, lang);
      if (a && !anchorsOf(rel).has(a)) bad.push(`${l.way}: ${rel}#${a}`);
    }
  }
  if (l.url && !/^https:\/\//.test(l.url)) bad.push(`${l.way}: ${l.url}`);
}
t(`alle ${ALL_LINKS.length} Links führen auf etwas, das es gibt (Seite, Sprungmarke in DE und EN, Route, Weg)`, bad.length === 0, bad.join(' | '));
t('Sprünge in die Anleitung zur KI über die festen Marken', ALL_LINKS.some(l => l.anchor === 'mehrwert') && ALL_LINKS.some(l => l.anchor === 'wer-bestaetigt'));
for (const lang of LANGS) {
  const tr = translator(lang);
  ALL_LINKS.forEach(l => tr(l.label));
  t(`${lang}: jeder Link hat seinen Text`, tr.missing.length === 0, [...new Set(tr.missing)].join(' '));
}

// ── Bilder der Doku ──────────────────────────────────────────────────
console.log('Bilder der Doku');
const dir = `${ROOT}/docs/ui/wegweiser`;
const svgs = readdirSync(dir).filter(f => f.endsWith('.svg'));
t('je Weg und Sprache breit und schmal', svgs.length === S.SETUP_IDS.length * 2 * 2 && svgs.every(f => /^[a-z-]+\.(de|en)(\.narrow)?\.svg$/.test(f)), `${svgs.length}`);
const unsafe = svgs.filter(f => /<script|\son[a-z]+=|href=|<image|url\(|@import|<foreignObject/i.test(readFileSync(`${dir}/${f}`, 'utf8')));
t('die Dateien tun nichts außer zeichnen (kein Skript, kein Verweis nach außen)', unsafe.length === 0, unsafe.join(' '));
const sample = readFileSync(`${dir}/local-ai.de.svg`, 'utf8');
t('bringen hell und dunkel und einen deckenden Hintergrund mit', /prefers-color-scheme:dark/.test(sample) && /class="ill-bg"/.test(sample) && /--ill-line:/.test(sample));
t('tragen ihre Maße, sonst zeichnet <img> sie zu klein', /^<svg width="760" height="\d+"/.test(sample));

// Doku-Seite: zeigt den Weg, die Schritte stehen in den Anleitungen
for (const lang of ['de', 'en']) {
  const page = readFileSync(`${ROOT}/${docPagePath('guide', lang)}`, 'utf8');
  t(`${lang}: Seite mit allen Wegen und Bildern`, S.SETUP_IDS.every(id => page.includes(`<a id="weg-${id}"></a>`) && page.includes(`${id}.${lang}.svg`) && page.includes(`${id}.${lang}.narrow.svg`)));
  t(`${lang}: als erzeugt gekennzeichnet`, page.includes('tools/build-setup-guide.mjs'));
  t(`${lang}: Schritte verweisen auf Anleitungen`, /\.\.\/.*anleitungen\/ki-assistent\.md/.test(page) && /\.\.\/.*anleitungen\/home-assistant\.md/.test(page) && /\.\.\/.*anleitungen\/posteingang\.md/.test(page));
}

// Woran die App und die CI hängen
const ci = readFileSync(`${ROOT}/.github/workflows/ci.yml`, 'utf8');
t('CI: Suite und Prüfung der Bilder', ci.includes('node tests/setup-guide.test.mjs') && ci.includes('node tools/build-setup-guide.mjs --check'));
const css = readFileSync(`${ROOT}/public/css/components.css`, 'utf8');
t('Schmale Fassung per Container-Abfrage, kein Querscrollen im Code', /@container \(max-width: 46rem\) \{\s*\.guide-scene__wide \{ display: none; \}\s*\.guide-scene__narrow \{ display: block; \}/.test(css)
  && /\.guide-code \{ white-space: pre-wrap;/.test(css) && !/\.guide-link \{[^}]*nowrap/.test(css));

console.log(`\n  setup-guide: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
