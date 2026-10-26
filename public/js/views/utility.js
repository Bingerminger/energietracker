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
import { makeChart, utilColor, tokenColor, chartColor, withAlpha, chartTableHtml, hatchFill } from '../components/chart.js';
import { isPartial, daysInMonth, yoyTrend, seriesSummary } from '../lib/chart-data.js';
import { isInterpolated, interpolationNote, markTitle, MARK } from '../lib/interpolation.js';   // v3.7.0 (#37, #39)
import { openModal, confirmModal, guardSubmit } from '../components/modal.js';
import { showFieldError } from '../lib/form.js';
import { toastOk, toastErr } from '../components/toast.js';
import { t, tp, getCurrencyMinor, getCurrencySymbol, getLocale } from '../lib/i18n.js';
import { info, infoNote } from '../components/info.js';
import { isFeedIn as feedInKind, isGeneration as generationKind, isPv, usesGasFactors, balanceView, moreIsBetter, uncertainRangeText } from '../lib/semantics.js';
import { typicalPerDay, checkReading, confirmIssues, issueText, deviceChangedBetween } from '../lib/plausibility.js';
// v3.2.0 (F1020) — Schaubilder zu dieser Verbrauchsart
import { moneyHtml, energyHtml, weatherHtml, timelineHtml, playExplainers } from '../components/explainer.js';
import { docUrl } from '../lib/docs.js';
// v3.4.0 (N1021) — Vertragsreihenfolge, Blättern, Abrechnungsperiode
import { groupContracts, phasePill } from '../lib/contract-order.js';
import { paginate, pagerHtml, wirePager, pageSize, PAGER_THRESHOLD } from '../components/pager.js';
import { lastClosedPeriod, periodToCheck, calendarYearOf, periodMode, billingApplies, periodName, periodsWithData, pickPeriod, periodRows, refMonth } from '../lib/billing-period.js';
import { periodModeHtml, wirePeriodMode, calendarOnlyNote } from '../components/period-mode.js';
import { bonusOf, bonusItemsOf, bonusLineText } from '../lib/bonus.js';   // v3.7.0 (#33)
import { mergeRestOf, restNoteHtml, restNoteText, subContractHint } from '../lib/rest-billing.js';   // v3.9.0 (F1032, #40)

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
  // v3.6.0 (F1030) — gewählte Abrechnungsperiode (Beginn) je Verbrauchsart
  periodByUtility: {},
  // v2.16.0 (Review FE-19) — Monatschart gemessen oder witterungsbereinigt, je Verbrauchsart
  chartMode: {},
  // v3.4.0 (N1021) — Liste der Ablesungen bzw. Zeiträume: Seite und Filter
  // gelten, solange Art, Zähler, Jahr und „Alle Jahre“ gleich bleiben
  // (Bearbeiten bleibt auf der Seite); jeder Aufruf der Ansicht beginnt auf
  // Seite 1 bei den neuesten Einträgen.
  list: { key: '', page: 1, flaggedOnly: false },
  listAllYears: false,
};

/** Seitengröße gemerkt je Liste (components/pager.js). */
const READINGS_PAGER = 'utility-readings';
const PERIODS_PAGER = 'utility-periods';

/**
 * v2.15.0 (Review FE-29) — Auswahl in der Adresse: `#/utility/gas?year=2025&meter=…`
 * lässt sich teilen, als Lesezeichen ablegen und übersteht Neuladen.
 * replaceState statt eines neuen Eintrags: Zurück führt zur vorigen Seite,
 * nicht durch jede angeklickte Jahreszahl.
 */
