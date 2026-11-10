// =====================================================================
// v3.11.0 (F1036, Paket briefe) — Dialog „Brief entwerfen“ ohne Server:
// erste Anfrage ohne Absender, Ort und Empfänger (Vorbelegung des Servers),
// danach die Eingaben; Widerspruch erst nach der Pflichtbestätigung
// (Entscheidung 5b); v3.12.0 (m4b) Absender und Lieferanschrift aus dem
// Haushalt, Anschrift des Anbieters aus dem Vertrag, „übernehmen“ nur auf
// Knopfdruck, ein auf dem Gerät gemerkter Absender (bis v3.11) füllt nur noch
// vor; PDF als Datei; „Als verschickt markieren“ über „Zu tun“. Antworten
// erfunden (Form wie POST /api/letters/render).
//
// Aufruf: node tests/letters.test.mjs
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

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body><div id="app"><main id="view"></main></div><div id="modal-root"></div><div id="toast-root"></div></body></html>',
  { url: 'http://127.0.0.1/', pretendToBeVisual: true });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage', 'getComputedStyle', 'MutationObserver']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));
const until = async (fn, n = 80) => { for (let i = 0; i < n && !fn(); i++) await tick(10); return fn(); };

// v3.12.0 (m4b) — was der erfundene Server im Haushalt und am Vertrag kennt
const household = { sender: [], supply: null, address: [], contract: true };
const model = (body) => {
  const sender = body.sender ?? household.sender;
  const recipient = body.recipient ?? ['Stadtwerke Musterstadt', ...household.address];
  const supply = body.refs?.supply_address ?? household.supply;
  const refs = [{ key: 'customer_number', label: 'Kundennummer', value: body.refs?.customer_number ?? 'K-4711' }];
  if (supply) refs.push({ key: 'supply_address', label: 'Lieferanschrift', value: supply });
  return {
  kind: body.kind, finding_id: body.finding_id ?? null, contract_id: body.contract_id ?? null, rules: body.utility !== 'at',
  form: body.kind === 'objection' || body.utility === 'fernwaerme' ? 'written' : 'text',
  sender, recipient, place: body.place ?? (household.sender.length ? 'Musterstadt' : ''),
  subject: 'Kündigung wegen Preisänderung', paragraphs: ['…'], refs,
  gaps: [...(sender.length ? [] : ['sender']), ...(body.refs?.customer_number === '' ? ['customer_number'] : [])],
  text: `${(sender.length ? sender : ['[…]']).join('\n')}\n\n${recipient.join('\n')}\n\nKundennummer: ${body.refs?.customer_number ?? 'K-4711'}`,
  sources: { sender: household.sender.length ? 'household' : null, supply_address: household.supply ? 'household' : null,
    recipient_address: household.address.length ? 'contract' : null, contract: household.contract && body.kind !== 'objection' ? { utility: 'strom', id: 'c_1' } : null },
  lines: [], norms: [], defaults: { sender: household.sender, place: household.sender.length ? 'Musterstadt' : '',
    recipient: body.kind === 'objection' ? [] : ['Stadtwerke Musterstadt', ...household.address], recipient_name: body.kind === 'objection' ? [] : ['Stadtwerke Musterstadt'],
    refs: { customer_number: 'K-4711', ...(household.supply ? { supply_address: household.supply } : {}) }, extra: {} },
  extra: { reading_value: body.extra?.reading_value ?? null },
  finding: body.contract_id ? {} : { id: body.finding_id, title: 'Preisänderung: Sonderkündigung', text: 'Der Versorger erhöht den Preis.', deadline: '2026-11-30', todo: true,
    norms: [{ key: 'de.enwg.41.5.s4', norm: '§ 41 Abs. 5 Satz 4 EnWG', url: 'https://www.gesetze-im-internet.de/enwg_2005/__41.html', checked_on: '2026-11-03' }],
    help: 'Hilfe: Verbraucherzentrale, Schlichtungsstelle Energie.',
    // v3.12.0 — Gebührenbescheid in der Weichzone: Frist möglicherweise abgelaufen
    ...(String(body.finding_id || '').includes('fee_notice') ? { maybe_expired: true } : {}),
    // v3.12.0 — mit eingetragenem Zugang: vom Zugang gerechnet, spätestens vier Tage danach
    ...(String(body.finding_id || '').includes('fee_notice_received') ? { known_basis: 'received', soft_until: '2026-12-04' } : {}) },
  // v3.12.0 — Kündigung: der Termin bleibt eine Lücke, der Dialog sagt warum
  ...(body.kind === 'termination' && body.contract_id === 'c_gap' ? { term_gap: { reason: 'missed', cancel_by: '2026-09-30', end: '2026-12-31' } } : {}),
  };
};
const calls = [];
let recsFor = [];
global.fetch = async (url, opts = {}) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  if (/\/api\/letters\/render$/.test(u)) {
    const body = JSON.parse(opts.body || '{}');
    calls.push(body);
    if (body.kind === 'objection' && body.extra?.confirm_objection !== true) {
      return new Response(JSON.stringify({ success: false, error: 'Bestätige zuerst', code: 'errors.letter.confirmRequired' }), { status: 422, headers: { 'Content-Type': 'application/json' } });
    }
    if (body.format === 'pdf') return new Response(new Blob(['%PDF-1.4'], { type: 'application/pdf' }), { status: 200, headers: { 'Content-Type': 'application/pdf' } });
    return new Response(JSON.stringify({ success: true, data: model(body) }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }
  if (/\/api\/recommendations/.test(u)) return new Response(JSON.stringify({ success: true, data: recsFor }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  // v3.12.0 (m4b) — „übernehmen“: Einstellungen und Vertrag
  if (/\/api\/settings$/.test(u) && opts.method && opts.method !== 'GET') {
    const body = JSON.parse(opts.body || '{}');
    calls.push({ settings: body });
    return new Response(JSON.stringify({ success: true, data: body }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }
  if (/\/api\/utility\/[a-z]+\/contracts\/[^/]+$/.test(u) && opts.method === 'PATCH') {
    calls.push({ contract: u.replace(/^.*\/api\//, ''), body: JSON.parse(opts.body || '{}') });
    return new Response(JSON.stringify({ success: true, data: {} }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }
  if (/\/api\/agenda\/done$/.test(u)) {
    calls.push({ done: JSON.parse(opts.body || '{}') });
    return new Response(JSON.stringify({ success: true, data: { activity_id: null } }), { status: 201, headers: { 'Content-Type': 'application/json' } });
  }
  return new Response(JSON.stringify({ success: true, data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
};
let downloaded = null;
global.URL.createObjectURL = () => 'blob:brief';
global.URL.revokeObjectURL = () => {};
dom.window.HTMLAnchorElement.prototype.click = function () { downloaded = this.download; };

const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const L = await import(`${JS}/lib/letter.js`);
const D = await import(`${JS}/components/letter-dialog.js`);
const root = document.getElementById('modal-root');

console.log('Dialog');
localStorage.clear();
L.openLetter({ id: 'rights-special_termination-0123456789ab', letter: 'special_termination', title: 'Preisänderung' });
await until(() => root.querySelector('[data-role="letter-preview"]')?.textContent.includes('Stadtwerke'));
const dlg = root.querySelector('[data-role="letter-dialog"]');
t('„Brief entwerfen“ öffnet den Dialog (über openLetter)', !!dlg && dlg.dataset.kind === 'special_termination');
t('Titel nennt die Vorlage', root.querySelector('.modal__title')?.textContent === 'Brief entwerfen: Sonderkündigung');
t('erste Anfrage: Vorlage und Befund, ohne Absender, Ort und Empfänger (Vorbelegung des Servers)', calls[0]?.finding_id === 'rights-special_termination-0123456789ab'
  && calls[0]?.kind === 'special_termination' && calls[0]?.format === 'text' && !('recipient' in calls[0]) && !('sender' in calls[0]) && !('place' in calls[0]), JSON.stringify(calls[0]));
t('kein „Auf diesem Gerät merken“ mehr', !root.querySelector('#lt-remember'));
t('ohne Absender im Haushalt: wo man ihn einträgt', /Kein Absender hinterlegt/.test(root.querySelector('[data-role="letter-sender-source"]')?.textContent || '')
  && root.querySelector('[data-role="letter-sender-source"] a')?.getAttribute('href') === '#/household/building?field=letter_sender_name'
  && !root.querySelector('[data-role="letter-save-sender"]'));
t('ohne Anschrift am Vertrag: wo man sie einträgt', /Anschrift des Anbieters fehlt/.test(root.querySelector('[data-role="letter-recipient-source"]')?.textContent || ''));
t('ohne Lieferanschrift: Sprung in den Haushalt', root.querySelector('[data-role="letter-supply-source"] a')?.getAttribute('href') === '#/household/building?field=letter_supply_address');
t('Empfänger vorbelegt', root.querySelector('#lt-recipient').value === 'Stadtwerke Musterstadt');
t('Bezugszeichen als Felder, dazu die Lieferanschrift', root.querySelector('[data-ref="customer_number"]')?.value === 'K-4711' && !!root.querySelector('[data-ref="supply_address"]'));
t('Felder der Vorlage', !!root.querySelector('[data-extra="reading_value"]') && !!root.querySelector('[data-extra="reading_date"]'));
t('Hinweis mit Frist und Fußzeile „keine Rechtsberatung“', /spätestens am 30\.11\.2026/.test(root.querySelector('[data-role="letter-deadline"]')?.textContent || '')
  && /^Hinweis, keine Rechtsberatung/.test(root.querySelector('[data-role="rights-footer"]')?.textContent || ''));
t('Hinweis „Brief auf Deutsch“', /auf Deutsch/.test(dlg.textContent));
t('fehlende Angaben gezählt', root.querySelector('[data-role="letter-gaps"]')?.textContent === 'Eine Angabe fehlt noch, sie steht als […] im Brief.');
t('Kopieren und PDF bereit, „verschickt“ sichtbar (im Fenster)', !root.querySelector('[data-act="copy"]').disabled && !root.querySelector('[data-act="pdf"]').disabled
  && !root.querySelector('[data-act="sent"]').hidden);

const sender = root.querySelector('#lt-sender');
sender.value = 'Erika Mustermann\nMusterweg 1\n\n12345 Musterstadt';
sender.dispatchEvent(new window.Event('input', { bubbles: true }));
root.querySelector('[data-ref="customer_number"]').value = 'K-9';
root.querySelector('[data-ref="customer_number"]').dispatchEvent(new window.Event('input', { bubbles: true }));
await until(() => calls.length >= 2 && root.querySelector('[data-role="letter-preview"]').textContent.includes('K-9'), 100);
const last = calls[calls.length - 1];
t('Eingaben gehen mit: Zeilen ohne Leerzeilen, Empfänger, Bezug, Felder', JSON.stringify(last.sender) === '["Erika Mustermann","Musterweg 1","12345 Musterstadt"]'
  && JSON.stringify(last.recipient) === '["Stadtwerke Musterstadt"]' && last.refs?.customer_number === 'K-9' && last.refs?.supply_address === ''
  && 'reading_value' in (last.extra || {}), JSON.stringify(last));
t('Vorschau folgt (eine Anfrage je Pause, nicht je Taste)', calls.length === 2 && /K-9/.test(root.querySelector('[data-role="letter-preview"]').textContent), String(calls.length));
t('nichts im Browser gespeichert', localStorage.getItem(D.REMEMBER_KEY) === null);
t('getippter Absender: „Im Haushalt speichern“ steht bereit, gespeichert ist nichts', root.querySelector('[data-role="letter-save-sender"]')?.textContent === 'Im Haushalt speichern'
  && !calls.some(c => c.settings));
root.querySelector('[data-role="letter-save-sender"]').click();
await until(() => calls.some(c => c.settings));
const saved = calls.find(c => c.settings)?.settings;
t('… speichert ausdrücklich: Name und Anschrift getrennt', saved?.letter_sender_name === 'Erika Mustermann' && saved?.letter_sender_address === 'Musterweg 1\n12345 Musterstadt', JSON.stringify(saved));
await until(() => /Aus dem Haushalt/.test(root.querySelector('[data-role="letter-sender-source"]')?.textContent || ''));
t('… danach „Aus dem Haushalt“, kein Knopf mehr', /Aus dem Haushalt/.test(root.querySelector('[data-role="letter-sender-source"]')?.textContent || '')
  && !root.querySelector('[data-role="letter-save-sender"]'));
const supplyIn = root.querySelector('[data-ref="supply_address"]');
supplyIn.value = 'Musterweg 1, 12345 Musterstadt';
supplyIn.dispatchEvent(new window.Event('input', { bubbles: true }));
await tick(20);
root.querySelector('[data-role="letter-save-supply"]')?.click();
await until(() => calls.filter(c => c.settings).length >= 2);
t('Lieferanschrift: „Im Haushalt speichern“', calls.filter(c => c.settings)[1]?.settings?.letter_supply_address === 'Musterweg 1, 12345 Musterstadt');
const rcp = root.querySelector('#lt-recipient');
rcp.value = 'Stadtwerke Musterstadt\nLindenallee 3\n98765 Musterstadt';
rcp.dispatchEvent(new window.Event('input', { bubbles: true }));
await tick(20);
t('Anschrift unter den Namen geschrieben: „Am Vertrag speichern“', root.querySelector('[data-role="letter-save-recipient"]')?.textContent === 'Am Vertrag speichern');
root.querySelector('[data-role="letter-save-recipient"]').click();
await until(() => calls.some(c => c.contract));
const pc = calls.find(c => c.contract);
t('… nur die Anschrift, ohne den Namen, an den Vertrag', pc?.contract === 'utility/strom/contracts/c_1' && pc?.body?.provider_address === 'Lindenallee 3\n98765 Musterstadt', JSON.stringify(pc));
rcp.value = 'Andere Stadtwerke\nLindenallee 3';
rcp.dispatchEvent(new window.Event('input', { bubbles: true }));
await tick(20);
t('… Name geändert: kein Speichern (unklar, was die Anschrift ist)', !root.querySelector('[data-role="letter-save-recipient"]'));

root.querySelector('[data-act="pdf"]').click();
await until(() => downloaded !== null);
// v3.11.0 (Skeptiker N12) — deutscher Name der Vorlage, nicht der interne Schlüssel
t('PDF als Datei (Format pdf, Name mit Vorlage)', calls.some(c => c.format === 'pdf') && /^brief-sonderkuendigung-\d{4}-\d{2}-\d{2}\.pdf$/.test(downloaded || ''), downloaded);
t('… und der Browser bleibt leer', localStorage.getItem(D.REMEMBER_KEY) === null);

root.querySelector('[data-act="sent"]').click();
await until(() => root.querySelector('#dd-note'));
t('„Als verschickt markieren“: Erledigt-Dialog mit Notiz', root.querySelector('#dd-note')?.value === 'Brief verschickt: Sonderkündigung'
  && /Datum ein, an dem du den Brief abgeschickt hast/.test(root.querySelector('[data-role="done-hint"]')?.textContent || ''));
root.querySelector('[data-act="save"]').click();
await until(() => calls.some(c => c.done));
const done = calls.find(c => c.done)?.done;
t('… hakt den Befund unter „Zu tun“ ab (uid = ID des Befunds)', done?.uid === 'rights-special_termination-0123456789ab' && done?.note === 'Brief verschickt: Sonderkündigung'
  && /^\d{4}-\d{2}-\d{2}$/.test(done?.done_on || ''), JSON.stringify(done));
await until(() => !root.querySelector('[data-role="letter-dialog"]'));
t('… und schließt den Brief', !root.querySelector('[data-role="letter-dialog"]'));

console.log('Widerspruch');
calls.length = 0;
localStorage.setItem(D.REMEMBER_KEY, JSON.stringify({ sender: ['Erika Mustermann'], place: 'Musterstadt' }));
const ctrl = D.openLetterDialog({ id: 'rights-fee_notice_deadline-0123456789ab', letter: 'objection' });
await tick(400);
t('ohne Bestätigung keine Anfrage, nur der Hinweis', calls.length === 0 && /Bestätige zuerst/.test(root.querySelector('[data-role="letter-preview"]').textContent)
  && root.querySelector('[data-act="pdf"]').disabled);
t('Hinweis zur Form (unterschreiben, Post)', /Unterschreibe den Widerspruch/.test(root.querySelector('[data-role="letter-confirm"]')?.textContent || ''));
const confirm = root.querySelector('#lt-confirm');
confirm.checked = true;
confirm.dispatchEvent(new window.Event('change', { bubbles: true }));
await until(() => calls.length > 0 && !root.querySelector('[data-act="pdf"]').disabled);
t('nach der Bestätigung: Anfrage und Vorschau', calls.length >= 1 && !root.querySelector('[data-act="pdf"]').disabled);
t('bis v3.11 auf dem Gerät gemerkter Absender: füllt vor, solange der Haushalt keinen hat', root.querySelector('#lt-sender').value === 'Erika Mustermann'
  && root.querySelector('#lt-place').value === 'Musterstadt' && /Auf diesem Gerät gemerkt/.test(root.querySelector('[data-role="letter-sender-source"]')?.textContent || ''));
await until(() => calls.some(c => JSON.stringify(c.sender) === '["Erika Mustermann"]'));
t('… und steht in der Vorschau', /Erika Mustermann/.test(root.querySelector('[data-role="letter-preview"]').textContent));
t('Widerspruch: kein Empfänger aus dem Vertrag, der alte Hinweis', /auf dem Schreiben oder der Rechnung/.test(root.querySelector('[data-role="letter-recipient-source"]')?.textContent || ''));
root.querySelector('[data-role="letter-save-sender"]').click();
await until(() => localStorage.getItem(D.REMEMBER_KEY) === null);
t('„Im Haushalt speichern“ übernimmt ihn und löscht den gemerkten', localStorage.getItem(D.REMEMBER_KEY) === null && calls.some(c => c.settings?.letter_sender_name === 'Erika Mustermann'));
t('Felder des Widerspruchs', ['levy', 'reason', 'suspend'].every(n => root.querySelector(`[data-extra="${n}"]`)));
// v3.12.0 — Weichzone: statt „Schicke ihn so ab …“ der Hinweis, dass die Frist möglicherweise abgelaufen ist (fett)
t('Frist möglicherweise abgelaufen: Hinweis im Dialog, Kernaussage fett', root.querySelector('[data-role="letter-deadline-soft"] strong')?.textContent === 'Die Frist ist möglicherweise schon abgelaufen.'
  && /frühestens am 30\.11\.2026/.test(root.querySelector('[data-role="letter-deadline-soft"]')?.textContent || '') && !root.querySelector('[data-role="letter-deadline"]'),
  root.querySelector('[data-role="letter-finding"]')?.innerHTML.slice(0, 200));
await tick(400);
const before = calls.length;
confirm.checked = false;
confirm.dispatchEvent(new window.Event('change', { bubbles: true }));
await tick(400);
t('Bestätigung zurückgenommen: kein PDF, keine Anfrage', root.querySelector('[data-act="pdf"]').disabled && calls.length === before, `${before} → ${calls.length}`);
ctrl.close(null);
await tick(20);
// v3.12.0 — mit eingetragenem Zugang: vom Zugang gerechnet, und bis wann spätestens
{
  const rec = D.openLetterDialog({ id: 'rights-fee_notice_received-0123456789ab', letter: 'objection' });
  await until(() => root.querySelector('#lt-confirm'));
  const ok = root.querySelector('#lt-confirm');
  ok.checked = true;
  ok.dispatchEvent(new window.Event('change', { bubbles: true }));
  await until(() => root.querySelector('[data-role="letter-deadline-soft"]'));
  const soft = root.querySelector('[data-role="letter-deadline-soft"]')?.textContent || '';
  t('Zugang eingetragen: „vom Tag des Zugangs“ und das späteste Ende', /Vom Tag des Zugangs gerechnet endete sie am 30\.11\.2026/.test(soft) && /spätestens bis 04\.12\.2026/.test(soft)
    && !/Bescheiddatum/.test(soft), soft);
  rec.close(null);
  await tick(20);
}

// v3.12.0 — „Brief schreiben“ an der Vertragskarte, ohne Befund
console.log('Vertragskarte');
{
  const contract = { id: 'c_1', provider: 'Stadtwerke Musterstadt', tariff_name: 'Basis', letters: ['advance_adjustment', 'termination', 'special_termination'] };
  localStorage.clear();
  calls.length = 0;
  const menu = D.openContractLetterMenu({ utility: 'strom', contract });
  await until(() => root.querySelector('[data-role="contract-letter-menu"]'));
  await tick(20);
  const kinds = () => [...root.querySelectorAll('[data-role="contract-letter-menu"] [data-kind]')].map(b => b.dataset.kind).join();
  t('Auswahl: nur Abschlag und Kündigung, auch wenn der Server mehr nennte', kinds() === 'advance_adjustment,termination', kinds());
  t('… sagt, warum es die Sonderkündigung hier nicht gibt', /Sonderkündigung, Widerspruch und Beanstandung gibt es nur an einem passenden Hinweis/.test(root.querySelector('[data-role="letter-menu-note"]')?.textContent || ''));
  t('… ohne Befund kein Eintrag dazu', !root.querySelector('[data-role="letter-menu-finding"]'));
  root.querySelector('[data-kind="termination"]').click();
  await until(() => root.querySelector('[data-role="letter-preview"]')?.textContent.includes('Stadtwerke'));
  const first = calls[0];
  t('Kündigung: Anfrage mit Vertrag statt Befund', first?.contract_id === 'c_1' && first?.utility === 'strom' && first?.kind === 'termination' && !('finding_id' in first) && first?.format === 'text' && !('recipient' in first), JSON.stringify(first));
  t('… Titel nennt die Vorlage', [...root.querySelectorAll('.modal__title')].pop()?.textContent === 'Brief entwerfen: Kündigung zum nächstmöglichen Termin');
  t('… Felder: Termin und Zählerstand', ['term_date', 'reading_value', 'reading_date'].every(n => root.querySelector(`[data-extra="${n}"]`)));
  t('… sagt, dass die Vorlage nichts Rechtliches behauptet', /keine rechtliche Aussage/.test(root.querySelector('[data-role="letter-termination-note"]')?.textContent || ''));
  t('… ohne Befund kein „verschickt“, keine Fußzeile, keine Frist', root.querySelector('[data-role="letter-dialog"]') && [...root.querySelectorAll('[data-act="sent"]')].pop().hidden
    && !root.querySelector('[data-role="letter-deadline"]') && !root.querySelector('[data-role="rights-footer"]'));
  t('… Satz zum Deutschen bleibt im Land mit Regeln', /Versorger und Behörden in Deutschland/.test(root.querySelector('[data-role="letter-german"]')?.textContent || ''));
  [...root.querySelectorAll('[data-act="close"]')].pop().click();
  await tick(20);
  menu.close(null);
  await tick(20);

  // Land ohne Regeln: Der Satz stimmt dort nicht
  calls.length = 0;
  const atDlg = D.openLetterDialog({ utility: 'at', contract_id: 'c_1', letter: 'termination' });
  await until(() => calls.length > 0 && root.querySelector('[data-role="letter-preview"]')?.textContent.includes('Stadtwerke'));
  t('Land ohne Regeln: nur eine Vorlage, kein „in Deutschland“', /gibt es nur auf Deutsch/.test(root.querySelector('[data-role="letter-german"]')?.textContent || ''));
  atDlg.close(null);
  await tick(20);

  // Fernwärme: unterschreiben
  calls.length = 0;
  const heat = D.openLetterDialog({ utility: 'fernwaerme', contract_id: 'c_9', letter: 'termination' });
  await until(() => calls.length > 0 && root.querySelector('[data-role="letter-written"]'));
  t('Fernwärme: Hinweis auf die Schriftform mit Norm', /schriftlich \(§ 32 AVBFernwärmeV\)/.test(root.querySelector('[data-role="letter-written"]')?.textContent || ''));
  heat.close(null);
  await tick(20);

  // v3.12.0 — Termin als Lücke: warum (Stichtag verpasst) und wo man ihn einträgt
  calls.length = 0;
  const gap = D.openLetterDialog({ utility: 'strom', contract_id: 'c_gap', letter: 'termination' });
  await until(() => calls.length > 0 && root.querySelector('[data-role="letter-term-gap"]'));
  const gapText = root.querySelector('[data-role="letter-term-gap"]')?.textContent || '';
  t('Termin als Lücke: der Dialog sagt warum, Kernaussage fett', root.querySelector('[data-role="letter-term-gap"] strong')?.textContent === 'Warum der Termin fehlt:'
    && /31\.12\.2026/.test(gapText) && /30\.09\.2026/.test(gapText) && /Trag ihn hier ein/.test(gapText), gapText);
  gap.close(null);
  await tick(20);

  // Abschlag: gleiche Vorlage wie aus dem Befund, Felder wie dort
  calls.length = 0;
  const adv = D.openLetterDialog({ utility: 'gas', contract_id: 'c_2', letter: 'advance_adjustment' });
  await until(() => calls.length > 0 && root.querySelector('[data-extra="amount_eur"]'));
  t('Abschlag: Betrag und Gründe als Felder, Anfrage mit Vertrag', !!root.querySelector('[data-extra="reasons"]') && calls[0]?.contract_id === 'c_2' && calls[0]?.utility === 'gas');
  adv.close(null);
  await tick(20);

  // Befund zum Vertrag: führt zu ihm; dieselbe Vorlage ohne Befund entfällt
  const rec = (id, letter, cid) => ({ id, severity: 'info', category: 'recht', title: `Titel ${id}`, detail: '', evidence: { finding_id: id, letter, contract_id: cid } });
  recsFor = [rec('rights-special_termination-aaaaaaaaaaaa', 'special_termination', 'c_1'), rec('rights-advance_high-bbbbbbbbbbbb', 'advance_adjustment', 'c_1'),
    rec('rights-bill_late-cccccccccccc', 'complaint', 'c_anders'), { id: 'r6', severity: 'warning', category: 'vertrag', title: 'x', detail: '', evidence: { contract_id: 'c_1' } }];
  const m2 = D.openContractLetterMenu({ utility: 'strom', contract });
  await until(() => root.querySelector('[data-role="letter-menu-finding"]'));
  const links = [...root.querySelectorAll('[data-role="letter-menu-finding"]')];
  t('Befund vorhanden: die Sonderkündigung führt zum Hinweis (Sprungmarke)', links.length === 2 && links[0].getAttribute('href') === '#/todo?art=rec&rec=rights-special_termination-aaaaaaaaaaaa'
    && links[0].textContent === 'Sonderkündigung: Titel rights-special_termination-aaaaaaaaaaaa', links.map(a => a.textContent).join(' | '));
  t('… kein Befund eines anderen Vertrags, keine andere Kategorie', !links.some(a => /bill_late|r6/.test(a.getAttribute('href'))));
  t('… Abschlag mit Befund: nur über den Befund (dessen Zahlen), die Kündigung bleibt', kinds() === 'termination', kinds());
  m2.close(null);
  recsFor = [];
  await tick(20);

  // Empfehlungen nicht ladbar: Die Auswahl bleibt
  const realFetch = global.fetch;
  global.fetch = async (url, o) => (/\/api\/recommendations/.test(String(url)) ? new Response('nein', { status: 500 }) : realFetch(url, o));
  const m3 = D.openContractLetterMenu({ utility: 'strom', contract });
  await tick(30);
  t('Empfehlungen nicht ladbar: die Auswahl bleibt', kinds() === 'advance_adjustment,termination');
  m3.close(null);
  global.fetch = realFetch;
}

// v3.12.0 (m4b) — Absender und Anschrift aus Haushalt und Vertrag
console.log('Haushalt und Vertrag');
{
  Object.assign(household, { sender: ['Erika Mustermann', 'Musterweg 1', '12345 Musterstadt'], supply: 'Musterweg 1, 12345 Musterstadt',
    address: ['Lindenallee 3', '98765 Musterstadt'], contract: true });
  localStorage.setItem(D.REMEMBER_KEY, JSON.stringify({ sender: ['Gerät Absender'], place: 'Woanders' }));
  calls.length = 0;
  const dlg2 = D.openLetterDialog({ utility: 'strom', contract_id: 'c_1', letter: 'termination' });
  await until(() => root.querySelector('[data-role="letter-preview"]')?.textContent.includes('Lindenallee'));
  t('vorbelegt aus dem Haushalt (nicht aus dem Gerät)', root.querySelector('#lt-sender').value === 'Erika Mustermann\nMusterweg 1\n12345 Musterstadt'
    && root.querySelector('#lt-place').value === 'Musterstadt');
  t('… Empfänger mit Anschrift aus dem Vertrag', root.querySelector('#lt-recipient').value === 'Stadtwerke Musterstadt\nLindenallee 3\n98765 Musterstadt'
    && /Anschrift aus dem Vertrag/.test(root.querySelector('[data-role="letter-recipient-source"]')?.textContent || ''));
  t('… Lieferanschrift aus dem Haushalt', root.querySelector('[data-ref="supply_address"]')?.value === 'Musterweg 1, 12345 Musterstadt'
    && /Aus dem Haushalt/.test(root.querySelector('[data-role="letter-supply-source"]')?.textContent || ''));
  t('… keine Knöpfe, solange nichts geändert ist', !root.querySelector('[data-role^="letter-save-"]'));
  const snd = root.querySelector('#lt-sender');
  snd.value = 'Erika Mustermann\nNeuer Weg 2\n12345 Musterstadt';
  snd.dispatchEvent(new window.Event('input', { bubbles: true }));
  await until(() => calls.some(c => JSON.stringify(c.sender || []).includes('Neuer Weg 2')), 100);
  t('geändert: gilt nur für diesen Brief, „Im Haushalt übernehmen“ steht bereit', /Nur für diesen Brief geändert/.test(root.querySelector('[data-role="letter-sender-source"]')?.textContent || '')
    && root.querySelector('[data-role="letter-save-sender"]')?.textContent === 'Im Haushalt übernehmen' && !calls.some(c => c.settings));
  const cn = root.querySelector('[data-ref="customer_number"]');
  cn.value = '';
  cn.dispatchEvent(new window.Event('input', { bubbles: true }));
  await until(() => /Vertragsnummer/.test(root.querySelector('[data-role="letter-where"]')?.textContent || ''), 100);
  t('Kundennummer fehlt: sagt, dass sie am Vertrag steht', /am Vertrag ein/.test(root.querySelector('[data-role="letter-where"]')?.textContent || ''));
  dlg2.close(null);
  await tick(20);
  Object.assign(household, { sender: [], supply: null, address: [], contract: true });
  localStorage.clear();
}

// v3.12.0 (m4b) — Angaben für Briefe am Vertrag: Felder im Dialog, Zeile auf der Karte
console.log('Vertragsdialog');
{
  const C = await import(`${JS}/views/contracts.js`);
  const form = document.createElement('form');
  form.innerHTML = C.letterFieldsHtml({ key: 'gas' }, { customer_number: 'K-1', provider_address: 'Lindenallee 3\n98765 Musterstadt' });
  t('Abschnitt „Angaben für Briefe“ mit drei Feldern, vorbelegt', form.querySelectorAll('[data-section="letters"] input, [data-section="letters"] textarea').length === 3
    && form.querySelector('[name="customer_number"]').value === 'K-1' && form.querySelector('[name="provider_address"]').value === 'Lindenallee 3\n98765 Musterstadt');
  form.querySelector('[name="contract_number"]').value = '  V-2 ';
  form.querySelector('[name="provider_address"]').value = 'Am Markt 1\n12345 Musterstadt\n';
  const pl = C.collectLetterFields(form, {});
  t('… gehen mit (leer = entfernen, Leerraum weg)', JSON.stringify(pl) === JSON.stringify({ customer_number: 'K-1', contract_number: 'V-2', provider_address: 'Am Markt 1\n12345 Musterstadt' }), JSON.stringify(pl));
  t('… nur bei Arten mit Briefen am Vertrag', C.letterFieldsHtml({ key: 'pv_einspeisung' }, {}) === '' && C.letterFieldsHtml({ key: 'heizoel' }, {}) === '');
  const card = C.forLettersHtml({ customer_number: 'K-1', contract_number: 'V-2', provider_address: 'Am Markt 1\n12345 Musterstadt' }, { key: 'strom' });
  t('Vertragskarte: die Angaben in einer Zeile', /data-role="contract-for-letters"/.test(card) && card.includes('Für Briefe: Kundennummer K-1, Vertragsnummer V-2, Am Markt 1, 12345 Musterstadt'), card);
  t('… ohne Angaben keine Zeile', C.forLettersHtml({}, { key: 'strom' }) === '');
}

console.log(`\nletters.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
