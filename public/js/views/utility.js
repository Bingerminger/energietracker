// =====================================================================
// Energietracker — Utility view (Gas/Strom/Wasser/Fernwärme/Heizöl/Pellets)
// Restored v0.9.0 layout:
//   1. Status-Banner (overdue alert / OK)
//   2. Year pills
//   3. KPI grid (Verbrauch / Kosten / Abschläge & Saldo Jahr / Tagesschnitt / CO₂)
//   4. Saldo-Karte aktueller Vertrag (4 spalten + verdict)
//   5. Vertragstabelle mit current_balance + projected_end_balance
//   6. Monatschart
//   7. Monatstabelle (Abschlag, Saldo kum., MA-3, MA-6)
//   8. Zählerstand-Tabelle
// All per selected meter (F3 — Multi-Meter dropdown).
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { tankLevel } from '../lib/tank.js';
import { fmt, escapeHtml, todayIso, parseDecimal, formatForInput, monthShortNames } from '../lib/format.js';
import { makeChart, utilColor, tokenColor, chartColor, withAlpha, chartTableHtml } from '../components/chart.js';
import { isPartial, daysInMonth, yoyTrend, seriesSummary } from '../lib/chart-data.js';
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { showFieldError } from '../lib/form.js';
import { toastOk, toastErr } from '../components/toast.js';
import { t, getCurrencyMinor, getCurrencySymbol } from '../lib/i18n.js';
import { info, infoNote } from '../components/info.js';
import { isFeedIn as feedInKind, isGeneration as generationKind, isPv, usesGasFactors, balanceView, moreIsBetter } from '../lib/semantics.js';
import { typicalPerDay, checkReading, confirmIssues, issueText, deviceChangedBetween } from '../lib/plausibility.js';

let _chart = null;
let _stockChart = null;   // v2.10.0 — Bestandsverlauf (Tankbuch)
let _balanceChart = null; // v2.16.0 — Saldo-Verlauf im Abrechnungszeitraum
let _pvChart = null;      // v2.16.0 — PV-Energiefluss
// v2.11.0 (Review FE-05) — nur der zuletzt gestartete Aufbau zeichnet. Wer
// schnell den Zähler wechselte, sah sonst die Stände des vorigen unter dem
// neuen Namen.
let rerenderSeq = 0;
const state = {
  utility: null,
  meters: [],
  selectedMeterId: null,
  // v2.15.0 (Review FE-22, UI-28) — das Jahr je Verbrauchsart. Bis v2.14
  // galt eins für alle: Nach Fernwärme (Daten bis 2025) öffneten Heizöl und
  // PV im Jahr 2025, obwohl es 2026 gab.
  yearByUtility: {},
  // v2.16.0 (Review FE-19) — Monatschart gemessen oder witterungsbereinigt, je Verbrauchsart
  chartMode: {},
};

/**
 * v2.15.0 (Review FE-29) — Auswahl in der Adresse: `#/utility/gas?year=2025&meter=…`
 * lässt sich teilen, als Lesezeichen ablegen und übersteht Neuladen.
 * replaceState statt eines neuen Eintrags: Zurück führt zur vorigen Seite,
 * nicht durch jede angeklickte Jahreszahl.
 */
function syncAddress(u, latestYear = null) {
  const q = new URLSearchParams();
  const year = state.yearByUtility[u.key];
  // Das jüngste Jahr ist der Normalfall — ein Lesezeichen soll dann auch im
  // nächsten Jahr das jüngste zeigen, nicht das von heute
  if (year && year !== latestYear) q.set('year', String(year));
  if (state.selectedMeterId && state.meters.length > 1) q.set('meter', state.selectedMeterId);
  const hash = `#/utility/${u.key}${q.toString() ? '?' + q : ''}`;
  if (window.location.hash !== hash) {
    try { history.replaceState(history.state, '', hash); } catch { /* egal */ }
  }
}

export async function render(container, params, ctx = {}) {
  const utilities = await getUtilities();
  const utilityKey = params[0];
  const utility = utilities.find(u => u.key === utilityKey);
  if (!utility) {
    container.innerHTML = `<div class="status-banner alert"><div class="status-banner__icon">⚠️</div>
      <div class="status-banner__text">${t('utility.unknown', { key: escapeHtml(utilityKey) })}</div></div>`;
    return;
  }
  state.utility = utility;
  container.setAttribute('data-utility', utility.key);

  container.innerHTML = `<div class="loading">${t('utility.loading')}</div>`;

  try {
    const meters = await api.meters(utility.key);
    state.meters = meters;
    // v2.15.0 — Auswahl aus der Adresse (?meter=…&year=…) vor der gemerkten
    const wantMeter = ctx.query?.get('meter');
    if (wantMeter && meters.some(m => m.id === wantMeter)) state.selectedMeterId = wantMeter;
    if (!state.selectedMeterId || !meters.find(m => m.id === state.selectedMeterId)) {
      state.selectedMeterId = meters[0]?.id || null;
    }
    const wantYear = Number(ctx.query?.get('year'));
    if (Number.isInteger(wantYear) && wantYear > 1900 && wantYear < 3000) state.yearByUtility[utility.key] = wantYear;
    await rerender(container);
    // v2.11.0 — Sprungziele des Erfassen-Blatts: ?add=delivery | level | reading
    const add = ctx.query?.get('add');
    const target = { delivery: '#btn-new-delivery', level: '#btn-new-level', reading: '#btn-new-reading' }[add];
    if (target) {
      // Adresse ohne ?add=… — sonst öffnete Neuladen den Dialog erneut
      syncAddress(utility);
      container.querySelector(target)?.click();
    }
  } catch (e) {
    container.innerHTML = `<div class="status-banner alert"><div class="status-banner__icon">⚠️</div>
      <div class="status-banner__text">${escapeHtml(e.message)}</div></div>`;
  }

  return () => {
    if (_chart) { _chart.destroy(); _chart = null; }
    if (_stockChart) { _stockChart.destroy(); _stockChart = null; }
    if (_balanceChart) { _balanceChart.destroy(); _balanceChart = null; }
    if (_pvChart) { _pvChart.destroy(); _pvChart = null; }
  };
}