function syncAddress(u, latestYear = null, period = null) {
  const q = new URLSearchParams();
  const year = state.yearByUtility[u.key];
  // Das jüngste Jahr ist der Normalfall — ein Lesezeichen soll dann auch im
  // nächsten Jahr das jüngste zeigen, nicht das von heute
  // v3.6.0 (F1030) — im Abrechnungsjahr die Periode (?period=Beginn)
  if (period) { if (period.from !== period.latest) q.set('period', period.from); }
  else if (year && year !== latestYear) q.set('year', String(year));
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
  state.list = { key: '', page: 1, flaggedOnly: false };
  state.listAllYears = false;
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
    const wantPeriod = ctx.query?.get('period');   // v3.6.0 (F1030) — Beginn der Abrechnungsperiode
    if (/^\d{4}-\d{2}-\d{2}$/.test(wantPeriod || '')) state.periodByUtility[utility.key] = wantPeriod;
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
  // v3.1.0 (H6, #17) — rechnet der Zähler über einen Gruppenvertrag, zeigt die
  // Ansicht dessen Saldo (die Gruppe als Ganzes) und sagt es dazu
  let groupNote = '';
  const gcRef = contractStatusData.group_contract;
  if (gcRef?.contract_id) {
    const [gs, groups] = await Promise.all([
      api.contractStatus(u.key, gcRef.group_id).catch(() => null),
      api.meterGroups(u.key).catch(() => []),
    ]);
    const gc = (gs?.contracts || []).find(c => c.contract_id === gcRef.contract_id);
    if (gc) {
      contracts.push(gc);
      groupNote = `<p class="muted small">${escapeHtml(t('utility.groupContractNote', { name: groups.find(g => g.id === gcRef.group_id)?.name || gcRef.group_id }))}</p>`;
    }
  }
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
  let readings = [], deliveries = [], stockHist = null, pvSummary = null, periods = [];
  // v3.1.0 (H3, B2) — Zähler mit Verbrauch je Zeitraum: Zeiträume statt Stände
  const isPeriod = !isDelivery && meter.capture === 'period';
  if (isPeriod) {
    periods = await api.periods(u.key, meter.id).catch(() => []);
  } else if (isDelivery) {
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
  const lastPeriod = isPeriod ? [...periods].sort((a, b) => b.to.localeCompare(a.to))[0] : null;
  const lastReading = isPeriod ? (lastPeriod ? { date: lastPeriod.to } : null) : (!isDelivery ? sortedReadings[0] : null);
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
          <strong>${isPeriod ? tp('utility.banner.lastPeriod', days, { days }) : tp('utility.banner.lastReading', days, { days })}</strong> · ${fmt.date(lastReading.date)}${trendStr}
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
  // v3.6.0 (F1030, #33) — Abrechnungsjahr: die Perioden und ihre Summen vom
  // Server (Rechnungen, sonst Stichtag); Kennzahlen, Tabelle, Diagramm und
  // Vorperiode folgen der gewählten Periode. Nur Arten mit Rechnungsprüfung.
  const billingOk = !isDelivery && !isPeriod && billingApplies(u, meter) && monthly.length > 0;
  const wantBilling = billingOk && periodMode() === 'billing';
  const bp = wantBilling ? await api.billingPeriods(u.key, meter.id).catch(() => null) : null;
  if (my !== rerenderSeq) return;
  const bList = periodsWithData(bp?.periods);
  const sel = wantBilling ? pickPeriod(bList, state.periodByUtility[u.key]) : null;
  const billing = !!sel;
  if (billing) state.periodByUtility[u.key] = sel.from;
  const prevSel = billing ? (bList[bList.indexOf(sel) - 1] || null) : null;
  const ref = billing ? refMonth(bList) : 1;
  const yr = billing ? Number(String(sel.as_of || sel.until).slice(0, 4)) : state.yearByUtility[u.key];
  const yrLabel = billing ? periodName(sel, fmt.date) : String(yr);
  const monthlyYear = billing ? periodRows(sel, monthly, consKey, ref) : monthly.filter(m => m.year === yr);
  // v2.16.0 (Review FE-19, FE-31) — das Vorjahr als Vergleich im Monatschart;
  // witterungsbereinigt nach dem Heizmodell (`heat_adjusted`), wo es Werte gibt
  const prevYear = billing ? (prevSel ? periodRows(prevSel, monthly, consKey, ref) : []) : monthly.filter(m => m.year === yr - 1);
  const chartNames = billing ? { current: yrLabel, prev: prevSel ? periodName(prevSel, fmt.date) : '' } : null;
  const canAdjust = !!u.hgt_relevant && monthlyYear.some(m => m.heat_adjusted != null);
  const chartMode = canAdjust && state.chartMode[u.key] === 'adjusted' ? 'adjusted' : 'measured';
  const pvFlowRows = (pvSummary?.monthly || []).filter(m => m.year === yr
    && ((m.erzeugung_kwh ?? 0) > 0 || (m.einspeisung_kwh ?? 0) > 0));
  // Zähler mit weniger als zwei Ständen: noch keine Monatswerte
  const noValues = !isDelivery && monthly.length === 0;
  // v3.7.0 (GitHub #37, #39) — ein Hinweis, wenn Monatswerte des Zeitraums verteilt
  // statt gemessen sind (mit der Zahl der Ablesungen). Bei PV zusätzlich, wenn die
  // PV-Übersicht des Jahres auf verteilten Monaten einer anderen Reihe beruht.
  let interpText = noValues ? '' : interpolationNote({
    rows: monthlyYear, info: consumptionData.interpolation || null, year: billing ? null : yr,
    heat: !!u.hgt_relevant && !isDelivery, pv: isPv(u),
  });
  if (!interpText && isPv(u) && pvSummary?.interpolation) {
    const series = (await getUtilities().catch(() => [])).find(x => x.key === pvSummary.interpolation.utility)?.label || null;
    interpText = interpolationNote({ rows: (pvSummary.monthly || []).filter(m => m.year === yr), info: pvSummary.interpolation, pv: true, series });
    if (my !== rerenderSeq) return;
  }

  // v3.4.0 (N1021) — Ablesungen bzw. Zeiträume: Anzahl im Jahr und gesamt
  // („10 in 2026 · 43 gesamt“; bis v3.3 stand nur die Gesamtzahl über einer
  // Liste des Jahres), Jahreswahl in der Karte, „Alle Jahre“ blättert
  const listAll = isPeriod ? periods : readings;
  const inListYear = isPeriod
    ? (p) => yearOf(p.from) === yr || yearOf(p.to) === yr
    : (r) => yearOf(r.date) === yr;
  const listInYear = listAll.filter(inListYear);
  const listKey = `${u.key}|${meter.id}|${yr}|${state.listAllYears ? 'all' : ''}`;
  if (state.list.key !== listKey) state.list = { key: listKey, page: 1, flaggedOnly: false };

  // ── Compute year totals for KPIs ────────────────────────────────
  // v1.6.1 — Fix #14: nutze utility-spezifischen Feldnamen (consKey)
  // statt hartkodiertem `m.kwh`, sonst zeigt das Wasser-Dashboard 0.
  // v3.6.0 — im Abrechnungsjahr die Summen der Periode vom Server (Saldo mit
  // zusätzlichen Abschlagszahlungen), nicht die Summe gerundeter Teilmonate
  const sum = (k) => (billing ? Number(sel[k]) || 0 : monthlyYear.reduce((s, m) => s + (m[k] || 0), 0));
  const totUnit = sum(consKey);
  const totCost = sum('cost');
  const totDays = sum('days');
  const totCO2  = sum('co2_kg');
  const totM3   = sum('m3');
  const totAdv  = billing ? Number(sel.advances) || 0 : sum('advance_eur');
  const yearBalance = billing ? Number(sel.balance) || 0 : totCost - totAdv;
  const yearBalanceView = balanceView(yearBalance, u);
  // v3.7.0 (GitHub #33) — die Kachel „Kosten“ ohne Bonus, der Bonus als eigene
  // Zeile darunter; der Saldo rechnet ihn ein (wie die Rechnung), der Ø je
  // Monat nicht (lib/bonus.js). Nicht bei Einspeisung und Erzeugung.
  const withBonus = !isFeedIn && !isGeneration;
  const totBonus = withBonus ? (billing ? Number(sel.bonus) || 0 : bonusOf(monthlyYear)) : 0;
  const costShown = totCost + totBonus;
  const bonusText = withBonus ? bonusLineText(billing ? sel.bonus_items : bonusItemsOf(monthlyYear), totBonus) : '';
  const hasContract = monthlyYear.some(m => m.contract_id);
  // v3.4.0 (N1021) — der laufende Vertrag nach der Phase des Servers: Bei
  // überlappenden Verträgen ist der abgelöste ältere zwar noch „im Zeitraum“
  // (`is_current`), gilt aber nicht mehr
  const currentContract = contracts.find(c => c.phase === 'current' || c.phase === 'renewed')
    || contracts.find(c => c.is_current && !c.phase);
  // v2.8.0 — Die Kacheln summieren die Monatszeilen, also nur abgelesene
  // Monate. Liegt die letzte Ablesung im laufenden Jahr zurück, nennt die
  // Abschlagskachel ihren Stand — sonst stünde „Abschläge 2026: 390 €" neben
  // den 1.170 €, die die Saldo-Karte nach Kalender bis heute zählt.
  const measuredUntil = billing ? sel.as_of : (currentContract?.measured_until || null);
  const partialYear = billing ? !!sel.as_of && sel.as_of < sel.until
    : !!measuredUntil && String(yr) === todayIso().slice(0, 4)
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

    ${noValues ? '' : billingOk ? `<div class="period-mode-bar">${periodModeHtml(wantBilling ? 'billing' : 'calendar')}</div>` : calendarOnlyNote(u.label)}
    ${noValues ? '' : billing ? periodPills(bList, sel, u.key) + periodMeta(sel, bp) : yearPills(years, yr, u.key)}
    ${wantBilling && !billing ? `<p class="muted small" data-role="period-none">${escapeHtml(t('billing.period.none'))}</p>` : ''}

    ${noValues ? '' : `<div class="kpi-grid">
      <div class="kpi c-${u.key}">
        <div class="kpi__label">${u.icon} ${isFeedIn ? t('utility.kpi.feedIn') : isGeneration ? t('utility.kpi.generation') : t('utility.kpi.consumption')} ${escapeHtml(yrLabel)}</div>
        <div class="kpi__value">${fmt.unit(totUnit, u.consumption_unit, 0)}</div>
        ${usesGasFactors(u) ? `<div class="kpi__sub">${fmt.unit(totM3, 'm³', 0)}</div>` : ''}
      </div>
      ${isGeneration ? '' : `
      <div class="kpi c-${u.key}">
        <div class="kpi__label">${isFeedIn ? t('utility.kpi.revenue') : t('utility.kpi.cost')} ${escapeHtml(yrLabel)}${bonusText ? infoNote(t('utility.kpi.cost'), t('bonus.costNote')) : ''}</div>
        <div class="kpi__value ${isFeedIn ? 'success-text' : ''}" data-role="kpi-cost">${fmt.eur(costShown)}</div>
        <div class="kpi__sub">${t('utility.kpi.perMonth', { value: fmt.eur(monthlyYear.length ? costShown / monthlyYear.length : 0) })}</div>
        ${bonusText ? `<div class="kpi__sub kpi__bonus" data-role="kpi-bonus">${escapeHtml(bonusText)}</div>` : ''}
      </div>`}
      ${hasContract && !isFeedIn ? `
        <div class="kpi c-yellow">
          <!-- v3.6.0 — im laufenden Jahr rechnen Kalender- und Abrechnungsjahr den
               angebrochenen Monat verschieden (ganz bzw. tagesgenau): ein Satz dazu -->
          <div class="kpi__label">${partialYear ? t('utility.kpi.advancesUntil', { date: fmt.date(measuredUntil) }) : t('utility.kpi.advances', { year: yrLabel })}${billingOk && partialYear ? info('runningYear') : ''}</div>
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
        <div class="kpi__sub">${tp('utility.kpi.daysCount', totDays, { days: totDays })}</div>
      </div>
      ${isFeedIn || isGeneration ? `
      <div class="kpi c-violet" data-min-level="advanced">
        <div class="kpi__label">${t('utility.kpi.co2Avoided', { year: yrLabel })}${info('co2Avoided')}</div>
        <!-- v2.13.0 — „vermieden“ ohne Minus: das Wort trägt die Richtung (wie im Jahresbericht) -->
        <div class="kpi__value">${fmt.int(totCO2)} <span style="font-size:14px;color:var(--text-2)">kg</span></div>
        <div class="kpi__sub">${t('utility.kpi.co2AvoidedSub', { tons: fmt.num(totCO2 / 1000, 2) })}</div>
      </div>
      ` : `
      <div class="kpi c-violet" data-min-level="advanced">
        <div class="kpi__label">${t('utility.kpi.co2', { year: yrLabel })}</div>
        <div class="kpi__value">${fmt.int(totCO2)} <span style="font-size:14px;color:var(--text-2)">kg</span></div>
        <div class="kpi__sub">${t('utility.kpi.co2Sub', { tons: fmt.num(totCO2 / 1000, 2) })}</div>
      </div>`}
    </div>`}

    ${noValues ? '' : restNoteHtml(mergeRestOf(monthlyYear.map(m => m.rest_of)), u.consumption_unit)}
    ${meter.parent_meter_id && contracts.some(c => !c.is_shadow) ? `<p class="banner banner--info small" data-role="sub-rest-note">${escapeHtml(subContractHint(meter, state.meters))}</p>` : ''}
    ${!isDelivery && currentContract ? groupNote + balanceCard(currentContract, u, country) : ''}
    <div class="card" data-role="explainers" hidden></div>

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
      <div class="card__title">${u.icon} ${t(isFeedIn ? 'utility.cards.monthlyChartFeedIn' : isGeneration ? 'utility.cards.monthlyChartGeneration' : 'utility.cards.monthlyChart', { year: yrLabel })}
        ${canAdjust ? `<span class="card__title-action seg" data-min-level="advanced" role="group" aria-label="${escapeHtml(t('utility.chart.modeLabel'))}">
          <button type="button" class="seg__btn ${chartMode === 'measured' ? 'active' : ''}" data-chart-mode="measured" aria-pressed="${chartMode === 'measured'}">${escapeHtml(t('utility.chart.modeMeasured'))}</button>
          <button type="button" class="seg__btn ${chartMode === 'adjusted' ? 'active' : ''}" data-chart-mode="adjusted" aria-pressed="${chartMode === 'adjusted'}">${escapeHtml(t('utility.chart.modeAdjusted'))}</button>
        </span>${info('weatherAdjusted')}` : ''}
      </div>
      ${interpText ? `<p class="banner banner--info small" data-role="interpolation-note"><span aria-hidden="true">${MARK}</span> ${escapeHtml(interpText)}</p>` : ''}
      ${billing && sel.price_missing ? `<p class="banner banner--warning small" data-role="period-price-missing">${escapeHtml(t('priceMissing.period'))}</p>` : ''}
      <div class="chart-wrap h300"><canvas id="month-chart"></canvas></div>
      <p class="chart-note" data-role="month-chart-note">${monthChartNote(monthlyYear, chartMode)}</p>
    </div>

    ${pvFlowRows.length ? `<div data-min-level="advanced">${pvFlowHtml(pvFlowRows, yr)}</div>` : ''}
    <!-- v3.7.0 — Speicher und Amortisation ab Erfahren (bis v3.6 Experte): Hat sich die Anlage gelohnt? ist eine Prüffrage -->
    ${u.key === 'pv_erzeugung' ? `<div data-role="pv-extras" data-min-level="advanced">${pvExtrasHtml(pvSummary, yr)}</div>` : ''}

    <div class="card" data-min-level="advanced">
      <div class="card__title">${t('utility.cards.monthlyTable', { year: yrLabel })}</div>
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
          <span class="muted" style="font-size:12px;margin-right:8px">${tp('utility.cards.deliveriesCount', deliveries.length)}</span>
          <button class="btn btn-${u.key} btn--sm" id="btn-new-delivery">${t('utility.action.newDelivery')}</button>
        </span>
      </div>
      ${deliveriesTable(deliveries, u)}
    </div>
    ` : isPeriod ? `
    <div class="card" data-role="list-card">
      <div class="card__title">${t('utility.periods.title')}
        <span class="card__title-action">
          ${listYearSelect(years, yr)}
          <span class="muted list-count" data-role="list-count">${listCountText(listInYear.length, listAll.length, yr)}</span>
          <button class="btn btn-${u.key} btn--sm" id="btn-new-period">${t('utility.periods.add')}</button>
        </span>
      </div>
      <div data-role="list-body"></div>
    </div>
    ` : `
    <div class="card" data-role="list-card">
      <div class="card__title">${t('utility.cards.readings')}
        <span class="card__title-action">
          ${listYearSelect(years, yr)}
          <span class="muted list-count" data-role="list-count">${listCountText(listInYear.length, listAll.length, yr)}</span>
          <button class="btn btn-${u.key} btn--sm" id="btn-new-reading">${t('utility.action.newReading')}</button>
        </span>
      </div>
      <div data-role="list-body"></div>
    </div>
    `}
    ${u.supports_bill_check ? billCheckLink(u, meter, settings, billing ? { sel, list: bList } : null) : ''}
    <div data-min-level="expert">${dhwNote(monthly, yr)}</div>
    <div data-role="co2-cost" data-min-level="expert"></div>
    <!-- v3.7.0 — Ladestrom-Nachweis und Jahresarbeitszahl ab Erfahren (bis v3.6 Experte): nachweisen und prüfen -->
    ${u.key === 'strom' && meter.role === 'ev_charger' ? '<div data-role="evcc" data-min-level="advanced"></div><div data-role="ev-report" data-min-level="advanced"></div>' : ''}
    ${(u.key === 'strom' && meter.role === 'heat_pump') || (u.key === 'waerme' && meter.role === 'heat_pump_output') ? '<div data-role="heat-pump" data-min-level="advanced"></div>' : ''}
  `;
  // v3.1.0 (H7, MKT-18) — Jahresarbeitszahl am Strom- und am Wärmezähler der Wärmepumpe
  if ((u.key === 'strom' && meter.role === 'heat_pump') || (u.key === 'waerme' && meter.role === 'heat_pump_output')) {
    api.heatPump(yr).then(r => {
      const el = container.querySelector('[data-role="heat-pump"]');
      const p = (r?.pumps || []).find(x => x.heat_meter_id === meter.id || (x.elec_meter_ids || []).includes(meter.id));
      if (el && my === rerenderSeq) el.innerHTML = heatPumpCardHtml(p, r?.reference, yr);
    }).catch(() => {});
  }
  // v3.1.0 (H6, MKT-14) — Wallbox: Ladestrom-Nachweis für den Dienstwagen
  if (u.key === 'strom' && meter.role === 'ev_charger') {
    loadEvReport(container, meter, new Date().getFullYear() - 1);
    loadEvcc(container, meter, yr, my);   // v3.2.0 (F1022)
  }
  // v3.1.0 (H4, CALC-27) — CO₂-Preis im Brennstoff (nur wo das Land ihn kennt)
  if (['gas', 'heizoel', 'fernwaerme', 'waerme'].includes(u.key)) {
    api.co2Costs(yr).then(c => {
      const row = c?.supported ? (c.rows || []).find(r => r.utility === u.key) : null;
      const el = container.querySelector('[data-role="co2-cost"]');
      if (!row || !el || my !== rerenderSeq) return;
      el.innerHTML = co2CostCard(row, c, yr);
    }).catch(() => {});
  }

  // v3.2.0 (F1020) — Schaubilder: Geld und Vertrag, Wetter, Energiefluss
  fillExplainers(container, { u, contract: isDelivery ? null : currentContract, monthly, yr, pvSummary, feedIn: isFeedIn, quality: consumptionData.anomaly_quality });

  // Chart
  if (!noValues) drawMonthChart('month-chart', monthlyYear, u, yr, prevYear, chartMode, chartNames);
  if (isDelivery) drawStockChart('stock-chart', stockHist, u, yr);
  if (!isDelivery && currentContract) drawBalanceChart('balance-chart', currentContract, u);
  if (pvFlowRows.length) drawPvFlowChart('pv-flow-chart', pvFlowRows, yr, await getUtilities().catch(() => []));

  // Wire up events
  wireEvents(container, u, meter, readings, contracts, deliveries, monthly, periods);
  if (!isDelivery) {
    wireList(container, { u, meter, isPeriod, rows: state.listAllYears ? listAll : listInYear, total: listAll.length,
      yr, readings, periods, warnByReading });
  }
  if (isDelivery) wireTankLevels(container, u, meter);
  // v3.6.0 (Paket abrechnung) — Kalenderjahr: „Rechnung prüfen“ wie die Seite selbst
  if (u.supports_bill_check && !billing && !isPeriod) fillBillCheckLink(container, u, meter, settings, my);
  // v3.6.0 (F1030) — Umschalter und Periodenwahl
  wirePeriodMode(container);
  container.querySelectorAll('.period-pills .pill').forEach(p => p.addEventListener('click', () => {
    state.periodByUtility[u.key] = p.getAttribute('data-period');
    rerender(container);
  }));
  syncAddress(u, latestYear, billing ? { from: sel.from, latest: bList[bList.length - 1].from } : null);
}

/** v3.6.0 (F1030) — Wahl der Abrechnungsperiode (Name vom Server, sonst die Daten). */
function periodPills(list, sel, utilityKey) {
  return `<div class="year-pills period-pills" role="group" aria-label="${escapeHtml(t('billing.period.pills'))}">
    ${list.map(p => {
      const on = p.from === sel.from;
      return `<button type="button" class="pill ${on ? 'active ' + utilityKey : ''}" data-period="${escapeHtml(p.from)}" aria-pressed="${on}">${escapeHtml(periodName(p, fmt.date))}${p.running ? ' ·' : ''}</button>`;
    }).join('')}
  </div>`;
}

/** v3.6.0 (F1030) — Zeitraum, Grundlage und Stand der gewählten Periode; Vorschlag, wenn der Stichtag nicht zur Rechnung passt. */
function periodMeta(sel, bp) {
  const parts = [t(sel.source === 'bill' ? 'billing.period.fromBill' : 'billing.period.fromAnchor', { from: fmt.date(sel.from), until: fmt.date(sel.until) })];
  if (sel.as_of && sel.as_of < sel.until) parts.push(t('billing.period.asOf', { date: fmt.date(sel.as_of) }));
  // v3.6.0 — ohne Schlussrechnung endet die Periode am Lieferantenwechsel
  if (sel.ends_at_switch) parts.push(t('billing.period.switch'));
  if (sel.running) parts.push(t('billing.period.running'));
  else if (!sel.complete) parts.push(t('billing.period.partial'));
  const lastBill = [...(bp?.periods || [])].reverse().find(p => p.source === 'bill');
  const md = (s) => { const [m, d] = String(s).split('-').map(Number); return new Date(Date.UTC(2000, m - 1, d)).toLocaleDateString(getLocale(), { day: 'numeric', month: 'long', timeZone: 'UTC' }); };
  // v3.7.0 (#33) — ohne Rechnung kommt der Vorschlag aus den Vertragsbeginnen
  const text = !bp?.suggested_anchor ? null
    : bp.suggested_anchor_source === 'contracts' ? t('billing.suggestContracts', { anchor: md(bp.anchor), day: md(bp.suggested_anchor) })
    : lastBill ? t('billing.suggest', { date: fmt.date(lastBill.until), anchor: md(bp.anchor), day: md(bp.suggested_anchor) }) : null;
  const hint = text ? `<p class="muted small" data-role="anchor-hint" data-source="${escapeHtml(bp.suggested_anchor_source || 'bill')}">${escapeHtml(text)}</p>` : '';
  return `<p class="muted small period-meta" data-role="period-meta">${escapeHtml(parts.join(' · '))}</p>${hint}`;
}

/**
 * v3.2.0 (F1020) — Schaubilder einer Verbrauchsart in jeder Stufe: Sie
 * erklären, was die Karten darüber in Zahlen sagen.
 */
function fillExplainers(container, { u, contract, monthly, yr, pvSummary, feedIn, quality = null }) {
  const box = container.querySelector('[data-role="explainers"]');
  if (!box) return;
  const parts = [];
  if (contract && !feedIn) {
    parts.push(moneyHtml(contract, { color: u.color }));
    parts.push(timelineHtml(contract, todayIso()));
  }
  if (u.hgt_relevant) parts.push(weatherHtml(monthly, { quality }));   // v3.7.0 (Nachlauf) — Gütesperre
  // v3.4.0 (F1026) — die ganze Antwort: Jahr und Monat umschaltbar, Start im
  // gewählten Jahr (sonst das jüngste)
  if (isPv(u) && pvSummary) parts.push(energyHtml(pvSummary, { year: yr }));
  const html = parts.filter(Boolean);
  if (!html.length) return;
  box.innerHTML = `<h2 class="card__title">${escapeHtml(t('dashboard.explainers.title'))}</h2>
    <div class="explainer-grid">${html.map(x => `<div>${x}</div>`).join('')}</div>`;
  box.hidden = false;
  playExplainers(box);
}

// ── F1012: Rechnungsprüfung — seit v2.11.0 eine eigene Seite (views/bill-check.js)
// v3.1.0 (H5) — für jede Art mit Rechnungsprüfung (Strom, Wasser, Fernwärme, Gas)
// v3.4.0 (N1021) — mit der letzten abgeschlossenen Abrechnungsperiode nach dem
// Stichtag der Art (wie die Seite selbst, wenn sie ohne Zeitraum öffnet). Bis
// v3.3 nahm der Verweis das angezeigte, meist laufende Jahr — eine Lücke bis
// zum 31.12. mit „keine Ablesung“.
// v3.6.0 (Paket abrechnung) — im Abrechnungsjahr die gewählte Periode, wenn sie
// abgeschlossen ist, sonst die jüngste abgeschlossene (wie die Seite selbst,
// periodToCheck). Im Kalenderjahr zuerst nach dem Stichtag; die Perioden des
// Servers kommen nach (fillBillCheckLink) — beide Wege bleiben gleich, auch
// wenn der Versorger in einem anderen Rhythmus abrechnet.
function billCheckLink(u, meter, settings = {}, billing = null) {
  const anchor = settings[`billing_cycle_anchor_${u.key}`];
  const period = billing?.sel?.complete && !billing.sel.running
    ? { from: billing.sel.from, until: billing.sel.until }
    : billing ? periodToCheck(billing.list, anchor, todayIso()) : lastClosedPeriod(anchor, todayIso());
  return `
    <div class="card card--link" data-min-level="advanced" data-role="bill-check-link">
      <div class="card__title">${t('nav.billCheck')}</div>
      <p class="muted">${u.key === 'gas' ? t('utility.billCheck.hint') : t('utility.billCheck.hintGeneric')}</p>
      <a class="btn btn--ghost btn--sm" href="${billCheckHref(u, meter, period)}">${escapeHtml(billCheckLabel(period))}</a>
    </div>`;
}

function billCheckHref(u, meter, period) {
  return `#/bill-check?utility=${encodeURIComponent(u.key)}&meter=${encodeURIComponent(meter.id)}&from=${period.from}&until=${period.until}`;
}

function billCheckLabel(period) {
  const year = calendarYearOf(period);
  return year != null ? t('utility.billCheck.open', { year })
    : t('utility.billCheck.openPeriod', { from: fmt.date(period.from), to: fmt.date(period.until) });
}

/** v3.6.0 (Paket abrechnung) — Kalenderjahr: der Verweis mit den Perioden des Servers (wie bill-check.js). */
function fillBillCheckLink(container, u, meter, settings, my) {
  api.meterBillingPeriods(u.key, meter.id).then(bp => {
    const a = container.querySelector('[data-role="bill-check-link"] a');
    if (!a || my !== rerenderSeq) return;
    const period = periodToCheck(bp?.periods, settings[`billing_cycle_anchor_${u.key}`], todayIso());
    a.setAttribute('href', billCheckHref(u, meter, period));
    a.textContent = billCheckLabel(period);
  }).catch(() => { /* der Verweis nach dem Stichtag bleibt */ });
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

// v3.7.0 (wirklichkeit-2) — dieselben Sätze wie im Jahresbericht
// (ReportAdviceService::uncertainBalance): Spanne des Saldos zur Abrechnung,
// Grund, Spanne des Abschlags. Positiver Saldo = Nachzahlung.
function uncertainBalanceText(unc, date) {
  const word = (v) => Math.abs(v) < 1 ? t('balanceUncertain.even')
    : t(v > 0 ? 'balanceUncertain.due' : 'balanceUncertain.credit', { amount: t('report.fmt.approx', { amount: fmt.eur0(Math.abs(v)) }) });
  const parts = (unc.reasons || []).map(r => r === 'factor_clamped'
    ? t('balanceUncertain.why.factor_clamped', { raw: fmt.num(unc.factor_raw ?? 0, 2), used: fmt.num(unc.factor ?? 0, 2) })
    : r === 'few_measured' ? t('balanceUncertain.why.few_measured')   // v3.7.0 (Schritt 3, N3)
      : t('balanceUncertain.why.poor_fit', { pct: fmt.pct(unc.fit_ratio ?? 0, 0) }));
  const why = parts.length > 2 ? t('balanceUncertain.why.three', { a: parts[0], b: parts[1], c: parts[2] })
    : parts.length > 1 ? t('balanceUncertain.why.both', { a: parts[0], b: parts[1] }) : (parts[0] ?? '');
  const [lo, hi] = unc.projected_range || [0, 0];
  const text = t('balanceUncertain.range', { date, low: word(lo), high: word(hi), why });
  const s = unc.suggested_range;
  return Array.isArray(s) ? `${text} ${t('balanceUncertain.advance', { low: fmt.eur0(s[0]), high: fmt.eur0(s[1]) })}` : text;
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
    ? tp('utility.balance.consumedMonthsWater', c.months_actual, { months: c.months_actual, m3: fmt.num(c.actual_m3 || 0, 1) })
    : tp('utility.balance.consumedMonths', c.months_actual, { months: c.months_actual, kwh: fmt.int(c.actual_kwh) });
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
  // v3.7.0 (wirklichkeit-2) — unsichere Schätzung (Kalibrierfaktor klemmt,
  // Heizmodell schlecht): Spanne und Grund statt eines Vorschlags; der Server
  // liefert dann keinen `suggested_advance`
  const unc = !isFeedIn ? c.projection_uncertain : null;
  const uncertainHtml = unc
    ? `<p class="balance-suggest" data-role="balance-uncertain">${escapeHtml(uncertainBalanceText(unc, fmt.date(c.effective_end)))}</p>`
    : '';
  // v2.9.0 (CALC-10, CALC-11) — weiterlaufender Vertrag, verpasste Frist,
  // angekündigte Preiserhöhung
  const notes = [];
  // v3.6.0 (Paket abrechnung) — Saldo erst nach der letzten gebuchten Rechnung
  if (c.balance_from && c.start && c.balance_from > c.start) notes.push(t('utility.balance.sinceBill', { date: fmt.date(c.balance_from) }));
  if (c.renewed) notes.push(t('utility.balance.renewed', { date: fmt.date(c.end) }));
  // v3.7.0 (wirklichkeit-2) — ohne Arbeitspreis: dieselbe Aussage wie im Abrechnungsjahr
  if (c.price_missing) notes.push(t('priceMissing.contract'));
  // v3.9.0 (F1032, #40) — Rest-Abrechnung: wie der Vertrag rechnet (gemessen − Subzähler)
  if (c.rest_of) notes.push(restNoteText(c.rest_of, u.consumption_unit));
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
          ${!isFeedIn && specialHtml ? `<div class="balance-col__sub" style="margin-top:6px">${tp('utility.balance.specialCount', spCount)}</div>${specialHtml}` : ''}
        </div>
        <div>
          <div class="balance-col__label">${isFeedIn ? t('utility.balance.colClaimFeedIn') : t('utility.balance.colBalance')}${info('balance')}</div>
          <!-- v2.13.0 (Review UI-18) — Kundensicht ohne Vorzeichen; die Farbe
               folgt der Bedeutung (bis v2.12 hieß die rote Klasse „positive") -->
          <div class="balance-col__value ${curView?.credit ? 'balance-col__value--credit' : curView?.credit === false ? 'balance-col__value--due' : ''}">${fmt.eur(curView ? curView.amount : cur)}</div>
          <div class="balance-col__sub">${isFeedIn ? (cur > 0 ? t('utility.balance.subCredit') : cur < 0 ? t('utility.balance.subReclaim') : t('utility.balance.subBalanced')) : (cur > 0 ? t('utility.balance.subUnderpaid') : cur < 0 ? t('utility.balance.subOverpaid') : t('utility.balance.subBalanced'))}</div>
        </div>
        <div class="balance-verdict ${unc ? 'balanced' : verdictCls}">
          <div class="balance-verdict__label">${unc ? '↕' : arrow} ${t('utility.balance.verdictLabel')}</div>
          <!-- v3.7.0 (Nachlauf) — unsicher: nur die Spanne, kein Punktwert „≈ X €“ -->
          <div class="balance-verdict__value"${unc ? ' data-role="verdict-range"' : ''}>${unc ? escapeHtml(uncertainRangeText(unc)) : fmt.eur(Math.abs(proj || 0))}</div>
          <div class="balance-verdict__verdict">${unc ? t('balanceUncertain.verdict') : t('utility.verdict.' + verdict)}</div>
          <div class="balance-verdict__date">${escapeHtml(dateLabel)}</div>
        </div>
      </div>
      ${suggestHtml}
      ${uncertainHtml}
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
  // v3.7.0 (#39) — verteilte Monate: geschätzt (≈ in der Tabelle, schraffiert im Diagramm)
  const anyInterp = rows.some(isInterpolated);
  return `
    <div class="card">
      <div class="card__title">☀️ ${escapeHtml(t('utility.pvFlow.title', { year }))}${info('selfConsumption')}</div>
      <div class="chart-wrap h300"><canvas id="pv-flow-chart"></canvas></div>
      <p class="chart-note">${escapeHtml(t('utility.pvFlow.note') + (anyInterp ? ' ' + t('interpolation.chartNote') : ''))}</p>
      ${chartTableHtml({
        caption: t('utility.pvFlow.title', { year }),
        columns: [t('utility.monthlyTable.colMonth'), t('utility.pvFlow.generation'), t('utility.pvFlow.self'), t('utility.pvFlow.feedIn'), t('utility.pvFlow.grid')],
        rows: rows.map(m => [fmt.month(m.ym) + (isInterpolated(m) ? ` ${MARK}` : ''), k(m.erzeugung_kwh), k(m.eigenverbrauch_kwh), k(m.einspeisung_kwh), k(m.bezug_kwh)]),
      })}
      ${anyInterp ? `<p class="muted small" data-role="pv-interpolated-legend">${escapeHtml(t('interpolation.legendPv'))}</p>` : ''}
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
  // v3.7.0 (#39) — verteilte Monate schraffiert: Ihre Aufteilung ist eine Schätzung
  const fill = (u) => (ctx) => (isInterpolated(rows[ctx.dataIndex]) ? hatchFill(chartColor(u)) : withAlpha(chartColor(u), 0.55));
  _pvChart = makeChart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { type: 'bar', label: t('utility.pvFlow.self'), data: rows.map(m => m.eigenverbrauch_kwh ?? null),
          backgroundColor: fill(gen), borderColor: utilColor(gen), borderWidth: 1, stack: 'pv', order: 2 },
        { type: 'bar', label: t('utility.pvFlow.feedIn'), data: rows.map(m => m.einspeisung_kwh ?? null),
          backgroundColor: fill(feed), borderColor: utilColor(feed), borderWidth: 1, stack: 'pv', order: 2 },
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
          const lines = [];
          if (m?.erzeugung_kwh != null) lines.push(t('utility.pvFlow.generationTip', { value: fmt.unit(m.erzeugung_kwh, 'kWh', 0) }));
          if (isInterpolated(m)) lines.push(`${MARK} ${t('interpolation.markTitleOpen')}`);
          return lines.join('\n');
        },
      } } },
      scales: { x: { stacked: true }, y: { stacked: true, title: { display: true, text: 'kWh' } } },
    },
  }, { label: tp('utility.pvFlow.alt', covered.length, { year, months: covered.length,
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
  // liefert (Gas/Strom/Fernwärme, seit v3.5.0 auch Wasser — GitHub #34). Die
  // Einspeisung kennt keine Sonderzahlungen; dort fehlt das Feld, und die
  // Spalte entfällt.
  const showSpecial = contracts.some(c => Array.isArray(c.special_payments));
  // v3.4.0 (N1021) — wie Kosten → Verträge: Zukünftig · Aktuell · Beendet,
  // jeweils der neueste oben (lib/contract-order.js), „Beendet“ ab drei
  // Einträgen eingeklappt. Bis v3.3 die Reihenfolge des Servers: älteste oben,
  // der Gruppenvertrag hinten angehängt.
  const cols = showSpecial ? 11 : 10;
  const groupBody = (g) => {
    const head = g.group === 'past'
      ? `<button type="button" class="link-btn contracts-table__toggle" data-toggle-group="past" aria-expanded="${!g.collapsed}">${escapeHtml(tp('contracts.phaseGroup.past', g.items.length))}</button>`
      : escapeHtml(t('contracts.phaseGroup.' + g.group));
    return `<tbody data-phase-group="${g.group}">
      <tr class="contracts-table__group"><th scope="rowgroup" colspan="${cols}">${head}</th></tr>
      ${g.items.map(c => contractRow(c, g.collapsed)).join('')}
    </tbody>`;
  };
  const contractRow = (c, hidden) => {
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
      return `<tr data-group-row data-contract-id="${escapeHtml(c.contract_id || '')}"${hidden ? ' hidden' : ''}>
        <td class="provider-cell">${escapeHtml(c.provider || '–')}${c.tariff_name ? `<small>${escapeHtml(c.tariff_name)}</small>` : ''}</td>
        <td class="period-cell">${period}</td>
        <td>${phasePill(c)}</td>
        <td class="num muted" style="font-size:11px">${tariffStr}</td>
        <td class="num">${c.current_advance_amount != null ? fmt.eur(c.current_advance_amount) : '–'}</td>
        <td class="num">${fmt.eur(c.cost_to_date ?? c.actual_cost)}</td>
        <td class="num">${fmt.eur(c.advance_paid)}</td>
        <td class="num success-text">${bonusStr}</td>
        ${showSpecial ? specialCell(c) : ''}
        ${balCell(cur)}
        ${c.projection_uncertain ? `<td class="num muted" data-role="expected-range">${escapeHtml(uncertainRangeText(c.projection_uncertain))}</td>` : balCell(proj, true)}
      </tr>`;
  };
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
    ${groupContracts(contracts).map(groupBody).join('')}
  </table></div>
  <p class="muted" style="font-size:11px;margin:8px 4px 0">${t(feedInKind(u) ? 'saldo.tableLegendFeedIn' : 'saldo.tableLegend')}${showSpecial ? ' ' + t('utility.contractsTable.hint') : ''}</p>
  ${contracts.filter(c => c.rest_of).map(c => `<p class="muted small" data-role="rest-contract-note" style="margin:4px 4px 0">${escapeHtml((c.provider || c.tariff_name || '–') + ': ' + restNoteText(c.rest_of, u.consumption_unit))}</p>`).join('')}`;
}

// v2.5.1 — Zelle „Sonderzahlungen": Netto aus Kundensicht. Erhalten (Rück-
// zahlung) zählt positiv, gezahlt (Nach-/Abschlagszahlung) negativ — das ist
// dieselbe Größe, die der Saldo als `special_payment_net` addiert.
// v2.13.0 (Review UI-17) — die Einzelposten zum Aufklappen statt im Tooltip,
// den es auf dem iPhone nicht gibt.
function specialCell(c) {
  // v3.4.0 (N1021) — die neueste Zahlung oben, wie die Vertragslisten
  const items = (Array.isArray(c.special_payments) ? [...c.special_payments] : [])
    .sort((a, b) => String(b?.date || '').localeCompare(String(a?.date || '')));
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
        ? ` <span class="muted" title="${escapeHtml(tp('utility.monthlyTable.estimatedTitle', m.estimated_days, { days: m.estimated_days }))}">≈</span>`
        // v3.7.0 (#37) — verteilt statt gemessen; Text auch für Screenreader
        : isInterpolated(m) ? ` <span class="muted" data-role="interpolated" title="${escapeHtml(markTitle(m))}"><span aria-hidden="true">${MARK}</span><span class="sr-only">${escapeHtml(markTitle(m))}</span></span>` : '';
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
  ${monthly.some(isInterpolated) ? `<p class="muted" style="font-size:12px;margin-top:8px" data-role="interpolated-legend">${escapeHtml(t('interpolation.legend'))}</p>` : ''}
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

// v3.4.0 (N1021) — Leerzustände der Liste: gar keine Ablesung, keine im Jahr
function readingsEmpty(total, year) {
  if (!total) return `<div class="empty" style="padding:32px"><div class="empty-icon">📋</div><h2>${t('utility.readingsTable.emptyTitle')}</h2></div>`;
  return `<div class="empty" style="padding:32px">
      <div class="empty-icon">📋</div>
      <h2>${t('utility.readingsTable.emptyYear', { year })}</h2>
      <p class="muted">${tp('utility.readingsTable.emptyYearHint', total)}</p>
    </div>`;
}

/** Tabelle der übergebenen Ablesungen (schon gefiltert, sortiert und ggf. eine Seite). */
function readingsTable(sorted, u, warnByReading = new Map()) {
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
        ${r.attachment_id ? `<button class="icon-btn icon-btn--thumb" data-action="view-photo" data-id="${escapeHtml(r.id)}" title="${t('readingsEntry.photo.view')}" aria-label="${t('readingsEntry.photo.view')}"><img class="reading-thumb" src="${escapeHtml(api.attachmentUrl(r.attachment_id))}" alt="" loading="lazy" decoding="async"></button>` : ''}
        ${r.is_suspect ? `<button class="icon-btn" data-action="confirm-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.confirmReading')}" aria-label="${t('utility.readingsTable.confirmReading')}"><span aria-hidden="true">✅</span></button>` : ''}
        <button class="icon-btn" data-action="edit-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.edit')}" aria-label="${t('utility.readingsTable.edit')}"><span aria-hidden="true">✏️</span></button>
        <button class="icon-btn" data-action="delete-reading" data-id="${escapeHtml(r.id)}" title="${t('utility.readingsTable.delete')}" aria-label="${t('utility.readingsTable.delete')}"><span aria-hidden="true">🗑️</span></button>
      </td>
    </tr>`;
    }).join('')}
    </tbody>
  </table></div>`;
}

// ── v3.1.0 (H3, B2) — Verbrauch je Zeitraum ──────────────────────────
// ── v3.4.0 (N1021) — Liste der Ablesungen bzw. Zeiträume ────────────
// Jahr direkt in der Karte (mit „Alle Jahre“), Anzahl im Jahr und gesamt,
// Blättern ab mehr als 50 Zeilen (Tagesdaten aus Home Assistant oder evcc),
// „nur auffällige“, damit PRÜFEN/UNPLAUSIBEL nicht auf Seite 7 verschwinden.

/** Kleine Jahreswahl der Karte: neueste Jahre zuerst, dazu „Alle Jahre“. */
function listYearSelect(years, yr) {
  if (!years.length) return '';
  const opts = [...years].sort((a, b) => b - a)
    .map(y => `<option value="${y}"${!state.listAllYears && y === yr ? ' selected' : ''}>${y}</option>`).join('');
  return `<select class="select list-year" data-role="list-year" aria-label="${escapeHtml(t('utility.list.yearLabel'))}">${opts}
    <option value="all"${state.listAllYears ? ' selected' : ''}>${escapeHtml(t('utility.list.allYears'))}</option></select>`;
}

/** „10 in 2026 · 43 gesamt“, bei „Alle Jahre“ nur die Gesamtzahl. */
function listCountText(inYear, total, yr) {
  const all = tp('utility.list.countTotal', total);
  return escapeHtml(state.listAllYears ? all : `${tp('utility.list.countInYear', inYear, { year: yr })} · ${all}`);
}

/**
 * Liste zeichnen und bedienen. Blättern und Filter zeichnen nur die Liste neu;
 * die Zeilenaktionen hängen deshalb an der Liste, nicht an jeder Zeile.
 */
function wireList(container, { u, meter, isPeriod, rows, total, yr, readings, periods, warnByReading }) {
  const card = container.querySelector('[data-role="list-card"]');
  const body = card?.querySelector('[data-role="list-body"]');
  if (!body) return;
  const listKey = isPeriod ? PERIODS_PAGER : READINGS_PAGER;
  const flagged = (r) => !isPeriod && (r.is_suspect || warnByReading.has(r.id));
  const sorted = isPeriod
    ? [...rows].sort((a, b) => b.from.localeCompare(a.from))
    : [...rows].sort((a, b) => b.date.localeCompare(a.date));
  const nFlagged = sorted.filter(flagged).length;
  if (!nFlagged) state.list.flaggedOnly = false;

  const draw = () => {
    const shown = state.list.flaggedOnly ? sorted.filter(flagged) : sorted;
    const p = paginate(shown, { page: state.list.page, size: pageSize(listKey) });
    state.list.page = p.page;
    const filter = nFlagged && (state.list.flaggedOnly || sorted.length > PAGER_THRESHOLD)
      ? `<label class="settings-field__check list-filter"><input type="checkbox" data-role="flagged-only"${state.list.flaggedOnly ? ' checked' : ''}> ${escapeHtml(tp('utility.readingsTable.onlyFlagged', nFlagged))}</label>`
      : '';
    const table = isPeriod ? periodsTable(p.items, u)
      : (p.items.length ? readingsTable(p.items, u, warnByReading) : readingsEmpty(total, yr));
    body.innerHTML = filter + table + pagerHtml(p, { listKey });
    wirePager(body, p, { listKey, onChange: ({ page }) => { state.list.page = page; draw(); } });
    body.querySelector('[data-role="flagged-only"]')?.addEventListener('change', (ev) => {
      state.list.flaggedOnly = ev.target.checked;
      state.list.page = 1;
      draw();
      body.querySelector('[data-role="flagged-only"]')?.focus();
    });
  };
  draw();

  card.querySelector('[data-role="list-year"]')?.addEventListener('change', (ev) => {
    const v = ev.target.value;
    state.listAllYears = v === 'all';
    if (v !== 'all') state.yearByUtility[u.key] = Number(v);
    rerender(container);
  });

  body.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn || !body.contains(btn)) return;
    const id = btn.getAttribute('data-id');
    switch (btn.getAttribute('data-action')) {
      case 'edit-reading':
        openReadingModal(container, u, meter, readings.find(r => r.id === id), readings);
        break;
      // v3.1.0 (H2, MKT-08) — Foto zur Ablesung groß ansehen, entfernen
      case 'view-photo': {
        const reading = readings.find(r => r.id === id);
        if (!reading?.attachment_id) return;
        const m = openModal({
          title: `${t('readingsEntry.photo.alt')} · ${fmt.date(reading.date)}`,
          size: 'lg',
          body: `<img class="photo-lightbox" src="${escapeHtml(api.attachmentUrl(reading.attachment_id))}" alt="${escapeHtml(t('readingsEntry.photo.alt'))} ${escapeHtml(fmt.date(reading.date))}">`,
          footer: `<a class="btn btn--ghost" href="${escapeHtml(api.attachmentUrl(reading.attachment_id))}" target="_blank" rel="noopener">${t('utility.photo.open')}</a>
            <button type="button" class="btn btn--danger" data-action="photo-remove">${t('readingsEntry.photo.remove')}</button>`,
        });
        m.modalEl.querySelector('[data-action="photo-remove"]').addEventListener('click', guardSubmit(m.modalEl.querySelector('[data-action="photo-remove"]'), async () => {
          try {
            await api.updateReading(u.key, reading.id, { attachment_id: null });
            m.close(true);
            toastOk(t('utility.photo.removed'));
            rerender(container);
          } catch (e) { toastErr(e.message); }
        }));
        break;
      }
      // v2.6.0 — Verdacht aus Home Assistant bestätigen: Der Stand zählt wieder.
      case 'confirm-reading':
        try {
          await api.updateReading(u.key, id, { is_suspect: false });
          toastOk(t('utility.toast.readingConfirmed'));
          rerender(container);
        } catch (e) { toastErr(e.message); }
        break;
      case 'delete-reading': {
        const reading = readings.find(r => r.id === id);
        const ok = await confirmModal({
          message: t('utility.confirm.deleteReading', { date: fmt.date(reading?.date) }),
          confirmLabel: t('utility.readingsTable.delete'), danger: true,
        });
        if (!ok) return;
        try { await api.deleteReading(u.key, id); toastOk(t('utility.toast.readingDeleted')); rerender(container); }
        catch (e) { toastErr(e.message); }
        break;
      }
      // v3.1.0 (H3, B2) — Zeiträume bearbeiten, löschen
      case 'edit-period':
        openPeriodModal(container, u, meter, periods.find(p => p.id === id), periods);
        break;
      case 'delete-period': {
        const p = periods.find(x => x.id === id);
        const ok = await confirmModal({
          message: t('utility.periods.confirmDelete', { from: fmt.date(p?.from), to: fmt.date(p?.to) }),
          confirmLabel: t('utility.readingsTable.delete'), danger: true,
        });
        if (!ok) return;
        try { await api.deletePeriod(u.key, p.id); toastOk(t('utility.periods.deleted')); rerender(container); }
        catch (e) { toastErr(e.message); }
        break;
      }
      default:
    }
  });
}

// v3.4.0 (N1021) — bekommt die Zeilen schon gefiltert und sortiert (Jahr bzw. „Alle Jahre“, Seite)
function periodsTable(list, u) {
  const unitOf = (p) => p.value_unit === 'meter' ? u.unit : (u.consumption_unit || u.unit);
  if (!list.length) {
    return `<div class="empty" style="padding:32px"><div class="empty-icon">📅</div><h2>${t('utility.periods.empty')}</h2>
      <p class="muted">${t('utility.periods.emptyHint')}</p></div>`;
  }
  return `<div class="table-wrap"><table class="table">
    <thead><tr>
      <th scope="col">${t('utility.periods.colPeriod')}</th>
      <th scope="col" class="num">${t('utility.periods.colValue', { unit: '' }).trim()}</th>
      <th scope="col">${t('utility.periods.colReference')}</th>
      <th scope="col">${t('utility.readingsTable.colNote')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>${list.map(p => {
      const ref = p.reference || {};
      const refs = ['prev_month', 'prev_year_month', 'average_user'].filter(k => ref[k] != null)
        .map(k => `${escapeHtml(t('utility.periods.ref.' + k))}: ${fmt.num(ref[k], 1)}`).join(' · ');
      return `<tr data-period-id="${escapeHtml(p.id)}">
        <td><strong>${fmt.date(p.from)} – ${fmt.date(p.to)}</strong> ${p.is_estimated ? `<span class="status-pill" style="background:var(--c-yellow-soft);color:var(--c-yellow)">${t('utility.readingsTable.estimated')}</span>` : ''}</td>
        <td class="num">${fmt.num(p.value, 1)} ${escapeHtml(unitOf(p))}</td>
        <td class="muted" style="font-size:12px">${refs}</td>
        <td class="muted" style="font-size:12px">${escapeHtml(p.note || '')}</td>
        <td style="text-align:right;white-space:nowrap">
          <button class="icon-btn" data-action="edit-period" data-id="${escapeHtml(p.id)}" title="${t('utility.readingsTable.edit')}" aria-label="${t('utility.readingsTable.edit')}"><span aria-hidden="true">✏️</span></button>
          <button class="icon-btn" data-action="delete-period" data-id="${escapeHtml(p.id)}" title="${t('utility.readingsTable.delete')}" aria-label="${t('utility.readingsTable.delete')}"><span aria-hidden="true">🗑️</span></button>
        </td>
      </tr>`;
    }).join('')}</tbody>
  </table></div>`;
}

// v3.1.0 (H4, CALC-27) — „CO₂-Preis im Jahr": steckt im Arbeitspreis, kein Aufschlag
function co2CostCard(row, c, year) {
  const src = t('co2cost.source.' + row.source);
  return `<div class="card">
    <h2 class="card__title">${escapeHtml(t('co2cost.title', { year }))}${info('co2Price')}</h2>
    <div class="kpi-grid">
      <div class="kpi"><div class="kpi__label">${t('co2cost.amount')}</div><div class="kpi__value">${fmt.eur(row.cost_eur_gross)}</div></div>
      <div class="kpi"><div class="kpi__label">${t('co2cost.perKwh')}</div><div class="kpi__value">${fmt.num(row.ct_per_kwh, 2)} ${escapeHtml(getCurrencyMinor())}/kWh</div></div>
      <div class="kpi"><div class="kpi__label">${t('co2cost.emissions')}</div><div class="kpi__value">${fmt.num(row.emissions_kg, 0)} kg</div></div>
      <div class="kpi"><div class="kpi__label">${t('co2cost.price')}</div><div class="kpi__value">${fmt.num(row.price_eur_t, 0)} ${escapeHtml(getCurrencySymbol())}/t</div></div>
    </div>
    <p class="muted small">${escapeHtml(t('co2cost.netNote', { net: fmt.eur(row.cost_eur_net) }))} ${escapeHtml(src)}${row.approx ? ' ' + escapeHtml(t('co2cost.approx')) : ''}${c.price?.assumed ? ' ' + escapeHtml(t('co2cost.priceAssumed')) : ''}</p>
  </div>`;
}

// v3.1.0 (H3, CALC-28) — Warmwasserzähler: Wärme als Rechenwert nach HeizkostenV § 9
// v3.1.0 (H6, MKT-14) — Ladestrom-Nachweis (BMF-Schreiben vom 11.11.2025): Jahr,
// Methode (Vertragspreis mit anteiligem Grundpreis oder Pauschale), CSV und PDF
async function loadEvReport(container, meter, year, method = 'contract', flat = '') {
  const el = container.querySelector('[data-role="ev-report"]');
  if (!el) return;
  let r = null, err = null;
  try { r = await api.evReport(meter.id, year, method, flat); } catch (e) { err = e.message; }
  const now = new Date().getFullYear();
  el.innerHTML = `<div class="card">
    <h2 class="card__title">${escapeHtml(t('evReport.title'))}</h2>
    <p class="muted small">${escapeHtml(t('evReport.intro'))}</p>
    <div class="form-row">
      <div class="field"><label for="ev-year">${t('evReport.year')}</label>
        <select class="select" id="ev-year">${[now, now - 1, now - 2, now - 3].map(y => `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`).join('')}</select></div>
      <div class="field"><label for="ev-method">${t('evReport.methodLabel')}</label>
        <select class="select" id="ev-method">${['contract', 'flat'].map(m => `<option value="${m}" ${m === method ? 'selected' : ''}>${escapeHtml(t('evReport.method.' + m))}</option>`).join('')}</select></div>
      <div class="field" ${method === 'flat' ? '' : 'hidden'}><label for="ev-flat">${t('evReport.flatLabel')}</label>
        <input class="input" id="ev-flat" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(flat !== '' ? formatForInput(Number(flat)) : formatForInput(r?.flat_ct))}"></div>
    </div>
    ${err ? `<p class="banner banner--info">${escapeHtml(err)}</p>` : `
      <p><strong>${escapeHtml(t('evReport.summary', { kwh: fmt.num(r.total.kwh, 0), amount: fmt.eur(r.total.amount_eur) }))}</strong></p>
      ${r.price_missing ? `<p class="muted small">${escapeHtml(t('evReport.priceMissing'))}</p>` : ''}
      <div class="form-actions">
        <a class="btn btn--ghost btn--sm" href="${escapeHtml(api.evReportUrl(meter.id, year, method, flat, 'csv'))}" download>${escapeHtml(t('evReport.downloadCsv'))}</a>
        <a class="btn btn--ghost btn--sm" href="${escapeHtml(api.evReportUrl(meter.id, year, method, flat, 'pdf'))}" target="_blank" rel="noopener">${escapeHtml(t('evReport.downloadPdf'))}</a>
      </div>`}
    <p class="muted small">${escapeHtml(t('evReport.disclaimer'))}</p>
  </div>`;
  const reload = () => {
    const raw = el.querySelector('#ev-flat')?.value.trim() || '';
    const n = raw === '' ? '' : parseDecimal(raw);
    loadEvReport(container, meter, Number(el.querySelector('#ev-year').value), el.querySelector('#ev-method').value, n == null ? '' : String(n));
  };
  el.querySelector('#ev-year').addEventListener('change', reload);
  el.querySelector('#ev-method').addEventListener('change', reload);
  el.querySelector('#ev-flat')?.addEventListener('change', reload);
}

// v3.2.0 (F1022) — Ladevorgänge aus evcc: Monatssummen mit Sonnenanteil und dem
// Preis, den evcc ausgewiesen hat; Übernahme per CSV-Export oder Abruf im
// Heimnetz, jeweils mit Vorschau. Der Energietracker steuert nichts — er
// rechnet nach, was evcc (oder Home Assistant) gesteuert hat.
async function loadEvcc(container, meter, year, my) {
  const el = container.querySelector('[data-role="evcc"]');
  if (!el) return;
  const [res, settings] = await Promise.all([api.evSessions(meter.id, year).catch(() => null), getSettings().catch(() => ({}))]);
  if (my !== rerenderSeq) return;
  const months = Object.entries(res?.monthly || {});
  const sum = months.reduce((a, [, m]) => ({ kwh: a.kwh + m.kwh, solar: a.solar + m.solar_kwh, n: a.n + m.sessions,
    price: m.price_eur == null ? a.price : (a.price ?? 0) + m.price_eur }), { kwh: 0, solar: 0, n: 0, price: null });
  const hasEndpoint = !!String(settings?.evcc_endpoint || '').trim();
  el.innerHTML = `<div class="card">
    <h2 class="card__title">${escapeHtml(t('evcc.title', { year }))}</h2>
    <p class="muted small">${escapeHtml(t('evcc.intro'))}</p>
    ${months.length ? `
      <p><strong>${escapeHtml(tp('evcc.summary', sum.n, { kwh: fmt.num(sum.kwh, 0), solar: fmt.pct(sum.kwh > 0 ? sum.solar / sum.kwh : 0, 0) }))}</strong>
        ${sum.price != null ? ` · ${escapeHtml(t('evcc.priceTotal', { amount: fmt.eur(sum.price) }))}` : ''}</p>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th>${escapeHtml(t('evcc.col.month'))}</th><th class="num">${escapeHtml(t('evcc.col.sessions'))}</th>
          <th class="num">kWh</th><th class="num">${escapeHtml(t('evcc.col.solar'))}</th><th class="num">${escapeHtml(t('evcc.col.price'))}</th></tr></thead>
        <tbody>${months.map(([ym, m]) => `<tr><td>${escapeHtml(fmt.month(ym))}</td><td class="num">${fmt.int(m.sessions)}</td>
          <td class="num">${fmt.num(m.kwh, 1)}</td><td class="num">${m.solar_pct == null ? '–' : fmt.pct(m.solar_pct / 100, 0)}</td>
          <td class="num">${m.price_eur == null ? '–' : fmt.eur(m.price_eur)}</td></tr>`).join('')}</tbody>
      </table></div>` : `<p class="muted">${escapeHtml(t('evcc.empty', { year }))}</p>`}
    <div class="fgrid">
      <div class="field"><label for="evcc-counters">${escapeHtml(t('evcc.countersLabel'))}</label>
        <select class="select" id="evcc-counters">${['auto', 'energy', 'none'].map(c => `<option value="${c}">${escapeHtml(t('evcc.counters.' + c))}</option>`).join('')}</select></div>
      <div class="field">
        <div class="field__actions">
          <label class="btn btn--ghost">${escapeHtml(t('evcc.chooseCsv'))}<input type="file" id="evcc-file" accept=".csv,text/csv" hidden></label>
          ${hasEndpoint ? `<button type="button" class="btn btn--ghost" id="evcc-sync">${escapeHtml(t('evcc.sync'))}</button>` : ''}
        </div>
      </div>
    </div>
    <p class="muted small">${escapeHtml(t(hasEndpoint ? 'evcc.syncHint' : 'evcc.noEndpoint'))}
      <a href="${escapeHtml(docUrl('evcc'))}">${escapeHtml(t('common.guide'))}</a></p>
  </div>`;

  // Vorschau (Trockenlauf) → bestätigen → übernehmen. Mehrere Ladepunkte mit
  // Zählerständen: erst den Ladepunkt dieses Zählers wählen (Stände gehören
  // zu genau einer Wallbox)
  const run = async (call) => {
    const counters = el.querySelector('#evcc-counters').value;
    let p, loadpoint = null;
    try { p = await call({ dryRun: true, counters }); }
    catch (e) {
      if (e.code !== 'errors.evcc.loadpointNeeded') { toastErr(e.message); return; }
      try {
        const names = (await call({ dryRun: true, counters: 'none' })).loadpoints || [];
        loadpoint = await pickLoadpoint(names);
        if (loadpoint == null) return;
        p = await call({ dryRun: true, counters, loadpoint });
      } catch (e2) { toastErr(e2.message); return; }
    }
    const lines = [
      tp('evcc.preview.sessions', p.sessions, { from: fmt.date(p.from), to: fmt.date(p.to), kwh: fmt.num(p.charged_kwh, 1) }),
      p.counter_source === 'none' ? t('evcc.preview.noReadings') : tp('evcc.preview.readings.' + p.counter_source, p.readings),
    ];
    if (p.replaces) lines.push(tp('evcc.preview.replaces', p.replaces));
    if (p.running) lines.push(t('evcc.preview.running'));
    if (loadpoint) lines.push(t('evcc.preview.onlyLoadpoint', { name: loadpoint }));
    else if ((p.loadpoints || []).length > 1) lines.push(t('evcc.preview.loadpoints', { names: p.loadpoints.join(', ') }));
    if (!await confirmModal({ title: t('evcc.preview.title'), message: lines.join(' '), confirmLabel: t('evcc.preview.apply') })) return;
    try {
      const r = await call({ counters, loadpoint });
      toastOk(tp('evcc.done', r.sessions));
      rerender(container);
    } catch (e) { toastErr(e.message); }
  };
  el.querySelector('#evcc-file').addEventListener('change', async (ev) => {
    const file = ev.target.files?.[0];
    if (!file) return;
    const text = await file.text();
    ev.target.value = '';
    run(opts => api.importEvcc(meter.id, text, opts));
  });
  el.querySelector('#evcc-sync')?.addEventListener('click', () => run(opts => api.syncEvcc(meter.id, opts)));
}

/** Ladepunkt wählen (F1022) — null bei Abbruch. */
function pickLoadpoint(names) {
  const ctrl = openModal({
    title: t('evcc.pick.title'),
    body: `<p class="muted">${escapeHtml(t('evcc.pick.hint'))}</p>
      <div class="field"><label for="evcc-lp">${escapeHtml(t('evcc.pick.label'))}</label>
      <select class="select" id="evcc-lp">${names.map(n => `<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`).join('')}</select></div>`,
    footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${escapeHtml(t('common.cancel'))}</button>
      <button type="button" class="btn btn--primary" data-act="ok">${escapeHtml(t('evcc.pick.ok'))}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="ok"]').addEventListener('click', () => close(modalEl.querySelector('#evcc-lp').value));
    },
  });
  // Escape, Zurück oder Abbrechen: null
  return ctrl.closedPromise.then(v => (typeof v === 'string' && v !== '' ? v : null));
}

