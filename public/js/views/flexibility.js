// =====================================================================
// v3.3.0 (F1021) — Flexibilität bewerten: Was hat das Verschieben gebracht?
//
// Gesteuert wird außerhalb (evcc, Home Assistant, der Speicher selbst); hier
// wird mit Verbrauch und Börsenpreis je Viertelstunde nachgerechnet:
//   • je Stromzähler: kWh in günstigen und negativen Viertelstunden, eigener
//     Durchschnittspreis gegen das Börsenmittel (Profilfaktor), Vorteil
//   • Speicher: geladen zu welchem Preis, entladen zu welchem
//   • § 14a Modul 3: Ersparnis aus den Zeitfenstern am Vertrag
// Gerechnet wird nur der Börsenanteil — ohne Steuern, Umlagen, Netzentgelt.
// =====================================================================
import { api } from '../api.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { toastErr, toastOk } from '../components/toast.js';
import { confirmModal } from '../components/modal.js';
import { t } from '../lib/i18n.js';
import { info } from '../components/info.js';
import { docUrl } from '../lib/docs.js';

const esc = escapeHtml;

export async function render(container, _params = [], ctx = {}) {
  const now = new Date().getFullYear();
  const wanted = Number(ctx.query?.get('year'));
  const year = wanted >= 2018 && wanted <= now ? wanted : now - 1;
  container.innerHTML = `<div class="loading">${esc(t('common.loading'))}</div>`;
  let r, series;
  try {
    [r, series] = await Promise.all([api.flexibility(year), api.intervals().catch(() => ({ series: [] }))]);
  } catch (e) {
    container.innerHTML = `<div class="banner banner--error">${esc(e.message)}</div>`;
    return;
  }
  const years = [now, now - 1, now - 2, now - 3];
  const meters = [...(r.meters || [])].sort((a, b) => Number(b.controllable) - Number(a.controllable));

  container.innerHTML = `
    <div class="section-head">
      <h1>${esc(t('flex.title'))}</h1>
      <div class="section-actions">
        <select class="select" id="flex-year" aria-label="${esc(t('flex.year'))}">
          ${years.map(y => `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`).join('')}
        </select>
        <button type="button" class="btn btn--ghost" id="flex-smard">${esc(t('flex.loadPrices', { year }))}</button>
      </div>
    </div>
    <p class="muted">${esc(t('flex.intro'))} <a href="${esc(docUrl('flexibility'))}">${esc(t('common.guide'))}</a></p>
    ${!r.prices?.available ? `<div class="banner banner--info">${esc(t('flex.noPrices', { year }))}</div>` : ''}
    ${!meters.length ? `<div class="banner banner--info">${esc(t('flex.noIntervals', { year }))}</div>` : ''}
    ${r.prices?.available ? meters.map(m => meterCard(m, r.year)).join('') : ''}
    ${r.battery ? batteryCard(r.battery) : ''}
    ${(r.module3 || []).map(module3Card).join('')}
    ${seriesCard(series.series || [], new Map(meters.map(m => [m.meter_id, m.name])))}
    ${r.prices?.available ? `<p class="muted small">${esc(t('flex.attribution'))}</p>` : ''}`;

  container.querySelector('#flex-year').addEventListener('change', (e) => { location.hash = `#/flexibility?year=${e.target.value}`; });
  container.querySelector('#flex-smard').addEventListener('click', async (e) => {
    e.target.disabled = true;
    try {
      const s = await api.syncSmardIntervals(year);
      toastOk(t('flex.pricesLoaded', { year, slots: fmt.int(s.slots) }));
      render(container, _params, ctx);
    } catch (err) { toastErr(err.message); e.target.disabled = false; }
  });
  container.querySelectorAll('[data-series-del]').forEach(b => b.addEventListener('click', async () => {
    if (!await confirmModal({ message: t('flex.series.confirmDelete'), confirmLabel: t('flex.series.delete'), danger: true })) return;
    try { await api.deleteInterval(b.dataset.seriesDel); render(container, _params, ctx); }
    catch (err) { toastErr(err.message); }
  }));
}

const month = (y, m) => fmt.month(`${y}-${String(m).padStart(2, '0')}`);
const ct = (v) => (v == null ? '–' : `${fmt.num(v, 2)} ct`);

function kpis(items) {
  return `<div class="kpi-grid">${items.map(([label, value, help]) => `
    <div class="kpi"><div class="kpi__label">${esc(label)}${help ? info(help) : ''}</div><div class="kpi__value">${esc(value)}</div></div>`).join('')}</div>`;
}