async function rerender(container) {
  const u = state.utility;
  const my = ++rerenderSeq;

  if (!state.meters.length || !state.selectedMeterId) {
    container.innerHTML = `
      ${header(u)}
      <div class="empty">
        <div class="empty-icon">📊</div>
        <h2>${t('utility.noMeters.title')}</h2>
        <p>${t('utility.noMeters.text', { label: escapeHtml(u.label) })}</p>
        <button class="btn btn--primary" id="goto-meters">${t('utility.noMeters.cta')}</button>
      </div>`;
    container.querySelector('#goto-meters')?.addEventListener('click', () => {
      location.hash = `#/utility/${u.key}/meters`;
    });
    return;
  }

  const meter = state.meters.find(m => m.id === state.selectedMeterId);

  let consumptionData, contractStatusData;
  try {
    [consumptionData, contractStatusData] = await Promise.all([
      api.meterConsumption(u.key, meter.id),
      api.contractStatus(u.key, meter.id),
    ]);
  } catch (e) {
    if (my !== rerenderSeq) return;
    container.innerHTML = `<div class="status-banner alert"><div class="status-banner__icon">⚠️</div>
      <div class="status-banner__text">${escapeHtml(e.message)}</div></div>`;
    return;
  }
  if (my !== rerenderSeq) return;

  const monthly   = consumptionData.monthly   || [];
  const contracts = contractStatusData.contracts || [];
  const isDelivery = u.reading_kind === 'delivery';
  // v2.9.0 — Rechtshinweise (Sonderkündigungsrecht) nur im passenden Land;
  // die Schwelle „Ablesung überfällig" aus den Einstellungen (CALC-23)
  const settings = (await getSettings().catch(() => null)) || {};
  const country = settings.country || 'DE';
  const alertDays = Math.max(1, Number(settings.alert_days_since_reading) || 45);
  // v1.6.1 — Fix #14: Verbrauchs-Feldname utility-abhängig.
  // Wasser/m³-native Utilities tragen den Verbrauch im Feld `m3`,
  // kWh-Utilities im Feld `kwh`. Vorher las das KPI immer `m.kwh`
  // → Wasser-Dashboard zeigte 0.
  const consKey = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  // P-PV-01 — PV-Einspeisung: der „Verbrauch" ist ein Ertrag, die
  // „Kosten" sind ein Vergütungs-Erlös. Labels, Farben und Vorzeichen-
  // Deutung kippen für accounting_kind = feed_in.
  const isFeedIn = u.accounting_kind === 'feed_in';
  // v2.10.0 (CALC-17) — Erzeugung emittiert nichts und kostet nichts: CO₂
  // als vermieden, keine Kostenkachel (bis v2.9 als Emission wie ein Verbrauch)
  const isGeneration = u.accounting_kind === 'generation';
  let readings = [], deliveries = [], stockHist = null, pvSummary = null;
  if (isDelivery) {
    [deliveries, stockHist] = await Promise.all([
      api.deliveries(u.key, meter.id).catch(() => []),
      api.stockHistory(u.key, meter.id).catch(() => null),
    ]);
  } else {
    // v2.16.0 (Review FE-17, FE-31) — PV-Seiten zeigen den Energiefluss aus der PV-Zusammenfassung
    [readings, pvSummary] = await Promise.all([
      api.readings(u.key, meter.id),
      isPv(u) ? api.pvSummary().catch(() => null) : null,
    ]);
  }
  if (my !== rerenderSeq) return;

  // ── Status banner: how many days since last reading ─────────────
  const sortedReadings = [...readings]
    .filter(r => !r.is_future)
    .sort((a, b) => b.date.localeCompare(a.date));
  const lastReading = !isDelivery ? sortedReadings[0] : null;
  let statusBannerHtml = '';
  if (lastReading) {
    const today = new Date(todayIso());
    const lastDate = new Date(lastReading.date);
    const days = Math.floor((today - lastDate) / 86400000);
    // v2.9.0 (CALC-23) — die Einstellung wirkt: Alarm ab der Schwelle,
    // Hinweis ab zwei Dritteln (Standard 45 → ab 30 Tagen gelb, ab 46 rot;
    // bis v2.8 fest 30/60 und die Einstellung ohne Wirkung)
    let cls = 'ok', icon = '✓';
    if (days > alertDays)                        { cls = 'alert'; icon = '⚠️'; }
    else if (days > Math.round(alertDays * 2 / 3)) { cls = 'warn';  icon = '⚡'; }
    // v2.15.0 (Review FE-08) — Trend gegen dieselben Monate des Vorjahres,
    // nur volle Monate, bei Heizarten witterungsbereinigt. Bis v2.14 standen
    // die letzten drei gegen die drei davor: Heizsaison gegen Sommer ergab
    // bei Fernwärme +470 %, ein halber März bei Gas −48 %. Deshalb war der
    // Pfeil bei PV ganz abgeschaltet; gegen das Vorjahr gilt er auch dort
    // (mehr Sonnenstrom ist gut).
    let trendStr = '';
    const tr = yoyTrend(monthly, consKey, { months: 3, adjustedKey: u.hgt_relevant ? 'heat_adjusted' : null });
    if (tr && Math.abs(tr.pct) >= 0.5) {
      const up = tr.pct > 0;
      const tone = up === moreIsBetter(u) ? 'success' : 'danger';
      const span = tr.months.length > 1
        ? `${fmt.month(tr.months[0])} – ${fmt.month(tr.months[tr.months.length - 1])}` : fmt.month(tr.months[0]);
      const label = t(tr.adjusted ? 'utility.banner.trendYoYAdjusted' : 'utility.banner.trendYoY', { span });
      trendStr = ` · ${escapeHtml(label)} <span class="kpi__trend kpi__trend--${tone}"><span aria-hidden="true">${up ? '▲' : '▼'}</span> ${up ? '+' : '−'}${fmt.num(Math.abs(tr.pct), 1)} %</span>`;
    }
    statusBannerHtml = `
      <div class="status-banner ${cls}">
        <div class="status-banner__icon">${icon}</div>
        <div class="status-banner__text">
          <strong>${t('utility.banner.lastReading', { days })}</strong> · ${fmt.date(lastReading.date)}${trendStr}
        </div>
        <button class="btn btn-${u.key} btn--sm" id="banner-new-reading">${t('utility.action.newReadingBanner')}</button>
      </div>`;
  }

  // ── v2.6.0 — unplausible Stände (Backend: plausibleReadings) ─────
  // Bis v2.5.3 fielen sie still aus der Rechnung; jetzt stehen sie hier.
  const warnings = Array.isArray(consumptionData.warnings) ? consumptionData.warnings : [];
  const warnByReading = new Map(warnings.filter(w => w.reading_id).map(w => [w.reading_id, w]));
  const warningsBannerHtml = warnings.length ? `
      <div class="status-banner warn" id="reading-warnings">
        <div class="status-banner__icon">⚠️</div>
        <div class="status-banner__text">
          <strong>${t('utility.warnings.title', { count: warnings.length })}</strong>
          <div class="muted" style="font-size:12px">${t('utility.warnings.hint')}</div>
          <ul class="warning-list">
            ${warnings.slice(0, 5).map(w => `<li>${escapeHtml(warningText(w, u))}</li>`).join('')}
            ${warnings.length > 5 ? `<li>… (+${warnings.length - 5})</li>` : ''}
          </ul>
        </div>
        ${warnings.some(w => w.type === 'decrease' || w.type === 'suspect')
          ? `<a class="btn btn--ghost btn--sm" href="#/utility/${u.key}/meters">${t('utility.warnings.gotoMeters')}</a>` : ''}
      </div>` : '';

  // ── Years available ─────────────────────────────────────────────
  // v2.15.0 (Review UI-28) — auch aus Ablesungen und Lieferungen: Ein Stand
  // in einem Jahr ohne Monatswert (etwa ein vorgemerkter) war nicht erreichbar
  const yearOf = (d) => Number(String(d || '').slice(0, 4));
  const years = [...new Set([
    ...monthly.map(m => m.year),
    ...readings.map(r => yearOf(r.date)),
    ...deliveries.map(d => yearOf(d.date)),
  ].filter(y => Number.isInteger(y) && y > 1900))].sort((a, b) => a - b);
  // Vorgewählt ist das jüngste Jahr mit Monatswerten — ein vorgemerkter
  // Stand im nächsten Jahr soll nicht auf ein leeres Jahr führen
  const dataYears = monthly.map(m => m.year).filter(Number.isInteger);
  const latestYear = dataYears.length ? Math.max(...dataYears) : (years[years.length - 1] || new Date().getFullYear());
  if (!years.includes(state.yearByUtility[u.key])) state.yearByUtility[u.key] = latestYear;
  const yr = state.yearByUtility[u.key];
  const monthlyYear = monthly.filter(m => m.year === yr);
  // v2.16.0 (Review FE-19, FE-31) — das Vorjahr als Vergleich im Monatschart;
  // witterungsbereinigt nach dem Heizmodell (`heat_adjusted`), wo es Werte gibt
  const prevYear = monthly.filter(m => m.year === yr - 1);
  const canAdjust = !!u.hgt_relevant && monthlyYear.some(m => m.heat_adjusted != null);
  const chartMode = canAdjust && state.chartMode[u.key] === 'adjusted' ? 'adjusted' : 'measured';
  const pvFlowRows = (pvSummary?.monthly || []).filter(m => m.year === yr
    && ((m.erzeugung_kwh ?? 0) > 0 || (m.einspeisung_kwh ?? 0) > 0));
  // Zähler mit weniger als zwei Ständen: noch keine Monatswerte
  const noValues = !isDelivery && monthly.length === 0;

  // ── Compute year totals for KPIs ────────────────────────────────
  // v1.6.1 — Fix #14: nutze utility-spezifischen Feldnamen (consKey)
  // statt hartkodiertem `m.kwh`, sonst zeigt das Wasser-Dashboard 0.
  const totUnit = monthlyYear.reduce((s, m) => s + (m[consKey] || 0), 0);
  const totCost = monthlyYear.reduce((s, m) => s + (m.cost || 0), 0);
  const totDays = monthlyYear.reduce((s, m) => s + (m.days || 0), 0);
  const totCO2  = monthlyYear.reduce((s, m) => s + (m.co2_kg || 0), 0);
  const totM3   = monthlyYear.reduce((s, m) => s + (m.m3 || 0), 0);
  const totAdv  = monthlyYear.reduce((s, m) => s + (m.advance_eur || 0), 0);
  const yearBalance = totCost - totAdv;
  const yearBalanceView = balanceView(yearBalance, u);
  const hasContract = monthlyYear.some(m => m.contract_id);
  const currentContract = contracts.find(c => c.is_current);
  // v2.8.0 — Die Kacheln summieren die Monatszeilen, also nur abgelesene
  // Monate. Liegt die letzte Ablesung im laufenden Jahr zurück, nennt die
  // Abschlagskachel ihren Stand — sonst stünde „Abschläge 2026: 390 €" neben
  // den 1.170 €, die die Saldo-Karte nach Kalender bis heute zählt.
  const measuredUntil = currentContract?.measured_until || null;
  const partialYear = !!measuredUntil && String(yr) === todayIso().slice(0, 4)
    && measuredUntil < todayIso() && measuredUntil.startsWith(String(yr));

  // ── Render whole page ───────────────────────────────────────────
  container.innerHTML = `
    ${header(u, meter)}

    ${statusBannerHtml}
    ${warningsBannerHtml}

    ${noValues ? `
    <!-- v2.13.0 (Review UI-27) — Leerzustand statt „0 kWh, 0,00 €" und eines
         leeren Diagramms: Monatswerte gibt es erst ab zwei Ständen -->
    <div class="card empty-card">
      <h2 class="card__title">${escapeHtml(t('utility.noData.title'))}</h2>
      <p>${escapeHtml(t(readings.length ? 'utility.noData.oneReading' : 'utility.noData.noReading'))}</p>
      <a class="btn btn--primary" href="#/zaehlerstaende?meter=${encodeURIComponent(meter.id)}">${escapeHtml(t('dashboard.todo.readingAction'))}</a>
    </div>` : ''}

    ${noValues ? '' : yearPills(years, yr, u.key)}

    ${noValues ? '' : `<div class="kpi-grid">
      <div class="kpi c-${u.key}">
        <div class="kpi__label">${u.icon} ${isFeedIn ? t('utility.kpi.feedIn') : isGeneration ? t('utility.kpi.generation') : t('utility.kpi.consumption')} ${yr}</div>
        <div class="kpi__value">${fmt.unit(totUnit, u.consumption_unit, 0)}</div>
        ${usesGasFactors(u) ? `<div class="kpi__sub">${fmt.unit(totM3, 'm³', 0)}</div>` : ''}
      </div>
      ${isGeneration ? '' : `
      <div class="kpi c-${u.key}">
        <div class="kpi__label">${isFeedIn ? t('utility.kpi.revenue') : t('utility.kpi.cost')} ${yr}</div>
        <div class="kpi__value ${isFeedIn ? 'success-text' : ''}">${fmt.eur(totCost)}</div>
        <div class="kpi__sub">${t('utility.kpi.perMonth', { value: fmt.eur(monthlyYear.length ? totCost / monthlyYear.length : 0) })}</div>
      </div>`}
      ${hasContract && !isFeedIn ? `
        <div class="kpi c-yellow">
          <div class="kpi__label">${partialYear ? t('utility.kpi.advancesUntil', { date: fmt.date(measuredUntil) }) : t('utility.kpi.advances', { year: yr })}</div>
          <div class="kpi__value">${fmt.eur(totAdv)}</div>
          <!-- v2.13.0 (Review UI-18) — Kundensicht: „Guthaben 75,29 €" statt „−75,29 €" -->
          <div class="kpi__sub ${yearBalanceView?.credit ? 'positive' : (yearBalanceView?.credit === false ? 'negative' : '')}">
            ${t(partialYear ? 'utility.kpi.balanceShort' : 'utility.kpi.yearBalance', { value: `${yearBalanceView.word} ${fmt.eur(yearBalanceView.amount)}` })}${info('balance')}
          </div>
        </div>
      ` : ''}
      <div class="kpi c-blue">
        <div class="kpi__label">${t('utility.kpi.dailyAvg')}</div>
        <div class="kpi__value">${totDays ? fmt.unit(totUnit / totDays, u.consumption_unit, 1).replace(u.consumption_unit, '') : '–'}
          <span style="font-size:14px;color:var(--text-2)">${u.consumption_unit}</span></div>
        <div class="kpi__sub">${t('utility.kpi.daysCount', { days: totDays })}</div>
      </div>
      ${isFeedIn || isGeneration ? `
      <div class="kpi c-violet">
        <div class="kpi__label">${t('utility.kpi.co2Avoided', { year: yr })}${info('co2Avoided')}</div>
        <!-- v2.13.0 — „vermieden“ ohne Minus: das Wort trägt die Richtung (wie im Jahresbericht) -->
        <div class="kpi__value">${fmt.int(totCO2)} <span style="font-size:14px;color:var(--text-2)">kg</span></div>
        <div class="kpi__sub">${t('utility.kpi.co2AvoidedSub', { tons: fmt.num(totCO2 / 1000, 2) })}</div>
      </div>
      ` : `
      <div class="kpi c-violet">
        <div class="kpi__label">${t('utility.kpi.co2', { year: yr })}</div>
        <div class="kpi__value">${fmt.int(totCO2)} <span style="font-size:14px;color:var(--text-2)">kg</span></div>
        <div class="kpi__sub">${t('utility.kpi.co2Sub', { tons: fmt.num(totCO2 / 1000, 2) })}</div>
      </div>`}
    </div>`}

    ${!isDelivery && currentContract ? balanceCard(currentContract, u, country) : ''}

    ${!isDelivery && u.has_contracts !== false ? `
    <div class="card">
      <div class="card__title">${t('utility.cards.contracts')}
        <span class="card__title-action">
          <button class="btn btn--ghost btn--sm" id="btn-new-contract" title="${t('utility.cards.manageContractsTitle')}">${t('utility.cards.manageContracts')}</button>
        </span>
      </div>
      ${contractsTable(contracts, u)}
    </div>
    ` : ''}

    ${noValues ? '' : `<div class="card">
      <div class="card__title">${u.icon} ${t(isFeedIn ? 'utility.cards.monthlyChartFeedIn' : isGeneration ? 'utility.cards.monthlyChartGeneration' : 'utility.cards.monthlyChart', { year: yr })}
        ${canAdjust ? `<span class="card__title-action seg" role="group" aria-label="${escapeHtml(t('utility.chart.modeLabel'))}">
          <button type="button" class="seg__btn ${chartMode === 'measured' ? 'active' : ''}" data-chart-mode="measured" aria-pressed="${chartMode === 'measured'}">${escapeHtml(t('utility.chart.modeMeasured'))}</button>
          <button type="button" class="seg__btn ${chartMode === 'adjusted' ? 'active' : ''}" data-chart-mode="adjusted" aria-pressed="${chartMode === 'adjusted'}">${escapeHtml(t('utility.chart.modeAdjusted'))}</button>
        </span>${info('weatherAdjusted')}` : ''}
      </div>
      <div class="chart-wrap h300"><canvas id="month-chart"></canvas></div>
      <p class="chart-note" data-role="month-chart-note">${monthChartNote(monthlyYear, chartMode)}</p>
    </div>

    ${pvFlowRows.length ? pvFlowHtml(pvFlowRows, yr) : ''}

    <div class="card">
      <div class="card__title">${t('utility.cards.monthlyTable', { year: yr })}</div>
      ${monthlyTable(monthlyYear, u, hasContract)}
    </div>`}

    ${isDelivery ? `
    <div class="card">
      <div class="card__title">${t('utility.cards.stockTank')}
        ${stockHist && stockHist.capacity ? `<span class="card__title-action">
          <span class="muted" style="font-size:12px">${stockTankSummary(stockHist, u)}</span>
        </span>` : ''}
      </div>
      ${stockTankBar(stockHist, u, settings.tank_warn_pct)}
      ${stockHist && stockHist.capacity ? `<div class="chart-wrap h220"><canvas id="stock-chart"></canvas></div>` : ''}
      ${tankNotes(stockHist, u)}
      ${tankLevelsBlock(meter, u)}
    </div>

    <div class="card">
      <div class="card__title">${t('utility.cards.deliveries')}
        <span class="card__title-action">
          <span class="muted" style="font-size:12px;margin-right:8px">${t('utility.cards.deliveriesCount', { count: deliveries.length })}</span>
          <button class="btn btn-${u.key} btn--sm" id="btn-new-delivery">${t('utility.action.newDelivery')}</button>
        </span>
      </div>
      ${deliveriesTable(deliveries, u)}
    </div>
    ` : `
    <div class="card">
      <div class="card__title">${t('utility.cards.readingsYear', { year: yr })}
        <span class="card__title-action">
          <span class="muted" style="font-size:12px;margin-right:8px">${t('utility.cards.readingsCount', { count: readings.length })}</span>
          <button class="btn btn-${u.key} btn--sm" id="btn-new-reading">${t('utility.action.newReading')}</button>
        </span>
      </div>
      ${readingsTable(readings, u, yr, warnByReading)}
    </div>
    `}
    ${usesGasFactors(u) ? billCheckLink(meter, yr) : ''}
  `;

  // Chart
  if (!noValues) drawMonthChart('month-chart', monthlyYear, u, yr, prevYear, chartMode);
  if (isDelivery) drawStockChart('stock-chart', stockHist, u, yr);
  if (!isDelivery && currentContract) drawBalanceChart('balance-chart', currentContract, u);
  if (pvFlowRows.length) drawPvFlowChart('pv-flow-chart', pvFlowRows, yr, await getUtilities().catch(() => []));

  // Wire up events
  wireEvents(container, u, meter, readings, contracts, deliveries, monthly);
  if (isDelivery) wireTankLevels(container, u, meter);
  syncAddress(u, latestYear);
}

