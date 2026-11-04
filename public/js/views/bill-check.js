// =====================================================================
// Energietracker — Rechnung prüfen (F1012, v2.5.0; eigene Seite seit v2.11.0)
//
// Rechnet die Versorgerrechnung nach: Für den gewählten Zeitraum liefert das
// Backend Abschnitte an jeder Ablesung (bei Gas auch an jedem
// Brennwertwechsel, sonst an Vertrags- und Preisstichtagen) — mit Menge und,
// seit v3.1.0, mit den Kosten nach denselben Regeln wie Monatssicht und Saldo.
//
// v3.1.0 (Paket H5, UI-35/MKT-17) — für alle Arten mit Zählerständen und
// Verträgen (Gas, Strom, Fernwärme, Wasser), dazu „Laut Rechnung": die Werte
// des Versorgers eintragen, die App zeigt die Abweichung und bucht das
// Ergebnis auf Wunsch als Sonderzahlung.
// =====================================================================

import { api } from '../api.js';
import { activeUtilities, getSettings } from '../state.js';
import { fmt, escapeHtml, parseDecimal, formatForInput, todayIso } from '../lib/format.js';
import { toastErr, toastOk } from '../components/toast.js';
import { confirmModal } from '../components/modal.js';
import { t, tp, getCurrencyMinor } from '../lib/i18n.js';
import { info } from '../components/info.js';
import { periodToCheck, exclusiveEnd, addDays } from '../lib/billing-period.js';
import { attachmentNames, attachmentLinksHtml } from '../lib/attachments.js';   // v3.7.0
import { restNoteHtml } from '../lib/rest-billing.js';   // v3.9.0 (F1032, #40) — Menge = Rest
import { offerThanks, compareThanks } from '../lib/thanks.js';   // v3.10.0 — Danke sagen
import { letterButton, bindLetterButtons, rightsFooterHtml } from '../lib/letter.js';   // v3.11.0 (F1035) — Rechte als Kunde

/** v3.11.0 (Paket rechnung) — Arten eines Postens (BillService::ITEM_KINDS), „Sonstiges“ zuerst wie bisher. */
const ITEM_KINDS = ['other', 'levy', 'fee', 'credit', 'metering'];

/** v3.11.0 (Paket rechnung) — Befunde „Deine Rechte als Kunde“ einer Art (GET /api/rights, gefiltert im Browser wie in der Pages-Demo). */
async function rightsOf(utility) {
  const r = await api.rights().catch(() => null);
  return (r?.findings || []).filter(f => f.utility === utility && f.bill_id);
}

/**
 * v3.11.0 (Paket rechnung) — die Hinweise zu einer Rechnung: Titel, Text, Fußzeile mit den
 * Normen als Link, „Brief entwerfen“. „Rechnung prüfen“ zeigt alle, auch außerhalb des Fensters
 * von „Zu tun“ (Grundsatz 5).
 */
export function billRightsHtml(findings) {
  if (!findings.length) return '';
  return `<div class="bill-rights" data-role="bill-rights">
    <h4 class="settings-subhead">${escapeHtml(t('rights.title'))}</h4>
    ${findings.map(f => `<div class="banner banner--${f.severity === 'warning' ? 'warning' : 'info'}" data-role="bill-right" data-check="${escapeHtml(f.check)}">
      <p><strong>${escapeHtml(f.title)}</strong></p>
      <p>${escapeHtml(f.text)}</p>
      ${f.deadline ? `<p class="small">${escapeHtml(t('rights.deadline', { date: fmt.date(f.deadline) }))}</p>` : ''}
      ${rightsFooterHtml(f)}
      ${letterButton(f)}
    </div>`).join('')}
  </div>`;
}

const ISO = /^\d{4}-\d{2}-\d{2}$/;

/**
 * v3.4.0 (N1021) — Zeitraum aus der Adresse: `from` + `until` (einschließlich),
 * ältere Verweise mit `to` (ausschließlich, bis v3.3) gelten weiter.
 * @returns {{from: string, until: string}|null}
 */
export function periodFromQuery(query) {
  const from = query.get('from') || '';
  if (!ISO.test(from)) return null;
  const until = query.get('until') || '';
  if (ISO.test(until)) return { from, until };
  const to = query.get('to') || '';
  return ISO.test(to) ? { from, until: addDays(to, -1) } : null;
}

/**
 * v3.4.0 (N1021) — Die Seite rechnet immer gleich: mit dem Zeitraum aus der
 * Adresse, sonst der letzten abgeschlossenen Abrechnungsperiode der Art
 * (Stichtag `billing_cycle_anchor_<art>`). Bis v3.3 rechnete sie nur mit
 * Zeitraum in der Adresse — über „Kosten → Rechnung prüfen“ blieb das
 * Ergebnis leer, und die Verbrauchsliste schien zu fehlen. Ein Wechsel von Art
 * oder Zähler behält den Zeitraum und rechnet neu; die Auswahl steht per
 * replaceState in der Adresse.
 */
export async function render(container, _params = [], ctx = {}) {
  const query = ctx.query || new URLSearchParams();
  const [all, settings] = await Promise.all([
    activeUtilities().catch(() => []),
    getSettings().catch(() => ({})),
  ]);
  const sel = {
    utils: all.filter(u => u.supports_bill_check),
    settings: settings || {},
    utility: query.get('utility'),
    meter: query.get('meter'),
    period: periodFromQuery(query),
    // v3.5.0 (F1029) — Sprung aus „Abrechnung erwartet“: Abschnitt „Rechnung des Versorgers“ offen
    capture: query.get('capture') === '1',
  };
  await draw(container, sel);
}

/** Auswahl in die Adresse — teilbar, übersteht Neuladen, ohne neuen Verlaufseintrag. */
function syncAddress(u, meter, period) {
  const q = new URLSearchParams({ utility: u.key, meter: meter.id, from: period.from, until: period.until });
  const hash = `#/bill-check?${q}`;
  if (window.location.hash !== hash) {
    try { history.replaceState(history.state, '', hash); } catch { /* egal */ }
  }
}

