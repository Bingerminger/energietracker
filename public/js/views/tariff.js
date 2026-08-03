// =====================================================================
// Energietracker v2.2.0 — Tarifvergleich (echt vs. Schattenverträge)
//
// Neu gegenüber v1.3.0:
//   - Jede Kennzahl bezieht sich auf die Monate, die der Vertrag wirklich
//     abdeckt (vorher: Gesamtverbrauch neben Teilzeitraum-Kosten — ein
//     Halbjahrestarif wirkte dadurch doppelt so günstig, wie er ist).
//   - Vollkosten je Einheit (ct/kWh bzw. ct/m³) als zeitraumunabhängiger
//     Vergleichsmaßstab — die Spalte, auf die es fachlich ankommt.
//   - Hochrechnung auf die volle Periode bei Teilabdeckung.
//   - Balkendiagramm der Vergleichskosten.
//   - Schattenverträge lassen sich hier anlegen, bearbeiten UND löschen;
//     vorher war nur das Anlegen möglich, und in der Vertragsansicht waren
//     sie nicht als Hypothese erkennbar.
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal, confirmModal } from '../components/modal.js';
import { makeChart } from '../components/chart.js';
import { fmt as f, escapeHtml as esc } from '../lib/format.js';
import { t } from '../lib/i18n.js';

let sel = { utility: null, meterId: null, year: null };
let chart = null;
let lastData = null;

function destroyChart() {
  if (chart) { try { chart.destroy(); } catch {} chart = null; }
}

export async function render(container) {
  container.innerHTML = `<div class="loading" role="status">${esc(t('tariff.loading'))}</div>`;
  let utilities, settings;
  try {
    [utilities, settings] = await Promise.all([getUtilities(), getSettings()]);
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }

  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities : utilities.map(u => u.key);
  // Tarifvergleich/Schattenverträge ergeben nur für vertragsbasierte Arten
  // Sinn. Wasser nutzt ein eigenes Drei-Komponenten-Modell; Heizöl/Pellets
  // (lieferbasiert) haben keine Verträge — dort ist die Lieferrechnung die
  // Kostenbasis. PV-Erzeugung führt gar keine Verträge (has_contracts=false).
  const usable = utilities.filter(u =>
    active.includes(u.key) &&
    u.key !== 'wasser' &&
    u.reading_kind !== 'delivery' &&
    u.has_contracts !== false);

  if (usable.length === 0) {
    container.innerHTML = `<div class="banner banner--info">${esc(t('tariff.noUsable'))}</div>`;
    return;
  }
  if (!sel.utility || !usable.find(u => u.key === sel.utility)) {
    sel.utility = usable[0].key;
    sel.meterId = null;
  }
  const utility = usable.find(u => u.key === sel.utility);
  container.setAttribute('data-utility', utility.key);

  let meters = [];
  try { meters = await api.meters(sel.utility); } catch {}
  if (!sel.meterId || !meters.find(m => m.id === sel.meterId)) {
    sel.meterId = meters[0]?.id || null;
  }

  container.innerHTML = `
    <div class="view-head">
      <h1>${esc(t('tariff.title'))}</h1>
      <p class="muted">${esc(t('tariff.subtitle'))}</p>
    </div>
    <div class="toolbar">
      <label>${esc(t('tariff.utility'))}
        <select id="t-util">${usable.map(u =>
          `<option value="${esc(u.key)}" ${u.key === sel.utility ? 'selected' : ''}>${esc(u.label)}</option>`).join('')}</select>
      </label>
      <label>${esc(t('tariff.meter'))}
        <select id="t-meter">${meters.map(m =>
          `<option value="${esc(m.id)}" ${m.id === sel.meterId ? 'selected' : ''}>${esc(m.name || m.id)}</option>`).join('')}</select>
      </label>
      <label>${esc(t('tariff.year'))}
        <select id="t-year"><option value="">${esc(t('tariff.wholePeriod'))}</option>${yearOpts()}</select>
      </label>
      <button class="btn btn--util" id="t-addshadow">${esc(t('tariff.addShadow'))}</button>
    </div>
    <div id="t-result" aria-live="polite"><div class="loading" role="status">${esc(t('tariff.loadingComparison'))}</div></div>
  `;

  container.querySelector('#t-util').addEventListener('change', e => {
    sel.utility = e.target.value; sel.meterId = null; destroyChart(); render(container);
  });
  container.querySelector('#t-meter').addEventListener('change', e => {
    sel.meterId = e.target.value; loadResult(container);
  });
  container.querySelector('#t-year').addEventListener('change', e => {
    sel.year = e.target.value || null; loadResult(container);
  });
  container.querySelector('#t-addshadow').addEventListener('click', () =>
    openShadowForm(container, null));

  await loadResult(container);
  return destroyChart;
}

