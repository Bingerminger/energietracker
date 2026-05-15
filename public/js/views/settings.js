// =====================================================================
// Energietracker v1.1.0 — Settings view
//   - configurable values, grouped into professional setting cards
//   - CSV export (F-07), JSON backup/restore, v0.9.0 migration
//   - system diagnostics
// =====================================================================

import { api } from '../api.js';
import { invalidateSettings } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { confirmModal, openModal } from '../components/modal.js';

// Each group renders as a settings card. `hint` is an optional explanatory
// line under the card title; each field may carry its own `hint` too.
const GROUPS = [
  {
    title: 'Physikalische Konstanten',
    icon: '🔬',
    hint: 'Umrechnung und Heizgradtag-Basis — wirken auf alle Auswertungen.',
    fields: [
      { key: 'gas_conversion_factor', label: 'Gas-Umrechnungsfaktor', unit: 'kWh/m³', step: '0.1',
        hint: 'Brennwert × Zustandszahl laut Gasabrechnung.' },
      { key: 'hdd_base_temp', label: 'HGT-Basistemperatur', unit: '°C', step: '0.5',
        hint: 'Heizgrenze — Tage darunter zählen als Heiztage.' },
    ],
  },
  {
    title: 'CO₂-Emissionsfaktoren',
    icon: '🌍',
    hint: 'Gramm CO₂ je Verbrauchseinheit, für die Emissionsabschätzung.',
    fields: [
      { key: 'co2_gas',    label: 'CO₂ Gas',    unit: 'g/kWh', step: '1' },
      { key: 'co2_strom',  label: 'CO₂ Strom',  unit: 'g/kWh', step: '1' },
      { key: 'co2_wasser', label: 'CO₂ Wasser', unit: 'g/m³',  step: '1' },
    ],
  },
  {
    title: 'Abrechnungszyklus',
    icon: '📅',
    hint: 'Stichtag der Jahresabrechnung je Verbrauchsart (Format MM-TT). '
        + 'Bestimmt, bis wann der Saldo offener Verträge projiziert wird; '
        + 'Verträge mit gepflegtem Ende nutzen weiterhin dieses Ende.',
    fields: [
      { key: 'billing_cycle_anchor_gas',    label: 'Stichtag Gas',    type: 'text', placeholder: '01-01' },
      { key: 'billing_cycle_anchor_strom',  label: 'Stichtag Strom',  type: 'text', placeholder: '01-01' },
      { key: 'billing_cycle_anchor_wasser', label: 'Stichtag Wasser', type: 'text', placeholder: '01-01' },
    ],
  },
  {
    title: 'Vertragserinnerungen',
    icon: '🔔',
    hint: 'Tage vor Vertragsende, ab denen die Vertragsübersicht warnt. '
        + 'Drei Stufen — frühe Vorwarnung bis letzter Tag.',
    fields: [
      { key: 'contract_remind_days_1', label: 'Stufe 1 — Vorwarnung',  unit: 'Tage', step: '1' },
      { key: 'contract_remind_days_2', label: 'Stufe 2 — Erinnerung',  unit: 'Tage', step: '1' },
      { key: 'contract_remind_days_3', label: 'Stufe 3 — dringend',    unit: 'Tage', step: '1' },
    ],
  },
  {
    title: 'Wasser-Referenzwerte',
    icon: '💧',
    hint: 'Haushaltsgröße, Pro-Kopf-Referenz und die Bänder des Spar-Index.',
    fields: [
      { key: 'wasser_personen_anzahl',   label: 'Personen im Haushalt',     step: '1' },
      { key: 'wasser_personen_referenz', label: 'Referenz',  unit: 'L/Person/Tag', step: '1' },
      { key: 'wasser_sparindex_gut',     label: 'Spar-Index — gut bis',     step: '1',
        hint: 'Index ≤ diesem Wert gilt als unauffällig.' },
      { key: 'wasser_sparindex_warnung', label: 'Spar-Index — Warnung ab',  step: '1',
        hint: 'Index ≥ diesem Wert zeigt Sparpotenzial an.' },
    ],
  },
  {
    title: 'Regression & Prognose',
    icon: '📈',
    hint: 'Filter für die Regressionsbasis und Parameter des Prognosemodells.',
    fields: [
      { key: 'min_days_period',        label: 'Min. Tage pro Periode',    step: '1' },
      { key: 'min_hdd_regression',     label: 'Min. HGT für Regression',  step: '0.5' },
      { key: 'blend_max',              label: 'Max. Blend-Gewicht',       step: '0.05' },
      { key: 'forecast_months',        label: 'Prognose-Horizont', unit: 'Monate', step: '1' },
      { key: 'min_temp_days_forecast', label: 'Min. Temp-Tage Prognose',  step: '1' },
      { key: 'forecast_model',         label: 'Standardmodell', type: 'select',
        options: ['linear', 'polynomial', 'robust', 'segmented'] },
      { key: 'anomaly_threshold',      label: 'Anomalie-Schwelle', unit: 'σ', step: '0.1' },
    ],
  },
  {
    title: 'Dashboard',
    icon: '🏠',
    hint: 'Darstellungsumfang und Schwellen der Übersicht.',
    fields: [
      { key: 'dashboard_months',         label: 'Monate auf Dashboard', step: '1' },
      { key: 'alert_days_since_reading', label: 'Warnung nach', unit: 'Tagen ohne Ablesung', step: '1' },
    ],
  },
  {
    title: 'Standort (Open-Meteo)',
    icon: '📍',
    hint: 'Koordinaten für den Wetter-Sync der Temperaturreihe.',
    fields: [
      { key: 'location_name',     label: 'Ortsname',     type: 'text' },
      { key: 'latitude',          label: 'Breitengrad',  step: '0.0001' },
      { key: 'longitude',         label: 'Längengrad',   step: '0.0001' },
      { key: 'weather_auto_fill', label: 'Wetter automatisch füllen', type: 'bool' },
    ],
  },
];

