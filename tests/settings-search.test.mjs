// =====================================================================
// v3.12.0 (M3) — Suche in den Einstellungen und Umzüge: Jede Einstellung wird gefunden, in allen
// 7 Sprachen, auch über der Stufe, und führt zu einer Seite, die das Feld wirklich zeigt.
//
// Ohne Server. Ob das Feld im echten Browser in den Blick kommt, prüft die Abnahme.
// =====================================================================
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');
const JS = pathToFileURL(resolve(ROOT, 'public/js')).href;
let pass = 0, fail = 0;
const t = (name, cond, detail = '') => {
  if (cond) { pass++; return; }
  fail++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
};

const dom = new JSDOM('<!DOCTYPE html><html><body><main id="view"></main><div id="toast-stack"></div><div id="modal-root"></div></body></html>', { url: 'http://127.0.0.1/', pretendToBeVisual: true });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'getComputedStyle', 'sessionStorage', 'localStorage']) {
  try { global[k] = dom.window[k]; } catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch { /* */ }
global.fetch = async () => new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });

const i18n = await import(`${JS}/lib/i18n.js`);
const { buildIndex, searchIndex, normalize, PLACES } = await import(`${JS}/lib/settings-index.js`);
const { GROUPS } = await import(`${JS}/views/settings-fields.js`);
const { redirectFor, routeExists } = await import(`${JS}/router.js`);
const LANGS = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];
const setLang = async (l) => {
  global.fetch = async (u) => {
    const file = String(u).match(/locales\/[a-z]+\.json/)?.[0];
    return new Response(file ? read(`public/${file}`) : '{}', { status: 200, headers: { 'content-type': 'application/json' } });
  };
  await i18n.initI18n(l);
};

// ── Jeder Schlüssel aus SettingsService::DEFAULTS ─────────────────────
const php = read('src/Services/SettingsService.php');
const defaults = [...php.match(/const DEFAULTS\s*=\s*\[([\s\S]*?)\n\s*\];/)[1].matchAll(/^\s*'([a-z0-9_]+)'\s*=>/gm)].map(m => m[1]);
// funktionen.md: veraltet, ohne Wirkung, nirgends angezeigt
const OHNE_WIRKUNG = ['min_temp_days_forecast', 'baujahr', 'billing_cycle_anchor_heizoel', 'billing_cycle_anchor_pellets'];
t('DEFAULTS gelesen', defaults.length > 100, String(defaults.length));

const who = { admin: true, login: true, isAllowed: () => true };
for (const lang of LANGS) {
  await setLang(lang);
  const index = buildIndex(GROUPS);
  t(`${lang}: Register nicht leer`, index.length > 100);
  t(`${lang}: Kein Eintrag ohne Titel`, index.every(e => e.title.trim()), index.filter(e => !e.title.trim()).map(e => e.key).join(' '));
  for (const key of defaults.filter(k => !OHNE_WIRKUNG.includes(k))) {
    const hits = searchIndex(index, key, who).shown;
    t(`${lang}: Schlüssel ${key} wird gefunden`, hits.length > 0);
  }
  // Über den Namen, den die Person in dieser Sprache sieht
  for (const e of index) {
    const hit = searchIndex(index, e.title, who).shown.some(x => x.key === e.key);
    t(`${lang}: „${e.title}“ findet ${e.key}`, hit);
  }
}
await setLang('de');
const index = buildIndex(GROUPS);

// ── Stufen und Rollen ─────────────────────────────────────────────────
const entry = (k) => index.find(e => e.key === k);
const beginner = { admin: true, login: false, isAllowed: (l) => l === 'beginner' };
const found = (q, w) => searchIndex(index, q, w);
t('Über der Stufe: nicht in der ersten Liste, aber unter „Weitere zeigen“',
  !found('contract_remind_days_1', beginner).shown.length && found('contract_remind_days_1', beginner).more.some(e => e.key === 'contract_remind_days_1'));
t('Rechnen liegt über Einsteiger', entry('forecast_model').level === 'expert');
t('Zähler, Personen, Gebäude ab Einsteiger', ['meters', 'people', 'wohnflaeche_m2', 'billing_cycle_anchor_gas'].every(k => entry(k).level === 'beginner'));
const member = { admin: false, login: true, isAllowed: () => true };
for (const q of ['mcp_enabled', 'api_keys', 'frame_ancestors', 'allowed_hosts', 'users', 'activity_keep_months']) {
  t(`Mitglied findet ${q} nicht`, !found(q, member).shown.some(e => e.key === q));
  t(`Verwalter findet ${q}`, found(q, who).shown.some(e => e.key === q));
}
t('Passwort ändern nur mit Anmeldung', !found('my_account', { admin: true, login: false, isAllowed: () => true }).shown.length && found('my_account', who).shown.length > 0);

