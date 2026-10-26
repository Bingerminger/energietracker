// =====================================================================
// v3.5.0 — Jahresbericht: die Optionen in einem Dialog (Nachzug F1025).
//
// Bis v3.4 kannten Druckansicht und PDF ihre Optionen nur als Adresse — Jahr,
// Sprache (`lang`), Teilbericht (`utilities`) und Anhang (`detail`); in der
// Oberfläche ließen sich nur Jahr und (in der Druckansicht) der Anhang
// wählen. Jetzt fragt ein Dialog vor dem Öffnen, Drucken oder Herunterladen:
//   • Jahr (dieses und die sechs davor, vorgewählt das Vorjahr),
//   • Verbrauchsarten (nur aktive; alle angehakt = ganzer Bericht),
//   • Sprache (nur fürs PDF — die Druckansicht spricht die Sprache der
//     Oberfläche, ihre Überschriften kommen aus dem Katalog dieses Geräts),
//   • Anhang „Zahlen im Detail“.
// v3.6.0 — dazu „Abrechnungsjahre“ (`billing`), die Seite „Flexibilität“
// (`flex`) und in der Druckansicht „Szenario anhängen“ (`scenario=<Name>`, ein
// in der Prognose-Werkstatt gespeichertes Szenario dieses Geräts).
// Der Dialog erzeugt genau die vorhandenen Parameter (ReportController), der
// Server braucht nichts Neues. Die Wahl merkt sich das Gerät (localStorage),
// außer dem Jahr — das steht jedes Mal wieder auf dem Vorjahr.
//
// Die Aktion ist ein echter Link (<a href>, mit `download` bzw. neuem Tab):
// Ein Klick darauf ist eine Nutzergeste, das braucht der Download am iPhone.
// In der Demo liegt der Bericht nur vollständig vor (fertige Dateien je
// Jahr und Sprache): Teilbericht und Anhang im PDF sind dort gesperrt.
// =====================================================================

import { api } from '../api.js';
import { t, getLocale, getLanguages } from '../lib/i18n.js';
import { escapeHtml as esc } from '../lib/format.js';
import { openModal } from './modal.js';
import { activeUtilities } from '../state.js';
import { DEMO } from '../lib/demo-mode.js';
import { savedScenarios } from '../lib/scenario.js';   // v3.6.0 — Szenario anhängen

export const REPORT_OPTIONS_KEY = 'et-report-options';

/** Jahre zur Auswahl: dieses und die sechs davor (wie bisher). */
export function reportYears(now = new Date().getFullYear(), first = null) {
  // v3.9.0 (L18) — nur Jahre mit Daten: ab dem Jahr der ersten Ablesung (bis v3.8 immer
  // sieben Jahre zurück, auch 2020 bei Daten ab 11/2022); mindestens das laufende Jahr
  const from = Number.isFinite(first) && first <= now ? Math.max(first, now - 6) : now - 6;
  return Array.from({ length: now - from + 1 }, (_, i) => now - i);
}

/** v3.9.0 (L18) — Jahr der ersten Daten über alle Zähler (Zählerstand-Übersicht), sonst null. */
export async function dataFirstYear() {
  try {
    const rows = await api.readingsOverview();
    const years = (Array.isArray(rows) ? rows : rows?.rows || []).map(r => Number(String(r.first_date || '').slice(0, 4))).filter(y => y > 1900);
    return years.length ? Math.min(...years) : null;
  } catch { return null; }
}

/** Gemerkte Auswahl dieses Geräts — oder {} (Speicher gesperrt, kaputt, leer). */
export function loadReportChoice() {
  try {
    const v = JSON.parse(localStorage.getItem(REPORT_OPTIONS_KEY) || '{}');
    return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
  } catch { return {}; }
}

/**
 * Auswahl merken (ohne Jahr). v3.6.0 (F1030) — dazu der Anhang „Abrechnungsjahre“ (`billing`);
 * v3.6.0 — die Seite „Flexibilität“ (`flex`). Ein angehängtes Szenario gilt nur jetzt.
 */
export function saveReportChoice({ utilities = null, lang = '', detail = false, billing = false, flex = false } = {}) {
  try {
    localStorage.setItem(REPORT_OPTIONS_KEY, JSON.stringify({ utilities: utilities?.length ? utilities : null, lang, detail: !!detail, billing: !!billing, flex: !!flex }));
  } catch { /* Speicher gesperrt: gilt nur jetzt */ }
}

/**
 * Eine Auswahl gegen das abgleichen, was es gibt: nur aktive Verbrauchsarten
 * in ihrer Reihenfolge (keine oder alle = ganzer Bericht → null), nur
 * bekannte Sprachen (sonst die der Oberfläche).
 *
 * @param {{utilities?: string[]|null, lang?: string, detail?: boolean, year?: number}} raw
 * @param {{active: Array<{key: string}>, languages: string[], locale: string, years: number[]}} ctx
 */
