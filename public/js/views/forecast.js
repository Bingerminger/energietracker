// =====================================================================
// Forecast view — works for any utility and meter.            v1.1.0
//   - 12-month chart (historical + forecast)
//   - Per-month detail table: Verbrauch, HGT, Kosten, projizierter
//     Abschlag, laufender Saldo (F-02), method label
//   - What-if controls: temp offset, price factor, model
// =====================================================================

import { api } from '../api.js';
import { activeUtilities, getSettings } from '../state.js';
import { fmt, escapeHtml, parseDecimal, formatForInput } from '../lib/format.js';
import { makeChart, utilColor, tokenColor, chartTableHtml } from '../components/chart.js';
import { toastErr } from '../components/toast.js';
import { t } from '../lib/i18n.js';
import { info } from '../components/info.js';
import { balanceView, isFeedIn } from '../lib/semantics.js';

let chart = null;

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('forecast.loading')}</div>`;
  // v2.2.0 — nur aktive Verbrauchsarten anbieten, wie Dashboard und
  // Seitenleiste. Vorher listete die Auswahl auch abgeschaltete Arten und
  // führte auf leere Prognosen.
  const utilities = await activeUtilities();
  if (!utilities.length) {
    container.innerHTML = `<div class="banner banner--info">${escapeHtml(t('forecast.noUtilities'))}</div>`;
    return;
  }

  container.innerHTML = `
    <div class="section-head">
      <h1>${t('forecast.title')}</h1>
      <div class="section-actions">
        <select class="select" id="util-select" aria-label="${t('forecast.selectUtility')}">
          ${utilities.map(u => `<option value="${u.key}">${u.icon} ${escapeHtml(u.label)}</option>`).join('')}
        </select>
        <select class="select" id="meter-select" aria-label="${t('forecast.selectMeter')}"></select>
      </div>
    </div>

    <div class="card">
      <h3 class="card__title">${t('forecast.whatIf')}</h3>
      <div class="form-row">
        <div class="field">
          <label for="temp-offset">${t('forecast.tempOffset')}</label>
          <input class="input" id="temp-offset" type="text" autocomplete="off" value="${formatForInput(0)}">
        </div>
        <div class="field">
          <label for="price-factor">${t('forecast.priceFactor')}</label>
          <input class="input" id="price-factor" type="text" inputmode="decimal" autocomplete="off" value="${formatForInput(1)}">
        </div>
        <div class="field">
          <label for="model">${t('forecast.model')}</label>
          <select class="select" id="model">
            <option value="linear">${t('forecast.models.linear')}</option>
            <option value="polynomial">${t('forecast.models.polynomial')}</option>
            <option value="robust">${t('forecast.models.robust')}</option>
            <option value="segmented">${t('forecast.models.segmented')}</option>
            <option value="sigmoid">${t('forecast.models.sigmoid')}</option>
          </select>
        </div>
        <div class="field">
          <label for="months">${t('forecast.horizon')}</label>
          <input class="input" id="months" type="text" inputmode="numeric" autocomplete="off" value="12">
        </div>
      </div>
      <div class="form-actions"><button class="btn btn--util" id="btn-go">${t('forecast.update')}</button></div>
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">${t('forecast.history')}</h3>
      <div class="chart-wrap"><canvas id="fc-chart"></canvas></div>
      <div data-role="fc-chart-data"></div>
      <div id="fc-info" class="muted" style="margin-top: var(--sp-3)"></div>
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">${t('forecast.monthlyDetail')}</h3>
      <div id="fc-table"></div>
    </div>
  `;

  const utilSel  = container.querySelector('#util-select');
  const meterSel = container.querySelector('#meter-select');
  let currentMeters = [];

  async function loadMeters() {
    const utility = utilities.find(u => u.key === utilSel.value);
    currentMeters = await api.meters(utility.key);
    meterSel.innerHTML = currentMeters.map(m => `<option value="${escapeHtml(m.id)}">${escapeHtml(m.name)}</option>`).join('');
  }

  // v2.15.0 (Review FE-16) — nur der zuletzt gestartete Lauf zeichnet. Zwei
  // schnelle Klicks legten zwei Charts auf dasselbe Canvas; der zweite warf
  // „Canvas is already in use“ roh und englisch in einen Toast.
  let runSeq = 0;
  async function run() {
    const my = ++runSeq;
    const utility = utilities.find(u => u.key === utilSel.value);
    if (!currentMeters.length) {
      container.querySelector('#fc-info').textContent = t('forecast.noMeters');
      container.querySelector('#fc-table').innerHTML = '';
      return;
    }
    // v2.5.3 (FE-02) — Leer heißt „keine Änderung" (Versatz 0, Faktor 1,
    // 12 Monate), nicht 0: Ein leerer Preisfaktor rechnete die Kosten auf 0 €.
    // Der Temperaturversatz hat bewusst kein inputmode="decimal" — die
    // iOS-Zahlentastatur kennt kein Minus.
    const whatIf = (id, fallback, ok) => {
      const el = container.querySelector(`#${id}`);
      const raw = el.value.trim();
      const n = raw === '' ? fallback : parseDecimal(raw);
      const valid = n !== null && ok(n);
      el.classList.toggle('invalid', !valid);
      return valid ? n : null;
    };
    const tempOffset  = whatIf('temp-offset', 0, n => Math.abs(n) <= 20);
    const priceFactor = whatIf('price-factor', 1, n => n >= 0 && n <= 10);
    const months      = whatIf('months', 12, n => Number.isInteger(n) && n >= 1 && n <= 24);
    if (tempOffset === null || priceFactor === null || months === null) {
      toastErr(t('forecast.whatIfInvalid'));
      return;
    }
    const opts = {
      temp_offset:  tempOffset,
      price_factor: priceFactor,
      model:        container.querySelector('#model').value,
      forecast_months: months,
    };
    try {
      const result = await api.forecast(utility.key, meterSel.value, opts);
      if (my !== runSeq) return;   // überholt
      renderResult(utility, result, container);
    } catch (e) { if (my === runSeq) toastErr(e.message); }
  }

  utilSel.addEventListener('change',  async () => { await loadMeters(); run(); });
  meterSel.addEventListener('change', run);
  container.querySelector('#btn-go').addEventListener('click', run);

  // v2.13.0 (Review FE-10) — Modell und Horizont aus den Einstellungen
  // vorbelegen. Bis v2.12 stand hier fest „linear, 12", während der
  // Tarifvergleich das eingestellte Modell nutzte: zwei Ansichten, zwei
  // verschiedene Prognosen.
  const s = (await getSettings().catch(() => null)) || {};
  const modelSel = container.querySelector('#model');
  if (modelSel && [...modelSel.options].some(o => o.value === s.forecast_model)) modelSel.value = s.forecast_model;
  const fm = Number(s.forecast_months);
  if (Number.isInteger(fm) && fm >= 1 && fm <= 24) container.querySelector('#months').value = String(fm);

  await loadMeters();
  await run();

  return () => { if (chart) { chart.destroy(); chart = null; } };
}

