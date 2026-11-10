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

console.log(`\n${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
