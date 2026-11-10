// =====================================================================
// v3.12.0 (F1037, Paket oberflaeche) — KI-Assistenten (MCP), nur für Verwalter.
//
// M2: Jede Sache hat ihren Ort, die Bausteine liegen hier.
//  - Verbindungen › KI-Assistenten › Stand: #mcp-card, Schalter `mcp_enabled` (ET_MCP=off als
//    feste Sperre sichtbar, der Schalter ist dann gesperrt), Kennnummern und Originale
//  - Verbindungen › KI-Assistenten › Verbinden: #mcp-connect-card, Schlüssel mit Bereich
//    `mcp`/`mcp_write` anlegen und widerrufen, Adresse der Schnittstelle, Vorlagen je Programm
//  - Zu tun › Offen: offene Vorschläge mit „Übernehmen“ und „Ablehnen“ (Zeilen, proposalRowsHtml)
//  - Zu tun › Verlauf › Zugriffe der KI: #mcp-activity-card, das Zugriffsprotokoll
//    (GET /api/mcp/journal, ab 51 Einträgen mit Blättern)
//
// v3.12.0 (Skeptiker M1) — „Übernehmen“: POST /api/mcp/proposals/{id}/apply, dieselbe
// Fassung wie apply_proposal. Der Schalter `mcp_apply_in_app` sagt, wer bestätigt.
// „Was bringt dir das?“ (zugeklappt, Katalogtexte, nichts gerechnet) steht oben auf der
// Karte; die Sprungmarke #mcp-value nutzt die Zeile „Neu in vX.Y“ der Demo.
//
// Die Schalter speichern sofort (eigene Einstellungen, keine Speicherleiste). Die
// Karte zeichnet sich nach einer Aktion nur in Teilen neu, damit der Fokus und ein
// gerade gezeigter Schlüssel stehen bleiben. Der Schlüssel steht nur im Browser
// und nur bis zum Verlassen der Seite in den Vorlagen (der Server zeigt ihn nie
// wieder). `mcp_allowed_origins` zeigt die Karte bewusst nicht (entfällt).
// =====================================================================
import { api } from '../api.js';
import { saveSettings } from '../state.js';
import { t, tp, hasTranslation } from '../lib/i18n.js';
import { escapeHtml, fmt, intlLocale } from '../lib/format.js';
import { copyText } from '../lib/clipboard.js';
import { ingressHost } from '../lib/ha-snippet.js';
import { docUrl } from '../lib/docs.js';
import { DEMO } from '../lib/demo-mode.js';
import { MCP_CLIENTS, mcpBaseUrl, mcpUrl, mcpBlocks } from '../lib/mcp-snippet.js';
import { toastOk, toastErr } from './toast.js';
import { confirmModal } from './modal.js';
import { paginate, pagerHtml, wirePager, pageSize } from './pager.js';
import { icon } from '../lib/icons.js';

/** Bereiche der KI-Schlüssel (AuthService::API_KEY_SCOPES, McpContext::SCOPES) */
export const MCP_SCOPES = ['mcp', 'mcp_write'];
/** Ergebnisse im Zugriffsprotokoll mit Farbe (Konzept §2.7) */
const RESULT_TAGS = { ok: 'tag--success', tool_error: 'tag--warning', error: 'tag--danger', denied: 'tag--danger', rate_limited: 'tag--warning' };
/** Schalter → Feld in GET /api/mcp/status */
const SETTINGS = [
  { key: 'mcp_enabled', field: 'setting', id: 'mcp-enabled', label: 'settings.mcp.enabled' },
  { key: 'mcp_apply_in_app', field: 'apply_in_app', id: 'mcp-apply-in-app', label: 'settings.mcp.applyInApp', hint: 'settings.mcp.applyInAppHint' },
  { key: 'mcp_share_identifiers', field: 'share_identifiers', id: 'mcp-share-identifiers', label: 'settings.mcp.shareIdentifiers', hint: 'settings.mcp.shareIdentifiersHint' },
  { key: 'mcp_share_documents', field: 'share_documents', id: 'mcp-share-documents', label: 'settings.mcp.shareDocuments', hint: 'settings.mcp.shareDocumentsHint' },
];
const JOURNAL_LIST = 'mcp-journal';
/** Rückmeldungen zu den experimentellen Programmen */
const FEEDBACK_URL = 'https://github.com/Bingerminger/energietracker/issues';
/** Beispiele unter „Was bringt dir das?“ (Katalog `settings.mcp.value.q<n>`, `.a<n>`); die Antworten sind erfunden */
export const MCP_VALUE_EXAMPLES = ['bill', 'balance', 'scenario', 'month', 'reading', 'together'];

