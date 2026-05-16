// =====================================================================
// Energietracker v1.1.0 — Analysis view (per utility):
//   - HGT scatter + regression (only for HGT-relevant utilities)
//   - Year-over-year comparison chart + YoY delta widget (F-04)
//   - Contract-end reminders (F-05)
//   - Water spar-index (F-10)
//   - Anomalies (per meter)
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
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
  // Contract status drives the F-05 contract-end reminder. It is optional —
  // a meter without contracts simply yields no reminder banner.
  let contractStatus = null;
  try { contractStatus = await api.contractStatus(u.key, meterId); }
  catch { contractStatus = null; }

  const monthly     = meterData.monthly || [];
  const anomalies   = meterData.anomalies || [];
  const regressions = meterData.regressions || {};
  const consKey     = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';

  body.innerHTML = `
    ${renderContractReminders(contractStatus)}
    ${u.key === 'wasser' ? await renderWaterSparindex(monthly) : ''}
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

    ${renderYoyWidget(monthly, u, consKey)}

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">Anomalien (${anomalies.length})</h3>
      ${anomalies.length === 0 ? '<p class="muted">Keine Anomalien erkannt — alle Monate liegen innerhalb des Erwartungsbereichs (σ-Schwelle aus Einstellungen).</p>' : `
        <p class="muted" style="margin-bottom:12px">
          ${u.hgt_relevant
            ? 'Monate, deren Verbrauch mehr als die σ-Schwelle vom HGT-Regressionsmodell abweicht.'
            : 'Monate, deren Verbrauch mehr als die σ-Schwelle vom Saisonprofil abweicht.'}
        </p>
        <div class="table-wrap"><table class="table">
          <thead><tr>
            <th>Monat</th>
            <th class="num">Verbrauch</th>
            <th class="num">Erwartet</th>
            <th class="num">Δ absolut</th>
            <th class="num">Δ %</th>
            <th class="num">σ-Wert</th>
            ${u.hgt_relevant ? '<th class="num">HGT</th><th class="num">ø Temp</th>' : ''}
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
  linear:     { label: 'Linear',         color: '#60a5fa', dash: []      },
  polynomial: { label: 'Polynomial (2)', color: '#a78bfa', dash: [6, 4]  },
  robust:     { label: 'Robust (Huber)', color: '#10b981', dash: [2, 3]  },
  segmented:  { label: 'Segmentiert',    color: '#f59e0b', dash: [10, 4] },
  sigmoid:    { label: 'Sigmoid',        color: '#ec4899', dash: [4, 2]  },
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

