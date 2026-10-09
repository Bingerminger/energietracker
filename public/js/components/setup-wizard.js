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
// =====================================================================

import { api } from '../api.js';
import { t } from '../lib/i18n.js';
import { escapeHtml as esc } from '../lib/format.js';
import { openModal } from './modal.js';
import { toastErr } from './toast.js';
import { getUtilities, getSettings, saveSettings } from '../state.js';
import { LEVELS, LEVEL_ICONS, currentLevel, saveLevel, sessionRole } from '../lib/levels.js';
import { DEMO, demoPersona, setDemoPersona } from '../lib/demo-mode.js';
import { loadDemo } from '../lib/demo.js';

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
 * @param {{session?: object|null, firstRun?: boolean}} [opts]
 * @returns {Promise<void>}
 */
export async function openSetupWizard({ session = null, firstRun = false } = {}) {
  const [utilities, settings] = await Promise.all([getUtilities().catch(() => []), getSettings().catch(() => ({}))]);
  const state = {
    step: 0,
    persona: DEMO ? demoPersona() : (settings.setup_persona || null),
    utilities: Array.isArray(settings.active_utilities) && settings.active_utilities.length
      ? [...settings.active_utilities] : utilities.map(u => u.key),
    level: firstRun ? null : currentLevel(),
    // Beispieldaten ersetzen den ganzen Haushalt — das dürfen nur Verwalter (F1023)
    start: sessionRole() === 'admin' ? 'demo' : 'own',
  };
  const canLoadDemo = sessionRole() === 'admin';
  const steps = DEMO ? ['persona', 'level', 'finish'] : ['persona', 'utilities', 'level', 'finish'];

  const personaStep = () => `
    <h3 class="setup__question">${esc(t('setup.persona.title'))}</h3>
    <div class="setup-choices" role="radiogroup" aria-label="${esc(t('setup.persona.title'))}">
      ${PERSONAS.map(p => `
        <label class="setup-choice${state.persona === p.id ? ' setup-choice--on' : ''}">
          <input type="radio" name="setup-persona" value="${p.id}" ${state.persona === p.id ? 'checked' : ''}>
          <span class="setup-choice__icon" aria-hidden="true">${p.icon}</span>
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
    <p class="muted small">${esc(t('setup.level.later'))}</p>`;

  const finishStep = () => {
    const p = persona(state.persona);
    const name = t(`setup.persona.${p.key}.title`);
    if (DEMO) {
      return `<h3 class="setup__question">${esc(t('setup.finish.title'))}</h3>
        <p>${esc(t('setup.finish.demoPublic', { persona: name }))}</p>`;
    }
    if (!canLoadDemo) {
      return `<h3 class="setup__question">${esc(t('setup.finish.title'))}</h3>
        <p class="muted">${esc(t('setup.finish.summary', { persona: name, level: t('level.' + state.level) }))}</p>
        <p>${esc(t('setup.finish.ownHint'))}</p>`;
    }
    return `
      <h3 class="setup__question">${esc(t('setup.finish.title'))}</h3>
      <p class="muted">${esc(t('setup.finish.summary', { persona: name, level: t('level.' + state.level) }))}</p>
      <div class="setup-choices" role="radiogroup" aria-label="${esc(t('setup.finish.title'))}">
        <label class="setup-choice${state.start === 'demo' ? ' setup-choice--on' : ''}">
          <input type="radio" name="setup-start" value="demo" ${state.start === 'demo' ? 'checked' : ''}>
          <span class="setup-choice__icon" aria-hidden="true">🧪</span>
          <span class="setup-choice__body"><strong>${esc(t('setup.finish.demo'))}</strong>
            <span class="muted small">${esc(t('setup.finish.demoHint', { persona: name }))}</span></span>
        </label>
        <label class="setup-choice${state.start === 'own' ? ' setup-choice--on' : ''}">
          <input type="radio" name="setup-start" value="own" ${state.start === 'own' ? 'checked' : ''}>
          <span class="setup-choice__icon" aria-hidden="true">📋</span>
          <span class="setup-choice__body"><strong>${esc(t('setup.finish.own'))}</strong>
            <span class="muted small">${esc(t('setup.finish.ownHint'))}</span></span>
        </label>
      </div>`;
  };

  const html = { persona: personaStep, utilities: utilitiesStep, level: levelStep, finish: finishStep };

  openModal({
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
      const draw = () => {
        const name = steps[state.step];
        body.innerHTML = (state.step === 0 ? `<p class="muted">${esc(t('setup.intro'))}</p>` : '') + html[name]();
        modalEl.querySelector('[data-role="progress"]').textContent = t('setup.progress', { n: state.step + 1, total: steps.length });
        back.hidden = state.step === 0;
        next.textContent = t(state.step === steps.length - 1 ? 'setup.done' : 'setup.next');
        next.disabled = (name === 'persona' && !state.persona) || (name === 'level' && !state.level)
          || (name === 'utilities' && !state.utilities.length);
        body.querySelector('input')?.focus();
      };
      body.addEventListener('change', (e) => {
        const el = e.target;
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
        draw();
      });
      back.addEventListener('click', () => { state.step = Math.max(0, state.step - 1); draw(); });
      modalEl.querySelector('[data-act="skip"]').addEventListener('click', async () => {
        if (!DEMO && settings.setup_pending) await saveSettings({ setup_pending: false }).catch(() => {});
        if (DEMO && !demoPersona(true)) setDemoPersona('showcase');
        close(null);
      });
      next.addEventListener('click', async () => {
        if (state.step < steps.length - 1) { state.step++; draw(); return; }
        next.disabled = true;
        try {
          await finish(state);
          close(true);
        } catch (e) {
          toastErr(e.message);
          next.disabled = false;
        }
      });
      draw();
    },
  });
}

/** Übernehmen: Einstellungen, Stufe, dann Beispielhaushalt oder eigene Daten. */
async function finish(state) {
  const p = persona(state.persona);
  if (DEMO) {
    setDemoPersona(p.id);
    await saveLevel(state.level);
    location.reload();
    return;
  }
  await saveSettings({
    active_utilities: state.utilities,
    wohnverhaeltnis: p.id === 'mieterin' ? 'miete' : 'eigentum',
    setup_persona: p.id,
    setup_pending: false,
  });
  await saveLevel(state.level);
  if (state.start === 'demo') {
    await loadDemo({ persona: p.id });
    return;
  }
  location.hash = '#/zaehlerstaende';
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
