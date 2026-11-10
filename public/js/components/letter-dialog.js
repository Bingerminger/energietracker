// =====================================================================
// v3.11.0 (F1036, Paket briefe) — Dialog „Brief entwerfen“ zu einem Befund
// „Deine Rechte als Kunde“. Öffnet sich nur auf Knopfdruck (Entscheidung 2a,
// lib/letter.js → openLetter). Der Server baut den Brief aus fester Vorlage
// (POST /api/letters/render); hier stehen nur die Eingaben:
//   - Absender, Empfänger, Ort, Angaben zur Zuordnung, je Vorlage ein paar
//     Felder; vorbelegt aus Vertrag, Zähler und Befund, alles änderbar;
//   - nichts davon speichert die App (Entscheidung 10a). „Absender auf diesem
//     Gerät merken“ legt ihn nur in diesem Browser ab (localStorage), der Haken
//     raus löscht ihn wieder;
//   - Widerspruch erst nach der Pflichtbestätigung (Entscheidung 5b);
//   - Vorschau als Text, „Text kopieren“, „PDF herunterladen“ und „Als
//     verschickt markieren“ (Erledigt-Vermerk über „Zu tun“, mit Datum).
// Der Brief ist immer Deutsch, der Dialog in der Sprache der Oberfläche; der
// Hinweis „keine Rechtsberatung“ steht hier, nicht im Brief.
//
// v3.12.0 — auch ohne Befund, von der Vertragskarte: openContractLetterMenu()
// bietet an, was der Vertrag hergibt (Antrag auf Abschlagsanpassung, Kündigung
// zum nächstmöglichen Termin) und führt zu einem Befund, wenn es einen gibt.
// Sonderkündigung, Widerspruch und Beanstandung gibt es ohne Befund nie.
//
// v3.12.0 (m4b) — kehrt Entscheidung 10a um: Absender und Lieferanschrift stehen
// als freiwillige Angaben im Haushalt (Haushalt › Gebäude), die Anschrift des
// Anbieters am Vertrag. Der Server füllt damit vor (die erste Anfrage schickt
// weder Absender noch Ort). Was die Person hier ändert, gilt nur für diesen Brief;
// „Im Haushalt übernehmen“ bzw. „Am Vertrag speichern“ legt es ausdrücklich ab.
// Fehlt etwas, sagt der Dialog, wo man es einträgt. „Auf diesem Gerät merken“
// entfällt; ein so gemerkter Absender füllt nur noch vor, solange der Haushalt
// keinen hat.
// =====================================================================

import { api } from '../api.js';
import { saveSettings } from '../state.js';
import { openModal, guardSubmit } from './modal.js';
import { markEventDone } from './done-dialog.js';
import { toastOk, toastErr } from './toast.js';
import { t, tp } from '../lib/i18n.js';
import { rich } from '../lib/rich.js';
import { escapeHtml as esc, fmt, todayIso } from '../lib/format.js';
import { copyText } from '../lib/clipboard.js';
import { rightsFooterHtml, findingOfRec, hasLetter, recHref, CONTRACT_LETTER_KINDS } from '../lib/letter.js';

/** Bis v3.11: der Absender nur auf diesem Gerät (Entscheidung 10a). Seit v3.12.0 nur noch gelesen, bis der Haushalt einen hat. */
export const REMEMBER_KEY = 'et.letter.sender';

/** v3.11.0 (Skeptiker N12) — Dateiname des PDF je Vorlage, wie LetterService::FILE_NAMES (der Brief ist Deutsch). */
const FILE_NAMES = {
  special_termination: 'sonderkuendigung', advance_adjustment: 'abschlagsanpassung',
  estimate_query: 'rueckfrage-schaetzung', complaint: 'beanstandung', objection: 'widerspruch',
  termination: 'kuendigung',
};

