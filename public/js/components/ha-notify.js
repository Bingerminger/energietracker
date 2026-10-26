// =====================================================================
// v3.9.0 (F1033, GitHub #36) — Karte „Benachrichtigungen an Home Assistant“
// (Einstellungen → Integrationen).
//   - Ziel: über den Supervisor (nur in der App für Home Assistant, ohne
//     weitere Einrichtung), Webhook oder Adresse und Token.
//   - Webhook-ID und Token gehen nur zum Server; zurück kommt nur
//     `has_webhook` bzw. `has_token`. Ein leeres Feld lässt das Gespeicherte
//     stehen. „Neue ID erzeugen“ füllt eine zufällige ID ein und zeigt sie in
//     der Vorlage für Home Assistant, bis gespeichert ist.
//   - Ändern dürfen nur Verwalter (der Server prüft es ebenfalls, 403).
//   - Eigene Speichern-Taste; die Felder tragen kein data-key, die
//     Speicherleiste der Seite sieht sie nicht.
// =====================================================================

import { api } from '../api.js';
import { t } from '../lib/i18n.js';
import { escapeHtml, intlLocale } from '../lib/format.js';
import { haWebhookAutomationYaml } from '../lib/ha-snippet.js';
import { copyText } from '../lib/clipboard.js';
import { toastOk, toastErr } from './toast.js';

const TARGETS = ['supervisor', 'webhook', 'token'];
const EVENTS = ['reminder', 'deadline', 'warning', 'document'];

/** Zeitstempel in der Sprache der Oberfläche. */
const stamp = (iso) => {
  const d = iso ? new Date(iso) : null;
  return !d || Number.isNaN(d.getTime()) ? '–' : d.toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' });
};

/** Grund eines Fehlschlags in Worten (Fehlercodes von NotifyTransport). */
export function reasonText(r) {
  if (!r?.error) return '';
  if (r.error === 'http' && r.status === 401) return t('settings.haNotify.error.unauthorized');
  const text = t(`settings.haNotify.error.${r.error}`, { status: r.status ?? '' });
  return text === `settings.haNotify.error.${r.error}` ? t('settings.haNotify.error.network') : text;
}

/** Zufällige Webhook-ID (wie Home Assistant sie selbst vorschlägt: nicht zu erraten). */
export function newWebhookId() {
  const bytes = new Uint8Array(18);
  crypto.getRandomValues(bytes);
  return 'energietracker_' + Array.from(bytes, b => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36]).join('');
}

/** Ziel, das die Karte zeigt: gespeichert, außer der Supervisor fehlt (Docker, NAS). */
const shownTarget = (s) => (s.target === 'supervisor' && !s.supervisor_available ? 'webhook' : (TARGETS.includes(s.target) ? s.target : 'webhook'));

function stateOf(s) {
  if (s.enabled && s.ready) return { cls: 'banner--success', text: t('settings.haNotify.state.on', { target: t(`settings.haNotify.target.${s.target}`) }) };
  if (s.enabled) return { cls: 'banner--warning', text: t('settings.haNotify.state.incomplete') };
  return { cls: 'banner--info', text: t('settings.haNotify.state.off') };
}

function lastText(s) {
  const l = s.last;
  if (!l) return '';
  return l.ok ? t('settings.haNotify.last', { when: stamp(l.at) })
    : t('settings.haNotify.lastFailed', { when: stamp(l.at), reason: reasonText(l) });
}

