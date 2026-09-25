// =====================================================================
// Energietracker v1.2.0 — Settings view
//   - configurable values, grouped into professional setting cards
//   - CSV export (F-07), JSON backup/restore, v0.9.0 migration
//   - system diagnostics
// =====================================================================

import { api } from '../api.js';
import { invalidateSettings, invalidateUtilities, getCountries } from '../state.js';
import { fmt, escapeHtml, parseDecimal, formatForInput, todayIso, intlLocale } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { confirmModal, openModal } from '../components/modal.js';
import { logout } from '../components/login.js';
import { haRestCommandYaml, haSecretsYaml, haAutomationYaml } from '../lib/ha-snippet.js';
import { t, getLocale, initI18n, getLanguages, setCurrencyParams } from '../lib/i18n.js';
import { setCountry } from '../lib/format.js';
import { CV_UNITS, cvUnit, gasFactorOf } from '../lib/gas-factor.js';
import { buildSidebar } from '../lib/sidebar.js';

// Each group renders as a settings card. `hint` is an optional explanatory
// line under the card title; each field may carry its own `hint` too.
// Gruppen-/Feld-Titel, -Hints und -Labels werden zur Render-Zeit über t()
// aufgelöst: settings.group.<gkey>.{title,hint} und settings.field.<key>.{label,hint}.
// Einheiten mit deutschen Wörtern liegen als unitKey vor; Symbol-Einheiten
// (kWh/m³, °C, σ …) bleiben literal.
const GROUPS = [
  { gkey: 'physical', icon: '🔬', fields: [
    // v2.5.0 — F1012: datierte Liste (Zustandszahl × Brennwert je Stichtag)
    // statt eines Skalars. Eigener Feldtyp, siehe renderGasFactors().
    { key: 'gas_conversion_factors', type: 'gasfactors' },
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
    { key: 'billing_cycle_anchor_gas',    type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    { key: 'billing_cycle_anchor_strom',  type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    { key: 'billing_cycle_anchor_wasser', type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
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
    { key: 'billing_cycle_anchor_fernwaerme', type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    { key: 'billing_cycle_anchor_heizoel',    type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
    { key: 'billing_cycle_anchor_pellets',    type: 'datemd', placeholderKey: 'settings.placeholder.dayMonth' },
  ]},
  { gkey: 'location', icon: '📍', fields: [
    { key: 'location_name',     type: 'text' },
    { key: 'latitude',          step: '0.0001', signed: true },
    { key: 'longitude',         step: '0.0001', signed: true },
    { key: 'weather_auto_fill', type: 'bool' },
  ]},
  // v2.6.0 — frame-ancestors der Content-Security-Policy (index.php): Wer die
  // App in eine Home-Assistant-Webseitenkarte einbettet, trägt deren Adresse ein.
  { gkey: 'embedding', icon: '🖼️', fields: [
    { key: 'frame_ancestors', type: 'text', placeholderKey: 'settings.placeholder.frameAncestors' },
  ]},
];

// v2.6.0 — Die Ansicht rendert sich nach Sprachwechsel, Import oder
// Anmeldeänderung selbst neu. Bisher blieben dabei die Listener der vorigen
// Runde am Container und am Fenster hängen: Nach einem Import meldete ein
// veralteter beforeunload-Handler „ungespeicherte Änderungen", und die
// input-Listener liefen in anderen Ansichten weiter (derselbe Container).
let _unlisten = [];
function unlistenAll() {
  _unlisten.forEach(off => off());
  _unlisten = [];
}
function listen(target, type, handler) {
  target.addEventListener(type, handler);
  _unlisten.push(() => target.removeEventListener(type, handler));
}

export async function render(container) {
  unlistenAll();
  container.innerHTML = `<div class="loading">${t('settings.loading')}</div>`;
  const [settings, diag, utilities, authStatus, session, apiKeys, snapshots, countries] = await Promise.all([
    api.settings(),
    api.diagnostics().catch(() => null),
    api.listUtilities().catch(() => []),
    api.authStatus().catch(() => ({ enabled: false, created_at: null })),
    // v2.6.0 — Anmeldung, API-Schlüssel, gespeicherte Snapshots
    api.session().catch(() => ({ mode: 'off', authenticated: true })),
    api.apiKeys().catch(() => []),
    api.snapshots().catch(() => []),
    // v2.7.0 — Länderprofile für die Karte „Sprache & Land"
    getCountries(),
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
        ${renderRegionFields(settings, countries || [])}
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

      <h4 class="settings-subhead">${t('settings.backup.snapshotsTitle')}</h4>
      <p class="muted" style="font-size:12px;margin: 0 0 var(--sp-2)">
        ${t('settings.backup.snapshotsHint')}
      </p>
      <div id="snap-list" class="snapshot-list">${renderSnapshots(snapshots)}</div>

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

    ${renderSecurityCard(session, apiKeys)}

    ${renderHomeAssistantCard(authStatus, haUtilities, metersByUtility, session)}

    ${diag ? renderDiagnostics(diag) : ''}
  `;

  // v2.2.0 — Schutz vor unbemerktem Verlust: Bis v2.1.5 gingen geänderte
  // Einstellungen beim Wegnavigieren oder Schließen des Tabs kommentarlos
  // verloren. Zwei Stufen, beide ohne die Navigation zu kapern:
  //   1. `beforeunload` warnt beim Schließen/Neuladen (echter Verlust).
  //   2. Ein Marker an der Speichern-Schaltfläche macht offene Änderungen
  //      sichtbar, solange man in der Ansicht ist.
  // v2.5.0 — F1012: Tabelle der datierten Gas-Faktoren verdrahten. Muss vor
  // der Baseline stehen, damit Hinzufügen/Entfernen den Ungespeichert-Marker
  // auslöst wie jede andere Änderung.
  wireGasFactors(container);

  let baseline = JSON.stringify(collectSettings(container));
  const saveButtons = [...container.querySelectorAll('#btn-save, #btn-save-2')];
  const isDirty = () => JSON.stringify(collectSettings(container)) !== baseline;

  const markDirty = () => {
    const dirty = isDirty();
    saveButtons.forEach(b => {
      b.classList.toggle('btn--dirty', dirty);
      b.textContent = dirty ? t('settings.saveUnsaved') : t('settings.save');
    });
  };
  listen(container, 'input', markDirty);
  listen(container, 'change', markDirty);

  const beforeUnload = (e) => {
    if (!isDirty()) return;
    e.preventDefault();
    e.returnValue = '';   // Browser verlangen das; der Text ist nicht steuerbar
  };
  listen(window, 'beforeunload', beforeUnload);

  const save = async () => {
    const invalid = firstInvalidSetting(container);
    if (invalid) {
      invalid.scrollIntoView?.({ block: 'center' });
      invalid.focus();
      toastErr(t('settings.invalidFields'));
      return;
    }
    const payload = collectSettings(container);
    try {
      await api.updateSettings(payload);
      invalidateSettings();
      baseline = JSON.stringify(payload);   // Stand ist gesichert
      markDirty();
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

  // v2.7.0 — Land, Währung und Zeitzone wirken wie die Sprache sofort. Beim
  // Land schlägt ein Dialog die abweichenden Werte des Länderprofils vor.
  container.querySelector('#country-select')?.addEventListener('change', async (e) => {
    const code = e.target.value;
    const profile = (countries || []).find(c => c.code === code);
    const diff = profile ? profileDiff(settings, profile) : [];
    const patch = { country: code };
    if (diff.length) {
      const choice = await chooseProfile(code, diff, profile);
      if (choice === null) { e.target.value = settings.country; return; }
      if (choice === 'all') diff.forEach(d => Object.assign(patch, d.patch));
    }
    applyRegion(container, patch, countries);
  });
  container.querySelector('#currency-select')?.addEventListener('change', (e) => applyRegion(container, { currency: e.target.value }, countries));
  container.querySelector('#tz-select')?.addEventListener('change', (e) => applyRegion(container, { timezone: e.target.value }, countries));

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
      a.href = url; a.download = `energietracker-backup-${todayIso()}.json`;
      a.click(); URL.revokeObjectURL(url);
    } catch (e) { toastErr(e.message); }
  });

  // v2.6.0 — Liste der Snapshots neu laden (nach Anlegen/Löschen)
  const refreshSnapshots = async () => {
    const el = container.querySelector('#snap-list');
    if (!el) return;
    try { el.innerHTML = renderSnapshots(await api.snapshots()); } catch { /* alte Liste bleibt */ }
  };

  container.querySelector('#btn-snapshot').addEventListener('click', async () => {
    try {
      const r = await api.snapshotBackup();
      toastOk(t('settings.backup.snapshotToast', { file: r.file || r.path || 'ok' }));
      refreshSnapshots();
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#snap-list')?.addEventListener('click', async (ev) => {
    const restoreBtn = ev.target.closest('[data-snap-restore]');
    const deleteBtn  = ev.target.closest('[data-snap-delete]');
    if (!restoreBtn && !deleteBtn) return;
    const btn  = restoreBtn || deleteBtn;
    const name = btn.getAttribute(restoreBtn ? 'data-snap-restore' : 'data-snap-delete');
    const date = stampText(btn.getAttribute('data-snap-date'));
    if (restoreBtn) {
      const ok = await confirmModal({
        title: t('settings.backup.snapRestoreTitle'),
        message: t('settings.backup.snapRestoreMsg', { date }),
        confirmLabel: t('settings.backup.snapRestore').replace(/…$/, ''), danger: true,
      });
      if (!ok) return;
      try {
        const report = await withSnapshotGuard(
          () => api.restoreSnapshot(name),
          () => api.restoreSnapshot(name, { allowWithoutSnapshot: true }));
        if (!report) return;
        toastOk(t('settings.backup.snapRestored'));
        await afterRestore(container);
      } catch (e) { showBackupError(e); }
      return;
    }
    const ok = await confirmModal({
      title: t('settings.backup.snapDeleteTitle'),
      message: t('settings.backup.snapDeleteMsg', { date }),
      confirmLabel: t('settings.backup.snapDelete'), danger: true,
    });
    if (!ok) return;
    try { await api.deleteSnapshot(name); toastOk(t('settings.backup.snapDeleted')); refreshSnapshots(); }
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
  // v2.6.0 — Import in zwei Schritten: Erst prüft der Server das Backup
  // (dry_run) und die Vorschau zeigt, was ersetzt wird und was unverändert
  // bleibt; eingespielt wird erst nach der Bestätigung. Ein fehlerhaftes
  // Backup ändert nichts und nennt die Fundstellen.
  container.querySelector('#import-file').addEventListener('change', async (e) => {
    const f = e.target.files[0];
    e.target.value = '';
    if (!f) return;
    let data;
    try { data = JSON.parse(await f.text()); }
    catch (err) { toastErr(t('settings.backup.migrateReadError', { msg: err.message })); return; }
    try {
      const preview = await api.importBackup(data, { dryRun: true });
      if (!await confirmImport(preview, utilities)) return;
      const report = await withSnapshotGuard(
        () => api.importBackup(data),
        () => api.importBackup(data, { allowWithoutSnapshot: true }));
      if (!report) return;
      const snap = report.auto_snapshot_before_restore;
      toastOk(typeof snap === 'string'
        ? t('settings.backup.importedSnap', { snap })
        : t('settings.backup.imported'));
      await afterRestore(container);
    } catch (err) { showBackupError(err); }
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

  // ── v2.6.0 — Anmeldung & Zugriff ──
  const pwValue = (id) => container.querySelector('#' + id)?.value ?? '';
  const newPassword = () => {
    const pw = pwValue('sec-new');
    if (pw.length < 8) { toastErr(t('errors.auth.passwordTooShort', { min: 8 })); return null; }
    if (pw !== pwValue('sec-repeat')) { toastErr(t('settings.security.mismatch')); return null; }
    return pw;
  };
  const sessionChanged = (mode) => window.dispatchEvent(new CustomEvent('et:session-changed', { detail: { mode } }));

  container.querySelector('#btn-sec-enable')?.addEventListener('click', async () => {
    const pw = newPassword();
    if (!pw) return;
    try {
      const r = await api.setPassword(pw);
      sessionChanged(r?.mode || 'password');
      toastOk(t('settings.security.enabled'));
      render(container);
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-sec-change')?.addEventListener('click', async () => {
    const pw = newPassword();
    if (!pw) return;
    try {
      await api.setPassword(pw, pwValue('sec-current'));
      toastOk(t('settings.security.changed'));
      render(container);
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-sec-logout')?.addEventListener('click', () => logout());

  container.querySelector('#btn-sec-disable')?.addEventListener('click', () => {
    openModal({
      title: t('settings.security.disableTitle'),
      body: `<p>${escapeHtml(t('settings.security.disableMsg'))}</p>
        <div class="field">
          <label for="sec-disable-pw">${t('settings.security.currentPassword')}</label>
          <input class="input input--text" id="sec-disable-pw" type="password" autocomplete="current-password">
          <div class="field-error" id="sec-disable-msg" role="alert" hidden></div>
        </div>`,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--danger" data-act="ok">${t('settings.security.disable').replace(/…$/, '')}</button>`,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
        const okBtn = modalEl.querySelector('[data-act="ok"]');
        okBtn?.addEventListener('click', async () => {
          okBtn.disabled = true;
          try {
            await api.disableLogin(modalEl.querySelector('#sec-disable-pw')?.value ?? '');
            close(true);
            sessionChanged('off');
            toastOk(t('settings.security.disabled'));
            render(container);
          } catch (e) {
            const msg = modalEl.querySelector('#sec-disable-msg');
            if (msg) { msg.textContent = e.message; msg.hidden = false; }
            okBtn.disabled = false;
          }
        });
      },
    });
  });

  const refreshKeys = async () => {
    const el = container.querySelector('#sec-keys');
    if (!el) return;
    try { el.innerHTML = renderKeyTable(await api.apiKeys()); } catch { /* alte Liste bleibt */ }
  };

  container.querySelector('#btn-key-create')?.addEventListener('click', async () => {
    const nameEl = container.querySelector('#key-name');
    const scope = container.querySelector('#key-scope')?.value === 'admin' ? 'admin' : 'read';
    const name = (nameEl?.value || '').trim();
    try {
      const res = await api.createApiKey(name, scope);
      const shown = name || t(scope === 'admin' ? 'settings.security.scopeAdmin' : 'settings.security.scopeRead');
      container.querySelector('#key-reveal').innerHTML = `
        <div class="banner banner--success" style="margin-top:.5rem">
          <strong>${t('settings.security.keyReveal', { name: escapeHtml(shown) })}</strong>
          <code class="mono" style="display:block;word-break:break-all;margin:6px 0">${escapeHtml(res.key)}</code>
          <button type="button" class="btn btn--sm" id="btn-key-copy">${t('settings.security.keyCopy')}</button>
        </div>`;
      container.querySelector('#btn-key-copy')?.addEventListener('click', () => copyText(res.key, t('settings.security.keyCopied')));
      if (nameEl) nameEl.value = '';
      refreshKeys();
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#sec-keys')?.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-key-revoke]');
    if (!btn) return;
    const ok = await confirmModal({
      title: t('settings.security.keyRevokeTitle'),
      message: t('settings.security.keyRevokeMsg', { name: btn.getAttribute('data-key-name') || '' }),
      confirmLabel: t('settings.security.keyRevoke'), danger: true,
    });
    if (!ok) return;
    try {
      await api.revokeApiKey(btn.getAttribute('data-key-revoke'));
      toastOk(t('settings.security.keyRevoked'));
      container.querySelector('#key-reveal').innerHTML = '';
      refreshKeys();
    } catch (e) { toastErr(e.message); }
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
    // Die Automatisierungs-Vorlage folgt den Aliasen.
    const code = container.querySelector('#ha-automation code');
    if (code) {
      code.textContent = haAutomationYaml(inputs.filter(i => i.value.trim()).map(i => ({
        utility: i.getAttribute('data-utility'), meter: i.value.trim(), sensor: `sensor.${i.value.trim()}`,
      })));
    }
  });

  container.querySelectorAll('[data-ha-copy]').forEach(btn => btn.addEventListener('click', () => {
    copyText(container.querySelector(btn.getAttribute('data-ha-copy'))?.innerText || '', t('settings.ha.yamlCopied'));
  }));
  container.querySelector('#btn-ha-copy-yaml')?.addEventListener('click', () => {
    copyText(container.querySelector('#ha-yaml')?.innerText || '', t('settings.ha.yamlCopied'));
  });

  // Der Router ruft diese Funktion beim Verlassen der Ansicht auf.
  return unlistenAll;
}

// ── v2.7.0 — Länderprofil (N1014) ────────────────────────────────────

function renderRegionFields(settings, countries) {
  const codes = countries.length ? countries.map(c => c.code) : [settings.country || 'DE'];
  // Währungen aus den Profilen (SSOT Config\Countries), nicht aus einer Liste hier
  const currencies = [...new Set([...countries.map(c => c.currency), settings.currency || 'EUR'])];
  return `
    <div class="field settings-field">
      <label for="country-select">${t('settings.region.country')}</label>
      <select class="select" id="country-select">
        ${codes.map(c => `<option value="${c}" ${c === settings.country ? 'selected' : ''}>${escapeHtml(t('countries.' + c))}</option>`).join('')}
      </select>
    </div>
    <div class="field settings-field">
      <label for="currency-select">${t('settings.region.currency')}</label>
      <select class="select" id="currency-select" aria-describedby="currency-hint">
        ${currencies.map(c => `<option value="${c}" ${c === settings.currency ? 'selected' : ''}>${c}</option>`).join('')}
      </select>
      <span class="settings-field__hint" id="currency-hint">${t('settings.region.currencyNote')}</span>
    </div>
    <div class="field settings-field">
      <label for="tz-select">${t('settings.region.timezone')}</label>
      <select class="select" id="tz-select">
        ${timeZones(settings.timezone, countries).map(z => `<option value="${escapeHtml(z)}" ${z === settings.timezone ? 'selected' : ''}>${escapeHtml(z)}</option>`).join('')}
      </select>
    </div>`;
}

/** Zeitzonen aus Intl (falls vorhanden), ergänzt um die der Profile und die aktuelle. */
function timeZones(current, countries) {
  let all = [];
  try { all = Intl.supportedValuesOf('timeZone'); } catch { all = []; }
  return [...new Set([...all, ...countries.map(c => c.timezone), current || 'Europe/Berlin'])].sort();
}

/** Zeilen „bisher → Profil" für die Werte, in denen das Profil abweicht. */
function profileDiff(settings, p) {
  const rows = [];
  const add = (key, label, now, next, patch) => {
    if (now !== next) rows.push({ key, label, now, next, patch });
  };
  add('currency', t('settings.region.currency'), settings.currency, p.currency, { currency: p.currency });
  add('timezone', t('settings.region.timezone'), settings.timezone, p.timezone, { timezone: p.timezone });
  add('hdd_base_temp', t('settings.field.hdd_base_temp.label'),
    fmt.num(settings.hdd_base_temp, 1) + ' °C', fmt.num(p.hdd_base_temp, 1) + ' °C', { hdd_base_temp: p.hdd_base_temp });
  add('co2_strom', t('settings.field.co2_strom.label'),
    fmt.num(settings.co2_strom, 0) + ' g/kWh', fmt.num(p.co2_strom, 0) + ' g/kWh', { co2_strom: p.co2_strom });
  const loc = (name, lat, lon) => `${name} (${fmt.num(lat, 4)}, ${fmt.num(lon, 4)})`;
  add('location', t('settings.group.location.title'),
    loc(settings.location_name, settings.latitude, settings.longitude), loc(p.location_name, p.latitude, p.longitude),
    { location_name: p.location_name, latitude: p.latitude, longitude: p.longitude });
  const cv = (u) => cvUnit(u).label;
  add('gas_cv_unit', t('settings.gasFactors.cvUnit'), cv(settings.gas_cv_unit), cv(p.gas_cv_unit), { gas_cv_unit: p.gas_cv_unit });
  return rows;
}

/** Dialog: alle Profilwerte übernehmen, nur das Land ändern oder abbrechen. */
function chooseProfile(code, diff, profile) {
  const source = profile.co2_strom_source === 'ember-2024' ? 'ember2024' : 'default';
  const ctrl = openModal({
    title: t('settings.region.applyTitle', { country: t('countries.' + code) }),
    body: `
      <p>${t('settings.region.applyIntro')}</p>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
          <th scope="col">${t('settings.region.colSetting')}</th>
          <th scope="col">${t('settings.region.colNow')}</th>
          <th scope="col">${t('settings.region.colProfile')}</th>
        </tr></thead>
        <tbody>${diff.map(d => `<tr><td>${escapeHtml(d.label)}</td><td>${escapeHtml(d.now)}</td><td><strong>${escapeHtml(d.next)}</strong></td></tr>`).join('')}</tbody>
      </table></div>
      ${diff.some(d => d.key === 'co2_strom') ? `<p class="muted">${escapeHtml(t('settings.region.source.' + source))}</p>` : ''}
      <p class="muted">${t('settings.region.applyKeep')}</p>`,
    footer: `
      <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
      <button type="button" class="btn btn--ghost" data-act="country">${t('settings.region.applyCountryOnly')}</button>
      <button type="button" class="btn btn--primary" data-act="all">${t('settings.region.applyAll')}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
      modalEl.querySelector('[data-act="country"]')?.addEventListener('click', () => close('country'));
      modalEl.querySelector('[data-act="all"]')?.addEventListener('click', () => close('all'));
    },
  });
  return ctrl.closedPromise.then(v => (v === 'all' || v === 'country') ? v : null);
}

async function applyRegion(container, patch, countries = []) {
  try {
    await api.updateSettings(patch);
    invalidateSettings();
    if (patch.currency) setCurrencyParams(patch.currency);
    if (patch.country) setCountry(patch.country, countries.find(c => c.code === patch.country)?.languages);
    toastOk(t('settings.region.saved'));
    render(container);
  } catch (err) { toastErr(err.message); }
}

function copyText(text, okMsg) {
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(() => toastOk(okMsg)).catch(() => toastErr(t('settings.ha.clipboardFail')));
  } else {
    toastErr(t('settings.ha.clipboardUnavailable'));
  }
}

// ── F1009 — Home-Assistant-Anbindung ─────────────────────────────────────
function renderHomeAssistantCard(authStatus, haUtilities, metersByUtility, session = null) {
  const enabled = !!authStatus?.enabled;
  // v2.6.0 — Hilfe bei der Fehlersuche („kommt überhaupt etwas an?") und der
  // Hinweis, dass der Push mit eingeschalteter Anmeldung einen Token braucht.
  const usage = enabled
    ? `<p class="settings-field__hint" id="ha-token-usage">${authStatus.last_used_at
        ? t('settings.ha.lastUsed', { when: stampHtml(authStatus.last_used_at) })
        : t('settings.ha.neverUsed')}</p>`
    : '';
  const loginWarning = !enabled && session && session.mode !== 'off'
    ? `<div class="banner banner--warning" style="margin-top:.5rem">${t('settings.ha.loginNeedsToken')}</div>`
    : '';

  // Zeilen: pro Zähler ein Alias-Feld. Wir zeigen utility + Zählername.
  const meterRows = haUtilities.flatMap(u =>
    (metersByUtility[u.key] || []).map(m => `
      <tr>
        <td>${escapeHtml(u.icon || '')} ${escapeHtml(u.label)}</td>
        <td>${escapeHtml(m.name)}</td>
        <td>
          <input class="input input--sm ha-alias-input" data-utility="${u.key}" data-meter="${escapeHtml(m.id)}"
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
      ${usage}${loginWarning}
      <div id="ha-token-reveal"></div>

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.ha.step2')}</h4>
      <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">
        ${t('settings.ha.aliasHint')}
      </p>
      ${meterRows ? `
        <div class="table-wrap"><table class="data-table">
          <thead><tr><th>${t('settings.ha.colUtility')}</th><th>${t('settings.ha.colMeter')}</th><th>${t('settings.ha.colAlias')}</th></tr></thead>
          <tbody>${meterRows}</tbody>
        </table></div>
        <div class="section-actions" style="margin-top:var(--sp-2)">
          <button class="btn" id="btn-ha-save-aliases">${t('settings.ha.saveAliases')}</button>
        </div>
      ` : `<p class="muted">${t('settings.ha.noMeters')}</p>`}

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.ha.step3')}</h4>
      <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">
        ${t('settings.ha.configHint')}
      </p>
      <p class="settings-field__hint"><code>configuration.yaml</code></p>
      <pre class="code-block" id="ha-yaml"><code>${escapeHtml(haRestCommandYaml(haBaseUrl()))}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" id="btn-ha-copy-yaml">${t('settings.ha.copyYaml')}</button>
      </div>
      <p class="settings-field__hint"><code>secrets.yaml</code></p>
      <pre class="code-block" id="ha-secrets"><code>${escapeHtml(haSecretsYaml())}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" data-ha-copy="#ha-secrets">${t('settings.ha.copyYaml')}</button>
      </div>

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.ha.step4')}</h4>
      <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">
        ${t('settings.ha.automationHint')}
      </p>
      <pre class="code-block" id="ha-automation"><code>${escapeHtml(haAutomationYaml(haAutomationEntries(haUtilities, metersByUtility)))}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" data-ha-copy="#ha-automation">${t('settings.ha.copyYaml')}</button>
      </div>
    </div>
  `;
}

// ── v2.6.0 — Anmeldung & Zugriff ─────────────────────────────────────────
//
// Die Anmeldung ist opt-in (Review 2026-09-24): Ohne sie verhält sich die App
// wie bisher. Die Karte zeigt den Modus, schaltet die Passwort-Anmeldung ein
// und aus und verwaltet API-Schlüssel für Skripte. Was über Umgebungsvariablen
// festgelegt ist (ET_AUTH, ET_ADMIN_PASSWORD_HASH), lässt sich hier nicht ändern.
function renderSecurityCard(session, keys) {
  const mode = ['off', 'password', 'proxy'].includes(session?.mode) ? session.mode : 'off';
  const modeFixed = !!session?.mode_fixed;
  const pwFixed   = !!session?.password_fixed;
  const label = { off: 'modeOff', password: 'modePassword', proxy: 'modeProxy' }[mode];
  const pwField = (id, key, autocomplete) => `
    <div class="field">
      <label for="${id}">${t(key)}</label>
      <input class="input input--text" id="${id}" type="password" autocomplete="${autocomplete}">
    </div>`;

  let body = '';
  if (mode === 'off' && !modeFixed) {
    body = `
      <div class="form-row" style="align-items:flex-end">
        ${pwField('sec-new', 'settings.security.newPassword', 'new-password')}
        ${pwField('sec-repeat', 'settings.security.repeatPassword', 'new-password')}
        <div class="field">
          <button type="button" class="btn btn--primary" id="btn-sec-enable">${t('settings.security.enable')}</button>
        </div>
      </div>
      <p class="settings-field__hint">${t('settings.security.enableHint')}</p>`;
  } else if (mode === 'password') {
    body = pwFixed
      ? `<p class="settings-field__hint">${t('settings.security.passwordFixed')}</p>`
      : `<div class="form-row" style="align-items:flex-end">
          ${pwField('sec-current', 'settings.security.currentPassword', 'current-password')}
          ${pwField('sec-new', 'settings.security.newPassword', 'new-password')}
          ${pwField('sec-repeat', 'settings.security.repeatPassword', 'new-password')}
          <div class="field">
            <button type="button" class="btn" id="btn-sec-change">${t('settings.security.change')}</button>
          </div>
        </div>`;
    body += `
      <div class="section-actions" style="margin-top:var(--sp-2)">
        <button type="button" class="btn btn--ghost" id="btn-sec-logout">${t('login.logout')}</button>
        ${modeFixed ? '' : `<button type="button" class="btn btn--ghost" id="btn-sec-disable">${t('settings.security.disable')}</button>`}
      </div>`;
  } else if (mode === 'proxy') {
    body = `<p class="settings-field__hint">${t('settings.security.proxyHint')}</p>`;
  }

  return `
    <div class="card" id="security-card">
      <h3 class="card__title">${t('settings.security.title')}</h3>
      <p class="muted" style="margin-bottom: var(--sp-3)">${t('settings.security.hint')}</p>
      <div class="section-actions" style="align-items:center;margin-bottom:var(--sp-3)">
        <span class="tag ${mode === 'off' ? 'tag--warning' : 'tag--success'}" id="sec-mode">${t('settings.security.' + label)}</span>
        ${modeFixed ? `<span class="muted" style="font-size:12px">${t('settings.security.modeFixed')}</span>` : ''}
      </div>
      ${body}

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.security.keysTitle')}</h4>
      <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">${t('settings.security.keysHint')}</p>
      <div id="sec-keys">${renderKeyTable(keys)}</div>
      <div class="form-row" style="align-items:flex-end;margin-top:var(--sp-2)">
        <div class="field">
          <label for="key-name">${t('settings.security.keyName')}</label>
          <input class="input input--text" id="key-name" type="text" maxlength="60" autocomplete="off"
                 placeholder="${escapeHtml(t('settings.security.keyNamePlaceholder'))}">
        </div>
        <div class="field">
          <label for="key-scope">${t('settings.security.keyScope')}</label>
          <select class="select" id="key-scope">
            <option value="read">${t('settings.security.scopeRead')}</option>
            <option value="admin">${t('settings.security.scopeAdmin')}</option>
          </select>
        </div>
        <div class="field">
          <button type="button" class="btn" id="btn-key-create">${t('settings.security.keyCreate')}</button>
        </div>
      </div>
      <div id="key-reveal"></div>
    </div>`;
}

function renderKeyTable(keys) {
  if (!Array.isArray(keys) || !keys.length) return `<p class="muted">${t('settings.security.keysNone')}</p>`;
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${t('settings.security.keyName')}</th>
      <th scope="col">${t('settings.security.keyScope')}</th>
      <th scope="col">${t('settings.security.keyCreatedAt')}</th>
      <th scope="col">${t('settings.security.keyLastUsed')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>${keys.map(k => `<tr>
      <td>${escapeHtml(k.name)}</td>
      <td>${t(k.scope === 'admin' ? 'settings.security.scopeAdmin' : 'settings.security.scopeRead')}</td>
      <td>${stampHtml(k.created_at)}</td>
      <td>${k.last_used_at ? stampHtml(k.last_used_at) : `<span class="muted">${t('settings.security.keyNever')}</span>`}</td>
      <td style="text-align:right">
        <button type="button" class="btn btn--ghost btn--sm" data-key-revoke="${escapeHtml(k.id)}" data-key-name="${escapeHtml(k.name)}">${t('settings.security.keyRevoke')}</button>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

// ── v2.6.0 — Snapshots und Import ────────────────────────────────────────

function renderSnapshots(list) {
  if (!Array.isArray(list) || !list.length) return `<p class="muted">${t('settings.backup.snapshotsNone')}</p>`;
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${t('settings.backup.colDate')}</th>
      <th scope="col">${t('settings.backup.colReason')}</th>
      <th scope="col" class="num snapshot-list__size">${t('settings.backup.colSize')}</th>
      <th scope="col"><span class="sr-only">${t('common.actions')}</span></th>
    </tr></thead>
    <tbody>${list.map(s => `<tr>
      <td title="${escapeHtml(s.name)}">${stampHtml(s.created_at)}</td>
      <td>${escapeHtml(t('settings.backup.reason.' + (s.reason || 'manual')))}</td>
      <td class="num snapshot-list__size">${bytesText(s.size)}</td>
      <td class="snapshot-list__actions">
        <a class="icon-btn" href="${api.snapshotUrl(s.name)}" download="${escapeHtml(s.name)}" title="${t('settings.backup.snapDownload')}" aria-label="${t('settings.backup.snapDownload')}"><span aria-hidden="true">⬇️</span></a>
        <button type="button" class="icon-btn" data-snap-restore="${escapeHtml(s.name)}" data-snap-date="${escapeHtml(s.created_at)}" title="${t('settings.backup.snapRestore')}" aria-label="${t('settings.backup.snapRestore')}"><span aria-hidden="true">↩️</span></button>
        <button type="button" class="icon-btn" data-snap-delete="${escapeHtml(s.name)}" data-snap-date="${escapeHtml(s.created_at)}" title="${t('settings.backup.snapDelete')}" aria-label="${t('settings.backup.snapDelete')}"><span aria-hidden="true">🗑️</span></button>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

/** Zeitstempel (ISO mit Uhrzeit) im Format der Oberflächensprache. */
function stampText(iso) {
  const d = iso ? new Date(iso) : null;
  if (!d || Number.isNaN(d.getTime())) return '–';
  return d.toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' });
}
const stampHtml = (iso) => escapeHtml(stampText(iso));

function bytesText(n) {
  const b = Number(n) || 0;
  return b >= 1048576 ? `${fmt.num(b / 1048576, 1)} MB` : `${fmt.int(Math.max(1, Math.round(b / 1024)))} KB`;
}

/**
 * Einspielen mit Rückweg: Scheitert der Sicherungs-Snapshot vorher (409),
 * fragt die Oberfläche nach, statt still ohne Rückweg weiterzumachen.
 * Liefert den Bericht oder null (abgebrochen).
 */
async function withSnapshotGuard(run, runWithoutSnapshot) {
  try {
    return await run();
  } catch (e) {
    if (e.status !== 409 || e.code !== 'errors.backup.snapshotFailed') throw e;
    const ok = await confirmModal({
      title: t('settings.backup.noSnapshotTitle'),
      message: e.message,
      confirmLabel: t('settings.backup.noSnapshotBtn'), danger: true,
    });
    return ok ? runWithoutSnapshot() : null;
  }
}

/** Fehler beim Einspielen: Fundstellen im Dialog, sonst als Hinweis. */
function showBackupError(err) {
  const problems = err?.detail?.problems;
  if (!Array.isArray(problems) || !problems.length) { toastErr(err?.message || String(err)); return; }
  const rows = problems.map(p => {
    const [kind, arg] = String(p.problem || '').split(/:(.*)/s);
    const what = kind === 'missing' || kind === 'date' ? t('settings.backup.problem.' + kind, { field: arg ?? '' })
      : kind === 'entry' ? t('settings.backup.problem.entry', { what: arg ?? '' })
      : t('settings.backup.problem.' + kind);
    const where = `${p.pot}${Number.isInteger(p.index) ? ' #' + (p.index + 1) : ''}`;
    return `<li><code>${escapeHtml(where)}</code> — ${escapeHtml(what)}</li>`;
  }).join('');
  openModal({
    title: t('settings.backup.problemsTitle'),
    body: `<p>${escapeHtml(err.message)}</p>
      <ul class="problem-list">${rows}</ul>
      ${problems.length >= 50 ? `<p class="muted">${t('settings.backup.problemsMore')}</p>` : ''}`,
    footer: `<button type="button" class="btn btn--primary" data-act="ok">${t('common.close')}</button>`,
    onMount({ modalEl, close }) {
      modalEl.querySelector('[data-act="ok"]')?.addEventListener('click', () => close(true));
    },
  });
}

/** Vorschau eines geprüften Backups (dry_run); true = einspielen. */
function confirmImport(report, utilities) {
  const label = (key) => (utilities || []).find(u => u.key === key)?.label || key;
  const items = [];
  for (const key of ['settings', 'temperatures', 'reminders', 'recommendations_dismissed']) {
    if (report[key] == null) continue;
    items.push(key === 'settings'
      ? escapeHtml(t('settings.backup.top.settings'))
      : `${escapeHtml(t('settings.backup.top.' + key))}: ${fmt.int(report[key])}`);
  }
  for (const [key, counts] of Object.entries(report.utilities || {})) {
    // „Verträge: 1" statt „1 Verträge" — ohne Pluralformen in allen Sprachen richtig
    const parts = Object.entries(counts || {})
      .filter(([, n]) => n > 0)
      .map(([pot, n]) => `${escapeHtml(t('settings.backup.pot.' + pot))}: ${fmt.int(n)}`);
    if (parts.length) items.push(`<strong>${escapeHtml(label(key))}</strong> — ${parts.join(', ')}`);
  }
  const untouched = (report.untouched || []).map(p => {
    const [u, pot] = String(p).split('/');
    return pot ? `${label(u)} – ${t('settings.backup.pot.' + pot)}` : t('settings.backup.top.' + u);
  });
  return new Promise(resolve => {
    openModal({
      title: t('settings.backup.previewTitle'),
      body: `<p>${t('settings.backup.previewIntro')}</p>
        <ul class="import-preview">${items.map(i => `<li>${i}</li>`).join('')}</ul>
        ${untouched.length ? `<p class="muted">${escapeHtml(t('settings.backup.previewUntouched', { list: untouched.join(', ') }))}</p>` : ''}
        <div class="banner banner--warning">${escapeHtml(t('settings.backup.importConfirmMsg'))}</div>`,
      footer: `
        <button type="button" class="btn btn--ghost" data-act="cancel">${t('common.cancel')}</button>
        <button type="button" class="btn btn--danger" data-act="ok">${t('settings.backup.importConfirmBtn')}</button>`,
      onMount({ modalEl, close }) {
        modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(false));
        modalEl.querySelector('[data-act="ok"]')?.addEventListener('click', () => close(true));
      },
    }).closedPromise.then(v => resolve(v === true));
  });
}

/** Nach Import/Wiederherstellung: Zwischenspeicher, Seitenleiste, Ansicht neu. */
async function afterRestore(container) {
  invalidateSettings();
  invalidateUtilities();
  try { await buildSidebar(); } catch { /* Ansicht trotzdem neu */ }
  render(container);
}

// Adresse dieser Installation, wie Home Assistant sie aufrufen soll.
function haBaseUrl() {
  return `${location.origin}${location.pathname.replace(/\/[^/]*$/, '')}`.replace(/\/$/, '');
}

// Je Zähler mit Alias ein Eintrag. Die HA-Entität kennt die App nicht; der
// Alias ist nur ein Vorschlag, den der Hinweistext zu ersetzen bittet.
function haAutomationEntries(haUtilities, metersByUtility) {
  return haUtilities.flatMap(u => (metersByUtility[u.key] || [])
    .filter(m => m.external_id)
    .map(m => ({ utility: u.key, meter: m.external_id, sensor: `sensor.${m.external_id}` })));
}

function renderGroup(g, settings) {
  return `
    <div class="card settings-card">
      <h3 class="card__title">${g.icon ? g.icon + ' ' : ''}${t('settings.group.' + g.gkey + '.title')}</h3>
      <p class="settings-card__hint">${t('settings.group.' + g.gkey + '.hint')}</p>
      <div class="settings-fields">
        ${g.fields.map(f => renderField(f, settings[f.key], settings)).join('')}
      </div>
    </div>
  `;
}

function renderField(f, value, settings = {}) {
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
  // v2.2.0 — Platzhalter mit deutschem Text („TT-MM", „z. B. 1998") laufen
  // jetzt über den Katalog.
  const placeholder = f.placeholderKey ? t(f.placeholderKey) : f.placeholder;
  const labelHtml = `<label for="${fieldId}">${t('settings.field.' + f.key + '.label')}${unit ? ` <span class="settings-field__unit">${escapeHtml(unit)}</span>` : ''}</label>`;

  if (f.type === 'gasfactors') {
    return renderGasFactors(f, Array.isArray(value) ? value : [], hint, settings);
  }
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
             value="${escapeHtml(disp)}" ${placeholder ? `placeholder="${escapeHtml(placeholder)}"` : ''}${describedBy}>
      <div class="field-error" id="${fieldId}-err" role="alert" hidden></div>
      ${hint}
    </div>`;
  }
  if (f.type === 'text') {
    return `<div class="field settings-field">
      ${labelHtml}
      <input class="input input--text" type="text" id="${fieldId}" data-key="${f.key}"
             value="${escapeHtml(value ?? '')}" ${placeholder ? `placeholder="${escapeHtml(placeholder)}"` : ''}${describedBy}>
      ${hint}
    </div>`;
  }
  // v2.5.3 (FE-02, FE-04) — Text mit Dezimaltastatur statt type="number"
  // („0,5" kam je nach Browser leer an) und escapter Wert (ein als Text
  // gespeicherter Wert brach bisher aus dem Attribut aus). Vorzeichen-Felder
  // ohne inputmode: Die iOS-Zahlentastatur hat kein Minus.
  return `<div class="field settings-field">
    ${labelHtml}
    <input class="input" type="text" ${f.signed ? '' : 'inputmode="decimal" '}autocomplete="off" id="${fieldId}" data-key="${f.key}" data-type="number"
           value="${escapeHtml(formatForInput(value))}"${describedBy}>
    <div class="field-error" id="${fieldId}-err" role="alert" hidden></div>
    ${hint}
  </div>`;
}

// v2.5.3 (FE-02) — Zahlen- und Stichtagsfelder vor dem Speichern prüfen.
// Ein unlesbarer Stichtag wurde bisher still zu 01-01. Liefert das erste
// ungültige Feld (oder null) und markiert alle ungültigen.
function firstInvalidSetting(container) {
  let first = null;
  container.querySelectorAll('[data-type="number"], [data-type="datemd"]').forEach(el => {
    const raw = el.value.trim();
    const isNum = el.getAttribute('data-type') === 'number';
    const ok = raw === '' || (isNum ? parseDecimal(raw) !== null : ddmmToMmdd(raw) !== null);
    const msg = ok ? null
      : isNum ? t('common.invalidNumber', { example: formatForInput(1234.5) })
      : t('settings.dayMonthInvalid');
    el.classList.toggle('invalid', !ok);
    if (ok) el.removeAttribute('aria-invalid'); else el.setAttribute('aria-invalid', 'true');
    const msgEl = container.querySelector(`#${el.id}-err`);
    if (msgEl) { msgEl.textContent = msg || ''; msgEl.hidden = ok; }
    if (!ok && !first) first = el;
  });
  return first;
}

// ── F1012: datierte Gas-Umrechnungsfaktoren ───────────────────────────
//
// Ein Eintrag je Brennwertperiode, wie die Gasrechnung sie ausweist:
// „Gültig ab", Zustandszahl, Brennwert → Faktor. Der undatierte Eintrag ist
// der migrierte Altwert und gilt für alles vor dem ersten Stichtag. Die Liste
// lebt in einem versteckten JSON-Feld, das collectSettings() wie jedes
// andere Feld einsammelt — Speichern läuft über denselben PATCH.

// v2.7.0 — Brennwert wahlweise in MJ/m³ (Vereinigtes Königreich, Niederlande)
// oder GJ/Smc (Italien). Gespeichert wird immer kWh/m³ (lib/gas-factor.js).
function renderGasFactorRows(list, unit = CV_UNITS.kwh) {
  if (!list.length) return `<tr><td colspan="5" class="muted">${t('settings.gasFactors.none')}</td></tr>`;
  return list.map((e, i) => `
    <tr>
      <td>${e.from ? fmt.date(e.from) : `<span class="muted">${t('settings.gasFactors.undated')}</span>`}</td>
      <td class="num">${e.zustandszahl != null ? fmt.num(e.zustandszahl, 4) : '–'}</td>
      <td class="num">${e.brennwert != null ? fmt.num(e.brennwert * unit.perKwh, unit.digits) : '–'}</td>
      <td class="num"><strong>${fmt.num(gasFactorOf(e), 3)}</strong></td>
      <td><button type="button" class="btn btn--ghost btn--sm" data-gf-del="${i}" aria-label="${t('settings.gasFactors.remove')}">${t('settings.gasFactors.remove')}</button></td>
    </tr>`).join('');
}

function renderGasFactors(f, list, hint, settings = {}) {
  const lastZ = [...list].reverse().find(e => e.zustandszahl != null)?.zustandszahl ?? '';
  const unit = cvUnit(settings.gas_cv_unit);
  const countryHint = t('settings.gasFactors.countryHint.' + settings.country);
  return `<div class="field settings-field settings-field--wide" data-gasfactors>
    <label>${t('settings.field.gas_conversion_factors.label')}</label>
    ${hint}
    <input type="hidden" data-key="${f.key}" data-type="json" value="${escapeHtml(JSON.stringify(list))}">
    <div class="form-row" style="margin-top:8px; align-items:flex-end">
      <div class="field">
        <label for="set-gas_cv_unit">${t('settings.gasFactors.cvUnit')}</label>
        <select class="select" id="set-gas_cv_unit" data-key="gas_cv_unit">
          ${Object.entries(CV_UNITS).map(([k, u]) => `<option value="${k}" ${u === unit ? 'selected' : ''}>${u.label}</option>`).join('')}
        </select>
      </div>
    </div>
    <p class="muted" data-gf-mjhint ${unit === CV_UNITS.kwh ? 'hidden' : ''}>${t('settings.gasFactors.mjHint')}</p>
    ${countryHint.startsWith('settings.') ? '' : `<p class="muted">${countryHint}</p>`}
    <div class="table-wrap" style="margin-top:8px">
      <table class="table table--compact" data-gf-table>
        <thead><tr>
          <th scope="col">${t('settings.gasFactors.colFrom')}</th>
          <th scope="col" class="num">${t('settings.gasFactors.colZ')}</th>
          <th scope="col" class="num" data-gf-hs-head>${t(unit.colKey)}</th>
          <th scope="col" class="num">${t('settings.gasFactors.colFactor')}</th>
          <th scope="col"></th>
        </tr></thead>
        <tbody>${renderGasFactorRows(list, unit)}</tbody>
      </table>
    </div>
    <div class="form-row" style="margin-top:10px; align-items:flex-end">
      <div class="field">
        <label for="gf-from">${t('settings.gasFactors.colFrom')}</label>
        <input class="input" id="gf-from" type="date">
      </div>
      <div class="field">
        <label for="gf-z">${t('settings.gasFactors.colZ')}</label>
        <input class="input" id="gf-z" type="text" inputmode="decimal" autocomplete="off" value="${escapeHtml(formatForInput(lastZ, 4))}" placeholder="${escapeHtml(formatForInput(0.96))}">
      </div>
      <div class="field">
        <label for="gf-hs" data-gf-hs-label>${t(unit.colKey)}</label>
        <input class="input" id="gf-hs" type="text" inputmode="decimal" autocomplete="off" placeholder="${escapeHtml(formatForInput(unit.sample, unit.digits))}">
      </div>
      <div class="field">
        <label for="gf-f">${t('settings.gasFactors.colFactorDirect')}</label>
        <input class="input" id="gf-f" type="text" inputmode="decimal" autocomplete="off" placeholder="${escapeHtml(formatForInput(10.8))}">
      </div>
      <div class="field">
        <button type="button" class="btn btn--ghost" data-gf-add>${t('settings.gasFactors.add')}</button>
      </div>
    </div>
    <p class="muted" style="margin-top:6px" data-gf-preview></p>
  </div>`;
}

// Verdrahtet Hinzufügen/Entfernen; hält die Liste im versteckten JSON-Feld.
function wireGasFactors(container) {
  const root = container.querySelector('[data-gasfactors]');
  if (!root) return;
  const hidden = root.querySelector('[data-key="gas_conversion_factors"]');
  const tbody  = root.querySelector('[data-gf-table] tbody');
  const read   = () => { try { return JSON.parse(hidden.value || '[]'); } catch { return []; } };
  const unitSel = root.querySelector('[data-key="gas_cv_unit"]');
  const unit   = () => cvUnit(unitSel?.value);
  const write  = (list) => {
    hidden.value = JSON.stringify(list);
    tbody.innerHTML = renderGasFactorRows(list, unit());
    hidden.dispatchEvent(new Event('input', { bubbles: true }));   // Ungespeichert-Marker
  };
  // v2.5.3 — parseDecimal: `replace(',', '.')` machte aus „1.050,5" 1,05.
  const num = (id) => parseDecimal(root.querySelector('#' + id)?.value ?? '');
  // v2.7.0 — Brennwert immer als kWh/m³ weiterrechnen, auch bei MJ-Eingabe
  const hsKwh = () => {
    const v = num('gf-hs');
    return v && unit() !== CV_UNITS.kwh ? Number((v / unit().perKwh).toFixed(6)) : v;
  };
  unitSel?.addEventListener('change', () => {
    const u = unit();
    root.querySelector('[data-gf-hs-head]').textContent = t(u.colKey);
    root.querySelector('[data-gf-hs-label]').textContent = t(u.colKey);
    root.querySelector('#gf-hs')?.setAttribute('placeholder', formatForInput(u.sample, u.digits));
    root.querySelector('[data-gf-mjhint]').hidden = u === CV_UNITS.kwh;
    tbody.innerHTML = renderGasFactorRows(read(), u);
    preview();
  });
  const preview = () => {
    const z = num('gf-z'), hs = hsKwh(), f = num('gf-f');
    const el = root.querySelector('[data-gf-preview]');
    if (z && hs) el.textContent = t('settings.gasFactors.preview', { z: fmt.num(z, 4), hs: fmt.num(hs, 3), f: fmt.num(z * hs, 3) });
    else if (f) el.textContent = t('settings.gasFactors.previewDirect', { f: fmt.num(f, 3) });
    else el.textContent = '';
  };
  ['gf-z', 'gf-hs', 'gf-f'].forEach(id => root.querySelector('#' + id)?.addEventListener('input', preview));

  root.addEventListener('click', (ev) => {
    const del = ev.target.closest('[data-gf-del]');
    if (del) {
      const list = read();
      list.splice(Number(del.getAttribute('data-gf-del')), 1);
      write(list);
      return;
    }
    if (ev.target.closest('[data-gf-add]')) {
      const from = root.querySelector('#gf-from')?.value || null;
      const z = num('gf-z'), hs = hsKwh(), f = num('gf-f');
      const list = read();
      if (!from && list.some(e => !e.from)) { toastErr(t('settings.gasFactors.errOneUndated')); return; }
      if (from && list.some(e => e.from === from)) { toastErr(t('settings.gasFactors.errDuplicate')); return; }
      let entry;
      if (z && hs)   entry = { from, zustandszahl: z, brennwert: hs, kwh_per_m3: Number((z * hs).toFixed(5)) };
      else if (f)    entry = { from, zustandszahl: null, brennwert: null, kwh_per_m3: f };
      else { toastErr(t('settings.gasFactors.errNeedValues')); return; }
      list.push(entry);
      list.sort((a, b) => (a.from ?? '') < (b.from ?? '') ? -1 : (a.from ?? '') > (b.from ?? '') ? 1 : 0);
      write(list);
      root.querySelector('#gf-from').value = '';
      root.querySelector('#gf-hs').value = '';
      root.querySelector('#gf-f').value = '';
      preview();
    }
  });
}

function collectSettings(container) {
  const out = {};
  container.querySelectorAll('[data-key]').forEach(el => {
    const key = el.getAttribute('data-key');
    if (el.tagName === 'SELECT') out[key] = el.value;
    else if (el.getAttribute('data-type') === 'json') {
      try { out[key] = JSON.parse(el.value || '[]'); } catch { out[key] = []; }
    }
    else if (el.getAttribute('data-type') === 'bool') out[key] = el.checked;
    else if (el.getAttribute('data-type') === 'datemd') {
      // Eingabe TT-MM → kanonisch MM-TT; leer ⇒ Default 01-01. Ungültiges
      // hält firstInvalidSetting() vor dem Speichern auf; hier bleibt der
      // Rohtext stehen, damit der Ungespeichert-Marker die Änderung sieht.
      const v = el.value.trim();
      out[key] = v === '' ? '01-01' : (ddmmToMmdd(v) ?? v);
    }
    else if (el.getAttribute('data-type') === 'number') {
      const v = el.value.trim();
      out[key] = v === '' ? null : (parseDecimal(v) ?? v);
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
                  <td class="num">${fmt.date(c.date)}</td>
                  <td class="num">${fmt.dec(c.counter, 3)}</td>
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
// v2.5.3 — ungültig ⇒ null statt still 01-01 (FE-02); „32-13" wurde
// vorher zu 31.12. geklemmt.
function ddmmToMmdd(v) {
  const m = /^(\d{1,2})[-.\/](\d{1,2})\.?$/.exec(String(v ?? '').trim());
  if (!m) return null;
  let d = parseInt(m[1], 10);
  const mo = parseInt(m[2], 10);
  if (d < 1 || d > 31 || mo < 1 || mo > 12) return null;
  // 29.–31. Februar auf 28 begrenzen (Backend klemmt zusätzlich)
  if (mo === 2 && d > 28) d = 28;
  const p = n => String(n).padStart(2, '0');
  return `${p(mo)}-${p(d)}`; // → MM-TT
}
