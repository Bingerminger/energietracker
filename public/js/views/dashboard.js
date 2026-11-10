// =====================================================================
// Dashboard — overview of all utilities, last 12 months.
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings, getSettingsSync } from '../state.js';
import { fmt, escapeHtml, todayIso } from '../lib/format.js';
import { makeChart, chartColor, withAlpha, tokenColor, chartTableHtml } from '../components/chart.js';
import { isPartial, daysInMonth, yoyTrend, lastMonths, shiftYm, trendNeedsCheck } from '../lib/chart-data.js';
import { toastErr, toastUndo } from '../components/toast.js';
import { t, tp } from '../lib/i18n.js';
import { heatLimitFacts, heatLimitEffect, heatLimitBeginnerLines, applyHeatLimit, isGroupId, heatLimitLaterUntil } from '../lib/heat-limit.js';   // v3.9.0 (#38)
// v3.9.0 (ablesung-pruefen) — Zwischenablesung prüfen, doppelt eingetragen, Hinweis auf die Zäsur
import { readingCheckLines, readingCheckTitle, correctHref, markReadings, deleteDuplicates, levelShiftLines, levelShiftTitle,
  setBaselineAt, dismissLevelShift } from '../lib/reading-check.js';
import { loadDemo } from '../lib/demo.js';
import { tankLevel } from '../lib/tank.js';
import { info, infoNote } from '../components/info.js';
import { isFeedIn, isGeneration, isPv, moreIsBetter, uncertainRangeHtml, expectedBalance, bandText } from '../lib/semantics.js';
import { setupSteps, setupListHtml } from '../lib/onboarding.js';
import { periodMode, periodName, pickPeriod, periodsWithData, addDays as addDaysIso, sameSpanTrend, periodWindow } from '../lib/billing-period.js';   // v3.6.0 (F1030), v3.10.0 (#36)
import { bonusOf, bonusItemsOf, bonusLineText } from '../lib/bonus.js';   // v3.7.0 (#33)
import { periodModeHtml, wirePeriodMode } from '../components/period-mode.js';
// v3.2.0 (F1019, F1020) — Einsteiger-Übersicht, Schaubilder, Vorschlag zum Hochstufen
import { currentLevel, atLeast, nextLevel, saveLevel, isDismissed, dismiss, suggestReasons, markShown } from '../lib/levels.js';
import { moneyHtml, energyHtml, weatherHtml, timelineHtml, playExplainers } from '../components/explainer.js';
// v3.4.0 (F1024) — Hinweise abhaken
import { markEventDone } from '../components/done-dialog.js';
// v3.5.0 (F1028) — Zuständig: Chip und Filter in „Zu tun“
import { assigneeOf, assigneeNames, chipHtml, filterHtml, bindFilter, filterNoteHtml, matchesAssignee, storedFilter } from '../lib/assignee.js';
// v3.6.0 — Kachel „Dein Hebel“: die Zahl der Ergebniskarte der Flexibilität
import { actionText } from '../lib/flex-text.js';
import { areaAssumedHtml } from '../lib/area-assumed.js';   // v3.6.0 (Paket einpers)
import { welcomeArt } from '../lib/illustrations.js';   // v3.9.0 (Paket oberflaeche) — Bild im Willkommen
import { icon, artIcon } from '../lib/icons.js';
import { trendHtml, SAME_BELOW_PCT } from '../components/trend.js';   // v3.12.0 (M4)
import { rich } from '../lib/rich.js';

// v3.9.0 (Skeptiker R2-5) — der Kasten der Übersicht, damit „Übernehmen“ der Heizgrenze sie ganz neu zeichnet
let dashRoot = null;

export async function render(container, _params = [], ctx = {}) {
  dashRoot = container;
  container.innerHTML = `<div class="loading">${t('dashboard.loading')}</div>`;
  const [allUtilities, settings] = await Promise.all([getUtilities(), getSettings()]);

  // v1.3.0 / P10 — nur aktive Verbrauchsarten anzeigen
  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities
    : allUtilities.map(u => u.key);
  const utilities = allUtilities.filter(u => active.includes(u.key));
  // v2.9.0 (CALC-23) — Zeitraum des Verlaufsdiagramms aus den Einstellungen
  const chartSpan = Math.min(36, Math.max(3, Number(settings.dashboard_months) || 12));

  // Fetch consumption for each active utility + Insights in parallel
  const [datasets, eff, todo, stromSaldo, pvSummary, tanks, summaryRes] = await Promise.all([
    Promise.all(utilities.map(async u => {
      try {
        // v3.6.0 (F1030) — im Abrechnungsjahr dazu die Perioden der Art
        const billingMode = periodMode() === 'billing' && u.supports_bill_check;
        const [c, bp] = await Promise.all([api.consumption(u.key),
          billingMode ? api.utilityBillingPeriods(u.key).catch(() => null) : null]);
        // v3.6.0 (Paket abrechnung) — Zählergruppen dann ebenfalls je Periode (vom Server)
        const groups = billingMode ? await groupPeriods(u.key, c).catch(() => null) : null;
        return { utility: u, consumption: c, billing: pickPeriod(bp?.periods), groups };
      } catch (e) {
        toastErr(`${u.label}: ${e.message}`);
        return { utility: u, consumption: null, failed: e };   // v3.12.0 — die Antwort zum Trend sagt es
      }
    })),
    api.efficiency().catch(() => null),
    // v3.1.0 (H1, B5) — „Zu tun" kommt aus der Agenda (dieselbe Quelle wie Kalender und Home Assistant)
    // v3.12.0 — über dieselbe Ladung wie die Seite „Zu tun“ und die Seitenleiste (todo.js loadOffen): „Noch n
    // weitere“ zählt damit dasselbe (auch Empfehlungen, Post, Vorschläge der KI), und ein Ausfall bleibt sichtbar
    loadTodo(settings),
    // F1005 (v1.7.0) — Strom-Saldo (Bezug−Einspeisung) + PV-Eigenverbrauch/Autarkie
    api.stromSaldo().catch(() => null),
    api.pvSummary().catch(() => null),
    loadTanks(utilities),   // v2.11.0 (Review FE-26) — parallel und zugleich mit dem Rest
    // v3.12.0 (M4) — Antworten und Fuß der Kacheln; ein Fehler bleibt ein Fehler (nicht „Noch kein Vertrag“)
    api.summary().then(data => ({ data }), error => ({ data: null, error })),
  ]);
  const summary = summaryRes.data;
  const summaryError = summaryRes.error || null;
  // v3.1.0 (H8, MKT-11) — Einordnung; eigene Karte, sobald ein Vergleichswert oder ein Link da ist
  const cmp = await api.comparison().catch(() => null);

  // F1005 — Insight-Karte „Strom-Saldo" nur, wenn der User tatsächlich
  // PV-Einspeisung erfasst. Sonst leere/nullenlange Anzeige bei Nicht-PV-
  // Haushalten.
  const pvActive = !!(stromSaldo && Array.isArray(stromSaldo.monthly)
    && stromSaldo.monthly.some(m => (m.einspeisung_kwh ?? 0) > 0));
  const saldoYear = pvActive ? pickYearRow(stromSaldo.yearly) : null;
  const pvYear    = pvActive && pvSummary ? pickYearRow(pvSummary.yearly) : null;

  // v2.12.0 (Review UI-21) — „Zu tun" zuerst: fällige Termine, fällige
  // Ablesungen, Kündigungsfristen und Tanks an einer Stelle. Bis v2.11 lag das
  // auf drei Karten verteilt unter Effizienz und Strom-Saldo. (Die Zeilen baut loadTodo mit buildTodo.)

  // A — Leerzustand: keinerlei Verbrauchsdaten in irgendeiner aktiven Art.
  const hasAnyData = datasets.some(d => ((d.consumption?.monthly_total) || []).length > 0);

  // v3.2.0 (F1019) — Einsteiger: drei Antworten statt aller Kennzahlen
  if (hasAnyData && currentLevel() === 'beginner') {
    return renderBeginner(container, { utilities, datasets, todo, settings, summary, summaryError });
  }

  // v2.16.0 — „Energie gesamt“: nur Verbrauch in kWh, ohne PV (Wasser steht in seiner Karte)
  const totalKinds = datasets.filter(d => !isPv(d.utility) && d.utility.consumption_unit === 'kWh'
    && (d.consumption?.monthly_total || []).length);
  const answers = hasAnyData ? await answersHtml({ utilities, datasets, settings, summary, summaryError, todo }) : '';
  const withPeriod = utilities.some(u => u.supports_bill_check);
  const billingMode = withPeriod && periodMode() === 'billing';

  // v3.12.0 (M4) — Antworten, Kacheln je Art, Zahlen, Energie gesamt. Die ausführlichen Karten
  // (Effizienz, Einordnung, Tanks, Strom-Saldo, Schaubilder) stehen unter Auswertungen › Kennzahlen,
  // die Empfehlungen und der Zuständig-Filter unter Zu tun.
  container.innerHTML = `
    <div class="section-head">
      <h1>${t('dashboard.title')}</h1>
    </div>

    ${!hasAnyData ? `
    <!-- v2.13.0 (Review UI-32, DOC-31) — Einstieg statt Leerseite: was die App
         tut, die ersten Schritte mit Häkchen aus den Daten, und Beispieldaten
         zum Ausprobieren (vorher sichert der Server den jetzigen Stand) -->
    <section class="card dash-welcome" aria-labelledby="dash-welcome-title">
      <div class="dash-welcome__art">${welcomeArt()}</div>
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
    <div data-role="level-hint"></div>
    ${answers}

    <section class="art-bereich" aria-labelledby="dash-arten-title">
      <div class="art-bereich__kopf">
        <h2 class="card__title" id="dash-arten-title">${escapeHtml(t(billingMode ? 'dashboard.tiles.titleBilling' : 'dashboard.tiles.title'))}</h2>
        ${withPeriod ? `<div class="period-mode-bar dash-period-mode">${periodModeHtml()}</div>` : ''}
      </div>
      <div class="art-raster" data-spalten="4" data-role="art-tiles">
        ${datasets.map(d => artTileHtml(d, { summary, tanks, pvYear })).join('')}
      </div>
    </section>

    ${numbersHtml({ eff, cmp, saldoYear, pvYear })}
    ${whatIfHtml()}

    ${totalKinds.length ? `<div class="card dash-gesamt">
      <h2 class="card__title">${t('dashboard.chart.totalTitle', { months: chartSpan })}</h2>
      <div class="chart-wrap"><canvas id="dash-chart"></canvas></div>
      <p class="fussnote">${escapeHtml(t('dashboard.chart.totalNote'))}</p>
      <div data-role="chart-extra"></div>
    </div>` : ''}
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

  // v3.4.0 (F1024) — Haken in „Zu tun"
  if (hasAnyData) renderTodoBox(container.querySelector('[data-role="todo"]'), todo);
  wirePeriodMode(container);   // v3.6.0 (F1030)
  if (hasAnyData) showMcpProposals(container, settings, todo.proposals);

  // v3.2.0 — Vorschlag zum Hochstufen und „Dein Hebel“ nachladen;
  // beides darf den ersten Inhalt nicht aufhalten
  if (hasAnyData) {
    const later = [];
    const hintBox = container.querySelector('[data-role="level-hint"]');
    if (hintBox) later.push(levelSuggestion(utilities, summary).then(html => { if (hintBox.isConnected) { hintBox.innerHTML = html; bindLevelHint(hintBox); } }).catch(() => {}));
    later.push(loadLeverTile(container, utilities));   // v3.6.0 — „Dein Hebel“, nachgeladen
    // v3.10.0 (GitHub #36) — beim Neuzeichnen am Ort (Stufe, Kalender-/Abrechnungsjahr) erst
    // fertig melden, wenn auch das Nachgeladene steht: sonst stünde nach dem Tausch „Lädt …“
    // da, und was über der Leseposition wächst, schöbe die Seite (Safari hält sie nicht fest)
    if (ctx.inPlace) await Promise.all(later);
  }

  // v2.16.0 (Review FE-17) — kleine Vielfache: je Karte ein eigener Verlauf mit
  // dem Vorjahr; gemeinsam nur, was sich addieren lässt (kWh)
  const charts = hasAnyData
    ? [renderTotalChart(datasets, chartSpan, container), ...renderSparks(datasets, container)]
    : [];

  // Cleanup: destroy charts on next nav (v2.15.0 — ohne window-Global;
  // die Chart-Schicht räumt beim Seitenwechsel ohnehin ab)
  return () => { charts.forEach(c => c?.destroy()); };
}

/** Das Jahr, das die Zahlen zeigen: das laufende, sonst das jüngste. */
export function pickYearRow(yearly) {
  const cy = new Date().getFullYear();
  return (yearly || []).find(y => y.year === cy) ?? (yearly || []).slice(-1)[0] ?? null;
}

/**
 * Tanks und Lager für Kachel und Kennzahlen. Alle parallel; bis v2.10 Tank für Tank nacheinander.
 * @returns {Promise<Array<{utility: object, meter: object, stock: number, cap: number, unit: string}>>}
 */
