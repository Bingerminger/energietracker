// =====================================================================
// v3.2.0 (F1018) — Einrichtungsassistent.
//
// Drei Fragen statt einer leeren Oberfläche: Wer bist du (Persona), welche
// Energieträger nutzt du, wie viel Erfahrung hast du? Daraus folgen die
// aktiven Verbrauchsarten, das Wohnverhältnis und die Nutzungsstufe (F1019).
// Zum Schluss: mit dem passenden Beispielhaushalt ansehen oder mit eigenen
// Daten beginnen.
//
// Erscheint beim allerersten Start (`setup_pending`, gesetzt nur bei einer
// Neuinstallation — nie nach einem Update) und in der öffentlichen Demo, bis
// dort ein Beispielhaushalt gewählt ist. Jederzeit erneut aus den
// Einstellungen. Gespeichert wird erst am Ende; „Überspringen“ lässt alles,
// wie es ist.
//
// v3.5.0 (N1022) — Schritt 1 fragt Sprache und Land. Vorgewählt ist, was
// gilt: die Sprache dieses Geräts (beim Erststart die des Browsers) und das
// Land, das der Erststart aus dem Browser abgeleitet hat. Eine andere Sprache
// gilt im Assistenten sofort (lib/language.js, wie in den Einstellungen);
// unter dem Land steht, was sein Profil setzt. Gespeichert wird beides erst
// mit „Los geht’s“ — das Land über denselben Weg wie in den Einstellungen
// (lib/region.js → POST /api/settings/country, nur Abweichungen vom Default),
// und nur, wenn es sich geändert hat. Abbrechen nimmt die Sprache zurück. In
// der Demo gilt die Sprache je Gerät; das Land ist das des Beispielhaushalts.
// =====================================================================

import { api } from '../api.js';
import { t, getLocale, getLanguages, deviceLanguage, setDeviceLanguage } from '../lib/i18n.js';
import { escapeHtml as esc } from '../lib/format.js';
import { openModal } from './modal.js';
import { toastErr } from './toast.js';
import { getUtilities, getSettings, saveSettings, getCountries } from '../state.js';
import { LEVELS, LEVEL_ICONS, currentLevel, saveLevel, sessionRole } from '../lib/levels.js';
import { DEMO, demoPersona, setDemoPersona, setDemoLanguage } from '../lib/demo-mode.js';
import { loadDemo } from '../lib/demo.js';
import { regionFacts, saveRegion } from '../lib/region.js';
import { switchLanguage } from '../lib/language.js';
import { openReadingSetup } from './reading-setup.js';   // v3.7.0 — „Auslesen einrichten“ als Dialog
import { docUrl } from '../lib/docs.js';   // v3.7.0 (Paket reifegrad) — Funktionstabelle nach Stufe
// v3.9.0 (Paket oberflaeche) — kleine Strichzeichnungen je Schritt und Haushalt (Zier, aria-hidden)
import { setupArt, personaArt } from '../lib/illustrations.js';
// v3.9.0 (F1032) — Schritt „Wie hängen deine Zähler?“ mit den Schaltbildern des Assistenten „Messkonzept“
import { schematic } from '../lib/metering-concepts.js';

/**
 * v3.9.0 — Bild neben die Frage eines Schritts: das erste `.setup__question`
 * (samt Einleitung im ersten Schritt) steht mit der Zeichnung in einer Zeile.
 * Ein Schritt ohne Zeichnung bleibt, wie er ist.
 */
export function withArt(step, html, lead = '') {
  const art = setupArt(step);
  const m = art ? html.match(/<h3 class="setup__question">[\s\S]*?<\/h3>/) : null;
  if (!m) return lead + html;
  return `<div class="setup__head"><div class="setup__art">${art}</div><div class="setup__lead">${lead}${m[0]}</div></div>`
    + html.slice(0, m.index) + html.slice(m.index + m[0].length);
}