export function resolveChoice(raw = {}, { active, languages, locale, years }) {
  const keys = active.map(u => u.key);
  const picked = Array.isArray(raw.utilities) ? raw.utilities : [];
  const subset = keys.filter(k => picked.includes(k));
  const year = years.includes(Number(raw.year)) ? Number(raw.year) : (years[1] ?? years[0]);
  return {
    year,
    utilities: subset.length && subset.length < keys.length ? subset : null,
    lang: languages.includes(raw.lang) ? raw.lang : locale,
    detail: !!raw.detail,
    billing: !!raw.billing,
    flex: !!raw.flex,
    scenario: typeof raw.scenario === 'string' && raw.scenario ? raw.scenario : null,
  };
}

/**
 * Adresse einer Aktion — genau die vorhandenen Parameter.
 *   print    → #/report/print?year=…&detail=1&utilities=gas,strom
 *   open     → yearly.pdf?…&inline=1 (neuer Tab)
 *   download → yearly.pdf?… (Datei)
 */
export function reportHref(mode, { year, utilities = null, lang = '', detail = false, billing = false, flex = false, scenario = null }) {
  if (mode === 'print') {
    // v3.6.0 — `flex=1` Seite „Flexibilität“; `scenario=<Name>` ein gespeichertes Szenario (nur Druckansicht, liegt auf dem Gerät)
    return `#/report/print?year=${year}${detail ? '&detail=1' : ''}${billing ? '&billing=1' : ''}${flex ? '&flex=1' : ''}${utilities?.length ? `&utilities=${utilities.join(',')}` : ''}${scenario ? `&scenario=${encodeURIComponent(scenario)}` : ''}`;
  }
  return api.yearlyReportUrl(year, { inline: mode === 'open', lang, detail, utilities, billing, flex });
}

/** Was die Demo kann: dort gibt es nur fertige Dateien des ganzen Berichts. */
export function demoLimits(mode, demo = DEMO) {
  const full = !(demo && mode !== 'print');
  return { subset: !demo, detail: full, billing: full, flex: full };
}

/**
 * Den Dialog öffnen. `mode`: print | open | download. `initial`: Auswahl,
 * mit der er startet (Druckansicht: die der Adresse), sonst die gemerkte.
 *
 * @returns {Promise<string|null>} die gewählte Adresse, null bei Abbruch
 */