function meterCard(m, year) {
  const tot = m.total;
  return `<div class="card">
    <h2 class="card__title">${esc(m.name)}${m.controllable ? ` <span class="tag">${esc(t('flex.controllable'))}</span>` : ''}</h2>
    ${tot ? kpis([
      [t('flex.kpi.kwh'), `${fmt.num(tot.priced_kwh, 0)} kWh`],
      [t('flex.kpi.cheap'), fmt.pct(tot.cheap_share, 0)],
      [t('flex.kpi.negative'), `${fmt.num(tot.negative_kwh, 0)} kWh`],
      [t('flex.kpi.own'), `${ct(tot.own_ct)} / ${ct(tot.market_ct)}`],
      [t('flex.kpi.factor'), tot.profile_factor == null ? '–' : fmt.num(tot.profile_factor, 2), 'profileFactor'],
      [t('flex.kpi.advantage'), fmt.eur(tot.advantage_eur)],
    ]) : `<p class="muted">${esc(t('flex.noOverlap'))}</p>`}
    ${tot ? `<p class="small">${esc(t(tot.advantage_eur >= 0 ? 'flex.verdict.good' : 'flex.verdict.bad', { eur: fmt.eur(Math.abs(tot.advantage_eur)), factor: fmt.num(tot.profile_factor ?? 1, 2) }))}</p>` : ''}
    <details class="flex-months"><summary>${esc(t('flex.months'))}</summary>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th>${esc(t('flex.col.month'))}</th><th class="num">kWh</th><th class="num">${esc(t('flex.col.cheap'))}</th>
          <th class="num">${esc(t('flex.col.negative'))}</th><th class="num">${esc(t('flex.col.own'))}</th><th class="num">${esc(t('flex.col.market'))}</th>
          <th class="num">${esc(t('flex.col.advantage'))}</th><th class="num">${esc(t('flex.col.coverage'))}</th></tr></thead>
        <tbody>${(m.months || []).map(x => `<tr><td>${esc(month(year, x.month))}</td>
          <td class="num">${fmt.num(x.kwh, 1)}</td><td class="num">${fmt.num(x.cheap_kwh, 1)}</td><td class="num">${fmt.num(x.negative_kwh, 1)}</td>
          <td class="num">${ct(x.own_ct)}</td><td class="num">${ct(x.market_ct)}</td>
          <td class="num">${x.advantage_eur == null ? '–' : fmt.eur(x.advantage_eur)}</td><td class="num">${fmt.pct(x.coverage, 0)}</td></tr>`).join('')}</tbody>
      </table></div>
    </details>
  </div>`;
}

function batteryCard(b) {
  const tot = b.total;
  return `<div class="card">
    <h2 class="card__title">${esc(t('flex.battery.title'))}</h2>
    ${kpis([
      [t('flex.battery.charged'), `${fmt.num(tot.charged_kwh, 0)} kWh · ${ct(tot.charge_ct)}`],
      [t('flex.battery.discharged'), `${fmt.num(tot.discharged_kwh, 0)} kWh · ${ct(tot.discharge_ct)}`],
      [t('flex.battery.value'), fmt.eur(tot.value_eur)],
    ])}
    <p class="small muted">${esc(t('flex.battery.hint'))}</p>
  </div>`;
}

function module3Card(m) {
  const tot = m.total;
  return `<div class="card">
    <h2 class="card__title">${esc(t('flex.module3.title', { name: m.name }))}${info('module14a')}</h2>
    ${kpis([
      [t('flex.module3.low'), `${fmt.num(tot.low_kwh, 0)} kWh`],
      [t('flex.module3.high'), `${fmt.num(tot.high_kwh, 0)} kWh`],
      [t('flex.module3.standard'), `${fmt.num(tot.standard_kwh, 0)} kWh`],
      [t('flex.module3.saving'), fmt.eur(tot.saving_eur)],
    ])}
    <p class="small muted">${esc(t('flex.module3.hint'))}</p>
  </div>`;
}

/** names: Zählername je ID aus der Auswertung — Reihen anderer Zähler (Speicher an der PV) zeigen Art · ID. */
function seriesCard(list, names = new Map()) {
  if (!list.length) return '';
  return `<div class="card" data-min-level="expert">
    <h2 class="card__title">${esc(t('flex.series.title'))}</h2>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th>${esc(t('flex.series.what'))}</th><th class="num">${esc(t('flex.year'))}</th>
        <th class="num">${esc(t('flex.col.coverage'))}</th><th>${esc(t('flex.series.source'))}</th><th></th></tr></thead>
      <tbody>${list.map(s => `<tr>
        <td>${esc(s.kind === 'price' ? t('flex.series.prices') : (names.get(s.meter_id) || `${s.utility} · ${s.meter_id}`))}</td>
        <td class="num">${s.year}</td><td class="num">${fmt.pct(s.slots ? s.filled / s.slots : 0, 0)}</td>
        <td>${esc(t('flex.series.sources.' + (['import', 'ingest', 'smard'].includes(s.source) ? s.source : 'import')))}</td>
        <td class="row-actions"><button type="button" class="icon-btn" data-series-del="${esc(s.id)}" aria-label="${esc(t('flex.series.delete'))}"><span aria-hidden="true">🗑️</span></button></td>
      </tr>`).join('')}</tbody>
    </table></div>
  </div>`;
}
