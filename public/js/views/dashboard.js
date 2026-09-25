// =====================================================================
// Dashboard — overview of all utilities, last 12 months.
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { fmt, escapeHtml, todayIso } from '../lib/format.js';
import { makeChart } from '../components/chart.js';
import { toastErr } from '../components/toast.js';
import { t, tp } from '../lib/i18n.js';
import { loadDemo } from '../lib/demo.js';
import { tankLevel } from '../lib/tank.js';
import { info, infoNote } from '../components/info.js';
import { isFeedIn, isGeneration, isPv, moreIsBetter } from '../lib/semantics.js';
import { setupSteps, setupListHtml } from '../lib/onboarding.js';

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('dashboard.loading')}</div>`;
  const [allUtilities, settings] = await Promise.all([getUtilities(), getSettings()]);

  // v1.3.0 / P10 — nur aktive Verbrauchsarten anzeigen
  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities
    : allUtilities.map(u => u.key);
  const utilities = allUtilities.filter(u => active.includes(u.key));
  // v2.9.0 (CALC-23) — Zeitraum des Verlaufsdiagramms aus den Einstellungen
  const chartSpan = Math.min(36, Math.max(3, Number(settings.dashboard_months) || 12));

  // Tank-Bestände für Delivery-Utilities — v2.11.0 (Review FE-26): alle
  // parallel und zugleich mit dem Rest; bis v2.10 Tank für Tank nacheinander,
  // nachdem alles andere schon da war
  const deliveryUtils = utilities.filter(u => u.reading_kind === 'delivery');
  const tankListsP = Promise.all(deliveryUtils.map(async u => {
    try {
      const meters = (await api.meters(u.key)).filter(m => (m.active ?? true) !== false);
      const hist = await Promise.all(meters.map(m => api.stockHistory(u.key, m.id).catch(() => null)));
      return meters.map((m, i) => ({ u, m, sh: hist[i] })).filter(x => x.sh && x.sh.capacity);
    } catch { return []; }
  }));

  // Fetch consumption for each active utility + Insights in parallel
  const [datasets, eff, recs, reminders, stromSaldo, pvSummary, tankLists, overview] = await Promise.all([
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
    tankListsP,
    // v2.12.0 — letzte Ablesung je Zähler für „Zu tun" (ein Aufruf)
    api.readingsOverview().catch(() => null),
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

  const tanks = tankLists.flat().map(({ u, m, sh }) => {
    const days = sh.days || [];
    const stock = days.length ? Number(days[days.length - 1].stock || 0) : 0;
    return { utility: u, meter: m, stock, cap: Number(sh.capacity), unit: sh.capacity_unit || u.volume_unit || 'L' };
  });

  // v2.12.0 (Review UI-21) — „Zu tun" zuerst: fällige Termine, fällige
  // Ablesungen, Kündigungsfristen und Tanks an einer Stelle. Bis v2.11 lag das
  // auf drei Karten verteilt unter Effizienz und Strom-Saldo.
  const todo = buildTodo({ reminders, recs, overview, settings, utilities });
  const todoRecIds = new Set(todo.filter(x => x.recId).map(x => x.recId));
  const topRecs = (recs || []).filter(r => !todoRecIds.has(r.id)).slice(0, 2);

  // A — Leerzustand: keinerlei Verbrauchsdaten in irgendeiner aktiven Art.
  const hasAnyData = datasets.some(d => ((d.consumption?.monthly_total) || []).length > 0);

  // Insight-Karten (Effizienz, Tanks, Strom-Saldo, Empfehlungen, Termine).
  const insightsHtml = `
    <div class="grid grid-2">
      ${eff && Array.isArray(eff.per_source) && eff.per_source.length ? `
      <div class="card dash-insight">
        <h2 class="card__title"><span aria-hidden="true">🏅</span> ${t('dashboard.efficiency.title')} ${eff.year}${info('efficiency')}</h2>
        ${eff.per_source.length === 1 ? `
          <div class="dash-eff">
            ${eff.scale === null ? '' : `<span class="dash-eff__class">${eff.per_source[0].class ?? '–'}</span>`}
            <span class="dash-eff__val">${fmt.num(eff.per_source[0].kwh_per_m2, 0)} kWh/m²·a</span>
          </div>
          <div class="kpi__sub">${escapeHtml(eff.per_source[0].label)} · ${t('dashboard.efficiency.livingArea', { area: eff.wohnflaeche_m2 })}</div>
        ` : `
          <div class="dash-eff-list">
            ${eff.per_source.map(s => `
              <div class="dash-eff-row">
                <span class="dash-eff-row__src">${escapeHtml(s.label)}</span>
                ${eff.scale === null ? '' : `<span class="dash-eff-row__cls badge badge--${effClsTone(s.class)}">${s.class ?? '–'}</span>`}
                <span class="dash-eff-row__val">${fmt.num(s.kwh_per_m2, 0)} kWh/m²·a</span>
              </div>`).join('')}
          </div>
          <div class="kpi__sub">${t('dashboard.efficiency.perSource', { area: eff.wohnflaeche_m2 })}</div>
        `}
        ${eff.certificate ? `
          <p class="kpi__sub dash-eff__cert">${escapeHtml(t(eff.per_source.length > 1 ? 'dashboard.efficiency.certificateAll' : 'dashboard.efficiency.certificate', {
            value: fmt.num(eff.certificate.kwh_per_m2, 0),
            cls: eff.scale !== null && eff.certificate.class ? ' · ' + eff.certificate.class : '',
          }))}${infoNote(t('glossary.efficiency.term'), t('dashboard.efficiency.certificateTitle', {
            area: fmt.num(eff.certificate.area_m2, 0),
            months: eff.certificate.months_36,
          }))}</p>` : ''}
        ${eff.per_source.some(s => s.complete === false) && eff.note ? `<p class="muted dash-eff__note">${escapeHtml(eff.note)}</p>` : ''}
        ${eff.scale_note ? `<p class="muted dash-eff__note">${escapeHtml(eff.scale_note)}</p>` : ''}
      </div>` : ''}

      ${tanks.length ? `
      <div class="card dash-insight">
        <h2 class="card__title"><span aria-hidden="true">🛢️</span> ${t('dashboard.tanks.title')}${info('tankBook')}</h2>
        ${tanks.map(tk => {
          const pct = tk.cap > 0 ? Math.max(0, Math.min(100, tk.stock / tk.cap * 100)) : 0;
          const cls = tankLevel(pct, settings?.tank_warn_pct);
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
            <div class="kpi__label">${t('dashboard.stromSaldo.pvRevenue')}${info('feedIn')}</div>
            <div class="kpi__value">${fmt.eur(saldoYear.einspeisung_revenue)}</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.pvRevenueSub', { kwh: fmt.num(saldoYear.einspeisung_kwh, 0) })}</div>
          </div>
          <div class="kpi kpi--accent">
            <div class="kpi__label">${t('dashboard.stromSaldo.netto')}</div>
            <!-- v2.13.0 (Review UI-18) — ohne Vorzeichen, die Zeile darunter sagt die Richtung -->
            <div class="kpi__value ${saldoYear.saldo_netto < 0 ? 'success-text' : ''}">${fmt.eur(Math.abs(saldoYear.saldo_netto || 0))}</div>
            <div class="kpi__sub">${saldoYear.saldo_netto < 0 ? t('dashboard.stromSaldo.nettoEarn') : t('dashboard.stromSaldo.nettoCost')}</div>
          </div>
          ${pvYear && pvYear.savings_eur != null ? `
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.savings')}${info('selfConsumption')}</div>
            <div class="kpi__value positive">${fmt.eur(pvYear.savings_eur)}</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.savingsSub', { kwh: fmt.num(pvYear.eigenverbrauch_kwh, 0) })}</div>
          </div>` : ''}
          ${pvYear && pvYear.autarkiequote != null ? `
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.autarky')}${info('autarky')}</div>
            <div class="kpi__value">${(pvYear.autarkiequote * 100).toFixed(0)} %</div>
            <div class="kpi__sub">${pvYear.months_covered < 12
              // weniger als ein ganzes Jahr (laufendes Jahr, später Beginn oder
              // ungleiche Abdeckung): sagen, worüber die Quote rechnet
              ? t('dashboard.stromSaldo.coveredSub', { months: pvYear.months_covered })
              : t('dashboard.stromSaldo.autarkySub')}</div>
          </div>` : ''}
          ${pvYear && pvYear.eigenverbrauchsquote != null ? `
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.selfUse')}${info('selfConsumptionRate')}</div>
            <div class="kpi__value">${(pvYear.eigenverbrauchsquote * 100).toFixed(0)} %</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.selfUseSub', { kwh: fmt.num(pvYear.eigenverbrauch_kwh, 0) })}</div>
          </div>` : ''}
        </div>
      </div>` : ''}

      ${topRecs.length ? `
      <div class="card dash-insight">
        <div class="card__head">
          <h2 class="card__title"><span aria-hidden="true">💡</span> ${t('dashboard.recommendations.title')}</h2>
          <a class="btn btn--ghost btn--sm" href="#/recommendations" aria-label="${escapeHtml(t('dashboard.allRecommendations'))}">${t('dashboard.allLink')}</a>
        </div>
        ${topRecs.map(r => `<div class="dash-rec dash-rec--${r.severity}">
          <strong>${escapeHtml(r.title)}</strong>
          <span class="muted">${escapeHtml(r.detail.slice(0, 110))}${r.detail.length > 110 ? '…' : ''}</span>
        </div>`).join('')}
      </div>` : ''}

    </div>`;

  container.innerHTML = `
    <div class="section-head">
      <h1>${t('dashboard.title')}</h1>
      <div class="section-actions">
        <!-- v2.11.0 (Review UI-21) — „Temperaturen" gehört zu den Wetterdaten;
             „Erfassen" steht in der Kopfleiste bzw. der Tab-Leiste -->
        <a class="btn btn--ghost" href="#/forecast">${t('nav.forecast')}</a>
      </div>
    </div>

    ${!hasAnyData ? `
    <!-- v2.13.0 (Review UI-32, DOC-31) — Einstieg statt Leerseite: was die App
         tut, die ersten Schritte mit Häkchen aus den Daten, und Beispieldaten
         zum Ausprobieren (vorher sichert der Server den jetzigen Stand) -->
    <section class="card dash-welcome" aria-labelledby="dash-welcome-title">
      <h2 class="dash-welcome__title" id="dash-welcome-title">${escapeHtml(t('dashboard.welcome.title'))}</h2>
      <p class="dash-welcome__text">${escapeHtml(t('dashboard.welcome.text'))}</p>
      <div class="dash-welcome__actions">
        <a class="btn btn--primary" href="#/zaehlerstaende">${escapeHtml(t('dashboard.empty.cta'))}</a>
        <button type="button" class="btn btn--ghost" data-action="demo">${escapeHtml(t('dashboard.welcome.demo'))}</button>
      </div>
      <h3 class="dash-welcome__steps">${escapeHtml(t('help.setup.title'))}</h3>
      <div data-role="setup"></div>
    </section>
    ` : `
    ${todoHtml(todo)}
    ${insightsHtml}

    <div class="grid grid-2" style="margin-top: var(--sp-5)">
      ${datasets.map(d => renderUtilityCard(d)).join('')}
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <h2 class="card__title">${t('dashboard.chart.title', { months: chartSpan })}</h2>
      <div class="chart-wrap"><canvas id="dash-chart"></canvas></div>
    </div>
    `}
  `;

  // v2.13.0 — Einstieg: Beispieldaten und die ersten Schritte
  if (!hasAnyData) {
    container.querySelector('[data-action="demo"]')?.addEventListener('click', async () => {
      try { await loadDemo(); } catch (e) { toastErr(e.message); }
    });
    setupSteps().then(steps => {
      const box = container.querySelector('[data-role="setup"]');
      if (box?.isConnected) box.innerHTML = setupListHtml(steps);
    }).catch(() => { /* ohne Liste bleibt der Einstieg bedienbar */ });
  }

  // Render combined chart
  renderCombinedChart(datasets, chartSpan);

  // Cleanup: destroy chart on next nav
  return () => {
    const ch = window._dashChart;
    if (ch) { ch.destroy(); window._dashChart = null; }
  };
}

/**
 * v2.12.0 (Review UI-21) — Was jetzt zu tun ist, mit Sprung dorthin.
 * Termine (fällig/überfällig), Ablesungen älter als
 * `alert_days_since_reading`, Kündigungsfristen und Tanks aus den
 * Empfehlungen (Kategorien „vertrag" und „bestand").
 */
function buildTodo({ reminders, recs, overview, settings, utilities }) {
  const items = [];
  for (const r of reminders || []) {
    if (!['due', 'overdue'].includes(r.status)) continue;
    const when = r.days_until == null ? fmt.date(r.next_due)
      : r.days_until < 0 ? tp('dashboard.reminders.overdueDays', -r.days_until)
      : r.days_until === 0 ? t('dashboard.reminders.now') : tp('dashboard.reminders.inDaysN', r.days_until);
    items.push({ icon: '📌', tone: r.status === 'overdue' ? 'alert' : 'warn', text: r.title, sub: when,
      href: '#/reminders', action: t('dashboard.todo.reminderAction') });
  }
  const alertDays = Math.max(1, Number(settings?.alert_days_since_reading) || 45);
  const active = new Set((utilities || []).map(u => u.key));
  const today = new Date(todayIso() + 'T00:00:00');
  const due = [];
  for (const row of overview?.rows || []) {
    if (!active.has(row.utility)) continue;
    const last = row.last_reading?.date;
    const days = last ? Math.round((today - new Date(last + 'T00:00:00')) / 86400000) : null;
    if (days !== null && days <= alertDays) continue;
    due.push({ row, days });
  }
  // Bis zu zwei Zähler einzeln (mit Sprung zur Karte), mehr als eine Zeile —
  // sonst schiebt ein monatlicher Ablesetag die ganze Übersicht nach unten
  if (due.length > 2) {
    const names = due.map(d => d.row.meter_name);
    items.push({ icon: '📋', tone: 'warn', text: tp('dashboard.todo.readingsDue', due.length),
      sub: names.slice(0, 3).join(', ') + (names.length > 3 ? ' …' : ''),
      href: '#/zaehlerstaende', action: t('dashboard.todo.readingAction') });
  } else {
    for (const { row, days } of due) {
      items.push({ icon: row.utility_icon || '📋', tone: 'warn', text: `${row.utility_label} · ${row.meter_name}`,
        sub: days === null ? t('dashboard.todo.readingNever') : tp('dashboard.todo.readingDue', days),
        href: `#/zaehlerstaende?meter=${encodeURIComponent(row.meter_id)}`, action: t('dashboard.todo.readingAction') });
    }
  }
  for (const r of recs || []) {
    if (r.category !== 'vertrag' && r.category !== 'bestand') continue;
    const u = r.evidence?.utility;
    const isTank = r.category === 'bestand';
    items.push({ icon: isTank ? '🛢️' : '📄', tone: r.severity === 'urgent' ? 'alert' : 'warn', text: r.title, recId: r.id,
      href: isTank ? `#/utility/${encodeURIComponent(u || '')}?add=delivery` : (u ? `#/utility/${encodeURIComponent(u)}/contracts` : '#/contracts'),
      action: t(isTank ? 'dashboard.todo.tankAction' : 'dashboard.todo.contractAction') });
  }
  // Überfälliges zuerst
  return items.sort((a, b) => (a.tone === 'alert' ? 0 : 1) - (b.tone === 'alert' ? 0 : 1));
}