// ── F1012: Rechnungsprüfung — seit v2.11.0 eine eigene Seite (views/bill-check.js)
function billCheckLink(meter, year) {
  const href = `#/bill-check?meter=${encodeURIComponent(meter.id)}&from=${year}-01-01&to=${year + 1}-01-01`;
  return `
    <div class="card card--link">
      <div class="card__title">${t('nav.billCheck')}</div>
      <p class="muted">${t('utility.billCheck.hint')}</p>
      <a class="btn btn--ghost btn--sm" href="${href}">${escapeHtml(t('utility.billCheck.open', { year }))}</a>
    </div>`;
}

function header(u, meter = null) {
  const icon = u.icon;
  const meterSelectorHtml = state.meters.length > 1 ? `
    <select class="select" id="meter-select" style="width:auto" aria-label="${t('utility.header.selectMeter')}">
      ${state.meters.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === state.selectedMeterId ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
    </select>` : (meter ? `<span class="muted" style="font-size:12px;align-self:center">${escapeHtml(meter.name)}</span>` : '');

  return `
    <div class="view-header">
      <div>
        <h1 class="view-header__title" style="color:var(--util-${u.key})"><span aria-hidden="true">${icon}</span> ${escapeHtml(u.label)}</h1>
        <div class="view-header__subtitle">${t(u.reading_kind === 'delivery' ? 'utility.subtitleDelivery' : feedInKind(u) ? 'utility.subtitleFeedIn' : generationKind(u) ? 'utility.subtitleGeneration' : 'utility.subtitle')}</div>
      </div>
      <div class="view-header__actions">
        ${meterSelectorHtml}
        <a class="btn btn--ghost btn--sm" href="#/utility/${u.key}/meters" title="${t('utility.header.metersTitle')}"><span aria-hidden="true">⚙️</span> ${t('utility.header.meters')}</a>
        ${u.reading_kind === 'delivery'
          // v2.5.3 — Heizöl/Pellets werden über Lieferungen erfasst. Der Knopf
          // „+ Ablesung" legte hier unsichtbare, wirkungslose Datensätze an.
          ? `<button class="btn btn-${u.key} btn--sm" id="header-new-delivery">${t('utility.action.newDelivery')}</button>`
          : `<button class="btn btn-${u.key} btn--sm" id="header-new-reading">${t('utility.action.newReading')}</button>`}
      </div>
    </div>
  `;
}

function yearPills(years, current, utilityKey) {
  if (!years.length) return '';
  // v2.15.0 — aria-pressed: die aktive Pille war nur an der Farbe erkennbar
  return `<div class="year-pills" role="group" aria-label="${escapeHtml(t('utility.yearPills'))}">
    ${years.map(y =>
      `<button type="button" class="pill ${y === current ? 'active ' + utilityKey : ''}" data-year="${y}" aria-pressed="${y === current}">${y}</button>`
    ).join('')}
  </div>`;
}

// ── Saldo-Karte aktueller Vertrag ───────────────────────────────────
function balanceCard(c, u, country = 'DE') {
  // P-PV-01 — feed_in (PV-Einspeisung): „Saldo" ist ein Vergütungs-Erlös.
  // Verdict-Farben und Saldo-Vorzeichen-Deutung kippen: positiver Saldo
  // ist gut (grün), nicht warnend (rot).
  const isFeedIn = u.accounting_kind === 'feed_in';
  const cur = c.current_balance;
  const proj = c.projected_end_balance;
  // N1007 — verdict ist jetzt ein stabiler Key (surcharge|refund|payout|reclaim|balanced).
  // Logik (Farbe/Pfeil) auf dem Key, Anzeige lokalisiert über t('utility.verdict.<key>').
  const verdict = c.verdict;
  const verdictCls = { refund: 'refund', payout: 'refund', surcharge: 'surcharge', reclaim: 'surcharge', balanced: 'balanced' }[verdict] || 'balanced';
  const arrow = { refund: '↓', payout: '↓', surcharge: '↑', reclaim: '↑', balanced: '→' }[verdict] || '→';
  const curView = balanceView(cur, u);
  const dateLabel = isFeedIn
    ? t('utility.balance.dateNext', { date: fmt.date(c.effective_end) })
    : (c.is_open_ended || c.renewed   // v2.9.0 — weiterlaufend: nächste Abrechnung, kein Vertragsende
        ? t('utility.balance.dateNext', { date: fmt.date(c.effective_end) })
        : t('utility.balance.dateEnd', { date: fmt.date(c.effective_end) }));

  const tariffParts = [];
  const isWater = u.key === 'wasser';
  if (c.current_working_price_ct != null) tariffParts.push(isWater
    ? t('utility.balance.unitWorkingWater', { value: fmt.num(c.current_working_price_ct, 4) })
    : t('utility.balance.unitWorking', { value: fmt.num(c.current_working_price_ct, 4) }));
  if (c.current_base_price_eur   != null) tariffParts.push(t('utility.balance.unitBase', { value: fmt.num(c.current_base_price_eur, 2) }));
  if (c.current_advance_amount   != null) tariffParts.push(t('utility.balance.unitAdvance', { value: fmt.eur(c.current_advance_amount) }));
  const tariffText = tariffParts.length ? tariffParts.join(' · ') : t('utility.balance.noTariff');

  const consumedSub = isWater
    ? t('utility.balance.consumedMonthsWater', { months: c.months_actual, m3: fmt.num(c.actual_m3 || 0, 1) })
    : t('utility.balance.consumedMonths', { months: c.months_actual, kwh: fmt.int(c.actual_kwh) });
  // v2.8.0 (CALC-02) — Kosten bis heute: gemessen bis zur letzten Ablesung,
  // danach geschätzt (Wetter, Saison). Ältere Server liefern nur actual_cost.
  const costToDate = c.cost_to_date ?? c.actual_cost;
  const estimated = (c.estimated_cost_to_date || 0) > 0.005 && c.measured_until;
  const measuredSub = estimated
    ? t('utility.balance.measuredThenEstimated', { date: fmt.date(c.measured_until), kwh: fmt.int(c.actual_kwh), value: fmt.eur(c.estimated_cost_to_date) })
    : consumedSub;
  // Die Teile der Summe (energy/base/bonus_to_date) — ältere Server liefern
  // nur die gemessenen Monate
  const hasParts = c.energy_cost_to_date != null && c.base_to_date != null;
  // UI-36 — Abschlagsvorschlag, wenn er sich spürbar vom heutigen unterscheidet
  const suggestHtml = !isFeedIn && c.suggested_advance != null && c.current_advance_amount != null
      && c.suggested_advance > 0 && Math.abs(proj) > 5
      && Math.abs(c.suggested_advance - c.current_advance_amount) >= 5
    ? `<p class="balance-suggest">${t('utility.balance.suggestAdvance', { suggested: fmt.eur(c.suggested_advance), current: fmt.eur(c.current_advance_amount) })}</p>`
    : '';
  // v2.9.0 (CALC-10, CALC-11) — weiterlaufender Vertrag, verpasste Frist,
  // angekündigte Preiserhöhung
  const notes = [];
  if (c.renewed) notes.push(t('utility.balance.renewed', { date: fmt.date(c.end) }));
  if (c.cancel_missed) notes.push(t('utility.balance.cancelMissed', { date: fmt.date(c.cancel_by), end: fmt.date(c.end) }));
  if (c.price_increase) {
    const pi = c.price_increase;
    const what = [];
    if (pi.working_price_ct) what.push(t('utility.balance.piWorking', { from: fmt.num(pi.working_price_ct[0], 2), to: fmt.num(pi.working_price_ct[1], 2) }));
    if (pi.base_price_eur) what.push(t('utility.balance.piBase', { from: fmt.num(pi.base_price_eur[0], 2), to: fmt.num(pi.base_price_eur[1], 2) }));
    notes.push(t('utility.balance.priceIncrease', { date: fmt.date(pi.from), what: what.join(', ') })
      + (country === 'DE' ? ' ' + t('utility.balance.priceIncreaseDe') : ''));
  }
  const notesHtml = notes.map(n => `<p class="balance-note">${escapeHtml(n)}</p>`).join('');

  // Breakdown row: for water we want three component pills, for gas/strom the
  // simple verbrauch+grundpreis+bonus line.
  const breakdownHtml = isWater && c.components
    ? renderWaterBreakdown(c.components, c.actual_bonus_total)
    : (() => {
        const parts = [];
        const energy = hasParts ? c.energy_cost_to_date : c.actual_kwh_cost;
        const base   = hasParts ? c.base_to_date : c.actual_base_total;
        const bonus  = hasParts ? c.bonus_to_date : c.actual_bonus_total;
        if (energy != null) parts.push(t('utility.balance.breakdownConsumption', { value: fmt.eur(energy) }));
        if (base > 0)       parts.push(t('utility.balance.breakdownBase', { value: fmt.eur(base) }));
        if (bonus > 0)      parts.push(t('utility.balance.breakdownBonus', { value: fmt.eur(bonus) }));
        return parts.length > 1 ? `<div class="balance-col__breakdown">${parts.join(' ')}</div>` : '';
      })();

  // F1003 — Sonderzahlungen unter dem Abschlag ausweisen (nur wenn welche
  // erfasst sind). Vorzeichen-Konvention: Rückzahlung erhöht den Saldo
  // (Überzahlung wird ausgeglichen), Nach-/Abschlagszahlung senkt ihn.
  const spCount = c.special_payments_count || 0;
  const spParts = [];
  if ((c.special_refund_total || 0) > 0)    spParts.push(t('utility.balance.specialRefund', { value: fmt.eur(c.special_refund_total) }));
  if ((c.special_surcharge_total || 0) > 0) spParts.push(t('utility.balance.specialSurcharge', { value: fmt.eur(c.special_surcharge_total) }));
  if ((c.special_advance_total || 0) > 0)   spParts.push(t('utility.balance.specialAdvance', { value: fmt.eur(c.special_advance_total) }));
  const specialHtml = spCount > 0
    ? `<div class="balance-col__breakdown">${spParts.join(' ')}</div>`
    : '';

  return `
    <div class="card card--${u.key}">
      <div class="card__title">${t('utility.balance.title', { name: escapeHtml(c.provider || c.tariff_name || '–') })}</div>
      <div class="muted num" style="font-size:11px;margin-bottom:14px">${escapeHtml(tariffText)}</div>
      <div class="balance-grid">
        <div>
          <div class="balance-col__label">${isFeedIn ? t('utility.balance.colConsumedFeedIn') : t('utility.balance.colConsumed')}</div>
          <div class="balance-col__value">${fmt.eur(costToDate)}</div>
          ${breakdownHtml}
          <div class="balance-col__sub">${measuredSub}</div>
        </div>
        <div>
          <div class="balance-col__label">${isFeedIn ? t('utility.balance.colPaidFeedIn') : t('utility.balance.colPaid')}</div>
          <div class="balance-col__value">${isFeedIn ? '–' : fmt.eur(c.advance_paid)}</div>
          <div class="balance-col__sub">${isFeedIn ? t('utility.balance.paidViaGrid') : (c.current_advance_amount != null ? t('utility.balance.paidCurrent', { value: fmt.eur(c.current_advance_amount) }) : t('utility.balance.paidCurrentNone'))}</div>
          ${!isFeedIn && specialHtml ? `<div class="balance-col__sub" style="margin-top:6px">${spCount === 1 ? t('utility.balance.specialCountOne') : t('utility.balance.specialCount', { count: spCount })}</div>${specialHtml}` : ''}
        </div>
        <div>
          <div class="balance-col__label">${isFeedIn ? t('utility.balance.colClaimFeedIn') : t('utility.balance.colBalance')}${info('balance')}</div>
          <!-- v2.13.0 (Review UI-18) — Kundensicht ohne Vorzeichen; die Farbe
               folgt der Bedeutung (bis v2.12 hieß die rote Klasse „positive") -->
          <div class="balance-col__value ${curView?.credit ? 'balance-col__value--credit' : curView?.credit === false ? 'balance-col__value--due' : ''}">${fmt.eur(curView ? curView.amount : cur)}</div>
          <div class="balance-col__sub">${isFeedIn ? (cur > 0 ? t('utility.balance.subCredit') : cur < 0 ? t('utility.balance.subReclaim') : t('utility.balance.subBalanced')) : (cur > 0 ? t('utility.balance.subUnderpaid') : cur < 0 ? t('utility.balance.subOverpaid') : t('utility.balance.subBalanced'))}</div>
        </div>
        <div class="balance-verdict ${verdictCls}">
          <div class="balance-verdict__label">${arrow} ${t('utility.balance.verdictLabel')}</div>
          <div class="balance-verdict__value">${fmt.eur(Math.abs(proj || 0))}</div>
          <div class="balance-verdict__verdict">${t('utility.verdict.' + verdict)}</div>
          <div class="balance-verdict__date">${escapeHtml(dateLabel)}</div>
        </div>
      </div>
      ${suggestHtml}
      ${notesHtml}
      ${isWater && c.components ? renderWaterComponentRow(c.components) : ''}
      ${balancePathHtml(c, u, isFeedIn)}
    </div>
  `;
}