export function renderHaNotifyCard(status, isAdmin = true) {
  if (!status) return '';
  const s = status;
  const dis = isAdmin ? '' : ' disabled';
  const target = shownTarget(s);
  const state = stateOf(s);
  const radio = (v) => {
    const off = v === 'supervisor' && !s.supervisor_available;
    return `<label class="settings-field__check"><input type="radio" name="han-target" value="${v}" ${v === target ? 'checked' : ''}${off ? ' disabled' : dis}>
      ${escapeHtml(t(`settings.haNotify.target.${v}`))}</label>`;
  };
  const hours = Array.from({ length: 24 }, (_, h) => `<option value="${h}" ${h === Number(s.hour) ? 'selected' : ''}>${escapeHtml(t('settings.haNotify.hourOption', { hour: String(h).padStart(2, '0') }))}</option>`).join('');
  return `<div class="card settings-card" id="ha-notify-card">
    <h2 class="card__title"><span aria-hidden="true">🔔</span> ${escapeHtml(t('settings.haNotify.title'))}</h2>
    <p class="settings-card__hint">${escapeHtml(t('settings.haNotify.intro'))}</p>
    <p class="banner ${state.cls}" data-role="han-state">${escapeHtml(state.text)}</p>
    <p class="settings-field__hint" data-role="han-privacy">${escapeHtml(t('settings.haNotify.privacy'))}</p>
    ${isAdmin ? '' : `<p class="muted" data-role="han-admin-only">${escapeHtml(t('settings.haNotify.adminOnly'))}</p>`}
    <form class="settings-fields" data-role="han-form" autocomplete="off" novalidate>
      <div class="field settings-field">
        <label class="settings-field__check">
          <input type="checkbox" id="han-enabled" ${s.enabled ? 'checked' : ''}${dis}> ${escapeHtml(t('settings.haNotify.enabled'))}
        </label>
      </div>
      <fieldset class="field settings-field" data-role="han-targets">
        <legend class="settings-field__label">${escapeHtml(t('settings.haNotify.targetLabel'))}</legend>
        ${TARGETS.map(radio).join('')}
        ${s.supervisor_available ? '' : `<span class="settings-field__hint" data-role="han-no-supervisor">${escapeHtml(t('settings.haNotify.supervisorMissing'))}</span>`}
      </fieldset>
      <div class="field settings-field" data-role="han-url-field">
        <label for="han-url">${escapeHtml(t('settings.haNotify.url'))}</label>
        <input class="input input--text" type="text" inputmode="url" id="han-url" spellcheck="false" autocapitalize="off"
               value="${escapeHtml(s.url || '')}" placeholder="http://192.168.178.10:8123" aria-describedby="han-url-hint"${dis}>
        <span class="settings-field__hint" id="han-url-hint">${escapeHtml(t('settings.haNotify.urlHint'))}</span>
      </div>
      <div class="field settings-field" data-role="han-webhook-field">
        <label for="han-webhook">${escapeHtml(t('settings.haNotify.webhook'))}</label>
        <input class="input input--text" type="text" id="han-webhook" spellcheck="false" autocapitalize="off" autocomplete="off"
               value="" placeholder="${escapeHtml(s.has_webhook ? t('settings.haNotify.secretStored') : '')}" aria-describedby="han-webhook-hint"${dis}>
        <span class="settings-field__hint" id="han-webhook-hint">${escapeHtml(t('settings.haNotify.webhookHint'))}</span>
        ${isAdmin ? `<div class="section-actions">
          <button type="button" class="btn btn--ghost btn--sm" data-role="han-new-id">${escapeHtml(t('settings.haNotify.newId'))}</button>
          ${s.has_webhook ? `<button type="button" class="btn btn--ghost btn--sm" data-role="han-clear-webhook">${escapeHtml(t('settings.haNotify.secretClear'))}</button>` : ''}
        </div>` : ''}
      </div>
      <div class="field settings-field" data-role="han-token-field">
        <label for="han-token">${escapeHtml(t('settings.haNotify.token'))}</label>
        <input class="input input--text" type="password" id="han-token" autocomplete="new-password" spellcheck="false"
               value="" placeholder="${escapeHtml(s.has_token ? t('settings.haNotify.secretStored') : '')}" aria-describedby="han-token-hint"${dis}>
        <span class="settings-field__hint" id="han-token-hint">${escapeHtml(t('settings.haNotify.tokenHint'))}</span>
        ${isAdmin && s.has_token ? `<div><button type="button" class="btn btn--ghost btn--sm" data-role="han-clear-token">${escapeHtml(t('settings.haNotify.secretClear'))}</button></div>` : ''}
      </div>
      <div class="field settings-field" data-role="han-service-field">
        <label for="han-service">${escapeHtml(t('settings.haNotify.service'))}</label>
        <input class="input input--text" type="text" id="han-service" spellcheck="false" autocapitalize="off"
               value="${escapeHtml(s.service || 'persistent_notification.create')}" aria-describedby="han-service-hint"${dis}>
        <span class="settings-field__hint" id="han-service-hint">${escapeHtml(t('settings.haNotify.serviceHint'))}</span>
      </div>
      <fieldset class="field settings-field" data-role="han-events">
        <legend class="settings-field__label">${escapeHtml(t('settings.haNotify.events'))}</legend>
        ${EVENTS.map(e => `<label class="settings-field__check"><input type="checkbox" data-han-event="${e}" ${(s.events || []).includes(e) ? 'checked' : ''}${dis}> ${escapeHtml(t(`settings.haNotify.event.${e}`))}</label>`).join('')}
      </fieldset>
      <div class="field settings-field">
        <label for="han-hour">${escapeHtml(t('settings.haNotify.hour'))}</label>
        <select class="select" id="han-hour" aria-describedby="han-hour-hint"${dis}>${hours}</select>
        <span class="settings-field__hint" id="han-hour-hint">${escapeHtml(t('settings.haNotify.hourHint'))}</span>
      </div>
      <div class="section-actions">
        <button type="submit" class="btn btn--primary btn--sm" id="han-save"${dis}>${escapeHtml(t('common.save'))}</button>
        <button type="button" class="btn btn--sm" id="han-test"${dis}>${escapeHtml(t('settings.haNotify.test'))}</button>
      </div>
      <p class="settings-field__hint" data-role="han-last">${escapeHtml(lastText(s))}</p>
      <div data-role="han-yaml-block">
        <h4 class="settings-subhead">${escapeHtml(t('settings.haNotify.yamlTitle'))}</h4>
        <p class="muted" style="font-size:12px;margin:0 0 var(--sp-2)">${escapeHtml(t('settings.haNotify.yamlHint'))}</p>
        <pre class="code-block" id="han-yaml"><code>${escapeHtml(haWebhookAutomationYaml(''))}</code></pre>
        <div class="section-actions"><button type="button" class="btn btn--sm" data-role="han-copy">${escapeHtml(t('settings.ha.copyYaml'))}</button></div>
      </div>
    </form>
  </div>`;
}

