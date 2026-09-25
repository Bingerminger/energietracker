// =====================================================================
// Temperature view — CSV import, Open-Meteo sync, monthly min/avg/max chart.
// =====================================================================

import { api } from '../api.js';
import { getSettings, getCountries, saveSettings } from '../state.js';
import { fmt, escapeHtml, parseDecimal, formatForInput } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { guardSubmit } from '../components/modal.js';
import { showFieldError } from '../lib/form.js';
import { makeChart, tokenColor, chartTableHtml } from '../components/chart.js';
import { t } from '../lib/i18n.js';

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('temperatures.loading')}</div>`;
  const [rawTemps, settings, countries] = await Promise.all([
    api.temperatures(), getSettings(), getCountries(),
  ]);
  // v2.7.0 — Steht der Standort noch auf der Voreinstellung des Landes
  // (Hauptstadt bzw. Leipzig), rechnen Gradtagzahlen und Prognosen mit dem
  // Wetter eines anderen Orts. Ein Hinweis unter den Koordinaten sagt das.
  const profile = (countries || []).find(c => c.code === settings.country);
  const defaultLocation = profile
    && Number(settings.latitude) === Number(profile.latitude)
    && Number(settings.longitude) === Number(profile.longitude);

  // Backend liefert eine Map { "YYYY-MM-DD": {min, avg, max} } — wir
  // brauchen hier eine sortierte Liste {date, min, avg, max} für die UI.
  const days = Object.entries(rawTemps || {})
    .map(([date, v]) => ({
      date,
      min: v?.min ?? null,
      avg: v?.avg ?? null,
      max: v?.max ?? null,
      source: v?.source ?? null,   // v2.8.0: archive | forecast | csv | manual
    }))
    .sort((a, b) => a.date.localeCompare(b.date));
  // v2.8.0 — bis wann Messwerte vorliegen, ab wann Vorhersage. Einträge ohne
  // Quelle (vor v2.8.0) gelten bis heute − 6 Tage als gemessen.
  const horizon = new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10);
  let measuredUntil = null, forecastUntil = null;
  for (const d of days) {
    const measured = d.source ? d.source !== 'forecast' : d.date <= horizon;
    if (measured) measuredUntil = d.date; else forecastUntil = d.date;
  }

  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${t('temperatures.title')}</h1>
        <div class="view-header__subtitle">${escapeHtml(t('temperatures.subtitle'))}</div>
      </div>
      <div class="view-header__actions">
        <button type="button" class="btn btn--primary" id="btn-sync">${t('temperatures.sync')}</button>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="card">
        <h2 class="card__title">${t('temperatures.csvImport')}</h2>
        <p class="muted">${t('temperatures.formatHint')}</p>
        <div class="drop-zone" id="drop" role="button" tabindex="0" aria-label="${t('temperatures.dropZoneAria')}">
          <p>${t('temperatures.dropZone')}</p>
          <input type="file" id="csv-input" accept=".csv,text/csv,text/plain" style="display:none">
        </div>
        <button type="button" class="btn btn--sm btn--ghost" id="dl-example" style="margin-top:10px"><span aria-hidden="true">⬇</span> ${t('temperatures.downloadExample')}</button>
      </div>
      <div class="card">
        <h2 class="card__title">${t('temperatures.location')}</h2>
        <!-- v2.12.0 (Review UI-30) — Ortssuche statt Koordinaten von Hand; der
             Standort steht nur noch hier (bis v2.11 auch in den Einstellungen)
             und wird beim Verlassen eines Feldes gespeichert, nicht erst beim
             Abgleich. -->
        <div class="field">
          <label for="geo-q">${t('temperatures.geoLabel')}</label>
          <div class="geo-search">
            <input class="input input--text" id="geo-q" type="search" autocomplete="off" enterkeyhint="search" placeholder="${escapeHtml(t('temperatures.geoPlaceholder'))}">
            <button type="button" class="btn" id="geo-go">${t('temperatures.geoSearch')}</button>
          </div>
          <ul class="geo-results" id="geo-results" aria-live="polite"></ul>
        </div>
        <div class="form-row">
          <!-- v2.5.3 — Text statt type="number": leer ergab 0/0 (Golf von Guinea),
               „51,34" je nach Browser ebenfalls. Kein inputmode="decimal", weil
               die iOS-Zahlentastatur kein Minus hat (westliche Längengrade). -->
          <div class="field"><label for="lat">${t('temperatures.lat')}</label><input class="input" id="lat" type="text" autocomplete="off" spellcheck="false" value="${escapeHtml(formatForInput(settings.latitude ?? 51.3397, 4))}">
            <div class="field-error" id="lat-msg" role="alert" hidden></div></div>
          <div class="field"><label for="lng">${t('temperatures.lng')}</label><input class="input" id="lng" type="text" autocomplete="off" spellcheck="false" value="${escapeHtml(formatForInput(settings.longitude ?? 12.3731, 4))}">
            <div class="field-error" id="lng-msg" role="alert" hidden></div></div>
          <div class="field"><label for="loc-name">${t('temperatures.locName')}</label><input class="input input--text" id="loc-name" value="${escapeHtml(settings.location_name || 'Leipzig')}"></div>
        </div>
        <p class="muted">${t('temperatures.locHint')}</p>
        <label class="settings-field__check" style="margin-top:var(--sp-2)">
          <input type="checkbox" id="auto-fill" ${settings.weather_auto_fill !== false ? 'checked' : ''}> ${t('settings.field.weather_auto_fill.label')}
        </label>
        <p class="settings-field__hint">${t('settings.field.weather_auto_fill.hint')}</p>
        <label class="settings-field__check" style="margin-top:var(--sp-2)">
          <input type="checkbox" id="sync-reload"> ${t('temperatures.reload')}
        </label>
        ${defaultLocation ? `<p class="banner banner--info" style="margin:var(--sp-3) 0 0">${escapeHtml(t('temperatures.locationDefault', { country: t('countries.' + profile.code), name: profile.location_name }))}</p>` : ''}
      </div>
    </div>

    <div class="card" style="margin-top: var(--sp-5)">
      <div class="section-head">
        <h2 class="card__title">${t('temperatures.monthly')}</h2>
        <div class="muted">${t('temperatures.daysLoaded', { count: days.length })}</div>
      </div>
      ${measuredUntil ? `<p class="muted" style="margin-top:0">${t(forecastUntil ? 'temperatures.statusWithForecast' : 'temperatures.status', {
        measured: fmt.date(measuredUntil), forecast: forecastUntil ? fmt.date(forecastUntil) : '',
      })}</p>` : ''}
      <div class="chart-wrap"><canvas id="temp-chart"></canvas></div>
      <div data-role="temp-chart-data"></div>
      <!-- v2.13.0 (Review DOC-22) — Quelle und Lizenz der Wetterdaten -->
      <p class="muted small attribution">${escapeHtml(t('temperatures.attribution'))} <a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo.com</a> (<a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a>)</p>
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
    // v2.12.0 — übliches CSV mit Semikolon (das alte Format mit
    // Anführungszeichen liest der Import weiter)
    const csv = 'Datum;Mittel;Min;Max\n15.01.2024;4,2;-1,0;7,1\n16.01.2024;3,8;-2,0;6,5\n';
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'beispiel-temperaturen.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  // v2.12.0 (Review UI-30) — Standort prüfen und speichern; beim Verlassen
  // eines Feldes, nach der Ortssuche und vor dem Abgleich
  const coord = (id, limit) => {
    const el = container.querySelector(`#${id}`);
    const n = parseDecimal(el.value);
    const ok = n !== null && Math.abs(n) <= limit;
    showFieldError(el, container.querySelector(`#${id}-msg`),
      ok ? null : t('temperatures.coordInvalid', { min: -limit, max: limit }));
    return ok ? n : null;
  };
  const saveLocation = async ({ quiet = false } = {}) => {
    const latitude  = coord('lat', 90);
    const longitude = latitude === null ? null : coord('lng', 180);
    if (latitude === null || longitude === null) return false;
    const location_name = container.querySelector('#loc-name').value.trim();
    const current = await getSettings();
    if (current.latitude === latitude && current.longitude === longitude && (current.location_name || '') === location_name) return true;
    // v2.11.0 (FE-12) — über saveSettings: Das Neuzeichnen las bis v2.10 den
    // alten Standort aus dem Cache und schrieb ihn beim nächsten Klick zurück.
    await saveSettings({ latitude, longitude, location_name });
    if (!quiet) toastOk(t('temperatures.locationSaved'));
    return true;
  };
  ['#lat', '#lng', '#loc-name'].forEach(sel => container.querySelector(sel)?.addEventListener('change', () => {
    saveLocation().catch(e => toastErr(e.message));
  }));

  // Ortssuche (Open-Meteo Geocoding über das eigene Backend)
  const geoInput = container.querySelector('#geo-q');
  const geoList = container.querySelector('#geo-results');
  let geoSeq = 0;
  const searchPlaces = async () => {
    const q = geoInput.value.trim();
    if (q.length < 2) { geoInput.focus(); return; }
    const my = ++geoSeq;
    geoList.innerHTML = `<li class="muted">${escapeHtml(t('common.loading'))}</li>`;
    try {
      const places = await api.geocode(q);
      if (my !== geoSeq) return;
      geoList.innerHTML = places.length
        ? places.map((p, i) => `<li><button type="button" class="btn btn--ghost btn--sm geo-results__item" data-geo="${i}">
            ${escapeHtml([p.name, p.postcode, p.admin1, p.country].filter(Boolean).join(', '))}
            <span class="muted">${escapeHtml(fmt.num(p.latitude, 2))}, ${escapeHtml(fmt.num(p.longitude, 2))}</span></button></li>`).join('')
        : `<li class="muted">${escapeHtml(t('temperatures.geoNone'))}</li>`;
      geoList.querySelectorAll('[data-geo]').forEach(btn => btn.addEventListener('click', async () => {
        const p = places[Number(btn.dataset.geo)];
        container.querySelector('#lat').value = formatForInput(p.latitude, 4);
        container.querySelector('#lng').value = formatForInput(p.longitude, 4);
        container.querySelector('#loc-name').value = p.name;
        geoList.innerHTML = '';
        try { await saveLocation(); } catch (e) { toastErr(e.message); }
      }));
    } catch (e) {
      if (my === geoSeq) geoList.innerHTML = `<li class="danger-text">${escapeHtml(e.message)}</li>`;
    }
  };
  container.querySelector('#geo-go')?.addEventListener('click', searchPlaces);
  geoInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); searchPlaces(); } });

  // „Wetter automatisch füllen" wirkt sofort
  container.querySelector('#auto-fill')?.addEventListener('change', async (e) => {
    try { await saveSettings({ weather_auto_fill: e.target.checked }); toastOk(t('settings.saved')); }
    catch (err) { toastErr(err.message); }
  });

  // Open-Meteo sync
  const syncBtn = container.querySelector('#btn-sync');
  syncBtn.addEventListener('click', guardSubmit(syncBtn, async () => {
    try {
      // Standort zuerst speichern — der Server gleicht für ihn ab
      if (!await saveLocation({ quiet: true })) return;
      // v2.8.0 — ohne Zeitraum ab der ersten Ablesung; „reload" ersetzt auch
      // Werte von vor v2.8.0 durch Archivwerte
      const reload = container.querySelector('#sync-reload')?.checked;
      const result = await api.syncOpenMeteo(reload ? { reload: 1 } : {});
      toastOk(t('temperatures.syncToast', { imported: result.imported || 0, archive: result.archive_rows || 0, forecast: result.forecast_rows || 0 }));
      if (result.archive_error)  toastErr(t('temperatures.archiveError', { err: result.archive_error }));
      if (result.forecast_error) toastErr(t('temperatures.forecastError', { err: result.forecast_error }));
      const cn = result.climate_normal;
      if (cn?.status === 'fetched' && cn.period) {
        toastOk(t('temperatures.climateFetched', { from: String(cn.period.from).slice(0, 4), to: String(cn.period.to).slice(0, 4) }));
      } else if (cn?.status === 'failed') {
        toastErr(t('temperatures.climateFailed'));
      }
      render(container);
    } catch (e) { toastErr(e.message); }
  }));

  const chart = renderMonthlyChart(days, container);

  // v2.15.0 — ohne window-Global; die Chart-Schicht räumt beim Seitenwechsel ab
  return () => { chart?.destroy(); };
}

