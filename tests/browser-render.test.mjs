// Browser-naher Test: lädt die echten View-ES-Module in JSDOM, ruft
// render() gegen den laufenden PHP-Backend-Server auf und prüft, dass
// echter DOM entsteht (kein Loading-Spinner, keine Exception). Fängt
// ReferenceErrors, kaputte DOM-Queries, Template- und Event-Bugs, die
// reine Backend-Shape-Tests NICHT sehen.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const ROOT = resolve(__dir, '..', 'public', 'js');
// v3.4.0 — Testserver aus ET_TEST_HOST (parallele Läufe auf eigenem Port), sonst wie bisher :8899
const HOST = (process.env.ET_TEST_HOST || 'http://127.0.0.1:8899').replace(/\/$/, '');
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
    { url: HOST + '/', pretendToBeVisual: true }
  );
  // JSDOM-Globals für die Module bereitstellen
  // v2.15.0 — history: Ansichten schreiben ihre Auswahl per replaceState in die Adresse
  for (const k of ['window', 'document', 'location', 'history',
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
    || HOST + '/';
  const nativeFetch = global.fetch;
  // v3.1.0 — Antwort sofort zu Ende lesen: Ein ungelesener Körper lässt undici in
  // der CI mit `assert(!this.paused)` abbrechen, wenn der PHP-Server die
  // Verbindung schließt (frontend-api-shape.test.js, gleiche Ursache).
  const buffered = async (r, init) => {
    const buf = await r.arrayBuffer();
    const empty = [101, 204, 205, 304].includes(r.status) || (init?.method || 'GET').toUpperCase() === 'HEAD';
    return new Response(empty ? null : buf, { status: r.status, statusText: r.statusText, headers: r.headers });
  };
  const browserLikeFetch = (input, init) => {
    try {
      if (typeof input === 'string' && !/^https?:\/\//i.test(input)) {
        input = new URL(input, BASE_URL).href;
      }
    } catch {}
    return nativeFetch(input, init).then(r => buffered(r, init));
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
  // v2.15.0 — das Stub zeichnet Canvas und Konfiguration auf: Farben,
  // Teilmonate und Kurzbeschreibungen lassen sich so am echten Aufbau prüfen
  const ChartStub = function (canvas, config) {
    const inst = { canvas, config, destroyed: false, destroy() { this.destroyed = true; },
      update(){}, resize(){}, data: config?.data || {}, options: config?.options || {} };
    ChartStub.instances.push(inst);
    return inst;
  };
  ChartStub.instances = [];
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
    await crawl(HOST + '/public/js/app.js');
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

  // ── v3.1.0 (H2, FE-32/MKT-08) — Foto-Knopf; ohne Verbindung einreihen,
  //    Badge zählt, beim Nachsenden kommt jeder Stand genau einmal an ──
  try {
    const outbox = await import(`${ROOT}/lib/outbox.js`);
    const { api } = await import(`${ROOT}/api.js`);
    const { formatForInput } = await import(`${ROOT}/lib/format.js`);
    outbox.useStore(outbox.memoryStore());
    const { view } = await renderView(`${ROOT}/views/readings-entry.js`);
    const photo = view.querySelector('[data-role="photo"]');
    t('readings-entry: Foto-Knopf öffnet die Kamera (accept=image/*, capture)',
      photo?.getAttribute('accept') === 'image/*' && photo?.getAttribute('capture') === 'environment');
    const rows = (await api.readingsOverview()).rows;
    const today = new Date().toISOString().slice(0, 10);
    const rowOf = (c) => rows.find(x => x.utility === c.dataset.utility && x.meter_id === c.dataset.meterId);
    // Karten ohne Stand von heute (der löste die Rückfrage „ersetzen?“ aus)
    const cards = [...view.querySelectorAll('.reading-card')]
      .filter(c => { const l = rowOf(c)?.last_reading; return !l || l.date < today; }).slice(0, 2);
    const before = {};
    for (const c of cards) {
      const r = rowOf(c);
      before[c.dataset.meterId] = (await api.readings(r.utility, r.meter_id)).length;
      // derselbe Wert wie zuletzt: keine Rückfrage (weder kleiner noch Sprung)
      c.querySelector('[data-role="counter"]').value = formatForInput(r.last_reading?.counter ?? 0);
    }
    let badge = null;
    global.window.addEventListener('et:outbox', (e) => { badge = e.detail; });
    const realFetch = global.fetch;
    global.fetch = (input, init) => (init?.method && init.method !== 'GET')
      ? Promise.reject(new TypeError('Failed to fetch')) : realFetch(input, init);
    view.querySelector('[data-action="save-all"]').dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    for (let i = 0; i < 40 && (await outbox.count()) < 2; i++) await new Promise(r => setTimeout(r, 50));
    await new Promise(r => setTimeout(r, 50));
    global.fetch = realFetch;
    t('readings-entry offline: zwei Stände eingereiht, Badge 2',
      (await outbox.count()) === 2 && badge === 2, `count=${await outbox.count()}, badge=${badge}`);
    t('readings-entry offline: Karten zeigen „wartet“, Liste „Noch nicht gespeichert“',
      cards.every(c => c.querySelector('.reading-card__status--warn')) && view.querySelectorAll('.outbox-item').length === 2
        && !view.querySelector('[data-role="outbox"]').hidden);
    const refs = (await outbox.list()).map(e => e.client_ref);
    const res = await outbox.flush(api);
    const again = await outbox.flush(api);
    const after = {};
    for (const c of cards) {
      const list = await api.readings(c.dataset.utility, c.dataset.meterId);
      after[c.dataset.meterId] = list.length;
      // aufräumen: spätere Ansichten rechnen mit den Demo-Daten
      for (const r of list.filter(x => refs.includes(x.client_ref))) await api.deleteReading(c.dataset.utility, r.id);
    }
    t('readings-entry online: beide genau einmal gespeichert, Badge 0',
      res.sent === 2 && again.sent === 0 && badge === 0
        && cards.every(c => after[c.dataset.meterId] === before[c.dataset.meterId] + 1),
      JSON.stringify({ sent: res.sent, before, after }));
  } catch (e) { t('readings-entry: Offline-Warteschlange', false, e.message); }

  // ── v3.1.0 (H3) — Zeitraum-Karte in der Erfassung, Ansicht „Mietverhältnis" ──
  try {
    const { api } = await import(`${ROOT}/api.js`);
    const state = await import(`${ROOT}/state.js`);
    const before = await api.settings();
    await api.updateSettings({ active_utilities: [...(before.active_utilities || []), 'waerme'], wohnverhaeltnis: 'miete' });
    const pm = await api.createMeter('waerme', { name: 'UVI', capture: 'period', installed_on: '2020-01-01' });
    try {
      const { view } = await renderView(`${ROOT}/views/readings-entry.js`);
      const card = view.querySelector(`.reading-card[data-meter-id="${pm.id}"]`);
      t('readings-entry: Zeitraum-Zähler bekommt Monat + Verbrauch statt Stand',
        card?.dataset.capture === 'period' && card.querySelector('input[type="month"][data-role="month"]') && card.querySelector('[data-ref="average_user"]'));

      state.invalidateSettings?.();
      const ten = await api.createTenancy({ start: '2024-01-01', meter_ids: { heat: [pm.id] },
        prepayments: [{ from: '2024-01-01', heating_eur_month: 100, operating_eur_month: 50 }] });
      const { view: tv } = await renderView(`${ROOT}/views/tenancy.js`);
      t('tenancy: Budget, Stammdaten und Abrechnungen',
        tv.querySelector('.view-header__title')?.textContent.includes('Mietverhältnis') && tv.querySelectorAll('.card').length >= 3
          && tv.querySelector('#st-new') && tv.querySelector('#ten-edit'), `${tv.querySelectorAll('.card').length} Karten`);
      tv.querySelector('#st-new').dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
      await new Promise(r => setTimeout(r, 50));
      t('tenancy: Formular „Abrechnung erfassen" öffnet', !!global.document.querySelector('#st-form'));
      global.document.querySelector('.modal__close')?.click();
      const nav = await import(`${ROOT}/lib/nav-model.js`);
      t('nav: Seite Mietverhältnis nur zur Miete',
        nav.sectionPages('costs', { tenant: true }).some(p => p.view === 'tenancy') && !nav.sectionPages('costs', {}).some(p => p.view === 'tenancy'));
      await api.deleteTenancy(ten.id);
    } finally {
      await api.deleteMeter('waerme', pm.id).catch(() => {});
      await api.updateSettings({ active_utilities: before.active_utilities, wohnverhaeltnis: before.wohnverhaeltnis || 'eigentum' });
    }
  } catch (e) { t('H3: Zeitraum-Karte / Mietverhältnis', false, e.message); }

  // ── 2. Termine ──
  try {
    const { view } = await renderView(`${ROOT}/views/reminders.js`);
    const html = view.innerHTML;
    t('reminders: render ohne Exception', html.includes('Termine') && !html.includes('Lade Termine'));
    t('reminders: + Termin-Button vorhanden', !!view.querySelector('#rem-add'));
    t('reminders: Kalender-Abo anbietbar (H1)', !!view.querySelector('#rem-subscribe'));
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

  // ── 2a. v3.5.0 (N1025) — Kalender-Abo unter Home-Assistant-Ingress: Die
  //    Ingress-Adresse braucht eine HA-Sitzung, kein Kalender kann sie abrufen.
  //    Dann die Adresse im Netz von Home Assistant (wie die HA-Vorlagen) samt
  //    Hinweis; ohne Ingress bleibt alles wie bisher.
  try {
    const calendarDialog = async (ingressHost) => {
      const { view } = await renderView(`${ROOT}/views/reminders.js`);
      if (ingressHost) {
        global.document.head.insertAdjacentHTML('beforeend', `<meta name="et-ingress-host" content="${ingressHost}">`);
      }
      view.querySelector('#rem-subscribe')?.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
      for (let i = 0; i < 40 && !global.document.querySelector('#modal-root #cal-https'); i++) {
        await new Promise(r => setTimeout(r, 50));
      }
      return {
        https: global.document.querySelector('#modal-root #cal-https')?.value || '',
        webcal: global.document.querySelector('#modal-root #cal-webcal')?.value || '',
        hint: global.document.querySelector('#modal-root #cal-ingress-hint')?.textContent || '',
      };
    };
    const plain = await calendarDialog(null);
    t('Kalender-Abo ohne Ingress: Adresse der Seite, kein Hinweis',
      plain.https.startsWith(`${HOST}/api.php/api/calendar.ics`) && plain.hint === '', plain.https);
    const ingress = await calendarDialog('1a2b3c4d-energietracker');
    t('Kalender-Abo unter Ingress: Adresse im Netz von Home Assistant',
      ingress.https.startsWith('http://1a2b3c4d-energietracker/api.php/api/calendar.ics')
        && ingress.webcal.startsWith('webcal://1a2b3c4d-energietracker/api.php/api/calendar.ics'), ingress.https);
    t('Kalender-Abo unter Ingress: Hinweis auf den direkten Port', ingress.hint.includes('/api.php'), ingress.hint);
  } catch (e) { t('Kalender-Abo unter Ingress', false, e.message); }

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
    // v3.1.0 (H5, MKT-24) — Angaben für den Wechsel mit Kopierknopf
    const cl = view.querySelector('#t-checklist');
    t('tariff: Checkliste für den Wechsel mit letztem Stand', !!cl?.querySelector('#t-copy-checklist') && /Letzter Zählerstand/.test(cl.textContent),
      (cl?.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 90));

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
    // v3.5.0 — Verfahren des Rechentags: ⓘ nennt Basis und verlangte Monate; vor
    // einem neuen Verfahren (GModG ab 2027) steht darunter die Vorschau
    const effApi = await (await global.fetch(HOST + '/api.php/api/benchmarks/efficiency')).json().then(d => d.data ?? d);
    const cert = effApi.certificate;
    const certInfo = view.querySelector('.dash-eff__cert .info-btn')?.getAttribute('data-info-text') || '';
    t('dashboard: ⓘ der Kennzahl nennt Basis und Datenzeitraum des Verfahrens',
      !!cert && certInfo.includes(cert.fuel_basis === 'hs' ? 'nach Brennwert' : 'nach Heizwert')
        && certInfo.includes(`verlangt ${cert.months_required} Monate`), certInfo);
    const upLine = view.querySelector('[data-role="cert-upcoming"]');
    t('dashboard: Vorschau auf das neue Verfahren genau dann, wenn die API eine liefert',
      cert?.upcoming ? !!upLine && upLine.textContent.includes(`statt ${cert.months_required} Monate`)
          && upLine.textContent.includes(cert.upcoming.from.split('-').reverse().join('.'))
        : !upLine, upLine?.textContent || '(keine)');
    // v2.12.0 (Review UI-21) — „Zu tun" oben: fällige Ablesungen und Termine mit Sprung
    const todo = view.querySelector('.dash-todo');
    t('dashboard: „Zu tun" vorhanden', !!todo);
    // Demo-Daten: sieben fällige Zähler → eine Sammelzeile zur Erfassung
    t('dashboard: fällige Ablesungen führen zur Erfassung', !!todo?.querySelector('a[href^="#/zaehlerstaende"]'));
    t('dashboard: viele fällige Zähler als eine Zeile',
      todo?.querySelectorAll('a[href^="#/zaehlerstaende"]').length === 1, `${todo?.querySelectorAll('li').length} Einträge`);
    t('dashboard: überfälliger Termin führt zu den Terminen', !!todo?.querySelector('a[href="#/reminders"]'));
    t('dashboard: keine Kachel „Aktive Zähler" mehr', !/Aktive Zähler/.test(html));
    // v3.6.0 (Paket flexplus) — ohne Viertelstunden keine Kachel „Dein Hebel“, auch kein Platzhalter
    await new Promise(r => setTimeout(r, 400));
    t('dashboard: ohne Viertelstunden keine Kachel „Dein Hebel“', !view.querySelector('[data-role="flex-lever"]')
      && !view.querySelector('[data-role="flex-lever-slot"]'));
  } catch (e) { t('dashboard: render', false, e.message); }

  // ── v3.6.0 (Paket flexplus) — Kachel „Dein Hebel“ als Baustein: mit und ohne Daten ──
  try {
    const { leverTileHtml } = await import(`${ROOT}/views/dashboard.js?lever=${Date.now()}`);
    const action = { kind: 'heat_pump', name: 'Wärmepumpe', signal: 'tou', real: true, basis: 'measured', eur: 39.68,
      window: { weekday: null, from: 0, to: 6, ct: 2.4, what: 'low' } };
    const s = { year: 2025, eur: 90.63, real: true, dynamic_eur: 250.75, vat_pct: 19, measured: true, action };
    const box = global.document.createElement('div');
    box.innerHTML = leverTileHtml(s);
    const tile = box.querySelector('[data-role="flex-lever"]');
    t('Kachel „Dein Hebel“: Bento-Kachel mit großer Zahl, inkl. USt', !!tile && tile.classList.contains('dash-insight')
      && /^91\s€$/.test(tile.querySelector('.dash-lever__big')?.textContent || '') && /inkl\. USt/.test(tile.textContent), tile?.textContent.replace(/\s+/g, ' ').trim());
    t('Kachel „Dein Hebel“: klein „bis zu“ mit dynamischem Tarif', /bis zu 251\s€/.test(tile?.querySelector('.dash-lever__upto')?.textContent || ''));
    t('Kachel „Dein Hebel“: ein Handlungssatz und ein Link zur Flexibilität', tile?.querySelectorAll('.dash-lever__action').length === 1
      && /00:00–06:00 Uhr/.test(tile.querySelector('.dash-lever__action').textContent) && !!tile.querySelector('a[href="#/flexibility"]'));
    t('Kachel „Dein Hebel“: ohne gemessene Viertelstunden, ohne Hebel, ohne Antwort leer',
      leverTileHtml({ ...s, measured: false }) === '' && leverTileHtml({ ...s, eur: 0.4 }) === '' && leverTileHtml(null) === '');
    box.innerHTML = leverTileHtml({ ...s, real: false, eur: 250.75, dynamic_eur: null, action: null });
    t('Kachel „Dein Hebel“: nur dynamisch — sagt es, ohne „bis zu“ und ohne Satz', /mit dynamischem Tarif/.test(box.textContent)
      && !box.querySelector('.dash-lever__upto') && !box.querySelector('.dash-lever__action'));
    box.innerHTML = leverTileHtml(s, { beginner: true });
    t('Kachel „Dein Hebel“: Einsteiger-Übersicht als Antwortkarte', !!box.querySelector('.dash-answer.dash-lever'));
    t('Kachel „Dein Hebel“: keine rohen Schlüssel', !/dashboard\.lever|flex\.[a-z]/.test(box.textContent));
  } catch (e) { t('Kachel „Dein Hebel“', false, e.message); }

  // ── v3.4.0 (F1024) — Haken in „Zu tun", Dialog, Tab „Erledigt" mit Filtern und Rückgängig ──
  try {
    const { api } = await import(`${ROOT}/api.js`);
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    const click = (el) => el?.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    await api.clearActivity();
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    await sleep(100);
    const btn = view.querySelector('.dash-todo [data-todo-done]');
    t('dashboard: Haken neben dem Hinweis (eigener Knopf, nicht im Link, mit Namen)',
      !!btn && !btn.closest('a') && /: .+/.test(btn.getAttribute('aria-label') || ''));
    t('dashboard: kein Haken an „Ablesung fällig"', !view.querySelector('.dash-todo a[href^="#/zaehlerstaende"]')?.closest('li')?.querySelector('[data-todo-done]'));
    click(btn);
    await sleep(50);
    const modal = global.document.querySelector('#modal-root');
    t('dashboard: Dialog mit Datum, Notiz und Beleg',
      !!modal.querySelector('#dd-date') && !!modal.querySelector('#dd-note') && modal.querySelector('#dd-file')?.type === 'file');
    modal.querySelector('#dd-note').value = 'Render-Test';
    click(modal.querySelector('[data-act="save"]'));
    let log = [];
    for (let i = 0; i < 40 && !log.length; i++) { await sleep(50); log = await api.activity(); }
    t('dashboard: abgehakt → Eintrag im Verlauf (ohne Anmeldung: niemand)',
      log.length === 1 && log[0].action === 'done' && log[0].note === 'Render-Test' && log[0].by === null, JSON.stringify(log[0]?.subject));
    t('dashboard: Hinweis mit Rückgängig', !!global.document.querySelector('#toast-stack .toast button'));

    const { view: av } = await renderView(`${ROOT}/views/activity.js`);
    t('activity: Seitenkopf und Filter Zeitraum/Bereich',
      !!av.querySelector('.view-header .view-header__title') && !!av.querySelector('#act-period') && !!av.querySelector('#act-area'));
    t('activity: nach Monaten gruppiert, Eintrag mit Notiz', av.querySelectorAll('.activity-month').length === 1 && av.innerHTML.includes('Render-Test'));
    t('activity: ohne Personen kein Personenfilter', !av.querySelector('#act-person'));
    t('activity: Verwalter sieht „Verlauf verwalten"', !!av.querySelector('#act-manage'));
    const area = av.querySelector('#act-area');
    area.value = log[0].subject.kind === 'reminder' ? 'contracts' : 'reminders';
    area.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    t('activity: Filter Bereich blendet aus', !av.querySelector('.activity-item') && !!av.querySelector('.banner'));
    const area2 = av.querySelector('#act-area');
    area2.value = '';
    area2.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    click(av.querySelector('[data-undo]'));
    for (let i = 0; i < 40 && !av.querySelector('.activity-item--undone'); i++) await sleep(50);
    t('activity: Rückgängig streicht durch statt zu löschen', !!av.querySelector('.activity-item--undone s') && av.querySelectorAll('.activity-item').length === 1);
    await api.clearActivity();
  } catch (e) { t('F1024: Haken und Verlauf', false, e.message); }

  // ── v3.5.0 (F1028) — Zuständig: Feld im Dialog, Chip und Filter in Terminen und „Zu tun“;
  //    (F1029) Abrechnung erwartet → Rechnung prüfen mit offenem Abschnitt; Verlauf als CSV ──
  try {
    const { api } = await import(`${ROOT}/api.js`);
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    const click = (el) => el?.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    // der gemerkte Filter lebt im localStorage des Fensters
    const useStorage = () => {
      try { Object.defineProperty(global, 'localStorage', { value: global.window.localStorage, configurable: true, writable: true }); } catch { /* egal */ }
    };
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    const lena = await api.createReminder({ title: 'Render-Zuständig', category: 'custom', next_due: yesterday, recurrence: 'yearly', assignee: 'Lena' });
    const other = await api.createReminder({ title: 'Render-Ohne', category: 'custom', next_due: yesterday, recurrence: 'yearly' });
    try {
      let { view } = await renderView(`${ROOT}/views/reminders.js`);
      useStorage();
      localStorage.removeItem('et-assignee-filter');
      ({ view } = await renderView(`${ROOT}/views/reminders.js`));
      useStorage();
      t('Termine: Chip mit dem Namen der Zuständigen',
        [...view.querySelectorAll('.chip--assignee')].some(c => c.textContent.includes('Lena')));
      t('Termine: Filter „Zuständig“ mit Alle · Meine Aufgaben · Ohne Zuständigkeit · Namen',
        [...(view.querySelector('#rem-assignee')?.options || [])].map(o => o.value).slice(0, 3).join() === 'all,mine,none'
          && !!view.querySelector('#rem-assignee option[value="name:lena"]'));
      const sel = view.querySelector('#rem-assignee');
      sel.value = 'name:lena';
      sel.dispatchEvent(new global.window.Event('change', { bubbles: true }));
      for (let i = 0; i < 40 && view.querySelector('.loading'); i++) await sleep(25);
      const rows = [...view.querySelectorAll('tbody tr')];
      t('Termine: Filter nach Name zeigt nur ihre Termine und nennt die übrigen',
        rows.length >= 1 && rows.every(r => r.textContent.includes('Lena')) && !!view.querySelector('[data-role="assignee-hidden"]'),
        `${rows.length} Zeilen`);
      click(view.querySelector('#rem-add'));
      const modal = global.document.querySelector('#modal-root');
      const field = modal.querySelector('#f-assignee');
      t('Termin-Dialog: Feld „Zuständig“ mit Vorschlägen (Namen, Personen)',
        field?.getAttribute('list') === 'f-assignee-list' && [...modal.querySelectorAll('#f-assignee-list option')].some(o => o.value === 'Lena'));

      const { view: dv } = await renderView(`${ROOT}/views/dashboard.js`);
      useStorage();
      await sleep(100);
      t('Zu tun: Chip am Termin und Filter „Zuständig“',
        !!dv.querySelector('.dash-todo .chip--assignee') && !!dv.querySelector('.dash-todo #todo-assignee'));
      const tsel = dv.querySelector('#todo-assignee');
      t('Zu tun: gemerkte Wahl gilt auch hier (Gerät)', tsel?.value === 'name:lena');
      t('Zu tun: gefiltert — nur Lenas Termine, der Rest gezählt',
        [...dv.querySelectorAll('.dash-todo__item')].every(li => li.textContent.includes('Lena')) && !!dv.querySelector('.dash-todo [data-role="assignee-hidden"]'));
      tsel.value = 'all';
      tsel.dispatchEvent(new global.window.Event('change', { bubbles: true }));
      const bill = dv.querySelector('.dash-todo a[href^="#/bill-check?"]');
      const events = (await api.agenda(90))?.events || [];
      const expected = events.filter(e => e.kind === 'bill_expected' && e.due_now);
      t('Zu tun: „Abrechnung erwartet“ führt zum Erfassen der Rechnung', !expected.length || (!!bill && bill.getAttribute('href').includes('capture=1')),
        `${expected.length} erwartet`);
      // Sprungziel des Hinweises (aus der Agenda oder, wenn die Daten gerade keinen haben, in derselben Form)
      const href = expected[0]?.href || '#/bill-check?utility=strom&from=2025-01-01&until=2025-12-31&capture=1';
      const q = new URLSearchParams(href.split('?')[1]);
      const { view: bv } = await renderView(`${ROOT}/views/bill-check.js`, [], { query: q });
      t('Rechnung prüfen: Abschnitt „Rechnung des Versorgers“ geöffnet', bv.querySelector('[data-role="bills"]')?.open === true);
      t('Rechnung prüfen: Zeitraum aus dem Hinweis', bv.querySelector('#bc-until')?.value === q.get('until'));
    } finally {
      try { localStorage.removeItem('et-assignee-filter'); } catch { /* egal */ }
      await api.deleteReminder(lena.id).catch(() => {});
      await api.deleteReminder(other.id).catch(() => {});
    }

    const { view: av } = await renderView(`${ROOT}/views/activity.js`);
    const csv = av.querySelector('#act-csv');
    t('Erledigt: Knopf „Als CSV“ mit Filter und Sprache', !!csv && /\/api\/activity\.csv\?.*format=local/.test(csv.getAttribute('href') || '')
      && /lang=de/.test(csv.getAttribute('href') || ''));
    const area = av.querySelector('#act-area');
    area.value = 'contracts';
    area.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    t('Erledigt: CSV folgt dem Filter Bereich', /kind=[^&]*bill_expected/.test(decodeURIComponent(av.querySelector('#act-csv')?.getAttribute('href') || '')));
  } catch (e) { t('F1028/F1029: Zuständig, Abrechnung erwartet, CSV', false, e.message); }

  // ── 5. Einstellungen — seit v2.12.0 Unterseiten (Review UI-13) ──
  const settingsPage = async (page) => (await renderView(`${ROOT}/views/settings.js`, page ? [page] : [])).view;

  // v3.1.0 (I18N-29) — Sprache dieses Geräts: im Browser, nicht für alle
  try {
    const view = await settingsPage();
    const storage = global.window.localStorage;
    Object.defineProperty(global, 'localStorage', { value: storage, configurable: true, writable: true });
    storage.removeItem('et-language');
    const calls = [];
    const realFetch = global.fetch;
    global.fetch = (u, o = {}) => { calls.push(`${(o.method || 'GET').toUpperCase()} ${u}`); return realFetch(u, o); };
    const sel = view.querySelector('#lang-select');
    sel.value = 'en';
    sel.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    await new Promise(r => setTimeout(r, 500));
    global.fetch = realFetch;
    const writes = calls.filter(c => !c.startsWith('GET'));
    t('settings: Gerätesprache wechseln — merkt sich der Browser, kein PATCH',
      storage.getItem('et-language') === 'en' && writes.length === 0, writes.join(', ') || 'nur GET');
    storage.removeItem('et-language');
    const i18n = await import(`${ROOT}/lib/i18n.js`);
    await i18n.initI18n('de');
  } catch (e) { t('settings: Gerätesprache', false, e.message); }
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
    // v3.4.0 (N1019) — im Handbuch der App, ohne neuen Tab
    const guide = [...view.querySelectorAll('a[href^="#/help/doc/"]')].find(a => /anleitungen\/home-assistant\.md$/.test(a.getAttribute('href') || ''));
    t('settings/integrationen: HA-Anleitung verlinkt', !!guide && !guide.hasAttribute('target') && !view.textContent.includes('HOME-ASSISTANT.md'),
      guide?.getAttribute('href'));
    // v3.1.0 (H1, API-33) — Sensor-Vorlage auf /api/summary, je Zähler mit Schlüssel aus der Summary
    const sensors = view.querySelector('#ha-sensors')?.textContent || '';
    t('settings/integrationen: REST-Sensoren auf /api/summary', /^rest:\n  - resource: ".*\/api\.php\/api\/summary"/.test(sensors)
      && /selectattr\('key', 'eq', 'gas\.[^']+'\)/.test(sensors) && sensors.includes('device_class: monetary'), sensors.slice(0, 80));
  } catch (e) { t('settings/integrationen: render', false, e.message); }

  // ── 5b. v3.4.0 (N1021) — ein Formularraster, Einstellungen im Raster ──
  // Kästchen-Beschriftung in Satzschreibung: jedes Kästchen sitzt in einem
  // Label mit .check (oder .settings-field__check), nie in einem nackten
  // Feld-Label, das die Versalien erbt.
  const caseOk = (root) => [...root.querySelectorAll('input[type="checkbox"], input[type="radio"]')]
    .filter(i => i.closest('label')).every(i => i.closest('label').matches('.check, .settings-field__check'));
  const noOldGrid = (root) => !root.querySelector('.form-row, .form-grid, [style*="flex-end"]');
  try {
    const { view } = await renderView(`${ROOT}/views/contracts.js`, ['strom']);
    await new Promise(r => setTimeout(r, 400));
    view.querySelector('[data-action="new-contract"]')?.click();
    await new Promise(r => setTimeout(r, 400));
    const form = global.document.querySelector('#modal-root #contract-form');
    const order = [...(form?.querySelectorAll(':scope > fieldset.fgroup') || [])]
      .map(f => f.dataset.section || f.dataset.group || f.dataset.role || '');
    const want = ['contract', 'term', 'working_prices', 'base_prices', 'advance_payments', 'grid_reduction', 'grid-tou', 'bonus', 'special'];
    t('N1021 Vertrag: Gruppen in der Reihenfolge der Rechnung', want.every((k, i) => order.indexOf(k) >= 0 && (i === 0 || order.indexOf(k) > order.indexOf(want[i - 1]))), order.join(' → '));
    const notes = form?.querySelector('[name="notes"]');
    const lastGroup = [...(form?.querySelectorAll('fieldset.fgroup') || [])].at(-1);
    t('N1021 Vertrag: Notizen am Ende', !!notes && !!lastGroup && !!(lastGroup.compareDocumentPosition(notes) & global.window.Node.DOCUMENT_POSITION_FOLLOWING) && !notes.closest('fieldset'));
    t('N1021 Vertrag: Legende im Kasten, Felder im Raster', !!form?.querySelector('fieldset.fgroup > legend') && !!form?.querySelector('.fgrid > .field.field--s [name="start"]'));
    t('N1021 Vertrag: kein zweites Spaltensystem, kein flex-end', !!form && noOldGrid(form));
    t('N1021 Vertrag: Kästchen in Satzschreibung', !!form && caseOk(form));
    // Preiszeilen im Listenraster; die zweite Zeile ist „nicht die erste" (Beschriftung nur oben)
    const wp = form?.querySelector('[data-group="working_prices"]');
    wp?.querySelector('[data-action="add-row"]')?.click();
    const rows = [...(wp?.querySelectorAll('.entries.rowlist > .rowlist__row.entry-row') || [])];
    t('N1021 Vertrag: Preiszeilen im Zeilenraster mit ✕ als eigener Spalte', rows.length === 2 && rows.every(r => r.querySelector(':scope > .rowlist__del'))
      && rows[1].matches('.rowlist__row + .rowlist__row') && !!rows[1].querySelector(':scope > .field > label'), `${rows.length} Zeilen`);
    // Zeitfenster: von und bis gleich (Text), „+ Zeitfenster" im Kopf
    form?.querySelector('[data-action="add-tou"]')?.click();
    const tou = form?.querySelector('.tou-row');
    t('N1021 Vertrag: Zeitfenster von/bis einheitlich, Knopf im Gruppenkopf',
      tou?.querySelector('[data-role="tou-from"]')?.type === 'text' && tou?.querySelector('[data-role="tou-to"]')?.type === 'text'
      && form.querySelector('[data-action="add-tou"]')?.classList.contains('fgroup__add'));
  } catch (e) { t('N1021 Vertrag', false, e.message); }

  // v3.5.0 (GitHub #34) — Wasservertrag: Abschnitt „Sonderzahlungen“ im Raster
  // des Standardvertrags; die Zeile wird gespeichert (gegen den Testserver,
  // danach zurückgesetzt)
  try {
    const { api } = await import(`${ROOT}/api.js`);
    const { view } = await renderView(`${ROOT}/views/contracts.js`, ['wasser']);
    await new Promise(r => setTimeout(r, 400));
    const card = view.querySelector('.contract-card[data-utility="wasser"]');
    const counts = card?.querySelector('.contract-card__counts')?.textContent || '';
    t('#34 Wasservertrag: Karte zählt die Sonderzahlungen', /Sonderzahlung|special payment/i.test(counts), counts.replace(/\s+/g, ' ').trim());
    const cid = card?.dataset.contractId;
    card?.querySelector('[data-edit-contract]')?.click();
    await new Promise(r => setTimeout(r, 300));
    const form = global.document.querySelector('#modal-root #water-contract-form');
    const sec = form?.querySelector('fieldset.fgroup.entry-group[data-section="special"]');
    t('#34 Wasservertrag: Abschnitt Sonderzahlungen wie bei Gas (Kasten, Knopf im Kopf)', !!sec && !!sec.querySelector(':scope > legend')
      && !!sec.querySelector(':scope > [data-action="add-special"].fgroup__add'));
    sec?.querySelector('[data-action="add-special"]')?.click();
    const row = sec?.querySelector('.entries.rowlist > .special-row.rowlist__row');
    t('#34 Wasservertrag: Zeile im Zeilenraster mit Art und ✕', !!row?.querySelector('[data-role="kind"]') && !!row?.querySelector(':scope > .rowlist__del'));
    // halbe Zeile (Datum ohne Betrag): markiert, nicht abgeschickt — wie bei Gas
    row.querySelector('[data-role="date"]').value = '2025-03-15';
    global.document.querySelector('#modal-root [data-act="save"]')?.click();
    await new Promise(r => setTimeout(r, 200));
    t('#34 Wasservertrag: halbe Sonderzahlung wird vor dem Absenden markiert',
      row.querySelector('[data-role="amount"]')?.classList.contains('invalid') && !!global.document.querySelector('#modal-root #water-contract-form'));
    row.querySelector('[data-role="amount"]').value = '42,50';
    const kind = row.querySelector('[data-role="kind"]');
    kind.value = 'nachzahlung_mit';
    kind.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    t('#34 Wasservertrag: „mit Auswirkung“ zeigt die Abschlagsanpassung', row.querySelector('[data-role="advance-block"]')?.hidden === false);
    row.querySelector('[data-role="new-advance"]').value = '45';
    row.querySelector('[data-role="advance-from"]').value = '2025-04-01';
    // collectWaterForm liest das Formular mit `new FormData(form)`; Nodes
    // eigenes FormData kennt kein JSDOM-Formular — für den Klick das der Seite
    const nodeFormData = global.FormData;
    global.FormData = global.window.FormData;
    let saved = null, sp = null;
    try {
      global.document.querySelector('#modal-root [data-act="save"]')?.click();
      for (let i = 0; i < 40 && !sp; i++) {
        await new Promise(r => setTimeout(r, 100));
        saved = await api.contract('wasser', cid);
        sp = (saved?.special_payments || []).find(p => p.date === '2025-03-15') || null;
      }
    } finally { global.FormData = nodeFormData; }
    t('#34 Wasservertrag: Sonderzahlung gespeichert, mit neuem Abschlag',
      sp?.kind === 'nachzahlung_mit' && sp.amount_eur === 42.5 && sp.new_advance_eur === 45 && sp.advance_from === '2025-04-01',
      sp ? JSON.stringify(sp) : `nicht gespeichert: ${(global.document.querySelector('#toast-stack')?.textContent || '').trim()}`
        + ` [${[...(form?.querySelectorAll('.invalid') || [])].map(el => el.getAttribute('data-field') || el.getAttribute('data-role') || el.name).join(',')}]`);
    if (sp) await api.updateContract('wasser', cid, { special_payments: (saved.special_payments || []).filter(p => p !== sp) });
  } catch (e) { t('#34 Wasservertrag', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/meters.js`, ['strom']);
    await new Promise(r => setTimeout(r, 400));
    view.querySelector('[data-action="new-meter"]')?.click();
    await new Promise(r => setTimeout(r, 300));
    const form = global.document.querySelector('#modal-root #meter-form');
    const first = form?.querySelector('fieldset.fgroup');
    t('N1021 Zähler: Rolle und Erfassung in der ersten Gruppe', first?.dataset.section === 'meter'
      && !!first.querySelector('[name="role"]') && !!first.querySelector('[name="capture"]') && !!first.querySelector('[name="name"]'));
    const groups = [...(form?.querySelectorAll('fieldset.fgroup') || [])].map(f => f.dataset.section || f.dataset.role || '');
    t('N1021 Zähler: Gerät → Aufbau → Marktlokation → Zäsuren', ['device', 'topology', 'market', 'baseline'].every((k, i, a) => groups.indexOf(k) > (i ? groups.indexOf(a[i - 1]) : 0)), groups.join(' → '));
    const notes = form?.querySelector('[name="notes"]');
    t('N1021 Zähler: Notizen am Ende, ohne Kasten', !!notes && !notes.closest('fieldset') && !!(form.querySelector('[data-section="baseline"]').compareDocumentPosition(notes) & global.window.Node.DOCUMENT_POSITION_FOLLOWING));
    t('N1021 Zähler: Zäsur-Knopf im Raster statt flex-end', !!form?.querySelector('.fgrid > .field > #bl-add:first-child') && noOldGrid(form));
  } catch (e) { t('N1021 Zähler', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/tariff.js`);
    await new Promise(r => setTimeout(r, 300));
    const util = view.querySelector('#t-util');
    if (util) { util.value = 'strom'; util.dispatchEvent(new global.window.Event('change', { bubbles: true })); }
    await new Promise(r => setTimeout(r, 600));
    view.querySelector('#t-addshadow')?.click();
    await new Promise(r => setTimeout(r, 200));
    const body = global.document.querySelector('#modal-root .modal__body');
    const dyn = [...(body?.querySelectorAll('[data-dyn]') || [])];
    // Bis v3.3 übersteuerte `.form-grid label{display:flex}` das hidden-Attribut
    t('N1021 Angebot: Felder des dynamischen Tarifs mit hidden, im Raster statt .form-grid',
      dyn.length === 2 && dyn.every(el => el.hidden && el.matches('.fgrid > .field')) && !body.querySelector('.form-grid'), `${dyn.length} Felder`);
    t('N1021 Angebot: Kästchen in Satzschreibung', !!body && caseOk(body));
  } catch (e) { t('N1021 Angebot', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/reminders.js`);
    view.querySelector('#rem-add')?.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    const body = global.document.querySelector('#modal-root .modal__body');
    t('N1021 Termin: Formularraster, Intervall per hidden', !!body?.querySelector('.fgrid > .field > #f-title') && body.querySelector('#f-rm-wrap')?.hidden === true
      && !body.querySelector('.form-grid'));
  } catch (e) { t('N1021 Termin', false, e.message); }

  // Einstellungen: jede Karte im Raster, breite Karten am Ende
  try {
    const outside = [], order = [];
    for (const page of ['general', 'household', 'utilities', 'data', 'integrations', 'access', 'expert', 'system']) {
      const view = await settingsPage(page);
      view.querySelectorAll('.card').forEach(c => { if (!c.parentElement?.classList.contains('settings-grid')) outside.push(`${page}:${c.id || c.className}`); });
      // „Daten": der einzelne Verweis auf den Jahresbericht steht bewusst am Ende
      if (page !== 'data') view.querySelectorAll('.settings-grid').forEach(g => {
        const cards = [...g.children];
        const firstWide = cards.findIndex(c => c.classList.contains('settings-card--wide'));
        if (firstWide >= 0 && cards.slice(firstWide).some(c => !c.classList.contains('settings-card--wide'))) order.push(page);
      });
    }
    t('N1021 Einstellungen: jede Karte im Raster', outside.length === 0, outside.join(', '));
    t('N1021 Einstellungen: breite Karten am Seitenende', order.length === 0, order.join(', '));
    const util = await settingsPage('utilities');
    const au = util.querySelector('#active-utils-card');
    t('N1021 Einstellungen: „Aktive Verbrauchsarten" schmal, Kästchen in Spalten',
      !!au && !au.classList.contains('settings-card--wide') && util.querySelector('#active-utils')?.classList.contains('check-grid'));
    const integ = await settingsPage('integrations');
    t('N1021 Einstellungen: Home Assistant geteilt — Token schmal, Vorlagen breit',
      !integ.querySelector('#ha-card').classList.contains('settings-card--wide') && !!integ.querySelector('#ha-card #btn-ha-generate, #ha-card #btn-ha-revoke')
      && integ.querySelector('#ha-setup-card')?.classList.contains('settings-card--wide') && !!integ.querySelector('#ha-setup-card #ha-yaml'));
    const access = await settingsPage('access');
    t('N1021 Einstellungen: Anmeldung schmal, API-Schlüssel breit',
      !!access.querySelector('#security-card') && !access.querySelector('#security-card').classList.contains('settings-card--wide')
      && access.querySelector('#keys-card')?.classList.contains('settings-card--wide') && !!access.querySelector('#keys-card #btn-key-create')
      && noOldGrid(access));
    const system = await settingsPage('system');
    const link = system.querySelector('#support-card a[data-support-link]');
    t('N1021 System: „Energietracker unterstützen" mit Link in neuem Tab',
      link?.getAttribute('href') === 'https://buymeacoffee.com/bingerminger' && link.getAttribute('target') === '_blank'
      && link.getAttribute('rel') === 'noopener noreferrer' && !system.querySelector('#support-card').classList.contains('settings-card--wide'),
      link?.outerHTML.slice(0, 120));
  } catch (e) { t('N1021 Einstellungen', false, e.message); }

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
    // Seite, die Gas-Ansicht verweist mit Zähler und Jahr darauf (seit v3.1.0 mit Art)
    const bcHrefs = [...view.querySelectorAll('a[href^="#/bill-check"]')].map(a => a.getAttribute('href'));
    t('utility(gas): Verweis auf die Rechnungsprüfung', bcHrefs.some(h => h.startsWith('#/bill-check?utility=gas&meter=')),
      bcHrefs.join(' ') || 'kein Verweis');
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

  // v3.1.0 (H5) — Rechnungsprüfung für Strom: Kostenspalten ohne Gasspalten, Formular „Laut Rechnung"
  try {
    const { view } = await renderView(`${ROOT}/views/bill-check.js`, [],
      { query: new URLSearchParams('utility=strom&from=2025-01-01&to=2026-01-01') });
    for (let i = 0; i < 40 && !view.querySelector('#bc-result table'); i++) await new Promise(r => setTimeout(r, 100));
    const head = [...view.querySelectorAll('#bc-result thead th')].map(th => th.textContent.trim());
    t('billCheck(strom): Kostenspalten ohne Gasspalten',
      head.includes('Verbrauchskosten') && head.includes('Feste Kosten') && !head.includes('Zustandszahl'), head.join('|'));
    t('billCheck(strom): eigene Summe genannt', /Nachgerechnet: .*\d/.test(view.querySelector('#bc-result .bill-check-total')?.textContent || ''));
    for (let i = 0; i < 20 && !view.querySelector('#bi-form'); i++) await new Promise(r => setTimeout(r, 100));
    t('billCheck(strom): Formular „Laut Rechnung" ohne CO₂-Felder', !!view.querySelector('#bi-form') && !view.querySelector('#bi-co2kg'));
  } catch (e) { t('billCheck(strom): render', false, e.message); }

  // v3.4.0 (N1021) — über „Kosten → Rechnung prüfen“ (ohne Parameter): Die
  // Seite rechnet sofort mit der letzten abgeschlossenen Abrechnungsperiode.
  // Bis v3.3 blieb das Ergebnis leer, bis jemand „Nachrechnen“ drückte.
  try {
    const { view } = await renderView(`${ROOT}/views/bill-check.js`, [], { query: new URLSearchParams('') });
    for (let i = 0; i < 40 && !view.querySelector('#bc-result table'); i++) await new Promise(r => setTimeout(r, 100));
    const y = new Date().getFullYear() - 1;
    t('billCheck ohne Parameter: Tabelle ohne Klick', !!view.querySelector('#bc-result table'));
    t('billCheck ohne Parameter: letzte abgeschlossene Periode, bis einschließlich',
      view.querySelector('#bc-from')?.value === `${y}-01-01` && view.querySelector('#bc-until')?.value === `${y}-12-31`,
      `${view.querySelector('#bc-from')?.value} – ${view.querySelector('#bc-until')?.value}`);
    const cap = view.querySelector('details[data-role="bills"]');
    t('billCheck ohne Parameter: Erfassen eingeklappt unter dem Ergebnis', !!cap && !cap.open && !!cap.querySelector('#bi-form'));
  } catch (e) { t('billCheck ohne Parameter: render', false, e.message); }

  // ── 7a. Utility-View Wasser: Spalte Sonderzahlungen wie bei Gas ──
  // v3.5.0 (GitHub #34) — bis v3.4 hieß die Prüfung „keine Spalte
  // Sonderzahlungen“ und hielt den Fehler fest: Wasser kannte keine.
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['wasser']);
    await new Promise(r => setTimeout(r, 500));
    t('utility(wasser): render ohne Exception', view.innerHTML.length > 50);
    t('utility(wasser): Spalte Sonderzahlungen wie bei Gas (#34)', !!view.querySelector('.contracts-table th.special-col')
      && view.querySelectorAll('.contracts-table td.special-cell').length >= 1);
  } catch (e) { t('utility(wasser): render', false, e.message); }

  // ── 7b. Utility-View Strom: Rechnungsprüfung seit v3.1.0 (H5) auch hier ──
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['strom']);
    await new Promise(r => setTimeout(r, 500));
    t('utility(strom): Rechnungsprüfung verlinkt', !!view.querySelector('a[href^="#/bill-check?utility=strom"]'));
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

  // ── v3.3.0 (F1021) — Flexibilität ohne Viertelstunden: Hinweise statt Fehler ──
  try {
    const { view } = await renderView(`${ROOT}/views/flexibility.js`, [], { query: new URLSearchParams('year=2025') });
    await new Promise(r => setTimeout(r, 400));
    const html = view.innerHTML;
    t('flexibility: render ohne Exception', html.includes('section-head') && !html.includes('banner--error'));
    // v3.6.0 — statt zweier Hinweise eine Einladung: was fehlt und was es bringt
    const missing = [...view.querySelectorAll('.flex-missing [data-missing]')].map(li => li.dataset.missing);
    t('flexibility: Einladung ohne Preise und ohne Viertelstunden', missing.includes('prices') && missing.includes('intervals') && missing.includes('devices'), missing.join());
    t('flexibility: Einladung mit Knopf für die Börsenpreise', !!view.querySelector('.flex-missing [data-act="flex-smard"]'));
    t('flexibility: ohne Daten keine Ampel, kein Hebel, keine Karte', !view.querySelector('#flex-ampel') && !view.querySelector('.flex-levers') && !view.querySelector('.ws-card'));
    t('flexibility: SMARD-Knopf mit Jahr', /2025/.test(view.querySelector('#flex-smard')?.textContent || ''));
    t('flexibility: keine rohen Schlüssel', !/flex\.[a-z]/.test(view.textContent));
  } catch (e) { t('flexibility: render', false, e.message); }

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
    t('forecast: Methode lesbar', /Mischung: \d+\s%\sHeizkurve/.test(tableText) && !/blend\(reg=/.test(tableText));
  } catch (e) { t('forecast: render', false, e.message); }

  // ── 8b. v3.6.0 (F1031) — Prognose-Werkstatt ──
  try {
    const { view } = await renderView(`${ROOT}/views/forecast.js`);
    await new Promise(r => setTimeout(r, 900));
    const card = () => view.querySelector('#ws-card')?.textContent || '';
    t('werkstatt: Ergebniskarte mit großer Zahl', !!view.querySelector('#ws-card .ws-card__big'), card().slice(0, 80));
    t('werkstatt: drei Kennzahlen für alle', view.querySelectorAll('#ws-kpis .kpi:not([data-min-level])').length === 3);
    t('werkstatt: Einsteiger-Regler Winter, Sparen, Tarif ohne Stufe',
      ['#ws-winter', '#ws-saving', '#ws-tariff'].every(s => !view.querySelector(s)?.closest('[data-min-level]')));
    t('werkstatt: Maßnahmen und Modell ab Erfahren', view.querySelector('#ws-measures')?.closest('[data-min-level]')?.dataset.minLevel === 'advanced'
      && view.querySelector('#ws-model')?.closest('[data-min-level]')?.dataset.minLevel === 'advanced');
    t('werkstatt: Optimierung, Modellvergleich, CSV ab Experte',
      ['#ws-opt', '#ws-models', '#ws-csv', '#ws-budget'].every(s => view.querySelector(s)?.closest('[data-min-level]')?.dataset.minLevel === 'expert'));
    t('werkstatt: Kurve kumuliert gezeichnet', (global.Chart.instances || []).filter(i => i.canvas?.id === 'ws-chart-cum' && !i.destroyed).length === 1);
    t('werkstatt: bisherige Prognose aufklappbar erreichbar', !!view.querySelector('details#fc-classic #fc-table table'));
    t('werkstatt: Beispielszenarien', view.querySelectorAll('#ws-presets [data-preset="cold_winter"], #ws-presets [data-preset="save10"]').length === 2);
    const before = card();
    const slider = view.querySelector('#ws-saving');
    slider.value = '20';
    slider.dispatchEvent(new global.window.Event('input', { bubbles: true }));
    await new Promise(r => setTimeout(r, 1000));
    t('werkstatt: Regler rechnet neu (entprellt)', card() !== before && /spart dir/.test(card()), card().slice(0, 120));
    t('werkstatt: Rangliste nennt Sparen', /Sparen \(Verhalten\)/.test(view.querySelector('#ws-ranking')?.textContent || ''));
    view.querySelector('#ws-presets [data-preset="cold_winter"]')?.click();
    await new Promise(r => setTimeout(r, 900));
    t('werkstatt: Beispiel „Kalter Winter“ mit eigenem Satz', /Ein kalter Winter kostet dich/.test(card()), card().slice(0, 120));
    t('werkstatt: Regler folgt dem Beispiel', Number(view.querySelector('#ws-winter').value) > 0 && view.querySelector('#ws-saving').value === '0');
    global.window.localStorage.removeItem('et-scenarios');
    view.querySelector('#ws-name').value = 'Mein Winter';
    view.querySelector('#ws-save').click();
    const saved = JSON.parse(global.window.localStorage.getItem('et-scenarios') || '[]');
    t('werkstatt: Szenario je Gerät gespeichert', saved.length === 1 && saved[0].name === 'Mein Winter' && saved[0].state.winter_pct > 0);
    view.querySelector('#ws-compare-btn').click();
    await new Promise(r => setTimeout(r, 900));
    t('werkstatt: Vergleich rechnet gespeicherte Szenarien', view.querySelectorAll('#ws-compare tbody tr').length === 1
      && /Mein Winter/.test(view.querySelector('#ws-compare')?.textContent || ''));
    // Maßnahme mit Kosten und Budget → beste Kombination
    view.querySelector('#ws-add-measure').click();
    const m = view.querySelector('.ws-measure');
    m.querySelector('[data-f="heat"]').value = '20';
    m.querySelector('[data-f="cost"]').value = '5000';
    view.querySelector('#ws-budget').value = '6000';
    view.querySelector('#ws-budget').dispatchEvent(new global.window.Event('input', { bubbles: true }));
    await new Promise(r => setTimeout(r, 1100));
    t('werkstatt: Optimierung unter dem Budget', /Mit 6\.000(,00)?\s€/.test(view.querySelector('#ws-opt')?.textContent || '')
      && !!view.querySelector('#ws-opt-apply'), `${(view.querySelector('#ws-opt')?.textContent || '').slice(0, 140)} ${view.querySelector('#ws-banner')?.textContent || ''}`);
    t('werkstatt: Ehrlichkeit — Modellrechnung, keine Messung', /Modellrechnung, keine Messung/.test(view.querySelector('#ws-honesty')?.textContent || ''));
    // Druckansicht aus dem gemerkten Szenario dieser Sitzung
    // im selben Fenster (derselbe Speicher des Browsers), gespeichertes Szenario per ?s=
    const printMod = await import(`${ROOT}/views/scenario-print.js?t=${Date.now()}`);
    const pv = { view: global.document.createElement('div') };
    global.document.body.append(pv.view);
    await printMod.render(pv.view, [], { query: new URLSearchParams('s=Mein%20Winter') });
    await new Promise(r => setTimeout(r, 300));
    const ptxt = pv.view.textContent || '';
    t('werkstatt: Druckansicht mit Annahmen, Vergleich, Kurven', !!pv.view.querySelector('.report-print.scenario-print')
      && /Annahmen/.test(ptxt) && /kälter/.test(ptxt) && pv.view.querySelectorAll('svg.scenario-print__svg').length === 2, ptxt.replace(/\s+/g, ' ').slice(0, 300));
  } catch (e) { t('werkstatt: render', false, e.stack || e.message); }

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
    // v3.4.0 — unbefristet/weiterlaufend: „Jederzeit kündbar“ statt eines Stichtags „heute“
    t('contractsOverview: unbefristeter Vertrag „Jederzeit kündbar“',
      !!view.querySelector('.contract-overview[data-utility="fernwaerme"]')?.textContent.includes('Jederzeit kündbar'));
    t('contractsOverview: befristeter Vertrag mit datiertem Stichtag',
      !!view.querySelector('.contract-overview[data-utility="gas"]')?.textContent.includes('Kündigen bis'));
  } catch (e) { t('contractsOverview: render', false, e.message); }

  // ── 13. v2.11.0 — Jahresbericht als eigene Seite ──
  try {
    const { view } = await renderView(`${ROOT}/views/report.js`);
    const open = view.querySelector('#report-open');
    t('report: im Browser öffnen (inline, neuer Tab)',
      (open?.getAttribute('href') || '').includes('inline=1') && open?.getAttribute('target') === '_blank');
    t('report: Herunterladen ohne inline', !(view.querySelector('#report-download')?.getAttribute('href') || '').includes('inline'));
  } catch (e) { t('report: render', false, e.message); }

  // ── 13b. v3.1.0 (I18N-12) — Druckansicht des Jahresberichts, de und fr ──
  try {
    const year = new Date().getFullYear() - 1;
    const i18n = await import(`${ROOT}/lib/i18n.js`);
    const rawKey = /\b[a-z]+(\.[a-zA-Z0-9]+){2,}\b/;
    for (const lang of ['de', 'fr']) {
      await i18n.initI18n(lang);
      const { view } = await renderView(`${ROOT}/views/report-print.js`, [], { query: new URLSearchParams(`year=${year}`) });
      // v3.4.0 (F1025) — dasselbe Modell wie das PDF: das Wichtigste, Erfolge,
      // Aufgaben, je Verbrauchsart ein Abschnitt, Themen, Wörterbuch
      const utilities = view.querySelectorAll('.report-print__utility').length;
      const text = view.querySelector('.report-print')?.textContent || '';
      t(`report-print (${lang}): das Wichtigste, Erfolge, Aufgaben, Wörterbuch, je Verbrauchsart ein Abschnitt`,
        !!view.querySelector('.report-print__cover') && !!view.querySelector('.report-print__successes-section')
        && !!view.querySelector('.report-print__tasks-section') && !!view.querySelector('.report-print__glossary') && utilities >= 3,
        `${utilities} Verbrauchsarten`);
      const lights = [...view.querySelectorAll('.report-print__light')];
      t(`report-print (${lang}): vier Ampeln, jede mit Symbol und Wort`,
        lights.length === 4 && lights.every(l => /[✔▲✖●]\s*\S/.test(l.querySelector('.report-print__light-word')?.textContent || '')));
      t(`report-print (${lang}): „Stand heute“ als Kasten`, view.querySelectorAll('.report-print__today').length >= 2);
      t(`report-print (${lang}): Schaubilder aus explainer.js (Energiefluss der PV, Zeitstrahl)`,
        !!view.querySelector('.explainer.ex-energy') && !!view.querySelector('.explainer.ex-timeline'));
      t(`report-print (${lang}): ohne Häkchen kein Anhang`, !view.querySelector('.report-print__appendix'));
      if (lang === 'de') {
        // v3.6.0 (abrplus) — Kosten wie die Übersicht: Heizöl und Pellets aus ihren Lieferungen
        const money = view.querySelector('.report-print__money')?.textContent || '';
        t('report-print (de): Heizöl und Pellets im Abschnitt „Geld“, ihre Seiten mit Kosten aus den Lieferungen',
          /Heizöl/.test(money) && /pellets/i.test(money) && (text.match(/aus den Lieferungen/g) || []).length >= 2, money.slice(0, 160));
      }
      t(`report-print (${lang}): kein Rohschlüssel, kein „undefined"`, !rawKey.test(text) && !/undefined|NaN/.test(text),
        (text.match(rawKey) || text.match(/undefined|NaN/) || [''])[0]);
      if (lang === 'fr') {
        t('report-print (fr): Zahlen mit geschütztem Tausendertrenner', /\d[  ]\d{3}/.test(text));
        t('report-print (fr): französische Überschriften', /Rapport annuel/.test(text) && !/Jahresbericht/.test(text));
      }
    }
    {
      await i18n.initI18n('de');
      const { view } = await renderView(`${ROOT}/views/report-print.js`, [], { query: new URLSearchParams(`year=${year}&detail=1`) });
      const tables = view.querySelectorAll('.report-print__appendix table');
      t('report-print: mit Häkchen der Anhang „Zahlen im Detail“, je Verbrauchsart eine Tabelle mit Kopfzeile',
        tables.length >= 3 && [...tables].every(tb => tb.querySelector('thead th[scope="col"]')), `${tables.length} Tabellen`);
      t('report-print: Häkchen gesetzt', view.querySelector('#print-detail')?.checked === true);
    }
    {
      // v3.6.0 — „Drucken“ führt am iPad in den Druckdialog; daneben die PDF-Datei mit derselben Auswahl
      await i18n.initI18n('de');
      const { view } = await renderView(`${ROOT}/views/report-print.js`, [],
        { query: new URLSearchParams(`year=${year}&detail=1&billing=1&flex=1&utilities=gas,strom&scenario=Kalter%20Winter`) });
      const a = view.querySelector('#print-pdf');
      const href = a?.getAttribute('href') || '';
      t('report-print: Knopf „Jahresbericht herunterladen“ neben „Drucken“', !!a && a.hasAttribute('download') && /yearly\.pdf\?/.test(href), href);
      t('report-print: PDF mit derselben Auswahl (Jahr, Anhang, Abrechnungsjahre, Flexibilität, Teilbericht)',
        [`year=${year}`, 'detail=1', 'billing=1', 'flex=1', 'utilities=gas,strom', 'lang='].every(p => href.includes(p)), href);
      t('report-print: Szenario nicht im PDF (gibt es nur in der Druckansicht)', !/scenario/.test(href), href);
    }
    // v3.1.0 (I18N-18) — Hauptansichten in fr und en: kein Rohschlüssel, kein „undefined"
    for (const lang of ['fr', 'en']) {
      await i18n.initI18n(lang);
      const bad = [];
      for (const [mod, params] of [['dashboard', []], ['utility', ['gas']], ['contracts-overview', []], ['settings', []],
        ['help', []], ['bill-check', []], ['tariff', []]]) {
        const { view } = await renderView(`${ROOT}/views/${mod}.js`, params, { query: new URLSearchParams() });
        await new Promise(r => setTimeout(r, 300));
        const text = view.textContent || '';
        const hit = text.match(rawKey) || text.match(/\bundefined\b|\bNaN\b/);
        if (hit) bad.push(`${mod}: ${hit[0]}`);
      }
      t(`Hauptansichten (${lang}): kein Rohschlüssel, kein „undefined"`, bad.length === 0, bad.join(' · '));
    }
    await i18n.initI18n('de');
    const css = readFileSync(new URL('../public/css/print.css', import.meta.url), 'utf8');
    t('print.css: @media print blendet Seitenleiste und Knöpfe aus, A4',
      /@media print[\s\S]*\.sidebar[\s\S]*display:\s*none/.test(css) && /size:\s*A4/.test(css));
    const shell = readFileSync(new URL('../index.php', import.meta.url), 'utf8');
    t('print.css: in index.php eingebunden', shell.includes('public/css/print.css'));
    const { view } = await renderView(`${ROOT}/views/report.js`);
    t('report: Druckansicht ist der Hauptknopf', /#\/report\/print\?year=\d{4}/.test(view.querySelector('#report-print.btn--primary')?.getAttribute('href') || ''));
  } catch (e) { t('report-print: render', false, e.message); }

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
    // v2.14.0 — jeder Link zeigt auf eine Seite aus lib/docs.js (DocsIntegrityTest prüft, dass es sie gibt)
    // v3.4.0 (N1019) — in der App (#/help/doc/…), nicht mehr auf GitHub
    const docs = await import(`${ROOT}/lib/docs.js`);
    const hrefs = [...view.querySelectorAll('.help-links a, [data-role="handbook"]')].map(a => a.getAttribute('href') || '');
    const inApp = (h) => h.startsWith('#/help/doc/') && docs.DOC_PATHS.includes('docs/' + decodeURIComponent(h.slice('#/help/doc/'.length).split('?')[0]));
    t('help: Handbuch und Doku-Links in der App', hrefs.length === 8 && hrefs.every(inApp)
      && view.querySelector('[data-role="handbook"]')?.getAttribute('href') === '#/help/doc/README.md', hrefs.join(' '));
    t('help: Doku-Links ohne neuen Tab', !view.querySelector('a[href^="#/help/doc/"][target]'));
    // v3.4.0 — alle Anwendungsfälle A–G und die Stufen, je mit Sprung in den Abschnitt
    const cases = [...view.querySelectorAll('.help-cases__item')];
    const more = cases.map(el => el.querySelector('.help-cases__more a')?.getAttribute('href') || '');
    t('help: Anwendungsfälle in jeder Sprache', cases.length === docs.CASES.length && docs.CASES.length >= 8
      && !cases.some(el => /help\.cases\./.test(el.textContent)), `${cases.length} Fälle`);
    t('help: jeder Fall springt in seinen Abschnitt', more.every((h, i) => inApp(h) && h.endsWith(`?h=${docs.CASES[i].anchor}`)), more.slice(0, 3).join(' '));
    t('help: FAQ und Fehlersuche verlinkt', hrefs.some(h => /einstieg\/faq\.md$/.test(h)) && hrefs.some(h => /betrieb\/fehlersuche\.md$/.test(h)));
    t('help: Handbuch liegt auf dem Server (Hinweis mit Version)', view.textContent.includes('1.3.0'));
    t('help: Checkliste Erste Schritte', !!view.querySelector('[data-role="setup"] .setup-list, [data-role="setup"] .banner--success'));
  } catch (e) { t('help: render', false, e.message); }

  // ── 16b. v3.4.0 (N1019) — Handbuch in der App, vom eigenen Server ──
  try {
    const { view } = await renderView(`${ROOT}/views/help-doc.js`, ['anleitungen/anwendungsfaelle.md'],
      { query: new URLSearchParams('h=case-heat') });
    t('help-doc: Seite vom Server gerendert', !!view.querySelector('.doc-body h1') && view.querySelector('.doc-body')?.getAttribute('lang') === 'de',
      view.querySelector('.doc-body h1')?.textContent);
    t('help-doc: Inhaltsverzeichnis mit Sprüngen', view.querySelectorAll('.doc-toc a[href^="#/help/doc/anleitungen/anwendungsfaelle.md?h="]').length >= 7);
    t('help-doc: Sprungmarke des Anwendungsfalls (Präfix d-)', !!view.querySelector('#d-case-heat') && !view.querySelector('#case-heat'));
    t('help-doc: Umschalter Deutsch/Englisch behält die Sprungmarke',
      !!view.querySelector('.doc-langs a[href="#/help/doc/en/anleitungen/anwendungsfaelle.md?h=case-heat"]')
      && view.querySelector('.doc-langs [aria-current]')?.getAttribute('lang') === 'de');
    const links = [...view.querySelectorAll('.doc-body a[href]')].map(a => a.getAttribute('href'));
    t('help-doc: Doku-Links bleiben in der App, kein blob/…/docs', links.filter(h => h.startsWith('#/help/doc/')).length > 20
      && !links.some(h => /github\.com\/[^ ]*\/blob\//.test(h)), `${links.length} Links`);
    t('help-doc: externe Links mit rel="noopener noreferrer"', [...view.querySelectorAll('.doc-body a[href^="http"]')]
      .every(a => a.target === '_blank' && a.rel === 'noopener noreferrer'));
    t('help-doc (de): kein Englisch-Hinweis', !view.querySelector('.doc-hint'));
    // Sprung innerhalb der Seite: scrollen statt neu aufbauen
    const toc = view.querySelector('.doc-toc a');
    const ev = new global.window.MouseEvent('click', { bubbles: true, cancelable: true });
    toc.dispatchEvent(ev);
    t('help-doc: Inhaltsverzeichnis springt ohne Neuaufbau', ev.defaultPrevented && global.location.hash === toc.getAttribute('href'), global.location.hash);
  } catch (e) { t('help-doc: render', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/help-doc.js`, ['referenz/ansichten.md']);
    const imgs = [...view.querySelectorAll('.doc-body img')];
    const src = imgs[0]?.getAttribute('src') || '';
    const r = src ? await fetch(src) : null;
    t('help-doc: Bilder über /api/docs/asset mit Version', imgs.length > 10 && imgs.every(i => /\/api\/docs\/asset\?path=ui%2Fscreenshots%2F.+&v=1\.3\.0$/.test(i.getAttribute('src')))
      && r?.ok && r.headers.get('content-type') === 'image/png', `${imgs.length} Bilder, ${src.slice(-60)}`);
    t('help-doc: Tabellen und Code scrollen in sich', !!view.querySelector('.doc-body .doc-table > table'));
    const lic = (await renderView(`${ROOT}/views/help-doc.js`, ['root/LICENSE'])).view;
    t('help-doc: LICENSE als Text', !!lic.querySelector('pre.doc-plain') && /GNU AFFERO/.test(lic.textContent));
    const nope = (await renderView(`${ROOT}/views/help-doc.js`, ['nope.md'])).view;
    t('help-doc: unbekannte Seite → Hinweis und Weg zurück', !!nope.querySelector('.banner--warning') && !nope.querySelector('.doc-body')
      && !!nope.querySelector('a[href="#/help/doc/README.md"]'), nope.querySelector('.banner--warning')?.textContent);
    await renderView(`${ROOT}/views/help-doc.js`, ['ERSTE-SCHRITTE.md'], { query: new URLSearchParams() });
    t('help-doc: alter Pfad führt zur neuen Seite', global.location.hash === '#/help/doc/einstieg/erste-schritte.md', global.location.hash);
    const i18n = await import(`${ROOT}/lib/i18n.js`);
    for (const lang of ['fr', 'en']) {
      await i18n.initI18n(lang);
      const v = (await renderView(`${ROOT}/views/help-doc.js`, ['en/README.md'])).view;
      const hint = v.querySelector('.doc-hint')?.textContent || '';
      if (lang === 'fr') t('help-doc (fr): Hinweis „englische Fassung“ auf Französisch', /version anglaise/.test(hint), hint);
      else t('help-doc (en): kein Hinweis', !hint);
    }
    await i18n.initI18n('de');
  } catch (e) { t('help-doc: Bilder, Text, Fehler', false, e.message); }

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

    // v3.1.0 (I18N-27) — Rechnungsbegriff des eingestellten Landes
    const countries = await (await global.fetch(HOST + '/api.php/api/countries')).json().then(d => d.data ?? d);
    gl.setBillContext(countries.find(c => c.code === 'FR'));
    global.document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    main.innerHTML = `<p>${gl.info('workingPrice')}</p>`;
    main.querySelector('.info-btn').dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true }));
    pop = global.document.querySelector('.info-pop');
    t('ⓘ Arbeitspreis mit Land FR nennt „Prix du kWh"', /Prix du kWh/.test(pop?.querySelector('.info-pop__bill')?.textContent || ''),
      pop?.querySelector('.info-pop__bill')?.textContent || 'keine Zeile');
    t('Tarifvergleich FR: amtliches Portal', gl.billPortal()?.url === 'https://comparateur-offres.energie-info.fr/');
    gl.setBillContext(countries.find(c => c.code === 'DE'));
    t('Deutschland: kein amtliches Portal', gl.billPortal() === null);
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
    // v2.16.0 — Datentabellen der Diagramme beginnen ebenfalls mit „Monat“; gemeint ist die Monatstabelle
    const monthTbl = [...view.querySelectorAll('.table')].find(tb => headsOf(tb)[0] === 'Monat' && !tb.closest('.chart-data'));
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

  // ── 17. v2.15.0 — Diagramme, die stimmen (F1) ──
  const chartOn = (id) => (global.Chart.instances || []).filter(i => i.canvas?.id === id && !i.destroyed);
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 700));
    const [mc] = chartOn('month-chart');
    // v2.16.0 — vorn steht das Vorjahr; das laufende Jahr trägt Farben als Funktion
    const ds = (mc?.config?.data?.datasets || []).find(d => typeof d.backgroundColor === 'function');
    const months = mc?.config?.data?.labels || [];
    const rows = [...view.querySelectorAll('.table tbody tr')];
    const partialRow = rows.find(r => /^\d+ \/ \d+$/.test(r.children[1]?.textContent.trim() || ''));
    t('utility(gas): Monatschart eingetragen, Farbe als Funktion (folgt dem Theme)', !!mc && typeof ds?.borderColor === 'function' && typeof ds?.backgroundColor === 'function');
    const pIdx = months.length - 1;
    const fullBg = ds?.backgroundColor?.({ dataIndex: 0 }) || '', partBg = ds?.backgroundColor?.({ dataIndex: pIdx }) || '';
    t('utility(gas): Teilmonat blass, volle Monate kräftig', /0\.12\)$/.test(partBg) && /0\.35\)$/.test(fullBg), `${fullBg} | ${partBg}`);
    const footer = mc?.config?.options?.plugins?.tooltip?.callbacks?.footer?.([{ dataIndex: pIdx }]) || '';
    t('utility(gas): Tooltip nennt die erfassten Tage', /Teilmonat: 14 von 31 Tagen/.test(footer), footer);
    t('utility(gas): Teilmonat in der Tabelle „14 / 31“ und erklärt', !!partialRow && partialRow.classList.contains('is-partial')
      && /nur teilweise erfasst/.test(view.textContent) && !!view.querySelector('.chart-note'));
    t('utility(gas): Kurzbeschreibung mit Summe und Monaten', /^Balkendiagramm: Gas 2026 je Monat, zusammen .+ am meisten im .+ am wenigsten im /.test(view.querySelector('#month-chart')?.getAttribute('aria-label') || ''),
      view.querySelector('#month-chart')?.getAttribute('aria-label'));
    const banner = view.querySelector('.status-banner')?.textContent || '';
    t('utility(gas): Trend gegen das Vorjahr statt „3-Monats-Trend“', /gegenüber Vorjahr/.test(banner) && !/3-Monats-Trend/.test(banner), banner.replace(/\s+/g, ' ').trim().slice(0, 120));
    // Witterungsbereinigt heißt Heizmodell (`heat_adjusted`, v2.8.0) — das Altfeld
    // `weather_adjusted` skaliert auch das Warmwasser und bleibt nur für die API
    const { api: apiMod } = await import(`${ROOT}/api.js`);
    const cd = await import(`${ROOT}/lib/chart-data.js`);
    const gasMeters = await apiMod.meters('gas');
    const cons = await apiMod.meterConsumption('gas', gasMeters[0].id);
    const want = cd.yoyTrend(cons.monthly || [], 'kwh', { months: 3, adjustedKey: 'heat_adjusted' });
    const wantTxt = want ? new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Math.abs(want.pct)) + ' %' : '';
    t('utility(gas): Trend aus dem Heizmodell (heat_adjusted)', !!want && want.adjusted && banner.includes(wantTxt), `${wantTxt} in „${banner.replace(/\s+/g, ' ').trim().slice(0, 140)}“`);
    t('utility(gas): Jahres-Pille mit aria-pressed', view.querySelector('.year-pills .pill.active')?.getAttribute('aria-pressed') === 'true'
      && view.querySelectorAll('.year-pills .pill[aria-pressed="false"]').length >= 1);
  } catch (e) { t('utility(gas): F1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas'], { query: new URLSearchParams('year=2025') });
    await new Promise(r => setTimeout(r, 700));
    t('utility(gas): Direktlink ?year=2025 wählt das Jahr', view.querySelector('.year-pills .pill.active')?.textContent.trim() === '2025');
    t('utility(gas): Auswahl steht in der Adresse', /[?&]year=2025/.test(global.window.location.hash), global.window.location.hash);
  } catch (e) { t('utility(gas): Direktlink', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    await new Promise(r => setTimeout(r, 700));
    t('dashboard: jede Karte nennt ihren Zeitraum', view.querySelectorAll('.dash-window').length >= 1
      && [...view.querySelectorAll('.dash-window')].every(p => /^Zeitraum: /.test(p.textContent)));
    const [dc] = chartOn('dash-chart');
    t('dashboard: Verlauf mit Theme-Farben und Datentabelle', !!dc && typeof dc.config.data.datasets[0]?.borderColor === 'function'
      && view.querySelectorAll('[data-role="chart-extra"] details.chart-data tbody tr').length > 0);
    t('dashboard: Trend nennt denselben Zeitraum ein Jahr zuvor', [...view.querySelectorAll('.kpi__trend')].every(s => /ein Jahr zuvor/.test(s.getAttribute('aria-label') || '')));
  } catch (e) { t('dashboard: F1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/analysis.js`, ['strom']);
    await new Promise(r => setTimeout(r, 700));
    const sel = view.querySelector('#util-select');
    if (sel && sel.value !== 'strom' && [...sel.options].some(o => o.value === 'strom')) {
      sel.value = 'strom'; sel.dispatchEvent(new global.window.Event('change'));
      await new Promise(r => setTimeout(r, 800));
    }
    t('analyse(strom): Saisonprofil heißt Balken-, Jahresvergleich Liniendiagramm (bis v2.14 vertauscht)',
      /^Balkendiagramm/.test(view.querySelector('#ch-seasonal')?.getAttribute('aria-label') || '')
        && /^Liniendiagramm/.test(view.querySelector('#ch-years')?.getAttribute('aria-label') || ''));
    const [yc] = chartOn('ch-years');
    const dashes = (yc?.config?.data?.datasets || []).map(d => JSON.stringify(d.borderDash));
    t('analyse(strom): Jahre unterscheiden sich nicht nur in der Farbe', dashes.length < 2 || new Set(dashes).size === dashes.length, dashes.join(' '));
    t('analyse(strom): Saison und Jahre als Tabelle', !!view.querySelector('[data-role="seasonal-data"] details.chart-data')
      && !!view.querySelector('[data-role="years-data"] details.chart-data'));
  } catch (e) { t('analyse(strom): F1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/temperatures.js`);
    await new Promise(r => setTimeout(r, 400));
    const [tc] = chartOn('temp-chart');
    const labels = (tc?.config?.data?.datasets || []).map(d => d.label);
    t('temperatures: Reihen übersetzt (bis v2.14 fest „Max/ø/Min“)', [...labels].sort().join() === 'Maximum,Minimum,Mittel', labels.join());
    t('temperatures: Monatswerte als Tabelle', view.querySelectorAll('[data-role="temp-chart-data"] tbody tr').length > 0);
  } catch (e) { t('temperatures: F1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/forecast.js`);
    await new Promise(r => setTimeout(r, 700));
    view.querySelector('#btn-go')?.click();
    view.querySelector('#btn-go')?.click();
    await new Promise(r => setTimeout(r, 900));
    t('forecast: zwei schnelle Läufe, ein Chart', chartOn('fc-chart').length === 1, `${chartOn('fc-chart').length} offen`);
    t('forecast: Verlauf und Prognose als Tabelle', view.querySelectorAll('[data-role="fc-chart-data"] tbody tr').length > 0);
    t('forecast: Kurzbeschreibung mit Zeitraum', /^Liniendiagramm: Verbrauch .+ und Prognose bis /.test(view.querySelector('#fc-chart')?.getAttribute('aria-label') || ''));
  } catch (e) { t('forecast: F1', false, e.message); }

  // ── 18. v2.16.0 — Was die Rechnung weiß (F2) ──
  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 900));
    const [bc] = chartOn('balance-chart');
    const bds = bc?.config?.data?.datasets || [];
    t('utility(gas): Saldo-Verlauf mit Kosten und Bezahltem', bds.length === 2 && bds.every(d => d.data.length >= 2)
      && !!view.querySelector('.balance-path details.chart-data tbody tr') && /am Ende (Guthaben|Nachzahlung)/.test(view.querySelector('#balance-chart')?.getAttribute('aria-label') || ''),
      view.querySelector('#balance-chart')?.getAttribute('aria-label'));
    const [mc] = chartOn('month-chart');
    const mds = mc?.config?.data?.datasets || [];
    t('utility(gas): Vorjahr im Monatschart, links (order)', /zum Vergleich/.test(mds[0]?.label || '') && mds[0].order < (mds.find(d => typeof d.backgroundColor === 'function')?.order ?? 0),
      mds.map(d => `${d.label}:${d.order}`).join(' | '));
    t('utility(gas): Monatstabelle mit Spalte „bereinigt“', [...view.querySelectorAll('.table thead th')].some(th => /bereinigt/.test(th.textContent)));
    const btn = view.querySelector('[data-chart-mode="adjusted"]');
    btn?.click();
    await new Promise(r => setTimeout(r, 200));
    const [mc2] = chartOn('month-chart');
    const labels2 = (mc2?.config?.data?.datasets || []).map(d => d.label);
    t('utility(gas): Umschalter „Witterungsbereinigt“ zeichnet bereinigt, ohne Temperatur', !!btn && btn.getAttribute('aria-pressed') === 'true'
      && labels2.some(l => /witterungsbereinigt/.test(l)) && !labels2.some(l => /Temperatur/.test(l))
      && /Normaljahr/.test(view.querySelector('[data-role="month-chart-note"]')?.textContent || ''), labels2.join(' | '));
    const adjVals = (mc2?.config?.data?.datasets || []).find(d => /witterungsbereinigt/.test(d.label))?.data || [];
    const { api: apiMod } = await import(`${ROOT}/api.js`);
    const gm = await apiMod.meters('gas');
    const cons = await apiMod.meterConsumption('gas', gm[0].id);
    const yr = Math.max(...(cons.monthly || []).map(m => m.year));
    const want = (cons.monthly || []).filter(m => m.year === yr).map(m => m.heat_adjusted ?? null);
    t('utility(gas): bereinigt heißt heat_adjusted (Heizmodell), nicht das Altfeld', JSON.stringify(adjVals) === JSON.stringify(want), `${JSON.stringify(adjVals)} vs ${JSON.stringify(want)}`);
  } catch (e) { t('utility(gas): F2', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['strom']);
    await new Promise(r => setTimeout(r, 700));
    t('utility(strom): keine Bereinigung ohne Heizbezug', !view.querySelector('[data-chart-mode]')
      && ![...view.querySelectorAll('.table thead th')].some(th => /bereinigt/.test(th.textContent)));
  } catch (e) { t('utility(strom): F2', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['pv_erzeugung']);
    await new Promise(r => setTimeout(r, 900));
    const [pc] = chartOn('pv-flow-chart');
    const pds = pc?.config?.data?.datasets || [];
    t('utility(pv_erzeugung): Energiefluss gestapelt mit Netzbezug', pds.length === 3 && pds.filter(d => d.stack === 'pv').length === 2
      && pds.some(d => d.type === 'line') && pc.config.options.scales.y.stacked === true
      && /Monaten mit Daten aller drei Zähler/.test(view.querySelector('#pv-flow-chart')?.getAttribute('aria-label') || ''),
      view.querySelector('#pv-flow-chart')?.getAttribute('aria-label'));
    // Die Teile ergeben das Ganze: erzeugt = selbst genutzt + eingespeist (nur gedeckte Monate)
    const nums = ((view.querySelector('#pv-flow-chart')?.getAttribute('aria-label') || '').match(/[\d.]+(?=\skWh)/g) || []).map(s => Number(s.replace(/\./g, '')));
    t('utility(pv_erzeugung): Kurzbeschreibung — erzeugt = selbst genutzt + eingespeist', nums.length >= 3 && Math.abs(nums[0] - nums[1] - nums[2]) <= 2, nums.join(' | '));
    // v3.4.0 (F1026) — Schaubild Energiefluss aus der echten Antwort: Jahr/Monat,
    // Knöpfe, Grafik aria-hidden; ein Wechsel auf den Monat bleibt im Bild
    const ef = view.querySelector('.ex-energy');
    const efSvg = ef?.querySelector('svg.ef');
    t('utility(pv_erzeugung): Energiefluss mit Zeitraum und Knöpfen', !!efSvg && efSvg.getAttribute('aria-hidden') === 'true'
      && ef.querySelectorAll('.ef-chip[aria-pressed]').length >= 3 && !!ef.querySelector('[data-ef-scope="month"]'),
      ef?.querySelector('.explainer__text')?.textContent);
    ef?.querySelector('[data-ef-scope="month"]')?.click();
    t('utility(pv_erzeugung): Energiefluss auf Monat umgeschaltet', ef?.querySelector('[data-ef-scope="month"]')?.getAttribute('aria-pressed') === 'true'
      && !/^\d{4}/.test(ef.querySelector('.ef-period__label')?.textContent || ''), ef?.querySelector('.ef-period__label')?.textContent);
    // v3.5.0 (F1027) — „Tag“ fragt den Server (energy-flow/days); ohne
    // Viertelstunden des Netzbezugs bleibt er verborgen
    const efDays = await (await fetch(`${HOST}/api/energy-flow/days`)).json();
    const dayBtn = ef?.querySelector('[data-ef-scope="day"]');
    t('utility(pv_erzeugung): „Tag“ nur mit Viertelstunden', !!dayBtn && dayBtn.hidden === !efDays.data?.available,
      `available=${efDays.data?.available}, hidden=${dayBtn?.hidden}`);
  } catch (e) { t('utility(pv_erzeugung): F2', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    await new Promise(r => setTimeout(r, 900));
    const sparks = [...view.querySelectorAll('canvas[data-spark]')];
    t('dashboard: kleine Vielfache — ein Verlauf je Karte', sparks.length >= 3 && sparks.every(c => chartOn(c.id).length || (global.Chart.instances || []).some(i => i.canvas === c && !i.destroyed)),
      `${sparks.length} Karten`);
    const [tc] = chartOn('dash-chart');
    const tl = (tc?.config?.data?.datasets || []).map(d => d.label);
    t('dashboard: „Energie gesamt“ gestapelt, ohne Wasser und PV', !!tc && tc.config.options.scales.y.stacked === true && tl.length >= 2
      && !tl.some(l => /Wasser|PV/.test(l)) && /Energie gesamt/.test(view.querySelector('#dash-chart')?.closest('.card')?.textContent || ''), tl.join(', '));
  } catch (e) { t('dashboard: F2', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/temperatures.js`);
    await new Promise(r => setTimeout(r, 400));
    const [tc] = chartOn('temp-chart');
    const max = (tc?.config?.data?.datasets || []).find(d => d.label === 'Maximum');
    t('temperatures: Spanne Min–Max als Band', !!max && max.fill === '-1');
  } catch (e) { t('temperatures: F2', false, e.message); }

  // ── v3.6.0 (F1030, #33) — Abrechnungsjahr statt Kalenderjahr: Umschalter in
  //    Verbrauchsart, Übersicht und Analyse, gemerkte Wahl, Arten ohne Rechnung
  const patchSettings = (body) => fetch(`${HOST}/api/settings`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  try {
    await patchSettings({ billing_cycle_anchor_gas: '09-01' });
    localStorage.setItem('et-period-mode', 'billing');
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 900));
    t('abrechnungsjahr(gas): Umschalter steht auf Abrechnungsjahr', view.querySelector('.period-mode-bar [data-period-mode="billing"]')?.getAttribute('aria-pressed') === 'true');
    const pills = [...view.querySelectorAll('.period-pills .pill')].map(p => p.textContent.trim());
    t('abrechnungsjahr(gas): Perioden nach dem Stichtag statt Jahren', pills.length >= 2 && pills.some(p => /^\d{4}\/\d{2}/.test(p)) && !view.querySelector('.year-pills:not(.period-pills)'), pills.join(' | '));
    const active = view.querySelector('.period-pills .pill.active')?.textContent.replace('·', '').trim() || '';
    t('abrechnungsjahr(gas): Kennzahlen nennen die Periode', !!active && view.querySelector('.kpi__label')?.textContent.includes(active), active);
    t('abrechnungsjahr(gas): Zeitraum und Grundlage benannt', /nach dem Stichtag/.test(view.querySelector('[data-role="period-meta"]')?.textContent || ''));
    // v3.6.0 (abrplus) — gerechnet bis zur letzten Ablesung: ⓘ „Laufendes Jahr“ an der Abschlagskachel
    const advLabel = view.querySelector('.kpi.c-yellow .kpi__label');
    t('abrechnungsjahr(gas): ⓘ „Laufendes Jahr“ an „Abschläge bis …“', /Abschläge bis/.test(advLabel?.textContent || '')
      && !!advLabel?.querySelector('[data-glossary="runningYear"]'), advLabel?.textContent);
    const [mc] = chartOn('month-chart');
    const labels = (mc?.config?.data?.datasets || []).map(d => d.label);
    t('abrechnungsjahr(gas): Diagramm vergleicht mit der Vorperiode', labels.some(l => /^Vorperiode /.test(l)), labels.join(', '));
    // v3.6.0 (Paket abrechnung) — „Rechnung prüfen“ folgt der gewählten (abgeschlossenen) Periode
    const gm = (await (await fetch(`${HOST}/api/utility/gas/meters`)).json()).data[0];
    const gp = (await (await fetch(`${HOST}/api/utility/gas/meters/${gm.id}/billing-periods`)).json()).data.periods.filter(p => p.days > 0);
    const gSel = gp[gp.length - 1];
    const gDone = gp.filter(p => p.complete && !p.running);
    const gWant = gSel.complete && !gSel.running ? gSel : gDone[gDone.length - 1];
    const gHref = view.querySelector('a[href^="#/bill-check"]')?.getAttribute('href') || '';
    t('abrechnungsjahr(gas): „Rechnung prüfen“ mit der gewählten bzw. jüngsten abgeschlossenen Periode', !!gWant && gHref.includes(`from=${gWant.from}&until=${gWant.until}`), gHref);
    const older = [...view.querySelectorAll('.period-pills .pill')].find(p => gDone.some(x => x.from === p.dataset.period && x.from !== gWant?.from));
    if (older) {
      older.click();
      await new Promise(r => setTimeout(r, 700));
      const oHref = view.querySelector('a[href^="#/bill-check"]')?.getAttribute('href') || '';
      const o = gDone.find(x => x.from === older.dataset.period);
      t('abrechnungsjahr(gas): eine ältere Periode gewählt → „Rechnung prüfen“ mit ihr', oHref.includes(`from=${o.from}&until=${o.until}`), oHref);
      t('abrechnungsjahr(gas): abgeschlossene Periode ohne ⓘ „Laufendes Jahr“', !view.querySelector('[data-glossary="runningYear"]'));
    }
    let fired = null;
    const onMode = (e) => { fired = e.detail?.mode; };
    global.window.addEventListener('et:periodmode', onMode);
    view.querySelector('.period-mode-bar [data-period-mode="calendar"]')?.click();
    global.window.removeEventListener('et:periodmode', onMode);
    t('abrechnungsjahr: Klick merkt die Wahl je Gerät und zeichnet neu', localStorage.getItem('et-period-mode') === 'calendar' && fired === 'calendar', `${localStorage.getItem('et-period-mode')}/${fired}`);
    localStorage.setItem('et-period-mode', 'billing');
  } catch (e) { t('abrechnungsjahr(gas): F1', false, e.message); }

  // v3.6.0 (abrplus) — Lieferantenwechsel ohne Schlussrechnung: Die Periode endet
  // am Wechsel und sagt es (Schaufenster Gas: anderer Versorger ab 01.01.2025);
  // im Kalenderjahr das ⓘ „Laufendes Jahr“, solange die Kachel „bis …“ rechnet
  try {
    const gm = (await (await fetch(`${HOST}/api/utility/gas/meters`)).json()).data[0];
    const sw = (await (await fetch(`${HOST}/api/utility/gas/meters/${gm.id}/billing-periods`)).json()).data.periods.find(p => p.ends_at_switch && p.source === 'anchor');
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 900));
    view.querySelector(`.period-pills .pill[data-period="${sw?.from}"]`)?.click();
    await new Promise(r => setTimeout(r, 700));
    const meta = view.querySelector('[data-role="period-meta"]')?.textContent || '';
    t('abrechnungsjahr(gas): Periode endet am Lieferantenwechsel und nennt ihn', !!sw && /endet mit dem Lieferantenwechsel/.test(meta), `${sw?.from}…${sw?.until} · ${meta}`);
    localStorage.setItem('et-period-mode', 'calendar');
    const cal = await renderView(`${ROOT}/views/utility.js`, ['gas']);
    await new Promise(r => setTimeout(r, 900));
    const label = cal.view.querySelector('.kpi.c-yellow .kpi__label');
    const until = /Abschläge bis/.test(label?.textContent || '');
    t('kalenderjahr(gas): ⓘ „Laufendes Jahr“ genau an „Abschläge bis …“', !!label && until === !!label.querySelector('[data-glossary="runningYear"]'), label?.textContent);
    localStorage.setItem('et-period-mode', 'billing');
    const oil = await renderView(`${ROOT}/views/utility.js`, ['heizoel']);
    await new Promise(r => setTimeout(r, 700));
    t('heizoel: ohne Abrechnungsjahr kein ⓘ „Laufendes Jahr“', !oil.view.querySelector('[data-glossary="runningYear"]'));
  } catch (e) { t('abrechnungsjahr(gas): Lieferantenwechsel', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['heizoel']);
    await new Promise(r => setTimeout(r, 700));
    t('abrechnungsjahr(heizoel): Kalenderjahr mit kurzem Hinweis', !!view.querySelector('[data-role="calendar-only"]') && !view.querySelector('.period-mode-bar') && !view.querySelector('.period-pills'));
  } catch (e) { t('abrechnungsjahr(heizoel): F1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    await new Promise(r => setTimeout(r, 900));
    const gasWin = view.querySelector('.card[data-utility="gas"] .dash-window')?.textContent || '';
    t('abrechnungsjahr(Übersicht): Umschalter und laufende Periode je Art', !!view.querySelector('.dash-period-mode [data-period-mode="billing"][aria-pressed="true"]')
      && /^Abrechnungsjahr \d{4}\/\d{2}: /.test(gasWin), gasWin);
  } catch (e) { t('abrechnungsjahr(Übersicht): F1', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/analysis.js`, ['gas']);
    await new Promise(r => setTimeout(r, 900));
    const [yc] = chartOn('ch-years');
    const series = (yc?.config?.data?.datasets || []).map(d => d.label);
    t('abrechnungsjahr(Analyse): Vergleich nach Perioden', /Abrechnungsjahre im Vergleich/.test(view.textContent) && series.some(l => /^\d{4}\/\d{2}$/.test(l))
      && /^Sep/.test(yc?.config?.data?.labels?.[0] || ''),`${series.join(', ')} · ${yc?.config?.data?.labels?.[0]}`);
  } catch (e) { t('abrechnungsjahr(Analyse): F1', false, e.message); }

  // v3.6.0 (Paket abrechnung) — Zählergruppen der Übersicht in derselben Periode
  // (Summe vom Server, ohne den Subzähler); Saldo nach einer gebuchten Rechnung
  const send = (method, path, body) => fetch(`${HOST}${path}`, { method, headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  try {
    const wm = (await (await fetch(`${HOST}/api/utility/wasser/meters`)).json()).data;
    const g = (await (await send('POST', '/api/utility/wasser/meter-groups', { name: 'Haus und Garten' })).json()).data;
    for (const m of wm) await send('PATCH', `/api/utility/wasser/meters/${m.id}`, { meter_group_id: g.id });
    const ub = (await (await fetch(`${HOST}/api/utility/wasser/billing-periods`)).json()).data.periods.filter(p => p.days > 0);
    const gw = (await (await fetch(`${HOST}/api/utility/wasser/meter-groups/${g.id}/billing-periods`)).json()).data.periods.filter(p => p.days > 0);
    const want = gw.find(p => p.from === ub[ub.length - 1]?.from) || gw[gw.length - 1];
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    await new Promise(r => setTimeout(r, 900));
    const val = view.querySelector('.card[data-utility="wasser"] .dash-groups__val')?.textContent.trim() || '';
    t('abrechnungsjahr(Übersicht): Zählergruppe in derselben Periode wie die Karte, ohne Subzähler', !!want
      && val.startsWith(`${Math.round(want.m3)} m³`) && val.includes(String(want.cost.toFixed(2)).replace('.', ',')), `${val} · ${want?.m3} m³ ${want?.cost} €`);
  } catch (e) { t('abrechnungsjahr(Übersicht): Gruppen', false, e.message); }

  try {
    const fb = (await (await fetch(`${HOST}/api/utility/fernwaerme/bills`)).json()).data[0];
    const booked = await send('POST', `/api/utility/fernwaerme/bills/${fb.id}/book`);
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['fernwaerme']);
    await new Promise(r => setTimeout(r, 900));
    const next = new Date(`${fb.period_to}T12:00:00Z`); next.setUTCDate(next.getUTCDate() + 1);
    const day = `${String(next.getUTCDate()).padStart(2, '0')}.${String(next.getUTCMonth() + 1).padStart(2, '0')}.${next.getUTCFullYear()}`;
    const notes = [...view.querySelectorAll('.balance-note')].map(n => n.textContent).join(' | ');
    t('Saldo-Karte: nach der gebuchten Rechnung „Gerechnet ab“ dem Tag danach', booked.ok && notes.includes(`Gerechnet ab ${day}`), notes);
  } catch (e) { t('Saldo-Karte: gebuchte Rechnung', false, e.message); }
  localStorage.removeItem('et-period-mode');
  await patchSettings({ billing_cycle_anchor_gas: '01-01' }).catch(() => {});

  // ── v3.6.0 (Paket B „Oberfläche“) — Einordnung als Ampel, CO₂-Liste zu,
  //    ruhige Formulare unter „Zugriff“ (Safari am iPad)
  try {
    freshDom();
    const i18n = await import(`${ROOT}/lib/i18n.js`);
    await i18n.initI18n('de');
    const { comparisonCardHtml } = await import(`${ROOT}/views/dashboard.js?b=${Date.now()}`);
    const card = (c) => { const d = global.document.createElement('div'); d.innerHTML = comparisonCardHtml(c, [{ key: 'gas', label: 'Gas' }]); return d; };
    const base = { year: 2025, supported: true, source: null, strom: null, heating: [], water: null, missing: [], links: { stromspiegel: 'https://www.stromspiegel.de/' } };
    // ohne eigene Vergleichswerte: Wasser als Ampel, Einladung mit genau einem Link zum Feld
    const without = card({ ...base, strom: { kwh: 4116, complete: true, own_value: null, delta_pct: null, rating: null, special_household: false },
      water: { m3: 179, complete: true, persons: 4, reference_l: 122, liters_per_person_day: 100, index: 82, delta_pct: -18, rating: 'good' },
      missing: ['reference_strom_kwh', 'reference_heat_kwh_m2'] });
    const links = [...without.querySelectorAll('a')].map(a => a.getAttribute('href'));
    t('einordnung ohne Vergleichswerte: Einladung mit genau einem Link zum Feld', links.length === 1 && links[0] === '#/settings/household?field=reference_strom_kwh', links.join(', '));
    t('einordnung: keine Links aus der App heraus (Strom-/Heizspiegel)', !without.querySelector('a[target="_blank"], a[href^="http"]'));
    const wrow = without.querySelector('.cmp-row');
    t('einordnung: Wasser gegen den Richtwert als Ampel mit Wort und Satz', wrow?.dataset.rating === 'good' && /^Gut Wasser: 100 Liter je Person und Tag, 18\s%\sunter dem Richtwert \(122 Liter\)\./.test(wrow.textContent.replace(/\s+/g, ' ').trim()),
      wrow?.textContent.replace(/\s+/g, ' ').trim());
    // mit Vergleichswerten: Ampel je Zeile, keine Einladung
    const withOwn = card({ ...base,
      strom: { kwh: 3650, complete: true, own_value: 3400, delta_pct: 7.4, rating: 'near', special_household: true },
      heating: [{ utility: 'gas', kwh_per_m2: 150, weather_adjusted: true, complete: true, own_value: 120, delta_pct: 25, rating: 'high' }],
      water: { m3: 120, complete: true, persons: 4, reference_l: 122, liters_per_person_day: 82, index: 67, delta_pct: -33, rating: 'good' }, source: 'Beispielquelle' });
    const ratings = [...withOwn.querySelectorAll('.cmp-row')].map(r => r.dataset.rating);
    t('einordnung mit Vergleichswerten: Ampel je Zeile (gelb, rot, grün), Wort neben der Farbe', ratings.join(',') === 'near,high,good'
      && [...withOwn.querySelectorAll('.cmp-row__label')].map(l => l.textContent).join('|') === 'Darüber|Deutlich darüber|Gut', ratings.join(','));
    t('einordnung: klarer Satz mit Abweichung und Vergleichswert', /Haushaltsstrom: 3\.650 kWh, 7\s%\süber deinem Vergleichswert \(3\.400 kWh\)\./.test(withOwn.textContent)
      && /Gas: 150 kWh\/m², 25\s%\süber deinem Vergleichswert \(120 kWh\/m²\)\./.test(withOwn.textContent), withOwn.querySelector('.cmp-row')?.textContent.replace(/\s+/g, ' '));
    t('einordnung: ohne fehlende Angabe keine Einladung, Quelle steht da', !withOwn.querySelector('[data-role="cmp-invite"]') && /Beispielquelle/.test(withOwn.textContent));
    // unvollständiges Jahr: Zeile ohne Ampel mit Hinweis
    const partial = card({ ...base, strom: { kwh: 1800, complete: false, own_value: 3400, delta_pct: null, rating: null, special_household: false } });
    t('einordnung: halbes Jahr ohne Ampel, mit Hinweis', partial.querySelector('.cmp-row')?.dataset.rating === 'none' && /alle Monate/.test(partial.textContent));
    // Fläche fehlt: genau dieser Link
    t('einordnung: fehlt die Wohnfläche, führt der Link dorthin', card({ ...base, missing: ['wohnflaeche_m2'] }).querySelector('a')?.getAttribute('href') === '#/settings/household?field=wohnflaeche_m2');
    // nichts Sinnvolles: keine Kachel
    t('einordnung: ohne Zeile und ohne Einladung keine Kachel', comparisonCardHtml(base) === '' && comparisonCardHtml({ supported: false }) === '');
  } catch (e) { t('einordnung: Kachel', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/dashboard.js`);
    await new Promise(r => setTimeout(r, 600));
    const c = view.querySelector('[data-role="comparison"]');
    t('einordnung (Übersicht, Beispieldaten): Kachel ohne Links nach außen, höchstens ein Link',
      !c || (!c.querySelector('a[target="_blank"]') && c.querySelectorAll('a').length <= 1), c ? c.textContent.replace(/\s+/g, ' ').slice(0, 160) : 'ausgeblendet');
  } catch (e) { t('einordnung (Übersicht)', false, e.message); }

  try {
    // Sprung aus der Einladung: Seite Haushalt, Feld im Fokus
    const { view } = await renderView(`${ROOT}/views/settings.js`, ['household'], { query: new URLSearchParams('field=reference_strom_kwh') });
    await new Promise(r => setTimeout(r, 50));
    const el = view.querySelector('[data-key="reference_strom_kwh"]');
    t('einordnung → Einstellungen: das Feld hat den Fokus und ist markiert', !!el && global.document.activeElement === el && el.closest('.settings-field')?.hasAttribute('data-target'));
  } catch (e) { t('einordnung → Einstellungen', false, e.message); }

  try {
    localStorage.removeItem('et-settings-co2-open');
    const { view } = await renderView(`${ROOT}/views/settings.js`, ['utilities']);
    const d = view.querySelector('[data-collapse="co2"] details.disclosure');
    t('CO₂-Emissionsfaktoren: lange Liste zugeklappt, Felder darin', !!d && !d.open && !!d.querySelector('[data-key="co2_gas"]') && !!d.querySelector('[data-co2years]')
      && d.dataset.disclosure === 'et-settings-co2-open', d ? `open=${d.open}` : 'kein Aufklappbereich');
    // Fehler im Feld: klappt auf
    const co2 = d.querySelector('[data-key="co2_gas"]');
    co2.value = 'abc';
    co2.dispatchEvent(new global.window.Event('input', { bubbles: true }));
    view.querySelector('#btn-save')?.click();
    await new Promise(r => setTimeout(r, 50));
    t('CO₂-Emissionsfaktoren: bei einem Fehler im Feld klappt die Liste auf', d.open && co2.classList.contains('invalid'));
    // nach dem Speichern: geänderte Liste offen (auf diesem Gerät gemerkt)
    const { view: v2 } = await renderView(`${ROOT}/views/settings.js`, ['utilities']);
    const d2 = v2.querySelector('[data-collapse="co2"] details.disclosure');
    const was = (await (await fetch(`${HOST}/api/settings`)).json()).data.co2_wasser;
    d2.open = false;
    const f2 = d2.querySelector('[data-key="co2_wasser"]');
    f2.value = String((Number(was) || 350) + 1);
    f2.dispatchEvent(new global.window.Event('input', { bubbles: true }));
    v2.querySelector('#btn-save')?.click();
    await new Promise(r => setTimeout(r, 400));
    t('CO₂-Emissionsfaktoren: nach dem Speichern ist die Liste offen', d2.open === true);
    await fetch(`${HOST}/api/settings`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ co2_wasser: was }) });
    localStorage.removeItem('et-settings-co2-open');
  } catch (e) { t('CO₂-Emissionsfaktoren: zugeklappt', false, e.message); }

  try {
    const { view } = await renderView(`${ROOT}/views/forecast.js`);
    const fc = view.querySelector('#fc-classic');
    t('Prognose im Detail: derselbe Aufklappbereich (components/disclosure.js)', !!fc && fc.matches('details.disclosure.fc-classic') && fc.dataset.disclosure === 'et-fc-classic-open');
  } catch (e) { t('Prognose im Detail: Aufklappbereich', false, e.message); }

  try {
    // Zugriff mit Anmeldung (Antworten für Sitzung und Personen nachgestellt):
    // Passwortfelder im Formular, Eingaben über die Klassen mit 16 px bei Touch
    const sessionPw = { mode: 'password', authenticated: true, role: 'admin', named_login: true,
      user: { id: 'u_1', name: 'Alex', role: 'admin', source: 'password', prefs: {} } };
    const fake = (u) => /\/api\/session(\?|$)/.test(u) ? sessionPw : /\/api\/users(\?|$)/.test(u)
      ? [{ id: 'u_1', name: 'Alex', role: 'admin', source: 'password' }, { id: 'u_2', name: 'Kim', role: 'member', source: 'password' }] : null;
    const withSession = async (page) => {
      const dom = freshDom();
      const real = global.fetch;
      global.fetch = (input, init) => {
        const data = (!init?.method || init.method === 'GET') ? fake(String(input)) : null;
        return data ? Promise.resolve(new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } })) : real(input, init);
      };
      try {
        const view = global.document.getElementById('view');
        const mod = await import(`${ROOT}/views/settings.js?s=${Date.now()}`);
        await mod.render(view, [page], {});
        return { view, dom };
      } finally { global.fetch = real; }
    };
    const { view: acc, dom } = await withSession('access');
    const { view: gen } = await withSession('general');
    const pws = [...acc.querySelectorAll('input[type="password"]'), ...gen.querySelectorAll('input[type="password"]')];
    t('Zugriff/Allgemein: jedes Passwortfeld steht in einem Formular', pws.length >= 3 && pws.every(i => i.closest('form')), `${pws.length} Felder, außerhalb: ${pws.filter(i => !i.closest('form')).map(i => i.id).join(', ')}`);
    const controls = [...acc.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select, textarea')];
    t('Zugriff: alle Eingaben tragen .input/.select (16 px bei Touch), keine eigene Schriftgröße',
      controls.length >= 5 && controls.every(el => el.matches('.input, .select, textarea.input') && !/font-size/.test(el.getAttribute('style') || '')),
      controls.filter(el => !el.matches('.input, .select, textarea.input')).map(el => el.id || el.outerHTML.slice(0, 60)).join(', '));
    // Stile wie im Browser: Touch-Abfragen setzen 16 px, keine andere Regel macht eine dieser Eingaben kleiner
    const { readFileSync } = await import('node:fs');
    const cssText = ['app.css', 'components.css'].map(f => readFileSync(resolve(ROOT, '..', 'css', f), 'utf8')).join('\n');
    const style = dom.window.document.createElement('style');
    style.textContent = cssText;
    dom.window.document.head.appendChild(style);
    const touch16 = [];
    const smaller = [];
    const walk = (rules, media) => {
      for (const r of rules) {
        if (r.cssRules && r.media) { walk(r.cssRules, r.media.mediaText); continue; }
        const fs = r.style?.getPropertyValue?.('font-size');
        if (!fs || !r.selectorText) continue;
        const px = /^(\d+(?:\.\d+)?)px$/.test(fs) ? parseFloat(fs) : null;
        const hits = controls.filter(el => { try { return el.matches(r.selectorText); } catch { return false; } });
        if (!hits.length) continue;
        if (media && /coarse/.test(media) && px !== null && px >= 16) touch16.push(media);
        else if (r.selectorText.replace(/\s+/g, ' ') !== '.input, .select, textarea.input') smaller.push(`${media || '—'} ${r.selectorText} ${fs}`);
      }
    };
    walk(style.sheet.cssRules, '');
    t('Zugriff: 16 px bei Touch (auch iPad mit Trackpad: any-pointer)', touch16.some(m => /\(pointer: coarse\)/.test(m)) && touch16.some(m => /any-pointer: coarse/.test(m)), touch16.join(' | '));
    t('Zugriff: keine Regel setzt eine Eingabe unter 16 px', smaller.length === 0, smaller.join(' | '));
  } catch (e) { t('Zugriff: ruhige Formulare', false, e.message); }

  // ── 19. v3.6.0 — Flexibilität lenkt (Beispielhaushalt mit Viertelstunden) ──
  // Zuletzt, weil der Beispielhaushalt alle Daten des Testservers ersetzt.
  try {
    const imp = await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ force: true, persona: 'eigenheim-modern' }) });
    t('flex: Beispielhaushalt „Eigenheim modern“ geladen', imp.ok, `HTTP ${imp.status}`);
    const api = (await (await fetch(`${HOST}/api/flexibility?year=2025`)).json()).data;
    const x = api.insights;
    const { view } = await renderView(`${ROOT}/views/flexibility.js`, [], { query: new URLSearchParams('year=2025') });
    await new Promise(r => setTimeout(r, 500));
    const { fmt } = await import(`${ROOT}/lib/format.js`);
    const big = view.querySelector('.ws-card__big')?.textContent || '';
    t('flex: große Zahl vom Server (mit dem Vertrag, wie er ist)', x.total_real_eur >= 1 && big === fmt.eur0(x.total_real_eur), `${big} / ${x.total_real_eur}`);
    t('flex: Karte als Bild speicherbar', !!view.querySelector('#flex-image'));
    const acts = [...view.querySelectorAll('.flex-actions__list li')];
    t('flex: ein Handlungssatz je Gerät mit Zeitfenster', acts.length === x.actions.filter(a => a.window).length && acts.length >= 3, `${acts.length}`);
    t('flex: Handlungssatz mit Uhrzeit und Betrag', /\d{2}:00/.test(acts[0]?.textContent || '') && /€/.test(acts[0]?.textContent || ''), acts[0]?.textContent.trim().slice(0, 90));
    t('flex: Beispielwerte gekennzeichnet', view.querySelectorAll('.flex-tag--assumed').length >= 1);
    const band = [...view.querySelectorAll('#flex-ampel .flex-band > li')];
    t('flex: Tagesband mit 24 Stunden, jede mit Stufe und Text für Screenreader', band.length === 24
      && band.every(li => /flex-lv--(cheap|mid|expensive)/.test(li.className) && (li.querySelector('.sr-only')?.textContent || '').length > 5));
    t('flex: Stufe auch als Symbol, nie nur Farbe', band.every(li => /[✔▲✖]/.test(li.querySelector('.flex-band__cell')?.textContent || '')));
    t('flex: Sonne im Band', band.some(li => li.querySelector('.flex-sun--strong')?.textContent === '☀'));
    const rows = view.querySelectorAll('#flex-ampel .flex-week tbody tr');
    t('flex: Wochenraster 7 × 24 ab Erfahren', rows.length === 7 && [...rows].every(r => r.querySelectorAll('td').length === 24)
      && view.querySelector('.flex-week-wrap')?.getAttribute('data-min-level') === 'advanced');
    const before = view.querySelector('#flex-ampel .flex-ampel__summary')?.textContent;
    view.querySelector(`#flex-ampel [data-season="${x.season_now === 'summer' ? 'winter' : 'summer'}"]`)?.click();
    const after = view.querySelector('#flex-ampel .flex-ampel__summary')?.textContent;
    t('flex: Jahreszeit umschalten zeigt die Zahlen der anderen Jahreszeit', view.querySelector('#flex-ampel [aria-pressed="true"]')?.dataset.season !== x.season_now && before !== after, `${before} → ${after}`);
    const lev = [...view.querySelectorAll('.flex-levers tbody tr')];
    t('flex: „Dein Hebel“ — jede Zeile vom Server, größter zuerst', lev.length === x.levers.length && lev[0]?.dataset.lever === x.levers[0].kind
      && view.querySelector('.flex-levers')?.getAttribute('data-min-level') === 'advanced', `${lev.length}`);
    t('flex: Sonnenstrom selbst nutzen', /kWh/.test(view.querySelector('.flex-solar')?.textContent || ''));
    t('flex: Nachrechnung je Zähler ab Experte', view.querySelector('.flex-check')?.getAttribute('data-min-level') === 'expert' && view.querySelectorAll('.flex-check .kpi-grid').length >= 3);
    t('flex: keine rohen Schlüssel, kein undefined', !/flex\.[a-z]/.test(view.textContent) && !/undefined|NaN/.test(view.textContent));
    // zusammengesetzte Schlüssel: jede Art × jedes Fenster, das der Server liefert, steht im Katalog
    const de = JSON.parse(readFileSync(resolve(__dir, '..', 'public', 'locales', 'de.json'), 'utf8'));
    const combos = new Set(x.levers.flatMap(l => ['summer', 'winter'].map(s => l.window?.[s]?.what).filter(Boolean).map(w => `${l.kind}.${w}`)));
    t('flex: Satz für jede Art und jedes Fenster im Katalog', [...combos].every(k => typeof de.flex.actions[k.split('.')[0]]?.[k.split('.')[1]] === 'string'), [...combos].join());

    // v3.6.0 (Paket flexplus) — inkl. USt, das Auto mit seinen Steckzeiten aus evcc
    t('flex: Karte „Beträge inkl. USt“', /inkl\. USt/.test(view.querySelector('.ws-card__footer')?.textContent || ''));
    t('flex: Summe und Obergrenze mit Umsatzsteuer', /inkl\. USt/.test(view.querySelector('.flex-levers')?.textContent || '')
      && /19\s?%\s?Umsatzsteuer/.test(view.querySelector('.flex-levers')?.textContent || ''));
    t('flex: Auto mit Steckzeiten aus evcc (Schild und Satz)', x.ev_availability?.basis === 'sessions'
      && !!view.querySelector('.flex-levers [data-availability="sessions"]')
      && new RegExp(`Steckzeiten aus ${x.ev_availability.sessions} Ladevorgängen`).test(view.querySelector('[data-role="ev-basis"]')?.textContent || ''));
    t('flex: große Zahl = summary vom Server', big === fmt.eur0(x.summary.eur) && x.summary.real === true);
    // Nachtrag — reiner Börsenanteil sagt „netto, ohne USt“: Ampel, Zeitfenster, Nachrechnung je Zähler, Speicher
    t('flex: Ampel-Spanne „netto, ohne USt“', /netto, ohne USt/.test(view.querySelector('#flex-ampel [data-role="ampel-range"]')?.textContent || '')
      && /netto, ohne USt/.test(view.querySelector('#flex-ampel')?.textContent || ''));
    t('flex: Zeitfenster nach Börsenpreis mit Schild „netto, ohne USt“', !!view.querySelector('#flex-windows .card__title [data-net]')
      && /\(netto\)/.test(view.querySelector('#flex-windows thead')?.textContent || ''));
    const netCards = [...view.querySelectorAll('.flex-check .card')].filter(c => c.querySelector('.card__title [data-net]'));
    const netWanted = api.meters.filter(m => m.total).length + (api.battery ? 1 : 0);
    t('flex: Nachrechnung je Zähler und Speicher mit Schild „netto, ohne USt“', netWanted >= 4 && netCards.length === netWanted
      && /ohne USt/.test(view.querySelector('.flex-check .flex-check__title + p')?.textContent || ''), `${netCards.length}/${netWanted} Karten`);
    t('flex: Modul 3 ohne Netto-Schild (Beträge am Vertrag)', [...view.querySelectorAll('.flex-check .card')]
      .filter(c => /Modul 3/.test(c.querySelector('.card__title')?.textContent || '')).every(c => !c.querySelector('[data-net]')));

    // v3.6.0 (Paket flexplus) — Kachel „Dein Hebel“ auf der Übersicht: dieselbe Zahl, nachgeladen
    const dash = await renderView(`${ROOT}/views/dashboard.js`);
    let tile = null;
    for (let i = 0; i < 40 && !tile; i++) { await new Promise(r => setTimeout(r, 100)); tile = dash.view.querySelector('[data-role="flex-lever"]'); }
    const sum = (await (await fetch(`${HOST}/api/flexibility?year=${new Date().getFullYear() - 1}&summary=1`)).json()).data;
    t('dashboard: Kachel „Dein Hebel“ mit Viertelstunden', !!tile && !!sum.summary?.measured, `${sum.year}: ${sum.summary?.eur}`);
    t('dashboard: Kachel zeigt die Zahl des Servers, inkl. USt', (tile?.querySelector('.dash-lever__big')?.textContent || '') === fmt.eur0(sum.summary.eur)
      && /inkl\. USt/.test(tile?.textContent || ''));
    t('dashboard: Kachel im Raster der übrigen, mit Link und Satz', !!tile?.closest('.grid') && !!tile.querySelector('a[href="#/flexibility"]')
      && !!tile.querySelector('.dash-lever__action'));
    if (sum.year === 2025) t('dashboard: dieselbe Zahl wie die Ergebniskarte der Seite', (tile?.querySelector('.dash-lever__big')?.textContent || '') === big);

    const rp = await renderView(`${ROOT}/views/report-print.js`, [], { query: new URLSearchParams('year=2025&flex=1') });
    await new Promise(r => setTimeout(r, 300));
    const page = rp.view.querySelector('.report-print__flex-page');
    t('flex: Jahresbericht mit Seite „Flexibilität“ (flex=1)', !!page && page.querySelectorAll('.flex-band').length === 2 && !!page.querySelector('.ws-card'));
    t('flex: Jahresbericht-Seite ohne Rohschlüssel', !/(flex|report)\.[a-z]/.test(page?.textContent || ''));
    t('flex: Jahresbericht-Seite sagt inkl. USt und nennt die Steckzeiten', /inkl\. USt/.test(page?.textContent || '')
      && /Steckzeiten aus/.test(page?.textContent || ''));
    t('flex: Jahresbericht-Seite kennzeichnet die ct-Werte „netto, ohne USt“', /Börsenanteil netto, ohne USt/.test(page?.textContent || '')
      && /\(netto\)/.test(page?.querySelector('table thead')?.textContent || ''));
    t('flex: Jahresbericht-Thema Flexibilität: Beträge netto ohne USt', /netto ohne USt/.test(rp.view.querySelector('.report-print__flex')?.textContent || ''));
    const rp0 = await renderView(`${ROOT}/views/report-print.js`, [], { query: new URLSearchParams('year=2025') });
    await new Promise(r => setTimeout(r, 300));
    t('flex: ohne flex=1 keine Seite', !rp0.view.querySelector('.report-print__flex-page'));
  } catch (e) { t('flex: Beispielhaushalt', false, e.message); }

  // ── 20. v3.6.0 (Paket einpers) — Standardwerte gelten als „fehlt“, Beispielhaushalt mit Vergleichswerten ──
  // Nach 19 (der Beispielhaushalt dort hat Fläche und Personen eingetragen)
  try {
    const patch = (body) => fetch(`${HOST}/api/settings`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    await patch({ wohnflaeche_m2: null });
    // Nachtrag: Effizienzklasse rechnet weiter mit 100 m², sagt es aber mit Link zum Feld
    {
      const dash0 = await renderView(`${ROOT}/views/dashboard.js`);
      await new Promise(r => setTimeout(r, 600));
      const note = dash0.view.querySelector('.dash-insight [data-role="area-assumed"]');
      t('einpers: Effizienz mit angenommener Fläche — Hinweis mit Link zum Feld', /Angenommene Wohnfläche 100 m²/.test(note?.textContent || '')
        && note.querySelector('a')?.getAttribute('href') === '#/settings/household?field=wohnflaeche_m2', note?.textContent.replace(/\s+/g, ' ').trim() || 'kein Hinweis');
    }
    const { view } = await renderView(`${ROOT}/views/settings.js`, ['household']);
    const area = view.querySelector('[data-key="wohnflaeche_m2"]');
    t('einpers: Wohnfläche ohne Eintrag leer, Default als Platzhalter, Satz dazu', area?.value === '' && area.getAttribute('placeholder') === '100'
      && /Noch nicht eingetragen.*100 m²/.test(view.querySelector('#set-wohnflaeche_m2-assumed')?.textContent || '')
      && (area.getAttribute('aria-describedby') || '').includes('set-wohnflaeche_m2-assumed'), `${area?.value}|${area?.getAttribute('placeholder')}`);
    t('einpers: eingetragene Personen stehen im Feld, ohne Satz', view.querySelector('[data-key="wasser_personen_anzahl"]')?.value === '4' && !view.querySelector('#set-wasser_personen_anzahl-assumed'));
    area.value = '100';
    area.dispatchEvent(new global.window.Event('input', { bubbles: true }));
    view.querySelector('#btn-save')?.click();
    await new Promise(r => setTimeout(r, 300));
    const after = (await (await fetch(`${HOST}/api/settings`)).json()).data;
    t('einpers: 100 m² bewusst eingetragen zählt, der Satz verschwindet', !after.assumed_keys.includes('wohnflaeche_m2') && after.wohnflaeche_m2 === 100
      && !view.querySelector('#set-wohnflaeche_m2-assumed'), JSON.stringify(after.assumed_keys));

    const imp = await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ force: true, persona: 'eigenheim-klassisch' }) });
    t('einpers: Beispielhaushalt „Eigenheim klassisch“ geladen', imp.ok, `HTTP ${imp.status}`);
    const dash = await renderView(`${ROOT}/views/dashboard.js`);
    await new Promise(r => setTimeout(r, 600));
    const card = dash.view.querySelector('[data-role="comparison"]');
    const ratings = [...(card?.querySelectorAll('.cmp-row') || [])].map(r => r.dataset.rating);
    t('einpers: Eigenheim klassisch zeigt die volle Ampel (Strom, Heizung, Wasser), ohne Einladung',
      ratings.length === 3 && ratings.every(r => r !== 'none') && !card.querySelector('[data-role="cmp-invite"]'), ratings.join(','));

    // Nachtrag: Druckansicht des Jahresberichts nennt die angenommene Fläche auf der Effizienzseite
    await patch({ wohnflaeche_m2: null });
    const rpA = await renderView(`${ROOT}/views/report-print.js`, [], { query: new URLSearchParams('year=2025') });
    await new Promise(r => setTimeout(r, 400));
    const rpNote = rpA.view.querySelector('.report-print__utility[data-utility="gas"] [data-role="area-assumed"]');
    t('einpers: Druckansicht — Effizienzseite nennt die angenommene Fläche', /angenommenen Wohnfläche von 100 m²/.test(rpNote?.textContent || ''), rpNote?.textContent || 'kein Satz');

    // Nachtrag: CO₂-Aufteilung der Mieterin ohne Fläche (Mietverhältnis leer, nichts eingetragen)
    const impM = await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ force: true, persona: 'mieterin' }) });
    const tens = (await (await fetch(`${HOST}/api/tenancies`)).json()).data || [];
    for (const x of tens) await fetch(`${HOST}/api/tenancies/${encodeURIComponent(x.id)}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ wohnflaeche_m2: null }) });
    await patch({ wohnflaeche_m2: null });
    const tv = await renderView(`${ROOT}/views/tenancy.js`, [], { query: new URLSearchParams() });
    await new Promise(r => setTimeout(r, 600));
    const tnote = tv.view.querySelector('[data-role="area-assumed"]');
    t('einpers: CO₂-Aufteilung mit angenommener Fläche — Hinweis mit Link ins Mietverhältnis', impM.ok && tens.length > 0
      && tnote?.querySelector('a')?.getAttribute('href') === '#/tenancy?field=wohnflaeche_m2', tnote?.textContent.replace(/\s+/g, ' ').trim() || 'kein Hinweis');
    const tj = await renderView(`${ROOT}/views/tenancy.js`, [], { query: new URLSearchParams('field=wohnflaeche_m2') });
    for (let i = 0; i < 30 && global.document.activeElement?.id !== 'tf-area'; i++) await new Promise(r => setTimeout(r, 50));
    t('einpers: Link öffnet das Mietverhältnis mit dem Flächenfeld im Fokus', global.document.activeElement?.id === 'tf-area', global.document.activeElement?.id || '–');
    global.document.querySelector('.modal [data-act="cancel"]')?.click();
    void tj;
  } catch (e) { t('einpers: Einordnung', false, e.message); }

  console.log(`\n  ERGEBNIS: ${pass} bestanden, ${fail} fehlgeschlagen`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('  HARNESS-FEHLER:', e.stack || e.message); process.exit(2); });
