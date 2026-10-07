// =====================================================================
// Pluralregeln im Browser (v3.1.0, Review I18N-09) — ohne Server, ohne DOM.
//
//   node tests/plural.test.mjs
//
// tp() wählt die Form über Intl.PluralRules, das Backend (Empfehlungen,
// Tarifwechsel) über I18nService::PLURAL_RULES. Beide prüfen gegen dieselbe
// Datei tests/fixtures/plural-cases.json — „vor 1 Tag" heißt in der Oberfläche
// und in einer Empfehlung gleich. Dazu: keine Ad-hoc-Plurale „=== 1 ? t(…)"
// mehr in den Ansichten.
// =====================================================================

import { readFileSync, readdirSync } from 'node:fs';
import { pluralCategory, presetLocale } from '../public/js/lib/i18n.js';

let failed = 0, passed = 0;
function eq(actual, expected, label) {
  if (Object.is(actual, expected)) { passed++; return; }
  failed++;
  console.log(`  ✗ ${label}: erwartet ${JSON.stringify(expected)}, bekommen ${JSON.stringify(actual)}`);
}

const { cases } = JSON.parse(readFileSync(new URL('./fixtures/plural-cases.json', import.meta.url), 'utf8'));
const languages = Object.keys(JSON.parse(readFileSync(new URL('../public/locales/languages.json', import.meta.url), 'utf8')));
for (const lang of languages) {
  eq(lang in cases, true, `${lang}: Eintrag in plural-cases.json`);
  presetLocale(lang);
  for (const [n, cat] of Object.entries(cases[lang] || {})) eq(pluralCategory(Number(n)), cat, `${lang} ${n}`);
}
// Europäisches Portugiesisch: „0 dias", nicht „0 dia" wie in Brasilien
presetLocale('pt');
eq(pluralCategory(0), 'other', 'pt: 0 ist Plural (pt-PT)');

// Keine Ad-hoc-Plurale in den Ansichten
const dir = new URL('../public/js/views/', import.meta.url);
for (const f of readdirSync(dir).filter(f => f.endsWith('.js'))) {
  const src = readFileSync(new URL(f, dir), 'utf8');
  // „morgen" ist ein eigenes Wort, kein Plural
  const m = src.match(/===\s*1\s*\?\s*t\('(?![\w.]*Tomorrow')/);
  eq(m ? m[0] : null, null, `${f}: „=== 1 ? t(" statt tp()`);
}

console.log(`\n  plural.test: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