function todoHtml(items) {
  if (!items.length) return '';
  return `
    <section class="card dash-todo" aria-labelledby="dash-todo-title">
      <h2 class="card__title" id="dash-todo-title"><span aria-hidden="true">✅</span> ${escapeHtml(t('dashboard.todo.title'))}</h2>
      <ul class="dash-todo__list">
        ${items.map(x => `<li class="dash-todo__item dash-todo__item--${x.tone}">
          <!-- die ganze Zeile ist der Link: am iPhone ein Tippziel statt eines
               umbrechenden Knopfs unter dem Text -->
          <a class="dash-todo__link" href="${x.href}">
            <span class="dash-todo__icon" aria-hidden="true">${escapeHtml(x.icon)}</span>
            <span class="dash-todo__text"><strong>${escapeHtml(x.text)}</strong>${x.sub ? `<span class="muted">${escapeHtml(x.sub)}</span>` : ''}</span>
            <span class="btn btn--sm btn--ghost dash-todo__go">${escapeHtml(x.action)}</span>
            <span class="dash-todo__chev" aria-hidden="true">›</span>
          </a>
        </li>`).join('')}
      </ul>
    </section>`;
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
  // v2.12.0 (Review UI-21) — die Kachel „Aktive Zähler 1 · 1 insgesamt" stand
  // achtmal auf der Übersicht; die Zähler sind einen Klick entfernt
  const noContract = totalCost === 0;                     // D — kein Vertrag/keine Kosten
  // v2.13.0 (Review FE-06) — Einspeisung ist ein Erlös, Erzeugung hat weder
  // Kosten noch Vertrag; bei beiden ist mehr besser (Trendfarbe)
  const feedIn = isFeedIn(utility);
  const generation = isGeneration(utility);
  const better = moreIsBetter(utility);
  const valueLabel = t(feedIn ? 'dashboard.kpi.feedIn' : generation ? 'dashboard.kpi.generation' : 'dashboard.kpi.consumption');

  return `
    <div class="card" data-utility="${utility.key}">
      <div class="section-head" style="margin-bottom: var(--sp-3)">
        <h2><span style="color: ${utility.color}" aria-hidden="true">${utility.icon}</span> ${escapeHtml(utility.label)}</h2>
        <div class="section-actions">
          <a class="btn btn--sm btn--ghost" href="#/utility/${utility.key}/meters">${t('dashboard.card.meters')}</a>
          <a class="btn btn--sm btn--util" href="#/utility/${utility.key}">${t('dashboard.card.details')}</a>
        </div>
      </div>
      <div class="grid grid-2 dash-util-kpis">
        <div class="kpi">
          <div class="kpi__label">${valueLabel}</div>
          <div class="kpi__value">${fmt.num(totalCons, 0)} ${trendBadge(totalCons, prevCons, hasPrev, better)}</div>
          <div class="kpi__sub">${utility.consumption_unit}</div>
        </div>
        ${generation ? '' : `<div class="kpi">
          <div class="kpi__label">${t(feedIn ? 'dashboard.kpi.revenue' : 'dashboard.kpi.cost')}</div>
          <div class="kpi__value">${noContract ? '<span class="kpi__empty" aria-hidden="true">—</span>' : `${fmt.eur(totalCost)} ${trendBadge(totalCost, prevCost, hasPrev, better)}`}</div>
          <div class="kpi__sub">${noContract ? t('dashboard.kpi.noContract') : t(feedIn ? 'dashboard.kpi.revenueSub' : 'dashboard.kpi.costSub')}</div>
        </div>`}
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
function trendBadge(curr, prev, hasPrev, moreIsGood = false) {
  if (!hasPrev || prev == null || prev <= 0) return '';
  const pct = (curr - prev) / prev * 100;
  if (!isFinite(pct) || Math.abs(pct) < 0.5) return '';
  const up = pct > 0;
  // v2.13.0 (Review FE-06) — bei Einspeisung und Erzeugung ist mehr gut
  const tone = up === moreIsGood ? 'success' : 'danger';
  const arrow = up ? '▲' : '▼';
  const title = escapeHtml(t('dashboard.trend.vsPrev'));
  const pctStr = fmt.num(Math.abs(pct), 0);
  // A11y: Pfeil + Farbe sind rein visuell — der aria-label nennt Richtung
  // und Bezug im Klartext; der Pfeil-Glyph bleibt aus dem Accessibility-Tree.
  const label = escapeHtml(t(up ? 'dashboard.trend.moreThanPrev' : 'dashboard.trend.lessThanPrev', { pct: pctStr }));
  return `<span class="kpi__trend kpi__trend--${tone}" title="${title}" aria-label="${label}">` +
    `<span aria-hidden="true">${arrow} ${pctStr} %</span></span>`;
}

function renderCombinedChart(datasets, span = 12) {
  const canvas = document.getElementById('dash-chart');
  if (!canvas) return;

  // Find the union of months across all utilities (last `span`, v2.9.0:
  // Einstellung dashboard_months — bis v2.8 fest 12 und die Einstellung
  // ohne Wirkung; der Vorjahresvergleich der Kacheln bleibt bei 12 Monaten)
  // v2.13.0 (Review FE-06) — nur Verbrauch: Einspeisung und Erzeugung stehen
  // in der Strom-Saldo-Karte; auf derselben kWh-Achse lasen sie sich als
  // Verbrauch
  datasets = datasets.filter(d => !isPv(d.utility));
  const allMonths = new Set();
  datasets.forEach(d => (d.consumption?.monthly_total || []).slice(-span).forEach(m => allMonths.add(m.ym)));
  const months = Array.from(allMonths).sort().slice(-span);

  const seriesList = datasets.map(d => {
    const u = d.utility;
    const key = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
    const byYm = Object.fromEntries((d.consumption?.monthly_total || []).map(m => [m.ym, m[key]]));
    // v2.5.3 (FE-03) — Monat ohne Daten ist eine Lücke, kein Nullverbrauch.
    // Die Achse vereint die Monate aller Arten; wer Strom täglich per Home
    // Assistant schickt und Gas monatlich abliest, sah die jüngsten Gasmonate
    // als 0 — die zentrale Grafik behauptete „kein Verbrauch".
    return {
      label: `${u.label} (${u.consumption_unit})`,
      data:  months.map(m => byYm[m] ?? null),
      spanGaps: false,
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
  window._dashChart = makeChart(canvas, cfg, { label: t('dashboard.chart.alt', { months: span }) });
}

// Effizienzklasse → Badge-Tönung (gut=success … schlecht=danger)
function effClsTone(cls) {
  if (!cls) return 'info';
  if (['A+', 'A', 'B'].includes(cls)) return 'success';
  if (['C', 'D'].includes(cls)) return 'info';
  if (['E', 'F'].includes(cls)) return 'warning';
  return 'danger'; // G, H
}
