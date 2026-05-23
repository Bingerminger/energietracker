// Browser-naher Test: lädt die echten View-ES-Module in JSDOM, ruft
// render() gegen den laufenden PHP-Backend-Server auf und prüft, dass
// echter DOM entsteht (kein Loading-Spinner, keine Exception). Fängt
// ReferenceErrors, kaputte DOM-Queries, Template- und Event-Bugs, die
// reine Backend-Shape-Tests NICHT sehen.
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const ROOT = resolve(__dir, '..', 'public', 'js');
let pass = 0, fail = 0;
const t = (n, ok, info = '') => {
  if (ok) { pass++; console.log(`  \u2713 ${n}${info ? ' \u2014 ' + info : ''}`); }
  else    { fail++; console.log(`  \u2717 ${n}${info ? ' \u2014 ' + info : ''}`); }
};

function freshDom() {
  const dom = new JSDOM(
    `<!DOCTYPE html><body data-app-version="1.3.0">
       <nav id="primary-nav"></nav>
       <main id="view"></main>
       <div id="toast-stack"></div>
       <div id="modal-root"></div>
     </body>`,
    { url: 'http://127.0.0.1:8899/', pretendToBeVisual: true }
  );
  // JSDOM-Globals für die Module bereitstellen
  for (const k of ['window', 'document', 'location',
                    'HTMLElement', 'Node', 'CustomEvent', 'Event',
                    'getComputedStyle']) {
    try { global[k] = dom.window[k]; }
    catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
  }
  // navigator ist in Node 22 ein read-only Getter → via defineProperty
  try {
    Object.defineProperty(global, 'navigator', {
      value: dom.window.navigator, configurable: true, writable: true,
    });
  } catch {}
  // fetch in die JSDOM-Window spiegeln. Node-natives fetch löst KEINE
  // relativen URLs auf; ein Browser tut das gegen die Seiten-URL.
  // api.js nutzt BASE='api.php' (relativ) — daher hier wie im Browser
  // gegen die JSDOM-Basis (dom.window.location) auflösen.
  const BASE_URL = (dom.window.location && dom.window.location.href)
    || 'http://127.0.0.1:8899/';
  const nativeFetch = global.fetch;
  const browserLikeFetch = (input, init) => {
    try {
      if (typeof input === 'string' && !/^https?:\/\//i.test(input)) {
        input = new URL(input, BASE_URL).href;
      }
    } catch {}
    return nativeFetch(input, init);
  };
  try { dom.window.fetch = browserLikeFetch; }
  catch { Object.defineProperty(dom.window, 'fetch', { value: browserLikeFetch, configurable: true }); }
  // Importierte ES-Module (api.js) lösen fetch aus dem GLOBAL-Scope auf,
  // nicht aus window. Absolute URLs passieren den Wrapper unverändert,
  // der Modulgraph-Crawl (absolute URLs) bleibt damit intakt.
  global.fetch = browserLikeFetch;
  try { dom.window.matchMedia = () => ({ matches: false, addEventListener(){}, removeEventListener(){} }); } catch {}
  // Chart.js kommt im Browser per CDN-<script> als globales `Chart`.
  // JSDOM hat kein Canvas-2D — daher ein No-Op-Stub mit der von
  // components/chart.js genutzten Oberfläche (defaults, Konstruktor).
  const ChartStub = function () { return { destroy(){}, update(){}, resize(){}, data:{}, options:{} }; };
  ChartStub.defaults = {
    color: '', borderColor: '',
    font: { family: '', size: 12 },
    plugins: { legend: { labels: { color: '' } },
               tooltip: { backgroundColor: '', borderColor: '' } },
    scale: { grid: {}, ticks: {} },
  };
  ChartStub.register = () => {};
  try { dom.window.Chart = ChartStub; } catch {}
  try { global.Chart = ChartStub; }
  catch { Object.defineProperty(global, 'Chart', { value: ChartStub, configurable: true, writable: true }); }
  // JSDOM bringt eigenes localStorage mit — nicht überschreiben.
  global.confirm = () => true;
  try { dom.window.confirm = global.confirm; } catch {}
  return dom;
}

async function renderView(modPath, params = []) {
  const dom = freshDom();
  const view = global.document.getElementById('view');
  const mod = await import(modPath + '?t=' + Date.now());
  const cleanup = await mod.render(view, params);
  return { dom, view, cleanup };
}

