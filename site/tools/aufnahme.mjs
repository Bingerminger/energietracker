// Aufnahme einer Ansicht der laufenden App für die Projektseite.
//
// Nur für Bilder, die es im Haupt-Repo noch nicht gibt (heute: Mietverhältnis).
// Die App läuft dafür mit einem eigenen, leeren Datenverzeichnis und dem
// Beispielhaushalt „Ich wohne zur Miete“ (erfundene Daten):
//
//   ET_DATA_DIR=/tmp/et-miete php -S 127.0.0.1:19901 router.php      (im Haupt-Repo)
//   A=http://127.0.0.1:19901/api.php/api
//   curl -s -X PATCH -H 'Content-Type: application/json' --data '{"language":"de"}' $A/settings
//   curl -s -X POST -H 'Content-Type: application/json' -H 'X-ET-Language: de' \
//     --data '{"force":true,"persona":"mieterin"}' $A/demo/import
//   curl -s -X PATCH -H 'Content-Type: application/json' \
//     --data '{"ui_level":"expert","weather_auto_fill":false}' $A/settings
//
//   node tools/aufnahme.mjs 19901 de '#/tenancy' tools/quellen/miete.de.png 1440 1100
//
// Für Englisch eine zweite Instanz mit eigenem Verzeichnis und "en". Danach
// den Server beenden. Braucht Playwright (nur auf dem Rechner des Maintainers).
import { createRequire } from 'node:module';

const require = createRequire(`${process.env.NODE_PATH || process.cwd()}/x`);
const { chromium } = require('playwright');

const [port, lang, route, out, w = '1440', h = '900', theme = 'light'] = process.argv.slice(2);
if (!out) {
  console.error('node tools/aufnahme.mjs <port> <de|en> <route> <ziel.png> [breite] [höhe] [light|dark]');
  process.exit(2);
}
// Datumsfelder formatiert der Browser selbst: Region des Browsers passend zur Sprache (docs/entwicklung/screenshots.md)
const browser = await chromium.launch({ args: [`--lang=${lang === 'en' ? 'en-GB' : 'de-DE'}`] });
const ctx = await browser.newContext({
  viewport: { width: Number(w), height: Number(h) },
  locale: lang === 'en' ? 'en-GB' : 'de-DE',
  timezoneId: 'Europe/Berlin',
  serviceWorkers: 'block',
  colorScheme: theme,
});
await ctx.addInitScript(([t, l]) => {
  try { localStorage.setItem('et-theme', t); localStorage.setItem('et-language', l); } catch { /* ohne Speicher */ }
}, [theme, lang]);
const page = await ctx.newPage();
await page.goto(`http://127.0.0.1:${port}/${route}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1800);
await page.screenshot({ path: out });
await browser.close();
console.log(`aufgenommen: ${out}`);