async function importCsv(file, container) {
  try {
    const text = await file.text();
    const res = await api.importTempCsv(text);
    toastOk(t('temperatures.importToast', { imported: res.imported || 0, skipped: res.skipped || 0 }));
    render(container);
  } catch (e) { toastErr(e.message); }
}

function renderMonthlyChart(days, container = document) {
  const canvas = container.querySelector('#temp-chart');
  if (!canvas) return null;

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

  // v2.15.0 — Beschriftungen übersetzt (bis v2.14 fest „Max/ø/Min“), Farben
  // aus den Theme-Token (danger/info), die dem Theme-Wechsel folgen
  const chart = makeChart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: t('temperatures.chart.max'), data: maxes, borderColor: tokenColor('danger'), backgroundColor: tokenColor('danger', 0.1), tension: 0.25 },
        { label: t('temperatures.chart.avg'), data: avgs,  borderColor: tokenColor('text1'),  backgroundColor: tokenColor('text1', 0.1),  tension: 0.25 },
        { label: t('temperatures.chart.min'), data: mins,  borderColor: tokenColor('info'),   backgroundColor: tokenColor('info', 0.1),   tension: 0.25 },
      ],
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: '°C' } } } },
  }, { label: months.length
    ? t('temperatures.chart.altSpan', { from: labels[0], to: labels[labels.length - 1] })
    : t('temperatures.chart.alt') });

  // v2.15.0 (Review FE-20) — die Monatswerte als Tabelle zum Aufklappen
  const extra = container.querySelector('[data-role="temp-chart-data"]');
  if (extra) {
    const c = (v) => (v == null ? null : fmt.num(v, 1));
    extra.innerHTML = chartTableHtml({
      caption: t('temperatures.chart.alt'),
      columns: [t('utility.monthlyTable.colMonth'), `${t('temperatures.chart.min')} (°C)`, `${t('temperatures.chart.avg')} (°C)`, `${t('temperatures.chart.max')} (°C)`],
      rows: months.map((m, i) => [labels[i], c(mins[i]), c(avgs[i]), c(maxes[i])]),
    });
  }
  return chart;
}
