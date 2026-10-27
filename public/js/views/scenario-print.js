// =====================================================================
// v3.6.0 (F1031) — Ein Szenario der Prognose-Werkstatt als Druckansicht.
//
// Im Stil des Jahresberichts (report-print.js, print.css): Annahmen,
// Ausgangsfall gegen Szenario, Kurven als SVG (drucken scharf), Kennzahlen,
// Rangliste und — falls gerechnet — die beste Kombination unter dem Budget.
// „Drucken“ bringt es auf Papier oder als PDF auf die Platte (Browser).
// Das Szenario kommt aus der Werkstatt (diese Sitzung) oder per `?s=<Name>`
// aus den gespeicherten; gerechnet wird wie dort auf dem Server.
// =====================================================================

import { api } from '../api.js';
import { getUtility } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { t } from '../lib/i18n.js';
import { advanceAmountText } from '../lib/advance-rhythm.js';   // v3.9.0 (Skeptiker H1)
import { renderError } from '../components/error.js';
import { balanceView } from '../lib/semantics.js';
import * as S from '../lib/scenario.js';
import { resultCardHtml } from '../components/result-card.js';

const esc = escapeHtml;
const signed = (v, f) => (v > 0 ? '+' : v < 0 ? '−' : '') + f(Math.abs(v));
const pctSigned = (v, d = 1) => signed(v, x => fmt.pct(x / 100, d));

/** Linien als SVG: Ausgangsfall gestrichelt, Szenario durchgezogen. */
export function lineSvg(labels, base, scen, { caption = '', unit = '' } = {}) {
  const W = 560, H = 200, L = 52, R = 8, T = 10, B = 26;
  const all = [...base, ...scen].filter(v => v != null && Number.isFinite(v));
  if (!all.length) return '';
  const max = Math.max(...all) * 1.05 || 1;
  const x = (i) => L + (labels.length > 1 ? i * (W - L - R) / (labels.length - 1) : 0);
  const y = (v) => T + (H - T - B) * (1 - v / max);
  const pts = (arr) => arr.map((v, i) => `${x(i).toFixed(1)},${y(v ?? 0).toFixed(1)}`).join(' ');
  const ticks = [0, max / 2, max].map(v => `<text x="${L - 6}" y="${y(v) + 3}" text-anchor="end">${esc(fmt.int(v))}</text>
      <line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" class="sp-grid"/>`).join('');
  const xl = labels.map((l, i) => (i % 2 === 0 || labels.length <= 6)
    ? `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">${esc(l)}</text>` : '').join('');
  return `<svg class="report-print__bars scenario-print__svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(caption)}">
      ${ticks}${xl}
      <text x="4" y="${T + 4}" class="sp-unit">${esc(unit)}</text>
      <polyline points="${pts(base)}" class="sp-base"/>
      <polyline points="${pts(scen)}" class="sp-scen"/>
    </svg>`;
}

/** Die Annahmen eines Szenarios als Liste lesbarer Sätze. */
export function assumptions(st, result, contractLabel = (id) => id) {
  const out = [];
  const b = S.scenarioBody(st);
  if (b.winter_pct) out.push(t(b.winter_pct > 0 ? 'scenario.winter.colder' : 'scenario.winter.milder', { pct: fmt.pct(Math.abs(b.winter_pct) / 100, 0) }));
  if (b.room_temp_delta) out.push(`${t('scenario.room.label')}: ${b.room_temp_delta > 0 ? '+' : '−'}${fmt.dec(Math.abs(b.room_temp_delta), 1)} °C`);
  if (b.saving_pct) out.push(t('scenario.saving.value', { pct: fmt.pct(b.saving_pct / 100, 0) }));
  if (b.baseload_pct) out.push(`${t('scenario.baseload.label')}: ${pctSigned(b.baseload_pct, 0)}`);
  for (const m of b.measures) {
    const name = m.label || t(`scenario.measure.kind.${m.kind || 'other'}`);
    out.push(t('scenario.print.measure', { name, heat: fmt.int(m.heat_pct), base: fmt.int(m.base_pct),
      cost: m.cost_eur != null ? fmt.eur(m.cost_eur) : '–' }) + (m.active ? '' : ` (${t('scenario.print.inactive')})`));
  }
  if (b.tariff.kind === 'cheapest' || b.tariff.kind === 'contract') {
    out.push(t('scenario.tariff.offer', { label: contractLabel(result?.tariff?.contract_id || b.tariff.contract_id) }));
  } else if (b.tariff.kind === 'custom') {
    out.push(t('scenario.print.customPrice', { wp: fmt.num(b.tariff.working_price_ct, 2), bp: fmt.eur(b.tariff.base_eur_month), unit: result?.unit || '' }));
  }
  if (b.ev_km) out.push(t('scenario.print.ev', { km: fmt.int(b.ev_km), kwh: fmt.num(b.ev_kwh_per_100km, 1) }));
  if (b.jaz_from != null) out.push(t('scenario.print.jaz', { from: fmt.num(b.jaz_from, 1), to: fmt.num(b.jaz_to, 1) }));
  out.push(`${t('scenario.model.label')}: ${t(`scenario.model.${result?.model || b.model}`)}`);
  if (b.budget_eur != null) out.push(`${t('scenario.budget.label')}: ${fmt.eur(b.budget_eur)}`);
  return out;
}

