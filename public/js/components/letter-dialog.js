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
// =====================================================================

import { api } from '../api.js';
import { openModal, guardSubmit } from './modal.js';
import { markEventDone } from './done-dialog.js';
import { toastOk, toastErr } from './toast.js';
import { t, tp } from '../lib/i18n.js';
import { escapeHtml as esc, fmt, todayIso } from '../lib/format.js';
import { copyText } from '../lib/clipboard.js';
import { rightsFooterHtml } from '../lib/letter.js';

/** Nur dieses Gerät, nur auf Wunsch (Entscheidung 10a). */
export const REMEMBER_KEY = 'et.letter.sender';

/** v3.11.0 (Skeptiker N12) — Dateiname des PDF je Vorlage, wie LetterService::FILE_NAMES (der Brief ist Deutsch). */
const FILE_NAMES = {
  special_termination: 'sonderkuendigung', advance_adjustment: 'abschlagsanpassung',
  estimate_query: 'rueckfrage-schaetzung', complaint: 'beanstandung', objection: 'widerspruch',
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
};

function remembered() {
  try { return JSON.parse(localStorage.getItem(REMEMBER_KEY) || 'null'); } catch { return null; }
}

function remember(on, data) {
  try {
    if (on) localStorage.setItem(REMEMBER_KEY, JSON.stringify(data));
    else localStorage.removeItem(REMEMBER_KEY);
  } catch { /* ohne Speicher: nur für diesen Dialog */ }
}

const lines = (v) => String(v || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);

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
 * Den Dialog zu einem Befund öffnen. `finding` braucht nur {id, letter, title?}.
 * @returns {{close: Function}|null}
 */
export function openLetterDialog(finding) {
  const kind = finding.letter;
  const saved = remembered();
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
        <p class="muted small">${esc(t('letters.dialog.german'))}</p>
        ${isObjection ? `
        <div class="letter-dialog__confirm" data-role="letter-confirm">
          <label class="check"><input type="checkbox" id="lt-confirm"> ${esc(t('letters.dialog.confirmObjection'))}</label>
          <p class="muted small">${esc(t('letters.dialog.objectionForm'))}</p>
        </div>` : ''}
        <div class="fgrid">
          <div class="field">
            <label for="lt-sender">${esc(t('letters.dialog.sender'))}</label>
            <textarea class="input" id="lt-sender" rows="4" autocomplete="off">${esc((saved?.sender || []).join('\n'))}</textarea>
          </div>
          <div class="field">
            <label for="lt-recipient">${esc(t('letters.dialog.recipient'))}</label>
            <textarea class="input" id="lt-recipient" rows="4" autocomplete="off" aria-describedby="lt-recipient-hint"></textarea>
            <p class="muted small" id="lt-recipient-hint">${esc(t('letters.dialog.recipientHint'))}</p>
          </div>
          <div class="field field--s">
            <label for="lt-place">${esc(t('letters.dialog.place'))}</label>
            <input class="input" type="text" id="lt-place" maxlength="60" autocomplete="off" value="${esc(saved?.place || '')}">
          </div>
          <div class="field field--l">
            <label class="check"><input type="checkbox" id="lt-remember"${saved ? ' checked' : ''}> ${esc(t('letters.dialog.remember'))}</label>
            <p class="muted small">${esc(t('letters.dialog.rememberHint'))}</p>
          </div>
        </div>
        <fieldset class="letter-dialog__refs">
          <legend>${esc(t('letters.dialog.refs'))}</legend>
          <div class="fgrid" data-role="letter-refs"></div>
        </fieldset>
        <div class="fgrid" data-role="letter-extra">${Object.entries(EXTRA[kind] || {}).map(([n, ty]) => extraField(kind, n, ty)).join('')}</div>
        <p class="muted small" data-role="letter-gaps" aria-live="polite"></p>
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

      // Eingaben → Anfrage. Erste Anfrage ohne Empfänger, Bezug und Felder: dann gilt die Vorbelegung.
      const body = () => {
        const b = { finding_id: finding.id, kind, sender: lines($('#lt-sender').value), place: $('#lt-place').value.trim() };
        if (isObjection) b.extra = { confirm_objection: confirmed() };
        if (!model) return b;
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

      const fillForm = (m) => {
        $('#lt-recipient').value = (m.recipient || []).join('\n');
        const keys = [...new Set([...m.refs.map(r => r.key), ...Object.keys(m.defaults.refs || {}), ...ALWAYS_REFS])];
        const values = Object.fromEntries(m.refs.map(r => [r.key, (m.gaps || []).includes(r.key) ? '' : r.value]));
        $('[data-role="letter-refs"]').innerHTML = keys.map(k => `
          <div class="field">
            <label for="lt-ref-${esc(k)}">${esc(t(`letters.ref.${k}`))}</label>
            <input class="input" type="text" id="lt-ref-${esc(k)}" data-ref="${esc(k)}" maxlength="60" autocomplete="off" value="${esc(values[k] ?? m.defaults.refs?.[k] ?? '')}">
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
          ${f.deadline ? `<p class="small" data-role="letter-deadline">${esc(t('letters.dialog.deadline', { date: fmt.date(f.deadline) }))}</p>` : ''}
          ${rightsFooterHtml(f)}`;
        btn('sent').hidden = !f.todo;
      };

      const show = (m) => {
        preview.textContent = m.text;
        const n = (m.gaps || []).length;
        $('[data-role="letter-gaps"]').textContent = n ? tp('letters.dialog.gaps', n) : '';
        setReady(true);
      };

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
        } catch (e) {
          if (mine !== seq) return;
          setReady(false);
          preview.textContent = e.message || String(e);
        }
      };
      const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };

      bodyEl.addEventListener('input', (e) => { if (e.target.id !== 'lt-remember') later(); });
      bodyEl.addEventListener('change', (e) => {
        if (e.target.id === 'lt-remember') {
          remember(e.target.checked, { sender: lines($('#lt-sender').value), place: $('#lt-place').value.trim() });
          return;
        }
        if (e.target.type === 'checkbox') refresh();
      });

      const keep = () => {
        if ($('#lt-remember').checked) remember(true, { sender: lines($('#lt-sender').value), place: $('#lt-place').value.trim() });
      };
      btn('copy').addEventListener('click', async () => {
        if (!model) return;
        keep();
        if (await copyText(model.text)) toastOk(t('letters.dialog.copied'));
        else toastErr(t('letters.dialog.copyFailed'));
      });
      const pdfBtn = btn('pdf');
      pdfBtn.addEventListener('click', guardSubmit(pdfBtn, async () => {
        keep();
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