/** Persona → Katalogname, Symbol und vorgeschlagene Verbrauchsarten. */
export const PERSONAS = [
  { id: 'mieterin',            key: 'tenant',       icon: '🏢', utilities: ['strom', 'waerme', 'wasser'], level: 'beginner' },
  { id: 'etw-fernwaerme',      key: 'flat',         icon: '🏙️', utilities: ['strom', 'fernwaerme'], level: 'beginner' },
  { id: 'eigenheim-klassisch', key: 'houseClassic', icon: '🏡', utilities: ['gas', 'strom', 'wasser'], level: 'advanced' },
  { id: 'eigenheim-modern',    key: 'houseModern',  icon: '☀️', utilities: ['strom', 'waerme', 'wasser', 'pv_erzeugung', 'pv_einspeisung'], level: 'advanced' },
  { id: 'showcase',            key: 'showcase',     icon: '🔍', utilities: null, level: 'expert' },
];

const persona = (id) => PERSONAS.find(p => p.id === id) || PERSONAS[PERSONAS.length - 1];

/**
 * v3.7.0 (Haushalt aus Dokumenten) — welche Dokumente beim Weg „Aus
 * Dokumenten“ helfen, je Haushalt: zur Miete die letzte Jahresrechnung und die
 * Vertragsbestätigung; sonst je Energieträger Jahresrechnung, Bestätigung und
 * Preisänderungen; mit Einspeisung der Vertrag bzw. die Abrechnung des
 * Netzbetreibers; mit Wärmepumpe oder Wallbox die Bestätigung des Sondertarifs.
 * @returns {string[]} Katalogschlüssel
 */
export function docsHelp(personaId, utilities = []) {
  const keys = [personaId === 'mieterin' ? 'tenant' : 'house'];
  if (utilities.includes('pv_einspeisung')) keys.push('pv');
  if (personaId === 'eigenheim-modern') keys.push('heatPump');
  return keys.map(k => `setup.finish.docsHelp.${k}`);
}

/**
 * v3.9.0 (F1032) — Braucht der Haushalt den Schritt „Wie hängen deine Zähler?“:
 * Strom und PV (Einspeisung oder Erzeugung) oder der Haushalt mit Wärmepumpe,
 * Wallbox und Speicher (Eigenheim modern). Sonst gilt ein Zähler je Art.
 */
export function conceptStepFor(utilities = [], persona = null) {
  const u = utilities || [];
  return u.includes('strom') && (u.includes('pv_einspeisung') || u.includes('pv_erzeugung') || persona === 'eigenheim-modern');
}

/**
 * v3.5.0 (N1022) — die Schritte des Assistenten: zuerst Sprache und Land, in
 * der Demo ohne Verbrauchsarten (die bringt der Beispielhaushalt mit).
 * v3.9.0 (F1032) — nach den Energieträgern „Wie hängen deine Zähler?“, wenn
 * der Haushalt mehr als einen Zähler je Art braucht (conceptStepFor).
 * @param {boolean} [demo]
 * @param {string[]|null} [utilities]  gewählte Energieträger
 * @param {string|null} [persona]
 */
export function setupSteps(demo = DEMO, utilities = null, persona = null) {
  if (demo) return ['region', 'persona', 'level', 'finish'];
  return conceptStepFor(utilities || [], persona)
    ? ['region', 'persona', 'utilities', 'concept', 'level', 'finish']
    : ['region', 'persona', 'utilities', 'level', 'finish'];
}

/**
 * @param {{session?: object|null, firstRun?: boolean}} [opts]
 * @returns {Promise<void>}
 */
