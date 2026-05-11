// =====================================================================
// Dashboard — overview of all utilities, last 12 months.
// =====================================================================

import { api } from '../api.js';
import { getUtilities } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { makeChart } from '../components/chart.js';
import { toastErr } from '../components/toast.js';

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade Übersicht…</div>';
  const utilities = await getUtilities();

  // Fetch consumption for each utility in parallel
  const datasets = await Promise.all(
    utilities.map(async u => {
      try {
        const c = await api.consumption(u.key);
        return { utility: u, consumption: c };
      } catch (e) {
        toastErr(`${u.label}: ${e.message}`);
        return { utility: u, consumption: null };
      }
    })
  );

  container.innerHTML = `
    <div class="section-head">
      <h1>Übersicht</h1>
      <div class="section-actions">
        <a class="btn btn--ghost" href="#/temperatures">Temperaturen</a>
        <a class="btn btn--primary" href="#/forecast">Prognose</a>
      </div>
    </div>

    <div class="grid grid-2">
      ${datasets.map(d => renderUtilityCard(d)).join('')}
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">12-Monats-Verbrauch (alle Verbrauchsarten)</h3>
      <div class="chart-wrap"><canvas id="dash-chart"></canvas></div>
    </div>
  `;

  // Render combined chart
  renderCombinedChart(datasets);

  // Cleanup: destroy chart on next nav
  return () => {
    const ch = window._dashChart;
    if (ch) { ch.destroy(); window._dashChart = null; }
  };
}

function renderUtilityCard({ utility, consumption }) {
  const last12 = (consumption?.monthly_total || []).slice(-12);
  const sumKey = utility.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  const totalCons = last12.reduce((s, m) => s + (m[sumKey] || 0), 0);
  const totalCost = last12.reduce((s, m) => s + (m.cost  || 0), 0);
  const meters = consumption?.meters || [];
  const activeMeters = meters.filter(m => m.meter.active);

  return `
    <div class="card" data-utility="${utility.key}">
      <div class="section-head" style="margin-bottom: var(--sp-3)">
        <h2><span style="color: ${utility.color}">${utility.icon}</span> ${escapeHtml(utility.label)}</h2>
        <div class="section-actions">
          <a class="btn btn--sm btn--ghost" href="#/utility/${utility.key}/meters">Zähler</a>
          <a class="btn btn--sm btn--util" href="#/utility/${utility.key}">Details</a>
        </div>
      </div>
      <div class="grid grid-3">
        <div class="kpi">
          <div class="kpi__label">Verbrauch 12 M</div>
          <div class="kpi__value">${fmt.num(totalCons, 0)}</div>
          <div class="kpi__sub">${utility.consumption_unit}</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">Kosten 12 M</div>
          <div class="kpi__value">${fmt.eur(totalCost)}</div>
          <div class="kpi__sub">inkl. Boni</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">Aktive Zähler</div>
          <div class="kpi__value">${activeMeters.length}</div>
          <div class="kpi__sub">${meters.length} insgesamt</div>
        </div>
      </div>
    </div>
  `;
}

function renderCombinedChart(datasets) {
  const canvas = document.getElementById('dash-chart');
  if (!canvas) return;

  // Find the union of months across all utilities (last 12)
  const allMonths = new Set();
  datasets.forEach(d => (d.consumption?.monthly_total || []).slice(-12).forEach(m => allMonths.add(m.ym)));
  const months = Array.from(allMonths).sort().slice(-12);

  const seriesList = datasets.map(d => {
    const u = d.utility;
    const key = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
    const byYm = Object.fromEntries((d.consumption?.monthly_total || []).map(m => [m.ym, m[key]]));
    return {
      label: `${u.label} (${u.consumption_unit})`,
      data:  months.map(m => byYm[m] ?? 0),
      borderColor: u.color,
      backgroundColor: u.color + '33',
      tension: 0.25,
      yAxisID: u.consumption_unit === 'kWh' ? 'y_kwh' : 'y_m3',
    };
  });

  const cfg = {
    type: 'line',
    data: { labels: months.map(m => fmt.month(m)), datasets: seriesList },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        y_kwh: { position: 'left',  title: { display: true, text: 'kWh' } },
        y_m3:  { position: 'right', title: { display: true, text: 'm³'  }, grid: { drawOnChartArea: false } },
      },
    }
  };
  window._dashChart = makeChart(canvas, cfg);
}