const list = (v) => (Array.isArray(v) ? v : []);
/** Zugriffsprotokoll als Liste (Paket protokoll: Liste oder `{entries}`), neueste zuerst */
const journalEntries = (j) => list(Array.isArray(j) ? j : j?.entries)
  .slice().sort((a, b) => String(b?.at || '').localeCompare(String(a?.at || '')));
const onlyMcp = (keys) => list(keys).filter(k => MCP_SCOPES.includes(k?.scope));

/**
 * Alles für die Karten; null, wenn der Server den Stand nicht liefert (älterer
 * Server, Mitglied). Fehler einzelner Listen lassen die Karte stehen.
 */
export async function loadMcp() {
  const [status, keys, journal, proposals] = await Promise.all([
    api.mcpStatus().catch(() => null),
    api.apiKeys().catch(() => []),
    api.mcpJournal().catch(() => []),
    api.mcpProposals().catch(() => []),
  ]);
  if (!status || typeof status !== 'object') return null;
  return { status, keys: onlyMcp(keys), journal: journalEntries(journal), proposals: list(proposals) };
}

/** Zeitstempel (ISO) im Format der Oberflächensprache. */
function stamp(iso) {
  const d = iso ? new Date(iso) : null;
  if (!d || Number.isNaN(d.getTime())) return '–';
  return d.toLocaleString(intlLocale(), { dateStyle: 'short', timeStyle: 'short' });
}

/** Stand oben in der Karte: gesperrt, aus, an ohne Schlüssel, an. */
export function mcpStateOf(status, keys = []) {
  if (status?.locked_by_env) return { cls: 'infobox--achtung', key: 'settings.mcp.state.locked', state: 'locked' };
  if (!status?.enabled) return { cls: 'infobox--info', key: 'settings.mcp.state.off', state: 'off' };
  if (!list(keys).length) return { cls: 'infobox--achtung', key: 'settings.mcp.state.noKey', state: 'no-key' };
  return { cls: 'infobox--erfolg', key: 'settings.mcp.state.on', state: 'on' };
}

/** Katalogschlüssel des Bereichs (gleich in der Schlüsselkarte unter Zugriff). */
export const mcpScopeLabelKey = (scope) => (scope === 'mcp_write' ? 'settings.security.scopeMcpWrite' : 'settings.security.scopeMcp');

// ── Karte 1: Stand und Schalter ──────────────────────────────────────

/** Mehrwert in zwei Sätzen und Beispielen: zugeklappt, damit die Schalter ohne Scrollen erreichbar bleiben. */
function renderValue() {
  return `<details class="disclosure" id="mcp-value" data-role="mcp-value">
      <summary class="disclosure__summary">${escapeHtml(t('settings.mcp.value.title'))}</summary>
      <div class="disclosure__body">
        <p class="settings-field__hint">${escapeHtml(t('settings.mcp.value.lead'))}</p>
        <p class="settings-field__hint"><em>${escapeHtml(t('settings.mcp.value.invented'))}</em></p>
        <dl class="mcp-value">${MCP_VALUE_EXAMPLES.map(k => `<div data-mcp-example="${k}">
          <dt>${escapeHtml(t(`settings.mcp.value.q.${k}`))}</dt>
          <dd>${escapeHtml(t(`settings.mcp.value.a.${k}`))}</dd>
        </div>`).join('')}</dl>
        <p class="settings-field__hint"><a href="${escapeHtml(docUrl('aiAssistant', 'mehrwert'))}" data-role="mcp-value-more">${escapeHtml(t('settings.mcp.value.more'))}</a></p>
      </div>
    </details>`;
}

