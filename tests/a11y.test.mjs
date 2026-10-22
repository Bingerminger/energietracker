// =====================================================================
// v3.7.0 (Qualität) — Barrierefreiheit der Dialoge, ohne externe Pakete.
//
//   node tests/a11y.test.mjs            (startet einen eigenen Server, freier Port
//                                        oder ET_A11Y_PORT; Daten: Kopie von demo-data/)
//
// Der Test lädt die echte App (app.js) in JSDOM wie poisoned-render.test.mjs,
// geht alle Seiten durch, die die Navigation erreicht, und klickt jeden
// Knopf. Jeder Dialog, der dabei über components/modal.js aufgeht, wird
// geprüft — auch Dialoge, die nach diesem Test dazukommen; es gibt keine
// Liste der Dialoge. Im Dialog klickt er jeden Knopf noch einmal (Rückfragen,
// Fehlermeldungen beim Speichern ohne Eingaben). Schreibende Anfragen und
// Abrufe ins Netz beantwortet der Test selbst mit einem Fehler: Kein Klick
// ändert Daten.
//
// Regeln je Dialog:
//   • role="dialog" bzw. "alertdialog", aria-modal="true", Hintergrund inert
//   • Titel per aria-labelledby, als Überschrift; alertdialog mit Beschreibung
//   • Fokus beim Öffnen im Dialog — nicht auf „×“, wenn es Felder/Knöpfe gibt
//   • Fokusfalle (Tab vom letzten aufs erste, Umschalt+Tab umgekehrt)
//   • Escape schließt, der Fokus kehrt zum Auslöser zurück
//   • jedes Feld beschriftet, jeder Knopf und Link mit Namen (Platzhalter zählt nicht)
//   • aria-Verweise zeigen auf vorhandene IDs, IDs eindeutig
//   • Fehlermeldungen: Live-Region (role="alert"/aria-live) und, sobald
//     sichtbar, per aria-describedby am Feld, das Feld mit aria-invalid
//   • Kontrast der Schrift nach WCAG AA (4,5:1, große Schrift 3:1) in hell
//     und dunkel, nachgerechnet aus den Stylesheets der App
// Dazu die Mechanik von modal.js an eigenen Dialogen (Reihenfolge des
// Startfokus, Touch, Optionsgruppen, <summary>, verschachtelte Dialoge,
// Auslöser nach Neuaufbau, showFieldError).
// =====================================================================
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { cpSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM, VirtualConsole } = require('jsdom');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const { contrast } = await import(pathToFileURL(join(ROOT, 'public/js/lib/contrast.js')).href);

let pass = 0, fail = 0;
const t = (name, ok, info = '') => {
  if (ok) { pass++; console.log(`  ✓ ${name}${info ? ' — ' + info : ''}`); }
  else    { fail++; console.log(`  ✗ ${name}${info ? ' — ' + info : ''}`); }
};
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
// A11Y_DEBUG=1: alle Befunde, Auslöser je Seite und alle Dialoge
const DEBUG = !!process.env.A11Y_DEBUG;
const list = (arr, n = 8) => arr.length ? `${arr.length}: ` + arr.slice(0, DEBUG ? arr.length : n).join(' | ') + (!DEBUG && arr.length > n ? ' …' : '') : '';

// ═════════════════════════════════════════════════════════════════════
// CSS-Kaskade — gerade genug, um Schrift- und Hintergrundfarbe eines
// Elements aus den Stylesheets der App zu bestimmen (JSDOM rechnet weder
// var() noch Vererbung). Regeln in @media/@container gelten nicht (Ansicht
// am Mac), @supports gilt.
// ═════════════════════════════════════════════════════════════════════
function splitTop(str, sep) {
  const out = []; let depth = 0, quote = null, start = 0;
  for (let i = 0; i < str.length; i++) {
    const c = str[i];
    if (quote) { if (c === quote && str[i - 1] !== '\\') quote = null; continue; }
    if (c === '"' || c === "'") quote = c;
    else if (c === '(' || c === '[') depth++;
    else if (c === ')' || c === ']') depth--;
    else if (c === sep && depth === 0) { out.push(str.slice(start, i)); start = i + 1; }
  }
  out.push(str.slice(start));
  return out;
}

