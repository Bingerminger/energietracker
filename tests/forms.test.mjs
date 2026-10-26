// =====================================================================
// v3.4.0 (N1021) — Aufgeräumte Oberfläche ohne Server:
//   - ein Formularraster (.fgrid, Feldgruppen, Zeilenlisten) im CSS,
//   - keine Einzel-Flicken mehr in den Ansichten (Versalien, flex-end),
//   - `[hidden]` gilt immer,
//   - Darstellung: Symbol zeigt die wirksame Darstellung, „A" bei Automatik,
//     kurze Meldung beim Umschalten,
//   - Uhrzeiten der Zeitfenster als HH:MM,
//   - was die Doku über beides behauptet.
//
// Aufruf: node tests/forms.test.mjs
// =====================================================================
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readFileSync, readdirSync } from 'node:fs';

const __dir = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');
const ROOT = resolve(__dir, '..');
const JS = resolve(ROOT, 'public', 'js');
const read = (p) => readFileSync(resolve(ROOT, p), 'utf8');

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
// CSS ohne Kommentare, Leerraum vereinheitlicht
const css = (p) => read(p).replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ');
const C = css('public/css/components.css');
const allCss = readdirSync(resolve(ROOT, 'public/css')).filter(f => f.endsWith('.css')).map(f => [f, css('public/css/' + f)]);
/** Deklarationsblock einer Regel (genauer Selektor) — oder ''. */
const rule = (src, selector) => {
  const esc = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/ /g, '\\s*');
  return (src.match(new RegExp(`(?:^|[}\\s])${esc}\\s*\\{([^}]*)\\}`)) || [])[1] || '';
};

