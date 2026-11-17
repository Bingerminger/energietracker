// =====================================================================
// v3.12.0 — Härtung: Hinweis auf neue Versionen, Standort und Wetterabgleich
// ändern nur Verwalter (App::ADMIN_SETTINGS). Die Oberfläche zeigt Mitgliedern
// den Stand gesperrt, statt sie in ein 403 laufen zu lassen:
//   - Einstellungen → Anzeige: `update_check` gesperrt, mit Hinweis
//   - Verbindungen → Wetterdaten: Ortssuche, Koordinaten, Ort und
//     „automatisch füllen“ gesperrt; der Abgleich speichert keinen Standort
// Ohne Anmeldung und für Verwalter bleibt alles bedienbar. Die Liste der
// gesperrten Felder ist dieselbe wie in src/bootstrap.php.
//
// Aufruf: node tests/outbound-admin.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..');
const JS = resolve(ROOT, 'public', 'js');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
global.fetch = async (url) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json$/);
  if (!m) return new Response('{}', { status: 404 });
  return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
};

const I = await import(`${JS}/lib/i18n.js`);
await I.initI18n('de');
const L = await import(`${JS}/lib/levels.js`);
const { GROUPS, renderGroup } = await import(`${JS}/views/settings-fields.js`);

const frag = (html) => { const d = document.createElement('div'); d.innerHTML = html; return d; };
const updates = GROUPS.find(g => g.gkey === 'updates');
const member = { mode: 'password', role: 'member', user: { id: 'u1', name: 'Ben', prefs: {} } };

console.log('Einstellungen → Anzeige: Hinweis auf neue Versionen');
for (const [who, session] of [['Mitglied', member], ['Verwalter', { ...member, role: 'admin' }], ['ohne Anmeldung', { mode: 'off' }]]) {
  L.setSession(session);
  const d = frag(renderGroup(updates, { update_check: true }));
  const box = d.querySelector('#set-update_check');
  const locked = who === 'Mitglied';
  t(`${who}: Schalter ${locked ? 'gesperrt' : 'bedienbar'}`, box?.disabled === locked);
  t(`${who}: Stand lesbar`, box?.checked === true);
  const hint = d.querySelector('[data-role="admin-only"]');
  t(`${who}: Hinweis ${locked ? 'da' : 'fehlt'}`, locked ? hint?.textContent.trim() === I.t('settings.region.adminOnly') : !hint);
}
L.setSession(null);

console.log('Verbindungen → Wetterdaten: Standort');
const temps = readFileSync(resolve(JS, 'views', 'temperatures.js'), 'utf8');
const boot = readFileSync(resolve(ROOT, 'src', 'bootstrap.php'), 'utf8');
const adminBlock = boot.slice(boot.indexOf('private const ADMIN_SETTINGS'), boot.indexOf('];', boot.indexOf('private const ADMIN_SETTINGS')));
const admin = new Set([...adminBlock.matchAll(/'([a-z_]+)'/g)].map(m => m[1]));
const fields = [...temps.matchAll(/<input[^>]*data-field="([a-z_]+)"[^>]*>/g)].map(m => [m[1], m[0]]);
t('Seite nennt Felder', fields.length >= 4, fields.map(f => f[0]).join(', '));
for (const [key, tag] of fields) {
  if (!admin.has(key)) continue;
  t(`${key}: für Mitglieder gesperrt`, tag.includes('${dis}'));
}
for (const key of ['update_check', 'location_name', 'latitude', 'longitude', 'weather_auto_fill']) {
  t(`${key} steht in ADMIN_SETTINGS`, admin.has(key));
}
t('Ortssuche gesperrt', /id="geo-q"[^>]*\$\{dis\}/.test(temps) && /id="geo-go"\$\{dis\}/.test(temps));
t('Rolle aus der Sitzung', /const canEdit = sessionRole\(\) === 'admin';/.test(temps) && /const dis = canEdit \? '' : ' disabled';/.test(temps));
t('Abgleich speichert für Mitglieder keinen Standort', /const saveLocation = async[^\n]*\n\s*if \(!canEdit\) return true;/.test(temps));
t('Hinweis, wer ändert', temps.includes("data-role=\"location-admin-only\">${escapeHtml(t('settings.region.adminOnly'))}"));