function renderStatusCard(d) {
  const s = d.status;
  const st = mcpStateOf(s, d.keys);
  const locked = !!s.locked_by_env;
  const fields = SETTINGS.map(f => {
    const hintKey = f.key === 'mcp_enabled' ? (locked ? 'settings.mcp.lockedHint' : 'settings.mcp.enabledHint') : f.hint;
    const off = f.key === 'mcp_enabled' && locked;
    // v3.12.0 (Skeptiker M1) — der Hinweis sagt, was gilt: an oder aus
    const hint = f.key === 'mcp_apply_in_app' && !s[f.field] ? 'settings.mcp.applyInAppOffHint' : hintKey;
    return `<div class="field settings-field">
        <label class="settings-field__check">
          <input type="checkbox" id="${f.id}" data-mcp-setting="${f.key}" data-field="${f.key}" aria-describedby="${f.id}-hint" ${s[f.field] ? 'checked' : ''}${off ? ' disabled' : ''}>
          ${escapeHtml(t(f.label))}
        </label>
        <span class="settings-field__hint" id="${f.id}-hint">${escapeHtml(t(hint))}</span>
        ${f.key === 'mcp_apply_in_app' && s.login_active === false ? `<span class="settings-field__hint" data-role="mcp-no-login">${escapeHtml(t('settings.mcp.applyInAppNoLogin'))}</span>` : ''}
      </div>`;
  }).join('');
  return `<div class="card settings-card" id="mcp-card">
    <h2 class="card__title">${icon('ki')} ${escapeHtml(t('settings.mcp.title'))}</h2>
    <p class="settings-card__hint">${escapeHtml(t('settings.mcp.intro'))}
      <a href="${escapeHtml(docUrl('aiAssistant'))}" data-role="mcp-guide">${escapeHtml(t('common.guide'))}</a></p>
    ${DEMO ? `<p class="infobox infobox--info" data-role="mcp-demo">${escapeHtml(t('settings.mcp.demo'))}</p>` : ''}
    ${renderValue()}
    <p class="infobox ${st.cls}" data-role="mcp-state" data-state="${st.state}" role="status">${escapeHtml(t(st.key))}</p>
    <div class="settings-fields">${fields}</div>
    <p class="settings-card__hint" data-role="mcp-privacy" style="margin-top:var(--sp-3)">${escapeHtml(t('settings.mcp.privacy'))}</p>
  </div>`;
}

// ── Karte 2: Schlüssel, Adresse, Vorlagen ────────────────────────────

