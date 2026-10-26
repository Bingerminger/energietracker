// =====================================================================
// v3.7.0 (Posteingang) — PDFs im Browser: Textebene lesen und Seiten zeigen.
//
// pdf.js liegt mitgeliefert unter public/vendor/pdfjs/ (Apache-2.0, Version
// fest, s. CREDITS.md) — wie Chart.js ohne Server-Abhängigkeit, auf NAS,
// Docker und Home Assistant gleich. Geladen wird es erst, wenn der
// Posteingang ein Dokument braucht; die übrige App lädt es nie.
//
// Die Textebene geht je Seite an den Server (classify/extract). Gelesen wird
// sie so, wie das Dokument sie schreibt: ein Textstück nach dem anderen, ein
// Zeilenende als Zeilenumbruch, zwischen zwei Stücken einer Zeile ein
// Leerzeichen, wenn keines dasteht. Die Belegprüfung des Servers vergleicht
// Zitate gegen genau diesen Text.
// =====================================================================

import { escapeHtml } from './format.js';

let libPromise = null;

/** Version der App (Cache-Buster der mitgelieferten Dateien, wie die Import-Map). */
function appVersion() {
  return (typeof document !== 'undefined' && document.body?.getAttribute('data-app-version')) || 'dev';
}

/**
 * Kann dieser Browser pdf.js ausführen? (DOMMatrix und Worker — ohne beide
 * gibt es nur den Link auf die Datei, etwa im Test mit JSDOM.)
 */
export function pdfSupported() {
  return typeof window !== 'undefined' && typeof window.DOMMatrix === 'function'
    && typeof window.Worker === 'function' && typeof window.HTMLCanvasElement === 'function';
}

/** pdf.js laden (einmal). */
export function loadPdfjs() {
  if (!pdfSupported()) return Promise.reject(Object.assign(new Error('pdf.js unsupported'), { code: 'unsupported' }));
  libPromise ??= import(`../../vendor/pdfjs/pdf.min.js?v=${encodeURIComponent(appVersion())}`).then((lib) => {
    lib.GlobalWorkerOptions.workerSrc = new URL(`../../vendor/pdfjs/pdf.worker.min.js?v=${encodeURIComponent(appVersion())}`, import.meta.url).href;
    return lib;
  }).catch((e) => { libPromise = null; throw e; });
  return libPromise;
}

/**
 * Ein PDF öffnen. Die Bytes holt die App selbst (gleiche Anmeldung wie jede
 * Anfrage, in der Demo eine fertige Datei); pdf.js bekommt sie als Daten.
 * Ohne `eval` (Content-Security-Policy der App).
 */
export async function openPdf(url, { signal } = {}) {
  const lib = await loadPdfjs();
  const res = await fetch(url, { credentials: 'same-origin', signal });
  if (!res.ok) throw Object.assign(new Error(`HTTP ${res.status}`), { status: res.status });
  const data = new Uint8Array(await res.arrayBuffer());
  return lib.getDocument({ data, isEvalSupported: false, useSystemFonts: true, verbosity: 0 }).promise;
}

/**
 * v3.9.0 — Leerzeichen zwischen zwei Stücken ohne eigenes Leerzeichen ab
 * diesem Anteil der Schrifthöhe Abstand (eine Wortlücke ist rund ein Viertel).
 * In einer Folge einzelner Zeichen (gesperrt, oder Zeichen für Zeichen
 * gezeichnet) gilt das Doppelte des üblichen Abstands der Folge, mindestens
 * ein Zehntel der Höhe: „1 . 2 3 4 , 5 6 €“ mit 0,4 pt zwischen den Ziffern und
 * 1,4 pt vor dem Euro wird „1.234,56 €“.
 */
export const SPACE_GAP = 0.25;
const LETTER_GAP_FACTOR = 2;
const LETTER_GAP_MIN = 0.1;

