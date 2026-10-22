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
const { moneyHtml, energyHtml, weatherHtml, timelineHtml } = await import(`${JS}/components/explainer.js`);

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
// v3.6.0 — die Seite lenkt Verhalten: für alle; was sie je Stufe zeigt, prüft der Render-Test
t('Flexibilität für alle Stufen (seit v3.6.0)', allowed('beginner', 'flexibility') && allowed('advanced', 'flexibility') && allowed('expert', 'flexibility'));
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

// ── v3.7.0 (Paket reifegrad) — jede Seite hat eine Stufe, die Doku sagt dieselbe ──
console.log('Stufen: Seiten, Doku, Vorschläge');
const src = (rel) => readFileSync(resolve(ROOT, rel), 'utf8');
const routerSrc = src('public/js/router.js');
const viewBlock = routerSrc.slice(routerSrc.indexOf('const VIEWS = {'), routerSrc.indexOf('};', routerSrc.indexOf('const VIEWS = {')));
const routerViews = [...viewBlock.matchAll(/^\s*'([a-z-]+)':\s*\{\s*section:/gm)].map(m => m[1]);
const settingsPages = JSON.parse((src('public/js/views/settings.js').match(/export const PAGES = (\[[^\]]+\])/)?.[1] || '[]').replace(/'/g, '"'));
const pageKeys = [...routerViews.filter(v => v !== 'settings'), ...settingsPages.map(p => 'settings:' + p)];
t('Router-Ansichten und Einstellungsseiten gelesen', routerViews.length >= 20 && settingsPages.length >= 8, `${routerViews.length} + ${settingsPages.length}`);
const missingLevel = pageKeys.filter(k => !(k in L.VIEW_LEVEL));
const staleLevel = Object.keys(L.VIEW_LEVEL).filter(k => !pageKeys.includes(k));
t('jede Seite des Routers hat eine Stufe in VIEW_LEVEL (keine gewachsene Zuordnung)', !missingLevel.length,
  missingLevel.length ? `fehlt: ${missingLevel.join(', ')} — Eintrag in levels.js und Zeile in docs/referenz/stufen.md` : `${pageKeys.length} Seiten`);
t('… und keine Stufe für eine Seite, die es nicht gibt', !staleLevel.length, staleLevel.join(', '));
t('… nur gültige Stufen', Object.values(L.VIEW_LEVEL).every(l => L.LEVELS.includes(l)));
t('viewLevel: Verbrauchsart wie „utility“, unbekannte Seite für alle',
  L.viewLevel('utility:gas') === L.VIEW_LEVEL.utility && L.viewLevel('gibt-es-nicht') === 'beginner' && L.viewLevel('settings:system') === 'expert');

// Funktionstabelle: Zeilen mit Sprungmarke <a id="level-…"> — die erste Spalte
// ohne „–“ ist die Mindeststufe. Seiten gegen VIEW_LEVEL, Teile von Seiten
// gegen den Quelltext (Marke im selben Tag bzw. in derselben Zeile).
const RANKS = { beginner: 0, advanced: 1, expert: 2 };
const maxLevel = (...ls) => ls.reduce((a, b) => (RANKS[b] > RANKS[a] ? b : a), 'beginner');
function docLevels(rel) {
  const rows = {}, problems = [];
  for (const line of src(rel).split('\n')) {
    if (!line.startsWith('|') || !line.includes('<a id="level-')) continue;
    const cells = line.split('|').slice(1, -1).map(c => c.trim());
    const shown = cells.slice(1, 4).map(c => c !== '–');
    const first = shown.indexOf(true);
    if (first < 0 || shown.slice(first).includes(false)) problems.push(`${rel}: Spalten nicht von links nach rechts: ${cells[0].slice(0, 60)}`);
    for (const m of cells[0].matchAll(/<a id="(level-[a-z0-9-]+)"><\/a>/g)) {
      if (m[1] in rows) problems.push(`${rel}: ${m[1]} doppelt`);
      rows[m[1]] = L.LEVELS[Math.max(0, first)];
    }
  }
  return { rows, problems };
}
const V = 'public/js/views/';
const lv = '(?: data-min-level="(\\w+)")?';
const re = (s) => new RegExp(s.replace('§LV§', lv));
/** Teile von Seiten: Sprungmarke → Seite und Marken im Quelltext (Gruppe 1 = Stufe, fehlt = für alle). */
const ELEMENTS = {
  'level-el-notice': { page: 'contracts', at: [[V + 'contracts.js', re('data-role="notice"§LV§')]] },
  'level-el-fw-prices': { page: 'contracts', at: [
    [V + 'contracts.js', /\{ key: 'capacity_prices',[^}\n]*?(?:level: '(\w+)'[^}\n]*)?\}/],
    [V + 'contracts.js', /\{ key: 'metering_prices',[^}\n]*?(?:level: '(\w+)'[^}\n]*)?\}/],
    [V + 'contracts.js', re('data-role="fw-plant"§LV§')]] },
  'level-el-contract-terms': { page: 'contracts', at: [
    [V + 'contracts.js', re('<div class="field field--s"§LV§>\\s*<label>\\$\\{t\\(\'contracts\\.modal\\.priceGuarantee\'\\)')],
    [V + 'contracts.js', re('<div class="field field--m"§LV§>\\s*<label>\\$\\{t\\(\'contracts\\.modal\\.noticeMode\'\\)')],
    [V + 'contracts.js', re('<div class="field field--s"§LV§>\\s*<label>\\$\\{t\\(\'contracts\\.modal\\.minTermEnd\'\\)')],
    [V + 'contracts.js', re('<div class="field field--full"§LV§>\\s*<label class="check">\\s*<input type="checkbox" name="auto_renews"')]] },
  'level-el-price-changes': { page: 'contracts', at: [
    [V + 'contracts.js', re('data-action="add-row"§LV§')],
    [V + 'contracts.js', re('data-section="bonus"§LV§')],
    [V + 'contracts.js', re('data-section="special"§LV§')]] },
  'level-el-contract-expert': { page: 'contracts', at: [
    [V + 'contracts.js', re('data-role="fw-factors"§LV§')],
    [V + 'contracts.js', re('<div class="field field--m"§LV§><label>\\$\\{t\\(\'contracts\\.fw\\.primaryEnergy\'\\)')],
    [V + 'contracts.js', re('data-role="member-prices"§LV§')],
    [V + 'contracts.js', /const GRID_GROUP = \{[^}\n]*?(?:level: '(\w+)'[^}\n]*)?\}/],
    [V + 'contracts.js', re('data-role="grid-tou"§LV§')],
    [V + 'contracts.js', re('<fieldset class="fgroup"§LV§><legend>\\$\\{t\\(\'contracts\\.revenue\\.title\'\\)')],
    [V + 'contracts.js', re('<label class="btn btn--sm btn--ghost"§LV§>\\$\\{t\\(\'contracts\\.priceImport\\.button\'\\)')],
    [V + 'contracts.js', re('<span class="contract-card__counts"§LV§>')]] },
  'level-el-meter-plant': { page: 'meters', at: [[V + 'meters.js', re('data-role="pv-plant"§LV§')], [V + 'meters.js', re('data-role="hp-link"§LV§')]] },
  'level-el-meter-expert': { page: 'meters', at: [
    [V + 'meters.js', re('<div class="field"§LV§>\\s*<label>\\$\\{t\\(\'meters\\.modal\\.group\'\\)')],
    [V + 'meters.js', re('data-section="baseline"§LV§')],
    [V + 'meters.js', re('<button class="btn btn--sm btn--ghost"§LV§ data-import-series=')]] },
  'level-el-dash-explainers': { page: 'dashboard', at: [[V + 'dashboard.js', /atLeast\('(\w+)'\) \? `<section class="card dash-explainers"/]] },
  'level-el-utility-advanced': { page: 'utility', at: [
    [V + 'utility.js', re('<div class="card"§LV§>\\s*<div class="card__title">\\$\\{t\\(\'utility\\.cards\\.monthlyTable\'')],
    [V + 'utility.js', re('<span class="card__title-action seg"§LV§ role="group"')],
    [V + 'utility.js', re('<div class="kpi c-violet"§LV§>\\s*<div class="kpi__label">\\$\\{t\\(\'utility\\.kpi\\.co2Avoided\'')],
    [V + 'utility.js', re('<div class="kpi c-violet"§LV§>\\s*<div class="kpi__label">\\$\\{t\\(\'utility\\.kpi\\.co2\', ')],
    [V + 'utility.js', re('\\$\\{pvFlowRows\\.length \\? `<div§LV§>\\$\\{pvFlowHtml')]] },
  'level-el-pv-extras': { page: 'utility', at: [[V + 'utility.js', re('data-role="pv-extras"§LV§')]] },
  'level-el-heat-pump': { page: 'utility', at: [[V + 'utility.js', re('data-role="heat-pump"§LV§')]] },
  'level-el-utility-expert': { page: 'utility', at: [[V + 'utility.js', re('<div§LV§>\\$\\{dhwNote\\(')], [V + 'utility.js', re('data-role="co2-cost"§LV§')]] },
  'level-el-baseline': { page: 'analysis', at: [[V + 'analysis.js', re('<div§LV§>\\$\\{renderBaselineBlock\\(')]] },
  'level-el-market': { page: 'tariffs', at: [
    [V + 'tariff.js', re('<section id="t-market"§LV§')],
    [V + 'tariff.js', re('<div class="field field--full"§LV§><label class="check"><input type="checkbox" id="s-dyn"')]] },
  'level-el-bill-check-link': { page: 'utility', at: [[V + 'utility.js', re('<div class="card card--link"§LV§ data-role="bill-check-link"')]] },
  'level-el-co2-split': { page: 'tenancy', at: [[V + 'tenancy.js', re('data-role="co2-split"§LV§')]] },
  'level-el-ev-report': { page: 'utility', at: [[V + 'utility.js', re('data-role="ev-report"§LV§')]] },
  'level-el-evcc': { page: 'utility', at: [[V + 'utility.js', re('data-role="evcc"§LV§')]] },
  'level-el-ws-advanced': { page: 'forecast', at: [[V + 'forecast.js', re('<div class="ws-group"§LV§>\\s*<h3>\\$\\{esc\\(t\\(\'scenario\\.group\\.advanced\'\\)\\)\\}')]] },
  'level-el-ws-results': { page: 'forecast', at: [
    [V + 'forecast.js', re('<div class="card"§LV§>\\s*<h3 class="card__title">\\$\\{esc\\(t\\(\'scenario\\.chart\\.monthTitle\'')],
    [V + 'forecast.js', re('<div class="card"§LV§ id="ws-ranking"')]] },
  'level-el-ws-expert': { page: 'forecast', at: [
    [V + 'forecast.js', re('<div class="ws-group"§LV§>\\s*<h3>\\$\\{esc\\(t\\(\'scenario\\.group\\.expert\'\\)\\)\\}')],
    [V + 'forecast.js', re('<div class="card"§LV§ id="ws-models"')],
    [V + 'forecast.js', re('<div class="card"§LV§ id="ws-opt"')],
    [V + 'forecast.js', re('id="ws-csv"§LV§')]] },
  'level-el-fc-classic': { page: 'forecast', at: [
    [V + 'forecast.js', re('<div class="field"§LV§>\\s*<label for="model">')],
    [V + 'forecast.js', re('<div class="field"§LV§>\\s*<label for="co2-scenario">')]] },
  'level-el-flex-advanced': { page: 'flexibility', at: [
    [V + 'flexibility.js', re('<div class="flex-week-wrap"§LV§>')],
    [V + 'flexibility.js', re('<div class="card" id="flex-windows"§LV§>')],
    [V + 'flexibility.js', re('<div class="card flex-solar"§LV§>')],
    [V + 'flexibility.js', re('<div class="card flex-levers"§LV§>')]] },
  'level-el-flex-expert': { page: 'flexibility', at: [
    [V + 'flexibility.js', re('<section class="flex-check"§LV§>')],
    [V + 'flexibility.js', re('<div class="card"§LV§>\\s*<h2 class="card__title">\\$\\{esc\\(t\\(\'flex\\.series\\.title\'')]] },
  'level-el-settings-general': { page: 'settings:general', at: ['dashboard', 'contractReminders'].map(g => [V + 'settings.js', group(g)]) },
  'level-el-settings-household': { page: 'settings:household', at: ['reference', 'water'].map(g => [V + 'settings.js', group(g)]) },
  'level-el-settings-utilities': { page: 'settings:utilities', at: ['physical', 'delivery', 'flex', 'pv'].map(g => [V + 'settings.js', group(g)]) },
  'level-el-settings-co2': { page: 'settings:utilities', at: [[V + 'settings.js', group('co2')]] },
};
function group(gkey) { return new RegExp(`\\{ gkey: '${gkey}',[^\\n]*?(?:level: '(\\w+)'[^\\n]*?)?fields: \\[`); }

const pageAnchor = (k) => 'level-' + k.replace(':', '-');
const anchorPage = Object.fromEntries(pageKeys.map(k => [pageAnchor(k), k]));
for (const rel of ['docs/referenz/stufen.md', 'docs/en/referenz/stufen.md']) {
  const { rows, problems } = docLevels(rel);
  t(`${rel}: Tabelle lesbar, Spalten von links nach rechts`, !problems.length && Object.keys(rows).length > 30, problems.join(' · ') || `${Object.keys(rows).length} Sprungmarken`);
  const unknown = Object.keys(rows).filter(a => !(a in anchorPage) && !(a in ELEMENTS));
  t(`${rel}: jede Sprungmarke ist eine Seite oder ein bekannter Teil`, !unknown.length, unknown.join(', '));
  const noRow = pageKeys.filter(k => !(pageAnchor(k) in rows));
  t(`${rel}: jede Seite hat ihre Zeile`, !noRow.length, noRow.length ? `fehlt: ${noRow.map(pageAnchor).join(', ')}` : '');
  const wrongPage = pageKeys.filter(k => rows[pageAnchor(k)] && rows[pageAnchor(k)] !== L.viewLevel(k));
  t(`${rel}: jede Seite mit der Stufe aus VIEW_LEVEL`, !wrongPage.length, wrongPage.map(k => `${k}: Doku ${rows[pageAnchor(k)]}, Code ${L.viewLevel(k)}`).join(' · '));
  const wrongEl = [];
  for (const [a, { page, at }] of Object.entries(ELEMENTS)) {
    if (!(a in rows)) { wrongEl.push(`${a} fehlt in der Doku`); continue; }
    for (const [file, rx] of at) {
      const m = rx.exec(src(file));
      if (!m) { wrongEl.push(`${a}: Marke ${rx} nicht in ${file}`); continue; }
      const code = maxLevel(m[1] || 'beginner', L.viewLevel(page));
      if (code !== rows[a]) wrongEl.push(`${a}: Doku ${rows[a]}, Code ${code} (${file})`);
    }
  }
  t(`${rel}: Teile von Seiten mit der Stufe aus dem Code`, !wrongEl.length, wrongEl.join(' · '));
}
const de = docLevels('docs/referenz/stufen.md').rows, en = docLevels('docs/en/referenz/stufen.md').rows;
t('Funktionstabelle: Deutsch und Englisch mit denselben Sprungmarken und Stufen',
  JSON.stringify(Object.entries(de).sort()) === JSON.stringify(Object.entries(en).sort()));
const docsJs = src('public/js/lib/docs.js');
t('Handbuch-Verweis „levels“ auf die Funktionstabelle (Einstellungen, Assistent)', /levels:\s*\['docs\/referenz\/stufen\.md',\s*'docs\/en\/referenz\/stufen\.md'\]/.test(docsJs)
  && /docUrl\('levels'\)/.test(src('public/js/views/settings.js')) && /docUrl\('levels'\)/.test(src('public/js/components/setup-wizard.js')));
t('Einstieg verlinkt die Funktionstabelle', /\.\.\/referenz\/stufen\.md/.test(src('docs/einstieg/einrichtung.md')) && /\.\.\/referenz\/stufen\.md/.test(src('docs/en/einstieg/einrichtung.md')));

// Vorschlag aus dem Haushalt
const utils = [{ key: 'strom', accounting_kind: 'consumption' }, { key: 'pv_erzeugung', accounting_kind: 'generation' },
  { key: 'pv_einspeisung', accounting_kind: 'feed_in' }, { key: 'waerme', accounting_kind: 'consumption' }, { key: 'gas', accounting_kind: 'consumption' }];
const mieterin = [{ utility: 'strom', role: null }];
const eigenheim = [{ utility: 'strom', role: null }, { utility: 'strom', role: 'ev_charger', is_sub_meter: true }, { utility: 'pv_erzeugung' },
  { utility: 'strom', role: 'battery_charge' }, { utility: 'waerme', role: 'heat_pump_output' }];
t('Vorschlag: Mieterin nur mit Strom — kein Anlass', L.suggestReasons('beginner', mieterin, { utilities: utils }).length === 0);
const rs = L.suggestReasons('beginner', eigenheim, { utilities: utils });
t('Vorschlag: Einsteiger mit PV, Speicher, Wärmepumpe und Wallbox → Anlässe für „Erfahren“',
  ['pv', 'battery', 'heatPump', 'wallbox'].every(r => rs.includes(r)) && rs.indexOf('pv') === 0, rs.join(','));
t('Vorschlag: PV über die Abrechnungsart, nicht über den Namen',
  L.suggestReasons('beginner', [{ utility: 'pv_erzeugung' }], { utilities: [{ key: 'pv_erzeugung', accounting_kind: 'consumption' }] }).length === 0
  && L.suggestReasons('beginner', [{ utility: 'solar' }], { utilities: [{ key: 'solar', accounting_kind: 'feed_in' }] }).join() === 'pv');
t('Vorschlag: nur aktive Verbrauchsarten zählen',
  L.suggestReasons('beginner', [{ utility: 'gas', role: 'ev_charger', is_sub_meter: true }, { utility: 'gas', external_id: 'x' }, { utility: 'gas' }],
    { utilities: [{ key: 'strom' }] }).length === 0);
t('Vorschlag: Erfahren → Experte nur mit Viertelstundenwerten',
  L.suggestReasons('advanced', eigenheim, { utilities: utils }).length === 0
  && L.suggestReasons('advanced', eigenheim, { utilities: utils, intervals: 2 }).join() === 'quarterHours');
t('Vorschlag: Experte bekommt keinen', L.suggestReasons('expert', eigenheim, { utilities: utils, intervals: 2 }).length === 0);
t('Vorschlag: jeder Anlass hat einen Text in jeder Sprache',
  Object.values(L.SUGGEST_REASONS).flat().every(r => ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'].every(l => {
    const c = JSON.parse(src(`public/locales/${l}.json`)); return !!c.level?.reason?.[r];
  })));
L.applyLevel('beginner');
localStorage.removeItem('et-level-hints');
t('gezeigt = nicht noch einmal (je Stufe)', !L.isDismissed('pv') && (L.markShown(['pv', 'wallbox']), L.isDismissed('pv') && L.isDismissed('wallbox'))
  && (L.applyLevel('advanced'), !L.isDismissed('pv')));

// ── v3.4.0 (F1024) — Hinweise › Erledigt ────────────────────────────────
console.log('Erledigt mit Verlauf');
t('Tab „Erledigt" ab Erfahren', !allowed('beginner', 'activity') && allowed('advanced', 'activity'));
L.applyLevel('beginner');
const hintsB = sectionPages('hints').map(p => p.view);
L.applyLevel('advanced');
const hintsA = sectionPages('hints').map(p => p.view);
t('… Navigation: Einsteiger ohne, Erfahren mit', !hintsB.includes('activity') && hintsA.join() === 'reminders,recommendations,activity', hintsA.join());
const A = await import(`${JS}/lib/activity.js`);
const entry = { title_key: 'calendar.event.cancelBy', params: { label: 'Strom', provider: 'Testwerk' }, title: 'alt' };
t('Titel in der Sprache der Oberfläche (title_key), nicht die Momentaufnahme', A.entryTitle(entry).includes('Kündigung') && !A.entryTitle(entry).includes('alt'));
t('… ohne Schlüssel die Momentaufnahme', A.entryTitle({ title: 'Empfehlung X' }) === 'Empfehlung X');
t('Grund mit Datum in Landesschreibweise',
  A.reasonText({ key: 'activity.reason.successor', params: { provider: 'Neuwerk', date: '2027-01-01' } }).includes('01.01.2027'));
t('wer: Person, ehemalige Person, Schlüssel, Integration, ohne Anmeldung niemand',
  A.byText({ type: 'person', id: 'u1', name: 'Anna' }) === 'Anna' && A.byText({ type: 'person', former: true }) !== ''
  && A.byText({ type: 'key', name: 'Skript' }).includes('Skript') && A.byText({ type: 'integration', name: 'home_assistant' }) === 'Home Assistant'
  && A.byText(null) === '');
t('Bereiche: jede Agenda-Art hat einen', ['reminder', 'cancel_by', 'term_end', 'price_increase', 'tank_reorder', 'reading_due',
  'tenancy_statement_due', 'objection_deadline', 'co2_claim_deadline', 'contract', 'recommendation'].every(k => A.AREA_KEYS.includes(A.AREAS[k])));
t('Personen im Verlauf (für den Filter)', A.personsIn([{ by: { type: 'person', id: 'u2', name: 'Ben' } }, { by: { type: 'person', id: 'u1', name: 'Anna' } },
  { by: null }, { by: { type: 'person', former: true } }]).map(p => p.name).join() === 'Anna,Ben');

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
// v3.7.0 (Nachlauf, V3) — Gütesperre nicht bestanden: keine bereinigte Aussage, wie im Jahresbericht
{
  const poor = weatherHtml(twoYears, { quality: { model_quality: 'poor', ratio: 0.36 } });
  t('Wetter: Gütesperre fällt — „lässt sich nicht belegen“ statt „wirklich weniger verbraucht“',
    /lässt sich nicht belegen: Das Heizmodell passt schlecht/.test(poor) && !/Du hast wirklich (weniger|mehr) verbraucht|Witterungsbereinigt|witterungsbereinigt [−+-]/.test(poor)
    && /bereinigt: nicht belegbar/.test(poor) && !/ex-badge--less/.test(poor), poor.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 300));
  t('Wetter: bestandene Gütesperre wie bisher', /wirklich weniger verbraucht/.test(weatherHtml(twoYears, { quality: { model_quality: 'good', ratio: 0.05 } })));
}

