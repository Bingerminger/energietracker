// =====================================================================
// Unit-Test für public/js/lib/format.js (v2.5.3) — ohne Server, ohne DOM.
//
//   node tests/format.test.mjs
//
// Sichert zwei Befunde des Reviews 2026-09-24 ab:
//   - FE-02/UI-02: Leere oder mit Komma getippte Zahlen wurden zu 0.
//     parseDecimal() liefert für „leer" null und versteht Komma und Punkt.
//   - FE-04: fmt.date()/fmt.month() gaben unlesbare Werte ROH zurück; die
//     Aufrufer setzen das Ergebnis in innerHTML (gespeichertes XSS).
// =====================================================================

import { parseDecimal, fmt, todayIso, formatForInput } from '../public/js/lib/format.js';
import { initI18n } from '../public/js/lib/i18n.js';

// i18n lädt Kataloge per fetch; ohne Server scheitert das still — die Sprache
// wird trotzdem gesetzt. Die Konsolenmeldung dazu ist hier erwartet. Das
// <html lang> setzt initI18n() auf einem minimalen document-Ersatz.
const quietError = console.error;
console.error = () => {};
globalThis.document ??= { documentElement: { setAttribute() {}, lang: '' } };

let failed = 0, passed = 0;
function eq(actual, expected, label) {
  if (Object.is(actual, expected)) { passed++; return; }
  failed++;
  console.log(`  ✗ ${label}: erwartet ${JSON.stringify(expected)}, bekommen ${JSON.stringify(actual)}`);
}

// ── Deutsch (Dezimalkomma) ────────────────────────────────────────────
await initI18n('de');
const de = [
  ['', null], ['   ', null], [null, null], [undefined, null], ['abc', null], ['12,5,3', null],
  ['12345,6', 12345.6], ['12345.6', 12345.6], ['1.234,5', 1234.5], ['1,234.5', 1234.5],
  ['1 234,5', 1234.5], ['1 234,5', 1234.5], ["1'234.5", 1234.5],
  ['1.234', 1234], ['1,234', 1.234], ['1.234.567', 1234567], ['0', 0], ['0,0', 0],
  ['-3,2', -3.2], ['+7', 7], [42, 42], [NaN, null], ['1.23.4', null], ['12,34,56', null],
];
for (const [inp, out] of de) eq(parseDecimal(inp), out, `de parseDecimal(${JSON.stringify(inp)})`);
eq(formatForInput(1234.5), '1234,5', 'de formatForInput');
eq(formatForInput(null), '', 'de formatForInput(null)');

// ── Englisch (Dezimalpunkt) ───────────────────────────────────────────
await initI18n('en');
eq(parseDecimal('1,234'), 1234, 'en: „1,234" ist tausend');
eq(parseDecimal('1.234'), 1.234, 'en: „1.234" ist ein Komma-Wert');
eq(parseDecimal('12345,6'), 12345.6, 'en: Komma mit einer Nachkommastelle bleibt Dezimal');
eq(formatForInput(1234.5), '1234.5', 'en formatForInput');
await initI18n('de');

// ── fmt.date / fmt.month ─────────────────────────────────────────────
eq(fmt.date('2026-03-08'), '08.03.2026', 'gültiges Datum');
eq(fmt.date('<img src=x onerror=alert(1)>'), '&lt;img src=x onerror=alert(1)&gt;', 'HTML wird escaped');
eq(fmt.date('x" autofocus onfocus="alert(1)'), 'x&quot; autofocus onfocus=&quot;alert(1)', 'Attribut-Ausbruch escaped');
eq(fmt.date('2026-02-30'), '2026-02-30', 'unmögliches Datum rollt nicht über');
eq(fmt.date(''), '–', 'leer');
eq(fmt.month('2026-03').includes('2026'), true, 'Monat');
eq(fmt.month('<b>'), '&lt;b&gt;', 'Monat: HTML wird escaped');
eq(fmt.month('2026-13'), '2026-13', 'Monat 13 rollt nicht über');

// ── todayIso in Ortszeit ─────────────────────────────────────────────
const d = new Date();
const local = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
eq(todayIso(), local, 'todayIso nutzt die Ortszeit');

console.error = quietError;
console.log(`\n  ${passed}/${passed + failed} Prüfungen bestanden`);
process.exit(failed ? 1 : 0);