export async function loadTanks(utilities) {
  const lists = await Promise.all(utilities.filter(u => u.reading_kind === 'delivery').map(async u => {
    try {
      const meters = (await api.meters(u.key)).filter(m => (m.active ?? true) !== false);
      const hist = await Promise.all(meters.map(m => api.stockHistory(u.key, m.id).catch(() => null)));
      return meters.map((m, i) => ({ u, m, sh: hist[i] })).filter(x => x.sh && x.sh.capacity);
    } catch { return []; }
  }));
  return lists.flat().map(({ u, m, sh }) => {
    const days = sh.days || [];
    const stock = days.length ? Number(days[days.length - 1].stock || 0) : 0;
    return { utility: u, meter: m, stock, cap: Number(sh.capacity), unit: sh.capacity_unit || u.volume_unit || 'L' };
  });
}

/** Karte Effizienz (Auswertungen › Kennzahlen). */
export function efficiencyCardHtml(eff, utilities) {
  if (!(eff && Array.isArray(eff.per_source) && eff.per_source.length)) return '';
  // v3.12.0 (M4) — die Sätze zur Rechnung zum Aufklappen: Die Karte steht im Raster neben kürzeren
  const notes = [
    efficiencyGridOnlyText(eff) ? `<p class="muted dash-eff__note" data-role="eff-grid-only">${escapeHtml(efficiencyGridOnlyText(eff))}</p>` : '',
    eff.certificate?.upcoming ? certificateUpcomingHtml(eff, utilities) : '',
    efficiencyIncompleteText(eff) ? `<p class="muted dash-eff__note" data-role="eff-incomplete">${escapeHtml(efficiencyIncompleteText(eff))}</p>` : '',
    eff.scale_note ? `<p class="muted dash-eff__note">${escapeHtml(eff.scale_note)}</p>` : '',
  ].join('');
  return `
      <div class="card dash-insight kennzahl">
        <h2 class="card__title">${icon('medaille')} ${t('dashboard.efficiency.title')} ${eff.year}${info('efficiency')}</h2>
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
        ${eff.area_assumed ? areaAssumedHtml(eff.wohnflaeche_m2) : ''}
        ${eff.certificate ? certificateHtml(eff, utilities) : ''}
        ${notes ? `<details class="dash-eff__mehr" data-role="eff-notes"><summary>${escapeHtml(t('dashboard.efficiency.notes'))}</summary>${notes}</details>` : ''}
      </div>`;
}

/** Karte Tanks und Lager (Auswertungen › Kennzahlen). */
export function tanksCardHtml(tanks, settings) {
  if (!tanks.length) return '';
  return `
      <div class="card dash-insight kennzahl">
        <h2 class="card__title">${icon('art-heizoel')} ${t('dashboard.tanks.title')}${info('tankBook')}</h2>
        ${tanks.map(tk => {
          const pct = tk.cap > 0 ? Math.max(0, Math.min(100, tk.stock / tk.cap * 100)) : 0;
          const cls = tankLevel(pct, settings?.tank_warn_pct);
          return `<div class="dash-tank">
            <div class="dash-tank__label">${artIcon(tk.utility, { size: 16 })} ${escapeHtml(tk.meter.name || tk.utility.label)}
              <span class="muted">${fmt.num(tk.stock,0)} / ${fmt.num(tk.cap,0)} ${tk.unit}</span></div>
            <div class="tank-bar"><div class="tank-bar__fill tank-bar__fill--${cls}" style="width:${pct.toFixed(0)}%"></div></div>
          </div>`;
        }).join('')}
      </div>`;
}

