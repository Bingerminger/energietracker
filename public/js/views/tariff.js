// =====================================================================
// Energietracker v1.3.0 — Tarifvergleich (echt vs. Schattenverträge)
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal } from '../components/modal.js';
import { fmt as f } from '../lib/format.js';
import { t } from '../lib/i18n.js';

let sel = { utility: null, meterId: null, year: null };

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('tariff.loading')}</div>`;
  let utilities, settings;
  try {
    [utilities, settings] = await Promise.all([getUtilities(), getSettings()]);
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }

  const active = Array.isArray(settings.active_utilities) && settings.active_utilities.length
    ? settings.active_utilities : utilities.map(u => u.key);
  // Tarifvergleich/Schattenverträge ergeben nur für vertragsbasierte
  // Arten Sinn. Wasser nutzt ein eigenes Drei-Komponenten-Modell und
  // ist nicht unterstützt; Heizöl/Pellets (lieferbasiert) haben keine
  // Verträge — dort ist die Tankrechnung die Kostenbasis.
  const usable = utilities.filter(u =>
    active.includes(u.key) && u.key !== 'wasser' && u.reading_kind !== 'delivery');

  if (usable.length === 0) {
    container.innerHTML = `<div class="banner banner--info">${t('tariff.noUsable')}</div>`;
    return;
  }
  if (!sel.utility || !usable.find(u => u.key === sel.utility)) {
    sel.utility = usable[0].key;
    sel.meterId = null;
  }

  let meters = [];
  try { meters = await api.meters(sel.utility); } catch {}
  if (!sel.meterId || !meters.find(m => m.id === sel.meterId)) {
    sel.meterId = meters[0]?.id || null;
  }

  container.innerHTML = `
    <div class="view-head"><h1>${t('tariff.title')}</h1>
      <p class="muted">${t('tariff.subtitle')}</p>
    </div>
    <div class="toolbar">
      <label>${t('tariff.utility')}
        <select id="t-util">${usable.map(u =>
          `<option value="${u.key}" ${u.key === sel.utility ? 'selected' : ''}>${esc(u.label)}</option>`).join('')}</select>
      </label>
      <label>${t('tariff.meter')}
        <select id="t-meter">${meters.map(m =>
          `<option value="${m.id}" ${m.id === sel.meterId ? 'selected' : ''}>${esc(m.name || m.id)}</option>`).join('')}</select>
      </label>
      <label>${t('tariff.year')}
        <select id="t-year"><option value="">${t('tariff.wholePeriod')}</option>${yearOpts()}</select>
      </label>
      <button class="btn btn--ghost" id="t-addshadow">${t('tariff.addShadow')}</button>
    </div>
    <div id="t-result"><div class="loading">${t('tariff.loadingComparison')}</div></div>
  `;

  container.querySelector('#t-util').addEventListener('change', e => {
    sel.utility = e.target.value; sel.meterId = null; render(container);
  });
  container.querySelector('#t-meter').addEventListener('change', e => {
    sel.meterId = e.target.value; loadResult(container);
  });
  container.querySelector('#t-year').addEventListener('change', e => {
    sel.year = e.target.value || null; loadResult(container);
  });
  container.querySelector('#t-addshadow').addEventListener('click', () =>
    openShadowForm(container));

  await loadResult(container);
}

async function loadResult(container) {
  const box = container.querySelector('#t-result');
  if (!box || !sel.meterId) { if (box) box.innerHTML = `<div class="banner banner--info">${t('tariff.noMeter')}</div>`; return; }
  box.innerHTML = `<div class="loading">${t('tariff.loadingComparison')}</div>`;
  let data;
  try {
    data = await api.tariffComparison(sel.utility, sel.meterId, sel.year);
  } catch (e) {
    box.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }
  if (!data.supported) {
    box.innerHTML = `<div class="banner banner--info">${esc(data.note || t('tariff.notSupported'))}</div>`;
    return;
  }
  if (!data.rows || data.rows.length === 0) {
    box.innerHTML = `<div class="banner banner--info">${esc(data.note || t('tariff.noContracts'))}</div>`;
    return;
  }

  const rows = data.rows.map(r => {
    const vs = r.vs_real_eur;
    const vsCls = vs == null ? '' : (vs < 0 ? 'pos' : (vs > 0 ? 'neg' : ''));
    const vsStr = vs == null ? '–' : (vs > 0 ? '+' : '') + f.eur(vs);
    return `<tr class="${r.is_shadow ? 'row-shadow' : ''}">
      <td>${r.is_shadow ? `<span class="badge badge--info">${t('tariff.shadowBadge')}</span> ` : ''}${esc(r.label)}</td>
      <td>${f.int(r.consumption)} kWh</td>
      <td>${f.eur(r.total_eur)}</td>
      <td class="delta ${vsCls}">${vsStr}</td>
    </tr>`;
  }).join('');

  box.innerHTML = `
    <p class="muted small">${data.period.from
      ? t('tariff.periodRange', { label: esc(data.period.label), from: data.period.from, to: data.period.to })
      : t('tariff.period', { label: esc(data.period.label) })}</p>
    <table class="data-table">
      <thead><tr><th scope="col">${t('tariff.col.tariff')}</th><th scope="col">${t('tariff.col.consumption')}</th><th scope="col">${t('tariff.col.cost')}</th><th scope="col">${t('tariff.col.vsReal')}</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
    <p class="muted small">${t('tariff.legend')}</p>
  `;
}

function openShadowForm(container) {
  openModal({
    title: t('tariff.shadow.title'),
    body: `
      <p class="muted small">${t('tariff.shadow.intro')}</p>
      <div class="form-grid">
        <label>${t('tariff.shadow.label')}<input type="text" id="s-label" placeholder="${t('tariff.shadow.labelPlaceholder')}"></label>
        <label>${t('tariff.shadow.provider')}<input type="text" id="s-prov" placeholder="${t('tariff.shadow.providerPlaceholder')}"></label>
        <label>${t('tariff.shadow.start')}<input type="date" id="s-start" value="${new Date().getFullYear()}-01-01"></label>
        <label>${t('tariff.shadow.workingPrice')}<input type="number" step="0.01" id="s-wp" placeholder="${t('tariff.shadow.workingPlaceholder')}"></label>
        <label>${t('tariff.shadow.basePrice')}<input type="number" step="0.01" id="s-bp" placeholder="${t('tariff.shadow.basePlaceholder')}"></label>
      </div>`,
    footer: `
      <button class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button class="btn btn--primary" data-act="save">${t('tariff.shadow.create')}</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
        const start = bodyEl.querySelector('#s-start').value;
        const wp = parseFloat(bodyEl.querySelector('#s-wp').value);
        const bp = parseFloat(bodyEl.querySelector('#s-bp').value);
        const label = bodyEl.querySelector('#s-label').value.trim();
        if (!label || !start || isNaN(wp)) { toastErr(t('tariff.shadow.validation')); return; }
        const payload = {
          meter_id: sel.meterId,
          provider: bodyEl.querySelector('#s-prov').value.trim(),
          tariff_name: label,
          start,
          is_shadow: true,
          shadow_label: label,
          working_prices: [{ from: start, ct_per_kwh: wp }],
          base_prices: isNaN(bp) ? [] : [{ from: start, eur_per_month: bp }],
        };
        try {
          await api.createContract(sel.utility, payload);
          toastOk(t('tariff.shadow.created'));
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
function esc(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
