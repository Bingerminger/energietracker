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

async function renderView(modPath, params = [], ctx = {}) {
  const dom = freshDom();
  const view = global.document.getElementById('view');
  const mod = await import(modPath + '?t=' + Date.now());
  const cleanup = await mod.render(view, params, ctx);
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
      // v2.11.0 — auch dynamische Importe (der Router lädt die Ansichten bei Bedarf)
      const re = /(?:from\s+|import\(\s*)["']([^"']+)["']/g; let m;
      while ((m = re.exec(src))) {
        if (m[1].startsWith('.')) await crawl(new URL(m[1], u).href);
      }
    }
    await crawl('http://127.0.0.1:8899/public/js/app.js');
    t('Modulgraph: app.js + alle Importe laden via HTTP', bad.length === 0,
      bad.length ? bad.join('; ') : seen.size + ' Module');
  } catch (e) { t('Modulgraph-Vorprüfung', false, e.message); }

  // ── 0b. i18n initialisieren (wie app.js). Ohne das liefert t() nur
  //    Übersetzungs-Keys statt echter Strings, und alle Text-Assertions
  //    unten würden fehlschlagen. Sprache: de (Default). freshDom() setzt
  //    vorher die document/fetch-Globals, die initI18n braucht.
  try {
    freshDom();
    const i18n = await import(`${ROOT}/lib/i18n.js`);
    await i18n.initI18n('de');
    t('i18n: de-Katalog geladen', i18n.t('nav.dashboard') === 'Übersicht',
      't(nav.dashboard)=' + i18n.t('nav.dashboard'));
  } catch (e) { t('i18n-Init', false, e.message); }

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
    t('recommendations: einheitlicher Seitenkopf', !!view.querySelector('.view-header .view-header__title'));
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
    // v2.4.2 — GitHub #21: Das Zählerstand-Feld für Gas trägt m³, nicht kWh.
    // Der Zähler zählt Kubikmeter; kWh ist die Einheit des VERBRAUCHS und
    // entsteht erst über den Umrechnungsfaktor. Bis v2.4.1 stand hier kWh —
    // wer dem Etikett folgte und selbst umrechnete, trug falsche Stände ein.
    const gasCard = [...cards].find(c => c.dataset.utility === 'gas');
    if (gasCard) {
      const label = gasCard.querySelector('.field--counter .field__label')?.textContent || '';
      t('readings-entry: Gas-Zählerstand ist in m³ beschriftet',
        label.includes('m³') && !label.includes('kWh'),
        `label="${label.trim()}"`);
    }
    // v2.12.0 (Review UI-15, UI-33)
    const first = cards[0];
    t('readings-entry: Datum je Karte eingeklappt hinter „Anderes Datum"',
      first?.querySelector('[data-role="date-wrap"]')?.hidden === true && !!first?.querySelector('[data-action="toggle-date"]'));
    t('readings-entry: kein Beispielwert als Platzhalter', !counter?.hasAttribute('placeholder'));
    const counters = [...view.querySelectorAll('[data-role="counter"]')];
    t('readings-entry: Weiter-Taste bis zum letzten Feld',
      counters.length < 2 || (counters[0].getAttribute('enterkeyhint') === 'next' && counters.at(-1).getAttribute('enterkeyhint') === 'done'));
    counter.value = 'abc';
    view.querySelector('[data-action="save-all"]').dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    await new Promise(r => setTimeout(r, 150));
    const err = first.querySelector('[data-role="error"]');
    t('readings-entry: Fehler sichtbar am Feld', err && !err.hidden && err.textContent.length > 5 && counter.getAttribute('aria-invalid') === 'true');

    // Direktsprung je Zähler (#/zaehlerstaende?meter=…)
    const wanted = cards[cards.length - 1]?.dataset.meterId;
    const { view: v2 } = await renderView(`${ROOT}/views/readings-entry.js`, [], { query: new URLSearchParams('meter=' + wanted) });
    t('readings-entry: Direktsprung markiert den Zähler',
      v2.querySelector('.reading-card--focus')?.dataset.meterId === wanted, `meter=${wanted}`);
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
    // v2.12.0 (Review UI-22) — Fehler am Feld, nicht im Toast
    global.document.querySelector('#modal-root #f-title').value = '';
    global.document.querySelector('#modal-root [data-act="save"]').dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    await new Promise(r => setTimeout(r, 50));
    const msg = global.document.querySelector('#modal-root #f-title-msg');
    t('reminders: Pflichtfeld-Fehler steht am Feld', msg && !msg.hidden && msg.textContent.length > 3);
    // v2.12.0 (Review UI-20, UI-22) — Seitenkopf wie überall, Namen in den Vorlesetexten
    t('reminders: einheitlicher Seitenkopf', !!view.querySelector('.view-header .view-header__title'));
    const doneBtn = view.querySelector('[data-done]');
    t('reminders: „Erledigt" nennt den Termin', !doneBtn || /: .+/.test(doneBtn.getAttribute('aria-label') || ''));
  } catch (e) { t('reminders: render', false, e.message); }

  // ── 3. Tarifvergleich (v2.3.0: Wechselentscheidung + Rückblick) ──
  try {
    const { view } = await renderView(`${ROOT}/views/tariff.js`);
    const html = view.innerHTML;
    t('tariff: render ohne Exception', html.includes('Wechsel prüfen'));
    t('tariff: Verbrauchsart-Selektor', !!view.querySelector('#t-util'));
    // v2.12.0 (Review UI-20) — gestaltete Auswahlfelder, Seitenkopf wie überall
    t('tariff: Auswahlfelder gestaltet', view.querySelector('#t-util')?.classList.contains('select'));
    t('tariff: einheitlicher Seitenkopf', !!view.querySelector('.view-header .view-header__title'));
    // kurz warten, bis loadAll() beide Blöcke nachzieht
    await new Promise(r => setTimeout(r, 500));

    const sw = view.querySelector('#t-switch');
    t('tariff: Wechselblock gefüllt',
      sw && sw.innerHTML.length > 30 && !sw.innerHTML.includes('Lade Vergleich'));

    // Der erwartete Jahresverbrauch ist die Zahl, mit der der Nutzer zum
    // Vergleichsportal geht — fehlt sie, ist der ganze Ablauf tot.
    t('tariff: erwarteter Jahresverbrauch sichtbar',
      !!view.querySelector('.switch-bignum strong'));
    t('tariff: Verbrauch kopierbar', !!view.querySelector('#t-copy-consumption'));
    t('tariff: Wechseltermin wählbar', !!view.querySelector('#t-switch-date'));

    // Der Rückblick ist eingeklappt, muss aber existieren und Zeilen tragen.
    const retro = view.querySelector('.retro-block');
    t('tariff: Rückblick vorhanden', !!retro);
    t('tariff: Rückblick trägt Zeilen',
      !retro || retro.querySelectorAll('tbody tr').length > 0);
    // v2.12.0 (Review UI-31) — nur Jahre mit Daten, kein rohes „2024-01"
    const years = [...view.querySelectorAll('#t-year option')].map(o => o.value).filter(Boolean);
    t('tariff: Rückblick bietet nur Jahre mit Daten', years.length >= 1 && years.length < 7, years.join(','));
    t('tariff: Zeitraum ohne ISO-Monat', !/\d{4}-\d{2}\b/.test(retro?.querySelector('.toolbar--sub .muted')?.textContent || ''));
  } catch (e) { t('tariff: render', false, e.message); }

  // ── 4. Dashboard (Chart-Stub, Insight-Karten) ──
  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    const html = view.innerHTML;
    t('dashboard: render ohne Exception', html.includes('\u00dcbersicht'));
    t('dashboard: Verbrauchskarten vorhanden', view.querySelectorAll('.card').length >= 1);
    const canvas = view.querySelector('#dash-chart');
    t('dashboard: Chart-Canvas eingebunden', !!canvas);
    // v2.10.0 — Effizienzkarte mit der zweiten Zahl (energieausweis-nah)
    t('dashboard: Effizienz mit energieausweis-naher Kennzahl', !!view.querySelector('.dash-eff__cert'));
    // v2.12.0 (Review UI-21) — „Zu tun" oben: fällige Ablesungen und Termine mit Sprung
    const todo = view.querySelector('.dash-todo');
    t('dashboard: „Zu tun" vorhanden', !!todo);
    // Demo-Daten: sieben fällige Zähler → eine Sammelzeile zur Erfassung
    t('dashboard: fällige Ablesungen führen zur Erfassung', !!todo?.querySelector('a[href^="#/zaehlerstaende"]'));
    t('dashboard: viele fällige Zähler als eine Zeile',
      todo?.querySelectorAll('a[href^="#/zaehlerstaende"]').length === 1, `${todo?.querySelectorAll('li').length} Einträge`);
    t('dashboard: überfälliger Termin führt zu den Terminen', !!todo?.querySelector('a[href="#/reminders"]'));
    t('dashboard: keine Kachel „Aktive Zähler" mehr', !/Aktive Zähler/.test(html));
  } catch (e) { t('dashboard: render', false, e.message); }

  // ── 5. Einstellungen — seit v2.12.0 Unterseiten (Review UI-13) ──
  const settingsPage = async (page) => (await renderView(`${ROOT}/views/settings.js`, page ? [page] : [])).view;
  try {
    const view = await settingsPage();
    t('settings/allgemein: Seite „Allgemein"', !!view.querySelector('h1')?.textContent.includes('Allgemein'));
    // v2.7.0 — Länderprofil: Land, Währung, Zeitzone
    const countryOpts = view.querySelectorAll('#country-select option').length;
    t('settings/allgemein: Länderauswahl mit allen Profilen', countryOpts === 9, `${countryOpts} Länder`);
    t('settings/allgemein: Währung und Zeitzone wählbar',
      !!view.querySelector('#currency-select') && view.querySelectorAll('#tz-select option').length > 10);
    const bar = view.querySelector('[data-role="savebar"]');
    t('settings/allgemein: Speicherleiste erst bei einer Änderung', bar?.hidden === true);
    const dm = view.querySelector('[data-key="dashboard_months"]');
    dm.value = '7';
    dm.dispatchEvent(new global.window.Event('input', { bubbles: true }));
    t('settings/allgemein: Speicherleiste nach einer Änderung', bar?.hidden === false);
    t('settings/allgemein: keine Expertenwerte', !view.querySelector('[data-key="blend_max"]'));
  } catch (e) { t('settings/allgemein: render', false, e.message); }

  try {
    const view = await settingsPage('utilities');
    t('settings/verbrauchsarten: active_utilities-Checkboxen', view.querySelectorAll('[data-active-util]').length >= 3);
    // v2.12.0 — alle Abrechnungsstichtage an einer Stelle; v2.13.0 (FE-10)
    // ohne Heizöl/Pellets (keine Abschläge, kein Stichtag), mit PV-Einspeisung
    const anchors = [...view.querySelectorAll('[data-key^="billing_cycle_anchor_"]')].map(el => el.dataset.key);
    t('settings/verbrauchsarten: fünf Abrechnungsstichtage in einer Karte',
      anchors.length === 5
        && new Set([...view.querySelectorAll('[data-key^="billing_cycle_anchor_"]')].map(el => el.closest('.settings-card'))).size === 1);
    t('settings/verbrauchsarten: Stichtag PV-Einspeisung statt Heizöl/Pellets',
      anchors.includes('billing_cycle_anchor_pv_einspeisung')
        && !anchors.includes('billing_cycle_anchor_heizoel') && !anchors.includes('billing_cycle_anchor_pellets'));
    // v2.5.0 — F1012: datierte Gas-Faktoren als Tabelle mit Erfassungszeile
    t('settings/verbrauchsarten: Gas-Faktoren-Tabelle über die ganze Breite',
      !!view.querySelector('.settings-card--wide [data-gasfactors] [data-gf-table]'));
    t('settings/verbrauchsarten: Gas-Faktoren als JSON-Feld eingesammelt',
      view.querySelector('[data-key="gas_conversion_factors"]')?.getAttribute('data-type') === 'json');
    t('settings/verbrauchsarten: Erfassungszeile mit Zustandszahl + Brennwert', !!view.querySelector('#gf-z') && !!view.querySelector('#gf-hs'));
    t('settings/verbrauchsarten: kein Feld für den alten Skalar', !view.querySelector('[data-key="gas_conversion_factor"]'));
    t('settings/verbrauchsarten: Brennwert-Einheit kWh, MJ, GJ',
      [...view.querySelectorAll('[data-key="gas_cv_unit"] option')].map(o => o.value).join() === 'kwh,mj,gj');
    // v2.10.0 — CO₂ Strom je Jahr, Einheiten je kWh
    t('settings/verbrauchsarten: CO₂-Jahreswerte als Tabelle',
      view.querySelectorAll('[data-co2years] [data-cy-table] tbody tr').length >= 3
        && view.querySelector('[data-key="co2_strom_years"]')?.getAttribute('data-type') === 'json');
    t('settings/verbrauchsarten: CO₂ Heizöl/Pellets je kWh', /CO₂ Heizöl[^<]*<span class="settings-field__unit">g\/kWh/.test(view.innerHTML));
    t('settings/verbrauchsarten: kein Standort mehr (steht bei den Wetterdaten)', !view.querySelector('[data-key="latitude"]'));
  } catch (e) { t('settings/verbrauchsarten: render', false, e.message); }

  try {
    const view = await settingsPage('household');
    t('settings/haushalt: Wohnfläche', !!view.querySelector('[data-key="wohnflaeche_m2"]'));
    t('settings/haushalt: beheizter Keller und dezentrales Warmwasser',
      !!view.querySelector('[data-key="beheizter_keller"]') && !!view.querySelector('[data-key="warmwasser_dezentral"]'));
    t('settings/haushalt: Personen im Haushalt', !!view.querySelector('[data-key="wasser_personen_anzahl"]'));
  } catch (e) { t('settings/haushalt: render', false, e.message); }

  try {
    const view = await settingsPage('data');
    // v2.11.0 — der Jahresbericht hat eine eigene Seite; hier nur der Verweis
    t('settings/daten: Verweis auf den Jahresbericht', !!view.querySelector('a[href="#/report"]'));
    t('settings/daten: Sicherung, Import und Demo-Daten',
      !!view.querySelector('#btn-export') && !!view.querySelector('#btn-import') && !!view.querySelector('#btn-demo'));
    t('settings/daten: keine Speicherleiste (keine Felder)', !view.querySelector('[data-role="savebar"]'));
  } catch (e) { t('settings/daten: render', false, e.message); }

  try {
    const view = await settingsPage('expert');
    const details = view.querySelector('details.settings-expert');
    t('settings/experte: eingeklappt mit Warnung', !!details && !details.open && !!details.querySelector('.banner--warning'));
    t('settings/experte: sigmoid im Modell-Picker',
      !![...view.querySelectorAll('select option')].find(o => o.value === 'sigmoid'));
    // v2.13.0 (UI-17) — lesbare Namen statt der Schlüssel, Bandbreite pflegbar
    const modelOpt = view.querySelector('[data-key="forecast_model"] option[value="segmented"]');
    t('settings/experte: Modellnamen übersetzt', !!modelOpt && modelOpt.textContent.trim() !== 'segmented',
      modelOpt?.textContent);
    const splitOpt = view.querySelector('[data-key="segmented_split_mode"] option[value="auto"]');
    t('settings/experte: Split-Modus übersetzt', !!splitOpt && splitOpt.textContent.trim() !== 'auto', splitOpt?.textContent);
    t('settings/experte: Breite des Prognosebands', !!view.querySelector('[data-key="confidence_band_sigma"]'));
  } catch (e) { t('settings/experte: render', false, e.message); }

  try {
    const view = await settingsPage('system');
    t('settings/system: Version und Diagnose', /Energietracker \d+\.\d+/.test(view.textContent) && !!view.querySelector('.diag-grid'));
  } catch (e) { t('settings/system: render', false, e.message); }

  try {
    const view = await settingsPage('integrations');
    t('settings/integrationen: Home Assistant', !!view.querySelector('#btn-ha-generate, #btn-ha-revoke'));
    // v2.13.0 (I18N-25) — Anleitung als Link, nicht als Pfad im Fließtext
    const guide = [...view.querySelectorAll('a[target="_blank"]')].find(a => /HOME-ASSISTANT\.md$/.test(a.getAttribute('href') || ''));
    t('settings/integrationen: HA-Anleitung verlinkt', !!guide && !view.textContent.includes('docs/HOME-ASSISTANT.md'),
      guide?.getAttribute('href'));
  } catch (e) { t('settings/integrationen: render', false, e.message); }

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
    // v2.10.0 — Tankbuch: Bestandskurve, Herkunftshinweis, Peilstände
    t('utility(heizoel): Tankbuch mit Kurve, Hinweis und Peilständen',
      !!view.querySelector('#stock-chart') && !!view.querySelector('#btn-new-level')
        && /gerechnet|geschätzt/.test(html) && html.includes('Peilst'),
      'Kurve/Knopf/Hinweis fehlt');
  } catch (e) { t('utility(heizoel): render', false, e.message); }

  // ── 7. Utility-View: kumulativ (Gas) — Regressionspfad nicht gebrochen ──
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 500));
    const html = view.innerHTML;
    t('utility(gas): render ohne Exception', html.length > 50 && (html.includes('Gas') || html.includes('Zähler')));
    // v2.5.0 — F1012: Rechnungsprüfung nur bei Gas; seit v2.11.0 eine eigene
    // Seite, die Gas-Ansicht verweist mit Zähler und Jahr darauf
    t('utility(gas): Verweis auf die Rechnungsprüfung', !!view.querySelector('a[href^="#/bill-check?meter="]'));
    // v2.5.1 — Spalte „Sonderzahlungen" in „Verträge & Abschläge": Kopf mit
    // Erklärung, Zelle mit Netto und Tooltip der Einzelposten (Demo-Daten
    // führen am Gas-Vertrag eine Rückzahlung und eine Abschlagszahlung).
    // v2.13.0 (UI-17) — Erklärung über ⓘ, Einzelposten zum Aufklappen statt
    // Tooltip (auf dem iPhone unerreichbar)
    const spHead = view.querySelector('.contracts-table th.special-col');
    t('utility(gas): Spalte Sonderzahlungen mit Erklärung',
      !!spHead?.querySelector('.info-btn[data-glossary="specialPayment"]'));
    const spCells = [...view.querySelectorAll('.contracts-table td.special-cell')];
    const filled = spCells.find(td => td.querySelector('details.special-details'));
    const spItems = filled ? [...filled.querySelectorAll('.special-details__list li')].map(li => li.textContent) : [];
    t('utility(gas): Zelle Sonderzahlungen mit Netto + aufklappbaren Posten',
      !!filled && /[+−-]?\d/.test(filled.querySelector('summary')?.textContent || '') && spItems.length > 0 && spItems.every(s => s.includes('·')),
      filled ? `${filled.querySelector('summary')?.textContent.trim()} | ${spItems[0] || ''}` : `${spCells.length} Zellen, keine aufklappbar`);
    t('utility(gas): Hinweistext unter der Tabelle', view.innerHTML.includes('Sonderzahlungen =') || view.innerHTML.includes('Special payments ='));
    // v2.8.0 — Kachel und Saldo-Karte widersprechen sich nicht: Liegt die
    // letzte Ablesung im laufenden Jahr zurück, nennt die Abschlagskachel
    // ihren Stand (die Karte rechnet nach Kalender bis heute)
    const advLabel = [...view.querySelectorAll('.kpi__label')].map(e => e.textContent).find(s => /Abschläge/.test(s)) || '';
    const measured = view.textContent.match(/gemessen bis (\d{2}\.\d{2}\.(\d{4}))/);
    const expectPartial = !!measured && measured[2] === String(new Date().getFullYear());
    t('utility(gas): Abschlagskachel nennt ihren Stand',
      expectPartial ? advLabel.includes('bis ' + measured[1]) : /Abschläge \d{4}/.test(advLabel), advLabel.trim());
    // Die Aufschlüsselung der Saldo-Karte ergibt die Summe
    const consumedCol = view.querySelector('.balance-grid > div');
    t('utility(gas): Saldo-Karte schlüsselt die Summe auf',
      !!consumedCol && /Verbrauch/.test(consumedCol.textContent) && /Grundpreis/.test(consumedCol.textContent));

  } catch (e) { t('utility(gas): render', false, e.message); }

  // ── 7c. Rechnung prüfen (eigene Seite seit v2.11.0) ──
  // v2.5.2 — Stand alt/neu je Abschnitt mit Ableseart; Ersatzwerte (E)
  // tragen einen Tooltip, die Fußnote erklärt. Zeitraum aus der Adresse →
  // die Seite rechnet sofort.
  try {
    const { view } = await renderView(`${ROOT}/views/bill-check.js`, [],
      { query: new URLSearchParams('from=2025-01-01&to=2026-01-01') });
    t('billCheck: Seite mit Überschrift', view.querySelector('h1')?.textContent.includes('Rechnung prüfen'));
    for (let i = 0; i < 40 && !view.querySelector('#bc-result table'); i++) await new Promise(r => setTimeout(r, 100));
    const bcHead = [...view.querySelectorAll('#bc-result thead th')].map(th => th.textContent.trim());
    t('billCheck: Spalten Stand alt / Stand neu', bcHead.includes('Stand alt') && bcHead.includes('Stand neu'), bcHead.join('|'));
    const counters = [...view.querySelectorAll('#bc-result td.counter-cell')];
    const interpolated = counters.filter(td => td.dataset.kind === 'interpolated');
    t('billCheck: Ersatzwert-Zellen mit Kürzel E und Tooltip',
      interpolated.length > 0 && interpolated.every(td => /\bE\b/.test(td.textContent) && (td.getAttribute('title') || '').length > 10),
      `${interpolated.length} Ersatzwerte von ${counters.length} Ständen`);
    t('billCheck: abgelesene Stände ohne Kürzel',
      counters.some(td => td.dataset.kind === 'reading') && counters.filter(td => td.dataset.kind === 'reading').every(td => !td.querySelector('sup')));
    t('billCheck: Fußnote zur Ableseart', !!view.querySelector('#bc-result .bill-check-legend') && view.querySelector('#bc-result .bill-check-legend').textContent.includes('E ='));
  } catch (e) { t('billCheck: render', false, e.message); }

  // ── 7a. Utility-View Wasser: KEINE Spalte Sonderzahlungen (kennt keine) ──
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['wasser']);
    await new Promise(r => setTimeout(r, 500));
    t('utility(wasser): render ohne Exception', view.innerHTML.length > 50);
    t('utility(wasser): keine Spalte Sonderzahlungen', !view.querySelector('.contracts-table th.special-col'));
  } catch (e) { t('utility(wasser): render', false, e.message); }

  // ── 7b. Utility-View Strom: KEINE Rechnungsprüfung (F1012 ist Gas-only) ──
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['strom']);
    await new Promise(r => setTimeout(r, 500));
    t('utility(strom): keine Rechnungsprüfung', !view.querySelector('a[href^="#/bill-check"]'));
  } catch (e) { t('utility(strom): render', false, e.message); }

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
    // v2.8.0 — Jahresband und Hinweis ohne Klimanormal (Demo-Daten haben keins)
    const info = view.querySelector('#fc-info')?.textContent || '';
    t('forecast: Jahresband genannt', /der Jahre zwischen/.test(info), info.slice(0, 120));
    t('forecast: Hinweis ohne Klimanormal', /Ohne Klimanormal/.test(info));
    // Methode übersetzt statt des API-Rohwerts blend(reg=…, seasonal=…)
    const tableText = view.textContent || '';
    t('forecast: Methode lesbar', /Mischung: \d+ % Heizkurve/.test(tableText) && !/blend\(reg=/.test(tableText));
  } catch (e) { t('forecast: render', false, e.message); }

  // ── 9. Analyse-View: Sigmoid im Korrelations-Chart (Fix v1.4.3 #1) ──
  try {
    const { view } = await renderView(`${ROOT}/views/analysis.js`, ['gas']);
    await new Promise(r => setTimeout(r, 600));
    const html = view.innerHTML;
    t('analyse(gas): render ohne Exception', html.length > 50 && !html.includes('Lade '));
    t('analyse(gas): Sigmoid in R²-Tabelle',
      /Sigmoid/i.test(html), 'kein Sigmoid-Eintrag in der Regressionsübersicht');
    t('analyse(gas): R² als Anpassung erklärt', /nicht, wie gut sie das nächste Jahr vorhersagt/.test(html));
  } catch (e) { t('analyse(gas): render', false, e.message); }

  // v2.8.0 — Heizöl: keine Heizkurve über nach Gradtagen verteilte Monate
  try {
    const { view } = await renderView(`${ROOT}/views/analysis.js`);
    await new Promise(r => setTimeout(r, 600));
    const sel = view.querySelector('#util-select');
    if (sel && [...sel.options].some(o => o.value === 'heizoel')) {
      sel.value = 'heizoel';
      sel.dispatchEvent(new global.window.Event('change'));
      await new Promise(r => setTimeout(r, 800));
      const html = view.innerHTML;
      t('analyse(heizoel): Hinweis statt Heizkurve', /Zirkelschluss/.test(html) && !view.querySelector('#ch-hdd'));
    } else {
      t('analyse(heizoel): Hinweis statt Heizkurve', false, 'Heizöl nicht aktiv in den Demo-Daten');
    }
  } catch (e) { t('analyse(heizoel): render', false, e.message); }

  // v2.8.0 — Temperaturen: Stand der Messwerte und Neu-laden-Option
  try {
    const { view } = await renderView(`${ROOT}/views/temperatures.js`);
    await new Promise(r => setTimeout(r, 400));
    const html = view.innerHTML;
    t('temperaturen: Stand der Messwerte', /Messwerte bis/.test(html));
    t('temperaturen: Archiv neu laden wählbar', !!view.querySelector('#sync-reload'));
  } catch (e) { t('temperaturen: render', false, e.message); }

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
    // v2.12.0 (Review UI-20) — Pluralformen statt „1 Arbeitspreise"
    t('contracts(gas): Zusammenfassung in der richtigen Pluralform',
      !/(^|[^\d.,])1 (Arbeitspreise|Grundpreise|Abschläge|Boni|Sonderzahlungen)\b/.test(view.textContent));
    // v2.7.0 — Umrechnungshilfe „Preis je m³" im Gasvertrag
    view.querySelector('[data-action="new-contract"]')?.click();
    await new Promise(r => setTimeout(r, 400));
    const box = global.document.querySelector('#modal-root [data-perm3]');
    t('contracts(gas): Umrechnungshilfe je m³ im Formular', !!box);
    // v2.12.0 (Review UI-16) — die erste Preiszeile gilt ab Vertragsbeginn
    const startIn = global.document.querySelector('#modal-root input[name="start"]');
    const firstDate = global.document.querySelector('#modal-root [data-group="working_prices"] .entry-row [data-role="date"]');
    t('contracts(gas): erste Preiszeile ab Vertragsbeginn', !!startIn?.value && firstDate?.value === startIn.value,
      `${startIn?.value} / ${firstDate?.value}`);
    if (box) {
      box.querySelector('#perm3-date').value = '2024-06-01';
      box.querySelector('#perm3-price').value = '1,10';
      box.querySelector('#perm3-price').dispatchEvent(new global.window.Event('input'));
      const out = box.querySelector('[data-perm3-result]').textContent;
      t('contracts(gas): je m³ ergibt ct/kWh', /ct\/kWh/.test(out) && !box.querySelector('[data-action="perm3-apply"]').disabled, out);
      box.querySelector('[data-action="perm3-apply"]').click();
      const row = [...global.document.querySelectorAll('#modal-root [data-group="working_prices"] .entry-row')]
        .find(r => r.querySelector('[data-role="date"]').value === '2024-06-01');
      t('contracts(gas): Übernehmen füllt die Arbeitspreis-Zeile', !!row && row.querySelector('[data-role="amount"]').value !== '',
        row ? row.querySelector('[data-role="amount"]').value : 'keine Zeile');
    }
    // v2.9.0 (CALC-10, CALC-11) — Frist mit Einheit, Kündigungsweise,
    // Verlängerung standardmäßig an
    const modalEl = global.document.querySelector('#modal-root');
    const units = [...(modalEl?.querySelectorAll('[name="notice_unit"] option') || [])].map(o => o.value);
    const modes = [...(modalEl?.querySelectorAll('[name="notice_mode"] option') || [])].map(o => o.value);
    t('contracts(gas): Frist in Monaten, Wochen oder Tagen', units.join(',') === 'months,weeks,days', units.join(','));
    t('contracts(gas): Kündigungsweise wählbar', ['', 'term_end', 'month_end', 'any_day'].every(v => modes.includes(v)), modes.join(','));
    t('contracts(gas): Verlängerung ohne Kündigung vorbelegt', modalEl?.querySelector('[name="auto_renews"]')?.checked === true);
  } catch (e) { t('contracts(gas): render', false, e.message); }

  // ── 12. v2.11.0 — Verträge & Abschläge über alle Verbrauchsarten ──
  try {
    const { view } = await renderView(`${ROOT}/views/contracts-overview.js`);
    await new Promise(r => setTimeout(r, 300));
    t('contractsOverview: Überschrift', !!view.querySelector('h1')?.textContent.includes('Verträge'));
    const cards = view.querySelectorAll('.contract-overview');
    t('contractsOverview: eine Karte je Verbrauchsart mit Verträgen', cards.length >= 3, `${cards.length} Karten`);
    t('contractsOverview: laufender Gasvertrag mit Erwartung zur Abrechnung',
      !!view.querySelector('.contract-overview[data-utility="gas"] .contract-overview__expected'));
    t('contractsOverview: Verweis auf die Vertragsverwaltung', !!view.querySelector('a[href="#/utility/gas/contracts"]'));
  } catch (e) { t('contractsOverview: render', false, e.message); }

  // ── 13. v2.11.0 — Jahresbericht als eigene Seite ──
  try {
    const { view } = await renderView(`${ROOT}/views/report.js`);
    const open = view.querySelector('#report-open');
    t('report: im Browser öffnen (inline, neuer Tab)',
      (open?.getAttribute('href') || '').includes('inline=1') && open?.getAttribute('target') === '_blank');
    t('report: Herunterladen ohne inline', !(view.querySelector('#report-download')?.getAttribute('href') || '').includes('inline'));
  } catch (e) { t('report: render', false, e.message); }

  // ── 14. v2.11.0 — Navigationsmodell: sieben Bereiche ──
  try {
    freshDom();
    const nm = await import(`${ROOT}/lib/nav-model.js`);
    const top = nm.sidebarModel([{ key: 'gas', label: 'Gas' }, { key: 'strom', label: 'Strom' }]).map(m => m.key);
    t('nav: sieben Bereiche', top.join(',') === 'dashboard,readings-entry,consumption,costs,analysis,hints,settings', top.join(','));
    t('nav: „Rechnung prüfen" nur mit Gas',
      nm.sectionPages('costs', { utilities: [{ key: 'gas' }] }).some(p => p.view === 'bill-check')
      && !nm.sectionPages('costs', { utilities: [{ key: 'strom' }] }).some(p => p.view === 'bill-check'));
    t('nav: Tab-Leiste mit fünf Zielen', nm.tabbarModel('gas').length === 5);
  } catch (e) { t('nav-model', false, e.message); }

  // ── 15. v2.11.0 — Dashboard: Kopf und Überschriften (UI-21, UI-26) ──
  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    t('dashboard: Temperaturen nicht mehr als Kopf-Aktion', !view.querySelector('a[href="#/temperatures"]'));
    t('dashboard: kein Link in einer Überschrift (UI-26)', !view.querySelector('h2 a, h2 .card__title-action'));
  } catch (e) { t('dashboard: Kopf', false, e.message); }

  // ── 16. v2.13.0 — Hilfe, Glossar und Erklärungen zum Antippen (E1) ──
  try {
    const { view } = await renderView(`${ROOT}/views/help.js`, [], { query: new URLSearchParams('term=hdd') });
    await new Promise(r => setTimeout(r, 300));
    const gl = await import(`${ROOT}/components/info.js`);
    const items = [...view.querySelectorAll('.glossary-list__item')];
    t('help: Glossar vollständig', items.length === gl.GLOSSARY.length, `${items.length}/${gl.GLOSSARY.length}`);
    t('help: jeder Begriff übersetzt', items.every(el => !/glossary\./.test(el.textContent)));
    t('help: Sprung aus dem ⓘ markiert den Begriff', !!view.querySelector('#g-hdd.glossary-list__item--focus'));
    const search = view.querySelector('[data-role="search"]');
    search.value = gl.glossaryTerm('hdd');
    search.dispatchEvent(new global.window.Event('input'));
    const shown = items.filter(el => !el.hidden).length;
    t('help: Suche filtert', shown >= 1 && shown < items.length, `${shown} Treffer`);
    t('help: Doku-Links', view.querySelectorAll('.help-links a[href^="https://github.com/"]').length >= 3);
    t('help: Checkliste Erste Schritte', !!view.querySelector('[data-role="setup"] .setup-list, [data-role="setup"] .banner--success'));
  } catch (e) { t('help: render', false, e.message); }

  try {
    const dom = freshDom();
    const gl = await import(`${ROOT}/components/info.js?t=${Date.now()}`);
    gl.installInfoPopovers();
    const main = global.document.getElementById('view');
    main.innerHTML = `<p>HGT ${gl.info('hdd')}</p><p>${gl.infoNote('Prüfen', 'Kleiner als der vorherige Stand.')}</p>`;
    const [b1, b2] = main.querySelectorAll('.info-btn');
    b1.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    let pop = global.document.querySelector('.info-pop');
    t('ⓘ: Klick öffnet die Erklärung aus dem Glossar',
      !!pop && pop.textContent.includes(gl.glossaryText('hdd')) && b1.getAttribute('aria-expanded') === 'true');
    t('ⓘ: Verweis ins Glossar', !!pop?.querySelector('a[href="#/help?term=hdd"]'));
    global.document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    t('ⓘ: Escape schließt', !global.document.querySelector('.info-pop') && b1.getAttribute('aria-expanded') === 'false');
    b2.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    pop = global.document.querySelector('.info-pop');
    t('ⓘ: Erklärung je Zeile, ohne Glossar-Link',
      !!pop && pop.textContent.includes('Kleiner als der vorherige Stand.') && !pop.querySelector('.info-pop__more'));
  } catch (e) { t('ⓘ: Popover', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    t('dashboard: ⓘ an den Kennzahlen', view.querySelectorAll('.info-btn').length >= 2, `${view.querySelectorAll('.info-btn').length}`);
    t('dashboard: kein Willkommen, wenn Daten da sind', !view.querySelector('.dash-welcome'));
    t('dashboard: PV als Einspeisung und Vergütung, nicht als Verbrauch und Kosten',
      /Einspeisung \(letzte 12 Monate\)/.test(view.textContent) && /Vergütung \(letzte 12 Monate\)/.test(view.textContent));
  } catch (e) { t('dashboard: E1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['pv_einspeisung']);
    await new Promise(r => setTimeout(r, 400));
    const headsOf = (tbl) => tbl ? [...tbl.querySelectorAll('thead th')].map(th => th.textContent) : [];
    const monthTbl = [...view.querySelectorAll('.table')].find(tb => headsOf(tb)[0] === 'Monat');
    const heads = headsOf(monthTbl);
    const cHeads = headsOf(view.querySelector('.contracts-table'));
    t('utility(pv_einspeisung): Untertitel Einspeisung', view.textContent.includes('Einspeisung · Vergütung'));
    t('utility(pv_einspeisung): Monate mit Erlös statt Kosten, ohne Abschlagsspalten',
      heads.some(h => h.startsWith('Erlös')) && !heads.some(h => h.startsWith('Kosten') || h.startsWith('Abschlag')), heads.join('|'));
    t('utility(pv_einspeisung): CO₂ als vermieden', heads.some(h => h.includes('vermieden')));
    // v2.13.0 — „vermieden“ trägt die Richtung, ein Minus davor verneint doppelt
    const co2Val = [...view.querySelectorAll('.kpi')].find(k => /vermieden/.test(k.textContent))?.querySelector('.kpi__value')?.textContent.trim() || '';
    t('utility(pv_einspeisung): vermiedenes CO₂ ohne Minus', /^\d/.test(co2Val), co2Val);
    t('utility(pv_einspeisung): Legende mit Rückforderung statt Nachzahlung',
      view.textContent.includes('Saldo: + Guthaben, − Rückforderung.') && !view.textContent.includes('− Nachzahlung'));
    // Der Netzbetreiber zahlt: verdient und erhalten, nicht verbraucht und bezahlt
    t('utility(pv_einspeisung): Vertrag mit Vergütung und Erhalten',
      cHeads.includes('Vergütung') && cHeads.includes('Erhalten') && !cHeads.includes('Verbraucht') && !cHeads.includes('Bezahlt'), cHeads.join('|'));
  } catch (e) { t('utility(pv_einspeisung): E1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['pv_erzeugung']);
    await new Promise(r => setTimeout(r, 400));
    const heads = [...view.querySelectorAll('.table thead th')].map(th => th.textContent);
    t('utility(pv_erzeugung): ohne Kosten- und Vertragsteil',
      !heads.some(h => h.startsWith('Kosten') || h.startsWith('Erlös')) && !view.querySelector('.contracts-table'), heads.join('|'));
    t('utility(pv_erzeugung): ohne Temperatur und Gradtage', !heads.some(h => /Temp|HGT/.test(h)));
  } catch (e) { t('utility(pv_erzeugung): E1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 500));
    // v2.13.0 (Review UI-18) — Saldo aus Kundensicht: Wort statt Vorzeichen
    const bal = view.querySelector('.balance-col__value--credit, .balance-col__value--due');
    t('utility(gas): Saldo als Guthaben/Nachzahlung ohne Minus',
      !!bal && !/^[\s]*[−-]/.test(bal.textContent), bal?.textContent.trim());
    t('utility(gas): Legende zum Vorzeichen', view.textContent.includes('Saldo: + Guthaben, − Nachzahlung.'));
    t('utility(gas): Grundpreis ausgeschrieben', !/\bGP\b/.test(view.textContent) && /Grundpreis/.test(view.textContent));
  } catch (e) { t('utility(gas): E1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/temperatures.js`);
    await new Promise(r => setTimeout(r, 300));
    // v2.13.0 (Review DOC-22) — Open-Meteo verlangt die Quellenangabe (CC BY 4.0)
    t('temperatures: Quelle und Lizenz der Wetterdaten',
      !!view.querySelector('.attribution a[href="https://open-meteo.com/"]')
        && !!view.querySelector('.attribution a[href="https://creativecommons.org/licenses/by/4.0/"]'));
  } catch (e) { t('temperatures: E1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/forecast.js`);
    await new Promise(r => setTimeout(r, 600));
    t('forecast: ⓘ am Saldo, Vorzeichen-Legende',
      !!view.querySelector('#fc-table th .info-btn[data-glossary="balance"]') && view.textContent.includes('Saldo: + Guthaben'));
  } catch (e) { t('forecast: E1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/tariff.js`);
    await new Promise(r => setTimeout(r, 600));
    // v2.13.0 (Review UI-27) — Spaltenerklärungen sichtbar statt nur im Tooltip
    t('tariff: keine Erklärung nur im Tooltip', !view.querySelector('th[title]'));
  } catch (e) { t('tariff: E1', false, e.message); }

  console.log(`\n  ERGEBNIS: ${pass} bestanden, ${fail} fehlgeschlagen`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('  HARNESS-FEHLER:', e.stack || e.message); process.exit(2); });
