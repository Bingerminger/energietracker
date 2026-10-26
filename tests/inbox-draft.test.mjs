// =====================================================================
// v3.7.0 (Haushalt aus Dokumenten) — der Entwurf in der Oberfläche.
//
//   node tests/inbox-draft.test.mjs
//
// Teil A ohne Server: Texte, Werte und die Anfrage fürs Übernehmen
// (lib/inbox-draft.js), welche Dokumente im Assistenten helfen.
//
// Teil B mit dem echten Server und einem erfundenen Stapel (Mieter nur Strom,
// Auslesungen wie gespeichert — tests/fixtures/draft-seed.php, ohne Modell):
// die echte App (app.js) in JSDOM, Stufe „Einsteiger“:
//   - Posteingang: Knopf zum Entwurf, sobald etwas ausgelesen ist
//   - Entwurf: Zusammenfassung, ein Haken je Feldgruppe, nur Zweifelhaftes
//     fragt (übernehmen, Entscheidung), Belegstelle auf Antippen, „Fehlt noch“
//     freundlich, beschriftete Bedienelemente
//   - Übernehmen: was entstanden ist, „Rechnung prüfen“, Sicherung; Dokumente
//     erledigt; danach ist der Entwurf leer
//   - Einrichtungsassistent: drei Wege, „Aus Dokumenten“ vorgewählt, ohne
//     Dienst zuerst zur Karte „Auslesen“
//   - Doku: Anleitung DE/EN nennt die Wege mit den Texten des Katalogs
//
// Port: ET_DRAFT_PORT (sonst ein freier).
// =====================================================================
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const JS = (p) => pathToFileURL(join(ROOT, 'public/js', p)).href;
const { JSDOM } = require('jsdom');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const read = (rel) => readFileSync(resolve(ROOT, rel), 'utf8');
const cat = (l) => JSON.parse(read(`public/locales/${l}.json`));

// ── Server und Daten ────────────────────────────────────────────────────
const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.once('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});
const dataDir = mkdtempSync(join(tmpdir(), 'et-draft-'));
writeFileSync(join(dataDir, 'settings.json'), JSON.stringify({ language: 'de', ui_level: 'beginner', setup_pending: false, weather_auto_fill: false,
  active_utilities: ['gas', 'strom', 'wasser'] }));
const port = Number(process.env.ET_DRAFT_PORT) || await freePort();
const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
  cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null' }, stdio: 'ignore',
});
const BASE = `http://127.0.0.1:${port}/`;
const nativeFetch = globalThis.fetch;

