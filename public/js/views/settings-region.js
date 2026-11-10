// =====================================================================
// Einstellungen › Region und Sprache, dazu Sprache und Darstellung unter „Für dich“.
// v3.12.0 — aus views/settings.js ausgelagert, Verhalten unverändert.
// =====================================================================
import { escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { openModal } from '../components/modal.js';
import { t, getLocale, getLanguages, deviceLanguage, resolveLanguage } from '../lib/i18n.js';
import { saveRegion } from '../lib/region.js';
import { icon } from '../lib/icons.js';
import { currentMode, applyTheme } from '../lib/theme.js';

/**
 * v3.12.0 (Sichtung S10) — Karte Sprache und Region: oben, was gerade gilt und warum, dann getrennt „Für dich“ und „Für alle“.
 * Die Sätze folgen `resolveLanguage`, dieselbe Reihenfolge wie beim Start der App. Die Vorgabe für alle ändern nur
 * Verwalter (`ADMIN_SETTINGS`), Mitglieder sehen sie gesperrt; ohne Anmeldung gibt es keinen Unterschied.
 */
export function renderLanguageCard(settings, session, countries) {
  const names = getLanguages();
  const signedIn = !!(session?.user && session.mode !== 'off');
  const person = signedIn ? (session.user?.prefs?.language || null) : null;
  const device = deviceLanguage();
  const own = person || device;
  const active = resolveLanguage({ person, device, installation: settings.language || getLocale() });
  const defaultName = names[settings.language] || names[getLocale()] || '';
  const dis = (session?.role || 'admin') === 'admin' ? '' : ' disabled';
  return `<div class="card settings-card" id="lang-card">
    <h2 class="card__title">${icon('globus')} ${t('settings.lang.title')}</h2>
    <p class="infobox infobox--neutral" data-role="lang-effective" data-source="${active.source}">
      <strong>${escapeHtml(t('settings.lang.effective', { lang: names[active.lang] || active.lang || '' }))}</strong>
      ${escapeHtml(t(`settings.lang.why.${active.source}`))}
    </p>
    <h3 class="settings-subhead" data-role="lang-for-you">${escapeHtml(t('settings.lang.forYou'))}</h3>
    <div class="settings-fields">
      <div class="field settings-field">
        <label for="lang-select">${escapeHtml(t('settings.lang.label'))}</label>
        <select class="select" id="lang-select">
          <option value="" ${own ? '' : 'selected'}>${escapeHtml(t('settings.lang.deviceFollow', { lang: defaultName }))}</option>
          ${Object.entries(names).map(([code, name]) => `<option value="${code}" ${code === own ? 'selected' : ''}>${escapeHtml(name)}</option>`).join('')}
        </select>
        <span class="settings-field__hint" data-role="lang-own-hint">${escapeHtml(t(signedIn ? 'settings.lang.personHint' : 'settings.lang.deviceHint'))}</span>
      </div>
    </div>
    <hr class="settings-rule">
    <h3 class="settings-subhead" data-role="lang-for-all">${escapeHtml(t('settings.lang.forAll'))}</h3>
    <p class="settings-card__hint"${dis ? ' data-role="region-admin-only"' : ''}>${escapeHtml(t('settings.lang.forAllHint'))}</p>
    <div class="settings-fields">
      <div class="field settings-field">
        <label for="lang-default-select">${escapeHtml(t('settings.lang.defaultLabel'))}</label>
        <select class="select" id="lang-default-select" data-field="language"${dis}>
          ${Object.entries(names).map(([code, name]) => `<option value="${code}" ${code === (settings.language || getLocale()) ? 'selected' : ''}>${escapeHtml(name)}</option>`).join('')}
        </select>
        <span class="settings-field__hint">${escapeHtml(t('settings.lang.defaultHint'))}</span>
      </div>
      ${renderRegionFields(settings, countries, dis)}
    </div>
  </div>`;
}


/**
 * v3.12.0 (M3) — „Für dich“: dieselbe Wahl wie in der Karte Region und Sprache (`#lang-select`, ein Weg in
 * settings.js), ohne die Vorgaben für alle. Die Sätze folgen wie dort `resolveLanguage`.
 */
export function renderOwnLanguageCard(settings, session) {
  const names = getLanguages();
  const signedIn = !!(session?.user && session.mode !== 'off');
  const person = signedIn ? (session.user?.prefs?.language || null) : null;
  const device = deviceLanguage();
  const own = person || device;
  const active = resolveLanguage({ person, device, installation: settings.language || getLocale() });
  const defaultName = names[settings.language] || names[getLocale()] || '';
  return `<div class="card settings-card" id="own-lang-card">
    <h2 class="card__title">${icon('globus')} ${escapeHtml(t('settings.lang.label'))}</h2>
    <p class="infobox infobox--neutral" data-role="lang-effective" data-source="${active.source}">
      <strong>${escapeHtml(t('settings.lang.effective', { lang: names[active.lang] || active.lang || '' }))}</strong>
      ${escapeHtml(t(`settings.lang.why.${active.source}`))}
    </p>
    <div class="settings-fields">
      <div class="field settings-field">
        <label for="lang-select">${escapeHtml(t('settings.lang.label'))}</label>
        <select class="select" id="lang-select" data-field="device_language">
          <option value="" ${own ? '' : 'selected'}>${escapeHtml(t('settings.lang.deviceFollow', { lang: defaultName }))}</option>
          ${Object.entries(names).map(([code, name]) => `<option value="${code}" ${code === own ? 'selected' : ''}>${escapeHtml(name)}</option>`).join('')}
        </select>
        <span class="settings-field__hint" data-role="lang-own-hint">${escapeHtml(t(signedIn ? 'settings.lang.personHint' : 'settings.lang.deviceHint'))}</span>
      </div>
    </div>
  </div>`;
}

/** v3.12.0 (M3) — Hell, Dunkel oder wie das System: derselbe Weg wie der Knopf oben (lib/theme.js), gilt für dieses Gerät. */
export function renderThemeCard() {
  const mode = currentMode();
  return `<div class="card settings-card" id="theme-card">
    <h2 class="card__title">${icon('sonne')} ${escapeHtml(t('settings.theme.title'))}</h2>
    <p class="settings-card__hint">${escapeHtml(t('settings.theme.hint'))}</p>
    <div class="settings-fields">
      <div class="field settings-field">
        <label for="theme-select">${escapeHtml(t('settings.theme.title'))}</label>
        <select class="select" id="theme-select" data-field="theme">
          ${['system', 'light', 'dark'].map(m => `<option value="${m}" ${m === mode ? 'selected' : ''}>${escapeHtml(t(`settings.theme.${m}`))}</option>`).join('')}
        </select>
      </div>
    </div>
  </div>`;
}

export function wireThemeCard(container) {
  container.querySelector('#theme-select')?.addEventListener('change', (e) => applyTheme(e.target.value));
}


// ── v2.7.0 — Länderprofil (N1014) ────────────────────────────────────

export function renderRegionFields(settings, countries, dis = '') {
  const codes = countries.length ? countries.map(c => c.code) : [settings.country || 'DE'];
  // Währungen aus den Profilen (SSOT Config\Countries), nicht aus einer Liste hier
  const currencies = [...new Set([...countries.map(c => c.currency), settings.currency || 'EUR'])];
  return `
    <div class="field settings-field">
      <label for="country-select">${t('settings.region.country')}</label>
      <select class="select" id="country-select" data-field="country"${dis}>
        ${codes.map(c => `<option value="${c}" ${c === settings.country ? 'selected' : ''}>${escapeHtml(t('countries.' + c))}</option>`).join('')}
      </select>
      <span class="settings-field__hint">${escapeHtml(t('settings.region.countryHint'))}</span>
    </div>
    <div class="field settings-field">
      <label for="currency-select">${t('settings.region.currency')}</label>
      <select class="select" id="currency-select" data-field="currency" aria-describedby="currency-hint"${dis}>
        ${currencies.map(c => `<option value="${c}" ${c === settings.currency ? 'selected' : ''}>${c}</option>`).join('')}
      </select>
      <span class="settings-field__hint" id="currency-hint">${t('settings.region.currencyNote')}</span>
    </div>
    <div class="field settings-field">
      <label for="tz-select">${t('settings.region.timezone')}</label>
      <select class="select" id="tz-select" data-field="timezone"${dis}>
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


// v3.5.0 (N1022) — profileDiff() und das Speichern stehen in lib/region.js;
// der Einrichtungsassistent wechselt das Land über dieselben Bausteine.

/** Dialog: alle Profilwerte übernehmen, nur das Land ändern oder abbrechen. */
export function chooseProfile(code, diff, profile) {
  const source = profile.co2_strom_source === 'ember-2024' ? 'ember2024'
    : profile.co2_strom_source === 'uba' ? 'uba' : 'default';
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
      ${diff.some(d => d.key === 'co2_strom' || d.key === 'co2_strom_years') ? `<p class="muted">${escapeHtml(t('settings.region.source.' + source))}</p>` : ''}
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


/** `change`: {country, profile} oder {currency} / {timezone} — s. saveRegion() */
export async function applyRegion(container, change, countries = [], rerender = () => {}) {
  try {
    await saveRegion(change, countries);
    toastOk(t('settings.region.saved'));
    rerender();
  } catch (err) { toastErr(err.message); }
}
