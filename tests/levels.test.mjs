// =====================================================================
// v3.2.0 (F1019, F1020) — Nutzungsstufen und Schaubilder ohne Server.
//
// Stufen: welche Seite welche Stufe braucht, woher die Stufe kommt (Person
// vor Installation, unbekannt = Experte, damit nach dem Update nichts
// verschwindet), dass Navigation und CSS ihr folgen.
// Schaubilder: das Geld-Bild rechnet mit dem Abrechnungszeitraum
// (balance_path), nicht mit den Abschlägen der ganzen Laufzeit; das
// Wetter-Bild braucht sechs Monate mit bereinigtem Wert und Vorjahr.
//
// Aufruf: node tests/levels.test.mjs
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
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
// Kataloge aus public/locales statt über HTTP
global.fetch = async (url) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json$/);
  if (!m) return new Response('{}', { status: 404 });
  return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
};

const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const L = await import(`${JS}/lib/levels.js`);
const { sectionPages } = await import(`${JS}/lib/nav-model.js`);
const { moneyHtml, weatherHtml, timelineHtml } = await import(`${JS}/components/explainer.js`);

// ── Stufen ──────────────────────────────────────────────────────────────
console.log('Nutzungsstufen');
t('ohne Angabe Experte — Bestand verliert nichts', L.resolveLevel({}, null) === 'expert');
t('unbekannter Wert wird Experte', L.resolveLevel({ ui_level: 'guru' }, null) === 'expert');
t('Stufe der Installation', L.resolveLevel({ ui_level: 'beginner' }, null) === 'beginner');
t('Stufe der Person vor der Installation',
  L.resolveLevel({ ui_level: 'expert' }, { user: { prefs: { ui_level: 'advanced' } } }) === 'advanced');

let events = 0;
window.addEventListener('et:levelchange', () => events++);
L.applyLevel('beginner');
t('applyLevel setzt html[data-level]', document.documentElement.getAttribute('data-level') === 'beginner');
L.applyLevel('beginner');
t('Ereignis nur bei echtem Wechsel', events === 1, `${events}`);

const allowed = (level, view) => { L.applyLevel(level); return L.viewAllowed(view); };
t('Einsteiger: Übersicht und Verträge ja, Wechsel und Analyse nein',
  allowed('beginner', 'dashboard') && allowed('beginner', 'contracts-overview')
  && !allowed('beginner', 'tariffs') && !allowed('beginner', 'analysis') && !allowed('beginner', 'meters'));
t('Erfahren: Wechsel, Rechnung prüfen, Zähler — Experten-Einstellungen nicht',
  allowed('advanced', 'tariffs') && allowed('advanced', 'bill-check') && allowed('advanced', 'meters')
  && !allowed('advanced', 'settings:expert') && !allowed('advanced', 'settings:access'));
t('Experte: alles', Object.keys(L.VIEW_LEVEL).every(v => allowed('expert', v)));
t('nächste Stufe', L.nextLevel('beginner') === 'advanced' && L.nextLevel('advanced') === 'expert' && L.nextLevel('expert') === null);

L.applyLevel('beginner');
const costs = sectionPages('costs', { utilities: [{ key: 'strom', label: 'Strom', supports_bill_check: true }], tenant: true }).map(p => p.view);
t('Navigation folgt der Stufe (Einsteiger: nur Verträge)', costs.join() === 'contracts-overview', costs.join());
L.applyLevel('expert');
const costsX = sectionPages('costs', { utilities: [{ key: 'strom', label: 'Strom', supports_bill_check: true }], tenant: true }).map(p => p.view);
t('… Experte: alle vier', costsX.join() === 'contracts-overview,tariffs,bill-check,tenancy', costsX.join());

L.applyLevel('beginner');
L.dismiss('battery');
t('Ablehnung gilt je Stufe', L.isDismissed('battery') && (L.applyLevel('advanced'), !L.isDismissed('battery')));

const css = readFileSync(resolve(ROOT, 'public', 'css', 'app.css'), 'utf8');
t('CSS blendet je Stufe aus',
  css.includes('html[data-level="beginner"] [data-min-level="advanced"]')
  && css.includes('html[data-level="beginner"] [data-min-level="expert"]')
  && css.includes('html[data-level="advanced"] [data-min-level="expert"]'));

// ── Schaubilder ─────────────────────────────────────────────────────────
console.log('Schaubilder');
const money = moneyHtml({
  balance_path: [{ ym: '2026-01', paid: 100, cost: 90 }, { ym: '2026-12', paid: 1200, cost: 1000 }],
  advance_paid: 5000, energy_cost_to_date: 600, base_to_date: 150, bonus_to_date: 0, projected_end_balance: -200,
});
t('Geld: letzter Punkt des Abrechnungszeitraums, nicht die Abschläge der Laufzeit',
  money.includes('1.200,00') && money.includes('1.000,00') && !money.includes('5.000'));
t('Geld: Guthaben in Worten', /Guthaben/.test(money));
t('Geld: ohne Saldo-Verlauf kein Bild', moneyHtml({ projected_end_balance: 0 }) === '');

const month = (y, m, kwh, hdd) => ({ ym: `${y}-${String(m).padStart(2, '0')}`, kwh, hdd, heat_adjusted: kwh * 1.02 });
const twoYears = [];
for (let m = 1; m <= 12; m++) twoYears.push(month(2025, m, 1000, 300));
for (let m = 1; m <= 12; m++) twoYears.push(month(2026, m, 900, 330));
const weather = weatherHtml(twoYears);
t('Wetter: Bild mit Vorjahr', weather.includes('ex-weather'));
t('Wetter: kälter und weniger verbraucht → bereinigt weniger', /ex-badge--less/.test(weather));
// Vorjahr vollständig, dieses Jahr nur vier Monate mit bereinigtem Wert → vier Paare
const fewAdjusted = twoYears.map((r, i) => (i < 12 || i >= 20 ? r : { ...r, heat_adjusted: null }));
t('Wetter: unter sechs Monaten mit bereinigtem Wert kein Bild', weatherHtml(fewAdjusted) === '');
const sixAdjusted = twoYears.map((r, i) => (i < 12 || i >= 18 ? r : { ...r, heat_adjusted: null }));
t('Wetter: ab sechs Monaten ein Bild', weatherHtml(sixAdjusted).includes('ex-weather'));

const running = timelineHtml({ start: '2024-01-01', is_open_ended: true }, '2026-10-09');
t('Zeitstrahl: weiterlaufender Vertrag ohne Frist', running.includes('läuft weiter'));
const fixed = timelineHtml({ start: '2025-01-01', end: '2026-12-31', cancel_by: '2026-11-30' }, '2026-10-09');
t('Zeitstrahl: Kündigen bis', fixed.includes('ex-mark--cancel') && fixed.includes('30.11.2026'));

console.log(`\nlevels.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
