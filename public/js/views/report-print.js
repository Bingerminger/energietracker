// =====================================================================
// Energietracker v3.1.0 — Jahresbericht als Druckansicht (Review I18N-12)
//
// Das PDF setzt nur CP1252: „CO₂" wurde dort zu „CO", griechische oder
// kyrillische Texte verschwanden, Zahlen formatierte der Server. Die
// Druckansicht zeigt denselben Bericht im Browser — Zahlen, Daten und Beträge
// über Intl wie in der übrigen Oberfläche, jede Schrift, Diagramme als SVG
// (druckt scharf). „Drucken" bringt ihn auf Papier oder als PDF auf die Platte;
// print.css blendet dabei Seitenleiste und Knöpfe aus.
//
// Daten: GET /api/reports/yearly?year=JJJJ (dieselbe Auswahl wie das PDF).
// =====================================================================

import { api } from '../api.js';
import { fmt, escapeHtml, monthShortNames } from '../lib/format.js';
import { t } from '../lib/i18n.js';
import { renderError } from '../components/error.js';

const esc = escapeHtml;

export async function render(container, params, ctx) {
  const now = new Date().getFullYear();
  const asked = Number(ctx?.query?.get('year'));
  const year = asked >= 2000 && asked <= 2100 ? asked : now - 1;

  const toolbar = `
    <div class="view-header no-print">
      <div>
        <a class="muted" href="#/report">${esc(t('report.print.back'))}</a>
        <h1 class="view-header__title">${esc(t('report.title', { year }))}</h1>
        <p class="view-header__subtitle">${esc(t('report.print.hint'))}</p>
      </div>
      <div class="report-print__actions">
        <select class="select" id="print-year" aria-label="${esc(t('settings.pdf.year'))}">
          ${Array.from({ length: 7 }, (_, i) => now - i).map(y => `<option value="${y}"${y === year ? ' selected' : ''}>${y}</option>`).join('')}
        </select>
        <button type="button" class="btn btn--primary" id="print-now">${esc(t('report.print.button'))}</button>
      </div>
    </div>`;
  container.innerHTML = `${toolbar}<div class="loading" role="status">${esc(t('common.loading'))}</div>`;

  let d;
  try {
    d = await api.yearlyReport(year);
  } catch (e) {
    renderError(container.querySelector('.loading'), e, () => render(container, params, ctx));
    return;
  }

  container.innerHTML = `${toolbar}
    <article class="report-print">
      ${cover(d)}
      ${overview(d)}
      ${(d.meters || []).map(m => meterSection(m, d.year)).join('')}
      ${recommendations(d)}
    </article>`;

  container.querySelector('#print-now')?.addEventListener('click', () => window.print());
  container.querySelector('#print-year')?.addEventListener('change', (e) => {
    location.hash = `#/report/print?year=${e.target.value}`;
  });
}

function cover(d) {
  return `
    <section class="print-section report-print__cover">
      <div class="report-print__brand">ENERGIETRACKER</div>
      <h2 class="report-print__title">${esc(t('report.title', { year: d.year }))}</h2>
      ${d.location_name ? `<p class="report-print__location">${esc(d.location_name)}</p>` : ''}
      <p class="muted">${esc(t('report.createdOn', { date: fmt.date(d.created_on) }))}</p>
      <p class="muted small">${esc(`Energietracker v${d.version || ''}`)}</p>
    </section>`;
}

const perM2 = (v) => `${fmt.num(v, 0)} kWh/m²·a`;