// ── PV-Energiefluss (v2.16.0, Review FE-17, FE-31) ─────────────────
// Wohin der Sonnenstrom geht und was trotzdem aus dem Netz kommt: je Monat
// selbst genutzt + eingespeist (zusammen die Erzeugung) als gestapelte
// Säule, der Netzbezug als Linie. Daten aus GET /api/pv-summary.
function pvFlowHtml(rows, year) {
  const k = (v) => (v == null ? null : fmt.int(v));
  return `
    <div class="card">
      <div class="card__title">☀️ ${escapeHtml(t('utility.pvFlow.title', { year }))}${info('selfConsumption')}</div>
      <div class="chart-wrap h300"><canvas id="pv-flow-chart"></canvas></div>
      <p class="chart-note">${escapeHtml(t('utility.pvFlow.note'))}</p>
      ${chartTableHtml({
        caption: t('utility.pvFlow.title', { year }),
        columns: [t('utility.monthlyTable.colMonth'), t('utility.pvFlow.generation'), t('utility.pvFlow.self'), t('utility.pvFlow.feedIn'), t('utility.pvFlow.grid')],
        rows: rows.map(m => [fmt.month(m.ym), k(m.erzeugung_kwh), k(m.eigenverbrauch_kwh), k(m.einspeisung_kwh), k(m.bezug_kwh)]),
      })}
    </div>`;
}

function drawPvFlowChart(canvasId, rows, year, utilities) {
  const canvas = document.getElementById(canvasId);
  if (_pvChart) { _pvChart.destroy(); _pvChart = null; }
  if (!canvas || !rows.length) return;
  const byKey = Object.fromEntries((utilities || []).map(x => [x.key, x]));
  const gen = byKey.pv_erzeugung || { color: '#fbbf24' };
  const feed = byKey.pv_einspeisung || { color: '#10b981' };
  const grid = byKey.strom || { color: '#22d3ee' };
  const labels = rows.map(m => fmt.month(m.ym));
  // Summen für die Kurzbeschreibung nur über Monate mit Daten aller drei
  // Zähler — sonst ergäben selbst genutzt und eingespeist nicht die Erzeugung
  const covered = rows.filter(m => m.covered && m.eigenverbrauch_kwh != null);
  const sum = (k) => covered.reduce((a, m) => a + (Number(m[k]) || 0), 0);
  _pvChart = makeChart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { type: 'bar', label: t('utility.pvFlow.self'), data: rows.map(m => m.eigenverbrauch_kwh ?? null),
          backgroundColor: utilColor(gen, 0.55), borderColor: utilColor(gen), borderWidth: 1, stack: 'pv', order: 2 },
        { type: 'bar', label: t('utility.pvFlow.feedIn'), data: rows.map(m => m.einspeisung_kwh ?? null),
          backgroundColor: utilColor(feed, 0.55), borderColor: utilColor(feed), borderWidth: 1, stack: 'pv', order: 2 },
        { type: 'line', label: t('utility.pvFlow.grid'), data: rows.map(m => m.bezug_kwh ?? null),
          borderColor: utilColor(grid), backgroundColor: 'transparent', tension: 0.25, pointRadius: 3, stack: 'grid', order: 1 },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { tooltip: { callbacks: {
        label: (item) => `${item.dataset.label}: ${fmt.unit(item.parsed.y, 'kWh', 0)}`,
        footer: (items) => {
          const m = rows[items[0]?.dataIndex];
          return m?.erzeugung_kwh != null ? t('utility.pvFlow.generationTip', { value: fmt.unit(m.erzeugung_kwh, 'kWh', 0) }) : '';
        },
      } } },
      scales: { x: { stacked: true }, y: { stacked: true, title: { display: true, text: 'kWh' } } },
    },
  }, { label: t('utility.pvFlow.alt', { year, months: covered.length,
    generation: fmt.unit(sum('erzeugung_kwh'), 'kWh', 0), self: fmt.unit(sum('eigenverbrauch_kwh'), 'kWh', 0),
    feedIn: fmt.unit(sum('einspeisung_kwh'), 'kWh', 0), grid: fmt.unit(sum('bezug_kwh'), 'kWh', 0) }) });
}

// ── Saldo-Verlauf (v2.16.0, Review FE-31) ──────────────────────────
// Dieselbe Rechnung wie die Karte, als Monatsreihe (`balance_path` aus der
// API): aufsummierte Kosten gegen das Bezahlte. Der Abstand der Linien ist
// der Saldo, der letzte Punkt die erwartete Abrechnung. Beantwortet die
// häufigste Frage — „Muss ich nachzahlen?“ — auf einen Blick.
function balancePath(c, isFeedIn) {
  return !isFeedIn && Array.isArray(c.balance_path) && c.balance_path.length >= 2 ? c.balance_path : null;
}

function balancePathHtml(c, u, isFeedIn) {
  const path = balancePath(c, isFeedIn);
  if (!path) return '';
  const est = ` · ${t('utility.balance.pathEstimated')}`;
  return `
      <div class="balance-path">
        <div class="card__title">${escapeHtml(t('utility.balance.pathTitle'))}</div>
        <div class="chart-wrap h220"><canvas id="balance-chart"></canvas></div>
        <p class="chart-note">${escapeHtml(t('utility.balance.pathNote'))}</p>
        ${chartTableHtml({
          caption: t('utility.balance.pathTitle'),
          columns: [t('utility.monthlyTable.colMonth'), t('utility.balance.pathCost'), t('utility.balance.pathPaid'), t('utility.balance.pathBalance')],
          rows: path.map(p => {
            const v = balanceView(p.balance, u);
            return [fmt.month(p.ym) + (p.estimated ? est : ''), fmt.eur(p.cost), fmt.eur(p.paid), v ? `${v.word} ${fmt.eur(v.amount)}` : fmt.eur(p.balance)];
          }),
        })}
      </div>`;
}

function drawBalanceChart(canvasId, c, u) {
  const canvas = document.getElementById(canvasId);
  if (_balanceChart) { _balanceChart.destroy(); _balanceChart = null; }
  const path = balancePath(c, feedInKind(u));
  if (!canvas || !path) return;
  const labels = path.map(p => fmt.month(p.ym));
  // Geschätzte Monate gestrichelt — bis zur letzten Ablesung ist gemessen
  const dash = (ctx) => (path[ctx.p1DataIndex]?.estimated ? [5, 4] : undefined);
  // Monate nach heute als hohle Punkte
  const point = (color) => (ctx) => (path[ctx.dataIndex]?.future ? 'transparent' : color());
  const last = path[path.length - 1];
  const end = balanceView(last.balance, u);
  _balanceChart = makeChart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: t('utility.balance.pathCost'), data: path.map(p => p.cost), borderColor: utilColor(u), backgroundColor: utilColor(u, 0.12),
          pointBackgroundColor: point(utilColor(u)), pointBorderColor: utilColor(u), segment: { borderDash: dash }, tension: 0.2, pointRadius: 3 },
        { label: t('utility.balance.pathPaid'), data: path.map(p => p.paid), borderColor: tokenColor('accent'), backgroundColor: tokenColor('accent', 0.12),
          pointBackgroundColor: point(tokenColor('accent')), pointBorderColor: tokenColor('accent'), segment: { borderDash: dash }, tension: 0, pointRadius: 3 },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        tooltip: { callbacks: {
          label: (item) => `${item.dataset.label}: ${fmt.eur(item.parsed.y)}`,
          footer: (items) => {
            const p = path[items[0]?.dataIndex];
            const v = p ? balanceView(p.balance, u) : null;
            return v ? `${v.word} ${fmt.eur(v.amount)}${p.estimated ? ` · ${t('utility.balance.pathEstimated')}` : ''}` : '';
          },
        } },
      },
      scales: { y: { beginAtZero: true, title: { display: true, text: getCurrencySymbol() } } },
    },
  }, { label: t('utility.balance.pathAlt', { from: labels[0], to: labels[labels.length - 1],
    result: end ? `${end.word} ${fmt.eur(end.amount)}` : fmt.eur(last.balance) }) });
}

function renderWaterBreakdown(components, bonusTotal) {
  const tw = components.trinkwasser || {};
  const sw = components.schmutzwasser || {};
  const nw = components.niederschlagswasser || {};
  const parts = [];
  if (tw.total > 0) parts.push(t('utility.balance.waterTw', { value: fmt.eur(tw.total) }));
  if (sw.total > 0) parts.push(t('utility.balance.waterSw', { value: fmt.eur(sw.total) }));
  if (nw.total > 0) parts.push(t('utility.balance.waterNw', { value: fmt.eur(nw.total) }));
  if (bonusTotal > 0) parts.push(t('utility.balance.breakdownBonus', { value: fmt.eur(bonusTotal) }));
  return parts.length > 1 ? `<div class="balance-col__breakdown">${parts.join(' ')}</div>` : '';
}

function renderWaterComponentRow(components) {
  const tw = components.trinkwasser || {};
  const sw = components.schmutzwasser || {};
  const nw = components.niederschlagswasser || {};
  return `
    <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border-1)">
      <div class="balance-col__label" style="margin-bottom:10px">${t('utility.balance.components')}</div>
      <div class="grid grid-3">
        <div>
          <div class="num text-1" style="font-size:13px;font-weight:600;color:var(--text-1)">${t('utility.balance.tw')}</div>
          <div class="balance-col__sub" style="margin-top:2px">${tw.current_ct_per_m3 != null ? t('utility.balance.ctPerM3', { value: fmt.num(tw.current_ct_per_m3, 2) }) : '–'}${tw.current_eur_per_month != null ? ' · ' + t('utility.balance.gpMonth', { value: fmt.num(tw.current_eur_per_month, 2) }) : ''}</div>
          <div class="num" style="margin-top:6px;font-size:14px;color:var(--text-1)">${fmt.eur(tw.total)}</div>
          <div class="balance-col__breakdown">${t('utility.balance.consShort', { value: fmt.eur(tw.working_cost) })}${tw.base_cost > 0 ? t('utility.balance.gpPlus', { value: fmt.eur(tw.base_cost) }) : ''}</div>
        </div>
        <div>
          <div class="num text-1" style="font-size:13px;font-weight:600;color:var(--text-1)">${t('utility.balance.sw')}</div>
          <div class="balance-col__sub" style="margin-top:2px">${sw.current_ct_per_m3 != null ? t('utility.balance.ctPerM3', { value: fmt.num(sw.current_ct_per_m3, 2) }) : '–'} · ${t('utility.balance.basisLabel')} ${sw.basis === 'separater_zaehler' ? t('utility.balance.basisSep') : t('utility.balance.basisTw')}</div>
          <div class="num" style="margin-top:6px;font-size:14px;color:var(--text-1)">${fmt.eur(sw.total)}</div>
        </div>
        <div>
          <div class="num text-1" style="font-size:13px;font-weight:600;color:var(--text-1)">${t('utility.balance.nw')}</div>
          <div class="balance-col__sub" style="margin-top:2px">${nw.current_eur_per_m2_year != null ? t('utility.balance.eurPerM2Year', { value: fmt.num(nw.current_eur_per_m2_year, 2), m2: fmt.int(nw.current_versiegelte_m2) }) : '–'}</div>
          <div class="num" style="margin-top:6px;font-size:14px;color:var(--text-1)">${fmt.eur(nw.total)}</div>
          ${nw.current_monthly != null ? `<div class="balance-col__breakdown">${t('utility.balance.perMonthShort', { value: fmt.eur(nw.current_monthly) })}</div>` : ''}
        </div>
      </div>
    </div>
  `;
}