/** Strom-Saldo als Reihe gleicher Zahlen (Auswertungen › Kennzahlen). */
export function stromSaldoHtml(saldoYear, pvYear) {
  if (!saldoYear) return '';
  return `
      <section class="kennzahl-reihe dash-insight-saldo" aria-labelledby="fig-saldo-title">
        <h2 class="card__title" id="fig-saldo-title">${icon('art-strom')} ${t('dashboard.stromSaldo.title')} ${saldoYear.year}</h2>
        <div class="raster dash-strom-saldo" data-zellen="zahl">
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
            <div class="kpi__value ${saldoYear.saldo_netto < 0 ? 'ton-ok' : ''}">${fmt.eur(Math.abs(saldoYear.saldo_netto || 0))}</div>
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
            <div class="kpi__value">${fmt.pct(pvYear.autarkiequote, 0)}</div>
            <div class="kpi__sub">${pvYear.months_covered < 12
              // weniger als ein ganzes Jahr (laufendes Jahr, später Beginn oder
              // ungleiche Abdeckung): sagen, worüber die Quote rechnet
              ? tp('dashboard.stromSaldo.coveredSub', pvYear.months_covered, { months: pvYear.months_covered })
              : t('dashboard.stromSaldo.autarkySub')}</div>
          </div>` : ''}
          ${pvYear && pvYear.eigenverbrauchsquote != null ? `
          <div class="kpi">
            <div class="kpi__label">${t('dashboard.stromSaldo.selfUse')}${info('selfConsumptionRate')}</div>
            <div class="kpi__value">${fmt.pct(pvYear.eigenverbrauchsquote, 0)}</div>
            <div class="kpi__sub">${t('dashboard.stromSaldo.selfUseSub', { kwh: fmt.num(pvYear.eigenverbrauch_kwh, 0) })}</div>
          </div>` : ''}
        </div>
      </section>`;
}

/**
 * v3.12.0 (M4) — Kurzfassung der Kennzahlen als Zahlenreihe, jede führt zur Karte mit der Herleitung.
 * Dieselben Quellen wie die Karten (`efficiency`, `comparison`, `strom-saldo`, `pv-summary`).
 */
export function numbersHtml({ eff, cmp, saldoYear, pvYear }) {
  const tile = (role, label, value, unit, sub, cls = '') => `<a class="zahl-link" href="#/figures" data-role="number-${role}">
        <div class="kpi">
          <div class="kpi__label">${escapeHtml(label)}</div>
          <div class="kpi__value${cls ? ` ${cls}` : ''}">${escapeHtml(value)}${unit ? ` <span class="kpi__unit">${escapeHtml(unit)}</span>` : ''}</div>
          ${sub ? `<div class="kpi__sub">${escapeHtml(sub)}</div>` : ''}
        </div></a>`;
  const out = [];
  const s0 = eff?.per_source?.[0];
  if (s0) {
    out.push(tile('efficiency', t('dashboard.number.efficiency', { year: eff.year, label: s0.label }),
      eff.scale === null ? fmt.num(s0.kwh_per_m2, 0) : (s0.class ?? '–'),
      eff.scale === null ? 'kWh/m²·a' : `${fmt.num(s0.kwh_per_m2, 0)} kWh/m²·a`,
      eff.area_assumed ? t('dashboard.number.areaAssumed') : t('dashboard.efficiency.livingArea', { area: eff.wohnflaeche_m2 })));
  }
  const w = cmp?.supported ? cmp.water : null;
  if (w?.rating) {
    out.push(tile('water', t('dashboard.number.water'), fmt.int(w.liters_per_person_day), t('dashboard.number.waterUnit'),
      t(w.delta_pct > 0 ? 'dashboard.number.above' : 'dashboard.number.below', { pct: fmt.pct(Math.abs(w.delta_pct) / 100, 0) })));
  }
  if (saldoYear) {
    out.push(tile('saldo', t('dashboard.number.netto', { year: saldoYear.year }), fmt.eur(Math.abs(saldoYear.saldo_netto || 0)), '',
      saldoYear.saldo_netto < 0 ? t('dashboard.stromSaldo.nettoEarn') : t('dashboard.stromSaldo.nettoCost'),
      saldoYear.saldo_netto < 0 ? 'ton-ok' : ''));
  }
  if (pvYear && pvYear.autarkiequote != null) {
    out.push(tile('autarky', t('dashboard.stromSaldo.autarky'), fmt.pct(pvYear.autarkiequote, 0), '',
      pvYear.months_covered < 12 ? tp('dashboard.stromSaldo.coveredSub', pvYear.months_covered, { months: pvYear.months_covered }) : t('dashboard.stromSaldo.autarkySub')));
  }
  return `<div class="raster dash-zahlen" data-zellen="zahl" data-role="numbers">${out.join('')}${leverSlotHtml()}</div>`;
}

/**
 * v2.12.0 (Review UI-21) — Was jetzt zu tun ist, mit Sprung dorthin.
 * Termine (fällig/überfällig), Ablesungen älter als
 * `alert_days_since_reading`, Kündigungsfristen und Tanks.
 *
 * v3.1.0 (Paket H1, B5) — die Regeln stehen jetzt einmal im Backend
 * (AgendaService, `due_now`); Kalender-Abo und Home Assistant lesen dieselben
 * Ereignisse. Vertrags- und Bestandseinträge tragen den Text ihrer Empfehlung
 * wie bis v3.0.
 */
/**
 * v3.6.0 — Kachel „Dein Hebel“: dieselbe Zahl wie die Ergebniskarte der Seite
 * „Flexibilität“ (Vorjahr wie dort vorgewählt), vom Server mit einer schlanken
 * Abfrage (`GET /api/flexibility?summary=1`, ohne Nachrechnung je Zähler).
 * Groß der Betrag mit deinem Tarif, klein daneben „bis zu …“ mit dynamischem
 * Tarif, ein Handlungssatz, ein Link zur Seite. Nur mit gemessenen
 * Viertelstunden und einem Hebel; sonst keine Kachel. Die Übersicht wartet
 * nicht darauf: Ein Platzhalter (template, belegt keine Zelle des Rasters)
 * wird ersetzt, sobald die Antwort da ist.
 */
function leverSlotHtml(variant = 'full') {
  return `<template data-role="flex-lever-slot" data-variant="${variant}"></template>`;
}

function loadLeverTile(container, utilities) {
  const slot = container.querySelector('[data-role="flex-lever-slot"]');
  if (!slot || !utilities.some(u => u.key === 'strom')) { slot?.remove(); return null; }
  const opts = { beginner: slot.dataset.variant === 'beginner' };
  return leverTileFor(new Date().getFullYear(), (y) => api.flexibilitySummary(y), opts).then(html => {
    if (!slot.isConnected) return;
    if (html) slot.outerHTML = html; else slot.remove();
  }).catch(() => { slot.remove(); });
}

/**
 * v3.12.0 — Kachel des Vorjahres; ergibt das Vorjahr keine Zahl, die des Jahres davor (der Titel
 * nennt das Jahr). Ohne Viertelstunden über ein ganzes Jahr, etwa im Beispielhaushalt, der nur die
 * letzten Monate fortschreibt, fehlte die Kachel sonst ganz.
 * @param {number} now  laufendes Jahr
 * @param {(year: number) => Promise<{summary?: object|null}>} summaryOf
 */
export async function leverTileFor(now, summaryOf, opts = {}) {
  for (const year of [now - 1, now - 2]) {
    const html = leverTileHtml((await summaryOf(year))?.summary, opts);
    if (html) return html;
  }
  return '';
}

/** @param {object|null} s `summary` aus GET /api/flexibility?summary=1 */
export function leverTileHtml(s, { beginner = false } = {}) {
  if (!s?.measured || !(s.eur >= 1)) return '';
  const vat = fmt.pct((s.vat_pct ?? 19) / 100, 0);
  const cls = beginner ? 'card dash-answer dash-lever' : 'card dash-insight dash-lever';
  return `<section class="${cls}" data-role="flex-lever" aria-labelledby="dash-lever-title">
        <div class="card__head">
          <h2 class="card__title" id="dash-lever-title">${icon('stoppuhr')} ${escapeHtml(t('dashboard.lever.title', { year: s.year }))}</h2>
          <a class="btn btn--ghost btn--sm" href="#/flexibility?year=${Number(s.year)}" aria-label="${escapeHtml(t('dashboard.lever.linkLabel'))}">${escapeHtml(t('dashboard.lever.link'))}</a>
        </div>
        <p class="dash-lever__figure"><span class="dash-lever__big">${escapeHtml(fmt.eur0(s.eur))}</span>
          <span class="dash-lever__per">${escapeHtml(t(s.real ? 'dashboard.lever.real' : 'dashboard.lever.dynamic', { vat }))}</span></p>
        ${s.dynamic_eur != null ? `<p class="kpi__sub dash-lever__upto">${escapeHtml(t('dashboard.lever.upTo', { eur: fmt.eur0(s.dynamic_eur) }))}</p>` : ''}
        ${s.action?.window ? `<p class="dash-lever__action">${escapeHtml(actionText(s.action))}</p>` : ''}
      </section>`;
}

/** v3.6.0 (F1031) — Einstieg in die Prognose-Werkstatt: „Was wäre wenn? Probier es aus“. */
function whatIfHtml() {
  return `<section class="card dash-whatif" aria-labelledby="dash-whatif-title" style="margin-top: var(--sp-5)">
      <div>
        <h2 class="card__title" id="dash-whatif-title">${icon('kolben')} ${escapeHtml(t('dashboard.whatIf.title'))}</h2>
        <p class="dash-whatif__text">${escapeHtml(t('dashboard.whatIf.text'))}</p>
      </div>
      <a class="btn btn--primary" href="#/forecast" data-role="whatif-link">${escapeHtml(t('dashboard.whatIf.button'))}</a>
    </section>`;
}

/**
 * Zeilen von „Zu tun“ aus den fälligen Ereignissen. v3.7.0 — `keep` filtert
 * die Ereignisse vorab (Zuständigkeit), damit auch die Sammelzeile der
 * Ablesungen nur zählt, was passt; `items.source` hält die Quelle für den Filter.
 */
export function buildTodo(events, recs, keep = () => true) {
  // v3.12.0 (M4) — zur Sicht, in der die Sache steht; Adressen in API, Kalender und Home Assistant bleiben
  const inSight = (href, view) => (String(href || '').startsWith('#/utility/') ? `${href}${href.includes('?') ? '&' : '?'}view=${view}` : href);
  const items = [];
  const due = (events || []).filter(e => e.due_now && keep(e));
  items.source = { events: events || [], recs: recs || [] };
  const recById = new Map((recs || []).map(r => [r.id, r]));
  // v3.4.0 (F1024) — `ev`: das Ereignis, das der Haken erledigt (can_mark_done)
  for (const e of due.filter(x => x.kind === 'reminder')) {
    const d = e.ref?.days_until;
    const when = d == null ? fmt.date(e.date)
      : d < 0 ? tp('dashboard.reminders.overdueDays', -d)
      : d === 0 ? t('dashboard.reminders.now') : tp('dashboard.reminders.inDaysN', d);
    items.push({ icon: 'merker', tone: e.severity === 'overdue' ? 'alert' : 'warn', text: e.params?.title ?? e.title, sub: when,
      href: e.href, action: t('dashboard.todo.reminderAction'), ev: e });
  }
  // Bis zu zwei Zähler einzeln (mit Sprung zur Karte), mehr als eine Zeile —
  // sonst schiebt ein monatlicher Ablesetag die ganze Übersicht nach unten
  const readings = due.filter(x => x.kind === 'reading_due');
  if (readings.length > 2) {
    // v3.12.0 — Art und Zähler wie in der Einzelzeile; bis dahin nur der Name („Hauptzähler, Hauptzähler, …“)
    const names = readings.map(e => [e.params?.label, e.params?.meter].filter(Boolean).join(' · '));
    items.push({ icon: 'klemmbrett', tone: 'warn', text: tp('dashboard.todo.readingsDue', readings.length),
      sub: names.slice(0, 3).join(', ') + (names.length > 3 ? ' …' : ''),
      href: '#/zaehlerstaende', action: t('dashboard.todo.readingAction') });
  } else {
    for (const e of readings) {
      const days = e.ref?.days_since_reading;
      items.push({ icon: 'klemmbrett', tone: 'warn', text: `${e.params?.label} · ${e.params?.meter}`,
        sub: days == null ? t('dashboard.todo.readingNever') : tp('dashboard.todo.readingDue', days),
        href: e.href, action: t('dashboard.todo.readingAction') });
    }
  }
  for (const e of due.filter(x => ['cancel_by', 'term_end', 'tank_reorder'].includes(x.kind))) {
    const isTank = e.kind === 'tank_reorder';
    const rec = recById.get(e.ref?.recommendation_id);
    // v3.4.0 (F1024) — Folgevertrag mit Lücke: die Lücke steht darunter
    const gap = Number(e.ref?.successor?.gap_days) || 0;
    items.push({ icon: isTank ? 'art-heizoel' : 'dokument', tone: e.severity === 'urgent' ? 'alert' : 'warn', text: rec?.title ?? e.title,
      sub: gap > 0 ? tp('dashboard.todo.successorGap', gap, { date: fmt.date(e.ref.successor.start) }) : '',
      recId: e.ref?.recommendation_id, href: e.href, ev: e,
      action: t(isTank ? 'dashboard.todo.tankAction' : 'dashboard.todo.contractAction') });
  }
  // v3.4.0 (F1024) — Preiserhöhung, Einwand- und CO₂-Frist: jetzt abhakbar, also auch hier
  for (const e of due.filter(x => ['price_increase', 'objection_deadline', 'co2_claim_deadline'].includes(x.kind))) {
    const rec = recById.get(e.ref?.recommendation_id);
    const isContract = e.kind === 'price_increase';
    items.push({ icon: isContract ? 'dokument' : 'beleg', tone: e.severity === 'urgent' ? 'alert' : 'warn', text: rec?.title ?? e.title,
      recId: e.ref?.recommendation_id, href: e.href, ev: e,
      action: t(isContract ? 'dashboard.todo.contractAction' : 'dashboard.todo.tenancyAction') });
  }
  // v3.5.0 (F1029) — Abrechnung erwartet bzw. überfällig: Sprung zum Erfassen, abhakbar („kam ohne Erfassung“).
  // Eine gesetzliche Frist gibt es nur für Strom und Gas (§ 40c Abs. 2 EnWG) — sonst sagt die Zeile das
  for (const e of due.filter(x => x.kind === 'bill_expected')) {
    const deadline = e.ref?.deadline;
    items.push({ icon: 'beleg', tone: e.severity === 'overdue' ? 'alert' : 'warn', text: e.title,
      sub: deadline ? t(e.ref?.overdue ? 'dashboard.todo.billOverdueSince' : 'dashboard.todo.billDeadline', { date: fmt.date(deadline) })
        : t('dashboard.todo.billNoDeadline'),
      href: e.href, ev: e, action: t('dashboard.todo.billAction') });
  }
  // v3.11.0 (F1035) — Deine Rechte als Kunde: Titel des Befunds (wie die Empfehlung), mit Frist darunter; abhakbar
  for (const e of due.filter(x => x.kind === 'rights')) {
    const rec = recById.get(e.ref?.recommendation_id);
    items.push({ icon: 'waage', tone: e.severity === 'urgent' || e.severity === 'overdue' ? 'alert' : 'warn', text: rec?.title ?? e.title,
      sub: e.ref?.deadline ? t('rights.deadline', { date: fmt.date(e.ref.deadline) }) : '',
      recId: e.ref?.recommendation_id, href: e.href, ev: e, action: t('dashboard.todo.rightsAction') });
  }
  // v3.11.0 (D, #54) — Verträge widersprechen sich: Titel der Empfehlung, Sprung zu den Verträgen (dort Ende setzen oder „Beides stimmt“)
  for (const e of due.filter(x => x.kind === 'contract_conflict')) {
    const rec = recById.get(e.ref?.recommendation_id);
    items.push({ icon: 'dokument', tone: 'warn', text: rec?.title ?? e.title, sub: rec?.detail ?? '',
      recId: e.ref?.recommendation_id, href: e.href, ev: e, action: t('dashboard.todo.contractAction') });
  }
  // v3.9.0 (#38) — Heizgrenze anpassen: Vorher/Nachher und Wirkung, „Übernehmen“ und „Nicht jetzt“
  for (const e of due.filter(x => x.kind === 'heat_limit')) {
    const r = e.ref || {};
    const h = { best: r.best, current: r.current, own: r.own, range: r.range, before: r.before, after: r.after, effect: r.effect };
    // je Satz eine Zeile (am Handy sonst ein Block): Zähler, vorher/nachher, Wirkung
    const lines = [`${e.params?.label ?? ''} · ${e.params?.meter ?? ''}`, ...heatLimitFacts(h, r.unit || 'kWh'), ...heatLimitEffect(h)];
    items.push({ icon: 'art-fernwaerme', tone: 'warn', text: t('heatLimit.title', { best: fmt.num(Number(r.best), 1) }),
      sub: lines.join(' '), subLines: lines,
      // v3.12.0 (M4) — der Saldo in dieser Zeile in Farbe, wie an der Karte
      htmlLines: [escapeHtml(lines[0]), ...heatLimitFacts(h, r.unit || 'kWh').map(escapeHtml), ...heatLimitEffect(h, { html: true })],
      // v3.9.0 (Skeptiker N4) — Einsteiger: kurz, ohne Fachwörter; das Übrige aufklappbar
      short: { text: t('heatLimit.beginner.title', { label: e.params?.label ?? '' }), subLines: heatLimitBeginnerLines(h), htmlLines: heatLimitBeginnerLines(h, { html: true }) },
      // v3.9.0 (Skeptiker R2-5) — steht in „Zu tun“, also nicht noch einmal unter „Top-Empfehlungen“
      recId: r.recommendation_id,
      href: inSight(e.href, 'balance'), ev: e, action: t('dashboard.todo.heatLimitAction'), heatLimit: { ...h, utility: r.utility, id: r.meter_id, recId: r.recommendation_id } });
  }
  // v3.9.0 (ablesung-pruefen) — Ablesung prüfen (gemessen gegen erwartet davor und danach;
  // „Stand ist richtig“, „Nur für die Abrechnung“, „Korrigieren“), doppelt eingetragen
  // („Eine löschen“) und der Hinweis auf die Analyse-Zäsur („Zäsur setzen“, „Nicht jetzt“)
  const meterLine = (e) => `${e.params?.label ?? ''} · ${e.params?.meter ?? ''}`;
  // „doppelt“ vor „prüfen“: erst die Doppel klären (Skeptiker M1)
  for (const e of due.filter(x => x.kind === 'reading_duplicate')) {
    const r = e.ref || {};
    const lines = [meterLine(e), t('readingCheck.duplicate.sub', { counter: `${fmt.num(Number(r.counter), 1)} ${r.unit || ''}`.trim() })];
    items.push({ icon: 'klemmbrett', tone: 'warn', text: t('readingCheck.duplicate.title', { date: fmt.date(r.date) }), sub: lines.join(' '), subLines: lines,
      href: inSight(e.href, 'readings'), ev: e, action: t('dashboard.todo.readingCheckAction'),
      readingCheck: { kind: 'duplicate', utility: r.utility, meterId: r.meter_id, ids: r.delete_ids || [] } });
  }
  for (const e of due.filter(x => x.kind === 'reading_check')) {
    const r = e.ref || {};
    const lines = [meterLine(e), ...readingCheckLines(r)];
    items.push({ icon: 'suche', tone: 'warn', text: readingCheckTitle(r), sub: lines.join(' '), subLines: lines,
      href: inSight(e.href, 'readings'), ev: e, action: t('dashboard.todo.readingCheckAction'),
      readingCheck: { kind: 'check', utility: r.utility, meterId: r.meter_id, ids: r.reading_ids || [], count: Number(r.count) || 1 } });
  }
  for (const e of due.filter(x => x.kind === 'level_shift')) {
    const r = e.ref || {};
    const lines = [meterLine(e), ...levelShiftLines(r)];
    items.push({ icon: r.direction === 'higher' ? 'verlaufskurve' : 'verlaufskurve', tone: 'warn', text: levelShiftTitle(r), sub: lines.join(' '), subLines: lines,
      href: e.href, ev: e, action: t('dashboard.todo.readingCheckAction'),
      readingCheck: { kind: 'level', utility: r.utility, meterId: r.meter_id, date: r.date, basis: r.basis } });
  }
  // Überfälliges zuerst
  const sorted = items.sort((a, b) => (a.tone === 'alert' ? 0 : 1) - (b.tone === 'alert' ? 0 : 1));
  sorted.source = items.source;
  return sorted;
}

/**
 * Zeilen von „Zu tun". Die ganze Zeile ist der Link (am iPhone ein Tippziel
 * statt eines umbrechenden Knopfs unter dem Text); v3.4.0 (F1024) — daneben
 * der Haken, wo sich der Hinweis von Hand erledigen lässt.
 */
function todoItemsHtml(items, { go = true, beginner = false } = {}) {
  // v3.9.0 (ablesung-pruefen) — der Hinweis auf die Zäsur hat „Nicht jetzt“ statt des Hakens
  const checkable = (x) => x.ev?.can_mark_done && !x.readingCheck;
  return items.map((x, i) => {
    // v3.9.0 (Skeptiker N4) — Einsteiger: die kurze Fassung, die Fachzeilen aufklappbar unter den Knöpfen
    const short = beginner && x.short ? x.short : null;
    const text = short ? short.text : x.text;
    const subLines = short ? short.subLines : x.subLines;
    const htmlLines = short ? short.htmlLines : x.htmlLines;
    return `<li class="dash-todo__item dash-todo__item--${x.tone}${checkable(x) ? ' dash-todo__item--check' : ''}">
    <a class="dash-todo__link" href="${x.href}">
      <span class="dash-todo__icon" aria-hidden="true">${icon(x.icon, { size: 20 })}</span>
      <span class="dash-todo__text"><strong>${escapeHtml(text)}</strong>${htmlLines ? htmlLines.map(s => `<span class="muted">${s}</span>`).join('') : subLines ? subLines.map(s => `<span class="muted">${escapeHtml(s)}</span>`).join('')
        : x.sub ? `<span class="muted">${escapeHtml(x.sub)}</span>` : ''}${x.ev ? chipHtml(x.ev) : ''}</span>
      ${go ? `<span class="btn btn--sm btn--ghost dash-todo__go">${escapeHtml(x.action)}</span>` : ''}
      <span class="dash-todo__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span>
    </a>
    ${checkable(x) ? `<button type="button" class="btn btn--sm btn--ghost dash-todo__done" data-todo-done="${i}"
      title="${escapeHtml(t('activity.markDone'))}" aria-label="${escapeHtml(t('activity.markDoneNamed', { title: text }))}">${icon('haken')}</button>` : ''}
    ${x.heatLimit ? heatLimitActionsHtml(x, i) : ''}
    ${short ? `<details class="dash-todo__more" data-role="todo-more"><summary>${escapeHtml(t('heatLimit.beginner.more'))}</summary>
      <p class="muted small"><strong>${escapeHtml(x.text)}.</strong> ${(x.subLines || []).map(escapeHtml).join(' ')}</p></details>` : ''}
    ${x.readingCheck ? readingCheckActionsHtml(x, i) : ''}
  </li>`;
  }).join('');
}

/**
 * v3.9.0 (ablesung-pruefen) — Knöpfe unter „Ablesung prüfen“, „doppelt eingetragen“ und
 * dem Hinweis auf die Zäsur (in jeder Stufe, wie beim Heizgrenze-Vorschlag).
 */
function readingCheckActionsHtml(x, i) {
  const rc = x.readingCheck;
  const attrs = `class="dash-todo__actions" data-role="reading-check-todo" data-kind="${escapeHtml(rc.kind)}" data-meter="${escapeHtml(String(rc.meterId ?? ''))}"`;
  if (rc.kind === 'check') {
    return `<div ${attrs}>
      <button type="button" class="btn btn--sm btn--primary" data-rc-verify="${i}">${escapeHtml(tp('readingCheck.verify', rc.count))}</button>
      <button type="button" class="btn btn--sm btn--ghost" data-rc-billing="${i}" title="${escapeHtml(t('readingCheck.billingOnlyHint'))}">${escapeHtml(t('readingCheck.billingOnly'))}</button>
      <a class="btn btn--sm btn--ghost" data-rc-correct="${i}" href="${escapeHtml(correctHref(rc.utility, rc.meterId, rc.ids[0] || ''))}">${escapeHtml(t('readingCheck.correct'))}</a>
    </div>`;
  }
  if (rc.kind === 'duplicate') {
    return `<div ${attrs}>
      <button type="button" class="btn btn--sm btn--ghost" data-rc-delete="${i}">${escapeHtml(t('readingCheck.duplicate.delete'))}</button>
    </div>`;
  }
  return `<div ${attrs}>
      <button type="button" class="btn btn--sm btn--primary" data-rc-baseline="${i}">${escapeHtml(t('levelShift.set'))}</button>
      <button type="button" class="btn btn--sm btn--ghost" data-rc-later="${i}">${escapeHtml(t('levelShift.notNow'))}</button>
    </div>`;
}

/**
 * v3.9.0 (Skeptiker R2-6) — der Satz zur Effizienz-Kachel, wenn eine Quelle kein ganzes Jahr hat:
 * mit den Namen genau dieser Quellen. Bis dahin stand der allgemeine Satz des Servers („… keine
 * Klasse“) unter allen Zeilen, auch unter Gas mit Klasse. Leer, wenn alle Quellen vollständig sind.
 */
/**
 * v3.10.0 (Skeptiker N10) — Wärmepumpe vorn in der Kaskade: Die Effizienz zählt nur den Strom aus dem
 * Netz, Jahresarbeitszahl und Energiefluss auch den selbst genutzten Sonnenstrom (`grid_only`).
 */
export function efficiencyGridOnlyText(eff) {
  return (eff?.per_source || []).some(s => s.grid_only) ? t('dashboard.efficiency.gridOnly') : '';
}

export function efficiencyIncompleteText(eff) {
  const names = (eff?.per_source || []).filter(s => s.complete === false).map(s => s.label).filter(Boolean);
  return names.length ? t('dashboard.efficiency.incompleteFor', { names: names.join(', '), year: eff.year }) : '';
}

/** v3.9.0 (#38) — „Übernehmen (nur dieser Zähler)“ und „Nicht jetzt“ unter dem Heizgrenze-Eintrag. */
function heatLimitActionsHtml(x, i) {
  const group = isGroupId(x.heatLimit.id);
  return `<div class="dash-todo__actions" data-role="heat-limit-todo" data-meter="${escapeHtml(String(x.heatLimit.id ?? ''))}">
      <button type="button" class="btn btn--sm btn--primary" data-heat-apply="${i}">${escapeHtml(t(group ? 'heatLimit.applyGroup' : 'heatLimit.apply'))}</button>
      <button type="button" class="btn btn--sm btn--ghost" data-heat-later="${i}" title="${escapeHtml(t('heatLimit.notNowHint'))}">${escapeHtml(t('heatLimit.notNow'))}</button>
    </div>`;
}

/**
 * v3.5.0 (F1028) — `f`: Filter nach Zuständigkeit {filter, names, hidden};
 * mit Filter bleibt die Karte auch ohne passende Zeile stehen (umschaltbar).
 */
function todoHtml(items, f = null, o = {}) {
  // v3.12.0 (M2) — die Seite Zu tun: ohne Überschrift (sie hat ihre eigene), mit weiteren Zeilen davor und danach
  const rows = `${o.before || ''}${todoItemsHtml(items, { beginner: !!o.beginner })}${o.after || ''}`;
  if (!rows.trim() && !f) return o.empty || '';
  const head = o.page
    ? (f ? `<div class="dash-todo__head dash-todo__head--filter">${filterHtml({ id: 'todo-assignee', value: f.filter, names: f.names })}</div>` : '')
    : `<div class="dash-todo__head">
        <h2 class="card__title" id="dash-todo-title">${icon('aufgaben')} ${escapeHtml(t('dashboard.todo.title'))}</h2>
        ${f ? filterHtml({ id: 'todo-assignee', value: f.filter, names: f.names }) : ''}
      </div>`;
  return `
    <section class="card dash-todo" ${o.page ? `aria-label="${escapeHtml(t('todo.title'))}"` : 'aria-labelledby="dash-todo-title"'}>
      ${head}
      ${rows.trim() ? `<ul class="dash-todo__list">${rows}</ul>`
        : `<p class="muted" data-role="assignee-empty">${escapeHtml(t('assignee.emptyFiltered'))}</p>`}
      ${f ? filterNoteHtml(f.hidden, f.filter) : ''}
    </section>`;
}

/**
 * Auszug auf der Übersicht: höchstens vier Zeilen, ohne Knopf „Zum …". Einsteiger bekommen dazu die kurze
 * Fassung ohne Fachwörter. v3.12.0 (M4) — in allen Stufen; alles Weitere steht unter Zu tun.
 */
function excerptTodoHtml(items, beginner = true) {
  const shown = excerptItems(items);
  // v3.12.0 — „Noch n weitere“ zählt wie „Zu tun“ und die Seitenleiste (`total` = todo.js offenCount: auch
  // Empfehlungen, Post und Vorschläge der KI); bis dahin nur die Fristen und Termine des Auszugs
  const total = Number.isFinite(items.total) ? Math.max(items.total, items.length) : items.length;
  const more = total - shown.length;
  // v3.12.0 — eine Quelle hat nicht geantwortet: die Fehlerzeile, nie „Gerade nichts. Gut so.“
  const failed = items.failed ? failedLineHtml(items.failed) : '';
  const list = shown.length ? `<ul class="dash-todo__list">${todoItemsHtml(shown, { go: false, beginner })}</ul>` : '';
  const moreHtml = more <= 0 ? ''
    : `<p class="muted small" data-role="todo-more">${escapeHtml(shown.length ? tp('dashboard.todo.more', more) : tp('dashboard.todo.waiting', more))}</p>`;
  return failed || list || moreHtml ? `${failed}${list}${moreHtml}`
    : `<p class="muted">${escapeHtml(t('dashboard.beginner.todo.none'))}</p>`;
}

/**
 * v3.12.0 — die Zeilen von „Zu tun“ für den Auszug, aus derselben Ladung wie die Seite „Zu tun“ und die
 * Seitenleiste (todo.js loadOffen). Dazu `total` (die Zahl unter „Alle“, offenCount) und `failed` (der erste
 * Fehler einer Quelle, sonst null). todo.js lädt erst hier, wie in der Seitenleiste (es importiert diese Datei).
 */
async function loadTodo(settings = getSettingsSync() || {}) {
  const { loadOffen, offenCount } = await import('./todo.js');
  // Vorschläge der KI nur, wenn sie in der App bestätigt werden (sonst gibt es keine, und es bleibt beim Abruf ohne Anlass)
  const d = await loadOffen({ proposals: wantsProposals(settings) });
  const todo = d.todo;
  todo.total = offenCount(d);
  todo.failed = d.failed ? (d.error || new Error('')) : null;
  todo.proposals = d.proposals;
  return todo;
}
const wantsProposals = (settings) => settings?.mcp_enabled === true && settings?.mcp_apply_in_app !== false;

/** Höchstens so viele Zeilen im Auszug auf der Übersicht. */
export const EXCERPT_ROWS = 4;

/**
 * v3.12.0 (m4b) — die Zeilen des Auszugs: Überfälliges zuerst, dann Fristen aus „Deine Rechte“, dann der Rest,
 * je in der Reihenfolge der Liste. Eine Rechte-Zeile fällt nie heraus, auch wenn vier überfällige Termine davor
 * stehen: Sie nimmt den letzten Platz. Bis dahin zeigte der Auszug die ersten vier Zeilen der Liste, und im
 * Schaufenster fiel die Sonderkündigung mit ihrer Frist hinter Wartungsterminen heraus.
 */
export function excerptItems(items, max = EXCERPT_ROWS) {
  const rank = (x) => (x.tone === 'alert' ? 0 : x.ev?.kind === 'rights' ? 1 : 2);
  const sorted = (items || []).map((x, i) => [x, i]).sort((a, b) => rank(a[0]) - rank(b[0]) || a[1] - b[1]).map(([x]) => x);
  const out = sorted.slice(0, max);
  const right = sorted.find(x => x.ev?.kind === 'rights');
  if (right && !out.includes(right) && max > 0) out[max - 1] = right;
  return out;
}

/**
 * v3.4.0 (F1024) — „Zu tun" in seinen Kasten zeichnen und die Haken binden:
 * kleiner Dialog (Datum, Notiz, Beleg), danach ist die Zeile weg; „Rückgängig"
 * im Hinweis holt sie zurück.
 */
export function renderTodoBox(box, items, o = {}) {
  if (!box?.isConnected) return;
  const full = !['beginner', 'excerpt'].includes(box.dataset.variant);
  // v3.5.0 (F1028) — Filter nach Zuständigkeit (volle Übersicht; je Gerät gemerkt)
  // v3.7.0 — über die Ereignisse (auch geerbte Zuständigkeit, auch Ablesungen in der Sammelzeile)
  const filter = storedFilter();
  const src = items.source;
  const due = src ? src.events.filter(e => e.due_now) : items.map(x => x.ev).filter(Boolean);
  const keep = (e) => matchesAssignee(e || {}, filter);
  const withFilter = full && items.length > 0 && (due.some(e => assigneeOf(e)) || filter !== 'all');
  const shown = !withFilter ? items : src ? buildTodo(src.events, src.recs, keep) : items.filter(x => keep(x.ev));
  const hidden = src ? due.filter(e => !keep(e)).length : items.length - shown.length;
  box.innerHTML = full
    ? todoHtml(shown, withFilter ? { filter, names: assigneeNames(due), hidden } : null, o)
    : excerptTodoHtml(items, box.dataset.variant === 'beginner');
  if (withFilter) bindFilter(box, () => renderTodoBox(box, items, o));
  const refresh = o.refresh || (() => refreshTodo(box));
  o.onDraw?.(box);
  box.querySelectorAll('[data-todo-done]').forEach(btn => btn.addEventListener('click', async () => {
    const x = (full ? shown : excerptItems(items))[Number(btn.dataset.todoDone)];
    if (!x?.ev) return;
    // v3.10.0 — vor dem Fälligkeitstag erledigt: Danke-Hinweis unter der Liste
    if (await markEventDone(x.ev, { title: x.text, onChange: refresh, thanksAfter: box })) { btn.closest('li')?.remove(); o.afterChange?.(); }
  }));
  // v3.9.0 (#38) — Heizgrenze übernehmen (mit Rückgängig) oder für diesen Datenstand ausblenden
  // v3.12.0 (m4b) — im Auszug die Zeilen in seiner Reihenfolge (excerptItems), sonst träfe der Knopf eine andere Zeile
  const list = full ? shown : excerptItems(items);
  box.querySelectorAll('[data-heat-apply]').forEach(btn => btn.addEventListener('click', async () => {
    const h = list[Number(btn.dataset.heatApply)]?.heatLimit;
    if (!h) return;
    btn.disabled = true;
    // v3.9.0 (Skeptiker R2-5) — die Grenze ändert Saldo, Empfehlungen und „Wohin geht mein Geld?“:
    // nach dem Übernehmen und nach „Rückgängig“ die ganze Übersicht neu, nicht nur „Zu tun“
    // (beim „Rückgängig“ ist dieser Kasten schon ersetzt: maßgeblich ist, ob die Übersicht noch zu sehen ist)
    const again = () => (dashRoot?.isConnected && dashRoot.querySelector('[data-role="todo"]') ? render(dashRoot) : refresh());
    if (!await applyHeatLimit({ utility: h.utility, id: h.id, value: h.best, previous: h.own ? h.current : null, onChange: again })) btn.disabled = false;
  }));
  box.querySelectorAll('[data-heat-later]').forEach(btn => btn.addEventListener('click', async () => {
    const h = list[Number(btn.dataset.heatLater)]?.heatLimit;
    if (!h?.recId) return;
    btn.disabled = true;
    try {
      // bis die Daten sich ändern: die Empfehlung trägt den Datenstand in ihrer ID; ein Jahr als Obergrenze
      await api.dismissRecommendation(h.recId, heatLimitLaterUntil());
      btn.closest('li')?.remove();
      o.afterChange?.();
      toastUndo(t('heatLimit.dismissed'), async () => {
        try { await api.restoreRecommendation(h.recId); await refresh(); } catch (e) { toastErr(e.message); }
      });
      window.dispatchEvent(new CustomEvent('et:badges-refresh'));
    } catch (e) { toastErr(e.message); btn.disabled = false; }
  }));
  // v3.9.0 (ablesung-pruefen) — die Knöpfe der Prüfung; jeder mit Rückgängig
  const act = (sel, fn) => box.querySelectorAll(`[${sel}]`).forEach(btn => btn.addEventListener('click', async () => {
    const rc = list[Number(btn.getAttribute(sel))]?.readingCheck;
    const ev = list[Number(btn.getAttribute(sel))]?.ev;
    if (!rc) return;
    btn.disabled = true;
    if (!await fn(rc, ev)) btn.disabled = false;
  }));
  act('data-rc-verify', (rc) => markReadings({ utility: rc.utility, ids: rc.ids, flag: 'is_verified', onChange: refresh }));
  act('data-rc-billing', (rc) => markReadings({ utility: rc.utility, ids: rc.ids, flag: 'is_billing_only', onChange: refresh }));
  act('data-rc-delete', (rc) => deleteDuplicates({ utility: rc.utility, meterId: rc.meterId, ids: rc.ids, onChange: refresh }));
  act('data-rc-baseline', (rc) => setBaselineAt({ utility: rc.utility, meterId: rc.meterId, date: rc.date, basis: rc.basis, onChange: refresh }));
  act('data-rc-later', (rc, ev) => ev ? dismissLevelShift({ event: ev, onChange: refresh }) : false);
}

async function refreshTodo(box) {
  try {
    // v3.12.0 — dieselbe Ladung wie beim ersten Zeichnen (Zahl wie „Zu tun“, Fehler als Fehler)
    renderTodoBox(box, await loadTodo());
  } catch { /* der alte Stand bleibt stehen */ }
  window.dispatchEvent(new CustomEvent('et:badges-refresh'));
}

/** v3.6.0 (Paket abrechnung) — Perioden je Zählergruppe mit Mitgliedern (Map Gruppe → Perioden). */
async function groupPeriods(utilityKey, consumption) {
  const memberOf = new Set((consumption?.meters || []).map(e => e.meter?.meter_group_id).filter(Boolean));
  const groups = (consumption?.meter_groups || []).filter(g => memberOf.has(g.id));
  if (!groups.length) return null;
  const lists = await Promise.all(groups.map(g => api.groupBillingPeriods(utilityKey, g.id).then(d => d?.periods || []).catch(() => [])));
  return new Map(groups.map((g, i) => [g.id, lists[i]]));
}

/**
 * v3.12.0 (M4) — Kachel einer Verbrauchsart: feste Plätze für Kopf, Zeitraum, zwei Werte mit Trend,
 * Spur und Fuß. Rechnung wie bis v3.11 (renderUtilityCard); jede Einzelheit der alten Karte steht
 * hier (Liste in umsetzung-m4.md), dazu neu der Fuß mit letzter Ablesung oder Füllstand.
 */
export function artTileHtml({ utility, consumption, billing = null, groups = null }, { summary = null, tanks = [], pvYear = null } = {}) {
  const monthly = consumption?.monthly_total || [];
  const sumKey = utility.consumption_unit === 'kWh' ? 'kwh' : 'm3';
  // v2.15.0 (Review FE-08) — Jede Karte nennt ihr Fenster: Bei Gas endete es
  // im März, bei Heizöl im September, bei Fernwärme im Dezember — unter
  // derselben Überschrift. Der Trend vergleicht dieselben vollen Monate des
  // Vorjahres; bis v2.14 stand ein halber März gegen einen ganzen.
  const win = lastMonths(monthly, 12);
  const last12 = win?.rows || [];
  // v3.6.0 (F1030) — im Abrechnungsjahr die laufende Periode der Art mit
  // ihrem Stichtag (Summen vom Server, ohne Subzähler), benannt; ohne Trend
  const totalCons = billing ? Number(billing[sumKey]) || 0 : last12.reduce((s, m) => s + (m[sumKey] || 0), 0);
  // v3.7.0 (GitHub #33) — Kosten ohne Bonus wie die Kachel der Verbrauchsart;
  // der Bonus als eigene Zeile, der Trend ebenfalls ohne Bonus (ein Bonus im
  // Vorjahr ließe das Jahr danach sonst teurer aussehen). Einspeisung unverändert.
  const withBonus = !isFeedIn(utility) && !isGeneration(utility);
  const bonus = withBonus ? (billing ? Number(billing.bonus) || 0 : bonusOf(last12)) : 0;
  const totalCost = (billing ? Number(billing.cost) || 0 : last12.reduce((s, m) => s + (m.cost  || 0), 0)) + bonus;
  const bonusText = withBonus ? bonusLineText(billing ? billing.bonus_items : bonusItemsOf(last12), bonus) : '';
  const costRows = withBonus ? monthly.map(m => ({ ...m, cost_without_bonus: (Number(m.cost) || 0) + (Number(m.bonus_eur) || 0) })) : monthly;
  const within = last12.map(m => m.ym);
  // v3.10.0 (GitHub #36) — im Abrechnungsjahr dieselbe Spanne der Periode davor (Server);
  // bis v3.9 stand dort kein Pfeil, ein angefangenes Jahr gegen ein ganzes wäre unfair
  const spanCost = (s) => (Number(s.cost) || 0) + (withBonus ? Number(s.bonus) || 0 : 0);
  const trendCons = billing ? sameSpanTrend(billing, s => s[sumKey]) : yoyTrend(monthly, sumKey, { months: 12, minMonths: 6, within });
  const trendCost = billing
    ? (billing.price_missing || billing.prev_same_span?.price_missing ? null : sameSpanTrend(billing, spanCost))
    : yoyTrend(costRows, withBonus ? 'cost_without_bonus' : 'cost', { months: 12, minMonths: 6, within });
  // v3.10.0 (Skeptiker N3) — ohne Namen nur die Daten, sonst stünden sie doppelt da
  const win2 = billing ? periodWindow(billing, fmt.date) : null;
  const windowText = billing
    ? t(win2.key, win2.params)
      + (billing.as_of && billing.as_of < billing.until ? ' · ' + t('billing.period.asOfReading', { date: fmt.date(addDaysIso(billing.as_of, 1)) }) : '')
    : win ? t('dashboard.card.window', { from: fmt.month(win.from), to: fmt.month(win.to) })
    + (win.partialLast ? ' · ' + t('dashboard.card.partial', { month: fmt.month(win.to), days: win.lastDays, total: win.lastTotal }) : '') : '';
  const noContract = totalCost === 0;                     // D — kein Vertrag/keine Kosten
  // v2.13.0 (Review FE-06) — Einspeisung ist ein Erlös, Erzeugung hat weder
  // Kosten noch Vertrag; bei beiden ist mehr besser (Trendfarbe)
  const feedIn = isFeedIn(utility);
  const generation = isGeneration(utility);
  const better = moreIsBetter(utility);
  // v3.10.0 (GitHub #36) — im Abrechnungsjahr zählen die Kacheln seit dessen Beginn, nicht 12 Monate
  // (Einspeisung und Erzeugung haben kein Abrechnungsjahr); ganz gerechnet „im Abrechnungsjahr“
  const sofar = !!billing && !!billing.as_of && billing.as_of < billing.until;
  const valueKey = feedIn ? 'feedIn' : generation ? 'generation' : !billing ? 'consumption' : sofar ? 'consumptionSoFar' : 'consumptionPeriod';
  const costKey = feedIn ? 'revenue' : !billing ? 'cost' : sofar ? 'costSoFar' : 'costPeriod';
  const trendOpts = {
    moreIsGood: better,
    // v3.10.0 (Skeptiker M1) — Weg zu den Ablesungen, wenn der Trend eher nach einem falschen Stand aussieht
    checkHref: `#/utility/${encodeURIComponent(utility.key)}?view=readings`,
    noneTitle: billing ? t('dashboard.trend.noneTitleBilling') : t(beginnerTrendNoneKey(monthly)),
  };
  const groupsHtml = groupBreakdown(consumption, sumKey, utility, billing ? { period: billing, groups } : null);
  // Der kurze Name steht sichtbar, der lange (mit Zeitraum) als Tooltip: In der Kachel nennt der Zeitraum ihn schon
  const slot = (key, value, rest) => `<div class="kpi art-kachel__wert">
          <div class="kpi__label" title="${escapeHtml(t(`dashboard.kpi.${key}`))}">${escapeHtml(t(`dashboard.tile.${key}`))}</div>
          <div class="kpi__value">${value}</div>
          ${rest}
        </div>`;
  const pct = pvYear && generation ? pvYear.eigenverbrauchsquote : null;
  return `
    <div class="card art-kachel" data-utility="${escapeHtml(utility.key)}">
      <h3 class="art-kachel__titel"><a class="art-kachel__kopf" href="#/utility/${escapeHtml(utility.key)}" aria-label="${escapeHtml(`${utility.label}: ${t('dashboard.card.details')}`)}">${artIcon(utility, { size: 20 })} ${escapeHtml(utility.label)}</a></h3>
      <p class="dash-window art-kachel__zeit">${escapeHtml(windowText)}</p>
      <div class="art-kachel__werte">
        ${slot(valueKey, escapeHtml(fmt.unit(totalCons, utility.consumption_unit, 0)), trendHtml(trendCons, trendOpts))}
        ${generation
          // v3.12.0 (M4) — Erzeugung kostet nichts: Wie viel davon im Haus bleibt
          ? (pct != null ? `<div class="kpi art-kachel__wert" data-role="tile-self-use">
          <div class="kpi__label">${escapeHtml(t('dashboard.tile.selfUse', { year: pvYear.year }))}</div>
          <div class="kpi__value">${escapeHtml(fmt.pct(pct, 0))}</div>
          ${pvYear.autarkiequote != null ? `<div class="kpi__sub">${escapeHtml(t('dashboard.tile.autarky', { pct: fmt.pct(pvYear.autarkiequote, 0) }))}</div>` : ''}
        </div>` : '')
          : slot(costKey,
            noContract ? '<span class="kpi__empty" aria-hidden="true">—</span>' : escapeHtml(fmt.eur(totalCost)),
            `${noContract ? '' : trendHtml(trendCost, trendOpts)}
          <div class="kpi__sub">${noContract ? t('dashboard.kpi.noContract') : billing ? escapeHtml(t('billing.dashboard.costSub', { advances: fmt.eur(billing.advances) })) : t(feedIn ? 'dashboard.kpi.revenueSub' : 'dashboard.kpi.costSub')}</div>
          ${bonusText && !noContract ? `<div class="kpi__sub kpi__bonus" data-role="kpi-bonus">${escapeHtml(bonusText)}</div>` : ''}`)}
      </div>
      ${last12.length >= 2 ? `<div class="dash-spark art-kachel__spur"><canvas data-spark="${escapeHtml(utility.key)}"></canvas></div>` : '<div class="art-kachel__spur" aria-hidden="true"></div>'}
      ${groupsHtml}
      ${tileFootHtml(utility, consumption, summary, tanks)}
    </div>
  `;
}