function parseCss(text, out = [], cond = []) {
  const css = text.replace(/\/\*[\s\S]*?\*\//g, '');
  let i = 0;
  while (i < css.length) {
    const open = css.indexOf('{', i);
    if (open < 0) break;
    let head = css.slice(i, open);
    head = head.slice(head.lastIndexOf(';') + 1).trim();
    let depth = 1, j = open + 1;
    while (j < css.length && depth) { if (css[j] === '{') depth++; else if (css[j] === '}') depth--; j++; }
    const body = css.slice(open + 1, j - 1);
    if (head.startsWith('@')) {
      if (/^@(media|supports|container|layer)\b/.test(head)) parseCss(body, out, [...cond, head]);
    } else if (!cond.some(c => !c.startsWith('@supports') && !c.startsWith('@layer'))) {
      const decls = {};
      for (const d of splitTop(body, ';')) {
        const k = d.indexOf(':');
        if (k < 0) continue;
        let prop = d.slice(0, k).trim();
        let value = d.slice(k + 1).trim();
        if (!prop) continue;
        if (!prop.startsWith('--')) prop = prop.toLowerCase();
        const important = /!important\s*$/i.test(value);
        if (important) value = value.replace(/!important\s*$/i, '').trim();
        decls[prop] = { value, important };
      }
      out.push({ selector: head, decls });
    }
    i = j;
  }
  return out;
}

function specificity(sel) {
  let a = 0, b = 0, c = 0;
  let s = sel.replace(/::?(before|after|first-line|first-letter|placeholder|selection|marker|backdrop|-webkit-[a-z-]+|-moz-[a-z-]+)(\([^)]*\))?/gi, '');
  // :is/:not/:has → spezifischstes Argument, :where → 0
  s = s.replace(/:(is|not|has|where|matches)\(/gi, (m) => m.toLowerCase());
  let guard = 0;
  while (/:(is|not|has|where)\(/.test(s) && guard++ < 50) {
    const m = /:(is|not|has|where)\(/.exec(s);
    let depth = 1, j = m.index + m[0].length;
    while (j < s.length && depth) { if (s[j] === '(') depth++; else if (s[j] === ')') depth--; j++; }
    const inner = s.slice(m.index + m[0].length, j - 1);
    if (m[1] !== 'where') {
      const best = splitTop(inner, ',').map(x => specificity(x.trim())).sort(cmpSpec).pop() || [0, 0, 0];
      a += best[0]; b += best[1]; c += best[2];
    }
    s = s.slice(0, m.index) + ' ' + s.slice(j);
  }
  a += (s.match(/#[\w-]+/g) || []).length;
  b += (s.match(/\.[\w-]+|\[[^\]]*\]|:(?!:)[\w-]+(\([^)]*\))?/g) || []).length;
  c += (s.replace(/\[[^\]]*\]|\([^)]*\)/g, '').match(/(^|[\s>+~])[a-z][\w-]*/gi) || []).length;
  return [a, b, c];
}
const cmpSpec = (x, y) => x[0] - y[0] || x[1] - y[1] || x[2] - y[2];
/** Rang einer Deklaration: [!important, a, b, c, Reihenfolge] — lexikografisch. */
const cmpRank = (x, y) => { for (let i = 0; i < x.length; i++) if (x[i] !== y[i]) return x[i] - y[i]; return 0; };

/** Schlüssel des letzten Verbunds (rechts vom letzten Kombinator) für den Index. */
function bucketOf(sel) {
  const flat = sel.replace(/\([^()]*(\([^()]*\))*[^()]*\)/g, '');
  const parts = flat.trim().split(/\s*[\s>+~]\s*/);
  const last = parts[parts.length - 1] || '';
  const cls = last.match(/\.([\w-]+)/);
  if (cls) return '.' + cls[1];
  const id = last.match(/#([\w-]+)/);
  if (id) return '#' + id[1];
  const tag = last.match(/^([a-z][\w-]*)/i);
  if (tag) return tag[1].toLowerCase();
  return '*';
}

const UA = { h1: { 'font-weight': '700' }, h2: { 'font-weight': '700' }, h3: { 'font-weight': '700' }, h4: { 'font-weight': '700' },
  strong: { 'font-weight': '700' }, b: { 'font-weight': '700' }, th: { 'font-weight': '700' }, legend: {} };

class Cascade {
  constructor(cssTexts) {
    this.entries = [];
    let order = 0;
    for (const text of cssTexts) {
      for (const rule of parseCss(text)) {
        for (const part of splitTop(rule.selector, ',')) {
          const sel = part.trim();
          if (!sel) continue;
          this.entries.push({ sel, spec: specificity(sel), order: order++, decls: rule.decls, bucket: bucketOf(sel) });
        }
      }
    }
    this.index = new Map();
    for (const e of this.entries) {
      if (!this.index.has(e.bucket)) this.index.set(e.bucket, []);
      this.index.get(e.bucket).push(e);
    }
    this.reset();
  }
  reset() { this.matched = new WeakMap(); this.memo = new WeakMap(); }
  match(el) {
    let m = this.matched.get(el);
    if (m) return m;
    const cand = [...(this.index.get('*') || []), ...(this.index.get(el.localName) || [])];
    if (el.id) cand.push(...(this.index.get('#' + el.id) || []));
    for (const c of el.classList) cand.push(...(this.index.get('.' + c) || []));
    m = [];
    const seen = new Set();
    for (const e of cand) {
      if (seen.has(e)) continue;
      seen.add(e);
      try { if (el.matches(e.sel)) m.push(e); } catch { /* Pseudo-Elemente, unbekannte Selektoren */ }
    }
    this.matched.set(el, m);
    return m;
  }
  declared(el, prop) {
    let memo = this.memo.get(el);
    if (!memo) { memo = new Map(); this.memo.set(el, memo); }
    if (memo.has(prop)) return memo.get(prop);
    let best = null;
    const consider = (value, important, spec, order) => {
      const rank = [important ? 1 : 0, ...spec, order];
      if (!best || cmpRank(rank, best.rank) > 0) best = { value, rank };
    };
    const ua = UA[el.localName]?.[prop];
    if (ua) consider(ua, false, [0, 0, 0], -1);
    for (const e of this.match(el)) {
      const d = e.decls[prop];
      if (d) consider(d.value, d.important, e.spec, e.order);
    }
    const inline = el.getAttribute?.('style');
    if (inline) {
      for (const d of splitTop(inline, ';')) {
        const k = d.indexOf(':');
        if (k < 0) continue;
        const p = d.slice(0, k).trim();
        if ((p.startsWith('--') ? p : p.toLowerCase()) !== prop) continue;
        let v = d.slice(k + 1).trim();
        const imp = /!important\s*$/i.test(v);
        consider(v.replace(/!important\s*$/i, '').trim(), imp, [9, 0, 0], 1e9);
      }
    }
    memo.set(prop, best);
    return best;
  }
  value(el, prop) { return this.declared(el, prop)?.value ?? null; }
  custom(el, name, depth = 0) {
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const v = this.value(n, name);
      if (v != null) return depth > 20 ? v : this.subst(n, v, depth + 1);
    }
    return null;
  }
  subst(el, value, depth = 0) {
    let out = String(value);
    for (let guard = 0; guard < 30; guard++) {
      const i = out.indexOf('var(');
      if (i < 0) break;
      let d = 1, j = i + 4;
      while (j < out.length && d) { if (out[j] === '(') d++; else if (out[j] === ')') d--; j++; }
      const args = splitTop(out.slice(i + 4, j - 1), ',');
      const name = args[0].trim();
      let rep = this.custom(el, name, depth);
      if (rep == null) rep = args.length > 1 ? this.subst(el, args.slice(1).join(',').trim(), depth + 1) : '';
      out = out.slice(0, i) + rep + out.slice(j);
    }
    return out.trim();
  }
  resolved(el, prop) { const v = this.value(el, prop); return v == null ? null : this.subst(el, v); }
  color(el) {
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const v = this.resolved(n, 'color');
      if (v == null || /^(inherit|unset|currentcolor)$/i.test(v)) continue;
      const c = parseColor(v, () => n.parentElement ? this.color(n.parentElement) : BLACK);
      if (c) return c;
    }
    return BLACK;
  }
  ownBackground(el) {
    const bc = this.declared(el, 'background-color');
    const bs = this.declared(el, 'background');
    const pick = bs && (!bc || cmpRank(bs.rank, bc.rank) > 0) ? bs : bc;
    if (!pick) return null;
    const v = this.subst(el, pick.value);
    if (/gradient\(|url\(/i.test(v)) {
      // Verlauf als Fläche: nicht berechenbar → Farbe des Verlaufs ignorieren, aber eine Farbe davor gilt
      const head = v.split(/\s+(?=[a-z-]*gradient\(|url\()/i)[0];
      return parseColor(head) || 'unknown';
    }
    return parseColor(v) || parseColor(v.split(/\s+/).find(tok => parseColor(tok)) || '') || null;
  }
  background(el) {
    const layers = [];
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const c = this.ownBackground(n);
      if (c === 'unknown') return null;
      if (c && c.a > 0) { layers.push(c); if (c.a >= 1) break; }
    }
    let base = layers.length && layers[layers.length - 1].a >= 1 ? layers.pop() : WHITE;
    while (layers.length) base = over(layers.pop(), base);
    return base;
  }
  opacity(el) {
    let o = 1;
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const v = this.resolved(n, 'opacity');
      if (v != null && !isNaN(parseFloat(v))) o *= parseFloat(v) / (v.trim().endsWith('%') ? 100 : 1);
    }
    return o;
  }
  fontSize(el) {
    const chain = [];
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) chain.unshift(n);
    let px = 16;
    for (const n of chain) {
      const v = this.resolved(n, 'font-size');
      if (!v) continue;
      const m = v.match(/^([\d.]+)(px|rem|em|%)$/);
      if (!m) continue;
      const x = parseFloat(m[1]);
      px = m[2] === 'px' ? x : m[2] === 'rem' ? x * 16 : m[2] === 'em' ? x * px : x / 100 * px;
    }
    return px;
  }
  fontWeight(el) {
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const v = this.resolved(n, 'font-weight');
      if (!v || v === 'inherit') continue;
      if (v === 'bold' || v === 'bolder') return 700;
      if (v === 'normal' || v === 'lighter') return 400;
      if (!isNaN(parseInt(v, 10))) return parseInt(v, 10);
    }
    return 400;
  }
  display(el) { return this.resolved(el, 'display'); }
}