export function wireHaNotifyCard(container, status, isAdmin, rerender) {
  const card = container.querySelector('#ha-notify-card');
  if (!card || !status) return;
  const $ = (sel) => card.querySelector(sel);
  const target = () => card.querySelector('input[name="han-target"]:checked')?.value || shownTarget(status);
  const sync = () => {
    const tg = target();
    $('[data-role="han-url-field"]').hidden = tg === 'supervisor';
    $('[data-role="han-webhook-field"]').hidden = tg !== 'webhook';
    $('[data-role="han-yaml-block"]').hidden = tg !== 'webhook';
    $('[data-role="han-token-field"]').hidden = tg !== 'token';
    $('[data-role="han-service-field"]').hidden = tg === 'webhook';
    const code = $('#han-yaml code');
    if (code) code.textContent = haWebhookAutomationYaml($('#han-webhook')?.value.trim() || '');
  };
  card.querySelectorAll('input[name="han-target"]').forEach(r => r.addEventListener('change', sync));
  $('#han-webhook')?.addEventListener('input', sync);
  $('[data-role="han-new-id"]')?.addEventListener('click', () => {
    $('#han-webhook').value = newWebhookId();
    sync();
  });
  $('[data-role="han-copy"]')?.addEventListener('click', () => {
    copyText($('#han-yaml')?.innerText || '').then(() => toastOk(t('settings.ha.yamlCopied'))).catch(() => {});
  });
  sync();
  if (!isAdmin) return;

  const collect = () => {
    const tg = target();
    const body = {
      enabled: !!$('#han-enabled')?.checked,
      target: tg,
      events: [...card.querySelectorAll('[data-han-event]')].filter(c => c.checked).map(c => c.dataset.hanEvent),
      hour: Number($('#han-hour')?.value ?? 8),
    };
    if (tg !== 'supervisor') body.url = $('#han-url')?.value.trim() || '';
    if (tg !== 'webhook') body.service = $('#han-service')?.value.trim() || 'persistent_notification.create';
    const webhook = $('#han-webhook')?.value.trim();
    if (webhook) body.webhook_id = webhook;
    const token = $('#han-token')?.value.trim();
    if (token) body.token = token;
    return body;
  };
  const save = async (extra = {}) => {
    const next = await api.updateHaNotify({ ...collect(), ...extra });
    Object.assign(status, next);
    return next;
  };
  $('[data-role="han-form"]')?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try { await save(); toastOk(t('settings.haNotify.saved')); rerender(); }
    catch (e) { toastErr(e.message); }
  });
  $('#han-test')?.addEventListener('click', async () => {
    try {
      // erst speichern: geprüft wird, was auf dem Server steht
      await save({ enabled: status.enabled });
      const r = await api.testHaNotify();
      if (r.ok) toastOk(t('settings.haNotify.testOk'));
      else toastErr(t('settings.haNotify.testFailed', { reason: reasonText(r) }));
      rerender();
    } catch (e) { toastErr(e.message); }
  });
  for (const [role, flag] of [['han-clear-webhook', 'clear_webhook'], ['han-clear-token', 'clear_token']]) {
    $(`[data-role="${role}"]`)?.addEventListener('click', async () => {
      try { await api.updateHaNotify({ [flag]: true, enabled: false }); toastOk(t('settings.haNotify.saved')); rerender(); }
      catch (e) { toastErr(e.message); }
    });
  }
}