/**
 * Fuß der Kachel: links der Stand (letzte Ablesung, Füllstand), rechts der Sprung zu den Zählern.
 * Hauptzähler zählen für den Stand, weil ein Zwischenzähler „zuletzt abgelesen“ sonst schönt;
 * mitgezählt in „n Zähler“ werden alle.
 */
function tileFootHtml(utility, consumption, summary, tanks) {
  const all = (summary?.meters || []).filter(m => m.utility === utility.key);
  const main = all.filter(m => !m.is_sub_meter);
  const groupCount = new Set((consumption?.meters || []).map(e => e.meter?.meter_group_id).filter(Boolean)).size;
  const mine = (tanks || []).filter(tk => tk.utility.key === utility.key);
  const delivery = utility.reading_kind === 'delivery';
  const n = all.length || (consumption?.meters || []).length;
  const count = (!delivery ? tp('dashboard.card.metersCount', n, {}) : utility.volume_unit === 'kg' ? tp('dashboard.card.storesCount', n, {}) : tp('dashboard.card.tanksCount', n, {}))
    + (groupCount ? `, ${tp('dashboard.card.groupsCount', groupCount, {})}` : '');
  let stand = '';
  if (delivery && mine.length) {
    const stock = mine.reduce((s, x) => s + x.stock, 0);
    const cap = mine.reduce((s, x) => s + x.cap, 0);
    const pct = cap > 0 ? Math.max(0, Math.min(100, stock / cap * 100)) : 0;
    const level = tankLevel(pct, getSettingsSync()?.tank_warn_pct);
    stand = `<span class="art-kachel__stand${level === 'ok' ? '' : ` ton-${level === 'alert' ? 'fehler' : 'warn'}`}" data-role="tile-stock" data-level="${escapeHtml(level)}">${escapeHtml(t(utility.volume_unit === 'kg' ? 'dashboard.card.store' : 'dashboard.card.tank', {
      pct: fmt.pct(pct / 100, 0), stock: fmt.num(stock, 0), cap: fmt.num(cap, 0), unit: mine[0].unit,
    }))}</span>`;
  } else if (!delivery) {
    const dated = main.filter(m => m.last_reading_date);
    if (dated.length) {
      const latest = dated.reduce((a, b) => (b.last_reading_date > a.last_reading_date ? b : a));
      const days = Number(latest.days_since_reading) || 0;
      const period = latest.capture === 'period';
      const due = main.some(m => m.reading_due);
      stand = `<span class="art-kachel__stand${due ? ' ton-warn' : ''}" data-role="tile-last-reading" title="${escapeHtml(fmt.date(latest.last_reading_date))}">${escapeHtml(tp(period ? 'utility.banner.lastPeriod' : 'utility.banner.lastReading', days, { days }))}</span>`;
    } else if (main.length) {
      stand = `<span class="art-kachel__stand ton-warn" data-role="tile-last-reading">${escapeHtml(t('dashboard.card.noReading'))}</span>`;
    }
  }
  return `<div class="art-kachel__fuss">${stand || '<span></span>'}<a href="#/household/meters?art=${escapeHtml(utility.key)}" aria-label="${escapeHtml(`${t('dashboard.card.meters')}: ${utility.label}`)}">${escapeHtml(count)}</a></div>`;
}