// ── Vertragstabelle ─────────────────────────────────────────────────
function contractsTable(contracts, u) {
  if (!contracts || !contracts.length) {
    return `<div class="empty" style="padding:32px 20px">
      <div class="empty-icon">📑</div>
      <h2>${t('utility.contractsTable.emptyTitle')}</h2>
      <p>${t('utility.contractsTable.emptyText')}</p>
      <a class="btn btn--primary" href="#/utility/${u.key}/contracts">${t('utility.contractsTable.emptyCta')}</a>
    </div>`;
  }
  // v2.13.0 (Review UI-18) — Vorzeichen aus Kundensicht (+ = Guthaben), bei
  // der Einspeisung ist ein Anspruch grün (bis v2.12 rot)
  const balCell = (v, bold = false) => {
    const bv = balanceView(v, u);
    return bv ? `<td class="num ${bv.cls}"${bold ? ' style="font-weight:600"' : ''}>${bv.signed}</td>` : '<td class="num muted">–</td>';
  };
  // v2.5.1 — Spalte „Sonderzahlungen": nur dort, wo das Backend Einzelposten
  // liefert (Gas/Strom/Fernwärme). Wasser und Einspeisung kennen keine
  // Sonderzahlungen; bei ihnen fehlt das Feld, und die Spalte entfällt.
  const showSpecial = contracts.some(c => Array.isArray(c.special_payments));
  return `<div class="table-wrap"><table class="table contracts-table">
    <thead><tr>
      <th scope="col">${t('utility.contractsTable.colProvider')}</th>
      <th scope="col">${t('utility.contractsTable.colPeriod')}</th>
      <th scope="col">${t('utility.contractsTable.colStatus')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colTariff')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colAdvance')}</th>
      <!-- v2.13.0 — bei der Einspeisung zahlt der Netzbetreiber: verdient und erhalten, nicht verbraucht und bezahlt -->
      <th scope="col" class="num">${t(feedInKind(u) ? 'utility.contractsTable.colEarned' : 'utility.contractsTable.colConsumed')}</th>
      <th scope="col" class="num">${t(feedInKind(u) ? 'utility.contractsTable.colReceived' : 'utility.contractsTable.colPaid')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colBonus')}</th>
      ${showSpecial ? `<th scope="col" class="num special-col">${t('utility.contractsTable.colSpecial')}${info('specialPayment')}</th>` : ''}
      <th scope="col" class="num">${t('utility.contractsTable.colBalanceToday')}${info('balance')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colBalanceExpected')}</th>
    </tr></thead>
    <tbody>
    ${contracts.map(c => {
      const stateCls = c.is_current ? 'active' : c.is_past ? 'past' : 'future';
      const stateLabel = c.renewed ? t('utility.contractsTable.stateRenewed') : c.is_current ? t('utility.contractsTable.stateActive') : c.is_past ? t('utility.contractsTable.statePast') : t('utility.contractsTable.stateFuture');
      const cur = c.current_balance, proj = c.projected_end_balance;
      const period = c.is_open_ended
        ? t('utility.contractsTable.periodOpen', { start: fmt.date(c.start) })
        : `${fmt.date(c.start)} → ${fmt.date(c.end)}`;
      const tariffParts = [];
      if (c.current_working_price_ct != null) tariffParts.push(fmt.num(c.current_working_price_ct, 4) + ' ' + getCurrencyMinor());
      // v2.13.0 — ausgeschrieben statt „GP“, auf den Cent statt gerundet
      if (c.current_base_price_eur   != null) tariffParts.push(t('utility.contractsTable.gpSuffix', { value: fmt.num(c.current_base_price_eur, 2) }));
      // Je Preis eine Zeile, ohne Umbruch darin (sonst „Grundpreis 11,90 / €/Monat“)
      const tariffStr = tariffParts.length ? tariffParts.map(p => `<span class="tariff-part">${p}</span>`).join('<br>') : '–';
      const bonusStr = c.actual_bonus_total > 0 ? fmt.eur(c.actual_bonus_total) : '–';
      return `<tr>
        <td class="provider-cell">${escapeHtml(c.provider || '–')}${c.tariff_name ? `<small>${escapeHtml(c.tariff_name)}</small>` : ''}</td>
        <td class="period-cell">${period}</td>
        <td><span class="status-pill ${stateCls}">${stateLabel}</span></td>
        <td class="num muted" style="font-size:11px">${tariffStr}</td>
        <td class="num">${c.current_advance_amount != null ? fmt.eur(c.current_advance_amount) : '–'}</td>
        <td class="num">${fmt.eur(c.cost_to_date ?? c.actual_cost)}</td>
        <td class="num">${fmt.eur(c.advance_paid)}</td>
        <td class="num success-text">${bonusStr}</td>
        ${showSpecial ? specialCell(c) : ''}
        ${balCell(cur)}
        ${balCell(proj, true)}
      </tr>`;
    }).join('')}
    </tbody>
  </table></div>
  <p class="muted" style="font-size:11px;margin:8px 4px 0">${t(feedInKind(u) ? 'saldo.tableLegendFeedIn' : 'saldo.tableLegend')}${showSpecial ? ' ' + t('utility.contractsTable.hint') : ''}</p>`;
}

// v2.5.1 — Zelle „Sonderzahlungen": Netto aus Kundensicht. Erhalten (Rück-
// zahlung) zählt positiv, gezahlt (Nach-/Abschlagszahlung) negativ — das ist
// dieselbe Größe, die der Saldo als `special_payment_net` addiert.
// v2.13.0 (Review UI-17) — die Einzelposten zum Aufklappen statt im Tooltip,
// den es auf dem iPhone nicht gibt.
function specialCell(c) {
  const items = Array.isArray(c.special_payments) ? c.special_payments : [];
  if (!items.length) return `<td class="num muted special-cell">–</td>`;
  const net = c.special_payment_net || 0;
  const cls = net > 0 ? 'success-text' : net < 0 ? 'danger-text' : 'muted';
  const sign = net > 0 ? '+' : '';
  const lines = items.map(sp => {
    const received = String(sp.kind).startsWith('rueckzahlung');
    const kind = t(`contracts.kinds.${sp.kind}`);
    const line = `${fmt.date(sp.date)} · ${kind} · ${received ? '+' : '−'}${fmt.eur(sp.amount_eur)}`;
    return sp.note ? `${line} (${sp.note})` : line;
  });
  const count = items.length > 1 ? ` <small class="muted">(${items.length})</small>` : '';
  return `<td class="num ${cls} special-cell">
    <details class="special-details">
      <summary>${sign}${fmt.eur(net)}${count}</summary>
      <ul class="special-details__list">${lines.map(l => `<li>${escapeHtml(l)}</li>`).join('')}</ul>
    </details>
  </td>`;
}

// ── Monatstabelle ───────────────────────────────────────────────────
function monthlyTable(monthly, u, hasContracts) {
  if (!monthly.length) return `<p class="muted" style="padding:16px">${t('utility.monthlyTable.noData')}</p>`;
  const isGas = usesGasFactors(u);
  // v2.13.0 (Review UI-19) — PV: kein Wetterbezug, Einspeisung als Erlös
  // ohne Abschlagsspalten (dort stand nur „–"), Erzeugung ganz ohne Kosten;
  // CO₂ ist bei PV vermieden und steht mit Minus wie in der Kachel
  const pv = isPv(u);
  const feedIn = feedInKind(u);
  const generation = generationKind(u);
  const showCost = !generation;
  const showBalance = hasContracts && !feedIn;
  // v2.16.0 (Review FE-19) — witterungsbereinigt nach dem Heizmodell, bis
  // dahin nur im PDF; die Spalte steht, wo es solche Werte gibt
  const adj = !!u.hgt_relevant && monthly.some(m => m.heat_adjusted != null);
  // v1.6.1 — Fix #14: Verbrauchs-Feldname je nach Utility. Wasser/
  // m³-native Utilities tragen den Wert im Feld `m3`; das `kwh`-
  // Feld ist nach applyUtilityFields leer. Vorher las die m³-Spalte
  // fälschlich `m.kwh` → komplette Wasser-Monatstabelle zeigte 0.
  const consKey = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  const sign = v => v > 0 ? '+' : '';

  const tot = monthly.reduce((s, m) => ({
    days: s.days + (m.days || 0),
    kwh:  s.kwh  + (m.kwh  || 0),
    m3:   s.m3   + (m.m3   || 0),
    cost: s.cost + (m.cost || 0),
    co2:  s.co2  + (m.co2_kg || 0),
    adv:  s.adv  + (m.advance_eur || 0),
  }), { days: 0, kwh: 0, m3: 0, cost: 0, co2: 0, adv: 0 });
  const yearBal = tot.cost - tot.adv;

  return `<div class="table-wrap"><table class="table">
    <thead><tr>
      <th scope="col">${t('utility.monthlyTable.colMonth')}</th>
      <th scope="col" class="num">${t('utility.monthlyTable.colDays')}</th>
      ${isGas ? '<th scope="col" class="num">m³</th>' : ''}
      <th scope="col" class="num">${u.consumption_unit}</th>
      ${adj ? `<th scope="col" class="num">${t('utility.monthlyTable.colAdjusted')}${info('weatherAdjusted')}</th>` : ''}
      <th scope="col" class="num">${t('utility.monthlyTable.colPerDay', { unit: u.consumption_unit })}</th>
      ${pv ? '' : `<th scope="col" class="num" title="${t('utility.monthlyTable.colTempTitle')}">${t('utility.monthlyTable.colTemp')}</th>`}
      ${pv ? '' : `<th scope="col" class="num">${t('utility.monthlyTable.colHdd')}${info('hdd')}</th>`}
      ${showCost ? `<th scope="col" class="num">${t('utility.monthlyTable.colPrice', { minor: getCurrencyMinor(), unit: u.consumption_unit })}</th>` : ''}
      ${showCost ? `<th scope="col" class="num">${t(feedIn ? 'utility.monthlyTable.colRevenue' : 'utility.monthlyTable.colCost')}</th>` : ''}
      ${showBalance ? `<th scope="col" class="num col-yellow">${t('utility.monthlyTable.colAdvance')}</th>` : ''}
      ${showBalance ? `<th scope="col" class="num">${t('utility.monthlyTable.colCumBalance')}${info('balance')}</th>` : ''}
      <th scope="col" class="num col-violet">${t(pv ? 'utility.monthlyTable.colCo2Avoided' : 'utility.monthlyTable.colCo2')}</th>
      <th scope="col" class="num col-blue">${t('utility.monthlyTable.colMa3')}${info('movingAverage')}</th>
      <th scope="col" class="num col-yellow">${t('utility.monthlyTable.colMa6')}</th>
    </tr></thead>
    <tbody>
    ${monthly.map(m => {
      const cumView = balanceView(m.cumulative_balance, u, (n) => fmt.num(n, 2));
      // v2.10.0 — Tankbuch: Monate mit geschätzten Tagen (nach dem letzten bekannten Bestand)
      const est = m.estimated_days > 0
        ? ` <span class="muted" title="${escapeHtml(t('utility.monthlyTable.estimatedTitle', { days: m.estimated_days }))}">≈</span>` : '';
      // v2.15.0 (Review FE-08) — Teilmonat mit erfassten Tagen, sichtbar statt im Tooltip
      const part = isPartial(m);
      return `<tr${part ? ' class="is-partial"' : ''}>
        <td><strong>${fmt.month(m.ym)}</strong>${est}</td>
        <td class="num">${part ? `${m.days} / ${daysInMonth(m.ym)}` : (m.days || 0)}</td>
        ${isGas ? `<td class="num">${fmt.int(m.m3)}</td>` : ''}
        <td class="num"><strong>${fmt.int(m[consKey])}</strong></td>
        ${adj ? `<td class="num">${m.heat_adjusted != null ? fmt.int(m.heat_adjusted) : '–'}</td>` : ''}
        <td class="num">${fmt.num(m.kwh_per_day, 1)}</td>
        ${pv ? '' : `<td class="num">${m.avg_temp != null ? fmt.num(m.avg_temp, 1) : '–'}</td>`}
        ${pv ? '' : `<td class="num">${m.hdd != null ? fmt.int(m.hdd) : '–'}</td>`}
        ${showCost ? `<td class="num">${m.working_price_ct != null ? fmt.num(m.working_price_ct, 4) : '–'}</td>` : ''}
        ${showCost ? `<td class="num"><strong>${fmt.num(m.cost, 2)}</strong></td>` : ''}
        ${showBalance ? `<td class="num col-yellow">${m.advance_eur != null ? fmt.num(m.advance_eur, 2) : '–'}</td>` : ''}
        ${showBalance ? `<td class="num ${cumView ? cumView.cls : 'muted'}" style="font-weight:500">${cumView ? cumView.signed : '–'}</td>` : ''}
        <td class="num col-violet">${fmt.int(m.co2_kg)}</td>
        <td class="num col-blue">${m.ma3 != null ? fmt.int(m.ma3) : '–'}</td>
        <td class="num col-yellow">${m.ma6 != null ? fmt.int(m.ma6) : '–'}</td>
      </tr>`;
    }).join('')}
    </tbody>
    <tfoot><tr>
      <td>${t('utility.monthlyTable.total')}</td>
      <td class="num">${tot.days}</td>
      ${isGas ? `<td class="num">${fmt.int(tot.m3)}</td>` : ''}
      <td class="num">${fmt.int(tot[consKey])}</td>
      ${adj ? `<td class="num">${monthly.every(m => m.heat_adjusted != null) ? fmt.int(monthly.reduce((a, m) => a + m.heat_adjusted, 0)) : '–'}</td>` : ''}
      <td></td>${pv ? '' : '<td></td><td></td>'}${showCost ? '<td></td>' : ''}
      ${showCost ? `<td class="num">${fmt.num(tot.cost, 2)}</td>` : ''}
      ${showBalance ? `<td class="num col-yellow">${fmt.num(tot.adv, 2)}</td>` : ''}
      ${showBalance ? (() => { const yb = balanceView(yearBal, u, (n) => fmt.num(n, 2)); return `<td class="num ${yb ? yb.cls : 'muted'}" style="font-weight:600">${yb ? yb.signed : '–'}</td>`; })() : ''}
      <td class="num">${fmt.int(tot.co2)}</td>
      <td></td><td></td>
    </tr></tfoot>
  </table></div>
  ${showBalance ? `<p class="muted" style="font-size:12px;margin-top:8px">${t('saldo.tableLegend')}</p>` : ''}
  ${monthly.some(m => m.estimated_days > 0) ? `<p class="muted" style="font-size:12px;margin-top:8px">${t('utility.monthlyTable.estimatedLegend')}</p>` : ''}
  ${monthly.some(isPartial) ? `<p class="muted" style="font-size:12px;margin-top:8px">${escapeHtml(t('utility.monthlyTable.partialLegend'))}</p>` : ''}`;
}

