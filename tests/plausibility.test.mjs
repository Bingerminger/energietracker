// =====================================================================
// Unit-Test für public/js/lib/plausibility.js (v2.6.0) — ohne Server, ohne DOM.
//
//   node tests/plausibility.test.mjs
//
// Sichert die Rückfragen bei der Erfassung eines Zählerstands ab (Review
// 2026-09-24): Tippfehler nach oben, Rückgang ohne Zählertausch, Datum in
// der Zukunft, zweiter Stand am selben Tag. Der typische Tagesverbrauch
// folgt derselben Regel wie ReadingService::typicalPerDay() im Backend.
// =====================================================================

import { readFileSync } from 'node:fs';
import { typicalPerDay, checkReading, deviceChangedBetween, neighbours, JUMP_FACTOR } from '../public/js/lib/plausibility.js';

let failed = 0, passed = 0;
function eq(actual, expected, label) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a === e) { passed++; return; }
  failed++;
  console.log(`  ✗ ${label}: erwartet ${e}, bekommen ${a}`);
}
const types = (issues) => issues.map(i => i.type);

// ── typicalPerDay ─────────────────────────────────────────────────────
const r = (date, counter, extra = {}) => ({ date, counter, device_id: 'd1', ...extra });
eq(typicalPerDay([]), null, 'leer → null');
eq(typicalPerDay([r('2026-01-01', 0), r('2026-01-11', 100)]), null, 'ein Intervall reicht nicht');
eq(typicalPerDay([r('2026-01-01', 0), r('2026-01-11', 100), r('2026-01-21', 300)]), 15, 'Median aus zwei Intervallen (10 und 20)');
eq(typicalPerDay([r('2026-01-01', 0), r('2026-01-11', 100), r('2026-01-21', 200), r('2026-01-31', 5000)]), 10,
  'Median ist robust gegen einen Ausreißer');
eq(typicalPerDay([r('2026-01-21', 300), r('2026-01-01', 0), r('2026-01-11', 100)]), 15, 'unsortierte Eingabe');
// Ohne die Geräteregel ergäbe das [10, 490] → Median 250.
eq(typicalPerDay([r('2026-01-01', 0), r('2026-01-11', 100), r('2026-01-21', 5000, { device_id: 'd2' })]),
  null, 'Intervalle über einen Gerätewechsel zählen nicht');
eq(typicalPerDay([r('2026-01-01', 0), r('2026-01-11', 100), r('2026-01-15', 0, { is_suspect: true }), r('2026-01-21', 200)]), 10,
  'verdächtige Stände zählen nicht mit');
eq(typicalPerDay([r('2026-01-01', 0), r('2026-01-11', 100), r('2026-01-21', 200), r('2030-01-01', 9e9, { is_future: true })]), 10,
  'geplante Stände zählen nicht mit');

// ── checkReading ──────────────────────────────────────────────────────
const today = '2026-09-24';
const prev = { date: '2026-09-14', counter: 1000 };
eq(types(checkReading({ value: 1100, date: today, today, prev, typical: 10 })), [], 'normaler Stand: keine Rückfrage');
eq(types(checkReading({ value: 1000 + 10 * 10 * JUMP_FACTOR + 1, date: today, today, prev, typical: 10 })), ['jump'],
  'mehr als das Dreifache des Üblichen');
eq(types(checkReading({ value: 1000 + 10 * 10 * JUMP_FACTOR, date: today, today, prev, typical: 10 })), [],
  'genau das Dreifache ist noch normal');
eq(types(checkReading({ value: 999, date: today, today, prev, typical: 10 })), ['lower'], 'kleiner als der vorige Stand');
eq(checkReading({ value: 999, date: today, today, prev, typical: 10 })[0].last, 1000, 'Rückgang nennt den vorigen Stand');
eq(types(checkReading({ value: 5, date: today, today, prev, typical: 10, deviceChanged: true })), [],
  'nach einem Zählertausch ist ein kleiner Stand normal');
eq(types(checkReading({ value: 12000, date: today, today, prev, typical: null })), ['magnitude'],
  'ohne typischen Wert: mehr als zehnmal so groß → Komma vergessen?');