export async function openReportDialog({ mode = 'print', initial = null } = {}) {
  let active = [];
  try { active = await activeUtilities(); } catch { active = []; }
  const languages = Object.keys(getLanguages());
  const years = reportYears(new Date().getFullYear(), await dataFirstYear());   // v3.9.0 (L18)
  const limits = demoLimits(mode);
  const pdf = mode !== 'print';
  const c = resolveChoice(initial || loadReportChoice(), { active, languages, locale: getLocale(), years });
  if (!limits.subset) c.utilities = null;
  if (!limits.detail) c.detail = false;
  if (!limits.billing) c.billing = false;
  if (!limits.flex) c.flex = false;
  // v3.6.0 — Szenario anhängen (gespeichert in der Prognose-Werkstatt, auf diesem Gerät): nur Druckansicht
  const scenarios = pdf ? [] : savedScenarios();
  if (!scenarios.some(s => s.name === c.scenario)) c.scenario = null;
  const checked = (k) => !c.utilities || c.utilities.includes(k);
  const action = { print: 'report.print.open', open: 'report.page.open', download: 'settings.pdf.download' }[mode] || 'report.print.open';

  const ctrl = openModal({
    title: t('report.dialog.title'),
    body: `
      <div class="fgrid report-dialog">
        <div class="field field--s">
          <label for="rd-year">${esc(t('settings.pdf.year'))}</label>
          <select class="select" id="rd-year">
            ${years.map(y => `<option value="${y}"${y === c.year ? ' selected' : ''}>${y}</option>`).join('')}
          </select>
        </div>
        ${pdf ? `<div class="field field--m">
          <label for="rd-lang">${esc(t('report.dialog.language'))}</label>
          <select class="select" id="rd-lang">
            ${Object.entries(getLanguages()).map(([code, name]) => `<option value="${esc(code)}"${code === c.lang ? ' selected' : ''}>${esc(name)}</option>`).join('')}
          </select>
        </div>` : `<p class="muted small report-dialog__lang">${esc(t('report.dialog.printLanguage', { lang: getLanguages()[getLocale()] || getLocale() }))}</p>`}
        <fieldset class="fgroup"${limits.subset ? '' : ' disabled'} aria-describedby="rd-util-hint">
          <legend>${esc(t('report.dialog.utilities'))}</legend>
          <div class="check-grid">
            ${active.map(u => `<label class="check"><input type="checkbox" name="rd-util" value="${esc(u.key)}"${checked(u.key) ? ' checked' : ''}> <span aria-hidden="true">${esc(u.icon || '')}</span> ${esc(u.label)}</label>`).join('')}
          </div>
          <p class="fgroup__hint" id="rd-util-hint">${esc(t(limits.subset ? 'report.dialog.utilitiesHint' : 'report.dialog.demoNote'))}</p>
          <div class="field-error" id="rd-util-msg" role="alert" hidden></div>
        </fieldset>
        <div class="field field--full">
          <label class="check"><input type="checkbox" id="rd-detail"${c.detail ? ' checked' : ''}${limits.detail ? '' : ' disabled'}> ${esc(t('report.print.detail'))}</label>
        </div>
        <div class="field field--full">
          <label class="check"><input type="checkbox" id="rd-billing" aria-describedby="rd-billing-hint"${c.billing ? ' checked' : ''}${limits.billing ? '' : ' disabled'}> ${esc(t('report.dialog.billing'))}</label>
          <p class="fgroup__hint" id="rd-billing-hint">${esc(t('report.dialog.billingHint'))}</p>
        </div>
        <div class="field field--full">
          <label class="check"><input type="checkbox" id="rd-flex" aria-describedby="rd-flex-hint"${c.flex ? ' checked' : ''}${limits.flex ? '' : ' disabled'}> ${esc(t('report.dialog.flex'))}</label>
          <p class="fgroup__hint" id="rd-flex-hint">${esc(t('report.dialog.flexHint'))}</p>
        </div>
        ${scenarios.length ? `<div class="field field--full">
          <label for="rd-scenario">${esc(t('report.dialog.scenario'))}</label>
          <select class="select" id="rd-scenario" aria-describedby="rd-scenario-hint">
            <option value="">${esc(t('report.dialog.scenarioNone'))}</option>
            ${scenarios.map(s => `<option value="${esc(s.name)}"${s.name === c.scenario ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}
          </select>
          <p class="fgroup__hint" id="rd-scenario-hint">${esc(t('report.dialog.scenarioHint'))}</p>
        </div>` : ''}
      </div>
      <p class="muted small">${esc(t('report.dialog.remembered'))}</p>`,
    footer: `
      <button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
      <a class="btn btn--primary" data-act="go" href="#"${mode === 'download' ? ' download' : ''}${mode === 'open' ? ' target="_blank" rel="noopener"' : ''}>${esc(t(action))}</a>`,
    onMount({ modalEl, close }) {
      // Abbrechen zuerst binden (Lektion 8)
      modalEl.querySelector('[data-act="cancel"]')?.addEventListener('click', () => close(null));
      const go = modalEl.querySelector('[data-act="go"]');
      const msg = modalEl.querySelector('#rd-util-msg');
      const read = () => {
        const boxes = [...modalEl.querySelectorAll('input[name="rd-util"]')];
        const on = boxes.filter(b => b.checked).map(b => b.value);
        return {
          year: Number(modalEl.querySelector('#rd-year')?.value) || c.year,
          utilities: on.length && on.length < boxes.length ? on : null,
          none: boxes.length > 0 && on.length === 0,
          lang: modalEl.querySelector('#rd-lang')?.value || c.lang,
          detail: !!modalEl.querySelector('#rd-detail')?.checked,
          billing: !!modalEl.querySelector('#rd-billing')?.checked,
          flex: !!modalEl.querySelector('#rd-flex')?.checked,
          scenario: modalEl.querySelector('#rd-scenario')?.value || null,
        };
      };
      const sync = () => {
        const v = read();
        go.setAttribute('href', reportHref(mode, v));
        go.setAttribute('aria-disabled', v.none ? 'true' : 'false');
        if (!v.none && msg) msg.hidden = true;
      };
      modalEl.querySelector('.modal__body')?.addEventListener('change', sync);
      go.addEventListener('click', (e) => {
        const v = read();
        if (v.none) {
          e.preventDefault();
          if (msg) { msg.textContent = t('report.dialog.utilitiesNone'); msg.hidden = false; }
          modalEl.querySelector('input[name="rd-util"]')?.focus();
          return;
        }
        saveReportChoice(v);
        const href = go.getAttribute('href');
        if (mode === 'print') {
          // Navigation: schließen ohne history.back(), dann springen (Lektion 64)
          e.preventDefault();
          close(href, 'navigation');
          location.hash = href;
          return;
        }
        // PDF: Der Link selbst öffnet den Tab bzw. lädt die Datei (Nutzergeste);
        // geschlossen wird danach, sonst hinge der Link nicht mehr im Dokument
        setTimeout(() => close(href, 'ui'), 0);
      });
      sync();
    },
  });
  return ctrl.closedPromise.then(v => (typeof v === 'string' ? v : null));
}