/** Angaben zur Zuordnung, die der Dialog immer anbietet (die Lieferanschrift kennt die App nicht). */
const ALWAYS_REFS = ['supply_address'];

/** Felder je Vorlage (wie LetterService::EXTRA): Name → Art des Eingabefelds. */
const EXTRA = {
  special_termination: { reading_value: 'number', reading_date: 'date' },
  advance_adjustment: { amount_eur: 'number', reasons: 'text' },
  estimate_query: { attach_photo: 'bool' },
  complaint: { note: 'text' },
  objection: { levy: 'line', reason: 'text', suspend: 'bool' },
  termination: { term_date: 'date', reading_value: 'number', reading_date: 'date' },   // v3.12.0
};

function remembered() {
  try { return JSON.parse(localStorage.getItem(REMEMBER_KEY) || 'null'); } catch { return null; }
}

function forget() {
  try { localStorage.removeItem(REMEMBER_KEY); } catch { /* ohne Speicher: nichts zu löschen */ }
}

const lines = (v) => String(v || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
const same = (a, b) => JSON.stringify(a || []) === JSON.stringify(b || []);

/**
 * v3.12.0 (m4b) — Anschrift des Anbieters aus dem Feld „Empfänger“: was nach den Zeilen mit seinem Namen steht
 * (`name` wie vom Server vorbelegt). Steht der Name nicht mehr vorn, ist nicht klar, was die Anschrift ist: null.
 * @param {string[]} recipient  Zeilen des Felds
 * @param {string[]} name       Zeilen des Namens (model.defaults.recipient_name)
 * @returns {string[]|null}
 */
export function addressAfterName(recipient, name) {
  if (!name?.length || recipient.length < name.length || !same(recipient.slice(0, name.length), name)) return null;
  return recipient.slice(name.length);
}

function extraField(kind, name, type) {
  const id = `lt-x-${name}`;
  const label = esc(t(`letters.extra.${name}`));
  if (type === 'bool') {
    return `<div class="field field--full"><label class="check"><input type="checkbox" id="${id}" data-extra="${name}"> ${label}</label></div>`;
  }
  if (type === 'text') {
    return `<div class="field field--full"><label for="${id}">${label}</label>`
      + `<textarea class="input" id="${id}" rows="3" maxlength="2000" data-extra="${name}"></textarea></div>`;
  }
  const attrs = type === 'number' ? 'type="number" inputmode="decimal" min="0" step="0.01"' : type === 'date' ? 'type="date"' : 'type="text" maxlength="60"';
  return `<div class="field ${type === 'line' ? 'field--l' : 'field--s'}"><label for="${id}">${label}</label>`
    + `<input class="input" ${attrs} id="${id}" data-extra="${name}" autocomplete="off"></div>`;
}

/**
 * v3.12.0 — „Brief schreiben“ an der Vertragskarte: die Auswahl. Ein Befund zu diesem Vertrag steht
 * zuerst und führt zu seinem Hinweis (dort Frist, Norm und der Brief mit seinen Zahlen); dieselbe
 * Vorlage ohne Befund entfällt dann. Die Liste der Empfehlungen kommt erst beim Öffnen.
 */
export function openContractLetterMenu({ utility, contract }) {
  const kinds = (contract.letters || []).filter(k => CONTRACT_LETTER_KINDS.includes(k));
  const name = [contract.provider, contract.tariff_name].filter(Boolean).join(' · ');
  return openModal({
    title: t('letters.write'),
    body: `
      <div class="letter-menu" data-role="contract-letter-menu">
        ${name ? `<p class="muted small">${esc(name)}</p>` : ''}
        <div data-role="letter-menu-findings"></div>
        <div class="letter-menu__list">
          ${kinds.map(k => `<button type="button" class="btn" data-kind="${esc(k)}">${esc(t(`letters.kind.${k}`))}</button>`).join('')}
        </div>
        <p class="muted small" data-role="letter-menu-note">${esc(t('letters.menu.onlyFromFinding'))}</p>
      </div>`,
    footer: `<button type="button" class="btn btn--ghost" data-act="close">${esc(t('common.close'))}</button>`,
    onMount: ({ modalEl, bodyEl, close }) => {
      modalEl.querySelector('[data-act="close"]').addEventListener('click', () => close(null));
      bodyEl.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => {
        // über der Auswahl (wie der Erledigt-Dialog über dem Brief): erst schließen und dann öffnen verträgt die History nicht
        openLetterDialog({ utility, contract_id: contract.id, letter: b.dataset.kind, title: name });
      }));
      api.recommendations().then(recs => {
        if (!bodyEl.isConnected) return;
        const mine = recs.map(r => [r, findingOfRec(r)]).filter(([r, f]) => hasLetter(f) && r.evidence?.contract_id === contract.id);
        bodyEl.querySelector('[data-role="letter-menu-findings"]').innerHTML = mine.length ? `
          <p class="small">${esc(t('letters.menu.fromFinding'))}</p>
          <div class="letter-menu__list">${mine.map(([r, f]) => `<a class="btn btn--primary" data-role="letter-menu-finding" data-letter="${esc(f.letter)}" href="${esc(recHref(r.id))}">${esc(t(`letters.kind.${f.letter}`))}: ${esc(r.title)}</a>`).join('')}</div>` : '';
        for (const [, f] of mine) bodyEl.querySelector(`[data-kind="${f.letter}"]`)?.remove();
        // der Fokus lag vielleicht auf dem entfernten Knopf
        if (!modalEl.contains(document.activeElement)) modalEl.querySelector('.letter-menu a, .letter-menu button, [data-act="close"]')?.focus();
      }).catch(() => { /* ohne Liste bleibt die Auswahl ohne Befund */ });
    },
  });
}

