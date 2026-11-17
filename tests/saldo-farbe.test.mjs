// =====================================================================
// v3.12.0 (Sichtung M1, S1) — ein Saldo ist grün oder rot, nach seinem Vorzeichen.
//
// Neutral nur bei genau 0 („ausgeglichen“) oder wenn kein Saldo vorliegt, und dann
// ohne Betrag. Das Wort bleibt, damit Farbe nicht das einzige Zeichen ist; dichte
// Tabellen tragen das Vorzeichen und eine Legende.
//
// LISTE nennt jede Stelle, die einen Saldo zeigt. Eine neue Datei, die ein Saldo-Feld
// liest, lässt den Test scheitern, bis sie hier steht.
// Beträge erfunden. Aufruf: node tests/saldo-farbe.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, relative } from 'node:path';
import { readFileSync, readdirSync, statSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..');
const JS = resolve(ROOT, 'public', 'js');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`);
};

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><main id="view"></main></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; } catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
global.fetch = async (url) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  return new Response(JSON.stringify({ success: true, data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const S = await import(`${JS}/lib/semantics.js`);
const U = await import(`${JS}/views/utility.js`);
const O = await import(`${JS}/views/contracts-overview.js`);
const D = await import(`${JS}/views/dashboard.js`);

const norm = (s) => String(s).replace(/\s+/g, ' ').trim();
const frag = (html) => { const d = document.createElement('div'); d.innerHTML = html; return d; };
const gas = { key: 'gas', label: 'Gas', accounting_kind: 'consumption', consumption_unit: 'kWh', has_contracts: true };
const pv = { key: 'pv_einspeisung', label: 'PV', accounting_kind: 'feed_in', consumption_unit: 'kWh', has_contracts: true };

// ── Das Vorzeichen entscheidet ───────────────────────────────────────
const credit = S.balanceView(-12.34, gas), due = S.balanceView(12.34, gas), even = S.balanceView(0, gas);
t('Guthaben grün, mit Wort', credit.cls === 'ton-ok' && credit.credit === true && credit.word === 'Guthaben');
t('Nachzahlung rot, mit Wort', due.cls === 'ton-fehler' && due.credit === false && due.word === 'Nachzahlung');
t('Ein Cent ist schon ein Saldo', S.balanceView(0.01, gas).cls === 'ton-fehler' && S.balanceView(-0.01, gas).cls === 'ton-ok');
t('Genau 0: neutral, ohne Betrag', even.credit === null && even.cls === 'muted' && !/\d/.test(even.signed), even.signed);
t('Kein Saldo: nichts', S.balanceView(null, gas) === null && S.balanceView(undefined, gas) === null && S.balanceView('x', gas) === null);
t('Einspeisung: Anspruch grün, Rückforderung rot', S.balanceView(50, pv).cls === 'ton-ok' && S.balanceView(-50, pv).cls === 'ton-fehler' && S.balanceView(-50, pv).word === 'Rückforderung');

// ── Fertiges HTML ────────────────────────────────────────────────────
let el = frag(S.balanceHtml(-12.34, gas)).firstElementChild;
t('balanceHtml: Wort, Betrag, Farbe', el.className === 'ton-ok' && /^Guthaben 12,34\s€$/.test(norm(el.textContent)), el.outerHTML);
el = frag(S.balanceHtml(0, gas)).firstElementChild;
t('balanceHtml bei 0: nur das Wort', el.className === 'muted' && !/\d/.test(el.textContent), el.outerHTML);
t('balanceHtml ohne Saldo: leer', S.balanceHtml(null, gas) === '');

// ── Spanne über die Null: beide Farben, derselbe Wortlaut ────────────
const over = { projected_range: [-768, 30] };
el = frag(S.uncertainRangeHtml(over));
t('Spanne über die Null: grün und rot', !!el.querySelector('.ton-ok') && !!el.querySelector('.ton-fehler'), el.innerHTML);
t('Spanne: derselbe Wortlaut wie im Text', norm(el.textContent) === norm(S.uncertainRangeText(over)), norm(el.textContent));
el = frag(S.uncertainRangeHtml({ projected_range: [-300, -100] }));
t('Zwei Guthaben: beide grün, keine rote Stelle', el.querySelectorAll('.ton-ok').length === 2 && !el.querySelector('.ton-fehler'));
t('Keine Spanne: leer', S.uncertainRangeHtml(null) === '' && S.uncertainRangeHtml({ projected_range: [1] }) === '');
t('tHtml maskiert den Rest', S.tHtml('scenario.kpi.balanceBase', {}, { amount: '<b>x</b>' }).includes('<b>x</b>'));

// ── Schlüssel des Urteils ────────────────────────────────────────────
t('Urteil nach Vorzeichen, ohne 5-€-Toleranz', S.verdictKey(3.5, gas) === 'surcharge' && S.verdictKey(-3.5, gas) === 'refund' && S.verdictKey(0, gas) === 'balanced');
t('Urteil Einspeisung', S.verdictKey(3.5, pv) === 'payout' && S.verdictKey(-3.5, pv) === 'reclaim');

// ── Kosten & Verträge → Verträge & Abschläge ─────────────────────────
const row = (x) => ({ contract_id: 'c1', provider: 'Musterwerke', tariff_name: 'Basis', is_current: true, start: '2026-01-01', end: '2026-12-31', settle_on: '2026-12-31', ...x });
const block = (c, u = gas) => frag(O.meterBlock(u, { id: 'm1', name: 'Hauptzähler' }, [c], false));
let b = block(row({ projected_end_balance: -843.49, verdict: 'refund' }));
t('Übersicht: Guthaben grün', !!b.querySelector('.contract-overview__expected strong.ton-ok') && /Guthaben 843,49/.test(norm(b.textContent)));
b = block(row({ projected_end_balance: 22.5, verdict: 'surcharge' }));
t('Übersicht: Nachzahlung rot', !!b.querySelector('.contract-overview__expected strong.ton-fehler') && /Nachzahlung 22,50/.test(norm(b.textContent)));
b = block(row({ projected_end_balance: 3.5, verdict: 'balanced' }));
t('Übersicht: unter 5 € nicht neutral', !!b.querySelector('.contract-overview__expected strong.ton-fehler') && /Nachzahlung 3,50/.test(norm(b.textContent)), norm(b.textContent));
b = block(row({ projected_end_balance: 0, verdict: 'balanced' }));
const evenText = norm(b.querySelector('.contract-overview__expected strong').textContent);
t('Übersicht: genau 0 neutral, ohne Betrag', evenText === 'Ausgeglichen' && !b.querySelector('.contract-overview__expected .ton-ok, .contract-overview__expected .ton-fehler'), evenText);
b = block(row({ projected_end_balance: -300, verdict: 'refund', projection_uncertain: { projected_range: [-486, -291], reasons: ['poor_fit'] } }));
t('Übersicht: unsichere Spanne in Farbe', b.querySelectorAll('[data-role="expected-range"] .ton-ok').length === 2, b.innerHTML);
b = block(row({ projected_end_balance: -100, verdict: 'refund', projection_uncertain: { projected_range: [-100, 40], reasons: ['poor_fit'] } }));
t('Übersicht: Spanne über die Null in beiden Farben', !!b.querySelector('[data-role="expected-range"] .ton-ok') && !!b.querySelector('[data-role="expected-range"] .ton-fehler'));
b = block(row({ projected_end_balance: 12, verdict: 'payout' }), pv);
t('Übersicht: Auszahlung der Einspeisung grün', !!b.querySelector('.contract-overview__expected strong.ton-ok') && /Auszahlung 12,00/.test(norm(b.textContent)), norm(b.textContent));

// ── Vertragstabelle der Verbrauchsart (auch „Beendet“) ───────────────
const tableRow = (x) => {
  const tb = frag(U.contractsTable([{ contract_id: 'c1', provider: 'Musterwerke', phase: 'past', is_past: true, start: '2024-01-01', end: '2024-12-31',
    cost_to_date: 100, advance_paid: 100, current_balance: null, projected_end_balance: null, ...x }], gas));
  return [...tb.querySelectorAll('tbody tr[data-group-row] td')].slice(-2);
};
let [cur, proj] = tableRow({ current_balance: -91, projected_end_balance: -91 });
t('Tabelle: Guthaben grün mit +', cur.classList.contains('ton-ok') && norm(cur.textContent).startsWith('+91,00'), cur.outerHTML);
[cur, proj] = tableRow({ current_balance: 120, projected_end_balance: 120 });
t('Tabelle: Nachzahlung rot mit −', proj.classList.contains('ton-fehler') && norm(proj.textContent).startsWith('−120,00'), proj.outerHTML);
[cur, proj] = tableRow({ current_balance: 0, projected_end_balance: 0 });
t('Tabelle: genau 0 ohne Betrag', cur.classList.contains('muted') && !/\d/.test(cur.textContent), cur.outerHTML);
[cur, proj] = tableRow({ current_balance: null, projected_end_balance: null });
t('Tabelle: kein Saldo ohne Betrag, neutral', norm(cur.textContent) === '–' && cur.classList.contains('muted'));
[cur, proj] = tableRow({ current_balance: -10, projected_end_balance: -10, projection_uncertain: { projected_range: [-20, 30], reasons: ['poor_fit'] } });
t('Tabelle: Spanne in beiden Farben', !!proj.querySelector('.ton-ok') && !!proj.querySelector('.ton-fehler'), proj.outerHTML);

// v3.12.0 (Sichtung S2) — die Zahl ist der Saldo am Vertragsende, kein Preisvergleich; die Legende sagt es
const pastTable = frag(U.contractsTable([{ contract_id: 'c1', provider: 'Musterwerke', phase: 'past', start: '2024-01-01', end: '2024-12-31', current_balance: -5, projected_end_balance: -5 }], gas));
t('Beendet: Legende nennt die Bedeutung', /Saldo gilt am Vertragsende/.test(pastTable.querySelector('[data-role="past-legend"]')?.textContent || ''), pastTable.textContent.slice(-200));
const curTable = frag(U.contractsTable([{ contract_id: 'c1', provider: 'Musterwerke', phase: 'current', is_current: true, start: '2026-01-01', end: '2026-12-31', current_balance: -5, projected_end_balance: -5 }], gas));
t('Ohne beendeten Vertrag keine Legende', !curTable.querySelector('[data-role="past-legend"]'));

// v3.13.0 — Frühere Verträge: Deckt eine gebuchte Rechnung das Ende, ist der Vertrag abgerechnet (0,00 €, der Satz mit
// dem Tag der Rechnung, Verbraucht und Bezahlt nach der Rechnung „–“, kein „mit Sonderzahlungen“)
const P = await import(`${JS}/views/contracts-past.js`);
const pastCells = (x) => [...frag(`<table><tbody>${P.pastRowHtml(P.pastRow({ contract_id: 'c1', provider: 'Musterwerke', start: '2023-01-01',
  end: '2024-12-31', cost_to_date: 0, advance_paid: 0, current_balance: 0, projected_end_balance: 0, verdict: 'balanced', ...x }, gas,
  { meter: 'Z', stored: null, bills: [], targetId: 'm1', isGroup: false }))}</tbody></table>`).querySelectorAll('td')];
let pc = pastCells({ settled_until: '2024-12-31', special_payment_net: 0 });
t('Frühere Verträge: abgerechnet mit der Rechnung bis …', /abgerechnet mit der Rechnung bis 31\.12\.2024/.test(norm(pc[6].textContent)), norm(pc[6].textContent));
t('Frühere Verträge: abgerechnet, Verbraucht und Bezahlt –', norm(pc[4].textContent) === '–' && norm(pc[5].textContent) === '–', `${pc[4].textContent} | ${pc[5].textContent}`);
pc = pastCells({ current_balance: -71.16, projected_end_balance: -71.16, cost_to_date: 768.84, advance_paid: 840, special_payment_net: 0 });
// dieselbe Zeile unter „Beendet“ der Verbrauchsart: Satz statt Saldo, Verbraucht und Bezahlt „–“
const settledRow = [...frag(U.contractsTable([{ contract_id: 'c1', provider: 'Musterwerke', phase: 'past', is_past: true, start: '2023-01-01',
  end: '2024-12-31', cost_to_date: 0, advance_paid: 0, current_balance: 0, projected_end_balance: 0, settled_until: '2024-12-31' }], gas))
  .querySelectorAll('tbody tr[data-group-row] td')];
t('Beendet: abgerechnet mit der Rechnung bis …', /abgerechnet mit der Rechnung bis 31\.12\.2024/.test(norm(settledRow.find(td => td.dataset.role === 'settled')?.textContent || '')),
  settledRow.map(td => norm(td.textContent)).join(' | '));
t('Beendet: abgerechnet, Verbraucht und Bezahlt –', norm(settledRow[5].textContent) === '–' && norm(settledRow[6].textContent) === '–', `${settledRow[5].textContent} | ${settledRow[6].textContent}`);
t('Frühere Verträge: offener Rest ohne Satz zur Rechnung', !/abgerechnet/.test(pc[6].textContent) && /71,16/.test(pc[6].textContent) && /768,84/.test(pc[4].textContent), norm(pc[6].textContent));

// ── „Muss ich nachzahlen?“ ───────────────────────────────────────────
const byKey = new Map([['gas', gas]]);
const money = (c) => frag(D.beginnerMoneyLines([{ utility: 'gas', name: 'Z', contract: { contract_id: 'c', provider: 'x', ...c } }], byKey).join(''));
b = money({ projected_end_balance: -0.4 });
t('Einsteiger: unter einem Euro nicht neutral', !!b.querySelector('.ton-ok') && /0,40/.test(norm(b.textContent)), norm(b.textContent));
b = money({ projected_end_balance: 0 });
t('Einsteiger: genau 0 neutral', !b.querySelector('.ton-ok, .ton-fehler') && !/\d\s€/.test(norm(b.textContent)), norm(b.textContent));
b = money({ projected_end_balance: -300, projection_uncertain: { projected_range: [-500, 10], reasons: ['poor_fit'] } });
t('Einsteiger: Spanne in beiden Farben', !!b.querySelector('[data-role="money-uncertain"] .ton-ok') && !!b.querySelector('[data-role="money-uncertain"] .ton-fehler'));

// ── v3.13.0 — Mietverhältnis ohne Preise (`price_missing`): kein Guthaben aus der ganzen Vorauszahlung ──
{
  const T = await import(`${JS}/views/tenancy.js`);
  const budget = { period_from: '2026-01-01', period_to: '2026-12-31', expected_eur: 0, prepaid_eur: 1560, projected_result_eur: -1560,
    suggested_prepayment_eur: 0, risk: null, assumptions: ['no_meters'], months: [], price_missing: true };
  b = frag(T.budgetCard(budget));
  t('Mietverhältnis ohne Preise: „Preis fehlt“, kein Guthaben, neutral', /Preis fehlt/.test(norm(b.querySelector('[data-role="tenancy-result"]').textContent))
    && !/Guthaben|1\.560,00/.test(norm(b.querySelector('[data-role="tenancy-result"]').textContent)) && !b.querySelector('[data-role="tenancy-result"].ton-ok')
    && !!b.querySelector('[data-role="tenancy-price-missing"]'), norm(b.textContent).slice(0, 300));
  b = frag(D.tenancyAnswerHtml(budget));
  t('Übersicht Miete ohne Preise: kein „reicht“, der Hinweis', !!b.querySelector('[data-role="rent-price-missing"]') && !b.querySelector('.ton-ok'), norm(b.textContent));
  // v3.13.0 — „Muss ich nachzahlen?“ mit nur dem Anfangsstand: die Zeile mit „Noch zu wenig Ablesungen“, kein Betrag
  b = money({ projected_end_balance: null, projection_missing: 'too_few_readings' });
  t('Einsteiger: nur der Anfangsstand nennt keinen Betrag', !!b.querySelector('[data-role="money-too-few"]') && !b.querySelector('.ton-ok, .ton-fehler')
    && /Noch zu wenig Ablesungen/.test(norm(b.textContent)), norm(b.textContent));
  b = frag(T.budgetCard({ ...budget, price_missing: undefined, projected_result_eur: -360, expected_eur: 1200 }));
  t('Gegenprobe: mit bekannten Kosten das Guthaben in Grün', !!b.querySelector('[data-role="tenancy-result"].ton-ok'), norm(b.textContent).slice(0, 200));
}

// ── Jede Stelle, die einen Saldo zeigt ───────────────────────────────
/**
 * Datei → wie sie ihn zeigt. `farbe`: Muster, das die Farbe belegt; `text`: bewusst nur Wort
 * (Satz in einem Hinweis, Bild, Papier), mit Grund. Alle anderen Dateien lesen keinen Saldo.
 */
const LISTE = {
  'views/utility.js': { farbe: /balanceHtml\(|uncertainRangeHtml\(|bv\.cls|cumView\.cls|yb\.cls|balance-col__value--credit/, wo: 'Kachel Abschläge, Saldo-Karte, Satz „Bis heute“, Vertragstabelle (auch Beendet), Monatstabelle, Tabelle unter dem Saldo-Verlauf' },
  'views/contracts-overview.js': { farbe: /uncertainRangeHtml\(|bv\.cls/, wo: 'Kosten & Verträge → Verträge & Abschläge' },
  'views/contracts-past.js': { farbe: /bv\.cls/, wo: 'Kosten & Verträge → Frühere Verträge' },
  'views/dashboard.js': { farbe: /uncertainRangeHtml\(|'ton-ok' : 'ton-fehler'/, wo: 'Übersicht: Muss ich nachzahlen? (Einsteiger)' },
  'views/forecast.js': { farbe: /balanceHtml\(|balView\.cls/, wo: 'Prognose-Werkstatt: Kachel, Vergleichswert, Monatstabelle' },
  'views/tenancy.js': { farbe: /rv\.cls|balanceView\(m\.balance_eur/, wo: 'Mietverhältnis: Ergebnis und Monatstabelle' },
  'views/report-print.js': { farbe: /data-saldo|saldoTon\(/, wo: 'Jahresbericht: Salden je Verbrauchsart' },
  'components/explainer.js': { text: 'Bild: Balken in der Farbe der Richtung (ex-gap--credit, ex-gap--due), der Satz darunter trägt das Wort' },
  'views/scenario-print.js': { text: 'Druck der Werkstatt: Wort und Betrag, Papier oft ohne Farbe' },
  'lib/heat-limit.js': { text: 'Satz im Hinweis zur Heizgrenze (vorher, nachher): Wort, keine Kennzahl' },
  'lib/ha-snippet.js': { text: 'Vorlage für Home Assistant: nennt das Feld, zeigt nichts' },
  'lib/billing-period.js': { text: 'Rechnet nur, zeigt nichts' },
  'lib/semantics.js': { farbe: /ton-ok/, wo: 'die Bausteine selbst' },
};
const FELD = /\b(current_balance|projected_end_balance|settle_balance|balance_running|scen_end_balance|base_end_balance|cumulative_balance|balance_eur|yearBalance|balance_path|balanceView|balanceWords|balances)\b/;
const walk = (d) => readdirSync(d).flatMap(f => { const p = resolve(d, f); return statSync(p).isDirectory() ? walk(p) : p.endsWith('.js') ? [p] : []; });
const gefunden = new Set();
for (const p of walk(JS)) {
  const rel = relative(JS, p);
  const src = readFileSync(p, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  if (!FELD.test(src) || rel === 'api.js') continue;
  gefunden.add(rel);
  const e = LISTE[rel];
  t(`Saldo-Stelle steht in der Liste: ${rel}`, !!e);
  if (e?.farbe) t(`${rel}: zeigt die Farbe des Vorzeichens`, e.farbe.test(src));
}
for (const k of Object.keys(LISTE)) t(`Eintrag noch nötig: ${k}`, gefunden.has(k));

console.log(`\n  saldo-farbe: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
