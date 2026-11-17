// =====================================================================
// v3.13.0 — Bewegte Bilder der Hauptfunktionen für die Projektseite (site/), aus der echten App.
//
//   NODE_PATH=<node_modules mit playwright> node tools/build-animations.mjs [Schalter]
//
// Ein Befehl für das nächste Release: startet je Sprache einen eigenen Server mit leerem Datenverzeichnis
// unter fester Uhr (libfaketime), lädt den Beispielhaushalt „Schaufenster“ (Stufe Experte), spielt jede
// Szene im eigenen Headless-Browser ab (Uhr des Browsers auf denselben Stichtag) und setzt die Einzelbilder
// zusammen. Keine Nutzerdaten, nur Beispieldaten; nichts geht ins Netz außer an den eigenen Server.
//
//   site/assets/img/anim/<szene>[-dunkel].<de|en>.webp   animiert (img2webp), Schleife, ohne Ton; kein GIF
//                                                         (Entscheidung 2026-11-16: wer die WebP-Bilder der Seite
//                                                         zeigt, spielt auch animiertes WebP; sonst gilt das Standbild)
//   site/tools/quellen/anim/<szene>[-dunkel].<de|en>.png Rückfallbild: ein Bild desselben Laufs, gleiche Maße
//   site/assets/img/anim/quellen.json                     Maße, Größe, Stichtag, Bildzahl
// Danach (ohne --ohne-seite) site/tools/bilder.mjs (macht aus den Rückfallbildern WebP unter
// assets/img/screens/, wie alle Bilder der Seite) und site/tools/build.mjs (Seite neu, Regeln R1 bis R12).
//
// Schalter:
//   --heute=JJJJ-MM-TT  Stichtag der festen Uhr, 10:00 Ortszeit Europe/Berlin (Standard: HEUTE unten)
//   --nur=a,b           nur diese Szenen (Namen unten in SZENEN)
//   --sprachen=de,en    --themen=hell,dunkel
//   --port=19454        erster Port; je Sprache einer (19454, 19455)
//   --frames=<ordner>   Einzelbilder und Kontaktbogen je Lauf behalten (zum Ansehen)
//   --ohne-seite        bilder.mjs und build.mjs nicht aufrufen
//
// Braucht: php, faketime (libfaketime), img2webp (libwebp); pngquant, wenn vorhanden; ffmpeg nur für den
// Kontaktbogen von --frames. Fehlt eines, bricht das Skript mit einem Hinweis ab und installiert nichts.
// Nur Entwicklung, gehört nicht ins Image.
// =====================================================================
import { createRequire } from 'node:module';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync, readFileSync, existsSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, delimiter } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO = join(dirname(fileURLToPath(import.meta.url)), '..');
const SITE = join(REPO, 'site');
const require = createRequire(join(process.env.NODE_PATH || join(process.env.HOME || '', 'node_modules'), 'x'));
const pw = require('playwright');

/** Stichtag der Aufnahmen für v3.13.0; für ein neues Release mit --heute weiterziehen. */
const HEUTE = '2026-11-16';
const VIEW = { width: 1280, height: 800 };

const arg = (name, dflt) => {
  const a = process.argv.find(x => x.startsWith(`--${name}=`));
  return a ? a.slice(name.length + 3) : dflt;
};
const flag = (name) => process.argv.includes(`--${name}`);
const DAY = arg('heute', HEUTE);
if (!/^\d{4}-\d{2}-\d{2}$/.test(DAY)) { console.error('--heute=JJJJ-MM-TT'); process.exit(2); }
const ONLY = arg('nur', '') ? new Set(arg('nur', '').split(',')) : null;
const LANGS = arg('sprachen', 'de,en').split(',');
const THEMES = arg('themen', 'hell,dunkel').split(',');
const PORT = Number(arg('port', '19454'));
const KEEP = arg('frames', '');

// ── Werkzeuge ───────────────────────────────────────────────────────
const which = (name) => {
  for (const d of [...(process.env.PATH || '').split(delimiter), '/opt/homebrew/bin', '/usr/local/bin']) {
    if (d && existsSync(join(d, name))) return join(d, name);
  }
  return null;
};
const TOOLS = Object.fromEntries(['php', 'faketime', 'img2webp', 'ffmpeg', 'pngquant'].map(n => [n, which(n)]));
const missing = ['php', 'faketime', 'img2webp', ...(KEEP ? ['ffmpeg'] : [])].filter(n => !TOOLS[n]);
if (missing.length) {
  console.error(`Es fehlt: ${missing.join(', ')}. Bitte selbst installieren (macOS: brew install libfaketime webp${KEEP ? ' ffmpeg' : ''}); das Skript installiert nichts.`);
  process.exit(2);
}

