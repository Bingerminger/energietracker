// =====================================================================
// v3.11.0 (F1036, Paket briefe) — Dialog „Brief entwerfen“ ohne Server:
// erste Anfrage ohne Empfänger (Vorbelegung des Servers), danach die Eingaben;
// Widerspruch erst nach der Pflichtbestätigung (Entscheidung 5b); Absender nur
// auf Wunsch im Browser (Entscheidung 10a), sonst nirgends; PDF als Datei;
// „Als verschickt markieren“ über „Zu tun“. Antworten erfunden (Form wie
// POST /api/letters/render).
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

const model = (body) => ({
  kind: body.kind, finding_id: body.finding_id, form: body.kind === 'objection' ? 'written' : 'text',
  sender: body.sender || [], recipient: body.recipient ?? ['Stadtwerke Musterstadt'],
  subject: 'Kündigung wegen Preisänderung', paragraphs: ['…'],
  refs: [{ key: 'customer_number', label: 'Kundennummer', value: body.refs?.customer_number ?? 'K-4711' }],
  gaps: (body.sender || []).length ? [] : ['sender'],
  text: `${(body.sender || ['[…]']).join('\n')}\n\n${(body.recipient ?? ['Stadtwerke Musterstadt']).join('\n')}\n\nKundennummer: ${body.refs?.customer_number ?? 'K-4711'}`,
  lines: [], norms: [], defaults: { recipient: ['Stadtwerke Musterstadt'], refs: { customer_number: 'K-4711' }, extra: {} },
  extra: { reading_value: body.extra?.reading_value ?? null },
  finding: { id: body.finding_id, title: 'Preisänderung: Sonderkündigung', text: 'Der Versorger erhöht den Preis.', deadline: '2026-11-30', todo: true,
    norms: [{ key: 'de.enwg.41.5.s4', norm: '§ 41 Abs. 5 Satz 4 EnWG', url: 'https://www.gesetze-im-internet.de/enwg_2005/__41.html', checked_on: '2026-11-03' }],
    help: 'Hilfe: Verbraucherzentrale, Schlichtungsstelle Energie.' },
});
const calls = [];
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
t('erste Anfrage: Vorlage und Befund, ohne Empfänger (Vorbelegung des Servers)', calls[0]?.finding_id === 'rights-special_termination-0123456789ab'
  && calls[0]?.kind === 'special_termination' && calls[0]?.format === 'text' && !('recipient' in calls[0]), JSON.stringify(calls[0]));
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
t('nichts im Browser gespeichert ohne Haken', localStorage.getItem(D.REMEMBER_KEY) === null);

const remember = root.querySelector('#lt-remember');
remember.checked = true;
remember.dispatchEvent(new window.Event('change', { bubbles: true }));
t('„Auf diesem Gerät merken“: Absender und Ort im Browser', JSON.parse(localStorage.getItem(D.REMEMBER_KEY) || '{}').sender?.[0] === 'Erika Mustermann');
remember.checked = false;
remember.dispatchEvent(new window.Event('change', { bubbles: true }));
t('Haken heraus löscht', localStorage.getItem(D.REMEMBER_KEY) === null);

root.querySelector('[data-act="pdf"]').click();
await until(() => downloaded !== null);
// v3.11.0 (Skeptiker N12) — deutscher Name der Vorlage, nicht der interne Schlüssel
t('PDF als Datei (Format pdf, Name mit Vorlage)', calls.some(c => c.format === 'pdf') && /^brief-sonderkuendigung-\d{4}-\d{2}-\d{2}\.pdf$/.test(downloaded || ''), downloaded);
t('… und ohne Haken bleibt der Browser leer', localStorage.getItem(D.REMEMBER_KEY) === null);

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
t('Absender aus diesem Gerät vorbelegt', root.querySelector('#lt-sender').value === 'Erika Mustermann' && root.querySelector('#lt-place').value === 'Musterstadt'
  && root.querySelector('#lt-remember').checked);
t('Hinweis zur Form (unterschreiben, Post)', /Unterschreibe den Widerspruch/.test(root.querySelector('[data-role="letter-confirm"]')?.textContent || ''));
const confirm = root.querySelector('#lt-confirm');
confirm.checked = true;
confirm.dispatchEvent(new window.Event('change', { bubbles: true }));
await until(() => calls.length > 0 && !root.querySelector('[data-act="pdf"]').disabled);
t('nach der Bestätigung: Anfrage und Vorschau', calls.length >= 1 && !root.querySelector('[data-act="pdf"]').disabled);
t('Felder des Widerspruchs', ['levy', 'reason', 'suspend'].every(n => root.querySelector(`[data-extra="${n}"]`)));
await tick(400);
const before = calls.length;
confirm.checked = false;
confirm.dispatchEvent(new window.Event('change', { bubbles: true }));
await tick(400);
t('Bestätigung zurückgenommen: kein PDF, keine Anfrage', root.querySelector('[data-act="pdf"]').disabled && calls.length === before, `${before} → ${calls.length}`);
ctrl.close(null);

console.log(`\nletters.test: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
