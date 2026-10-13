// =====================================================================
// v3.4.0 (N1019) — Hilfe auf dem Server: eine Seite der Dokumentation.
//
// Route `#/help/doc/<pfad>?h=<anker>`. <pfad> ist der Schlüssel von
// GET /api/docs: `README.md`, `en/anleitungen/kalender.md`,
// `root/CHANGELOG.md`. Die Seite kommt vom eigenen Server in der Fassung
// dieses Release — auch ohne Internet, offline aus dem Service Worker — und
// wird hier gerendert (lib/markdown.js): mit Inhaltsverzeichnis, Umschalter
// Deutsch/Englisch und, für Französisch, Italienisch, Spanisch, Portugiesisch
// und Niederländisch, dem Hinweis, dass die Doku englisch ist. Tabellen und
// Code scrollen in sich (iPhone). Links in der Doku bleiben in der App; nur
// Dateien, die nicht zur Doku gehören (roadmap.md, Vorlagen), öffnen GitHub
// im Tag dieser Version.
// =====================================================================
import { t, getLocale, getLanguages } from '../lib/i18n.js';
import { escapeHtml } from '../lib/format.js';
import { api } from '../api.js';
import { renderMarkdown, ID_PREFIX } from '../lib/markdown.js';
import { docLinks, docRoute, docsInOtherLanguage } from '../lib/docs.js';

// Zuletzt gelesene Seiten: Ein Sprung von Seite zu Seite und zurück lädt nicht neu.
const CACHE = new Map();
const CACHE_MAX = 12;

async function load(key) {
  if (CACHE.has(key)) return CACHE.get(key);
  const page = await api.docPage(key);
  CACHE.set(key, page);
  if (CACHE.size > CACHE_MAX) CACHE.delete(CACHE.keys().next().value);
  return page;
}

/** Index des Handbuchs: in der Sprache der Seite, sonst in der der Oberfläche. */
function indexKey(lang) {
  const de = lang === 'de' || (lang !== 'en' && getLocale() === 'de');
  return de ? 'README.md' : 'en/README.md';
}

const appVersion = () => document.body?.getAttribute('data-app-version') || '';

/** Zur Sprungmarke `h` (ohne Präfix) scrollen, falls es sie auf der Seite gibt. */
function scrollToAnchor(container, anchor) {
  const el = container.ownerDocument?.getElementById(ID_PREFIX + anchor);
  if (!el || !container.contains(el)) return false;
  el.scrollIntoView?.({ block: 'start' });
  return true;
}

function barHtml(page, anchor) {
  const names = getLanguages();
  const name = (code) => escapeHtml(names[code] || code.toUpperCase());
  let langs = '';
  if (page.mirror && (page.lang === 'de' || page.lang === 'en')) {
    // immer Deutsch | English, die gezeigte Sprache markiert
    langs = `<span class="doc-langs" role="group" aria-label="${escapeHtml(t('help.doc.language'))}">
      ${['de', 'en'].map(code => (code === page.lang
        ? `<strong class="doc-langs__item doc-langs__item--current" aria-current="true" lang="${code}">${name(code)}</strong>`
        : `<a class="doc-langs__item" href="${escapeHtml(docRoute(page.mirror, anchor))}" lang="${code}" hreflang="${code}">${name(code)}</a>`)).join('')}
    </span>`;
  }
  return `<nav class="doc-bar" aria-label="${escapeHtml(t('help.doc.handbook'))}">
    <span class="doc-bar__path">
      <a href="#/help">${escapeHtml(t('help.title'))}</a>
      <span aria-hidden="true">›</span>
      <a href="${escapeHtml(docRoute(indexKey(page.lang)))}">${escapeHtml(t('help.doc.handbook'))}</a>
    </span>
    ${langs}
  </nav>`;
}

