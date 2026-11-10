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
//   • v3.12.0 — nach jedem Release beginnt die Demo für jeden bei null
//     (demoFreshStart), und jede Datei unter demo-api/ trägt die Marke des Baus
//     (`?b=<built_at>`), damit ein neuer Bau nie aus dem Browser-Cache kommt
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
// v3.12.0 — die Version, mit der dieser Browser die Demo zuletzt gesehen hat
const VERSION_KEY = 'et-demo-version';
/**
 * v3.12.0 — So beginnt jeder Schlüssel, den die Oberfläche im Browser ablegt (tests/demo-start.test.mjs
 * prüft alle Fundstellen). Kein `clear()`: Der Ursprung auf GitHub Pages gehört allen Seiten des Kontos.
 */
export const STORAGE_PREFIXES = ['et-', 'et.'];
/** v3.12.0 — wie lange der Start auf meta.json wartet; danach läuft die Demo mit dem, was im Browser steht. */
export const META_TIMEOUT_MS = 3000;

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

// v3.12.0 — Marke des Baus aus meta.json (`built_at`); leer, solange sie fehlt
let buildMark = '';
/** Adresse einer Datei unter demo-api/ mit der Marke des Baus. `demoKey` und die Indexe kennen sie nicht. */
function marked(url) {
  return buildMark ? `${url}${url.includes('?') ? '&' : '?'}b=${buildMark}` : url;
}

async function loadJson(url) {
  await demoMeta();   // erst die Marke, damit auch die allererste Antwort vom neuen Bau kommt
  const r = await fetch(marked(url));
  if (!r.ok) throw new Error(`${url}: HTTP ${r.status}`);
  return r.json();
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
  return marked(`${ROOT}/docs/${String(path).split('/').map(encodeURIComponent).join('/')}`);
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
/**
 * v3.11.0 (F1036, Paket doku) — Brief zu einem Befund: der erste Entwurf, wie der Dialog ihn
 * anfragt (Antworten aus letters-<lang>.json). Jede Änderung und das PDF rechnet erst ein Server.
 */
const LETTER_RENDER = '/api/letters/render';
const letterIndexes = new Map();
function loadLetterIndex(lang) {
  const key = `${demoPersona()}|${lang}`;
  if (!letterIndexes.has(key)) letterIndexes.set(key, loadJson(`${personaRoot()}/letters-${lang}.json`).catch(() => ({})));
  return letterIndexes.get(key);
}
const scenarioIndexes = new Map();
function loadScenarioIndex() {
  const p = demoPersona();
  if (!scenarioIndexes.has(p)) scenarioIndexes.set(p, loadJson(`${personaRoot()}/scenarios.json`).catch(() => ({})));
  return scenarioIndexes.get(p);
}

/**
 * Bautag und Version der Demo: `{ built_at, today, version, languages }`.
 * v3.12.0 — am Cache vorbei (GitHub Pages schickt `max-age=600`), mit Zeitgrenze;
 * nicht erreichbar: `{}`, die Demo läuft trotzdem.
 */
export function demoMeta() {
  metaPromise ??= (async () => {
    const stop = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = setTimeout(() => stop?.abort(), META_TIMEOUT_MS);
    try {
      const r = await fetch(`${ROOT}/meta.json`, { cache: 'no-cache', signal: stop?.signal });
      if (!r.ok) throw new Error(`meta.json: HTTP ${r.status}`);
      const meta = await r.json();
      buildMark = encodeURIComponent(String(meta?.built_at ?? ''));
      return meta ?? {};
    } catch {
      return {};
    } finally {
      clearTimeout(timer);
    }
  })();
  return metaPromise;
}

/** Alle Schlüssel der Oberfläche aus einem Speicher (fremde bleiben). */
function clearStored(storage) {
  const mine = [];
  for (let i = 0; i < storage.length; i++) {
    const k = storage.key(i);
    if (k !== null && STORAGE_PREFIXES.some(p => k.startsWith(p))) mine.push(k);
  }
  mine.forEach(k => storage.removeItem(k));
}

/**
 * v3.12.0 — Die Demo beginnt nach jedem Release bei null. Sie merkt sich Haushalt, Stufe, Sprache,
 * ausgeblendete Hinweise und mehr im Browser. Wer sie einmal besucht hat, sah neue Funktionen sonst
 * nie: Die Beispiele für die Hauptfunktion stehen im Schaufenster, der gemerkte Haushalt blieb.
 *
 * Läuft vor dem ersten Lesen dieser Wahlen (app.js). Weicht die Version in meta.json von der
 * gemerkten ab, ist alles weg, der Einrichtungsassistent startet wie beim ersten Besuch; `?persona=` und
 * `?lang=` in der Adresse gelten danach wie immer. Speicher gesperrt oder meta.json nicht erreichbar:
 * Es bleibt, wie es ist. Eine Installation läuft nie hierdurch (`DEMO`, app.js).
 * @returns {Promise<boolean>} zurückgesetzt?
 */
export async function demoFreshStart() {
  const version = (await demoMeta())?.version;
  if (!version) return false;
  try {
    if (localStorage.getItem(VERSION_KEY) === version) return false;
    clearStored(localStorage);
    localStorage.setItem(VERSION_KEY, version);
  } catch {
    return false;   // Speicher gesperrt: dann merkt sich die Demo ohnehin nichts
  }
  try { clearStored(sessionStorage); } catch { /* gesperrt: dort liegt nur eine Meldung von vor einem Neustart */ }
  return true;
}

/**
 * v3.12.0 — Hauptfunktion des laufenden Release für die Hinweisleiste (Text `demo.news`).
 * Beim Release mitziehen. Passt die Version nicht mehr, schweigt die Zeile, statt Altes als neu zu nennen.
 */
export const DEMO_NEWS = { version: '3.12', href: '#/connections/ai?mcp=value' };

/** Zeile „Neu in vX.Y“ für diese Version oder null. */
export function demoNews(version, news = DEMO_NEWS) {
  const v = String(version ?? '');
  return v === news.version || v.startsWith(`${news.version}.`) ? news : null;
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
  } else if (method === 'POST' && path === LETTER_RENDER) {
    key = demoBodyKey(path, body);
    file = (await loadLetterIndex(lang))[key] ?? (await loadLetterIndex('de'))[key];
    if (!file) {
      demoMisses.add(key);
      throw demoError('demo.letterPresetsOnly', 404);
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
  return marked(`${personaRoot()}/files/${lang}/${demoFileName(path)}`);
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
  // v3.12.0 — „Neu in vX.Y“: führt von der Leiste zur Hauptfunktion des Release
  const fresh = demoNews(meta.version);
  const news = fresh ? document.createElement('p') : null;
  if (news) {
    news.className = 'demo-bar__news';
    news.dataset.role = 'demo-news';
    const a = document.createElement('a');
    a.href = fresh.href;
    a.textContent = t('demo.news', { version: fresh.version });
    news.append(a);
  }
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
  if (news) bar.prepend(news);
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
