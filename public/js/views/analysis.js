// =====================================================================
// Analysis view — works for any utility:
//   - HGT scatter + regression (only for HGT-relevant utilities)
//   - Year-over-year comparison
//   - Anomalies (per meter)
// =====================================================================

import { api } from '../api.js';
import { getUtilities } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { makeChart, themeColors } from '../components/chart.js';
import { toastErr } from '../components/toast.js';

let charts = [];

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade…</div>';
  const utilities = await getUtilities();

  container.innerHTML = `
    <div class="section-head">
      <h1>📊 Analyse</h1>
      <div class="section-actions">
        <select class="select" id="util-select">
          ${utilities.map(u => `<option value="${u.key}">${u.icon} ${escapeHtml(u.label)}</option>`).join('')}
        </select>
        <select class="select" id="meter-select"></select>
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
    body.innerHTML = '<div class="loading">Lade…</div>';
    const meters = await api.meters(utility.key);
    meterSel.innerHTML = meters.map(m => `<option value="${m.id}">${escapeHtml(m.name)}</option>`).join('');
    if (!meters.length) { body.innerHTML = '<p class="muted">Keine Zähler.</p>'; return; }
    await renderForMeter(utility, meterSel.value || meters[0].id, body);
  }

  utilSel.addEventListener('change', reload);
  meterSel.addEventListener('change', async () => {
    const utility = utilities.find(u => u.key === utilSel.value);
    destroyCharts();
    body.innerHTML = '<div class="loading">Lade…</div>';
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
  const monthly     = meterData.monthly || [];
  const anomalies   = meterData.anomalies || [];
  const regressions = meterData.regressions || {};
  const consKey     = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';

  body.innerHTML = `
    <div class="grid grid-2">
      ${u.hgt_relevant ? `
        <div class="card">
          <h3 class="card__title">HGT-Korrelation · vier Regressionsmodelle</h3>
          <div class="chart-wrap"><canvas id="ch-hdd"></canvas></div>
          <div class="regression-summary" id="reg-summary"></div>
        </div>
      ` : `
        <div class="card">
          <h3 class="card__title">Saisonprofil</h3>
          <div class="chart-wrap"><canvas id="ch-seasonal"></canvas></div>
          <p class="muted">${escapeHtml(u.label)} ist nicht temperaturabhängig — Saisonprofil statt HGT.</p>
        </div>
      `}

      <div class="card">
        <h3 class="card__title">Jahresvergleich</h3>
        <div class="chart-wrap"><canvas id="ch-years"></canvas></div>
      </div>
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">Anomalien (${anomalies.length})</h3>
      ${anomalies.length === 0 ? '<p class="muted">Keine Anomalien erkannt.</p>' : `
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Monat</th><th class="num">Verbrauch</th><th class="num">Erwartet</th><th class="num">Abweichung (σ)</th></tr></thead>
          <tbody>
            ${anomalies.map(a => `
              <tr>
                <td>${fmt.month(a.ym)}</td>
                <td class="num">${fmt.num(a.actual, 0)}</td>
                <td class="num">${fmt.num(a.expected, 0)}</td>
                <td class="num ${a.z >= 0 ? 'danger-text' : 'success-text'}">${fmt.num(a.z, 2)}</td>
              </tr>`).join('')}
          </tbody></table></div>
      `}
    </div>
  `;

  // Render charts
  if (u.hgt_relevant) renderHgtScatter(monthly, u, consKey, regressions);
  else                renderSeasonalProfile(monthly, u, consKey);
  renderYearComparison(monthly, u, consKey);
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
    default: return null;
  }
}

const MODEL_STYLE = {
  linear:     { label: 'Linear',         color: '#60a5fa', dash: []      },
  polynomial: { label: 'Polynomial (2)', color: '#a78bfa', dash: [6, 4]  },
  robust:     { label: 'Robust (Huber)', color: '#10b981', dash: [2, 3]  },
  segmented:  { label: 'Segmentiert',    color: '#f59e0b', dash: [10, 4] },
};

function renderHgtScatter(monthly, u, consKey, regressions) {
  const canvas = document.getElementById('ch-hdd');
  if (!canvas) return;
  const points = monthly
    .filter(m => m.hdd > 0 && m[consKey] > 0)
    .map(m => ({ x: m.hdd, y: m[consKey], ym: m.ym }));

  const xMax = points.reduce((m, p) => Math.max(m, p.x), 0) || 1;

  // Build one polyline per model. For non-linear models, sample evenly.
  const lineDatasets = [];
  const summaryRows  = [];
  ['linear', 'polynomial', 'robust', 'segmented'].forEach(model => {
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
      label: style.label,
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
        { label: 'Monate', data: points, backgroundColor: u.color, pointRadius: 4 },
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
  }));

  // R² summary table — sortiert nach R² absteigend
  const ranked = summaryRows
    .map(r => ({ ...r, r2: r.reg?.r2 ?? -1 }))
    .sort((a, b) => b.r2 - a.r2);
  const bestModel = ranked.find(r => r.reg?.valid);

  const note = document.getElementById('reg-summary');
  if (note) {
    note.innerHTML = `
      <table class="table table--compact">
        <thead><tr><th>Modell</th><th class="num">R²</th><th class="num">n</th><th>Koeffizienten</th></tr></thead>
        <tbody>
          ${ranked.map(r => `
            <tr class="${bestModel && r.model === bestModel.model ? 'is-best' : ''}">
              <td>
                <span class="legend-swatch" style="background:${r.style.color}; ${r.style.dash.length ? `outline:1px dashed ${r.style.color}; outline-offset:1px;` : ''}"></span>
                ${r.style.label}
                ${bestModel && r.model === bestModel.model ? '<span class="badge badge--success">bestes Fit</span>' : ''}
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
  if (!reg?.valid) return 'zu wenige Datenpunkte';
  const unit = u.consumption_unit;
  switch (model) {
    case 'linear':
    case 'robust':
      return `${unit}/HGT = ${(reg.a ?? 0).toFixed(2)}, Achsenabschnitt = ${(reg.b ?? 0).toFixed(1)}`;
    case 'polynomial':
      return `a = ${(reg.a ?? 0).toExponential(2)}, b = ${(reg.b ?? 0).toFixed(2)}, c = ${(reg.c ?? 0).toFixed(1)}`;
    case 'segmented': {
      const heat = reg.heat || {}; const base = reg.base || {};
      return `HGT≥${reg.split}: ${heat.a?.toFixed(2) ?? '–'}×HGT+${heat.b?.toFixed(1) ?? '–'} | HGT<${reg.split}: ${base.a?.toFixed(2) ?? '–'}×HGT+${base.b?.toFixed(1) ?? '–'}`;
    }
    default: return '';
  }
}

function renderSeasonalProfile(monthly, u, consKey) {
  const canvas = document.getElementById('ch-seasonal');
  if (!canvas) return;
  const buckets = Array.from({length:12}, () => []);
  monthly.forEach(m => { if (m[consKey] > 0) buckets[m.month-1].push(m[consKey]); });
  const avgs = buckets.map(arr => arr.length ? arr.reduce((s,v)=>s+v,0)/arr.length : 0);
  const labels = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
  charts.push(makeChart(canvas, {
    type: 'bar',
    data: { labels, datasets: [{ label: u.label + ' ø', data: avgs, backgroundColor: u.color + '88', borderColor: u.color }] },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: u.consumption_unit } } } }
  }));
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
      labels: ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'],
      datasets: years.map((y, i) => ({
        label: y, data: byYear[y],
        borderColor: colors[i % colors.length],
        backgroundColor: colors[i % colors.length] + '22',
        tension: 0.25, spanGaps: true,
      })),
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: u.consumption_unit } } } }
  }));
}