// F1006 — Zähler-Gruppen als Dashboard-Summe. Pro Gruppe mit Mitgliedern wird
// der 12-Monats-Verbrauch (und, falls vorhanden, die Kosten) der zugeordneten
// Zähler aufsummiert und als aufklappbare Übersicht in der Utility-Karte
// gezeigt. Liefert leeren String, wenn es keine Gruppe mit Mitgliedern gibt
// (dann bleibt die Karte unverändert wie zuvor).
// v3.6.0 (Paket abrechnung) — im Abrechnungsjahr dieselbe Periode wie die
// Kachel darüber (Summen der Gruppe vom Server, GET …/meter-groups/{id}/billing-periods);
// hat die Gruppe sie nicht (eigene Rechnungen), ihre jüngste — mit Namen.
function groupBreakdown(consumption, sumKey, utility, billing = null) {
  const groups = consumption?.meter_groups || [];
  const meters = consumption?.meters || [];
  if (!groups.length) return '';

  const rows = groups.map(g => {
    const members = meters.filter(e => (e.meter?.meter_group_id ?? null) === g.id);
    if (!members.length) return null;
    if (billing) {
      const list = periodsWithData(billing.groups?.get(g.id));
      const p = list.find(x => x.from === billing.period.from) || pickPeriod(list);
      if (!p) return null;
      return { name: g.name, cons: Number(p[sumKey]) || 0, cost: Number(p.cost) || 0,
        period: p.from === billing.period.from ? '' : periodName(p, fmt.date) };
    }
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
            <span class="dash-groups__name">${escapeHtml(g.name)}${g.period ? ` <span class="muted">(${escapeHtml(g.period)})</span>` : ''}</span>
            <span class="dash-groups__val">${fmt.num(g.cons, 0)} ${escapeHtml(utility.consumption_unit)}${g.cost > 0 ? ` · ${fmt.eur(g.cost)}` : ''}</span>
          </li>`).join('')}
      </ul>
    </details>`;
}

/**
 * v2.16.0 (Review FE-17) — „Energie gesamt“: die kWh-Verbrauchsarten
 * gestapelt je Monat. Bis v2.15 teilten sich Heizarten (bis 3.000 kWh) und
 * Strom (rund 250) eine Linienachse, Wasser hatte eine zweite — Strom lag
 * plattgedrückt am Boden. Jede Verbrauchsart hat ihren Verlauf jetzt in der
 * eigenen Karte; hier steht nur, was sich addieren lässt.
 */
function renderTotalChart(datasets, span = 12, container = document) {
  const canvas = container.querySelector('#dash-chart');
  if (!canvas) return null;
  const kinds = datasets.filter(d => !isPv(d.utility) && d.utility.consumption_unit === 'kWh'
    && (d.consumption?.monthly_total || []).length);
  if (!kinds.length) return null;
  const allMonths = new Set();
  kinds.forEach(d => (d.consumption.monthly_total || []).slice(-span).forEach(m => allMonths.add(m.ym)));
  const months = Array.from(allMonths).sort().slice(-span);
  const labels = months.map(m => fmt.month(m));
  const seriesList = kinds.map(d => {
    const u = d.utility;
    const rows = Object.fromEntries((d.consumption.monthly_total || []).map(m => [m.ym, m]));
    const partial = months.map(m => !!rows[m] && isPartial(rows[m]));
    const bar = (full, part) => (ctx) => withAlpha(chartColor(u), partial[ctx.dataIndex] ? part : full);
    return {
      label: u.label,
      // v2.5.3 (FE-03) — Monat ohne Daten ist eine Lücke, kein Nullverbrauch
      data: months.map(m => rows[m]?.kwh ?? null),
      backgroundColor: bar(0.6, 0.2),
      borderColor: bar(1, 0.5),
      borderWidth: 1,
      stack: 'kwh',
      etMeta: { rows, partial },
    };
  });
  const totals = months.map((_, i) => seriesList.reduce((a, s2) => a + (Number(s2.data[i]) || 0), 0));
  const chart = makeChart(canvas, {
    type: 'bar',
    data: { labels, datasets: seriesList },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        tooltip: { callbacks: {
          label: (item) => `${item.dataset.label}: ${fmt.unit(item.parsed.y, 'kWh', 0)}`,
          afterLabel: (item) => {
            const meta = item.dataset.etMeta;
            const row = meta?.rows[months[item.dataIndex]];
            return row && meta.partial[item.dataIndex] ? t('chart.partialMonth', { days: row.days, total: daysInMonth(row.ym) }) : '';
          },
          footer: (items) => t('dashboard.chart.totalTip', { value: fmt.unit(totals[items[0]?.dataIndex] ?? 0, 'kWh', 0) }),
        } },
      },
      scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, title: { display: true, text: 'kWh' } } },
    },
  }, { label: t('dashboard.chart.totalAlt', { months: span, list: kinds.map(d => d.utility.label).join(', '),
    total: fmt.unit(totals.reduce((a, v) => a + v, 0), 'kWh', 0) }) });

  // v2.15.0 (Review FE-20) — die Werte als Tabelle zum Aufklappen
  const extra = container.querySelector('[data-role="chart-extra"]');
  if (extra) {
    extra.innerHTML = chartTableHtml({
      caption: t('dashboard.chart.totalTitle', { months: span }),
      columns: [t('utility.monthlyTable.colMonth'), ...seriesList.map(s2 => `${s2.label} (kWh)`), t('dashboard.chart.totalColumn')],
      rows: months.map((m, i) => [fmt.month(m), ...seriesList.map(s2 => {
        const v = s2.data[i];
        if (v == null) return null;
        const row = s2.etMeta.rows[m];
        return fmt.int(v) + (s2.etMeta.partial[i] ? ` (${row.days}/${daysInMonth(m)})` : '');
      }), fmt.int(totals[i])]),
    });
  }
  return chart;
}

