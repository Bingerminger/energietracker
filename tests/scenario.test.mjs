// =====================================================================
// v3.6.0 (F1031) — Prognose-Werkstatt ohne Server: Zustand, Anfrage,
// Beispielszenarien, Speichern je Gerät, CSV, Demo-Schlüssel, Stufen,
// Ergebniskarte.
//
// Die Anfrage muss immer gleich aussehen — sonst findet die Pages-Demo ihre
// vorgerechneten Antworten nicht (tools/build-demo.mjs baut sie mit denselben
// Funktionen). Gerechnet wird nur auf dem Server (ScenarioServiceTest).
//
// Aufruf: node tests/scenario.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..');
const JS = resolve(ROOT, 'public', 'js');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'sessionStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}

const S = await import(`${JS}/lib/scenario.js`);
const { demoBodyKey } = await import(`${JS}/lib/demo-key.js`);

// ── Anfrage ───────────────────────────────────────────────────────────
const base = S.defaultState('gas', 'm_gas_main');
const body = S.scenarioBody(base);
t('Ausgangsfall: alle Regler null, Tarif laufend, keine optionalen Felder',
  body.winter_pct === 0 && body.saving_pct === 0 && body.tariff.kind === 'current' && body.measures.length === 0
  && !('budget_eur' in body) && !('regression' in body) && !('ev_kwh_per_100km' in body) && !('jaz_from' in body));
t('Ausgangsfall erkannt', S.isBaseline(base));
const typed = S.scenarioBody({ ...base, winter_pct: '15', saving_pct: '', measures: [{ id: 'a', heat_pct: '20', cost_eur: '' }] });
t('Zahlen aus Formularen werden Zahlen, Leeres der Ausgangswert',
  typed.winter_pct === 15 && typed.saving_pct === 0 && typed.measures[0].heat_pct === 20 && typed.measures[0].cost_eur === null && typed.measures[0].active === true);
t('JAZ nur beide', !('jaz_from' in S.scenarioBody({ ...base, jaz_from: 3 })) && S.scenarioBody({ ...base, jaz_from: 3, jaz_to: 4 }).jaz_to === 4);
t('höchstens zehn Maßnahmen', S.scenarioBody({ ...base, measures: Array.from({ length: 12 }, (_, i) => ({ id: `m${i}` })) }).measures.length === S.MAX_MEASURES);
const custom = S.scenarioBody({ ...base, tariff: { kind: 'custom', working_price_ct: '9.5' } });
t('eigener Preis: Grundpreis 0 als Ausgangswert', custom.tariff.working_price_ct === 9.5 && custom.tariff.base_eur_month === 0);
t('Anfrage ist idempotent', JSON.stringify(S.scenarioBody(S.scenarioBody(typed))) === JSON.stringify(typed));
t('Demo-Schlüssel unabhängig von der Reihenfolge der Felder',
  demoBodyKey('/api/forecast/scenario', { a: 1, b: { y: 2, x: [1, { q: 1, p: 2 }] } }) === demoBodyKey('/api/forecast/scenario', { b: { x: [1, { p: 2, q: 1 }], y: 2 }, a: 1 }));
t('Demo-Schlüssel trennt verschiedene Eingaben', demoBodyKey('/api/forecast/scenario', body) !== demoBodyKey('/api/forecast/scenario', typed));

// ── Beispielszenarien ─────────────────────────────────────────────────
// v3.12.0 (F1040) — Das Profil kommt vom Server (ScenarioProfile.php), der Browser kennt die Beispiele nicht selbst
const roofMeasure = { id: 'roof', kind: 'roof', label: '', heat_pct: 15, base_pct: 0, cost_eur: null, active: true };
const resp = { valid: true, profile: { group: 'heat_fuel', base_word: 'hot_cook',
  presets: [
    { id: 'cold_winter', direction: 'more', apply: { winter_pct: 9 }, assumed: { pct: 9 } },
    { id: 'room_minus_1', direction: 'less', apply: { room_temp_delta: -1 }, assumed: {} },
    { id: 'roof', direction: 'less', apply: { measures: [roofMeasure] }, assumed: { pct: 15 } },
    { id: 'cheapest', direction: 'less', apply: { tariff: { kind: 'cheapest' } }, assumed: {} },
  ],
  measures: [{ kind: 'roof', levers: ['heat'] }, { kind: 'hot_water', levers: ['base'] }, { kind: 'other', levers: ['heat', 'base'] }],
  kind_aliases: { shower: 'hot_water' }, preset_redirects: { save10: 'room_minus_1' } } };
