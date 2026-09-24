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

import { readFileSync } from 'node:fs';
import { parseDecimal, fmt, todayIso, formatForInput, setCountry, intlLocale } from '../public/js/lib/format.js';
import { initI18n, t, setCurrencyParams, getCurrency } from '../public/js/lib/i18n.js';
import { gasEntryOn, gasFactorOf, cvUnit, CV_UNITS } from '../public/js/lib/gas-factor.js';

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

// ── v2.7.0 — Länderprofil: Region aus Sprache + Land, Währung ─────────
await initI18n('de');
setCountry('CH', ['de', 'fr', 'it']); setCurrencyParams('CHF');
eq(intlLocale(), 'de-CH', 'Region aus Sprache und Land');
eq(fmt.money(1234.5).startsWith('CHF'), true, 'de-CH: Franken vor dem Betrag');
eq(/^1.234\.5$/.test(fmt.num(1234.5, 1)), true, 'de-CH: Dezimalpunkt');
setCountry('DE', ['de']); setCurrencyParams('EUR');
eq(intlLocale(), 'de-DE', 'zurück auf Deutschland');
eq(fmt.money(1234.5), '1.234,50\u00a0€', 'de-DE: Euro');
await initI18n('en');
// Bestandsinstallationen tragen das Land DE — Englisch muss dort englisch
// schreiben bleiben (Intl schriebe für en-DE „1.234,5").
setCountry('DE', ['de']);
eq(intlLocale(), 'en-GB', 'Englisch in Deutschland bleibt en-GB');
eq(fmt.num(1234.5, 1), '1,234.5', 'Englisch in Deutschland: englische Zahlen');
setCountry('GB', ['en']); setCurrencyParams('GBP');
eq(fmt.money(1234.5), '£1,234.50', 'en-GB: Pfund');
setCountry(null);
eq(intlLocale(), 'en-GB', 'ungültiges Land: Region der Sprache');
setCountry('DE', ['de']);
setCurrencyParams('ZZZ');
eq(getCurrency(), 'EUR', 'unbekannte Währung fällt auf EUR');

// Katalog-Platzhalter {cur}/{minor}/{code}: dafür die echten Kataloge laden.
const realFetch = globalThis.fetch;
globalThis.fetch = async (url) => ({
  ok: true, json: async () => JSON.parse(readFileSync(new URL('../' + url, import.meta.url), 'utf8')),
});
await initI18n('de');
setCurrencyParams('CHF');
eq(t('contracts.unit.ctPerKwh'), 'Rp./kWh', 'Untereinheit aus der Währung');
eq(t('contracts.unit.ctPerKwh', { minor: 'x' }), 'x/kWh', 'ausdrückliche Parameter haben Vorrang');
setCurrencyParams('EUR');
eq(t('contracts.unit.ctPerKwh'), 'ct/kWh', 'Euro: ct');
globalThis.fetch = realFetch;
await initI18n('de');

// ── v2.7.0 — Gas-Umrechnung: gültiger Eintrag, Einheiten ──────────────
const gf = [
  { from: '2025-01-01', zustandszahl: null, brennwert: null, kwh_per_m3: 10.5 },
  { from: null, zustandszahl: null, brennwert: null, kwh_per_m3: 11 },
  { from: '2024-07-01', zustandszahl: 0.95, brennwert: 11.2, kwh_per_m3: 10.64 },
];
eq(gasEntryOn(gf, '2024-01-01')?.kwh_per_m3, 11, 'vor dem ersten Stichtag gilt der undatierte Eintrag');
eq(gasEntryOn(gf, '2024-07-01')?.brennwert, 11.2, 'Stichtag selbst gehört zum neuen Eintrag');
eq(gasEntryOn(gf, '2026-03-01')?.kwh_per_m3, 10.5, 'spätester erreichter Stichtag');
eq(gasEntryOn([{ from: '2030-01-01', kwh_per_m3: 10 }], '2024-01-01')?.kwh_per_m3, 10, 'nur künftige Stichtage: der früheste');
eq(gasEntryOn([], '2024-01-01'), null, 'leere Liste');
eq(Math.abs(gasFactorOf(gf[2]) - 10.64) < 1e-9, true, 'Faktor = Zustandszahl × Brennwert');
eq(gasFactorOf(gf[0]), 10.5, 'Faktor direkt');
eq(cvUnit('gj').perKwh, 0.0036, 'GJ/Smc');
eq(cvUnit('mj').perKwh, 3.6, 'MJ/m³');
eq(cvUnit('btu'), CV_UNITS.kwh, 'Unbekannte Einheit gilt als kWh/m³');

// ── todayIso in Ortszeit ─────────────────────────────────────────────
const d = new Date();
const local = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
eq(todayIso(), local, 'todayIso nutzt die Ortszeit');

console.error = quietError;
console.log(`\n  ${passed}/${passed + failed} Prüfungen bestanden`);
process.exit(failed ? 1 : 0);