export async function render(container) {
  container.innerHTML = '<div class="loading">Lade…</div>';
  const [settings, diag] = await Promise.all([
    api.settings(),
    api.diagnostics().catch(() => null),
  ]);

  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">⚙️ Einstellungen</h1>
        <div class="view-header__subtitle">Konfiguration · Datenexport · Backup &amp; Wiederherstellung</div>
      </div>
      <div class="view-header__actions">
        <button type="button" class="btn btn--primary" id="btn-save">Einstellungen speichern</button>
      </div>
    </div>

    <div class="settings-grid">
      ${GROUPS.map(g => renderGroup(g, settings)).join('')}
    </div>

    <div class="form-actions" style="margin-top: var(--sp-4)">
      <button type="button" class="btn btn--primary" id="btn-save-2">Einstellungen speichern</button>
    </div>

    <div class="card">
      <h3 class="card__title">📤 Datenexport (CSV)</h3>
      <p class="muted" style="margin-bottom: var(--sp-4)">
        Tabellarischer Export für Excel, LibreOffice oder Google Sheets —
        Semikolon-getrennt, UTF-8 mit BOM, deutsches Dezimalkomma. Ergänzt das
        vollständige JSON-Backup weiter unten.
      </p>
      <div class="export-grid">
        <div class="export-tile">
          <div class="export-tile__label">Monatsübersicht</div>
          <div class="export-tile__hint">Verbrauch, Kosten, Abschlag und Saldo je Monat.</div>
          <div class="export-tile__actions">
            <a class="btn btn--sm btn-gas"    href="${api.exportMonthlyCsvUrl('gas')}"    download>Gas</a>
            <a class="btn btn--sm btn-strom"  href="${api.exportMonthlyCsvUrl('strom')}"  download>Strom</a>
            <a class="btn btn--sm btn-wasser" href="${api.exportMonthlyCsvUrl('wasser')}" download>Wasser</a>
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">Zählerstände</div>
          <div class="export-tile__hint">Alle Rohablesungen, eine Zeile je Ablesung.</div>
          <div class="export-tile__actions">
            <a class="btn btn--sm btn-gas"    href="${api.exportReadingsCsvUrl('gas')}"    download>Gas</a>
            <a class="btn btn--sm btn-strom"  href="${api.exportReadingsCsvUrl('strom')}"  download>Strom</a>
            <a class="btn btn--sm btn-wasser" href="${api.exportReadingsCsvUrl('wasser')}" download>Wasser</a>
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">Temperaturreihe</div>
          <div class="export-tile__hint">Tageswerte min / ø / max der gesamten Reihe.</div>
          <div class="export-tile__actions">
            <a class="btn btn--sm" href="${api.exportTemperaturesCsvUrl()}" download>Temperaturen exportieren</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3 class="card__title">💾 Backup &amp; Wiederherstellung</h3>
      <p class="muted" style="margin-bottom: var(--sp-3)">
        Vollständiges JSON-Backup (Format v3.0) — enthält alle Verbrauchsarten,
        Zähler, Verträge, Temperaturen und Einstellungen, wieder importierbar.
      </p>
      <div class="section-actions">
        <button class="btn" id="btn-export">JSON-Backup herunterladen</button>
        <button class="btn" id="btn-snapshot">Snapshot speichern</button>
        <button class="btn btn--ghost" id="btn-import">Backup importieren…</button>
        <input type="file" id="import-file" accept=".json,application/json" style="display:none">
      </div>

      <hr class="settings-rule">

      <h4 class="settings-subhead">📦 Migration aus v0.9.0</h4>
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

  const save = async () => {
    const payload = collectSettings(container);
    try {
      await api.updateSettings(payload);
      invalidateSettings();
      toastOk('Einstellungen gespeichert');
    } catch (e) { toastErr(e.message); }
  };
  container.querySelector('#btn-save').addEventListener('click', save);
  container.querySelector('#btn-save-2').addEventListener('click', save);

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
    try { const r = await api.snapshotBackup(); toastOk(`Snapshot: ${r.file || r.path || 'ok'}`); }
    catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-import').addEventListener('click',
    () => container.querySelector('#import-file').click());
  container.querySelector('#import-file').addEventListener('change', async (e) => {
    const f = e.target.files[0]; if (!f) return;
    const ok = await confirmModal({
      title: 'Backup importieren?',
      message: 'Achtung: bestehende Daten werden überschrieben. Vorher Backup erstellen!',
      confirmLabel: 'Importieren', danger: true,
    });
    if (!ok) { e.target.value = ''; return; }
    try {
      const text = await f.text();
      const data = JSON.parse(text);
      await api.importBackup(data);
      toastOk('Backup importiert');
      invalidateSettings();
      render(container);
    } catch (e2) { toastErr(e2.message); }
    finally { e.target.value = ''; }
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
    <div class="card settings-card">
      <h3 class="card__title">${g.icon ? g.icon + ' ' : ''}${escapeHtml(g.title)}</h3>
      ${g.hint ? `<p class="settings-card__hint">${escapeHtml(g.hint)}</p>` : ''}
      <div class="settings-fields">
        ${g.fields.map(f => renderField(f, settings[f.key])).join('')}
      </div>
    </div>
  `;
}

function renderField(f, value) {
  const hint = f.hint ? `<span class="settings-field__hint">${escapeHtml(f.hint)}</span>` : '';
  const labelHtml = `<label>${escapeHtml(f.label)}${f.unit ? ` <span class="settings-field__unit">${escapeHtml(f.unit)}</span>` : ''}</label>`;

  if (f.type === 'select') {
    return `<div class="field settings-field">
      ${labelHtml}
      <select class="select" data-key="${f.key}">
        ${f.options.map(o => `<option value="${o}" ${o === value ? 'selected' : ''}>${o}</option>`).join('')}
      </select>
      ${hint}
    </div>`;
  }
  if (f.type === 'bool') {
    return `<div class="field settings-field">
      ${labelHtml}
      <label class="settings-field__check">
        <input type="checkbox" data-key="${f.key}" data-type="bool" ${value ? 'checked' : ''}> aktiv
      </label>
      ${hint}
    </div>`;
  }
  if (f.type === 'text') {
    return `<div class="field settings-field">
      ${labelHtml}
      <input class="input input--text" type="text" data-key="${f.key}"
             value="${escapeHtml(value ?? '')}" ${f.placeholder ? `placeholder="${escapeHtml(f.placeholder)}"` : ''}>
      ${hint}
    </div>`;
  }
  return `<div class="field settings-field">
    ${labelHtml}
    <input class="input" type="number" step="${f.step || '1'}" data-key="${f.key}" value="${value ?? ''}">
    ${hint}
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
    <div class="card">
      <h3 class="card__title">🩺 System-Diagnose</h3>
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
        Daten wurden auf das aktuelle Schema übersetzt (noch nicht geschrieben).
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
            <strong>Ersetzen</strong> — vorhandene Daten werden gelöscht und durch das Backup ersetzt.
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
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="apply"]')?.addEventListener('click', async () => {
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