export async function openSetupWizard({ session = null, firstRun = false } = {}) {
  let [utilities, settings, countries] = await Promise.all([
    getUtilities().catch(() => []), getSettings().catch(() => ({})), getCountries().catch(() => []),
  ]);
  // v3.5.1 — Blieb die Länderliste beim ersten Öffnen leer (Abruf gescheitert),
  // bot der Assistent nur das aktuelle Land an, die Einstellungen später alle.
  // Einmal direkt nachladen statt ein Feld mit einer einzigen Wahl zu zeigen.
  if (!countries.length && !DEMO) countries = await api.countries().catch(() => []);
  // v3.7.0 (Haushalt aus Dokumenten) — ist das Auslesen eingerichtet? Sonst bietet „Aus Dokumenten“ den Dialog „Auslesen einrichten“
  let reader = DEMO ? null : await api.inbox().then(d => d?.provider || null).catch(() => null);
  // Was gilt, wenn der Assistent öffnet — Vergleich für „nur Abweichungen“ und fürs Zurücknehmen
  const start = { language: getLocale(), country: settings.country || 'DE' };
  const state = {
    step: 0,
    language: start.language,
    country: start.country,
    persona: DEMO ? demoPersona() : (settings.setup_persona || null),
    utilities: Array.isArray(settings.active_utilities) && settings.active_utilities.length
      ? [...settings.active_utilities] : utilities.map(u => u.key),
    level: firstRun ? null : currentLevel(),
    // v3.7.0 — empfohlen: aus Dokumenten (Beispieldaten ersetzen den ganzen Haushalt, das dürfen nur Verwalter, F1023)
    start: 'docs',
    // v3.6.0 — Hinweis auf neue Versionen: bei der Einrichtung vorgeschlagen (sichtbar,
    // abwählbar); beim erneuten Öffnen die aktuelle Einstellung
    updateCheck: firstRun ? true : !!settings.update_check,
    // v3.9.0 (F1032) — „Wie hängen deine Zähler?“: simple (ein Zähler je Art) oder concept (Assistent)
    meterSetup: 'simple',
  };
  const canLoadDemo = sessionRole() === 'admin';
  // v3.9.0 (F1032) — die Schritte folgen den gewählten Energieträgern (Schritt „Wie hängen deine Zähler?“)
  const stepsNow = () => setupSteps(DEMO, state.utilities, state.persona);

  /** Was das gewählte Land setzt (gewechselt) bzw. was gilt (unverändert). */
  const factsHtml = () => {
    const profile = countries.find(c => c.code === state.country) || null;
    const changed = state.country !== start.country && !!profile;
    const facts = regionFacts(changed ? profile : settings,
      { lang: state.language, country: state.country, languages: profile?.languages || [] });
    return `<p class="setup-profile__title">${esc(t(changed ? 'setup.region.profile' : 'setup.region.current', { country: t('countries.' + state.country) }))}</p>
      <dl class="setup-profile__list">${facts.map(f => `<div class="setup-profile__row" data-fact="${f.key}"><dt>${esc(f.label)}</dt><dd>${esc(f.value)}</dd></div>`).join('')}</dl>
      <p class="muted small">${esc(t('settings.region.applyKeep'))}</p>`;
  };

  const regionStep = () => {
    const codes = countries.length ? countries.map(c => c.code) : [state.country];
    return `
    <h3 class="setup__question">${esc(t('setup.region.title'))}</h3>
    <div class="fgrid">
      <div class="field">
        <label for="setup-lang">${esc(t('setup.region.language'))}</label>
        <select class="select" id="setup-lang" name="setup-lang">
          ${Object.entries(getLanguages()).map(([code, name]) => `<option value="${esc(code)}"${code === state.language ? ' selected' : ''}>${esc(name)}</option>`).join('')}
        </select>
      </div>
      <div class="field">
        ${DEMO
          // v3.5.1 — in der Demo gilt das Land der Beispieldaten: als Text mit
          // Erklärung statt eines gesperrten Felds, das wie ein Fehler wirkte
          ? `<span class="label">${esc(t('settings.region.country'))}</span>
        <p class="setup-country-fixed" id="setup-country" data-country="${esc(state.country)}"><strong>${esc(t('countries.' + state.country))}</strong></p>
        <p class="settings-field__hint" id="setup-country-demo">${esc(t('setup.region.demoCountry'))}</p>`
          : `<label for="setup-country">${esc(t('settings.region.country'))}</label>
        <select class="select" id="setup-country" name="setup-country">
          ${codes.map(c => `<option value="${esc(c)}"${c === state.country ? ' selected' : ''}>${esc(t('countries.' + c))}</option>`).join('')}
        </select>`}
      </div>
    </div>
    <div class="setup-profile" data-role="region-facts" aria-live="polite">${factsHtml()}</div>`;
  };

  const personaStep = () => `
    <h3 class="setup__question">${esc(t('setup.persona.title'))}</h3>
    <div class="setup-choices" role="radiogroup" aria-label="${esc(t('setup.persona.title'))}">
      ${PERSONAS.map(p => `
        <label class="setup-choice${state.persona === p.id ? ' setup-choice--on' : ''}">
          <input type="radio" name="setup-persona" value="${p.id}" ${state.persona === p.id ? 'checked' : ''}>
          ${personaArt(p.id) ? `<span class="setup-choice__art">${personaArt(p.id)}</span>` : `<span class="setup-choice__icon" aria-hidden="true">${p.icon}</span>`}
          <span class="setup-choice__body"><strong>${esc(t(`setup.persona.${p.key}.title`))}</strong>
            <span class="muted small">${esc(t(`setup.persona.${p.key}.text`))}</span></span>
        </label>`).join('')}
    </div>`;

  const utilitiesStep = () => `
    <h3 class="setup__question">${esc(t('setup.utilities.title'))}</h3>
    <p class="muted small">${esc(t('setup.utilities.hint'))}</p>
    <div class="setup-utilities">
      ${utilities.map(u => `<label class="settings-field__check">
        <input type="checkbox" name="setup-util" value="${esc(u.key)}" ${state.utilities.includes(u.key) ? 'checked' : ''}>
        <span aria-hidden="true">${esc(u.icon || '')}</span> ${esc(u.label)}</label>`).join('')}
    </div>
    <p class="banner banner--info small">${esc(t('setup.utilities.heatNote'))}</p>`;

  const levelStep = () => `
    <h3 class="setup__question">${esc(t('setup.level.title'))}</h3>
    <div class="setup-choices" role="radiogroup" aria-label="${esc(t('setup.level.title'))}">
      ${LEVELS.map(l => `
        <label class="setup-choice${state.level === l ? ' setup-choice--on' : ''}">
          <input type="radio" name="setup-level" value="${l}" ${state.level === l ? 'checked' : ''}>
          <span class="setup-choice__icon" aria-hidden="true">${LEVEL_ICONS[l]}</span>
          <span class="setup-choice__body"><strong>${esc(t('level.' + l))}</strong>
            <span class="muted small">${esc(t('settings.level.describe.' + l))}</span></span>
        </label>`).join('')}
    </div>
    <p class="muted small">${esc(t('setup.level.later'))}
      <a href="${esc(docUrl('levels'))}" target="_blank" rel="noopener" data-role="level-table">${esc(t('level.table'))}</a></p>`;

  const finishStep = () => {
    const p = persona(state.persona);
    const name = t(`setup.persona.${p.key}.title`);
    if (DEMO) {
      return `<h3 class="setup__question">${esc(t('setup.finish.title'))}</h3>
        <p>${esc(t('setup.finish.demoPublic', { persona: name }))}</p>`;
    }
    // v3.7.0 — drei Wege: aus Dokumenten (empfohlen), von Hand, Beispielhaushalt (nur Verwalter)
    const choice = (value, icon, title, hint, extra = '') => `
        <label class="setup-choice${state.start === value ? ' setup-choice--on' : ''}">
          <input type="radio" name="setup-start" value="${value}" ${state.start === value ? 'checked' : ''}>
          <span class="setup-choice__icon" aria-hidden="true">${icon}</span>
          <span class="setup-choice__body"><strong>${esc(title)}</strong>${extra}
            <span class="muted small">${esc(hint)}</span></span>
        </label>`;
    const docsBox = state.start !== 'docs' ? '' : `
      <div class="setup-docs" data-role="setup-docs">
        <p class="setup-docs__title">${esc(t('setup.finish.docsHelpTitle'))}</p>
        <ul>${docsHelp(p.id, state.utilities).map(k => `<li>${esc(t(k))}</li>`).join('')}</ul>
        ${reader?.available ? '' : `<div class="banner banner--info small" data-role="setup-reader"><p>${esc(canLoadDemo
          ? t('setup.finish.docsReader') : t('setup.finish.docsReaderMember'))}</p>
          ${canLoadDemo ? `<p><button type="button" class="btn btn--primary btn--sm" data-act="reading-setup">${esc(t('inboxDraft.setup.button'))}</button></p>` : ''}</div>`}
      </div>`;
    return `
      <h3 class="setup__question">${esc(t('setup.finish.title'))}</h3>
      <p class="muted">${esc(t('setup.finish.summary', { persona: name, level: t('level.' + state.level) }))}</p>
      <div class="setup-choices" role="radiogroup" aria-label="${esc(t('setup.finish.title'))}">
        ${choice('docs', '📥', t('setup.finish.docs'), t('setup.finish.docsHint'), ` <span class="badge badge--info">${esc(t('setup.finish.recommended'))}</span>`)}
        ${choice('own', '📋', t('setup.finish.own'), t('setup.finish.ownHint'))}
        ${state.utilities.includes('strom') ? choice('concept', '🔌', t('concept.title'), t('concept.setupChoice.hint')) : ''}
        ${canLoadDemo ? choice('demo', '🧪', t('setup.finish.demo'), t('setup.finish.demoHint', { persona: name })) : ''}
      </div>
      ${docsBox}
      ${canLoadDemo ? `<label class="settings-field__check setup-update-check">
        <input type="checkbox" name="setup-update-check" ${state.updateCheck ? 'checked' : ''}>
        ${esc(t('setup.finish.updateCheck'))}</label>` : ''}`;
  };

  /**
   * v3.9.0 (F1032) — „Wie hängen deine Zähler?“: ein Zähler je Art (Standard) oder ein
   * anderer Aufbau; dann wählt „So geht es weiter“ den Assistenten „Messkonzept“ vor.
   */
  const conceptStep = () => {
    const pv = state.utilities.includes('pv_einspeisung') || state.utilities.includes('pv_erzeugung');
    const card = (value, art, title, text) => `
        <label class="setup-choice concept-layout${state.meterSetup === value ? ' setup-choice--on' : ''}">
          <input type="radio" name="setup-meters" value="${value}" ${state.meterSetup === value ? 'checked' : ''}>
          <span class="concept-layout__art">${art}</span>
          <span class="setup-choice__body"><strong>${esc(title)}</strong>
            <span class="muted small">${esc(text)}</span></span>
        </label>`;
    return `
      <h3 class="setup__question">${esc(t('setup.concept.title'))}</h3>
      <p class="muted small">${esc(t('setup.concept.hint'))}</p>
      <div class="concept-layouts" role="radiogroup" aria-label="${esc(t('setup.concept.title'))}">
        ${card('simple', schematic('single', { pv }), t('setup.concept.simple'), t('setup.concept.simpleHint'))}
        ${card('concept', schematic('behind', { device: 'heat_pump', pv }), t('setup.concept.other'), t('setup.concept.otherHint'))}
      </div>`;
  };

  const html = { region: regionStep, persona: personaStep, utilities: utilitiesStep, concept: conceptStep, level: levelStep, finish: finishStep };

  const ctrl = openModal({
    title: t('setup.title'),
    size: 'lg',
    body: '<div class="setup" data-role="setup-body"></div>',
    footer: `
      <button type="button" class="btn btn--ghost" data-act="skip">${esc(t('setup.skip'))}</button>
      <span class="setup__progress muted small" data-role="progress"></span>
      <button type="button" class="btn btn--ghost" data-act="back">${esc(t('setup.back'))}</button>
      <button type="button" class="btn btn--primary" data-act="next">${esc(t('setup.next'))}</button>`,
    onMount({ modalEl, close }) {
      const body = modalEl.querySelector('[data-role="setup-body"]');
      const next = modalEl.querySelector('[data-act="next"]');
      const back = modalEl.querySelector('[data-act="back"]');
      const skip = modalEl.querySelector('[data-act="skip"]');
      /** Titel und Knöpfe nach einem Sprachwechsel neu beschriften. */
      const relabel = () => {
        const title = modalEl.querySelector('.modal__title');
        if (title) title.textContent = t('setup.title');
        modalEl.querySelector('.modal__close')?.setAttribute('aria-label', t('common.close'));
        skip.textContent = t('setup.skip');
        back.textContent = t('setup.back');
      };
      /** @param {string} [focusSel] Element, das den Fokus behält (sonst das erste Feld) */
      const draw = (focusSel) => {
        const steps = stepsNow();
        const name = steps[state.step];
        body.innerHTML = withArt(name, html[name](), state.step === 0 ? `<p class="muted">${esc(t('setup.intro'))}</p>` : '');
        modalEl.querySelector('[data-role="progress"]').textContent = t('setup.progress', { n: state.step + 1, total: steps.length });
        back.hidden = state.step === 0;
        next.textContent = t(state.step === steps.length - 1 ? 'setup.done' : 'setup.next');
        next.disabled = (name === 'persona' && !state.persona) || (name === 'level' && !state.level)
          || (name === 'utilities' && !state.utilities.length);
        (focusSel && body.querySelector(focusSel) || body.querySelector('input, select:not([disabled])'))?.focus();
      };
      body.addEventListener('change', async (e) => {
        const el = e.target;
        // v3.5.0 (N1022) — Sprache: Kataloge nachladen wie die Einstellungen,
        // der Assistent steht sofort in der neuen Sprache (Fokus bleibt am Feld)
        if (el.name === 'setup-lang') {
          state.language = el.value;
          el.disabled = true;
          try {
            await switchLanguage(state.language);
            utilities = await getUtilities().catch(() => utilities);   // Bezeichnungen vom Server, übersetzt
          } catch (err) { toastErr(err.message); }
          relabel();
          draw('#setup-lang');
          return;
        }
        // Land: nur die Angaben darunter neu, das Feld behält den Fokus
        if (el.name === 'setup-country') {
          state.country = el.value;
          const facts = body.querySelector('[data-role="region-facts"]');
          if (facts) facts.innerHTML = factsHtml();
          return;
        }
        if (el.name === 'setup-persona') {
          state.persona = el.value;
          const p = persona(el.value);
          state.utilities = p.utilities ? [...p.utilities] : utilities.map(u => u.key);
          if (!state.level || firstRun) state.level = p.level;
        }
        if (el.name === 'setup-util') {
          state.utilities = [...body.querySelectorAll('input[name="setup-util"]:checked')].map(x => x.value);
        }
        if (el.name === 'setup-level') state.level = el.value;
        if (el.name === 'setup-start') state.start = el.value;
        // v3.9.0 (F1032) — anderer Aufbau: „So geht es weiter“ wählt den Assistenten „Messkonzept“ vor
        if (el.name === 'setup-meters') {
          state.meterSetup = el.value;
          if (el.value === 'concept') state.start = 'concept';
          else if (state.start === 'concept') state.start = 'docs';
        }
        if (el.name === 'setup-update-check') state.updateCheck = el.checked;
        // v3.5.1 — der Fokus bleibt am gewählten Feld. Bisher setzte draw() ihn
        // aufs erste Feld; dessen Karte trug den Fokusrahmen und sah am iPad wie
        // eine zweite Auswahl aus. Werte stammen aus festen Listen (a–z, _ , -).
        draw(el.type === 'radio' || el.type === 'checkbox' ? `input[name="${el.name}"][value="${el.value}"]` : undefined);
      });
      back.addEventListener('click', () => { state.step = Math.max(0, state.step - 1); draw(); });
      // v3.7.0 — „Auslesen einrichten“ über dem Assistenten; danach zeigt der Schritt den neuen Stand
      body.addEventListener('click', async (e) => {
        if (!e.target.closest?.('[data-act="reading-setup"]')) return;
        reader = await openReadingSetup().catch(() => reader) ?? reader;
        if (body.isConnected) draw('input[name="setup-start"][value="docs"]');
      });
      modalEl.querySelector('[data-act="skip"]').addEventListener('click', async () => {
        if (!DEMO && settings.setup_pending) await saveSettings({ setup_pending: false }).catch(() => {});
        if (DEMO && !demoPersona(true)) setDemoPersona('showcase');
        close(null);
      });
      next.addEventListener('click', async () => {
        if (state.step < stepsNow().length - 1) { state.step++; draw(); return; }
        next.disabled = true;
        try {
          const go = await finish(state, { start, settings, countries, session });
          // Lektion 64: Wer schließt und sofort navigiert, schließt mit
          // 'navigation' — sonst nahm history.back() den Sprung zu
          // „Zählerstände“ wieder zurück
          close(true, go ? 'navigation' : 'ui');
          if (go) location.hash = go;
        } catch (e) {
          toastErr(e.message);
          next.disabled = false;
        }
      });
      draw();
    },
  });
  // Abgebrochen (Überspringen, ×, Escape, Zurück-Taste): die Sprache wie vorher
  ctrl.closedPromise.then((done) => {
    if (done !== true && getLocale() !== start.language) switchLanguage(start.language).catch(() => {});
  });
}