function efficiency(eff) {
  const sources = eff?.per_source || [];
  if (!sources.length) return `<p class="muted">${esc(eff?.note || t('report.noEfficiencyData'))}</p>`;
  const hasScale = eff.scale != null;
  let html;
  if (sources.length === 1) {
    const s = sources[0];
    html = `
      <div class="report-print__tile">
        <div class="muted">${esc(t(hasScale ? 'report.efficiencyClass' : 'report.heatDemand', { label: s.label }))}</div>
        <div class="report-print__big">${esc(hasScale ? (s.class ?? '–') : perM2(s.kwh_per_m2))}</div>
        ${hasScale ? `<div><strong>${esc(perM2(s.kwh_per_m2))}</strong></div>` : ''}
        <div class="muted small">${esc(t('report.livingArea', { area: fmt.num(eff.wohnflaeche_m2, 0) }))}</div>
      </div>`;
  } else {
    html = `
      <h3>${esc(t('report.efficiencyPerSource'))}</h3>
      <table class="table report-print__table"><tbody>
        ${sources.map(s => `<tr><td>${esc(s.label)}</td>${hasScale ? `<td>${esc(t('report.class', { class: s.class ?? '–' }))}</td>` : ''}<td class="num">${esc(perM2(s.kwh_per_m2))}</td></tr>`).join('')}
      </tbody></table>`;
  }
  const c = eff.certificate;
  if (c) {
    html += `<p class="muted small">${esc(t('report.certificate', { value: fmt.num(c.kwh_per_m2, 0), area: fmt.num(c.area_m2, 0) }))}${hasScale && c.class ? ` · ${esc(t('report.class', { class: c.class }))}` : ''}</p>`;
  }
  if (sources.some(s => s.complete === false) && eff.note) html += `<p class="muted small">${esc(eff.note)}</p>`;
  if (!hasScale && eff.scale_note) html += `<p class="muted small">${esc(eff.scale_note)}</p>`;
  return html;
}

function overview(d) {
  const rows = (d.utilities || []).map(u => {
    const value = fmt.unit(u.consumption, u.unit, u.unit === 'kWh' ? 0 : 1);
    const avoided = t('report.co2Avoided', { kg: fmt.num(u.co2_kg, 0) });
    const cost = u.accounting_kind === 'feed_in' ? t('report.revenueValue', { amount: fmt.money(u.cost) })
      : u.accounting_kind === 'generation' ? '–' : fmt.money(u.cost);
    const co2 = u.accounting_kind === 'generation' ? avoided
      : u.accounting_kind === 'feed_in' ? (d.has_generation ? t('report.co2InGeneration') : avoided)
      : fmt.unit(u.co2_kg, 'kg', 0);
    return `<tr><td>${esc(u.label)}</td><td class="num">${esc(value)}</td><td class="num">${esc(cost)}</td><td class="num">${esc(co2)}</td></tr>`;
  }).join('');
  return `
    <section class="print-section">
      <h2>${esc(t('report.overview', { year: d.year }))}</h2>
      ${efficiency(d.efficiency)}
      <h3>${esc(t('report.consumptionCosts'))}</h3>
      <table class="table report-print__table">
        <thead><tr><th>${esc(t('report.colUtility'))}</th><th class="num">${esc(t('report.colConsumption'))}</th><th class="num">${esc(t('report.colCost'))}</th><th class="num">${esc(t('report.colCo2'))}</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </section>`;
}

/** Monatsbalken als SVG — druckt scharf, anders als ein Canvas. */
function bars(m) {
  const names = monthShortNames();
  const byMonth = new Map(m.months.map(x => [Number(x.ym.slice(5, 7)) - 1, x.value]));
  const max = Math.max(1, ...byMonth.values());
  const w = 24, gap = 6, h = 90;
  const cols = names.map((n, i) => {
    const v = byMonth.get(i) ?? 0;
    const bh = Math.round((v / max) * h);
    const x = i * (w + gap);
    return `<rect x="${x}" y="${h - bh}" width="${w}" height="${bh}" rx="2"><title>${esc(n)}: ${esc(fmt.unit(v, m.unit, m.unit === 'kWh' ? 0 : 1))}</title></rect>`
      + `<text x="${x + w / 2}" y="${h + 14}" text-anchor="middle">${esc(n)}</text>`;
  }).join('');
  return `<svg class="report-print__bars" viewBox="0 0 ${12 * (w + gap)} ${h + 18}" role="img"
      aria-label="${esc(`${m.label} · ${m.meter_name}`)}"
      style="fill: var(--util-${esc(m.utility)}, var(--accent))">${cols}</svg>`;
}