function renderKeyTable(keys) {
  if (!keys.length) return `<p class="settings-field__hint" data-role="mcp-keys-none">${escapeHtml(t('settings.mcp.keysNone'))}</p>`;
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${escapeHtml(t('settings.security.keyName'))}</th>
      <th scope="col">${escapeHtml(t('settings.security.keyScope'))}</th>
      <th scope="col">${escapeHtml(t('settings.security.keyCreatedAt'))}</th>
      <th scope="col">${escapeHtml(t('settings.security.keyLastUsed'))}</th>
      <th scope="col"><span class="sr-only">${escapeHtml(t('common.actions'))}</span></th>
    </tr></thead>
    <tbody>${keys.map(k => `<tr data-mcp-key="${escapeHtml(k.id)}">
      <td>${escapeHtml(k.name)}</td>
      <td>${escapeHtml(t(mcpScopeLabelKey(k.scope)))}</td>
      <td>${escapeHtml(stamp(k.created_at))}</td>
      <td>${k.last_used_at ? escapeHtml(stamp(k.last_used_at)) : `<span class="muted">${escapeHtml(t('settings.security.keyNever'))}</span>`}</td>
      <td style="text-align:right">
        <button type="button" class="btn btn--ghost btn--sm" data-mcp-revoke="${escapeHtml(k.id)}" data-key-name="${escapeHtml(k.name)}"
                aria-label="${escapeHtml(`${t('settings.security.keyRevoke')}: ${k.name}`)}">${escapeHtml(t('settings.security.keyRevoke'))}</button>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

/** Adresse und Hinweise zu dieser Installation */
function connection() {
  const ingress = ingressHost();
  const url = mcpUrl(mcpBaseUrl(globalThis.location, ingress));
  return { url, ingress: !!ingress };
}

function blockHtml(b, label) {
  const name = b.file || label;
  return `${b.file ? `<p class="settings-field__hint"><code>${escapeHtml(b.file)}</code></p>` : ''}
    <pre class="code-block" data-mcp-block="${b.id}"><code>${escapeHtml(b.text)}</code></pre>
    <div class="section-actions">
      <button type="button" class="btn btn--sm" data-mcp-copy="${b.id}" aria-label="${escapeHtml(`${t('settings.mcp.copy')}: ${name}`)}">${escapeHtml(t('settings.mcp.copy'))}</button>
    </div>`;
}

function clientHtml(c, url, key) {
  const blocks = mcpBlocks(c.id, { url, key, prompt: t('settings.mcp.vscodePrompt') });
  const hint = (k) => `<p class="settings-field__hint">${t(k)}</p>`;   // Katalog: nur code/em
  const body = c.id === 'claude-code'
    ? `${hint('settings.mcp.client.claudeCodeCli')}${blockHtml(blocks[0], c.label)}${hint('settings.mcp.client.claudeCodeJson')}${blockHtml(blocks[1], c.label)}`
    : `${hint(`settings.mcp.client.${c.id}`)}${blocks.map(b => blockHtml(b, c.label)).join('')}`;
  const badge = c.experimental ? `<span class="badge badge--warning" data-role="mcp-client-experimental">${escapeHtml(t('settings.mcp.experimental'))}</span>`
    : c.recommended ? `<span class="badge badge--success" data-role="mcp-client-recommended">${escapeHtml(t('settings.mcp.recommended'))}</span>` : '';
  return `<details class="disclosure" data-mcp-client="${c.id}"${c.recommended ? ' open' : ''}>
      <summary class="disclosure__summary">${escapeHtml(c.label)} ${badge}</summary>
      <div class="disclosure__body">${body}</div>
    </details>`;
}

function renderConnectCard(d) {
  const { url, ingress } = connection();
  return `<div class="card settings-card settings-card--wide" id="mcp-connect-card">
    <h2 class="card__title" tabindex="-1">${icon('schluessel')} ${escapeHtml(t('settings.mcp.connectTitle'))}</h2>
    ${d.status.enabled ? '' : `<p class="infobox infobox--info" data-role="mcp-connect-off">${escapeHtml(t('settings.mcp.connectOff'))}</p>`}

    <h3 class="settings-subhead">${escapeHtml(t('settings.mcp.step1'))}</h3>
    <p class="settings-field__hint">${escapeHtml(t('settings.mcp.keysHint'))}</p>
    <div data-role="mcp-keys">${renderKeyTable(d.keys)}</div>
    <form class="fgrid" data-role="mcp-key-form" style="margin-top:var(--sp-3)" autocomplete="off" novalidate>
      <div class="field field--m">
        <label for="mcp-key-name">${escapeHtml(t('settings.security.keyName'))}</label>
        <input class="input input--text" id="mcp-key-name" type="text" maxlength="60" autocomplete="off"
               placeholder="${escapeHtml(t('settings.mcp.keyNamePlaceholder'))}">
      </div>
      <div class="field field--m">
        <label for="mcp-key-scope">${escapeHtml(t('settings.security.keyScope'))}</label>
        <select class="select" id="mcp-key-scope">
          ${MCP_SCOPES.map(s => `<option value="${s}">${escapeHtml(t(mcpScopeLabelKey(s)))}</option>`).join('')}
        </select>
      </div>
      <div class="field field--m">
        <button type="submit" class="btn" id="btn-mcp-key-create">${escapeHtml(t('settings.security.keyCreate'))}</button>
      </div>
    </form>
    <div data-role="mcp-key-reveal"></div>

    <hr class="settings-rule">
    <h3 class="settings-subhead">${escapeHtml(t('settings.mcp.step2'))}</h3>
    <p class="settings-field__hint"><label for="mcp-url">${escapeHtml(t('settings.mcp.addressLabel'))}</label></p>
    <div class="input-pair input-pair--stapel">
      <input class="input input--text" id="mcp-url" type="text" readonly value="${escapeHtml(url)}" aria-describedby="mcp-url-hint">
      <button type="button" class="btn btn--sm" data-mcp-copy-url>${escapeHtml(t('settings.mcp.copyAddress'))}</button>
    </div>
    <p class="settings-field__hint" id="mcp-url-hint">${escapeHtml(t('settings.mcp.addressHint'))}</p>
    ${ingress ? `<p class="infobox infobox--achtung" data-role="mcp-ingress">${escapeHtml(t('settings.mcp.ingressHint'))}</p>` : ''}
    <p class="settings-field__hint" data-role="mcp-home-net">${escapeHtml(t('settings.mcp.homeNetHint'))}</p>
    <p class="settings-field__hint" data-role="mcp-clients-hint">${escapeHtml(t('settings.mcp.clientsHint'))}
      <a href="${FEEDBACK_URL}" target="_blank" rel="noopener" data-role="mcp-feedback">${escapeHtml(t('settings.mcp.feedback'))}</a></p>
    <div data-role="mcp-clients">${MCP_CLIENTS.map(c => clientHtml(c, url, '')).join('')}</div>
  </div>`;
}

// ── Karte 3: Vorschläge und Zugriffe ─────────────────────────────────

const kindText = (kind) => (hasTranslation(`settings.mcp.kind.${kind}`) ? t(`settings.mcp.kind.${kind}`) : String(kind || ''));

/** Fremdtext neben dem Satz: Zähler, Anbieter (Name in der App; steht nie in der Zusammenfassung) */
function proposalContext(p) {
  const u = p?.untrusted || {};
  const parts = [];
  if (u.name) parts.push(t('settings.mcp.proposalMeter', { name: u.name }));
  if (u.provider) parts.push(t('settings.mcp.proposalProvider', { name: u.provider }));
  if (u.note) parts.push(t('settings.mcp.proposalNote', { note: u.note }));   // v3.12.0 (Nachprüfung B3) — sie wird mit eingetragen
  return parts.join(' · ');
}

/** Offene Vorschläge als Zeilen der Liste in Zu tun. Mit ausgeschalteter Schnittstelle bleibt nur „Ablehnen“. */
export function proposalRowsHtml(proposals, canApply = true) {
  return list(proposals).map(p => {
    const meta = [kindText(p.kind), p.key_name || '', `${t('settings.mcp.col.created')} ${stamp(p.created_at)}`,
      `${t('settings.mcp.col.expires')} ${stamp(p.expires_at)}`].filter(Boolean).join(' · ');
    return `<li class="dash-todo__item dash-todo__item--warn" data-mcp-proposal="${escapeHtml(p.id)}">
      <div class="dash-todo__link">
        <span class="dash-todo__icon" aria-hidden="true">${icon('ki', { size: 20 })}</span>
        <span class="dash-todo__text" data-role="mcp-proposal-summary"><strong>${escapeHtml(t('todo.ki.title', { summary: p.summary || '' }))}</strong>
          <span class="muted">${escapeHtml(meta)}</span>
          ${proposalContext(p) ? `<span class="muted" data-role="mcp-proposal-context">${escapeHtml(proposalContext(p))}</span>` : ''}</span>
      </div>
      <div class="dash-todo__actions" data-role="mcp-proposal-actions">
        ${canApply ? `<button type="button" class="btn btn--sm btn--primary" data-mcp-apply="${escapeHtml(p.id)}"
                aria-label="${escapeHtml(`${t('settings.mcp.apply')}: ${p.summary || p.id}`)}">${escapeHtml(t('settings.mcp.apply'))}</button>` : ''}
        <button type="button" class="btn btn--sm btn--ghost" data-mcp-reject="${escapeHtml(p.id)}"
                aria-label="${escapeHtml(`${t('settings.mcp.reject')}: ${p.summary || p.id}`)}">${escapeHtml(t('settings.mcp.reject'))}</button>
      </div>
    </li>`;
  }).join('');
}

/**
 * Was für die Vorschläge gilt, unter der Liste in Zu tun: wer bestätigt (die Zusage „nur in der App“)
 * und, ohne Anmeldung, wer alles bestätigen kann.
 */
export function proposalsNoteHtml(status) {
  return `<p class="infobox infobox--neutral" data-role="mcp-proposals-hint">${escapeHtml(t(status?.apply_in_app ? 'settings.mcp.proposalsHintApp' : 'settings.mcp.proposalsHintClient'))}</p>
    ${status?.login_active === false ? `<p class="infobox infobox--achtung" data-role="mcp-no-login">${escapeHtml(t('settings.mcp.applyInAppNoLogin'))}</p>` : ''}`;
}

/**
 * Übernehmen und Ablehnen an den Zeilen. `d.proposals` trägt den Stand; `onChange` zeichnet neu, auch nach
 * einem Fehler (abgelaufen, übernommen): Die Liste zeigt dann den Stand des Servers.
 * @param {HTMLElement} root  enthält die Zeilen
 * @param {{proposals: Array}} d
 * @param {() => void} onChange
 */
export function wireProposals(root, d, onChange) {
  const reload = async (id) => {
    try { d.proposals = list(await api.mcpProposals()); } catch { d.proposals = d.proposals.filter(x => x.id !== id); }
    window.dispatchEvent(new CustomEvent('et:badges-refresh'));
    onChange();
  };
  root.querySelectorAll('[data-mcp-apply]').forEach(btn => btn.addEventListener('click', async () => {
    const id = btn.getAttribute('data-mcp-apply');
    const p = d.proposals.find(x => x.id === id);
    const ok = await confirmModal({
      title: t('settings.mcp.applyTitle'),
      message: t('settings.mcp.applyMsg', { key: p?.key_name || '' }),
      detail: [p?.summary || '', proposalContext(p)].filter(Boolean).join(' · '),
      confirmLabel: t('settings.mcp.apply'),
    });
    if (!ok) return;
    try {
      const r = await api.applyMcpProposal(id);
      // Teilerfolg sagen: Was nicht ging, steht nicht im Bestand
      if ((r?.failed || []).length) toastErr(t('settings.mcp.appliedPartly'));
      else toastOk(t('settings.mcp.applied'));
    } catch (e) { toastErr(e.message); }
    await reload(id);
  }));
  root.querySelectorAll('[data-mcp-reject]').forEach(btn => btn.addEventListener('click', async () => {
    const id = btn.getAttribute('data-mcp-reject');
    const p = d.proposals.find(x => x.id === id);
    const ok = await confirmModal({
      title: t('settings.mcp.rejectTitle'),
      message: t('settings.mcp.rejectMsg'),
      detail: p?.summary || '',
      confirmLabel: t('settings.mcp.reject'), danger: true,
    });
    if (!ok) return;
    try {
      await api.rejectMcpProposal(id);
      toastOk(t('settings.mcp.rejected'));
    } catch (e) { toastErr(e.message); }
    await reload(id);
  }));
}

/** Zeilen über der Karte „Stand“: was wartet, wer zuletzt zugegriffen hat. */
export function renderMcpLinks(d) {
  const n = d.proposals.length;
  const last = d.journal[0];
  const row = (href, ico, title, meta, role) => `<li class="zeile-wrap"><a class="zeile" href="${href}" data-role="${role}">
      <span class="zeile__icon" aria-hidden="true">${icon(ico, { size: 20 })}</span>
      <span class="zeile__text"><span class="zeile__titel">${escapeHtml(title)}</span><span class="zeile__meta">${escapeHtml(meta)}</span></span>
      <span class="zeile__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span></a></li>`;
  return `<section class="card zeilen"><ul class="zeilen__liste">
    ${row('#/todo?art=ki', 'aufgaben', n ? tp('connections.ai.proposals', n, { count: n }) : t('connections.ai.proposalsNone'), t('connections.ai.proposalsMeta'), 'mcp-link-proposals')}
    ${row('#/todo/history/ai', 'klemmbrett', t('connections.ai.access'), last ? t('connections.ai.lastAccess', { when: stamp(last.at), key: last.key_name || '' }) : t('settings.mcp.journalNone'), 'mcp-link-access')}
  </ul></section>`;
}

const clientText = (c) => (c && typeof c === 'object' ? [c.name, c.version].filter(Boolean).join(' ') : String(c || ''));
const resultText = (r) => (hasTranslation(`settings.mcp.result.${r}`) ? t(`settings.mcp.result.${r}`) : String(r || ''));

/** Ausschnitt des Protokolls samt Blätterleiste */
function renderJournal(entries, page = 1) {
  if (!entries.length) return `<p class="settings-field__hint" data-role="mcp-journal-none">${escapeHtml(t('settings.mcp.journalNone'))}</p>`;
  const p = paginate(entries, { page, size: pageSize(JOURNAL_LIST) });
  return `<div class="table-wrap"><table class="table table--compact">
    <thead><tr>
      <th scope="col">${escapeHtml(t('settings.mcp.col.time'))}</th>
      <th scope="col">${escapeHtml(t('settings.mcp.col.key'))}</th>
      <th scope="col">${escapeHtml(t('settings.mcp.col.client'))}</th>
      <th scope="col">${escapeHtml(t('settings.mcp.col.call'))}</th>
      <th scope="col">${escapeHtml(t('settings.mcp.col.result'))}</th>
      <th scope="col" class="num">${escapeHtml(t('settings.mcp.col.duration'))}</th>
    </tr></thead>
    <tbody>${p.items.map(e => `<tr data-mcp-journal>
      <td>${escapeHtml(stamp(e.at))}</td>
      <td>${escapeHtml(e.key_name || '')}</td>
      <td>${escapeHtml(clientText(e.client))}</td>
      <td><code>${escapeHtml(e.name || e.method || '')}</code></td>
      <td><span class="tag ${RESULT_TAGS[e.result] || ''}">${escapeHtml(resultText(e.result))}</span></td>
      <td class="num">${e.ms !== null && e.ms !== undefined && Number.isFinite(Number(e.ms)) ? `${escapeHtml(fmt.int(Number(e.ms)))} ms` : '–'}</td>
    </tr>`).join('')}</tbody>
  </table></div>${pagerHtml(p, { listKey: JOURNAL_LIST })}`;
}

/** Zu tun › Verlauf › Zugriffe der KI */
export function renderJournalCard(d) {
  return `<div class="card settings-card settings-card--wide" id="mcp-activity-card">
    <h2 class="card__title" id="mcp-journal-title" tabindex="-1">${icon('klemmbrett')} ${escapeHtml(t('settings.mcp.journalTitle'))}</h2>
    <p class="settings-field__hint">${escapeHtml(t('settings.mcp.journalHint'))}</p>
    <div data-role="mcp-journal">${renderJournal(d.journal)}</div>
    <div class="section-actions" style="margin-top:var(--sp-3)">
      <button type="button" class="btn btn--ghost btn--sm" data-mcp-refresh>${escapeHtml(t('settings.mcp.refresh'))}</button>
    </div>
  </div>`;
}

/** Blättern und „Aktualisieren“ im Protokoll */
export function wireJournalCard(container, d) {
  const card = container.querySelector('#mcp-activity-card');
  if (!card) return;
  let page = 1;
  const draw = () => {
    const el = card.querySelector('[data-role="mcp-journal"]');
    if (!el) return;
    el.innerHTML = renderJournal(d.journal, page);
    const p = paginate(d.journal, { page, size: pageSize(JOURNAL_LIST) });
    wirePager(el, p, { listKey: JOURNAL_LIST, onChange: (x) => { page = x.page; draw(); } });
  };
  draw();
  card.querySelector('[data-mcp-refresh]')?.addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    try {
      d.journal = journalEntries(await api.mcpJournal());
      page = 1;
      draw();
    } catch (e) { toastErr(e.message); }
    finally { btn.disabled = false; btn.focus(); }
  });
}

