// =====================================================================
// Energietracker v1.2.0 — Settings view
//   - configurable values, grouped into professional setting cards
//   - CSV export (F-07), JSON backup/restore, v0.9.0 migration
//   - system diagnostics
// =====================================================================

import { api } from '../api.js';
import { invalidateSettings, invalidateUtilities } from '../state.js';
import { fmt, escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { confirmModal, openModal } from '../components/modal.js';
import { t, getLocale, initI18n, getLanguages } from '../lib/i18n.js';
import { buildSidebar } from '../lib/sidebar.js';

// Each group renders as a settings card. `hint` is an optional explanatory
// line under the card title; each field may carry its own `hint` too.
// Gruppen-/Feld-Titel, -Hints und -Labels werden zur Render-Zeit über t()
// aufgelöst: settings.group.<gkey>.{title,hint} und settings.field.<key>.{label,hint}.
// Einheiten mit deutschen Wörtern liegen als unitKey vor; Symbol-Einheiten
// (kWh/m³, °C, σ …) bleiben literal.
const GROUPS = [
  { gkey: 'physical', icon: '🔬', fields: [
    { key: 'gas_conversion_factor', unit: 'kWh/m³', step: '0.1' },
    { key: 'hdd_base_temp', unit: '°C', step: '0.5' },
  ]},
  { gkey: 'co2', icon: '🌍', fields: [
    { key: 'co2_gas',    unit: 'g/kWh', step: '1' },
    { key: 'co2_strom',  unit: 'g/kWh', step: '1' },
    { key: 'co2_wasser', unit: 'g/m³',  step: '1' },
    { key: 'co2_fernwaerme', unit: 'g/kWh', step: '1' },
    { key: 'co2_heizoel',    unit: 'g/L',   step: '1' },
    { key: 'co2_pellets',    unit: 'g/kg',  step: '1' },
  ]},
  { gkey: 'billing', icon: '📅', fields: [
    { key: 'billing_cycle_anchor_gas',    type: 'datemd', placeholder: 'TT-MM (01-01)' },
    { key: 'billing_cycle_anchor_strom',  type: 'datemd', placeholder: 'TT-MM (01-01)' },
    { key: 'billing_cycle_anchor_wasser', type: 'datemd', placeholder: 'TT-MM (01-01)' },
  ]},
  { gkey: 'contractReminders', icon: '🔔', fields: [
    { key: 'contract_remind_days_1', unitKey: 'settings.unit.days', step: '1' },
    { key: 'contract_remind_days_2', unitKey: 'settings.unit.days', step: '1' },
    { key: 'contract_remind_days_3', unitKey: 'settings.unit.days', step: '1' },
  ]},
  { gkey: 'water', icon: '💧', fields: [
    { key: 'wasser_personen_anzahl',   step: '1' },
    { key: 'wasser_personen_referenz', unitKey: 'settings.unit.lPerPersonDay', step: '1' },
    { key: 'wasser_sparindex_gut',     step: '1' },
    { key: 'wasser_sparindex_warnung', step: '1' },
  ]},
  { gkey: 'regression', icon: '📈', fields: [
    { key: 'min_days_period',        step: '1' },
    { key: 'min_hdd_regression',     step: '0.5' },
    { key: 'blend_max',              step: '0.05' },
    { key: 'forecast_months',        unitKey: 'settings.unit.months', step: '1' },
    { key: 'min_temp_days_forecast', step: '1' },
    { key: 'forecast_model',         type: 'select', options: ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'] },
    { key: 'segmented_split_mode',   type: 'select', options: ['auto', 'fixed'] },
    { key: 'segmented_fixed_split',  unit: 'HGT', step: '1' },
    { key: 'anomaly_threshold',      unit: 'σ', step: '0.1' },
  ]},
  { gkey: 'dashboard', icon: '🏠', fields: [
    { key: 'dashboard_months',         step: '1' },
    { key: 'alert_days_since_reading', unitKey: 'settings.unit.daysNoReading', step: '1' },
  ]},
  { gkey: 'building', icon: '🏢', fields: [
    { key: 'wohnflaeche_m2', unit: 'm²', step: '1' },
    { key: 'baujahr',        type: 'text', placeholder: 'z. B. 1998' },
    { key: 'gebaeudetyp',    type: 'select', options: ['efh', 'rh', 'mfh', 'whg'] },
  ]},
  { gkey: 'delivery', icon: '🛢️', fields: [
    { key: 'heizoel_kwh_per_l', unit: 'kWh/L', step: '0.1' },
    { key: 'pellets_kwh_per_kg', unit: 'kWh/kg', step: '0.1' },
    { key: 'delivery_baseload_share', step: '0.05' },
    { key: 'tank_warn_pct', unitKey: 'settings.unit.pctRemaining', step: '1' },
  ]},
  { gkey: 'remindersRec', icon: '📌', fields: [
    { key: 'reminder_warn_days_before', unitKey: 'settings.unit.daysBefore', step: '1' },
    { key: 'reminder_overdue_days',     unitKey: 'settings.unit.days', step: '1' },
    { key: 'recommendation_anomaly_sigma', unit: 'σ', step: '0.1' },
    { key: 'recommendation_trend_pct_year', unitKey: 'settings.unit.pctYear', step: '0.5' },
    { key: 'billing_cycle_anchor_fernwaerme', type: 'datemd', placeholder: 'TT-MM (01-01)' },
    { key: 'billing_cycle_anchor_heizoel',    type: 'datemd', placeholder: 'TT-MM (01-01)' },
    { key: 'billing_cycle_anchor_pellets',    type: 'datemd', placeholder: 'TT-MM (01-01)' },
  ]},
  { gkey: 'location', icon: '📍', fields: [
    { key: 'location_name',     type: 'text' },
    { key: 'latitude',          step: '0.0001' },
    { key: 'longitude',         step: '0.0001' },
    { key: 'weather_auto_fill', type: 'bool' },
  ]},
];

export async function render(container) {
  container.innerHTML = `<div class="loading">${t('settings.loading')}</div>`;
  const [settings, diag, utilities, authStatus] = await Promise.all([
    api.settings(),
    api.diagnostics().catch(() => null),
    api.listUtilities().catch(() => []),
    api.authStatus().catch(() => ({ enabled: false, created_at: null })),
  ]);

  // F1009 — Zähler je (nicht-Delivery-)Utility für die Alias-Verwaltung laden.
  const haUtilities = (utilities || []).filter(u => u.reading_kind !== 'delivery');
  const metersByUtility = {};
  await Promise.all(haUtilities.map(async u => {
    metersByUtility[u.key] = await api.meters(u.key).catch(() => []);
  }));

  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${t('settings.title')}</h1>
        <div class="view-header__subtitle">${t('settings.subtitle')}</div>
      </div>
      <div class="view-header__actions">
        <button type="button" class="btn btn--primary" id="btn-save">${t('settings.save')}</button>
      </div>
    </div>

    <div class="card settings-card">
      <h3 class="card__title">${t('settings.lang.title')}</h3>
      <p class="settings-card__hint">${t('settings.lang.hint')}</p>
      <div class="settings-fields">
        <div class="field settings-field">
          <label for="lang-select">${t('settings.lang.label')}</label>
          <select class="select" id="lang-select">
            ${Object.entries(getLanguages()).map(([code, name]) => `<option value="${code}" ${code === getLocale() ? 'selected' : ''}>${escapeHtml(name)}</option>`).join('')}
          </select>
        </div>
      </div>
    </div>

    <div class="settings-grid">
      ${GROUPS.map(g => renderGroup(g, settings)).join('')}
    </div>

    <div class="card settings-card">
      <h3 class="card__title">${t('settings.activeUtils.title')}</h3>
      <p class="settings-card__hint">${t('settings.activeUtils.hint')}</p>
      <div class="settings-fields" id="active-utils">
        ${(utilities || []).map(u => {
          const act = Array.isArray(settings.active_utilities) && settings.active_utilities.length
            ? settings.active_utilities.includes(u.key)
            : true;
          return `<label class="settings-field__check">
            <input type="checkbox" data-active-util="${u.key}" ${act ? 'checked' : ''}>
            ${u.icon ? u.icon + ' ' : ''}${escapeHtml(u.label)}
          </label>`;
        }).join('')}
      </div>
    </div>

    <div class="card">
      <h3 class="card__title">${t('settings.pdf.title')}</h3>
      <p class="muted" style="margin-bottom: var(--sp-3)">
        ${t('settings.pdf.hint')}
      </p>
      <div class="section-actions">
        <label>${t('settings.pdf.year')}
          <select class="select" id="pdf-year" style="margin-left: var(--sp-2)">
            ${pdfYearOpts()}
          </select>
        </label>
        <a class="btn btn--primary" id="pdf-dl" href="${api.yearlyReportUrl(new Date().getFullYear() - 1)}">
          ${t('settings.pdf.download')}
        </a>
      </div>
    </div>

    <div class="form-actions" style="margin-top: var(--sp-4)">
      <button type="button" class="btn btn--primary" id="btn-save-2">${t('settings.save')}</button>
    </div>

    <div class="card">
      <h3 class="card__title">${t('settings.export.title')}</h3>
      <p class="muted" style="margin-bottom: var(--sp-4)">
        ${t('settings.export.hint')}
      </p>
      <div class="export-grid">
        <div class="export-tile">
          <div class="export-tile__label">${t('settings.export.monthly')}</div>
          <div class="export-tile__hint">${t('settings.export.monthlyHint')}</div>
          <div class="export-tile__actions">
            ${(utilities || [])
              .filter(u => exportActive(settings, u.key))
              .map(u => `<a class="btn btn--sm btn-${u.key}" href="${api.exportMonthlyCsvUrl(u.key)}" download>${escapeHtml(u.label)}</a>`)
              .join('') || `<span class="muted" style="font-size:12px">${t('settings.export.noActive')}</span>`}
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">${t('settings.export.readings')}</div>
          <div class="export-tile__hint">${t('settings.export.readingsHint')}</div>
          <div class="export-tile__actions">
            ${(utilities || [])
              .filter(u => exportActive(settings, u.key))
              .map(u => {
                const isDelivery = u.reading_kind === 'delivery';
                const url = isDelivery ? api.exportDeliveriesCsvUrl(u.key) : api.exportReadingsCsvUrl(u.key);
                const tag = isDelivery ? t('settings.export.deliveriesTag') : '';
                return `<a class="btn btn--sm btn-${u.key}" href="${url}" download>${escapeHtml(u.label)}${tag}</a>`;
              })
              .join('') || `<span class="muted" style="font-size:12px">${t('settings.export.noActive')}</span>`}
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">${t('settings.export.temperatures')}</div>
          <div class="export-tile__hint">${t('settings.export.temperaturesHint')}</div>
          <div class="export-tile__actions">
            <a class="btn btn--sm" href="${api.exportTemperaturesCsvUrl()}" download>${t('settings.export.exportTemperatures')}</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3 class="card__title">${t('settings.backup.title')}</h3>
      <p class="muted" style="margin-bottom: var(--sp-3)">
        ${t('settings.backup.hint')}
      </p>
      <div class="section-actions">
        <button class="btn" id="btn-export">${t('settings.backup.download')}</button>
        <button class="btn" id="btn-snapshot">${t('settings.backup.snapshot')}</button>
        <button class="btn btn--ghost" id="btn-import">${t('settings.backup.import')}</button>
        <input type="file" id="import-file" accept=".json,application/json" style="display:none">
        <button class="btn btn--ghost" id="btn-demo">${t('settings.backup.demo')}</button>
      </div>
      <p class="settings-card__hint" style="margin-top:.5rem">
        ${t('settings.backup.demoHint')}
      </p>

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.backup.migrateSubhead')}</h4>
      <p class="muted" style="font-size:12px;margin: 0 0 var(--sp-3)">
        ${t('settings.backup.migrateHint')}
      </p>
      <div class="section-actions">
        <button class="btn btn--ghost" id="btn-migrate-v09">${t('settings.backup.migrateBtn')}</button>
        <input type="file" id="migrate-file" accept=".json,application/json" style="display:none">
      </div>
    </div>

    ${renderHomeAssistantCard(authStatus, haUtilities, metersByUtility)}

    ${diag ? renderDiagnostics(diag) : ''}
  `;

  const save = async () => {
    const payload = collectSettings(container);
    try {
      await api.updateSettings(payload);
      invalidateSettings();
      toastOk(t('settings.saved'));
    } catch (e) { toastErr(e.message); }
  };
  container.querySelector('#btn-save').addEventListener('click', save);
  container.querySelector('#btn-save-2').addEventListener('click', save);

  // Sprachumschalter: Sprache speichern, i18n neu laden, Sidebar + View neu rendern.
  container.querySelector('#lang-select')?.addEventListener('change', async (e) => {
    const lang = e.target.value;
    try {
      await api.updateSettings({ language: lang });
      invalidateSettings();
      await initI18n(lang);
      invalidateUtilities();   // Labels kommen lokalisiert vom Backend → neu laden
      await buildSidebar();
      toastOk(t('settings.lang.saved'));
      render(container);
    } catch (err) { toastErr(err.message); }
  });

  // v1.3.0 — PDF-Jahr-Auswahl aktualisiert den Download-Link
  const pdfYear = container.querySelector('#pdf-year');
  const pdfDl   = container.querySelector('#pdf-dl');
  if (pdfYear && pdfDl) {
    pdfYear.addEventListener('change', () => {
      pdfDl.setAttribute('href', api.yearlyReportUrl(pdfYear.value));
    });
  }

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
    try { const r = await api.snapshotBackup(); toastOk(t('settings.backup.snapshotToast', { file: r.file || r.path || 'ok' })); }
    catch (e) { toastErr(e.message); }
  });

  // F1007 — Demo-Daten-Komfort-Import
  container.querySelector('#btn-demo').addEventListener('click', async () => {
    try {
      const status = await api.demoStatus();
      if (!status.available) {
        toastErr(t('settings.backup.demoUnavailable'));
        return;
      }
      if (!status.is_empty) {
        const ok = await confirmModal({
          title: t('settings.backup.demoConfirmTitle'),
          message: t('settings.backup.demoConfirmMsg'),
          confirmLabel: t('settings.backup.demoConfirmBtn'), danger: true,
        });
        if (!ok) return;
      }
      const report = await api.importDemo(!status.is_empty);
      const snap = report?.auto_snapshot_before_restore;
      invalidateSettings();
      if (typeof snap === 'string') toastOk(t('settings.backup.demoLoadedSnap', { snap }));
      else toastOk(t('settings.backup.demoLoaded'));
      render(container);
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-import').addEventListener('click',
    () => container.querySelector('#import-file').click());
  container.querySelector('#import-file').addEventListener('change', async (e) => {
    const f = e.target.files[0]; if (!f) return;
    const ok = await confirmModal({
      title: t('settings.backup.importConfirmTitle'),
      message: t('settings.backup.importConfirmMsg'),
      confirmLabel: t('settings.backup.importConfirmBtn'), danger: true,
    });
    if (!ok) { e.target.value = ''; return; }
    try {
      const text = await f.text();
      const data = JSON.parse(text);
      const report = await api.importBackup(data);
      const snap = report?.auto_snapshot_before_restore;
      if (typeof snap === 'string') {
        toastOk(t('settings.backup.importedSnap', { snap }));
      } else {
        toastOk(t('settings.backup.imported'));
      }
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
      toastErr(t('settings.backup.migrateReadError', { msg: err.message }));
    } finally {
      e.target.value = '';
    }
  });

  // ── F1009 — Home-Assistant-Handler ──
  container.querySelector('#btn-ha-generate')?.addEventListener('click', async () => {
    const ok = await confirmModal({
      title: t('settings.ha.genConfirmTitle'),
      message: t('settings.ha.genConfirmMsg'),
      confirmLabel: t('settings.ha.genConfirmBtn'),
    });
    if (!ok) return;
    try {
      const res = await api.generateToken();
      const reveal = container.querySelector('#ha-token-reveal');
      reveal.innerHTML = `
        <div class="banner banner--success" style="margin-top:.5rem">
          <strong>${t('settings.ha.revealLabel')}</strong>
          <code class="mono" style="display:block;word-break:break-all;margin:6px 0">${escapeHtml(res.token)}</code>
          <button class="btn btn--sm" id="btn-ha-copy-token">${t('settings.ha.copyToken')}</button>
        </div>`;
      container.querySelector('#btn-ha-copy-token')?.addEventListener('click', () => copyText(res.token, t('settings.ha.tokenCopied')));
      container.querySelector('#ha-token-state').className = 'tag tag--success';
      container.querySelector('#ha-token-state').textContent = t('settings.ha.tokenActive');
      toastOk(t('settings.ha.generated'));
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-ha-revoke')?.addEventListener('click', async () => {
    const ok = await confirmModal({
      title: t('settings.ha.revokeConfirmTitle'),
      message: t('settings.ha.revokeConfirmMsg'),
      confirmLabel: t('settings.ha.revokeConfirmBtn'), danger: true,
    });
    if (!ok) return;
    try { await api.revokeToken(); toastOk(t('settings.ha.revoked')); render(container); }
    catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-ha-save-aliases')?.addEventListener('click', async () => {
    const inputs = [...container.querySelectorAll('.ha-alias-input')];
    let saved = 0, failed = 0;
    for (const inp of inputs) {
      const utility = inp.getAttribute('data-utility');
      const meterId = inp.getAttribute('data-meter');
      const value = inp.value.trim();
      try { await api.updateMeter(utility, meterId, { external_id: value || null }); saved++; }
      catch (e) { failed++; toastErr(`${utility}/${meterId}: ${e.message}`); }
    }
    if (failed === 0) toastOk(t('settings.ha.aliasesSaved', { count: saved }));
  });

  container.querySelector('#btn-ha-copy-yaml')?.addEventListener('click', () => {
    copyText(container.querySelector('#ha-yaml')?.innerText || '', t('settings.ha.yamlCopied'));
  });
}

function copyText(text, okMsg) {
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(() => toastOk(okMsg)).catch(() => toastErr(t('settings.ha.clipboardFail')));
  } else {
    toastErr(t('settings.ha.clipboardUnavailable'));
  }
}

// ── F1009 — Home-Assistant-Anbindung ─────────────────────────────────────
function renderHomeAssistantCard(authStatus, haUtilities, metersByUtility) {
  const enabled = !!authStatus?.enabled;

  // Zeilen: pro Zähler ein Alias-Feld. Wir zeigen utility + Zählername.
  const meterRows = haUtilities.flatMap(u =>
    (metersByUtility[u.key] || []).map(m => `
      <tr>
        <td>${escapeHtml(u.icon || '')} ${escapeHtml(u.label)}</td>
        <td>${escapeHtml(m.name)}</td>
        <td>
          <input class="input input--sm ha-alias-input" data-utility="${u.key}" data-meter="${m.id}"
                 value="${escapeHtml(m.external_id || '')}" placeholder="${escapeHtml(t('settings.ha.aliasPlaceholder', { key: u.key }))}"
                 style="min-width:180px">
        </td>
      </tr>
    `)
  ).join('');

  return `
    <div class="card" id="ha-card">
      <h3 class="card__title">${t('settings.ha.title')}</h3>
      <p class="muted" style="margin-bottom: var(--sp-3)">
        ${t('settings.ha.intro')}
      </p>

      <h4 class="settings-subhead">${t('settings.ha.step1')}</h4>
      <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">
        ${t('settings.ha.tokenHint')}
      </p>
      <div class="section-actions" style="align-items:center">
        <span class="tag ${enabled ? 'tag--success' : 'tag--warning'}" id="ha-token-state">
          ${enabled ? t('settings.ha.tokenActive') : t('settings.ha.tokenOpen')}
        </span>
        <button class="btn" id="btn-ha-generate">${enabled ? t('settings.ha.generateNew') : t('settings.ha.generate')}</button>
        ${enabled ? `<button class="btn btn--ghost" id="btn-ha-revoke">${t('settings.ha.revoke')}</button>` : ''}
      </div>
      <div id="ha-token-reveal"></div>

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.ha.step2')}</h4>
      <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">
        ${t('settings.ha.aliasHint')}
      </p>
      ${meterRows ? `
        <table class="data-table">
          <thead><tr><th>${t('settings.ha.colUtility')}</th><th>${t('settings.ha.colMeter')}</th><th>${t('settings.ha.colAlias')}</th></tr></thead>
          <tbody>${meterRows}</tbody>
        </table>
        <div class="section-actions" style="margin-top:var(--sp-2)">
          <button class="btn" id="btn-ha-save-aliases">${t('settings.ha.saveAliases')}</button>
        </div>
      ` : `<p class="muted">${t('settings.ha.noMeters')}</p>`}

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.ha.step3')}</h4>
      <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">
        ${t('settings.ha.configHint')}
      </p>
      <pre class="code-block" id="ha-yaml"><code>${escapeHtml(haRestCommandYaml())}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" id="btn-ha-copy-yaml">${t('settings.ha.copyYaml')}</button>
      </div>
    </div>
  `;
}

// Statisches REST-Command-Snippet (korrekt für /api/ingest).
function haRestCommandYaml() {
  const base = `${location.origin}${location.pathname.replace(/\/[^/]*$/, '')}`.replace(/\/$/, '');
  return [
    'rest_command:',
    '  energietracker_push:',
    `    url: "${base}/api.php/api/ingest"`,
    '    method: POST',
    '    headers:',
    '      Authorization: "Bearer DEIN_API_TOKEN"   # Token aus Schritt 1',
    '      Content-Type: "application/json"',
    '    payload: >',
    '      {',
    '        "utility": "{{ utility }}",',
    '        "meter": "{{ meter }}",',
    '        "value": {{ states(sensor_entity) | float(0) }},',
    `        "date": "{{ now().strftime('%Y-%m-%d') }}"`,
    '      }',
  ].join('\n');
}

function renderGroup(g, settings) {
  return `
    <div class="card settings-card">
      <h3 class="card__title">${g.icon ? g.icon + ' ' : ''}${t('settings.group.' + g.gkey + '.title')}</h3>
      <p class="settings-card__hint">${t('settings.group.' + g.gkey + '.hint')}</p>
      <div class="settings-fields">
        ${g.fields.map(f => renderField(f, settings[f.key])).join('')}
      </div>
    </div>
  `;
}

function renderField(f, value) {
  // A11y (N1009): stabile id pro Feld, damit Label (for) und Control (id)
  // verknüpft sind und der Hinweis per aria-describedby zugeordnet werden kann.
  const fieldId = 'set-' + f.key;
  const hintKey = 'settings.field.' + f.key + '.hint';
  const hintVal = t(hintKey);
  const hasHint = hintVal !== hintKey;
  const hintId  = fieldId + '-hint';
  const hint = hasHint ? `<span class="settings-field__hint" id="${hintId}">${hintVal}</span>` : '';
  const describedBy = hasHint ? ` aria-describedby="${hintId}"` : '';
  const unit = f.unitKey ? t(f.unitKey) : f.unit;
  const labelHtml = `<label for="${fieldId}">${t('settings.field.' + f.key + '.label')}${unit ? ` <span class="settings-field__unit">${escapeHtml(unit)}</span>` : ''}</label>`;

  if (f.type === 'select') {
    return `<div class="field settings-field">
      ${labelHtml}
      <select class="select" id="${fieldId}" data-key="${f.key}"${describedBy}>
        ${f.options.map(o => `<option value="${o}" ${o === value ? 'selected' : ''}>${o}</option>`).join('')}
      </select>
      ${hint}
    </div>`;
  }
  if (f.type === 'bool') {
    return `<div class="field settings-field">
      ${labelHtml}
      <label class="settings-field__check">
        <input type="checkbox" id="${fieldId}" data-key="${f.key}" data-type="bool" ${value ? 'checked' : ''}${describedBy}> ${t('settings.boolActive')}
      </label>
      ${hint}
    </div>`;
  }
  if (f.type === 'datemd') {
    // Speicherung kanonisch MM-TT (für valide Datumskonstruktion im
    // Backend), Anzeige im deutschen Format TT-MM.
    const disp = mmddToDdmm(value);
    return `<div class="field settings-field">
      ${labelHtml}
      <input class="input input--text" type="text" id="${fieldId}" data-key="${f.key}" data-type="datemd"
             value="${escapeHtml(disp)}" ${f.placeholder ? `placeholder="${escapeHtml(f.placeholder)}"` : ''}${describedBy}>
      ${hint}
    </div>`;
  }
  if (f.type === 'text') {
    return `<div class="field settings-field">
      ${labelHtml}
      <input class="input input--text" type="text" id="${fieldId}" data-key="${f.key}"
             value="${escapeHtml(value ?? '')}" ${f.placeholder ? `placeholder="${escapeHtml(f.placeholder)}"` : ''}${describedBy}>
      ${hint}
    </div>`;
  }
  return `<div class="field settings-field">
    ${labelHtml}
    <input class="input" type="number" id="${fieldId}" step="${f.step || '1'}" data-key="${f.key}" value="${value ?? ''}"${describedBy}>
    ${hint}
  </div>`;
}

function collectSettings(container) {
  const out = {};
  container.querySelectorAll('[data-key]').forEach(el => {
    const key = el.getAttribute('data-key');
    if (el.tagName === 'SELECT') out[key] = el.value;
    else if (el.getAttribute('data-type') === 'bool') out[key] = el.checked;
    else if (el.getAttribute('data-type') === 'datemd') {
      // Eingabe TT-MM → kanonisch MM-TT; ungültig ⇒ Default 01-01
      out[key] = ddmmToMmdd(el.value);
    }
    else if (el.type === 'number') {
      const v = el.value;
      out[key] = v === '' ? null : Number(v);
    } else out[key] = el.value;
  });
  // v1.3.0 — aktive Verbrauchsarten aus den Checkboxen
  const utilBoxes = container.querySelectorAll('[data-active-util]');
  if (utilBoxes.length) {
    out.active_utilities = [...utilBoxes]
      .filter(b => b.checked)
      .map(b => b.getAttribute('data-active-util'));
  }
  return out;
}

function pdfYearOpts() {
  const now = new Date().getFullYear();
  let o = '';
  for (let y = now; y >= now - 6; y--) {
    o += `<option value="${y}" ${y === now - 1 ? 'selected' : ''}>${y}</option>`;
  }
  return o;
}

function renderDiagnostics(d) {
  return `
    <div class="card">
      <h3 class="card__title">${t('settings.diag.title')}</h3>
      <dl class="diag-grid">
        ${renderDiagRow(t('settings.diag.appVersion'),    d.app_version,    'mono')}
        ${renderDiagRow(t('settings.diag.schemaVersion'), d.schema_version, 'mono')}
        ${renderDiagRow(t('settings.diag.phpVersion'),    d.php_version,    'mono')}
        ${renderDiagRow(t('settings.diag.dataDir'),       d.data_dir,       'mono path')}
        ${renderDiagRow(t('settings.diag.writable'), renderBool(d.data_dir_writable))}
        ${renderDiagRow(t('settings.diag.curl'),     renderBool(d.curl_available))}
        ${renderDiagRow(t('settings.diag.timezone'),      d.time_zone,      'mono')}
        ${renderDiagRow(t('settings.diag.serverTime'),    fmt.date(String(d.now).slice(0,10)) + ' ' + String(d.now).slice(11,19), 'mono')}
        ${renderDiagRow(t('settings.diag.migrationNeeded'), renderBool(d.migration_needed, /*inverted*/ true))}
      </dl>

      <h4 class="diag-subhead">${t('settings.diag.utilities')}</h4>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
          <th>${t('settings.diag.colUtility')}</th>
          <th class="num">${t('settings.diag.colMeters')}</th>
          <th class="num">${t('settings.diag.colReadings')}</th>
          <th class="num">${t('settings.diag.colContracts')}</th>
          <th>${t('settings.diag.colLastReading')}</th>
        </tr></thead>
        <tbody>
          ${Object.entries(d.utilities || {}).map(([key, u]) => `
            <tr>
              <td><strong>${escapeHtml(key)}</strong></td>
              <td class="num">${fmt.int(u.meters)}</td>
              <td class="num">${fmt.int(u.readings)}</td>
              <td class="num">${fmt.int(u.contracts)}</td>
              <td class="num">${u.last_reading_date ? fmt.date(u.last_reading_date) : '<span class="dim">–</span>'}</td>
            </tr>`).join('')}
        </tbody>
      </table></div>

      <h4 class="diag-subhead">${t('settings.diag.tempSeries')}</h4>
      <p class="diag-line">${t('settings.diag.tempStored', { count: fmt.int(d.temperatures?.rows ?? 0) })}</p>

      <h4 class="diag-subhead">${t('settings.diag.knownKeys')}
        <span class="muted" style="font-weight:400">(${(d.settings_known_keys || []).length})</span>
      </h4>
      <div class="diag-chips">
        ${(d.settings_known_keys || []).map(k => `<code class="chip">${escapeHtml(k)}</code>`).join('')}
      </div>
    </div>
  `;
}

function renderDiagRow(label, value, valueClass = '') {
  return `
    <dt>${escapeHtml(label)}</dt>
    <dd class="${valueClass}">${value === null || value === undefined || value === '' ? '<span class="dim">–</span>' : value}</dd>
  `;
}

// Render a boolean as a colored pill. When `inverted` is true, `false` is the
// good/green state (used for "Migration nötig" — false = nothing to do).
function renderBool(v, inverted = false) {
  const truthy = !!v;
  const good = inverted ? !truthy : truthy;
  return `<span class="badge badge--${good ? 'success' : 'warning'}">${truthy ? t('settings.diag.yes') : t('settings.diag.no')}</span>`;
}

// ── Migrations-Dialog v0.9.0 ───────────────────────────────────────
function openMigrationDialog(previewResult, onDone) {
  const d = previewResult;
  const r = d.report;
  const candidates = r.device_replacement_candidates || [];

  const body = `
    <div style="font-size:13px">
      <p>
        ${t('settings.migrationDialog.detected', { version: escapeHtml(d.legacy_version) })}
      </p>

      <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:14px 0">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:8px">${t('settings.migrationDialog.whatImported')}</div>
        <table class="table table--compact" style="margin:0">
          <thead><tr><th></th><th class="num">Gas</th><th class="num">Strom</th><th class="num">Wasser</th></tr></thead>
          <tbody>
            <tr><td>${t('settings.migrationDialog.readings')}</td><td class="num">${r.readings.gas}</td><td class="num">${r.readings.strom}</td><td class="num">${r.readings.wasser}</td></tr>
            <tr><td>${t('settings.migrationDialog.contracts')}</td><td class="num">${r.contracts.gas}</td><td class="num">${r.contracts.strom}</td><td class="num">${r.contracts.wasser}</td></tr>
          </tbody>
        </table>
        <div style="margin-top:8px;font-size:12px;color:var(--text-2)">
          ${t('settings.migrationDialog.tempSettings', { temps: r.temperatures, settings: r.settings })}
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
            <strong>${t('settings.migrationDialog.candidates', { count: candidates.length })}</strong>
            ${t('settings.migrationDialog.candidatesHint')}
          </summary>
          <table class="table table--compact" style="margin-top:8px">
            <thead><tr><th>${t('settings.migrationDialog.colUtility')}</th><th>${t('settings.migrationDialog.colDate')}</th><th class="num">${t('settings.migrationDialog.colCounter')}</th><th>${t('settings.migrationDialog.colComment')}</th></tr></thead>
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
            ${t('settings.migrationDialog.candidatesNote')}
          </p>
        </details>
      ` : ''}

      <div style="background:var(--bg-2);border-radius:var(--r-md);padding:12px 14px;margin:14px 0">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);margin-bottom:8px">${t('settings.migrationDialog.howWrite')}</div>
        <label style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;cursor:pointer;text-transform:none;letter-spacing:0">
          <input type="radio" name="migrate-mode" value="replace" checked>
          <span>
            <strong>${t('settings.migrationDialog.replaceLabel')}</strong> ${t('settings.migrationDialog.replaceDesc')}
          </span>
        </label>
        <label style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;cursor:pointer;text-transform:none;letter-spacing:0">
          <input type="radio" name="migrate-mode" value="merge">
          <span>
            <strong>${t('settings.migrationDialog.mergeLabel')}</strong> ${t('settings.migrationDialog.mergeDesc')}
          </span>
        </label>
      </div>
    </div>
  `;

  const footer = `
    <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
    <button type="button" class="btn btn--primary" data-act="apply">${t('settings.migrationDialog.import')}</button>
  `;

  openModal({
    title: t('settings.migrationDialog.title'),
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
          toastOk(t('settings.migrationDialog.done', { total, snapshot: result.snapshot }));
          close(true);
          if (onDone) onDone();
        } catch (err) {
          toastErr(t('settings.migrationDialog.failed', { msg: err.message }));
        }
      });
    },
  });
}

