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
import { getUtilities } from '../state.js';
import { fmt, escapeHtml, todayIso } from '../lib/format.js';
import { makeChart, themeColors } from '../components/chart.js';
import { openModal, confirmModal } from '../components/modal.js';
import { toastOk, toastErr } from '../components/toast.js';
import { t } from '../lib/i18n.js';

let _chart = null;
const state = {
  utility: null,
  meters: [],
  selectedMeterId: null,
  selectedYear: null,
};

export async function render(container, params) {
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
    if (!state.selectedMeterId || !meters.find(m => m.id === state.selectedMeterId)) {
      state.selectedMeterId = meters[0]?.id || null;
    }
    await rerender(container);
  } catch (e) {
    container.innerHTML = `<div class="status-banner alert"><div class="status-banner__icon">⚠️</div>
      <div class="status-banner__text">${escapeHtml(e.message)}</div></div>`;
  }

  return () => { if (_chart) { _chart.destroy(); _chart = null; } };
}

async function rerender(container) {
  const u = state.utility;

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
    container.innerHTML = `<div class="status-banner alert"><div class="status-banner__icon">⚠️</div>
      <div class="status-banner__text">${escapeHtml(e.message)}</div></div>`;
    return;
  }

  const monthly   = consumptionData.monthly   || [];
  const contracts = contractStatusData.contracts || [];
  const isDelivery = u.reading_kind === 'delivery';
  // v1.6.1 — Fix #14: Verbrauchs-Feldname utility-abhängig.
  // Wasser/m³-native Utilities tragen den Verbrauch im Feld `m3`,
  // kWh-Utilities im Feld `kwh`. Vorher las das KPI immer `m.kwh`
  // → Wasser-Dashboard zeigte 0.
  const consKey = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  // P-PV-01 — PV-Einspeisung: der „Verbrauch" ist ein Ertrag, die
  // „Kosten" sind ein Vergütungs-Erlös. Labels, Farben und Vorzeichen-
  // Deutung kippen für accounting_kind = feed_in.
  const isFeedIn = u.accounting_kind === 'feed_in';
  let readings = [], deliveries = [], stockHist = null;
  if (isDelivery) {
    deliveries = await api.deliveries(u.key, meter.id).catch(() => []);
    stockHist  = await api.stockHistory(u.key, meter.id).catch(() => null);
  } else {
    readings = await api.readings(u.key, meter.id);
  }

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
    let cls = 'ok', icon = '✓';
    if (days > 60)      { cls = 'alert'; icon = '⚠️'; }
    else if (days > 30) { cls = 'warn';  icon = '⚡'; }
    // Trend: compare last 3 months to previous 3 months.
    // P-PV-01 — Bei PV (feed_in/generation) ist dieser Vergleich reine
    // Saisonalität (Frühling vs. Winter, Sonne ≠ konstant), kein echter
    // Verbrauchstrend → irreführend, daher unterdrückt.
    // C — 3-Monats-Trend als farbiger Pfeil (mehr Verbrauch = rot ▲).
    let trendStr = '';
    if (monthly.length >= 6 && u.accounting_kind !== 'feed_in' && u.accounting_kind !== 'generation') {
      const recent = monthly.slice(-3).reduce((s, m) => s + (m[consKey] || 0), 0);
      const prev   = monthly.slice(-6, -3).reduce((s, m) => s + (m[consKey] || 0), 0);
      if (prev > 0) {
        const pct = ((recent - prev) / prev * 100);
        const up = pct > 0;
        const tone = up ? 'danger' : 'success';
        const arrow = up ? '▲' : '▼';
        trendStr = ` · ${t('utility.banner.trend')} <span class="kpi__trend kpi__trend--${tone}">${arrow} ${fmt.num(Math.abs(pct), 1)} %</span>`;
      }
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

  // ── Years available ─────────────────────────────────────────────
  const years = [...new Set(monthly.map(m => m.year))].sort();
  if (!state.selectedYear || !years.includes(state.selectedYear)) {
    state.selectedYear = years[years.length - 1] || new Date().getFullYear();
  }
  const yr = state.selectedYear;
  const monthlyYear = monthly.filter(m => m.year === yr);

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
  const hasContract = monthlyYear.some(m => m.contract_id);
  const currentContract = contracts.find(c => c.is_current);

  // ── Render whole page ───────────────────────────────────────────
  container.innerHTML = `
    ${header(u, meter)}

    ${statusBannerHtml}

    ${yearPills(years, yr, u.key)}

    <div class="kpi-grid">
      <div class="kpi c-${u.key}">
        <div class="kpi__label">${u.icon} ${isFeedIn ? t('utility.kpi.feedIn') : t('utility.kpi.consumption')} ${yr}</div>
        <div class="kpi__value">${fmt.unit(totUnit, u.consumption_unit, 0)}</div>
        ${u.key === 'gas' ? `<div class="kpi__sub">${fmt.unit(totM3, 'm³', 0)}</div>` : ''}
      </div>
      <div class="kpi c-${u.key}">
        <div class="kpi__label">${isFeedIn ? t('utility.kpi.revenue') : t('utility.kpi.cost')} ${yr}</div>
        <div class="kpi__value ${isFeedIn ? 'positive' : ''}">${fmt.eur(totCost)}</div>
        <div class="kpi__sub">${t('utility.kpi.perMonth', { value: fmt.eur(monthlyYear.length ? totCost / monthlyYear.length : 0) })}</div>
      </div>
      ${hasContract && !isFeedIn ? `
        <div class="kpi c-yellow">
          <div class="kpi__label">${t('utility.kpi.advances', { year: yr })}</div>
          <div class="kpi__value">${fmt.eur(totAdv)}</div>
          <div class="kpi__sub ${yearBalance < 0 ? 'positive' : (yearBalance > 0 ? 'negative' : '')}">
            ${t('utility.kpi.yearBalance', { value: (yearBalance > 0 ? '+' : '') + fmt.eur(yearBalance) })}
          </div>
        </div>
      ` : ''}
      <div class="kpi c-blue">
        <div class="kpi__label">${t('utility.kpi.dailyAvg')}</div>
        <div class="kpi__value">${totDays ? fmt.unit(totUnit / totDays, u.consumption_unit, 1).replace(u.consumption_unit, '') : '–'}
          <span style="font-size:14px;color:var(--text-2)">${u.consumption_unit}</span></div>
        <div class="kpi__sub">${t('utility.kpi.daysCount', { days: totDays })}</div>
      </div>
      ${u.accounting_kind === 'feed_in' ? `
      <div class="kpi c-violet" title="${t('utility.kpi.co2AvoidedTitle')}">
        <div class="kpi__label">${t('utility.kpi.co2Avoided', { year: yr })}</div>
        <div class="kpi__value">−${fmt.int(totCO2)} <span style="font-size:14px;color:var(--text-2)">kg</span></div>
        <div class="kpi__sub">${t('utility.kpi.co2AvoidedSub', { tons: fmt.num(totCO2 / 1000, 2) })}</div>
      </div>
      ` : `
      <div class="kpi c-violet">
        <div class="kpi__label">${t('utility.kpi.co2', { year: yr })}</div>
        <div class="kpi__value">${fmt.int(totCO2)} <span style="font-size:14px;color:var(--text-2)">kg</span></div>
        <div class="kpi__sub">${t('utility.kpi.co2Sub', { tons: fmt.num(totCO2 / 1000, 2) })}</div>
      </div>`}
    </div>

    ${!isDelivery && currentContract ? balanceCard(currentContract, u) : ''}

    ${!isDelivery ? `
    <div class="card">
      <div class="card__title">${t('utility.cards.contracts')}
        <span class="card__title-action">
          <button class="btn btn--ghost btn--sm" id="btn-new-contract" title="${t('utility.cards.manageContractsTitle')}">${t('utility.cards.manageContracts')}</button>
        </span>
      </div>
      ${contractsTable(contracts, u)}
    </div>
    ` : ''}

    <div class="card">
      <div class="card__title">${u.icon} ${t('utility.cards.monthlyChart', { year: yr })}</div>
      <div class="chart-wrap h300"><canvas id="month-chart"></canvas></div>
    </div>

    <div class="card">
      <div class="card__title">${t('utility.cards.monthlyTable', { year: yr })}</div>
      ${monthlyTable(monthlyYear, u, hasContract)}
    </div>

    ${isDelivery ? `
    <div class="card">
      <div class="card__title">${t('utility.cards.stockTank')}
        ${stockHist && stockHist.capacity ? `<span class="card__title-action">
          <span class="muted" style="font-size:12px">${stockTankSummary(stockHist, u)}</span>
        </span>` : ''}
      </div>
      ${stockTankBar(stockHist, u)}
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
      <div class="card__title">${t('utility.cards.readings')}
        <span class="card__title-action">
          <span class="muted" style="font-size:12px;margin-right:8px">${t('utility.cards.readingsCount', { count: readings.length })}</span>
          <button class="btn btn-${u.key} btn--sm" id="btn-new-reading">${t('utility.action.newReading')}</button>
        </span>
      </div>
      ${readingsTable(readings, u)}
    </div>
    `}
  `;

  // Chart
  drawMonthChart('month-chart', monthlyYear, u);

  // Wire up events
  wireEvents(container, u, meter, readings, contracts, deliveries);
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
        <div class="view-header__subtitle">${t('utility.subtitle')}</div>
      </div>
      <div class="view-header__actions">
        ${meterSelectorHtml}
        <a class="btn btn--ghost btn--sm" href="#/utility/${u.key}/meters" title="${t('utility.header.metersTitle')}"><span aria-hidden="true">⚙️</span> ${t('utility.header.meters')}</a>
        <button class="btn btn-${u.key} btn--sm" id="header-new-reading">${t('utility.action.newReading')}</button>
      </div>
    </div>
  `;
}

function yearPills(years, current, utilityKey) {
  if (!years.length) return '';
  return `<div class="year-pills">
    ${years.map(y =>
      `<button class="pill ${y === current ? 'active ' + utilityKey : ''}" data-year="${y}">${y}</button>`
    ).join('')}
  </div>`;
}

// ── Saldo-Karte aktueller Vertrag ───────────────────────────────────
function balanceCard(c, u) {
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
  const sign = v => v > 0 ? '+' : '';
  const dateLabel = isFeedIn
    ? t('utility.balance.dateNext', { date: fmt.date(c.effective_end) })
    : (c.is_open_ended
        ? t('utility.balance.dateEstOpen', { date: fmt.date(c.effective_end) })
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

  // Breakdown row: for water we want three component pills, for gas/strom the
  // simple verbrauch+grundpreis+bonus line.
  const breakdownHtml = isWater && c.components
    ? renderWaterBreakdown(c.components, c.actual_bonus_total)
    : (() => {
        const parts = [];
        if (c.actual_kwh_cost != null)    parts.push(t('utility.balance.breakdownConsumption', { value: fmt.eur(c.actual_kwh_cost) }));
        if (c.actual_base_total > 0)      parts.push(t('utility.balance.breakdownBase', { value: fmt.eur(c.actual_base_total) }));
        if (c.actual_bonus_total > 0)     parts.push(t('utility.balance.breakdownBonus', { value: fmt.eur(c.actual_bonus_total) }));
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
          <div class="balance-col__value">${fmt.eur(c.actual_cost)}</div>
          <div class="balance-col__sub">${consumedSub}</div>
          ${breakdownHtml}
        </div>
        <div>
          <div class="balance-col__label">${isFeedIn ? t('utility.balance.colPaidFeedIn') : t('utility.balance.colPaid')}</div>
          <div class="balance-col__value">${isFeedIn ? '–' : fmt.eur(c.advance_paid)}</div>
          <div class="balance-col__sub">${isFeedIn ? t('utility.balance.paidViaGrid') : (c.current_advance_amount != null ? t('utility.balance.paidCurrent', { value: fmt.eur(c.current_advance_amount) }) : t('utility.balance.paidCurrentNone'))}</div>
          ${!isFeedIn && specialHtml ? `<div class="balance-col__sub" style="margin-top:6px">${spCount === 1 ? t('utility.balance.specialCountOne') : t('utility.balance.specialCount', { count: spCount })}</div>${specialHtml}` : ''}
        </div>
        <div>
          <div class="balance-col__label">${isFeedIn ? t('utility.balance.colClaimFeedIn') : t('utility.balance.colBalance')}</div>
          <div class="balance-col__value ${isFeedIn ? (cur > 0 ? 'negative' : cur < 0 ? 'positive' : '') : (cur > 0 ? 'positive' : cur < 0 ? 'negative' : '')}">${sign(cur)}${fmt.eur(cur)}</div>
          <div class="balance-col__sub">${isFeedIn ? (cur > 0 ? t('utility.balance.subCredit') : cur < 0 ? t('utility.balance.subReclaim') : t('utility.balance.subBalanced')) : (cur > 0 ? t('utility.balance.subUnderpaid') : cur < 0 ? t('utility.balance.subOverpaid') : t('utility.balance.subBalanced'))}</div>
        </div>
        <div class="balance-verdict ${verdictCls}">
          <div class="balance-verdict__label">${arrow} ${t('utility.balance.verdictLabel')}</div>
          <div class="balance-verdict__value">${sign(proj)}${fmt.eur(proj)}</div>
          <div class="balance-verdict__verdict">${t('utility.verdict.' + verdict)}</div>
          <div class="balance-verdict__date">${escapeHtml(dateLabel)}</div>
        </div>
      </div>
      ${isWater && c.components ? renderWaterComponentRow(c.components) : ''}
    </div>
  `;
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
  const sign = v => v > 0 ? '+' : '';
  const balCls = v => v > 0 ? 'danger-text' : v < 0 ? 'success-text' : 'muted';
  return `<div class="table-wrap"><table class="table contracts-table">
    <thead><tr>
      <th scope="col">${t('utility.contractsTable.colProvider')}</th>
      <th scope="col">${t('utility.contractsTable.colPeriod')}</th>
      <th scope="col">${t('utility.contractsTable.colStatus')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colTariff')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colAdvance')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colConsumed')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colPaid')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colBonus')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colBalanceToday')}</th>
      <th scope="col" class="num">${t('utility.contractsTable.colBalanceExpected')}</th>
    </tr></thead>
    <tbody>
    ${contracts.map(c => {
      const stateCls = c.is_current ? 'active' : c.is_past ? 'past' : 'future';
      const stateLabel = c.is_current ? t('utility.contractsTable.stateActive') : c.is_past ? t('utility.contractsTable.statePast') : t('utility.contractsTable.stateFuture');
      const cur = c.current_balance, proj = c.projected_end_balance;
      const period = c.is_open_ended
        ? t('utility.contractsTable.periodOpen', { start: fmt.date(c.start) })
        : `${fmt.date(c.start)} → ${fmt.date(c.end)}`;
      const tariffParts = [];
      if (c.current_working_price_ct != null) tariffParts.push(fmt.num(c.current_working_price_ct, 4) + ' ct');
      if (c.current_base_price_eur   != null) tariffParts.push(t('utility.contractsTable.gpSuffix', { value: fmt.int(c.current_base_price_eur) }));
      const tariffStr = tariffParts.length ? tariffParts.join(' · ') : '–';
      const bonusStr = c.actual_bonus_total > 0 ? fmt.eur(c.actual_bonus_total) : '–';
      return `<tr>
        <td class="provider-cell">${escapeHtml(c.provider || '–')}${c.tariff_name ? `<small>${escapeHtml(c.tariff_name)}</small>` : ''}</td>
        <td class="period-cell">${period}</td>
        <td><span class="status-pill ${stateCls}">${stateLabel}</span></td>
        <td class="num muted" style="font-size:11px">${tariffStr}</td>
        <td class="num">${c.current_advance_amount != null ? fmt.eur(c.current_advance_amount) : '–'}</td>
        <td class="num">${fmt.eur(c.actual_cost)}</td>
        <td class="num">${fmt.eur(c.advance_paid)}</td>
        <td class="num success-text">${bonusStr}</td>
        <td class="num ${balCls(cur)}">${sign(cur)}${fmt.eur(cur)}</td>
        <td class="num ${balCls(proj)}" style="font-weight:600">${sign(proj)}${fmt.eur(proj)}</td>
      </tr>`;
    }).join('')}
    </tbody>
  </table></div>`;
}

