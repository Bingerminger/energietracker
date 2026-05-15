// =====================================================================
// Forecast view — works for any utility and meter.            v1.1.0
//   - 12-month chart (historical + forecast)
//   - Per-month detail table: Verbrauch, HGT, Kosten, projizierter
//     Abschlag, laufender Saldo (F-02), method label
//   - What-if controls: temp offset, price factor, model
// =====================================================================

import { api } from '../api.js';
import { getUtilities } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { makeChart, themeColors } from '../components/chart.js';
import { toastErr } from '../components/toast.js';

let chart = null;

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade…</div>';
  const utilities = await getUtilities();

  container.innerHTML = `
    <div class="section-head">
      <h1>🔮 Prognose</h1>
      <div class="section-actions">
        <select class="select" id="util-select">
          ${utilities.map(u => `<option value="${u.key}">${u.icon} ${escapeHtml(u.label)}</option>`).join('')}
        </select>
        <select class="select" id="meter-select"></select>
      </div>
    </div>

    <div class="card">
      <h3 class="card__title">What-if-Simulator</h3>
      <div class="form-row">
        <div class="field">
          <label>Temperatur-Offset (°C)</label>
          <input class="input" id="temp-offset" type="number" step="0.5" value="0">
        </div>
        <div class="field">
          <label>Preis-Faktor</label>
          <input class="input" id="price-factor" type="number" step="0.05" value="1.0">
        </div>
        <div class="field">
          <label>Modell</label>
          <select class="select" id="model">
            <option value="linear">Linear</option>
            <option value="polynomial">Polynomial (Grad 2)</option>
            <option value="robust">Robust (Huber)</option>
            <option value="segmented">Segmentiert (Heizen/Sommer)</option>
          </select>
        </div>
        <div class="field">
          <label>Horizont (Monate)</label>
          <input class="input" id="months" type="number" min="1" max="24" value="12">
        </div>
      </div>
      <div class="form-actions"><button class="btn btn--util" id="btn-go">Aktualisieren</button></div>
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">Verlauf + Prognose</h3>
      <div class="chart-wrap"><canvas id="fc-chart"></canvas></div>
      <div id="fc-info" class="muted" style="margin-top: var(--sp-3)"></div>
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">Monatsdetail</h3>
      <div id="fc-table"></div>
    </div>
  `;

  const utilSel  = container.querySelector('#util-select');
  const meterSel = container.querySelector('#meter-select');
  let currentMeters = [];

  async function loadMeters() {
    const utility = utilities.find(u => u.key === utilSel.value);
    currentMeters = await api.meters(utility.key);
    meterSel.innerHTML = currentMeters.map(m => `<option value="${m.id}">${escapeHtml(m.name)}</option>`).join('');
  }

  async function run() {
    if (chart) { chart.destroy(); chart = null; }
    const utility = utilities.find(u => u.key === utilSel.value);
    if (!currentMeters.length) {
      container.querySelector('#fc-info').textContent = 'Keine Zähler.';
      container.querySelector('#fc-table').innerHTML = '';
      return;
    }
    const opts = {
      temp_offset:  Number(container.querySelector('#temp-offset').value),
      price_factor: Number(container.querySelector('#price-factor').value),
      model:        container.querySelector('#model').value,
      forecast_months: Number(container.querySelector('#months').value),
    };
    try {
      const result = await api.forecast(utility.key, meterSel.value, opts);
      renderResult(utility, result, container);
    } catch (e) { toastErr(e.message); }
  }

  utilSel.addEventListener('change',  async () => { await loadMeters(); run(); });
  meterSel.addEventListener('change', run);
  container.querySelector('#btn-go').addEventListener('click', run);

  await loadMeters();
  await run();

  return () => { if (chart) { chart.destroy(); chart = null; } };
}