/**
 * v3.5.0 (N1022) — Hat dieses Gerät (bzw. die angemeldete Person) eine eigene
 * Sprache, zieht sie mit; sonst folgt es ohnehin der Installation.
 */
function syncOwnLanguage(lang, session) {
  if (session?.user && session.mode !== 'off') {
    if (session.user.prefs?.language) api.updateMe({ language: lang }).catch(() => {});
  } else if (deviceLanguage()) {
    setDeviceLanguage(lang);
  }
}

/**
 * Übernehmen: Sprache und Land, Einstellungen, Stufe, dann Beispielhaushalt
 * oder eigene Daten. Gespeichert wird nur, was sich geändert hat.
 * @returns {Promise<string|null>} Adresse, zu der es danach geht
 */
/** v3.6.0 — Einstellungen der Installation ändert nur, wer verwaltet (wie „Beispieldaten laden“). */
const canAdmin = () => sessionRole() === 'admin';

async function finish(state, { start, settings, countries, session }) {
  const p = persona(state.persona);
  const langChanged = state.language !== start.language;
  if (DEMO) {
    // Demo: Sprache je Gerät, kein Server; das Land ist das des Beispielhaushalts
    if (langChanged) setDemoLanguage(state.language);
    setDemoPersona(p.id);
    await saveLevel(state.level);
    location.reload();
    return null;
  }
  // Land über denselben Weg wie die Einstellungen (nur bei einem Wechsel)
  if (state.country !== start.country) await saveRegion({ country: state.country, profile: true }, countries);
  await saveSettings({
    active_utilities: state.utilities,
    wohnverhaeltnis: p.id === 'mieterin' ? 'miete' : 'eigentum',
    setup_persona: p.id,
    setup_pending: false,
    ...(canAdmin(session) ? { update_check: !!state.updateCheck } : {}),
    // Standardsprache der Installation (Jahresbericht, CSV, neue Geräte)
    ...(langChanged && state.language !== settings.language ? { language: state.language } : {}),
  });
  if (langChanged) syncOwnLanguage(state.language, session);
  await saveLevel(state.level);
  if (state.start === 'demo' && canAdmin()) {
    await loadDemo({ persona: p.id });
    return null;
  }
  // v3.7.0 — aus Dokumenten: in den Posteingang zum Ablegen (ohne eingerichtetes
  // Auslesen bietet er dort „Auslesen einrichten“ als Dialog an)
  if (state.start === 'docs') return '#/inbox';
  // v3.9.0 (F1032) — Messkonzept einrichten: die Zähler „Strom“ öffnen den Assistenten
  if (state.start === 'concept' && state.utilities.includes('strom')) return '#/utility/strom/meters?concept=1';
  return '#/zaehlerstaende';
}

/**
 * Beim Start: Neuinstallation (`setup_pending`) oder öffentliche Demo ohne
 * gewählten Beispielhaushalt.
 */
export async function maybeStartSetup(session) {
  if (DEMO) {
    if (!demoPersona(true)) openSetupWizard({ session, firstRun: true });
    return;
  }
  const s = await getSettings().catch(() => null);
  if (s?.setup_pending) openSetupWizard({ session, firstRun: true });
}
