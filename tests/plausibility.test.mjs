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
import { typicalPerDay, checkReading, deviceChangedBetween, neighbours, JUMP_FACTOR, issueText, weatherExpectations, readingPreview } from '../public/js/lib/plausibility.js';
import { fmt } from '../public/js/lib/format.js';

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

// ── v3.13.0 (#65) — Erwartung nach dem Wetter (GET …/reading-expectation) ──
{
  const prev = { date: '2025-11-01', counter: 12429.7 };
  const w = { basis: 'degree_days', from: '2025-11-01', to: '2025-11-08', expected: 28.5, high: 85.5 };
  const at = (value, extra = {}) => checkReading({ value, date: '2025-11-08', today: '2025-11-08', prev, typical: 0.4, ...extra });
  // Melder: Sommer 0,4 m³ am Tag, erste Heizwoche 28,5 m³ — die alte Regel fragt, nach dem Wetter nicht
  eq(types(at(12458.2)), ['jump'], 'alte Regel: Heizbeginn gilt als Tippfehler (#65)');
  eq(types(at(12458.2, { expected: w })), [], 'nach dem Wetter: erste Heizwoche ohne Rückfrage');
  const j = at(12429.7 + 86, { expected: w });
  eq(types(j), ['jump'], 'über der Obergrenze nach dem Wetter: Sprung');
  eq([Math.round(j[0].amount * 10) / 10, j[0].expected, j[0].basis], [86, 28.5, 'degree_days'], 'Sprung nennt Menge, Erwartung und Grundlage');
  eq(issueText(j[0], { unit: 'm³' }), 'readingCheck.jumpWeather', 'Klartext nach dem Wetter (am Feld `expected`)');
  eq(issueText({ type: 'jump', perDay: 5, typical: 1 }, { unit: 'm³' }), 'readingCheck.jump', 'ohne Erwartung der bisherige Klartext');
  const open = { ...w, expected: null, high: null };
  eq(types(at(13500, { expected: open })), [], 'Höhe offen (kälter als alles bisher): kein Sprung');
  eq(types(at(124582, { expected: open })), ['magnitude'], 'Höhe offen: Komma vergessen fragt');
  eq(types(at(12458.2, { expected: { ...w, from: '2025-10-25' } })), ['jump'], 'Erwartung für einen anderen Abstand: alte Regel');
  eq(types(at(12458.2, { expected: { ...w, basis: null } })), ['jump'], 'ohne Grundlage (keine Temperaturen): alte Regel');
  eq(types(at(5, { expected: w, deviceChanged: true })), [], 'Gerätetausch dazwischen: keine Rückfrage');

  let calls = 0;
  const wx = weatherExpectations(async (from, to) => { calls++; return { basis: 'heat_model', from, to, expected: 1, high: 3 }; });
  eq(wx.has('2025-11-01', '2025-11-08'), false, 'Erwartung: vor dem Laden nicht da');
  eq(wx.get('2025-11-01', '2025-11-08'), null, 'Erwartung: vor dem Laden null (beim Tippen die alte Regel)');
  const p1 = wx.load('2025-11-01', '2025-11-08');
  eq(wx.has('2025-11-01', '2025-11-08'), true, 'Erwartung: unterwegs zählt als geladen (kein zweiter Abruf)');
  await p1; await wx.load('2025-11-01', '2025-11-08');
  eq([calls, wx.get('2025-11-01', '2025-11-08')?.high], [1, 3], 'Erwartung: einmal je Abstand geladen, dann sofort da');
  eq(await wx.load('2025-11-08', '2025-11-01'), null, 'Erwartung: kein Abstand, keine Anfrage');
  eq(calls, 1, 'Erwartung: ohne Abstand nicht gefragt');
  const off = weatherExpectations(async () => { throw new Error('offline'); });
  eq(await off.load('2025-11-01', '2025-11-08'), null, 'Erwartung: ohne Verbindung null (alte Regel)');
  const demo = weatherExpectations(async () => null);
  eq(await demo.load('2025-11-01', '2025-11-08'), null, 'Erwartung: Demo ohne Antwort null');
}