function renderResult(u, result, container) {
  const info = container.querySelector('#fc-info');
  const tbl  = container.querySelector('#fc-table');

  if (!result.valid) {
    info.innerHTML = `<span class="danger-text">${escapeHtml(result.reason || 'Keine Prognose möglich')}</span>`;
    tbl.innerHTML = '';
    return;
  }

  const consKey = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  const hist = result.historical || [];
  const fc   = result.forecast   || [];

  // Build chart
  const labels  = [...hist.map(h => fmt.month(h.ym)), ...fc.map(f => fmt.month(f.ym))];
  const histData = [...hist.map(h => h[consKey]), ...fc.map(() => null)];
  const fcData   = [...hist.map(() => null), ...fc.map(f => f[consKey])];

  const canvas = container.querySelector('#fc-chart');
  chart = makeChart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Historie',  data: histData, borderColor: u.color, backgroundColor: u.color + '22', tension: 0.25, spanGaps: false },
        { label: 'Prognose',  data: fcData,   borderColor: themeColors.text1, borderDash: [6,4], backgroundColor: 'rgba(231,236,243,0.06)', tension: 0.25, spanGaps: false },
      ],
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: u.consumption_unit } } } }
  });

  const reg = result.regression;
  info.innerHTML = `
    Modell: <strong>${u.hgt_relevant ? (reg?.model || 'linear') : 'seasonal_only'}</strong> ·
    Blend-Gewicht: <strong>${fmt.num(result.blend_weight * 100, 1)}%</strong>${reg ? ` (R² ${fmt.num(reg.r2, 3)})` : ''} ·
    Letzter Arbeitspreis: <strong>${fmt.num(result.last_price_ct, 3)} ct/${u.consumption_unit}</strong>
  `;

  // F-02: the forecast now carries a full contract-aware finance projection.
  // `cost_estimated` uses the per-month working/base price of the active
  // contract; `advance_estimated` is the projected Abschlag; `balance_running`
  // is the cumulative (Kosten − Abschlag) — negative = Guthaben, positive =
  // Nachzahlung. The running balance of the final month is the projected
  // year-end balance.
  const hasFinance = fc.some(r => r.advance_estimated != null || r.balance_running != null);
  const lastBalance = fc.length ? fc[fc.length - 1].balance_running : null;

  tbl.innerHTML = `
    ${hasFinance && lastBalance != null ? `
      <div class="banner ${lastBalance > 5 ? 'banner--warning' : lastBalance < -5 ? 'banner--success' : 'banner--info'}"
           style="margin-bottom: var(--sp-3)">
        Projizierter Saldo am Ende des Horizonts:
        <strong>${fmt.eur(Math.abs(lastBalance))}</strong>
        ${lastBalance > 5 ? 'Nachzahlung' : lastBalance < -5 ? 'Guthaben' : '(ausgeglichen)'}
        <span class="muted"> · kumuliert aus geschätzten Kosten minus projizierten Abschlägen</span>
      </div>
    ` : ''}
    <div class="table-wrap"><table class="table">
      <thead><tr>
        <th>Monat</th><th class="num">Verbrauch (${u.consumption_unit})</th>
        ${u.hgt_relevant ? '<th class="num">HGT geschätzt</th>' : ''}
        <th class="num">Kosten geschätzt</th>
        <th class="num">Abschlag projiziert</th>
        <th class="num">Saldo laufend</th>
        <th>Methode</th>
      </tr></thead>
      <tbody>
        ${fc.map(r => {
          const bal = r.balance_running;
          const balCls = bal == null ? '' : (bal > 0 ? 'danger-text' : bal < 0 ? 'success-text' : '');
          return `
          <tr>
            <td>${fmt.month(r.ym)}</td>
            <td class="num">${fmt.num(r[consKey], 0)}</td>
            ${u.hgt_relevant ? `<td class="num">${fmt.num(r.hdd_estimated, 0)}</td>` : ''}
            <td class="num">${fmt.eur(r.cost_estimated)}</td>
            <td class="num">${r.advance_estimated != null ? fmt.eur(r.advance_estimated) : '<span class="dim">–</span>'}</td>
            <td class="num ${balCls}">${bal != null ? fmt.eur(bal) : '<span class="dim">–</span>'}</td>
            <td><code class="mono" style="font-size: var(--fs-xs)">${escapeHtml(r.method)}</code></td>
          </tr>`;
        }).join('')}
      </tbody>
    </table></div>
    ${hasFinance ? `
      <p class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2)">
        Künftige Boni werden nicht fortgeschrieben — nur im Vertrag gepflegte Boni
        mit Gutschriftdatum im Prognosezeitraum fließen in die Kosten ein.
      </p>
    ` : ''}
  `;
}