function renderResult(u, result, container) {
  // v2.13.0 — nicht `info`: Der Name gehört dem ⓘ-Knopf aus components/info.js,
  // die lokale Variable überschattete ihn und die Tabelle blieb leer
  const infoEl = container.querySelector('#fc-info');
  const tbl  = container.querySelector('#fc-table');

  const chartData = container.querySelector('[data-role="fc-chart-data"]');
  if (!result.valid) {
    if (chart) { chart.destroy(); chart = null; }
    infoEl.innerHTML =`<span class="danger-text">${escapeHtml(result.reason || t('forecast.noForecast'))}</span>`;
    tbl.innerHTML = '';
    if (chartData) chartData.innerHTML = '';
    return;
  }

  const consKey = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  const hist = result.historical || [];
  const fc   = result.forecast   || [];

  // Build chart
  const labels  = [...hist.map(h => fmt.month(h.ym)), ...fc.map(f => fmt.month(f.ym))];
  const histData = [...hist.map(h => h[consKey]), ...fc.map(() => null)];
  const fcData   = [...hist.map(() => null), ...fc.map(f => f[consKey])];
  // v2.1.5 — die gestrichelte Prognoselinie am letzten Historie-Punkt andocken,
  // damit zwischen Historie und Prognose keine sichtbare Lücke entsteht.
  if (hist.length > 0 && fc.length > 0) fcData[hist.length - 1] = hist[hist.length - 1][consKey];
  // v2.8.0 (CALC-13) — Unsicherheitsband als Fläche zwischen zwei Linien
  const hasBand = fc.some(f => f.band_low != null && f.band_high != null);
  const bandLow  = [...hist.map(() => null), ...fc.map(f => f.band_low ?? null)];
  const bandHigh = [...hist.map(() => null), ...fc.map(f => f.band_high ?? null)];

  const canvas = container.querySelector('#fc-chart');
  chart = makeChart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: t('forecast.chartHist'),     data: histData, borderColor: utilColor(u), backgroundColor: utilColor(u, 0.13), tension: 0.25, spanGaps: false },
        ...(hasBand ? [
          { label: '', data: bandLow, borderColor: 'transparent', pointRadius: 0, tension: 0.25, spanGaps: false, fill: false },
          { label: t('forecast.chartBand', { level: result.annual?.level_pct ?? 80 }), data: bandHigh, borderColor: 'transparent', backgroundColor: utilColor(u, 0.15), pointRadius: 0, tension: 0.25, spanGaps: false, fill: '-1' },
        ] : []),
        // v2.15.0 (Review FE-11) — Farbe als Funktion: Bis v2.14 behielt die
        // Linie nach dem Theme-Wechsel das helle Grau und verschwand (1,0:1)
        { label: t('forecast.chartForecast'), data: fcData,   borderColor: tokenColor('text1'), borderDash: [6,4], backgroundColor: tokenColor('text1', 0.06), tension: 0.25, spanGaps: false },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      // die Unterkante des Bands hat keinen eigenen Legendeneintrag
      plugins: { legend: { labels: { filter: (item) => item.text !== '' } } },
      scales: { y: { title: { display: true, text: u.consumption_unit } } },
    }
  }, { label: hist.length && fc.length
    ? t('forecast.chartAltSpan', { from: fmt.month(hist[0].ym), to: fmt.month(hist[hist.length - 1].ym), until: fmt.month(fc[fc.length - 1].ym) })
    : t('forecast.chartAlt') });

  // v2.15.0 (Review FE-20) — Verlauf und Prognose als Tabelle zum Aufklappen
  if (chartData) {
    const unit = u.consumption_unit;
    chartData.innerHTML = chartTableHtml({
      caption: t('forecast.history'),
      columns: [t('forecast.col.month'), `${t('forecast.chartHist')} (${unit})`, `${t('forecast.chartForecast')} (${unit})`,
        ...(hasBand ? [t('forecast.chartBand', { level: result.annual?.level_pct ?? 80 })] : [])],
      rows: [
        ...hist.map(h => [fmt.month(h.ym), fmt.int(h[consKey]), null, ...(hasBand ? [null] : [])]),
        ...fc.map(f => [fmt.month(f.ym), null, fmt.int(f[consKey]),
          ...(hasBand ? [f.band_low != null && f.band_high != null ? `${fmt.int(f.band_low)} – ${fmt.int(f.band_high)}` : null] : [])]),
      ],
    });
  }

  const reg = result.regression;
  // v2.13.0 (Review UI-17) — Modellname übersetzt statt Schlüssel („linear", „seasonal_only")
  const modelKey = u.hgt_relevant ? (reg?.model || 'linear') : 'seasonal_only';
  const modelName = modelKey === 'seasonal_only' ? t('forecast.models.seasonalOnly') : t(`forecast.models.${modelKey}`);
  const lines = [t('forecast.info', {
    model: escapeHtml(modelName === `forecast.models.${modelKey}` ? modelKey : modelName),
    blend: fmt.num(result.blend_weight * 100, 1),
    r2: reg ? ` (${t('forecast.r2Label')} ${fmt.num(reg.r2, 3)})` : '',
    price: fmt.num(result.last_price_ct, 3),
    unit: u.consumption_unit,
  })];
  // v2.8.0 — Jahresband und Herkunft der Heizgradtage
  if (result.annual) {
    lines.push(t('forecast.annualBand', {
      value: fmt.num(result.annual.value, 0), low: fmt.num(result.annual.low, 0), high: fmt.num(result.annual.high, 0),
      level: result.annual.level_pct, unit: u.consumption_unit,
    }));
  }
  if (u.hgt_relevant && result.hdd_source === 'climate_normal' && result.climate_normal?.period) {
    lines.push(t('forecast.hddSourceClimate', {
      from: String(result.climate_normal.period.from).slice(0, 4), to: String(result.climate_normal.period.to).slice(0, 4),
    }));
  } else if (u.hgt_relevant && result.hdd_source === 'temperature_history') {
    lines.push(t('forecast.hddSourceHistory'));
  }
  const warn = (result.warnings || []).map(w => {
    if (w.code === 'history_short') {
      const names = (w.missing || []).map(m => fmt.month(`2024-${String(m).padStart(2, '0')}`).replace(/\s*2024$/, '')).join(', ');
      return t('forecast.warn.historyShort', { months: w.months, missing: names || '–' });
    }
    if (w.code === 'no_climate_normal') return t('forecast.warn.noClimateNormal');
    return '';
  }).filter(Boolean);
  infoEl.innerHTML = lines.join('<br>')
    + (warn.length ? `<div class="banner banner--warning" style="margin-top: var(--sp-3)">${warn.map(escapeHtml).join('<br>')}</div>` : '');

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
        ${t('forecast.balanceLabel')}
        <strong>${fmt.eur(Math.abs(lastBalance))}</strong>
        ${lastBalance > 5 ? t('forecast.balanceSurcharge') : lastBalance < -5 ? t('forecast.balanceCredit') : t('forecast.balanceBalanced')}
        <span class="muted"> ${t('forecast.balanceNote')}</span>
      </div>
    ` : ''}
    <div class="table-wrap"><table class="table">
      <thead><tr>
        <th scope="col">${t('forecast.col.month')}</th><th scope="col" class="num">${t('forecast.col.consumption', { unit: u.consumption_unit })}</th>
        ${u.hgt_relevant ? `<th scope="col" class="num">${t('forecast.col.hgt')}</th>` : ''}
        <th scope="col" class="num">${t('forecast.col.cost')}</th>
        <th scope="col" class="num">${t('forecast.col.advance')}</th>
        <th scope="col" class="num">${t('forecast.col.balance')}${info('balance')}</th>
        <th scope="col">${t('forecast.col.method')}</th>
      </tr></thead>
      <tbody>
        ${fc.map(r => {
          // v2.13.0 (Review UI-18) — Vorzeichen aus Kundensicht, + heißt Guthaben
          const balView = balanceView(r.balance_running, u);
          return `
          <tr>
            <td>${fmt.month(r.ym)}</td>
            <td class="num">${fmt.num(r[consKey], 0)}</td>
            ${u.hgt_relevant ? `<td class="num">${fmt.num(r.hdd_estimated, 0)}</td>` : ''}
            <td class="num">${fmt.eur(r.cost_estimated)}${r.contract_assumed ? ' *' : ''}</td>
            <td class="num">${r.advance_estimated != null ? fmt.eur(r.advance_estimated) : '<span class="dim">–</span>'}</td>
            <td class="num ${balView ? balView.cls : ''}">${balView ? balView.signed : '<span class="dim">–</span>'}</td>
            <td>${methodLabel(r.method)}</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table></div>
    ${hasFinance ? `
      <p class="muted" style="font-size: var(--fs-xs); margin-top: var(--sp-2)">
        ${t(isFeedIn(u) ? 'saldo.tableLegendFeedIn' : 'saldo.tableLegend')} ${t('forecast.bonusNote')}
        ${fc.some(r => r.contract_assumed) ? `<br>* ${t('forecast.assumedNote')}` : ''}
      </p>
    ` : ''}
  `;
}

// v2.8.0 — Methoden lesbar. Das API-Feld bleibt unverändert
// (`blend(reg=0.56, seasonal=0.44)`), übersetzt wird nur die Anzeige.
function methodLabel(method) {
  const known = ['seasonal_only', 'regression_only', 'heat_model', 'filled'];
  if (known.includes(method)) return escapeHtml(t('forecast.method.' + method));
  const blend = /^blend\(reg=([\d.]+), seasonal=([\d.]+)\)$/.exec(method || '');
  if (blend) return escapeHtml(t('forecast.method.blend', { reg: fmt.pct(Number(blend[1]), 0), seasonal: fmt.pct(Number(blend[2]), 0) }));
  return `<code class="mono" style="font-size: var(--fs-xs)">${escapeHtml(method)}</code>`;
}
