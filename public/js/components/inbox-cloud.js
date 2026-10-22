// =====================================================================
// v3.7.0 — Karte „Auslesen“ (Einstellungen → Experte). Wer die Post im
// Posteingang ausliest, an EINER Stelle (Entscheidung 2026-10-21):
//   - Umschalter Lokal | Cloud; die Wahl ist `inbox_provider` (keine zweite
//     Stelle). Der Server prüft dasselbe (SettingsService::guardInboxProvider).
//   - Lokal: Adresse und Schnittstelle des eigenen Diensts (`ocr_endpoint`,
//     `ocr_api`, gemeinsam mit der Texterkennung der Zählerfotos), Textmodell
//     und Zeitlimit des Posteingangs, „Verbindung prüfen“.
//   - Cloud (Paket cloud, experimentell): Anbieter samt Voreinstellungen,
//     Schlüssel (nie angezeigt), Modell aus der Liste des Diensts, Zeitlimit,
//     „Eingeschaltet“ (das Opt-in).
//   - Der Schlüssel geht nur zum Server; zurück kommt nur `has_key`.
//   - Ändern dürfen nur Verwalter (der Server prüft es ebenfalls, 403);
//     Mitglieder speichern nur die lokalen Felder, die ihnen offenstehen.
//   - Eigene Speichern-Taste; die Felder tragen kein data-key, die
//     Speicherleiste der Seite sieht sie nicht (`data-field` für Sprünge).
// =====================================================================

import { api } from '../api.js';
import { invalidateSettings, saveSettings } from '../state.js';
import { t, tp } from '../lib/i18n.js';
import { escapeHtml, parseDecimal } from '../lib/format.js';
import { toastOk, toastErr } from './toast.js';
import { confirmModal } from './modal.js';

const providerOf = (status, id) => (status?.providers || []).find(p => p.id === id) || {};
const presetOf = (status, id) => (status?.presets || []).find(p => p.id === id) || {};
const presetLabel = (p) => p.label || t('settings.reading.preset.custom');
const hostOf = (url) => { try { return new URL(String(url || '').trim()).hostname; } catch { return ''; } };
const OCR_APIS = ['ollama', 'openai'];

// Ansicht nach dem Speichern auf der Cloud-Seite: dort bleiben, auch wenn die
// Cloud (noch) nicht ausliest — sonst sprang die Karte mitten in der
// Einrichtung (Schlüssel gespeichert, Modell noch nicht gewählt) auf „Lokal“.
let nextView = null;
// Modellliste: gleich nach dem Speichern eines Schlüssels, sonst auf Knopfdruck —
// beim bloßen Ansehen der Seite fragt die Karte keinen Dienst
let loadAfterSave = false;

// Stand der Karte. Wer ausliest, entscheidet `inbox_provider`: eingeschaltet
// und gewählt → Dokumente gehen hinaus; eingeschaltet, aber „Lokal“ gewählt →
// nichts geht hinaus; eingeschaltet ohne Schlüssel (etwa nach dem Einspielen
// einer Sicherung) → es geht nichts hinaus.
function stateOf(status, active = status.active) {
  const cur = providerOf(status, status.provider);
  const name = cur.label || status.provider;
  if (status.ready && active) return { warn: true, text: t('settings.inboxCloud.stateOn', { name, host: cur.destination || '–' }) };
  if (status.ready) return { warn: false, text: t('settings.inboxCloud.stateReady', { name }) };
  return { warn: false, text: t(status.enabled ? 'settings.inboxCloud.stateNoKey' : 'settings.inboxCloud.stateOff') };
}

const hintHtml = (id, text) => text ? `<span class="settings-field__hint" id="${id}">${escapeHtml(text)}</span>` : '';
const fieldText = (key) => {
  const h = t(`settings.field.${key}.hint`);
  return { label: t(`settings.field.${key}.label`), hint: h === `settings.field.${key}.hint` ? '' : h };
};

