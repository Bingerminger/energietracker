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
//
// v3.4.0 (F1025) — Das PDF entsteht in der Sprache dieses Geräts: Der Link
// trägt `lang` (ein Download schickt keinen Sprach-Header und kam bisher in
// der Standardsprache der Installation).
//
// v3.5.0 — Vor dem Öffnen, Drucken oder Herunterladen fragt ein Dialog Jahr,
// Verbrauchsarten, Sprache (PDF) und Anhang (components/report-dialog.js).
// Die Knöpfe bleiben echte Links mit der gemerkten Auswahl dieses Geräts —
// ein Mittelklick oder langes Tippen öffnet sie ohne Dialog.
// =====================================================================

import { escapeHtml } from '../lib/format.js';
import { t, getLanguages, getLocale } from '../lib/i18n.js';
import { docUrl } from '../lib/docs.js';
import { activeUtilities } from '../state.js';
import { openReportDialog, reportHref, resolveChoice, loadReportChoice, reportYears, demoLimits } from '../components/report-dialog.js';

export async function render(container) {
  const pdf = t('format.pdfCharset') !== 'none';
  let active = [];
  try { active = await activeUtilities(); } catch { active = []; }
  const choice = (mode) => {
    const c = resolveChoice(loadReportChoice(), { active, languages: Object.keys(getLanguages()), locale: getLocale(), years: reportYears() });
    const limits = demoLimits(mode);
    return { ...c, utilities: limits.subset ? c.utilities : null, detail: limits.detail && c.detail, billing: limits.billing && c.billing, flex: limits.flex && c.flex };
  };
  const href = (mode) => escapeHtml(reportHref(mode, choice(mode)));

  container.innerHTML = `
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t('nav.report'))}</h1>
        <p class="view-header__subtitle">${escapeHtml(t('report.page.subtitle'))}
          <a id="report-howto" href="${docUrl('report')}" target="_blank" rel="noopener">${escapeHtml(t('report.page.howToRead'))}</a></p>
      </div>
    </div>
    <div class="card">
      <p class="muted">${t('settings.pdf.hint')}</p>
      <div class="report-actions">
        <a class="btn btn--primary" id="report-print" data-report="print" href="${href('print')}">${escapeHtml(t('report.print.open'))}</a>
      </div>
      ${pdf ? `
      <h3 style="margin: var(--sp-4) 0 var(--sp-2); font-size: 1rem">${escapeHtml(t('report.page.pdfHeading'))}</h3>
      <div class="report-actions">
        <a class="btn btn--ghost" id="report-open" data-report="open" target="_blank" rel="noopener" href="${href('open')}">${escapeHtml(t('report.page.open'))}</a>
        <a class="btn btn--ghost" id="report-download" data-report="download" href="${href('download')}" download>${escapeHtml(t('settings.pdf.download'))}</a>
      </div>
      <p class="muted report-note">${escapeHtml(t('report.page.note'))}</p>` : ''}
      <p class="muted report-note">${escapeHtml(t('report.page.choose'))}</p>
    </div>`;

  const refresh = () => {
    for (const a of container.querySelectorAll('[data-report]')) a.setAttribute('href', reportHref(a.dataset.report, choice(a.dataset.report)));
  };
  container.querySelectorAll('[data-report]').forEach(a => a.addEventListener('click', async (e) => {
    // Mittelklick, Strg-/⌘-Klick: der Link mit der gemerkten Auswahl, ohne Dialog
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    await openReportDialog({ mode: a.dataset.report });
    refresh();   // gemerkte Auswahl in die Links
  }));
}