/**
 * v2.16.0 (Review FE-17) — Mini-Verlauf je Karte: die zwölf Monate des
 * Kartenfensters als Säulen, dieselben Monate ein Jahr zuvor als Linie.
 * Ohne Achsen und Legende; die Zahlen stehen in den Kennzahlen darüber.
 */
function renderSparks(datasets, container) {
  const out = [];
  for (const d of datasets) {
    const u = d.utility;
    const canvas = container.querySelector(`canvas[data-spark="${u.key}"]`);
    if (!canvas) continue;
    const monthly = d.consumption?.monthly_total || [];
    const key = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
    const win = lastMonths(monthly, 12);
    if (!win || win.rows.length < 2) continue;
    const byYm = new Map(monthly.map(m => [m.ym, m]));
    const rows = win.rows;
    const partial = rows.map(m => isPartial(m));
    const prev = rows.map(m => byYm.get(shiftYm(m.ym, -12))?.[key] ?? null);
    const hasPrev = prev.some(v => v != null);
    const bar = (full, part) => (ctx) => withAlpha(chartColor(u), partial[ctx.dataIndex] ? part : full);
    const unit = u.consumption_unit;
    out.push(makeChart(canvas, {
      type: 'bar',
      data: {
        labels: rows.map(m => fmt.month(m.ym)),
        datasets: [
          { type: 'bar', label: u.label, data: rows.map(m => m[key] ?? null), backgroundColor: bar(0.55, 0.18), borderWidth: 0, order: 2 },
          ...(hasPrev ? [{ type: 'line', label: t('dashboard.spark.prev'), data: prev, borderColor: tokenColor('text2'), borderWidth: 1.5,
            borderDash: [3, 3], pointRadius: 0, tension: 0.3, spanGaps: false, order: 1 }] : []),
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (item) => `${item.dataset.label}: ${fmt.unit(item.parsed.y, unit, 0)}` } },
        },
        scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
      },
    }, { label: t(hasPrev ? 'dashboard.spark.altPrev' : 'dashboard.spark.alt', { label: u.label, from: fmt.month(win.from), to: fmt.month(win.to) }) }));
  }
  return out;
}

// Effizienzklasse → Badge-Tönung (gut=success … schlecht=danger)
function effClsTone(cls) {
  if (!cls) return 'info';
  if (['A+', 'A', 'B'].includes(cls)) return 'success';
  if (['C', 'D'].includes(cls)) return 'info';
  if (['E', 'F'].includes(cls)) return 'warning';
  return 'danger'; // G, H
}

// v3.5.0 — Energieausweis-nahe Kennzahl nach dem Verfahren, das heute gilt
// (BenchmarkService::CERT_RULES, § 82 GEG bzw. ab 2027 GModG). Vor einem neuen
// Verfahren steht darunter, was es ändert und welche Zahl es ergäbe (`upcoming`).
function certificateHtml(eff, utilities = []) {
  const c = eff.certificate;
  const cls = (x) => eff.scale !== null && x.class ? ' · ' + x.class : '';
  const basis = (x) => x.fuel_basis === 'hs' ? t('dashboard.efficiency.basis.hs') : t('dashboard.efficiency.basis.hi');
  const label = (k) => utilities.find(u => u.key === k)?.label ?? k;
  const approx = (x) => x.fuel_basis_approx?.length
    ? ' ' + t('dashboard.efficiency.certificateApprox', { fuels: x.fuel_basis_approx.map(label).join(', ') }) : '';
  return `
          <p class="kpi__sub dash-eff__cert">${escapeHtml(t(eff.per_source.length > 1 ? 'dashboard.efficiency.certificateAll' : 'dashboard.efficiency.certificate', {
            value: fmt.num(c.kwh_per_m2, 0), cls: cls(c),
          }))}${infoNote(t('glossary.efficiency.term'), t('dashboard.efficiency.certificateTitle', {
            basis: basis(c), area: fmt.num(c.area_m2, 0),
            required: c.months_required ?? 36, months: c.months_in_window ?? c.months_36,
          }) + approx(c))}</p>`;
}

/** v3.5.0 — vor einem neuen Verfahren: was es ändert und welche Zahl es ergäbe. */
function certificateUpcomingHtml(eff, utilities = []) {
  const c = eff.certificate;
  const up = c.upcoming;
  const cls = (x) => eff.scale !== null && x.class ? ' · ' + x.class : '';
  const basis = (x) => x.fuel_basis === 'hs' ? t('dashboard.efficiency.basis.hs') : t('dashboard.efficiency.basis.hi');
  const label = (k) => utilities.find(u => u.key === k)?.label ?? k;
  const approx = (x) => x.fuel_basis_approx?.length
    ? ' ' + t('dashboard.efficiency.certificateApprox', { fuels: x.fuel_basis_approx.map(label).join(', ') }) : '';
  return `<p class="muted dash-eff__note" data-role="cert-upcoming">${escapeHtml(t('dashboard.efficiency.certificateUpcoming', {
    date: fmt.date(up.from), basis: basis(up),
    dhw: fmt.num(up.dhw_rule, 0), dhwOld: fmt.num(c.dhw_rule, 0),
    required: up.months_required, requiredOld: c.months_required,
    value: fmt.num(up.kwh_per_m2, 0), cls: cls(up),
  }) + approx(up))}</p>`;
}