// Ist eine Verbrauchsart für den Export aktiv? (active_utilities leer ⇒ alle)
function exportActive(settings, key) {
  const a = settings && settings.active_utilities;
  return Array.isArray(a) && a.length ? a.includes(key) : true;
}

// ── Abrechnungs-Stichtag: Datums-Konvertierung (v1.4.2, Bug #4) ──
// Speicherung kanonisch "MM-TT" (damit das Backend YYYY-MM-TT bauen
// kann), Anzeige/Eingabe im deutschen Format "TT-MM".
function mmddToDdmm(v) {
  const m = /^(\d{2})-(\d{2})$/.exec(String(v ?? '').trim());
  if (!m) return '01-01';
  return `${m[2]}-${m[1]}`; // MM-TT → TT-MM
}
function ddmmToMmdd(v) {
  const m = /^(\d{1,2})[-.\/](\d{1,2})$/.exec(String(v ?? '').trim());
  if (!m) return '01-01';
  let d = Math.min(31, Math.max(1, parseInt(m[1], 10)));
  let mo = Math.min(12, Math.max(1, parseInt(m[2], 10)));
  // 29.–31. Februar auf 28 begrenzen (Backend klemmt zusätzlich)
  if (mo === 2 && d > 28) d = 28;
  const p = n => String(n).padStart(2, '0');
  return `${p(mo)}-${p(d)}`; // → MM-TT
}
