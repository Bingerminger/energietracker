// Bilder und Schriften der Projektseite aus dem Haupt-Repo holen.
//
//   NODE_PATH=<Ordner mit playwright> node tools/bilder.mjs     (ET_REPO=../energietracker)
//
// Liest nur. Schneidet die Aufnahmen der Oberfläche (docs/ui/screenshots,
// docs/assets/promo/screens) zu und schreibt sie als WebP nach assets/img/screens/.
// Kodiert wird im Browser (Canvas → image/webp), damit kein weiteres Werkzeug nötig ist.
// Gibt es eine dunkle Aufnahme, entsteht daneben <name>-dunkel.<lang>.webp; die Seite
// zeigt sie im dunklen Thema. Die Schaubilder des Wegweisers folgen in der App dem
// System; hier entstehen je eine helle und eine dunkle Fassung, damit sie auch dem
// Umschalter der Seite folgen. Dazu Logo, Favicon, Vorschaubild für Links (die
// Social Preview aus M6) und die Schriften DM Sans und DM Mono (SIL OFL 1.1).
// Woher jedes Bild kommt, steht danach in assets/img/quellen.json.
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, copyFileSync, mkdirSync, rmSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const ET = resolve(process.env.ET_REPO || join(ROOT, '../energietracker'));
const require = createRequire(`${process.env.NODE_PATH || process.cwd()}/x`);

const SHOTS = 'docs/ui/screenshots';
const PROMO = 'docs/assets/promo/screens';
const en = (p) => p.replace(`${SHOTS}/`, `${SHOTS}/en/`);

/**
 * name, Quelle je Sprache (de, sonst en aus docs/ui/screenshots/en/), dunkle Quelle je Sprache
 * (dunkelDe/dunkelEn, falls es eine gibt), Ausschnitt [x, y, b, h] in Pixeln der Aufnahme, Zielbreite.
 * Ausschnitte meiden Sparbeträge der erfundenen Beispiele (Auftrag: keine Sparbeträge).
 */
const JOBS = [
  { name: 'uebersicht', de: `${PROMO}/uebersicht.de.png`, en: `${PROMO}/uebersicht.en.png`,
    dunkelDe: `${PROMO}/uebersicht-dunkel.de.png`, dunkelEn: `${PROMO}/uebersicht-dunkel.en.png`, width: 1440 },
  { name: 'iphone', de: `${PROMO}/iphone.de.png`, en: `${PROMO}/iphone.en.png`,
    dunkelDe: `${PROMO}/iphone-dunkel.de.png`, dunkelEn: `${PROMO}/iphone-dunkel.en.png`, width: 780 },
  { name: 'gas', de: `${SHOTS}/gas-view.png`, crop: [220, 48, 1220, 762], dunkel: 'gas-view' },
  { name: 'rechnung', de: 'tools/quellen/rechnung.de.png', en: 'tools/quellen/rechnung.en.png', local: true, crop: [220, 48, 1220, 762], dunkel: 'rechnung' },
  { name: 'wechsel', de: `${SHOTS}/tarifvergleich.png`, crop: [220, 48, 1220, 490], dunkel: 'tarifvergleich' },
  { name: 'zutun', de: `${SHOTS}/zu-tun.png`, crop: [220, 48, 1220, 762], dunkel: 'zu-tun' },
  { name: 'analyse', de: `${SHOTS}/analyse.png`, crop: [220, 500, 1220, 762], dunkel: 'analyse' },
  { name: 'prognose', de: `${SHOTS}/prognose.png`, crop: [624, 836, 792, 664], dunkel: 'prognose' },
  { name: 'werkstatt', de: `${SHOTS}/prognose.png`, crop: [220, 48, 1220, 762], dunkel: 'prognose' },
  { name: 'jahresbericht', de: `${SHOTS}/jahresbericht.png`, crop: [220, 48, 890, 740], dunkel: 'jahresbericht' },
  { name: 'kennzahlen', de: `${SHOTS}/kennzahlen.png`, crop: [220, 48, 1220, 740], dunkel: 'kennzahlen' },
  { name: 'miete', de: 'tools/quellen/miete.de.png', en: 'tools/quellen/miete.en.png', local: true, crop: [220, 48, 1220, 780], dunkel: 'miete' },
  { name: 'brief', de: `${SHOTS}/brief-dialog.png`, crop: [235, 40, 970, 810], dunkel: 'brief-dialog' },
  { name: 'erfassen', de: `${SHOTS}/erfassen-iphone.png`, width: 780, dunkel: 'erfassen-iphone' },
];
// Dunkle Fassungen der übrigen Ansichten (nur für die Projektseite, nicht im Haupt-Repo):
// tools/quellen/dunkel/<name>.<lang>.png, aufgenommen mit
//   node tools/build-screenshots.mjs <basis-url> <de|en> <ordner> <namen> --dunkel   (Haupt-Repo, Beispielhaushalt)
//   node tools/aufnahme.mjs <port> <de|en> '#/bill-check' tools/quellen/dunkel/rechnung.<lang>.png 1440 1100 dark
//   node tools/aufnahme.mjs <port> <de|en> '#/tenancy' tools/quellen/dunkel/miete.<lang>.png 1440 1100 dark   (Mieterhaushalt)
// Gleiche Fenstergröße wie die helle Aufnahme, damit derselbe Ausschnitt passt.
const DUNKEL = 'tools/quellen/dunkel';

