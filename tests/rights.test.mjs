// =====================================================================
// v3.11.0 (F1035, F1036, Paket basis) — „Deine Rechte als Kunde“ ohne Server:
// Knopf „Brief entwerfen“ (lib/letter.js, der eine Einstieg für alle Ansichten),
// Fußzeile mit den Normen als Link zur Quelle („Hinweis, keine Rechtsberatung“),
// Befund unter „Zu tun“ (Agenda-Art `rights`) und als Empfehlung der Kategorie
// `recht`. Die Befunde sind erfunden (Form wie GET /api/rights).
//
// Aufruf: node tests/rights.test.mjs
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

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><main id="view"></main></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}

const norms = [
  { key: 'de.enwg.41.4', norm: '§ 41 Abs. 4 Satz 2 Nr. 6, Abs. 1 Satz 2 Nr. 5 EnWG', url: 'https://www.gesetze-im-internet.de/enwg_2005/__41.html', checked_on: '2026-11-03', status: 'W' },
  { key: 'de.vz.bonus', norm: '§ 40c Abs. 3 EnWG (Auslegung der Verbraucherzentrale)', url: 'https://www.verbraucherzentrale.de/x', checked_on: '2026-10-01', status: 'S' },
];
const rec = {
  id: 'rights-bonus_missing-0123456789ab', severity: 'warning', category: 'recht', title: 'Bonus nicht gutgeschrieben', detail: 'In der Rechnung fehlt der Bonus.',
  evidence: { utility: 'strom', meter_id: 'm_strom_main', contract_id: 'c_1', bill_id: 'b_1', check: 'bonus_missing', finding_id: 'rights-bonus_missing-0123456789ab',
    date: '2026-10-20', deadline: null, amount_eur: 100, letter: 'complaint', norms, footer: '…', help: 'Hilfe: Verbraucherzentrale, Schlichtungsstelle Energie.',
    href: '#/bill-check?utility=strom&meter=m_strom_main' },
};
const other = { id: 'r4_x', severity: 'info', category: 'anomalie', title: 'Ungewöhnlich hoch', detail: '…', evidence: {} };

