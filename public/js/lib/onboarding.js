// =====================================================================
// v2.13.0 (Review UI-32, DOC-31) — Erste Schritte.
//
// Eine Checkliste, deren Häkchen sich aus den Daten ergeben, nicht von Hand:
// Ort gesetzt, erster Stand, zweiter Stand (erst dann gibt es Verbrauch),
// Vertrag, optional Home Assistant. Dieselbe Liste steht auf der leeren
// Übersicht und in der Hilfe.
// =====================================================================
import { api } from '../api.js';
import { getSettings, getCountries, getUtilities } from '../state.js';
import { t } from './i18n.js';
import { escapeHtml } from './format.js';

/**
 * @returns {Promise<Array<{id: string, done: boolean, href: string, optional?: boolean}>>}
 */
export async function setupSteps() {
  const [settings, countries, utilities, overview, ha] = await Promise.all([
    getSettings().catch(() => ({})),
    getCountries().catch(() => []),
    getUtilities().catch(() => []),
    api.readingsOverview().catch(() => null),
    api.authStatus().catch(() => null),
  ]);

  // Steht der Standort noch auf der Voreinstellung des Landes, rechnen
  // Gradtage und Prognose mit dem Wetter eines anderen Orts.
  const profile = (countries || []).find(c => c.code === settings?.country);
  const locationDefault = !!profile
    && Number(settings.latitude) === Number(profile.latitude)
    && Number(settings.longitude) === Number(profile.longitude);

  const rows = overview?.rows || [];
  const steps = [{ id: 'location', done: !locationDefault, href: '#/temperatures' }];
  if (rows.length) {
    steps.push({ id: 'firstReading', done: rows.some(r => r.last_reading), href: '#/zaehlerstaende' });
    steps.push({ id: 'secondReading', done: rows.some(r => (r.reading_count || 0) >= 2), href: '#/zaehlerstaende' });
  }

  // Ein echter Vertrag (kein Angebot) bei irgendeiner Verbrauchsart mit Verträgen
  const withContracts = (utilities || []).filter(u => u.has_contracts !== false && u.reading_kind !== 'delivery');
  const lists = await Promise.all(withContracts.map(u => api.contracts(u.key).catch(() => [])));
  const hasContract = lists.some(list => (list || []).some(c => !c.is_shadow));
  if (withContracts.length) steps.push({ id: 'contract', done: hasContract, href: '#/contracts' });

  steps.push({ id: 'homeAssistant', done: !!(ha?.enabled && ha?.last_used_at), href: '#/settings/integrations', optional: true });
  return steps;
}

/** Offene Pflichtschritte (ohne die optionalen). */
export const openSteps = (steps) => steps.filter(s => !s.done && !s.optional);

export function setupListHtml(steps) {
  return `
    <ol class="setup-list">
      ${steps.map((s, i) => `
      <li class="setup-list__item${s.done ? ' setup-list__item--done' : ''}">
        <span class="setup-list__mark" aria-hidden="true">${s.done ? '✓' : i + 1}</span>
        <span class="setup-list__body">
          <span><a href="${s.href}">${escapeHtml(t(`onboarding.step.${s.id}.title`))}</a>${s.optional ? ` <span class="muted small">(${escapeHtml(t('onboarding.optional'))})</span>` : ''}</span>
          <span class="muted small">${escapeHtml(t(`onboarding.step.${s.id}.text`))}</span>
        </span>
        <span class="sr-only">${escapeHtml(t(s.done ? 'onboarding.done' : 'onboarding.open'))}</span>
      </li>`).join('')}
    </ol>`;
}
