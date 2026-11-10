// =====================================================================
// Energietracker v1.2.0 — Settings view
//   - configurable values, grouped into professional setting cards
//   - CSV export (F-07), JSON backup/restore
//   - system diagnostics, security check (v3.4.0)
// =====================================================================

import { api } from '../api.js';
import { getCountries, saveSettings } from '../state.js';
import { escapeHtml } from '../lib/format.js';
import { toastOk, toastErr } from '../components/toast.js';
import { confirmModal } from '../components/modal.js';
import { t, deviceLanguage, setDeviceLanguage, resolveLanguage } from '../lib/i18n.js';
import { profileDiff } from '../lib/region.js';
import { switchLanguage } from '../lib/language.js';
import { wireDisclosures, openDisclosureOf } from '../components/disclosure.js';
import { jumpToField } from '../lib/field-jump.js';
import { atLeast } from '../lib/levels.js';
import { renderReadingCard, wireReadingCard } from '../components/inbox-cloud.js';
import { renderHaNotifyCard, wireHaNotifyCard } from '../components/ha-notify.js';
import { loadMcp, renderMcpLinks, renderMcpStatusCard, renderMcpConnectCard, wireMcpCards } from '../components/mcp-card.js';
import { openConceptWizard, conceptOffered } from '../components/concept-wizard.js';
import { icon, artIcon } from '../lib/icons.js';
import { rich } from '../lib/rich.js';
import { GROUPS, renderGroup, firstInvalidSetting, wireCo2Years, renderDefaultUpdates, wireGasFactors, collectSettings } from './settings-fields.js';
import { unlistenAll, listen } from './settings-shared.js';
import { renderExportCard, renderBackupCard, wireDataPage } from './settings-data.js';
import { wireAccessPage, renderLevelCard, wireLevelCard, renderMyAccountCard, loginOff, renderUsersCard, wireUsersCard, renderSecurityCard, renderKeysCard, renderSecurityCheck, wireSecurityCheck } from './settings-access.js';
import { wireIntegrationsPage, renderHaTokenCard, renderHaAliasCard, renderHaTemplatesCard } from './settings-integrations.js';
import { renderAbout, renderSupportCard, renderDiagnostics } from './settings-system.js';
import { renderLanguageCard, renderOwnLanguageCard, renderThemeCard, wireThemeCard, chooseProfile, applyRegion } from './settings-region.js';
import { renderPeopleCard, wirePeopleCard } from './settings-household.js';
import { renderRegister, wireRegister } from './settings-register.js';


// v2.12.0 (Review UI-13) — Unterseiten statt eines 6.600-px-Monolithen mit
// 21 Abschnitten und vier Speichern-Knöpfen. Jede Seite lädt nur, was sie
// zeigt; Seiten mit Feldern haben eine Speicherleiste, die erscheint, sobald
// etwas geändert ist. Die Wetterdaten (Standort, Abgleich, CSV) sind die
// Ansicht temperatures.js — ein Tab derselben Leiste.
// v3.12.0 (M2) — Integrationen, Auslesen, evcc und Heimnetz sind unter Verbindungen gezogen
// (views/connections.js, `ctx.connection`): dasselbe Gerüst mit Speicherleiste und `?field=`.
// v3.12.0 (M3) — Themen statt Datentöpfe: Haushalt ist ein eigener Bereich (views/household.js,
// `ctx.household`), „Experte“ heißt Rechnen, `general` trägt Suche und „Für dich“.
export const PAGES = ['general', 'region', 'display', 'data', 'access', 'calculation', 'system'];

const FIELD_PAGES = new Set(['display', 'data', 'household:building', 'household:utilities', 'access', 'calculation', 'connections:reading', 'connections:evcc', 'connections:homenet']);
const HA = 'connections:homeassistant';
const AI = 'connections:ai';
const KEYS = 'connections:keys';
const READING = 'connections:reading';


