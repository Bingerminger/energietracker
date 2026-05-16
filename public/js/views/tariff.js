// =====================================================================
// Energietracker v1.3.0 — Tarifvergleich (echt vs. Schattenverträge)
// =====================================================================

import { api } from '../api.js';
import { getUtilities, getSettings } from '../state.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal } from '../components/modal.js';

let sel = { utility: null, meterId: null, year: null };

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade…</div>';
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
    container.innerHTML = `<div class="banner banner--info">Keine vergleichbaren Verbrauchsarten aktiv. Tarifvergleich gilt für vertragsbasierte Arten (Gas, Strom, Fernwärme).</div>`;
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
    <div class="view-head"><h2>Tarifvergleich</h2>
      <p class="muted">Was hättest du mit einem anderen Tarif bezahlt? Echte Verträge + hypothetische Schattenverträge auf den tatsächlichen Verbrauch gerechnet.</p>
    </div>
    <div class="toolbar">
      <label>Verbrauchsart
        <select id="t-util">${usable.map(u =>
          `<option value="${u.key}" ${u.key === sel.utility ? 'selected' : ''}>${esc(u.label)}</option>`).join('')}</select>
      </label>
      <label>Zähler
        <select id="t-meter">${meters.map(m =>
          `<option value="${m.id}" ${m.id === sel.meterId ? 'selected' : ''}>${esc(m.name || m.id)}</option>`).join('')}</select>
      </label>
      <label>Jahr
        <select id="t-year"><option value="">Gesamter Zeitraum</option>${yearOpts()}</select>
      </label>
      <button class="btn btn--ghost" id="t-addshadow">+ Schattenvertrag</button>
    </div>
    <div id="t-result"><div class="loading">Lade Vergleich…</div></div>
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
  if (!box || !sel.meterId) { if (box) box.innerHTML = '<div class="banner banner--info">Kein Zähler vorhanden.</div>'; return; }
  box.innerHTML = '<div class="loading">Lade Vergleich…</div>';
  let data;
  try {
    data = await api.tariffComparison(sel.utility, sel.meterId, sel.year);
  } catch (e) {
    box.innerHTML = `<div class="banner banner--error">${esc(e.message || e)}</div>`;
    return;
  }
  if (!data.supported) {
    box.innerHTML = `<div class="banner banner--info">${esc(data.note || 'Nicht unterstützt.')}</div>`;
    return;
  }
  if (!data.rows || data.rows.length === 0) {
    box.innerHTML = `<div class="banner banner--info">${esc(data.note || 'Keine Verträge zum Vergleich.')}</div>`;
    return;
  }

  const fmt = v => v == null ? '–' : v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  const rows = data.rows.map(r => {
    const vs = r.vs_real_eur;
    const vsCls = vs == null ? '' : (vs < 0 ? 'pos' : (vs > 0 ? 'neg' : ''));
    const vsStr = vs == null ? '–' : (vs > 0 ? '+' : '') + vs.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    return `<tr class="${r.is_shadow ? 'row-shadow' : ''}">
      <td>${r.is_shadow ? '<span class="badge badge--info">Schatten</span> ' : ''}${esc(r.label)}</td>
      <td>${r.consumption.toLocaleString('de-DE')} kWh</td>
      <td>${fmt(r.total_eur)}</td>
      <td class="delta ${vsCls}">${vsStr}</td>
    </tr>`;
  }).join('');

  box.innerHTML = `
    <p class="muted small">Zeitraum: ${esc(data.period.label)} ${data.period.from ? `(${data.period.from} – ${data.period.to})` : ''}</p>
    <table class="data-table">
      <thead><tr><th>Tarif</th><th>Verbrauch</th><th>Kosten</th><th>vs. real bezahlt</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
    <p class="muted small">Negative Differenz (grün) = günstiger als der tatsächlich abgerechnete Vertrag.</p>
  `;
}

function openShadowForm(container) {
  openModal({
    title: 'Schattenvertrag anlegen',
    body: `
      <p class="muted small">Ein hypothetischer Tarif — fließt NICHT in Saldo/Prognose ein, nur in diesen Vergleich.</p>
      <div class="form-grid">
        <label>Bezeichnung<input type="text" id="s-label" placeholder="z. B. Discounter-Angebot"></label>
        <label>Anbieter<input type="text" id="s-prov" placeholder="optional"></label>
        <label>Gültig ab<input type="date" id="s-start" value="${new Date().getFullYear()}-01-01"></label>
        <label>Arbeitspreis (ct/kWh)<input type="number" step="0.01" id="s-wp" placeholder="z. B. 8.50"></label>
        <label>Grundpreis (€/Monat)<input type="number" step="0.01" id="s-bp" placeholder="z. B. 12.00"></label>
      </div>`,
    footer: `
      <button class="btn btn--ghost" data-act="cancel">Abbrechen</button>
      <button class="btn btn--primary" data-act="save">Anlegen</button>`,
    onMount: ({ bodyEl, modalEl, close }) => {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="save"]').addEventListener('click', async () => {
        const start = bodyEl.querySelector('#s-start').value;
        const wp = parseFloat(bodyEl.querySelector('#s-wp').value);
        const bp = parseFloat(bodyEl.querySelector('#s-bp').value);
        const label = bodyEl.querySelector('#s-label').value.trim();
        if (!label || !start || isNaN(wp)) { toastErr('Bezeichnung, Datum und Arbeitspreis sind Pflicht'); return; }
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
          toastOk('Schattenvertrag angelegt');
          close(null);
          await loadResult(container);
        } catch (e) { toastErr('Fehler: ' + (e.message || e)); }
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
