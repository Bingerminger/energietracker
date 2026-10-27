// =====================================================================
// v3.9.0 (F1032, GitHub #40) — Assistent „Messkonzept“ ohne Server:
//   • welche Aufbauten zu den Antworten passen (Kaskade nicht mit HT/NT nur am Gerät),
//   • Kürzel Z1, Z2 … wie im Issue (Einspeisung = zweites Zählwerk von Z1),
//   • Schaltbilder: role="img" mit Titel aus dem Katalog (7 Sprachen), nur
//     Klassen statt Farben, Knoten an jedem Abzweig, Zählwerke unter dem
//     Zähler, Wärmepumpe bzw. Wallbox, „Z1 − Z2“ in der Kaskade,
//   • Vorauswahl vorhandener Zähler (keine Doppelten, nur wo eindeutig),
//   • Körper für POST /api/metering-concept,
//   • Vorbelegung aus dem Haushalt, Schritte des Assistenten.
//
// Aufruf: node tests/metering-concepts.test.mjs
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
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><div id="app"></div><div id="modal-root"></div><div id="toast-stack"></div></body></html>', { url: 'http://127.0.0.1/#/settings' });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
dom.window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
global.fetch = async (url) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json/);
  if (m) return new Response(read(`public/locales/${m[1]}.json`), { status: 200 });
  return new Response(JSON.stringify({ success: true, data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

const i18n = await import(`${JS}/lib/i18n.js`);
await i18n.initI18n('de');
const C = await import(`${JS}/lib/metering-concepts.js`);
const box = document.createElement('div');
const parse = (html) => { box.innerHTML = html; return box.firstElementChild; };

console.log('Aufbauten und Antworten');
t('ohne Gerät mit eigenem Tarif: nur „ein Zähler für alles“', C.conceptsFor({ pv: true }).join() === 'single');
t('mit Wärmepumpe: nebeneinander, dahinter, Kaskade', C.conceptsFor({ device: 'heat_pump' }).join() === 'side_by_side,behind,cascade');
t('Kaskade nicht mit HT/NT nur am Gerät (wie errors.concept.touCascade)', !C.conceptAllowed('cascade', { device: 'heat_pump', tou: 'device' })
  && C.conceptAllowed('cascade', { device: 'heat_pump', tou: 'all' }) && C.conceptAllowed('behind', { device: 'heat_pump', tou: 'device' }));
const n0 = C.normalizeSetup({ pv: false, battery: true, tou: 'device', device: 'sauna' });
t('Speicher nur mit PV, HT/NT am Gerät nur mit Gerät, unbekanntes Gerät fällt weg', n0.battery === false && n0.tou === 'none' && n0.device === null, JSON.stringify(n0));

console.log('Kürzel wie im Issue');
const tc = C.slotTags('cascade', { device: 'heat_pump', pv: true, tou: 'all' });
t('Kaskade: Z1 vorn (Zweirichtung, auch die Einspeisung), Z2 Haushalt, Z3 PV', tc.main === 'Z1' && tc.feed === 'Z1' && tc.house === 'Z2' && tc.gen === 'Z3', JSON.stringify(tc));
const tb = C.slotTags('behind', { device: 'ev_charger', pv: true, battery: true });
t('dahinter mit Speicher: Z2 Gerät, Z3 PV, Z4 Speicher (Ladung und Entladung)', tb.dev === 'Z2' && tb.gen === 'Z3' && tb.bat_in === 'Z4' && tb.bat_out === 'Z4', JSON.stringify(tb));
t('Vorbelegung der Zählwerke wie der Server (HT/NT nur am Gerät)', JSON.stringify(C.defaultRegisters('behind', { device: 'heat_pump', tou: 'device' }))
  === JSON.stringify({ main: { one: '1.8.0' }, dev: { ht: '1.8.1', nt: '1.8.2' } }));
t('Kennzahl kurz fürs Schild', C.shortObis('1-0:1.8.1*255') === '1.8.1' && C.shortObis('2.8.0') === '2.8.0' && C.shortObis('') === '');

console.log('Schaltbilder');
const cases = [
  ['single', {}], ['single', { pv: true }], ['single', { pv: true, battery: true, tou: 'all' }],
  ['side_by_side', { device: 'heat_pump' }], ['side_by_side', { device: 'heat_pump', pv: true, tou: 'all' }],
  ['behind', { device: 'ev_charger', tou: 'device' }], ['behind', { device: 'heat_pump', pv: true, battery: true }],
  ['cascade', { device: 'heat_pump', pv: true, tou: 'all' }], ['cascade', { device: 'ev_charger' }],
];
const svgs = cases.map(([c, s]) => [c, s, C.schematic(c, s)]);
t('jedes Bild: role="img" mit Titel „Schaltbild: …“', svgs.every(([c, , html]) => {
  const el = parse(html);
  return el?.getAttribute('role') === 'img' && el.querySelector('title')?.textContent === `Schaltbild: ${i18n.t(`concept.layout.${c}.title`)}`;
}));
const all = svgs.map(x => x[2]).join('');
t('keine festen Farben, keine Bilddateien, jede Form mit .ill-Klasse', !/(fill|stroke|color)\s*[=:]\s*["']?(#|rgb|hsl)/i.test(all) && !/style="|<image|href=/i.test(all)
  && [...all.matchAll(/<(path|rect|circle|ellipse|polyline|text)\b([^>]*)>/g)].every(m => /class="ill-/.test(m[2])));
const casc = parse(svgs[7][2]);
const texts = (el) => [...el.querySelectorAll('text')].map(x => x.textContent);
t('Kaskade: Zähler Z1–Z3, das Gerät als Rest „Z1 − Z2“', ['Z1', 'Z2', 'Z3', 'Z1 − Z2'].every(x => texts(casc).includes(x)), texts(casc).join(' '));
t('Kaskade: Zählwerke unter Z1 (1.8.1, 1.8.2, 2.8.0) und Z2 (1.8.1, 1.8.2)', texts(casc).filter(x => x === '1.8.1').length === 2 && texts(casc).includes('2.8.0'));
t('Kaskade mit PV: Pfeil der Einspeisung zum Netz, Wärmepumpe, Sonne', casc.querySelectorAll('path.ill-accent').length >= 1
  && !!casc.querySelector('.ill-c-waerme') && !!casc.querySelector('.ill-c-pv'));
// Knoten: wo drei Leitungen sich treffen (Zuleitung + innere Abzweige)
const nodes = (html) => parse(html).querySelectorAll('circle.ill-node').length;
t('Knoten an jedem Abzweig (Kaskade mit PV: einer, die Zuleitung trifft die PV-Zeile)', nodes(svgs[7][2]) === 1, String(nodes(svgs[7][2])));
t('Knoten (dahinter mit PV und Speicher: Zuleitung + zwei innere Abzweige)', nodes(svgs[6][2]) === 3, String(nodes(svgs[6][2])));
t('Knoten (nebeneinander: am Netz, an Z1 zu Haushalt und PV)', nodes(svgs[4][2]) === 2, String(nodes(svgs[4][2])));
t('ein Zähler ohne PV: keine Sammelleitung, kein Knoten', nodes(svgs[0][2]) === 0 && !parse(svgs[0][2]).querySelector('path.ill-accent'));
t('Wallbox statt Wärmepumpe', !parse(svgs[5][2]).querySelector('.ill-c-waerme') && !!parse(svgs[3][2]).querySelector('.ill-c-waerme'));
t('Speicher im Bild, wenn gewählt', /class="ill-fill" x="[\d.]+" y="[\d.]+"/.test(svgs[6][2]) && parse(svgs[6][2]).querySelectorAll('text').length > parse(C.schematic('behind', { device: 'heat_pump', pv: true })).querySelectorAll('text').length);
const own = parse(C.schematic('single', { tou: 'all' }, { registers: { main: { ht: '1-0:1.8.1*255', nt: '1.8.2' } }, title: 'Eigenes Bild' }));
t('eigene Anlage: Titel und Zählwerke aus der Vorschau (kurz fürs Schild)', own.querySelector('title')?.textContent === 'Eigenes Bild' && texts(own).includes('1.8.1') && texts(own).includes('1.8.2'));
const css = read('public/css/components.css').replace(/\/\*[\s\S]*?\*\//g, '');
t('neue Klassen haben eine Regel ohne feste Farbe (.ill-node, .ill-small)', ['ill-node', 'ill-small'].every(c => {
  const m = css.match(new RegExp(`\\.ill \\.${c}\\s*\\{([^}]*)\\}`));
  return m && !/#[0-9a-f]{3,8}\b|rgb\(|hsl\(/i.test(m[1]) && /var\(--ill-/.test(m[1]);
}));
const langs = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];
const missing = [];
for (const l of langs) {
  await i18n.initI18n(l);
  for (const c of C.CONCEPTS) {
    const title = parse(C.schematic(c, { device: 'heat_pump', pv: true })).querySelector('title')?.textContent || '';
    if (!title || title.includes('concept.')) missing.push(`${l}:${c}`);
    const text = C.layoutText(c, { device: 'ev_charger', pv: true });
    if (text.includes('{') || text.includes('concept.')) missing.push(`${l}:${c}:text`);
  }
}
await i18n.initI18n('de');
t('Titel und Sätze in allen sieben Sprachen (Gerät eingesetzt)', missing.length === 0, missing.join(', '));

console.log('Vorhandene Zähler (keine Doppelten)');
const slotsCascade = {
  main: { utility: 'strom', role: 'heat_pump', parent: null, registers: { ht: '1.8.1', nt: '1.8.2' } },
  house: { utility: 'strom', role: 'household', parent: 'main', registers: { ht: '1.8.1', nt: '1.8.2' } },
  feed: { utility: 'pv_einspeisung', role: null, parent: null, registers: { one: '2.8.0' } },
  gen: { utility: 'pv_erzeugung', role: null, parent: null, registers: { one: '' } },
};
const fresh = C.suggestAssignments({ main: { utility: 'strom', role: 'household', parent: null, registers: { one: '1.8.0' } },
  dev: { utility: 'strom', role: 'heat_pump', parent: 'main', registers: { one: '1.8.0' } } }, { strom: [{ id: 'm1', name: 'Hauptzähler' }] });
t('Neuinstallation: der Standardzähler wird Z1, das Gerät neu', JSON.stringify(fresh) === JSON.stringify({ main: { meter_id: 'm1' } }), JSON.stringify(fresh));
const tou = C.suggestAssignments(slotsCascade, {
  strom: [{ id: 'a', name: 'Z1 HT', obis: '1.8.1' }, { id: 'b', name: 'Z1 NT' }, { id: 'c', name: 'Haus HT', parent_meter_id: 'a' }, { id: 'd', name: 'Haus NT', parent_meter_id: 'b', obis: '1-0:1.8.2' }],
  pv_einspeisung: [{ id: 'f', name: 'Einspeisung' }], pv_erzeugung: [{ id: 'g', name: 'PV' }, { id: 'x', name: 'Speicher', role: 'battery_charge' }],
});
t('HT/NT je Zählwerk (Kennzahl oder Name), Haushalt dahinter, Einspeisung, Erzeugung ohne den Speicher',
  JSON.stringify(tou) === JSON.stringify({ main: { meter_ids: { ht: 'a', nt: 'b' } }, feed: { meter_id: 'f' }, gen: { meter_id: 'g' }, house: { meter_ids: { ht: 'c', nt: 'd' } } }), JSON.stringify(tou));
const two = C.suggestAssignments({ main: { utility: 'strom', role: 'household', parent: null, registers: { one: '1.8.0' } } }, { strom: [{ id: 'm1', name: 'A' }, { id: 'm2', name: 'B' }] });
t('nicht eindeutig (zwei Zähler): keine Vorauswahl', JSON.stringify(two) === '{}');
const side = C.suggestAssignments({ main: { utility: 'strom', role: 'household', parent: null, registers: { one: '1.8.0' } },
  dev: { utility: 'strom', role: 'heat_pump', parent: null, registers: { one: '1.8.0' } } },
{ strom: [{ id: 'h', name: 'Haushalt' }, { id: 'w', name: 'WP', role: 'heat_pump' }, { id: 'old', name: 'Alt', active: false }] });
t('nebeneinander: Hauszähler und Zähler mit der Rolle des Geräts (inaktive zählen nicht)', side.main?.meter_id === 'h' && side.dev?.meter_id === 'w', JSON.stringify(side));

const sig = (concept, setup) => C.slotSignature(Object.fromEntries(Object.entries(C.defaultRegisters(concept, setup)).map(([k, r]) => [k,
  { utility: k === 'feed' ? 'pv_einspeisung' : k === 'gen' || k.startsWith('bat_') ? 'pv_erzeugung' : 'strom', role: k === 'dev' ? setup.device : null, parent: concept === 'behind' && k === 'dev' ? 'main' : null, registers: r }])));
t('Eingaben bleiben nur bei gleichen Stellen (anderes Gerät, HT/NT oder Aufbau: neu vorbelegt)',
  sig('behind', { device: 'heat_pump' }) === sig('behind', { device: 'heat_pump' })
  && sig('behind', { device: 'heat_pump' }) !== sig('behind', { device: 'ev_charger' })
  && sig('behind', { device: 'heat_pump' }) !== sig('behind', { device: 'heat_pump', tou: 'device' })
  && sig('behind', { device: 'heat_pump' }) !== sig('side_by_side', { device: 'heat_pump' }));

console.log('Körper für POST /api/metering-concept');
const body = C.conceptPayload('cascade', { device: 'heat_pump', pv: true, tou: 'all' },
  { main: { name: ' Z1 ', serial: 'TEST-1', obis: { ht: '1.8.1', nt: ' ' } }, feed: { name: 'Einspeisung', serial: '' }, house: { meter_ids: { ht: 'c', nt: '' } } },
  { main: { provider: 'Versorger A', start: '2025-01-01', ct: '30,5', ct_nt: '20' }, house: { provider: '', start: '2025-01-01', ct: '' } });
t('Aufbau, Namen ohne Leerraum, leere Kennzahl fällt weg', body.concept === 'cascade' && body.tou === 'all' && body.pv === true && body.slots.main.name === 'Z1'
  && JSON.stringify(body.slots.main.obis) === '{"ht":"1.8.1"}', JSON.stringify(body.slots.main));
t('Einspeisung ohne eigene Nummer: die von Z1 (Zweirichtungszähler)', body.slots.feed.serial === 'TEST-1');
t('vorhandener Zähler je Zählwerk, leere Auswahl fällt weg', JSON.stringify(body.slots.house) === '{"meter_ids":{"ht":"c"}}', JSON.stringify(body.slots.house));
t('Preise mit Komma, Vertrag ohne Eintrag fällt weg (später)', body.contracts.main.ct === 30.5 && body.contracts.main.ct_nt === 20 && !('house' in body.contracts), JSON.stringify(body.contracts));

const withInstall = C.conceptPayload('behind', { device: 'heat_pump' }, { dev: { name: 'Wärmepumpe', installed_on: '2025-01-01', initial_counter: '1234,5' } }, {});
t('Einbau und Stand beim Einbau gehen mit (Skeptiker M11), Komma als Dezimalzeichen', withInstall.slots.dev.installed_on === '2025-01-01' && withInstall.slots.dev.initial_counter === 1234.5,
  JSON.stringify(withInstall.slots.dev));
const perReg = C.conceptPayload('behind', { device: 'heat_pump', tou: 'device' }, { dev: { initial_counter: { ht: '500', nt: '300,5', x: '' } } }, {});
t('Stand beim Einbau je Zählwerk (Skeptiker R2-1), leere fallen weg', JSON.stringify(perReg.slots.dev.initial_counter) === '{"ht":500,"nt":300.5}', JSON.stringify(perReg.slots.dev));
t('ohne Einbau kein Feld (der Server nimmt den Vertragsbeginn)', !('installed_on' in C.conceptPayload('single', {}, { main: { name: 'A', installed_on: '', initial_counter: '' } }, {}).slots.main));

console.log('Abrechnungsjahr: Verbrauch = Messung, abgerechnet = Rest (Skeptiker M9)');
const BP = await import(`${JS}/lib/billing-period.js`);
const pr = BP.periodRows({ from: '2025-01-01', months: [{ ym: '2025-01', month: 1, days: 31, kwh: 300, measured_kwh: 500, kwh_cost: 60, cost: 70, advance_eur: 50 },
  { ym: '2025-02', month: 2, days: 28, kwh: 400, measured_kwh: 400, kwh_cost: 80, cost: 90, advance_eur: 50 }] }, [], 'kwh');
t('Periodenzeile: Verbrauch = Messung, abgerechnet = Menge der Verträge, Preis je abgerechneter kWh', pr[0].kwh === 500 && pr[0].billed_kwh === 300
  && Math.abs(pr[0].working_price_ct - 20) < 1e-9 && pr[1].kwh === 400 && pr[1].billed_kwh === undefined, JSON.stringify(pr.map(r => [r.kwh, r.billed_kwh, r.working_price_ct])));

console.log('Rest-Hinweis in den Vertragsansichten (Skeptiker N7)');
const RB = await import(`${JS}/lib/rest-billing.js`);
const ms = [{ id: 'p', name: 'Hauszähler' }, { id: 's', name: 'Wärmepumpe', parent_meter_id: 'p' }, { id: 'b', name: 'Garten', parent_meter_id: 'p' },
  { id: 'g1', name: 'Z1 HT', meter_group_id: 'G' }, { id: 'g2', name: 'Z1 NT', meter_group_id: 'G' }, { id: 'h1', name: 'Haus HT', parent_meter_id: 'g1', meter_group_id: 'H' }];
const cs = [{ meter_id: 's' }, { meter_id: 'b', is_shadow: true }, { meter_group_id: 'H' }];
t('Subzähler mit eigenem Vertrag ziehen ab, ein Angebot nicht', JSON.stringify(RB.deductingSubNames({ meter_id: 'p' }, ms, cs)) === '["Wärmepumpe"]');
t('Gruppenvertrag: Subzähler der Mitglieder mit Gruppenvertrag ihrer Gruppe', JSON.stringify(RB.deductingSubNames({ meter_group_id: 'G' }, ms, cs)) === '["Haus HT"]');
t('Wasser rechnet keinen Rest', RB.deductingSubNames({ meter_id: 'p' }, ms, cs, 'wasser').length === 0);

console.log('Assistent');
const W = await import(`${JS}/components/concept-wizard.js`);
t('fünf Schritte: Aufbau, Schaltbild, Zähler, Verträge, Zusammenfassung', W.CONCEPT_STEPS.join() === 'setup,layout,meters,contracts,summary');
const pre = W.setupFromHousehold({ active_utilities: ['strom', 'pv_einspeisung'] }, { strom: [{ id: 'a', name: 'WP HT', role: 'heat_pump' }], pv_erzeugung: [{ id: 'b', role: 'battery_charge' }] });
t('Vorbelegung aus dem Haushalt: PV aktiv, Wärmepumpe, Speicher, HT/NT nur am Gerät', pre.pv && pre.device === 'heat_pump' && pre.battery && pre.tou === 'device', JSON.stringify(pre));
const preAll = W.setupFromHousehold({}, { strom: [{ id: 'h', name: 'Haus HT', obis: '1.8.1' }, { id: 'a', name: 'WP HT', role: 'heat_pump' }] });
t('… HT/NT an jedem Bezugszähler, wenn auch der Haushalt Zählwerke HT/NT hat', preAll.tou === 'all', JSON.stringify(preAll));
// Aufbau aus dem Haushalt (Schaufenster: Wärmepumpe hinter dem Hauptzähler → „dahinter“)
const hh = (strom) => C.conceptFromHousehold(C.setupFromHousehold({}, { strom }), { strom });
t('Aufbau aus dem Haushalt: dahinter, Kaskade, nebeneinander, ein Zähler', hh([{ id: 'p', name: 'Haus' }, { id: 'w', name: 'WP', role: 'heat_pump', parent_meter_id: 'p' }]) === 'behind'
  && hh([{ id: 'w', name: 'Vorn', role: 'heat_pump' }, { id: 'h', name: 'Haus', parent_meter_id: 'w' }]) === 'cascade'
  && hh([{ id: 'p', name: 'Haus' }, { id: 'w', name: 'WP', role: 'heat_pump' }]) === 'side_by_side' && hh([{ id: 'p', name: 'Haus' }]) === 'single');
// Eingaben nach der ersten Vorschau und Körper wie im Assistenten (der Demo-Bau rechnet damit vor)
const slots = { main: { utility: 'strom', role: 'household', parent: null, registers: { one: '1.8.0' }, name: 'Hauszähler' },
  feed: { utility: 'pv_einspeisung', role: null, parent: null, registers: { one: '2.8.0' }, name: 'Einspeisung' } };
const input = C.initialInput(slots, { main: { meter_id: 'm1' } });
t('Eingaben: Name aus der Vorschau, vorhandener Zähler, Zählwerke', JSON.stringify(input) === JSON.stringify({
  main: { name: 'Hauszähler', serial: '', meter_id: 'm1', obis: { one: '1.8.0' } }, feed: { name: 'Einspeisung', serial: '', obis: { one: '2.8.0' } } }), JSON.stringify(input));
const wb = C.wizardPayload('single', { pv: true }, input, {}, { strom: [{ id: 'm1', devices: [{ id: 'd', serial: 'TEST-1', removed_on: null }] }] });
t('Körper: die Einspeisung bekommt die Nummer des vorhandenen Z1', wb.slots.feed.serial === 'TEST-1' && wb.slots.main.meter_id === 'm1', JSON.stringify(wb.slots));
const empty = W.setupFromHousehold({ active_utilities: ['strom'] }, { strom: [{ id: 'm', name: 'Hauptzähler' }] });
t('… und ohne Anlage: nichts vorgewählt', !empty.pv && !empty.device && empty.tou === 'none');
const meters = read('public/js/views/meters.js'), settingsView = read('public/js/views/settings.js'), setup = read('public/js/components/setup-wizard.js');
t('angeboten an Strom und PV, nicht an Gas, auch in der öffentlichen Demo (Vorschau vorberechnet)', W.conceptOffered('strom', false) && W.conceptOffered('pv_einspeisung', false)
  && !W.conceptOffered('gas', false) && W.conceptOffered('strom', true));
t('Einstiege: Zählerseite, Einstellungen, Ersteinrichtung (alle über conceptOffered)',
  (meters.match(/conceptOffered\(u\.key\)/g) || []).length === 2 && /!conceptOffered\(\) \? '' : `<div class="card settings-card" id="concept-card">/.test(settingsView)
  && /choice\('concept'/.test(setup) && /\?concept=1/.test(setup));

console.log('Doku nennt den Assistenten mit den Texten des Katalogs');
for (const [lang, file] of [['de', 'docs/anleitungen/anwendungsfaelle.md'], ['en', 'docs/en/anleitungen/anwendungsfaelle.md']]) {
  const doc = read(file).replace(/\s+/g, ' ');
  const c = JSON.parse(read(`public/locales/${lang}.json`));
  const want = [c.concept.title, c.concept.entry.title, c.concept.setup.deviceHeatPump, c.concept.setup.touAll, c.concept.layout.cascade.title, c.settings.page.utilities.title];
  t(`${lang}: Use-Case H nennt Knopf, Karte, Antworten und Aufbau wie der Assistent`, doc.includes('doc-claim: concept-wizard') && want.every(s => doc.includes(s)),
    want.filter(s => !doc.includes(s)).join(' | '));
}
// v3.9.0 (doku, Runde 3) — der Assistent ist auch in der öffentlichen Demo; die UI-Referenz zitiert
// den Satz der Demo für einen anderen Aufbau und sagt nicht mehr, er fehle dort
for (const [lang, file] of [['de', 'docs/referenz/ansichten.md'], ['en', 'docs/en/referenz/ansichten.md']]) {
  const doc = read(file).replace(/\s+/g, ' ');
  const c = JSON.parse(read(`public/locales/${lang}.json`));
  const missing = lang === 'de' ? /öffentlichen Demo fehlt (sie|er)\b/ : /not part of the public demo/;
  t(`${lang}: UI-Referenz: Assistent auch in der Demo, mit dem Satz der Demo bei anderem Aufbau`,
    W.conceptOffered('strom', true) && doc.includes(c.demo.conceptPresetsOnly) && !missing.test(doc));
  // v3.9.0 (doku, Runde 4) — „Kosten & Verträge“: der Satz am Gruppenvertrag wie im Katalog
  const via = c.contractsOverview.viaGroup.replace('{name}', '…');
  t(`${lang}: UI-Referenz zitiert den Satz am Gruppenvertrag wie der Katalog`, doc.includes(via), via);
}

console.log(`\n  metering-concepts: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