const BLACK = { r: 0, g: 0, b: 0, a: 1 };
const WHITE = { r: 255, g: 255, b: 255, a: 1 };
const NAMED = { white: WHITE, black: BLACK, transparent: { r: 0, g: 0, b: 0, a: 0 }, red: { r: 255, g: 0, b: 0, a: 1 } };
function parseColor(v, current) {
  if (v == null) return null;
  const s = String(v).trim().toLowerCase();
  if (!s) return null;
  if (NAMED[s]) return NAMED[s];
  if (s === 'currentcolor' && current) return current();
  let m = s.match(/^#([0-9a-f]{3,8})$/);
  if (m) {
    let h = m[1];
    if (h.length === 3 || h.length === 4) h = [...h].map(x => x + x).join('');
    return { r: parseInt(h.slice(0, 2), 16), g: parseInt(h.slice(2, 4), 16), b: parseInt(h.slice(4, 6), 16), a: h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1 };
  }
  m = s.match(/^rgba?\((.*)\)$/);
  if (m) {
    const p = m[1].split(/[\s,/]+/).filter(Boolean).map(x => x.endsWith('%') ? parseFloat(x) / 100 : parseFloat(x));
    if (p.length < 3 || p.some(isNaN)) return null;
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  }
  m = s.match(/^color-mix\(\s*in\s+srgb\s*,(.*)\)$/);
  if (m) {
    const args = splitTop(m[1], ',').map(x => x.trim());
    if (args.length !== 2) return null;
    const pc = args.map(a => { const mm = a.match(/\s([\d.]+)%$/); return { col: parseColor(mm ? a.slice(0, mm.index) : a, current), p: mm ? parseFloat(mm[1]) / 100 : null }; });
    if (!pc[0].col || !pc[1].col) return null;
    let [p1, p2] = [pc[0].p, pc[1].p];
    if (p1 == null && p2 == null) { p1 = 0.5; p2 = 0.5; } else if (p1 == null) p1 = 1 - p2; else if (p2 == null) p2 = 1 - p1;
    const sum = p1 + p2; p1 /= sum; p2 /= sum;
    const A = pc[0].col, B = pc[1].col;
    const a = A.a * p1 + B.a * p2;
    if (a === 0) return { r: 0, g: 0, b: 0, a: 0 };
    const ch = (k) => (A[k] * A.a * p1 + B[k] * B.a * p2) / a;
    return { r: ch('r'), g: ch('g'), b: ch('b'), a };
  }
  return null;
}
function over(top, base) {
  const a = top.a + base.a * (1 - top.a);
  const ch = (k) => (top[k] * top.a + base[k] * base.a * (1 - top.a)) / (a || 1);
  return { r: ch('r'), g: ch('g'), b: ch('b'), a };
}
const hex = (c) => '#' + [c.r, c.g, c.b].map(x => Math.max(0, Math.min(255, Math.round(x))).toString(16).padStart(2, '0')).join('');

// ═════════════════════════════════════════════════════════════════════
// Hilfen am DOM
// ═════════════════════════════════════════════════════════════════════
const HAS_TEXT = /[\p{L}\p{N}]/u;
function visibleText(node) {
  let s = '';
  for (const c of node.childNodes) {
    if (c.nodeType === 3) s += c.textContent;
    else if (c.nodeType === 1 && c.getAttribute('aria-hidden') !== 'true' && !['SCRIPT', 'STYLE', 'TEMPLATE'].includes(c.tagName)) {
      if (c.tagName === 'IMG') s += c.getAttribute('alt') || '';
      else if (c.tagName === 'svg' || c.tagName === 'SVG') s += c.querySelector('title')?.textContent || '';
      else s += visibleText(c);
    }
  }
  return s.replace(/\s+/g, ' ').trim();
}
function byIds(doc, ids) { return String(ids || '').split(/\s+/).filter(Boolean).map(id => doc.getElementById(id)); }
/** Zugänglicher Name nach accname (vereinfacht): aria-labelledby, aria-label, <label>, title. */
function accName(el) {
  const doc = el.ownerDocument;
  const lb = el.getAttribute('aria-labelledby');
  if (lb) { const s = byIds(doc, lb).map(n => n ? visibleText(n) : '').join(' ').trim(); if (s) return s; }
  const al = (el.getAttribute('aria-label') || '').trim();
  if (al) return al;
  if (['INPUT', 'SELECT', 'TEXTAREA', 'METER', 'PROGRESS', 'OUTPUT'].includes(el.tagName)) {
    if (el.tagName === 'INPUT' && ['button', 'submit', 'reset'].includes(el.type)) return (el.value || '').trim();
    for (const label of el.labels || []) {
      const clone = label.cloneNode(true);
      clone.querySelectorAll('input, select, textarea, [aria-hidden="true"]').forEach(n => n.remove());
      const s = clone.textContent.replace(/\s+/g, ' ').trim();
      if (s) return s;
    }
  } else {
    const s = visibleText(el);
    if (s) return s;
  }
  return (el.getAttribute('title') || '').trim();
}
const describe = (el) => {
  if (!el) return '–';
  const cls = [...el.classList].slice(0, 2).map(c => '.' + c).join('');
  const id = el.id ? '#' + el.id : '';
  const name = (el.getAttribute('name') ? `[name=${el.getAttribute('name')}]` : '') + (el.dataset?.act ? `[data-act=${el.dataset.act}]` : '');
  return `${el.tagName.toLowerCase()}${id}${cls}${name}`;
};

const freePort = () => new Promise((res, rej) => {
  const s = createServer();
  s.once('error', rej);
  s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); });
});