async function loadResult(container) {
  destroyChart();
  const box = container.querySelector('#t-result');
  if (!box) return;
  if (!sel.meterId) {
    box.innerHTML = `<div class="banner banner--info">${esc(t('tariff.noMeter'))}</div>`;
    return;
  }
  box.innerHTML = `<div class="loading" role="status">${esc(t('tariff.loadingComparison'))}</div>`;

  let data;
  try {
    data = await api.tariffComparison(sel.utility, sel.meterId, sel.year);
  } catch (e) {
    box.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }
  lastData = data;

  if (!data.supported) {
    box.innerHTML = `<div class="banner banner--info">${esc(data.note || t('tariff.notSupported'))}</div>`;
    return;
  }
  if (!data.rows || data.rows.length === 0) {
    box.innerHTML = `<div class="banner banner--info">${esc(data.note || t('tariff.noRows'))}</div>`;
    return;
  }

  const unit = data.unit || 'kWh';
  const anyPartial = data.rows.some(r => !r.covers_full_period);

  box.innerHTML = `
    <p class="muted small">
      ${data.period.from
        ? esc(t('tariff.periodRange', { label: data.period.label, from: data.period.from, to: data.period.to }))
        : esc(t('tariff.period', { label: data.period.label }))}
      ${data.real_total_eur != null
        ? ` · ${esc(t('tariff.realTotal', { value: f.eur(data.real_total_eur) }))}` : ''}
    </p>

    <div class="table-wrap">
      <table class="data-table tariff-table">
        <thead><tr>
          <th scope="col">${esc(t('tariff.col.tariff'))}</th>
          <th scope="col">${esc(t('tariff.col.period'))}</th>
          <th scope="col" class="num">${esc(t('tariff.col.consumption'))}</th>
          <th scope="col" class="num">${esc(t('tariff.col.cost'))}</th>
          <th scope="col" class="num" title="${esc(t('tariff.unitCostTitle'))}">${esc(t('tariff.col.unitCost', { unit }))}</th>
          <th scope="col" class="num">${esc(t('tariff.col.savings'))}</th>
          <th scope="col"><span class="sr-only">${esc(t('common.actions'))}</span></th>
        </tr></thead>
        <tbody>${data.rows.map(r => rowHtml(r, unit)).join('')}</tbody>
      </table>
    </div>

    ${anyPartial ? `<p class="muted small">${esc(t('tariff.partialHint'))}</p>` : ''}

    <div class="card" style="margin-top: var(--sp-4)">
      <div class="card__title">${esc(t('tariff.chartTitle'))}</div>
      <div class="chart-wrap h300"><canvas id="t-chart"></canvas></div>
    </div>

    <p class="muted small">${esc(t('tariff.legend'))}</p>
  `;

  drawChart(container, data);
  wireRowActions(container);
}