function meterSection(m, year) {
  const dec = m.unit === 'kWh' ? 0 : 1;
  const k = m.kpis || {};
  const kpis = [
    [t('report.kpiAnnual'), fmt.unit(k.sum, m.unit, dec)],
    [t('report.kpiAvgMonth'), fmt.unit(k.avg_month, m.unit, dec)],
  ];
  if (m.accounting_kind === 'feed_in') kpis.push([t('report.kpiRevenue'), fmt.money(k.cost)]);
  else if (m.accounting_kind !== 'generation') kpis.push([t('report.kpiTotalCost'), fmt.money(k.cost)]);
  const peak = (p) => (p ? `${fmt.month(p.ym)} · ${fmt.num(p.value, dec)}` : '–');
  kpis.push([t('report.kpiPeakMonth'), peak(k.peak)], [t('report.kpiLowMonth'), peak(k.low)]);

  const wa = m.has_weather_adjusted;
  const head = [t('report.tableMonth'), m.unit,
    m.accounting_kind === 'feed_in' ? t('report.tableRevenue') : t('report.tableCost'),
    t('report.tableTemp'), t('report.tableHdd'), ...(wa ? [t('report.tableWeatherAdj'), t('report.tableDelta')] : [])];
  const rows = m.months.map(x => {
    const cells = [fmt.month(x.ym), fmt.num(x.value, dec),
      m.accounting_kind === 'generation' ? '–' : fmt.num(x.cost, 2),
      x.avg_temp != null ? fmt.num(x.avg_temp, 1) : '–', x.hdd != null ? fmt.num(x.hdd, 0) : '–'];
    if (wa) {
      cells.push(x.heat_adjusted != null ? fmt.num(x.heat_adjusted, 0) : '–');
      cells.push(x.weather_delta_pct != null ? `${x.weather_delta_pct > 0 ? '+' : ''}${fmt.pct(x.weather_delta_pct / 100, 0)}` : '–');
    }
    return `<tr>${cells.map((c, i) => `<td${i ? ' class="num"' : ''}>${esc(c)}</td>`).join('')}</tr>`;
  }).join('');

  return `
    <section class="print-section">
      <h2>${esc(t('report.meterPageTitle', { label: m.label, year, meter: m.meter_name }))}</h2>
      <div class="report-print__kpis">
        ${kpis.map(([l, v]) => `<div class="report-print__kpi"><div class="muted small">${esc(l)}</div><strong>${esc(v)}</strong></div>`).join('')}
      </div>
      ${bars(m)}
      <table class="table report-print__table">
        <thead><tr>${head.map((h, i) => `<th${i ? ' class="num"' : ''}>${esc(h)}</th>`).join('')}</tr></thead>
        <tbody>${rows}</tbody>
      </table>
      ${wa ? `<p class="muted small">${esc(t('report.weatherNote'))}</p>` : ''}
      ${m.has_temperature ? `<p class="muted small">${esc(t('report.weatherSource'))}</p>` : ''}
    </section>`;
}

function recommendations(d) {
  const recs = d.recommendations || [];
  return `
    <section class="print-section">
      <h2>${esc(t('report.recommendations'))}</h2>
      ${recs.length ? recs.map(r => `
        <div class="report-print__rec report-print__rec--${esc(r.severity)}">
          <div><span class="report-print__sev">${esc(t(`recommendations.sev.${r.severity}`))}</span> <strong>${esc(r.title)}</strong></div>
          <p class="muted">${esc(r.detail)}</p>
        </div>`).join('') : `<p class="muted">${esc(t('report.noRecommendations'))}</p>`}
    </section>`;
}
