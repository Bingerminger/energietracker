// v3.0.0 — Abdeckungstest der öffentlichen Demo (GitHub Pages).
//
// Baut die Demo (tools/build-demo.mjs, braucht PHP), liefert sie über einen
// statischen Server aus und startet die ECHTE App (app.js aus dem
// Bauergebnis) in JSDOM — so, wie ein Besucher sie lädt. Dann wird jede Seite
// aus dem Navigationsmodell aufgerufen. Grün nur, wenn
//   • keine Anfrage ohne gespeicherte Antwort blieb (demo-mode.js sammelt sie),
//   • keine Ausnahme und kein Konsolenfehler auftrat,
//   • Kennzeichen, Hinweisleiste und Sprachwahl da sind,
//   • Schreibzugriffe mit „nichts gespeichert“ abgelehnt werden.
//
//   node tests/demo.test.mjs            (baut nach einem Temp-Verzeichnis)
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { mkdtempSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, extname, join, resolve, normalize } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { buildDemo } from '../tools/build-demo.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

/**
 * v3.6.0 — Personen in der gebauten Demo (jeder Haushalt, deutsch): Jede
 * Zuständigkeit zeigt auf eine Person aus `/api/people` oder ist als gelöscht
 * markiert; ebenso jede Person im Verlauf. Mindestens ein Haushalt zeigt eine
 * gelöschte Person mit erledigten Einträgen.
 */
function checkPeople(out, meta) {
  const deleted = [];
  for (const persona of meta.personas || ['showcase']) {
    const dir = persona === 'showcase' ? join(out, 'demo-api') : join(out, 'demo-api', 'p', persona);
    const idx = JSON.parse(readFileSync(join(dir, 'index-de.json'), 'utf8'));
    const get = (key) => JSON.parse(readFileSync(join(out, 'demo-api/r', idx[key]), 'utf8')).data;
    const ids = new Set(get('/api/people').map(p => p.id));
    const bad = [];
    for (const r of get('/api/reminders')) {
      if (r.assignee && !ids.has(r.assignee_id) && !r.assignee_deleted) bad.push(`Termin ${r.title}: ${r.assignee}`);
    }
    for (const e of get('/api/activity')) {
      for (const p of [e.by, e.undone?.by, e.assignee]) {
        if (!p || p.former || (p.type && p.type !== 'person')) continue;
        if (p.deleted) { deleted.push(`${persona}: ${p.name}`); continue; }
        if (!ids.has(p.id)) bad.push(`Verlauf: ${p.name}`);
      }
    }
    t(`Personen ${persona}: Zuständige und Verlauf angelegt`, ids.size > 0 && bad.length === 0, bad.slice(0, 3).join(' · ') || `${ids.size} Personen`);
  }
  t('Eine gelöschte Person mit erledigten Einträgen', deleted.length > 0, [...new Set(deleted)].join(' · '));
}

/**
 * v3.6.0 (Paket einpers) — Einordnung in der gebauten Demo: Eigenheim klassisch
 * trägt eigene Vergleichswerte und zeigt die volle Ampel (jede Zeile bewertet,
 * keine Einladung); Wohnfläche und Personen gelten in jedem Haushalt als
 * eingetragen (sonst zählten die Standardwerte nicht).
 */
function checkComparison(out, meta) {
  for (const persona of (meta.personas || []).filter(p => p !== 'showcase')) {
    const idx = JSON.parse(readFileSync(join(out, 'demo-api', 'p', persona, 'index-de.json'), 'utf8'));
    const get = (key) => JSON.parse(readFileSync(join(out, 'demo-api/r', idx[key]), 'utf8')).data;
    const s = get('/api/settings');
    t(`Einordnung ${persona}: Wohnfläche und Personen eingetragen`, Array.isArray(s.assumed_keys) && s.assumed_keys.length === 0, JSON.stringify(s.assumed_keys));
    if (persona !== 'eigenheim-klassisch') continue;
    const c = get('/api/benchmarks/comparison');
    const ratings = [c.strom?.rating, c.heating?.[0]?.rating, c.water?.rating];
    t('Einordnung eigenheim-klassisch: volle Ampel ohne Einladung', ratings.every(Boolean) && c.missing.length === 0, `${c.year}: ${ratings.join(',')} · ${c.missing.join(',')}`);
  }
}

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json', '.css': 'text/css',
  '.csv': 'text/csv', '.pdf': 'application/pdf', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };

function serve(dir, port) {
  const server = createServer((req, res) => {
    let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (p.endsWith('/')) p += 'index.html';
    const file = normalize(join(dir, p));
    if (!file.startsWith(dir)) { res.writeHead(403); res.end(); return; }
    try {
      if (!statSync(file).isFile()) throw new Error();
      res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream' });
      res.end(readFileSync(file));
    } catch { res.writeHead(404); res.end('not found'); }
  });
  return new Promise(r => server.listen(port, '127.0.0.1', () => r(server)));
}