function rowHtml(r, unit) {
  const vs = r.vs_real_eur;
  const vsCls = vs == null ? '' : (vs < 0 ? 'success-text' : (vs > 0 ? 'danger-text' : ''));
  const vsStr = vs == null
    ? '<span class="dim">–</span>'
    : `${vs > 0 ? '+' : ''}${f.eur(vs)}${r.vs_real_pct != null
        ? ` <span class="muted">(${r.vs_real_pct > 0 ? '+' : ''}${f.num(r.vs_real_pct, 1)} %)</span>` : ''}`;

  const monthsLabel = r.months_covered === 1
    ? t('tariff.monthsOne')
    : t('tariff.months', { count: r.months_covered });
  const periodCell = r.covers_full_period
    ? `<span class="muted">${esc(monthsLabel)}</span>`
    : `<span class="badge badge--info">${esc(monthsLabel)}</span>`;

  // Die Hochrechnung nur zeigen, wo sie etwas beantwortet: bei einem
  // konkreten Jahr lautet die Frage „was hätte dieser Tarif das ganze Jahr
  // gekostet?". Über den gesamten Erfassungszeitraum (oft mehrere Jahre)
  // deckt kein Vertrag alles ab — dort stünde die Hochrechnung in jeder Zeile
  // und suggerierte eine Laufzeit, die es nie gab. Der Vergleich läuft dann
  // über den Einheitspreis.
  const showProjection = sel.year != null && !r.covers_full_period
    && r.projected_full_eur != null && lastData;
  const projected = showProjection
    ? `<div class="muted small">${esc(t('tariff.projectedFull', {
        months: lastData.period.months, value: f.eur(r.projected_full_eur) }))}</div>`
    : '';

  return `<tr class="${r.is_shadow ? 'row-shadow' : ''}" data-contract="${esc(r.contract_id)}">
    <td>${r.is_shadow ? `<span class="badge badge--shadow">${esc(t('tariff.shadowBadge'))}</span> ` : ''}${esc(r.label)}</td>
    <td>${periodCell}</td>
    <td class="num">${f.int(r.consumption)} ${esc(unit)}</td>
    <td class="num"><strong>${r.total_eur != null ? f.eur(r.total_eur) : '<span class="dim">–</span>'}</strong>${projected}</td>
    <td class="num">${r.unit_cost_ct != null ? f.num(r.unit_cost_ct, 2) : '<span class="dim">–</span>'}</td>
    <td class="num ${vsCls}">${vsStr}</td>
    <td class="cell-actions">${r.is_shadow ? `
      <button class="btn btn--xs btn--ghost" data-edit-shadow="${esc(r.contract_id)}"
              title="${esc(t('tariff.action.edit'))}" aria-label="${esc(t('tariff.action.edit'))}"><span aria-hidden="true">✎</span></button>
      <button class="btn btn--xs btn--ghost" data-delete-shadow="${esc(r.contract_id)}"
              title="${esc(t('tariff.action.delete'))}" aria-label="${esc(t('tariff.action.delete'))}"><span aria-hidden="true">🗑</span></button>
    ` : ''}</td>
  </tr>`;
}

function drawChart(container, data) {
  const canvas = container.querySelector('#t-chart');
  if (!canvas) return;
  // Verglichen wird der Einheitspreis, nicht die Summe: Er ist von der
  // Laufzeit unabhängig und stellt damit auch ein Quartal und ein Jahr
  // nebeneinander ehrlich dar. Absolute Kosten hätten hier den längsten
  // Vertrag automatisch als teuersten gezeigt.
  const rows = data.rows.filter(r => r.unit_cost_ct != null);
  if (!rows.length) return;

  const css = getComputedStyle(document.documentElement);
  const uColor = css.getPropertyValue(`--util-${data.utility}`).trim() || '#4a90e2';
  const shadowColor = css.getPropertyValue('--c-violet').trim() || '#8b5cf6';
  const unit = data.unit || 'kWh';

  chart = makeChart(canvas, {
    type: 'bar',
    data: {
      labels: rows.map(r => r.label),
      datasets: [{
        label: t('tariff.col.unitCost', { unit }),
        data: rows.map(r => r.unit_cost_ct),
        backgroundColor: rows.map(r => (r.is_shadow ? shadowColor : uColor) + '55'),
        borderColor: rows.map(r => r.is_shadow ? shadowColor : uColor),
        borderWidth: 1,
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { title: { display: true, text: `ct/${unit}` }, beginAtZero: true } },
    },
  }, { label: t('tariff.chartAlt', { unit }) });
}

function wireRowActions(container) {
  container.querySelectorAll('[data-edit-shadow]').forEach(b =>
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit-shadow');
      let existing = null;
      try {
        const all = await api.contracts(sel.utility);
        existing = all.find(c => c.id === id) || null;
      } catch (e) { toastErr(e.message || e); return; }
      openShadowForm(container, existing);
    }));

  container.querySelectorAll('[data-delete-shadow]').forEach(b =>
    b.addEventListener('click', async () => {
      const ok = await confirmModal({
        title: t('tariff.shadow.deleteTitle'),
        message: t('tariff.shadow.deleteMessage'),
        confirmLabel: t('tariff.shadow.deleteConfirm'),
        danger: true,
      });
      if (!ok) return;
      try {
        await api.deleteContract(sel.utility, b.getAttribute('data-delete-shadow'));
        toastOk(t('tariff.shadow.deleted'));
        await loadResult(container);
      } catch (e) { toastErr(e.message || e); }
    }));
}