// ── F-05: contract-end reminders ───────────────────────────────────
// Renders a banner per contract whose `should_remind` flag is set by the
// backend (days_until_end within one of the configured thresholds).
// remind_stage 1..3 maps to info / warning / danger styling.
function renderContractReminders(contractStatus) {
  const contracts = contractStatus?.contracts || [];
  const due = contracts.filter(c => c.should_remind);
  if (!due.length) return '';

  const stageClass = { 1: 'banner--info', 2: 'banner--warning', 3: 'banner--error' };
  const stageLabel = { 1: 'Vorwarnung', 2: 'Erinnerung', 3: 'Dringend' };

  return due.map(c => {
    const stage = c.remind_stage || 1;
    const days = c.days_until_end;
    const provider = c.provider || c.tariff_name || 'Vertrag';
    return `
      <div class="banner ${stageClass[stage] || 'banner--info'}" style="margin-bottom: var(--sp-3)">
        <strong>Vertragsende ${stageLabel[stage] || ''}:</strong>
        ${escapeHtml(provider)} endet
        ${days === 0 ? 'heute' : days === 1 ? 'morgen' : `in ${days} Tagen`}
        ${c.end ? `(${fmt.date(c.end)})` : ''}.
        <span class="muted"> Kündigungsfrist und Anschlussvertrag prüfen.</span>
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
        <h3 class="card__title">Jahresvergleich · Monatsdeltas</h3>
        <p class="muted">Mindestens zwei Jahre mit Daten nötig — aktuell ${years.length}.</p>
      </div>
    `;
  }
  const prev = years[years.length - 2];
  const curr = years[years.length - 1];
  const monthNames = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
  const unit = u.consumption_unit;

  let sumPrev = 0, sumCurr = 0, nBoth = 0;
  const rows = monthNames.map((name, i) => {
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
      <h3 class="card__title">Jahresvergleich · Monatsdeltas (${prev} → ${curr})</h3>
      <div class="table-wrap"><table class="table">
        <thead><tr>
          <th>Monat</th>
          <th class="num">${prev} (${unit})</th>
          <th class="num">${curr} (${unit})</th>
          <th class="num">Δ absolut</th>
          <th class="num">Δ %</th>
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
          <td><strong>Summe (gemeinsame Monate)</strong></td>
          <td class="num">${cell(nBoth ? sumPrev : null)}</td>
          <td class="num">${cell(nBoth ? sumCurr : null)}</td>
          ${deltaCell(totalDelta, totalPct)}
        </tr></tfoot>
      </table></div>
      <p class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2)">
        Vergleich nur über Monate, die in beiden Jahren Daten haben.
        Positive Werte = Mehrverbrauch gegenüber dem Vorjahr.
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
        <h3 class="card__title">💧 Wasser-Spar-Index</h3>
        <p class="muted">Noch keine ausreichenden Verbrauchsdaten.</p>
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
    gut:     { cls: 'success', label: 'Unauffällig', note: 'Verbrauch im oder unter dem Referenzbereich.' },
    mittel:  { cls: 'warning', label: 'Beobachten',  note: 'Verbrauch über dem guten Band, aber noch unter der Warnschwelle.' },
    warnung: { cls: 'danger',  label: 'Sparpotenzial', note: 'Verbrauch deutlich über der Referenz — Einsparpotenzial prüfen.' },
  }[band];

  // Position of the index on a 0..(bandWarn × 1.5) scale for the bar.
  const scaleMax = Math.max(bandWarn * 1.5, index * 1.1);
  const pos = Math.min(100, (index / scaleMax) * 100);
  const gutPos = Math.min(100, (bandGut / scaleMax) * 100);
  const warnPos = Math.min(100, (bandWarn / scaleMax) * 100);

  return `
    <div class="card">
      <h3 class="card__title">💧 Wasser-Spar-Index</h3>
      <div class="sparindex">
        <div class="sparindex__main">
          <div class="sparindex__value ${bandMeta.cls}-text">${fmt.num(index, 0)}</div>
          <div class="sparindex__badge badge badge--${bandMeta.cls}">${bandMeta.label}</div>
        </div>
        <div class="sparindex__detail">
          <div>${fmt.num(litersPerPersonDay, 0)} L / Person / Tag
               <span class="muted">· Referenz ${fmt.num(referenz, 0)} L</span></div>
          <div class="muted" style="font-size: var(--fs-xs)">
            Basis: ${recent.length} Monat${recent.length === 1 ? '' : 'e'} ·
            ${fmt.num(totalM3, 1)} m³ · ${personen} Person${personen === 1 ? '' : 'en'}
          </div>
        </div>
      </div>
      <div class="sparindex__bar">
        <div class="sparindex__bar-track">
          <div class="sparindex__bar-zone sparindex__bar-zone--gut" style="width:${gutPos}%"></div>
          <div class="sparindex__bar-zone sparindex__bar-zone--mittel" style="left:${gutPos}%;width:${warnPos - gutPos}%"></div>
          <div class="sparindex__bar-zone sparindex__bar-zone--warnung" style="left:${warnPos}%;right:0"></div>
          <div class="sparindex__bar-marker" style="left:${pos}%"></div>
        </div>
        <div class="sparindex__bar-labels">
          <span>0</span>
          <span>gut ≤ ${bandGut}</span>
          <span>Warnung ≥ ${bandWarn}</span>
        </div>
      </div>
      <p class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-3)">
        ${bandMeta.note} Index 100 = exakt der in den Einstellungen hinterlegte
        Referenzverbrauch pro Person.
      </p>
    </div>
  `;
}
