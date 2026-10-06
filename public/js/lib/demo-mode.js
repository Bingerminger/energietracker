// =====================================================================
// v3.0.0 — Die öffentliche Demo (GitHub Pages) hat kein PHP.
//
// tools/build-demo.mjs startet beim Bauen den echten Server mit den
// Demo-Daten (bis zum Bautag fortgeschrieben), ruft jede Leseanfrage der
// Oberfläche ab und legt die Antworten je Sprache unter `demo-api/` ab. Im
// Browser beantwortet dieses Modul die Anfragen von api.js daraus:
//
//   • GET → gespeicherte Antwort (Index je Sprache, Rückfall Deutsch)
//   • alles andere → Fehler „In der Demo wird nichts gespeichert“
//   • GET ohne gespeicherte Antwort (eigene Was-wäre-wenn-Werte, frei
//     gewählter Prüfzeitraum) → Fehler „nicht vorberechnet“
//
// Eine normale Installation führt diesen Code nie aus: Er greift nur, wenn
// die Seite `data-demo` trägt (das setzt index.php allein mit ET_DEMO_BUILD).
// =====================================================================

import { demoKey, demoFileName } from './demo-key.js';
import { t } from './i18n.js';

export const DEMO = typeof document !== 'undefined'
  && !!document.documentElement?.hasAttribute?.('data-demo');

const ROOT = 'demo-api';
const LANG_KEY = 'et-demo-lang';
const REPO = 'https://github.com/Bingerminger/energietracker';

/** Schlüssel ohne gespeicherte Antwort — für den Abdeckungstest. */
export const demoMisses = new Set();
if (DEMO && typeof window !== 'undefined') window.__etDemoMisses = demoMisses;

const indexes = new Map();
let metaPromise = null;

function loadJson(url) {
  return fetch(url).then(r => {
    if (!r.ok) throw new Error(`${url}: HTTP ${r.status}`);
    return r.json();
  });
}

function loadIndex(lang) {
  if (!indexes.has(lang)) {
    indexes.set(lang, loadJson(`${ROOT}/index-${lang}.json`).catch(() => ({})));
  }
  return indexes.get(lang);
}

/** Bautag und Version der Demo: `{ built_at, today, version, languages }`. */
export function demoMeta() {
  metaPromise ??= loadJson(`${ROOT}/meta.json`).catch(() => ({}));
  return metaPromise;
}

function demoError(code, status = 0) {
  const err = new Error(t(code));
  err.status = status;
  err.code = code;
  return err;
}

/**
 * Antwort auf eine Anfrage von api.js. Gibt wie dort `data` zurück oder wirft.
 * @param {string} method
 * @param {string} path  `/api/…` samt Query
 * @param {string} lang  aktive Sprache
 */
export async function demoRequest(method, path, lang) {
  if (method !== 'GET') throw demoError('demo.readOnly', 403);
  const key = demoKey(path);
  const file = (await loadIndex(lang))[key] ?? (await loadIndex('de'))[key];
  if (!file) {
    demoMisses.add(key);
    throw demoError('demo.unavailable', 404);
  }
  const payload = await loadJson(`${ROOT}/r/${file}`);
  if (payload?.success === false) {
    // gespeicherte Fehlerantwort des Servers: wie im Echtbetrieb weiterreichen
    const err = new Error(payload.error || 'HTTP ' + (payload.status ?? 400));
    err.status = payload.status ?? 400;
    err.code = payload.code;
    err.detail = payload.detail;
    throw err;
  }
  return payload?.data;
}

/** Datei-Downloads (CSV, PDF) als fertige Datei je Sprache, s. demoFileName(). */
export function demoFileUrl(path, lang) {
  return `${ROOT}/files/${lang}/${demoFileName(path)}`;
}

/** Sprache der Demo: `?lang=` vor gespeicherter Wahl vor Browser. */
export function demoLanguage() {
  let lang = null;
  try { lang = new URLSearchParams(location.search).get('lang'); } catch { /* ohne location */ }
  try {
    if (lang) localStorage.setItem(LANG_KEY, lang);
    else lang = localStorage.getItem(LANG_KEY);
  } catch { /* Speicher gesperrt: nur diese Sitzung */ }
  return lang || (navigator.languages?.[0] ?? navigator.language ?? 'de');
}

/**
 * Kennzeichen in der Kopfleiste und Hinweisleiste mit Sprachwahl und dem Weg
 * zur eigenen Installation. Die Leiste lässt sich schließen; das Kennzeichen
 * öffnet sie wieder.
 * @param {{ languages: Array<{code:string,label:string}>, locale: string, formatDate: (d:string)=>string }} opts
 */
export async function mountDemoUi({ languages, locale, formatDate }) {
  if (!DEMO || document.getElementById('demo-bar')) return;
  const meta = await demoMeta();
  const install = locale === 'de' ? `${REPO}/blob/main/README.de.md#schnellstart` : `${REPO}#quick-start`;

  const bar = document.createElement('div');
  bar.id = 'demo-bar';
  bar.className = 'demo-bar';
  bar.setAttribute('role', 'note');
  bar.setAttribute('aria-label', t('demo.chip'));
  const text = document.createElement('p');
  text.className = 'demo-bar__text';
  text.textContent = t('demo.text', { date: meta.today ? formatDate(meta.today) : '' });
  const select = document.createElement('select');
  select.className = 'demo-bar__lang';
  select.setAttribute('aria-label', t('demo.language'));
  for (const l of languages) {
    const o = document.createElement('option');
    o.value = l.code;
    o.textContent = l.label;
    if (l.code === locale) o.selected = true;
    select.append(o);
  }
  select.addEventListener('change', () => {
    const url = new URL(location.href);
    url.searchParams.set('lang', select.value);
    location.href = url.toString();
  });
  const link = document.createElement('a');
  link.className = 'btn btn--primary btn--sm';
  link.href = install;
  link.target = '_blank';
  link.rel = 'noopener';
  link.textContent = t('demo.install');
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'demo-bar__close';
  close.setAttribute('aria-label', t('demo.close'));
  close.textContent = '×';
  bar.append(text, select, link, close);
  document.body.append(bar);

  const chip = document.createElement('button');
  chip.type = 'button';
  chip.className = 'demo-chip';
  chip.textContent = t('demo.chip');
  chip.setAttribute('aria-expanded', 'true');
  chip.setAttribute('aria-controls', 'demo-bar');
  document.querySelector('.topbar__brand')?.append(chip);

  const show = (on) => { bar.hidden = !on; chip.setAttribute('aria-expanded', String(on)); };
  close.addEventListener('click', () => show(false));
  chip.addEventListener('click', () => show(bar.hidden));
}