function localPanel(settings, isAdmin) {
  const ep = fieldText('ocr_endpoint'), ap = fieldText('ocr_api'), md = fieldText('inbox_model'), to = fieldText('inbox_timeout_s');
  const api = OCR_APIS.includes(settings.ocr_api) ? settings.ocr_api : 'ollama';
  return `<div class="settings-fields" data-role="rd-local">
      <p class="settings-field__hint" data-role="rd-shared">${escapeHtml(t('settings.reading.localShared'))}</p>
      <div class="field settings-field">
        <label for="rd-endpoint">${escapeHtml(ep.label)}</label>
        <input class="input input--text" type="text" inputmode="url" id="rd-endpoint" data-field="ocr_endpoint" spellcheck="false" autocapitalize="off"
               value="${escapeHtml(settings.ocr_endpoint || '')}" placeholder="${escapeHtml(t('settings.placeholder.ocrEndpoint'))}" aria-describedby="rd-endpoint-hint"${isAdmin ? '' : ' disabled'}>
        ${hintHtml('rd-endpoint-hint', ep.hint)}
      </div>
      <div class="field settings-field">
        <label for="rd-api">${escapeHtml(ap.label)}</label>
        <select class="select" id="rd-api" data-field="ocr_api" aria-describedby="rd-api-hint">
          ${OCR_APIS.map(o => `<option value="${o}" ${o === api ? 'selected' : ''}>${escapeHtml(t(`settings.ocrApis.${o}`))}</option>`).join('')}
        </select>
        ${hintHtml('rd-api-hint', ap.hint)}
      </div>
      ${isAdmin ? `<div class="settings-field">
        <button type="button" class="btn btn--ghost btn--sm" data-role="rd-check">${escapeHtml(t('settings.reading.check'))}</button>
        <span class="settings-field__hint" data-role="rd-check-result" role="status"></span>
      </div>` : ''}
      <div class="field settings-field">
        <label for="rd-model">${escapeHtml(md.label)}</label>
        <input class="input input--text" type="text" id="rd-model" data-field="inbox_model" spellcheck="false" autocapitalize="off"
               value="${escapeHtml(settings.inbox_model ?? '')}" placeholder="${escapeHtml(t('settings.placeholder.inboxModel'))}" aria-describedby="rd-model-hint">
        ${hintHtml('rd-model-hint', md.hint)}
      </div>
      <div class="field settings-field">
        <label for="rd-timeout">${escapeHtml(to.label)} <span class="settings-field__unit">${escapeHtml(t('settings.unit.seconds'))}</span></label>
        <input class="input" type="text" inputmode="numeric" id="rd-timeout" data-field="inbox_timeout_s" value="${escapeHtml(String(settings.inbox_timeout_s ?? ''))}" aria-describedby="rd-timeout-hint">
        ${hintHtml('rd-timeout-hint', to.hint)}
      </div>
    </div>`;
}