async function draw(container, sel) {
  const { utils } = sel;
  const u = utils.find(x => x.key === sel.utility) || utils.find(x => x.key === 'gas') || utils[0];
  // v3.1.0 (H5) — Untertitel je Art: Gas mit Zustandszahl und Brennwert, sonst Arbeits- und Grundpreis
  const header = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('nav.billCheck'))}</h1>
        <p class="view-header__subtitle">${t(!u || u.key === 'gas' ? 'utility.billCheck.hint' : 'utility.billCheck.hintGeneric')}</p>
      </div>
    </div>`;
  const meters = u ? await api.meters(u.key).catch(() => []) : [];
  if (!u || !meters.length) {
    container.innerHTML = `${header}
      <div class="empty">
        <p>${escapeHtml(t('billCheck.noMeter'))}</p>
        <a class="btn btn--primary" href="#/utility/${escapeHtml(u?.key || 'gas')}/meters">${escapeHtml(t('billCheck.addMeter'))}</a>
      </div>`;
    return;
  }

  let current = meters.find(m => m.id === sel.meter) || meters[0];
  // v3.6.0 (Paket abrechnung) — ohne Zeitraum in der Adresse: die jüngste
  // abgeschlossene Abrechnungsperiode wie im Abrechnungsjahr (Server), sonst Stichtag
  const periods = sel.period ? null : await api.meterBillingPeriods(u.key, current.id).then(d => d?.periods).catch(() => null);
  const period = sel.period || periodToCheck(periods, sel.settings[`billing_cycle_anchor_${u.key}`], todayIso());

  container.innerHTML = `${header}
    <div class="card" data-utility="${escapeHtml(u.key)}">
      <div class="form-row">
        ${utils.length > 1 ? `
        <div class="field">
          <label for="bc-utility">${escapeHtml(t('billCheck.pickUtility'))}</label>
          <select class="select" id="bc-utility">
            ${utils.map(x => `<option value="${escapeHtml(x.key)}"${x.key === u.key ? ' selected' : ''}>${escapeHtml(x.icon || '')} ${escapeHtml(x.label)}</option>`).join('')}
          </select>
        </div>` : ''}
        ${meters.length > 1 ? `
        <div class="field">
          <label for="bc-meter">${escapeHtml(t('billCheck.meter'))}</label>
          <select class="select" id="bc-meter">
            ${meters.map(m => `<option value="${escapeHtml(m.id)}"${m.id === current.id ? ' selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
          </select>
        </div>` : ''}
      </div>
      ${billCheckForm(period.from, period.until)}
      ${sel.period ? '' : `<p class="muted small" data-role="period-hint">${escapeHtml(t('billCheck.periodDefault'))}</p>`}
      <div id="bc-result"></div>
    </div>
    <!-- v3.11.0 (Nachprüfung NP6) — ohne erfasste Rechnung sichtbar, nicht im zugeklappten Abschnitt -->
    <p class="muted small" data-role="rights-no-bill" hidden></p>
    <details class="card bill-capture" data-role="bills"><summary class="card__title">${escapeHtml(t('billCheck.invoice.capture'))}</summary></details>`;

  const readPeriod = () => ({
    from: container.querySelector('#bc-from')?.value || '',
    until: container.querySelector('#bc-until')?.value || '',
  });
  container.querySelector('#bc-utility')?.addEventListener('change', async (e) => {
    // Zeitraum behalten, Zähler der neuen Art; dann neu rechnen
    const p = readPeriod();
    await draw(container, { ...sel, utility: e.target.value, meter: null,
      period: ISO.test(p.from) && ISO.test(p.until) ? p : sel.period });
    container.querySelector('#bc-utility')?.focus();
  });
  container.querySelector('#bc-meter')?.addEventListener('change', (e) => {
    current = meters.find(m => m.id === e.target.value) || current;
    container.querySelector('#bc-run')?.click();
    drawBills(container, u, current, readPeriod);
  });
  wireBillCheck(container, u, () => current, (p) => { syncAddress(u, current, p); showBillPeriod(container); });
  ['#bc-from', '#bc-until'].forEach(s => container.querySelector(s)?.addEventListener('change', () => showBillPeriod(container)));
  if (sel.capture) {
    const capture = container.querySelector('[data-role="bills"]');
    if (capture) capture.open = true;
  }
  drawBills(container, u, current, readPeriod);
  container.querySelector('#bc-run')?.click();
}

/**
 * Eingaben „Von – Bis einschließlich“ und der Knopf. Seit v3.4.0 (N1021)
 * einschließlich wie auf der Rechnung und wie beim Erfassen; bis v3.3 hieß es
 * hier „Bis (ausschließlich)“ mit dem 01.01. des Folgejahres.
 */
export function billCheckForm(from, until) {
  return `
      <div class="fgrid">
        <div class="field field--s">
          <label for="bc-from">${t('utility.billCheck.from')}</label>
          <input class="input" id="bc-from" type="date" value="${escapeHtml(from)}">
        </div>
        <div class="field field--s">
          <label for="bc-until">${t('billCheck.invoice.toInclusive')}</label>
          <input class="input" id="bc-until" type="date" value="${escapeHtml(until)}">
        </div>
        <div class="field field--s">
          <button type="button" class="btn btn--util" id="bc-run">${t('utility.billCheck.run')}</button>
        </div>
      </div>`;
}

function reasonLabel(reason) {
  const map = {
    start: 'start', end: 'end', reading: 'reading',
    reading_estimated: 'readingEstimated', factor: 'factor', price: 'price',
  };
  // Kombinationen wie „reading+factor": beide Gründe nennen
  return reason.split('+').map(r => t('utility.billCheck.reason.' + (map[r] || r))).join(' · ');
}

// v2.5.2 — Zählerstand an einer Abschnittsgrenze mit Ableseart, wie die
// Rechnung ihn ausweist: abgelesen (ohne Zusatz), als geschätzt erfasst (S)
// oder Ersatzwert (E) — kein Stand an diesem Tag, tagesgenau interpoliert.
// Die Fußnote unter der Tabelle erklärt die Kürzel.
function counterCell(value, kind) {
  if (kind == null) return `<td class="num muted counter-cell">–</td>`;
  const mark = kind === 'reading_estimated' ? t('utility.billCheck.mark.estimated')
             : kind === 'interpolated'      ? t('utility.billCheck.mark.interpolated') : '';
  const kindKey = kind === 'reading_estimated' ? 'readingEstimated' : kind;
  const title = escapeHtml(t('utility.billCheck.kind.' + kindKey));
  const val = value != null ? fmt.num(value, Number.isInteger(value) ? 0 : 1) : '–';
  return `<td class="num counter-cell" data-kind="${escapeHtml(kind)}" title="${title}">${val}${mark ? `<sup class="muted"> ${mark}</sup>` : ''}</td>`;
}

const eur = (v) => v == null ? '–' : fmt.eur(v);

export function renderBillCheck(bill, u = { key: 'gas' }) {
  const rows = bill.rows || [];
  if (!rows.length) return `<p class="muted">${t('utility.billCheck.empty')}</p>`;
  const tot = bill.totals || {};
  const gas = u.key === 'gas';
  const water = u.key === 'wasser';
  const qty = water ? 'm3' : 'kwh';
  const qtyUnit = water ? 'm³' : 'kWh';
  const costCols = `
        <th scope="col" class="num">${t('billCheck.row.price', { minor: getCurrencyMinor(), unit: qtyUnit })}</th>
        <th scope="col" class="num">${t('billCheck.row.energyCost')}</th>
        <th scope="col" class="num">${t('billCheck.row.fixed')}</th>`;
  const costCells = (r) => `
            <td class="num">${water ? '' : (r.ct_per_kwh != null ? fmt.num(r.ct_per_kwh, 2) : '–')}</td>
            <td class="num">${eur(r.energy_cost)}</td>
            <td class="num">${eur(r.fixed_cost)}</td>`;
  return `
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr>
        <th scope="col">${t('utility.billCheck.col.period')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.counterFrom')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.counterTo')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.days')}</th>
        <th scope="col">${t('utility.billCheck.col.reason')}</th>
        ${gas ? `
        <th scope="col" class="num">m³</th>
        <th scope="col" class="num">${t('utility.billCheck.col.z')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.hs')}</th>
        <th scope="col" class="num">kWh/m³</th>` : ''}
        <th scope="col" class="num">${qtyUnit}</th>
        ${costCols}
      </tr></thead>
      <tbody>
        ${rows.map(r => `
          <tr class="${r[gas ? 'm3' : qty] == null ? 'muted' : ''}">
            <td>${fmt.date(r.from)} – ${fmt.date(r.to_inclusive)}</td>
            ${counterCell(r.counter_from, r.counter_from_kind)}
            ${counterCell(r.counter_to, r.counter_to_kind)}
            <td class="num">${r.days}</td>
            <td>${reasonLabel(r.reason)}</td>
            ${gas ? `
            <td class="num">${r.m3 != null ? fmt.num(r.m3, 1) : `<em>${t('utility.billCheck.noReading')}</em>`}</td>
            <td class="num">${r.zustandszahl != null ? fmt.num(r.zustandszahl, 4) : '–'}</td>
            <td class="num">${r.brennwert != null ? fmt.num(r.brennwert, 3) : '–'}</td>
            <td class="num">${fmt.num(r.kwh_per_m3, 3)}</td>` : ''}
            <td class="num"><strong>${r[qty] != null ? fmt.num(r[qty], water ? 2 : 0) : (gas ? '–' : `<em>${t('utility.billCheck.noReading')}</em>`)}</strong></td>
            ${costCells(r)}
          </tr>`).join('')}
      </tbody>
      <tfoot><tr>
        <td><strong>${t('utility.billCheck.total')}</strong></td>
        <td></td><td></td>
        <td class="num">${tot.days ?? ''}</td>
        <td></td>
        ${gas ? `<td class="num"><strong>${fmt.num(tot.m3, 1)}</strong></td><td></td><td></td><td></td>` : ''}
        <td class="num"><strong>${fmt.num(tot[qty], water ? 2 : 0)}</strong></td>
        <td></td>
        <td class="num">${eur(tot.energy_cost)}</td>
        <td class="num">${eur(tot.fixed_cost)}</td>
      </tr></tfoot>
    </table></div>
    <p class="bill-check-total"><strong>${escapeHtml(tot.bonus ? t('billCheck.ownTotal', { total: eur(tot.total), rebate: eur(tot.bonus) }) : t('billCheck.ownTotalOnly', { total: eur(tot.total) }))}</strong></p>
    ${restNoteHtml(tot.rest_of, qtyUnit)}
    <p class="muted bill-check-legend" style="margin-top:8px">${t('utility.billCheck.legend', { est: t('utility.billCheck.mark.estimated'), int: t('utility.billCheck.mark.interpolated') })}</p>
    ${tot.gaps ? `<p class="muted" style="margin-top:8px">${tp('utility.billCheck.gapsN', tot.gaps)}</p>` : ''}
    ${tot.price_missing ? `<p class="muted">${escapeHtml(t('billCheck.reason.price_missing'))}</p>` : ''}
    ${gas ? `<p class="muted" style="margin-top:8px">${t('utility.billCheck.formula')}</p>` : ''}`;
}

/**
 * @param {HTMLElement} container
 * @param {{key: string}} u
 * @param {() => {id: string}} getMeter  der gerade gewählte Zähler
 * @param {(p: {from: string, until: string}) => void} [onRun]  Zeitraum gerechnet (Adresse)
 */
export function wireBillCheck(container, u, getMeter, onRun = () => {}) {
  const btn = container.querySelector('#bc-run');
  const out = container.querySelector('#bc-result');
  if (!btn || !out) return;
  let seq = 0;
  btn.addEventListener('click', async () => {
    const from  = container.querySelector('#bc-from')?.value;
    const until = container.querySelector('#bc-until')?.value;
    if (!ISO.test(from || '') || !ISO.test(until || '') || from > until) { toastErr(t('utility.billCheck.errRange')); return; }
    onRun({ from, until });
    const my = ++seq;   // nur die letzte Rechnung zeigt ihr Ergebnis
    out.innerHTML = `<div class="loading">${t('common.loading')}</div>`;
    try {
      // Die Schnittstelle nimmt `to` ausschließlich
      const bill = await api.billCheck(u.key, getMeter().id, from, exclusiveEnd(until));
      if (my === seq) out.innerHTML = renderBillCheck(bill, u);
    } catch (e) {
      if (my !== seq) return;
      out.innerHTML = '';
      toastErr(e.message);
    }
  });
}

// ── v3.1.0 (H5, UI-35) — „Laut Rechnung": Werte des Versorgers ───────

// v3.4.0 (N1021) — „Rechnung des Versorgers erfassen (optional)“: eingeklappt
// unter dem Ergebnis, mit der Liste der erfassten Rechnungen. Der Zeitraum ist
// der eine oben (bis einschließlich); bis v3.3 hatte das Formular einen
// eigenen, und es stand gleich unter der Auswahl — die Seite las sich wie
// „Rechnung erfassen“. Bearbeiten setzt den Zeitraum oben auf den der Rechnung.
async function drawBills(container, u, meter, readPeriod) {
  const el = container.querySelector('[data-role="bills"]');
  if (!el) return;
  const water = u.key === 'wasser';
  const bills = await api.bills(u.key, meter.id).catch(() => []);
  const docNames = await attachmentNames(bills);   // v3.7.0 — Dateinamen der Belege
  const rights = bills.length ? await rightsOf(u.key) : [];   // v3.11.0 (Paket rechnung)
  // v3.11.0 (Skeptiker M7, Nachprüfung NP6) — ohne Rechnung über dem zugeklappten Abschnitt: Die Rechte-Prüfungen
  // laufen ab der ersten erfassten Rechnung (nur, wo die App Regeln kennt: `enabled`)
  const noBill = container.querySelector('[data-role="rights-no-bill"]');
  if (noBill) {
    const on = !bills.length && (await api.rights().catch(() => null))?.enabled !== false;
    noBill.hidden = !on;
    noBill.innerHTML = on ? `${escapeHtml(t('billCheck.rightsNoBill'))} <a href="#/inbox">${escapeHtml(t('billCheck.rightsInbox'))}</a>` : '';
  }
  el.innerHTML = `
    <summary class="card__title">${escapeHtml(t('billCheck.invoice.capture'))}${bills.length ? ` <span class="muted small" data-role="bill-count">· ${escapeHtml(tp('billCheck.invoice.captured', bills.length))}</span>` : ''}</summary>
    <h2 class="settings-subhead">${t('billCheck.invoice.title')}${info('utilityStatement')}</h2>
    <p class="muted">${t('billCheck.invoice.hint')}</p>
    <form id="bi-form" class="bill-form">
      <p class="muted small" data-role="bill-period"></p>
      <!-- v3.4.0 (N1021) — Formularraster wie in den Dialogen -->
      <div class="fgrid">
        <div class="field field--s"><label for="bi-issued">${t('billCheck.invoice.issued')}</label><input class="input" id="bi-issued" name="issued_on" type="date"></div>
        <!-- v3.11.0 (Paket rechnung) — Zugang der Rechnung: Frist des Versorgers (A1) -->
        <div class="field field--s"><label for="bi-received">${t('billCheck.invoice.received')}</label><input class="input" id="bi-received" name="received_on" type="date" aria-describedby="bi-received-hint">
          <small class="settings-field__hint" id="bi-received-hint">${t('billCheck.invoice.receivedHint')}</small></div>
        <!-- v3.11.0 (integration) — Rechnungsnummer: Bezug in den Briefen zu dieser Rechnung -->
        <div class="field field--s"><label for="bi-number">${t('billCheck.invoice.number')}</label><input class="input" id="bi-number" name="invoice_number" type="text" maxlength="80" autocomplete="off" aria-describedby="bi-number-hint">
          <small class="settings-field__hint" id="bi-number-hint">${t('billCheck.invoice.numberHint')}</small></div>
        <div class="field field--s"><label for="bi-qty">${water ? t('billCheck.invoice.m3') : t('billCheck.invoice.kwh')}</label><input class="input" id="bi-qty" name="qty" type="text" inputmode="decimal"></div>
        <div class="field field--s"><label for="bi-amount">${t('billCheck.invoice.amount')}</label><input class="input" id="bi-amount" name="amount" type="text" inputmode="decimal"></div>
        <div class="field field--s"><label for="bi-adv">${t('billCheck.invoice.advances')}</label><input class="input" id="bi-adv" name="advances" type="text" inputmode="decimal"></div>
        <div class="field field--s"><label for="bi-res">${t('billCheck.invoice.result')}</label><input class="input" id="bi-res" name="result" type="text" inputmode="decimal" placeholder="${escapeHtml(t('billCheck.invoice.resultHint'))}"></div>
        ${u.key === 'gas' ? `
        <div class="field field--s"><label for="bi-co2kg">${t('co2split.statement.emissions')}</label><input class="input" id="bi-co2kg" name="co2_kg" type="text" inputmode="decimal"></div>
        <div class="field field--s"><label for="bi-co2eur">${t('billCheck.invoice.co2Cost')}</label><input class="input" id="bi-co2eur" name="co2_eur" type="text" inputmode="decimal"></div>` : ''}
      </div>
      <!-- v3.11.0 (Paket rechnung) — die Rechnung nennt einen Stand geschätzt (A5) -->
      <fieldset class="fgroup" data-role="bill-estimated"><legend>${t('billCheck.invoice.estimated')}</legend>
        <div class="fgrid">
          <div class="field field--s"><label class="check"><input type="checkbox" name="est_start"> ${t('billCheck.invoice.estimatedStart')}</label></div>
          <div class="field field--s"><label class="check"><input type="checkbox" name="est_end"> ${t('billCheck.invoice.estimatedEnd')}</label></div>
          <!-- v3.11.0 (Skeptiker M5) — der geschätzte Endstand an der Rechnung, keine Ablesung -->
          <div class="field field--s"><label for="bi-est-value">${t('billCheck.invoice.estimatedValue', { unit: escapeHtml(u.unit || '') })}</label><input class="input" id="bi-est-value" name="est_value" type="text" inputmode="decimal"></div>
          <div class="field field--s"><label for="bi-est-on">${t('billCheck.invoice.estimatedOn')}</label><input class="input" id="bi-est-on" name="est_on" type="date"></div>
        </div>
        <p class="fgroup__hint">${t('billCheck.invoice.estimatedHint')}</p>
      </fieldset>
      <fieldset class="fgroup"><legend>${t('billCheck.invoice.items')}</legend>
        <button type="button" class="btn btn--ghost btn--sm fgroup__add" data-add-item>${t('billCheck.invoice.addItem')}</button>
        <div class="rowlist rowlist--item" data-items></div>
      </fieldset>
      ${water ? `
      <!-- v3.11.0 (Paket rechnung) — Gebührenbescheid einer Behörde oder Gemeinde (A7) -->
      <fieldset class="fgroup" data-role="bill-notice"><legend>${t('billCheck.invoice.notice')}</legend>
        <div class="fgrid">
          <div class="field field--full"><label class="check"><input type="checkbox" name="notice_public"> ${t('billCheck.invoice.noticePublic')}</label></div>
          <div class="field field--s"><label for="bi-notice-dated">${t('billCheck.invoice.noticeDated')}</label><input class="input" id="bi-notice-dated" name="notice_dated" type="date"></div>
          <div class="field field--s"><label for="bi-notice-served">${t('billCheck.invoice.noticeServed')}</label><input class="input" id="bi-notice-served" name="notice_served" type="date"></div>
          <div class="field"><label for="bi-notice-remedy">${t('billCheck.invoice.noticeRemedy')}</label>
            <select class="input" id="bi-notice-remedy" name="notice_remedy" aria-describedby="bi-notice-remedy-hint">
              <option value="">${t('billCheck.invoice.notStated')}</option>
              <option value="objection">${t('inbox.option.objection')}</option>
              <option value="action">${t('inbox.option.action')}</option>
            </select>
            <small class="settings-field__hint" id="bi-notice-remedy-hint">${t('billCheck.invoice.noticeRemedyHint')}</small></div>
          <div class="field"><label for="bi-notice-file">${t('billCheck.invoice.noticeFile')}</label><input class="input input--text" id="bi-notice-file" name="notice_file" type="text" maxlength="80"></div>
        </div>
      </fieldset>` : ''}
      <div class="fgrid">
        <div class="field field--full">
          <label class="btn btn--ghost btn--sm"><span aria-hidden="true">📄</span> ${t('billCheck.invoice.attach')}
            <input type="file" accept="application/pdf,image/*" data-role="bill-pdf" class="sr-only"></label>
          <span class="muted small" data-role="bill-pdf-n"></span>
        </div>
      </div>
      <p class="muted small" data-role="bill-editing" hidden></p>
      <div class="form-actions">
        <button type="button" class="btn btn--primary" id="bi-save">${t('billCheck.invoice.save')}</button>
        <button type="button" class="btn btn--ghost" id="bi-cancel" hidden>${t('common.cancel')}</button>
      </div>
    </form>
    <div data-role="bill-list">${billList(bills, water, docNames, rights)}</div>
    <div data-role="bill-compare"></div>`;

  let attachments = [];
  let editing = null;   // v3.2.0 — Rechnung, die gerade bearbeitet wird
  // v3.4.0 (N1021) — Bezeichnung | Betrag | ✕ im Zeilenraster
  // v3.11.0 (Paket rechnung) — dazu Art, netto/brutto und Steuersatz als Felder (bis zur Basis nur
  // weitergereicht): Gutschrift (Bonus, A4) und Messstellenbetrieb (A6) trägt man hier von Hand ein
  const opt = (v, cur, label) => `<option value="${escapeHtml(v)}"${v === cur ? ' selected' : ''}>${escapeHtml(label)}</option>`;
  const itemRow = (it = {}) => `<div class="rowlist__row bill-item">
      <div class="field"><label>${t('billCheck.invoice.itemLabel')}</label><input class="input input--text" data-i="label" type="text" maxlength="120" value="${escapeHtml(it.label ?? '')}"></div>
      <div class="field"><label>${t('billCheck.invoice.itemAmount')}</label><input class="input" data-i="amount_eur" type="text" inputmode="decimal" value="${escapeHtml(formatForInput(it.amount_eur))}"></div>
      <div class="field"><label>${t('billCheck.invoice.itemKind')}</label><select class="input" data-i="kind">
        ${ITEM_KINDS.map(k => opt(k, ITEM_KINDS.includes(it.kind) ? it.kind : 'other', t('billCheck.invoice.itemKinds.' + k))).join('')}</select></div>
      <div class="field"><label>${t('billCheck.invoice.itemBasis')}</label><select class="input" data-i="basis">
        ${opt('', it.basis || '', t('billCheck.invoice.notStated'))}${opt('net', it.basis || '', t('inbox.option.net'))}${opt('gross', it.basis || '', t('inbox.option.gross'))}</select></div>
      <div class="field"><label>${t('billCheck.invoice.itemVat')}</label><input class="input" data-i="vat_pct" type="text" inputmode="decimal" value="${escapeHtml(formatForInput(it.vat_pct))}"></div>
      <button type="button" class="btn btn--danger btn--icon rowlist__del" data-del-item title="${escapeHtml(t('tenancy.form.removeRow'))}" aria-label="${escapeHtml(t('tenancy.form.removeRow'))}"><span aria-hidden="true">×</span></button>
    </div>`;
  el.querySelector('[data-add-item]').addEventListener('click', () => el.querySelector('[data-items]').insertAdjacentHTML('beforeend', itemRow()));
  el.addEventListener('click', (e) => { const d = e.target.closest('[data-del-item]'); if (d) d.closest('.bill-item')?.remove(); });
  const pdf = el.querySelector('[data-role="bill-pdf"]');
  pdf.addEventListener('change', async () => {
    const file = pdf.files?.[0];
    if (!file) return;
    try {
      const a = await api.uploadAttachment(file, file.type === 'application/pdf' ? 'bill_pdf' : 'other', file.name);
      attachments.push(a.id);
      el.querySelector('[data-role="bill-pdf-n"]').textContent = tp('tenancy.statement.attached', attachments.length);
    } catch (err) { toastErr(err.message); }
    pdf.value = '';
  });
  el.querySelector('#bi-save').addEventListener('click', async () => {
    const f = el.querySelector('#bi-form');
    const n = (v) => { const s = String(v ?? '').trim(); if (s === '') return null; const x = parseDecimal(s); if (x == null) throw new Error(t('common.invalidNumber', { example: formatForInput(1234.5) })); return x; };
    let data;
    try {
      const invoice = { amount_eur: n(f.amount.value), advances_paid_eur: n(f.advances.value), result_eur: n(f.result.value) };
      invoice[water ? 'volume_m3' : 'energy_kwh'] = n(f.qty.value);
      Object.keys(invoice).forEach(k => invoice[k] == null && delete invoice[k]);
      const val = (r, k) => r.querySelector(`[data-i="${k}"]`)?.value ?? '';
      data = {
        meter_id: meter.id, period_from: readPeriod().from, period_to: readPeriod().until, issued_on: f.issued_on.value || null,
        received_on: f.received_on.value || null,   // v3.11.0 (Paket rechnung) — leer entfernt
        invoice_number: f.invoice_number.value.trim() || null,   // v3.11.0 (integration) — leer entfernt
        invoice,
        // v3.11.0 (Paket rechnung) — Art, netto/brutto und Satz aus den Feldern der Zeile (leer: ohne Angabe)
        items: [...el.querySelectorAll('.bill-item')].map(r => ({ label: val(r, 'label'), amount_eur: n(val(r, 'amount_eur')) ?? 0,
          kind: val(r, 'kind') || 'other', ...(val(r, 'basis') ? { basis: val(r, 'basis') } : {}),
          ...(n(val(r, 'vat_pct')) != null ? { vat_pct: n(val(r, 'vat_pct')) } : {}) })),
        co2: f.co2_kg ? { emissions_kg: n(f.co2_kg.value), cost_eur: n(f.co2_eur.value) } : null,
        // v3.11.0 (Paket rechnung) — geschätzte Ränder (ohne Haken: Feld leer) und Gebührenbescheid (nur Wasser)
        reading_estimated: f.est_start.checked || f.est_end.checked || n(f.est_value.value) != null
          ? { start: f.est_start.checked, end: f.est_end.checked || n(f.est_value.value) != null } : null,
        // v3.11.0 (Skeptiker M5) — geschätzter Endstand an der Rechnung (ohne Tag: Ende des Zeitraums)
        estimated_end_reading: n(f.est_value.value) != null ? { value: n(f.est_value.value), on: f.est_on.value || null } : null,
        ...(f.notice_public ? { notice: f.notice_public.checked ? { public_law: true, dated_on: f.notice_dated.value || null,
          served_on: f.notice_served.value || null, remedy: f.notice_remedy.value || null, file_number: f.notice_file.value.trim() || null } : null } : {}),
        attachment_ids: attachments,
      };
    } catch (err) { toastErr(err.message); return; }
    try {
      const b = editing ? await api.updateBill(u.key, editing.id, data) : await api.createBill(u.key, data);
      toastOk(t(editing ? 'billCheck.updated' : 'billCheck.saved'));
      // v3.11.0 (Skeptiker M5) — der geschätzte Endstand passt nicht zu deinen Ablesungen (Zähler liefe rückwärts)
      if ((b.warnings || []).includes('estimated_reading_backwards') && b.estimated_reading_neighbor) {
        // v3.11.0 (Nachprüfung, klein) — Stand mit Tausenderpunkt wie überall („1.593 m³“), nicht wie im Eingabefeld
        toastErr(t('billCheck.estimatedBackwards', { date: fmt.date(b.estimated_reading_neighbor.date),
          counter: fmt.dec(b.estimated_reading_neighbor.counter, 3), unit: u.unit || '' }));
      }
      await drawBills(container, u, meter, readPeriod);
      showCompare(container, u, b.id);
    } catch (err) { toastErr(err.message); }
  });
  // v3.2.0 (Patch-Pool) — Rechnung bearbeiten: Formular mit ihren Werten füllen
  const startEdit = (b) => {
    const f = el.querySelector('#bi-form');
    const v = (x) => formatForInput(x);
    editing = b;
    // v3.4.0 (N1021) — ein Zeitraum für Prüfen und Erfassen: oben setzen und nachrechnen
    const fromEl = container.querySelector('#bc-from'), untilEl = container.querySelector('#bc-until');
    if (fromEl && untilEl && b.period_from && b.period_to) {
      fromEl.value = b.period_from;
      untilEl.value = b.period_to;
      container.querySelector('#bc-run')?.click();
      showBillPeriod(container);
    }
    f.issued_on.value = b.issued_on || '';
    f.received_on.value = b.received_on || '';   // v3.11.0 (Paket rechnung)
    f.invoice_number.value = b.invoice_number || '';   // v3.11.0 (integration)
    f.est_start.checked = !!b.reading_estimated?.start;
    f.est_end.checked = !!b.reading_estimated?.end;
    f.est_value.value = v(b.estimated_end_reading?.value);   // v3.11.0 (Skeptiker M5)
    f.est_on.value = b.estimated_end_reading?.on || '';
    if (f.notice_public) {
      f.notice_public.checked = !!b.notice?.public_law;
      f.notice_dated.value = b.notice?.dated_on || '';
      f.notice_served.value = b.notice?.served_on || '';
      f.notice_remedy.value = b.notice?.remedy || '';
      f.notice_file.value = b.notice?.file_number || '';
    }
    f.qty.value = v(water ? b.invoice?.volume_m3 : b.invoice?.energy_kwh);
    f.amount.value = v(b.invoice?.amount_eur);
    f.advances.value = v(b.invoice?.advances_paid_eur);
    f.result.value = v(b.invoice?.result_eur);
    if (f.co2_kg) { f.co2_kg.value = v(b.co2?.emissions_kg); f.co2_eur.value = v(b.co2?.cost_eur); }
    el.querySelector('[data-items]').innerHTML = (b.items || []).map(itemRow).join('');
    attachments = [...(b.attachment_ids || [])];
    el.querySelector('[data-role="bill-pdf-n"]').textContent = attachments.length ? tp('tenancy.statement.attached', attachments.length) : '';
    const note = el.querySelector('[data-role="bill-editing"]');
    note.textContent = t('billCheck.editing', { from: fmt.date(b.period_from), to: fmt.date(b.period_to) })
      + (b.special_payment_id ? ' ' + t('billCheck.editBookedNote') : '');
    note.hidden = false;
    el.querySelector('#bi-save').textContent = t('billCheck.invoice.update');
    el.querySelector('#bi-cancel').hidden = false;
    f.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
  };
  el.querySelector('#bi-cancel').addEventListener('click', () => drawBills(container, u, meter, readPeriod));
  el.querySelector('[data-role="bill-list"]').addEventListener('click', async (e) => {
    const chk = e.target.closest('[data-bill-check]');
    const book = e.target.closest('[data-bill-book]');
    const del = e.target.closest('[data-bill-del]');
    const edit = e.target.closest('[data-bill-edit]');
    if (edit) { const b = bills.find(x => x.id === edit.dataset.billEdit); if (b) startEdit(b); }
    if (chk) showCompare(container, u, chk.dataset.billCheck);
    if (book) {
      try { await api.bookBill(u.key, book.dataset.billBook); toastOk(t('billCheck.booked')); drawBills(container, u, meter, readPeriod); }
      catch (err) { toastErr(err.message); }
    }
    if (del) {
      const ok = await confirmModal({ message: t('billCheck.confirmDelete'), confirmLabel: t('utility.readingsTable.delete'), danger: true });
      if (!ok) return;
      try { await api.deleteBill(u.key, del.dataset.billDel); drawBills(container, u, meter, readPeriod); }
      catch (err) { toastErr(err.message); }
    }
  });
  showBillPeriod(container);
}

/** v3.4.0 (N1021) — die Erfassung übernimmt den Zeitraum oben; der Satz nennt ihn. */
function showBillPeriod(container) {
  const el = container.querySelector('[data-role="bill-period"]');
  const from = container.querySelector('#bc-from')?.value;
  const until = container.querySelector('#bc-until')?.value;
  if (el) el.textContent = t('billCheck.invoice.period', { from: fmt.date(from), to: fmt.date(until) });
}

// v3.7.0 — die Belege je Rechnung über lib/attachments.js: 📄 mit Dateinamen im
// aria-label. Bis dahin hieß der Link, der den Beleg öffnet, „Rechnung anhängen“.
function billList(bills, water, docNames = new Map(), rights = []) {
  // v3.11.0 (Skeptiker M7) — ohne Rechnung sagt eine leere Liste nicht „alles in Ordnung“; der Satz zu den
  // Rechte-Prüfungen steht seit der Nachprüfung (NP6) über dem Abschnitt (drawBills), sichtbar auch zugeklappt
  if (!bills.length) return `<p class="muted">${t('billCheck.noBills')}</p>`;
  // v3.11.0 (Paket rechnung) — Hinweise zu deinen Rechten je Rechnung; „Vergleichen“ zeigt sie
  const nRights = (b) => rights.filter(f => f.bill_id === b.id).length;
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${t('utility.billCheck.col.period')}</th>
      <th scope="col" class="num">${water ? 'm³' : 'kWh'}</th>
      <th scope="col" class="num">${t('billCheck.invoice.amount')}</th>
      <th scope="col" class="num">${t('billCheck.invoice.result')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>${bills.map(b => `<tr>
      <td>${fmt.date(b.period_from)} – ${fmt.date(b.period_to)}${nRights(b) ? ` <span class="tag tag--warning" data-role="bill-rights-count">⚖️ ${escapeHtml(tp('billCheck.rightsCount', nRights(b)))}</span>` : ''}</td>
      <td class="num">${fmt.num(water ? b.invoice?.volume_m3 : b.invoice?.energy_kwh, water ? 2 : 0)}</td>
      <td class="num">${eur(b.invoice?.amount_eur)}</td>
      <td class="num">${eur(b.invoice?.result_eur)}</td>
      <td style="text-align:right;white-space:nowrap">
        ${attachmentLinksHtml(b, docNames, { icons: true })}
        <button type="button" class="btn btn--ghost btn--sm" data-bill-check="${escapeHtml(b.id)}">${t('billCheck.compare')}</button>
        ${b.special_payment_id ? `<span class="tag">${t('billCheck.bookedTag')}</span>`
          : (b.invoice?.result_eur ? `<button type="button" class="btn btn--ghost btn--sm" data-bill-book="${escapeHtml(b.id)}">${t('billCheck.book')}</button>` : '')}
        <button type="button" class="icon-btn" data-bill-edit="${escapeHtml(b.id)}" aria-label="${escapeHtml(t('utility.readingsTable.edit'))}"><span aria-hidden="true">✏️</span></button>
        <button type="button" class="icon-btn" data-bill-del="${escapeHtml(b.id)}" aria-label="${escapeHtml(t('utility.readingsTable.delete'))}"><span aria-hidden="true">🗑️</span></button>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

async function showCompare(container, u, id) {
  const el = container.querySelector('[data-role="bill-compare"]');
  if (!el) return;
  el.innerHTML = `<div class="loading">${t('common.loading')}</div>`;
  try {
    const [c, rights] = await Promise.all([api.billCompare(u.key, id), rightsOf(u.key)]);
    const mine = rights.filter(f => f.bill_id === id);   // v3.11.0 (Paket rechnung)
    const water = u.key === 'wasser';
    const q = water ? 'm3' : 'kwh';
    const unit = water ? 'm³' : 'kWh';
    const badge = c.verdict === 'ok' ? 'success' : c.verdict === 'check' ? 'warning' : c.verdict === 'no_prices' ? 'info' : 'neutral';
    el.innerHTML = `
      <h3 class="settings-subhead">${escapeHtml(t('billCheck.delta.title', { from: fmt.date(c.period_from), to: fmt.date(c.period_to) }))}
        ${c.verdict ? `<span class="badge badge--${badge}">${escapeHtml(t('billCheck.delta.' + c.verdict))}</span>` : ''}</h3>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th scope="col"></th><th scope="col" class="num">${t('billCheck.delta.ours')}</th><th scope="col" class="num">${t('billCheck.delta.invoice')}</th><th scope="col" class="num">${t('billCheck.delta.diff')}</th></tr></thead>
        <tbody>
          <tr><th scope="row">${unit}</th><td class="num">${fmt.num(c.ours[q], water ? 2 : 0)}</td><td class="num">${fmt.num(water ? c.invoice.volume_m3 : c.invoice.energy_kwh, water ? 2 : 0)}</td>
            <td class="num">${c.delta[q] != null ? `${fmt.num(c.delta[q], 1)} (${fmt.num(c.delta[q + '_pct'], 1)} %)` : '–'}</td></tr>
          <tr><th scope="row">${t('billCheck.delta.amount')}</th><td class="num">${eur(c.ours.total)}</td><td class="num">${eur(c.invoice.amount_eur)}</td>
            <td class="num">${c.delta.eur != null ? `${eur(c.delta.eur)} (${fmt.num(c.delta.eur_pct, 1)} %)` : '–'}</td></tr>
          <tr><th scope="row">${t('billCheck.invoice.advances')}</th><td class="num">${eur(c.ours.advances)}</td><td class="num">${eur(c.invoice.advances_paid_eur)}</td><td></td></tr>
        </tbody>
      </table></div>
      ${(c.reasons || []).length ? `<ul class="muted small">${c.reasons.map(r => `<li>${escapeHtml(t('billCheck.reason.' + r))}</li>`).join('')}</ul>` : ''}
      ${compareNotesHtml(c, u)}
      ${billRightsHtml(mine)}`;
    bindLetterButtons(el, (fid) => mine.find(f => f.id === fid) || null);
    // v3.10.0 — die Prüfung hat eine Abweichung gefunden: ein Nutzen-Moment. Skeptiker N11: mit
    // Abstand am Ende des Ergebnisses (nicht direkt unter der Abweichung) und nicht, wenn sie aus
    // eigenen Lücken stammen kann
    if (compareThanks(c)) offerThanks(el, 'bill_check', { where: 'after', apart: true });
  } catch (e) { el.innerHTML = ''; toastErr(e.message); }
}

/**
 * v3.7.0 (treffsicher) — Was „Rechnung prüfen“ zum Urteil sagt: ohne Preise
 * die Abschnitte samt Vertrag (Sprung zu den Verträgen), bei Gas abweichender
 * Brennwert mit beiden Werten (Sprung zur Einstellung).
 */
export function compareNotesHtml(c, u = { key: 'gas' }) {
  const parts = [];
  // v3.7.0 (treffsicher, Nachbesserung) — auch bei „prüfen“ (die Menge weicht ab): fehlen Preise, steht es da
  if (c.verdict === 'no_prices' || (c.price_missing_at || []).length) {
    const at = (c.price_missing_at || []).map(x => `<li>${escapeHtml(x.contract_id
      ? t('billCheck.noPrices.at', { from: fmt.date(x.from), to: fmt.date(x.to), provider: x.provider || '–' })
      : t('billCheck.noPrices.noContract', { from: fmt.date(x.from), to: fmt.date(x.to) }))}</li>`).join('');
    parts.push(`<div class="banner banner--info" data-role="no-prices"><p>${escapeHtml(t('billCheck.noPrices.hint'))}</p>
      ${at ? `<ul class="small">${at}</ul>` : ''}
      <p><a href="#/utility/${escapeHtml(encodeURIComponent(u.key))}/contracts">${escapeHtml(t('billCheck.noPrices.link'))}</a></p></div>`);
  }
  const fm = c.factor_mismatches || [];
  if (fm.length) {
    parts.push(`<div class="banner banner--warning" data-role="factor-mismatch"><ul class="small">${fm.map(x => `<li>${escapeHtml(t('billCheck.factorMismatch.line', {
      from: fmt.date(x.from), invoice: fmt.num(x.invoice?.kwh_per_m3, 3), z: fmt.num(x.invoice?.zustandszahl, 4),
      hs: fmt.num(x.invoice?.brennwert, 3), list: fmt.num(x.list?.kwh_per_m3, 3) }))}</li>`).join('')}</ul>
      <p><a href="#/settings/utilities">${escapeHtml(t('contracts.gasFactor.link'))}</a></p></div>`);
  }
  return parts.join('');
}