// ── Readings-Tabelle ────────────────────────────────────────────────
// v2.2.0 — auf das gewählte Jahr begrenzt (die Jahres-Pillen steuerten bis
// v2.2.0 nur Chart und Monatstabelle). Mit dem Home-Assistant-Ingest (F1009)
// entstehen tägliche Ablesungen; die ungefilterte Tabelle wuchs auf tausende
// Zeilen und machte die Ansicht unbrauchbar.
// v2.6.0 — Klartext einer Warnung aus ConsumptionService::plausibleReadings()
function warningText(w, u) {
  const base = { date: fmt.date(w.date), counter: fmt.num(w.counter, 1), unit: u.unit };
  if (w.type === 'suspect') return t('utility.warnings.suspect', base);
  if (w.type === 'outlier') return t(w.kind === 'dip' ? 'utility.warnings.dip' : 'utility.warnings.spike', base);
  if (w.type === 'decrease') {
    return t('utility.warnings.decrease', {
      ...base,
      prevDate: fmt.date(w.previous?.date),
      prevCounter: fmt.num(w.previous?.counter, 1),
    });
  }
  return `${base.date}: ${base.counter} ${u.unit}`;
}

function readingsTable(readings, u, year = null, warnByReading = new Map()) {
  if (!readings.length) return `<div class="empty" style="padding:32px"><div class="empty-icon">📋</div><h2>${t('utility.readingsTable.emptyTitle')}</h2></div>`;

  const inYear = year == null
    ? readings
    : readings.filter(r => String(r.date || '').slice(0, 4) === String(year));

  if (!inYear.length) {
    return `<div class="empty" style="padding:32px">
      <div class="empty-icon">📋</div>
      <h2>${t('utility.readingsTable.emptyYear', { year })}</h2>
      <p class="muted">${t('utility.readingsTable.emptyYearHint', { count: readings.length })}</p>
    </div>`;
  }

  const sorted = [...inYear].sort((a, b) => b.date.localeCompare(a.date));
  return `<div class="table-wrap"><table class="table">
    <thead><tr>
      <th scope="col">${t('utility.readingsTable.colDate')}</th>
      <th scope="col" class="num">${t('utility.readingsTable.colCounter')}</th>
      <th scope="col">${t('utility.readingsTable.colNote')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>
    ${sorted.map(r => {
      // v2.6.0 — Verdacht (Home Assistant, fallender Stand) und Ausreißer
      const warn = warnByReading.get(r.id);
      // v2.13.0 (Review UI-27) — der Grund zum Antippen statt nur als Tooltip
      const flag = r.is_suspect
        ? `<span class="status-pill suspect">${t('utility.readingsTable.suspect')}</span>${infoNote(t('utility.readingsTable.suspect'), t('utility.readingsTable.suspectTitle'))}`
        : warn ? `<span class="status-pill implausible">${t('utility.readingsTable.implausible')}</span>${infoNote(t('utility.readingsTable.implausible'), warningText(warn, u))}` : '';
      return `<tr data-reading-id="${escapeHtml(r.id)}"${r.is_suspect || warn ? ' class="row--flagged"' : ''}>
      <td><strong>${fmt.date(r.date)}</strong> ${r.is_future ? `<span class="status-pill future">${t('utility.readingsTable.future')}</span>` : ''} ${r.is_estimated ? `<span class="status-pill" style="background:var(--c-yellow-soft);color:var(--c-yellow)">${t('utility.readingsTable.estimated')}</span>` : ''} ${flag}</td>
      <td class="num">${fmt.num(r.counter, 1)} ${u.unit}</td>
      <td class="muted" style="font-size:12px">${escapeHtml(r.note || '')}</td>
      <td style="text-align:right;white-space:nowrap">
        ${r.is_suspect ? `<button class="icon-btn" data-action="confirm-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.confirmReading')}" aria-label="${t('utility.readingsTable.confirmReading')}"><span aria-hidden="true">✅</span></button>` : ''}
        <button class="icon-btn" data-action="edit-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.edit')}" aria-label="${t('utility.readingsTable.edit')}"><span aria-hidden="true">✏️</span></button>
        <button class="icon-btn" data-action="delete-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.delete')}" aria-label="${t('utility.readingsTable.delete')}"><span aria-hidden="true">🗑️</span></button>
      </td>
    </tr>`;
    }).join('')}
    </tbody>
  </table></div>`;
}

// ── Lieferungen (Heizöl/Pellets) ────────────────────────────────────
function deliveriesTable(deliveries, u) {
  if (!deliveries.length) {
    return `<div class="empty" style="padding:32px"><div class="empty-icon">🚚</div><h2>${t('utility.deliveriesTable.emptyTitle')}</h2><p>${t('utility.deliveriesTable.emptyText')}</p></div>`;
  }
  const unit = u.volume_unit || u.unit || 'L';
  const sorted = [...deliveries].sort((a, b) => b.date.localeCompare(a.date));
  return `<div class="table-wrap"><table class="table">
    <thead><tr>
      <th scope="col">${t('utility.deliveriesTable.colDate')}</th>
      <th scope="col" class="num">${t('utility.deliveriesTable.colQuantity')}</th>
      <th scope="col" class="num">${t('utility.deliveriesTable.colUnitPrice')}</th>
      <th scope="col" class="num">${t('utility.deliveriesTable.colTotal')}</th>
      <th scope="col">${t('utility.deliveriesTable.colSupplier')}</th>
      <th scope="col">${t('utility.deliveriesTable.colNote')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>
    ${sorted.map(d => {
      const qty = Number(d.quantity || 0);
      const upC = d.unit_price_cents != null ? Number(d.unit_price_cents) : null;
      const tot = d.total_eur != null ? Number(d.total_eur)
                  : (upC != null ? qty * upC / 100 : null);
      return `<tr data-delivery-id="${escapeHtml(d.id)}">
        <td><strong>${fmt.date(d.date)}</strong> ${d.is_planned ? `<span class="status-pill future">${t('utility.deliveriesTable.planned')}</span>` : ''} ${d.fill_to_full ? `<span class="status-pill active">${t('utility.deliveriesTable.full')}</span>${infoNote(t('utility.deliveriesTable.full'), t('utility.deliveryModal.fillToFullHint'))}` : ''}</td>
        <td class="num">${fmt.num(qty, 0)} ${unit}</td>
        <td class="num">${upC != null ? fmt.num(upC, 2) + ' ' + getCurrencyMinor() : '–'}</td>
        <td class="num">${tot != null ? fmt.eur(tot) : '–'}</td>
        <td>${escapeHtml(d.supplier || '')}</td>
        <td class="muted" style="font-size:12px">${escapeHtml(d.note || '')}</td>
        <td style="text-align:right;white-space:nowrap">
          <button class="icon-btn" data-action="edit-delivery" data-id="${escapeHtml(d.id)}" title="${t('utility.readingsTable.edit')}" aria-label="${t('utility.readingsTable.edit')}"><span aria-hidden="true">✏️</span></button>
          <button class="icon-btn" data-action="delete-delivery" data-id="${escapeHtml(d.id)}" title="${t('utility.readingsTable.delete')}" aria-label="${t('utility.readingsTable.delete')}"><span aria-hidden="true">🗑️</span></button>
        </td>
      </tr>`;
    }).join('')}
    </tbody>
  </table></div>`;
}

function stockTankSummary(stockHist, u) {
  if (!stockHist || !stockHist.capacity) return '';
  const unit = stockHist.capacity_unit || u.volume_unit || 'L';
  const days = stockHist.days || [];
  const last = days.length ? days[days.length - 1] : null;
  const stock = last ? Number(last.stock || 0) : 0;
  const pct = stockHist.capacity > 0 ? (stock / stockHist.capacity * 100) : 0;
  return t('utility.tank.summary', { stock: fmt.num(stock, 0), unit, cap: fmt.num(stockHist.capacity, 0), pct: pct.toFixed(0) });
}

function stockTankBar(stockHist, u, warnPct) {
  if (!stockHist || !stockHist.capacity) {
    return `<div class="empty" style="padding:24px"><p class="muted">${t('utility.tank.noCapacity')}</p></div>`;
  }
  const unit = stockHist.capacity_unit || u.volume_unit || 'L';
  const days = stockHist.days || [];
  const last = days.length ? days[days.length - 1] : null;
  const stock = last ? Number(last.stock || 0) : 0;
  const cap = Number(stockHist.capacity);
  const pct = cap > 0 ? Math.max(0, Math.min(100, stock / cap * 100)) : 0;
  const barCls = tankLevel(pct, warnPct);
  return `
    <div class="tank-bar-wrap">
      <div class="tank-bar" aria-hidden="true">
        <div class="tank-bar__fill tank-bar__fill--${barCls}" style="width:${pct.toFixed(1)}%"></div>
      </div>
      <div class="tank-bar__legend">
        <span>${t('utility.tank.remaining', { stock: `<strong>${fmt.num(stock, 0)}</strong>`, unit })}</span>
        <span class="muted">${t('utility.tank.ofCap', { pct: pct.toFixed(0), cap: fmt.num(cap, 0), unit })}</span>
      </div>
    </div>`;
}

// ── v2.10.0 — Tankbuch: Herkunft der Zahlen, Warnungen, Peilstände ──
// Die Rechnung kennt den Bestand an Stützstellen (Anfangsbestand, Lieferung
// „bis voll", Peilstand); dazwischen ist der Verbrauch gerechnet, danach
// geschätzt. Die Karte sagt, was davon gilt — und was fehlt.
function tankNotes(stockHist, u) {
  if (!stockHist) return '';
  const unit = stockHist.capacity_unit || u.volume_unit || 'L';
  const anchors = Array.isArray(stockHist.anchors) ? stockHist.anchors : [];
  const known = anchors.filter(a => a.kind !== 'start');
  const notes = [];
  notes.push(known.length
    ? t('utility.tank.noteMeasured', { date: fmt.date(stockHist.estimated_from) })
    : t('utility.tank.noteEstimated'));
  for (const w of Array.isArray(stockHist.warnings) ? stockHist.warnings : []) {
    if (w.code === 'inconsistent_level') {
      notes.push(t('utility.tank.warnInconsistent', { from: fmt.date(w.from), to: fmt.date(w.to), excess: fmt.num(w.excess, 0), unit }));
    } else if (w.code === 'stock_exhausted') {
      notes.push(t('utility.tank.warnExhausted', { date: fmt.date(w.date) }));
    } else if (w.code === 'no_calibration') {
      notes.push(t('utility.tank.warnNoCalibration'));
    } else if (w.code === 'flat_no_temperatures') {
      notes.push(t('utility.tank.warnFlat'));
    }
  }
  return notes.map((n, i) => `<p class="${i === 0 ? 'muted' : 'balance-note'}" style="font-size:12px;margin-top:8px">${escapeHtml(n)}</p>`).join('');
}

function tankLevelsBlock(meter, u) {
  const unit = meter.capacity_unit || u.volume_unit || 'L';
  const levels = [...(Array.isArray(meter.tank_levels) ? meter.tank_levels : [])].sort((a, b) => b.date.localeCompare(a.date));
  const rows = levels.map(l => `
    <tr>
      <td>${fmt.date(l.date)}</td>
      <td class="num" style="white-space:nowrap">${fmt.num(l.level, 0)} ${escapeHtml(unit)}</td>
      <td class="muted">${escapeHtml(l.note || '')}</td>
      <td class="actions"><button class="icon-btn" data-action="delete-level" data-date="${escapeHtml(l.date)}" title="${t('utility.readingsTable.delete')}" aria-label="${t('utility.readingsTable.delete')}"><span aria-hidden="true">🗑️</span></button></td>
    </tr>`).join('');
  return `
    <div class="tank-levels" style="margin-top:16px">
      <div class="card__title" style="font-size:14px">${t('utility.tank.levelsTitle')}
        <span class="card__title-action"><button class="btn btn-${u.key} btn--sm" id="btn-new-level">${t('utility.tank.addLevel')}</button></span>
      </div>
      ${levels.length
        ? `<div class="table-wrap"><table class="table"><tbody>${rows}</tbody></table></div>`
        : `<p class="muted" style="font-size:12px">${t('utility.tank.levelsEmpty')}</p>`}
    </div>`;
}

