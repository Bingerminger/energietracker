// =====================================================================
// v3.5.0 (N1022) — Einrichtung fragt zuerst Sprache und Land, ohne Server.
//
// Geprüft wird der Assistent selbst (components/setup-wizard.js) in JSDOM,
// die API ist ein Stub, der jede Anfrage mitschreibt:
//   • Schrittfolge in beiden Modi, Sprache und Land zuerst,
//   • Vorauswahl = was gilt, beim erneuten Öffnen die aktuellen Werte,
//   • Sprachwechsel beschriftet den Assistenten sofort neu (Fokus bleibt),
//     Abbrechen nimmt ihn zurück,
//   • unter dem Land steht, was das Profil setzt; Englisch + Deutschland
//     schreibt wie en-GB,
//   • gespeichert wird nur, was sich geändert hat — das Land über
//     POST /api/settings/country (derselbe Weg wie die Einstellungen),
//   • nach „Los geht’s“ bleibt der Sprung zu „Zählerstände“ stehen,
//   • Demo (eigener Prozess, `--demo`): Sprache je Gerät, Land gesperrt,
//     kein Schreibzugriff.
//
// Aufruf: node tests/setup-wizard.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const __file = fileURLToPath(import.meta.url);
const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');
const ROOT = resolve(dirname(__file), '..');
const JS = resolve(ROOT, 'public', 'js');
const DEMO_RUN = process.argv.includes('--demo');
const BOOT_RUN = process.argv.includes('--boot');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const tick = (ms = 20) => new Promise(r => setTimeout(r, ms));

// ── Umgebung ────────────────────────────────────────────────────────────
const vc = new VirtualConsole();   // „Not implemented: navigation“ (location.reload) stumm
const dom = new JSDOM(`<!DOCTYPE html><html${DEMO_RUN ? ' data-demo' : ''}><head></head><body>
  <div id="app"><header class="topbar"><div class="topbar__actions"><button id="theme-toggle"><span class="topbar__btn-icon"></span></button></div></header>
  <aside id="sidebar"><nav id="primary-nav"></nav></aside><main id="view"></main></div>
  <nav id="tabbar"></nav><div id="modal-root"></div><div id="toast-stack"></div></body></html>`,
{ url: 'http://127.0.0.1/#/settings', pretendToBeVisual: true, virtualConsole: vc });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
dom.window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });

// Länderprofile wie GET /api/countries (Auszug; die Regeln des Servers prüft CountrySwitchTest.php)
const UBA = { 2023: 379, 2024: 353, 2025: 344 };
const COUNTRIES = [
  { code: 'DE', languages: ['de'], currency: 'EUR', timezone: 'Europe/Berlin', hdd_base_temp: 15, co2_strom: 380, co2_strom_years: UBA,
    location_name: 'Leipzig Zentrum', latitude: 51.3397, longitude: 12.3731, gas_cv_unit: 'kwh' },
  { code: 'AT', languages: ['de'], currency: 'EUR', timezone: 'Europe/Vienna', hdd_base_temp: 15, co2_strom: 103,
    location_name: 'Wien', latitude: 48.2082, longitude: 16.3738, gas_cv_unit: 'kwh' },
  { code: 'GB', languages: ['en'], currency: 'GBP', timezone: 'Europe/London', hdd_base_temp: 15.5, co2_strom: 217,
    location_name: 'London', latitude: 51.5074, longitude: -0.1278, gas_cv_unit: 'mj' },
];
const DEFAULTS = { language: 'de', country: 'DE', currency: 'EUR', timezone: 'Europe/Berlin', hdd_base_temp: 15, co2_strom: 380,
  co2_strom_years: UBA, location_name: 'Leipzig Zentrum', latitude: 51.3397, longitude: 12.3731, gas_cv_unit: 'kwh',
  active_utilities: ['gas', 'strom', 'wasser'], setup_pending: true, setup_persona: null, ui_level: 'expert' };
