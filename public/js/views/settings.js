// =====================================================================
// Energietracker v1.2.0 — Settings view
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
      { key: 'co2_fernwaerme', label: 'CO₂ Fernwärme', unit: 'g/kWh', step: '1' },
      { key: 'co2_heizoel',    label: 'CO₂ Heizöl',    unit: 'g/L',   step: '1' },
      { key: 'co2_pellets',    label: 'CO₂ Pellets',   unit: 'g/kg',  step: '1' },
    ],
  },
  {
    title: 'Abrechnungszyklus',
    icon: '📅',
    hint: 'Stichtag der Jahresabrechnung je Verbrauchsart (Format TT-MM, '
        + 'z. B. 01-01 für 1. Januar, 15-04 für 15. April). '
        + 'Bestimmt, bis wann der Saldo offener Verträge projiziert wird; '
        + 'Verträge mit gepflegtem Ende nutzen weiterhin dieses Ende.',
    fields: [
      { key: 'billing_cycle_anchor_gas',    label: 'Stichtag Gas', type: 'datemd', placeholder: 'TT-MM (01-01)' },
      { key: 'billing_cycle_anchor_strom',  label: 'Stichtag Strom', type: 'datemd', placeholder: 'TT-MM (01-01)' },
      { key: 'billing_cycle_anchor_wasser', label: 'Stichtag Wasser', type: 'datemd', placeholder: 'TT-MM (01-01)' },
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
        options: ['linear', 'polynomial', 'robust', 'segmented', 'sigmoid'] },
      { key: 'segmented_split_mode',   label: 'Segment-Knickpunkt', type: 'select',
        options: ['auto', 'fixed'],
        hint: 'auto = datenbasiert gefittet, fixed = fester HGT-Wert unten.' },
      { key: 'segmented_fixed_split',  label: 'Fester Knickpunkt', unit: 'HGT', step: '1',
        hint: 'Nur wirksam bei Modus „fixed".' },
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
    title: 'Gebäude & Effizienz',
    icon: '🏢',
    hint: 'Bezugsgrößen für die Effizienzklasse (kWh/m²·a) und den Heizenergie-Benchmark.',
    fields: [
      { key: 'wohnflaeche_m2', label: 'Wohnfläche', unit: 'm²', step: '1',
        hint: 'Beheizte Fläche — Nenner der Effizienzkennzahl.' },
      { key: 'baujahr',        label: 'Baujahr', type: 'text', placeholder: 'z. B. 1998' },
      { key: 'gebaeudetyp',    label: 'Gebäudetyp', type: 'select',
        options: ['efh', 'rh', 'mfh', 'whg'],
        hint: 'efh = Einfam., rh = Reihenhaus, mfh = Mehrfam., whg = Wohnung.' },
    ],
  },
  {
    title: 'Energieträger (Lieferung)',
    icon: '🛢️',
    hint: 'Energiegehalt der lieferbasierten Brennstoffe und Tank-Warnschwelle.',
    fields: [
      { key: 'heizoel_kwh_per_l', label: 'Heizöl Energiegehalt', unit: 'kWh/L', step: '0.1' },
      { key: 'pellets_kwh_per_kg', label: 'Pellets Energiegehalt', unit: 'kWh/kg', step: '0.1' },
      { key: 'delivery_baseload_share', label: 'Sockel-Anteil Verteilung', step: '0.05',
        hint: 'Anteil des Verbrauchs als wetterunabhängige Grundlast (Rest HGT-gewichtet).' },
      { key: 'tank_warn_pct', label: 'Tank-Warnung ab', unit: '% Restbestand', step: '1' },
    ],
  },
  {
    title: 'Termine & Empfehlungen',
    icon: '📌',
    hint: 'Schwellen für Fälligkeits-Status und die Empfehlungs-Engine.',
    fields: [
      { key: 'reminder_warn_days_before', label: 'Termin „bald fällig" ab', unit: 'Tage vorher', step: '1' },
      { key: 'reminder_overdue_days',     label: 'Kulanz bis „überfällig"', unit: 'Tage', step: '1' },
      { key: 'recommendation_anomaly_sigma', label: 'Empfehlung Anomalie-Schwelle', unit: 'σ', step: '0.1' },
      { key: 'recommendation_trend_pct_year', label: 'Empfehlung Trend-Schwelle', unit: '%/Jahr', step: '0.5' },
      { key: 'billing_cycle_anchor_fernwaerme', label: 'Stichtag Fernwärme', type: 'datemd', placeholder: 'TT-MM (01-01)' },
      { key: 'billing_cycle_anchor_heizoel',    label: 'Stichtag Heizöl', type: 'datemd', placeholder: 'TT-MM (01-01)' },
      { key: 'billing_cycle_anchor_pellets',    label: 'Stichtag Pellets', type: 'datemd', placeholder: 'TT-MM (01-01)' },
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
  const [settings, diag, utilities] = await Promise.all([
    api.settings(),
    api.diagnostics().catch(() => null),
    api.listUtilities().catch(() => []),
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

    <div class="card settings-card">
      <h3 class="card__title">🧩 Aktive Verbrauchsarten</h3>
      <p class="settings-card__hint">Nur angehakte Verbrauchsarten erscheinen in Sidebar,
        Dashboard und Jahresbericht. Daten inaktiver Arten bleiben erhalten.</p>
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
      <h3 class="card__title">📄 PDF-Jahresbericht</h3>
      <p class="muted" style="margin-bottom: var(--sp-3)">
        Mehrseitiger Bericht mit Übersicht, Effizienzklasse, Verbrauchstabellen
        je Verbrauchsart und offenen Empfehlungen.
      </p>
      <div class="section-actions">
        <label>Jahr
          <select class="select" id="pdf-year" style="margin-left: var(--sp-2)">
            ${pdfYearOpts()}
          </select>
        </label>
        <a class="btn btn--primary" id="pdf-dl" href="${api.yearlyReportUrl(new Date().getFullYear() - 1)}">
          Jahresbericht herunterladen
        </a>
      </div>
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
            ${(utilities || [])
              .filter(u => exportActive(settings, u.key))
              .map(u => `<a class="btn btn--sm btn-${u.key}" href="${api.exportMonthlyCsvUrl(u.key)}" download>${escapeHtml(u.label)}</a>`)
              .join('') || '<span class="muted" style="font-size:12px">Keine aktive Verbrauchsart.</span>'}
          </div>
        </div>
        <div class="export-tile">
          <div class="export-tile__label">Zählerstände / Lieferungen</div>
          <div class="export-tile__hint">Kumulative Arten: Rohablesungen. Heizöl/Pellets: Brennstofflieferungen (eine Zeile je Lieferung).</div>
          <div class="export-tile__actions">
            ${(utilities || [])
              .filter(u => exportActive(settings, u.key))
              .map(u => {
                const isDelivery = u.reading_kind === 'delivery';
                const url = isDelivery ? api.exportDeliveriesCsvUrl(u.key) : api.exportReadingsCsvUrl(u.key);
                const tag = isDelivery ? ' (Lieferungen)' : '';
                return `<a class="btn btn--sm btn-${u.key}" href="${url}" download>${escapeHtml(u.label)}${tag}</a>`;
              })
              .join('') || '<span class="muted" style="font-size:12px">Keine aktive Verbrauchsart.</span>'}
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
        <button class="btn btn--ghost" id="btn-demo">Demo-Daten laden</button>
      </div>
      <p class="settings-card__hint" style="margin-top:.5rem">
        „Demo-Daten laden" spielt einen vollständigen Beispieldatensatz ein —
        ideal zum Ausprobieren in einem leeren Energietracker. Sind bereits
        Daten vorhanden, wird vorher gewarnt und automatisch ein Snapshot
        angelegt.
      </p>

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
    try { const r = await api.snapshotBackup(); toastOk(`Snapshot: ${r.file || r.path || 'ok'}`); }
    catch (e) { toastErr(e.message); }
  });

  // F1007 — Demo-Daten-Komfort-Import
  container.querySelector('#btn-demo').addEventListener('click', async () => {
    try {
      const status = await api.demoStatus();
      if (!status.available) {
        toastErr('Demo-Daten sind in dieser Installation nicht verfügbar.');
        return;
      }
      if (!status.is_empty) {
        const ok = await confirmModal({
          title: 'Demo-Daten laden?',
          message: 'Es sind bereits Daten vorhanden. Der Import überschreibt sie. '
                 + 'Vorher wird automatisch ein Sicherheits-Snapshot deiner aktuellen '
                 + 'Daten angelegt, den du jederzeit wieder einspielen kannst.',
          confirmLabel: 'Demo-Daten laden', danger: true,
        });
        if (!ok) return;
      }
      const report = await api.importDemo(!status.is_empty);
      const snap = report?.auto_snapshot_before_restore;
      invalidateSettings();
      if (typeof snap === 'string') toastOk(`Demo-Daten geladen. Sicherheits-Snapshot: ${snap}`);
      else toastOk('Demo-Daten geladen.');
      render(container);
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-import').addEventListener('click',
    () => container.querySelector('#import-file').click());
  container.querySelector('#import-file').addEventListener('change', async (e) => {
    const f = e.target.files[0]; if (!f) return;
    const ok = await confirmModal({
      title: 'Backup importieren?',
      message: 'Achtung: bestehende Daten werden überschrieben.\n\n'
             + 'Vor dem Import legt die App automatisch einen Sicherheits-'
             + 'Snapshot deiner aktuellen Daten unter '
             + '<code>data/backups/pre-restore-…</code> ab — falls der '
             + 'Import nicht so aussieht wie erwartet, kannst du diesen '
             + 'Snapshot wieder einspielen.',
      confirmLabel: 'Importieren', danger: true,
    });
    if (!ok) { e.target.value = ''; return; }
    try {
      const text = await f.text();
      const data = JSON.parse(text);
      const report = await api.importBackup(data);
      const snap = report?.auto_snapshot_before_restore;
      if (typeof snap === 'string') {
        toastOk(`Backup importiert. Sicherheits-Snapshot: ${snap}`);
      } else {
        toastOk('Backup importiert');
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
  if (f.type === 'datemd') {
    // Speicherung kanonisch MM-TT (für valide Datumskonstruktion im
    // Backend), Anzeige im deutschen Format TT-MM.
    const disp = mmddToDdmm(value);
    return `<div class="field settings-field">
      ${labelHtml}
      <input class="input input--text" type="text" data-key="${f.key}" data-type="datemd"
             value="${escapeHtml(disp)}" ${f.placeholder ? `placeholder="${escapeHtml(f.placeholder)}"` : ''}>
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
      <h3 class="card__title">🩺 System-Diagnose</h3>
      <dl class="diag-grid">
        ${renderDiagRow('App-Version',       d.app_version,    'mono')}
        ${renderDiagRow('Schema-Version',    d.schema_version, 'mono')}
        ${renderDiagRow('PHP-Version',       d.php_version,    'mono')}
        ${renderDiagRow('Datenverzeichnis',  d.data_dir,       'mono path')}
        ${renderDiagRow('Verzeichnis schreibbar', renderBool(d.data_dir_writable))}
        ${renderDiagRow('cURL verfügbar',    renderBool(d.curl_available))}
        ${renderDiagRow('Zeitzone',          d.time_zone,      'mono')}
        ${renderDiagRow('Server-Zeit',       fmt.date(String(d.now).slice(0,10)) + ' ' + String(d.now).slice(11,19), 'mono')}
        ${renderDiagRow('Migration nötig',   renderBool(d.migration_needed, /*inverted*/ true))}
      </dl>

      <h4 class="diag-subhead">Verbrauchsarten</h4>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr>
          <th>Verbrauchsart</th>
          <th class="num">Zähler</th>
          <th class="num">Ablesungen</th>
          <th class="num">Verträge</th>
          <th>Letzte Ablesung</th>
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

      <h4 class="diag-subhead">Temperaturreihe</h4>
      <p class="diag-line"><strong>${fmt.int(d.temperatures?.rows ?? 0)}</strong> Tageswerte gespeichert.</p>

      <h4 class="diag-subhead">Bekannte Settings-Schlüssel
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
  return `<span class="badge badge--${good ? 'success' : 'warning'}">${truthy ? 'ja' : 'nein'}</span>`;
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