// v3.1.0 (H7, MKT-18) — Jahresarbeitszahl: Wärme ÷ Strom der Wärmepumpe
function heatPumpCardHtml(p, ref, year) {
  if (!p) return `<div class="card"><h2 class="card__title">${escapeHtml(t('heatPump.title', { year }))}${info('jaz')}</h2>
    <p class="muted">${escapeHtml(t('heatPump.noHeatMeter'))}</p></div>`;
  return `<div class="card">
    <h2 class="card__title">${escapeHtml(t('heatPump.title', { year }))}${info('jaz')}</h2>
    ${p.jaz != null ? `
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi__label">${escapeHtml(t('heatPump.jaz'))}</div><div class="kpi__value">${fmt.num(p.jaz, 1)}</div>
          <div class="kpi__sub">${escapeHtml(tp('heatPump.coverage', p.months_covered))}</div></div>
        ${p.jaz_heating_season != null ? `<div class="kpi"><div class="kpi__label">${escapeHtml(t('heatPump.season'))}</div><div class="kpi__value">${fmt.num(p.jaz_heating_season, 1)}</div></div>` : ''}
        <div class="kpi"><div class="kpi__label">${escapeHtml(t('heatPump.energy'))}</div><div class="kpi__value">${fmt.int(p.heat_kwh)} / ${fmt.int(p.elec_kwh)}</div>
          <div class="kpi__sub">kWh</div></div>
      </div>
      <div class="table-wrap"><table class="table table--compact"><thead><tr>
        <th scope="col">${escapeHtml(t('heatPump.month'))}</th><th scope="col" class="num">${escapeHtml(t('heatPump.heat'))}</th>
        <th scope="col" class="num">${escapeHtml(t('heatPump.elec'))}</th><th scope="col" class="num">${escapeHtml(t('heatPump.cop'))}</th></tr></thead>
        <tbody>${p.months.map(m => `<tr><td>${escapeHtml(fmt.month(m.ym))}</td><td class="num">${fmt.int(m.heat_kwh)}</td><td class="num">${fmt.int(m.elec_kwh)}</td><td class="num">${fmt.num(m.cop, 1)}</td></tr>`).join('')}</tbody>
      </table></div>` : `<p class="muted">${escapeHtml(t(p.linked ? 'heatPump.noOverlap' : 'heatPump.notLinked'))}</p>`}
    <p class="muted small">${escapeHtml(t('heatPump.reference', { air: fmt.num(ref?.air_water ?? 3.4, 1), ground: fmt.num(ref?.ground_water ?? 4.3, 1) }))}</p>
  </div>`;
}