/** Lage eines Stücks aus pdf.js (`transform`, `width`, `height`), sonst null. */
function placeOf(it) {
  const tr = it?.transform;
  if (!Array.isArray(tr) || tr.length < 6 || !tr.every(n => typeof n === 'number' && Number.isFinite(n))) return null;
  if (typeof it.width !== 'number' || !Number.isFinite(it.width)) return null;
  const h = typeof it.height === 'number' && it.height > 0 ? it.height : Math.abs(tr[3]) || Math.hypot(tr[2], tr[3]);
  return { x: tr[4], y: tr[5], w: it.width, h };
}

const blank = (it) => it.str.trim() === '';

/**
 * Eine Folge von Stücken (in Ausgabe-Reihenfolge) zu Text. Leerzeichen
 * zwischen zwei Stücken, wenn keines dasteht: mit Lage nach dem Abstand, ohne
 * Lage immer (so las die App bis v3.8 jede Zeile).
 */
function joinRun(seq) {
  const one = (it) => [...it.str].length === 1 && !blank(it);
  const single = [];
  for (let i = 0; i + 1 < seq.length; i++) {
    const a = seq[i], b = seq[i + 1];
    if (a.p && b.p && one(a) && one(b)) single.push(Math.max(0, b.p.x - (a.p.x + a.p.w)));
  }
  single.sort((x, y) => x - y);
  const letterGap = single.length >= 2 ? single[Math.floor((single.length - 1) / 2)] : null;
  let out = '';
  seq.forEach((it, i) => {
    out += it.str;
    const next = seq[i + 1];
    if (!next || it.str === '' || next.str === '' || /\s$/.test(it.str) || /^\s/.test(next.str)) return;
    if (!it.p || !next.p || it.p.w <= 0) { out += ' '; return; }
    const h = Math.max(it.p.h, next.p.h);
    if (!(h > 0)) { out += ' '; return; }
    let thr = SPACE_GAP * h;
    if (letterGap !== null && one(it) && one(next)) thr = Math.min(thr, Math.max(LETTER_GAP_MIN * h, LETTER_GAP_FACTOR * letterGap));
    if (next.p.x - (it.p.x + it.p.w) >= thr) out += ' ';
  });
  return out;
}

/**
 * Eine Stromzeile (bis zum Stück mit `hasEOL`). Mit Lage: in Läufen gleicher
 * Grundlinie (benachbarte Stücke, y innerhalb der halben Höhe); ein Lauf, der
 * nicht von links nach rechts geschrieben ist (das PDF zeichnet rechtsbündige
 * Zahlen Zeichen für Zeichen von rechts), wird nach x sortiert, reine
 * Leerzeichen-Stücke fallen dann heraus (ihre Lage passt nicht zur Reihenfolge).
 * Läufe nacheinander, nie über die Stromzeile hinaus: Spalten verschränken sich
 * nicht. Ohne Lage (Tests, ältere Aufrufer): wie bis v3.8.
 */
function joinLine(line) {
  const items = line.map(it => ({ str: it.str, p: placeOf(it) }));
  if (items.some(it => !blank(it) && it.p === null)) return joinRun(items.map(it => ({ str: it.str, p: null })));
  const runs = [];
  let run = null;
  for (const it of items) {
    if (blank(it)) { (run ??= (runs.push({ y: null, h: 0, items: [] }), runs[runs.length - 1])).items.push(it); continue; }
    const fits = run && run.y !== null && Math.abs(it.p.y - run.y) <= 0.5 * Math.max(it.p.h, run.h);
    if (run && (run.y === null || fits)) {
      if (run.y === null) { run.y = it.p.y; run.h = it.p.h; }
      run.items.push(it);
      run.h = Math.max(run.h, it.p.h);
      continue;
    }
    run = { y: it.p.y, h: it.p.h, items: [it] };
    runs.push(run);
  }
  return runs.map((r) => {
    const solid = r.items.filter(it => !blank(it));
    const ordered = solid.every((it, i) => i === 0 || it.p.x >= solid[i - 1].p.x);
    return joinRun(ordered ? r.items : solid.map((it, i) => [it, i]).sort((a, b) => a[0].p.x - b[0].p.x || a[1] - b[1]).map(a => a[0]));
  }).reduce((acc, s) => (acc === '' || s === '' || /\s$/.test(acc) || /^\s/.test(s) ? acc + s : `${acc} ${s}`), '');
}