// ── v3.13.0 — Verbindungen: Mitglieder sehen nur, was sie ändern dürfen ─────────────────────────────
// Token-Knöpfe für Home Assistant, evcc und Sicherheit im Heimnetz richtet ein Verwalter ein. Mitglieder
// bekommen dort den Satz, wer es einrichtet, statt Feldern und Knöpfen, die mit 403 enden; ohne Anmeldung und
// für Verwalter bleibt alles bedienbar. Die Rechte am Server bleiben, wie sie sind (OutboundSettingsAccessTest).
console.log('Verbindungen: Token, evcc, Heimnetz');
const { renderHaTokenCard } = await import(`${JS}/views/settings-integrations.js`);
const connSrc = readFileSync(resolve(JS, 'views', 'connections.js'), 'utf8');
const settingsSrc = readFileSync(resolve(JS, 'views', 'settings.js'), 'utf8');
for (const [who, session] of [['Mitglied', member], ['Verwalter', { ...member, role: 'admin' }], ['ohne Anmeldung', { mode: 'off' }]]) {
  L.setSession(session);
  const isAdmin = who !== 'Mitglied';
  const card = frag(renderHaTokenCard({ enabled: true, last_used_at: null }, session, (session.role || 'admin') === 'admin'));
  t(`${who}: Token-Knöpfe ${isAdmin ? 'da' : 'ausgeblendet'}`, isAdmin ? !!card.querySelector('#btn-ha-generate') && !!card.querySelector('#btn-ha-revoke')
    : !card.querySelector('#btn-ha-generate, #btn-ha-revoke, #ha-token-state'));
  t(`${who}: Satz, wer den Token einrichtet, ${isAdmin ? 'fehlt' : 'steht da'}`, isAdmin ? !card.querySelector('[data-role="ha-token-admin-only"]')
    : card.querySelector('[data-role="ha-token-admin-only"]')?.textContent.trim() === I.t('settings.ha.memberToken'));
  for (const gkey of ['evcc', 'homeTls']) {
    const g = GROUPS.find(x => x.gkey === gkey);
    const d = frag(renderGroup(g, { evcc_endpoint: 'http://192.168.178.30:7070', home_ca_pem: '', home_tls_insecure: false }));
    const inputs = d.querySelectorAll('[data-key]').length;
    t(`${who}: ${gkey} ${isAdmin ? 'mit Feldern' : 'ohne Felder, mit dem Satz'}`, isAdmin ? inputs === g.fields.length && !d.querySelector('[data-role="admin-only-group"]')
      : inputs === 0 && d.querySelector('[data-role="admin-only"]')?.textContent.trim() === I.t('settings.adminOnlyCard'), `${inputs} Felder`);
  }
}
L.setSession(null);
t('evcc und Heimnetz: jede Taste der Gruppe steht in ADMIN_SETTINGS', ['evcc', 'homeTls'].every(k => GROUPS.find(g => g.gkey === k).fields.every(f => admin.has(f.key))));
t('Verbindungen: Mitglieder fragen den Token-Stand nicht ab (adminOnly)', /admin \? api\.authStatus\(\)/.test(connSrc) && /needs\(HA, KEYS\) && adminRole \? api\.authStatus\(\)/.test(settingsSrc));
t('Verbindungen: die Zeile Home Assistant zeigt Mitgliedern keinen Stand', /tag: !admin \? '' :/.test(connSrc));

