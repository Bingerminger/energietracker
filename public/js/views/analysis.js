// =====================================================================
// Energietracker v1.1.0 — Analysis view (per utility):
//   - HGT scatter + regression (only for HGT-relevant utilities)
//   - Year-over-year comparison chart + YoY delta widget (F-04)
//   - Contract-end reminders (F-05)
//   - Water spar-index (F-10)
//   - Anomalies (per meter)
// =====================================================================

import { api } from '../api.js';
import { activeUtilities, getSettings } from '../state.js';
import { fmt, escapeHtml, monthShortNames } from '../lib/format.js';
import { makeChart, themeColors } from '../components/chart.js';
import { toastErr } from '../components/toast.js';
import { t } from '../lib/i18n.js';

// Locale-bewusste Kurz-Monatsnamen für die Chart-Achsen. v2.2.0: aus der
// gemeinsamen Intl-Quelle in lib/format.js statt einer zweiten, nur auf de/en
// gepflegten Tabelle.
const monthNames = () => monthShortNames();

let charts = [];

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('analysis.loading')}</div>`;
  // v2.2.0 — wie Dashboard und Seitenleiste nur die aktiven Verbrauchsarten.
  const utilities = await activeUtilities();
  if (!utilities.length) {
    container.innerHTML = `<div class="banner banner--info">${escapeHtml(t('analysis.noUtilities'))}</div>`;
    return;
  }

  container.innerHTML = `
    <div class="section-head">
      <h1>${t('analysis.title')}</h1>
      <div class="section-actions">
        <select class="select" id="util-select" aria-label="${t('analysis.selectUtility')}">
          ${utilities.map(u => `<option value="${u.key}">${u.icon} ${escapeHtml(u.label)}</option>`).join('')}
        </select>
        <select class="select" id="meter-select" aria-label="${t('analysis.selectMeter')}"></select>
      </div>
    </div>
    <div id="analysis-body"><div class="loading">…</div></div>
  `;

  const utilSel  = container.querySelector('#util-select');
  const meterSel = container.querySelector('#meter-select');
  const body     = container.querySelector('#analysis-body');

  async function reload() {
    destroyCharts();
    const utility = utilities.find(u => u.key === utilSel.value);
    body.innerHTML = `<div class="loading">${t('analysis.loading')}</div>`;
    const meters = await api.meters(utility.key);
    meterSel.innerHTML = meters.map(m => `<option value="${m.id}">${escapeHtml(m.name)}</option>`).join('');
    if (!meters.length) { body.innerHTML = `<p class="muted">${t('analysis.noMeters')}</p>`; return; }
    await renderForMeter(utility, meterSel.value || meters[0].id, body);
  }

  utilSel.addEventListener('change', reload);
  meterSel.addEventListener('change', async () => {
    const utility = utilities.find(u => u.key === utilSel.value);
    destroyCharts();
    body.innerHTML = `<div class="loading">${t('analysis.loading')}</div>`;
    await renderForMeter(utility, meterSel.value, body);
  });

  await reload();

  return destroyCharts;
}

function destroyCharts() {
  charts.forEach(c => { try { c.destroy(); } catch {} });
  charts = [];
}

async function renderForMeter(u, meterId, body) {
  let meterData;
  try { meterData = await api.meterConsumption(u.key, meterId); }
  catch (e) { toastErr(e.message); body.innerHTML = `<div class="banner banner--error">${escapeHtml(e.message)}</div>`; return; }
  // Contract status drives the F-05 contract-end reminder. It is optional —
  // a meter without contracts simply yields no reminder banner.
  let contractStatus = null;
  try { contractStatus = await api.contractStatus(u.key, meterId); }
  catch { contractStatus = null; }

  const monthly     = meterData.monthly || [];
  const anomalies   = meterData.anomalies || [];
  const regressions = meterData.regressions || {};
  const baseline    = meterData.baseline || null;              // F1011
  const comparison  = meterData.baseline_comparison || null;   // F1011
  const consKey     = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';

  body.innerHTML = `
    ${renderContractReminders(contractStatus)}
    ${renderBaselineBlock(baseline, comparison, u)}
    ${u.key === 'wasser' ? await renderWaterSparindex(monthly) : ''}
    <div class="grid grid-2">
      ${u.hgt_relevant ? `
        <div class="card">
          <h3 class="card__title">${t('analysis.hgtTitle')}</h3>
          <div class="chart-wrap"><canvas id="ch-hdd"></canvas></div>
          <div class="regression-summary" id="reg-summary"></div>
        </div>
      ` : `
        <div class="card">
          <h3 class="card__title">${t('analysis.seasonalTitle')}</h3>
          <div class="chart-wrap"><canvas id="ch-seasonal"></canvas></div>
          <p class="muted">${t('analysis.notTempDependent', { label: escapeHtml(u.label) })}</p>
        </div>
      `}

      <div class="card">
        <h3 class="card__title">${t('analysis.yearComparison')}</h3>
        <div class="chart-wrap"><canvas id="ch-years"></canvas></div>
      </div>
    </div>

    ${renderYoyWidget(monthly, u, consKey)}

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">${t('analysis.anomalies', { count: anomalies.length })}</h3>
      ${anomalies.length === 0 ? `<p class="muted">${t('analysis.noAnomalies')}</p>` : `
        <p class="muted" style="margin-bottom:12px">
          ${u.hgt_relevant ? t('analysis.anomalyExplainHgt') : t('analysis.anomalyExplainSeasonal')}
        </p>
        <div class="table-wrap"><table class="table">
          <thead><tr>
            <th scope="col">${t('analysis.anomalyCol.month')}</th>
            <th scope="col" class="num">${t('analysis.anomalyCol.consumption')}</th>
            <th scope="col" class="num">${t('analysis.anomalyCol.expected')}</th>
            <th scope="col" class="num">${t('analysis.anomalyCol.deltaAbs')}</th>
            <th scope="col" class="num">${t('analysis.anomalyCol.deltaPct')}</th>
            <th scope="col" class="num">${t('analysis.anomalyCol.sigma')}</th>
            ${u.hgt_relevant ? `<th scope="col" class="num">${t('analysis.anomalyCol.hgt')}</th><th scope="col" class="num">${t('analysis.anomalyCol.temp')}</th>` : ''}
          </tr></thead>
          <tbody>
            ${anomalies.map(a => {
              const z = a.z_score ?? 0;
              const val = a.value ?? 0;
              const dev = a.deviation ?? 0;
              const pct = a.percent ?? 0;
              const cls = z >= 0 ? 'danger-text' : 'success-text';
              const unit = u.consumption_unit;
              return `
                <tr>
                  <td><strong>${fmt.month(a.ym)}</strong></td>
                  <td class="num">${fmt.num(val, 0)} ${unit}</td>
                  <td class="num">${fmt.num(a.expected, 0)} ${unit}</td>
                  <td class="num ${cls}">${dev >= 0 ? '+' : ''}${fmt.num(dev, 0)}</td>
                  <td class="num ${cls}">${pct >= 0 ? '+' : ''}${fmt.num(pct, 1)} %</td>
                  <td class="num ${cls}" style="font-weight:600">${z >= 0 ? '+' : ''}${fmt.num(z, 2)}</td>
                  ${u.hgt_relevant ? `<td class="num">${a.hdd != null ? fmt.int(a.hdd) : '–'}</td><td class="num">${a.avg_temp != null ? fmt.num(a.avg_temp, 1) + ' °C' : '–'}</td>` : ''}
                </tr>`;
            }).join('')}
          </tbody></table></div>
      `}
    </div>
  `;

  // Render charts
  if (u.hgt_relevant) renderHgtScatter(monthly, u, consKey, regressions);
  else                renderSeasonalProfile(monthly, u, consKey);
  renderYearComparison(monthly, u, consKey);
}

// ── F1011: Zäsur — Hinweisband, Grenzen, Vorher/Nachher ─────────────
//
// Drei Bausteine, alle optional:
//  1. Ist eine Zäsur wirksam, sagt ein Band, ab wann gerechnet wird.
//  2. Reißt eine der drei Untergrenzen, steht im Klartext da, welche und
//     wie viel fehlt — statt dass eine Auswertung wortlos verschwindet.
//     Das gilt auch OHNE Zäsur: Wer erst sieben Monate Daten hat, bekommt
//     dieselbe Erklärung.
//  3. Liegen beide Epochen dick genug vor, steht die Wirkung der Maßnahme
//     als Zahl da — Verbrauch je Gradtag vorher gegen nachher.
function renderBaselineBlock(baseline, comparison, u) {
  if (!baseline) return '';

  const parts = [];

  if (baseline.active_from) {
    const since = baseline.active_label
      ? t('analysis.baseline.activeSince', {
          date: fmt.date(baseline.active_from),
          label: escapeHtml(baseline.active_label),
        })
      : t('analysis.baseline.activeSinceNoLabel', { date: fmt.date(baseline.active_from) });
    const excluded = baseline.months_total - baseline.months_after;
    parts.push(`
      <div class="banner banner--info">
        <strong>${since}</strong>
        ${excluded > 0 ? `<br><span class="muted">${t('analysis.baseline.excluded', { count: excluded })}</span>` : ''}
      </div>`);
  }

  const missing = (baseline.limits || []).filter(l => !l.ok);
  if (missing.length) {
    parts.push(`
      <div class="banner banner--warning">
        <strong>${t('analysis.baseline.limitsTitle')}</strong>
        <ul style="margin:6px 0 0 18px">
          ${missing.map(l => `<li>${t('analysis.baseline.limit.' + l.key, { need: l.need, have: l.have })}</li>`).join('')}
        </ul>
      </div>`);
  }

  if (comparison) {
    const perDay = t('analysis.baseline.perDegreeDay', { unit: escapeHtml(comparison.unit) });
    const better = comparison.delta_pct < 0;
    parts.push(`
      <div class="card" style="margin-top: var(--sp-5)">
        <h3 class="card__title">${t('analysis.baseline.comparisonTitle')}</h3>
        <p class="muted" style="margin-bottom:12px">${t('analysis.baseline.comparisonHint')}</p>
        <div class="kpi-grid">
          <div class="kpi">
            <div class="kpi__label">${t('analysis.baseline.before')}</div>
            <div class="kpi__value">${fmt.num(comparison.before.slope, 3)}</div>
            <div class="kpi__sub">${perDay} · ${t('analysis.baseline.points', { n: comparison.before.points })}</div>
          </div>
          <div class="kpi">
            <div class="kpi__label">${t('analysis.baseline.after')}</div>
            <div class="kpi__value">${fmt.num(comparison.after.slope, 3)}</div>
            <div class="kpi__sub">${perDay} · ${t('analysis.baseline.points', { n: comparison.after.points })}</div>
          </div>
          <div class="kpi">
            <div class="kpi__label">${t('analysis.baseline.effect')}</div>
            <div class="kpi__value ${better ? 'success-text' : 'danger-text'}">
              ${comparison.delta_pct > 0 ? '+' : ''}${fmt.num(comparison.delta_pct, 1)} %
            </div>
            <div class="kpi__sub">${t('analysis.baseline.weatherCorrected')}</div>
          </div>
        </div>
      </div>`);
  }

  return parts.join('\n');
}

// Predict y for each model given x. Mirrors RegressionService::predict on the backend.
function predictFor(model, reg, x) {
  if (!reg?.valid) return null;
  switch (model) {
    case 'linear':
    case 'robust':
      return Math.max(0, (reg.a ?? 0) * x + (reg.b ?? 0));
    case 'polynomial':
      return Math.max(0, (reg.a ?? 0) * x * x + (reg.b ?? 0) * x + (reg.c ?? 0));
    case 'segmented': {
      const split = reg.split ?? 50;
      if (x >= split) return Math.max(0, (reg.heat?.a ?? 0) * x + (reg.heat?.b ?? 0));
      return Math.max(0, (reg.base?.a ?? 0) * x + (reg.base?.b ?? 0));
    }
    case 'sigmoid': {
      // Spiegelt RegressionService::sigmoidPredict
      const A = reg.A ?? 0, B = reg.B ?? 1, C = reg.C ?? 3;
      const t0 = reg.theta0 ?? 0, D = reg.D ?? 0;
      const denom = x - t0;
      if (denom <= 1e-6) return Math.max(0, D);
      return Math.max(0, A / (1 + Math.pow(B / denom, C)) + D);
    }
    default: return null;
  }
}

const MODEL_STYLE = {
  linear:     { color: '#60a5fa', dash: []      },
  polynomial: { color: '#a78bfa', dash: [6, 4]  },
  robust:     { color: '#10b981', dash: [2, 3]  },
  segmented:  { color: '#f59e0b', dash: [10, 4] },
  sigmoid:    { color: '#ec4899', dash: [4, 2]  },
};
const modelLabel = (m) => t('analysis.model.' + m);

// F1011: Neutralton für die Punkte vor einer Zäsur. Bewusst als Literal und
// nicht aus einem --util-*-Token abgeleitet: Die Utility-Token sind im
// Hellmodus `color-mix()`, was auf dem Canvas still zu Schwarz zusammenfällt
// (Fehler aus v2.3.0). Zwei feste Werte, einer je Theme.
function preBaselineColor() {
  return document.documentElement.getAttribute('data-theme') === 'light'
    ? 'rgba(15, 23, 42, 0.22)'
    : 'rgba(255, 255, 255, 0.22)';
}

function renderHgtScatter(monthly, u, consKey, regressions) {
  const canvas = document.getElementById('ch-hdd');
  if (!canvas) return;
  const usable = monthly.filter(m => m.hdd > 0 && m[consKey] > 0);
  // F1011: Die Punkte vor der Zäsur verschwinden nicht — sie werden nur
  // ausgegraut und aus dem Fit genommen. Die Daten bleiben sichtbar.
  const points = usable
    .filter(m => !m.pre_baseline)
    .map(m => ({ x: m.hdd, y: m[consKey], ym: m.ym }));
  const prePoints = usable
    .filter(m => m.pre_baseline)
    .map(m => ({ x: m.hdd, y: m[consKey], ym: m.ym }));

  const xMax = usable.reduce((m, p) => Math.max(m, p.hdd), 0) || 1;

  // Build one polyline per model. For non-linear models, sample evenly.
  const lineDatasets = [];
  const summaryRows  = [];
  ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'].forEach(model => {
    const reg = regressions[model];
    const style = MODEL_STYLE[model];
    if (!reg?.valid) {
      summaryRows.push({ model, style, reg, line: null });
      return;
    }
    const steps = (model === 'linear' || model === 'robust') ? 2 : 50;
    const linePoints = [];
    for (let i = 0; i <= steps; i++) {
      const x = (xMax * i) / steps;
      const y = predictFor(model, reg, x);
      if (y !== null) linePoints.push({ x, y });
    }
    lineDatasets.push({
      label: modelLabel(model),
      data: linePoints,
      type: 'line',
      borderColor: style.color,
      borderDash: style.dash,
      borderWidth: 2,
      pointRadius: 0,
      fill: false,
      tension: 0,
    });
    summaryRows.push({ model, style, reg, line: linePoints });
  });

  charts.push(makeChart(canvas, {
    type: 'scatter',
    data: {
      datasets: [
        ...(prePoints.length ? [{
          label: t('analysis.baseline.chartPre'),
          data: prePoints,
          backgroundColor: preBaselineColor(),
          pointRadius: 3,
        }] : []),
        { label: t('analysis.scatterPoints'), data: points, backgroundColor: u.color, pointRadius: 4 },
        ...lineDatasets,
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'top', labels: { color: themeColors.text2 } } },
      scales: {
        x: { title: { display: true, text: 'HGT' } },
        y: { title: { display: true, text: u.consumption_unit } },
      },
    }
  }, { label: t('analysis.chartAlt.hdd') }));

  // R² summary table — sortiert nach R² absteigend
  const ranked = summaryRows
    .map(r => ({ ...r, r2: r.reg?.r2 ?? -1 }))
    .sort((a, b) => b.r2 - a.r2);
  const bestModel = ranked.find(r => r.reg?.valid);

  const note = document.getElementById('reg-summary');
  if (note) {
    note.innerHTML = `
      <table class="table table--compact">
        <thead><tr><th scope="col">${t('analysis.reg.model')}</th><th scope="col" class="num">R²</th><th scope="col" class="num">${t('analysis.reg.n')}</th><th scope="col">${t('analysis.reg.coeffs')}</th></tr></thead>
        <tbody>
          ${ranked.map(r => `
            <tr class="${bestModel && r.model === bestModel.model ? 'is-best' : ''}">
              <td>
                <span class="legend-swatch" style="background:${r.style.color}; ${r.style.dash.length ? `outline:1px dashed ${r.style.color}; outline-offset:1px;` : ''}"></span>
                ${modelLabel(r.model)}
                ${bestModel && r.model === bestModel.model ? `<span class="badge badge--success">${t('analysis.reg.bestFit')}</span>` : ''}
              </td>
              <td class="num">${r.reg?.valid ? r.reg.r2.toFixed(3) : '–'}</td>
              <td class="num">${r.reg?.n ?? '–'}</td>
              <td class="muted">${formatCoefficients(r.model, r.reg, u)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  }
}

function formatCoefficients(model, reg, u) {
  if (!reg?.valid) return t('analysis.reg.tooFew');
  const unit = u.consumption_unit;
  switch (model) {
    case 'linear':
    case 'robust':
      return `${unit}/HGT = ${(reg.a ?? 0).toFixed(2)}, ${t('analysis.reg.intercept')} = ${(reg.b ?? 0).toFixed(1)}`;
    case 'polynomial':
      return `a = ${(reg.a ?? 0).toExponential(2)}, b = ${(reg.b ?? 0).toFixed(2)}, c = ${(reg.c ?? 0).toFixed(1)}`;
    case 'segmented': {
      const heat = reg.heat || {}; const base = reg.base || {};
      return `HGT≥${reg.split}: ${heat.a?.toFixed(2) ?? '–'}×HGT+${heat.b?.toFixed(1) ?? '–'} | HGT<${reg.split}: ${base.a?.toFixed(2) ?? '–'}×HGT+${base.b?.toFixed(1) ?? '–'}`;
    }
    case 'sigmoid':
      return reg.predict
        ? reg.predict
        : `A=${(reg.A ?? 0).toFixed(1)}, B=${(reg.B ?? 0).toFixed(1)}, C=${reg.C ?? '–'}, θ₀=${(reg.theta0 ?? 0).toFixed(1)}, D=${(reg.D ?? 0).toFixed(0)}`;
    default: return '';
  }
}

function renderSeasonalProfile(monthly, u, consKey) {
  const canvas = document.getElementById('ch-seasonal');
  if (!canvas) return;
  const buckets = Array.from({length:12}, () => []);
  monthly.forEach(m => { if (m[consKey] > 0) buckets[m.month-1].push(m[consKey]); });
  const avgs = buckets.map(arr => arr.length ? arr.reduce((s,v)=>s+v,0)/arr.length : 0);
  const labels = monthNames();
  charts.push(makeChart(canvas, {
    type: 'bar',
    data: { labels, datasets: [{ label: t('analysis.seasonalAvg', { label: u.label }), data: avgs, backgroundColor: u.color + '88', borderColor: u.color }] },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: u.consumption_unit } } } }
  }, { label: t('analysis.chartAlt.seasonal') }));
}

function renderYearComparison(monthly, u, consKey) {
  const canvas = document.getElementById('ch-years');
  if (!canvas) return;
  const byYear = {};
  monthly.forEach(m => {
    if (!byYear[m.year]) byYear[m.year] = Array(12).fill(null);
    byYear[m.year][m.month-1] = m[consKey];
  });
  const years = Object.keys(byYear).sort();
  const colors = ['#22d3ee', '#f59e0b', '#a78bfa', '#10b981', '#ef4444', '#3b82f6'];
  charts.push(makeChart(canvas, {
    type: 'line',
    data: {
      labels: monthNames(),
      datasets: years.map((y, i) => ({
        label: y, data: byYear[y],
        borderColor: colors[i % colors.length],
        backgroundColor: colors[i % colors.length] + '22',
        tension: 0.25, spanGaps: true,
      })),
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: u.consumption_unit } } } }
  }, { label: t('analysis.chartAlt.years') }));
}

// ── F-05: contract-end reminders ───────────────────────────────────
// Renders a banner per contract whose `should_remind` flag is set by the
// backend (days_until_end within one of the configured thresholds).
// remind_stage 1..3 maps to info / warning / danger styling.
function renderContractReminders(contractStatus) {
  const contracts = contractStatus?.contracts || [];
  const due = contracts.filter(c => c.should_remind);
  if (!due.length) return '';

  const stageClass = { 1: 'banner--info', 2: 'banner--warning', 3: 'banner--error' };

  return due.map(c => {
    const stage = c.remind_stage || 1;
    const days = c.days_until_end;
    const provider = c.provider || c.tariff_name || t('analysis.contractEnd.fallbackProvider');
    const stageLabel = t('analysis.contractEnd.stage' + stage);
    const when = days === 0 ? t('analysis.contractEnd.endsToday')
      : days === 1 ? t('analysis.contractEnd.endsTomorrow')
      : t('analysis.contractEnd.endsInDays', { days });
    return `
      <div class="banner ${stageClass[stage] || 'banner--info'}" style="margin-bottom: var(--sp-3)">
        <strong>${t('analysis.contractEnd.title', { stage: stageLabel })}</strong>
        ${t('analysis.contractEnd.ends', { provider: escapeHtml(provider), when })}
        ${c.end ? `(${fmt.date(c.end)})` : ''}.
        <span class="muted"> ${t('analysis.contractEnd.checkNote')}</span>
      </div>
    `;
  }).join('');
}

// ── F-04: year-over-year delta widget ──────────────────────────────
// Month-by-month comparison of the two most recent years that have data,
// with absolute and percentage deltas. Complements the existing
// "Jahresvergleich" line chart with concrete numbers.
function renderYoyWidget(monthly, u, consKey) {
  const byYear = {};
  monthly.forEach(m => {
    if (!byYear[m.year]) byYear[m.year] = Array(12).fill(null);
    byYear[m.year][m.month - 1] = m[consKey];
  });
  const years = Object.keys(byYear).map(Number).sort((a, b) => a - b);
  if (years.length < 2) {
    return `
      <div class="card" style="margin-top: var(--sp-5)">
        <h3 class="card__title">${t('analysis.yoy.title')}</h3>
        <p class="muted">${t('analysis.yoy.needTwo', { count: years.length })}</p>
      </div>
    `;
  }
  const prev = years[years.length - 2];
  const curr = years[years.length - 1];
  const names = monthNames();
  const unit = u.consumption_unit;

  let sumPrev = 0, sumCurr = 0, nBoth = 0;
  const rows = names.map((name, i) => {
    const a = byYear[prev][i];
    const b = byYear[curr][i];
    let delta = null, pct = null;
    if (a != null && b != null) {
      delta = b - a;
      pct = a !== 0 ? (delta / a) * 100 : null;
      sumPrev += a; sumCurr += b; nBoth++;
    }
    return { name, a, b, delta, pct };
  });
  const totalDelta = nBoth ? sumCurr - sumPrev : null;
  const totalPct = nBoth && sumPrev !== 0 ? (totalDelta / sumPrev) * 100 : null;

  const cell = (v, d = 0) => v == null ? '<span class="dim">–</span>' : fmt.num(v, d);
  const deltaCell = (delta, pct) => {
    if (delta == null) return '<td class="num"><span class="dim">–</span></td><td class="num"><span class="dim">–</span></td>';
    const cls = delta > 0 ? 'danger-text' : delta < 0 ? 'success-text' : '';
    const sign = delta > 0 ? '+' : '';
    return `
      <td class="num ${cls}">${sign}${fmt.num(delta, 0)}</td>
      <td class="num ${cls}">${pct == null ? '–' : sign + fmt.num(pct, 1) + ' %'}</td>
    `;
  };

  return `
    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">${t('analysis.yoy.titleYears', { prev, curr })}</h3>
      <div class="table-wrap"><table class="table">
        <thead><tr>
          <th scope="col">${t('analysis.yoy.colMonth')}</th>
          <th scope="col" class="num">${t('analysis.yoy.colYear', { year: prev, unit })}</th>
          <th scope="col" class="num">${t('analysis.yoy.colYear', { year: curr, unit })}</th>
          <th scope="col" class="num">${t('analysis.yoy.colDeltaAbs')}</th>
          <th scope="col" class="num">${t('analysis.yoy.colDeltaPct')}</th>
        </tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr>
              <td><strong>${r.name}</strong></td>
              <td class="num">${cell(r.a)}</td>
              <td class="num">${cell(r.b)}</td>
              ${deltaCell(r.delta, r.pct)}
            </tr>
          `).join('')}
        </tbody>
        <tfoot><tr>
          <td><strong>${t('analysis.yoy.sumRow')}</strong></td>
          <td class="num">${cell(nBoth ? sumPrev : null)}</td>
          <td class="num">${cell(nBoth ? sumCurr : null)}</td>
          ${deltaCell(totalDelta, totalPct)}
        </tr></tfoot>
      </table></div>
      <p class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2)">
        ${t('analysis.yoy.note')}
      </p>
    </div>
  `;
}

// ── F-10: water spar-index ─────────────────────────────────────────
// Index = (Liter/Person/Tag) / Referenz × 100, computed over the most
// recent up-to-12 months of data. Bands (gut / Warnung) come from
// Settings. Only meaningful for the water utility.
async function renderWaterSparindex(monthly) {
  const settings = await getSettings().catch(() => ({}));
  const personen = Number(settings.wasser_personen_anzahl) || 1;
  const referenz = Number(settings.wasser_personen_referenz) || 127;
  const bandGut = Number(settings.wasser_sparindex_gut) || 100;
  const bandWarn = Number(settings.wasser_sparindex_warnung) || 150;

  // Sum m³ and days over the most recent 12 months that carry data.
  const recent = monthly.filter(m => (m.m3 ?? 0) > 0 && (m.days ?? 0) > 0).slice(-12);
  if (!recent.length) {
    return `
      <div class="card">
        <h3 class="card__title">${t('analysis.spar.title')}</h3>
        <p class="muted">${t('analysis.spar.noData')}</p>
      </div>
    `;
  }
  const totalM3 = recent.reduce((s, m) => s + (m.m3 || 0), 0);
  const totalDays = recent.reduce((s, m) => s + (m.days || 0), 0);
  const litersPerPersonDay = totalDays > 0
    ? (totalM3 * 1000) / totalDays / personen
    : 0;
  const index = referenz > 0 ? (litersPerPersonDay / referenz) * 100 : 0;

  const band = index <= bandGut ? 'gut'
             : index >= bandWarn ? 'warnung'
             : 'mittel';
  const bandMeta = {
    gut:     { cls: 'success', label: t('analysis.spar.bandGutLabel'),    note: t('analysis.spar.bandGutNote') },
    mittel:  { cls: 'warning', label: t('analysis.spar.bandMittelLabel'), note: t('analysis.spar.bandMittelNote') },
    warnung: { cls: 'danger',  label: t('analysis.spar.bandWarnLabel'),   note: t('analysis.spar.bandWarnNote') },
  }[band];

  // Position of the index on a 0..(bandWarn × 1.5) scale for the bar.
  const scaleMax = Math.max(bandWarn * 1.5, index * 1.1);
  const pos = Math.min(100, (index / scaleMax) * 100);
  const gutPos = Math.min(100, (bandGut / scaleMax) * 100);
  const warnPos = Math.min(100, (bandWarn / scaleMax) * 100);

  return `
    <div class="card">
      <h3 class="card__title">${t('analysis.spar.title')}</h3>
      <div class="sparindex">
        <div class="sparindex__main">
          <div class="sparindex__value ${bandMeta.cls}-text">${fmt.num(index, 0)}</div>
          <div class="sparindex__badge badge badge--${bandMeta.cls}">${bandMeta.label}</div>
        </div>
        <div class="sparindex__detail">
          <div>${t('analysis.spar.perPersonDay', { liters: fmt.num(litersPerPersonDay, 0) })}
               <span class="muted">${t('analysis.spar.reference', { ref: fmt.num(referenz, 0) })}</span></div>
          <div class="muted" style="font-size: var(--fs-xs)">
            ${t('analysis.spar.basis', {
              months: recent.length === 1 ? t('analysis.spar.monthsOne', { count: recent.length }) : t('analysis.spar.monthsMany', { count: recent.length }),
              m3: fmt.num(totalM3, 1),
              persons: personen === 1 ? t('analysis.spar.personsOne', { count: personen }) : t('analysis.spar.personsMany', { count: personen }),
            })}
          </div>
        </div>
      </div>
      <div class="sparindex__bar">
        <div class="sparindex__bar-track" aria-hidden="true">
          <div class="sparindex__bar-zone sparindex__bar-zone--gut" style="width:${gutPos}%"></div>
          <div class="sparindex__bar-zone sparindex__bar-zone--mittel" style="left:${gutPos}%;width:${warnPos - gutPos}%"></div>
          <div class="sparindex__bar-zone sparindex__bar-zone--warnung" style="left:${warnPos}%;right:0"></div>
          <div class="sparindex__bar-marker" style="left:${pos}%"></div>
        </div>
        <div class="sparindex__bar-labels">
          <span>0</span>
          <span>${t('analysis.spar.barGut', { value: bandGut })}</span>
          <span>${t('analysis.spar.barWarn', { value: bandWarn })}</span>
        </div>
      </div>
      <p class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-3)">
        ${t('analysis.spar.indexNote', { bandNote: bandMeta.note })}
      </p>
    </div>
  `;
}
