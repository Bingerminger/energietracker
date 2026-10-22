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
 * Textstücke einer Seite (pdf.js `getTextContent().items`) zu Text: Zeilenende
 * → Umbruch, sonst ein Leerzeichen zwischen zwei Stücken, wenn keines dasteht.
 * Rein, ohne pdf.js — für Tests.
 *
 * @param {Array<{str: string, hasEOL?: boolean}>} items
 */
export function joinTextItems(items) {
  let out = '';
  const list = (items || []).filter(it => typeof it?.str === 'string');
  list.forEach((it, i) => {
    out += it.str;
    if (it.hasEOL) { out += '\n'; return; }
    const next = list[i + 1];
    if (next && it.str !== '' && next.str !== '' && !/\s$/.test(it.str) && !/^\s/.test(next.str)) out += ' ';
  });
  return out.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
}

/** Textebene aller Seiten (eine Zeichenkette je Seite). */
export async function textPages(doc) {
  const pages = [];
  for (let i = 1; i <= doc.numPages; i++) {
    const page = await doc.getPage(i);
    pages.push(joinTextItems((await page.getTextContent()).items));
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