// v3.1.0 (H8, MKT-11) — Einordnung des eigenen Verbrauchs an selbst eingetragenen
// Vergleichswerten (Strom- und Heizspiegel liegen nicht bei: Ihre Tabellen
// brauchen eine Genehmigung).
// v3.6.0 — als Ampel mit einem Satz je Zeile (Strom, Heizung, Wasser gegen den
// Richtwert je Person); fehlt eine Angabe, lädt genau ein Link zum Feld ein
// (Einstellungen → Haushalt, `?field=`). Die Links auf die Startseiten von
// Strom- und Heizspiegel sind entfallen: Sie führten aus der App heraus, ohne
// zum Eintragen zurückzuführen. Ohne Zeile und ohne Einladung keine Kachel.
// Bleibt beim Kalenderjahr (F1030).
export const COMPARISON_INVITE = {
  reference_strom_kwh:    'reference',
  reference_heat_kwh_m2:  'reference',
  wohnflaeche_m2:         'area',
  wasser_personen_anzahl: 'persons',
};

export function comparisonCardHtml(c, utilities = []) {
  if (!c?.supported) return '';
  const pct = (d) => fmt.pct(Math.abs(d) / 100, 0);
  // Ampelzeile: Punkt (Farbe) + Wort (dasselbe in Text) + Satz
  const row = (rating, text, sub = '') => `<li class="cmp-row${rating ? ` cmp-row--${rating}` : ''}" data-rating="${rating || 'none'}">
          <span class="cmp-row__dot" aria-hidden="true"></span>
          <span class="cmp-row__body">${escapeHtml(text)}${rating ? ` <strong class="cmp-row__label">${escapeHtml(t('comparison.rating.' + rating))}</strong>` : ''}${sub ? `<span class="cmp-row__sub">${sub}</span>` : ''}</span>
        </li>`;
  const sentence = (kind, d, vars) => t(`comparison.line.${kind}${d > 0 ? 'Above' : 'Below'}`, { ...vars, pct: pct(d) });
  const rows = [];
  const s = c.strom;
  if (s && s.own_value != null) {
    const notes = [!s.complete ? t('comparison.incomplete') : '', s.special_household ? t('comparison.special') : ''].filter(Boolean);
    rows.push(row(s.rating, s.rating
      ? sentence('strom', s.delta_pct, { kwh: fmt.int(s.kwh), own: fmt.int(s.own_value) })
      : t('comparison.strom', { kwh: fmt.int(s.kwh), own: fmt.int(s.own_value) }), notes.map(escapeHtml).join(' ')));
  }
  for (const h of (c.heating || []).filter(x => x.own_value != null)) {
    const vars = { label: utilities.find(x => x.key === h.utility)?.label || h.utility, value: fmt.num(h.kwh_per_m2, 0), own: fmt.num(h.own_value, 0) };
    rows.push(row(h.rating, h.rating ? sentence('heating', h.delta_pct, vars) : t('comparison.heating', vars),
      h.complete ? '' : escapeHtml(t('comparison.incomplete'))));
  }
  const w = c.water;
  if (w?.rating) {
    rows.push(row(w.rating, sentence('water', w.delta_pct, { liters: fmt.int(w.liters_per_person_day), ref: fmt.int(w.reference_l) }),
      escapeHtml(t('comparison.waterBasis', { m3: fmt.num(w.m3, 0), persons: fmt.int(w.persons) }))));
  }
  const field = (c.missing || []).find(k => COMPARISON_INVITE[k]);
  const invite = field ? `<p class="cmp-invite" data-role="cmp-invite">${escapeHtml(t('comparison.invite.' + COMPARISON_INVITE[field]))}
          <a href="#/household/building?field=${encodeURIComponent(field)}">${escapeHtml(t('comparison.invite.' + COMPARISON_INVITE[field] + 'Link'))}</a></p>` : '';
  if (!rows.length && !invite) return '';
  const own = (s && s.own_value != null) || (c.heating || []).some(x => x.own_value != null);
  return `
      <div class="card dash-insight kennzahl" data-role="comparison">
        <h2 class="card__title">${icon('lineal')} ${escapeHtml(t('comparison.title', { year: c.year }))}</h2>
        ${rows.length ? `<ul class="cmp-list">${rows.join('')}</ul>` : ''}
        ${c.source && own ? `<p class="muted small">${escapeHtml(t('comparison.source', { source: c.source }))}</p>` : ''}
        ${invite}
      </div>`;
}

// ── v3.2.0 (F1019) — Übersicht für Einsteiger ───────────────────────────
//
// Drei Antworten statt aller Kennzahlen: Bekomme ich Geld zurück? Mehr oder
// weniger als im Vorjahr? Was ist zu tun? Bei Miete dazu: Reicht die
// Vorauszahlung? Ein Schaubild erklärt die erste Antwort. Gerechnet wird
// dasselbe wie in den anderen Stufen (contract-status, /api/summary).

/**
 * v3.9.0 (Gruppenverträge) — je Gruppe mit laufendem Gruppenvertrag ein Eintrag
 * wie ein Zähler (aus `group_contract` der Mitglieder, je Gruppe einmal). Bis
 * dahin fehlte ihr Saldo in „Bekomme ich Geld zurück?“: Die Mitglieder tragen
 * `contract: null`, Subzähler zählten ohnehin nicht.
 * @param {Array<object>} meters  `summary.meters`
 * @returns {Array<object>} `summary.meters` und dahinter die Gruppen
 */
export function withGroupContracts(meters) {
  const seen = new Set();
  const groups = [];
  for (const m of meters || []) {
    const g = m.group_contract;
    const key = `${m.utility}.${g?.group_id}`;
    if (!g?.contract || seen.has(key)) continue;
    seen.add(key);
    groups.push({ key, utility: m.utility, meter_id: g.group_id, name: g.name, is_sub_meter: false, is_group: true, contract: g.contract });
  }
  return [...(meters || []), ...groups];
}

/** Hauptzähler und Gruppen mit laufendem Vertrag und erwartetem Saldo (ohne PV). */
const moneyMeters = (meters, byKey) => withGroupContracts(meters).filter(m => m.contract && !m.is_sub_meter && byKey.has(m.utility)
  && m.contract.projected_end_balance != null && !isPv(byKey.get(m.utility)));

/**
 * Zeilen der Antwort „Muss ich nachzahlen?“ (Einsteiger) aus `/api/summary`.
 * Rein, ohne DOM — geprüft in tests/browser-render.test.mjs.
 * @param {Array<object>} meters  `summary.meters`
 * @param {Map<string, object>} byKey  Verbrauchsarten nach Schlüssel
 * @returns {string[]} `<li>` je Zähler mit laufendem Vertrag
 */
export function beginnerMoneyLines(meters, byKey) {
  const withContract = moneyMeters(meters, byKey);
  // v3.9.0 (A3) — mehrere Zähler einer Art (etwa Haushalt und Wärmepumpe): mit Zählername
  const perUtility = withContract.reduce((acc, m) => acc.set(m.utility, (acc.get(m.utility) || 0) + 1), new Map());
  return withContract.map(m => {
    const u0 = byKey.get(m.utility);
    const u = perUtility.get(m.utility) > 1 && m.name ? { ...u0, label: `${u0.label} (${m.name})` } : u0;
    // v3.10.0 — eine Quelle mit der Saldo-Karte (expectedBalance): Summe einer Abrechnung über
    // mehrere Verträge, Spanne bzw. Breite der Schätzung
    const exp = expectedBalance(m.contract);
    const v = exp.value;
    // v3.9.0 (Skeptiker H3) — dieselbe Unsicherheit wie die Saldo-Karte: Spanne statt eines sicheren Betrags
    const unc = exp.unc;
    // v3.12.0 (Sichtung S1) — neutral nur bei genau 0; unter einem Euro auf den Cent, nicht „rund 0 €“
    const credit = !unc && v < 0, even = !unc && Math.abs(v) < 0.005;
    // v3.12.0 — genau 0 heißt „ausgeglichen“ wie an der Saldo-Karte (bis dahin „etwa ausgeglichen“); unter einem
    // Euro der Betrag auf den Cent ohne „rund“ (bis dahin „rund 0,38 €“)
    const cent = Math.abs(v) < 1;
    const text = unc ? ''
      : even ? t('saldo.even')
      // v3.9.0 (L2) — „rund“ nur mit gerundeter Zahl
      : cent ? t(credit ? 'dashboard.beginner.money.creditExact' : 'dashboard.beginner.money.dueExact', { amount: fmt.eur(Math.abs(v)) })
      : t(credit ? 'dashboard.beginner.money.credit' : 'dashboard.beginner.money.due', { amount: fmt.eur0(Math.abs(v)) });
    // v3.10.0 (Skeptiker v3.9.0, R2-3) — auch eine sichere Schätzung hat eine Breite, wie an der Karte
    const band = !unc ? bandText(exp.band) : '';
    // v3.9.0 (L1) — der letzte Tag des Abrechnungszeitraums, wie überall
    const until = m.contract.period_last_day || m.contract.period_end;
    return `<li class="dash-answer__row">${artIcon(u, { size: 16 })}
      <span><strong>${escapeHtml(u.label)}:</strong> <span class="${even || unc ? '' : credit ? 'ton-ok' : 'ton-fehler'}"${unc ? ' data-role="money-uncertain"' : ''}>${unc ? `${uncertainRangeHtml(unc)} (${escapeHtml(t('balanceUncertain.verdict'))})` : escapeHtml(text)}</span>
      ${band ? `<span class="muted small" data-role="money-band"> · ${escapeHtml(band)}</span>` : ''}
      ${until ? `<span class="muted small"> · ${escapeHtml(t('dashboard.beginner.money.until', { date: fmt.date(until) }))}</span>` : ''}</span></li>`;
  });
}

/**
 * v3.9.0 (Skeptiker N2) — warum es keinen Vergleich mit dem Vorjahr gibt: Gibt es Monate von vor
 * einem Jahr, fehlen gemessene Paare (verteilte und Teilmonate zählen nicht), nicht das Vorjahr.
 * Bis dahin stand immer „Noch kein Vorjahr zum Vergleichen“, auch mit mehreren erfassten Jahren.
 * @param {Array<object>} monthly  Monatszeilen der Art (monthly_total)
 * @returns {string} Katalogschlüssel
 */
export function beginnerTrendNoneKey(monthly) {
  const rows = Array.isArray(monthly) ? monthly : [];
  const last = rows.length ? rows[rows.length - 1].ym : null;
  const hasPrevYear = !!last && rows.some(r => r.ym <= shiftYm(last, -12));
  return hasPrevYear ? 'dashboard.beginner.trend.fewMeasured' : 'dashboard.beginner.trend.none';
}

/**
 * v3.12.0 (Skeptiker M1) — offene Vorschläge der KI warten in der App: Hinweis über „Zu tun“ (nur Verwalter
 * bekommen die Liste, sonst bleibt es still). Kein Ereignis der Agenda: das ginge auch in Kalender und Home Assistant.
 */
function showMcpProposals(container, settings, loaded = null) {
  if (!wantsProposals(settings)) return;
  // v3.12.0 — die Liste kommt aus derselben Ladung wie „Zu tun“ (loadTodo), kein zweiter Abruf
  (Array.isArray(loaded) ? Promise.resolve(loaded) : api.mcpProposals()).then((open) => {
    const n = Array.isArray(open) ? open.length : 0;
    const box = container.querySelector('[data-role="todo"]');
    if (!n || !box?.isConnected) return;
    box.insertAdjacentHTML('beforebegin', `<p class="infobox infobox--info" data-role="mcp-proposals-waiting">
      <a href="#/todo?art=ki">${escapeHtml(tp('dashboard.mcpProposals', n, { count: n }))}</a></p>`);
  }).catch(() => { /* Mitglied oder älterer Server: kein Hinweis */ });
}

/**
 * v3.12.0 — die Antwort „Mehr oder weniger als im Vorjahr?“ zu einem Trend, mit derselben Schwelle wie die
 * Kachel daneben (components/trend.js SAME_BELOW_PCT). Bis dahin galt hier unter 2 % „etwa wie im Vorjahr“,
 * während die Kachel schon „−2 %“ zeigte. Rein, geprüft in tests/browser-render.test.mjs.
 * @param {{pct: number}} trend
 * @param {object} u  Verbrauchsart
 * @returns {{same: boolean, word: string, tone: string}}
 */
export function trendAnswer(trend, u) {
  const pct = Number(trend?.pct);
  const same = Math.abs(pct) < SAME_BELOW_PCT;
  const good = moreIsBetter(u) ? pct > 0 : pct < 0;
  const word = same ? t('dashboard.beginner.trend.same')
    : t(pct > 0 ? 'dashboard.beginner.trend.more' : 'dashboard.beginner.trend.less', { pct: fmt.pct(Math.abs(pct) / 100, 0) });
  return { same, word, tone: same ? '' : good ? 'ton-ok' : 'ton-fehler' };
}

/**
 * v3.12.0 — eine Quelle der Übersicht hat nicht geantwortet: eine Fehlerzeile mit der Meldung des Servers (bei
 * einem Serverfehler mit Fehler-ID), nie der Leerzustand. Bis dahin stand bei einem Fehler „Gerade nichts. Gut so.“
 * bzw. „Noch kein Vertrag mit Abschlag erfasst.“, als wäre es die Antwort.
 * @param {Error|object} err
 * @param {{inline?: boolean}} [o]  inline: nur der Text, ohne Box (in einer Zeile der Liste)
 */
