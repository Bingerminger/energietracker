// =====================================================================
// Settings view — all configurable values, backup/restore, diagnostics.
// =====================================================================

import { api } from '../api.js';
import { invalidateSettings } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { confirmModal, openModal } from '../components/modal.js';

const GROUPS = [
  {
    title: 'Physikalische Konstanten',
    fields: [
      { key: 'gas_conversion_factor', label: 'Gas-Umrechnungsfaktor (kWh/m³)', step: '0.1' },
      { key: 'hdd_base_temp',         label: 'HGT-Basistemperatur (°C)',       step: '0.5' },
    ],
  },
  {
    title: 'CO₂-Emissionsfaktoren',
    fields: [
      { key: 'co2_gas',   label: 'CO₂ Gas (g/kWh)',  step: '1' },
      { key: 'co2_strom', label: 'CO₂ Strom (g/kWh)',step: '1' },
      { key: 'co2_wasser',label: 'CO₂ Wasser (g/m³)',step: '1' },
    ],
  },
  {
    title: 'Wasser-Referenzwerte',
    fields: [
      { key: 'wasser_personen_anzahl',  label: 'Personen im Haushalt', step: '1' },
      { key: 'wasser_personen_referenz',label: 'Referenz (L/Person/Tag)', step: '1' },
    ],
  },
  {
    title: 'Regression / Prognose',
    fields: [
      { key: 'min_days_period',     label: 'Min. Tage pro Periode',  step: '1' },
      { key: 'min_hdd_regression',  label: 'Min. HGT für Regression', step: '0.5' },
      { key: 'blend_max',           label: 'Max. Blend-Gewicht',     step: '0.05' },
      { key: 'forecast_months',     label: 'Prognose-Monate',        step: '1' },
      { key: 'min_temp_days_forecast', label: 'Min. Temp-Tage Prognose', step: '1' },
      { key: 'forecast_model',      label: 'Standardmodell',         type: 'select', options: ['linear','polynomial','robust','segmented'] },
      { key: 'anomaly_threshold',   label: 'Anomalie-Schwelle (σ)',  step: '0.1' },
    ],
  },
  {
    title: 'Dashboard',
    fields: [
      { key: 'dashboard_months',         label: 'Monate auf Dashboard',       step: '1' },
      { key: 'alert_days_since_reading', label: 'Warnung nach (Tagen ohne Ablesung)', step: '1' },
    ],
  },
  {
    title: 'Standort (Open-Meteo)',
    fields: [
      { key: 'location_name', label: 'Ortsname',   type: 'text' },
      { key: 'latitude',      label: 'Breitengrad',step: '0.0001' },
      { key: 'longitude',     label: 'Längengrad', step: '0.0001' },
      { key: 'weather_auto_fill', label: 'Wetter automatisch füllen', type: 'bool' },
    ],
  },
];

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade…</div>';
  const [settings, diag] = await Promise.all([api.settings(), api.diagnostics().catch(() => null)]);

  container.innerHTML = `
    <div class="section-head"><h1>⚙️ Einstellungen</h1></div>

    <form id="settings-form">
      ${GROUPS.map(g => renderGroup(g, settings)).join('')}
      <div class="form-actions">
        <button type="button" class="btn btn--primary" id="btn-save">Speichern</button>
      </div>
    </form>

    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">Backup &amp; Restore</h3>
      <div class="section-actions">
        <button class="btn" id="btn-export">JSON-Backup herunterladen</button>
        <button class="btn" id="btn-snapshot">Snapshot speichern</button>
        <button class="btn btn--ghost" id="btn-import">Backup importieren…</button>
        <input type="file" id="import-file" accept=".json,application/json" style="display:none">
      </div>
      <p class="muted" style="margin-top: var(--sp-2)">Backup-Format v3.0 — enthält alle Verbrauchsarten, Zähler, Verträge, Temperaturen und Einstellungen.</p>

      <hr style="border:none;border-top:1px solid var(--border-1);margin: var(--sp-4) 0">

      <h4 style="margin:0 0 8px;font-size:13px;color:var(--text-1)">📦 Migration aus v0.9.0</h4>
      <p class="muted" style="font-size:12px;margin: 0 0 var(--sp-3)">
        Lade ein altes v0.9.0-Backup (JSON mit <code>version: "2.1"</code>) hoch.
        Das Backup wird vor dem Schreiben analysiert; danach entscheidest du
        zwischen <strong>Ersetzen</strong> (alles überschreiben) oder
        <strong>Zusammenführen</strong> (nur neue IDs hinzufügen).
        In beiden Fällen wird vorher automatisch ein Sicherheits-Snapshot
        der aktuellen Daten angelegt.
      </p>
      <div class="section-actions">
        <button class="btn btn--ghost" id="btn-migrate-v09">v0.9.0-Backup analysieren…</button>
        <input type="file" id="migrate-file" accept=".json,application/json" style="display:none">
      </div>
    </div>

    ${diag ? renderDiagnostics(diag) : ''}
  `;

  container.querySelector('#btn-save').addEventListener('click', async () => {
    const payload = collectSettings(container);
    try {
      await api.updateSettings(payload);
      invalidateSettings();
      toastOk('Einstellungen gespeichert');
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-export').addEventListener('click', async () => {
    try {
      const data = await api.exportBackup();
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = `energietracker-backup-${new Date().toISOString().slice(0,10)}.json`;
      a.click(); URL.revokeObjectURL(url);
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-snapshot').addEventListener('click', async () => {
    try { const r = await api.snapshotBackup(); toastOk(`Snapshot: ${r.path || 'ok'}`); }
    catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-import').addEventListener('click', () => container.querySelector('#import-file').click());
  container.querySelector('#import-file').addEventListener('change', async (e) => {
    const f = e.target.files[0]; if (!f) return;
    const ok = await confirmModal({
      title: 'Backup importieren?',
      message: 'Achtung: bestehende Daten werden überschrieben. Vorher Backup erstellen!',
      confirmLabel: 'Importieren', danger: true,
    });
    if (!ok) return;
    try {
      const text = await f.text();
      const data = JSON.parse(text);
      await api.importBackup(data);
      toastOk('Backup importiert');
      invalidateSettings();
      render(container);
    } catch (e2) { toastErr(e2.message); }
  });

  // ── Migration aus v0.9.0 ──
  container.querySelector('#btn-migrate-v09').addEventListener('click', () => {
    container.querySelector('#migrate-file').click();
  });
  container.querySelector('#migrate-file').addEventListener('change', async (e) => {
    const f = e.target.files[0]; if (!f) return;
    try {
      const text = await f.text();
      const backup = JSON.parse(text);
      const previewResult = await api.migrationV09Preview(backup);
      openMigrationDialog(previewResult, () => render(container));
    } catch (err) {
      toastErr('Fehler beim Lesen: ' + err.message);
    } finally {
      e.target.value = '';
    }
  });
}

function renderGroup(g, settings) {
  return `
    <div class="card" style="margin-bottom: var(--sp-4)">
      <h3 class="card__title">${escapeHtml(g.title)}</h3>
      <div class="form-row">
        ${g.fields.map(f => renderField(f, settings[f.key])).join('')}
      </div>
    </div>
  `;
}

function renderField(f, value) {
  if (f.type === 'select') {
    return `<div class="field">
      <label>${escapeHtml(f.label)}</label>
      <select class="select" data-key="${f.key}">
        ${f.options.map(o => `<option value="${o}" ${o === value ? 'selected' : ''}>${o}</option>`).join('')}
      </select>
    </div>`;
  }
  if (f.type === 'bool') {
    return `<div class="field">
      <label>${escapeHtml(f.label)}</label>
      <label><input type="checkbox" data-key="${f.key}" data-type="bool" ${value ? 'checked' : ''}> aktiv</label>
    </div>`;
  }
  if (f.type === 'text') {
    return `<div class="field">
      <label>${escapeHtml(f.label)}</label>
      <input class="input input--text" type="text" data-key="${f.key}" value="${escapeHtml(value ?? '')}">
    </div>`;
  }
  return `<div class="field">
    <label>${escapeHtml(f.label)}</label>
    <input class="input" type="number" step="${f.step || '1'}" data-key="${f.key}" value="${value ?? ''}">
  </div>`;
}

function collectSettings(container) {
  const out = {};
  container.querySelectorAll('[data-key]').forEach(el => {
    const key = el.getAttribute('data-key');
    if (el.tagName === 'SELECT') out[key] = el.value;
    else if (el.getAttribute('data-type') === 'bool') out[key] = el.checked;
    else if (el.type === 'number') {
      const v = el.value;
      out[key] = v === '' ? null : Number(v);
    } else out[key] = el.value;
  });
  return out;
}

function renderDiagnostics(d) {
  return `
    <div class="card" style="margin-top: var(--sp-5)">
      <h3 class="card__title">System-Diagnose</h3>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Schlüssel</th><th>Wert</th></tr></thead>
        <tbody>
          ${Object.entries(d).map(([k, v]) => `
            <tr>
              <td><code class="mono">${escapeHtml(k)}</code></td>
              <td><code class="mono">${escapeHtml(typeof v === 'object' ? JSON.stringify(v) : String(v))}</code></td>
            </tr>`).join('')}
        </tbody>
      </table></div>
    </div>
  `;
}

// ── Migrations-Dialog v0.9.0 ───────────────────────────────────────
function openMigrationDialog(previewResult, onDone) {
  const d = previewResult;
  const r = d.report;
  const candidates = r.device_replacement_candidates || [];

  const body = `
    <div style="font-size:13px">
      <p>
        Erkanntes <strong>v0.9.0-Backup-Format ${escapeHtml(d.legacy_version)}</strong>.
        Daten wurden auf das v1.0.2-Schema übersetzt (noch nicht geschrieben).
      </p>

      <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:14px 0">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:8px">Was importiert würde</div>
        <table class="table table--compact" style="margin:0">
          <thead><tr><th></th><th class="num">Gas</th><th class="num">Strom</th><th class="num">Wasser</th></tr></thead>
          <tbody>
            <tr><td>Ablesungen</td><td class="num">${r.readings.gas}</td><td class="num">${r.readings.strom}</td><td class="num">${r.readings.wasser}</td></tr>
            <tr><td>Verträge</td><td class="num">${r.contracts.gas}</td><td class="num">${r.contracts.strom}</td><td class="num">${r.contracts.wasser}</td></tr>
          </tbody>
        </table>
        <div style="margin-top:8px;font-size:12px;color:var(--text-2)">
          ${r.temperatures} Temperaturtage · ${r.settings} Settings-Keys
        </div>
      </div>

      ${r.warnings.length ? `
        <div class="banner banner--warning" style="font-size:12px">
          ${r.warnings.map(w => `<div>· ${escapeHtml(w)}</div>`).join('')}
        </div>
      ` : ''}

      ${candidates.length ? `
        <details style="margin:12px 0">
          <summary style="cursor:pointer;color:var(--text-2);font-size:12px">
            <strong>${candidates.length} mögliche Zählerwechsel erkannt</strong>
            — nach dem Import als Device-Tausch nachpflegen?
          </summary>
          <table class="table table--compact" style="margin-top:8px">
            <thead><tr><th>Utility</th><th>Datum</th><th class="num">Stand</th><th>Kommentar</th></tr></thead>
            <tbody>
              ${candidates.map(c => `
                <tr>
                  <td>${escapeHtml(c.utility)}</td>
                  <td class="num">${escapeHtml(c.date)}</td>
                  <td class="num">${escapeHtml(String(c.counter))}</td>
                  <td style="font-size:11px;color:var(--text-2)">${escapeHtml(c.comment || '–')}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
          <p class="muted" style="font-size:11px;margin-top:8px">
            Nach dem Import unter <em>Zähler &amp; Geräte → Zählertausch</em> (oder API:
            <code>POST /api/utility/{u}/meters/{id}/replace-device</code>) nachmodellieren.
          </p>
        </details>
      ` : ''}

      <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:14px 0">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:8px">Wie schreiben?</div>
        <label style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;cursor:pointer;text-transform:none;letter-spacing:0">
          <input type="radio" name="migrate-mode" value="replace" checked>
          <span>
            <strong>Ersetzen</strong> — vorhandene v1.0.2-Daten werden gelöscht und durch das Backup ersetzt.
            <em>Vorher wird automatisch ein Sicherheits-Snapshot der aktuellen Daten unter <code>data/backups/</code> abgelegt.</em>
          </span>
        </label>
        <label style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;cursor:pointer;text-transform:none;letter-spacing:0">
          <input type="radio" name="migrate-mode" value="merge">
          <span>
            <strong>Zusammenführen</strong> — nur Ablesungen/Verträge mit neuer ID werden ergänzt.
            Bestehende Daten bleiben. Temperaturtage werden nur ergänzt, wenn das Datum noch nicht existiert.
          </span>
        </label>
      </div>
    </div>
  `;

  const footer = `
    <button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button>
    <button type="button" class="btn btn--primary" data-act="apply">Importieren</button>
  `;

  openModal({
    title: 'Migration aus v0.9.0',
    body, footer,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="apply"]').addEventListener('click', async () => {
        const mode = modalEl.querySelector('input[name="migrate-mode"]:checked').value;
        try {
          const result = await api.migrationV09Import(d.translated, mode);
          const w = result.written;
          const total =
            (w.gas?.readings || 0) + (w.strom?.readings || 0) + (w.wasser?.readings || 0);
          toastOk(`Migration OK · ${total} Ablesungen geschrieben · Snapshot ${result.snapshot}`);
          close(true);
          if (onDone) onDone();
        } catch (err) {
          toastErr('Import fehlgeschlagen: ' + err.message);
        }
      });
    },
  });
}