eq(types(checkReading({ value: 9000, date: today, today, prev, typical: null })), [], 'ohne typischen Wert: kein Sprung-Urteil');
eq(types(checkReading({ value: 1100, date: '2026-10-01', today, prev, typical: 10 })), ['future'], 'Datum in der Zukunft');
eq(types(checkReading({ value: 1100, date: today, today, prev, typical: 10, sameDay: { date: today, counter: 1090 } })), ['sameDay'],
  'schon ein Stand an diesem Tag');
eq(types(checkReading({ value: 500, date: '2026-09-01', today, prev, typical: 10 })), [],
  'ein vorheriger Stand NACH dem Datum ist keine Vergleichsbasis');
eq(types(checkReading({ value: 1100, date: today, today, prev: null, typical: null })), [], 'erster Stand: nichts zu prüfen');

// ── deviceChangedBetween ─────────────────────────────────────────────
const meter = { devices: [{ installed_on: '2020-01-01' }, { installed_on: '2026-09-20' }] };
eq(deviceChangedBetween(meter, '2026-09-14', '2026-09-24'), true, 'Einbau im Intervall');
eq(deviceChangedBetween(meter, '2026-09-20', '2026-09-24'), false, 'Einbau am Tag des vorigen Stands gehört nicht dazwischen');
eq(deviceChangedBetween(meter, '2026-09-14', '2026-09-20'), true, 'Einbau am Tag des neuen Stands zählt (Stichtag gehört zum neuen Gerät)');
eq(deviceChangedBetween({}, '2026-01-01', '2026-12-31'), false, 'ohne Geräte');

// ── v3.12.0 — voriger und nächster Stand zum gewählten Datum (Dialog „+ Ablesung“ und Seite „Zählerstände“) ──
{
  const list = [r('2026-01-01', 100), r('2026-02-01', 200), r('2026-03-01', 300), r('2026-02-15', 999, { is_suspect: true }), r('2026-12-01', 5000, { is_future: true })];
  const n = neighbours(list, { devices: [{ installed_on: '2020-01-01' }] }, '2026-02-10');
  eq([n.prev?.date, n.next?.date], ['2026-02-01', '2026-03-01'], 'Nachtrag: voriger und nächster Stand um das Datum, Verdacht nicht als nächster');
  eq(types(checkReading({ value: 350, date: '2026-02-10', today, typical: null, ...n })), ['higher_than_next'], 'Nachtrag über dem nächsten Stand fragt nach');
  eq(types(checkReading({ value: 150, date: '2026-02-10', today, typical: null, ...n })), ['lower'], 'Nachtrag unter dem vorigen Stand fragt nach');
  eq(types(checkReading({ value: 250, date: '2026-02-10', today, typical: null, ...n })), [], 'Nachtrag dazwischen: keine Rückfrage');
  const swap = neighbours(list, { devices: [{ installed_on: '2020-01-01' }, { installed_on: '2026-02-20' }] }, '2026-02-10');
  eq([swap.deviceChanged, swap.nextDeviceChanged], [false, true], 'Tausch zwischen Datum und nächstem Stand');
  eq(neighbours(list, null, '2027-01-01').next, null, 'geplante Stände sind kein nächster Stand');
}

// ── v3.12.0 (F1037) — gemeinsame Probe mit ReadingService::plausibilityIssues (Vorschlag über MCP) ──
const shared = JSON.parse(readFileSync(new URL('./fixtures/reading-plausibility.json', import.meta.url), 'utf8'));
for (const c of shared.cases) {
  eq(types(checkReading({ value: c.value, date: c.date, today: '2099-12-31', prev: c.prev, typical: c.typical, deviceChanged: c.deviceChanged,
    next: c.next ?? null, nextDeviceChanged: !!c.nextDeviceChanged })),
    c.expect, `gemeinsame Probe: ${c.name}`);
}
eq(shared.cases.length >= 10, true, 'gemeinsame Probe hat Fälle');

console.log(`plausibility.test: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