// ═════════════════════════════════════════════════════════════════════
(async () => {
  const started = Date.now();
  const dataDir = mkdtempSync(join(tmpdir(), 'et-a11y-'));
  for (const entry of readdirSync(join(ROOT, 'demo-data'))) {
    if (['personas', 'sources', 'translations.json', 'energietracker-demo-backup.json', 'README.md'].includes(entry)) continue;
    cpSync(join(ROOT, 'demo-data', entry), join(dataDir, entry), { recursive: true });
  }
  const settingsFile = join(dataDir, 'settings.json');
  const settings = JSON.parse(readFileSync(settingsFile, 'utf8'));
  // Kein Wetterabgleich (ginge ins Netz), kein Assistent beim Start, alle Seiten sichtbar
  // Miete: auch die Seite „Mietverhältnis“ mit ihren Dialogen
  writeFileSync(settingsFile, JSON.stringify({ ...settings, weather_auto_fill: false, setup_pending: false, ui_level: 'expert', update_check: false, wohnverhaeltnis: 'miete' }));

  const port = Number(process.env.ET_A11Y_PORT) || await freePort();
  const proc = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: ROOT, env: { ...process.env, ET_DATA_DIR: dataDir, ET_LOG_DEST: 'null' }, stdio: 'ignore',
  });
  const BASE = `http://127.0.0.1:${port}/`;
  const watchdog = setTimeout(() => { console.log('  ✗ Zeitlimit (15 min) überschritten'); proc.kill(); process.exit(1); }, 15 * 60 * 1000);
  try {
    let up = false;
    for (let i = 0; i < 100 && !up; i++) {
      try { up = (await fetch(BASE + 'api/health')).ok; } catch { await sleep(100); }
    }
    t('Server mit Beispieldaten läuft', up);

    const html = await (await fetch(BASE)).text();
    const vc = new VirtualConsole();   // „Not implemented: navigation/window.print“ (Downloads, Drucken) stumm
    const dom = new JSDOM(html, { url: BASE + '#/dashboard', pretendToBeVisual: true, virtualConsole: vc });
    const w = dom.window;
    const doc = w.document;
    for (const k of ['window', 'document', 'location', 'history', 'HTMLElement', 'Element', 'Node', 'CustomEvent', 'Event',
      'getComputedStyle', 'localStorage', 'sessionStorage', 'MouseEvent', 'KeyboardEvent', 'requestAnimationFrame', 'MutationObserver']) {
      try { global[k] = w[k]; } catch { Object.defineProperty(global, k, { value: w[k], configurable: true, writable: true }); }
    }
    Object.defineProperty(global, 'navigator', { value: w.navigator, configurable: true, writable: true });

    // ── Netz: nur lesen ──
    let inflight = 0;
    const blocked = [];
    const nativeFetch = global.fetch;
    const OUTBOUND = /\/api\/(geocode|update-check|weather|temperatures\/sync|smard|market|ocr|evcc\/(fetch|sync))/;
    const browserFetch = async (input, init) => {
      let url = typeof input === 'string' ? input : input?.url;
      if (!/^https?:\/\//i.test(url)) url = new URL(url, w.location.href).href;
      const method = (init?.method || 'GET').toUpperCase();
      if (!url.startsWith(BASE) || (method !== 'GET' && method !== 'HEAD' && !/dry_run=1/.test(url)) || OUTBOUND.test(url)) {
        blocked.push(`${method} ${url.replace(BASE, '/')}`);
        return new Response(JSON.stringify({ success: false, error: 'Test: nur lesen', code: 'errors.test.readOnly' }),
          { status: 503, headers: { 'Content-Type': 'application/json' } });
      }
      inflight++;
      try {
        const r = await nativeFetch(url, init);
        const buf = await r.arrayBuffer();
        const empty = [101, 204, 205, 304].includes(r.status) || method === 'HEAD';
        return new Response(empty ? null : buf, { status: r.status, statusText: r.statusText, headers: r.headers });
      } finally { inflight--; }
    };
    w.fetch = browserFetch;
    global.fetch = browserFetch;
    let touch = false;
    w.matchMedia = (q) => ({ matches: touch && /coarse/.test(q), media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    w.scrollTo = () => {};
    w.Element.prototype.scrollIntoView = function () {};
    const Chart = function (canvas, config) { return { canvas, config, destroy() {}, update() {}, resize() {}, data: config?.data || {}, options: config?.options || {} }; };
    Chart.defaults = { color: '', borderColor: '', font: { family: '', size: 12 },
      plugins: { legend: { labels: {} }, tooltip: {} }, scale: { grid: {}, ticks: {} } };
    Chart.register = () => {};
    w.Chart = Chart; global.Chart = Chart;
    w.confirm = () => false; global.confirm = w.confirm;
    w.alert = () => {}; w.prompt = () => null; w.print = () => {}; w.open = () => null;
    process.on('unhandledRejection', () => {});

    // ── Stylesheets der App (aus index.php) + Laufzeit-<style> (Farben der Verbrauchsarten) ──
    const sheets = [...html.matchAll(/<link rel="stylesheet" href="(public\/css\/[a-z-]+\.css)/g)].map(m => m[1]).filter(f => !f.endsWith('print.css'));
    const sheetTexts = sheets.map(f => readFileSync(join(ROOT, f), 'utf8'));
    let cascade = null;
    const freshCascade = () => {
      cascade = new Cascade([...sheetTexts, ...[...doc.querySelectorAll('style')].map(s => s.textContent)]);
      return cascade;
    };

    // ── Layout-Ersatz: dargestellt = verbunden, nicht [hidden], nicht display:none, nicht in zugeklappten <details> ──
    const renderedCache = new WeakMap();
    let layoutEpoch = 0;
    const rendered = (el) => {
      if (!el?.isConnected) return false;
      const hit = renderedCache.get(el);
      if (hit && hit.epoch === layoutEpoch) return hit.v;
      let v = true;
      for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
        if (n.hidden || n.tagName === 'TEMPLATE' || (n.tagName === 'INPUT' && n.type === 'hidden')) { v = false; break; }
        if (n.style?.display === 'none') { v = false; break; }
        const p = n.parentElement;
        if (p?.tagName === 'DETAILS' && !p.open && !(n.tagName === 'SUMMARY' && p.querySelector(':scope > summary') === n)) { v = false; break; }
        if (cascade && cascade.display(n) === 'none') { v = false; break; }
      }
      renderedCache.set(el, { epoch: layoutEpoch, v });
      return v;
    };
    w.Element.prototype.getClientRects = function () {
      return rendered(this) ? [{ x: 0, y: 0, width: 10, height: 10, top: 0, left: 0, right: 10, bottom: 10 }] : [];
    };
    const mo = new w.MutationObserver(() => { layoutEpoch++; cascade?.reset(); });
    mo.observe(doc, { subtree: true, childList: true, attributes: true });

    const settle = async (min = 20) => {
      await sleep(min);
      let quiet = 0;
      for (let i = 0; i < 400; i++) {
        if (inflight === 0 && !doc.querySelector('#view .loading')) { if (++quiet >= 2) return; } else quiet = 0;
        await sleep(15);
      }
    };
    const press = (key, shiftKey = false) => {
      const target = doc.activeElement || doc.body;
      const ev = new w.KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true });
      target.dispatchEvent(ev);
      return ev;
    };
    const backdrops = () => [...doc.querySelectorAll('#modal-root > .modal-backdrop')];

    await import(pathToFileURL(join(ROOT, 'public/js/app.js')).href);
    await settle(200);
    t('App startet', !!doc.querySelector('#view .view-header, #view h1'));
    freshCascade();
    const modal = await import(pathToFileURL(join(ROOT, 'public/js/components/modal.js')).href);

    // ═══ Prüfungen je Dialog ═══
    const V = {
      role: [], title: [], heading: [], describe: [], inert: [], focusIn: [], focusClose: [], trap: [], escape: [],
      back: [], labels: [], names: [], refs: [], ids: [], errLive: [], errLinked: [], errInvalid: [], light: [], dark: [],
    };
    const checked = new Set();
    let dialogs = 0, backChecks = 0, trapChecks = 0, errorChecks = 0, contrastChecks = 0;
    const titleOf = (m) => {
      const ids = m?.getAttribute('aria-labelledby');
      return (ids && byIds(doc, ids).map(n => n?.textContent || '').join(' ').trim()) || m?.querySelector('.modal__title')?.textContent?.trim() || '(ohne Titel)';
    };

    // Tab-Reihenfolge nach HTML (eigene Fassung, unabhängig von modal.js)
    const FOCUSABLE = 'a[href], area[href], button, input, select, textarea, summary, iframe, object, embed, audio[controls], video[controls], [contenteditable], [tabindex]';
    const tabOrder = (container) => {
      const seen = new Map();
      const out = [];
      for (const el of container.querySelectorAll(FOCUSABLE)) {
        if (el.disabled || el.type === 'hidden' || el.closest('[inert], fieldset[disabled]')) continue;
        if (el.hasAttribute('tabindex') && parseInt(el.getAttribute('tabindex'), 10) < 0) continue;
        if (el.getAttribute('contenteditable') === 'false') continue;
        if (el.tagName === 'SUMMARY' && el.parentElement?.querySelector(':scope > summary') !== el) continue;
        if (!rendered(el)) continue;
        if (el.type === 'radio' && el.name) {
          const g = seen.get(el.name);
          if (g) { if (el.checked) out[g] = el; continue; }
          seen.set(el.name, out.length);
        }
        out.push(el);
      }
      return out;
    };

    function checkStatic(m, label) {
      const role = m.getAttribute('role');
      if (!['dialog', 'alertdialog'].includes(role) || m.getAttribute('aria-modal') !== 'true') V.role.push(`${label}: role=${role} aria-modal=${m.getAttribute('aria-modal')}`);
      const lb = byIds(doc, m.getAttribute('aria-labelledby'));
      if (!lb.length || lb.some(n => !n || !m.contains(n)) || !lb.map(n => n?.textContent || '').join('').trim()) V.title.push(label);
      else if (!lb.some(n => /^H[1-6]$/.test(n.tagName) || n.getAttribute('role') === 'heading')) V.heading.push(label);
      if (role === 'alertdialog') {
        const ds = byIds(doc, m.getAttribute('aria-describedby'));
        if (!ds.length || ds.some(n => !n) || !ds.map(n => n.textContent).join('').trim()) V.describe.push(label);
      }
      if (!doc.getElementById('app')?.hasAttribute('inert')) V.inert.push(label);
      // Felder
      for (const el of m.querySelectorAll('input:not([type="hidden"]), select, textarea')) {
        // Ausgenommen: für Bildschirmleser verborgen, Dateiauswahl hinter einem Knopf
        if (el.closest('[aria-hidden="true"]') || (el.type === 'file' && !rendered(el))) continue;
        if (!accName(el)) V.labels.push(`${label}: ${describe(el)}${el.placeholder ? ' (nur Platzhalter)' : ''}`);
      }
      for (const el of m.querySelectorAll('button, a[href], [role="button"], summary')) {
        if (!accName(el)) V.names.push(`${label}: ${describe(el)}`);
      }
      // Verweise und IDs
      for (const el of [m, ...m.querySelectorAll('[aria-labelledby], [aria-describedby], [aria-controls], [aria-errormessage], label[for]')]) {
        for (const attr of ['aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-errormessage', 'for']) {
          if (attr === 'for' && el.tagName !== 'LABEL') continue;
          for (const id of String(el.getAttribute(attr) || '').split(/\s+/).filter(Boolean)) {
            if (!doc.getElementById(id)) V.refs.push(`${label}: ${describe(el)} ${attr}=${id}`);
          }
        }
      }
      for (const el of m.querySelectorAll('[id]')) {
        if (doc.querySelectorAll(`[id="${el.id.replace(/"/g, '\\"')}"]`).length > 1) V.ids.push(`${label}: #${el.id}`);
      }
      // Fehlermeldungen: Live-Region
      for (const el of m.querySelectorAll('.field-error, .form-error, [data-role$="-msg"], [data-role="error"]')) {
        const live = ['alert', 'status'].includes(el.getAttribute('role')) || ['polite', 'assertive'].includes(el.getAttribute('aria-live'));
        if (!live) V.errLive.push(`${label}: ${describe(el)}`);
      }
    }

    function checkVisibleErrors(m, label) {
      for (const el of m.querySelectorAll('.field-error, .form-error, [data-role$="-msg"], [data-role="error"]')) {
        if (!rendered(el) || !HAS_TEXT.test(el.textContent)) continue;
        errorChecks++;
        const users = el.id ? [...m.querySelectorAll('[aria-describedby]')].filter(c => c.getAttribute('aria-describedby').split(/\s+/).includes(el.id)) : [];
        if (!users.length) {
          V.errLinked.push(`${label}: „${el.textContent.trim().slice(0, 40)}“ (${describe(el)})`);
          continue;
        }
        const fields = users.filter(c => c.matches('input, select, textarea'));
        if (fields.length && !fields.some(c => c.getAttribute('aria-invalid') === 'true')) V.errInvalid.push(`${label}: ${describe(fields[0])}`);
      }
    }

    function checkContrast(m, label) {
      const root = doc.documentElement;
      const before = root.getAttribute('data-theme');
      for (const theme of ['light', 'dark']) {
        root.setAttribute('data-theme', theme);
        const c = freshCascade();
        layoutEpoch++;
        const seen = new Set();
        for (const el of [m, ...m.querySelectorAll('*')]) {
          if (['OPTION', 'SCRIPT', 'STYLE', 'TEMPLATE', 'BR', 'svg'].includes(el.tagName) || el.closest('svg')) continue;
          const isErr = el.matches('.field-error, [role="alert"]');
          if (!isErr && !rendered(el)) continue;
          if (el.disabled || el.closest('fieldset[disabled], button:disabled, [aria-disabled="true"]')) continue;
          const own = [...el.childNodes].some(n => n.nodeType === 3 && HAS_TEXT.test(n.textContent));
          const field = el.matches('input:not([type="checkbox"], [type="radio"], [type="range"], [type="color"], [type="file"], [type="hidden"]), select, textarea');
          if (!own && !field && !isErr) continue;
          const bg = c.background(el);
          if (!bg) continue;
          let fg = c.color(el);
          const op = c.opacity(el);
          if (fg.a < 1) fg = over(fg, bg);
          if (op < 1) fg = over({ ...fg, a: op }, bg);
          const ratio = contrast(hex(fg), hex(bg));
          const size = c.fontSize(el);
          const large = size >= 24 || (size >= 18.66 && c.fontWeight(el) >= 700);
          contrastChecks++;
          if (ratio + 1e-9 < (large ? 3 : 4.5)) {
            const key = `${describe(el)} ${hex(fg)}/${hex(bg)}`;
            if (seen.has(key)) continue;
            seen.add(key);
            V[theme].push(`${label}: ${describe(el)} „${(el.textContent || el.value || '').trim().slice(0, 24)}“ ${ratio.toFixed(2)}:1 (${hex(fg)} auf ${hex(bg)})`);
          }
        }
      }
      if (before == null) root.removeAttribute('data-theme'); else root.setAttribute('data-theme', before);
      freshCascade();
      layoutEpoch++;
    }

    /** Prüft den obersten Dialog; schließt ihn per Escape. */
    async function checkDialog(bd, trigger, depth, where) {
      const m = bd.querySelector('.modal') || bd.firstElementChild;
      const title = titleOf(m);
      const label = `„${title}“`;
      const key = `${m.getAttribute('role')}|${title}`;
      const fresh = !checked.has(key);
      if (fresh) {
        checked.add(key);
        dialogs++;
        checkStatic(m, label);
        checkContrast(m, label);
        // Fokus beim Öffnen
        const a = doc.activeElement;
        if (!a || !m.contains(a)) V.focusIn.push(`${label} (${where}) → ${describe(a)}`);
        else if (a.classList.contains('modal__close') && tabOrder(m).some(el => !el.classList.contains('modal__close'))) V.focusClose.push(label);
        // Fokusfalle
        const order = tabOrder(m);
        if (order.length >= 2) {
          trapChecks++;
          const keep = doc.activeElement;
          order[order.length - 1].focus();
          press('Tab');
          const fwd = doc.activeElement === order[0];
          order[0].focus();
          press('Tab', true);
          const bwd = doc.activeElement === order[order.length - 1];
          if (!fwd || !bwd) V.trap.push(`${label}: ${fwd ? '' : `Tab vom letzten (${describe(order[order.length - 1])}) → ${describe(doc.activeElement)}`}${bwd ? '' : ' Umschalt+Tab vom ersten'}`);
          keep?.focus?.();
        }
      }
      // Knöpfe im Dialog: Rückfragen und Fehlermeldungen (nur in der obersten Ebene)
      if (fresh && depth === 0) {
        const sigOf = (el) => [el.tagName, el.dataset.act || '', el.dataset.action || '', el.dataset.role || '', el.className, accName(el)].join('|');
        const sigs = [...new Set([...m.querySelectorAll('button, a[data-act], a[data-action]')]
          .filter(el => !el.classList.contains('modal__close') && el.dataset.act !== 'cancel' && rendered(el) && !el.disabled)
          .map(sigOf))];
        for (const sig of sigs.slice(0, 30)) {
          if (!bd.isConnected) break;
          const el = [...m.querySelectorAll('button, a[data-act], a[data-action]')].find(x => sigOf(x) === sig && rendered(x) && !x.disabled);
          if (!el) continue;
          const n = backdrops().length;
          el.focus();
          const focused = doc.activeElement === el;
          try { el.click(); } catch { /* Fehler im Handler: zählt hier nicht */ }
          await settle();
          if (bd.isConnected) checkVisibleErrors(m, label);
          const now = backdrops();
          if (now.length > n) await checkDialog(now[now.length - 1], focused ? el : null, 1, `${where} → ${accName(el)}`);
          // Ein Klick hat einen weiteren Dialog geöffnet und offen gelassen
          for (const extra of backdrops().slice(backdrops().indexOf(bd) + 1)) { void extra; press('Escape'); await settle(10); }
        }
      }
      // Escape schließt, Fokus kehrt zurück
      if (!bd.isConnected) return;
      m.contains(doc.activeElement) || m.focus();
      press('Escape');
      await settle(10);
      if (bd.isConnected) { V.escape.push(label); modal.closeAllModals('navigation'); await settle(10); return; }
      if (trigger && trigger.isConnected && trigger !== doc.body) {
        backChecks++;
        if (doc.activeElement !== trigger && !V.back.some(x => x.startsWith(label))) V.back.push(`${label} (${where}): Fokus auf ${describe(doc.activeElement)} statt ${describe(trigger)}`);
      }
    }

    // ═══ Durch die App ═══
    let navSeq = 0;
    const go = async (route) => {
      const r = route + (route.includes('?') ? '&' : '?') + `a11y=${++navSeq}`;
      w.location.hash = r;
      await settle(40);
      for (const bd of backdrops()) await checkDialog(bd, null, 0, `${route} beim Laden`);
      modal.closeAllModals('navigation');
      doc.querySelectorAll('#view details:not([open])').forEach(d => { d.open = true; });
      layoutEpoch++;
    };
    const normRoute = (href) => {
      const h = (href || '').replace(/^[^#]*/, '');
      const path = h.split('?')[0];
      if (!/^#\/[a-z]/.test(path) || path.startsWith('#/help/doc')) return null;
      return path;
    };
    // Auslöser: jeder Knopf; Links, die etwas tun (Knopf-Optik, data-Attribut, Download) —
    // ohne Navigationsleisten, neue Tabs und fremde Adressen
    const triggerSel = '#view button, #view a[href]';
    const isTrigger = (el) => {
      if (!rendered(el) || el.disabled) return false;
      if (el.tagName !== 'A') return true;
      if (el.closest('nav, [role="tablist"]') || el.target === '_blank' || /^https?:/i.test(el.getAttribute('href'))) return false;
      return el.classList.contains('btn') || el.hasAttribute('download') || [...el.attributes].some(a => a.name.startsWith('data-'));
    };
    const sigOf = (el) => [el.tagName, el.dataset.act || '', el.dataset.action || '', el.dataset.role || '', el.className, accName(el).slice(0, 40)].join('|');

    const routes = ['#/dashboard'];
    const done = new Set();
    const discover = () => {
      for (const a of doc.querySelectorAll('#primary-nav a[href^="#/"], #view a[href^="#/"], .sidebar__footer a[href^="#/"]')) {
        const p = normRoute(a.getAttribute('href'));
        if (p && !routes.includes(p) && routes.length < 80) routes.push(p);
      }
    };
    discover();
    let clicks = 0;
    // ＋ Erfassen in der Kopfleiste (außerhalb der Ansicht)
    {
      const cap = doc.querySelector('[data-action="open-capture"]');
      cap?.focus();
      cap?.click();
      await settle();
      const bd = backdrops()[0];
      if (bd) await checkDialog(bd, cap, 0, 'Kopfleiste');
      modal.closeAllModals('navigation');
    }
    for (let ri = 0; ri < routes.length; ri++) {
      const route = routes[ri];
      if (done.has(route)) continue;
      done.add(route);
      await go(route);
      discover();
      const sigs = [...new Set([...doc.querySelectorAll(triggerSel)].filter(isTrigger).map(sigOf))].slice(0, 70);
      if (DEBUG) console.log(`  · ${route}: ${sigs.length} Auslöser — ${sigs.map(s => s.split('|').slice(1, 2).concat(s.split('|')[5]).join(':')).join(', ')}`);
      for (const sig of sigs) {
        let el = [...doc.querySelectorAll(triggerSel)].find(x => isTrigger(x) && sigOf(x) === sig);
        if (!el) { await go(route); el = [...doc.querySelectorAll(triggerSel)].find(x => isTrigger(x) && sigOf(x) === sig); }
        if (!el) continue;
        const hash = w.location.hash;
        const n = backdrops().length;
        el.focus();
        const trigger = doc.activeElement === el ? el : null;
        clicks++;
        try { el.click(); } catch { /* Fehler im Handler: zählt hier nicht */ }
        await settle();
        const now = backdrops();
        if (now.length > n) await checkDialog(now[now.length - 1], trigger, 0, `${route} → ${accName(el).slice(0, 30) || describe(el)}`);
        modal.closeAllModals('navigation');
        if (w.location.hash !== hash) await go(route);
      }
    }
    console.log(`  · ${done.size} Seiten, ${clicks} Klicks, ${blocked.length} Anfragen abgefangen (nur lesen), ${Math.round((Date.now() - started) / 1000)} s`);

    const MIN_DIALOGS = 25;
    t(`Dialoge gefunden und geprüft (mindestens ${MIN_DIALOGS})`, dialogs >= MIN_DIALOGS, `${dialogs} Dialoge: ${[...checked].map(k => k.split('|')[1]).slice(0, DEBUG ? 999 : 40).join(' · ')}`);
    t('Rolle dialog/alertdialog und aria-modal', !V.role.length, list(V.role));
    t('Titel per aria-labelledby (im Dialog, mit Text)', !V.title.length, list(V.title));
    t('Titel ist eine Überschrift', !V.heading.length, list(V.heading));
    t('Rückfrage (alertdialog) mit Beschreibung per aria-describedby', !V.describe.length, list(V.describe));
    t('Hintergrund (#app) inert, solange ein Dialog offen ist', !V.inert.length, list(V.inert));
    t('Fokus beim Öffnen im Dialog', !V.focusIn.length, list(V.focusIn));
    t('Startfokus nicht auf „×“, wenn es Felder oder Knöpfe gibt', !V.focusClose.length, list(V.focusClose));
    t(`Fokusfalle: Tab vom letzten aufs erste, Umschalt+Tab vom ersten aufs letzte (${trapChecks} Dialoge)`, trapChecks >= 10 && !V.trap.length, list(V.trap));
    t('Escape schließt den obersten Dialog', !V.escape.length, list(V.escape));
    t(`Fokus kehrt nach dem Schließen zum Auslöser zurück (${backChecks} Fälle)`, backChecks >= 10 && !V.back.length, list(V.back));
    t('Jedes Feld beschriftet (label, aria-label, aria-labelledby; Platzhalter zählt nicht)', !V.labels.length, list(V.labels, 12));
    t('Jeder Knopf und Link mit Namen', !V.names.length, list(V.names, 12));
    t('aria-Verweise und label[for] zeigen auf vorhandene IDs', !V.refs.length, list(V.refs));
    t('IDs in Dialogen eindeutig im Dokument', !V.ids.length, list(V.ids));
    t('Fehlermeldungen sind Live-Regionen (role="alert"/aria-live)', !V.errLive.length, list(V.errLive, 12));
    t(`Sichtbare Fehlermeldung per aria-describedby am Feld (${errorChecks} Meldungen)`, errorChecks >= 3 && !V.errLinked.length, list(V.errLinked));
    t('Feld mit sichtbarer Fehlermeldung trägt aria-invalid="true"', !V.errInvalid.length, list(V.errInvalid));
    t(`Kontrast hell (WCAG AA, ${contrastChecks} Prüfungen beide Themes)`, contrastChecks > 100 && !V.light.length, list(V.light, 12));
    t('Kontrast dunkel (WCAG AA)', contrastChecks > 100 && !V.dark.length, list(V.dark, 12));

    // ═══ Mechanik von modal.js an eigenen Dialogen ═══
    console.log('modal.js');
    await go('#/help');
    const view = doc.getElementById('view');
    const btn = (id) => { const b = doc.createElement('button'); b.type = 'button'; b.id = id; b.textContent = id; view.appendChild(b); return b; };
    const formBody = `<div class="fgrid"><div class="field"><label for="u-a">Name</label><input class="input" id="u-a" aria-describedby="u-a-msg"><div class="field-error" id="u-a-msg" role="alert" hidden></div></div>
      <details class="perm3"><summary>Mehr</summary><a href="#/help">Hilfe</a></details>
      <fieldset class="fgroup"><legend>Art</legend><label class="check"><input type="radio" name="u-r" value="1"> Eins</label><label class="check"><input type="radio" name="u-r" value="2" checked> Zwei</label></fieldset>
      <button type="button" tabindex="-1" id="u-skip">nicht erreichbar</button><span tabindex="0" id="u-last">Ende</span></div>`;
    const foot = `<button type="button" class="btn btn--ghost" data-act="cancel">Abbrechen</button><button type="button" class="btn btn--primary" data-act="ok">OK</button>`;

    let b = btn('u-trigger');
    b.focus();
    let ctrl = modal.openModal({ title: 'Formular', body: formBody, footer: foot });
    let m = ctrl.modalEl;
    t('Startfokus: erstes Feld im Inhalt (nicht „×“)', doc.activeElement === m.querySelector('#u-a'), describe(doc.activeElement));
    t('Titel: <h2> per aria-labelledby, Dialog fokussierbar nur per Skript', m.querySelector('#' + m.getAttribute('aria-labelledby'))?.tagName === 'H2' && m.getAttribute('tabindex') === '-1');
    const order = modal.tabbables(m).map(el => el.id || el.dataset.act || el.tagName.toLowerCase());
    t('Tab-Reihenfolge: <summary> drin; Inhalt zugeklappter <details>, tabindex=-1 und nicht gewähltes Optionsfeld draußen',
      JSON.stringify(order) === JSON.stringify(['button', 'u-a', 'summary', 'input', 'u-last', 'cancel', 'ok'])
      && modal.tabbables(m).find(el => el.type === 'radio')?.value === '2', order.join(','));
    m.querySelector('[data-act="ok"]').focus(); press('Tab');
    t('Fokusfalle: Tab vom letzten → „×“ (erstes)', doc.activeElement === m.querySelector('.modal__close'));
    press('Tab', true);
    t('Fokusfalle: Umschalt+Tab vom ersten → letztes', doc.activeElement === m.querySelector('[data-act="ok"]'));
    b.focus(); press('Tab');
    t('Fokusfalle: Tab mit Fokus außerhalb → ins erste Element', m.contains(doc.activeElement));
    t('#app inert, solange offen', doc.getElementById('app').hasAttribute('inert'));
    // verschachtelt: Rückfrage über dem Formular
    const inner = m.querySelector('#u-a');
    inner.focus();
    let p = modal.confirmModal({ title: 'Wirklich?', message: 'Eintrag löschen?', confirmLabel: 'Löschen', danger: true });
    const top = backdrops().at(-1).querySelector('.modal');
    t('Rückfrage: alertdialog mit Beschreibung (die Frage)', top.getAttribute('role') === 'alertdialog' && doc.getElementById(top.getAttribute('aria-describedby'))?.textContent === 'Eintrag löschen?');
    t('Rückfrage: Startfokus auf „Abbrechen“', doc.activeElement === top.querySelector('[data-act="cancel"]'), describe(doc.activeElement));
    press('Escape');
    // nicht ewig warten, wenn Escape nicht schließt
    const answer = await Promise.race([p, sleep(1500).then(() => 'offen')]);
    t('Escape schließt nur die Rückfrage, Antwort „nein“', answer === false && backdrops().length === 1, String(answer));
    if (answer === 'offen') modal.closeAllModals('navigation');
    t('Fokus zurück ins Feld des Formulars', doc.activeElement === inner);
    press('Escape');
    await settle(10);
    t('Escape schließt das Formular, Fokus zurück zum Auslöser, #app nicht mehr inert', !backdrops().length && doc.activeElement === b && !doc.getElementById('app').hasAttribute('inert'));

    // onMount-Fokus bleibt, autofocus gewinnt vor dem ersten Feld
    b.focus();
    ctrl = modal.openModal({ title: 'Mit onMount', body: formBody, footer: foot, onMount({ modalEl }) { modalEl.querySelector('#u-last').focus(); } });
    t('Fokus aus onMount bleibt (überschrieb bis v3.6 „×“)', doc.activeElement?.id === 'u-last', describe(doc.activeElement));
    ctrl.close(null);
    ctrl = modal.openModal({ title: 'Autofokus', body: formBody.replace('id="u-last"', 'id="u-last" data-autofocus'), footer: foot });
    t('data-autofocus gewinnt', doc.activeElement?.id === 'u-last');
    ctrl.close(null);
    // Touch: kein Textfeld (Bildschirmtastatur), dafür der Dialog
    touch = true;
    ctrl = modal.openModal({ title: 'Touch', body: formBody, footer: foot });
    t('Touch: Startfokus auf dem Dialog, nicht im Textfeld', doc.activeElement === ctrl.modalEl);
    ctrl.close(null);
    ctrl = modal.openModal({ title: 'Touch Auswahl', body: '<label for="u-s">Jahr</label><select id="u-s"><option>1</option></select>', footer: foot });
    t('Touch: Auswahlfeld darf den Fokus bekommen', doc.activeElement?.id === 'u-s');
    ctrl.close(null);
    touch = false;
    // nur Text: Fußleiste, ohne Fußleiste der Dialog
    ctrl = modal.openModal({ title: 'Nur Text', body: '<p>Hinweis</p>', footer: foot });
    t('Nur Text: erster Knopf der Fußleiste', doc.activeElement?.dataset.act === 'cancel');
    ctrl.close(null);
    ctrl = modal.openModal({ title: 'Ohne alles', body: '<p>Hinweis</p>' });
    t('Ohne Feld und Fußleiste: „×“', doc.activeElement === ctrl.modalEl.querySelector('.modal__close'));
    ctrl.close(null);
    // Auslöser wird nach dem Schließen neu aufgebaut (Liste nach dem Speichern)
    view.insertAdjacentHTML('beforeend', '<div id="u-list"><button type="button" data-act="edit" data-id="x1">Bearbeiten</button></div>');
    view.querySelector('[data-id="x1"]').focus();
    ctrl = modal.openModal({ title: 'Neuaufbau', body: formBody, footer: foot });
    ctrl.close(true);
    view.querySelector('#u-list').innerHTML = '<button type="button" data-act="edit" data-id="x1">Bearbeiten</button>';
    await settle(20);
    t('Auslöser nach Neuaufbau: Fokus auf dem gleichen Knopf der neuen Liste', doc.activeElement === view.querySelector('[data-id="x1"]') && doc.activeElement.isConnected);
    view.querySelector('#u-list').innerHTML = '';
    view.insertAdjacentHTML('beforeend', '<button type="button" id="u-gone">weg</button>');
    view.querySelector('#u-gone').focus();
    ctrl = modal.openModal({ title: 'Auslöser weg', body: formBody, footer: foot });
    view.querySelector('#u-gone').remove();
    ctrl.close(null);
    t('Auslöser entfernt: Fokus auf den Inhaltsbereich statt <body>', doc.activeElement === view);
    // showFieldError verknüpft die Meldung
    const { showFieldError } = await import(pathToFileURL(join(ROOT, 'public/js/lib/form.js')).href);
    ctrl = modal.openModal({ title: 'Fehler', body: '<div class="field"><label for="u-x">Wert</label><input id="u-x"><div class="field-error" role="alert" hidden></div></div>' });
    const fx = ctrl.modalEl.querySelector('#u-x');
    const fm = ctrl.modalEl.querySelector('.field-error');
    showFieldError(fx, fm, 'Bitte eine Zahl');
    t('showFieldError: aria-invalid, Meldung per aria-describedby, Fokus im Feld', fx.getAttribute('aria-invalid') === 'true'
      && fx.getAttribute('aria-describedby')?.split(' ').includes(fm.id) && !!fm.id && doc.activeElement === fx && !fm.hidden);
    showFieldError(fx, fm, null);
    showFieldError(fx, fm, 'Noch einmal');
    t('showFieldError: Verknüpfung nur einmal, aufheben entfernt aria-invalid nur bis zum nächsten Fehler', fx.getAttribute('aria-describedby').split(' ').length === 1 && fx.getAttribute('aria-invalid') === 'true');
    ctrl.close(null);

    // Kontrast-Rechner gegen bekannte Werte (die Prüfung oben hängt an ihm)
    const probe = new Cascade([':root { --a: #111827; --t: #8590a6; } [data-theme="light"] { --a: #ffffff; --t: #566276; } .x { background: var(--a); color: var(--t); } .x .y { color: color-mix(in srgb, var(--t) 50%, transparent); }']);
    const host = doc.createElement('div'); host.className = 'x'; host.innerHTML = '<span class="y">Text</span>'; doc.body.appendChild(host);
    doc.documentElement.setAttribute('data-theme', 'light');
    probe.reset();
    const r1 = contrast(hex(probe.color(host)), hex(probe.background(host)));
    const y = host.querySelector('.y');
    const r2 = contrast(hex(over(probe.color(y), probe.background(y))), hex(probe.background(y)));
    t('Kontrast-Rechner: var() je Theme, Vererbung der Fläche, color-mix mit transparent', Math.abs(r1 - contrast('#566276', '#ffffff')) < 0.01 && r2 < r1 && r2 > 1, `${r1.toFixed(2)} / ${r2.toFixed(2)}`);
    host.remove();
  } catch (e) {
    t('Barrierefreiheit der Dialoge', false, e.stack || e.message);
  } finally {
    clearTimeout(watchdog);
    proc.kill();
    rmSync(dataDir, { recursive: true, force: true });
    console.log(`\na11y: ${pass} bestanden, ${fail} fehlgeschlagen`);
    process.exit(fail ? 1 : 0);
  }
})();
