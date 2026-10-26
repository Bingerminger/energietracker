// =====================================================================
// Prognose — v3.6.0 (F1031): die Prognose-Werkstatt.
//
// Oben die Werkstatt: Regler und Eingaben links (am Telefon oben), Ergebnis-
// karte, Kennzahlen und Kurven rechts, sofort neu gerechnet (entprellt). Die
// Rechnung macht der Server (POST /api/forecast/scenario, ScenarioService) —
// hier wird nichts nachgerechnet. Stufen: Einsteiger drei Regler (Winter,
// sparen, Tarif) und drei Zahlen; Erfahren Maßnahmen, Raumtemperatur,
// Modellwahl, Rangliste; Experte alles, Modellvergleich, Band, Optimierung,
// CSV. Szenarien benennen, je Gerät speichern, vergleichen; Beispielszenarien
// zum Anklicken (lib/scenario.js).
//
// Darunter, aufklappbar, die bisherige Prognose im Detail (seit v1.1.0):
// Verlauf mit Prognose und Band, Monatstabelle mit Kosten, Abschlag, Saldo
// und Methode, Was-wäre-wenn über Temperaturversatz und Preisfaktor.
// =====================================================================

import { api } from '../api.js';
import { activeUtilities, getSettings } from '../state.js';
import { fmt, escapeHtml, parseDecimal, formatForInput } from '../lib/format.js';
import { makeChart, utilColor, tokenColor, chartTableHtml } from '../components/chart.js';
import { toastErr, toastOk } from '../components/toast.js';
import { t, tp } from '../lib/i18n.js';
import { info } from '../components/info.js';
import { balanceView, isFeedIn } from '../lib/semantics.js';
import { DEMO } from '../lib/demo-mode.js';
import * as S from '../lib/scenario.js';
import { resultCardHtml, downloadResultCard } from '../components/result-card.js';
import { docUrl } from '../lib/docs.js';
// v3.6.0 — derselbe Aufklappbereich wie „CO₂-Emissionsfaktoren“ in den Einstellungen
import { disclosureHtml, wireDisclosures } from '../components/disclosure.js';
import { fillHeatLimitSlot } from '../lib/heat-limit.js';   // v3.9.0 (#38)

const esc = escapeHtml;
let chart = null;
const CLASSIC_KEY = 'et-fc-classic-open';   // gemerkter Zustand je Gerät (seit v3.6.0 in components/disclosure.js)
const REGRESSION_MODELS = ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'];

const reducedMotion = () => {
  try { return !!window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches; } catch { return false; }
};
const signed = (v, f) => (v > 0 ? '+' : v < 0 ? '−' : '') + f(Math.abs(v));
// Prozent über Intl („10 %“, „10%“ je Sprache); die API liefert Prozentpunkte
const pctSigned = (v, d = 1) => signed(v, x => fmt.pct(x / 100, d));