function cloudPanel(status, dis, isAdmin) {
  return `<div class="settings-fields" data-role="rd-cloud">
      <p class="banner banner--warning" data-role="ic-experimental">${escapeHtml(t('inboxCloud.experimental'))}</p>
      <p class="banner banner--warning" data-role="ic-privacy">${escapeHtml(t('settings.inboxCloud.privacy'))}</p>
      ${isAdmin ? '' : `<p class="muted" data-role="ic-admin-only">${escapeHtml(t('settings.inboxCloud.adminOnly'))}</p>`}
      <div class="field settings-field">
        <label for="ic-provider">${escapeHtml(t('settings.inboxCloud.provider'))}</label>
        <select class="select" id="ic-provider" aria-describedby="ic-sends"${dis}>
          ${(status.presets || []).map(p => `<option value="${escapeHtml(p.id)}" ${p.id === status.preset ? 'selected' : ''}>${escapeHtml(presetLabel(p))}</option>`).join('')}
        </select>
        <span class="settings-field__hint" id="ic-sends" data-role="ic-sends"></span>
      </div>
      <div class="field settings-field" data-role="ic-endpoint-field">
        <label for="ic-endpoint">${escapeHtml(t('settings.inboxCloud.endpoint'))}</label>
        <input class="input input--text" type="text" inputmode="url" id="ic-endpoint" spellcheck="false" autocapitalize="off"
               value="${escapeHtml(status.preset === 'custom' ? (status.endpoint || '') : '')}" placeholder="https://api.example.com/v1" aria-describedby="ic-endpoint-hint"${dis}>
        <span class="settings-field__hint" id="ic-endpoint-hint">${escapeHtml(t('settings.inboxCloud.endpointHint'))}</span>
      </div>
      <div class="field settings-field">
        <label for="ic-key">${escapeHtml(t('settings.inboxCloud.key'))}</label>
        <input class="input input--text" type="password" id="ic-key" autocomplete="new-password" spellcheck="false"
               value="" aria-describedby="ic-key-hint"${dis}>
        <span class="settings-field__hint" id="ic-key-hint" data-role="ic-key-hint"></span>
        ${isAdmin ? `<div><button type="button" class="btn btn--ghost btn--sm" data-role="ic-clear-key" hidden>${escapeHtml(t('settings.inboxCloud.keyClear'))}</button></div>` : ''}
      </div>
      <div class="field settings-field">
        <label for="ic-model">${escapeHtml(t('settings.inboxCloud.model'))}</label>
        <select class="select" id="ic-model-list" data-role="ic-model-list" aria-label="${escapeHtml(t('settings.reading.modelList'))}" hidden${dis}></select>
        <input class="input input--text" type="text" id="ic-model" spellcheck="false" autocapitalize="off"
               value="${escapeHtml(status.model || '')}" aria-describedby="ic-model-hint"${dis}>
        <span class="settings-field__hint" id="ic-model-hint" data-role="ic-model-hint"></span>
        ${isAdmin ? `<div><button type="button" class="btn btn--ghost btn--sm" data-role="ic-model-load" hidden>${escapeHtml(t('settings.reading.modelLoad'))}</button></div>` : ''}
      </div>
      <div class="field settings-field">
        <label for="ic-timeout">${escapeHtml(t('settings.inboxCloud.timeout'))} <span class="settings-field__unit">${escapeHtml(t('settings.unit.seconds'))}</span></label>
        <input class="input" type="text" inputmode="numeric" id="ic-timeout" value="${escapeHtml(String(status.timeout_s ?? ''))}"${dis}>
      </div>
      <div class="field settings-field">
        <label class="settings-field__check">
          <input type="checkbox" id="ic-enabled" aria-describedby="ic-enabled-hint" ${status.enabled ? 'checked' : ''}${dis}> ${escapeHtml(t('settings.inboxCloud.enabled'))}
        </label>
        <span class="settings-field__hint" id="ic-enabled-hint">${escapeHtml(t('settings.inboxCloud.enabledHint'))}</span>
      </div>
    </div>`;
}

export function renderReadingCard(status, settings, isAdmin) {
  if (!status || !settings) return '';
  const dis = isAdmin ? '' : ' disabled';
  const state = stateOf(status);
  // die Wahl ist `inbox_provider`; nach dem Speichern auf der Cloud-Seite bleibt die Ansicht dort
  const view = nextView || ((settings.inbox_provider || 'local') !== 'local' ? 'cloud' : 'local');
  nextView = null;
  const radio = (v, label) => `<label class="seg__btn"><input type="radio" name="rd-mode" value="${v}" ${view === v ? 'checked' : ''}${dis}> ${escapeHtml(label)}</label>`;
  return `<div class="card settings-card" id="inbox-reading-card" data-view="${view}">
    <h2 class="card__title"><span aria-hidden="true">📥</span> ${escapeHtml(t('settings.reading.title'))}</h2>
    <p class="settings-card__hint">${escapeHtml(t('settings.reading.hint'))}</p>
    <p class="banner ${state.warn ? 'banner--warning' : 'banner--info'}" data-role="ic-state">${escapeHtml(state.text)}</p>
    <form class="settings-fields" data-role="ic-form" autocomplete="off" novalidate>
      <fieldset class="seg seg--radio" data-role="rd-mode">
        <legend class="settings-field__label">${escapeHtml(t('settings.reading.mode'))}</legend>
        ${radio('local', t('settings.reading.modeLocal'))}
        ${radio('cloud', t('settings.reading.modeCloud'))}
      </fieldset>
      ${localPanel(settings, isAdmin)}
      ${cloudPanel(status, dis, isAdmin)}
      <div><button type="submit" class="btn btn--primary btn--sm" id="ic-save">${escapeHtml(t('common.save'))}</button></div>
    </form>
  </div>`;
}