// ── Monatstabelle ───────────────────────────────────────────────────
function monthlyTable(monthly, u, hasContracts) {
  if (!monthly.length) return `<p class="muted" style="padding:16px">${t('utility.monthlyTable.noData')}</p>`;
  const isGas = u.key === 'gas';
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
      <th scope="col" class="num">${t('utility.monthlyTable.colPerDay', { unit: u.consumption_unit })}</th>
      <th scope="col" class="num" title="${t('utility.monthlyTable.colTempTitle')}">${t('utility.monthlyTable.colTemp')}</th>
      <th scope="col" class="num" title="${t('utility.monthlyTable.colHddTitle')}">${t('utility.monthlyTable.colHdd')}</th>
      <th scope="col" class="num">ct/${u.consumption_unit === 'kWh' ? 'kWh' : 'm³'}</th>
      <th scope="col" class="num">${t('utility.monthlyTable.colCost')}</th>
      ${hasContracts ? `<th scope="col" class="num col-yellow">${t('utility.monthlyTable.colAdvance')}</th>` : ''}
      ${hasContracts ? `<th scope="col" class="num" title="${t('utility.monthlyTable.colCumBalanceTitle')}">${t('utility.monthlyTable.colCumBalance')}</th>` : ''}
      <th scope="col" class="num col-violet">${t('utility.monthlyTable.colCo2')}</th>
      <th scope="col" class="num col-blue" title="${t('utility.monthlyTable.colMa3Title')}">${t('utility.monthlyTable.colMa3')}</th>
      <th scope="col" class="num col-yellow" title="${t('utility.monthlyTable.colMa6Title')}">${t('utility.monthlyTable.colMa6')}</th>
    </tr></thead>
    <tbody>
    ${monthly.map(m => {
      const cum = m.cumulative_balance;
      const bCls = cum == null ? 'muted' : cum > 0 ? 'danger-text' : cum < 0 ? 'success-text' : 'muted';
      return `<tr>
        <td><strong>${fmt.month(m.ym)}</strong></td>
        <td class="num">${m.days || 0}</td>
        ${isGas ? `<td class="num">${fmt.int(m.m3)}</td>` : ''}
        <td class="num"><strong>${fmt.int(m[consKey])}</strong></td>
        <td class="num">${fmt.num(m.kwh_per_day, 1)}</td>
        <td class="num">${m.avg_temp != null ? fmt.num(m.avg_temp, 1) : '–'}</td>
        <td class="num">${m.hdd != null ? fmt.int(m.hdd) : '–'}</td>
        <td class="num">${m.working_price_ct != null ? fmt.num(m.working_price_ct, 4) : '–'}</td>
        <td class="num"><strong>${fmt.num(m.cost, 2)}</strong></td>
        ${hasContracts ? `<td class="num col-yellow">${m.advance_eur != null ? fmt.num(m.advance_eur, 2) : '–'}</td>` : ''}
        ${hasContracts ? `<td class="num ${bCls}" style="font-weight:500">${cum != null ? sign(cum) + fmt.num(cum, 2) : '–'}</td>` : ''}
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
      <td></td><td></td><td></td><td></td>
      <td class="num">${fmt.num(tot.cost, 2)}</td>
      ${hasContracts ? `<td class="num col-yellow">${fmt.num(tot.adv, 2)}</td>` : ''}
      ${hasContracts ? `<td class="num ${yearBal > 0 ? 'danger-text' : yearBal < 0 ? 'success-text' : 'muted'}" style="font-weight:600">${sign(yearBal)}${fmt.num(yearBal, 2)}</td>` : ''}
      <td class="num">${fmt.int(tot.co2)}</td>
      <td></td><td></td>
    </tr></tfoot>
  </table></div>`;
}

// ── Readings-Tabelle ────────────────────────────────────────────────
function readingsTable(readings, u) {
  if (!readings.length) return `<div class="empty" style="padding:32px"><div class="empty-icon">📋</div><h2>${t('utility.readingsTable.emptyTitle')}</h2></div>`;
  const sorted = [...readings].sort((a, b) => b.date.localeCompare(a.date));
  return `<div class="table-wrap"><table class="table">
    <thead><tr>
      <th scope="col">${t('utility.readingsTable.colDate')}</th>
      <th scope="col" class="num">${t('utility.readingsTable.colCounter')}</th>
      <th scope="col">${t('utility.readingsTable.colNote')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>
    ${sorted.map(r => `<tr data-reading-id="${escapeHtml(r.id)}">
      <td><strong>${fmt.date(r.date)}</strong> ${r.is_future ? `<span class="status-pill future">${t('utility.readingsTable.future')}</span>` : ''} ${r.is_estimated ? `<span class="status-pill" style="background:var(--c-yellow-soft);color:var(--c-yellow)">${t('utility.readingsTable.estimated')}</span>` : ''}</td>
      <td class="num">${fmt.num(r.counter, 1)} ${u.unit}</td>
      <td class="muted" style="font-size:12px">${escapeHtml(r.note || '')}</td>
      <td style="text-align:right;white-space:nowrap">
        <button class="icon-btn" data-action="edit-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.edit')}" aria-label="${t('utility.readingsTable.edit')}"><span aria-hidden="true">✏️</span></button>
        <button class="icon-btn" data-action="delete-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.delete')}" aria-label="${t('utility.readingsTable.delete')}"><span aria-hidden="true">🗑️</span></button>
      </td>
    </tr>`).join('')}
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
        <td><strong>${fmt.date(d.date)}</strong> ${d.is_planned ? `<span class="status-pill future">${t('utility.deliveriesTable.planned')}</span>` : ''}</td>
        <td class="num">${fmt.num(qty, 0)} ${unit}</td>
        <td class="num">${upC != null ? fmt.num(upC, 2) + ' ct' : '–'}</td>
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

function stockTankBar(stockHist, u) {
  if (!stockHist || !stockHist.capacity) {
    return `<div class="empty" style="padding:24px"><p class="muted">${t('utility.tank.noCapacity')}</p></div>`;
  }
  const unit = stockHist.capacity_unit || u.volume_unit || 'L';
  const days = stockHist.days || [];
  const last = days.length ? days[days.length - 1] : null;
  const stock = last ? Number(last.stock || 0) : 0;
  const cap = Number(stockHist.capacity);
  const pct = cap > 0 ? Math.max(0, Math.min(100, stock / cap * 100)) : 0;
  let barCls = 'ok';
  if (pct <= 8)  barCls = 'alert';
  else if (pct <= 15) barCls = 'warn';
  return `
    <div class="tank-bar-wrap">
      <div class="tank-bar" aria-hidden="true">
        <div class="tank-bar__fill tank-bar__fill--${barCls}" style="width:${pct.toFixed(1)}%"></div>
      </div>
      <div class="tank-bar__legend">
        <span>${t('utility.tank.remaining', { stock: `<strong>${fmt.num(stock, 0)}</strong>`, unit })}</span>
        <span class="muted">${t('utility.tank.ofCap', { pct: pct.toFixed(0), cap: fmt.num(cap, 0), unit })}</span>
      </div>
      <p class="muted" style="font-size:12px;margin-top:8px">
        ${t('utility.tank.modelNote')}
      </p>
    </div>`;
}

// ── Chart ───────────────────────────────────────────────────────────
function drawMonthChart(canvasId, monthly, u) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  if (_chart) _chart.destroy();
  const labels = monthly.map(m => fmt.month(m.ym));
  const consumption = monthly.map(m => m.kwh);
  const temp = monthly.map(m => m.avg_temp);

  _chart = makeChart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          type: 'bar',
          label: u.label + ' (' + u.consumption_unit + ')',
          data: consumption,
          backgroundColor: hexToRgba(utilityColor(u.key), 0.3),
          borderColor: utilityColor(u.key),
          borderWidth: 1,
          yAxisID: 'y',
          order: 2,
        },
        {
          type: 'line',
          label: t('utility.chart.temp'),
          data: temp,
          borderColor: themeColors.accent,
          backgroundColor: 'transparent',
          tension: 0.3,
          pointRadius: 2,
          yAxisID: 'y1',
          order: 1,
        },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        y: { position: 'left', title: { display: true, text: u.consumption_unit } },
        y1: { position: 'right', title: { display: true, text: '°C' }, grid: { drawOnChartArea: false } },
      },
    }
  }, { label: t('utility.chart.alt') });
}

function utilityColor(key) {
  return key === 'gas' ? '#ff7b2e' : key === 'strom' ? '#2de8a4' : '#3b82f6';
}
function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

// ── Event wiring ────────────────────────────────────────────────────
function wireEvents(container, u, meter, readings, contracts, deliveries = []) {
  // Year pills
  container.querySelectorAll('.year-pills .pill').forEach(p => {
    p.addEventListener('click', () => {
      state.selectedYear = Number(p.getAttribute('data-year'));
      rerender(container);
    });
  });

  // Meter selector
  container.querySelector('#meter-select')?.addEventListener('change', (e) => {
    state.selectedMeterId = e.target.value;
    rerender(container);
  });

  // Reading actions
  const lastRd = [...readings].filter(r => !r.is_future).sort((a, b) => b.date.localeCompare(a.date))[0] || null;
  const newReadingHandlers = ['#header-new-reading', '#banner-new-reading', '#btn-new-reading'];
  newReadingHandlers.forEach(sel => {
    container.querySelector(sel)?.addEventListener('click', () => openReadingModal(container, u, meter, null, lastRd));
  });

  container.querySelectorAll('[data-action="edit-reading"]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-id');
      const reading = readings.find(r => r.id === id);
      openReadingModal(container, u, meter, reading);
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
  container.querySelector('#btn-new-delivery')?.addEventListener('click',
    () => openDeliveryModal(container, u, meter, null));
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
function openReadingModal(container, u, meter, reading, lastReading = null) {
  const isEdit = !!reading;
  const today = todayIso();
  const body = `
    <form id="reading-form">
      <div class="field">
        <label for="rf-date">${t('utility.readingModal.date')}</label>
        <input class="input" id="rf-date" type="date" name="date" value="${reading?.date || today}" required>
      </div>
      <div class="field">
        <label for="rf-counter">${t('utility.readingModal.counter', { unit: u.unit })}</label>
        <input class="input" id="rf-counter" type="number" step="0.01" name="counter" value="${reading?.counter ?? ''}" required>
        <div class="reading-card__preview" data-role="preview" hidden></div>
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
      const updatePreview = () => {
        if (!previewEl || isEdit || !lastReading || lastReading.counter == null) return;
        const v = parseFloat(String(counterEl.value).replace(',', '.'));
        if (Number.isNaN(v)) { previewEl.hidden = true; return; }
        const diff = v - Number(lastReading.counter);
        const delta = (diff < 0 ? '−' : '+') + fmt.num(Math.abs(diff), 2);
        let days = null;
        if (lastReading.date && dateEl.value) {
          const d = Math.round((new Date(dateEl.value) - new Date(lastReading.date)) / 86400000);
          if (Number.isFinite(d) && d > 0) days = d;
        }
        previewEl.hidden = false;
        previewEl.textContent = days != null
          ? t('utility.preview.sinceLastDays', { delta, unit: u.unit, days })
          : t('utility.preview.sinceLast', { delta, unit: u.unit });
      };
      counterEl?.addEventListener('input', updatePreview);
      dateEl?.addEventListener('change', updatePreview);

      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
        const form = modalEl.querySelector('#reading-form');
        const data = {
          meter_id: meter.id,
          date: form.date.value,
          counter: Number(form.counter.value),
          note: form.note.value,
          is_estimated: form.is_estimated.checked,
        };
        if (!data.date || isNaN(data.counter)) { toastErr(t('utility.readingModal.validation')); return; }
        try {
          if (isEdit) await api.updateReading(u.key, reading.id, data);
          else        await api.createReading(u.key, data);
          toastOk(isEdit ? t('utility.readingModal.savedEdit') : t('utility.readingModal.savedNew'));
          close(true);
          rerender(container);
        } catch (e) { toastErr(e.message); }
      });
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
        <input class="input" id="df-date" type="date" name="date" value="${delivery?.date || today}" required>
      </div>
      <div class="field">
        <label for="df-quantity">${t('utility.deliveryModal.quantity', { unit })}</label>
        <input class="input" id="df-quantity" type="number" step="0.01" name="quantity" value="${delivery?.quantity ?? ''}" required>
      </div>
      <div class="field">
        <label for="df-unit-price">${t('utility.deliveryModal.unitPrice', { unit })}</label>
        <input class="input" id="df-unit-price" type="number" step="0.01" name="unit_price_cents" value="${delivery?.unit_price_cents ?? ''}">
      </div>
      <div class="field">
        <label for="df-total">${t('utility.deliveryModal.total')}</label>
        <input class="input" id="df-total" type="number" step="0.01" name="total_eur" value="${delivery?.total_eur ?? ''}">
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
      const numOf = (el) => { const v = parseFloat(String(el?.value ?? '').replace(',', '.')); return Number.isFinite(v) ? v : null; };
      const recalcTotal = () => { const q = numOf(qEl), u = numOf(uEl); if (q != null && u != null) tEl.value = (q * u / 100).toFixed(2); };
      const recalcUnit  = () => { const q = numOf(qEl), tot = numOf(tEl); if (q != null && q !== 0 && tot != null) uEl.value = (tot / q * 100).toFixed(2); };
      qEl?.addEventListener('input', () => { if (numOf(uEl) != null) recalcTotal(); else recalcUnit(); });
      uEl?.addEventListener('input', recalcTotal);
      tEl?.addEventListener('input', recalcUnit);

      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
        const form = modalEl.querySelector('#delivery-form');
        const qty = Number(form.quantity.value);
        if (!form.date.value || isNaN(qty) || qty <= 0) {
          toastErr(t('utility.deliveryModal.validation')); return;
        }
        const data = {
          meter_id: meter.id,
          date: form.date.value,
          quantity: qty,
          supplier: form.supplier.value.trim(),
          note: form.note.value.trim(),
          is_planned: form.is_planned.checked,
        };
        const upc = form.unit_price_cents.value;
        const tot = form.total_eur.value;
        if (upc !== '') data.unit_price_cents = Number(upc);
        if (tot !== '') data.total_eur = Number(tot);
        try {
          if (isEdit) await api.updateDelivery(u.key, delivery.id, data);
          else        await api.createDelivery(u.key, data);
          toastOk(isEdit ? t('utility.deliveryModal.savedEdit') : t('utility.deliveryModal.savedNew'));
          close(true);
          rerender(container);
        } catch (e) { toastErr(e.message); }
      });
    },
  });
}
