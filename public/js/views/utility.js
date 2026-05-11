// =====================================================================
// Energietracker v1.0.2 — Utility view (Gas/Strom/Wasser)
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
import { openModal } from '../components/modal.js';
import { toastOk, toastErr } from '../components/toast.js';

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
      <div class="status-banner__text">Unbekannte Verbrauchsart: ${escapeHtml(utilityKey)}</div></div>`;
    return;
  }
  state.utility = utility;
  container.setAttribute('data-utility', utility.key);

  container.innerHTML = `<div class="loading">Lade…</div>`;

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
        <h3>Noch keine Zähler</h3>
        <p>Lege einen ${escapeHtml(u.label)}-Zähler über die Zählerverwaltung an.</p>
        <button class="btn btn--primary" id="goto-meters">Zur Zählerverwaltung</button>
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
  const readings  = await api.readings(u.key, meter.id);

  // ── Status banner: how many days since last reading ─────────────
  const sortedReadings = [...readings]
    .filter(r => !r.is_future)
    .sort((a, b) => b.date.localeCompare(a.date));
  const lastReading = sortedReadings[0];
  let statusBannerHtml = '';
  if (lastReading) {
    const today = new Date(todayIso());
    const lastDate = new Date(lastReading.date);
    const days = Math.floor((today - lastDate) / 86400000);
    let cls = 'ok', icon = '✓';
    if (days > 60)      { cls = 'alert'; icon = '⚠️'; }
    else if (days > 30) { cls = 'warn';  icon = '⚡'; }
    // Trend: compare last 3 months to previous 3 months
    let trendStr = '';
    const consKey = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
    if (monthly.length >= 6) {
      const recent = monthly.slice(-3).reduce((s, m) => s + (m[consKey] || 0), 0);
      const prev   = monthly.slice(-6, -3).reduce((s, m) => s + (m[consKey] || 0), 0);
      if (prev > 0) {
        const pct = ((recent - prev) / prev * 100);
        const sign = pct > 0 ? '+' : '';
        trendStr = ` · 3-Monats-Trend ${sign}${pct.toFixed(1)} %`;
      }
    }
    statusBannerHtml = `
      <div class="status-banner ${cls}">
        <div class="status-banner__icon">${icon}</div>
        <div class="status-banner__text">
          <strong>Letzte Ablesung vor ${days} Tagen</strong> · ${fmt.date(lastReading.date)}${trendStr}
        </div>
        <button class="btn btn-${u.key} btn--sm" id="banner-new-reading">+ Neue Ablesung</button>
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
  const totKwh  = monthlyYear.reduce((s, m) => s + (m.kwh || 0), 0);
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
        <div class="kpi__label">${u.icon} Verbrauch ${yr}</div>
        <div class="kpi__value">${fmt.unit(totKwh, u.consumption_unit, 0)}</div>
        ${u.key === 'gas' ? `<div class="kpi__sub">${fmt.unit(totM3, 'm³', 0)}</div>` : ''}
      </div>
      <div class="kpi c-${u.key}">
        <div class="kpi__label">💶 Kosten ${yr}</div>
        <div class="kpi__value">${fmt.eur(totCost)}</div>
        <div class="kpi__sub">ø ${fmt.eur(monthlyYear.length ? totCost / monthlyYear.length : 0)}/Monat</div>
      </div>
      ${hasContract ? `
        <div class="kpi c-yellow">
          <div class="kpi__label">💰 Abschläge ${yr}</div>
          <div class="kpi__value">${fmt.eur(totAdv)}</div>
          <div class="kpi__sub ${yearBalance < 0 ? 'positive' : (yearBalance > 0 ? 'negative' : '')}">
            Saldo Jahr ${yearBalance > 0 ? '+' : ''}${fmt.eur(yearBalance)}
          </div>
        </div>
      ` : ''}
      <div class="kpi c-blue">
        <div class="kpi__label">📅 Tagesschnitt</div>
        <div class="kpi__value">${totDays ? fmt.unit(totKwh / totDays, u.consumption_unit, 1).replace(u.consumption_unit, '') : '–'}
          <span style="font-size:14px;color:var(--text-2)">${u.consumption_unit}</span></div>
        <div class="kpi__sub">${totDays} Tage</div>
      </div>
      <div class="kpi c-violet">
        <div class="kpi__label">🌍 CO₂ ${yr}</div>
        <div class="kpi__value">${fmt.int(totCO2)} <span style="font-size:14px;color:var(--text-2)">kg</span></div>
        <div class="kpi__sub">${fmt.num(totCO2 / 1000, 2)} t</div>
      </div>
    </div>

    ${currentContract ? balanceCard(currentContract, u) : ''}

    <div class="card">
      <div class="card__title">📑 Verträge & Abschläge
        <span class="card__title-action">
          <button class="btn btn--ghost btn--sm" id="btn-new-contract">+ Neuer Vertrag</button>
        </span>
      </div>
      ${contractsTable(contracts, u)}
    </div>

    <div class="card">
      <div class="card__title">${u.icon} Monatsverbrauch ${yr}</div>
      <div class="chart-wrap h300"><canvas id="month-chart"></canvas></div>
    </div>

    <div class="card">
      <div class="card__title">📋 Monatstabelle ${yr}</div>
      ${monthlyTable(monthlyYear, u, hasContract)}
    </div>

    <div class="card">
      <div class="card__title">🔢 Zählerstände
        <span class="card__title-action">
          <span class="muted" style="font-size:12px;margin-right:8px">${readings.length} Ablesungen gesamt</span>
          <button class="btn btn-${u.key} btn--sm" id="btn-new-reading">+ Ablesung</button>
        </span>
      </div>
      ${readingsTable(readings, u)}
    </div>
  `;

  // Chart
  drawMonthChart('month-chart', monthlyYear, u);

  // Wire up events
  wireEvents(container, u, meter, readings, contracts);
}

function header(u, meter = null) {
  const icon = u.icon;
  const meterSelectorHtml = state.meters.length > 1 ? `
    <select class="select" id="meter-select" style="width:auto">
      ${state.meters.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === state.selectedMeterId ? 'selected' : ''}>${escapeHtml(m.name)}</option>`).join('')}
    </select>` : (meter ? `<span class="muted" style="font-size:12px;align-self:center">${escapeHtml(meter.name)}</span>` : '');

  return `
    <div class="view-header">
      <div>
        <h1 class="view-header__title" style="color:var(--util-${u.key})">${icon} ${escapeHtml(u.label)}</h1>
        <div class="view-header__subtitle">Zählerstände · Monatsverbrauch · Verträge & Saldo</div>
      </div>
      <div class="view-header__actions">
        ${meterSelectorHtml}
        <a class="btn btn--ghost btn--sm" href="#/utility/${u.key}/meters" title="Zählerverwaltung">⚙️ Zähler</a>
        <button class="btn btn-${u.key} btn--sm" id="header-new-reading">+ Ablesung</button>
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
  const cur = c.current_balance;
  const proj = c.projected_end_balance;
  const verdict = c.verdict;
  const verdictCls = verdict === 'Erstattung' ? 'refund'
                   : verdict === 'Nachzahlung' ? 'surcharge' : 'balanced';
  const arrow = verdict === 'Nachzahlung' ? '↑' : verdict === 'Erstattung' ? '↓' : '→';
  const sign = v => v > 0 ? '+' : '';
  const dateLabel = c.is_open_ended
    ? `geschätztes Ende: ${fmt.date(c.effective_end)} (offener Vertrag, +12 M)`
    : `Vertragsende: ${fmt.date(c.effective_end)}`;
  const tariffParts = [];
  if (c.current_working_price_ct != null) tariffParts.push(fmt.num(c.current_working_price_ct, 4) + ' ct/kWh');
  if (c.current_base_price_eur   != null) tariffParts.push(fmt.num(c.current_base_price_eur, 2) + ' €/Monat');
  if (c.current_advance_amount   != null) tariffParts.push('Abschlag ' + fmt.eur(c.current_advance_amount));
  const tariffText = tariffParts.length ? tariffParts.join(' · ') : 'keine Tarifdaten';

  const breakdown = [];
  if (c.actual_kwh_cost != null)    breakdown.push(`${fmt.eur(c.actual_kwh_cost)} Verbrauch`);
  if (c.actual_base_total > 0)      breakdown.push(`+ ${fmt.eur(c.actual_base_total)} Grundpreis`);
  if (c.actual_bonus_total > 0)     breakdown.push(`− ${fmt.eur(c.actual_bonus_total)} Bonus`);

  return `
    <div class="card card--${u.key}">
      <div class="card__title">⚖️ Saldo aktueller Vertrag — ${escapeHtml(c.provider || c.tariff_name || '–')}</div>
      <div class="muted num" style="font-size:11px;margin-bottom:14px">${escapeHtml(tariffText)}</div>
      <div class="balance-grid">
        <div>
          <div class="balance-col__label">Verbraucht (Stand heute)</div>
          <div class="balance-col__value">${fmt.eur(c.actual_cost)}</div>
          <div class="balance-col__sub">${c.months_actual} Monate · ${fmt.int(c.actual_kwh)} kWh</div>
          ${breakdown.length > 1 ? `<div class="balance-col__breakdown">${breakdown.join(' ')}</div>` : ''}
        </div>
        <div>
          <div class="balance-col__label">Abschlag bezahlt</div>
          <div class="balance-col__value">${fmt.eur(c.advance_paid)}</div>
          <div class="balance-col__sub">aktuell: ${c.current_advance_amount != null ? fmt.eur(c.current_advance_amount) + '/Monat' : '–'}</div>
        </div>
        <div>
          <div class="balance-col__label">Aktueller Saldo</div>
          <div class="balance-col__value ${cur > 0 ? 'positive' : cur < 0 ? 'negative' : ''}">${sign(cur)}${fmt.eur(cur)}</div>
          <div class="balance-col__sub">${cur > 0 ? 'Unterzahlt' : cur < 0 ? 'Überzahlt' : 'ausgeglichen'}</div>
        </div>
        <div class="balance-verdict ${verdictCls}">
          <div class="balance-verdict__label">${arrow} Erwartet bei Abrechnung</div>
          <div class="balance-verdict__value">${sign(proj)}${fmt.eur(proj)}</div>
          <div class="balance-verdict__verdict">${verdict}</div>
          <div class="balance-verdict__date">${escapeHtml(dateLabel)}</div>
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
      <h3>Keine Verträge erfasst</h3>
      <p>Lege einen Vertrag an, um Abschläge, Tarifpreise, Boni und Saldo zu verfolgen.</p>
    </div>`;
  }
  const sign = v => v > 0 ? '+' : '';
  const balCls = v => v > 0 ? 'danger-text' : v < 0 ? 'success-text' : 'muted';
  return `<div class="table-wrap"><table class="table contracts-table">
    <thead><tr>
      <th>Anbieter / Tarif</th>
      <th>Zeitraum</th>
      <th>Status</th>
      <th class="num">Tarif</th>
      <th class="num">Abschlag</th>
      <th class="num">Verbraucht</th>
      <th class="num">Bezahlt</th>
      <th class="num">Bonus</th>
      <th class="num">Saldo heute</th>
      <th class="num">Erw. Saldo</th>
    </tr></thead>
    <tbody>
    ${contracts.map(c => {
      const stateCls = c.is_current ? 'active' : c.is_past ? 'past' : 'future';
      const stateLabel = c.is_current ? 'AKTIV' : c.is_past ? 'VERGANGEN' : 'ZUKÜNFTIG';
      const cur = c.current_balance, proj = c.projected_end_balance;
      const period = c.is_open_ended
        ? `${fmt.date(c.start)} → laufend`
        : `${fmt.date(c.start)} → ${fmt.date(c.end)}`;
      const tariffParts = [];
      if (c.current_working_price_ct != null) tariffParts.push(fmt.num(c.current_working_price_ct, 4) + ' ct');
      if (c.current_base_price_eur   != null) tariffParts.push(fmt.int(c.current_base_price_eur) + ' € GP');
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
  if (!monthly.length) return '<p class="muted" style="padding:16px">Keine Daten für dieses Jahr.</p>';
  const isGas = u.key === 'gas';
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
      <th>Monat</th>
      <th class="num">Tage</th>
      ${isGas ? '<th class="num">m³</th>' : ''}
      <th class="num">${u.consumption_unit}</th>
      <th class="num">${u.consumption_unit}/Tag</th>
      <th class="num">ø Temp</th>
      <th class="num">HGT</th>
      <th class="num">ct/${u.consumption_unit === 'kWh' ? 'kWh' : 'm³'}</th>
      <th class="num">Kosten €</th>
      ${hasContracts ? '<th class="num col-yellow">Abschlag €</th>' : ''}
      ${hasContracts ? '<th class="num">Saldo (kum.)</th>' : ''}
      <th class="num col-violet">CO₂ kg</th>
      <th class="num col-blue">MA·3</th>
      <th class="num col-yellow">MA·6</th>
    </tr></thead>
    <tbody>
    ${monthly.map(m => {
      const cum = m.cumulative_balance;
      const bCls = cum == null ? 'muted' : cum > 0 ? 'danger-text' : cum < 0 ? 'success-text' : 'muted';
      return `<tr>
        <td><strong>${fmt.month(m.ym)}</strong></td>
        <td class="num">${m.days || 0}</td>
        ${isGas ? `<td class="num">${fmt.int(m.m3)}</td>` : ''}
        <td class="num"><strong>${fmt.int(m.kwh)}</strong></td>
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
      <td>Gesamt</td>
      <td class="num">${tot.days}</td>
      ${isGas ? `<td class="num">${fmt.int(tot.m3)}</td>` : ''}
      <td class="num">${fmt.int(tot.kwh)}</td>
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
  if (!readings.length) return '<div class="empty" style="padding:32px"><div class="empty-icon">📋</div><h3>Keine Ablesungen</h3></div>';
  const sorted = [...readings].sort((a, b) => b.date.localeCompare(a.date));
  return `<div class="table-wrap"><table class="table">
    <thead><tr>
      <th>Datum</th>
      <th class="num">Zählerstand</th>
      <th>Notiz</th>
      <th></th>
    </tr></thead>
    <tbody>
    ${sorted.map(r => `<tr data-reading-id="${escapeHtml(r.id)}">
      <td><strong>${fmt.date(r.date)}</strong> ${r.is_future ? '<span class="status-pill future">ZUKUNFT</span>' : ''} ${r.is_estimated ? '<span class="status-pill" style="background:var(--c-yellow-soft);color:var(--c-yellow)">SCHÄTZUNG</span>' : ''}</td>
      <td class="num">${fmt.num(r.counter, 1)} ${u.unit}</td>
      <td class="muted" style="font-size:12px">${escapeHtml(r.note || '')}</td>
      <td style="text-align:right;white-space:nowrap">
        <button class="icon-btn" data-action="edit-reading" data-id="${escapeHtml(r.id)}" title="Bearbeiten">✏️</button>
        <button class="icon-btn" data-action="delete-reading" data-id="${escapeHtml(r.id)}" title="Löschen">🗑️</button>
      </td>
    </tr>`).join('')}
    </tbody>
  </table></div>`;
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
          label: 'ø Temperatur',
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
  });
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
function wireEvents(container, u, meter, readings, contracts) {
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
  const newReadingHandlers = ['#header-new-reading', '#banner-new-reading', '#btn-new-reading'];
  newReadingHandlers.forEach(sel => {
    container.querySelector(sel)?.addEventListener('click', () => openReadingModal(container, u, meter, null));
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
      if (!confirm(`Ablesung vom ${fmt.date(reading?.date)} wirklich löschen?`)) return;
      try { await api.deleteReading(u.key, id); toastOk('Ablesung gelöscht'); rerender(container); }
      catch (e) { toastErr(e.message); }
    });
  });

  // Contract action
  container.querySelector('#btn-new-contract')?.addEventListener('click', () => {
    location.hash = `#/utility/${u.key}/contracts`;
  });
}

// ── Reading modal (add / edit) ──────────────────────────────────────
function openReadingModal(container, u, meter, reading) {
  const isEdit = !!reading;
  const today = todayIso();
  const body = `
    <form id="reading-form">
      <div class="field">
        <label>Datum</label>
        <input class="input" type="date" name="date" value="${reading?.date || today}" required>
      </div>
      <div class="field">
        <label>Zählerstand (${u.unit})</label>
        <input class="input" type="number" step="0.01" name="counter" value="${reading?.counter ?? ''}" required>
      </div>
      <div class="field">
        <label>Notiz (optional)</label>
        <input class="input input--text" type="text" name="note" value="${escapeHtml(reading?.note || '')}">
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0">
          <input type="checkbox" name="is_estimated" ${reading?.is_estimated ? 'checked' : ''}>
          <span>Geschätzt / korrigierter Wert</span>
        </label>
      </div>
    </form>
  `;
  const footer = `
    <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
    <button type="button" class="btn btn--primary" data-act="save">${isEdit ? 'Speichern' : 'Anlegen'}</button>
  `;
  openModal({
    title: isEdit ? `Ablesung bearbeiten · ${u.label}` : `Neue Ablesung · ${u.label}`,
    body, footer,
    onMount({ modalEl, close }) {
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
        if (!data.date || isNaN(data.counter)) { toastErr('Datum und Zählerstand sind Pflicht.'); return; }
        try {
          if (isEdit) await api.updateReading(u.key, reading.id, data);
          else        await api.createReading(u.key, data);
          toastOk(isEdit ? 'Ablesung aktualisiert' : 'Ablesung gespeichert');
          close(true);
          rerender(container);
        } catch (e) { toastErr(e.message); }
      });
    },
  });
}
