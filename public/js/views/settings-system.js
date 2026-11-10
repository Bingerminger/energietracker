// =====================================================================
// Einstellungen › System: Über, Unterstützen, Diagnose.
// v3.12.0 — aus views/settings.js ausgelagert, Verhalten unverändert.
// =====================================================================
import { fmt, escapeHtml } from '../lib/format.js';
import { t, tp } from '../lib/i18n.js';
import { icon } from '../lib/icons.js';


// ── Seite „System": Version und Verweise (v2.12.0, Review UI-29) ──────────
export function renderAbout(diag) {
  const version = diag?.app_version || document.body.getAttribute('data-app-version') || '';
  // v3.0.0 — AGPL §13: der Quellcode genau dieser Version ist einen Klick entfernt
  // (Tag vX.Y.Z); ohne lesbare Version das Repository.
  const repo = 'https://github.com/Bingerminger/energietracker';
  const source = /^\d+\.\d+\.\d+$/.test(version) ? `${repo}/tree/v${version}` : repo;
  return `
    <div class="card settings-card" id="about-card" data-field="about">
      <h2 class="card__title">${icon('info')} ${escapeHtml(t('settings.system.aboutTitle'))}</h2>
      <p>${escapeHtml(t('settings.system.about', { version }))}</p>
      <p class="muted">${escapeHtml(t('settings.system.license'))}
        · <a href="${escapeHtml(source)}" target="_blank" rel="noopener" data-source-link>${escapeHtml(t('settings.system.source'))}</a>
        · <a href="${repo}" target="_blank" rel="noopener">GitHub</a></p>
    </div>`;
}


// v3.4.0 (GitHub #28) — Wer das Projekt unterstützen möchte, findet hier den
// Weg; freiwillig, ohne Gegenleistung, nichts in der App hängt daran. Der
// Link öffnet einen neuen Tab und gibt keine Herkunft weiter.
const SUPPORT_URL = 'https://buymeacoffee.com/bingerminger';

export function renderSupportCard() {
  return `
    <div class="card settings-card" id="support-card" data-field="support">
      <h2 class="card__title">${icon('tasse')} ${escapeHtml(t('settings.support.title'))}</h2>
      <p>${escapeHtml(t('settings.support.text'))}</p>
      <a class="btn btn--ghost support-card__link" href="${SUPPORT_URL}" target="_blank" rel="noopener noreferrer" data-support-link>${escapeHtml(t('settings.support.link'))}</a>
    </div>`;
}


export function renderDiagnostics(d) {
  return `
    <div class="card settings-card settings-card--wide" id="diag-card" data-field="diagnostics">
      <h2 class="card__title">${icon('puls')} ${t('settings.diag.title')}</h2>
      <dl class="diag-grid">
        ${renderDiagRow(t('settings.diag.appVersion'),    d.app_version,    'mono')}
        ${renderDiagRow(t('settings.diag.schemaVersion'), d.schema_version, 'mono')}
        ${renderDiagRow(t('settings.diag.phpVersion'),    d.php_version,    'mono')}
        ${renderDiagRow(t('settings.diag.dataDir'),       d.data_dir,       'mono path')}
        ${renderDiagRow(t('settings.diag.writable'), renderBool(d.data_dir_writable))}
        ${renderDiagRow(t('settings.diag.curl'),     renderBool(d.curl_available))}
        ${d.home_tls ? renderDiagRow(t('settings.diag.homeCa'), homeTlsText(d.home_tls)) : ''}
        ${renderDiagRow(t('settings.diag.timezone'),      d.time_zone,      'mono')}
        ${renderDiagRow(t('settings.diag.serverTime'),    fmt.date(String(d.now).slice(0,10)) + ' ' + String(d.now).slice(11,19), 'mono')}        ${renderDiagRow(t('settings.diag.migrationNeeded'), renderBool(d.migration_needed, /*inverted*/ true))}
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
      <p class="diag-line">${tp('settings.diag.tempStored', d.temperatures?.rows ?? 0, { count: fmt.int(d.temperatures?.rows ?? 0) })}</p>

      <h4 class="diag-subhead">${t('settings.diag.knownKeys')}
        <span class="muted" style="font-weight:400">(${(d.settings_known_keys || []).length})</span>
      </h4>
      <div class="diag-chips">
        ${(d.settings_known_keys || []).map(k => `<code class="chip">${escapeHtml(k)}</code>`).join('')}
      </div>
    </div>
  `;
}


// v3.10.0 (#36) — eigene Stammzertifikate (ET_CA_FILE und Einstellung), Datei
// nicht lesbar, Prüfung im Heimnetz aus: eine Zeile, ohne Pfad
function homeTlsText(h) {
  const n = (h.ca_file_certs || 0) + (h.ca_settings_certs || 0);
  const parts = [tp('settings.diag.homeCaCount', n, { count: fmt.int(n) })];
  if (h.ca_file === 'unreadable') parts.push(t('settings.diag.homeCaFileUnreadable'));
  if (h.ca_file === 'invalid') parts.push(t('settings.diag.homeCaFileInvalid'));
  if (h.insecure_local) parts.push(t('settings.diag.homeTlsInsecure'));
  return parts.join(' · ');
}


// v3.4.0 (N1020) — Werte werden escaped; fertiges HTML (renderBool) kommt als
// { html } und ist damit als solches gekennzeichnet. Bis v3.3 ging jeder Wert
// roh in die Seite, auch der Pfad des Datenverzeichnisses.
function renderDiagRow(label, value, valueClass = '') {
  const shown = value === null || value === undefined || value === '' ? '<span class="dim">–</span>'
    : typeof value === 'object' && typeof value.html === 'string' ? value.html
    : escapeHtml(String(value));
  return `
    <dt>${escapeHtml(label)}</dt>
    <dd class="${escapeHtml(valueClass)}">${shown}</dd>
  `;
}


// Render a boolean as a colored pill. When `inverted` is true, `false` is the
// good/green state (used for "Migration nötig" — false = nothing to do).
function renderBool(v, inverted = false) {
  const truthy = !!v;
  const good = inverted ? !truthy : truthy;
  return { html: `<span class="badge badge--${good ? 'success' : 'warning'}">${escapeHtml(truthy ? t('settings.diag.yes') : t('settings.diag.no'))}</span>` };
}