// ── v3.13.0 — Angaben für Briefe nur Verwalter (`letters_admin_only`) ─────────────────────────────────
console.log('Haushalt → Gebäude: Angaben für Briefe');
const letters = GROUPS.find(g => g.gkey === 'letters');
for (const on of [false, true]) {
  for (const [who, session] of [['Mitglied', member], ['Verwalter', { ...member, role: 'admin' }], ['ohne Anmeldung', { mode: 'off' }]]) {
    L.setSession(session);
    const d = frag(renderGroup(letters, { letter_sender_name: 'Ida Quellfeld', letter_sender_address: 'Weg 1', letter_supply_address: 'Weg 1', letters_admin_only: on }));
    const locked = on && who === 'Mitglied';
    const texts = ['letter_sender_name', 'letter_sender_address', 'letter_supply_address'].map(k => d.querySelector(`[data-key="${k}"]`));
    t(`${on ? 'an' : 'aus'}, ${who}: Absender und Lieferanschrift ${locked ? 'gesperrt' : 'bedienbar'}`, texts.every(x => x && x.disabled === locked) && texts[0].value === 'Ida Quellfeld');
    const sw = d.querySelector('[data-key="letters_admin_only"]');
    t(`${on ? 'an' : 'aus'}, ${who}: Schalter ${who === 'Mitglied' ? 'gesperrt' : 'bedienbar'}, Stand lesbar`, !!sw && sw.disabled === (who === 'Mitglied') && sw.checked === on);
  }
}
L.setSession(null);
t('letters_admin_only steht in ADMIN_SETTINGS', admin.has('letters_admin_only'));
const dialogSrc = readFileSync(resolve(JS, 'components', 'letter-dialog.js'), 'utf8');
t('Brief-Dialog: Mitglieder mit Sperre bekommen kein „Im Haushalt übernehmen“', /const locked = getSettingsSync\(\)\?\.letters_admin_only === true && sessionRole\(\) !== 'admin';/.test(dialogSrc)
  && /innerHTML = locked\s*\n?\s*\? \(changed/.test(dialogSrc) && /supplySrc\.innerHTML = locked/.test(dialogSrc));

// ── v3.13.0 (K2d) — Härtung: Texterkennung, Auslesen und Benachrichtigung nur Verwalter ─────────────────
// Jedes Feld mit einem Schlüssel aus ADMIN_SETTINGS ist für Mitglieder gesperrt: in der Karte „Auslesen“, in der
// Gruppe „Texterkennung“ der Einstellungen und in der Karte der Benachrichtigung.
console.log('Verbindungen: Auslesen, Texterkennung, Benachrichtigung');
for (const k of ['ocr_api', 'ocr_model', 'ocr_timeout_s', 'inbox_model', 'inbox_timeout_s', 'inbox_cloud_timeout_s', 'ha_notify_events', 'ha_notify_hour']) {
  t(`${k} steht in ADMIN_SETTINGS`, admin.has(k));
}
const { renderReadingCard } = await import(`${JS}/components/inbox-cloud.js`);
const { renderHaNotifyCard } = await import(`${JS}/components/ha-notify.js`);
const readStatus = { presets: [{ id: 'custom', label: 'Eigener Dienst' }], preset: 'custom', model: '', timeout_s: 120, enabled: false, has_key: false };
const readSettings = { inbox_provider: 'local', ocr_api: 'ollama', inbox_model: 'qwen3.8', inbox_timeout_s: 300, ocr_endpoint: '' };
for (const [who, isAdmin] of [['Mitglied', false], ['Verwalter', true]]) {
  const card = frag(renderReadingCard(readStatus, readSettings, isAdmin));
  const fields = [...card.querySelectorAll('[data-field]')].filter(el => admin.has(el.getAttribute('data-field')) && el.matches('input, select'));
  t(`${who}: Auslesen, ${fields.length} Felder ${isAdmin ? 'bedienbar' : 'gesperrt'}`, fields.length >= 6 && fields.every(el => el.disabled === !isAdmin),
    fields.filter(el => el.disabled === isAdmin).map(el => el.getAttribute('data-field')).join(', '));
  const ha = frag(renderHaNotifyCard({ enabled: true, target: 'webhook', url: '', service: '', events: ['reminder'], hour: 8, has_token: false }, isAdmin));
  const haFields = [...ha.querySelectorAll('[data-field="ha_notify_hour"], [data-field="ha_notify_events"] input')];
  t(`${who}: Benachrichtigung, Ereignisse und Uhrzeit ${isAdmin ? 'bedienbar' : 'gesperrt'}`, haFields.length >= 2 && haFields.every(el => el.disabled === !isAdmin));
}
const ocrGroup = GROUPS.find(g => g.gkey === 'ocr');
for (const [who, session] of [['Mitglied', member], ['Verwalter', { ...member, role: 'admin' }]]) {
  L.setSession(session);
  const d = frag(renderGroup(ocrGroup, { ocr_model: 'bildmodell', ocr_timeout_s: 30 }));
  const ins = [...d.querySelectorAll('[data-key]')];
  const locked = who === 'Mitglied';
  t(`${who}: Texterkennung (Bildmodell, Zeitlimit) ${locked ? 'gesperrt, mit Hinweis' : 'bedienbar'}`, ins.length === 2 && ins.every(el => el.disabled === locked)
    && (locked ? d.querySelectorAll('[data-role="admin-only"]').length === 2 : !d.querySelector('[data-role="admin-only"]')));
}
L.setSession(null);
t('Texterkennung: jede Taste der Gruppe steht in ADMIN_SETTINGS', ocrGroup.fields.every(f => admin.has(f.key)));

console.log(`\n${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
