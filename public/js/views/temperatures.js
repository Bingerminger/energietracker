// =====================================================================
// Temperature view — CSV import, Open-Meteo sync, monthly min/avg/max chart.
// =====================================================================

import { api } from '../api.js';
import { getSettings } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { makeChart, themeColors } from '../components/chart.js';
import { t } from '../lib/i18n.js';

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('temperatures.loading')}</div>`;
  const [rawTemps, settings] = await Promise.all([api.temperatures(), getSettings()]);

  // Backend liefert eine Map { "YYYY-MM-DD": {min, avg, max} } — wir
  // brauchen hier eine sortierte Liste {date, min, avg, max} für die UI.
  const days = Object.entries(rawTemps || {})
    .map(([date, v]) => ({
      date,
      min: v?.min ?? null,
      avg: v?.avg ?? null,
      max: v?.max ?? null,
    }))
    .sort((a, b) => a.date.localeCompare(b.date));

  container.innerHTML = `
    <div class="section-head">
      <h1>${t('temperatures.title')}</h1>
      <div class="section-actions">
        <button type="button" class="btn btn--primary" id="btn-sync">${t('temperatures.sync')}</button>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="card">
        <h3 class="card__title">${t('temperatures.csvImport')}</h3>
        <p class="muted">${t('temperatures.formatHint')}</p>
        <div class="drop-zone" id="drop" role="button" tabindex="0" aria-label="${t('temperatures.dropZoneAria')}">
          <p>${t('temperatures.dropZone')}</p>
          <input type="file" id="csv-input" accept=".csv,text/csv,text/plain" style="display:none">
        </div>
        <button type="button" class="btn btn--sm btn--ghost" id="dl-example" style="margin-top:10px"><span aria-hidden="true">⬇</span> ${t('temperatures.downloadExample')}</button>
      </div>
      <div class="card">
        <h3 class="card__title">${t('temperatures.location')}</h3>
        <div class="form-row">
          <div class="field"><label for="lat">${t('temperatures.lat')}</label><input class="input" id="lat" type="number" step="0.0001" value="${settings.latitude ?? 51.3397}"></div>
          <div class="field"><label for="lng">${t('temperatures.lng')}</label><input class="input" id="lng" type="number" step="0.0001" value="${settings.longitude ?? 12.3731}"></div>
          <div class="field"><label for="loc-name">${t('temperatures.locName')}</label><input class="input input--text" id="loc-name" value="${escapeHtml(settings.location_name || 'Leipzig')}"></div>
        </div>
        <p class="muted">${t('temperatures.locHint')}</p>
      </div>
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <div class="section-head">
        <h2 style="margin:0;font-size:var(--fs-lg)">${t('temperatures.monthly')}</h2>
        <div class="muted">${t('temperatures.daysLoaded', { count: days.length })}</div>
      </div>
      <div class="chart-wrap"><canvas id="temp-chart"></canvas></div>
    </div>
  `;

  // CSV import
  const drop = container.querySelector('#drop');
  const input = container.querySelector('#csv-input');
  drop.addEventListener('click', () => input.click());
  // A11y (N1009): Die Drop-Zone ist role="button" — Enter/Leertaste lösen sie aus.
  drop.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
  });
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
  drop.addEventListener('drop', async (e) => {
    e.preventDefault(); drop.classList.remove('dragover');
    const file = e.dataTransfer.files[0]; if (file) await importCsv(file, container);
  });
  input.addEventListener('change', async (e) => {
    const file = e.target.files[0]; if (file) await importCsv(file, container);
  });

  // Beispiel-CSV als Datei erzeugen und herunterladen.
  container.querySelector('#dl-example')?.addEventListener('click', () => {
    const csv = '15.01.2024"4.2"-1.0"7.1\n16.01.2024"3.8"-2.0"6.5\n';
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'beispiel-temperaturen.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // Open-Meteo sync
  container.querySelector('#btn-sync').addEventListener('click', async () => {
    try {
      // Persist location to settings first (so backend uses it for the sync)
      await api.updateSettings({
        latitude:      Number(container.querySelector('#lat').value),
        longitude:     Number(container.querySelector('#lng').value),
        location_name: container.querySelector('#loc-name').value,
      });
      const result = await api.syncOpenMeteo({});
      toastOk(t('temperatures.syncToast', { imported: result.imported || 0, archive: result.archive_rows || 0, forecast: result.forecast_rows || 0 }));
      if (result.archive_error)  toastErr(t('temperatures.archiveError', { err: result.archive_error }));
      if (result.forecast_error) toastErr(t('temperatures.forecastError', { err: result.forecast_error }));
      render(container);
    } catch (e) { toastErr(e.message); }
  });

  renderMonthlyChart(days);

  return () => { const c = window._tempChart; if (c) { c.destroy(); window._tempChart = null; } };
}

async function importCsv(file, container) {
  try {
    const text = await file.text();
    const res = await api.importTempCsv(text);
    toastOk(t('temperatures.importToast', { imported: res.imported || 0, skipped: res.skipped || 0 }));
    render(container);
  } catch (e) { toastErr(e.message); }
}

function renderMonthlyChart(days) {
  const canvas = document.getElementById('temp-chart');
  if (!canvas) return;
  if (window._tempChart) window._tempChart.destroy();

  // Aggregate per month
  const byMonth = {};
  days.forEach(d => {
    const ym = (d.date || '').slice(0, 7);
    if (!ym) return;
    if (!byMonth[ym]) byMonth[ym] = { min: Infinity, max: -Infinity, sum: 0, n: 0 };
    if (d.min != null) byMonth[ym].min = Math.min(byMonth[ym].min, Number(d.min));
    if (d.max != null) byMonth[ym].max = Math.max(byMonth[ym].max, Number(d.max));
    if (d.avg != null) { byMonth[ym].sum += Number(d.avg); byMonth[ym].n++; }
  });
  const months = Object.keys(byMonth).sort();
  const labels = months.map(m => fmt.month(m));
  const mins   = months.map(m => byMonth[m].min === Infinity ? null : byMonth[m].min);
  const maxes  = months.map(m => byMonth[m].max === -Infinity ? null : byMonth[m].max);
  const avgs   = months.map(m => byMonth[m].n ? byMonth[m].sum / byMonth[m].n : null);

  window._tempChart = makeChart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Max', data: maxes, borderColor: '#f87171', backgroundColor: 'rgba(248,113,113,0.1)', tension: 0.25 },
        { label: 'ø',   data: avgs,  borderColor: themeColors.text1, backgroundColor: 'rgba(231,236,243,0.1)', tension: 0.25 },
        { label: 'Min', data: mins,  borderColor: '#60a5fa', backgroundColor: 'rgba(96,165,250,0.1)', tension: 0.25 },
      ],
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: '°C' } } } },
  }, { label: t('temperatures.chart.alt') });
}