// ── Feste Uhr ───────────────────────────────────────────────────────
/** 10:00 Ortszeit Europe/Berlin am Stichtag */
function stichtag(day) {
  const guess = new Date(`${day}T10:00:00Z`);
  const off = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Berlin', timeZoneName: 'shortOffset' })
    .formatToParts(guess).find(p => p.type === 'timeZoneName').value;   // „GMT+1“
  return new Date(guess.getTime() - Number(off.replace('GMT', '') || 0) * 3600e3);
}
const T0 = stichtag(DAY);
const SHIFT = Math.round((T0.getTime() - Date.now()) / 1000);   // Versatz für faketime, in Sekunden
const fakeNow = () => new Date(Date.now() + SHIFT * 1000);

// ── Server je Sprache ───────────────────────────────────────────────
const servers = [];
async function startServer(port) {
  const data = mkdtempSync(join(tmpdir(), 'et-anim-'));
  // php direkt (nicht über /usr/bin/env), Versatzform: Die Uhr läuft ab dem Stichtag weiter (wie tools/zeitreise.sh)
  const proc = spawn(TOOLS.faketime, ['-f', `${SHIFT >= 0 ? '+' : ''}${SHIFT}`, TOOLS.php, '-S', `127.0.0.1:${port}`, 'router.php'], {
    // eigene Prozessgruppe: Beim Beenden gehen auch die Worker von php -S (PHP_CLI_SERVER_WORKERS) mit
    cwd: REPO, stdio: ['ignore', 'ignore', 'ignore'], detached: true,
    env: { ...process.env, ET_DATA_DIR: data, TZ: 'Europe/Berlin', NO_FAKE_STAT: '1', PHP_CLI_SERVER_WORKERS: '4' },
  });
  const s = { port, data, proc, base: `http://127.0.0.1:${port}` };
  servers.push(s);
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(`${s.base}/api.php/api/health`)).ok) return s; } catch { /* startet noch */ }
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error(`Server auf Port ${port} startet nicht (belegt?)`);
}
const stopAll = () => {
  for (const s of servers.splice(0)) {
    try { process.kill(-s.proc.pid, 'SIGTERM'); } catch { try { s.proc.kill(); } catch { /* schon weg */ } }
    rmSync(s.data, { recursive: true, force: true });
  }
};
process.on('exit', stopAll);
process.on('SIGINT', () => { stopAll(); process.exit(130); });