export async function render(container, params = [], ctx = {}) {
  unlistenAll();
  const conn = ctx?.connection || null;   // { page, sub, top, after }
  const hh = ctx?.household || null;       // { page }
  const page = conn ? `connections:${conn.page}` : hh ? `household:${hh.page}` : PAGES.includes(params?.[0]) ? params[0] : 'general';
  const sub = conn?.sub || '';
  container.innerHTML = `<div class="loading">${t('settings.loading')}</div>`;

  const needs = (...pages) => pages.includes(page);
  const [settings, diag, utilities, authStatus, session, apiKeys, snapshots, countries, defaultUpdates, attachments] = await Promise.all([
    api.settings(),
    // v3.4.0 (N1020) — auch für den Sicherheits-Check unter „Zugriff"
    needs('system', 'access') ? api.diagnostics().catch(() => null) : null,
    needs('household:utilities', 'data', HA) ? api.listUtilities().catch(() => []) : [],
    needs(HA, KEYS) ? api.authStatus().catch(() => ({ enabled: false, created_at: null })) : null,
    // v2.6.0 — Anmeldung, API-Schlüssel, gespeicherte Snapshots
    // v3.9.0 — auch Personen (Verwalter oder Mitglied, Anmeldung an oder aus)
    needs('general', 'region', 'household:people', 'access', HA, AI, KEYS, READING) ? api.session().catch(() => ({ mode: 'off', authenticated: true })) : null,
    needs(KEYS) ? api.apiKeys().catch(() => []) : [],
    needs('data') ? api.snapshots().catch(() => []) : [],
    // v2.7.0 — Länderprofile für die Karte „Sprache & Land"
    needs('region') ? getCountries() : [],
    // v2.10.0 — korrigierte Standardwerte, die diese Installation noch nicht nutzt
    needs('general', 'calculation') ? api.settingsDefaultUpdates().catch(() => []) : [],
    // v3.1.0 (H2) — Belege: Anzahl und Speicher für die Backup-Karte
    needs('data') ? api.attachments().catch(() => null) : null,
  ]);

  // v3.2.0 (F1023) — Personen im Haushalt (nur Verwalter, nur mit Anmeldung)
  const isAdmin = (session?.role || 'admin') === 'admin';
  // v3.6.0 — auch ohne Anmeldung: dann die Personen ohne Zugang aus dem Personenverzeichnis
  const users = page === 'access' && isAdmin
    ? await api.users().catch(() => []) : [];
  // v3.9.0 — Personen im Haushalt (Karte unter Haushalt & Gebäude, jede Stufe, auch ohne
  // Anmeldung): Verwalter die volle Liste (mit Zugang ja/nein), Mitglieder nur die Namen
  const people = page === 'household:people'
    ? await (isAdmin ? api.users() : api.people()).catch(() => []) : [];
  // v3.7.0 (Paket cloud) — Stand des Auslesens in der Cloud (ohne Schlüssel, nur has_key)
  const inboxCloud = page === READING ? await api.inboxCloud().catch(() => null) : null;
  // v3.9.0 (F1033) — Stand der Benachrichtigungen (ohne Webhook-ID und Token)
  const haNotify = page === HA && sub === 'notifications' ? await api.haNotify().catch(() => null) : null;
  // v3.12.0 (F1037) — KI-Assistenten: nur Verwalter (die Routen /api/mcp/… sind adminOnly)
  const mcp = page === AI && isAdmin ? await loadMcp() : null;

  // F1009 — Zähler je (nicht-Delivery-)Utility für die Alias-Verwaltung laden.
  const haUtilities = (utilities || []).filter(u => u.reading_kind !== 'delivery');
  const metersByUtility = {};
  if (page === HA && (sub === 'meters' || sub === 'templates')) {
    await Promise.all(haUtilities.map(async u => {
      metersByUtility[u.key] = await api.meters(u.key).catch(() => []);
    }));
  }

  // v3.4.0 (N1021) — Jede Unterseite legt ALLE Karten in ein Raster (bis
  // v3.3 nur die aus GROUPS; die übrigen liefen über 100 % Breite). Schmale
  // Karten zuerst, breite (`settings-card--wide`) am Ende — so bleibt keine
  // Lücke vor einer breiten Karte, und Lese- wie Tab-Reihenfolge folgen der
  // Anzeige. Ausnahme „Daten": Der einzelne Verweis auf den Jahresbericht
  // steht am Ende, sonst stünde er allein über zwei leeren Spalten.
  const grid = (...cards) => {
    const list = cards.flat().filter(Boolean);
    return list.length ? `<div class="settings-grid">${list.join('')}</div>` : '';
  };
  const groupCards = (list) => [...list.filter(g => !g.wide), ...list.filter(g => g.wide)].map(g => renderGroup(g, settings));
  // v3.7.0 — wer ausliest (`inbox_provider`), steht nur noch in der Karte
  // „Auslesen“ (Umschalter Lokal | Cloud); der Server prüft dasselbe.
  const groups = GROUPS.filter(g => g.page === page);
  const pageGroups = groupCards(groups);
  const narrowGroups = groupCards(groups.filter(g => !g.wide));
  const wideGroups = groupCards(groups.filter(g => g.wide));
  const bodies = {
    general: renderRegister({ settings, defaultUpdates, cards: grid(renderLevelCard(session), renderOwnLanguageCard(settings, session), renderThemeCard(), renderMyAccountCard(session)) }),
    region: grid(renderLanguageCard(settings, session, countries || [])),
    display: grid(pageGroups),
    'household:building': grid(pageGroups),
    'household:people': grid(renderPeopleCard(people, session, isAdmin)),
    // „Aktive Verbrauchsarten" schmal, die Kästchen in Spalten (bis v3.3 neun
    // Zeilen untereinander über die ganze Breite)
    'household:utilities': grid(
      `<div class="card settings-card" id="active-utils-card" data-field="active_utilities">
        <h2 class="card__title">${icon('bausteine')} ${t('settings.activeUtils.title')}</h2>
        <p class="settings-card__hint">${t('settings.activeUtils.hint')}</p>
        <div class="settings-fields check-grid" id="active-utils">
          ${(utilities || []).map(u => {
            const act = Array.isArray(settings.active_utilities) && settings.active_utilities.length
              ? settings.active_utilities.includes(u.key)
              : true;
            return `<label class="settings-field__check">
              <input type="checkbox" data-active-util="${u.key}" ${act ? 'checked' : ''}>
              ${artIcon(u, { size: 16 })} ${escapeHtml(u.label)}
            </label>`;
          }).join('')}
        </div>
      </div>`,
      // v3.9.0 (F1032) — Assistent „Messkonzept“ (nicht in der öffentlichen Demo)
      !conceptOffered() ? '' : `<div class="card settings-card" id="concept-card" data-field="concept">
        <h2 class="card__title">${icon('stecker')} ${escapeHtml(t('concept.entry.title'))}</h2>
        <p class="settings-card__hint">${escapeHtml(t('concept.entry.text'))}</p>
        <button type="button" class="btn btn--ghost" id="btn-concept-wizard">${escapeHtml(t('concept.title'))}</button>
      </div>`,
      pageGroups),
    data: grid(
      renderExportCard(settings, utilities),
      renderBackupCard(snapshots, attachments),
      pageGroups),
    // v3.2.0 (F1023) — Mitglieder sehen nur, wer den Zugriff verwaltet
    // v3.4.0 — Anmeldung schmal, API-Schlüssel und Personen breit am Ende
    // v3.9.0 — ohne Anmeldung ist „Benutzer und Rechte“ ein schmaler Hinweis (die
    // Personen stehen unter Haushalt & Gebäude), daher vor den breiten Karten
    access: isAdmin
      ? grid(renderSecurityCheck(diag, isAdmin), renderSecurityCard(session), narrowGroups,
          loginOff(session) ? renderUsersCard(users, session) : '',
          // v3.12.0 (M2) — API-Schlüssel stehen unter Verbindungen
          `<div class="card settings-card" id="keys-moved">
            <h2 class="card__title">${icon('schluessel')} ${escapeHtml(t('settings.security.keysTitle'))}</h2>
            <p class="settings-card__hint">${escapeHtml(t('settings.security.keysMoved'))}</p>
            <a class="btn btn--ghost btn--sm" href="#/connections/keys">${escapeHtml(t('connections.keys.title'))}</a>
          </div>`,
          loginOff(session) ? '' : renderUsersCard(users, session), wideGroups)
      : grid(renderSecurityCheck(diag, isAdmin), `<div class="card settings-card"><p class="muted">${escapeHtml(t('settings.users.memberHint'))}</p>
          <p class="muted" data-role="member-delete-hint">${escapeHtml(t('settings.users.memberDeleteHint'))}</p></div>`),
    // v3.12.0 (M3) — Rechnen ab Experte: die Warnung steht über allem, nichts mehr eingeklappt
    calculation: `<p class="infobox infobox--achtung" data-role="calc-warning">${escapeHtml(t('settings.expert.warning'))}</p>
      ${grid(pageGroups, renderDefaultUpdates(defaultUpdates))}`,
    // v3.4.0 (GitHub #28) — „Energietracker unterstützen" neben „Über"
    // v3.12.0 (M3) — der Sicherheits-Check steht einmal, unter Zugang und Sicherheit
    system: grid(renderAbout(diag), renderSupportCard(), diag ? renderDiagnostics(diag) : ''),
  };

  // v3.12.0 (M2) — Verbindungen: je Seite, was sie braucht (Home Assistant und KI je Reiter)
  const connectionBody = () => {
    if (page === READING) return grid(renderReadingCard(inboxCloud, settings, isAdmin), pageGroups);
    if (page === HA) {
      if (sub === 'meters') return grid(renderHaAliasCard(haUtilities, metersByUtility));
      if (sub === 'templates') return grid(renderHaTemplatesCard(haUtilities, metersByUtility, session));
      if (sub === 'notifications') return grid(renderHaNotifyCard(haNotify, isAdmin));
      return grid(renderHaTokenCard(authStatus, session));
    }
    if (page === AI) {
      if (!mcp) return `<div class="infobox infobox--info" data-role="mcp-admin-only">${escapeHtml(t('todo.history.adminOnly'))}</div>`;
      return sub === 'connect' && !mcp.status.locked_by_env ? grid(renderMcpConnectCard(mcp))
        : `${renderMcpLinks(mcp)}${grid(renderMcpStatusCard(mcp))}`;
    }
    if (page === KEYS) {
      if (!isAdmin) return grid(`<div class="card settings-card"><p class="muted">${escapeHtml(t('settings.users.memberHint'))}</p></div>`);
      return `<section class="card zeilen" data-role="keys-all"><ul class="zeilen__liste">
          <li class="zeile-wrap"><a class="zeile" href="#/connections/homeassistant"><span class="zeile__icon" aria-hidden="true">${icon('home', { size: 20 })}</span>
            <span class="zeile__text"><span class="zeile__titel">${escapeHtml(t('connections.keys.haTitle'))}</span><span class="zeile__meta">${escapeHtml(t('connections.keys.haMeta'))}</span></span>
            <span class="zeile__status"><span class="tag ${authStatus?.enabled ? 'tag--success' : ''}">${escapeHtml(t(authStatus?.enabled ? 'connections.tag.active' : 'connections.tag.notSet'))}</span></span>
            <span class="zeile__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span></a></li>
          <li class="zeile-wrap"><a class="zeile" href="#/connections/ai/connect"><span class="zeile__icon" aria-hidden="true">${icon('ki', { size: 20 })}</span>
            <span class="zeile__text"><span class="zeile__titel">${escapeHtml(t('connections.keys.aiTitle'))}</span><span class="zeile__meta">${escapeHtml(t('connections.keys.aiMeta'))}</span></span>
            <span class="zeile__chev" aria-hidden="true">${icon('rechts', { size: 16 })}</span></a></li>
        </ul></section>${grid(renderKeysCard(apiKeys))}`;
    }
    return grid(pageGroups);
  };
  const pageKey = conn ? `connections.${conn.page}` : hh ? `household.${hh.page}` : `settings.page.${page}`;

  container.innerHTML = `
    ${conn?.top || ''}
    <div class="view-header">
      <div>
        <h1 class="view-header__title">${escapeHtml(t(page === 'general' ? 'nav.settings' : `${pageKey}.title`))}</h1>
        <div class="view-header__subtitle">${rich(`${pageKey}.subtitle`)}</div>
      </div>
    </div>
    ${conn ? connectionBody() : bodies[page]}
    ${conn?.after || ''}
    <div class="settings-more" data-role="settings-more"></div>
    ${FIELD_PAGES.has(page) ? `
    <div class="settings-savebar" data-role="savebar" hidden>
      <span class="settings-savebar__text">${escapeHtml(t('settings.savebar.unsaved'))}</span>
      <button type="button" class="btn btn--ghost" id="btn-discard">${escapeHtml(t('settings.savebar.discard'))}</button>
      <button type="button" class="btn btn--primary" id="btn-save">${escapeHtml(t('common.save'))}</button>
    </div>` : ''}
  `;

  // v2.5.0 — F1012: Tabelle der datierten Gas-Faktoren verdrahten. Muss vor
  // der Baseline stehen, damit Hinzufügen/Entfernen die Speicherleiste
  // auslöst wie jede andere Änderung.
  wireGasFactors(container);
  wireCo2Years(container);   // v2.10.0
  // v2.10.0 — korrigierte Standardwerte übernehmen (Lektion 36)
  container.querySelector('#btn-take-defaults')?.addEventListener('click', async () => {
    const patch = Object.fromEntries((defaultUpdates || []).map(u => [u.key, u.recommended]));
    try {
      await saveSettings(patch);
      toastOk(t('settings.defaultUpdates.applied'));
      render(container, params, ctx);
    } catch (err) { toastErr(err.message); }
  });

  // v2.2.0 — Schutz vor unbemerktem Verlust. v2.12.0: statt eines Markers an
  // zwei Knöpfen eine Speicherleiste, die nur bei offenen Änderungen steht,
  // dazu eine Rückfrage, wenn man die Seite über einen Link verlässt.
  let baseline = JSON.stringify(collectSettings(container));
  const savebar = container.querySelector('[data-role="savebar"]');
  const isDirty = () => !!savebar && JSON.stringify(collectSettings(container)) !== baseline;
  const markDirty = () => { if (savebar) savebar.hidden = !isDirty(); };
  listen(container, 'input', markDirty);
  listen(container, 'change', markDirty);

  const beforeUnload = (e) => {
    if (!isDirty()) return;
    e.preventDefault();
    e.returnValue = '';   // Browser verlangen das; der Text ist nicht steuerbar
  };
  listen(window, 'beforeunload', beforeUnload);

  // Links innerhalb der App (Tabs, Seitenleiste): bei offenen Änderungen fragen
  const leaveGuard = async (e) => {
    const a = e.target.closest?.('a[href^="#/"]');
    if (!a || !isDirty() || e.defaultPrevented) return;
    e.preventDefault();
    e.stopPropagation();
    const ok = await confirmModal({
      title: t('settings.leave.title'),
      message: t('settings.leave.message'),
      confirmLabel: t('settings.leave.confirm'), danger: true,
    });
    if (!ok) return;
    baseline = JSON.stringify(collectSettings(container));   // nicht erneut fragen
    location.hash = a.getAttribute('href');
  };
  listen(document, 'click', leaveGuard);

  // v3.6.0 — Felder in einem zugeklappten Bereich (CO₂-Faktoren): Was sich
  // geändert hat, klappt nach dem Speichern und bei einem Fehler auf — sonst
  // sähe man weder den gespeicherten Wert noch die Ursache der Meldung.
  const openChanged = () => {
    let before = {};
    try { before = JSON.parse(baseline || '{}'); } catch { /* alles gilt als geändert */ }
    for (const [k, v] of Object.entries(collectSettings(container))) {
      if (JSON.stringify(v) !== JSON.stringify(before[k])) openDisclosureOf(container.querySelector(`[data-key="${k}"]`));
    }
  };
  const save = async () => {
    const invalid = firstInvalidSetting(container);
    if (invalid) {
      container.querySelectorAll('.invalid').forEach(el => openDisclosureOf(el));
      invalid.scrollIntoView?.({ block: 'center' });
      invalid.focus();
      toastErr(t('settings.invalidFields'));
      return;
    }
    const payload = collectSettings(container);
    try {
      // v2.11.0 (FE-12) — saveSettings meldet die Änderung; die Seitenleiste
      // folgt einer geänderten Auswahl der Verbrauchsarten sofort.
      await saveSettings(payload);
      openChanged();
      // v3.6.0 — eine eben eingetragene Angabe ist keine Annahme mehr
      container.querySelectorAll('[data-role="assumed"]').forEach(h => {
        if (h.closest('.settings-field')?.querySelector('[data-type="number"]')?.value.trim()) h.remove();
      });
      baseline = JSON.stringify(payload);   // Stand ist gesichert
      markDirty();
      toastOk(t('settings.saved'));
    } catch (e) { openChanged(); toastErr(e.message); }
  };
  container.querySelector('#btn-save')?.addEventListener('click', save);
  container.querySelector('#btn-discard')?.addEventListener('click', () => {
    baseline = '';   // die Rückfrage nicht auslösen
    render(container, params, ctx);
  });

  // Sprache wechseln: i18n neu laden, Sidebar + View neu rendern.
  // v3.5.0 (N1022) — derselbe Weg wie im Einrichtungsassistenten (lib/language.js)
  const applyLanguage = async (lang) => {
    await switchLanguage(lang);
    toastOk(t('settings.lang.saved'));
    render(container, params, ctx);
  };
  // v3.1.0 (I18N-29) — Sprache dieses Geräts (im Browser); leer = wie die Installation
  container.querySelector('#lang-select')?.addEventListener('change', async (e) => {
    const lang = e.target.value || null;
    setDeviceLanguage(lang);
    // v3.2.0 (F1023) — mit Anmeldung ist es die Sprache der Person (auf jedem Gerät)
    if (session?.user && session.mode !== 'off') api.updateMe({ language: lang }).catch(() => {});
    try { await applyLanguage(lang || settings.language); }
    catch (err) { toastErr(err.message); }
  });
  // Standardsprache der Installation: neue Geräte, PDF, CSV, Home Assistant
  container.querySelector('#lang-default-select')?.addEventListener('change', async (e) => {
    const lang = e.target.value;
    try {
      await saveSettings({ language: lang });
      settings.language = lang;
      // v3.12.0 (Sichtung S10) — die Anzeige wechselt nur, wenn hier die Vorgabe gilt, nicht bei eigener Wahl der Person oder des Geräts
      const own = session?.user && session.mode !== 'off' ? session.user.prefs?.language : null;
      if (resolveLanguage({ person: own, device: deviceLanguage(), installation: lang }).source === 'installation') await applyLanguage(lang);
      else { toastOk(t('settings.lang.defaultSaved')); render(container, params, ctx); }
    } catch (err) { toastErr(err.message); }
  });

  // v2.7.0 — Land, Währung und Zeitzone wirken wie die Sprache sofort. Beim
  // Land schlägt ein Dialog die abweichenden Werte des Länderprofils vor.
  // v3.5.0 (N1022) — gespeichert über POST /api/settings/country (lib/region.js):
  // nur, was vom Default abweicht, wie beim Erststart und in der Einrichtung
  container.querySelector('#country-select')?.addEventListener('change', async (e) => {
    const code = e.target.value;
    const profile = (countries || []).find(c => c.code === code);
    const diff = profile ? profileDiff(settings, profile) : [];
    let withProfile = false;
    if (diff.length) {
      const choice = await chooseProfile(code, diff, profile);
      if (choice === null) { e.target.value = settings.country; return; }
      withProfile = choice === 'all';
    }
    applyRegion(container, { country: code, profile: withProfile }, countries, () => render(container, params, ctx));
  });
  container.querySelector('#currency-select')?.addEventListener('change', (e) => applyRegion(container, { currency: e.target.value }, countries, () => render(container, params, ctx)));
  container.querySelector('#tz-select')?.addEventListener('change', (e) => applyRegion(container, { timezone: e.target.value }, countries, () => render(container, params, ctx)));

  if (page === 'data') wireDataPage(container, utilities, () => render(container, params, ctx));
  if (page === 'access' || page === KEYS) wireAccessPage(container, () => render(container, params, ctx));
  if (page === 'access') wireUsersCard(container, session, () => render(container, params, ctx));
  if (page === 'household:people') wirePeopleCard(container, () => render(container, params, ctx));   // v3.9.0
  if (page === 'access') wireSecurityCheck(container, () => render(container, params, ctx));
  if (page === 'general') { wireLevelCard(container, session); wireThemeCard(container); wireRegister(container, session); }
  if (page === 'household:utilities') container.querySelector('#btn-concept-wizard')?.addEventListener('click', () => {
    openConceptWizard().then(done => { if (done && container.isConnected) render(container, params, ctx); }).catch(e => toastErr(e.message));
  });
  if (page === HA) wireIntegrationsPage(container, () => render(container, params, ctx));
  if (page === HA && sub === 'notifications') wireHaNotifyCard(container, haNotify, isAdmin, () => render(container, params, ctx));   // v3.9.0
  if (page === AI) wireMcpCards(container, mcp);   // v3.12.0 (F1037)
  if (page === READING) wireReadingCard(container, inboxCloud, settings, isAdmin, () => render(container, params, ctx));   // v3.7.0
  wireDisclosures(container);   // v3.6.0

  // v3.6.0 — Sprung zu einem Feld (`?field=wohnflaeche_m2`); v3.12.0 (M3) auch über der eigenen Stufe
  jumpToField(container, ctx?.query);

  // v3.12.0 (M3) — Karten über der Stufe: „Weitere zeigen“ öffnet sie auf dieser Seite, die Stufe bleibt
  const above = () => [...container.querySelectorAll('.settings-card[data-min-level]')].filter(c => !atLeast(c.getAttribute('data-min-level')));
  const moreSlot = container.querySelector('[data-role="settings-more"]');
  if (moreSlot && above().length) {
    moreSlot.innerHTML = `<button type="button" class="btn btn--ghost btn--sm" id="weitere-zeigen">${escapeHtml(t('settings.more'))}</button>`;
    moreSlot.querySelector('#weitere-zeigen').addEventListener('click', () => {
      const cards = above();
      cards.forEach(c => c.removeAttribute('data-min-level'));
      moreSlot.innerHTML = '';
      (cards[0]?.querySelector('input:not([type="hidden"]), select, textarea, button, summary') || cards[0])?.focus?.();
    });
  }

  // Der Router ruft diese Funktion beim Verlassen der Ansicht auf.
  return unlistenAll;
}