global.fetch = async (url) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  if (/\/api\/recommendations/.test(u)) return new Response(JSON.stringify({ success: true, data: [rec, other] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  return new Response(JSON.stringify({ success: true, data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const L = await import(`${JS}/lib/letter.js`);

console.log('Brief entwerfen');
const finding = { id: rec.id, letter: 'complaint', title: 'Bonus <fehlt>' };
const html = L.letterButton(finding);
t('Knopf mit Vorlage und Befund', /data-role="letter-button"/.test(html) && /data-letter="complaint"/.test(html) && html.includes(`data-finding="${rec.id}"`));
t('Text „Brief entwerfen“, Name der Vorlage als Titel', /Brief entwerfen<\/button>$/.test(html) && /title="Beanstandung einer Rechnung"/.test(html));
t('Titel des Befunds im Namen, escaped', /aria-label="Brief entwerfen: Bonus &lt;fehlt&gt;"/.test(html));
t('kein Brief: kein Knopf', L.letterButton({ id: rec.id, letter: null }) === '' && L.letterButton(null) === '');
t('unbekannte Vorlage: kein Knopf', L.letterButton({ id: rec.id, letter: 'mahnung' }) === '');
t('ohne ID: kein Knopf', L.letterButton({ id: '', letter: 'complaint' }) === '');
t('Vorlagen wie RightsService::LETTERS', L.LETTER_KINDS.join() === 'special_termination,advance_adjustment,estimate_query,complaint,objection');
t('Empfehlung → Befund', JSON.stringify(L.findingOfRec(rec)) === JSON.stringify({ id: rec.id, letter: 'complaint', title: rec.title })
  && L.findingOfRec(other) === null);
let opened = null;
window.addEventListener('et:letter', (e) => { opened = e.detail; });
const box = document.createElement('div');
box.innerHTML = html;
document.body.appendChild(box);
L.bindLetterButtons(box);
L.bindLetterButtons(box);   // zweimal binden: ein Klick öffnet einmal
let count = 0;
window.addEventListener('et:letter', () => { count++; });
box.querySelector('button').click();
t('Klick öffnet den Brief (Ereignis et:letter mit Befund)', opened?.id === rec.id && opened?.letter === 'complaint' && count === 1, JSON.stringify(opened));
t('openLetter ohne Brief: nichts', L.openLetter({ id: rec.id, letter: null }) === false);
box.remove();

console.log('Fußzeile');
const foot = L.rightsFooterHtml(rec.evidence);
const fd = document.createElement('div');
fd.innerHTML = foot;
const p = fd.querySelector('[data-role="rights-footer"]');
t('„Hinweis, keine Rechtsberatung. Grundlage: …“ mit Stand (ältester Abruf) und Hilfe',
  /^Hinweis, keine Rechtsberatung\. Grundlage: § 41 .* EnWG, § 40c Abs\. 3 EnWG \(Auslegung der Verbraucherzentrale\) \(Stand 01\.10\.2026\)\. Hilfe: Verbraucherzentrale, Schlichtungsstelle Energie\.$/.test(p?.textContent || ''),
  p?.textContent);
const links = [...fd.querySelectorAll('a[data-role="rights-norm"]')];
t('jede Norm ein Link zur Quelle, neues Fenster ohne Opener', links.length === 2 && links[0].getAttribute('href') === norms[0].url
  && links.every(a => a.target === '_blank' && a.rel === 'noopener noreferrer'));
const evil = L.rightsFooterHtml({ norms: [{ norm: '<b>x</b>', url: 'javascript:alert(1)', checked_on: '2026-11-03' }] });
t('keine Quelle ohne https, Text escaped', !/href=/.test(evil) && /&lt;b&gt;x&lt;\/b&gt;/.test(evil));
t('ohne Normen: keine Fußzeile', L.rightsFooterHtml({}) === '' && L.rightsFooterHtml(null) === '');

console.log('„Zu tun“');
const D = await import(`${JS}/views/dashboard.js`);
const ev = (extra = {}) => ({ uid: rec.id, kind: 'rights', date: '2026-11-03', title: 'Bonus nicht gutgeschrieben (Agenda)', title_key: 'rights.check.bonus_missing',
  params: {}, severity: 'due', due_now: true, href: rec.evidence.href, can_mark_done: true, done: null, occurrence: `${rec.id}@2026-10-20`,
  ref: { utility: 'strom', meter_id: 'm_strom_main', contract_id: 'c_1', bill_id: 'b_1', check: 'bonus_missing', deadline: null, letter: 'complaint', amount_eur: 100,
    recommendation_id: rec.id }, ...extra });
const items = D.buildTodo([ev()], [rec]);
const it = items.find(x => x.ev?.kind === 'rights');
t('Befund als Zeile: Titel der Empfehlung, Sprung, abhakbar, nicht noch einmal unter den Empfehlungen',
  !!it && it.text === rec.title && it.href === rec.evidence.href && it.recId === rec.id && it.ev.can_mark_done && it.action === 'Ansehen' && it.sub === '');
const dl = D.buildTodo([ev({ date: '2026-11-30', ref: { ...ev().ref, deadline: '2026-11-30', recommendation_id: undefined } })], []).find(x => x.ev?.kind === 'rights');
t('mit Frist: „Frist: …“ darunter, Titel aus dem Ereignis', dl?.sub === 'Frist: 30.11.2026' && dl?.text === 'Bonus nicht gutgeschrieben (Agenda)', dl?.sub);
t('nicht fällig: keine Zeile', !D.buildTodo([ev({ due_now: false, severity: 'upcoming' })], [rec]).some(x => x.ev?.kind === 'rights'));
const A = await import(`${JS}/lib/activity.js`);
t('Verlauf: Bereich „Verträge“', A.AREAS.rights === 'contracts');

console.log('Empfehlungen');
const V = await import(`${JS}/views/recommendations.js`);
const view = document.getElementById('view');
await V.render(view);
for (let i = 0; i < 40 && !view.querySelector('.rec-card'); i++) await new Promise(r => setTimeout(r, 10));
const card = view.querySelector('[data-role="rights-card"]');
t('Karte der Kategorie „Rechte“ mit Namen der Prüfung', !!card && card.dataset.check === 'bonus_missing'
  && card.querySelector('.rec-card__cat')?.textContent === 'Rechte · Bonus nicht gutgeschrieben', card?.querySelector('.rec-card__cat')?.textContent);
t('… mit Fußzeile und Links', !!card?.querySelector('[data-role="rights-footer"]') && card.querySelectorAll('a[data-role="rights-norm"]').length === 2);
opened = null;
card?.querySelector('[data-role="letter-button"]')?.click();
t('… und „Brief entwerfen“ öffnet den Brief zum Befund', opened?.id === rec.id && opened?.letter === 'complaint');
// v3.11.0 (Paket doku) — „Was die App prüft“: Handbuch an der Sprungmarke der Prüfung (RightsSourcesDocTest hält sie)
const docLink = card?.querySelector('a[data-role="rights-doc"]');
t('… und „Was die App prüft“ führt ins Handbuch an die Stelle der Prüfung', docLink?.textContent === 'Was die App prüft'
  && docLink?.getAttribute('href') === '#/help/doc/verstehen/17-rechte.md?h=rights-bonus-missing', docLink?.getAttribute('href'));
const plain = [...view.querySelectorAll('.rec-card')].find(c => !c.dataset.role);
t('andere Kategorien ohne Fußzeile und Knopf', !!plain && !plain.querySelector('[data-role="rights-footer"], [data-role="letter-button"], [data-role="rights-doc"]'));

// v3.11.0 (Nachprüfung) — was die Ansichten aus Kündigungstag, Verlängerung, Bonus, Preisstufe und geschätztem
// Endstand machen (erfundene Zeilen in der Form von contract-status, tariff-switch, Verträgen und Posteingang)
console.log('Nachprüfung');
{
  const strip = (html) => { const d = document.createElement('div'); d.innerHTML = html; return d.textContent.replace(/\s+/g, ' ').trim(); };
  const { fmt } = await import(`${JS}/lib/format.js`);
  const U = await import(`${JS}/views/utility.js`);
  const strom = { key: 'strom', label: 'Strom', unit: 'kWh', consumption_unit: 'kWh', accounting_kind: 'consumption' };
  const heat = { key: 'fernwaerme', label: 'Fernwärme', unit: 'kWh', consumption_unit: 'kWh', accounting_kind: 'consumption' };
  const gas = { key: 'gas', label: 'Gas', unit: 'm³', consumption_unit: 'kWh', accounting_kind: 'consumption' };
  const base = { contract_id: 'c1', provider: 'Musterstadt', start: '2017-01-01', is_current: true, current_balance: 0, projected_end_balance: 0 };
  // NP3 — Fernwärme ohne Frist, Kündigungstag verpasst
  let txt = strip(U.balanceCard({ ...base, end: '2026-12-31', cancel_missed: true, cancel_by: '2026-03-31', renewal_term: { until: '2031-12-31', cancel_by: '2031-03-31' } }, heat));
  t('NP3: Saldo-Karte nennt die Verlängerung bis 31.12.2031 und den nächsten Kündigungstag', txt.includes('31.12.2031') && txt.includes('31.03.2031')
    && txt.includes('§ 32 AVBFernwärmeV') && !txt.includes('steht im Vertrag'), txt.slice(0, 300));
  // NP4 — verlängert mit Folgevertrag: kein Ende der Verlängerung
  txt = strip(U.balanceCard({ ...base, end: '2026-06-30', renewed: true, notice_basis: 'renewed', switch_date: '2027-04-01', cancel_by: null,
    successor: { contract_id: 'c2', provider: 'Musterstadt', start: '2027-04-01', gap_days: 274, gap_covered: true } }, heat));
  t('NP4: Saldo-Karte nennt den Folgevertrag, kein „läuft bis …“ der Verlängerung', txt.includes('bis am 01.04.2027 der Folgevertrag beginnt') && !/2031|2030/.test(txt), txt.slice(0, 300));
  // Grundversorgung verlängert: zwei Wochen Frist
  txt = strip(U.balanceCard({ ...base, end: '2025-12-31', renewed: true, notice_basis: 'basic_supply', switch_date: '2026-11-18', cancel_by: null }, gas));
  t('Grundversorgung: Saldo-Karte mit zwei Wochen Frist und § 20 GasGVV', txt.includes('zwei Wochen Frist') && txt.includes('§ 20 GasGVV') && txt.includes('17.11.2026')
    && !txt.includes('steht im Vertrag'), txt.slice(0, 300));
  // NP7 — Bonus der Zeile wie im Saldo
  t('NP7: Bonus der Tabellenzeile aus bonus_to_date, sonst actual_bonus_total', U.contractRowBonus({ bonus_to_date: 0, actual_bonus_total: 38.71 }) === 0
    && U.contractRowBonus({ actual_bonus_total: 75 }) === 75);
  const row = strip(U.contractsTable([{ ...base, end: null, is_open_ended: true, bonus_to_date: 75, actual_bonus_total: 38.71, cost_to_date: 100, advance_paid: 200 }], strom));
  const sp = (s) => s.replace(/\s+/g, ' ');
  t('NP7: Spalte Bonus zeigt 75,00 €, nicht 38,71 €', row.includes(sp(fmt.eur(75))) && !row.includes(sp(fmt.eur(38.71))), row.slice(0, 200));

  // NP2 — „Wechsel prüfen“
  const T = await import(`${JS}/views/tariff.js`);
  const card = (current, extra = {}) => strip(T.timingCardHtml({ utility: 'gas', switch_date: '2026-12-01', switch_date_source: 'default', window: { from: '2026-12', to: '2028-11', months: 24 }, current, ...extra }));
  txt = card({ label: 'Gas Grund', end: '2025-12-31', renewed: true, basis: 'renewed', cancel_by: null, notice_period_months: 1 });
  t('NP2: verlängert: „verlängert seit“, kein vergangenes „läuft bis“, kein „Frist pflegen“ trotz eingetragener Frist', txt.includes('verlängert seit 31.12.2025')
    && !txt.includes('läuft bis') && !txt.includes('pflegen') && txt.includes('Kündigungsfrist laut Vertrag'), txt);
  txt = card({ label: 'Gas Grund', end: '2025-12-31', renewed: true, basis: 'basic_supply', cancel_by: null }, { switch_date: '2026-11-18', switch_date_source: 'contract' });
  t('Grundversorgung: „Wechsel prüfen“ nennt zwei Wochen Frist (§ 20 GasGVV)', txt.includes('zwei Wochen Frist kündbar (§ 20 GasGVV)') && !txt.includes('pflegen'), txt);
  txt = card({ label: 'Gas', end: '2026-12-31', renewed: false, basis: 'fixed_end', cancel_by: null, notice_period_months: null, notice_period_days: null });
  t('NP2: ohne eingetragene Frist bleibt „Kündigungsfrist am Vertrag pflegen“', txt.includes('pflegen') && txt.includes('läuft bis 31.12.2026'), txt);
  txt = card({ label: 'Gas', end: null, renewed: false, basis: 'unknown', cancel_by: null, notice_period_months: 3, notice_period_days: null });
  t('NP2: mit eingetragener Frist nie „Kündigungsfrist am Vertrag pflegen“ (auch ohne berechneten Tag)', !txt.includes('pflegen'), txt);
  txt = card({ label: 'Wärme', end: '2026-12-31', renewed: false, basis: 'fixed_end', cancel_by: '2026-03-31', days_to_cancel: -218,
    renewal_term: { until: '2031-12-31', cancel_by: '2031-03-31' } }, { utility: 'fernwaerme', switch_date: '2032-01-01', switch_date_source: 'contract' });
  t('NP3: „Wechsel prüfen“ nennt die Verlängerung bis 31.12.2031 und den nächsten Kündigungstag', txt.includes('Frist verstrichen') && txt.includes('bis 31.12.2031')
    && txt.includes('31.03.2031'), txt);

  // NP3/NP4 — Übersicht der Verträge
  const O = await import(`${JS}/views/contracts-overview.js`);
  const meter = { id: 'm1', name: 'Wärme' };
  txt = strip(O.meterBlock(heat, meter, [{ ...base, meter_id: 'm1', end: '2026-12-31', cancel_missed: true, cancel_by: '2026-03-31', renewal_term: { until: '2031-12-31', cancel_by: '2031-03-31' } }], false));
  t('NP3: Übersicht „verstrichen, verlängert sich bis 31.12.2031“', txt.includes('verlängert sich bis 31.12.2031'), txt.slice(0, 200));
  txt = strip(O.meterBlock(heat, meter, [{ ...base, meter_id: 'm1', end: '2026-06-30', renewed: true, notice_basis: 'renewed', cancel_by: null,
    successor: { contract_id: 'c2', provider: 'Wärme Neu', start: '2027-04-01', gap_days: 274, gap_covered: true } }], false));
  t('NP4: Übersicht nennt den Folgevertrag statt „Kündigungsfrist laut Vertrag“', txt.includes('Folgevertrag Wärme Neu ab 01.04.2027') && !txt.includes('laut Vertrag'), txt.slice(0, 200));
  txt = strip(O.meterBlock(gas, meter, [{ ...base, meter_id: 'm1', end: '2025-12-31', renewed: true, notice_basis: 'basic_supply', cancel_by: null, switch_date: '2026-11-18' }], false));
  t('Grundversorgung: Übersicht mit zwei Wochen Frist', txt.includes('zwei Wochen Frist'), txt.slice(0, 200));

  // klein — Vertragskarte mit der angekündigten Preisstufe
  const C = await import(`${JS}/views/contracts.js`);
  txt = strip(C.contractValuesHtml({ values_on: { date: '2026-11-03', working_price_ct: 30, base_price_eur: 12, advance_eur: 110 },
    working_prices: [{ from: '2026-01-01', ct_per_kwh: 30 }, { from: '2026-12-01', ct_per_kwh: 34.5 }], base_prices: [{ from: '2026-01-01', eur_per_month: 12 }, { from: '2026-12-01', eur_per_month: 13.5 }] }, strom));
  t('Vertragskarte: „ab 01.12.2026: Arbeitspreis 34,50 ct/kWh · Grundpreis 13,50 €/Monat“', txt.includes('ab 01.12.2026') && txt.includes('34,50') && txt.includes('13,50'), txt);
  txt = strip(C.contractValuesHtml({ values_on: { date: '2026-11-03', working_price_ct: 30 }, working_prices: [{ from: '2026-01-01', ct_per_kwh: 30 }] }, strom));
  t('Vertragskarte ohne spätere Stufe: keine Zeile', !txt.includes('ab '), txt);

  // NP5 — Posteingang: Warnung zum geschätzten Endstand, Stand mit Tausenderpunkt
  const I = await import(`${JS}/views/inbox.js`);
  t('NP5: Satz wie im Formular, mit Tausenderpunkt', I.backwardsText({ date: '2026-01-10', counter: 31040 }, 'kWh') === 'Der geschätzte Endstand passt nicht zu deiner Ablesung vom 10.01.2026 (31.040 kWh): Der Zähler liefe rückwärts. Prüf die Rechnung und die Ablesung.',
    I.backwardsText({ date: '2026-01-10', counter: 31040 }, 'kWh'));
  const pv = { proposals: [{ id: 'p1', kind: 'bill', status: 'new', label_key: 'inbox.proposal.bill', params: { from: '2025-01-01', to: '2025-12-31', result: null }, data: {},
    checked: true, estimate_backwards: { date: '2026-01-10', counter: 31040 } }] };
  const ph = document.createElement('div'); ph.innerHTML = I.proposalsHtml(pv, 'kWh');
  t('NP5: Prüfansicht zeigt die Warnung am Rechnungsvorschlag', ph.querySelector('[data-role="estimate-backwards"]')?.textContent.includes('31.040 kWh'), ph.textContent.slice(0, 200));
}

console.log(`\nrights.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