function drawStockChart(canvasId, stockHist, u, year) {
  const canvas = document.getElementById(canvasId);
  if (_stockChart) { _stockChart.destroy(); _stockChart = null; }
  if (!canvas || !stockHist) return;
  const days = (stockHist.days || []).filter(d => d.date.startsWith(String(year)));
  if (!days.length) return;
  const unit = stockHist.capacity_unit || u.volume_unit || 'L';
  const anchorAt = new Map((stockHist.anchors || []).map(a => [a.date, a]));
  // Gerechnet und geschätzt als zwei Linien; der erste geschätzte Tag hängt
  // auch an der gerechneten Linie, damit kein Loch entsteht.
  const measured  = days.map((d, i) => (!d.estimated || (i > 0 && !days[i - 1].estimated)) ? d.stock : null);
  const estimated = days.map(d => d.estimated ? d.stock : null);
  // Punkte zeigen den bekannten Stand (Beginn des Tages, nach einer
  // Lieferung), nicht den Tagesendwert der Kurve — sonst stünde ein
  // erfasster Peilstand von 1650 L als 1640 L da.
  const anchors   = days.map(d => anchorAt.has(d.date) ? anchorAt.get(d.date).stock : null);
  const labels = days.map(d => d.date);
  const shortMonths = monthShortNames();
  _stockChart = makeChart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: t('utility.tank.chartMeasured', { unit }), data: measured, borderColor: utilColor(u), backgroundColor: 'transparent', pointRadius: 0, borderWidth: 2, spanGaps: false },
        { label: t('utility.tank.chartEstimated', { unit }), data: estimated, borderColor: utilColor(u), backgroundColor: 'transparent', pointRadius: 0, borderWidth: 2, borderDash: [5, 4], spanGaps: false },
        { label: t('utility.tank.chartAnchors'), data: anchors, borderColor: tokenColor('accent'), backgroundColor: tokenColor('accent'), showLine: false, pointRadius: 4 },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        // Beschriftung nur an Monatsersten, auf schmalen Bildschirmen jedes Quartal
        x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0,
          callback: function (v, i) {
            const d = labels[i];
            if (!d || !d.endsWith('-01')) return '';
            const m = Number(d.slice(5, 7));
            const step = (this.chart?.width || 600) < 520 ? 3 : 1;
            return (m - 1) % step === 0 ? shortMonths[m - 1] : '';
          } } },
        y: { min: 0, suggestedMax: Number(stockHist.capacity) || undefined, title: { display: true, text: unit } },
      },
    },
  }, { label: t('utility.tank.chartAlt', { year }) });
}

function wireTankLevels(container, u, meter) {
  container.querySelector('#btn-new-level')?.addEventListener('click', () => openTankLevelModal(container, u, meter));
  container.querySelectorAll('[data-action="delete-level"]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const date = btn.dataset.date;
      const ok = await confirmModal({
        message: t('utility.tank.levelDeleteConfirm', { date: fmt.date(date) }),
        confirmLabel: t('utility.readingsTable.delete'), danger: true,
      });
      if (!ok) return;
      const levels = (meter.tank_levels || []).filter(l => l.date !== date);
      try {
        await api.updateMeter(u.key, meter.id, { tank_levels: levels });
        toastOk(t('utility.tank.levelDeleted'));
        state.meters = await api.meters(u.key);
        rerender(container);
      } catch (e) { toastErr(e.message); }
    });
  });
}

function openTankLevelModal(container, u, meter) {
  const unit = meter.capacity_unit || u.volume_unit || 'L';
  const body = `
    <form id="level-form">
      <div class="field">
        <label for="lf-date">${t('utility.tank.levelDate')}</label>
        <input class="input" id="lf-date" type="date" name="date" value="${escapeHtml(todayIso())}" max="${escapeHtml(todayIso())}" required>
      </div>
      <div class="field">
        <label for="lf-level">${t('utility.tank.levelValue', { unit })}</label>
        <input class="input" id="lf-level" type="text" inputmode="decimal" autocomplete="off" name="level" required aria-describedby="lf-level-hint lf-level-msg">
        <small class="muted" id="lf-level-hint">${t('utility.tank.levelHint')}</small>
        <div class="field-error" id="lf-level-msg" role="alert" hidden></div>
      </div>
      <div class="field">
        <label for="lf-note">${t('utility.tank.levelNote')}</label>
        <input class="input input--text" id="lf-note" type="text" name="note" maxlength="80">
      </div>
    </form>`;
  const footer = `
    <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
    <button type="button" class="btn btn--primary" data-act="save">${t('utility.readingModal.create')}</button>`;
  openModal({
    title: t('utility.tank.addLevel'),
    body, footer,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      const saveBtn = modalEl.querySelector('[data-act="save"]');
      saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
        const form = modalEl.querySelector('#level-form');
        const levelEl = form.level;
        const level = parseDecimal(levelEl.value);
        const cap = Number(meter.capacity) || 0;
        if (level == null || level < 0 || (cap > 0 && level > cap * 1.02)) {
          showFieldError(levelEl, modalEl.querySelector('#lf-level-msg'),
            cap > 0 ? t('utility.tank.levelRange', { cap: fmt.num(cap, 0), unit }) : t('common.invalidNumber', { example: formatForInput(1234.5) }));
          return;
        }
        const date = form.date.value;
        if (!date) return;
        const levels = (meter.tank_levels || []).filter(l => l.date !== date);
        levels.push({ date, level, note: form.note.value.trim() });
        try {
          await api.updateMeter(u.key, meter.id, { tank_levels: levels });
          toastOk(t('utility.tank.levelSaved'));
          close(true);
          state.meters = await api.meters(u.key);
          rerender(container);
        } catch (e) { toastErr(e.message); }
      }));
    },
  });
}

// ── Chart ───────────────────────────────────────────────────────────
// v2.15.0 — Farben folgen dem Theme (Review FE-11); ein Teilmonat steht
// blass da und nennt im Tooltip seine erfassten Tage (FE-08); die
// Kurzbeschreibung nennt Summe, stärksten und schwächsten Monat (FE-20).
// v2.16.0 (Review FE-19, FE-31) — daneben das Vorjahr als Umriss; wahlweise
// witterungsbereinigt nach dem Heizmodell (`heat_adjusted`): Dann zeigt der
// Vergleich, ob gespart wurde — nicht, ob der Winter mild war.
function drawMonthChart(canvasId, monthly, u, year, prevRows = [], mode = 'measured') {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  if (_chart) { _chart.destroy(); _chart = null; }
  // v2.2.0 — Verbrauchs-Feldname utility-abhängig (wie in KPI und Monatstabelle,
  // Fix #14): m³-native Verbrauchsarten tragen den Wert in `m3`, das `kwh`-Feld
  // ist nach applyUtilityFields 0. Vorher las der Chart hart `m.kwh` → das
  // Wasser-Monatschart war eine durchgehende Nullreihe.
  const consKey = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  const adjusted = mode === 'adjusted';
  const valKey = adjusted ? 'heat_adjusted' : consKey;
  const labels = monthly.map(m => fmt.month(m.ym));
  const values = monthly.map(m => m[valKey] ?? null);
  const partial = monthly.map(m => isPartial(m));
  const prevByMonth = new Map(prevRows.map(m => [m.month, m]));
  const prev = monthly.map(m => prevByMonth.get(m.month)?.[valKey] ?? null);
  const hasPrev = prev.some(v => v != null);
  const temp = monthly.map(m => m.avg_temp);
  // v2.13.0 (Review UI-19) — Sonnenstrom hängt nicht an der Temperatur; die
  // bereinigten Werte gelten für ein Normaljahr, die Temperatur gehört dann nicht dazu
  const withTemp = !isPv(u) && !adjusted;
  const barColor = (full, part) => (ctx) => withAlpha(chartColor(u), partial[ctx.dataIndex] ? part : full);
  const unit = u.consumption_unit;

  _chart = makeChart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        ...(hasPrev ? [{
          type: 'bar',
          label: t('utility.chart.prevYear', { year: year - 1 }),
          data: prev,
          backgroundColor: 'transparent',
          borderColor: utilColor(u, 0.6),
          borderWidth: 1.5,
          yAxisID: 'y',
          // `order` legt bei Chart.js auch die Lage im Balkenpaar fest: Vorjahr links
          order: 2,
        }] : []),
        {
          type: 'bar',
          label: adjusted ? t('utility.chart.adjustedLabel', { label: u.label, unit }) : u.label + ' (' + unit + ')',
          data: values,
          backgroundColor: barColor(0.35, 0.12),
          borderColor: barColor(1, 0.5),
          borderWidth: 1,
          yAxisID: 'y',
          order: 3,
        },
        ...(withTemp ? [{
          type: 'line',
          label: t('utility.chart.temp'),
          data: temp,
          borderColor: tokenColor('accent'),
          backgroundColor: 'transparent',
          tension: 0.3,
          pointRadius: 2,
          yAxisID: 'y1',
          order: 1,
        }] : []),
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        tooltip: { callbacks: { footer: (items) => {
          const i = items[0]?.dataIndex;
          if (i == null) return '';
          const m = monthly[i];
          const lines = [];
          if (partial[i]) lines.push(t('chart.partialMonth', { days: m.days, total: daysInMonth(m.ym) }));
          if (adjusted && m[consKey] != null) lines.push(t('utility.chart.measuredValue', { value: fmt.unit(m[consKey], unit, 0) }));
          if (m.device_swap) lines.push(t('utility.chart.deviceSwap'));
          return lines.join('\n');
        } } },
      },
      scales: {
        y: { position: 'left', title: { display: true, text: unit } },
        ...(withTemp ? { y1: { position: 'right', title: { display: true, text: '°C' }, grid: { drawOnChartArea: false } } } : {}),
      },
    }
  }, { label: monthChartLabel(u, year, labels, values, prev, adjusted) });
}

/** Kurzbeschreibung des Monatscharts: Summe, stärkster und schwächster Monat, Vorjahr. */
function monthChartLabel(u, year, labels, values, prev = [], adjusted = false) {
  const s = seriesSummary(labels, values);
  if (!s) return t('utility.chart.alt');
  const unit = (v) => fmt.unit(v, u.consumption_unit, 0);
  let text = t(adjusted ? 'utility.chart.altSummaryAdjusted' : 'utility.chart.altSummary', {
    label: u.label, year, total: unit(s.sum),
    maxMonth: s.max.label, max: unit(s.max.value),
    minMonth: s.min.label, min: unit(s.min.value),
  });
  // Vorjahr über dieselben Monate, soweit vorhanden
  const pairs = values.map((v, i) => [v, prev[i]]).filter(([v, p]) => v != null && p != null);
  if (pairs.length) {
    text += ' ' + t('utility.chart.altPrev', { year: year - 1, total: unit(pairs.reduce((a, [, p]) => a + Number(p), 0)), months: pairs.length });
  }
  return text;
}

/** Hinweis unter dem Monatschart: Teilmonate und, bereinigt, was die Zahlen bedeuten. */
function monthChartNote(rows, mode) {
  const parts = [];
  if (mode === 'adjusted') parts.push(t('utility.chart.adjustedNote'));
  if (rows.some(isPartial)) parts.push(t('chart.partialLegend'));
  return escapeHtml(parts.join(' '));
}

// ── Event wiring ────────────────────────────────────────────────────
function wireEvents(container, u, meter, readings, contracts, deliveries = [], monthly = []) {
  // v2.16.0 — gemessen / witterungsbereinigt: nur das Diagramm neu, nicht die Seite
  container.querySelectorAll('[data-chart-mode]').forEach(b => {
    b.addEventListener('click', () => {
      const mode = b.dataset.chartMode;
      state.chartMode[u.key] = mode;
      container.querySelectorAll('[data-chart-mode]').forEach(x => {
        const on = x.dataset.chartMode === mode;
        x.classList.toggle('active', on);
        x.setAttribute('aria-pressed', String(on));
      });
      const yr = state.yearByUtility[u.key];
      const rows = monthly.filter(m => m.year === yr);
      drawMonthChart('month-chart', rows, u, yr, monthly.filter(m => m.year === yr - 1), mode);
      const note = container.querySelector('[data-role="month-chart-note"]');
      if (note) note.innerHTML = monthChartNote(rows, mode);
    });
  });

  // Year pills
  container.querySelectorAll('.year-pills .pill').forEach(p => {
    p.addEventListener('click', () => {
      state.yearByUtility[u.key] = Number(p.getAttribute('data-year'));
      rerender(container);
    });
  });

  // Meter selector
  container.querySelector('#meter-select')?.addEventListener('change', (e) => {
    state.selectedMeterId = e.target.value;
    rerender(container);
  });

  // Reading actions
  const newReadingHandlers = ['#header-new-reading', '#banner-new-reading', '#btn-new-reading'];
  newReadingHandlers.forEach(sel => {
    container.querySelector(sel)?.addEventListener('click', () => openReadingModal(container, u, meter, null, readings));
  });

  container.querySelectorAll('[data-action="edit-reading"]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-id');
      const reading = readings.find(r => r.id === id);
      openReadingModal(container, u, meter, reading, readings);
    });
  });
  // v2.6.0 — Verdacht aus Home Assistant bestätigen: Der Stand zählt wieder.
  container.querySelectorAll('[data-action="confirm-reading"]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        await api.updateReading(u.key, btn.getAttribute('data-id'), { is_suspect: false });
        toastOk(t('utility.toast.readingConfirmed'));
        rerender(container);
      } catch (e) { toastErr(e.message); }
    });
  });
  container.querySelectorAll('[data-action="delete-reading"]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      const reading = readings.find(r => r.id === id);
      const ok = await confirmModal({
        message: t('utility.confirm.deleteReading', { date: fmt.date(reading?.date) }),
        confirmLabel: t('utility.readingsTable.delete'), danger: true,
      });
      if (!ok) return;
      try { await api.deleteReading(u.key, id); toastOk(t('utility.toast.readingDeleted')); rerender(container); }
      catch (e) { toastErr(e.message); }
    });
  });

  // Delivery actions (Heizöl/Pellets)
  ['#btn-new-delivery', '#header-new-delivery'].forEach(sel => {
    container.querySelector(sel)?.addEventListener('click', () => openDeliveryModal(container, u, meter, null));
  });
  container.querySelectorAll('[data-action="edit-delivery"]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-id');
      const d = deliveries.find(x => x.id === id);
      openDeliveryModal(container, u, meter, d);
    });
  });
  container.querySelectorAll('[data-action="delete-delivery"]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      const d = deliveries.find(x => x.id === id);
      const ok = await confirmModal({
        message: t('utility.confirm.deleteDelivery', { date: fmt.date(d?.date) }),
        confirmLabel: t('utility.readingsTable.delete'), danger: true,
      });
      if (!ok) return;
      try { await api.deleteDelivery(u.key, id); toastOk(t('utility.toast.deliveryDeleted')); rerender(container); }
      catch (e) { toastErr(e.message); }
    });
  });

  // Contract action
  container.querySelector('#btn-new-contract')?.addEventListener('click', () => {
    location.hash = `#/utility/${u.key}/contracts`;
  });
}

