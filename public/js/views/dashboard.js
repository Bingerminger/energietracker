// =====================================================================
// Dashboard — overview of all utilities, last 12 months.
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { makeChart } from '../components/chart.js';
import { toastErr } from '../components/toast.js';
import { t } from '../lib/i18n.js';

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('dashboard.loading')}</div>`;
  const [allUtilities, settings] = await Promise.all([getUtilities(), getSettings()]);

  // v1.3.0 / P10 — nur aktive Verbrauchsarten anzeigen
  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities
    : allUtilities.map(u => u.key);
  const utilities = allUtilities.filter(u => active.includes(u.key));

  // Fetch consumption for each active utility + Insights in parallel
  const [datasets, eff, recs, reminders, stromSaldo, pvSummary] = await Promise.all([
    Promise.all(utilities.map(async u => {
      try {
        const c = await api.consumption(u.key);
        return { utility: u, consumption: c };
      } catch (e) {
        toastErr(`${u.label}: ${e.message}`);
        return { utility: u, consumption: null };
      }
    })),
    api.efficiency().catch(() => null),
    api.recommendations().catch(() => []),
    api.reminders().catch(() => []),
    // F1005 (v1.7.0) — Strom-Saldo (Bezug−Einspeisung) + PV-Eigenverbrauch/Autarkie
    api.stromSaldo().catch(() => null),
    api.pvSummary().catch(() => null),
  ]);

  // F1005 — Insight-Karte „Strom-Saldo" nur, wenn der User tatsächlich
  // PV-Einspeisung erfasst. Sonst leere/nullenlange Anzeige bei Nicht-PV-
  // Haushalten.
  const pvActive = !!(stromSaldo && Array.isArray(stromSaldo.monthly)
    && stromSaldo.monthly.some(m => (m.einspeisung_kwh ?? 0) > 0));
  const pickYearRow = (yearly) => {
    const cy = new Date().getFullYear();
    return (yearly || []).find(y => y.year === cy)
        ?? (yearly || []).slice(-1)[0]
        ?? null;
  };
  const saldoYear = pvActive ? pickYearRow(stromSaldo.yearly) : null;
  const pvYear    = pvActive && pvSummary ? pickYearRow(pvSummary.yearly) : null;

  // Tank-Bestände für Delivery-Utilities sammeln
  const deliveryUtils = utilities.filter(u => u.reading_kind === 'delivery');
  const tanks = [];
  for (const u of deliveryUtils) {
    try {
      const meters = await api.meters(u.key);
      for (const m of meters) {
        if ((m.active ?? true) === false) continue;
        const sh = await api.stockHistory(u.key, m.id).catch(() => null);
        if (sh && sh.capacity) {
          const days = sh.days || [];
          const stock = days.length ? Number(days[days.length - 1].stock || 0) : 0;
          tanks.push({ utility: u, meter: m, stock, cap: Number(sh.capacity), unit: sh.capacity_unit || u.volume_unit || 'L' });
        }
      }
    } catch {}
  }

  const topRecs = (recs || []).slice(0, 2);
  const dueRem = (reminders || [])
    .filter(r => ['due', 'overdue', 'due_soon'].includes(r.status))
    .slice(0, 2);

  // A — Leerzustand: keinerlei Verbrauchsdaten in irgendeiner aktiven Art.
  const hasAnyData = datasets.some(d => ((d.consumption?.monthly_total) || []).length > 0);

  // Insight-Karten (Effizienz, Tanks, Strom-Saldo, Empfehlungen, Termine).
  const insightsHtml = `
    <div class="grid grid-2">
      ${eff && Array.isArray(eff.per_source) && eff.per_source.length ? `
      <div class="card dash-insight">
        <h2 class="card__title"><span aria-hidden="true">🏅</span> ${t('dashboard.efficiency.title')} ${eff.year}</h2>
        ${eff.per_source.length === 1 ? `
          <div class="dash-eff">
            <span class="dash-eff__class">${eff.per_source[0].class ?? '–'}</span>
            <span class="dash-eff__val">${fmt.num(eff.per_source[0].kwh_per_m2, 0)} kWh/m²·a</span>
          </div>
          <div class="kpi__sub">${escapeHtml(eff.per_source[0].label)} · ${t('dashboard.efficiency.livingArea', { area: eff.wohnflaeche_m2 })}</div>
        ` : `
          <div class="dash-eff-list">
            ${eff.per_source.map(s => `
              <div class="dash-eff-row">
                <span class="dash-eff-row__src">${escapeHtml(s.label)}</span>
                <span class="dash-eff-row__cls badge badge--${effClsTone(s.class)}">${s.class ?? '–'}</span>
                <span class="dash-eff-row__val">${fmt.num(s.kwh_per_m2, 0)} kWh/m²·a</span>
              </div>`).join('')}
          </div>
          <div class="kpi__sub">${t('dashboard.efficiency.perSource', { area: eff.wohnflaeche_m2 })}</div>
        `}
      </div>` : ''}

      ${tanks.length ? `
      <div class="card dash-insight">
        <h2 class="card__title"><span aria-hidden="true">🛢️</span> ${t('dashboard.tanks.title')}</h2>
        ${tanks.map(tk => {
          const pct = tk.cap > 0 ? Math.max(0, Math.min(100, tk.stock / tk.cap * 100)) : 0;
          const cls = pct <= 8 ? 'alert' : (pct <= 15 ? 'warn' : 'ok');
          return `<div class="dash-tank">
            <div class="dash-tank__label">${tk.utility.icon} ${escapeHtml(tk.meter.name || tk.utility.label)}
              <span class="muted">${fmt.num(tk.stock,0)} / ${fmt.num(tk.cap,0)} ${tk.unit}</span></div>
            <div class="tank-bar"><div class="tank-bar__fill tank-bar__fill--${cls}" style="width:${pct.toFixed(0)}%"></div></div>
          </div>`;
        }).join('')}
      </div>` : ''}

      ${saldoYear ? `
      <div class="card dash-insight">
        <h2 class="card__title"><span aria-hidden="true">⚡</span> ${t('dashboard.stromSaldo.title')} ${saldoYear.year}</h2>
        <div class="dash-strom-saldo">
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.bezug')}</div>
            <div class="kpi__value">${fmt.eur(saldoYear.bezug_cost)}</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.bezugSub', { kwh: fmt.num(saldoYear.bezug_kwh, 0) })}</div>
          </div>
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.pvRevenue')}</div>
            <div class="kpi__value">${fmt.eur(saldoYear.einspeisung_revenue)}</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.pvRevenueSub', { kwh: fmt.num(saldoYear.einspeisung_kwh, 0) })}</div>
          </div>
          <div class="kpi kpi--accent">
            <div class="kpi__label">${t('dashboard.stromSaldo.netto')}</div>
            <div class="kpi__value">${fmt.eur(saldoYear.saldo_netto)}</div>
            <div class="kpi__sub">${saldoYear.saldo_netto < 0 ? t('dashboard.stromSaldo.nettoEarn') : t('dashboard.stromSaldo.nettoCost')}</div>
          </div>
          ${pvYear && pvYear.autarkiequote != null ? `
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.autarky')}</div>
            <div class="kpi__value">${(pvYear.autarkiequote * 100).toFixed(0)} %</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.autarkySub')}</div>
          </div>` : ''}
          ${pvYear && pvYear.eigenverbrauchsquote != null ? `
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.selfUse')}</div>
            <div class="kpi__value">${(pvYear.eigenverbrauchsquote * 100).toFixed(0)} %</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.selfUseSub', { kwh: fmt.num(pvYear.eigenverbrauch_kwh, 0) })}</div>
          </div>` : ''}
        </div>
      </div>` : ''}

      ${topRecs.length ? `
      <div class="card dash-insight">
        <h2 class="card__title"><span aria-hidden="true">💡</span> ${t('dashboard.recommendations.title')}
          <span class="card__title-action"><a class="btn btn--ghost btn--sm" href="#/recommendations">${t('dashboard.allLink')}</a></span>
        </h2>
        ${topRecs.map(r => `<div class="dash-rec dash-rec--${r.severity}">
          <strong>${escapeHtml(r.title)}</strong>
          <span class="muted">${escapeHtml(r.detail.slice(0, 110))}${r.detail.length > 110 ? '…' : ''}</span>
        </div>`).join('')}
      </div>` : ''}

      ${dueRem.length ? `
      <div class="card dash-insight">
        <h2 class="card__title"><span aria-hidden="true">📌</span> ${t('dashboard.reminders.title')}
          <span class="card__title-action"><a class="btn btn--ghost btn--sm" href="#/reminders">${t('dashboard.allLink')}</a></span>
        </h2>
        ${dueRem.map(r => `<div class="dash-rec">
          <strong>${escapeHtml(r.title)}</strong>
          <span class="muted">${t('dashboard.reminders.due', { date: r.next_due })}${r.days_until != null ? ` (${r.days_until <= 0 ? t('dashboard.reminders.now') : t('dashboard.reminders.inDays', { days: r.days_until })})` : ''}</span>
        </div>`).join('')}
      </div>` : ''}
    </div>`;

  container.innerHTML = `
    <div class="section-head">
      <h1>${t('dashboard.title')}</h1>
      <div class="section-actions">
        <a class="btn btn--ghost" href="#/temperatures">${t('nav.temperatures')}</a>
        <a class="btn btn--primary" href="#/forecast">${t('nav.forecast')}</a>
      </div>
    </div>

    ${!hasAnyData ? `
    <div class="card dash-empty">
      <div class="dash-empty__icon" aria-hidden="true">📋</div>
      <h2 class="card__title">${t('dashboard.empty.title')}</h2>
      <p class="muted dash-empty__text">${t('dashboard.empty.text')}</p>
      <a class="btn btn--primary" href="#/zaehlerstaende">${t('dashboard.empty.cta')}</a>
    </div>
    ` : `
    ${insightsHtml}

    <div class="grid grid-2" style="margin-top: var(--sp-5)">
      ${datasets.map(d => renderUtilityCard(d)).join('')}
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h2 class="card__title">${t('dashboard.chart.title')}</h2>
      <div class="chart-wrap"><canvas id="dash-chart"></canvas></div>
    </div>
    `}
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
  const monthly = consumption?.monthly_total || [];
  const sumKey = utility.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  const last12 = monthly.slice(-12);
  const prev12 = monthly.slice(-24, -12);                 // B — Vergleichszeitraum
  const totalCons = last12.reduce((s, m) => s + (m[sumKey] || 0), 0);
  const totalCost = last12.reduce((s, m) => s + (m.cost  || 0), 0);
  const prevCons  = prev12.reduce((s, m) => s + (m[sumKey] || 0), 0);
  const prevCost  = prev12.reduce((s, m) => s + (m.cost  || 0), 0);
  const hasPrev   = prev12.length >= 6;                   // genug Vergleichsdaten
  const meters = consumption?.meters || [];
  const activeMeters = meters.filter(m => m.meter.active);
  const noContract = totalCost === 0;                     // D — kein Vertrag/keine Kosten

  return `
    <div class="card" data-utility="${utility.key}">
      <div class="section-head" style="margin-bottom: var(--sp-3)">
        <h2><span style="color: ${utility.color}" aria-hidden="true">${utility.icon}</span> ${escapeHtml(utility.label)}</h2>
        <div class="section-actions">
          <a class="btn btn--sm btn--ghost" href="#/utility/${utility.key}/meters">${t('dashboard.card.meters')}</a>
          <a class="btn btn--sm btn--util" href="#/utility/${utility.key}">${t('dashboard.card.details')}</a>
        </div>
      </div>
      <div class="grid grid-3">
        <div class="kpi">
          <div class="kpi__label">${t('dashboard.kpi.consumption')}</div>
          <div class="kpi__value">${fmt.num(totalCons, 0)} ${trendBadge(totalCons, prevCons, hasPrev)}</div>
          <div class="kpi__sub">${utility.consumption_unit}</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">${t('dashboard.kpi.cost')}</div>
          <div class="kpi__value">${noContract ? '<span class="kpi__empty" aria-hidden="true">—</span>' : `${fmt.eur(totalCost)} ${trendBadge(totalCost, prevCost, hasPrev)}`}</div>
          <div class="kpi__sub">${noContract ? t('dashboard.kpi.noContract') : t('dashboard.kpi.costSub')}</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">${t('dashboard.kpi.activeMeters')}</div>
          <div class="kpi__value">${activeMeters.length}</div>
          <div class="kpi__sub">${t('dashboard.kpi.totalMeters', { count: meters.length })}</div>
        </div>
      </div>
      ${groupBreakdown(consumption, sumKey, utility)}
    </div>
  `;
}

