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
const resp = { capabilities: { heat_model: true, tariff: true }, offers: [{ contract_id: 'c1', computable: true }], winter_presets: { cold_pct: 9 } };
t('alle vier Beispiele, wenn Heizmodell und Angebot da sind', S.presetsFor(resp).join() === 'cold_winter,roof,cheapest,save10');
t('Strom ohne Heizmodell und Angebot: nur sparen', S.presetsFor({ capabilities: { heat_model: false, tariff: true }, offers: [] }).join() === 'save10');
t('kalter Winter aus dem Klimanormal', S.presetState('cold_winter', base, resp).winter_pct === 9);
t('kalter Winter ohne Klimanormal: 10 % als Annahme', S.presetState('cold_winter', base, { capabilities: { heat_model: true } }).winter_pct === 10);
const roof = S.presetState('roof', { ...base, saving_pct: 30 }, resp);
t('Beispiel beginnt frisch (andere Regler auf null)', roof.saving_pct === 0 && roof.measures[0].kind === 'roof' && roof.measures[0].heat_pct === 15 && roof.meter_id === 'm_gas_main');
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

console.log(`\nscenario.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