export async function render(container, params, ctx) {
  const wanted = ctx?.query?.get?.('s');
  const saved = wanted ? S.savedScenarios().find(x => x.name === wanted) : null;
  const cur = saved ? { name: saved.name, state: saved.state } : S.currentScenario();
  const toolbar = `
    <div class="view-header no-print">
      <div>
        <a class="muted" href="#/forecast">${esc(t('scenario.print.back'))}</a>
        <h1 class="view-header__title">${esc(t('scenario.print.title'))}</h1>
        <p class="view-header__subtitle">${esc(t('scenario.print.hint'))}</p>
      </div>
      <div class="report-print__actions">
        <button type="button" class="btn btn--primary" id="print-now">${esc(t('report.print.button'))}</button>
      </div>
    </div>`;
  if (!cur?.state?.utility || !cur.state.meter_id) {
    container.innerHTML = `${toolbar}<div class="banner banner--info">${esc(t('scenario.print.none'))}</div>`;
    return;
  }
  container.innerHTML = `${toolbar}<div class="loading" role="status">${esc(t('common.loading'))}</div>`;
  let r, u, meters = [], contracts = [];
  try {
    [r, u, meters, contracts] = await Promise.all([
      api.scenario(S.scenarioBody(cur.state)),
      getUtility(cur.state.utility),
      api.meters(cur.state.utility).catch(() => []),
      api.contracts(cur.state.utility, cur.state.meter_id).catch(() => []),
    ]);
  } catch (e) {
    container.innerHTML = toolbar;
    renderError(container, e);
    return;
  }
  container.querySelector('#print-now')?.addEventListener('click', () => window.print());
  if (!r.valid) {
    container.innerHTML = `${toolbar}<div class="banner banner--info">${esc(t(r.supported ? 'scenario.tooFew' : 'scenario.unsupported', { label: u?.label || '' }))}</div>`;
    container.querySelector('#print-now')?.addEventListener('click', () => window.print());
    return;
  }
  container.innerHTML = `${toolbar}
    <article class="report-print scenario-print">${scenarioHtml({ cur, r, u, meters, contracts })}</article>`;
  container.querySelector('#print-now')?.addEventListener('click', () => window.print());
}

/**
 * v3.6.0 — Die Abschnitte eines gerechneten Szenarios (Deckblatt mit Karte,
 * Annahmen und Vergleich, Kurven, Rangliste). Die eigene Druckansicht und der
 * Jahresbericht („Szenario anhängen“) setzen sie in ihren Artikel.
 *
 * @param {{cur: {name?: string, state: object}, r: object, u: object|null, meters: Array, contracts: Array, cardId?: string}} p
 */