try {
  let up = false;
  for (let i = 0; i < 100 && !up; i++) {
    try { up = (await nativeFetch(BASE + 'api/health')).ok; } catch { await sleep(100); }
  }
  t('Testserver läuft', up);

  // ── JSDOM mit der echten Shell ──
  const html = await (await nativeFetch(BASE)).text();
  const dom = new JSDOM(html, { url: BASE + '#/dashboard', pretendToBeVisual: true });
  const w = dom.window;
  for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event',
    'getComputedStyle', 'localStorage', 'sessionStorage', 'MouseEvent', 'KeyboardEvent', 'requestAnimationFrame']) {
    try { global[k] = w[k]; } catch { Object.defineProperty(global, k, { value: w[k], configurable: true, writable: true }); }
  }
  Object.defineProperty(global, 'navigator', { value: w.navigator, configurable: true, writable: true });
  let inflight = 0;
  const browserFetch = async (input, init) => {
    if (typeof input === 'string' && !/^https?:\/\//i.test(input)) input = new URL(input, w.location.href).href;
    inflight++;
    try {
      const r = await nativeFetch(input, init);
      const buf = await r.arrayBuffer();
      const empty = [101, 204, 205, 304].includes(r.status) || (init?.method || 'GET').toUpperCase() === 'HEAD';
      return new Response(empty ? null : buf, { status: r.status, statusText: r.statusText, headers: r.headers });
    } finally { inflight--; }
  };
  w.fetch = browserFetch;
  global.fetch = browserFetch;
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.scrollTo = () => {};
  w.HTMLElement.prototype.scrollIntoView = function () {};
  const Chart = function (canvas, config) { return { canvas, config, destroy() {}, update() {}, resize() {} }; };
  Chart.defaults = { color: '', borderColor: '', font: { family: '', size: 12 }, plugins: { legend: { labels: {} }, tooltip: {} }, scale: { grid: {}, ticks: {} } };
  Chart.register = () => {};
  w.Chart = Chart; global.Chart = Chart;
  process.on('unhandledRejection', () => {});
  const settle = async () => {
    for (let i = 0; i < 200; i++) {
      await sleep(30);
      if (inflight === 0 && !w.document.querySelector('#view .loading')) { await sleep(80); if (inflight === 0) return; }
    }
  };
  const view = () => w.document.getElementById('view');
  const go = async (hash) => { w.location.hash = hash; await settle(); };
  const text = (el) => (el?.textContent || '').replace(/\s+/g, ' ').trim();
  const call = async (method, path, body) => {
    const r = await nativeFetch(BASE + path.replace(/^\//, ''), { method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined });
    return (await r.json())?.data;
  };

  // ── Teil A: Bausteine (Katalog geladen über die App) ──
  await import(JS('app.js'));
  await settle();
  const L = await import(JS('lib/inbox-draft.js'));
  const W = await import(JS('components/setup-wizard.js'));
  console.log('Texte und Auswahl');
  t('Zusammenfassung „… aus 3 Dokumenten. 1 Punkt braucht dich.“', L.summaryText({ documents: 3, meters: 1, contracts: 1, readings: 2, bills: 1, questions: 1 })
    === '1 Zähler, 1 Vertrag, 2 Ablesungen, 1 Rechnung aus 3 Dokumenten. 1 Punkt braucht dich.', L.summaryText({ documents: 3, meters: 1, contracts: 1, readings: 2, bills: 1, questions: 1 }));
  t('ohne Fragen: „Nichts braucht dich“', /Nichts braucht dich/.test(L.summaryText({ documents: 1, readings: 1, questions: 0 })));
  t('leer: der Weg in den Posteingang', /Noch kein Dokument ausgelesen/.test(L.summaryText({ documents: 0 })));
  const contract = { kind: 'contract', label_key: 'inboxDraft.item.contract.new', params: { provider: 'Nordlicht', from: '2025-04-01', to: null } };
  t('Eintrag: Vertrag ab … bis …', L.itemText(contract) === 'Vertrag Nordlicht 01.04.2025 bis …', L.itemText(contract));
  t('Feld: Arbeitspreis ab Datum, Wert in ct/kWh', L.fieldLabel({ kind: 'prices' }, { name: 'wp:2025-04-01' }) === 'Arbeitspreis ab 01.04.2025'
    && L.valueText({ kind: 'price_ct' }, 31.2) === '31,2 ct/kWh');
  t('Feld: Ablesung mit Einheit', L.valueText({ kind: 'number', name: 'r:2025-12-31' }, 3800, { unit: 'kWh' }) === '3.800 kWh');
  t('Feld: Ergebnis als Nachzahlung/Guthaben', /^Nachzahlung 40,00\s€$/.test(L.valueText({ kind: 'money', name: 'result' }, 40)));
  t('Gründe des Entwurfs und der Belegprüfung', L.reasonText(['differs', 'quote_not_found']) === 'Die Dokumente sagen Verschiedenes · Belegstelle steht nicht im Dokument');
  t('Herkunft: Beginn aus dem Lieferbeginn-Brief', L.basisText({ name: 'start', basis: 'supply_start' }) === 'aus dem Lieferbeginn-Brief');
  t('zu bestätigen: zweifelhaft, kein Kernfeld, keine Entscheidung', L.needsConfirm({ check: true, status: 'new' })
    && !L.needsConfirm({ check: true, core: true, status: 'new' }) && L.needsConfirm({ check: true, core: true, status: 'conflict' })
    && !L.needsConfirm({ check: true, status: 'new', options: [{}, {}] }) && !L.needsConfirm({ check: true, status: 'exists' }));
  const sel = L.initialSelection({ items: [{ id: 'a', checked: true, actionable: true }, { id: 'b', checked: false, actionable: true }, { id: 'c', checked: true, actionable: false }] });
  sel.confirm.set('a', new Set(['consumption'])); sel.choose.set('a', new Map([['tariff_name', 1]]));
  t('Anfrage: Auswahl, Bestätigungen, Entscheidungen', JSON.stringify(L.applyPayload(sel)) === '{"items":["a"],"confirm":{"a":["consumption"]},"choose":{"a":{"tariff_name":1}}}',
    JSON.stringify(L.applyPayload(sel)));
  t('„Rechnung prüfen“ mit Zähler und Zeitraum', L.billCheckHref({ utility: 'strom', meter_id: 'm1', period_from: '2025-04-01', period_to: '2025-12-31' })
    === '#/bill-check?utility=strom&meter=m1&from=2025-04-01&until=2025-12-31');
  t('Assistent: zur Miete Rechnung und Bestätigung', W.docsHelp('mieterin', ['strom']).join() === 'setup.finish.docsHelp.tenant');
  t('Assistent: Eigenheim mit PV und Wärmepumpe', W.docsHelp('eigenheim-modern', ['strom', 'pv_einspeisung']).join()
    === 'setup.finish.docsHelp.house,setup.finish.docsHelp.pv,setup.finish.docsHelp.heatPump');
  t('Missing: Verbrauchsart vorn, Versorger im Satz', L.missingText({ kind: 'advance', params: { provider: 'Nordlicht' } }, 'Strom') === 'Strom: Abschlag für Nordlicht steht in keinem Dokument.');
  const openText = L.missingText({ kind: 'open', params: { name: 'a.pdf' }, open: [{ field: 'notice_period', reason: 'missing' }, { field: 'tariff_name', reason: 'unsure' }] }, 'Strom');
  t('Missing: offene Punkte eines Dokuments mit den Sätzen des Posteingangs (fehlt / unsicher)',
    openText === 'Strom: a.pdf – Fehlt im Dokument, später nachtragen: Kündigungsfrist · Unsicher gelesen, nicht übernommen: Tarif', openText);
  t('Abgleich: andere Schreibweise am selben Zähler zur selben Zeit wird gesagt', L.findingText({ kind: 'same_supplier', params: { name: 'B Energie', of: 'Stadtwerke B' } })
    === 'B Energie und Stadtwerke B: als derselbe Versorger behandelt – gleicher Zähler, gleiche Zeit, ein gemeinsamer Name.');
  // Nachbesserung nach Nutzentest 2 — Rechnungspreis als Befund, Preise unter „Fehlt noch“, vorgezogener Beginn, Korrektur
  const wpText = L.findingText({ kind: 'invoice_wp', params: { name: 'r.pdf', date: '2025-10-01', invoice: 26.22, chain: 31.2 } });
  t('Befund: abweichender Rechnungspreis mit beiden Werten, die Kette bleibt', wpText.startsWith('r.pdf: Arbeitspreis ab 01.10.2025 26,22 ct/kWh statt 31,2 ct/kWh') && /Übernommen wird die Kette/.test(wpText), wpText);
  t('Befund: Grundpreis in Euro', /10,84\s€ statt 12,90\s€ im Monat/.test(L.findingText({ kind: 'invoice_bp', params: { name: 'r.pdf', date: '2025-04-01', invoice: 10.84, chain: 12.9 } })));
  t('Fehlt noch: Preise nur auf Rechnungen', L.missingText({ kind: 'prices_unconfirmed', params: { provider: 'Nordlicht' } }, 'Strom')
    === 'Strom: Preise für Nordlicht stehen nur auf Rechnungen. Bestätige sie oben oder trag sie im Vertrag ein.');
  t('Fehlt noch: nur der Grundpreis', L.missingText({ kind: 'base_price', params: { provider: 'Nordlicht' } }, 'Gas') === 'Gas: Der Grundpreis für Nordlicht steht in keinem Dokument.');
  t('Herkunft: Beginn vorgezogen auf die erste Rechnung', /vor dem Beginn aus der Bestätigung/.test(L.basisText({ name: 'start', basis: 'invoice_earlier' })));
  t('Herkunft: neue Preiszeile wegen eines anderen Steuersatzes', /anderer Steuersatz laut Rechnung/.test(L.basisText({ name: 'wp:2025-07-01', basis: 'vat_changed' })));
  // Nachbesserung 3 — Abschnitt ohne Preis, Stände nach offenem Tausch, Kennung aus dem jüngsten Dokument
  t('Fehlt noch: Abschnitt ohne Arbeitspreis mit Datum', L.missingText({ kind: 'working_price_gap', params: { provider: 'N', from: '2024-04-01', to: '2025-03-31' } }, 'Strom')
    === 'Strom: Für N nennt vom 01.04.2024 bis 31.03.2025 kein Dokument einen Arbeitspreis.');
  t('Grund: Stand nach einem Tausch ohne Endstand', /erst den Tausch eintragen/.test(L.reasonText(['swap_open'])));
  t('Herkunft: Kennung aus dem jüngsten Dokument', /jüngsten Dokument/.test(L.basisText({ name: 'customer_number', hint: 'newest' })));
  // Nachbesserung 4 — Basis eines Nettopreises (eine Hilfe für Vorschau und Entwurf), Zweitarifzähler, Tausch zu prüfen
  const P = await import(JS('lib/price-basis.js'));
  t('Preisbasis: netto mit Satz = brutto, wie der Server rechnet', P.grossOf(0.3, 19) === 0.357
    && /^netto laut Rechnung, mit 19\s%\sUSt = 0,357\s€ brutto$/u.test(P.priceBasisText({ vatPct: 19, gross: 0.357 })), P.priceBasisText({ vatPct: 19, gross: 0.357 }));
  t('Entwurf: Basis am Preisfeld aus der Rechnung', /netto laut Rechnung, mit 19\s%\sUSt = 31,2018\sct\/kWh brutto/u.test(L.basisText({ name: 'wp:2025-01-01', kind: 'price_ct', value: 31.2018, net: { vat_pct: 19 } })),
    L.basisText({ name: 'wp:2025-01-01', kind: 'price_ct', value: 31.2018, net: { vat_pct: 19 } }));
  t('Fehlt noch: Zweitarifzähler', /zwei Zählwerke \(HT\/NT\)/.test(L.missingText({ kind: 'two_registers', params: { name: 'a.pdf', meter: 'X' } }, 'Strom')));
  t('Grund: Tausch passt nicht ganz', /passen nicht ganz zum Tausch/.test(L.reasonText(['swap_readings'])));
  // 3.7.1 — Entwurf auf einen bestehenden Haushalt
  t('Gründe: ergänzen und Überschneidung am Bestand', L.reasonText(['existing_update', 'existing_overlap'])
    === 'Ergänzt, was schon da ist – bitte prüfen · Überschneidet sich mit dem Bestand – bitte prüfen', L.reasonText(['existing_update', 'existing_overlap']));
  const choice = L.missingText({ kind: 'meter_choice', params: { meter: 'GAS-88', count: 1, meters: 'Gaszähler, Gaszähler Garage' } }, 'Gas');
  t('Fehlt noch: zu welchem Zähler gehört die Nummer', /^Gas: Zu welchem Zähler gehört GAS-88\? In Frage: Gaszähler, Gaszähler Garage\./.test(choice), choice);
  const near = L.findingText({ kind: 'start_near', params: { provider: 'Nordlicht', from: '2025-01-03', date: '2025-01-01' } });
  t('Befund: Beginn wenige Tage neben dem Bestand', /Beginn laut Dokument 03\.01\.2025, im Bestand 01\.01\.2025 – derselbe Vertrag/.test(near), near);
  t('Dokument: Zähler offen', L.docStateText('meter_choice') === 'Zähler offen – im Dokument wählen');
  t('Befunde: vorgezogener Beginn und Rechnungskorrektur', /Beginn auf den 01\.04\.2024 vorgezogen/.test(L.findingText({ kind: 'start_from_invoice', params: { provider: 'N', from: '2024-04-01', to: '2025-04-01' } }))
    && /b\.pdf ersetzt a\.pdf/.test(L.findingText({ kind: 'bill_corrected', params: { name: 'b.pdf', of: 'a.pdf' } })));

  // ── Teil B: ein erfundener Stapel ──
  console.log('Posteingang und Entwurf');
  const pdf = (tag) => `%PDF-1.4\n% draft ui ${tag}\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n`;
  for (const name of ['bestaetigung', 'lieferbeginn', 'rechnung']) {
    await nativeFetch(`${BASE}api/inbox?name=${name}.pdf`, { method: 'POST', headers: { 'Content-Type': 'application/pdf' }, body: pdf(name) });
  }
  const spec = [
    { name: 'bestaetigung.pdf', type: 'contract_confirmation', values: { utility: 'strom', supplier: 'Nordlicht Energie GmbH', tariff_name: 'Nordlicht Basis 12',
      working_price: '31,20', base_price: '12,90', base_price_period: 'month', contract_number: 'NL-4401', meter_number: 'STR-1001' } },
    { name: 'lieferbeginn.pdf', type: 'supply_start', values: { utility: 'strom', supplier: 'Nordlicht Energie', supply_start: '01.04.2025',
      meter_number: 'STR-1001', reading_start: '2000', contract_number: 'NL-4401' } },
    { name: 'rechnung.pdf', type: 'invoice', flag: ['consumption'], values: { utility: 'strom', supplier: 'Nordlicht Energie GmbH', kind: 'annual',
      period_from: '01.04.2025', period_to: '31.12.2025', consumption: '1800', reading_old: '2000', reading_old_date: '01.04.2025', reading_new: '3800',
      reading_new_date: '31.12.2025', total_amount: '640,00', advances_total: '600,00', result_kind: 'due', result_amount: '40,00',
      meter_number: 'STR-1001', contract_number: 'NL-4401',
      // Nachbesserung 4 — Grundpreis netto zzgl. 19 % (= 12,90 brutto wie in der Bestätigung)
      base_price: '10,84', base_price_period: 'month', base_price_basis: 'net', base_price_vat_pct: '19 %' } },
  ];
  const specFile = join(dataDir, 'seed.json');
  writeFileSync(specFile, JSON.stringify(spec));
  const seed = spawnSync('php', [join(ROOT, 'tests/fixtures/draft-seed.php'), dataDir, specFile], { encoding: 'utf8' });
  t('Auslesungen wie gespeichert (ohne Modell)', seed.status === 0, (seed.stderr || seed.stdout || '').trim());

  // Nachbesserung 4 — Korrigieren in der Vorschau: Basis und Ergebnis am Eingabefeld (lib/price-basis.js)
  const bill = (await call('GET', '/api/inbox')).entries.find(e => e.name === 'rechnung.pdf');
  await go(`#/inbox/${bill.id}`);
  for (let i = 0; i < 60 && !view().querySelector('[data-correct="base_price"]'); i++) await sleep(30);
  view().querySelector('[data-correct="base_price"]')?.click();
  const basisEl = () => view().querySelector('[data-field="base_price"] [data-role="basis"]');
  t('Vorschau: am Eingabefeld des Nettopreises Basis und Ergebnis', /netto laut Rechnung, mit 19\s%\sUSt = 12,8996\s€ brutto/u.test(text(basisEl())), text(basisEl()));
  const edit = view().querySelector('[data-field="base_price"] [data-role="edit"]');
  if (edit) { edit.value = '11'; edit.dispatchEvent(new w.Event('input', { bubbles: true })); }
  t('… und rechnet die Eingabe in derselben Basis um', /= 13,09\s€ brutto/u.test(text(basisEl())), text(basisEl()));

  await go('#/inbox?r=1');
  const entry = view().querySelector('#inbox-draft-entry a');
  t('Posteingang: Knopf zum Entwurf mit der Zahl der ausgelesenen Dokumente', entry?.getAttribute('href') === '#/inbox/draft'
    && /aus 3 ausgelesenen Dokumenten/.test(text(entry)), text(entry));

  await go('#/inbox/draft');
  const v = view();
  t('Entwurf: Überschrift und Titel', text(v.querySelector('h1')) === 'Haushalt aus Dokumenten' && /Haushalt aus Dokumenten/.test(w.document.title));
  t('Entwurf: für Einsteiger sichtbar (kein Hinweis auf eine höhere Stufe)', !v.querySelector('.level-hint, [data-role="level-hint"]'));
  t('Entwurf: Zusammenfassung', /1 Vertrag, 2 Ablesungen, 1 Rechnung aus 3 Dokumenten\. 1 Punkt braucht dich\./.test(text(v.querySelector('#draft-summary'))),
    text(v.querySelector('#draft-summary')));
  const strom = v.querySelector('.draft-utility[data-utility="strom"]');
  t('Entwurf: Abschnitt je Verbrauchsart', !!strom && /Strom/.test(text(strom.querySelector('h2'))));
  const items = [...v.querySelectorAll('.draft-item')];
  const kinds = items.map(i => i.dataset.kind);
  t('Entwurf: Zähler → Verträge → Preise → Ablesungen → Rechnung', kinds.join() === 'meter,contract,prices,readings,bill', kinds.join());
  t('Entwurf: ein Haken je Feldgruppe, beschriftet', items.every(i => i.querySelector('label input[type="checkbox"][data-pick]')));
  t('Entwurf: vorbelegt, was neu und ohne Zweifel ist', items.every(i => i.querySelector('input[data-pick]').checked));
  t('Entwurf: der Standardzähler wird übernommen', /Zähler \S.*: Nummer STR-1001 und Einbau eintragen/.test(text(items[0])), text(items[0]).slice(0, 80));
  t('Entwurf: Vertragsbeginn aus dem Lieferbeginn-Brief', /aus dem Lieferbeginn-Brief/.test(text(items[1])));
  const cons = items[4].querySelector('.draft-field[data-field="consumption"]');
  t('Entwurf: nur das Zweifelhafte fragt (Verbrauch, eigener Haken „übernehmen“)', !!cons?.classList.contains('draft-field--check')
    && !!cons.querySelector('label input[data-confirm]') && v.querySelectorAll('input[data-confirm]').length === 1, text(cons));
  const src = items[1].querySelector('details.draft-src');
  t('Entwurf: Belegstelle auf Antippen (Dokument, Seite, Zitat)', !!src && /Belegstelle/.test(text(src.querySelector('summary')))
    && !!src.querySelector('a[href^="#/inbox/inb_"]') && !!src.querySelector('.inbox-quote'));
  const missing = v.querySelector('#draft-missing');
  t('Entwurf: „Fehlt noch“ freundlich, mit Sprung zur Stelle', /Strom: Abschlag für Nordlicht Energie GmbH steht in keinem Dokument\./.test(text(missing))
    && /kein Fehler/.test(text(missing)) && missing.querySelector('a')?.getAttribute('href') === '#/utility/strom/contracts', text(missing));
  t('Entwurf: Dokumente mit Stand', v.querySelectorAll('#draft-docs li[data-doc]').length === 3);
  t('Entwurf: keine rohen Schlüssel', !/inboxDraft\.|inbox\.[a-z]+\./.test(text(v)), (text(v).match(/inbox(Draft)?\.[a-zA-Z.]+/) || [''])[0]);
  const btn = v.querySelector('#draft-apply');
  t('Entwurf: Knopf nennt die Zahl', text(btn) === '5 Einträge übernehmen', text(btn));
  // Verbrauch bestätigen, dann übernehmen
  const ck = cons.querySelector('input[data-confirm]');
  ck.checked = true; ck.dispatchEvent(new w.Event('change', { bubbles: true }));
  btn.click();
  for (let i = 0; i < 80 && !view().querySelector('#draft-result .draft-result'); i++) await sleep(50);
  await settle();
  const res = view().querySelector('#draft-result');
  t('Übernommen: was entstanden ist', /1 Vertrag angelegt/.test(text(res)) && /2 Ablesungen/.test(text(res)) && /1 Rechnung erfasst/.test(text(res))
    && /3 Dokumente erledigt/.test(text(res)), text(res).slice(0, 200));
  const bc = res.querySelector('a[href^="#/bill-check"]');
  t('Übernommen: „Rechnung prüfen“ für die Rechnung', /Strom: Rechnung 01\.04\.2025 bis 31\.12\.2025 prüfen/.test(text(bc))
    && /utility=strom&meter=m_strom_default&from=2025-04-01&until=2025-12-31/.test(bc?.getAttribute('href') || ''), bc?.getAttribute('href'));
  t('Übernommen: was noch fehlt, mit Sprung', /Abschlag für Nordlicht Energie GmbH/.test(text(res)));
  t('Übernommen: Hinweis auf die Sicherung', !!res.querySelector('a[href="#/settings/data"]'));
  const bills = await call('GET', '/api/utility/strom/bills');
  t('Übernommen: die bestätigte Angabe steht in der Rechnung', bills?.[0]?.invoice?.energy_kwh === 1800, JSON.stringify(bills?.[0]?.invoice));
  const inbox = await call('GET', '/api/inbox');
  t('Übernommen: alle Dokumente erledigt', inbox.entries.every(e => e.status === 'done'));
  t('danach: der Entwurf ist leer, kein Knopf', !view().querySelector('.draft-item') && !view().querySelector('#draft-apply'));
  const acts = await call('GET', '/api/activity');
  t('Verlauf: „Haushalt aus Dokumenten übernommen“', (acts || []).some(a => /Haushalt aus Dokumenten übernommen/.test(a.title || '')));

  // ── Einrichtungsassistent: drei Wege ──
  console.log('Einrichtungsassistent');
  const state = await import(JS('state.js'));
  state.invalidateSettings();
  const modal = () => w.document.querySelector('#modal-root .modal');
  W.openSetupWizard({ firstRun: false });
  for (let i = 0; i < 60 && !modal(); i++) await sleep(25);
  modal()?.querySelector('[data-act="next"]')?.click(); await sleep(40);   // Sprache und Land
  modal()?.querySelector('input[name="setup-persona"][value="mieterin"]')?.click(); await sleep(40);
  for (let s = 0; s < 3; s++) { modal()?.querySelector('[data-act="next"]')?.click(); await sleep(40); }   // Energieträger, Stufe → So geht es weiter
  const m = modal();
  const starts = [...(m?.querySelectorAll('input[name="setup-start"]') || [])].map(r => r.value);
  // v3.9.0 (F1032) — dazu „Messkonzept einrichten“ (Haushalt mit Strom), vor dem Beispielhaushalt
  t('Wege: aus Dokumenten, von Hand, Messkonzept, Beispielhaushalt', starts.join() === 'docs,own,concept,demo', starts.join());
  t('„Aus Dokumenten“ vorgewählt und empfohlen', m?.querySelector('input[name="setup-start"]:checked')?.value === 'docs' && /empfohlen/.test(text(m.querySelector('.setup-choice'))));
  t('welche Dokumente helfen (je Haushalt)', m?.querySelectorAll('[data-role="setup-docs"] li').length >= 1);
  const hint = m?.querySelector('[data-role="setup-reader"]');
  t('ohne Dienst: Hinweis und Knopf „Auslesen einrichten“', /Richte ihn gleich hier ein/.test(text(hint)) && text(hint.querySelector('button[data-act="reading-setup"]')) === 'Auslesen einrichten',
    text(hint));
  hint?.querySelector('button[data-act="reading-setup"]')?.click();
  const top = () => [...w.document.querySelectorAll('#modal-root .modal')].at(-1);
  for (let i = 0; i < 60 && !top()?.querySelector('#inbox-reading-card'); i++) await sleep(30);
  t('… öffnet die Karte „Auslesen“ als Dialog über dem Assistenten', w.document.querySelectorAll('#modal-root .modal').length === 2
    && !!top().querySelector('#inbox-reading-card input[name="rd-mode"][value="cloud"]') && text(top().querySelector('.modal__title')) === 'Auslesen einrichten');
  top()?.querySelector('[data-act="reading-setup-done"]')?.click();
  for (let i = 0; i < 40 && w.document.querySelectorAll('#modal-root .modal').length > 1; i++) await sleep(30);
  t('… „Fertig“ schließt nur den Dialog, der Assistent bleibt', w.document.querySelectorAll('#modal-root .modal').length === 1
    && !!modal()?.querySelector('input[name="setup-start"][value="docs"]:checked'));
  modal()?.querySelector('[data-act="next"]')?.click();
  for (let i = 0; i < 60 && modal(); i++) await sleep(30);
  await settle();
  t('„Los geht’s“ mit „Aus Dokumenten“: in den Posteingang', w.location.hash === '#/inbox', w.location.hash);

  // ── „Auslesen einrichten“ im Posteingang ──
  console.log('Auslesen einrichten (Dialog)');
  const RS = await import(JS('components/reading-setup.js'));
  const st = await call('GET', '/api/inbox/cloud');
  const ss = await call('GET', '/api/settings');
  const member = new JSDOM(`<div>${RS.readingSetupBodyHtml(st, ss, false)}</div>`).window.document;
  t('Mitglied: Satz, dass ein Verwalter einrichtet; die Adresse gesperrt', /wer die Installation verwaltet/.test(text(member.querySelector('[data-role="reading-setup-member"]')))
    && member.querySelector('#rd-endpoint')?.hasAttribute('disabled'));
  await go('#/inbox?r=2');
  let setupBtn = view().querySelector('button[data-act="reading-setup"]');
  t('Posteingang ohne Dienst: Knopf „Auslesen einrichten“ hervorgehoben', !!setupBtn?.classList.contains('btn--primary'));
  setupBtn?.click();
  for (let i = 0; i < 60 && !top()?.querySelector('#inbox-reading-card'); i++) await sleep(30);
  const dlg = top();
  t('… derselbe Baustein wie in den Einstellungen (Lokal | Cloud)', !!dlg?.querySelector('#inbox-reading-card form[data-role="ic-form"]')
    && dlg.querySelectorAll('input[name="rd-mode"]').length === 2 && dlg.getAttribute('role') === 'dialog');
  const ep = dlg?.querySelector('#rd-endpoint');
  if (ep) ep.value = 'http://127.0.0.1:11434';
  dlg?.querySelector('form[data-role="ic-form"]')?.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  for (let i = 0; i < 80 && !top()?.querySelector('[data-role="reading-setup-ready"]'); i++) await sleep(40);
  t('… speichern: der Dialog sagt, wer jetzt ausliest', /Eingerichtet: Die App liest jetzt aus mit/.test(text(top()?.querySelector('[data-role="reading-setup-ready"]'))),
    text(top()?.querySelector('.modal__body')).slice(0, 120));
  t('… gespeichert wie in der Karte', (await call('GET', '/api/settings')).ocr_endpoint === 'http://127.0.0.1:11434');
  top()?.querySelector('[data-act="reading-setup-done"]')?.click();
  for (let i = 0; i < 40 && w.document.querySelector('#modal-root .modal'); i++) await sleep(30);
  await settle();
  setupBtn = view().querySelector('button[data-act="reading-setup"]');
  t('danach: Knopf bleibt auf Wunsch, zurückhaltend', !!setupBtn && setupBtn.classList.contains('btn--ghost') && /Liest aus mit/.test(text(view().querySelector('[data-role="provider"]'))),
    setupBtn?.className);

  // ── Doku: was sie behauptet, steht im Katalog ──
  console.log('Doku');
  for (const [lang, file] of [['de', 'docs/anleitungen/haushalt-aus-dokumenten.md'], ['en', 'docs/en/anleitungen/haushalt-aus-dokumenten.md']]) {
    const d = read(file);
    const c = cat(lang);
    const f = c.setup.finish;
    t(`${lang}: Anleitung nennt die drei Wege wie im Assistenten`, [f.docs, f.own, f.demo].every(s => d.includes(s)), [f.docs, f.own, f.demo].filter(s => !d.includes(s)).join(' | '));
    t(`${lang}: Anleitung nennt den Knopf im Posteingang wie im Katalog`, d.includes(c.inboxDraft.entry.other.replace('{count}', '…')), c.inboxDraft.entry.other);
    t(`${lang}: Anleitung nennt die Karte „Auslesen“ und den Knopf „Auslesen einrichten“`, d.includes(c.settings.reading.title) && d.includes(c.inboxDraft.setup.button));
    t(`${lang}: Route der Ansicht`, d.includes('#/inbox/draft'));
  }
  const routes = read('src/bootstrap.php');
  t('Doku-Route und Server-Route stimmen', ['GET', 'POST'].every(m2 => routes.includes(m2 === 'GET' ? "r->get('/api/inbox/draft'" : "r->post('/api/inbox/draft/apply'")));
} catch (e) {
  t('Harness', false, e.stack || e.message);
} finally {
  proc.kill();
  rmSync(dataDir, { recursive: true, force: true });
  console.log(`\ninbox-draft.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
  process.exit(fail ? 1 : 0);
}