const WEGWEISER = ['together', 'home-assistant'];
const FONTS = ['dm-sans.woff2', 'dm-sans-ziffern.woff2', 'dm-mono-400.woff2', 'OFL.txt'];
// Vorschaubild für Links: die freigegebene Social Preview aus M6, als Kopie in tools/quellen/
const VORSCHAU = 'tools/quellen';

const commit = execFileSync('git', ['-C', ET, 'rev-parse', '--short', 'HEAD'], { encoding: 'utf8' }).trim();
const out = (...p) => join(ROOT, ...p);
for (const d of ['assets/img/screens', 'assets/img/wegweiser']) {
  rmSync(out(d), { recursive: true, force: true });
  mkdirSync(out(d), { recursive: true });
}
mkdirSync(out('assets/fonts'), { recursive: true });

const { chromium } = require('playwright');
const browser = await chromium.launch();
const page = await browser.newPage();
await page.setContent('<!doctype html><body></body>');

const manifest = { commit, bilder: [] };
async function zuschneiden(file, crop, width, target, quelle) {
  const dataUrl = `data:image/png;base64,${readFileSync(file).toString('base64')}`;
  const res = await page.evaluate(async ({ dataUrl, crop, width }) => {
    const img = new Image();
    img.src = dataUrl;
    await img.decode();
    const [x, y, w, h] = crop || [0, 0, img.naturalWidth, img.naturalHeight];
    const tw = width || w;
    const th = Math.round(h * tw / w);
    const c = document.createElement('canvas');
    c.width = tw; c.height = th;
    const g = c.getContext('2d');
    g.imageSmoothingQuality = 'high';
    g.drawImage(img, x, y, w, h, 0, 0, tw, th);
    return { url: c.toDataURL('image/webp', 0.84), w: tw, h: th };
  }, { dataUrl, crop, width });
  writeFileSync(out(target), Buffer.from(res.url.split(',')[1], 'base64'));
  manifest.bilder.push({ datei: target, quelle, ausschnitt: crop || null, breite: res.w, hoehe: res.h });
}
for (const job of JOBS) {
  for (const lang of ['de', 'en']) {
    const rel = job[lang] || en(job.de);
    const crop = (lang === 'en' && job.cropEn) || job.crop;
    const quelle = (r) => (job.local ? r : `energietracker/${r}`);
    await zuschneiden(job.local ? out(rel) : join(ET, rel), crop, job.width, `assets/img/screens/${job.name}.${lang}.webp`, quelle(rel));
    const dunkel = job[lang === 'de' ? 'dunkelDe' : 'dunkelEn'];
    if (dunkel && existsSync(join(ET, dunkel))) {
      await zuschneiden(join(ET, dunkel), crop, job.width, `assets/img/screens/${job.name}-dunkel.${lang}.webp`, quelle(dunkel));
    } else if (job.dunkel) {
      const d = `${DUNKEL}/${job.dunkel}.${lang}.png`;
      if (existsSync(out(d))) await zuschneiden(out(d), crop, job.width, `assets/img/screens/${job.name}-dunkel.${lang}.webp`, d);
      else console.warn(`ohne dunkle Fassung: ${job.name} (${lang}), fehlt ${d}`);
    }
  }
}
await browser.close();

// Schaubilder: helle Fassung ohne den Block für das dunkle System, dunkle mit ihm als Grundwert
const DARK = /@media \(prefers-color-scheme:dark\)\{(:root\{[^}]*\})\}/;
for (const name of WEGWEISER) {
  for (const lang of ['de', 'en']) {
    for (const v of ['', '.narrow']) {
      const f = `${name}.${lang}${v}`;
      const svg = readFileSync(join(ET, 'docs/ui/wegweiser', `${f}.svg`), 'utf8');
      const m = svg.match(DARK);
      if (!m) throw new Error(`${f}.svg: Block für das dunkle Thema nicht gefunden`);
      writeFileSync(out('assets/img/wegweiser', `${f}.hell.svg`), svg.replace(DARK, ''));
      writeFileSync(out('assets/img/wegweiser', `${f}.dunkel.svg`), svg.replace(DARK, m[1]));
      for (const t of ['hell', 'dunkel']) manifest.bilder.push({ datei: `assets/img/wegweiser/${f}.${t}.svg`, quelle: `energietracker/docs/ui/wegweiser/${f}.svg (${t})` });
    }
  }
}
for (const lang of ['de', 'en']) {
  copyFileSync(out(VORSCHAU, `vorschau.${lang}.png`), out(`assets/img/vorschau.${lang}.png`));
  manifest.bilder.push({ datei: `assets/img/vorschau.${lang}.png`, quelle: `${VORSCHAU}/vorschau.${lang}.png (Social Preview aus M6)`, breite: 1280, hoehe: 640 });
}
for (const f of FONTS) copyFileSync(join(ET, 'public/vendor/fonts', f), out('assets/fonts', f));
copyFileSync(join(ET, 'docs/assets/promo/logo.svg'), out('assets/img/logo.svg'));
// Die Grafiken der App, unverändert: die Seite rendert daraus ihr eigenes Sprite (src/seiten.mjs).
copyFileSync(join(ET, 'public/js/lib/icons.js'), out('src/icons-app.mjs'));
copyFileSync(join(ET, 'public/favicon.ico'), out('favicon.ico'));
copyFileSync(join(ET, 'public/img/icon-light-180.png'), out('assets/img/apple-touch-icon.png'));

writeFileSync(out('assets/img/quellen.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`${manifest.bilder.length} Bilder aus energietracker@${commit}`);
