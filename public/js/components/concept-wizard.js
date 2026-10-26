// =====================================================================
// v3.9.0 (F1032, GitHub #40) — Assistent „Messkonzept“.
//
// Fünf Schritte: der Aufbau (PV, Speicher, Gerät mit eigenem Tarif, HT/NT),
// „Wie hängen die Zähler?“ mit Schaltbildern, die Zähler (Nummer, Zählwerke
// mit vorbelegter OBIS-Kennzahl, vorhandene zuordnen), die Verträge (Haushalt,
// Gerät, Einspeisung; leer geht auch) und die Zusammenfassung mit dem
// Schaltbild der eigenen Anlage. Angelegt wird erst dort, über
// POST /api/metering-concept (vorher ein Snapshot); die Schritte zeigt vorher
// die Vorschau (schreibt nichts).
//
// Auf einem bestehenden Haushalt keine Doppelten: vorhandene Zähler sind
// vorgewählt, wo es eindeutig ist (lib/metering-concepts.js), und jede Stelle
// lässt sich einem vorhandenen Zähler zuordnen. Ein laufender Vertrag bleibt.
//
// Einstieg: Zähler „Strom“ (Knopf „Messkonzept einrichten“), Einstellungen →
// Verbrauchsarten & Abrechnung (Karte „Messkonzept“) und als Weg am Ende der
// Ersteinrichtung. Nicht in der öffentlichen Demo (dort schreibt nichts).
// =====================================================================

import { api } from '../api.js';
import { t } from '../lib/i18n.js';
import { escapeHtml as esc, todayIso } from '../lib/format.js';
import { openModal } from './modal.js';
import { toastOk, toastErr } from './toast.js';
import { getUtilities, getSettings, invalidateSettings } from '../state.js';
import { DEMO } from '../lib/demo-mode.js';
import {
  normalizeSetup, conceptsFor, conceptAllowed, layoutTitle, layoutText, schematic, slotTags,
  registerLabel, suggestAssignments, conceptPayload,
} from '../lib/metering-concepts.js';

export const CONCEPT_STEPS = ['setup', 'layout', 'meters', 'contracts', 'summary'];

/** Verbrauchsarten, deren Zählerseite den Assistenten anbietet. */
export const CONCEPT_UTILITIES = ['strom', 'pv_einspeisung', 'pv_erzeugung'];

/** Bietet die App den Assistenten an? Nicht in der öffentlichen Demo (dort schreibt nichts). */
export function conceptOffered(utilityKey = 'strom', demo = DEMO) {
  return !demo && CONCEPT_UTILITIES.includes(utilityKey);
}

/** Vorbelegung des Aufbaus aus dem Haushalt: PV aktiv, Rollen vorhandener Zähler. */
export function setupFromHousehold(settings, meters) {
  const active = Array.isArray(settings?.active_utilities) ? settings.active_utilities : [];
  const strom = meters.strom || [];
  const device = strom.some(m => m.role === 'heat_pump') ? 'heat_pump' : strom.some(m => m.role === 'ev_charger') ? 'ev_charger' : null;
  const pv = active.includes('pv_einspeisung') || (meters.pv_einspeisung || []).length > 0;
  const battery = (meters.pv_erzeugung || []).some(m => m.role === 'battery_charge' || m.role === 'battery_discharge');
  const reg = (m) => /^1\.8\.[12]$/.test(String(m.obis || '').replace(/^1-0:/, '').replace(/\*.*$/, '')) || /\b(HT|NT)\b/.test(m.name || '');
  return normalizeSetup({ device, pv, battery, tou: strom.some(reg) ? 'all' : 'none' });
}

/**
 * Öffnet den Assistenten.
 * @returns {Promise<object|null>} Ergebnis des Anlegens (oder null, abgebrochen)
 */