/**
 * Den Dialog zu einem Befund öffnen. `finding` braucht nur {id, letter, title?}.
 * v3.12.0 — ohne Befund, aus dem Vertrag: {utility, contract_id, letter, title?}.
 * @returns {{close: Function}|null}
 */
export function openLetterDialog(finding) {
  const kind = finding.letter;
  const isObjection = kind === 'objection';
  let model = null;
  let seq = 0;
  let timer = null;

  const ctrl = openModal({
    title: t('letters.dialog.title', { kind: t(`letters.kind.${kind}`) }),
    size: 'lg',
    body: `
      <div class="letter-dialog" data-role="letter-dialog" data-kind="${esc(kind)}">
        <div data-role="letter-finding"></div>
        <p class="muted small" data-role="letter-german">${esc(t('letters.dialog.german'))}</p>
        ${kind === 'termination' ? `<p class="muted small" data-role="letter-termination-note">${esc(t('letters.dialog.terminationNote'))}</p>` : ''}
        ${isObjection ? `
        <div class="letter-dialog__confirm" data-role="letter-confirm">
          <label class="check"><input type="checkbox" id="lt-confirm"> ${esc(t('letters.dialog.confirmObjection'))}</label>
          <p class="muted small">${esc(t('letters.dialog.objectionForm'))}</p>
        </div>` : ''}
        <div class="fgrid">
          <div class="field">
            <label for="lt-sender">${esc(t('letters.dialog.sender'))}</label>
            <textarea class="input" id="lt-sender" rows="4" autocomplete="off" aria-describedby="lt-sender-source"></textarea>
            <div class="letter-dialog__source" id="lt-sender-source" data-role="letter-sender-source" aria-live="polite"></div>
          </div>
          <div class="field">
            <label for="lt-recipient">${esc(t('letters.dialog.recipient'))}</label>
            <textarea class="input" id="lt-recipient" rows="4" autocomplete="off" aria-describedby="lt-recipient-hint"></textarea>
            <div class="letter-dialog__source" id="lt-recipient-hint" data-role="letter-recipient-source" aria-live="polite"><p class="muted small">${esc(t('letters.dialog.recipientHint'))}</p></div>
          </div>
          <div class="field field--s">
            <label for="lt-place">${esc(t('letters.dialog.place'))}</label>
            <input class="input" type="text" id="lt-place" maxlength="60" autocomplete="off">
          </div>
        </div>
        <fieldset class="letter-dialog__refs">
          <legend>${esc(t('letters.dialog.refs'))}</legend>
          <div class="fgrid" data-role="letter-refs"></div>
        </fieldset>
        <div class="fgrid" data-role="letter-extra">${Object.entries(EXTRA[kind] || {}).map(([n, ty]) => extraField(kind, n, ty)).join('')}</div>
        <p class="muted small" data-role="letter-gaps" aria-live="polite"></p>
        <div data-role="letter-where"></div>
        <h3 class="letter-dialog__h">${esc(t('letters.dialog.preview'))}</h3>
        <pre class="letter-preview" data-role="letter-preview" tabindex="0" aria-live="polite">${esc(isObjection ? t('letters.dialog.confirmFirst') : t('common.loading'))}</pre>
      </div>`,
    footer: `
      <button type="button" class="btn btn--ghost" data-act="close">${esc(t('common.close'))}</button>
      <button type="button" class="btn" data-act="copy" disabled>${esc(t('letters.dialog.copy'))}</button>
      <button type="button" class="btn" data-act="pdf" disabled>${esc(t('letters.dialog.pdf'))}</button>
      <button type="button" class="btn btn--primary letter-dialog__sent" data-act="sent" hidden>${esc(t('letters.dialog.sent'))}</button>`,
    onMount: ({ modalEl, bodyEl, close }) => {
      const $ = (s) => bodyEl.querySelector(s);
      const btn = (a) => modalEl.querySelector(`[data-act="${a}"]`);
      btn('close').addEventListener('click', () => close(null));
      const preview = $('[data-role="letter-preview"]');
      const confirmed = () => !isObjection || !!$('#lt-confirm')?.checked;
      const setReady = (on) => { btn('copy').disabled = !on; btn('pdf').disabled = !on; };

      // Eingaben → Anfrage. Erste Anfrage ohne Absender, Ort, Empfänger, Bezug und Felder: dann gilt die
      // Vorbelegung (v3.12.0 m4b: Absender und Ort aus dem Haushalt).
      const body = () => {
        const source = finding.contract_id ? { utility: finding.utility, contract_id: finding.contract_id } : { finding_id: finding.id };
        const b = { ...source, kind };
        if (isObjection) b.extra = { confirm_objection: confirmed() };
        if (!model) return b;
        b.sender = lines($('#lt-sender').value);
        b.place = $('#lt-place').value.trim();
        b.recipient = lines($('#lt-recipient').value);
        b.refs = {};
        bodyEl.querySelectorAll('[data-ref]').forEach(el => { b.refs[el.dataset.ref] = el.value.trim(); });
        b.extra = {};
        bodyEl.querySelectorAll('[data-extra]').forEach(el => {
          const v = el.type === 'checkbox' ? el.checked : el.value.trim();
          b.extra[el.dataset.extra] = el.type === 'number' ? (v === '' ? null : Number(v)) : v;
        });
        if (isObjection) b.extra.confirm_objection = confirmed();
        return b;
      };

      // v3.12.0 (m4b) — was gespeichert ist (Haushalt, Vertrag), damit der Dialog Änderungen erkennt
      const stored = { sender: [], supply: '', address: [], name: [], contract: null };
      let legacy = false;
      const fillForm = (m) => {
        const d = m.defaults || {};
        stored.sender = m.sources?.sender ? [...(d.sender || [])] : [];
        stored.supply = m.sources?.supply_address ? String(d.refs?.supply_address || '') : '';
        stored.name = [...(d.recipient_name || [])];
        stored.address = (d.recipient || []).slice(stored.name.length);
        stored.contract = m.sources?.contract || null;
        // ohne Absender im Haushalt: ein auf diesem Gerät gemerkter (bis v3.11) füllt vor
        const saved = stored.sender.length ? null : remembered();
        legacy = !!saved?.sender?.length;
        $('#lt-sender').value = (legacy ? saved.sender : m.sender || []).join('\n');
        $('#lt-place').value = legacy && saved.place ? saved.place : (m.place || '');
        $('#lt-recipient').value = (m.recipient || []).join('\n');
        const keys = [...new Set([...m.refs.map(r => r.key), ...Object.keys(m.defaults.refs || {}), ...ALWAYS_REFS])];
        const values = Object.fromEntries(m.refs.map(r => [r.key, (m.gaps || []).includes(r.key) ? '' : r.value]));
        $('[data-role="letter-refs"]').innerHTML = keys.map(k => `
          <div class="field">
            <label for="lt-ref-${esc(k)}">${esc(t(`letters.ref.${k}`))}</label>
            <input class="input" type="text" id="lt-ref-${esc(k)}" data-ref="${esc(k)}" maxlength="60" autocomplete="off" value="${esc(values[k] ?? m.defaults.refs?.[k] ?? '')}"${k === 'supply_address' ? ' aria-describedby="lt-supply-source"' : ''}>
            ${k === 'supply_address' ? '<div class="letter-dialog__source" id="lt-supply-source" data-role="letter-supply-source" aria-live="polite"></div>' : ''}
          </div>`).join('');
        bodyEl.querySelectorAll('[data-extra]').forEach(el => {
          const v = m.extra?.[el.dataset.extra];
          if (el.type === 'checkbox') el.checked = v === true;
          else if (v != null) el.value = String(v);
        });
        const f = m.finding || {};
        $('[data-role="letter-finding"]').innerHTML = `
          ${f.title ? `<p class="letter-dialog__what"><strong>${esc(f.title)}</strong></p>` : ''}
          ${f.text && f.text !== f.title ? `<p class="small">${esc(f.text)}</p>` : ''}
          ${f.deadline && f.maybe_expired
            // v3.12.0 — Gebührenbescheid: das Ende vom Bescheiddatum ist das früheste; der Brief bleibt, mit Hinweis
            ? `<p class="small" data-role="letter-deadline-soft">${f.known_basis === 'received' && f.soft_until
                ? rich('letters.dialog.deadlineMaybePassedReceived', { date: fmt.date(f.deadline), until: fmt.date(f.soft_until) })
                : rich('letters.dialog.deadlineMaybePassed', { date: fmt.date(f.deadline) })}</p>`
            : f.deadline ? `<p class="small" data-role="letter-deadline">${esc(t('letters.dialog.deadline', { date: fmt.date(f.deadline) }))}</p>` : ''}
          ${rightsFooterHtml(f)}`;
        btn('sent').hidden = !f.todo;
        // v3.12.0 — im Land ohne Rechte-Regeln: Der Satz „Versorger in Deutschland lesen ihn“ stimmt dort nicht
        if (m.rules === false) $('[data-role="letter-german"]').textContent = t('letters.dialog.germanNeutral');
        // v3.12.0 — Kündigung von Fernwärme: unterschreiben (§ 32 AVBFernwärmeV, Prüfbericht 30)
        if (kind === 'termination' && m.form === 'written') {
          $('[data-role="letter-termination-note"]').insertAdjacentHTML('afterend', `<p class="small" data-role="letter-written">${esc(t('letters.dialog.terminationWritten'))}</p>`);
        }
        // v3.12.0 (Skeptiker M11) — der Termin wäre nur eine Annahme: sagen, statt ihn „nach meinen Unterlagen“ in den Brief zu schreiben
        if (kind === 'termination' && m.term_assumed) {
          $('[data-role="letter-termination-note"]').insertAdjacentHTML('afterend', `<p class="small" data-role="letter-term-assumed">${esc(t('letters.dialog.termAssumed',
            { cancelBy: fmt.date(m.term_assumed.cancel_by), until: fmt.date(m.term_assumed.until) }))}</p>`);
        }
        // v3.12.0 — der Termin steht als Lücke im Brief: warum, und wo man ihn einträgt (Frist am Vertrag oder hier)
        if (kind === 'termination' && m.term_gap && !m.term_assumed) {
          const g = m.term_gap;
          $('[data-role="letter-termination-note"]').insertAdjacentHTML('afterend', `<p class="small" data-role="letter-term-gap">${rich(`letters.dialog.termGap.${g.reason}`,
            { cancelBy: g.cancel_by ? fmt.date(g.cancel_by) : '', end: g.end ? fmt.date(g.end) : '' })}</p>`);
        }
      };

      // v3.12.0 (m4b) — je Angabe: woher sie kommt, wo man sie einträgt, und „übernehmen“, wenn sie hier geändert ist
      const btnHtml = (role, key) => `<button type="button" class="btn btn--ghost btn--sm" data-role="${role}">${esc(t(key))}</button>`;
      const sources = () => {
        const sender = lines($('#lt-sender').value);
        const changed = !same(sender, stored.sender);
        $('[data-role="letter-sender-source"]').innerHTML = stored.sender.length
          ? `<p class="muted small">${rich(changed ? 'letters.dialog.senderChanged' : 'letters.dialog.senderFromHousehold')}</p>${changed && sender.length ? btnHtml('letter-save-sender', 'letters.dialog.takeOver') : ''}`
          : `<p class="muted small">${rich(legacy ? 'letters.dialog.senderFromDevice' : 'letters.dialog.senderMissing')}</p>${sender.length ? btnHtml('letter-save-sender', 'letters.dialog.saveHousehold') : ''}`;
        const supplyEl = $('[data-ref="supply_address"]');
        const supplySrc = $('[data-role="letter-supply-source"]');
        if (supplyEl && supplySrc) {
          const supply = supplyEl.value.trim();
          supplySrc.innerHTML = stored.supply
            ? `<p class="muted small">${rich(supply === stored.supply ? 'letters.dialog.supplyFromHousehold' : 'letters.dialog.supplyChanged')}</p>${supply && supply !== stored.supply ? btnHtml('letter-save-supply', 'letters.dialog.takeOver') : ''}`
            : `<p class="muted small">${rich('letters.dialog.supplyMissing')}</p>${supply ? btnHtml('letter-save-supply', 'letters.dialog.saveHousehold') : ''}`;
        }
        // Empfänger: nur, wenn der Server den Anbieter aus dem Vertrag vorbelegt hat
        if (stored.name.length) {
          const address = addressAfterName(lines($('#lt-recipient').value), stored.name);
          const canSave = !!stored.contract && address !== null && address.length > 0 && !same(address, stored.address);
          $('[data-role="letter-recipient-source"]').innerHTML = `<p class="muted small">${rich(stored.address.length
            ? (address !== null && same(address, stored.address) ? 'letters.dialog.recipientFromContract' : 'letters.dialog.recipientChanged')
            : 'letters.dialog.recipientMissing')}</p>${canSave ? btnHtml('letter-save-recipient', 'letters.dialog.saveContract') : ''}`;
        }
        // Kunden-, Vertrags- und Zählernummer: wo sie stehen
        const where = [];
        if (stored.contract && (model?.gaps || []).some(g => g === 'customer_number' || g === 'contract_number')) where.push(rich('letters.dialog.whereNumbers'));
        if ((model?.gaps || []).includes('meter_serial')) where.push(rich('letters.dialog.whereMeter'));
        $('[data-role="letter-where"]').innerHTML = where.map(s => `<p class="muted small">${s}</p>`).join('');
      };

      const show = (m) => {
        preview.textContent = m.text;
        const n = (m.gaps || []).length;
        $('[data-role="letter-gaps"]').textContent = n ? tp('letters.dialog.gaps', n) : '';
        setReady(true);
        sources();
      };

      // „übernehmen“: ausdrücklich in den Haushalt bzw. an den Vertrag; danach gilt es als gespeichert
      const save = async (what) => {
        try {
          if (what === 'sender') {
            const s = lines($('#lt-sender').value);
            await saveSettings({ letter_sender_name: s[0] || '', letter_sender_address: s.slice(1).join('\n') });
            stored.sender = s;
            if (legacy) { forget(); legacy = false; }
            toastOk(t('letters.dialog.savedHousehold'));
          } else if (what === 'supply') {
            const v = $('[data-ref="supply_address"]').value.trim();
            await saveSettings({ letter_supply_address: v });
            stored.supply = v;
            toastOk(t('letters.dialog.savedHousehold'));
          } else if (what === 'recipient' && stored.contract) {
            const address = addressAfterName(lines($('#lt-recipient').value), stored.name) || [];
            await api.updateContract(stored.contract.utility, stored.contract.id, { provider_address: address.join('\n') });
            stored.address = address;
            toastOk(t('letters.dialog.savedContract'));
          }
          sources();
        } catch (e) { toastErr(e.message || String(e)); }
      };
      bodyEl.addEventListener('click', (e) => {
        const b = e.target.closest('[data-role^="letter-save-"]');
        if (b) save(b.dataset.role.slice('letter-save-'.length));
      });

      const refresh = async () => {
        if (!confirmed()) { setReady(false); preview.textContent = t('letters.dialog.confirmFirst'); return; }
        const mine = ++seq;
        try {
          const first = !model;
          const m = await api.letterRender(body());
          if (mine !== seq) return;   // eine neuere Eingabe ist unterwegs
          model = m;
          if (first) fillForm(m);
          show(m);
          if (first && legacy) later();   // der auf diesem Gerät gemerkte Absender gehört in die Vorschau
        } catch (e) {
          if (mine !== seq) return;
          setReady(false);
          preview.textContent = e.message || String(e);
        }
      };
      const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };

      bodyEl.addEventListener('input', () => { later(); if (model) sources(); });
      bodyEl.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox') refresh();
      });

      btn('copy').addEventListener('click', async () => {
        if (!model) return;
        if (await copyText(model.text)) toastOk(t('letters.dialog.copied'));
        else toastErr(t('letters.dialog.copyFailed'));
      });
      const pdfBtn = btn('pdf');
      pdfBtn.addEventListener('click', guardSubmit(pdfBtn, async () => {
        try {
          const blob = await api.letterPdf(body());
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          // v3.11.0 (Skeptiker N12) — Name vom Server (deutscher Name der Vorlage, nicht der interne Schlüssel);
          // ohne ihn (Pages-Demo, ältere Antwort) derselbe Name wie LetterService::FILE_NAMES
          a.href = url; a.download = model?.file_name || `brief-${FILE_NAMES[kind] || 'brief'}-${todayIso()}.pdf`;
          document.body.appendChild(a); a.click(); a.remove();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (e) { toastErr(e.message || String(e)); }
      }));
      btn('sent').addEventListener('click', async () => {
        const f = model?.finding || {};
        const kindLabel = t(`letters.kind.${kind}`);
        const res = await markEventDone({ uid: finding.id, kind: 'rights', title: f.title || finding.title || kindLabel, date: f.deadline || todayIso() },
          { hint: t('letters.dialog.sentHint'), note: t('letters.dialog.sentNote', { kind: kindLabel }) });
        if (res) close(true);
      });

      if (!isObjection) refresh();
    },
  });
  ctrl.closedPromise.then(() => clearTimeout(timer));
  return ctrl;
}