t('Beispiele aus dem Profil des Servers', S.presetsFor(resp).join() === 'cold_winter,room_minus_1,roof,cheapest');
t('ohne gültige Antwort keine Beispiele', S.presetsFor({ valid: false, profile: resp.profile }).length === 0 && S.presetsFor(null).length === 0);
t('Zahl des Beispiels aus dem Profil', S.presetState('cold_winter', base, resp).winter_pct === 9);
t('unbekanntes Beispiel: frischer Zustand', S.presetState('gibtsnicht', { ...base, saving_pct: 30 }, resp).saving_pct === 0);
const roof = S.presetState('roof', { ...base, saving_pct: 30 }, resp);
t('Beispiel beginnt frisch (andere Regler auf null)', roof.saving_pct === 0 && roof.measures[0].kind === 'roof' && roof.measures[0].heat_pct === 15 && roof.meter_id === 'm_gas_main');
roof.measures[0].heat_pct = 99;
t('Beispiel teilt nichts mit der Antwort', roofMeasure.heat_pct === 15);
t('Sprung: vorhandene Kennung bleibt', S.resolvePreset('roof', resp) === 'roof');
t('Sprung: alte Kennung leitet weiter', S.resolvePreset('save10', resp) === 'room_minus_1');
t('Sprung: unbekannt bleibt ohne Wirkung', S.resolvePreset('gibtsnicht', resp) === null && S.resolvePreset(null, resp) === null);
t('Wort aus dem Profil, ohne Profil Grundlast', S.baseWord(resp) === 'hot_cook' && S.baseWord(null) === 'baseload');
const passt = S.measureView(resp, { kind: 'roof', heat_pct: '', base_pct: '' });
t('Maßnahme: nur der Hebel ihrer Art', passt.fits && passt.heat && !passt.base && passt.kinds.join() === 'roof,hot_water,other');
const alias = S.measureView(resp, { kind: 'shower', base_pct: '10' });
t('Maßnahme: alte Art unter neuem Namen', alias.kind === 'hot_water' && alias.fits);
const fremd = S.measureView(resp, { kind: 'leak', base_pct: '10', heat_pct: '' });
t('Maßnahme: passt nicht, bleibt mit Wert sichtbar und markiert', !fremd.fits && fremd.base && !fremd.heat && fremd.kinds.includes('leak'));
const fremdWert = S.measureView(resp, { kind: 'roof', heat_pct: '', base_pct: '5' });
t('Maßnahme: gespeicherter Wert im fremden Feld bleibt sichtbar', fremdWert.base && fremdWert.heat);
t('Maßnahme: gespeicherter Heizanteil an einer Art ohne Heizung bleibt sichtbar', (() => { const v = S.measureView(resp, { kind: 'hot_water', heat_pct: '5', base_pct: '' }); return v.heat && v.base; })());
t('Maßnahme: ohne Wert kein fremdes Feld', (() => { const v = S.measureView(resp, { kind: 'hot_water', heat_pct: '', base_pct: '' }); return !v.heat && v.base; })());
t('Maßnahme ohne Profil: beide Felder', (() => { const v = S.measureView(null, { kind: 'roof' }); return v.heat && v.base && v.fits; })());
t('günstigstes Angebot', S.presetState('cheapest', base, resp).tariff.kind === 'cheapest');

// ── Speichern je Gerät ────────────────────────────────────────────────
localStorage.clear();
t('speichern', S.saveScenario('Winter', { ...base, winter_pct: 9 }) && S.savedScenarios().length === 1);
S.saveScenario('Dach', roof);
S.saveScenario('Winter', { ...base, winter_pct: 12 });
const list = S.savedScenarios();
t('gleicher Name ersetzt, neuestes zuerst', list.length === 2 && list[0].name === 'Winter' && list[0].state.winter_pct === 12);
t('leerer Name wird nicht gespeichert', !S.saveScenario('   ', base));
S.deleteScenario('Dach');
t('löschen', S.savedScenarios().map(x => x.name).join() === 'Winter');
for (let i = 0; i < 25; i++) S.saveScenario(`S${i}`, base);
t('höchstens 20', S.savedScenarios().length === 20);
localStorage.setItem(S.STORE_KEY, '{kaputt');
t('kaputter Speicher: leere Liste statt Fehler', Array.isArray(S.savedScenarios()) && S.savedScenarios().length === 0);
S.rememberCurrent({ ...base, winter_pct: 5 }, 'Jetzt');
t('Druckansicht findet das Szenario dieser Sitzung', S.currentScenario()?.name === 'Jetzt' && S.currentScenario().state.winter_pct === 5);