// v3.1.0 (H7, CALC-29) — PV: Speicher, Amortisation, Annahme Balkonkraftwerk, § 51 EEG
function pvExtrasHtml(pv, year) {
  if (!pv) return '';
  const y = (pv.yearly || []).find(x => x.year === year);
  const parts = [];
  if (y?.battery) {
    const b = y.battery;
    parts.push(`<div class="card"><h2 class="card__title">${escapeHtml(t('pv.battery.title', { year }))}</h2>
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi__label">${escapeHtml(t('pv.battery.charged'))}</div><div class="kpi__value">${fmt.int(b.charged_kwh)} kWh</div></div>
        <div class="kpi"><div class="kpi__label">${escapeHtml(t('pv.battery.discharged'))}</div><div class="kpi__value">${fmt.int(b.discharged_kwh)} kWh</div></div>
        ${b.efficiency_pct != null ? `<div class="kpi"><div class="kpi__label">${escapeHtml(t('pv.battery.efficiency'))}</div><div class="kpi__value">${fmt.num(b.efficiency_pct, 0)} %</div>
          <div class="kpi__sub">${escapeHtml(t('pv.battery.losses', { kwh: fmt.int(b.losses_kwh) }))}</div></div>` : ''}
        ${b.full_cycles != null ? `<div class="kpi"><div class="kpi__label">${escapeHtml(t('pv.battery.cycles'))}</div><div class="kpi__value">${fmt.num(b.full_cycles, 0)}</div></div>` : ''}
      </div></div>`);
  }
  const p = pv.payback;
  if (p) {
    parts.push(`<div class="card"><h2 class="card__title">${escapeHtml(t('pv.payback.title'))}</h2>
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi__label">${escapeHtml(t('pv.payback.investment'))}</div><div class="kpi__value">${fmt.eur(p.investment_eur)}</div></div>
        <div class="kpi"><div class="kpi__label">${escapeHtml(t('pv.payback.benefit'))}</div><div class="kpi__value">${fmt.eur(p.benefit_to_date_eur)}</div>
          ${p.avg_benefit_12m_eur != null ? `<div class="kpi__sub">${escapeHtml(t('pv.payback.perYear', { value: fmt.eur(p.avg_benefit_12m_eur) }))}</div>` : ''}</div>
        ${p.break_even_ym ? `<div class="kpi"><div class="kpi__label">${escapeHtml(t(p.break_even_projected ? 'pv.payback.expected' : 'pv.payback.reached'))}</div>
          <div class="kpi__value">${escapeHtml(fmt.month(p.break_even_ym))}</div>
          ${p.years_to_break_even != null ? `<div class="kpi__sub">${escapeHtml(t('pv.payback.years', { years: fmt.num(p.years_to_break_even, 1) }))}</div>` : ''}</div>` : ''}
      </div>
      <p class="muted small">${escapeHtml(t('pv.payback.hint'))}</p></div>`);
  }
  if (pv.self_consumption_assumed_pct != null) {
    parts.push(`<p class="muted small">${escapeHtml(t('pv.plugIn.assumed', { pct: fmt.num(pv.self_consumption_assumed_pct, 0) }))}</p>`);
  }
  if ((pv.hints || []).includes('negative_prices')) {
    parts.push(`<p class="banner banner--info">${escapeHtml(t('pv.hint51'))}</p>`);
  }
  return parts.join('');
}