// F1006 — Zähler-Gruppen als Dashboard-Summe. Pro Gruppe mit Mitgliedern wird
// der 12-Monats-Verbrauch (und, falls vorhanden, die Kosten) der zugeordneten
// Zähler aufsummiert und als aufklappbare Übersicht in der Utility-Karte
// gezeigt. Liefert leeren String, wenn es keine Gruppe mit Mitgliedern gibt
// (dann bleibt die Karte unverändert wie zuvor).
function groupBreakdown(consumption, sumKey, utility) {
  const groups = consumption?.meter_groups || [];
  const meters = consumption?.meters || [];
  if (!groups.length) return '';

  const rows = groups.map(g => {
    const members = meters.filter(e => (e.meter?.meter_group_id ?? null) === g.id);
    if (!members.length) return null;
    // v2.1.3 — F1006: Enthält die Gruppe einen Eltern- UND seinen Subzähler,
    // darf der Subzähler nicht zusätzlich gezählt werden — der Eltern-Brutto
    // enthält ihn bereits (analog der Utility-Gesamtsumme in ConsumptionService).
    const memberIds = new Set(members.map(e => e.meter?.id));
    let cons = 0, cost = 0;
    for (const e of members) {
      const parent = e.meter?.parent_meter_id ?? null;
      if (parent !== null && memberIds.has(parent)) continue;
      const last12 = (e.monthly || []).slice(-12);
      cons += last12.reduce((s, m) => s + (m[sumKey] || 0), 0);
      cost += last12.reduce((s, m) => s + (m.cost   || 0), 0);
    }
    return { name: g.name, cons, cost };
  }).filter(Boolean);

  if (!rows.length) return '';

  return `
    <details class="dash-groups">
      <summary class="dash-groups__summary">${t('dashboard.groups.summary', { count: rows.length })}</summary>
      <ul class="dash-groups__list">
        ${rows.map(g => `
          <li class="dash-groups__item">
            <span class="dash-groups__name">${escapeHtml(g.name)}</span>
            <span class="dash-groups__val">${fmt.num(g.cons, 0)} ${escapeHtml(utility.consumption_unit)}${g.cost > 0 ? ` · ${fmt.eur(g.cost)}` : ''}</span>
          </li>`).join('')}
      </ul>
    </details>`;
}