function failedLineHtml(err, { inline = false } = {}) {
  const msg = String(err?.message || '').trim();
  const html = `${rich('dashboard.answers.failed')}${msg ? ` <span class="muted small" data-role="error-detail">${escapeHtml(msg)}</span>` : ''}`;
  return inline ? `<span class="ton-fehler" data-role="answer-failed">${html}</span>`
    : `<p class="infobox infobox--fehler" role="alert" data-role="answer-failed">${html}</p>`;
}

/**
 * Die Antworten der Übersicht, seit v3.12.0 (M4) in allen Stufen: Was ist zu tun, bekomme ich Geld
 * zurück, mehr oder weniger als im Vorjahr; bei Miete dazu: Reicht die Vorauszahlung?
 */
async function answersHtml({ utilities, datasets, settings, summary, summaryError = null, todo, lever = false }) {
  const byKey = new Map(utilities.map(u => [u.key, u]));
  const budget = settings.wohnverhaeltnis === 'miete' ? await tenancyBudget().catch(() => null) : null;
  const moneyLines = beginnerMoneyLines(summary?.meters || [], byKey);
  const trendLines = datasets.filter(d => (d.consumption?.monthly_total || []).length).map(({ utility: u, consumption }) => {
    const monthly = consumption.monthly_total;
    const key = u.consumption_unit === 'kWh' ? 'kwh' : 'm3';
    const win = lastMonths(monthly, 12);
    const trend = yoyTrend(monthly, key, { months: 12, minMonths: 6, within: win?.rows.map(m => m.ym) });
    if (!trend) {
      return `<li class="dash-answer__row">${artIcon(u, { size: 16 })}
        <span><strong>${escapeHtml(u.label)}:</strong> <span class="muted">${escapeHtml(t(beginnerTrendNoneKey(monthly)))}</span></span></li>`;
    }
    // v3.10.0 (Skeptiker M1) — so stark verändert, dass eher eine Ablesung falsch ist
    if (trendNeedsCheck(trend.pct)) {
      return `<li class="dash-answer__row">${artIcon(u, { size: 16 })}
        <span><strong>${escapeHtml(u.label)}:</strong> <a href="#/utility/${encodeURIComponent(u.key)}?view=readings" data-role="trend-check">${escapeHtml(t('dashboard.trend.checkLabel'))}</a></span></li>`;
    }
    const { word, tone } = trendAnswer(trend, u);
    return `<li class="dash-answer__row">${artIcon(u, { size: 16 })}
      <span><strong>${escapeHtml(u.label)}:</strong> <span class="${tone}">${escapeHtml(word)}</span></span></li>`;
  });
  // v3.12.0 — eine Art, deren Verbrauch nicht geladen wurde, steht mit dem Fehler da (bis dahin fehlte sie still)
  const failedTrend = datasets.filter(d => d.failed).map(({ utility: u, failed }) => `<li class="dash-answer__row" data-role="answer-failed">${artIcon(u, { size: 16 })}
      <span><strong>${escapeHtml(u.label)}:</strong> ${failedLineHtml(failed, { inline: true })}</span></li>`);
  return `
    <div class="grid grid-2 dash-answers">
      <section class="card dash-answer dash-todo" aria-labelledby="dash-a-todo">
        <h2 class="card__title" id="dash-a-todo">${icon('aufgaben')} ${escapeHtml(t('dashboard.beginner.todo.title'))}</h2>
        <div data-role="todo" data-variant="${currentLevel() === 'beginner' ? 'beginner' : 'excerpt'}">${excerptTodoHtml(todo, currentLevel() === 'beginner')}</div>
        <p class="dash-answer__fuss"><a class="btn btn--ghost btn--sm" href="#/todo" data-role="todo-all">${escapeHtml(t('dashboard.todo.all'))}</a></p>
      </section>
      <section class="card dash-answer" aria-labelledby="dash-a-money">
        <h2 class="card__title" id="dash-a-money">${icon('geld')} ${escapeHtml(t('dashboard.beginner.money.title'))}</h2>
        ${summaryError ? failedLineHtml(summaryError)
          : moneyLines.length ? `<ul class="dash-answer__list">${moneyLines.join('')}</ul>`
          : `<p class="muted">${escapeHtml(t('dashboard.beginner.money.none'))} <a href="#/contracts">${escapeHtml(t('dashboard.beginner.money.add'))}</a></p>`}
      </section>
      <section class="card dash-answer" aria-labelledby="dash-a-trend">
        <h2 class="card__title" id="dash-a-trend">${icon('verlaufskurve')} ${escapeHtml(t('dashboard.beginner.trend.title'))}</h2>
        <ul class="dash-answer__list">${[...trendLines, ...failedTrend].join('')}</ul>
      </section>
      ${budget ? tenancyAnswerHtml(budget) : ''}
      ${lever ? leverSlotHtml('beginner') : ''}
    </div>`;
}

async function renderBeginner(container, { utilities, datasets, todo, settings, summary, summaryError = null }) {
  const byKey = new Map(utilities.map(u => [u.key, u]));
  const withContract = moneyMeters(summary?.meters || [], byKey);
  const answers = await answersHtml({ utilities, datasets, settings, summary, summaryError, todo, lever: true });

  // Schaubild zur ersten Antwort: der Vertrag mit dem höchsten Abschlag
  const main = [...withContract].sort((a, b) => Math.abs(Number(b.contract.advance_month) || 0) - Math.abs(Number(a.contract.advance_month) || 0))[0];
  let explainer = '';
  if (main) {
    try {
      const st = await api.contractStatus(main.utility, main.meter_id);
      const row = (st?.contracts || []).find(c => c.is_current);
      explainer = moneyHtml(row, { color: byKey.get(main.utility)?.color, utilityLabel: byKey.get(main.utility)?.label });
    } catch { /* ohne Schaubild */ }
  }

  container.innerHTML = `
    <!-- v3.12.0 (Sichtung S5) — ein Knopf fürs Erfassen: der in Kopf- und Tab-Leiste. Ist eine Ablesung fällig, steht sie unter „Zu tun“. -->
    <div class="section-head">
      <h1>${t('dashboard.title')}</h1>
    </div>
    <div data-role="level-hint"></div>
    ${answers}
    ${whatIfHtml()}
    ${explainer ? `<section class="card" style="margin-top: var(--sp-5)">${explainer}</section>` : ''}`;
  renderTodoBox(container.querySelector('[data-role="todo"]'), todo);   // v3.4.0 (F1024)
  showMcpProposals(container, settings, todo.proposals);
  loadLeverTile(container, utilities);   // v3.6.0 — „Dein Hebel“ auch für Einsteiger
  playExplainers(container);
  const hintBox = container.querySelector('[data-role="level-hint"]');
  levelSuggestion(utilities, summary).then(html => { if (hintBox?.isConnected) { hintBox.innerHTML = html; bindLevelHint(hintBox); } }).catch(() => {});
}

/** Mietverhältnis (Miete): Budget des ersten laufenden Mietverhältnisses. */
async function tenancyBudget() {
  const list = await api.tenancies();
  const today = new Date().toISOString().slice(0, 10);
  const current = (list || []).find(x => !x.end || x.end >= today);
  return current ? api.tenancyBudget(current.id) : null;
}

function tenancyAnswerHtml(b) {
  const r = Number(b.projected_result_eur);
  if (!Number.isFinite(r)) return '';
  const credit = r <= 0;
  const text = t(credit ? 'dashboard.beginner.rent.enough' : 'dashboard.beginner.rent.short', { amount: fmt.eur0(Math.abs(r)) });   // v3.9.0 (L2)
  return `<section class="card dash-answer" aria-labelledby="dash-a-rent">
    <h2 class="card__title" id="dash-a-rent">${icon('block')} ${escapeHtml(t('dashboard.beginner.rent.title'))}</h2>
    <p class="${credit ? 'ton-ok' : 'ton-fehler'}">${escapeHtml(text)}</p>
    ${!credit && b.suggested_prepayment_eur ? `<p class="muted small">${escapeHtml(t('dashboard.beginner.rent.suggest', { amount: fmt.eur(b.suggested_prepayment_eur) }))}</p>` : ''}
  </section>`;
}

// ── v3.2.0 (F1020) — Schaubilder auf der Übersicht (Experte) ────────────

export async function loadExplainers(utilities, box) {
  const byKey = new Map(utilities.map(u => [u.key, u]));
  // v3.4.0 (F1026) — „heute“ in Ortszeit wie in der Verbrauchsansicht (UTC lag
  // zwischen 0 und 2 Uhr einen Tag zurück)
  const today = todayIso();
  const [summary, pv] = await Promise.all([api.summary().catch(() => null), api.pvSummary().catch(() => null)]);
  const meters = withGroupContracts(summary?.meters).filter(m => byKey.has(m.utility) && !m.is_sub_meter);
  const withContract = meters.filter(m => m.contract && !isPv(byKey.get(m.utility)));
  const main = [...withContract].sort((a, b) => Math.abs(Number(b.contract.advance_month) || 0) - Math.abs(Number(a.contract.advance_month) || 0))[0];
  const heat = meters.find(m => !m.is_group && byKey.get(m.utility)?.hgt_relevant && (m.role == null || m.role === 'consumption'));
  const [status, heatRes] = await Promise.all([
    main ? api.contractStatus(main.utility, main.meter_id).catch(() => null) : null,
    heat ? api.meterConsumption(heat.utility, heat.meter_id).catch(() => null) : null,
  ]);
  // v3.7.0 (Nachlauf) — mit der Gütesperre: ohne bestandene keine bereinigte Aussage
  const heatRows = heatRes?.monthly || (Array.isArray(heatRes) ? heatRes : null);
  const row = (status?.contracts || []).find(c => c.is_current);
  const years = pv?.yearly || [];
  const cy = new Date().getFullYear();
  const pvYear = years.find(y => y.year === cy - 1) || years[years.length - 1];
  const parts = [
    moneyHtml(row, { color: main ? byKey.get(main.utility)?.color : undefined, utilityLabel: main ? byKey.get(main.utility)?.label : null }),
    // v3.4.0 (F1026) — die ganze Antwort: Jahr und Monat umschaltbar
    pv ? energyHtml(pv, { year: pvYear?.year }) : '',
    weatherHtml(Array.isArray(heatRows) ? heatRows : [], { quality: heatRes?.anomaly_quality }),
    timelineHtml(row, today),
  ].filter(Boolean);
  if (!box.isConnected) return;
  if (!parts.length) { box.closest('section')?.remove(); return; }
  box.innerHTML = parts.map(p => `<div class="dash-explainer">${p}</div>`).join('');
  playExplainers(box);
}

// ── v3.2.0 (F1019) — Vorschlag zum Hochstufen ───────────────────────────
//
// Die Daten zeigen mehr, als die Stufe anzeigt: Subzähler, Home Assistant,
// Wärmepumpe oder Speicher. Der Vorschlag blockiert nichts; „Nicht mehr
// fragen" merkt sich das Gerät je Stufe und Anlass.
// v3.7.0 (Paket reifegrad) — die Anlässe kommen aus dem Haushalt
// (levels.js `suggestReasons`): Einsteiger mit PV, Speicher, Wärmepumpe oder
// Wallbox → Erfahren; Erfahren mit Viertelstunden → Experte. Jeder Anlass
// erscheint höchstens einmal je Gerät und Stufe.

async function levelSuggestion(utilities, summary = null) {
  const next = nextLevel();
  if (!next) return '';
  const level = currentLevel();
  const [data, series] = await Promise.all([
    summary || api.summary().catch(() => null),
    level === 'advanced' ? api.intervals().catch(() => null) : null,
  ]);
  const reasons = suggestReasons(level, data?.meters || [], { utilities, intervals: (series?.series || []).length });
  const open = reasons.filter(r => !isDismissed(r));
  if (!open.length) return '';
  markShown(open);
  const name = t('level.' + next);
  return `<div class="infobox infobox--info infobox--zeile level-hint" role="note" data-reasons="${escapeHtml(open.join(' '))}" data-next="${next}">
    <span>${escapeHtml(t('level.hint.data', { level: name }))}
      <span class="muted small">${open.map(r => escapeHtml(t('level.reason.' + r))).join(' · ')}</span></span>
    <span class="infobox__aktion">
      <button type="button" class="btn btn--sm btn--primary" data-act="raise">${escapeHtml(t('level.hint.switch', { level: name }))}</button>
      <button type="button" class="btn btn--sm btn--ghost" data-act="dismiss">${escapeHtml(t('level.hint.dismiss'))}</button>
    </span>
  </div>`;
}

function bindLevelHint(box) {
  const el = box.querySelector('.level-hint');
  if (!el) return;
  el.querySelector('[data-act="raise"]')?.addEventListener('click', () => { saveLevel(el.dataset.next).catch(e => toastErr(e.message)); });
  el.querySelector('[data-act="dismiss"]')?.addEventListener('click', () => {
    (el.dataset.reasons || '').split(' ').filter(Boolean).forEach(dismiss);
    el.remove();
  });
}
