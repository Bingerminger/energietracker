// =====================================================================
// Verbindungen › Home Assistant.
// v3.12.0 — aus views/settings.js ausgelagert; M2: Verbinden, Zähler zuordnen und Vorlagen je eine Seite.
// =====================================================================
import { api } from '../api.js';
import { escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { confirmModal } from '../components/modal.js';
import { haRestCommandYaml, haSecretsYaml, haAutomationYaml, haRestSensorYaml, haReadSecretYaml, haBaseUrl, haTodoSensorYaml } from '../lib/ha-snippet.js';
import { t, tp } from '../lib/i18n.js';
import { docUrl } from '../lib/docs.js';
import { copyText as copyToClipboard } from '../lib/clipboard.js';
import { icon, artIcon } from '../lib/icons.js';
import { stampHtml } from './settings-data.js';


// ── Verbindungen › Home Assistant (F1009) ─────────────────────────────────
export function wireIntegrationsPage(container, rerender) {
  container.querySelector('#btn-ha-generate')?.addEventListener('click', async () => {
    const ok = await confirmModal({
      title: t('settings.ha.genConfirmTitle'),
      message: t('settings.ha.genConfirmMsg'),
      confirmLabel: t('settings.ha.genConfirmBtn'),
    });
    if (!ok) return;
    try {
      const res = await api.generateToken();
      const reveal = container.querySelector('#ha-token-reveal');
      reveal.innerHTML = `
        <div class="infobox infobox--erfolg" style="margin-top:.5rem">
          <strong>${t('settings.ha.revealLabel')}</strong>
          <code class="mono" style="display:block;word-break:break-all;margin:6px 0">${escapeHtml(res.token)}</code>
          <button class="btn btn--sm" id="btn-ha-copy-token">${t('settings.ha.copyToken')}</button>
        </div>`;
      container.querySelector('#btn-ha-copy-token')?.addEventListener('click', () => copyText(res.token, t('settings.ha.tokenCopied')));
      container.querySelector('#ha-token-state').className = 'tag tag--success';
      container.querySelector('#ha-token-state').innerHTML = `${icon('schloss', { size: 16 })} ${escapeHtml(t('settings.ha.tokenActive'))}`;
      toastOk(t('settings.ha.generated'));
    } catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-ha-revoke')?.addEventListener('click', async () => {
    const ok = await confirmModal({
      title: t('settings.ha.revokeConfirmTitle'),
      message: t('settings.ha.revokeConfirmMsg'),
      confirmLabel: t('settings.ha.revokeConfirmBtn'), danger: true,
    });
    if (!ok) return;
    try { await api.revokeToken(); toastOk(t('settings.ha.revoked')); rerender(); }
    catch (e) { toastErr(e.message); }
  });

  container.querySelector('#btn-ha-save-aliases')?.addEventListener('click', async () => {
    const inputs = [...container.querySelectorAll('.ha-alias-input')];
    let saved = 0, failed = 0;
    for (const inp of inputs) {
      const utility = inp.getAttribute('data-utility');
      const meterId = inp.getAttribute('data-meter');
      const value = inp.value.trim();
      try { await api.updateMeter(utility, meterId, { external_id: value || null }); saved++; }
      catch (e) { failed++; toastErr(`${utility}/${meterId}: ${e.message}`); }
    }
    if (failed === 0) toastOk(tp('settings.ha.aliasesSavedN', saved));
    // Die Automatisierungs-Vorlage folgt den Aliasen.
    const code = container.querySelector('#ha-automation code');
    if (code) {
      code.textContent = haAutomationYaml(inputs.filter(i => i.value.trim()).map(i => ({
        utility: i.getAttribute('data-utility'), meter: i.value.trim(), sensor: `sensor.${i.value.trim()}`,
      })));
    }
  });

  container.querySelectorAll('[data-ha-copy]').forEach(btn => btn.addEventListener('click', () => {
    copyText(container.querySelector(btn.getAttribute('data-ha-copy'))?.innerText || '', t('settings.ha.yamlCopied'));
  }));
  container.querySelector('#btn-ha-copy-yaml')?.addEventListener('click', () => {
    copyText(container.querySelector('#ha-yaml')?.innerText || '', t('settings.ha.yamlCopied'));
  });
}


// v2.11.0 — mit Rückfall für den Betrieb über http:// (FE-24); bis v2.10
// hieß es dort immer „Zwischenablage nicht verfügbar"
export function copyText(text, okMsg) {
  copyToClipboard(text).then(ok => ok ? toastOk(okMsg) : toastErr(t('settings.ha.clipboardFail')));
}


// ── F1009 — Home-Assistant-Anbindung ─────────────────────────────────────
// v3.12.0 (M2) — je Unterseite eine Karte: Token, Zähler-Aliase, Vorlagen.
export function renderHaTokenCard(authStatus, session = null) {
  const enabled = !!authStatus?.enabled;
  // v2.6.0 — Hilfe bei der Fehlersuche („kommt überhaupt etwas an?") und der
  // Hinweis, dass der Push mit eingeschalteter Anmeldung einen Token braucht.
  const usage = enabled
    ? `<p class="settings-field__hint" id="ha-token-usage">${authStatus.last_used_at
        ? t('settings.ha.lastUsed', { when: stampHtml(authStatus.last_used_at) })
        : t('settings.ha.neverUsed')}</p>`
    : '';
  const loginWarning = !enabled && session && session.mode !== 'off'
    ? `<div class="infobox infobox--achtung" style="margin-top:.5rem">${t('settings.ha.loginNeedsToken')}</div>`
    : '';

  return `
    <div class="card settings-card" id="ha-card">
      <h2 class="card__title">${icon('home')} ${t('settings.ha.title')}</h2>
      <p class="muted" style="margin-bottom: var(--sp-3)">
        ${t('settings.ha.intro')}
        <!-- v2.13.0 (Review I18N-25) — klickbar und in der passenden Sprache;
             bis v2.12 stand hier der deutsche Pfad als Text -->
        <a href="${escapeHtml(docUrl('homeAssistant'))}">${escapeHtml(t('settings.ha.guide'))}</a>
      </p>
      <!-- v3.5.1 — Home Assistant OS: die App aus dem eigenen Repository (energietracker-ha) -->
      <p class="settings-card__hint" id="ha-app-hint">${escapeHtml(t('settings.ha.appHint'))}
        <a href="${HA_APP_ADD_URL}" target="_blank" rel="noopener">${escapeHtml(t('settings.ha.appAdd'))}</a></p>

      <h4 class="settings-subhead">${t('settings.ha.step1')}</h4>
      <p class="muted" style="font-size:var(--fs-xs);margin:0 0 var(--sp-2)">
        ${t('settings.ha.tokenHint')}
      </p>
      <div class="section-actions" style="align-items:center">
        <span class="tag ${enabled ? 'tag--success' : 'tag--warning'}" id="ha-token-state">
          ${enabled ? `${icon('schloss', { size: 16 })} ${t('settings.ha.tokenActive')}` : `${icon('schloss_offen', { size: 16 })} ${t('settings.ha.tokenOpen')}`}
        </span>
        <button class="btn" id="btn-ha-generate">${enabled ? t('settings.ha.generateNew') : t('settings.ha.generate')}</button>
        ${enabled ? `<button class="btn btn--ghost" id="btn-ha-revoke">${t('settings.ha.revoke')}</button>` : ''}
      </div>
      ${usage}${loginWarning}
      <div id="ha-token-reveal"></div>
    </div>`;
}

/** Zähler zuordnen: pro Zähler ein Alias-Feld */
export function renderHaAliasCard(haUtilities, metersByUtility) {
  // Zeilen: pro Zähler ein Alias-Feld. Wir zeigen utility + Zählername.
  const meterRows = haUtilities.flatMap(u =>
    (metersByUtility[u.key] || []).map(m => `
      <tr>
        <td>${artIcon(u, { size: 16 })} ${escapeHtml(u.label)}</td>
        <td>${escapeHtml(m.name)}</td>
        <td>
          <input class="input input--sm ha-alias-input" data-utility="${u.key}" data-meter="${escapeHtml(m.id)}"
                 value="${escapeHtml(m.external_id || '')}" placeholder="${escapeHtml(t('settings.ha.aliasPlaceholder', { key: u.key }))}"
                 style="min-width:180px">
        </td>
      </tr>
    `)
  ).join('');

  return `
    <div class="card settings-card settings-card--wide" id="ha-setup-card">
      <h2 class="card__title">${t('connections.ha.tab.meters')}</h2>

      <h4 class="settings-subhead">${t('settings.ha.step2')}</h4>
      <p class="muted" style="font-size:var(--fs-xs);margin:0 0 var(--sp-2)">
        ${t('settings.ha.aliasHint')}
      </p>
      ${meterRows ? `
        <div class="table-wrap"><table class="data-table">
          <thead><tr><th>${t('settings.ha.colUtility')}</th><th>${t('settings.ha.colMeter')}</th><th>${t('settings.ha.colAlias')}</th></tr></thead>
          <tbody>${meterRows}</tbody>
        </table></div>
        <div class="section-actions" style="margin-top:var(--sp-2)">
          <button class="btn" id="btn-ha-save-aliases">${t('settings.ha.saveAliases')}</button>
        </div>
      ` : `<p class="muted">${t('settings.ha.noMeters')}</p>`}
    </div>`;
}

/** Vorlagen: REST-Command, Secrets, Automatisierung, Sensoren, Sensor „Zu tun“ */
export function renderHaTemplatesCard(haUtilities, metersByUtility, session = null) {
  return `
    <div class="card settings-card settings-card--wide" id="ha-templates-card">
      <h2 class="card__title">${t('settings.ha.setupTitle')}</h2>

      <h4 class="settings-subhead">${t('settings.ha.step3')}</h4>
      <p class="muted" style="font-size:var(--fs-xs);margin:0 0 var(--sp-2)">
        ${t('settings.ha.configHint')}
      </p>
      <p class="settings-field__hint"><code>configuration.yaml</code></p>
      <pre class="code-block" id="ha-yaml"><code>${escapeHtml(haRestCommandYaml(haBaseUrl()))}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" id="btn-ha-copy-yaml">${t('settings.ha.copyYaml')}</button>
      </div>
      <p class="settings-field__hint"><code>secrets.yaml</code></p>
      <pre class="code-block" id="ha-secrets"><code>${escapeHtml(haSecretsYaml())}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" data-ha-copy="#ha-secrets">${t('settings.ha.copyYaml')}</button>
      </div>

      <hr class="settings-rule">

      <h4 class="settings-subhead">${t('settings.ha.step4')}</h4>
      <p class="muted" style="font-size:var(--fs-xs);margin:0 0 var(--sp-2)">
        ${t('settings.ha.automationHint')}
      </p>
      <pre class="code-block" id="ha-automation"><code>${escapeHtml(haAutomationYaml(haAutomationEntries(haUtilities, metersByUtility)))}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" data-ha-copy="#ha-automation">${t('settings.ha.copyYaml')}</button>
      </div>

      <!-- v3.1.0 (Paket H1, API-33) — Werte zurück nach Home Assistant -->
      <h4 class="settings-subhead">${t('settings.ha.sensors.title')}</h4>
      <p class="muted" style="font-size:var(--fs-xs);margin:0 0 var(--sp-2)">${t('settings.ha.sensors.intro')}</p>
      <pre class="code-block" id="ha-sensors"><code>${escapeHtml(haRestSensorYaml(haBaseUrl(), haSensorMeters(haUtilities, metersByUtility)))}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" data-ha-copy="#ha-sensors">${t('settings.ha.copyYaml')}</button>
      </div>
      ${session && session.mode !== 'off' ? `
      <p class="muted" style="font-size:var(--fs-xs);margin:var(--sp-2) 0">${t('settings.ha.sensors.keyHint')}</p>
      <pre class="code-block" id="ha-read-secret"><code>${escapeHtml(haReadSecretYaml())}</code></pre>` : ''}

      <!-- v3.9.0 (F1033) — Sensor „Zu tun“ auf GET /api/todo -->
      <h4 class="settings-subhead">${escapeHtml(t('settings.ha.todo.title'))}</h4>
      <p class="muted" style="font-size:var(--fs-xs);margin:0 0 var(--sp-2)">${escapeHtml(t('settings.ha.todo.intro'))}</p>
      <pre class="code-block" id="ha-todo-sensor"><code>${escapeHtml(haTodoSensorYaml(haBaseUrl()))}</code></pre>
      <div class="section-actions">
        <button class="btn btn--sm" data-ha-copy="#ha-todo-sensor">${t('settings.ha.copyYaml')}</button>
      </div>
    </div>
  `;
}


// v3.1.0 — Zähler für die Sensor-Vorlage: Schlüssel wie in /api/summary
function haSensorMeters(haUtilities, metersByUtility) {
  return haUtilities.flatMap(u => (metersByUtility[u.key] || []).map(m => ({
    key: `${u.key}.${m.id}`, name: `${u.label} ${m.name}`, unit: u.consumption_unit || 'kWh',
    hasContract: u.has_contracts !== false && u.accounting_kind !== 'generation',
  })));
}


// v3.4.0 (N1021) — „Anmeldung & Zugriff" geteilt: Anmeldung (schmal) und
// API-Schlüssel (breit, Tabelle); Eingaben im Formularraster statt einer
// Zeile mit `align-items:flex-end`.
/** v3.5.1 — „Repository zu Home Assistant hinzufügen“ (my.home-assistant.io öffnet die eigene Instanz). */
const HA_APP_ADD_URL = 'https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FBingerminger%2Fenergietracker-ha';


// Je Zähler mit Alias ein Eintrag. Die HA-Entität kennt die App nicht; der
// Alias ist nur ein Vorschlag, den der Hinweistext zu ersetzen bittet.
function haAutomationEntries(haUtilities, metersByUtility) {
  return haUtilities.flatMap(u => (metersByUtility[u.key] || [])
    .filter(m => m.external_id)
    .map(m => ({ utility: u.key, meter: m.external_id, sensor: `sensor.${m.external_id}` })));
}