async function call(s, lang, method, path, body, headers = {}) {
  const r = await fetch(`${s.base}/api.php/api${path}`, {
    method, body: body ? JSON.stringify(body) : undefined,
    headers: { 'content-type': 'application/json', 'x-et-language': lang, 'x-requested-with': 'XMLHttpRequest', ...headers },
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(`${method} ${path}: ${r.status} ${JSON.stringify(j).slice(0, 200)}`);
  return j?.data;
}
/** Beispielhaushalt frisch laden, wie „Mit Beispieldaten ausprobieren“ (docs/entwicklung/screenshots.md) */
async function frisch(s, lang) {
  await call(s, lang, 'GET', '/settings');
  await call(s, lang, 'PATCH', '/settings', { language: lang });
  await call(s, lang, 'POST', '/demo/import', { force: true });
  await call(s, lang, 'PATCH', '/settings', { ui_level: 'expert', weather_auto_fill: false });
  // Beleg für die feste Uhr: Der Server hat den Haushalt am Stichtag angelegt
  const h = await call(s, lang, 'GET', '/health');
  if (!String(h?.data_initialized_at || '').startsWith(DAY)) throw new Error(`Uhr des Servers nicht verstellt: ${h?.data_initialized_at}`);
}
async function mcp(s, key, name, args) {
  const r = await fetch(`${s.base}/api.php/api/mcp`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', accept: 'application/json, text/event-stream', authorization: `Bearer ${key}`, 'mcp-protocol-version': '2025-06-18' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name, arguments: args } }),
  });
  const j = await r.json();
  if (!r.ok || j?.result?.isError) throw new Error(`MCP ${name}: ${r.status} ${JSON.stringify(j).slice(0, 300)}`);
  return j.result.structuredContent;
}
const lastReading = async (s, lang, utility, meter) => {
  const rows = (await call(s, lang, 'GET', `/utility/${utility}/readings?meter_id=${meter}`)) || [];
  return rows.filter(r => r.meter_id === meter && !r.is_future).sort((a, b) => a.date.localeCompare(b.date)).at(-1);
};

// ── Film: Schlüsselbilder mit Haltezeiten ───────────────────────────
const ZEIGER = `<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 2.5 18.5 12l-6.6 1.4L8.3 20z" fill="#111827" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/></svg>`;
/** Mauszeiger und Klickring als eigene Ebene über der App (pointer-events: none) */
const overlay = (svg) => {
  const add = () => {
    if (document.getElementById('anim-zeiger')) return;
    const z = document.createElement('div');
    z.id = 'anim-zeiger';
    z.innerHTML = svg;
    z.style.cssText = 'position:fixed;left:-60px;top:-60px;z-index:2147483647;pointer-events:none;';
    const k = document.createElement('div');
    k.id = 'anim-klick';
    k.style.cssText = 'position:fixed;left:-60px;top:-60px;width:34px;height:34px;margin:-17px 0 0 -17px;border-radius:50%;border:3px solid #2563eb;opacity:.75;z-index:2147483646;pointer-events:none;display:none;';
    document.documentElement.append(k, z);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', add); else add();
};

/** Katalogtexte, die KI-Programme beim Namen nennen; im Bild soll keiner stehen */
const HERSTELLER_HINWEISE = ['settings.mcp.clientsHint', 'setups.scene.cloudAi.detailIntro'];
/** Je Hinweis zwei Stücke aus der Mitte des Texts ohne Auszeichnung, wie sie im Fenster stehen */
function verbotenFuer(lang) {
  const cat = JSON.parse(readFileSync(join(REPO, `public/locales/${lang}.json`), 'utf8'));
  return HERSTELLER_HINWEISE.flatMap((k) => {
    const v = k.split('.').reduce((o, p) => o?.[p], cat);
    if (typeof v !== 'string') throw new Error(`Katalogschlüssel fehlt: ${k}`);
    const t = v.replace(/\*\*|`/g, '').replace(/\{[^}]*\}/g, '').replace(/\s+/g, ' ');
    const m = Math.floor(t.length / 2);
    return [t.slice(m - 30, m), t.slice(m, m + 30)];
  });
}

class Film {
  constructor(page, verboten = []) { this.page = page; this.verboten = verboten; this.frames = []; this.pos = { x: VIEW.width * 0.62, y: VIEW.height * 0.55 }; this.posterAt = -1; }
  async snap(ms) {
    // Keine Liste von Herstellern im Bild (öffentliche Seite): Die App nennt KI-Programme sachlich in zwei Hinweisen
    // (HERSTELLER_HINWEISE); steht einer davon im Fenster, bricht die Szene ab
    const hinweis = await this.page.evaluate((stuecke) => {
      const norm = (s) => s.replace(/\s+/g, ' ');
      for (const el of document.querySelectorAll('#view *, .modal *')) {
        if (el.children.length > 3) continue;
        const t = norm(el.textContent || '');
        if (!stuecke.some(s => t.includes(s))) continue;
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < innerHeight && getComputedStyle(el).visibility !== 'hidden') return t.slice(0, 80);
      }
      return null;
    }, this.verboten || []);
    if (hinweis) throw new Error(`Hinweis mit Herstellernamen im Bild: „${hinweis}“`);
    // Textcursor aus: Er blinkt, und dieselbe Aufnahme ergäbe sonst je Lauf andere Bilder
    const buf = await this.page.screenshot({ type: 'png', caret: 'hide' });
    const last = this.frames.at(-1);
    if (last && last.buf.equals(buf)) last.ms += ms;   // gleiche Bilder zusammenlegen
    else this.frames.push({ buf, ms });
  }
  hold(ms) { this.frames.at(-1).ms += ms; }
  /** Rückfallbild: derselbe Zustand wie das letzte Bild, ohne Zeiger */
  async poster() {
    this.posterAt = this.frames.length - 1;
    await this.page.evaluate(() => { for (const id of ['anim-zeiger', 'anim-klick']) { const e = document.getElementById(id); if (e) e.style.visibility = 'hidden'; } });
    this.posterBuf = await this.page.screenshot({ type: 'png', caret: 'hide' });
    await this.page.evaluate(() => { for (const id of ['anim-zeiger', 'anim-klick']) { const e = document.getElementById(id); if (e) e.style.visibility = ''; } });
  }
  async zeiger(x, y, klick = false) {
    await this.page.evaluate(([x, y, klick]) => {
      const z = document.getElementById('anim-zeiger'), k = document.getElementById('anim-klick');
      if (z) { z.style.left = `${x - 4}px`; z.style.top = `${y - 2}px`; }
      if (k) { k.style.left = `${x}px`; k.style.top = `${y}px`; k.style.display = klick ? 'block' : 'none'; }
    }, [x, y, klick]);
  }
  /** Zeiger gleitet zum Element (sichtbar gemacht), Hover-Zustand wie mit der Maus */
  async move(target, { steps = 7, ms = 45 } = {}) {
    const loc = typeof target === 'string' ? this.page.locator(target).first() : target;
    await loc.scrollIntoViewIfNeeded();
    const b = await loc.boundingBox();
    if (!b) throw new Error(`nicht sichtbar: ${target}`);
    const to = { x: b.x + Math.min(b.width / 2, 60), y: b.y + b.height / 2 };
    const from = { ...this.pos };
    for (let i = 1; i <= steps; i++) {
      const t = i / steps, e = t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2;
      await this.zeiger(from.x + (to.x - from.x) * e, from.y + (to.y - from.y) * e);
      await this.snap(ms);
    }
    this.pos = to;
    await this.page.mouse.move(to.x, to.y);
    return loc;
  }
  /** `nachher: false`: kein Bild nach dem Klick (wenn die App danach erst noch springt) */
  async click(target, { settle = 900, nachher = true } = {}) {
    const loc = await this.move(target);
    await this.zeiger(this.pos.x, this.pos.y, true);
    await this.snap(140);
    await this.zeiger(this.pos.x, this.pos.y, false);
    await loc.click();
    await ruhig(this.page, settle);
    await this.page.evaluate(() => { const k = document.getElementById('anim-klick'); if (k) k.style.display = 'none'; });
    if (nachher) await this.snap(120);
    return loc;
  }
  async select(target, value, { settle = 1500 } = {}) {
    const loc = await this.move(target);
    await this.zeiger(this.pos.x, this.pos.y, true);
    await this.snap(140);
    await this.zeiger(this.pos.x, this.pos.y, false);
    await loc.selectOption(value);
    await ruhig(this.page, settle);
    await this.snap(120);
  }
  /** Tippen, Zeichen für Zeichen; `ab`: erst ab diesem Zeichen aufnehmen (die ersten Ziffern lösen sonst Hinweise aus,
   *  die nur halb Getipptes betreffen, etwa „kleiner als der letzte Stand“) */
  async type(target, text, { ms = 85, ab = 0 } = {}) {
    const loc = await this.click(target, { settle: 200 });
    await loc.fill('');
    for (const [i, ch] of [...text].entries()) {
      await this.page.keyboard.type(ch);
      if (i + 1 >= ab) await this.snap(i + 1 === ab ? ms * ab : ms);
    }
  }
  /** Seite in wenigen Schritten scrollen (Fenster oder ein Element mit eigenem Scrollbereich) */
  async scrollTo(target, { steps = 5, ms = 55, block = 0.18 } = {}) {
    const y0 = await this.page.evaluate(() => window.scrollY);
    const loc = typeof target === 'string' ? this.page.locator(target) : target;
    const y1 = await loc.first().evaluate((el, block) => {
      const r = el.getBoundingClientRect();
      return Math.max(0, Math.min(window.scrollY + r.top - innerHeight * block, document.documentElement.scrollHeight - innerHeight));
    }, block);
    for (let i = 1; i <= steps; i++) {
      const t = i / steps, e = t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2;
      await this.page.evaluate((y) => window.scrollTo({ top: y, behavior: 'instant' }), y0 + (y1 - y0) * e);
      await this.snap(ms);
    }
  }
}
async function ruhig(page, ms = 800) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForFunction(() => !document.querySelector('#view > .loading'), null, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(ms);
}
async function oeffne(page, base, route) {
  await page.goto(`${base}/${route}`, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => !document.querySelector('#view > .loading') && document.querySelector('#view')?.children.length > 0, null, { timeout: 15000 });
  await page.waitForTimeout(1600);   // Diagramme zeichnen sich ein
}
const muss = async (page, sel, re, was) => {
  const text = await page.locator(sel).first().innerText().catch(() => '');
  if (!re.test(text)) throw new Error(`${was}: erwartet ${re}, gefunden „${text.slice(0, 240)}“`);
};
const zahl = (lang, v, d = 1) => v.toLocaleString(lang === 'de' ? 'de-DE' : 'en-GB', { minimumFractionDigits: d, maximumFractionDigits: d, useGrouping: false });

// ── Szenen ──────────────────────────────────────────────────────────
// Jede Szene: vor (Daten vorbereiten, über die API), spiel (Drehbuch im Browser). poster() markiert das
// Rückfallbild. Szenen mit „aendert“ ändern Daten; vor jedem Lauf lädt das Skript den Haushalt neu.
const SZENEN = {
  // 1. Zählerstand eintragen, die Rückfrage nach dem Wetter fängt das vergessene Komma (#65)
  rueckfrage: {
    async vor({ s, lang }) {
      const last = await lastReading(s, lang, 'gas', 'm_gas_main');
      const today = fakeNow().toISOString().slice(0, 10);
      const exp = await call(s, lang, 'GET', `/utility/gas/meters/m_gas_main/reading-expectation?from=${last.date}&to=${today}`);
      if (exp?.basis == null || !(exp.expected > 0)) throw new Error('keine Erwartung nach dem Wetter');
      const richtig = Math.round((Number(last.counter) + exp.expected) * 10) / 10;
      const tippfehler = String(Math.round(richtig * 10));
      // so viele Ziffern, wie der letzte Stand vor dem Komma hat
      const ab = String(Math.floor(Number(last.counter))).length;
      return { richtig, tippfehler, ab };
    },
    async spiel({ film, page, base, lang, v }) {
      await oeffne(page, base, '#/zaehlerstaende');
      await film.snap(1300);
      const feld = '.reading-card[data-meter-id="m_gas_main"] [data-role="counter"]';
      // erst ab der Stelle aufnehmen, an der der Stand über dem letzten liegt
      await film.type(feld, v.tippfehler, { ab: v.ab });
      film.hold(500);
      await film.click('[data-action="save-all"]', { settle: 700 });
      await page.waitForSelector('.modal', { state: 'visible' });
      await page.waitForTimeout(400);
      await muss(page, '.modal', lang === 'de' ? /nach dem Wetter erwartet/ : /weather suggests/, 'Rückfrage nach dem Wetter');
      await film.snap(3200);
      await film.poster();
      // Nach der Rückfrage gibt die App den Fokus an „Alle speichern“ zurück (Ende der Liste); erst danach wieder zur
      // Karte, ohne den Sprung aufzunehmen
      await film.click('.modal [data-act="cancel"], .modal .btn--ghost', { settle: 1500, nachher: false });
      await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
      await page.waitForTimeout(300);
      await film.snap(500);
      // Das vergessene Komma einfügen: Zeiger ins Feld, vor die letzte Ziffer, Komma tippen
      const f = await film.click(feld, { settle: 200 });
      await f.press('End');
      await f.press('ArrowLeft');
      await film.snap(500);
      // Nach dem Komma läuft die Seite in Chromium ans Ende (bei „Alle speichern“; Ursache in der App nicht geklärt,
      // dem Fokus nach bleibt das Feld aktiv): zurück an den Anfang, bevor das Bild entsteht
      await page.keyboard.type(lang === 'de' ? ',' : '.');
      await page.waitForTimeout(900);
      await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
      await page.waitForTimeout(200);
      await film.snap(1300);
      if ((await f.inputValue()) !== zahl(lang, v.richtig)) throw new Error(`Stand nach dem Komma: ${await f.inputValue()}`);
      // Speichern; danach steht die Karte oben mit dem neuen letzten Stand, die Meldung unten rechts bleibt
      await film.click('[data-action="save-all"]', { settle: 1400, nachher: false });
      await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
      await page.waitForTimeout(300);
      await film.snap(2600);
    },
    aendert: true,
  },

  // 2. „Bekomme ich Geld zurück?“ auf der Übersicht, dann der Saldo der Gasrechnung mit Spanne
  saldo: {
    async spiel({ film, page, base }) {
      await oeffne(page, base, '#/dashboard');
      await film.snap(1500);
      await film.move('li.dash-answer__row:has([data-role="money-band"])');
      film.hold(1900);
      await film.click('#sidebar a[href="#/utility/gas"], nav a[href="#/utility/gas"]', { settle: 1800 });
      film.hold(600);
      await film.click('[data-sicht-btn="balance"]', { settle: 1800 });
      await muss(page, '[data-sicht="balance"] .balance-verdict', /\d/, 'Erwartet bei Abrechnung (Gas)');
      film.hold(2600);
      await film.poster();
      await film.scrollTo('[data-sicht="balance"] canvas', { block: 0.12 });
      await page.waitForTimeout(1500);
      await film.snap(2800);
    },
  },

  // 3. Prognose: Wallbox (E-Auto), HT und NT als ein Vertrag, Einspeisung nach der Sonne mit Auszahlungen.
  //    Name nicht „prognose“: So heißt schon ein Bild der Seite (site/tools/bilder.mjs bricht bei gleichem Namen ab).
  'prognose-strom': {
    async spiel({ film, page, base, lang }) {
      await oeffne(page, base, '#/forecast');
      await film.snap(1000);
      await film.select('#util-select', 'strom', { settle: 2000 });
      // Wallbox: Beispiel „E-Auto“ am Hauptzähler
      await film.click('#ws-presets [data-preset="ev"]', { settle: 2200 });
      film.hold(2400);
      // HT und NT als ein Vertrag: die Gruppe als Ziel
      await film.select('#meter-select', 'g_strom_wp', { settle: 2400 });
      if ((await page.locator('#meter-select').inputValue()) !== 'g_strom_wp') throw new Error('Gruppe HT/NT nicht als Ziel gewählt');
      film.hold(2600);
      await film.poster();
      // Einspeisung nach der Sonne, mit Auszahlungen. Kein Beispiel „Speicher dazu“: Es nennt einen Sparbetrag,
      // und die Seite zeigt keine Sparbeträge erfundener Beispiele (site/tools/bilder.mjs).
      await film.click('#sidebar a[href="#/utility/pv_einspeisung"], nav a[href="#/utility/pv_einspeisung"]', { settle: 1600 });
      await film.click('[data-sicht-btn="balance"]', { settle: 1800 });
      await muss(page, '[data-sicht="balance"]', lang === 'de' ? /Bereits ausgezahlt/ : /paid out/i, 'Auszahlungen der Einspeisung');
      film.hold(3000);
    },
  },

  // 4. Verträge und Fristen bis zum Brief
  fristen: {
    async spiel({ film, page, base }) {
      await oeffne(page, base, '#/contracts');
      await film.snap(1800);
      await film.click('#sidebar a[href="#/todo"], nav a[href="#/todo"]', { settle: 1600 });
      film.hold(1600);
      await film.click('[data-art="rights"]', { settle: 1200 });
      film.hold(1400);
      await film.click('[data-role="letter-button"]', { settle: 600 });
      await page.waitForSelector('[data-role="letter-preview"]');
      await page.waitForFunction(() => (document.querySelector('[data-role="letter-preview"]')?.textContent || '').split('\n').length > 5);
      await page.waitForTimeout(500);
      await film.snap(1800);
      // Im Dialog zur Vorschau des Briefs, darin bis kurz vor die Anrede
      await page.evaluate(() => {
        const pre = document.querySelector('[data-role="letter-preview"]');
        pre.scrollIntoView({ block: 'end' });
        const lines = pre.textContent.split('\n');
        const at = lines.findIndex(l => /^(Sehr geehrte|Dear)/.test(l));
        pre.scrollTop = Math.max(0, (at - 6) * parseFloat(getComputedStyle(pre).lineHeight));
      });
      await page.waitForTimeout(300);
      await film.snap(3200);
      await film.poster();
    },
  },

  // 5. KI-Assistent: Zahlen mit Herkunft, ein Vorschlag, bestätigt in der App
  ki: {
    async vor({ s, lang }) {
      await call(s, lang, 'PATCH', '/settings', { mcp_enabled: true });
      const key = (await call(s, lang, 'POST', '/auth/keys', { name: 'Open WebUI', scope: 'mcp_write' })).key;
      await mcp(s, key, 'get_overview', {});
      const last = await lastReading(s, lang, 'gas', 'm_gas_main');
      await mcp(s, key, 'propose_reading', { utility: 'gas', meter: 'm_gas_main', counter: Math.round(Number(last.counter) + 61), date: fakeNow().toISOString().slice(0, 10) });
      return {};
    },
    async spiel({ film, page, base, lang }) {
      await oeffne(page, base, '#/help/setups/local-ai');
      await film.snap(1700);
      // „Was du bekommst“: zu jeder Zahl ihre Herkunft
      await film.scrollTo(page.locator('#view li').filter({ hasText: lang === 'de' ? 'Zu jeder Zahl ihre Herkunft' : 'The source of every figure' }), { block: 0.55 });
      film.hold(2400);
      await film.poster();
      await film.click('#sidebar a[href="#/connections"], nav a[href="#/connections"]', { settle: 1200 });
      await film.click('#view a[href="#/connections/ai"]', { settle: 1400 });
      film.hold(1800);
      await film.click('#sidebar a[href="#/todo"], nav a[href="#/todo"]', { settle: 1600 });
      film.hold(800);
      await film.click('[data-mcp-apply], [data-act="mcp-apply"], button[data-proposal-apply]', { settle: 600 });
      await page.waitForSelector('.modal', { state: 'visible' });
      await page.waitForTimeout(400);
      await muss(page, '.modal', /Open WebUI/, 'Vorschlag der KI über ihren Schlüssel');
      await film.snap(1700);
      await film.click('.modal [data-act="ok"], .modal .btn--primary', { settle: 1400 });
      film.hold(2000);
    },
    aendert: true,
  },

  // 6. Posteingang: Dokument öffnen, Einträge prüfen, übernehmen
  posteingang: {
    async spiel({ film, page, base, lang }) {
      await oeffne(page, base, '#/inbox');
      await film.snap(1400);
      await film.click('#view a[href^="#/inbox/inb_"]', { settle: 1800 });
      film.hold(1500);
      // Das markierte Feld: gelesen 10.450, im Dokument steht 10.540
      const korr = page.locator('#view [data-correct]').first();
      await film.scrollTo('#view [data-correct]', { block: 0.45 });
      film.hold(1600);
      await film.poster();
      await film.click(korr, { settle: 500 });
      const edit = page.locator('#view .inbox-edit [data-role="edit"]');
      await film.type(edit, '10540');
      film.hold(300);
      await film.click('#view .inbox-edit [data-role="ok"]', { settle: 1400 });
      film.hold(900);
      await film.scrollTo('#view h1', { block: 0.1 });
      const take = page.locator('#view').locator('button, a').filter({ hasText: lang === 'de' ? 'Übernehmen und prüfen' : 'Take over and check' }).first();
      await film.click(take, { settle: 2200 });
      await muss(page, 'body', lang === 'de' ? /über deiner eigenen Rechnung/ : /than your own calculation/, 'Rechnung gegen die eigene geprüft');
      film.hold(2600);
    },
    aendert: true,
  },
};

// ── Lauf ────────────────────────────────────────────────────────────
const OUT_ANIM = join(SITE, 'assets/img/anim');
const OUT_POSTER = join(SITE, 'tools/quellen/anim');
mkdirSync(OUT_ANIM, { recursive: true });
mkdirSync(OUT_POSTER, { recursive: true });
const MANIFEST = join(OUT_ANIM, 'quellen.json');
const manifest = existsSync(MANIFEST) ? JSON.parse(readFileSync(MANIFEST, 'utf8')) : { bilder: [] };
const commit = execFileSync('git', ['-C', REPO, 'rev-parse', '--short', 'HEAD'], { encoding: 'utf8' }).trim();
const version = readFileSync(join(REPO, 'VERSION'), 'utf8').trim();

function encode(frames, name, dir) {
  const files = frames.map((f, i) => { const p = join(dir, `f${String(i).padStart(3, '0')}.png`); writeFileSync(p, f.buf); return { file: p, ms: f.ms }; });
  const webp = join(OUT_ANIM, `${name}.webp`);
  // WebP: verlustbehaftet, Qualität 80; nur geänderte Bereiche je Bild (img2webp sucht sie selbst). Kein GIF.
  const a = ['-loop', '0', '-lossy', '-q', '80', '-m', '6'];
  for (const f of files) a.push('-d', String(f.ms), f.file);
  execFileSync(TOOLS.img2webp, [...a, '-o', webp], { stdio: ['ignore', 'ignore', 'pipe'] });
  // Bildliste mit Dauer, für den Kontaktbogen (--frames)
  writeFileSync(join(dir, 'liste.txt'), `ffconcat version 1.0\n${files.map(f => `file '${f.file}'\nduration ${(f.ms / 1000).toFixed(3)}`).join('\n')}\nfile '${files.at(-1).file}'\n`);
  return { webp, files };
}

const browsers = {};
// Datumsfelder formatiert der Browser selbst (docs/entwicklung/screenshots.md): Deutsch mit dem vollen Chromium
// („tt.mm.jjjj“), sonst die Headless-Shell mit --lang=en-GB („dd/mm/yyyy“), wie tools/build-screenshots.mjs
const launch = async (lang) => {
  const opts = { args: [`--lang=${lang === 'de' ? 'de-DE' : 'en-GB'}`] };
  return (browsers[lang] ??= lang === 'de'
    ? await pw.chromium.launch({ ...opts, channel: 'chromium' }).catch(() => pw.chromium.launch(opts))
    : await pw.chromium.launch(opts));
};
const results = [];
const failures = [];
try {
  for (const [i, lang] of LANGS.entries()) {
    const s = await startServer(PORT + i);
    const browser = await launch(lang);
    for (const [szene, def] of Object.entries(SZENEN)) {
      if (ONLY && !ONLY.has(szene)) continue;
      for (const theme of THEMES) {
        const name = `${szene}${theme === 'dunkel' ? '-dunkel' : ''}.${lang}`;
        const scheme = theme === 'dunkel' ? 'dark' : 'light';
        try {
          await frisch(s, lang);
          const v = def.vor ? await def.vor({ s, lang }) : {};
          const ctx = await browser.newContext({
            viewport: VIEW, deviceScaleFactor: 1, locale: lang === 'de' ? 'de-DE' : 'en-GB', timezoneId: 'Europe/Berlin',
            serviceWorkers: 'block', colorScheme: scheme, reducedMotion: 'no-preference',
          });
          await ctx.clock.install({ time: fakeNow() });
          await ctx.addInitScript(([t, l]) => { try { localStorage.setItem('et-theme', t); localStorage.setItem('et-language', l); } catch { /* ohne Speicher */ } }, [scheme, lang]);
          await ctx.addInitScript(overlay, ZEIGER);
          const page = await ctx.newPage();
          const errors = [];
          page.on('pageerror', e => errors.push(String(e.message || e)));
          const film = new Film(page, verbotenFuer(lang));
          await def.spiel({ film, page, base: s.base, lang, v, s });
          await ctx.close();
          if (errors.length) throw new Error(`Seitenfehler: ${errors.join(' | ')}`);
          if (film.posterAt < 0) throw new Error('kein Rückfallbild markiert');
          const dir = KEEP ? join(KEEP, name) : mkdtempSync(join(tmpdir(), 'et-anim-f-'));
          rmSync(dir, { recursive: true, force: true });
          mkdirSync(dir, { recursive: true });
          const { webp, files } = encode(film.frames, name, dir);
          const poster = join(OUT_POSTER, `${name}.png`);
          writeFileSync(poster, film.posterBuf);
          if (TOOLS.pngquant) execFileSync(TOOLS.pngquant, ['--quality=88-100', '--strip', '--force', '--output', poster, poster]);
          const dauer = film.frames.reduce((n, f) => n + f.ms, 0);
          const entry = {
            datei: `assets/img/anim/${name}.webp`, szene, sprache: lang, thema: theme,
            breite: VIEW.width, hoehe: VIEW.height, bilder: film.frames.length, dauer_ms: dauer,
            bytes: statSync(webp).size,
            rueckfall: `assets/img/screens/${name}.webp`,
            quelle_rueckfall: `tools/quellen/anim/${name}.png`,
          };
          manifest.bilder = manifest.bilder.filter(b => b.datei !== entry.datei);
          manifest.bilder.push(entry);
          results.push(entry);
          console.log(`ok ${name}: ${film.frames.length} Bilder, ${(dauer / 1000).toFixed(1)} s, WebP ${(entry.bytes / 1024).toFixed(0)} KB`);
          if (KEEP) {
            // Kontaktbogen zum Ansehen: alle Bilder klein nebeneinander
            const n = files.length, cols = 6, rows = Math.ceil(n / cols);
            execFileSync(TOOLS.ffmpeg, ['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', join(dir, 'liste.txt'),
              '-vf', `scale=300:-1,tile=${cols}x${rows}:padding=3`, '-frames:v', '1', '-update', '1', join(KEEP, `${name}-bogen.png`)]);
          } else rmSync(dir, { recursive: true, force: true });
        } catch (e) {
          failures.push(`${name}: ${e.message}`);
          console.log(`FEHLER ${name}: ${e.message}`);
        }
      }
    }
  }
} finally {
  for (const b of Object.values(browsers)) await b.close();
  stopAll();
}

manifest.bilder.sort((a, b) => a.datei.localeCompare(b.datei));
manifest.version = version;
manifest.commit = commit;
manifest.heute = DAY;
manifest.erzeugt_mit = 'tools/build-animations.mjs';
writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2) + '\n');

if (failures.length) {
  console.error(`\n${failures.length} Szenen fehlgeschlagen:\n  ${failures.join('\n  ')}`);
  process.exit(1);
}
if (!flag('ohne-seite')) {
  const env = { ...process.env, ET_REPO: REPO };
  execFileSync(process.execPath, [join(SITE, 'tools/bilder.mjs')], { cwd: SITE, env, stdio: 'inherit' });
  execFileSync(process.execPath, [join(SITE, 'tools/build.mjs')], { cwd: SITE, env, stdio: 'inherit' });
}
console.log(`fertig: ${results.length} Animationen, Stichtag ${DAY}`);