// ── CSS: ein Raster ───────────────────────────────────────────────────
console.log('Formularraster (components.css)');
t('[hidden] gilt immer (display:none !important)', /\[hidden\] \{ display: none !important; \}/.test(C));
const flicken = allCss.flatMap(([f, s]) => (s.match(/[.#\w-]+\[hidden\]\s*\{/g) || []).map(m => `${f}: ${m}`));
t('keine Einzelregeln .x[hidden] mehr', flicken.length === 0, flicken.join(', '));
t('kein zweites System .form-grid', !allCss.some(([, s]) => /\.form-grid\b/.test(s)));
t('Steuerhöhe 36 px, bei Touch 44 px', /:root \{ --control-h: 36px; \}/.test(C)
  // v3.5.0 (N1023) — Touch-Größen bis zur Navigationsschwelle (nav-threshold.test.mjs)
  && /@media \(pointer: coarse\), \(max-width: 899\.98px\) \{ :root \{ --control-h: 44px; \} \}/.test(C)
  && /min-height: var\(--control-h\)/.test(rule(C, '.input, .select, textarea.input')));
t('.fgrid: sechs Spalten, eigene Breite als Maßstab', /grid-template-columns: repeat\(6, minmax\(0, 1fr\)\)/.test(rule(C, '.fgrid'))
  && /container-type: inline-size/.test(rule(C, '.fgrid')));
t('Breiten nach Inhalt: xs 1, s/m 2, Standard 3, l 4, full ganze Zeile',
  /span 1/.test(rule(C, '.fgrid > .field--xs')) && /span 2/.test(rule(C, '.fgrid > .field--s, .fgrid > .field--m'))
  && /span 3/.test(rule(C, '.fgrid > .field')) && /span 4/.test(rule(C, '.fgrid > .field--l')) && /1 \/ -1/.test(rule(C, '.fgrid > .field--full')));
t('schmal zwei Spalten (Container-Abfrage und Fenster-Rückfall)',
  /@container \(max-width: 34rem\) \{ \.fgrid > \.field, \.fgrid > \.field--m, \.fgrid > \.field--l \{ grid-column: 1 \/ -1; \} \.fgrid > \.field--s, \.fgrid > \.field--xs \{ grid-column: auto \/ span 3; \}/.test(C)
  && /@media \(max-width: 600px\) \{[^@]*\.fgrid > \.field--s, \.fgrid > \.field--xs \{ grid-column: auto \/ span 3; \}/.test(C));
t('Beschriftung, Feld und Hinweis einer Zeile auf gleicher Höhe (Unterraster)',
  /@supports \(grid-template-rows: subgrid\) \{ \.fgrid > \.field \{[^}]*grid-template-rows: subgrid;[^}]*grid-row: span 4;/.test(C));
t('Knopf/Kästchen ohne Beschriftung auf Höhe der Felder (statt flex-end)',
  /grid-row: 1 \/ span 2; align-self: end;/.test(rule(C, '.fgrid > .field > :first-child:is(.check, .btn, .field__actions)')));
t('Feldgruppe: Legende im Kasten (float), Knopf im Kopf ohne Umbruch',
  /float: left/.test(rule(C, '.fgroup > legend')) && /white-space: nowrap/.test(rule(C, '.fgroup > .fgroup__add'))
  && /white-space: nowrap/.test(rule(C, '.entry-group__head > .btn')));
t('Felder in Gruppen mit eigener Fläche', /background: var\(--bg-1\)/.test(rule(C, '.fgroup :is(.input, .select)'))
  && /background: var\(--bg-2\)/.test(rule(C, 'fieldset.fgroup, .fgroup')));
t('Zeilenliste: Datum 10,5 rem | Betrag 9 rem | Zusatz | ✕',
  /var\(--row-cols, 10\.5rem 9rem minmax\(0, 1fr\) auto\)/.test(rule(C, '.rowlist__row')) && /grid-column: -2 \/ -1/.test(rule(C, '.rowlist__row > .rowlist__del')));
t('Zeilenliste: Beschriftung nur über der ersten Zeile (sonst sr-only)',
  /clip: rect\(0, 0, 0, 0\)/.test(rule(C, '.rowlist__row + .rowlist__row > .field:not(.rowlist__sub) > :is(label, .label)')));
// Versalien nur für Feldbeschriftungen — nie für Kästchen-Texte
const upper = rule(C, '.field > label:not(.btn, .check, .settings-field__check), .label');
t('Versalien nur für Feldbeschriftungen, Kästchen in Satzschreibung',
  /text-transform: uppercase/.test(upper) && /text-transform: none/.test(rule(C, '.check, .settings-field__check'))
  && !/\.field label\b/.test(C));
t('Einstellungen: Karten oben bündig, Zahlen in breiten Karten höchstens 12 rem',
  /align-items: start/.test(rule(C, '.settings-grid')) && /max-width: 12rem/.test(rule(C, '.settings-card--wide .settings-field > .input:not(.input--text)')));

// ── Ansichten: keine Einzel-Flicken ───────────────────────────────────
console.log('Ansichten');
const views = ['views', 'components'].flatMap(d => readdirSync(resolve(JS, d)).filter(f => f.endsWith('.js')).map(f => [`${d}/${f}`, readFileSync(resolve(JS, d, f), 'utf8')]));
const hits = (re) => views.flatMap(([f, s]) => s.split('\n').map((l, i) => re.test(l) ? `${f}:${i + 1}` : null).filter(Boolean));
const tt = hits(/text-transform:\s*none/);
t('keine Versalien-Flicken (text-transform:none inline)', tt.length === 0, tt.join(', '));
const fe = hits(/display:\s*flex;\s*align-items:\s*flex-end/);
t('kein schwebendes ✕ (display:flex;align-items:flex-end)', fe.length === 0, fe.join(', '));
const fg = hits(/class="form-grid"/);
t('kein .form-grid mehr', fg.length === 0, fg.join(', '));
// Die Dialoge, die N1021 umgebaut hat, nutzen das Raster und keine alte Zeile
for (const [f, needle] of [['views/contracts.js', 'id="contract-form"'], ['views/meters.js', 'id="meter-form"'], ['views/tenancy.js', 'id="ten-form"'],
  ['views/reminders.js', 'reminders.form.fTitle'], ['views/tariff.js', 'tariff.shadow.label']]) {
  const src = views.find(([n]) => n === f)?.[1] || '';
  t(`${f}: Formularraster`, src.includes(needle) && /class="fgrid"/.test(src));
}
const flexEndRow = hits(/class="form-row" style="align-items:flex-end/).filter(h => !h.startsWith('views/report.js'));
t('keine form-row mit flex-end in den umgebauten Ansichten', flexEndRow.length === 0, flexEndRow.join(', '));

// ── Uhrzeit HH:MM ─────────────────────────────────────────────────────
console.log('Uhrzeit');
const { normalizeTime } = await import(`${JS}/lib/format.js`);
const cases = [['6', '06:00'], ['06:00', '06:00'], ['1530', '15:30'], ['6,30', '06:30'], ['6.30', '06:30'], ['24', '24:00'], ['24:00', '24:00'],
  ['', ''], ['25', '25'], ['24:30', '24:30'], ['7:5', '7:5'], ['abc', 'abc']];
const bad = cases.filter(([i, o]) => normalizeTime(i) !== o).map(([i, o]) => `${i} → ${normalizeTime(i)} (erwartet ${o})`);
t('Uhrzeiten wie im Preisblatt werden HH:MM, Unlesbares bleibt stehen', bad.length === 0, bad.join('; '));

// ── Darstellung ───────────────────────────────────────────────────────
console.log('Darstellung');
const dom = new JSDOM(`<!DOCTYPE html><html data-theme="dark"><head><meta name="color-scheme" content="dark"></head><body>
  <button id="theme-toggle"><span class="topbar__btn-icon"></span></button><div id="toast-stack"></div></body></html>`, { url: 'http://127.0.0.1/', pretendToBeVisual: true });
for (const k of ['window', 'document', 'HTMLElement', 'Node', 'CustomEvent', 'Event', 'localStorage']) {
  try { global[k] = dom.window[k]; }
  catch { Object.defineProperty(global, k, { value: dom.window[k], configurable: true, writable: true }); }
}
try { Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true, writable: true }); } catch {}
let systemLight = false;
dom.window.matchMedia = () => ({ matches: systemLight, addEventListener() {}, removeEventListener() {} });
global.fetch = async (url) => {
  const m = String(url).match(/public\/locales\/([a-z]+)\.json$/);
  if (!m) return new Response('{}', { status: 404 });
  return new Response(readFileSync(resolve(ROOT, 'public', 'locales', `${m[1]}.json`), 'utf8'), { status: 200 });
};
const { initI18n } = await import(`${JS}/lib/i18n.js`);
await initI18n('de');
const { mountThemeToggle, refreshThemeToggle } = await import(`${JS}/lib/theme.js`);
const btn = document.getElementById('theme-toggle');
const state = () => {
  const badge = btn.querySelector('[data-theme-badge]');
  return { mode: btn.dataset.themeMode, icon: btn.querySelector('.topbar__btn-icon').textContent,
    badge: badge && !badge.hidden ? badge.textContent : null, theme: document.documentElement.getAttribute('data-theme'),
    toast: [...document.querySelectorAll('#toast-stack .toast__msg')].map(e => e.textContent).at(-1) || null,
    toasts: document.querySelectorAll('#toast-stack .toast').length, label: btn.getAttribute('aria-label') };
};
localStorage.removeItem('et-theme');
mountThemeToggle(btn);
refreshThemeToggle();
let s = state();
t('System dunkel: Mond mit Abzeichen „A", keine Meldung beim Start', s.mode === 'system' && s.icon === '🌙' && s.badge === 'A' && s.toast === null, JSON.stringify(s));
t('Beschriftung nennt den Modus wie bisher', s.label === 'Darstellung: wie das System – umschalten', s.label);
btn.click(); s = state();
t('Klick → hell: Sonne, kein Abzeichen, Meldung „Hell"', s.mode === 'light' && s.theme === 'light' && s.icon === '☀️' && s.badge === null && s.toast === 'Hell', JSON.stringify(s));
btn.click(); s = state();
t('Klick → dunkel: Mond, kein Abzeichen, Meldung „Dunkel"', s.mode === 'dark' && s.icon === '🌙' && s.badge === null && s.toast === 'Dunkel', JSON.stringify(s));
await new Promise(r => setTimeout(r, 300));
systemLight = true;
btn.click(); s = state();
t('Klick → wie das System (hell): Sonne mit „A", Meldung „Automatisch – folgt dem System"',
  s.mode === 'system' && s.theme === 'light' && s.icon === '☀️' && s.badge === 'A' && s.toast === 'Automatisch – folgt dem System', JSON.stringify(s));
t('schnelles Weiterklicken stapelt keine Meldungen', s.toasts <= 2, `${s.toasts} Meldungen`);

// ── Doku ──────────────────────────────────────────────────────────────
console.log('Doku');
const settingsSrc = read('public/js/views/settings.js');
const url = (settingsSrc.match(/const SUPPORT_URL = '([^']+)'/) || [])[1];
for (const [lang, ans, fun] of [['de', 'docs/referenz/ansichten.md', 'docs/einstieg/funktionen.md'], ['en', 'docs/en/referenz/ansichten.md', 'docs/en/einstieg/funktionen.md']]) {
  const a = read(ans), f = read(fun);
  t(`${lang}: Ansichten nennen Abzeichen „A" und die wirksame Darstellung`, /\*\*[„“"]A[“"”]\*\*/.test(a) && a.includes('☀️') && a.includes('🌙'));
  t(`${lang}: Funktionen nennen den Kreislauf mit „A"`, /[„“"]A[“"”]/.test(f));
  t(`${lang}: Unterstützen-Link wie in der App`, !!url && a.includes(url.replace(/^https:\/\//, '')));
}

// ── v3.6.0: Umschalter Kalender/Abrechnung mit Abstand ────────────────
t('Umschalter Kalenderjahr · Abrechnungsjahr: eigene Zeile mit Abstand', /margin-bottom: var\(--sp-3\)/.test(rule(C, '.period-mode-bar'))
  && /display: flex/.test(rule(C, '.period-mode-bar')));

// ── v3.5.1: Safari am iPad ────────────────────────────────────────────
console.log('v3.5.1 (iPad, Safari)');
const raw = read('public/css/components.css').replace(/\/\*[\s\S]*?\*\//g, '');
t('Auswahlkarten: Hover nur mit Maus (@media (hover: hover))', /@media \(hover: hover\) \{\s*\.setup-choice:hover \{/.test(raw)
  && !/(^|\})\s*\.setup-choice:hover \{/.test(raw.replace(/@media \(hover: hover\) \{\s*\.setup-choice:hover \{[^}]*\}\s*\}/, '')));
t('Auswahlkarten: Fokusrahmen nur bei Tastatur (:focus-visible)', /\.setup-choice:has\(input:focus-visible\) \{/.test(C) && !/\.setup-choice:focus-within/.test(C));
const dateRule = rule(C, '.input[type="date"], .input[type="month"], .input[type="time"], .input[type="datetime-local"]');
t('Datumsfelder folgen dem Raster (appearance: none, min-width: 0)', /appearance: none/.test(dateRule) && /min-width: 0/.test(dateRule));
t('Hinweise unter Zugriff in einer Größe', /font-size: var\(--fs-sm\)/.test(rule(C, '.security-check__item'))
  && /class="settings-card__hint">\$\{t\('settings\.security\.hint'\)\}/.test(settingsSrc)
  && /class="settings-card__hint">\$\{escapeHtml\(t\('settings\.securityCheck\.hint'\)\)\}/.test(settingsSrc));
t('Passwortfelder im Formular (mit Benutzername für Passwortmanager)', /<form class="fgrid" data-role="sec-form"/.test(settingsSrc)
  && /autocomplete="username"/.test(settingsSrc) && !/<div class="fgrid">\s*\$\{pwField\(/.test(settingsSrc));
t('arbeitende Knöpfe zeigen ein Rad, still bei „Bewegung reduzieren“', /animation: et-spin/.test(rule(C, '.btn[aria-busy="true"]::after'))
  && /prefers-reduced-motion: reduce\) \{ \.btn\[aria-busy="true"\]::after \{ animation: none; \}/.test(C));
const flexSrc = read('public/js/views/flexibility.js');
t('Börsenpreise Monat für Monat mit Fortschritt', /syncSmardIntervals\(year, m\)/.test(flexSrc) && /<progress/.test(flexSrc) && /flex\.loadingPrices/.test(flexSrc));
const haAdd = 'https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2FBingerminger%2Fenergietracker-ha';
t('Home-Assistant-App: Knopf in README (DE/EN), Handbuch (DE/EN) und App',
  ['README.md', 'README.de.md', 'docs/anleitungen/home-assistant.md', 'docs/en/anleitungen/home-assistant.md'].every(f => read(f).includes(haAdd))
  && settingsSrc.includes(`const HA_APP_ADD_URL = '${haAdd}'`));

// ── v3.6.0 (Paket B): ruhige Formulare am iPad, Aufklappbereich ────────
console.log('v3.6.0 (Zugriff ruhig, Aufklappbereich)');
const sel = rule(C, '.select:not([multiple]):not([size])');
t('Auswahlfelder ohne Systemdarstellung (Höhe wie Eingaben), Pfeil aus dem Theme, Textschrift',
  /-webkit-appearance: none; appearance: none;/.test(sel) && /font-family: var\(--font-sans\)/.test(sel) && /padding-right: 32px/.test(sel) && /var\(--text-2\)/.test(sel));
t('Textfelder ohne Safaris Eigendarstellung (Kästchen, Datei, Regler ausgenommen)',
  /appearance: none/.test(rule(C, '.input:not([type="checkbox"], [type="radio"], [type="file"], [type="range"], [type="color"])')));
t('16 px auch bei iPad mit Trackpad (any-pointer: coarse)', /@media \(any-pointer: coarse\) \{ \.input, \.select, textarea\.input \{ font-size: 16px; \} \}/.test(C));
t('kein Vergrößern beim Drehen (text-size-adjust)', /html \{ -webkit-text-size-adjust: 100%; text-size-adjust: 100%; \}/.test(css('public/css/app.css')));
t('Einstellungen: Feldabstand wie im Raster (16 px), Hinweis ohne Extraabstand',
  /gap: var\(--sp-4\)/.test(rule(C, '.settings-fields')) && /margin-top: 0/.test(rule(C, '.settings-field__hint')));
for (const role of ['sec-form', 'me-form', 'user-form', 'pw-reset-form', 'sec-disable-form']) {
  t(`Passwortformular ${role} (Safari erkennt es, Enter schickt nichts ab)`, new RegExp(`<form[^>]*data-role="${role}"`).test(settingsSrc)
    && new RegExp(`form\\[data-role="${role}"\\][^\\n]*submit`).test(settingsSrc));
}
const fcSrc = read('public/js/views/forecast.js');
t('ein Aufklappbereich für Prognose und CO₂-Faktoren (components/disclosure.js)',
  /from '\.\.\/components\/disclosure\.js'/.test(fcSrc) && /from '\.\.\/components\/disclosure\.js'/.test(settingsSrc)
  && /disclosureHtml\(\{ id: 'fc-classic'/.test(fcSrc) && /gkey: 'co2'[^\n]*collapse: 'et-settings-co2-open'/.test(settingsSrc)
  && !/<details class="card fc-classic"/.test(fcSrc));

// ── v3.9.0 (Paket oberflaeche) — Touch-Größen und Dialog-Fußleiste ──────
console.log('Touch und Dialoge (v3.9.0)');
const touch = (C.match(/@media \(pointer: coarse\), \(max-width: 899\.98px\) \{ \.btn \{ min-height: 44px; \}([\s\S]*?)\}\s*\}/) || [])[1] || '';
t('Stufenwahl in der Kopfzeile bei Touch 44 px (wie die Knöpfe daneben)', /\.topbar__select \{ height: 44px; \}/.test(touch));
t('auch kleine Knöpfe bei Touch 44 px, ein reines Symbol 44 px breit', /\.btn--sm \{ min-height: 44px;/.test(touch)
  && /\.btn--sm\[aria-label\]:not\(:has\(\*\)\) \{ min-width: 44px;/.test(touch));
t('Text in der Dialog-Fußleiste mittig zu den Knöpfen', /\} \.modal__foot \{ padding: var\(--sp-4\) var\(--sp-5\);[^}]*align-items: center/.test(C));

console.log(`\n  forms: ${pass} bestanden, ${fail} fehlgeschlagen`);
process.exit(fail ? 1 : 0);
