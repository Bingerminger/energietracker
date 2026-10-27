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
//   • v3.6.0 (F1031) — POST /api/forecast/scenario rechnet nur: Ausgangsfall
//     und Beispielszenarien je Zähler liegen in `scenarios.json` (je
//     Haushalt, sprachunabhängig); eigene Reglerwerte → „nur Beispiele“
//   • v3.9.0 (F1032) — POST /api/metering-concept/preview schreibt nichts: die
//     Vorschau des Assistenten „Messkonzept“ für den Aufbau des Haushalts liegt
//     in `concepts-<lang>.json` (je Haushalt und Sprache, die Namen sind
//     übersetzt); ein anderer Aufbau → „nur für den Aufbau des Haushalts“,
//     „Anlegen“ → „nichts gespeichert“
//
// Eine normale Installation führt diesen Code nie aus: Er greift nur, wenn
// die Seite `data-demo` trägt (das setzt index.php allein mit ET_DEMO_BUILD).
// =====================================================================

import { demoKey, demoFileName, demoBodyKey } from './demo-key.js';
import { t } from './i18n.js';

export const DEMO = typeof document !== 'undefined'
  && !!document.documentElement?.hasAttribute?.('data-demo');

const ROOT = 'demo-api';
const LANG_KEY = 'et-demo-lang';
// v3.2.0 (F1018) — gewählter Beispielhaushalt (Einrichtungsassistent)
const PERSONA_KEY = 'et-demo-persona';
const PERSONAS = ['mieterin', 'etw-fernwaerme', 'eigenheim-klassisch', 'eigenheim-modern', 'showcase'];

/**
 * Beispielhaushalt der Demo: `?persona=` vor gespeicherter Wahl, sonst
 * „showcase" (alle Arten). `strict`: null, solange keiner gewählt ist — dann
 * startet der Assistent.
 */
export function demoPersona(strict = false) {
  let p = null;
  try { p = new URLSearchParams(location.search).get('persona'); } catch { /* ohne location */ }
  try {
    if (p && PERSONAS.includes(p)) localStorage.setItem(PERSONA_KEY, p);
    else p = localStorage.getItem(PERSONA_KEY);
  } catch { /* Speicher gesperrt: nur diese Sitzung */ }
  return PERSONAS.includes(p) ? p : (strict ? null : 'showcase');
}

export function setDemoPersona(p) {
  try { if (PERSONAS.includes(p)) localStorage.setItem(PERSONA_KEY, p); } catch { /* gilt bis zum Neuladen */ }
}

/** Ablage eines Beispielhaushalts: showcase an der bisherigen Stelle, die anderen unter p/<persona>/. */
const personaRoot = () => {
  const p = demoPersona();
  return p === 'showcase' ? ROOT : `${ROOT}/p/${p}`;
};
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

// v3.4.0 (N1019) — Handbuch: Seiten und Bilder sind in allen Haushalten und
// Sprachen dieselben; build-demo.mjs legt sie einmal ab (docs-index.json,
// Bilder unter demo-api/docs/).
let docsIndex = null;
function loadDocsIndex() {
  docsIndex ??= loadJson(`${ROOT}/docs-index.json`).catch(() => ({}));
  return docsIndex;
}

/** Bild der Doku in der Demo: fertige Datei statt /api/docs/asset. */
export function demoDocAssetUrl(path) {
  return `${ROOT}/docs/${String(path).split('/').map(encodeURIComponent).join('/')}`;
}

function loadIndex(lang) {
  // je Haushalt und Sprache — ein Wechsel des Haushalts braucht kein Neuladen der Module
  const key = `${demoPersona()}|${lang}`;
  if (!indexes.has(key)) {
    // ältere Bauten kennen nur den einen Haushalt: dann dessen Antworten
    indexes.set(key, loadJson(`${personaRoot()}/index-${lang}.json`)
      .catch(() => loadJson(`${ROOT}/index-${lang}.json`))
      .catch(() => ({})));
  }
  return indexes.get(key);
}