function dhwNote(monthly, year) {
  const rows = (monthly || []).filter(m => m.dhw_kwh != null && String(m.ym || '').startsWith(String(year)));
  if (!rows.length) return '';
  const kwh = rows.reduce((s, m) => s + Number(m.dhw_kwh || 0), 0);
  return `<p class="muted" style="font-size:12px;margin:var(--sp-2) 0 0">${escapeHtml(t('utility.water.dhwNote', {
    kwh: fmt.num(kwh, 0), year, temp: fmt.num(rows[0].dhw_temp_c, 0) }))}${info('dhw')}</p>`;
}

function openPeriodModal(container, u, meter, period, periods = []) {
  const isGas = u.unit !== u.consumption_unit;
  const last = [...periods].sort((a, b) => b.to.localeCompare(a.to))[0];
  // neuer Zeitraum: der Monat nach dem letzten, sonst der Vormonat
  const start = (() => {
    const d = last ? new Date(last.to + 'T12:00:00') : new Date();
    if (last) d.setDate(d.getDate() + 1); else { d.setDate(1); d.setMonth(d.getMonth() - 1); }
    return d;
  })();
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const end = new Date(start.getFullYear(), start.getMonth() + 1, 0, 12);
  const from = period?.from || iso(start);
  const to = period?.to || iso(end);
  const ref = period?.reference || {};
  const body = `
    <form id="period-form">
      <!-- v3.4.0 (N1021) — Formularraster -->
      <div class="fgrid">
        <div class="field field--s"><label for="pf-from">${t('utility.periods.from')}</label><input class="input" id="pf-from" name="from" type="date" required value="${escapeHtml(from)}"></div>
        <div class="field field--s"><label for="pf-to">${t('utility.periods.to')}</label><input class="input" id="pf-to" name="to" type="date" required value="${escapeHtml(to)}"></div>
        <div class="field field--s field--new"><label for="pf-value">${t('utility.periods.value')}</label>
          <input class="input" id="pf-value" name="value" type="text" inputmode="decimal" autocomplete="off" required value="${escapeHtml(formatForInput(period?.value))}"></div>
        <div class="field field--s"><label for="pf-unit">${t('utility.periods.unit')}</label>
          <select class="input" id="pf-unit" name="value_unit" ${isGas ? '' : 'disabled'}>
            <option value="consumption" ${period?.value_unit === 'meter' ? '' : 'selected'}>${escapeHtml(u.consumption_unit)}</option>
            ${isGas ? `<option value="meter" ${period?.value_unit === 'meter' ? 'selected' : ''}>${escapeHtml(u.unit)}</option>` : ''}
          </select></div>
      </div>
      <fieldset class="fgroup">
        <legend>${t('readingsEntry.period.reference')}</legend>
        <div class="fgrid">
          ${['prev_month', 'prev_year_month', 'average_user'].map(k => `
          <div class="field field--s"><label for="pf-${k}">${t('utility.periods.ref.' + k)}</label>
            <input class="input" id="pf-${k}" data-ref="${k}" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(ref[k]))}"></div>`).join('')}
        </div>
      </fieldset>
      <div class="fgrid">
        <div class="field field--full"><label class="check"><input type="checkbox" name="is_estimated" ${period?.is_estimated ? 'checked' : ''}> ${t('utility.readingsTable.estimated')}</label></div>
        <div class="field field--full"><label for="pf-note">${t('utility.readingsTable.colNote')}</label>
          <input class="input input--text" id="pf-note" name="note" type="text" maxlength="500" value="${escapeHtml(period?.note || '')}"></div>
      </div>
    </form>`;
  openModal({
    title: period ? t('utility.periods.edit') : t('utility.periods.add'),
    body,
    footer: `<button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button type="button" class="btn btn-${u.key}" data-act="save">${t('common.save')}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(false));
      const saveBtn = modalEl.querySelector('[data-act="save"]');
      saveBtn.addEventListener('click', guardSubmit(saveBtn, async () => {
        const f = modalEl.querySelector('#period-form');
        const value = parseDecimal(f.value.value);
        if (value == null || value < 0) { toastErr(t('common.invalidNumber', { example: formatForInput(812.5) })); return; }
        const reference = {};
        for (const el of f.querySelectorAll('[data-ref]')) {
          const v = parseDecimal(el.value.trim());
          if (v != null && v >= 0) reference[el.dataset.ref] = v;
        }
        const data = {
          from: f.from.value, to: f.to.value, value, value_unit: f.value_unit.value || 'consumption',
          is_estimated: f.is_estimated.checked, note: f.note.value, reference,
        };
        try {
          if (period) await api.updatePeriod(u.key, period.id, data);
          else await api.createPeriod(u.key, { ...data, meter_id: meter.id });
          toastOk(t('utility.periods.saved'));
          close(true);
          rerender(container);
        } catch (e) { toastErr(e.message); }
      }));
    },
  });
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
      <!-- v3.4.0 (N1021) — Formularraster: Datum und Stand nebeneinander -->
      <div class="fgrid">
        <div class="field field--s">
          <label for="lf-date">${t('utility.tank.levelDate')}</label>
          <input class="input" id="lf-date" type="date" name="date" value="${escapeHtml(todayIso())}" max="${escapeHtml(todayIso())}" required>
        </div>
        <div class="field field--l">
          <label for="lf-level">${t('utility.tank.levelValue', { unit })}</label>
          <input class="input" id="lf-level" type="text" inputmode="decimal" autocomplete="off" name="level" required aria-describedby="lf-level-hint lf-level-msg">
          <small class="settings-field__hint" id="lf-level-hint">${t('utility.tank.levelHint')}</small>
          <div class="field-error" id="lf-level-msg" role="alert" hidden></div>
        </div>
        <div class="field field--full">
          <label for="lf-note">${t('utility.tank.levelNote')}</label>
          <input class="input input--text" id="lf-note" type="text" name="note" maxlength="80">
        </div>
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
// v3.6.0 (F1030) — `names` {current, prev}: im Abrechnungsjahr die Namen der
// Perioden statt Jahr/Vorjahr; die Vorperiode steht an derselben Stelle (`slot`)
function drawMonthChart(canvasId, monthly, u, year, prevRows = [], mode = 'measured', names = null) {
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
  // v3.7.0 (#37) — verteilte Monate schraffiert (Farbe aus u.color, nie --util-*)
  const interp = monthly.map(m => isInterpolated(m));
  const prevByMonth = new Map(prevRows.map(m => [m.slot ?? m.month, m]));
  const prev = monthly.map(m => prevByMonth.get(m.slot ?? m.month)?.[valKey] ?? null);
  const hasPrev = prev.some(v => v != null);
  const temp = monthly.map(m => m.avg_temp);
  // v2.13.0 (Review UI-19) — Sonnenstrom hängt nicht an der Temperatur; die
  // bereinigten Werte gelten für ein Normaljahr, die Temperatur gehört dann nicht dazu
  const withTemp = !isPv(u) && !adjusted;
  const barColor = (full, part) => (ctx) => withAlpha(chartColor(u), partial[ctx.dataIndex] ? part : full);
  const barFill = (ctx) => (interp[ctx.dataIndex] ? hatchFill(chartColor(u)) : barColor(0.35, 0.12)(ctx));
  const unit = u.consumption_unit;

  _chart = makeChart(canvas, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        ...(hasPrev ? [{
          type: 'bar',
          label: names ? t('billing.chart.prev', { name: names.prev }) : t('utility.chart.prevYear', { year: year - 1 }),
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
          backgroundColor: barFill,
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
          if (interp[i]) lines.push(`${MARK} ${markTitle(m)}`);
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
  }, { label: monthChartLabel(u, year, labels, values, prev, adjusted, names) });
}

/** Kurzbeschreibung des Monatscharts: Summe, stärkster und schwächster Monat, Vorjahr. */
function monthChartLabel(u, year, labels, values, prev = [], adjusted = false, names = null) {
  const s = seriesSummary(labels, values);
  if (!s) return t('utility.chart.alt');
  const unit = (v) => fmt.unit(v, u.consumption_unit, 0);
  let text = t(adjusted ? 'utility.chart.altSummaryAdjusted' : 'utility.chart.altSummary', {
    label: u.label, year: names ? names.current : year, total: unit(s.sum),
    maxMonth: s.max.label, max: unit(s.max.value),
    minMonth: s.min.label, min: unit(s.min.value),
  });
  // Vorjahr über dieselben Monate, soweit vorhanden
  const pairs = values.map((v, i) => [v, prev[i]]).filter(([v, p]) => v != null && p != null);
  if (pairs.length) {
    text += ' ' + tp('utility.chart.altPrev', pairs.length, { year: names ? names.prev : year - 1, total: unit(pairs.reduce((a, [, p]) => a + Number(p), 0)), months: pairs.length });
  }
  return text;
}

/** Hinweis unter dem Monatschart: Teilmonate und, bereinigt, was die Zahlen bedeuten. */
function monthChartNote(rows, mode) {
  const parts = [];
  if (mode === 'adjusted') parts.push(t('utility.chart.adjustedNote'));
  if (rows.some(isPartial)) parts.push(t('chart.partialLegend'));
  if (rows.some(isInterpolated)) parts.push(t('interpolation.chartNote'));   // v3.7.0 (#37)
  return escapeHtml(parts.join(' '));
}

// ── Event wiring ────────────────────────────────────────────────────
function wireEvents(container, u, meter, readings, contracts, deliveries = [], monthly = [], periods = []) {
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
      state.listAllYears = false;   // v3.4.0 — ein Jahr gewählt: die Liste folgt ihm
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
    container.querySelector(sel)?.addEventListener('click', () => (meter?.capture === 'period'
      ? openPeriodModal(container, u, meter, null, periods)
      : openReadingModal(container, u, meter, null, readings)));
  });
  // v3.1.0 (H3, B2) — Zeiträume anlegen; Bearbeiten, Löschen und die
  // Aktionen je Ablesung hängen seit v3.4.0 an der Liste (wireList)
  container.querySelector('#btn-new-period')?.addEventListener('click', () => openPeriodModal(container, u, meter, null, periods));

  // v3.4.0 (N1021) — die Gruppe „Beendet“ der Vertragstabelle auf- und zuklappen
  container.querySelectorAll('[data-toggle-group]').forEach(btn => {
    btn.addEventListener('click', () => {
      const open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', String(open));
      btn.closest('tbody')?.querySelectorAll('tr[data-group-row]').forEach(tr => { tr.hidden = !open; });
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
        <label class="check">
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
            ? tp('utility.preview.sinceLastDays', days, { delta, unit: u.unit, days })
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
      <!-- v3.4.0 (N1021) — Formularraster: Datum, Menge, Preis in einer Zeile -->
      <div class="fgrid">
        <div class="field field--s">
          <label for="df-date">${t('utility.deliveryModal.date')}</label>
          <input class="input" id="df-date" type="date" name="date" value="${escapeHtml(delivery?.date || today)}" required>
        </div>
        <div class="field field--s">
          <label for="df-quantity">${t('utility.deliveryModal.quantity', { unit })}</label>
          <input class="input" id="df-quantity" type="text" inputmode="decimal" autocomplete="off" name="quantity" value="${escapeHtml(formatForInput(delivery?.quantity))}" required aria-describedby="df-quantity-msg">
          <div class="field-error" id="df-quantity-msg" role="alert" hidden></div>
        </div>
        <div class="field field--s">
          <label for="df-unit-price">${t('utility.deliveryModal.unitPrice', { unit })}</label>
          <input class="input" id="df-unit-price" type="text" inputmode="decimal" autocomplete="off" name="unit_price_cents" value="${escapeHtml(formatForInput(delivery?.unit_price_cents))}" aria-describedby="df-unit-price-msg">
          <div class="field-error" id="df-unit-price-msg" role="alert" hidden></div>
        </div>
        <div class="field field--s">
          <label for="df-total">${t('utility.deliveryModal.total')}</label>
          <input class="input" id="df-total" type="text" inputmode="decimal" autocomplete="off" name="total_eur" value="${escapeHtml(formatForInput(delivery?.total_eur))}" aria-describedby="df-total-msg">
          <div class="field-error" id="df-total-msg" role="alert" hidden></div>
        </div>
        <div class="field field--l">
          <label for="df-supplier">${t('utility.deliveryModal.supplier')}</label>
          <input class="input input--text" id="df-supplier" type="text" name="supplier" value="${escapeHtml(delivery?.supplier || '')}">
        </div>
        <div class="field field--full">
          <label for="df-note">${t('utility.deliveryModal.note')}</label>
          <input class="input input--text" id="df-note" type="text" name="note" value="${escapeHtml(delivery?.note || '')}">
        </div>
        <div class="field field--full">
          <label class="check">
            <input type="checkbox" name="fill_to_full" ${delivery?.fill_to_full ? 'checked' : ''} aria-describedby="df-full-hint">
            <span>${t('utility.deliveryModal.fillToFull')}</span>
          </label>
          <small class="settings-field__hint" id="df-full-hint">${t('utility.deliveryModal.fillToFullHint')}</small>
        </div>
        <div class="field field--full">
          <label class="check">
            <input type="checkbox" name="is_planned" ${delivery?.is_planned ? 'checked' : ''}>
            <span>${t('utility.deliveryModal.planned')}</span>
          </label>
        </div>
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