// ── v3.13.0 (Paket K2e) — Vorschau beim Eintippen gegen den Stand davor und danach zum Datum ──
// Erfundener Gaszähler: 4.200 m³ am 31.03.2024, 7.300 m³ am 31.01.2026; Nachtrag 5.600 m³ am 28.02.2025.
// Von Hand: seit dem Stand davor 5.600 − 4.200 = +1.400, bis zum Stand danach 7.300 − 5.600 = +1.700.
{
  global.fetch = async (url) => {
    const m = String(url).match(/locales\/([a-z]+)\.json$/);
    return m ? new Response(readFileSync(new URL(`../public/locales/${m[1]}.json`, import.meta.url), 'utf8'), { status: 200 }) : new Response('{}', { status: 404 });
  };
  const { initI18n } = await import('../public/js/lib/i18n.js');
  global.document ??= { documentElement: { setAttribute() {} } };   // ohne DOM: initI18n setzt <html lang>
  await initI18n('de');
  const nb = (s) => (s == null ? s : s.replace(/[  ]/g, ' '));
  const A = { date: '2024-03-31', counter: 4200 }, B = { date: '2026-01-31', counter: 7300 };
  eq(nb(readingPreview({ value: 5600, date: '2025-02-28', prev: A, next: B, unit: 'm³' })),
    '+1.400,00 m³ seit dem Stand vom 31.03.2024 · +1.700,00 m³ bis zum Stand vom 31.01.2026', 'Vorschau: Nachtrag zwischen zwei Ständen (Dialog)');
  eq(nb(readingPreview({ value: 5600, date: '2025-02-28', prev: A, next: B, unit: 'm³', num: (n) => fmt.dec(n, 3), view: 'readingsEntry' })),
    '+1.400 m³ seit dem Stand vom 31.03.2024 · +1.700 m³ bis zum Stand vom 31.01.2026', 'Vorschau: derselbe Nachtrag auf der Seite (eigenes Zahlenformat)');
  eq(nb(readingPreview({ value: 4000, date: '2024-01-15', prev: null, next: A, unit: 'm³' })),
    '+200,00 m³ bis zum Stand vom 31.03.2024', 'Vorschau: Nachtrag vor dem ersten Stand, nur bis zum nächsten');
  eq(nb(readingPreview({ value: 7400, date: '2026-02-10', prev: B, next: null, unit: 'm³' })),
    '+100,00 m³ seit letztem Stand (vor 10 Tagen)', 'Vorschau: neuer jüngster Stand wie bisher');
  // Jahreswechsel von Hand: 31.12.2025 → 01.01.2026 ist ein Tag
  eq(nb(readingPreview({ value: 7012, date: '2026-01-01', prev: { date: '2025-12-31', counter: 7000 }, unit: 'm³' })),
    '+12,00 m³ seit letztem Stand (vor 1 Tag)', 'Vorschau: über den Jahreswechsel ein Tag');
  eq(nb(readingPreview({ value: 7500, date: '2025-02-28', prev: A, next: B, unit: 'm³' })),
    '+3.300,00 m³ seit dem Stand vom 31.03.2024 · −200,00 m³ bis zum Stand vom 31.01.2026', 'Vorschau: über dem Stand danach negativ (die Rückfrage meldet es)');
  eq(nb(readingPreview({ value: 30, date: '2025-02-28', prev: A, next: B, unit: 'm³', deviceChanged: true })),
    '+7.270,00 m³ bis zum Stand vom 31.01.2026', 'Vorschau: Gerätetausch davor, keine Differenz zum alten Gerät');
  eq(nb(readingPreview({ value: 5600, date: '2025-02-28', prev: A, next: B, unit: 'm³', nextDeviceChanged: true })),
    '+1.400,00 m³ seit dem Stand vom 31.03.2024', 'Vorschau: Gerätetausch danach, keine Differenz zum neuen Gerät');
  eq(readingPreview({ value: 4300, date: '2024-03-31', prev: A, unit: 'm³' }), null, 'Vorschau: am Tag des vorigen Stands keine Differenz');
  eq(readingPreview({ value: null, date: '2025-02-28', prev: A, next: B }), null, 'Vorschau: ohne Zahl keine');
}

// ── v3.12.0 (F1037) — gemeinsame Probe mit ReadingService::plausibilityIssues (Vorschlag über MCP) ──
const shared = JSON.parse(readFileSync(new URL('./fixtures/reading-plausibility.json', import.meta.url), 'utf8'));
for (const c of shared.cases) {
  eq(types(checkReading({ value: c.value, date: c.date, today: '2099-12-31', prev: c.prev, typical: c.typical, deviceChanged: c.deviceChanged,
    next: c.next ?? null, nextDeviceChanged: !!c.nextDeviceChanged, expected: c.expected ?? null })),
    c.expect, `gemeinsame Probe: ${c.name}`);
}
eq(shared.cases.length >= 10, true, 'gemeinsame Probe hat Fälle');

console.log(`plausibility.test: ${passed} bestanden, ${failed} fehlgeschlagen`);
process.exit(failed ? 1 : 0);