let settings = { ...DEFAULTS };
const calls = [];
const utilitiesFor = (lang) => [
  { key: 'gas', label: lang === 'en' ? 'Gas' : 'Gas', icon: '🔥' },
  { key: 'strom', label: lang === 'en' ? 'Electricity' : 'Strom', icon: '⚡' },
  { key: 'wasser', label: lang === 'en' ? 'Water' : 'Wasser', icon: '💧' },
];
const json = (data) => new Response(JSON.stringify({ success: true, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });

let countriesFailOnce = true;
global.fetch = dom.window.fetch = async (url, opts = {}) => {
  const u = String(url);
  const loc = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (loc) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${loc[1]}.json`), 'utf8'), { status: 200 });
  const method = opts.method || 'GET';
  const lang = opts.headers?.['X-ET-Language'] || 'de';
  // Demo: Abzug unter demo-api/ (lib/demo-mode.js)
  const idx = u.match(/demo-api\/index-([a-z]+)\.json$/);
  if (idx) return new Response(JSON.stringify({ '/api/settings': 's.json', '/api/utilities': `u-${idx[1]}.json`, '/api/countries': 'c.json' }), { status: 200 });
  const rf = u.match(/demo-api\/r\/(.+)$/);
  if (rf) {
    calls.push({ method: 'GET', path: 'demo:' + rf[1] });
    const f = rf[1];
    return json(f === 's.json' ? settings : f === 'c.json' ? COUNTRIES : utilitiesFor(f.slice(2, 4)));
  }
  if (/demo-api\//.test(u)) return new Response('{}', { status: 404 });
  const path = u.replace(/^.*api\.php/, '');
  const body = opts.body ? JSON.parse(opts.body) : null;
  calls.push({ method, path, body, lang });
  if (path === '/api/settings' && method === 'GET') return json(settings);
  if (path === '/api/settings' && method === 'PATCH') { settings = { ...settings, ...body }; return json(settings); }
  if (path === '/api/settings/country' && method === 'POST') {
    const p = COUNTRIES.find(c => c.code === body.country);
    const { code, languages, ...values } = p;
    settings = body.profile === false ? { ...settings, country: code } : { ...settings, ...values, co2_strom_years: values.co2_strom_years ?? [], country: code };
    return json(settings);
  }
  // v3.5.1 — der erste Abruf scheitert (wie beim Erststart beobachtet); der Assistent lädt nach
  if (path === '/api/countries' && countriesFailOnce) { countriesFailOnce = false; return new Response('{"success":false}', { status: 500 }); }
  if (path === '/api/countries') return json(COUNTRIES);
  if (path === '/api/utilities') return json(utilitiesFor(lang));
  if (path === '/api/session') return json({ mode: 'off', authenticated: true });
  return json(null);
};

if (BOOT_RUN) {
  // ── Start der App: englische Installation mit Stufe „Erfahren“ ────────
  // (Zustand nach der Einrichtung). Die Seitenleiste nennt die Verbrauchsarten
  // englisch — bis v3.4 holte der Stufenwechsel sie noch vor dem Katalog,
  // also mit „de“ (app.js: applyLevel erst nach initI18n).
  settings = { ...DEFAULTS, language: 'en', ui_level: 'advanced', setup_pending: false };
  dom.window.scrollTo = () => {};
  await import(`${JS}/app.js`);
  for (let i = 0; i < 50 && !/Electricity|Strom/.test(document.getElementById('primary-nav')?.textContent || ''); i++) await tick(40);
  await tick(200);
  const nav = document.getElementById('primary-nav')?.textContent.replace(/\s+/g, ' ') || '';
  t('Start: Seitenleiste in der Sprache der Installation, auch mit Stufe „Erfahren“', /Electricity/.test(nav) && !/Strom|Wasser/.test(nav), nav.slice(0, 80));
  t('… Stufe gilt', document.documentElement.getAttribute('data-level') === 'advanced');
  console.log(`DEMO ${pass} ${fail}`);
  process.exit(fail ? 1 : 0);
}

const i18n = await import(`${JS}/lib/i18n.js`);
await i18n.initI18n('de');
const state = await import(`${JS}/state.js`);
const W = await import(`${JS}/components/setup-wizard.js`);

const modal = () => document.querySelector('#modal-root .modal');
const q = (sel) => modal()?.querySelector(sel);
const next = () => q('[data-act="next"]').click();
const change = (el, value) => { el.value = value; el.dispatchEvent(new dom.window.Event('change', { bubbles: true })); };
const fact = (key) => q(`[data-fact="${key}"] dd`)?.textContent || '';
const writes = () => calls.filter(c => c.method !== 'GET');
const open = async (opts = {}) => {
  state.invalidateSettings();
  await W.openSetupWizard(opts);
  await tick();
};

if (DEMO_RUN) {
  // ── Demo: Sprache je Gerät, Land gesperrt, nichts geschrieben ─────────
  localStorage.setItem('et-demo-persona', 'showcase');
  t('Demo: Schritte Sprache/Land → Haushalt → Stufe → Weiter', W.setupSteps().join() === 'region,persona,level,finish', W.setupSteps().join());
  await open({ firstRun: true });
  t('Demo: Schritt 1 fragt Sprache und Land', !!q('#setup-lang') && !!q('#setup-country') && /1 von 4/.test(q('[data-role="progress"]')?.textContent || ''));
  // v3.5.1 — Land als Text mit Hinweis, kein gesperrtes Auswahlfeld (wirkte am iPad wie ein Fehler)
  t('Demo: Land als Text, Hinweis auf den Beispielhaushalt', q('#setup-country')?.tagName === 'P'
    && !q('select#setup-country') && q('#setup-country')?.dataset.country === 'DE' && !!q('#setup-country-demo')?.textContent);
  change(q('#setup-lang'), 'en');
  await tick(80);
  t('Demo: Sprache gilt sofort im Assistenten', q('.setup__question')?.textContent === 'Language and country' && i18n.getLocale() === 'en');
  // v3.5.1 — nach der Wahl bleibt der Fokus an der gewählten Karte, nicht an der ersten
  // (eine Karte weiter hinten wählen, sonst fielen beide zusammen)
  next(); q('input[name="setup-persona"][value="eigenheim-modern"]').click(); await tick();
  t('Demo: Fokus bleibt an der gewählten Karte', document.activeElement === q('input[name="setup-persona"][value="eigenheim-modern"]')
    && q('.setup-choice--on input')?.value === 'eigenheim-modern'
    && document.activeElement !== q('input[name="setup-persona"]'));
  q('input[name="setup-persona"][value="mieterin"]').click(); await tick();
  next(); q('input[name="setup-level"][value="beginner"]')?.click(); await tick();
  next(); await tick();
  calls.length = 0;
  next(); await tick(80);
  t('Demo: Sprache je Gerät gemerkt (et-demo-lang)', localStorage.getItem('et-demo-lang') === 'en', localStorage.getItem('et-demo-lang'));
  t('Demo: Haushalt und Stufe im Browser', localStorage.getItem('et-demo-persona') === 'mieterin' && localStorage.getItem('et-demo-level') === 'beginner');
  t('Demo: kein Schreibzugriff an einen Server', writes().length === 0 && !calls.some(c => /settings\/country/.test(c.path)), JSON.stringify(writes()));
  console.log(`DEMO ${pass} ${fail}`);
  process.exit(fail ? 1 : 0);
}

// ── Schrittfolge ────────────────────────────────────────────────────────
console.log('Schritte');
t('Installation: Sprache/Land → Wer → Energieträger → Stufe → Weiter', W.setupSteps(false).join() === 'region,persona,utilities,level,finish');
t('Demo: Sprache/Land → Wer → Stufe → Weiter', W.setupSteps(true).join() === 'region,persona,level,finish');

// ── Regel aus N1014: Region nur bei gesprochener Sprache ────────────────
console.log('Schreibweise (lib/format.js regionLocale)');
const { regionLocale } = await import(`${JS}/lib/format.js`);
t('Englisch + Deutschland bleibt en-GB', regionLocale('en', 'DE', ['de']) === 'en-GB');
t('Deutsch in der Schweiz / Österreich: de-CH / de-AT', regionLocale('de', 'CH', ['de', 'fr', 'it']) === 'de-CH' && regionLocale('de', 'AT', ['de']) === 'de-AT');
t('Italienisch in Deutschland bleibt it-IT, Französisch in der Schweiz fr-CH',
  regionLocale('it', 'DE', ['de']) === 'it-IT' && regionLocale('fr', 'CH', ['de', 'fr', 'it']) === 'fr-CH');

// ── Schritt 1 ───────────────────────────────────────────────────────────
console.log('Schritt 1: Sprache und Land');
await open({ firstRun: true });
t('Schritt 1 von 5 fragt Sprache und Land', q('.setup__question')?.textContent === 'Sprache und Land'
  && /1 von 5/.test(q('[data-role="progress"]')?.textContent || ''));
t('Vorauswahl: Sprache dieses Geräts, Land der Installation', q('#setup-lang')?.value === 'de' && q('#setup-country')?.value === 'DE');
t('Länderliste nach gescheitertem erstem Abruf nachgeladen (alle Länder wählbar)',
  q('#setup-country')?.options.length === COUNTRIES.length && calls.filter(c => c.path === '/api/countries').length === 2,
  `${q('#setup-country')?.options.length} Länder, ${calls.filter(c => c.path === '/api/countries').length} Abrufe`);
t('alle Oberflächensprachen zur Wahl (languages.json)', q('#setup-lang')?.options.length === Object.keys(i18n.getLanguages()).length);
t('Fokus im ersten Feld', document.activeElement === q('#setup-lang'));
t('unverändert: „Eingestellt für Deutschland“ mit den eigenen Werten', /Eingestellt für Deutschland/.test(q('.setup-profile__title')?.textContent || '')
  && fact('location') === 'Leipzig Zentrum' && /Umweltbundesamt 2023–2025/.test(fact('co2_strom')));
t('Schreibweise Deutsch/Deutschland', /1\.234,5/.test(fact('format')) && /31\.12\.2026/.test(fact('format')), fact('format'));
q('#setup-country').focus();
change(q('#setup-country'), 'AT');
await tick();
t('Land gewechselt: was das Profil setzt (Wien, Zeitzone, 103 g/kWh)', /Länderprofil Österreich setzt/.test(q('.setup-profile__title')?.textContent || '')
  && fact('location') === 'Wien' && fact('timezone') === 'Europe/Vienna' && /103 g\/kWh/.test(fact('co2_strom')), fact('co2_strom'));
t('… Fokus bleibt am Land', document.activeElement === q('#setup-country'));
t('… Währung als Platzhalter: „nicht umgerechnet“', /nicht umgerechnet/.test(q('.setup-profile')?.textContent || ''));
change(q('#setup-lang'), 'en');
await tick(80);
t('Sprachwechsel: Assistent sofort englisch (Frage, Titel, Knöpfe)', q('.setup__question')?.textContent === 'Language and country'
  && q('.modal__title')?.textContent === 'Set up' && q('[data-act="next"]')?.textContent === 'Next' && q('[data-act="skip"]')?.textContent === 'Skip',
  `${q('.modal__title')?.textContent} · ${q('[data-act="next"]')?.textContent}`);
t('… Fokus bleibt an der Sprache', document.activeElement === q('#setup-lang'));
t('… gewähltes Land bleibt', q('#setup-country')?.value === 'AT');
t('… Bezeichnungen der Verbrauchsarten neu vom Server (X-ET-Language: en)', calls.some(c => c.path === '/api/utilities' && c.lang === 'en'));
change(q('#setup-country'), 'DE');
await tick();
t('Englisch + Deutschland schreibt wie en-GB (1,234.5 · 31/12/2026)', /1,234\.5/.test(fact('format')) && /31\/12\/2026/.test(fact('format')) && !/1\.234,5/.test(fact('format')), fact('format'));
calls.length = 0;
q('[data-act="skip"]').click();
await tick(80);
t('Überspringen nimmt die Sprache zurück', i18n.getLocale() === 'de');
t('… und schreibt weder Land noch Sprache', !writes().some(c => c.path === '/api/settings/country' || 'language' in (c.body || {})), JSON.stringify(writes()));

// ── Speichern: nur, was sich geändert hat ───────────────────────────────
// ── v3.9.0 (Paket oberflaeche) — Bilder je Schritt und je Haushalt ────────
console.log('Bilder (v3.9.0)');
{
  settings = { ...DEFAULTS };
  await open({ firstRun: true });
  const art = () => q('.setup__head .setup__art svg.ill');
  const seen = [];
  for (let i = 0; i < 5 && modal(); i++) {
    const svg = art();
    seen.push(svg && svg.getAttribute('aria-hidden') === 'true' && svg.getAttribute('focusable') === 'false'
      && !svg.hasAttribute('role') && !!q('.setup__head .setup__lead .setup__question'));
    if (i === 1) {
      const arts = [...modal().querySelectorAll('.setup-choice .setup-choice__art svg.ill')];
      t('Haushalte: jede Karte mit Zeichnung statt Emoji, als Zier', arts.length === W.PERSONAS.length
        && arts.every(s => s.getAttribute('aria-hidden') === 'true') && !modal().querySelector('.setup-choice .setup-choice__icon'),
        `${arts.length} von ${W.PERSONAS.length}`);
      q('input[name="setup-persona"][value="eigenheim-modern"]').click(); await tick();
    }
    if (i < 4) { next(); await tick(); }
  }
  t('jeder der fünf Schritte: Zeichnung neben der Frage, aria-hidden (die Frage sagt alles)', seen.length === 5 && seen.every(Boolean), JSON.stringify(seen));
  t('Einleitung steht im ersten Schritt neben dem Bild', W.withArt('region', '<h3 class="setup__question">F</h3><p>x</p>', '<p class="muted">E</p>')
    .startsWith('<div class="setup__head"><div class="setup__art"><svg class="ill'));
  t('Schritt ohne Zeichnung bleibt unverändert', W.withArt('unbekannt', '<h3 class="setup__question">F</h3>', '<p>E</p>') === '<p>E</p><h3 class="setup__question">F</h3>');
  q('[data-act="skip"]')?.click(); await tick(60);
}

console.log('Speichern');
settings = { ...DEFAULTS };
location.hash = '#/settings';
await tick();
await open({ firstRun: true });
const walk = async () => {
  next(); await tick();
  q('input[name="setup-persona"][value="eigenheim-klassisch"]').click(); await tick();
  next(); await tick();   // Energieträger
  next(); await tick();   // Stufe (vom Haushalt vorgeschlagen)
  next(); await tick();   // So geht es weiter
  q('input[name="setup-start"][value="own"]')?.click(); await tick();
};
await walk();
calls.length = 0;
next(); await tick(120);
const patch = writes().find(c => c.path === '/api/settings' && 'setup_pending' in (c.body || {}));
t('unverändert übernommen: kein Landwechsel, keine Sprache im PATCH', !writes().some(c => c.path === '/api/settings/country') && patch && !('language' in patch.body),
  JSON.stringify(writes().map(c => c.path)));
t('Einstellungen des Haushalts wie bisher', patch?.body?.setup_pending === false && patch?.body?.setup_persona === 'eigenheim-klassisch');
// v3.6.0 — die Einrichtung schlägt den Hinweis auf neue Versionen vor (sichtbar, abwählbar)
t('Hinweis auf neue Versionen: bei der Einrichtung vorgeschlagen und gespeichert', patch?.body?.update_check === true, JSON.stringify(patch?.body));
t('nach „Los geht’s“ bleibt der Sprung zu „Zählerstände“ stehen (kein history.back)', location.hash === '#/zaehlerstaende', location.hash);

// v3.9.0 (F1032) — vierter Weg: „Messkonzept einrichten“ (mit Strom), ohne neuen Schritt
settings = { ...DEFAULTS };
location.hash = '#/settings';
await tick();
await open({ firstRun: true });
await walk();
const conceptChoice = q('input[name="setup-start"][value="concept"]');
t('Weg „Messkonzept einrichten“ am Ende der Einrichtung (Haushalt mit Strom)', !!conceptChoice && W.setupSteps(false).length === 5);
conceptChoice?.click(); await tick();
next(); await tick(120);
t('… führt zu den Zählern „Strom“ und öffnet dort den Assistenten', location.hash === '#/utility/strom/meters?concept=1', location.hash);

settings = { ...DEFAULTS };
location.hash = '#/settings';
await tick();
await open({ firstRun: true });
change(q('#setup-lang'), 'en');
await tick(80);
change(q('#setup-country'), 'AT');
await tick();
await walk();
calls.length = 0;
next(); await tick(120);
const country = writes().filter(c => c.path === '/api/settings/country');
t('Land gewechselt: einmal POST /api/settings/country mit Profil', country.length === 1 && country[0].body.country === 'AT' && country[0].body.profile === true,
  JSON.stringify(country.map(c => c.body)));
t('Sprache gewechselt: Standardsprache der Installation', writes().some(c => c.path === '/api/settings' && c.body?.language === 'en'));
t('Sprache bleibt nach „Los geht’s“', i18n.getLocale() === 'en');

// ── Erneut öffnen: aktuelle Werte ───────────────────────────────────────
console.log('Erneut aus den Einstellungen');
await open();
t('Schritt 1 zeigt die aktuellen Werte (en, Österreich)', q('#setup-lang')?.value === 'en' && q('#setup-country')?.value === 'AT'
  && /Current settings for Austria/.test(q('.setup-profile__title')?.textContent || ''));
q('.modal__close').click();
await tick(60);
t('× schließt ohne Änderung', i18n.getLocale() === 'en' && !document.querySelector('#modal-root .modal'));

// Gerät mit eigener Sprache: zieht beim Wechsel mit
i18n.setDeviceLanguage('en');
await open();
change(q('#setup-lang'), 'fr');
await tick(80);
await walk();
next(); await tick(120);
t('eigene Sprache des Geräts zieht mit', localStorage.getItem('et-language') === 'fr' && i18n.getLocale() === 'fr');
i18n.setDeviceLanguage(null);
await i18n.initI18n('de');

// ── Einstellungen: derselbe Weg (POST /api/settings/country) ───────────
console.log('Einstellungen → Allgemein → Land');
settings = { ...DEFAULTS, setup_pending: false };
const settingsView = await import(`${JS}/views/settings.js`);
const view = document.getElementById('view');
const pickCountry = async (code, act) => {
  calls.length = 0;
  await settingsView.render(view, ['general'], {});
  await tick(60);
  const sel = view.querySelector('#country-select');
  if (!sel) return null;
  change(sel, code);
  await tick(60);
  modal()?.querySelector(`[data-act="${act}"]`)?.click();
  await tick(120);
  return writes().filter(c => c.path === '/api/settings/country').map(c => c.body);
};
const all = await pickCountry('AT', 'all');
t('„Alle übernehmen“ → POST /api/settings/country mit Profil', JSON.stringify(all) === '[{"country":"AT","profile":true}]', JSON.stringify(all));
settings = { ...DEFAULTS, setup_pending: false };
const only = await pickCountry('GB', 'country');
t('„Nur Land ändern“ → ohne Profil', JSON.stringify(only) === '[{"country":"GB","profile":false}]', JSON.stringify(only));
t('kein PATCH mit Profilwerten mehr', !writes().some(c => c.path === '/api/settings' && ('country' in (c.body || {}) || 'timezone' in (c.body || {}))));
await i18n.initI18n('de');

// ── Doku: was sie über die Schritte sagt, muss stimmen ─────────────────
console.log('Doku');
const cat = (l) => JSON.parse(readFileSync(resolve(ROOT, 'public', 'locales', `${l}.json`), 'utf8'));
const NUM = { de: ['', '', '', 'drei', 'vier', 'fünf'], en: ['', '', '', 'three', 'four', 'five'] };
for (const [lang, file, step, demo] of [['de', 'docs/einstieg/einrichtung.md', 'Schritt 1 von', 'mit {n} Schritten'],
  ['en', 'docs/en/einstieg/einrichtung.md', 'Step 1 of', 'opens with {n} steps']]) {
  const d = readFileSync(resolve(ROOT, file), 'utf8');
  const nInst = W.setupSteps(false).length, nDemo = W.setupSteps(true).length;
  t(`${lang}: Schrittzahl der Installation (${nInst}) und der Demo (${nDemo}) wie im Code`,
    d.includes(`${step} ${nInst}`) && d.includes(demo.replace('{n}', NUM[lang][nDemo])));
  t(`${lang}: Schritt 1 heißt wie im Katalog`, d.includes(cat(lang).setup.region.title));
}

// ── Demo (eigener Prozess: DEMO wird beim Laden des Moduls bestimmt) ────
for (const [title, flag] of [['Demo', '--demo'], ['Start der App', '--boot']]) {
  console.log(title);
  const child = spawnSync(process.execPath, [__file, flag], { encoding: 'utf8', env: process.env });
  const out = child.stdout || '';
  process.stdout.write(out.split('\n').filter(l => !l.startsWith('DEMO ')).join('\n'));
  const m = out.match(/DEMO (\d+) (\d+)/);
  if (m) { pass += Number(m[1]); fail += Number(m[2]); } else { t(`${title}: Lauf`, false, (child.stderr || '').slice(0, 400)); }
}

console.log(`\nsetup-wizard: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