(async () => {
  // ── 0. Modulgraph-Vorprüfung (Bug-1-Regression): app.js + ALLE
  //    transitiven Importe müssen über HTTP laden — ein falscher
  //    relativer Pfad (z. B. ./state.js in lib/) bricht sonst die
  //    ganze App, „Lade…" bleibt stehen. JSDOM-Direktimport sieht das
  //    NICHT, daher hier explizit gegen den Server prüfen.
  try {
    const seen = new Set(), bad = [];
    async function crawl(u) {
      if (seen.has(u)) return; seen.add(u);
      const r = await global.fetch(u);
      if (!r.ok) { bad.push(u + ' → HTTP ' + r.status); return; }
      const ct = r.headers.get('content-type') || '';
      if (!ct.includes('javascript')) { bad.push(u + ' → MIME ' + ct); return; }
      const src = await r.text();
      const re = /from\s+["']([^"']+)["']/g; let m;
      while ((m = re.exec(src))) {
        if (m[1].startsWith('.')) await crawl(new URL(m[1], u).href);
      }
    }
    await crawl('http://127.0.0.1:8899/public/js/app.js');
    t('Modulgraph: app.js + alle Importe laden via HTTP', bad.length === 0,
      bad.length ? bad.join('; ') : seen.size + ' Module');
  } catch (e) { t('Modulgraph-Vorprüfung', false, e.message); }

  // ── 1. Empfehlungen ──
  try {
    const { view } = await renderView(`${ROOT}/views/recommendations.js`);
    const html = view.innerHTML;
    t('recommendations: render ohne Exception', html.length > 50 && !html.includes('Lade Empfehlungen'));
    t('recommendations: Filter-Buttons vorhanden', view.querySelectorAll('.seg__btn').length >= 4);
    // Interaktion: Filter „Achtung" klicken — darf nicht werfen
    const warnBtn = [...view.querySelectorAll('.seg__btn')].find(b => b.dataset.sev === 'warning');
    if (warnBtn) { warnBtn.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true })); }
    t('recommendations: Filter-Klick ohne Exception', true);
  } catch (e) { t('recommendations: render', false, e.message); }

  // ── F1004 (v1.6.0): zentrale Zählerstand-Erfassung ──
  try {
    const { view } = await renderView(`${ROOT}/views/readings-entry.js`);
    const html = view.innerHTML;
    t('readings-entry: render ohne Exception',
      html.includes('Zählerstände') && !html.includes('Lade Zähler'));
    const cards = view.querySelectorAll('.reading-card');
    t('readings-entry: Zähler-Karten pro kumulativer Utility',
      cards.length >= 1, `${cards.length} Karten`);
    // numerische Tastatur fürs iPhone
    const counter = view.querySelector('[data-role="counter"]');
    t('readings-entry: Counter-Input mit inputmode=decimal',
      counter?.getAttribute('inputmode') === 'decimal');
    // Datum-Input default heute
    const date = view.querySelector('[data-role="date"]');
    t('readings-entry: Datum-Input vorhanden + ISO-Default',
      date?.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(date?.value || ''));
    // sticky Save sichtbar (rows > 0)
    const sticky = view.querySelector('[data-role="sticky"]');
    t('readings-entry: Sticky-Save-Action sichtbar', sticky && !sticky.hidden);
    // Scope: keine Heizöl/Pellets-Karten
    const utils = [...cards].map(c => c.dataset.utility);
    t('readings-entry: keine Delivery-Utilities in Karten',
      !utils.includes('heizoel') && !utils.includes('pellets'),
      `utilities=${[...new Set(utils)].join(',')}`);
  } catch (e) { t('readings-entry: render', false, e.message); }

  // ── 2. Termine ──
  try {
    const { view } = await renderView(`${ROOT}/views/reminders.js`);
    const html = view.innerHTML;
    t('reminders: render ohne Exception', html.includes('Termine') && !html.includes('Lade Termine'));
    t('reminders: + Termin-Button vorhanden', !!view.querySelector('#rem-add'));
    // Modal öffnen — fängt Modal-Wiring-Bugs (Lesson v1.0.4)
    view.querySelector('#rem-add')?.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    const modal = global.document.querySelector('#modal-root .modal');
    t('reminders: Termin-Modal öffnet', !!modal);
    t('reminders: Modal hat Speichern-Button', !!global.document.querySelector('#modal-root [data-act="save"]'));
  } catch (e) { t('reminders: render', false, e.message); }

  // ── 3. Tarifvergleich ──
  try {
    const { view } = await renderView(`${ROOT}/views/tariff.js`);
    const html = view.innerHTML;
    t('tariff: render ohne Exception', html.includes('Tarifvergleich'));
    t('tariff: Verbrauchsart-Selektor', !!view.querySelector('#t-util'));
    // kurz warten, bis loadResult() das Ergebnis nachzieht
    await new Promise(r => setTimeout(r, 400));
    const res = view.querySelector('#t-result');
    t('tariff: Ergebnisbereich gefüllt', res && res.innerHTML.length > 30 && !res.innerHTML.includes('Lade Vergleich'));
  } catch (e) { t('tariff: render', false, e.message); }

  // ── 4. Dashboard (Chart-Stub, Insight-Karten) ──
  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    const html = view.innerHTML;
    t('dashboard: render ohne Exception', html.includes('\u00dcbersicht'));
    t('dashboard: Verbrauchskarten vorhanden', view.querySelectorAll('.card').length >= 1);
    const canvas = view.querySelector('#dash-chart');
    t('dashboard: Chart-Canvas eingebunden', !!canvas);
  } catch (e) { t('dashboard: render', false, e.message); }

  // ── 5. Settings (größte View, alle neuen Felder) ──
  try {
    const { view } = await renderView(`${ROOT}/views/settings.js`);
    const html = view.innerHTML;
    t('settings: render ohne Exception', html.includes('Einstellungen'));
    t('settings: active_utilities-Checkboxen', view.querySelectorAll('[data-active-util]').length >= 3);
    t('settings: PDF-Download-Link', !!view.querySelector('#pdf-dl'));
    t('settings: sigmoid im Modell-Picker',
      !![...view.querySelectorAll('select option')].find(o => o.value === 'sigmoid'));
    t('settings: Gebäude-Feld wohnflaeche', !!view.querySelector('[data-key="wohnflaeche_m2"]'));
  } catch (e) { t('settings: render', false, e.message); }

  // ── 6. Utility-View: Delivery-Modus (Heizöl) ──
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['heizoel']);
    await new Promise(r => setTimeout(r, 500)); // async rerender
    const html = view.innerHTML;
    const ok = html.includes('Heiz\u00f6l') || html.includes('Lieferung') || html.includes('Zähler');
    t('utility(heizoel): render ohne Exception', html.length > 50 && ok);
    // Bei vorhandenem Tank: Lieferungs-Button + Tank-Balken
    const hasDeliveryUI = html.includes('Lieferung') || html.includes('Noch keine Zähler');
    t('utility(heizoel): Delivery-UI oder leerer Zustand', hasDeliveryUI);
  } catch (e) { t('utility(heizoel): render', false, e.message); }

  // ── 7. Utility-View: kumulativ (Gas) — Regressionspfad nicht gebrochen ──
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 500));
    const html = view.innerHTML;
    t('utility(gas): render ohne Exception', html.length > 50 && (html.includes('Gas') || html.includes('Zähler')));
  } catch (e) { t('utility(gas): render', false, e.message); }

  // ── 7b. F1005 (v1.7.0) — PV-Einspeisung & PV-Erzeugung rendern leer-Smoke ──
  for (const pvKey of ['pv_einspeisung', 'pv_erzeugung']) {
    try {
      const { view } = await renderView(`${ROOT}/views/utility.js`, [pvKey]);
      await new Promise(r => setTimeout(r, 400));
      const html = view.innerHTML;
      t(`utility(${pvKey}): render ohne Exception`,
        html.length > 50 && !html.includes('Lade '));
    } catch (e) { t(`utility(${pvKey}): render`, false, e.message); }
  }

  // ── 8. Forecast-View: alle 5 Modelle wählbar (Bug-Fix v1.4.1) ──
  try {
    const { view } = await renderView(`${ROOT}/views/forecast.js`);
    await new Promise(r => setTimeout(r, 600));
    const html = view.innerHTML;
    t('forecast: render ohne Exception', html.length > 50 && !html.includes('Lade '));
    const opts = [...view.querySelectorAll('#model option')].map(o => o.value);
    t('forecast: alle 5 Regressionsmodelle wählbar',
      ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'].every(m => opts.includes(m)),
      opts.join(','));
  } catch (e) { t('forecast: render', false, e.message); }

  // ── 9. Analyse-View: Sigmoid im Korrelations-Chart (Fix v1.4.3 #1) ──
  try {
    const { view } = await renderView(`${ROOT}/views/analysis.js`, ['gas']);
    await new Promise(r => setTimeout(r, 600));
    const html = view.innerHTML;
    t('analyse(gas): render ohne Exception', html.length > 50 && !html.includes('Lade '));
    t('analyse(gas): Sigmoid in R²-Tabelle',
      /Sigmoid/i.test(html), 'kein Sigmoid-Eintrag in der Regressionsübersicht');
  } catch (e) { t('analyse(gas): render', false, e.message); }

  // ── 10. Contracts-View: Liefer-Arten ohne Verträge (Fix v1.4.3 #2) ──
  try {
    const { view } = await renderView(`${ROOT}/views/contracts.js`, ['heizoel']);
    await new Promise(r => setTimeout(r, 400));
    const html = view.innerHTML;
    t('contracts(heizoel): Hinweis statt Vertragsformular',
      /keine Verträge/i.test(html) && !view.querySelector('[data-action="new-contract"]'),
      'Heizöl sollte erklärenden Hinweis zeigen, kein "+ Neuer Vertrag"');
  } catch (e) { t('contracts(heizoel): render', false, e.message); }

  // ── 11. Contracts-View: kumulative Art behält Vertragsverwaltung ──
  try {
    const { view } = await renderView(`${ROOT}/views/contracts.js`, ['gas']);
    await new Promise(r => setTimeout(r, 400));
    t('contracts(gas): Vertragsverwaltung vorhanden',
      !!view.querySelector('[data-action="new-contract"]'),
      'Gas sollte "+ Neuer Vertrag" anbieten');
  } catch (e) { t('contracts(gas): render', false, e.message); }

  console.log(`\n  ERGEBNIS: ${pass} bestanden, ${fail} fehlgeschlagen`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('  HARNESS-FEHLER:', e.stack || e.message); process.exit(2); });