// B — kleiner Trend-Indikator: aktuelle 12 Monate vs. vorherige 12 Monate.
// Mehr Verbrauch/Kosten = ungünstig (danger ▲), weniger = gut (success ▼).
// Liefert leeren String, wenn kein belastbarer Vergleich möglich ist.
function trendBadge(curr, prev, hasPrev) {
  if (!hasPrev || prev == null || prev <= 0) return '';
  const pct = (curr - prev) / prev * 100;
  if (!isFinite(pct) || Math.abs(pct) < 0.5) return '';
  const up = pct > 0;
  const tone = up ? 'danger' : 'success';
  const arrow = up ? '▲' : '▼';
  const title = escapeHtml(t('dashboard.trend.vsPrev'));
  const pctStr = fmt.num(Math.abs(pct), 0);
  // A11y: Pfeil + Farbe sind rein visuell — der aria-label nennt Richtung
  // und Bezug im Klartext; der Pfeil-Glyph bleibt aus dem Accessibility-Tree.
  const label = escapeHtml(t(up ? 'dashboard.trend.moreThanPrev' : 'dashboard.trend.lessThanPrev', { pct: pctStr }));
  return `<span class="kpi__trend kpi__trend--${tone}" title="${title}" aria-label="${label}">` +
    `<span aria-hidden="true">${arrow} ${pctStr} %</span></span>`;
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
  window._dashChart = makeChart(canvas, cfg, { label: t('dashboard.chart.alt') });
}

// Effizienzklasse → Badge-Tönung (gut=success … schlecht=danger)
function effClsTone(cls) {
  if (!cls) return 'info';
  if (['A+', 'A', 'B'].includes(cls)) return 'success';
  if (['C', 'D'].includes(cls)) return 'info';
  if (['E', 'F'].includes(cls)) return 'warning';
  return 'danger'; // G, H
}