(async () => {
  const out = mkdtempSync(join(tmpdir(), 'et-demo-dist-'));
  let server;
  try {
    const built = await buildDemo({ out, port: Number(process.env.ET_DEMO_BUILD_PORT) || 8895, log: () => {} });
    t('Demo gebaut', built.keys > 100 && built.files > 10, `${built.keys} Anfragen, ${built.responses} Antworten, ${built.files} Dateien`);
    const meta = JSON.parse(readFileSync(join(out, 'demo-api/meta.json'), 'utf8'));
    t('meta.json: Version und Bautag', meta.version === readFileSync(join(ROOT, 'VERSION'), 'utf8').trim()
      && /^\d{4}-\d{2}-\d{2}$/.test(meta.today), `${meta.version} · ${meta.today}`);
    const langs = Object.keys(JSON.parse(readFileSync(join(ROOT, 'public/locales/languages.json'), 'utf8')));
    const sameKeys = langs.every(l => {
      const idx = JSON.parse(readFileSync(join(out, `demo-api/index-${l}.json`), 'utf8'));
      return Object.keys(idx).length === meta.keys;   // v3.2.0 — built.keys zählt alle Haushalte
    });
    t('Index je Sprache vollständig', sameKeys, langs.join(' '));
    checkPeople(out, meta);
    checkComparison(out, meta);   // v3.6.0 (Paket einpers)
    // v3.1.0 — Server-Texte in der Sprache der Demo (X-ET-Language), nicht in der Standardsprache
    const labelOf = (lang) => {
      const idx = JSON.parse(readFileSync(join(out, `demo-api/index-${lang}.json`), 'utf8'));
      const res = JSON.parse(readFileSync(join(out, 'demo-api/r', idx['/api/utilities']), 'utf8'));
      return res.data.find(u => u.key === 'strom')?.label;
    };
    t('Bezeichnungen je Sprache vom Server', labelOf('en') === 'Electricity' && labelOf('fr') === 'Électricité'
      && labelOf('de') === 'Strom', `${labelOf('de')} · ${labelOf('en')} · ${labelOf('fr')}`);
    // v3.1.0 (I18N-24) — die Demo-Daten selbst in der Sprache (Zählername)
    const meterOf = (lang) => {
      const idx = JSON.parse(readFileSync(join(out, `demo-api/index-${lang}.json`), 'utf8'));
      const res = JSON.parse(readFileSync(join(out, 'demo-api/r', idx['/api/utility/gas/meters']), 'utf8'));
      return res.data[0]?.name;
    };
    t('Demo-Daten je Sprache', meterOf('de') === 'Hauptzähler Gas' && meterOf('en') === 'Main gas meter'
      && meterOf('nl') === 'Hoofdgasmeter', `${meterOf('de')} · ${meterOf('en')} · ${meterOf('nl')}`);
    const html = readFileSync(join(out, 'index.html'), 'utf8');
    t('index.html: data-demo, kein Service Worker', html.includes(' data-demo') && !html.includes("serviceWorker.register('sw.js')"));

    const port = Number(process.env.ET_DEMO_PORT) || 8894;   // v3.4.0 — parallele Läufe auf eigenem Port
    server = await serve(out, port);
    const BASE = `http://127.0.0.1:${port}/`;
    const dom = new JSDOM(html, { url: BASE + '?lang=de#/dashboard', pretendToBeVisual: true });
    const w = dom.window;
    for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event',
      'getComputedStyle', 'localStorage', 'sessionStorage', 'MouseEvent', 'KeyboardEvent', 'requestAnimationFrame']) {
      try { global[k] = w[k]; } catch { Object.defineProperty(global, k, { value: w[k], configurable: true, writable: true }); }
    }
    Object.defineProperty(global, 'navigator', { value: w.navigator, configurable: true, writable: true });
    let inflight = 0;
    const nativeFetch = global.fetch;
    const demoApiCalls = [];   // v3.12.0 — jeder Abruf unter demo-api/ trägt die Marke des Baus
    const browserFetch = async (input, init) => {
      if (typeof input === 'string' && !/^https?:\/\//i.test(input)) input = new URL(input, w.location.href).href;
      if (String(input).includes('/demo-api/')) demoApiCalls.push([String(input), init]);
      inflight++;
      try { return await nativeFetch(input, init); } finally { inflight--; }
    };
    w.fetch = browserFetch;
    global.fetch = browserFetch;
    w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    w.scrollTo = () => {};
    const Chart = function (canvas, config) { return { canvas, config, destroy() {}, update() {}, resize() {} }; };
    Chart.defaults = { color: '', borderColor: '', font: { family: '', size: 12 },
      plugins: { legend: { labels: {} }, tooltip: {} }, scale: { grid: {}, ticks: {} } };
    Chart.register = () => {};
    w.Chart = Chart; global.Chart = Chart;
    w.confirm = () => true; global.confirm = w.confirm;

    const errors = [];
    const origError = console.error;
    console.error = (...a) => { errors.push(a.map(x => x?.message || String(x)).join(' ')); };
    w.addEventListener('error', e => errors.push('error: ' + (e.message || e.error?.message)));
    process.on('unhandledRejection', e => errors.push('unhandled: ' + (e?.message || e)));

    const settle = async () => {
      for (let i = 0; i < 100; i++) {
        await sleep(30);
        if (inflight === 0 && !w.document.querySelector('#view .loading')) { await sleep(60); if (inflight === 0) return; }
      }
    };

    // v3.2.0 (F1018) — der Rundgang zeigt das Schaufenster; ohne gewählten Haushalt
    // öffnet die Demo den Einrichtungsassistenten (geprüft weiter unten)
    w.localStorage.setItem('et-demo-persona', 'showcase');
    w.localStorage.setItem('et-demo-level', 'expert');
    // v3.12.0 — dieselbe Version wie der Bau, sonst setzt der Start die Wahl oben zurück (tests/demo-start.test.mjs)
    w.localStorage.setItem('et-demo-version', meta.version);
    await import(pathToFileURL(join(out, 'public/js/app.js')).href);
    await settle();
    await sleep(200);
    t('App startet in der Demo', !!w.document.querySelector('#view .view-header, #view h1'), w.document.title);
    t('gleiche Version: Die Wahl bleibt, kein Assistent', w.localStorage.getItem('et-demo-persona') === 'showcase' && !w.document.querySelector('.modal #setup-lang'));
    t('Kennzeichen „Demo“ in der Kopfleiste', !!w.document.querySelector('.topbar__brand .demo-chip'));
    const bar = w.document.getElementById('demo-bar');
    t('Hinweisleiste mit Bautag, Installieren-Link und Sprachwahl',
      !!bar && bar.textContent.includes(meta.today.slice(8, 10)) && !!bar.querySelector('a[href*="github.com/Bingerminger/energietracker"]')
      && bar.querySelectorAll('select option').length === langs.length);

    const { api } = await import(pathToFileURL(join(out, 'public/js/api.js')).href);
    const utilities = await api.listUtilities();
    const settings = await api.settings();
    const active = utilities.filter(u => (settings.active_utilities || []).includes(u.key));
    const year = new Date().getFullYear();
    const routes = ['#/dashboard', '#/zaehlerstaende',
      ...active.flatMap(u => [`#/utility/${u.key}`, `#/household/meters?art=${u.key}`, `#/utility/${u.key}/contracts`]),
      `#/utility/gas?year=${year - 1}`,
      '#/contracts', '#/contracts/past', '#/tariffs', '#/bill-check', '#/figures', '#/analysis', '#/forecast', '#/flexibility', '#/report', '#/report/print',
      '#/todo', '#/todo?art=rec', '#/todo?art=rights', '#/todo/dates', '#/todo/history', '#/todo/history/ai', '#/help',
      // v3.12.0 (Paket wegweiser) — Wegweiser: Auswahl, jeder Weg, die Doku-Seite mit Bildern
      '#/help/setups', '#/help/setups/by-hand', '#/help/setups/inbox', '#/help/setups/home-assistant', '#/help/setups/local-ai',
      '#/help/setups/cloud-ai', '#/help/setups/together', '#/help/doc/einstieg/wegweiser.md',
      // v3.7.0 — Posteingang: Liste, Prüfliste und die Vorschau des Beispieldokuments
      '#/inbox', '#/inbox?view=review', ...(await api.inbox()).entries.map(e => `#/inbox/${e.id}`), '#/inbox/draft',
      // v3.4.0 (N1019) — Handbuch: Index, Sprung in einen Abschnitt, Seite mit Bildern, Datei im Wurzelverzeichnis
      '#/help/doc/README.md', '#/help/doc/anleitungen/anwendungsfaelle.md?h=case-heat',
      '#/help/doc/en/referenz/ansichten.md', '#/help/doc/root/CHANGELOG.md',
      '#/settings', '#/settings/region', '#/settings/display', '#/household', '#/household/people', '#/household/building', '#/household/utilities', '#/settings/data', '#/settings/calculation',
      '#/connections', '#/connections/reading', '#/connections/homeassistant', '#/connections/homeassistant/meters', '#/connections/homeassistant/notifications', '#/connections/homeassistant/templates', '#/connections/weather', '#/connections/evcc', '#/connections/calendar', '#/connections/ai', '#/connections/ai/connect', '#/connections/keys', '#/connections/homenet', '#/settings/access', '#/settings/expert', '#/settings/system'];
    const before = errors.length;
    for (const r of routes) {
      w.location.hash = r;
      await settle();
      const view = w.document.getElementById('view');
      const broken = view.querySelector('.infobox--fehler')?.textContent?.trim();
      if (broken) errors.push(`${r}: ${broken.slice(0, 160)}`);
      if (r === '#/dashboard') {
        t('Schaufenster: keine Kachel „Dein Hebel“ (ohne Viertelstunden)', !view.querySelector('[data-role="flex-lever"]'));
      }
      if (r === '#/bill-check') {
        view.querySelector('#bc-run')?.click();
        await settle();
        t('Rechnungsprüfung mit Vorbelegung rechnet', !!view.querySelector('#bc-result table'));
      }
      // v3.6.0 (F1031) — Prognose-Werkstatt: Ausgangsfall und Beispielszenarien
      // vorgerechnet; ein eigener Reglerwert sagt freundlich „nur Beispiele“
      if (r === '#/forecast') {
        t('Werkstatt in der Demo: Ergebniskarte zum Ausgangsfall', !!view.querySelector('#ws-card .ws-card__big'));
        const chips = [...view.querySelectorAll('#ws-presets [data-preset]')].filter(b => b.dataset.preset);
        t('Werkstatt in der Demo: Beispielszenarien angeboten', chips.length >= 2, chips.map(b => b.dataset.preset).join(','));
        const before = view.querySelector('#ws-card')?.textContent;
        chips[0]?.click();
        await settle(); await sleep(150);
        t('Werkstatt in der Demo: Beispielszenario rechnet (vorgerechnet)', !view.querySelector('#ws-banner .infobox')
          && view.querySelector('#ws-card')?.textContent !== before, view.querySelector('#ws-banner')?.textContent || '');
        const slider = view.querySelector('#ws-saving');
        slider.value = '7';
        slider.dispatchEvent(new w.Event('input', { bubbles: true }));
        await sleep(400); await settle();
        t('Werkstatt in der Demo: eigener Wert → Hinweis statt Fehler', /Beispielszenarien/.test(view.querySelector('#ws-banner')?.textContent || ''));
        for (const k of [...(w.__etDemoMisses || [])]) if (k.startsWith('POST /api/forecast/scenario') && k.includes('"saving_pct":7')) w.__etDemoMisses.delete(k);
        // v3.12.0 (F1040) — jede Art zeigt ihre eigenen Beispiele, auch in der Demo
        view.querySelector('#ws-presets [data-preset=""]')?.click();
        await settle(); await sleep(150);
        const pick = view.querySelector('#util-select');
        if ([...pick.options].some(o => o.value === 'wasser')) {
          pick.value = 'wasser';
          pick.dispatchEvent(new w.Event('change', { bubbles: true }));
          await sleep(400); await settle();
          const ids = [...view.querySelectorAll('#ws-presets [data-preset]')].map(b => b.dataset.preset).filter(Boolean);
          t('Werkstatt in der Demo: Wasser mit eigenen Beispielen', ids.includes('shower') && !ids.includes('cold_winter'), ids.join(','));
          view.querySelector('#ws-presets [data-preset="shower"]')?.click();
          await settle(); await sleep(150);
          t('Werkstatt in der Demo: Sparbrause rechnet (vorgerechnet)', !view.querySelector('#ws-banner .infobox'), view.querySelector('#ws-banner')?.textContent || '');
          t('Werkstatt in der Demo: Wasser sagt, warum es keinen Tarif gibt', !view.querySelector('#ws-tariff-none').hidden && view.querySelector('#ws-tariff').closest('[data-cap]').hidden);
        }
        // v3.12.0 (F1040, Phase 2) — Heizöl, Pellets und PV rechnet die Werkstatt jetzt, auch in der Demo vorgerechnet
        for (const [u, preset, want] of [['heizoel', 'heat_pump', /Wärmepumpe/], ['pellets', 'fuel_price_up', /Preis/], ['pv_erzeugung', 'battery', /Speicher/]]) {
          if (![...pick.options].some(o => o.value === u)) continue;
          // erst zurücksetzen: ein Beispiel der vorigen Art (Sparbrause) ist in der Demo für diese Art nicht vorgerechnet
          view.querySelector('#ws-presets [data-preset=""]')?.click();
          await settle(); await sleep(150);
          pick.value = u;
          pick.dispatchEvent(new w.Event('change', { bubbles: true }));
          await sleep(400); await settle();
          const ids = [...view.querySelectorAll('#ws-presets [data-preset]')].map(b => b.dataset.preset).filter(Boolean);
          t(`Werkstatt in der Demo: ${u} mit eigenen Beispielen`, ids.includes(preset), ids.join(','));
          view.querySelector(`#ws-presets [data-preset="${preset}"]`)?.click();
          await settle(); await sleep(150);
          t(`Werkstatt in der Demo: ${u}/${preset} rechnet (vorgerechnet)`, !view.querySelector('#ws-banner .infobox') && want.test(view.querySelector('#ws-card')?.textContent || ''),
            (view.querySelector('#ws-banner')?.textContent || '') + (view.querySelector('#ws-card')?.textContent || '').slice(0, 120));
          view.querySelector('#ws-presets [data-preset=""]')?.click();
          await settle(); await sleep(150);
        }
      }
      // v3.7.0 — In der Demo liest niemand aus: vorbereitete Auslesung, Übernehmen gesperrt, das PDF als Datei
      // v3.7.0 (Haushalt aus Dokumenten) — der Entwurf aus der vorbereiteten Auslesung, Übernehmen gesperrt
      if (r === '#/inbox/draft') {
        t('Posteingang in der Demo: Entwurf über die Dokumente, Übernehmen gesperrt', !!view.querySelector('#draft-summary')
          && view.querySelectorAll('.draft-item').length > 0 && (!view.querySelector('#draft-apply') || view.querySelector('#draft-apply').disabled)
          && [...view.querySelectorAll('input[data-pick]')].every(c => c.disabled));
      } else if (r.startsWith('#/inbox/')) {
        t('Posteingang in der Demo: Vorschau mit vorbereiteter Auslesung und Vorschlägen', !!view.querySelector('#inbox-fields .inbox-field--check')
          && view.querySelectorAll('#inbox-proposals input[data-proposal]').length > 3 && !!view.querySelector('#inbox-apply[disabled]')
          && !view.querySelector('#inbox-read'));
        const href = view.querySelector('#inbox-doc a')?.getAttribute('href') || '';
        const pdfRes = href ? await browserFetch(href) : null;
        const head = pdfRes?.ok ? Buffer.from(await pdfRes.arrayBuffer()).subarray(0, 5).toString() : '';
        t('Posteingang in der Demo: das Beispieldokument liegt als Datei bereit', head === '%PDF-', href);
      }
      // v3.10.0 — Danke sagen: nie in der Demo, auch nicht beim fertigen Jahresbericht
      if (r === '#/report/print') {
        await sleep(150);
        w.dispatchEvent(new w.Event('afterprint'));   // Skeptiker N11: der Moment ist „gedruckt“
        await sleep(150);
        t('Danke-Hinweis: nie in der Demo', !!view.querySelector('.report-print') && !w.document.querySelector('.thanks-note'));
      }
      // v3.12.0 (F1037) — die neue Hauptfunktion ist sichtbar und sagt, dass hier nichts verbindet
      if (r === '#/connections/ai') {
        const card = view.querySelector('#mcp-card');
        t('KI-Assistenten in der Demo: Karte da, ehrlicher Hinweis, aus', !!card && !!card.querySelector('[data-role="mcp-demo"]')
          && card.querySelector('[data-role="mcp-state"]')?.dataset.state === 'off', card?.querySelector('[data-role="mcp-state"]')?.dataset.state || 'keine Karte');
        t('KI-Assistenten in der Demo: Anleitung und Vorlagen', !!card?.querySelector('[data-role="mcp-guide"]')
          && !!view.querySelector('a[href="#/connections/ai/connect"]') && !!view.querySelector('a[href="#/todo/history/ai"]'));
      }
      if (r === '#/settings/system') {
        const link = view.querySelector('[data-source-link]')?.getAttribute('href') || '';
        t('System: Link auf den Quellcode dieser Version', link.endsWith(`/tree/v${meta.version}`), link);
      }
      // v3.4.0 (N1019) — das Handbuch liest sich auch in der Demo
      if (r === '#/help/setups/local-ai') {
        t('Wegweiser in der Demo: Weg mit Hausgrenze und „Im Detail“', !!view.querySelector('.ill-boundary') && !!view.querySelector('details[data-role="detail"]'));
      }
      if (r === '#/help/setups') {
        t('Wegweiser in der Demo: Kurzauswahl und alle Wege', view.querySelectorAll('fieldset.guide-q').length === 3 && view.querySelectorAll('.guide-tile').length === 6);
      }
      if (r === '#/help/doc/einstieg/wegweiser.md') {
        const src = view.querySelector('.doc-body picture img')?.getAttribute('src') || '';
        t('Wegweiser in der Demo: Doku-Seite mit Schaubild aus dem Bau', /ui\/wegweiser\/by-hand\.de\.svg/.test(src) && statSync(join(out, 'demo-api/docs/ui/wegweiser/by-hand.de.svg')).size > 500, src);
      }
      if (r === '#/help/doc/README.md') {
        t('Handbuch in der Demo: Index mit Inhalt und Sprachumschalter', !!view.querySelector('.doc-body h1')
          && !!view.querySelector('.doc-langs a[href="#/help/doc/en/README.md"]'), w.document.title);
      }
      if (r.startsWith('#/help/doc/anleitungen/anwendungsfaelle.md')) {
        t('Handbuch in der Demo: Sprungmarke des Anwendungsfalls', !!view.querySelector('#d-case-heat'));
      }
      if (r === '#/help/doc/en/referenz/ansichten.md') {
        const srcs = [...view.querySelectorAll('.doc-body img')].map(i => i.getAttribute('src'));
        const first = srcs[0] ? await browserFetch(srcs[0]) : null;
        const png = first?.ok ? Buffer.from(await first.arrayBuffer()).subarray(1, 4).toString() : '';
        t('Handbuch in der Demo: Bilder als fertige Dateien', srcs.length > 5
          && srcs.every(s => s.startsWith('demo-api/docs/ui/screenshots/')) && png === 'PNG', `${srcs.length} Bilder, ${srcs[0]}`);
      }
    }
    // alter Pfad (Weiterleitung seit v2.14.0) → neue Seite
    w.location.hash = '#/help/doc/ERSTE-SCHRITTE.md';
    await settle();
    await settle();
    t('Handbuch in der Demo: alter Pfad führt zur neuen Seite', w.location.hash === '#/help/doc/einstieg/erste-schritte.md'
      && !!w.document.querySelector('#view .doc-body h1'), w.location.hash);
    t('Handbuch in der Demo: Seiten und Bilder abgelegt', meta.docs > 50 && meta.doc_images > 20, `${meta.docs} Seiten, ${meta.doc_images} Bilder`);
    const misses = [...(w.__etDemoMisses || [])];
    t(`${routes.length} Seiten ohne fehlende Antworten`, misses.length === 0, misses.slice(0, 8).join(' · '));
    const newErrors = errors.slice(before);
    t('keine Fehler beim Durchklicken', newErrors.length === 0, newErrors.slice(0, 5).join(' | '));

    // v3.10.0 (Hauptfunktion „Saldo aus einem Guss“, #49) — im Schaufenster an jedem Tag sichtbar: Gas wird
    // höchstens bis zwölf Tage vor dem Bautag abgelesen; die Saldo-Karte zeigt den gemessenen Saldo mit Beginn
    // und Ende und die Schätzung bis heute als Zusatzzeile (vorberechnete Antworten)
    {
      w.location.hash = '#/utility/gas?meter=m_gas_main';
      await settle();
      const view = w.document.getElementById('view');
      const span = view.querySelector('[data-role="balance-span"]')?.textContent || '';
      const est = view.querySelector('[data-role="balance-estimate-today"]')?.textContent || '';
      t('Schaufenster: Saldo-Karte Gas trennt Gemessenes (bis zur letzten Ablesung) und Geschätztes (bis heute)',
        /bis zur letzten Ablesung am/.test(span) && /^Bis heute geschätzt:/.test(est.trim()) && !!view.querySelector('[data-role="balance-current"]'),
        `${span} | ${est.trim().slice(0, 120)}`);
    }

    // v3.9.0 (F1032) — Messkonzept im Schaufenster: Wärmepumpe mit Doppeltarifzähler hinter dem
    // Hauptzähler. Rest-Hinweis und Spalte „Abgerechnet“ am Hauptzähler, Zählwerke an den Zählern,
    // der Assistent bis zur Zusammenfassung (Vorschau vorberechnet), „Anlegen“ gesperrt.
    {
      const missesBefore = new Set(w.__etDemoMisses || []);
      w.location.hash = '#/utility/strom?meter=m_strom_main';
      await settle();
      const view = w.document.getElementById('view');
      const note = view.querySelector('[data-role="rest-note"]')?.textContent || '';
      t('Schaufenster: Hauptzähler Strom rechnet nur den Rest („… gemessen − … Wärmepumpe HT − … Wärmepumpe NT“)',
        /gemessen − /.test(note) && /Wärmepumpe HT/.test(note) && /Wärmepumpe NT/.test(note), note.slice(0, 200));
      t('Schaufenster: Kachel und Monatstabelle nennen die abgerechnete Menge', !!view.querySelector('[data-role="kpi-billed"]')
        && !!view.querySelector('th[data-role="col-billed"]'));
      w.location.hash = '#/household/meters?art=strom';
      await settle();
      const mv = w.document.getElementById('view');
      t('Schaufenster: Zählwerke mit OBIS an den Zählern (1.8.0, 1.8.1, 1.8.2)', ['1.8.0', '1.8.1', '1.8.2'].every(o => mv.textContent.includes(o)));
      const btn = mv.querySelector('[data-action="concept"]');
      t('Demo: Zählerseite bietet „Messkonzept einrichten“', !!btn);
      btn?.click();
      await settle(); await sleep(200);
      const mq = (s) => w.document.querySelector(`#modal-root ${s}`);
      const step = () => mq('[data-role="concept-body"]')?.dataset.step;
      const next = async () => { mq('[data-act="next"]')?.click(); await settle(); await sleep(150); };
      t('Assistent in der Demo: Aufbau aus dem Schaufenster vorbelegt (PV, Wärmepumpe, HT/NT am Gerät)', step() === 'setup' && mq('input[name="cw-pv"]')?.checked
        && mq('input[name="cw-device"]:checked')?.value === 'heat_pump' && mq('input[name="cw-tou"]:checked')?.value === 'device');
      await next();
      t('Assistent in der Demo: „Subzähler dahinter“ vorgewählt', step() === 'layout' && mq('input[name="cw-concept"]:checked')?.value === 'behind');
      await next();
      const ex = [...w.document.querySelectorAll('#modal-root select[data-field="existing"]')].map(s => `${s.dataset.slot}/${s.dataset.reg}=${s.value}`);
      t('Assistent in der Demo: Zähler (vorberechnete Vorschau), vorhandene vorgewählt', step() === 'meters'
        && ex.includes('main/one=m_strom_main') && ex.includes('dev/ht=m_strom_wp_ht') && ex.includes('dev/nt=m_strom_wp_nt'), ex.join(' '));
      await next();
      t('Assistent in der Demo: Verträge zeigen die laufenden statt leerer Felder', step() === 'contracts'
        && w.document.querySelectorAll('#modal-root [data-role="cw-running-contract"]').length >= 2);
      await next();
      const ops = [...w.document.querySelectorAll('#modal-root [data-role="cw-actions"] li')].map(li => li.dataset.op);
      t('Assistent in der Demo: Zusammenfassung mit Schaltbild, nichts doppelt', step() === 'summary' && !!mq('.concept-summary__art svg[role="img"]')
        && ops.includes('update_meter') && !ops.includes('create_meter'), ops.join(','));
      mq('[data-act="next"]')?.click();   // „Anlegen“
      await settle(); await sleep(200);
      const toasts = w.document.getElementById('toast-stack')?.textContent || '';
      t('Assistent in der Demo: „Anlegen“ gesperrt (nichts gespeichert)', !!mq('[data-role="concept-body"]') && /nichts gespeichert/.test(toasts), toasts.trim().slice(0, 160));
      mq('.modal__close')?.click();
      await settle();
      // v3.9.0 (Gruppenverträge) — „Kosten & Verträge“ und die Verbrauchsseite der Wärmepumpe zeigen den Gruppenvertrag
      w.location.hash = '#/contracts';
      await settle(); await settle();
      const grp = w.document.querySelector('#view .contract-overview__row[data-group="g_strom_wp"]');
      t('Demo: „Kosten & Verträge“ — Wärmepumpe HT/NT über den Gruppenvertrag, kein „Vertrag anlegen“', !!grp && /Über die Gruppe/.test(grp.textContent)
        && /Wärmepumpe Duo/.test(grp.textContent) && !/Kein laufender Vertrag/.test(w.document.querySelector('#view .contract-overview[data-utility="strom"]')?.textContent || ''),
        grp?.textContent.replace(/\s+/g, ' ').trim().slice(0, 160));
      w.location.hash = '#/utility/strom?meter=m_strom_wp_ht';
      await settle(); await settle();
      t('Demo: Verbrauchsseite der Wärmepumpe mit der Saldo-Karte des Gruppenvertrags', /Gruppenvertrag „Wärmepumpe \(HT\/NT\)“/.test(w.document.getElementById('view')?.textContent || '')
        && !!w.document.querySelector('#view .balance-verdict__value'));
      const newMisses = [...(w.__etDemoMisses || [])].filter(k => !missesBefore.has(k));
      t('Assistent in der Demo: jede Vorschau vorberechnet', newMisses.length === 0 && (meta.concepts || 0) > 0, newMisses.slice(0, 3).join(' · ') || `${meta.concepts} Vorschauen`);

      // v3.11.0 (F1035, F1036, Paket doku) — „Deine Rechte als Kunde“ im Schaufenster: die Sonderkündigung liegt
      // immer zwölf Tage voraus (demo_align), die Empfehlung zeigt die Norm als Link und „Brief entwerfen“;
      // der erste Entwurf des Briefs ist vorberechnet (letters-<lang>.json), nichts fehlt
      const missesLetter = new Set(w.__etDemoMisses || []);
      w.location.hash = '#/todo?art=rec';
      await settle(); await settle();
      const rc = w.document.querySelector('#view [data-role="rights-card"][data-check="special_termination"]');
      t('Demo: Empfehlung „Rechte“ mit Norm als Link und „Brief entwerfen“', !!rc && !!rc.querySelector('a[data-role="rights-norm"]')
        && !!rc.querySelector('[data-role="letter-button"]') && !!w.document.querySelector('#view [data-role="rights-card"][data-check="fee_notice_deadline"]'),
        rc?.textContent.replace(/\s+/g, ' ').trim().slice(0, 160));
      rc?.querySelector('[data-role="letter-button"]')?.click();
      await settle(); await sleep(300); await settle();
      const pv = mq('[data-role="letter-preview"]')?.textContent || '';
      const lm = [...(w.__etDemoMisses || [])].filter(k => !missesLetter.has(k));
      t('Demo: Brief „Sonderkündigung“ vorberechnet', /Kündigung wegen Preisänderung/.test(pv) && lm.length === 0 && (meta.letters || 0) > 0,
        lm.slice(0, 2).join(' · ') || pv.replace(/\s+/g, ' ').slice(0, 120));
      mq('[data-act="close"]')?.click();
      await settle();

      // v3.12.0 — Sprungmarke: Die Rechte-Karte aus dem Schaufenster, hervorgehoben; Rechte stehen in ihrer Stufe vorn
      {
        const id = rc?.dataset.recId;
        const allRecs = await api.recommendations();
        const order = allRecs.map(r => `${r.severity}|${r.category === 'recht' ? 'recht' : 'sonst'}`);
        const inOrder = ['urgent', 'warning', 'info'].every(sev => {
          const inSev = order.filter(o => o.startsWith(sev + '|'));
          return inSev.join() === [...inSev].sort().join();
        });
        t('Demo: In jeder Stufe stehen die Rechte-Hinweise vorn', inOrder && order.some(o => o.endsWith('|recht')), order.join(' '));
        w.location.hash = `#/todo?art=rec&rec=${encodeURIComponent(id)}`;
        await settle(); await sleep(50);
        const tg = w.document.querySelector('#view [data-rec-target]');
        t('Demo: Sprungmarke hebt den Hinweis hervor', tg?.dataset.recId === id && tg.classList.contains('rec-card--target'));
        const seg = w.document.querySelector('#view .seg__btn[data-art="rights"]');
        t('Demo: Filter „Rechte“ mit Zahl', /^Rechte \d+$/.test(seg?.textContent.trim() || ''), seg?.textContent);

        // v3.12.0 (Mehrwert) — „Neu in vX.Y“: Block offen, und gesprungen wird nach dem Router (der scrollt nach oben)
        {
          const seen = [];
          const realScroll = w.scrollTo, realInto = w.Element.prototype.scrollIntoView;
          w.scrollTo = () => seen.push('oben');
          w.Element.prototype.scrollIntoView = function () { seen.push(this.id || this.tagName); };
          w.location.hash = '#/settings/integrations?mcp=value';   // alte Adresse: die Weiterleitung nimmt die Sprungmarke mit
          await settle(); await sleep(50);
          const value = w.document.querySelector('#view #mcp-value');
          t('Demo: „Neu in“ öffnet „Was bringt dir das?“ und springt danach dorthin', value?.open === true && seen.at(-1) === 'mcp-value', seen.join(' '));
          w.scrollTo = realScroll; w.Element.prototype.scrollIntoView = realInto;
        }

        // „Zu tun“ führt zum Hinweis; die Agenda trägt die Sprungmarke vom Server
        const due =((await api.agenda(90)).events ?? []).filter(e => e.kind === 'rights' && e.due_now);
        t('Demo: „Zu tun“ führt einen Rechte-Eintrag zur Sprungmarke', due.length > 0 && due.every(e => !e.ref?.recommendation_id || e.href === `#/todo?art=rec&rec=${encodeURIComponent(e.uid)}`)
          && due.some(e => e.ref?.recommendation_id), due.map(e => e.href).join(' '));
        w.location.hash = '#/todo';   // v3.12.0 (M4) — die ganze Liste; die Übersicht zeigt vier Zeilen
        await settle(); await settle();
        const todoLink = [...w.document.querySelectorAll('#view .dash-todo a')].find(x => x.getAttribute('href') === `#/todo?art=rec&rec=${encodeURIComponent(id)}`);
        t('Demo: Die Zeile unter „Zu tun“ trägt die Sprungmarke', !!todoLink, todoLink?.textContent.replace(/\s+/g, ' ').trim() || [...w.document.querySelectorAll('#view .dash-todo a')].map(x => x.getAttribute('href')).join(' '));

        // Vertragskarte: Auswahl, Sonderkündigung führt zum Hinweis, Kündigung vorberechnet
        const missesCard = new Set(w.__etDemoMisses || []);
        w.location.hash = '#/utility/strom/contracts';
        await settle(); await settle();
        const cards = [...w.document.querySelectorAll('#view .contract-card')];
        const withLetter = cards.filter(c => c.querySelector('[data-role="contract-letter"]'));
        t('Demo: Vertragskarte mit „Brief schreiben“', withLetter.length > 0 && withLetter[0].querySelector('[data-role="contract-letter"]').textContent.includes('Brief schreiben'), `${withLetter.length} von ${cards.length}`);
        withLetter[0]?.querySelector('[data-role="contract-letter"]')?.click();
        await settle(); await sleep(200); await settle();
        t('Demo: Auswahl am Vertrag bietet die Kündigung, nie die Sonderkündigung ohne Hinweis', !!mq('[data-role="contract-letter-menu"] [data-kind="termination"]') && !mq('[data-kind="special_termination"]'));
        mq('[data-kind="termination"]')?.click();
        await settle(); await sleep(300); await settle();
        const pvCard = [...w.document.querySelectorAll('#modal-root [data-role="letter-preview"]')].pop()?.textContent || '';
        const cm = [...(w.__etDemoMisses || [])].filter(k => !missesCard.has(k));
        t('Demo: Kündigung am Vertrag vorberechnet', /Kündigung zum nächstmöglichen Termin/.test(pvCard) && !/§/.test(pvCard) && cm.length === 0, cm.slice(0, 2).join(' · ') || pvCard.replace(/\s+/g, ' ').slice(0, 120));
        // v3.12.0 (m4b) — Schaufenster mit erfundenem Absender, Anschrift des Anbieters und Nummern: keine Lücke
        // (Kopf bis zum Betreff: Absender, Empfänger, Angaben zur Zuordnung; der Termin hängt am Kalender und bleibt außen vor)
        const kopf = pvCard.split('\nKündigung')[0];
        t('Demo: Kündigung ohne Lücke im Kopf, Absender, Anschrift und Nummern vorbelegt', !kopf.includes('[…]') && kopf.includes('Anna und Paul Muster') && /Musterweg 12/.test(kopf)
          && /Kundennummer: K-\d+/.test(kopf) && /Vertragsnummer: V-/.test(kopf) && /Lieferanschrift: Musterweg 12/.test(kopf), kopf.replace(/\s+/g, ' ').slice(0, 240));
        for (const b of [...w.document.querySelectorAll('#modal-root [data-act="close"]')].reverse()) { b.click(); await sleep(20); }
        await settle();
      }
    }

    // v3.12.0 — meta.json am Cache vorbei, jeder andere Abruf unter demo-api/ mit `?b=<built_at>`; `demoKey` und die
    // Abdeckung oben („ohne fehlende Antworten“) sehen die Marke nicht
    {
      const stamp = `b=${encodeURIComponent(meta.built_at)}`;
      const unmarked = demoApiCalls.filter(([u]) => !u.includes(stamp) && !u.endsWith('/demo-api/meta.json')).map(([u]) => u);
      t('Alle Abrufe unter demo-api/ mit der Marke des Baus', demoApiCalls.length > 100 && unmarked.length === 0, unmarked.slice(0, 3).join(' · ') || `${demoApiCalls.length} Abrufe`);
      const metaCalls = demoApiCalls.filter(([u]) => u.endsWith('/demo-api/meta.json'));
      t('meta.json ohne Cache', metaCalls.length === 1 && metaCalls[0][1]?.cache === 'no-cache', `${metaCalls.length}`);
      const { demoKey, demoFileName } = await import(pathToFileURL(join(out, 'public/js/lib/demo-key.js')).href);
      t('Schlüssel der Anfragen kennen die Marke nicht', demoKey('/api/rights') === '/api/rights' && !demoFileName('/api/reports/yearly.pdf?year=2025').includes(stamp));
      const fileUrl = api.yearlyReportUrl(year - 1, { inline: true });
      t('Datei-Adressen mit der Marke', fileUrl.includes(stamp) && (await browserFetch(fileUrl)).ok, fileUrl);
    }

    // v3.2.0 (F1018) — der Einrichtungsassistent bietet die Beispielhaushalte an
    const { openSetupWizard } = await import(pathToFileURL(join(out, 'public/js/components/setup-wizard.js')).href);
    await openSetupWizard({ firstRun: true });
    await settle();
    // v3.5.0 (N1022) — zuerst Sprache und Land; das Land ist das des Beispielhaushalts
    // v3.5.1 — das Land steht in der Demo als Text mit Hinweis, kein gesperrtes Feld
    t('Einrichtungsassistent: Schritt 1 Sprache und Land, Land in der Demo als Text',
      w.document.querySelector('.modal #setup-lang')?.options.length === langs.length
      && w.document.querySelector('.modal #setup-country')?.tagName === 'P'
      && !w.document.querySelector('.modal select#setup-country')
      && !!w.document.querySelector('.modal #setup-country-demo')
      && !!w.document.querySelector('.modal [data-fact="format"]'));
    w.document.querySelector('.modal [data-act="next"]')?.click();
    await settle();
    const choices = w.document.querySelectorAll('.modal input[name="setup-persona"]').length;
    t('Einrichtungsassistent mit fünf Personas', choices === 5, String(choices));
    w.document.querySelector('.modal .modal__close')?.click();

    // v3.2.0 (F1018, F1019) — jeder Beispielhaushalt, als Einsteiger und als Experte
    const state = await import(pathToFileURL(join(out, 'public/js/state.js')).href);
    const levels = await import(pathToFileURL(join(out, 'public/js/lib/levels.js')).href);
    for (const persona of (meta.personas || []).filter(x => x !== 'showcase')) {
      w.localStorage.setItem('et-demo-persona', persona);
      state.invalidateSettings(); state.invalidateUtilities();
      w.__etDemoMisses?.clear();
      const pErrors = errors.length;
      const pSettings = await api.settings();
      const pUtils = (await api.listUtilities()).filter(u => (pSettings.active_utilities || []).includes(u.key));
      const pRoutes = ['#/dashboard', '#/zaehlerstaende', ...pUtils.map(u => `#/utility/${u.key}`), '#/contracts',
        '#/tariffs', '#/bill-check', '#/report', `#/flexibility?year=${new Date().getFullYear() - 1}`, ...(pSettings.wohnverhaeltnis === 'miete' ? ['#/tenancy'] : [])];
      const leverTiles = [];
      for (const level of ['beginner', 'expert']) {
        w.localStorage.setItem('et-demo-level', level);
        levels.applyLevel(level);
        for (const r of pRoutes) {
          w.location.hash = r + (r.includes('?') ? '&' : '?') + 'p=' + persona + level;
          await settle();
          const broken = w.document.getElementById('view').querySelector('.infobox--fehler')?.textContent?.trim();
          if (broken) errors.push(`${persona} ${r}: ${broken.slice(0, 160)}`);
          // v3.6.0 (Paket flexplus) — Kachel „Dein Hebel“ (nachgeladen, vorgerechnet)
          if (r === '#/dashboard') leverTiles.push(w.document.querySelector('#view [data-role="flex-lever"] .dash-lever__big')?.textContent || '');
        }
      }
      // nur der Haushalt mit Viertelstunden zeigt die Kachel, in beiden Stufen
      if (persona === 'eigenheim-modern') {
        t('Beispielhaushalt eigenheim-modern: Kachel „Dein Hebel“ auf der Übersicht (Einsteiger und Experte)',
          leverTiles.length === 2 && leverTiles.every(x => /\d\s?€/.test(x)), leverTiles.join(' · '));
      } else if (leverTiles.some(Boolean)) {
        errors.push(`${persona}: Kachel „Dein Hebel“ ohne Viertelstunden`);
      }
      const pMisses = [...(w.__etDemoMisses || [])];
      t(`Beispielhaushalt ${persona}: ${pRoutes.length} Seiten × 2 Stufen ohne Lücke`,
        pMisses.length === 0 && errors.length === pErrors, [...pMisses.slice(0, 5), ...errors.slice(pErrors, pErrors + 3)].join(' · '));
    }
    w.localStorage.setItem('et-demo-persona', 'showcase');
    state.invalidateSettings(); state.invalidateUtilities();

    let code = null;
    try { await api.createReading('gas', { meter_id: 'x', date: '2026-01-01', counter: 1 }); }
    catch (e) { code = e.code; }
    t('Schreiben wird abgelehnt: „nichts gespeichert“', code === 'demo.readOnly', String(code));
    const csv = await (await browserFetch(api.exportMonthlyCsvUrl('gas'))).text();
    t('CSV-Export als Datei', csv.split('\n').length > 5, csv.split('\n')[0].slice(0, 60));
    const pdf = await browserFetch(api.yearlyReportUrl(year - 1, { inline: true }));
    t('Jahresbericht als PDF', pdf.ok && (await pdf.arrayBuffer()).byteLength > 1000);
    console.error = origError;
  } catch (e) {
    t('Demo-Test', false, e.stack || e.message);
  } finally {
    server?.close();
    rmSync(out, { recursive: true, force: true });
    console.log(`\n${pass} ok, ${fail} Fehler`);
    process.exit(fail ? 1 : 0);
  }
})();
