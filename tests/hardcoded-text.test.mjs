// =====================================================================
// Keine harten Texte in den Ansichten (v3.1.0, Review I18N-14).
//
//   node tests/hardcoded-text.test.mjs
//
// Sucht in public/js/views/*.js und public/js/components/*.js
//   - Textknoten in HTML-Templates (`>Text<`) mit Buchstaben außerhalb von ${…},
//   - String-Literale mit Umlaut oder ß außerhalb von Kommentaren.
// Texte gehören in die Kataloge (t()/tp()). Erlaubt sind Einheiten, Formelzeichen
// und Marken — die ALLOW-Liste unten.
// =====================================================================

import { readFileSync, readdirSync } from 'node:fs';

// Wörter, die in jeder Sprache gleich geschrieben werden
const ALLOW = new Set(['kWh', 'm³', 'm²', '°C', 'CO₂', 'R²', 'L', 'kg', 'MWh', 'ct', 'HGT', 'Ø', 'ø', 'PV', 'CSV', 'JSON',
  'PDF', 'API', 'Home Assistant', 'Open-Meteo', 'Open-Meteo.com', 'Energietracker', 'ENERGIETRACKER', 'GitHub', 'YAML', 'HTTPS',
  'ID', 'OK', 'z', 'Hs', 'kWh/m²·a', 'kWh/m³', 'g/kWh', 'configuration.yaml', 'secrets.yaml']);

const strip = src => src
  .replace(/\/\*[\s\S]*?\*\//g, m => m.replace(/[^\n]/g, ' '))   // Blockkommentare
  .replace(/(^|[^:\\])\/\/.*$/gm, '$1')                           // Zeilenkommentare
  .replace(/<!--[\s\S]*?-->/g, '');                                // HTML-Kommentare in Templates

let failed = 0, passed = 0;
const hits = [];
for (const dir of ['views', 'components']) {
  const base = new URL(`../public/js/${dir}/`, import.meta.url);
  for (const f of readdirSync(base).filter(f => f.endsWith('.js'))) {
    const src = strip(readFileSync(new URL(f, base), 'utf8'));
    // Textknoten: zwischen > und < (oder ${), ohne Platzhalter
    for (const m of src.matchAll(/(?:<[a-z][a-z0-9]*(?:\s[^<>]*)?>|<\/[a-z][a-z0-9]*>)([^<>`${}]*\p{L}[^<>`${}]*)(?=<|\$\{)/gu)) {
      const text = m[1].trim();
      if (!text || ALLOW.has(text)) continue;
      if (!/\p{L}{3,}/u.test(text)) continue;                 // einzelne Buchstaben, Kürzel
      if (/^[\w.-]+\(|=>|&&|\|\||\)\s*[;,]?$|\?\s*['"]|['"]\s*:|\b(const|let|return)\b/.test(text)) continue; // JS-Ausdruck
      if (/^[a-z][\w-]*(\s+[a-z][\w-]*=)/.test(text)) continue;
      hits.push(`${dir}/${f}: Textknoten „${text}“`);
    }
    // String-Literale mit Umlaut
    for (const m of src.matchAll(/(['"])((?:\\.|(?!\1)[^\n])*[äöüÄÖÜß](?:\\.|(?!\1)[^\n])*)\1/gu)) {
      hits.push(`${dir}/${f}: Literal „${m[2]}“`);
    }
  }
}
for (const h of hits) { failed++; console.log(`  ✗ ${h}`); }
if (!hits.length) passed++;
console.log(`\n  hardcoded-text: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