export function scenarioHtml({ cur, r, u, meters = [], contracts = [], cardId = 'ws-card-sentence' }) {
  const contractLabel = (id) => {
    const c = contracts.find(x => String(x.id) === String(id));
    return c ? (c.shadow_label || [c.provider, c.tariff_name].filter(Boolean).join(' · ') || id) : id;
  };
  const meter = meters.find(m => m.id === cur.state.meter_id);
  const name = cur.name || t('scenario.card.yours');
  const d = r.totals.delta;
  const tone = Math.abs(d.cost) < 1 ? 'same' : d.cost > 0 ? 'more' : 'less';
  const amount = fmt.eur0(Math.abs(d.cost));
  const card = resultCardHtml({
    brand: t('scenario.card.brand'), title: name, tone,
    big: tone === 'same' ? '±0' : `${d.cost > 0 ? '+' : '−'}${amount}`,
    sentence: tone === 'same' ? t('scenario.card.same', { name }) : t(tone === 'more' ? 'scenario.card.more' : 'scenario.card.less', { name, amount }),
    facts: [], footer: t('scenario.card.footer', { from: fmt.month(r.from), to: fmt.month(r.to), date: fmt.date(new Date().toISOString().slice(0, 10)) }),
  }, cardId);
  const row = (label, base, scen, delta) => `<tr><th scope="row">${esc(label)}</th><td class="num">${esc(base)}</td><td class="num">${esc(scen)}</td><td class="num">${esc(delta)}</td></tr>`;
  const bal = (v) => { const b = balanceView(v, u); return b ? `${b.word} ${fmt.eur(b.amount)}` : '–'; };
  const labels = r.months.map(m => fmt.month(m.ym));
  const ranking = (r.ranking || []).map(x => {
    const label = x.kind === 'measure'
      ? ((cur.state.measures || []).find(m => m.id === x.id)?.label || t(`scenario.measure.kind.${(cur.state.measures || []).find(m => m.id === x.id)?.kind || 'other'}`))
      : x.kind === 'tariff' ? t('scenario.tariff.offer', { label: x.tariff_kind === 'custom' ? t('scenario.tariff.custom') : contractLabel(r.tariff.contract_id) })
      : t(`scenario.lever.${x.kind}`);
    return `<tr><th scope="row">${esc(label)}</th><td class="num">${esc(signed(x.saving_eur, fmt.eur))}</td>
      <td class="num">${x.cost_eur != null ? esc(fmt.eur(x.cost_eur)) : '–'}</td>
      <td class="num">${x.payback_years != null ? esc(t('scenario.ranking.years', { n: fmt.num(x.payback_years, 1) })) : '–'}</td></tr>`;
  }).join('');
  const o = r.optimization;

  return `
      <section class="print-section report-print__cover">
        <p class="report-print__brand">${esc(t('scenario.card.brand'))}</p>
        <h2 class="report-print__title">${esc(name)}</h2>
        <p class="report-print__location">${esc(`${u?.icon || ''} ${u?.label || r.utility}${meter ? ` · ${meter.name}` : ''}`)} · ${esc(fmt.month(r.from))} – ${esc(fmt.month(r.to))}</p>
        ${card}
        <h3>${esc(t('scenario.print.assumptions'))}</h3>
        <ul>${assumptions(cur.state, r, contractLabel).map(a => `<li>${esc(a)}</li>`).join('')}</ul>
        <h3>${esc(t('scenario.print.compare'))}</h3>
        <div class="table-wrap"><table class="table table--compact report-print__table">
          <thead><tr><th scope="col"></th><th scope="col" class="num">${esc(t('scenario.chart.base'))}</th><th scope="col" class="num">${esc(t('scenario.chart.scenario'))}</th><th scope="col" class="num">${esc(t('scenario.compare.delta'))}</th></tr></thead>
          <tbody>
            ${row(t('scenario.kpi.cost'), fmt.eur(r.totals.base.cost), fmt.eur(r.totals.scenario.cost), signed(d.cost, fmt.eur))}
            ${row(t('scenario.kpi.qtyYear'), fmt.unit(r.totals.base.qty, r.unit), fmt.unit(r.totals.scenario.qty, r.unit), d.qty_pct != null ? pctSigned(d.qty_pct) : '–')}
            ${d.co2_kg != null ? row(t('scenario.kpi.co2Year'), `${fmt.int(r.totals.base.co2_kg)} kg`, `${fmt.int(r.totals.scenario.co2_kg)} kg`, `${signed(d.co2_kg, fmt.int)} kg`) : ''}
            ${r.advance.uncertain
              // v3.7.0 (wirklichkeit-2) — Gütesperre: Spannen statt Beträge
              ? row(t('scenario.kpi.advance'), ...['base_range', 'scen_range'].map(k => r.advance.uncertain[k]
                  ? t('scenario.kpi.advanceRange', { low: fmt.eur0(r.advance.uncertain[k][0]), high: fmt.eur0(r.advance.uncertain[k][1]) }) : '–'), t('scenario.kpi.advanceUncertain'))
              // v3.9.0 (L12) — kurz vor der Abrechnung: der laufende Bedarf danach
              : r.advance.withheld === 'short' && r.advance.base_ongoing != null
              ? row(t('scenario.kpi.advance'), advanceAmountText(r.advance.base_ongoing, r.advance.every_months), advanceAmountText(r.advance.scen_ongoing, r.advance.every_months), t('scenario.kpi.advanceShort', { date: fmt.date(r.billing?.settle_on || r.billing?.until) }))
              : row(t('scenario.kpi.advance'), advanceAmountText(r.advance.base_recommended, r.advance.every_months), advanceAmountText(r.advance.scen_recommended, r.advance.every_months), signed(r.advance.scen_recommended - r.advance.base_recommended, fmt.eur))}
            ${r.billing ? row(t('scenario.kpi.balance', { date: fmt.date(r.billing.settle_on || r.billing.until) }), bal(r.billing.base_end_balance), bal(r.billing.scen_end_balance), '') : ''}
          </tbody></table></div>
      </section>
      <section class="print-section">
        <h2>${esc(t('scenario.chart.cumTitle'))}</h2>
        ${lineSvg(labels, r.months.map(m => m.base_cum_cost), r.months.map(m => m.scen_cum_cost), { caption: t('scenario.chart.cumAlt', { base: fmt.eur(r.totals.base.cost), scen: fmt.eur(r.totals.scenario.cost) }), unit: t('scenario.chart.eur') })}
        <p class="ws-note"><span class="rp-key sp-key--base"></span> ${esc(t('scenario.chart.base'))} <span class="rp-key sp-key--scen"></span> ${esc(t('scenario.chart.scenario'))}</p>
        <h2>${esc(t('scenario.chart.monthTitle'))}</h2>
        ${lineSvg(labels, r.months.map(m => m.base_qty), r.months.map(m => m.scen_qty), { caption: t('scenario.chart.monthAlt', { unit: r.unit }), unit: r.unit })}
        <div class="table-wrap"><table class="table table--compact report-print__table">
          <thead><tr><th scope="col">${esc(t('forecast.col.month'))}</th><th scope="col" class="num">${esc(`${t('scenario.chart.base')} (${r.unit})`)}</th>
            <th scope="col" class="num">${esc(`${t('scenario.chart.scenario')} (${r.unit})`)}</th><th scope="col" class="num">${esc(t('scenario.chart.costBase'))}</th>
            <th scope="col" class="num">${esc(t('scenario.chart.costScen'))}</th></tr></thead>
          <tbody>${r.months.map(m => `<tr><th scope="row">${esc(fmt.month(m.ym))}</th><td class="num">${esc(fmt.int(m.base_qty))}</td>
            <td class="num">${esc(fmt.int(m.scen_qty))}</td><td class="num">${esc(fmt.eur(m.base_cost))}</td><td class="num">${esc(fmt.eur(m.scen_cost))}</td></tr>`).join('')}</tbody>
        </table></div>
      </section>
      ${ranking || o ? `<section class="print-section">
        ${ranking ? `<h2>${esc(t('scenario.ranking.title'))}</h2><p>${esc(t('scenario.ranking.hint'))}</p>
          <table class="table table--compact report-print__table"><thead><tr><th scope="col">${esc(t('scenario.ranking.lever'))}</th>
            <th scope="col" class="num">${esc(t('scenario.ranking.saving'))}</th><th scope="col" class="num">${esc(t('scenario.ranking.cost'))}</th>
            <th scope="col" class="num">${esc(t('scenario.ranking.payback'))}</th></tr></thead><tbody>${ranking}</tbody></table>` : ''}
        ${o && o.chosen.length ? `<h2>${esc(t('scenario.opt.title'))}</h2><p>${esc(t('scenario.opt.result', {
          list: o.chosen.map(id => (cur.state.measures || []).find(m => m.id === id)?.label || t(`scenario.measure.kind.${(cur.state.measures || []).find(m => m.id === id)?.kind || 'other'}`)).join(', '),
          budget: fmt.eur(o.budget_eur), cost: fmt.eur(o.cost_eur), saving: fmt.eur(o.saving_eur) }))}</p>` : ''}
      </section>` : ''}
      <p class="ws-note">${esc(t('scenario.honesty.model', { month: r.basis?.last_measured ? fmt.month(r.basis.last_measured) : '–' }))} ${esc(t('scenario.print.footer'))}</p>`;
}