/** Karten für Verbindungen › KI-Assistenten. Ist die Schnittstelle per ET_MCP gesperrt, entfällt „verbinden“. */
export const renderMcpStatusCard = (d) => (d?.status ? renderStatusCard(d) : '');
export const renderMcpConnectCard = (d) => (d?.status && !d.status.locked_by_env ? renderConnectCard(d) : '');

// ── Verdrahtung ──────────────────────────────────────────────────────

/**
 * @param {HTMLElement} container  die Seite
 * @param {object|null} d          aus loadMcp() (wird bei Aktionen fortgeschrieben)
 */
export function wireMcpCards(container, d) {
  if (!d?.status) return;
  const statusCard = container.querySelector('#mcp-card');
  const connectCard = container.querySelector('#mcp-connect-card');
  let revealed = null;   // {id, key} des in dieser Ansicht angelegten Schlüssels

  // „Neu in vX.Y“ der Demo: #/connections/ai?mcp=value öffnet den Block und springt hin.
  // Erst nach dem Router (setTimeout): Er scrollt nach dem Rendern an den Anfang
  if (/[?&]mcp=value\b/.test(globalThis.location?.hash || '')) {
    const value = statusCard?.querySelector('#mcp-value');
    if (value) {
      value.open = true;
      setTimeout(() => {
        if (!value.isConnected) return;
        value.scrollIntoView?.({ block: 'start' });
        value.querySelector('summary')?.focus({ preventScroll: true });
      }, 0);
    }
  }

  const showState = () => {
    const el = statusCard?.querySelector('[data-role="mcp-state"]');
    if (!el) return;
    const st = mcpStateOf(d.status, d.keys);
    el.className = `infobox ${st.cls}`;
    el.dataset.state = st.state;
    el.textContent = t(st.key);
    const off = connectCard?.querySelector('[data-role="mcp-connect-off"]');
    if (off) off.hidden = !!d.status.enabled;
  };

  // Schalter: sofort speichern; scheitert es, springt das Kästchen zurück
  statusCard?.querySelectorAll('[data-mcp-setting]').forEach(box => box.addEventListener('change', async () => {
    const f = SETTINGS.find(x => x.key === box.dataset.mcpSetting);
    if (!f) return;
    const value = box.checked;
    box.disabled = true;
    try {
      await saveSettings({ [f.key]: value });
      d.status[f.field] = value;
      if (f.key === 'mcp_enabled') d.status.enabled = value && !d.status.locked_by_env;
      showState();
      if (f.key === 'mcp_apply_in_app') {
        const hintEl = statusCard.querySelector(`#${f.id}-hint`);
        if (hintEl) hintEl.textContent = t(value ? 'settings.mcp.applyInAppHint' : 'settings.mcp.applyInAppOffHint');
      }
      toastOk(t('settings.saved'));
    } catch (e) {
      box.checked = !value;
      toastErr(e.message);
    } finally {
      box.disabled = f.key === 'mcp_enabled' && !!d.status.locked_by_env;
    }
  }));

  // Vorlagen: gleicher Text wie beim Rendern, mit dem eben angelegten Schlüssel
  const fillSnippets = () => {
    if (!connectCard) return;
    const { url } = connection();
    for (const c of MCP_CLIENTS) {
      for (const b of mcpBlocks(c.id, { url, key: revealed?.key || '', prompt: t('settings.mcp.vscodePrompt') })) {
        const code = connectCard.querySelector(`[data-mcp-block="${b.id}"] code`);
        if (code) code.textContent = b.text;
      }
    }
  };

  const drawKeys = () => {
    const el = connectCard?.querySelector('[data-role="mcp-keys"]');
    if (el) el.innerHTML = renderKeyTable(d.keys);
    showState();
  };
  const reloadKeys = async () => {
    try { d.keys = onlyMcp(await api.apiKeys()); } catch { /* alte Liste bleibt */ }
    drawKeys();
  };

  connectCard?.querySelector('form[data-role="mcp-key-form"]')?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const nameEl = connectCard.querySelector('#mcp-key-name');
    const picked = connectCard.querySelector('#mcp-key-scope')?.value;
    const scope = MCP_SCOPES.includes(picked) ? picked : 'mcp';
    const name = (nameEl?.value || '').trim();
    const btn = connectCard.querySelector('#btn-mcp-key-create');
    if (btn) btn.disabled = true;
    try {
      const res = await api.createApiKey(name, scope);
      revealed = { id: res.id, key: res.key };
      const reveal = connectCard.querySelector('[data-role="mcp-key-reveal"]');
      reveal.innerHTML = `<div class="infobox infobox--erfolg" style="margin-top:.5rem">
          <strong>${escapeHtml(t('settings.security.keyReveal', { name: name || t(mcpScopeLabelKey(scope)) }))}</strong>
          <code class="mono" data-role="mcp-key-value" style="display:block;word-break:break-all;margin:6px 0">${escapeHtml(res.key)}</code>
          <span class="settings-field__hint">${escapeHtml(t('settings.mcp.keyRevealHint'))}</span>
          <div><button type="button" class="btn btn--sm" data-role="mcp-key-copy">${escapeHtml(t('settings.security.keyCopy'))}</button></div>
        </div>`;
      const copyBtn = reveal.querySelector('[data-role="mcp-key-copy"]');
      copyBtn?.addEventListener('click', () => copy(res.key, t('settings.security.keyCopied')));
      if (nameEl) nameEl.value = '';
      fillSnippets();
      await reloadKeys();
      copyBtn?.focus();
    } catch (e) { toastErr(e.message); }
    finally { if (btn) btn.disabled = false; }
  });

  connectCard?.querySelector('[data-role="mcp-keys"]')?.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-mcp-revoke]');
    if (!btn) return;
    const id = btn.getAttribute('data-mcp-revoke');
    const ok = await confirmModal({
      title: t('settings.security.keyRevokeTitle'),
      message: t('settings.security.keyRevokeMsg', { name: btn.getAttribute('data-key-name') || '' }),
      confirmLabel: t('settings.security.keyRevoke'), danger: true,
    });
    if (!ok) return;
    try {
      await api.revokeApiKey(id);
      toastOk(t('settings.security.keyRevoked'));
      if (revealed?.id === id) {
        revealed = null;
        connectCard.querySelector('[data-role="mcp-key-reveal"]').innerHTML = '';
        fillSnippets();
      }
      await reloadKeys();
      connectCard.querySelector('#mcp-key-name')?.focus();
    } catch (e) { toastErr(e.message); }
  });

  // Kopieren: Adresse und jeder Vorlagenblock (Text wie angezeigt)
  connectCard?.querySelector('[data-mcp-copy-url]')?.addEventListener('click', () =>
    copy(connectCard.querySelector('#mcp-url')?.value || '', t('settings.mcp.copied')));
  connectCard?.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-mcp-copy]');
    if (!btn) return;
    copy(connectCard.querySelector(`[data-mcp-block="${btn.getAttribute('data-mcp-copy')}"]`)?.textContent || '', t('settings.mcp.copied'));
  });

}

function copy(text, okMsg) {
  copyText(text).then(ok => (ok ? toastOk(okMsg) : toastErr(t('settings.ha.clipboardFail'))));
}