/** v3.6.0 (F1031) — POST-Routen, die nur rechnen (Antworten aus scenarios.json). */
const POST_READS = ['/api/forecast/scenario'];
/** v3.9.0 (F1032) — Vorschau des Assistenten „Messkonzept“ (Antworten aus concepts-<lang>.json). */
const CONCEPT_PREVIEW = '/api/metering-concept/preview';
const conceptIndexes = new Map();
function loadConceptIndex(lang) {
  const key = `${demoPersona()}|${lang}`;
  if (!conceptIndexes.has(key)) conceptIndexes.set(key, loadJson(`${personaRoot()}/concepts-${lang}.json`).catch(() => ({})));
  return conceptIndexes.get(key);
}
const scenarioIndexes = new Map();
function loadScenarioIndex() {
  const p = demoPersona();
  if (!scenarioIndexes.has(p)) scenarioIndexes.set(p, loadJson(`${personaRoot()}/scenarios.json`).catch(() => ({})));
  return scenarioIndexes.get(p);
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
export async function demoRequest(method, path, lang, body = null) {
  let key, file;
  if (method === 'POST' && path === CONCEPT_PREVIEW) {
    key = demoBodyKey(path, body);
    file = (await loadConceptIndex(lang))[key] ?? (await loadConceptIndex('de'))[key];
    if (!file) {
      demoMisses.add(key);
      throw demoError('demo.conceptPresetsOnly', 404);
    }
  } else if (method === 'POST' && POST_READS.includes(path)) {
    // v3.6.0 (F1031) — Rechnen ohne Schreiben: vorgerechnete Szenarien
    key = demoBodyKey(path, body);
    file = (await loadScenarioIndex())[key];
    if (!file) {
      demoMisses.add(key);
      throw demoError('demo.scenarioPresetsOnly', 404);
    }
  } else {
    if (method !== 'GET') throw demoError('demo.readOnly', 403);
    key = demoKey(path);
    file = key.startsWith('/api/docs?')
      ? (await loadDocsIndex())[key]
      : (await loadIndex(lang))[key] ?? (await loadIndex('de'))[key];
  }
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
  return `${personaRoot()}/files/${lang}/${demoFileName(path)}`;
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
 * v3.5.0 (N1022) — Sprache der Demo festlegen (je Gerät, wie die Sprachwahl
 * der Hinweisleiste). Steht `?lang=` in der Adresse, zieht es mit — sonst
 * gewänne beim Neuladen die alte Adresse.
 */
export function setDemoLanguage(lang) {
  try { localStorage.setItem(LANG_KEY, lang); } catch { /* Speicher gesperrt: nur diese Sitzung */ }
  try {
    const url = new URL(location.href);
    if (url.searchParams.has('lang')) {
      url.searchParams.set('lang', lang);
      history.replaceState(history.state, '', url.toString());
    }
  } catch { /* ohne location */ }
}

/**
 * v3.7.1 — Umgebungsname als Abzeichen neben dem Logo, im Design des Abzeichens
 * „DEMO“ (dieselbe Klasse, nur ohne Knopf). In der öffentlichen Demo bleibt
 * „DEMO“, der Name wird übergangen; leer = kein Abzeichen.
 * @param {string|null|undefined} name  Einstellung `env_name` (serverseitig bereinigt)
 * @param {boolean} [demo]
 */
export function mountEnvBadge(name, demo = DEMO) {
  const brand = document.querySelector('.topbar__brand');
  if (!brand || demo) return;
  const v = String(name ?? '').trim();
  let el = document.getElementById('env-badge');
  if (!v) { el?.remove(); return; }
  if (!el) {
    el = document.createElement('span');
    el.id = 'env-badge';
    el.className = 'demo-chip demo-chip--env';
    el.dataset.role = 'env-badge';
    brand.append(el);
  }
  el.textContent = v;
  el.title = t('app.envBadge', { name: v });
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