function tocHtml(page, headings) {
  const items = headings.filter(h => h.level === 2 || h.level === 3);
  if (items.length < 3) return '';
  const open = window.matchMedia?.('(min-width: 900px)')?.matches ? ' open' : '';
  return `<details class="doc-toc"${open}>
    <summary>${escapeHtml(t('help.doc.toc'))}</summary>
    <ul class="doc-toc__list">
      ${items.map(h => `<li class="doc-toc__item doc-toc__item--${h.level}"><a href="${escapeHtml(docRoute(page.path, h.id.slice(ID_PREFIX.length)))}">${h.html}</a></li>`).join('')}
    </ul>
  </details>`;
}

function notFoundHtml(message) {
  return `<div class="view-header"><div><h1 class="view-header__title">${escapeHtml(t('help.doc.handbook'))}</h1></div></div>
    <div class="banner banner--warning" role="status">${escapeHtml(message)}</div>
    <p class="help-actions">
      <a class="btn btn--ghost btn--sm" href="${escapeHtml(docRoute(indexKey('')))}">${escapeHtml(t('help.doc.handbook'))}</a>
      <a class="btn btn--ghost btn--sm" href="#/help">${escapeHtml(t('help.title'))}</a>
    </p>`;
}

export async function render(container, params = [], ctx = {}) {
  const key = String(params[0] || '') || indexKey('');
  const anchor = ctx.query?.get('h') || '';
  let page;
  try {
    page = await load(key);
  } catch (e) {
    if (e?.status !== 404) throw e;
    // Alter Pfad (Weiterleitung seit v2.14.0): weiter zur neuen Seite
    const moved = e.detail?.moved_to;
    if (typeof moved === 'string' && moved) {
      window.location.replace(docRoute(moved, anchor));
      return;
    }
    container.innerHTML = notFoundHtml(e.message);
    return;
  }

  const links = docLinks(page.path, { rootFiles: page.root_files || [], version: appVersion(), assetUrl: api.docAssetUrl });
  const doc = /\.md$/i.test(page.path)
    ? renderMarkdown(page.markdown, links)
    // LICENSE: reiner Text
    : { html: `<h1>${escapeHtml(page.path.split('/').pop())}</h1><pre class="doc-code doc-plain"><code>${escapeHtml(page.markdown)}</code></pre>`, headings: [] };
  const lang = page.lang === 'de' || page.lang === 'en' ? ` lang="${page.lang}"` : '';
  // Das Inhaltsverzeichnis steht unter dem Titel der Seite
  const head = doc.html.match(/^<h1[^>]*>[\s\S]*?<\/h1>\n?/)?.[0] || '';
  container.innerHTML = `
    <div class="help-doc">
      ${barHtml(page, anchor)}
      ${docsInOtherLanguage() && page.lang === 'en' ? `<p class="banner banner--info doc-hint" role="note">${escapeHtml(t('help.docs.englishOnly'))}</p>` : ''}
      <article class="doc-body"${lang}>${head}${tocHtml(page, doc.headings)}${doc.html.slice(head.length)}</article>
    </div>`;

  // Sprünge innerhalb der Seite (Inhaltsverzeichnis, Verweise auf Abschnitte):
  // scrollen statt die Seite neu aufzubauen
  const onClick = (ev) => {
    const a = ev.target?.closest?.('a[href^="#/help/doc/"]');
    if (!a || ev.defaultPrevented || ev.button > 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    const href = a.getAttribute('href');
    const [path, query = ''] = href.slice('#/help/doc/'.length).split('?');
    const h = new URLSearchParams(query).get('h');
    let target = '';
    try { target = decodeURIComponent(path); } catch { return; }
    if (target !== page.path || !h) return;
    ev.preventDefault();
    window.history.replaceState(window.history.state, '', href);
    scrollToAnchor(container, h);
  };
  container.addEventListener('click', onClick);

  // Nach dem Router scrollen (er setzt die Ansicht an den Anfang)
  const timer = anchor ? setTimeout(() => scrollToAnchor(container, anchor), 0) : null;
  return () => {
    if (timer) clearTimeout(timer);
    container.removeEventListener('click', onClick);
  };
}