// ── Reading modal (add / edit) ──────────────────────────────────────
function openReadingModal(container, u, meter, reading, readings = []) {
  const isEdit = !!reading;
  const today = todayIso();
  // v2.6.0 — Grundlage der Plausibilitätsprüfung: alle anderen Stände dieses
  // Zählers (ohne den bearbeiteten und ohne unbestätigten Verdacht).
  const others = (readings || []).filter(r => r.id !== reading?.id && !r.is_suspect);
  const typical = typicalPerDay(others);
  const prevBefore = (date) => others
    .filter(r => !r.is_future && r.date < date)
    .sort((a, b) => a.date.localeCompare(b.date))
    .at(-1) || null;
  const lastReading = isEdit ? null : prevBefore('9999-12-31');
  const body = `
    <form id="reading-form">
      <div class="field">
        <label for="rf-date">${t('utility.readingModal.date')}</label>
        <input class="input" id="rf-date" type="date" name="date" value="${escapeHtml(reading?.date || today)}" required>
      </div>
      <div class="field">
        <label for="rf-counter">${t('utility.readingModal.counter', { unit: u.unit })}</label>
        <input class="input" id="rf-counter" type="text" inputmode="decimal" autocomplete="off" name="counter" value="${escapeHtml(formatForInput(reading?.counter))}" required aria-describedby="rf-counter-msg">
        <div class="reading-card__preview" data-role="preview" hidden></div>
        <div class="field-error" id="rf-counter-msg" data-role="msg" role="alert" hidden></div>
      </div>
      <div class="field">
        <label for="rf-note">${t('utility.readingModal.note')}</label>
        <input class="input input--text" id="rf-note" type="text" name="note" value="${escapeHtml(reading?.note || '')}">
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0">
          <input type="checkbox" name="is_estimated" ${reading?.is_estimated ? 'checked' : ''}>
          <span>${t('utility.readingModal.estimated')}</span>
        </label>
      </div>
    </form>
  `;
  const footer = `
    <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
    <button type="button" class="btn btn--primary" data-act="save">${isEdit ? t('utility.readingModal.save') : t('utility.readingModal.create')}</button>
  `;
  openModal({
    title: isEdit ? t('utility.readingModal.titleEdit', { label: u.label }) : t('utility.readingModal.titleNew', { label: u.label }),
    body, footer,
    onMount({ modalEl, close }) {
      // B — Verbrauchs-Vorschau beim Eingeben (nur im Anlegen-Modus mit
      // bekanntem letztem Stand).
      const counterEl = modalEl.querySelector('input[name="counter"]');
      const dateEl    = modalEl.querySelector('input[name="date"]');
      const previewEl = modalEl.querySelector('[data-role="preview"]');
      const msgEl     = modalEl.querySelector('[data-role="msg"]');
      const lastCounter = !isEdit && lastReading?.counter != null ? Number(lastReading.counter) : null;
      const updatePreview = () => {
        showFieldError(counterEl, msgEl, null);
        if (!previewEl || lastCounter == null) return;
        const v = parseDecimal(counterEl.value);
        if (v == null) { previewEl.hidden = true; return; }
        const diff = v - lastCounter;
        const delta = (diff < 0 ? '−' : '+') + fmt.num(Math.abs(diff), 2);
        let days = null;
        if (lastReading.date && dateEl.value) {
          const d = Math.round((new Date(dateEl.value) - new Date(lastReading.date)) / 86400000);
          if (Number.isFinite(d) && d > 0) days = d;
        }
        previewEl.hidden = false;
        // v2.6.0 — dieselben Rückfragen wie beim Speichern, schon beim Tippen
        // (Rückgang, ungewöhnlicher Sprung, Komma vergessen, Zukunft).
        const date = dateEl.value || today;
        const prev = prevBefore(date);
        const issues = checkReading({
          value: v, date, today, prev, typical,
          deviceChanged: prev ? deviceChangedBetween(meter, prev.date, date) : false,
        });
        previewEl.textContent = [
          days != null
            ? t('utility.preview.sinceLastDays', { delta, unit: u.unit, days })
            : t('utility.preview.sinceLast', { delta, unit: u.unit }),
          ...issues.map(i => issueText(i, { unit: u.unit, date })),
        ].join(' · ');
        previewEl.classList.toggle('reading-card__preview--warn', issues.length > 0);
      };
      counterEl?.addEventListener('input', updatePreview);
      dateEl?.addEventListener('change', updatePreview);

      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      const saveBtn = modalEl.querySelector('[data-act="save"]');
      saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
        const form = modalEl.querySelector('#reading-form');
        // v2.5.3 — Ein leeres oder unlesbares Feld ist ein Fehler, keine 0
        // (Lektion 24). Vorher machte Number('') daraus einen Zählerstand 0.
        const counter = parseDecimal(form.counter.value);
        if (!form.date.value) { toastErr(t('utility.readingModal.validation')); return; }
        if (counter == null || counter < 0) {
          showFieldError(counterEl, msgEl, t('common.invalidNumber', { example: formatForInput(1234.5) }));
          return;
        }
        // v2.6.0 — Rückfragen vor dem Speichern (lib/plausibility.js). Bis
        // v2.5.3 gab es nur „kleiner als der letzte"; ein Tippfehler nach oben
        // und ein zweiter Stand am selben Tag gingen still durch.
        const date = form.date.value;
        const prev = prevBefore(date);
        // auch ein unbestätigter Verdacht am selben Tag: Der neue Wert ersetzt ihn
        const sameDay = isEdit ? null : ((readings || []).find(r => r.date === date) || null);
        const issues = checkReading({
          value: counter, date, today, prev, typical, sameDay,
          deviceChanged: prev ? deviceChangedBetween(meter, prev.date, date) : false,
        });
        if (issues.length && !await confirmIssues(issues, { unit: u.unit, date, utility: u.key })) return;
        const data = {
          meter_id: meter.id,
          date,
          counter,
          note: form.note.value,
          is_estimated: form.is_estimated.checked,
        };
        try {
          if (isEdit)       await api.updateReading(u.key, reading.id, data);
          else if (sameDay) await api.updateReading(u.key, sameDay.id, data);   // ersetzen statt doppeln
          else              await api.createReading(u.key, data);
          toastOk(isEdit ? t('utility.readingModal.savedEdit') : t('utility.readingModal.savedNew'));
          close(true);
          rerender(container);
        } catch (e) { toastErr(e.message); }
      }));
    },
  });
}

// ── Delivery modal (add / edit) — Heizöl/Pellets ────────────────────
function openDeliveryModal(container, u, meter, delivery) {
  const isEdit = !!delivery;
  const today = todayIso();
  const unit = u.volume_unit || u.unit || 'L';
  const body = `
    <form id="delivery-form">
      <div class="field">
        <label for="df-date">${t('utility.deliveryModal.date')}</label>
        <input class="input" id="df-date" type="date" name="date" value="${escapeHtml(delivery?.date || today)}" required>
      </div>
      <div class="field">
        <label for="df-quantity">${t('utility.deliveryModal.quantity', { unit })}</label>
        <input class="input" id="df-quantity" type="text" inputmode="decimal" autocomplete="off" name="quantity" value="${escapeHtml(formatForInput(delivery?.quantity))}" required aria-describedby="df-quantity-msg">
        <div class="field-error" id="df-quantity-msg" role="alert" hidden></div>
      </div>
      <div class="field">
        <label for="df-unit-price">${t('utility.deliveryModal.unitPrice', { unit })}</label>
        <input class="input" id="df-unit-price" type="text" inputmode="decimal" autocomplete="off" name="unit_price_cents" value="${escapeHtml(formatForInput(delivery?.unit_price_cents))}" aria-describedby="df-unit-price-msg">
        <div class="field-error" id="df-unit-price-msg" role="alert" hidden></div>
      </div>
      <div class="field">
        <label for="df-total">${t('utility.deliveryModal.total')}</label>
        <input class="input" id="df-total" type="text" inputmode="decimal" autocomplete="off" name="total_eur" value="${escapeHtml(formatForInput(delivery?.total_eur))}" aria-describedby="df-total-msg">
        <div class="field-error" id="df-total-msg" role="alert" hidden></div>
      </div>
      <div class="field">
        <label for="df-supplier">${t('utility.deliveryModal.supplier')}</label>
        <input class="input input--text" id="df-supplier" type="text" name="supplier" value="${escapeHtml(delivery?.supplier || '')}">
      </div>
      <div class="field">
        <label for="df-note">${t('utility.deliveryModal.note')}</label>
        <input class="input input--text" id="df-note" type="text" name="note" value="${escapeHtml(delivery?.note || '')}">
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0">
          <input type="checkbox" name="fill_to_full" ${delivery?.fill_to_full ? 'checked' : ''} aria-describedby="df-full-hint">
          <span>${t('utility.deliveryModal.fillToFull')}</span>
        </label>
        <small class="muted" id="df-full-hint">${t('utility.deliveryModal.fillToFullHint')}</small>
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0">
          <input type="checkbox" name="is_planned" ${delivery?.is_planned ? 'checked' : ''}>
          <span>${t('utility.deliveryModal.planned')}</span>
        </label>
      </div>
    </form>
  `;
  const footer = `
    <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
    <button type="button" class="btn btn--primary" data-act="save">${isEdit ? t('utility.readingModal.save') : t('utility.readingModal.create')}</button>
  `;
  openModal({
    title: isEdit ? t('utility.deliveryModal.titleEdit', { label: u.label }) : t('utility.deliveryModal.titleNew', { label: u.label }),
    body, footer,
    onMount({ modalEl, close }) {
      // C (v2.1.4) — Gesamtbetrag ↔ Menge × Stückpreis live verknüpfen, damit
      // sich die drei Felder nicht widersprechen. Programmatisches .value setzen
      // feuert kein 'input'-Event → keine Endlosschleife.
      const qEl = modalEl.querySelector('input[name="quantity"]');
      const uEl = modalEl.querySelector('input[name="unit_price_cents"]');
      const tEl = modalEl.querySelector('input[name="total_eur"]');
      const numOf = (el) => parseDecimal(el?.value);
      const recalcTotal = () => { const q = numOf(qEl), u = numOf(uEl); if (q != null && u != null) tEl.value = formatForInput(Math.round(q * u) / 100, 2); };
      const recalcUnit  = () => { const q = numOf(qEl), tot = numOf(tEl); if (q != null && q !== 0 && tot != null) uEl.value = formatForInput(Math.round(tot / q * 10000) / 100, 2); };
      qEl?.addEventListener('input', () => { if (numOf(uEl) != null) recalcTotal(); else recalcUnit(); });
      uEl?.addEventListener('input', recalcTotal);
      tEl?.addEventListener('input', recalcUnit);

      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      const saveBtn = modalEl.querySelector('[data-act="save"]');
      saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
        const form = modalEl.querySelector('#delivery-form');
        const qty = parseDecimal(form.quantity.value);
        if (!form.date.value) { toastErr(t('utility.deliveryModal.validation')); return; }
        if (qty == null || qty <= 0) {
          showFieldError(qEl, modalEl.querySelector('#df-quantity-msg'), t('utility.deliveryModal.validation'));
          return;
        }
        const data = {
          meter_id: meter.id,
          date: form.date.value,
          quantity: qty,
          supplier: form.supplier.value.trim(),
          note: form.note.value.trim(),
          is_planned: form.is_planned.checked,
          fill_to_full: form.fill_to_full.checked,   // v2.10.0 — Tankbuch-Stützstelle
        };
        // Optionale Felder: leer bleibt leer; Text, der keine Zahl ist, ist ein Fehler.
        for (const [el, key, msgId] of [[uEl, 'unit_price_cents', '#df-unit-price-msg'], [tEl, 'total_eur', '#df-total-msg']]) {
          if (String(el.value).trim() === '') continue;
          const v = parseDecimal(el.value);
          if (v == null || v < 0) {
            showFieldError(el, modalEl.querySelector(msgId), t('common.invalidNumber', { example: formatForInput(1234.5) }));
            return;
          }
          data[key] = v;
        }
        try {
          if (isEdit) await api.updateDelivery(u.key, delivery.id, data);
          else        await api.createDelivery(u.key, data);
          toastOk(isEdit ? t('utility.deliveryModal.savedEdit') : t('utility.deliveryModal.savedNew'));
          close(true);
          rerender(container);
        } catch (e) { toastErr(e.message); }
      }));
    },
  });
}
