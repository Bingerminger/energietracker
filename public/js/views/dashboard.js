// =====================================================================
// Dashboard — overview of all utilities, last 12 months.
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { makeChart } from '../components/chart.js';
import { toastErr } from '../components/toast.js';

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade Übersicht…</div>';
  const [allUtilities, settings] = await Promise.all([getUtilities(), getSettings()]);

  // v1.3.0 / P10 — nur aktive Verbrauchsarten anzeigen
  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities
    : allUtilities.map(u => u.key);
  const utilities = allUtilities.filter(u => active.includes(u.key));

  // Fetch consumption for each active utility + Insights in parallel
  const [datasets, eff, recs, reminders] = await Promise.all([
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
  ]);

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

  container.innerHTML = `
    <div class="section-head">
      <h1>Übersicht</h1>
      <div class="section-actions">
        <a class="btn btn--ghost" href="#/temperatures">Temperaturen</a>
        <a class="btn btn--primary" href="#/forecast">Prognose</a>
      </div>
    </div>

    <div class="grid grid-2">
      ${eff && Array.isArray(eff.per_source) && eff.per_source.length ? `
      <div class="card dash-insight">
        <h3 class="card__title">🏅 Effizienz ${eff.year}</h3>
        ${eff.per_source.length === 1 ? `
          <div class="dash-eff">
            <span class="dash-eff__class">${eff.per_source[0].class ?? '–'}</span>
            <span class="dash-eff__val">${fmt.num(eff.per_source[0].kwh_per_m2, 0)} kWh/m²·a</span>
          </div>
          <div class="kpi__sub">${escapeHtml(eff.per_source[0].label)} · Wohnfläche ${eff.wohnflaeche_m2} m²</div>
        ` : `
          <div class="dash-eff-list">
            ${eff.per_source.map(s => `
              <div class="dash-eff-row">
                <span class="dash-eff-row__src">${escapeHtml(s.label)}</span>
                <span class="dash-eff-row__cls badge badge--${effClsTone(s.class)}">${s.class ?? '–'}</span>
                <span class="dash-eff-row__val">${fmt.num(s.kwh_per_m2, 0)} kWh/m²·a</span>
              </div>`).join('')}
          </div>
          <div class="kpi__sub">je Heizquelle · Wohnfläche ${eff.wohnflaeche_m2} m²</div>
        `}
      </div>` : ''}

      ${tanks.length ? `
      <div class="card dash-insight">
        <h3 class="card__title">🛢️ Tank-Bestände</h3>
        ${tanks.map(t => {
          const pct = t.cap > 0 ? Math.max(0, Math.min(100, t.stock / t.cap * 100)) : 0;
          const cls = pct <= 8 ? 'alert' : (pct <= 15 ? 'warn' : 'ok');
          return `<div class="dash-tank">
            <div class="dash-tank__label">${t.utility.icon} ${escapeHtml(t.meter.name || t.utility.label)}
              <span class="muted">${fmt.num(t.stock,0)} / ${fmt.num(t.cap,0)} ${t.unit}</span></div>
            <div class="tank-bar"><div class="tank-bar__fill tank-bar__fill--${cls}" style="width:${pct.toFixed(0)}%"></div></div>
          </div>`;
        }).join('')}
      </div>` : ''}

      ${topRecs.length ? `
      <div class="card dash-insight">
        <h3 class="card__title">💡 Top-Empfehlungen
          <span class="card__title-action"><a class="btn btn--ghost btn--sm" href="#/recommendations">alle</a></span>
        </h3>
        ${topRecs.map(r => `<div class="dash-rec dash-rec--${r.severity}">
          <strong>${escapeHtml(r.title)}</strong>
          <span class="muted">${escapeHtml(r.detail.slice(0, 110))}${r.detail.length > 110 ? '…' : ''}</span>
        </div>`).join('')}
      </div>` : ''}

      ${dueRem.length ? `
      <div class="card dash-insight">
        <h3 class="card__title">📌 Anstehende Termine
          <span class="card__title-action"><a class="btn btn--ghost btn--sm" href="#/reminders">alle</a></span>
        </h3>
        ${dueRem.map(r => `<div class="dash-rec">
          <strong>${escapeHtml(r.title)}</strong>
          <span class="muted">fällig ${r.next_due}${r.days_until != null ? ` (${r.days_until <= 0 ? 'jetzt' : 'in ' + r.days_until + ' T'})` : ''}</span>
        </div>`).join('')}
      </div>` : ''}
    </div>

    <div class="grid grid-2" style="margin-top: var(--sp-5)">
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

// Effizienzklasse → Badge-Tönung (gut=success … schlecht=danger)
function effClsTone(cls) {
  if (!cls) return 'info';
  if (['A+', 'A', 'B'].includes(cls)) return 'success';
  if (['C', 'D'].includes(cls)) return 'info';
  if (['E', 'F'].includes(cls)) return 'warning';
  return 'danger'; // G, H
}