// ── So sucht ein Mensch ───────────────────────────────────────────────
t('Groß, klein, Akzent', normalize('ZÄHLER Straße') === 'zahler strasse');
for (const [q, key] of [['Sprache', 'language'], ['Stichtag', 'billing_cycle_anchor_gas'], ['Passwort', 'my_account'], ['zaehler', 'meters'], ['Wohnfläche', 'wohnflaeche_m2'], ['wohnflache', 'wohnflaeche_m2'], ['Rechnung prüfen', 'bill_check'], ['Vertragsfrist', 'contract_remind_days_1'], ['Vorwarnung', 'contract_remind_days_1'], ['Heizgrenze', 'hdd_base_temp'], ['Messkonzept', 'concept'], ['Hell', 'theme']]) {
  t(`Suche „${q}“ findet ${key}`, found(q, who).shown.some(e => e.key === key), found(q, who).shown.slice(0, 4).map(e => e.key).join(' '));
}
t('Bester Treffer zuerst', found('Wohnfläche', who).shown[0]?.key === 'wohnflaeche_m2', found('Wohnfläche', who).shown[0]?.key);
t('Mehrere Wörter: alle müssen passen', found('Gas Stichtag', who).shown.some(e => e.key === 'billing_cycle_anchor_gas') && !found('Gas Stichtag', who).shown.some(e => e.key === 'billing_cycle_anchor_strom'));
t('Leere Suche: nichts', !found('   ', who).shown.length);
t('Unsinn: nichts', !found('xyzzy', who).shown.length && !found('xyzzy', who).more.length);

// ── Jedes Ziel ist eine Seite, und der Sprung nennt das Feld ──────────
const landed = (href) => {
  const [p] = href.slice(1).split('?');
  return !redirectFor(p, new URLSearchParams(href.split('?')[1] || '')) && routeExists(p);
};
for (const e of index.filter(x => x.href.startsWith('#/'))) t(`Ziel von ${e.key} ist eine Seite ohne Umweg: ${e.href}`, landed(e.href));
for (const [k, p] of Object.entries(PLACES)) t(`Ort ${k}: Bereich und Titel im Katalog`, i18n.hasTranslation(p.area) && i18n.hasTranslation(p.title), `${p.area} ${p.title}`);

// ── Jedes alte Lesezeichen mit ?field= kommt dort an, wo das Feld jetzt steht ──
const placeOf = (g) => PLACES[g.page].href.slice(1);
for (const g of GROUPS) for (const f of g.fields) {
  for (const old of ['/settings', '/settings/general', '/settings/household', '/settings/utilities', '/settings/expert', '/settings/integrations']) {
    const got = redirectFor(old, new URLSearchParams(`field=${f.key}`));
    // Felder von `access` und `data` bleiben auf ihrer Seite: Das Lesezeichen kennt nur das alte Blatt
    if (g.page === 'access') continue;
    t(`${old}?field=${f.key} führt nach ${placeOf(g)}`, got === `#${placeOf(g)}?field=${f.key}`, String(got));
  }
}
for (const [old, want] of [
  ['/settings/household', '#/household/building'], ['/settings/utilities', '#/household/utilities'], ['/settings/general', '#/settings'], ['/settings/expert', '#/settings/calculation'],
  ['/utility/gas/meters', '#/household/meters?art=gas'],
]) t(`Lesezeichen ${old} → ${want}`, redirectFor(old, new URLSearchParams('')) === want, String(redirectFor(old, new URLSearchParams(''))));
t('Zählertausch aus dem Posteingang behält alles', redirectFor('/utility/gas/meters', new URLSearchParams('replace=m1&date=2026-01-02&old=5')) === '#/household/meters?art=gas&replace=m1&date=2026-01-02&old=5');
t('Einrichtung Messkonzept', redirectFor('/utility/strom/meters', new URLSearchParams('concept=1')) === '#/household/meters?art=strom&concept=1');
t('Einstellungen ohne Feld bleiben, wo sie sind', redirectFor('/settings', new URLSearchParams('')) === null);
t('Standardsprache, Land, Währung, Zeitzone', ['language', 'country', 'currency', 'timezone'].every(k => redirectFor('/settings/general', new URLSearchParams(`field=${k}`)) === `#/settings/region?field=${k}`));
t('Zugang bleibt', redirectFor('/settings/access', new URLSearchParams('')) === null);

// ── Vorgaben ohne Verwalter-Prüfung, eine Stelle (offene Entscheidung des Maintainers) ─────
const region = read('public/js/views/settings-region.js');
t('Region: keine eigene Rollenprüfung in der Karte', !/isAdmin|sessionRole/.test(region));

// ── Sätze im Katalog nennen keine alten Orte ──────────────────────────
for (const lang of LANGS) {
  const cat = read(`public/locales/${lang}.json`);
  t(`${lang}: keine Seite „Experte“, „Allgemein“ mehr in Pfaden`, !/(Einstellungen|Settings|Paramètres|Impostazioni|Ajustes|Definições|Instellingen) → (Allgemein|General|Général|Generali?e?|Geral|Algemeen|Experte|Expert|Esperto|Experto|Especialista)\b/.test(cat));
}

console.log(`\n  settings-search: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