export async function render(container, params = [], ctx = {}) {
  container.innerHTML = `<div class="loading">${t('forecast.loading')}</div>`;
  // v2.2.0 — nur aktive Verbrauchsarten anbieten, wie Dashboard und Seitenleiste
  const utilities = await activeUtilities();
  if (!utilities.length) {
    container.innerHTML = `<div class="banner banner--info">${esc(t('forecast.noUtilities'))}</div>`;
    return;
  }
  // Die Werkstatt rechnet für Arten mit Zählerständen und Verträgen; Lieferungen
  // und Einspeisung bleiben bei der Prognose im Detail.
  const wsCapable = (u) => u.reading_kind !== 'delivery' && u.has_contracts !== false && !isFeedIn(u);
  const first = utilities.find(wsCapable) || utilities[0];

  container.innerHTML = `
    <div class="section-head">
      <h1>${esc(t('scenario.title'))}</h1>
      <div class="section-actions">
        <select class="select" id="util-select" aria-label="${esc(t('forecast.selectUtility'))}">
          ${utilities.map(u => `<option value="${u.key}"${u.key === first.key ? ' selected' : ''}>${u.icon} ${esc(u.label)}</option>`).join('')}
        </select>
        <select class="select" id="meter-select" aria-label="${esc(t('forecast.selectMeter'))}"></select>
      </div>
    </div>
    <p class="ws-lead">${t('scenario.lead')} <a href="${docUrl('whatIf')}">${esc(t('scenario.guide'))}</a></p>
    <div class="ws-presets" role="group" aria-label="${esc(t('scenario.presets.label'))}" id="ws-presets"></div>
    <div class="ws" id="ws">
      <form class="card ws__controls" id="ws-form" novalidate autocomplete="off">
        <h2 class="card__title">${esc(t('scenario.controls'))}${info('scenario')}</h2>
        <!-- Telefon: das Ergebnis klebt über den Reglern (die Karte steht darunter, aria-live dort) -->
        <div class="ws-mini" id="ws-mini" aria-hidden="true"></div>
        <div class="ws-range" data-cap="heat_model">
          <div class="ws-range__head"><label for="ws-winter">${esc(t('scenario.winter.label'))}</label><output for="ws-winter" id="ws-winter-out"></output></div>
          <input type="range" id="ws-winter" min="-30" max="30" step="1" value="0">
          <p class="ws-range__hint" id="ws-winter-hint">${esc(t('scenario.winter.hint'))}</p>
        </div>
        <div class="ws-range">
          <div class="ws-range__head"><label for="ws-saving">${esc(t('scenario.saving.label'))}</label><output for="ws-saving" id="ws-saving-out"></output></div>
          <input type="range" id="ws-saving" min="0" max="50" step="1" value="0">
          <p class="ws-range__hint">${esc(t('scenario.saving.hint'))}</p>
        </div>
        <div class="field" data-cap="tariff">
          <label for="ws-tariff">${esc(t('scenario.tariff.label'))}</label>
          <select class="select" id="ws-tariff"></select>
        </div>
        <div class="fgrid" id="ws-custom" hidden>
          <div class="field field--s"><label for="ws-wp">${esc(t('scenario.tariff.workingPrice'))}</label>
            <input class="input" id="ws-wp" type="text" inputmode="decimal"></div>
          <div class="field field--s"><label for="ws-bp">${esc(t('scenario.tariff.basePrice'))}</label>
            <input class="input" id="ws-bp" type="text" inputmode="decimal"></div>
        </div>

        <div class="ws-group" data-min-level="advanced">
          <h3>${esc(t('scenario.group.advanced'))}</h3>
          <div class="ws-range" data-cap="room_temp">
            <div class="ws-range__head"><label for="ws-room">${esc(t('scenario.room.label'))}</label><output for="ws-room" id="ws-room-out"></output></div>
            <input type="range" id="ws-room" min="-3" max="3" step="0.5" value="0">
            <p class="ws-range__hint">${esc(t('scenario.room.hint'))}</p>
          </div>
          <div class="ws-range">
            <div class="ws-range__head"><label for="ws-baseload">${esc(t('scenario.baseload.label'))}</label><output for="ws-baseload" id="ws-baseload-out"></output></div>
            <input type="range" id="ws-baseload" min="-50" max="50" step="5" value="0">
            <p class="ws-range__hint">${esc(t('scenario.baseload.hint'))}</p>
          </div>
          <div class="field">
            <label for="ws-model">${esc(t('scenario.model.label'))}${info('models')}</label>
            <select class="select" id="ws-model">
              ${['standard', 'last_year', 'trend'].map(m => `<option value="${m}">${esc(t(`scenario.model.${m}`))}</option>`).join('')}
            </select>
          </div>
          <h3 class="ws-group__sub">${esc(t('scenario.measures.title'))}</h3>
          <p class="ws-note">${esc(t('scenario.measures.hint'))}</p>
          <div class="ws-measures" id="ws-measures"></div>
          <button type="button" class="btn btn--ghost btn--sm" id="ws-add-measure">${esc(t('scenario.measures.add'))}</button>
          <!-- v3.7.0 (Paket reifegrad) — E-Auto und Jahresarbeitszahl ab Erfahren (bis v3.6
               Experte): Maßnahmen für Haushalte mit Wallbox oder Wärmepumpe, kein Rechenparameter -->
          <div class="fgrid" data-cap="ev">
            <div class="field field--s"><label for="ws-ev-km">${esc(t('scenario.ev.km'))}</label>
              <input class="input" id="ws-ev-km" type="text" inputmode="numeric"></div>
            <div class="field field--s"><label for="ws-ev-kwh">${esc(t('scenario.ev.kwh'))}</label>
              <input class="input" id="ws-ev-kwh" type="text" inputmode="decimal" placeholder="${esc(t('scenario.ev.placeholder'))}"></div>
          </div>
          <div class="fgrid" data-cap="jaz">
            <div class="field field--s"><label for="ws-jaz-from">${esc(t('scenario.jaz.from'))}</label>
              <input class="input" id="ws-jaz-from" type="text" inputmode="decimal"></div>
            <div class="field field--s"><label for="ws-jaz-to">${esc(t('scenario.jaz.to'))}</label>
              <input class="input" id="ws-jaz-to" type="text" inputmode="decimal"></div>
          </div>
        </div>

        <div class="ws-group" data-min-level="expert">
          <h3>${esc(t('scenario.group.expert'))}</h3>
          <div class="field">
            <label for="ws-regression">${esc(t('scenario.regression'))}</label>
            <select class="select" id="ws-regression">
              ${REGRESSION_MODELS.map(m => `<option value="${m}">${esc(t(`forecast.models.${m}`))}</option>`).join('')}
            </select>
          </div>
          <div class="fgrid">
            <div class="field field--m"><label for="ws-budget">${esc(t('scenario.budget.label'))}</label>
              <input class="input" id="ws-budget" type="text" inputmode="decimal" placeholder="${esc(t('scenario.budget.placeholder'))}"></div>
          </div>
        </div>

        <div class="ws-group">
          <h3>${esc(t('scenario.save.title'))}</h3>
          <div class="fgrid">
            <div class="field field--l"><label for="ws-name">${esc(t('scenario.save.name'))}</label>
              <input class="input" id="ws-name" type="text" maxlength="60"></div>
            <div class="field field--s"><span class="sr-only">${esc(t('scenario.save.button'))}</span>
              <button type="button" class="btn btn--util" id="ws-save">${esc(t('scenario.save.button'))}</button></div>
          </div>
          <div class="field" id="ws-saved-wrap" hidden>
            <label for="ws-saved">${esc(t('scenario.save.list'))}</label>
            <select class="select" id="ws-saved"></select>
            <div class="ws-actions" style="margin-top: var(--sp-2)">
              <button type="button" class="btn btn--ghost btn--sm" id="ws-load">${esc(t('scenario.save.load'))}</button>
              <button type="button" class="btn btn--ghost btn--sm" id="ws-delete">${esc(t('scenario.save.delete'))}</button>
              <button type="button" class="btn btn--ghost btn--sm" id="ws-compare-btn">${esc(t('scenario.compare.button'))}</button>
            </div>
          </div>
          <p class="ws-note">${esc(t('scenario.save.local'))}</p>
        </div>
      </form>

      <div class="ws__results" aria-live="polite" aria-busy="false" id="ws-results">
        <div id="ws-banner"></div>
        <div id="ws-card"></div>
        <div class="kpi-grid ws-kpis" id="ws-kpis"></div>
        <div class="card">
          <h3 class="card__title">${esc(t('scenario.chart.cumTitle'))}</h3>
          <div class="chart-wrap short"><canvas id="ws-chart-cum"></canvas></div>
          <div data-role="ws-cum-data"></div>
        </div>
        <div class="card" data-min-level="advanced">
          <h3 class="card__title">${esc(t('scenario.chart.monthTitle'))}</h3>
          <div class="chart-wrap short"><canvas id="ws-chart-month"></canvas></div>
          <div data-role="ws-month-data"></div>
        </div>
        <div class="card" data-min-level="advanced" id="ws-ranking"></div>
        <div class="card" data-min-level="expert" id="ws-models"></div>
        <div class="card" data-min-level="expert" id="ws-opt"></div>
        <div class="card" id="ws-compare" hidden></div>
        <div class="ws-actions">
          <button type="button" class="btn btn--primary" id="ws-image">${esc(t('scenario.share.image'))}</button>
          <button type="button" class="btn btn--ghost" id="ws-print">${esc(t('scenario.share.print'))}</button>
          <button type="button" class="btn btn--ghost" id="ws-csv" data-min-level="expert">${esc(t('scenario.share.csv'))}</button>
        </div>
        <p class="ws-note" id="ws-honesty"></p>
        <div data-role="heat-limit-slot-ws"></div>
      </div>
    </div>

    ${disclosureHtml({ id: 'fc-classic', className: 'card fc-classic', storageKey: CLASSIC_KEY, summary: t('scenario.classic'), body: `
      <div class="card" style="box-shadow: none">
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
          <div class="field" data-min-level="expert">
            <label for="model">${t('forecast.model')}</label>
            <select class="select" id="model">
              ${REGRESSION_MODELS.map(m => `<option value="${m}">${t(`forecast.models.${m}`)}</option>`).join('')}
            </select>
          </div>
          <div class="field">
            <label for="months">${t('forecast.horizon')}</label>
            <input class="input" id="months" type="text" inputmode="numeric" autocomplete="off" value="12">
          </div>
          <!-- v3.1.0 (H4, MKT-26) — CO₂-Preis-Szenario (leer = aus) -->
          <div class="field" data-min-level="expert">
            <label for="co2-scenario"><span data-role="co2-label">${t('forecast.co2Scenario.label', { year: 2028 })}</span>${info('co2Price')}</label>
            <input class="input" id="co2-scenario" type="text" inputmode="decimal" autocomplete="off" placeholder="${esc(t('forecast.co2Scenario.placeholder'))}">
          </div>
        </div>
        <div class="form-actions"><button class="btn btn--util" id="btn-go">${t('forecast.update')}</button></div>
      </div>
      <div class="card" style="margin-top: var(--sp-5)">
        <h3 class="card__title">${t('forecast.history')}</h3>
        <div class="chart-wrap"><canvas id="fc-chart"></canvas></div>
        <div data-role="fc-chart-data"></div>
        <div id="fc-info" class="muted" style="margin-top: var(--sp-3)"></div>
        <div data-role="heat-limit-slot-fc"></div>
      </div>
      <div class="card" style="margin-top: var(--sp-5)">
        <h3 class="card__title">${t('forecast.monthlyDetail')}</h3>
        <div id="fc-table"></div>
      </div>` })}
  `;

  const $ = (sel) => container.querySelector(sel);
  const utilSel  = $('#util-select');
  const meterSel = $('#meter-select');
  let currentMeters = [];
  let contracts = [];

  async function loadMeters() {
    const utility = utilities.find(u => u.key === utilSel.value);
    currentMeters = await api.meters(utility.key);
    meterSel.innerHTML = currentMeters.map(m => `<option value="${esc(m.id)}">${esc(m.name)}</option>`).join('');
    contracts = await api.contracts(utility.key, meterSel.value || undefined).catch(() => []);
  }

  // ══ Werkstatt ════════════════════════════════════════════════════════
  const s = (await getSettings().catch(() => null)) || {};
  let state = S.defaultState(utilSel.value, '');
  let result = null;        // letzte Antwort
  let activePreset = null;  // Beispielszenario, das gerade gilt
  let scenarioName = '';
  let measureSeq = 0;

  const form = $('#ws-form');
  const util = () => utilities.find(u => u.key === utilSel.value);

  function contractLabel(id) {
    const c = contracts.find(x => String(x.id) === String(id));
    if (!c) return id;
    return c.shadow_label || [c.provider, c.tariff_name].filter(Boolean).join(' · ') || id;
  }

  function outputs() {
    const w = Number($('#ws-winter').value);
    $('#ws-winter-out').textContent = w === 0 ? t('scenario.winter.normal') : t(w > 0 ? 'scenario.winter.colder' : 'scenario.winter.milder', { pct: fmt.pct(Math.abs(w) / 100, 0) });
    const sv = Number($('#ws-saving').value);
    $('#ws-saving-out').textContent = sv === 0 ? t('scenario.none') : t('scenario.saving.value', { pct: fmt.pct(sv / 100, 0) });
    const r = Number($('#ws-room').value);
    $('#ws-room-out').textContent = r === 0 ? t('scenario.none') : `${r > 0 ? '+' : '−'}${fmt.dec(Math.abs(r), 1)} °C`;
    const b = Number($('#ws-baseload').value);
    $('#ws-baseload-out').textContent = b === 0 ? t('scenario.none') : pctSigned(b, 0);
  }

  /** Formular → Zustand. Ungültige Felder werden markiert und zählen als leer. */
  function readForm() {
    const dec = (id, ok = () => true) => {
      const el = $(id);
      const raw = el.value.trim();
      if (raw === '') { el.classList.remove('invalid'); return null; }
      const n = parseDecimal(raw);
      const valid = n !== null && ok(n);
      el.classList.toggle('invalid', !valid);
      return valid ? n : null;
    };
    const kind = $('#ws-tariff').value || 'current';
    let tariff = { kind };
    if (kind.startsWith('contract:')) tariff = { kind: 'contract', contract_id: kind.slice(9) };
    if (kind === 'custom') {
      tariff = { kind, working_price_ct: dec('#ws-wp', n => n >= 0 && n <= 500), base_eur_month: dec('#ws-bp', n => n >= 0 && n <= 1000) ?? 0 };
    }
    const measures = [...container.querySelectorAll('.ws-measure')].map(row => ({
      id: row.dataset.id,
      kind: row.querySelector('[data-f="kind"]').value || null,
      label: row.querySelector('[data-f="label"]').value.trim(),
      heat_pct: dec(`#${row.id} [data-f="heat"]`, n => n >= 0 && n <= 90) ?? 0,
      base_pct: dec(`#${row.id} [data-f="base"]`, n => n >= 0 && n <= 90) ?? 0,
      cost_eur: dec(`#${row.id} [data-f="cost"]`, n => n >= 0 && n <= 1e7),
      active: row.querySelector('[data-f="active"]').checked,
    }));
    const jf = dec('#ws-jaz-from', n => n >= 1 && n <= 8);
    const jt = dec('#ws-jaz-to', n => n >= 1 && n <= 8);
    const evKm = dec('#ws-ev-km', n => n >= 0 && n <= 100000) ?? 0;
    const evKwh = dec('#ws-ev-kwh', n => n >= 5 && n <= 60);
    return {
      ...state,
      utility: utilSel.value, meter_id: meterSel.value,
      model: $('#ws-model').value || 'standard',
      regression: $('#ws-regression').value !== (s.forecast_model || 'linear') ? $('#ws-regression').value : null,
      winter_pct: Number($('#ws-winter').value), saving_pct: Number($('#ws-saving').value),
      room_temp_delta: Number($('#ws-room').value), baseload_pct: Number($('#ws-baseload').value),
      ev_km: evKwh != null ? evKm : 0, ev_kwh_per_100km: evKm > 0 ? evKwh : null,
      jaz_from: jf != null && jt != null ? jf : null, jaz_to: jf != null && jt != null ? jt : null,
      tariff: tariff.kind === 'custom' && tariff.working_price_ct == null ? { kind: 'current' } : tariff,
      measures, budget_eur: dec('#ws-budget', n => n >= 0 && n <= 1e7),
    };
  }

  function measureRow(m) {
    const id = `ws-m-${++measureSeq}`;
    return `<div class="ws-measure" id="${id}" data-id="${esc(m.id)}">
      <div class="ws-measure__head">
        <label><input type="checkbox" data-f="active"${m.active !== false ? ' checked' : ''}> ${esc(t('scenario.measures.active'))}</label>
        <button type="button" class="btn btn--ghost btn--sm" data-f="remove" aria-label="${esc(t('scenario.measures.remove'))}">✕</button>
      </div>
      <div class="fgrid">
        <div class="field field--m"><label for="${id}-kind">${esc(t('scenario.measures.kind'))}</label>
          <select class="select" id="${id}-kind" data-f="kind">${S.MEASURE_KINDS.map(k => `<option value="${k}"${k === (m.kind || 'other') ? ' selected' : ''}>${esc(t(`scenario.measure.kind.${k}`))}</option>`).join('')}</select></div>
        <div class="field field--l"><label for="${id}-label">${esc(t('scenario.measures.labelField'))}</label>
          <input class="input" id="${id}-label" data-f="label" type="text" maxlength="80" value="${esc(m.label || '')}"></div>
        <div class="field field--s"><label for="${id}-heat">${esc(t('scenario.measures.heat'))}</label>
          <input class="input" id="${id}-heat" data-f="heat" type="text" inputmode="decimal" value="${m.heat_pct ? esc(formatForInput(m.heat_pct)) : ''}"></div>
        <div class="field field--s"><label for="${id}-base">${esc(t('scenario.measures.base'))}</label>
          <input class="input" id="${id}-base" data-f="base" type="text" inputmode="decimal" value="${m.base_pct ? esc(formatForInput(m.base_pct)) : ''}"></div>
        <div class="field field--s"><label for="${id}-cost">${esc(t('scenario.measures.cost'))}</label>
          <input class="input" id="${id}-cost" data-f="cost" type="text" inputmode="decimal" value="${m.cost_eur != null ? esc(formatForInput(m.cost_eur)) : ''}"></div>
      </div>
    </div>`;
  }

  /** Zustand → Formular (Beispielszenario, gespeichertes Szenario). */
  function writeForm(st) {
    $('#ws-winter').value = String(st.winter_pct ?? 0);
    $('#ws-saving').value = String(st.saving_pct ?? 0);
    $('#ws-room').value = String(st.room_temp_delta ?? 0);
    $('#ws-baseload').value = String(st.baseload_pct ?? 0);
    $('#ws-model').value = st.model || 'standard';
    $('#ws-regression').value = st.regression || s.forecast_model || 'linear';
    const tk = st.tariff?.kind === 'contract' ? `contract:${st.tariff.contract_id}` : (st.tariff?.kind || 'current');
    if ([...$('#ws-tariff').options].some(o => o.value === tk)) $('#ws-tariff').value = tk;
    else $('#ws-tariff').value = 'current';
    $('#ws-wp').value = st.tariff?.kind === 'custom' && st.tariff.working_price_ct != null ? formatForInput(st.tariff.working_price_ct) : '';
    $('#ws-bp').value = st.tariff?.kind === 'custom' && st.tariff.base_eur_month ? formatForInput(st.tariff.base_eur_month) : '';
    $('#ws-custom').hidden = $('#ws-tariff').value !== 'custom';
    $('#ws-ev-km').value = st.ev_km ? formatForInput(st.ev_km) : '';
    $('#ws-ev-kwh').value = st.ev_kwh_per_100km != null ? formatForInput(st.ev_kwh_per_100km) : '';
    $('#ws-jaz-from').value = st.jaz_from != null ? formatForInput(st.jaz_from) : '';
    $('#ws-jaz-to').value = st.jaz_to != null ? formatForInput(st.jaz_to) : '';
    $('#ws-budget').value = st.budget_eur != null ? formatForInput(st.budget_eur) : '';
    $('#ws-measures').innerHTML = (st.measures || []).map(measureRow).join('');
    $('#ws-add-measure').disabled = (st.measures || []).length >= S.MAX_MEASURES;
    outputs();
  }

  function fillTariffs() {
    const offers = result?.offers || [];
    const cur = $('#ws-tariff').value;
    const opts = [['current', t('scenario.tariff.current')]];
    if (offers.some(o => o.computable)) opts.push(['cheapest', t('scenario.tariff.cheapest')]);
    for (const o of offers.filter(x => x.computable)) opts.push([`contract:${o.contract_id}`, t('scenario.tariff.offer', { label: contractLabel(o.contract_id) })]);
    opts.push(['custom', t('scenario.tariff.custom')]);
    $('#ws-tariff').innerHTML = opts.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('');
    $('#ws-tariff').value = opts.some(([v]) => v === cur) ? cur : 'current';
    $('#ws-custom').hidden = $('#ws-tariff').value !== 'custom';
  }

  function renderPresets() {
    const ids = result ? S.presetsFor(result) : [];
    $('#ws-presets').innerHTML = `<span class="muted">${esc(t('scenario.presets.try'))}</span>`
      + ids.map(id => `<button type="button" class="ws-chip" data-preset="${id}" aria-pressed="${activePreset === id}">${esc(t(`scenario.preset.${id}.label`))}</button>`).join('')
      + `<button type="button" class="ws-chip" data-preset="" aria-pressed="false">${esc(t('scenario.presets.reset'))}</button>`;
  }

  function applyCaps(caps) {
    for (const el of container.querySelectorAll('[data-cap]')) {
      const on = !!caps?.[el.dataset.cap];
      el.hidden = !on;
    }
    for (const opt of $('#ws-model').options) opt.disabled = caps?.models ? caps.models[opt.value] === false : false;
  }

  // ── Rechnen (entprellt; nur der zuletzt gestartete Lauf zeichnet) ──────
  let wsSeq = 0;
  let timer = null;
  const schedule = (delay = 280) => { clearTimeout(timer); timer = setTimeout(() => compute(), delay); };

  async function compute(st = readForm()) {
    const my = ++wsSeq;
    state = st;
    S.rememberCurrent(state, scenarioTitle());
    if (!state.meter_id) return;
    const res = $('#ws-results');
    res.setAttribute('aria-busy', 'true');
    try {
      const r = await api.scenario(S.scenarioBody(state));
      if (my !== wsSeq) return;
      result = r;
      $('#ws-banner').innerHTML = '';
      renderWorkshop();
    } catch (e) {
      if (my !== wsSeq) return;
      if (e.code === 'demo.scenarioPresetsOnly') {
        $('#ws-banner').innerHTML = `<div class="banner banner--info">${esc(t('scenario.demoNote'))}</div>`;
      } else {
        $('#ws-banner').innerHTML = `<div class="banner banner--warning">${esc(e.message)}</div>`;
      }
    } finally {
      if (my === wsSeq) res.setAttribute('aria-busy', 'false');
    }
  }

  function scenarioTitle() {
    if (scenarioName) return scenarioName;
    if (activePreset) return t(`scenario.preset.${activePreset}.label`);
    return t('scenario.card.yours');
  }

  function cardSpec() {
    const d = result.totals.delta;
    const tone = Math.abs(d.cost) < 1 ? 'same' : d.cost > 0 ? 'more' : 'less';
    const name = scenarioTitle();
    const amount = fmt.eur0(Math.abs(d.cost));
    const facts = [
      { label: t('scenario.kpi.qty'), value: d.qty_pct != null ? pctSigned(d.qty_pct) : '–' },
    ];
    if (d.co2_kg != null) facts.push({ label: t('scenario.kpi.co2'), value: `${signed(d.co2_kg, fmt.int)} kg` });
    if (result.advance?.scen_recommended != null) facts.push({ label: t('scenario.kpi.advance'), value: fmt.eur0(result.advance.scen_recommended) });
    return {
      brand: t('scenario.card.brand'), title: name, tone, facts,
      big: tone === 'same' ? '±0' : `${d.cost > 0 ? '+' : '−'}${amount}`,
      // Beispielszenario mit erwarteter Richtung: der eigene Satz („Ein kalter Winter kostet dich …“)
      sentence: activePreset && tone === (activePreset === 'cold_winter' ? 'more' : 'less') && !scenarioName
        ? t(`scenario.preset.${activePreset}.sentence`, { amount })
        : tone === 'same' ? t('scenario.card.same', { name })
        : t(tone === 'more' ? 'scenario.card.more' : 'scenario.card.less', { name, amount }),
      footer: t('scenario.card.footer', { from: fmt.month(result.from), to: fmt.month(result.to), date: fmt.date(new Date().toISOString().slice(0, 10)) }),
    };
  }

  function renderWorkshop() {
    const u = util();
    if (!result.supported || !result.valid) {
      $('#ws-card').innerHTML = '';
      $('#ws-kpis').innerHTML = '';
      $('#ws-banner').innerHTML = `<div class="banner banner--info">${esc(t(!result.supported ? 'scenario.unsupported' : 'scenario.tooFew', { label: u?.label || '' }))}</div>`;
      for (const id of ['#ws-ranking', '#ws-models', '#ws-opt', '#ws-honesty']) $(id).innerHTML = '';
      applyCaps(null);
      renderPresets();
      return;
    }
    applyCaps(result.capabilities);
    fillTariffs();
    renderPresets();
    const unit = result.unit;
    const tot = result.totals;
    const spec = cardSpec();
    $('#ws-card').innerHTML = resultCardHtml(spec);
    $('#ws-mini').innerHTML = `<strong class="ws-mini__big ws-mini--${spec.tone}">${esc(spec.big)}</strong><span>${esc(spec.title)}</span>`;

    // Kennzahlen: drei für alle, zwei mehr ab Erfahren
    const kpi = (label, value, sub = '', cls = '', min = '') => `<div class="kpi"${min ? ` data-min-level="${min}"` : ''}>
        <div class="kpi__label">${esc(label)}</div><div class="kpi__value">${value}</div>
        ${sub ? `<div class="kpi__sub ${cls}">${sub}</div>` : ''}</div>`;
    const dc = tot.delta.cost;
    const billing = result.billing;
    const balScen = billing ? balanceView(billing.scen_end_balance, u) : null;
    $('#ws-kpis').innerHTML = [
      kpi(t('scenario.kpi.cost'), esc(fmt.eur(tot.scenario.cost)),
        esc(t('scenario.kpi.vsBase', { delta: signed(dc, fmt.eur), base: fmt.eur(tot.base.cost) })), dc > 0.5 ? 'negative' : dc < -0.5 ? 'positive' : ''),
      kpi(t('scenario.kpi.qtyYear'), esc(fmt.unit(tot.scenario.qty, unit)),
        esc(tot.delta.qty_pct != null ? pctSigned(tot.delta.qty_pct) : ''), tot.delta.qty > 0 ? 'negative' : tot.delta.qty < 0 ? 'positive' : ''),
      // v3.7.0 (wirklichkeit-2) — Gütesperre: Spanne statt Betrag
      // v3.9.0 (L12) — kurz vor der Abrechnung kein Nachholbetrag, sondern der laufende Bedarf danach
      kpi(t('scenario.kpi.advance'), esc(result.advance.uncertain?.scen_range
          ? t('scenario.kpi.advanceRange', { low: fmt.eur0(result.advance.uncertain.scen_range[0]), high: fmt.eur0(result.advance.uncertain.scen_range[1]) })
          : result.advance.withheld === 'short' && result.advance.scen_ongoing != null ? fmt.eur(result.advance.scen_ongoing)
          : fmt.eur(result.advance.scen_recommended)),
        esc([result.advance.current != null ? t('scenario.kpi.advanceNow', { amount: fmt.eur(result.advance.current) }) : '',
          result.advance.uncertain ? t('scenario.kpi.advanceUncertain') : '',
          result.advance.withheld === 'short' && !result.advance.uncertain ? t('scenario.kpi.advanceShort', { date: fmt.date(billing?.settle_on || billing?.until) }) : ''].filter(Boolean).join(' · '))),
      // v3.9.0 (L1) — derselbe Abrechnungstag wie die Saldo-Karte
      ...(balScen ? [kpi(t('scenario.kpi.balance', { date: fmt.date(billing.settle_on || billing.until) }), `<span class="${balScen.cls}">${esc(balScen.word)} ${esc(fmt.eur(balScen.amount))}</span>`,
        esc(t('scenario.kpi.balanceBase', { amount: balanceView(billing.base_end_balance, u)?.signed ?? '–' })), '', 'advanced')] : []),
      ...(tot.delta.co2_kg != null ? [kpi(t('scenario.kpi.co2Year'), esc(`${fmt.int(tot.scenario.co2_kg)} kg`),
        esc(`${signed(tot.delta.co2_kg, fmt.int)} kg`), tot.delta.co2_kg > 0 ? 'negative' : tot.delta.co2_kg < 0 ? 'positive' : '', 'advanced')] : []),
    ].join('');

    renderCharts(u);
    renderRanking();
    renderModels(u);
    renderOptimization();
    renderHonesty();
  }

  function renderCharts(u) {
    const months = result.months;
    const labels = months.map(m => fmt.month(m.ym));
    const anim = reducedMotion() ? { animation: false } : {};
    makeChart($('#ws-chart-cum'), {
      type: 'line',
      data: { labels, datasets: [
        { label: t('scenario.chart.base'), data: months.map(m => m.base_cum_cost), borderColor: tokenColor('text2'), borderDash: [6, 4], pointRadius: 0, tension: 0.2 },
        { label: t('scenario.chart.scenario'), data: months.map(m => m.scen_cum_cost), borderColor: utilColor(u), backgroundColor: utilColor(u, 0.12), fill: false, pointRadius: 2, tension: 0.2 },
      ] },
      options: { responsive: true, maintainAspectRatio: false, ...anim, scales: { y: { title: { display: true, text: t('scenario.chart.eur') } } } },
    }, { label: t('scenario.chart.cumAlt', { base: fmt.eur(result.totals.base.cost), scen: fmt.eur(result.totals.scenario.cost) }) });
    const cum = container.querySelector('[data-role="ws-cum-data"]');
    cum.innerHTML = chartTableHtml({
      caption: t('scenario.chart.cumTitle'),
      columns: [t('forecast.col.month'), t('scenario.chart.base'), t('scenario.chart.scenario')],
      rows: months.map(m => [fmt.month(m.ym), fmt.eur(m.base_cum_cost), fmt.eur(m.scen_cum_cost)]),
    });

    const band = months.some(m => m.scen_band_low != null);
    makeChart($('#ws-chart-month'), {
      type: 'line',
      data: { labels, datasets: [
        ...(band ? [
          { label: '', data: months.map(m => m.scen_band_low), borderColor: 'transparent', pointRadius: 0, fill: false, tension: 0.25 },
          { label: t('forecast.chartBand', { level: result.band?.level_pct ?? 80 }), data: months.map(m => m.scen_band_high), borderColor: 'transparent', backgroundColor: utilColor(u, 0.15), pointRadius: 0, fill: '-1', tension: 0.25 },
        ] : []),
        { label: t('scenario.chart.base'), data: months.map(m => m.base_qty), borderColor: tokenColor('text2'), borderDash: [6, 4], pointRadius: 0, tension: 0.25 },
        { label: t('scenario.chart.scenario'), data: months.map(m => m.scen_qty), borderColor: utilColor(u), pointRadius: 2, tension: 0.25 },
      ] },
      options: { responsive: true, maintainAspectRatio: false, ...anim,
        plugins: { legend: { labels: { filter: (item) => item.text !== '' } } },
        scales: { y: { title: { display: true, text: result.unit } } } },
    }, { label: t('scenario.chart.monthAlt', { unit: result.unit }) });
    container.querySelector('[data-role="ws-month-data"]').innerHTML = chartTableHtml({
      caption: t('scenario.chart.monthTitle'),
      columns: [t('forecast.col.month'), `${t('scenario.chart.base')} (${result.unit})`, `${t('scenario.chart.scenario')} (${result.unit})`, t('scenario.chart.costBase'), t('scenario.chart.costScen')],
      rows: months.map(m => [fmt.month(m.ym), fmt.int(m.base_qty), fmt.int(m.scen_qty), fmt.eur(m.base_cost), fmt.eur(m.scen_cost)]),
    });
  }

  function leverLabel(x) {
    if (x.kind === 'measure') {
      const m = (state.measures || []).find(mm => mm.id === x.id);
      return m?.label || t(`scenario.measure.kind.${m?.kind || 'other'}`);
    }
    if (x.kind === 'tariff') return x.tariff_kind === 'custom' ? t('scenario.tariff.custom')
      : t('scenario.tariff.offer', { label: contractLabel(result.tariff.contract_id) });
    return t(`scenario.lever.${x.kind}`);
  }

  function renderRanking() {
    const list = result.ranking || [];
    $('#ws-ranking').innerHTML = `<h3 class="card__title">${esc(t('scenario.ranking.title'))}</h3>
      <p class="ws-note">${esc(t('scenario.ranking.hint'))}</p>
      ${list.length ? `<div class="table-wrap"><table class="table table--compact">
        <thead><tr><th scope="col">${esc(t('scenario.ranking.lever'))}</th><th scope="col" class="num">${esc(t('scenario.ranking.saving'))}</th>
          <th scope="col" class="num">${esc(t('scenario.ranking.qty', { unit: result.unit }))}</th><th scope="col" class="num">${esc(t('scenario.ranking.cost'))}</th>
          <th scope="col" class="num">${esc(t('scenario.ranking.payback'))}</th></tr></thead>
        <tbody>${list.map(x => `<tr><th scope="row">${esc(leverLabel(x))}</th>
          <td class="num ${x.saving_eur > 0.5 ? 'success-text' : x.saving_eur < -0.5 ? 'danger-text' : ''}">${esc(signed(x.saving_eur, fmt.eur))}</td>
          <td class="num">${esc(signed(x.saving_qty, fmt.int))}</td>
          <td class="num">${x.cost_eur != null ? esc(fmt.eur(x.cost_eur)) : '<span class="dim">–</span>'}</td>
          <td class="num">${x.payback_years != null ? esc(t('scenario.ranking.years', { n: fmt.num(x.payback_years, 1) })) : '<span class="dim">–</span>'}</td></tr>`).join('')}</tbody>
      </table></div>` : `<p class="muted">${esc(t('scenario.ranking.empty'))}</p>`}`;
  }

  function renderModels(u) {
    const m = result.models || {};
    const rows = ['standard', 'last_year', 'trend'].map(k => m[k] ? `<tr${k === result.model ? ' class="is-current"' : ''}>
        <th scope="row">${esc(t(`scenario.model.${k}`))}${k === 'trend' && m.trend.growth_pct != null ? ` <span class="muted">(${esc(pctSigned(m.trend.growth_pct))})</span>` : ''}</th>
        <td class="num">${esc(fmt.unit(m[k].base_qty, result.unit))}</td><td class="num">${esc(fmt.eur(m[k].base_cost))}</td>
        <td class="num">${esc(fmt.unit(m[k].scen_qty, result.unit))}</td><td class="num">${esc(fmt.eur(m[k].scen_cost))}</td></tr>`
      : `<tr><th scope="row">${esc(t(`scenario.model.${k}`))}</th><td colspan="4" class="muted">${esc(t('scenario.model.unavailable'))}</td></tr>`).join('');
    const band = result.band;
    $('#ws-models').innerHTML = `<h3 class="card__title">${esc(t('scenario.models.title'))}</h3>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th scope="col">${esc(t('scenario.model.label'))}</th><th scope="col" class="num">${esc(t('scenario.models.baseQty'))}</th>
          <th scope="col" class="num">${esc(t('scenario.models.baseCost'))}</th><th scope="col" class="num">${esc(t('scenario.models.scenQty'))}</th>
          <th scope="col" class="num">${esc(t('scenario.models.scenCost'))}</th></tr></thead>
        <tbody>${rows}</tbody></table></div>
      ${band ? `<p class="ws-note">${esc(t('scenario.models.band', { level: band.level_pct, low: fmt.int(band.scen_low), high: fmt.int(band.scen_high), unit: result.unit }))}</p>` : ''}`;
  }

  function renderOptimization() {
    const o = result.optimization;
    const box = $('#ws-opt');
    if (!o) {
      box.innerHTML = `<h3 class="card__title">${esc(t('scenario.opt.title'))}</h3><p class="ws-note">${esc(t('scenario.opt.hint'))}</p>`;
      return;
    }
    const names = o.chosen.map(id => leverLabel({ kind: 'measure', id }));
    box.innerHTML = `<h3 class="card__title">${esc(t('scenario.opt.title'))}</h3>
      ${o.candidates === 0 ? `<p class="muted">${esc(t('scenario.opt.noCandidates'))}</p>`
        : o.chosen.length === 0 ? `<p>${esc(t('scenario.opt.none', { budget: fmt.eur(o.budget_eur) }))}</p>`
        : `<p>${esc(t('scenario.opt.result', { list: names.join(', '), budget: fmt.eur(o.budget_eur), cost: fmt.eur(o.cost_eur), saving: fmt.eur(o.saving_eur) }))}
            ${o.payback_years != null ? esc(t('scenario.opt.payback', { n: fmt.num(o.payback_years, 1) })) : ''}</p>
           <button type="button" class="btn btn--util btn--sm" id="ws-opt-apply">${esc(t('scenario.opt.apply'))}</button>`}
      ${o.lp ? `<p class="ws-note">${esc(t('scenario.opt.lp', { bound: fmt.eur(o.lp.bound_eur), value: fmt.num(o.lp.budget_value_per_eur * 100, 1), n: o.evaluated }))}</p>` : ''}`;
  }

  function renderHonesty() {
    const b = result.basis || {};
    const parts = [t('scenario.honesty.model', { month: b.last_measured ? fmt.month(b.last_measured) : '–' })];
    if (b.heat_share_year != null) parts.push(t('scenario.honesty.heat', { pct: fmt.pct(b.heat_share_year, 0) }));
    if (activePreset) parts.push(t(`scenario.preset.${activePreset}.note`, { pct: fmt.pct(state.winter_pct / 100, 0) }));
    for (const w of result.warnings || []) parts.push(t(`scenario.warn.${w.code}`, { n: w.months ?? 0, pct: fmt.pct(w.ratio ?? 0, 0) }));
    $('#ws-honesty').textContent = parts.join(' ');
    heatLimitHint('ws', (result.warnings || []).some(w => w.code === 'poor_fit'), 'workshop', result.unit);
  }

  // v3.9.0 (#38) — schlecht passendes Heizmodell: der Heizgrenze-Vorschlag mit Knopf (Werkstatt und Prognose)
  function heatLimitHint(where, poor, context, unit) {
    const slot = container.querySelector(`[data-role="heat-limit-slot-${where}"]`);
    if (!slot) return;
    slot.innerHTML = '';
    if (!poor || !meterSel.value) return;
    fillHeatLimitSlot(slot, { utility: utilSel.value, id: meterSel.value, context, unit,
      onChange: async () => { await Promise.all([compute(), run()]); } });
  }

  // ── Ereignisse ────────────────────────────────────────────────────────
  form.addEventListener('input', (e) => {
    if (e.target.type === 'range') outputs();
    activePreset = null;
    scenarioName = '';
    schedule(e.target.type === 'range' ? 180 : 450);
  });
  form.addEventListener('change', (e) => {
    if (e.target.id === 'ws-tariff') $('#ws-custom').hidden = e.target.value !== 'custom';
    if (e.target.matches('select, input[type="checkbox"]')) { activePreset = null; schedule(0); }
  });
  form.addEventListener('submit', (e) => { e.preventDefault(); schedule(0); });
  form.addEventListener('click', (e) => {
    const rm = e.target.closest('[data-f="remove"]');
    if (rm) { rm.closest('.ws-measure')?.remove(); $('#ws-add-measure').disabled = false; schedule(0); }
  });
  $('#ws-add-measure').addEventListener('click', () => {
    const n = container.querySelectorAll('.ws-measure').length;
    if (n >= S.MAX_MEASURES) return;
    let id = `m${n + 1}`;
    while (container.querySelector(`.ws-measure[data-id="${id}"]`)) id += 'x';
    $('#ws-measures').insertAdjacentHTML('beforeend', measureRow({ id, kind: 'other', heat_pct: 0, base_pct: 0, cost_eur: null, active: true }));
    $('#ws-add-measure').disabled = n + 1 >= S.MAX_MEASURES;
    container.querySelector('.ws-measure:last-child select')?.focus();
  });
  $('#ws-presets').addEventListener('click', (e) => {
    const b = e.target.closest('[data-preset]');
    if (!b) return;
    const id = b.dataset.preset || null;
    const next = id ? S.presetState(id, state, result) : S.defaultState(state.utility, state.meter_id);
    activePreset = id;
    scenarioName = '';
    writeForm(next);
    compute(next);
  });
  $('#ws-image').addEventListener('click', async () => {
    if (!result?.valid) return;
    const ok = await downloadResultCard(cardSpec(), `${t('scenario.share.filename')}-${state.utility}.png`);
    if (!ok) toastErr(t('scenario.share.imageFailed'));
  });
  $('#ws-print').addEventListener('click', () => {
    S.rememberCurrent(state, scenarioTitle());
    location.hash = '#/forecast/print';
  });
  $('#ws-csv').addEventListener('click', () => {
    if (!result?.valid) return;
    const head = ['month', 'base_qty', 'scen_qty', 'base_cost', 'scen_cost', 'base_cum_cost', 'scen_cum_cost', 'advance'].map(k => t(`scenario.csv.${k}`, { unit: result.unit }));
    const blob = new Blob([S.scenarioCsv(result, head)], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = `${t('scenario.share.filename')}-${state.utility}-${result.from}.csv`;
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
  });

  // Speichern und Vergleichen (je Gerät)
  function fillSaved() {
    const list = S.savedScenarios();
    $('#ws-saved-wrap').hidden = list.length === 0;
    $('#ws-saved').innerHTML = list.map(x => `<option value="${esc(x.name)}">${esc(x.name)}</option>`).join('');
  }
  $('#ws-save').addEventListener('click', () => {
    const name = $('#ws-name').value.trim() || scenarioTitle();
    if (S.saveScenario(name, readForm())) { scenarioName = name; fillSaved(); toastOk(t('scenario.save.done', { name })); }
    else toastErr(t('scenario.save.failed'));
  });
  $('#ws-load').addEventListener('click', async () => {
    const x = S.savedScenarios().find(y => y.name === $('#ws-saved').value);
    if (!x) return;
    scenarioName = x.name;
    activePreset = null;
    $('#ws-name').value = x.name;
    if (x.state.utility !== utilSel.value && utilities.some(u => u.key === x.state.utility)) {
      utilSel.value = x.state.utility;
      await loadMeters();
    }
    if (currentMeters.some(m => m.id === x.state.meter_id)) meterSel.value = x.state.meter_id;
    writeForm(x.state);
    compute();
  });
  $('#ws-delete').addEventListener('click', () => { S.deleteScenario($('#ws-saved').value); fillSaved(); });
  $('#ws-compare-btn').addEventListener('click', async () => {
    const list = S.savedScenarios();
    const box = $('#ws-compare');
    box.hidden = false;
    box.innerHTML = `<h3 class="card__title">${esc(t('scenario.compare.title'))}</h3><div class="loading">${esc(t('common.loading'))}</div>`;
    const rows = [];
    for (const x of list) {
      try {
        const r = await api.scenario(S.scenarioBody(x.state));
        rows.push(r.valid ? `<tr><th scope="row">${esc(x.name)}</th><td>${esc(utilities.find(u => u.key === x.state.utility)?.label || x.state.utility)}</td>
          <td class="num">${esc(fmt.eur(r.totals.scenario.cost))}</td><td class="num">${esc(signed(r.totals.delta.cost, fmt.eur))}</td>
          <td class="num">${esc(fmt.unit(r.totals.scenario.qty, r.unit))}</td><td class="num">${r.totals.delta.co2_kg != null ? esc(`${signed(r.totals.delta.co2_kg, fmt.int)} kg`) : '–'}</td></tr>`
          : `<tr><th scope="row">${esc(x.name)}</th><td colspan="5" class="muted">${esc(t('scenario.tooFew', { label: '' }))}</td></tr>`);
      } catch (e) {
        rows.push(`<tr><th scope="row">${esc(x.name)}</th><td colspan="5" class="muted">${esc(e.code === 'demo.scenarioPresetsOnly' ? t('scenario.demoShort') : e.message)}</td></tr>`);
      }
    }
    box.innerHTML = `<h3 class="card__title">${esc(t('scenario.compare.title'))}</h3>
      <div class="table-wrap"><table class="table table--compact"><thead><tr>
        <th scope="col">${esc(t('scenario.compare.name'))}</th><th scope="col">${esc(t('scenario.compare.utility'))}</th>
        <th scope="col" class="num">${esc(t('scenario.kpi.cost'))}</th><th scope="col" class="num">${esc(t('scenario.compare.delta'))}</th>
        <th scope="col" class="num">${esc(t('scenario.kpi.qtyYear'))}</th><th scope="col" class="num">${esc(t('scenario.kpi.co2'))}</th>
      </tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
  });
  container.addEventListener('click', (e) => {
    if (e.target.id !== 'ws-opt-apply' || !result?.optimization) return;
    const chosen = new Set(result.optimization.chosen);
    for (const row of container.querySelectorAll('.ws-measure')) row.querySelector('[data-f="active"]').checked = chosen.has(row.dataset.id);
    activePreset = null;
    compute();
  });
  wireDisclosures(container);   // merkt sich „Prognose im Detail“ offen/zu

  // ══ Prognose im Detail (bisherige Ansicht) ═════════════════════════
  // v2.15.0 (Review FE-16) — nur der zuletzt gestartete Lauf zeichnet. Zwei
  // schnelle Klicks legten zwei Charts auf dasselbe Canvas; der zweite warf
  // „Canvas is already in use“ roh und englisch in einen Toast.
  let runSeq = 0;
  async function run() {
    const my = ++runSeq;
    const utility = util();
    if (!currentMeters.length) {
      $('#fc-info').textContent = t('forecast.noMeters');
      $('#fc-table').innerHTML = '';
      return;
    }
    // v2.5.3 (FE-02) — Leer heißt „keine Änderung" (Versatz 0, Faktor 1,
    // 12 Monate), nicht 0: Ein leerer Preisfaktor rechnete die Kosten auf 0 €.
    // Der Temperaturversatz hat bewusst kein inputmode="decimal" — die
    // iOS-Zahlentastatur kennt kein Minus.
    const whatIf = (id, fallback, ok) => {
      const el = $(`#${id}`);
      const raw = el.value.trim();
      const n = raw === '' ? fallback : parseDecimal(raw);
      const valid = n !== null && ok(n);
      el.classList.toggle('invalid', !valid);
      return valid ? n : null;
    };
    const tempOffset  = whatIf('temp-offset', 0, n => Math.abs(n) <= 20);
    const priceFactor = whatIf('price-factor', 1, n => n >= 0 && n <= 10);
    const months      = whatIf('months', 12, n => Number.isInteger(n) && n >= 1 && n <= 24);
    const co2Raw      = $('#co2-scenario').value.trim();
    const co2         = co2Raw === '' ? '' : whatIf('co2-scenario', 0, n => n >= 0 && n <= 1000);
    if (tempOffset === null || priceFactor === null || months === null || co2 === null) {
      toastErr(t('forecast.whatIfInvalid'));
      return;
    }
    const opts = {
      temp_offset:  tempOffset,
      price_factor: priceFactor,
      model:        $('#model').value,
      forecast_months: months,
      co2_scenario_eur_t: co2,
    };
    try {
      const res = await api.forecast(utility.key, meterSel.value, opts);
      if (my !== runSeq) return;   // überholt
      renderResult(utility, res, container);
      heatLimitHint('fc', (res.warnings || []).some(w => w.code === 'poor_fit'), 'forecast', utility.consumption_unit);   // v3.9.0 (#38)
    } catch (e) { if (my === runSeq) toastErr(e.message); }
  }

  async function meterChanged() {
    contracts = await api.contracts(utilSel.value, meterSel.value || undefined).catch(() => []);
    state = { ...readForm(), tariff: { kind: 'current' } };
    $('#ws-tariff').value = 'current';
    activePreset = null;
    await Promise.all([compute({ ...state, utility: utilSel.value, meter_id: meterSel.value }), run()]);
  }
  utilSel.addEventListener('change', async () => { await loadMeters(); meterChanged(); });
  meterSel.addEventListener('change', meterChanged);
  $('#btn-go').addEventListener('click', run);

  // v2.13.0 (Review FE-10) — Modell und Horizont aus den Einstellungen
  // vorbelegen. Bis v2.12 stand hier fest „linear, 12", während der
  // Tarifvergleich das eingestellte Modell nutzte: zwei Ansichten, zwei
  // verschiedene Prognosen.
  const modelSel = $('#model');
  if (modelSel && [...modelSel.options].some(o => o.value === s.forecast_model)) modelSel.value = s.forecast_model;
  if ([...$('#ws-regression').options].some(o => o.value === s.forecast_model)) $('#ws-regression').value = s.forecast_model;
  if (s.co2_price_scenario_eur_t != null) $('#co2-scenario').value = formatForInput(s.co2_price_scenario_eur_t);
  // v3.1.0 — das Startjahr des Szenarios aus den Einstellungen (vorher fest „ab 2028")
  const co2Label = container.querySelector('[data-role="co2-label"]');
  if (co2Label && s.co2_price_scenario_from) co2Label.textContent = t('forecast.co2Scenario.label', { year: s.co2_price_scenario_from });
  const fm = Number(s.forecast_months);
  if (Number.isInteger(fm) && fm >= 1 && fm <= 24) $('#months').value = String(fm);

  await loadMeters();
  fillTariffs();
  fillSaved();
  outputs();
  state = S.defaultState(utilSel.value, meterSel.value);
  await Promise.all([compute(state), run()]);
  // Einstieg mit Beispielszenario (#/forecast?preset=cold_winter, Übersicht)
  const want = ctx?.query?.get?.('preset');
  if (want && result?.valid && S.presetsFor(result).includes(want)) {
    activePreset = want;
    const next = S.presetState(want, state, result);
    writeForm(next);
    await compute(next);
  }

  return () => { clearTimeout(timer); if (chart) { chart.destroy(); chart = null; } };
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
  // v3.9.0 (L4) — der wirksame Anteil der Heizkurve (Server `blend_effective`); rechnet kein
  // Monat mit ihr (Gütesperre, Sommer), kein R² daneben, sondern der Grund
  const blendEff = result.blend_effective ?? result.blend_weight;
  const poorFit = result.fit_quality?.model_quality === 'poor';
  const lines = [t('forecast.info', {
    model: escapeHtml(modelName === `forecast.models.${modelKey}` ? modelKey : modelName),
    blend: fmt.num(blendEff * 100, 1),
    r2: reg && blendEff > 0 ? ` (${t('forecast.r2Label')} ${fmt.num(reg.r2, 3)})` : (reg && poorFit ? ` (${t('forecast.blendPoorFit')})` : ''),
    price: fmt.num(result.last_price_ct, 3),
    unit: u.consumption_unit,
  })];
  // v2.8.0 — Jahresband und Herkunft der Heizgradtage
  // v3.7.0 (wirklichkeit-2) — Gütesperre: das Jahr ohne Band
  // v3.7.0 (Schritt 3, N1/N3) — auch zu wenig gemessen (Heizmodell) bzw. keine Streuung über die Jahre
  if (result.annual && result.annual.low == null) {
    const noBand = { few_measured: 'forecast.annualNoBandFew', few_years: 'forecast.annualNoBandYears' }[result.annual.withheld] || 'forecast.annualNoBand';
    lines.push(t(noBand, { value: fmt.num(result.annual.value, 0), unit: u.consumption_unit }));
  } else if (result.annual) {
    lines.push(t(u.hgt_relevant ? 'forecast.annualBand' : 'forecast.annualBandSpread', {
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
  // v3.1.0 (H4, MKT-26) — was ein höherer CO₂-Preis kosten würde
  if (result.co2_scenario?.delta_ct_per_kwh != null) {
    const c = result.co2_scenario;
    lines.push(escapeHtml(t('forecast.co2Scenario.delta', {
      price: fmt.num(c.eur_t, 0), from: c.from, ct: fmt.num(c.delta_ct_per_kwh, 2), amount: fmt.eur(c.delta_cost_12m_eur),
    })));
  }
  const warn = (result.warnings || []).map(w => {
    if (w.code === 'history_short') {
      const names = (w.missing || []).map(m => fmt.month(`2024-${String(m).padStart(2, '0')}`).replace(/\s*2024$/, '')).join(', ');
      return t('forecast.warn.historyShort', { months: w.months, missing: names || '–' });
    }
    // v3.9.0 (auswertung) — ohne zwölf gemessene Kalendermonate: die Jahresmenge der letzten 365 Tage
    if (w.code === 'level_last_year') {
      return t('forecast.warn.levelLastYear', { amount: fmt.unit(w.value, u.consumption_unit), from: fmt.month(w.from), to: fmt.month(w.to) });
    }
    if (w.code === 'no_climate_normal') return t('forecast.warn.noClimateNormal');
    // v3.7.0 (wirklichkeit-2) — Normal vorhanden, aber nicht für diese Heizgrenze
    if (w.code === 'climate_normal_grid') return t('forecast.warn.climateNormalGrid', { base: fmt.num(w.base, 1), min: fmt.num(w.min, 0), max: fmt.num(w.max, 0) });
    // v3.7.0 (#37) — verteilte Monate prägen weder Saisonprofil noch Heizkurve
    if (w.code === 'interpolated') {
      return tp('forecast.warn.interpolated', w.months, { count: w.months, readings: w.readings ?? '–' })
        + (w.heat_basis === 'intervals' ? ' ' + t('forecast.warn.interpolatedIntervals') : '');
    }
    // v3.7.0 (wirklichkeit-2) — Gütesperre und eine Heizkurve, die nicht passt
    if (w.code === 'poor_fit') return t('forecast.warn.poorFit', { pct: fmt.pct(w.ratio ?? 0, 0) });
    if (w.code === 'few_measured') return t('forecast.warn.fewMeasured');   // v3.7.0 (Schritt 3, N3)
    if (w.code === 'regression_invalid') {
      return t('forecast.warn.regressionInvalid', { model: t(`forecast.models.${w.model}`), why: t(`regression.reason.${w.reason}`, { n: w.n ?? 0, r2: fmt.num(w.r2 ?? 0, 2) }) });
    }
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
  const known = ['seasonal_only', 'regression_only', 'heat_model', 'filled', 'seasonal_interpolated'];   // v3.7.0 (#37)
  if (known.includes(method)) return escapeHtml(t('forecast.method.' + method));
  const blend = /^blend\(reg=([\d.]+), seasonal=([\d.]+)\)$/.exec(method || '');
  if (blend) return escapeHtml(t('forecast.method.blend', { reg: fmt.pct(Number(blend[1]), 0), seasonal: fmt.pct(Number(blend[2]), 0) }));
  return `<code class="mono" style="font-size: var(--fs-xs)">${escapeHtml(method)}</code>`;
}