// ── CSV ───────────────────────────────────────────────────────────────
const csv = S.scenarioCsv({ months: [{ ym: '2026-11', base_qty: 100.5, scen_qty: 90, base_cost: 10, scen_cost: 9, base_cum_cost: 10, scen_cum_cost: 9, advance: null }] },
  ['Monat', 'Menge; Ausgangsfall', 'b', 'c', 'd', 'e', 'f', 'g']);
t('CSV: Semikolon, Punkt, Kopf geschützt, leere Abschläge leer',
  csv === 'Monat;"Menge; Ausgangsfall";b;c;d;e;f;g\n2026-11;100.5;90;10;9;10;9;\n', JSON.stringify(csv));

// ── Stufen und Ergebniskarte ──────────────────────────────────────────
const L = await import(`${JS}/lib/levels.js`);
t('Prognose-Werkstatt für Einsteiger erreichbar', L.viewLevel('forecast') === 'beginner' && L.viewLevel('scenario-print') === 'beginner');
const { resultCardHtml } = await import(`${JS}/components/result-card.js`);
const html = resultCardHtml({ brand: 'B', title: '<b>x</b>', tone: 'more', big: '+214 €', sentence: 'Ein kalter Winter kostet dich 214 € mehr im Jahr.', facts: [{ label: 'CO₂', value: '+90 kg' }], footer: 'F' });
t('Ergebniskarte: Satz als Beschriftung, Werte escaped', html.includes('ws-card--more') && html.includes('<figcaption') && html.includes('&lt;b&gt;x&lt;/b&gt;') && !html.includes('<b>x</b>'));

// v3.10.0 (Skeptiker N6) — „erstellt am“ der Ergebniskarten in Ortszeit: `toISOString()` ist UTC und
// zeigte kurz nach Mitternacht (Sommerzeit bis 02:00) den Vortag
for (const f of ['views/forecast.js', 'views/scenario-print.js', 'views/flexibility.js']) {
  const src = readFileSync(resolve(JS, f), 'utf8');
  t(`${f}: Datum der Ergebniskarte in Ortszeit (todayIso)`, !/new Date\(\)\.toISOString\(\)\.slice\(0, 10\)/.test(src) && /date: fmt\.date\(todayIso\(\)\)/.test(src));
}

// v3.12.0 — die Untereinheit der Währung (ct, Rp., p) kommt aus getCurrencyMinor(), nie fest im Code der Werkstatt
for (const f of ['views/forecast.js', 'views/scenario-print.js', 'lib/scenario.js']) {
  const src = readFileSync(resolve(JS, f), 'utf8').split('\n').filter(l => !/^\s*\/\//.test(l)).join('\n');
  t(`${f}: keine feste Untereinheit „ct/“`, !/[\s`'"(]ct\//.test(src), (src.match(/.{0,40}[\s`'"(]ct\/.{0,20}/) || [''])[0]);
}

// v3.12.0 (F1040, Phase 2) — die neuen Felder nur, wenn gesetzt: Die Anfragen der übrigen Arten
// (und damit die vorgerechneten Antworten der Demo) bleiben, wie sie waren
{
  const old = ['utility', 'meter_id', 'model', 'winter_pct', 'room_temp_delta', 'saving_pct', 'baseload_pct', 'ev_km', 'measures', 'tariff'];
  t('Phase 2: Anfrage ohne neue Felder wie bisher', JSON.stringify(Object.keys(S.scenarioBody(S.defaultState('gas', 'm')))) === JSON.stringify(old));
  const b = S.scenarioBody({ ...S.defaultState('heizoel', 'm'), price_pct: 10, replace: { to: 'heat_pump', jaz: 3, power_ct: null } });
  t('Phase 2: Preis und Heizungstausch in der Anfrage', b.price_pct === 10 && JSON.stringify(b.replace) === '{"to":"heat_pump","jaz":3}', JSON.stringify(b));
  const p = S.scenarioBody({ ...S.defaultState('pv_erzeugung', 'm'), pv_size_pct: 50, battery_kwh: 5, self_use_pct: -10, feed_in_ct: 0, pv_kwp_now: 8 });
  t('Phase 2: PV-Felder, auch eine Vergütung von 0 ct', p.pv_size_pct === 50 && p.battery_kwh === 5 && p.self_use_pct === -10 && p.feed_in_ct === 0 && p.pv_kwp_now === 8, JSON.stringify(p));
  t('Phase 2: neuer Hebel ist kein Ausgangsfall', !S.isBaseline({ ...S.defaultState('pv_erzeugung', 'm'), battery_kwh: 5 }) && S.isBaseline(S.defaultState('pv_erzeugung', 'm')));
}

console.log(`\nscenario.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
