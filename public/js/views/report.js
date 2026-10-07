// =====================================================================
// Energietracker v2.11.0 — Jahresbericht
//
// Bis v2.10 stand der Jahresbericht in den Einstellungen zwischen Standort
// und Backup (Review UI-12). Jetzt eine Seite unter „Auswertungen".
// „Im Browser öffnen" lädt das PDF inline in einem neuen Tab (Review UI-25):
// In der Home-Bildschirm-App auf dem iPhone kam ein Download über
// `Content-Disposition: attachment` oft nicht an; die PDF-Ansicht von iOS
// kann teilen und sichern.
//
// v3.1.0 (Review I18N-12) — Hauptweg ist die Druckansicht (#/report/print):
// jede Sprache und Schrift, Zahlen wie in der Oberfläche, „Drucken" sichert
// auch als PDF. Die PDF-Datei bleibt darunter, solange die Sprache in ihren
// Schriften (CP1252) darstellbar ist (format.pdfCharset).
// =====================================================================

import { api } from '../api.js';
import { escapeHtml } from '../lib/format.js';
import { t, getLanguages } from '../lib/i18n.js';
import { getSettings } from '../state.js';

export async function render(container) {
  const now = new Date().getFullYear();
  const years = Array.from({ length: 7 }, (_, i) => now - i);
  const selected = now - 1;
  const pdf = t('format.pdfCharset') !== 'none';
  // v3.1.0 (I18N-29) — das PDF entsteht in der Standardsprache, die Druckansicht in der des Geräts
  const settings = await getSettings().catch(() => ({}));
  const pdfLang = getLanguages()[settings?.language] || '';

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
          <label for="report-year">${t('settings.pdf.year')}</label>
          <select class="select" id="report-year">
            ${years.map(y => `<option value="${y}"${y === selected ? ' selected' : ''}>${y}</option>`).join('')}
          </select>
        </div>
        <div class="field report-actions">
          <a class="btn btn--primary" id="report-print" href="#/report/print?year=${selected}">${escapeHtml(t('report.print.open'))}</a>
        </div>
      </div>
      ${pdf ? `
      <h3 style="margin: var(--sp-4) 0 var(--sp-2); font-size: 1rem">${escapeHtml(t('report.page.pdfHeading'))}</h3>
      <div class="report-actions">
        <a class="btn btn--ghost" id="report-open" target="_blank" rel="noopener" href="${api.yearlyReportUrl(selected, { inline: true })}">${escapeHtml(t('report.page.open'))}</a>
        <a class="btn btn--ghost" id="report-download" href="${api.yearlyReportUrl(selected)}" download>${escapeHtml(t('settings.pdf.download'))}</a>
      </div>
      <p class="muted report-note">${escapeHtml(t('report.page.languageNote', { lang: pdfLang }))} ${escapeHtml(t('report.page.note'))}</p>` : ''}
    </div>`;

  const sel = container.querySelector('#report-year');
  sel.addEventListener('change', () => {
    container.querySelector('#report-print').setAttribute('href', `#/report/print?year=${sel.value}`);
    container.querySelector('#report-open')?.setAttribute('href', api.yearlyReportUrl(sel.value, { inline: true }));
    container.querySelector('#report-download')?.setAttribute('href', api.yearlyReportUrl(sel.value));
  });
}
