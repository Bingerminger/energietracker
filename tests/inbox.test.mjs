// =====================================================================
// v3.7.0 — Posteingang ohne Server: Texte und Werte (lib/inbox.js), die
// Textebene (lib/pdf.js) und die Reihenfolge der Warteschlange.
//
// Die Textebene liest das mitgelieferte pdf.js — hier in Node am erfundenen
// Beispieldokument des Schaufensters: Jede Belegstelle der vorberechneten
// Auslesung muss in dem Text stehen, den pdf.js liefert (sonst stimmte die
// Demo nicht mit dem überein, was die App aus dem Dokument liest).
//
// Aufruf: node tests/inbox.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync, readdirSync } from 'node:fs';

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

const dom = new JSDOM('<!DOCTYPE html><html><body data-app-version="3.7.0"></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'sessionStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
const nativeFetch = global.fetch;
global.fetch = async (url, init) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  return nativeFetch(url, init);
};

const { initI18n, tp } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const I = await import(`${JS}/lib/inbox.js`);
const P = await import(`${JS}/lib/pdf.js`);
const Q = await import(`${JS}/lib/inbox-queue.js`);

console.log('Texte und Werte');
t('offen: neu, vorgemerkt, ausgelesen, fehlgeschlagen', I.openCount([{ status: 'new' }, { status: 'queued' }, { status: 'read' }, { status: 'failed' }, { status: 'done' }, { status: 'filed' }]) === 4);
t('Arten wie der Server (InboxSchema::TYPES)', I.TYPES.join() === readFileSync(resolve(ROOT, 'src/Services/Inbox/InboxSchema.php'), 'utf8')
  .match(/public const TYPES = \[([\s\S]*?)\];/)[1].replace(/[\s']/g, '').replace(/,$/, ''));
t('jede Art hat ihren Namen', I.TYPES.every(x => !/^inbox\./.test(I.typeLabel(x))));
t('Wert eines Datums deutsch', I.valueText('date', '2025-12-31') === '31.12.2025');
t('Preis in ct/kWh', I.valueText('price_ct', 7.9) === '7,9 ct/kWh', I.valueText('price_ct', 7.9));
t('Betrag in €', /^548,54\s€$/.test(I.valueText('money', 548.54)), I.valueText('money', 548.54));
t('Auswahl übersetzt', I.valueText('enum', 'credit') === 'Guthaben' && I.valueText('enum', 'due') === 'Nachzahlung');
t('Gründe als Text', I.reasonText(['value_not_in_quote', 'price_from_invoice']) === 'Wert steht nicht in der Belegstelle · Preis aus einer Rechnung: netto, brutto, Teilbetrag?');
t('Gas-Umrechnung als Eintrag', /01\.01\.2025 bis 30\.09\.2025: Zustandszahl 0,96 × Brennwert 11,65 kWh\/m³/.test(I.itemText('gas_factors', { from: '2025-01-01', to: '2025-09-30', z: 0.96, hs: 11.65 })));
t('Ergebnis: positiv Nachzahlung, negativ Guthaben', /^Nachzahlung 42,70\s€$/.test(I.resultText(42.7)) && /^Guthaben 548,54\s€$/.test(I.resultText(-548.54)));
const reading = I.proposalText({ kind: 'reading', label_key: 'inbox.proposal.reading', params: { date: '2025-12-31', counter: 1235.6 } }, { unit: 'm³' });
t('Vorschlag Ablesung mit Einheit', reading === 'Ablesung am 31.12.2025: 1.235,6 m³', reading);
const change = I.proposalText({ kind: 'contract_price', label_key: 'inbox.proposal.workingPriceChange', params: { from: '2026-04-01', value: 32.4, old: 30.1 } });
t('Preisänderung „30,1 → 32,4 ct“ wie im Konzept', change === 'Arbeitspreis ab 01.04.2026: 32,4 ct/kWh (bisher 30,1 ct/kWh)', change);
const bill = I.proposalText({ kind: 'bill', label_key: 'inbox.proposal.bill', params: { from: '2025-01-01', to: '2025-12-31', result: 42.7 } });
t('Vorschlag Rechnung mit Nachzahlung', /^Rechnung 01\.01\.2025 bis 31\.12\.2025 erfassen, Nachzahlung 42,70\s€$/.test(bill), bill);
t('Abgleich: Widerspruch nennt den Bestand', /^Erfasst ist 13\.570 m³\.$/.test(I.detailText({ kind: 'reading', detail: { counter: 13570 } }, { unit: 'm³' })));
const order = I.orderedFields({ a: { status: 'ok' }, b: { status: 'check' }, c: { status: 'empty' }, d: { status: 'empty' } }, { a: {}, b: {}, c: { core: true }, d: {} });
t('Felder: markierte zuerst, leere Nebenfelder zuletzt', order.join() === 'b,c,a,d', order.join());
const mt = I.manualTargets('invoice', { utility: 'gas', meterId: 'm1', from: '2025-01-01', until: '2025-12-31' });
t('Von Hand: Rechnung zuerst, mit Zeitraum', mt[0].key === 'bill' && /#\/bill-check\?utility=gas&capture=1&meter=m1&from=2025-01-01&until=2025-12-31/.test(mt[0].href), mt[0].href);
t('Von Hand: Vertragsbestätigung → Vertrag', I.manualTargets('contract_confirmation', { utility: 'strom' })[0].href === '#/utility/strom/contracts');
t('Von Hand: Zählerwechsel → Zähler', I.manualTargets('meter_change', { utility: 'gas' })[0].key === 'meter');

// v3.7.0 (treffsicher) — Hinweise und offene Punkte; „keine Preise“ und Brennwert laut Rechnung in „Rechnung prüfen“
console.log('Marken, offene Punkte, Rechnung prüfen (treffsicher)');
{
  const M = await import(`${JS}/lib/inbox-marks.js`);
  const notes = M.proposalNotes({ hints: [{ field: 'supply_start', reason: 'estimated' }],
    open: [{ field: 'advance', reason: 'missing' }, { field: 'tariff_name', reason: 'unsure' }] }).join(' | ');
  t('Vorschlag: voraussichtlich, fehlt, unsicher', notes === 'Nur voraussichtlich: Lieferbeginn | Fehlt im Dokument, später nachtragen: Abschlag | Unsicher gelesen, nicht übernommen: Tarif', notes);
  t('Feld: Hinweise ohne Marke, „fehlt“ nicht doppelt', M.fieldHints({ reasons: ['quote_not_found', 'supported', 'missing'] }).join() === 'supported');
  t('Grund „nicht im Dokument“ als Text', I.reasonText(['missing']) === 'Nicht im Dokument');
  // Nachbesserung 4: Stände je Zähler, Zählertausch aus der Rechnung mit beiden Ständen
  const mr = I.itemText('meter_readings', { meter_number: 'TEST-9999-02', reading_old: 0.5, reading_old_date: '2025-05-14', reading_new: 702.3, reading_new_date: '2025-12-31' });
  t('Stände je Zähler: Nummer, Anfang und Ende mit Datum', mr === 'Zähler TEST-9999-02: 0,5 (14.05.2025) → 702,3 (31.12.2025)', mr);
  const sw = I.proposalText({ kind: 'meter_change', label_key: 'inbox.proposal.meterSwapInvoice',
    params: { date: '2025-05-14', serial: 'TEST-9999-02', old_serial: 'TEST-4711-01', old_final: 12910, new_initial: 0.5 } }, { unit: 'm³' });
  t('Zählertausch aus der Rechnung: beide Stände mit Einheit', sw === 'Zählertausch am 14.05.2025: Endstand 12.910 m³ am Zähler TEST-4711-01, neuer Zähler TEST-9999-02 mit Anfangsstand 0,5 m³', sw);
  // Nachbesserung 4 (Zusatz): geschätzte Stände
  const mre = I.itemText('meter_readings', { meter_number: 'TEST-4711-01', reading_old: 12345, reading_old_date: '2024-12-31', reading_new: 13580, reading_new_date: '2025-12-31', estimated: true });
  t('Stände je Zähler: geschätzt laut Rechnung', /· geschätzt laut Rechnung$/.test(mre), mre);
  const re = I.proposalText({ kind: 'reading', label_key: 'inbox.proposal.readingEstimated', params: { date: '2025-12-31', counter: 13580 } }, { unit: 'm³' });
  t('Ablesung geschätzt laut Rechnung', re === 'Ablesung am 31.12.2025: 13.580 m³ (geschätzt laut Rechnung)', re);
  t('Ablese-Art als ja/nein', I.valueText('bool', true) === 'Ja' && I.valueText('bool', false) === 'Nein');
  t('Grund „Form ungültig“ (Marktlokation) als Text', /^Form ungültig – eine Marktlokation hat 11 Ziffern/.test(I.reasonText(['invalid_format'])), I.reasonText(['invalid_format']));
  // Nachbesserung 2: Nettopreis umgerechnet, gleicher Preis mit anderem Steuersatz
  const netItem = I.itemText('working_prices', { from: '2025-01-01', ct: 10, basis: 'net', vat_pct: 19 }, { net: { vat_pct: 19, gross: 11.9 } });
  t('Arbeitspreis netto: Satz und Bruttopreis daneben', netItem === 'ab 01.01.2025: 10 ct/kWh netto + 19 % USt = 11,9 ct/kWh', netItem);
  t('Arbeitspreis ohne erkannten Nettopreis: wie gedruckt', I.itemText('working_prices', { from: '2025-01-01', ct: 10, basis: 'net' }, { net: null }) === 'ab 01.01.2025: 10 ct/kWh');
  t('Feld: netto umgerechnet und anderer Steuersatz sind Hinweise', M.fieldHints({ reasons: ['price_from_invoice', 'net_price', 'vat_changed'] }).join() === 'net_price,vat_changed');
  const vat = M.proposalNotes({ hints: [{ field: 'working_prices', reason: 'net_price' }, { field: 'working_prices', reason: 'vat_changed' }] }).join(' | ');
  t('Vorschlag: gleicher Preis, anderer Steuersatz', vat === 'Gleicher Preis wie im Vertrag, nur ein anderer Steuersatz – die neue Preiszeile gilt ab dem Tag, den die Rechnung für sie nennt.', vat);
  const net = M.proposalNotes({ hints: [{ field: 'base_price', reason: 'net_price' }] }).join(' | ');
  t('Vorschlag: netto in brutto umgerechnet', /^Netto laut Rechnung, mit dem ausgewiesenen Steuersatz in brutto umgerechnet/.test(net), net);
  const dup = M.duplicateHtml({ duplicates: [{ id: 'inb_0123456789ab', name: 'Scan <1>.pdf', created_at: '2026-02-05T18:42:00+01:00' }] });
  t('wohl dasselbe Dokument: Name, Tag und Sprung, nichts roh', /Wohl dasselbe Dokument wie „Scan &lt;1&gt;\.pdf“ \(abgelegt am 05\.02\.2026\)/.test(dup)
    && /href="#\/inbox\/inb_0123456789ab"/.test(dup), dup.replace(/\s+/g, ' '));
  t('ohne Doppel kein Hinweis', M.duplicateHtml({ duplicates: [] }) === '');
  const BC = await import(`${JS}/views/bill-check.js`);
  const np = BC.compareNotesHtml({ verdict: 'no_prices', price_missing_at: [{ from: '2025-01-01', to: '2025-12-31', contract_id: 'c1', provider: 'Muster' },
    { from: '2026-01-01', to: '2026-01-31', contract_id: null }] }, { key: 'strom' });
  t('keine Preise: Abschnitte mit Vertrag, Sprung zu den Verträgen', /01\.01\.2025 bis 31\.12\.2025: kein Preis im Vertrag Muster/.test(np)
    && /01\.01\.2026 bis 31\.01\.2026: kein Vertrag/.test(np) && /href="#\/utility\/strom\/contracts"/.test(np), np.replace(/\s+/g, ' ').slice(0, 160));
  const fm = BC.compareNotesHtml({ verdict: 'ok', factor_mismatches: [{ from: '2025-07-01',
    invoice: { kwh_per_m3: 10.64, zustandszahl: 0.95, brennwert: 11.2 }, list: { kwh_per_m3: 10.83 } }] }, { key: 'gas' });
  t('Brennwert laut Rechnung: beide Werte, Sprung zur Einstellung', /Ab 01\.07\.2025: laut Rechnung 10,640 kWh\/m³ \(0,9500 × 11,200\), in den Einstellungen 10,830 kWh\/m³/.test(fm)
    && /href="#\/settings\/utilities"/.test(fm), fm.replace(/\s+/g, ' ').slice(0, 200));
  t('ohne Befund kein Zusatz', BC.compareNotesHtml({ verdict: 'ok', factor_mismatches: [] }) === '');
  // Nachbesserung nach dem Nutzentest: weicht die Menge ab, bleibt „prüfen“ — die fehlenden Preise stehen trotzdem da
  const npC = BC.compareNotesHtml({ verdict: 'check', reasons: ['quantity', 'price_missing'],
    price_missing_at: [{ from: '2025-01-01', to: '2025-12-31', contract_id: 'c1', provider: 'Muster' }] }, { key: 'gas' });
  t('„prüfen“ mit fehlenden Preisen: Abschnitt und Sprung zu den Verträgen', /data-role="no-prices"/.test(npC)
    && /01\.01\.2025 bis 31\.12\.2025: kein Preis im Vertrag Muster/.test(npC) && /href="#\/utility\/gas\/contracts"/.test(npC), npC.replace(/\s+/g, ' ').slice(0, 160));
  t('„prüfen“ ohne fehlende Preise: kein Hinweis', !/no-prices/.test(BC.compareNotesHtml({ verdict: 'check', price_missing_at: [] }, { key: 'gas' })));
  const oqG = I.gistText({ key: 'inbox.gist.invoice.offQuantityNoPrices', params: { pct: 7.25 } });
  t('Kernaussage nennt Menge und fehlende Preise', oqG === 'Der Verbrauch auf der Abrechnung weicht um 7,3 % von deinen Zählerständen ab, und im Vertrag fehlen Preise – den Betrag kann die App nicht nachrechnen.', oqG);
  // Zusammenführung mit „betrieb“: die Kernaussage kennt „keine Preise“, offene Punkte und Doppel
  const npG = I.gistText({ key: 'inbox.gist.invoice.noPrices', params: { from: '2025-01-01', to: '2025-12-31' } });
  t('Kernaussage „keine Preise“ mit Zeitraum', npG === 'Die Abrechnung 01.01.2025 bis 31.12.2025: Im Vertrag fehlen Preise – den Betrag kann die App nicht nachrechnen.', npG);
  t('Knopf zu den Preisen im Vertrag', I.gistActionLabel({ key: 'inbox.gist.action.addPrices' }) === 'Preise im Vertrag eintragen');
  const openG = I.gistDetails({ details: [{ key: 'inbox.note.missing', params: { fields: ['advance', 'working_price'] } },
    { key: 'billCheck.noPrices.at', params: { from: '2025-01-01', to: '2025-12-31', provider: 'Muster' } }] });
  t('Details: offene Punkte mit Feldnamen, Abschnitt ohne Preis', openG[0] === 'Fehlt im Dokument, später nachtragen: Abschlag, Arbeitspreis'
    && openG[1] === '01.01.2025 bis 31.12.2025: kein Preis im Vertrag Muster', openG.join(' | '));
  const dupA = I.dossierText({ kind: 'inbox', type: 'invoice', duplicate_of: 'inb_0123456789ab', gist: { key: 'inbox.gist.invoice.ok', params: { from: '2025-01-01', to: '2025-12-31' } } });
  t('Akte: Doppel kenntlich', / · wohl ein Doppel$/.test(dupA), dupA);
}
// ── v3.7.0 (Paket betrieb) — Kernaussage und Akte ──
console.log('Kernaussage (Paket betrieb)');
{
  const pc = { key: 'inbox.gist.priceChange.more', params: { date: '2026-04-01', provider: 'Stadtwerke Musterstadt', amount: 52.4 },
    figure: { kind: 'money_year', value: 52.4, tone: 'bad', signed: true }, action: { kind: 'link', key: 'inbox.gist.action.compareTariffs', params: { amount: 120 }, href: '#/tariffs' },
    details: [{ key: 'inbox.gist.detail.workingPrice', params: { old: 11.9, new: 12.8 } }, { key: 'inbox.gist.detail.basePrice', params: { old: 12.5, new: 13.1 } },
      { key: 'inbox.gist.detail.gap', params: { days: 1 } }, { key: 'inbox.gist.detail.gap', params: { days: 3 } }] };
  const s = I.gistText(pc);
  t('Preisänderung: Satz mit Datum, Anbieter und Betrag', /^Ab 01\.04\.2026 kostet dich Stadtwerke Musterstadt rund 52,40\s€ mehr im Jahr\.$/.test(s), s);
  t('Zahl mit Vorzeichen je Jahr', /^\+52,40\s€ im Jahr$/.test(I.gistFigure(pc.figure)), I.gistFigure(pc.figure));
  t('Minderkosten mit Minuszeichen', /^−7,00\s€ im Monat$/.test(I.gistFigure({ kind: 'money_month', value: -7, signed: true })), I.gistFigure({ kind: 'money_month', value: -7, signed: true }));
  t('Knopf nennt die Ersparnis', /^Wechsel spart rund 120,00\s€ – Tarife vergleichen$/.test(I.gistActionLabel(pc.action)), I.gistActionLabel(pc.action));
  const d = I.gistDetails(pc);
  t('Details: Arbeitspreis in ct/kWh, Grundpreis in €', d[0] === 'Arbeitspreis: 11,9 ct/kWh → 12,8 ct/kWh' && /^Grundpreis im Monat: 12,50\s€ → 13,10\s€$/.test(d[1]), d.slice(0, 2).join(' | '));
  t('Lücke mit Pluralform (1 Tag / 3 Tage)', /liegt 1 Tag ohne/.test(d[2]) && /liegen 3 Tage ohne/.test(d[3]), d.slice(2).join(' | '));
  t('Rechnung: Ergebnis als Zahl (positiv = Nachzahlung)', /^Nachzahlung 42,70\s€$/.test(I.gistFigure({ kind: 'result', value: 42.7 })));
  const off = I.gistText({ key: 'inbox.gist.invoice.offMore', params: { from: '2025-01-01', to: '2025-12-31', amount: 18.5 } });
  t('Rechnung: Abweichung in €', /^Die Abrechnung liegt 18,50\s€ über deiner eigenen Rechnung\.$/.test(off), off);
  const adv = I.gistText({ key: 'inbox.gist.advance.high', params: { amount: 220, diff: 80, recommended: 140, date: '2026-10-01' } });
  t('Abschlag zu hoch: neuer, Abstand, passend', /^Der neue Abschlag von 220,00\s€ ist rund 80,00\s€ im Monat zu hoch \(passend wären 140,00\s€\)\.$/.test(adv), adv);
  const basis = I.gistLine('inbox.gist.detail.advanceBasis', { recommended: 140, months: 12, balance: -960 });
  t('Abschlag: Ende der Abrechnung als Guthaben', /Guthaben 960,00\s€/.test(basis), basis);
  const who = I.gistText({ key: 'inbox.gist.reading.who', params: { date: '2026-12-01', meter: 'Gas', person: 'Mia' } });
  t('Zählerstand: Frist und zuständige Person', who === 'Zählerstand Gas bis 01.12.2026 melden – zuständig ist Mia.', who);
  const nobody = I.gistText({ key: 'inbox.gist.priceChange.new', params: { date: null } });
  t('fehlendes Datum als Strich statt „null“', nobody === 'Ab – gelten neue Preise.', nobody);
  const swap = I.gistText({ key: 'inbox.gist.meterChange.todo', params: { date: '2026-05-15', old: 13902.5, new: 0.8, serial: 'X-2' } });
  t('Zählerwechsel: Stände als Zahl', /Endstand 13\.902,5, neuer Zähler X-2 ab 0,8\./.test(swap), swap);
  t('ohne Kernaussage kein Text', I.gistText(null) === '' && I.gistFigure(null) === '' && I.gistDetails(null).length === 0);
}
console.log('Akte (Paket betrieb)');
{
  const items = [
    { kind: 'inbox', type: 'invoice', date: '2025-12-31', title: 'Abrechnung.pdf', gist: { key: 'inbox.gist.invoice.ok', params: { from: '2025-01-01', to: '2025-12-31' } } },
    { kind: 'bill', type: 'annual', date: '2024-12-31', title: '', period_from: '2024-01-01', period_to: '2024-12-31', result_eur: -20 },
    { kind: 'photo', type: 'reading', date: '2025-06-30', title: 'zaehler.jpg', counter: 12900 },
    { kind: 'receipt', type: 'contract', date: '2026-01-02', title: 'Vertrag.pdf', provider: 'Musterenergie' },
  ];
  t('Akte: Kernaussage des Dokuments', I.dossierText(items[0]) === 'Die Abrechnung 01.01.2025 bis 31.12.2025 passt zu deinen Daten.', I.dossierText(items[0]));
  t('Akte: Rechnung mit Zeitraum und Ergebnis', /^Abrechnung 01\.01\.2024 bis 31\.12\.2024 · Guthaben 20,00\s€$/.test(I.dossierText(items[1])), I.dossierText(items[1]));
  t('Akte: Foto mit Zählerstand', I.dossierText(items[2]) === 'Zählerstand 12.900', I.dossierText(items[2]));
  t('Akte: Art je Eintrag (Dokumentart, Rechnung, Beleg, Foto)', items.map(I.dossierKind).join() === 'inbox:invoice,bill,photo,receipt'
    && I.dossierKindLabel('inbox:invoice') === 'Rechnung' && I.dossierKindLabel('photo') === 'Foto');
  const D = await import(`${JS}/components/dossier.js`);
  t('Filter nach Art', D.filterDossier(items, { kind: 'photo' }).length === 1);
  t('Suche in Titel und Satz (ohne Groß/klein)', D.filterDossier(items, { query: 'musterenergie' }).length === 1 && D.filterDossier(items, { query: 'PASST' }).length === 1);
  const html = D.dossierBodyHtml(items);
  t('Liste mit Auswahl der vorkommenden Arten und Zähler', (html.match(/<option /g) || []).length === 5 && /4 Dokumente/.test(html));
  t('Eintrag mit Link in die Vorschau und zum Öffnen', /href="#\/inbox\/inb_1"/.test(D.dossierItemHtml({ ...items[0], inbox_id: 'inb_1', attachment_id: 'att_0123456789abcdef' }))
    && /api\/attachments\/att_0123456789abcdef/.test(D.dossierItemHtml({ ...items[0], attachment_id: 'att_0123456789abcdef' })));
  t('Titel wird escaped', !/<img/.test(D.dossierItemHtml({ ...items[2], title: '<img src=x onerror=alert(1)>' })));
}

// v3.9.0 (F1034) — Vertrag aus der Rechnung: Aufschlüsselung je Sparte, Abschlag mit Rhythmus.
// Form der Parameter wie Proposals::contractFromInvoice (WaterBillContractTest).
console.log('Vertrag aus der Rechnung (vertrag-aus-rechnung)');
{
  const R = await import(`${JS}/lib/advance-rhythm.js`);
  const sp = (s) => s.replace(/ /g, ' ');
  t('monatlich: kein Zusatz, der Betrag wie bisher', R.advanceRhythm(1) === '' && sp(R.advanceAmountText(50, 1)) === '50,00 €');
  t('alle 3 Monate ohne Termine', R.advanceRhythm(3) === 'alle 3 Monate', R.advanceRhythm(3));
  t('alle 2 Monate: der Betrag gilt je Termin', sp(R.advanceAmountText(165, 2)) === '165,00 € alle 2 Monate', R.advanceAmountText(165, 2));
  t('Termine laut Plan', R.advanceRhythm(2, ['2026-05-15', '2026-07-15']) === 'alle 2 Monate, am 15.05.2026, 15.07.2026'
    && R.advanceRhythm(1, ['2026-03-01']) === 'am 01.03.2026', R.advanceRhythm(2, ['2026-05-15', '2026-07-15']));
  t('Rhythmus wie der Server (ContractService::ADVANCE_EVERY)', R.ADVANCE_EVERY.join() === (readFileSync(resolve(ROOT, 'src/Services/ContractService.php'), 'utf8')
    .match(/public const ADVANCE_EVERY = \[([^\]]*)\]/) || [])[1]?.replace(/\s/g, ''));
  const net = (label, price, unit, vat) => ({ label, price, unit, net: true, vat_pct: vat });
  const lines = I.proposalLines({ kind: 'contract_create', params: { lines: [
    { section: 'trinkwasser', kind: 'working', from: '2025-04-01', value: 224.7, parts: [net('Wasserverbrauch', 2.1, 'm3', 7)] },
    { section: 'trinkwasser', kind: 'base', from: '2025-04-01', value: 13.375, parts: [net('Basispreis', 50, 'year', 7), net('Bereitstellungspreis', 100, 'year', 7)] },
    { section: 'schmutzwasser', kind: 'base', from: '2025-10-01', value: 8.7267, parts: [net('Basispreis', 44, 'year', 19), net('Bereitstellungspreis', 44, 'year', 19)] },
    { section: 'niederschlagswasser', kind: 'rate', from: '2025-04-01', value: 1.19, area: 200, parts: [net('Niederschlagswasserpreis', 1, 'm2_year', 19)] },
    { section: null, kind: 'advance', from: '2026-05-15', value: 165, every: 2, dates: ['2026-05-15', '2026-07-15', '2026-09-15'] },
  ] } }).map(sp);
  t('Trinkwasser je m³ brutto, aus dem Nettopreis mit Satz', lines[0] === 'Trinkwasser ab 01.04.2025: 224,7 ct/m³ (aus Wasserverbrauch 2,10 € je m³ netto + 7 % USt)', lines[0]);
  t('Grundpreis aus Basis- und Bereitstellungspreis, je Monat mit allen Stellen', lines[1] === 'Grundpreis Trinkwasser ab 01.04.2025: 13,375 € pro Monat (aus Basispreis 50,00 € je Jahr netto + 7 % USt + Bereitstellungspreis 100,00 € je Jahr netto + 7 % USt)', lines[1]);
  t('Schmutzwasser hat einen eigenen Grundpreis', /^Grundpreis Schmutzwasser ab 01\.10\.2025: 8,7267 € pro Monat/.test(lines[2]), lines[2]);
  t('Niederschlagswasser je m² und Jahr mit Fläche', lines[3] === 'Niederschlagswasser ab 01.04.2025: 1,19 € je m² und Jahr für 200 m² (aus Niederschlagswasserpreis 1,00 € je m² und Jahr netto + 19 % USt)', lines[3]);
  t('Abschlagsplan: je Termin, alle 2 Monate, mit den Terminen', lines[4] === 'Abschlag ab 15.05.2026: 165,00 € je Termin, alle 2 Monate, am 15.05.2026, 15.07.2026, 15.09.2026', lines[4]);
  const gas = I.proposalLines({ kind: 'contract_create', params: { lines: [{ section: null, kind: 'working', from: '2025-01-01', value: 11.9,
    parts: [{ label: 'Arbeitspreis', price: 8.5, unit: 'kwh', net: false }, { label: 'Energiesteuer', price: 0.55, unit: 'kwh', net: false }] }] } }).map(sp);
  t('Gas: Arbeitspreis aus Bestandteilen (Energiesteuer)', gas[0] === 'Arbeitspreis ab 01.01.2025: 11,9 ct/kWh (aus Arbeitspreis 8,5 ct/kWh + Energiesteuer 0,55 ct/kWh)', gas[0]);
  const wp = sp(I.proposalText({ kind: 'contract_water_price', label_key: 'inbox.proposal.waterBase', data: { key: 'eur_per_month' },
    params: { section: 'schmutzwasser', from: '2025-10-01', value: 8.7267 } }));
  t('Preiszeile am Bestand: Sparte und Grundpreis je Monat', wp === 'Grundpreis Schmutzwasser ab 01.10.2025: 8,7267 € pro Monat (aus der Rechnung)', wp);
  const ww = sp(I.proposalText({ kind: 'contract_water_price', label_key: 'inbox.proposal.waterWorking', data: { key: 'ct_per_m3' },
    params: { section: 'trinkwasser', from: '2025-10-01', value: 246.1 } }));
  t('Preiszeile am Bestand: Wasser in ct/m³ wie im Vertrag', ww === 'Trinkwasser ab 01.10.2025: 246,1 ct/m³ (aus der Rechnung)', ww);
  const item = sp(I.itemText('water_prices', { section: 'schmutzwasser', label: 'Abwassermenge', from: '2025-04-01', to: '2025-09-30', price: 1.8, unit: 'm3' },
    { net: { vat_pct: 19, gross: 2.142 } }));
  t('Feld Wasserpreise: netto, Satz und brutto mit allen Stellen', item === 'Schmutzwasser · Abwassermenge 01.04.2025 bis 30.09.2025: 1,80 € je m³ netto + 19 % USt = 2,142 € je m³', item);
  const adv = sp(I.proposalText({ kind: 'contract_advance', label_key: 'inbox.proposal.advance', params: { from: '2026-05-15', value: 165, every: 2, dates: ['2026-05-15'] } }));
  t('Abschlagsänderung mit Rhythmus', /, alle 2 Monate, am 15\.05\.2026$/.test(adv), adv);
  const rep = I.detailText({ kind: 'reading', detail: { reported_on: '2026-07-20', counter: 1210 } }, { unit: 'm³' });
  t('Stand: dein gemeldeter Stand, auf das Zeitraumende datiert', rep === 'Dein Stand vom 20.07.2026 (1.210 m³): Der Versorger datiert den gemeldeten Stand auf das Ende des Zeitraums.', rep);
  const below = I.detailText({ kind: 'reading', detail: { neighbor: { side: 'prev', date: '2026-07-30', counter: 1212 } } }, { unit: 'm³' });
  t('Stand: liegt unter deinem Stand (mit Tag)', below === 'Liegt unter deinem Stand 1.212 m³ vom 30.07.2026.', below);
  // Skeptiker H2, N15, N8 — gezahlte Abschläge im Vorschlag, Name im Bestand, „kein Vertrag“ statt 0,00 €
  const paid = sp(I.proposalLines({ kind: 'contract_create', params: { lines: [{ section: null, kind: 'paid', from: '2025-04-01', to: '2026-03-31', value: 66.6667, total: 800 }] } })[0]);
  t('Vertrag aus der Rechnung: gezahlte Abschläge des Zeitraums', paid === 'Gezahlte Abschläge 01.04.2025 bis 31.03.2026: 800,00 € laut Rechnung', paid);
  // Skeptiker R2-8 — die 0-€-Zeile danach steht als Hinweis da, im Vorschlag und am Vertrag
  const [gap, gapOpen] = I.proposalLines({ kind: 'contract_create', params: { lines: [
    { section: null, kind: 'gap', from: '2026-04-01', to: '2026-05-14' }, { section: null, kind: 'gap', from: '2026-04-01', to: null }] } });
  t('Vorschlag: bis zum ersten neuen Termin kein Abschlag eingetragen',
    gap === '01.04.2026 bis 14.05.2026: kein Abschlag eingetragen, die Rechnung nennt dafür keinen. Hast du in der Zeit gezahlt, trag es am Vertrag nach.', gap);
  t('Vorschlag: ohne neuen Abschlag offen', gapOpen === 'Ab 01.04.2026 kein Abschlag eingetragen, die Rechnung nennt keinen neuen. Trag ihn am Vertrag nach, sobald du ihn kennst.', gapOpen);
  const plan = [{ from: '2025-04-01', amount_eur: 66.6667 }, { from: '2026-04-01', amount_eur: 0 }, { from: '2026-05-15', amount_eur: 165, every_months: 2 }];
  const span = R.zeroAdvanceSpan(plan, plan[1]);
  t('Vertrag: 0-€-Zeile bis zum Vortag der nächsten Zeile', JSON.stringify(span) === JSON.stringify({ from: '2026-04-01', until: '2026-05-14' }), JSON.stringify(span));
  t('Vertrag: Hinweis zur 0-€-Zeile', R.zeroAdvanceText(span) === '01.04.2026 bis 14.05.2026: kein Abschlag eingetragen. Hast du in der Zeit gezahlt, trag den Betrag hier ein.', R.zeroAdvanceText(span));
  t('Vertrag: letzte 0-€-Zeile offen', R.zeroAdvanceText(R.zeroAdvanceSpan(plan.slice(0, 2), plan[1])) === 'Ab 01.04.2026 kein Abschlag eingetragen.');
  t('Vertrag: kein Hinweis für einen Betrag, eine leere oder undatierte Zeile', R.zeroAdvanceSpan(plan, plan[0]) === null
    && R.zeroAdvanceSpan(plan, { from: '2026-04-01', amount_eur: '' }) === null && R.zeroAdvanceSpan(plan, { from: '', amount_eur: 0 }) === null);
  // beide Vertragsformulare zeigen den Hinweis (Standard- und Wasserformular; die Darstellung selbst prüft die Sichtprüfung)
  const cv = readFileSync(resolve(ROOT, 'public/js/views/contracts.js'), 'utf8');
  t('Vertrag: Hinweis in beiden Formularen verdrahtet', cv.includes('renderEntryRow(g, e, entries)') && cv.includes("${advance ? advanceGapHtml(entries, e) : ''}")
    && cv.includes("groupKey === 'ap' && f === 'amount_eur' ? waterGapNote(entries, e)") && (cv.match(/zeroAdvanceText\(zeroAdvanceSpan\(entries, e\)\)/g) || []).length === 2);
  const DR0 = await import(`${JS}/lib/inbox-draft.js`);
  const nm = DR0.basisText({ name: 'provider', kind: 'text', value: 'Stadtwerke Musterstadt GmbH', status: 'exists', existing_name: 'Stadtwerke Musterstadt' });
  t('Entwurf: Versorger vorhanden, im Bestand anders geschrieben', nm === 'Im Bestand heißt der Versorger „Stadtwerke Musterstadt“.', nm);
  const SM = await import(`${JS}/lib/semantics.js`);
  const water = { key: 'wasser', has_contracts: true, accounting_kind: 'consumption' };
  t('Kosten ohne Vertrag: „kein Vertrag“, mit Vertrag, Kosten oder im Abrechnungsjahr nicht', SM.costWithoutContract({ u: water, hasContract: false, cost: 0 })
    && !SM.costWithoutContract({ u: water, hasContract: true, cost: 0 }) && !SM.costWithoutContract({ u: water, hasContract: false, cost: 12.5 })
    && !SM.costWithoutContract({ u: water, hasContract: false, cost: 0, billing: true })
    && !SM.costWithoutContract({ u: { key: 'pv_einspeisung', accounting_kind: 'feed_in' }, hasContract: false, cost: 0 })
    && !SM.costWithoutContract({ u: { key: 'heizoel', has_contracts: false }, hasContract: false, cost: 0 }));
  const DR = await import(`${JS}/lib/inbox-draft.js`);
  const dn = DR.basisText({ name: 'r:2026-07-31', kind: 'number', value: 1209, neighbor: { side: 'prev', date: '2026-07-30', counter: 1212 } });
  t('Entwurf: Stand nennt den Nachbarn', dn === 'Liegt unter deinem Stand 1.212 vom 30.07.2026.', dn);
  const MK = await import(`${JS}/lib/inbox-marks.js`);
  const rc = MK.proposalNotes({ hints: [{ field: 'advance_new_every', reason: 'rhythm_conflict' }] }).join(' | ');
  t('Rhythmus gegen Termine: Hinweis unter dem Vorschlag', /^Der Rhythmus laut Rechnung passt nicht zu den genannten Terminen\./.test(rc), rc);
  const fig = sp(I.gistFigure({ kind: 'money_rate', value: -12, signed: true }));
  t('Kernaussage: Abweichung je Termin, nicht je Monat', fig === '−12,00 € je Termin', fig);
}

console.log('Warteschlange');
const next = Q.nextQueued([{ id: 'b', status: 'queued', queued_at: '2026-01-01T10:00:00#00001' }, { id: 'a', status: 'queued', queued_at: '2026-01-01T10:00:00#00000' },
  { id: 'c', status: 'new' }]);
t('in der Reihenfolge des Vormerkens', next?.id === 'a');
t('übersprungene nicht noch einmal', Q.nextQueued([{ id: 'a', status: 'queued', queued_at: '1' }, { id: 'b', status: 'queued', queued_at: '2' }], new Set(['a']))?.id === 'b');
t('nichts mehr vorgemerkt → Ende', Q.nextQueued([{ id: 'a', status: 'read' }]) === null);

// v3.7.0 (cloud) — Zustimmung zur Cloud: einmal je Lauf für genau die Dokumente beim Fragen
console.log('Zustimmung für einen Lauf (Cloud)');
{
  const E = [{ id: 'n', status: 'new', attachment_id: 'a1' }, { id: 'f', status: 'failed', attachment_id: 'a2' },
    { id: 'q', status: 'queued', attachment_id: 'a3', queued_at: '1' }, { id: 'r', status: 'read', attachment_id: 'a4' }, { id: 'x', status: 'new', attachment_id: null }];
  const all = Q.runScope(E);
  t('„Alle auslesen“: neue, fehlgeschlagene und vorgemerkte mit PDF', all.count === 3 && [...all.ids].join() === 'n,f,q', [...all.ids].join());
  const resume = Q.runScope(E, { resume: true });
  t('Fortsetzen: nur die vorgemerkten', resume.count === 1 && resume.ids.has('q'));
  t('der Lauf nimmt nur Dokumente aus seiner Zustimmung', Q.nextQueued([{ id: 'late', status: 'queued', queued_at: '0' }, { id: 'q', status: 'queued', queued_at: '1' }],
    new Set(), new Set(['q']))?.id === 'q');

  // nachgebauter Server: GET /api/inbox liefert den Stand; gelesen wird mit einem Ersatz für pdf.js + Server
  const server = [{ id: 'a', status: 'queued', queued_at: '1', attachment_id: 'x1' }, { id: 'b', status: 'queued', queued_at: '2', attachment_id: 'x2' }];
  const prevFetch = global.fetch;
  global.fetch = async (url, init) => {
    if (/\/api\/inbox$/.test(String(url)) && (init?.method || 'GET') === 'GET') {
      return new Response(JSON.stringify({ success: true, data: { entries: server, queue: { queued: server.filter(e => e.status === 'queued').length } } }),
        { status: 200, headers: { 'Content-Type': 'application/json' } });
    }
    return prevFetch(url, init);
  };
  const keys = () => [localStorage, sessionStorage].map(s => Array.from({ length: s.length }, (_, i) => s.key(i)).sort().join()).join('|');
  const before = keys();
  const calls = [];
  const fakeRead = async (entry, { consent }) => { calls.push(`${entry.id}:${consent}`); server.find(e => e.id === entry.id).status = 'read'; return {}; };
  const scope = Q.runScope(server, { resume: true });
  server.push({ id: 'late', status: 'queued', queued_at: '0', attachment_id: 'x3' });   // nach der Zustimmung vorgemerkt
  const prog = await Q.runQueue({ consent: true, only: scope.ids, read: fakeRead });
  t('Lauf: Zustimmung bei jedem Aufruf, genau die Dokumente beim Fragen', calls.join() === 'a:true,b:true', calls.join());
  t('Lauf: Anzahl wie im Dialog', prog.total === 2 && prog.done === 2, JSON.stringify(prog));
  calls.length = 0;
  await Q.runQueue({ read: fakeRead });
  t('nächster Lauf ohne neue Zustimmung: nichts gemerkt', calls.join() === 'late:false', calls.join());
  t('Zustimmung nirgends im Browser gespeichert', keys() === before, `${before} → ${keys()}`);
  global.fetch = prevFetch;
  const two = tp('inbox.consent.text', 2, { provider: 'Claude (Anthropic) – api.anthropic.com' });
  t('Dialog nennt die Zahl und das Ziel', two === 'Diese 2 Dokumente gehen zum Auslesen an Claude (Anthropic) – api.anthropic.com. Einverstanden?', two);
}

// 3.7.1 — hinter einem Webserver mit kurzer Zeitgrenze (Synology: 504 nach 60 s) rechnet PHP weiter
// und speichert; die Oberfläche fragt den Eintrag ab, bis der Schritt gespeichert ist
console.log('Warten nach 504 oder Netzabbruch');
{
  const json = (data, status = 200) => new Response(JSON.stringify({ success: true, data }), { status, headers: { 'Content-Type': 'application/json' } });
  const html504 = () => new Response('<html><body>504 Gateway Time-out</body></html>', { status: 504, headers: { 'Content-Type': 'text/html' } });
  const entry = { id: 'w1', status: 'queued', type: null, updated_at: '2026-10-22T10:00:00+02:00', attachment_id: 'x1' };
  let server = { ...entry };
  let polls = 0;
  const calls = [];
  const prevFetch = global.fetch;
  // Erkennen: 504, Auslesen: Netzabbruch; der Server speichert trotzdem (nach der zweiten Abfrage)
  global.fetch = async (url, init) => {
    const u = String(url);
    const m = init?.method || 'GET';
    if (/\/api\/inbox\/w1\/classify$/.test(u)) { calls.push('classify'); return html504(); }
    if (/\/api\/inbox\/w1\/extract$/.test(u)) { calls.push('extract'); throw new TypeError('Failed to fetch'); }
    if (/\/api\/inbox\/w1$/.test(u) && m === 'GET') {
      calls.push('poll');
      if (++polls === 2) server = { ...server, type: 'invoice', updated_at: '2026-10-22T10:01:30+02:00' };
      if (polls === 4) server = { ...server, status: 'read', updated_at: '2026-10-22T10:03:10+02:00', extraction: { fields: {} } };
      return json({ entry: server, recognize_only: false });
    }
    return prevFetch(url, init);
  };
  const steps = [];
  // Uhr zum Vorspulen: 5 s je Abfrage, Obergrenze 660 s wie in der App
  let tick = 0;
  const fast = { wait: { intervalMs: 5000, limitMs: 660000 }, sleep: async (ms) => { tick += ms; }, now: () => tick };
  const pv = await Q.readDocument(entry, { pages: ['Rechnung'], onStep: (s) => steps.push(s), ...fast });
  t('504 beim Erkennen, Netzabbruch beim Auslesen: abgefragt, bis gespeichert', pv?.entry?.status === 'read' && pv.entry.type === 'invoice', JSON.stringify(pv?.entry));
  t('je Schritt einmal gesendet, nicht noch einmal ausgelesen', calls.filter(c => c !== 'poll').join() === 'classify,extract' && polls === 4, calls.join());
  t('Schritt „wartet“ angezeigt', steps.join() === 'text,type,wait,fields,wait', steps.join());

  // fehlgeschlagen: der Fehler, den der Server vermerkt hat
  server = { ...entry }; polls = 0;
  global.fetch = async (url, init) => {
    const u = String(url);
    if (/\/classify$/.test(u)) return html504();
    if (/\/api\/inbox\/w1$/.test(u)) {
      if (++polls === 1) server = { ...server, status: 'failed', updated_at: '2026-10-22T10:01:00+02:00', error: { key: 'errors.inbox.badAnswer', params: {} } };
      return json({ entry: server });
    }
    return prevFetch(url, init);
  };
  let err = null;
  try { await Q.readDocument(entry, { pages: ['Rechnung'], ...fast }); } catch (e) { err = e; }
  const { t: tr } = await import(`${JS}/lib/i18n.js`);
  t('fehlgeschlagen: die Meldung des Servers', err?.message === tr('errors.inbox.badAnswer') && err.code === 'errors.inbox.badAnswer', err?.message);

  // ohne Änderung bis zur Obergrenze: erst dann ein Fehler (der Lauf nimmt das Dokument nicht noch einmal)
  server = { ...entry }; polls = 0;
  global.fetch = async (url, init) => {
    if (/\/api\/inbox\/w1$/.test(String(url))) { polls++; return json({ entry: server }); }
    return prevFetch(url, init);
  };
  let clock = 0;
  err = null;
  try {
    await Q.settle(async () => { throw Object.assign(new Error('504'), { status: 504 }); }, entry, 'type',
      { wait: { intervalMs: 5000, limitMs: 660000 }, sleep: async (ms) => { clock += ms; }, now: () => clock });
  } catch (e) { err = e; }
  t('Obergrenze: 660 s, alle 5 s gefragt, dann „keine Antwort“', err?.code === 'waited' && err.status === 504 && polls === 132
    && err.message === tr('inbox.wait.gaveUp'), `${polls} · ${err?.code} · ${err?.message}`);
  // ein Fehler mit Antwort (etwa 400) ist ein Fehler: kein Warten
  polls = 0; err = null;
  try { await Q.settle(async () => { throw Object.assign(new Error('bad'), { status: 400 }); }, entry, 'type', fast); } catch (e) { err = e; }
  t('Fehler mit Antwort: sofort, ohne Abfrage', err?.status === 400 && polls === 0);
  t('Abbruch vor der Antwort: 504, Netz, Zeitlimit — sonst nicht', Q.cutOff({ status: 504 }) && Q.cutOff({ status: 0, code: 'network' })
    && Q.cutOff({ status: 0, code: 'timeout' }) && !Q.cutOff({ status: 502 }) && !Q.cutOff({ status: 400 }));
  // noch einmal auslesen: der alte Stand (Art bekannt, ausgelesen) ist nicht das Ergebnis
  const old = { ...entry, type: 'invoice', status: 'read' };
  t('fertig erst mit geänderter Zeit und neuem Stand', Q.stepState(old, { ...old }, 'type') === null && Q.stepState(old, { ...old }, 'fields') === null
    && Q.stepState(entry, { ...entry }, 'type') === null
    && Q.stepState(entry, { ...entry, updated_at: 'x', type: null }, 'type') === null
    && Q.stepState(entry, { ...entry, updated_at: 'x', type: 'invoice' }, 'fields') === null
    && Q.stepState(entry, { ...entry, updated_at: 'x', type: 'invoice', status: 'read' }, 'fields') === 'done');
  // der Lauf: ohne Antwort nach dem Warten nicht noch einmal dasselbe Dokument (es bliebe vorgemerkt)
  const queued = [{ id: 'q1', status: 'queued', queued_at: '1', attachment_id: 'x1' }, { id: 'q2', status: 'queued', queued_at: '2', attachment_id: 'x2' }];
  global.fetch = async (url, init) => {
    if (/\/api\/inbox$/.test(String(url)) && (init?.method || 'GET') === 'GET') return json({ entries: queued, queue: { queued: queued.filter(e => e.status === 'queued').length } });
    return prevFetch(url, init);
  };
  const tries = [];
  const prog = await Q.runQueue({ read: async (e) => {
    tries.push(e.id);
    if (e.id === 'q1' && tries.filter(x => x === 'q1').length > 1) queued[0].status = 'read';   // nur bei einem zweiten Versuch „fertig“
    if (e.id === 'q1') throw Object.assign(new Error('keine Antwort'), { status: 504, code: 'waited' });
    queued[1].status = 'read';
    return {};
  } });
  t('Lauf: nach „keine Antwort“ weiter mit dem nächsten, nicht noch einmal', tries.join() === 'q1,q2' && prog.failed === 1 && prog.done === 1, tries.join());
  global.fetch = prevFetch;
  t('Server rechnet nach einem Abbruch zu Ende (ignore_user_abort)', /@ignore_user_abort\(true\)/.test(readFileSync(resolve(ROOT, 'src/Controllers/InboxController.php'), 'utf8')));
}

console.log('Textebene (pdf.js)');
t('Textstücke: Zeilenende = Umbruch, Stücke einer Zeile mit Leerzeichen', P.joinTextItems([{ str: 'Abschlag', hasEOL: false }, { str: '80,00 €', hasEOL: true },
  { str: '', hasEOL: true }, { str: 'Guthaben:', hasEOL: false }, { str: ' 548,54 €', hasEOL: false }]) === 'Abschlag 80,00 €\n\nGuthaben: 548,54 €');
t('JSDOM ohne DOMMatrix: kein pdf.js (nur der Link)', P.pdfSupported() === false);

// v3.9.0 — Stücke mit Lage (wie getTextContent sie liefert): nach x in der Zeile, Leerzeichen nach dem Abstand.
// Erfundene Zeilen nach dem Muster einer Wasserrechnung, die rechtsbündige Zahlen Zeichen für Zeichen von rechts zeichnet.
{
  const it = (str, x, w, { y = 397.7, h = 9, eol = false } = {}) => ({ str, transform: [h, 0, 0, h, x, y], width: w, height: h, hasEOL: eol });
  const sp = (x, w, y = 397.7) => ({ str: ' ', transform: [1, 0, 0, 1, x, y], width: w, height: 0, hasEOL: false });
  // Rückwärts gezeichnet: genau die Rohstücke aus dem Befund
  const back = [it('Rechnungsbetrag', 74.4, 71), sp(145.4, 379.7),
    it('6', 525.2, 5), it('5', 519.7, 5), it(',', 516.8, 2.5), it('4', 511.5, 5), it('3', 506, 5), it('2', 500.5, 5), it('.', 497.7, 2.5), it('1', 492.3, 5),
    sp(497.3, 34.3), it('€', 531.6, 5, { eol: true })];
  t('Lage: Zahl von rechts gezeichnet → „1.234,56 €“', P.joinTextItems(back) === 'Rechnungsbetrag 1.234,56 €', JSON.stringify(P.joinTextItems(back)));
  // Tabellenzeile rückwärts: Etikett am Ende des Stroms, Menge, Einheit mit Hochzahl, Preis — zwei Spalten auf gleicher Höhe
  const row = [it('³', 236.4, 3, { y: 401.2, h: 5 }), it('m', 229.8, 6.5), it('2', 222.2, 5), it('3', 216.7, 5),
    it('5', 316.5, 5), it('4', 311, 5), it(',', 308.2, 2.5), it('2', 302.7, 5), it('€', 324, 5), it('/', 329.6, 2.5), it('m', 332.6, 6.5), it('³', 339.2, 3, { y: 401.2, h: 5 }),
    it('Wasserverbrauch', 74.4, 70, { eol: true })];
  t('Lage: Tabellenzeile rückwärts → Etikett vorn, „32 m³ 2,45 €/m³“', P.joinTextItems(row) === 'Wasserverbrauch 32 m³ 2,45 €/m³', JSON.stringify(P.joinTextItems(row)));
  const days = [it('e', 140.5, 4.5), it('g', 136, 4.5), it('a', 131.5, 4.5), it('T', 126, 5.5), it('3', 112, 5), it('5', 106.5, 5), it('1', 101, 5, { eol: true })];
  t('Lage: „e g a T 3 5 1“ → „153 Tage“', P.joinTextItems(days) === '153 Tage', JSON.stringify(P.joinTextItems(days)));
  // Normale Zeile (links nach rechts, Leerzeichen-Stücke wie pdf.js sie einfügt): Text wie bis v3.8
  const plain = [it('Abschlag', 74.4, 38), sp(112.4, 2.5), it('ab', 114.9, 9.5), sp(124.4, 2.5), it('15.05.2026:', 126.9, 47), it('165,00 €', 300, 36, { eol: true }),
    it('Guthaben:', 74.4, 41), it(' 548,54 €', 115.4, 40, { eol: true })];
  const legacy = (items) => P.joinTextItems(items.map(({ str, hasEOL }) => ({ str, hasEOL })));
  t('Lage: Zeilen ohne Rückwärtslauf wie bis v3.8', P.joinTextItems(plain) === legacy(plain) && legacy(plain) === 'Abschlag ab 15.05.2026: 165,00 €\nGuthaben: 548,54 €', JSON.stringify(P.joinTextItems(plain)));
  // Zwei Spalten einer Tabelle auf gleicher Höhe, vorwärts: wie bis v3.8
  const cols = [it('Trinkwasser', 74.4, 50), it('224,70 ct/m³', 300, 52), it('13,375 €', 420, 36, { eol: true })];
  t('Lage: Spalten auf gleicher Höhe (vorwärts) mit einem Leerzeichen', P.joinTextItems(cols) === 'Trinkwasser 224,70 ct/m³ 13,375 €');
  // gesperrtes Wort mit kleinen Lücken (keine Leerzeichen-Stücke): ein Wort; die Wortlücke davor bleibt
  const word = 'Rechnung'.split('').map((c, i) => it(c, 120 + i * 6.2, 5.2));
  const spaced = [it('Ihre', 74.4, 20), ...word.slice(0, -1), { ...word[word.length - 1], hasEOL: true }];
  t('Lage: gesperrtes Wort „R e c h n u n g“ → „Rechnung“', P.joinTextItems(spaced) === 'Ihre Rechnung', JSON.stringify(P.joinTextItems(spaced)));
  // zwei Textspalten nebeneinander: jede Stromzeile für sich, nichts wird verschränkt — auch ohne Zeilenende im Strom
  const two = [it('Links eins', 50, 45, { y: 700, eol: true }), it('Links zwei', 50, 45, { y: 688, eol: true }),
    it('Rechts eins', 300, 50, { y: 700, eol: true }), it('Rechts zwei', 300, 50, { y: 688, eol: true })];
  t('Lage: zwei Textspalten bleiben getrennt', P.joinTextItems(two) === 'Links eins\nLinks zwei\nRechts eins\nRechts zwei', JSON.stringify(P.joinTextItems(two)));
  const noEol = [it('Links eins', 50, 45, { y: 700 }), it('Rechts eins', 300, 50, { y: 700 }), it('Links zwei', 50, 45, { y: 688 }), it('Rechts zwei', 300, 50, { y: 688, eol: true })];
  t('Lage: ohne Zeilenende im Strom wird nur innerhalb einer Höhe sortiert', P.joinTextItems(noEol) === legacy(noEol)
    && legacy(noEol) === 'Links eins Rechts eins Links zwei Rechts zwei', JSON.stringify(P.joinTextItems(noEol)));
  // Stücke ohne Lage (Tests, ältere Aufrufer), auch gemischt: wie bis v3.8
  const mixed = [{ str: 'Abschlag', hasEOL: false }, it('1', 120, 5), it('8', 114.5, 5), it('0', 125.5, 5, { eol: true })];
  t('Lage: eine Zeile mit Stücken ohne Lage bleibt, wie sie war', P.joinTextItems(mixed) === 'Abschlag 1 8 0', JSON.stringify(P.joinTextItems(mixed)));
}
const vendor = resolve(ROOT, 'public/vendor/pdfjs');
const head = readFileSync(resolve(vendor, 'pdf.min.js'), 'utf8').slice(0, 2000);
t('pdf.js mitgeliefert mit Lizenz (Apache-2.0)', /Apache License, Version 2\.0/.test(head) && /Apache License/.test(readFileSync(resolve(vendor, 'LICENSE'), 'utf8')));
const credits = readFileSync(resolve(ROOT, 'CREDITS.md'), 'utf8');
const version = (head.match(/pdfjsVersion = (\d+\.\d+\.\d+)/) || [])[1];
t('CREDITS nennt pdf.js mit der mitgelieferten Version', !!version && credits.includes(`PDF.js](https://mozilla.github.io/pdf.js/) ${version}`), version);
const read = (rel) => readFileSync(resolve(ROOT, rel), 'utf8');
t('Architektur (DE+EN) nennt pdf.js mit der mitgelieferten Version', ['docs/entwicklung/architektur.md', 'docs/en/entwicklung/architektur.md']
  .every(f => read(f).includes(`PDF.js ${version}`)));
// pdf.js nur im Posteingang: kein statischer Import, nicht in der Shell, nicht vorab im Service Worker
const pdfLib = read('public/js/lib/pdf.js');
const jsFiles = (dir) => readdirSync(resolve(ROOT, dir), { withFileTypes: true })
  .flatMap(d => d.isDirectory() ? jsFiles(`${dir}/${d.name}`) : d.name.endsWith('.js') ? [`${dir}/${d.name}`] : []);
const pdfUsers = jsFiles('public/js').filter(f => f !== 'public/js/lib/pdf.js' && /vendor\/pdfjs/.test(read(f)));
t('pdf.js lädt nur lib/pdf.js, und zwar bei Bedarf (dynamischer Import)', pdfUsers.length === 0
  && /import\(`\.\.\/\.\.\/vendor\/pdfjs\/pdf\.min\.js/.test(pdfLib) && !/^import .*vendor\/pdfjs/m.test(pdfLib), pdfUsers.join(', '));
t('pdf.js nicht in index.php und nicht vorab im Service Worker', !/pdfjs/.test(read('index.php')) && !/pdfjs/.test(read('sw.js')));
t('pdf.js ohne eval (isEvalSupported: false)', /isEvalSupported: false/.test(pdfLib));
// Doku: empfohlenes Modell, Speicherbedarf, Verwalter-Schlüssel — wie im Code
const model = (read('src/Services/Inbox/LocalProvider.php').match(/RECOMMENDED_MODEL = '([^']+)'/) || [])[1];
const guides = ['docs/anleitungen/posteingang.md', 'docs/en/anleitungen/posteingang.md'].map(read);
t('Anleitung (DE+EN) empfiehlt das Modell aus dem Code und nennt rund 18 GB', model === 'qwen3.8'
  && guides.every(g => g.includes(`ollama pull ${model}`) && /18 GB/.test(g)), model);
const admin = (read('src/bootstrap.php').match(/ADMIN_SETTINGS = \[([^\]]+)\]/) || [])[1] || '';
t('inbox_provider nur für Verwalter — im Code und in der Einstellungs-Referenz (DE+EN)', /'inbox_provider'/.test(admin)
  && ['docs/referenz/einstellungen.md', 'docs/en/referenz/einstellungen.md'].every(f => /\(since v3\.7\.0\) `inbox_provider`|\(seit v3\.7\.0\) `inbox_provider`/.test(read(f))));
const timeout = (read('src/Services/SettingsService.php').match(/'inbox_timeout_s'\s*=>\s*(\d+)/) || [])[1];
t('Zeitlimit je Schritt: Standard 300 wie in der Doku, unter den 310 s von nginx', timeout === '300'
  && /fastcgi_read_timeout\s+310s?/.test(read('docker/nginx.conf')) && guides.every(g => /310/.test(g)), timeout);
try {
  const lib = await import(pathToFileURL(resolve(vendor, 'pdf.min.js')).href);
  lib.GlobalWorkerOptions.workerSrc = pathToFileURL(resolve(vendor, 'pdf.worker.min.js')).href;
  const att = readdirSync(resolve(ROOT, 'demo-data/attachments')).find(f => f.endsWith('.pdf'));
  const doc = await lib.getDocument({ data: new Uint8Array(readFileSync(resolve(ROOT, 'demo-data/attachments', att))), isEvalSupported: false, verbosity: 0 }).promise;
  const pages = await P.textPages(doc);
  t('Beispieldokument: drei Seiten mit Textebene', pages.length === 3 && /Jahresabrechnung Erdgas 2025/.test(pages[0]) && /Ergänzende Bedingungen/.test(pages[2]));
  // v3.9.0 — mit Lage gelesen: ein Dokument ohne rückwärts gezeichnete Zeilen ergibt genau den Text wie bis v3.8
  const before = [];
  for (let i = 1; i <= doc.numPages; i++) {
    const items = (await (await doc.getPage(i)).getTextContent()).items;
    before.push(P.joinTextItems(items.map(({ str, hasEOL }) => ({ str, hasEOL }))));
  }
  const diff = pages.findIndex((p, i) => p !== before[i]);
  t('Beispieldokument: mit Lage derselbe Text wie ohne (Byte für Byte)', diff === -1, diff === -1 ? '' : `Seite ${diff + 1}`);
  // v3.9.0 — ein erfundenes PDF, das eine rechtsbündige Zahl Zeichen für Zeichen von rechts zeichnet (Helvetica 9 pt):
  // textPages gibt die Lage mit, der Text steht in der richtigen Reihenfolge; eine Zeile von links bleibt wie bisher
  const tinyPdf = (content) => {
    const objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
      '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
      '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
      `<< /Length ${Buffer.byteLength(content, 'latin1')} >>\nstream\n${content}\nendstream`];
    let pdf = '%PDF-1.4\n';
    const offs = [];
    objs.forEach((o, i) => { offs.push(Buffer.byteLength(pdf, 'latin1')); pdf += `${i + 1} 0 obj\n${o}\nendobj\n`; });
    const xref = Buffer.byteLength(pdf, 'latin1');
    pdf += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${offs.map(o => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
    return new Uint8Array(Buffer.from(pdf, 'latin1'));
  };
  const w = { '1': 5.004, '2': 5.004, '3': 5.004, '4': 5.004, '5': 5.004, '6': 5.004, ',': 2.502, '.': 2.502 };
  let x = 530.2;
  const glyphs = [...'1.234,56'].reverse().map((c) => { x -= w[c]; return `1 0 0 1 ${x.toFixed(3)} 397.7 Tm (${c}) Tj`; });
  const stream = ['BT /F1 9 Tf 1 0 0 1 74.4 397.7 Tm (Rechnungsbetrag) Tj', ...glyphs, '1 0 0 1 532.2 397.7 Tm (\x80) Tj ET',
    'BT /F1 9 Tf 1 0 0 1 74.4 380 Tm (Abschlag ab 15.05.2026) Tj 1 0 0 1 494.18 380 Tm (165,00 \x80) Tj ET'].join('\n');
  const tiny = await lib.getDocument({ data: tinyPdf(stream), isEvalSupported: false, verbosity: 0 }).promise;
  const [tinyText] = await P.textPages(tiny);
  t('PDF von rechts gezeichnet: „Rechnungsbetrag 1.234,56 €“', /^Rechnungsbetrag 1\.234,56 €$/m.test(tinyText), JSON.stringify(tinyText));
  t('PDF: Zeile von links wie bisher', /^Abschlag ab 15\.05\.2026 165,00 €$/m.test(tinyText), JSON.stringify(tinyText));
  const norm = (s) => String(s).toLowerCase().replace(/\s+/g, ' ').trim();
  const text = norm(pages.join(' '));
  const [entry] = JSON.parse(readFileSync(resolve(ROOT, 'demo-data/inbox.json'), 'utf8'));
  const quotes = [];
  for (const f of Object.values(entry.extraction.fields)) {
    if (f.quote) quotes.push(f.quote);
    for (const i of f.items || []) if (i.quote) quotes.push(i.quote);
  }
  const missing = quotes.filter(q => !text.includes(norm(q)));
  t('jede Belegstelle der vorberechneten Auslesung steht in der Textebene von pdf.js', quotes.length > 20 && missing.length === 0, missing.slice(0, 3).join(' | ') || `${quotes.length} Zitate`);
  t('Beispieldokument: 3 Marken, darunter der Zahlendreher beim Verbrauch', entry.extraction.flags === 3
    && entry.extraction.fields.consumption.reasons.includes('value_not_in_quote'));
} catch (e) { t('pdf.js in Node', false, e.message); }

console.log(`\ninbox.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
