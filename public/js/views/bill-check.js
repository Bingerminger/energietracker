// =====================================================================
// Energietracker — Rechnung prüfen (F1012, v2.5.0; eigene Seite seit v2.11.0)
//
// Rechnet die Gasrechnung nach: Für den gewählten Zeitraum liefert das
// Backend Abschnitte an jeder Ablesung und jedem Brennwertwechsel — je
// Abschnitt m³ × Zustandszahl × Brennwert = kWh, genau wie die Zeilen einer
// Versorgerrechnung. Damit lässt sich jede Rechnungszeile gegen die eigenen
// Zählerstände prüfen, statt sie zu glauben.
//
// v2.11.0 (Review UI-12) — bis v2.10 stand die Prüfung nur am Ende der
// Gas-Seite; wer sie suchte, fand sie nicht. Jetzt ist sie eine Seite unter
// „Kosten & Verträge" (#/bill-check?meter=…&from=…&to=…), die Gas-Seite
// verweist hierher.
// =====================================================================

import { api } from '../api.js';
import { getUtility } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { toastErr } from '../components/toast.js';
import { t } from '../lib/i18n.js';

const ISO = /^\d{4}-\d{2}-\d{2}$/;

export async function render(container, _params = [], ctx = {}) {
  const query = ctx.query || new URLSearchParams();
  const [u, meters] = await Promise.all([getUtility('gas'), api.meters('gas').catch(() => [])]);
  const header = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('nav.billCheck'))}</h1>
        <p class="view-header__subtitle">${t('utility.billCheck.hint')}</p>
      </div>
    </div>`;
  if (!u || !meters.length) {
    container.innerHTML = `${header}
      <div class="empty">
        <p>${escapeHtml(t('billCheck.noMeter'))}</p>
        <a class="btn btn--primary" href="#/utility/gas/meters">${escapeHtml(t('billCheck.addMeter'))}</a>
      </div>`;
    return;
  }

  const wanted = query.get('meter');
  const meter = meters.find(m => m.id === wanted) || meters[0];
  const year = new Date().getFullYear() - 1;
  const from = ISO.test(query.get('from') || '') ? query.get('from') : `${year}-01-01`;
  const to   = ISO.test(query.get('to') || '')   ? query.get('to')   : `${year + 1}-01-01`;

  container.innerHTML = `${header}
    <div class="card" data-utility="gas">
      ${meters.length > 1 ? `
      <div class="field">
        <label for="bc-meter">${escapeHtml(t('billCheck.meter'))}</label>
        <select class="select" id="bc-meter">
          ${meters.map(m => `<option value="${escapeHtml(m.id)}"${m.id === meter.id ? ' selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
        </select>
      </div>` : ''}
      ${billCheckForm(from, to)}
    </div>`;

  let current = meter;
  container.querySelector('#bc-meter')?.addEventListener('change', (e) => {
    current = meters.find(m => m.id === e.target.value) || current;
    container.querySelector('#bc-result').innerHTML = '';
  });
  wireBillCheck(container, u, () => current);
  // Mit Zeitraum in der Adresse gleich rechnen (Verweis von der Gas-Seite)
  if (query.get('from') && query.get('to')) container.querySelector('#bc-run')?.click();
}

/** Eingaben „von – bis" und Ergebnisbereich. */
export function billCheckForm(from, to) {
  return `
      <div class="form-row" style="align-items:flex-end; margin-bottom:10px">
        <div class="field">
          <label for="bc-from">${t('utility.billCheck.from')}</label>
          <input class="input" id="bc-from" type="date" value="${escapeHtml(from)}">
        </div>
        <div class="field">
          <label for="bc-to">${t('utility.billCheck.to')}</label>
          <input class="input" id="bc-to" type="date" value="${escapeHtml(to)}">
        </div>
        <div class="field">
          <button type="button" class="btn btn--util" id="bc-run">${t('utility.billCheck.run')}</button>
        </div>
      </div>
      <div id="bc-result"></div>`;
}

function reasonLabel(reason) {
  const map = {
    start: 'start', end: 'end', reading: 'reading',
    reading_estimated: 'readingEstimated', factor: 'factor',
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

export function renderBillCheck(bill) {
  const rows = bill.rows || [];
  if (!rows.length) return `<p class="muted">${t('utility.billCheck.empty')}</p>`;
  const tot = bill.totals || {};
  return `
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr>
        <th scope="col">${t('utility.billCheck.col.period')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.counterFrom')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.counterTo')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.days')}</th>
        <th scope="col">${t('utility.billCheck.col.reason')}</th>
        <th scope="col" class="num">m³</th>
        <th scope="col" class="num">${t('utility.billCheck.col.z')}</th>
        <th scope="col" class="num">${t('utility.billCheck.col.hs')}</th>
        <th scope="col" class="num">kWh/m³</th>
        <th scope="col" class="num">kWh</th>
      </tr></thead>
      <tbody>
        ${rows.map(r => `
          <tr class="${r.m3 == null ? 'muted' : ''}">
            <td>${fmt.date(r.from)} – ${fmt.date(r.to_inclusive)}</td>
            ${counterCell(r.counter_from, r.counter_from_kind)}
            ${counterCell(r.counter_to, r.counter_to_kind)}
            <td class="num">${r.days}</td>
            <td>${reasonLabel(r.reason)}</td>
            <td class="num">${r.m3 != null ? fmt.num(r.m3, 1) : `<em>${t('utility.billCheck.noReading')}</em>`}</td>
            <td class="num">${r.zustandszahl != null ? fmt.num(r.zustandszahl, 4) : '–'}</td>
            <td class="num">${r.brennwert != null ? fmt.num(r.brennwert, 3) : '–'}</td>
            <td class="num">${fmt.num(r.kwh_per_m3, 3)}</td>
            <td class="num"><strong>${r.kwh != null ? fmt.num(r.kwh, 0) : '–'}</strong></td>
          </tr>`).join('')}
      </tbody>
      <tfoot><tr>
        <td><strong>${t('utility.billCheck.total')}</strong></td>
        <td></td><td></td>
        <td class="num">${tot.days ?? ''}</td>
        <td></td>
        <td class="num"><strong>${fmt.num(tot.m3, 1)}</strong></td>
        <td></td><td></td><td></td>
        <td class="num"><strong>${fmt.num(tot.kwh, 0)}</strong></td>
      </tr></tfoot>
    </table></div>
    <p class="muted bill-check-legend" style="margin-top:8px">${t('utility.billCheck.legend')}</p>
    ${tot.gaps ? `<p class="muted" style="margin-top:8px">${t('utility.billCheck.gaps', { count: tot.gaps })}</p>` : ''}
    <p class="muted" style="margin-top:8px">${t('utility.billCheck.formula')}</p>`;
}

/**
 * @param {HTMLElement} container
 * @param {{key: string}} u
 * @param {() => {id: string}} getMeter  der gerade gewählte Zähler
 */
export function wireBillCheck(container, u, getMeter) {
  const btn = container.querySelector('#bc-run');
  const out = container.querySelector('#bc-result');
  if (!btn || !out) return;
  let seq = 0;
  btn.addEventListener('click', async () => {
    const from = container.querySelector('#bc-from')?.value;
    const to   = container.querySelector('#bc-to')?.value;
    if (!from || !to || from >= to) { toastErr(t('utility.billCheck.errRange')); return; }
    const my = ++seq;   // nur die letzte Rechnung zeigt ihr Ergebnis
    out.innerHTML = `<div class="loading">${t('common.loading')}</div>`;
    try {
      const bill = await api.billCheck(u.key, getMeter().id, from, to);
      if (my === seq) out.innerHTML = renderBillCheck(bill);
    } catch (e) {
      if (my !== seq) return;
      out.innerHTML = '';
      toastErr(e.message);
    }
  });
}