export async function openConceptWizard() {
  const [settings, utilities] = await Promise.all([getSettings().catch(() => ({})), getUtilities().catch(() => [])]);
  const meterUtilities = ['strom', 'pv_einspeisung', 'pv_erzeugung'];
  const meters = Object.fromEntries(await Promise.all(meterUtilities.map(async (u) => [u, await api.meters(u).catch(() => [])])));
  const label = (u) => utilities.find(x => x.key === u)?.label || u;

  const state = {
    step: 0,
    setup: setupFromHousehold(settings, meters),
    concept: null,
    slots: null,          // aus der Vorschau: Stelle → {utility, role, parent, registers, contract, name}
    input: {},            // Stelle → {name, serial, meter_id, meter_ids, obis}
    contracts: {},        // Vertragsschlüssel → {provider, start, ct, ct_nt, base, advance}
    preview: null,
  };
  const setConcept = () => {
    const list = conceptsFor(state.setup);
    if (!list.includes(state.concept) || !conceptAllowed(state.concept, state.setup)) {
      state.concept = list.find(c => conceptAllowed(c, state.setup)) || list[0];
    }
  };
  setConcept();
  /** Körper für Vorschau und Anlegen; die Einspeisung bekommt ohne eigene Nummer die von Z1 (vorhanden oder eingegeben). */
  const payload = () => {
    const input = { ...state.input };
    if (input.feed && !input.feed.serial?.trim() && !input.feed.meter_id) {
      const ids = [state.input.main?.meter_id, ...Object.values(state.input.main?.meter_ids || {})].filter(Boolean);
      const m = (meters.strom || []).find(x => ids.includes(x.id));
      const dev = m?.devices?.find(d => !d.removed_on) || m?.devices?.[m.devices.length - 1];
      if (dev?.serial) input.feed = { ...input.feed, serial: dev.serial };
    }
    return conceptPayload(state.concept, state.setup, input, state.contracts);
  };
  const tags = () => slotTags(state.concept, state.setup);

  // ── Schritte ───────────────────────────────────────────────────────
  const radio = (name, value, checked, text) => `<label class="settings-field__check">
      <input type="radio" name="${name}" value="${value}" ${checked ? 'checked' : ''}> ${esc(text)}</label>`;
  const setupStep = () => {
    const s = state.setup;
    return `
      <p class="muted">${esc(t('concept.intro'))}</p>
      <h3 class="setup__question">${esc(t('concept.setup.title'))}</h3>
      <div class="concept-setup">
        <label class="settings-field__check"><input type="checkbox" name="cw-pv" ${s.pv ? 'checked' : ''}> ${esc(t('concept.setup.pv'))}</label>
        <label class="settings-field__check"${s.pv ? '' : ' hidden'} data-role="cw-battery"><input type="checkbox" name="cw-battery" ${s.battery ? 'checked' : ''}> ${esc(t('concept.setup.battery'))}</label>
      </div>
      <fieldset class="concept-fieldset">
        <legend>${esc(t('concept.setup.device'))}</legend>
        ${radio('cw-device', '', !s.device, t('concept.setup.deviceNone'))}
        ${radio('cw-device', 'heat_pump', s.device === 'heat_pump', t('concept.setup.deviceHeatPump'))}
        ${radio('cw-device', 'ev_charger', s.device === 'ev_charger', t('concept.setup.deviceEv'))}
        <p class="muted small">${esc(t('concept.setup.deviceHint'))}</p>
      </fieldset>
      <fieldset class="concept-fieldset">
        <legend>${esc(t('concept.setup.tou'))}</legend>
        ${radio('cw-tou', 'none', s.tou === 'none', t('concept.setup.touNone'))}
        ${radio('cw-tou', 'all', s.tou === 'all', t('concept.setup.touAll'))}
        ${s.device ? radio('cw-tou', 'device', s.tou === 'device', t('concept.setup.touDevice')) : ''}
        <p class="muted small">${esc(t('concept.setup.touHint'))}</p>
      </fieldset>`;
  };

  const layoutStep = () => `
      <h3 class="setup__question">${esc(t('concept.layout.title'))}</h3>
      <p class="muted small">${esc(t('concept.layout.hint'))}</p>
      <div class="concept-layouts" role="radiogroup" aria-label="${esc(t('concept.layout.title'))}">
        ${conceptsFor(state.setup).map(c => {
          const ok = conceptAllowed(c, state.setup);
          const on = state.concept === c;
          return `<label class="setup-choice concept-layout${on ? ' setup-choice--on' : ''}${ok ? '' : ' concept-layout--off'}" data-concept="${c}">
            <input type="radio" name="cw-concept" value="${c}" ${on ? 'checked' : ''} ${ok ? '' : 'disabled'}>
            <span class="concept-layout__art">${schematic(c, state.setup)}</span>
            <span class="setup-choice__body"><strong>${esc(layoutTitle(c))}</strong>
              <span class="muted small">${esc(ok ? layoutText(c, state.setup) : t('errors.concept.touCascade'))}</span></span>
          </label>`;
        }).join('')}
      </div>
      ${state.setup.battery ? `<p class="muted small">${esc(t('concept.layout.battery'))}</p>` : ''}`;

  const meterOptions = (u, selected) => {
    const list = (meters[u] || []).filter(m => m.active !== false);
    return `<option value="">${esc(t('concept.meters.new'))}</option>`
      + list.map(m => `<option value="${esc(m.id)}" ${m.id === selected ? 'selected' : ''}>${esc(m.name)}${m.obis ? ` (${esc(m.obis)})` : ''}</option>`).join('');
  };
  const metersStep = () => {
    const tg = tags();
    return `
      <h3 class="setup__question">${esc(t('concept.meters.title'))}</h3>
      <p class="muted small">${esc(t('concept.meters.hint'))}</p>
      ${Object.entries(state.slots).map(([key, slot]) => {
        const v = state.input[key] || {};
        const regs = Object.entries(slot.registers);
        const multi = regs.length > 1;
        const allExisting = regs.every(([r]) => (multi ? v.meter_ids?.[r] : v.meter_id));
        const hint = key === 'feed' ? t('concept.meters.sameDevice', { name: state.input.main?.name || state.slots.main?.name || '' })
          : key === 'gen' ? t('concept.meters.genHint') : key.startsWith('bat_') ? t('concept.meters.batteryHint') : '';
        return `<fieldset class="concept-slot" data-slot="${key}">
          <legend><span class="concept-tag">${esc(tg[key] || '')}</span> ${esc(v.name || slot.name)} <span class="muted small">· ${esc(label(slot.utility))}</span></legend>
          <div class="fgrid">
            ${regs.map(([r, obis]) => `
            <div class="field">
              <label for="cw-ex-${key}-${r}">${esc(t('concept.meters.existing'))}${multi ? ` · ${esc(registerLabel(slot, r))}` : ''}</label>
              <select class="select" id="cw-ex-${key}-${r}" data-slot="${key}" data-reg="${r}" data-field="existing">${meterOptions(slot.utility, multi ? v.meter_ids?.[r] : v.meter_id)}</select>
            </div>
            ${slot.utility === 'pv_erzeugung' ? '' : `<div class="field">
              <label for="cw-obis-${key}-${r}">${esc(t('meters.modal.obis'))}${multi || slot.utility !== 'strom' ? ` · ${esc(registerLabel(slot, r))}` : ''}</label>
              <input class="input" id="cw-obis-${key}-${r}" data-slot="${key}" data-reg="${r}" data-field="obis" value="${esc(v.obis?.[r] ?? obis)}" inputmode="decimal" autocomplete="off" spellcheck="false">
            </div>`}`).join('')}
            <div class="field"${allExisting ? ' hidden' : ''}>
              <label for="cw-name-${key}">${esc(t('concept.meters.name'))}</label>
              <input class="input" id="cw-name-${key}" data-slot="${key}" data-field="name" value="${esc(v.name ?? slot.name)}" maxlength="80">
            </div>
            <div class="field"${allExisting ? ' hidden' : ''}>
              <label for="cw-serial-${key}">${esc(t('concept.meters.serial'))}</label>
              <input class="input" id="cw-serial-${key}" data-slot="${key}" data-field="serial" value="${esc(v.serial || '')}" maxlength="40" autocomplete="off" spellcheck="false">
            </div>
          </div>
          ${hint ? `<p class="muted small">${esc(hint)}</p>` : ''}
        </fieldset>`;
      }).join('')}`;
  };

  const contractSlots = () => Object.entries(state.slots).filter(([, s]) => s.contract);
  const restNote = (key) => {
    const name = (k) => state.input[k]?.name || state.slots[k]?.name || '';
    if (key === 'main' && state.concept === 'behind') return t('concept.contracts.restNote', { parent: name('main'), sub: name('dev') });
    if (key === 'main' && state.concept === 'cascade') return t('concept.contracts.restNote', { parent: name('main'), sub: name('house') });
    return '';
  };
  const contractsStep = () => `
      <h3 class="setup__question">${esc(t('concept.contracts.title'))}</h3>
      <p class="muted small">${esc(t('concept.contracts.hint'))}</p>
      ${contractSlots().map(([key, slot]) => {
        const c = state.contracts[slot.contract] || {};
        const multi = Object.keys(slot.registers).length > 1;
        const feed = slot.utility === 'pv_einspeisung';
        const f = (field, lab, type = 'text', extra = '') => `<div class="field">
            <label for="cw-c-${key}-${field}">${esc(lab)}</label>
            <input class="input" id="cw-c-${key}-${field}" data-contract="${slot.contract}" data-field="${field}" type="${type}" value="${esc(c[field] ?? '')}" ${extra}>
          </div>`;
        const note = restNote(key);
        return `<fieldset class="concept-slot" data-contract-slot="${key}">
          <legend><span class="concept-tag">${esc(tags()[key] || '')}</span> ${esc(state.input[key]?.name || slot.name)} <span class="muted small">· ${esc(label(slot.utility))}</span></legend>
          <div class="fgrid">
            ${f('provider', t('contracts.modal.provider'), 'text', 'maxlength="80" autocomplete="organization"')}
            ${f('start', t('concept.contracts.start'), 'date')}
            ${f('ct', t(feed ? 'concept.contracts.feedCt' : multi ? 'concept.contracts.ctHt' : 'concept.contracts.ct'), 'text', 'inputmode="decimal"')}
            ${multi ? f('ct_nt', t('concept.contracts.ctNt'), 'text', 'inputmode="decimal"') : ''}
            ${feed ? '' : f('base', t('concept.contracts.base'), 'text', 'inputmode="decimal"') + f('advance', t('concept.contracts.advance'), 'text', 'inputmode="decimal"')}
          </div>
          ${note ? `<p class="muted small" data-role="cw-rest-note">${esc(note)}</p>` : ''}
        </fieldset>`;
      }).join('')}`;

  const actionText = (a) => {
    const name = a.name || '';
    if (a.op === 'activate') return t('concept.summary.op.activate', { list: a.utilities.map(label).join(', ') });
    const details = [];
    if ((a.op === 'create_meter' || a.op === 'update_meter') && a.data?.obis) details.push(t('concept.summary.detailObis', { obis: a.data.obis }));
    if ((a.op === 'create_meter' || a.op === 'update_meter') && a.parent_ref) {
      const [pk] = a.parent_ref.split('|');
      const parentAction = state.preview.actions.find(x => x.ref === a.parent_ref);
      details.push(t('concept.summary.detailParent', { name: parentAction?.name || state.slots[pk]?.name || pk }));
    }
    if ((a.op === 'create_meter' || a.op === 'update_meter') && a.data?.role) {
      const r = t(`meters.roles.${a.utility}.${a.data.role}`);
      if (!r.startsWith('meters.roles.')) details.push(r);
    }
    const main = t(`concept.summary.op.${a.op}`, { name, existing: a.existing || '' });
    return details.length ? `${main} (${details.join(', ')})` : main;
  };
  const summaryStep = () => {
    const p = state.preview;
    const regs = Object.fromEntries(Object.entries(p.slots).map(([k, s]) => [k, s.registers]));
    const tg = tags();
    const legend = Object.entries(p.slots).filter(([k]) => k !== 'feed' && k !== 'bat_out').map(([k, s]) => {
      const names = [state.input[k]?.name || s.name];
      if (k === 'main' && p.slots.feed) names.push(state.input.feed?.name || p.slots.feed.name);
      if (k === 'bat_in' && p.slots.bat_out) names.push(state.input.bat_out?.name || p.slots.bat_out.name);
      const codes = [...Object.values(s.registers), ...(k === 'main' && p.slots.feed ? Object.values(p.slots.feed.registers) : [])].filter(Boolean);
      return `<li><span class="concept-tag">${esc(tg[k] || '')}</span> ${esc(names.join(', '))}${codes.length ? ` <span class="muted small">${esc(codes.join(', '))}</span>` : ''}</li>`;
    }).join('');
    return `
      <h3 class="setup__question">${esc(t('concept.summary.title'))}</h3>
      <div class="concept-summary">
        <div class="concept-summary__art">${schematic(state.concept, state.setup, { registers: regs, title: t('concept.art.own') })}</div>
        <ul class="concept-legend" aria-label="${esc(t('concept.summary.legend'))}">${legend}</ul>
      </div>
      ${p.problems.length ? `<div class="banner banner--error" role="alert" data-role="cw-problems">${p.problems.map(x => `<p>${esc(t('errors.concept.' + x.code, x.params || {}))}</p>`).join('')}</div>` : ''}
      <ul class="concept-actions" data-role="cw-actions">${p.actions.map(a => `<li data-op="${esc(a.op)}">${esc(actionText(a))}</li>`).join('')}</ul>
      <p class="muted small">${esc(t('concept.summary.snapshot'))}</p>`;
  };
  const html = { setup: setupStep, layout: layoutStep, meters: metersStep, contracts: contractsStep, summary: summaryStep };

  /** Vorschau holen; die Stellen kommen vom Server (Namen, Zählwerke, Verträge). */
  const loadSlots = async () => {
    const p = await api.conceptPreview(conceptPayload(state.concept, state.setup, {}, {}));
    const keep = state.slots && Object.keys(p.slots).join() === Object.keys(state.slots).join();
    state.slots = p.slots;
    if (!keep) {
      const suggested = suggestAssignments(p.slots, meters);
      state.input = Object.fromEntries(Object.entries(p.slots).map(([k, s]) => [k, { name: s.name, serial: '', ...(suggested[k] || {}), obis: { ...s.registers } }]));
      state.contracts = Object.fromEntries(Object.values(p.slots).filter(s => s.contract).map(s => [s.contract, { start: todayIso() }]));
    }
  };

  const ctrl = openModal({
    title: t('concept.title'),
    size: 'lg',
    body: '<div class="setup concept-wizard" data-role="concept-body"></div>',
    footer: `
      <button type="button" class="btn btn--ghost" data-act="cancel">${esc(t('common.cancel'))}</button>
      <span class="setup__progress muted small" data-role="progress"></span>
      <button type="button" class="btn btn--ghost" data-act="back">${esc(t('setup.back'))}</button>
      <button type="button" class="btn btn--primary" data-act="next">${esc(t('setup.next'))}</button>`,
    onMount({ modalEl, close }) {
      const body = modalEl.querySelector('[data-role="concept-body"]');
      const next = modalEl.querySelector('[data-act="next"]');
      const back = modalEl.querySelector('[data-act="back"]');
      const draw = (focusSel) => {
        const name = CONCEPT_STEPS[state.step];
        body.innerHTML = html[name]();
        body.dataset.step = name;
        modalEl.querySelector('[data-role="progress"]').textContent = t('setup.progress', { n: state.step + 1, total: CONCEPT_STEPS.length });
        back.hidden = state.step === 0;
        next.textContent = t(name === 'summary' ? 'concept.summary.apply' : 'setup.next');
        next.disabled = name === 'summary' && state.preview.problems.length > 0;
        (focusSel && body.querySelector(focusSel) || body.querySelector('input:not([disabled]), select'))?.focus();
      };
      body.addEventListener('change', (e) => {
        const el = e.target;
        if (el.name === 'cw-pv') { state.setup = normalizeSetup({ ...state.setup, pv: el.checked }); setConcept(); draw('input[name="cw-pv"]'); return; }
        if (el.name === 'cw-battery') { state.setup = normalizeSetup({ ...state.setup, battery: el.checked }); return; }
        if (el.name === 'cw-device') { state.setup = normalizeSetup({ ...state.setup, device: el.value || null }); setConcept(); draw(`input[name="cw-device"][value="${el.value}"]`); return; }
        if (el.name === 'cw-tou') { state.setup = normalizeSetup({ ...state.setup, tou: el.value }); setConcept(); return; }
        if (el.name === 'cw-concept') { state.concept = el.value; draw(`input[name="cw-concept"][value="${el.value}"]`); return; }
        if (el.dataset.field === 'existing') {
          const v = state.input[el.dataset.slot] ||= {};
          const multi = Object.keys(state.slots[el.dataset.slot].registers).length > 1;
          if (multi) { v.meter_ids = { ...(v.meter_ids || {}), [el.dataset.reg]: el.value || undefined }; }
          else v.meter_id = el.value || undefined;
          draw(`#${el.id}`);
        }
      });
      body.addEventListener('input', (e) => {
        const el = e.target;
        if (el.dataset.slot && el.dataset.field === 'name') (state.input[el.dataset.slot] ||= {}).name = el.value;
        if (el.dataset.slot && el.dataset.field === 'serial') (state.input[el.dataset.slot] ||= {}).serial = el.value;
        if (el.dataset.slot && el.dataset.field === 'obis') {
          const v = state.input[el.dataset.slot] ||= {};
          v.obis = { ...(v.obis || {}), [el.dataset.reg]: el.value };
        }
        if (el.dataset.contract) (state.contracts[el.dataset.contract] ||= {})[el.dataset.field] = el.value;
      });
      modalEl.querySelector('[data-act="cancel"]').addEventListener('click', () => close(null));
      back.addEventListener('click', () => { state.step = Math.max(0, state.step - 1); draw(); });
      next.addEventListener('click', async () => {
        const name = CONCEPT_STEPS[state.step];
        next.disabled = true;
        try {
          if (name === 'layout') await loadSlots();
          // Verträge ohne Eintrag fallen weg (später unter Verträge)
          if (name === 'contracts') state.preview = await api.conceptPreview(payload());
          if (name === 'summary') {
            const res = await api.conceptApply(payload());
            if ((res.actions || []).some(a => a.op === 'activate')) {
              invalidateSettings();
              const s = await getSettings().catch(() => null);
              if (s) window.dispatchEvent(new CustomEvent('et:settingschange', { detail: { keys: ['active_utilities'], settings: s } }));
            }
            toastOk(t('concept.done'));
            close(res);
            return;
          }
          state.step++;
          draw();
        } catch (err) {
          toastErr(err.message);
          next.disabled = false;
        }
      });
      draw();
    },
  });
  return ctrl.closedPromise;
}
