// =====================================================================
// v3.12.0 (Sichtung S10) — wer wem welche Sprache einstellt. Der Text sagt, was der Code tut.
//
// Reihenfolge (lib/i18n.js resolveLanguage, app.js beim Start): die Wahl der angemeldeten Person,
// dann die Wahl auf diesem Gerät, dann die Vorgabe der Installation. In sieben Sprachen.
// Aufruf: node tests/sprache-wahl.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const JS = resolve(ROOT, 'public', 'js');
let pass = 0, fail = 0;
const t = (name, ok, info = '') => { if (ok) { pass++; return; } fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); };

const dom = new JSDOM('<!DOCTYPE html><html><head></head><body></body></html>', { url: 'http://127.0.0.1/' });
for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; } catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
global.fetch = async (url) => {
  const u = String(url);
  const m = u.match(/public\/locales\/([a-z]+)\.json$/);
  if (m) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
  if (/languages\.json/.test(u)) return new Response(readFileSync(resolve(ROOT, 'public', 'locales', 'languages.json'), 'utf8'), { status: 200 });
  return new Response(JSON.stringify({ success: true, data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

const I = await import(`${JS}/lib/i18n.js`);
await I.initI18n('de');
const R = await import(`${JS}/views/settings-region.js`);

// ── Die Reihenfolge ──────────────────────────────────────────────────
const r = I.resolveLanguage;
t('Person vor Gerät vor Vorgabe', r({ person: 'fr', device: 'en', installation: 'de' }).source === 'person' && r({ person: 'fr', device: 'en', installation: 'de' }).lang === 'fr');
t('Gerät vor Vorgabe', r({ device: 'en', installation: 'de' }).source === 'device' && r({ device: 'en', installation: 'de' }).lang === 'en');
t('Nichts gewählt: Vorgabe', r({ installation: 'de' }).source === 'installation' && r({ person: null, device: null, installation: 'it' }).lang === 'it');
const app = readFileSync(`${JS}/app.js`, 'utf8');
t('Der Start der App nimmt dieselbe Reihenfolge', /resolveLanguage\(\{ person: session\?\.user\?\.prefs\?\.language, device: deviceLanguage\(\), installation: s\?\.language \}\)/.test(app));
const settingsJs = readFileSync(`${JS}/views/settings.js`, 'utf8');
t('Vorgabe ändern fragt dieselbe Reihenfolge', /resolveLanguage\(\{ person: own, device: deviceLanguage\(\), installation: lang \}\)\.source === 'installation'/.test(settingsJs));

// ── Wer darf: Die Vorgabe für alle ändern nur Verwalter (am Server belegt in HouseholdAccessTest), die eigene Wahl bleibt frei ──
const boot = readFileSync(`${ROOT}/src/bootstrap.php`, 'utf8');
const admin = boot.match(/ADMIN_SETTINGS = \[([\s\S]*?)\];/)?.[1] || '';
t('ADMIN_SETTINGS gefunden', admin.includes('frame_ancestors'));
for (const k of ['language', 'country', 'currency', 'timezone']) t(`${k}: Verwalter-Einstellung`, admin.includes(`'${k}'`));
t('Land wechseln ist eine Verwalter-Route', /\/api\/settings\/country/.test(boot.slice(boot.indexOf('private function adminOnly'), boot.indexOf('ADMIN_SETTINGS = ['))));

// ── Die Karte in sieben Sprachen ─────────────────────────────────────
const LANGS = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];
const WHY = { person: 'settings.lang.why.person', device: 'settings.lang.why.device', installation: 'settings.lang.why.installation' };
const frag = (html) => { const d = document.createElement('div'); d.innerHTML = html; return d; };
const norm = (s) => s.replace(/\s+/g, ' ').trim();
for (const lang of LANGS) {
  await I.initI18n(lang);
  const names = I.getLanguages();
  const settings = { language: 'it', country: 'DE', currency: 'EUR', timezone: 'Europe/Berlin' };
  const person = (language) => ({ mode: 'password', role: 'member', user: { id: 'u1', name: 'Erika', prefs: { language } } });
  const cases = [
    { session: person('fr'), device: 'en', source: 'person', lang: 'fr', own: 'fr' },
    { session: person(null), device: 'en', source: 'device', lang: 'en', own: 'en' },
    { session: person(null), device: null, source: 'installation', lang: 'it', own: '' },
    { session: { mode: 'off' }, device: 'nl', source: 'device', lang: 'nl', own: 'nl' },
    { session: { mode: 'off' }, device: null, source: 'installation', lang: 'it', own: '' },
  ];
  for (const c of cases) {
    if (c.device) localStorage.setItem('et-language', c.device); else localStorage.removeItem('et-language');
    const d = frag(R.renderLanguageCard(settings, c.session, []));
    const box = d.querySelector('[data-role="lang-effective"]');
    t(`${lang}: Anzeige ${c.source}`, box?.dataset.source === c.source, box?.outerHTML);
    t(`${lang}: nennt die Sprache, die gilt (${c.lang})`, norm(box.querySelector('strong').textContent).includes(names[c.lang]), box.textContent);
    t(`${lang}: nennt den Grund`, norm(box.textContent).endsWith(norm(I.t(WHY[c.source]))), box.textContent);
    t(`${lang}: Auswahl zeigt die eigene Wahl`, d.querySelector('#lang-select').value === c.own, d.querySelector('#lang-select').value);
    const signedIn = c.session.mode !== 'off';
    t(`${lang}: Hinweis sagt, für wen die eigene Wahl gilt`, norm(d.querySelector('[data-role="lang-own-hint"]').textContent) === I.t(signedIn ? 'settings.lang.personHint' : 'settings.lang.deviceHint'));
    t(`${lang}: getrennt: für dich, für alle`, !!d.querySelector('[data-role="lang-for-you"]') && !!d.querySelector('[data-role="lang-for-all"]')
      && d.querySelector('[data-role="lang-for-you"]').compareDocumentPosition(d.querySelector('#lang-default-select')) & dom.window.Node.DOCUMENT_POSITION_FOLLOWING);
    // v3.12.0 (M3) — „Für dich“ zeigt dieselbe Wahl mit einer eigenen Kopie der Logik: dieselben Aussagen
    const o = frag(R.renderOwnLanguageCard(settings, c.session));
    const obox = o.querySelector('[data-role="lang-effective"]');
    t(`${lang}: Für dich: Anzeige ${c.source}`, obox?.dataset.source === c.source, obox?.outerHTML);
    t(`${lang}: Für dich: nennt die Sprache und den Grund`, norm(obox?.querySelector('strong')?.textContent || '').includes(names[c.lang])
      && norm(obox.textContent).endsWith(norm(I.t(WHY[c.source]))), obox?.textContent);
    t(`${lang}: Für dich: Auswahl zeigt die eigene Wahl`, o.querySelector('#lang-select')?.value === c.own, o.querySelector('#lang-select')?.value);
  }
  // Mitglied: Vorgabe lesbar, nicht bedienbar, die eigene Wahl bleibt bedienbar. Verwalter und ohne Anmeldung: alles bedienbar
  const VORGABE = ['#lang-default-select', '#country-select', '#currency-select', '#tz-select'];
  const member = frag(R.renderLanguageCard(settings, person(null), [{ code: 'DE', currency: 'EUR' }]));
  t(`${lang}: Mitglied: Vorgabe gesperrt`, VORGABE.every(s => member.querySelector(s)?.disabled === true));
  t(`${lang}: Mitglied: Werte lesbar`, member.querySelector('#lang-default-select').value === 'it' && member.querySelector('#currency-select').value === 'EUR' && member.querySelector('#tz-select').value === 'Europe/Berlin');
  t(`${lang}: Mitglied: eigene Sprache bedienbar`, member.querySelector('#lang-select').disabled === false);
  t(`${lang}: Mitglied: Hinweis, wer ändert`, norm(member.querySelector('[data-role="region-admin-only"]')?.textContent || '') === norm(I.t('settings.lang.forAllHint')));
  for (const who of [{ mode: 'off' }, { ...person(null), role: 'admin' }]) {
    const a = frag(R.renderLanguageCard(settings, who, [{ code: 'DE', currency: 'EUR' }]));
    t(`${lang}: ${who.role || 'ohne Anmeldung'}: Vorgabe bedienbar`, VORGABE.every(s => a.querySelector(s)?.disabled === false) && !a.querySelector('[data-role="region-admin-only"]'));
  }
  if (lang === 'de') t('Der Satz sagt, wer ändern darf', /nur Verwalter/.test(I.t('settings.lang.forAllHint')) && /ohne Anmeldung/.test(I.t('settings.lang.forAllHint')), I.t('settings.lang.forAllHint'));
  // Die Sätze sind da und keine Schlüssel
  for (const k of ['settings.lang.effective', 'settings.lang.why.person', 'settings.lang.why.device', 'settings.lang.why.installation', 'settings.lang.forYou', 'settings.lang.forAll', 'settings.lang.forAllHint', 'settings.lang.label', 'settings.lang.personHint', 'settings.lang.deviceHint', 'settings.lang.defaultHint', 'settings.region.countryHint', 'settings.region.adminOnly']) {
    t(`${lang}: ${k}`, I.t(k) !== k && I.t(k).length > 2);
  }
}
localStorage.removeItem('et-language');

// ── v3.13.0 (Paket K2e) — Erster Start: Die Oberfläche schickt die Sprache des Browsers, nicht die eigene Vorgabe ──
// Bis v3.12 trugen die ersten Anfragen `Accept-Language: de` und `X-ET-Language: de` (DEFAULT_LOCALE, solange kein
// Katalog geladen war): Ein französischer Browser bekam beim allerersten Start Deutsch, Deutschland und den deutschen
// Wetterort. Der Server entscheidet aus `Accept-Language` (firstStartChoice); die Region („fr-CH“) bestimmt das Land.
{
  const setLangs = (list) => Object.defineProperty(dom.window.navigator, 'languages', { value: list, configurable: true });
  const fresh = async (tag) => import(`${JS}/lib/i18n.js?${tag}`);   // eine eigene Instanz: vor dem Laden eines Katalogs
  setLangs(['fr-CH', 'fr', 'en']);
  let F = await fresh('erststart-fr');
  t('Erststart: Accept-Language aus navigator.languages, mit Gewichten', F.requestLanguages().accept === 'fr-CH, fr;q=0.9, en;q=0.8', JSON.stringify(F.requestLanguages()));
  t('Erststart: keine eigene Sprache (kein X-ET-Language)', F.requestLanguages().device === null);
  setLangs(['de-AT']);
  F = await fresh('erststart-at');
  t('Erststart de-AT', F.requestLanguages().accept === 'de-AT' && F.requestLanguages().device === null, JSON.stringify(F.requestLanguages()));
  setLangs(['en-GB', 'de-DE']);
  F = await fresh('erststart-gb');
  t('Erststart en-GB vor de-DE', F.requestLanguages().accept === 'en-GB, de-DE;q=0.9', JSON.stringify(F.requestLanguages()));
  setLangs(['fr-CH']);
  F = await fresh('geraet');
  F.presetLocale('it');
  t('Eigene Wahl des Geräts: diese Sprache in beiden Kopfzeilen', JSON.stringify(F.requestLanguages()) === JSON.stringify({ accept: 'it', device: 'it' }), JSON.stringify(F.requestLanguages()));
  F = await fresh('geladen');
  await F.initI18n('nl');
  t('Nach dem Laden der Sprache: diese in beiden Kopfzeilen', JSON.stringify(F.requestLanguages()) === JSON.stringify({ accept: 'nl', device: 'nl' }), JSON.stringify(F.requestLanguages()));
  setLangs([]);
  F = await fresh('nur-language');
  t('Ohne Liste: navigator.language', F.requestLanguages().accept === dom.window.navigator.language && !!dom.window.navigator.language, JSON.stringify(F.requestLanguages()));
  const navLang = dom.window.navigator.language;
  Object.defineProperty(dom.window.navigator, 'language', { value: '', configurable: true });
  F = await fresh('ohne');
  t('Ohne Angabe des Browsers: keine eigene Kopfzeile (der Browser schickt seine)', F.requestLanguages().accept === null && F.requestLanguages().device === null, JSON.stringify(F.requestLanguages()));
  Object.defineProperty(dom.window.navigator, 'language', { value: navLang, configurable: true });
  setLangs(['fr-CH', 'x;<script>', 'de']);
  F = await fresh('kaputt');
  t('Ungültige Einträge fallen weg', F.requestLanguages().accept === 'fr-CH, de;q=0.9', JSON.stringify(F.requestLanguages()));
  // api.js schickt genau diese Kopfzeilen
  const apiSrc = readFileSync(`${JS}/api.js`, 'utf8');
  t('api.js nimmt requestLanguages() für die Kopfzeilen', /requestLanguages\(\)/.test(apiSrc) && !/'Accept-Language': getLocale\(\)/.test(apiSrc));
}

console.log(`\n  sprache-wahl: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