/**
 * Textstücke einer Seite (pdf.js `getTextContent().items`) zu Text: Zeilenende
 * → Umbruch, sonst ein Leerzeichen zwischen zwei Stücken, wenn keines dasteht.
 * v3.9.0 — mit Lage (`transform`, `width`, `height`) nach der Lage: Stücke
 * einer Zeile von links nach rechts, Leerzeichen nach dem Abstand (joinLine).
 * Rein, ohne pdf.js — für Tests.
 *
 * @param {Array<{str: string, hasEOL?: boolean, transform?: number[], width?: number, height?: number}>} items
 */
export function joinTextItems(items) {
  const list = (items || []).filter(it => typeof it?.str === 'string');
  let out = '';
  let line = [];
  for (const it of list) {
    line.push(it);
    if (it.hasEOL) { out += `${joinLine(line)}\n`; line = []; }
  }
  if (line.length) out += joinLine(line);
  return out.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
}

/** Textebene aller Seiten (eine Zeichenkette je Seite), mit der Lage jedes Stücks gelesen (joinTextItems). */
export async function textPages(doc) {
  const pages = [];
  for (let i = 1; i <= doc.numPages; i++) {
    const page = await doc.getPage(i);
    const items = (await page.getTextContent()).items.map(it => ({ str: it.str, hasEOL: !!it.hasEOL, transform: it.transform, width: it.width, height: it.height }));
    pages.push(joinTextItems(items));
  }
  return pages;
}

/**
 * Seiten zeigen: je Seite eine Leinwand in `container`, gezeichnet, sobald sie
 * in Sicht kommt. `onPage(n, el)` meldet jede angelegte Seite (Sprungmarke).
 *
 * @returns {() => void} Aufräumen
 */
export function renderPages(doc, container, { width = 0, onPage = null, label = (n) => String(n) } = {}) {
  const pages = [];
  let gone = false;
  const draw = async (wrap, n) => {
    if (wrap.dataset.drawn) return;
    wrap.dataset.drawn = '1';
    const page = await doc.getPage(n);
    if (gone) return;
    const base = page.getViewport({ scale: 1 });
    const cssW = Math.max(200, width || wrap.clientWidth || 560);
    const ratio = Math.min(2, window.devicePixelRatio || 1);
    const vp = page.getViewport({ scale: (cssW / base.width) * ratio });
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(vp.width);
    canvas.height = Math.floor(vp.height);
    canvas.style.width = '100%';
    canvas.setAttribute('aria-hidden', 'true');
    wrap.querySelector('.pdf-page__ph')?.remove();
    wrap.prepend(canvas);
    await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport: vp }).promise.catch(() => {});
  };
  const io = typeof IntersectionObserver === 'function'
    ? new IntersectionObserver((entries) => entries.forEach(e => { if (e.isIntersecting) draw(e.target, Number(e.target.dataset.page)); }), { rootMargin: '400px' })
    : null;
  for (let n = 1; n <= doc.numPages; n++) {
    const wrap = document.createElement('figure');
    wrap.className = 'pdf-page';
    wrap.dataset.page = String(n);
    wrap.id = `pdf-page-${n}`;
    wrap.innerHTML = `<div class="pdf-page__ph"></div><figcaption class="pdf-page__no">${escapeHtml(label(n))}</figcaption>`;
    container.append(wrap);
    pages.push(wrap);
    onPage?.(n, wrap);
    if (io) io.observe(wrap); else draw(wrap, n);
  }
  return () => { gone = true; io?.disconnect(); };
}
