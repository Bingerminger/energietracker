// =====================================================================
// v3.13.0 (Paket vertrag) — Auszahlungen der Einspeisung an der Saldo-Karte.
//
// Die Karte zeigt im Abrechnungsjahr erwartet, erhalten und offen (Server:
// Vertragsstatus `payouts`, FeedInPayoutService::annotate). Hier stehen die
// erfassten Auszahlungen des Zählers mit „Auszahlung erfassen“ (Tag, Betrag,
// Zeitraum optional, Notiz) und „Löschen“ mit „Rückgängig“ (legt den Eintrag
// neu an). Gerechnet wird nichts: Was in das Jahr fällt, sagt der Server.
//   GET/POST /api/feed-in-payouts, DELETE /api/feed-in-payouts/{id}
// =====================================================================
import { api } from '../api.js';
import { t } from '../lib/i18n.js';
import { fmt, escapeHtml, parseDecimal, todayIso } from '../lib/format.js';
import { toastErr, toastOk, toastUndo } from './toast.js';
import { maxAttr } from '../lib/text-limits.js';   // v3.13.0 (Paket F) — Höchstlängen

const esc = escapeHtml;
const KEEP = ['meter_id', 'date', 'amount', 'period_from', 'period_until', 'note', 'source'];

/** Zeitraum einer Auszahlung als Text, ohne Zeitraum leer. */
export function payoutPeriodText(p) {
  return p?.period_from && p?.period_until ? t('feedInPayouts.periodText', { from: fmt.date(p.period_from), until: fmt.date(p.period_until) }) : '';
}

function listHtml(list) {
  if (!list.length) return `<p class="muted small" data-role="fip-empty">${esc(t('feedInPayouts.empty'))}</p>`;
  const rows = [...list].reverse().map(p => `<tr data-fip="${esc(p.id)}">
      <td>${esc(fmt.date(p.date))}</td>
      <td class="num">${esc(fmt.eur(p.amount))}</td>
      <td>${esc(payoutPeriodText(p))}</td>
      <td>${esc(p.note || '')}</td>
      <td><button type="button" class="btn btn--sm btn--ghost" data-fip-delete="${esc(p.id)}"
        aria-label="${esc(t('feedInPayouts.deleteNamed', { date: fmt.date(p.date) }))}">${esc(t('feedInPayouts.delete'))}</button></td>
    </tr>`).join('');
  return `<div class="table-wrap"><table class="table table--compact" data-role="fip-list">
      <thead><tr><th scope="col">${esc(t('feedInPayouts.colDate'))}</th><th scope="col" class="num">${esc(t('feedInPayouts.colAmount'))}</th>
        <th scope="col">${esc(t('feedInPayouts.colPeriod'))}</th><th scope="col">${esc(t('feedInPayouts.colNote'))}</th>
        <th scope="col"><span class="sr-only">${esc(t('common.actions'))}</span></th></tr></thead>
      <tbody>${rows}</tbody></table></div>`;
}

function formHtml() {
  return `<form class="fgrid" data-role="fip-form" novalidate hidden>
      <div class="field field--s"><label for="fip-date">${esc(t('feedInPayouts.date'))}</label>
        <input class="input" type="date" id="fip-date" value="${esc(todayIso())}" required></div>
      <div class="field field--s"><label for="fip-amount">${esc(t('feedInPayouts.amount'))}</label>
        <input class="input" id="fip-amount" inputmode="decimal" autocomplete="off" required></div>
      <div class="field field--s"><label for="fip-from">${esc(t('feedInPayouts.periodFrom'))}</label>
        <input class="input" type="date" id="fip-from" aria-describedby="fip-period-hint"></div>
      <div class="field field--s"><label for="fip-until">${esc(t('feedInPayouts.periodUntil'))}</label>
        <input class="input" type="date" id="fip-until" aria-describedby="fip-period-hint"></div>
      <div class="field field--full"><label for="fip-note">${esc(t('feedInPayouts.note'))}</label>
        <input class="input" id="fip-note" ${maxAttr('feed_in_payouts', 'note')} autocomplete="off"></div>
      <p class="muted small field--full" id="fip-period-hint">${esc(t('feedInPayouts.periodHint'))}</p>
      <div class="field field--full">
        <button type="submit" class="btn btn--primary" data-role="fip-save">${esc(t('common.save'))}</button>
        <button type="button" class="btn btn--ghost" data-role="fip-cancel">${esc(t('common.cancel'))}</button>
      </div>
    </form>`;
}

/**
 * Füllt den Platz an der Saldo-Karte der Einspeisung.
 *
 * @param {HTMLElement|null} slot `[data-role="feed-in-payouts"]`
 * @param {{meterId:string, onChange?:() => void}} opts
 */
export async function fillFeedInPayouts(slot, { meterId, onChange = () => {} } = {}) {
  if (!slot || !meterId) return;
  let list = [];
  try { list = await api.feedInPayouts(meterId); } catch { return; }   // ohne Antwort (ältere Server, Demo) bleibt der Platz leer
  slot.innerHTML = `
    <div class="card__head" data-role="fip">
      <h3 class="card__title">${esc(t('feedInPayouts.title'))}</h3>
      <button type="button" class="btn btn--sm btn--primary" data-role="fip-add">${esc(t('feedInPayouts.add'))}</button>
    </div>
    ${formHtml()}
    ${listHtml(Array.isArray(list) ? list : [])}`;
  const form = slot.querySelector('[data-role="fip-form"]');
  const $ = (sel) => slot.querySelector(sel);
  $('[data-role="fip-add"]').addEventListener('click', () => { form.hidden = false; $('#fip-amount').focus(); });
  $('[data-role="fip-cancel"]').addEventListener('click', () => { form.hidden = true; });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const amount = parseDecimal($('#fip-amount').value.trim());
    const date = $('#fip-date').value;
    const from = $('#fip-from').value || null, until = $('#fip-until').value || null;
    const bad = amount === null || !date || (!!from !== !!until) || (from && until && from > until);
    $('#fip-amount').classList.toggle('invalid', amount === null);
    if (bad) { toastErr(t('feedInPayouts.invalid')); return; }
    try {
      await api.createFeedInPayout({ meter_id: meterId, date, amount, period_from: from, period_until: until, note: $('#fip-note').value.trim() });
      toastOk(t('feedInPayouts.saved'));
      onChange();
    } catch (err) { toastErr(err.message); }
  });
  slot.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-fip-delete]');
    if (!b) return;
    try {
      const res = await api.deleteFeedInPayout(b.dataset.fipDelete);
      const old = res?.payout;
      onChange();
      if (old) {
        toastUndo(t('feedInPayouts.deleted'), async () => {
          try { await api.createFeedInPayout(Object.fromEntries(KEEP.map(k => [k, old[k] ?? null]))); onChange(); }
          catch (err) { toastErr(err.message); }
        });
      }
    } catch (err) { toastErr(err.message); }
  });
}
