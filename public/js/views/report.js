// =====================================================================
// Energietracker v2.11.0 — Jahresbericht (PDF)
//
// Bis v2.10 stand der Jahresbericht in den Einstellungen zwischen Standort
// und Backup (Review UI-12). Jetzt eine Seite unter „Auswertungen".
// „Im Browser öffnen" lädt das PDF inline in einem neuen Tab (Review UI-25):
// In der Home-Bildschirm-App auf dem iPhone kam ein Download über
// `Content-Disposition: attachment` oft nicht an; die PDF-Ansicht von iOS
// kann teilen und sichern.
// =====================================================================

import { api } from '../api.js';
import { escapeHtml } from '../lib/format.js';
import { t } from '../lib/i18n.js';

export async function render(container) {
  const now = new Date().getFullYear();
  const years = Array.from({ length: 7 }, (_, i) => now - i);
  const selected = now - 1;

  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('nav.report'))}</h1>
        <p class="view-header__subtitle">${escapeHtml(t('report.page.subtitle'))}</p>
      </div>
    </div>
    <div class="card">
      <p class="muted">${t('settings.pdf.hint')}</p>
      <div class="form-row" style="align-items:flex-end">
        <div class="field">
          <label for="report-year">${escapeHtml(t('settings.pdf.year'))}</label>
          <select class="select" id="report-year">
            ${years.map(y => `<option value="${y}"${y === selected ? ' selected' : ''}>${y}</option>`).join('')}
          </select>
        </div>
        <div class="field report-actions">
          <a class="btn btn--primary" id="report-open" target="_blank" rel="noopener" href="${api.yearlyReportUrl(selected, { inline: true })}">${escapeHtml(t('report.page.open'))}</a>
          <a class="btn btn--ghost" id="report-download" href="${api.yearlyReportUrl(selected)}" download>${escapeHtml(t('settings.pdf.download'))}</a>
        </div>
      </div>
      <p class="muted report-note">${escapeHtml(t('report.page.note'))}</p>
    </div>`;

  const sel = container.querySelector('#report-year');
  sel.addEventListener('change', () => {
    container.querySelector('#report-open').setAttribute('href', api.yearlyReportUrl(sel.value, { inline: true }));
    container.querySelector('#report-download').setAttribute('href', api.yearlyReportUrl(sel.value));
  });
}