/**
 * Anlegen und Bearbeiten teilen ein Formular. `existing` = null → anlegen.
 * Gegenüber v1.3.0 kam das Ende-Datum dazu: ohne es lief jede Hypothese
 * unbegrenzt weiter, was den Vergleich über lange Zeiträume verzerrte.
 */
function openShadowForm(container, existing) {
  const isEdit = !!existing;
  const unit = (lastData && lastData.unit) || 'kWh';
  const wp0 = existing?.working_prices?.[0] || {};
  const bp0 = existing?.base_prices?.[0] || {};
  const start = existing?.start || `${new Date().getFullYear()}-01-01`;

  openModal({
    title: isEdit ? t('tariff.shadow.titleEdit') : t('tariff.shadow.title'),
    body: `
      <p class="muted small">${esc(t('tariff.shadow.intro'))}</p>
      <div class="form-grid">
        <label>${esc(t('tariff.shadow.label'))}<input type="text" id="s-label"
          value="${esc(existing?.shadow_label || existing?.tariff_name || '')}"
          placeholder="${esc(t('tariff.shadow.labelPlaceholder'))}"></label>
        <label>${esc(t('tariff.shadow.provider'))}<input type="text" id="s-prov"
          value="${esc(existing?.provider || '')}"
          placeholder="${esc(t('tariff.shadow.providerPlaceholder'))}"></label>
        <label>${esc(t('tariff.shadow.start'))}<input type="date" id="s-start" value="${esc(start)}"></label>
        <label>${esc(t('tariff.shadow.end'))}<input type="date" id="s-end" value="${esc(existing?.end || '')}">
          <span class="settings-field__hint">${esc(t('tariff.shadow.endHint'))}</span></label>
        <label>${esc(t('tariff.shadow.workingPrice', { unit }))}<input type="number" step="0.01" id="s-wp"
          value="${wp0.ct_per_kwh ?? ''}" placeholder="${esc(t('tariff.shadow.workingPlaceholder'))}"></label>
        <label>${esc(t('tariff.shadow.basePrice'))}<input type="number" step="0.01" id="s-bp"
          value="${bp0.eur_per_month ?? ''}" placeholder="${esc(t('tariff.shadow.basePlaceholder'))}"></label>
      </div>`,
    footer: `
      <button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
      <button type="button" class="btn btn--primary" data-act="save">${esc(isEdit ? t('tariff.shadow.save') : t('tariff.shadow.create'))}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
        const startVal = bodyEl.querySelector('#s-start').value;
        const endVal   = bodyEl.querySelector('#s-end').value;
        const wp = parseFloat(bodyEl.querySelector('#s-wp').value);
        const bp = parseFloat(bodyEl.querySelector('#s-bp').value);
        const label = bodyEl.querySelector('#s-label').value.trim();
        if (!label || !startVal || isNaN(wp)) { toastErr(t('tariff.shadow.validation')); return; }

        const payload = {
          meter_id: sel.meterId,
          provider: bodyEl.querySelector('#s-prov').value.trim(),
          tariff_name: label,
          start: startVal,
          end: endVal || null,
          is_shadow: true,
          shadow_label: label,
          working_prices: [{ from: startVal, ct_per_kwh: wp }],
          base_prices: isNaN(bp) ? [] : [{ from: startVal, eur_per_month: bp }],
        };
        try {
          if (isEdit) await api.updateContract(sel.utility, existing.id, payload);
          else        await api.createContract(sel.utility, payload);
          toastOk(isEdit ? t('tariff.shadow.updated') : t('tariff.shadow.created'));
          close(null);
          await loadResult(container);
        } catch (e) { toastErr(t('tariff.shadow.error', { msg: e.message || e })); }
      });
    },
  });
}

function yearOpts() {
  const now = new Date().getFullYear();
  let o = '';
  for (let y = now; y >= now - 6; y--) {
    o += `<option value="${y}" ${String(y) === String(sel.year) ? 'selected' : ''}>${y}</option>`;
  }
  return o;
}
