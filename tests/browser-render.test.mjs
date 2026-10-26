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
    // v3.7.0 — Karte „Auslesen“: Umschalter Lokal | Cloud (die Wahl ist `inbox_provider`),
    // Lokal mit den gemeinsamen Feldern der Texterkennung, Cloud mit Voreinstellungen und
    // „experimentell“; eigene Speichern-Taste (nicht die Speicherleiste)
    const card = view.querySelector('#inbox-reading-card');
    t('settings/experte: Karte „Auslesen“', !!card);
    const pane = (role) => card?.querySelector(`[data-role="${role}"]`);
    const modeOf = () => card?.querySelector('input[name="rd-mode"]:checked')?.value;
    t('settings/experte: ohne Cloud steht der Umschalter auf „Lokal“',
      modeOf() === 'local' && pane('rd-local')?.hidden === false && pane('rd-cloud')?.hidden === true, modeOf());
    t('settings/experte: Lokal mit Adresse, Schnittstelle, Textmodell, „Verbindung prüfen“ und dem Satz zur Texterkennung',
      !!card?.querySelector('#rd-endpoint[data-field="ocr_endpoint"]') && !!card?.querySelector('#rd-api[data-field="ocr_api"]')
      && !!card?.querySelector('#rd-model[data-field="inbox_model"]') && !!pane('rd-check')
      && /Zählerfotos/.test(pane('rd-shared')?.textContent || ''));
    t('settings/experte: Adresse und Schnittstelle stehen nur einmal, die Texterkennung behält Bildmodell und Zeitlimit',
      view.querySelectorAll('[data-key="ocr_endpoint"], [data-field="ocr_endpoint"]').length === 1
      && view.querySelectorAll('[data-key="ocr_api"], [data-field="ocr_api"]').length === 1
      && !!view.querySelector('[data-key="ocr_model"]') && !!view.querySelector('[data-key="ocr_timeout_s"]'));
    t('settings/experte: keine zweite Stelle für die Wahl, wer ausliest', !view.querySelector('[data-key="inbox_provider"]'));
    t('settings/experte: Cloud aus — kein Dokument verlässt das Heimnetz',
      !!card && !card.querySelector('#ic-enabled')?.checked && /Heimnetz|home network/.test(pane('ic-state')?.textContent || ''));
    const cloudRadio = card?.querySelector('input[name="rd-mode"][value="cloud"]');
    if (cloudRadio) { cloudRadio.checked = true; cloudRadio.dispatchEvent(new global.window.Event('change', { bubbles: true })); }
    t('settings/experte: „Cloud“ zeigt die Cloud-Felder mit dem Vermerk „experimentell“',
      pane('rd-cloud')?.hidden === false && pane('rd-local')?.hidden === true
      && /Experimentell: noch nicht mit echten Rückmeldungen geprüft/.test(pane('ic-experimental')?.textContent || ''));
    const keyInput = card?.querySelector('#ic-key');
    t('settings/experte: Schlüsselfeld verdeckt und leer', keyInput?.type === 'password' && keyInput.value === '' && keyInput.getAttribute('autocomplete') === 'new-password');
    const providerSel = card?.querySelector('#ic-provider');
    const presetIds = [...(providerSel?.options || [])].map(o => o.value).join();
    t('settings/experte: Voreinstellungen Claude, OpenAI, Mistral, OpenRouter, Gemini, eigene Adresse',
      presetIds === 'anthropic,openai,mistral,openrouter,gemini,custom', presetIds);
    t('settings/experte: Claude schickt das PDF, Adresse nur bei eigener Adresse',
      pane('ic-endpoint-field')?.hidden === true && /PDF/.test(pane('ic-sends')?.textContent || ''));
    const pick = (v) => { if (providerSel) { providerSel.value = v; providerSel.dispatchEvent(new global.window.Event('change', { bubbles: true })); } };
    pick('mistral');
    t('settings/experte: Mistral füllt die Adresse vor (kein Adressfeld), schickt nur den Text',
      pane('ic-endpoint-field')?.hidden === true && /Text/.test(pane('ic-sends')?.textContent || ''));
    t('settings/experte: Modellliste kommt nach dem Speichern des Schlüssels, bis dahin frei einzutragen',
      pane('ic-model-list')?.hidden === true && card?.querySelector('#ic-model')?.hidden === false
      && /Nach dem Speichern des Schlüssels/.test(pane('ic-model-hint')?.textContent || ''), pane('ic-model-hint')?.textContent);
    pick('custom');
    t('settings/experte: eigene Adresse zeigt das Adressfeld', pane('ic-endpoint-field')?.hidden === false);
    t('settings/experte: Felder der Karte ohne data-key (nicht in der Speicherleiste)', !card?.querySelector('[data-key]'));
    // Einschalten ist das Opt-in: erst eine Rückfrage, die nennt, wohin was geht — und „experimentell“
    pick('anthropic');
    const enabledBox = card?.querySelector('#ic-enabled');
    if (enabledBox) enabledBox.checked = true;
    card?.querySelector('form[data-role="ic-form"]')?.dispatchEvent(new global.window.Event('submit', { bubbles: true, cancelable: true }));
    await new Promise(r => setTimeout(r, 30));
    const ask = global.document.querySelector('.modal');
    t('settings/experte: Einschalten fragt nach, nennt das Ziel und „experimentell“',
      !!ask && /api\.anthropic\.com/.test(ask.textContent) && /Experimentell/.test(ask.querySelector('[data-role="confirm-detail"]')?.textContent || ''),
      ask?.textContent?.slice(0, 120));
    global.document.querySelector('.modal [data-act="cancel"]')?.click();
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
    // v3.7.0 (Paket betrieb) — Schlüssel „nur Posteingang“ für Kurzbefehl und E-Mail
    const scopes = [...access.querySelectorAll('#key-scope option')].map(o => `${o.value}:${o.textContent.trim()}`);
    t('Zugriff: Bereich „nur Posteingang (PDF ablegen)“ wählbar', scopes.includes('inbox:nur Posteingang (PDF ablegen)'), scopes.join(' | '));
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
    // v3.4.0 — unbefristet/weiterlaufend: kein Stichtag „heute“; v3.9.0 (L13) — ohne eingetragene
    // Frist (Beispieldaten Fernwärme) nicht „Jederzeit kündbar“, sondern „Kündigungsfrist nicht eingetragen“
    t('contractsOverview: unbefristeter Vertrag ohne Frist „Kündigungsfrist nicht eingetragen“',
      !!view.querySelector('.contract-overview[data-utility="fernwaerme"]')?.textContent.includes('Kündigungsfrist nicht eingetragen')
      && !view.querySelector('.contract-overview[data-utility="fernwaerme"]')?.textContent.includes('Jederzeit kündbar'));
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
    // v3.7.0 — dazu der Posteingang hinter den Zählerständen (Erfassen)
    t('nav: acht Bereiche mit dem Posteingang', top.join(',') === 'dashboard,readings-entry,inbox,consumption,costs,analysis,hints,settings', top.join(','));
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
    // (v3.9.0, L9: das Vorjahr auch, für seine verteilten Monate; daher nach dem Namen)
    const ds = (mc?.config?.data?.datasets || []).find(d => typeof d.backgroundColor === 'function' && !/zum Vergleich|Vorperiode/.test(d.label || ''));
    const months = mc?.config?.data?.labels || [];
    const rows = [...view.querySelectorAll('.table tbody tr')];
    const partialRow = rows.find(r => /^\d+ \/ \d+$/.test(r.children[1]?.textContent.trim() || ''));
    t('utility(gas): Monatschart eingetragen, Farbe als Funktion (folgt dem Theme)', !!mc && typeof ds?.borderColor === 'function' && typeof ds?.backgroundColor === 'function');
    const pIdx = months.length - 1;
    const fullBg = ds?.backgroundColor?.({ dataIndex: 0 }) || '', partBg = ds?.backgroundColor?.({ dataIndex: pIdx }) || '';
    // v3.7.0 (#37) — ist der Teilmonat zugleich verteilt (Schaufenster: Lücke Ende Januar bis Mitte März), ist er schraffiert
    t('utility(gas): Teilmonat blass (verteilt: schraffiert), volle Monate kräftig', /0\.(12|2)\)$/.test(partBg) && /0\.35\)$/.test(fullBg), `${fullBg} | ${partBg}`);
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
    t('utility(gas): Vorjahr im Monatschart, links (order)', /zum Vergleich/.test(mds[0]?.label || '') && mds[0].order < (mds.find(d => typeof d.backgroundColor === 'function' && !/zum Vergleich|Vorperiode/.test(d.label || ''))?.order ?? 0),
      mds.map(d => `${d.label}:${d.order}`).join(' | '));
    t('utility(gas): Monatstabelle mit Spalte „bereinigt“', [...view.querySelectorAll('.table thead th')].some(th => /bereinigt/.test(th.textContent)));
    // v3.9.0 (L6) — ein Saldo für „jetzt“: Die Gesamtzeile der Spalte „Saldo (kum.)“ ist ihr letzter Wert
    // (bis v3.8 der Jahressaldo neben einer Summe ab Vertragsbeginn), und die Kachel nennt denselben Betrag
    const mt = [...view.querySelectorAll('.table')].find(tb => tb.querySelector('tfoot') && [...tb.querySelectorAll('thead th')].some(th => /kum/.test(th.textContent)));
    const cumIdx = mt ? [...mt.querySelectorAll('thead th')].findIndex(th => /kum/.test(th.textContent)) : -1;
    const cumCells = mt ? [...mt.querySelectorAll('tbody tr')].map(r => r.children[cumIdx]?.textContent.trim()).filter(v => v && v !== '–') : [];
    const cumTotal = mt?.querySelector('tfoot tr')?.children[cumIdx]?.textContent.trim();
    const num = (s) => Number(String(s || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.'));
    const kpiBal = [...view.querySelectorAll('.kpi__sub')].map(k => k.textContent).find(s => /Saldo/.test(s)) || '';
    t('utility(gas): Saldo (kum.) — Gesamtzeile = letzte Zeile = Kachel', !!mt && cumCells.length > 0 && cumTotal === cumCells[cumCells.length - 1]
      && Math.abs(Math.abs(num(cumTotal)) - Math.abs(num(kpiBal.replace(/Saldo:?/, '')))) < 0.011,
      `${cumCells.slice(-1)[0]} | ${cumTotal} | ${kpiBal.replace(/\s+/g, ' ').trim()}`);
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
    // v3.9.0 (L16) — der Satz zuerst, das Wort danach (bis v3.8 „Darüber Wasser: …“, ein Satzanfang ohne Bezug)
    t('einordnung: Wasser gegen den Richtwert als Ampel mit Satz und Wort', wrow?.dataset.rating === 'good' && /^Wasser: 100 Liter je Person und Tag, 18\s%\sunter dem Richtwert \(122 Liter\)\. Gut/.test(wrow.textContent.replace(/\s+/g, ' ').trim()),
      wrow?.textContent.replace(/\s+/g, ' ').trim());
    // mit Vergleichswerten: Ampel je Zeile, keine Einladung
    const withOwn = card({ ...base,
      strom: { kwh: 3650, complete: true, own_value: 3400, delta_pct: 7.4, rating: 'near', special_household: true },
      heating: [{ utility: 'gas', kwh_per_m2: 150, weather_adjusted: true, complete: true, own_value: 120, delta_pct: 25, rating: 'high' }],
      water: { m3: 120, complete: true, persons: 4, reference_l: 122, liters_per_person_day: 82, index: 67, delta_pct: -33, rating: 'good' }, source: 'Beispielquelle' });
    const ratings = [...withOwn.querySelectorAll('.cmp-row')].map(r => r.dataset.rating);
    t('einordnung mit Vergleichswerten: Ampel je Zeile (gelb, rot, grün), Wort neben der Farbe', ratings.join(',') === 'near,high,good'
      && [...withOwn.querySelectorAll('.cmp-row__label')].map(l => l.textContent).join('|') === 'Etwas hoch|Hoch|Gut', ratings.join(','));
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
    // v3.9.0 (A3, L1, L2) — Einsteiger „Muss ich nachzahlen?“: zwei Stromzähler mit Vertrag tragen ihren
    // Namen (bis v3.8 zweimal „Strom“), „rund“ ohne Cent, der letzte Tag des Zeitraums
    freshDom();
    const fmtMod = await import(`${ROOT}/lib/format.js`);
    const { beginnerMoneyLines } = await import(`${ROOT}/views/dashboard.js?b=${Date.now()}`);
    const byKey = new Map([['strom', { key: 'strom', label: 'Strom', icon: '⚡' }], ['gas', { key: 'gas', label: 'Gas', icon: '🔥' }]]);
    const c = (bal, last, end) => ({ projected_end_balance: bal, period_last_day: last, period_end: end });
    const box = global.document.createElement('ul');
    box.innerHTML = beginnerMoneyLines([
      { utility: 'strom', name: 'Haushalt', contract: c(-81.4, '2026-12-31', '2027-01-01') },
      { utility: 'strom', name: 'Wärmepumpe', contract: c(120.6, '2026-12-31', '2027-01-01') },
      { utility: 'gas', name: 'Gaszähler', contract: c(-369.91, '2026-12-31', '2027-01-01') },
    ], byKey).join('');
    const rows = [...box.querySelectorAll('li')].map(li => li.textContent.replace(/\s+/g, ' ').trim());
    t('A3: zwei Stromzähler mit Namen, Gas ohne', rows.length === 3 && rows[0].includes('Strom (Haushalt):') && rows[1].includes('Strom (Wärmepumpe):')
      && rows[2].includes('Gas:') && !rows[2].includes('Gas ('), rows.join(' | '));
    t('L2/L1: „rund“ ohne Cent, bis zum letzten Tag', rows[2].includes(fmtMod.fmt.eur0(369.91).replace(/\s+/g, ' ')) && !rows[2].includes('369,91')
      && rows.every(r => r.includes(fmtMod.fmt.date('2026-12-31')) && !r.includes(fmtMod.fmt.date('2027-01-01'))), rows[2]);
  } catch (e) { t('Einsteiger: Muss ich nachzahlen?', false, e.stack || e.message); }

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

  // ── 21. v3.7.0 (Paket zustaendig) — Zuständig an Zählern und Verträgen, Fristen erben ──
  // Lädt selbst Eigenheim klassisch (Gaszähler: Jonas, Gasvertrag: Sabine, Eichfristen am Zähler)
  try {
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    const click = (el) => el?.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true }));
    const imp = await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ force: true, persona: 'eigenheim-klassisch' }) });
    const people = (await (await fetch(`${HOST}/api/people`)).json()).data || [];
    const idOf = (name) => people.find(p => p.name === name)?.id;
    t('zustaendig: Beispielhaushalt geladen, Personen da', imp.ok && !!idOf('Jonas') && !!idOf('Sabine'));
    // aktive Verbrauchsarten des neuen Haushalts (der Zwischenspeicher hält noch die vorigen)
    const st = await import(`${ROOT}/state.js`);
    st.invalidateSettings();
    st.invalidateUtilities();
    try { localStorage.removeItem('et-assignee-filter'); } catch { /* egal */ }

    const mv = await renderView(`${ROOT}/views/meters.js`, ['gas']);
    const card = [...mv.view.querySelectorAll('.meter-card')].find(c => c.textContent.includes('Gaszähler'));
    t('zustaendig: Zählerkarte mit Chip der Zuständigen', /Jonas/.test(card?.querySelector('.chip--assignee')?.textContent || ''));
    click(card?.querySelector('[data-edit-meter]'));
    for (let i = 0; i < 40 && !global.document.querySelector('#mf-assignee'); i++) await sleep(25);
    const ms = global.document.querySelector('#mf-assignee');
    t('zustaendig: Zählerformular — Auswahl „Zuständig“ mit den Personen, Jonas gewählt', ms?.value === idOf('Jonas')
      && [...(ms?.options || [])].map(o => o.textContent).includes('Sabine') && ms.options[0].value === '');
    global.document.querySelector('.modal [data-act="cancel"]')?.click();

    const cv = await renderView(`${ROOT}/views/contracts.js`, ['gas']);
    const ccard = cv.view.querySelector('[data-contract-id="c_gas_fix"]');
    t('zustaendig: Vertragskarte mit Chip der Zuständigen', /Sabine/.test(ccard?.querySelector('.chip--assignee')?.textContent || ''));
    click(ccard?.querySelector('[data-edit-contract]'));
    for (let i = 0; i < 40 && !global.document.querySelector('#cf-assignee'); i++) await sleep(25);
    t('zustaendig: Vertragsformular — Sabine gewählt, Satz zu den Fristen', global.document.querySelector('#cf-assignee')?.value === idOf('Sabine')
      && /Kündigungsfrist/.test(global.document.querySelector('#cf-assignee-hint')?.textContent || ''));
    global.document.querySelector('.modal [data-act="cancel"]')?.click();

    const rv = await renderView(`${ROOT}/views/reminders.js`);
    const row = [...rv.view.querySelectorAll('tbody tr')].find(r => r.textContent.includes('Eichung Gaszähler'));
    t('zustaendig: Eichfrist erbt die Person des Zählers (Chip „über den Zähler“)', /Jonas/.test(row?.textContent || '')
      && !!row?.querySelector('.chip--via') && /über den Zähler/.test(row?.textContent || ''));
    click(row?.querySelector('[data-edit]'));
    for (let i = 0; i < 40 && !global.document.querySelector('#f-meter'); i++) await sleep(25);
    const fm = global.document.querySelector('#f-meter');
    const inh = global.document.querySelector('[data-role="assignee-inherit"]');
    t('zustaendig: Termin-Dialog — Zähler gewählt, Satz „Ohne Eintrag zuständig: Jonas“', fm?.value === 'gas|m_gas_haus'
      && /Ohne Eintrag zuständig: Jonas/.test(inh?.textContent || ''), `${fm?.value}|${inh?.textContent}`);
    const fa = global.document.querySelector('#f-assignee');
    fa.value = 'Thomas';
    fa.dispatchEvent(new global.window.Event('input', { bubbles: true }));
    t('zustaendig: mit eigenem Eintrag entfällt der Satz', inh?.hidden === true && inh.textContent === '');
    fa.value = '';
    fa.dispatchEvent(new global.window.Event('input', { bubbles: true }));
    fm.value = '';
    fm.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    click(global.document.querySelector('.modal [data-act="save"]'));
    let saved = null;
    for (let i = 0; i < 40; i++) {
      await sleep(50);
      saved = ((await (await fetch(`${HOST}/api/reminders`)).json()).data || []).find(r => r.title === 'Eichung Gaszähler');
      if (saved && !saved.meter_id) break;
    }
    t('zustaendig: Termin ohne Zähler gespeichert — erbt nichts mehr', !!saved && !saved.meter_id && saved.assignee_inherited === null);
    // neue Eichfrist: der einzige Gaszähler ist vorgewählt, solange niemand wählt
    const rv2 = await renderView(`${ROOT}/views/reminders.js`);
    click(rv2.view.querySelector('#rem-add'));
    const cat = global.document.querySelector('#f-cat');
    cat.value = 'gaszaehler_eichung';
    cat.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    t('zustaendig: neue Eichfrist — einziger passender Zähler vorgewählt, Satz zur Person',
      global.document.querySelector('#f-meter')?.value === 'gas|m_gas_haus'
        && /Jonas/.test(global.document.querySelector('[data-role="assignee-inherit"]')?.textContent || ''));
    global.document.querySelector('.modal [data-act="cancel"]')?.click();

    const ov = await renderView(`${ROOT}/views/contracts-overview.js`);
    for (let i = 0; i < 40 && ov.view.querySelector('.loading'); i++) await sleep(25);
    const gasRow = ov.view.querySelector('.contract-overview[data-utility="gas"] .contract-overview__row');
    t('zustaendig: Verträge & Abschläge — Chip „über den Vertrag“ und Filter', /Sabine/.test(gasRow?.textContent || '')
      && /über den Vertrag/.test(gasRow?.textContent || '') && !!ov.view.querySelector('#co-assignee option[value="name:sabine"]'));
    const fsel = ov.view.querySelector('#co-assignee');
    fsel.value = 'none';
    fsel.dispatchEvent(new global.window.Event('change', { bubbles: true }));
    t('zustaendig: Filter „Ohne Zuständigkeit“ blendet Gas (Sabine) aus und nennt es', !ov.view.querySelector('.contract-overview[data-utility="gas"]')
      && !!ov.view.querySelector('.contract-overview[data-utility="strom"]') && !!ov.view.querySelector('[data-role="assignee-hidden"]'));
    try { localStorage.removeItem('et-assignee-filter'); } catch { /* egal */ }

    const ev = (await (await fetch(`${HOST}/api/agenda?days=365`)).json()).data?.events || [];
    const sources = new Set(ev.map(e => e.assignee_source));
    t('zustaendig: Agenda — Fristen tragen die Herkunft (Vertrag, Zähler)', sources.has('contract') && sources.has('meter'), [...sources].join(','));
  } catch (e) { t('zustaendig: Zuständig an Zählern und Verträgen', false, e.message); }

  // ── 22. v3.7.0 (Paket nachzuege) — Kosten ohne Bonus, Sonstige Gutschrift, Gas-Umrechnung,
  //        Flexibilität mit eigenen Werten, Modul 3 netto ──
  try {
    const patch = (body) => fetch(`${HOST}/api/settings`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const impS = await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ force: true }) });
    t('nachzuege: Beispieldaten (Schaufenster) geladen', impS.ok, `HTTP ${impS.status}`);
    const { fmt } = await import(`${ROOT}/lib/format.js`);
    const apiMod = await import(`${ROOT}/api.js`);
    // (1) Kachel „Kosten“ ohne Bonus, der Bonus als eigene Zeile — im Jahr mit Bonus
    const gasMeters = await apiMod.api.meters('gas');
    const cons = await apiMod.api.meterConsumption('gas', gasMeters[0].id);
    const byYear = {};
    for (const m of cons.monthly || []) { byYear[m.year] ??= { cost: 0, bonus: 0, n: 0 }; byYear[m.year].cost += m.cost || 0; byYear[m.year].bonus += m.bonus_eur || 0; byYear[m.year].n++; }
    const bonusYear = Object.keys(byYear).find(y => byYear[y].bonus > 0);
    t('nachzuege: Beispieldaten mit Bonus bei Gas (Vorbedingung)', !!bonusYear);
    const uv = await renderView(`${ROOT}/views/utility.js`, ['gas'], { query: new URLSearchParams(`year=${bonusYear}`) });
    await new Promise(r => setTimeout(r, 700));
    const y = byYear[bonusYear];
    const costTxt = uv.view.querySelector('[data-role="kpi-cost"]')?.textContent || '';
    t('nachzuege: Kachel „Kosten“ ohne Bonus', costTxt === fmt.eur(y.cost + y.bonus), `${costTxt} / ${fmt.eur(y.cost + y.bonus)}`);
    const bonusTxt = uv.view.querySelector('[data-role="kpi-bonus"]')?.textContent || '';
    t('nachzuege: Bonus als eigene Zeile mit Datum', /^Bonus −/.test(bonusTxt) && /\d{2}\.\d{2}\.\d{4}/.test(bonusTxt), bonusTxt);
    t('nachzuege: Ø je Monat ohne Bonus', (uv.view.querySelector('[data-role="kpi-cost"]')?.nextElementSibling?.textContent || '').includes(fmt.eur((y.cost + y.bonus) / y.n)));
    t('nachzuege: ⓘ erklärt Kachel, Saldo und Ø', /Saldo rechnet ihn ein/.test(uv.view.querySelector('[data-role="kpi-cost"]')?.parentElement?.querySelector('[data-info-text]')?.dataset.infoText || ''));
    // (2) Vertragsformular: Sonstige Gutschrift mit Notiz; die Notiz eines Bonus bleibt erhalten
    const cv = await renderView(`${ROOT}/views/contracts.js`, ['gas']);
    await new Promise(r => setTimeout(r, 400));
    const withBonus = (await apiMod.api.contracts('gas')).find(c => (c.bonuses || []).some(b => b.label));
    cv.view.querySelector(`[data-edit-contract="${withBonus?.id}"]`)?.click();
    await new Promise(r => setTimeout(r, 400));
    const form = global.document.querySelector('#modal-root #contract-form');
    const brow = form?.querySelector('.bonus-row');
    t('nachzuege: Bonus mit Art „Sonstige Gutschrift“', !!brow?.querySelector('[data-role="type"] option[value="sonstige"]')
      && brow.querySelector('[data-role="type"] option[value="sonstige"]').textContent === 'Sonstige Gutschrift');
    t('nachzuege: Notizfeld zeigt die gespeicherte Notiz', brow?.querySelector('[data-role="note"]')?.value === withBonus.bonuses.find(b => b.label).label,
      brow?.querySelector('[data-role="note"]')?.value);
    // (3) Gas: die heute geltende Umrechnung mit ⓘ und Verweis auf die Einstellung
    const gf = form?.querySelector('[data-role="gas-factor-note"]');
    t('nachzuege: Gas-Umrechnung im Vertragsformular', /Umrechnung heute: .*kWh\/m³/.test(gf?.textContent || '')
      && gf.querySelector('a')?.getAttribute('href') === '#/settings/utilities' && /Netz/.test(gf.querySelector('[data-info-text]')?.dataset.infoText || ''),
      gf?.textContent.replace(/\s+/g, ' ').trim().slice(0, 120));
    t('nachzuege: Vertragskarte nennt die Boni mit Art', !!cv.view.querySelector('[data-role="contract-bonuses"]')
      && /Bonus \(/.test(cv.view.querySelector('[data-role="contract-bonuses"]')?.textContent || ''));
    // Speichern behält die Notiz (vorher ging sie beim Speichern verloren) und nimmt die neue Art an
    const keptLabel = withBonus.bonuses.find(b => b.label).label;
    const btype = brow?.querySelector('[data-role="type"]');
    if (btype) { btype.value = 'sonstige'; btype.dispatchEvent(new global.window.Event('change', { bubbles: true })); }
    // JSDOM kennt den Namenszugriff `form.provider` nicht (der Browser schon): nachrüsten
    for (const el of form?.elements || []) {
      if (el.name && !(el.name in form)) Object.defineProperty(form, el.name, { get: () => form.elements.namedItem(el.name), configurable: true });
    }
    global.document.querySelector('#modal-root [data-act="save"]')?.click();
    for (let i = 0; i < 30 && global.document.querySelector('#modal-root #contract-form'); i++) await new Promise(r => setTimeout(r, 50));
    const saved = (await apiMod.api.contracts('gas')).find(c => c.id === withBonus.id);
    const savedB = (saved?.bonuses || []).find(b => b.label === keptLabel);
    t('nachzuege: Speichern behält die Notiz und speichert „Sonstige Gutschrift“', !global.document.querySelector('#modal-root #contract-form')
      && savedB?.type === 'sonstige', JSON.stringify(saved?.bonuses));
    // Tarifvergleich (Rückblick): welche Boni im Betrag stecken, mit Art und Notiz
    const tv = await renderView(`${ROOT}/views/tariff.js`);
    await new Promise(r => setTimeout(r, 300));
    const tUtil = tv.view.querySelector('#t-util');
    if (tUtil) { tUtil.value = 'gas'; tUtil.dispatchEvent(new global.window.Event('change', { bubbles: true })); }
    await new Promise(r => setTimeout(r, 700));
    const tBonus = [...tv.view.querySelectorAll('#t-retro [data-role="tariff-bonus"]')].map(e => e.textContent).join(' | ');
    t('nachzuege: Tarifvergleich nennt den Bonus mit Art, Datum und Notiz',
      /(Bonus \([^)]+\)|Sonstige Gutschrift) −[\d.,]+\s€ am \d{2}\.\d{2}\.\d{4} \([^)]+\)/.test(tBonus), tBonus || 'keine Zeile');
    // (4) Strom: Modul 3 mit Schalter „ohne USt“, keine Gasumrechnung
    const sv = await renderView(`${ROOT}/views/contracts.js`, ['strom']);
    await new Promise(r => setTimeout(r, 400));
    sv.view.querySelector('[data-action="new-contract"]')?.click();
    await new Promise(r => setTimeout(r, 400));
    const sform = global.document.querySelector('#modal-root #contract-form');
    const net = sform?.querySelector('[data-role="grid-tou"] [data-role="tou-net"]');
    t('nachzuege: Modul 3 mit Schalter „ohne USt“ (aus = brutto)', !!net && net.checked === false && net.closest('label')?.matches('.check'));
    t('nachzuege: Strom ohne Gasumrechnung', !sform?.querySelector('[data-role="gas-factor-note"]'));
    global.document.querySelector('.modal [data-act="cancel"]')?.click();
    // gespeichert: der Schalter kommt als `net: true` am Vertrag an
    const stromC = (await apiMod.api.contracts('strom')).find(c => !c.is_shadow && sv.view.querySelector(`[data-edit-contract="${c.id}"]`));
    sv.view.querySelector(`[data-edit-contract="${stromC?.id}"]`)?.click();
    await new Promise(r => setTimeout(r, 400));
    const eform = global.document.querySelector('#modal-root #contract-form');
    const touBox = eform?.querySelector('[data-role="grid-tou"]');
    if (touBox) {
      touBox.querySelector('[data-role="tou-standard"]').value = '8';
      touBox.querySelector('[data-role="tou-net"]').checked = true;
    }
    for (const el of eform?.elements || []) {
      if (el.name && !(el.name in eform)) Object.defineProperty(eform, el.name, { get: () => eform.elements.namedItem(el.name), configurable: true });
    }
    global.document.querySelector('#modal-root [data-act="save"]')?.click();
    for (let i = 0; i < 30 && global.document.querySelector('#modal-root #contract-form'); i++) await new Promise(r => setTimeout(r, 50));
    const touSaved = (await apiMod.api.contracts('strom')).find(c => c.id === stromC?.id)?.grid_tou;
    t('nachzuege: Modul 3 „ohne USt“ wird gespeichert', touSaved?.net === true && Number(touSaved?.standard_ct) === 8, JSON.stringify(touSaved));
    if (global.document.querySelector('#modal-root #contract-form')) global.document.querySelector('.modal [data-act="cancel"]')?.click();
    // (5) Einstellungen: Flexibilität mit Beispielwerten als Platzhalter
    await patch({ flex_washer_kwh_per_cycle: null, flex_dishwasher_cycles_per_week: null });
    const st = await renderView(`${ROOT}/views/settings.js`, ['utilities']);
    await new Promise(r => setTimeout(r, 300));
    const dish = st.view.querySelector('[data-key="flex_dishwasher_cycles_per_week"]');
    t('nachzuege: Flexibilität — leeres Feld, Beispielwert als Platzhalter und Satz', dish?.value === '' && dish.getAttribute('placeholder') === '5'
      && /Beispielwert 5/.test(st.view.querySelector('#set-flex_dishwasher_cycles_per_week-assumed')?.textContent || ''), `${dish?.value}|${dish?.getAttribute('placeholder')}`);
    t('nachzuege: Steckzeit des Autos ohne Platzhalter-Satz (leer = ganzer Ladetag)', st.view.querySelector('[data-key="flex_ev_plugged_from"]')?.value === ''
      && !st.view.querySelector('#set-flex_ev_plugged_from-assumed'));
    t('nachzuege: Stichtag eindeutig „erster Tag der Abrechnungsperiode“', /erster Tag der Abrechnungsperiode/.test(st.view.querySelector('label[for="set-billing_cycle_anchor_gas"]')?.textContent || '')
      && /01-09 heißt 1\.9\. bis 31\.8\./.test(st.view.textContent));
    // (6) Flexibilität: eigener Wert statt Beispielwert
    const impM = await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ force: true, persona: 'eigenheim-modern' }) });
    await patch({ flex_dishwasher_cycles_per_week: 7 });
    const fv = await renderView(`${ROOT}/views/flexibility.js`, [], { query: new URLSearchParams('year=2025') });
    await new Promise(r => setTimeout(r, 500));
    const dishRow = fv.view.querySelector('.flex-levers tr[data-lever="dishwasher"]');
    t('nachzuege: Flexibilität nennt den eigenen Wert', impM.ok && !!dishRow?.querySelector('.flex-tag--own') && !dishRow.querySelector('.flex-tag--assumed'));
    t('nachzuege: Annahmen je Teil Beispielwert oder eigener Wert', /Spülmaschine 1 kWh je Gang, 7 Gänge je Woche \(eigener Wert\)/.test(fv.view.querySelector('[data-role="assumptions"]')?.textContent || '')
      && /\(Beispielwert\)/.test(fv.view.querySelector('[data-role="assumptions"]')?.textContent || ''), fv.view.querySelector('[data-role="assumptions"]')?.textContent);
    await patch({ flex_dishwasher_cycles_per_week: null });
  } catch (e) { t('nachzuege: v3.7.0', false, e.message); }

  // ── 23. v3.7.0 (Integration nachzuege) — Belege am Vertrag: Dateiname, Link zum Öffnen,
  //        ohne Namen „Beleg 2“; ein Vertrag ohne Belege zeigt keine Zeile ──
  try {
    const jpost = (path, method, body, type = 'application/json') => fetch(`${HOST}${path}`, { method, headers: { 'Content-Type': type }, body });
    const impB = await jpost('/api/demo/import', 'POST', JSON.stringify({ force: true }));
    t('belege: Schaufenster geladen', impB.ok, `HTTP ${impB.status}`);
    const pdf = (tag) => `%PDF-1.4\n% render attachments ${tag}\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n`;
    const a1 = (await (await jpost('/api/attachments?kind=other&name=Stromvertrag-Beispiel.pdf', 'POST', pdf('a'), 'application/pdf')).json()).data?.id;
    const a2 = (await (await jpost('/api/attachments?kind=other', 'POST', pdf('b'), 'application/pdf')).json()).data?.id;
    // der Dateiname kommt vom Hochladen — er darf nicht als HTML in die Seite
    const a3 = (await (await jpost(`/api/attachments?kind=other&name=${encodeURIComponent('<i>kursiv<i>.pdf')}`, 'POST', pdf('c'), 'application/pdf')).json()).data?.id;
    const scs = ((await (await fetch(`${HOST}/api/utility/strom/contracts`)).json()).data || []).filter(c => !c.is_shadow);
    const target = scs[0];
    const upd = await jpost(`/api/utility/strom/contracts/${encodeURIComponent(target?.id)}`, 'PATCH', JSON.stringify({ attachment_ids: [a1, a2, a3] }));
    t('belege: drei Belege am Stromvertrag gespeichert (Vorbedingung)', !!a1 && !!a2 && !!a3 && upd.ok && scs.length >= 2, `HTTP ${upd.status}, ${scs.length} Verträge`);
    const st = await import(`${ROOT}/state.js`);
    st.invalidateSettings(); st.invalidateUtilities();
    const cv = await renderView(`${ROOT}/views/contracts.js`, ['strom']);
    for (let i = 0; i < 40 && cv.view.querySelector('.loading'); i++) await new Promise(r => setTimeout(r, 25));
    const docs = cv.view.querySelector(`[data-contract-id="${target?.id}"] [data-role="contract-documents"]`);
    const links = [...(docs?.querySelectorAll('a[data-attachment]') || [])];
    t('belege: Vertragskarte nennt die Belege mit Dateinamen', /^Belege:/.test(docs?.textContent.trim() || '') && links.length === 3
      && /Stromvertrag-Beispiel\.pdf/.test(links[0]?.textContent || '') && /Beleg 2/.test(links[1]?.textContent || ''), docs?.textContent.replace(/\s+/g, ' ').trim() || 'keine Zeile');
    t('belege: Dateiname als Text, nicht als HTML', !!links[2] && !links[2].querySelector('i') && links[2].textContent.includes('<i>kursiv<i>.pdf'), links[2]?.innerHTML);
    t('belege: Link öffnet den Beleg in einem neuen Tab', links.every(a => /api\.php\/api\/attachments\/att_[0-9a-f]{16}$/.test(a.getAttribute('href') || '')
      && a.getAttribute('target') === '_blank' && a.getAttribute('rel') === 'noopener') && links[0]?.getAttribute('title') === 'Stromvertrag-Beispiel.pdf öffnen',
      links.map(a => a.getAttribute('href')).join(' '));
    const other = cv.view.querySelector(`[data-contract-id="${scs.find(c => !(c.attachment_ids || []).length && c.id !== target?.id)?.id}"]`);
    t('belege: Vertrag ohne Belege ohne Zeile', !!other && !other.querySelector('[data-role="contract-documents"]'));
    // Zusammenführen (contracts.js): Bonuszeile des Wasservertrags mit Art (#35) und beschrifteten Feldern (Qualität)
    const wv = await renderView(`${ROOT}/views/contracts.js`, ['wasser']);
    for (let i = 0; i < 40 && wv.view.querySelector('.loading'); i++) await new Promise(r => setTimeout(r, 25));
    wv.view.querySelector('[data-action="new-contract"]')?.click();
    for (let i = 0; i < 40 && !global.document.querySelector('.modal [data-group="bonuses"]'); i++) await new Promise(r => setTimeout(r, 25));
    const wrow = global.document.querySelector('.modal [data-group="bonuses"] tbody tr');
    t('belege/zusammengeführt: Wasserbonus mit Art und beschrifteten Feldern', !!wrow?.querySelector('select[data-field="type"] option[value="sonstige"]')
      && ['credit_date', 'amount_eur', 'label'].every(f => (wrow.querySelector(`[data-field="${f}"]`)?.getAttribute('aria-label') || '') !== ''),
      wrow ? [...wrow.querySelectorAll('[data-field]')].map(e => `${e.dataset.field}:${e.getAttribute('aria-label') || '–'}`).join(' ') : 'kein Dialog');
    global.document.querySelector('.modal [data-act="cancel"]')?.click();
  } catch (e) { t('belege: Belege am Vertrag', false, e.stack || e.message); }

  // ── 24. v3.7.0 — Posteingang mit dem Beispieldokument des Schaufensters ──
  // Am Ende: Das Übernehmen ändert Bestand, auf den frühere Abschnitte bauen.
  try {
    const imp = await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ force: true }) });
    t('inbox: Schaufenster mit Beispieldokument geladen', imp.ok, `HTTP ${imp.status}`);
    // die Verbrauchsarten des vorigen Haushalts nicht weiterverwenden (state.js merkt sie sich)
    const st = await import(`${ROOT}/state.js`);
    st.invalidateSettings(); st.invalidateUtilities();
    const list = await renderView(`${ROOT}/views/inbox.js`, [], { query: new URLSearchParams() });
    const lv = list.view;
    const input = lv.querySelector('#inbox-file');
    t('inbox: Ablegen per Dateiauswahl (nur PDF, mehrere) und Ziehen', input?.type === 'file' && /application\/pdf/.test(input.getAttribute('accept') || '')
      && input.hasAttribute('multiple') && !!lv.querySelector('label#inbox-drop[for="inbox-file"]'));
    t('inbox: Hinweis aufs iPad (Dateien-App)', /Dateien-App/.test(lv.querySelector('#inbox-drop')?.textContent || ''));
    const row = lv.querySelector('#inbox-list tr[data-entry]');
    // v3.7.0 (treffsicher) — gespeichert sind 3 Marken (ohne Bestand); Arbeits- und Grundpreis
    // stehen so im Vertrag des Schaufensters, also bleibt nur der eingebaute Zahlendreher
    t('inbox: Beispieldokument in der Liste, ausgelesen mit 1 Marke (Preise wie im Vertrag)', !!row && /Jahresabrechnung-Erdgas-2025\.pdf/.test(row.textContent)
      && /Ausgelesen · 1 Feld prüfen/.test(row.textContent) && /Rechnung/.test(row.textContent), row?.textContent.replace(/\s+/g, ' ').trim());
    t('inbox: ohne Dienst der Hinweis auf qwen3.8 und 18 GB', /qwen3\.8/.test(lv.querySelector('[data-role="provider"]')?.textContent || '')
      && /18 GB/.test(lv.querySelector('[data-role="provider"]')?.textContent || ''));
    t('inbox: keine rohen Schlüssel', !/inbox\.[a-z]/.test(lv.textContent));
    const id = row?.dataset.entry;
    // v3.7.0 (Paket betrieb) — Kernaussage in der Liste, Hinweis auf Kurzbefehl, Ordner, E-Mail
    const gl = row?.querySelector('[data-role="gist"]');
    t('inbox/betrieb: Kernaussage unter dem Namen (Satz und Zahl)', !!gl && /Abrechnung 01\.01\.2025 bis 31\.12\.2025/.test(gl.textContent)
      && /Guthaben|Nachzahlung/.test(gl.textContent), gl?.textContent.trim());
    const ga = row?.querySelector('[data-gist-action]');
    t('inbox/betrieb: Knopf „Übernehmen und prüfen“ führt in die Vorschau', ga?.getAttribute('href') === `#/inbox/${encodeURIComponent(id)}`
      && /Übernehmen und prüfen/.test(ga.textContent), ga?.outerHTML);
    const ch = lv.querySelector('#inbox-channels');
    t('inbox/betrieb: „Auch ohne Browser“ mit Kurzbefehl, Ordner und E-Mail', !!ch && /Kurzbefehl/.test(ch.textContent) && /posteingang/.test(ch.textContent)
      && ['inbox-shortcut', 'inbox-folder', 'inbox-email'].every(a => [...ch.querySelectorAll('a')].some(x => (x.getAttribute('href') || '').endsWith(`?h=${a}`))),
      [...(ch?.querySelectorAll('a') || [])].map(a => a.getAttribute('href')).join(' '));

    // v3.7.0 (cloud) — Zustimmung: einzeln je Dokument, die Warteschlange einmal für
    // alle Dokumente des Laufs mit ihrer Zahl; Ziel und Hinweis im Dialog; nichts gemerkt
    try {
      const jpost = (path, method, body) => fetch(`${HOST}${path}`, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      const on = await jpost('/api/inbox/cloud', 'PATCH', { enabled: true, provider: 'anthropic', api_key: 'sk-ant-test-render-0000' });
      const added = [];
      for (const tag of ['a', 'b']) {
        const pdf = `%PDF-1.4\n% render consent ${tag}\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n`;
        const r = await fetch(`${HOST}/api/inbox?name=cloud-${tag}.pdf`, { method: 'POST', headers: { 'Content-Type': 'application/pdf' }, body: pdf });
        added.push((await r.json()).data?.id);
      }
      t('inbox/cloud: eingeschaltet mit Schlüssel, zwei neue Dokumente', on.ok && added.every(Boolean));
      const cl = (await renderView(`${ROOT}/views/inbox.js`, [], { query: new URLSearchParams() })).view;
      t('inbox/cloud: Hinweis nennt den Cloud-Dienst', /Claude \(Anthropic\)/.test(cl.querySelector('[data-role="provider"]')?.textContent || ''));
      cl.querySelector('#inbox-read-all')?.click();
      await new Promise(r => setTimeout(r, 50));
      const askAll = global.document.querySelector('.modal');
      t('inbox/cloud: „Alle auslesen“ fragt einmal für alle mit ihrer Zahl, Ziel und Hinweis',
        !!askAll && /Diese 2 Dokumente gehen zum Auslesen an Claude \(Anthropic\) – api\.anthropic\.com/.test(askAll.textContent)
        && /als PDF an Claude \(Anthropic\) unter api\.anthropic\.com/.test(askAll.querySelector('[data-role="confirm-detail"]')?.textContent || '')
        && /Experimentell: noch nicht mit echten Rückmeldungen geprüft/.test(askAll.querySelector('[data-role="confirm-detail"]')?.textContent || ''),
        askAll?.textContent.replace(/\s+/g, ' ').trim().slice(0, 160));
      global.document.querySelector('.modal [data-act="cancel"]')?.click();
      await new Promise(r => setTimeout(r, 50));
      const after = (await (await fetch(`${HOST}/api/inbox`)).json()).data;
      t('inbox/cloud: abgelehnt — nichts vorgemerkt, nichts gesendet', after.queue?.queued === 0
        && added.every(i => after.entries.find(e => e.id === i)?.status === 'new'));
      const one = (await renderView(`${ROOT}/views/inbox.js`, [added[0]], { query: new URLSearchParams() })).view;
      await new Promise(r => setTimeout(r, 100));
      one.querySelector('#inbox-read')?.click();
      await new Promise(r => setTimeout(r, 50));
      const askOne = global.document.querySelector('.modal');
      t('inbox/cloud: einzeln fragt je Dokument', !!askOne && /Dieses Dokument geht zum Auslesen an Claude \(Anthropic\) – api\.anthropic\.com/.test(askOne.textContent),
        askOne?.textContent.replace(/\s+/g, ' ').trim().slice(0, 160));
      global.document.querySelector('.modal [data-act="cancel"]')?.click();
      await new Promise(r => setTimeout(r, 50));
      // eine Wahrheit: der Umschalter der Karte „Auslesen“ zeigt, wer ausliest, und stellt es um
      st.invalidateSettings();
      const ex1 = (await renderView(`${ROOT}/views/settings.js`, ['expert'])).view;
      const mode1 = ex1.querySelector('#inbox-reading-card input[name="rd-mode"]:checked')?.value;
      t('inbox/cloud: die Karte „Auslesen“ steht auf „Cloud“ und sagt, wohin Dokumente gehen',
        mode1 === 'cloud' && /geht an api\.anthropic\.com/.test(ex1.querySelector('[data-role="ic-state"]')?.textContent || ''), mode1);
      // v3.7.0 — in der Cloud-Ansicht steht deutlich, was hinausgeht und was nicht
      t('inbox/cloud: die Cloud-Ansicht sagt, dass jedes Dokument hinausgeht und sonst nichts',
        /verlässt jedes Dokument.*Sonst geht nichts hinaus/s.test(ex1.querySelector('[data-role="ic-privacy"]')?.textContent || ''));
      const localRadio = ex1.querySelector('#inbox-reading-card input[name="rd-mode"][value="local"]');
      if (localRadio) { localRadio.checked = true; localRadio.dispatchEvent(new global.window.Event('change', { bubbles: true })); }
      ex1.querySelector('#inbox-reading-card form[data-role="ic-form"]')?.dispatchEvent(new global.window.Event('submit', { bubbles: true, cancelable: true }));
      await new Promise(r => setTimeout(r, 150));
      const afterLocal = (await (await fetch(`${HOST}/api/settings`)).json()).data;
      t('inbox/cloud: „Lokal“ wählen und speichern stellt inbox_provider um, die Cloud bleibt eingeschaltet',
        afterLocal.inbox_provider === 'local' && afterLocal.inbox_cloud_enabled === true, `${afterLocal.inbox_provider} ${afterLocal.inbox_cloud_enabled}`);
      st.invalidateSettings();
      const ex2 = (await renderView(`${ROOT}/views/settings.js`, ['expert'])).view;
      t('inbox/cloud: „Lokal“ gewählt — die Karte sagt, dass nichts hinausgeht',
        ex2.querySelector('#inbox-reading-card input[name="rd-mode"]:checked')?.value === 'local'
        && /es liest aber „Lokal“/.test(ex2.querySelector('[data-role="ic-state"]')?.textContent || ''),
        ex2.querySelector('[data-role="ic-state"]')?.textContent);
      for (const i of added) await fetch(`${HOST}/api/inbox/${i}`, { method: 'DELETE' });
      await jpost('/api/inbox/cloud', 'PATCH', { enabled: false, clear_key: true });
      st.invalidateSettings();
    } catch (e) { t('inbox/cloud: Zustimmung', false, e.message); }

    const rev = await renderView(`${ROOT}/views/inbox.js`, [], { query: new URLSearchParams('view=review') });
    const items = [...rev.view.querySelectorAll('#inbox-review .inbox-review__item')];
    t('inbox: Prüfliste nur mit den markierten Feldern (Preise wie im Vertrag nicht)', items.length === 1 && /Verbrauch/.test(items.map(i => i.textContent).join(' '))
      && /Wert steht nicht in der Belegstelle/.test(rev.view.textContent), `${items.length} Einträge`);

    const det = await renderView(`${ROOT}/views/inbox.js`, [id], { query: new URLSearchParams() });
    const dv = det.view;
    await new Promise(r => setTimeout(r, 200));
    const cons = [...dv.querySelectorAll('#inbox-fields [data-field]')].find(li => li.dataset.field === 'consumption');
    t('inbox: markiertes Feld hervorgehoben, mit Grund und Belegstelle', cons?.classList.contains('inbox-field--check')
      && /Wert steht nicht in der Belegstelle/.test(cons.textContent) && /Verbrauch: 945,6 m³ = 10\.540 kWh/.test(cons.querySelector('.inbox-quote')?.textContent || ''));
    t('inbox: markierte Felder zuerst', dv.querySelector('#inbox-fields [data-field]')?.classList.contains('inbox-field--check'));
    const wpF = [...dv.querySelectorAll('#inbox-fields [data-field]')].find(li => li.dataset.field === 'working_prices');
    t('inbox: Preis wie im Vertrag ohne Marke, mit Hinweis (v3.7.0 treffsicher)', !!wpF && !wpF.classList.contains('inbox-field--check')
      && /Wie im Vertrag/.test(wpF.querySelector('[data-role="hint"]')?.textContent || ''), wpF?.textContent.replace(/\s+/g, ' ').trim());
    // v3.7.0 (treffsicher, Nachbesserung 2) — Hilfsfelder (netto/brutto, Steuersatz) nicht unter „nicht im Dokument“
    const auxNames = Object.entries((await (await fetch(`${HOST}/api/inbox/${encodeURIComponent(id)}`)).json()).data?.schema || {})
      .filter(([, d]) => d.aux).map(([n]) => n);
    const auxShown = [...dv.querySelectorAll('#inbox-fields-card [data-field]')].map(li => li.dataset.field).filter(n => auxNames.includes(n));
    t('inbox: Hilfsfelder Netto/Steuersatz nur, wenn sie dastehen', auxNames.length >= 2 && auxShown.length === 0, `${auxNames.join(',')} | gezeigt: ${auxShown.join(',')}`);
    // v3.7.0 (Paket betrieb) — Karte „Was heißt das für dich?“ oben: Zahl, Satz, Knopf, Rest aufklappbar
    const gc = dv.querySelector('#inbox-gist');
    t('inbox/betrieb: Kernaussage oben in der Vorschau', !!gc && dv.querySelector('#inbox-side')?.firstElementChild === gc
      && gc.dataset.gist === 'inbox.gist.invoice.pending' && /Übernimm sie, dann prüft die App/.test(gc.textContent)
      && !!gc.querySelector('[data-role="gist-figure"]') && /Übernehmen und prüfen/.test(gc.querySelector('[data-gist-action="apply"]')?.textContent || ''),
      gc?.textContent.replace(/\s+/g, ' ').trim());
    t('inbox/betrieb: der Rest aufklappbar (neuer Abschlag)', /Neuer Abschlag 80,00\s€ ab 01\.02\.2026/.test(gc?.querySelector('details.inbox-gist__more')?.textContent || ''),
      gc?.querySelector('details')?.textContent.replace(/\s+/g, ' ').trim());
    t('inbox: Zuordnung vorbelegt (Gas, Hauptzähler, Vertrag am Stichtag)', dv.querySelector('#inbox-utility')?.value === 'gas'
      && dv.querySelector('#inbox-meter')?.value === 'm_gas_main' && dv.querySelector('#inbox-contract')?.value === 'c_gas_003'
      && /Zählernummer/.test(dv.querySelector('#inbox-assign')?.textContent || ''), `${dv.querySelector('#inbox-meter')?.value} ${dv.querySelector('#inbox-contract')?.value}`);
    const props = [...dv.querySelectorAll('#inbox-proposals .inbox-proposal')];
    const prop = (re) => props.find(p => re.test(p.textContent));
    const readingP = prop(/Ablesung am 31\.12\.2025: 1\.235,6 m³/);
    t('inbox: Vorschlag Ablesung neu und angehakt', !!readingP && readingP.querySelector('input').checked && /neu/.test(readingP.textContent));
    const billP = prop(/Rechnung 01\.01\.2025 bis 31\.12\.2025 erfassen, Guthaben 548,54/);
    t('inbox: Rechnung mit markierter Quelle nicht vorbelegt', !!billP && !billP.querySelector('input').checked && /prüfen/.test(billP.textContent), billP?.textContent.replace(/\s+/g, ' ').trim());
    const factorP = props.filter(p => /Gas-Umrechnung ab/.test(p.textContent));
    t('inbox: Gas-Umrechnung schon vorhanden (Stichtag-Liste)', factorP.length === 2 && factorP.every(p => /schon vorhanden/.test(p.textContent) && p.querySelector('input').disabled));
    t('inbox: Arbeitspreis aus der Rechnung: vorhanden und markiert', /schon vorhanden/.test(prop(/Arbeitspreis ab 01\.01\.2025/)?.textContent || ''));
    t('inbox: neuer Abschlag am Folgevertrag', /Abschlag ab 01\.02\.2026: 80,00\s€/.test(prop(/Abschlag ab/)?.textContent || ''));
    const bookP = prop(/Sonderzahlung buchen/);
    t('inbox: Buchung folgt der Rechnung (markiert, nicht vorbelegt)', !!bookP && !bookP.querySelector('input').checked && /prüfen/.test(bookP.textContent));
    t('inbox: Vorschau des Dokuments — ohne pdf.js der Link auf die Datei', /PDF öffnen/.test(dv.querySelector('#inbox-doc')?.textContent || '')
      && /api\.php\/api\/attachments\/att_/.test(dv.querySelector('#inbox-doc a')?.getAttribute('href') || ''));
    const manual = dv.querySelector('#inbox-manual a[data-manual]');
    t('inbox: „Von Hand erfassen“ zuerst die Rechnung, mit Zähler und Zeitraum', manual?.dataset.manual === 'bill'
      && /#\/bill-check\?.*utility=gas.*capture=1.*meter=m_gas_main.*from=2025-01-01.*until=2025-12-31/.test(manual.getAttribute('href') || ''), manual?.getAttribute('href'));
    manual.dispatchEvent(new global.window.MouseEvent('click', { bubbles: true, cancelable: true }));
    const dock = global.document.getElementById('doc-dock');
    t('inbox: Dokument bleibt daneben (Andockfenster außerhalb der Ansicht)', !!dock && global.document.body.classList.contains('doc-dock-open')
      && /Jahresabrechnung-Erdgas-2025\.pdf/.test(dock.textContent));
    dock?.querySelector('[data-act="close"]')?.click();
    t('inbox: Andockfenster schließt', !global.document.getElementById('doc-dock') && !global.document.body.classList.contains('doc-dock-open'));

    // Verbrauch korrigieren → die Rechnung ist danach vorbelegt; übernehmen
    cons.querySelector('[data-correct="consumption"]')?.click();
    const ed = cons.querySelector('[data-role="edit"]');
    ed.value = '10540';
    cons.querySelector('[data-role="ok"]')?.click();
    for (let i = 0; i < 40 && !dv.querySelector('#inbox-fields .inbox-field--edited'); i++) await new Promise(r => setTimeout(r, 50));
    const bill2 = [...dv.querySelectorAll('#inbox-proposals .inbox-proposal')].find(p => /Rechnung 01\.01\.2025/.test(p.textContent));
    t('inbox: nach der Korrektur ist die Rechnung vorbelegt', !!bill2?.querySelector('input').checked, bill2?.textContent.replace(/\s+/g, ' ').trim());
    dv.querySelector('#inbox-apply')?.click();
    for (let i = 0; i < 60 && !/Übernommen/.test(dv.querySelector('#inbox-state')?.textContent || ''); i++) await new Promise(r => setTimeout(r, 50));
    const bills = (await (await fetch(`${HOST}/api/utility/gas/bills`)).json()).data || [];
    const b = bills.find(x => x.period_from === '2025-01-01' && x.period_to === '2025-12-31');
    t('inbox: übernommen — Rechnung mit Dokument als Beleg und gebucht', !!b && b.attachment_ids?.length === 1 && !!b.special_payment_id
      && Math.round(b.invoice.energy_kwh) === 10540, JSON.stringify(b?.invoice || {}));
    t('inbox: Stand „Übernommen“', /Übernommen/.test(dv.querySelector('#inbox-state')?.textContent || ''));
    const cts = (await (await fetch(`${HOST}/api/utility/gas/contracts`)).json()).data || [];
    const next = cts.find(c => (c.advance_payments || []).some(a => a.from === '2026-02-01' && a.amount_eur === 80));
    t('inbox: der neue Abschlag steht am Vertrag, der ab Februar 2026 läuft', !!next && next.start <= '2026-02-01' && next.start > '2025-12-31', next?.id);
    const again = [...dv.querySelectorAll('#inbox-proposals .inbox-proposal')];
    // (die alte Ablesung 31.12.2024 war freiwillig und nicht angehakt — sie bleibt „neu“)
    const done = again.filter(p => /Ablesung am 31\.12\.2025|Rechnung 01\.01\.2025|Sonderzahlung buchen|Abschlag ab 01\.02\.2026/.test(p.textContent));
    t('inbox: danach ist alles Übernommene „schon vorhanden“', done.length === 4 && done.every(p => /schon vorhanden/.test(p.textContent)),
      done.map(p => p.textContent.replace(/\s+/g, ' ').trim()).join(' | '));
    const acts = (await (await fetch(`${HOST}/api/activity`)).json()).data || [];
    t('inbox: Verlauf „Aus Dokument übernommen“', acts.some(a => a.subject?.kind === 'inbox' && /Aus Dokument übernommen/.test(a.title)));
    // v3.7.0 (Integration nachzuege) — die übernommene Rechnung nennt ihr Dokument beim Namen
    // (eine Rechnung hängt an der Rechnung; an den Vertrag gehen Briefe wie Preis- und Abschlagsänderung)
    const bcx = await renderView(`${ROOT}/views/bill-check.js`, [], { query: new URLSearchParams('utility=gas&meter=m_gas_main&from=2025-01-01&to=2026-01-01') });
    for (let i = 0; i < 40 && !bcx.view.querySelector('[data-role="bill-list"] a[data-attachment]'); i++) await new Promise(r => setTimeout(r, 50));
    const bdoc = bcx.view.querySelector('[data-role="bill-list"] a[data-attachment]');
    t('inbox: Rechnungsliste — Beleg öffnen mit Dateinamen (nicht „Rechnung anhängen“)', bdoc?.getAttribute('aria-label') === 'Jahresabrechnung-Erdgas-2025.pdf öffnen'
      && /api\.php\/api\/attachments\/att_/.test(bdoc.getAttribute('href') || ''), bdoc?.getAttribute('aria-label') || 'kein Link');

    // v3.7.0 (Paket betrieb) — nach dem Übernehmen ist die Rechnung geprüft
    const gk = dv.querySelector('#inbox-gist')?.dataset.gist || '';
    t('inbox/betrieb: nach dem Übernehmen das Urteil von „Rechnung prüfen“', /^inbox\.gist\.invoice\.(ok|offMore|offLess|offQuantity)$/.test(gk), gk);
    const listAfter = (await (await fetch(`${HOST}/api/inbox`)).json()).data.entries.find(e => e.id === id);
    t('inbox/betrieb: die Liste trägt die gespeicherte Kernaussage samt Prüfung', listAfter?.gist?.key === gk && !!listAfter.gist.check?.bill_id, JSON.stringify(listAfter?.gist?.check || {}));

    // v3.7.0 (Paket betrieb) — Akte am Zähler: das übernommene Dokument mit seiner Kernaussage, die Rechnung nicht doppelt
    st.invalidateUtilities();
    const mv = await renderView(`${ROOT}/views/meters.js`, ['gas'], { query: new URLSearchParams() });
    const dz = [...mv.view.querySelectorAll('details.dossier')].find(d => d.dataset.dossierMeter === 'm_gas_main');
    t('akte: aufklappbar am Zähler', !!dz && /Akte/.test(dz.querySelector('summary')?.textContent || ''));
    if (dz) { dz.open = true; dz.dispatchEvent(new global.window.Event('toggle')); }
    for (let i = 0; i < 60 && !dz?.querySelector('[data-role="dossier-list"]'); i++) await new Promise(r => setTimeout(r, 50));
    const dItems = [...(dz?.querySelectorAll('.dossier__item') || [])];
    const dInbox = dItems.find(li => /Jahresabrechnung-Erdgas-2025\.pdf/.test(li.textContent));
    t('akte: Dokument aus dem Posteingang mit Kernaussage, Art und Öffnen', !!dInbox && dInbox.dataset.kind === 'inbox:invoice'
      && /passt zu deinen Daten|über deiner eigenen Rechnung|unter deiner eigenen Rechnung|weicht um/.test(dInbox.textContent)
      && /api\.php\/api\/attachments\/att_/.test(dInbox.querySelector('.dossier__open')?.getAttribute('href') || ''), dInbox?.textContent.replace(/\s+/g, ' ').trim());
    t('akte: das PDF steht nur einmal', dItems.filter(li => /Jahresabrechnung-Erdgas-2025\.pdf/.test(li.textContent)).length === 1);
    const kindSel = dz?.querySelector('[data-role="dossier-kind"]');
    if (kindSel) { kindSel.value = 'inbox:invoice'; kindSel.dispatchEvent(new global.window.Event('change')); }
    const shown = [...(dz?.querySelectorAll('.dossier__item') || [])];
    t('akte: Filter nach Art', shown.length >= 1 && shown.every(li => li.dataset.kind === 'inbox:invoice'), shown.map(li => li.dataset.kind).join(','));

    // v3.7.0 (Paket betrieb) — Zählerwechsel aus dem Posteingang: Dialog vorbelegt
    await renderView(`${ROOT}/views/meters.js`, ['gas'], { query: new URLSearchParams('replace=m_gas_main&date=2026-05-15&old=13902.5&new=0.8&serial=TEST-NEU&inbox=inb_x') });
    for (let i = 0; i < 40 && !global.document.querySelector('.modal #replace-form'); i++) await new Promise(r => setTimeout(r, 50));
    const rf = global.document.querySelector('.modal #replace-form');
    const fv = (n) => rf?.querySelector(`[name="${n}"]`)?.value;
    t('zähler: Zählertausch vorbelegt aus dem Posteingang', fv('date') === '2026-05-15' && fv('old_final_counter') === '13902,5'
      && fv('new_initial_counter') === '0,8' && fv('serial') === 'TEST-NEU' && !!global.document.querySelector('.modal [data-role="swap-prefilled"]'),
      rf ? ['date', 'old_final_counter', 'new_initial_counter', 'serial'].map(fv).join(' ') : 'kein Dialog');
    global.document.querySelector('.modal [data-act="cancel"]')?.click();
  } catch (e) { t('inbox: Posteingang', false, e.stack || e.message); }

  // ── v3.7.0 (Paket reifegrad) — Navigation je Stufe über den echten Router,
  //    Vorschlag „höhere Stufe“ höchstens einmal je Anlass (Haushalt mit PV),
  //    Schaubilder der Übersicht ab Erfahren, E-Auto/JAZ der Werkstatt ab
  //    Erfahren, Link auf die Funktionstabelle. Am Ende wieder Experte. ──
  const prevStorage = Object.getOwnPropertyDescriptor(global, 'localStorage');
  try {
    const L = await import(`${ROOT}/lib/levels.js`);
    const { startRouter } = await import(`${ROOT}/router.js`);
    const waitFor = async (fn, ms = 8000) => {
      for (let i = 0; i < ms / 50 && !fn(); i++) await new Promise(r => setTimeout(r, 50));
      return fn();
    };
    const routed = async (level, hash) => {
      const dom = freshDom();
      dom.window.scrollTo = () => {};
      L.applyLevel(level);
      dom.window.location.hash = hash;
      const view = global.document.getElementById('view');
      startRouter(view);
      await waitFor(() => view.querySelector('.view-host h1') && !view.querySelector('.view-host > .loading, .view-body > .loading'));
      return view;
    };
    const tabs = (v) => [...v.querySelectorAll('.section-tabs a')].map(a => a.getAttribute('href'));
    let v = await routed('beginner', '#/contracts');
    t('stufen: Einsteiger — Kosten ohne „Wechsel prüfen“ und „Rechnung prüfen“', !!v.querySelector('h1')
      && !tabs(v).includes('#/tariffs') && !tabs(v).includes('#/bill-check'), tabs(v).join(' ') || 'keine Tabs');
    v = await routed('advanced', '#/contracts');
    t('stufen: Erfahren — Kosten mit „Wechsel prüfen“ und „Rechnung prüfen“', tabs(v).includes('#/tariffs') && tabs(v).includes('#/bill-check'), tabs(v).join(' '));
    v = await routed('beginner', '#/settings');
    t('stufen: Einsteiger — Einstellungen Allgemein, Haushalt, Verbrauchsarten, Daten',
      tabs(v).join() === '#/settings,#/settings/household,#/settings/utilities,#/settings/data', tabs(v).join(' '));
    t('stufen: Einstellungen › Stufe verlinkt die Funktionstabelle',
      /referenz\/stufen\.md$/.test(decodeURIComponent(v.querySelector('[data-role="level-table"]')?.getAttribute('href') || '')),
      v.querySelector('[data-role="level-table"]')?.getAttribute('href') || 'kein Link');
    v = await routed('expert', '#/settings');
    t('stufen: Experte — alle neun Einstellungsseiten', tabs(v).length === 9, tabs(v).join(' '));
    // bis v3.6 löschte die Ansicht den Hinweis, wenn der Bereich keine Tabs hatte
    // (Kosten hat für Einsteiger nur „Verträge & Abschläge“)
    for (const hash of ['#/tariffs', '#/bill-check']) {
      v = await routed('beginner', hash);
      t(`stufen: ausgeblendete Seite ${hash} öffnet trotzdem, mit Hinweis über der Ansicht`, !!v.querySelector('.view-host > .level-hint')
        && /Erfahren/.test(v.querySelector('.level-hint')?.textContent || '') && !!v.querySelector('.view-host > .view-body h1'));
    }

    // Einsteiger mit PV (Schaufenster): Vorschlag „Erfahren“, ein zweites Mal nicht mehr
    const dom = freshDom();
    Object.defineProperty(global, 'localStorage', { value: dom.window.localStorage, configurable: true, writable: true });
    L.applyLevel('beginner');
    const dash = await import(`${ROOT}/views/dashboard.js?t=${Date.now()}`);
    const box = global.document.getElementById('view');
    await dash.render(box);
    await waitFor(() => box.querySelector('[data-role="level-hint"] .level-hint'), 3000);
    const hint = box.querySelector('[data-role="level-hint"] .level-hint');
    t('stufen: Einsteiger mit PV — Vorschlag „Erfahren“ mit Anlass', hint?.dataset.next === 'advanced'
      && (hint.dataset.reasons || '').split(' ').includes('pv') && /PV-Anlage/.test(hint.textContent), hint?.dataset.reasons || 'kein Hinweis');
    await dash.render(box);
    await new Promise(r => setTimeout(r, 400));
    t('stufen: … und höchstens einmal je Anlass', !!box.querySelector('[data-role="level-hint"]') && !box.querySelector('[data-role="level-hint"] .level-hint'));

    L.applyLevel('advanced');
    freshDom();
    const box2 = global.document.getElementById('view');
    await dash.render(box2);
    t('stufen: Erfahren — Übersicht mit „Schaubilder“ (bis v3.6 Experte)', !!box2.querySelector('section.dash-explainers'));
    L.applyLevel('beginner');
    freshDom();
    const box3 = global.document.getElementById('view');
    await dash.render(box3);
    t('stufen: Einsteiger — Übersicht ohne den Abschnitt „Schaubilder“', !!box3.querySelector('.dash-answers') && !box3.querySelector('section.dash-explainers'));

    L.applyLevel('expert');
    const { view: fv } = await renderView(`${ROOT}/views/forecast.js`);
    t('stufen: Werkstatt — E-Auto und JAZ unter „Mehr Stellschrauben“ (ab Erfahren)',
      ['#ws-ev-km', '#ws-ev-kwh', '#ws-jaz-from', '#ws-jaz-to'].every(s => fv.querySelector(s)?.closest('[data-min-level]')?.dataset.minLevel === 'advanced')
      && fv.querySelector('#ws-regression')?.closest('[data-min-level]')?.dataset.minLevel === 'expert');
  } catch (e) { t('stufen: Navigation und Vorschlag', false, e.stack || e.message); }
  finally {
    try { (await import(`${ROOT}/lib/levels.js`)).applyLevel('expert'); } catch { /* bleibt */ }
    if (prevStorage) Object.defineProperty(global, 'localStorage', prevStorage);
    else delete global.localStorage;
  }

  // ── 23. v3.7.0 (Paket interpolation, GitHub #37, #38, #39) — gemessen oder verteilt ──
  // Lädt selbst das Schaufenster (Zwischenzähler Werkstatt, vierteljährlich abgelesen),
  // legt einen Zähler an, den das Heizmodell schlecht trifft, und zum Schluss ein
  // Balkonkraftwerk mit zwei Ablesungen im Haushalt der Mieterin.
  try {
    const sleep = (ms) => new Promise(r => setTimeout(r, ms));
    const post = (path, body) => fetch(`${HOST}/api${path}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const json = async (path) => (await (await fetch(`${HOST}/api${path}`)).json()).data;
    const st = await import(`${ROOT}/state.js`);
    const fresh = () => { st.invalidateSettings(); st.invalidateUtilities(); };
    const imp = await post('/demo/import', { force: true });
    fresh();
    const cons = await json('/utility/gas/meters/m_gas_werkstatt/consumption');
    const year = Math.max(...cons.monthly.filter(m => m.interpolated).map(m => m.year).filter(y => cons.monthly.filter(m => m.year === y).length === 12));
    t('interpolation: Schaufenster mit Zwischenzähler, Monatswerte verteilt, Heizmodell über die Abstände', imp.ok && cons.interpolation?.interpolated_months > 12
      && cons.interpolation.heat_basis === 'intervals' && cons.monthly.every(m => m.interpolated === true),
      `${cons.interpolation?.interpolated_months} von ${cons.interpolation?.months}, ${cons.interpolation?.readings} Ablesungen, ${cons.interpolation?.heat_basis}`);
    t('interpolation: verteilte Monate nicht in der Abweichungstabelle', (cons.anomalies || []).length === 0 && cons.anomaly_quality === null);

    // Verbrauchsart: ein Hinweis mit der Zahl der Ablesungen im Jahr, ≈ in der Tabelle, schraffiert im Diagramm
    const { view } = await renderView(`${ROOT}/views/utility.js`, ['gas'], { query: new URLSearchParams(`meter=m_gas_werkstatt&year=${year}`) });
    await sleep(600);
    const note = view.querySelectorAll('[data-role="interpolation-note"]');
    const inYear = Number(cons.interpolation.readings_by_year?.[year] ?? 0);
    t('interpolation: Verbrauchsart — genau ein Hinweis, mit Ablesungen im Jahr und Heizmodell', note.length === 1
      && new RegExp(`12 von 12 Monatswerten in ${year} sind verteilt, nicht gemessen \\(${inYear} Ablesungen in ${year}\\)`).test(note[0].textContent)
      && /Heizmodell rechnet deshalb über die \d+ Abstände/.test(note[0].textContent), note[0]?.textContent.trim().slice(0, 160));
    t('interpolation: Monatstabelle markiert jeden verteilten Monat (≈ mit Klartext)', view.querySelectorAll('[data-role="interpolated"]').length === 12
      && /aus \d+ Tagen zwischen zwei Ablesungen/.test(view.querySelector('[data-role="interpolated"]')?.getAttribute('title') || '')
      && !!view.querySelector('[data-role="interpolated-legend"]'));
    const [mc] = chartOn('month-chart');
    const cur = (mc?.config?.data?.datasets || []).find(d => typeof d.backgroundColor === 'function' && !/zum Vergleich|Vorperiode/.test(d.label || ''));
    t('interpolation: Monatschart schraffiert (Farbe aus u.color) mit Satz darunter', /0\.2\)$/.test(cur?.backgroundColor?.({ dataIndex: 3 }) || '')
      && /Schraffierte Säulen/.test(view.querySelector('[data-role="month-chart-note"]')?.textContent || ''), cur?.backgroundColor?.({ dataIndex: 3 }));
    // v3.9.0 (L9) — Legende mit der Fläche eines gemessenen Monats, Vorjahr mit seinen verteilten Monaten schraffiert
    const dsets = mc?.config?.data?.datasets || [];
    const legend = mc?.config?.options?.plugins?.legend?.labels?.generateLabels?.({ data: mc.config.data }) || [];
    const mainItem = legend.find(it => it.datasetIndex === dsets.indexOf(cur));
    t('interpolation: Legende ohne Schraffur (Fläche wie ein gemessener Monat)', /0\.35\)$/.test(mainItem?.fillStyle || ''), mainItem?.fillStyle);
    const prevDs = dsets.find(d => d !== cur && d.type === 'bar');
    t('interpolation: Vorjahr zeigt verteilte Monate schraffiert', !prevDs || /0\.2\)$/.test(prevDs.backgroundColor?.({ dataIndex: 3 }) || ''),
      prevDs ? String(prevDs.backgroundColor?.({ dataIndex: 3 })) : 'ohne Vorjahr');
    const main = await renderView(`${ROOT}/views/utility.js`, ['gas'], { query: new URLSearchParams('meter=m_gas_main&year=2025') });
    await sleep(500);
    t('interpolation: Hauptzähler monatlich abgelesen — kein Hinweis', !main.view.querySelector('[data-role="interpolation-note"]'));

    // Analyse: Hinweis über den ganzen Zeitraum, „verteilt, nicht im Modell“, statt „alle im Erwartungsbereich“ die Grenze
    const an = await renderView(`${ROOT}/views/analysis.js`);
    const us = an.view.querySelector('#util-select');
    // Erst warten, bis die Ansicht ihren eigenen Aufbau beendet hat — sonst überschreibt er die Auswahl
    const settled = async (opt) => {
      for (let i = 0; i < 100 && !(an.view.querySelector(`#meter-select option[value="${opt}"]`)
        && !an.view.querySelector('#analysis-body > .loading') && an.view.querySelector('#ch-years')); i++) await sleep(50);
    };
    if (us.value !== 'gas') { us.value = 'gas'; us.dispatchEvent(new global.window.Event('change')); }
    await settled('m_gas_werkstatt');
    const ms = an.view.querySelector('#meter-select');
    ms.value = 'm_gas_werkstatt'; ms.dispatchEvent(new global.window.Event('change'));
    const wantReadings = `${cons.interpolation.readings} Ablesungen von `;
    for (let i = 0; i < 80 && !(an.view.querySelector('[data-role="interpolation-note"]')?.textContent || '').includes(wantReadings); i++) await sleep(50);
    const aNote = an.view.querySelector('[data-role="interpolation-note"]')?.textContent || '';
    t('interpolation: Analyse — Hinweis mit den Ablesungen des Zeitraums', new RegExp(`${cons.interpolation.readings} Ablesungen von `).test(aNote)
      && /Anomalien, Empfehlungen und die Heizkurve lassen diese Monate aus/.test(aNote), aNote.trim().slice(0, 140));
    t('interpolation: Analyse — Punkte der Heizkurve „verteilt, nicht im Modell“', /verteilt, nicht im Modell/.test(an.view.querySelector('[data-role="hdd-data"]')?.textContent || ''));
    t('interpolation: Analyse — keine Prüfung möglich, statt „alle Monate im Erwartungsbereich“', !!an.view.querySelector('[data-role="anomaly-limit"]')
      && !/alle Monate liegen innerhalb/.test(an.view.textContent));
    // wirklichkeit-2 (Punkt 6) — der Grund, mit der Zahl der Punkte
    t('wirklichkeit-2: ungültige Heizkurve sagt den Grund („zu wenige Datenpunkte (0)“)', /zu wenige Datenpunkte \(0\)/.test(an.view.querySelector('#reg-summary')?.textContent || ''),
      (an.view.querySelector('#reg-summary')?.textContent || '').replace(/\s+/g, ' ').slice(0, 160));

    // Gütesperre (#38): Monat für Monat ±40 % — das Heizmodell trifft nichts, die Tabelle bliebe leer.
    // Dazu eine stark konvexe Heizkurve (kubisch in den Gradtagen): Die Gerade drückt die Grundlast auf null.
    const mk = await (await post('/utility/gas/meters', { name: 'Werkstatt Test', installed_on: '2023-07-01', parent_meter_id: 'm_gas_main' })).json();
    const mid = mk?.data?.id;
    const temp = [0, 0.5, 1.5, 5, 9, 14, 17, 19, 18, 14, 9, 4, 1.5];
    let counter = 0;
    for (let i = 0; i <= 34; i++) {
      const d = new Date(Date.UTC(2023, 6 + i, 1));
      const date = d.toISOString().slice(0, 10);
      await post('/utility/gas/readings', { meter_id: mid, date, counter: Math.round(counter * 10) / 10 });
      const mo = d.getUTCMonth() + 1;
      const h = Math.max(0, 15 - temp[mo]);
      counter += (0.6 + 0.003 * h * h * h) * 30 * [0.6, 1.4][i % 2];
    }
    const q = (await json(`/utility/gas/meters/${mid}/consumption`)).anomaly_quality;
    us.dispatchEvent(new global.window.Event('change'));   // Zählerliste neu laden
    await sleep(100);
    await settled(mid);
    ms.value = mid; ms.dispatchEvent(new global.window.Event('change'));
    for (let i = 0; i < 80 && !an.view.querySelector('[data-role="anomaly-quality"]'); i++) await sleep(50);
    const qa = an.view.querySelector('[data-role="anomaly-quality"]');
    t('gütesperre: schlechtes Modell — Satz über der Tabelle mit Grenze', q?.model_quality === 'poor' && /passt schlecht/.test(qa?.textContent || '')
      && new RegExp(`eines mittleren gemessenen Monats \\(${Math.round(q.mean_month).toLocaleString('de-DE')} kWh\\)`).test(qa?.textContent || '')
      && new RegExp(`ab ${Math.round(q.detect_floor).toLocaleString('de-DE')} kWh`).test(qa?.textContent || ''), `${q?.ratio} · ${qa?.textContent.trim().slice(0, 120)}`);
    t('gütesperre: Sprung zur Heizgrenze (Einstellungen › Verbrauchsarten & Abrechnung › Physikalische Konstanten)',
      qa?.querySelector('a')?.getAttribute('href') === '#/settings/utilities?field=hdd_base_temp'
      && /Verbrauchsarten & Abrechnung › Physikalische Konstanten › HGT-Basistemperatur/.test(qa?.querySelector('a')?.textContent || ''));
    t('gütesperre: kein „alle Monate liegen innerhalb“', !/alle Monate liegen innerhalb/.test(an.view.textContent));
    // v3.7.0 (wirklichkeit-2, Schritt 3, N2) — der Satz über der Tabelle passt zu ihr: mit Zeilen nicht „dass sie leer ist“
    const rowsShown = ((await json(`/utility/gas/meters/${mid}/consumption`))?.anomalies || []).length;
    t(`gütesperre: Satz über der Tabelle passt zu ihr (${rowsShown} Zeilen)`, rowsShown > 0
      ? /kann ebenso der Fehler des Modells sein/.test(qa?.textContent || '') && !/dass sie leer ist/.test(qa?.textContent || '')
      : /dass sie leer ist/.test(qa?.textContent || ''), (qa?.textContent || '').trim().slice(-160));
    t('gütesperre: Grundlast weit unter den gemessenen Sommermonaten — Satz mit Modell und Messung je Tag',
      (q?.reasons || []).includes('base_load')
      && (() => {
        const one = (v) => v.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        return (qa?.textContent || '').includes(`Für den Sommer sagt es ${one(q.base_load.model_per_day)} kWh am Tag voraus, gemessen sind ${one(q.base_load.measured_per_day)} kWh am Tag.`);
      })(),
      `${JSON.stringify(q?.reasons)} · ${JSON.stringify(q?.base_load)}`);

    // ── wirklichkeit-2 (Punkt 3) — Gütesperre: Spanne statt Abschlagsvorschlag ──
    await post('/utility/gas/contracts', { meter_id: mid, provider: 'Testgas', start: '2023-07-01',
      working_prices: [{ from: '2023-07-01', ct_per_kwh: 11 }], advance_payments: [{ from: '2023-07-01', amount_eur: 120 }] });
    const cs = ((await json(`/utility/gas/meters/${mid}/contract-status`))?.contracts || []).find(c => c.is_current);
    t('wirklichkeit-2: Gütesperre → kein Abschlagsvorschlag, Spanne mit Grund', cs?.suggested_advance === null
      && (cs?.projection_uncertain?.reasons || []).includes('poor_fit') && cs.projection_uncertain.projected_range?.length === 2, JSON.stringify(cs?.projection_uncertain));
    const uv = await renderView(`${ROOT}/views/utility.js`, ['gas'], { query: new URLSearchParams(`meter=${mid}`) });
    for (let i = 0; i < 60 && !uv.view.querySelector('[data-role="balance-uncertain"]'); i++) await sleep(50);
    const bu = uv.view.querySelector('[data-role="balance-uncertain"]')?.textContent || '';
    t('wirklichkeit-2: Saldo-Karte — „unsicher: zwischen … und …, weil das Heizmodell schlecht passt“', /unsicher: zwischen .+ und .+, weil das Heizmodell schlecht zu diesem Zähler passt/.test(bu)
      && !/Abschlag von .+ statt/.test(uv.view.querySelector('.balance-suggest:not([data-role])')?.textContent || ''), bu.slice(0, 220));
    // ── Nachlauf vor dem Release (Skeptiker V1–V3) ──
    const vr = uv.view.querySelector('[data-role="verdict-range"]')?.textContent || '';
    const pointEur = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Math.abs(cs?.projected_end_balance ?? 0));
    t('Nachlauf V1: Saldo-Karte ohne Punktwert, nur die Spanne', /(Guthaben|Nachzahlung|ausgeglichen) bis .*(Guthaben|Nachzahlung|ausgeglichen)/.test(vr)
      && !(uv.view.querySelector('.balance-verdict__value')?.textContent || '').includes(pointEur) && !/≈/.test(vr)
      && /unsicher/.test(uv.view.querySelector('.balance-verdict__verdict')?.textContent || ''), `${vr} · ${pointEur}`);
    const er = uv.view.querySelector('.contracts-table [data-role="expected-range"]')?.textContent || '';
    t('Nachlauf V2: Verträge-Tabelle — „Erwartet“ als Spanne', /bis/.test(er), er);
    const co = await renderView(`${ROOT}/views/contracts-overview.js`);
    for (let i = 0; i < 80 && !co.view.querySelector('[data-role="expected-range"]'); i++) await sleep(50);
    const coText = co.view.querySelector('[data-role="expected-range"]')?.textContent.replace(/\s+/g, ' ').trim() || '';
    t('Nachlauf V2: Kosten & Verträge — „Erwartet zum …“ mit der Spanne der Saldo-Karte', /^Erwartet zum .+: .+ bis .+ \(unsicher\)$/.test(coText), coText);
    const wx = uv.view.querySelector('.ex-weather .explainer__text')?.textContent || '';
    t('Nachlauf V3: „Kälter oder mehr verbraucht?“ ohne bereinigte Aussage bei Gütesperre', !uv.view.querySelector('.ex-weather')
      || (/lässt sich nicht belegen/.test(wx) && !/Du hast wirklich/.test(wx)), wx.slice(0, 200));
    // V6 — Teilmonat im Jahresvergleich: mit *, ohne Δ, nicht in der Summe
    const { renderYoyWidget } = await import(`${ROOT}/views/analysis.js`);
    const yoy = global.document.createElement('div');
    yoy.innerHTML = renderYoyWidget({ keys: [2025, 2026], names: { 2025: '2025', 2026: '2026' }, labels: ['Jan', 'Feb', 'Mär'],
      values: { 2025: [100, 100, 100], 2026: [110, 90, 40] }, rows: { 2025: [], 2026: [] },
      partial: { 2025: [false, false, false], 2026: [false, false, true] } }, { key: 'strom', consumption_unit: 'kWh' });
    const foot = [...yoy.querySelectorAll('tfoot td')].map(td => td.textContent.trim());
    const mar = [...yoy.querySelectorAll('tbody tr')][2]?.textContent || '';
    t('Nachlauf V6: Teilmonat mit *, ohne Δ, Summe nur über volle Monate', foot[1] === '200' && foot[2] === '200'
      && !!yoy.querySelector('[data-role="yoy-partial"]') && !/[+−-]\d/.test(mar) && !!yoy.querySelector('[data-role="yoy-partial-note"]'), `${foot.join(' | ')} · ${mar.replace(/\s+/g, ' ')}`);

    // ── wirklichkeit-2 (Schritt 3, N3) — vierteljährlich abgelesen (Heizmodell über die Abstände): Spanne ──
    const fewC = (await (await post('/utility/gas/contracts', { meter_id: 'm_gas_werkstatt', provider: 'Testgas', start: '2024-01-01',
      working_prices: [{ from: '2024-01-01', ct_per_kwh: 11 }], advance_payments: [{ from: '2024-01-01', amount_eur: 60 }] })).json())?.data;
    const fs = ((await json('/utility/gas/meters/m_gas_werkstatt/contract-status'))?.contracts || []).find(c => c.is_current);
    t('wirklichkeit-2 (Schritt 3): zu wenig gemessen → Spanne statt Vorschlag', fs?.suggested_advance === null
      && (fs?.projection_uncertain?.reasons || []).includes('few_measured'), JSON.stringify(fs?.projection_uncertain));
    const fv = await renderView(`${ROOT}/views/utility.js`, ['gas'], { query: new URLSearchParams('meter=m_gas_werkstatt') });
    for (let i = 0; i < 60 && !fv.view.querySelector('[data-role="balance-uncertain"]'); i++) await sleep(50);
    const fb = fv.view.querySelector('[data-role="balance-uncertain"]')?.textContent || '';
    t('wirklichkeit-2 (Schritt 3): Saldo-Karte — „weil zu wenige Monate gemessen sind, um das Heizmodell zu prüfen“',
      /unsicher: zwischen .+ und .+, weil zu wenige Monate gemessen sind, um das Heizmodell zu prüfen/.test(fb), fb.slice(0, 220));
    const fm = fv.view.querySelector('.ex-money .explainer__text')?.textContent || '';
    t('wirklichkeit-2 (Schritt 3): „Wohin geht mein Geld?“ mit Spanne', !fv.view.querySelector('.ex-money') || /je nach Schätzung zwischen/.test(fm), fm.slice(0, 220));
    if (fewC?.id) await fetch(`${HOST}/api/utility/gas/contracts/${fewC.id}`, { method: 'DELETE' });
    // dieselbe Prognose: das Jahr ohne Band, mit Grund und Hinweis
    const fcv = await renderView(`${ROOT}/views/forecast.js`);
    await sleep(600);
    const fu = fcv.view.querySelector('#util-select');
    if (fu && fu.value !== 'gas') { fu.value = 'gas'; fu.dispatchEvent(new global.window.Event('change')); await sleep(600); }
    const fms = fcv.view.querySelector('#meter-select');
    if (fms) { fms.value = 'm_gas_werkstatt'; fms.dispatchEvent(new global.window.Event('change')); }
    for (let i = 0; i < 100 && !/Heizmodell zu prüfen/.test(fcv.view.querySelector('#fc-info')?.textContent || ''); i++) await sleep(50);
    const finfo = fcv.view.querySelector('#fc-info')?.textContent || '';
    t('wirklichkeit-2 (Schritt 3): Prognose — Jahr ohne Band, weil zu wenig gemessen, mit Hinweis',
      /ohne Band, weil zu wenige Monate gemessen sind, um das Heizmodell zu prüfen/.test(finfo)
      && /Zu wenige gemessene Monate, um das Heizmodell zu prüfen: Die Prognose zeigt kein Band/.test(finfo), finfo.replace(/\s+/g, ' ').slice(0, 300));
    // N1 — Strom: das Jahresband aus der Streuung über die Jahre, nicht „je nach Winter“
    if (fu) { fu.value = 'strom'; fu.dispatchEvent(new global.window.Event('change')); }
    const fcInfo = () => fcv.view.querySelector('#fc-info')?.textContent || '';
    for (let i = 0; i < 100 && !(/Jahr: /.test(fcInfo()) && !/Heizmodell zu prüfen/.test(fcInfo())); i++) await sleep(50);
    const sinfo = fcv.view.querySelector('#fc-info')?.textContent || '';
    t('wirklichkeit-2 (Schritt 3): Prognose Strom — Jahresband nach der Streuung über die Jahre', /nach der Streuung der Monate über die Jahre/.test(sinfo) && !/je nach Winter/.test(sinfo),
      sinfo.replace(/\s+/g, ' ').slice(0, 300));
    // v3.9.0 (auswertung, Zusatzbefund) — Strom einmal im Jahr abgelesen: die Jahresmenge der letzten 365 Tage, mit Hinweis
    const yearly = (await (await post('/utility/strom/meters', { name: 'Jahresablesung Test', installed_on: '2023-03-01', parent_meter_id: 'm_strom_main' })).json())?.data?.id;
    for (const [date, counter] of [['2023-03-01', 0], ['2024-03-01', 900], ['2025-03-01', 1800], ['2026-03-01', 2900]]) {
      await post('/utility/strom/readings', { meter_id: yearly, date, counter });
    }
    const fy = fcv.view.querySelector('#meter-select');
    if (fy && yearly) {
      for (let i = 0; i < 40 && !fy.querySelector(`option[value="${yearly}"]`); i++) {
        fu.value = 'gas'; fu.dispatchEvent(new global.window.Event('change')); await sleep(300);
        fu.value = 'strom'; fu.dispatchEvent(new global.window.Event('change')); await sleep(300);
      }
      const sel = fcv.view.querySelector('#meter-select');
      sel.value = yearly; sel.dispatchEvent(new global.window.Event('change'));
    }
    for (let i = 0; i < 100 && !/letzten 365 Tage/.test(fcInfo()); i++) await sleep(50);
    t('Zusatzbefund: Prognose Strom mit Jahresablesungen — Jahresmenge der letzten 365 Tage (1.100 kWh) mit Hinweis',
      /Die Jahresmenge folgt dem Verbrauch der letzten 365 Tage \(1\.100\skWh, März 2025 bis [^)]*2026\)/.test(fcInfo()), fcInfo().replace(/\s+/g, ' ').slice(0, 400));
    if (yearly) await fetch(`${HOST}/api/utility/strom/meters/${yearly}`, { method: 'DELETE' });
    // v3.9.0 (L15) — künftiger Vertrag: Preis und Abschlag seines Beginns statt „–“, der erwartete Saldo mit ≈ und Legende
    const fut = (await (await post('/utility/gas/contracts', { meter_id: 'm_gas_main', provider: 'Künftig Testgas', start: '2027-06-01',
      working_prices: [{ from: '2027-06-01', ct_per_kwh: 9.87 }], base_prices: [{ from: '2027-06-01', eur_per_month: 14 }],
      advance_payments: [{ from: '2027-06-01', amount_eur: 111 }] })).json())?.data;
    const fg = await renderView(`${ROOT}/views/utility.js`, ['gas'], { query: new URLSearchParams('meter=m_gas_main') });
    for (let i = 0; i < 60 && !fg.view.querySelector('.contracts-table'); i++) await sleep(50);
    const futRow = [...fg.view.querySelectorAll('.contracts-table tr')].find(r => r.textContent.includes('Künftig Testgas'));
    const futText = futRow?.textContent.replace(/\s+/g, ' ') || '';
    t('L15: künftiger Vertrag mit Arbeitspreis und Abschlag seines Beginns, Saldo mit ≈ und Legende', !!futRow && /9,87/.test(futText) && /111/.test(futText)
      && (!futRow.querySelector('[data-role="expected-future"]') || /≈/.test(futRow.querySelector('[data-role="expected-future"]').textContent))
      && (!futRow.querySelector('[data-role="expected-future"]') || !!fg.view.querySelector('[data-role="future-legend"]')), futText.slice(0, 300));
    if (fut?.id) await fetch(`${HOST}/api/utility/gas/contracts/${fut.id}`, { method: 'DELETE' });

    // ── wirklichkeit-2 (Punkt 1) — Heizgrenze-Vorschlag: ein Zähler, der genau unter 10,5 °C heizt ──
    const temps = await json('/temperatures');
    const hk = (await (await post('/utility/gas/meters', { name: 'Heizgrenze Test', installed_on: '2023-07-01', parent_meter_id: 'm_gas_main' })).json())?.data?.id;
    let ctr = 0;
    for (const d = new Date(Date.UTC(2023, 6, 1)); d <= new Date(Date.UTC(2026, 4, 1)); d.setUTCDate(d.getUTCDate() + 1)) {
      const iso = d.toISOString().slice(0, 10);
      if (iso.endsWith('-01')) await post('/utility/gas/readings', { meter_id: hk, date: iso, counter: Math.round(ctr * 100) / 100 });
      const avg = temps?.[iso]?.avg;
      ctr += 0.4 + 0.25 * Math.max(0, 10.5 - (avg ?? 10.5));
    }
    const hl = (await json(`/utility/gas/meters/${hk}/consumption?heat_limit=1`))?.heat_limit;
    t('wirklichkeit-2: Heizgrenze-Vorschlag über die API (nur mit ?heat_limit=1)', hl?.suggest === true && hl.best >= 9.5 && hl.best <= 11.5
      && (await json(`/utility/gas/meters/${hk}/consumption`))?.heat_limit === null, JSON.stringify({ ...hl, grid: undefined }));
    us.dispatchEvent(new global.window.Event('change'));
    await sleep(100);
    await settled(hk);
    ms.value = hk; ms.dispatchEvent(new global.window.Event('change'));
    for (let i = 0; i < 100 && !an.view.querySelector('[data-role="heat-limit"]'); i++) await sleep(50);
    const hb = an.view.querySelector('[data-role="heat-limit"]');
    const btn = hb?.querySelector('[data-role="heat-limit-apply"]');
    t('wirklichkeit-2: Analyse — Satz mit beiden Streuungen und Knopf je Zähler (Stufe Experte)', /als Heizgrenze passt das Heizmodell deutlich besser/.test(hb?.textContent || '')
      && /gilt dann für diesen Zähler/.test(hb?.textContent || '') && /Heizgrenze dieses Zählers auf/.test(btn?.textContent || '')
      && Number(btn?.dataset.value) === hl?.best, (hb?.textContent || '').trim().slice(0, 200));
    btn?.click();
    let m1 = null;
    for (let i = 0; i < 60; i++) { m1 = await json(`/utility/gas/meters/${hk}`); if (Number(m1?.hdd_base_temp) === hl?.best) break; await sleep(50); }
    const s1 = await json('/settings');
    t('wirklichkeit-2: übernommen erst per Klick — am Zähler, die Einstellung bleibt', Number(m1?.hdd_base_temp) === hl?.best && Number(s1?.hdd_base_temp) === 15,
      `${m1?.hdd_base_temp} / ${s1?.hdd_base_temp}`);
    for (let i = 0; i < 100 && !an.view.querySelector('[data-role="heat-limit-reset"]'); i++) await sleep(50);
    const own = an.view.querySelector('[data-role="heat-limit-own"]');
    t('wirklichkeit-2: danach eigene Grenze genannt, kein neuer Vorschlag, zurücknehmbar', /eigener Heizgrenze/.test(own?.textContent || '')
      && !an.view.querySelector('[data-role="heat-limit"]') && !!own?.querySelector('[data-role="heat-limit-reset"]'), (own?.textContent || '').trim().slice(0, 140));
    own?.querySelector('[data-role="heat-limit-reset"]')?.click();
    let m2 = null;
    for (let i = 0; i < 60; i++) { m2 = await json(`/utility/gas/meters/${hk}`); if (m2 && !('hdd_base_temp' in m2)) break; await sleep(50); }
    t('wirklichkeit-2: zurückgenommen — der Zähler rechnet wieder mit der Einstellung', m2 && !('hdd_base_temp' in m2), JSON.stringify(m2?.hdd_base_temp));
    fresh();

    // PV (#39): Balkonkraftwerk mit zwei Ablesungen
    await post('/demo/import', { force: true, persona: 'mieterin' });
    const s0 = await json('/settings');
    await fetch(`${HOST}/api/settings`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ active_utilities: [...new Set([...(s0.active_utilities || []), 'pv_erzeugung'])] }) });
    const pvm = (await (await post('/utility/pv_erzeugung/meters', { name: 'Balkonkraftwerk', installed_on: '2024-04-01' })).json())?.data;
    await post('/utility/pv_erzeugung/readings', { meter_id: pvm?.id, date: '2024-04-01', counter: 0 });
    await post('/utility/pv_erzeugung/readings', { meter_id: pvm?.id, date: '2025-10-01', counter: 1104.3 });
    fresh();
    const pv = await json('/pv-summary');
    t('pv: Übersicht mit Hinweis „verteilt“ und der Zahl der Ablesungen', (pv.hints || []).includes('interpolated')
      && pv.interpolation?.readings === 2 && pv.interpolation.utility === 'pv_erzeugung', JSON.stringify(pv.interpolation));
    const pvv = await renderView(`${ROOT}/views/utility.js`, ['pv_erzeugung'], { query: new URLSearchParams('year=2025') });
    await sleep(600);
    const pvNote = pvv.view.querySelectorAll('[data-role="interpolation-note"]');
    t('pv: ein Hinweis — Monatswerte verteilt, Eigenverbrauch, Ersparnis und Amortisation Schätzungen', pvNote.length === 1
      && /von 9 Monatswerten in 2025 sind verteilt|9 von 9 Monatswerten in 2025 sind verteilt/.test(pvNote[0].textContent)
      && /Eigenverbrauch, Autarkiequote, Ersparnis und Amortisation sind für diese Monate Schätzungen/.test(pvNote[0].textContent), pvNote[0]?.textContent.trim().slice(0, 200));
    t('pv: Energiefluss — verteilte Monate markiert', !!pvv.view.querySelector('[data-role="pv-interpolated-legend"]')
      && /≈/.test(pvv.view.querySelector('.chart-data')?.textContent || ''));
  } catch (e) { t('interpolation: gemessen oder verteilt', false, e.stack || e.message); }

  // ── 24. v3.7.1 — Umgebungsname als Abzeichen neben dem Logo (Design wie „DEMO“) ──
  try {
    const patch = async (body) => (await fetch(`${HOST}/api/settings`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
    const saved = await patch({ env_name: '  <i>Test</i>   Haus  ' });
    t('umgebung: Server bereinigt und begrenzt (12 Zeichen, ohne Tags)', saved?.data?.env_name === 'Test Haus', JSON.stringify(saved?.data?.env_name));
    const dm = await import(`${ROOT}/lib/demo-mode.js`);
    let brand = global.document.querySelector('.topbar__brand');
    if (!brand) { brand = global.document.createElement('div'); brand.className = 'topbar__brand'; global.document.body.prepend(brand); }
    dm.mountEnvBadge(saved?.data?.env_name);
    const badge = brand.querySelector('[data-role="env-badge"]');
    t('umgebung: Abzeichen mit Wert, Klasse des Demo-Abzeichens, kein Knopf', badge?.textContent === 'Test Haus'
      && badge.classList.contains('demo-chip') && badge.tagName === 'SPAN' && /Umgebung: Test Haus/.test(badge.title || ''), badge?.outerHTML);
    dm.mountEnvBadge('');
    t('umgebung: ohne Wert kein Abzeichen', !brand.querySelector('[data-role="env-badge"]'));
    dm.mountEnvBadge('TEST', true);
    t('umgebung: in der öffentlichen Demo bleibt „DEMO“ (Name übergangen)', !brand.querySelector('[data-role="env-badge"]'));
    const css = readFileSync(resolve(__dir, '..', 'public', 'css', 'components.css'), 'utf8');
    t('umgebung: einzeilig auch am Handy (nowrap, schrumpft nicht)', /\.demo-chip--env\s*\{[^}]*white-space:\s*nowrap[^}]*flex-shrink:\s*0/.test(css));
    // Einstellungen → Allgemein: das Feld mit Grenze
    (await import(`${ROOT}/state.js`)).invalidateSettings();
    const sv = await renderView(`${ROOT}/views/settings.js`, ['general']);
    await new Promise(r => setTimeout(r, 500));
    const field = sv.view.querySelector('input[data-key="env_name"]');
    t('umgebung: Feld unter Einstellungen › Allgemein mit 12 Zeichen', field?.getAttribute('maxlength') === '12' && field.value === 'Test Haus', field?.outerHTML?.slice(0, 200));
    await patch({ env_name: '' });
  } catch (e) { t('umgebung: Abzeichen', false, e.stack || e.message); }

  // ── 25. v3.7.1 (GitHub #38) — /forecast beachtet hdd_base wie /consumption ──
  try {
    const get = async (p) => { const r = await fetch(`${HOST}/api${p}`); return { status: r.status, data: (await r.json())?.data }; };
    // das Schaufenster (davor lag der Haushalt der Mieterin, ohne Gas)
    await fetch(`${HOST}/api/demo/import`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ force: true }) });
    const m = 'm_gas_main';
    const [plain, at12, cons12, bad] = await Promise.all([get(`/utility/gas/meters/${m}/forecast`), get(`/utility/gas/meters/${m}/forecast?hdd_base=12`),
      get(`/utility/gas/meters/${m}/consumption?hdd_base=12`), get(`/utility/gas/meters/${m}/forecast?hdd_base=40`)]);
    const last = (r) => (r.data?.historical || []).slice(-1)[0]?.hdd;
    t('#38: /forecast?hdd_base=12 rechnet mit 12 °C (Gradtage wie /consumption?hdd_base=12)', at12.data?.hdd_base === 12 && plain.data?.hdd_base !== 12
      && last(at12) === (cons12.data?.monthly || []).slice(-1)[0]?.hdd && last(at12) !== last(plain), `${plain.data?.hdd_base} → ${at12.data?.hdd_base} · ${last(plain)} → ${last(at12)}`);
    t('#38: /forecast mit Heizgrenze außerhalb 6–22 °C: 400', bad.status === 400, String(bad.status));
  } catch (e) { t('#38: /forecast hdd_base', false, e.stack || e.message); }

  console.log(`\n  ERGEBNIS: ${pass} bestanden, ${fail} fehlgeschlagen`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('  HARNESS-FEHLER:', e.stack || e.message); process.exit(2); });