export function wireReadingCard(container, status, settings, isAdmin, rerender) {
  const card = container.querySelector('#inbox-reading-card');
  if (!card || !status || !settings) return;
  const $ = (sel) => card.querySelector(sel);
  const mode = () => card.querySelector('input[name="rd-mode"]:checked')?.value || 'local';
  const chosen = () => $('#ic-provider')?.value || status.preset;
  const providerFor = (presetId) => presetOf(status, presetId).provider || 'anthropic';
  // gerade geladene Modellliste (nur für die gespeicherte Voreinstellung)
  let list = null;

  // Felder je Anbieter: Adresse nur bei „eigene Adresse“, Hinweise zu Modell,
  // Schlüssel und dem, was hinausgeht
  const sync = () => {
    const view = mode();
    card.dataset.view = view;
    $('[data-role="rd-local"]').hidden = view !== 'local';
    $('[data-role="rd-cloud"]').hidden = view !== 'cloud';
    const id = chosen();
    const pre = presetOf(status, id);
    const p = providerOf(status, providerFor(id));
    const hasKey = !!pre.has_key;
    const field = $('[data-role="ic-endpoint-field"]');
    if (field) field.hidden = id !== 'custom';
    const sends = $('[data-role="ic-sends"]');
    if (sends) sends.textContent = p.sends ? t(`settings.inboxCloud.sends.${p.sends}`) : '';
    const keyHint = $('[data-role="ic-key-hint"]');
    if (keyHint) keyHint.textContent = t(hasKey ? 'settings.inboxCloud.keyStored' : 'settings.inboxCloud.keyMissing');
    const clear = $('[data-role="ic-clear-key"]');
    if (clear) clear.hidden = !hasKey || id !== status.preset;
    const model = $('#ic-model');
    if (model) model.placeholder = p.default_model || '';
    syncModel();
  };

  // Modell: Liste des Diensts, wenn geladen; sonst frei einzutragen
  const syncModel = () => {
    const sel = $('#ic-model-list');
    const input = $('#ic-model');
    const hint = $('[data-role="ic-model-hint"]');
    if (!sel || !input || !hint) return;
    const p = providerOf(status, providerFor(chosen()));
    const base = p.default_model ? t('settings.inboxCloud.modelDefault', { model: p.default_model }) : t('settings.inboxCloud.modelRequired');
    const same = chosen() === status.preset;
    const load = $('[data-role="ic-model-load"]');
    if (load) load.hidden = !(same && status.has_key && !list?.ok);
    if (list?.ok && same) {
      sel.hidden = false;
      input.hidden = sel.value !== '';
      hint.textContent = tp('settings.reading.modelListLoaded', list.models.length, { name: presetLabel(presetOf(status, status.preset)) });
      return;
    }
    sel.hidden = true;
    input.hidden = false;
    if (list && !list.ok && same) hint.textContent = `${t('settings.reading.modelListFailed', { reason: list.error })} ${base}`;
    else hint.textContent = presetOf(status, chosen()).has_key && same ? base : `${base} ${t('settings.reading.modelListAfterSave')}`;
  };

  const fillList = (models) => {
    const sel = $('#ic-model-list');
    const input = $('#ic-model');
    if (!sel || !input) return;
    const p = providerOf(status, status.provider);
    const current = input.value.trim() || p.default_model || '';
    const known = models.some(m => m.id === current);
    sel.innerHTML = models.map(m => `<option value="${escapeHtml(m.id)}" ${m.id === current ? 'selected' : ''}>${escapeHtml(m.label ? `${m.label} (${m.id})` : m.id)}</option>`).join('')
      + `<option value="" ${known ? '' : 'selected'}>${escapeHtml(t('settings.reading.modelOther'))}</option>`;
  };

  // nach dem Speichern des Schlüssels: Modelle des Diensts (nur Verwalter; der Schlüssel bleibt auf dem Server)
  const loadModels = async () => {
    if (!isAdmin || list?.ok || !status.has_key || mode() !== 'cloud') return;
    list = { ok: false, error: '…', models: [] };
    try {
      const r = await api.inboxCloudModels();
      list = { ok: Array.isArray(r?.models) && r.models.length > 0, error: '', models: r?.models || [] };
      if (list.ok) fillList(list.models);
      else list.error = t('settings.reading.modelListEmpty');
    } catch (err) {
      list = { ok: false, error: err.message, models: [] };
    }
    if (card.isConnected) syncModel();
  };

  card.querySelectorAll('input[name="rd-mode"]').forEach(r => r.addEventListener('change', sync));
  $('[data-role="ic-model-load"]')?.addEventListener('click', loadModels);
  $('#ic-model-list')?.addEventListener('change', (e) => {
    const input = $('#ic-model');
    if (input && e.target.value !== '') input.value = e.target.value;
    syncModel();
    if (input && e.target.value === '') input.focus();
  });

  // „Verbindung prüfen“ (eigener Dienst): mit dem, was im Feld steht, auch ungespeichert
  $('[data-role="rd-check"]')?.addEventListener('click', async () => {
    const out = $('[data-role="rd-check-result"]');
    if (out) out.textContent = '…';
    try {
      const r = await api.inboxLocalCheck(($('#rd-endpoint')?.value || '').trim(), $('#rd-api')?.value || 'ollama');
      const model = ($('#rd-model')?.value || '').trim() || r.model;
      const found = model && (r.models || []).some(m => m === model || m === `${model}:latest`);
      const parts = [tp('settings.reading.checkOk', (r.models || []).length, { host: r.host || '–' })];
      if (model) parts.push(t(found ? 'settings.reading.checkModelFound' : 'settings.reading.checkModelMissing', { model }));
      if (out) out.textContent = parts.join(' ');
    } catch (err) {
      if (out) out.textContent = err.message;
    }
  });

  // Wahl der Posteingangs-Karte gespeichert: der Stand folgt, ohne die Seite neu zu zeichnen.
  // Der Hörer meldet sich ab, sobald die Karte nicht mehr im Dokument steht (Lektion 43).
  const onSettings = (e) => {
    if (!card.isConnected) { window.removeEventListener('et:settingschange', onSettings); return; }
    const reader = e.detail?.settings?.inbox_provider;
    if (!(e.detail?.keys || []).includes('inbox_provider') || typeof reader !== 'string') return;
    const state = stateOf(status, status.ready && reader === status.provider);
    const el = $('[data-role="ic-state"]');
    if (el) { el.textContent = state.text; el.className = `banner ${state.warn ? 'banner--warning' : 'banner--info'}`; }
  };
  window.addEventListener('et:settingschange', onSettings);

  $('#ic-provider')?.addEventListener('change', () => {
    // Das Modell gehört zum Anbieter: beim Wechsel leeren (= Standard)
    const model = $('#ic-model');
    if (model && chosen() !== status.preset) model.value = '';
    if (model && chosen() === status.preset) model.value = status.model || '';
    sync();
  });
  sync();
  if (loadAfterSave) { loadAfterSave = false; loadModels(); }

  // Lokale Felder: was sich gegenüber dem Stand geändert hat (Mitglieder: ohne die Adresse)
  const localPatch = () => {
    const patch = {};
    const add = (key, value) => { if (value !== undefined && String(value) !== String(settings[key] ?? '')) patch[key] = value; };
    if (isAdmin) add('ocr_endpoint', ($('#rd-endpoint')?.value || '').trim());
    add('ocr_api', $('#rd-api')?.value);
    add('inbox_model', ($('#rd-model')?.value || '').trim());
    const timeout = parseDecimal($('#rd-timeout')?.value || '');
    if (timeout !== null) add('inbox_timeout_s', Math.round(timeout));
    return patch;
  };

  const saveCloud = async () => {
    const presetId = chosen();
    const provider = providerFor(presetId);
    let enabled = !!$('#ic-enabled')?.checked;
    const listSel = $('#ic-model-list');
    const model = (listSel && !listSel.hidden && listSel.value !== '' ? listSel.value : ($('#ic-model')?.value || '')).trim();
    const key = ($('#ic-key')?.value || '').trim();
    // Ohne Modell geht ein OpenAI-kompatibler Dienst nicht an: erst Schlüssel
    // speichern (dann lädt die Liste), Einschalten folgt mit dem Modell
    let pickModel = false;
    if (enabled && provider === 'openai' && !model && !(status.enabled && status.preset === presetId)) { enabled = false; pickModel = true; }
    // Einschalten ist das Opt-in: einmal ausdrücklich bestätigen, was wohin geht —
    // derselbe Hinweis wie vor jedem Auslesen, mit der eben eingetragenen Adresse
    if (enabled && !(status.enabled && status.provider === provider)) {
      const p = providerOf(status, provider);
      const pre = presetOf(status, presetId);
      const host = presetId === 'custom' ? hostOf($('#ic-endpoint')?.value) : (pre.host || p.destination);
      const ok = await confirmModal({
        title: t('settings.inboxCloud.confirmTitle'),
        message: t(`inboxCloud.notice.${p.sends === 'pdf' ? 'pdf' : 'text'}`, { name: pre.label || p.label || provider, host: host || '–' }),
        detail: t('inboxCloud.experimental'),
        confirmLabel: t('settings.inboxCloud.confirmOn'),
      });
      if (!ok) return false;
    }
    const body = { preset: presetId, enabled, model, select: true };
    if (presetId === 'custom') body.endpoint = ($('#ic-endpoint')?.value || '').trim();
    const timeout = parseDecimal($('#ic-timeout')?.value || '');
    if (timeout !== null) body.timeout_s = Math.round(timeout);
    if (key) body.api_key = key;
    await api.updateInboxCloud(body);
    loadAfterSave = !!key;   // „Nach dem Speichern des Schlüssels lädt die Karte die Modelle“
    const keyInput = $('#ic-key');
    if (keyInput) keyInput.value = '';   // nie im Formular stehen lassen
    // die lokalen Felder gelten auch für die Zählerfotos: Geändertes mitspeichern
    const local = localPatch();
    if (Object.keys(local).length) await saveSettings(local);
    invalidateSettings();   // inbox_provider und inbox_cloud_* stehen auch in den Einstellungen
    toastOk(t(pickModel ? 'settings.reading.pickModel' : 'settings.reading.saved'));
    return true;
  };

  $('form[data-role="ic-form"]')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      if (mode() === 'cloud' && isAdmin) {
        if (!(await saveCloud())) return;
        nextView = 'cloud';
      } else {
        const patch = localPatch();
        if (isAdmin && (settings.inbox_provider || 'local') !== 'local') patch.inbox_provider = 'local';
        if (Object.keys(patch).length) await saveSettings(patch);
        toastOk(t('settings.reading.saved'));
      }
      rerender();
    } catch (err) { toastErr(err.message); }
  });

  $('[data-role="ic-clear-key"]')?.addEventListener('click', async () => {
    const provider = providerFor(chosen());
    const ok = await confirmModal({
      title: t('settings.inboxCloud.keyClear'),
      message: t('settings.inboxCloud.keyClearConfirm', { name: presetLabel(presetOf(status, chosen())) }),
      confirmLabel: t('settings.inboxCloud.keyClear'), danger: true,
    });
    if (!ok) return;
    try {
      // ohne Schlüssel bleibt der gewählte Anbieter nicht eingeschaltet; die Auswahl bleibt
      await api.updateInboxCloud({ clear_key: provider, ...(status.enabled && status.provider === provider ? { enabled: false } : {}) });
      invalidateSettings();
      toastOk(t('settings.reading.saved'));
      nextView = 'cloud';
      rerender();
    } catch (err) { toastErr(err.message); }
  });
}