// v3.4.0 (F1026) — Energiefluss: eine Jahreszeile reicht (Druckansicht), die
// ganze Antwort schaltet Jahr und Monat; Einzelprüfungen in explainer.test.mjs
const pvYear = { year: 2025, erzeugung_kwh: 6500, einspeisung_kwh: 4200, eigenverbrauch_kwh: 2300, bezug_kwh: 3800 };
const energy = energyHtml(pvYear);
t('Energie: Bild aus einer Jahreszeile', energy.includes('ex-energy') && energy.includes('Sonne') && !energy.includes('ef-period'));
t('Energie: Autarkie aus dem Fluss (ohne Speicher wie bisher)', energy.includes('38') && /Autarkie: 38/.test(energy));
t('Energie: mit Monaten umschaltbar', energyHtml({ yearly: [pvYear], monthly: [{ ym: '2025-06', year: 2025, flow: null }, { ym: '2025-07', year: 2025, erzeugung_kwh: 900, einspeisung_kwh: 600, bezug_kwh: 200 }] }).includes('data-ef-scope="month"'));
t('Energie: ohne Erzeugung kein Bild', energyHtml({ year: 2025, erzeugung_kwh: 0, bezug_kwh: 3000 }) === '');

const running = timelineHtml({ start: '2024-01-01', is_open_ended: true }, '2026-10-09');
t('Zeitstrahl: weiterlaufender Vertrag ohne Frist', running.includes('läuft weiter'));
const fixed = timelineHtml({ start: '2025-01-01', end: '2026-12-31', cancel_by: '2026-11-30' }, '2026-10-09');
t('Zeitstrahl: Kündigen bis', fixed.includes('ex-mark--cancel') && fixed.includes('30.11.2026'));

console.log(`\nlevels.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
